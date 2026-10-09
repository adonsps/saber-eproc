<?php
require_once 'config.php';
$ws_conn = getWorkspaceConnection(); // For workspace tables

$password_plain = 'password123';
$password_hashed = password_hash($password_plain, PASSWORD_DEFAULT);

// Update passwords for Theodore, Hadi, Hartanto in Workspace DB
$users_to_update = ['theodore.seamditia@kawanlamacorp.com', 'hadi.risali@kawanlamacorp.com', 'hartanto@kawanlamacorp.com'];
foreach ($users_to_update as $u) {
    $stmt = $ws_conn->prepare("UPDATE users SET password = ? WHERE email = ?");
    $stmt->bind_param("ss", $password_hashed, $u);
    $stmt->execute();
}

// Update passwords in VSS DB (uses $conn from config.php)
foreach ($users_to_update as $u) {
    $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
    $stmt->bind_param("ss", $password_hashed, $u);
    $stmt->execute();
}

// Clear old dummy data if exists
$ws_conn->query("DELETE FROM rfq_vendors WHERE rfq_id = 999");
$ws_conn->query("DELETE FROM rfqs WHERE id = 999");
$ws_conn->query("DELETE FROM rfq_committee WHERE rfq_id = 999");
$ws_conn->query("DELETE FROM rfq_audit_logs WHERE rfq_id = 999");

$package_name = "Tender Pengadaan Server & Perangkat IT Corporate 2026";
$deadline = date('Y-m-d H:i:s', strtotime('-1 day'));

// Insert RFQ - Theodore as PIC
$stmt = $ws_conn->prepare("SELECT username FROM users WHERE email = ?");
$stmt->bind_param("s", $users_to_update[0]);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$theodore_user = $row['username'];

$stmt = $ws_conn->prepare("INSERT INTO rfqs (id, package_name, deadline, status, created_by, round_number, rfq_type) VALUES (999, ?, ?, 'Active', ?, 1, 'Standard')");
$stmt->bind_param("sss", $package_name, $deadline, $theodore_user);
$stmt->execute();

// Insert Vendors (Quotations)
$vendors = [
    ['name' => 'PT Teknologi Masa Depan', 'email' => 'tmd@example.com', 'pic' => 'Budi', 'price' => 145500000.00, 'time' => '-12 hours'],
    ['name' => 'CV Digital Solusi', 'email' => 'digital@example.com', 'pic' => 'Andi', 'price' => 152000000.00, 'time' => '-14 hours'],
    ['name' => 'PT Mega Cyber Persada', 'email' => 'mega@example.com', 'pic' => 'Citra', 'price' => 139000000.00, 'time' => '-16 hours']
];

foreach ($vendors as $v) {
    $submitted = date('Y-m-d H:i:s', strtotime($v['time']));
    $stmt = $ws_conn->prepare("INSERT INTO rfq_vendors (rfq_id, vendor_name, vendor_email, vendor_pic, vendor_wa, secret_password, total_price, ppn_status, price_validity, submitted_at, final_boq) VALUES (999, ?, ?, ?, '0812345678', 'secret123', ?, 'Exclude', '30 Days', ?, 'dummy_boq.pdf')");
    $stmt->bind_param("sssds", $v['name'], $v['email'], $v['pic'], $v['price'], $submitted);
    $stmt->execute();
}

// Assign Committee: Hadi and Hartanto
$stmt = $ws_conn->prepare("INSERT INTO rfq_committee (rfq_id, username, has_opened) VALUES (999, 'hadi.risali@kawanlamacorp.com', 0)");
$stmt->execute();

$stmt = $ws_conn->prepare("INSERT INTO rfq_committee (rfq_id, username, has_opened) VALUES (999, 'hartanto@kawanlamacorp.com', 0)");
$stmt->execute();

$logs = [
    ['action' => 'CREATE', 'details' => 'Tender Pengadaan Server created', 'user' => $theodore_user],
    ['action' => 'INVITE', 'details' => 'Invited PT Teknologi Masa Depan, CV Digital Solusi, PT Mega Cyber Persada', 'user' => $theodore_user],
    ['action' => 'SUBMIT_QUOTATION', 'details' => 'PT Teknologi Masa Depan submitted quotation', 'user' => 'System'],
    ['action' => 'SUBMIT_QUOTATION', 'details' => 'CV Digital Solusi submitted quotation', 'user' => 'System'],
    ['action' => 'SUBMIT_QUOTATION', 'details' => 'PT Mega Cyber Persada submitted quotation', 'user' => 'System'],
];

foreach ($logs as $log) {
    $stmt = $ws_conn->prepare("INSERT INTO rfq_audit_logs (rfq_id, action_type, description, username) VALUES (999, ?, ?, ?)");
    $stmt->bind_param("sss", $log['action'], $log['details'], $log['user']);
    $stmt->execute();
}

echo "Dummy RFQ 999 created successfully with Theodore, Hadi, Hartanto!";
?>
