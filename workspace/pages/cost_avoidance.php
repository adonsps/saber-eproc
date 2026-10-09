<?php
// pages/cost_avoidance.php
require_once '../includes/auth.php';
require_once '../config/database.php';
// Shared SQL builders + to_idr()/ca_date_range()/ca_manual_aggregate(), used by
// this page and by ajax_cost_avoidance_table.php / _charts.php / _baselines.php so
// the Cost Avoidance figures can never drift between the page and its endpoints.
// Project-team detection (formerly load_project_team_map() from
// includes/project_team_helper.php) is part of ca_team_expr() now.
require_once '../includes/cost_avoidance_helper.php';

// Team-scoped access gate (replaces the old require_manager()-only gate).
// Six-branch resolution per ca_resolve_session_team_scope(); DB-backed, so it
// must come after database.php + cost_avoidance_helper.php, not before like
// the old require_manager() call. All 4 tabs stay visible for every admitted
// role — $ca_team_scope (null = unrestricted, else allowed team codes) is
// threaded through every query/write below instead of any tab-level hiding.
$ca_team_scope = require_cost_avoidance_access($pdo);
// Read-only role: the gate above resolves it to unrestricted READ scope, so
// the write controls below are hidden. ws_require_write() does the enforcing.
$is_viewer = ws_is_viewer();
$ca_ctx = ca_context($pdo);

// Auto-migrate schema: Add pillar column to prs table if it doesn't exist
$has_pillar_column = false;
try {
    $pdo->query("SELECT pillar FROM prs LIMIT 1");
    $has_pillar_column = true;
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE prs ADD COLUMN pillar VARCHAR(100) DEFAULT NULL");
        $has_pillar_column = true;
    } catch (PDOException $ex) {
        $has_pillar_column = false;
    }
}

// Auto-migrate schema: Add quotation_link column to prs table if it doesn't exist
try {
    $pdo->query("SELECT quotation_link FROM prs LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE prs ADD COLUMN quotation_link VARCHAR(500) DEFAULT NULL");
    } catch (PDOException $ex) {}
}

// Auto-migrate schema: Add ca_taxonomy_level_2 column to prs table if it
// doesn't exist — the Project "type of work" classification (Input
// Baselines tab), independent of the item-level taxonomy used elsewhere.
try {
    $pdo->query("SELECT ca_taxonomy_level_2 FROM prs LIMIT 1");
} catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE prs ADD COLUMN ca_taxonomy_level_2 VARCHAR(255) DEFAULT NULL");
    } catch (PDOException $ex) {}
}

// The pgroup/pic team join and the team resolution it feeds now live in
// ca_from_where() / ca_team_expr() (includes/cost_avoidance_helper.php), which the
// AJAX endpoints share with this page.

// Still needed here: the Settings tab renders every currency (active + inactive)
// in its rate/activation table, and the Input Baseline / Manual CA dropdowns
// and money-symbol lookups below use only the active subset from $ca_ctx.
$all_currencies = $pdo->query("SELECT code, name, symbol, rate_to_idr, is_active FROM currencies ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$ca_currency_symbols = ca_currency_symbols($ca_ctx['currencies']);

require_once '../config/gemini_api.php';

// classify_manual_ca_category() and the Manual Cost Avoidance create/update/delete
// rules (validation, server-side total, exchange-rate snapshot, confirm-on-edit,
// edit history) live in includes/manual_ca_helper.php, shared with the
// dashboards' Manual Input modal (pages/ajax_manual_ca_save.php).
require_once '../includes/manual_ca_helper.php';
require_once '../includes/ca_ui_shared.php';

// A write action's item/PO line falls outside the session's resolved team
// scope (ca_assert_item_team_allowed() threw). Same silent-redirect-with-
// flash shape the CSV import path below already uses; no data is written.
function ca_reject_out_of_scope(): void {
    $_SESSION['import_msg'] = 'You are not allowed to modify this item — it is outside your assigned team scope.';
    $_SESSION['import_type'] = 'error';
    header("Location: cost_avoidance.php");
    exit;
}

// A write action targets an AON (contract-priced) item — ca_assert_item_not_aon()
// threw. AON items are excluded from every Cost Avoidance read, so the UI cannot
// offer one; reaching here means a stale tab or a crafted request. Same
// silent-redirect-with-flash shape as ca_reject_out_of_scope() above — no new UX
// mechanism, and no data is written.
function ca_reject_aon(): void {
    $_SESSION['import_msg'] = 'This is an AON (contract) item — its price is fixed by contract, so it cannot be given a Cost Avoidance baseline.';
    $_SESSION['import_type'] = 'error';
    header("Location: cost_avoidance.php");
    exit;
}

// A Manual Cost Avoidance write submitted a currency code that isn't in the
// active `currencies` list (see parse_manual_ca_fields()) — a stale tab (the
// code was deactivated after page load) or a crafted request. Same
// silent-redirect-with-flash shape as ca_reject_out_of_scope() above.
function ca_reject_invalid_currency(): void {
    $_SESSION['import_msg'] = 'Invalid or inactive currency — refresh the page and try again.';
    $_SESSION['import_type'] = 'error';
    header("Location: cost_avoidance.php");
    exit;
}

// --- Input Baselines write actions, as reusable functions --------------
//
// Each ca_apply_*() below is the exact body the single-action POST branches
// used before the Input Baselines staged-changes/batch-save model existed —
// only pulled out of the branch and parameterized on a $post array instead
// of reading $_POST directly, so the new batch_save_baselines action
// (further down) can call the SAME function per staged item instead of
// reimplementing any of these writes or their checks. The single-action
// branches below call these too, so there is exactly one implementation of
// each write, used by both the legacy per-action path and the batch path.
//
// Return shape: ['ok' => bool, 'scope_error' => bool (optional), 'rows' =>
// int (optional), 'error' => string|null]. 'scope_error' distinguishes a
// ca_assert_item_team_allowed() rejection (a trust-boundary violation —
// the single-action branches translate this to ca_reject_out_of_scope(),
// exactly as before) from any other failure (the single-action branches
// ignore these, exactly as before — e.g. save_header today silently
// no-ops when 0 rows match; that behavior is unchanged). The batch handler
// is the only caller that acts on 'rows'/'error' for a non-scope failure,
// since only it has a "report what didn't apply" concept.

function ca_apply_save_header(PDO $pdo, array $ctx, ?array $team_scope, array $post): array {
    $item   = (string)($post['item'] ?? '');
    $vendor = (string)($post['vendor'] ?? '');
    $uom    = (($post['uom'] ?? '') !== '') ? $post['uom'] : null;
    $team   = (string)($post['team'] ?? '');

    try {
        ca_assert_item_team_allowed($pdo, $ctx, $team_scope, $item, $vendor, $uom);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'scope_error' => true, 'error' => 'Outside your assigned team scope.'];
    }

    // Layered on top of the team check above — both must pass. See
    // ca_assert_item_not_aon().
    try {
        ca_assert_item_not_aon($pdo, $item, $vendor, $uom);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'aon_error' => true, 'error' => 'AON (contract) item — cannot be baselined.'];
    }

    // Currency + the exchange rate this baseline is entered at (blank = today's
    // rate), validated by the same resolver the dashboard modal / bulk import /
    // Manual Input use, and stored beside the price.
    $fx = ca_resolve_fx($post['currency'] ?? '', $post['baseline_fx_rate'] ?? null, $ctx['currencies']);
    if (!$fx['ok']) {
        return ['ok' => false, 'error' => $fx['message']];
    }
    $currency = $fx['currency'];
    $lk = ca_clean_link($post['quotation_link'] ?? '');
    if (!$lk['ok']) {
        return ['ok' => false, 'error' => $lk['message']];
    }

    // 4-column group key (item + vendor + uom + team), tightened from the
    // old 2-column (item + vendor) match per Addendum #1 §A3 — strictly
    // narrower, so it cannot touch rows the old match wouldn't have.
    $clean_baseline = str_replace(',', '', (string)($post['baseline'] ?? ''));
    $joins = ca_team_joins($ctx);
    $stmt = $pdo->prepare("
        UPDATE prs p {$joins}
        SET p.baseline_price = ?, p.baseline_currency = ?, p.baseline_fx_rate = ?, p.quotation_link = ?
        WHERE p.item_description = ? AND p.vendor_name = ? AND p.uom <=> ?
        AND " . ca_team_expr($ctx) . " = ?
        AND (p.baseline_price IS NULL OR p.baseline_price = 0) AND p.po_number IS NOT NULL
        AND " . ca_not_intercompany_sql('p') . "
    ");
    $stmt->execute([$clean_baseline, $currency, $fx['rate'], $lk['value'] !== '' ? $lk['value'] : null, $item, $vendor, $uom, $team]);
    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'error' => 'no matching PO lines (already baselined or out of date)'];
    }
    return ['ok' => true, 'rows' => $stmt->rowCount(), 'error' => null];
}

function ca_apply_save_detail(PDO $pdo, array $ctx, ?array $team_scope, array $post): array {
    $ticket_no   = (string)($post['ticket_no'] ?? '');
    $ticket_line = (string)($post['ticket_line'] ?? '');

    try {
        ca_assert_item_team_allowed($pdo, $ctx, $team_scope, null, null, null, $ticket_no, $ticket_line);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'scope_error' => true, 'error' => 'Outside your assigned team scope.'];
    }

    // Layered on top of the team check above — both must pass.
    try {
        ca_assert_item_not_aon($pdo, null, null, null, $ticket_no, $ticket_line);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'aon_error' => true, 'error' => 'AON (contract) item — cannot be baselined.'];
    }

    $po_currency = strtoupper((string)($post['po_currency'] ?? ''));
    if (!ca_is_valid_currency($po_currency, $ctx['currencies'])) {
        return ['ok' => false, 'error' => 'invalid or inactive currency'];
    }
    // Base currency + the rate this baseline is entered at (blank = today's).
    $fx = ca_resolve_fx($post['currency'] ?? '', $post['baseline_fx_rate'] ?? null, $ctx['currencies']);
    if (!$fx['ok']) {
        return ['ok' => false, 'error' => $fx['message']];
    }
    $currency = $fx['currency'];
    $price = ca_parse_amount(str_replace(',', '', (string)($post['baseline'] ?? '')));
    if (!$price['ok'] || $price['value'] === null || round($price['value'], 2) <= 0 || $price['value'] >= 1.0E+16) {
        return ['ok' => false, 'error' => 'Initial Quotation Price must be a number greater than 0.'];
    }
    $newPrice = round($price['value'], 2);
    $lk = ca_clean_link($post['quotation_link'] ?? '');
    if (!$lk['ok']) {
        return ['ok' => false, 'error' => $lk['message']];
    }

    // What is there now. An existing price can be changed, but only after the
    // user confirmed (confirm_edit) -- the same yes/no the dashboard modal and
    // Manual Input ask. Every change is logged (who/when/old -> new) and the
    // dashboard's Initial Quotation Price row for the line, if any, is kept in
    // step so both places show one price.
    $old = $pdo->prepare("SELECT baseline_price, baseline_currency, baseline_fx_rate FROM prs WHERE ticket_no = ? AND ticket_line_item = ? LIMIT 1");
    $old->execute([$ticket_no, $ticket_line]);
    $oldRow = $old->fetch(PDO::FETCH_ASSOC) ?: null;

    $oldSnap = ($oldRow && $oldRow['baseline_price'] !== null && (float)$oldRow['baseline_price'] != 0)
        ? ['price' => round((float)$oldRow['baseline_price'], 2), 'currency' => $oldRow['baseline_currency'] ?: 'IDR', 'fx_rate' => $oldRow['baseline_fx_rate']]
        : null;
    $newSnap = ['price' => $newPrice, 'currency' => $currency, 'fx_rate' => $fx['rate']];
    $curRates = [];
    foreach ($ctx['currencies'] as $cc => $cv) { $curRates[$cc] = (float)$cv['rate_to_idr']; }
    $priceChanged = ca_hist_price_changed($oldSnap, $newSnap, $curRates);
    if ($oldSnap !== null && $priceChanged && empty($post['confirm_edit'])) {
        return ['ok' => false, 'needs_confirm' => true, 'error' => 'this price is already saved - the change needs confirmation'];
    }

    $stmt = $pdo->prepare("UPDATE prs SET baseline_price = ?, baseline_currency = ?, baseline_fx_rate = ?, po_currency = ?, quotation_link = ? WHERE ticket_no = ? AND ticket_line_item = ?");
    $stmt->execute([$newPrice, $currency, $fx['rate'], $po_currency, $lk['value'] !== '' ? $lk['value'] : null, $ticket_no, $ticket_line]);
    if ($stmt->rowCount() === 0 && !$priceChanged) {
        return ['ok' => true, 'rows' => 0, 'error' => null];   // nothing actually changed
    }
    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'error' => 'PO line not found or already changed'];
    }

    if ($priceChanged) {
        ca_hist_log($pdo, $_SESSION, CA_HIST_QUOTATION_TICKET, ca_hist_key_ticket($ticket_no, $ticket_line),
            $oldSnap === null ? 'CREATE' : 'UPDATE', $oldSnap, $newSnap, 'CA_PAGE', ['summary' => 'Edited on the Cost Avoidance page']);
        $pdo->prepare("UPDATE vendor_quotation_last_price SET last_price = ?, currency = ?, fx_rate = ?, updated_by = ?
                       WHERE scope = 'item' AND ticket_no = ? AND ticket_line_item = ?")
            ->execute([$newPrice, $currency, $fx['rate'], $_SESSION['name'] ?? '', $ticket_no, $ticket_line]);
    }
    return ['ok' => true, 'rows' => $stmt->rowCount(), 'error' => null];
}

function ca_apply_update_po_currency_header(PDO $pdo, array $ctx, ?array $team_scope, array $post): array {
    $item   = (string)($post['item'] ?? '');
    $vendor = (string)($post['vendor'] ?? '');
    $uom    = (($post['uom'] ?? '') !== '') ? $post['uom'] : null;

    try {
        ca_assert_item_team_allowed($pdo, $ctx, $team_scope, $item, $vendor, $uom);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'scope_error' => true, 'error' => 'Outside your assigned team scope.'];
    }

    // Layered on top of the team check above — both must pass.
    try {
        ca_assert_item_not_aon($pdo, $item, $vendor, $uom);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'aon_error' => true, 'error' => 'AON (contract) item — cannot be modified.'];
    }

    $po_currency = strtoupper((string)($post['po_currency'] ?? ''));
    if (!ca_is_valid_currency($po_currency, $ctx['currencies'])) {
        return ['ok' => false, 'error' => 'invalid or inactive currency'];
    }

    $stmt = $pdo->prepare("
        UPDATE prs SET po_currency = ?
        WHERE item_description = ? AND vendor_name = ?
        AND po_number IS NOT NULL
        AND " . ca_not_intercompany_sql('') . "
    ");
    $stmt->execute([$po_currency, $item, $vendor]);
    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'error' => 'no matching PO lines'];
    }
    return ['ok' => true, 'rows' => $stmt->rowCount(), 'error' => null];
}

function ca_apply_update_po_currency_detail(PDO $pdo, array $ctx, ?array $team_scope, array $post): array {
    $ticket_no   = (string)($post['ticket_no'] ?? '');
    $ticket_line = (string)($post['ticket_line'] ?? '');

    try {
        ca_assert_item_team_allowed($pdo, $ctx, $team_scope, null, null, null, $ticket_no, $ticket_line);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'scope_error' => true, 'error' => 'Outside your assigned team scope.'];
    }

    // Layered on top of the team check above — both must pass.
    try {
        ca_assert_item_not_aon($pdo, null, null, null, $ticket_no, $ticket_line);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'aon_error' => true, 'error' => 'AON (contract) item — cannot be modified.'];
    }

    $po_currency = strtoupper((string)($post['po_currency'] ?? ''));
    if (!ca_is_valid_currency($po_currency, $ctx['currencies'])) {
        return ['ok' => false, 'error' => 'invalid or inactive currency'];
    }

    $stmt = $pdo->prepare("UPDATE prs SET po_currency = ? WHERE ticket_no = ? AND ticket_line_item = ?");
    $stmt->execute([$po_currency, $ticket_no, $ticket_line]);
    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'error' => 'PO line not found or already changed'];
    }
    return ['ok' => true, 'rows' => $stmt->rowCount(), 'error' => null];
}

// ca_apply_save_ca_taxonomy() lives in includes/cost_avoidance_helper.php now
// (moved there 2026-09-17 so ajax_project_quotation_save.php's Last Price
// modal can reuse it too, not just this page).

