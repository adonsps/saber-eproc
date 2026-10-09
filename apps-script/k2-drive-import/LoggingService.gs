var K2LoggingService = (function () {
  function info(code, context) {
    log('INFO', code, context || {});
  }

  function warn(code, context) {
    log('WARN', code, context || {});
  }

  function error(code, context) {
    log('ERROR', code, context || {});
  }

  function log(level, code, context) {
    var safe = redact(context || {});
    var line = JSON.stringify({ level: level, code: code, context: safe });
    if (typeof console !== 'undefined' && console.log) {
      console.log(line);
    } else if (typeof Logger !== 'undefined') {
      Logger.log(line);
    }
  }

  function redact(value) {
    if (Array.isArray(value)) return value.map(redact);
    if (value && typeof value === 'object') {
      var out = {};
      Object.keys(value).forEach(function (key) {
        if (/secret|signature|token|password/i.test(key)) {
          out[key] = '[redacted]';
        } else {
          out[key] = redact(value[key]);
        }
      });
      return out;
    }
    return value;
  }

  return {
    info: info,
    warn: warn,
    error: error,
    redact: redact
  };
})();
