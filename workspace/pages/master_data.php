<?php
// pages/master_data.php
require_once '../includes/auth.php';
// We now allow all roles in (including PIC), but restrict what they can do via $_SESSION['role']
require_login(); 
require_once '../config/database.php';
require_once '../includes/sla_helper.php';
// The golden-rule name builders (Hybrid SKU Format) — every write path below
// runs level_5 through them, see md_l5_golden_rule().
require_once '../includes/master_data_enrichment.php';
// Audit trail: every write below stamps created_by / last_modified_by and,
// when a tracked column actually changed, appends to master_data_history.
require_once '../includes/master_data_audit.php';

// DEKLARASIKAN VARIABEL DI SINI SEBELUM CEK POST
$role = $_SESSION['role'] ?? '';
$current_user = $_SESSION['name'] ?? '';
$audit_actor  = md_audit_actor();

// md_l5_golden_rule() now lives in includes/master_data_enrichment.php,
// shared with the CSV import endpoint (ajax_master_data_import.php).

// --- HANDLE POST REQUESTS ---

$uploadMessage = '';

// 1. Category Manager: Manual Add single row
if ($role === 'Category Manager' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_single_row'])) {
    $l0 = trim($_POST['level_0'] ?? '');
    $l1 = trim($_POST['level_1'] ?? '');
    $l2 = trim($_POST['level_2'] ?? '');
    $l3 = trim($_POST['level_3'] ?? '');
    $l4 = trim($_POST['level_4'] ?? '');
    $l5 = md_l5_golden_rule($_POST['level_5'] ?? '', $l4);

    // Handle array from multi-select
    if (isset($_POST['pic_names']) && is_array($_POST['pic_names'])) {
        $pics = implode(', ', array_map('trim', $_POST['pic_names']));
    } else {
        $pics = trim($_POST['pic_names'] ?? '');
    }

    if ($l4 !== '') {
        $sql = "INSERT INTO master_data (level_0, level_1, level_2, level_3, level_4, level_5, pic_names, status, created_by, last_modified_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)
                ON DUPLICATE KEY UPDATE
                level_0 = VALUES(level_0), level_1 = VALUES(level_1),
                level_2 = VALUES(level_2), level_3 = VALUES(level_3),
                pic_names = VALUES(pic_names),
                suggested_pic_names = NULL, suggested_by = NULL, status = 'active',
                last_modified_by = VALUES(last_modified_by)";
        $stmt = $pdo->prepare($sql);
        $before = md_audit_snapshot($pdo, md_audit_ids_by_level($pdo, $l4, $l5));
        $stmt->execute([$l0, $l1, $l2, $l3, $l4, $l5, $pics, $audit_actor, $audit_actor]);
        md_audit_log_upsert($pdo, $stmt, $before, $l4, $l5, $audit_actor);
        $uploadMessage = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 shadow-sm rounded'><p class='font-bold'>Row Added!</p><p>The manual row was successfully added/updated in the Taxonomy.</p></div>";
    } else {
        $uploadMessage = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6'>Level 4 is required.</div>";
    }
}

// 2. PIC/Manager: Submit New SKU
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_sku'])) {
    $item_name = trim($_POST['item_name']);
    $google_drive_link = trim($_POST['google_drive_link']);
    $item_group_owner = trim($_POST['item_group_owner']);
    $user_remarks_similarity = isset($_POST['user_remarks_similarity']) ? trim($_POST['user_remarks_similarity']) : '';
    
    $temp_level4 = 'pending_' . uniqid();
    $stmt = $pdo->prepare("INSERT INTO master_data (level_0, level_1, level_2, level_3, level_4, level_5, pic_names, google_drive_link, item_group_owner, status, suggested_by, requested_item_name, user_remarks_similarity, created_by, last_modified_by) VALUES ('', '', '', '', ?, ?, '', ?, ?, 'user_suggested', ?, ?, ?, ?, ?)");
    $stmt->execute([$temp_level4, $item_name, $google_drive_link, $item_group_owner, $current_user, $item_name, $user_remarks_similarity, $audit_actor, $audit_actor]);
    $new_id = $pdo->lastInsertId();
    md_audit_log_insert($pdo, (int)$new_id, $audit_actor);
    header("Location: master_data.php?tab=suggestions&sku_added=1&trigger_find_similar=1&chk_id=$new_id");
    exit;
}

// 2.5. PIC: Resolve Similar SKU
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_similar_sku'])) {
    if ($role === 'PIC') {
        $id = $_POST['suggestion_id'];
        $decision = $_POST['similar_decision']; // 'use_existing' or 'different'
        
        if ($decision === 'use_existing') {
            $similar_id = $_POST['selected_existing_sku_id'];
            $similar_sku_number = trim($_POST['selected_existing_sku_number']);
            $stmt = $pdo->prepare("UPDATE master_data SET status='registered', sku_number=?, similar_sku_id=?, last_modified_by=? WHERE id=?");
            $stmt->execute([$similar_sku_number, $similar_id, $audit_actor, $id]);
            header("Location: master_data.php?tab=suggestions&resolved=1");
        } else {
            $diff_reason = $_POST['similar_sku_difference']; // e.g. "Not specific", "Different brand"
            $remarks = trim($_POST['pic_remarks']);
            $stmt = $pdo->prepare("UPDATE master_data SET similar_sku_difference=?, pic_remarks=?, last_modified_by=? WHERE id=?");
            $stmt->execute([$diff_reason, $remarks, $audit_actor, $id]);
            header("Location: master_data.php?tab=suggestions&trigger_auto_generate=1&chk_id=$id");
        }
        exit;
    }
}

// 3. Category Manager: Approve/Update SKU Suggestion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_suggestion'], $_POST['suggestion_id'])) {
    if ($role === 'Category Manager') {
        $id = $_POST['suggestion_id'];
        $l0 = trim($_POST['level_0'] ?? '');
        $l1 = trim($_POST['level_1'] ?? '');
        $l2 = trim($_POST['level_2'] ?? '');
        $l3 = trim($_POST['level_3'] ?? '');
        $l4 = trim($_POST['level_4'] ?? '');
        $l5 = md_l5_golden_rule($_POST['level_5'] ?? '', $l4);

        $new_pic_names = '';
        if (isset($_POST['pic_names_arr']) && is_array($_POST['pic_names_arr'])) {
            $new_pic_names = implode(', ', array_map('trim', $_POST['pic_names_arr']));
        }
        
        // If this is a Reject action
        if (isset($_POST['action']) && $_POST['action'] === 'reject') {
            $pdo->prepare("DELETE FROM master_data WHERE id = ?")->execute([$id]);
            header("Location: master_data.php?tab=suggestions&rejected=1");
            exit;
        }

        $sku_type_option = $_POST['sku_type_option'] ?? 'existing';
        
        $stmt_check = $pdo->prepare("SELECT status FROM master_data WHERE id=?");
        $stmt_check->execute([$id]);
        $current_status = $stmt_check->fetchColumn();

        if ($current_status === 'registered') {
            $status_val = 'registered';
        } else if ($current_status === 'active') {
            $status_val = 'active';
        } else {
            $status_val = ($sku_type_option === 'new') ? 'approved_new' : 'active';
        }

        $brands = $_POST['available_brands'] ?? [];
        $brands_str = is_array($brands) ? implode(', ', array_map('trim', array_filter($brands))) : trim($brands);

        $sku_number = trim($_POST['sku_number'] ?? '');

        // A different suggestion (an earlier "Submit New SKU", or a separate
        // AI proposal) may already have been approved under this exact
        // category -- approving this one too would write a second live row
        // for the same item. Two signals catch it: an exact (level_4, level_5)
        // match (which the unique key would otherwise turn into an uncaught
        // fatal error right here, since nothing wraps this UPDATE in a
        // try/catch), and a matching requested_item_name -- the same anchor
        // that closed this loophole for AI-enrichment rows, here catching two
        // "Submit New SKU" requests for the literal same free-typed item even
        // when the Category Manager ends up wording the final category
        // slightly differently each time. Merge into the existing one instead:
        // repoint any PR lines already routed here, carry over ticket_ids,
        // then retire this row rather than leave a duplicate active/registered
        // branch.
        $reqNameStmt = $pdo->prepare("SELECT requested_item_name FROM master_data WHERE id = ?");
        $reqNameStmt->execute([$id]);
        $requested_item_name = (string)$reqNameStmt->fetchColumn();

        $dupStmt = $pdo->prepare(
            "SELECT id FROM master_data
             WHERE id != ? AND status IN ('active', 'registered')
               AND (
                    (level_4 = ? AND level_5 = ?)
                    OR (? <> '' AND requested_item_name = ?)
               )
             LIMIT 1"
        );
        $dupStmt->execute([$id, $l4, $l5, $requested_item_name, $requested_item_name]);
        $existing_id = $dupStmt->fetchColumn();

        if ($existing_id) {
            $existing_id = (int)$existing_id;

            $ticketIdsStmt = $pdo->prepare("SELECT ticket_ids FROM master_data WHERE id = ?");
            $ticketIdsStmt->execute([$id]);
            $ticket_ids_raw = (string)$ticketIdsStmt->fetchColumn();
            foreach (array_filter(array_map('trim', explode(',', $ticket_ids_raw))) as $ticket) {
                md_append_ticket_id($pdo, $existing_id, $ticket);
            }

            // Not stamped: prs.last_updated_by/date track assigned_pic, and this
            // merge only re-points master_data_id.
            $pdo->prepare("UPDATE prs SET master_data_id = ? WHERE master_data_id = ?")
                ->execute([$existing_id, $id]);

            $before = md_audit_snapshot($pdo, [$id]);
            $mergeStmt = $pdo->prepare(
                "UPDATE master_data SET status = 'merged_duplicate', suggested_pic_names = NULL,
                    pic_remarks = CONCAT_WS(' | ', NULLIF(pic_remarks, ''), CONCAT('Merged into master_data id ', ?, ' on approval.')),
                    last_modified_by = ? WHERE id = ?"
            );
            $mergeStmt->execute([$existing_id, $audit_actor, $id]);
            md_audit_log_update($pdo, $before, $audit_actor);

            header("Location: master_data.php?tab=suggestions&merged=1&merged_into=$existing_id");
            exit;
        }

        // suggested_pic_names is cleared on approval: the Category Manager has
        // just confirmed (or overridden) it, so it is no longer a pending
        // proposal and must stop showing as one in the taxonomy table.
        $before = md_audit_snapshot($pdo, [$id]);
        $stmt = $pdo->prepare("UPDATE master_data SET level_0=?, level_1=?, level_2=?, level_3=?, level_4=?, level_5=?, pic_names=?, status=?, available_brands=?, sku_number=?, suggested_pic_names=NULL, last_modified_by=? WHERE id=?");
        $stmt->execute([$l0, $l1, $l2, $l3, $l4, $l5, $new_pic_names, $status_val, $brands_str, $sku_number, $audit_actor, $id]);
        md_audit_log_update($pdo, $before, $audit_actor);
        header("Location: master_data.php?tab=suggestions&approved=1&trigger_ai_check=1&chk_id=$id");
        exit;
    }
}

// 3.6. Category Manager: Register SKU
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_sku_action'])) {
    if ($role === 'Category Manager') {
        $id = $_POST['suggestion_id'];
        $final_sku = trim($_POST['final_sku_number']);
        
        $stmt = $pdo->prepare("UPDATE master_data SET sku_number = ?, status = 'registered', last_modified_by = ? WHERE id = ?");
        $stmt->execute([$final_sku, $audit_actor, $id]);
        
        header("Location: master_data.php?tab=suggestions&registered=1");
        exit;
    }
}

// 3.7 Category Manager: Edit Active Row from Table
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_single_row'])) {
    if ($role === 'Category Manager') {
        $id = $_POST['edit_row_id'];
        
        // Handle Reject Action
        if (isset($_POST['action']) && $_POST['action'] === 'reject_suggestion') {
            if (!empty($id)) {
                $stmt_check = $pdo->prepare("SELECT status FROM master_data WHERE id=?");
                $stmt_check->execute([$id]);
                $status = $stmt_check->fetchColumn();

                if ($status === 'ai_suggested') {
                    $pdo->prepare("DELETE FROM master_data WHERE id = ?")->execute([$id]);
                } else {
                    $pdo->prepare("UPDATE master_data SET suggested_pic_names = NULL, suggested_by = NULL, last_modified_by = ? WHERE id = ?")->execute([$audit_actor, $id]);
                }
                header("Location: master_data.php?tab=taxonomy&rejected=1");
                exit;
            }
        }

        $l0 = trim($_POST['level_0'] ?? '');
        $l1 = trim($_POST['level_1'] ?? '');
        $l2 = trim($_POST['level_2'] ?? '');
        $l3 = trim($_POST['level_3'] ?? '');
        $l4 = trim($_POST['level_4'] ?? '');
        $l5 = md_l5_golden_rule($_POST['level_5'] ?? '', $l4);

        $pics = '';
        if (isset($_POST['pic_names_arr']) && is_array($_POST['pic_names_arr'])) {
            $pics = implode(', ', array_map('trim', $_POST['pic_names_arr']));
        }
        
        if (!empty($id) && !empty($l4)) {
            // Editing an AI-discovered row from the taxonomy table approves it --
            // same reasoning as the PIC update above.
            $before = md_audit_snapshot($pdo, [$id]);
            $stmt = $pdo->prepare("UPDATE master_data
                SET level_0=?, level_1=?, level_2=?, level_3=?, level_4=?, level_5=?, pic_names=?,
                    suggested_pic_names=NULL, suggested_by=NULL, last_modified_by=?,
                    status = CASE WHEN status = 'ai_suggested' THEN 'active' ELSE status END
                WHERE id=?");
            $stmt->execute([$l0, $l1, $l2, $l3, $l4, $l5, $pics, $audit_actor, $id]);
            md_audit_log_update($pdo, $before, $audit_actor);
            header("Location: master_data.php?tab=taxonomy&updated=1");
            exit;
        }
    }
}

// 3.5. Manager: Suggest PIC for Pending Suggestion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['suggest_pic_suggestion'])) {
    if ($role === 'Manager') {
        $id = $_POST['suggestion_id'];
        $new_pic_names = '';
        if (isset($_POST['pic_names_arr']) && is_array($_POST['pic_names_arr'])) {
            $new_pic_names = implode(', ', array_map('trim', $_POST['pic_names_arr']));
        }
        $pdo->prepare("UPDATE master_data SET suggested_pic_names = ?, suggested_by = ?, last_modified_by = ? WHERE id = ?")->execute([$new_pic_names, $current_user, $audit_actor, $id]);
        header("Location: master_data.php?tab=suggestions&suggest_update=1");
        exit;
    }
}

// 4. Category Manager: Save Golden Rules
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_golden_rules'])) {
    if ($role === 'Category Manager') {
        $rules_text = trim($_POST['golden_rules_text']);
        // DELETE, not TRUNCATE, and inside a transaction. TRUNCATE is DDL: it
        // commits implicitly and cannot be rolled back, so when the INSERT that
        // followed it failed the golden rules were simply gone -- which is
        // exactly what happened on 2026-08-05, the id column having lost its
        // AUTO_INCREMENT (see the repair migration in config/database.php).
        // The rules steer every AI naming decision in the app; losing them to a
        // half-finished save is not an acceptable outcome of pressing Save.
        $saved = true;
        try {
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM ai_golden_rules");
            if (!empty($rules_text)) {
                $stmt = $pdo->prepare("INSERT INTO ai_golden_rules (rule_text) VALUES (?)");
                $stmt->execute([$rules_text]);
            }
            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Golden rules save failed, previous text kept: ' . $e->getMessage());
            $saved = false;
        }
        header("Location: master_data.php?tab=rules&" . ($saved ? 'rules_saved=1' : 'rules_error=1'));
        exit;
    }
}

