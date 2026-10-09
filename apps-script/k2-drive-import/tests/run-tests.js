const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..');
let uuidCounter = 0;
let fetchCount = 0;
let mailCount = 0;
const scriptProperties = {};

const context = {
  console,
  Date,
  JSON,
  Math,
  Number,
  Object,
  String,
  Array,
  Error,
  RegExp,
  Utilities: {
    DigestAlgorithm: { SHA_256: 'SHA_256' },
    Charset: { UTF_8: 'UTF-8' },
    computeDigest: function (_algorithm, value) {
      const buffer = Array.isArray(value)
        ? Buffer.from(value.map((byte) => (byte < 0 ? byte + 256 : byte)))
        : Buffer.from(String(value), 'utf8');
      return toSignedBytes(crypto.createHash('sha256').update(buffer).digest());
    },
    computeHmacSha256Signature: function (value, secret) {
      return toSignedBytes(crypto.createHmac('sha256', secret).update(String(value), 'utf8').digest());
    },
    formatDate: function (date, timezone, pattern) {
      if (timezone !== 'Asia/Jakarta') throw new Error('Unsupported test timezone');
      const local = new Date(date.getTime() + 7 * 60 * 60 * 1000);
      const yyyy = local.getUTCFullYear();
      const mm = pad(local.getUTCMonth() + 1);
      const dd = pad(local.getUTCDate());
      const hh = pad(local.getUTCHours());
      const mi = pad(local.getUTCMinutes());
      const ss = pad(local.getUTCSeconds());
      const jsDay = local.getUTCDay();
      const isoDay = jsDay === 0 ? 7 : jsDay;
      if (pattern === 'yyyy-MM-dd HH:mm:ss u') return `${yyyy}-${mm}-${dd} ${hh}:${mi}:${ss} ${isoDay}`;
      throw new Error('Unsupported pattern');
    },
    getUuid: function () {
      uuidCounter += 1;
      return `uuid-${uuidCounter}`;
    },
    sleep: function () {}
  },
  PropertiesService: {
    getScriptProperties: function () {
      return {
        getProperties: function () { return Object.assign({}, scriptProperties); },
        getProperty: function (key) { return Object.prototype.hasOwnProperty.call(scriptProperties, key) ? scriptProperties[key] : null; },
        setProperty: function (key, value) { scriptProperties[key] = String(value); return this; },
        deleteProperty: function (key) { delete scriptProperties[key]; return this; }
      };
    }
  },
  UrlFetchApp: {
    fetch: function () {
      fetchCount += 1;
      return {
        getResponseCode: () => 200,
        getContentText: () => JSON.stringify({ success: true, code: 'OK', next_action: 'none' })
      };
    }
  },
  MailApp: {
    sendEmail: function () { mailCount += 1; }
  },
  LockService: {
    getScriptLock: function () {
      return { tryLock: () => true, releaseLock: () => {} };
    }
  },
  ScriptApp: {
    triggers: [],
    newTrigger: function (handler) {
      const trigger = {
        handler,
        getHandlerFunction: () => handler,
        timeBased: function () { return this; },
        atHour: function (hour) { this.hour = hour; return this; },
        everyDays: function (days) { this.days = days; return this; },
        inTimezone: function (timezone) { this.timezone = timezone; return this; },
        create: function () { context.ScriptApp.triggers.push(this); return this; }
      };
      return trigger;
    },
    getProjectTriggers: function () { return this.triggers; },
    deleteTrigger: function (trigger) {
      this.triggers = this.triggers.filter((item) => item !== trigger);
    }
  }
};

vm.createContext(context);
[
  'Config.gs',
  'LoggingService.gs',
  'BatchIdentity.gs',
  'TriggerService.gs',
  'DriveBatchFinder.gs',
  'VssApiClient.gs',
  'ArchiveService.gs',
  'RecoveryStore.gs',
  'NotificationService.gs',
  'Main.gs'
].forEach((file) => {
  vm.runInContext(fs.readFileSync(path.join(root, file), 'utf8'), context, { filename: file });
});

const tests = [];

tests.push(['slot resolution and UTC conversion', () => {
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:12:00Z'), 'Asia/Jakarta');
  assertEqual('morning', slot.label);
  assertEqual('2026-07-22T02:00:00.000000Z', slot.scheduled_slot_at_utc);
  assertEqual('2026-07-22T00:00:00.000000Z', slot.arrival_start_utc);
  assertEqual('2026-07-22T02:45:59.000000Z', slot.arrival_end_utc);
}]);

tests.push(['workday weekends and optional holidays', () => {
  assertEqual(true, context.K2TriggerService.isWorkday(new Date('2026-07-22T02:00:00Z'), 'Asia/Jakarta', []));
  assertEqual(false, context.K2TriggerService.isWorkday(new Date('2026-07-25T02:00:00Z'), 'Asia/Jakarta', []));
  assertEqual(false, context.K2TriggerService.isWorkday(new Date('2026-07-22T02:00:00Z'), 'Asia/Jakarta', ['2026-07-22']));
}]);

tests.push(['Saturday without override skips recovery and new batch', () => {
  resetScriptProperties();
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-25T02:15:00Z'), 'Asia/Jakarta');
  context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'DETECTED', next_action: 'aon' }));
  let statusCalls = 0;
  let discoveryCalls = 0;
  const originalPostJson = context.K2VssApiClient.postJson;
  const originalDiscover = context.K2DriveBatchFinder.discoverSourceFiles;
  context.K2VssApiClient.postJson = function (_config, endpoint) {
    if (endpoint === 'batch_status') statusCalls += 1;
    return { body: { success: true, state: 'COMPLETED', archive_state: 'COMPLETED', next_action: 'none' } };
  };
  context.K2DriveBatchFinder.discoverSourceFiles = function () {
    discoveryCalls += 1;
    return { success: false, errors: [] };
  };
  try {
    const summary = context.K2Main.run(new Date('2026-07-25T02:15:00Z'), sampleConfig({ allowNonWorkdayRecovery: false }), {});
    assertEqual('WORKDAY_SKIPPED', summary.code);
    assertEqual(0, statusCalls);
    assertEqual(0, discoveryCalls);
    assertEqual(1, context.K2RecoveryStore.list().length);
  } finally {
    context.K2VssApiClient.postJson = originalPostJson;
    context.K2DriveBatchFinder.discoverSourceFiles = originalDiscover;
  }
}]);

tests.push(['Saturday override allows recovery but disables new batch', () => {
  resetScriptProperties();
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-25T02:15:00Z'), 'Asia/Jakarta');
  context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'DETECTED', next_action: 'aon' }));
  let statusCalls = 0;
  let discoveryCalls = 0;
  const originalPostJson = context.K2VssApiClient.postJson;
  const originalDiscover = context.K2DriveBatchFinder.discoverSourceFiles;
  context.K2VssApiClient.postJson = function (_config, endpoint) {
    if (endpoint === 'batch_status') statusCalls += 1;
    return { body: { success: true, state: 'COMPLETED', archive_state: 'COMPLETED', next_action: 'none' } };
  };
  context.K2DriveBatchFinder.discoverSourceFiles = function () {
    discoveryCalls += 1;
    return { success: false, errors: [] };
  };
  try {
    const summary = context.K2Main.run(new Date('2026-07-25T02:15:00Z'), sampleConfig({ allowNonWorkdayRecovery: true }), {});
    assertEqual('WORKDAY_SKIPPED', summary.code);
    assertEqual(1, statusCalls);
    assertEqual(0, discoveryCalls);
    assertEqual(0, context.K2RecoveryStore.list().length);
  } finally {
    context.K2VssApiClient.postJson = originalPostJson;
    context.K2DriveBatchFinder.discoverSourceFiles = originalDiscover;
  }
}]);

tests.push(['configured holiday without override skips recovery', () => {
  resetScriptProperties();
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'DETECTED', next_action: 'aon' }));
  let statusCalls = 0;
  const originalPostJson = context.K2VssApiClient.postJson;
  context.K2VssApiClient.postJson = function (_config, endpoint) {
    if (endpoint === 'batch_status') statusCalls += 1;
    return { body: { success: true, state: 'COMPLETED', archive_state: 'COMPLETED', next_action: 'none' } };
  };
  try {
    const summary = context.K2Main.run(new Date('2026-07-22T02:15:00Z'), sampleConfig({ optionalHolidayDates: ['2026-07-22'], allowNonWorkdayRecovery: false }), {});
    assertEqual('WORKDAY_SKIPPED', summary.code);
    assertEqual(0, statusCalls);
    assertEqual(1, context.K2RecoveryStore.list().length);
  } finally {
    context.K2VssApiClient.postJson = originalPostJson;
  }
}]);

tests.push(['filename and time-window selection', () => {
  const config = sampleConfig();
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  const files = [
    file('old-pr', config.filenames.pr, '2026-07-22T00:10:00Z'),
    file('new-pr', config.filenames.pr, '2026-07-22T01:40:00Z'),
    file('wrong-name', 'Report Ticket Monitoring GP Officer (2).xlsx', '2026-07-22T01:45:00Z')
  ];
  assertEqual('new-pr', context.K2DriveBatchFinder.selectEligibleFile(files, config.filenames.pr, config.sourceFolderId, slot, new Date('2026-07-22T02:15:00Z'), 10).file.id);
  assertEqual('FILE_MISSING', context.K2DriveBatchFinder.selectEligibleFile([], config.filenames.pr, config.sourceFolderId, slot, new Date('2026-07-22T02:15:00Z'), 10).error);
}]);