// Structured (not pre-formatted) failure info for one batch item, so the
// client can render it through the same modal component the client-side
// taxonomy-validation failure uses (caModalOpen()) instead of a second,
// server-only text format. item/vendor come straight off the change payload
// for group-level actions; detail-level actions (save_detail,
// update_po_currency_detail) carry no item/vendor of their own, so the
// client attaches them as informational _item/_vendor fields when staging
// (see caStageSaveDetail()/caStagePoCurrencyDetail() — cost_avoidance.php's
// <script> section) purely for a readable label here.
function ca_batch_item_label(array $change): array {
    $item   = trim((string)($change['item'] ?? $change['_item'] ?? ''));
    $vendor = trim((string)($change['vendor'] ?? $change['_vendor'] ?? ''));
    if ($item !== '') {
        return ['item' => $item, 'vendor' => $vendor];
    }
    if (!empty($change['ticket_no'])) {
        return ['item' => 'PO ' . $change['ticket_no'] . ' line ' . ($change['ticket_line'] ?? ''), 'vendor' => ''];
    }
    return ['item' => 'unknown item', 'vendor' => ''];
}

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // require_cost_avoidance_access() above admits the read-only Viewer role
    // (unrestricted scope), so every write entry point on this page states its
    // own requirement.
    ws_require_write();

    // 1. Save Baseline Header (Strip commas before saving)
    if ($_POST['action'] === 'save_header') {
        $result = ca_apply_save_header($pdo, $ca_ctx, $ca_team_scope, $_POST);
        if (!$result['ok'] && !empty($result['scope_error'])) {
            ca_reject_out_of_scope();
        }
        if (!$result['ok'] && !empty($result['aon_error'])) {
            ca_reject_aon();
        }
        // Any other failure (0 rows matched) is a silent no-op today, same as
        // before this function existed — the single-action path never had a
        // way to report it, and this PR doesn't add one for it.
    }
    // 2. Save Detail
    elseif ($_POST['action'] === 'save_detail') {
        $result = ca_apply_save_detail($pdo, $ca_ctx, $ca_team_scope, $_POST);
        if (!$result['ok'] && !empty($result['scope_error'])) {
            ca_reject_out_of_scope();
        }
        if (!$result['ok'] && !empty($result['aon_error'])) {
            ca_reject_aon();
        }
        if (!$result['ok']) {
            // Was silent before: tell the user why nothing was saved.
            $_SESSION['import_msg'] = 'Price not saved: ' . $result['error'] . '.';
            $_SESSION['import_type'] = 'error';
            header("Location: cost_avoidance.php");
            exit;
        }
    }
    // 3. Remove Baseline
    elseif ($_POST['action'] === 'remove_baseline') {
        $rb_t = (string)($_POST['ticket_no'] ?? '');
        $rb_l = (string)($_POST['ticket_line'] ?? '');
        // Same team-scope + AON guards every other write on this page has (this
        // one had neither).
        try {
            ca_assert_item_team_allowed($pdo, $ca_ctx, $ca_team_scope, null, null, null, $rb_t, $rb_l);
        } catch (RuntimeException $e) {
            ca_reject_out_of_scope();
        }
        try {
            ca_assert_item_not_aon($pdo, null, null, null, $rb_t, $rb_l);
        } catch (RuntimeException $e) {
            ca_reject_aon();
        }
        $rb_old = $pdo->prepare("SELECT baseline_price, baseline_currency, baseline_fx_rate FROM prs WHERE ticket_no = ? AND ticket_line_item = ? LIMIT 1");
        $rb_old->execute([$rb_t, $rb_l]);
        $rb_row = $rb_old->fetch(PDO::FETCH_ASSOC) ?: null;
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE prs SET baseline_price = NULL, baseline_fx_rate = NULL WHERE ticket_no = ? AND ticket_line_item = ?")
                ->execute([$rb_t, $rb_l]);
            if ($rb_row && $rb_row['baseline_price'] !== null && (float)$rb_row['baseline_price'] != 0) {
                // The dashboard's Initial Quotation Price for this line is the same
                // number -- remove it too, or the dashboard would keep showing a
                // price Cost Avoidance no longer has. Logged either way.
                $pdo->prepare("DELETE FROM vendor_quotation_last_price WHERE scope = 'item' AND ticket_no = ? AND ticket_line_item = ?")
                    ->execute([$rb_t, $rb_l]);
                ca_hist_log($pdo, $_SESSION, CA_HIST_QUOTATION_TICKET, ca_hist_key_ticket($rb_t, $rb_l), 'DELETE',
                    ['price' => round((float)$rb_row['baseline_price'], 2), 'currency' => $rb_row['baseline_currency'] ?: 'IDR', 'fx_rate' => $rb_row['baseline_fx_rate']],
                    null, 'CA_PAGE', ['summary' => 'Baseline removed on the Cost Avoidance page']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    // 4. Batch Update PO Currency (Header Level)
    elseif ($_POST['action'] === 'update_po_currency_header') {
        $result = ca_apply_update_po_currency_header($pdo, $ca_ctx, $ca_team_scope, $_POST);
        if (!$result['ok'] && !empty($result['scope_error'])) {
            ca_reject_out_of_scope();
        }
        if (!$result['ok'] && !empty($result['aon_error'])) {
            ca_reject_aon();
        }
    }
    // 5. Update PO Currency (Detail Level for Missing Baselines)
    elseif ($_POST['action'] === 'update_po_currency_detail') {
        $result = ca_apply_update_po_currency_detail($pdo, $ca_ctx, $ca_team_scope, $_POST);
        if (!$result['ok'] && !empty($result['scope_error'])) {
            ca_reject_out_of_scope();
        }
        if (!$result['ok'] && !empty($result['aon_error'])) {
            ca_reject_aon();
        }
    }
    // 5b. Save Project "Type of Work" (Level 2) taxonomy for a baseline group.
    elseif ($_POST['action'] === 'save_ca_taxonomy') {
        $result = ca_apply_save_ca_taxonomy($pdo, $ca_ctx, $ca_team_scope, $_POST);
        if (!$result['ok']) {
            if (!empty($result['scope_error'])) {
                ca_reject_out_of_scope();
            }
            if (!empty($result['aon_error'])) {
                ca_reject_aon();
            }
            $_SESSION['import_msg'] = 'Invalid Level 2 taxonomy selection for this team.';
            $_SESSION['import_type'] = 'error';
            header("Location: cost_avoidance.php");
            exit;
        }
    }
    // 5c. Input Baselines tab: batch-save every staged change from one client-
    // side "Save" click. Each item is routed through the exact same
    // ca_apply_*() function (and therefore the same
    // ca_assert_item_team_allowed() check / v_item_taxonomy tamper-check /
    // 4-column group-key match) as its single-action counterpart above — this
    // loops over those functions, it does not reimplement any write.
    //
    // Team-scope failures roll back the WHOLE batch and abort: a crafted/
    // out-of-scope item is a trust-boundary violation (the same one
    // ca_reject_out_of_scope() already guards everywhere else), not a data
    // race, so partially applying around it would still mean trusting a
    // client that sent a tampered item. Any other per-item failure (a row
    // someone else already baselined between page-load and Save, an invalid
    // taxonomy selection) is a genuine race/staleness condition instead: that
    // item is skipped and reported, but every other valid item in the same
    // batch still commits — mirroring this file's own CSV import above
    // ("$success_count lines ... $error_count lines could not be matched"),
    // the existing precedent in this exact file for "some rows in a bulk
    // operation don't match, report and move on" rather than "one bad row
    // voids everyone else's work."
    elseif ($_POST['action'] === 'batch_save_baselines') {
        $changes = json_decode((string)($_POST['changes'] ?? '[]'), true);
        if (!is_array($changes)) {
            $changes = [];
        }

        $pdo->beginTransaction();
        $applied = 0;
        $failed = [];
        foreach ($changes as $change) {
            if (!is_array($change) || !isset($change['action'])) {
                continue;
            }

            switch ($change['action']) {
                case 'save_ca_taxonomy':
                    $result = ca_apply_save_ca_taxonomy($pdo, $ca_ctx, $ca_team_scope, $change);
                    break;
                case 'save_header':
                    $result = ca_apply_save_header($pdo, $ca_ctx, $ca_team_scope, $change);
                    break;
                case 'update_po_currency_header':
                    $result = ca_apply_update_po_currency_header($pdo, $ca_ctx, $ca_team_scope, $change);
                    break;
                case 'save_detail':
                    $result = ca_apply_save_detail($pdo, $ca_ctx, $ca_team_scope, $change);
                    break;
                case 'update_po_currency_detail':
                    $result = ca_apply_update_po_currency_detail($pdo, $ca_ctx, $ca_team_scope, $change);
                    break;
                default:
                    $result = ['ok' => false, 'error' => 'unknown action'];
            }

            if (!empty($result['scope_error'])) {
                $pdo->rollBack();
                ca_reject_out_of_scope();
            }

            // An AON item is treated like the scope violation above, not like
            // the per-item staleness failures below: the whole batch rolls back
            // and aborts. AON lines are absent from every read, so a staged one
            // means a stale tab or a crafted payload — the same "don't trust the
            // rest of this payload either" situation, not a row someone else
            // happened to baseline mid-edit.
            if (!empty($result['aon_error'])) {
                $pdo->rollBack();
                ca_reject_aon();
            }

            if ($result['ok']) {
                $applied++;
            } else {
                $label = ca_batch_item_label($change);
                $failed[] = [
                    'item'   => $label['item'],
                    'vendor' => $label['vendor'],
                    'reason' => $result['error'],
                    'action' => $change['action'],
                ];
            }
        }
        $pdo->commit();

        if ($failed) {
            // Structured, not an HTML string: rendered client-side through
            // caModalOpen() — the same modal component the client-side
            // taxonomy-validation failure uses, per the "one UX pattern for
            // both failure paths" requirement. Deliberately NOT import_msg —
            // that still feeds the plain inline banner every other action on
            // this page uses, which this path no longer shows.
            $_SESSION['ca_batch_failures'] = json_encode(['applied' => $applied, 'failed' => $failed]);
            header("Location: cost_avoidance.php");
            exit;
        }

        header("Location: cost_avoidance.php?success=1");
        exit;
    }
    // 6. Update Currency Settings (rate + active toggle, every non-IDR currency)
    elseif ($_POST['action'] === 'update_currencies') {
        $rates = $_POST['rate_to_idr'] ?? [];
        $actives = $_POST['is_active'] ?? [];
        $validCodes = $pdo->query("SELECT code FROM currencies WHERE code != 'IDR'")->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $pdo->prepare("UPDATE currencies SET rate_to_idr = ?, is_active = ? WHERE code = ?");
        $rateRejected = [];
        foreach ($validCodes as $code) {
            if (!isset($rates[$code])) continue;
            $clean_rate = str_replace(',', '', (string)$rates[$code]);
            if (!is_numeric($clean_rate)) continue;
            $rate = (float)$clean_rate;
            // A rate must never be negative, and a currency that saved rows already
            // use must keep a real rate: with none, those rows would have no IDR
            // value (they are left out of the totals). Keep the old rate and say so.
            // Switching a currency OFF is always safe: conversion no longer depends
            // on is_active, only the pickers do.
            if ($rate < 0 || !is_finite($rate) || ($rate == 0 && ca_currency_in_use($pdo, $code))) {
                $rateRejected[] = $code;
                continue;
            }
            $isActive = isset($actives[$code]) ? 1 : 0;
            $stmt->execute([$rate, $isActive, $code]);
            // USD's rate is dual-written to the legacy system_settings key so
            // cost_savings_helper.php's independent usd_exchange_rate read (a
            // separate feature, not itself in multi-currency scope) stays in sync.
            if ($code === 'USD') {
                $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'usd_exchange_rate'")->execute([$rate]);
            }
        }
        if ($rateRejected) {
            $_SESSION['import_msg'] = 'Rate not changed for ' . htmlspecialchars(implode(', ', $rateRejected))
                . ': a rate must be greater than 0 while saved prices or POs use that currency (they would lose their Rupiah value). All other settings were saved.';
        }
    }
    // 7. Update Team Targets
    elseif ($_POST['action'] === 'update_targets') {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['target_CSGSM', $_POST['target_csgsm']]);
        $stmt->execute(['target_TIGS', $_POST['target_tigs']]);
        $stmt->execute(['target_FNBOM', $_POST['target_fnbom']]);
        $stmt->execute(['target_ADMIN', $_POST['target_admin']]);
        // Phase F-5 (Task D): Project team targets (PKR / PKP / MFM).
        $stmt->execute(['target_PKR', $_POST['target_pkr'] ?? 4.5]);
        $stmt->execute(['target_PKP', $_POST['target_pkp'] ?? 4.5]);
        $stmt->execute(['target_MFM', $_POST['target_mfm'] ?? 4.5]);
    }
    // 8. Update Google API Credentials
    elseif ($_POST['action'] === 'update_google_api') {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['google_client_id', $_POST['client_id']]);
        $stmt->execute(['google_api_key', $_POST['api_key']]);
    }
    // 8b/8c/8d. Manual Cost Avoidance: Create / Update / Delete (separate table,
    // not tied to any PR/PO). All three go through includes/manual_ca_helper.php.
    elseif (in_array($_POST['action'], ['create_manual_ca', 'update_manual_ca', 'delete_manual_ca'], true)) {
        if ($_POST['action'] === 'delete_manual_ca') {
            $mr = manual_ca_delete($pdo, $_SESSION, $ca_team_scope, (int)($_POST['m_id'] ?? 0), 'MANUAL');
        } else {
            // create ignores any posted m_id; update needs one.
            if ($_POST['action'] === 'create_manual_ca') { $_POST['m_id'] = 0; }
            $mr = manual_ca_save($pdo, $_SESSION, $ca_team_scope, $_POST, 'MANUAL', !empty($_POST['confirm_edit']));
        }
        if (!$mr['ok']) {
            $_SESSION['import_msg'] = !empty($mr['needs_confirm'])
                ? 'This entry is already saved - the change was not applied because it was not confirmed. Please try again and confirm.'
                : $mr['message'];
            $_SESSION['import_type'] = 'error';
            header("Location: cost_avoidance.php");
            exit;
        }
    }
    // 9. Import Baselines CSV
    elseif ($_POST['action'] === 'import_baselines' && isset($_FILES['baseline_csv'])) {
        $file = $_FILES['baseline_csv'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            if (strtolower($ext) === 'csv') {
                if (($handle = fopen($file['tmp_name'], 'r')) !== FALSE) {
                    $headers = fgetcsv($handle, 10000, ',');
                    if ($headers !== FALSE) {
                        $headers = array_map('strtolower', array_map('trim', $headers));
                        
                        $ticket_idx = -1;
                        $line_idx = -1;
                        $price_idx = -1;
                        $pillar_idx = -1;
                        
                        $ticket_headers = ['ticket_no', 'ticket number', 'ticket_number', 'ticket no', 'ticketno'];
                        $line_headers = ['ticket_line', 'ticket_line_item', 'line', 'ticket line', 'line item', 'line_item'];
                        $price_headers = ['initial_quotation_price', 'initial quotation price', 'last_quotation_price', 'baseline_price', 'last quotation price', 'price', 'baseline', 'quotation price', 'quotation'];
                        $pillar_headers = ['pillar', 'level_0', 'level 0', 'team'];
                        
                        foreach ($headers as $idx => $header) {
                            if (in_array($header, $ticket_headers)) $ticket_idx = $idx;
                            if (in_array($header, $line_headers)) $line_idx = $idx;
                            if (in_array($header, $price_headers)) $price_idx = $idx;
                            if (in_array($header, $pillar_headers)) $pillar_idx = $idx;
                        }
                        
                        if ($ticket_idx === -1) {
                            foreach ($headers as $idx => $header) {
                                if (strpos($header, 'ticket') !== false || strpos($header, 'no') !== false) {
                                    $ticket_idx = $idx; break;
                                }
                            }
                        }
                        if ($line_idx === -1) {
                            foreach ($headers as $idx => $header) {
                                if (strpos($header, 'line') !== false) {
                                    $line_idx = $idx; break;
                                }
                            }
                        }
                        if ($price_idx === -1) {
                            foreach ($headers as $idx => $header) {
                                if (strpos($header, 'price') !== false || strpos($header, 'quota') !== false || strpos($header, 'base') !== false) {
                                    $price_idx = $idx; break;
                                }
                            }
                        }
                        if ($pillar_idx === -1) {
                            foreach ($headers as $idx => $header) {
                                if (strpos($header, 'pillar') !== false || strpos($header, 'team') !== false || strpos($header, 'level_0') !== false || strpos($header, 'level 0') !== false) {
                                    $pillar_idx = $idx; break;
                                }
                            }
                        }
                        
                        if ($ticket_idx !== -1 && $line_idx !== -1 && $price_idx !== -1) {
                            $success_count = 0;
                            $error_count = 0;
                            
                            // This upload carries no currency column, so it can only ever
                            // write Rupiah. A line that already holds a foreign-currency
                            // baseline (USD, SGD, ...) is therefore left alone and counted:
                            // overwriting it would re-tag a foreign price as IDR.
                            $foreign_skipped = 0;
                            $cur_check = $pdo->prepare("SELECT baseline_currency FROM prs WHERE ticket_no = ? AND ticket_line_item = ? AND baseline_price IS NOT NULL AND baseline_price <> 0 LIMIT 1");
                            $idr_only = " AND (baseline_currency IS NULL OR TRIM(baseline_currency) = '' OR UPPER(TRIM(baseline_currency)) = 'IDR')";
                            $has_pillar = ($pillar_idx !== -1 && $has_pillar_column);
                            if ($has_pillar) {
                                $stmt = $pdo->prepare("
                                    UPDATE prs
                                    SET baseline_price = ?, baseline_currency = 'IDR', baseline_fx_rate = NULL, pillar = ?
                                    WHERE ticket_no = ? AND ticket_line_item = ?" . $idr_only . "
                                ");
                            } else {
                                $stmt = $pdo->prepare("
                                    UPDATE prs
                                    SET baseline_price = ?, baseline_currency = 'IDR', baseline_fx_rate = NULL
                                    WHERE ticket_no = ? AND ticket_line_item = ?" . $idr_only . "
                                ");
                            }

                            while (($row = fgetcsv($handle, 10000, ',')) !== FALSE) {
                                if (empty(array_filter($row))) continue;
                                $t_val = trim((string)($row[$ticket_idx] ?? ''));
                                $l_val = trim((string)($row[$line_idx] ?? ''));
                                $p_val = trim((string)($row[$price_idx] ?? ''));
                                
                                $p_val_clean = str_replace(['Rp', ',', ' ', '$'], '', $p_val);
                                
                                if ($t_val !== '' && $l_val !== '' && is_numeric($p_val_clean)) {
                                    $cur_check->execute([$t_val, $l_val]);
                                    $saved_cur = $cur_check->fetchColumn();
                                    if ($saved_cur !== false && strtoupper(trim((string)$saved_cur)) !== '' && strtoupper(trim((string)$saved_cur)) !== 'IDR') {
                                        $foreign_skipped++;
                                        continue;
                                    }
                                    $params = $has_pillar
                                        ? [(float)$p_val_clean, trim((string)($row[$pillar_idx] ?? '')), $t_val, $l_val]
                                        : [(float)$p_val_clean, $t_val, $l_val];
                                    
                                    if ($stmt->execute($params)) {
                                        $success_count++;
                                    } else {
                                        $error_count++;
                                    }
                                } else {
                                    $error_count++;
                                }
                            }
                            fclose($handle);
                            $_SESSION['import_msg'] = "<strong>Import completed:</strong> Matched and updated <strong>$success_count lines</strong> successfully. $error_count lines could not be matched or had invalid data."
                                . ($foreign_skipped > 0 ? " <strong>$foreign_skipped lines were skipped</strong> because they already hold a foreign-currency price (change those on the Savings Report or the dashboard, where the currency can be chosen)." : '');
                            $_SESSION['import_type'] = "success";
                        } else {
                            fclose($handle);
                            $_SESSION['import_msg'] = "Could not map CSV columns. Please check your headers. Ensure columns for Ticket Number, Ticket Line, and Price exist.";
                            $_SESSION['import_type'] = "error";
                        }
                    } else {
                        fclose($handle);
                        $_SESSION['import_msg'] = "The uploaded file is empty.";
                        $_SESSION['import_type'] = "error";
                    }
                }
            } else {
                $_SESSION['import_msg'] = "Invalid file type. Please upload a CSV file.";
                $_SESSION['import_type'] = "error";
            }
        } else {
            $_SESSION['import_msg'] = "Error uploading file. Code: " . $file['error'];
            $_SESSION['import_type'] = "error";
        }
        header("Location: cost_avoidance.php");
        exit;
    }
    
    header("Location: cost_avoidance.php?success=1");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tax_rule'])) {
    // require_cost_avoidance_access() above admits the read-only Viewer role
    // (unrestricted scope), so every write entry point on this page states its
    // own requirement.
    ws_require_write();
    $stmt = $pdo->prepare("INSERT INTO tax_rules (p_org_prefix, is_inclusive_ppn) VALUES (?, 1) ON DUPLICATE KEY UPDATE is_inclusive_ppn = 1");
    $stmt->execute([trim(strtoupper($_POST['new_porg_prefix']))]);
    header("Location: cost_avoidance.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_tax_rule'])) {
    // require_cost_avoidance_access() above admits the read-only Viewer role
    // (unrestricted scope), so every write entry point on this page states its
    // own requirement.
    ws_require_write();
    $stmt = $pdo->prepare("DELETE FROM tax_rules WHERE p_org_prefix = ?");
    $stmt->execute([$_POST['prefix_to_delete']]);
    header("Location: cost_avoidance.php");
    exit;
}

// --- MASTER DATA FETCH ENGINE ---
// Rendered by the Settings tab's tax-rule list. The PPN prefix MATCHING that used
// to be derived from this now lives in ca_is_inclusive_expr(), which reads
// tax_rules directly in SQL.
$tax_rules = $pdo->query("SELECT * FROM tax_rules ORDER BY p_org_prefix ASC")->fetchAll(PDO::FETCH_ASSOC);

// Rendered by the Settings tab's target form. The charts get their own copy from
// ca_team_targets() so the endpoint does not depend on this page.
$team_targets = ca_team_targets($pdo);

// Canonical source is ca_manual_team_options() (includes/cost_avoidance_helper.php)
// — the same 7-entry map ca_manual_aggregate() and ca_user_team_code() already
// use. This used to be a second, inline-duplicated copy of that same list
// (Phase 0 flagged both existed; this was the one actually live in the
// form-rendering path below) — removed in favor of the one shared source.
$manual_team_options = ca_manual_team_options();
$manual_team_full_to_code = array_flip($manual_team_options);

// Scoped to the session's resolved team scope ($ca_team_scope, from the Phase
// 2 access gate): unrestricted sessions (CM, exception Managers) still see
// all 7; a team-scoped session sees only their own team(s) in both the
// create-form and edit-form dropdowns.
$manual_team_options_scoped = ($ca_team_scope === null)
    ? $manual_team_options
    : array_intersect_key($manual_team_options, array_flip($ca_team_scope));

// Default the Manual Cost Avoidance "Team" field via ca_resolve_session_team_scope()
// (through $ca_team_scope) instead of the old raw users.team_name lookup,
// which was blank for every Project session and so never gave any of them a
// default (Addendum #3 §C2). Unrestricted sessions get no default, matching
// prior behavior. A multi-team session (e.g. Rio, PKR+MFM) defaults to the
// first of their allowed teams — arbitrary but deterministic per request;
// both are equally "theirs," so there's no more-correct single choice.
$current_user_team_full = null;
if ($ca_team_scope !== null && !empty($ca_team_scope)) {
    $current_user_team_full = $manual_team_options[$ca_team_scope[0]] ?? null;
}

// Vendor taxonomy for the Manual CA "Search Database" modal (same vendor
// lookup/filters used by the RFQ tender feature's vendor search).
try {
    $vss_pdo_taxonomy = get_vss_pdo();
    $vss_company_types = $vss_pdo_taxonomy->query("SELECT DISTINCT company_type FROM vendors WHERE company_type IS NOT NULL AND company_type != '' ORDER BY company_type ASC")->fetchAll(PDO::FETCH_COLUMN);
    $vss_core_businesses = $vss_pdo_taxonomy->query("SELECT DISTINCT sub_company_type FROM vendors WHERE sub_company_type IS NOT NULL AND sub_company_type != '' ORDER BY sub_company_type ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $vss_company_types = [];
    $vss_core_businesses = [];
}

$google_settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('google_client_id', 'google_api_key')")->fetchAll(PDO::FETCH_KEY_PAIR);
$google_client_id = $google_settings['google_client_id'] ?? '';
$google_api_key = $google_settings['google_api_key'] ?? '';

// Level 2 ("type of work") taxonomy options per Project team, for the Input
// Baselines tab's conditional dropdown (rendered only for PKR/PKP/MFM
// groups). Comes from CA_PROJECT_TAXONOMY (the business's code list, in
// cost_avoidance_helper.php) — same for every group — embedded as a JS const below
// so each group's row render picks its own team's list with no extra
// round-trip. Same tamper-check set save_ca_taxonomy re-validates against.
$ca_project_level2_options = [];
foreach (CA_PROJECT_TEAM_LEVEL1 as $ca_team_code => $ca_team_level1) {
    $ca_project_level2_options[$ca_team_code] = ca_project_level2_options($pdo, $ca_team_level1);
}

// --- DATE FILTER LOGIC ---
// Only the raw filter values are needed here: they seed the form controls and the
// caFilters / caBFilters objects the JS sends to the endpoints, which resolve them
// with the same ca_date_range() this page used to call directly.
$g_filter = $_GET['g_filter'] ?? 'all';
$g_from = $_GET['g_from'] ?? '';
$g_to = $_GET['g_to'] ?? '';

$b_filter = $_GET['b_filter'] ?? 'all';
$b_from = $_GET['b_from'] ?? '';
$b_to = $_GET['b_to'] ?? '';

// --- PAGE DATA -------------------------------------------------------------
// $ca_ctx was already resolved near the top of the file (needed earlier by
// the POST action handler's write-side team checks).
// This is the Manual CA tab's OWN render call — $ca_team_scope is passed here
// (unlike ajax_cost_avoidance_charts.php's Mixed-chart caller, which must
// NEVER receive it — that chart stays unrestricted for every session).
$manual = ca_manual_aggregate(
    $pdo,
    ca_normalize_filters(['g_filter' => $g_filter, 'g_from' => $g_from, 'g_to' => $g_to]),
    ca_effective_currencies($ca_ctx),
    $ca_team_scope
);
$manual_entries = $manual['entries'];

// Fixed UoM dropdown for Manual Cost Avoidance entries (no UoM master table exists in this app).
$manual_uom_options = ['AU', 'BOX', 'BT', 'CAN', 'CAR', 'DPR', 'EA', 'GAL', 'KG', 'M', 'M2', 'M3', 'MON', 'PAA', 'PAC', 'PC', 'RIM', 'ROL', 'SET', 'UNT'];

require_once '../includes/header.php';
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Read-only 'Viewer' session. Hides write affordances only; the server refuses
// the matching endpoints regardless of what this page renders. Declared outside
// the Google-credentials block below so switchTab() can rely on it always existing.
var IS_VIEWER = <?= json_encode($is_viewer) ?>;
</script>

<?php if ($google_client_id && $google_api_key): ?>
<script async defer src="https://apis.google.com/js/api.js" onload="onApiLoad()"></script>
<script async defer src="https://accounts.google.com/gsi/client" onload="gisLoaded()"></script>
<script>
    let pickerApiLoaded = false;
    let tokenClient;
    let oauthToken = null;

    function onApiLoad() {
        gapi.load('picker', {'callback': function() { pickerApiLoaded = true; }});
    }

    function gisLoaded() {
        tokenClient = google.accounts.oauth2.initTokenClient({
            client_id: '<?= htmlspecialchars($google_client_id) ?>',
            scope: 'https://www.googleapis.com/auth/drive.readonly',
            callback: '', // Defined dynamically in createPicker
        });
    }

    function createPicker(inputId) {
        if (!pickerApiLoaded || !tokenClient) {
            alert('Google APIs are not loaded yet. Please wait a moment or check your credentials.');
            return;
        }

        tokenClient.callback = async (response) => {
            if (response.error !== undefined) {
                throw (response);
            }
            oauthToken = response.access_token;
            
            const view = new google.picker.View(google.picker.ViewId.DOCS);
            const picker = new google.picker.PickerBuilder()
                .enableFeature(google.picker.Feature.NAV_HIDDEN)
                .setDeveloperKey('<?= htmlspecialchars($google_api_key) ?>')
                .setOAuthToken(oauthToken)
                .addView(view)
                .setCallback((data) => {
                    if (data.action == google.picker.Action.PICKED) {
                        const file = data.docs[0];
                        document.getElementById(inputId).value = file.url;
                    }
                })
                .build();
            picker.setVisible(true);
        };

        if (oauthToken === null) {
            tokenClient.requestAccessToken({prompt: 'consent'});
        } else {
            tokenClient.requestAccessToken({prompt: ''});
        }
    }
</script>
<?php endif; ?>

<div class="mb-6 flex justify-between items-end border-b pb-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">Cost Avoidance Matrix</h2>
        <p class="text-sm text-gray-600">Financial tracking against total managed spend.</p>
    </div>
    <a href="dashboard_mgr.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded-lg text-sm shadow transition">&larr; Back to Dashboard</a>
</div>

<?php if(isset($_GET['success'])): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 shadow-sm font-bold">Database updated successfully!</div>
<?php endif; ?>

<?php if (isset($_SESSION['import_msg'])): ?>
    <div class="p-4 mb-6 rounded-lg font-medium <?= $_SESSION['import_type'] === 'success' ? 'bg-green-100 text-green-800 border-l-4 border-green-500' : 'bg-red-100 text-red-800 border-l-4 border-red-500' ?>">
        <?= $_SESSION['import_msg'] ?>
    </div>
    <?php unset($_SESSION['import_msg'], $_SESSION['import_type']); ?>
<?php endif; ?>

<!-- Shared, hidden-by-default error banner for the ajax_cost_avoidance_*.php
     endpoints — surfaces a short message instead of failing silently. Same
     pattern as #slaAjaxError on sla_report.php. -->
<div id="caAjaxError" class="hidden mb-4 bg-red-50 border border-red-200 text-red-700 text-xs font-semibold px-4 py-2 rounded-lg"></div>

<!-- GLOBAL DATE FILTER -->
<div class="mb-6 bg-white p-4 rounded-lg shadow-sm border border-gray-200">
    <h3 class="text-xs font-bold text-gray-600 uppercase mb-3">Global Date Filter (Applies to all Tabs)</h3>
    <!-- Applies without reloading the page: the two server-side tables and the
         charts re-query themselves from the same filter object (see caRefreshAll). -->
    <form id="globalFilterForm" class="flex flex-wrap gap-2 items-end">
        <div class="flex flex-col">
            <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Quick Filter</label>
            <select id="gFilterSelect" name="g_filter" onchange="toggleCustomDates(this, 'global')" class="text-xs border border-gray-300 rounded px-2 py-1.5 focus:ring-indigo-500">
                <option value="all" <?= $g_filter === 'all' ? 'selected' : '' ?>>All Time</option>
                <option value="this_month" <?= $g_filter === 'this_month' ? 'selected' : '' ?>>This Month</option>
                <option value="last_month" <?= $g_filter === 'last_month' ? 'selected' : '' ?>>Last Month</option>
                <option value="last_2_weeks" <?= $g_filter === 'last_2_weeks' ? 'selected' : '' ?>>Last 2 Weeks</option>
                <option value="custom" <?= $g_filter === 'custom' ? 'selected' : '' ?>>Custom Range</option>
            </select>
        </div>
        
        <div id="global-custom-dates" class="<?= $g_filter === 'custom' ? 'flex' : 'hidden' ?> flex-wrap gap-2 items-end">
            <div class="flex flex-col">
                <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">From</label>
                <input type="date" id="gFrom" name="g_from" value="<?= htmlspecialchars($g_from) ?>" class="text-xs border border-gray-300 rounded px-2 py-1 focus:ring-indigo-500">
            </div>
            <div class="flex flex-col">
                <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">To</label>
                <input type="date" id="gTo" name="g_to" value="<?= htmlspecialchars($g_to) ?>" class="text-xs border border-gray-300 rounded px-2 py-1 focus:ring-indigo-500">
            </div>
        </div>

        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold py-1.5 px-4 rounded shadow-sm transition">Apply</button>
        <!-- Resets both date filters and the team selection in place. Always shown
             now: with no page reload there is no server-rendered state to key it off. -->
        <a href="#" id="globalFilterClear" class="text-xs text-gray-500 hover:text-gray-700 underline font-semibold ml-2">Clear</a>
    </form>
</div>

<div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200 mb-6">
    <div class="flex flex-col md:flex-row md:justify-between md:items-start gap-3 mb-1">
        <div>
            <h3 class="text-sm font-bold text-gray-700 mb-1" id="chartTitle">Financial Impact Summary</h3>
            <p class="text-xs text-gray-500" id="chartSubtitle">Total Spend reflects all active POs. Avoidance % is calculated against the team's entire managed spend.</p>
        </div>
        <div class="flex space-x-1 bg-gray-200 p-1 rounded-lg max-w-fit flex-shrink-0">
            <button type="button" onclick="switchChartView('po')" id="chartview-po" class="px-4 py-1.5 text-xs font-bold rounded-md bg-white text-indigo-700 shadow shadow-sm transition">PO-Based</button>
            <button type="button" onclick="switchChartView('mixed')" id="chartview-mixed" class="px-4 py-1.5 text-xs font-bold rounded-md text-gray-600 hover:text-gray-800 transition">Mixed</button>
        </div>
    </div>
    <div class="relative h-64" id="chartview-content-po">
        <div class="viz-chart-loader absolute inset-0 z-10 flex items-center justify-center bg-white bg-opacity-80">
            <div class="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
        </div>
        <canvas id="financialChart"></canvas>
    </div>
    <div class="relative h-64 hidden" id="chartview-content-mixed">
        <div class="viz-chart-loader-mixed absolute inset-0 z-10 flex items-center justify-center bg-white bg-opacity-80">
            <div class="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
        </div>
        <!-- Both are always in the DOM now; caLoadCharts() toggles them, since
             whether there is data is only known once the response arrives. -->
        <div id="mixedEmptyState" class="hidden absolute inset-0 flex items-center justify-center text-sm text-gray-400 font-medium">No Cost Avoidance data in the selected date range.</div>
        <canvas id="mixedFinancialChart"></canvas>
    </div>
</div>

<div class="mb-6 bg-white p-4 rounded-lg shadow-sm border border-gray-200">
    <div class="flex flex-col md:flex-row md:justify-between md:items-start gap-4">
        <div>
            <h3 class="text-xs font-bold text-gray-600 uppercase mb-3">Quick Filter by Team (Applies to all Tabs)</h3>
            <!-- Rebuilt by caRenderTeamButtons() from the charts response, so the list
                 only ever offers teams that exist in the active date window. -->
            <div id="teamFilterButtons" class="flex flex-wrap gap-2">
                <button type="button" class="team-filter-btn text-xs font-bold bg-gray-100 text-gray-800 border border-gray-300 px-3 py-1.5 rounded hover:bg-gray-200 transition focus:outline-none" data-team="">All Teams</button>
            </div>
        </div>
        <div class="bg-gray-50 p-3 rounded border border-gray-100 text-xs">
            <h3 class="font-bold text-gray-700 mb-1">Team Acronyms</h3>
            <ul class="text-gray-600 space-y-1">
                <li><strong class="text-indigo-700">CSGSM:</strong> Corporate Serv., Gen Supplies & Marketing</li>
                <li><strong class="text-indigo-700">TIGS:</strong> Tech, Industrial, & General Services</li>
                <li><strong class="text-indigo-700">FNBOM:</strong> FNB & Operational Merchandise</li>
                <li><strong class="text-indigo-700">ADMIN:</strong> Admin Team</li>
                <li><strong class="text-indigo-700">PKR:</strong> Project Konstruksi Retail</li>
                <li><strong class="text-indigo-700">PKP:</strong> Project Konstruksi Properti</li>
                <li><strong class="text-indigo-700">MFM:</strong> Maintenance &amp; Facility Management</li>
            </ul>
        </div>
    </div>
</div>

<div class="flex space-x-1 bg-gray-200 p-1 rounded-lg mb-6 max-w-fit">
    <button onclick="switchTab('report')" id="tab-report" class="px-6 py-2 text-sm font-bold rounded-md bg-white text-indigo-700 shadow shadow-sm transition">Savings Report</button>
    <?php if (!$is_viewer): // data-entry tab: nothing on it is readable-only ?>
    <button onclick="switchTab('input')" id="tab-input" class="px-6 py-2 text-sm font-bold rounded-md text-gray-600 hover:text-gray-800 transition">Input Baselines <span class="ml-2 bg-red-500 text-white px-2 py-0.5 rounded-full text-xs" id="input-counter">–</span></button>
    <?php endif; ?>
    <button onclick="switchTab('manual')" id="tab-manual" class="px-6 py-2 text-sm font-bold rounded-md text-gray-600 hover:text-gray-800 transition">Manual Input Cost Avoidance</button>

    <?php if ($_SESSION['role'] === 'Category Manager'): ?>
        <button onclick="switchTab('tax')" id="tab-tax" class="px-6 py-2 text-sm font-bold rounded-md text-gray-600 hover:text-gray-800 transition">Settings & PPN Rules</button>
    <?php endif; ?>
</div>

<div id="content-report" class="tab-content">
    <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200 relative">
        <div class="viz-table-loader absolute inset-0 z-20 flex flex-col items-center justify-center bg-white bg-opacity-80 rounded-lg">
            <div class="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
            <p class="mt-3 text-xs font-semibold text-gray-500">Loading report…</p>
        </div>
        <!-- Horizontal scroll wrapper: the report rows (long Rp figures, SKU
             descriptions, Action buttons) are wider than this card on smaller
             viewports. Without this the table pushed past the card's right edge.
             overflow-x-auto keeps the overflow scrolling INSIDE the card. -->
        <div class="overflow-x-auto">
        <table id="reportTable" class="min-w-full leading-normal w-full text-left">
            <thead>
                <tr class="bg-gray-100 border-b">
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase">PO Number</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase">Team</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase">SKU & Item Description</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase text-right">PO Total & Nett Price</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase text-right">Quotation Total & Unit Price</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase text-right">Cost Avoidance</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <!-- Rows are fetched from ajax_cost_avoidance_table.php and rendered by
                     DataTables' columns.render (see the #reportTable init below). Nothing is
                     server-rendered here any more: the page used to echo one <tr> per priced
                     PR line, so its HTML grew with the size of `prs`. -->
            </tbody>
            <tfoot>
                <tr class="bg-gray-50 border-t-2 border-gray-300">
                    <td colspan="5" class="p-4 text-right font-bold text-gray-800 uppercase text-lg">Total Cost Avoidance:</td>
                    <td id="reportTotalAvoidance" class="p-4 text-right font-bold font-mono text-xl text-gray-800 whitespace-nowrap">Rp 0</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</div>

<div id="content-input" class="tab-content hidden">
    <div class="bg-white shadow-md rounded-lg border border-gray-200">
        <div class="p-6 border-b border-gray-200 bg-gray-50 rounded-t-lg">
            <h3 class="text-lg font-bold text-gray-800 mb-1">Items Missing Quotation Data</h3>
            <p class="text-sm text-gray-600">Input a quotation price at the header level to apply it to all pending POs, or expand an item to set specific prices for individual POs.</p>
        </div>

        <!-- This tab's own canvassing-date filter. No longer a GET form that reloads
             the page: it narrows the same server-side query the group list already
             uses, so applying it is one AJAX round trip. -->
        <div class="bg-gray-50 border-b border-gray-200 p-4 pt-0">
            <div class="flex flex-wrap gap-2 items-end">
                <div class="flex flex-col">
                    <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Filter Canvassing Date</label>
                    <select id="bFilterSelect" onchange="toggleCustomDates(this, 'baseline')" class="text-xs border border-gray-300 rounded px-2 py-1.5 focus:ring-indigo-500">
                        <option value="all" <?= $b_filter === 'all' ? 'selected' : '' ?>>All Time</option>
                        <option value="this_month" <?= $b_filter === 'this_month' ? 'selected' : '' ?>>This Month</option>
                        <option value="last_month" <?= $b_filter === 'last_month' ? 'selected' : '' ?>>Last Month</option>
                        <option value="last_2_weeks" <?= $b_filter === 'last_2_weeks' ? 'selected' : '' ?>>Last 2 Weeks</option>
                        <option value="custom" <?= $b_filter === 'custom' ? 'selected' : '' ?>>Custom Range</option>
                    </select>
                </div>

                <div id="baseline-custom-dates" class="<?= $b_filter === 'custom' ? 'flex' : 'hidden' ?> flex-wrap gap-2 items-end">
                    <div class="flex flex-col">
                        <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">From</label>
                        <input type="date" id="bFrom" value="<?= htmlspecialchars($b_from) ?>" class="text-xs border border-gray-300 rounded px-2 py-1 focus:ring-indigo-500">
                    </div>
                    <div class="flex flex-col">
                        <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">To</label>
                        <input type="date" id="bTo" value="<?= htmlspecialchars($b_to) ?>" class="text-xs border border-gray-300 rounded px-2 py-1 focus:ring-indigo-500">
                    </div>
                </div>

                <button type="button" id="bApplyBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold py-1.5 px-4 rounded shadow-sm transition">Apply Filter</button>
            </div>
        </div>

        <div class="p-4 relative">
            <div class="viz-baselines-loader absolute inset-0 z-20 flex flex-col items-center justify-center bg-white bg-opacity-80 rounded-lg">
                <div class="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
                <p class="mt-3 text-xs font-semibold text-gray-500">Loading queue…</p>
            </div>

            <!-- Search bar (DataTables' own #baselinesTable_filter box is relocated
                 here after init, see caBaselinesFilterSlot below) on the left, the
                 unsaved-changes indicator on the right — hidden until the
                 staged-changes model (caPendingChanges) has at least one entry. -->
            <div class="flex justify-between items-center mb-2 gap-3 flex-wrap">
                <div id="caBaselinesFilterSlot" class="flex-shrink-0"></div>
                <!-- `flex` must sit alongside `hidden` here: caUpdateUnsavedBar()
                     shows this by removeClass('hidden') alone, and without an
                     explicit display utility the div fell back to display:block,
                     which silently no-op'd items-center/gap-3 and let the icon,
                     count and buttons wrap onto a second line. Tailwind orders
                     .hidden after .flex, so `hidden flex` is still hidden until
                     the class is removed. flex-nowrap + flex-shrink-0 keep all
                     four children on one line, and flex-shrink-0 on the bar
                     itself stops the enclosing justify-between row from
                     squeezing it narrower than its content — the enclosing row
                     already has flex-wrap, so when the search box and the bar
                     together don't fit, the whole bar drops to its own line
                     intact instead of being compressed into two lines. -->
                <div id="caUnsavedBar" class="hidden flex flex-nowrap flex-shrink-0 items-center gap-2 bg-amber-50 border border-amber-300 rounded-lg px-3 py-2 shadow-lg ring-1 ring-black/5">
                    <svg class="w-4 h-4 text-amber-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                    <span id="caUnsavedCount" class="text-xs font-bold text-amber-800 whitespace-nowrap flex-shrink-0">0 unsaved changes</span>
                    <button type="button" id="caCancelBtn" class="text-xs font-semibold text-gray-600 hover:text-gray-800 px-3 py-1.5 rounded-md border border-gray-300 bg-white hover:bg-gray-50 transition whitespace-nowrap flex-shrink-0">Cancel</button>
                    <button type="button" id="caSaveBtn" class="text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 px-4 py-1.5 rounded-md shadow-sm transition whitespace-nowrap flex-shrink-0">Save</button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table id="baselinesTable" class="min-w-full leading-normal w-full text-left">
                    <thead class="bg-white ring-1 ring-gray-200">
                        <tr>
                            <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">SKU &amp; Item Description</th>
                            <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Vendor Name</th>
                            <th class="p-3 text-xs font-bold text-gray-600 uppercase w-72 text-center">Initial Quotation Unit Price</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="content-manual" class="tab-content hidden">
    <div class="bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 p-3 mb-6 text-xs font-semibold rounded">
        These records are entered manually and are not linked to any PR/PO. They are kept in a separate table and never mixed into the Savings Report above.
    </div>

    <div class="mb-6 bg-white p-4 rounded-lg shadow-sm border border-gray-200">
        <h3 class="text-sm font-bold text-gray-700 mb-1">Manual Cost Avoidance (excludes PO-based Cost Avoidance)</h3>
        <p id="manualTotalIdr" class="text-2xl font-bold font-mono text-green-600 mt-2">Rp 0</p>
        <div id="manualCategoryWrap" class="hidden">
        <div class="mt-4 relative">
            <div id="categoryCardsRow" class="flex flex-nowrap gap-2 items-start overflow-hidden">

                <button type="button" onclick="toggleCategoryDropdown()" id="categoryMoreBtn" class="hidden bg-gray-50 border border-gray-200 rounded px-3 py-2 min-w-[140px] text-left hover:bg-gray-100 transition focus:outline-none flex-shrink-0">
                    <div class="text-[10px] font-bold text-indigo-600 uppercase flex items-center gap-1">
                        <span id="categoryMoreLabel">+0 Lainnya</span>
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </div>
                    <div class="text-sm font-bold font-mono text-gray-800" id="categoryMoreAmount">Rp 0</div>
                </button>
            </div>

            <div id="categoryDropdownPanel" class="hidden absolute z-20 mt-1 left-0 bg-white border border-gray-200 rounded-lg shadow-lg py-2 min-w-[240px] max-h-64 overflow-y-auto"></div>
        </div>
        </div>
    </div>

    <?php if (!$is_viewer): ?>
    <div class="mb-6 bg-white shadow-md rounded-lg border border-gray-200">
        <div class="p-6 border-b border-gray-200 bg-gray-50 rounded-t-lg">
            <h3 class="text-lg font-bold text-gray-800 mb-1">Add New Manual Cost Avoidance Entry</h3>
            <p class="text-sm text-gray-600">Record a Cost Avoidance case that has no underlying Purchase Order.</p>
        </div>
        <form method="POST" id="manual-create-form" novalidate class="manual-ca-form p-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
            <input type="hidden" name="action" value="create_manual_ca">

            <div class="flex flex-col">
                <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Transaction Date *</label>
                <input type="date" name="m_entry_date" required class="w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div class="flex flex-col">
                <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Team *</label>
                <select name="m_team" required class="w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white">
                    <option value="" disabled <?= $current_user_team_full ? '' : 'selected' ?>>--</option>
                    <?php foreach ($manual_team_options_scoped as $team_code => $team_full): ?>
                        <option value="<?= htmlspecialchars($team_full) ?>" <?= ($current_user_team_full === $team_full) ? 'selected' : '' ?>><?= htmlspecialchars($team_code) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex flex-col">
                <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Vendor Name *</label>
                <input type="text" id="mc_vendor_name" name="m_vendor_name" required readonly onclick="openVendorSearchCA('mc_vendor_name')" placeholder="Klik untuk cari vendor..." class="w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-gray-50 cursor-pointer">
            </div>

            <div class="flex flex-col sm:col-span-2 md:col-span-3">
                <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Item Description *</label>
                <input type="text" name="m_item_description" required class="w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <div class="flex flex-col">
                <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Quantity *</label>
                <input type="number" step="0.01" name="m_quantity" id="mc_quantity" required value="1" class="w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 manual-calc-input">
            </div>
            <div class="flex flex-col">
                <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">UoM *</label>
                <select name="m_uom" required class="w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white">
                    <option value="" disabled selected>--</option>
                    <?php foreach ($manual_uom_options as $uom_opt): ?>
                        <option value="<?= htmlspecialchars($uom_opt) ?>"><?= htmlspecialchars($uom_opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex flex-col">
                <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Currency *</label>
                <select name="m_currency" required class="w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white">
                    <?php foreach ($ca_ctx['currencies'] as $cur_code => $cur_def): if ($cur_code !== 'IDR' && (float)$cur_def['rate_to_idr'] <= 0) continue; ?>
                        <option value="<?= htmlspecialchars($cur_code) ?>"><?= htmlspecialchars($cur_code) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ca-fx-wrap hidden flex-col sm:col-span-2 md:col-span-3 border border-amber-200 bg-amber-50/60 rounded px-3 py-2">
                <label class="text-[11px] text-gray-700">Exchange rate (IDR per 1 <b class="ca-fx-cur"></b>) &mdash; today's rate is filled in; edit it if these prices were quoted at another rate. It is saved with the entry.</label>
                <div class="flex items-center gap-2 mt-1">
                    <input type="number" step="0.0001" min="0" name="m_fx_rate" class="ca-fx-rate w-44 text-sm border border-gray-300 rounded px-2 py-1.5" placeholder="0">
                    <span class="ca-fx-preview text-xs text-gray-600"></span>
                </div>
            </div>

            <div class="flex flex-col">
                <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Unit Price Before *</label>
                <input type="text" name="m_unit_price_before" id="mc_before" required class="format-currency w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 manual-calc-input">
            </div>
            <div class="flex flex-col">
                <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Final Price *</label>
                <input type="text" name="m_avoided_price" id="mc_avoided" required class="format-currency w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 manual-calc-input">
            </div>
            <div class="flex flex-col">
                <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Total Cost Avoidance *</label>
                <input type="text" name="m_total_cost_avoidance" id="mc_total" readonly class="format-currency w-full text-sm border border-gray-300 rounded px-3 py-2 bg-gray-100 cursor-not-allowed font-bold">
            </div>

            <div class="flex flex-col sm:col-span-2 md:col-span-3">
                <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Remarks / Justification</label>
                <input type="text" name="m_remarks" class="w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <div class="flex flex-col sm:col-span-2 md:col-span-3">
                <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Quotation / Drive Link</label>
                <input type="text" name="m_quotation_link" class="w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="G-Drive Link (Optional)">
            </div>

            <div class="sm:col-span-2 md:col-span-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pt-3 mt-1 border-t border-gray-100">
                <p class="text-[10px] text-gray-400">Total Cost Avoidance is auto-calculated from Unit Price Before / Avoided Price / Quantity when filled; otherwise enter it directly.</p>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold py-2 px-6 rounded shadow-sm transition self-start sm:self-auto">Save Entry</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="bg-white shadow-md rounded-lg border border-gray-200">
        <div class="p-6 border-b border-gray-200 bg-gray-50 rounded-t-lg">
            <h3 class="text-lg font-bold text-gray-800 mb-1">Manual Cost Avoidance Entries</h3>
            <p class="text-sm text-gray-600">All manually recorded Cost Avoidance cases (no underlying PR/PO).</p>
        </div>
        <div class="overflow-x-auto overflow-y-auto max-h-[650px] p-4">
        <table id="manualCaTable" class="min-w-full leading-normal w-full text-left">
            <thead class="bg-white ring-1 ring-gray-200">
                <tr>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase">Date</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase">Team</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase">Item / Vendor</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase">Category</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase text-right">Qty / UoM</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase text-right">Before / Avoided</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase text-right">Total Cost Avoidance</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($manual_entries as $mrow): ?>
                    <?php
                        $m_curr_symbol = ($ca_currency_symbols[strtoupper($mrow['currency'])] ?? 'Rp') . ' ';
                        $m_before_display = $mrow['unit_price_before'] !== null ? $m_curr_symbol . number_format((float)$mrow['unit_price_before'], 2) : '-';
                        $m_avoided_display = $mrow['avoided_price'] !== null ? $m_curr_symbol . number_format((float)$mrow['avoided_price'], 2) : '-';
                        $m_row_id = 'manual-' . $mrow['id'];
                    ?>
                    <tr id="main-<?= $m_row_id ?>" class="border-b hover:bg-indigo-50 transition text-sm cursor-pointer" data-team="<?= htmlspecialchars($mrow['team'] ?? '') ?>" onclick="toggleManualDetail('<?= $m_row_id ?>')" title="Klik untuk lihat remarks & link">
                        <td class="p-3 text-gray-700">
                            <div class="flex items-center gap-2">
                                <svg id="chevron-<?= $m_row_id ?>" class="w-3.5 h-3.5 text-gray-400 flex-shrink-0 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"></path></svg>
                                <span><?= htmlspecialchars(date('d M Y', strtotime($mrow['entry_date']))) ?></span>
                            </div>
                            <!-- Detail content (remarks & drive link) for the expandable child row.
                                 Kept hidden here and injected via DataTables row().child() on toggle,
                                 so it is NOT a sibling <tr> (which would break DataTables' column count). -->
                            <div id="detail-data-<?= $m_row_id ?>" class="hidden">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 px-3 py-2">
                                    <div>
                                        <div class="text-[10px] font-bold text-gray-500 uppercase mb-1">Remarks / Justification</div>
                                        <div class="text-sm text-gray-700 whitespace-pre-line"><?= !empty($mrow['remarks']) ? htmlspecialchars($mrow['remarks']) : '<span class="text-gray-400 italic">Tidak ada remarks</span>' ?></div>
                                    </div>
                                    <div>
                                        <div class="text-[10px] font-bold text-gray-500 uppercase mb-1">Quotation / Drive Link</div>
                                        <?php if (ca_link_href($mrow['quotation_link']) !== ''): ?>
                                            <a href="<?= htmlspecialchars($mrow['quotation_link']) ?>" target="_blank" onclick="event.stopPropagation()" class="text-sm text-blue-600 hover:text-blue-800 hover:underline break-all inline-flex items-center gap-1">
                                                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                                                <span><?= htmlspecialchars($mrow['quotation_link']) ?></span>
                                            </a>
                                        <?php elseif (!empty($mrow['quotation_link'])): ?><span class="text-sm text-gray-700 break-all"><?= htmlspecialchars($mrow['quotation_link']) ?></span><?php else: ?>
                                            <span class="text-sm text-gray-400 italic">Tidak ada link</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="p-3 font-bold text-gray-800"><?= htmlspecialchars($mrow['team'] ?: '-') ?></td>
                        <td class="p-3 text-gray-800">
                            <div class="font-bold truncate max-w-[240px] flex items-center gap-1">
                                <span class="truncate" title="<?= htmlspecialchars($mrow['item_description']) ?>"><?= htmlspecialchars($mrow['item_description']) ?></span>
                                <?php if (ca_link_href($mrow['quotation_link']) !== ''): ?>
                                    <a href="<?= htmlspecialchars($mrow['quotation_link']) ?>" target="_blank" onclick="event.stopPropagation()" class="text-blue-500 hover:text-blue-700 flex-shrink-0" title="View Quotation"><svg class="w-3 h-3 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg></a>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-gray-500"><?= htmlspecialchars($mrow['vendor_name']) ?></div>
                        </td>
                        <td class="p-3 text-gray-600">
                            <?php if ($mrow['category']): ?>
                                <div class="font-semibold text-gray-800 truncate max-w-[140px]" title="<?= htmlspecialchars($mrow['category_level2'] ?: '-') ?>"><?= htmlspecialchars($mrow['category_level2'] ?: '-') ?></div>
                                <div class="text-[10px] text-gray-400 uppercase truncate max-w-[140px]"><?= htmlspecialchars($mrow['category']) ?></div>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td class="p-3 text-right font-mono text-gray-700"><?= number_format((float)$mrow['quantity'], 2) ?> <?= htmlspecialchars($mrow['uom'] ?: '') ?></td>
                        <td class="p-3 text-right font-mono text-xs">
                            <div class="text-gray-400 line-through"><?= $m_before_display ?></div>
                            <div class="text-emerald-600 font-semibold text-sm"><?= $m_avoided_display ?></div>
                        </td>
                        <td class="p-3 text-right font-bold font-mono text-green-600">
                            <?= $m_curr_symbol ?><?= number_format((float)$mrow['total_cost_avoidance'], 2) ?>
                        </td>
                        <td class="p-3 text-center" onclick="event.stopPropagation()">
                            <?php if (!$is_viewer): ?>
                            <div class="flex justify-center space-x-3 items-center" id="action-btns-<?= $m_row_id ?>">
                                <button type="button" onclick="toggleEdit('<?= $m_row_id ?>')" class="text-indigo-600 hover:text-indigo-800 focus:outline-none" title="Edit">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                </button>
                                <form method="POST" onsubmit="return confirm('Delete this manual cost avoidance entry?');" class="inline">
                                    <input type="hidden" name="action" value="delete_manual_ca">
                                    <input type="hidden" name="m_id" value="<?= $mrow['id'] ?>">
                                    <button type="submit" class="text-red-500 hover:text-red-700 focus:outline-none" title="Delete">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                    </button>
                                </form>
                            </div>

                            <form id="edit-form-<?= $m_row_id ?>" method="POST" novalidate data-m-id="<?= (int)$mrow['id'] ?>" data-old-price="<?= htmlspecialchars((string)($mrow['unit_price_before'] ?? '')) ?>" data-old-cur="<?= htmlspecialchars((string)$mrow['currency']) ?>" data-old-rate="<?= htmlspecialchars((string)($mrow['fx_rate'] ?? '')) ?>" onclick="if(event.target===this) toggleEdit('<?= $m_row_id ?>')" class="manual-ca-form hidden fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
                                <input type="hidden" name="action" value="update_manual_ca">
                                <input type="hidden" name="m_id" value="<?= $mrow['id'] ?>">

                                <div class="bg-white w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-lg shadow-xl text-left">
                                    <div class="p-6 border-b border-gray-200 bg-gray-50 rounded-t-lg flex items-start justify-between">
                                        <h3 class="text-lg font-bold text-gray-800">Edit Manual Cost Avoidance Entry</h3>
                                        <button type="button" class="ca-manual-history p-1.5 -mt-1 -mr-2 rounded-full text-gray-400 hover:text-indigo-600 hover:bg-indigo-50" data-m-id="<?= (int)$mrow['id'] ?>" title="Edit history" aria-label="Edit history">
                                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        </button>
                                    </div>

                                    <div class="p-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                                        <div class="flex flex-col">
                                            <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Transaction Date *</label>
                                            <input type="date" name="m_entry_date" value="<?= htmlspecialchars($mrow['entry_date']) ?>" required class="w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                        </div>
                                        <div class="flex flex-col">
                                            <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Team *</label>
                                            <select name="m_team" required class="w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 bg-white">
                                                <option value="" disabled <?= empty($mrow['team']) ? 'selected' : '' ?>>--</option>
                                                <?php foreach ($manual_team_options_scoped as $team_code => $team_full): ?>
                                                    <option value="<?= htmlspecialchars($team_full) ?>" <?= ($mrow['team'] ?? '') === $team_full ? 'selected' : '' ?>><?= htmlspecialchars($team_code) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="flex flex-col">
                                            <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Vendor Name *</label>
                                            <input type="text" id="me_vendor_name_<?= $mrow['id'] ?>" name="m_vendor_name" value="<?= htmlspecialchars($mrow['vendor_name']) ?>" required readonly onclick="openVendorSearchCA('me_vendor_name_<?= $mrow['id'] ?>')" class="w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 bg-gray-50 cursor-pointer">
                                        </div>

                                        <div class="flex flex-col sm:col-span-2 md:col-span-3">
                                            <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Item Description *</label>
                                            <input type="text" name="m_item_description" value="<?= htmlspecialchars($mrow['item_description']) ?>" required class="w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                        </div>

                                        <div class="flex flex-col">
                                            <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Quantity *</label>
                                            <input type="number" step="0.01" name="m_quantity" id="me_quantity_<?= $mrow['id'] ?>" value="<?= htmlspecialchars($mrow['quantity']) ?>" required class="w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 manual-calc-input" data-row="<?= $mrow['id'] ?>">
                                        </div>
                                        <div class="flex flex-col">
                                            <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">UoM *</label>
                                            <select name="m_uom" required class="w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 bg-white">
                                                <option value="" disabled <?= empty($mrow['uom']) ? 'selected' : '' ?>>--</option>
                                                <?php foreach ($manual_uom_options as $uom_opt): ?>
                                                    <option value="<?= htmlspecialchars($uom_opt) ?>" <?= ($mrow['uom'] ?? '') === $uom_opt ? 'selected' : '' ?>><?= htmlspecialchars($uom_opt) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="flex flex-col">
                                            <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Currency *</label>
                                            <select name="m_currency" required class="w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 bg-white">
                                                <?php foreach ($ca_ctx['currencies'] as $cur_code => $cur_def): if ($cur_code !== 'IDR' && (float)$cur_def['rate_to_idr'] <= 0 && $mrow['currency'] !== $cur_code) continue; ?>
                                                    <option value="<?= htmlspecialchars($cur_code) ?>" <?= $mrow['currency'] === $cur_code ? 'selected' : '' ?>><?= htmlspecialchars($cur_code) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="ca-fx-wrap hidden flex-col sm:col-span-2 md:col-span-3 border border-amber-200 bg-amber-50/60 rounded px-3 py-2">
                                            <label class="text-[11px] text-gray-700">Exchange rate (IDR per 1 <b class="ca-fx-cur"></b>) &mdash; the rate this entry was saved at; edit it if needed.</label>
                                            <div class="flex items-center gap-2 mt-1">
                                                <input type="number" step="0.0001" min="0" name="m_fx_rate" value="<?= htmlspecialchars((string)($mrow['fx_rate'] ?? '')) ?>" class="ca-fx-rate w-44 text-sm border border-gray-300 rounded px-2 py-1.5" placeholder="0">
                                                <span class="ca-fx-preview text-xs text-gray-600"></span>
                                            </div>
                                        </div>

                                        <div class="flex flex-col">
                                            <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Unit Price Before *</label>
                                            <input type="text" name="m_unit_price_before" id="me_before_<?= $mrow['id'] ?>" value="<?= htmlspecialchars($mrow['unit_price_before'] ?? '') ?>" required class="format-currency w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 manual-calc-input" data-row="<?= $mrow['id'] ?>">
                                        </div>
                                        <div class="flex flex-col">
                                            <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Final Price *</label>
                                            <input type="text" name="m_avoided_price" id="me_avoided_<?= $mrow['id'] ?>" value="<?= htmlspecialchars($mrow['avoided_price'] ?? '') ?>" required class="format-currency w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 manual-calc-input" data-row="<?= $mrow['id'] ?>">
                                        </div>
                                        <div class="flex flex-col">
                                            <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Total Cost Avoidance</label>
                                            <input type="text" name="m_total_cost_avoidance" id="me_total_<?= $mrow['id'] ?>" value="<?= htmlspecialchars($mrow['total_cost_avoidance']) ?>" readonly class="format-currency w-full text-sm border border-gray-300 rounded px-3 py-2 bg-gray-100 cursor-not-allowed font-bold">
                                        </div>

                                        <div class="flex flex-col sm:col-span-2 md:col-span-3">
                                            <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Remarks / Justification</label>
                                            <input type="text" name="m_remarks" value="<?= htmlspecialchars($mrow['remarks'] ?? '') ?>" class="w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                        </div>

                                        <div class="flex flex-col sm:col-span-2 md:col-span-3">
                                            <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Quotation / Drive Link</label>
                                            <input type="text" name="m_quotation_link" value="<?= htmlspecialchars($mrow['quotation_link'] ?? '') ?>" class="w-full text-sm border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500" placeholder="G-Drive Link (Optional)">
                                        </div>
                                    </div>

                                    <div class="px-6 pb-6 flex items-center justify-end gap-3 border-t border-gray-100 pt-4">
                                        <button type="button" onclick="toggleEdit('<?= $m_row_id ?>')" class="text-gray-600 hover:text-gray-800 text-sm font-bold px-4 py-2 focus:outline-none">Cancel</button>
                                        <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-6 py-2 rounded shadow text-sm transition">Save Changes</button>
                                    </div>
                                </div>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (empty($manual_entries)): ?>
            <div class="text-center py-8 text-gray-500 font-medium">No manual cost avoidance entries yet.</div>
        <?php endif; ?>
        </div>
    </div>
</div>

<!-- Vendor Search Modal (Manual Cost Avoidance) -->
<div id="vendorSearchModalCA" class="fixed inset-0 z-[60] hidden flex items-center justify-center bg-black bg-opacity-50 p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl flex flex-col max-h-[88vh] overflow-hidden">
        <!-- Header -->
        <div class="flex justify-between items-center px-5 py-4 border-b border-gray-100">
            <h3 class="text-base font-bold text-gray-900">Search Vendor Database</h3>
            <button type="button" onclick="closeVendorSearchCA()" class="text-gray-400 hover:text-gray-700 rounded-md w-8 h-8 flex items-center justify-center hover:bg-gray-100 transition">&times;</button>
        </div>

        <!-- Search bar -->
        <div id="vsSearchBarCA" class="px-5 py-4 bg-gray-50 border-b border-gray-100 grid grid-cols-1 md:grid-cols-[1fr_1fr_1fr_auto] gap-3 md:items-end">
            <div class="flex flex-col">
                <label class="text-xs font-semibold text-gray-500 mb-1.5">Company Name</label>
                <input type="text" id="vsNameCA" class="text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none" placeholder="e.g. PT Jaya...">
            </div>
            <div class="flex flex-col">
                <label class="text-xs font-semibold text-gray-500 mb-1.5">Company Type</label>
                <select id="vsTypeCA" class="text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none bg-white">
                    <option value="">All Types</option>
                    <?php foreach ($vss_company_types as $type): ?>
                        <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex flex-col">
                <label class="text-xs font-semibold text-gray-500 mb-1.5">Core Business</label>
                <select id="vsCoreCA" class="text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none bg-white">
                    <option value="">All Core Business</option>
                    <?php foreach ($vss_core_businesses as $core): ?>
                        <option value="<?= htmlspecialchars($core) ?>"><?= htmlspecialchars($core) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" onclick="searchVendorsCA(1)" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 px-5 rounded-lg text-sm transition">Search</button>
        </div>

        <!-- Body -->
        <div class="px-5 py-4 overflow-y-auto flex-1">
            <div id="vsLoadingCA" class="hidden text-sm text-indigo-600 font-semibold mb-3">Searching…</div>

            <!-- Add new vendor -->
            <div id="vsAddNewPanelCA" class="hidden">
                <div class="max-w-xl mx-auto py-6">
                    <label class="text-sm font-semibold text-gray-800 mb-2 block">Nama Vendor Baru</label>
                    <input type="text" id="vsNewVendorNameCA" class="w-full text-sm px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none mb-4" placeholder="Ketik nama vendor...">
                    <div class="flex justify-between items-center">
                        <button type="button" onclick="cancelAddNewVendorCA()" class="text-sm text-gray-500 hover:text-gray-800 font-medium">&larr; Kembali ke Pencarian</button>
                        <button type="button" onclick="confirmNewVendorCA()" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold py-2 px-5 rounded-lg transition">Gunakan Vendor Ini</button>
                    </div>
                </div>
            </div>

            <!-- Results -->
            <div id="vsResultsTableCA" class="overflow-hidden border border-gray-200 rounded-lg">
                <table class="w-full text-left text-sm border-collapse">
                    <thead class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide sticky top-0 z-10">
                        <tr>
                            <th class="px-3 py-2.5 font-semibold border-b border-gray-200">Company Name</th>
                            <th class="px-3 py-2.5 font-semibold border-b border-gray-200">Core Business</th>
                            <th class="px-3 py-2.5 font-semibold border-b border-gray-200">PQ Status</th>
                            <th class="px-3 py-2.5 font-semibold border-b border-gray-200">Risk Status</th>
                            <th class="px-3 py-2.5 font-semibold border-b border-gray-200 w-16 text-center">Pilih</th>
                        </tr>
                    </thead>
                    <tbody id="vsResultsBodyCA">
                        <tr><td colspan="5" class="px-3 py-8 text-center text-gray-400 text-xs">Enter search criteria and click Search.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer -->
        <div class="px-5 py-3.5 border-t border-gray-100 bg-gray-50 flex justify-between items-center gap-3">
            <div id="vsPaginationCA" class="flex gap-2 items-center text-xs text-gray-500"></div>
            <div class="flex items-center gap-2">
                <button type="button" id="vsAddNewBtnCA" onclick="addNewVendorCA()" class="border border-gray-300 bg-white hover:bg-gray-100 text-gray-700 font-semibold py-2 px-4 rounded-lg text-sm transition">+ Vendor Baru</button>
                <button type="button" onclick="closeVendorSearchCA()" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold py-2 px-4 rounded-lg text-sm transition">Cancel</button>
            </div>
        </div>
    </div>
</div>

<?php if ($_SESSION['role'] === 'Category Manager'): ?>
<div id="content-tax" class="tab-content hidden grid grid-cols-1 md:grid-cols-2 gap-6">
    
    <div class="space-y-6">
        <!-- Target Settings Card -->
        <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200">
            <h3 class="text-lg font-bold text-gray-800 mb-2">Team Target Avoidance (%)</h3>
            <p class="text-xs text-gray-600 mb-4">Set the KPI target percentage for each team's cost avoidance metric.</p>
            
            <form method="POST" class="bg-gray-50 p-4 rounded border border-gray-200">
                <input type="hidden" name="action" value="update_targets">
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">CSGSM Target (%)</label>
                        <input type="number" step="0.01" name="target_csgsm" class="w-full p-2 border rounded text-sm" value="<?= $team_targets['CSGSM'] ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">TIGS Target (%)</label>
                        <input type="number" step="0.01" name="target_tigs" class="w-full p-2 border rounded text-sm" value="<?= $team_targets['TIGS'] ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">FNBOM Target (%)</label>
                        <input type="number" step="0.01" name="target_fnbom" class="w-full p-2 border rounded text-sm" value="<?= $team_targets['FNBOM'] ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">ADMIN Target (%)</label>
                        <input type="number" step="0.01" name="target_admin" class="w-full p-2 border rounded text-sm" value="<?= $team_targets['ADMIN'] ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">PKR Target (%)</label>
                        <input type="number" step="0.01" name="target_pkr" class="w-full p-2 border rounded text-sm" value="<?= $team_targets['PKR'] ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">PKP Target (%)</label>
                        <input type="number" step="0.01" name="target_pkp" class="w-full p-2 border rounded text-sm" value="<?= $team_targets['PKP'] ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">MFM Target (%)</label>
                        <input type="number" step="0.01" name="target_mfm" class="w-full p-2 border rounded text-sm" value="<?= $team_targets['MFM'] ?>">
                    </div>
                </div>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded text-sm transition w-full">Save Targets</button>
            </form>
        </div>

        <!-- CSV Baseline Importer Card -->
        <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200">
            <h3 class="text-lg font-bold text-gray-800 mb-2">Migrate Baselines / Import Quotations</h3>
            <p class="text-xs text-gray-600 mb-4">Upload a CSV file containing <strong>Ticket Number</strong>, <strong>Ticket Line</strong>, and <strong>Initial Quotation Price</strong> to batch-update baseline prices.</p>
            
            <form method="POST" enctype="multipart/form-data" class="bg-gray-50 p-4 rounded border border-gray-200">
                <input type="hidden" name="action" value="import_baselines">
                
                <div class="mb-4">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Select CSV File</label>
                    <input type="file" name="baseline_csv" accept=".csv" required class="w-full text-xs bg-white border border-gray-300 p-2 rounded focus:outline-none focus:ring-1 focus:ring-indigo-500">
                </div>
                
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded text-sm transition w-full">
                    Import & Match Baselines
                </button>
            </form>
        </div>
    </div>

    <div class="space-y-6">
        <!-- Google API Configuration Card -->
        <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200">
            <h3 class="text-lg font-bold text-gray-800 mb-2">Google Drive Integration</h3>
            <p class="text-xs text-gray-600 mb-4">Input your Google Cloud Client ID and API Key to enable the native Google Drive Picker popup for quotations.</p>
            
            <form method="POST" class="bg-gray-50 p-4 rounded border border-gray-200">
                <input type="hidden" name="action" value="update_google_api">
                <div class="mb-3">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Google Client ID</label>
                    <input type="text" name="client_id" class="w-full p-2 border rounded text-xs font-mono" placeholder="1234567890-abcde.apps.googleusercontent.com" value="<?= htmlspecialchars($google_client_id) ?>">
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Google API Key (Developer Key)</label>
                    <input type="text" name="api_key" class="w-full p-2 border rounded text-xs font-mono" placeholder="AIzaSy..." value="<?= htmlspecialchars($google_api_key) ?>">
                </div>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded text-sm transition w-full">Save Google Credentials</button>
            </form>
        </div>
        
        <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200">
            <h3 class="text-lg font-bold text-gray-800 mb-2">Currency Settings</h3>
            <p class="text-xs text-gray-600 mb-4">Set the exchange rate to Rupiah for each currency used when a baseline or PO is entered in a non-IDR currency. Inactive currencies are hidden from every baseline/PO/manual entry dropdown until activated here with a real rate.</p>

            <form method="POST">
                <input type="hidden" name="action" value="update_currencies">
                <table class="min-w-full border rounded mb-2 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="p-2 text-left font-bold text-gray-600">Code</th>
                            <th class="p-2 text-left font-bold text-gray-600">Name</th>
                            <th class="p-2 text-left font-bold text-gray-600">Rate to IDR (Rp)</th>
                            <th class="p-2 text-center font-bold text-gray-600">Active</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($all_currencies as $cur): if ($cur['code'] === 'IDR') continue; ?>
                        <tr class="border-t">
                            <td class="p-2 font-mono font-bold"><?= htmlspecialchars($cur['code']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($cur['name']) ?></td>
                            <td class="p-2">
                                <input type="text" inputmode="decimal" name="rate_to_idr[<?= htmlspecialchars($cur['code']) ?>]" required class="format-currency w-32 p-1 border rounded text-sm font-mono" value="<?= htmlspecialchars(rtrim(rtrim(number_format((float)$cur['rate_to_idr'], 4, '.', ''), '0'), '.')) ?>">
                            </td>
                            <td class="p-2 text-center">
                                <input type="checkbox" name="is_active[<?= htmlspecialchars($cur['code']) ?>]" value="1" <?= $cur['is_active'] ? 'checked' : '' ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded text-sm transition">Save Currency Settings</button>
            </form>
            <p class="text-xs text-gray-400 mt-2">Activating a currency with rate 0 keeps it hidden from dropdowns — enter a real rate first.</p>
        </div>

        <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200">
            <h3 class="text-lg font-bold text-gray-800 mb-2">PPN Inclusion Rules</h3>
            <p class="text-xs text-gray-600 mb-6">If a PO belongs to a P.Org starting with these prefixes, the system will divide the PO Amount by 1.11 to find the exact Nett Price before calculating cost avoidance.</p>
            
            <table class="min-w-full border rounded mb-6 text-sm">
                <thead class="bg-gray-50"><th class="p-2 text-left font-bold text-gray-600">P.Org Prefix</th><th class="p-2 text-right font-bold text-gray-600">Action</th></thead>
                <tbody>
                    <?php foreach($tax_rules as $rule): ?>
                    <tr class="border-b">
                        <td class="p-2 font-mono font-bold text-indigo-700"><?= htmlspecialchars($rule['p_org_prefix']) ?></td>
                        <td class="p-2 text-right">
                            <form method="POST" onsubmit="return confirm('Delete this tax rule?');">
                                <input type="hidden" name="delete_tax_rule" value="1">
                                <input type="hidden" name="prefix_to_delete" value="<?= htmlspecialchars($rule['p_org_prefix']) ?>">
                                <button type="submit" class="text-red-500 hover:text-red-700 font-bold text-xs">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <form method="POST" class="bg-gray-50 p-4 rounded border border-gray-200">
                <input type="hidden" name="save_tax_rule" value="1">
                <label class="block text-xs font-bold text-gray-700 mb-1">Add New Prefix (e.g. "DF")</label>
                <div class="flex gap-2">
                    <input type="text" name="new_porg_prefix" required class="flex-grow p-2 border rounded text-sm uppercase" placeholder="Prefix...">
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded text-sm transition">Add Rule</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Shared modal shell (caModalOpen()/caModalClose() below) — one generic
     dialog reused for the Input Baselines taxonomy-required error, the
     confirm-before-save prompt, the server-side batch partial-failure
     report, and the in-app tab-switch-with-pending-changes warning. Styled
     to match #vendorSearchModalCA above rather than introducing a new
     dialog look. Sits at the top level (not inside any .tab-content) so it
     stays usable regardless of which tab is active. -->
<div id="caModal" class="fixed inset-0 z-[70] hidden items-center justify-center bg-black bg-opacity-50 p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md flex flex-col overflow-hidden">
        <div class="flex justify-between items-center px-5 py-4 border-b border-gray-100">
            <h3 id="caModalTitle" class="text-base font-bold text-gray-900"></h3>
            <button type="button" onclick="caModalClose()" class="text-gray-400 hover:text-gray-700 rounded-md w-8 h-8 flex items-center justify-center hover:bg-gray-100 transition">&times;</button>
        </div>
        <div id="caModalBody" class="px-5 py-4 text-sm text-gray-700 max-h-[60vh] overflow-y-auto"></div>
        <div id="caModalFooter" class="px-5 py-4 border-t border-gray-100 flex justify-end gap-2"></div>
    </div>
</div>

<script>
    // Whether the Google Drive picker button can be offered on the inline edit
    // form (it is only wired up when both API credentials are configured).
    var caGooglePicker = <?= json_encode((bool)($google_client_id && $google_api_key)) ?>;

    // Active currency codes + symbols (Settings tab), replacing every
    // hardcoded IDR/USD <option> pair below. IDR is always included.
    var CA_CURRENCIES = <?= json_encode(array_keys($ca_ctx['currencies'])) ?>;
    var CA_CURRENCY_SYMBOLS = <?= json_encode($ca_currency_symbols, JSON_UNESCAPED_UNICODE) ?>;
    // Builds the <option> list for a currency <select>, selecting `cur` if given.
    function caCurrencyOptionsHtml(cur) {
        // Currencies without a usable rate (active but rate 0) can't be picked --
        // unless it is the one already saved on the row.
        var usable = CA_CURRENCIES.filter(function (code) {
            return code === 'IDR' || code === cur || typeof caFxRates === 'undefined' || caFxRates[code] > 0;
        });
        return usable.map(function (code) {
            return '<option value="' + code + '"' + (cur === code ? ' selected' : '') + '>' + code + '</option>';
        }).join('');
    }

    // Level 2 ("type of work") options per Project team code, keyed the same
    // way d.team arrives from the group list — {} for every non-Project team,
    // so caBaselineItemCell() can just check `CA_PROJECT_LEVEL2_OPTIONS[d.team]`
    // to decide whether to render the taxonomy dropdown at all.
    var CA_PROJECT_LEVEL2_OPTIONS = <?= json_encode($ca_project_level2_options, JSON_UNESCAPED_UNICODE) ?>;
    // label -> code per team (CA_PROJECT_TAXONOMY), shown as "J1 · Civil-Architecture".
    var CA_PROJECT_TAXONOMY_CODES = <?= json_encode(array_map('array_flip', CA_PROJECT_TAXONOMY), JSON_UNESCAPED_UNICODE) ?>;

    // Set only right after a batch_save_baselines redirect that had at least
    // one per-item failure (a race condition — something changed between
    // page load and Save). {applied: int, failed: [{item, vendor, reason, action}]}
    // or null. Rendered through caModalOpen() on document.ready below.
    var caBatchFailures = <?php
        echo $_SESSION['ca_batch_failures'] ?? 'null';
        unset($_SESSION['ca_batch_failures']);
    ?>;

    // The active Global Date Filter, sent with every Savings Report / chart
    // request so the server applies the same window the page was opened with.
    var caFilters = {
        g_filter: <?= json_encode($g_filter) ?>,
        g_from:   <?= json_encode($g_from) ?>,
        g_to:     <?= json_encode($g_to) ?>,
        team:     ''
    };

    // The Input Baselines tab's own canvassing-date filter, narrowing that tab on
    // top of caFilters. Same shape so both go through ca_normalize_filters().
    var caBFilters = {
        g_filter: <?= json_encode($b_filter) ?>,
        g_from:   <?= json_encode($b_from) ?>,
        g_to:     <?= json_encode($b_to) ?>,
        team:     ''
    };

    // --- Formatting helpers -------------------------------------------------
    function caEsc(s) {
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // PHP number_format($v, 0, ',', '.')  =>  "1.234.567"
    function caInt(v) {
        var n = Number(v) || 0;
        var neg = n < 0;
        n = Math.round(Math.abs(n)); // half-up away from zero, like PHP
        return (neg ? '-' : '') + String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }
    // PHP number_format($v, 2)  =>  "1,234.56"
    function caDec2(v) {
        var n = Number(v) || 0;
        var neg = n < 0;
        var parts = Math.abs(n).toFixed(2).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return (neg ? '-' : '') + parts.join('.');
    }
    // Symbol looked up from CA_CURRENCY_SYMBOLS; IDR (and any unknown/inactive
    // code) keeps the old integer-formatted "Rp" display, every other active
    // currency uses 2-decimal formatting.
    function caMoney(v, curr) {
        var symbol = CA_CURRENCY_SYMBOLS[curr] || 'Rp';
        return (curr && curr !== 'IDR' && CA_CURRENCY_SYMBOLS[curr]) ? symbol + ' ' + caDec2(v) : symbol + ' ' + caInt(v);
    }
    // DOM id for a report row. The server-rendered markup used md5(ticket.line);
    // any stable unique string works, and this one stays readable in devtools.
    // The "rep-" prefix keeps it clear of the Manual table's "manual-<id>" ids,
    // which share the same toggleEdit() handler.
    function caRowId(d) {
        return 'rep-' + String(d.ticket + '-' + d.line).replace(/[^A-Za-z0-9_-]/g, '_');
    }

    function caShowError(msg) { $('#caAjaxError').text(msg).removeClass('hidden'); }
    function caHideError() { $('#caAjaxError').addClass('hidden'); }

    // PHP date('d M Y') — the format the Canvassing Date column used.
    var CA_MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    function caDate(iso) {
        if (!iso) return '-';
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso));
        if (!m) return '-';
        return m[3] + ' ' + CA_MONTHS[parseInt(m[2], 10) - 1] + ' ' + m[1];
    }

    // A Google Drive picker button for the given input id, or '' when the Drive
    // credentials are not configured.
    function caPickerBtn(inputId, cls, svgCls) {
        if (!caGooglePicker) return '';
        return '<button type="button" onclick="createPicker(\'' + inputId + '\')" class="' + cls + '" title="Select from Google Drive">' +
               '<svg class="' + svgCls + ' text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"></path></svg></button>';
    }

    // --- Input Baselines renderers -----------------------------------------
    function caGroupId(d) {
        var s = String(d.item) + '|' + String(d.vendor) + '|' + String(d.uom || '') + '|' + String(d.team);
        var h = 5381;
        for (var i = 0; i < s.length; i++) { h = ((h << 5) + h + s.charCodeAt(i)) | 0; }
        return 'grp' + (h >>> 0).toString(36);
    }

    function caIsProjectTeam(team) {
        var opts = CA_PROJECT_LEVEL2_OPTIONS[team];
        return !!(opts && opts.length);
    }

    // --- Staged changes / batch save ----------------------------------------
    //
    // Every write control on this tab (taxonomy dropdown, Batch Fix PO Curr,
    // Apply All, and the per-PO currency select + Save inside an expanded
    // group) used to be its own <form> posting immediately and reloading the
    // whole page. None of them submit anymore — each only stages an entry
    // here, keyed by a string unique per (control type, group-or-PO-line), so
    // re-editing the same field replaces its entry instead of accumulating
    // duplicates. Nothing reaches the server until Save, which submits every
    // staged entry in one POST (action=batch_save_baselines) — the only point
    // that still reloads the page. "X unsaved changes" is always
    // Object.keys(caPendingChanges).length.
    //
    // caGroupInfo / caDetailGroupInfo cache each group's/PO-line's identity
    // (team, saved taxonomy, item/vendor label) every time it's rendered, so
    // the pre-Save validation below can check a staged change against its
    // owning group's taxonomy even if that group has since scrolled off the
    // current DataTables server-side page (its row data would otherwise be
    // gone from the browser entirely).
    var caPendingChanges = {};
    var caGroupInfo = {};
    var caDetailGroupInfo = {};
    // Set right before Save's own hidden form submits, so the beforeunload
    // guard below doesn't warn about the very navigation the user just asked
    // for by clicking Save.
    var caNavigatingOnPurpose = false;

    function caPendingCount() { return Object.keys(caPendingChanges).length; }

    function caUpdateUnsavedBar() {
        var n = caPendingCount();
        if (n > 0) {
            $('#caUnsavedBar').removeClass('hidden');
            $('#caUnsavedCount').text(n + ' unsaved changes');
        } else {
            $('#caUnsavedBar').addClass('hidden');
            $('#caSaveError').addClass('hidden').empty();
        }
    }

    function caStage(key, change) {
        caPendingChanges[key] = change;
        caUpdateUnsavedBar();
    }
    function caUnstage(key) {
        delete caPendingChanges[key];
        caUpdateUnsavedBar();
    }
    function caMarkPending(el, isPending) {
        if (el) $(el).toggleClass('ca-pending-field', !!isPending);
    }
    function caMarkError(el, isError) {
        if (el) $(el).toggleClass('ca-error-field', !!isError);
    }

    // The 4-column group identity (item+vendor+uom+team), read from a group-
    // level form's own hidden inputs — same fields caGroupId() hashes.
    function caGroupKeyFromForm(form) {
        // form.elements['item'] is NOT safe: HTMLFormControlsCollection has its
        // own native .item() method, so bracket access with the string "item"
        // resolves to that function instead of the hidden <input name="item">
        // — namedItem() is the collision-safe lookup for exactly this name.
        var itemEl = form.elements.namedItem('item');
        return {
            item:   itemEl ? itemEl.value : '',
            vendor: form.elements['vendor'] ? form.elements['vendor'].value : '',
            uom:    form.elements['uom']     ? form.elements['uom'].value    : '',
            team:   form.elements['team']   ? form.elements['team'].value   : ''
        };
    }

    // --- TAXONOMY dropdown ---------------------------------------------------
    function caStageTaxonomy(selectEl) {
        var g = caGroupKeyFromForm(selectEl.form);
        var key = 'taxonomy:' + caGroupId(g);
        var original = selectEl.dataset.original || '';
        var value = selectEl.value;

        caMarkError(selectEl, false);
        if (value === original) {
            caUnstage(key);
            caMarkPending(selectEl, false);
            return;
        }
        caStage(key, { action: 'save_ca_taxonomy', item: g.item, vendor: g.vendor, uom: g.uom, team: g.team, ca_taxonomy_level_2: value });
        caMarkPending(selectEl, true);
    }

    // --- Batch Fix PO Curr (group header) ------------------------------------
    function caStagePoCurrencyHeader(selectEl) {
        var g = caGroupKeyFromForm(selectEl.form);
        var key = 'poCurHeader:' + caGroupId(g);
        var value = selectEl.value;

        if (!value) {
            caUnstage(key);
            caMarkPending(selectEl, false);
            return;
        }
        caStage(key, { action: 'update_po_currency_header', item: g.item, vendor: g.vendor, uom: g.uom, po_currency: value });
        caMarkPending(selectEl, true);
    }

    // --- Apply All (save_header) ---------------------------------------------
    function caStageApplyAll(form) {
        var g = caGroupKeyFromForm(form);
        var key = 'applyAll:' + caGroupId(g);
        var baseline = (form.elements['baseline'].value || '').trim();
        var fields = [form.elements['currency'], form.elements['baseline'], form.elements['quotation_link']];

        if (baseline === '') {
            caUnstage(key);
            fields.forEach(function (f) { caMarkPending(f, false); });
            return false;
        }
        caStage(key, {
            action: 'save_header', item: g.item, vendor: g.vendor, uom: g.uom, team: g.team,
            currency: form.elements['currency'].value, baseline: baseline,
            quotation_link: form.elements['quotation_link'].value || ''
        });
        fields.forEach(function (f) { caMarkPending(f, true); });
        return false; // this form only ever stages now — never actually submits
    }

    // --- Per-PO-line currency select (inside an expanded group) --------------
    function caStagePoCurrencyDetail(selectEl) {
        var form = selectEl.form;
        var ticket = form.elements['ticket_no'].value;
        var line = form.elements['ticket_line'].value;
        var key = 'poCurDetail:' + ticket + '|' + line;
        var original = selectEl.dataset.original || '';
        var value = selectEl.value;

        if (value === original) {
            caUnstage(key);
            caMarkPending(selectEl, false);
            return;
        }
        // _item/_vendor are informational only (from caDetailGroupInfo,
        // populated by caBaselineDetailHtml() on render) — the server never
        // reads them for the write itself, only for a readable label if this
        // change ends up in a server-side batch failure report.
        var info = caDetailGroupInfo[ticket + '|' + line];
        caStage(key, {
            action: 'update_po_currency_detail', ticket_no: ticket, ticket_line: line, po_currency: value,
            _item: info ? info.item : '', _vendor: info ? info.vendor : ''
        });
        caMarkPending(selectEl, true);
    }

    // --- Per-PO-line currency + price + G-Drive link + Save ------------------
    function caStageSaveDetail(form) {
        var ticket = form.elements['ticket_no'].value;
        var line = form.elements['ticket_line'].value;
        var key = 'saveDetail:' + ticket + '|' + line;
        var baseline = (form.elements['baseline'].value || '').trim();
        var fields = [form.elements['currency'], form.elements['baseline'], form.elements['quotation_link']];

        if (baseline === '') {
            caUnstage(key);
            fields.forEach(function (f) { caMarkPending(f, false); });
            return false;
        }
        // po_currency here always carries this PO line's current-or-pending
        // value — never the form's own stale hidden field — so a pending
        // update_po_currency_detail change for the same line is never
        // silently reverted by this save going out alongside it.
        var poCurKey = 'poCurDetail:' + ticket + '|' + line;
        var poCurrency = caPendingChanges[poCurKey] ? caPendingChanges[poCurKey].po_currency : form.elements['po_currency'].value;
        var info = caDetailGroupInfo[ticket + '|' + line];

        caStage(key, {
            action: 'save_detail', ticket_no: ticket, ticket_line: line, po_currency: poCurrency,
            currency: form.elements['currency'].value, baseline: baseline,
            quotation_link: form.elements['quotation_link'].value || '',
            _item: info ? info.item : '', _vendor: info ? info.vendor : ''
        });
        fields.forEach(function (f) { caMarkPending(f, true); });
        return false;
    }

    // For Project groups (team in CA_PROJECT_LEVEL2_OPTIONS with a non-empty
    // list), a required TAXONOMY dropdown — its own small form/action
    // (save_ca_taxonomy), independent of the baseline price "Apply All" form
    // below, matching the group's server-resolved caTaxonomyLevel2 (MAX()
    // across the group's PO lines, same precedent as the SKU column) as its
    // current selection, or the staged value if one is pending.
    function caTaxonomyCell(d) {
        var opts = CA_PROJECT_LEVEL2_OPTIONS[d.team];
        if (!opts || !opts.length) return '';
        var groupId = caGroupId(d);
        var saved = d.caTaxonomyLevel2 || '';
        var pending = caPendingChanges['taxonomy:' + groupId];
        var current = pending ? pending.ca_taxonomy_level_2 : saved;

        var optionsHtml = '<option value="" disabled' + (current ? '' : ' selected') + '>Select taxonomy</option>';
        // A value saved under the old list stays visible (not re-selectable)
        // until someone picks a current one.
        if (current && opts.indexOf(current) === -1) {
            optionsHtml += '<option value="" disabled selected>' + caEsc(current) + ' (old list)</option>';
        }
        for (var i = 0; i < opts.length; i++) {
            var code = (CA_PROJECT_TAXONOMY_CODES[d.team] || {})[opts[i]];
            optionsHtml += '<option value="' + caEsc(opts[i]) + '"' + (opts[i] === current ? ' selected' : '') + '>' + caEsc((code ? code + ' · ' : '') + opts[i]) + '</option>';
        }
        return '' +
        '<form method="POST" class="flex items-center gap-1 mt-2 bg-white p-1 border rounded shadow-sm" onsubmit="return false;">' +
            '<input type="hidden" name="action" value="save_ca_taxonomy">' +
            '<input type="hidden" name="item" value="' + caEsc(d.item) + '">' +
            '<input type="hidden" name="vendor" value="' + caEsc(d.vendor) + '">' +
            '<input type="hidden" name="uom" value="' + caEsc(d.uom || '') + '">' +
            '<input type="hidden" name="team" value="' + caEsc(d.team) + '">' +
            '<span class="text-xs font-extrabold text-gray-700 uppercase tracking-wide">Taxonomy:</span>' +
            '<select name="ca_taxonomy_level_2" required data-original="' + caEsc(saved) + '" data-group-id="' + groupId + '" data-role="taxonomy" ' +
                'class="text-xs font-bold p-1 border-2 rounded cursor-pointer flex-1' + (pending ? ' ca-pending-field' : '') + '" onchange="caStageTaxonomy(this)">' +
                optionsHtml +
            '</select>' +
        '</form>';
    }

    function caBaselineItemCell(d) {
        var sku = d.sku ? '<span class="text-indigo-600 font-bold">[' + caEsc(d.sku) + '] </span>' : '';
        var groupId = caGroupId(d);
        // Registered on every render (Project team or not) so a validation
        // check run after this group has scrolled off-page can still find its
        // saved taxonomy state and label.
        caGroupInfo[groupId] = { team: d.team, caTaxonomyLevel2: d.caTaxonomyLevel2 || '', item: d.item, vendor: d.vendor };

        var poCurPending = caPendingChanges['poCurHeader:' + groupId];
        return '' +
        '<div class="flex justify-between items-start gap-2">' +
            // Same truncate-with-tooltip treatment as the Savings Report table's
            // item cell (caReportTable's SKU/Item column, ~line 2359) — long
            // item descriptions (e.g. "Pengadaan dan Pemasangan Pole Sign
            // Lightbox Ukuran = 240 cm x 240 cm...") were the single biggest
            // contributor to this row overflowing the viewport at 100% zoom.
            '<div class="font-medium text-sm text-gray-800 truncate max-w-[240px]" title="' + caEsc(d.item) + '">' + sku + caEsc(d.item) + '</div>' +
            '<div class="flex items-center space-x-2 flex-shrink-0">' +
                '<span class="text-xs font-bold text-indigo-700 bg-indigo-50 px-2 py-1 rounded border border-indigo-100">' + caEsc(d.team) + '</span>' +
                '<span class="text-xs font-bold text-gray-500 bg-gray-200 px-2 py-1 rounded whitespace-nowrap">UoM: ' + caEsc(d.uom || 'N/A') + '</span>' +
            '</div>' +
        '</div>' +
        '<div class="flex items-center gap-4 mt-2">' +
            '<div class="baseline-toggle text-xs text-blue-600 font-semibold cursor-pointer flex items-center hover:text-blue-800 transition">' +
                '<svg class="baseline-toggle-icon w-4 h-4 mr-1 transform transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>' +
                'View ' + d.poCount + ' Related PO(s)' +
            '</div>' +
            '<form method="POST" class="flex items-center gap-1 bg-white p-1 border rounded shadow-sm" onsubmit="return false;">' +
                '<input type="hidden" name="action" value="update_po_currency_header">' +
                '<input type="hidden" name="item" value="' + caEsc(d.item) + '">' +
                '<input type="hidden" name="vendor" value="' + caEsc(d.vendor) + '">' +
                '<input type="hidden" name="uom" value="' + caEsc(d.uom || '') + '">' +
                '<input type="hidden" name="team" value="' + caEsc(d.team) + '">' +
                '<span class="text-[10px] font-bold text-gray-500 uppercase">Batch Fix PO Curr:</span>' +
                '<select name="po_currency" class="text-[10px] p-0.5 border rounded cursor-pointer' + (poCurPending ? ' ca-pending-field' : '') + '" onchange="caStagePoCurrencyHeader(this)">' +
                    '<option value="" disabled' + (poCurPending ? '' : ' selected') + '>Select</option>' +
                    caCurrencyOptionsHtml(poCurPending ? poCurPending.po_currency : null) +
                '</select>' +
            '</form>' +
        '</div>' +
        caTaxonomyCell(d);
    }

    // No submit button — typing/selecting any field here stages an "apply to
    // every related PO in this group" pending change directly (same pattern
    // the TAXONOMY dropdown already used), exactly like caStageApplyAll()
    // already did on every keystroke even when the button still existed.
    function caBaselineApplyAllCell(d) {
        var id = caGroupId(d);
        var pending = caPendingChanges['applyAll:' + id];
        var currency = pending ? pending.currency : 'IDR';
        var baseline = pending ? formatThousands(pending.baseline) : '';
        var link = pending ? pending.quotation_link : '';
        var pendingCls = pending ? ' ca-pending-field' : '';
        // Two explicit equal-width rows (currency+price, then G-Drive link)
        // rather than `flex-wrap` + `max-w-[280px]`, which is what left the grey
        // box visibly wider than its controls. `w-fit` (width:fit-content) is
        // resolved against the container's *unwrapped* max-content — all four
        // controls on one line, ~408px — so it clamped to the 280px max-width
        // and stayed there even though flex-wrap then laid the controls out in
        // rows only ~196px wide. justify-end right-aligned those short rows
        // inside the 280px box, which is the dead grey space on the left. No CSS
        // width keyword means "shrink to the post-wrap width", so the wrap is
        // gone: flex-col with both rows pinned to the same w-[196px] makes
        // fit-content exactly 196px, and the grey background hugs the controls.
        // Keeping the controls off one line still matters for the horizontal
        // overflow at 100% zoom that the wrap was originally introduced to fix.
        return '' +
        '<form method="POST" class="flex flex-col gap-1 items-end bg-gray-100 p-2 rounded border border-gray-200 shadow-sm w-fit max-w-full ml-auto" onsubmit="return caStageApplyAll(this);">' +
            '<input type="hidden" name="action" value="save_header">' +
            '<input type="hidden" name="item" value="' + caEsc(d.item) + '">' +
            '<input type="hidden" name="vendor" value="' + caEsc(d.vendor) + '">' +
            '<input type="hidden" name="uom" value="' + caEsc(d.uom || '') + '">' +
            '<input type="hidden" name="team" value="' + caEsc(d.team) + '">' +
            '<div class="flex gap-1 h-9 w-[196px]">' +
                '<select name="currency" class="p-2 border border-gray-300 rounded text-sm focus:ring-indigo-500 bg-white h-9 flex-shrink-0' + pendingCls + '" onchange="caStageApplyAll(this.form)">' +
                    caCurrencyOptionsHtml(currency) +
                '</select>' +
                '<input type="text" name="baseline" value="' + caEsc(baseline) + '" class="format-currency flex-1 min-w-0 p-2 border border-gray-300 rounded text-sm focus:ring-indigo-500 h-9' + pendingCls + '" placeholder="Unit Price" oninput="caStageApplyAll(this.form)">' +
            '</div>' +
            '<div class="flex gap-1 h-9 w-[196px]">' +
                '<input type="text" id="link_hdr_' + id + '" name="quotation_link" value="' + caEsc(link) + '" class="flex-1 min-w-0 p-2 border border-gray-300 rounded text-sm focus:ring-indigo-500 h-9' + pendingCls + '" placeholder="G-Drive Link (Optional)" oninput="caStageApplyAll(this.form)">' +
                caPickerBtn('link_hdr_' + id, 'bg-gray-200 hover:bg-gray-300 px-2 rounded h-9 flex items-center justify-center flex-shrink-0', 'w-4 h-4') +
            '</div>' +
        '</form>';
    }

    // The expanded child row: one line per PO behind the group, each with its own
    // currency fix and specific-baseline form. Re-rendered fresh from cached raw
    // rows every time the group is (re-)expanded (see the .baseline-toggle
    // handler below) rather than reusing a cached HTML string, so a staged
    // change made before a collapse/re-expand still shows on re-expand.
    function caBaselineDetailHtml(d, rows) {
        var groupId = caGroupId(d);
        var body = '';
        for (var i = 0; i < rows.length; i++) {
            var po = rows[i];
            var detailKey = po.ticket + '|' + po.line;
            caDetailGroupInfo[detailKey] = { groupId: groupId, team: d.team, item: d.item, vendor: d.vendor };

            var lid = 'link_dtl_' + String(po.ticket + '_' + po.line).replace(/[^A-Za-z0-9_-]/g, '_');
            var ppn = po.inclusive
                ? '<span class="text-[9px] bg-yellow-100 text-yellow-800 px-1 py-0.5 rounded font-bold mt-1 inline-block">-PPN</span>' : '';

            var poCurPending = caPendingChanges['poCurDetail:' + detailKey];
            var poCurCurrent = poCurPending ? poCurPending.po_currency : po.poCurrency;

            var savePending = caPendingChanges['saveDetail:' + detailKey];
            var saveCurrency = savePending ? savePending.currency : 'IDR';
            var saveBaseline = savePending ? formatThousands(savePending.baseline) : '';
            var saveLink = savePending ? savePending.quotation_link : '';
            var savePendingCls = savePending ? ' ca-pending-field' : '';

            body +=
            '<tr class="border-b last:border-b-0 hover:bg-gray-50 transition">' +
                '<td class="p-2 pl-4 font-bold text-gray-800">' + caEsc(po.po) + '</td>' +
                '<td class="p-2 text-gray-600">' + caDate(po.canvassing) + '</td>' +
                '<td class="p-2 text-right font-mono font-semibold text-gray-700">' + caDec2(po.qty) + '</td>' +
                '<td class="p-2 text-right font-mono font-semibold text-gray-700">' +
                    '<div class="flex items-center justify-end gap-1">' +
                        '<span>' + caMoney(po.poAmount, po.poCurrency) + '</span>' +
                        '<form method="POST" class="inline m-0 p-0" onsubmit="return false;">' +
                            '<input type="hidden" name="action" value="update_po_currency_detail">' +
                            '<input type="hidden" name="ticket_no" value="' + caEsc(po.ticket) + '">' +
                            '<input type="hidden" name="ticket_line" value="' + caEsc(po.line) + '">' +
                            '<select name="po_currency" data-original="' + caEsc(po.poCurrency) + '" class="text-[9px] p-0 border rounded cursor-pointer bg-gray-50' + (poCurPending ? ' ca-pending-field' : '') + '" onchange="caStagePoCurrencyDetail(this)">' +
                                caCurrencyOptionsHtml(poCurCurrent) +
                            '</select>' +
                        '</form>' +
                    '</div>' + ppn +
                '</td>' +
                '<td class="p-2 text-right font-mono font-bold text-indigo-700">Rp ' + caInt(po.nettUnit) + '</td>' +
                '<td class="p-2 pr-4 text-right">' +
                    // Same two-equal-rows structure (and for the same reason) as
                    // caBaselineApplyAllCell() above — see the comment there.
                    '<form method="POST" class="flex flex-col gap-1 items-end bg-gray-100 p-1 rounded border shadow-sm w-fit max-w-full ml-auto" onsubmit="return caStageSaveDetail(this);">' +
                        '<input type="hidden" name="action" value="save_detail">' +
                        '<input type="hidden" name="ticket_no" value="' + caEsc(po.ticket) + '">' +
                        '<input type="hidden" name="ticket_line" value="' + caEsc(po.line) + '">' +
                        '<input type="hidden" name="po_currency" value="' + caEsc(po.poCurrency) + '">' +
                        '<div class="flex gap-1 w-[160px]">' +
                            '<select name="currency" class="p-1 text-xs border border-gray-300 rounded focus:ring-emerald-500 bg-white flex-shrink-0' + savePendingCls + '" onchange="caStageSaveDetail(this.form)">' +
                                caCurrencyOptionsHtml(saveCurrency) +
                            '</select>' +
                            '<input type="text" name="baseline" value="' + caEsc(saveBaseline) + '" class="format-currency flex-1 min-w-0 p-1 text-xs border border-gray-300 rounded focus:ring-emerald-500' + savePendingCls + '" placeholder="Unit Price" oninput="caStageSaveDetail(this.form)">' +
                        '</div>' +
                        '<div class="flex gap-1 w-[160px]">' +
                            '<input type="text" id="' + lid + '" name="quotation_link" value="' + caEsc(saveLink) + '" class="flex-1 min-w-0 p-1 text-xs border border-gray-300 rounded focus:ring-emerald-500' + savePendingCls + '" placeholder="G-Drive Link" oninput="caStageSaveDetail(this.form)">' +
                            caPickerBtn(lid, 'bg-gray-200 hover:bg-gray-300 px-2 py-1 rounded flex items-center justify-center flex-shrink-0', 'w-3 h-3') +
                        '</div>' +
                    '</form>' +
                '</td>' +
            '</tr>';
        }

        if (!body) {
            body = '<tr><td colspan="6" class="p-3 text-center text-gray-400 italic">Tidak ada PO terkait.</td></tr>';
        }

        return '' +
        '<div class="p-4 pl-12"><div class="border border-gray-200 rounded-lg overflow-hidden bg-white shadow-sm"><div class="overflow-x-auto">' +
            '<table class="min-w-full text-left text-xs">' +
                '<thead class="bg-indigo-50 text-indigo-800 border-b border-indigo-100"><tr>' +
                    '<th class="p-2 pl-4 uppercase font-bold">PO Number</th>' +
                    '<th class="p-2 uppercase font-bold">Canvassing Date</th>' +
                    '<th class="p-2 text-right uppercase font-bold">Qty</th>' +
                    '<th class="p-2 text-right uppercase font-bold">PO Total Amount</th>' +
                    '<th class="p-2 text-right uppercase font-bold text-indigo-700">Nett Unit Price (IDR)</th>' +
                    '<th class="p-2 text-right w-64 pr-4 uppercase font-bold">Set Specific Unit Baseline</th>' +
                '</tr></thead>' +
                '<tbody>' + body + '</tbody>' +
            '</table>' +
        '</div></div></div>';
    }

    // --- Shared modal (styled like #vendorSearchModalCA) ---------------------
    // One generic dialog reused by: the taxonomy-required validation failure
    // (client-side, below, and server-side batch partial-failures — same
    // component, not two UX patterns per action), the confirm-before-save
    // prompt, and the in-app tab-switch-with-pending-changes warning. The
    // native beforeunload dialog (real navigation/close/refresh) is a
    // separate, unstylable browser prompt and deliberately does NOT go
    // through this — see the beforeunload handler further down.
    function caModalOpen(opts) {
        $('#caModalTitle').text(opts.title);
        $('#caModalBody').html(opts.bodyHtml);
        var footer = $('#caModalFooter').empty();
        (opts.buttons || []).forEach(function (b) {
            var cls = b.primary
                ? 'bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 px-5 rounded-lg text-sm transition'
                : 'text-sm text-gray-500 hover:text-gray-800 font-medium px-3 py-2';
            $('<button type="button"></button>').addClass(cls).text(b.label).on('click', function () {
                caModalClose();
                if (b.onClick) b.onClick();
            }).appendTo(footer);
        });
        $('#caModal').removeClass('hidden').addClass('flex');
    }
    function caModalClose() {
        $('#caModal').removeClass('flex').addClass('hidden');
    }

    // One line per offending item, in the exact quoted format requested:
    // Taxonomy is required for "{item_description}" ({vendor_name})
    function caTaxonomyModalBody(items) {
        return '<ul class="space-y-1.5 list-disc pl-4">' + items.map(function (it) {
            var line = 'Taxonomy is required for &quot;' + caEsc(it.item) + '&quot;' + (it.vendor ? ' (' + caEsc(it.vendor) + ')' : '');
            if (it.offPage) line += ' <span class="text-gray-400">(not shown on current page — use search to find it)</span>';
            return '<li>' + line + '</li>';
        }).join('') + '</ul>';
    }

    // Server-side batch partial-failure report (a race condition — something
    // else changed the row between page load and Save). Shown once, right
    // after the Input Baselines table's first load on the redirected page —
    // that's the earliest point caGroupInfo is populated, which is needed to
    // find and red-outline a failed save_ca_taxonomy item's TAXONOMY select
    // if that group happens to still be on the current page (best-effort,
    // same caveat as the pre-submit validation above). Uses the exact same
    // caModalOpen() component as that pre-submit check, per the "one UX
    // pattern for both failure paths" requirement — this is a distinct
    // message-building function only because the content differs (an
    // arbitrary per-item failure reason, not always a taxonomy message).
    function caShowBatchFailuresIfAny() {
        if (!caBatchFailures || !caBatchFailures.failed || !caBatchFailures.failed.length) return;
        switchTab('input'); // safe pre-any-staging on a fresh page load — never hits the leaving-with-pending path

        var items = caBatchFailures.failed.map(function (f) {
            var el = null;
            if (f.action === 'save_ca_taxonomy') {
                document.querySelectorAll('select[data-role="taxonomy"]').forEach(function (sel) {
                    var info = caGroupInfo[sel.getAttribute('data-group-id')];
                    if (info && info.item === f.item && info.vendor === f.vendor) el = sel;
                });
                if (el) caMarkError(el, true);
            }
            return { item: f.item, vendor: f.vendor, reason: f.reason, offPage: f.action === 'save_ca_taxonomy' && !el };
        });

        var body = '<p class="mb-2 font-semibold">' + caBatchFailures.applied + ' change(s) were saved. ' +
            items.length + ' could not be applied — most likely changed by someone else since this page loaded:</p>' +
            '<ul class="space-y-1.5 list-disc pl-4">' + items.map(function (it) {
                var line = '&quot;' + caEsc(it.item) + '&quot;' + (it.vendor ? ' (' + caEsc(it.vendor) + ')' : '') + ' — ' + caEsc(it.reason);
                if (it.offPage) line += ' <span class="text-gray-400">(not shown on current page)</span>';
                return '<li>' + line + '</li>';
            }).join('') + '</ul>';

        caModalOpen({ title: 'Some Changes Could Not Be Applied', bodyHtml: body, buttons: [{ label: 'OK', primary: true }] });
        caBatchFailures = null;
    }

    // Validate every staged baseline-price change (Apply All or a per-line
    // Save) against its owning group's taxonomy state before Save is allowed
    // to submit anything. Only Project-team (PKR/PKP/MFM) groups are subject
    // to this — CA_PROJECT_LEVEL2_OPTIONS is empty for every other team.
    // Returns true (nothing blocked) or false (blocked; the modal above and
    // the offending TAXONOMY dropdown(s)' red outline are shown first).
    function caValidateBeforeSave() {
        var offendingGroupIds = {};

        Object.keys(caPendingChanges).forEach(function (key) {
            var change = caPendingChanges[key];
            var groupId = null, team = null;

            if (key.indexOf('applyAll:') === 0) {
                groupId = key.slice('applyAll:'.length);
                team = change.team;
            } else if (key.indexOf('saveDetail:') === 0) {
                var info = caDetailGroupInfo[change.ticket_no + '|' + change.ticket_line];
                if (info) { groupId = info.groupId; team = info.team; }
            }
            if (!groupId || !caIsProjectTeam(team)) return;

            var taxPending = caPendingChanges['taxonomy:' + groupId];
            var hasTaxonomy = taxPending ? !!taxPending.ca_taxonomy_level_2 : !!(caGroupInfo[groupId] && caGroupInfo[groupId].caTaxonomyLevel2);
            if (!hasTaxonomy) offendingGroupIds[groupId] = true;
        });

        $('.ca-error-field').removeClass('ca-error-field');
        var ids = Object.keys(offendingGroupIds);
        if (!ids.length) {
            return true;
        }

        var firstEl = null;
        var items = ids.map(function (groupId) {
            var el = document.querySelector('select[data-role="taxonomy"][data-group-id="' + groupId + '"]');
            if (el) { caMarkError(el, true); if (!firstEl) firstEl = el; }
            var info = caGroupInfo[groupId];
            return { item: info ? info.item : groupId, vendor: info ? info.vendor : '', offPage: !el };
        });

        caModalOpen({
            title: 'Taxonomy Required',
            bodyHtml: caTaxonomyModalBody(items),
            buttons: [{ label: 'OK', primary: true }]
        });
        if (firstEl) firstEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return false;
    }

    // Action column: the Edit / Remove buttons plus the inline edit form, ported
    // from the markup the page used to render server-side. toggleEdit() swaps
    // between the two by id, exactly as before.
    function caActionCell(d) {
        // Manual GP-backfill rows have no prs ticket line behind them, so the
        // Edit-price / Remove-baseline forms (which POST ticket_no + ticket_line
        // to prs write actions) do not apply — showing them would submit empty
        // keys. Render a provenance badge instead.
        if (d.source === 'DASHBOARD') {
            return '<span class="inline-block text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded font-bold uppercase tracking-wide" ' +
                   'title="Manual Input entered from the dashboard — edit it under the Manual tab">Dashboard</span>';
        }
        if (d.source === 'MANUAL') {
            return '<span class="inline-block text-[10px] bg-gray-100 text-gray-600 px-2 py-0.5 rounded font-bold uppercase tracking-wide" ' +
                   'title="Backfilled from the Monitoring GP Project workbook — not a K2 PO line">GP</span>';
        }
        var id = caRowId(d);
        var ticket = caEsc(d.ticket), line = caEsc(d.line);
        // Keep whatever currency is saved (the old USD-or-IDR clamp silently flipped
        // e.g. an EUR baseline to IDR the moment it was edited).
        var poCurr = d.poCurrency || 'IDR';
        var baseCurr = d.baseCurrency || 'IDR';
        // The rate this baseline is converted at right now (its saved snapshot, or
        // today's rate for a legacy row) = baselineIdr / baseline.
        var effRate = (baseCurr !== 'IDR' && d.baseline > 0 && d.baselineIdr > 0) ? Math.round(d.baselineIdr / d.baseline * 10000) / 10000 : '';
        var sel = function (name, cur) {
            return '<select name="' + name + '" class="p-1 text-xs border border-gray-300 rounded focus:ring-indigo-500 bg-white">' +
                   caCurrencyOptionsHtml(cur) + '</select>';
        };
        var picker = caGooglePicker
            ? '<button type="button" onclick="createPicker(\'link_edit_' + id + '\')" class="bg-gray-200 hover:bg-gray-300 px-2 py-1 rounded text-xs" title="Select from Google Drive">' +
              '<svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"></path></svg></button>'
            : '';

        return '' +
        '<div class="flex justify-center space-x-3 items-center" id="action-btns-' + id + '">' +
            '<button onclick="toggleEdit(\'' + id + '\')" class="text-indigo-600 hover:text-indigo-800 focus:outline-none" title="Edit Price &amp; Currency">' +
                '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg></button>' +
            '<form method="POST" onsubmit="return confirm(\'Remove baseline price? This PO will return to the Input Baselines queue.\');" class="inline">' +
                '<input type="hidden" name="action" value="remove_baseline">' +
                '<input type="hidden" name="ticket_no" value="' + ticket + '">' +
                '<input type="hidden" name="ticket_line" value="' + line + '">' +
                '<button type="submit" class="text-red-500 hover:text-red-700 focus:outline-none" title="Remove Price">' +
                    '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>' +
            '</form>' +
        '</div>' +
        '<form id="edit-form-' + id + '" method="POST" data-old-price="' + caEsc(d.baseline) + '" data-old-cur="' + caEsc(baseCurr) + '" data-old-rate="' + caEsc(effRate) + '" data-old-link="' + caEsc(d.quotationLink || '') + '" class="ca-inline-form hidden flex flex-col gap-1 items-end mt-1 bg-gray-50 p-2 rounded border border-gray-200">' +
            '<input type="hidden" name="action" value="save_detail">' +
            '<input type="hidden" name="ticket_no" value="' + ticket + '">' +
            '<input type="hidden" name="ticket_line" value="' + line + '">' +
            '<div class="flex gap-2">' +
                '<div class="flex flex-col text-left"><label class="text-[9px] font-bold text-gray-500 uppercase">PO Curr</label>' + sel('po_currency', poCurr) + '</div>' +
                '<div class="flex flex-col text-left"><label class="text-[9px] font-bold text-gray-500 uppercase">Base Curr</label>' + sel('currency', baseCurr) + '</div>' +
                '<div class="flex flex-col text-left"><label class="text-[9px] font-bold text-gray-500 uppercase">Base Unit Price</label>' +
                    // Pre-formatted here because the one-off .format-currency pass on
                    // document.ready cannot reach rows drawn later by DataTables.
                    '<input type="text" name="baseline" required class="format-currency w-24 p-1 text-xs border border-gray-300 rounded focus:ring-indigo-500" value="' + caEsc(formatThousands(d.baseline)) + '"></div>' +
            '</div>' +
            '<div class="ca-fx-wrap hidden flex-col text-left w-full">' +
                '<label class="text-[9px] font-bold text-gray-500 uppercase">Exchange rate (IDR per 1 <span class="ca-fx-cur"></span>) - edit if quoted at another rate</label>' +
                '<div class="flex items-center gap-2"><input type="number" step="0.0001" min="0" name="baseline_fx_rate" class="ca-fx-rate w-28 p-1 text-xs border border-gray-300 rounded" placeholder="0" value="' + caEsc(effRate) + '">' +
                '<span class="ca-fx-preview text-[10px] text-gray-500"></span></div></div>' +
            '<div class="flex gap-2 w-full"><div class="flex flex-col text-left w-full">' +
                '<label class="text-[9px] font-bold text-gray-500 uppercase">Quotation Link</label>' +
                '<div class="flex gap-1">' +
                    '<input type="text" id="link_edit_' + id + '" name="quotation_link" class="w-full p-1 text-xs border border-gray-300 rounded focus:ring-indigo-500" placeholder="Google Drive Link" value="' + caEsc(d.quotationLink || '') + '">' +
                    picker +
                '</div></div></div>' +
            '<div class="flex gap-1 mt-1 w-full justify-end">' +
                '<button type="button" onclick="toggleEdit(\'' + id + '\')" class="text-gray-500 hover:text-gray-700 text-xs px-1 focus:outline-none">Cancel</button>' +
                '<button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-2 py-1 rounded text-xs font-bold transition">Save</button>' +
            '</div>' +
        '</form>';
    }

    $(document).ready(function() {
        $.fn.dataTable.ext.errMode = 'none';

        var table = $('#reportTable').DataTable({
            "serverSide": true,
            "processing": true,
            "ajax": {
                "url": "ajax_cost_avoidance_table.php",
                "type": "POST",
                "data": function (d) { d.gf = JSON.stringify(caFilters); },
                "error": function () {
                    $('.viz-table-loader').fadeOut(200);
                    caShowError('Gagal memuat Savings Report. Coba muat ulang halaman.');
                }
            },
            "pageLength": 25,
            "lengthChange": false,
            "autoWidth": false,
            "order": [[ 6, "desc" ]],
            "columnDefs": [{ "orderable": false, "targets": 6 }],
            "createdRow": function (row) { $(row).addClass('border-b hover:bg-indigo-50 transition text-sm'); },
            "initComplete": function () { $('.viz-table-loader').fadeOut(200); },
            "drawCallback": function (settings) {
                caHideError();
                var json = settings.json || {};
                var total = Number(json.totalAvoidance) || 0;
                $('#reportTotalAvoidance')
                    .text('Rp ' + caInt(total))
                    .toggleClass('text-green-600', total > 0)
                    .toggleClass('text-gray-800', total <= 0);
            },
            "columns": [
                { "data": "po", "className": "p-3 font-bold text-indigo-700",
                  "render": function (d) { return caEsc(d); } },
                { "data": "team", "className": "p-3 font-bold text-gray-800",
                  "render": function (d) { return caEsc(d); } },
                { "data": null, "className": "p-3 text-gray-800",
                  "render": function (d) {
                      var sku = d.sku ? '<span class="text-indigo-600 font-bold">[' + caEsc(d.sku) + '] </span>' : '';
                      var ppn = d.inclusive
                          ? '<span class="ml-1 text-[9px] bg-yellow-100 text-yellow-800 px-1 py-0.5 rounded font-bold">-PPN</span>' : '';
                      return '<div class="truncate max-w-[200px]" title="' + caEsc(d.item) + '">' + sku + caEsc(d.item) + '</div>' +
                             '<div class="text-xs text-gray-500 font-semibold mt-0.5">' + caEsc(d.vendor) +
                             ' | Org: ' + caEsc(d.porg) + ppn + '</div>';
                  } },
                // The three money columns never wrap: a Rupiah figure broken across
                // lines is unreadable. If that makes the table wider than its card,
                // the surrounding .overflow-x-auto scrolls it — which is exactly what
                // that wrapper was added for.
                { "data": null, "className": "p-3 text-right whitespace-nowrap",
                  "render": function (d) {
                      return '<div class="font-bold text-gray-800 font-mono text-sm">' + caMoney(d.poAmount, d.poCurrency) + '</div>' +
                             '<div class="text-[10px] text-gray-500 font-mono font-semibold">Nett: Rp ' + caInt(d.nettUnit) +
                             ' / ' + caEsc(d.uom) + ' (' + caDec2(d.qty) + ' Qty)</div>';
                  } },
                { "data": null, "className": "p-3 text-right whitespace-nowrap",
                  "render": function (d) {
                      var link = /^https?:\/\/[^\s<>"']+$/i.test(d.quotationLink || '')
                          ? '<a href="' + caEsc(d.quotationLink) + '" target="_blank" class="text-blue-500 hover:text-blue-700 ml-1" title="View Quotation">' +
                            '<svg class="w-3 h-3 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg></a>'
                          : '';
                      var usdTotal = (d.baseCurrency === 'USD')
                          ? '<br><span class="text-gray-400">Total ~Rp ' + caInt(d.baselineIdr * d.qty) + '</span>' : '';
                      return '<div class="font-bold text-gray-600 font-mono text-sm">' + caMoney(d.baseline * d.qty, d.baseCurrency) + '</div>' +
                             '<div class="text-[10px] text-gray-500 font-mono font-semibold">Unit: ' + caMoney(d.baseline, d.baseCurrency) +
                             ' / ' + caEsc(d.uom) + ' ' + link + usdTotal + '</div>';
                  } },
                { "data": "avoidance", "className": "p-3 text-right font-bold font-mono whitespace-nowrap",
                  "render": function (d, type) {
                      if (type !== 'display') return d;
                      var cls = d > 0 ? 'text-green-600' : (d < 0 ? 'text-red-600' : 'text-gray-500');
                      return '<span class="' + cls + '">Rp ' + caInt(d) + '</span>';
                  } },
                { "data": null, "className": "p-3 text-right", "orderable": false,
                  "render": function (d) { return caActionCell(d); } }
            ]
        });
        window.caReportTable = table;

        // Records are paginated by DataTables now — drop the report skeleton.
        $('.viz-table-loader').fadeOut(200);

        // --- Input Baselines queue -----------------------------------------
        var baselinesTable = $('#baselinesTable').DataTable({
            "serverSide": true,
            "processing": true,
            "ajax": {
                "url": "ajax_cost_avoidance_baselines.php",
                "type": "POST",
                "data": function (d) {
                    d.mode = 'groups';
                    d.gf = JSON.stringify(caFilters);
                    d.bf = JSON.stringify(caBFilters);
                },
                "error": function () {
                    $('.viz-baselines-loader').fadeOut(200);
                    caShowError('Gagal memuat antrian Input Baselines. Coba muat ulang halaman.');
                }
            },
            "pageLength": 25,
            "lengthChange": false,
            // Same reason as the Savings Report table: with serverSide, autoWidth
            // pins measured widths as inline styles (here it wrote width:0px onto
            // every header). Let the browser lay the table out instead.
            "autoWidth": false,
            "order": [[ 0, "asc" ]],
            "columnDefs": [{ "orderable": false, "targets": 2 }],
            "language": {
                "emptyTable": "All eligible POs have baseline prices configured!"
            },
            "createdRow": function (row) { $(row).addClass('border-b border-gray-200 hover:bg-gray-50 transition'); },
            "initComplete": function () { $('.viz-baselines-loader').fadeOut(200); caShowBatchFailuresIfAny(); },
            "drawCallback": function (settings) {
                caHideError();
                var json = settings.json || {};
               
                if (json.recordsFiltered !== undefined) $('#input-counter').text(json.recordsFiltered);
            },
            "columns": [
                { "data": null, "className": "p-4 align-top",
                  "render": function (d) { return caBaselineItemCell(d); } },
                { "data": "vendor", "className": "p-4 text-sm text-gray-600 font-semibold align-top pt-5",
                  "render": function (d) { return caEsc(d); } },
                { "data": null, "className": "p-4 align-top pt-3 text-right", "orderable": false,
                  "render": function (d) { return caBaselineApplyAllCell(d); } }
            ]
        });
        window.caBaselinesTable = baselinesTable;

        // Search bar: #baselinesTable uses DataTables' own default filter box
        // (no custom dom option), which floats top-right of the table by
        // default. Relocate it into the slot on the left of the unsaved-
        // changes bar so both share one row without a custom dom string.
        $('#baselinesTable_wrapper .dataTables_filter').appendTo('#caBaselinesFilterSlot');

        // Expand / collapse a group to reveal its PO lines. DataTables' native
        // child-row API keeps the detail attached to its parent across sorting
        // and pagination. The raw PO rows are fetched once and cached on the
        // row (d._detailRows) — but the HTML itself is always re-rendered from
        // caBaselineDetailHtml() on every expand, never cached, so a staged
        // change made before a collapse (or made on another group, changing
        // this row's pending state indirectly — it doesn't, but re-rendering
        // is cheap either way) always shows correctly on re-expand.
        $('#baselinesTable tbody').on('click', '.baseline-toggle', function () {
            var tr = $(this).closest('tr');
            var row = baselinesTable.row(tr);
            var icon = tr.find('.baseline-toggle-icon');

            if (row.child.isShown()) {
                row.child.hide();
                icon.removeClass('rotate-180');
                return;
            }

            var d = row.data();
            if (d._detailRows) {
                row.child(caBaselineDetailHtml(d, d._detailRows), 'bg-gray-100 border-b-4 border-gray-300 shadow-inner').show();
                icon.addClass('rotate-180');
                return;
            }

            row.child('<div class="p-4 pl-12 text-xs text-gray-500 font-semibold">Memuat PO terkait…</div>',
                      'bg-gray-100 border-b-4 border-gray-300 shadow-inner').show();
            icon.addClass('rotate-180');

            $.post('ajax_cost_avoidance_baselines.php', {
                mode: 'detail',
                item: d.item, vendor: d.vendor, uom: d.uom || '', team: d.team,
                gf: JSON.stringify(caFilters),
                bf: JSON.stringify(caBFilters)
            }).done(function (resp) {
                if (resp && resp.error) { caShowError(resp.error); }
                d._detailRows = (resp && resp.rows) || [];
                row.child(caBaselineDetailHtml(d, d._detailRows), 'bg-gray-100 border-b-4 border-gray-300 shadow-inner').show();
            }).fail(function () {
                row.child('<div class="p-4 pl-12 text-xs text-red-600 font-semibold">Gagal memuat PO terkait. Coba lagi.</div>',
                          'bg-gray-100 border-b-4 border-gray-300 shadow-inner').show();
            });
        });

        // --- Staged changes: Cancel / Save --------------------------------
        // Cancel reverts every field under #baselinesTable (group rows and
        // any expanded child rows alike — child rows live in the same
        // <table>) to its cached original value and clears all pending state.
        // No network request.
        $('#caCancelBtn').on('click', function () {
            caPendingChanges = {};
            $('#baselinesTable select[data-original]').each(function () { this.value = this.dataset.original; });
            $('#baselinesTable input[name="baseline"], #baselinesTable input[name="quotation_link"]').val('');
            $('#baselinesTable select[name="currency"]').each(function () { this.selectedIndex = 0; });
            $('#baselinesTable select[name="po_currency"]:not([data-original])').each(function () { this.selectedIndex = 0; });
            $('.ca-pending-field').removeClass('ca-pending-field');
            $('.ca-error-field').removeClass('ca-error-field');
            caUpdateUnsavedBar();
        });

        // Save: validate first (no request, no modal beyond the taxonomy one
        // if blocked) — then a confirm-before-save modal — only on explicit
        // confirmation does the actual batch submit fire, the only point
        // that reloads the page. A hidden form (not fetch/AJAX) so the
        // existing batch_save_baselines redirect convention (identical to
        // every other action on this page) keeps working unchanged.
        function caSubmitBatch() {
            var changes = Object.keys(caPendingChanges).map(function (k) { return caPendingChanges[k]; });
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = 'cost_avoidance.php';
            form.style.display = 'none';
            var actionInput = document.createElement('input');
            actionInput.type = 'hidden'; actionInput.name = 'action'; actionInput.value = 'batch_save_baselines';
            var changesInput = document.createElement('input');
            changesInput.type = 'hidden'; changesInput.name = 'changes'; changesInput.value = JSON.stringify(changes);
            form.appendChild(actionInput);
            form.appendChild(changesInput);
            document.body.appendChild(form);
            caNavigatingOnPurpose = true;
            form.submit();
        }

        $('#caSaveBtn').on('click', function () {
            if (!caPendingCount()) return;
            if (!caValidateBeforeSave()) return;

            caModalOpen({
                title: 'Confirm Save',
                bodyHtml: 'This will be entered into Cost Avoidance. Are you sure?',
                buttons: [
                    { label: 'Go Back', primary: false },
                    { label: 'Confirm & Save', primary: true, onClick: caSubmitBatch }
                ]
            });
        });

        // This tab's canvassing-date filter. Applying it re-queries the group list
        // instead of reloading the whole page, as the old GET form did.
        $('#bApplyBtn').on('click', function () {
            caBFilters.g_filter = $('#bFilterSelect').val();
            caBFilters.g_from   = $('#bFrom').val() || '';
            caBFilters.g_to     = $('#bTo').val() || '';
            baselinesTable.ajax.reload();
        });

        // Manual Cost Avoidance listing (separate table/tab, own DataTable instance)
        // Column 1 is "Team" here too, same index as the Savings Report table above,
        // so the same team-filter buttons below can filter both with one search() call.
        var manualTable = $('#manualCaTable').DataTable({
            "pageLength": 25,
            "lengthChange": false,
            "order": [[ 0, "desc" ]]
        });
        // Exposed so toggleManualDetail() (a global onclick handler) can drive
        // this instance's native child rows.
        window.manualTable = manualTable;

        // Universal Team Filter (Applies to Savings Report + Manual tables AND Input Baselines Accordion)
        // manualTeamFullNames maps team code -> full descriptive name, since the
        // Manual table's Team column stores/displays the full name while these
        // filter buttons use the short code (same as the Savings Report table).
        //
        // The Savings Report is server-side now, so its half of this is no longer a
        // DataTables column search: the team goes into caFilters and the table is
        // reloaded, which lets the server apply it as a WHERE on the derived team
        // expression. The Manual table and the accordion are still client-side and
        // keep the original filtering.
        //
        // Delegated, because caRenderTeamButtons() replaces these buttons whenever
        // the date filter changes.
        var manualTeamFullNames = <?= json_encode($manual_team_options) ?>;
        $('#teamFilterButtons').on('click', '.team-filter-btn', function(e) {
            e.preventDefault();
            var team = $(this).attr('data-team');

            $('.team-filter-btn').removeClass('ring-2 ring-offset-2 ring-indigo-400 opacity-100').addClass('opacity-50');
            $(this).removeClass('opacity-50').addClass('ring-2 ring-offset-2 ring-indigo-400 opacity-100');
            caFilters.team = team || '';
            table.ajax.reload();
            baselinesTable.ajax.reload();

            if (team) {
                var manualTeamSearch = manualTeamFullNames[team] || team;
                manualTable.column(1).search('^' + $.fn.dataTable.util.escapeRegex(manualTeamSearch) + '$', true, false).draw();
            } else {
                manualTable.column(1).search('').draw();
            }
        });

        // Initialize Format Currency on load for pre-filled inputs
        $('.format-currency').each(function() {
            if ($(this).val()) $(this).val(formatThousands($(this).val()));
        });

        // Dynamic Format Currency on user input
        $(document).on('input', '.format-currency', function() {
            $(this).val(formatThousands($(this).val()));
        });

        // Charts are fetched from ajax_cost_avoidance_charts.php rather than being
        // embedded as json_encode()d PHP arrays, so the page no longer has to sweep
        // every PO line just to produce a handful of per-team totals.
        caLoadCharts();

        // Global Date Filter. It used to be a GET form that reloaded the page; now
        // it re-queries the two server-side tables and the charts in place.
        $('#globalFilterForm').on('submit', function (e) {
            e.preventDefault();
            caFilters.g_filter = $('#gFilterSelect').val();
            caFilters.g_from   = $('#gFrom').val() || '';
            caFilters.g_to     = $('#gTo').val() || '';
            caRefreshAll();
        });

        $('#globalFilterClear').on('click', function (e) {
            e.preventDefault();
            $('#gFilterSelect').val('all');
            $('#gFrom').val('');
            $('#gTo').val('');
            $('#global-custom-dates').addClass('hidden').removeClass('flex');
            caFilters.g_filter = 'all'; caFilters.g_from = ''; caFilters.g_to = '';
            caBFilters.g_filter = 'all'; caBFilters.g_from = ''; caBFilters.g_to = '';
            $('#bFilterSelect').val('all'); $('#bFrom').val(''); $('#bTo').val('');
            $('#baseline-custom-dates').addClass('hidden').removeClass('flex');
            $('.team-filter-btn').removeClass('ring-2 ring-offset-2 ring-indigo-400 opacity-50').addClass('opacity-100');
            caFilters.team = '';
            $('#manualCaTable').DataTable().column(1).search('').draw();
            caRefreshAll();
        });
    });

    // Everything the Global Date Filter feeds. The two tables re-query themselves;
    // the charts and the Manual tab KPI come from one charts request.
    function caRefreshAll() {
        if (window.caReportTable) window.caReportTable.ajax.reload();
        if (window.caBaselinesTable) window.caBaselinesTable.ajax.reload();
        caLoadCharts();
    }

    // Chart instances are kept so a filter change can destroy and redraw them
    // rather than stacking canvases.
    var caPoChart = null, caMixedChart = null;

    function caLoadCharts() {
        $('.viz-chart-loader, .viz-chart-loader-mixed').show();
        $.post('ajax_cost_avoidance_charts.php', { gf: JSON.stringify(caFilters) })
            .done(function (resp) {
                if (!resp) return;
                if (resp.error) { caShowError(resp.error); } else { caHideError(); }

                if (caPoChart) { caPoChart.destroy(); caPoChart = null; }
                caPoChart = buildFinancialChart(
                    document.getElementById('financialChart').getContext('2d'),
                    resp.po.teams, resp.po.spend, resp.po.avoidance, resp.po.targets, resp.po.pct
                );

                // The Mixed canvas has an empty state when no team has any data.
                var hasMixed = resp.mixed && resp.mixed.teams && resp.mixed.teams.length > 0;
                $('#mixedEmptyState').toggleClass('hidden', hasMixed);
                $('#mixedFinancialChart').toggleClass('hidden', !hasMixed);
                if (caMixedChart) { caMixedChart.destroy(); caMixedChart = null; }
                if (hasMixed) {
                    caMixedChart = buildFinancialChart(
                        document.getElementById('mixedFinancialChart').getContext('2d'),
                        resp.mixed.teams, resp.mixed.spend, resp.mixed.avoidance, resp.mixed.targets, resp.mixed.pct
                    );
                }

                caRenderTeamButtons(resp.teams || []);
                caRenderManualKpi(resp);
            })
            .fail(function () {
                caShowError('Gagal memuat chart Cost Avoidance. Coba lagi.');
            })
            .always(function () {
                $('.viz-chart-loader, .viz-chart-loader-mixed').fadeOut(200);
            });
    }

    // The team buttons list only the teams present in the current date window, so
    // it is rebuilt whenever the filter changes. The currently selected team is
    // kept selected if it survives the new window.
    function caRenderTeamButtons(teams) {
        var html = '<button type="button" class="team-filter-btn text-xs font-bold bg-gray-100 text-gray-800 border border-gray-300 px-3 py-1.5 rounded hover:bg-gray-200 transition focus:outline-none" data-team="">All Teams</button>';
        for (var i = 0; i < teams.length; i++) {
            html += '<button type="button" class="team-filter-btn text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-200 px-3 py-1.5 rounded hover:bg-indigo-100 transition focus:outline-none opacity-100" data-team="' +
                    caEsc(teams[i]) + '">' + caEsc(teams[i]) + '</button>';
        }
        $('#teamFilterButtons').html(html);

        if (caFilters.team && teams.indexOf(caFilters.team) === -1) {
            caFilters.team = ''; // the selected team no longer exists in this window
        }
        if (caFilters.team) {
            $('.team-filter-btn').addClass('opacity-50');
            $('.team-filter-btn[data-team="' + caFilters.team + '"]')
                .removeClass('opacity-50').addClass('ring-2 ring-offset-2 ring-indigo-400 opacity-100');
        }
    }

    // Manual tab KPI: the headline figure plus the level_2 category cards.
    function caRenderManualKpi(resp) {
        $('#manualTotalIdr').text('Rp ' + caInt(resp.manualTotal || 0));

        var cats = resp.manualCats || [];
        var cards = '', drops = '';
        for (var i = 0; i < cats.length; i++) {
            var name = caEsc(cats[i].name), amt = 'Rp ' + caInt(cats[i].amount);
            cards += '<div class="cat-card bg-gray-50 border border-gray-100 rounded px-3 py-2 min-w-[160px] flex-shrink-0" data-idx="' + i + '" data-amount="' + cats[i].amount + '">' +
                     '<div class="text-[10px] font-bold text-gray-500 uppercase truncate" title="' + name + '">' + name + '</div>' +
                     '<div class="text-sm font-bold font-mono text-gray-800">' + amt + '</div></div>';
            drops += '<div class="cat-dropdown-row hidden flex items-center justify-between gap-4 px-3 py-1.5 hover:bg-gray-50 text-xs" data-idx="' + i + '">' +
                     '<span class="text-gray-600 truncate max-w-[140px]" title="' + name + '">' + name + '</span>' +
                     '<span class="font-mono font-bold text-gray-800 whitespace-nowrap">' + amt + '</span></div>';
        }
        $('#categoryCardsRow .cat-card').remove();
        $('#categoryCardsRow').prepend(cards);
        $('#categoryDropdownPanel').html(drops);
        $('#manualCategoryWrap').toggleClass('hidden', cats.length === 0);
        if (typeof layoutCategoryCards === 'function') layoutCategoryCards();
    }

    // Shared builder for the PO-based and Mixed financial charts: two Rp bars
    // (spend + avoidance) on the left axis, two % lines (target + actual) on the
    // right. Only the labels and the four data arrays differ between the charts.
    function buildFinancialChart(ctx, labels, spendData, avoidanceData, targetData, pctData) {
        return new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Total Spend (Rp)', type: 'bar', backgroundColor: '#94a3b8', data: spendData, yAxisID: 'y' },
                    { label: 'Cost Avoidance (Rp)', type: 'bar', backgroundColor: '#22c55e', data: avoidanceData, yAxisID: 'y' },
                    {
                        label: 'Target KPI %', type: 'line',
                        borderColor: '#ef4444', borderDash: [5, 5], backgroundColor: '#ef4444',
                        borderWidth: 2, pointRadius: 4, pointBackgroundColor: '#ffffff', pointBorderWidth: 2,
                        data: targetData, yAxisID: 'y1'
                    },
                    {
                        label: 'Actual Avoidance %', type: 'line',
                        borderColor: '#4f46e5', backgroundColor: '#4f46e5',
                        borderWidth: 3, pointBackgroundColor: '#ffffff', pointBorderWidth: 2, pointRadius: 5,
                        data: pctData, yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: {
                        type: 'linear', display: true, position: 'left',
                        title: { display: true, text: 'Rupiah (Rp)' },
                        ticks: {
                            callback: function(value) {
                                if (value >= 1000000000) return (value / 1000000000).toFixed(1) + 'B';
                                if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M';
                                return value;
                            }
                        }
                    },
                    y1: {
                        type: 'linear', display: true, position: 'right',
                        title: { display: true, text: 'Percentage (%)' },
                        grid: { drawOnChartArea: false },
                        ticks: { callback: function(value) { return value + '%'; } }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label.includes('%')) {
                                    return label + ': ' + context.raw + '%';
                                }
                                return label + ': Rp ' + context.raw.toLocaleString('id-ID');
                            }
                        }
                    }
                }
            }
        });
    }

    function switchChartView(view) {
        const isPo = (view === 'po');
        $('#chartview-content-po').toggleClass('hidden', !isPo);
        $('#chartview-content-mixed').toggleClass('hidden', isPo);
        $('#chartview-po, #chartview-mixed').removeClass('bg-white text-indigo-700 shadow shadow-sm').addClass('text-gray-600 hover:text-gray-800');
        $('#chartview-' + view).addClass('bg-white text-indigo-700 shadow shadow-sm').removeClass('text-gray-600 hover:text-gray-800');

        if (isPo) {
            $('#chartTitle').text('Financial Impact Summary');
            $('#chartSubtitle').text("Total Spend reflects all active POs. Avoidance % is calculated against the team's entire managed spend.");
        } else {
            $('#chartTitle').text('Mixed Cost Avoidance Impact Summary');
            $('#chartSubtitle').text("Total Spend combines PO spend and Manual (non-PO) final/avoided price. Avoidance % is calculated against that combined value.");
        }
    }

    function switchTab(tabId) {
        // A Viewer has no Input Baselines tab; a bookmarked ?b_filter= link would
        // otherwise auto-jump them onto its data-entry UI. Redirect to the report.
        if (IS_VIEWER && tabId === 'input') { tabId = 'report'; }
        // Input Baselines uses same-page JS tabs, not real navigation, so
        // beforeunload never sees a tab switch — guard it here instead, via
        // the shared modal (not confirm()) since this is an in-app action,
        // not a real page unload. Confirming discards pending state exactly
        // like Cancel; declining leaves the user on the Input Baselines tab
        // with everything intact.
        var leavingInputWithPending = tabId !== 'input' && !$('#content-input').hasClass('hidden') && caPendingCount() > 0;
        if (leavingInputWithPending) {
            caModalOpen({
                title: 'Unsaved Changes',
                bodyHtml: 'There are unsaved changes in Input Baselines. Switching tabs will discard them.',
                buttons: [
                    { label: 'Stay on this tab', primary: false },
                    { label: 'Discard & Switch', primary: true, onClick: function () {
                        $('#caCancelBtn').trigger('click');
                        caPerformTabSwitch(tabId);
                    } }
                ]
            });
            return;
        }

        caPerformTabSwitch(tabId);
    }

    function caPerformTabSwitch(tabId) {
        $('.tab-content').addClass('hidden');
        $('#content-' + tabId).removeClass('hidden');
        $('[id^="tab-"]').removeClass('bg-white text-indigo-700 shadow shadow-sm').addClass('text-gray-600 hover:text-gray-800');
        $('#tab-' + tabId).addClass('bg-white text-indigo-700 shadow shadow-sm').removeClass('text-gray-600 hover:text-gray-800');
        // Category cards are measured by actual rendered width, which is 0 while
        // the tab is hidden — re-measure now that the container is visible.
        if (tabId === 'manual' && typeof layoutCategoryCards === 'function') {
            layoutCategoryCards();
        }
    }

    // Warns before leaving the page entirely (close tab, reload, external
    // navigation) while there's at least one staged Input Baselines change.
    // Skipped only for Save's own intentional submit (caNavigatingOnPurpose).
    window.addEventListener('beforeunload', function (e) {
        if (caNavigatingOnPurpose) return;
        if (typeof caPendingCount === 'function' && caPendingCount() > 0) {
            e.preventDefault();
            e.returnValue = 'There are unsaved changes.';
            return e.returnValue;
        }
    });

    function toggleCustomDates(selectElem, prefix) {
        if (selectElem.value === 'custom') {
            $('#' + prefix + '-custom-dates').removeClass('hidden').addClass('flex');
        } else {
            $('#' + prefix + '-custom-dates').addClass('hidden').removeClass('flex');
        }
    }

    // toggleDetails() used to expand a server-rendered accordion row by id. The
    // Input Baselines groups are DataTables rows now, so expanding one is handled
    // by the delegated .baseline-toggle handler, which fetches the PO lines.

    // Auto-switch to Input Baselines tab if b_filter is used. Applying that filter
    // no longer reloads the page, so this now only fires for a URL that arrives with
    // b_filter already set (a bookmark or a shared link).
    <?php if ($b_filter !== 'all'): ?>
    $(document).ready(function() {
        switchTab('input');
    });
    <?php endif; ?>

    function toggleEdit(rowId) {
        const btns = document.getElementById('action-btns-' + rowId);
        const form = document.getElementById('edit-form-' + rowId);
        if (form.classList.contains('hidden')) {
            form.classList.remove('hidden');
            btns.classList.add('hidden');
            // Inline price editor: instant, editable exchange rate (lazy - the
            // form is drawn by DataTables after page load).
            if (form.classList.contains('ca-inline-form') && !$(form).data('fx')) {
                var $f = $(form);
                var fx = CAUI.bindFx({
                    $currency: $f.find('select[name="currency"]'), $price: $f.find('[name="baseline"]'),
                    $rate: $f.find('.ca-fx-rate'), $wrap: $f.find('.ca-fx-wrap'), $preview: $f.find('.ca-fx-preview')
                }, caFxRates);
                $f.data('fx', fx);
                var setCur = function () { $f.find('.ca-fx-cur').text($f.find('select[name="currency"]').val()); };
                var sync = function () { $f.find('.ca-fx-wrap').toggleClass('flex', !$f.find('.ca-fx-wrap').hasClass('hidden')); };
                $f.find('select[name="currency"]').on('change', function () { setCur(); sync(); });
                fx.setRate($f.attr('data-old-rate') || null); setCur(); sync();
            }
        } else {
            form.classList.add('hidden');
            btns.classList.remove('hidden');
        }
    }

    // Expand/collapse a manual entry row to reveal its remarks & drive link.
    // Uses DataTables' native child-row API so the detail stays attached to its
    // parent row across sorting/pagination (a sibling <tr> would also trip the
    // "Incorrect column count" warning).
    function toggleManualDetail(rowId) {
        const chevron = document.getElementById('chevron-' + rowId);
        const tr = document.getElementById('main-' + rowId);
        if (!tr || !window.manualTable) return;
        const row = window.manualTable.row(tr);
        if (row.child.isShown()) {
            row.child.hide();
            if (chevron) chevron.classList.remove('rotate-90');
        } else {
            const data = document.getElementById('detail-data-' + rowId);
            row.child($('<div>').html(data ? data.innerHTML : '').contents()).show();
            $(row.child()).addClass('bg-indigo-50/40');
            if (chevron) chevron.classList.add('rotate-90');
        }
    }

    // Manual Cost Avoidance vendor search (same VSS vendor lookup/filters as
    // the RFQ tender feature). Search first; if nothing matches, "+ Vendor Baru"
    // swaps the results table for a plain name input inside the same modal —
    // the underlying page field stays read-only/click-to-search either way.
    let vendorSearchCATargetInput = null;
    let currentVendorResultsCA = [];

    function openVendorSearchCA(targetInputId) {
        vendorSearchCATargetInput = targetInputId;
        const input = document.getElementById(targetInputId);
        cancelAddNewVendorCA(); 
        document.getElementById('vsNameCA').value = (input && input.value) ? input.value : '';
        document.getElementById('vendorSearchModalCA').classList.remove('hidden');
        searchVendorsCA(1);
    }

    function closeVendorSearchCA() {
        document.getElementById('vendorSearchModalCA').classList.add('hidden');
    }

    // Vendor not found: swap the results table for a name input, still inside this modal.
    function addNewVendorCA() {
        document.getElementById('vsSearchBarCA').classList.add('hidden');
        document.getElementById('vsResultsTableCA').classList.add('hidden');
        document.getElementById('vsAddNewBtnCA').classList.add('hidden');
        document.getElementById('vsAddNewPanelCA').classList.remove('hidden');
        document.getElementById('vsNewVendorNameCA').value = document.getElementById('vsNameCA').value || '';
        document.getElementById('vsNewVendorNameCA').focus();
    }

    function cancelAddNewVendorCA() {
        document.getElementById('vsSearchBarCA').classList.remove('hidden');
        document.getElementById('vsAddNewPanelCA').classList.add('hidden');
        document.getElementById('vsResultsTableCA').classList.remove('hidden');
        document.getElementById('vsAddNewBtnCA').classList.remove('hidden');
    }

    function confirmNewVendorCA() {
        const name = document.getElementById('vsNewVendorNameCA').value.trim();
        if (!name) {
            alert('Nama vendor tidak boleh kosong.');
            return;
        }
        if (vendorSearchCATargetInput) {
            const input = document.getElementById(vendorSearchCATargetInput);
            if (input) input.value = name;
        }
        cancelAddNewVendorCA();
        closeVendorSearchCA();
    }

    function searchVendorsCA(page = 1) {
        const type = document.getElementById('vsTypeCA').value;
        const core = document.getElementById('vsCoreCA').value;
        const name = document.getElementById('vsNameCA').value;
        const body = document.getElementById('vsResultsBodyCA');

        document.getElementById('vsLoadingCA').classList.remove('hidden');
        body.innerHTML = '';
        body.dataset.searched = '1';

        fetch(`ajax_search_vendors.php?type=${encodeURIComponent(type)}&core=${encodeURIComponent(core)}&name=${encodeURIComponent(name)}&page=${page}`)
            .then(r => r.json())
            .then(res => {
                document.getElementById('vsLoadingCA').classList.add('hidden');
                if (res.status === 'success') {
                    currentVendorResultsCA = res.data;
                    renderVendorResultsCA(res.data);
                    renderVSPaginationCA(res.pagination);
                } else {
                    alert('Error searching vendors: ' + (res.message || 'Unknown error'));
                }
            })
            .catch(e => {
                document.getElementById('vsLoadingCA').classList.add('hidden');
                console.error(e);
                alert('Error fetching vendors');
            });
    }

    function renderVSPaginationCA(pagination) {
        const container = document.getElementById('vsPaginationCA');
        if (!pagination || pagination.total_pages <= 1) {
            container.innerHTML = `<span class="text-gray-500 font-semibold text-xs">Total: ${pagination ? pagination.total_records : 0} vendors</span>`;
            return;
        }
        let html = `<span class="text-gray-500 font-semibold mr-2">Page ${pagination.current_page} of ${pagination.total_pages} (${pagination.total_records} vendors)</span>`;
        if (pagination.current_page > 1) {
            html += `<button onclick="searchVendorsCA(${pagination.current_page - 1})" class="px-2 py-1 bg-white border border-gray-300 rounded hover:bg-gray-100 text-gray-700">Prev</button>`;
        }
        if (pagination.current_page < pagination.total_pages) {
            html += `<button onclick="searchVendorsCA(${pagination.current_page + 1})" class="px-2 py-1 bg-white border border-gray-300 rounded hover:bg-gray-100 text-gray-700">Next</button>`;
        }
        container.innerHTML = html;
    }

    function renderVendorResultsCA(data) {
        const tbody = document.getElementById('vsResultsBodyCA');
        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="p-4 text-center text-gray-500 text-xs">No vendors found. Close this modal and type the vendor name manually.</td></tr>';
            return;
        }
        let html = '';
        data.forEach((v, index) => {
            const pqBadge = v.registration_status === 'approved' ? 'text-green-600 bg-green-100' : 'text-gray-600 bg-gray-100';
            const pqText = (v.registration_status || 'Unknown').toUpperCase().replace(/_/g, ' ');
            const riskBadge = v.k2_status === 'approved' ? 'text-green-600 bg-green-100' : (v.k2_status === 'rejected' ? 'text-red-600 bg-red-100' : 'text-yellow-600 bg-yellow-100');
            const riskText = (v.k2_status || 'Unknown').toUpperCase();
            let coreBus = v.sub_company_type || '-';
            if (coreBus.length > 30) coreBus = coreBus.substring(0, 30) + '...';

            html += `<tr class="border-b hover:bg-gray-50">
                <td class="p-2 font-semibold text-gray-800">${v.company_name}</td>
                <td class="p-2 text-xs text-gray-600" title="${v.sub_company_type || ''}">${coreBus}</td>
                <td class="p-2"><span class="px-2 py-0.5 rounded text-[10px] font-bold ${pqBadge}">${pqText}</span></td>
                <td class="p-2"><span class="px-2 py-0.5 rounded text-[10px] font-bold ${riskBadge}">${riskText}</span></td>
                <td class="p-2 text-center"><button type="button" onclick="selectVendorCA(${index})" class="bg-indigo-600 hover:bg-indigo-700 text-white text-[10px] font-bold py-1 px-2 rounded">Pilih</button></td>
            </tr>`;
        });
        tbody.innerHTML = html;
    }

    function selectVendorCA(index) {
        const v = currentVendorResultsCA[index];
        if (!v || !vendorSearchCATargetInput) return;
        const input = document.getElementById(vendorSearchCATargetInput);
        if (input) input.value = v.company_name;
        closeVendorSearchCA();
    }

    // "+N Lainnya" dropdown for the Manual Cost Avoidance category breakdown —
    // toggles open on click, closes on an outside click.
    function toggleCategoryDropdown() {
        document.getElementById('categoryDropdownPanel').classList.toggle('hidden');
    }
    $(document).on('click', function(e) {
        const panel = document.getElementById('categoryDropdownPanel');
        const btn = document.getElementById('categoryMoreBtn');
        if (!panel || panel.classList.contains('hidden')) return;
        if (!panel.contains(e.target) && !btn.contains(e.target)) {
            panel.classList.add('hidden');
        }
    });

    // Fits as many category cards on one row as actually fit the container's
    // width, moving whatever doesn't fit into the "+N Lainnya" button/dropdown
    // instead of a hardcoded card count — re-measured on load and on resize.
    function layoutCategoryCards() {
        const row = document.getElementById('categoryCardsRow');
        const moreBtn = document.getElementById('categoryMoreBtn');
        if (!row || !moreBtn) return;
        const cards = Array.from(row.querySelectorAll('.cat-card'));
        if (cards.length === 0) return;

        cards.forEach(c => c.classList.remove('hidden'));
        moreBtn.classList.add('hidden');
        document.querySelectorAll('.cat-dropdown-row').forEach(r => r.classList.add('hidden'));
        document.getElementById('categoryDropdownPanel').classList.add('hidden');

        const gap = 8; // matches gap-2
        const rowWidth = row.clientWidth;

        moreBtn.classList.remove('hidden');
        const moreBtnWidth = moreBtn.offsetWidth;
        moreBtn.classList.add('hidden');

        let used = 0;
        let cutoff = cards.length;
        for (let i = 0; i < cards.length; i++) {
            const w = cards[i].offsetWidth + (i > 0 ? gap : 0);
            const isLast = i === cards.length - 1;
            const reserve = isLast ? 0 : moreBtnWidth + gap;
            if (used + w + reserve > rowWidth) {
                cutoff = i;
                break;
            }
            used += w;
        }

        if (cutoff < cards.length) {
            let hiddenCount = 0;
            let hiddenSum = 0;
            for (let i = cutoff; i < cards.length; i++) {
                cards[i].classList.add('hidden');
                hiddenCount++;
                hiddenSum += parseFloat(cards[i].dataset.amount || '0');
                const dropRow = document.querySelector('.cat-dropdown-row[data-idx="' + cards[i].dataset.idx + '"]');
                if (dropRow) dropRow.classList.remove('hidden');
            }
            document.getElementById('categoryMoreLabel').textContent = '+' + hiddenCount + ' Lainnya';
            document.getElementById('categoryMoreAmount').textContent = 'Rp ' + Math.round(hiddenSum).toLocaleString('id-ID');
            moreBtn.classList.remove('hidden');
        }
    }

    let categoryLayoutResizeTimer = null;
    $(window).on('resize', function() {
        clearTimeout(categoryLayoutResizeTimer);
        categoryLayoutResizeTimer = setTimeout(layoutCategoryCards, 150);
    });
    $(document).ready(layoutCategoryCards);

    // Shared thousands-separator formatter used by both the .format-currency
    // inputs (on load + on input) and the Manual CA total auto-calc below.
    // Strips everything but digits and a single decimal point, then groups the
    // integer part with commas.
    function formatThousands(raw) {
        let val = String(raw).replace(/[^\d.]/g, '');
        let parts = val.split('.');
        if (parts.length > 2) { parts.pop(); val = parts.join('.'); }
        if (parts[0].length > 0) { parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ","); }
        return parts.join('.');
    }

    // Manual Cost Avoidance: auto-calculate Total = (Unit Price Before - Avoided Price) * Qty.
    // The Total field always stays editable — if the user types their own value
    // afterwards, that value is submitted as-is (no server-side override).
    // Bound via the same jQuery delegation used by the .format-currency handler above,
    // and registered AFTER it, so for every keystroke the comma-formatter always runs
    // first and this always reads the freshly-formatted value — no race between the two.
    function recalcManualTotal(beforeSel, avoidedSel, qtySel, totalSel) {
        const b = parseFloat($(beforeSel).val().replace(/,/g, ''));
        const a = parseFloat($(avoidedSel).val().replace(/,/g, ''));
        const q = parseFloat($(qtySel).val());
        if (!isNaN(b) && !isNaN(a) && !isNaN(q)) {
            $(totalSel).val(formatThousands(((b - a) * q).toFixed(2)));
        }
    }

    $(document).on('input', '#mc_before, #mc_avoided, #mc_quantity', function() {
        recalcManualTotal('#mc_before', '#mc_avoided', '#mc_quantity', '#mc_total');
    });

    $(document).on('input', '.manual-calc-input[data-row]', function() {
        const rowId = $(this).data('row');
        recalcManualTotal('#me_before_' + rowId, '#me_avoided_' + rowId, '#me_quantity_' + rowId, '#me_total_' + rowId);
    });

    // Manual Cost Avoidance forms: custom-styled popup instead of the browser's
    // native "Please fill out this field" bubble (novalidate is set on both
    // forms, so this is the only validation that runs). Mimics the native
    // bubble's behavior — appears under the first invalid field, focuses it,
    // disappears as soon as the user starts fixing it — just restyled.
    function hideManualCaTooltip() {
        $('#manualCaTooltip').remove();
    }

    function showManualCaTooltip($field) {
        hideManualCaTooltip();
        const offset = $field.offset();
        const $tip = $(
            '<div id="manualCaTooltip" class="absolute z-50 flex items-center gap-2 bg-white border border-orange-200 shadow-lg rounded-lg px-3 py-2 text-xs text-gray-700">' +
                '<svg class="w-4 h-4 text-orange-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l6.516 11.59c.75 1.335-.213 2.987-1.743 2.987H3.483c-1.53 0-2.493-1.652-1.743-2.987L8.257 3.1zM11 14a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V7a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>' +
                '<span>Harap isi bidang ini.</span>' +
            '</div>'
        );
        $('body').append($tip);
        $tip.css({ top: (offset.top + $field.outerHeight() + 6) + 'px', left: offset.left + 'px' });
        $field.addClass('border-red-500 ring-1 ring-red-500');
    }

    // Exchange-rate field on every Manual form (create + each edit modal): pick a
    // foreign currency -> today's rate appears instantly, editable; the rate is
    // saved with the entry (CAUI = includes/ca_ui_shared.php).
    const caFxRates = <?= json_encode(array_map(function($c) { return (float)$c['rate_to_idr']; }, $ca_ctx['currencies'])) ?>;
    // On DOM ready: CAUI (includes/ca_ui_shared.php) is emitted after this script block.
    $(function() { $('.manual-ca-form').each(function() {
        const $f = $(this);
        const $wrap = $f.find('.ca-fx-wrap');
        if (!$wrap.length) return;
        const fx = CAUI.bindFx({
            $currency: $f.find('select[name="m_currency"]'), $price: $f.find('[name="m_unit_price_before"]'),
            $rate: $f.find('.ca-fx-rate'), $wrap: $wrap, $preview: $f.find('.ca-fx-preview')
        }, caFxRates);
        $f.data('fx', fx);
        const setCur = function() { $f.find('.ca-fx-cur').text($f.find('select[name="m_currency"]').val()); };
        $f.find('select[name="m_currency"]').on('change', setCur);
        setCur();
        // Existing entry: show the rate it was saved with (legacy = today's).
        if ($f.attr('data-old-cur')) fx.setRate($f.attr('data-old-rate') || null); else fx.refresh();
        // The wrapper is a flex column when visible.
        const sync = function() { $wrap.toggleClass('flex', !$wrap.hasClass('hidden')); };
        $f.find('select[name="m_currency"]').on('change', sync); sync();
    }); });

    $(document).on('click', '.ca-manual-history', function(e) {
        e.stopPropagation();
        const id = String($(this).data('m-id'));
        CAUI.history('manual', id, 'Manual entry #' + id);
    });

    // Inline price editor (Savings Report rows): changing an ALREADY SAVED price
    // asks yes/no with old -> new first; "Yes" resubmits with confirm_edit=1.
    $(document).on('submit', 'form.ca-inline-form', function(e) {
        const $f = $(this);
        const fxw = $f.data('fx');
        const rate = fxw ? fxw.value() : '';
        if (rate === null) {
            e.preventDefault();
            alertInline($f, 'Exchange rate must be greater than 0.');
            return;
        }
        if ($f.data('confirmed')) return;
        const oldP = parseFloat(String($f.attr('data-old-price')).replace(/,/g, ''));
        if (!(oldP > 0)) return;                                   // first price: no prompt
        const oldSnap = { price: oldP, currency: $f.attr('data-old-cur'), fx_rate: $f.attr('data-old-rate') || null };
        const newSnap = { price: parseFloat(String($f.find('[name="baseline"]').val()).replace(/,/g, '')), currency: $f.find('select[name="currency"]').val(), fx_rate: rate || null };
        const same = Math.abs(oldSnap.price - newSnap.price) < 0.005 && oldSnap.currency === newSnap.currency &&
                     (newSnap.currency === 'IDR' || Math.abs((parseFloat(oldSnap.fx_rate) || 0) - (parseFloat(newSnap.fx_rate) || 0)) < 0.00005);
        if (same) return;                                          // server treats it as a no-op
        e.preventDefault();
        CAUI.confirm({
            title: 'Change saved price?',
            bodyHtml: '<p class="mb-2">This price is already saved. Do you want to change it?</p>' +
                '<p class="text-xs bg-gray-50 border border-gray-200 rounded px-2 py-1">' + CAUI.esc(CAUI.fmtSnap(oldSnap)) + ' &rarr; <b>' + CAUI.esc(CAUI.fmtSnap(newSnap)) + '</b></p>' +
                '<p class="mt-2 text-xs text-gray-500">The change is recorded in the history with your name and the time.</p>',
            yes: 'Yes, change it', no: 'No'
        }, function () {
            if (!$f.find('input[name="confirm_edit"]').length) $f.append('<input type="hidden" name="confirm_edit" value="1">');
            $f.data('confirmed', true);
            HTMLFormElement.prototype.submit.call($f[0]);
        });
    });
    function alertInline($f, msg) {
        let $m = $f.find('.ca-inline-err');
        if (!$m.length) $m = $('<p class="ca-inline-err text-[10px] text-red-600 w-full text-left"></p>').appendTo($f);
        $m.text(msg);
    }

    $(document).on('submit', '.manual-ca-form', function(e) {
        $(this).find('[required]').removeClass('border-red-500 ring-1 ring-red-500');
        let firstInvalid = null;
        $(this).find('[required]').each(function() {
            if (!this.checkValidity() && !firstInvalid) firstInvalid = this;
        });
        if (firstInvalid) {
            e.preventDefault();
            $(firstInvalid).focus();
            showManualCaTooltip($(firstInvalid));
        } else if ($(this).find('input[name="action"]').val() === 'update_manual_ca' && !$(this).data('confirmed')) {
            // Editing a saved entry: ask first, showing old -> new; "Yes" resubmits.
            e.preventDefault();
            hideManualCaTooltip();
            const $f = $(this);
            const fxv = $f.data('fx') ? $f.data('fx').value() : '';
            const oldSnap = { price: $f.attr('data-old-price').replace(/,/g, ''), currency: $f.attr('data-old-cur'), fx_rate: $f.attr('data-old-rate') || null };
            const newSnap = { price: $f.find('[name="m_unit_price_before"]').val().replace(/,/g, ''), currency: $f.find('select[name="m_currency"]').val(), fx_rate: fxv || null };
            CAUI.confirm({
                title: 'Change saved entry?',
                bodyHtml: '<p class="mb-2">This entry is already saved. Do you want to change it?</p>' +
                    '<p class="text-xs bg-gray-50 border border-gray-200 rounded px-2 py-1">Initial price: ' + CAUI.esc(CAUI.fmtSnap(oldSnap)) + ' &rarr; <b>' + CAUI.esc(CAUI.fmtSnap(newSnap)) + '</b></p>' +
                    '<p class="mt-2 text-xs text-gray-500">The change is recorded in the history with your name and the time.</p>',
                yes: 'Yes, change it', no: 'No'
            }, function() {
                if (!$f.find('input[name="confirm_edit"]').length) $f.append('<input type="hidden" name="confirm_edit" value="1">');
                $f.data('confirmed', true);
                const $b = $f.find('button[type="submit"]');
                $b.data('originalHtml', $b.html());
                $b.prop('disabled', true).addClass('opacity-60 cursor-not-allowed').html('Menyimpan...');
                HTMLFormElement.prototype.submit.call($f[0]);
            });
        } else {
            hideManualCaTooltip();
            // Valid submit: lock the Save button so a slow reload can't be
            // double-submitted. The page reloads on POST, so no manual re-enable.
            const $btn = $(this).find('button[type="submit"]');
            $btn.data('originalHtml', $btn.html());
            $btn.prop('disabled', true).addClass('opacity-60 cursor-not-allowed').html('Menyimpan...');
        }
    });

    // If the user comes back via the browser's back/forward cache, the button
    // was left disabled by the submit above — restore it so it's usable again.
    $(window).on('pageshow', function(e) {
        if (e.originalEvent && e.originalEvent.persisted) {
            $('.manual-ca-form button[type="submit"]').each(function() {
                const $btn = $(this);
                if ($btn.data('originalHtml') !== undefined) {
                    $btn.prop('disabled', false).removeClass('opacity-60 cursor-not-allowed').html($btn.data('originalHtml'));
                }
            });
        }
    });

    // Dismiss the popup as soon as the user starts fixing the flagged field.
    $(document).on('input change', '.manual-ca-form [required]', function() {
        if (this.checkValidity()) {
            $(this).removeClass('border-red-500 ring-1 ring-red-500');
            hideManualCaTooltip();
        }
    });
</script>

<?php ca_render_shared_ui(); ?>
<?php require_once '../includes/footer.php'; ?>