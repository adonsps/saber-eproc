<?php
// includes/sla_bulk_request.php
//
// BULK SLA REQUEST (CSV) — shared parse + validation logic for
// pages/ajax_sla_bulk_request.php.
//
// Why it exists: many lines breached their SLA for reasons outside the PIC's
// control (wrong automated PIC assignment, taxonomy mismatch, K2 outages). Filing
// those one inline edit at a time on SLA Tracking does not scale, so a PIC /
// Manager / Category Manager can upload a CSV of lines instead.
//
// What a bulk row IS: an ordinary 'sla_status' request (SLA Breached -> Within
// SLA) in sla_revision_requests, status 'pending'. Nothing new downstream — it
// shows up in the Manage SLA Request inbox, is decided by ajax_sla_request_decide.php
// and applied by sla_rev_apply_approved(), exactly like one filed by hand. The
// only difference from the inline flow is that a bulk upload is NEVER
// auto-approved, whatever the uploader's role: the feature was asked for as
// "submit, then approve", and a CSV is the one place a single click can touch
// hundreds of lines.
//
// Same two-step shape as the Master Data importer (includes/master_data_import.php):
//   sla_bulk_parse_csv()     — file -> plain row arrays. Only checks CSV SHAPE
//                               (headers, empty file, row cap).
//   sla_bulk_classify_rows() — every business rule, against the LIVE database.
//                               Called on upload (validate) AND again on confirm
//                               against what the browser sent back, so confirm
//                               can never write something validate would not
//                               also have accepted.
//
// Every CSV row lands in exactly one status:
//   READY   — passes every rule below; default: submit.
//   INVALID — fails at least one; never submitted. 'errors' says why.
//
// Rules, in the order they are checked:
//   1. ticket_no / line_item present, remarks + link well-formed (the same
//      sla_rev_validate() the inline request goes through).
//   2. The ticket exists in `prs`, and so does the line item on that ticket.
//   3. The same ticket + line appears only ONCE in the file.
//   4. The line is on the uploader's own SLA Tracking list (persona scope; a PIC
//      only their own lines) — same row set ajax_sla_request_submit.php uses.
//   5. The line has no request already waiting for approval (any type).
//   6. The line is currently SLA Breached — the one eligibility rule of the
//      whole revision workflow (sla_rev_line_editability()).
//
// MUST be loaded after includes/sla_helper.php (uses resolve_sla_persona() /
// get_sla_computed_rows() / sla_rev_*), same convention as sla_rev_requestable_lines().

// Hard cap on data rows per file. get_sla_computed_rows() is built once per call
// regardless, so the cap is about the preview and the approver's inbox, not the DB.
if (!defined('SLA_BULK_MAX_ROWS')) define('SLA_BULK_MAX_ROWS', 1000);

// Remarks longer than this are almost certainly a pasted cell gone wrong; the
// inline popover caps the same field at 1000 (maxlength on #slaEditPopRemarks).
if (!defined('SLA_BULK_MAX_REMARKS')) define('SLA_BULK_MAX_REMARKS', 1000);
// Must equal the minimum sla_rev_validate() enforces for a single request.
if (!defined('SLA_BULK_MIN_REMARKS')) define('SLA_BULK_MIN_REMARKS', 10);

// Recognisable ticket prefix of the template's example row, so a user who forgets
// to delete it gets a message that says so instead of a bare "not found".
if (!defined('SLA_BULK_EXAMPLE_TICKET')) define('SLA_BULK_EXAMPLE_TICKET', 'EXAMPLE-');