tests.push(['ambiguous duplicate timestamp handling', () => {
  const config = sampleConfig();
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  const files = [
    file('a', config.filenames.pr, '2026-07-22T01:40:00Z'),
    file('b', config.filenames.pr, '2026-07-22T01:40:00Z')
  ];
  assertEqual('FILE_AMBIGUOUS', context.K2DriveBatchFinder.selectEligibleFile(files, config.filenames.pr, config.sourceFolderId, slot, new Date('2026-07-22T02:15:00Z'), 10).error);
}]);

tests.push(['file stability check', () => {
  const before = file('a', 'x.xlsx', '2026-07-22T01:40:00Z', 12);
  const after = file('a', 'x.xlsx', '2026-07-22T01:40:00Z', 12);
  const changed = file('a', 'x.xlsx', '2026-07-22T01:41:00Z', 12);
  assertEqual(true, context.K2DriveBatchFinder.checkFileStability(before, after, 10, new Date('2026-07-22T02:15:00Z')));
  assertEqual(false, context.K2DriveBatchFinder.checkFileStability(before, changed, 10, new Date('2026-07-22T02:15:00Z')));
  const verified = context.K2DriveBatchFinder.verifySelectedFilesStable(
    sampleConfig({ dryRun: true }),
    { pr: before },
    new Date('2026-07-22T02:15:00Z'),
    () => after
  );
  assertEqual(true, verified.success);
}]);

tests.push(['metadata normalization and deterministic manifest', () => {
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  const meta = context.K2DriveBatchFinder.metadataForFile(file('p1', 'pr.xlsx', '2026-07-22T01:40:00Z', 7), 'pr', slot, 'Asia/Jakarta', 'a'.repeat(64));
  assertEqual('2026-07-22T01:40:00.000000Z', meta.modified_timestamp);
  const files = sourceFiles();
  assertEqual(context.K2BatchIdentity.batchUid(files), context.K2BatchIdentity.batchUid([files[2], files[0], files[1]]));
  assertEqual('k2b_0f2b895ae3e718a35328c5a4803aa0ccfe213d569edeb64528a1d2cbed7c116d', context.K2BatchIdentity.batchUid(files));
  assertEqual(['pr', 'catalog', 'price'], Object.keys(context.K2BatchIdentity.keyedSourceManifest(files)));
}]);

tests.push(['PHP shared HMAC vector', () => {
  const canonical = context.K2VssApiClient.canonicalSignatureString(
    'post',
    '/vms/workspace/api/k2_automation/register_batch.php?ignored=1',
    'client-a',
    '2026-07-22T02:00:00Z',
    'nonce-001',
    'request-001',
    'register_batch',
    'k2b_' + 'a'.repeat(64),
    'b'.repeat(64),
    'c'.repeat(64)
  );
  assertEqual([
    'POST',
    '/vms/workspace/api/k2_automation/register_batch.php',
    'client-a',
    '2026-07-22T02:00:00Z',
    'nonce-001',
    'request-001',
    'register_batch',
    'k2b_' + 'a'.repeat(64),
    'b'.repeat(64),
    'c'.repeat(64)
  ].join('\n'), canonical);
  assertEqual('8b0590816d22a51db08a3c2eb24a36f6928b269f9f0e90024fbc50a3c525f6f7', context.K2VssApiClient.sign('test-secret', canonical));
  assertEqual('/vms/workspace/api/k2_automation/register_batch.php', context.K2VssApiClient.endpointPath(sampleConfig(), 'register_batch'));
}]);

tests.push(['new nonce and stable request id across retry', () => {
  const one = context.K2VssApiClient.newNonce();
  const two = context.K2VssApiClient.newNonce();
  assertNotEqual(one, two);
  const req1 = context.K2BatchIdentity.requestId('k2b_' + 'a'.repeat(64), 'aon', 1);
  const req2 = context.K2BatchIdentity.requestId('k2b_' + 'a'.repeat(64), 'aon', 1);
  const req3 = context.K2BatchIdentity.requestId('k2b_' + 'a'.repeat(64), 'aon', 2);
  assertEqual(req1, req2);
  assertNotEqual(req1, req3);
}]);

tests.push(['archive guards and partial reconciliation', () => {
  assertEqual(false, context.K2ArchiveService.canArchive({ state: 'AON_SUCCESS', archive_state: 'NOT_READY' }));
  assertEqual(true, context.K2ArchiveService.canArchive({ state: 'IMPORT_SUCCESS', archive_state: 'NOT_READY' }));
  assertEqual(true, context.K2ArchiveService.canArchive({ state: 'ARCHIVE_PENDING', archive_state: 'FAILED_RETRYABLE' }));
  assertEqual(['b'], context.K2ArchiveService.reconcilePartialArchive([{ drive_file_id: 'a' }, { drive_file_id: 'b' }], ['a']).map((item) => item.drive_file_id));
}]);

tests.push(['RecoveryStore persistence omits secrets', () => {
  resetScriptProperties();
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'DETECTED', next_action: 'aon' }));
  const stored = Object.values(scriptProperties).join('\n');
  assertEqual(true, stored.indexOf(batchUid) !== -1);
  assertEqual(false, stored.indexOf('test-secret') !== -1);
  assertEqual(1, context.K2RecoveryStore.list().length);
}]);

tests.push(['recovery status is loaded before source discovery', () => {
  resetScriptProperties();
  let driveAccessed = false;
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'ARCHIVE_PENDING', archive_state: 'PROCESSING' }));
  const originalPostJson = context.K2VssApiClient.postJson;
  const originalDriveApp = context.DriveApp;
  context.K2VssApiClient.postJson = function (_config, endpoint) {
    assertEqual('batch_status', endpoint);
    return { body: { success: true, state: 'COMPLETED', archive_state: 'COMPLETED', next_action: 'none' } };
  };
  context.DriveApp = { getFolderById: () => { driveAccessed = true; throw new Error('Drive should not be touched'); } };
  try {
    const config = sampleConfig({ sourceFolderId: '', SOURCE_FOLDER_ID: '', dryRun: false });
    const summary = context.K2Main.run(new Date('2026-07-22T02:15:00Z'), config, {});
    assertEqual('CONFIG_INVALID', summary.code);
    assertEqual(false, driveAccessed);
    assertEqual(0, context.K2RecoveryStore.list().length);
  } finally {
    context.K2VssApiClient.postJson = originalPostJson;
    context.DriveApp = originalDriveApp;
  }
}]);

tests.push(['normal weekday processes recovery then current slot discovery', () => {
  resetScriptProperties();
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'DETECTED', next_action: 'aon' }));
  let statusCalls = 0;
  let discoveryCalls = 0;
  const originalPostJson = context.K2VssApiClient.postJson;
  const originalDiscover = context.K2DriveBatchFinder.discoverSourceFiles;
  context.K2VssApiClient.postJson = function (_config, endpoint) {
    if (endpoint === 'batch_status') statusCalls += 1;
    return { body: { success: true, state: 'COMPLETED', archive_state: 'COMPLETED', next_action: 'none' } };
  };
  context.K2DriveBatchFinder.discoverSourceFiles = function () {
    discoveryCalls += 1;
    return { success: false, errors: [{ dataset_type: 'pr', code: 'FILE_MISSING' }] };
  };
  try {
    const summary = context.K2Main.run(new Date('2026-07-22T02:15:00Z'), sampleConfig(), {});
    assertEqual('SOURCE_FILES_UNAVAILABLE', summary.code);
    assertEqual(1, statusCalls);
    assertEqual(1, discoveryCalls);
    assertEqual(0, context.K2RecoveryStore.list().length);
  } finally {
    context.K2VssApiClient.postJson = originalPostJson;
    context.K2DriveBatchFinder.discoverSourceFiles = originalDiscover;
  }
}]);

tests.push(['recovery polls a queued AON attempt and never re-uploads', () => {
  resetScriptProperties();
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'DETECTED', next_action: 'aon' }));
  let postFilesCalls = 0;
  const originalPostJson = context.K2VssApiClient.postJson;
  const originalPostFiles = context.K2VssApiClient.postFiles;
  // A still-queued/processing AON attempt reports next_action 'none', exactly
  // like a completed operation with nothing further to do right now — this is
  // the server contract the async worker queue relies on (see Main.gs's
  // comment above the next_action checks in run()/processRecoveryRecord()).
  context.K2VssApiClient.postJson = function (_config, endpoint) {
    assertEqual('batch_status', endpoint);
    return { body: { success: true, code: 'BATCH_STATUS', state: 'AON_QUEUED', archive_state: 'NOT_READY', next_action: 'none', operation_status: 'RUNNING' } };
  };
  context.K2VssApiClient.postFiles = function () {
    postFilesCalls += 1;
    return { body: { success: false, code: 'UNEXPECTED_UPLOAD' } };
  };
  try {
    context.K2Main.processRecoveryRecords(sampleConfig(), slot, new Date('2026-07-22T02:15:00Z'), {});
    assertEqual(0, postFilesCalls);
    // Still queued: the recovery record must survive for the next poll, not be
    // cleared (only COMPLETED clears it) or marked terminal (AON_QUEUED is not
    // one of the *_FAILED_FINAL/terminal states).
    assertEqual(1, context.K2RecoveryStore.list().length);
    assertEqual(false, context.K2RecoveryStore.list()[0].terminal_failure);
  } finally {
    context.K2VssApiClient.postJson = originalPostJson;
    context.K2VssApiClient.postFiles = originalPostFiles;
  }
}]);

