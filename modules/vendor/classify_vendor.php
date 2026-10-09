<?php
// modules/vendor/classify_vendor.php
// Direct Registration only: saves the Vendor Sub-Category declared right
// before the PQ form (see the gate in pq_form.php), then filters the PQ
// question set to only that category (every pq_questions row maps to
// exactly one pq_weight.sub_variant_target).
session_start();
require '../../config.php';
require_once '../../includes/vendor_context.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vendor' || empty($_SESSION['impersonator'])) {
    die("Access Denied");
}

// The impersonator check above also lets a staff profile-correction session
// through (see modules/shared/edit_vendor_profile.php) — that one is scoped to
// profile.php only, not PQ classification.
vendor_staff_edit_restrict(fn() => die("Access Denied"));

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT id, company_type, is_umkm, legal_entity_type, registration_status FROM vendors WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();
if (!$vendor) die("Vendor profile not found.");

// Let Sourcing re-pick the category to correct a mis-classification. Only
// allowed before the submission actually reaches VM (registration_status
// flips away from the pre-VM statuses at that point, same gate used
// elsewhere for the "resume" flow) — once it's with VM the category is part
// of a decision that's already been handed off and shouldn't move under it.
$editable_statuses = ['registration_submitted', 'registered', 'min_info_submitted', 'invited_pq', 'draft', 'revision_requested'];
if (isset($_GET['reset'])) {
    if (!in_array($vendor['registration_status'], $editable_statuses, true)) {
        header("Location: pq_form.php?error=" . urlencode("Pengajuan sudah tidak dapat diubah kategorinya."));
        exit();
    }

    // Switching category means switching to a completely different question
    // set (no question is shared between categories), so any answers saved
    // under the old category no longer apply to the new one — clear them
    // rather than leaving stale/mismatched data behind.
    $conn->begin_transaction();
    try {
        $sub_q = $conn->query("SELECT id FROM pq_submissions WHERE vendor_id = " . intval($vendor['id']));
        if ($sub_row = $sub_q->fetch_assoc()) {
            $conn->query("DELETE FROM pq_answers WHERE submission_id = " . intval($sub_row['id']));
            $conn->query("DELETE FROM pq_submissions WHERE id = " . intval($sub_row['id']));
        }
        $conn->query("UPDATE vendors SET sub_company_type = NULL WHERE id = " . intval($vendor['id']));
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: pq_form.php?error=" . urlencode("Gagal mengubah kategori: " . $e->getMessage()));
        exit();
    }

    header("Location: pq_form.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: pq_form.php");
    exit();
}

if (vendor_uses_perseorangan_pq($vendor['company_type'], $vendor['is_umkm'] ?? 0)) {
    // Standalone sole-proprietor company_type, or a self-declared UMKM vendor
    // -> always the Perseorangan question set.
    $valid_sub_types = ['Perseorangan'];
} elseif ($vendor['company_type'] === 'Contractor') {
    $valid_sub_types = ($vendor['legal_entity_type'] === 'Perseorangan')
        ? ['Individual / Foreman']
        : ['Project', 'Retail & Maintenance', 'Individual / Foreman'];
} else {
    $valid_sub_types = ['Principal', 'Distributor', 'Retail', 'Service'];
}

$declared_sub_type = trim($_POST['sub_company_type'] ?? '');
if (!in_array($declared_sub_type, $valid_sub_types, true)) {
    header("Location: pq_form.php?error=" . urlencode("Sub-Kategori Vendor tidak valid."));
    exit();
}

$stmt_upd = $conn->prepare("UPDATE vendors SET sub_company_type = ? WHERE id = ?");
$stmt_upd->bind_param("si", $declared_sub_type, $vendor['id']);
$stmt_upd->execute();

header("Location: pq_form.php");
exit();
