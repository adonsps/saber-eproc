<?php
// modules/sourcing/review_action.php
session_start();
require '../../config.php';

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'sourcing') {
    die("Access Denied");
}

// Show validation/processing errors as the review page's app-style popup modal
// (via $_SESSION['flash_error']) instead of a native browser alert(). review_pq.php
// takes ?id=<vendor_id>, so we always redirect back with the vendor id.
function flashErrorBack(int $vendor_id, string $message): void
{
    $_SESSION['flash_error'] = $message;
    echo "<script>window.location.href = 'review_pq.php?id=" . intval($vendor_id) . "';</script>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $sub_id = intval($_POST['submission_id']);
    $vendor_id = intval($_POST['vendor_id']);
    $notes = mysqli_real_escape_string($conn, str_replace(["\r\n", "\r"], "\n", $_POST['sourcing_notes'] ?? ''));
    $action = $_POST['action'];
    $current_user_id = $_SESSION['user_id'];

    // Server-side backstop: High/Critical vendors must document vendor type +
    // reason in the note (client JS can be bypassed). Recompute the risk
    // category from stored totals — never trust the client — using the same
    // logic as modules/sourcing/review_pq.php. Kept here (not in the shared
    // advanceSubmissionToVm helper) so the Direct Registration flow is unaffected.
    require_once '../../includes/pq_risk_helper.php';
    require_once '../../includes/sourcing_note_helper.php';
    $rc_row = $conn->query("SELECT s.total_weighted_score, s.max_possible_weighted_score, v.company_type, v.sub_company_type
        FROM pq_submissions s JOIN vendors v ON v.id = s.vendor_id WHERE s.id = " . intval($sub_id))->fetch_assoc();
    $server_risk_category = pqRiskCategory(pqRiskPercentage(
        (float)($rc_row['total_weighted_score'] ?? 0),
        (float)($rc_row['max_possible_weighted_score'] ?? 0)
    ));
    $notes_raw = trim(str_replace(["\r\n", "\r"], "\n", $_POST['sourcing_notes'] ?? ''));
    if (sourcingNoteRequiresStructure($server_risk_category)) {
        // Require a Reason; the vendor type is taken canonically from the vendor
        // record (never trust the client). The note is then rebuilt so storage is
        // consistent regardless of what was posted (client JS can be bypassed).
        $reason = extractSourcingReason($notes_raw);
        if ($reason === '') {
            flashErrorBack($vendor_id, 'This vendor is ' . $server_risk_category . ' risk — you must document the Reason for Registration before proceeding.');
        }
        $canonical_type = formatRegisteredVendorType($rc_row['company_type'] ?? '', $rc_row['sub_company_type'] ?? '');
        $notes_raw = composeSourcingNote($canonical_type, $reason);
        $notes     = mysqli_real_escape_string($conn, $notes_raw);
    }

    if ($action == 'approve_sourcing') {

        require_once '../../includes/pq_workflow_helper.php';

        // Wrap all writes in one atomic transaction.
        // Previously: vendors UPDATE and openPqSlaLeg auto-committed before
        // $stmt->execute(), leaving orphaned SLA legs if the submission UPDATE failed.
        $conn->begin_transaction();
        try {
            // Validates sub_company_type + the 16-digit NPWP gate, advances
            // pq_submissions to under_review_vm, mirrors vendors status, and
            // opens the 16-working-hour SLA leg (2 working days). Also used by
            // the Direct Registration impersonation flow in modules/vendor/submit_pq.php,
            // so both paths enforce the exact same rules and can't drift apart.
            advanceSubmissionToVm($conn, $sub_id, $vendor_id, $current_user_id, $notes);

            $conn->commit();

        } catch (Exception $e) {
            $conn->rollback();
            flashErrorBack($vendor_id, $e->getMessage());
        }

        // Flash the success message for the dashboard's on-brand popup (ftPopup)
        // instead of a native browser alert().
        $_SESSION['flash_success'] = 'Verified! Application sent to Vendor Management Team.';
        echo "<script>window.location.href = 'dashboard.php';</script>";
        exit;

    } elseif ($action == 'request_revision') {
        // Direct Registration: Sourcing filled/submitted the PQ themselves —
        // the vendor never touched this submission, so sending a revision
        // request TO the vendor makes no sense. review_pq.php already hides
        // this button for these vendors; this is the server-side backstop
        // (mirrors the same guard on 'return_to_vendor' in
        // modules/vendormgt/approve_action.php).
        $v_check = $conn->query("SELECT registered_by_sourcing_id FROM vendors WHERE id = " . intval($vendor_id))->fetch_assoc();
        if (!empty($v_check['registered_by_sourcing_id'])) {
            flashErrorBack($vendor_id, 'This submission was filled by Sourcing via Direct Registration — the vendor was never involved and cannot be sent a revision request.');
        }

        // Send back to Vendor for Revision. Reuse the already-normalized note
        // ($notes_raw), which is the authoritative rebuilt note for High/Critical.
        $message = $notes_raw;
        if ($message === '') {
            flashErrorBack($vendor_id, 'A summary note is required.');
        }
        $sourcing_name = $_SESSION['user_name'] ?? '';
        // Overwrite the vendor-facing note with the latest message only (no trail),
        // matching VM behaviour.
        $notes_to_vendor = date('d F Y H.i') . ' - ' . $message . ' ; ' . $sourcing_name;

        $sql  = "UPDATE pq_submissions SET status = 'revision_needed', sourcing_notes = ?, notes_to_vendor = ?, sourcing_reviewer_id = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssii", $notes, $notes_to_vendor, $current_user_id, $sub_id);

        // Update Vendor Status to match
        $conn->query("UPDATE vendors SET registration_status = 'revision_requested' WHERE id = $vendor_id");

        $msg = "Returned to Vendor for Revision. You are now the assigned reviewer.";

    } else {
        die("Invalid action.");
    }

    // Reached only by request_revision (approve_sourcing exits above)
    if ($stmt->execute()) {
        // Email the vendor that their submission needs revision.
        // $message holds the clean, vendor-facing note built above.
        $cn = $conn->query("SELECT company_name FROM vendors WHERE id = " . intval($vendor_id));
        $vendorName = ($cn && ($cnRow = $cn->fetch_assoc())) ? ($cnRow['company_name'] ?? '') : '';
        require_once '../../includes/notification_helper.php';
        notifyOnVendorRevision((int)$current_user_id, (int)$vendor_id, $vendorName, $message, 'sourcing', 0);

        // Flash for the dashboard's on-brand popup instead of a native alert().
        $_SESSION['flash_success'] = $msg;
        echo "<script>window.location.href = 'dashboard.php';</script>";
    } else {
        flashErrorBack($vendor_id, 'Could not save the revision request: ' . $conn->error);
    }
} else {
    header("Location: dashboard.php");
}
?>