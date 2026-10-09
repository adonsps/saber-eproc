<?php
// includes/aon_parser.php
// Imports AON data from TWO CSV files:
//   1. Master Item Catalog  — columns: SKU, Item Name (lookup only)
//   2. Price Per Vendor     — columns: SKU, Vendor Name, Validity From/To,
//                             Contract Qty, Used Qty, Remain Qty, Price, Alokasi
//
// Flow: Price Per Vendor (Alokasi not blank) → these are the true AON SKUs.
//       Master Item Catalog is used only for item name lookup by SKU.
// After upsert, any existing aon_skus NOT in the current valid set are soft-deleted.
// Mismatches are flagged:
//   dup_sku           — same SKU appears in catalog with multiple different names
//   dup_name          — same item name maps to multiple different SKUs
//   unmatched_catalog — AON SKU in Price file but not found in Item Catalog

if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
    require_once __DIR__ . '/../config/database.php';
}
require_once __DIR__ . '/aon_helper.php';

function aon_parse_date($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : null;
}

function aon_parse_number($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    $clean = str_replace(['Rp', ' '], '', $raw);
    $has_dot   = strpos($clean, '.') !== false;
    $has_comma = strpos($clean, ',') !== false;
    if ($has_dot && $has_comma) {
        // Both separators: last one is the decimal
        if (strrpos($clean, '.') > strrpos($clean, ',')) {
            $clean = str_replace(',', '', $clean); // standard 1,500.00
        } else {
            $clean = str_replace('.', '', $clean);     // Indonesian 1.500,00
            $clean = str_replace(',', '.', $clean);
        }
    } elseif ($has_dot && preg_match('/^\d{1,3}(\.\d{3})+$/', $clean)) {
        $clean = str_replace('.', '', $clean); // Indonesian thousands: 1.500 → 1500
    } else {
        $clean = str_replace(',', '', $clean); // standard thousands commas
    }
    if ($clean === '' || !is_numeric($clean)) return null;
    return (float)$clean;
}

// Returns a clean numeric string suitable for VARCHAR qty columns.
function aon_parse_qty_string($raw) {
    $v = aon_parse_number($raw);
    if ($v === null) return null;
    return $v == (int)$v ? (string)(int)$v : rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
}

/**
 * Parse both CSVs, merge, flag issues, upsert into aon_skus.
 * Returns a summary array or ['error' => string].
 */
function parse_and_insert_dual_aon($catalog_path, $price_path, $imported_by = null) {
    return k2_parse_and_insert_dual_aon_internal($catalog_path, $price_path, $imported_by, ['strict' => false]);
}

