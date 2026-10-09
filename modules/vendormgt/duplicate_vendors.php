<?php
// modules/vendormgt/duplicate_vendors.php
//
// The Vendor Management screen for companies holding more than one row in the
// register, and the place where a duplicate is retired.
//
// Retiring means DEACTIVATING, never deleting: the duplicate row carries PQ
// submissions, notifications and document audits that no foreign key protects,
// so a DELETE would orphan all of it irreversibly. Deactivation blocks the
// vendor's login, removes the row from the registration duplicate check, and can
// be undone from this same screen.
//
// Clustering and the keeper decision come from includes/vendor_duplicate_audit.php
// — shared with tools/audit_duplicate_vendors.php so the CLI report and this
// screen never disagree about which row survives.
//
// ACCESS: vendor_mgt and vendormgt_head, matching modules/vendormgt/all_vendors.php.

session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/vendor_duplicate_audit.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['vendor_mgt', 'vendormgt_head'], true)) {
    die("Access Denied");
}

// Minted here because this page renders every form that posts to
// vendor_active_action.php, which rejects a request that does not carry it.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$page_title = "Duplicate Vendors (VM)";
include $path_to_root . 'includes/header.php';

// Filters. `show` keeps the low-confidence clusters one click away, because
// those are the only ones that genuinely need a person.
$search    = trim((string) ($_GET['search'] ?? ''));
$show      = $_GET['show'] ?? 'all';          // all | check | deps | inactive
$threshold = (float) ($_GET['threshold'] ?? 95);
if ($threshold < 80 || $threshold > 100) { $threshold = 95.0; }

$audit = vendor_dup_build_plan($conn, ['threshold' => $threshold, 'use_word' => true]);
$plan  = $audit['plan'];
$pairs = $audit['pair_reason'];

// Per-cluster derived facts, computed once so the markup stays readable.
$rowsOut = [];
$needCheckCount = $inactiveCount = 0;
foreach ($plan as $i => $entry) {
    $needsCheck = vendor_dup_cluster_needs_check($entry, $pairs);
    $deps = 0;
    $inactiveHere = 0;
    foreach ($entry['drops'] as $d) {
        $deps += $d['child_total'];
        if ($d['is_active'] === 0) { $inactiveHere++; }
    }
    if ($entry['keeper']['is_active'] === 0) { $inactiveHere++; }
    if ($needsCheck)   { $needCheckCount++; }
    if ($inactiveHere) { $inactiveCount++; }

    // Haystack for the search box: everything a VM might type.
    $hay = mb_strtolower($entry['keeper']['company_name'] . ' ' . $entry['keeper']['email'] . ' ' . $entry['keeper']['id']);
    foreach ($entry['drops'] as $d) {
        $hay .= ' ' . mb_strtolower($d['company_name'] . ' ' . $d['email'] . ' ' . $d['id'] . ' ' . $d['registration_status']);
    }

    $rowsOut[] = [
        'n'          => $i + 1,
        'entry'      => $entry,
        'needsCheck' => $needsCheck,
        'deps'       => $deps,
        'inactive'   => $inactiveHere,
        'hay'        => $hay,
    ];
}

// Apply filters server-side so the counters below match what is on screen.
$visible = array_values(array_filter($rowsOut, static function ($r) use ($search, $show) {
    if ($search !== '' && !str_contains($r['hay'], mb_strtolower($search))) { return false; }
    if ($show === 'check'    && !$r['needsCheck'])    { return false; }
    if ($show === 'deps'     && $r['deps'] < 1)       { return false; }
    if ($show === 'inactive' && $r['inactive'] < 1)   { return false; }
    return true;
}));

$qs = static fn(array $over) => http_build_query(array_merge(
    ['search' => $search, 'show' => $show, 'threshold' => (int) $threshold], $over
));
?>