tests.push(['recovery uses exact Drive file ID and rejects same-name substitute', () => {
  const manifest = recoveryManifest();
  const config = sampleConfig();
  assertThrows(() => context.K2Main.filesFromRegisteredManifest(config, manifest, {
    driveFiles: [file('wrong-id', manifest[0].original_filename, manifest[0].modified_timestamp, manifest[0].file_size_bytes)],
    blobs: {}
  }, true));
}]);

tests.push(['archive timeout recovery replays same request with new nonce', () => {
  resetScriptProperties();
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  const requestId = context.K2BatchIdentity.requestId(batchUid, 'archive', 1);
  let record = context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'ARCHIVE_PENDING', archive_state: 'PROCESSING' });
  record.logical_request_ids = { 'archive:1': requestId };
  record.active_operation = 'archive';
  record.active_attempt_number = 1;
  context.K2RecoveryStore.save(record);

  const calls = [];
  const originalPostJson = context.K2VssApiClient.postJson;
  const originalDriveApp = context.DriveApp;
  context.K2VssApiClient.postJson = function (_config, endpoint, _operation, _batchUid, reqId) {
    calls.push({ endpoint, requestId: reqId });
    if (endpoint === 'batch_status') {
      return { body: processingArchiveStatus(manifest, 1) };
    }
    if (endpoint === 'start_archive') {
      return { body: processingArchiveStatus(manifest, 1) };
    }
    if (endpoint === 'archive') {
      return { body: { success: true, code: 'OPERATION_SUCCEEDED', state: 'COMPLETED', archive_state: 'COMPLETED', next_action: 'none' } };
    }
    throw new Error('Unexpected endpoint ' + endpoint);
  };
  context.DriveApp = { getFolderById: (id) => ({ id }), getFileById: (id) => ({ id, moveTo: () => { throw new Error('already archived files must not move'); } }) };
  try {
    context.K2Main.processRecoveryRecords(sampleConfig(), slot, new Date('2026-07-22T02:15:00Z'), {
      driveFiles: recoveryDriveFiles(manifest, ['archive-folder']),
      blobs: recoveryBlobs()
    });
    assertEqual(['batch_status', 'start_archive', 'archive'], calls.map((call) => call.endpoint));
    assertEqual(requestId, calls[1].requestId);
    assertEqual(requestId, calls[2].requestId);
    assertEqual(0, context.K2RecoveryStore.list().length);
  } finally {
    context.K2VssApiClient.postJson = originalPostJson;
    context.DriveApp = originalDriveApp;
  }
}]);

tests.push(['partial archive persists completed moves and later moves remaining file only', () => {
  resetScriptProperties();
  const config = sampleConfig();
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  const record = context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, {}));
  const originalDriveApp = context.DriveApp;
  context.DriveApp = { getFolderById: (id) => ({ id }), getFileById: () => ({ moveTo: () => {} }) };
  try {
    const firstRecords = recoveryDriveFiles(manifest, ['source-folder']);
    firstRecords[2].moveError = 'synthetic move failure';
    const first = context.K2ArchiveService.moveRegisteredFiles(config, manifest, context.K2RecoveryStore, record, { driveFiles: firstRecords });
    assertEqual(false, first.success);
    assertEqual(2, context.K2RecoveryStore.load(batchUid).archive_completed_file_ids.length);

    const secondRecords = recoveryDriveFiles(manifest, ['archive-folder']);
    secondRecords[2].parentIds = ['source-folder'];
    const second = context.K2ArchiveService.moveRegisteredFiles(config, manifest, context.K2RecoveryStore, context.K2RecoveryStore.load(batchUid), { driveFiles: secondRecords });
    assertEqual(true, second.success);
    assertEqual(3, context.K2RecoveryStore.load(batchUid).archive_completed_file_ids.length);
  } finally {
    context.DriveApp = originalDriveApp;
  }
}]);

tests.push(['deliberate archive retry uses a new request id', () => {
  const batchUid = 'k2b_' + 'a'.repeat(64);
  assertNotEqual(
    context.K2BatchIdentity.requestId(batchUid, 'archive', 1),
    context.K2BatchIdentity.requestId(batchUid, 'archive', 2)
  );
}]);

tests.push(['bounded recovery loop still allows current slot processing', () => {
  resetScriptProperties();
  const manifest = recoveryManifest();
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  for (let i = 0; i < 3; i += 1) {
    const batchUid = context.K2BatchIdentity.batchUid(manifest.map((entry) => Object.assign({}, entry, { drive_file_id: entry.drive_file_id + i })));
    context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, {}));
  }
  let statusCalls = 0;
  const originalPostJson = context.K2VssApiClient.postJson;
  context.K2VssApiClient.postJson = function (_config, endpoint) {
    if (endpoint === 'batch_status') statusCalls += 1;
    return { body: { success: true, state: 'COMPLETED', archive_state: 'COMPLETED', next_action: 'none' } };
  };
  try {
    context.K2Main.processRecoveryRecords(sampleConfig({ recoveryBatchLimit: 2 }), slot, new Date('2026-07-22T02:15:00Z'), {});
    assertEqual(2, statusCalls);
    assertEqual(1, context.K2RecoveryStore.list().length);
  } finally {
    context.K2VssApiClient.postJson = originalPostJson;
  }
}]);

tests.push(['final recovery failure is marked terminal and notified once', () => {
  resetScriptProperties();
  mailCount = 0;
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'DETECTED', next_action: 'aon' }));
  let statusCalls = 0;
  const originalPostJson = context.K2VssApiClient.postJson;
  context.K2VssApiClient.postJson = function (_config, endpoint) {
    if (endpoint === 'batch_status') statusCalls += 1;
    return { body: terminalAonStatus() };
  };
  try {
    context.K2Main.processRecoveryRecords(sampleConfig({ dryRun: false, notificationEmails: ['ops@example.invalid'] }), slot, new Date('2026-07-22T02:15:00Z'), {});
    const stored = context.K2RecoveryStore.load(batchUid);
    assertEqual(true, stored.terminal_failure);
    assertEqual(true, !!stored.terminal_notified_at);
    assertEqual(1, mailCount);

    context.K2Main.processRecoveryRecords(sampleConfig({ dryRun: false, notificationEmails: ['ops@example.invalid'] }), slot, new Date('2026-07-22T02:20:00Z'), {});
    assertEqual(1, mailCount);
    assertEqual(1, statusCalls);
  } finally {
    context.K2VssApiClient.postJson = originalPostJson;
  }
}]);

tests.push(['terminal recovery records are skipped during normal retries', () => {
  resetScriptProperties();
  mailCount = 0;
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  let record = context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, terminalAonStatus()));
  context.K2RecoveryStore.markTerminal(record, terminalAonStatus(), new Date('2026-07-22T02:15:00Z'));
  let statusCalls = 0;
  const originalPostJson = context.K2VssApiClient.postJson;
  context.K2VssApiClient.postJson = function (_config, endpoint) {
    if (endpoint === 'batch_status') statusCalls += 1;
    return { body: terminalAonStatus() };
  };
  try {
    context.K2Main.processRecoveryRecords(sampleConfig({ dryRun: false, notificationEmails: ['ops@example.invalid'] }), slot, new Date('2026-07-22T02:30:00Z'), {});
    assertEqual(0, statusCalls);
    assertEqual(1, mailCount);
    assertEqual(true, !!context.K2RecoveryStore.load(batchUid).terminal_notified_at);
    context.K2Main.processRecoveryRecords(sampleConfig({ dryRun: false, notificationEmails: ['ops@example.invalid'] }), slot, new Date('2026-07-22T02:35:00Z'), {});
    assertEqual(1, mailCount);
    assertEqual(1, context.K2RecoveryStore.list().length);
  } finally {
    context.K2VssApiClient.postJson = originalPostJson;
  }
}]);

