<?php
// includes/master_data_import.php
//
// CSV Import for Master Data (pages/ajax_master_data_import.php).
//
// Two entry points, deliberately kept separate:
//   md_import_parse_csv()      -- turns an uploaded file into plain row arrays.
//                                  Only checks CSV *shape* (headers, blank file).
//   md_import_classify_rows()  -- the actual business rules AND the change-vs-
//                                  current-database diff. Both the "validate"
//                                  step (fresh upload) and the "confirm" step
//                                  (re-checking what the browser sent back) call
//                                  this SAME function against the LIVE table, so
//                                  confirm can never write something validate
//                                  would not also have offered -- the client-held
//                                  row list is never trusted on its own.
//
// Every CSV row lands in exactly one status:
//   UNCHANGED -- id exists, every tracked field already matches. Always skip.
//   UPDATED   -- id exists, at least one tracked field differs. Default: update.
//   NEW       -- id does not exist in master_data yet. Default: skip; the user
//                may explicitly opt to Add it (see md_import_expected_columns()
//                callers) -- there is no silent insert.
//   INVALID   -- fails structural or field-level validation. Always skip.
//
// There is deliberately no DELETE anywhere in this file or its caller: a row
// that exists in master_data but is absent from the CSV is simply left alone.
// The CSV is an update/add instruction, never a full replacement of the table.

require_once __DIR__ . '/master_data_enrichment.php';
require_once __DIR__ . '/master_data_audit.php'; // MD_AUDIT_COLUMNS -- the diff field list

if (!function_exists('md_import_expected_columns')) {

/** The CSV template's columns, in the order the template is generated. */
function md_import_expected_columns(): array {
    return ['id', 'level_0', 'level_1', 'level_2', 'level_3', 'level_4', 'level_5', 'pic_names'];
}

/**
 * Parse an uploaded CSV into row arrays keyed by md_import_expected_columns().
 * Column order in the file does not matter -- header NAMES do, and must be
 * exactly the expected set (case-insensitive). Returns:
 *   ['ok' => bool, 'error' => ?string, 'rows' => [ ['row_number'=>int, 'id'=>string, 'level_0'=>string, ...], ... ] ]
 * 'error' is a user-facing message explaining a structural rejection (not CSV,
 * empty, wrong/missing columns) -- row-level problems are not reported here,
 * see md_import_validate_rows().
 */
function md_import_parse_csv(string $filePath, string $delimiter = ','): array {
    if (!is_file($filePath) || !is_readable($filePath)) {
        return ['ok' => false, 'error' => 'Could not read the uploaded file.', 'rows' => []];
    }
    if ($delimiter === '') {
        $delimiter = ',';
    }

    $handle = fopen($filePath, 'r');
    if ($handle === false) {
        return ['ok' => false, 'error' => 'Could not read the uploaded file.', 'rows' => []];
    }

    $header = fgetcsv($handle, 0, $delimiter);
    if ($header === false || $header === null || (count($header) === 1 && trim((string)$header[0]) === '')) {
        fclose($handle);
        return ['ok' => false, 'error' => 'The CSV file is empty.', 'rows' => []];
    }

    // A leading UTF-8 BOM (common from Excel exports) would otherwise corrupt
    // the very first header name and fail the column check for a file that is
    // otherwise perfectly correct.
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $normalized = array_map(static fn($h) => strtolower(trim((string)$h)), $header);

    $expected   = md_import_expected_columns();
    $missing    = array_values(array_diff($expected, $normalized));
    $unexpected = array_values(array_diff($normalized, $expected));
    if (!empty($missing) || !empty($unexpected)) {
        fclose($handle);
        $msg = 'CSV column headers do not match the expected template (' . implode(', ', $expected) . ').';
        if (!empty($missing))    { $msg .= ' Missing: ' . implode(', ', $missing) . '.'; }
        if (!empty($unexpected)) { $msg .= ' Unexpected: ' . implode(', ', $unexpected) . '.'; }
        return ['ok' => false, 'error' => $msg, 'rows' => []];
    }

    // name -> column index, so a template whose columns were reordered by hand
    // still parses correctly.
    $colIndex = array_flip($normalized);

    $rows = [];
    $row_number = 1; // header is row 1, so the first data row is row 2
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $row_number++;
        if (count($data) === 1 && trim((string)$data[0]) === '') {
            continue; // blank line (trailing newline etc.)
        }
        $row = ['row_number' => $row_number];
        foreach ($expected as $col) {
            $row[$col] = isset($data[$colIndex[$col]]) ? trim((string)$data[$colIndex[$col]]) : '';
        }
        $rows[] = $row;
    }
    fclose($handle);

    if (empty($rows)) {
        return ['ok' => false, 'error' => 'The CSV file has no data rows.', 'rows' => []];
    }

    return ['ok' => true, 'error' => null, 'rows' => $rows];
}

