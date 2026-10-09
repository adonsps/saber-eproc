<?php
// tools/audit_lost_vendor_documents.php
//
// Audit which vendor documents were lost by the pre-fix upload-path bug, and
// state, per vendor, exactly what has to happen to get them back.
//
// Background: until commit b1b179b (PR #171) several upload handlers wrote to
// <docroot>/../uploads, which under Docker resolves to /var/www — root-owned,
// unwritable by www-data, and absent. move_uploaded_file() returned false, the
// return value was never checked, and the UI reported success. Files uploaded
// through those screens between the VPS cutover (~2026-07-29) and the fix were
// silently discarded. Vendor profile documents were fixed earlier, by 699066a
// on 2026-08-11, so their exposure window is shorter.
//
// WHAT CAN AND CANNOT BE RECOVERED
//   * UPLOADED files (vendor-supplied) are NOT recoverable. The bytes were
//     never written to any disk and PHP deleted the temp file at request end;
//     they are not in the volume, not in /var/www, not in the nightly backup.
//     The vendor must re-submit. This tool tells you who, and how.
//   * GENERATED files (PQ Result certificates, Exception Request approval PDFs)
//     ARE recoverable, because they are rebuilt from data still in the DB.
//     See tools/regenerate_pq_result_pdfs.php for the certificate backfill.
//
// SAFETY: READ-ONLY. This tool issues SELECTs only and never writes to the
// database — there is deliberately no --apply flag. The only thing it can write
// is a CSV report, and only when --csv is passed.
//
// Targets the VSS / vmsportal database via the app's own config.php. Loading
// config.php runs the app's standard idempotent bootstrap migrations — the same
// ones any normal page load runs — and nothing more.
//
// Usage:
//   Report (default window, from the VPS cutover):
//     docker-compose exec app php tools/audit_lost_vendor_documents.php
//   On the production VPS:
//     docker exec $(docker ps --format '{{.Names}}' | grep vms_prod_app) \
//       php /var/www/html/tools/audit_lost_vendor_documents.php
//   Only the vendors actually worth contacting (HIGH + MEDIUM tiers):
//     ... --priority
//   Different window / single vendor / include healthy rows:
//     ... --since=2026-07-01   ... --vendor=1483   ... --include-ok
//   Write the outreach list to CSV (db_backups/local_runtime_exports/):
//     ... --csv

if (php_sapi_name() !== 'cli') exit("CLI only.\n");

// ---------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------
$since       = '2026-07-29';   // VPS/Docker cutover; earliest plausible impact
$onlyVendor  = null;
$wantCsv     = false;
$csvPath     = null;
$includeOk    = in_array('--include-ok', $argv, true);
$priorityOnly = in_array('--priority', $argv, true);

foreach ($argv as $a) {
    if (str_starts_with($a, '--since='))  $since      = substr($a, 8);
    if (str_starts_with($a, '--vendor=')) $onlyVendor = (int) substr($a, 9);
    if ($a === '--csv')                   $wantCsv    = true;
    if (str_starts_with($a, '--csv='))  { $wantCsv    = true; $csvPath = substr($a, 6); }
    if ($a === '--help' || $a === '-h') {
        fwrite(STDERR, "See the header of " . basename(__FILE__) . " for usage.\n");
        exit(0);
    }
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
    fwrite(STDERR, "ABORT: --since must be YYYY-MM-DD, got '{$since}'.\n");
    exit(2);
}

require __DIR__ . '/../config.php';                       // $conn (mysqli, vmsportal)
require_once __DIR__ . '/../includes/upload_paths.php';   // shared upload-base resolver

// ---------------------------------------------------------------------------
// Identity banner. USER() is user@host — not a secret; the password is never
// read or printed.
// ---------------------------------------------------------------------------
$id = $conn->query("SELECT DATABASE() AS d, USER() AS u, @@hostname AS h, VERSION() AS v")->fetch_assoc();
fwrite(STDERR, "----------------------------------------------------------------\n");
fwrite(STDERR, "Lost vendor document audit  (READ-ONLY, no DB writes)\n");
fwrite(STDERR, "  DATABASE : {$id['d']}\n");
fwrite(STDERR, "  USER     : {$id['u']}\n");
fwrite(STDERR, "  HOSTNAME : {$id['h']}\n");
fwrite(STDERR, "  VERSION  : {$id['v']}\n");
fwrite(STDERR, "  SINCE    : {$since}\n");
fwrite(STDERR, "  BASES    : " . (implode(', ', vendor_upload_bases()) ?: '(none found!)') . "\n");
fwrite(STDERR, "----------------------------------------------------------------\n\n");

