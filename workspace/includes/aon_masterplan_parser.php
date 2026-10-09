<?php
// includes/aon_masterplan_parser.php
// Parses "Sourcing Masterplan – Data cost saving item AoN 2026" CSV format.
// Columns: No | Category | Vendor | Validity Date | PIC STC/STT | SKU |
//           Item Name | Detail List Name | Avg Price 2025 | Price 2026 | Diff |
//           Qty Jan-Dec (12) | Saving Jan-Dec (12) | Total
// First 2 rows are headers; data starts at row 3.
// Category, Vendor, Validity, PIC inherit downward within each vendor block.

require_once __DIR__ . '/../config/database.php';

function mp_parse_validity($raw) {
    static $id = [
        'Januari'=>'January','Februari'=>'February','Maret'=>'March',
        'April'=>'April','Mei'=>'May','Juni'=>'June','Juli'=>'July',
        'Agustus'=>'August','September'=>'September','Oktober'=>'October',
        'November'=>'November','Desember'=>'December','Des'=>'December',
        'Okt'=>'October','Agt'=>'August','Mar'=>'March',
    ];

    // Single-pass, word-boundary-safe translation: preg_replace_callback matches
    // only against the original string, so a short key (e.g. 'Mar') can never
    // re-match text already produced by translating a longer key (e.g. 'Maret'
    // -> 'March'), unlike the previous sequential str_ireplace() chain which
    // corrupted "Maret" into "Marchch".
    static $pattern  = null;
    static $lower_id = null;
    if ($pattern === null) {
        $keys = array_keys($id);
        usort($keys, fn($a, $b) => strlen($b) - strlen($a)); // longest-first
        $pattern  = '/\b(' . implode('|', array_map('preg_quote', $keys)) . ')\b/iu';
        $lower_id = array_change_key_case($id, CASE_LOWER);
    }

    $raw = trim((string)$raw);
    if ($raw === '' || mb_stripos($raw, 'tidak') !== false)
        return ['valid_from' => null, 'valid_until' => null];

    $translate = fn($s) => preg_replace_callback(
        $pattern,
        fn($m) => $lower_id[mb_strtolower($m[1])] ?? $m[0],
        $s
    );

    // Range: "Maret 2026 - 31 Des 2026" or "Maret 2026 - 31 December 2026"
    if (preg_match('/^(.+?)\s*-\s*(\d.+)$/u', $raw, $m)) {
        $ts1 = strtotime($translate(trim($m[1])));
        $ts2 = strtotime($translate(trim($m[2])));
        return [
            'valid_from'  => $ts1 ? date('Y-m-d', $ts1) : null,
            'valid_until' => $ts2 ? date('Y-m-d', $ts2) : null,
        ];
    }

    $ts = strtotime($translate($raw));
    if (!$ts) return ['valid_from' => null, 'valid_until' => null];

    // Day = 1  → treat as contract start date; assume valid through year-end
    if ((int)date('d', $ts) === 1) {
        return [
            'valid_from'  => date('Y-m-d', $ts),
            'valid_until' => date('Y', $ts) . '-12-31',
        ];
    }

    // Otherwise (e.g., "Dec 31, 2026") → end date
    return ['valid_from' => null, 'valid_until' => date('Y-m-d', $ts)];
}

function mp_clean_sku($raw) {
    $clean = trim(str_replace([',', ' '], '', (string)$raw));
    return $clean === '' ? null : $clean;
}

function mp_clean_number($raw) {
    $clean = str_replace([',', ' ', 'Rp'], '', trim((string)$raw));
    if ($clean === '' || !is_numeric($clean)) return null;
    // Handle Indonesian thousands separator: X.XXX
    if (strpos($clean, '.') !== false && preg_match('/^\d{1,3}(\.\d{3})+$/', $clean)) {
        $clean = str_replace('.', '', $clean);
    }
    return is_numeric($clean) ? (float)$clean : null;
}