function k2_parse_and_insert_dual_aon_internal($catalog_path, $price_path, $imported_by = null, array $options = []) {
    global $pdo;

    $strict = (bool)($options['strict'] ?? false);

    $effective_year = 2026;

    // ── 1. Master Item Catalog ────────────────────────────────────────────────
    $catalog      = [];      // sku → first item_name seen
    $sku_to_names = [];      // sku → [name, …]  (detect dup_sku)
    $name_to_skus = [];      // name → [sku, …]  (detect dup_name)

    if (($fh = fopen($catalog_path, 'r')) === false) {
        if ($strict) {
            throw new RuntimeException('Cannot open Master Item Catalog file.');
        }
        return ['error' => 'Cannot open Master Item Catalog file.'];
    }

    $hdr = fgetcsv($fh, 0, ',');
    if ($hdr === false) {
        fclose($fh);
        if ($strict) {
            throw new RuntimeException('Master Item Catalog file is empty.');
        }
        return ['error' => 'Master Item Catalog file is empty.'];
    }

    $cidx = [];
    foreach ($hdr as $i => $h) $cidx[strtoupper(trim((string)$h))] = $i;

    if (!isset($cidx['SKU'])) {
        fclose($fh);
        if ($strict) {
            throw new RuntimeException('Master Item Catalog is missing the SKU column.');
        }
        return ['error' => 'Master Item Catalog is missing the SKU column.'];
    }

    $name_key = null;
    foreach (['ITEM NAME', 'ITEM_NAME', 'NAME', 'DETAIL', 'DESCRIPTION', 'ITEM DESCRIPTION', 'MATERIAL DESCRIPTION', 'ITEM DESCRIPTION'] as $k)
        if (isset($cidx[$k])) { $name_key = $k; break; }
    if ($name_key === null) {
        fclose($fh);
        $message = 'Master Item Catalog is missing an Item Name column (tried: ITEM NAME, DETAIL, NAME, DESCRIPTION).';
        if ($strict) {
            throw new RuntimeException($message);
        }
        return ['error' => $message . ' Found columns: ' . implode(', ', array_keys($cidx))];
    }

    $ccell = function ($row, ...$names) use ($cidx) {
        foreach ($names as $n)
            if (isset($cidx[$n])) return trim((string)($row[$cidx[$n]] ?? ''));
        return '';
    };
    $yn = function ($raw, $default = 'Yes') {
        $v = strtoupper(trim((string)$raw));
        if (in_array($v, ['YES', 'TRUE', '1', 'Y'], true)) return 'Yes';
        if (in_array($v, ['NO',  'FALSE', '0', 'N'], true)) return 'No';
        return $default;
    };

    while (($row = fgetcsv($fh, 0, ',')) !== false) {
        $sku  = trim((string)($row[$cidx['SKU']]       ?? ''));
        $name = trim((string)($row[$cidx[$name_key]]   ?? ''));
        if ($sku === '') continue;

        $sku_to_names[$sku][] = $name;
        if (!isset($catalog[$sku])) {
            $max_del_raw = $ccell($row, 'MAX DELIVERY', 'MAX DELIVERY TIME', 'MAX DELIVERY DAYS', 'MAX DELIVERY TIME DAYS');
            $catalog[$sku] = [
                'name'                   => $name,
                'detail'                 => $name,
                'group_owner'            => $ccell($row, 'GROUP OWNER') ?: null,
                'area'                   => $ccell($row, 'AREA') ?: null,
                'uom'                    => $ccell($row, 'UOM', 'UNIT OF MEASURE', 'UNIT') ?: null,
                'est_price'              => aon_parse_number($ccell($row, 'EST PRICE', 'ESTIMATED PRICE', 'EST_PRICE')),
                'buying_price'           => aon_parse_number($ccell($row, 'BUYING PRICE', 'BUY PRICE', 'BUYING_PRICE')),
                'hashtag'                => $ccell($row, 'HASHTAG') ?: null,
                'article_sap'            => $ccell($row, 'ARTICLE SAP', 'ARTICLE_SAP', 'SAP ARTICLE') ?: null,
                'validity_from'          => aon_parse_date($ccell($row, 'VALIDITY FROM', 'VALIDITY DATE', 'VALID FROM')),
                'validity_to'            => aon_parse_date($ccell($row, 'VALIDITY TO', 'VALIDITY UNTIL', 'VALID TO')),
                'max_delivery_time_days' => $max_del_raw !== '' ? (int)$max_del_raw : null,
                'min_qty'                => $ccell($row, 'MIN QTY', 'MIN QUANTITY', 'MINIMUM QTY', 'MINIMUM QUANTITY') ?: null,
                'warranty'               => $ccell($row, 'WARRANTY') ?: null,
                'extend_to_group'        => $ccell($row, 'EXTEND TO GF', 'EXTEND TO GROUP', 'EXTEND TO GF/GH') ?: null,
                'is_reguler'             => $yn($ccell($row, 'IS REGULER', 'IS REGULAR')),
                'is_active'              => $yn($ccell($row, 'IS ACTIVE')),
                'auto_create_po'         => $yn($ccell($row, 'AUTO CREATE PO', 'AUTO CREATE', 'AUTO_CREATE_PO'), 'No'),
            ];
        }

        if ($name !== '') {
            if (!isset($name_to_skus[$name])) $name_to_skus[$name] = [];
            if (!in_array($sku, $name_to_skus[$name], true)) $name_to_skus[$name][] = $sku;
        }
    }
    fclose($fh);

    // ── 2. Price Per Vendor ───────────────────────────────────────────────────
    $prices = [];   // sku → {vendor, valid_from, valid_until, quantity, used_qty, remain_qty, price}

    if (($fh = fopen($price_path, 'r')) === false) {
        if ($strict) {
            throw new RuntimeException('Cannot open Price Per Vendor file.');
        }
        return ['error' => 'Cannot open Price Per Vendor file.'];
    }

    $hdr = fgetcsv($fh, 0, ',');
    if ($hdr === false) {
        fclose($fh);
        if ($strict) {
            throw new RuntimeException('Price Per Vendor file is empty.');
        }
        return ['error' => 'Price Per Vendor file is empty.'];
    }

    $pidx = [];
    foreach ($hdr as $i => $h) $pidx[strtoupper(trim((string)$h))] = $i;

    if (!isset($pidx['SKU'])) {
        fclose($fh);
        if ($strict) {
            throw new RuntimeException('Price Per Vendor file is missing the SKU column.');
        }
        return ['error' => 'Price Per Vendor file is missing the SKU column.'];
    }

    // Flexible column resolver: first matching name wins
    $pcell = function ($row, ...$names) use ($pidx) {
        foreach ($names as $n)
            if (isset($pidx[$n])) return $row[$pidx[$n]] ?? '';
        return '';
    };

    $today = date('Y-m-d');

    // Rank a price row so the best candidate for a SKU can be picked when the
    // SKU repeats (multiple vendors, or the same vendor renewed across years):
    //   2 = has an allocation + full validity range, and that range is currently active
    //   1 = has an allocation + full validity range, but it's expired (or not started yet)
    //   0 = missing allocation or validity data entirely
    $rank_price_row = function ($p) use ($today) {
        $has_data = $p['alokasi'] !== null && $p['valid_from'] !== null && $p['valid_until'] !== null;
        if (!$has_data) return 0;
        $active = $p['valid_from'] <= $today && $p['valid_until'] >= $today;
        return $active ? 2 : 1;
    };

    while (($row = fgetcsv($fh, 0, ',')) !== false) {
        $sku = trim((string)($row[$pidx['SKU']] ?? ''));
        if ($sku === '') continue;

        $vendor      = trim((string)$pcell($row, 'VENDOR NAME', 'VENDOR'));
        $valid_from  = aon_parse_date($pcell($row, 'VALIDITY FROM', 'VALIDITY DATE', 'VALID FROM'));
        $valid_until = aon_parse_date($pcell($row, 'VALIDITY TO',   'VALIDITY UNTIL', 'VALID TO'));
        $quantity    = aon_parse_qty_string($pcell($row, 'CONTRACT QTY', 'CONTRACT QUANTITY', 'QTY'));
        $used_qty    = aon_parse_qty_string($pcell($row, 'USED QTY',     'USED QUANTITY'));
        $remain_qty  = aon_parse_qty_string($pcell($row, 'REMAIN QTY',   'REMAINING QTY', 'REMAINING QUANTITY',
                                                     'REMAIN CONTRACT QUANTITY', 'REMAINING CONTRACT QUANTITY'));
        $price       = aon_parse_number($pcell($row, 'PRICE',        'NEGOTIATION PRICE', 'BUYING PRICE'));
        $alokasi     = aon_parse_qty_string($pcell($row, 'ALOKASI',      'ALLOCATION', 'ALOC'));

        // Prefer the CSV's own remain-qty column, same as the other qty fields;
        // only derive it when the file doesn't provide one directly.
        if ($remain_qty === null && $quantity !== null && $used_qty !== null) {
            $remain_qty = aon_parse_qty_string((string)((float)$quantity - (float)$used_qty));
        }

        $candidate = compact('vendor', 'valid_from', 'valid_until', 'quantity', 'used_qty', 'remain_qty', 'price', 'alokasi');

        // A SKU can appear multiple times in this file — different vendors, or
        // the same vendor with a renewed/expired contract row. Don't let
        // whichever row happens to come last blindly win; keep the row with
        // the highest rank (active validity beats expired beats missing data).
        if (!isset($prices[$sku]) || $rank_price_row($candidate) > $rank_price_row($prices[$sku])) {
            $prices[$sku] = $candidate;
        }
    }
    fclose($fh);

    // ── 3. 2025 reference prices from purchases_2025 ──────────────────────────
    // Per (sku, item name, vendor) unit price — SUM(net po_amount)/SUM(qty)
    // normalizes a multi-qty PO line down to a single-unit price. PPN is netted
    // out first for the FF**/FY**/DF** POrgs, which book their POs gross — see
    // aon_net_amount_sql(); without it the 2025 baseline reads ~11% high against
    // the same SKU's PPN-exclusive contract price. Vendor is
    // grouped separately (not blended into the name group) because the same
    // item name is sometimes purchased from an unrelated vendor at a very
    // different price; aon_resolve_vendor_price() picks the one vendor to
    // keep per (sku, name) — see its docblock in aon_helper.php.
    // Two lookups are then built from the resolved prices:
    //   $by_sku[sku]     -> every distinct name that sku was purchased under
    //                       in 2025 (same SKU, 2+ names)
    //   $by_name[NAME]   -> every distinct SKU purchased under that name in
    //                       2025 (same name, 2+ SKUs — e.g. re-coded between years)
    // Both feed aon_build_2025_pairs() below so the STC can compare each
    // distinct 2025 price against the single 2026 price without averaging.
    $by_sku  = [];
    $by_name = [];
    try {
        $net_amount_2025 = aon_net_amount_sql('');
        $r2025n = $pdo->query(
            "SELECT sku, TRIM(item_description) AS item_name, TRIM(vendor_name) AS vendor_name,
                    SUM($net_amount_2025) AS amount, SUM(qty) AS qty
             FROM purchases_2025
             WHERE qty > 0 AND item_description IS NOT NULL AND TRIM(item_description) != ''
             GROUP BY sku, TRIM(item_description), TRIM(vendor_name)"
        );
        $raw = [];
        foreach ($r2025n as $r) {
            if ($r['sku'] === null || $r['sku'] === '') continue;
            $raw[$r['sku']][$r['item_name']][$r['vendor_name']] = [
                'qty'    => (float)$r['qty'],
                'amount' => (float)$r['amount'],
            ];
        }

        $sku_vendor = [];
        foreach ($prices as $sku => $pd) $sku_vendor[$sku] = $pd['vendor'] ?? null;

        $resolved = aon_resolve_vendor_price($raw, $sku_vendor);
        foreach ($resolved as $sku => $names) {
            foreach ($names as $name => $price) {
                if ($price === null) continue;
                $entry = ['sku' => $sku, 'name' => $name, 'price' => $price];
                $by_sku[$sku][] = $entry;
                $by_name[strtoupper($name)][] = $entry;
            }
        }
    } catch (\PDOException $e) { /* table may not exist yet */ }

    // ── 4. Merge, flag, upsert ────────────────────────────────────────────────
    // Price Per Vendor is the source of truth: only SKUs with a non-blank
    // allocation are AON SKUs. The catalog is used only for item name lookup.
    $all_skus = array_filter(array_keys($prices), fn($sku) => $prices[$sku]['alokasi'] !== null);
    $inserted = $updated = $skipped = 0;

    $stmt = $pdo->prepare("
        INSERT INTO aon_skus
            (sku_code, item_name, previous_item, category, vendor, quantity, used_qty, remain_qty,
             price, price_2025, effective_year, valid_from, valid_until,
             alokasi, flag, flag_detail, status, created_by, updated_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'ACTIVE',?,?)
        ON DUPLICATE KEY UPDATE
            -- Preserve masterplan-seeded item_name and handler; only update supply data
            previous_item = COALESCE(previous_item, VALUES(previous_item)),
            -- Auto-fill category from the catalog's Area column, same as
            -- previous_item: fill in when blank, never overwrite a manual edit.
            category      = COALESCE(category, VALUES(category)),
            vendor        = VALUES(vendor),
            -- quantity falls back to existing so a masterplan-seeded value isn't
            -- erased when this SKU's picked row has no contract qty; used_qty,
            -- remain_qty and price only ever come from the Price file, so they
            -- must always take the picked row's value as-is (even if blank) —
            -- never blend with a stale value from a previous vendor row/import.
            quantity      = COALESCE(VALUES(quantity), quantity),
            used_qty      = VALUES(used_qty),
            remain_qty    = VALUES(remain_qty),
            price         = VALUES(price),
            price_2025    = COALESCE(price_2025, VALUES(price_2025)),
            -- Price file is the source of truth for validity dates each import;
            -- only fall back to the existing value when the new file has none.
            valid_from    = COALESCE(VALUES(valid_from),  valid_from),
            valid_until   = COALESCE(VALUES(valid_until), valid_until),
            alokasi       = COALESCE(VALUES(alokasi), alokasi),
            flag          = VALUES(flag),
            flag_detail   = VALUES(flag_detail),
            updated_by    = VALUES(updated_by),
            is_deleted    = 0
    ");

    foreach ($all_skus as $sku) {
        $cat_entry   = $catalog[$sku] ?? null;
        $item_name   = $cat_entry['name']   ?? $sku;
        $catalog_detail = $cat_entry['detail'] ?? null;
        $pd          = $prices[$sku]  ?? [];

        // The 2025 name and price must always travel together: only show a
        // "2025 item name" when purchases_2025 actually has matching data
        // behind it, so a real price is guaranteed to come with it. Covers
        // both: this SKU purchased under 2+ names, and this item name
        // purchased under 2+ SKU codes (see aon_build_2025_pairs). Never
        // fall back to the catalog's detail text with no price — that shows
        // a name with nothing to back it up.
        $pairs_2025 = aon_build_2025_pairs($sku, $item_name, $by_sku, $by_name);
        if (count($pairs_2025) > 1) {
            $prev_item_value  = implode('|', array_column($pairs_2025, 'name'));
            $price_2025_value = implode('|', array_map(
                fn($p) => rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.'),
                array_column($pairs_2025, 'price')
            ));
        } elseif (count($pairs_2025) === 1) {
            // Real purchases_2025 match — keep the catalog's display name
            // (falling back to the purchases_2025 name only if the catalog
            // has none) but the price must be the actual matched value.
            $prev_item_value  = $catalog_detail ?? $pairs_2025[0]['name'];
            $price_2025_value = $pairs_2025[0]['price'];
        } else {
            $prev_item_value  = null;
            $price_2025_value = null;
        }

        // Build flags
        $flags   = [];
        $details = [];

        $unique_names = array_unique($sku_to_names[$sku] ?? []);
        if (count($unique_names) > 1) {
            $flags[]   = 'dup_sku';
            $details[] = 'Same SKU has multiple names: ' . implode(' / ', $unique_names);
        }
        if ($item_name !== $sku && isset($name_to_skus[$item_name]) && count($name_to_skus[$item_name]) > 1) {
            $flags[]   = 'dup_name';
            $details[] = 'Same item name maps to multiple SKUs: ' . implode(', ', $name_to_skus[$item_name]);
        }
        if (!isset($catalog[$sku])) {
            $flags[]   = 'unmatched_catalog';
            $details[] = 'SKU found in Price file but not in Item Catalog';
        }

        try {
            $stmt->execute([
                $sku,
                $item_name,
                $prev_item_value,
                $cat_entry['area'] ?? null,
                $pd['vendor']      ?? null,
                $pd['quantity']    ?? null,
                $pd['used_qty']    ?? null,
                $pd['remain_qty']  ?? null,
                $pd['price']       ?? null,
                $price_2025_value,
                $effective_year,
                $pd['valid_from']  ?? null,
                $pd['valid_until'] ?? null,
                $pd['alokasi']     ?? null,
                $flags   ? implode(',', $flags)    : null,
                $details ? implode('; ', $details) : null,
                $imported_by,
                $imported_by,
            ]);
            $rc = $stmt->rowCount();
            if ($rc === 1)     $inserted++;
            elseif ($rc === 2) $updated++;
            else               $skipped++;
        } catch (\PDOException $e) {
            if ($strict) {
                throw $e;
            }
            $skipped++;
        }
    }

    // ── 5. Cleanup: soft-delete any SKU no longer in the valid AON set ──────────
    // Price Per Vendor is authoritative — anything not in the current valid set
    // (SKUs with allocation) is removed so stale/wrong imports don't linger.
    $removed = 0;
    if (!empty($all_skus)) {
        $valid_codes  = array_values($all_skus);
        $placeholders = implode(',', array_fill(0, count($valid_codes), '?'));
        $del_stmt = $pdo->prepare(
            "UPDATE aon_skus SET is_deleted = 1
             WHERE sku_code NOT IN ($placeholders) AND is_deleted = 0"
        );
        $del_stmt->execute($valid_codes);
        $removed = $del_stmt->rowCount();
    }

    // ── 6. Upsert ALL catalog items into sku_dictionary ──────────────────────────
    // Every SKU in the Master Item Catalog is synced to the dictionary.
    // Taxonomy levels (level_0–4) and mapping_status are intentionally NOT updated
    // here — those are managed manually or by AI and must not be overwritten on import.
    $dict_inserted = $dict_updated = 0;
    if (!empty($catalog)) {
        $dict_stmt = $pdo->prepare("
            INSERT INTO sku_dictionary
                (sku, detail, group_owner, area, uom, est_price, buying_price, hashtag,
                 article_sap, validity_from, validity_to, max_delivery_time_days, min_qty,
                 warranty, extend_to_group, is_reguler, is_active, auto_create_po,
                 modified_by, modified_date)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE
                detail                 = VALUES(detail),
                group_owner            = VALUES(group_owner),
                area                   = VALUES(area),
                uom                    = VALUES(uom),
                est_price              = COALESCE(VALUES(est_price),   est_price),
                buying_price           = COALESCE(VALUES(buying_price), buying_price),
                hashtag                = VALUES(hashtag),
                article_sap            = VALUES(article_sap),
                validity_from          = COALESCE(VALUES(validity_from),  validity_from),
                validity_to            = COALESCE(VALUES(validity_to),    validity_to),
                max_delivery_time_days = COALESCE(VALUES(max_delivery_time_days), max_delivery_time_days),
                min_qty                = COALESCE(VALUES(min_qty), min_qty),
                warranty               = VALUES(warranty),
                extend_to_group        = VALUES(extend_to_group),
                is_reguler             = VALUES(is_reguler),
                is_active              = VALUES(is_active),
                auto_create_po         = VALUES(auto_create_po),
                modified_by            = VALUES(modified_by),
                modified_date          = NOW()
        ");

        foreach ($catalog as $sku => $cat) {
            try {
                $dict_stmt->execute([
                    $sku,
                    $cat['detail'],
                    $cat['group_owner'],
                    $cat['area'],
                    $cat['uom'],
                    $cat['est_price'],
                    $cat['buying_price'],
                    $cat['hashtag'],
                    $cat['article_sap'],
                    $cat['validity_from'],
                    $cat['validity_to'],
                    $cat['max_delivery_time_days'],
                    $cat['min_qty'],
                    $cat['warranty'],
                    $cat['extend_to_group'],
                    $cat['is_reguler'],
                    $cat['is_active'],
                    $cat['auto_create_po'],
                    $imported_by,
                ]);
                $rc = $dict_stmt->rowCount();
                if ($rc === 1)     $dict_inserted++;
                elseif ($rc === 2) $dict_updated++;
            } catch (\PDOException $e) {
                if ($strict) {
                    throw $e;
                }
                // skip rows that fail (e.g. data type mismatch)
            }
        }
    }

    return [
        'catalog_count'    => count($catalog),
        'price_count'      => count($prices),
        'total'            => count($all_skus),
        'inserted'         => $inserted,
        'updated'          => $updated,
        'skipped'          => $skipped,
        'removed'          => $removed,
        'dict_inserted'    => $dict_inserted,
        'dict_updated'     => $dict_updated,
        'dup_sku_count'    => count(array_filter($sku_to_names,  fn($n) => count(array_unique($n)) > 1)),
        'dup_name_count'   => count(array_filter($name_to_skus,  fn($s) => count($s) > 1)),
        'unmatched_count'  => count(array_filter($all_skus, fn($sku) => !isset($catalog[$sku]))),
        'price_columns'    => array_keys($pidx),  // actual column names found in the Price CSV
    ];
}
