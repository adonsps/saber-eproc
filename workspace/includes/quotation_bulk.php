<?php
// includes/quotation_bulk.php
//
// BULK "Initial Quotation Price" (CSV) for the Manager / Project Head / PIC
// dashboards: Download Template -> fill in Excel -> Import. Endpoint:
// pages/ajax_quotation_bulk.php. The how-to-fill guide lives in the web UI
// (includes/quotation_bulk_ui.php), not in the file.
//
// CSV only (confirmed by user, 2026-09-29): opens on every laptop and a flat
// file can't lose a renamed/moved sheet. Two templates:
//   GP Project     -- one row per CANVASSING (unique key canvassing_no):
//                     canvassing_no, baseline_currency, baseline_price,
//                     exchange_rate, taxonomy, quotation_link, remark -- all
//                     written. (No item_description column: a canvassing has
//                     one price, and an old file that still has it is fine.)
//   quotation_link is free text (2026-10-01), not necessarily a URL.
//   Nothing is skipped silently: only a row with NOTHING filled in is
//   skipped (and still listed, with the reason); a row with anything filled
//   in but no price is an ERROR that says what is wrong.
//   GP Non-Project -- one row per open LINE (unique key ticket_no +
//                     ticket_line_item): ticket_no, ticket_line_item,
//                     baseline_currency, baseline_price, exchange_rate,
//                     quotation_link, remark -- remark goes to the line's Notes.
//   exchange_rate is OPTIONAL (column and cell): blank = today's rate from
//   Currency Settings, ignored for IDR -- exactly what the modal does. Price,
//   currency and rate all go through the same validation as the modal
//   (quotation_validate_price() / ca_resolve_fx()).
//
// Importing is the modal's save done in bulk: every unit goes through
// quotation_save_project_canvas() / quotation_save_ticket_items()
// (includes/vendor_quotation_helper.php), as a dry run for the preview and
// for real on confirm, so the rules are exactly the modal's. Per unit:
//   - untouched (no price, link or remark) -> skipped;
//   - Project: price + taxonomy required;
//   - Non-Project: lines are priced one by one and each line stands on its own
//     (changed 2026-10-02): an empty line stays open, a line with a problem is
//     reported and left out, and the good lines of the same ticket still import;
//   - any problem rejects THAT unit only; the others still import.

require_once __DIR__ . '/vendor_quotation_helper.php';
require_once __DIR__ . '/currency_helper.php';
require_once __DIR__ . '/cost_avoidance_helper.php';

// A full Non-Project template is ~20k lines for a Category Manager.
if (!defined('QB_MAX_ROWS')) define('QB_MAX_ROWS', 50000);

