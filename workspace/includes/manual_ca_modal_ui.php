<?php
// includes/manual_ca_modal_ui.php
//
// "Manual Input" modal for the dashboards (dashboard_mgr.php,
// dashboard_project_head.php, dashboard_pic.php): the Cost Avoidance page's
// Manual Input, launched from the "Initial Quotation Price" dropdown
// (includes/quotation_bulk_ui.php -> #mcaOpen). Same functionality as the
// Manual tab -- date, team, vendor, item, qty, UoM, currency, initial price,
// final price, auto total, remarks, link -- plus the editable exchange rate.
// Create-only here: saved entries are edited (with confirmation + history) on
// the Cost Avoidance page's Manual tab. Endpoint: pages/ajax_manual_ca_save.php;
// rules: includes/manual_ca_helper.php.
// Render it once per page, outside any <form>.

require_once __DIR__ . '/ca_ui_shared.php';

if (!function_exists('mca_render_modal')) {

function mca_render_modal(): void {
    ca_render_shared_ui();
    $in = 'block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm';
    $lb = 'text-[10px] font-bold text-gray-500 uppercase mb-1';
    ?>
<!-- MANUAL COST AVOIDANCE MODAL -->
<div id="mcaModal" class="fixed z-50 inset-0 hidden overflow-y-auto">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
    <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity backdrop-blur-sm" id="mcaOverlay"></div>
    <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
    <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full">
      <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
        <h3 class="text-lg font-medium text-gray-900" id="mcaTitle">Manual Input &mdash; Cost Avoidance</h3>
        <p class="text-xs text-gray-500 mb-3" id="mcaSub">Record a Cost Avoidance case that has no underlying Purchase Order.</p>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div class="flex flex-col"><label class="<?= $lb ?>">Transaction Date *</label><input type="date" id="mca-date" class="<?= $in ?>"></div>
          <div class="flex flex-col"><label class="<?= $lb ?>">Team *</label><select id="mca-team" class="<?= $in ?>"></select></div>
          <div class="flex flex-col"><label class="<?= $lb ?>">Vendor Name *</label>
            <input type="text" id="mca-vendor" list="mca-vendor-list" autocomplete="off" placeholder="Type to search vendor..." class="<?= $in ?>">
            <datalist id="mca-vendor-list"></datalist>
          </div>
          <div class="flex flex-col sm:col-span-3"><label class="<?= $lb ?>">Item Description *</label><input type="text" id="mca-item" class="<?= $in ?>"></div>
          <div class="flex flex-col"><label class="<?= $lb ?>">Quantity *</label><input type="number" step="0.01" min="0" id="mca-qty" value="1" class="<?= $in ?>"></div>
          <div class="flex flex-col"><label class="<?= $lb ?>">UoM *</label><select id="mca-uom" class="<?= $in ?>"></select></div>
          <div class="flex flex-col"><label class="<?= $lb ?>">Currency *</label><select id="mca-currency" class="<?= $in ?>"></select></div>
          <div class="flex flex-col"><label class="<?= $lb ?>">Initial Price (per unit) *</label><input type="number" step="0.01" min="0" id="mca-before" class="<?= $in ?>" placeholder="0.00"></div>
          <div class="flex flex-col"><label class="<?= $lb ?>">Final Price (per unit) *</label><input type="number" step="0.01" min="0" id="mca-avoided" class="<?= $in ?>" placeholder="0.00"></div>
          <div class="flex flex-col"><label class="<?= $lb ?>">Total Cost Avoidance</label><input type="text" id="mca-total" readonly class="<?= $in ?> bg-gray-100 font-bold cursor-not-allowed"></div>
          <div id="mca-rate-wrap" class="hidden flex-col sm:col-span-3 border border-amber-200 bg-amber-50/60 rounded-md px-3 py-2">
            <label class="text-xs text-gray-700">Exchange rate (IDR per 1 <b id="mca-rate-cur"></b>) &mdash; today&rsquo;s rate is filled in; edit it if these prices were quoted at another rate. The rate you enter is saved with the entry.</label>
            <div class="flex items-center gap-2 mt-1">
              <input type="number" step="0.0001" min="0" id="mca-rate" class="w-44 py-1.5 px-2 border border-gray-300 bg-white rounded-md shadow-sm text-sm" placeholder="0">
              <span id="mca-rate-preview" class="text-xs text-gray-600"></span>
            </div>
          </div>
          <div class="flex flex-col sm:col-span-3"><label class="<?= $lb ?>">Remarks / Justification</label><input type="text" id="mca-remarks" class="<?= $in ?>"></div>
          <div class="flex flex-col sm:col-span-3"><label class="<?= $lb ?>">Quotation / Drive Link</label><input type="text" id="mca-link" class="<?= $in ?>" placeholder="G-Drive Link (Optional)"></div>
        </div>
        <p id="mcaError" class="hidden text-xs text-red-600 mt-3"></p>
        <p id="mcaOk" class="hidden text-xs text-green-700 mt-3"></p>

      </div>
      <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse gap-2">
        <button type="button" id="mcaSaveBtn" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-bold text-white hover:bg-indigo-700 sm:w-auto sm:text-sm">Save Entry</button>
        <button type="button" id="mcaCloseBtn" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-bold text-gray-700 hover:bg-gray-50 sm:mt-0 sm:w-auto sm:text-sm">Close</button>
      </div>
    </div>
  </div>
</div>
<script>
$(function() {
    var $m = $('#mcaModal');
    var mcaRates = {};           // code -> today's rate
    var mcaFx = null;

    function esc(s) { return CAUI.esc(s); }
    function showErr(msg) { $('#mcaError').text(msg || '').toggleClass('hidden', !msg); if (msg) $('#mcaOk').addClass('hidden'); }
    function showOk(msg) { $('#mcaOk').text(msg || '').toggleClass('hidden', !msg); if (msg) $('#mcaError').addClass('hidden'); }

    function recalcTotal() {
        var b = parseFloat($('#mca-before').val()), a = parseFloat($('#mca-avoided').val()), q = parseFloat($('#mca-qty').val());
        if (!isFinite(b) || !isFinite(a) || !isFinite(q)) { $('#mca-total').val(''); return; }
        var t = (b - a) * q;
        $('#mca-total').val(($('#mca-currency').val() || 'IDR') + ' ' + t.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    }
    $('#mca-before, #mca-avoided, #mca-qty').on('input', recalcTotal);
    $('#mca-currency').on('change', function() { $('#mca-rate-cur').text($(this).val()); recalcTotal(); });

    function resetForm() {
        $('#mca-date').val(new Date().toISOString().slice(0, 10));
        $('#mca-vendor, #mca-item, #mca-before, #mca-avoided, #mca-remarks, #mca-link, #mca-total').val('');
        $('#mca-qty').val('1');
        $('#mca-uom').val('');
        $('#mca-currency').val('IDR');
        $('#mca-rate-cur').text('IDR');
        if (mcaFx) mcaFx.setRate(null);
        showErr('');
    }

    function loadInit(keepForm) {
        $.ajax({
            url: 'ajax_manual_ca_save.php', type: 'POST', dataType: 'json', data: { action: 'init' },
            success: function(r) {
                if (r.status !== 'success') { showErr(r.message || 'Could not load Manual Input.'); return; }
                if (keepForm) return;
                var teamHtml = '';
                Object.keys(r.teams || {}).forEach(function(code) {
                    teamHtml += '<option value="' + esc(r.teams[code]) + '">' + esc(code) + '</option>';
                });
                $('#mca-team').html(teamHtml).val(r.default_team || Object.values(r.teams || {})[0] || '');
                $('#mca-uom').html('<option value="">--</option>' + (r.uoms || []).map(function(u) { return '<option>' + esc(u) + '</option>'; }).join(''));
                mcaRates = {};
                $('#mca-currency').html((r.currencies || []).map(function(c) {
                    mcaRates[c.code] = Number(c.rate_to_idr) || 0;
                    return '<option value="' + esc(c.code) + '">' + esc(c.code) + '</option>';
                }).join(''));
                // Instant, editable exchange rate (same widget as the price modal).
                mcaFx = CAUI.bindFx({
                    $currency: $('#mca-currency'), $price: $('#mca-before'),
                    $rate: $('#mca-rate'), $wrap: $('#mca-rate-wrap'), $preview: $('#mca-rate-preview')
                }, mcaRates);
                resetForm();
            },
            error: function() { showErr('Connection error. Please try again.'); }
        });
    }

    $('#mcaOpen').on('click', function() {
        $('#qbDropdownMenu').addClass('hidden');
        $('#qbDropdownChevron').removeClass('rotate-180');
        showErr(''); showOk('');
        $m.removeClass('hidden');
        loadInit(false);
    });
    $('#mcaCloseBtn, #mcaOverlay').on('click', function() { $m.addClass('hidden'); });
    function payload() {
        var rate = mcaFx ? mcaFx.value() : '';
        return {
            action: 'save',
            m_entry_date: $('#mca-date').val(), m_team: $('#mca-team').val(), m_vendor_name: $('#mca-vendor').val(),
            m_item_description: $('#mca-item').val(), m_quantity: $('#mca-qty').val(), m_uom: $('#mca-uom').val(),
            m_currency: $('#mca-currency').val(), m_fx_rate: rate === null ? '' : rate,
            m_unit_price_before: $('#mca-before').val(), m_avoided_price: $('#mca-avoided').val(),
            m_remarks: $('#mca-remarks').val(), m_quotation_link: $('#mca-link').val()
        };
    }

    $('#mcaSaveBtn').on('click', function() {
        var btn = $(this);
        if (btn.prop('disabled')) return;
        showErr(''); showOk('');
        var p = payload();
        if (!p.m_entry_date || !p.m_team || !$.trim(p.m_vendor_name) || !$.trim(p.m_item_description) || !p.m_uom ||
            p.m_unit_price_before === '' || p.m_avoided_price === '') {
            showErr('Please fill in all fields marked * (date, team, vendor, item, UoM, initial and final price).'); return;
        }
        if (mcaFx && mcaFx.value() === null) { showErr('Exchange rate must be greater than 0.'); return; }
        var label = btn.text();
        function send(extra) {
            btn.prop('disabled', true).text('Saving...');
            $.ajax({
                url: 'ajax_manual_ca_save.php', type: 'POST', dataType: 'json', data: $.extend({}, p, extra || {}),
                success: function(r) {
                    btn.prop('disabled', false).text(label);
                    if (r.status === 'confirm') {
                        var body = '<p class="mb-2">This entry is already saved. Do you want to change it?</p><ul class="space-y-1 text-xs">';
                        (r.changes || []).forEach(function(c) {
                            body += '<li class="bg-gray-50 border border-gray-200 rounded px-2 py-1">Initial price: ' + esc(CAUI.fmtSnap(c.old)) + ' &rarr; <b>' + esc(CAUI.fmtSnap(c.new)) + '</b>' +
                                (c.note ? '<span class="block text-gray-500">' + esc(c.note) + '</span>' : '') + '</li>';
                        });
                        body += '</ul><p class="mt-2 text-xs text-gray-500">The change is recorded in the history with your name and the time.</p>';
                        CAUI.confirm({ title: 'Change saved entry?', bodyHtml: body, yes: 'Yes, change it', no: 'No' }, function() { send({ confirm_edit: 1 }); });
                        return;
                    }
                    if (r.status !== 'success') { showErr(r.message || 'Save failed.'); return; }
                    showOk('Entry saved. It now appears on the Cost Avoidance page.');
                    resetForm();
                },
                error: function() { btn.prop('disabled', false).text(label); showErr('Connection error. Please try again.'); }
            });
        }
        send();
    });

    // Vendor typeahead (free text stays allowed: a vendor may not be registered yet).
    var vendorTimer = null;
    $('#mca-vendor').on('input', function() {
        var q = $.trim($(this).val());
        clearTimeout(vendorTimer);
        if (q.length < 2) return;
        vendorTimer = setTimeout(function() {
            $.getJSON('ajax_search_vendors.php', { name: q, page: 1 }, function(r) {
                if (!r || r.status !== 'success') return;
                $('#mca-vendor-list').html((r.data || []).map(function(v) { return '<option value="' + esc(v.company_name) + '">'; }).join(''));
            });
        }, 300);
    });
});
</script>
    <?php
}

}
