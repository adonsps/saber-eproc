var K2VssApiClient = (function () {
  var ENDPOINT_FILES = {
    register_batch: 'register_batch.php',
    batch_status: 'batch_status.php',
    aon: 'import_aon.php',
    pr: 'import_pr.php',
    start_archive: 'start_archive.php',
    archive: 'archive_result.php'
  };

  function canonicalSignatureString(method, path, clientId, timestamp, nonce, requestId, operation, batchUid, metadataSha256, fileManifestSha256) {
    return [
      String(method).toUpperCase(),
      normalizePath(path),
      clientId,
      timestamp,
      nonce,
      requestId,
      operation,
      batchUid,
      String(metadataSha256).toLowerCase(),
      String(fileManifestSha256).toLowerCase()
    ].join('\n');
  }

  function sign(secret, canonical) {
    var bytes = Utilities.computeHmacSha256Signature(canonical, secret, Utilities.Charset.UTF_8);
    return K2BatchIdentity.bytesToHex(bytes);
  }

  function headers(config, endpoint, batchUid, operation, requestId, metadata, fileManifest, nonce, timestamp) {
    var path = endpointPath(config, endpoint);
    var metadataHash = K2BatchIdentity.sha256Hex(K2BatchIdentity.canonicalJson(metadata || {}));
    var manifestHash = K2BatchIdentity.sha256Hex(K2BatchIdentity.canonicalJson({ files: fileManifest || [] }));
    var canonical = canonicalSignatureString(
      'POST',
      path,
      config.clientId,
      timestamp,
      nonce,
      requestId,
      operation,
      batchUid,
      metadataHash,
      manifestHash
    );
    return {
      'X-K2-Client-ID': config.clientId,
      'X-K2-Timestamp': timestamp,
      'X-K2-Nonce': nonce,
      'X-K2-Request-ID': requestId,
      'X-K2-Batch-UID': batchUid,
      'X-K2-Operation': operation,
      'X-K2-Metadata-SHA256': metadataHash,
      'X-K2-File-Manifest-SHA256': manifestHash,
      'X-K2-Signature': sign(config.hmacSecret, canonical)
    };
  }

  function postJson(config, endpoint, operation, batchUid, requestId, metadata) {
    return requestWithRetries(config, endpoint, operation, batchUid, requestId, metadata, [], null);
  }

  function postFiles(config, endpoint, operation, batchUid, requestId, metadata, fileManifest, blobs) {
    return requestWithRetries(config, endpoint, operation, batchUid, requestId, metadata, fileManifest, blobs || {});
  }

  function requestWithRetries(config, endpoint, operation, batchUid, requestId, metadata, fileManifest, blobs) {
    if (config.dryRun) {
      return { dry_run: true, status: 0, body: { success: true, code: 'CLIENT_DRY_RUN', next_action: 'none' } };
    }
    K2Config.assertValidFor(config, 'api');
    var attempts = Math.max(1, config.apiMaxRetries || 1);
    var last = null;
    for (var i = 0; i < attempts; i++) {
      var nonce = newNonce();
      var timestamp = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
      var hdrs = headers(config, endpoint, batchUid, operation, requestId, metadata, fileManifest, nonce, timestamp);
      var options = buildFetchOptions(hdrs, metadata, blobs);
      var response;
      try {
        response = UrlFetchApp.fetch(endpointUrl(config, endpoint), options);
      } catch (e) {
        last = {
          status: 0,
          body: {
            success: false,
            code: 'TRANSPORT_EXCEPTION',
            retryable: true,
            message: 'Network request failed before an HTTP response was received.'
          }
        };
        continue;
      }
      last = parseResponse(response);
      if (!isRetryableTransport(last.status)) return last;
      Utilities.sleep(Math.min(2000, (config.apiRetryBaseMs || 250) * (i + 1)));
    }
    return last;
  }

  function buildFetchOptions(headers, metadata, blobs) {
    if (blobs) {
      var payload = { metadata: K2BatchIdentity.canonicalJson(metadata) };
      Object.keys(blobs).forEach(function (field) { payload[field] = blobs[field]; });
      return { method: 'post', muteHttpExceptions: true, headers: headers, payload: payload };
    }
    return {
      method: 'post',
      contentType: 'application/json',
      muteHttpExceptions: true,
      headers: headers,
      payload: K2BatchIdentity.canonicalJson(metadata)
    };
  }

  function parseResponse(response) {
    var text = response.getContentText();
    var body;
    try {
      body = JSON.parse(text);
    } catch (e) {
      body = {
        success: false,
        code: 'INVALID_JSON_RESPONSE',
        retryable: true,
        message: 'Server response was not valid JSON.'
      };
    }
    return { status: response.getResponseCode(), body: body };
  }

  function isRetryableTransport(status) {
    return status === 0 || status === 429 || status === 500 || status === 503 || status === 504;
  }

  function normalizePath(path) {
    var normalized = ('/' + String(path).split('?')[0].split('#')[0]).replace(/\/+/g, '/');
    normalized.split('/').forEach(function (segment) {
      if (segment === '.' || segment === '..') throw new Error('Invalid API path');
    });
    return normalized;
  }

  function endpointPath(config, endpoint) {
    if (!ENDPOINT_FILES[endpoint]) throw new Error('Unknown endpoint: ' + endpoint);
    if (!config.publicApiBasePath) throw new Error('Missing public API base path');
    return normalizePath(config.publicApiBasePath + '/' + ENDPOINT_FILES[endpoint]);
  }

  function endpointUrl(config, endpoint) {
    return origin(config.vssBaseUrl) + endpointPath(config, endpoint);
  }

  function origin(url) {
    var match = String(url || '').match(/^([a-z][a-z0-9+.-]*:\/\/[^\/?#]+)/i);
    if (!match) throw new Error('Invalid VSS base URL');
    return match[1].replace(/\/+$/, '');
  }

  function newNonce() {
    return Utilities.getUuid() + '-' + Date.now();
  }

  return {
    ENDPOINT_FILES: ENDPOINT_FILES,
    canonicalSignatureString: canonicalSignatureString,
    sign: sign,
    headers: headers,
    postJson: postJson,
    postFiles: postFiles,
    requestWithRetries: requestWithRetries,
    isRetryableTransport: isRetryableTransport,
    normalizePath: normalizePath,
    endpointPath: endpointPath,
    endpointUrl: endpointUrl,
    newNonce: newNonce
  };
})();
