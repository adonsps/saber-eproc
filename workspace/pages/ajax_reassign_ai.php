<?php
// pages/ajax_reassign_ai.php
header('Content-Type: application/json');

// 1. Prevent PHP from timing out during AI API response
set_time_limit(120);

require_once '../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Pastikan hanya Admin yang bisa mengeksekusi ini
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access.']);
    exit;
}

require_once '../config/database.php';
require_once '../includes/pr_audit_stamp.php'; // prs.last_updated_by / last_updated_date
require_once '../config/gemini_api.php'; 

$admin_name = $_SESSION['name'];
$ticket_no = $_POST['ticket_no'] ?? '';

if (empty($ticket_no)) {
    echo json_encode(['status' => 'error', 'message' => 'Ticket number is required.']);
    exit;
}

try {
    // 1. Verifikasi Kepemilikan (Pastikan tiket ini benar dipegang oleh Admin yang login)
    // Fetch EVERY line of this ticket currently owned by this admin -- a ticket
    // can have multiple lines, and each must be re-classified and reassigned
    // independently rather than sampling one line for the whole ticket.
    $stmt = $pdo->prepare("SELECT ticket_no, ticket_line_item, item_description, ticket_type, p_org, p_group FROM prs WHERE ticket_no = ? AND assigned_pic = ?");
    $stmt->execute([$ticket_no, $admin_name]);
    $owned_lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($owned_lines)) {
        echo json_encode(['status' => 'error', 'message' => 'Ticket not found or you do not have ownership of this ticket.']);
        exit;
    }

    $pr = $owned_lines[0];

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

    if ($phaseTableExists('project_pgroups')) {
        $stmt_project = $pdo->prepare("SELECT 1 FROM project_pgroups WHERE p_group = ? AND status = 'ACTIVE' LIMIT 1");
        $stmt_project->execute([trim($pr['p_group'] ?? '')]);
        if ($stmt_project->fetchColumn()) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Project tickets bypass AI and must be assigned by the Project Head dashboard.'
            ]);
            exit;
        }
    } else {
        error_log('project_pgroups table not found; manual AI reassignment project guard disabled until migration is applied.');
    }

    // =========================================================================
    // BYPASS: JIKA INTERCOMPANY, CEK ADMIN MAPPINGS TERLEBIH DAHULU
    // (ticket/p_org-wide rule -- legitimately applies uniformly to every line,
    // unlike the AI classification below which must run per line)
    // =========================================================================
    $intercompany_pic = null;
    $lower_ticket_type = strtolower(trim($pr['ticket_type'] ?? ''));
    if ($lower_ticket_type === 'intercompany' || $lower_ticket_type === 'intercompany new store') {
        $p_org_key = strtoupper(trim($pr['p_org']));

        $stmt_admin = $pdo->prepare("SELECT pic_name FROM admin_mappings WHERE p_org = ? LIMIT 1");
        $stmt_admin->execute([$p_org_key]);
        $admin_rule = $stmt_admin->fetch(PDO::FETCH_ASSOC);

        if ($admin_rule && !empty($admin_rule['pic_name'])) {
            $intercompany_pic = $admin_rule['pic_name'];

            // Lapis 1 Anti-Loop: Jika tiket intercompany muter di admin ini, paksa jatuh ke AI
            if ($intercompany_pic === $admin_name) {
                $intercompany_pic = null;
            }
        }
    }

    // Master Data taxonomy map is shared across all lines that need AI
    // classification (only fetched when the intercompany bypass didn't apply).
    $taxonomy_map = [];
    $categoriesStr = '';
    if ($intercompany_pic === null) {
        $stmt_master = $pdo->query("SELECT level_4, pic_names FROM master_data WHERE pic_names IS NOT NULL AND pic_names != ''");
        $master_data = $stmt_master->fetchAll(PDO::FETCH_ASSOC);

        $categoriesList = [];
        foreach ($master_data as $row) {
            $l4 = trim($row['level_4']);
            $taxonomy_map[$l4] = trim($row['pic_names']);
            $categoriesList[] = $l4;
        }
        $categoriesStr = implode(", ", $categoriesList);
    }

    // =========================================================================
    // PROSES SETIAP LINE ITEM SECARA INDEPENDEN
    // Setiap line bisa punya kategori (dan karenanya PIC) yang berbeda, jadi
    // klasifikasi + update dijalankan per line, bukan sekali untuk seluruh tiket.
    // =========================================================================
    $line_results = [];
    $any_success = false;

    foreach ($owned_lines as $line) {
        $ticket_line_item = $line['ticket_line_item'];
        $new_pic = $intercompany_pic;
        $ai_guessed_categories = [];

        if (empty($new_pic)) {
            // =====================================================================
            // PROMPT AI - MENGGUNAKAN STRICT JSON & SMART MAPPING
            // =====================================================================
            $prompt = "You are an expert procurement taxonomy classifier.\n";
            $prompt .= "Exact approved categories:\n" . $categoriesStr . "\n\n";
            $prompt .= "Item Description to Classify:\n" . $line['item_description'] . "\n\n";
            $prompt .= "Task: Map the item description to the single most accurate category from the approved categories.\n";
            $prompt .= "CRITICAL RULES:\n";
            $prompt .= "1. Output ONLY a valid JSON object with the key \"category\". Do NOT add markdown, greetings, or conversational text.\n";
            $prompt .= "2. SMART MAPPING: If the exact specific item is missing, pick the closest broad category.\n";
            $prompt .= "Example Output: {\"category\": \"Server/Storage\"}";

            $line_votes = [];

            // 3x Percobaan (Sistem Voting AI)
            for ($i = 0; $i < 3; $i++) {
                $temp = ($i == 0) ? 0.1 : (($i == 1) ? 0.4 : 0.7);
                $response = call_gemini_api($prompt, $temp, gemini_key_vendor_suggest());

                // --- DATABASE KEEP-ALIVE PING (MENCEGAH ERROR 2006 TIMEOUT) ---
                // MySQL sering memutus koneksi jika terlalu lama menunggu proses AI.
                // Kita gunakan blok ini untuk mengecek status dan reconnect jika terputus.
                try {
                    $pdo->query("SELECT 1");
                } catch (PDOException $e) {
                    // Koneksi terputus! Panggil ulang database config untuk reconnect
                    global $dsn, $user, $pass, $options; $pdo = new PDO($dsn, $user, $pass, $options);
                }
                // --------------------------------------------------------------

                // AUTO-CLEANER: Hapus markdown blok jika AI membandel
                $response_clean = trim(str_replace(['```json', '```JSON', '```'], '', $response));

                // Parse JSON
                $json_data = json_decode($response_clean, true);
                $assigned_category = "";

                if (is_array($json_data) && isset($json_data['category'])) {
                    $assigned_category = trim($json_data['category']);
                } else {
                    $assigned_category = "JSON Parsing Failed: " . substr($response_clean, 0, 50);
                }

                $ai_guessed_categories[] = $assigned_category;

                // Cek apakah kategori yang ditebak AI ada di database Master Data
                if (isset($taxonomy_map[$assigned_category])) {
                    $pic_string = $taxonomy_map[$assigned_category];

                    // Load Balancing & Lapis 3 Anti-Loop (Coret Admin yang login)
                    if (strpos($pic_string, ',') !== false) {
                        $combo_pics = array_map('trim', explode(',', $pic_string));

                        // Buang nama Admin saat ini dari pilihan agar AI dipaksa memilih orang lain
                        $combo_pics = array_filter($combo_pics, function($p) use ($admin_name) {
                            return $p !== $admin_name;
                        });

                        $combo_pics = array_values($combo_pics); // Re-index array

                        if (!empty($combo_pics)) {
                            $pic_string = $combo_pics[array_rand($combo_pics)];
                        } else {
                            $pic_string = $admin_name; // Fallback jika cuma ada admin ini
                        }
                    }

                    if (!isset($line_votes[$pic_string])) {
                        $line_votes[$pic_string] = 0;
                    }
                    $line_votes[$pic_string]++;
                }
            }

            // Tentukan Pemenang Vote (untuk line ini saja)
            if (!empty($line_votes)) {
                arsort($line_votes);
                $new_pic = array_key_first($line_votes);

                // Lapis 2 Anti-Loop: Jika hasil akhirnya tetap merujuk ke Admin ini sendiri
                if ($new_pic === $admin_name) {
                    $line_results[] = [
                        'ticket_line_item' => $ticket_line_item,
                        'new_pic' => null,
                        'error' => 'AI mapped this to your own category (' . htmlspecialchars(implode(", ", array_unique($ai_guessed_categories))) . '). You must manually process this line.'
                    ];
                    continue;
                }
            }
        }

        // =========================================================================
        // UPDATE DATABASE JIKA BERHASIL MENEMUKAN PIC BARU (LINE INI SAJA)
        // =========================================================================
        if (!empty($new_pic)) {
            // --- KEEP-ALIVE PING SEBELUM UPDATE EKSEKUSI ---
            try {
                $pdo->query("SELECT 1");
            } catch (PDOException $e) {
                global $dsn, $user, $pass, $options; $pdo = new PDO($dsn, $user, $pass, $options);
            }
            // --------------------------------------------------------

            $updateStmt = $pdo->prepare("UPDATE prs SET " . pr_stamp_pic_set_sql()
                . ", assigned_pic = ?, assigned_by_ai = 1 WHERE ticket_no = ? AND ticket_line_item = ?");
            $updateStmt->execute(array_merge(
                pr_stamp_pic_params($new_pic),
                [$new_pic, $ticket_no, $ticket_line_item]
            ));

            $line_results[] = [
                'ticket_line_item' => $ticket_line_item,
                'new_pic' => $new_pic,
                'error' => null
            ];
            $any_success = true;
        } else {
            $debug_info = implode(" | ", array_unique($ai_guessed_categories));
            $line_results[] = [
                'ticket_line_item' => $ticket_line_item,
                'new_pic' => null,
                'error' => 'AI failed to find a valid Taxonomy match. AI Output: ' . htmlspecialchars($debug_info)
            ];
        }
    }

    echo json_encode([
        'status' => $any_success ? 'success' : 'error',
        'ticket_no' => $ticket_no,
        'lines' => $line_results,
        'message' => $any_success ? null : 'AI failed to reassign any line item of this ticket.'
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'System Error: ' . $e->getMessage()]);
}
?>