if (!vendor_upload_bases()) {
    fwrite(STDERR, "ABORT: no uploads directory visible from here. Run this inside the app\n");
    fwrite(STDERR, "       container so the uploads volume is mounted, or file checks would\n");
    fwrite(STDERR, "       report every document as missing.\n");
    exit(3);
}

// ---------------------------------------------------------------------------
// The vendor-supplied document columns, in vendor-facing terms.
// 'always' documents are required of everyone; 'badan_usaha' only of legal
// entities (pq_form.php:23-39 gatekeeper).
// ---------------------------------------------------------------------------
// 'optional' documents come from the single "Profil Perusahaan (Opsional)" file
// input on register.php — genuinely optional and skipped by most vendors, so
// their absence is NOT evidence of loss and must not drive outreach. One upload
// populates both registration_file and company_profile_file, so skipping it
// shows up as two absences; that is what inflated the first run's numbers.
$docs = [
    'registration_file'               => ['label' => 'Dokumen Registrasi',  'when' => 'optional'],
    'company_profile_file'            => ['label' => 'Company Profile',     'when' => 'optional'],
    'director_id_card_file'           => ['label' => 'KTP Direktur',        'when' => 'always'],
    'bank_cover_path'                 => ['label' => 'Buku Tabungan',       'when' => 'always'],
    'npwp_path'                       => ['label' => 'NPWP',                'when' => 'always'],
    'business_ethics_commitment_file' => ['label' => 'Pakta Integritas',    'when' => 'always'],
    'tax_statement_letter_file'       => ['label' => 'Tax Statement Letter', 'when' => 'always'],
    'nib_path'                        => ['label' => 'NIB',                 'when' => 'badan_usaha'],
    'deed_of_establishment_file'      => ['label' => 'Akta Pendirian',      'when' => 'badan_usaha'],
    'decree_of_legal_entity_file'     => ['label' => 'SK Kemenkumham',      'when' => 'badan_usaha'],
];

// Statuses that freeze the profile — vendor cannot self-serve while in these.
// Mirrors vendor_profile_locked_statuses() in includes/vendor_context.php.
$lockedStatuses = ['pq_submitted', 'under_review_vm', 'under_review_vm_head',
                   'returned_to_sourcing', 'rejected'];

// ---------------------------------------------------------------------------
// Pull candidate vendors. 'migrated' rows are bulk-imported legacy master data
// that never had documents in this system — counting them would wildly
// overstate the damage, so they are excluded.
// ---------------------------------------------------------------------------
// A vendor is in scope if ANY of its document-writing activity falls in the
// window — not just registration. Filtering on created_at alone (the first
// version of this tool) silently excluded the worst-affected group: vendors
// onboarded months earlier who submitted a PQ or updated their profile during
// the outage.
$cols = implode(", ", array_map(fn($c) => "v.`$c`", array_keys($docs)));
$sql = "SELECT v.id, v.company_name, v.registration_status, v.legal_entity_type,
               v.company_type, v.created_at, v.updated_at, $cols,
               (SELECT u.email FROM users u
                 WHERE u.vendor_id = v.id AND u.contact_role = 'primary'
                 ORDER BY u.id LIMIT 1) AS primary_email,
               (SELECT MAX(ps.submitted_at) FROM pq_submissions ps
                 WHERE ps.vendor_id = v.id) AS last_pq_submitted_at
        FROM vendors v
        WHERE v.registration_status <> 'migrated'
          AND (
                v.created_at >= ?
             OR v.updated_at >= ?
             OR EXISTS (SELECT 1 FROM pq_submissions ps2
                         WHERE ps2.vendor_id = v.id AND ps2.submitted_at >= ?)
              )"
     . ($onlyVendor ? " AND v.id = " . (int)$onlyVendor : "")
     . " ORDER BY v.created_at";

$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $since, $since, $since);
$stmt->execute();
$vendors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ---------------------------------------------------------------------------
// PQ submissions in the window that carry no attachment at all.
// ---------------------------------------------------------------------------
$pqLost = [];
$pqSql = "SELECT s.vendor_id, s.id AS submission_id, s.status, s.submitted_at,
                 COUNT(a.id) AS answers,
                 SUM(COALESCE(a.attachment_path,'') <> '') AS with_file
          FROM pq_submissions s
          LEFT JOIN pq_answers a ON a.submission_id = s.id
          WHERE s.submitted_at >= ?
          GROUP BY s.id
          HAVING with_file = 0 AND answers > 0";
$pqStmt = $conn->prepare($pqSql);
$pqStmt->bind_param("s", $since);
$pqStmt->execute();
foreach ($pqStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $pqLost[(int)$r['vendor_id']] = $r;
}