tests.push(['retention cleanup removes terminal completed malformed and excess records', () => {
  resetScriptProperties();
  const manifest = recoveryManifest();
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  const oldTerminal = context.K2RecoveryStore.save(Object.assign(
    context.K2RecoveryStore.fromRegistration('old-terminal', slot, 'Asia/Jakarta', manifest, terminalAonStatus()),
    { terminal_failure: true, terminal_at: '2026-07-01T00:00:00.000Z', updated_at: '2026-07-01T00:00:00.000Z' }
  ));
  context.K2RecoveryStore.save(Object.assign(
    context.K2RecoveryStore.fromRegistration('completed-record', slot, 'Asia/Jakarta', manifest, { state: 'COMPLETED', archive_state: 'COMPLETED' }),
    { updated_at: '2026-07-22T00:00:00.000Z' }
  ));
  scriptProperties[context.K2RecoveryStore.PREFIX + 'MALFORMED'] = '{';
  context.K2RecoveryStore.save(Object.assign(context.K2RecoveryStore.fromRegistration('keep-a', slot, 'Asia/Jakarta', manifest, {}), { updated_at: '2026-07-22T01:00:00.000Z' }));
  context.K2RecoveryStore.save(Object.assign(context.K2RecoveryStore.fromRegistration('keep-b', slot, 'Asia/Jakarta', manifest, {}), { updated_at: '2026-07-22T01:01:00.000Z' }));
  context.K2RecoveryStore.save(Object.assign(context.K2RecoveryStore.fromRegistration('drop-oldest-active', slot, 'Asia/Jakarta', manifest, {}), { updated_at: '2026-07-22T00:59:00.000Z' }));

  const cleanup = context.K2RecoveryStore.cleanup(
    { recoveryRecordLimit: 2, recoveryRetentionDays: 30, terminalRecoveryRetentionDays: 7 },
    new Date('2026-07-22T02:15:00Z')
  );
  const remaining = context.K2RecoveryStore.list().map((record) => record.batch_uid).sort();

  assertEqual(true, cleanup.deleted_count >= 4);
  assertEqual(1, cleanup.malformed_count);
  assertEqual(null, context.K2RecoveryStore.load(oldTerminal.batch_uid));
  assertEqual(['keep-a', 'keep-b'], remaining);
}]);

tests.push(['old terminal records do not block current slot', () => {
  resetScriptProperties();
  const manifest = recoveryManifest();
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  const terminalRecord = context.K2RecoveryStore.save(Object.assign(
    context.K2RecoveryStore.fromRegistration('terminal-current-slot', slot, 'Asia/Jakarta', manifest, terminalAonStatus()),
    { terminal_failure: true, terminal_at: '2026-07-22T01:00:00.000Z', terminal_notified_at: '2026-07-22T01:00:01.000Z' }
  ));
  let statusCalls = 0;
  let discoveryCalls = 0;
  const originalPostJson = context.K2VssApiClient.postJson;
  const originalDiscover = context.K2DriveBatchFinder.discoverSourceFiles;
  context.K2VssApiClient.postJson = function (_config, endpoint) {
    if (endpoint === 'batch_status') statusCalls += 1;
    return { body: terminalAonStatus() };
  };
  context.K2DriveBatchFinder.discoverSourceFiles = function () {
    discoveryCalls += 1;
    return { success: false, errors: [{ dataset_type: 'pr', code: 'FILE_MISSING' }] };
  };
  try {
    const summary = context.K2Main.run(new Date('2026-07-22T02:15:00Z'), sampleConfig(), {});
    assertEqual('SOURCE_FILES_UNAVAILABLE', summary.code);
    assertEqual(0, statusCalls);
    assertEqual(1, discoveryCalls);
    assertEqual(true, context.K2RecoveryStore.isTerminalRecord(terminalRecord));
  } finally {
    context.K2VssApiClient.postJson = originalPostJson;
    context.K2DriveBatchFinder.discoverSourceFiles = originalDiscover;
  }
}]);

tests.push(['UrlFetch network exception and invalid JSON are controlled retryable results', () => {
  const originalFetch = context.UrlFetchApp.fetch;
  context.UrlFetchApp.fetch = function () { throw new Error('dns failed'); };
  try {
    const failed = context.K2VssApiClient.postJson(sampleConfig({ apiMaxRetries: 1 }), 'batch_status', 'batch_status', 'k2b_' + 'a'.repeat(64), 'req-net', { batch_uid: 'k2b_' + 'a'.repeat(64) });
    assertEqual('TRANSPORT_EXCEPTION', failed.body.code);
    assertEqual(true, failed.body.retryable);
  } finally {
    context.UrlFetchApp.fetch = originalFetch;
  }

  context.UrlFetchApp.fetch = function () {
    return {
      getResponseCode: () => 502,
      getContentText: () => '<html>bad gateway</html>'
    };
  };
  assertEqual('INVALID_JSON_RESPONSE', context.K2VssApiClient.requestWithRetries(
    sampleConfig({ apiMaxRetries: 1 }),
    'batch_status',
    'batch_status',
    'k2b_' + 'a'.repeat(64),
    'req-json',
    { batch_uid: 'k2b_' + 'a'.repeat(64) },
    [],
    null
  ).body.code);
  context.UrlFetchApp.fetch = originalFetch;
}]);

tests.push(['email failure does not mask result', () => {
  const originalSend = context.MailApp.sendEmail;
  context.MailApp.sendEmail = function () { throw new Error('smtp failed'); };
  try {
    const sent = context.K2NotificationService.send(sampleConfig({ dryRun: false, notificationEmails: ['ops@example.invalid'] }), { code: 'DONE' });
    assertEqual(false, sent.sent);
    assertEqual('NOTIFICATION_FAILED', sent.error);
  } finally {
    context.MailApp.sendEmail = originalSend;
  }
}]);

tests.push(['ScriptLock is released when scheduled run throws', () => {
  let released = 0;
  const originalLockService = context.LockService;
  const originalRun = context.K2Main.run;
  context.LockService = {
    getScriptLock: function () {
      return { tryLock: () => true, releaseLock: () => { released += 1; } };
    }
  };
  context.K2Main.run = function () { throw new Error('forced run failure'); };
  try {
    assertThrows(() => context.runScheduledImport());
    assertEqual(1, released);
  } finally {
    context.LockService = originalLockService;
    context.K2Main.run = originalRun;
  }
}]);

tests.push(['ScriptLock is released after scheduled run returns', () => {
  let released = 0;
  const originalLockService = context.LockService;
  const originalGetConfig = context.K2Config.getConfig;
  const originalRun = context.K2Main.run;
  const config = sampleConfig({ allowNonWorkdayRecovery: false });
  context.LockService = {
    getScriptLock: function () {
      return { tryLock: () => true, releaseLock: () => { released += 1; } };
    }
  };
  context.K2Config.getConfig = function () { return config; };
  context.K2Main.run = function () { return { code: 'WORKDAY_SKIPPED' }; };
  try {
    const summary = context.runScheduledImport();
    assertEqual('WORKDAY_SKIPPED', summary.code);
    assertEqual(1, released);
  } finally {
    context.LockService = originalLockService;
    context.K2Config.getConfig = originalGetConfig;
    context.K2Main.run = originalRun;
  }
}]);

tests.push(['dry-run prevents API fetch and email delivery', () => {
  fetchCount = 0;
  mailCount = 0;
  const config = sampleConfig({ dryRun: true });
  const response = context.K2VssApiClient.postJson(config, 'batch_status', 'batch_status', 'k2b_' + 'a'.repeat(64), 'request-1', { batch_uid: 'k2b_' + 'a'.repeat(64) });
  context.K2NotificationService.send(config, { code: 'DRY_RUN', slot: 'morning' });
  assertEqual(true, response.dry_run);
  assertEqual(0, fetchCount);
  assertEqual(0, mailCount);
}]);

tests.push(['notification secret redaction', () => {
  const formatted = context.K2NotificationService.format({ code: 'AUTH_FAILED', hmacSecret: 'secret', signature: 'sig', message: 'safe' });
  assertEqual(false, formatted.body.indexOf('secret') !== -1);
  assertEqual(false, JSON.stringify(context.K2LoggingService.redact({ token: 'abc' })).indexOf('abc') !== -1);
}]);

tests.push(['main missing files exits safely', () => {
  const config = sampleConfig({ dryRun: true });
  const summary = context.K2Main.run(new Date('2026-07-22T02:15:00Z'), config, { driveFiles: [] });
  assertEqual('SOURCE_FILES_UNAVAILABLE', summary.code);
}]);

// -----------------------------------------------------------------------
// Discovery sweep (windowed retry) — the catch windows and the
// register-once / alert-once contract. Times below are UTC; +7h = WIB.
tests.push(['discovery window boundaries', () => {
  const tz = 'Asia/Jakarta';
  const w = context.K2TriggerService.activeDiscoveryWindow;
  assertEqual(null, w(new Date('2026-07-22T01:05:00Z'), tz)); // 08:05 too early
  const inMorning = w(new Date('2026-07-22T01:20:00Z'), tz);  // 08:20
  assertEqual('morning', inMorning.label);
  assertEqual(false, inMorning.isLast);
  assertEqual(false, w(new Date('2026-07-22T01:42:00Z'), tz).isLast); // 08:42 no longer the end
  assertEqual(false, w(new Date('2026-07-22T02:26:00Z'), tz).isLast); // 09:26 no longer the end
  assertEqual('morning', w(new Date('2026-07-22T02:35:00Z'), tz).label); // 09:35 open (was closed)
  const lateMorning = w(new Date('2026-07-22T02:56:00Z'), tz); // 09:56
  assertEqual(true, lateMorning.isLast);
  assertEqual('morning', w(new Date('2026-07-22T03:00:00Z'), tz).label); // 10:00 last tick
  assertEqual(null, w(new Date('2026-07-22T03:01:00Z'), tz)); // 10:01 morning closed
  assertEqual('afternoon', w(new Date('2026-07-22T08:22:00Z'), tz).label); // 15:22
}]);

