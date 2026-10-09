<?php
// modules/sourcing/manage_questions_new.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';

// 1. Security Check (Roles: Sourcing + Vendor Management)
$allowed_roles = ['sourcing', 'vendor_mgt', 'vendormgt_head'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles, true)) {
    die("Access Denied");
}

// Where the "Back to Dashboard" button should point, per role. VM users live under
// modules/vendormgt/, sourcing users under modules/sourcing/ (this file's folder).
$is_vm_user = in_array($_SESSION['role'], ['vendor_mgt', 'vendormgt_head'], true);
$dashboard_url = $is_vm_user ? '../vendormgt/dashboard.php' : 'dashboard.php';

$page_title = "Manage PQ Questions";
include $path_to_root . 'includes/header.php';

// PQ_SCORING_OPEN_STATUSES + the per-question submission counts. An edit here
// only re-scores the PQs still with Sourcing, so the edit modal shows how many
// that is (and how many are frozen) before anyone agrees to the change.
require_once $path_to_root . 'includes/pq_question_change_log.php';
$submission_counts = pqSubmissionCountsByQuestion($conn);

// 2. Fetch Sections for Dropdowns
$sections = [];
$sec_res = $conn->query("SELECT * FROM pq_sections ORDER BY sort_order ASC");
if ($sec_res) {
    while($s = $sec_res->fetch_assoc()) {
        $sections[] = $s;
    }
}

// 2b. Weight budget per sub-category group.
//
// Each business-model/sub-category is a 100-point budget, enforced on save by
// pqGroupWeightSum() in question_action.php. Every group currently sits at
// exactly 100, which means any increase is refused -- and until now the only
// way to discover that was to fill in the form and have the save bounce. The
// numbers are shown up front instead, and again live inside the edit modal, so
// "you must take points off something else first" is visible before typing
// rather than after saving.
//
// Only ACTIVE questions count, matching pqGroupWeightSum() exactly.
$weight_budgets = [];
$wb_res = $conn->query(
    "SELECT COALESCE(pw.sub_variant_target, '') AS grp, SUM(pw.weight) AS used, COUNT(*) AS n
     FROM pq_weight pw
     JOIN pq_questions q ON q.id = pw.question_id AND q.is_active = 1
     GROUP BY grp
     ORDER BY grp ASC"
);
if ($wb_res) {
    while ($b = $wb_res->fetch_assoc()) {
        $weight_budgets[$b['grp']] = ['used' => (float) $b['used'], 'n' => (int) $b['n']];
    }
}

// 3. Handle Filters
$search = $_GET['search'] ?? '';
$section_filter = $_GET['section_filter'] ?? '';
$variant_filter = $_GET['variant_filter'] ?? '';

// Build Query
// Weight and sub-variant live in pq_weight (keyed by question_id), NOT on
// pq_questions. Each question owns exactly one pq_weight row (enforced by the
// upsert in question_action.php), so a plain LEFT JOIN is safe and exposes
// pw.weight / pw.sub_variant_target directly for both the listing and the
// edit modal (which reads those field names off the row JSON).
//
// effective_help_text is what the edit modal loads, NOT q.help_text. The vendor
// PQ form and the review screen both score against
// COALESCE(pq_weight.help_text, pq_questions.help_text), and on a fair number of
// live questions those two have drifted apart -- so editing the options this
// page used to show meant editing a copy nobody was ever scored against, and
// wondering why the review screen disagreed. The modal now shows the options
// actually in force.
$sql = "SELECT q.*, s.section_name,
               pw.weight,
               pw.sub_variant_target,
               COALESCE(pw.help_text, q.help_text) AS effective_help_text
        FROM pq_questions q
        LEFT JOIN pq_sections s ON q.section_id = s.id
        LEFT JOIN pq_weight pw ON pw.question_id = q.id
        WHERE 1=1";

if (!empty($search)) {
    $safe_search = $conn->real_escape_string($search);
    $sql .= " AND q.question_text LIKE '%$safe_search%'";
}

if (!empty($section_filter)) {
    $safe_sec = $conn->real_escape_string($section_filter);
    $sql .= " AND q.section_id = '$safe_sec'";
}

if (!empty($variant_filter)) {
    $safe_var = $conn->real_escape_string($variant_filter);
    $sql .= " AND (q.variant_target LIKE '%$safe_var%' OR pw.sub_variant_target LIKE '%$safe_var%')";
}

$sql .= " ORDER BY s.sort_order ASC, q.id ASC";
$result = $conn->query($sql);

// The filters as they stand, sent along with every save/toggle so the page the
// editor comes back to is the page they left. question_action.php re-whitelists
// these keys before they reach a Location: header.
$return_query = http_build_query(array_filter([
    'search'         => $search,
    'section_filter' => $section_filter,
    'variant_filter' => $variant_filter,
], static fn($v) => $v !== '' && $v !== null));
?>