// ---------------------------------------------------------------------------
// Attachments whose DB row points at a file that is not on disk. This is the
// strongest available evidence of the bug: the app recorded a path, so the
// vendor definitely submitted something, yet the bytes are gone.
// ---------------------------------------------------------------------------
$orphanAttachments = [];
$oa = $conn->query("SELECT s.vendor_id, a.attachment_path
                    FROM pq_answers a
                    JOIN pq_submissions s ON s.id = a.submission_id
                    WHERE COALESCE(a.attachment_path,'') <> ''");
while ($row = $oa->fetch_assoc()) {
    foreach (explode(',', $row['attachment_path']) as $p) {
        $p = trim($p);
        if ($p !== '' && vendor_upload_resolve($p) === null) {
            $orphanAttachments[(int)$row['vendor_id']][] = $p;
        }
    }
}

// ---------------------------------------------------------------------------
// Classify each vendor.
// ---------------------------------------------------------------------------
$report  = [];
$summary = ['orphaned' => 0, 'missing' => 0, 'pq_lost' => 0, 'clean' => 0,
            'self_serve' => 0, 'needs_revision' => 0, 'no_login' => 0,
            'tier_HIGH' => 0, 'tier_MEDIUM' => 0, 'tier_LOW' => 0];

foreach ($vendors as $v) {
    $vid          = (int) $v['id'];
    $isBadanUsaha = ($v['legal_entity_type'] === 'Badan Usaha');

    $orphaned    = [];   // DB has a path, file is gone -> definitely lost
    $missingReq  = [];   // required doc, column empty -> lost, or not yet supplied
    $missingOpt  = [];   // optional doc, column empty -> almost certainly just skipped

    foreach ($docs as $col => $meta) {
        if ($meta['when'] === 'badan_usaha' && !$isBadanUsaha) continue;
        $stored = trim((string) $v[$col]);
        if ($stored === '') {
            if ($meta['when'] === 'optional') $missingOpt[] = $meta['label'];
            else                             $missingReq[] = $meta['label'];
        } elseif (vendor_upload_resolve($stored) === null) {
            $orphaned[] = $meta['label'];
        }
    }
    $missing = array_merge($missingReq, $missingOpt);

    $pqOrphans = $orphanAttachments[$vid] ?? [];
    $pqNoFiles = isset($pqLost[$vid]);
    $hasIssue  = $orphaned || $missing || $pqOrphans || $pqNoFiles;

    // Confidence tier. Only HIGH and MEDIUM are worth contacting anyone about.
    //   HIGH   — a file provably vanished, or evidence-bearing PQ answers carry
    //            no attachment for a submission made during the outage.
    //   MEDIUM — a REQUIRED legal document is absent. Could be loss, could be a
    //            vendor who simply has not got there yet; judge by status.
    //   LOW    — only the optional registration document is absent. Noise.
    $pqInWindow = $pqNoFiles && ($pqLost[$vid]['submitted_at'] ?? '') >= $since;
    if ($orphaned || $pqOrphans || $pqInWindow) {
        $tier = 'HIGH';
    } elseif ($missingReq) {
        $tier = 'MEDIUM';
    } elseif ($hasIssue) {
        $tier = 'LOW';
    } else {
        $tier = 'OK';
    }

    if (!$hasIssue) {
        $summary['clean']++;
        if (!$includeOk) continue;
    }
    if ($priorityOnly && !in_array($tier, ['HIGH', 'MEDIUM'], true)) continue;
    if (isset($summary['tier_' . $tier])) $summary['tier_' . $tier]++;

    // Recovery route. The PQ form only reopens for revision_requested /
    // invited_pq / draft (pq_form.php:132), so anything involving lost PQ
    // attachments needs Sourcing to send the vendor back, regardless of lock.
    $locked   = in_array($v['registration_status'], $lockedStatuses, true);
    $pqIssue  = $pqOrphans || $pqNoFiles;
    if (empty($v['primary_email'])) {
        $route  = 'SOURCING: no vendor login — upload on their behalf';
        $action = 'Use Sourcing > Upload Profile File, or contact via the registering Sourcing user.';
        $summary['no_login']++;
    } elseif ($locked || $pqIssue) {
        $route  = 'SOURCING: Request Revision';
        $action = 'Open review_pq.php > Request Revision with a note that this was a system fault. '
                . 'That unlocks the profile, reopens the PQ form as "Kirim Ulang", and emails the vendor.';
        $summary['needs_revision']++;
    } else {
        $route  = 'VENDOR: self-serve';
        $action = 'Vendor logs in > Profil > Dokumen Legal > re-upload > Simpan Perubahan.';
        $summary['self_serve']++;
    }

    if ($orphaned)  $summary['orphaned']++;
    if ($missing)   $summary['missing']++;
    if ($pqIssue)   $summary['pq_lost']++;

    $report[] = [
        'tier'                => $tier,
        'vendor_id'           => $vid,
        'company_name'        => $v['company_name'],
        'registration_status' => $v['registration_status'],
        'created_at'          => $v['created_at'],
        'last_pq_submitted_at'=> $v['last_pq_submitted_at'] ?? '',
        'primary_email'       => $v['primary_email'] ?? '',
        'confirmed_lost'      => implode('; ', $orphaned),
        'required_docs_absent'=> implode('; ', $missingReq),
        'optional_docs_absent'=> implode('; ', $missingOpt),
        'pq_attachments_lost' => $pqOrphans ? count($pqOrphans) . ' file(s)'
                                : ($pqNoFiles ? 'submission has none' : ''),
        'recovery_route'      => $route,
        'action_required'     => $action,
    ];
}

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------
$total = count($vendors);
echo "Vendors active since {$since} — registered, profile-updated or PQ-submitted\n";
echo "(excluding 'migrated'): {$total}\n";
echo "  fully healthy ................ {$summary['clean']}\n";
echo "  CONFIRMED LOST (path recorded, file gone) .. {$summary['orphaned']}\n";
echo "  documents absent (lost or never supplied) .. {$summary['missing']}\n";
echo "  PQ attachments lost ......................... {$summary['pq_lost']}\n";
echo "\nConfidence tiers (contact HIGH and MEDIUM only):\n";
echo "  HIGH   file provably gone, or PQ submitted in-window with no attachment .. {$summary['tier_HIGH']}\n";
echo "  MEDIUM a REQUIRED legal document is absent ............................... {$summary['tier_MEDIUM']}\n";
echo "  LOW    only the optional registration document is absent — noise ........ {$summary['tier_LOW']}\n";
echo "\nRecovery routes:\n";
echo "  vendor can self-serve ....................... {$summary['self_serve']}\n";
echo "  needs Sourcing 'Request Revision' ........... {$summary['needs_revision']}\n";
echo "  no vendor login (Sourcing must act) ......... {$summary['no_login']}\n";
echo "\nUploaded files cannot be restored from anywhere — the bytes were never\n";
echo "written. Every vendor below has to re-submit. Generated documents (PQ\n";
echo "Result certificates) ARE rebuildable: see tools/regenerate_pq_result_pdfs.php.\n\n";

if (!$report) {
    echo "Nothing to report for this window.\n";
} else {
    // Most severe first, so the top of the list is the outreach list.
    $rank = ['HIGH' => 0, 'MEDIUM' => 1, 'LOW' => 2, 'OK' => 3];
    usort($report, fn($a, $b) => [$rank[$a['tier']], $a['vendor_id']]
                             <=> [$rank[$b['tier']], $b['vendor_id']]);

    printf("%-7s %-6s %-28s %-21s %-30s %s\n", 'TIER', 'ID', 'COMPANY', 'STATUS', 'PROBLEM', 'ROUTE');
    echo str_repeat('-', 140) . "\n";
    foreach ($report as $r) {
        $problem = $r['confirmed_lost'] ? 'LOST: ' . $r['confirmed_lost']
                 : ($r['required_docs_absent'] ? 'absent: ' . $r['required_docs_absent']
                 : ($r['optional_docs_absent'] ? '(optional only)' : ''));
        if ($r['pq_attachments_lost']) {
            $problem = trim($problem . ' | PQ: ' . $r['pq_attachments_lost'], ' |');
        }
        printf("%-7s %-6d %-28s %-21s %-30s %s\n",
            $r['tier'],
            $r['vendor_id'],
            mb_strimwidth((string)$r['company_name'], 0, 28, '…'),
            $r['registration_status'],
            mb_strimwidth($problem, 0, 30, '…'),
            explode(':', $r['recovery_route'])[0]
        );
    }
    echo "\n" . count($report) . " vendor(s) need action. Use --csv for the full outreach list.\n";
}

// ---------------------------------------------------------------------------
// Optional CSV export (gitignored runtime path).
// ---------------------------------------------------------------------------
if ($wantCsv && $report) {
    if ($csvPath === null) {
        $dir = __DIR__ . '/../db_backups/local_runtime_exports';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $csvPath = $dir . '/lost_vendor_documents_' . date('Ymd_His') . '.csv';
    }
    $fh = @fopen($csvPath, 'w');
    if (!$fh) {
        fwrite(STDERR, "\nWARNING: could not write CSV to {$csvPath}\n");
    } else {
        fputcsv($fh, array_keys($report[0]));
        foreach ($report as $r) fputcsv($fh, $r);
        fclose($fh);
        echo "\nCSV written: {$csvPath}\n";
        echo "(gitignored runtime path — do not commit it)\n";
    }
}
