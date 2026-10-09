function installK2TimeTriggers() {
  K2TriggerService.installTimeTriggers();
}

// Every 1 min so PR/archive advance almost immediately after AON_SUCCESS
// (was 10 min). The recovery run is a lightweight status poll; if Apps Script
// daily-runtime quota ever complains, bump this back toward 5.
function installK2RecoveryTrigger() {
  K2TriggerService.installRecoveryTrigger(1);
}

// Install once. Replaces the fixed 09:00/12:00/15:00 discovery triggers with a
// 5-min sweep that only acts inside the :15-:45 catch windows (files land :00-:10
// past 8/12/15 WIB and need ~10 min to settle). It retries until the files are
// ready, registers on the first success (idempotent -- later ticks no-op), and
// emails only OPERATION_STARTED on success, or one SOURCE_FILES_STILL_UNAVAILABLE
// if a window closes with nothing found. runScheduledImport stays for manual use.
function installK2DiscoverySweepTrigger() {
  K2TriggerService.installDiscoverySweep();
}

// Install once. Daily folder janitor: at ~18:00 WIB (after the 15:00 K2 slot, so
// no import is ever in flight) it moves every file in the Drive source folder that
// is NOT one of the 3 K2 files and is older than JANITOR_MIN_AGE_HOURS (default 24h)
// into the same ARCHIVE_FOLDER_ID the K2 files use, keeping the source folder clean.
// The 3 K2 filenames are a hard skip-list, so a not-yet-processed K2 file is never
// touched. See K2Main.janitorSweep.
function installK2JanitorTrigger() {
  K2TriggerService.installJanitorTrigger();
}

// Daily janitor entry point (runs via installK2JanitorTrigger). Uses a generous
// 30s tryLock (vs the 1s elsewhere) because the once-a-day 18:00 firing overlaps
// the every-1-minute recovery tick -- the longer wait ensures a passing recovery
// run can't make the janitor skip its single daily pass.
function runK2Janitor() {
  var config = K2Config.getConfig();
  var lock = LockService.getScriptLock();
  if (!lock.tryLock(30000)) {
    K2LoggingService.warn('LOCK_UNAVAILABLE', { source: 'janitor' });
    return { code: 'LOCK_UNAVAILABLE' };
  }
  try {
    return K2Main.janitorSweep(config, new Date(), {});
  } finally {
    lock.releaseLock();
  }
}

function runScheduledImport() {
  var config = K2Config.getConfig();
  var lock = LockService.getScriptLock();
  if (!lock.tryLock(1000)) {
    K2LoggingService.warn('LOCK_UNAVAILABLE', {});
    return;
  }
  try {
    var result = K2Main.run(new Date(), config);
    return result;
  } finally {
    lock.releaseLock();
  }
}

// Recovery-only entry point (Area H). Intended for a short, frequent trigger
// (recommended every 5-10 minutes — see README) so a queued/processing AON or
// PR attempt is noticed and advanced (PR queued promptly after AON_SUCCESS,
// archive started only after PR success) well before the next 09:00/12:00/15:00
// discovery run, without ever touching Drive source discovery or registering a
// new batch. It calls the exact same K2Main.processRecoveryRecords() the normal
// run() already uses for its own recovery pass — the ONLY thing this wrapper adds
// is its own ScriptLock acquire/release, exactly like runScheduledImport() does
// for the full run. Never installs a trigger itself; that remains a manual step.
function runK2RecoveryOnly() {
  var config = K2Config.getConfig();
  var lock = LockService.getScriptLock();
  if (!lock.tryLock(1000)) {
    K2LoggingService.warn('LOCK_UNAVAILABLE', { source: 'recovery_only' });
    return { code: 'LOCK_UNAVAILABLE', recovery_count: 0 };
  }
  try {
    var results = K2Main.processRecoveryRecords(config, null, new Date(), {});
    K2LoggingService.info('RECOVERY_ONLY_COMPLETED', { recovery_count: results.length });
    return { code: 'RECOVERY_ONLY_COMPLETED', recovery_count: results.length };
  } finally {
    lock.releaseLock();
  }
}

// Fires every 5 min (via installK2DiscoverySweepTrigger). No-op outside the catch
// windows; inside one it attempts discovery and, on the first success, hands off
// to the normal run() to register + queue AON. See K2Main.discoverySweep.
function runK2DiscoverySweep() {
  var config = K2Config.getConfig();
  var lock = LockService.getScriptLock();
  if (!lock.tryLock(1000)) {
    K2LoggingService.warn('LOCK_UNAVAILABLE', { source: 'discovery_sweep' });
    return { code: 'LOCK_UNAVAILABLE' };
  }
  try {
    return K2Main.discoverySweep(config, new Date(), {});
  } finally {
    lock.releaseLock();
  }
}

