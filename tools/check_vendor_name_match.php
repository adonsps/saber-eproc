<?php
// tools/check_vendor_name_match.php
//
// Ask what the matcher actually thinks of a company name: type a name, get the
// nearest vendors already in the register, with every score that goes into the
// decision. Built to answer "why didn't this one trigger?" without guessing.
//
// Two independent signals are reported, because they catch different things:
//
//   CHAR  similar_text() percentage (includes/vendor_name_similarity.php).
//         Catches typos, punctuation and case: "PT. ARSINSI" vs "PT ARSINSI".
//         Blind to a whole inserted word — two names of different length are
//         capped at 2*shorter/(sum) before a single character is compared, so
//         "PT MITRA PRINTING" vs "CV MITRA ABADI PRINTING" cannot exceed 85%.
//
//   WORD  containment of the shorter name's distinctive words in the longer,
//         plus how many words the longer one adds. Catches exactly the case CHAR
//         cannot, and is the signal that pairs the MITRA PRINTING names.
//
// SAFETY: READ-ONLY. SELECTs only; no --apply flag exists and nothing is written.
//
// Usage:
//   docker-compose exec app php tools/check_vendor_name_match.php "PT MITRA PRINTING"
//   ... --top=25          how many nearest names to list (default 12)
//   ... --min=70          hide anything scoring under this on BOTH signals

if (php_sapi_name() !== 'cli') exit("CLI only.\n");

$name = null;
$top  = 12;
$min  = 60.0;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--top=(\d+)$/', $arg, $m))        { $top = (int) $m[1]; continue; }
    if (preg_match('/^--min=([\d.]+)$/', $arg, $m))     { $min = (float) $m[1]; continue; }
    if (str_starts_with($arg, '--')) continue;
    $name = $arg;
}
if ($name === null || trim($name) === '') {
    exit("Usage: php tools/check_vendor_name_match.php \"PT SOME COMPANY\" [--top=N] [--min=P]\n");
}

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/vendor_name_similarity.php';

if (!($conn instanceof mysqli)) exit("No database connection.\n");

$norm = vendor_name_normalize($name);
echo "\nCandidate : {$name}\n";
echo "Normalised: {$norm}\n";
echo "Words     : [" . implode(', ', vendor_name_core_tokens($norm)) . "]  (legal form dropped)\n\n";

$rows = [];
$res = $conn->query(
    "SELECT id, company_name, email, registration_status
       FROM vendors
      WHERE company_name IS NOT NULL AND company_name <> ''"
);
while ($r = $res->fetch_assoc()) {
    $otherNorm = vendor_name_normalize($r['company_name']);
    if ($otherNorm === '' || $otherNorm === $norm && (int) $r['id'] === 0) continue;

    $char = vendor_name_similarity($norm, $otherNorm);
    $word = vendor_name_token_overlap($norm, $otherNorm);

    if ($char < $min && $word['pct'] < $min) continue;

    $rows[] = [
        'id'     => (int) $r['id'],
        'name'   => $r['company_name'],
        'status' => $r['registration_status'],
        'email'  => $r['email'],
        'char'   => $char,
        'word'   => $word,
        // Ceiling the character score could ever reach for these two lengths —
        // shows at a glance when CHAR was doomed by length, not by spelling.
        'cap'    => 2.0 * min(strlen($norm), strlen($otherNorm))
                        / (strlen($norm) + strlen($otherNorm)) * 100.0,
    ];
}

usort($rows, static fn($a, $b) => max($b['char'], $b['word']['pct']) <=> max($a['char'], $a['word']['pct']));
$rows = array_slice($rows, 0, $top);

if (!$rows) {
    echo "No vendor scores at least {$min}% on either signal.\n\n";
    exit(0);
}

printf("%-5s  %-40s  %-22s  %6s %6s  %6s %-14s\n",
    'ID', 'COMPANY NAME', 'STAGE', 'CHAR', '(cap)', 'WORD', 'WORD DETAIL');
echo str_repeat('-', 118) . "\n";

foreach ($rows as $r) {
    $w = $r['word'];
    $detail = sprintf('%d shared, +%d', $w['shared'], $w['extra']);

    // What each rule-of-thumb would say, so the gap between them is visible.
    $charHit = $r['char'] >= VENDOR_NAME_MATCH_THRESHOLD;
    $wordHit = $w['pct'] >= 100.0 && $w['shared'] >= 2 && $w['extra'] <= 1;
    $mark = $charHit ? ' <= CHAR match' : ($wordHit ? ' <= WORD match only' : '');

    printf("%-5d  %-40s  %-22s  %5.1f%% %5.1f%%  %5.1f%% %-14s%s\n",
        $r['id'],
        mb_substr($r['name'], 0, 40),
        $r['status'],
        $r['char'],
        $r['cap'],
        $w['pct'],
        $detail,
        $mark
    );
    printf("       %s\n", vendor_name_mask_email($r['email']));
}

echo "\nCHAR threshold in force: " . VENDOR_NAME_MATCH_THRESHOLD . "%\n";
echo "WORD rule shown above: containment 100%, at least 2 shared words, at most 1 extra word.\n";
echo "READ-ONLY: nothing was written.\n\n";
