const crypto = require('crypto');
const http = require('http');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const BASE_URL = process.env.K2_HTTP_TEST_BASE_URL || 'http://127.0.0.1:18087';
const CLIENT_ID = 'client-a';
const SECRET = 'test-secret';
const API_BASE_PATH = '/vms/workspace/api/k2_automation';
const DATASET_ORDER = ['pr', 'catalog', 'price'];
const FIXTURES_DIR = process.env.K2_HTTP_HARNESS_FIXTURES_DIR || '/tmp/k2_http_harness_fixtures';
const STORAGE_ROOT = process.env.K2_AUTOMATION_STORAGE_ROOT || '/tmp/k2-automation-http-harness-storage';
const WORKER_PHP = process.env.K2_HTTP_HARNESS_WORKER_PHP || '/var/www/html/workspace/cron/process_k2_import_queue.php';
const GENERATOR_PHP = process.env.K2_HTTP_HARNESS_GENERATOR_PHP || '/var/www/html/workspace/automation/http_harness/generate_fixtures.php';

let nonceCounter = 0;

// This harness now exercises the ASYNC queue architecture (Area F):
// import_aon/import_pr are expected to return 202 + *_QUEUED immediately with
// no business write, and the CLI worker is invoked as a real subprocess (same
// binary as production cron) to actually resolve each queued attempt before
// the harness polls batch_status for the final state. Real (if minimal)
// XLSX fixtures are used — a synthetic non-XLSX byte string cannot survive
// the real worker's normalizer, which is no longer bypassable via a fake
// executor now that the queue-only rework removed those test doubles.
async function main() {
  const fixtures = generateFixtures();
  const files = sourceFilesFromFixtures('phase8f', fixtures);
  const batchUid = batchUidFor(files);

  await expectCode('multipart required', postJson('import_aon', 'aon', batchUid, requestId(batchUid, 'aon', 1), operationPayload(batchUid, files, [], 1)), 400, 'MULTIPART_REQUIRED');

  const wrongPath = await postJson('register_batch', 'register_batch', batchUid, requestId(batchUid, 'register_batch', 1), registerPayload(batchUid, files), {
    actualPath: '/workspace/api/k2_automation/register_batch.php',
    signedPath: '/workspace/api/k2_automation/register_batch.php'
  });
  assertEqual('PUBLIC_API_PATH_MISMATCH', wrongPath.body.code, 'wrong path code');
  assert([400, 401].includes(wrongPath.status), `wrong path HTTP status: ${wrongPath.status}`);

  // Expired timestamp: router.php's controller clock is fixed at 2026-07-22T02:00:00Z
  // with a 300s allowed skew, so a timestamp an hour earlier must be rejected.
  const expiredHeaders = signedHeaders('register_batch', 'register_batch', batchUid, requestId(batchUid, 'register_batch', 1), registerPayload(batchUid, files), [], undefined, '2026-07-22T01:00:00Z');
  const expired = await httpRequest(endpointPath('register_batch'), expiredHeaders, Buffer.from(canonicalJson(registerPayload(batchUid, files))), 'application/json');
  assertEqual(401, expired.status, 'expired timestamp status');
  assertEqual('EXPIRED_TIMESTAMP', expired.body.code, 'expired timestamp code');

  const registered = await postJson('register_batch', 'register_batch', batchUid, requestId(batchUid, 'register_batch', 1), registerPayload(batchUid, files));
  assertEqual(201, registered.status, 'register status ' + JSON.stringify(registered.body));
  assertEqual('BATCH_REGISTERED', registered.body.code, 'register code');
  assertEqual(3, registered.body.result.registered_manifest.length, 'registered manifest count');

  // Nonce replay: reusing the exact same nonce for a second request must be rejected.
  const replayNonce = 'nonce-fixed-replay';
  const first = await postJson('batch_status', 'batch_status', batchUid, requestId(batchUid, 'batch_status', 1), { batch_uid: batchUid }, { nonce: replayNonce });
  assertEqual(200, first.status, 'first batch_status with fixed nonce');
  const replayed = await postJson('batch_status', 'batch_status', batchUid, requestId(batchUid, 'batch_status', 1), { batch_uid: batchUid }, { nonce: replayNonce });
  assertEqual(409, replayed.status, 'nonce replay status');
  assertEqual('NONCE_REPLAY', replayed.body.code, 'nonce replay code');

  const wrongHashManifest = fileManifest([
    manifestEntry('catalog_file', files.catalog, Buffer.from('wrong-catalog')),
    manifestEntry('price_file', files.price, files.price.content)
  ]);
  const wrongHash = await postMultipart(
    'import_aon',
    'aon',
    batchUid,
    requestId(batchUid, 'aon-wrong-hash', 1),
    Object.assign(operationPayload(batchUid, files, wrongHashManifest, 1), { request_id_for_test: 'wrong-hash' }),
    wrongHashManifest,
    {
      catalog_file: { filename: files.catalog.original_filename, content: files.catalog.content },
      price_file: { filename: files.price.original_filename, content: files.price.content }
    }
  );
  assertEqual(422, wrongHash.status, 'wrong hash HTTP status');

  // Incorrect filename in the signed manifest (content/hash correct, filename wrong).
  // Filename is metadata-only (checksum is authoritative), so this is expected to
  // queue successfully — that means it would consume attempt_number=1 on whatever
  // batch it targets, so it runs against its OWN disposable batch/files rather
  // than the shared batchUid used for the main success flow below.
  const filenameCheckFiles = sourceFilesFromFixtures('phase8f-filename-check', fixtures);
  const filenameCheckBatchUid = batchUidFor(filenameCheckFiles);
  const filenameCheckRegister = await postJson('register_batch', 'register_batch', filenameCheckBatchUid, requestId(filenameCheckBatchUid, 'register_batch', 1), registerPayload(filenameCheckBatchUid, filenameCheckFiles));
  assertEqual(201, filenameCheckRegister.status, 'filename-check batch register status');

  const wrongFilenameManifest = fileManifest([
    { field: 'catalog_file', filename: 'not-the-real-name.xlsx', file_size_bytes: filenameCheckFiles.catalog.content.length, sha256_content_hash: sha256(filenameCheckFiles.catalog.content) },
    manifestEntry('price_file', filenameCheckFiles.price, filenameCheckFiles.price.content)
  ]);
  const wrongFilename = await postMultipart(
    'import_aon', 'aon', filenameCheckBatchUid, requestId(filenameCheckBatchUid, 'aon-wrong-filename', 1),
    operationPayload(filenameCheckBatchUid, filenameCheckFiles, wrongFilenameManifest, 1), wrongFilenameManifest,
    {
      catalog_file: { filename: filenameCheckFiles.catalog.original_filename, content: filenameCheckFiles.catalog.content },
      price_file: { filename: filenameCheckFiles.price.original_filename, content: filenameCheckFiles.price.content }
    }
  );
  assertEqual(202, wrongFilename.status, 'mismatched-filename-but-correct-hash still queues');
  assertEqual('AON_QUEUED', wrongFilename.body.state, 'mismatched filename state');

  // Incorrect manifest: signed manifest hash does not match what postMultipart actually sends.
  const incorrectManifestReq = await postMultipart(
    'import_aon', 'aon', batchUid, requestId(batchUid, 'aon-incorrect-manifest', 1),
    operationPayload(batchUid, files, fileManifest([
      manifestEntry('catalog_file', files.catalog, files.catalog.content),
      manifestEntry('price_file', files.price, files.price.content)
    ]), 1),
    [], // signed with an EMPTY manifest while files are actually attached below -> hash mismatch
    {
      catalog_file: { filename: files.catalog.original_filename, content: files.catalog.content },
      price_file: { filename: files.price.original_filename, content: files.price.content }
    }
  );
  assertEqual(422, incorrectManifestReq.status, 'incorrect manifest status');

  // Wrong operation: sign the request as "pr" but call the import_aon endpoint.
  const wrongOperation = await postMultipart(
    'import_aon', 'pr', batchUid, requestId(batchUid, 'aon-wrong-op', 1),
    operationPayload(batchUid, files, fileManifest([
      manifestEntry('catalog_file', files.catalog, files.catalog.content),
      manifestEntry('price_file', files.price, files.price.content)
    ]), 1),
    fileManifest([
      manifestEntry('catalog_file', files.catalog, files.catalog.content),
      manifestEntry('price_file', files.price, files.price.content)
    ]),
    {
      catalog_file: { filename: files.catalog.original_filename, content: files.catalog.content },
      price_file: { filename: files.price.original_filename, content: files.price.content }
    }
  );
  assert([400, 422].includes(wrongOperation.status), `wrong operation status: ${wrongOperation.status}`);

  const aonAttemptOneId = requestId(batchUid, 'aon', 1);
  const aonManifest = fileManifest([
    manifestEntry('catalog_file', files.catalog, files.catalog.content),
    manifestEntry('price_file', files.price, files.price.content)
  ]);
  const aonQueue = await postMultipart(
    'import_aon',
    'aon',
    batchUid,
    aonAttemptOneId,
    Object.assign(operationPayload(batchUid, files, aonManifest, 1), { request_id_for_test: aonAttemptOneId }),
    aonManifest,
    {
      catalog_file: { filename: files.catalog.original_filename, content: files.catalog.content },
      price_file: { filename: files.price.original_filename, content: files.price.content }
    }
  );
  assertEqual(202, aonQueue.status, 'AON queue status');
  assertEqual('AON_QUEUED', aonQueue.body.state, 'AON queued state');

  // Duplicate logical request: replay the exact same signed request while it is
  // still queued/unclaimed — must return the SAME in-progress result, not a new attempt.
  const aonDuplicate = await postMultipart(
    'import_aon', 'aon', batchUid, aonAttemptOneId,
    Object.assign(operationPayload(batchUid, files, aonManifest, 1), { request_id_for_test: aonAttemptOneId }),
    aonManifest,
    {
      catalog_file: { filename: files.catalog.original_filename, content: files.catalog.content },
      price_file: { filename: files.price.original_filename, content: files.price.content }
    }
  );
  assertEqual(202, aonDuplicate.status, 'AON duplicate logical request status');
  assert(['REQUEST_IN_PROGRESS', 'REQUEST_REPLAYED'].includes(aonDuplicate.body.code), `AON duplicate code: ${aonDuplicate.body.code}`);

  // Archive guard: must be rejected before AON/PR have succeeded.
  const earlyArchive = await postJson('start_archive', 'archive', batchUid, requestId(batchUid, 'archive-early', 1), { batch_uid: batchUid, attempt_number: 1 });
  assert(earlyArchive.body.success === false, 'archive must be rejected before AON/PR success');

  console.log('Invoking the real CLI worker for the AON attempt...');
  runWorker();

  const aonStatus = await pollBatchStatus(batchUid, 'AON_SUCCESS', 30);
  assertEqual('AON_SUCCESS', aonStatus.body.state, 'AON worker result state');

  const prManifest = fileManifest([manifestEntry('pr_file', files.pr, files.pr.content)]);
  const prRequestId = requestId(batchUid, 'pr', 1);
  const prQueue = await postMultipart(
    'import_pr',
    'pr',
    batchUid,
    prRequestId,
    Object.assign(operationPayload(batchUid, files, prManifest, 1), { request_id_for_test: prRequestId }),
    prManifest,
    { pr_file: { filename: files.pr.original_filename, content: files.pr.content } }
  );
  assertEqual(202, prQueue.status, 'PR queue status');
  assertEqual('PR_QUEUED', prQueue.body.state, 'PR queued state');

  console.log('Invoking the real CLI worker for the PR attempt...');
  runWorker();

  const prStatus = await pollBatchStatus(batchUid, 'ARCHIVE_PENDING', 30);
  assertEqual('ARCHIVE_PENDING', prStatus.body.state, 'PR worker result state (archive-ready)');
  assertEqual('PENDING', prStatus.body.archive_state, 'archive state pending after both successes');

  const archiveRequestId = requestId(batchUid, 'archive', 1);
  const start = await postJson('start_archive', 'archive', batchUid, archiveRequestId, { batch_uid: batchUid, attempt_number: 1 });
  assertEqual(200, start.status, 'archive start status');
  assertEqual('PROCESSING', start.body.archive_state, 'archive start state');
  assertEqual(3, start.body.result.registered_manifest.length, 'archive start manifest count');

  const archivePayload = Object.assign(operationPayload(batchUid, files, [], 1), {
    archive_success: true,
    retryable: false,
    error_code: null,
    safe_message: null
  });
  const archive = await postJson('archive_result', 'archive', batchUid, archiveRequestId, archivePayload);
  assertEqual(200, archive.status, 'archive result status');
  assertEqual('COMPLETED', archive.body.state, 'archive completed state');

  const archiveReplay = await postJson('archive_result', 'archive', batchUid, archiveRequestId, archivePayload);
  assertEqual(200, archiveReplay.status, 'archive replay status');
  assertEqual('REQUEST_REPLAYED', archiveReplay.body.code, 'archive replay code');

  const status = await postJson('batch_status', 'batch_status', batchUid, requestId(batchUid, 'batch_status_final', 1), { batch_uid: batchUid });
  assertEqual(200, status.status, 'terminal status HTTP');
  assertEqual('COMPLETED', status.body.state, 'terminal status state');

  console.log('HTTP multipart async integration harness passed.');
}

