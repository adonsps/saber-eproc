<?php
/**
 * modules/shared/dashboard/analytics_widgets.php
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    return; 
}

// Test/QA accounts and rejected registrations must never appear in analytics.
$an_exclude_test = "user_id NOT IN (SELECT id FROM users WHERE is_test_account = 1) AND registration_status <> 'rejected'";

/* ------------------------------------------------------------------ *
 * Qualification lifecycle buckets driven by vendors.registration_status.
 * Bucket index matches the chart label order below.
 *   0 Registration Submitted | 1 Migrated/Existing | 2 Invited PQ
 *   3 On Process | 4 Waiting Approval | 5 Approved | -1 ignored
 * ------------------------------------------------------------------ */
$an_self_set     = ['invited_pq'];                                             // profile complete, can fill PQ, not yet submitted
$an_process_set  = ['pq_submitted', 'revision_requested', 'returned_to_sourcing'];
$an_approval_set = ['under_review_sourcing', 'under_review_vm', 'under_review_vm_head'];

/* ------------------------------------------------------------------ *
 * Province metadata used both to parse addresses and to colour the map.
 * Province => [lat, lng, [match patterns...]]. More specific names are listed
 * before their substrings (e.g. PAPUA BARAT before PAPUA) so the first match wins.
 * ------------------------------------------------------------------ */
$an_province_meta = [
    'DKI Jakarta'         => [-6.20, 106.82, ['JAKARTA']],
    'Jawa Barat'          => [-6.90, 107.60, ['JAWA BARAT']],
    'Banten'              => [-6.40, 106.10, ['BANTEN']],
    'Jawa Tengah'         => [-7.30, 110.40, ['JAWA TENGAH']],
    'DI Yogyakarta'       => [-7.80, 110.40, ['YOGYAKARTA', 'DIY']],
    'Jawa Timur'          => [-7.55, 112.50, ['JAWA TIMUR']],
    'Bali'                => [-8.40, 115.20, ['BALI']],
    'Nusa Tenggara Barat' => [-8.70, 117.40, ['NUSA TENGGARA BARAT', 'NUSATENGGARA BARAT', 'NTB']],
    'Nusa Tenggara Timur' => [-8.70, 121.10, ['NUSA TENGGARA TIMUR', 'NTT']],
    'Aceh'                => [4.70, 96.70,   ['ACEH']],
    'Sumatera Utara'      => [2.10, 99.00,   ['SUMATERA UTARA', 'SUMATRA UTARA']],
    'Sumatera Barat'      => [-0.70, 100.50, ['SUMATERA BARAT', 'SUMATRA BARAT']],
    'Kepulauan Riau'      => [3.90, 108.10,  ['KEPULAUAN RIAU', 'KEP. RIAU', 'KEP RIAU']],
    'Riau'                => [0.50, 101.70,  ['RIAU']],
    'Jambi'               => [-1.60, 103.60, ['JAMBI']],
    'Sumatera Selatan'    => [-3.30, 104.00, ['SUMATERA SELATAN', 'SUMATRA SELATAN']],
    'Bangka Belitung'     => [-2.70, 106.40, ['BANGKA', 'BELITUNG']],
    'Bengkulu'            => [-3.80, 102.30, ['BENGKULU']],
    'Lampung'             => [-4.90, 105.40, ['LAMPUNG']],
    'Kalimantan Barat'    => [-0.30, 111.50, ['KALIMANTAN BARAT']],
    'Kalimantan Tengah'   => [-1.70, 113.40, ['KALIMANTAN TENGAH']],
    'Kalimantan Selatan'  => [-3.10, 115.30, ['KALIMANTAN SELATAN']],
    'Kalimantan Utara'    => [3.10, 116.00,  ['KALIMANTAN UTARA']],
    'Kalimantan Timur'    => [0.50, 116.40,  ['KALIMANTAN TIMUR']],
    'Sulawesi Utara'      => [1.00, 124.50,  ['SULAWESI UTARA']],
    'Gorontalo'           => [0.70, 122.40,  ['GORONTALO']],
    'Sulawesi Tengah'     => [-1.40, 121.40, ['SULAWESI TENGAH']],
    'Sulawesi Barat'      => [-2.80, 119.20, ['SULAWESI BARAT']],
    'Sulawesi Tenggara'   => [-4.10, 122.20, ['SULAWESI TENGGARA']],
    'Sulawesi Selatan'    => [-4.00, 120.00, ['SULAWESI SELATAN']],
    'Maluku Utara'        => [1.60, 127.90,  ['MALUKU UTARA']],
    'Maluku'              => [-3.70, 128.60, ['MALUKU']],
    'Papua Barat'         => [-1.30, 133.20, ['PAPUA BARAT']],
    'Papua'               => [-4.30, 138.10, ['PAPUA']],
];

// Resolve a company_address string to a province name (first pattern wins).
$an_resolve_prov = function (string $addr) use ($an_province_meta): string {
    $addr = strtoupper($addr);
    foreach ($an_province_meta as $prov => $meta) {
        foreach ($meta[2] as $pat) {
            if (preg_match('/\b' . preg_quote($pat, '/') . '\b/', $addr)) {
                return $prov;
            }
        }
    }
    return '';
};

/* ------------------------------------------------------------------ *
 * PER-VENDOR FACT TABLE (+ KPI totals) for client-side cross-filtering.
 * ------------------------------------------------------------------ */
$an_facts    = [];  // vendor_id => ['q'=>, 'r'=>, 'k'=>, 't'=>, 'p'=>, 's'=>[]]
$an_total     = 0;
$an_migrated  = 0;
$an_registered = 0; // self-registered (not migrated, not approved)
$an_approved  = 0;
$an_geo_mapped = 0; // vendors resolved to a province

$res = $conn->query("SELECT id, company_name, registration_status, company_type, sub_company_type, company_address FROM vendors WHERE $an_exclude_test");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $id = (int) $row['id'];
        $st = $row['registration_status'];

        // Qualification bucket.
        if ($st === 'registration_submitted')           { $q = 0; } // just registered, profile not yet complete
        elseif ($st === 'migrated')                     { $q = 1; }
        elseif (in_array($st, $an_self_set, true))      { $q = 2; } // invited_pq: profile complete, can fill PQ
        elseif (in_array($st, $an_process_set, true))   { $q = 3; }
        elseif (in_array($st, $an_approval_set, true))  { $q = 4; }
        elseif ($st === 'approved')                     { $q = 5; }
        else                                            { $q = -1; } // 'registered', 'draft', 'rejected', ...

        // KPI class.
        if ($st === 'migrated')      { $k = 'm'; $an_migrated++; }
        elseif ($st === 'approved')  { $k = 'a'; $an_approved++; }
        else                         { $k = 'r'; $an_registered++; }
        $an_total++;

        // Taxonomy company_type.
        $ct = $row['company_type'];
        $t  = $ct === 'Contractor' ? 'K' : ($ct === 'Supplier' ? 'S' : '');

        // Province.
        $addr = $row['company_address'];
        $p    = ($addr !== null && $addr !== '') ? $an_resolve_prov($addr) : '';
        if ($p !== '') { $an_geo_mapped++; }

        // id / n / rs / sct are display-only fields consumed by the detail table below.
        $an_facts[$id] = [
            'q'   => $q, 'r' => -1, 'k' => $k, 't' => $t, 'p' => $p, 's' => [],
            'id'  => $id,
            'n'   => (string) $row['company_name'],
            'rs'  => (string) $st,
            'sct' => (string) $row['sub_company_type'],
        ];
    }
}

