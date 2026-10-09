<?php
// tools/audit_duplicate_vendors.php
//
// Find vendors that are the same real company registered more than once, and
// say which row would be kept and which would be removed.
//
// Registration only ever de-duplicated on email, so one company could register
// once per mailbox it owned. This tool clusters the register by fuzzy company
// name (includes/vendor_name_similarity.php — the same matcher the registration
// gate uses) and ranks each cluster by how far the vendor got through the
// pipeline. The furthest-along row is the keeper; the rest are the duplicates.
//
// SAFETY: READ-ONLY. SELECTs only — there is deliberately no --apply flag here.
// Removing the rows it lists is a separate, explicitly-gated tool; this one only
// ever tells you what such a run would do.
//
// Targets the VSS / vmsportal database via the app's own config.php.
//
// Usage:
//   docker-compose exec app php tools/audit_duplicate_vendors.php
//   ... --threshold=97        stricter name matching (default 95)
//   ... --verbose             show every column that drives the keep decision
//   ... --csv                 write the plan to db_backups/local_runtime_exports/
//   ... --html                write a browsable review page to the same folder

if (php_sapi_name() !== 'cli') exit("CLI only.\n");

$threshold = 95.0;
$verbose   = in_array('--verbose', $argv, true);
$wantCsv   = in_array('--csv', $argv, true);
$wantHtml  = in_array('--html', $argv, true);

// --word turns on the second matching signal: whole-word containment, for the
// duplicates a character score structurally cannot see ("PT MITRA PRINTING" vs
// "CV MITRA ABADI PRINTING" is capped at 85% on length alone). Off by default so
// the default output stays the character-only list.
$useWord   = in_array('--word', $argv, true);
$wordOnly  = in_array('--word-only', $argv, true);   // show ONLY what --word adds
if ($wordOnly) $useWord = true;
$maxExtra  = 1;   // how many words the longer name may add and still be "the same"
foreach ($argv as $arg) {
    if (preg_match('/^--threshold=([\d.]+)$/', $arg, $m)) {
        $threshold = (float) $m[1];
    }
    if (preg_match('/^--max-extra=(\d+)$/', $arg, $m)) {
        $maxExtra = (int) $m[1];
    }
}

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/vendor_name_similarity.php';

if (!($conn instanceof mysqli)) {
    exit("No database connection.\n");
}

// ---------------------------------------------------------------------------
// Cluster and rank
// ---------------------------------------------------------------------------
// The clustering, the pipeline ranking and the keeper choice all live in
// includes/vendor_duplicate_audit.php, shared with the Vendor Management screen
// (modules/vendormgt/duplicate_vendors.php). Two implementations of "which row
// survives" would eventually disagree, and they would disagree about live vendor
// accounts. Everything below this point is presentation only.
require_once __DIR__ . '/../includes/vendor_duplicate_audit.php';

$audit = vendor_dup_build_plan($conn, [
    'threshold' => $threshold,
    'use_word'  => $useWord,
    'max_extra' => $maxExtra,
    'word_only' => $wordOnly,
]);

$plan          = $audit['plan'];
$total         = $audit['total'];
$totalToRemove = $audit['flagged'];
$totalOrphans  = $audit['orphan_rows'];
$UNRULED       = VENDOR_DUP_UNRULED;

// Flatten each link's reason to the label string the report, CSV and HTML print.
$pairScores = [];
foreach ($audit['pair_reason'] as $aId => $row) {
    foreach ($row as $bId => $reason) {
        $pairScores[$aId][$bId] = $reason['label'];
    }
}

$needsRuling = [];
foreach ($plan as $entry) {
    if ($entry['decisive']) { $needsRuling[] = $entry['keeper']['company_name']; }
}

echo "Loaded {$total} vendors. Matching at >= {$threshold}% name similarity.\n";
echo "Scored {$audit['compared']} candidate pairs (of "
   . number_format($total * ($total - 1) / 2) . " possible).\n";
