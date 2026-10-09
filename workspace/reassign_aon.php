<?php
// workspace/reassign_aon.php
//
// One-off backfill: re-route in-flight AON PR lines to the Admin team, using the
// same rule the PR import now applies (pr_admin_route_pic()) and the same rule
// Intercompany has always used.
//
// Companion to reassign_intercompany.php. Detection runs in PHP rather than SQL:
// "AON" means the SKU is on the Master AON list OR the item name carries a
// standalone AON token, and the token test is aon_name_flags_aon() -- the exact
// function the dashboard and the importer use. Expressing that word boundary in
// SQL would need REGEXP, whose syntax differs between the local MySQL 8 and the
// MariaDB prod DB.
//
// SCOPE -- mirrors the guards in includes/excel_parser.php exactly:
//   * A line whose PR date precedes its SKU's contract valid_from is NOT AON --
//     it could not draw on a contract that wasn't live yet, so it stays with the
//     ordinary dispatcher (aon_contract_started()).
//   * Only lines the dashboard shows as Outstanding or On Progress. Anything at
//     PO Created / Escalated / Sendback / Cancelled is finished work and is left
//     untouched.
//   * Never overwrites a manually assigned PIC (assigned_by_ai = 0 with a name
//     on it -- the same signal the dashboard's "Manual Assignments" filter uses).
//
// Every run writes a rollback script to
// db_backups/local_runtime_exports/ before touching anything, so an --apply can
// always be undone.
//
// Usage (command line only -- see the guard below):
//   php reassign_aon.php            # dry run, prints what would change
//   php reassign_aon.php --apply    # writes the changes

// COMMAND LINE ONLY. This file lives under the web root, so without this guard
// any visitor -- signed in or not -- could rewrite assigned_pic across every
// in-flight AON line just by requesting it with ?apply=1. A web request gets a
// bare 404 and nothing else: not even the dry-run listing, which would otherwise
// disclose ticket numbers and PIC names. Checked before the DB is touched.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/aon_helper.php';
require_once __DIR__ . '/includes/pr_admin_routing.php';
require_once __DIR__ . '/includes/pr_status_helper.php';
require_once __DIR__ . '/includes/pr_audit_stamp.php';

$apply = in_array('--apply', $argv ?? [], true);
$nl    = "\n";

// Lines the backfill is allowed to touch, by dashboard display status.
$IN_FLIGHT = ['Outstanding', 'On Progress'];