<nav class="navbar navbar-vss mb-4">
    <div class="container-fluid">
        <span class="navbar-brand fw-bold d-flex align-items-center gap-2">
            <?= vss_brand_logo() ?>Master Data: PQ Questions
        </span>
        <div class="d-flex align-items-center">
            <a href="<?= htmlspecialchars($dashboard_url ?? '') ?>" class="btn btn-sm btn-nav">
                <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
            </a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 pb-5">
    
    <!-- The result of the last save is announced in the shared dialog at the
         bottom of this file, not in a banner up here. The page now comes back
         scrolled to where the editor was working, so a banner pinned to the top
         of a long list is a message they would never see. -->

    <!-- Weight budget: what is left in each 100-point group ------------------
         Read-only, and deliberately above the filters rather than tucked in a
         tooltip: it is the constraint that decides whether an edit further down
         the page can be saved at all. -->
    <?php if (!empty($weight_budgets)): ?>
    <div class="card shadow-sm mb-4 border-0">
        <div class="card-body p-3">
            <div class="d-flex align-items-center gap-2 mb-2">
                <h6 class="mb-0 fw-bold text-primary"><i class="fas fa-balance-scale me-1"></i> Weight Budget</h6>
                <span class="text-muted small">Each business model / sub-category shares 100 points across its active questions.</span>
            </div>
            <div class="row g-2">
                <?php foreach ($weight_budgets as $grp => $b):
                    $used = $b['used'];
                    $free = 100.0 - $used;
                    // Full is the normal, healthy state here, so it is not dressed
                    // up as an error -- only a group that has somehow gone OVER 100
                    // is worth colouring as a problem.
                    if ($used > 100.0 + 0.001)      { $tone = 'danger';  $note = 'over budget'; }
                    elseif ($free < 0.001)          { $tone = 'secondary'; $note = 'full'; }
                    else                            { $tone = 'success'; $note = number_format($free, 2) . ' free'; }
                ?>
                <div class="col-md-3 col-sm-6">
                    <div class="border rounded-3 px-3 py-2 h-100">
                        <div class="fw-bold small text-dark text-truncate" title="<?= htmlspecialchars($grp !== '' ? $grp : 'All Sub-types') ?>">
                            <?= htmlspecialchars($grp !== '' ? $grp : 'All Sub-types') ?>
                        </div>
                        <div class="d-flex align-items-baseline gap-1">
                            <span class="fw-bold text-<?= $tone ?>"><?= number_format($used, 2) ?></span>
                            <span class="text-muted" style="font-size:0.75rem;">/ 100</span>
                            <span class="badge bg-<?= $tone ?>-subtle text-<?= $tone ?> border border-<?= $tone ?>-subtle ms-auto" style="font-size:0.62rem;"><?= $note ?></span>
                        </div>
                        <div class="text-muted" style="font-size:0.68rem;"><?= (int) $b['n'] ?> active question<?= $b['n'] === 1 ? '' : 's' ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter & Search Bar -->
    <div class="card shadow-sm mb-4 border-0 bg-light">
        <div class="card-body p-3">
            <form method="GET" action="manage_questions_new.php" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-start-0" placeholder="Search question text..." value="<?= htmlspecialchars($search ?? '') ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="section_filter" class="form-select">
                        <option value="">All Business Models</option>
                        <?php foreach($sections as $sec): ?>
                            <option value="<?= $sec['id'] ?>" <?= ($section_filter == $sec['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sec['section_name'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="variant_filter" class="form-select">
                        <option value="">All Targets</option>
                        <option value="Supplier" <?= ($variant_filter == 'Supplier') ? 'selected' : '' ?>>Supplier</option>
                        <option value="Contractor" <?= ($variant_filter == 'Contractor') ? 'selected' : '' ?>>Contractor</option>
                        <option value="Perseorangan" <?= ($variant_filter == 'Perseorangan') ? 'selected' : '' ?>>Perseorangan</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100 fw-bold">Filter</button>
                    <?php if(!empty($search) || !empty($section_filter) || !empty($variant_filter)): ?>
                        <a href="manage_questions_new.php" class="btn btn-outline-secondary">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-primary">Question Configuration</h5>
            <button class="btn btn-primary btn-sm fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addQuestionModal">
                <i class="fas fa-plus me-1"></i> Add New Question
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small text-muted text-uppercase">
                        <tr style="white-space: nowrap;">
                            <th class="ps-4" style="width: 13%;">Target Variant</th>
                            <th style="width: 12%;">Section</th>
                            <th style="width: 28%;">Question Text</th>
                            <th style="width: 28%;">Help / Options</th>
                            <th class="text-center" style="width: 6%;">Weight</th>
                            <th class="text-center" style="width: 6%;">Status</th>
                            <th class="text-end pe-4" style="width: 7%;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()):
                                // Carried into the edit modal so the confirmation can say how
                                // many PQs this change actually reaches, and how many are past
                                // Sourcing and therefore frozen.
                                $counts = $submission_counts[(int) $row['id']] ?? ['open' => 0, 'locked' => 0];
                                $row['open_submissions']   = $counts['open'];
                                $row['locked_submissions'] = $counts['locked'];
                            ?>
                                <tr>
                                    <td class="ps-4">
                                        <?php $vt = $row['variant_target'] ?? ''; ?>
                                        <div class="small fw-bold text-dark"><?= htmlspecialchars(($vt !== '' && $vt !== 'All') ? $vt : 'All Models') ?></div>
                                        <div class="text-muted text-truncate" style="font-size: 0.7rem; max-width: 150px;" title="<?= htmlspecialchars($row['sub_variant_target'] ?? '') ?>">
                                            <?= htmlspecialchars($row['sub_variant_target'] ?: 'All Sub-types') ?>
                                        </div>
                                    </td>
                                    <td class="fw-bold text-secondary small"><?= htmlspecialchars($row['section_name'] ?? 'Uncategorized') ?></td>
                                    <td>
                                        <div class="fw-bold text-dark mb-1" style="font-size: 0.9rem;"><?= htmlspecialchars($row['question_text'] ?? '') ?></div>
                                    </td>
                                    <td>
                                        <!-- The options actually in force (pq_weight's, falling
                                             back to the question's), matching what the vendor
                                             form and the review screen score against. -->
                                        <div class="small text-muted" style="white-space: pre-line; max-height: 4.5em; overflow: auto;" title="<?= htmlspecialchars($row['effective_help_text'] ?? '') ?>">
                                            <?= htmlspecialchars($row['effective_help_text'] ?: '- Free Text -') ?>
                                        </div>
                                    </td>
                                    <td class="text-center fw-bold text-primary">
                                        <?php if ($row['weight'] !== null): ?>
                                            <?= floatval($row['weight']) ?>
                                        <?php else: ?>
                                            <span class="text-muted fw-normal">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if($row['is_active']): ?>
                                            <span class="badge bg-success rounded-pill px-3">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary rounded-pill px-3">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button class="btn btn-sm btn-outline-primary" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editQuestionModal"
                                                onclick='populateEditModal(<?= json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <!-- return_query keeps the filters through the round-trip;
                                             js-keep-scroll keeps the reading position. -->
                                        <a href="question_action.php?action=toggle&id=<?= $row['id'] ?>&status=<?= $row['is_active'] ? 0 : 1 ?><?= $return_query !== '' ? '&return_query=' . urlencode($return_query) : '' ?>"
                                           class="btn btn-sm js-keep-scroll <?= $row['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?> ms-1"
                                           title="<?= $row['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                            <i class="fas <?= $row['is_active'] ? 'fa-ban' : 'fa-check' ?>"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">
                                <i class="fas fa-search fa-3x mb-3 opacity-25"></i><br>
                                No questions found matching your criteria.
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: ADD QUESTION -->
<div class="modal fade" id="addQuestionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-plus me-2"></i>Add PQ Question</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="question_action.php" method="POST" id="addQuestionForm" onsubmit="return submitAddQuestion()">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="return_query" value="<?= htmlspecialchars($return_query) ?>">
                <div class="modal-body p-4">
                    
                    <hr class="my-4">
                    <h6 class="fw-bold text-secondary mb-3">Applicability Targets</h6>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-primary">Main Business Model (Variant)</label>
                            <select name="variant_target" id="add_variant_target" class="form-select">
                                <option value="">All Models</option>
                                <option value="Contractor">Contractor</option>
                                <option value="Supplier">Supplier</option>
                                <option value="Perseorangan">Perseorangan</option>
                            </select>
                            <div class="form-text" style="font-size:0.7rem;">Leave empty for ALL.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-info">Specific Sub-Category</label>
                            <select name="sub_variant_target" id="add_sub_variant_target" class="form-select">
                                <option value="">All Sub-types</option>
                            </select>
                            <div class="form-text" style="font-size:0.7rem;">Select a Main Business Model first.</div>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-bold">Section <span class="text-danger">*</span></label>
                            <select name="section_id" class="form-select" required>
                                <option value="">Select Section...</option>
                                <?php foreach($sections as $sec): ?>
                                    <option value="<?= $sec['id'] ?>"><?= htmlspecialchars($sec['section_name'] ?? '') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Score Weight <span class="text-danger">*</span></label>
                            <input type="number" step="0.1" min="0" name="weight" class="form-control" required placeholder="e.g. 1.5">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Question Text <span class="text-danger">*</span></label>
                        <textarea name="question_text" class="form-control" rows="2" required placeholder="Enter the assessment criteria..."></textarea>
                    </div>

                    <!-- ANSWER TYPE DROPDOWN -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Answer Type <span class="text-danger">*</span></label>
                        <select id="add_answer_type" class="form-select answer-type-select" data-target="add" required>
                            <option value="free_text" selected>Free Text / Number Input</option>
                            <option value="multiple_choice">Multiple Choice (Options)</option>
                        </select>
                    </div>

                    <!-- DYNAMIC OPTIONS BUILDER -->
                    <div class="mb-3" id="add_options_container" style="display:none;">
                        <label class="form-label small fw-bold text-primary">Choices & Values Configuration</label>
                        <div id="add_options_list">
                            <!-- Dynamic rows will appear here -->
                            <div class="input-group input-group-sm mb-2 option-row">
                                <span class="input-group-text fw-bold">Score</span>
                                <input type="number" class="form-control option-val" placeholder="0-5" style="max-width: 80px;">
                                <span class="input-group-text fw-bold">Label</span>
                                <input type="text" class="form-control option-text" placeholder="Description (e.g. Excellent)">
                                <button type="button" class="btn btn-outline-danger remove-option" onclick="this.closest('.option-row').remove()"><i class="fas fa-times"></i></button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-1 fw-bold" onclick="addOptionRow('add_options_list')"><i class="fas fa-plus"></i> Add Option</button>
                        
                        <!-- Hidden input to hold the formatted string for the DB -->
                        <input type="hidden" name="help_text" id="add_help_text_hidden">
                    </div>

                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4">Save Question</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: EDIT QUESTION -->
<div class="modal fade" id="editQuestionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Edit Question</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="question_action.php" method="POST" id="editQuestionForm" enctype="multipart/form-data" onsubmit="return submitEditQuestion()">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="return_query" value="<?= htmlspecialchars($return_query) ?>">
                <input type="hidden" name="question_id" id="edit_id">
                
                <div class="modal-body p-4">
                    <hr class="my-4">

                    <!-- Business Model, Sub-Category and Section are fixed once a question
                         is created; they are shown disabled here and are NOT updated on save. -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-primary">Main Business Model (Variant)</label>
                            <select name="variant_target" id="edit_variant_target" class="form-select" disabled>
                                <option value="">All Models</option>
                                <option value="Contractor">Contractor</option>
                                <option value="Supplier">Supplier</option>
                                <option value="Perseorangan">Perseorangan</option>
                            </select>
                            <div class="form-text" style="font-size:0.7rem;"><i class="fas fa-lock me-1"></i>Cannot be changed after creation.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-info">Specific Sub-Category</label>
                            <select name="sub_variant_target" id="edit_sub_variant_target" class="form-select" disabled>
                                <option value="">All Sub-types</option>
                            </select>
                            <div class="form-text" style="font-size:0.7rem;"><i class="fas fa-lock me-1"></i>Cannot be changed after creation.</div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-bold">Section</label>
                            <select name="section_id" id="edit_section_id" class="form-select" disabled>
                                <option value="">Select Section...</option>
                                <?php foreach($sections as $sec): ?>
                                    <option value="<?= $sec['id'] ?>"><?= htmlspecialchars($sec['section_name'] ?? '') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" style="font-size:0.7rem;"><i class="fas fa-lock me-1"></i>Cannot be changed after creation.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Score Weight <span class="text-danger">*</span></label>
                            <input type="number" step="0.1" min="0" name="weight" id="edit_weight" class="form-control" required>
                            <!-- Live budget for this question's own group, so the
                                 100-point ceiling is visible while the number is
                                 being typed instead of after the save bounces. -->
                            <div id="edit_weight_budget" class="form-text" style="font-size:0.7rem;"></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Question Text <span class="text-danger">*</span></label>
                        <textarea name="question_text" id="edit_question_text" class="form-control" rows="2" required></textarea>
                    </div>

                    <!-- ANSWER TYPE DROPDOWN -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Answer Type <span class="text-danger">*</span></label>
                        <select id="edit_answer_type" class="form-select answer-type-select" data-target="edit" required>
                            <option value="free_text">Free Text / Number Input</option>
                            <option value="multiple_choice">Multiple Choice (Options)</option>
                        </select>
                    </div>

                    <!-- DYNAMIC OPTIONS BUILDER -->
                    <div class="mb-3" id="edit_options_container" style="display:none;">
                        <label class="form-label small fw-bold text-primary">Choices & Values Configuration</label>
                        <div id="edit_options_list">
                            <!-- Populated dynamically -->
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-1 fw-bold" onclick="addOptionRow('edit_options_list')"><i class="fas fa-plus"></i> Add Option</button>

                        <!-- Hidden input to hold the formatted string for the DB -->
                        <input type="hidden" name="help_text" id="edit_help_text_hidden">
                    </div>

                    <!-- CHANGE NOTE ---------------------------------------------
                         Appears only once the weight or an option score has
                         actually moved. Rewording a question or fixing a typo is
                         ordinary maintenance and is left alone; changing what a
                         vendor scores is a decision, and this is the only place
                         that decision gets written down. Hidden until it applies
                         so it does not read as one more required field on every
                         edit. -->
                    <div id="edit_change_note" class="mb-1 border-top pt-3" style="display:none;">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <h6 class="fw-bold text-warning-emphasis mb-0" style="font-size:0.85rem;">
                                <i class="fas fa-clipboard-check me-1"></i>Reason for this change
                            </h6>
                            <span id="edit_change_summary" class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" style="font-size:0.62rem;"></span>
                        </div>
                        <!-- Who this change actually reaches. Filled in by JS from the
                             counts carried on the row. -->
                        <div id="edit_change_reach" class="form-text mb-2" style="font-size:0.68rem;"></div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold mb-1">Notes / Remarks <span class="text-danger">*</span></label>
                            <textarea name="change_remarks" id="edit_change_remarks" class="form-control" rows="2"
                                      placeholder="e.g. weight moved from Riwayat Proyek to Laporan Keuangan per VM review 12 Aug"></textarea>
                            <div class="form-text" style="font-size:0.68rem;">Recorded with the before/after values and your name.</div>
                        </div>
                        <div>
                            <label class="form-label small fw-bold mb-1">Attach an image <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="file" name="change_image" id="edit_change_image" class="form-control form-control-sm"
                                   accept="image/png,image/jpeg,image/gif,image/webp">
                            <div class="form-text" style="font-size:0.68rem;">JPG, PNG, GIF or WEBP. Maximum 1 MB.</div>
                            <div id="edit_change_image_error" class="text-danger fw-bold mt-1" style="font-size:0.7rem; display:none;"></div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning text-dark fw-bold px-4">Update Question</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- SHARED DIALOG ------------------------------------------------------------
     Everything this page needs to say or ask now goes through here: the
     validation refusals, the "this will re-score vendors" confirmation, and the
     result of the last save. They used to be the browser's own alert()/confirm()
     boxes, which render as an unstyled strip pinned to the top of the window
     with the host name in it, and a page banner that scrolls out of sight. -->
<div class="modal fade" id="pqDialogModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header" id="pqDialogHeader">
                <h5 class="modal-title fw-bold" id="pqDialogTitle"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="pqDialogBody" style="font-size:0.9rem;"></div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" id="pqDialogCancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary fw-bold px-4" id="pqDialogOk">OK</button>
            </div>
        </div>
    </div>
</div>

<script>
    // --- DIALOGS ---------------------------------------------------------
    // One modal, three uses: a refusal (OK only), a question (Cancel /
    // Continue), and the result of the save that just happened. Bootstrap is
    // loaded by footer.php, i.e. AFTER this script -- so nothing here may touch
    // `bootstrap` at parse time, only inside handlers that run later.
    let pqDialogOnOk    = null;   // what Continue does, if anything
    let pqDialogGoAhead = false;  // Continue was pressed, as opposed to dismissed
    let pqDialogReopen  = null;   // modal to restore afterwards, if we hid one

    const PQ_DIALOG_TONES = {
        danger:  { header: 'bg-danger text-white',      close: 'btn-close-white', ok: 'btn-danger'  },
        warning: { header: 'bg-warning text-dark',      close: '',                ok: 'btn-warning text-dark' },
        success: { header: 'bg-success text-white',     close: 'btn-close-white', ok: 'btn-success' },
        info:    { header: 'bg-primary text-white',     close: 'btn-close-white', ok: 'btn-primary' }
    };

    function pqDialog(opts) {
        const tone   = PQ_DIALOG_TONES[opts.tone || 'info'];
        const header = document.getElementById('pqDialogHeader');
        const okBtn  = document.getElementById('pqDialogOk');
        const cancel = document.getElementById('pqDialogCancel');

        header.className = 'modal-header ' + tone.header;
        header.querySelector('.btn-close').className = 'btn-close ' + tone.close;
        document.getElementById('pqDialogTitle').innerHTML = opts.title;
        document.getElementById('pqDialogBody').innerHTML  = opts.body;
        okBtn.className = 'btn fw-bold px-4 ' + tone.ok;
        okBtn.textContent = opts.okText || 'OK';
        cancel.style.display = opts.onOk ? '' : 'none';

        pqDialogOnOk    = opts.onOk || null;
        pqDialogGoAhead = false;

        // Only one modal at a time -- Bootstrap does not really support stacking
        // them. Whichever form is open steps aside while the question is asked
        // and comes back if the answer is no, so nothing typed into it is lost
        // by saying Cancel.
        pqDialogReopen = null;
        const openForm = document.querySelector('.modal.show:not(#pqDialogModal)');
        if (opts.pauseOpenModal && openForm) {
            pqDialogReopen = openForm;
            openForm.addEventListener('hidden.bs.modal', function once() {
                openForm.removeEventListener('hidden.bs.modal', once);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('pqDialogModal')).show();
            });
            bootstrap.Modal.getOrCreateInstance(openForm).hide();
            return;
        }
        bootstrap.Modal.getOrCreateInstance(document.getElementById('pqDialogModal')).show();
    }

    /** A refusal or a notice. OK only. */
    function pqNotify(title, body, tone, pauseOpenModal) {
        pqDialog({ title: title, body: body, tone: tone || 'danger', pauseOpenModal: !!pauseOpenModal });
    }

    /** A question. Runs onOk only if Continue is pressed. */
    function pqAsk(title, body, onOk, okText) {
        pqDialog({
            title: title, body: body, tone: 'warning', okText: okText || 'Continue',
            onOk: onOk, pauseOpenModal: true
        });
    }

    document.getElementById('pqDialogOk').addEventListener('click', function () {
        pqDialogGoAhead = true;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('pqDialogModal')).hide();
    });

    // Acted on after the modal is fully closed, so a submit or a re-opened form
    // never races the backdrop being torn down.
    document.getElementById('pqDialogModal').addEventListener('hidden.bs.modal', function () {
        const fn = pqDialogOnOk, go = pqDialogGoAhead, reopen = pqDialogReopen;
        pqDialogOnOk = null; pqDialogGoAhead = false; pqDialogReopen = null;

        if (go && fn) { fn(); return; }
        if (reopen) { bootstrap.Modal.getOrCreateInstance(reopen).show(); }
    });

    // --- KEEP THE PAGE WHERE IT WAS -------------------------------------
    // A save is a round-trip through question_action.php, which used to land
    // back at the top of an unfiltered list. The filters travel in the form's
    // return_query field; the reading position travels here.
    const PQ_SCROLL_KEY = 'pqManageScroll';

    function rememberScroll() {
        try { sessionStorage.setItem(PQ_SCROLL_KEY, String(window.scrollY)); } catch (e) { /* private mode */ }
    }

    document.querySelectorAll('a.js-keep-scroll').forEach(a => a.addEventListener('click', rememberScroll));

    document.addEventListener('DOMContentLoaded', function () {
        let y = null;
        try {
            y = sessionStorage.getItem(PQ_SCROLL_KEY);
            sessionStorage.removeItem(PQ_SCROLL_KEY);   // one restore per save
        } catch (e) { /* private mode */ }
        if (y !== null) window.scrollTo(0, parseInt(y, 10) || 0);

        // The result of the save that just happened, as a dialog rather than a
        // banner -- with the page restored to mid-list, a banner at the top
        // would be a message nobody reads.
        const result = <?= json_encode([
            'msg'   => isset($_GET['msg'])   ? (string) $_GET['msg']   : null,
            'error' => isset($_GET['error']) ? (string) $_GET['error'] : null,
        ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        if (result.error) {
            pqNotify('<i class="fas fa-exclamation-circle me-2"></i>Not saved', pqEscape(result.error), 'danger');
        } else if (result.msg) {
            pqNotify('<i class="fas fa-check-circle me-2"></i>Saved', pqEscape(result.msg), 'success');
        }
        // Drop msg/error from the address bar once shown, so a later refresh
        // does not re-announce a save that happened ten minutes ago. The
        // filters stay in the URL, because those are still in force.
        if ((result.msg || result.error) && window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.delete('msg');
            url.searchParams.delete('error');
            window.history.replaceState({}, '', url.pathname + (url.search === '?' ? '' : url.search));
        }
    });

    /** Text into the dialog body, which is set as HTML. */
    function pqEscape(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    // --- DICTIONARY MAPPING FOR TARGETS ---
    const subOptions = {
        'Contractor': ['Project', 'Retail & Maintenance', 'Individual / Foreman'],
        'Supplier': ['Principal', 'Distributor', 'Retail', 'Service'],
        'Perseorangan': ['Perseorangan']
    };

    function updateSubVariants(variantSelectId, subVariantSelectId, selectedSub = '') {
        const variant = document.getElementById(variantSelectId).value;
        const subSelect = document.getElementById(subVariantSelectId);
        
        subSelect.innerHTML = '<option value="">All Sub-types</option>';

        if (variant && subOptions[variant]) {
            subOptions[variant].forEach(opt => {
                const option = document.createElement('option');
                option.value = opt;
                option.textContent = opt;
                if (opt === selectedSub) option.selected = true;
                subSelect.appendChild(option);
            });
        }
    }

    document.getElementById('add_variant_target').addEventListener('change', function() {
        updateSubVariants('add_variant_target', 'add_sub_variant_target');
    });

    document.getElementById('edit_variant_target').addEventListener('change', function() {
        updateSubVariants('edit_variant_target', 'edit_sub_variant_target');
    });

    // --- MULTIPLE CHOICE UI BUILDER ---
    
    // 1. Add a new row to the UI
    function addOptionRow(containerId, val = '', text = '') {
        const container = document.getElementById(containerId);
        const div = document.createElement('div');
        div.className = 'input-group input-group-sm mb-2 option-row';
        div.innerHTML = `
            <span class="input-group-text fw-bold">Score</span>
            <input type="number" class="form-control option-val" placeholder="0-5" style="max-width: 80px;" value="${val}">
            <span class="input-group-text fw-bold">Label</span>
            <input type="text" class="form-control option-text" placeholder="Description (e.g. Excellent)" value="${text}">
            <button type="button" class="btn btn-outline-danger remove-option" onclick="this.closest('.option-row').remove()"><i class="fas fa-times"></i></button>
        `;
        container.appendChild(div);
    }

    // 2. Handle Answer Type selection changes
    document.querySelectorAll('.answer-type-select').forEach(select => {
        select.addEventListener('change', function() {
            const mode = this.getAttribute('data-target'); // 'add' or 'edit'
            const container = document.getElementById(mode + '_options_container');
            const list = document.getElementById(mode + '_options_list');
            
            if (this.value === 'free_text') {
                container.style.display = 'none';
                // Remove Required to prevent invisible HTML5 validation blocks
                list.querySelectorAll('input').forEach(inp => inp.removeAttribute('required'));
            } else {
                container.style.display = 'block';
                // Enforce required
                list.querySelectorAll('input').forEach(inp => inp.setAttribute('required', 'true'));
                
                // Ensure at least one row exists
                if (list.children.length === 0) {
                    addOptionRow(mode + '_options_list');
                    list.querySelectorAll('input').forEach(inp => inp.setAttribute('required', 'true'));
                }
            }
        });
    });

    // 3. Compile UI Rows into (number) description format on Form Submit
    function buildHelpText(mode) {
        const type = document.getElementById(mode + '_answer_type').value;
        const hiddenInput = document.getElementById(mode + '_help_text_hidden');
        
        if (type === 'free_text') {
            hiddenInput.value = ''; // Empty string signals Free Text to the database
        } else {
            let formattedString = '';
            const rows = document.getElementById(mode + '_options_list').querySelectorAll('.option-row');
            
            rows.forEach(row => {
                const val = row.querySelector('.option-val').value;
                const txt = row.querySelector('.option-text').value;
                if (val !== '' && txt.trim() !== '') {
                    formattedString += `(${val}) ${txt.trim()}\n`;
                }
            });
            
            if (formattedString === '') {
                pqNotify('Nothing to score',
                    'Give this question at least one option with both a score and a label, or switch it back to Free Text.',
                    'danger', true);
                return false; // Stop submission
            }

            hiddenInput.value = formattedString.trim(); // Populate hidden field for PHP
        }
        return true; // Allow submission
    }

    /** Add form: nothing to confirm, but the reading position is still kept. */
    function submitAddQuestion() {
        if (!buildHelpText('add')) return false;
        rememberScroll();
        return true;
    }

    // --- WEIGHT BUDGET + CHANGE NOTE ------------------------------------------
    // Every sub-category shares 100 points across its active questions, and the
    // save refuses anything that pushes a group past it. All of them currently
    // sit at exactly 100, so without this the only feedback was a bounced save.
    const WEIGHT_BUDGETS = <?= json_encode($weight_budgets, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const MAX_CHANGE_IMAGE_BYTES = 1048576;   // 1 MB, mirrored server-side

    // The state the modal opened with, so "did this actually change?" is asked
    // against what was loaded rather than against the database on every keystroke.
    let editOriginal = { weight: 0, group: '', options: '', active: true, openSubs: 0, lockedSubs: 0 };

    /**
     * What the REST of this question's group already spends, mirroring
     * pqGroupWeightSum() on the server: active questions only, this one excluded.
     * An inactive question contributes nothing to the stored total, so there is
     * nothing of its own to subtract back out.
     */
    function othersInGroup(budget) {
        return editOriginal.active ? (budget.used - editOriginal.weight) : budget.used;
    }

    /**
     * Money question? Mirrors pqIsFinancialQuestion() in
     * includes/pq_financial_scoring.php -- these are scored from the amount the
     * vendor typed against the bands in the option list, not from a picked
     * option, so they re-grade differently and are confirmed differently.
     */
    function isFinancialQuestion(text) {
        const t = (text || '').toLowerCase();
        return t.indexOf('income') !== -1 || t.indexOf('pendapatan') !== -1
            || t.indexOf('capital') !== -1 || t.indexOf('modal') !== -1;
    }

    /** Options string -> [{score, key}], the same parse the server does. */
    function parseOptions(text) {
        const out = [];
        if (!text) return out;
        const re = /\((\d+)\)\s*([^]*?)(?=\(\d+\)|$)/g;
        let m;
        while ((m = re.exec(text)) !== null) {
            const label = m[2].trim();
            if (label === '') continue;
            out.push({ score: parseInt(m[1], 10), key: label.toLowerCase().replace(/\s+/g, ' '), label: label });
        }
        return out;
    }

    /** Options whose wording stayed but whose number moved: these re-grade vendors. */
    function optionScoreMoves(oldText, newText) {
        const index = (opts) => {
            const by = {}, dupe = {};
            opts.forEach(o => { if (by[o.key] !== undefined) { dupe[o.key] = true; } else { by[o.key] = o; } });
            Object.keys(dupe).forEach(k => delete by[k]);
            return by;
        };
        const a = index(parseOptions(oldText)), b = index(parseOptions(newText));
        const moves = [];
        Object.keys(a).forEach(k => {
            if (b[k] && b[k].score !== a[k].score) {
                moves.push({ label: a[k].label, from: a[k].score, to: b[k].score });
            }
        });
        return moves;
    }

    /** Current options as the string that will be posted. */
    function currentEditOptions() {
        if (document.getElementById('edit_answer_type').value === 'free_text') return '';
        let s = '';
        document.getElementById('edit_options_list').querySelectorAll('.option-row').forEach(row => {
            const val = row.querySelector('.option-val').value;
            const txt = row.querySelector('.option-text').value;
            if (val !== '' && txt.trim() !== '') s += '(' + val + ') ' + txt.trim() + '\n';
        });
        return s.trim();
    }

    function editWeightNow() {
        const v = parseFloat(document.getElementById('edit_weight').value);
        return isNaN(v) ? 0 : v;
    }

    /** Live budget line under the weight field, plus the change-note reveal. */
    function refreshEditState() {
        const budget = WEIGHT_BUDGETS[editOriginal.group];
        const el = document.getElementById('edit_weight_budget');
        const w = editWeightNow();

        if (budget) {
            const others = othersInGroup(budget);
            const total = others + w;
            const groupName = editOriginal.group === '' ? 'All Sub-types' : editOriginal.group;
            if (total > 100.0001 && w > editOriginal.weight) {
                el.className = 'form-text text-danger fw-bold';
                el.textContent = groupName + ': others use ' + others.toFixed(2) + ', so this would total '
                    + total.toFixed(2) + ' — ' + (total - 100).toFixed(2)
                    + ' over the 100-point budget. Lower another question in this group first.';
            } else {
                el.className = 'form-text';
                el.textContent = groupName + ': others use ' + others.toFixed(2) + ' of 100 · this question '
                    + w.toFixed(2) + ' · total ' + total.toFixed(2) + '.';
            }
        } else {
            el.className = 'form-text';
            el.textContent = '';
        }

        const weightChanged = Math.abs(w - editOriginal.weight) > 0.0001;
        const optsChanged = currentEditOptions() !== editOriginal.options;
        const note = document.getElementById('edit_change_note');
        const summary = document.getElementById('edit_change_summary');

        if (weightChanged || optsChanged) {
            note.style.display = 'block';
            const bits = [];
            if (weightChanged) bits.push('weight ' + editOriginal.weight.toFixed(2) + ' → ' + w.toFixed(2));
            if (optsChanged) bits.push('option scores edited');
            summary.textContent = bits.join(' · ');
            // The blast radius, visible while the change is being made and not
            // only in the confirmation at the end.
            document.getElementById('edit_change_reach').innerHTML =
                '<i class="fas fa-pen-to-square me-1"></i>Applies to <strong>' + editOriginal.openSubs
                + '</strong> PQ' + (editOriginal.openSubs === 1 ? '' : 's') + ' still under Sourcing. '
                + '<i class="fas fa-lock ms-1 me-1"></i><strong>' + editOriginal.lockedSubs
                + '</strong> already sent to VM, approved or rejected '
                + (editOriginal.lockedSubs === 1 ? 'is' : 'are') + ' left untouched.';
        } else {
            note.style.display = 'none';
            summary.textContent = '';
        }
        return { weightChanged, optsChanged };
    }

    // Any edit to the weight or to an option row re-evaluates the budget line and
    // whether the reason box is needed. Delegated so rows added later are covered.
    document.getElementById('edit_weight').addEventListener('input', refreshEditState);
    document.getElementById('edit_options_list').addEventListener('input', refreshEditState);
    document.getElementById('edit_answer_type').addEventListener('change', refreshEditState);

    /**
     * Submit guard for the edit form: options, budget, reason, attachment, and
     * the "this re-scores vendors" confirmation.
     *
     * The confirmation is a modal now, so the answer arrives on a callback
     * instead of from confirm()'s return value -- the form cannot be allowed to
     * submit while the question is still on screen. So the first pass always
     * cancels the submit, and the callback re-submits with editConfirmed set,
     * which this function then waves straight through.
     */
    let editConfirmed = false;

    function resubmitEditForm() {
        editConfirmed = true;
        const form = document.getElementById('editQuestionForm');
        if (form.requestSubmit) { form.requestSubmit(); } else { rememberScroll(); form.submit(); }
    }

    function submitEditQuestion() {
        if (editConfirmed) { editConfirmed = false; rememberScroll(); return true; }

        if (!buildHelpText('edit')) return false;

        const state = refreshEditState();
        const w = editWeightNow();
        const budget = WEIGHT_BUDGETS[editOriginal.group];
        const groupName = editOriginal.group === '' ? 'All Sub-types' : editOriginal.group;

        // Refuse client-side what the server would refuse anyway, so the answer
        // arrives before the form is thrown away rather than after.
        if (budget) {
            const total = othersInGroup(budget) + w;
            if (total > 100.0001 && w > editOriginal.weight) {
                pqNotify('Over the 100-point budget',
                    'This would take <strong>' + pqEscape(groupName) + '</strong> to '
                    + total.toFixed(2) + ' points, ' + (total - 100).toFixed(2)
                    + ' over its budget.<p class="mb-0 mt-2">Lower another question in the same group first.</p>',
                    'danger', true);
                return false;
            }
        }

        if (state.weightChanged || state.optsChanged) {
            const remarks = document.getElementById('edit_change_remarks');
            if (remarks.value.trim() === '') {
                pqNotify('A reason is needed',
                    'Write a short note explaining why the weight or the option scores are changing. '
                    + 'It is recorded with the before/after values and your name.',
                    'danger', true);
                // Focused once the dialog is out of the way and the form is back.
                document.getElementById('pqDialogModal').addEventListener('hidden.bs.modal',
                    function once() {
                        this.removeEventListener('hidden.bs.modal', once);
                        setTimeout(() => remarks.focus(), 300);
                    });
                return false;
            }

            const img = document.getElementById('edit_change_image');
            const err = document.getElementById('edit_change_image_error');
            err.style.display = 'none';
            if (img.files && img.files.length > 0 && img.files[0].size > MAX_CHANGE_IMAGE_BYTES) {
                err.textContent = 'That image is ' + (img.files[0].size / 1048576).toFixed(2)
                    + ' MB. The limit is 1 MB.';
                err.style.display = 'block';
                return false;
            }

            // Re-grading vendors who have already answered is spelled out and
            // confirmed rather than done quietly. How it re-grades them depends
            // on the kind of question, so the two are asked differently -- and
            // both now say plainly that only the PQs still with Sourcing move.
            const optsNow = currentEditOptions();
            if (isFinancialQuestion(document.getElementById('edit_question_text').value)) {
                // A money question scores from the AMOUNT the vendor typed
                // against these bands, so every existing answer is re-derived --
                // there is no per-option before/after to list.
                if (state.optsChanged) {
                    pqAsk('Re-score this amount question?',
                        '<p>This question is scored from the <strong>amount each vendor entered</strong>, '
                        + 'read against the bands below.</p>'
                        + '<p>Changing them re-derives the score of every answer on record '
                        + 'for it.</p>' + reachHtml(),
                        resubmitEditForm, 'Yes, apply');
                    return false;
                }
            } else {
                const moves = optionScoreMoves(editOriginal.options, optsNow);
                if (moves.length > 0) {
                    const lines = moves.map(m =>
                        '<li><strong>' + pqEscape(m.label) + '</strong>: ' + m.from + ' &rarr; ' + m.to + '</li>'
                    ).join('');
                    pqAsk('Re-score answers on these options?',
                        '<p>These options keep their wording but change their score:</p>'
                        + '<ul class="mb-2">' + lines + '</ul>'
                        + '<p>Answers already given against them move to the new score, and those '
                        + 'submissions are re-totalled.</p>' + reachHtml(),
                        resubmitEditForm, 'Yes, apply');
                    return false;
                }
            }
        }
        rememberScroll();
        return true;
    }

    /** The freeze, in the confirmation itself: who moves and who does not. */
    function reachHtml() {
        const open = editOriginal.openSubs, locked = editOriginal.lockedSubs;
        let s = '<div class="border-top pt-2 mt-2" style="font-size:0.82rem;">';
        s += '<div><i class="fas fa-pen-to-square me-1 text-warning"></i><strong>'
           + open + '</strong> PQ' + (open === 1 ? '' : 's') + ' still under Sourcing '
           + (open === 1 ? 'is' : 'are') + ' re-scored.</div>';
        s += '<div class="text-muted"><i class="fas fa-lock me-1"></i><strong>'
           + locked + '</strong> already sent to VM, approved or rejected '
           + (locked === 1 ? 'keeps its' : 'keep their') + ' existing score.</div>';
        return s + '</div>';
    }

    // --- POPULATE EDIT MODAL ---
    function populateEditModal(data) {
        document.getElementById('edit_id').value = data.id;
        document.getElementById('edit_section_id').value = data.section_id;
        document.getElementById('edit_weight').value = data.weight;
        document.getElementById('edit_question_text').value = data.question_text;

        const answerTypeSelect = document.getElementById('edit_answer_type');
        const optionsContainer = document.getElementById('edit_options_container');
        const optionsList = document.getElementById('edit_options_list');

        // The options actually in force -- pq_weight's where it has them, the
        // question's otherwise. Same value the vendor form and the review screen
        // score against; q.help_text alone would be a copy that has drifted.
        const effectiveHelp = (data.effective_help_text !== undefined && data.effective_help_text !== null)
            ? data.effective_help_text
            : (data.help_text || '');

        // Clear previous rows
        optionsList.innerHTML = '';

        // Determine Type & Parse DB String
        if (!effectiveHelp || effectiveHelp.trim() === '') {
            // Free Text Mode
            answerTypeSelect.value = 'free_text';
            optionsContainer.style.display = 'none';
        } else {
            // Multiple Choice Mode
            answerTypeSelect.value = 'multiple_choice';
            optionsContainer.style.display = 'block';

            // Regex Parse the string back into inputs
            const lines = effectiveHelp.split('\n');
            lines.forEach(line => {
                const match = line.trim().match(/^\((\d+)\)\s*(.*)/);
                if (match) {
                    addOptionRow('edit_options_list', match[1], match[2]);
                }
            });

            // Fallback if formatting was malformed
            if (optionsList.children.length === 0) {
                addOptionRow('edit_options_list');
            }

            // Set required fields dynamically
            optionsList.querySelectorAll('input').forEach(inp => inp.setAttribute('required', 'true'));
        }

        // Setup Dropdowns
        document.getElementById('edit_variant_target').value = data.variant_target || '';
        updateSubVariants('edit_variant_target', 'edit_sub_variant_target', data.sub_variant_target || '');

        // Baseline for the change detection, captured AFTER the rows are built so
        // it reflects exactly what the editor is looking at.
        editOriginal = {
            weight: parseFloat(data.weight) || 0,
            group: data.sub_variant_target || '',
            options: currentEditOptions(),
            active: !!parseInt(data.is_active, 10),
            // How far a scoring change to this question reaches: PQs still with
            // Sourcing move, everything past it is frozen.
            openSubs: parseInt(data.open_submissions, 10) || 0,
            lockedSubs: parseInt(data.locked_submissions, 10) || 0
        };
        editConfirmed = false;
        document.getElementById('edit_change_remarks').value = '';
        document.getElementById('edit_change_image').value = '';
        document.getElementById('edit_change_image_error').style.display = 'none';
        refreshEditState();
    }
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>