function generateFixtures() {
  if (!fs.existsSync(FIXTURES_DIR)) {
    fs.mkdirSync(FIXTURES_DIR, { recursive: true });
  }
  execFileSync('php', [GENERATOR_PHP, FIXTURES_DIR], { stdio: 'inherit' });

  return {
    catalog: fs.readFileSync(path.join(FIXTURES_DIR, 'catalog.xlsx')),
    price: fs.readFileSync(path.join(FIXTURES_DIR, 'price.xlsx')),
    pr: fs.readFileSync(path.join(FIXTURES_DIR, 'pr.xlsx'))
  };
}

function runWorker() {
  const env = Object.assign({}, process.env, {
    K2_AUTOMATION_STORAGE_ROOT: STORAGE_ROOT,
    K2_AUTOMATION_CLIENT_ID: CLIENT_ID,
    K2_AUTOMATION_HMAC_SECRET: SECRET,
    K2_AON_MAX_REMOVAL_RATIO: process.env.K2_AON_MAX_REMOVAL_RATIO || '0.5'
  });
  // --limit=5 drains every currently-queued attempt (not just one): the harness
  // registers more than one batch (the main flow plus the filename-mismatch
  // disposable batch), and the worker claims the OLDEST queued attempt first,
  // which is not necessarily the one this particular call is waiting on.
  const output = execFileSync('php', [WORKER_PHP, '--limit=5'], { env }).toString('utf8');
  console.log(output.trim());
}

