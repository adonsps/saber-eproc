<?php
// modules/vendormgt/vendor_active_action.php
//
// Deactivate or reactivate a vendor. This is how a duplicate registration is
// retired — never by DELETE.
//
// A duplicate row still carries real history: PQ submissions, notifications,
// document audits, its login. None of those tables has a foreign key back to
// `vendors`, so deleting the row would silently orphan every one of them with no
// way to reconstruct what happened. Flipping `is_active` keeps the evidence,
// stays reversible, and leaves an audit trail of who did it and why.
//
// Deactivating blocks the vendor's login (see login_action.php) and takes the
// row out of the registration duplicate check, so the surviving record is the
// only one a new registrant is matched against.
//
// ACCESS: Vendor Management only — vendor_mgt and vendormgt_head, matching the
// gate on modules/vendormgt/all_vendors.php.

session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['vendor_mgt', 'vendormgt_head'], true)) {
    http_response_code(403);
    die("Access Denied");
}

// State-changing, so POST only: a GET would let a link or a prefetch flip a
// vendor's access without anyone deciding to.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    die("Method Not Allowed");
}

// ── CSRF guard ───────────────────────────────────────────────────────────────
// POST-only stops a prefetch, not a cross-site form: without this, any page a
// logged-in VM user visits could silently post here and cut off a vendor's
// login. Same pattern as modules/admin/holidays_action.php; the token is minted
// on the page that renders the forms (duplicate_vendors.php).
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    die("Invalid CSRF token.");
}

$vendor_id = (int) ($_POST['vendor_id'] ?? 0);
$action    = (string) ($_POST['action'] ?? '');
$reason    = trim((string) ($_POST['reason'] ?? ''));
$return_to = (string) ($_POST['return_to'] ?? 'duplicate_vendors.php');
$actor     = (int) $_SESSION['user_id'];

// Only ever bounce back inside this module — never to a URL the request supplied.
if (!preg_match('/^[a-z0-9_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $return_to)) {
    $return_to = 'duplicate_vendors.php';
}

$back = static function (string $url, string $key, string $msg): never {
    header('Location: ' . $url . (str_contains($url, '?') ? '&' : '?') . $key . '=' . urlencode($msg));
    exit();
};

if ($vendor_id <= 0 || !in_array($action, ['deactivate', 'reactivate'], true)) {
    $back($return_to, 'error', 'Permintaan tidak valid.');
}

// Confirm the vendor exists, and capture the name for the flash message.
$stmt = $conn->prepare("SELECT company_name, is_active FROM vendors WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$vendor) {
    $back($return_to, 'error', 'Vendor tidak ditemukan.');
}

$name = $vendor['company_name'];

if ($action === 'deactivate') {
    if ((int) $vendor['is_active'] === 0) {
        $back($return_to, 'error', "{$name} sudah nonaktif.");
    }
    // A reason is required going out and cleared coming back, so a row's stored
    // reason always belongs to the deactivation currently in force.
    if ($reason === '') {
        $back($return_to, 'error', 'Alasan wajib diisi saat menonaktifkan vendor.');
    }
    $reason = mb_substr($reason, 0, 255);

    $stmt = $conn->prepare(
        "UPDATE vendors
            SET is_active = 0, deactivated_at = NOW(), deactivated_by = ?, deactivation_reason = ?
          WHERE id = ? AND is_active = 1"
    );
    $stmt->bind_param("isi", $actor, $reason, $vendor_id);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();

    if ($changed < 1) {
        $back($return_to, 'error', "Gagal menonaktifkan {$name}.");
    }
    $back($return_to, 'success', "{$name} dinonaktifkan. Vendor tidak dapat login lagi.");
}

// --- reactivate -------------------------------------------------------------
if ((int) $vendor['is_active'] === 1) {
    $back($return_to, 'error', "{$name} sudah aktif.");
}

$stmt = $conn->prepare(
    "UPDATE vendors
        SET is_active = 1, deactivated_at = NULL, deactivated_by = NULL, deactivation_reason = NULL
      WHERE id = ? AND is_active = 0"
);
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$changed = $stmt->affected_rows;
$stmt->close();

if ($changed < 1) {
    $back($return_to, 'error', "Gagal mengaktifkan kembali {$name}.");
}
$back($return_to, 'success', "{$name} diaktifkan kembali.");