// Risk bucket from the latest PQ submission per vendor.
$sql_risk = "SELECT s.vendor_id AS vid, s.max_possible_weighted_score AS mx, s.total_weighted_score AS tot
             FROM pq_submissions s
             WHERE s.id IN (SELECT MAX(id) FROM pq_submissions GROUP BY vendor_id)
               AND s.max_possible_weighted_score > 0
               AND s.vendor_id IN (SELECT id FROM vendors WHERE $an_exclude_test)";
$res = $conn->query($sql_risk);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $vid = (int) $row['vid'];
        if (!isset($an_facts[$vid])) { continue; }
        $mx = (float) $row['mx'];
        if ($mx <= 0) { continue; }
        $rp = (($mx - (float) $row['tot']) / $mx) * 100;
        $rp = max(0, min(100, $rp));
        if ($rp <= 15)     { $ri = 4; }
        elseif ($rp <= 29) { $ri = 3; }
        elseif ($rp <= 50) { $ri = 2; }
        elseif ($rp <= 61) { $ri = 1; }
        else               { $ri = 0; }
        $an_facts[$vid]['r'] = $ri;
    }
}

// Level-2 sub_category memberships per vendor (Contractor/Supplier only).
$sql_subs = "SELECT DISTINCT v.id AS vid, t.sub_category AS tw
             FROM vendors v
             JOIN taxonomy t
               ON FIND_IN_SET(t.sub_category, REPLACE(v.core_business, ', ', ',') COLLATE utf8mb4_unicode_ci) > 0
             WHERE v.core_business IS NOT NULL AND v.core_business <> ''
               AND v.company_type IN ('Contractor', 'Supplier')
               AND $an_exclude_test";
$res = $conn->query($sql_subs);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $vid = (int) $row['vid'];
        if (isset($an_facts[$vid])) {
            $an_facts[$vid]['s'][] = $row['tw'];
        }
    }
}

/* ------------------------------------------------------------------ *
 * TAXONOMY LEVEL 2 full breakdown per company_type - powers the
 * ------------------------------------------------------------------ */
$an_tax_breakdown = function (mysqli $conn, string $exclude, string $companyType): array {
    $out  = ['full' => [], 'total' => 0];
    $type = $conn->real_escape_string($companyType);
    $sql  = "SELECT t.sub_category AS tw, COUNT(DISTINCT v.id) AS c
             FROM vendors v
             JOIN taxonomy t
               ON FIND_IN_SET(t.sub_category, REPLACE(v.core_business, ', ', ',') COLLATE utf8mb4_unicode_ci) > 0
             WHERE v.core_business IS NOT NULL AND v.core_business <> ''
               AND v.company_type = '$type'
               AND $exclude
             GROUP BY t.sub_category
             ORDER BY c DESC";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out['full'][] = ['label' => $row['tw'], 'value' => (int) $row['c']];
            $out['total'] += (int) $row['c'];
        }
    }
    return $out;
};

$an_tax_k = $an_tax_breakdown($conn, $an_exclude_test, 'Contractor');
$an_tax_s = $an_tax_breakdown($conn, $an_exclude_test, 'Supplier');

$an_geo_choropleth = [];
foreach ($an_province_meta as $prov => $meta) {
    $an_geo_choropleth[] = ['name' => $prov, 'pat' => $meta[2]];
}

/* ------------------------------------------------------------------ *
 * JSON payloads for the front-end
 * ------------------------------------------------------------------ */
$an_json_facts = json_encode(array_values($an_facts));
$an_json_choro = json_encode($an_geo_choropleth);

$an_role         = $_SESSION['role'] ?? '';
$an_profile_dir  = ($an_role === 'sourcing') ? 'sourcing' : 'vendormgt';
$an_profile_base = ($path_to_root ?? '../../') . 'modules/' . $an_profile_dir . '/view_profile.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>
   
    @media (min-width: 768px) {
        .border-end-md { border-right: 1px solid var(--bs-border-color, #dee2e6); }
    }
    .an-geo-label { background: none; border: 0; }
    .an-geo-label span {
        display: block;
        text-align: center;
        font: 700 11px/14px system-ui, sans-serif;
        color: #fff;
        text-shadow: 0 0 2px #000, 0 0 2px #000, 0 1px 2px #000;
        pointer-events: none;
        white-space: nowrap;
    }
    #anQualChart, #anRiskChart, #anTaxChartK, #anTaxChartS { cursor: pointer; }

    .an-btn-soft {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        color: #0d6efd;
        background: rgba(13, 110, 253, .1);
        border: 1px solid transparent;
        border-radius: .375rem;
        padding: .22rem .6rem;
        line-height: 1.25;
        font-weight: 500;
        font-size: clamp(.62rem, .5rem + .35vw, .8rem);
        transition: background-color .15s ease, color .15s ease;
    }
    .an-btn-soft:hover,
    .an-btn-soft:focus {
        background: rgba(13, 110, 253, .18);
        color: #0a58ca;
    }

    #anGeoMap .leaflet-control-zoom {
        border: 0;
        border-radius: .4rem;
        overflow: hidden;
        margin: 10px;
        box-shadow: 0 1px 5px rgba(0, 0, 0, .15);
    }
    #anGeoMap .leaflet-control-zoom a {
        display: flex;
        align-items: center;
        justify-content: center;
        width: clamp(22px, 20px + .6vw, 30px);
        height: clamp(22px, 20px + .6vw, 30px);
        color: #495057;
        background: #fff;
        border: 0;
        border-bottom: 1px solid #eef0f2;
        font-size: clamp(.62rem, .5rem + .35vw, .82rem);
        transition: background-color .15s ease, color .15s ease;
    }
    #anGeoMap .leaflet-control-zoom a:last-child { border-bottom: 0; }
    #anGeoMap .leaflet-control-zoom a:hover {
        background: var(--bs-primary, #0d6efd);
        color: #fff;
    }
    #anGeoMap .leaflet-control-zoom a.leaflet-disabled {
        color: #c4c9cf;
        background: #f8f9fa;
        cursor: default;
    }
    #anGeoCard.an-geo-fullscreen {
        position: fixed;
        inset: 0;
        z-index: 1050;
        border-radius: 0;
    }
    #anGeoCard.an-geo-fullscreen #anGeoMap {
        height: calc(100vh - 49px) !important;
        border-radius: 0 !important;
    }
    body.an-geo-fullscreen-open { overflow: hidden; }

    #anTbl { --bs-table-striped-bg: rgba(0, 0, 0, .02); font-size: .8125rem; }
    #anTbl thead th.an-sort { cursor: pointer; white-space: nowrap; user-select: none; }
    #anTbl thead th.an-sort:hover { background: rgba(13, 110, 253, .08); }
    #anTbl thead tr.an-filter-row th { padding: .25rem .4rem; background: #fff; font-weight: 400; }
    #anTbl thead tr.an-filter-row .form-control,
    #anTbl thead tr.an-filter-row .form-select { font-size: .72rem; padding-top: .15rem; padding-bottom: .15rem; }

    #anTbl .btn-icon {
        width: 36px;
        height: 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        padding: 0;
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        border: 1px solid transparent;
    }
    #anTbl .btn-icon:hover {
        transform: translateY(-3px) scale(1.05);
        box-shadow: 0 6px 12px rgba(0, 0, 0, 0.12) !important;
        z-index: 10;
    }
    #anTbl .btn-icon:active { transform: translateY(-1px); }
    #anTbl .btn-icon-dark { background: #f8f9fa; color: #212529; border-color: rgba(33, 37, 41, 0.1); }
    #anTbl .btn-icon-dark:hover { background: #212529; color: #fff; }

    #anGeoTotal {
        font-size: clamp(.62rem, .5rem + .35vw, .78rem);
        font-weight: 500;
    }