try {
    // pgroup_code => label/team_name, keyed uppercase (same shape excel_parser.php builds).
    $allowed_pgroups = [];
    foreach ($pdo->query("SELECT pgroup_code, label, team_name FROM allowed_pgroups WHERE status = 'ACTIVE'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $allowed_pgroups[strtoupper(trim($row['pgroup_code']))] = [
            'label'     => $row['label'],
            'team_name' => $row['team_name'],
        ];
    }
    
    $admin_routing_rules = [];
    try {
        $stmt = $pdo->query("SELECT rule_type, rule_value, pic_name FROM pr_admin_routing_rules");
        $admin_routing_rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // sku_code => valid_from (nullable). The date decides whether a candidate
    // line's PR was raised after its contract went live.
    $aon_sku_set = [];
    foreach ($pdo->query("SELECT sku_code, valid_from FROM aon_skus WHERE is_deleted = 0 AND alokasi IS NOT NULL AND alokasi != ''")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $aon_sku_set[strtoupper(trim((string)$row['sku_code']))] = $row['valid_from'];
    }

    // Broad candidate pull: anything on the AON SKU list, plus anything with
    // "AON" anywhere in the description. The LIKE is only a cheap prefilter --
    // aon_name_flags_aon() below decides what actually counts, and
    // compute_pr_display_status() decides what is still in flight.
    $candidates = $pdo->query("
        SELECT ticket_no, ticket_line_item, p_org, p_group, sku, item_description, pr_date,
               assigned_pic, assigned_by_ai,
               status_ticket, canvassing_status, canvassing_no, po_number
        FROM prs
        WHERE (
                EXISTS (
                    SELECT 1 FROM aon_skus a
                    WHERE a.sku_code = TRIM(prs.sku)
                      AND a.is_deleted = 0
                      AND a.alokasi IS NOT NULL AND a.alokasi != ''
                )
                OR UPPER(IFNULL(prs.item_description, '')) LIKE '%AON%'
              )
    ")->fetchAll(PDO::FETCH_ASSOC);

    $to_update       = [];
    $not_really_aon  = 0;
    $before_contract = 0;
    $not_in_flight   = 0;
    $manually_set    = 0;
    $skipped_no_rule = 0;
    $already_correct = 0;

    foreach ($candidates as $row) {
        $sku_key  = strtoupper(trim((string)$row['sku']));
        $on_list  = $sku_key !== '' && array_key_exists($sku_key, $aon_sku_set);
        $is_aon   = $on_list || aon_name_flags_aon($row['item_description'] ?? '');
        if (!$is_aon) { $not_really_aon++; continue; }

        // PR raised before the contract started -> ordinary ticket, leave it to
        // the AI dispatcher. Counted separately so a dry run shows how many lines
        // this rule holds back.
        if ($on_list && !aon_contract_started($aon_sku_set[$sku_key], $row['pr_date'] ?? null)) {
            $before_contract++;
            continue;
        }

        $display_status = compute_pr_display_status(
            $row['status_ticket'], $row['canvassing_status'], $row['po_number'], $row['canvassing_no']
        );
        if (!in_array($display_status, $IN_FLIGHT, true)) { $not_in_flight++; continue; }

        $current_pic = trim((string)$row['assigned_pic']);
        if ($current_pic !== '' && (int)$row['assigned_by_ai'] === 0) { $manually_set++; continue; }

        $routed = pr_admin_route_pic($row['p_org'], $row['p_group'], $allowed_pgroups, $admin_routing_rules);
        if ($routed === null) { $skipped_no_rule++; continue; }
        if ($current_pic === $routed) { $already_correct++; continue; }

        $to_update[] = [
            'ticket'  => $row['ticket_no'],
            'line'    => $row['ticket_line_item'],
            'old_pic' => $row['assigned_pic'],          // may be null
            'old_ai'  => $row['assigned_by_ai'],        // may be null
            'new_pic' => $routed,
            'status'  => $display_status,
        ];
    }

    echo "AON candidates scanned      : " . count($candidates) . $nl;
    echo "  not AON (LIKE prefilter)  : " . $not_really_aon . $nl;
    echo "  PR before contract start  : " . $before_contract . $nl;
    echo "  not Outstanding/OnProgress: " . $not_in_flight . $nl;
    echo "  manually assigned (kept)  : " . $manually_set . $nl;
    echo "  no routing rule match     : " . $skipped_no_rule . $nl;
    echo "  already routed            : " . $already_correct . $nl;
    echo "  TO RE-ROUTE               : " . count($to_update) . $nl . $nl;

    $by_target = [];
    foreach ($to_update as $u) { $by_target[$u['new_pic']] = ($by_target[$u['new_pic']] ?? 0) + 1; }
    arsort($by_target);
    foreach ($by_target as $name => $n) { echo "  -> {$name}: {$n}" . $nl; }
    echo $nl;

    if (empty($to_update)) {
        echo "Nothing to do." . $nl;
        return;
    }

    // --- Rollback script (written on dry runs too, so it can be reviewed) -----
    $export_dir = __DIR__ . '/../db_backups/local_runtime_exports';
    if (!is_dir($export_dir)) { @mkdir($export_dir, 0777, true); }
    $rollback_path = $export_dir . '/reassign_aon_rollback_' . date('Ymd_His') . '.sql';
    $sql = "-- Rollback for reassign_aon.php, generated " . date('c') . "\n"
         . "-- Restores assigned_pic / assigned_by_ai for the " . count($to_update) . " line(s) it re-routes.\n"
         . "-- It does NOT restore prs.last_updated_by / last_updated_date: those keep\n"
         . "-- pointing at this script, which is the truthful record of who touched the row.\n"
         . "START TRANSACTION;\n";
    foreach ($to_update as $u) {
        $sql .= sprintf(
            "UPDATE prs SET assigned_pic = %s, assigned_by_ai = %s WHERE ticket_no = %s AND ticket_line_item = %s;\n",
            $u['old_pic'] === null ? 'NULL' : $pdo->quote($u['old_pic']),
            $u['old_ai']  === null ? 'NULL' : (int)$u['old_ai'],
            $pdo->quote($u['ticket']),
            $pdo->quote($u['line'])
        );
    }
    $sql .= "COMMIT;\n";
    file_put_contents($rollback_path, $sql);
    echo "Rollback script written to: " . $rollback_path . $nl;

    if (!$apply) {
        echo $nl . "DRY RUN -- nothing was written. Re-run with --apply to commit." . $nl;
        return;
    }

    $upd = $pdo->prepare("UPDATE prs SET " . pr_stamp_pic_set_sql()
        . ", assigned_pic = ?, assigned_by_ai = 0 WHERE ticket_no = ? AND ticket_line_item = ?");
    $stamp_by = pr_stamp_by();
    $stamp_at = pr_stamp_now();
    $pdo->beginTransaction();
    try {
        $written = 0;
        foreach ($to_update as $u) {
            $upd->execute(array_merge(
                pr_stamp_pic_params($u['new_pic'], $stamp_by, $stamp_at),
                [$u['new_pic'], $u['ticket'], $u['line']]
            ));
            $written += $upd->rowCount();
        }
        $pdo->commit();
        echo $nl . "Applied. {$written} PR line(s) re-routed to the Admin team." . $nl;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
} catch (PDOException $e) {
    echo "Error re-routing AON PRs: " . $e->getMessage() . $nl;
}