echo "Linked: {$audit['char_pairs']} by character similarity"
   . ($useWord
        ? ", {$audit['word_pairs']} by word containment (max +{$maxExtra} extra word"
          . ($maxExtra === 1 ? '' : 's') . ")"
        : '')
   . ".\n\n";

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
$fmt = static function (array $v, string $mark) use ($pairScores): string {
    $line = sprintf(
        "   %s id=%-5d %-42s %-24s %s",
        $mark,
        $v['id'],
        mb_substr($v['company_name'], 0, 42),
        $v['registration_status'],
        $v['email'] ?: '(no email)'
    );
    if ($v['child_total'] > 0) {
        $bits = [];
        foreach ($v['children'] as $t => $c) { $bits[] = "{$t}:{$c}"; }
        $line .= "\n           dependent rows -> " . implode(', ', $bits);
    }
    return $line;
};

echo str_repeat('=', 100) . "\n";
echo "DUPLICATE CLUSTERS: " . count($plan) . "\n";
echo str_repeat('=', 100) . "\n\n";

foreach ($plan as $i => $p) {
    $flag = $p['decisive'] ? '  [!] outcome depends on an unruled status' : '';
    echo sprintf("#%-3d %s%s\n", $i + 1, $p['keeper']['company_name'], $flag);
    echo $fmt($p['keeper'], 'KEEP') . "\n";
    foreach ($p['drops'] as $d) {
        $why = $pairScores[$p['keeper']['id']][$d['id']] ?? 'linked transitively';
        echo $fmt($d, 'DROP') . "  [{$why}]\n";
    }
    echo "\n";
}

echo str_repeat('=', 100) . "\n";
echo "SUMMARY\n";
echo str_repeat('=', 100) . "\n";
echo "  vendors in register        : {$total}\n";
echo "  duplicate clusters         : " . count($plan) . "\n";
echo "  rows that would be removed : {$totalToRemove}\n";
echo "  dependent rows on those    : {$totalOrphans}   <- orphaned by a bare DELETE (no FKs exist)\n";
if ($needsRuling) {
    echo "  clusters needing a ruling  : " . count($needsRuling) . "  (statuses: " . implode(', ', $UNRULED) . ")\n";
}
echo "\nREAD-ONLY: nothing was written.\n";

if ($wantCsv) {
    $dir = __DIR__ . '/../db_backups/local_runtime_exports';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $path = $dir . '/duplicate_vendors_' . date('Ymd_His') . '.csv';
    $fh = fopen($path, 'w');
    fputcsv($fh, ['cluster', 'action', 'vendor_id', 'company_name', 'registration_status', 'email', 'vendor_code', 'dependent_rows', 'linked_by']);
    foreach ($plan as $i => $p) {
        fputcsv($fh, [$i + 1, 'KEEP', $p['keeper']['id'], $p['keeper']['company_name'], $p['keeper']['registration_status'], $p['keeper']['email'], $p['keeper']['vendor_code'], $p['keeper']['child_total'], '']);
        foreach ($p['drops'] as $d) {
            fputcsv($fh, [$i + 1, 'DROP', $d['id'], $d['company_name'], $d['registration_status'], $d['email'], $d['vendor_code'], $d['child_total'], $pairScores[$p['keeper']['id']][$d['id']] ?? '']);
        }
    }
    fclose($fh);
    echo "CSV written: {$path}\n";
}