// Read-only view of the recovery store. Run this first whenever ingestion looks
// stalled: it shows every pending record in the order processRecoveryRecords()
// will actually service them, and marks the cut-off at recoveryBatchLimit --
// anything below that line is waiting, not working.
function k2InspectRecovery() {
  var config = K2Config.getConfig();
  var limit = Math.max(0, Math.min(5, Number(config.recoveryBatchLimit || 2)));
  var pending = K2RecoveryStore.listPending(config, new Date());
  Logger.log('pending recovery records: ' + pending.length + ' | serviced per tick: ' + limit);
  pending.forEach(function (record, i) {
    var state = record.server_state || {};
    Logger.log([
      (i < limit ? '  SERVICED' : '  WAITING '),
      i,
      record.batch_uid,
      'slot=' + record.scheduled_slot_at_utc,
      'state=' + state.state,
      'next=' + state.next_action,
      'failures=' + (record.consecutive_failures || 0),
      'err=' + record.last_safe_error,
      'updated=' + record.updated_at
    ].join('  |  '));
  });
  return { pending_count: pending.length, batch_limit: limit };
}

// Operator escape hatch: drop pending records whose scheduled slot is already
// older than olderThanHours (default 24). A genuinely in-flight batch is minutes
// old, never a day, so anything older is a batch that will never finish -- and
// while it sits there it consumes one of the recoveryBatchLimit slots. Keyed on
// scheduled_slot_at_utc rather than updated_at on purpose: a failing record gets
// its updated_at refreshed on every tick, so it always looks "recent".
function k2ClearStaleRecovery(olderThanHours) {
  var cutoffHours = Number(olderThanHours) > 0 ? Number(olderThanHours) : 24;
  var props = PropertiesService.getScriptProperties();
  var all = props.getProperties();
  var now = Date.now();
  var deleted = 0;
  var kept = 0;
  Object.keys(all).filter(function (key) {
    return key.indexOf(K2RecoveryStore.PREFIX) === 0;
  }).sort().forEach(function (key) {
    var record;
    try {
      record = JSON.parse(all[key]);
    } catch (e) {
      props.deleteProperty(key);
      deleted += 1;
      Logger.log('deleted (malformed): ' + key);
      return;
    }
    var stamp = new Date(record.scheduled_slot_at_utc || record.updated_at || 0).getTime();
    var ageHours = isNaN(stamp) ? Number.MAX_SAFE_INTEGER : (now - stamp) / 3600000;
    if (ageHours <= cutoffHours) {
      kept += 1;
      return;
    }
    // Age alone is not proof a batch is dead -- a genuinely in-flight one could be
    // old after a long outage, and deleting its record orphans it permanently.
    // Only drop what is provably going nowhere: already terminal, actively
    // failing, or never successfully polled (server_state still unset).
    var state = record.server_state || {};
    var strandedByAge = record.terminal_failure ||
      record.last_safe_error ||
      !state.state ||
      state.state === 'AON_SUCCESS';
    if (!strandedByAge) {
      kept += 1;
      Logger.log('kept (still in flight): ' + key + '  state=' + state.state);
      return;
    }
    props.deleteProperty(key);
    deleted += 1;
    Logger.log('deleted: ' + key + '  age=' + ageHours.toFixed(1) + 'h  state=' +
      ((record.server_state || {}).state) + '  err=' + record.last_safe_error);
  });
  Logger.log('k2ClearStaleRecovery: deleted=' + deleted + ' kept=' + kept + ' cutoff=' + cutoffHours + 'h');
  return { deleted_count: deleted, kept_count: kept, cutoff_hours: cutoffHours };
}