<style>
    .dup-card { border: 1px solid #e9ecef; border-radius: 14px; overflow: hidden; background: #fff; }
    .dup-head { display: flex; justify-content: space-between; align-items: center; gap: 12px;
                padding: 12px 16px; background: #fafbfc; border-bottom: 1px solid #e9ecef; }
    .dup-head h6 { margin: 0; font-weight: 700; }
    .dup-num { color: #9aa1ab; font-weight: 400; margin-right: 8px; }
    /* Fixed layout so the <th> widths govern every cluster table identically;
       with auto layout each table would size to its own content and the headers
       would drift out of line from one card to the next. */
    .dup-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .dup-table thead th {
        font-size: 10.5px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase;
        color: #8a929c; text-align: left; padding: 8px 14px; border-bottom: 1px solid #e9ecef;
        white-space: nowrap;   /* a wrapped label doubled the header height */
    }
    .dup-table thead th.th-end { text-align: right; }
    /* Fixed layout clips rather than widens, so long values must wrap. */
    .dup-table td.nm { overflow-wrap: anywhere; }
    .dup-table td { padding: 11px 14px; border-top: 1px solid #f1f2f4; vertical-align: middle; }
    .dup-table tbody tr:first-child td { border-top: 0; }
    .row-keep { background: #e8f6ee; }
    .row-off  { background: #f8f9fa; }
    .row-off .dup-name, .row-off .dup-mail { text-decoration: line-through; opacity: .65; }
    .tag { display: inline-block; font-size: 10.5px; font-weight: 800; letter-spacing: .4px;
           padding: 3px 8px; border-radius: 6px; white-space: nowrap; }
    .tag-keep { background: #0f7b3d; color: #fff; }
    .tag-dup  { background: #b42318; color: #fff; }
    .tag-off  { background: #6c757d; color: #fff; }
    .dup-name { font-weight: 600; }
    .dup-why  { display: block; font-weight: 400; font-size: 11.5px; color: #6b7280; margin-top: 2px; }
    .dup-mail { color: #6b7280; font-size: 12.5px; word-break: break-all; }
    .dup-kid  { color: #6b7280; font-size: 11.5px; }
    .pill { background: #eef1f5; border-radius: 6px; padding: 2px 8px; font-size: 11.5px; white-space: nowrap; }
    .flag { display: inline-block; background: #fff4e5; color: #b25e09; border: 1px solid #f0cf9f;
            border-radius: 6px; padding: 2px 8px; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .stat-box { background: #fff; border: 1px solid #e9ecef; border-radius: 12px; padding: 12px 16px; }
    .stat-box b { display: block; font-size: 22px; line-height: 1.2; }
    .stat-box span { color: #6b7280; font-size: 12px; }
    /* Collapsed help — present but out of the way. */
    .dup-help { font-size: 12.5px; color: #6b7280; background: #fff;
                border: 1px solid #e9ecef; border-radius: 10px; padding: 8px 14px; }
    .dup-help summary { cursor: pointer; font-weight: 600; color: #495057; }
    .dup-help summary::marker { color: #adb5bd; }
    /* Row actions: small, and never wrapping the icon onto its own line —
       wrapping is what made these read as oversized blocks. */
    .btn-act { font-size: 11.5px; padding: 4px 12px; border-radius: 999px; white-space: nowrap; }
    /* The VSS theme sets .btn{padding:10px 25px;border-radius:50px} and
       .form-control/.form-select{padding:12px 15px}. Those outrank Bootstrap's
       -sm classes, so the filter controls need explicit sizing here — and it is
       what stretched the icon-only reset button into an oval. Scoped to this
       bar; the global theme is untouched. */
    .dup-filters .form-control,
    .dup-filters .form-select,
    .dup-filters .input-group-text,
    .dup-filters .btn {
        height: 36px; padding: 6px 12px; font-size: 13px;
        box-shadow: none; line-height: 1.4;
    }
    .dup-filters .btn { padding: 6px 18px; }
    /* Equal width and height, so it reads as a round icon button, not an oval. */
    .dup-filters .btn-reset { width: 36px; height: 36px; padding: 0; flex: 0 0 auto; border-radius: 50%; }
    .btn-act { box-shadow: none; }
</style>

<nav class="navbar navbar-vss mb-4">
    <div class="container-fluid">
        <span class="navbar-brand fw-bold d-flex align-items-center gap-2">
            <?= vss_brand_logo() ?>Duplicate Vendors
        </span>
        <div class="d-flex align-items-center gap-2">
            <a href="all_vendors.php" class="btn btn-sm btn-nav">
                <i class="fas fa-list me-1"></i> All Vendors
            </a>
            <a href="dashboard.php" class="btn btn-sm btn-nav">
                <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
            </a>
            <?php include $path_to_root . 'includes/navbar_profile.php'; ?>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 pb-5">

    <?php if (!empty($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 border-0 shadow-sm">
            <i class="fas fa-check-circle me-1"></i> <?= htmlspecialchars($_GET['success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 border-0 shadow-sm">
            <i class="fas fa-exclamation-circle me-1"></i> <?= htmlspecialchars($_GET['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold text-dark mb-0">Possible Duplicate Registrations</h4>
            <small class="text-muted">
                Same company registered more than once &mdash; matched on company name, not email.
            </small>
        </div>
    </div>

    <div class="row g-2 mb-4">
        <div class="col-6 col-lg"><div class="stat-box"><b><?= (int) $audit['total'] ?></b><span>vendors in register</span></div></div>
        <div class="col-6 col-lg"><div class="stat-box"><b><?= count($plan) ?></b><span>duplicate clusters</span></div></div>
        <div class="col-6 col-lg"><div class="stat-box"><b><?= (int) $audit['flagged'] ?></b><span>rows flagged duplicate</span></div></div>
        <div class="col-6 col-lg"><div class="stat-box"><b class="text-warning"><?= $needCheckCount ?></b><span>need a human check</span></div></div>
        <div class="col-6 col-lg"><div class="stat-box"><b class="text-secondary"><?= $inactiveCount ?></b><span>already actioned</span></div></div>
    </div>

    <?php /* Collapsed by default. The rule matters while you are deciding on a
             cluster, not on every page load — and it was pushing the actual list
             below the fold. */ ?>
    <details class="dup-help mb-3">
        <summary>How KEEP is chosen, and what deactivating does</summary>
        <div class="pt-2">
            <b>KEEP</b> = furthest along the pipeline (Approved &rsaquo; Under Review VM &rsaquo;
            Under Review Sourcing / Revision &rsaquo; PQ Submitted &rsaquo; Full Profile &rsaquo;
            Migrated &rsaquo; Registered). Ties go to the row with the most history, then the oldest.<br>
            <b>Deactivating never deletes.</b> History is kept, the vendor simply cannot log in, and it is reversible.<br>
            <b>Amber &ldquo;verify first&rdquo;</b> = linked by shared words rather than spelling; about half are different companies.
        </div>
    </details>

    <div class="card shadow-sm border-0 rounded-3 mb-4">
        <div class="card-body py-2">
            <form method="GET" action="duplicate_vendors.php" class="row g-2 align-items-center dup-filters">
                <div class="col-md-5">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text input-group-text-sm bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control form-control-sm border-start-0 ps-0"
                               placeholder="Search company, email or vendor ID..."
                               value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="show" class="form-select form-select-sm">
                        <option value="all"      <?= $show === 'all'      ? 'selected' : '' ?>>All clusters</option>
                        <option value="check"    <?= $show === 'check'    ? 'selected' : '' ?>>Only those needing a check</option>
                        <option value="deps"     <?= $show === 'deps'     ? 'selected' : '' ?>>Only those with history attached</option>
                        <option value="inactive" <?= $show === 'inactive' ? 'selected' : '' ?>>Only those already actioned</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="threshold" class="form-select form-select-sm">
                        <?php foreach ([95, 97, 99, 100] as $t): ?>
                            <option value="<?= $t ?>" <?= (int) $threshold === $t ? 'selected' : '' ?>>Match &ge; <?= $t ?>%</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary fw-bold flex-fill">
                        <i class="fas fa-filter me-1"></i> Apply
                    </button>
                    <?php if ($search !== '' || $show !== 'all' || (int) $threshold !== 95): ?>
                        <a href="duplicate_vendors.php" class="btn btn-sm btn-outline-secondary btn-reset" title="Reset filter"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <p class="text-muted small mb-3">
        Showing <?= count($visible) ?> of <?= count($plan) ?> clusters.
    </p>

    <?php if (!$visible): ?>
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body text-center py-5 text-muted">
                <i class="fas fa-check-circle fa-2x mb-2 d-block text-success"></i>
                No clusters match those filters.
            </div>
        </div>
    <?php endif; ?>

    <?php
    // One row of the cluster table. $keep marks the survivor; everything else is
    // a candidate for deactivation.
    $renderRow = function (array $v, bool $keep, string $why) use ($qs) {
        $off = $v['is_active'] === 0;
        $kids = [];
        foreach ($v['children'] as $t => $c) { $kids[] = htmlspecialchars("$t: $c"); }
        ?>
        <tr class="<?= $keep ? 'row-keep' : ($off ? 'row-off' : '') ?>">
            <td>
                <?php if ($keep): ?><span class="tag tag-keep">KEEP</span>
                <?php else: ?><span class="tag tag-dup">DUPLICATE</span><?php endif; ?>
            </td>
            <td class="text-muted small">#<?= (int) $v['id'] ?></td>
            <td>
                <span class="dup-name"><?= htmlspecialchars($v['company_name']) ?></span>
                <?php if ($why !== ''): ?><span class="dup-why"><?= htmlspecialchars($why) ?></span><?php endif; ?>
            </td>
            <td>
                <span class="pill"><?= htmlspecialchars(vendor_dup_status_label($v['registration_status'])) ?></span>
                <?php if ($off): ?><br><span class="tag tag-off mt-1">NONAKTIF</span><?php endif; ?>
            </td>
            <td class="dup-mail"><?= htmlspecialchars($v['email'] ?: '—') ?></td>
            <td class="dup-kid">
                <?= $kids ? implode(' &middot; ', $kids) : '<span class="text-muted">no history</span>' ?>
            </td>
            <td class="text-end">
                <?php if ($off): ?>
                    <form method="POST" action="vendor_active_action.php" class="d-inline"
                          onsubmit="return confirm('Aktifkan kembali <?= htmlspecialchars(addslashes($v['company_name'])) ?>?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="vendor_id" value="<?= (int) $v['id'] ?>">
                        <input type="hidden" name="action" value="reactivate">
                        <input type="hidden" name="return_to" value="duplicate_vendors.php?<?= htmlspecialchars($qs([])) ?>">
                        <button class="btn btn-outline-success btn-act">
                            <i class="fas fa-rotate-left me-1"></i> Aktifkan
                        </button>
                    </form>
                <?php else: ?>
                    <button class="btn btn-outline-danger btn-act btn-deact"
                            data-id="<?= (int) $v['id'] ?>"
                            data-name="<?= htmlspecialchars($v['company_name']) ?>"
                            data-keep="<?= $keep ? '1' : '0' ?>">
                        <i class="fas fa-ban me-1"></i> Nonaktifkan
                    </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    };
    ?>

    <?php foreach ($visible as $r):
        $entry = $r['entry'];
    ?>
        <div class="dup-card shadow-sm mb-3">
            <div class="dup-head">
                <h6><span class="dup-num">#<?= $r['n'] ?></span><?= htmlspecialchars($entry['keeper']['company_name']) ?></h6>
                <div class="d-flex gap-2">
                    <?php if ($r['needsCheck']): ?>
                        <span class="flag" title="Linked by shared words, not spelling — verify before acting">
                            <i class="fas fa-triangle-exclamation me-1"></i>verify first
                        </span>
                    <?php endif; ?>
                    <?php if ($entry['decisive']): ?>
                        <span class="flag" title="Outcome depends on a status not covered by the ranking rule">unruled status</span>
                    <?php endif; ?>
                    <?php if ($r['inactive']): ?>
                        <span class="tag tag-off"><?= $r['inactive'] ?> nonaktif</span>
                    <?php endif; ?>
                </div>
            </div>
            <table class="dup-table">
                <thead>
                    <tr>
                        <th style="width:110px;">Decision</th>
                        <th style="width:96px;">Vendor ID</th>
                        <th>Company Name</th>
                        <th style="width:180px;">Stage</th>
                        <th style="width:200px;">Email</th>
                        <th style="width:165px;">History</th>
                        <th style="width:140px;" class="th-end">Action</th>
                    </tr>
                </thead>
                <?php $renderRow($entry['keeper'], true, ''); ?>
                <?php foreach ($entry['drops'] as $d): ?>
                    <?php $renderRow($d, false, vendor_dup_reason($pairs, $entry['keeper']['id'], $d['id'])['label']); ?>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endforeach; ?>

</div>

<?php /* Deactivation always goes through this dialog: it is the only place the
         required reason is collected, and it warns explicitly when the row being
         switched off is the one the ranking picked to keep. */ ?>
<div class="modal fade" id="deactModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content border-0 shadow-lg" style="border-radius:16px;"
          method="POST" action="vendor_active_action.php">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold">Nonaktifkan Vendor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <p class="mb-3">
          Menonaktifkan <strong id="deactName"></strong>.
          Data PQ dan riwayatnya tetap tersimpan &mdash; vendor hanya tidak bisa login lagi,
          dan bisa diaktifkan kembali kapan saja.
        </p>

        <div class="alert alert-warning small d-none" id="deactKeepWarn">
          <i class="fas fa-triangle-exclamation me-1"></i>
          Baris ini adalah <strong>KEEP</strong> &mdash; record yang paling jauh prosesnya di grup ini.
          Pastikan Anda memang ingin menonaktifkan yang ini, bukan duplikatnya.
        </div>

        <label class="form-label fw-semibold small">Alasan <span class="text-danger">*</span></label>
        <input type="text" name="reason" class="form-control" maxlength="255" required
               placeholder="mis. Duplikat dari vendor #866">
        <div class="form-text">Tersimpan pada record vendor bersama nama Anda dan waktunya.</div>

        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="vendor_id" id="deactId">
        <input type="hidden" name="action" value="deactivate">
        <input type="hidden" name="return_to" value="duplicate_vendors.php?<?= htmlspecialchars($qs([])) ?>">
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold">
          <i class="fas fa-ban me-1"></i> Nonaktifkan
        </button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('deactModal');
    if (!el) return;
    var modal = new bootstrap.Modal(el);
    var nameEl = document.getElementById('deactName'),
        idEl   = document.getElementById('deactId'),
        warn   = document.getElementById('deactKeepWarn');

    document.querySelectorAll('.btn-deact').forEach(function (b) {
        b.addEventListener('click', function () {
            nameEl.textContent = b.dataset.name;
            idEl.value = b.dataset.id;
            // Switching off the survivor is legitimate but rarely intended, so
            // it is called out rather than silently allowed.
            warn.classList.toggle('d-none', b.dataset.keep !== '1');
            modal.show();
        });
    });
});
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>
