var K2TriggerService = (function () {
  // windowStartMin/windowEndMin = local minutes-since-midnight bounding the sweep's
  // catch window -- the hours files actually land in Drive, NOT processingHour. The
  // discovery sweep uses these to decide when to look; see activeDiscoveryWindow.
  // A minute-of-day range (rather than an hour plus a :15-:45 minute test) is what
  // lets the afternoon window span more than one hour with no dead gap at :46-:14.
  //
  // arrivalEndMin = local minutes-since-midnight of the LAST minute a file may carry
  // as its Drive modified time (buildSlot appends :59 seconds). It is a filter on the
  // file; windowStartMin/windowEndMin are a filter on the clock. Both must be widened
  // together -- widening only the schedule leaves the sweep ticking over files it
  // will always reject, and widening only the cap makes files eligible after the last
  // tick has already run.
  //
  // Morning: the 08:00 drop drifted to ~09:36 in Oct 2026, past the old 09:14:59 cap,
  // so every morning batch was rejected by the file filter. Files are now accepted
  // through 09:45:59 and the window stays open to 10:00 -- a file modified at the cap
  // settles at 09:55:59 and is caught by the 10:00 tick, the last one (see INVARIANT
  // below). The cap deliberately stays below midday's 10:00 arrivalStartHour so the
  // two slots never compete for the same file; widening morning further means moving
  // midday's arrival start too.
  //
  // Afternoon: the 15:00 drop has been observed landing past 16:00, so files are
  // accepted through 16:29:59 and the window stays open to 17:00. That gap is not
  // slack -- an eligible file still has to clear the 10-minute
  // FILE_MINIMUM_AGE_MINUTES settle (16:29:59 -> 16:39:59) and then be caught by a
  // 5-minute tick. INVARIANT: windowEndMin >= arrivalEndMin + FILE_MINIMUM_AGE_MINUTES
  // + one tick, or a late file becomes importable only after its own window has
  // closed and is then never imported at all -- not that day, and not the next,
  // because the next day's slots filter on their own arrival ranges.
  var SLOT_DEFS = [
    { label: 'morning', processingHour: 9, arrivalStartHour: 7, arrivalEndMin: 9 * 60 + 45, windowStartMin: 8 * 60 + 15, windowEndMin: 10 * 60 },
    { label: 'midday', processingHour: 12, arrivalStartHour: 10, arrivalEndMin: 12 * 60 + 14, windowStartMin: 12 * 60 + 15, windowEndMin: 12 * 60 + 45 },
    { label: 'afternoon', processingHour: 15, arrivalStartHour: 13, arrivalEndMin: 16 * 60 + 29, windowStartMin: 15 * 60 + 15, windowEndMin: 17 * 60 }
  ];

  function installTimeTriggers() {
    var config = K2Config.getConfig();
    removeTimeTriggers();
    SLOT_DEFS.forEach(function (slot) {
      ScriptApp.newTrigger('runScheduledImport')
        .timeBased()
        .atHour(slot.processingHour)
        .everyDays(1)
        .inTimezone(config.timezone)
        .create();
    });
  }

  function removeTimeTriggers() {
    ScriptApp.getProjectTriggers().forEach(function (trigger) {
      if (trigger.getHandlerFunction && trigger.getHandlerFunction() === 'runScheduledImport') {
        ScriptApp.deleteTrigger(trigger);
      }
    });
  }

  // README-recommended cadence (5-10 min); see apps-script/k2-drive-import/README.md
  // "Recovery-only entry point". Deliberately not installed by installTimeTriggers()
  // above -- an operator must call this explicitly.
  function installRecoveryTrigger(everyMinutes) {
    removeRecoveryTrigger();
    ScriptApp.newTrigger('runK2RecoveryOnly')
      .timeBased()
      .everyMinutes(everyMinutes || 10)
      .create();
  }

  function removeRecoveryTrigger() {
    ScriptApp.getProjectTriggers().forEach(function (trigger) {
      if (trigger.getHandlerFunction && trigger.getHandlerFunction() === 'runK2RecoveryOnly') {
        ScriptApp.deleteTrigger(trigger);
      }
    });
  }

  // Discovery catch-window for the sweep. Files land from :00 past the drop hour
  // and need ~10 min to settle, so a window opens at :15 and stays open across the
  // whole observed arrival spread -- a slot retries until the files land AND rides
  // out Apps Script's trigger drift, instead of firing once and giving up.
  // Returns { label, isLast } when inside a window, else null. isLast marks the
  // final attempts (last 5 minutes) -- the only time the "still no files" alert may
  // fire. Deriving isLast from windowEndMin rather than a hardcoded :40 is what
  // keeps that alert to ONE firing per slot on a window spanning several hours.
  function activeDiscoveryWindow(date, timezone) {
    var parts = localParts(date, timezone);
    var minuteOfDay = parts.hour * 60 + parts.minute;
    for (var i = 0; i < SLOT_DEFS.length; i++) {
      var slot = SLOT_DEFS[i];
      if (minuteOfDay >= slot.windowStartMin && minuteOfDay <= slot.windowEndMin) {
        return { label: slot.label, isLast: minuteOfDay >= slot.windowEndMin - 5 };
      }
    }
    return null;
  }

  // Replaces the three fixed 09:00/12:00/15:00 discovery triggers with a single
  // every-5-min sweep (runK2DiscoverySweep) that only acts inside the catch
  // windows above, so a slot keeps retrying until the files land and settle.
  // The trigger itself is window-agnostic -- it fires all day and the windows live
  // in SLOT_DEFS -- so widening a window needs no trigger reinstall.
  // removeTimeTriggers() clears the old fixed ones so they can't also fire and
  // spam SOURCE_FILES_UNAVAILABLE. runScheduledImport stays available for manual
  // nudges.
  function installDiscoverySweep() {
    removeTimeTriggers();
    removeDiscoverySweep();
    ScriptApp.newTrigger('runK2DiscoverySweep')
      .timeBased()
      .everyMinutes(5)
      .create();
  }

  function removeDiscoverySweep() {
    ScriptApp.getProjectTriggers().forEach(function (trigger) {
      if (trigger.getHandlerFunction && trigger.getHandlerFunction() === 'runK2DiscoverySweep') {
        ScriptApp.deleteTrigger(trigger);
      }
    });
  }

  // Hour (local, config.timezone) the daily janitor fires. 18:00 WIB is after the
  // afternoon catch window closes (17:00), so the janitor never competes with an
  // import. If that window is ever pushed past ~17:30, move this later too.
  var JANITOR_HOUR = 18;

  // Daily folder janitor trigger (runK2Janitor). Fires once a day at JANITOR_HOUR,
  // independent of the import/recovery/sweep triggers.
  function installJanitorTrigger() {
    var config = K2Config.getConfig();
    removeJanitorTrigger();
    ScriptApp.newTrigger('runK2Janitor')
      .timeBased()
      .atHour(JANITOR_HOUR)
      .everyDays(1)
      .inTimezone(config.timezone)
      .create();
  }

  function removeJanitorTrigger() {
    ScriptApp.getProjectTriggers().forEach(function (trigger) {
      if (trigger.getHandlerFunction && trigger.getHandlerFunction() === 'runK2Janitor') {
        ScriptApp.deleteTrigger(trigger);
      }
    });
  }

  function isWorkday(date, timezone, holidays) {
    var parts = localParts(date, timezone);
    if (parts.weekday === 6 || parts.weekday === 7) return false;
    return (holidays || []).indexOf(parts.date) === -1;
  }

  function resolveSlot(date, timezone) {
    var parts = localParts(date, timezone);
    var best = SLOT_DEFS[0];
    var bestDistance = Math.abs(parts.hour - best.processingHour);
    SLOT_DEFS.forEach(function (slot) {
      var distance = Math.abs(parts.hour - slot.processingHour);
      if (distance < bestDistance) {
        best = slot;
        bestDistance = distance;
      }
    });
    return buildSlot(parts.date, best, timezone);
  }

  function buildSlot(localDate, slot, timezone) {
    var scheduledLocal = localDate + 'T' + pad(slot.processingHour) + ':00:00';
    var arrivalStartLocal = localDate + 'T' + pad(slot.arrivalStartHour) + ':00:00';
    // :59 seconds so arrivalEndMin is inclusive of its whole minute.
    var arrivalEndLocal = localDate + 'T' + pad(Math.floor(slot.arrivalEndMin / 60)) +
      ':' + pad(slot.arrivalEndMin % 60) + ':59';
    return {
      label: slot.label,
      timezone: timezone,
      scheduled_local: scheduledLocal,
      scheduled_slot_at_utc: localToUtcIso(scheduledLocal, timezone),
      arrival_start_utc: localToUtcIso(arrivalStartLocal, timezone),
      arrival_end_utc: localToUtcIso(arrivalEndLocal, timezone),
      processing_hour: slot.processingHour,
      expected_arrival_hour: slot.arrivalStartHour + 1
    };
  }

  function localParts(date, timezone) {
    var stamp = Utilities.formatDate(date, timezone, 'yyyy-MM-dd HH:mm:ss u');
    var match = /^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2}):\d{2} (\d)$/.exec(stamp);
    if (!match) throw new Error('Could not format local date.');
    return {
      date: match[1],
      hour: Number(match[2]),
      minute: Number(match[3]),
      weekday: Number(match[4])
    };
  }

  function localToUtcIso(localStamp, timezone) {
    if (timezone !== 'Asia/Jakarta') {
      throw new Error('Only Asia/Jakarta local conversion is configured for K2 slots.');
    }
    var date = new Date(localStamp + '+07:00');
    return date.toISOString().replace(/\.\d{3}Z$/, '.000000Z');
  }

  function pad(value) {
    return ('0' + value).slice(-2);
  }

  return {
    SLOT_DEFS: SLOT_DEFS,
    installTimeTriggers: installTimeTriggers,
    removeTimeTriggers: removeTimeTriggers,
    installRecoveryTrigger: installRecoveryTrigger,
    removeRecoveryTrigger: removeRecoveryTrigger,
    activeDiscoveryWindow: activeDiscoveryWindow,
    installDiscoverySweep: installDiscoverySweep,
    removeDiscoverySweep: removeDiscoverySweep,
    installJanitorTrigger: installJanitorTrigger,
    removeJanitorTrigger: removeJanitorTrigger,
    isWorkday: isWorkday,
    resolveSlot: resolveSlot,
    localToUtcIso: localToUtcIso
  };
})();