if (!function_exists('sla_bulk_columns')) {

/**
 * Canonical column => accepted header spellings. Header matching is
 * case-insensitive and treats spaces / hyphens as underscores, so "Ticket No",
 * "ticket-no" and "TICKET_NO" all read as ticket_no.
 */
function sla_bulk_columns(): array {
    return [
        'ticket_no' => ['ticket_no', 'ticket_number', 'ticket', 'pr_number', 'pr_no'],
        'line_item' => ['line_item', 'ticket_line_item', 'line', 'line_no', 'item_line'],
        'remarks'   => ['remarks', 'remark', 'reason'],
        'link'      => ['link', 'evidence_link', 'evidence_url', 'url'],
    ];
}

// The link column may be left out of the file entirely — it is optional per row anyway.
function sla_bulk_required_columns(): array {
    return ['ticket_no', 'line_item', 'remarks'];
}

/** The downloadable template: header + one example row the user overwrites. */
function sla_bulk_template_csv(string $delimiter = ','): string {
    $rows = [
        ['ticket_no', 'line_item', 'remarks', 'link'],
        [SLA_BULK_EXAMPLE_TICKET . 'T2604A98017', '10',
         'Delayed by K2 system malfunction - approval stuck in K2 for 5 days (delete this example row)',
         'https://drive.google.com/your-evidence-link'],
    ];
    $fh = fopen('php://temp', 'r+');
    foreach ($rows as $r) fputcsv($fh, $r, $delimiter);
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    // UTF-8 BOM so Excel opens it as UTF-8 instead of guessing a code page.
    return "\xEF\xBB\xBF" . $csv;
}

// Header cell -> lookup form used against sla_bulk_columns().
function sla_bulk_norm_header($h): string {
    $h = preg_replace('/^\xEF\xBB\xBF/', '', (string)$h);
    $h = strtolower(trim($h));
    return preg_replace('/[\s\-]+/', '_', $h);
}

// Excel on a Windows box saves "CSV" in the ANSI code page, not UTF-8. A single
// accented character in a remark would then make json_encode() fail on the whole
// response, so anything that is not valid UTF-8 is read as Windows-1252.
function sla_bulk_to_utf8($s): string {
    $s = (string)$s;
    if ($s === '' || mb_check_encoding($s, 'UTF-8')) return $s;
    return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

// prs ticket numbers are upper-case everywhere (checked against the live table);
// the column collation is case-insensitive, but the in-memory maps here are not.
function sla_bulk_norm_ticket($v): string {
    return strtoupper(trim(preg_replace('/\s+/', '', (string)$v)));
}

// Excel "helpfully" turns 0010 into 10 and sometimes 10 into 10.0. prs stores the
// plain integer form, so both are folded back to it. Anything non-numeric is
// left as typed and simply won't match.
function sla_bulk_norm_line($v): string {
    $v = trim((string)$v);
    if (preg_match('/^0*(\d+)(?:\.0+)?$/', $v, $m)) {
        return ltrim($m[1], '0') === '' ? '0' : ltrim($m[1], '0');
    }
    return $v;
}

/**
 * Picks the delimiter when the user leaves it on "Auto": whichever candidate
 * splits the header line into the most known column names. Excel in an
 * Indonesian locale saves CSV with ';', which is the common case this covers.
 */
function sla_bulk_detect_delimiter(string $firstLine): string {
    $known = [];
    foreach (sla_bulk_columns() as $aliases) foreach ($aliases as $a) $known[$a] = true;

    $best = ',';
    $bestHits = -1;
    foreach ([',', ';', "\t", '|'] as $d) {
        $hits = 0;
        foreach (str_getcsv($firstLine, $d) as $cell) {
            if (isset($known[sla_bulk_norm_header($cell)])) $hits++;
        }
        if ($hits > $bestHits) { $best = $d; $bestHits = $hits; }
    }
    return $best;
}

/**
 * Parse an uploaded CSV into
 *   ['ok' => bool, 'error' => ?string, 'delimiter' => string,
 *    'rows' => [ ['row_number'=>int, 'ticket_no'=>, 'line_item'=>, 'remarks'=>, 'link'=>], ... ]]
 * $delimiter '' means auto-detect. Row-level problems are NOT reported here —
 * see sla_bulk_classify_rows(). Values are returned as typed (trimmed, UTF-8);
 * normalisation happens in classify so the preview can show what was uploaded.
 */
function sla_bulk_parse_csv(string $filePath, string $delimiter = ''): array {
    $fail = function ($msg) { return ['ok' => false, 'error' => $msg, 'delimiter' => ',', 'rows' => []]; };

    if (!is_file($filePath) || !is_readable($filePath)) return $fail('Could not read the uploaded file.');
    $handle = fopen($filePath, 'r');
    if ($handle === false) return $fail('Could not read the uploaded file.');

    // Skip a UTF-8 BOM (Excel writes one) at the STREAM level, before fgetcsv
    // sees it: left in place, a quoted first header ("Ticket No") is read with its
    // quotes as literal characters and no longer matches any column name.
    $start = (fread($handle, 3) === "\xEF\xBB\xBF") ? 3 : 0;
    fseek($handle, $start);

    if ($delimiter === '') {
        $first = fgets($handle);
        if ($first === false) { fclose($handle); return $fail('The CSV file is empty.'); }
        $delimiter = sla_bulk_detect_delimiter(sla_bulk_to_utf8($first));
        fseek($handle, $start);
    }

    $header = fgetcsv($handle, 0, $delimiter);
    if ($header === false || $header === null || (count($header) === 1 && trim((string)$header[0]) === '')) {
        fclose($handle);
        return $fail('The CSV file is empty.');
    }

    // header cell -> canonical column name
    $alias_to_col = [];
    foreach (sla_bulk_columns() as $col => $aliases) foreach ($aliases as $a) $alias_to_col[$a] = $col;

    $colIndex = [];
    $unexpected = [];
    foreach ($header as $i => $h) {
        $n = sla_bulk_norm_header(sla_bulk_to_utf8($h));
        if ($n === '') continue; // trailing empty column from Excel
        if (!isset($alias_to_col[$n])) { $unexpected[] = trim((string)$h); continue; }
        $col = $alias_to_col[$n];
        if (isset($colIndex[$col])) {
            fclose($handle);
            return $fail('The column "' . $col . '" appears more than once in the header.');
        }
        $colIndex[$col] = $i;
    }
    $missing = array_values(array_diff(sla_bulk_required_columns(), array_keys($colIndex)));
    if (!empty($missing) || !empty($unexpected)) {
        fclose($handle);
        $msg = 'CSV column headers do not match the template (ticket_no, line_item, remarks, link).';
        if (!empty($missing))    $msg .= ' Missing: ' . implode(', ', $missing) . '.';
        if (!empty($unexpected)) $msg .= ' Unexpected: ' . implode(', ', $unexpected) . '.';
        if (count($header) === 1) $msg .= ' If the file uses ";" or tabs, choose that delimiter (or Auto-detect).';
        return $fail($msg);
    }

    $rows = [];
    $row_number = 1; // header is row 1, so the first data row is row 2 — matches what Excel shows
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $row_number++;
        // Blank line, or a row Excel left with nothing but delimiters.
        if (implode('', array_map('trim', array_map('strval', $data))) === '') continue;

        if (count($rows) >= SLA_BULK_MAX_ROWS) {
            fclose($handle);
            return $fail('The file has more than ' . SLA_BULK_MAX_ROWS . ' data rows. Please split it into smaller files.');
        }

        $row = ['row_number' => $row_number];
        foreach (array_keys(sla_bulk_columns()) as $col) {
            $row[$col] = isset($colIndex[$col], $data[$colIndex[$col]])
                ? trim(sla_bulk_to_utf8($data[$colIndex[$col]]))
                : '';
        }
        $rows[] = $row;
    }
    fclose($handle);

    if (empty($rows)) return $fail('The CSV file has no data rows.');

    return ['ok' => true, 'error' => null, 'delimiter' => $delimiter, 'rows' => $rows];
}

/**
 * Validate every row against the live database. Never writes anything — safe
 * to call on upload and again on confirm.
 *
 * $rows is the parse output (or the browser's copy of it on confirm).
 * $session is $_SESSION — the persona and PIC-ownership rules read it.
 *
 * Returns:
 *   [
 *     'summary' => ['total_rows', 'ready', 'invalid', 'not_found', 'duplicate', 'not_eligible'],
 *     'rows'    => [
 *        [ 'row_number', 'ticket_no', 'line_item',     // canonical when found, else as typed
 *          'remarks', 'link', 'status' => 'READY'|'INVALID',
 *          'errors'  => [ ['type' => ..., 'title' => ..., 'message' => ...], ... ],  // most fundamental first
 *          'item', 'pic', 'current_status', 'duration',  // context for the preview, blank if not found
 *          'status_note',                                 // why current_status is blank, e.g. 'Ticket not found'
 *          'clean'   => ['remarks' => , 'evidence_url' => ]   // READY only — what confirm writes
 *        ], ...
 *     ],
 *   ]
 * error types: format | example | not_found | duplicate | scope | pending | not_eligible
 */
function sla_bulk_classify_rows(PDO $pdo, array $session, array $rows): array {
    $role = $session['role'] ?? '';
    $name = trim((string)($session['name'] ?? ''));

    // --- Pass 1: normalise keys, collect distinct tickets -------------------
    $tickets = [];
    foreach ($rows as &$r) {
        $r['_t'] = sla_bulk_norm_ticket($r['ticket_no'] ?? '');
        $r['_l'] = sla_bulk_norm_line($r['line_item'] ?? '');
        if ($r['_t'] !== '' && strpos($r['_t'], SLA_BULK_EXAMPLE_TICKET) !== 0) $tickets[$r['_t']] = true;
    }
    unset($r);

    // --- Existence: every line of every ticket named in the file -------------
    // One IN() per 500 tickets instead of a query per row. Carries pr_date and
    // assigned_pic so a line that exists but is not on SLA Tracking can be told
    // apart from one that does not exist at all.
    $prs_lines = [];   // TICKET => [ line => ['ticket_no','ticket_line_item','pr_date','assigned_pic'] ]
    foreach (array_chunk(array_keys($tickets), 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare("SELECT ticket_no, ticket_line_item, pr_date, assigned_pic
                                 FROM prs WHERE ticket_no IN ($ph)");
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $prs_lines[strtoupper(trim((string)$p['ticket_no']))][sla_bulk_norm_line($p['ticket_line_item'])] = $p;
        }
    }

    // --- Duplicates inside the file ------------------------------------------
    $seen = []; // TICKET|line => [row_numbers]
    foreach ($rows as $r) {
        if ($r['_t'] === '' || $r['_l'] === '') continue;
        $seen[$r['_t'] . '|' . $r['_l']][] = (int)$r['row_number'];
    }

    // --- The uploader's own SLA Tracking rows --------------------------------
    // Same persona-scoped, override-aware row set the page renders and the
    // single-request endpoint checks against: statusName is the EFFECTIVE
    // verdict, pending_req the any-type pending request.
    $computed = get_sla_computed_rows($pdo, resolve_sla_persona($pdo, $session));
    $sla_rows = [];
    foreach ($computed['rows'] as $cr) {
        $sla_rows[sla_rev_line_key($cr['ticket_no'], $cr['ticket_line_item'])] = $cr;
    }

    $type_labels = sla_rev_type_labels();
    $out = [];
    $summary = ['total_rows' => 0, 'ready' => 0, 'invalid' => 0, 'not_found' => 0, 'duplicate' => 0, 'not_eligible' => 0];

    foreach ($rows as $r) {
        // Each error is a short title (what is wrong) plus a detail line (the
        // specifics / how to fix it), so the preview reads at a glance.
        $errors = [];
        $err = function ($type, $title, $msg) use (&$errors) {
            $errors[] = ['type' => $type, 'title' => $title, 'message' => $msg];
        };

        $entry = [
            'row_number'     => (int)$r['row_number'],
            'ticket_no'      => (string)($r['ticket_no'] ?? ''),
            'line_item'      => (string)($r['line_item'] ?? ''),
            'remarks'        => (string)($r['remarks'] ?? ''),
            'link'           => (string)($r['link'] ?? ''),
            'item'           => '',
            'pic'            => '',
            'current_status' => '',
            // Shown in the Current Status column when there is no status to show
            // (ticket/line not found, not on the user's list) — says why instead of "-".
            'status_note'    => '',
            'duration'       => '',
        ];

        // 1. Shape.
        if ($r['_t'] === '') { $err('format', 'Ticket number missing', 'Fill in the ticket_no column.'); $entry['status_note'] = 'No ticket number'; }
        if ($r['_l'] === '') { $err('format', 'Line item missing', 'Fill in the line_item column.'); if ($entry['status_note'] === '') $entry['status_note'] = 'No line item'; }

        // Remarks are judged here, on their own, so the message can say exactly how
        // short they are. Same minimum as sla_rev_validate().
        $rem_len = mb_strlen($entry['remarks']);
        if ($rem_len === 0) {
            $err('format', 'Remarks missing', 'Explain why this line needs adjusting (at least ' . SLA_BULK_MIN_REMARKS . ' characters).');
        } elseif ($rem_len < SLA_BULK_MIN_REMARKS) {
            $err('format', 'Remarks too short', $rem_len . ' character' . ($rem_len === 1 ? '' : 's') . ' — at least ' . SLA_BULK_MIN_REMARKS . ' are required.');
        } elseif ($rem_len > SLA_BULK_MAX_REMARKS) {
            $err('format', 'Remarks too long', $rem_len . ' characters — maximum ' . SLA_BULK_MAX_REMARKS . '.');
        }

        // The link goes through the SAME validator as the inline request so the
        // http(s)-only rule cannot drift. sla_rev_validate() stops at its first
        // failure, so it is handed a placeholder that passes the remarks check —
        // remarks were already judged above, and a short remark must not hide a
        // bad link behind it.
        list($link_ok, $link_res) = sla_rev_validate([
            'request_type'    => 'sla_status',
            'requested_value' => 'Within SLA',
            'remarks'         => str_repeat('x', SLA_BULK_MIN_REMARKS),
            'evidence_url'    => $entry['link'],
        ]);
        if (!$link_ok) $err('format', 'Invalid link', $link_res);

        $is_example = ($r['_t'] !== '' && strpos($r['_t'], SLA_BULK_EXAMPLE_TICKET) === 0);
        if ($is_example) {
            $err('example', 'Template example row', 'This row came from the template — delete it before uploading.');
            $entry['status_note'] = 'Example row';
        }

        // 2. Existence.
        $prs = null;
        if ($r['_t'] !== '' && $r['_l'] !== '' && !$is_example) {
            if (!isset($prs_lines[$r['_t']])) {
                $err('not_found', 'Ticket not found', 'Ticket ' . $r['_t'] . ' does not exist in the database. Check the ticket number.');
                $entry['status_note'] = 'Ticket not found';
            } elseif (!isset($prs_lines[$r['_t']][$r['_l']])) {
                $avail = array_keys($prs_lines[$r['_t']]);
                sort($avail, SORT_NATURAL);
                $list = implode(', ', array_slice($avail, 0, 12)) . (count($avail) > 12 ? ', …' : '');
                $err('not_found', 'Line item not found', 'Ticket ' . $r['_t'] . ' exists, but it has no line ' . $r['_l'] . '. Lines on this ticket: ' . $list . '.');
                $entry['status_note'] = 'Line not found';
            } else {
                $prs = $prs_lines[$r['_t']][$r['_l']];
                // Canonical spelling from the DB from here on, so confirm writes
                // exactly what `prs` holds.
                $entry['ticket_no'] = (string)$prs['ticket_no'];
                $entry['line_item'] = (string)$prs['ticket_line_item'];
                $entry['pic']       = trim((string)($prs['assigned_pic'] ?? ''));
            }
        }

        // 3. One request per line per file.
        if ($r['_t'] !== '' && $r['_l'] !== '') {
            $dups = $seen[$r['_t'] . '|' . $r['_l']] ?? [];
            if (count($dups) > 1) {
                $others = array_values(array_diff($dups, [(int)$r['row_number']]));
                $err('duplicate', 'Duplicate in file', 'Ticket ' . $r['_t'] . ' line ' . $r['_l'] . ' is also on row'
                    . (count($others) === 1 ? ' ' : 's ') . implode(', ', $others)
                    . '. Only one request per line is allowed — keep one row and delete the rest.');
            }
        }

        // 4–6. Only meaningful for a line that exists.
        if ($prs !== null) {
            $key = sla_rev_line_key($prs['ticket_no'], $prs['ticket_line_item']);
            $row = $sla_rows[$key] ?? null;

            if ($row === null) {
                if (empty($prs['pr_date'])) {
                    $err('scope', 'Not on SLA Tracking yet', 'TR Date Technical is still empty, so this line has no SLA result.');
                    $entry['status_note'] = 'Not tracked yet';
                } elseif ($role === 'PIC' && $entry['pic'] !== $name) {
                    $err('scope', 'Not your line', 'This line is assigned to ' . ($entry['pic'] !== '' ? $entry['pic'] : 'another PIC') . '. You can only request your own lines.');
                    $entry['status_note'] = 'Not your line';
                } else {
                    $err('scope', 'Outside your scope', 'This line is not on your SLA Tracking list.');
                    $entry['status_note'] = 'Outside your scope';
                }
            } else {
                $entry['item']           = trim((string)($row['item_description'] ?? ''));
                $entry['current_status'] = (string)$row['statusName'];
                $entry['duration']       = (string)($row['durationDisplay'] ?? '');

                // Belt and braces — the PIC persona already filters to own lines.
                if ($role === 'PIC' && trim((string)($row['assigned_pic'] ?? '')) !== $name) {
                    $err('scope', 'Not your line', 'This line is assigned to another PIC. You can only request your own lines.');
                }

                if (!empty($row['pending_req'])) {
                    $p = reset($row['pending_req']);
                    $err('pending', 'Already waiting for approval', 'A '
                        . ($type_labels[$p['request_type']] ?? $p['request_type']) . ' request'
                        . (!empty($p['requested_by_name']) ? ' by ' . $p['requested_by_name'] : '')
                        . (!empty($p['created_at']) ? ' on ' . date('d M Y', strtotime($p['created_at'])) : '')
                        . ' is still pending. Only one pending request per line is allowed.');
                }

                if ($entry['current_status'] !== 'SLA Breached') {
                    $err('not_eligible', 'Ticket is Within SLA', 'Current status is ' . $entry['current_status']
                        . (!empty($row['status_overridden']) ? ' (already adjusted by an approved revision)' : '')
                        . '. Only SLA Breached lines can be requested.');
                }
            }
        }

        // Most fundamental problem first: a missing ticket matters more than a
        // short remark, and is what the user should fix first.
        $prio = ['example' => 0, 'not_found' => 1, 'duplicate' => 2, 'scope' => 3, 'pending' => 4, 'not_eligible' => 5, 'format' => 6];
        usort($errors, fn($a, $b) => ($prio[$a['type']] ?? 9) <=> ($prio[$b['type']] ?? 9));

        $summary['total_rows']++;
        if (empty($errors)) {
            $entry['status'] = 'READY';
            $entry['errors'] = [];
            $entry['clean']  = ['remarks' => $entry['remarks'], 'evidence_url' => $link_res['evidence_url']];
            $summary['ready']++;
        } else {
            $entry['status'] = 'INVALID';
            $entry['errors'] = $errors;
            $summary['invalid']++;
            $types = array_column($errors, 'type');
            // One bucket per row for the summary cards, most fundamental first.
            if (in_array('not_found', $types, true) || in_array('example', $types, true)) {
                $summary['not_found']++;
            } elseif (in_array('duplicate', $types, true) || in_array('pending', $types, true)) {
                $summary['duplicate']++;
            } else {
                $summary['not_eligible']++;
            }
        }
        $out[] = $entry;
    }

    return ['summary' => $summary, 'rows' => $out];
}

} // function_exists guard
