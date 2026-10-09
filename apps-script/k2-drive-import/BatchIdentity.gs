var K2BatchIdentity = (function () {
  var DATASET_ORDER = ['pr', 'catalog', 'price'];

  function normalizeSourceFiles(files) {
    var byType = {};
    files.forEach(function (file) {
      byType[file.dataset_type] = file;
    });
    return DATASET_ORDER.map(function (datasetType) {
      var file = byType[datasetType];
      if (!file) throw new Error('Missing dataset type: ' + datasetType);
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

  function sourceManifest(files) {
    return normalizeSourceFiles(files);
  }

  function sourceManifestHash(files) {
    return sha256Hex(JSON.stringify({
      schema: 'k2-import-source-manifest-v1',
      files: keyedSourceManifest(files)
    }));
  }

  function batchUid(files) {
    return 'k2b_' + sourceManifestHash(files);
  }

  function requestId(batchUidValue, operation, attemptNumber) {
    return 'gasreq_' + sha256Hex(canonicalJson({
      schema: 'k2-apps-script-request-v1',
      batch_uid: batchUidValue,
      operation_type: operation,
      attempt_number: Number(attemptNumber)
    }));
  }

  function fileManifest(entries) {
    return entries.map(function (entry) {
      return {
        field: entry.field,
        filename: entry.filename,
        file_size_bytes: Number(entry.file_size_bytes),
        sha256_content_hash: String(entry.sha256_content_hash).toLowerCase()
      };
    });
  }

  function keyedSourceManifest(files) {
    var normalized = sourceManifest(files);
    var keyed = {};
    normalized.forEach(function (file) {
      keyed[file.dataset_type] = file;
    });
    return keyed;
  }

  function canonicalJson(value) {
    return JSON.stringify(sortRecursively(value));
  }

  function sortRecursively(value) {
    if (Array.isArray(value)) return value.map(sortRecursively);
    if (value && typeof value === 'object' && !(value instanceof Date)) {
      var out = {};
      Object.keys(value).sort().forEach(function (key) {
        out[key] = sortRecursively(value[key]);
      });
      return out;
    }
    return value;
  }

  function sha256Hex(value) {
    var bytes = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, value, Utilities.Charset.UTF_8);
    return bytesToHex(bytes);
  }

  function bytesToHex(bytes) {
    return bytes.map(function (byte) {
      var unsigned = byte < 0 ? byte + 256 : byte;
      return ('0' + unsigned.toString(16)).slice(-2);
    }).join('');
  }

  function utcIso(value) {
    var date = value instanceof Date ? value : new Date(value);
    return date.toISOString().replace(/\.\d{3}Z$/, '.000000Z');
  }

  return {
    DATASET_ORDER: DATASET_ORDER,
    normalizeSourceFiles: normalizeSourceFiles,
    sourceManifest: sourceManifest,
    keyedSourceManifest: keyedSourceManifest,
    sourceManifestHash: sourceManifestHash,
    batchUid: batchUid,
    requestId: requestId,
    fileManifest: fileManifest,
    canonicalJson: canonicalJson,
    sha256Hex: sha256Hex,
    bytesToHex: bytesToHex,
    utcIso: utcIso
  };
})();