if ($wantHtml) {
    // A page for the person who has to rule on 36 clusters, not a prettier dump.
    // Every row carries the three things the decision turns on — how far through
    // the pipeline it got, what history hangs off it, and how the name was
    // matched — and the low-confidence clusters can be isolated in one click,
    // because those are the only ones that really need a human.
    $dir = __DIR__ . '/../db_backups/local_runtime_exports';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $path = $dir . '/duplicate_vendors_' . date('Ymd_His') . '.html';

    $h = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    // "Word-linked" = at least one row was tied in by shared words rather than
    // spelling. Those are the roughly half-precise ones; MAKMUR JAYA against
    // PT Yoga Jaya Makmur is in this group.
    $isWordCluster = static function (array $p) use ($pairScores): bool {
        foreach ($p['drops'] as $d) {
            $why = $pairScores[$p['keeper']['id']][$d['id']] ?? '';
            if (strpos($why, 'same words') !== false) { return true; }
        }
        return false;
    };

    $wordClusters = 0;
    foreach ($plan as $p) { if ($isWordCluster($p)) { $wordClusters++; } }

    $rowHtml = static function (array $v, bool $keep, string $why) use ($h): string {
        $kids = [];
        foreach ($v['children'] as $t => $c) { $kids[] = $h($t . ': ' . $c); }
        $kidTxt = $kids ? implode(' &middot; ', $kids) : '<span class="muted">no dependent rows</span>';
        $badge  = $keep ? '<span class="tag keep">KEEP</span>' : '<span class="tag drop">DROP</span>';
        $whyTxt = $why !== '' ? '<span class="why">' . $h($why) . '</span>' : '';
        return '<tr class="' . ($keep ? 'r-keep' : 'r-drop') . '">'
            . '<td>' . $badge . '</td>'
            . '<td class="id">' . (int) $v['id'] . '</td>'
            . '<td class="nm">' . $h($v['company_name']) . $whyTxt . '</td>'
            . '<td><span class="st">' . $h($v['registration_status']) . '</span></td>'
            . '<td class="em">' . $h($v['email'] !== '' ? $v['email'] : '-') . '</td>'
            . '<td class="kid">' . $kidTxt . '</td>'
            . '</tr>';
    };

    ob_start();
    ?>
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Duplicate Vendors - VSS</title>
<style>
  :root{--bg:#f6f7f9;--card:#fff;--ink:#1a1d21;--muted:#6b7280;--line:#e5e7eb;
        --keep:#0f7b3d;--keepbg:#e8f6ee;--drop:#b42318;--warn:#b25e09;--warnbg:#fff4e5}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);
       font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:28px 20px 60px}
  .wrap{max-width:1180px;margin:0 auto}
  h1{font-size:22px;margin:0 0 4px}
  .sub{color:var(--muted);margin:0 0 20px}
  .stats{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:18px}
  .stat{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:10px 16px;min-width:132px}
  .stat b{display:block;font-size:22px;line-height:1.2}
  .stat span{color:var(--muted);font-size:12px}
  .note{background:var(--warnbg);border:1px solid #f5d9ae;border-radius:12px;padding:12px 16px;margin-bottom:18px}
  .note b{color:var(--warn)}
  .bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:16px;position:sticky;top:0;
       background:var(--bg);padding:10px 0;z-index:5;border-bottom:1px solid var(--line)}
  input[type=search]{flex:1;min-width:220px;padding:9px 13px;border:1px solid var(--line);border-radius:9px;font-size:14px}
  label.chk{display:flex;align-items:center;gap:7px;background:var(--card);border:1px solid var(--line);
            border-radius:9px;padding:8px 13px;cursor:pointer;user-select:none;white-space:nowrap}
  .cl{background:var(--card);border:1px solid var(--line);border-radius:14px;margin-bottom:14px;overflow:hidden}
  .cl.hide{display:none}
  .clh{display:flex;justify-content:space-between;align-items:center;gap:12px;
       padding:12px 16px;border-bottom:1px solid var(--line);background:#fafbfc}
  .clh h2{font-size:15px;margin:0;font-weight:700}
  .num{color:var(--muted);font-weight:400;margin-right:8px}
  table{width:100%;border-collapse:collapse}
  td{padding:10px 12px;border-top:1px solid #f1f2f4;vertical-align:top}
  tr:first-child td{border-top:0}
  .r-keep{background:var(--keepbg)}
  .tag{display:inline-block;font-size:11px;font-weight:800;letter-spacing:.4px;padding:3px 8px;border-radius:6px}
  .tag.keep{background:var(--keep);color:#fff}
  .tag.drop{background:var(--drop);color:#fff}
  .id{color:var(--muted);font-variant-numeric:tabular-nums;width:56px}
  .nm{font-weight:600;min-width:230px}
  .why{display:block;font-weight:400;font-size:11.5px;color:var(--muted);margin-top:2px}
  .st{background:#eef1f5;border-radius:6px;padding:2px 8px;font-size:11.5px;white-space:nowrap}
  .em{color:var(--muted);font-size:12.5px;word-break:break-all;min-width:170px}
  .kid{color:var(--muted);font-size:11.5px;min-width:150px}
  .muted{color:#9aa1ab}
  .flag{display:inline-block;background:var(--warnbg);color:var(--warn);border:1px solid #f0cf9f;
        border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700;white-space:nowrap}
  .empty{padding:26px;text-align:center;color:var(--muted)}
  footer{color:var(--muted);font-size:12px;margin-top:26px;text-align:center}
  @media print{.bar{display:none}.cl{break-inside:avoid}}
</style></head><body><div class="wrap">

<h1>Duplicate Vendors</h1>
<p class="sub">VSS register &middot; generated <?= date('d M Y, H:i') ?> &middot; read-only, nothing has been deleted</p>

<div class="stats">
  <div class="stat"><b><?= (int) $total ?></b><span>vendors in register</span></div>
  <div class="stat"><b><?= count($plan) ?></b><span>duplicate clusters</span></div>
  <div class="stat"><b><?= (int) $totalToRemove ?></b><span>rows flagged DROP</span></div>
  <div class="stat"><b><?= (int) $totalOrphans ?></b><span>dependent rows on them</span></div>
  <div class="stat"><b><?= (int) $wordClusters ?></b><span>need a human check</span></div>
</div>

<div class="note">
  <b>KEEP is chosen by pipeline stage</b>, furthest along wins:
  approved &rsaquo; under review VM / revision &rsaquo; under review Sourcing / revision &rsaquo;
  PQ submitted &rsaquo; full profile &rsaquo; migrated &rsaquo; registered.
  Ties break to the row carrying the most history, then to the oldest row.
  <br><b>Start with the amber clusters</b> - those were linked by shared words rather than
  spelling, and about half of them turn out to be genuinely different companies.
</div>

<div class="bar">
  <input type="search" id="q" placeholder="Search company name, email, vendor id or status...">
  <label class="chk"><input type="checkbox" id="onlyWord"> Only clusters needing a check</label>
  <label class="chk"><input type="checkbox" id="onlyDeps"> Only clusters with dependent rows</label>
</div>

<div id="list">
<?php foreach ($plan as $i => $p):
    $word = $isWordCluster($p);
    $deps = 0; foreach ($p['drops'] as $d) { $deps += $d['child_total']; }
?>
  <div class="cl" data-word="<?= $word ? 1 : 0 ?>" data-deps="<?= (int) $deps ?>">
    <div class="clh">
      <h2><span class="num">#<?= $i + 1 ?></span><?= $h($p['keeper']['company_name']) ?></h2>
      <div>
        <?php if ($word): ?><span class="flag">word match - verify</span><?php endif; ?>
        <?php if ($p['decisive']): ?><span class="flag">unruled status</span><?php endif; ?>
      </div>
    </div>
    <table>
      <?= $rowHtml($p['keeper'], true, '') ?>
      <?php foreach ($p['drops'] as $d): ?>
        <?= $rowHtml($d, false, $pairScores[$p['keeper']['id']][$d['id']] ?? 'linked transitively') ?>
      <?php endforeach; ?>
    </table>
  </div>
<?php endforeach; ?>
</div>
<div class="empty" id="none" style="display:none">Nothing matches those filters.</div>

<footer>tools/audit_duplicate_vendors.php &middot; READ-ONLY &middot; no rows were changed</footer>

<script>
  var q = document.getElementById('q'),
      w = document.getElementById('onlyWord'),
      dp = document.getElementById('onlyDeps'),
      cls = [].slice.call(document.querySelectorAll('.cl')),
      none = document.getElementById('none');
  function apply(){
    var t = q.value.trim().toLowerCase(), shown = 0;
    cls.forEach(function(c){
      var ok = (!t || c.textContent.toLowerCase().indexOf(t) > -1)
            && (!w.checked || c.dataset.word === '1')
            && (!dp.checked || +c.dataset.deps > 0);
      c.classList.toggle('hide', !ok);
      if (ok) { shown++; }
    });
    none.style.display = shown ? 'none' : '';
  }
  q.addEventListener('input', apply);
  w.addEventListener('change', apply);
  dp.addEventListener('change', apply);
</script>
</div></body></html>
    <?php
    file_put_contents($path, ob_get_clean());
    echo "HTML written: {$path}\n";
}