function parse_and_insert_masterplan($csv_path, $imported_by = null) {
    global $pdo;

    if (($fh = fopen($csv_path, 'r')) === false)
        return ['error' => 'Cannot open masterplan file.'];

    fgetcsv($fh); // skip row 1 (main headers)
    fgetcsv($fh); // skip row 2 (month sub-headers)

    $inserted = $updated = $skipped = 0;

    // Inherited context within a vendor block
    $cur_category = '';
    $cur_vendor   = '';
    $cur_validity = '';
    $cur_pic      = '';

    $stmt = $pdo->prepare("
        INSERT INTO aon_skus
            (sku_code, item_name, previous_item, category, vendor,
             price, price_2025, effective_year,
             valid_from, valid_until, alokasi, handler, quantity, status, created_by, updated_by)
        VALUES (?,?,?,?,?,?,?,2026,?,?,1,?,?,'ACTIVE',?,?)
        ON DUPLICATE KEY UPDATE
            item_name     = VALUES(item_name),
            previous_item = VALUES(previous_item),
            category      = COALESCE(category,    VALUES(category)),
            vendor        = COALESCE(vendor,       VALUES(vendor)),
            price         = COALESCE(VALUES(price),         price),
            price_2025    = COALESCE(VALUES(price_2025),    price_2025),
            valid_from    = COALESCE(VALUES(valid_from),    valid_from),
            valid_until   = COALESCE(VALUES(valid_until),   valid_until),
            alokasi       = 1,
            handler       = COALESCE(VALUES(handler), handler),
            quantity      = COALESCE(VALUES(quantity), quantity),
            updated_by    = VALUES(updated_by)
    ");

    while (($row = fgetcsv($fh, 0, ',')) !== false) {
        $row = array_pad($row, 36, '');

        // -- Inherit context --
        $cat    = trim($row[1]);
        $vendor = trim($row[2]);
        $val    = trim($row[3]);
        $pic    = trim($row[4]);

        if ($cat    !== '') $cur_category = $cat;
        if ($vendor !== '') {
            // New vendor → reset inherited PIC for this block
            if ($vendor !== $cur_vendor) $cur_pic = '';
            $cur_vendor = $vendor;
        }
        if ($val !== '') $cur_validity = $val;
        if ($pic !== '') $cur_pic = $pic;

        // -- SKU --
        $sku = mp_clean_sku($row[5]);
        if ($sku === null) { $skipped++; continue; }

        // -- Names --
        $item_name   = trim($row[6]);
        $detail_name = trim($row[7]);
        if ($item_name === '') $item_name = $detail_name;
        if ($item_name === '') { $skipped++; continue; }

        // -- Prices --
        $price_2025 = mp_clean_number($row[8]);
        $price_2026 = mp_clean_number($row[9]);

        // -- Quantity (sum of 12 monthly Quantity PO columns at indices 11–22) --
        $total_qty = 0;
        for ($i = 11; $i <= 22; $i++) {
            $q = mp_clean_number($row[$i] ?? '');
            if ($q !== null) $total_qty += $q;
        }
        // Store as VARCHAR-compatible string
        $quantity = $total_qty > 0 ? (string)(int)$total_qty : null;

        // -- Dates --
        ['valid_from' => $vfrom, 'valid_until' => $vuntil] = mp_parse_validity($cur_validity);

        // -- Vendor: strip leading vendor-code number (e.g. "1002006681 PT. MITRA…") --
        $vendor_name = preg_replace('/^\d{6,}\s+/u', '', $cur_vendor);

        // -- PIC --
        $pic_val = ($cur_pic !== '') ? $cur_pic : null;

        try {
            $stmt->execute([
                $sku,
                $item_name,
                $detail_name !== '' ? $detail_name : null,
                $cur_category !== '' ? $cur_category : null,
                $vendor_name  !== '' ? $vendor_name  : null,
                $price_2026,
                $price_2025,
                $vfrom,
                $vuntil,
                $pic_val,
                $quantity,
                $imported_by,
                $imported_by,
            ]);
            $rc = $stmt->rowCount();
            if ($rc === 1)     $inserted++;
            elseif ($rc === 2) $updated++;
            else               $skipped++;
        } catch (\PDOException $e) {
            $skipped++;
        }
    }
    fclose($fh);

    return compact('inserted', 'updated', 'skipped') + ['total' => $inserted + $updated];
}