async function pollBatchStatus(batchUid, expectedState, maxAttempts) {
  let last = null;
  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    last = await postJson('batch_status', 'batch_status', batchUid, requestId(batchUid, `poll-${attempt}`, 1), { batch_uid: batchUid });
    if (last.body.state === expectedState) {
      return last;
    }
    await sleep(200);
  }
  throw new Error(`Timed out waiting for state ${expectedState}, last seen: ${last ? last.body.state : 'none'}`);
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function registerPayload(batchUidValue, files) {
  return {
    batch_uid: batchUidValue,
    scheduled_slot_at_utc: '2026-07-22T02:00:00.000000Z',
    schedule_timezone: 'Asia/Jakarta',
    source_files: sourceManifest(files)
  };
}

function operationPayload(batchUidValue, files, manifest, attemptNumberValue) {
  return Object.assign(registerPayload(batchUidValue, files), {
    attempt_number: attemptNumberValue,
    file_manifest: manifest
  });
}

function sourceFilesFromFixtures(suffix, fixtures) {
  const raw = { pr: fixtures.pr, catalog: fixtures.catalog, price: fixtures.price };
  const files = {};
  DATASET_ORDER.forEach((datasetType, index) => {
    files[datasetType] = {
      dataset_type: datasetType,
      drive_file_id: `drive-${suffix}-${datasetType}`,
      original_filename: `${suffix}-${datasetType}.xlsx`,
      modified_timestamp: `2026-07-22T01:0${index}:00.000000Z`,
      file_size_bytes: raw[datasetType].length,
      sha256_content_hash: sha256(raw[datasetType]),
      content: raw[datasetType]
    };
  });
  return files;
}

