<?php
// tools/promote_pq_draft_submission.php
//
// Find — and optionally repair — PQ submissions that sit at status 'draft' while
// the vendor record already says the PQ was submitted.
//
// Background: modules/vendor/submit_pq.php used to accept a POST with no check
// on the current submission status. A form page a vendor still had open (back
// button, second tab, double submit) could therefore POST a "Simpan" long after
// the PQ had been finally submitted. That re-wrote pq_answers and pushed
// pq_submissions.status back to 'draft', while vendors.registration_status
// stayed 'pq_submitted' — because submit_pq.php only touches registration_status
// on a final submit. The two columns then disagree:
//
//   * modules/sourcing/dashboard.php reads vendors.registration_status
//     -> badge says "PQ SUBMITTED" and the "Review PQ" button is offered
//   * modules/sourcing/review_pq.php:147 reads pq_submissions.status
//     -> 'draft' is not in the unlock list, so the assessment renders locked
//
// The vendor cannot fix it either: pq_form.php redirects them away once a
// submission exists and registration_status is 'pq_submitted'. So the record is
// stuck until something promotes the submission. That is what --apply does.
//
// The guard that prevents new occurrences lives in submit_pq.php; this tool is
// only for records already in that state.
//
// WHAT --apply DOES (one vendor at a time)
//   pq_submissions.status : 'draft' -> 'under_review_sourcing'
//   vendors.registration_status : forced to 'pq_submitted' if it drifted
//   Nothing else. Answers, attachments, scores and submitted_at are left exactly
//   as they are — the answers currently on record become the submitted PQ, which
//   is the same thing "Kirim Form" would have produced. Sourcing scores it as
//   usual in review_pq.php / update_assessment.php.
//
// SAFETY
//   * Read-only unless --apply is passed.
//   * --apply requires --vendor=<id> AND --confirm-db=<database name>, and the
//     name must match DATABASE() on the live connection.
//   * Every row it is about to change is dumped to a JSON backup under
//     db_backups/local_runtime_backups/ before the UPDATE runs.
//   * The two UPDATEs run in one transaction and are re-read afterwards.
//
// Targets the VSS / vmsportal database via the app's own config.php.
//
// Usage:
//   Audit everything (read-only):
//     docker-compose exec app php tools/promote_pq_draft_submission.php
//   Audit one vendor:
//     ... --vendor=859
//   Repair one vendor:
//     ... --vendor=859 --apply --confirm-db=u894129685_vmsportal
//   On the production VPS:
//     docker exec $(docker ps --format '{{.Names}}' | grep vms_prod_app) \
//       php /var/www/html/tools/promote_pq_draft_submission.php --vendor=859

if (php_sapi_name() !== 'cli') exit("CLI only.\n");

// ---------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------
$onlyVendor = null;
$confirmDb  = null;
$doApply    = in_array('--apply', $argv, true);

foreach ($argv as $a) {
    if (str_starts_with($a, '--vendor='))     $onlyVendor = (int) substr($a, 9);
    if (str_starts_with($a, '--confirm-db=')) $confirmDb  = substr($a, 13);
    if ($a === '--help' || $a === '-h') {
        echo "See the header of " . basename(__FILE__) . " for usage.\n";
        exit(0);
    }
}

require __DIR__ . '/../config.php';   // $conn (mysqli, vmsportal)

// ---------------------------------------------------------------------------
// Identity banner. USER() is user@host — not a secret; the password is never
// read or printed.
// ---------------------------------------------------------------------------
$id = $conn->query("SELECT DATABASE() AS d, USER() AS u, @@hostname AS h, VERSION() AS v")->fetch_assoc();
echo "----------------------------------------------------------------\n";
echo "PQ draft/submitted desync  (" . ($doApply ? "APPLY — WILL WRITE" : "READ-ONLY audit") . ")\n";
echo "  DATABASE : {$id['d']}\n";
echo "  USER     : {$id['u']}\n";
echo "  HOSTNAME : {$id['h']}\n";
echo "  VERSION  : {$id['v']}\n";
echo "----------------------------------------------------------------\n\n";

// ---------------------------------------------------------------------------
// A 'draft' submission is legitimate while the vendor still owns the form.
// Anything past that means the two columns disagree.
// ---------------------------------------------------------------------------
$legit_draft_reg_statuses = [
    'registration_submitted', 'registered', 'min_info_submitted',
    'invited_pq', 'migrated', 'draft', 'revision_requested',
];

$sql = "SELECT v.id                AS vendor_id,
               v.company_name,
               v.registration_status,
               v.sub_company_type,
               s.id                AS submission_id,
               s.status            AS submission_status,
               s.submitted_at,
               s.total_weighted_score,
               s.max_possible_weighted_score,
               (SELECT COUNT(*) FROM pq_answers a
                 WHERE a.submission_id = s.id)                              AS answers,
               (SELECT COUNT(*) FROM pq_answers a
                 WHERE a.submission_id = s.id AND a.attachment_path IS NOT NULL
                   AND a.attachment_path <> '')                             AS attachments,
               (SELECT COUNT(*) FROM pq_submissions s2
                 WHERE s2.vendor_id = v.id)                                 AS submission_count
          FROM vendors v
          JOIN pq_submissions s
            ON s.id = (SELECT MAX(s2.id) FROM pq_submissions s2 WHERE s2.vendor_id = v.id)
         WHERE s.status = 'draft'";
