<?php
// includes/vendor_quotation_helper.php
// Shared core for "Initial Quotation Price": auth, line lookups, taxonomy
// options and the save paths, used by the modal endpoints
// (ajax_project_quotation_lines.php / ajax_project_quotation_save.php) and the
// bulk CSV import (ajax_quotation_bulk.php) on dashboard_project_head.php,
// dashboard_mgr.php and dashboard_pic.php.
//
// Granularity (confirmed by user, 2026-09-29):
//   - GP Project     -> one price per CANVASSING (canvas scope) + taxonomy required;
//   - GP Non-Project -> per TICKET, every line priced (item scope).
//
// A ticket's p_group decides which gate applies:
//   - An ACTIVE Project p_group -> Project Head access to THAT specific group
//     (get_project_head_groups()), same restriction ajax_project_assign.php
//     already enforces for reassignment.
//   - Anything else (Non-Project) -> any Manager / Category Manager / Admin,
//     matching dashboard_mgr.php's own reassignment write-permission model
//     (visibility is scoped by persona in the SELECT, not by a per-ticket
//     check on write).
require_once __DIR__ . '/ca_intercompany_exceptions.php';   // ca_not_intercompany_sql(), ca_is_intercompany_exception()

if (!function_exists('quotation_authorize_ticket')) {
    function quotation_authorize_ticket(PDO $pdo, array $session, string $p_group) {
        $stmt = $pdo->prepare("SELECT project_group_number FROM project_pgroups WHERE p_group = ? AND status = 'ACTIVE' LIMIT 1");
        $stmt->execute([$p_group]);
        $pg = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pg) {
            $allowed_groups = get_project_head_groups($pdo, $session);
            if (empty($allowed_groups) || !in_array((int)$pg['project_group_number'], $allowed_groups, true)) {
                return ['ok' => false, 'message' => 'P.Group is not within your authorised Project groups.'];
            }
            return ['ok' => true];
        }

        $role = $session['role'] ?? '';
        if (!in_array($role, ['Manager', 'Category Manager', 'Admin'], true)) {
            return ['ok' => false, 'message' => 'Manager access required.'];
        }
        return ['ok' => true];
    }
}

// Canvassing-level gate used by every quotation read/write. Sourcing officers
// (PIC) work from their own worklist (dashboard_pic.php, assigned_pic), so a
// PIC may price a canvassing when at least one of its lines is assigned to
// them -- including the lines of other tickets on that canvassing, which are
// priced together. Everyone else goes through quotation_authorize_ticket().
if (!function_exists('quotation_authorize_canvassing')) {
    function quotation_authorize_canvassing(PDO $pdo, array $session, string $p_group, array $lines) {
        if (($session['role'] ?? '') === 'PIC') {
            $me = strtolower(trim((string)($session['name'] ?? '')));
            if ($me !== '') {
                foreach ($lines as $l) {
                    if (strtolower(trim((string)($l['assigned_pic'] ?? ''))) === $me) return ['ok' => true];
                }
            }
            return ['ok' => false, 'message' => 'None of this canvassing\'s lines are assigned to you.'];
        }
        return quotation_authorize_ticket($pdo, $session, $p_group);
    }
}

// GP Project quotation granularity is the canvassing: one canvassing
// round can cover several tickets, and the modal prices every line on it.
// Cancelled-ticket lines are left out so they can't block the "every line
// must be priced" rule. A canvassing never spans p_groups in practice; if it
// ever does, refuse rather than authorize against just one of them.
if (!function_exists('quotation_canvassing_lines')) {
    function quotation_canvassing_lines(PDO $pdo, string $canvassing_no) {
        $stmt = $pdo->prepare("
            SELECT ticket_no, ticket_line_item, item_description, p_group,
                   IFNULL(ticket_type,'') AS ticket_type, vendor_name, uom, ca_taxonomy_level_2, assigned_pic
            FROM prs
            WHERE canvassing_no = ?
              AND LOWER(IFNULL(status_ticket,'')) NOT LIKE '%cancel%'
            ORDER BY ticket_no, ticket_line_item
        ");
        $stmt->execute([$canvassing_no]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($lines)) {
            return ['ok' => false, 'message' => 'Canvassing not found, or it has no active lines.'];
        }

        $p_groups = [];
        $tickets = [];
        foreach ($lines as $l) {
            $p_groups[(string)$l['p_group']] = true;
            $tickets[(string)$l['ticket_no']] = true;
        }
        if (count($p_groups) > 1) {
            return ['ok' => false, 'message' => 'Canvassing spans multiple P.Groups.'];
        }

        return [
            'ok'           => true,
            'lines'        => $lines,
            'p_group'      => (string)array_key_first($p_groups),
            'ticket_count' => count($tickets),
        ];
    }
}

// Batched "N items priced" summary for the dashboards: item-scope rows are
// still keyed by (ticket_no, ticket_line_item), so count them through prs
// back up to the canvassing each line belongs to.
if (!function_exists('quotation_item_counts_by_canvassing')) {
    function quotation_item_counts_by_canvassing(PDO $pdo, array $canvassing_nos) {
        $canvassing_nos = array_values(array_filter(array_map('strval', $canvassing_nos), function($c) { return trim($c) !== ''; }));
        if (empty($canvassing_nos)) return [];
        $ph = implode(',', array_fill(0, count($canvassing_nos), '?'));
        $stmt = $pdo->prepare("
            SELECT p.canvassing_no, COUNT(*) AS priced
            FROM vendor_quotation_last_price v
            JOIN prs p ON p.ticket_no = v.ticket_no AND p.ticket_line_item = v.ticket_line_item
            WHERE v.scope = 'item' AND v.last_price IS NOT NULL AND p.canvassing_no IN ($ph)
            GROUP BY p.canvassing_no
        ");
        $stmt->execute($canvassing_nos);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['canvassing_no']] = (int)$r['priced'];
        }
        return $out;
    }
}

