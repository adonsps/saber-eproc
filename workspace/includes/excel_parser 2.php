<?php
// includes/excel_parser.php
require_once '../config/database.php';
require_once __DIR__ . '/quant_eval.php';

function parse_and_insert_csv($file_path, $original_filename = null, $imported_by = null) {
    global $pdo;

    date_default_timezone_set('Asia/Jakarta');
    $current_jakarta_time = date('Y-m-d H:i:s');

    $totalRows = 0;
    $insertedCount = 0;
    $updatedCount = 0;
    $skippedCount = 0;
    $warningCount = 0;
    $warnings = [];
    $maxStoredWarnings = 500;
    $seenRequestors = [];

    $excluded_statuses = [
        'canceled', 'cancelled', 'cancelled ticket', 'canceled ticket',
        'rejected', 'done', 'closed', 'waiting for requestor','revise',
        'draft', 'waiting for approval', 'waiting for buyer response', 'on review'
    ];

    $addWarning = function($ticket_no, $ticket_line_item, $p_group, $ticket_type, $message) use (&$warningCount, &$warnings, $maxStoredWarnings) {
        $warningCount++;
        if (count($warnings) < $maxStoredWarnings) {
            $warnings[] = [
                'ticket_no' => $ticket_no,
                'ticket_line_item' => $ticket_line_item,
                'p_group' => $p_group,
                'ticket_type' => $ticket_type,
                'message' => $message
            ];
        }
    };

    $phaseTableExists = function($tableName) use ($pdo) {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([$tableName]);
        return (bool)$stmt->fetchColumn();
    };

    try {
        $stmt = $pdo->query("SELECT pgroup_code, label, team_name FROM allowed_pgroups WHERE status = 'ACTIVE'");
        $allowed_pgroups_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $allowed_pgroups = [];
        foreach ($allowed_pgroups_raw as $row) {
            $allowed_pgroups[strtoupper(trim($row['pgroup_code']))] = [
                'label' => $row['label'],
                'team_name' => $row['team_name']
            ];
        }
    } catch (PDOException $e) {
        $allowed_pgroups = [];
    }

    $gp_officer_map = [];
    try {
        $stmt = $pdo->query("SELECT gp_officer_id, name FROM users WHERE gp_officer_id IS NOT NULL AND gp_officer_id != ''");
        $gp_officer_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($gp_officer_rows as $row) {
            $gp_officer_map[trim($row['gp_officer_id'])] = $row['name'];
        }
    } catch (PDOException $e) {}

    $acronym_map = [
        'Corporate Services, General Supplies & Marketing' => 'CSGSM',
        'Technology, Industrial, and General Services'      => 'TIGS',
        'FNB and Operational Merchandise'                   => 'FNBOM',
        'Admin Team'                                        => 'ADMIN',
        'Project Konstruksi Retail'                         => 'PKR',
        'Project Konstruksi Properti'                       => 'PKP',
        'Maintenance & Facility Management'                 => 'MFM',
    ];

    $project_pgroups = [];
    $projectPgroupsAvailable = $phaseTableExists('project_pgroups');
    if ($projectPgroupsAvailable) {
        $stmt = $pdo->query("SELECT p_group FROM project_pgroups WHERE status = 'ACTIVE'");
        $project_pgroups = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $project_pgroups = array_map('strtoupper', array_map('trim', $project_pgroups));
        $project_pgroups = array_fill_keys($project_pgroups, true);
    } else {
        error_log('project_pgroups table not found; project PGroup detection disabled until migration is applied.');
    }

    try {
        $stmt = $pdo->query("SELECT ticket_no, ticket_line_item FROM prs");
        $existing_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $existing_tickets = [];
        foreach ($existing_rows as $row) {
            $existing_tickets[$row['ticket_no'] . '|' . $row['ticket_line_item']] = true;
        }
    } catch (PDOException $e) {
        $existing_tickets = [];
    }

    // --- PRE-FETCH ADMIN MAPPINGS ---
    // Memuat mapping P.ORG ke PIC ke dalam array untuk mempercepat pengecekan tanpa membebani database
    try {
        $stmt_admin = $pdo->query("SELECT p_org, pic_name FROM admin_mappings");
        $admin_rows = $stmt_admin->fetchAll(PDO::FETCH_ASSOC);
        $admin_map = [];
        foreach ($admin_rows as $ar) {
            $admin_map[strtoupper(trim($ar['p_org']))] = trim($ar['pic_name']);
        }
    } catch (PDOException $e) {
        $admin_map = [];
    }

    // --- PRE-FETCH QUANT EVAL CONFIG ---
    try {
        $stmt_quant = $pdo->query("SELECT * FROM quant_eval_config WHERE id = 1");
        $quant_config = $stmt_quant->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $quant_config = ['otf_score_3_max_days' => 0, 'otf_score_2_max_days' => 2, 'otf_score_1_min_days' => 3];
    }

    // --- PRE-FETCH VENDOR TOP SCORES ---
    try {
        $stmt_top = $pdo->query("
            SELECT mv.description as vendor_name, COALESCE(q.score, 0) as top_score
            FROM master_vendor mv
            LEFT JOIN quant_top_scores q ON mv.top = q.top_value
            WHERE mv.description IS NOT NULL AND mv.description != ''
        ");
        $vendor_top_map = [];
        while ($row = $stmt_top->fetch(PDO::FETCH_ASSOC)) {
            $vendor_top_map[strtoupper(trim($row['vendor_name']))] = (int)$row['top_score'];
        }
    } catch (PDOException $e) {
        $vendor_top_map = [];
    }

    if (($handle = fopen($file_path, "r")) !== FALSE) {
        fgetcsv($handle, 10000, ",");

        while (($cells = fgetcsv($handle, 10000, ",")) !== FALSE) {
            if (empty(array_filter($cells))) continue;
            $totalRows++;

            $ticket_no        = isset($cells[0]) ? trim((string)$cells[0]) : '';
            $ticket_line_item = isset($cells[5]) ? trim((string)$cells[5]) : '';

            if (empty($ticket_no)) {
                $skippedCount++;
                continue;
            }

            $is_existing = isset($existing_tickets[$ticket_no . '|' . $ticket_line_item]);

            $ticket_type   = isset($cells[1]) ? trim((string)$cells[1]) : '';
            $status_ticket = isset($cells[2]) ? trim((string)$cells[2]) : '';
            $p_group       = isset($cells[4]) ? trim((string)$cells[4]) : '';
            $p_group_key   = strtoupper($p_group);
            $lower_ticket_type = strtolower(trim($ticket_type));
            $is_low_value_opex = $lower_ticket_type === 'low value opex';
            $is_intercompany_ticket = in_array($lower_ticket_type, ['intercompany', 'intercompany new store'], true);
            $is_project_pgroup = isset($project_pgroups[$p_group_key]);

            if ($lower_ticket_type === 'project new store' && !$is_project_pgroup) {
                $addWarning(
                    $ticket_no,
                    $ticket_line_item,
                    $p_group,
                    $ticket_type,
                    'Ticket type is Project New Store, but PGroup is not mapped in active project_pgroups.'
                );
            } elseif ($is_project_pgroup && $lower_ticket_type !== 'project new store' && !$is_low_value_opex && !$is_intercompany_ticket) {
                $addWarning(
                    $ticket_no,
                    $ticket_line_item,
                    $p_group,
                    $ticket_type,
                    'PGroup is mapped in active project_pgroups, but ticket type is not Project New Store.'
                );
            }

            $gp_officer    = isset($cells[11]) ? trim((string)$cells[11]) : '';
            $gp_officer    = ($gp_officer !== '') ? $gp_officer : null;
            
            $raw_date = isset($cells[8]) ? $cells[8] : null; 
            $pr_date  = !empty($raw_date) ? date('Y-m-d', strtotime((string)$raw_date)) : null;

            $po_number = isset($cells[23]) && trim((string)$cells[23]) !== '' ? trim((string)$cells[23]) : null;
            $raw_gr_date = isset($cells[36]) ? trim((string)$cells[36]) : null;
            $gr_date = !empty($raw_gr_date) ? date('Y-m-d', strtotime($raw_gr_date)) : null;

            if (!$is_existing) {
                if (empty($po_number) && empty($gr_date)) {
                    if (in_array(strtolower($status_ticket), $excluded_statuses)) { $skippedCount++; continue; }
                }
                if ($is_low_value_opex) { $skippedCount++; continue; }
                if (!isset($allowed_pgroups[$p_group_key]) && !$is_project_pgroup) { $skippedCount++; continue; }
            }

            $p_org            = isset($cells[3]) ? trim((string)$cells[3]) : ''; 
            $requestor_name   = isset($cells[10]) ? trim((string)$cells[10]) : ''; 
            $site_allocation  = isset($cells[12]) ? trim((string)$cells[12]) : ''; 
            $canvassing_no    = isset($cells[13]) && trim((string)$cells[13]) !== '' ? trim((string)$cells[13]) : null;
            $canvassing_status = isset($cells[14]) && trim((string)$cells[14]) !== '' ? trim((string)$cells[14]) : null;
            $vendor_name      = isset($cells[26]) && trim((string)$cells[26]) !== '' ? trim((string)$cells[26]) : null;
            
            $qty_ad           = isset($cells[29]) && trim((string)$cells[29]) !== '' ? (float)$cells[29] : null;
            $qty_g            = isset($cells[6]) && trim((string)$cells[6]) !== '' ? (float)$cells[6] : null;
            $qty              = ($qty_ad !== null && $qty_ad != 0) ? $qty_ad : $qty_g;

            $raw_canv_date    = isset($cells[15]) ? trim((string)$cells[15]) : null;
            $canvassing_date  = !empty($raw_canv_date) ? date('Y-m-d', strtotime($raw_canv_date)) : null;

            $raw_deliv_date   = isset($cells[32]) ? trim((string)$cells[32]) : null;
            $delivery_date    = !empty($raw_deliv_date) ? date('Y-m-d', strtotime($raw_deliv_date)) : null;

            $tr_date_gp = !empty($cells[9]) ? date('Y-m-d', strtotime($cells[9])) : null; 
            $canvassing_date_approver_1 = !empty($cells[17]) ? date('Y-m-d', strtotime($cells[17])) : null;

            $sku              = isset($cells[27]) && trim((string)$cells[27]) !== '' ? trim((string)$cells[27]) : null;
            $item_description = isset($cells[28]) ? trim((string)$cells[28]) : '';
            $uom              = isset($cells[30]) && trim((string)$cells[30]) !== '' ? trim((string)$cells[30]) : null;
            
            $raw_po_amount    = isset($cells[31]) ? trim((string)$cells[31]) : '';
            $po_amount        = !empty($raw_po_amount) ? (float)str_replace(['Rp', ',', ' '], '', $raw_po_amount) : null;

            // --- SYSTEM RULE ROUTING (Bypass AI) ---
            $assigned_pic = null;
            $assigned_by_ai = null;

            // Intercompany keeps the existing admin mapping route, even when its PGroup is mapped as Project.
            if ($is_intercompany_ticket) {
                $p_org_key = strtoupper(trim($p_org));
                if (isset($admin_map[$p_org_key])) {
                    $assigned_pic = $admin_map[$p_org_key];
                    $assigned_by_ai = 0; // 0 = Sistem / Manual rule
                }
            } elseif (isset($allowed_pgroups[$p_group_key]) && $allowed_pgroups[$p_group_key]['label'] === 'Team GP Project') {
                $assigned_by_ai = 0;
                $gp_key = trim((string)$gp_officer);
                if ($gp_key !== '' && isset($gp_officer_map[$gp_key])) {
                    $assigned_pic = $gp_officer_map[$gp_key];
                } else {
                    $team = $allowed_pgroups[$p_group_key]['team_name'];
                    if ($team && isset($acronym_map[$team])) {
                        $assigned_pic = $acronym_map[$team];
                    } elseif ($gp_key !== '') {
                        $assigned_pic = $gp_key;
                    }
                }
            }

            // --- OTF Calculation ---
            $otf_res = calculate_otf_score($gr_date, $delivery_date, $quant_config);
            $otf_score = $otf_res['score'];
            $otf_status = $otf_res['status'];
            
            // --- TOP Score Retrieval ---
            $top_score = null;
            if ($vendor_name !== null && trim($vendor_name) !== '') {
                $vendor_key = strtoupper(trim($vendor_name));
                $top_score = isset($vendor_top_map[$vendor_key]) ? $vendor_top_map[$vendor_key] : null;
            }

            // Gunakan COALESCE pada ON DUPLICATE KEY UPDATE untuk memastikan kita tidak 
            // menimpa data assigned_pic yang sudah ada jika baris ini sedang diperbarui
            $insertQuery = "INSERT INTO prs 
                (ticket_no, ticket_line_item, p_org, p_group, requestor_name, site_allocation, pr_date, item_description, status_ticket, canvassing_no, canvassing_status, canvassing_date, po_number, ticket_type, vendor_name, qty, delivery_date, gr_date, tr_date_gp, canvassing_date_approver_1, created_at, sku, uom, po_amount, assigned_pic, assigned_by_ai, gp_officer, otf_score, otf_status, top_score) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                status_ticket = VALUES(status_ticket),
                canvassing_no = VALUES(canvassing_no),
                canvassing_status = VALUES(canvassing_status),
                canvassing_date = VALUES(canvassing_date),
                pr_date = VALUES(pr_date),
                po_number = VALUES(po_number),
                ticket_type = VALUES(ticket_type),
                vendor_name = VALUES(vendor_name),
                qty = VALUES(qty),
                delivery_date = VALUES(delivery_date),
                gr_date = VALUES(gr_date),
                tr_date_gp = VALUES(tr_date_gp),
                canvassing_date_approver_1 = VALUES(canvassing_date_approver_1),
                sku = VALUES(sku),
                uom = VALUES(uom),
                po_amount = VALUES(po_amount),
                assigned_pic = COALESCE(VALUES(assigned_pic), assigned_pic),
                assigned_by_ai = COALESCE(VALUES(assigned_by_ai), assigned_by_ai),
                gp_officer = VALUES(gp_officer),
                otf_score = VALUES(otf_score),
                otf_status = VALUES(otf_status),
                top_score = VALUES(top_score)";
            
            $stmt = $pdo->prepare($insertQuery);

            try {
                $stmt->execute([
                    $ticket_no, $ticket_line_item, $p_org, $p_group, $requestor_name, 
                    $site_allocation, $pr_date, $item_description, $status_ticket, 
                    $canvassing_no, $canvassing_status, $canvassing_date, $po_number, $ticket_type,
                    $vendor_name, $qty, $delivery_date, $gr_date, $tr_date_gp, $canvassing_date_approver_1, $current_jakarta_time,
                    $sku, $uom, $po_amount, $assigned_pic, $assigned_by_ai, $gp_officer, $otf_score, $otf_status, $top_score
                ]);

                $affected = $stmt->rowCount();
                if ($affected == 1) {
                    $insertedCount++;
                } elseif ($affected == 2) {
                    $updatedCount++;
                } else {
                    $skippedCount++;
                }
                if ($requestor_name !== '') {
                    $seenRequestors[$requestor_name] = true;
                }
            } catch (PDOException $e) {
                $skippedCount++;
            }
        }
        fclose($handle);
    }

    // --- Requestor Contact Master auto-sync ---
    // Requestor names newly seen in this import batch get a blank contact row
    // in `requestors` so admins can fill them in manually later. Existing rows
    // are never touched (insert-only, no overwrite, no duplicates).
    try {
        if (!empty($seenRequestors)) {
            if ($phaseTableExists('requestors')) {
                $genRequestorToken = function() use ($pdo) {
                    $check = $pdo->prepare("SELECT 1 FROM requestors WHERE public_token = ? LIMIT 1");
                    for ($i = 0; $i < 6; $i++) {
                        $t = bin2hex(random_bytes(32)); // 64 hex chars, matches requestors.public_token
                        $check->execute([$t]);
                        if ($check->fetchColumn() === false) {
                            return $t;
                        }
                    }
                    throw new RuntimeException('Unable to generate a unique public_token after retries.');
                };

                $existsStmt = $pdo->prepare("SELECT 1 FROM requestors WHERE requestor_name = ? LIMIT 1");
                $insertStmt = $pdo->prepare("INSERT INTO requestors
                    (requestor_name, email, whatsapp_number, manager_email, manager_username, public_token)
                    VALUES (?, NULL, NULL, NULL, NULL, ?)");

                foreach (array_keys($seenRequestors) as $rn) {
                    try {
                        $existsStmt->execute([$rn]);
                        if ($existsStmt->fetchColumn() !== false) {
                            continue; // already in Requestor Contact Master — never overwrite
                        }
                        $token = $genRequestorToken();
                        $insertStmt->execute([$rn, $token]);
                    } catch (\Throwable $e) {
                        $addWarning('', '', '', '', "Requestor auto-sync: could not create requestor '{$rn}': " . $e->getMessage());
                    }
                }
            } else {
                $addWarning('', '', '', '', 'Requestor auto-sync skipped: requestors table not found.');
            }
        }
    } catch (\Throwable $e) {
        $addWarning('', '', '', '', 'Requestor auto-sync failed unexpectedly: ' . $e->getMessage());
    }

    // Menghitung jumlah yang tersisa untuk AI.
    // Menambahkan pengecualian agar Intercompany tidak dihitung sebagai PR untuk AI
    $projectAiExclusionSql = $projectPgroupsAvailable ? "
        AND NOT EXISTS (
            SELECT 1 FROM project_pgroups pp
            WHERE pp.p_group = prs.p_group
            AND pp.status = 'ACTIVE'
        )" : "";

    $aiQueueStmt = $pdo->query("
        SELECT COUNT(DISTINCT ticket_no) as ticket_count, COUNT(*) as line_count
        FROM prs
        WHERE assigned_pic IS NULL
        $projectAiExclusionSql
        AND LOWER(status_ticket) NOT IN (
            'canceled', 'cancelled', 'cancelled ticket', 'canceled ticket',
            'rejected', 'done', 'closed', 'waiting for requestor','revise',
            'draft', 'waiting for approval', 'waiting for buyer response', 'on review'
        )
        AND LOWER(IFNULL(ticket_type, '')) NOT IN ('low value opex', 'intercompany', 'intercompany new store')
    ");
    $ai_queue = $aiQueueStmt->fetch(PDO::FETCH_ASSOC);

    $importBatchLogged = false;
    $warningsJson = null;
    if ($warningCount > 0) {
        $warningsJson = json_encode([
            'total_warnings' => $warningCount,
            'stored_warnings' => count($warnings),
            'truncated' => $warningCount > count($warnings),
            'warnings' => $warnings
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    try {
        if ($phaseTableExists('import_batches')) {
            $batchStmt = $pdo->prepare("
                INSERT INTO import_batches
                    (filename, imported_by, imported_at, source_generated_at, total_rows, inserted_rows, updated_rows, skipped_rows, warning_count, warnings_json)
                VALUES
                    (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)
            ");
            $batchStmt->execute([
                $original_filename ?: basename($file_path),
                $imported_by,
                $current_jakarta_time,
                $totalRows,
                $insertedCount,
                $updatedCount,
                $skippedCount,
                $warningCount,
                $warningsJson
            ]);
            $importBatchLogged = true;
        } else {
            error_log('import_batches table not found; skipping import batch logging until migration is applied.');
        }
    } catch (PDOException $e) {
        error_log('Failed to insert import_batches row: ' . $e->getMessage());
    }

    return [
        'total_rows' => $totalRows,
        'inserted' => $insertedCount,
        'updated' => $updatedCount,
        'skipped' => $skippedCount,
        'ai_tickets' => $ai_queue['ticket_count'],
        'ai_lines' => $ai_queue['line_count'],
        'warning_count' => $warningCount,
        'warnings_stored' => count($warnings),
        'warnings_truncated' => $warningCount > count($warnings),
        'import_batch_logged' => $importBatchLogged
    ];
}
?>
