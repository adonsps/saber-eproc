<?php
// includes/ca_ui_shared.php
//
// Small shared front-end kit for everything on the dashboards that enters or
// edits a Cost Avoidance price (the Initial Quotation Price modal, the bulk
// import's edit warning, and Manual Input), so they behave identically:
//
//   CAUI.fmtMoney(price, currency, rate)  "USD 1,250.00 @ 16,250 (= Rp 20,312,500)"
//   CAUI.bindFx(cfg, ratesByCode)         instant, EDITABLE exchange-rate field:
//                                         picking a currency prefills today's
//                                         rate (currencies.rate_to_idr); the
//                                         user may overwrite it -- the rate
//                                         used is stored with the price.
//   CAUI.confirm({title, bodyHtml, yes, no}, onYes)   yes/no modal
//   CAUI.history(type, key, title)        "who changed what, when" modal with
//                                         an X that returns to the page under it
//                                         (endpoint: ajax_ca_edit_history.php).
//
// ca_render_shared_ui() emits the markup + script once per page.

if (!function_exists('ca_render_shared_ui')) {

function ca_render_shared_ui(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
<!-- CA SHARED: confirm + history modals -->
<div id="caConfirmModal" class="fixed z-[70] inset-0 hidden overflow-y-auto">
  <div class="flex items-center justify-center min-h-screen px-4">
    <div class="fixed inset-0 bg-gray-600 bg-opacity-75" id="caConfirmOverlay"></div>
    <div class="relative bg-white rounded-lg shadow-xl w-full max-w-lg">
      <div class="px-5 pt-5 pb-3">
        <h3 class="text-lg font-medium text-gray-900" id="caConfirmTitle">Are you sure?</h3>
        <div class="mt-2 text-sm text-gray-600" id="caConfirmBody"></div>
      </div>
      <div class="bg-gray-50 px-5 py-3 flex flex-row-reverse gap-2 rounded-b-lg">
        <button type="button" id="caConfirmYes" class="inline-flex justify-center rounded-md px-4 py-2 bg-indigo-600 text-sm font-bold text-white hover:bg-indigo-700">Yes</button>
        <button type="button" id="caConfirmNo" class="inline-flex justify-center rounded-md border border-gray-300 px-4 py-2 bg-white text-sm font-bold text-gray-700 hover:bg-gray-50">No</button>
      </div>
    </div>
  </div>
</div>

<div id="caHistoryModal" class="fixed z-[60] inset-0 hidden overflow-y-auto">
  <div class="flex items-center justify-center min-h-screen px-4">
    <div class="fixed inset-0 bg-gray-600 bg-opacity-75" id="caHistoryOverlay"></div>
    <div class="relative bg-white rounded-lg shadow-xl w-full max-w-3xl">
      <div class="flex items-start justify-between px-5 pt-4 pb-2 border-b border-gray-100">
        <div>
          <h3 class="text-lg font-medium text-gray-900">Edit history</h3>
          <p class="text-xs text-gray-500" id="caHistorySub"></p>
        </div>
        <button type="button" id="caHistoryClose" aria-label="Close history" class="text-gray-400 hover:text-gray-700 p-1 -mr-1">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="px-5 py-3 max-h-[60vh] overflow-y-auto">
        <p id="caHistoryLoading" class="text-xs text-gray-400 italic">Loading&hellip;</p>
        <p id="caHistoryEmpty" class="hidden text-sm text-gray-500">No changes recorded yet.</p>
        <p id="caHistoryError" class="hidden text-sm text-red-600"></p>
        <table id="caHistoryTable" class="hidden min-w-full text-xs">
          <thead><tr class="text-left text-gray-500 uppercase tracking-wider">
            <th class="py-1 pr-3">When</th><th class="py-1 pr-3">Who</th><th class="py-1 pr-3">What</th><th class="py-1">Price change</th>
          </tr></thead>
          <tbody id="caHistoryBody"></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
window.CAUI = (function($) {
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }
    function num(n, dp) {
        var v = Number(n);
        if (!isFinite(v)) return String(n);
        return v.toLocaleString('en-US', { minimumFractionDigits: dp, maximumFractionDigits: dp });
    }
    function rateTxt(r) {
        var v = Number(r);
        if (!isFinite(v)) return String(r);
        return v.toLocaleString('en-US', { maximumFractionDigits: 4 });
    }
    // snapshot {price, currency, fx_rate} -> text
    function fmtMoney(price, currency, rate) {
        if (price === null || price === undefined || price === '') return '-';
        var cur = currency || 'IDR';
        var t = cur + ' ' + num(price, 2);
        if (cur !== 'IDR' && Number(rate) > 0) {
            t += ' @ ' + rateTxt(rate) + ' (= Rp ' + num(Number(price) * Number(rate), 0) + ')';
        }
        return t;
    }
    function fmtSnap(s) { return s ? fmtMoney(s.price, s.currency, s.fx_rate) : '(none)'; }

    // cfg: {$currency, $price, $rate, $wrap, $preview}  (jQuery objects)
    // ratesByCode: {USD: 16250, ...} -- today's rate per currency.
    // Call the returned object's .setRate(saved) to load a stored rate instead
    // of today's; user edits are respected until the currency changes again.
    function bindFx(cfg, ratesByCode) {
        function isIdr() { return (cfg.$currency.val() || 'IDR') === 'IDR'; }
        function refresh() {
            var idr = isIdr();
            cfg.$wrap.toggleClass('hidden', idr);
            if (idr) { cfg.$rate.val(''); cfg.$preview.text(''); return; }
            var r = parseFloat(String(cfg.$rate.val()).replace(/,/g, ''));
            var p = parseFloat(String(cfg.$price.val()).replace(/,/g, ''));
            cfg.$preview.text(r > 0 && p > 0 ? '≈ Rp ' + num(p * r, 0) : '');
        }
        function useToday() {
            var code = cfg.$currency.val() || 'IDR';
            var r = ratesByCode[code];
            cfg.$rate.val(code !== 'IDR' && r > 0 ? r : '');
            refresh();
        }
        cfg.$currency.off('change.cafx').on('change.cafx', useToday);
        cfg.$rate.off('input.cafx').on('input.cafx', refresh);
        cfg.$price.off('input.cafx').on('input.cafx', refresh);
        return {
            refresh: refresh,
            useToday: useToday,
            // stored = the rate saved with an existing price (or null)
            setRate: function(stored) {
                if (isIdr()) { refresh(); return; }
                if (stored !== null && stored !== undefined && Number(stored) > 0) { cfg.$rate.val(stored); refresh(); }
                else useToday();
            },
            // '' for IDR, else the typed rate; returns null when a foreign rate is missing/invalid
            value: function() {
                if (isIdr()) return '';
                var r = parseFloat(String(cfg.$rate.val()).replace(/,/g, ''));
                return r > 0 ? String(r) : null;
            }
        };
    }

    var confirmCb = null;
    function confirmModal(o, onYes) {
        $('#caConfirmTitle').text(o.title || 'Are you sure?');
        $('#caConfirmBody').html(o.bodyHtml || '');
        $('#caConfirmYes').text(o.yes || 'Yes');
        $('#caConfirmNo').text(o.no || 'No');
        confirmCb = onYes;
        $('#caConfirmModal').removeClass('hidden');
    }
    function closeConfirm() { $('#caConfirmModal').addClass('hidden'); confirmCb = null; }
    $(document).on('click', '#caConfirmNo, #caConfirmOverlay', closeConfirm);
    $(document).on('click', '#caConfirmYes', function() {
        var cb = confirmCb; closeConfirm();
        if (typeof cb === 'function') cb();
    });

    function historyModal(type, key, subtitle) {
        $('#caHistorySub').text(subtitle || '');
        $('#caHistoryTable, #caHistoryEmpty, #caHistoryError').addClass('hidden');
        $('#caHistoryLoading').removeClass('hidden');
        $('#caHistoryBody').empty();
        $('#caHistoryModal').removeClass('hidden');
        $.ajax({
            url: 'ajax_ca_edit_history.php', type: 'POST', dataType: 'json', data: { type: type, key: key },
            success: function(resp) {
                $('#caHistoryLoading').addClass('hidden');
                if (resp.status !== 'success') { $('#caHistoryError').text(resp.message || 'Could not load history.').removeClass('hidden'); return; }
                var rows = resp.rows || [];
                if (!rows.length) { $('#caHistoryEmpty').removeClass('hidden'); return; }
                var html = '';
                rows.forEach(function(r) {
                    var what = r.action === 'CREATE' ? 'Created' : (r.action === 'DELETE' ? 'Removed' : 'Changed');
                    if (r.line) what += ' &middot; line ' + esc(r.line);
                    var chg = r.action === 'DELETE' ? esc(fmtSnap(r.old))
                            : (r.action === 'CREATE' ? esc(fmtSnap(r.new)) : esc(fmtSnap(r.old)) + ' &rarr; ' + esc(fmtSnap(r.new)));
                    var extra = '';
                    if (r.detail && r.detail.kind === 'PO_CURRENCY') {
                        // A PO currency switch carries no price: show what changed instead of "(none) -> (none)".
                        what = 'PO currency' + (r.line ? ' &middot; line ' + esc(r.line) : '');
                        chg = esc(r.detail.summary || '');
                    } else if (r.detail && r.detail.summary) extra = '<span class="block text-gray-400">' + esc(r.detail.summary) + '</span>';
                    html += '<tr class="border-t border-gray-100 align-top">' +
                        '<td class="py-1.5 pr-3 whitespace-nowrap text-gray-600">' + esc(r.at) + '</td>' +
                        '<td class="py-1.5 pr-3 font-semibold text-gray-800">' + esc(r.actor) + '</td>' +
                        '<td class="py-1.5 pr-3 text-gray-700">' + what + '</td>' +
                        '<td class="py-1.5 text-gray-800">' + chg + extra + '</td></tr>';
                });
                $('#caHistoryBody').html(html);
                $('#caHistoryTable').removeClass('hidden');
            },
            error: function() { $('#caHistoryLoading').addClass('hidden'); $('#caHistoryError').text('Connection error.').removeClass('hidden'); }
        });
    }
    $(document).on('click', '#caHistoryClose, #caHistoryOverlay', function() { $('#caHistoryModal').addClass('hidden'); });

    return { esc: esc, fmtMoney: fmtMoney, fmtSnap: fmtSnap, bindFx: bindFx, confirm: confirmModal, history: historyModal };
})(jQuery);
</script>
    <?php
}

}