/**
 * Validate parsed rows against the business rules, then diff every row that
 * passes against the LIVE master_data table. Never writes anything -- purely
 * reads + in-memory comparison, safe to call twice (once on upload, once
 * again on confirm against whatever the browser sent back).
 *
 * Returns:
 *   [
 *     'summary' => ['total_rows'=>, 'unchanged'=>, 'updated'=>, 'new_rows'=>, 'invalid'=>],
 *     'rows'     => [
 *       [
 *         'row_number'     => int,
 *         'csv_id'         => string,       // the raw id cell from the CSV
 *         'id'             => ?int,         // the matching master_data.id, only when it exists
 *         'status'         => 'UNCHANGED'|'UPDATED'|'NEW'|'INVALID',
 *         'default_action' => 'skip'|'update',   // NEW's default is always 'skip' -- see file header
 *         'errors'         => [ ['error_type'=>, 'error_message'=>], ... ],  // INVALID only
 *         'changed_fields' => [ ['field'=>, 'old'=>, 'new'=>], ... ],        // UPDATED only
 *         'current_values' => ?array,       // MD_AUDIT_COLUMNS => value, UPDATED/UNCHANGED only
 *         'new_values'     => ?array,       // MD_AUDIT_COLUMNS => value, UPDATED/NEW only
 *       ], ...
 *     ],
 *   ]
 *
 * 'new_values' has already been run through md_l5_golden_rule() (level_5) and
 * md_validate_pic_names() (pic_names) -- the same transforms every other
 * master_data write path applies, so an imported row is indistinguishable
 * from one hand-edited through the Taxonomy tab.
 *
 * A NEW row is also checked against the (level_4, level_5) unique key -- both
 * the live table AND every other NEW row already seen in this same call --
 * before it is ever offered as addable, so Add can't fail on a collision that
 * was foreseeable at classify time.
 */
