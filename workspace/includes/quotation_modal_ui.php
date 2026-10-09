<?php
// includes/quotation_modal_ui.php
//
// The "Initial Quotation Price" modal (markup + script), shared by
// dashboard_mgr.php, dashboard_project_head.php and dashboard_pic.php:
// qm_render_modal("#prTable") renders it once, wired to that table's
// .quotation-btn buttons. Endpoints: ajax_project_quotation_lines.php / _save.php.
//
// Two modes, picked by the button (confirmed by user, 2026-09-29):
//   .quotation-btn[data-canvassing] -> GP Project: ONE price for the whole
//       canvassing + a required taxonomy (shown with its code, e.g. "J1 ·
//       Civil-Architecture");
//   .quotation-btn[data-ticket]     -> GP Non-Project: one price per line of
//       that ticket; lines left empty stay open.
// Render it outside any <form>.

require_once __DIR__ . '/ca_ui_shared.php';

if (!function_exists('qm_render_modal')) {

function qm_render_modal(string $tableSelector): void {
    $input = 'block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm';
    ca_render_shared_ui();
    ?>
<!-- QUOTATION MODAL -->
<div id="quotationModal" class="fixed z-50 inset-0 hidden overflow-y-auto">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
    <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity backdrop-blur-sm" id="quotationOverlay"></div>
    <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
    <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-3xl sm:w-full">
        <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4 relative">
            <button type="button" id="quotationHistoryBtn" title="Edit history" aria-label="Edit history" class="hidden absolute top-3 right-3 p-1.5 rounded-full text-gray-400 hover:text-indigo-600 hover:bg-indigo-50">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </button>
            <div class="sm:flex sm:items-start">
              <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-indigo-100 sm:mx-0 sm:h-10 sm:w-10">
                <svg class="h-6 w-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h3m-3 0h-3m-2-7h.01M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2" /></svg>
              </div>
              <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                <h3 class="text-lg font-medium text-gray-900">Initial Quotation Price</h3>
                <div class="mt-2">
                  <p class="text-sm text-gray-500 mb-4"><span id="modal-q-unit-label">Canvassing</span> <strong id="modal-q-unit-display" class="text-indigo-600"></strong> <span id="modal-q-unit-sub" class="text-xs text-gray-400"></span></p>

                  <p id="modal-q-locked-note" class="hidden text-xs text-blue-700 bg-blue-50 border border-blue-200 rounded-md px-3 py-2 mb-4">This price is already saved. Updates will be recorded in the system.</p>

                  <!-- PO currency: a PO the import left as IDR can be switched to its real currency -->
                  <div id="modal-q-po-cur" class="hidden border border-gray-200 rounded-md p-3 mb-4 bg-gray-50">
                    <p class="text-xs font-semibold text-gray-600">PO currency</p>
                    <p class="text-xs text-gray-500 mb-2">If a PO was made in another currency, change it here. The PO amount stays the same, only the currency changes.</p>
                    <div id="modal-q-po-cur-rows" class="space-y-1.5"></div>
                    <p id="modal-q-po-cur-error" class="hidden text-xs text-red-600 mt-1"></p>
                  </div>

                  <!-- GP Project: one price for the whole canvassing -->
                  <div id="modal-q-canvas-fields" class="hidden">
                    <div class="gap-4 border border-gray-200 rounded-md p-4 mb-4 bg-gray-50 sm:grid sm:grid-cols-2 space-y-3 sm:space-y-0">
                      <p class="text-xs text-gray-500 sm:col-span-2">One price for the whole canvassing (all its tickets and lines).</p>
                      <div class="space-y-1">
                        <label class="filt-label">Initial Quotation Price <span class="font-normal text-gray-500">(before tax)</span> <span class="text-red-500">*</span></label>
                        <div class="flex gap-2">
                          <select id="modal-q-canvas-currency" class="w-24 flex-shrink-0 py-2 px-2 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"></select>
                          <input type="number" step="0.01" id="modal-q-canvas-price" class="flex-1 min-w-0 py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="0.00">
                        </div>
                        <p id="modal-q-canvas-price-error" class="hidden text-xs text-red-600">Initial Quotation Price is required.</p>
                        <p id="modal-q-canvas-ca-hint" class="text-xs"></p>
                        <p id="modal-q-canvas-rate-hint" class="hidden text-xs text-gray-500"></p>
                        <div id="modal-q-canvas-rate-wrap" class="hidden mt-1">
                          <label class="text-xs text-gray-600">Exchange rate (IDR per 1 <span id="modal-q-canvas-rate-cur"></span>) &mdash; today&rsquo;s rate, edit if the quotation was made at another rate</label>
                          <div class="flex items-center gap-2 mt-0.5">
                            <input type="number" step="0.0001" min="0" id="modal-q-canvas-rate" class="w-40 py-1.5 px-2 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 text-sm" placeholder="0">
                            <span id="modal-q-canvas-rate-preview" class="text-xs text-gray-500"></span>
                          </div>
                          <p id="modal-q-canvas-rate-error" class="hidden text-xs text-red-600 mt-0.5">Exchange rate must be greater than 0.</p>
                        </div>
                      </div>
                      <div class="space-y-1">
                        <label class="filt-label">Taxonomy <span class="text-red-500">*</span> <span id="modal-q-taxonomy-team" class="normal-case font-normal text-gray-400"></span></label>
                        <select id="modal-q-canvas-taxonomy" class="<?= $input ?>"></select>
                        <p id="modal-q-canvas-taxonomy-error" class="hidden text-xs text-red-600">Taxonomy is required.</p>
                      </div>
                      <div class="space-y-1">
                        <label class="filt-label">G Drive Link</label>
                        <input type="text" id="modal-q-canvas-gdrive" class="<?= $input ?>" placeholder="https://drive.google.com/...">
                      </div>
                      <div class="space-y-1">
                        <label class="filt-label">Remark</label>
                        <textarea id="modal-q-canvas-notes" rows="2" class="<?= $input ?>"></textarea>
                      </div>
                    </div>
                  </div>

                  <!-- GP Non-Project: one price per line of the ticket -->
                  <div id="modal-q-item-fields" class="space-y-4 max-h-[28rem] overflow-y-auto"></div>
                  <p class="text-xs text-gray-400 italic" id="modal-q-loading">Loading&hellip;</p>

                  <p id="modal-q-error" class="text-xs text-red-600 mt-3 hidden"></p>
                </div>
              </div>
            </div>
        </div>
        <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
            <button type="button" id="quotationSaveBtn" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-bold text-white hover:bg-indigo-700 sm:ml-3 sm:w-auto sm:text-sm">Save Changes</button>
            <button type="button" id="quotationCancelBtn" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-bold text-gray-700 hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">Cancel</button>
        </div>
    </div>
  </div>
</div>
<script>
$(function() {
    // --- QUOTATION MODAL (Initial Quotation Price) ---
    var qModal = $('#quotationModal');
    var qMode = null;          // 'project' | 'nonproject'
    var qKey = '';             // canvassing_no | ticket_no
    var qCurrencies = [];
    var qRatesByCode = {};
    var qInputClass = <?= json_encode($input) ?>;

    function qEscapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    // Thousands separators for the item price box: keeps digits and one ".",
    // groups the integer part with commas.
    function qFmtInput(raw) {
        var s = String(raw == null ? '' : raw).replace(/[^\d.]/g, '');
        var parts = s.split('.');
        if (parts.length > 2) parts = [parts[0], parts.slice(1).join('')];
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return parts.join('.');
    }
    // What the server/validation should see: no separators.
    function qRawPrice(v) {
        return String(v == null ? '' : v).replace(/,/g, '').trim();
    }

    // 10 -> "10", 2.5 -> "2.5" (qty arrives as a float).
    function qFmtQty(n) {
        return Number(n).toLocaleString('en-US', {maximumFractionDigits: 4});
    }

    function qCurrencyOptionsHtml(selectedCode) {
        var sel = selectedCode || 'IDR';
        var html = qCurrencies.map(function(c) {
            return '<option value="' + qEscapeHtml(c.code) + '"' + (c.code === sel ? ' selected' : '') + '>' + qEscapeHtml(c.code) + '</option>';
        }).join('');
        // A saved price whose currency was switched off since must not silently
        // fall back to the first option (that would re-save it in another currency).
        var known = qCurrencies.some(function(c) { return c.code === sel; });
        if (!known) html = '<option value="' + qEscapeHtml(sel) + '" selected>' + qEscapeHtml(sel) + ' (not enabled)</option>' + html;
        return html;
    }

    // Rate is a manually-configured snapshot from Cost Avoidance's Currency
    // Settings tab (currencies.rate_to_idr), not a live FX feed -- worded as
    // "Rate used" rather than implying real-time market data.
    function qFormatRate(rate) {
        var n = Number(rate);
        if (!isFinite(n)) return String(rate);
        return n.toLocaleString('id-ID', { maximumFractionDigits: n % 1 === 0 ? 0 : 2 });
    }

    function qRateHintText(code) {
        var c = code || 'IDR';
        if (c === 'IDR') return '';
        var rate = qRatesByCode[c];
        if (!rate || rate <= 0) return '';
        return 'Rate used: 1 ' + c + ' = Rp ' + qFormatRate(rate);
    }

    var qCanvasFx = null;
    function qUpdateCanvasRateHint() {
        $('#modal-q-canvas-rate-cur').text($('#modal-q-canvas-currency').val() || '');
    }
    // Every already-saved unit in this modal (for the history icon).
    var qHasSaved = false;
    function qHistoryTarget() {
        return qMode === 'project' ? { type: 'project', key: qKey } : { type: 'ticket', key: qKey };
    }

    // --- PO currency switch -------------------------------------------------
    // qPoRefs holds the PO line objects the modal already shows (po_lines /
    // line.po). A switch edits them in place, so every cost avoidance hint
    // re-reads the new currency without reloading the modal.
    var qPoRefs = [];
    var qPoCurChanged = false;   // a PO was switched: refresh the dashboard on close

    function qPoRefreshHints() {
        if (qMode === 'project') { qShowCanvasAvoidance(); return; }
        $('#modal-q-item-fields > div').each(function() {
            var f = $(this).data('refresh');
            if (typeof f === 'function') f();
        });
    }

    function qApplyPoCurrency(poNumber, cur) {
        var rate = qRatesByCode[cur] || 0;
        qPoRefs.forEach(function(l) {
            if (l.po_number !== poNumber) return;
            l.po_currency = cur;
            l.unit_ex_tax_idr = cur === 'IDR' ? l.unit_ex_tax : (rate > 0 ? Math.round(l.unit_ex_tax * rate * 10000) / 10000 : null);
        });
    }

    function qRenderPoCurrency() {
        var seen = {}, rows = '';
        qPoRefs.forEach(function(l) {
            if (seen[l.po_number]) return;
            seen[l.po_number] = true;
            rows += '<div class="flex items-center gap-2 text-xs" data-po="' + qEscapeHtml(l.po_number) + '">' +
                '<span class="text-gray-700">PO <strong>' + qEscapeHtml(l.po_number) + '</strong> is in</span>' +
                '<select class="q-po-cur w-24 py-1 px-2 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 text-xs" data-prev="' + qEscapeHtml(l.po_currency) + '">' +
                    qCurrencyOptionsHtml(l.po_currency) + '</select></div>';
        });
        $('#modal-q-po-cur-error').addClass('hidden').text('');
        $('#modal-q-po-cur-rows').html(rows);
        $('#modal-q-po-cur').toggleClass('hidden', rows === '');
    }

    $('#modal-q-po-cur-rows').on('change', '.q-po-cur', function() {
        var $sel = $(this);
        var po = String($sel.closest('[data-po]').attr('data-po'));
        var prev = String($sel.attr('data-prev'));
        var next = String($sel.val());
        $sel.val(prev);   // shown as switched only once the server confirms it
        if (next === prev) return;
        $('#modal-q-po-cur-error').addClass('hidden').text('');
        var body = '<p class="mb-2">Change the currency of PO <b>' + CAUI.esc(po) + '</b> from <b>' + CAUI.esc(prev) + '</b> to <b>' + CAUI.esc(next) + '</b>?</p>' +
            '<p class="text-xs text-gray-500">The PO amount stays the same, only its currency changes (for example, 14 becomes ' + CAUI.esc(next) + ' 14). ' +
            'This applies to every line of this PO and is recorded in the history with your name and the time.</p>';
        CAUI.confirm({ title: 'Change PO currency?', bodyHtml: body, yes: 'Yes, change it', no: 'No' }, function() {
            $sel.prop('disabled', true);
            var data = { po_number: po, po_currency: next };
            if (qMode === 'project') data.canvassing_no = qKey; else data.ticket_no = qKey;
            $.ajax({
                url: 'ajax_project_quotation_po_currency.php', type: 'POST', dataType: 'json', data: data,
                success: function(resp) {
                    $sel.prop('disabled', false);
                    if (resp.status !== 'success') {
                        $('#modal-q-po-cur-error').removeClass('hidden').text(resp.message || 'Could not change the PO currency.');
                        return;
                    }
                    $sel.val(next).attr('data-prev', next);
                    qApplyPoCurrency(po, next);
                    if (resp.changed) qPoCurChanged = true;
                    qPoRefreshHints();
                },
                error: function() {
                    $sel.prop('disabled', false);
                    $('#modal-q-po-cur-error').removeClass('hidden').text('Connection error. Please try again.');
                }
            });
        });
    });

    // Taxonomy ("type of work", Cost Avoidance Level 2) -- required on GP
    // Project. The value is the label (what Cost Avoidance stores); the code
    // is shown alongside it.
    function qTaxonomyOptionsHtml(options, selected) {
        return '<option value="">Select taxonomy</option>' + options.map(function(o) {
            var display = (o.code ? o.code + ' · ' : '') + o.label;
            return '<option value="' + qEscapeHtml(o.label) + '"' + (o.label === selected ? ' selected' : '') + '>' + qEscapeHtml(display) + '</option>';
        }).join('');
    }

    // The cost avoidance a typed price will produce on the Cost Avoidance page, as
    // the report computes it: sum over the lines that have a PO (and are not AON /
    // Intercompany) of (baseline - PO nett unit price) x qty, in Rupiah. The PO nett
    // price is the PO total without tax, per unit, so the baseline is typed before tax.
    // $poLines: [{po_number, po_currency, qty, unit_ex_tax, unit_ex_tax_idr, tax_removed, eligible}]
    // totalLines: how many lines the price applies to (1 for a Non-Project item).
    function qAvoidanceHtml(poLines, totalLines, p, cur, rate) {
        var rp = function(n) { return 'Rp ' + Math.round(n).toLocaleString('en-US'); };
        var gray = function(t) { return '<span class="text-gray-500">' + t + '</span>'; };
        poLines = poLines || [];
        if (poLines.length === 0) {
            return gray('Cost avoidance appears here once a PO is issued' + (totalLines > 1 ? ' (no PO on any of the ' + totalLines + ' lines yet)' : ' for this line') + '.');
        }
        var elig = poLines.filter(function(l) { return l.eligible; });
        if (elig.length === 0) return gray('This is an AON / Intercompany item, so it does not count toward Cost Avoidance.');
        var one = elig.length === 1 ? elig[0] : null;
        var nettOf = function(l) {
            // Same foreign currency on the PO and the baseline: the PO converts at the
            // baseline's rate (as the report does); otherwise at today's rate.
            return (cur !== 'IDR' && cur === l.po_currency && rate > 0) ? l.unit_ex_tax * rate : l.unit_ex_tax_idr;
        };
        // A PO in a foreign currency reads "nett USD 14 per unit (about Rp 251,510)", not "Rp 14".
        var nettText = function(l) {
            var idr = nettOf(l);
            if (l.po_currency === 'IDR') return idr == null ? 'n/a' : rp(idr);
            return l.po_currency + ' ' + Number(l.unit_ex_tax).toLocaleString('en-US', {maximumFractionDigits: 2});
        };
        var detail = one
            ? 'PO ' + qEscapeHtml(one.po_number) + ': nett ' + qEscapeHtml(nettText(one)) + ' per unit' + (one.tax_removed ? ' (tax removed)' : '')
            : elig.length + ' PO lines';
        if (totalLines > elig.length) detail += ', ' + (totalLines - elig.length) + ' of ' + totalLines + ' lines not counted yet (no PO, or AON)';
        if (!isFinite(p) || p <= 0) return gray(detail + '. Enter the price to see the cost avoidance.');
        if (cur !== 'IDR' && !(rate > 0)) return gray('Enter the exchange rate to see the cost avoidance.');
        var base = cur === 'IDR' ? p : p * rate, total = 0, usable = 0;
        elig.forEach(function(l) {
            var n = nettOf(l);
            if (n === null || n === undefined) return;
            usable++;
            total += (base - n) * l.qty;
        });
        if (usable === 0) return gray('The PO currency has no exchange rate yet, so the cost avoidance cannot be shown.');
        return 'Cost avoidance: <span class="font-bold ' + (total >= 0 ? 'text-green-700' : 'text-red-600') + '">' + (total < 0 ? '-' : '') + rp(Math.abs(total)) + '</span> ' + gray('(' + detail + ')');
    }

    function qRenderItemFields(lines) {
        var $container = $('#modal-q-item-fields').empty();
        lines.forEach(function(line) {
            var qty = (line.qty === null || line.qty === undefined || !isFinite(Number(line.qty))) ? null : Number(line.qty);
            var priceVal = (line.last_price === null || line.last_price === undefined) ? '' : line.last_price;
            // Stored as DECIMAL ("100000.0000"): drop the trailing zeros before display.
            if (priceVal !== '' && String(priceVal).indexOf('.') !== -1) priceVal = String(priceVal).replace(/\.?0+$/, '');
            var $row = $(
                '<div class="border border-gray-200 rounded-md p-4 space-y-3 text-left" data-line="' + qEscapeHtml(line.ticket_line_item) + '">' +
                    '<p class="text-xs font-semibold text-gray-600">Line ' + qEscapeHtml(line.ticket_line_item) + ' &middot; <span class="font-normal text-gray-500">' + qEscapeHtml(line.description) + '</span></p>' +
                    (qty !== null ? '<p class="text-xs font-bold text-gray-800">Qty ' + qEscapeHtml(qFmtQty(qty)) + (line.uom ? ' ' + qEscapeHtml(line.uom) : '') + '</p>' : '') +
                    '<div class="space-y-1">' +
                        '<label class="filt-label">Initial Quotation Unit Price <span class="font-normal text-gray-500">(per 1 unit, before tax)</span></label>' +
                        '<div class="flex gap-2">' +
                            '<select class="q-item-currency w-24 flex-shrink-0 py-2 px-2 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">' + qCurrencyOptionsHtml(line.currency) + '</select>' +
                            '<input type="text" inputmode="decimal" autocomplete="off" class="q-item-price flex-1 min-w-0 py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="0.00" value="' + qEscapeHtml(qFmtInput(priceVal)) + '">' +
                        '</div>' +
                        '<p class="q-item-price-error hidden text-xs text-red-600">Initial Quotation Price is required.</p>' +
                        (qty !== null ? '<p class="q-item-total-hint text-xs text-gray-500"></p>' : '') +
                        '<p class="q-item-ca-hint text-xs"></p>' +
                        '<div class="q-item-rate-wrap hidden mt-1">' +
                            '<label class="text-xs text-gray-600">Exchange rate (IDR per 1 <span class="q-item-rate-cur"></span>)</label>' +
                            '<div class="flex items-center gap-2 mt-0.5">' +
                                '<input type="number" step="0.0001" min="0" class="q-item-rate w-40 py-1.5 px-2 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 text-sm" placeholder="0">' +
                                '<span class="q-item-rate-preview text-xs text-gray-500"></span>' +
                            '</div>' +
                            '<p class="q-item-rate-error hidden text-xs text-red-600 mt-0.5">Exchange rate must be greater than 0.</p>' +
                        '</div>' +
                    '</div>' +
                    '<div class="space-y-1">' +
                        '<label class="filt-label">G Drive Link</label>' +
                        '<input type="text" class="q-item-gdrive ' + qInputClass + '" placeholder="https://drive.google.com/..." value="' + qEscapeHtml(line.gdrive_link) + '">' +
                    '</div>' +
                    '<div class="space-y-1">' +
                        '<label class="filt-label">Remark</label>' +
                        '<textarea class="q-item-notes ' + qInputClass + '" rows="2">' + qEscapeHtml(line.notes) + '</textarea>' +
                    '</div>' +
                '</div>'
            );
            // A line priced earlier can be edited (confirmed on save, logged).
            var saved = line.last_price !== null && line.last_price !== undefined;
            $row.data('saved', saved);
            if (saved) {
                qHasSaved = true;
                $row.find('p').first().append(' <span class="ml-1 text-[9px] text-blue-600 font-bold uppercase tracking-wider">Saved</span>');
            }
            $container.append($row);
            // Instant, editable exchange rate for this line.
            var fx = CAUI.bindFx({
                $currency: $row.find('.q-item-currency'), $price: $row.find('.q-item-price'),
                $rate: $row.find('.q-item-rate'), $wrap: $row.find('.q-item-rate-wrap'), $preview: $row.find('.q-item-rate-preview')
            }, qRatesByCode);
            $row.data('fx', fx);
            $row.find('.q-item-currency').on('change', function() { $row.find('.q-item-rate-cur').text($(this).val()); });
            $row.find('.q-item-rate-cur').text(line.currency || 'IDR');
            fx.setRate(saved ? line.fx_rate : null);
            // "x qty = total" echo, so a lump total typed by mistake is obvious.
            var $hint = $row.find('.q-item-total-hint');
            var $ca = $row.find('.q-item-ca-hint');
            var po = line.po || null;
            var showAvoidance = function() {
                var p = parseFloat(qRawPrice($row.find('.q-item-price').val()));
                var cur = $row.find('.q-item-currency').val() || 'IDR';
                var rate = null;
                if (cur !== 'IDR') { var r = $row.data('fx') ? $row.data('fx').value() : null; rate = r ? parseFloat(r) : null; }
                $ca.html(qAvoidanceHtml(po ? [po] : [], 1, p, cur, rate));
            };
            var showTotal = function() {
                showAvoidance();
                if (qty === null) return;
                var p = parseFloat(qRawPrice($row.find('.q-item-price').val()));
                if (!isFinite(p) || p <= 0) { $hint.text(''); return; }
                $hint.html('× ' + qEscapeHtml(qFmtQty(qty)) + ' = <span class="font-bold text-gray-800">' + qEscapeHtml($row.find('.q-item-currency').val()) + ' ' + qEscapeHtml((p * qty).toLocaleString('en-US', {maximumFractionDigits: 2})) + ' total</span>');
            };
            $row.data('refresh', showTotal);
            $row.find('.q-item-currency').on('change', showTotal);
            $row.find('.q-item-rate').on('input', showTotal);
            // Live thousands separators, keeping the caret next to the same digit.
            $row.find('.q-item-price').on('input', function() {
                var el = this, pos = el.selectionStart;
                var digitsBefore = el.value.slice(0, pos).replace(/[^\d.]/g, '').length;
                el.value = qFmtInput(el.value);
                var n = 0, np = digitsBefore === 0 ? 0 : el.value.length;
                for (var i = 0; digitsBefore > 0 && i < el.value.length; i++) {
                    if (/[\d.]/.test(el.value[i])) n++;
                    if (n === digitsBefore) { np = i + 1; break; }
                }
                try { el.setSelectionRange(np, np); } catch (e) {}
                showTotal();
            });
            showTotal();
        });
    }

    $('#modal-q-canvas-currency').on('change', qUpdateCanvasRateHint);

    // Project: one price for the whole canvassing, mirrored onto every line, so the
    // preview adds up (price - PO nett) x qty over the lines that already have a PO.
    var qCanvasPoLines = [], qCanvasLineCount = 0;
    function qShowCanvasAvoidance() {
        var p = parseFloat(qRawPrice($('#modal-q-canvas-price').val()));
        var cur = $('#modal-q-canvas-currency').val() || 'IDR';
        var rate = null;
        if (cur !== 'IDR') { var r = qCanvasFx ? qCanvasFx.value() : null; rate = r ? parseFloat(r) : null; }
        $('#modal-q-canvas-ca-hint').html(qAvoidanceHtml(qCanvasPoLines, qCanvasLineCount, p, cur, rate));
    }
    // bindFx re-binds its own currency handler (which sets today's rate) every time the
    // modal opens, i.e. after this one, so run on the next tick to read the updated rate.
    $('#modal-q-canvas-currency').on('change', function() { setTimeout(qShowCanvasAvoidance, 0); });
    $('#modal-q-canvas-price, #modal-q-canvas-rate').on('input', qShowCanvasAvoidance);

    $('#quotationHistoryBtn').on('click', function() {
        var t = qHistoryTarget();
        CAUI.history(t.type, t.key, (qMode === 'project' ? 'Canvassing ' : 'Ticket ') + qKey);
    });

    $(<?= json_encode($tableSelector . ' tbody') ?>).on('click', '.quotation-btn', function() {
        var canv = String($(this).attr('data-canvassing') || '');
        var ticket = String($(this).attr('data-ticket') || '');
        qMode = canv !== '' ? 'project' : 'nonproject';
        qKey = canv !== '' ? canv : ticket;

        $('#modal-q-unit-label').text(qMode === 'project' ? 'Canvassing' : 'Ticket');
        $('#modal-q-unit-display').text(qKey);
        $('#modal-q-unit-sub').text('');
        $('#modal-q-error').addClass('hidden').text('');
        $('#modal-q-locked-note').addClass('hidden');
        $('#quotationHistoryBtn').addClass('hidden');
        qHasSaved = false;
        qPoRefs = [];
        qPoCurChanged = false;
        $('#modal-q-po-cur').addClass('hidden');
        $('#modal-q-item-fields').empty().addClass('hidden');
        $('#modal-q-canvas-fields').addClass('hidden');
        $('#modal-q-canvas-fields').find('input, select, textarea').prop('disabled', false);
        $('#modal-q-canvas-price-error, #modal-q-canvas-taxonomy-error, #modal-q-canvas-rate-error').addClass('hidden');
        $('#modal-q-loading').removeClass('hidden');
        $('#quotationSaveBtn').prop('disabled', false).text('Save Changes').removeClass('opacity-50 cursor-not-allowed');
        qModal.removeClass('hidden');

        $.ajax({
            url: 'ajax_project_quotation_lines.php',
            type: 'POST',
            dataType: 'json',
            data: qMode === 'project' ? { canvassing_no: qKey } : { ticket_no: qKey },
            success: function(resp) {
                $('#modal-q-loading').addClass('hidden');
                if (resp.status !== 'success') {
                    $('#modal-q-error').removeClass('hidden').text(resp.message || 'Could not load quotation data.');
                    $('#quotationSaveBtn').prop('disabled', true).addClass('opacity-50 cursor-not-allowed');
                    return;
                }
                qCurrencies = resp.currencies || [];
                qRatesByCode = {};
                qCurrencies.forEach(function(c) { qRatesByCode[c.code] = Number(c.rate_to_idr) || 0; });

                if (resp.mode === 'project') {
                    var tickets = resp.tickets || [];
                    qUpdateCanvasRateHint();
                    $('#modal-q-unit-sub').text('· ' + (tickets.length > 1 ? tickets.length + ' tickets' : 'ticket ' + (tickets[0] || '')) + ', ' + resp.line_count + ' line' + (resp.line_count === 1 ? '' : 's'));
                    var c = resp.canvas || {};
                    $('#modal-q-canvas-currency').html(qCurrencyOptionsHtml(c.currency || 'IDR'));
                    $('#modal-q-canvas-price').val(c.last_price == null ? '' : c.last_price);
                    // qRatesByCode is reassigned per load; bindFx replaces its own handlers.
                    qCanvasFx = CAUI.bindFx({
                        $currency: $('#modal-q-canvas-currency'), $price: $('#modal-q-canvas-price'),
                        $rate: $('#modal-q-canvas-rate'), $wrap: $('#modal-q-canvas-rate-wrap'), $preview: $('#modal-q-canvas-rate-preview')
                    }, qRatesByCode);
                    qCanvasFx.setRate(c.last_price == null ? null : c.fx_rate);
                    qCanvasPoLines = resp.po_lines || [];
                    qPoRefs = qCanvasPoLines;
                    qRenderPoCurrency();
                    qCanvasLineCount = Number(resp.line_count) || qCanvasPoLines.length;
                    qShowCanvasAvoidance();
                    $('#modal-q-canvas-gdrive').val(c.gdrive_link || '');
                    $('#modal-q-canvas-notes').val(c.notes || '');
                    $('#modal-q-canvas-taxonomy').html(qTaxonomyOptionsHtml(resp.taxonomy_options || [], resp.taxonomy || ''));
                    $('#modal-q-taxonomy-team').text(resp.taxonomy_team ? '(' + resp.taxonomy_team + ')' : '');
                    qUpdateCanvasRateHint();
                    $('#modal-q-canvas-fields').removeClass('hidden');
                } else {
                    var lines = resp.lines || [];
                    $('#modal-q-unit-sub').text('· ' + lines.length + ' line' + (lines.length === 1 ? '' : 's'));
                    qPoRefs = lines.map(function(l) { return l.po; }).filter(Boolean);
                    qRenderPoCurrency();
                    qRenderItemFields(lines);
                    $('#modal-q-item-fields').removeClass('hidden');
                }

                // A saved price stays editable: show the note + the history icon.
                // (Saving a change asks for confirmation and is logged.)
                if (resp.finalized || qHasSaved || (resp.canvas && resp.canvas.last_price != null)) {
                    $('#modal-q-locked-note').removeClass('hidden');
                    $('#quotationHistoryBtn').removeClass('hidden');
                }
            },
            error: function() {
                $('#modal-q-loading').addClass('hidden');
                $('#modal-q-error').removeClass('hidden').text('Connection error. Please try again.');
            }
        });
    });

    // A PO currency switch is saved as soon as it is confirmed, so closing the
    // modal still refreshes the dashboard (its PO Currency Code column).
    $('#quotationCancelBtn, #quotationOverlay').on('click', function() {
        qModal.addClass('hidden');
        if (qPoCurChanged) { qPoCurChanged = false; qAfterSave(); }
    });

    // After a save: a page can refresh just its table by defining
    // window.qmOnSaved (dashboard_mgr.php's server-side DataTable); otherwise
    // the page reloads.
    function qAfterSave() {
        qModal.addClass('hidden');
        if (typeof window.qmOnSaved === 'function') { window.qmOnSaved(); } else { location.reload(); }
    }

    $('#quotationSaveBtn').on('click', function() {
        var btn = $(this);
        if (btn.prop('disabled')) return;

        // Price (and, on Project, taxonomy) is required; link and remark stay
        // optional. Inline red errors only -- never a JS alert().
        $('#modal-q-error').addClass('hidden').text('');
        $('.q-item-price-error, .q-item-rate-error, #modal-q-canvas-price-error, #modal-q-canvas-taxonomy-error, #modal-q-canvas-rate-error').addClass('hidden');
        $('.q-item-price, .q-item-rate, #modal-q-canvas-price, #modal-q-canvas-rate, #modal-q-canvas-taxonomy').removeClass('border-red-400').addClass('border-gray-300');

        var hasError = false;
        function qMarkInvalid($input, $errorEl) {
            $input.removeClass('border-gray-300').addClass('border-red-400');
            $errorEl.removeClass('hidden');
            hasError = true;
        }
        function qPriceInvalid(val) {
            return val === '' || val === null || isNaN(val) || parseFloat(val) <= 0;
        }

        var payload;
        if (qMode === 'project') {
            if (qPriceInvalid($('#modal-q-canvas-price').val())) qMarkInvalid($('#modal-q-canvas-price'), $('#modal-q-canvas-price-error'));
            if (!$('#modal-q-canvas-taxonomy').val()) qMarkInvalid($('#modal-q-canvas-taxonomy'), $('#modal-q-canvas-taxonomy-error'));
            var canvasRate = qCanvasFx ? qCanvasFx.value() : '';
            if (canvasRate === null) qMarkInvalid($('#modal-q-canvas-rate'), $('#modal-q-canvas-rate-error'));
            payload = {
                canvassing_no: qKey,
                currency: $('#modal-q-canvas-currency').val(),
                last_price: $('#modal-q-canvas-price').val(),
                fx_rate: canvasRate === null ? '' : canvasRate,
                taxonomy: $('#modal-q-canvas-taxonomy').val(),
                gdrive_link: $('#modal-q-canvas-gdrive').val(),
                notes: $('#modal-q-canvas-notes').val()
            };
        } else {
            var lines = [];
            $('#modal-q-item-fields > div').each(function() {
                var $row = $(this);
                var priceTxt = qRawPrice($row.find('.q-item-price').val());
                if (priceTxt === '') {
                    // Left empty = the line stays open. Only a line that already has a
                    // price, or has a link/remark but no price, is a mistake.
                    var other = String($row.find('.q-item-gdrive').val() || '').trim() !== '' || String($row.find('.q-item-notes').val() || '').trim() !== '';
                    if ($row.data('saved') || other) qMarkInvalid($row.find('.q-item-price'), $row.find('.q-item-price-error'));
                    return;
                }
                if (qPriceInvalid(priceTxt)) qMarkInvalid($row.find('.q-item-price'), $row.find('.q-item-price-error'));
                var lineRate = $row.data('fx') ? $row.data('fx').value() : '';
                if (lineRate === null) qMarkInvalid($row.find('.q-item-rate'), $row.find('.q-item-rate-error'));
                lines.push({
                    ticket_line_item: String($row.attr('data-line')),
                    currency: $row.find('.q-item-currency').val(),
                    last_price: qRawPrice($row.find('.q-item-price').val()),
                    fx_rate: lineRate === null ? '' : lineRate,
                    gdrive_link: $row.find('.q-item-gdrive').val(),
                    notes: $row.find('.q-item-notes').val()
                });
            });
            payload = { ticket_no: qKey, lines: JSON.stringify(lines) };
            if (!hasError && lines.length === 0) {
                $('#modal-q-error').removeClass('hidden').text('Fill in the price of at least one line. Lines left empty stay open.');
                return;
            }
        }

        if (hasError) {
            $('#modal-q-error').removeClass('hidden').text('Please fill in the required field(s) highlighted below.');
            return;
        }

        function qSend(extra) {
        btn.prop('disabled', true).text('Saving...');
        $.ajax({
            url: 'ajax_project_quotation_save.php',
            type: 'POST',
            dataType: 'json',
            data: $.extend({}, payload, extra || {}),
            success: function(resp) {
                if (resp.status === 'confirm') {
                    // Editing a saved price: ask first, showing old -> new.
                    btn.prop('disabled', false).text('Save Changes');
                    var body = '<p class="mb-2">This price is already saved. Do you want to change it?</p><ul class="space-y-1 text-xs">';
                    (resp.changes || []).forEach(function(c) {
                        body += '<li class="bg-gray-50 border border-gray-200 rounded px-2 py-1">' +
                            (c.line ? '<b>Line ' + CAUI.esc(c.line) + ':</b> ' : '') +
                            CAUI.esc(CAUI.fmtSnap(c.old)) + ' &rarr; <b>' + CAUI.esc(CAUI.fmtSnap(c.new)) + '</b></li>';
                    });
                    body += '</ul><p class="mt-2 text-xs text-gray-500">The change is recorded in the history with your name and the time.</p>';
                    CAUI.confirm({ title: 'Change saved price?', bodyHtml: body, yes: 'Yes, change it', no: 'No' }, function() { qSend({ confirm_edit: 1 }); });
                    return;
                }
                if (resp.status === 'success') {
                    // A taxonomy/baseline warning (AON item) doesn't undo the price
                    // already committed -- but location.reload() would wipe the
                    // message, so show it inline instead; they can Cancel out.
                    var taxWarnings = resp.taxonomy_warnings || {};
                    var baseWarnings = resp.baseline_warnings || {};
                    var warnLines = Object.keys(Object.assign({}, taxWarnings, baseWarnings));
                    if (warnLines.length) {
                        btn.text('Saved');
                        $('#modal-q-error').removeClass('hidden').text('Initial Quotation Price was saved. ' +
                            warnLines.map(function(li) {
                                var reasons = [];
                                if (taxWarnings[li]) reasons.push('taxonomy: ' + taxWarnings[li]);
                                if (baseWarnings[li]) reasons.push('cost avoidance: ' + baseWarnings[li]);
                                return li + ' (' + reasons.join('; ') + ')';
                            }).join('; '));
                        $('#quotationCancelBtn, #quotationOverlay').one('click', qAfterSave);
                    } else {
                        qAfterSave();
                    }
                } else {
                    $('#modal-q-error').removeClass('hidden').text(resp.message || 'Save failed.');
                    btn.prop('disabled', false).text('Save Changes');
                }
            },
            error: function() {
                $('#modal-q-error').removeClass('hidden').text('Connection error. Please try again.');
                btn.prop('disabled', false).text('Save Changes');
            }
        });
        }
        qSend();
    });
});
</script>
    <?php
}

}
