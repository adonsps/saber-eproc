var K2Config = (function () {
  var REQUIRED = [
    'SOURCE_FOLDER_ID',
    'ARCHIVE_FOLDER_ID',
    'VSS_BASE_URL',
    'VSS_CLIENT_ID',
    'VSS_HMAC_SECRET'
  ];

  var DEFAULTS = {
    TIMEZONE: 'Asia/Jakarta',
    PR_FILENAME: 'Report Ticket Monitoring GP Officer.xlsx',
    CATALOG_FILENAME: 'Master Item Catalog.xlsx',
    PRICE_FILENAME: 'Price Per Vendor And Tiering.xlsx',
    DRY_RUN: 'true',
    FILE_MINIMUM_AGE_MINUTES: '10',
    FILE_STABILITY_INTERVAL_SECONDS: '5',
    API_MAX_RETRIES: '2',
    API_RETRY_BASE_MS: '250',
    ALLOW_NON_WORKDAY_RECOVERY: 'false',
    RECOVERY_BATCH_LIMIT: '2',
    RECOVERY_RECORD_LIMIT: '20',
    RECOVERY_RETENTION_DAYS: '30',
    TERMINAL_RECOVERY_RETENTION_DAYS: '7',
    RECOVERY_MAX_CONSECUTIVE_FAILURES: '30',
    OPTIONAL_HOLIDAY_DATES: '',
    NOTIFICATION_EMAILS: '',
    JANITOR_MIN_AGE_HOURS: '24'
  };

  function getConfig(overrides) {
    var props = {};
    if (typeof PropertiesService !== 'undefined' && PropertiesService.getScriptProperties) {
      props = PropertiesService.getScriptProperties().getProperties();
    }
    props = Object.assign({}, DEFAULTS, props, overrides || {});
    return {
      sourceFolderId: props.SOURCE_FOLDER_ID || '',
      archiveFolderId: props.ARCHIVE_FOLDER_ID || '',
      vssBaseUrl: trimTrailingSlash(props.VSS_BASE_URL || ''),
      publicApiBasePath: normalizeApiBasePath(props.VSS_PUBLIC_API_BASE_PATH || '', props.VSS_BASE_URL || ''),
      clientId: props.VSS_CLIENT_ID || '',
      hmacSecret: props.VSS_HMAC_SECRET || '',
      notificationEmails: splitCsv(props.NOTIFICATION_EMAILS || ''),
      timezone: props.TIMEZONE || DEFAULTS.TIMEZONE,
      dryRun: parseBool(props.DRY_RUN),
      fileMinimumAgeMinutes: parseInt(props.FILE_MINIMUM_AGE_MINUTES || DEFAULTS.FILE_MINIMUM_AGE_MINUTES, 10),
      fileStabilityIntervalSeconds: parseInt(props.FILE_STABILITY_INTERVAL_SECONDS || DEFAULTS.FILE_STABILITY_INTERVAL_SECONDS, 10),
      apiMaxRetries: parseInt(props.API_MAX_RETRIES || DEFAULTS.API_MAX_RETRIES, 10),
      apiRetryBaseMs: parseInt(props.API_RETRY_BASE_MS || DEFAULTS.API_RETRY_BASE_MS, 10),
      allowNonWorkdayRecovery: parseBool(props.ALLOW_NON_WORKDAY_RECOVERY || DEFAULTS.ALLOW_NON_WORKDAY_RECOVERY),
      recoveryBatchLimit: parseInt(props.RECOVERY_BATCH_LIMIT || DEFAULTS.RECOVERY_BATCH_LIMIT, 10),
      recoveryRecordLimit: parseInt(props.RECOVERY_RECORD_LIMIT || DEFAULTS.RECOVERY_RECORD_LIMIT, 10),
      recoveryRetentionDays: parseInt(props.RECOVERY_RETENTION_DAYS || DEFAULTS.RECOVERY_RETENTION_DAYS, 10),
      terminalRecoveryRetentionDays: parseInt(props.TERMINAL_RECOVERY_RETENTION_DAYS || DEFAULTS.TERMINAL_RECOVERY_RETENTION_DAYS, 10),
      recoveryMaxConsecutiveFailures: parseInt(props.RECOVERY_MAX_CONSECUTIVE_FAILURES || DEFAULTS.RECOVERY_MAX_CONSECUTIVE_FAILURES, 10),
      optionalHolidayDates: splitCsv(props.OPTIONAL_HOLIDAY_DATES || ''),
      janitorMinAgeHours: parseInt(props.JANITOR_MIN_AGE_HOURS || DEFAULTS.JANITOR_MIN_AGE_HOURS, 10),
      filenames: {
        pr: props.PR_FILENAME || DEFAULTS.PR_FILENAME,
        catalog: props.CATALOG_FILENAME || DEFAULTS.CATALOG_FILENAME,
        price: props.PRICE_FILENAME || DEFAULTS.PRICE_FILENAME
      }
    };
  }

  function validateRequired(config) {
    return validateFor(config, 'all');
  }

  function validateFor(config, context) {
    context = context || 'all';
    var missing = [];
    var dryRun = !!(config && config.dryRun);

    if ((context === 'all' || context === 'discovery') && !dryRun) {
      if (!config.sourceFolderId) missing.push('SOURCE_FOLDER_ID');
    }

    if ((context === 'all' || context === 'archive') && !dryRun) {
      if (!config.archiveFolderId) missing.push('ARCHIVE_FOLDER_ID');
    }

    if ((context === 'all' || context === 'api') && !dryRun) {
      if (!config.vssBaseUrl) missing.push('VSS_BASE_URL');
      if (!config.publicApiBasePath) missing.push('VSS_PUBLIC_API_BASE_PATH');
      if (!config.clientId) missing.push('VSS_CLIENT_ID');
      if (!config.hmacSecret) missing.push('VSS_HMAC_SECRET');
    }

    if (context === 'notification' && !dryRun && config.notificationEmails.length === 0) {
      missing.push('NOTIFICATION_EMAILS');
    }

    return missing;
  }

  function assertValidFor(config, context) {
    var missing = validateFor(config, context);
    if (missing.length) {
      throw new Error('Missing required K2 configuration for ' + context + ': ' + missing.join(', '));
    }
  }

  function redacted(config) {
    var copy = JSON.parse(JSON.stringify(config));
    if (copy.hmacSecret) copy.hmacSecret = '[redacted]';
    return copy;
  }

  function parseBool(value) {
    return String(value || '').toLowerCase() === 'true';
  }

  function splitCsv(value) {
    return String(value || '').split(',').map(function (item) {
      return item.trim();
    }).filter(Boolean);
  }

  function trimTrailingSlash(value) {
    return String(value || '').replace(/\/+$/, '');
  }

  function normalizeApiBasePath(value, baseUrl) {
    var explicit = String(value || '').trim();
    if (explicit) return normalizePath(explicit);

    var match = String(baseUrl || '').match(/^[a-z][a-z0-9+.-]*:\/\/[^\/?#]+(\/[^?#]*)?/i);
    var basePath = match && match[1] ? match[1] : '';
    return normalizePath(basePath + '/workspace/api/k2_automation');
  }

  function normalizePath(value) {
    var path = ('/' + String(value || '').split('?')[0].split('#')[0]).replace(/\/+/g, '/').replace(/\/$/, '');
    if (!path) path = '/';
    path.split('/').forEach(function (segment) {
      if (segment === '.' || segment === '..') throw new Error('Invalid API base path');
    });
    return path;
  }

  return {
    REQUIRED: REQUIRED,
    DEFAULTS: DEFAULTS,
    getConfig: getConfig,
    validateRequired: validateRequired,
    validateFor: validateFor,
    assertValidFor: assertValidFor,
    redacted: redacted
  };
})();
