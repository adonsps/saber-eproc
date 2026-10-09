<?php
// includes/contract_maintenance_reminder.php
//
// WhatsApp reminders for Contract Maintenance. Three independent senders
// share everything below except how they pick their contracts:
//
//   MANUAL   Category Manager ctrl/shift-selects specific rows on the
//            dashboard's "Expired or Expiring" table and presses "Shoot
//            Reminder". Always allowed to repeat -- see the note on
//            contract_maintenance_reminder_log in config/database.php.
//
//   AUTO     workspace/cron/contract_maintenance_reminder_cron.php sends once
//            per contract PER STAGE, at 3, 2 and 1 calendar month(s) before its
//            output_end_date (expiry is judged by the output/PO document's own
//            end date, not contract_end_date -- see contract_maintenance_
//            status() in includes/contract_maintenance_helper.php). A contract
//            gets up to three separate reminders over its life as it crosses
//            each window, never more than one per stage -- see
//            contract_maintenance_reminder_stage_for() below. Covers every
//            output_type EXCEPT 'PO' -- see AUTO-PO below.
//            Dedup is enforced by this file (NOT EXISTS against the log per
//            stage), unlike AON's date-keyed log rows, because none of these
//            three stages ever repeats -- there's only one "first time" each
//            to guard.
//
//   AUTO-PO  Same cron, same 3/2/1-month cadence, same WA groups, but scoped
//            to output_type = 'PO' rows only and logged under PO_-prefixed
//            stage values (contract_maintenance_po_reminder_dispatch_auto()).
//            2026-09-07 revision: a PO is just the funding document behind a
//            contract's work, not the work itself -- the work keeps going
//            even after a specific PO expires, someone just needs to raise a
//            new one -- so POs get their own sweep with PO-specific message
//            wording, independent of the AUTO track's dedup for the same
//            contract_id.
//
// All three go through contract_maintenance_reminder_send(), which mirrors
// includes/aon_reminder.php's aon_reminder_dispatch(): dry-run when no group
// is configured, never throws, and only records what actually sent.

require_once __DIR__ . '/contract_maintenance_helper.php';

const CONTRACT_MAINTENANCE_REMINDER_STAGE_MANUAL = 'MANUAL';
const CONTRACT_MAINTENANCE_REMINDER_STAGE_T3M     = 'T3M';
const CONTRACT_MAINTENANCE_REMINDER_STAGE_T2M     = 'T2M';
const CONTRACT_MAINTENANCE_REMINDER_STAGE_T1M     = 'T1M';

// PO track (2026-09-07 revision): a PO is just the funding document behind a
// contract's work, not the work itself -- the work keeps going even after a
// specific PO expires, someone just needs to raise a new one. So output_type
// = 'PO' rows get their own reminder sweep, same 3/2/1-month cadence as the
// main track (contract_maintenance_reminder_stages() below, reused as-is),
// but logged/deduped under PO_-prefixed stage values so the two sweeps never
// collide in contract_maintenance_reminder_log -- see
// contract_maintenance_reminder_due_rows_by() and
// contract_maintenance_po_reminder_dispatch_auto().
const CONTRACT_MAINTENANCE_REMINDER_PO_STAGE_PREFIX = 'PO_';

const CONTRACT_MAINTENANCE_REMINDER_TZ = 'Asia/Jakarta';

/**
 * The three auto stages, in priority order (nearest to expiry first -- this
 * order matters, see contract_maintenance_reminder_stage_for()). 'months' is
 * the size of the window; 'label' is the human-readable stage name used in
 * the WA message and the cron's console output.
 */
function contract_maintenance_reminder_stages() {
    return [
        CONTRACT_MAINTENANCE_REMINDER_STAGE_T1M => ['months' => 1, 'label' => '1 bulan lagi'],
        CONTRACT_MAINTENANCE_REMINDER_STAGE_T2M => ['months' => 2, 'label' => '2 bulan lagi'],
        CONTRACT_MAINTENANCE_REMINDER_STAGE_T3M => ['months' => 3, 'label' => '3 bulan lagi'],
    ];
}

function contract_maintenance_reminder_today() {
    return (new DateTime('now', new DateTimeZone(CONTRACT_MAINTENANCE_REMINDER_TZ)))->format('Y-m-d');
}