var K2Main = (function () {
  function run(now, config, injected) {
    injected = injected || {};
    var slot = K2TriggerService.resolveSlot(now, config.timezone);
    var recoveryStore = injected.recoveryStore || K2RecoveryStore;
    var configErrors = K2Config.validateFor(config, 'api');
    if (configErrors.length && !config.dryRun) {
      return finish(config, { code: 'CONFIG_INVALID', slot: slot.label, message: 'K2 automation API configuration is incomplete.', errors: configErrors });
    }

    if (!K2TriggerService.isWorkday(now, config.timezone, config.optionalHolidayDates)) {
      var recoveryResults = [];
      if (config.allowNonWorkdayRecovery) {
        recoveryResults = processRecoveryRecords(config, slot, now, injected);
      }
      return finish(config, { code: 'WORKDAY_SKIPPED', slot: slot.label, recovery_count: recoveryResults.length, message: 'Not a configured workday.' });
    }

    processRecoveryRecords(config, slot, now, injected);

    var discoveryErrors = K2Config.validateFor(config, 'discovery');
    if (discoveryErrors.length && !config.dryRun) {
      return finish(config, { code: 'CONFIG_INVALID', slot: slot.label, message: 'K2 automation discovery configuration is incomplete.', errors: discoveryErrors });
    }

    var discovery = K2DriveBatchFinder.discoverSourceFiles(config, slot, now, injected.driveFiles);
    if (!discovery.success) {
      return finish(config, { code: 'SOURCE_FILES_UNAVAILABLE', slot: slot.label, message: 'Missing or ambiguous source files.', errors: discovery.errors });
    }

    var stability = K2DriveBatchFinder.verifySelectedFilesStable(config, discovery.files, now, injected.rereadFile);
    if (!stability.success) {
      return finish(config, { code: 'SOURCE_FILES_UNSTABLE', slot: slot.label, message: 'One or more source files changed during stability check.', errors: stability.errors });
    }

    var files = hashDiscoveredFiles(stability.files, injected.blobs || {});
    var sourceFiles = ['pr', 'catalog', 'price'].map(function (datasetType) {
      return K2DriveBatchFinder.metadataForFile(files[datasetType], datasetType, slot, config.timezone, files[datasetType].sha256);
    });
    var batchUid = K2BatchIdentity.batchUid(sourceFiles);
    var registerPayload = {
      batch_uid: batchUid,
      scheduled_slot_at_utc: slot.scheduled_slot_at_utc,
      schedule_timezone: config.timezone,
      source_files: K2BatchIdentity.sourceManifest(sourceFiles)
    };

    var registerRequestId = K2BatchIdentity.requestId(batchUid, 'register_batch', 1);
    var register = K2VssApiClient.postJson(config, 'register_batch', 'register_batch', batchUid, registerRequestId, registerPayload);
    if (!register.body || register.body.success === false) {
      return finish(config, { code: register.body && register.body.code || 'REGISTER_FAILED', slot: slot.label, batch_uid: batchUid, message: 'Batch registration failed.' });
    }

    var status = register.body;
    var recoveryRecord = recoveryStore.save(K2RecoveryStore.fromRegistration(batchUid, slot, config.timezone, sourceFiles, status));
    // AON/PR uploads now only QUEUE server-side (workspace/cron/process_k2_import_queue.php
    // does the actual XLSX normalize + business write out of band). uploadAon()/uploadPr()
    // below return as soon as the server has queued the attempt, with state
    // AON_QUEUED/PR_QUEUED and next_action 'none' — this run then simply finishes, and
    // the NEXT scheduled/recovery pass (processRecoveryRecord below) polls batch_status
    // again to notice AON_SUCCESS/IMPORT_SUCCESS and continue. next_action only becomes
    // 'pr'/'archive' once the server reports the prior phase actually completed, so this
    // block never re-uploads a still-queued or still-processing attempt.
    if (status.next_action === 'aon') {
      status = runAon(config, batchUid, slot, sourceFiles, files, status, recoveryStore, recoveryRecord);
      recoveryRecord = recoveryStore.load(batchUid) || recoveryRecord;
    }
    if (status.next_action === 'pr') {
      status = runPr(config, batchUid, slot, sourceFiles, files, status, recoveryStore, recoveryRecord);
      recoveryRecord = recoveryStore.load(batchUid) || recoveryRecord;
    }
    if (K2ArchiveService.canArchive(status)) {
      status = archive(config, batchUid, slot, sourceFiles, status, attemptNumberForStatus(status, 1), recoveryStore, recoveryRecord, injected);
    }
    recoveryRecord = recoveryStore.load(batchUid) || recoveryRecord;
    if (status && status.state === 'COMPLETED') {
      recoveryStore.clear(batchUid);
    } else if (K2RecoveryStore.isTerminalStatus(status)) {
      markTerminalAlreadyNotified(recoveryStore, recoveryRecord, status);
    }

    return finish(config, {
      code: status.code || 'COMPLETED',
      slot: slot.label,
      batch_uid: batchUid,
      state: status.state,
      archive_state: status.archive_state,
      files: sourceFiles.map(function (file) {
        return { dataset_type: file.dataset_type, filename: file.original_filename, drive_file_id: file.drive_file_id };
      }),
      counters: status.result && status.result.counters,
      message: status.message || 'K2 automation run finished.'
    });
  }

  // Windowed discovery with retry (called every ~5 min by runK2DiscoverySweep).
  // Outside a catch window: no-op. Inside one: retry discovery until the files are
  // present AND settled, hand the first ready attempt to run() (which registers +
  // queues AON and emails OPERATION_STARTED), record a per-day/per-slot "done"
  // marker so later ticks no-op, and -- only on the final attempts of a window that
  // still found nothing -- email a single SOURCE_FILES_STILL_UNAVAILABLE.
  function discoverySweep(config, now, injected) {
    injected = injected || {};
    var window = K2TriggerService.activeDiscoveryWindow(now, config.timezone);
    if (!window) return { code: 'OUTSIDE_WINDOW' };
    if (!K2TriggerService.isWorkday(now, config.timezone, config.optionalHolidayDates)) {
      return { code: 'WORKDAY_SKIPPED', slot: window.label };
    }

    var configErrors = K2Config.validateFor(config, 'discovery');
    if (configErrors.length && !config.dryRun) {
      return { code: 'CONFIG_INVALID', slot: window.label, errors: configErrors };
    }

    var slot = K2TriggerService.resolveSlot(now, config.timezone);
    var props = sweepProps(injected);
    var day = String(slot.scheduled_local).slice(0, 10);
    var doneKey = 'k2sweep_done_' + day + '|' + window.label;
    var alertKey = 'k2sweep_alerted_' + day + '|' + window.label;
    pruneSweepMarkers(props, day);

    if (props.getProperty(doneKey)) return { code: 'ALREADY_DONE', slot: window.label };

    var discovery = K2DriveBatchFinder.discoverSourceFiles(config, slot, now, injected.driveFiles);
    var stable = discovery.success
      ? K2DriveBatchFinder.verifySelectedFilesStable(config, discovery.files, now, injected.rereadFile)
      : { success: false };

    if (discovery.success && stable.success) {
      var result = run(now, config, injected);
      if (result && result.batch_uid) {
        props.setProperty(doneKey, new Date().toISOString());
      }
      return result;
    }

    if (window.isLast && !props.getProperty(alertKey)) {
      K2NotificationService.send(config, {
        code: 'SOURCE_FILES_STILL_UNAVAILABLE',
        slot: window.label,
        message: 'No complete, settled source-file set was found for the ' + window.label +
          ' slot before its discovery window closed. Check the Drive source folder.'
      });
      props.setProperty(alertKey, new Date().toISOString());
    }
    return { code: 'RETRY_PENDING', slot: window.label };
  }

  function sweepProps(injected) {
    if (injected.scriptProperties) return injected.scriptProperties;
    return PropertiesService.getScriptProperties();
  }

  // Keep the Properties store tidy: drop any sweep marker not tagged with today's
  // local date so old day/slot markers don't accumulate.
  function pruneSweepMarkers(props, day) {
    var all = props.getProperties();
    Object.keys(all).forEach(function (key) {
      if (key.indexOf('k2sweep_') === 0 && key.indexOf(day) === -1) {
        props.deleteProperty(key);
      }
    });
  }

  // Daily folder janitor. Lists every file in the Drive source folder and moves the
  // ones that are at least config.janitorMinAgeHours old into the archive folder.
  // Non-K2 files: archived once old enough. Files sharing one of the 3 K2 filenames:
  // the SINGLE NEWEST copy of each name is always kept (that is the live file the
  // importer uses, and it must never be pulled out from under the parser -- even if
  // it is itself >24h old because K2 stalled); older DUPLICATE copies are archived
  // once old enough. A K2-named copy with an unknown timestamp is always kept, to be
  // safe. Per-file try/catch keeps one bad file from aborting the batch. dryRun
  // reports the would-be moves without touching Drive. injected.driveFiles overrides
  // the live listing in tests; each file exposes name/getName,
  // modifiedTime/getLastUpdated, and moveTo(folder).
  function janitorSweep(config, now, injected) {
    injected = injected || {};
    K2Config.assertValidFor(config, 'archive');
    var minAgeMs = Math.max(0, Number(config.janitorMinAgeHours || 24)) * 3600000;
    var nowMs = now.getTime();

    var k2Names = {};
    k2Names[config.filenames.pr] = true;
    k2Names[config.filenames.catalog] = true;
    k2Names[config.filenames.price] = true;

    var files = listJanitorSourceFiles(config, injected);

    // First pass: for each K2 filename, find the newest (max) modified time across all
    // its copies, so the second pass can protect exactly that newest copy.
    var k2NewestMs = {};
    files.forEach(function (file) {
      var name = janitorFileName(file);
      if (!k2Names[name]) return;
      var ms = janitorLastUpdatedMs(file);
      if (isNaN(ms)) return;
      if (!(name in k2NewestMs) || ms > k2NewestMs[name]) k2NewestMs[name] = ms;
    });

    var archiveFolder = null; // resolved lazily, only when a real move happens
    var archived = [];
    var skippedRecent = [];
    var skippedK2 = [];
    var failed = [];

    files.forEach(function (file) {
      var name = janitorFileName(file);
      var updatedMs = janitorLastUpdatedMs(file);

      if (k2Names[name]) {
        // Keep the newest copy (and any copy tied at that newest timestamp), and keep
        // any copy whose timestamp we can't read -- never risk the live/unprocessed
        // input. Older duplicate copies fall through to the age check below.
        if (isNaN(updatedMs) || (name in k2NewestMs && updatedMs === k2NewestMs[name])) {
          skippedK2.push(name);
          return;
        }
      } else if (isNaN(updatedMs)) {
        // Non-K2 file with unknown timestamp -> treat as recent (skip), don't risk it.
        skippedRecent.push(name);
        return;
      }

      if ((nowMs - updatedMs) < minAgeMs) { skippedRecent.push(name); return; }

      if (config.dryRun) { archived.push(name); return; }

      try {
        if (!archiveFolder) archiveFolder = DriveApp.getFolderById(config.archiveFolderId);
        file.moveTo(archiveFolder);
        archived.push(name);
      } catch (e) {
        failed.push(name);
        K2LoggingService.warn('JANITOR_FILE_FAILED', { filename: name, message: String(e.message || e) });
      }
    });

    K2LoggingService.info('JANITOR_COMPLETED', {
      dry_run: !!config.dryRun,
      archived_count: archived.length,
      skipped_recent_count: skippedRecent.length,
      skipped_k2_count: skippedK2.length,
      failed_count: failed.length
    });

    return {
      code: 'JANITOR_COMPLETED',
      dry_run: !!config.dryRun,
      archived: archived,
      skipped_recent: skippedRecent,
      skipped_k2: skippedK2,
      failed: failed
    };
  }

  function listJanitorSourceFiles(config, injected) {
    if (injected.driveFiles) return injected.driveFiles;
    var out = [];
    var iterator = DriveApp.getFolderById(config.sourceFolderId).getFiles();
    while (iterator.hasNext()) { out.push(iterator.next()); }
    return out;
  }

  function janitorFileName(file) {
    if (file && typeof file.getName === 'function') return file.getName();
    return (file && file.name) || '';
  }

  function janitorLastUpdatedMs(file) {
    if (file && typeof file.getLastUpdated === 'function') {
      var d = file.getLastUpdated();
      return d ? d.getTime() : NaN;
    }
    if (file && file.modifiedTime) return new Date(file.modifiedTime).getTime();
    return NaN;
  }

  function processRecoveryRecords(config, currentSlot, now, injected) {
    var recoveryStore = injected.recoveryStore || K2RecoveryStore;
    var limit = Math.max(0, Math.min(5, Number(config.recoveryBatchLimit || 2)));
    if (limit === 0) return [];
    recoveryStore.cleanup(config, now);
    notifyPendingTerminalRecords(config, recoveryStore);
    var pending = recoveryStore.listPending(config, now);
    warnIfRecoveryBacklog(config, pending, limit, now, injected);
    var records = pending.slice(0, limit);
    var results = [];
    records.forEach(function (record) {
      try {
        results.push(processRecoveryRecord(config, record, injected, recoveryStore));
      } catch (e) {
        recoveryStore.noteFailure(record, 'RECOVERY_EXCEPTION', config);
        K2LoggingService.warn('RECOVERY_EXCEPTION', { batch_uid: record.batch_uid, message: String(e.message || e) });
      }
    });
    return results;
  }

  // More pending records than we can service in one tick means somebody is
  // waiting. Harmless for a tick or two; a standing alarm otherwise, because it
  // is exactly the condition that silently stalled ingestion -- two dead batches
  // held both slots for 15 days while every new batch queued behind them.
  // Throttled to one notification per local day so the 1-minute trigger cannot
  // turn this into a mail flood.
  function warnIfRecoveryBacklog(config, pending, limit, now, injected) {
    if (!pending || pending.length <= limit) return;
    K2LoggingService.warn('RECOVERY_BACKLOG', { pending_count: pending.length, batch_limit: limit });
    var props = sweepProps(injected || {});
    // Day key derived the same way discoverySweep() does it, via resolveSlot's
    // scheduled_local, rather than a second Utilities.formatDate pattern.
    var slot = K2TriggerService.resolveSlot(now || new Date(), config.timezone);
    var alertKey = 'k2sweep_recovery_backlog_' + String(slot.scheduled_local).slice(0, 10);
    if (props.getProperty(alertKey)) return;
    props.setProperty(alertKey, new Date().toISOString());
    K2NotificationService.send(config, {
      code: 'RECOVERY_BACKLOG',
      message: pending.length + ' batches are pending in the recovery store but only ' + limit +
        ' are advanced per tick, so the remainder are not progressing. Run k2InspectRecovery() and clear any record that can no longer succeed.'
    });
  }

  function processRecoveryRecord(config, record, injected, recoveryStore) {
    var batchUid = record.batch_uid;
    var statusRequestId = K2BatchIdentity.requestId(batchUid, 'batch_status', 1);
    var statusResponse = K2VssApiClient.postJson(config, 'batch_status', 'batch_status', batchUid, statusRequestId, { batch_uid: batchUid });
    var status = statusResponse.body;
    if (!status || status.success === false) {
      // A non-retryable error response -- BATCH_NOT_FOUND being the common one,
      // when the batch no longer exists server-side -- is terminal: re-polling can
      // never change the answer. This branch used to return before the
      // isTerminalStatus() check below, so the record stayed pending forever and
      // held one of the scarce per-tick slots until the 30-day retention sweep.
      if (K2RecoveryStore.isTerminalStatus(status)) {
        return finishRecovered(config, recoveryStore, batchUid, status, record);
      }
      recoveryStore.noteFailure(record, (status && status.code) || 'STATUS_FAILED', config);
      return status;
    }

    // AON_QUEUED/PR_QUEUED/AON_PROCESSING/PR_PROCESSING are all in-progress, not
    // terminal (K2RecoveryStore.isTerminalStatus only matches *_FAILED_FINAL and
    // archive FAILED_FINAL) — a batch sitting in one of those states here just
    // falls through to the next_action checks below, which stay 'none' until a
    // worker actually finishes the phase, so nothing gets re-uploaded.
    record = recoveryStore.clearFailures(record);
    record = recoveryStore.update(record, { server_state: status });
    if (status.state === 'COMPLETED') {
      notifyCompletionOnce(config, record, status);
      recoveryStore.clear(batchUid);
      return status;
    }
    if (K2RecoveryStore.isTerminalStatus(status)) {
      return finishRecovered(config, recoveryStore, batchUid, status, record);
    }

    var sourceFiles = registeredManifestFromStatus(status, record);
    var slot = {
      label: record.slot_label || '',
      scheduled_slot_at_utc: record.scheduled_slot_at_utc,
      timezone: record.timezone || config.timezone
    };

    if (status.next_action === 'aon') {
      return finishRecovered(config, recoveryStore, batchUid, runAon(
        config,
        batchUid,
        slot,
        sourceFiles,
        filesFromRegisteredManifest(config, sourceFiles, injected, true),
        status,
        recoveryStore,
        record
      ));
    }

    if (status.next_action === 'pr') {
      return finishRecovered(config, recoveryStore, batchUid, runPr(
        config,
        batchUid,
        slot,
        sourceFiles,
        filesFromRegisteredManifest(config, sourceFiles, injected, true),
        status,
        recoveryStore,
        record
      ));
    }

    if (K2ArchiveService.canArchive(status)) {
      return finishRecovered(
        config,
        recoveryStore,
        batchUid,
        archive(config, batchUid, slot, sourceFiles, status, attemptNumberForStatus(status, 1), recoveryStore, record, injected),
        record
      );
    }

    return status;
  }

  function runAon(config, batchUid, slot, sourceFiles, files, status, recoveryStore, recoveryRecord) {
    var attemptNumber = attemptNumberForStatus(status, 1);
    var requestId = requestIdFor(recoveryRecord, batchUid, 'aon', attemptNumber);
    recoveryRecord = recoveryStore.update(recoveryRecord, {
      active_operation: 'aon',
      active_attempt_number: attemptNumber,
      logical_request_ids: rememberRequestId(recoveryRecord, 'aon', attemptNumber, requestId)
    });
    var response = uploadAon(config, batchUid, slot, sourceFiles, files, attemptNumber, requestId);
    recoveryStore.update(recoveryRecord, { server_state: response, active_operation: null, active_attempt_number: null });
    return response;
  }

  function finishRecovered(config, recoveryStore, batchUid, status, record) {
    if (status && status.state === 'COMPLETED') {
      notifyCompletionOnce(config, record, status);
      recoveryStore.clear(batchUid);
      return status;
    }
    if (K2RecoveryStore.isTerminalStatus(status)) {
      record = recoveryStore.load(batchUid) || record || { batch_uid: batchUid };
      record = recoveryStore.markTerminal(record, status);
      if (!record.terminal_notified_at) {
        notifyTerminalFailureOnce(config, recoveryStore, record, status);
      }
    }
    return status;
  }

  function markTerminalAlreadyNotified(recoveryStore, record, status) {
    if (!record || !record.batch_uid) return;
    record = recoveryStore.markTerminal(record, status);
    if (!record.terminal_notified_at) {
      recoveryStore.markTerminalNotified(record);
    }
  }

  function notifyTerminalFailureOnce(config, recoveryStore, record, status) {
    try {
      K2NotificationService.send(config, {
        code: status.code || record.last_safe_error || 'TERMINAL_FAILURE',
        slot: record.slot_label || '',
        batch_uid: record.batch_uid,
        state: status.state,
        archive_state: status.archive_state,
        message: status.message || 'K2 automation recovery reached a final non-retryable failure.'
      });
    } catch (e) {
      K2LoggingService.warn('TERMINAL_NOTIFICATION_FAILED', { batch_uid: record.batch_uid, message: String(e.message || e) });
    }
    recoveryStore.markTerminalNotified(recoveryStore.load(record.batch_uid) || record);
  }

  // Unlike terminal failures, COMPLETED is only ever observed once per batch --
  // recoveryStore.clear(batchUid) removes the record immediately after this
  // fires, so there's no repeat-tick dedup to worry about here.
  function notifyCompletionOnce(config, record, status) {
    try {
      K2NotificationService.send(config, {
        code: 'BATCH_COMPLETED',
        slot: record.slot_label || '',
        batch_uid: record.batch_uid,
        state: status.state,
        archive_state: status.archive_state,
        counters: status.result && status.result.counters,
        message: 'K2 automation batch completed successfully.'
      });
    } catch (e) {
      K2LoggingService.warn('COMPLETION_NOTIFICATION_FAILED', { batch_uid: record.batch_uid, message: String(e.message || e) });
    }
  }

  function notifyPendingTerminalRecords(config, recoveryStore) {
    recoveryStore.list().forEach(function (record) {
      if (K2RecoveryStore.isTerminalRecord(record) && !record.terminal_notified_at) {
        notifyTerminalFailureOnce(config, recoveryStore, record, record.server_state || {});
      }
    });
  }

  function runPr(config, batchUid, slot, sourceFiles, files, status, recoveryStore, recoveryRecord) {
    var attemptNumber = attemptNumberForStatus(status, 1);
    var requestId = requestIdFor(recoveryRecord, batchUid, 'pr', attemptNumber);
    recoveryRecord = recoveryStore.update(recoveryRecord, {
      active_operation: 'pr',
      active_attempt_number: attemptNumber,
      logical_request_ids: rememberRequestId(recoveryRecord, 'pr', attemptNumber, requestId)
    });
    var response = uploadPr(config, batchUid, slot, sourceFiles, files, attemptNumber, requestId);
    recoveryStore.update(recoveryRecord, { server_state: response, active_operation: null, active_attempt_number: null });
    return response;
  }

  function uploadAon(config, batchUid, slot, sourceFiles, files, attemptNumber, requestId) {
    var manifest = K2BatchIdentity.fileManifest([
      manifestEntry('catalog_file', files.catalog),
      manifestEntry('price_file', files.price)
    ]);
    var payload = operationPayload(batchUid, slot, sourceFiles, manifest, attemptNumber);
    var response = K2VssApiClient.postFiles(config, 'aon', 'aon', batchUid, requestId, payload, manifest, {
      catalog_file: files.catalog.blob,
      price_file: files.price.blob
    });
    return response.body;
  }

  function uploadPr(config, batchUid, slot, sourceFiles, files, attemptNumber, requestId) {
    var manifest = K2BatchIdentity.fileManifest([manifestEntry('pr_file', files.pr)]);
    var payload = operationPayload(batchUid, slot, sourceFiles, manifest, attemptNumber);
    var response = K2VssApiClient.postFiles(config, 'pr', 'pr', batchUid, requestId, payload, manifest, {
      pr_file: files.pr.blob
    });
    return response.body;
  }

  function archive(config, batchUid, slot, sourceFiles, serverState, attemptNumber, recoveryStore, recoveryRecord, injected) {
    var requestId = requestIdFor(recoveryRecord, batchUid, 'archive', attemptNumber);
    recoveryRecord = recoveryStore.update(recoveryRecord, {
      active_operation: 'archive',
      active_attempt_number: attemptNumber,
      logical_request_ids: rememberRequestId(recoveryRecord, 'archive', attemptNumber, requestId)
    });
    var start = K2VssApiClient.postJson(config, 'start_archive', 'archive', batchUid, requestId, {
      batch_uid: batchUid,
      attempt_number: attemptNumber
    });
    var startBody = start.body || serverState;
    if (!startBody || startBody.success === false) {
      recoveryStore.update(recoveryRecord, { server_state: startBody, last_safe_error: startBody && startBody.code || 'ARCHIVE_START_FAILED' });
      return startBody || serverState;
    }

    sourceFiles = registeredManifestFromStatus(startBody, recoveryRecord);
    var move = K2ArchiveService.moveRegisteredFiles(config, sourceFiles, recoveryStore, recoveryRecord, injected);
    var payload = Object.assign(operationPayload(batchUid, slot, sourceFiles, [], attemptNumber), {
      archive_success: move.success,
      retryable: !move.success,
      error_code: move.success ? null : 'ARCHIVE_MOVE_FAILED',
      safe_message: move.success ? null : 'One or more registered files could not be moved to Archive.',
      archive_detail: {
        moved_count: move.moved.length,
        failed_count: move.failed.length
      }
    });
    var response = K2VssApiClient.postJson(config, 'archive', 'archive', batchUid, requestId, payload);
    recoveryRecord = recoveryStore.load(batchUid) || recoveryRecord;
    recoveryStore.update(recoveryRecord, { server_state: response.body || serverState, active_operation: null, active_attempt_number: null });
    return response.body || serverState;
  }

  function hashDiscoveredFiles(files, injectedBlobs) {
    var out = {};
    Object.keys(files).forEach(function (datasetType) {
      var file = files[datasetType];
      var blob = injectedBlobs[file.id] || (file.file && file.file.getBlob && file.file.getBlob());
      if (!blob) throw new Error('Missing blob for ' + datasetType);
      var bytes = blob.getBytes ? blob.getBytes() : blob.bytes;
      out[datasetType] = Object.assign({}, file, {
        blob: blob,
        sha256: K2BatchIdentity.bytesToHex(Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, bytes))
      });
    });
    return out;
  }

  function manifestEntry(field, file) {
    return {
      field: field,
      filename: file.name,
      file_size_bytes: file.size,
      sha256_content_hash: file.sha256
    };
  }

  function operationPayload(batchUid, slot, sourceFiles, fileManifest, attemptNumber) {
    return {
      batch_uid: batchUid,
      scheduled_slot_at_utc: slot.scheduled_slot_at_utc,
      schedule_timezone: slot.timezone,
      source_files: K2BatchIdentity.sourceManifest(sourceFiles),
      attempt_number: Number(attemptNumber),
      file_manifest: fileManifest
    };
  }

  function nextAttemptNumber(status, fallback) {
    return Number(status && (status.next_attempt_number || (status.result && status.result.next_attempt_number)) || fallback);
  }

  function attemptNumberForStatus(status, fallback) {
    if (status && status.operation_status === 'RUNNING' && status.current_attempt_number) {
      return Number(status.current_attempt_number);
    }
    return nextAttemptNumber(status, fallback);
  }

  function registeredManifestFromStatus(status, recoveryRecord) {
    return K2RecoveryStore.compactManifest(
      status && status.result && status.result.registered_manifest ||
      recoveryRecord && recoveryRecord.registered_manifest ||
      []
    );
  }

  function filesFromRegisteredManifest(config, registeredManifest, injected, requireSourceParent) {
    var out = {};
    registeredManifest.forEach(function (registered) {
      var record = K2DriveBatchFinder.readFileById(registered.drive_file_id, injected.driveFiles);
      if (!K2DriveBatchFinder.recordMatchesRegistered(record, registered)) {
        throw new Error('Registered Drive file metadata mismatch.');
      }
      if (requireSourceParent && !K2DriveBatchFinder.hasParent(record, config.sourceFolderId)) {
        throw new Error('Registered Drive file is not in the source folder.');
      }
      var blob = injected.blobs && injected.blobs[registered.drive_file_id] || record.blob || (record.file && record.file.getBlob && record.file.getBlob());
      if (!blob) throw new Error('Missing blob for registered Drive file.');
      var bytes = blob.getBytes ? blob.getBytes() : blob.bytes;
      var sha256 = K2BatchIdentity.bytesToHex(Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, bytes));
      if (sha256 !== registered.sha256_content_hash) {
        throw new Error('Registered Drive file hash mismatch.');
      }
      out[registered.dataset_type] = {
        id: registered.drive_file_id,
        name: registered.original_filename,
        size: registered.file_size_bytes,
        modifiedTime: registered.modified_timestamp,
        parentIds: record.parentIds || [],
        blob: blob,
        sha256: sha256
      };
    });
    return out;
  }

  function requestIdFor(recoveryRecord, batchUid, operation, attemptNumber) {
    var key = operation + ':' + attemptNumber;
    return recoveryRecord && recoveryRecord.logical_request_ids && recoveryRecord.logical_request_ids[key] ||
      K2BatchIdentity.requestId(batchUid, operation, attemptNumber);
  }

  function rememberRequestId(recoveryRecord, operation, attemptNumber, requestId) {
    var ids = Object.assign({}, recoveryRecord.logical_request_ids || {});
    ids[operation + ':' + attemptNumber] = requestId;
    return ids;
  }

  function finish(config, summary) {
    try {
      K2NotificationService.send(config, summary);
    } catch (e) {
      K2LoggingService.warn('NOTIFICATION_FAILED', { message: String(e.message || e) });
    }
    return summary;
  }

  return {
    run: run,
    discoverySweep: discoverySweep,
    janitorSweep: janitorSweep,
    operationPayload: operationPayload,
    manifestEntry: manifestEntry,
    hashDiscoveredFiles: hashDiscoveredFiles,
    processRecoveryRecords: processRecoveryRecords,
    filesFromRegisteredManifest: filesFromRegisteredManifest,
    attemptNumberForStatus: attemptNumberForStatus
  };
})();