// The afternoon window spans 15:15-17:00, so it must stay open across two hour
// boundaries (the old hour + :15-:45 test went dead at 15:46) and must raise isLast
// only at the real end, not at 15:40 or 16:40.
tests.push(['afternoon window spans past the hour boundary', () => {
  const tz = 'Asia/Jakarta';
  const w = context.K2TriggerService.activeDiscoveryWindow;
  const late = w(new Date('2026-07-22T08:50:00Z'), tz); // 15:50 — was a dead gap
  assertEqual('afternoon', late.label);
  assertEqual(false, late.isLast);
  const past16 = w(new Date('2026-07-22T09:45:00Z'), tz); // 16:45
  assertEqual('afternoon', past16.label);
  assertEqual(false, past16.isLast);
  assertEqual(false, w(new Date('2026-07-22T08:40:00Z'), tz).isLast); // 15:40 not the end
  assertEqual(false, w(new Date('2026-07-22T09:40:00Z'), tz).isLast); // 16:40 not the end
  assertEqual(true, w(new Date('2026-07-22T09:56:00Z'), tz).isLast);  // 16:56
  assertEqual(null, w(new Date('2026-07-22T10:01:00Z'), tz));         // 17:01 closed
}]);

// The schedule above is worthless if the file filter still rejects a late arrival:
// afternoon eligibility must now reach 16:29:59 WIB and stop there.
tests.push(['afternoon arrival window reaches 16:29:59 WIB', () => {
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T09:20:00Z'), 'Asia/Jakarta'); // 16:20
  assertEqual('afternoon', slot.label);
  assertEqual('2026-07-22T08:00:00.000000Z', slot.scheduled_slot_at_utc); // still stamped 15:00 WIB
  assertEqual('2026-07-22T06:00:00.000000Z', slot.arrival_start_utc);     // 13:00 WIB
  assertEqual('2026-07-22T09:29:59.000000Z', slot.arrival_end_utc);       // 16:29:59 WIB
}]);

// Same for morning: the drop drifted to ~09:36, so eligibility must reach 09:45:59
// WIB -- and stop there, short of midday's 10:00 arrival start.
tests.push(['morning arrival window reaches 09:45:59 WIB', () => {
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:50:00Z'), 'Asia/Jakarta'); // 09:50
  assertEqual('morning', slot.label);
  assertEqual('2026-07-22T02:00:00.000000Z', slot.scheduled_slot_at_utc); // still stamped 09:00 WIB
  assertEqual('2026-07-22T00:00:00.000000Z', slot.arrival_start_utc);     // 07:00 WIB
  assertEqual('2026-07-22T02:45:59.000000Z', slot.arrival_end_utc);       // 09:45:59 WIB
}]);

// The invariant that makes a widened window actually import anything: a slot's
// last-eligible file must still clear the settle AND leave at least one sweep tick
// inside the window, or it becomes importable only after its own window has closed
// and is then never imported at all. Guards every future window edit.
// Held by all three slots (morning: 09:45:59 cap, 10:00 close -- exactly one tick);
// there is deliberately no exemption list.
tests.push(['every slot leaves a tick between its arrival cap and window close', () => {
  const SETTLE_MIN = 10; // FILE_MINIMUM_AGE_MINUTES default
  const TICK_MIN = 5;    // installDiscoverySweep() everyMinutes(5)
  context.K2TriggerService.SLOT_DEFS.forEach((slot) => {
    if (slot.arrivalEndMin + SETTLE_MIN + TICK_MIN > slot.windowEndMin) {
      throw new Error(slot.label + ' arrival cap leaves no tick before its window closes');
    }
  });
}]);

tests.push(['discovery sweep no-op outside window', () => {
  const config = sampleConfig({ dryRun: true });
  const res = context.K2Main.discoverySweep(config, new Date('2026-07-22T03:30:00Z'), { scriptProperties: makeProps() }); // 10:30
  assertEqual('OUTSIDE_WINDOW', res.code);
}]);

tests.push(['discovery sweep retries quietly when files not yet present', () => {
  const config = sampleConfig({ dryRun: true });
  let sends = 0;
  const origSend = context.K2NotificationService.send;
  context.K2NotificationService.send = function () { sends += 1; };
  try {
    const res = context.K2Main.discoverySweep(config, new Date('2026-07-22T01:20:00Z'), { scriptProperties: makeProps(), driveFiles: [] }); // 08:20
    assertEqual('RETRY_PENDING', res.code);
    assertEqual(0, sends);
  } finally {
    context.K2NotificationService.send = origSend;
  }
}]);

tests.push(['discovery sweep alerts once when window closes empty', () => {
  const config = sampleConfig({ dryRun: true });
  const props = makeProps();
  let sends = 0;
  let alertCode = null;
  const origSend = context.K2NotificationService.send;
  context.K2NotificationService.send = function (_c, summary) { sends += 1; alertCode = summary.code; };
  try {
    context.K2Main.discoverySweep(config, new Date('2026-07-22T02:56:00Z'), { scriptProperties: props, driveFiles: [] }); // 09:56 last
    assertEqual('SOURCE_FILES_STILL_UNAVAILABLE', alertCode);
    assertEqual(1, sends);
    context.K2Main.discoverySweep(config, new Date('2026-07-22T02:58:00Z'), { scriptProperties: props, driveFiles: [] }); // 09:58 last again
    assertEqual(1, sends); // no repeat alert
  } finally {
    context.K2NotificationService.send = origSend;
  }
}]);

tests.push(['discovery sweep no-ops once the slot has registered', () => {
  const config = sampleConfig({ dryRun: true });
  const props = makeProps();
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T01:20:00Z'), 'Asia/Jakarta');
  const day = String(slot.scheduled_local).slice(0, 10);
  props.setProperty('k2sweep_done_' + day + '|morning', 'x');
  let discoverCalls = 0;
  const origDiscover = context.K2DriveBatchFinder.discoverSourceFiles;
  context.K2DriveBatchFinder.discoverSourceFiles = function () { discoverCalls += 1; return { success: false, files: {}, errors: [] }; };
  try {
    const res = context.K2Main.discoverySweep(config, new Date('2026-07-22T01:22:00Z'), { scriptProperties: props, driveFiles: [] });
    assertEqual('ALREADY_DONE', res.code);
    assertEqual(0, discoverCalls);
  } finally {
    context.K2DriveBatchFinder.discoverSourceFiles = origDiscover;
  }
}]);

// -----------------------------------------------------------------------
// runK2RecoveryOnly() — dedicated recovery-only entry point (Area H). It is a
// thin ScriptLock wrapper around the exact same K2Main.processRecoveryRecords()
// the full run() already uses for its own recovery pass, so these tests focus
// on the wrapper's own contract (lock lifecycle, never touching Drive discovery
// or registering a batch) rather than re-proving state-machine transitions
// already covered by the processRecoveryRecords tests above.
tests.push(['recovery-only entry point queued AON polling causes no upload', () => {
  resetScriptProperties();
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'DETECTED', next_action: 'aon' }));
  let postFilesCalls = 0;
  const originalPostJson = context.K2VssApiClient.postJson;
  const originalPostFiles = context.K2VssApiClient.postFiles;
  context.K2VssApiClient.postJson = function (_config, endpoint) {
    assertEqual('batch_status', endpoint);
    return { body: { success: true, code: 'BATCH_STATUS', state: 'AON_QUEUED', archive_state: 'NOT_READY', next_action: 'none', operation_status: 'RUNNING' } };
  };
  context.K2VssApiClient.postFiles = function () {
    postFilesCalls += 1;
    return { body: { success: false, code: 'UNEXPECTED_UPLOAD' } };
  };
  try {
    const result = context.runK2RecoveryOnly();
    assertEqual('RECOVERY_ONLY_COMPLETED', result.code);
    assertEqual(1, result.recovery_count);
    assertEqual(0, postFilesCalls);
  } finally {
    context.K2VssApiClient.postJson = originalPostJson;
    context.K2VssApiClient.postFiles = originalPostFiles;
  }
}]);

tests.push(['recovery-only entry point AON_PROCESSING polling causes no upload', () => {
  resetScriptProperties();
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'DETECTED', next_action: 'aon' }));
  let postFilesCalls = 0;
  const originalPostJson = context.K2VssApiClient.postJson;
  const originalPostFiles = context.K2VssApiClient.postFiles;
  context.K2VssApiClient.postJson = function (_config, endpoint) {
    assertEqual('batch_status', endpoint);
    return { body: { success: true, code: 'BATCH_STATUS', state: 'AON_PROCESSING', archive_state: 'NOT_READY', next_action: 'none', operation_status: 'RUNNING' } };
  };
  context.K2VssApiClient.postFiles = function () {
    postFilesCalls += 1;
    return { body: { success: false, code: 'UNEXPECTED_UPLOAD' } };
  };
  try {
    const result = context.runK2RecoveryOnly();
    assertEqual(0, postFilesCalls);
    assertEqual(1, context.K2RecoveryStore.list().length);
  } finally {
    context.K2VssApiClient.postJson = originalPostJson;
    context.K2VssApiClient.postFiles = originalPostFiles;
  }
}]);

