<?php
/* ------------------------------------------------------------------ *
 * modules/shared/dashboard/spending.php
 * ------------------------------------------------------------------ */
$vs_cats      = [];    // label => [ ['vendor'=>, 'value'=>(float)], ... ] desc by value
$vs_has_table = false; // vendor_eval_po_taxonomy present in Workspace DB?
$vs_max_bars  = 5;    // cap vendors shown per chart so bars stay readable

$vs_ws = function_exists('getWorkspaceConnection') ? getWorkspaceConnection() : null;
if ($vs_ws instanceof mysqli) {
    $chk = $vs_ws->query("SHOW TABLES LIKE 'vendor_eval_po_taxonomy'");
    if ($chk && $chk->num_rows > 0) {
        $vs_has_table = true;

        $vs_sql = "SELECT
                TRIM(t.taxonomy_level_1) AS l1,
                p.vendor_name            AS vendor,
                SUM(p.po_amount)         AS spend
            FROM prs p
            JOIN vendor_eval_po_taxonomy t ON p.po_number = t.po_number
            WHERE p.po_number IS NOT NULL AND p.po_number <> ''
              AND p.po_amount > 0 AND p.qty > 0
              AND p.vendor_name IS NOT NULL AND TRIM(p.vendor_name) <> ''
              AND t.taxonomy_level_1 IS NOT NULL AND TRIM(t.taxonomy_level_1) <> ''
            GROUP BY l1, vendor
            ORDER BY l1 ASC, spend DESC";
        if ($res = $vs_ws->query($vs_sql)) {
            while ($row = $res->fetch_assoc()) {
                $vs_cats[$row['l1']][] = ['vendor' => $row['vendor'], 'value' => (float) $row['spend']];
            }
        }
    }
    $vs_ws->close();
}

// Turn the category map into an ordered list of charts, biggest category first.
$vs_charts = [];
foreach ($vs_cats as $label => $vendors) {
    $total   = array_sum(array_column($vendors, 'value'));
    $vendors = array_slice($vendors, 0, $vs_max_bars); // top-N spenders per category
    $vs_charts[] = ['label' => $label, 'total' => $total, 'vendors' => $vendors];
}
usort($vs_charts, fn($a, $b) => $b['total'] <=> $a['total']);

// Pin the three "Project" family categories to the very end of the board so
// they always land as the last thumbnails, in a fixed order (Retail, Property,
// Maintenance). Everything else keeps its spend-desc position. Matching is
// case-insensitive on the trimmed Level 1 label.
$vs_pin_order = ['project - retail', 'project - property', 'maintenance & facility management'];
$vs_pin_key   = fn($label) => array_search(strtolower(trim($label)), $vs_pin_order, true);
$vs_rest      = [];  // non-pinned, keep spend-desc order
$vs_pinned    = [];  // pinned charts keyed by their slot in $vs_pin_order
foreach ($vs_charts as $chart) {
    $slot = $vs_pin_key($chart['label']);
    if ($slot === false) {
        $vs_rest[] = $chart;
    } else {
        $chart['pin']     = $slot;   
        $vs_pinned[$slot] = $chart;
    }
}
ksort($vs_pinned);                            
$vs_charts = array_merge($vs_rest, array_values($vs_pinned));

$vs_json = json_encode($vs_charts);
?>

