var K2RecoveryStore = (function () {
  var PREFIX = 'K2_RECOVERY_';
  var MAX_RECORD_BYTES = 8000;
  // Mirrors Config.gs RECOVERY_MAX_CONSECUTIVE_FAILURES; used only when a caller
  // passes no config, so the two can never silently disagree.
  var DEFAULT_MAX_CONSECUTIVE_FAILURES = 30;

  function fromRegistration(batchUid, slot, timezone, registeredManifest, serverState) {
    return {
      batch_uid: batchUid,
      scheduled_slot_at_utc: slot.scheduled_slot_at_utc,
      slot_label: slot.label || '',
      timezone: timezone,
      registered_manifest: compactManifest(registeredManifest),
      server_state: compactServerState(serverState || {}),
      active_operation: null,
      active_attempt_number: null,
      logical_request_ids: {},
      archive_completed_file_ids: [],
      consecutive_failures: 0,
      terminal_failure: false,
      terminal_at: null,
      terminal_notified_at: null,
      last_safe_error: null,
      updated_at: nowIso()
    };
  }

  function save(record) {
    if (!record || !record.batch_uid) throw new Error('Recovery batch UID is required.');
    var copy = compactRecord(record);
    var json = JSON.stringify(copy);
    if (json.length > MAX_RECORD_BYTES) {
      throw new Error('Recovery record exceeds bounded Script Properties size.');
    }
    props().setProperty(key(copy.batch_uid), json);
    return copy;
  }

  function update(record, patch) {
    return save(Object.assign({}, record || {}, patch || {}, { updated_at: nowIso() }));
  }

  function list() {
    return readRecords().map(function (entry) {
      return entry.record;
    });
  }

  // Oldest-updated first. processRecoveryRecords() only advances
  // config.recoveryBatchLimit records per tick, so ANY stable ordering starves
  // the tail: the previous order came from readRecords()'s .sort() over Script
  // Property keys, i.e. by batch_uid content hash, so the same two records won
  // every tick and every other batch waited forever. Ordering by updated_at
  // rotates fairly -- whatever was serviced most recently goes to the back.
  function listPending(config, now) {
    cleanup(config, now);
    return list().filter(function (record) {
      return !isTerminalRecord(record);
    }).sort(function (a, b) {
      return updatedAtMs(a) - updatedAtMs(b);
    });
  }

  function updatedAtMs(record) {
    var parsed = new Date((record && record.updated_at) || 0).getTime();
    return isNaN(parsed) ? 0 : parsed;
  }

  function load(batchUid) {
    var json = props().getProperty(key(batchUid));
    if (!json) return null;
    return compactRecord(JSON.parse(json));
  }

  function clear(batchUid) {
    props().deleteProperty(key(batchUid));
  }

  function markTerminal(record, status, now) {
    var patch = {
      terminal_failure: true,
      terminal_at: record.terminal_at || nowIso(now),
      server_state: compactServerState(status || record.server_state || {}),
      last_safe_error: (status && status.code) || record.last_safe_error || 'TERMINAL_FAILURE'
    };
    return update(record, patch);
  }

  function markTerminalNotified(record, now) {
    return update(record, { terminal_notified_at: record.terminal_notified_at || nowIso(now) });
  }

  // Failure budget. Every failure path used to just stamp last_safe_error and
  // leave the record pending, so a batch that can never succeed -- e.g. its Drive
  // file was replaced, permanently invalidating the registered manifest -- held a
  // scarce processing slot until the 30-day retention sweep. After
  // recoveryMaxConsecutiveFailures consecutive failures the record goes terminal,
  // which makes cleanup() reclaim it and notifyPendingTerminalRecords() alert on
  // it. At the 1-minute recovery tick the default budget is ~30 minutes of solid
  // failure -- long enough to ride out a VSS redeploy, so an otherwise healthy
  // in-flight batch is never given up on prematurely.
  function noteFailure(record, errorCode, config) {
    var budget = Math.max(1, Number((config || {}).recoveryMaxConsecutiveFailures || DEFAULT_MAX_CONSECUTIVE_FAILURES));
    var failures = Math.max(0, Number(record.consecutive_failures || 0)) + 1;
    var patch = {
      consecutive_failures: failures,
      last_safe_error: errorCode || 'RECOVERY_FAILURE'
    };
    if (failures >= budget) {
      patch.terminal_failure = true;
      patch.terminal_at = record.terminal_at || nowIso();
    }
    return update(record, patch);
  }

  function clearFailures(record) {
    if (!record || !Number(record.consecutive_failures)) return record;
    return update(record, { consecutive_failures: 0 });
  }

  function cleanup(config, now) {
    config = config || {};
    var nowDate = now || new Date();
    var store = props();
    var entries = readRecords(true);
    var deleted = 0;
    var malformed = 0;
    entries.forEach(function (entry) {
      if (entry.malformed) {
        store.deleteProperty(entry.key);
        malformed += 1;
        deleted += 1;
        return;
      }
      if (entry.record.server_state && entry.record.server_state.state === 'COMPLETED') {
        store.deleteProperty(entry.key);
        deleted += 1;
        return;
      }
      if (isTerminalRecord(entry.record) && ageDays(entry.record.terminal_at || entry.record.updated_at, nowDate) > boundedDays(config.terminalRecoveryRetentionDays, 7)) {
        store.deleteProperty(entry.key);
        deleted += 1;
        return;
      }
      if (!isTerminalRecord(entry.record) && ageDays(entry.record.updated_at, nowDate) > boundedDays(config.recoveryRetentionDays, 30)) {
        store.deleteProperty(entry.key);
        deleted += 1;
      }
    });

    var totalLimit = Math.max(1, Math.min(100, Number(config.recoveryRecordLimit || 20)));
    var remaining = readRecords().sort(function (a, b) {
      var aTerminal = isTerminalRecord(a.record) ? 0 : 1;
      var bTerminal = isTerminalRecord(b.record) ? 0 : 1;
      if (aTerminal !== bTerminal) return aTerminal - bTerminal;
      return new Date(a.record.updated_at).getTime() - new Date(b.record.updated_at).getTime();
    });
    while (remaining.length > totalLimit) {
      store.deleteProperty(remaining.shift().key);
      deleted += 1;
    }

    return { deleted_count: deleted, malformed_count: malformed, total_count: readRecords().length };
  }

  function key(batchUid) {
    return PREFIX + String(batchUid).replace(/[^A-Za-z0-9_.:-]/g, '_');
  }

  function compactRecord(record) {
    return {
      batch_uid: String(record.batch_uid || ''),
      scheduled_slot_at_utc: String(record.scheduled_slot_at_utc || ''),
      slot_label: String(record.slot_label || ''),
      timezone: String(record.timezone || ''),
      registered_manifest: compactManifest(record.registered_manifest || []),
      server_state: compactServerState(record.server_state || {}),
      active_operation: record.active_operation || null,
      active_attempt_number: record.active_attempt_number == null ? null : Number(record.active_attempt_number),
      logical_request_ids: compactRequestIds(record.logical_request_ids || {}),
      archive_completed_file_ids: (record.archive_completed_file_ids || []).map(String),
      consecutive_failures: Math.max(0, Number(record.consecutive_failures || 0)),
      terminal_failure: !!record.terminal_failure,
      terminal_at: record.terminal_at ? String(record.terminal_at) : null,
      terminal_notified_at: record.terminal_notified_at ? String(record.terminal_notified_at) : null,
      last_safe_error: record.last_safe_error || null,
      updated_at: String(record.updated_at || nowIso())
    };
  }

  function compactManifest(files) {
    return (files || []).map(function (file) {
      return {
        dataset_type: String(file.dataset_type || ''),
        original_filename: String(file.original_filename || file.filename || ''),
        drive_file_id: String(file.drive_file_id || ''),
        modified_timestamp: String(file.modified_timestamp || ''),
        file_size_bytes: Number(file.file_size_bytes || 0),
        sha256_content_hash: String(file.sha256_content_hash || '').toLowerCase()
      };
    });
  }

  function compactServerState(state) {
    return {
      code: state.code || null,
      state: state.state || null,
      archive_state: state.archive_state || null,
      next_action: state.next_action || null,
      current_attempt_number: state.current_attempt_number == null ? null : Number(state.current_attempt_number),
      next_attempt_number: state.next_attempt_number == null ? null : Number(state.next_attempt_number),
      active_request_id: state.active_request_id || null,
      operation_status: state.operation_status || null
    };
  }

  function isTerminalStatus(status) {
    return status &&
      (
        status.state === 'AON_FAILED_FINAL' ||
        status.state === 'PR_FAILED_FINAL' ||
        status.archive_state === 'FAILED_FINAL' ||
        (status.success === false && status.retryable === false && status.next_action === 'none')
      );
  }

  function isTerminalRecord(record) {
    return !!(record && (record.terminal_failure || isTerminalStatus(record.server_state)));
  }

  function readRecords(includeMalformed) {
    var all = props().getProperties();
    return Object.keys(all).filter(function (name) {
      return name.indexOf(PREFIX) === 0;
    }).sort().map(function (name) {
      try {
        return { key: name, record: compactRecord(JSON.parse(all[name])), malformed: false };
      } catch (e) {
        return includeMalformed ? { key: name, record: null, malformed: true } : null;
      }
    }).filter(Boolean);
  }

  function ageDays(timestamp, now) {
    if (!timestamp) return 0;
    var then = new Date(timestamp);
    if (isNaN(then.getTime())) return Number.MAX_SAFE_INTEGER;
    return (now.getTime() - then.getTime()) / 86400000;
  }

  function boundedDays(value, fallback) {
    var parsed = Number(value);
    if (!isFinite(parsed) || parsed < 0) return fallback;
    return parsed;
  }

  function compactRequestIds(ids) {
    var out = {};
    Object.keys(ids || {}).forEach(function (key) {
      out[key] = String(ids[key]);
    });
    return out;
  }

  function props() {
    if (typeof PropertiesService === 'undefined' || !PropertiesService.getScriptProperties) {
      throw new Error('Script Properties are unavailable.');
    }
    return PropertiesService.getScriptProperties();
  }

  function nowIso(now) {
    return (now || new Date()).toISOString();
  }

  return {
    PREFIX: PREFIX,
    fromRegistration: fromRegistration,
    save: save,
    update: update,
    list: list,
    listPending: listPending,
    load: load,
    clear: clear,
    cleanup: cleanup,
    markTerminal: markTerminal,
    markTerminalNotified: markTerminalNotified,
    noteFailure: noteFailure,
    clearFailures: clearFailures,
    isTerminalStatus: isTerminalStatus,
    isTerminalRecord: isTerminalRecord,
    compactManifest: compactManifest
  };
})();
