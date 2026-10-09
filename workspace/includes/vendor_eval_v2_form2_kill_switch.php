<?php
// workspace/includes/vendor_eval_v2_form2_kill_switch.php
//
// Form 2 cron live-send kill switch. Independent of ve2_form2_send_reminders()'s
// own preview flag — see db_backups/vendor_eval_v2_form2_send_kill_switch_01_schema.sql
// for why this exists as a second, separate gate.
//
// FAIL-CLOSED: if the settings table is missing, unreadable, or the singleton
// row is missing, ve2_form2_live_send_enabled() returns false. A DB error or
// a schema that was never applied must never be interpreted as "go ahead".
//
// PURE LIBRARY: no output, no session, no side effects on require.

if (!defined('VE2_FORM2_SEND_SETTINGS_TABLE')) { define('VE2_FORM2_SEND_SETTINGS_TABLE', 'vendor_eval_v2_form2_send_settings'); }
if (!defined('VE2_FORM2_SEND_SETTINGS_LOG_TABLE')) { define('VE2_FORM2_SEND_SETTINGS_LOG_TABLE', 'vendor_eval_v2_form2_send_settings_log'); }

/** Whether the Form 2 cron is allowed to send for real. Defaults to false on any doubt. */
function ve2_form2_live_send_enabled(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query('SELECT live_send_enabled FROM ' . VE2_FORM2_SEND_SETTINGS_TABLE . ' WHERE id = 1');
        $val  = $stmt !== false ? $stmt->fetchColumn() : false;
        return $val !== false && (int)$val === 1;
    } catch (Throwable $e) {
        // Table not applied yet, or any other read failure — fail closed.
        return false;
    }
}

/**
 * Flip the switch. Always writes an audit row alongside the settings update.
 * Callers are responsible for their own DB identity gate / confirmation UX —
 * this function just performs the write once called.
 */
function ve2_form2_set_live_send_enabled(PDO $pdo, bool $enabled, string $changed_by, string $note = ''): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('
            INSERT INTO ' . VE2_FORM2_SEND_SETTINGS_TABLE . ' (id, live_send_enabled, updated_by, updated_at)
            VALUES (1, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE live_send_enabled = VALUES(live_send_enabled),
                                     updated_by = VALUES(updated_by),
                                     updated_at = VALUES(updated_at)
        ')->execute([$enabled ? 1 : 0, $changed_by]);

        $pdo->prepare('
            INSERT INTO ' . VE2_FORM2_SEND_SETTINGS_LOG_TABLE . ' (action, note, changed_by)
            VALUES (?, ?, ?)
        ')->execute([$enabled ? 'ON' : 'OFF', $note, $changed_by]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