tests.push(['recovery-only entry point queues PR promptly after AON_SUCCESS', () => {
  resetScriptProperties();
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'AON_SUCCESS', next_action: 'pr' }));
  const driveRecords = recoveryDriveFiles(manifest, ['source-folder']);
  let postFilesCalls = 0;
  const originalGetConfig = context.K2Config.getConfig;
  const originalPostJson = context.K2VssApiClient.postJson;
  const originalPostFiles = context.K2VssApiClient.postFiles;
  const originalReadFileById = context.K2DriveBatchFinder.readFileById;
  const fixedTestConfig = sampleConfig();
  context.K2Config.getConfig = function () { return fixedTestConfig; };
  context.K2DriveBatchFinder.readFileById = function (fileId) {
    return driveRecords.find((record) => String(record.id) === String(fileId));
  };
  context.K2VssApiClient.postJson = function (_config, endpoint) {
    assertEqual('batch_status', endpoint);
    return { body: { success: true, code: 'BATCH_STATUS', state: 'AON_SUCCESS', archive_state: 'NOT_READY', next_action: 'pr', result: { registered_manifest: manifest } } };
  };
  context.K2VssApiClient.postFiles = function (_config, _endpointOp, operation) {
    postFilesCalls += 1;
    assertEqual('pr', operation);
    return { body: { success: true, code: 'OPERATION_STARTED', state: 'PR_QUEUED', archive_state: 'NOT_READY', next_action: 'none' } };
  };
  try {
    const result = context.runK2RecoveryOnly();
    assertEqual(1, postFilesCalls);
    assertEqual('RECOVERY_ONLY_COMPLETED', result.code);
  } finally {
    context.K2Config.getConfig = originalGetConfig;
    context.K2VssApiClient.postJson = originalPostJson;
    context.K2VssApiClient.postFiles = originalPostFiles;
    context.K2DriveBatchFinder.readFileById = originalReadFileById;
  }
}]);

tests.push(['recovery-only entry point queued PR polling causes no upload', () => {
  resetScriptProperties();
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'AON_SUCCESS', next_action: 'pr' }));
  let postFilesCalls = 0;
  const originalPostJson = context.K2VssApiClient.postJson;
  const originalPostFiles = context.K2VssApiClient.postFiles;
  context.K2VssApiClient.postJson = function (_config, endpoint) {
    assertEqual('batch_status', endpoint);
    return { body: { success: true, code: 'BATCH_STATUS', state: 'PR_QUEUED', archive_state: 'NOT_READY', next_action: 'none', operation_status: 'RUNNING' } };
  };
  context.K2VssApiClient.postFiles = function () {
    postFilesCalls += 1;
    return { body: { success: false, code: 'UNEXPECTED_UPLOAD' } };
  };
  try {
    context.runK2RecoveryOnly();
    assertEqual(0, postFilesCalls);
  } finally {
    context.K2VssApiClient.postJson = originalPostJson;
    context.K2VssApiClient.postFiles = originalPostFiles;
  }
}]);

tests.push(['recovery-only entry point proceeds to archive only after PR success', () => {
  resetScriptProperties();
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'IMPORT_SUCCESS', archive_state: 'NOT_READY' }));
  const driveRecords = recoveryDriveFiles(manifest, ['source-folder']);
  const originalGetConfig = context.K2Config.getConfig;
  const originalPostJson = context.K2VssApiClient.postJson;
  const originalDriveApp = context.DriveApp;
  const originalReadFileById = context.K2DriveBatchFinder.readFileById;
  let archiveStarted = false;
  let archiveCompleted = false;
  const fixedTestConfig = sampleConfig();
  context.K2Config.getConfig = function () { return fixedTestConfig; };
  context.K2DriveBatchFinder.readFileById = function (fileId) {
    return driveRecords.find((record) => String(record.id) === String(fileId));
  };
  context.DriveApp = { getFolderById: function (id) { return { id: id }; }, getFileById: function () { throw new Error('Not expected: direct getFileById'); } };
  context.K2VssApiClient.postJson = function (_config, endpoint) {
    if (endpoint === 'batch_status') {
      return { body: { success: true, code: 'BATCH_STATUS', state: 'IMPORT_SUCCESS', archive_state: 'NOT_READY', next_action: 'archive', result: { registered_manifest: manifest } } };
    }
    if (endpoint === 'start_archive') {
      archiveStarted = true;
      return { body: { success: true, code: 'ARCHIVE_READY', state: 'ARCHIVE_PENDING', archive_state: 'PROCESSING', result: { registered_manifest: manifest, attempt_id: 1 } } };
    }
    if (endpoint === 'archive') {
      archiveCompleted = true;
      return { body: { success: true, code: 'ARCHIVE_READY', state: 'COMPLETED', archive_state: 'COMPLETED' } };
    }
    throw new Error('Unexpected endpoint ' + endpoint);
  };
  try {
    context.runK2RecoveryOnly();
    assertEqual(true, archiveStarted);
    assertEqual(true, archiveCompleted);
  } finally {
    context.K2Config.getConfig = originalGetConfig;
    context.K2VssApiClient.postJson = originalPostJson;
    context.DriveApp = originalDriveApp;
    context.K2DriveBatchFinder.readFileById = originalReadFileById;
  }
}]);

tests.push(['recovery-only entry point never performs Drive source discovery', () => {
  resetScriptProperties();
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'DETECTED', next_action: 'aon' }));
  let discoveryCalls = 0;
  const originalDiscover = context.K2DriveBatchFinder.discoverSourceFiles;
  const originalPostJson = context.K2VssApiClient.postJson;
  context.K2DriveBatchFinder.discoverSourceFiles = function () {
    discoveryCalls += 1;
    throw new Error('Recovery-only must never call discoverSourceFiles');
  };
  context.K2VssApiClient.postJson = function () {
    return { body: { success: true, code: 'BATCH_STATUS', state: 'AON_QUEUED', archive_state: 'NOT_READY', next_action: 'none', operation_status: 'RUNNING' } };
  };
  try {
    context.runK2RecoveryOnly();
    assertEqual(0, discoveryCalls);
  } finally {
    context.K2DriveBatchFinder.discoverSourceFiles = originalDiscover;
    context.K2VssApiClient.postJson = originalPostJson;
  }
}]);

tests.push(['recovery-only entry point never registers a new batch', () => {
  resetScriptProperties();
  // No recovery records exist at all — a fresh/empty recovery store must
  // result in zero processed records and zero register_batch calls, proving
  // this entry point cannot originate a new batch on its own.
  let registerCalls = 0;
  const originalPostJson = context.K2VssApiClient.postJson;
  context.K2VssApiClient.postJson = function (_config, endpoint) {
    if (endpoint === 'register_batch') registerCalls += 1;
    return { body: { success: true, code: 'BATCH_STATUS' } };
  };
  try {
    const result = context.runK2RecoveryOnly();
    assertEqual(0, result.recovery_count);
    assertEqual(0, registerCalls);
  } finally {
    context.K2VssApiClient.postJson = originalPostJson;
  }
}]);

tests.push(['recovery-only entry point releases ScriptLock on success', () => {
  let released = 0;
  const originalLockService = context.LockService;
  context.LockService = {
    getScriptLock: function () {
      return { tryLock: () => true, releaseLock: () => { released += 1; } };
    }
  };
  try {
    const result = context.runK2RecoveryOnly();
    assertEqual('RECOVERY_ONLY_COMPLETED', result.code);
    assertEqual(1, released);
  } finally {
    context.LockService = originalLockService;
  }
}]);

tests.push(['recovery-only entry point releases ScriptLock when processing throws', () => {
  resetScriptProperties();
  let released = 0;
  const originalLockService = context.LockService;
  const originalProcessRecoveryRecords = context.K2Main.processRecoveryRecords;
  context.LockService = {
    getScriptLock: function () {
      return { tryLock: () => true, releaseLock: () => { released += 1; } };
    }
  };
  context.K2Main.processRecoveryRecords = function () { throw new Error('forced recovery failure'); };
  try {
    assertThrows(() => context.runK2RecoveryOnly());
    assertEqual(1, released);
  } finally {
    context.LockService = originalLockService;
    context.K2Main.processRecoveryRecords = originalProcessRecoveryRecords;
  }
}]);

tests.push(['recovery-only entry point returns LOCK_UNAVAILABLE and skips processing when lock is busy', () => {
  const originalLockService = context.LockService;
  const originalProcessRecoveryRecords = context.K2Main.processRecoveryRecords;
  let processCalls = 0;
  context.LockService = {
    getScriptLock: function () {
      return { tryLock: () => false, releaseLock: () => {} };
    }
  };
  context.K2Main.processRecoveryRecords = function () { processCalls += 1; return []; };
  try {
    const result = context.runK2RecoveryOnly();
    assertEqual('LOCK_UNAVAILABLE', result.code);
    assertEqual(0, processCalls);
  } finally {
    context.LockService = originalLockService;
    context.K2Main.processRecoveryRecords = originalProcessRecoveryRecords;
  }
}]);

tests.push(['recovery-only entry point bounds records processed per run', () => {
  resetScriptProperties();
  for (let i = 0; i < 5; i++) {
    const manifest = recoveryManifest().map((f) => Object.assign({}, f, { drive_file_id: f.drive_file_id + '-' + i }));
    const batchUid = context.K2BatchIdentity.batchUid(manifest);
    const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
    context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'DETECTED', next_action: 'aon' }));
  }
  const originalPostJson = context.K2VssApiClient.postJson;
  let statusCalls = 0;
  context.K2VssApiClient.postJson = function (_config, endpoint) {
    statusCalls += 1;
    return { body: { success: true, code: 'BATCH_STATUS', state: 'AON_QUEUED', archive_state: 'NOT_READY', next_action: 'none', operation_status: 'RUNNING' } };
  };
  try {
    const result = context.runK2RecoveryOnly.call ? context.runK2RecoveryOnly() : null;
    assertEqual(2, result.recovery_count); // default recoveryBatchLimit is 2
    assertEqual(2, statusCalls);
  } finally {
    context.K2VssApiClient.postJson = originalPostJson;
  }
}]);