if (!function_exists('qb_columns')) {

// Header names ARE the import contract (matched by name, not position).
function qb_columns(string $type): array {
    return $type === 'project'
        ? ['canvassing_no', 'baseline_currency', 'baseline_price', 'exchange_rate', 'taxonomy', 'quotation_link', 'remark']
        : ['ticket_no', 'ticket_line_item', 'sku', 'item_description', 'qty', 'baseline_currency', 'baseline_price', 'exchange_rate', 'quotation_link', 'remark'];
}

function qb_norm_header($h): string {
    $h = preg_replace('/^\xEF\xBB\xBF/', '', (string)$h);
    return strtolower(preg_replace('/[^a-z0-9]+/i', '', $h));
}

// prs ticket numbers are upper-case; same fold as sla_bulk_norm_ticket().
function qb_norm_ticket($v): string {
    return strtoupper(trim(preg_replace('/\s+/', '', (string)$v)));
}

// Excel turns 0010 into 10 and sometimes 10 into 10.0; prs stores the plain
// integer form. Same fold as sla_bulk_norm_line().
function qb_norm_line($v): string {
    $v = trim((string)$v);
    if (preg_match('/^0*(\d+)(?:[.,]0+)?$/', $v, $m)) {
        return ltrim($m[1], '0') === '' ? '0' : ltrim($m[1], '0');
    }
    return $v;
}

/**
 * Lenient price parse for typed text like "1.250.000", "Rp 1,250,000",
 * "1.250.000,50", "1250000,5" (Indonesian Excel) or "1,250,000.50".
 * Returns ['ok'=>bool, 'value'=>?float] -- empty input is ok with value null.
 */
function qb_parse_price($v): array {
    if ($v === null) return ['ok' => true, 'value' => null];
    if (is_int($v) || is_float($v)) return ['ok' => true, 'value' => (float)$v];
    $s = trim((string)$v);
    if ($s === '') return ['ok' => true, 'value' => null];
    $s = preg_replace('/[^0-9.,\-]/', '', $s);
    if ($s === '' || $s === '-') return ['ok' => false, 'value' => null];
    $hasDot = strpos($s, '.') !== false;
    $hasComma = strpos($s, ',') !== false;
    if ($hasDot && $hasComma) {
        // Whichever separator comes last is the decimal one.
        if (strrpos($s, ',') > strrpos($s, '.')) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
        else { $s = str_replace(',', '', $s); }
    } elseif ($hasDot) {
        if (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s);
    } elseif ($hasComma) {
        $s = preg_match('/^-?\d{1,3}(,\d{3})+$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
    }
    if (!is_numeric($s)) return ['ok' => false, 'value' => null];
    return ['ok' => true, 'value' => (float)$s];
}

/**
 * The units a template of $type should list -- exactly what the user sees on
 * that dashboard, minus finalized, Intercompany and anything
 * quotation_authorize_canvassing() refuses.
 *   project:    ['rows' => [['canvassing_no', 'taxonomy' (prefill code)], ...]]
 *   nonproject: ['rows' => [['ticket_no', 'ticket_line_item'], ...]]  (open lines only)
 */
function quotation_bulk_worklist(PDO $pdo, array $session, string $type, string $source): array {
    $isPic = ($session['role'] ?? '') === 'PIC';
    $projectPg = [];
    foreach ($pdo->query("SELECT p_group FROM project_pgroups WHERE status = 'ACTIVE'")->fetchAll(PDO::FETCH_COLUMN) as $pg) { $projectPg[(string)$pg] = true; }

    // Candidate canvassings (project) / tickets (non-project).
    $keys = [];
    if ($isPic) {
        // Sourcing officer (dashboard_pic.php): canvassed lines on their own
        // worklist -- same filter as that page's query.
        $stmt = $pdo->prepare("
            SELECT DISTINCT canvassing_no, ticket_no, p_group
            FROM prs
            WHERE assigned_pic = ? AND canvassing_no IS NOT NULL AND canvassing_no != ''
              AND LOWER(status_ticket) NOT LIKE '%cancel%'
              AND (pr_date >= '2026-05-01' OR pr_date IS NULL OR (canvassing_no IS NOT NULL AND canvassing_no != '') OR (po_number IS NOT NULL AND po_number != ''))
        ");
        $stmt->execute([(string)($session['name'] ?? '')]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $isProject = isset($projectPg[(string)$r['p_group']]);
            if ($isProject !== ($type === 'project')) continue;
            $keys[trim((string)($isProject ? $r['canvassing_no'] : $r['ticket_no']))] = true;
        }
    } elseif ($type === 'project') {
        // Same scope as dashboard_project_head.php's MAIN FETCH (and the
        // Manager dashboard's Project rows), per line so a ticket split across
        // canvassings lists every one of them.
        $groups = get_project_head_groups($pdo, $session);
        if (!empty($groups)) {
            $ph = implode(',', array_fill(0, count($groups), '?'));
            $stmt = $pdo->prepare("
                SELECT DISTINCT prs.canvassing_no
                FROM prs
                JOIN project_pgroups pp ON prs.p_group = pp.p_group AND pp.status = 'ACTIVE'
                WHERE pp.project_group_number IN ($ph)
                  AND prs.canvassing_no IS NOT NULL AND prs.canvassing_no != ''
                  AND LOWER(prs.status_ticket) NOT LIKE '%cancel%'
                  AND (prs.pr_date >= '2026-05-01' OR prs.pr_date IS NULL OR (prs.canvassing_no IS NOT NULL AND prs.canvassing_no != '') OR (prs.po_number IS NOT NULL AND prs.po_number != ''))
            ");
            $stmt->execute($groups);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $c) { $keys[trim((string)$c)] = true; }
        }
    } else {
        // Non-Project (Manager dashboard): the persona-scoped line rows the
        // table itself shows -- tickets with at least one canvassed line.
        require_once __DIR__ . '/dashboard_data_helper.php';
        $result = get_dashboard_rows($pdo, $session, false);
        foreach ($result['rows'] as $r) {
            if (($r['_row_type'] ?? '') !== 'nonproject') continue;
            if (trim((string)($r['canvassing_no'] ?? '')) === '') continue;
            $t = trim((string)($r['ticket_no'] ?? ''));
            if ($t !== '') $keys[$t] = true;
        }
    }
    if (empty($keys)) return ['rows' => []];

    $authCache = [];
    $authorized = function(string $pg, array $lines) use ($pdo, $session, $isPic, &$authCache) {
        // A PIC's gate depends on the unit's own lines; everyone else's only
        // on the p_group, so that one is cached.
        if ($isPic) return quotation_authorize_canvassing($pdo, $session, $pg, $lines)['ok'];
        if (!isset($authCache[$pg])) $authCache[$pg] = quotation_authorize_ticket($pdo, $session, $pg)['ok'];
        return $authCache[$pg];
    };

    $rows = [];
    if ($type === 'project') {
        $linesByCanv = quotation_lines_for_canvassings($pdo, array_keys($keys));
        if (empty($linesByCanv)) return ['rows' => []];
        $ph = implode(',', array_fill(0, count($linesByCanv), '?'));
        $stmt = $pdo->prepare("SELECT canvassing_no FROM vendor_quotation_last_price WHERE scope = 'canvas' AND last_price IS NOT NULL AND canvassing_no IN ($ph)");
        $stmt->execute(array_keys($linesByCanv));
        $canvasPriced = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        $itemPriced = quotation_priced_item_keys($pdo, array_merge(...array_values($linesByCanv)));

        ksort($linesByCanv, SORT_NATURAL);
        foreach ($linesByCanv as $canv => $lines) {
            if (isset($canvasPriced[$canv])) continue;
            $pgs = array_unique(array_map('strval', array_column($lines, 'p_group')));
            if (count($pgs) !== 1 || !isset($projectPg[$pgs[0]])) continue;
            if (quotation_block_intercompany($lines)) continue;
            $open = 0;
            foreach ($lines as $l) { if (!isset($itemPriced[$l['ticket_no'] . '|' . $l['ticket_line_item']])) $open++; }
            if ($open === 0) continue;
            if (!$authorized($pgs[0], $lines)) continue;

            $rows[] = ['canvassing_no' => (string)$canv, 'taxonomy' => ''];
        }
        return ['rows' => $rows];
    }

    $linesByTicket = quotation_lines_for_tickets($pdo, array_keys($keys));
    if (empty($linesByTicket)) return ['rows' => []];
    $itemPriced = quotation_priced_item_keys($pdo, array_merge(...array_values($linesByTicket)));
    ksort($linesByTicket, SORT_NATURAL);
    foreach ($linesByTicket as $ticket => $lines) {
        $pgs = array_unique(array_map('strval', array_column($lines, 'p_group')));
        if (count($pgs) !== 1 || isset($projectPg[$pgs[0]])) continue;
        if (quotation_block_intercompany($lines)) continue;
        $open = array_filter($lines, function($l) use ($itemPriced) { return !isset($itemPriced[$l['ticket_no'] . '|' . $l['ticket_line_item']]); });
        if (empty($open)) continue;
        if (!$authorized($pgs[0], $lines)) continue;
        foreach ($open as $l) {
            $rows[] = ['ticket_no' => (string)$ticket, 'ticket_line_item' => (string)$l['ticket_line_item'],
                       'sku' => qb_short((string)($l['sku'] ?? ''), 60),
                       'item_description' => qb_short((string)($l['item_description'] ?? ''), 250),
                       // Reference only (never read back on import): baseline_price is per 1 unit.
                       'qty' => isset($l['qty']) ? rtrim(rtrim(number_format((float)$l['qty'], 4, '.', ''), '0'), '.') : ''];
        }
    }
    return ['rows' => $rows];
}

/** Streams the template CSV to the browser. */
function quotation_bulk_write_template(array $worklist, string $type, string $filename): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    // No "sep=," hint line: it showed up as an extra first row in Google Sheets.
    // The parser still accepts it, so templates downloaded earlier keep working.
    $cols = qb_columns($type);
    qb_fputcsv($out, $cols);
    foreach ($worklist['rows'] as $r) {
        $vals = [];
        foreach ($cols as $c) {
            if ($c === 'baseline_currency') { $vals[] = 'IDR'; continue; }
            $v = (string)($r[$c] ?? '');
            // Item names come from K2 free text: a leading = + - @ would be run as
            // a formula by Excel/Sheets, so neutralise it (reference columns only).
            if (($c === 'item_description' || $c === 'sku') && $v !== '' && strpbrk($v[0], '=+-@') !== false) $v = " " . $v;
            $vals[] = $v;
        }
        qb_fputcsv($out, $vals);
    }
    fclose($out);
}

// Single-line, trimmed, length-capped text for a reference column.
function qb_short(string $s, int $max): string {
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}

function qb_fputcsv($fh, array $vals): void {
    $cells = array_map(function($v) {
        $v = (string)$v;
        return preg_match('/[",\r\n;]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
    }, $vals);
    fwrite($fh, implode(',', $cells) . "\r\n");
}

/**
 * Parse an uploaded CSV. Tolerant of what Excel does when re-saving: UTF-8
 * BOM, the "sep=" line kept or dropped, ";" or tab instead of ",". Columns
 * are matched by header name. Returns ['ok', 'error', 'type', 'rows'] with
 * values normalised but NOT validated (quotation_bulk_classify does that).
 */
function quotation_bulk_parse_csv(string $path): array {
    $fail = function($msg) { return ['ok' => false, 'error' => $msg, 'type' => null, 'rows' => []]; };

    $raw = @file_get_contents($path);
    if ($raw === false) return $fail('Could not read the file.');
    if (substr($raw, 0, 2) === 'PK') return $fail('This looks like an Excel workbook. Please upload the CSV: in Excel use File > Save As > "CSV (Comma delimited)".');
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);

    $lines = explode("\n", $raw);
    $delim = null;
    $i = 0;
    // Skip blank lines and an optional "sep=X" line (which also names the delimiter).
    // Google Sheets / Excel turn that hint into a cell, so a re-exported file
    // starts with "sep=,,,,,," (trailing delimiters) -- accept that too, and skip
    // lines that hold nothing but delimiters.
    $sepRe = '/^"?sep=(.)"?[,;\t ]*$/i';
    while ($i < count($lines) && (trim($lines[$i]) === '' || preg_match('/^[,;\t" ]+$/', trim($lines[$i])) || preg_match($sepRe, trim($lines[$i])))) {
        if (preg_match($sepRe, trim($lines[$i]), $m)) $delim = $m[1];
        $i++;
    }
    if ($i >= count($lines)) return $fail('The file is empty.');
    $headerLine = $lines[$i];
    // Pick the delimiter that actually splits the header into known columns.
    $known = array_flip(array_map('qb_norm_header', array_merge(qb_columns('project'), qb_columns('nonproject'))));
    $best = 0;
    foreach (array_unique(array_filter([$delim, ',', ';', "\t"])) as $d) {
        $hits = count(array_filter(str_getcsv($headerLine, $d), function($h) use ($known) { return isset($known[qb_norm_header($h)]); }));
        if ($hits > $best) { $best = $hits; $delim = $d; }
    }
    if ($best === 0) return $fail('The file does not match the template (no known column headers found). Please start from a freshly downloaded template.');

    $header = array_map('qb_norm_header', str_getcsv($headerLine, $delim));
    $colIndex = [];
    foreach ($header as $idx => $h) { if ($h !== '' && !isset($colIndex[$h])) $colIndex[$h] = $idx; }
    $type = isset($colIndex['canvassingno']) && !isset($colIndex['ticketlineitem']) ? 'project'
          : (isset($colIndex['ticketno']) || isset($colIndex['ticketlineitem']) ? 'nonproject' : null);
    if ($type === null) return $fail('The file does not match either template: it needs canvassing_no (GP Project) or ticket_no + ticket_line_item (GP Non-Project).');
    $required = $type === 'project'
        ? ['canvassing_no', 'baseline_currency', 'baseline_price', 'taxonomy']
        : ['ticket_no', 'ticket_line_item', 'baseline_currency', 'baseline_price'];
    $missing = array_values(array_filter($required, function($c) use ($colIndex) { return !isset($colIndex[qb_norm_header($c)]); }));
    if (!empty($missing)) return $fail('Missing column(s): ' . implode(', ', $missing) . '. Please start from a freshly downloaded template.');

    // The rest of the file, parsed as CSV (quoted cells may hold line breaks).
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, implode("\n", array_slice($lines, $i + 1)));
    rewind($fh);
    $rows = [];
    $rowNumber = $i + 1;
    while (($cells = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        $rowNumber++;
        if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') continue;
        if (count($rows) >= QB_MAX_ROWS) { fclose($fh); return $fail('The file has more than ' . QB_MAX_ROWS . ' data rows. Please split it into smaller files.'); }
        $get = function($c) use ($colIndex, $cells) {
            $k = qb_norm_header($c);
            return isset($colIndex[$k]) ? trim((string)($cells[$colIndex[$k]] ?? '')) : '';
        };
        $row = [
            'row_number'        => $rowNumber,
            'baseline_currency' => strtoupper($get('baseline_currency')),
            'baseline_price'    => $get('baseline_price'),
            'exchange_rate'     => $get('exchange_rate'),
            'quotation_link'    => $get('quotation_link'),
            'remark'            => $get('remark'),
        ];
        if ($type === 'project') {
            $row['canvassing_no'] = strtoupper($get('canvassing_no'));
            $row['taxonomy'] = $get('taxonomy');
        } else {
            $row['ticket_no'] = qb_norm_ticket($get('ticket_no'));
            $row['ticket_line_item'] = qb_norm_line($get('ticket_line_item'));
        }
        $rows[] = $row;
    }
    fclose($fh);

    if (empty($rows)) return $fail('The file has no data rows.');
    return ['ok' => true, 'error' => null, 'type' => $type, 'rows' => $rows];
}

// Keeps only the fields the parser produces (confirm posts the rows back).
function quotation_bulk_clean_rows(array $rows, string $type): array {
    $clean = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $c = [
            'row_number'        => (int)($r['row_number'] ?? 0),
            'baseline_currency' => strtoupper(trim((string)($r['baseline_currency'] ?? ''))),
            'baseline_price'    => trim((string)($r['baseline_price'] ?? '')),
            'exchange_rate'     => trim((string)($r['exchange_rate'] ?? '')),
            'quotation_link'    => trim((string)($r['quotation_link'] ?? '')),
            'remark'            => trim((string)($r['remark'] ?? '')),
        ];
        if ($type === 'project') {
            $c['canvassing_no'] = strtoupper(trim((string)($r['canvassing_no'] ?? '')));
            $c['taxonomy'] = trim((string)($r['taxonomy'] ?? ''));
        } else {
            $c['ticket_no'] = qb_norm_ticket($r['ticket_no'] ?? '');
            $c['ticket_line_item'] = qb_norm_line($r['ticket_line_item'] ?? '');
        }
        $clean[] = $c;
    }
    return $clean;
}

/**
 * What a user filled in on one row, besides the prefilled cells: the names of
 * the columns, in plain words. baseline_currency counts only when it is not
 * IDR (IDR is the prefill). Taxonomy is not prefilled, so any taxonomy counts.
 */
function qb_filled_columns(array $r): array {
    $f = [];
    $cur = $r['baseline_currency'];
    if ($cur !== '' && $cur !== 'IDR') $f[] = "Currency ($cur)";
    if ($r['exchange_rate'] !== '') $f[] = 'Exchange Rate';
    if (isset($r['taxonomy']) && $r['taxonomy'] !== '') $f[] = 'Taxonomy';
    if ($r['quotation_link'] !== '') $f[] = 'Quotation Link';
    if ($r['remark'] !== '') $f[] = 'Remark';
    return $f;
}

/**
 * Every problem of ONE row, as plain sentences (never just the first one).
 * Returns ['blank' => nothing filled in, 'problems' => [...], 'price' => ?float].
 * Rules, same as the modal plus two the modal can't hit:
 *   - the price is the anchor: other cells filled but no price = a problem,
 *     never a silent skip;
 *   - IDR has no exchange rate, so one typed next to IDR is a problem.
 */
function qb_check_row(array $r, array $currencies): array {
    $out = ['blank' => false, 'problems' => [], 'price' => null];
    $others = qb_filled_columns($r);
    if ($r['baseline_price'] === '') {
        if (!$others) { $out['blank'] = true; return $out; }
        $list = count($others) > 1 ? implode(', ', array_slice($others, 0, -1)) . ' and ' . end($others) : $others[0];
        $out['problems'][] = "Initial Price is empty, but $list " . (count($others) > 1 ? 'are' : 'is') . ' filled in. Fill in the price, or clear those cells to skip this row.';
        return $out;
    }
    $p = qb_parse_price($r['baseline_price']);
    if (!$p['ok']) { $out['problems'][] = "Initial Price \"{$r['baseline_price']}\" is not a number."; return $out; }
    $cur = $r['baseline_currency'] !== '' ? $r['baseline_currency'] : 'IDR';
    if ($cur === 'IDR' && $r['exchange_rate'] !== '') {
        $out['problems'][] = 'Currency is IDR, so Exchange Rate must be empty. Clear the exchange rate, or change the currency.';
    }
    $v = quotation_validate_price(sprintf('%.4F', $p['value']), $cur, $r['exchange_rate'], $currencies);
    if (!$v['ok'] && !($cur === 'IDR' && $r['exchange_rate'] !== '')) {
        $out['problems'][] = str_replace('Initial Quotation Price', 'Initial Price', $v['message']);
    }
    $lk = ca_clean_link($r['quotation_link']);
    if (!$lk['ok']) $out['problems'][] = $lk['message'];
    $out['price'] = $p['value'];
    return $out;
}

// "Line 10, 20, 30: message" per distinct message, so 8 lines with the same
// mistake read as one sentence, not eight.
function qb_group_issues(array $issues, string $noun = 'Line'): string {
    $parts = [];
    foreach ($issues as $msg => $ids) {
        $ids = array_values(array_unique($ids));
        $shown = array_slice($ids, 0, 8);
        $label = $noun . (count($ids) > 1 ? 's ' : ' ') . implode(', ', $shown) . (count($ids) > count($shown) ? ' and ' . (count($ids) - count($shown)) . ' more' : '');
        $parts[] = "$label: $msg";
    }
    return implode(' ', $parts);
}

// "USD 1,250.00 @ 16,250 (= Rp 20,312,500)" / "IDR 1,250,000.00" -- a price
// snapshot as the preview shows it, rate and IDR equivalent included.
function qb_money_summary(array $snap): string {
    $txt = $snap['currency'] . ' ' . number_format((float)$snap['price'], 2, '.', ',');
    if ($snap['currency'] !== 'IDR' && !empty($snap['fx_rate'])) {
        $txt .= ' @ ' . rtrim(rtrim(number_format((float)$snap['fx_rate'], 4, '.', ','), '0'), '.')
              . ' (= Rp ' . number_format((float)$snap['price'] * (float)$snap['fx_rate'], 0, '.', ',') . ')';
    }
    return $txt;
}

function qb_change_text(?array $old, array $new): string {
    return ($old === null ? '(none)' : qb_money_summary($old)) . ' -> ' . qb_money_summary($new);
}

/**
 * Validate parsed rows against the LIVE database, one unit (canvassing or
 * ticket) at a time. Writes nothing. Each unit comes back as:
 *   ['key', 'status' => READY|SKIPPED|ERROR, 'message', 'lines', 'summary',
 *    'input' (what confirm would save), 'row_numbers']
 */
function quotation_bulk_classify(PDO $pdo, array $session, array $rows, string $type): array {
    $groups = [];
    foreach ($rows as $r) {
        $k = trim((string)($type === 'project' ? $r['canvassing_no'] : $r['ticket_no']));
        $groups[$k === '' ? '(blank)' : $k][] = $r;
    }

    $currencies = ca_get_active_currencies($pdo);
    $out = [];
    foreach ($groups as $key => $grows) {
        $res = ['key' => (string)$key, 'status' => 'ERROR', 'message' => '', 'lines' => count($grows),
                'summary' => '', 'input' => null, 'row_numbers' => array_column($grows, 'row_number')];
        $err = function($msg) use (&$res) { $res['status'] = 'ERROR'; $res['message'] = $msg; return $res; };
        $col = $type === 'project' ? 'canvassing_no' : 'ticket_no';
        $problemText = '';

        if ($key === '(blank)') {
            $filled = array_filter($grows, function($r) use ($currencies) { return !qb_check_row($r, $currencies)['blank']; });
            if (!$filled) { $res['status'] = 'SKIPPED'; $res['message'] = "Skipped: row(s) " . implode(', ', $res['row_numbers']) . " have no $col and nothing else filled in."; $out[] = $res; continue; }
            $out[] = $err('Row(s) ' . implode(', ', $res['row_numbers']) . " have no $col, so they cannot be matched to anything.");
            continue;
        }

        if ($type === 'project') {
            if (count($grows) > 1) { $out[] = $err("canvassing_no appears on " . count($grows) . ' rows (' . implode(', ', $res['row_numbers']) . '). Keep only one row per canvassing.'); continue; }
            $g = $grows[0];
            // The canvassing's team decides which taxonomy codes are valid.
            $canv = quotation_canvassing_lines($pdo, (string)$key);
            $team = $canv['ok'] ? quotation_project_team($pdo, $canv['p_group']) : null;
            $tax = ['ok' => false, 'label' => null, 'message' => ''];
            if ($canv['ok'] && $team !== null) {
                if ($g['taxonomy'] !== '') $tax = quotation_resolve_taxonomy($pdo, $team, $g['taxonomy']);
            }
            $chk = qb_check_row($g, $currencies);
            if ($chk['blank']) { $res['status'] = 'SKIPPED'; $res['message'] = "Skipped: row {$g['row_number']} has no price and nothing else filled in."; $out[] = $res; continue; }
            $problems = $chk['problems'];
            if ($chk['price'] !== null && $canv['ok'] && $team !== null) {
                if ($g['taxonomy'] === '') {
                    $problems[] = "Taxonomy is empty. This canvassing belongs to team $team, so use one of: " . implode(', ', array_filter(array_column(quotation_taxonomy_options($pdo, $team), 'code'), 'strlen')) . '.';
                } elseif (!$tax['ok']) {
                    $problems[] = $tax['message'];
                }
            }
            if ($problems) { $out[] = $err("Row {$g['row_number']}: " . implode(' ', $problems)); continue; }
            $p = ['value' => $chk['price']];
            $input = [
                'last_price'  => $p['value'] === null ? '' : sprintf('%.4F', $p['value']),
                'currency'    => $g['baseline_currency'] !== '' ? $g['baseline_currency'] : 'IDR',
                'fx_rate'     => $g['exchange_rate'],
                'taxonomy'    => $g['taxonomy'],
                'gdrive_link' => $g['quotation_link'],
                'notes'       => $g['remark'],
            ];
            $dry = quotation_save_project_canvas($pdo, $session, (string)$key, $input, ['dry_run' => true]);
            if (!$dry['ok']) { $out[] = $err($dry['message']); continue; }
            $canv = quotation_canvassing_lines($pdo, (string)$key);
            $tax = quotation_resolve_taxonomy($pdo, quotation_project_team($pdo, $canv['p_group']), $g['taxonomy']);
            $res['lines'] = count($canv['lines']);
            $snap = quotation_validate_price($input['last_price'], $input['currency'], $input['fx_rate'], $currencies)['snapshot'];
            $res['summary'] = qb_money_summary($snap) . ' for the whole canvassing · ' . $tax['label'];
            if (!empty($dry['changes'])) {
                $res['is_edit'] = true;
                $res['change_text'] = qb_change_text($dry['changes'][0]['old'], $dry['changes'][0]['new']);
                $res['message'] = 'Already priced - will be CHANGED: ' . $res['change_text'];
            }
        } else {
            // Each line stands on its own: a line with a problem is reported and left
            // out, the good lines of the same ticket still import.
            $byLine = [];
            $issues = [];    // message => [line, ...], so equal mistakes read as one sentence
            $other  = [];    // problems that belong to a row, not to a line number
            $checks = [];
            foreach ($grows as $g) { $checks[] = qb_check_row($g, $currencies); }
            if (count(array_filter($checks, function($c) { return !$c['blank']; })) === 0) {
                $res['status'] = 'SKIPPED';
                $res['message'] = 'Skipped: no price, exchange rate, taxonomy, link or remark filled in on any line of this ticket.';
                $out[] = $res; continue;
            }
            $tk = quotation_ticket_lines($pdo, (string)$key);
            if (!$tk['ok']) { $out[] = $err($tk['message']); continue; }
            $dbLines = array_flip(array_map('strval', array_column($tk['lines'], 'ticket_line_item')));
            // A line number listed twice cannot be trusted: none of its copies is imported.
            $rowsOf = [];
            foreach ($grows as $g) { if ($g['ticket_line_item'] !== '') $rowsOf[$g['ticket_line_item']][] = $g['row_number']; }
            foreach ($grows as $n => $g) {
                $li = $g['ticket_line_item'];
                $chk = $checks[$n];
                if ($chk['blank']) continue;   // an empty line just stays open
                if ($li === '') { $other[] = "Row {$g['row_number']}: the line item number is empty."; continue; }
                if (count($rowsOf[$li]) > 1) { $issues['listed more than once (rows ' . implode(', ', $rowsOf[$li]) . '). Fill it in only once.'][] = $li; continue; }
                if (!isset($dbLines[$li])) { $issues['not on this ticket (or it is cancelled).'][] = $li; continue; }
                if ($chk['problems']) { foreach ($chk['problems'] as $msg) { $issues[$msg][] = $li; } continue; }
                $byLine[$li] = [
                    'ticket_line_item' => $li,
                    'last_price'       => sprintf('%.4F', $chk['price']),
                    'currency'         => $g['baseline_currency'] !== '' ? $g['baseline_currency'] : 'IDR',
                    'fx_rate'          => $g['exchange_rate'],
                    'gdrive_link'      => $g['quotation_link'],
                    'notes'            => $g['remark'],
                ];
            }
            $problemText = trim(implode(' ', array_merge($other, $issues ? [qb_group_issues($issues)] : [])));
            if (!$byLine) { $out[] = $err($problemText !== '' ? $problemText : 'No line could be imported.'); continue; }
            $priced = quotation_priced_item_keys($pdo, $tk['lines']);
            $input = ['lines' => array_values($byLine)];
            $dry = quotation_save_ticket_items($pdo, $session, (string)$key, $input, ['dry_run' => true]);
            if (!$dry['ok']) { $out[] = $err($dry['message']); continue; }
            $totals = [];
            foreach ($byLine as $l) {
                $snap = quotation_validate_price($l['last_price'], $l['currency'], $l['fx_rate'], $currencies)['snapshot'];
                $totals[$snap['currency']] = ($totals[$snap['currency']] ?? 0) + $snap['price'];
            }
            $parts = [];
            foreach ($totals as $cur => $sum) { $parts[] = $cur . ' ' . number_format($sum, 2, '.', ','); }
            $res['summary'] = count($byLine) . ' line' . (count($byLine) === 1 ? '' : 's') . ', sum of unit prices ' . implode(' + ', $parts);
            if (!empty($dry['changes'])) {
                $res['is_edit'] = true;
                $bits = array_map(function($c) { return 'line ' . $c['line'] . ': ' . qb_change_text($c['old'], $c['new']); }, $dry['changes']);
                $res['change_text'] = implode('; ', $bits);
                $res['message'] = 'Already priced - will be CHANGED: ' . $res['change_text'];
            }
            $untouched = count($priced) - count($dry['changes']);
            if ($untouched > 0) $res['message'] .= ($res['message'] !== '' ? ' ' : '') . $untouched . ' already-priced line(s) left unchanged.';
            // Lines not priced now and not priced before: they stay open.
            $stillOpen = 0;
            foreach ($tk['lines'] as $l) {
                if (!isset($priced[$l['ticket_no'] . '|' . $l['ticket_line_item']]) && !isset($byLine[(string)$l['ticket_line_item']])) $stillOpen++;
            }
            if ($stillOpen > 0) $res['message'] .= ($res['message'] !== '' ? ' ' : '') . $stillOpen . ' other line' . ($stillOpen === 1 ? '' : 's') . ' of this ticket stay open.';
        }

        if (!empty($problemText)) { $res['partial'] = true; $res['problem_text'] = $problemText; }
        $res['status'] = 'READY';
        $res['input'] = $input;
        $out[] = $res;
    }

    // Errors first, then ready, then skipped -- what needs attention on top.
    $rank = ['ERROR' => 0, 'READY' => 1, 'SKIPPED' => 2];
    usort($out, function($a, $b) use ($rank) {
        return [$rank[$a['status']], $a['key']] <=> [$rank[$b['status']], $b['key']];
    });
    return $out;
}

// What the web guide shows: the active currencies and, per Project team the
// user works on, its taxonomy codes.
function quotation_bulk_guide_data(PDO $pdo, array $teams): array {
    // code => current rate, only for currencies that can actually be converted.
    $currencies = [];
    foreach (ca_get_active_currencies($pdo) as $code => $c) {
        if ($code !== 'IDR' && (float)$c['rate_to_idr'] <= 0) continue;
        $currencies[$code] = (float)$c['rate_to_idr'];
    }
    $taxonomy = [];
    foreach ($teams as $t) { $taxonomy[$t] = quotation_taxonomy_options($pdo, $t); }
    return ['currencies' => $currencies, 'taxonomy' => $taxonomy];
}

}