/**
 * The WhatsApp group IDs, from config/contract_maintenance_wa_groups.php.
 * Empty until someone fills that file in -- see its header comment. That
 * emptiness is what keeps every sender in dry run.
 */
function contract_maintenance_reminder_group_ids() {
    $path = __DIR__ . '/../config/contract_maintenance_wa_groups.php';
    if (!is_file($path)) return [];
    $config = require $path;
    $out = [];
    foreach (($config['groups'] ?? []) as $id) {
        $id = trim((string)$id);
        if ($id !== '') $out[] = $id;
    }
    return $out;
}

function contract_maintenance_reminder_default_link() {
    return 'https://vssportal.online/workspace/pages/contract_maintenance.php';
}

/**
 * The date $end_date's reminder window opens, $months calendar months before
 * it. Same clamped month-math as aon_reminder_window_opens() (includes/
 * aon_reminder.php): contracts are written in months, so "N months before the
 * 31st" has to land on the 28th/30th of that earlier month, not 31 days back.
 * Returns null when $end_date is missing/unparseable.
 */
function contract_maintenance_reminder_window_opens($end_date, $months) {
    $end = substr((string)$end_date, 0, 10);
    $ts  = strtotime($end);
    if ($ts === false || $end === '' || $end === '0000-00-00') return null;

    $d = new DateTime($end);
    $day = (int)$d->format('j');
    $d->modify('first day of this month')->modify('-' . (int)$months . ' month');
    $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($day, (int)$d->format('t')));
    return $d->format('Y-m-d');
}

/**
 * A contract's expiry date for reminder purposes: output_end_date, or null
 * if blank. Same basis the dashboard status badge uses
 * (contract_maintenance_status()) -- expiry is judged by the output/PO
 * document's own end date, not contract_end_date.
 */
function contract_maintenance_reminder_soonest_date(array $row) {
    return !empty($row['output_end_date']) ? $row['output_end_date'] : null;
}

/**
 * Which auto stage (if any) a contract is in TODAY, purely by date -- mirrors
 * aon_reminder_stage_for() (includes/aon_reminder.php): checked nearest-to-
 * expiry first (T1M, then T2M, then T3M) so a contract already in its final
 * month reports T1M, not T3M, even though its T3M window technically opened
 * two months ago too. Returns null once output_end_date has passed (already
 * expired -- this is a "before expiry" heads up, not a past-due chase; the
 * dashboard/manual button covers already-expired contracts) or when it has
 * no output_end_date at all.
 */
function contract_maintenance_reminder_stage_for($output_end_date, $today = null) {
    $today = $today ?: contract_maintenance_reminder_today();
    if (empty($output_end_date) || $output_end_date < $today) return null;

    foreach (contract_maintenance_reminder_stages() as $stage => $meta) {
        $opens = contract_maintenance_reminder_window_opens($output_end_date, $meta['months']);
        if ($opens !== null && $today >= $opens) return $stage;
    }
    return null;
}

/**
 * Every (contract_id, stage) pair already sent, so a contract already
 * reminded for a stage is never picked up for it again -- the once-per-stage
 * guard. Same shape as aon_reminder_log_index().
 */