function sourceManifest(files) {
  return DATASET_ORDER.map((datasetType) => {
    const file = files[datasetType] || files.find((item) => item.dataset_type === datasetType);
    return {
      dataset_type: datasetType,
      drive_file_id: String(file.drive_file_id),
      original_filename: String(file.original_filename),
      modified_timestamp: utcIso(file.modified_timestamp),
      file_size_bytes: Number(file.file_size_bytes),
      sha256_content_hash: String(file.sha256_content_hash).toLowerCase()
    };
  });
}

function batchUidFor(files) {
  return 'k2b_' + sha256(JSON.stringify({ schema: 'k2-import-source-manifest-v1', files: keyedSourceManifest(files) }));
}

function requestId(batchUidValue, operation, attemptNumberValue) {
  return 'gasreq_' + sha256(canonicalJson({
    schema: 'k2-apps-script-request-v1',
    batch_uid: batchUidValue,
    operation_type: operation,
    attempt_number: Number(attemptNumberValue)
  }));
}

function fileManifest(entries) {
  return entries.map((entry) => ({
    field: entry.field,
    filename: entry.filename,
    file_size_bytes: Number(entry.file_size_bytes),
    sha256_content_hash: String(entry.sha256_content_hash).toLowerCase()
  }));
}

function keyedSourceManifest(files) {
  const keyed = {};
  sourceManifest(files).forEach((file) => {
    keyed[file.dataset_type] = file;
  });
  return keyed;
}