if ($onlyVendor) $sql .= " AND v.id = " . (int) $onlyVendor;
$sql .= " ORDER BY s.id DESC";

$rows = [];
$res  = $conn->query($sql);
while ($r = $res->fetch_assoc()) $rows[] = $r;

if (!$rows) {
    echo $onlyVendor
        ? "Vendor {$onlyVendor}: no submission, or its latest submission is not a draft.\n"
        : "No draft submissions found at all.\n";
    exit(0);
}

$desynced = [];
foreach ($rows as $r) {
    $bad = !in_array($r['registration_status'], $legit_draft_reg_statuses, true);
    if ($bad) $desynced[] = $r;

    printf(
        "%s vendor %-6s %-40s reg=%-22s sub#%-6s status=%-8s answers=%-4s files=%-4s subs=%s\n",
        $bad ? 'DESYNCED' : 'ok      ',
        $r['vendor_id'],
        mb_substr($r['company_name'] ?? '', 0, 40),
        $r['registration_status'],
        $r['submission_id'],
        $r['submission_status'],
        $r['answers'],
        $r['attachments'],
        $r['submission_count']
    );
}

echo "\n" . count($rows) . " draft submission(s) checked, " . count($desynced) . " desynced.\n";

if (!$doApply) {
    if ($desynced) {
        echo "\nTo repair one of them (writes to the database):\n";
        echo "  php " . basename(__FILE__) . " --vendor=" . $desynced[0]['vendor_id']
             . " --apply --confirm-db=" . $id['d'] . "\n";
        echo "\nCheck 'answers' above first — that count is what will become the\n";
        echo "submitted PQ. If it is 0 the draft is empty and promoting it would\n";
        echo "hand Sourcing a blank form; reset the vendor instead so they refill.\n";
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// --apply: gates
// ---------------------------------------------------------------------------
if (!$onlyVendor) {
    echo "ABORT: --apply requires --vendor=<id>. One vendor at a time, on purpose.\n";
    exit(2);
}
if ($confirmDb === null || $confirmDb !== $id['d']) {
    echo "ABORT: --apply requires --confirm-db=<database name> matching DATABASE().\n";
    echo "       This connection is on '{$id['d']}'.\n";
    exit(2);
}
if (count($rows) !== 1) {
    echo "ABORT: expected exactly one row for vendor {$onlyVendor}, got " . count($rows) . ".\n";
    exit(2);
}

$row = $rows[0];
if (!in_array($row, $desynced, true)) {
    echo "ABORT: vendor {$onlyVendor} is not desynced — registration_status is\n";
    echo "       '{$row['registration_status']}', which legitimately owns a draft.\n";
    echo "       Nothing to repair; the vendor can still finish the form themselves.\n";
    exit(2);
}
if ((int) $row['answers'] === 0) {
    echo "ABORT: submission #{$row['submission_id']} has 0 answers. Promoting an empty\n";
    echo "       draft would put a blank PQ in front of Sourcing. Use Reset PQ so the\n";
    echo "       vendor refills instead.\n";
    exit(2);
}

// ---------------------------------------------------------------------------
// --apply: backup, then write
// ---------------------------------------------------------------------------
$backup_dir = __DIR__ . '/../db_backups/local_runtime_backups';
if (!is_dir($backup_dir)) mkdir($backup_dir, 0775, true);
$backup_file = $backup_dir . '/pq_promote_v' . $row['vendor_id'] . '_' . date('Ymd_His') . '.json';

$backup = [
    'generated_at' => date('c'),
    'database'     => $id['d'],
    'tool'         => basename(__FILE__),
    'before'       => $row,
    'answers'      => [],
];
$ba = $conn->query("SELECT * FROM pq_answers WHERE submission_id = " . (int) $row['submission_id']);
while ($a = $ba->fetch_assoc()) $backup['answers'][] = $a;

if (file_put_contents($backup_file, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
    echo "ABORT: could not write backup to {$backup_file}.\n";
    exit(2);
}
echo "\nBackup written: {$backup_file}\n";
echo "  (" . count($backup['answers']) . " answer rows captured)\n";

$conn->begin_transaction();
try {
    $sid = (int) $row['submission_id'];
    $vid = (int) $row['vendor_id'];

    // Exactly what a real "Kirim Form" produces. submitted_at is deliberately
    // left alone — it already carries the genuine submission timestamp, and
    // rewriting it would distort the SLA/aging figures.
    $conn->query("UPDATE pq_submissions SET status = 'under_review_sourcing' WHERE id = {$sid}");
    $conn->query("UPDATE vendors SET registration_status = 'pq_submitted' WHERE id = {$vid}");

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    echo "ROLLED BACK: " . $e->getMessage() . "\n";
    exit(1);
}

$after = $conn->query(
    "SELECT v.registration_status, s.id AS submission_id, s.status
       FROM vendors v JOIN pq_submissions s ON s.vendor_id = v.id
      WHERE v.id = " . (int) $row['vendor_id'] . " AND s.id = " . (int) $row['submission_id']
)->fetch_assoc();

echo "\nAPPLIED.\n";
echo "  vendor {$row['vendor_id']} registration_status : {$row['registration_status']} -> {$after['registration_status']}\n";
echo "  submission #{$after['submission_id']} status          : {$row['submission_status']} -> {$after['status']}\n";
echo "\nSourcing can now open modules/sourcing/review_pq.php?id={$row['vendor_id']} and assess it.\n";