function contract_maintenance_reminder_sent_stages(PDO $pdo) {
    $sent = [];
    try {
        $rows = $pdo->query(
            "SELECT contract_id, stage FROM contract_maintenance_reminder_log WHERE stage != '" . CONTRACT_MAINTENANCE_REMINDER_STAGE_MANUAL . "'"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('contract_maintenance_reminder_sent_stages: ' . $e->getMessage());
        return [];
    }
    foreach ($rows as $r) {
        $sent[(int)$r['contract_id'] . '|' . $r['stage']] = true;
    }
    return $sent;
}

/**
 * Shared engine behind both the main and PO due-rows functions below: fetch
 * non-deleted contracts matching $where_sql, work out each one's plain stage
 * (T1M/T2M/T3M) via contract_maintenance_reminder_stage_for(), and skip any
 * already sent for its LOGGED stage (log_stage = $log_stage_prefix . stage).
 * Two stage fields are kept on each due row on purpose: 'stage' stays plain
 * so contract_maintenance_reminder_format_row()'s label lookup
 * (contract_maintenance_reminder_stages()[stage]) works unchanged for both
 * tracks, while 'log_stage' is what's actually deduped/recorded -- see the PO
 * track comment above CONTRACT_MAINTENANCE_REMINDER_PO_STAGE_PREFIX.
 */
function contract_maintenance_reminder_due_rows_by(PDO $pdo, $today, $where_sql, $log_stage_prefix = '') {
    $today = $today ?: contract_maintenance_reminder_today();

    try {
        $rows = $pdo->query(
            "SELECT * FROM contract_maintenance WHERE is_deleted = 0 AND ($where_sql)"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('contract_maintenance_reminder_due_rows_by: ' . $e->getMessage());
        return [];
    }

    $sent = contract_maintenance_reminder_sent_stages($pdo);
    $due  = [];
    foreach ($rows as $r) {
        $soonest = contract_maintenance_reminder_soonest_date($r);
        $stage   = contract_maintenance_reminder_stage_for($soonest, $today);
        if ($stage === null) continue;
        $log_stage = $log_stage_prefix . $stage;
        if (isset($sent[(int)$r['id'] . '|' . $log_stage])) continue;

        $r['soonest_date'] = $soonest;
        $r['stage']        = $stage;
        $r['log_stage']    = $log_stage;
        $due[] = $r;
    }
    return $due;
}

/**
 * Main track: contracts due for an auto reminder right now, EXCLUDING PO rows
 * (output_type = 'PO' has its own track, contract_maintenance_po_reminder_
 * due_rows() below) -- not soft-deleted, currently inside one of the three
 * stage windows, and not already sent for that specific stage.
 */
function contract_maintenance_reminder_due_rows(PDO $pdo, $today = null) {
    return contract_maintenance_reminder_due_rows_by(
        $pdo, $today, "output_type IS NULL OR output_type != 'PO'"
    );
}

/**
 * PO track: same windows/dedup engine as the main track, scoped to
 * output_type = 'PO' rows only, logged/deduped under PO_-prefixed stage
 * values (see CONTRACT_MAINTENANCE_REMINDER_PO_STAGE_PREFIX) so it never
 * collides with the main track's log rows for the same contract.
 */
function contract_maintenance_po_reminder_due_rows(PDO $pdo, $today = null) {
    return contract_maintenance_reminder_due_rows_by(
        $pdo, $today, "output_type = 'PO'", CONTRACT_MAINTENANCE_REMINDER_PO_STAGE_PREFIX
    );
}

/**
 * One line per contract: category/description, supplier, BU, site, expiry.
 * Shared by both the manual and auto message builders below.
 */
function contract_maintenance_reminder_format_row(array $row) {
    $soonest = $row['soonest_date'] ?? contract_maintenance_reminder_soonest_date($row);
    $label = trim((string)($row['item_category'] ?? ''));
    if (!empty($row['description'])) $label .= ' - ' . $row['description'];

    $s = '• *' . $label . "*\n";
    if (!empty($row['supplier']))     $s .= '   Supplier: ' . $row['supplier'] . "\n";
    if (!empty($row['business_unit']))$s .= '   BU: ' . $row['business_unit'];
    if (!empty($row['site_area']))    $s .= ' - ' . $row['site_area'];
    $s .= "\n";
    if ($soonest) {
        $s .= '   Berakhir: ' . date('d M Y', strtotime($soonest));
        // Only the auto sender sets 'stage' -- a manually selected contract
        // has no single stage (it was picked by hand, not by a date window).
        if (!empty($row['stage'])) {
            $s .= ' (' . (contract_maintenance_reminder_stages()[$row['stage']]['label'] ?? $row['stage']) . ')';
        }
        $s .= "\n";
    }
    return $s;
}

/**
 * Builds the WA message for a list of contract rows. $heading carries the
 * emoji + title line so the manual and auto sends read as distinct reasons
 * ("you were reminded about these" vs "these are approaching on their own"),
 * everything else about the layout is shared. $note is the closing
 * call-to-action line -- defaults to the original contract/work wording; the
 * PO track (contract_maintenance_po_reminder_dispatch_auto()) passes its own,
 * since "raise a new PO" is different advice than "renew/negotiate this
 * contract".
 */
function contract_maintenance_reminder_build_message(array $rows, $heading, $link = null, $today = null, $note = null) {
    $today = $today ?: contract_maintenance_reminder_today();
    $link  = $link ?: contract_maintenance_reminder_default_link();
    $note  = $note ?? 'Mohon segera ditindaklanjuti perpanjangan/negosiasi kontraknya.';

    $m  = $heading . "\n";
    $m .= date('d M Y', strtotime($today)) . "\n\n";
    $m .= '*' . count($rows) . "* kontrak perlu segera diperpanjang:\n\n";
    foreach ($rows as $r) {
        $m .= contract_maintenance_reminder_format_row($r) . "\n";
    }
    $m .= $note . "\n\n";
    $m .= "*Buka Workspace:*\n";
    $m .= $link;
    return $m;
}

/**
 * Send $message to every configured group. Mirrors aon_reminder_dispatch()'s
 * send half: dry-run when no group is configured, never throws.
 *
 * @return array{dry_run:bool, groups:int, accepted:int, sent:bool, error:?string}
 */
function contract_maintenance_reminder_send(string $message) {
    $out = ['dry_run' => false, 'groups' => 0, 'accepted' => 0, 'sent' => false, 'error' => null];
    try {
        $groups = contract_maintenance_reminder_group_ids();
        $out['groups'] = count($groups);
        $dry = empty($groups);
        $out['dry_run'] = $dry;
        if ($dry) return $out;

        require_once __DIR__ . '/fonnte.php';
        $accepted = 0;
        foreach ($groups as $groupId) {
            $res = sendFonnteGroupChecked($groupId, $message);
            if (!empty($res['ok'])) $accepted++;
        }
        $out['accepted'] = $accepted;
        if ($accepted === 0) {
            $out['error'] = 'No WhatsApp group accepted the message.';
            return $out;
        }
        $out['sent'] = true;
        return $out;
    } catch (Throwable $e) {
        error_log('contract_maintenance_reminder_send: ' . $e->getMessage());
        $out['error'] = 'Reminder send failed.';
        return $out;
    }
}

/** One log row per contract for the given stage. INSERT, not INSERT IGNORE:
 * a manual send is deliberately repeatable (see config/database.php), so two
 * sends about the same contract are two legitimate rows, not a duplicate.
 */
function contract_maintenance_reminder_log(PDO $pdo, $contract_id, $stage, $sent_by = null) {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO contract_maintenance_reminder_log (contract_id, stage, sent_by) VALUES (?, ?, ?)"
        );
        $stmt->execute([(int)$contract_id, $stage, $sent_by]);
        return true;
    } catch (PDOException $e) {
        error_log('contract_maintenance_reminder_log: ' . $e->getMessage());
        return false;
    }
}