// Batched quotation_canvassing_lines() for bulk template / import: every
// active line on each canvassing, keyed by canvassing_no. Same cancelled-ticket
// exclusion.
if (!function_exists('quotation_lines_for_canvassings')) {
    function quotation_lines_for_canvassings(PDO $pdo, array $canvassing_nos) {
        $canvassing_nos = array_values(array_unique(array_filter(array_map(function($c) { return trim((string)$c); }, $canvassing_nos), 'strlen')));
        $out = [];
        foreach (array_chunk($canvassing_nos, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare("
                SELECT canvassing_no, ticket_no, ticket_line_item, item_description, p_group,
                       IFNULL(ticket_type,'') AS ticket_type, vendor_name, uom, qty, ca_taxonomy_level_2, assigned_pic,
                       canvassing_status
                FROM prs
                WHERE canvassing_no IN ($ph)
                  AND LOWER(IFNULL(status_ticket,'')) NOT LIKE '%cancel%'
                ORDER BY canvassing_no, ticket_no, ticket_line_item
            ");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(string)$r['canvassing_no']][] = $r;
            }
        }
        return $out;
    }
}

// GP Non-Project quotations are keyed by TICKET (every active line of one
// ticket is priced together -- the original granularity, restored
// 2026-09-29); GP Project ones by canvassing (above). Same cancelled-line
// exclusion and single-p_group rule.
if (!function_exists('quotation_ticket_lines')) {
    function quotation_ticket_lines(PDO $pdo, string $ticket_no) {
        $lines = quotation_lines_for_tickets($pdo, [$ticket_no])[trim($ticket_no)] ?? [];
        if (empty($lines)) {
            return ['ok' => false, 'message' => 'Ticket not found, or it has no active lines.'];
        }
        $p_groups = array_unique(array_map('strval', array_column($lines, 'p_group')));
        if (count($p_groups) > 1) {
            return ['ok' => false, 'message' => 'Ticket spans multiple P.Groups.'];
        }
        return ['ok' => true, 'lines' => $lines, 'p_group' => (string)$p_groups[0]];
    }
}

// Batched quotation_ticket_lines() for the bulk template / import.
if (!function_exists('quotation_lines_for_tickets')) {
    function quotation_lines_for_tickets(PDO $pdo, array $ticket_nos) {
        $ticket_nos = array_values(array_unique(array_filter(array_map(function($t) { return trim((string)$t); }, $ticket_nos), 'strlen')));
        $out = [];
        foreach (array_chunk($ticket_nos, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare("
                SELECT ticket_no, ticket_line_item, canvassing_no, item_description, sku, p_group,
                       IFNULL(ticket_type,'') AS ticket_type, vendor_name, uom, qty, assigned_pic
                FROM prs
                WHERE ticket_no IN ($ph)
                  AND LOWER(IFNULL(status_ticket,'')) NOT LIKE '%cancel%'
                ORDER BY ticket_no, CAST(ticket_line_item AS UNSIGNED), ticket_line_item
            ");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(string)$r['ticket_no']][] = $r;
            }
        }
        return $out;
    }
}

// What each PO line of a ticket cost per unit, for the modal's live "cost
// avoidance" preview. Same rule the Cost Avoidance report uses for the nett unit
// price: the PO total, PPN stripped (/1.11) when the line's p_org is PPN-inclusive,
// divided by qty -- so a baseline typed in the modal is compared like for like
// (the baseline is entered BEFORE PPN). Lines with no PO yet are simply absent.
// Each row: ticket_no, line, po_number, po_currency, qty, unit_ex_tax (PO currency),
// unit_ex_tax_idr (null when the currency has no rate), tax_removed, and
// `eligible` = false for AON / Intercompany lines, which never get a baseline and
// so never count toward Cost Avoidance. Lines with no PO yet are simply absent.
if (!function_exists('quotation_po_info_rows')) {
    function quotation_po_info_rows(PDO $pdo, string $where, array $params): array {
        require_once __DIR__ . '/cost_avoidance_helper.php';
        $rated = ca_get_convertible_currencies($pdo);
        $stmt = $pdo->prepare("
            SELECT p.ticket_no, p.ticket_line_item, p.po_number, p.po_amount, p.po_currency, p.qty,
                   (" . ca_is_inclusive_expr() . ") AS is_inclusive,
                   (" . ca_not_intercompany_sql('p') . " AND NOT " . ca_is_aon_expr('p') . ") AS eligible
            FROM prs p
            WHERE {$where}
              AND p.po_number IS NOT NULL AND p.po_number <> '' AND p.po_amount > 0 AND p.qty > 0
              AND LOWER(IFNULL(p.status_ticket,'')) NOT LIKE '%cancel%'
            ORDER BY p.ticket_no, CAST(p.ticket_line_item AS UNSIGNED)
        ");
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cur = strtoupper(trim((string)$r['po_currency']));
            if ($cur === '') $cur = 'IDR';
            $inc = (int)$r['is_inclusive'] === 1;
            $unit = ((float)$r['po_amount'] / ($inc ? 1.11 : 1.0)) / (float)$r['qty'];
            $known = $cur === 'IDR' || isset($rated[$cur]);
            $out[] = [
                'ticket_no'       => (string)$r['ticket_no'],
                'line'            => (string)$r['ticket_line_item'],
                'po_number'       => (string)$r['po_number'],
                'po_currency'     => $cur,
                'qty'             => (float)$r['qty'],
                'unit_ex_tax'     => round($unit, 4),
                'unit_ex_tax_idr' => $known ? round(ca_to_idr($unit, $cur, $rated, null, true), 4) : null,
                'tax_removed'     => $inc,
                'eligible'        => (int)$r['eligible'] === 1,
            ];
        }
        return $out;
    }
}
// Non-Project: line => info for one ticket.
if (!function_exists('quotation_item_po_info')) {
    function quotation_item_po_info(PDO $pdo, string $ticket_no): array {
        $out = [];
        foreach (quotation_po_info_rows($pdo, 'p.ticket_no = ?', [trim($ticket_no)]) as $r) {
            $out[$r['line']] = $r;
        }
        return $out;
    }
}
// Project: every PO line under one canvassing (one price is mirrored onto all of them).
if (!function_exists('quotation_canvas_po_info')) {
    function quotation_canvas_po_info(PDO $pdo, string $canvassing_no): array {
        return quotation_po_info_rows($pdo, 'p.canvassing_no = ?', [trim($canvassing_no)]);
    }
}

// Active (not cancelled) lines per ticket, so a partly priced Non-Project
// ticket can read "2 of 5 items priced" and its open lines stay visible.
if (!function_exists('quotation_line_totals_by_ticket')) {
    function quotation_line_totals_by_ticket(PDO $pdo, array $ticket_nos) {
        $ticket_nos = array_values(array_unique(array_filter(array_map(function($t) { return trim((string)$t); }, $ticket_nos), 'strlen')));
        $out = [];
        foreach (array_chunk($ticket_nos, 1000) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare("
                SELECT ticket_no, COUNT(*) AS n FROM prs
                WHERE ticket_no IN ($ph) AND LOWER(IFNULL(status_ticket,'')) NOT LIKE '%cancel%'
                GROUP BY ticket_no
            ");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[(string)$r['ticket_no']] = (int)$r['n']; }
        }
        return $out;
    }
}

// "N items priced" per ticket for the Non-Project dashboard rows.
if (!function_exists('quotation_item_counts_by_ticket')) {
    function quotation_item_counts_by_ticket(PDO $pdo, array $ticket_nos) {
        $ticket_nos = array_values(array_unique(array_filter(array_map(function($t) { return trim((string)$t); }, $ticket_nos), 'strlen')));
        $out = [];
        foreach (array_chunk($ticket_nos, 1000) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare("
                SELECT ticket_no, COUNT(*) AS priced
                FROM vendor_quotation_last_price
                WHERE scope = 'item' AND last_price IS NOT NULL AND ticket_no IN ($ph)
                GROUP BY ticket_no
            ");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(string)$r['ticket_no']] = (int)$r['priced'];
            }
        }
        return $out;
    }
}

// ticket_no|line keys on these lines that already carry a saved item price.
if (!function_exists('quotation_priced_item_keys')) {
    function quotation_priced_item_keys(PDO $pdo, array $lines) {
        $out = [];
        foreach (array_chunk($lines, 500) as $chunk) {
            $tuples = [];
            $params = [];
            foreach ($chunk as $l) { $tuples[] = '(?, ?)'; $params[] = $l['ticket_no']; $params[] = $l['ticket_line_item']; }
            $stmt = $pdo->prepare("SELECT ticket_no, ticket_line_item FROM vendor_quotation_last_price WHERE scope = 'item' AND last_price IS NOT NULL AND (ticket_no, ticket_line_item) IN (" . implode(',', $tuples) . ")");
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[$r['ticket_no'] . '|' . $r['ticket_line_item']] = true; }
        }
        return $out;
    }
}

// p_group -> Project team code (PKR/PKP/MFM), or null for Non-Project.
if (!function_exists('quotation_project_team')) {
    function quotation_project_team(PDO $pdo, string $p_group) {
        require_once __DIR__ . '/cost_avoidance_helper.php';
        $stmt = $pdo->prepare("SELECT project_group_number FROM project_pgroups WHERE p_group = ? AND status = 'ACTIVE' LIMIT 1");
        $stmt->execute([$p_group]);
        $n = $stmt->fetchColumn();
        if ($n === false) return null;
        return CA_PROJECT_GROUP_CODE[(int)$n] ?? ('G' . (int)$n);
    }
}

// Project taxonomy (Level 2 "type of work") for one team as
// [['code','label'], ...] -- straight from CA_PROJECT_TAXONOMY
// (cost_avoidance_helper.php), the business's source of truth (I = Property,
// J = Retail, K = Maintenance). The single source for the modal, the import
// guide and validation; never read from v_item_taxonomy, which disagrees
// between environments.
if (!function_exists('quotation_taxonomy_options')) {
    function quotation_taxonomy_options(PDO $pdo, ?string $team) {
        require_once __DIR__ . '/cost_avoidance_helper.php';
        if ($team === null || !isset(CA_PROJECT_TAXONOMY[$team])) return [];
        $opts = [];
        foreach (CA_PROJECT_TAXONOMY[$team] as $code => $label) { $opts[] = ['code' => $code, 'label' => $label]; }
        return $opts;
    }
}

// Resolve what a user typed/picked -- "J1", "J1 · Civil-Architecture",
// "J1 Civil-Architecture" or the plain label -- to the stored Level 2 label.
// Returns ['ok', 'label', 'message'].
if (!function_exists('quotation_resolve_taxonomy')) {
    function quotation_resolve_taxonomy(PDO $pdo, ?string $team, $value) {
        $v = trim(preg_replace('/\s+/u', ' ', (string)$value));
        if ($v === '') return ['ok' => false, 'label' => null, 'message' => 'Taxonomy is required.'];
        $opts = quotation_taxonomy_options($pdo, $team);
        if (empty($opts)) return ['ok' => false, 'label' => null, 'message' => 'No taxonomy options are set up for team ' . ($team ?? '?') . '.'];

        $code = strtoupper(preg_split('/[\s·\-]+/u', $v)[0]);
        foreach ($opts as $o) {
            if ($o['code'] === '' || strtoupper($o['code']) !== $code) continue;
            // "J1" alone is fine. If a name follows the code it must be that
            // code's own name, so "J2 Civil-Architecture" cannot quietly save
            // as J2 (FF&E).
            $rest = trim(preg_replace('/^[A-Za-z]\d+[\s·\-:]*/u', '', $v));
            if ($rest !== '' && strcasecmp($rest, $o['label']) !== 0) {
                return ['ok' => false, 'label' => null, 'message' => "Taxonomy \"$v\" does not match: {$o['code']} is \"{$o['label']}\". Write it like {$o['code']} {$o['label']}."];
            }
            return ['ok' => true, 'label' => $o['label'], 'message' => ''];
        }
        foreach ($opts as $o) {
            if (strcasecmp($o['label'], $v) === 0) return ['ok' => true, 'label' => $o['label'], 'message' => ''];
        }
        // Say which team this unit belongs to and what is valid for it: a code
        // such as J1 is real, but only for another team (PKR), which is what
        // people hit when they copy a code from the wrong list.
        $valid = implode(', ', array_filter(array_column($opts, 'code'), 'strlen'));
        $hint = "This one belongs to team $team, so use one of: $valid.";
        if (preg_match('/^[A-Z]\d+$/', $code)) {
            return ['ok' => false, 'label' => null, 'message' => "Taxonomy \"$code\" is for a different team. $hint"];
        }
        return ['ok' => false, 'label' => null, 'message' => "Taxonomy \"$v\" is not a known code. $hint"];
    }
}

// The two save paths for Initial Quotation Price -- used by the modal
// (ajax_project_quotation_save.php) and the bulk import
// (ajax_quotation_bulk.php), so both enforce exactly the same rules:
//   GP Project     -> quotation_save_project_canvas(): one price per canvassing,
//                     taxonomy required (confirmed by user, 2026-09-29);
//   GP Non-Project -> quotation_save_ticket_items(): every open line of one
//                     ticket priced together.
//
// Price + currency + exchange rate are validated in ONE place
// (quotation_validate_price(), on top of ca_resolve_fx() in currency_helper.php)
// for both callers -- an invalid/inactive currency is an error everywhere, and
// the rate the price was entered at is stored beside it (fx_rate) so a later
// change to currencies.rate_to_idr cannot silently move an old baseline.
//
// A unit that already has a price can be EDITED (it used to be locked once
// saved). The first save just saves; a later save that changes the price,
// currency, rate, link or remark must be confirmed ($opts['confirm_edit']) --
// without it the save is refused with ['needs_confirm' => true, 'changes' =>
// [[key, old, new], ...]] so the caller can ask "change X to Y?". Every
// create/edit is logged to cost_avoidance_edit_history (who/when/old->new)
// inside the same transaction.
//
// $opts: dry_run (run every check, write nothing; the result carries
// 'changes'), confirm_edit, source ('MODAL' default, or 'BULK' for history).
// A real run writes inside its own transaction (unless the caller already
// opened one), so a failure never leaves a unit half-priced.
// Both return ['ok', 'message', 'lines_saved', 'taxonomy_warnings',
// 'baseline_warnings'] plus, when relevant, 'changes' / 'needs_confirm'.
if (!function_exists('quotation_save_result')) {
    function quotation_save_result($ok, $message, $saved = 0, $taxWarn = [], $baseWarn = [], $extra = []) {
        return array_merge(['ok' => $ok, 'message' => $message, 'lines_saved' => $saved,
                'taxonomy_warnings' => $taxWarn, 'baseline_warnings' => $baseWarn], $extra);
    }
}

if (!function_exists('quotation_block_intercompany')) {
    function quotation_block_intercompany(array $lines) {
        foreach ($lines as $l) {
            // Intercompany blocks pricing, except the tickets on the Cost Avoidance
            // exception list (includes/ca_intercompany_exceptions.php).
            if (ca_is_intercompany_type($l['ticket_type']) && !ca_is_intercompany_exception($l['ticket_no'] ?? '')) return true;
        }
        return false;
    }
}

// True when a Project canvassing already carries a price: a canvas price
// exists, or every line already has an item price saved under the earlier
// per-line rule (legacy). It can still be edited -- the edit is confirmed and
// logged like any other.
if (!function_exists('quotation_project_canvas_finalized')) {
    function quotation_project_canvas_finalized(PDO $pdo, string $canvassing_no, array $lines) {
        $stmt = $pdo->prepare("SELECT 1 FROM vendor_quotation_last_price WHERE scope = 'canvas' AND canvassing_no = ? AND last_price IS NOT NULL LIMIT 1");
        $stmt->execute([$canvassing_no]);
        if ($stmt->fetchColumn()) return true;
        return count(quotation_priced_item_keys($pdo, $lines)) >= count($lines);
    }
}

// A price snapshot ['price' (2 dp), 'currency', 'fx_rate' (4 dp, null for IDR)].
if (!function_exists('quotation_snapshot')) {
    function quotation_snapshot($price, $currency, $fx): array {
        $cur = strtoupper(trim((string)$currency));
        if ($cur === '') $cur = 'IDR';
        return [
            'price'    => $price === null ? null : round((float)$price, 2),
            'currency' => $cur,
            'fx_rate'  => ($cur === 'IDR' || $fx === null || (float)$fx <= 0) ? null : round((float)$fx, 4),
        ];
    }
}

// code => current rate_to_idr, for comparing a legacy (NULL-rate) row.
if (!function_exists('quotation_current_rates')) {
    function quotation_current_rates(array $currencies): array {
        $out = [];
        foreach ($currencies as $code => $c) { $out[$code] = (float)$c['rate_to_idr']; }
        return $out;
    }
}

// THE price check every entry path shares (modal and bulk; Manual Input uses
// the same ca_resolve_fx()): a positive amount that fits DECIMAL(18,2), an
// active currency, and a usable exchange rate. Returns ['ok', 'message',
// 'snapshot'] -- the snapshot is exactly what gets stored.
if (!function_exists('quotation_validate_price')) {
    function quotation_validate_price($priceRaw, $currency, $rateRaw, array $currencies): array {
        require_once __DIR__ . '/currency_helper.php';
        $p = ca_parse_amount($priceRaw);
        if ($p['ok'] && $p['value'] === null) {
            return ['ok' => false, 'message' => 'Initial Quotation Price is required.', 'snapshot' => null];
        }
        if (!$p['ok'] || round($p['value'], 2) <= 0) {
            return ['ok' => false, 'message' => 'Initial Quotation Price must be a number greater than 0.', 'snapshot' => null];
        }
        if ($p['value'] >= 1.0E+16) {
            return ['ok' => false, 'message' => 'Initial Quotation Price is too large.', 'snapshot' => null];
        }
        $fx = ca_resolve_fx($currency, $rateRaw, $currencies);
        if (!$fx['ok']) return ['ok' => false, 'message' => $fx['message'], 'snapshot' => null];
        return ['ok' => true, 'message' => '', 'snapshot' => quotation_snapshot($p['value'], $fx['currency'], $fx['rate'])];
    }
}

// $input: ['currency', 'last_price', 'fx_rate', 'gdrive_link', 'notes', 'taxonomy']
if (!function_exists('quotation_save_project_canvas')) {
    function quotation_save_project_canvas(PDO $pdo, array $session, string $canvassing_no, array $input, array $opts = []) {
        require_once __DIR__ . '/currency_helper.php';
        require_once __DIR__ . '/cost_avoidance_helper.php';
        require_once __DIR__ . '/cost_avoidance_history.php';
        $dry_run = !empty($opts['dry_run']);
        $confirm = !empty($opts['confirm_edit']);
        $source  = (string)($opts['source'] ?? 'MODAL');
        $canvassing_no = trim($canvassing_no);
        if ($canvassing_no === '') return quotation_save_result(false, 'Canvassing number is required.');

        $canv = quotation_canvassing_lines($pdo, $canvassing_no);
        if (!$canv['ok']) return quotation_save_result(false, $canv['message']);
        $lines = $canv['lines'];
        $p_group = $canv['p_group'];

        $auth = quotation_authorize_canvassing($pdo, $session, $p_group, $lines);
        if (!$auth['ok']) return quotation_save_result(false, $auth['message']);

        $team = quotation_project_team($pdo, $p_group);
        if ($team === null) return quotation_save_result(false, 'This is a GP Non-Project canvassing - Non-Project quotations are entered per ticket.');
        if (quotation_block_intercompany($lines)) return quotation_save_result(false, 'Intercompany tickets are routed via admin mappings and cannot be edited here.');

        $currencies = ca_get_active_currencies($pdo);
        $v = quotation_validate_price($input['last_price'] ?? '', $input['currency'] ?? '', $input['fx_rate'] ?? null, $currencies);
        if (!$v['ok']) return quotation_save_result(false, $v['message']);
        $new = $v['snapshot'];
        $tax = quotation_resolve_taxonomy($pdo, $team, $input['taxonomy'] ?? '');
        if (!$tax['ok']) return quotation_save_result(false, $tax['message']);

        $lk = ca_clean_link($input['gdrive_link'] ?? '');
        if (!$lk['ok']) return quotation_save_result(false, $lk['message']);
        $gdrive_link = $lk['value'];
        $notes       = trim((string)($input['notes'] ?? ''));

        // Already priced? (canvas row, or the legacy every-line-priced state.)
        $stmt = $pdo->prepare("SELECT last_price, currency, fx_rate, gdrive_link, notes FROM vendor_quotation_last_price WHERE scope = 'canvas' AND canvassing_no = ? AND last_price IS NOT NULL LIMIT 1");
        $stmt->execute([$canvassing_no]);
        $ex = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $legacy = $ex === null && quotation_project_canvas_finalized($pdo, $canvassing_no, $lines);
        $isEdit = $ex !== null || $legacy;
        $old = $ex !== null ? quotation_snapshot($ex['last_price'], $ex['currency'], $ex['fx_rate']) : null;
        $changed = false;
        if ($isEdit) {
            $changed = $legacy
                || ca_hist_price_changed($old, $new, quotation_current_rates($currencies))
                || (string)$ex['gdrive_link'] !== $gdrive_link
                || (string)$ex['notes'] !== $notes;
        }
        $changes = ($isEdit && $changed) ? [['key' => $canvassing_no, 'old' => $old, 'new' => $new]] : [];

        if ($dry_run) return quotation_save_result(true, '', count($lines), [], [], ['is_edit' => $isEdit, 'changes' => $changes]);
        if ($changes && !$confirm) {
            return quotation_save_result(false, 'This canvassing already has an Initial Quotation Price - please confirm the change.', 0, [], [],
                ['needs_confirm' => true, 'is_edit' => true, 'changes' => $changes]);
        }

        $user = $session['name'] ?? $session['username'] ?? ('user#' . ($session['user_id'] ?? '?'));
        $now  = date('Y-m-d H:i:s');
        $taxonomy_warnings = [];
        $baseline_warnings = [];

        $ownTxn = !$pdo->inTransaction();
        if ($ownTxn) $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO vendor_quotation_last_price
                    (scope, canvassing_no, last_price, currency, fx_rate, gdrive_link, notes, created_by, updated_by, created_at, updated_at)
                VALUES ('canvas', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    last_price = VALUES(last_price),
                    currency = VALUES(currency),
                    fx_rate = VALUES(fx_rate),
                    gdrive_link = VALUES(gdrive_link),
                    notes = VALUES(notes),
                    updated_by = VALUES(updated_by),
                    updated_at = VALUES(updated_at)
            ");
            $stmt->execute([$canvassing_no, $new['price'], $new['currency'], $new['fx_rate'], $gdrive_link, $notes, $user, $user, $now, $now]);

            // The dashboard's Initial Quotation Price is the source of truth for Cost
            // Avoidance's baseline (confirmed by user, 2026-09-17) -- mirror it
            // onto every line under this canvassing, together with the rate it was
            // entered at. AON items stay excluded, same rule Cost Avoidance
            // itself enforces.
            $stmt = $pdo->prepare("
                UPDATE prs p
                SET p.baseline_price = ?, p.baseline_currency = ?, p.baseline_fx_rate = ?, p.quotation_link = ?
                WHERE p.canvassing_no = ?
                AND " . ca_not_intercompany_sql('p') . "
                AND NOT (" . ca_is_aon_expr('p') . ")
            ");
            $stmt->execute([$new['price'], $new['currency'], $new['fx_rate'], $gdrive_link !== '' ? $gdrive_link : null, $canvassing_no]);
            // rowCount() only counts CHANGED rows, so ask directly whether any line
            // is eligible. If none is (every line AON / Intercompany) the price is
            // saved on the dashboard but does not count toward Cost Avoidance.
            $elig = $pdo->prepare("
                SELECT COUNT(*) FROM prs p
                WHERE p.canvassing_no = ?
                AND " . ca_not_intercompany_sql('p') . "
                AND NOT (" . ca_is_aon_expr('p') . ")
            ");
            $elig->execute([$canvassing_no]);
            if ((int)$elig->fetchColumn() === 0) {
                $baseline_warnings[$canvassing_no] = 'all lines are AON (contract) / Intercompany items - not counted toward Cost Avoidance.';
            }

            if (!$isEdit || $changed) {
                ca_hist_log($pdo, $session, CA_HIST_QUOTATION_PROJECT, $canvassing_no, $isEdit ? 'UPDATE' : 'CREATE', $old, $new, $source, [
                    'link'  => $ex !== null ? [(string)$ex['gdrive_link'], $gdrive_link] : $gdrive_link,
                    'notes' => $ex !== null ? [(string)$ex['notes'], $notes] : $notes,
                    'replaced_line_prices' => $legacy,
                ]);
            }

            // Taxonomy classifies each item+vendor pairing (Cost Avoidance's own
            // save_ca_taxonomy(), identical to Input Baselines' write), so apply
            // it once per distinct pairing on the canvassing. Access was already
            // gated above, hence team_scope=null. A failure (e.g. AON item) is
            // reported back but does not undo the price.
            $ctx = ca_context($pdo);
            $seen = [];
            foreach ($lines as $l) {
                $k = $l['item_description'] . "\x1F" . $l['vendor_name'] . "\x1F" . $l['uom'];
                if (isset($seen[$k])) continue;
                $seen[$k] = true;
                $r = ca_apply_save_ca_taxonomy($pdo, $ctx, null, [
                    'item'                => (string)$l['item_description'],
                    'vendor'              => (string)$l['vendor_name'],
                    'uom'                 => $l['uom'],
                    'team'                => $team,
                    'ca_taxonomy_level_2' => $tax['label'],
                ]);
                if (!$r['ok']) $taxonomy_warnings[$l['ticket_no'] . ' / line ' . $l['ticket_line_item']] = $r['error'];
            }
            if ($ownTxn) $pdo->commit();
        } catch (Exception $e) {
            if ($ownTxn && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return quotation_save_result(true, '', count($lines), $taxonomy_warnings, $baseline_warnings, ['is_edit' => $isEdit, 'changes' => $changes]);
    }
}

// $input: ['lines' => [['ticket_line_item','last_price','currency','fx_rate','gdrive_link','notes'], ...]]
// (a JSON string is accepted too, as the modal posts it).
// Lines are priced ONE BY ONE (changed 2026-10-02; it used to require every
// open line in one save): a line with a price in the payload is saved, a line
// left empty stays OPEN for later and gets no Cost Avoidance baseline. Lines
// that already have a price may be included to EDIT them; leaving them out
// leaves them untouched. A line that already has a price cannot be blanked.
if (!function_exists('quotation_save_ticket_items')) {
    function quotation_save_ticket_items(PDO $pdo, array $session, string $ticket_no, array $input, array $opts = []) {
        require_once __DIR__ . '/currency_helper.php';
        require_once __DIR__ . '/cost_avoidance_helper.php';
        require_once __DIR__ . '/cost_avoidance_history.php';
        $dry_run = !empty($opts['dry_run']);
        $confirm = !empty($opts['confirm_edit']);
        $source  = (string)($opts['source'] ?? 'MODAL');
        $ticket_no = trim($ticket_no);
        if ($ticket_no === '') return quotation_save_result(false, 'Ticket number is required.');

        $tk = quotation_ticket_lines($pdo, $ticket_no);
        if (!$tk['ok']) return quotation_save_result(false, $tk['message']);
        $lines = $tk['lines'];

        $auth = quotation_authorize_canvassing($pdo, $session, $tk['p_group'], $lines);
        if (!$auth['ok']) return quotation_save_result(false, $auth['message']);
        if (quotation_project_team($pdo, $tk['p_group']) !== null) {
            return quotation_save_result(false, 'This is a GP Project ticket - Project quotations are entered per canvassing.');
        }
        if (quotation_block_intercompany($lines)) return quotation_save_result(false, 'Intercompany tickets are routed via admin mappings and cannot be edited here.');

        $raw = $input['lines'] ?? [];
        if (is_string($raw)) $raw = json_decode($raw, true) ?: [];
        if (!is_array($raw)) $raw = [];
        $byLine = [];
        foreach ($raw as $line) {
            if (is_array($line)) $byLine[trim((string)($line['ticket_line_item'] ?? ''))] = $line;
        }

        // Saved prices of this ticket, per line.
        $stmt = $pdo->prepare("SELECT ticket_line_item, last_price, currency, fx_rate, gdrive_link, notes FROM vendor_quotation_last_price WHERE scope = 'item' AND ticket_no = ? AND last_price IS NOT NULL");
        $stmt->execute([$ticket_no]);
        $existing = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $existing[(string)$r['ticket_line_item']] = $r; }

        $currencies = ca_get_active_currencies($pdo);
        $rates = quotation_current_rates($currencies);
        // Every problem on the ticket is collected and reported together, so a
        // mixed-currency ticket is fixed in one go instead of one error at a time.
        $toSave = [];
        $lineErrors = []; // "Line N: ..."
        $priced = 0;      // lines in the payload that carry a price
        foreach ($lines as $l) {
            $li = (string)$l['ticket_line_item'];
            $ex = $existing[$li] ?? null;
            $in = $byLine[$li] ?? null;
            if ($in === null) continue;       // not in this save: stays as it is (open, or priced earlier)
            if (trim((string)($in['last_price'] ?? '')) === '') {
                if ($ex !== null) $lineErrors[] = "Line $li: Initial Quotation Price is required (this line already has a saved price).";
                elseif (trim((string)($in['gdrive_link'] ?? '')) !== '' || trim((string)($in['notes'] ?? '')) !== '') {
                    $lineErrors[] = "Line $li: Initial Quotation Price is required when a link or remark is filled in.";
                }
                continue;                     // untouched: this line stays open
            }
            $priced++;
            $v = quotation_validate_price($in['last_price'] ?? '', $in['currency'] ?? '', $in['fx_rate'] ?? null, $currencies);
            if (!$v['ok']) { $lineErrors[] = "Line $li: " . $v['message']; continue; }
            $new = $v['snapshot'];
            $lk = ca_clean_link($in['gdrive_link'] ?? '');
            if (!$lk['ok']) { $lineErrors[] = "Line $li: " . $lk['message']; continue; }
            $gdrive_link = $lk['value'];
            $notes       = trim((string)($in['notes'] ?? ''));
            $old = $ex !== null ? quotation_snapshot($ex['last_price'], $ex['currency'], $ex['fx_rate']) : null;
            $changed = $ex === null
                || ca_hist_price_changed($old, $new, $rates)
                || (string)$ex['gdrive_link'] !== $gdrive_link
                || (string)$ex['notes'] !== $notes;
            if (!$changed) continue;          // an edit that changes nothing is a no-op
            $toSave[] = ['line' => $l, 'existing' => $ex, 'old' => $old, 'new' => $new,
                         'gdrive_link' => $gdrive_link, 'notes' => $notes];
        }
        if ($lineErrors) return quotation_save_result(false, implode(' ', $lineErrors));
        if ($priced === 0) return quotation_save_result(false, 'Enter an Initial Quotation Price for at least one line.');

        $changes = [];
        foreach ($toSave as $s) {
            if ($s['existing'] === null) continue;
            $li = (string)$s['line']['ticket_line_item'];
            $changes[] = ['key' => ca_hist_key_ticket((string)$s['line']['ticket_no'], $li), 'line' => $li, 'old' => $s['old'], 'new' => $s['new']];
        }
        if ($dry_run) return quotation_save_result(true, '', count($toSave), [], [], ['is_edit' => !empty($existing), 'changes' => $changes]);
        if ($changes && !$confirm) {
            return quotation_save_result(false, 'This ticket already has an Initial Quotation Price - please confirm the change.', 0, [], [],
                ['needs_confirm' => true, 'is_edit' => true, 'changes' => $changes]);
        }

        $user = $session['name'] ?? $session['username'] ?? ('user#' . ($session['user_id'] ?? '?'));
        $now  = date('Y-m-d H:i:s');
        $baseline_warnings = [];

        $ownTxn = !$pdo->inTransaction();
        if ($ownTxn) $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO vendor_quotation_last_price
                    (scope, ticket_no, ticket_line_item, last_price, currency, fx_rate, gdrive_link, notes, created_by, updated_by, created_at, updated_at)
                VALUES ('item', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    last_price = VALUES(last_price),
                    currency = VALUES(currency),
                    fx_rate = VALUES(fx_rate),
                    gdrive_link = VALUES(gdrive_link),
                    notes = VALUES(notes),
                    updated_by = VALUES(updated_by),
                    updated_at = VALUES(updated_at)
            ");
            // Mirror onto the Cost Avoidance baseline for this exact line, every
            // save (confirmed by user, 2026-09-17), with the rate it was entered
            // at. AON items stay excluded.
            $baselineStmt = $pdo->prepare("
                UPDATE prs p
                SET p.baseline_price = ?, p.baseline_currency = ?, p.baseline_fx_rate = ?, p.quotation_link = ?
                WHERE p.ticket_no = ? AND p.ticket_line_item = ?
                AND NOT (" . ca_is_aon_expr('p') . ")
            ");
            foreach ($toSave as $s) {
                $tn = (string)$s['line']['ticket_no'];
                $li = (string)$s['line']['ticket_line_item'];
                $n  = $s['new'];
                $stmt->execute([$tn, $li, $n['price'], $n['currency'], $n['fx_rate'], $s['gdrive_link'], $s['notes'], $user, $user, $now, $now]);
                $baselineStmt->execute([$n['price'], $n['currency'], $n['fx_rate'], $s['gdrive_link'] !== '' ? $s['gdrive_link'] : null, $tn, $li]);
                if ($baselineStmt->rowCount() === 0) {
                    $baseline_warnings[$tn . ' / line ' . $li] = 'AON (contract) item - not counted toward Cost Avoidance.';
                }
                $ex = $s['existing'];
                ca_hist_log($pdo, $session, CA_HIST_QUOTATION_TICKET, ca_hist_key_ticket($tn, $li), $ex !== null ? 'UPDATE' : 'CREATE', $s['old'], $n, $source, [
                    'link'  => $ex !== null ? [(string)$ex['gdrive_link'], $s['gdrive_link']] : $s['gdrive_link'],
                    'notes' => $ex !== null ? [(string)$ex['notes'], $s['notes']] : $s['notes'],
                ]);
            }
            if ($ownTxn) $pdo->commit();
        } catch (Exception $e) {
            if ($ownTxn && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return quotation_save_result(true, '', count($toSave), [], $baseline_warnings, ['is_edit' => !empty($existing), 'changes' => $changes]);
    }
}

// Switch the CURRENCY of one PO from inside the Initial Quotation Price modal.
// The K2 import never carries a PO's currency, so every PO defaults to IDR; a PO
// really made in USD shows "Rp 14" instead of "USD 14". Only the currency label
// changes: po_amount keeps its number (14 stays 14, now read as USD).
// One PO is always one currency, so every line of that po_number is updated
// (across tickets), but only a PO that sits on THIS canvassing / ticket may be
// switched, by whoever may price it. Logged to cost_avoidance_edit_history
// (action 'PO_CUR') on the same keys the price history uses, in one transaction.
// $mode: 'project' (key = canvassing_no) | 'ticket' (key = ticket_no).
// Returns ['ok', 'message', 'changed', 'po_number', 'from', 'to', 'rows'].
if (!function_exists('quotation_change_po_currency')) {
    function quotation_change_po_currency(PDO $pdo, array $session, string $mode, string $key, string $po_number, string $currency): array {
        require_once __DIR__ . '/currency_helper.php';
        require_once __DIR__ . '/cost_avoidance_helper.php';
        require_once __DIR__ . '/cost_avoidance_history.php';
        $fail = function($msg) { return ['ok' => false, 'message' => $msg, 'changed' => false]; };
        $key = trim($key);
        $po_number = trim($po_number);
        $cur = strtoupper(trim($currency));
        if ($key === '' || $po_number === '') return $fail('PO number is required.');

        if ($mode === 'project') {
            $scope = quotation_canvassing_lines($pdo, $key);
            if (!$scope['ok']) return $fail($scope['message']);
            $auth = quotation_authorize_canvassing($pdo, $session, $scope['p_group'], $scope['lines']);
            if (!$auth['ok']) return $fail($auth['message']);
            if (quotation_project_team($pdo, $scope['p_group']) === null) return $fail('This is a GP Non-Project canvassing - open the quotation from the ticket instead.');
            $poRows = quotation_canvas_po_info($pdo, $key);
            $histType = CA_HIST_QUOTATION_PROJECT;
        } else {
            $scope = quotation_ticket_lines($pdo, $key);
            if (!$scope['ok']) return $fail($scope['message']);
            $auth = quotation_authorize_canvassing($pdo, $session, $scope['p_group'], $scope['lines']);
            if (!$auth['ok']) return $fail($auth['message']);
            if (quotation_project_team($pdo, $scope['p_group']) !== null) return $fail('This is a GP Project ticket - open the quotation from its canvassing instead.');
            $poRows = array_values(quotation_item_po_info($pdo, $key));
            $histType = CA_HIST_QUOTATION_TICKET;
        }
        if (quotation_block_intercompany($scope['lines'])) return $fail('Intercompany tickets are routed via admin mappings and cannot be edited here.');

        $onThis = array_values(array_filter($poRows, function($r) use ($po_number) { return $r['po_number'] === $po_number; }));
        if (!$onThis) return $fail('This PO is not on this ' . ($mode === 'project' ? 'canvassing' : 'ticket') . '.');

        // Only a currency the system can convert (IDR, or an active one with a rate).
        $active = ca_get_active_currencies($pdo);
        if ($cur !== 'IDR' && (!isset($active[$cur]) || (float)$active[$cur]['rate_to_idr'] <= 0)) {
            return $fail('That currency is not available.');
        }

        $stmt = $pdo->prepare("SELECT DISTINCT UPPER(TRIM(IFNULL(NULLIF(TRIM(po_currency),''),'IDR'))) FROM prs WHERE po_number = ?");
        $stmt->execute([$po_number]);
        $olds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $from = count($olds) === 1 ? (string)$olds[0] : (string)$onThis[0]['po_currency'];
        if (count($olds) === 1 && $from === $cur) {
            return ['ok' => true, 'message' => '', 'changed' => false, 'po_number' => $po_number, 'from' => $from, 'to' => $cur, 'rows' => 0];
        }

        $ownTxn = !$pdo->inTransaction();
        if ($ownTxn) $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("UPDATE prs SET po_currency = ? WHERE po_number = ?");
            $upd->execute([$cur, $po_number]);
            $rows = $upd->rowCount();

            $detail = ['kind' => 'PO_CURRENCY', 'po_number' => $po_number, 'from' => $from, 'to' => $cur,
                       'summary' => "PO $po_number currency: $from \u{2192} $cur (amounts unchanged)"];
            $keys = $mode === 'project' ? [$key] : array_map(function($r) { return ca_hist_key_ticket($r['ticket_no'], $r['line']); }, $onThis);
            foreach ($keys as $k) {
                ca_hist_log($pdo, $session, $histType, $k, 'PO_CUR', null, null, 'MODAL', $detail);
            }
            if ($ownTxn) $pdo->commit();
        } catch (Exception $e) {
            if ($ownTxn && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['ok' => true, 'message' => '', 'changed' => true, 'po_number' => $po_number, 'from' => $from, 'to' => $cur, 'rows' => $rows];
    }
}