<style>
    /* ---- Vendor Spending FIFO board ---- */

    .vs-holder { overflow: hidden; }
    .vs-holder .vs-holder-title {
        display: block;
        font-weight: 600;
        color: #212529;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .vs-holder .vs-holder-body { position: relative; }

    /* Divider between header and chart, coloured by family: bright green for
       non-Project categories, neutral grey for the pinned "Project" cards */
    .vs-holder:not(.is-project) .card-header { border-bottom: 3px solid #16a34a; }
    .vs-holder.is-project        .card-header { border-bottom: 3px solid #4b5563; }

    /* Category-total KPI in the header (main cards only) */
    .vs-holder .vs-holder-total {
        font-weight: 700;
        color: #212529;
        white-space: nowrap;
        line-height: 1.1;
        margin-left: auto;
    }
    .vs-holder.is-thumb .vs-holder-total { display: none; }

    @keyframes vsFade {
        from { opacity: 0; }
        to   { opacity: 1; }
    }

    /* Main (large) cards */
    .vs-holder.is-main {
        border: 1px solid #eceef1;
        box-shadow: 0 .45rem .95rem rgba(33, 37, 41, .09) !important;
        transition: box-shadow .18s ease, transform .18s ease;
        animation: vsFade .65s ease-in-out;
    }

    @media (prefers-reduced-motion: reduce) {
        .vs-holder.is-main { animation: none; }
    }

    .vs-holder.is-main:hover {
        box-shadow: 0 .7rem 1.2rem rgba(33, 37, 41, .12) !important;
        transform: translateY(-2px);
    }
    .vs-holder.is-main .vs-holder-title { font-size: .95rem; }
    .vs-holder.is-main .vs-holder-total { font-size: 1.05rem; }
    .vs-holder.is-main .vs-holder-body  { height: 340px; }

    /* Thumbnail (small) cards */
    .vs-thumbs {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: .75rem;
        margin-top: 1rem;
    }
    @media (max-width: 991.98px) {
        .vs-thumbs { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 575.98px) {
        .vs-thumbs { grid-template-columns: 1fr; }
    }
    .vs-holder.is-thumb {
        cursor: pointer;
        transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
        border: 1px solid #e9ecef;
    }
    .vs-holder.is-thumb:hover { transform: translateY(-3px); }

    .vs-holder.is-thumb:not(.is-project):hover {
        box-shadow: 0 .5rem 1rem rgba(25, 135, 84, .40) !important;
        border-color: #198754;
    }
    .vs-holder.is-thumb:not(.is-project):hover .vs-holder-hint { color: #146c43; }

    .vs-holder.is-thumb.is-project:hover {
        box-shadow: 0 .5rem 1rem rgba(73, 80, 87, .40) !important;
        border-color: #6c757d;
    }
    .vs-holder.is-thumb.is-project:hover .vs-holder-hint { color: #343a40; }

    .vs-holder.is-thumb .vs-holder-title { font-size: .8rem; }
    .vs-holder.is-thumb .vs-holder-body  { height: 132px; }
    .vs-holder.is-thumb .vs-holder-hint  {
        font-size: .8rem;
        color: #adb5bd;
        white-space: nowrap;
    }
    .vs-holder.is-main .vs-holder-hint { display: none; }
</style>

<!-- ===================== Vendor Spending per Taxonomy Level 1 (FIFO board) ===================== -->
<div class="card shadow-sm">
    <div class="card-body">
        <?php if (!$vs_has_table): ?>
            <p class="text-muted fst-italic mb-0"><i class="fas fa-circle-info me-1"></i>Tabel <code>vendor_eval_po_taxonomy</code> tidak ditemukan di database Workspace, jadi vendor spending per kategori belum bisa ditampilkan.</p>
        <?php elseif (empty($vs_charts)): ?>
            <p class="text-muted fst-italic mb-0">Belum ada data PO ter-mapping pada database Workspace.</p>
        <?php else: ?>
            <!-- <p class="text-muted small mb-3"><i class="fas fa-hand-pointer me-1"></i>Klik salah satu thumbnail di bawah untuk menaikkannya menjadi chart utama (mekanisme antrian / FIFO).</p> -->

            <div id="vsBoard">
                <!-- Three active (main) visualizations -->
                <div class="row g-3">
                    <div class="col-12 col-lg-4" id="vsMain1"></div>
                    <div class="col-12 col-lg-4" id="vsMain2"></div>
                    <div class="col-12 col-lg-4" id="vsMain3"></div>
                </div>

                <!-- Thumbnail queue -->
                <div id="vsThumbsWrap" class="mt-3"> 
                    <div class="vs-thumbs" id="vsThumbs"></div>
                </div>
            </div>

            <!-- <p class="text-muted small mt-3 mb-0">Sumber: <code>prs</code> (Workspace) &times; <code>vendor_eval_po_taxonomy</code>. Nilai = gross <code>po_amount</code> per vendor, diurutkan terbesar &rarr; terkecil.</p> -->
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var VS = <?= $vs_json ?: '[]' ?>;
    var board = document.getElementById('vsBoard');
    if (!board || !VS.length || typeof Chart === 'undefined') { return; }

    var main1El   = document.getElementById('vsMain1');
    var main2El   = document.getElementById('vsMain2');
    var main3El   = document.getElementById('vsMain3');
    var thumbsEl  = document.getElementById('vsThumbs');
    var thumbsWrap = document.getElementById('vsThumbsWrap');

    // Distinct-ish colour per chart so cards are easy to tell apart.
    var PALETTE = ['#6ba6f0', '#5cc9a7', '#f0b46b', '#b58bf0', '#f08b8b', '#7bc8e0',
                   '#e0c65c', '#8ba0f0', '#9bd06b', '#f08bc0'];


    function shortLabel(s) {
        s = String(s);
        var max = 12;
        return s.length > max ? s.slice(0, max).trimEnd() + '…' : s;
    }

    function rp(v)      { return 'Rp ' + Number(v).toLocaleString('id-ID'); }
    function rpShort(v) {
        v = Number(v) || 0;
        if (v >= 1e12) { return 'Rp ' + (v / 1e12).toFixed(1).replace('.', ',') + ' T'; }
        if (v >= 1e9)  { return 'Rp ' + (v / 1e9).toFixed(1).replace('.', ',') + ' M'; }
        if (v >= 1e6)  { return 'Rp ' + (v / 1e6).toFixed(1).replace('.', ',') + ' jt'; }
        return 'Rp ' + v.toLocaleString('id-ID');
    }

    var holders = [];  // DOM nodes, index == chart index
    var charts  = [];  // Chart instances, same index

    VS.forEach(function (cat, i) {
        var holder = document.createElement('div');
        holder.className = 'vs-holder card shadow-sm';
        if (cat.pin != null) { holder.classList.add('is-project'); } 
        holder.dataset.idx = i;
        holder.innerHTML =
            '<div class="card-header bg-white py-2 d-flex justify-content-between align-items-center gap-2">' +
                '<span class="vs-holder-title" title="' + escapeAttr(cat.label) + '">' + escapeHtml(cat.label) + '</span>' +
                '<span class="vs-holder-total" title="Total spending kategori: ' + rp(cat.total) + '">' + rpShort(cat.total) + '</span>' +
                '<span class="vs-holder-hint" title="Klik untuk buka"><i class="fas fa-arrow-up"></i></span>' +
            '</div>' +
            '<div class="card-body vs-holder-body p-2"><canvas></canvas></div>';
        holders.push(holder);
    });

    // Queue of chart indices: [main1, main2, thumb, thumb, ...]
    var queue = VS.map(function (_, i) { return i; });


    function normalizeQueue() {
        var rest = queue.slice(3);
        rest.sort(function (a, b) {
            var pa = VS[a].pin, pb = VS[b].pin;
            if (pa == null && pb == null) { return 0; }   // both non-pinned: keep order
            if (pa == null) { return -1; }                // non-pinned stays ahead
            if (pb == null) { return 1; }                 // pinned sinks to the tail
            return pa - pb;                               // both pinned: fixed order
        });
        queue = queue.slice(0, 3).concat(rest);
    }

    main2El.style.display    = queue.length >= 2 ? '' : 'none';
    main3El.style.display    = queue.length >= 3 ? '' : 'none';
    thumbsWrap.style.display = queue.length >= 4 ? '' : 'none';
    if (queue.length === 1) {
        main1El.className = 'col-12';
    } else if (queue.length === 2) {
        main1El.className = 'col-12 col-lg-6';
        main2El.className = 'col-12 col-lg-6';
    }

    // Place holders into their slots and tag them main/thumb (sizes via CSS).
    function place() {
        if (queue[0] !== undefined) { main1El.appendChild(holders[queue[0]]); }
        if (queue[1] !== undefined) { main2El.appendChild(holders[queue[1]]); }
        if (queue[2] !== undefined) { main3El.appendChild(holders[queue[2]]); }
        for (var k = 3; k < queue.length; k++) { thumbsEl.appendChild(holders[queue[k]]); }

        holders.forEach(function (h, idx) {
            var pos = queue.indexOf(idx);
            h.classList.toggle('is-main', pos < 3);
            h.classList.toggle('is-thumb', pos >= 3);
        });

        // Container sizes changed; ask each chart to fit its new box.
        charts.forEach(function (c) { if (c) { c.resize(); } });
    }

    normalizeQueue();
    place(); // initial arrangement before charts are created (correct sizing)

    // Instantiate charts now that canvases live in correctly sized containers.
    VS.forEach(function (cat, i) {
        var el = holders[i].querySelector('canvas');
        var labels = cat.vendors.map(function (v) { return v.vendor; });
        var values = cat.vendors.map(function (v) { return Math.round(v.value); });
        var colour = PALETTE[i % PALETTE.length];

        charts[i] = new Chart(el, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Total Spending', data: values, backgroundColor: colour,
                    borderWidth: 0, borderRadius: 4, borderSkipped: false, maxBarThickness: 48
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 250 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function (items) { return items.length ? items[0].label : ''; }, // vendor name
                            label: function (c) { return rp(c.raw); }                                 // total spending
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: true, color: '#adb5bd' },
                        ticks: {
                            font: { size: 10 }, autoSkip: true, maxRotation: 90, minRotation: 0,
                            // queue; re-evaluated on every draw, so promoting a
                            // thumbnail to a main restores the full vendor name.
                            callback: function (value) {
                                var full = this.getLabelForValue(value);
                                return (holders[i] && holders[i].classList.contains('is-thumb'))
                                    ? shortLabel(full) : full;
                            }
                        } },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#dee2e6', borderDash: [3, 3] },
                        border: { display: true, color: '#adb5bd' },
                        ticks: { callback: function (v) { return rpShort(v); }, font: { size: 10 } }
                    }
                }
            }
        });
    });

    // FIFO rotation: clicked thumbnail -> front; everything else keeps its
    function promote(idx) {
        if (queue[0] === idx) { return; }               // already main1
        queue = [idx].concat(queue.filter(function (x) { return x !== idx; }));
        normalizeQueue(); // keep the Project cards pinned to the last thumbnails
        place();
    }

    // One delegated listener; only thumbnails react.
    thumbsEl.addEventListener('click', function (e) {
        var holder = e.target.closest('.vs-holder.is-thumb');
        if (holder) { promote(parseInt(holder.dataset.idx, 10)); }
    });

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function escapeAttr(s) { return escapeHtml(s); }
});
</script>