/**
 * MANUAL sender: given a list of contract_maintenance ids (already picked by
 * the Category Manager on the dashboard), fetch them, build one digest
 * message, send, and log each on success. Never throws.
 *
 * @return array{status:string, message:string, count:int, dry_run:bool, error:?string}
 */
function contract_maintenance_reminder_send_manual(PDO $pdo, array $ids, $actor = null) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) {
        return ['status' => 'error', 'message' => 'No contracts selected.', 'count' => 0, 'dry_run' => false, 'error' => 'no_ids'];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM contract_maintenance WHERE is_deleted = 0 AND id IN ($placeholders)");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('contract_maintenance_reminder_send_manual: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'Database error.', 'count' => 0, 'dry_run' => false, 'error' => $e->getMessage()];
    }
    if (empty($rows)) {
        return ['status' => 'error', 'message' => 'Selected contracts were not found.', 'count' => 0, 'dry_run' => false, 'error' => 'not_found'];
    }

    $message = contract_maintenance_reminder_build_message(
        $rows, '🔔 *REMINDER KONTRAK MAINTENANCE*'
    );
    $result = contract_maintenance_reminder_send($message);

    if ($result['dry_run']) {
        return [
            'status' => 'success', 'dry_run' => true, 'count' => count($rows), 'error' => null,
            'message' => 'No WhatsApp group configured yet, so nothing was actually sent (dry run). Add one to config/contract_maintenance_wa_groups.php to enable sending.',
        ];
    }
    if (!$result['sent']) {
        return ['status' => 'error', 'dry_run' => false, 'count' => 0, 'error' => $result['error'],
            'message' => $result['error'] ?: 'Could not send the reminder.'];
    }

    foreach ($rows as $r) {
        contract_maintenance_reminder_log($pdo, $r['id'], CONTRACT_MAINTENANCE_REMINDER_STAGE_MANUAL, $actor);
    }

    return [
        'status' => 'success', 'dry_run' => false, 'count' => count($rows), 'error' => null,
        'message' => 'Reminder sent for ' . count($rows) . ' contract' . (count($rows) === 1 ? '' : 's') . '.',
    ];
}

