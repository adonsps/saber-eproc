var K2NotificationService = (function () {
  function send(config, summary) {
    var formatted = format(summary);
    if (config.dryRun || !config.notificationEmails.length) {
      K2LoggingService.info('NOTIFICATION_DRY_RUN', { subject: formatted.subject, body: formatted.body });
      return { sent: false, dry_run: true };
    }
    try {
      K2Config.assertValidFor(config, 'notification');
      MailApp.sendEmail(config.notificationEmails.join(','), formatted.subject, formatted.body);
      return { sent: true };
    } catch (e) {
      K2LoggingService.warn('NOTIFICATION_FAILED', { message: String(e.message || e) });
      return { sent: false, error: 'NOTIFICATION_FAILED' };
    }
  }

  function format(summary) {
    var safe = K2LoggingService.redact(summary || {});
    var subject = '[K2 Automation] ' + (safe.code || 'SUMMARY') + ' ' + (safe.slot || '');
    var lines = [
      'Code: ' + (safe.code || ''),
      'Slot: ' + (safe.slot || ''),
      'Batch UID: ' + (safe.batch_uid || ''),
      'State: ' + (safe.state || '') + ' / ' + (safe.archive_state || ''),
      'Message: ' + (safe.message || '')
    ];
    if (safe.files) {
      lines.push('Files:');
      safe.files.forEach(function (file) {
        lines.push('- ' + file.dataset_type + ': ' + file.filename + ' (' + file.drive_file_id + ')');
      });
    }
    if (safe.counters) {
      lines.push('Counters: ' + JSON.stringify(safe.counters));
    }
    return { subject: subject.trim(), body: lines.join('\n') };
  }

  return {
    send: send,
    format: format
  };
})();
