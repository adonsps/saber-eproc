<?php
// includes/quotation_bulk_ui.php
//
// Shared UI for the bulk Initial Quotation Price CSV template/import
// (pages/ajax_quotation_bulk.php), used by dashboard_mgr.php,
// dashboard_project_head.php and dashboard_pic.php so they can't drift apart:
//   qb_render_toolbar()  -- the "Initial Quotation Price" dropdown (Download
//                           Template for the type(s) the user works on +
//                           Import), placed in the table toolbar;
//   qb_guide_for()       -- what the "How to fill" guide shows for this user;
//   qb_render_modal()    -- the Import modal (with the guide) + its script
//                           (fixed-position, render it once after jQuery).
//
// Flow: choose file -> Validate (server previews every canvassing / ticket,
// writes nothing) -> tick -> Import (server re-validates from scratch, then
// saves each ticked unit on its own) -> results -> reload.
//
// The guide lives here, not in the file: the template is a plain CSV
// (confirmed by user, 2026-09-29).

if (!function_exists('qb_render_toolbar')) {

// People who don't get the bulk template/import at all (requested 2026-09-28:
// Mr Hadi, Mr Andreas, Mr Franky). They still see the Initial Quotation Price
// column and the per-canvassing modal -- only the dropdown is hidden, and
// ajax_quotation_bulk.php refuses them too.
if (!defined('QB_HIDDEN_USERNAMES')) define('QB_HIDDEN_USERNAMES', ['hadi.risali', 'andreas.yonathan', 'teofilus.franky']);

function qb_hidden_for(array $session): bool {
    return in_array(strtolower(trim((string)($session['username'] ?? ''))), QB_HIDDEN_USERNAMES, true);
}

function qb_render_toolbar(string $source, bool $showProject, bool $showNonProject, bool $showManual = false): void {
    $base = 'ajax_quotation_bulk.php?action=template&source=' . urlencode($source);
    ?>
    <div class="relative" id="qbDropdownContainer">
        <button type="button" id="qbDropdownBtn" class="group flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-white rounded-lg shadow-sm hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
            Initial Quotation Price
            <svg class="w-3 h-3 opacity-70 transition-transform duration-150" id="qbDropdownChevron" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
        </button>
        <div id="qbDropdownMenu" class="hidden absolute left-0 mt-1.5 w-64 bg-white rounded-md shadow-lg border border-gray-200 z-50">
            <div class="py-1">
                <?php if ($showManual): ?>
                <button type="button" id="mcaOpen" class="w-full text-left px-4 py-1.5 text-xs text-gray-700 hover:bg-emerald-50 hover:text-emerald-700 font-medium">Manual Input (Cost Avoidance)</button>
                <?php endif; ?>
                <?php if ($showProject): ?>
                <a href="<?= htmlspecialchars($base . '&type=project') ?>" class="qb-template-link block w-full text-left px-4 py-1.5 text-xs text-gray-700 hover:bg-emerald-50 hover:text-emerald-700 font-medium">Download Template (Project)</a>
                <?php endif; ?>
                <?php if ($showNonProject): ?>
                <a href="<?= htmlspecialchars($base . '&type=nonproject') ?>" class="qb-template-link block w-full text-left px-4 py-1.5 text-xs text-gray-700 hover:bg-emerald-50 hover:text-emerald-700 font-medium">Download Template (Non-Project)</a>
                <?php endif; ?>
                <?php if ($showProject || $showNonProject): ?>
                <button type="button" id="qbOpenImport" class="w-full text-left px-4 py-1.5 text-xs text-gray-700 hover:bg-emerald-50 hover:text-emerald-700 font-medium border-t border-gray-100 mt-1 pt-2">Import</button>
                <?php endif; ?>
            </div>
            <p id="qbTemplateHint" class="hidden px-4 pb-2 text-[11px] text-emerald-700">Preparing the template &mdash; the download starts in a few seconds.</p>
        </div>
    </div>
    <?php
}

/**
 * Guide content for this user: which template types, the active currencies
 * and -- for Project -- the taxonomy codes of the teams they work on (each
 * team's own codes only, so no duplicates).
 */
function qb_guide_for(PDO $pdo, array $session, bool $showProject, bool $showNonProject): array {
    require_once __DIR__ . '/quotation_bulk.php';
    $teams = [];
    if ($showProject) {
        if (($session['role'] ?? '') === 'PIC') {
            $stmt = $pdo->prepare("
                SELECT DISTINCT pp.project_group_number
                FROM prs JOIN project_pgroups pp ON pp.p_group = prs.p_group AND pp.status = 'ACTIVE'
                WHERE prs.assigned_pic = ?
            ");
            $stmt->execute([(string)($session['name'] ?? '')]);
            $groups = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } else {
            $groups = get_project_head_groups($pdo, $session);
        }
        foreach ($groups as $g) {
            if (isset(CA_PROJECT_GROUP_CODE[$g])) $teams[CA_PROJECT_GROUP_CODE[$g]] = true;
        }
    }
    $data = quotation_bulk_guide_data($pdo, array_keys($teams));
    return ['project' => $showProject, 'nonproject' => $showNonProject] + $data;
}

function qb_render_modal(array $guide = []): void {
    $gProject = !empty($guide['project']);
    $gNonProject = !empty($guide['nonproject']);
    $rateMap = $guide['currencies'] ?? ['IDR' => 1];
    $codes = array_keys($rateMap);
    $currencies = count($codes) > 1 ? implode(', ', array_slice($codes, 0, -1)) . ' or ' . end($codes) : implode('', $codes);
    $th = 'px-2 py-1 text-left font-semibold text-gray-600 border-b border-gray-200';
    $td = 'px-2 py-1 align-top border-b border-gray-100';
    ?>
<!-- BULK QUOTATION IMPORT MODAL -->
<div id="qbImportModal" class="fixed z-50 inset-0 hidden overflow-y-auto">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
    <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity backdrop-blur-sm" id="qbImportOverlay"></div>
    <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
    <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-5xl sm:w-full">
        <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
            <h3 class="text-lg font-medium text-gray-900">Import Initial Quotation Price</h3>
            <p class="text-xs text-gray-500 mt-1 mb-3">Upload the filled-in template (.csv).</p>

            <details id="qbGuide" class="mb-4 border border-emerald-200 bg-emerald-50/40 rounded-lg text-xs text-gray-700">
                <summary class="cursor-pointer select-none px-3 py-2 font-semibold text-emerald-800">How to fill the template</summary>
                <div class="px-3 pb-3 space-y-3">
                    <ol class="list-decimal pl-5 space-y-1">
                        <li>Download the template. It lists only items that still need a price.</li>
                        <li>Fill it in Excel or Google Sheets and save it as <b>CSV</b>. Keep the header row.</li>
                        <li>Upload it here and press <b>Validate</b>. Nothing is saved until you press <b>Import</b>.</li>
                    </ol>
                    <p>Rows you leave empty are skipped. A row with a problem shows the reason, and the other rows still import. A price that is already saved shows as <b>Change</b>.</p>
                    <?php if ($gProject): ?>
                    <div>
                        <p class="font-semibold text-gray-800 mb-1">GP Project</p>
                        <table class="w-full bg-white border border-gray-200 rounded">
                            <thead><tr><th class="<?= $th ?>">Column</th><th class="<?= $th ?>">Fill in</th></tr></thead>
                            <tbody>
                                <tr><td class="<?= $td ?> font-mono">canvassing_no</td><td class="<?= $td ?>">Already filled. Do not change.</td></tr>
                                <tr><td class="<?= $td ?> font-mono">baseline_currency</td><td class="<?= $td ?>"><?= htmlspecialchars($currencies) ?>.</td></tr>
                                <tr><td class="<?= $td ?> font-mono">baseline_price</td><td class="<?= $td ?>">The initial quotation price for the item or service.</td></tr>
                                <tr><td class="<?= $td ?> font-mono">exchange_rate</td><td class="<?= $td ?>">Only for a foreign currency. Rupiah for 1 unit, e.g. <span class="font-mono">16250</span>.</td></tr>
                                <tr><td class="<?= $td ?> font-mono">taxonomy</td><td class="<?= $td ?>">The code and name of the canvassing's team, written like <span class="font-mono">J1 Civil-Architecture</span>. See the lists below.</td></tr>
                            </tbody>
                        </table>
                        <?php if (!empty($guide['taxonomy'])): ?>
                        <p class="font-semibold text-gray-800 mt-3 mb-1">Taxonomy codes (use the code of the team that owns the canvassing)</p>
                        <div class="grid gap-3 sm:grid-cols-<?= min(3, count($guide['taxonomy'])) ?>">
                            <?php foreach ($guide['taxonomy'] as $team => $opts): ?>
                            <table class="w-full bg-white border border-gray-200 rounded self-start">
                                <thead><tr><th class="<?= $th ?>" colspan="2"><?= htmlspecialchars($team) ?></th></tr></thead>
                                <tbody>
                                    <?php foreach ($opts as $o): ?>
                                    <tr><td class="<?= $td ?> font-mono font-semibold w-10"><?= htmlspecialchars($o['code'] !== '' ? $o['code'] : '-') ?></td><td class="<?= $td ?>"><?= htmlspecialchars($o['label']) ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($gNonProject): ?>
                    <div>
                        <p class="font-semibold text-gray-800 mb-1">GP Non-Project</p>
                        <table class="w-full bg-white border border-gray-200 rounded">
                            <thead><tr><th class="<?= $th ?>">Column</th><th class="<?= $th ?>">Fill in</th></tr></thead>
                            <tbody>
                                <tr><td class="<?= $td ?> font-mono">ticket_no, ticket_line_item</td><td class="<?= $td ?>">Already filled. Do not change.</td></tr>
                                <tr><td class="<?= $td ?> font-mono">sku</td><td class="<?= $td ?>">The item's SKU number.</td></tr>
                                <tr><td class="<?= $td ?> font-mono">item_description</td><td class="<?= $td ?>">The item name.</td></tr>
                                <tr><td class="<?= $td ?> font-mono">qty</td><td class="<?= $td ?>">Already filled, for reference. Do not change.</td></tr>
                                <tr><td class="<?= $td ?> font-mono">baseline_currency</td><td class="<?= $td ?>"><?= htmlspecialchars($currencies) ?>.</td></tr>
                                <tr><td class="<?= $td ?> font-mono">baseline_price</td><td class="<?= $td ?>">The initial quotation price <strong>per 1 unit, before tax</strong> (quotation total &divide; qty), not the line total.</td></tr>
                                <tr><td class="<?= $td ?> font-mono">exchange_rate</td><td class="<?= $td ?>">Only for a foreign currency. Rupiah for 1 unit, e.g. <span class="font-mono">16250</span>.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </details>

            <div id="qbStepUpload">
                <label id="qbDropZone" for="qbFile" class="flex flex-col items-center justify-center w-full px-6 py-8 border-2 border-dashed border-gray-300 rounded-lg bg-gray-50 cursor-pointer hover:border-emerald-400 hover:bg-emerald-50 transition-colors">
                    <svg class="w-10 h-10 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                    <span id="qbDropText" class="text-sm text-gray-700"><span class="font-semibold text-emerald-700">Click to choose</span> or drag your .csv file here</span>
                    <span id="qbDropFile" class="hidden mt-2 inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-100 text-emerald-800 text-xs font-semibold"></span>
                    <input type="file" id="qbFile" accept=".csv,text/csv" class="hidden">
                </label>
                <div class="flex justify-end mt-3">
                    <button type="button" id="qbValidateBtn" class="inline-flex items-center px-4 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-md shadow-sm">Validate</button>
                </div>
            </div>

            <div id="qbStepPreview" class="hidden mt-4">
                <div class="flex flex-wrap items-center gap-2 mb-3 text-xs">
                    <span class="px-2 py-1 rounded bg-green-100 text-green-800 font-semibold"><span id="qbCountReady">0</span> ready</span>
                    <span class="px-2 py-1 rounded bg-red-100 text-red-800 font-semibold"><span id="qbCountError">0</span> with errors</span>
                    <span class="px-2 py-1 rounded bg-gray-100 text-gray-600 font-semibold"><span id="qbCountSkipped">0</span> Skipped</span>
                </div>
                <div class="max-h-[26rem] overflow-y-auto border border-gray-200 rounded-md">
                    <table class="min-w-full text-xs">
                        <thead class="bg-gray-50 sticky top-0">
                            <tr class="text-left text-gray-600 uppercase tracking-wider">
                                <th class="px-3 py-2 w-8"><input type="checkbox" id="qbCheckAll" class="rounded border-gray-300" checked></th>
                                <th class="px-3 py-2" id="qbKeyHeader">Canvassing / Ticket</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2 text-center">Lines</th>
                                <th class="px-3 py-2">Data</th>
                            </tr>
                        </thead>
                        <tbody id="qbPreviewBody"></tbody>
                    </table>
                </div>
            </div>

            <div id="qbStepResult" class="hidden mt-4 rounded-md border p-3">
                <p id="qbResultSummary" class="text-sm font-semibold mb-2"></p>
                <ul id="qbResultList" class="max-h-[26rem] overflow-y-auto text-xs space-y-1"></ul>
            </div>

            <p id="qbEditWarn" class="hidden text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-md px-3 py-2 mt-3"></p>
            <p id="qbError" class="hidden text-xs text-red-600 mt-3"></p>
        </div>
        <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse gap-2">
            <button type="button" id="qbConfirmBtn" class="hidden w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-emerald-600 text-base font-bold text-white hover:bg-emerald-700 sm:w-auto sm:text-sm">Import</button>
            <button type="button" id="qbDoneBtn" class="hidden w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-bold text-white hover:bg-indigo-700 sm:w-auto sm:text-sm">Done</button>
            <button type="button" id="qbCancelBtn" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-bold text-gray-700 hover:bg-gray-50 sm:mt-0 sm:w-auto sm:text-sm">Cancel</button>
        </div>
    </div>
  </div>
</div>
<script>
$(function() {
    var qbRows = [], qbType = null, qbSaved = false, qbFileObj = null;

    function qbEsc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }
    function qbShowError(msg) { $('#qbError').text(msg || '').toggleClass('hidden', !msg); }

    // Dropdown
    $('#qbDropdownBtn').on('click', function(e) {
        e.stopPropagation();
        $('#qbDropdownMenu').toggleClass('hidden');
        $('#qbDropdownChevron').toggleClass('rotate-180');
    });
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#qbDropdownContainer').length) {
            $('#qbDropdownMenu').addClass('hidden');
            $('#qbDropdownChevron').removeClass('rotate-180');
        }
    });
    // Building the worklist takes a few seconds server-side; say so.
    $('.qb-template-link').on('click', function() {
        $('#qbTemplateHint').removeClass('hidden');
        setTimeout(function() { $('#qbTemplateHint').addClass('hidden'); }, 12000);
    });

    // Drop zone: the hidden #qbFile covers click-to-choose; drag & drop sets
    // the same qbFileObj. Choosing a new file clears any earlier preview.
    function qbSetFile(file) {
        qbFileObj = file || null;
        $('#qbDropFile').text(file ? file.name : '').toggleClass('hidden', !file);
        $('#qbStepPreview, #qbConfirmBtn').addClass('hidden');
        $('#qbPreviewBody').empty();
        qbShowError(file && !/\.(csv|txt)$/i.test(file.name) ? 'Please choose the CSV template (.csv).' : '');
    }
    $('#qbFile').on('change', function() { qbSetFile(this.files[0]); });
    $('#qbDropZone').on('dragenter dragover', function(e) {
        e.preventDefault(); e.stopPropagation();
        $(this).addClass('border-emerald-500 bg-emerald-50').removeClass('border-gray-300 bg-gray-50');
    }).on('dragleave dragend drop', function(e) {
        e.preventDefault(); e.stopPropagation();
        $(this).removeClass('border-emerald-500 bg-emerald-50').addClass('border-gray-300 bg-gray-50');
    }).on('drop', function(e) {
        var files = e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files;
        if (files && files.length) qbSetFile(files[0]);
    });

    function qbReset() {
        qbRows = []; qbType = null; qbSaved = false;
        $('#qbFile').val('');
        qbSetFile(null);
        $('#qbValidateBtn').prop('disabled', false).text('Validate');
        $('#qbStepUpload').removeClass('hidden');
        $('#qbStepPreview, #qbStepResult, #qbConfirmBtn, #qbDoneBtn').addClass('hidden');
        $('#qbPreviewBody, #qbResultList').empty();
        $('#qbCheckAll').prop('checked', true);
        qbShowError('');
    }

    $('#qbOpenImport').on('click', function() {
        $('#qbDropdownMenu').addClass('hidden');
        qbReset();
        $('#qbImportModal').removeClass('hidden');
    });
    $('#qbCancelBtn, #qbImportOverlay').on('click', function() {
        $('#qbImportModal').addClass('hidden');
        if (qbSaved) location.reload();
    });
    $('#qbDoneBtn').on('click', function() { location.reload(); });

    // Changing prices that are already saved needs one explicit yes: the first
    // press of Import turns the button into the confirmation.
    var qbEditArmed = false;
    function qbUpdateConfirm() {
        var n = $('#qbPreviewBody .qb-pick:checked').length;
        var unit = qbType === 'nonproject' ? 'ticket' : 'canvassing';
        qbEditArmed = false;
        $('#qbEditWarn').addClass('hidden');
        $('#qbConfirmBtn').text('Import ' + n + ' ' + unit + (n === 1 ? '' : 's'))
            .prop('disabled', n === 0).toggleClass('opacity-50 cursor-not-allowed', n === 0);
    }

    $('#qbValidateBtn').on('click', function() {
        var file = qbFileObj;
        if (!file) { qbShowError('Choose the filled-in template (.csv) first.'); return; }
        qbShowError('');
        var fd = new FormData();
        fd.append('action', 'validate');
        fd.append('file', file);
        var btn = $(this).prop('disabled', true).text('Validating...');
        $.ajax({
            url: 'ajax_quotation_bulk.php', type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: function(resp) {
                btn.prop('disabled', false).text('Validate');
                if (resp.status !== 'success') { qbShowError(resp.message || 'Validation failed.'); return; }
                qbRows = resp.rows || [];
                qbType = resp.type;
                $('#qbKeyHeader').text(qbType === 'nonproject' ? 'Ticket' : 'Canvassing');
                $('#qbCountReady').text(resp.counts.READY);
                $('#qbCountError').text(resp.counts.ERROR);
                $('#qbCountSkipped').text(resp.counts.SKIPPED);
                var html = '';
                (resp.units || []).forEach(function(c) {
                    var chip = c.status === 'READY' ? 'bg-green-100 text-green-800' : (c.status === 'ERROR' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-600');
                    var label = c.status === 'READY' ? (c.is_edit ? 'Change' : 'Ready') : (c.status === 'ERROR' ? 'Error' : 'Skipped');
                    if (c.status === 'READY' && c.is_edit) chip = 'bg-amber-100 text-amber-800';
                    if (c.status === 'READY' && c.partial) { chip = 'bg-amber-100 text-amber-800'; label = c.is_edit ? 'Change, partly' : 'Partly'; }
                    var detail = c.status === 'READY'
                        ? qbEsc(c.summary) + (c.message ? '<span class="block ' + (c.is_edit ? 'text-amber-700' : 'text-gray-400') + '">' + qbEsc(c.message) + '</span>' : '') +
                            (c.problem_text ? '<span class="block text-red-700">Not imported: ' + qbEsc(c.problem_text) + '</span>' : '')
                        : qbEsc(c.message);
                    html += '<tr class="border-t border-gray-100 qb-row-' + c.status.toLowerCase() + '">' +
                        '<td class="px-3 py-1.5">' + (c.status === 'READY' ? '<input type="checkbox" class="qb-pick rounded border-gray-300" data-edit="' + (c.is_edit ? '1' : '0') + '" value="' + qbEsc(c.key) + '" checked>' : '') + '</td>' +
                        '<td class="px-3 py-1.5 font-semibold text-gray-800 whitespace-nowrap">' + qbEsc(c.key) + '</td>' +
                        '<td class="px-3 py-1.5"><span class="px-2 py-0.5 rounded ' + chip + ' font-semibold">' + label + '</span></td>' +
                        '<td class="px-3 py-1.5 text-center">' + qbEsc(c.lines) + '</td>' +
                        '<td class="px-3 py-1.5 ' + (c.status === 'ERROR' ? 'text-red-700' : (c.status === 'SKIPPED' ? 'text-gray-500' : 'text-gray-700')) + '">' + detail + '</td>' +
                    '</tr>';
                });
                $('#qbPreviewBody').html(html);
                $('#qbStepPreview').removeClass('hidden');
                $('#qbConfirmBtn').removeClass('hidden');
                if (resp.counts.READY === 0) {
                    qbShowError(resp.counts.ERROR > 0
                        ? 'Nothing can be imported yet - fix the errors in the file and validate again.'
                        : 'No prices found in the file. Fill in baseline_price first.');
                }
                qbUpdateConfirm();
            },
            error: function() { btn.prop('disabled', false).text('Validate'); qbShowError('Connection error. Please try again.'); }
        });
    });

    $('#qbCheckAll').on('change', function() { $('#qbPreviewBody .qb-pick').prop('checked', this.checked); qbUpdateConfirm(); });
    $('#qbPreviewBody').on('change', '.qb-pick', qbUpdateConfirm);

    $('#qbConfirmBtn').on('click', function() {
        var selected = $('#qbPreviewBody .qb-pick:checked').map(function() { return this.value; }).get();
        if (!selected.length) return;
        var nEdit = $('#qbPreviewBody .qb-pick:checked[data-edit="1"]').length;
        if (nEdit > 0 && !qbEditArmed) {
            qbEditArmed = true;
            $('#qbEditWarn').text(nEdit + ' of the selected units already have a saved price. Importing will CHANGE them (old and new values are logged in their history). Press the button again to confirm.').removeClass('hidden');
            $(this).text('Yes, change ' + nEdit + ' saved price' + (nEdit === 1 ? '' : 's') + ' and import');
            return;
        }
        var pick = {};
        selected.forEach(function(c) { pick[c] = true; });
        // Only the ticked units' rows go back; the server re-validates them.
        var keyCol = qbType === 'nonproject' ? 'ticket_no' : 'canvassing_no';
        var rows = qbRows.filter(function(r) { return pick[r[keyCol]]; });
        var btn = $(this).prop('disabled', true).text('Importing...');
        qbShowError('');
        $.ajax({
            url: 'ajax_quotation_bulk.php', type: 'POST', dataType: 'json',
            data: { action: 'confirm', type: qbType, rows: JSON.stringify(rows), selected: JSON.stringify(selected) },
            success: function(resp) {
                if (resp.status !== 'success') { btn.prop('disabled', false); qbUpdateConfirm(); qbShowError(resp.message || 'Import failed.'); return; }
                qbSaved = resp.saved > 0;
                $('#qbStepUpload, #qbStepPreview, #qbConfirmBtn').addClass('hidden');
                var unit = qbType === 'nonproject' ? 'ticket(s)' : 'canvassing(s)';
                // A finished import reads as success (green), never as an error:
                // everything saved = all green; anything not saved is amber and
                // listed apart, so it cannot be mistaken for the saved ones.
                var allOk = !resp.failed;
                $('#qbStepResult').removeClass('border-green-200 bg-green-50 border-amber-200 bg-amber-50')
                    .addClass(allOk ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50');
                $('#qbResultSummary').text((allOk ? '✓ ' : '') + resp.saved + ' ' + unit + ' saved' + (resp.failed ? ', ' + resp.failed + ' not saved' : ' successfully') + '.')
                    .removeClass('text-green-800 text-amber-800').addClass(allOk ? 'text-green-800' : 'text-amber-800');
                var okHtml = '', failHtml = '';
                (resp.results || []).forEach(function(r) {
                    var warn = (r.warnings || []).length ? '<span class="block text-amber-700 pl-4">' + r.warnings.map(qbEsc).join('<br>') + '</span>' : '';
                    if (r.ok) {
                        okHtml += '<li class="text-green-800">✓ <b>' + qbEsc(r.key) + '</b> &mdash; ' +
                            (qbType === 'project' ? 'price saved for the whole canvassing' : qbEsc(r.lines_saved) + ' line(s) saved') + warn + '</li>';
                    } else {
                        failHtml += '<li class="text-amber-900"><b>' + qbEsc(r.key) + '</b> &mdash; ' + qbEsc(r.message) + warn + '</li>';
                    }
                });
                var html = okHtml;
                if (failHtml) html += '<li class="pt-2 font-semibold text-amber-800">Not saved (nothing was changed for these):</li>' + failHtml;
                $('#qbResultList').html(html);
                $('#qbStepResult, #qbDoneBtn').removeClass('hidden');
            },
            error: function() { btn.prop('disabled', false); qbUpdateConfirm(); qbSaved = true; qbShowError('Connection error - some rows may already have been saved. Close this window (the page reloads) and check before importing again; already-saved ones will show as a Change.'); }
        });
    });
});
</script>
    <?php
}

}