</style>

<div class="container pb-4">

    <div id="anFilterBar" class="mb-2" style="display:none;"></div>

    <!-- ===================== KPI CARDS ===================== -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-start border-4 border-primary h-100">
                <div class="card-body py-2">
                    <small class="text-muted text-uppercase fw-bold">Total Vendor</small>
                    <h4 class="fw-bold text-primary mb-0" id="anKpiTotal"><?= number_format($an_total) ?></h4>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-start border-4 border-info h-100">
                <div class="card-body py-2">
                    <small class="text-muted text-uppercase fw-bold">Total Registered</small>
                    <h4 class="fw-bold text-info mb-0" id="anKpiRegistered"><?= number_format($an_registered) ?></h4>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-start border-4 border-secondary h-100">
                <div class="card-body py-2">
                    <small class="text-muted text-uppercase fw-bold">Total Migrated</small>
                    <h4 class="fw-bold text-secondary mb-0" id="anKpiMigrated"><?= number_format($an_migrated) ?></h4>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-start border-4 border-success h-100">
                <div class="card-body py-2">
                    <small class="text-muted text-uppercase fw-bold">Total Approved</small>
                    <h4 class="fw-bold text-success mb-0" id="anKpiApproved"><?= number_format($an_approved) ?></h4>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================== ROW 1: Qualification + Risk ===================== -->
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-2">
                    <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-chart-column text-primary me-2"></i>Vendor Qualification Status</h6>
                </div>
                <div class="card-body pt-1" style="height: 240px;">
                    <canvas id="anQualChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-2">
                    <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-shield-halved text-primary me-2"></i>Vendor Risk Profile</h6>
                </div>
                <div class="card-body pt-1" style="height: 240px;">
                    <canvas id="anRiskChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================== ROW 2: Taxonomy Level 2 (one card, 2 pies) + Geospatial ===================== -->
    <div class="row g-3 mb-2">
        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-2">
                    <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-layer-group text-primary me-2"></i>Taxonomy Level 2 &mdash; Top 5</h6>
                </div>
                <div class="card-body p-2">
                    <div class="row g-2">
                        <!-- Kontraktor -->
                        <div class="col-md-6 border-end-md">
                            <div class="d-flex justify-content-between align-items-center mb-1 px-2">
                                <span class="fw-semibold text-secondary"><i class="fas fa-helmet-safety text-primary me-2"></i>Kontraktor</span>
                                <?php if (!empty($an_tax_k['full'])): ?>
                                    <button type="button" class="an-btn-soft" data-bs-toggle="modal" data-bs-target="#anTaxModalK">
                                        <i class="fas fa-table"></i>Lihat Semua
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex align-items-center justify-content-center pt-1" style="height: 220px;">
                                <?php if (!empty($an_tax_k['full'])): ?>
                                    <canvas id="anTaxChartK"></canvas>
                                <?php else: ?>
                                    <p class="text-muted fst-italic mb-0">Belum ada data core business kontraktor.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <!-- Supplier -->
                        <div class="col-md-6">
                            <div class="d-flex justify-content-between align-items-center mb-1 px-2">
                                <span class="fw-semibold text-secondary"><i class="fas fa-boxes-stacked text-primary me-2"></i>Supplier</span>
                                <?php if (!empty($an_tax_s['full'])): ?>
                                    <button type="button" class="an-btn-soft" data-bs-toggle="modal" data-bs-target="#anTaxModalS">
                                        <i class="fas fa-table"></i>Lihat Semua
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex align-items-center justify-content-center pt-1" style="height: 220px;">
                                <?php if (!empty($an_tax_s['full'])): ?>
                                    <canvas id="anTaxChartS"></canvas>
                                <?php else: ?>
                                    <p class="text-muted fst-italic mb-0">Belum ada data core business supplier.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm h-100" id="anGeoCard">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-map-location-dot text-primary me-2"></i>Vendor Distribution (Province)</h6>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-light text-dark border" id="anGeoTotal"><i class="fas fa-location-dot text-primary me-1"></i><?= number_format($an_geo_mapped) ?> vendor</span>
                        <button type="button" class="an-btn-soft" id="anGeoFullscreen" title="Perbesar ke layar penuh">
                            <i class="fas fa-expand"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="anGeoMap" style="height: 100%; min-height: 260px; width: 100%; border-radius: 0 0 .375rem .375rem;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================== ROW 3: Vendor detail table (follows the active cross-filter) ===================== -->
    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-table text-primary me-2"></i>Detail Vendor</h6>
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-muted small" id="anTblCount"></span>
                        <button type="button" id="anTblReset" class="an-btn-soft"><i class="fas fa-times"></i>Reset Filter</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 420px;">
                        <table id="anTbl" class="table table-sm table-striped table-hover align-middle mb-0">
                            <thead class="table-light position-sticky top-0" style="z-index:1;">
                                <tr class="text-center align-middle">
                                    <th style="width:44px;">#</th>
                                    <th class="an-sort text-start" data-sort="n"   role="button">Vendor <i class="fas fa-sort text-muted small"></i></th>
                                    <th class="an-sort" data-sort="bt"  role="button">Business Type <i class="fas fa-sort text-muted small"></i></th>
                                    <th class="an-sort" data-sort="sct" role="button">Sub Business Type <i class="fas fa-sort text-muted small"></i></th>
                                    <th class="an-sort" data-sort="cb"  role="button">Core Business <i class="fas fa-sort text-muted small"></i></th>
                                    <th class="an-sort" data-sort="p"   role="button">Province <i class="fas fa-sort text-muted small"></i></th>
                                    <th class="an-sort" data-sort="rs"  role="button">Registration Status <i class="fas fa-sort text-muted small"></i></th>
                                    <th class="an-sort" data-sort="r"   role="button">Risk Level <i class="fas fa-sort text-muted small"></i></th>
                                    <th style="width:70px;">Action</th>
                                </tr>
                                <tr class="an-filter-row">
                                    <th></th>
                                    <th><input type="text" class="form-control form-control-sm" data-col="n" placeholder="Cari..."></th>
                                    <th><select class="form-select form-select-sm" data-col="bt"><option value="">Semua</option></select></th>
                                    <th><select class="form-select form-select-sm" data-col="sct"><option value="">Semua</option></select></th>
                                    <th><select class="form-select form-select-sm" data-col="cb"><option value="">Semua</option></select></th>
                                    <th><select class="form-select form-select-sm" data-col="p"><option value="">Semua</option></select></th>
                                    <th><select class="form-select form-select-sm" data-col="rs"><option value="">Semua</option></select></th>
                                    <th><select class="form-select form-select-sm" data-col="r"><option value="">Semua</option></select></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="anTblBody"></tbody>
                        </table>
                    </div>
                    <div id="anTblEmpty" class="text-muted fst-italic text-center py-4" style="display:none;">Tidak ada vendor yang cocok.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===================== Taxonomy Level 2 - full breakdown pop-ups ===================== -->