// 5. Existing Manual PIC Update on Active Taxonomy
if ($role !== 'PIC' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_pic'])) {
    $new_pic_names = '';
    if (isset($_POST['pic_names_arr']) && is_array($_POST['pic_names_arr'])) {
        $new_pic_names = implode(', ', array_map('trim', $_POST['pic_names_arr']));
    }
    $level_4_key = $_POST['level_4_key'] ?? ''; 
    $row_id = $_POST['row_id'] ?? '';
    
    $brands = $_POST['available_brands'] ?? [];
    $brands_str = is_array($brands) ? implode(', ', array_map('trim', array_filter($brands))) : trim($brands);

    $source_tab = $_POST['source_tab'] ?? '';

    if ($role === 'Category Manager') {
        if (!empty($row_id)) {
            // Setting the PIC on an AI-discovered row IS the approval: it stops
            // being a pending suggestion and joins the knowledge base the AI maps
            // against. Any other status is left exactly as it was.
            $before = md_audit_snapshot($pdo, [$row_id]);
            $updateStmt = $pdo->prepare("UPDATE master_data
                SET pic_names = ?, suggested_pic_names = NULL, suggested_by = NULL, available_brands = ?, last_modified_by = ?,
                    status = CASE WHEN status = 'ai_suggested' THEN 'active' ELSE status END
                WHERE id = ?");
            $updateStmt->execute([$new_pic_names, $brands_str, $audit_actor, $row_id]);
            md_audit_log_update($pdo, $before, $audit_actor);
        } else {
            $before = md_audit_snapshot($pdo, md_audit_ids_by_level($pdo, $level_4_key, false, ['active', 'registered']));
            $updateStmt = $pdo->prepare("UPDATE master_data SET pic_names = ?, suggested_pic_names = NULL, suggested_by = NULL, available_brands = ?, last_modified_by = ? WHERE level_4 = ? AND status IN ('active', 'registered')");
            $updateStmt->execute([$new_pic_names, $brands_str, $audit_actor, $level_4_key]);
            md_audit_log_update($pdo, $before, $audit_actor);
        }

        if ($source_tab === 'suggestions') {
            header("Location: master_data.php?tab=suggestions&manual_update=1");
        } else {
            header("Location: master_data.php?manual_update=1");
        }
    } elseif (!empty($row_id)) {
        // Proposing a PIC on one specific row (the taxonomy table always sends an
        // id). Matching by level_4 instead would splatter the proposal across every
        // sibling sharing that category and miss the AI-discovered row entirely,
        // since those are not 'active'/'registered' yet.
        $updateStmt = $pdo->prepare("UPDATE master_data SET suggested_pic_names = ?, suggested_by = ?, last_modified_by = ? WHERE id = ?");
        $updateStmt->execute([$new_pic_names, $current_user, $audit_actor, $row_id]);

        if ($source_tab === 'suggestions') {
            header("Location: master_data.php?tab=suggestions&suggest_update=1");
        } else {
            header("Location: master_data.php?suggest_update=1");
        }
    } else {
        $updateStmt = $pdo->prepare("UPDATE master_data SET suggested_pic_names = ?, suggested_by = ?, last_modified_by = ? WHERE level_4 = ? AND status IN ('active', 'registered')");
        $updateStmt->execute([$new_pic_names, $current_user, $audit_actor, $level_4_key]);
        
        if ($source_tab === 'suggestions') {
            header("Location: master_data.php?tab=suggestions&suggest_update=1");
        } else {
            header("Location: master_data.php?suggest_update=1");
        }
    }
    exit;
}

require_once '../includes/header.php';

// --- FETCH DATA FOR UI ---
// 'ai_suggested' rows are included alongside the approved taxonomy on purpose:
// those are the items Master Data enrichment discovered on a ticket / SKU
// Dictionary mapping (includes/master_data_enrichment.php). They arrive with a
// blank pic_names and the closest category's PIC in suggested_pic_names, so they
// surface under the "Show Pending Suggestions" quick filter exactly like a
// human-proposed PIC change -- the AI does the mapping, a Category Manager still
// signs it off. They are badged in the table below so they can't be mistaken for
// an already-approved routing rule, and the AI prompts keep reading only
// 'active'/'registered', so nothing routes on them until they are approved.
$stmt = $pdo->query("SELECT id, level_0, level_1, level_2, level_3, level_4, level_5, pic_names, suggested_pic_names, suggested_by, available_brands, sku_number, status FROM master_data WHERE status IN ('active', 'registered', 'ai_suggested') OR status IS NULL ORDER BY level_0, level_1, level_2, level_3, level_4");
$taxonomy_data = $stmt->fetchAll();

$unique_level_0 = array_filter(array_unique(array_column($taxonomy_data, 'level_0')));
$unique_level_1 = array_filter(array_unique(array_column($taxonomy_data, 'level_1')));
$unique_level_2 = array_filter(array_unique(array_column($taxonomy_data, 'level_2')));
sort($unique_level_0); sort($unique_level_1); sort($unique_level_2);

$picStmt = $pdo->query("SELECT name FROM users WHERE role IN ('PIC', 'Admin') ORDER BY name ASC");
$all_pics = $picStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch Suggestions
$sugStmt = $pdo->prepare("SELECT * FROM master_data WHERE status IN ('ai_suggested', 'user_suggested', 'rejected', 'approved_new', 'registered') OR (status = 'active' AND (suggested_by IS NOT NULL OR ai_confidence IS NOT NULL)) ORDER BY (suggested_by = ?) DESC, id DESC");
$sugStmt->execute([$current_user]);
$suggestions_data = $sugStmt->fetchAll();

$pendingCount = 0;
foreach ($taxonomy_data as $row) {
    if (!empty($row['suggested_pic_names'])) {
        $pendingCount++;
    }
}

// Fetch Taxonomy Dropdown Options
$l0_options = $pdo->query("SELECT DISTINCT `Tower` FROM item_taxonomy_db_view WHERE `Tower` IS NOT NULL AND `Tower` != '' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$l1_options = $pdo->query("SELECT DISTINCT `Category` FROM item_taxonomy_db_view WHERE `Category` IS NOT NULL AND `Category` != '' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$l2_options = $pdo->query("SELECT DISTINCT `Sub Category` FROM item_taxonomy_db_view WHERE `Sub Category` IS NOT NULL AND `Sub Category` != '' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$l3_options = $pdo->query("SELECT DISTINCT `Commodity` FROM item_taxonomy_db_view WHERE `Commodity` IS NOT NULL AND `Commodity` != '' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);

// Fetch Golden Rules
$grStmt = $pdo->query("SELECT rule_text FROM ai_golden_rules LIMIT 1");
$current_golden_rule = $grStmt->fetchColumn() ?: "";

$active_tab = $_GET['tab'] ?? 'taxonomy';
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<div class="mb-6 flex justify-between items-end border-b pb-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">Taxonomy Master Data</h2>
        <?php if ($role === 'Category Manager'): ?>
            <p class="text-gray-600">Category Manager Access: Manage AI routing rules, SKU suggestions, and Golden Rules.</p>
        <?php elseif ($role === 'Manager' || $role === 'Admin'): ?>
            <p class="text-gray-600">Manager View: View AI routing rules and suggest new SKUs/PICs to the Category Manager.</p>
        <?php else: ?>
            <p class="text-gray-600">PIC View: View AI routing rules and submit new SKU requests.</p>
        <?php endif; ?>
    </div>
    <div class="flex space-x-3">
        <?php if ($role === 'PIC'): ?>
            <a href="dashboard_pic.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded transition shadow">&larr; Back</a>
        <?php else: ?>
            <a href="dashboard_mgr.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded transition shadow">&larr; Back</a>
        <?php endif; ?>
    </div>
</div>

<!-- TABS NAVIGATION -->
<div class="mb-6 border-b border-gray-200">
    <ul class="flex flex-wrap -mb-px text-sm font-medium text-center text-gray-500">
        <li class="mr-2">
            <a href="?tab=taxonomy" class="inline-flex p-4 border-b-2 rounded-t-lg group <?= $active_tab === 'taxonomy' ? 'text-indigo-600 border-indigo-600 active' : 'border-transparent hover:text-gray-600 hover:border-gray-300' ?>">
                Active Taxonomy
            </a>
        </li>
        <li class="mr-2">
            <a href="?tab=suggestions" class="inline-flex p-4 border-b-2 rounded-t-lg group <?= $active_tab === 'suggestions' ? 'text-indigo-600 border-indigo-600 active' : 'border-transparent hover:text-gray-600 hover:border-gray-300' ?>">
                SKU Suggestions 
                <?php if(count($suggestions_data) > 0): ?>
                    <span class="ml-2 bg-red-100 text-red-800 text-xs font-semibold px-2.5 py-0.5 rounded"><?= count($suggestions_data) ?></span>
                <?php endif; ?>
            </a>
        </li>
        <?php if ($role === 'Category Manager'): ?>
        <li class="mr-2">
            <a href="?tab=import" class="inline-flex p-4 border-b-2 rounded-t-lg group <?= $active_tab === 'import' ? 'text-indigo-600 border-indigo-600 active' : 'border-transparent hover:text-gray-600 hover:border-gray-300' ?>">
                Import Master Data (.CSV)
            </a>
        </li>
        <li class="mr-2">
            <a href="?tab=rules" class="inline-flex p-4 border-b-2 rounded-t-lg group <?= $active_tab === 'rules' ? 'text-indigo-600 border-indigo-600 active' : 'border-transparent hover:text-gray-600 hover:border-gray-300' ?>">
                AI Golden Rules
            </a>
        </li>
        <?php endif; ?>
    </ul>
</div>

<?= $uploadMessage ?>

<?php if (isset($_GET['manual_update']) || isset($_GET['approved'])): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 shadow-sm rounded">
        <p class="font-bold">Approved!</p>
        <p>The routing rule was successfully updated in the AI Database.</p>
    </div>
<?php endif; ?>
<?php if (isset($_GET['rules_saved'])): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 shadow-sm rounded">
        <p class="font-bold">Rules Saved!</p>
        <p>The AI Golden Rules have been successfully updated.</p>
    </div>
<?php endif; ?>
<?php if (isset($_GET['suggest_update']) || isset($_GET['sku_added'])): ?>
    <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-6 shadow-sm rounded">
        <p class="font-bold">SKU Suggestion Processing</p>
        <p>Your SKU has been processed. If auto-generation is successful, you may proceed to register this SKU directly in K2-PSS without waiting for Category Manager's approval.</p>
    </div>
<?php endif; ?>
<?php if (isset($_GET['registered'])): ?>
    <div class="bg-teal-100 border-l-4 border-teal-500 text-teal-700 p-4 mb-6 shadow-sm rounded">
        <p class="font-bold">SKU Registered!</p>
        <p>The SKU was successfully registered in the Master Data and AI Database.</p>
    </div>
<?php endif; ?>
<?php if (isset($_GET['rejected'])): ?>
    <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-6 shadow-sm rounded">
        <p class="font-bold">Suggestion Rejected</p>
        <p>The suggestion was deleted.</p>
    </div>
<?php endif; ?>
<?php if (isset($_GET['merged'])): ?>
    <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-6 shadow-sm rounded">
        <p class="font-bold">Merged into an existing category</p>
        <p>This category already existed (id <?= (int)($_GET['merged_into'] ?? 0) ?>) as an active/registered row. Any PR lines already routed to this suggestion have been repointed there instead of creating a duplicate.</p>
    </div>
<?php endif; ?>

<!-- TAB 1: ACTIVE TAXONOMY -->
<div id="tab-taxonomy" class="<?= $active_tab !== 'taxonomy' ? 'hidden' : '' ?>">
    <?php if ($role === 'Category Manager'): ?>
        <form action="master_data.php?tab=taxonomy" method="POST" class="bg-white p-6 rounded-lg shadow-md border border-gray-200 mb-8">
            <h3 class="block text-gray-700 text-sm font-bold mb-4 border-b pb-2">Manual Add: Single Master Data Row</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Level 0</label>
                    <select name="level_0" class="manual-add-select w-full border rounded p-2 text-sm bg-white">
                        <option value="">-- Select L0 --</option>
                        <?php foreach ($l0_options as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Level 1</label>
                    <select name="level_1" class="manual-add-select w-full border rounded p-2 text-sm bg-white">
                        <option value="">-- Select L1 --</option>
                        <?php foreach ($l1_options as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Level 2</label>
                    <select name="level_2" class="manual-add-select w-full border rounded p-2 text-sm bg-white">
                        <option value="">-- Select L2 --</option>
                        <?php foreach ($l2_options as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Level 3</label>
                    <select name="level_3" class="manual-add-select w-full border rounded p-2 text-sm bg-white">
                        <option value="">-- Select L3 --</option>
                        <?php foreach ($l3_options as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label class="block text-xs font-bold text-gray-700 mb-1">Level 4 <span class="text-red-500">*</span></label><input type="text" name="level_4" required class="w-full border rounded p-2 text-sm" placeholder="e.g. MACBOOK PRO"></div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">SKU (L5)</label>
                    <input type="text" name="level_5" class="w-full border rounded p-2 text-sm" placeholder="e.g. LAPTOP - M3 MAX 64GB">
                    <p class="mt-1 text-[10px] text-gray-500">Golden Rule 3 &mdash; Hybrid SKU: <span class="font-semibold">L4 core - critical attributes</span>. Saved in that form automatically.</p>
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-xs font-bold text-gray-700 mb-2">Assigned PIC(s)</label>
                <input type="hidden" name="pic_names" id="manual_row_pics_input">
                <div class="flex items-center space-x-3">
                    <button type="button" id="openManualRowPicModalBtn" class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded text-gray-700 bg-white hover:bg-gray-50 focus:outline-none transition min-w-fit">
                        Select PIC(s)...
                    </button>
                    <span id="manual_row_pics_display" class="text-sm text-gray-600 font-medium bg-gray-50 px-3 py-2 rounded border border-gray-200 w-full truncate">None Selected</span>
                </div>
            </div>
            <div class="flex justify-end">
                <button type="submit" name="add_single_row" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded shadow">Add Row</button>
            </div>
        </form>
    <?php endif; ?>

    <div class="mb-4">
        <h3 class="text-sm font-bold text-gray-700 mb-2 uppercase tracking-wide">Filter by Category (Level 0)</h3>
        <div class="flex flex-wrap gap-2">
            <button type="button" class="team-filter-btn px-4 py-2 bg-indigo-50 text-indigo-700 font-bold rounded-lg shadow-sm border border-indigo-200 hover:bg-indigo-100 transition ring-2 ring-offset-2 ring-indigo-400 focus:outline-none" data-team="">All Categories</button>
            <?php foreach ($unique_level_0 as $cat): 
                $catLabel = $cat;
                if ($cat === 'Corporate Services, General Supplies & Marketing') $catLabel = 'CSGM';
                elseif ($cat === 'FNB and Operational Merchandise') $catLabel = 'FNBOM';
                elseif ($cat === 'Technology, Industrial, and General Services') $catLabel = 'TIGS';
            ?>
                <button type="button" class="team-filter-btn px-4 py-2 bg-white text-gray-600 font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 transition focus:outline-none" data-team="<?= htmlspecialchars($cat) ?>" title="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($catLabel) ?></button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="mb-6 flex space-x-3 pt-2 items-center">
        <button type="button" class="quick-filter-btn px-4 py-2 bg-gray-50 text-gray-700 font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-100 transition ring-2 ring-offset-2 ring-gray-400 focus:outline-none" data-status="">View All Taxonomy</button>
        <button type="button" class="quick-filter-btn px-4 py-2 bg-yellow-50 text-yellow-700 font-bold rounded-lg shadow-sm border border-yellow-200 hover:bg-yellow-100 transition focus:outline-none relative" data-status="HasSuggestion">
            🔔 Show Pending Suggestions
            <?php if ($role === 'Category Manager' && $pendingCount > 0): ?>
                <span class="absolute -top-2 -right-2 bg-red-500 text-white text-xs font-bold px-2 py-0.5 rounded-full shadow border border-white"><?= $pendingCount ?></span>
            <?php endif; ?>
        </button>
        <div class="flex-1"></div>
        <div class="relative" id="exportDropdownContainerMaster">
            <button type="button" id="exportBtnMaster" class="flex items-center gap-2 bg-indigo-600 text-white text-xs font-bold px-3 py-1.5 rounded-lg hover:bg-indigo-700 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                Export
            </button>
            <div id="exportMenuMaster" class="hidden absolute right-0 mt-2 w-40 bg-white rounded-md shadow-lg border border-gray-200 z-50">
                <div class="px-4 py-2 border-b border-gray-100">
                    <label class="text-xs font-semibold text-gray-600 flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="exportAllPagesMaster" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        All Pages
                    </label>
                </div>
                <div class="py-1">
                    <button type="button" id="masterExportExcel" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">Excel</button>
                    <button type="button" id="masterExportCSV" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">CSV</button>
                    <button type="button" id="masterExportPDF" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">PDF</button>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white shadow-md rounded-lg border border-gray-200 mb-8">
        <div class="px-6 pb-6 pt-2">
            <table id="masterTable" class="min-w-full leading-normal w-full text-left">
                <thead>
                    <tr>
                        <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[80px]">
                            <div class="relative flex items-center justify-between mb-2">
                                <div class="text-xs font-bold text-gray-600 uppercase tracking-wider">ID</div>
                                <input type="text" data-col="0" class="column-search-text w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search...">
                            </div>
                        </th>
                        <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[150px]">
                            <div class="relative flex flex-col items-start mb-2">
                                <div class="text-xs font-bold text-gray-600 uppercase tracking-wider mb-1">L0</div>
                                <select data-col="1" class="column-search-select w-full px-2 py-1 text-xs border border-gray-300 rounded bg-white">
                                    <option value="">All</option>
                                    <?php foreach ($l0_options as $opt): ?>
                                        <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </th>
                        <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[150px]">
                            <div class="relative flex flex-col items-start mb-2">
                                <div class="text-xs font-bold text-gray-600 uppercase tracking-wider mb-1">L1</div>
                                <select data-col="2" class="column-search-select w-full px-2 py-1 text-xs border border-gray-300 rounded bg-white">
                                    <option value="">All</option>
                                    <?php foreach ($l1_options as $opt): ?>
                                        <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </th>
                        <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[150px]">
                            <div class="relative flex flex-col items-start mb-2">
                                <div class="text-xs font-bold text-gray-600 uppercase tracking-wider mb-1">L2</div>
                                <select data-col="3" class="column-search-select w-full px-2 py-1 text-xs border border-gray-300 rounded bg-white">
                                    <option value="">All</option>
                                    <?php foreach ($l2_options as $opt): ?>
                                        <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </th>
                        <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[150px]">
                            <div class="relative flex flex-col items-start mb-2">
                                <div class="text-xs font-bold text-gray-600 uppercase tracking-wider mb-1">L3</div>
                                <select data-col="4" class="column-search-select w-full px-2 py-1 text-xs border border-gray-300 rounded bg-white">
                                    <option value="">All</option>
                                    <?php foreach ($l3_options as $opt): ?>
                                        <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </th>
                        <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[150px]">
                            <div class="relative flex items-center justify-between mb-2">
                                <div class="text-xs font-bold text-gray-600 uppercase tracking-wider">L4</div>
                                <input type="text" data-col="5" class="column-search-text w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search...">
                            </div>
                        </th>
                        <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[150px]">
                            <div class="relative flex items-center justify-between mb-2">
                                <div class="text-xs font-bold text-gray-600 uppercase tracking-wider">SKU (L5)</div>
                                <input type="text" data-col="6" class="column-search-text w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search...">
                            </div>
                        </th>
                        <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[170px]">
                            <div class="relative flex items-center justify-between mb-2">
                                <div class="text-xs font-bold text-gray-600 uppercase tracking-wider">Assigned PIC(s)</div>
                            </div>
                        </th>
                        <?php if ($role === 'Category Manager'): ?>
                        <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[80px]">
                            <div class="text-xs font-bold text-gray-600 uppercase tracking-wider mb-2">Action</div>
                        </th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($taxonomy_data as $row): ?>
                        <?php
                            $has_suggestion = !empty($row['suggested_pic_names']);
                            // A row the AI proposed but nobody has approved yet -- not part of
                            // the knowledge base until a Category Manager signs it off.
                            $is_ai_new = ($row['status'] ?? '') === 'ai_suggested';
                            // The Assigned PIC a Category Manager is about to confirm. An
                            // unassigned row shows the AI's suggestion in the field itself
                            // rather than "-- Empty --", so reviewing is confirm-and-save
                            // instead of re-picking a name the system already worked out.
                            $pic_prefill = $row['pic_names'] ?: ($row['suggested_pic_names'] ?? '');
                            $pic_is_prefill = empty($row['pic_names']) && $pic_prefill !== '';
                        ?>
                        <tr class="hover:bg-indigo-50 transition border-b border-gray-100 <?= $is_ai_new ? 'bg-amber-50/60' : ($has_suggestion ? 'bg-yellow-50/30' : '') ?>">
                            <td class="px-4 py-3 text-sm text-gray-500"><?= $row['id'] ?></td>
                            <td class="px-4 py-3 text-sm font-semibold text-indigo-700"><?= htmlspecialchars($row['level_0'] ?: '-') ?></td>
                            <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($row['level_1']) ?></td>
                            <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($row['level_2']) ?></td>
                            <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($row['level_3']) ?></td>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900"><?= htmlspecialchars($row['level_4']) ?></td>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                <?= htmlspecialchars($row['level_5'] ?? '-') ?>
                                <?php if ($is_ai_new): ?>
                                    <span class="ml-1 align-middle inline-block text-[9px] font-bold text-amber-800 bg-amber-100 border border-amber-300 px-1.5 py-0.5 rounded"
                                          title="New item found on a ticket / SKU mapping and added by the AI. Not used for routing until approved.">NEW · AI</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm" data-search="<?= $has_suggestion ? 'HasSuggestion ' : '' ?><?= htmlspecialchars($pic_prefill) ?>">
                                <?php if ($role === 'PIC'): ?>
                                    <span class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium text-gray-700 bg-gray-50 rounded mb-1">
                                        <?= htmlspecialchars($row['pic_names'] ?: '-- Empty --') ?>
                                    </span>
                                <?php else: ?>
                                    <button type="button"
                                            class="open-modal-btn inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-xs font-medium rounded text-indigo-700 bg-white hover:bg-indigo-50 focus:outline-none transition-colors mb-1"
                                            data-id="<?= $row['id'] ?>"
                                            data-l4="<?= htmlspecialchars($row['level_4']) ?>"
                                            data-pics="<?= htmlspecialchars($row['pic_names']) ?>"
                                            data-suggested="<?= htmlspecialchars($row['suggested_pic_names'] ?? '') ?>"
                                            <?= $pic_is_prefill ? 'title="Pre-filled from the AI suggestion — open and save to confirm it."' : '' ?>>
                                        <?= htmlspecialchars($pic_prefill ?: '-- Empty --') ?>
                                        <?php if ($role === 'Category Manager'): ?>
                                            <svg class="ml-2 h-3 w-3 text-gray-400" fill="currentColor" viewBox="0 0 20 20"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"></path></svg>
                                        <?php else: ?>
                                            <svg class="ml-2 h-3 w-3 text-yellow-500" fill="currentColor" viewBox="0 0 20 20"><path d="M11 3a1 1 0 10-2 0v1a1 1 0 102 0V3zM15.657 5.757a1 1 0 00-1.414-1.414l-.707.707a1 1 0 001.414 1.414l.707-.707zM18 10a1 1 0 01-1 1h-1a1 1 0 110-2h1a1 1 0 011 1zM5.05 6.464A1 1 0 106.464 5.05l-.707-.707a1 1 0 00-1.414 1.414l.707.707zM5 10a1 1 0 01-1 1H3a1 1 0 110-2h1a1 1 0 011 1zM8 16v-1h4v1a2 2 0 11-4 0zM12 14c.015-.34.208-.646.477-.859a4 4 0 10-4.954 0c.27.213.462.519.476.859h4.002z"></path></svg>
                                        <?php endif; ?>
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($has_suggestion): ?>
                                    <!-- Every suggestion gets the yellow callout under the PIC name, whether
                                         it is a pending CHANGE to an assigned row or the pre-fill on an
                                         unassigned one. The pre-filled case used to be hidden on the grounds
                                         that the field above already showed the name -- but the field shows
                                         only the name, so the row read as an ordinary assignment with no sign
                                         it was still unconfirmed and no "By:" to say who proposed it. -->
                                    <div class="mt-1 text-[10px] font-bold text-yellow-700 bg-yellow-100 border border-yellow-300 px-2 py-1 rounded inline-block">
                                        Suggestion: <?= htmlspecialchars($row['suggested_pic_names'] ?: '-- Empty --') ?><br>
                                        <span class="font-normal text-gray-500">By: <?= htmlspecialchars($row['suggested_by']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <?php if ($role === 'Category Manager'): ?>
                            <td class="px-4 py-3 text-sm text-center">
                                <button type="button" class="edit-row-btn text-indigo-600 hover:text-indigo-900 transition-colors"
                                        data-id="<?= $row['id'] ?>"
                                        data-l0="<?= htmlspecialchars($row['level_0']) ?>"
                                        data-l1="<?= htmlspecialchars($row['level_1']) ?>"
                                        data-l2="<?= htmlspecialchars($row['level_2']) ?>"
                                        data-l3="<?= htmlspecialchars($row['level_3']) ?>"
                                        data-l4="<?= htmlspecialchars($row['level_4']) ?>"
                                        data-l5="<?= htmlspecialchars($row['level_5'] ?? '') ?>"
                                        data-pics="<?= htmlspecialchars($row['pic_names']) ?>"
                                        data-suggested="<?= htmlspecialchars($row['suggested_pic_names'] ?? '') ?>"
                                        data-status="<?= htmlspecialchars($row['status'] ?? '') ?>"
                                        title="Edit Row">
                                    <svg class="h-5 w-5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                </button>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- TAB 2: SKU SUGGESTIONS -->
<div id="tab-suggestions" class="<?= $active_tab !== 'suggestions' ? 'hidden' : '' ?>">
    <div class="mb-8">
        <?php if ($role !== 'Category Manager'): ?>
            <div class="bg-white p-6 rounded-lg shadow-md border border-gray-200">
                <h3 class="text-lg font-bold text-gray-800 mb-4">Submit New SKU Request</h3>
                <form action="master_data.php" method="POST" class="space-y-4" id="skuSuggestionForm">
                    <input type="hidden" name="submit_sku" value="1">
                    <input type="hidden" name="user_remarks_similarity" id="user_remarks_similarity" value="">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Type *</label>
                            <select name="item_type" id="sug_item_type" required class="mt-1 block w-full border border-gray-400 px-3 py-2 bg-gray-50 text-slate-800 rounded focus:bg-white focus:ring-indigo-500 focus:border-indigo-500 shadow-inner">
                                <option value="">Select...</option>
                                <option value="Goods">Goods (Barang)</option>
                                <option value="Service">Service (Jasa)</option>
                            </select>
                        </div>
                        <div class="md:col-span-1">
                            <label class="block text-sm font-medium text-gray-700">Item Name (SKU) *</label>
                            <input type="text" name="item_name" id="sug_item_name" required class="mt-1 block w-full border border-gray-400 px-3 py-2 bg-gray-50 text-slate-800 rounded focus:bg-white focus:ring-indigo-500 focus:border-indigo-500 shadow-inner">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Google Drive Link</label>
                            <input type="url" name="google_drive_link" class="mt-1 block w-full border border-gray-400 px-3 py-2 bg-gray-50 text-slate-800 rounded focus:bg-white focus:ring-indigo-500 focus:border-indigo-500 shadow-inner">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Item Group Owner</label>
                            <input type="text" name="item_group_owner" class="mt-1 block w-full border border-gray-400 px-3 py-2 bg-gray-50 text-slate-800 rounded focus:bg-white focus:ring-indigo-500 focus:border-indigo-500 shadow-inner">
                        </div>
                    </div>
                    <button type="submit" name="submit_sku" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded transition shadow">Submit Request</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="bg-white shadow-md rounded-lg border border-gray-200">
        <div class="p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Pending Suggestions (AI & User)</h3>
            
            <div class="mb-4 flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                <div>
                    <h4 class="text-sm font-bold text-gray-700 mb-2 uppercase tracking-wide">Quick Filters (Status)</h4>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="sug-status-btn px-4 py-2 bg-indigo-50 text-indigo-700 font-bold rounded-lg shadow-sm border border-indigo-200 hover:bg-indigo-100 transition ring-2 ring-offset-2 ring-indigo-400 focus:outline-none" data-status="">All</button>
                        <button type="button" class="sug-status-btn px-4 py-2 bg-yellow-50 text-yellow-700 font-bold rounded-lg shadow-sm border border-yellow-200 hover:bg-yellow-100 transition focus:outline-none" data-status="User Suggested">User Suggested</button>
                        <button type="button" class="sug-status-btn px-4 py-2 bg-blue-50 text-blue-700 font-bold rounded-lg shadow-sm border border-blue-200 hover:bg-blue-100 transition focus:outline-none" data-status="AI Suggested">AI Suggested</button>
                        <button type="button" class="sug-status-btn px-4 py-2 bg-green-50 text-green-700 font-bold rounded-lg shadow-sm border border-green-200 hover:bg-green-100 transition focus:outline-none" data-status="Approved|Mapped|Registered">Approved / Registered</button>
                        <button type="button" class="sug-status-btn px-4 py-2 bg-red-50 text-red-700 font-bold rounded-lg shadow-sm border border-red-200 hover:bg-red-100 transition focus:outline-none" data-status="Rejected">Rejected</button>
                    </div>
                </div>
                
                <div class="relative group z-30 pt-6">
                    <button type="button" class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-lg shadow-sm transition focus:outline-none">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                        Export
                        <svg class="w-4 h-4 ml-2 -mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div class="absolute right-0 mt-1 w-40 bg-white border border-gray-200 rounded-md shadow-lg hidden group-hover:block transition-all opacity-0 group-hover:opacity-100 overflow-hidden">
                        <div class="py-1">
                            <button type="button" id="customExportExcel" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">Excel</button>
                            <button type="button" id="customExportCSV" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">CSV</button>
                            <button type="button" id="customExportPDF" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">PDF</button>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <table id="suggestionsTable" class="min-w-full leading-normal w-full text-left">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="th-sug-filter px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[60px]">
                                <div class="text-xs font-bold text-gray-600 uppercase mb-2">ID</div>
                                <input type="text" data-col="0" class="sug-search-text w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search...">
                            </th>
                            <th class="th-sug-filter px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[120px]">
                                <div class="text-xs font-bold text-gray-600 uppercase mb-2">Status</div>
                                <input type="text" data-col="1" class="sug-search-text w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search...">
                            </th>
                            <th class="th-sug-filter px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[100px]">
                                <div class="text-xs font-bold text-gray-600 uppercase mb-2">SLA</div>
                                <input type="text" data-col="2" class="sug-search-text w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search...">
                            </th>
                            <th class="th-sug-filter px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[150px]">
                                <div class="text-xs font-bold text-gray-600 uppercase mb-2">Item/SKU Info</div>
                                <input type="text" data-col="3" class="sug-search-text w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search...">
                            </th>
                            <th class="th-sug-filter px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[120px]">
                                <div class="text-xs font-bold text-gray-600 uppercase mb-2">Brands</div>
                                <input type="text" data-col="4" class="sug-search-text w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search...">
                            </th>
                            <th class="th-sug-filter px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[150px]">
                                <div class="text-xs font-bold text-gray-600 uppercase mb-2">Suggested Taxonomy</div>
                                <input type="text" data-col="5" class="sug-search-text w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search...">
                            </th>
                            <th class="th-sug-filter px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[120px]">
                                <div class="text-xs font-bold text-gray-600 uppercase mb-2">PIC Requestor</div>
                                <input type="text" data-col="6" class="sug-search-text w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search...">
                            </th>
                            <th class="th-sug-filter px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[120px]">
                                <div class="text-xs font-bold text-gray-600 uppercase mb-2">Request Time</div>
                                <input type="text" data-col="7" class="sug-search-text w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search...">
                            </th>
                            <th class="th-sug-filter px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[120px]">
                                <div class="text-xs font-bold text-gray-600 uppercase mb-2">Last Updated</div>
                                <input type="text" data-col="8" class="sug-search-text w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search...">
                            </th>
                            <th class="px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[120px]">
                                <div class="text-xs font-bold text-gray-600 uppercase">Actions</div>
                            </th>
                            <th class="hidden">L0</th>
                            <th class="hidden">L1</th>
                            <th class="hidden">L2</th>
                            <th class="hidden">L3</th>
                            <th class="hidden">L4</th>
                            <th class="hidden">L5</th>
                            <th class="hidden">Suggested PIC</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($suggestions_data) === 0): ?>
                            <tr><td colspan="8" class="px-4 py-4 text-center text-gray-500">No pending suggestions.</td></tr>
                        <?php else: ?>
                            <?php foreach ($suggestions_data as $sug): ?>
                                <tr class="border-b border-gray-100 hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm text-gray-500"><?= $sug['id'] ?></td>
                                    <td class="px-4 py-3">
                                        <?php if ($sug['status'] === 'ai_suggested'): ?>
                                            <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded whitespace-nowrap">AI Suggested</span>
                                        <?php elseif ($sug['status'] === 'active'): ?>
                                            <span class="bg-green-100 text-green-800 text-xs font-semibold px-2.5 py-0.5 rounded whitespace-nowrap">Mapped (Existing)</span>
                                        <?php elseif ($sug['status'] === 'approved_new'): ?>
                                            <span class="bg-green-100 text-green-800 text-xs font-semibold px-2.5 py-0.5 rounded whitespace-nowrap">Mapped (New)</span>
                                        <?php elseif ($sug['status'] === 'registered'): ?>
                                            <span class="bg-green-100 text-green-800 text-xs font-semibold px-2.5 py-0.5 rounded whitespace-nowrap">Registered</span>
                                        <?php elseif ($sug['status'] === 'rejected'): ?>
                                            <span class="bg-red-100 text-red-800 text-xs font-semibold px-2.5 py-0.5 rounded whitespace-nowrap">Rejected</span>
                                        <?php else: ?>
                                            <span class="bg-yellow-100 text-yellow-800 text-xs font-semibold px-2.5 py-0.5 rounded whitespace-nowrap">User Suggested</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm font-semibold">
                                        <?php if ($sug['status'] === 'user_suggested'): ?>
                                            <?php
                                                // 5-day SLA logic for Category Manager to review
                                                $workingDaysElapsed = getWorkingDaysDuration($sug['created_at'], date('Y-m-d H:i:s'), $pdo);
                                                $slaRemaining = 5 - $workingDaysElapsed;
                                                if ($slaRemaining < 1):
                                            ?>
                                                <span class="bg-red-50 text-red-700 border border-red-300 text-[10px] font-bold px-2 py-0.5 rounded shadow-sm animate-pulse whitespace-nowrap">Overdue (<1 day)</span>
                                            <?php else: ?>
                                                <span class="text-gray-600 text-[11px] whitespace-nowrap"><?= $slaRemaining ?> days left</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-gray-400 italic text-xs">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        <div class="mb-1 text-xs text-gray-500">Req: <?= htmlspecialchars($sug['requested_item_name'] ?: '-') ?></div>
                                        <strong><?= htmlspecialchars($sug['level_5'] ?: $sug['level_4'] ?: '-') ?></strong>
                                        <?php if ($sug['sku_number']): ?>
                                            <div class="mt-1 text-xs font-bold text-teal-700">SKU: <?= htmlspecialchars($sug['sku_number']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($sug['similar_sku_difference'])): ?>
                                            <div class="mt-2 text-xs border-l-2 border-orange-400 pl-2 bg-orange-50 p-1">
                                                <strong class="text-orange-800">PIC Note:</strong> <?= htmlspecialchars($sug['similar_sku_difference']) ?>
                                                <?php if (!empty($sug['pic_remarks'])): ?>
                                                    <br><span class="text-gray-600">"<?= htmlspecialchars($sug['pic_remarks']) ?>"</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($sug['google_drive_link']): ?>
                                            <br><a href="<?= htmlspecialchars($sug['google_drive_link']) ?>" target="_blank" class="text-indigo-600 hover:underline text-xs">Drive Link</a>
                                        <?php endif; ?>
                                        <?php if ($sug['item_group_owner']): ?>
                                            <br><span class="text-xs text-gray-500">Owner: <?= htmlspecialchars($sug['item_group_owner']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-600">
                                        <?= htmlspecialchars($sug['available_brands'] ?: '-') ?>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-600">
                                        L0: <?= htmlspecialchars($sug['level_0']) ?><br>
                                        L1: <?= htmlspecialchars($sug['level_1']) ?><br>
                                        L2: <?= htmlspecialchars($sug['level_2']) ?><br>
                                        L3: <?= htmlspecialchars($sug['level_3']) ?><br>
                                        L4: <?= htmlspecialchars($sug['level_4']) ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        <?php if ($sug['status'] === 'ai_suggested'): ?>
                                            <?php // Match strength against Master Data, not raw AI confidence:
                                                  // anything under the enrichment threshold is why this row exists
                                                  // at all, so it is only alarming (red) when it is also weak. ?>
                                            Match: <strong class="<?= $sug['ai_confidence'] < 50 ? 'text-red-600' : 'text-amber-600' ?>"><?= htmlspecialchars($sug['ai_confidence']) ?>%</strong>
                                            <?php if (!empty($sug['suggested_by'])): ?>
                                                <br><span class="text-[10px] text-gray-500">By: <?= htmlspecialchars($sug['suggested_by']) ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            By: <?= htmlspecialchars($sug['suggested_by']) ?>
                                        <?php endif; ?>
                                        <?php if ($sug['suggested_pic_names']): ?>
                                            <br><span class="text-[10px] text-indigo-700 font-bold">Suggested PIC: <?= htmlspecialchars($sug['suggested_pic_names']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-500">
                                        <?= $sug['created_at'] ? formatJakartaDate($sug['created_at']) : '-' ?>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-500">
                                        <?= $sug['updated_at'] ? formatJakartaDate($sug['updated_at']) : '-' ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if ($role === 'Category Manager'): ?>
                                            <?php if ($sug['status'] === 'approved_new'): ?>
                                                <button type="button" class="register-sku-btn bg-teal-600 hover:bg-teal-700 text-white font-bold py-1 px-3 rounded text-xs transition shadow-sm block w-full mb-1"
                                                    data-id="<?= $sug['id'] ?>"
                                                    data-desc="<?= htmlspecialchars($sug['level_5'] ?: $sug['level_4'] ?: '') ?>"
                                                >Register SKU</button>
                                            <?php elseif ($sug['status'] === 'active' || $sug['status'] === 'registered'): ?>
                                                <button type="button" class="open-modal-btn bg-white border border-gray-300 shadow-sm text-indigo-700 hover:bg-indigo-50 font-bold py-1 px-3 rounded text-sm transition block w-full mb-1"
                                                    data-id="<?= $sug['id'] ?>"
                                                    data-l4="<?= htmlspecialchars($sug['level_4']) ?>"
                                                    data-pics="<?= htmlspecialchars($sug['pic_names'] ?? '') ?>"
                                                    data-suggested="<?= htmlspecialchars($sug['suggested_pic_names'] ?? '') ?>">
                                                    Update Routing Rule
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($sug['status'] !== 'rejected'): ?>
                                                <button type="button" class="review-btn bg-indigo-100 text-indigo-700 hover:bg-indigo-200 font-bold py-1 px-3 rounded text-sm transition block w-full mb-1"
                                                    data-id="<?= $sug['id'] ?>"
                                                    data-l0="<?= htmlspecialchars($sug['level_0'] ?? '') ?>"
                                                    data-l1="<?= htmlspecialchars($sug['level_1'] ?? '') ?>"
                                                    data-l2="<?= htmlspecialchars($sug['level_2'] ?? '') ?>"
                                                    data-l3="<?= htmlspecialchars($sug['level_3'] ?? '') ?>"
                                                    data-l4="<?= htmlspecialchars(strpos($sug['level_4'] ?? '', 'pending_') === 0 ? '' : $sug['level_4']) ?>"
                                                    data-l5="<?= htmlspecialchars($sug['level_5'] ?? '') ?>"
                                                    data-pics="<?= htmlspecialchars($sug['pic_names'] ?? '') ?>"
                                                    data-suggested="<?= htmlspecialchars($sug['suggested_pic_names'] ?? '') ?>"
                                                    data-brands="<?= htmlspecialchars($sug['available_brands'] ?? '') ?>"
                                                    data-sku="<?= htmlspecialchars($sug['sku_number'] ?? '') ?>"
                                                    data-status="<?= htmlspecialchars($sug['status'] ?? '') ?>"
                                                    data-desc="<?= htmlspecialchars($sug['level_5'] ?: $sug['level_4'] ?: '') ?>"
                                                ><?= in_array($sug['status'], ['active', 'approved_new', 'registered']) ? 'Edit' : 'Review' ?></button>
                                            <?php else: ?>
                                                <span class="text-gray-400 italic text-xs">Reviewed</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php if ($role === 'PIC' && !empty($sug['ai_similar_skus_json']) && $sug['ai_similar_skus_json'] !== '[]' && empty($sug['similar_sku_id']) && empty($sug['similar_sku_difference'])): ?>
                                                <button type="button" class="review-similar-btn bg-yellow-100 text-yellow-800 hover:bg-yellow-200 font-bold py-1 px-3 rounded text-xs transition block w-full mb-1 border border-yellow-300"
                                                    data-id="<?= $sug['id'] ?>"
                                                    data-similar="<?= htmlspecialchars($sug['ai_similar_skus_json']) ?>"
                                                >Review Similar SKUs</button>
                                            <?php endif; ?>
                                            
                                            <?php if ($sug['status'] === 'approved_new'): ?>
                                                <span class="text-orange-600 font-semibold text-xs">Awaiting Registration</span>
                                            <?php elseif ($sug['status'] === 'active' || $sug['status'] === 'registered' || $sug['status'] === 'rejected'): ?>
                                                <span class="text-gray-400 italic text-xs">Reviewed</span>
                                            <?php elseif ($role === 'Manager'): ?>
                                                <button type="button" class="suggest-pic-btn bg-yellow-100 text-yellow-700 hover:bg-yellow-200 font-bold py-1 px-3 rounded text-sm transition"
                                                    data-id="<?= $sug['id'] ?>"
                                                    data-desc="<?= htmlspecialchars($sug['level_5'] ?: $sug['level_4'] ?: '') ?>"
                                                    data-suggested-pics="<?= htmlspecialchars($sug['suggested_pic_names'] ?? '') ?>"
                                                >Assign PIC</button>
                                            <?php else: ?>
                                                <span class="text-gray-400 italic text-xs">Awaiting CM</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="hidden"><?= htmlspecialchars($sug['level_0']) ?></td>
                                    <td class="hidden"><?= htmlspecialchars($sug['level_1']) ?></td>
                                    <td class="hidden"><?= htmlspecialchars($sug['level_2']) ?></td>
                                    <td class="hidden"><?= htmlspecialchars($sug['level_3']) ?></td>
                                    <td class="hidden"><?= htmlspecialchars($sug['level_4']) ?></td>
                                    <td class="hidden"><?= htmlspecialchars($sug['level_5']) ?></td>
                                    <td class="hidden"><?= htmlspecialchars($sug['suggested_pic_names'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- TAB 3: AI GOLDEN RULES -->
<?php if ($role === 'Category Manager'): ?>
<div id="tab-rules" class="<?= $active_tab !== 'rules' ? 'hidden' : '' ?>">
    <div class="bg-white p-6 rounded-lg shadow-md border border-gray-200">
        <h3 class="text-lg font-bold text-gray-800 mb-2">AI Taxonomy Golden Rules</h3>
        <p class="text-gray-600 text-sm mb-4">Provide free-text instructions, rules, or logic for the AI to follow when classifying PR items into the Taxonomy levels.</p>
        
        <form action="master_data.php" method="POST">
            <textarea name="golden_rules_text" rows="12" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 p-3" placeholder="Example: If an item is a laptop, ensure it is classified under L0='Technology', L1='Hardware'..."><?= htmlspecialchars($current_golden_rule) ?></textarea>
            <div class="mt-4 flex justify-end">
                <button type="submit" name="save_golden_rules" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded transition shadow">Save Golden Rules</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- TAB 4: IMPORT MASTER DATA (CSV) -->
<?php if ($role === 'Category Manager'): ?>
<div id="tab-import" class="<?= $active_tab !== 'import' ? 'hidden' : '' ?>">
    <div class="bg-white p-6 rounded-lg shadow-md border border-gray-200 mb-8">
        <h3 class="text-lg font-bold text-gray-800 mb-2">Import Master Data (.CSV)</h3>
        <p class="text-gray-600 text-sm mb-4">
            Upload a CSV to update existing Master Data rows by <code class="bg-gray-100 px-1 rounded">id</code>, or add
            rows whose id doesn't exist yet. Nothing is written until you review the change preview and confirm --
            a row missing from the CSV is simply left alone. Required columns (header names, any order):
            <code class="bg-gray-100 px-1 rounded">id, level_0, level_1, level_2, level_3, level_4, level_5, pic_names</code>.
        </p>
        <div class="flex items-end space-x-4">
            <div class="flex-grow">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="import_csv_file">Choose CSV File</label>
                <input type="file" id="import_csv_file" accept=".csv"
                       class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2" for="import_csv_delimiter">Delimiter</label>
                <select id="import_csv_delimiter"
                        class="shadow appearance-none border rounded py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500 h-[42px]">
                    <option value="," selected>Comma (,)</option>
                    <option value=";">Semicolon (;)</option>
                    <option value="\t">Tab</option>
                    <option value="|">Pipe (|)</option>
                </select>
            </div>
        </div>
        <div class="flex justify-start items-center space-x-3 mt-4">
            <button type="button" id="import_download_template" class="bg-white hover:bg-indigo-50 text-indigo-700 font-bold py-2 px-4 rounded-lg shadow-sm hover:shadow border border-indigo-200 transition-all duration-150 h-[42px] inline-flex items-center text-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                Download Template
            </button>
            <button type="button" id="import_upload_validate" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-lg shadow-sm hover:shadow-md transition-all duration-150 h-[42px] inline-flex items-center text-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M12 12v9m0-9l3 3m-3-3l-3 3"></path></svg>
                Upload &amp; Validate
            </button>
        </div>
        <div id="import_upload_error" class="hidden mt-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded"></div>
    </div>

    <!-- Change Preview -->
    <div id="import_preview_panel" class="hidden bg-white p-6 rounded-lg shadow-md border border-gray-200 mb-8">
        <h3 class="text-lg font-bold text-gray-800 mb-4">Change Preview</h3>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-4 text-center">
            <div class="bg-gray-50 rounded p-3"><div class="text-xl font-bold text-gray-800" id="import_sum_total">0</div><div class="text-xs text-gray-500 uppercase">Total Rows</div></div>
            <div class="bg-gray-50 rounded p-3"><div class="text-xl font-bold text-gray-600" id="import_sum_unchanged">0</div><div class="text-xs text-gray-500 uppercase">Unchanged</div></div>
            <div class="bg-yellow-50 rounded p-3"><div class="text-xl font-bold text-yellow-700" id="import_sum_updated">0</div><div class="text-xs text-gray-500 uppercase">Updated</div></div>
            <div class="bg-blue-50 rounded p-3"><div class="text-xl font-bold text-blue-700" id="import_sum_new">0</div><div class="text-xs text-gray-500 uppercase">New / ID Not Found</div></div>
            <div class="bg-red-50 rounded p-3"><div class="text-xl font-bold text-red-700" id="import_sum_invalid">0</div><div class="text-xs text-gray-500 uppercase">Invalid</div></div>
        </div>

        <!-- Filter pills -->
        <div class="flex flex-wrap items-center gap-2 mb-3">
            <button type="button" data-import-filter="all" class="import-filter-btn px-3 py-1 rounded-full text-xs font-bold border">All</button>
            <button type="button" data-import-filter="UPDATED" class="import-filter-btn px-3 py-1 rounded-full text-xs font-bold border">Updated</button>
            <button type="button" data-import-filter="NEW" class="import-filter-btn px-3 py-1 rounded-full text-xs font-bold border">New</button>
            <button type="button" data-import-filter="UNCHANGED" class="import-filter-btn px-3 py-1 rounded-full text-xs font-bold border">Unchanged</button>
            <button type="button" data-import-filter="INVALID" class="import-filter-btn px-3 py-1 rounded-full text-xs font-bold border">Invalid</button>
            <span class="flex-grow"></span>
            <button type="button" id="import_select_all" class="px-3 py-1 rounded-full text-xs font-bold border border-indigo-300 text-indigo-600 bg-white hover:bg-indigo-50">Select all in view</button>
            <button type="button" id="import_select_none" class="px-3 py-1 rounded-full text-xs font-bold border border-gray-300 text-gray-600 bg-white hover:bg-gray-50">Deselect all in view</button>
        </div>

        <div id="import_rows_list" class="border border-gray-200 rounded max-h-[28rem] overflow-auto"></div>
        <p id="import_rows_more" class="hidden text-xs text-gray-500 mt-1"></p>

        <div class="flex justify-end space-x-3 border-t pt-4 mt-4">
            <button type="button" id="import_cancel" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-2 px-6 rounded shadow transition">Cancel</button>
            <button type="button" id="import_confirm" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded shadow transition disabled:opacity-50 disabled:cursor-not-allowed">Confirm &amp; Apply Changes</button>
        </div>
    </div>

    <!-- Result -->
    <div id="import_result_panel" class="hidden bg-white p-6 rounded-lg shadow-md border border-gray-200 mb-8">
        <h3 class="text-lg font-bold text-gray-800 mb-4">Update Completed</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4 text-center max-w-2xl">
            <div class="bg-yellow-50 rounded p-3"><div class="text-xl font-bold text-yellow-700" id="import_res_updated">0</div><div class="text-xs text-gray-500 uppercase">Updated</div></div>
            <div class="bg-blue-50 rounded p-3"><div class="text-xl font-bold text-blue-700" id="import_res_added">0</div><div class="text-xs text-gray-500 uppercase">Added</div></div>
            <div class="bg-gray-50 rounded p-3"><div class="text-xl font-bold text-gray-600" id="import_res_skipped">0</div><div class="text-xs text-gray-500 uppercase">Skipped</div></div>
            <div class="bg-red-50 rounded p-3"><div class="text-xl font-bold text-red-700" id="import_res_failed">0</div><div class="text-xs text-gray-500 uppercase">Failed</div></div>
        </div>
        <div id="import_res_message" class="text-sm text-gray-600 mb-4"></div>
    </div>
</div>
<?php endif; ?>

<!-- MODAL: SUGGESTION REVIEW (Category Manager Only) -->
<?php if ($role === 'Category Manager'): ?>
<div id="reviewModal" class="fixed z-50 inset-0 hidden overflow-y-auto">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
    <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" id="reviewModalOverlay"></div>
    <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
    <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
      <form method="POST" action="master_data.php">
          <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
              <h3 class="text-lg font-bold text-gray-900 mb-4">Review & Approve SKU/Taxonomy</h3>
              <input type="hidden" name="suggestion_id" id="rev_id">
              <input type="hidden" name="approve_suggestion" value="1">
              
              <div class="flex justify-between items-center mb-4 p-3 bg-indigo-50 rounded">
                  <div class="text-sm font-semibold text-indigo-800">Target Item: <span id="rev_desc_display"></span></div>
                  <button type="button" id="btn_ask_ai" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold py-1.5 px-3 rounded shadow transition flex items-center">
                      <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                      Ask AI to Autofill
                  </button>
              </div>

               <!-- AI Confidence Display -->
              <div id="ai_autofill_result" class="hidden mb-4 p-3 rounded text-sm"></div>

              <!-- SKU Type Option Selection -->
              <div class="mb-4 bg-gray-50 p-3 rounded border border-gray-200">
                  <label class="block text-xs font-bold text-gray-700 mb-2">SKU Type Option</label>
                  <div class="flex items-center space-x-6">
                      <label class="inline-flex items-center cursor-pointer">
                          <input type="radio" name="sku_type_option" value="existing" checked class="form-radio h-4 w-4 text-indigo-600 focus:ring-indigo-500">
                          <span class="ml-2 text-sm text-gray-700">Use Existing SKU</span>
                      </label>
                      <label class="inline-flex items-center cursor-pointer">
                          <input type="radio" name="sku_type_option" value="new" class="form-radio h-4 w-4 text-indigo-600 focus:ring-indigo-500">
                          <span class="ml-2 text-sm text-gray-700">Create as New SKU</span>
                      </label>
                  </div>
              </div>

              <div class="grid grid-cols-2 gap-4 mb-4">
                  <div><label class="block text-xs font-bold text-gray-700">Level 0</label><input type="text" name="level_0" id="rev_l0" class="mt-1 w-full border border-gray-400 px-3 py-2 bg-gray-50 text-slate-800 rounded focus:bg-white shadow-inner text-sm"></div>
                  <div><label class="block text-xs font-bold text-gray-700">Level 1</label><input type="text" name="level_1" id="rev_l1" class="mt-1 w-full border border-gray-400 px-3 py-2 bg-gray-50 text-slate-800 rounded focus:bg-white shadow-inner text-sm"></div>
                  <div><label class="block text-xs font-bold text-gray-700">Level 2</label><input type="text" name="level_2" id="rev_l2" class="mt-1 w-full border border-gray-400 px-3 py-2 bg-gray-50 text-slate-800 rounded focus:bg-white shadow-inner text-sm"></div>
                  <div><label class="block text-xs font-bold text-gray-700">Level 3</label><input type="text" name="level_3" id="rev_l3" class="mt-1 w-full border border-gray-400 px-3 py-2 bg-gray-50 text-slate-800 rounded focus:bg-white shadow-inner text-sm"></div>
                  <div><label class="block text-xs font-bold text-gray-700">Level 4</label><input type="text" name="level_4" id="rev_l4" class="mt-1 w-full border border-gray-400 px-3 py-2 bg-gray-50 text-slate-800 rounded focus:bg-white shadow-inner text-sm"></div>
                  <div>
                      <label class="block text-xs font-bold text-gray-700">SKU Name (Level 5)</label>
                      <input type="text" name="level_5" id="rev_l5" class="mt-1 w-full border border-gray-400 px-3 py-2 bg-gray-50 text-slate-800 rounded focus:bg-white shadow-inner text-sm">
                      <p class="mt-1 text-[10px] text-gray-500">Golden Rule 3 &mdash; Hybrid SKU: <span class="font-semibold">L4 core - critical attributes</span>. Saved in that form automatically.</p>
                  </div>
              </div>

              <div class="mb-4 bg-gray-50 p-3 rounded border border-gray-200" id="rev_sku_container">
                  <label class="block text-xs font-bold text-gray-700 mb-2">SKU Number (If Registered)</label>
                  <input type="text" name="sku_number" id="rev_sku" class="mt-1 w-full border border-gray-400 px-3 py-2 bg-white text-slate-800 rounded focus:bg-white shadow-inner text-sm" placeholder="e.g. IT-NET-001">
              </div>

              <!-- Available Brands Dynamic Input -->
              <div class="mb-4">
                  <label class="block text-xs font-bold text-gray-700 mb-2">Available Brands</label>
                  <div id="brands_container" class="space-y-2">
                      <!-- Existing/New inputs go here -->
                  </div>
                  <button type="button" id="btn_add_brand" class="mt-2 text-xs text-indigo-600 hover:text-indigo-800 font-bold flex items-center">
                      <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                      Add Brand
                  </button>
              </div>

              <label class="block text-xs font-bold text-gray-700 mb-2">Assign PIC(s)</label>
              <div class="border border-gray-300 rounded-md overflow-hidden bg-white shadow-sm max-h-40 overflow-y-auto p-2" id="rev_pic_list">
                  <?php foreach ($all_pics as $pic): ?>
                      <label class="flex items-center p-1 hover:bg-indigo-50 rounded cursor-pointer transition-colors">
                          <input type="checkbox" name="pic_names_arr[]" value="<?= htmlspecialchars($pic) ?>" class="rev-pic-checkbox h-4 w-4 text-indigo-600 rounded">
                          <span class="ml-2 text-sm text-gray-700"><?= htmlspecialchars($pic) ?></span>
                      </label>
                  <?php endforeach; ?>
              </div>
          </div>
          <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
            <button type="submit" name="action" value="approve" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-green-600 hover:bg-green-700 text-base font-medium text-white sm:ml-3 sm:w-auto sm:text-sm">Approve & Save to Active</button>
            <button type="submit" name="action" value="reject" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-red-600 hover:bg-red-700 text-base font-medium text-white sm:ml-3 sm:w-auto sm:text-sm">Reject & Delete</button>
            <button type="button" id="closeReviewModal" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">Cancel</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL: REGISTER SKU (Category Manager Only) -->
<div id="registerSkuModal" class="fixed z-50 inset-0 hidden overflow-y-auto">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
    <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" id="registerSkuModalOverlay"></div>
    <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
    <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full">
      <form method="POST" action="master_data.php">
          <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
              <h3 class="text-lg font-bold text-gray-900 mb-4">Complete SKU Registration</h3>
              <p class="text-sm text-gray-500 mb-4">Item/SKU: <strong id="reg_desc" class="text-teal-600"></strong></p>
              <input type="hidden" name="suggestion_id" id="reg_id">
              <input type="hidden" name="register_sku_action" value="1">
              
              <div>
                  <label class="block text-sm font-medium text-gray-700">Enter ERP SKU Number *</label>
                  <input type="text" name="final_sku_number" id="reg_sku_number" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm" placeholder="e.g. SKU-12345">
              </div>
          </div>
          <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
            <button type="submit" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-teal-600 hover:bg-teal-700 text-base font-medium text-white sm:ml-3 sm:w-auto sm:text-sm">Register SKU</button>
            <button type="button" id="closeRegisterSkuModal" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">Cancel</button>
          </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- MODAL: MANAGER SUGGEST PIC FOR PENDING REQUEST -->
<?php if ($role === 'Manager'): ?>
<div id="suggestPicModal" class="fixed z-50 inset-0 hidden overflow-y-auto">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
    <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" id="suggestPicModalOverlay"></div>
    <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
    <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full">
      <form method="POST" action="master_data.php">
          <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
              <h3 class="text-lg font-bold text-gray-900 mb-4">Assign PIC to New SKU</h3>
              <p class="text-sm text-gray-500 mb-4">Item: <strong id="spm_desc" class="text-indigo-600"></strong></p>
              <input type="hidden" name="suggestion_id" id="spm_id">
              <input type="hidden" name="suggest_pic_suggestion" value="1">
              
              <div class="max-h-48 overflow-y-auto p-2 border rounded">
                  <?php foreach ($all_pics as $pic): ?>
                      <label class="flex items-center p-2 hover:bg-indigo-50 rounded cursor-pointer">
                          <input type="checkbox" name="pic_names_arr[]" value="<?= htmlspecialchars($pic) ?>" class="spm-pic-checkbox h-4 w-4 text-indigo-600 rounded">
                          <span class="ml-3 text-sm text-gray-700"><?= htmlspecialchars($pic) ?></span>
                      </label>
                  <?php endforeach; ?>
              </div>
          </div>
          <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
            <button type="submit" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-yellow-600 hover:bg-yellow-700 text-base font-medium text-white sm:ml-3 sm:w-auto sm:text-sm">Assign PIC</button>
            <button type="button" id="closeSuggestPicModal" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">Cancel</button>
          </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($role !== 'PIC'): ?>
<!-- MODAL: MANUAL PIC UPDATE (Legacy) -->
<div id="updateModal" class="fixed z-50 inset-0 hidden overflow-y-auto">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
    <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" id="modalOverlay"></div>
    <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
    <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full">
      <form method="POST" action="master_data.php">
          <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
              <h3 class="text-lg font-medium text-gray-900"><?= $role === 'Category Manager' ? 'Update Routing Rule' : 'Suggest Routing Change' ?></h3>
              <p class="text-sm text-gray-500 mb-4">Edit the PIC assignment for: <strong id="modal-cat-display" class="text-indigo-600"></strong></p>
              <input type="hidden" name="level_4_key" id="modal-l4-input">
              <input type="hidden" name="row_id" id="modal-id-input">
              <input type="hidden" name="source_tab" id="modal-source-tab" value="">
              <input type="hidden" name="update_pic" value="1">
              <div class="max-h-48 overflow-y-auto p-2 border rounded">
                  <?php foreach ($all_pics as $pic): ?>
                      <label class="flex items-center p-2 hover:bg-indigo-50 rounded cursor-pointer">
                          <input type="checkbox" name="pic_names_arr[]" value="<?= htmlspecialchars($pic) ?>" class="modal-pic-checkbox h-4 w-4 text-indigo-600 rounded">
                          <span class="ml-3 text-sm text-gray-700"><?= htmlspecialchars($pic) ?></span>
                      </label>
                  <?php endforeach; ?>
              </div>
          </div>
          <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
            <button type="submit" class="w-full inline-flex justify-center rounded-md shadow-sm px-4 py-2 <?= $role === 'Category Manager' ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-yellow-500 hover:bg-yellow-600' ?> font-medium text-white sm:ml-3 sm:w-auto sm:text-sm"><?= $role === 'Category Manager' ? 'Save Rule' : 'Submit Suggestion' ?></button>
            <button type="button" id="closeModalBtn" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-gray-700 hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">Cancel</button>
          </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- RESOLVE SIMILAR SKU MODAL (PIC) -->
<div id="resolveSimilarModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
        <div class="fixed inset-0 transition-opacity" aria-hidden="true">
            <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
        </div>
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
            <form method="POST">
                <input type="hidden" name="resolve_similar_sku" value="1">
                <input type="hidden" name="suggestion_id" id="rs_suggestion_id">
                
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Review Similar SKUs</h3>
                    <p class="text-sm text-gray-500 mb-4">AI found existing SKUs with >90% similarity. Please review and decide.</p>
                    
                    <div id="rs_matches_container" class="space-y-4 mb-4 max-h-64 overflow-y-auto pr-2">
                        <!-- Filled by JS -->
                    </div>
                    
                    <div class="mt-4 border-t pt-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">My Decision:</label>
                        <select name="similar_decision" id="rs_decision" class="w-full border-gray-300 rounded focus:ring-indigo-500 mb-3" required>
                            <option value="">-- Select Decision --</option>
                            <option value="use_existing">Use Existing SKU (Identical)</option>
                            <option value="different">Similar but Different</option>
                        </select>
                        
                        <div id="rs_use_existing_wrap" class="hidden">
                            <label class="block text-sm text-gray-700">Select which SKU to use:</label>
                            <select name="selected_existing_sku_id" id="rs_selected_id" class="w-full border-gray-300 rounded mb-2" required disabled>
                            </select>
                            <input type="hidden" name="selected_existing_sku_number" id="rs_selected_sku_number">
                        </div>
                        
                        <div id="rs_different_wrap" class="hidden">
                            <label class="block text-sm text-gray-700">Reason it's different:</label>
                            <select name="similar_sku_difference" id="rs_difference_reason" class="w-full border-gray-300 rounded mb-2" required disabled>
                                <option value="Not Specific">Not Specific</option>
                                <option value="Different Brand">Different Brand</option>
                                <option value="Different Specification">Different Specification</option>
                                <option value="No matches were 90% similar">None of these are actual matches</option>
                            </select>
                            <label class="block text-sm text-gray-700 mt-2">Remarks for Category Manager:</label>
                            <textarea name="pic_remarks" class="w-full border-gray-300 rounded" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button type="submit" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 sm:ml-3 sm:w-auto sm:text-sm">Submit Decision</button>
                    <button type="button" id="closeRsModal" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: MANUAL ROW PIC SELECTION -->
<div id="manualRowPicModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
        <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" aria-hidden="true" id="manualRowPicModalOverlay"></div>
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full">
            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <h3 class="text-lg leading-6 font-medium text-gray-900 mb-2">Select Assigned PIC(s)</h3>
                <p class="text-sm text-gray-500 mb-4">Search and select the PICs for this new row.</p>
                <input type="text" id="manualRowPicSearch" class="w-full border rounded p-2 text-sm mb-3" placeholder="Search PIC name...">
                <div class="max-h-60 overflow-y-auto p-2 border rounded" id="manualRowPicList">
                    <?php foreach ($all_pics as $pic): ?>
                        <label class="flex items-center p-2 hover:bg-indigo-50 rounded cursor-pointer pic-item">
                            <input type="checkbox" value="<?= htmlspecialchars($pic) ?>" class="manual-row-pic-checkbox h-4 w-4 text-indigo-600 rounded border-gray-300">
                            <span class="ml-3 text-sm text-gray-700 pic-name"><?= htmlspecialchars($pic) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                <button type="button" id="saveManualRowPicBtn" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 sm:ml-3 sm:w-auto sm:text-sm">Done</button>
                <button type="button" id="closeManualRowPicModalBtn" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: EDIT ROW -->
<div id="editRowModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
        <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" aria-hidden="true" id="editRowModalOverlay"></div>
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-xl sm:w-full">
            <form method="POST" action="master_data.php?tab=taxonomy">
                <input type="hidden" name="edit_single_row" value="1">
                <input type="hidden" name="edit_row_id" id="edit_row_id">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Edit Master Data Row</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div><label class="block text-xs font-bold text-gray-700 mb-1">Level 0</label><input type="text" name="level_0" id="edit_l0" class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700 mb-1">Level 1</label><input type="text" name="level_1" id="edit_l1" class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700 mb-1">Level 2</label><input type="text" name="level_2" id="edit_l2" class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700 mb-1">Level 3</label><input type="text" name="level_3" id="edit_l3" class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700 mb-1">Level 4 <span class="text-red-500">*</span></label><input type="text" name="level_4" id="edit_l4" required class="w-full border rounded p-2 text-sm"></div>
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">SKU (L5)</label>
                            <input type="text" name="level_5" id="edit_l5" class="w-full border rounded p-2 text-sm">
                            <p class="mt-1 text-[10px] text-gray-500">Golden Rule 3 &mdash; Hybrid SKU: <span class="font-semibold">L4 core - critical attributes</span>. Saved in that form automatically.</p>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-xs font-bold text-gray-700 mb-2">Assigned PIC(s)</label>
                        <div class="max-h-40 overflow-y-auto p-2 border rounded">
                            <?php foreach ($all_pics as $pic): ?>
                                <label class="flex items-center p-2 hover:bg-indigo-50 rounded cursor-pointer">
                                    <input type="checkbox" name="pic_names_arr[]" value="<?= htmlspecialchars($pic) ?>" class="edit-row-pic-checkbox h-4 w-4 text-indigo-600 rounded">
                                    <span class="ml-3 text-sm text-gray-700"><?= htmlspecialchars($pic) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button type="submit" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 sm:ml-3 sm:w-auto sm:text-sm">Save Changes</button>
                    <button type="submit" name="action" value="reject_suggestion" id="editRowRejectBtn" class="mt-3 w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-medium text-white hover:bg-red-700 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm hidden">Reject</button>
                    <button type="button" id="closeEditRowModalBtn" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    $(document).ready(function() {
        // Edit Row Modal Logic
        $('.edit-row-btn').on('click', function() {
            var btn = $(this);
            $('#edit_row_id').val(btn.data('id'));
            $('#edit_l0').val(btn.data('l0'));
            $('#edit_l1').val(btn.data('l1'));
            $('#edit_l2').val(btn.data('l2'));
            $('#edit_l3').val(btn.data('l3'));
            $('#edit_l4').val(btn.data('l4'));
            $('#edit_l5').val(btn.data('l5'));
            
            // An unassigned row is pre-ticked with the AI's suggested PIC, so
            // approving it is a save rather than a re-pick. An already-assigned
            // row keeps what it has — a suggestion never silently overwrites it.
            var picsStr = btn.data('pics') || btn.data('suggested') || '';
            var pics = picsStr ? String(picsStr).split(',').map(function(item) { return item.trim(); }) : [];
            $('.edit-row-pic-checkbox').each(function() {
                $(this).prop('checked', pics.indexOf($(this).val()) !== -1);
            });

            var status = btn.data('status');
            var suggested = btn.data('suggested');
            if (status === 'ai_suggested' || ((status === 'active' || status === 'registered') && suggested)) {
                $('#editRowRejectBtn').removeClass('hidden');
            } else {
                $('#editRowRejectBtn').addClass('hidden');
            }

            $('#editRowModal').removeClass('hidden');
        });

        $('#closeEditRowModalBtn, #editRowModalOverlay').on('click', function() {
            $('#editRowModal').addClass('hidden');
        });
        // Manual Row PIC Modal Logic
        $('#openManualRowPicModalBtn').on('click', function() {
            $('#manualRowPicModal').removeClass('hidden');
            $('#manualRowPicSearch').val('').trigger('keyup');
        });

        $('#closeManualRowPicModalBtn, #manualRowPicModalOverlay').on('click', function() {
            $('#manualRowPicModal').addClass('hidden');
        });

        $('#manualRowPicSearch').on('keyup', function() {
            var val = $(this).val().toLowerCase();
            $('#manualRowPicList .pic-item').each(function() {
                var name = $(this).find('.pic-name').text().toLowerCase();
                $(this).toggle(name.indexOf(val) > -1);
            });
        });

        $('#saveManualRowPicBtn').on('click', function() {
            var selected = [];
            $('.manual-row-pic-checkbox:checked').each(function() {
                selected.push($(this).val());
            });
            $('#manual_row_pics_input').val(selected.join(', '));
            $('#manual_row_pics_display').text(selected.length > 0 ? selected.join(', ') : 'None Selected');
            $('#manualRowPicModal').addClass('hidden');
        });

        var table = $('#masterTable').DataTable({
            "lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
            "pageLength": 50,
            "language": { "search": "Global Search:" },
            "orderCellsTop": true,
            "scrollX": true,
            "scrollY": "700px",
            "scrollCollapse": true,
            "dom": '<"hidden"B>lfrtip',
            "buttons": [
                {
                    extend: 'excelHtml5',
                    className: 'hidden buttons-excel-master',
                    exportOptions: { 
                        columns: [0, 1, 2, 3, 4, 5, 6, 7],
                        format: {
                            header: function (data, columnIdx) {
                                var headers = ['ID', 'L0', 'L1', 'L2', 'L3', 'L4', 'L5', 'Assigned PIC'];
                                return headers[columnIdx] || data;
                            }
                        }
                    }
                },
                {
                    extend: 'csvHtml5',
                    className: 'hidden buttons-csv-master',
                    exportOptions: { 
                        columns: [0, 1, 2, 3, 4, 5, 6, 7],
                        format: {
                            header: function (data, columnIdx) {
                                var headers = ['ID', 'L0', 'L1', 'L2', 'L3', 'L4', 'L5', 'Assigned PIC'];
                                return headers[columnIdx] || data;
                            }
                        }
                    }
                },
                {
                    extend: 'pdfHtml5',
                    className: 'hidden buttons-pdf-master',
                    orientation: 'landscape',
                    pageSize: 'LEGAL',
                    exportOptions: { 
                        columns: [0, 1, 2, 3, 4, 5, 6, 7],
                        format: {
                            header: function (data, columnIdx) {
                                var headers = ['ID', 'L0', 'L1', 'L2', 'L3', 'L4', 'L5', 'Assigned PIC'];
                                return headers[columnIdx] || data;
                            }
                        }
                    }
                }
            ]
        });

        var sugTable = $('#suggestionsTable').DataTable({
            "lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
            "pageLength": 50,
            "language": { "search": "Global Search:" },
            "orderCellsTop": true,
            "order": [],
            "scrollX": true,
            "scrollY": "700px",
            "scrollCollapse": true,
            "dom": '<"hidden"B>frtip',
            "buttons": [
                {
                    extend: 'excelHtml5',
                    className: 'hidden buttons-excel',
                    exportOptions: { columns: [0, 1, 2, 10, 11, 12, 13, 14, 15, 4, 6, 16, 7, 8] }
                },
                {
                    extend: 'csvHtml5',
                    className: 'hidden buttons-csv',
                    exportOptions: { columns: [0, 1, 2, 10, 11, 12, 13, 14, 15, 4, 6, 16, 7, 8] }
                },
                {
                    extend: 'pdfHtml5',
                    className: 'hidden buttons-pdf',
                    orientation: 'landscape',
                    pageSize: 'LEGAL',
                    exportOptions: { columns: [0, 1, 2, 10, 11, 12, 13, 14, 15, 4, 6, 16, 7, 8] }
                }
            ]
        });

        $('.column-search-select').select2({ placeholder: "All", allowClear: true, width: '100%' });
        $('.manual-add-select').select2({ placeholder: "Select option", allowClear: true, width: '100%' });

        $('#customExportExcel').on('click', function() { sugTable.button('.buttons-excel').trigger(); });
        $('#customExportCSV').on('click', function() { sugTable.button('.buttons-csv').trigger(); });
        $('#customExportPDF').on('click', function() { sugTable.button('.buttons-pdf').trigger(); });

        $('#exportBtnMaster').on('click', function(e) {
            e.stopPropagation();
            $('#exportMenuMaster').toggleClass('hidden');
        });
        
        $(document).on('click', function(e) {
            if (!$(e.target).closest('#exportDropdownContainerMaster').length) {
                $('#exportMenuMaster').addClass('hidden');
            }
        });

        function triggerMasterExport(type) {
            if ($('#exportAllPagesMaster').is(':checked')) {
                var oldLen = table.page.len();
                table.one('draw', function() {
                    table.button('.buttons-' + type + '-master').trigger();
                    table.page.len(oldLen).draw();
                });
                table.page.len(-1).draw();
            } else {
                table.button('.buttons-' + type + '-master').trigger();
            }
            $('#exportMenuMaster').addClass('hidden');
        }

        $('#masterExportExcel').on('click', function() { triggerMasterExport('excel'); });
        $('#masterExportCSV').on('click', function() { triggerMasterExport('csv'); });
        $('#masterExportPDF').on('click', function() { triggerMasterExport('pdf'); });

        $('.column-search-text').on('keyup change clear', function(e) {
            e.stopPropagation();
            table.column($(this).data('col')).search(this.value).draw();
        });
        
        $('.column-search-select').on('change', function(e) {
            e.stopPropagation();
            var val = $.fn.dataTable.util.escapeRegex($(this).val());
            table.column($(this).data('col')).search(val ? '^' + val + '$' : '', true, false).draw();
        });

        $('.sug-search-text').on('keyup change clear', function(e) {
            e.stopPropagation();
            sugTable.column($(this).data('col')).search(this.value).draw();
        });

        // Quick Filters for Suggestions Status
        $('.sug-status-btn').on('click', function() {
            var status = $(this).data('status');
            $('.sug-status-btn').removeClass('ring-2 ring-offset-2 ring-indigo-400');
            $(this).addClass('ring-2 ring-offset-2 ring-indigo-400');
            
            if (status) {
                sugTable.column(1).search('^.*(' + status + ').*$', true, false).draw();
            } else {
                sugTable.column(1).search('').draw();
            }
        });

        // Column Filters for Taxonomy Table
        $('.column-search-text').on('keyup change clear', function(e) {
            e.stopPropagation();
            table.column($(this).data('col')).search(this.value).draw();
        });

        // Quick Filters
        $('.team-filter-btn').on('click', function() {
            var team = $(this).data('team');
            $('.team-filter-btn').removeClass('ring-2 ring-offset-2 ring-indigo-400 bg-indigo-50 text-indigo-700').addClass('bg-white text-gray-600 border-gray-200');
            $(this).removeClass('bg-white text-gray-600 border-gray-200').addClass('ring-2 ring-offset-2 ring-indigo-400 bg-indigo-50 text-indigo-700');
            if (team === '') table.column(1).search('', false, false).draw();
            else table.column(1).search('^' + $.fn.dataTable.util.escapeRegex(team) + '$', true, false).draw();
        });

        $('.quick-filter-btn').on('click', function() {
            var status = $(this).data('status');
            $('.quick-filter-btn').removeClass('ring-2 ring-offset-2 ring-gray-400 ring-yellow-400');
            $(this).addClass('ring-2 ring-offset-2 ' + (status === '' ? 'ring-gray-400' : 'ring-yellow-400'));
            table.column(7).search(status).draw(); // PIC column is 7
        });

        // Modals
        $('#masterTable tbody, #suggestionsTable tbody').on('click', '.open-modal-btn', function() {
            var isSuggestions = $(this).closest('table').attr('id') === 'suggestionsTable';
            $('#modal-source-tab').val(isSuggestions ? 'suggestions' : 'taxonomy');
            
            $('#modal-id-input').val($(this).data('id'));
            $('#modal-l4-input').val($(this).data('l4'));
            $('#modal-cat-display').text($(this).data('l4'));
            $('.modal-pic-checkbox').prop('checked', false);
            
            var suggestedStr = $(this).data('suggested');
            var currentStr = $(this).data('pics');
            var picsToLoad = suggestedStr && <?= $role === 'Category Manager' ? 'true' : 'false' ?> ? suggestedStr : currentStr;
            
            if (picsToLoad) {
                var picArray = String(picsToLoad).split(',').map(item => item.trim());
                $('.modal-pic-checkbox').each(function() {
                    if (picArray.includes($(this).val())) $(this).prop('checked', true);
                });
            }
            $('#updateModal').removeClass('hidden');
        });

        $('#closeModalBtn, #modalOverlay').on('click', function() { $('#updateModal').addClass('hidden'); });

        // Review Modal for CM
        $('#suggestionsTable tbody').on('click', '.review-btn', function() {
            $('#rev_id').val($(this).data('id'));
            $('#rev_desc_display').text($(this).data('desc'));
            $('#rev_l0').val($(this).data('l0'));
            $('#rev_l1').val($(this).data('l1'));
            $('#rev_l2').val($(this).data('l2'));
            $('#rev_l3').val($(this).data('l3'));
            $('#rev_l4').val($(this).data('l4'));
            $('#rev_l5').val($(this).data('l5'));
            $('#rev_sku').val($(this).data('sku') || '');
            
            var currentStatus = $(this).data('status');
            if (currentStatus === 'registered' || currentStatus === 'active') {
                $('input[name="sku_type_option"][value="existing"]').prop('checked', true);
            } else if (currentStatus === 'approved_new') {
                $('input[name="sku_type_option"][value="new"]').prop('checked', true);
            }
            
            // Load brands
            $('#brands_container').empty();
            var brandsData = $(this).data('brands');
            if (brandsData) {
                var brandArray = String(brandsData).split(',').map(item => item.trim());
                brandArray.forEach(function(b) {
                    if (b) addBrandInput(b);
                });
            } else {
                addBrandInput(''); // one empty input by default
            }

            $('.rev-pic-checkbox').prop('checked', false);
            // Same rule as the taxonomy table: nothing assigned yet means the
            // AI's suggested PIC is already ticked, ready to be confirmed.
            var pics = $(this).data('pics') || $(this).data('suggested');
            if (pics) {
                var picArray = String(pics).split(',').map(item => item.trim());
                $('.rev-pic-checkbox').each(function() {
                    if (picArray.includes($(this).val())) $(this).prop('checked', true);
                });
            }
            
            $('#ai_autofill_result').addClass('hidden').removeClass('bg-green-100 bg-yellow-100 bg-red-100 text-green-800 text-yellow-800 text-red-800');
            $('#reviewModal').removeClass('hidden');
        });
        $('#closeReviewModal, #reviewModalOverlay').on('click', function() { $('#reviewModal').addClass('hidden'); });

        // Brand dynamic logic
        function addBrandInput(val) {
            var html = '<div class="flex items-center space-x-2"><input type="text" name="available_brands[]" value="'+val+'" class="flex-1 border-gray-300 rounded focus:ring-indigo-500 text-sm placeholder-gray-400" placeholder="e.g. Samsung"><button type="button" class="text-red-500 hover:text-red-700 remove-brand-btn"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button></div>';
            $('#brands_container').append(html);
        }

        $('#btn_add_brand').on('click', function() {
            addBrandInput('');
        });

        $(document).on('click', '.remove-brand-btn', function() {
            $(this).parent().remove();
        });

        // Manager Suggest PIC Modal
        $('#suggestionsTable tbody').on('click', '.suggest-pic-btn', function() {
            $('#spm_id').val($(this).data('id'));
            $('#spm_desc').text($(this).data('desc'));
            $('.spm-pic-checkbox').prop('checked', false);
            var pics = $(this).data('suggested-pics');
            if (pics) {
                var picArray = String(pics).split(',').map(item => item.trim());
                $('.spm-pic-checkbox').each(function() {
                    if (picArray.includes($(this).val())) $(this).prop('checked', true);
                });
            }
            $('#suggestPicModal').removeClass('hidden');
        });
        $('#closeSuggestPicModal, #suggestPicModalOverlay').on('click', function() { $('#suggestPicModal').addClass('hidden'); });

        // Register SKU Modal Handlers
        $(document).on('click', '.register-sku-btn', function() {
            $('#reg_id').val($(this).data('id'));
            $('#reg_desc').text($(this).data('desc'));
            $('#reg_sku_number').val($(this).data('desc'));
            $('#registerSkuModal').removeClass('hidden');
        });
        $('#closeRegisterSkuModal, #registerSkuModalOverlay').on('click', function() {
            $('#registerSkuModal').addClass('hidden');
        });

        // AI Autofill Action
        $('#btn_ask_ai').on('click', function() {
            var itemDesc = $('#rev_desc_display').text();
            var btn = $(this);
            btn.prop('disabled', true).html('Thinking...');
            $('#ai_autofill_result').removeClass('hidden').html('Calling AI...').addClass('bg-gray-100 text-gray-800');

            $.ajax({
                url: 'ajax_ai_autofill_taxonomy.php',
                type: 'POST',
                data: { item_desc: itemDesc },
                dataType: 'json',
                success: function(res) {
                    btn.prop('disabled', false).html('<svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>Ask AI to Autofill');
                    if (res.status === 'success') {
                        var conf = parseInt(res.confidence);
                        var colorClass = conf > 80 ? 'bg-green-100 text-green-800' : (conf > 50 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800');
                        $('#ai_autofill_result').removeClass('bg-gray-100 text-gray-800').addClass(colorClass)
                            .html(`<strong>AI Autofill Complete (Confidence: ${conf}%)</strong><br>Review the populated fields below.`);
                        
                        if(res.taxonomy.level_0) $('#rev_l0').val(res.taxonomy.level_0);
                        if(res.taxonomy.level_1) $('#rev_l1').val(res.taxonomy.level_1);
                        if(res.taxonomy.level_2) $('#rev_l2').val(res.taxonomy.level_2);
                        if(res.taxonomy.level_3) $('#rev_l3').val(res.taxonomy.level_3);
                        if(res.taxonomy.level_4) $('#rev_l4').val(res.taxonomy.level_4);
                        // don't overwrite level 5 unless it suggests one explicitly?
                        if(res.taxonomy.level_5) $('#rev_l5').val(res.taxonomy.level_5);
                    } else {
                        $('#ai_autofill_result').removeClass('bg-gray-100 text-gray-800').addClass('bg-red-100 text-red-800').html('AI Autofill Failed: ' + res.message);
                    }
                },
                error: function() {
                    btn.prop('disabled', false).html('Ask AI to Autofill');
                    $('#ai_autofill_result').removeClass('bg-gray-100 text-gray-800').addClass('bg-red-100 text-red-800').html('Server connection error.');
                }
            });
        });
        // Check for AI background trigger
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('trigger_ai_check') && urlParams.has('chk_id')) {
            const chkId = urlParams.get('chk_id');
            window.history.replaceState({}, document.title, window.location.pathname + "?tab=suggestions");
            
            var toastHtml = `
            <div id="ai-toast" class="fixed bottom-4 right-4 bg-indigo-600 text-white px-4 py-3 rounded shadow-lg flex items-center z-50">
                <svg class="animate-spin h-5 w-5 mr-3 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                <span class="font-bold text-sm">Evaluating AI Golden Rules in background...</span>
            </div>`;
            $('body').append(toastHtml);

            $.ajax({
                url: 'ajax_ai_golden_rule_update.php',
                type: 'POST',
                data: { id: chkId },
                dataType: 'json',
                success: function(res) {
                    $('#ai-toast svg').remove();
                    $('#ai-toast').removeClass('bg-indigo-600').addClass(res.status === 'updated' ? 'bg-green-600' : 'bg-gray-800');
                    $('#ai-toast').prepend('<svg class="h-5 w-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>');
                    $('#ai-toast span').text(res.status === 'updated' ? 'Golden Rules Updated!' : 'No Golden Rule Updates Needed');
                    setTimeout(() => $('#ai-toast').fadeOut(), 5000);
                },
                error: function() {
                    $('#ai-toast svg').remove();
                    $('#ai-toast').removeClass('bg-indigo-600').addClass('bg-red-600');
                    $('#ai-toast span').text('Failed to evaluate Golden Rules.');
                    setTimeout(() => $('#ai-toast').fadeOut(), 5000);
                }
            });
        }

        // PIC Resolve Similar SKU Modal Logic
        $('#masterTable tbody, #suggestionsTable tbody').on('click', '.review-similar-btn', function() {
            var id = $(this).data('id');
            var matches = $(this).data('similar');
            
            $('#rs_suggestion_id').val(id);
            
            var container = $('#rs_matches_container');
            var select = $('#rs_selected_id');
            container.empty();
            select.empty();
            
            if (matches && matches.length > 0) {
                matches.forEach(function(m) {
                    var itemHtml = '<div class="bg-gray-50 p-3 rounded border"><div class="font-bold text-indigo-700">' + m.sku + ' <span class="text-green-600 text-xs">(Conf: ' + m.confidence + '%)</span></div><div class="text-xs text-gray-600">' + m.detail + '</div><div class="text-xs mt-1 italic">Reasoning: ' + m.reasoning + '</div></div>';
                    container.append(itemHtml);
                    
                    select.append('<option value="' + m.id + '" data-sku="' + m.sku + '">' + m.sku + '</option>');
                });
                $('#rs_selected_sku_number').val(matches[0].sku);
            }
            
            $('#rs_decision').val('');
            $('#rs_use_existing_wrap, #rs_different_wrap').addClass('hidden');
            $('#rs_selected_id, #rs_difference_reason').prop('disabled', true);
            
            $('#resolveSimilarModal').removeClass('hidden');
        });

        $('#rs_decision').on('change', function() {
            if ($(this).val() === 'use_existing') {
                $('#rs_use_existing_wrap').removeClass('hidden');
                $('#rs_selected_id').prop('disabled', false);
                $('#rs_different_wrap').addClass('hidden');
                $('#rs_difference_reason').prop('disabled', true);
            } else if ($(this).val() === 'different') {
                $('#rs_use_existing_wrap').addClass('hidden');
                $('#rs_selected_id').prop('disabled', true);
                $('#rs_different_wrap').removeClass('hidden');
                $('#rs_difference_reason').prop('disabled', false);
            } else {
                $('#rs_use_existing_wrap, #rs_different_wrap').addClass('hidden');
                $('#rs_selected_id, #rs_difference_reason').prop('disabled', true);
            }
        });

        $('#rs_selected_id').on('change', function() {
            $('#rs_selected_sku_number').val($(this).find('option:selected').data('sku'));
        });

        $('#closeRsModal').on('click', function() {
            $('#resolveSimilarModal').addClass('hidden');
        });

        // Check for AI background trigger for finding similar SKUs
        if (urlParams.has('trigger_find_similar') && urlParams.has('chk_id')) {
            const chkId = urlParams.get('chk_id');
            window.history.replaceState({}, document.title, window.location.pathname + "?tab=suggestions");
            
            var toastHtml = `
            <div id="ai-similar-toast" class="fixed bottom-4 right-4 bg-blue-600 text-white px-4 py-3 rounded shadow-lg flex items-center z-50">
                <svg class="animate-spin h-5 w-5 mr-3 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                <span class="font-bold text-sm">AI is checking for similar existing SKUs...</span>
            </div>`;
            $('body').append(toastHtml);

            $.ajax({
                url: 'ajax_ai_find_similar_sku.php',
                type: 'POST',
                data: { id: chkId },
                dataType: 'json',
                success: function(res) {
                    $('#ai-similar-toast svg').remove();
                    $('#ai-similar-toast').removeClass('bg-blue-600').addClass(res.status === 'success' && res.matches > 0 ? 'bg-yellow-500' : 'bg-gray-800');
                    $('#ai-similar-toast').prepend('<svg class="h-5 w-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>');
                    if (res.status === 'success' && res.matches > 0) {
                        $('#ai-similar-toast').fadeOut();
                        
                        // Automatically open the modal for the PIC!
                        $('#rs_suggestion_id').val(chkId);
                        var container = $('#rs_matches_container');
                        var select = $('#rs_selected_id');
                        container.empty();
                        select.empty();
                        
                        res.data.forEach(function(m) {
                            var itemHtml = '<div class="bg-gray-50 p-3 rounded border"><div class="font-bold text-indigo-700">' + m.sku + ' <span class="text-green-600 text-xs">(Conf: ' + m.confidence + '%)</span></div><div class="text-xs text-gray-600">' + m.detail + '</div><div class="text-xs mt-1 italic">Reasoning: ' + m.reasoning + '</div></div>';
                            container.append(itemHtml);
                            select.append('<option value="' + m.id + '" data-sku="' + m.sku + '">' + m.sku + '</option>');
                        });
                        $('#rs_selected_sku_number').val(res.data[0].sku);
                        
                        $('#rs_decision').val('');
                        $('#rs_use_existing_wrap, #rs_different_wrap').addClass('hidden');
                        $('#rs_selected_id, #rs_difference_reason').prop('disabled', true);
                        
                        $('#resolveSimilarModal').removeClass('hidden');
                    } else {
                        $('#ai-similar-toast span').text('No similar SKUs. Auto-generating taxonomy...');
                        $.ajax({
                            url: 'ajax_auto_generate_sku.php',
                            type: 'POST',
                            data: { id: chkId },
                            dataType: 'json',
                            success: function(genRes) {
                                if(genRes.status === 'success') {
                                    $('#ai-similar-toast').removeClass('bg-gray-800').addClass('bg-green-600');
                                    $('#ai-similar-toast span').text('Taxonomy generated successfully!');
                                } else {
                                    $('#ai-similar-toast').removeClass('bg-gray-800').addClass('bg-red-600');
                                    $('#ai-similar-toast span').text('Failed to auto-generate taxonomy.');
                                }
                                setTimeout(() => { $('#ai-similar-toast').fadeOut(); location.reload(); }, 2000);
                            },
                            error: function() {
                                setTimeout(() => { $('#ai-similar-toast').fadeOut(); location.reload(); }, 2000);
                            }
                        });
                    }
                },
                error: function() {
                    $('#ai-similar-toast svg').remove();
                    $('#ai-similar-toast').removeClass('bg-blue-600').addClass('bg-red-600');
                    $('#ai-similar-toast span').text('Failed to run AI similarity check.');
                    setTimeout(() => $('#ai-similar-toast').fadeOut(), 5000);
                }
            });
        }

        // Check for auto-generate trigger
        if (urlParams.has('trigger_auto_generate') && urlParams.has('chk_id')) {
            const chkId = urlParams.get('chk_id');
            window.history.replaceState({}, document.title, window.location.pathname + "?tab=suggestions");
            
            var toastHtml = `
            <div id="ai-generate-toast" class="fixed bottom-4 right-4 bg-blue-600 text-white px-4 py-3 rounded shadow-lg flex items-center z-50">
                <svg class="animate-spin h-5 w-5 mr-3 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                <span class="font-bold text-sm">Auto-generating taxonomy for new SKU...</span>
            </div>`;
            $('body').append(toastHtml);

            $.ajax({
                url: 'ajax_auto_generate_sku.php',
                type: 'POST',
                data: { id: chkId },
                dataType: 'json',
                success: function(res) {
                    $('#ai-generate-toast svg').remove();
                    if(res.status === 'success') {
                        $('#ai-generate-toast').removeClass('bg-blue-600').addClass('bg-green-600');
                        $('#ai-generate-toast span').text('Taxonomy generated successfully!');
                    } else {
                        $('#ai-generate-toast').removeClass('bg-blue-600').addClass('bg-red-600');
                        $('#ai-generate-toast span').text('Failed to auto-generate taxonomy.');
                    }
                    setTimeout(() => { $('#ai-generate-toast').fadeOut(); location.reload(); }, 2000);
                },
                error: function() {
                    $('#ai-generate-toast svg').remove();
                    $('#ai-generate-toast').removeClass('bg-blue-600').addClass('bg-red-600');
                    $('#ai-generate-toast span').text('Server error during taxonomy generation.');
                    setTimeout(() => { $('#ai-generate-toast').fadeOut(); location.reload(); }, 2000);
                }
            });
        }
        
        // SKU Suggestion AI Interception
        $('#skuSuggestionForm').on('submit', function(e) {
            if ($('#user_remarks_similarity').val() !== '') {
                return true; // Already processed and bypassed
            }
            
            e.preventDefault();
            var itemName = $('#sug_item_name').val().trim();
            var itemType = $('#sug_item_type').val();
            
            // Auto-prepend Jasa if Service is selected but missing
            if (itemType === 'Service' && !/^Jasa\b/i.test(itemName)) {
                itemName = 'Jasa ' + itemName;
                $('#sug_item_name').val(itemName);
            }
            
            Swal.fire({
                title: 'Checking Similarity & AI Validation',
                text: 'AI is checking if a similar SKU exists and validating your input...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });
            
            $.post('ajax_check_similar_sku.php', { item_name: itemName, item_type: itemType }, function(res) {
                if (res.suspicious_jasa) {
                    Swal.fire({
                        title: 'Are you sure this is a Goods (Barang)?',
                        html: '<p class="text-sm text-gray-600 mb-4">Based on the item description, the AI thinks this should be classified as a <strong>Service (Jasa)</strong>.</p><div class="bg-indigo-50 border border-indigo-200 text-indigo-800 p-3 rounded text-sm text-left italic"><strong>AI Reasoning:</strong> ' + res.suspicious_reason + '</div>',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, change to Service (Jasa)',
                        cancelButtonText: 'No, keep as Goods (Barang)',
                        confirmButtonColor: '#4f46e5',
                        cancelButtonColor: '#6b7280'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            $('#sug_item_type').val('Service');
                            if (!/^Jasa\b/i.test(itemName)) {
                                $('#sug_item_name').val('Jasa ' + itemName);
                            }
                            $('#skuSuggestionForm').submit();
                        } else {
                            // User insisted it's Goods, proceed to similarity check manually by bypassing the AI check
                            $('#user_remarks_similarity').val('User verified as Goods.');
                            $('#skuSuggestionForm').submit();
                        }
                    });
                    return;
                }

                if (res.has_similar && res.similar_items && res.similar_items.length > 0) {
                    var html = '<div class="text-left text-sm mt-3 mb-5 max-h-48 overflow-y-auto bg-gray-50 p-3 border border-gray-300 rounded shadow-inner">';
                    res.similar_items.forEach(function(item) {
                        html += '<div class="mb-3 pb-3 border-b border-gray-200 last:border-0 last:mb-0 last:pb-0"><strong>' + item.sku + '</strong> (' + item.similarity_score + '% similar)<br><span class="text-xs text-gray-600 leading-snug block mt-1">' + item.detail + '</span><span class="text-xs italic text-indigo-600 block mt-1">' + (item.reason || '') + '</span></div>';
                    });
                    html += '</div>';
                    
                    html += '<div class="text-left mt-4"><label class="block text-sm font-bold text-gray-800 mb-2" for="swal-custom-remarks">If you still want to suggest this as a new SKU, you MUST explain why (Remarks):</label><textarea id="swal-custom-remarks" class="w-full border border-gray-300 rounded p-3 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 shadow-sm" rows="4" placeholder="Type your reasoning here..."></textarea></div>';
                    
                    Swal.fire({
                        title: 'Similar Items Found!',
                        html: '<p class="text-sm text-gray-600">We found items in the SKU Dictionary that are very similar to <strong>' + itemName + '</strong>.</p>' + html,
                        icon: 'warning',
                        width: '600px',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, Suggest Anyway',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#4f46e5',
                        customClass: {
                            popup: 'rounded-xl'
                        },
                        preConfirm: () => {
                            const val = document.getElementById('swal-custom-remarks').value.trim();
                            if (!val) {
                                Swal.showValidationMessage('You need to write remarks if you want to proceed!');
                                return false;
                            }
                            return val;
                        }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            $('#user_remarks_similarity').val(result.value);
                            // Programmatically submit the form (bypassing jQuery submit handler)
                            HTMLFormElement.prototype.submit.call($('#skuSuggestionForm')[0]);
                        }
                    });
                } else {
                    // No similar items or error, submit normally
                    HTMLFormElement.prototype.submit.call($('#skuSuggestionForm')[0]);
                }
            }, 'json').fail(function() {
                // If endpoint fails, just submit
                HTMLFormElement.prototype.submit.call($('#skuSuggestionForm')[0]);
            });
        });

        // --- IMPORT MASTER DATA (CSV) ---
        (function() {
            var importRows = [];       // full classify() result from the last validate call: one entry per CSV row
            var importActions = {};    // row_number -> 'update' | 'add' | 'skip' (the user's checkbox choice)
            var importFilter = 'all';
            var IMPORT_RENDER_CAP = 300; // the row list only ever draws this many DOM rows per filter; selection still covers everything

            var STATUS_BADGE = {
                UNCHANGED: 'bg-gray-100 text-gray-600',
                UPDATED:   'bg-yellow-100 text-yellow-800',
                NEW:       'bg-blue-100 text-blue-800',
                INVALID:   'bg-red-100 text-red-800'
            };

            var $escDiv = $('<div>');
            function esc(v) { return $escDiv.text(v === null || v === undefined ? '' : v).html(); }

            function isSelectable(entry) {
                return entry.status === 'UPDATED' || entry.status === 'NEW';
            }

            var MD_FIELDS = ['level_0', 'level_1', 'level_2', 'level_3', 'level_4', 'level_5', 'pic_names'];
            var MD_FIELD_LABELS = {
                level_0: 'Level 0', level_1: 'Level 1', level_2: 'Level 2', level_3: 'Level 3',
                level_4: 'Level 4', level_5: 'Level 5', pic_names: 'PIC Names'
            };

            function fieldCellHtml(entry, field) {
                if (entry.status === 'UPDATED') {
                    var change = (entry.changed_fields || []).filter(function(c) { return c.field === field; })[0];
                    if (change) {
                        return '<div class="text-red-600 line-through">' + esc(change.old || '(blank)') + '</div>' +
                            '<div class="text-green-700 font-medium">' + esc(change.new || '(blank)') + '</div>';
                    }
                    return '<span class="text-gray-500">' + esc((entry.current_values || {})[field] || '') + '</span>';
                }
                if (entry.status === 'NEW') {
                    return '<span class="text-gray-800">' + esc((entry.new_values || {})[field] || '') + '</span>';
                }
                return '<span class="text-gray-400">' + esc((entry.current_values || {})[field] || '') + '</span>';
            }

            function rowTrHtml(entry) {
                var checkbox = '';
                if (isSelectable(entry)) {
                    var checked = importActions[entry.row_number] !== 'skip' ? 'checked' : '';
                    var intent = entry.status === 'UPDATED' ? 'update' : 'add';
                    checkbox = '<input type="checkbox" class="import-row-checkbox h-4 w-4 text-indigo-600 rounded" ' +
                        'data-row-number="' + entry.row_number + '" data-intent="' + intent + '" ' + checked + '>';
                }

                var badge = '<span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase ' +
                    (STATUS_BADGE[entry.status] || 'bg-gray-100 text-gray-600') + '">' + esc(entry.status) + '</span>';

                var idLabel = esc(entry.csv_id) + (entry.status === 'NEW'
                    ? '<span class="block text-[10px] text-gray-400 font-normal whitespace-nowrap">not in database</span>'
                    : '');

                var tr = '<tr class="' + (entry.status === 'UNCHANGED' ? 'bg-gray-50' : '') + '">' +
                    '<td class="px-2 py-2 align-top">' + checkbox + '</td>' +
                    '<td class="px-2 py-2 align-top text-sm font-semibold text-gray-800 whitespace-nowrap">' + idLabel + '</td>' +
                    '<td class="px-2 py-2 align-top">' + badge + '</td>' +
                    '<td class="px-2 py-2 align-top text-[10px] text-gray-400">' + entry.row_number + '</td>';

                if (entry.status === 'INVALID') {
                    var msgs = (entry.errors || []).map(function(e) { return esc(e.error_message); }).join('<br>');
                    tr += '<td class="px-2 py-2 align-top text-xs text-red-700" colspan="' + MD_FIELDS.length + '">' + msgs + '</td>';
                } else {
                    MD_FIELDS.forEach(function(f) {
                        tr += '<td class="px-2 py-2 align-top text-xs whitespace-nowrap">' + fieldCellHtml(entry, f) + '</td>';
                    });
                }
                tr += '</tr>';
                return tr;
            }

            function filteredRows() {
                if (importFilter === 'all') { return importRows; }
                return importRows.filter(function(e) { return e.status === importFilter; });
            }

            function renderRowsList() {
                var rows = filteredRows();
                var $list = $('#import_rows_list').empty();
                if (!rows.length) {
                    $list.append('<div class="p-4 text-sm text-gray-400 text-center">No rows in this view.</div>');
                    $('#import_rows_more').addClass('hidden');
                    return;
                }

                var thead = '<thead class="bg-gray-50 sticky top-0 z-10"><tr>' +
                    '<th class="px-2 py-2"></th>' +
                    '<th class="px-2 py-2 text-left text-[10px] font-bold text-gray-500 uppercase whitespace-nowrap">ID</th>' +
                    '<th class="px-2 py-2 text-left text-[10px] font-bold text-gray-500 uppercase whitespace-nowrap">Status</th>' +
                    '<th class="px-2 py-2 text-left text-[10px] font-bold text-gray-500 uppercase whitespace-nowrap">Row</th>' +
                    MD_FIELDS.map(function(f) {
                        return '<th class="px-2 py-2 text-left text-[10px] font-bold text-gray-500 uppercase whitespace-nowrap">' + MD_FIELD_LABELS[f] + '</th>';
                    }).join('') +
                    '</tr></thead>';

                var tbody = '<tbody class="divide-y divide-gray-100">';
                rows.slice(0, IMPORT_RENDER_CAP).forEach(function(entry) { tbody += rowTrHtml(entry); });
                tbody += '</tbody>';

                $list.html('<table class="min-w-full text-xs">' + thead + tbody + '</table>');

                if (rows.length > IMPORT_RENDER_CAP) {
                    $('#import_rows_more').removeClass('hidden')
                        .text('Showing first ' + IMPORT_RENDER_CAP + ' of ' + rows.length + ' rows in this view. "Select all in view" still applies to every row, not only the ones shown.');
                } else {
                    $('#import_rows_more').addClass('hidden');
                }
            }

            function updateFilterButtons() {
                $('.import-filter-btn').each(function() {
                    var f = $(this).data('import-filter');
                    var active = (String(f) === importFilter);
                    $(this).toggleClass('bg-indigo-600 text-white border-indigo-600', active)
                        .toggleClass('bg-white text-gray-600 border-gray-300 hover:bg-gray-50', !active);
                });
            }

            function confirmEnabledCount() {
                var n = 0;
                importRows.forEach(function(e) {
                    if (isSelectable(e) && importActions[e.row_number] && importActions[e.row_number] !== 'skip') { n++; }
                });
                return n;
            }

            function refreshConfirmButton() {
                $('#import_confirm').prop('disabled', confirmEnabledCount() === 0);
            }

            function resetImportUi() {
                importRows = [];
                importActions = {};
                importFilter = 'all';
                $('#import_preview_panel, #import_result_panel, #import_upload_error').addClass('hidden');
                $('#import_csv_file').val('');
            }

            $('#import_download_template').on('click', function() {
                var delimiter = document.getElementById('import_csv_delimiter').value;
                delimiter = delimiter === '\\t' ? '\t' : delimiter;
                var headers = ['id', 'level_0', 'level_1', 'level_2', 'level_3', 'level_4', 'level_5', 'pic_names'];
                var exampleRow = ['', 'Technology, Industrial, and General Services', 'IT Equipment', 'Computer', 'Laptop', 'MACBOOK PRO', 'LAPTOP - M3 MAX 64GB', 'John Doe'];
                function toCsvField(v) {
                    v = String(v);
                    if (v.indexOf(delimiter) !== -1 || v.indexOf('"') !== -1 || v.indexOf('\n') !== -1) {
                        return '"' + v.replace(/"/g, '""') + '"';
                    }
                    return v;
                }
                var csvContent = headers.map(toCsvField).join(delimiter) + '\r\n'
                    + exampleRow.map(toCsvField).join(delimiter) + '\r\n';
                var blob = new Blob(['﻿' + csvContent], { type: 'text/csv;charset=utf-8;' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'master_data_import_template.csv';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            });

            $('#import_upload_validate').on('click', function() {
                var fileInput = document.getElementById('import_csv_file');
                if (!fileInput.files || !fileInput.files.length) {
                    $('#import_upload_error').removeClass('hidden').text('Please choose a CSV file first.');
                    return;
                }
                var fd = new FormData();
                fd.append('action', 'validate');
                fd.append('csv_file', fileInput.files[0]);
                fd.append('delimiter', document.getElementById('import_csv_delimiter').value);

                var $btn = $(this).prop('disabled', true).text('Validating...');
                $('#import_upload_error, #import_result_panel').addClass('hidden');

                $.ajax({
                    url: 'ajax_master_data_import.php',
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json'
                }).done(function(res) {
                    if (res.status !== 'ok') {
                        $('#import_upload_error').removeClass('hidden').text(res.message || 'Validation failed.');
                        $('#import_preview_panel').addClass('hidden');
                        return;
                    }
                    importRows = res.rows || [];
                    importActions = {};
                    importRows.forEach(function(e) { importActions[e.row_number] = e.default_action; });
                    importFilter = 'all';

                    $('#import_sum_total').text(res.summary.total_rows);
                    $('#import_sum_unchanged').text(res.summary.unchanged);
                    $('#import_sum_updated').text(res.summary.updated);
                    $('#import_sum_new').text(res.summary.new_rows);
                    $('#import_sum_invalid').text(res.summary.invalid);

                    updateFilterButtons();
                    renderRowsList();
                    refreshConfirmButton();
                    $('#import_preview_panel').removeClass('hidden');
                }).fail(function() {
                    $('#import_upload_error').removeClass('hidden').text('Server error while validating the file.');
                }).always(function() {
                    $btn.prop('disabled', false).text('Upload & Validate');
                });
            });

            $(document).on('click', '.import-filter-btn', function() {
                importFilter = $(this).data('import-filter');
                updateFilterButtons();
                renderRowsList();
            });

            $(document).on('change', '.import-row-checkbox', function() {
                var rowNumber = $(this).data('row-number');
                var intent = $(this).data('intent');
                importActions[rowNumber] = this.checked ? intent : 'skip';
                refreshConfirmButton();
            });

            $('#import_select_all, #import_select_none').on('click', function() {
                var select = ($(this).attr('id') === 'import_select_all');
                filteredRows().forEach(function(e) {
                    if (!isSelectable(e)) { return; }
                    importActions[e.row_number] = select ? (e.status === 'UPDATED' ? 'update' : 'add') : 'skip';
                });
                renderRowsList();
                refreshConfirmButton();
            });

            $('#import_cancel').on('click', resetImportUi);

            $('#import_confirm').on('click', function() {
                var updateCount = 0, addCount = 0;
                importRows.forEach(function(e) {
                    var a = importActions[e.row_number];
                    if (a === 'update') { updateCount++; }
                    if (a === 'add') { addCount++; }
                });
                if (updateCount + addCount === 0) { return; }
                var $btn = $(this);

                Swal.fire({
                    title: 'Confirm import changes?',
                    html: 'You are about to apply:<br>' +
                        '<strong>' + updateCount + '</strong> record(s) will be updated<br>' +
                        '<strong>' + addCount + '</strong> record(s) will be added<br>' +
                        'Every other row is left unchanged. This cannot be undone from this screen.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Confirm & Apply Changes',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#16a34a',
                    cancelButtonColor: '#6b7280'
                }).then(function(result) {
                    if (!result.isConfirmed) { return; }

                    var payload = importRows.map(function(e) {
                        return {
                            row_number: e.row_number,
                            id: e.csv_id,
                            level_0: (e.new_values || e.current_values || {}).level_0 || '',
                            level_1: (e.new_values || e.current_values || {}).level_1 || '',
                            level_2: (e.new_values || e.current_values || {}).level_2 || '',
                            level_3: (e.new_values || e.current_values || {}).level_3 || '',
                            level_4: (e.new_values || e.current_values || {}).level_4 || '',
                            level_5: (e.new_values || e.current_values || {}).level_5 || '',
                            pic_names: (e.new_values || e.current_values || {}).pic_names || '',
                            action: importActions[e.row_number] || 'skip'
                        };
                    });

                    $btn.prop('disabled', true).text('Applying...');
                    $.ajax({
                        url: 'ajax_master_data_import.php',
                        type: 'POST',
                        data: { action: 'confirm', rows: JSON.stringify(payload) },
                        dataType: 'json'
                    }).done(function(res) {
                        if (res.status !== 'ok') {
                            $('#import_upload_error').removeClass('hidden').text(res.message || 'Update failed.');
                            return;
                        }
                        $('#import_res_updated').text(res.summary.updated);
                        $('#import_res_added').text(res.summary.added);
                        $('#import_res_skipped').text(res.summary.skipped);
                        $('#import_res_failed').text(res.summary.failed);
                        $('#import_res_message').text(res.message ||
                            (res.summary.updated + ' updated, ' + res.summary.added + ' added, ' +
                             res.summary.skipped + ' skipped, ' + res.summary.failed + ' failed.'));
                        $('#import_preview_panel').addClass('hidden');
                        $('#import_result_panel').removeClass('hidden');
                        $('#import_csv_file').val('');
                        importRows = [];
                        importActions = {};
                    }).fail(function() {
                        $('#import_upload_error').removeClass('hidden').text('Server error while applying the update.');
                    }).always(function() {
                        $btn.prop('disabled', false).text('Confirm & Apply Changes');
                    });
                });
            });

        })();

    });
</script>

<?php require_once '../includes/footer.php'; ?>
