<?php
// seed_rfq_demo_data.php
// Seeds 10 RFQ tenders (ids 901-910) spanning the full lifecycle, for
// demoing/testing the RFQ feature. Idempotent: re-running deletes and
// recreates ids 901-910, so it can be re-run as many times as needed.
//
// This is web-accessible (repo root is copied into the docroot), so it is
// gated behind login + Category Manager, and requires an explicit confirm
// step -- it deletes whatever currently occupies ids 901-910 before
// reseeding, which would be real data if this environment has real tenders
// in that id range.
require_once 'workspace/includes/auth.php';
require_login();
if (($_SESSION['role'] ?? '') !== 'Category Manager') {
    http_response_code(403);
    die('Akses ditolak. Hanya Category Manager yang dapat menjalankan seeder ini.');
}
require_once 'workspace/config/database.php';

$confirmed = (($_GET['confirm'] ?? $_POST['confirm'] ?? '') === 'yes');
if (!$confirmed) {
    $existing = $pdo->query("SELECT id, package_name FROM rfqs WHERE id BETWEEN 901 AND 911 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    echo "This will DELETE and recreate RFQ ids 901-911.\n\n";
    if ($existing) {
        echo "Ids 901-911 CURRENTLY EXIST in this database and will be destroyed:\n";
        foreach ($existing as $r) { echo "  #{$r['id']} - {$r['package_name']}\n"; }
    } else {
        echo "Ids 901-911 do not currently exist in this database.\n";
    }
    echo "\nRe-run with ?confirm=yes to proceed.\n";
    exit;
}

$now = new DateTime();
function at($daysOffset, $time = '17:00:00') {
    global $now;
    $d = (clone $now)->modify(($daysOffset >= 0 ? '+' : '') . $daysOffset . ' days');
    return $d->format('Y-m-d') . ' ' . $time;
}
function plusHours($datetimeStr, $hours) {
    return (new DateTime($datetimeStr))->modify($hours . ' hours')->format('Y-m-d H:i:s');
}
function secret() {
    return strtoupper(substr(md5(uniqid((string)rand(), true)), 0, 6));
}

// Writes a minimal valid PDF so seeded "download" links resolve to a real
// file instead of a broken link, and returns the stored filename (matching
// the naming convention rfq_vendor.php itself uses: <timestamp>_<doc>_<name>).
$uploadDir = __DIR__ . '/uploads/rfq_uploads/';
if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }
function placeholderFile($timestamp, $docType, $label, $vendorName) {
    global $uploadDir;
    $safeName = preg_replace('/[^A-Za-z0-9]+/', '', $vendorName);
    $filename = $timestamp . '_' . $docType . '_' . $safeName . '.pdf';
    $content = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
        . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 150]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n"
        . "4 0 obj<</Length 80>>stream\nBT /F1 12 Tf 20 100 Td ($label - $vendorName) Tj ET\nendstream endobj\n"
        . "5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n"
        . "trailer<</Root 1 0 R>>\n%%EOF";
    file_put_contents($uploadDir . $filename, $content);
    return $filename;
}

$rfqIds = range(901, 911);