function md_import_classify_rows(PDO $pdo, array $rows): array {
    $valid_pics = md_valid_pic_names($pdo);
    $required_fields = ['level_0', 'level_1', 'level_2', 'level_3', 'level_4', 'level_5'];

    $idCounts = [];
    foreach ($rows as $r) {
        $idRaw = trim((string)($r['id'] ?? ''));
        if ($idRaw !== '' && ctype_digit($idRaw)) {
            $idCounts[$idRaw] = ($idCounts[$idRaw] ?? 0) + 1;
        }
    }
    $candidateIds = array_keys($idCounts);

    // Full current rows (not just existence) for every candidate id, so an
    // UPDATED row can be diffed field by field without a second query per row.
    $current = [];
    if (!empty($candidateIds)) {
        $ph   = implode(',', array_fill(0, count($candidateIds), '?'));
        $cols = implode(', ', MD_AUDIT_COLUMNS);
        $stmt = $pdo->prepare("SELECT id, $cols FROM master_data WHERE id IN ($ph)");
        $stmt->execute($candidateIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $current[(string)$row['id']] = $row;
        }
    }

    // Every (level_4, level_5) pair already in the table -- see the NEW branch
    // below. Same approach nma_rename_master_data.php uses for the same reason.
    $taken = [];
    foreach ($pdo->query("SELECT id, level_4, level_5 FROM master_data")->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $taken[mb_strtolower(trim((string)$t['level_4']) . '|' . trim((string)$t['level_5']), 'UTF-8')] = (int)$t['id'];
    }
    $reserved = []; // pairs claimed by an earlier NEW row in THIS call

    $seen = [];
    $out  = [];
    $c = ['unchanged' => 0, 'updated' => 0, 'new' => 0, 'invalid' => 0];

    foreach ($rows as $r) {
        $row_number = (int)($r['row_number'] ?? 0);
        $idRaw = trim((string)($r['id'] ?? ''));
        $entry = [
            'row_number' => $row_number, 'csv_id' => $idRaw, 'id' => null,
            'status' => null, 'default_action' => 'skip', 'errors' => [],
            'changed_fields' => [], 'current_values' => null, 'new_values' => null,
        ];

        if ($idRaw === '') {
            $entry['status'] = 'INVALID';
            $entry['errors'][] = ['error_type' => 'MISSING_ID', 'error_message' => 'ID is required.'];
            $c['invalid']++; $out[] = $entry; continue;
        }
        if (!ctype_digit($idRaw)) {
            $entry['status'] = 'INVALID';
            $entry['errors'][] = ['error_type' => 'INVALID_ID', 'error_message' => 'ID must be a positive integer.'];
            $c['invalid']++; $out[] = $entry; continue;
        }
        if (isset($seen[$idRaw])) {
            $entry['status'] = 'INVALID';
            $entry['errors'][] = ['error_type' => 'DUPLICATE_ID', 'error_message' => 'ID appears multiple times in the uploaded file.'];
            $c['invalid']++; $out[] = $entry; continue;
        }
        $seen[$idRaw] = true;

        $rowErrors = [];
        foreach ($required_fields as $field) {
            if (trim((string)($r[$field] ?? '')) === '') {
                $rowErrors[] = ['error_type' => 'MISSING_FIELD', 'error_message' => $field . ' is required.'];
            }
        }
        $picRaw = trim((string)($r['pic_names'] ?? ''));
        $picResult = ['value' => '', 'invalid' => []];
        if ($picRaw === '') {
            $rowErrors[] = ['error_type' => 'MISSING_FIELD', 'error_message' => 'pic_names is required.'];
        } else {
            $picResult = md_validate_pic_names($picRaw, $valid_pics);
            if (!empty($picResult['invalid'])) {
                $rowErrors[] = ['error_type' => 'INVALID_PIC', 'error_message' => 'Unknown PIC name(s): ' . implode(', ', $picResult['invalid']) . '.'];
            }
        }
        if (!empty($rowErrors)) {
            $entry['status'] = 'INVALID';
            $entry['errors'] = $rowErrors;
            $c['invalid']++; $out[] = $entry; continue;
        }

        $l4 = trim((string)$r['level_4']);
        $new_values = [
            'level_0' => trim((string)$r['level_0']), 'level_1' => trim((string)$r['level_1']),
            'level_2' => trim((string)$r['level_2']), 'level_3' => trim((string)$r['level_3']),
            'level_4' => $l4, 'level_5' => md_l5_golden_rule((string)$r['level_5'], $l4),
            'pic_names' => $picResult['value'],
        ];

        if (isset($current[$idRaw])) {
            $changed = [];
            foreach (MD_AUDIT_COLUMNS as $field) {
                $old = trim((string)($current[$idRaw][$field] ?? ''));
                $new = trim((string)($new_values[$field] ?? ''));
                if ($old !== $new) {
                    $changed[] = ['field' => $field, 'old' => $old, 'new' => $new];
                }
            }
            $entry['id'] = (int)$idRaw;
            $entry['current_values'] = $current[$idRaw];
            $entry['new_values'] = $new_values;
            if (empty($changed)) {
                $entry['status'] = 'UNCHANGED';
                $entry['default_action'] = 'skip';
                $c['unchanged']++;
            } else {
                $entry['status'] = 'UPDATED';
                $entry['default_action'] = 'update';
                $entry['changed_fields'] = $changed;
                $c['updated']++;
            }
        } else {
            $key = mb_strtolower($l4 . '|' . $new_values['level_5'], 'UTF-8');
            if (isset($taken[$key])) {
                $entry['status'] = 'INVALID';
                $entry['errors'][] = ['error_type' => 'DUPLICATE_TAXONOMY', 'error_message' => "This level_4 + level_5 already exists in Master Data (id={$taken[$key]}); it cannot be added as new."];
                $c['invalid']++; $out[] = $entry; continue;
            }
            if (isset($reserved[$key])) {
                $entry['status'] = 'INVALID';
                $entry['errors'][] = ['error_type' => 'DUPLICATE_TAXONOMY', 'error_message' => "This level_4 + level_5 is also proposed by row {$reserved[$key]} in this file."];
                $c['invalid']++; $out[] = $entry; continue;
            }
            $reserved[$key] = $row_number;
            $entry['status'] = 'NEW';
            $entry['default_action'] = 'skip';
            $entry['new_values'] = $new_values;
            $c['new']++;
        }
        $out[] = $entry;
    }

    $summary = [
        'total_rows' => count($rows),
        'unchanged'  => $c['unchanged'],
        'updated'    => $c['updated'],
        'new_rows'   => $c['new'],
        'invalid'    => $c['invalid'],
    ];

    return ['summary' => $summary, 'rows' => $out];
}

/** Streams the downloadable CSV template. Caller is responsible for headers. */
function md_import_template_csv(): string {
    $out = fopen('php://temp', 'r+');
    fputcsv($out, md_import_expected_columns());
    fputcsv($out, ['1001', 'IT', 'Hardware', 'Computer', 'Laptop', 'Business Laptop', 'Notebook', 'John Doe']);
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return $csv;
}

}