/**
 * AUTO sender: the cron's single entry point. Computes what is due across all
 * three stages, sends one digest (each contract's line names its own stage --
 * see contract_maintenance_reminder_format_row()), and records each contract
 * under ITS OWN stage on success, so it is never sent again for that specific
 * stage but can still be picked up later for the next one. Never throws --
 * see contract_maintenance_reminder_send().
 *
 * @return array{due:int, dry_run:bool, sent:bool, recorded:int, error:?string}
 */
function contract_maintenance_reminder_dispatch_auto(PDO $pdo, $today = null) {
    $out = ['due' => 0, 'dry_run' => false, 'sent' => false, 'recorded' => 0, 'error' => null];
    try {
        $due = contract_maintenance_reminder_due_rows($pdo, $today);
        $out['due'] = count($due);
        if (empty($due)) return $out;

        $message = contract_maintenance_reminder_build_message(
            $due, '⏰ *AUTO-REMINDER: KONTRAK AKAN BERAKHIR*', null, $today
        );
        $result = contract_maintenance_reminder_send($message);
        $out['dry_run'] = $result['dry_run'];

        if ($result['dry_run'] || !$result['sent']) {
            $out['error'] = $result['error'];
            return $out;
        }

        $out['sent'] = true;
        foreach ($due as $r) {
            if (contract_maintenance_reminder_log($pdo, $r['id'], $r['log_stage'], null)) {
                $out['recorded']++;
            }
        }
        error_log(sprintf(
            'contract_maintenance_reminder_dispatch_auto: due=%d recorded=%d',
            $out['due'], $out['recorded']
        ));
        return $out;
    } catch (Throwable $e) {
        error_log('contract_maintenance_reminder_dispatch_auto: ' . $e->getMessage());
        $out['error'] = 'Auto reminder dispatch failed.';
        return $out;
    }
}

/**
 * PO track's auto sender -- exact mirror of contract_maintenance_reminder_
 * dispatch_auto() above, but sourced from contract_maintenance_po_reminder_
 * due_rows() (output_type = 'PO' only) and framed around "the work continues,
 * please raise a new PO" rather than "renew/negotiate this contract". Logs
 * under each row's PO_-prefixed log_stage, so it never collides with the main
 * track's log rows for the same contract. Never throws -- see
 * contract_maintenance_reminder_send().
 *
 * @return array{due:int, dry_run:bool, sent:bool, recorded:int, error:?string}
 */
function contract_maintenance_po_reminder_dispatch_auto(PDO $pdo, $today = null) {
    $out = ['due' => 0, 'dry_run' => false, 'sent' => false, 'recorded' => 0, 'error' => null];
    try {
        $due = contract_maintenance_po_reminder_due_rows($pdo, $today);
        $out['due'] = count($due);
        if (empty($due)) return $out;

        $message = contract_maintenance_reminder_build_message(
            $due,
            '⏰ *AUTO-REMINDER: PO AKAN BERAKHIR*',
            null,
            $today,
            'PO ini akan segera berakhir. Pekerjaan/kontrak tetap berjalan seperti biasa -- mohon segera buat PO baru agar pekerjaan tetap tercover secara administratif.'
        );
        $result = contract_maintenance_reminder_send($message);
        $out['dry_run'] = $result['dry_run'];

        if ($result['dry_run'] || !$result['sent']) {
            $out['error'] = $result['error'];
            return $out;
        }

        $out['sent'] = true;
        foreach ($due as $r) {
            if (contract_maintenance_reminder_log($pdo, $r['id'], $r['log_stage'], null)) {
                $out['recorded']++;
            }
        }
        error_log(sprintf(
            'contract_maintenance_po_reminder_dispatch_auto: due=%d recorded=%d',
            $out['due'], $out['recorded']
        ));
        return $out;
    } catch (Throwable $e) {
        error_log('contract_maintenance_po_reminder_dispatch_auto: ' . $e->getMessage());
        $out['error'] = 'PO auto reminder dispatch failed.';
        return $out;
    }
}