$pdo->beginTransaction();
try {
    // --- Clean slate for ids 901-910 ---
    $in = implode(',', $rfqIds);
    $vendorIds = $pdo->query("SELECT id FROM rfq_vendors WHERE rfq_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
    if ($vendorIds) {
        $vin = implode(',', array_map('intval', $vendorIds));
        $pdo->exec("DELETE FROM rfq_vendor_approvals WHERE rfq_vendor_id IN ($vin)");
    }
    $pdo->exec("DELETE FROM rfq_vendors WHERE rfq_id IN ($in)");
    $pdo->exec("DELETE FROM rfq_items WHERE rfq_id IN ($in)");
    $pdo->exec("DELETE FROM rfq_committee WHERE rfq_id IN ($in)");
    $pdo->exec("DELETE FROM rfq_audit_logs WHERE rfq_id IN ($in)");
    $pdo->exec("DELETE FROM rfqs WHERE id IN ($in)");

    $insRfq = $pdo->prepare("INSERT INTO rfqs (id, package_name, deadline, status, created_by, created_at, winner_vendor_id, final_price, closed_by, closed_at, parent_id, round_number, rfq_type, pending_deadline, deadline_request_by, deadline_request_status)
        VALUES (:id, :package_name, :deadline, :status, :created_by, :created_at, :winner_vendor_id, :final_price, :closed_by, :closed_at, :parent_id, :round_number, :rfq_type, :pending_deadline, :deadline_request_by, :deadline_request_status)");

    $insItem = $pdo->prepare("INSERT INTO rfq_items (rfq_id, ticket_no, ticket_line_item, item_description, qty, uom) VALUES (?, ?, ?, ?, ?, ?)");

    $insCommittee = $pdo->prepare("INSERT INTO rfq_committee (rfq_id, username, has_opened, opened_at) VALUES (?, ?, ?, ?)");

    $insVendor = $pdo->prepare("INSERT INTO rfq_vendors (rfq_id, vendor_name, vendor_email, vendor_wa, vendor_pic, secret_password, total_price, ppn_status, price_validity, submitted_at, addition_status, file_cover_letter, file_boq, file_supporting)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $insLog = $pdo->prepare("INSERT INTO rfq_audit_logs (rfq_id, username, action_type, target_vendor_id, created_at, description) VALUES (?, ?, ?, ?, ?, ?)");

    $insApproval = $pdo->prepare("INSERT INTO rfq_vendor_approvals (rfq_vendor_id, committee_username, status, approved_at) VALUES (?, ?, ?, ?)");

    // ===================================================================
    // 901 - Active, Standard, fresh invite (no submissions yet) + bonus:
    //       pending deadline-change request + pending mid-tender vendor add
    // ===================================================================
    $deadline901 = at(7, '17:00:00');
    $created901 = at(-3, '09:15:00');
    $insRfq->execute([
        'id' => 901, 'package_name' => 'Pengadaan Smartphone Android & Aksesoris Corporate Batch September 2026',
        'deadline' => $deadline901, 'status' => 'Active', 'created_by' => 'i_tristan.gautama', 'created_at' => $created901,
        'winner_vendor_id' => null, 'final_price' => null, 'closed_by' => null, 'closed_at' => null,
        'parent_id' => null, 'round_number' => 1, 'rfq_type' => 'Standard',
        'pending_deadline' => at(11, '17:00:00'), 'deadline_request_by' => 'hadi.risali', 'deadline_request_status' => 'PENDING',
    ]);
    foreach ([
        ['10', '[33023] SMARTPHONE ANDROID 6GB/128GB', 1.00, 'EA'],
        ['20', '[33021] CASING SMARTPHONE 6GB/128GB', 1.00, 'EA'],
        ['30', '[33024] TEMPERED GLASS SMARTPHONE ANDROID 6GB/128GB', 1.00, 'EA'],
    ] as $it) { $insItem->execute([901, 'T2602A95076', $it[0], $it[1], $it[2], $it[3]]); }
    foreach (['hadi.risali', 'fabian.suhalim', 'adonis.sutanto'] as $c) {
        $insCommittee->execute([901, $c, 0, null]);
    }
    $vendors901 = [
        ['PT Distribusi Gadget Nusantara', 'sales@gadgetnusantara.co.id', '628123456701', 'Budi Santoso'],
        ['PT Solusi Elektronik Prima', 'procurement@elektronikprima.co.id', '628123456702', 'Andi Wijaya'],
        ['CV Mega Komunika Indonesia', 'cs@megakomunika.co.id', '628123456703', 'Citra Lestari'],
        ['PT Trimitra Selular Abadi', 'tender@trimitraselular.co.id', '628123456704', 'Denny Kurniawan'],
    ];
    $v901Ids = [];
    foreach ($vendors901 as $v) {
        $insVendor->execute([901, $v[0], $v[1], $v[2], $v[3], secret(), null, null, null, null, 'APPROVED', null, null, null]);
        $v901Ids[$v[0]] = $pdo->lastInsertId();
    }
    // Bonus mid-tender vendor addition, pending committee sign-off
    $insVendor->execute([901, 'PT Vendor Tambahan Digital', 'info@vendortambahan.co.id', '628123456705', 'Fajar Ramadhan', secret(), null, null, null, null, 'PENDING', null, null, null]);
    $extraVendorId901 = $pdo->lastInsertId();
    $pdo->prepare("UPDATE rfq_vendors SET addition_reason = ? WHERE id = ?")->execute(['Rekomendasi tambahan dari tim Project setelah RFQ dibuka', $extraVendorId901]);
    $insApproval->execute([$extraVendorId901, 'hadi.risali', 'APPROVED', plusHours($created901, 26)]);
    $insApproval->execute([$extraVendorId901, 'fabian.suhalim', 'PENDING', null]);
    $insApproval->execute([$extraVendorId901, 'adonis.sutanto', 'PENDING', null]);

    $insLog->execute([901, 'i_tristan.gautama', 'CREATE', null, $created901, 'Tender Pengadaan Smartphone Android dibuat']);
    $insLog->execute([901, 'i_tristan.gautama', 'INVITE', null, plusHours($created901, 1), 'Invited PT Distribusi Gadget Nusantara, PT Solusi Elektronik Prima, CV Mega Komunika Indonesia, PT Trimitra Selular Abadi']);
    $insLog->execute([901, 'i_tristan.gautama', 'REQUEST_ADD_VENDOR', $extraVendorId901, plusHours($created901, 25), 'Requested to add vendor: PT Vendor Tambahan Digital. Reason: Rekomendasi tambahan dari tim Project setelah RFQ dibuka']);
    $insLog->execute([901, 'hadi.risali', 'APPROVE_ADD_VENDOR', $extraVendorId901, plusHours($created901, 26), 'Approved addition of vendor.']);
    $insLog->execute([901, 'hadi.risali', 'REQUEST_CHANGE_DEADLINE|' . at(11, '17:00:00'), null, plusHours($created901, 30), null]);

    // ===================================================================
    // 902 - Active, Standard, deadline still open, partial submissions
    // ===================================================================
    $deadline902 = at(3, '12:00:00');
    $created902 = at(-6, '10:00:00');
    $insRfq->execute([
        'id' => 902, 'package_name' => 'Pengadaan Reverse Osmosis Water Treatment System Cabang Denpasar',
        'deadline' => $deadline902, 'status' => 'Active', 'created_by' => 'theodore.seamditia', 'created_at' => $created902,
        'winner_vendor_id' => null, 'final_price' => null, 'closed_by' => null, 'closed_at' => null,
        'parent_id' => null, 'round_number' => 1, 'rfq_type' => 'Standard',
        'pending_deadline' => null, 'deadline_request_by' => null, 'deadline_request_status' => null,
    ]);
    $insItem->execute([902, 'T2604F97038', '100', 'CTSYS-037 - RO MACHINE C/W, FLOW CONTROL AND ELECTRICAL PANEL, WITH HOUSING FRP AND SILVERTEC MEMBRANE RO, CAPACITY 2.200 GPD 300-1000 PPM (BRANKISH)(PVC)', 1.00, 'UNT']);
    foreach (['rio.tandy', 'hadi.risali'] as $c) { $insCommittee->execute([902, $c, 0, null]); }
    $insVendor->execute([902, 'PT Aqua Prima Teknindo', 'tender@aquaprimateknindo.co.id', '628123456711', 'Siti Nurhaliza', secret(), 187500000.00, 'Exclude', '30 Hari', at(-1, '14:20:00'), 'APPROVED', null, null, null]);
    $insVendor->execute([902, 'CV Tirta Jernih Sistema', 'sales@tirtajernih.co.id', '628123456712', 'Yudi Pratama', secret(), 172900000.00, 'Include', '45 Hari', at(0, '08:05:00'), 'APPROVED', null, null, null]);
    $insVendor->execute([902, 'PT Sarana Air Bersih Mandiri', 'procurement@saranaairbersih.co.id', '628123456713', 'Wawan Setiadi', secret(), null, null, null, null, 'APPROVED', null, null, null]);
    $insLog->execute([902, 'theodore.seamditia', 'CREATE', null, $created902, 'Tender Pengadaan RO Machine dibuat']);
    $insLog->execute([902, 'theodore.seamditia', 'INVITE', null, plusHours($created902, 1), 'Invited PT Aqua Prima Teknindo, CV Tirta Jernih Sistema, PT Sarana Air Bersih Mandiri']);
    $insLog->execute([902, 'System', 'SUBMIT_QUOTATION', null, at(-1, '14:20:00'), 'PT Aqua Prima Teknindo submitted quotation']);
    $insLog->execute([902, 'System', 'SUBMIT_QUOTATION', null, at(0, '08:05:00'), 'CV Tirta Jernih Sistema submitted quotation']);

    // ===================================================================
    // 903 - Deadline passed, all submitted, ready to Unseal
    // ===================================================================
    $deadline903 = at(-2, '17:00:00');
    $created903 = at(-12, '09:00:00');
    $insRfq->execute([
        'id' => 903, 'package_name' => 'Pengadaan Tablet Samsung & Aksesoris IT Corporate 2026',
        'deadline' => $deadline903, 'status' => 'Active', 'created_by' => 'dave.hadiwirya', 'created_at' => $created903,
        'winner_vendor_id' => null, 'final_price' => null, 'closed_by' => null, 'closed_at' => null,
        'parent_id' => null, 'round_number' => 1, 'rfq_type' => 'Standard',
        'pending_deadline' => null, 'deadline_request_by' => null, 'deadline_request_status' => null,
    ]);
    foreach ([
        ['10', '[16757] [AON] TEMPERED GLASS FOR TABLET 8 INCH', 1.00, 'EA'],
        ['20', '[17088] [AON] EXTENDED WARRANTY 1 YEAR FOR TABLET SAMSUNG 8 INCH', 1.00, 'EA'],
        ['30', '[16354] [AON] CASING COVER ARMOR CASE WITH HANDSTRAP & SHOULDER STRAP FOR TABLET 8 INCH', 1.00, 'EA'],
        ['40', '[17089] [AON] TABLET SAMSUNG 8 INCH (WIFI+CELLULAR)', 1.00, 'EA'],
    ] as $it) { $insItem->execute([903, 'T2604H95021', $it[0], $it[1], $it[2], $it[3]]); }
    foreach (['hadi.risali', 'adonis.sutanto', 'jason.sunjaya'] as $c) { $insCommittee->execute([903, $c, 0, null]); }
    $ts903a = strtotime(at(-5, '11:00:00')); $ts903b = strtotime(at(-4, '15:40:00')); $ts903c = strtotime(at(-3, '10:10:00'));
    $insVendor->execute([903, 'PT Mitra Digital Nusantara', 'tender@mitradigital.co.id', '628123456721', 'Lina Kusuma', secret(), 165000000.00, 'Exclude', '30 Hari', at(-5, '11:00:00'), 'APPROVED',
        placeholderFile($ts903a, 'cover', 'Cover Letter', 'PT Mitra Digital Nusantara'), placeholderFile($ts903a, 'boq', 'BoQ', 'PT Mitra Digital Nusantara'), placeholderFile($ts903a, 'supporting', 'Dokumen Pendukung', 'PT Mitra Digital Nusantara')]);
    $insVendor->execute([903, 'CV Cahaya Teknologi', 'sales@cahayateknologi.co.id', '628123456722', 'Herman Susilo', secret(), 158750000.00, 'Include', '14 Hari', at(-4, '15:40:00'), 'APPROVED',
        placeholderFile($ts903b, 'cover', 'Cover Letter', 'CV Cahaya Teknologi'), placeholderFile($ts903b, 'boq', 'BoQ', 'CV Cahaya Teknologi'), placeholderFile($ts903b, 'supporting', 'Dokumen Pendukung', 'CV Cahaya Teknologi')]);
    $insVendor->execute([903, 'PT Solusi Gadget Sejahtera', 'procurement@solusigadget.co.id', '628123456723', 'Novita Sari', secret(), 171200000.00, 'Exclude', '30 Hari', at(-3, '10:10:00'), 'APPROVED',
        placeholderFile($ts903c, 'cover', 'Cover Letter', 'PT Solusi Gadget Sejahtera'), placeholderFile($ts903c, 'boq', 'BoQ', 'PT Solusi Gadget Sejahtera'), placeholderFile($ts903c, 'supporting', 'Dokumen Pendukung', 'PT Solusi Gadget Sejahtera')]);
    $insLog->execute([903, 'dave.hadiwirya', 'CREATE', null, $created903, 'Tender Pengadaan Tablet Samsung dibuat']);
    $insLog->execute([903, 'dave.hadiwirya', 'INVITE', null, plusHours($created903, 1), 'Invited PT Mitra Digital Nusantara, CV Cahaya Teknologi, PT Solusi Gadget Sejahtera']);
    $insLog->execute([903, 'System', 'SUBMIT_QUOTATION', null, at(-5, '11:00:00'), 'PT Mitra Digital Nusantara submitted quotation']);
    $insLog->execute([903, 'System', 'SUBMIT_QUOTATION', null, at(-4, '15:40:00'), 'CV Cahaya Teknologi submitted quotation']);
    $insLog->execute([903, 'System', 'SUBMIT_QUOTATION', null, at(-3, '10:10:00'), 'PT Solusi Gadget Sejahtera submitted quotation']);

    // ===================================================================
    // 904 - Deadline passed, partial unseal, one vendor eliminated
    // ===================================================================
    $deadline904 = at(-4, '17:00:00');
    $created904 = at(-15, '09:00:00');
    $insRfq->execute([
        'id' => 904, 'package_name' => 'Pengadaan Xmas Decoration Supplies Retail Season 2026',
        'deadline' => $deadline904, 'status' => 'Active', 'created_by' => 'mega.wati', 'created_at' => $created904,
        'winner_vendor_id' => null, 'final_price' => null, 'closed_by' => null, 'closed_at' => null,
        'parent_id' => null, 'round_number' => 1, 'rfq_type' => 'Standard',
        'pending_deadline' => null, 'deadline_request_by' => null, 'deadline_request_status' => null,
    ]);
    foreach ([
        ['10', '36842 - 10639279 XMAS BALL 6CM S/35 GOLD Y25', 7.00, 'SET'],
        ['20', '36844 - 10644476 XMAS BALL 6CM GOLD S/7 Y25', 1.00, 'SET'],
        ['30', '36838 - 10644478 XMAS BALL 10CM RED S/6 Y25', 13.00, 'SET'],
        ['40', '36839 - 10644480 XMAS BALL 6CM RED S/9 Y25', 2.00, 'SET'],
        ['50', '36841 - 10644468 XMAS BALL 5CM RED S/12 Y25', 12.00, 'SET'],
    ] as $it) { $insItem->execute([904, 'T2601PIN002', $it[0], $it[1], $it[2], $it[3]]); }
    $insCommittee->execute([904, 'hadi.risali', 1, at(-3, '09:30:00')]);
    $insCommittee->execute([904, 'fabian.suhalim', 1, at(-2, '13:15:00')]);
    $insCommittee->execute([904, 'rio.tandy', 0, null]);
    $insVendor->execute([904, 'PT Dekorasi Nusantara Indah', 'tender@dekorasinusantara.co.id', '628123456731', 'Rina Marpaung', secret(), 42500000.00, 'Exclude', '14 Hari', at(-6, '10:30:00'), 'APPROVED', null, null, null]);
    $insVendor->execute([904, 'CV Gemilang Ornamen', 'sales@gemilangornamen.co.id', '628123456732', 'Dedi Firmansyah', secret(), 39800000.00, 'Include', '14 Hari', at(-6, '16:00:00'), 'APPROVED', null, null, null]);
    $insVendor->execute([904, 'PT Kilau Cahaya Dekorasi', 'info@kilaucahaya.co.id', '628123456733', 'Yustina Halim', secret(), null, null, null, null, 'APPROVED', null, null, null]);
    $v904Eliminated = $pdo->lastInsertId();
    $insLog->execute([904, 'mega.wati', 'CREATE', null, $created904, 'Tender Pengadaan Xmas Decoration dibuat']);
    $insLog->execute([904, 'mega.wati', 'INVITE', null, plusHours($created904, 1), 'Invited PT Dekorasi Nusantara Indah, CV Gemilang Ornamen, PT Kilau Cahaya Dekorasi']);
    $insLog->execute([904, 'System', 'SUBMIT_QUOTATION', null, at(-6, '10:30:00'), 'PT Dekorasi Nusantara Indah submitted quotation']);
    $insLog->execute([904, 'System', 'SUBMIT_QUOTATION', null, at(-6, '16:00:00'), 'CV Gemilang Ornamen submitted quotation']);
    $insLog->execute([904, 'hadi.risali', 'UNSEAL', null, at(-3, '09:30:00'), null]);
    $insLog->execute([904, 'Sistem (hadi.risali)', 'ELIMINATED', $v904Eliminated, at(-3, '09:30:01'), null]);
    $insLog->execute([904, 'fabian.suhalim', 'UNSEAL', null, at(-2, '13:15:00'), null]);

    // ===================================================================
    // 905 - Fully Unsealed (all committee opened) - ready to compare/negotiate
    // ===================================================================
    $deadline905 = at(-9, '17:00:00');
    $created905 = at(-20, '09:00:00');
    $insRfq->execute([
        'id' => 905, 'package_name' => 'Pengadaan Working Chair & Furniture Kantor Cabang 2026',
        'deadline' => $deadline905, 'status' => 'Unsealed', 'created_by' => 'nicholas.kiehiunglie', 'created_at' => $created905,
        'winner_vendor_id' => null, 'final_price' => null, 'closed_by' => null, 'closed_at' => null,
        'parent_id' => null, 'round_number' => 1, 'rfq_type' => 'Standard',
        'pending_deadline' => null, 'deadline_request_by' => null, 'deadline_request_status' => null,
    ]);
    $insItem->execute([905, 'T2606P99010', '1120', '[28572] 10515425 G ZAKI WORKING CHAIR LOW BACK BLACK', 40.00, 'EA']);
    $insCommittee->execute([905, 'adonis.sutanto', 1, at(-8, '09:00:00')]);
    $insCommittee->execute([905, 'jason.sunjaya', 1, at(-8, '11:45:00')]);
    $insVendor->execute([905, 'PT Furnindo Karya Sejahtera', 'tender@furnindokarya.co.id', '628123456741', 'Agus Salim', secret(), 28400000.00, 'Exclude', '30 Hari', at(-10, '10:00:00'), 'APPROVED', null, null, null]);
    $insVendor->execute([905, 'CV Kursi Ergonomis Prima', 'sales@kursiergonomis.co.id', '628123456742', 'Melisa Tanuwijaya', secret(), 26950000.00, 'Include', '21 Hari', at(-10, '13:20:00'), 'APPROVED', null, null, null]);
    $insVendor->execute([905, 'PT Mitra Perabot Kantor', 'procurement@mitraperabot.co.id', '628123456743', 'Robby Halim', secret(), 29750000.00, 'Exclude', '14 Hari', at(-9, '09:50:00'), 'APPROVED', null, null, null]);
    $insLog->execute([905, 'nicholas.kiehiunglie', 'CREATE', null, $created905, 'Tender Pengadaan Working Chair dibuat']);
    $insLog->execute([905, 'nicholas.kiehiunglie', 'INVITE', null, plusHours($created905, 1), 'Invited PT Furnindo Karya Sejahtera, CV Kursi Ergonomis Prima, PT Mitra Perabot Kantor']);
    $insLog->execute([905, 'System', 'SUBMIT_QUOTATION', null, at(-10, '10:00:00'), 'PT Furnindo Karya Sejahtera submitted quotation']);
    $insLog->execute([905, 'System', 'SUBMIT_QUOTATION', null, at(-10, '13:20:00'), 'CV Kursi Ergonomis Prima submitted quotation']);
    $insLog->execute([905, 'System', 'SUBMIT_QUOTATION', null, at(-9, '09:50:00'), 'PT Mitra Perabot Kantor submitted quotation']);
    $insLog->execute([905, 'adonis.sutanto', 'UNSEAL', null, at(-8, '09:00:00'), null]);
    $insLog->execute([905, 'jason.sunjaya', 'UNSEAL', null, at(-8, '11:45:00'), null]);

    // ===================================================================
    // 906 - Locked for final negotiation (QS locked it)
    // ===================================================================
    $deadline906 = at(-14, '17:00:00');
    $created906 = at(-25, '09:00:00');
    $insRfq->execute([
        'id' => 906, 'package_name' => 'Tender Jasa Cleaning Service & Consumables Gedung HO 2026',
        'deadline' => $deadline906, 'status' => 'Locked', 'created_by' => 'olivia.juliani', 'created_at' => $created906,
        'winner_vendor_id' => null, 'final_price' => null, 'closed_by' => null, 'closed_at' => null,
        'parent_id' => null, 'round_number' => 1, 'rfq_type' => 'Standard',
        'pending_deadline' => null, 'deadline_request_by' => null, 'deadline_request_status' => null,
    ]);
    $insItem->execute([906, 'T2601J99037', '780', '[9388] 158904 GLASS CLEANER GLANCE 4LT', 780.00, 'EA']);
    $insCommittee->execute([906, 'hadi.risali', 1, at(-13, '09:20:00')]);
    $insCommittee->execute([906, 'rio.tandy', 1, at(-13, '10:05:00')]);
    $insVendor->execute([906, 'PT Bersih Sejahtera Abadi', 'tender@bersihsejahtera.co.id', '628123456751', 'Anton Wijaya', secret(), 18200000.00, 'Exclude', '30 Hari', at(-16, '10:00:00'), 'APPROVED', null, null, null]);
    $insVendor->execute([906, 'CV Clean Solution Indonesia', 'sales@cleansolution.co.id', '628123456752', 'Sri Wahyuni', secret(), 17650000.00, 'Include', '30 Hari', at(-16, '14:10:00'), 'APPROVED', null, null, null]);
    $insVendor->execute([906, 'PT Sanitasi Prima Nusantara', 'procurement@sanitasiprima.co.id', '628123456753', 'Hendra Gunawan', secret(), 19000000.00, 'Exclude', '14 Hari', at(-15, '09:30:00'), 'APPROVED', null, null, null]);
    $insLog->execute([906, 'olivia.juliani', 'CREATE', null, $created906, 'Tender Jasa Cleaning Service dibuat']);
    $insLog->execute([906, 'olivia.juliani', 'INVITE', null, plusHours($created906, 1), 'Invited PT Bersih Sejahtera Abadi, CV Clean Solution Indonesia, PT Sanitasi Prima Nusantara']);
    $insLog->execute([906, 'System', 'SUBMIT_QUOTATION', null, at(-16, '10:00:00'), 'PT Bersih Sejahtera Abadi submitted quotation']);
    $insLog->execute([906, 'System', 'SUBMIT_QUOTATION', null, at(-16, '14:10:00'), 'CV Clean Solution Indonesia submitted quotation']);
    $insLog->execute([906, 'System', 'SUBMIT_QUOTATION', null, at(-15, '09:30:00'), 'PT Sanitasi Prima Nusantara submitted quotation']);
    $insLog->execute([906, 'hadi.risali', 'UNSEAL', null, at(-13, '09:20:00'), null]);
    $insLog->execute([906, 'rio.tandy', 'UNSEAL', null, at(-13, '10:05:00'), null]);
    $insLog->execute([906, 'rina.susanto', 'LOCKED', null, at(-12, '08:45:00'), null]);

    // ===================================================================
    // 907 - Closed with a winner, negotiated final price
    // ===================================================================
    $deadline907 = at(-19, '17:00:00');
    $created907 = at(-30, '09:00:00');
    $insRfq->execute([
        'id' => 907, 'package_name' => 'Pengadaan Name Badge & ID Card Training Batch 2026',
        'deadline' => $deadline907, 'status' => 'Closed', 'created_by' => 'i_tristan.gautama', 'created_at' => $created907,
        'winner_vendor_id' => null, 'final_price' => 8200000.00, 'closed_by' => 'i_tristan.gautama', 'closed_at' => at(-16, '15:00:00'),
        'parent_id' => null, 'round_number' => 1, 'rfq_type' => 'Standard',
        'pending_deadline' => null, 'deadline_request_by' => null, 'deadline_request_status' => null,
    ]);
    $insItem->execute([907, 'T2607A99084', '350', '[6155] NAME BADGE (NAME TAG TRAINING)', 500.00, 'EA']);
    $insCommittee->execute([907, 'hadi.risali', 1, at(-18, '09:15:00')]);
    $insCommittee->execute([907, 'adonis.sutanto', 1, at(-18, '10:40:00')]);
    $ts907a = strtotime(at(-21, '10:00:00')); $ts907b = strtotime(at(-21, '13:30:00')); $ts907c = strtotime(at(-20, '09:15:00'));
    $insVendor->execute([907, 'PT Percetakan Identitas Utama', 'tender@identitasutama.co.id', '628123456761', 'Wisnu Prakoso', secret(), 8500000.00, 'Exclude', '14 Hari', at(-21, '10:00:00'), 'APPROVED',
        placeholderFile($ts907a, 'cover', 'Cover Letter', 'PT Percetakan Identitas Utama'), placeholderFile($ts907a, 'boq', 'BoQ', 'PT Percetakan Identitas Utama'), placeholderFile($ts907a, 'supporting', 'Dokumen Pendukung', 'PT Percetakan Identitas Utama')]);
    $winner907 = $pdo->lastInsertId();
    $insVendor->execute([907, 'CV Kartu Digital Sejahtera', 'sales@kartudigital.co.id', '628123456762', 'Feby Anggraini', secret(), 9100000.00, 'Include', '14 Hari', at(-21, '13:30:00'), 'APPROVED',
        placeholderFile($ts907b, 'cover', 'Cover Letter', 'CV Kartu Digital Sejahtera'), placeholderFile($ts907b, 'boq', 'BoQ', 'CV Kartu Digital Sejahtera'), placeholderFile($ts907b, 'supporting', 'Dokumen Pendukung', 'CV Kartu Digital Sejahtera')]);
    $insVendor->execute([907, 'PT Badge Print Solusi', 'procurement@badgeprint.co.id', '628123456763', 'Reza Fahlevi', secret(), 8950000.00, 'Exclude', '7 Hari', at(-20, '09:15:00'), 'APPROVED',
        placeholderFile($ts907c, 'cover', 'Cover Letter', 'PT Badge Print Solusi'), placeholderFile($ts907c, 'boq', 'BoQ', 'PT Badge Print Solusi'), placeholderFile($ts907c, 'supporting', 'Dokumen Pendukung', 'PT Badge Print Solusi')]);
    $pdo->prepare("UPDATE rfqs SET winner_vendor_id = ? WHERE id = 907")->execute([$winner907]);
    $ts907final = strtotime(at(-16, '15:00:00'));
    $pdo->prepare("UPDATE rfq_vendors SET final_cover_letter = ?, final_boq = ?, final_supporting = ? WHERE id = ?")->execute([
        placeholderFile($ts907final, 'final_cover', 'Final Cover Letter', 'PT Percetakan Identitas Utama'),
        placeholderFile($ts907final, 'final_boq', 'Final BoQ', 'PT Percetakan Identitas Utama'),
        placeholderFile($ts907final, 'final_supporting', 'Final Dokumen Pendukung', 'PT Percetakan Identitas Utama'),
        $winner907,
    ]);
    $insLog->execute([907, 'i_tristan.gautama', 'CREATE', null, $created907, 'Tender Pengadaan Name Badge dibuat']);
    $insLog->execute([907, 'i_tristan.gautama', 'INVITE', null, plusHours($created907, 1), 'Invited PT Percetakan Identitas Utama, CV Kartu Digital Sejahtera, PT Badge Print Solusi']);
    $insLog->execute([907, 'System', 'SUBMIT_QUOTATION', null, at(-21, '10:00:00'), 'PT Percetakan Identitas Utama submitted quotation']);
    $insLog->execute([907, 'System', 'SUBMIT_QUOTATION', null, at(-21, '13:30:00'), 'CV Kartu Digital Sejahtera submitted quotation']);
    $insLog->execute([907, 'System', 'SUBMIT_QUOTATION', null, at(-20, '09:15:00'), 'PT Badge Print Solusi submitted quotation']);
    $insLog->execute([907, 'hadi.risali', 'UNSEAL', null, at(-18, '09:15:00'), null]);
    $insLog->execute([907, 'adonis.sutanto', 'UNSEAL', null, at(-18, '10:40:00'), null]);
    $insLog->execute([907, 'rina.susanto', 'LOCKED', null, at(-17, '08:30:00'), null]);
    $insLog->execute([907, 'i_tristan.gautama', 'CLOSED_TENDER_AND_SET_PRICE', $winner907, at(-16, '15:00:00'), 'Negosiasi final: PT Percetakan Identitas Utama dari Rp8.500.000 menjadi Rp8.200.000']);

    // ===================================================================
    // 909 - Round 1 of a two-round negotiation (Unsealed, superseded by 908)
    // ===================================================================
    $deadline909 = at(-24, '17:00:00');
    $created909 = at(-33, '09:00:00');
    $insRfq->execute([
        'id' => 909, 'package_name' => 'Pengadaan Genset & Panel Listrik Cabang Bali',
        'deadline' => $deadline909, 'status' => 'Unsealed', 'created_by' => 'theodore.seamditia', 'created_at' => $created909,
        'winner_vendor_id' => null, 'final_price' => null, 'closed_by' => null, 'closed_at' => null,
        'parent_id' => null, 'round_number' => 1, 'rfq_type' => 'Standard',
        'pending_deadline' => null, 'deadline_request_by' => null, 'deadline_request_status' => null,
    ]);
    $insCommittee->execute([909, 'rio.tandy', 1, at(-23, '09:30:00')]);
    $insCommittee->execute([909, 'fabian.suhalim', 1, at(-23, '11:00:00')]);
    $insVendor->execute([909, 'PT Genset Power Nusantara', 'tender@gensetpower.co.id', '628123456771', 'Iwan Setiawan', secret(), 215000000.00, 'Exclude', '30 Hari', at(-26, '10:00:00'), 'APPROVED', null, null, null]);
    $v909PowerId = $pdo->lastInsertId();
    $insVendor->execute([909, 'CV Elektrindo Panel Sejahtera', 'sales@elektrindopanel.co.id', '628123456772', 'Gunawan Halim', secret(), 228500000.00, 'Include', '30 Hari', at(-26, '14:00:00'), 'APPROVED', null, null, null]);
    $v909ElektrindoId = $pdo->lastInsertId();
    $insLog->execute([909, 'theodore.seamditia', 'CREATE', null, $created909, 'Tender Pengadaan Genset & Panel Listrik dibuat']);
    $insLog->execute([909, 'theodore.seamditia', 'INVITE', null, plusHours($created909, 1), 'Invited PT Genset Power Nusantara, CV Elektrindo Panel Sejahtera']);
    $insLog->execute([909, 'System', 'SUBMIT_QUOTATION', null, at(-26, '10:00:00'), 'PT Genset Power Nusantara submitted quotation']);
    $insLog->execute([909, 'System', 'SUBMIT_QUOTATION', null, at(-26, '14:00:00'), 'CV Elektrindo Panel Sejahtera submitted quotation']);
    $insLog->execute([909, 'rio.tandy', 'UNSEAL', null, at(-23, '09:30:00'), null]);
    $insLog->execute([909, 'fabian.suhalim', 'UNSEAL', null, at(-23, '11:00:00'), null]);

    // ===================================================================
    // 908 - Round 2 (child of 909), open for revised quotes
    // ===================================================================
    $deadline908 = at(5, '17:00:00');
    $created908 = at(-2, '10:00:00');
    $insRfq->execute([
        'id' => 908, 'package_name' => 'Pengadaan Genset & Panel Listrik Cabang Bali (Putaran 2)',
        'deadline' => $deadline908, 'status' => 'Active', 'created_by' => 'theodore.seamditia', 'created_at' => $created908,
        'winner_vendor_id' => null, 'final_price' => null, 'closed_by' => null, 'closed_at' => null,
        'parent_id' => 909, 'round_number' => 2, 'rfq_type' => 'Standard',
        'pending_deadline' => null, 'deadline_request_by' => null, 'deadline_request_status' => null,
    ]);
    $insCommittee->execute([908, 'rio.tandy', 0, null]);
    $insCommittee->execute([908, 'fabian.suhalim', 0, null]);
    $insVendor->execute([908, 'PT Genset Power Nusantara', 'tender@gensetpower.co.id', '628123456771', 'Iwan Setiawan', secret(), 198000000.00, 'Exclude', '30 Hari', at(-1, '11:30:00'), 'APPROVED', null, null, null]);
    $insVendor->execute([908, 'CV Elektrindo Panel Sejahtera', 'sales@elektrindopanel.co.id', '628123456772', 'Gunawan Halim', secret(), null, null, null, null, 'APPROVED', null, null, null]);
    $insLog->execute([908, 'theodore.seamditia', 'CREATE', null, $created908, 'Ronde 2 dibuka setelah negosiasi harga Putaran 1']);
    $insLog->execute([908, 'theodore.seamditia', 'INVITE', null, plusHours($created908, 1), 'Invited PT Genset Power Nusantara, CV Elektrindo Panel Sejahtera untuk Putaran 2']);
    $insLog->execute([908, 'System', 'SUBMIT_QUOTATION', null, at(-1, '11:30:00'), 'PT Genset Power Nusantara submitted quotation (Putaran 2)']);

    // ===================================================================
    // 910 - Construction-type RFQ, fresh invite
    // ===================================================================
    $deadline910 = at(12, '17:00:00');
    $created910 = at(-1, '09:00:00');
    $insRfq->execute([
        'id' => 910, 'package_name' => 'Konstruksi Renovasi Gudang Distribusi Cikarang Fase 2',
        'deadline' => $deadline910, 'status' => 'Active', 'created_by' => 'dave.hadiwirya', 'created_at' => $created910,
        'winner_vendor_id' => null, 'final_price' => null, 'closed_by' => null, 'closed_at' => null,
        'parent_id' => null, 'round_number' => 1, 'rfq_type' => 'Construction',
        'pending_deadline' => null, 'deadline_request_by' => null, 'deadline_request_status' => null,
    ]);
    foreach (['vava.delaranto', 'hadi.risali', 'adonis.sutanto'] as $c) { $insCommittee->execute([910, $c, 0, null]); }
    $insVendor->execute([910, 'PT Bangun Konstruksi Sejahtera', 'tender@bangunkonstruksi.co.id', '628123456781', 'Tommy Kusnadi', secret(), null, null, null, null, 'APPROVED', null, null, null]);
    $insVendor->execute([910, 'CV Karya Bangunan Mandiri', 'sales@karyabangunan.co.id', '628123456782', 'Sandra Yuliani', secret(), null, null, null, null, 'APPROVED', null, null, null]);
    $insVendor->execute([910, 'PT Cipta Graha Konstruksindo', 'procurement@ciptagraha.co.id', '628123456783', 'Bambang Suryanto', secret(), null, null, null, null, 'APPROVED', null, null, null]);
    $insLog->execute([910, 'dave.hadiwirya', 'CREATE', null, $created910, 'Tender Konstruksi Renovasi Gudang dibuat']);
    $insLog->execute([910, 'dave.hadiwirya', 'INVITE', null, plusHours($created910, 1), 'Invited PT Bangun Konstruksi Sejahtera, CV Karya Bangunan Mandiri, PT Cipta Graha Konstruksindo']);

    // ===================================================================
    // 911 - Locked, ready for winner selection (Category Manager on
    // committee: adonis.sutanto), for demoing "who can close a Locked tender"
    // ===================================================================
    $deadline911 = at(-10, '17:00:00');
    $created911 = at(-18, '09:00:00');
    $insRfq->execute([
        'id' => 911, 'package_name' => 'Pengadaan Printer Receipt Thermal untuk Outlet Retail 2026',
        'deadline' => $deadline911, 'status' => 'Locked', 'created_by' => 'ririn.hayanto', 'created_at' => $created911,
        'winner_vendor_id' => null, 'final_price' => null, 'closed_by' => null, 'closed_at' => null,
        'parent_id' => null, 'round_number' => 1, 'rfq_type' => 'Standard',
        'pending_deadline' => null, 'deadline_request_by' => null, 'deadline_request_status' => null,
    ]);
    $insItem->execute([911, 'T2603A95043', '10', '[8584] Printer Receipt Thermal USB + Ethernet', 25.00, 'EA']);
    $insCommittee->execute([911, 'adonis.sutanto', 1, at(-9, '10:15:00')]);
    $insCommittee->execute([911, 'hadi.risali', 1, at(-9, '11:00:00')]);
    $insVendor->execute([911, 'PT Cetak Cepat Nusantara', 'tender@cetakcepat.co.id', '628123456791', 'Yohan Prasetyo', secret(), 62500000.00, 'Exclude', '30 Hari', at(-12, '10:00:00'), 'APPROVED', null, null, null]);
    $insVendor->execute([911, 'CV Struk Elektronik Sejahtera', 'sales@strukelektronik.co.id', '628123456792', 'Diana Kartika', secret(), 59800000.00, 'Include', '30 Hari', at(-12, '14:30:00'), 'APPROVED', null, null, null]);
    $insVendor->execute([911, 'PT Mitra POS Indonesia', 'procurement@mitrapos.co.id', '628123456793', 'Bayu Anggara', secret(), 64200000.00, 'Exclude', '14 Hari', at(-11, '09:20:00'), 'APPROVED', null, null, null]);
    $insLog->execute([911, 'ririn.hayanto', 'CREATE', null, $created911, 'Tender Pengadaan Printer Receipt Thermal dibuat']);
    $insLog->execute([911, 'ririn.hayanto', 'INVITE', null, plusHours($created911, 1), 'Invited PT Cetak Cepat Nusantara, CV Struk Elektronik Sejahtera, PT Mitra POS Indonesia']);
    $insLog->execute([911, 'System', 'SUBMIT_QUOTATION', null, at(-12, '10:00:00'), 'PT Cetak Cepat Nusantara submitted quotation']);
    $insLog->execute([911, 'System', 'SUBMIT_QUOTATION', null, at(-12, '14:30:00'), 'CV Struk Elektronik Sejahtera submitted quotation']);
    $insLog->execute([911, 'System', 'SUBMIT_QUOTATION', null, at(-11, '09:20:00'), 'PT Mitra POS Indonesia submitted quotation']);
    $insLog->execute([911, 'adonis.sutanto', 'UNSEAL', null, at(-9, '10:15:00'), null]);
    $insLog->execute([911, 'hadi.risali', 'UNSEAL', null, at(-9, '11:00:00'), null]);
    $insLog->execute([911, 'rina.susanto', 'LOCKED', null, at(-8, '08:30:00'), null]);

    $pdo->commit();
    echo "Seed complete. RFQ ids 901-911 created.\n";

    $check = $pdo->query("SELECT id, package_name, status, rfq_type, round_number, deadline FROM rfqs WHERE id BETWEEN 901 AND 911 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($check as $r) {
        echo "  [{$r['id']}] {$r['package_name']} | {$r['status']} | {$r['rfq_type']} R{$r['round_number']} | deadline {$r['deadline']}\n";
    }
} catch (Exception $e) {
    $pdo->rollBack();
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
