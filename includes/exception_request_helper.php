<?php
// includes/exception_request_helper.php
//
// Exception Request no longer has any state on the vendors table — a vendor's
// current Exception Request state is simply its most recent exception_requests
const EXCEPTION_REQUEST_LATEST_JOIN = "
    LEFT JOIN (
        SELECT err.*
        FROM exception_requests err
        INNER JOIN (
            SELECT vendor_id, MAX(id) AS max_id
            FROM exception_requests
            GROUP BY vendor_id
        ) latest ON err.vendor_id = latest.vendor_id AND err.id = latest.max_id
    ) er ON er.vendor_id = v.id
";

// Equivalent of the old vendors.is_fast_track = 1: the vendor's latest cycle
// is still active (pending review or already approved), not rejected/absent.
function isExceptionRequestActive(?string $erStatus): bool
{
    return in_array($erStatus, ['under_review_vm_head', 'approved'], true);
}

// Maks vendor per user sourcing yang boleh sekaligus punya Exception Request
// aktif (under_review_vm_head/approved) dengan PQ belum selesai. Pengajuan baru
// diblokir sampai minimal satu PQ vendor tersebut disetujui final.
const EXCEPTION_REQUEST_MAX_OPEN_PQ = 3;

/**
 * Vendor yang sedang memakai kuota Exception Request user sourcing ini:
 * baris ER terbaru vendor berstatus aktif, diajukan oleh user tsb, dan PQ-nya
 * belum disetujui final (registration_status <> 'approved').
 *
 * @return array{count:int, max:int, full:bool, vendors:array<int,array{id:int,company_name:string}>}
 */
function getExceptionRequestPqDebt(mysqli $conn, int $sourcingUserId): array
{
    $sql = "SELECT v.id, v.company_name
            FROM vendors v " . EXCEPTION_REQUEST_LATEST_JOIN . "
            WHERE er.status IN ('under_review_vm_head','approved')
              AND er.submitted_by = ?
              AND v.registration_status <> 'approved'
              AND v.user_id NOT IN (SELECT id FROM users WHERE is_test_account = 1)
            ORDER BY v.company_name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $sourcingUserId);
    $stmt->execute();
    $res = $stmt->get_result();

    $vendors = [];
    while ($row = $res->fetch_assoc()) {
        $vendors[] = ['id' => (int)$row['id'], 'company_name' => (string)$row['company_name']];
    }
    $count = count($vendors);

    return [
        'count'   => $count,
        'max'     => EXCEPTION_REQUEST_MAX_OPEN_PQ,
        'full'    => $count >= EXCEPTION_REQUEST_MAX_OPEN_PQ,
        'vendors' => $vendors,
    ];
}

/**
 * Guard kuota Exception Request — satu-satunya pintu pengecekan untuk semua
 * endpoint pengajuan. Tidak melakukan apa-apa bila user masih boleh mengajukan.
 * Bila kuota penuh, $fail dipanggil dengan pesan blokir + extra
 * ['quota_full' => true] (dipakai JS dashboard untuk menutup modal ER otomatis)
 * dan responder tersebut mengakhiri request. Pesan sengaja TIDAK menyebut angka
 * batas/jumlah — hanya meminta sourcing menyelesaikan PQ vendornya dahulu.
 *
 * @param callable(string,array):void $fail Responder error milik endpoint pemanggil.
 */
function enforceExceptionRequestQuota(mysqli $conn, int $sourcingUserId, callable $fail): void
{
    $debt = getExceptionRequestPqDebt($conn, $sourcingUserId);
    if (!$debt['full']) {
        return;
    }
    $names = array_column($debt['vendors'], 'company_name');
    $fail(
        'Terdapat vendor dengan Exception Request aktif yang proses PQ-nya belum selesai: '
        . implode(', ', $names) . '. '
        . 'Selesaikan PQ vendor tersebut terlebih dahulu sebelum mengajukan Exception Request baru.',
        // 'vendors' terstruktur dipakai popup kuota di dashboard untuk merender
        // daftar per baris; 'message' tetap fallback untuk respons non-AJAX.
        ['quota_full' => true, 'vendors' => $names]
    );
}

/**
 * Per-VM-Head-user personalization. Keyed by users.id. Add a new entry here
 * whenever another VM Head account needs its own scope/default view — every
 * setting falls back to the shared default (see vmHeadSetting()) if omitted.
 *
 * Settings:
 * - can_decide_exception_request: if true, both Approve and Reject are
 *   enabled — everyone else gets read-only access to the review page.
 *   Defaults to false: Hadi is currently the only VM Head who decides
 *   Exception Requests.
 * - default_dashboard_filter: dashboard.php `filter` used on first load
 *   (before the user picks a tab), overriding the normal 'worklist' default.
 */