function manifestEntry(field, file, content) {
  return {
    field,
    filename: file.original_filename,
    file_size_bytes: content.length,
    sha256_content_hash: sha256(content)
  };
}

async function postJson(endpoint, operation, batchUidValue, requestIdValue, metadata, options = {}) {
  const raw = canonicalJson(metadata);
  const headers = signedHeaders(endpoint, operation, batchUidValue, requestIdValue, metadata, [], options.signedPath, options.timestamp, options.nonce);
  return httpRequest(options.actualPath || endpointPath(endpoint), headers, Buffer.from(raw), 'application/json');
}

async function postMultipart(endpoint, operation, batchUidValue, requestIdValue, metadata, manifest, files, options = {}) {
  const boundary = '----k2phase8f' + crypto.randomBytes(8).toString('hex');
  const parts = [];
  const metadataRaw = canonicalJson(metadata);
  parts.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="metadata"\r\n\r\n${metadataRaw}\r\n`));
  Object.keys(files).forEach((field) => {
    const file = files[field];
    parts.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${field}"; filename="${file.filename}"\r\nContent-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\n`));
    parts.push(file.content);
    parts.push(Buffer.from('\r\n'));
  });
  parts.push(Buffer.from(`--${boundary}--\r\n`));
  const body = Buffer.concat(parts);
  const headers = signedHeaders(endpoint, operation, batchUidValue, requestIdValue, metadata, manifest, options.signedPath);
  return httpRequest(options.actualPath || endpointPath(endpoint), headers, body, `multipart/form-data; boundary=${boundary}`);
}