tests.push(['recovery-only entry point sends no email in dry-run for a terminal record', () => {
  resetScriptProperties();
  const originalGetConfig = context.K2Config.getConfig;
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'AON_FAILED_FINAL', next_action: 'none' }));
  const dryRunConfig = sampleConfig({ dryRun: true, notificationEmails: ['ops@example.invalid'] });
  context.K2Config.getConfig = function () { return dryRunConfig; };
  const originalPostJson = context.K2VssApiClient.postJson;
  const mailBefore = mailCount;
  context.K2VssApiClient.postJson = function () {
    return { body: { success: true, code: 'BATCH_STATUS', state: 'AON_FAILED_FINAL', archive_state: 'NOT_READY', next_action: 'none' } };
  };
  try {
    context.runK2RecoveryOnly();
    assertEqual(mailBefore, mailCount);
  } finally {
    context.K2Config.getConfig = originalGetConfig;
    context.K2VssApiClient.postJson = originalPostJson;
  }
}]);

tests.push(['janitor archives a non-K2 file older than 24h', () => {
  const now = new Date('2026-08-11T10:00:00Z');
  const old = new Date(now.getTime() - 25 * 3600000).toISOString();
  const moved = [];
  const files = [janitorFile('Random Report.xlsx', old, moved)];
  const originalDriveApp = context.DriveApp;
  context.DriveApp = { getFolderById: (id) => ({ id }) };
  try {
    const result = context.K2Main.janitorSweep(sampleConfig(), now, { driveFiles: files });
    assertEqual('JANITOR_COMPLETED', result.code);
    assertEqual(['Random Report.xlsx'], result.archived);
    assertEqual(['archive-folder'], moved);
  } finally {
    context.DriveApp = originalDriveApp;
  }
}]);

tests.push(['janitor skips a non-K2 file younger than 24h', () => {
  const now = new Date('2026-08-11T10:00:00Z');
  const recent = new Date(now.getTime() - 2 * 3600000).toISOString();
  const moved = [];
  const files = [janitorFile('Fresh Drop.xlsx', recent, moved)];
  const result = context.K2Main.janitorSweep(sampleConfig(), now, { driveFiles: files });
  assertEqual(0, result.archived.length);
  assertEqual(['Fresh Drop.xlsx'], result.skipped_recent);
  assertEqual(0, moved.length);
}]);

tests.push(['janitor never archives the 3 K2 files even when old', () => {
  const now = new Date('2026-08-11T10:00:00Z');
  const old = new Date(now.getTime() - 100 * 3600000).toISOString();
  const moved = [];
  const files = [
    janitorFile('Master Item Catalog.xlsx', old, moved),
    janitorFile('Price Per Vendor And Tiering.xlsx', old, moved),
    janitorFile('Report Ticket Monitoring GP Officer.xlsx', old, moved)
  ];
  const result = context.K2Main.janitorSweep(sampleConfig(), now, { driveFiles: files });
  assertEqual(0, result.archived.length);
  assertEqual(3, result.skipped_k2.length);
  assertEqual(0, moved.length);
}]);

tests.push(['janitor keeps newest K2 duplicate and archives the older copies', () => {
  const now = new Date('2026-08-11T10:00:00Z');
  const t = (h) => new Date(now.getTime() - h * 3600000).toISOString();
  const moved = [];
  const files = [
    janitorFile('Master Item Catalog.xlsx', t(72), moved), // old dup
    janitorFile('Master Item Catalog.xlsx', t(30), moved), // newest (still > 24h)
    janitorFile('Master Item Catalog.xlsx', t(48), moved)  // old dup
  ];
  const originalDriveApp = context.DriveApp;
  context.DriveApp = { getFolderById: (id) => ({ id }) };
  try {
    const result = context.K2Main.janitorSweep(sampleConfig(), now, { driveFiles: files });
    assertEqual(2, result.archived.length);   // two older duplicates archived
    assertEqual(1, result.skipped_k2.length); // newest copy protected
    assertEqual(2, moved.length);
  } finally {
    context.DriveApp = originalDriveApp;
  }
}]);

tests.push(['janitor protects the newest K2 copy and holds a still-recent duplicate', () => {
  const now = new Date('2026-08-11T10:00:00Z');
  const t = (h) => new Date(now.getTime() - h * 3600000).toISOString();
  const moved = [];
  const files = [
    janitorFile('Price Per Vendor And Tiering.xlsx', t(1), moved),  // newest, recent
    janitorFile('Price Per Vendor And Tiering.xlsx', t(50), moved)  // old dup
  ];
  const originalDriveApp = context.DriveApp;
  context.DriveApp = { getFolderById: (id) => ({ id }) };
  try {
    const result = context.K2Main.janitorSweep(sampleConfig(), now, { driveFiles: files });
    assertEqual(['Price Per Vendor And Tiering.xlsx'], result.archived); // only the old dup
    assertEqual(1, result.skipped_k2.length); // newest kept
    assertEqual(1, moved.length);
  } finally {
    context.DriveApp = originalDriveApp;
  }
}]);

tests.push(['janitor dry-run reports would-archive without moving', () => {
  const now = new Date('2026-08-11T10:00:00Z');
  const old = new Date(now.getTime() - 25 * 3600000).toISOString();
  const moved = [];
  const files = [janitorFile('Old Thing.xlsx', old, moved)];
  const result = context.K2Main.janitorSweep(sampleConfig({ dryRun: true }), now, { driveFiles: files });
  assertEqual(true, result.dry_run);
  assertEqual(['Old Thing.xlsx'], result.archived);
  assertEqual(0, moved.length);
}]);

tests.push(['janitor entry point releases ScriptLock on success', () => {
  resetScriptProperties();
  let released = 0;
  const originalLockService = context.LockService;
  const originalGetConfig = context.K2Config.getConfig;
  const originalDriveApp = context.DriveApp;
  const janitorConfig = sampleConfig(); // capture before overriding (sampleConfig calls getConfig)
  context.LockService = {
    getScriptLock: function () {
      return { tryLock: () => true, releaseLock: () => { released += 1; } };
    }
  };
  context.K2Config.getConfig = () => janitorConfig;
  context.DriveApp = { getFolderById: (id) => ({ id, getFiles: () => ({ hasNext: () => false }) }) };
  try {
    const result = context.runK2Janitor();
    assertEqual('JANITOR_COMPLETED', result.code);
    assertEqual(1, released);
  } finally {
    context.LockService = originalLockService;
    context.K2Config.getConfig = originalGetConfig;
    context.DriveApp = originalDriveApp;
  }
}]);

tests.push(['janitor entry point returns LOCK_UNAVAILABLE and skips work when lock is busy', () => {
  const originalLockService = context.LockService;
  const originalJanitorSweep = context.K2Main.janitorSweep;
  let sweepCalls = 0;
  context.LockService = {
    getScriptLock: function () {
      return { tryLock: () => false, releaseLock: () => {} };
    }
  };
  context.K2Main.janitorSweep = function () { sweepCalls += 1; return { code: 'JANITOR_COMPLETED' }; };
  try {
    const result = context.runK2Janitor();
    assertEqual('LOCK_UNAVAILABLE', result.code);
    assertEqual(0, sweepCalls);
  } finally {
    context.LockService = originalLockService;
    context.K2Main.janitorSweep = originalJanitorSweep;
  }
}]);

let failures = 0;
tests.push(['recovery rotates fairly instead of starving records behind the batch limit', () => {
  resetScriptProperties();
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  const uids = [];
  // Saved oldest-updated first. Ordering used to come from the Script Property
  // key (the batch_uid content hash), so the same two records won every tick and
  // the rest never ran at all -- the defect that stalled PR ingestion.
  //
  // updated_at MUST be relative to the real clock: runK2RecoveryOnly() reads
  // new Date() itself, and RecoveryStore.cleanup() deletes any non-terminal record
  // older than recoveryRetentionDays (30). Pinning these to a fixed past date made
  // the test pass only for 30 days after that date and then fail forever -- it began
  // failing on 2026-08-21. Only the relative ORDER matters here, never the absolute
  // date, so anchor them to now.
  const base = Date.now();
  for (let i = 0; i < 5; i++) {
    const manifest = recoveryManifest().map((f) => Object.assign({}, f, { drive_file_id: f.drive_file_id + '-rot-' + i }));
    const batchUid = context.K2BatchIdentity.batchUid(manifest);
    uids.push(batchUid);
    const record = context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'AON_QUEUED', next_action: 'none' });
    record.updated_at = new Date(base - (5 - i) * 3600 * 1000).toISOString();
    context.K2RecoveryStore.save(record);
  }
  const originalPostJson = context.K2VssApiClient.postJson;
  const seen = [];
  context.K2VssApiClient.postJson = function (_config, _endpoint, _operation, batchUid) {
    seen.push(batchUid);
    return { body: { success: true, code: 'BATCH_STATUS', state: 'AON_QUEUED', archive_state: 'NOT_READY', next_action: 'none', operation_status: 'RUNNING' } };
  };
  try {
    context.runK2RecoveryOnly();
    assertEqual(uids[0], seen[0]);
    assertEqual(uids[1], seen[1]);
    context.runK2RecoveryOnly();
    // Second tick must move on to the next two, not repeat the first two.
    assertEqual(uids[2], seen[2]);
    assertEqual(uids[3], seen[3]);
    assertEqual(4, new Set(seen).size);
  } finally {
    context.K2VssApiClient.postJson = originalPostJson;
  }
}]);