const VM_HEAD_USER_SETTINGS = [
    142 => [ // Hadi Sanjaya Risali — the only VM Head who can decide Exception
             // Requests; dashboard also opens straight to Exception Requests.
        'can_decide_exception_request' => true,
        'default_dashboard_filter' => 'exception_request_waiting',
    ],
];

function vmHeadSetting(int $userId, string $key, $default)
{
    return VM_HEAD_USER_SETTINGS[$userId][$key] ?? $default;
}

function canDecideExceptionRequest(int $userId): bool
{
    return vmHeadSetting($userId, 'can_decide_exception_request', false);
}

// ─────────────────────────────────────────────────────────────────────────────
// EXCEPTION REQUEST OTP (replaces the old password confirmation on submit).
//
// The Sourcing user requests a 6-digit OTP, which is delivered by WhatsApp to
// their direct supervisor. Supervisor and WhatsApp number are NOT stored on the
// vmsportal `users` table — they live in the separate WORKSPACE database:
//   vmsportal users.email  ──(same email)──►  workspace users.email
//   workspace users.manager_username ─────►  workspace users.username (supervisor)
//   supervisor.phone_number  = WhatsApp destination
// ─────────────────────────────────────────────────────────────────────────────

/** Generate a fresh 6-digit numeric OTP (leading zeros preserved). */
function generateExceptionRequestOtp(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Normalize an Indonesian phone number to Fonnte's expected "62xxxxxxxx" form,
 * mirroring tools/import_vendor_eval_requestors.php::normalize_whatsapp().
 * Returns '' when the input is empty or not a plausible phone number.
 */
function normalizeExceptionRequestWa(?string $raw): string
{
    $v = preg_replace('/[\s\-\(\)\.]/', '', trim((string)$raw));
    if ($v === '') return '';
    if (strpos($v, '+') === 0) $v = substr($v, 1);      // +62... / +... -> strip the plus
    if (strpos($v, '0') === 0) $v = '62' . substr($v, 1); // local 08xx -> 62 8xx
    if (!ctype_digit($v)) return '';
    $len = strlen($v);
    if ($len < 9 || $len > 15) return '';
    return $v;
}

/** Mask a phone number for display/logging: 6281234567 -> 6281****567 */
function maskExceptionRequestWa(string $phone): string
{
    $len = strlen($phone);
    if ($len <= 7) return str_repeat('*', max(0, $len));
    return substr($phone, 0, 4) . str_repeat('*', $len - 7) . substr($phone, -3);
}

/**
 * Resolve the direct supervisor (name + normalized WhatsApp number) for a
 * Sourcing user by crossing into the workspace database. Never throws.
 *
 * @return array{ok: bool, name: string, phone: string, error: string}
 *         On success: ok=true, name + phone populated (phone already normalized).
 *         On failure: ok=false, error holds a user-facing Indonesian message.
 */
function findSourcingSupervisorWa(mysqli $conn, int $sourcingUserId): array
{
    $out = ['ok' => false, 'name' => '', 'phone' => '', 'error' => ''];

    // 1. Sourcing user's email (the cross-database key).
    $stmt = $conn->prepare("SELECT email, full_name FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $sourcingUserId);
    $stmt->execute();
    $su = $stmt->get_result()->fetch_assoc();
    if (!$su || empty($su['email'])) {
        $out['error'] = 'Akun Anda tidak memiliki email yang terdaftar.';
        return $out;
    }
    $email = (string)$su['email'];

    // 2. Workspace DB.
    if (!function_exists('getWorkspaceConnection')) {
        $out['error'] = 'Konfigurasi database workspace tidak tersedia.';
        return $out;
    }
    $ws = getWorkspaceConnection();
    if (!($ws instanceof mysqli)) {
        $out['error'] = 'Koneksi ke database workspace gagal. Coba lagi nanti.';
        return $out;
    }

    // 3. Workspace user (matched by email) -> manager_username.
    $wstmt = $ws->prepare("SELECT manager_username FROM users WHERE email = ? LIMIT 1");
    $wstmt->bind_param("s", $email);
    $wstmt->execute();
    $wu = $wstmt->get_result()->fetch_assoc();
    if (!$wu) {
        $ws->close();
        $out['error'] = 'Data karyawan Anda tidak ditemukan di database workspace. Hubungi admin.';
        return $out;
    }
    $mgr = trim((string)($wu['manager_username'] ?? ''));
    if ($mgr === '') {
        $ws->close();
        $out['error'] = 'Supervisor / atasan Anda belum diatur di database workspace. Hubungi admin.';
        return $out;
    }

    // 4. Supervisor's phone number.
    $mstmt = $ws->prepare("SELECT name, phone_number FROM users WHERE username = ? LIMIT 1");
    $mstmt->bind_param("s", $mgr);
    $mstmt->execute();
    $mr = $mstmt->get_result()->fetch_assoc();
    $ws->close();
    if (!$mr) {
        $out['error'] = 'Akun supervisor tidak ditemukan di database workspace. Hubungi admin.';
        return $out;
    }

    $phone = normalizeExceptionRequestWa($mr['phone_number'] ?? '');
    if ($phone === '') {
        $out['error'] = 'Nomor WhatsApp supervisor (' . ($mr['name'] ?? $mgr) . ') belum terdaftar. Hubungi admin.';
        return $out;
    }

    $out['ok']    = true;
    $out['name']  = (string)($mr['name'] ?? 'Supervisor');
    $out['phone'] = $phone;
    return $out;
}

/**
 * Build the "Exception Request Approval" PDF — a stand-in cover document for the
 * PQ Result, since PQ has not been done yet for an Exception Request vendor (the
 * real PQ Result PDF is generated separately and independently once/if PQ is later
 * approved).
 */
function generateExceptionRequestApprovalPdf(
    mysqli $conn,
    int $vendorId,
    string $company,
    int $erId,
    int $sourcingId,
    string $vmHeadName,
    string $vmNotes
): string {
    require_once __DIR__ . '/../libs/tcpdf/tcpdf.php';

    $v_stmt = $conn->prepare("SELECT v.* FROM vendors v WHERE v.id = ?");
    $v_stmt->bind_param("i", $vendorId);
    $v_stmt->execute();
    $vendor_full = $v_stmt->get_result()->fetch_assoc() ?: [];

    $er_detail_stmt = $conn->prepare("SELECT submitted_at, reason, submitted_notes FROM exception_requests WHERE id = ?");
    $er_detail_stmt->bind_param("i", $erId);
    $er_detail_stmt->execute();
    $er_detail = $er_detail_stmt->get_result()->fetch_assoc() ?: [];

    $submitted_by_name = '-';
    if ($sourcingId > 0) {
        $sub_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
        $sub_stmt->bind_param("i", $sourcingId);
        $sub_stmt->execute();
        $sub_row = $sub_stmt->get_result()->fetch_assoc();
        $submitted_by_name = $sub_row['full_name'] ?? '-';
    }

    if (ob_get_length()) ob_clean();

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Kawan Lama VMS');
    $pdf->SetAuthor('Vendor Management System');
    $pdf->SetTitle('Exception Request Approval - ' . $company);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10);

    $label_style = 'border-bottom:1px solid #e9ecef; color:#495057; font-size:9.5px;';
    $value_style = 'border-bottom:1px solid #e9ecef; color:#212529; font-size:10px;';
    $zebra       = 'background-color:#f8f9fa;';

    // One striped label/value row; $last drops the bottom border, $extra appends cell styles.
    $infoRow = function (string $label, string $value, bool $odd, bool $last = false, string $extra = '') use ($label_style, $value_style, $zebra): string {
        $ls = $last ? str_replace('border-bottom:1px solid #e9ecef;', '', $label_style) : $label_style;
        $vs = $last ? str_replace('border-bottom:1px solid #e9ecef;', '', $value_style) : $value_style;
        return '
        <tr' . ($odd ? ' style="' . $zebra . '"' : '') . '>
            <td width="32%" style="' . $ls . '"><strong>' . $label . '</strong></td>
            <td width="68%" style="' . $vs . $extra . '">' . $value . '</td>
        </tr>';
    };

    // Section heading + blue underline, kept inside a cellpadding="8" table so its
    // edges align with the data tables (see NB2 above).
    $sectionHeading = function (string $title): string {
        return '
    <table cellpadding="8" cellspacing="0" width="100%">
        <tr><td style="border-bottom:2px solid #0056b3;"><span style="color:#0056b3; font-size:13px; font-weight:bold; letter-spacing:0.5px;">' . $title . '</span></td></tr>
    </table>';
    };

    $header_html = '
    <table cellpadding="8" cellspacing="0" width="100%">
        <tr>
            <td style="background-color:#fd7e14; color:#ffffff;">
                <span style="font-size:20px; font-weight:bold; letter-spacing:0.5px;">EXCEPTION REQUEST APPROVAL</span><br>
                <span style="font-size:10px; color:#ffe9d1;">Vendor Sourcing System - Kawan Lama Group</span>
            </td>
        </tr>
        <tr>
            <td style="border:1px solid #ffe1b8; background-color:#fff8ef; color:#7a4a00; font-size:9px; line-height:1.5;">Dokumen ini merupakan bukti persetujuan <strong>sementara </strong>untuk melakukan Vendor Registrasi <strong>(Belum melalui Tahapan PQ)</strong>.
            Dokumen PQ Result resmi akan menyusul dan menggantikan dokumen ini setelah proses PQ vendor ini selesai.</td>
        </tr>
    </table>';

    $company_html = '
    <table cellpadding="8" cellspacing="0" width="100%" style="border:1px solid #dee2e6;">'
        . $infoRow('Company', '<strong>' . htmlspecialchars($company) . '</strong>', true)
        . $infoRow('Type', htmlspecialchars($vendor_full['company_type'] ?? '-'), false, true) . '
    </table>';

    $detail_html = '
    <table cellpadding="8" cellspacing="0" width="100%" style="border:1px solid #dee2e6;">'
        . $infoRow('Submitted By', htmlspecialchars($submitted_by_name), true)
        . $infoRow('Date Submitted', (!empty($er_detail['submitted_at']) ? date('d M Y H:i', strtotime($er_detail['submitted_at'])) . ' WIB' : '-'), false)
        . $infoRow('Vendor Condition', htmlspecialchars($er_detail['reason'] ?? '-'), true)
        . $infoRow('Notes', nl2br(htmlspecialchars($er_detail['submitted_notes'] ?: '-')), false, false, ' line-height:1.5;')
        . $infoRow('Approved By', htmlspecialchars($vmHeadName), true)
        . $infoRow('VM Head Notes', nl2br(htmlspecialchars($vmNotes ?: '-')), false, false, ' line-height:1.5;') . '
        <tr style="background-color:#d4edda;">
            <td width="32%" style="color:#155724; font-size:9.5px;"><strong>Date Approved</strong></td>
            <td width="68%" style="color:#155724; font-size:10px;"><strong>' . date('d M Y H:i') . ' WIB</strong></td>
        </tr>
    </table>';

    $profile_html = '
    <table cellpadding="8" cellspacing="0" width="100%" style="border:1px solid #dee2e6;">'
        . $infoRow('Legal Entity', htmlspecialchars($vendor_full['legal_entity_type'] ?? '-'), true)
        . $infoRow('Director / Owner', htmlspecialchars($vendor_full['director_name'] ?? '-'), false)
        . $infoRow('Contact Person', htmlspecialchars($vendor_full['contact_person'] ?? '-'), true)
        . $infoRow('Phone', htmlspecialchars($vendor_full['phone'] ?? '-'), false)
        . $infoRow('PIC Finance', htmlspecialchars($vendor_full['pic_finance'] ?? '-'), true)
        . $infoRow('Email PIC Finance', htmlspecialchars($vendor_full['email_pic_finance'] ?? '-'), false)
        . $infoRow('Email', htmlspecialchars($vendor_full['email'] ?? '-'), true)
        . $infoRow('Establishment Date', (!empty($vendor_full['establishment_date']) ? date('d M Y', strtotime($vendor_full['establishment_date'])) : '-'), false)
        . $infoRow('Core Business', htmlspecialchars($vendor_full['core_business'] ?? '-'), true)
        . $infoRow('NIB Number', htmlspecialchars($vendor_full['nib_number'] ?? '-'), false)
        . $infoRow('NPWP Number', htmlspecialchars($vendor_full['npwp_number'] ?? '-'), true)
        . $infoRow('NPWP Name', htmlspecialchars($vendor_full['npwp_name'] ?? '-'), false)
        . $infoRow('Bank Account', htmlspecialchars($vendor_full['bank_account_number'] ?? '-'), true)
        . $infoRow('Director ID (KTP)', htmlspecialchars($vendor_full['director_id_number'] ?? '-'), false)
        . $infoRow('Deed of Establishment', htmlspecialchars($vendor_full['deed_of_establishment_number'] ?? '-'), true, true) . '
    </table>';

    $left_margin = $pdf->getMargins()['left'];
    $writeBlock = function (string $html) use ($pdf, $left_margin): void {
        $pdf->writeHTML($html, false, false, true, false, '');
        $pdf->SetX($left_margin);
    };

    $writeBlock($header_html);
    $writeBlock($company_html);
    $writeBlock($sectionHeading('A. DETAIL APPROVAL'));
    $writeBlock($detail_html);
    $writeBlock($sectionHeading('B. VENDOR PROFILE'));
    $writeBlock($profile_html);

    $temp_path = sys_get_temp_dir() . '/ER_tmp_' . uniqid('', true) . '.pdf';
    $pdf->Output($temp_path, 'F');

    return $temp_path;
}