function signedHeaders(endpoint, operation, batchUidValue, requestIdValue, metadata, manifest, signedPath, timestampOverride, nonceOverride) {
  const timestamp = timestampOverride || '2026-07-22T02:00:00Z';
  const nonce = nonceOverride || ('nonce-' + (++nonceCounter) + '-' + crypto.randomBytes(4).toString('hex'));
  const metadataHash = sha256(canonicalJson(metadata || {}));
  const manifestHash = sha256(canonicalJson({ files: manifest || [] }));
  const canonical = [
    'POST',
    normalizePath(signedPath || endpointPath(endpoint)),
    CLIENT_ID,
    timestamp,
    nonce,
    requestIdValue,
    operation,
    batchUidValue,
    metadataHash,
    manifestHash
  ].join('\n');
  return {
    'X-K2-Client-ID': CLIENT_ID,
    'X-K2-Timestamp': timestamp,
    'X-K2-Nonce': nonce,
    'X-K2-Request-ID': requestIdValue,
    'X-K2-Batch-UID': batchUidValue,
    'X-K2-Operation': operation,
    'X-K2-Metadata-SHA256': metadataHash,
    'X-K2-File-Manifest-SHA256': manifestHash,
    'X-K2-Signature': crypto.createHmac('sha256', SECRET).update(canonical, 'utf8').digest('hex')
  };
}

function httpRequest(path, headers, body, contentType) {
  const url = new URL(BASE_URL + path);
  return new Promise((resolve, reject) => {
    const req = http.request({
      hostname: url.hostname,
      port: url.port,
      path: url.pathname,
      method: 'POST',
      headers: Object.assign({}, headers, {
        'Content-Type': contentType,
        'Content-Length': body.length
      })
    }, (res) => {
      const chunks = [];
      res.on('data', (chunk) => chunks.push(chunk));
      res.on('end', () => {
        const text = Buffer.concat(chunks).toString('utf8');
        let parsed;
        try {
          parsed = JSON.parse(text);
        } catch (error) {
          reject(new Error(`Invalid JSON response ${res.statusCode}: ${text}`));
          return;
        }
        resolve({ status: res.statusCode, body: parsed });
      });
    });
    req.on('error', reject);
    req.end(body);
  });
}

async function expectCode(label, promise, status, code) {
  const result = await promise;
  assertEqual(status, result.status, `${label} status`);
  assertEqual(code, result.body.code, `${label} code`);
}

function endpointPath(endpoint) {
  const files = {
    register_batch: 'register_batch.php',
    batch_status: 'batch_status.php',
    import_aon: 'import_aon.php',
    import_pr: 'import_pr.php',
    start_archive: 'start_archive.php',
    archive_result: 'archive_result.php'
  };
  return `${API_BASE_PATH}/${files[endpoint]}`;
}

function normalizePath(path) {
  return ('/' + String(path).split('?')[0].split('#')[0]).replace(/\/+/g, '/');
}

function canonicalJson(value) {
  return JSON.stringify(sortRecursively(value));
}

function sortRecursively(value) {
  if (Array.isArray(value)) return value.map(sortRecursively);
  if (value && typeof value === 'object' && !(value instanceof Date) && !Buffer.isBuffer(value)) {
    const out = {};
    Object.keys(value).sort().forEach((key) => {
      out[key] = sortRecursively(value[key]);
    });
    return out;
  }
  return value;
}

function sha256(value) {
  return crypto.createHash('sha256').update(value).digest('hex');
}

function utcIso(value) {
  return new Date(value).toISOString().replace(/\.\d{3}Z$/, '.000000Z');
}

function assertEqual(expected, actual, label) {
  if (expected !== actual) {
    throw new Error(`${label}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
  }
}

function assertNotEqual(left, right, label) {
  if (left === right) {
    throw new Error(`${label}: expected values to differ`);
  }
}

function assert(condition, label) {
  if (!condition) {
    throw new Error(label);
  }
}

main().catch((error) => {
  console.error(error.stack || error.message);
  process.exit(1);
});