<?php
$an_tax_modals = [
    ['id' => 'anTaxModalK', 'typ' => 'K', 'title' => 'Kontraktor', 'data' => $an_tax_k],
    ['id' => 'anTaxModalS', 'typ' => 'S', 'title' => 'Supplier',   'data' => $an_tax_s],
];
foreach ($an_tax_modals as $m):
    if (empty($m['data']['full'])) { continue; }
    $m_total = $m['data']['total'];
?>
<div class="modal fade" id="<?= $m['id'] ?>" tabindex="-1" aria-labelledby="<?= $m['id'] ?>Label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="<?= $m['id'] ?>Label">
                    <i class="fas fa-layer-group text-primary me-2"></i>Taxonomy Level 2 &mdash; <?= htmlspecialchars($m['title']) ?> (Semua Sub Category)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover align-middle mb-0">
                        <thead class="table-light position-sticky top-0">
                            <tr>
                                <th style="width:48px;" class="text-center">#</th>
                                <th>Sub Category</th>
                                <th class="text-end" style="width:110px;">Vendor</th>
                                <th class="text-end" style="width:80px;">%</th>
                            </tr>
                        </thead>
                        <tbody id="<?= $m['id'] ?>Body" data-typ="<?= $m['typ'] ?>">
                            <?php foreach ($m['data']['full'] as $i => $r): ?>
                                <tr>
                                    <td class="text-center text-muted"><?= $i + 1 ?></td>
                                    <td><?= htmlspecialchars($r['label']) ?></td>
                                    <td class="text-end"><?= number_format($r['value']) ?></td>
                                    <td class="text-end"><?= $m_total > 0 ? round($r['value'] / $m_total * 100, 1) : 0 ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fw-bold border-top">
                                <td></td>
                                <td>Total</td>
                                <td class="text-end" id="<?= $m['id'] ?>Total"><?= number_format($m_total) ?></td>
                                <td class="text-end">100%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    /* ============================================================= *
     * Cross-filter engine: everything is recomputed from the
     * per-vendor fact table so a click on any chart re-filters the
     * whole dashboard. A single filter can be active at a time.
     * ============================================================= */
    var anFacts = <?= $an_json_facts ?>;
    var anProfileBase = <?= json_encode($an_profile_base) ?>; 
    // Active cross-filters keyed by dimension; combined with AND. One value per
    // dimension (clicking the same segment again clears it, a different one in the
    // same dimension replaces it). e.g. { geo:{val:'Jawa Barat'}, qual:{val:1} }
    var anState = { filters: {} };

    var anQualChart = null, anRiskChart = null, anTaxChartK = null, anTaxChartS = null;
    var anGeoRender = null; 

    var anQualLabels = ['Registration Submitted', 'Migrated/Existing', 'Invited PQ', 'On Process', 'Waiting Approval', 'Approved'];
    var anRiskLabels = ['Critical', 'High', 'Moderate', 'Low', 'Very Low'];
    var anRiskColors = ['#ef7b85', '#ff9e5c', '#ffd257', '#5fd3ad', '#63c793']; // matches the risk chart

    function anFmt(n) { return Number(n).toLocaleString('en-US'); }

    // Does a fact satisfy one dimension's filter?
    function anMatchOne(f, dim, flt) {
        if (dim === 'qual') { return f.q === flt.val; }
        if (dim === 'risk') { return f.r === flt.val; }
        if (dim === 'tax')  { return f.t === flt.t && f.s.indexOf(flt.val) !== -1; }
        if (dim === 'geo')  { return f.p === flt.val; }
        return true;
    }

    // A fact passes only if it satisfies every active dimension (AND).
    function anMatch(f, filters) {
        for (var dim in filters) {
            if (!filters.hasOwnProperty(dim)) { continue; }
            if (!anMatchOne(f, dim, filters[dim])) { return false; }
        }
        return true;
    }

    // One pass over the fact table produces every aggregate the UI needs.
    function anCompute(filters) {
        filters = filters || {};
        var qual = [0, 0, 0, 0, 0, 0], risk = [0, 0, 0, 0, 0];
        var kpi = { total: 0, r: 0, m: 0, a: 0 };
        var geo = {}, taxK = {}, taxS = {};
        for (var i = 0; i < anFacts.length; i++) {
            var f = anFacts[i];
            if (!anMatch(f, filters)) { continue; }
            kpi.total++;
            if (f.k === 'm') { kpi.m++; } else if (f.k === 'a') { kpi.a++; } else { kpi.r++; }
            if (f.q >= 0) { qual[f.q]++; }
            if (f.r >= 0) { risk[f.r]++; }
            if (f.p) { geo[f.p] = (geo[f.p] || 0) + 1; }
            var bag = f.t === 'K' ? taxK : (f.t === 'S' ? taxS : null);
            if (bag && f.s.length) {
                var seen = {};
                for (var j = 0; j < f.s.length; j++) {
                    var s = f.s[j];
                    if (seen[s]) { continue; } // count each vendor once per sub_category
                    seen[s] = 1;
                    bag[s] = (bag[s] || 0) + 1;
                }
            }
        }
        return { qual: qual, risk: risk, kpi: kpi, geo: geo, taxK: taxK, taxS: taxS };
    }

    function anTop5(bag) {
        var arr = Object.keys(bag).map(function (k) { return [k, bag[k]]; });
        arr.sort(function (a, b) { return b[1] - a[1]; });
        var top = arr.slice(0, 5);
        var labels = top.map(function (x) { return x[0]; });
        var values = top.map(function (x) { return x[1]; });
        // Aggregate everything beyond the top 5 into a "Lainnya" slice.
        var rest = arr.slice(5);
        if (rest.length) {
            var other = rest.reduce(function (sum, x) { return sum + x[1]; }, 0);
            if (other > 0) {
                labels.push('Lainnya');
                values.push(other);
            }
        }
        return { labels: labels, values: values };
    }

    function anTaxPieData(bag, typ) {
        var flt = anState.filters.tax;
        if (flt && flt.t === typ && flt.val !== 'Lainnya') {
            return { labels: [flt.val], values: [bag[flt.val] || 0] };
        }
        return anTop5(bag);
    }

    function anRenderTaxModal(bag, bodyId, totalId) {
        var body = document.getElementById(bodyId);
        if (!body) { return; }
        var arr = Object.keys(bag).map(function (k) { return [k, bag[k]]; });
        arr.sort(function (a, b) { return b[1] - a[1]; });
        var total = arr.reduce(function (sum, x) { return sum + x[1]; }, 0);
        var rows = [];
        for (var i = 0; i < arr.length; i++) {
            var pct = total > 0 ? Math.round(arr[i][1] / total * 1000) / 10 : 0;
            rows.push(
                '<tr>' +
                    '<td class="text-center text-muted">' + (i + 1) + '</td>' +
                    '<td>' + anEsc(arr[i][0]) + '</td>' +
                    '<td class="text-end">' + anFmt(arr[i][1]) + '</td>' +
                    '<td class="text-end">' + pct + '%</td>' +
                '</tr>'
            );
        }
        body.innerHTML = rows.length
            ? rows.join('')
            : '<tr><td colspan="4" class="text-center text-muted fst-italic py-3">Tidak ada data untuk filter ini.</td></tr>';
        var tot = document.getElementById(totalId);
        if (tot) { tot.textContent = anFmt(total); }
    }

    function anLabelFor(dim, flt) {
        if (dim === 'qual') { return 'Kualifikasi: ' + anQualLabels[flt.val]; }
        if (dim === 'risk') { return 'Risiko: ' + anRiskLabels[flt.val]; }
        if (dim === 'tax')  { return (flt.t === 'K' ? 'Kontraktor' : 'Supplier') + ' — ' + flt.val; }
        if (dim === 'geo')  { return 'Provinsi: ' + flt.val; }
        return '';
    }

    function anEsc(s) {
        return String(s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    function anRenderBanner() {
        var bar = document.getElementById('anFilterBar');
        if (!bar) { return; }
        var dims = Object.keys(anState.filters);
        if (!dims.length) { bar.style.display = 'none'; bar.innerHTML = ''; return; }
        bar.style.display = '';
        var chips = dims.map(function (dim) {
            return '<span class="badge bg-primary d-inline-flex align-items-center gap-2 py-2 px-3 me-1">' +
                '<i class="fas fa-filter"></i> ' + anEsc(anLabelFor(dim, anState.filters[dim])) +
                ' <button type="button" class="btn-close btn-close-white" style="font-size:.6rem;" aria-label="Hapus filter" onclick="anRemoveFilter(\'' + dim + '\')"></button>' +
            '</span>';
        }).join('');
        var clearAll = dims.length > 1
            ? '<button type="button" class="an-btn-soft ms-1" onclick="anClearFilter()"><i class="fas fa-times"></i>Hapus semua</button>'
            : '';
        bar.innerHTML = chips + clearAll +
            '<span class="text-muted small ms-2">Filter digabung (AND). Klik ulang bagian yang sama untuk menghapusnya.</span>';
    }

    // Humanised registration_status label, mirroring the vendor list badges.
    function anStatusLabel(st) {
        if (st === 'returned_to_sourcing') { return 'RETURNED BY VM'; }
        if (st === 'under_review_vm')      { return 'WITH VM TEAM'; }
        return String(st || '-').replace(/_/g, ' ').toUpperCase();
    }

    var anSort = { key: null, dir: 1 };
    function anBizType(f) { return f.t === 'K' ? 'Contractor' : (f.t === 'S' ? 'Supplier' : ''); }

    // Per-column table filters ('' = no filter for that column). Text column (n)
    var anColFilters = { n: '', bt: '', sct: '', cb: '', p: '', rs: '', r: '' };

    function anCoreBiz(f) {
        var arr = f.s || [], seen = {}, out = [];
        for (var i = 0; i < arr.length; i++) {
            var v = arr[i];
            if (v === '' || v == null || seen[v]) { continue; }
            seen[v] = 1;
            out.push(v);
        }
        return out.join(', ');
    }

    function anColMatch(f) {
        var cf = anColFilters;
        if (cf.n && String(f.n || '').toLowerCase().indexOf(cf.n.toLowerCase()) === -1) { return false; }
        if (cf.bt && anBizType(f) !== cf.bt) { return false; }
        if (cf.sct && String(f.sct || '') !== cf.sct) { return false; }
        if (cf.cb && (f.s || []).indexOf(cf.cb) === -1) { return false; }
        if (cf.p && String(f.p || '') !== cf.p) { return false; }
        if (cf.rs && String(f.rs || '') !== cf.rs) { return false; }
        if (cf.r !== '' && String(f.r) !== cf.r) { return false; }
        return true;
    }

    // Comparable value for a given sort column (numeric for risk, else lowercased text).
    function anSortVal(f, key) {
        if (key === 'r')   { return f.r; }
        if (key === 'n')   { return String(f.n || '').toLowerCase(); }
        if (key === 'bt')  { return anBizType(f).toLowerCase(); }
        if (key === 'p')   { return String(f.p || '').toLowerCase(); }
        if (key === 'sct') { return String(f.sct || '').toLowerCase(); }
        if (key === 'cb')  { return anCoreBiz(f).toLowerCase(); }
        if (key === 'rs')  { return anStatusLabel(f.rs).toLowerCase(); }
        return '';
    }

    function anUpdateSortIcons() {
        var ths = document.querySelectorAll('#anTbl thead th.an-sort');
        for (var i = 0; i < ths.length; i++) {
            var icon = ths[i].querySelector('i');
            if (!icon) { continue; }
            if (anSort.key === ths[i].getAttribute('data-sort')) {
                icon.className = 'fas ' + (anSort.dir === 1 ? 'fa-sort-up' : 'fa-sort-down') + ' text-primary small';
            } else {
                icon.className = 'fas fa-sort text-muted small';
            }
        }
    }

    // Detail table: lists every vendor in the active cross-filter, further
    function anRenderTable() {
        var body = document.getElementById('anTblBody');
        if (!body) { return; }
        var list = [];
        for (var i = 0; i < anFacts.length; i++) {
            var f = anFacts[i];
            if (!anMatch(f, anState.filters)) { continue; }
            if (!anColMatch(f)) { continue; }
            list.push(f);
        }
        if (anSort.key) {
            list.sort(function (a, b) {
                var va = anSortVal(a, anSort.key), vb = anSortVal(b, anSort.key);
                if (va < vb) { return -anSort.dir; }
                if (va > vb) { return anSort.dir; }
                return 0;
            });
        }
        var rows = [];
        for (var n = 0; n < list.length; n++) {
            var f = list[n];
            var bt   = anBizType(f) || '-';
            var subs = f.sct ? f.sct : '-';
            var core = anCoreBiz(f) || '-';
            var risk = f.r >= 0 ? anRiskLabels[f.r] : '-';
            var riskColor = f.r >= 0 ? anRiskColors[f.r] : '#adb5bd';
            rows.push(
                '<tr class="text-center">' +
                    '<td class="text-muted">' + (n + 1) + '</td>' +
                    '<td class="fw-semibold text-dark text-start">' + anEsc(f.n || '-') + '</td>' +
                    '<td>' + anEsc(bt) + '</td>' +
                    '<td class="small">' + anEsc(subs) + '</td>' +
                    '<td class="small text-start">' + anEsc(core) + '</td>' +
                    '<td>' + anEsc(f.p || '-') + '</td>' +
                    '<td><span class="badge bg-light text-dark border" style="min-width:130px;">' + anEsc(anStatusLabel(f.rs)) + '</span></td>' +
                    '<td><span class="badge" style="background:' + riskColor + ';color:#fff;min-width:80px;">' + anEsc(risk) + '</span></td>' +
                    '<td><a class="btn-icon btn-icon-dark shadow-sm" href="' + anEsc(anProfileBase) + '?id=' + encodeURIComponent(f.id) + '" title="View Full Profile"><i class="fas fa-user-circle"></i></a></td>' +
                '</tr>'
            );
        }
        body.innerHTML = rows.join('');
        var empty = document.getElementById('anTblEmpty');
        if (empty) { empty.style.display = list.length ? 'none' : ''; }
        var cnt = document.getElementById('anTblCount');
        if (cnt) { cnt.textContent = anFmt(list.length) + ' vendor'; }
    }

    // Populate the per-column dropdowns from the fact table, then wire the
    function anInitTableFilters() {
        function distinct(mapFn) {
            var set = {};
            for (var i = 0; i < anFacts.length; i++) {
                var v = mapFn(anFacts[i]);
                if (v !== '' && v != null) { set[v] = 1; }
            }
            return Object.keys(set).sort();
        }
        function fill(col, opts) {
            var sel = document.querySelector('#anTbl thead select[data-col="' + col + '"]');
            if (!sel) { return; }
            for (var i = 0; i < opts.length; i++) {
                var o = document.createElement('option');
                o.value = opts[i].value;
                o.textContent = opts[i].label;
                sel.appendChild(o);
            }
        }
        var idMap = function (v) { return { value: v, label: v }; };

        // Core business is multi-valued (each vendor can belong to several
        // sub_categories), so collect distinct entries across every f.s array.
        function distinctCore() {
            var set = {};
            for (var i = 0; i < anFacts.length; i++) {
                var arr = anFacts[i].s || [];
                for (var j = 0; j < arr.length; j++) { if (arr[j] !== '' && arr[j] != null) { set[arr[j]] = 1; } }
            }
            return Object.keys(set).sort();
        }

        fill('bt',  distinct(anBizType).map(idMap));
        fill('sct', distinct(function (f) { return f.sct; }).map(idMap));
        fill('cb',  distinctCore().map(idMap));
        fill('p',   distinct(function (f) { return f.p; }).map(idMap));
        fill('rs',  distinct(function (f) { return f.rs; })
                        .map(function (v) { return { value: v, label: anStatusLabel(v) }; })
                        .sort(function (a, b) { return a.label < b.label ? -1 : (a.label > b.label ? 1 : 0); }));

        var present = {};
        for (var i = 0; i < anFacts.length; i++) { present[String(anFacts[i].r)] = 1; }
        var riskOpts = [];
        for (var ri = 0; ri < anRiskLabels.length; ri++) { riskOpts.push({ value: String(ri), label: anRiskLabels[ri] }); }
        riskOpts.push({ value: '-1', label: '-' });
        fill('r', riskOpts.filter(function (o) { return present[o.value]; }));

        var controls = document.querySelectorAll('#anTbl thead [data-col]');
        for (var c = 0; c < controls.length; c++) {
            controls[c].addEventListener(controls[c].tagName === 'SELECT' ? 'change' : 'input', function () {
                anColFilters[this.getAttribute('data-col')] = this.value;
                anRenderTable();
            });
        }

        var reset = document.getElementById('anTblReset');
        if (reset) {
            reset.addEventListener('click', function () {
                var cs = document.querySelectorAll('#anTbl thead [data-col]');
                for (var i = 0; i < cs.length; i++) {
                    cs[i].value = '';
                    anColFilters[cs[i].getAttribute('data-col')] = '';
                }
                anRenderTable();
            });
        }
    }

    window.anClearFilter = function () { anState.filters = {}; anApply(); };
    window.anRemoveFilter = function (dim) { delete anState.filters[dim]; anApply(); };
    function anSetFilter(dim, val, typ) {
        var cur = anState.filters[dim];
        // Toggle off when the exact same segment is clicked again; otherwise set
        // (or replace) this dimension's value while keeping the other filters.
        if (cur && cur.val === val && cur.t === typ) {
            delete anState.filters[dim];
        } else {
            anState.filters[dim] = { val: val, t: typ };
        }
        anApply();
    }

    // Push the current filter's aggregates into every visualisation.
    function anApply() {
        var agg = anCompute(anState.filters), el;
        if ((el = document.getElementById('anKpiTotal')))      { el.textContent = anFmt(agg.kpi.total); }
        if ((el = document.getElementById('anKpiRegistered'))) { el.textContent = anFmt(agg.kpi.r); }
        if ((el = document.getElementById('anKpiMigrated')))   { el.textContent = anFmt(agg.kpi.m); }
        if ((el = document.getElementById('anKpiApproved')))   { el.textContent = anFmt(agg.kpi.a); }

        if (anQualChart) { anQualChart.data.datasets[0].data = agg.qual; anQualChart.update(); }
        if (anRiskChart) { anRiskChart.data.datasets[0].data = agg.risk; anRiskChart.update(); }
        if (anTaxChartK) { var tk = anTaxPieData(agg.taxK, 'K'); anTaxChartK.data.labels = tk.labels; anTaxChartK.data.datasets[0].data = tk.values; anTaxChartK.update(); }
        if (anTaxChartS) { var ts = anTaxPieData(agg.taxS, 'S'); anTaxChartS.data.labels = ts.labels; anTaxChartS.data.datasets[0].data = ts.values; anTaxChartS.update(); }
        anRenderTaxModal(agg.taxK, 'anTaxModalKBody', 'anTaxModalKTotal');
        anRenderTaxModal(agg.taxS, 'anTaxModalSBody', 'anTaxModalSTotal');

        var geoTotal = 0;
        for (var kk in agg.geo) { geoTotal += agg.geo[kk]; }
        if ((el = document.getElementById('anGeoTotal'))) { el.innerHTML = '<i class="fas fa-location-dot text-primary me-1"></i>' + anFmt(geoTotal) + ' vendor ter-mapping'; }
        if (anGeoRender) { anGeoRender(agg.geo); }

        anRenderBanner();
        anRenderTable();
    }

    // Show a pointer cursor only while hovering a clickable element.
    function anHover(evt, els) {
        var t = evt.native && evt.native.target;
        if (t) { t.style.cursor = els.length ? 'pointer' : 'default'; }
    }

    var anInit = anCompute({});

    if (typeof Chart !== 'undefined') {
        var anPieDataLabels = {
            anchor: 'end',
            align: 'end',
            offset: 4,
            clamp: true,
            clip: false,
            color: '#495057',
            font: { weight: 'bold', size: 10 },
            formatter: function (value, ctx) {
                var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                if (!total || !value) { return ''; }
                var pct = Math.round((value / total) * 100);
                return value + ' (' + pct + '%)';
            }
        };

        // Compact legend: small font/box and long category names truncated to a
        var anPieLegendLabels = {
            padding: 4,
            boxWidth: 10,
            boxHeight: 10,
            font: { size: 10 },
            generateLabels: function (chart) {
                var ds = chart.data.datasets[0] || {};
                var colors = ds.backgroundColor || [];
                return (chart.data.labels || []).map(function (lbl, i) {
                    var full = String(lbl);
                    return {
                        text: full.length > 22 ? full.slice(0, 21) + '…' : full,
                        fillStyle: colors[i],
                        strokeStyle: colors[i],
                        lineWidth: 1,
                        hidden: false,
                        index: i
                    };
                });
            }
        };

        // 1. Vendor Qualification Status (bar, 5 lifecycle buckets)
        var qualValues = anInit.qual;
        var qualMaxVal = Math.max.apply(null, qualValues.concat([0]));
        var qualStep = (function (maxVal) {
            if (maxVal <= 0) { return 5; }
            var rough = maxVal / 5;                         // aim for ~5 steps
            var mag   = Math.pow(10, Math.floor(Math.log10(rough)));
            var norm  = rough / mag;                        // 1..10
            var nice  = norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10;
            return Math.max(5, nice * mag);                 // never finer than 5
        })(qualMaxVal);

        var qualAxisMax = (Math.ceil(qualMaxVal / qualStep) + 1) * qualStep;
        anQualChart = new Chart(document.getElementById('anQualChart'), {
            type: 'bar',
            plugins: [ChartDataLabels],
            data: {
                labels: anQualLabels,
                datasets: [{
                    label: '# Vendor',
                    data: qualValues,
                    backgroundColor: ['#b39ddb', '#adb5bd', '#5fd0e6', '#ffd257', '#ff9e5c', '#63c793'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 24 } },
                onHover: anHover,
                onClick: function (e, els) { if (els.length) { anSetFilter('qual', els[0].index); } },
                plugins: {
                    legend: { display: false },
                    datalabels: {
                        anchor: 'end',
                        align: 'end',
                        offset: 1,
                        clamp: true,
                        clip: false,
                        color: '#495057',
                        font: { weight: 'bold', size: 11 },
                        formatter: function (value) {
                            return value > 0 ? value : '';
                        }
                    }
                },
                scales: {
                    x: { ticks: { font: { size: 10 } } },
                    y: { beginAtZero: true, max: qualAxisMax, ticks: { precision: 0, stepSize: qualStep } }
                }
            }
        });

        // 2. Vendor Risk Profile (bar)
        var riskValues = anInit.risk;
        var riskStep = 5;
        var riskMaxVal = Math.max.apply(null, riskValues.concat([0]));
        var riskAxisMax = (Math.ceil(riskMaxVal / riskStep) + 1) * riskStep;
        anRiskChart = new Chart(document.getElementById('anRiskChart'), {
            type: 'bar',
            plugins: [ChartDataLabels],
            data: {
                labels: anRiskLabels,
                datasets: [{
                    label: '# Vendor',
                    data: riskValues,
                    backgroundColor: ['#ef7b85', '#ff9e5c', '#ffd257', '#5fd3ad', '#63c793'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 24 } },
                onHover: anHover,
                onClick: function (e, els) { if (els.length) { anSetFilter('risk', els[0].index); } },
                plugins: {
                    legend: { display: false },
                    datalabels: {
                        anchor: 'end',
                        align: 'end',
                        offset: 1,
                        clamp: true,
                        clip: false,
                        color: '#495057',
                        font: { weight: 'bold', size: 11 },
                        formatter: function (value) {
                            return value > 0 ? value : '';
                        }
                    }
                },
                scales: { y: { beginAtZero: true, max: riskAxisMax, ticks: { precision: 0, stepSize: riskStep } } }
            }
        });

        // 3. Taxonomy Level 2 - Top 5 (pie), one per company_type.
        var anTaxPalette = ['#6ba6f0', '#9b86e0', '#ff9e5c', '#5fd3ad', '#ffd257', '#c2c9d6'];
        function anMakeTaxPie(canvasId, top, typ) {
            var el = document.getElementById(canvasId);
            if (!el) { return null; }
            return new Chart(el, {
                type: 'pie',
                plugins: [ChartDataLabels],
                data: {
                    labels: top.labels,
                    datasets: [{
                        data: top.values,
                        backgroundColor: anTaxPalette,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    radius: '69%',
                    layout: { padding: { top: 8, right: 8, bottom: 2, left: 8 } },
                    onHover: anHover,
                    onClick: function (e, els, chart) { if (els.length) { var lbl = chart.data.labels[els[0].index]; if (lbl !== 'Lainnya') { anSetFilter('tax', lbl, typ); } } },
                    plugins: {
                        legend: { position: 'bottom', labels: anPieLegendLabels },
                        datalabels: anPieDataLabels
                    }
                }
            });
        }
        anTaxChartK = anMakeTaxPie('anTaxChartK', anTop5(anInit.taxK), 'K');
        anTaxChartS = anMakeTaxPie('anTaxChartS', anTop5(anInit.taxS), 'S');
    }

    // 4. Geospatial map (Leaflet choropleth: provinces shaded by vendor count)
    if (typeof L !== 'undefined' && document.getElementById('anGeoMap')) {
        var choro = <?= $an_json_choro ?>;

        // Map a GeoJSON province name to our province using the same
        // specific-before-substring patterns as the PHP address parser.
        function anResolveProv(rawName) {
            var name = (rawName || '').toUpperCase();
            for (var i = 0; i < choro.length; i++) {
                var pats = choro[i].pat;
                for (var j = 0; j < pats.length; j++) {
                    var pat = pats[j].replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                    if (new RegExp('\\b' + pat + '\\b').test(name)) { return choro[i].name; }
                }
            }
            return null;
        }

        // Dynamic buckets: colour scaled to the highest province count.
        function anGetColor(c, maxCount) {
            if (!c || c <= 0) { return '#e9ecef'; }
            var r = maxCount > 0 ? c / maxCount : 0;
            return r > 0.80 ? '#57c96a' :
                   r > 0.60 ? '#8fdd8a' :
                   r > 0.40 ? '#efe07a' :
                   r > 0.20 ? '#ffcb73' :
                   r > 0.05 ? '#ff9e78' :
                              '#f56b6b';
        }

        var map = L.map('anGeoMap', { scrollWheelZoom: false, attributionControl: false }).setView([-2.5, 118.0], 4);
        L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 10,
            attribution: '&copy; Esri'
        }).addTo(map);

        var anZoomIn  = document.querySelector('#anGeoMap .leaflet-control-zoom-in');
        var anZoomOut = document.querySelector('#anGeoMap .leaflet-control-zoom-out');
        if (anZoomIn)  { anZoomIn.innerHTML  = '<i class="fas fa-plus"></i>'; }
        if (anZoomOut) { anZoomOut.innerHTML = '<i class="fas fa-minus"></i>'; }

        var anGeoFsBtn = document.getElementById('anGeoFullscreen');
        if (anGeoFsBtn) {
            anGeoFsBtn.addEventListener('click', function () {
                var card = document.getElementById('anGeoCard');
                var isFs = card.classList.toggle('an-geo-fullscreen');
                document.body.classList.toggle('an-geo-fullscreen-open', isFs);
                anGeoFsBtn.innerHTML = isFs ? '<i class="fas fa-compress"></i>' : '<i class="fas fa-expand"></i>';
                anGeoFsBtn.title = isFs ? 'Keluar dari layar penuh' : 'Perbesar ke layar penuh';
                setTimeout(function () { map.invalidateSize(); }, 150);
            });
            document.addEventListener('keydown', function (e) {
                var card = document.getElementById('anGeoCard');
                if (e.key === 'Escape' && card.classList.contains('an-geo-fullscreen')) {
                    anGeoFsBtn.click();
                }
            });
        }

        var geoFeatures = [];   // { layer, prov } per GeoJSON polygon
        var geoLabels   = [];   // { marker, latlng, name, prov, count }
        var geoLegend   = null;
        var geoCounts   = {};   // province name => current (filtered) vendor count

        function declutterLabels() {
            var placed = [];
            geoLabels.slice()
                .filter(function (l) { return l.count > 0; })
                .sort(function (a, b) { return b.count - a.count; })
                .forEach(function (l) {
                    var pt = map.latLngToContainerPoint(l.latlng);
                    var w = l.name.length * 6.2 + 6, h = 14;
                    var box = { x1: pt.x - w / 2, y1: pt.y - h / 2, x2: pt.x + w / 2, y2: pt.y + h / 2 };
                    var hit = placed.some(function (b) {
                        return !(box.x2 < b.x1 || box.x1 > b.x2 || box.y2 < b.y1 || box.y1 > b.y2);
                    });
                    l.marker.setOpacity(hit ? 0 : 1);
                    if (!hit) { placed.push(box); }
                });
            geoLabels.forEach(function (l) { if (l.count <= 0) { l.marker.setOpacity(0); } });
        }

        // Legend: dynamic buckets scaled to the highest province count, shown as "1+, 4+, ...".
        function buildLegend(maxCount) {
            if (geoLegend) { map.removeControl(geoLegend); geoLegend = null; }
            if (maxCount <= 0) { return; }
            geoLegend = L.control({ position: 'topright' });
            geoLegend.onAdd = function () {
                var div = L.DomUtil.create('div', 'an-geo-legend');
                div.style.cssText = 'background:#fff;padding:4px 6px;border-radius:4px;box-shadow:0 0 6px rgba(0,0,0,.2);font-size:9px;line-height:13px;';
                var grades = [0.05, 0.20, 0.40, 0.60, 0.80];
                var prev = 1;
                div.innerHTML = '<div class="fw-bold mb-1 text-center">Jumlah<br>Vendor</div>';
                for (var i = 0; i < grades.length; i++) {
                    var color = anGetColor(grades[i] * maxCount + 0.001, maxCount);
                    div.innerHTML += '<i style="display:inline-block;width:9px;height:9px;margin-right:4px;background:' + color + '"></i>' +
                                     prev + '+<br>';
                    prev = Math.max(prev + 1, Math.ceil(grades[i] * maxCount) + 1);
                }
                div.innerHTML += '<i style="display:inline-block;width:9px;height:9px;margin-right:4px;background:' + anGetColor(maxCount, maxCount) + '"></i>' + prev + '+';
                return div;
            };
            geoLegend.addTo(map);
        }

          anGeoRender = function (counts) {
            geoCounts = counts || {};
            var maxCount = 0;
            for (var k in geoCounts) { if (geoCounts[k] > maxCount) { maxCount = geoCounts[k]; } }
            geoFeatures.forEach(function (gf) {
                var c = gf.prov ? (geoCounts[gf.prov] || 0) : 0;
                gf.layer.setStyle({ fillColor: anGetColor(c, maxCount), weight: 1, color: '#ffffff', fillOpacity: 0.8 });
                var label = gf.prov || gf.raw;
                gf.layer.setTooltipContent('<strong>' + label + '</strong>: ' + c + ' vendor');
            });
            geoLabels.forEach(function (l) { l.count = l.prov ? (geoCounts[l.prov] || 0) : 0; });
            buildLegend(maxCount);
            declutterLabels();
        };

        fetch('https://cdn.jsdelivr.net/gh/ans-4175/peta-indonesia-geojson@master/indonesia-prov.geojson')
            .then(function (r) { return r.json(); })
            .then(function (geo) {
                L.geoJSON(geo, {
                    style: function () {
                        return { fillColor: '#e9ecef', weight: 1, color: '#ffffff', fillOpacity: 0.8 };
                    },
                    onEachFeature: function (feature, layer) {
                        var prov = anResolveProv(feature.properties.Propinsi);
                        layer.bindTooltip('', { sticky: true });
                        layer.on({
                            mouseover: function (e) { e.target.setStyle({ weight: 2, color: '#495057', fillOpacity: 0.95 }); },
                            mouseout:  function (e) { e.target.setStyle({ weight: 1, color: '#ffffff', fillOpacity: 0.8 }); }
                        });
                        if (prov) { layer.on('click', function () { anSetFilter('geo', prov); }); }

                        geoFeatures.push({ layer: layer, prov: prov, raw: feature.properties.Propinsi });

                        if (prov) {
                            var center = layer.getBounds().getCenter();
                            var lblMarker = L.marker(center, {
                                interactive: false,
                                keyboard: false,
                                icon: L.divIcon({
                                    className: 'an-geo-label',
                                    html: '<span>' + prov + '</span>',
                                    iconSize: [120, 16],
                                    iconAnchor: [60, 8]
                                })
                            }).addTo(map);
                            geoLabels.push({ marker: lblMarker, latlng: center, name: prov, prov: prov, count: 0 });
                        }
                    }
                }).addTo(map);

                anGeoRender(anCompute(anState.filters).geo);
                map.on('zoomend moveend', declutterLabels);
            })
            .catch(function () {
                document.getElementById('anGeoMap').insertAdjacentHTML(
                    'beforeend',
                    '<div class="text-muted small p-2">Peta wilayah gagal dimuat.</div>'
                );
            });

        setTimeout(function () { map.invalidateSize(); }, 200);
    }

    // Initial detail-table render + per-column filters (table-only, no chart impact).
    anInitTableFilters();

    // Click a header to sort by that column; click again to flip direction.
    var anSortHeaders = document.querySelectorAll('#anTbl thead th.an-sort');
    for (var anH = 0; anH < anSortHeaders.length; anH++) {
        anSortHeaders[anH].addEventListener('click', function () {
            var key = this.getAttribute('data-sort');
            if (anSort.key === key) { anSort.dir = -anSort.dir; }
            else { anSort.key = key; anSort.dir = 1; }
            anUpdateSortIcons();
            anRenderTable();
        });
    }

    anRenderTable();
});
</script>