tests.push(['a non-retryable status error marks the record terminal instead of leaving it pending', () => {
  resetScriptProperties();
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'AON_SUCCESS', next_action: 'pr' }));
  const originalPostJson = context.K2VssApiClient.postJson;
  context.K2VssApiClient.postJson = function () {
    return { body: { success: false, code: 'BATCH_NOT_FOUND', next_action: 'none', retryable: false } };
  };
  try {
    context.runK2RecoveryOnly();
    const config = context.K2Config.getConfig();
    // Previously this record stayed pending forever, holding a scarce slot until
    // the 30-day retention sweep.
    assertEqual(0, context.K2RecoveryStore.listPending(config, new Date()).length);
    assertEqual(true, context.K2RecoveryStore.load(batchUid).terminal_failure);
  } finally {
    context.K2VssApiClient.postJson = originalPostJson;
  }
}]);

tests.push(['a record that keeps failing goes terminal once the failure budget is spent', () => {
  resetScriptProperties();
  const manifest = recoveryManifest();
  const batchUid = context.K2BatchIdentity.batchUid(manifest);
  const slot = context.K2TriggerService.resolveSlot(new Date('2026-07-22T02:15:00Z'), 'Asia/Jakarta');
  context.K2RecoveryStore.save(context.K2RecoveryStore.fromRegistration(batchUid, slot, 'Asia/Jakarta', manifest, { state: 'AON_SUCCESS', next_action: 'pr' }));
  const originalPostJson = context.K2VssApiClient.postJson;
  const originalGetConfig = context.K2Config.getConfig;
  const budgetConfig = sampleConfig({ recoveryMaxConsecutiveFailures: 3 });
  context.K2Config.getConfig = function () { return budgetConfig; };
  // Retryable failure: never terminal on its own, so only the budget stops it.
  context.K2VssApiClient.postJson = function () {
    return { body: { success: false, code: 'LOCK_CONFLICT', next_action: 'none', retryable: true } };
  };
  try {
    for (let i = 0; i < 2; i++) context.runK2RecoveryOnly();
    assertEqual(false, !!context.K2RecoveryStore.load(batchUid).terminal_failure);
    assertEqual(2, context.K2RecoveryStore.load(batchUid).consecutive_failures);
    context.runK2RecoveryOnly(); // 3rd failure spends the configured budget
    assertEqual(true, context.K2RecoveryStore.load(batchUid).terminal_failure);
  } finally {
    context.K2VssApiClient.postJson = originalPostJson;
    context.K2Config.getConfig = originalGetConfig;
  }
}]);

tests.forEach(([name, fn]) => {
  try {
    fn();
    console.log(`PASS ${name}`);
  } catch (error) {
    failures += 1;
    console.log(`FAIL ${name}: ${error.message}`);
  }
});

if (failures > 0) {
  console.log(`${failures} Apps Script test(s) failed.`);
  process.exit(1);
}

console.log(`${tests.length} Apps Script test(s) passed.`);

function sampleConfig(overrides = {}) {
  return Object.assign(context.K2Config.getConfig({
    SOURCE_FOLDER_ID: 'source-folder',
    ARCHIVE_FOLDER_ID: 'archive-folder',
    VSS_BASE_URL: 'https://vss.example.test/vms',
    VSS_CLIENT_ID: 'client-a',
    VSS_HMAC_SECRET: 'test-secret',
    DRY_RUN: 'false'
  }), overrides);
}

function file(id, name, modifiedTime, size = 10) {
  return { id, name, modifiedTime, size, parentIds: ['source-folder'] };
}

// Janitor file handle: exposes name/modifiedTime and a moveTo spy that records the
// destination folder id into `moved`. Mirrors the shape janitorSweep reads.
function janitorFile(name, modifiedTime, moved) {
  return { name, modifiedTime, moveTo: (folder) => { moved.push(folder.id); } };
}

// Isolated Script Properties mock for discovery-sweep tests (so marker state
// doesn't leak into the shared global scriptProperties store).
function makeProps() {
  const store = {};
  return {
    getProperties: () => Object.assign({}, store),
    getProperty: (k) => (Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null),
    setProperty: (k, v) => { store[k] = String(v); },
    deleteProperty: (k) => { delete store[k]; }
  };
}

function sourceFiles() {
  return [
    { dataset_type: 'pr', drive_file_id: 'pr-id', original_filename: 'pr.xlsx', modified_timestamp: '2026-07-22T01:00:00Z', file_size_bytes: 1, sha256_content_hash: 'a'.repeat(64) },
    { dataset_type: 'catalog', drive_file_id: 'cat-id', original_filename: 'catalog.xlsx', modified_timestamp: '2026-07-22T01:01:00Z', file_size_bytes: 2, sha256_content_hash: 'b'.repeat(64) },
    { dataset_type: 'price', drive_file_id: 'price-id', original_filename: 'price.xlsx', modified_timestamp: '2026-07-22T01:02:00Z', file_size_bytes: 3, sha256_content_hash: 'c'.repeat(64) }
  ];
}

function recoveryManifest() {
  const contents = recoveryContents();
  return [
    recoveryEntry('pr', 'recover-pr', 'Report Ticket Monitoring GP Officer.xlsx', contents.pr),
    recoveryEntry('catalog', 'recover-catalog', 'Master Item Catalog.xlsx', contents.catalog),
    recoveryEntry('price', 'recover-price', 'Price Per Vendor And Tiering.xlsx', contents.price)
  ];
}

function recoveryEntry(datasetType, id, filename, content) {
  return {
    dataset_type: datasetType,
    drive_file_id: id,
    original_filename: filename,
    modified_timestamp: '2026-07-22T01:00:00.000000Z',
    file_size_bytes: Buffer.byteLength(content),
    sha256_content_hash: sha256Text(content)
  };
}

function recoveryContents() {
  return {
    pr: 'synthetic-pr',
    catalog: 'synthetic-catalog',
    price: 'synthetic-price'
  };
}

function recoveryBlobs() {
  const contents = recoveryContents();
  return {
    'recover-pr': blob(contents.pr),
    'recover-catalog': blob(contents.catalog),
    'recover-price': blob(contents.price)
  };
}

function recoveryDriveFiles(manifest, parentIds) {
  const blobs = recoveryBlobs();
  return manifest.map((entry) => ({
    id: entry.drive_file_id,
    name: entry.original_filename,
    size: entry.file_size_bytes,
    modifiedTime: entry.modified_timestamp,
    parentIds: parentIds.slice(),
    blob: blobs[entry.drive_file_id],
    file: {
      moveTo: function (folder) {
        this.movedTo = folder.id;
      }
    }
  }));
}

function processingArchiveStatus(manifest, attemptNumber) {
  return {
    success: true,
    code: 'BATCH_STATUS',
    state: 'ARCHIVE_PENDING',
    archive_state: 'PROCESSING',
    next_action: 'archive',
    operation_status: 'RUNNING',
    current_attempt_number: attemptNumber,
    result: { registered_manifest: manifest }
  };
}

function terminalAonStatus() {
  return {
    success: true,
    code: 'AON_VALIDATION_FAILED',
    state: 'AON_FAILED_FINAL',
    archive_state: 'NOT_READY',
    next_action: 'none',
    retryable: false,
    message: 'Synthetic final AON validation failure.'
  };
}

function blob(value) {
  const bytes = Array.from(Buffer.from(value, 'utf8'));
  return { bytes, getBytes: () => bytes };
}

function sha256Text(value) {
  return crypto.createHash('sha256').update(value, 'utf8').digest('hex');
}

function resetScriptProperties() {
  Object.keys(scriptProperties).forEach((key) => {
    delete scriptProperties[key];
  });
}

function toSignedBytes(buffer) {
  return Array.from(buffer).map((byte) => (byte > 127 ? byte - 256 : byte));
}

function pad(value) {
  return String(value).padStart(2, '0');
}

function assertEqual(expected, actual) {
  const expectedValue = typeof expected === 'object' ? JSON.stringify(expected) : expected;
  const actualValue = typeof actual === 'object' ? JSON.stringify(actual) : actual;
  if (expectedValue !== actualValue) {
    throw new Error(`expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
  }
}

function assertNotEqual(left, right) {
  if (left === right) {
    throw new Error(`expected values to differ: ${left}`);
  }
}

function assertFalse(value) {
  if (value) {
    throw new Error(`expected falsey value, got ${JSON.stringify(value)}`);
  }
}

function assertThrows(fn) {
  let threw = false;
  try {
    fn();
  } catch (error) {
    threw = true;
  }
  if (!threw) {
    throw new Error('expected function to throw');
  }
}
