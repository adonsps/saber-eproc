<?php
// config.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Return-to-intended-page. Dipanggil di sini karena setiap halaman modules/**
// sudah me-require config.php, sedangkan guard-nya ditulis inline per file dan
// mencampur cek "belum login" dengan cek role dalam satu if. Inert untuk request
// non-GET, pengguna yang sudah login, dan CLI/cron.
require_once __DIR__ . '/includes/auth_intended.php';
auth_remember_intended();

$host = getenv('DB_HOST') ?: "localhost";

// Detect environment based on server path or request URI
$is_qa = (strpos(__DIR__, '/qa/') !== false || strpos($_SERVER['REQUEST_URI'] ?? '', '/qa/') !== false);

if ($host === 'db') {
    // Local Docker Env (VSS Portal)
    $user = "u894129685_adonisvm";
    $pass = "Xcel007!";
    $db   = "u894129685_vmsportal";
} elseif ($is_qa) {
    // Hostinger QA Env
    $user = "u894129685_sigitqa";
    $pass = "Xcel007!";
    $db   = "u894129685_qa_vmsportal";
} else {
    // Hostinger Production Env
    $user = "u894129685_adonisvm";
    $pass = "Xcel007!";
    $db   = "u894129685_vmsportal";
}

// try/catch, not just a connect_error check: since PHP 8.1 mysqli reports errors
// by throwing, so the constructor blows up before connect_error can be read. That
// is how a stopped database used to reach the browser as an uncaught
// mysqli_sql_exception with the DB host and username in the stack trace.
$conn_error = null;
try {
    $conn = @new mysqli($host, $user, $pass, $db);
    $conn_error = $conn->connect_error;
} catch (\Throwable $e) {
    $conn = null;
    $conn_error = $e->getMessage();
}

if ($conn_error !== null && $conn_error !== '') {
    // The database being unreachable is the normal state while it is being
    // restored or repaired, which is precisely when maintenance mode matters.
    // Hand vendor traffic the maintenance page rather than a stack trace.
    error_log("[config] VSS DB connection failed: " . $conn_error);
    require_once __DIR__ . '/includes/maintenance_gate.php';
    vss_maintenance_guard(null);

    // Internal roles and the auth endpoints were let past the gate but still
    // have no usable connection, and maintenance.php itself cannot render its
    // normal chrome (header.php reads notifications from $conn). Everyone left
    // gets the same self-contained 503 page.
    vss_maintenance_render_offline();
}

// 1. Set timezone for consistency
date_default_timezone_set('Asia/Jakarta');

// 2. Set MySQL Database Timezone to Jakarta (+07:00)
$conn->query("SET time_zone = '+07:00'");

// 3. Maintenance gate — deliberately the first thing after the connection, and
// ahead of every schema migration below. A request that is going to be turned
// away has no business firing ALTER TABLEs at a database somebody is restoring.
//
// ensure_tables() replaces what used to be a bare CREATE TABLE IF NOT EXISTS plus
// an INSERT IGNORE of is_active = 0: a restore that dropped the table had it
// rebuilt as "maintenance off" by the very next page hit, so the switch turned
// itself off in the middle of the DB work it was protecting.
//
// guard() then holds off every vendor-facing request — logged in OR anonymous.
// Internal roles and the auth endpoints pass through so nobody gets locked out
// of turning maintenance back off. See includes/maintenance_gate.php.
require_once __DIR__ . '/includes/maintenance_gate.php';
vss_maintenance_ensure_tables($conn);
vss_maintenance_guard($conn);

/**
 * Workspace (VSS) database connection.
 */
function getWorkspaceConnection(): ?mysqli {
    $ws_host = getenv('DB_HOST') ?: 'localhost';
    $is_qa   = (strpos(__DIR__, '/qa/') !== false || strpos($_SERVER['REQUEST_URI'] ?? '', '/qa/') !== false);
    $ws_db   = $is_qa ? 'u894129685_workspace_qa' : 'u894129685_workspace';

    $ws = @new mysqli($ws_host, 'u894129685_workspace007', 'Xcel007!', $ws_db);
    if ($ws->connect_error) {
        error_log("[config] Workspace DB connection failed: " . $ws->connect_error);
        return null;
    }
    $ws->query("SET time_zone = '+07:00'");
    return $ws;
}

const REGISTRATION_BCC_RECIPIENTS = [
    'klgprocurement@gmail.com',
];

// Jeda minimum antar pengiriman email verifikasi registrasi (detik).
const VERIFICATION_RESEND_COOLDOWN_SEC = 60;

// Tujuan pemberitahuan saat email verifikasi GAGAL terkirim.
const MAIL_FAILURE_ALERT_RECIPIENTS = [
    'sigit.ramadan@kawanlamacorp.com',
];


// Schema migration: VM "Return to Vendor" flow.
try {
    $conn->query("ALTER TABLE pq_submissions ADD COLUMN notes_to_vendor TEXT NULL AFTER vm_notes");
} catch (mysqli_sql_exception $e) {
    // Error 1060 is Duplicate column name. Ignore it.
    if ($e->getCode() != 1060) {
        throw $e;
    }
}

try {
    $conn->query("ALTER TABLE pq_sla_logs MODIFY vm_action_type ENUM('forward_head','returned_to_sourcing','returned_to_vendor') COLLATE utf8mb4_unicode_ci DEFAULT NULL");
} catch (mysqli_sql_exception $e) {
    // Ignore if enum already exists or modified
}

// Schema migration: Exception Request feature (table + OTP flow). Mirrors
// db_backups/exception_requests_table.sql + exception_requests_otp.sql so a fresh
// database is provisioned automatically. Every statement below is idempotent.
try {
    $conn->query("CREATE TABLE IF NOT EXISTS exception_requests (
        id INT NOT NULL AUTO_INCREMENT,
        vendor_id INT NOT NULL,
        status ENUM('requested','under_review_vm_head','approved','rejected')
            COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'under_review_vm_head',
        submitted_by INT DEFAULT NULL,
        submitted_at DATETIME DEFAULT NULL,
        reason VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        submitted_notes TEXT COLLATE utf8mb4_unicode_ci,
        otp_hash VARCHAR(255) NULL,
        otp_requested_at DATETIME NULL,
        otp_sent_to VARCHAR(30) NULL,
        otp_attempts INT NOT NULL DEFAULT 0,
        reviewed_by INT DEFAULT NULL COMMENT 'VM Head user who approved/rejected',
        reviewed_at DATETIME DEFAULT NULL,
        review_notes TEXT COLLATE utf8mb4_unicode_ci,
        PRIMARY KEY (id),
        KEY vendor_id (vendor_id),
        CONSTRAINT fk_exception_request_vendor FOREIGN KEY (vendor_id) REFERENCES vendors (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (mysqli_sql_exception $e) {
    // 1050 = table already exists. Anything else (e.g. FK when `vendors` is absent) is logged, not fatal.
    if ($e->getCode() != 1050) {
        error_log("[config] exception_requests create skipped: " . $e->getMessage());
    }
}

// Bring older exception_requests tables up to the current schema (idempotent).
try {
    $conn->query("ALTER TABLE exception_requests
        MODIFY status ENUM('requested','under_review_vm_head','approved','rejected')
            COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'under_review_vm_head'");
} catch (mysqli_sql_exception $e) {
    // No-op if the enum already includes 'requested'.
}

foreach ([
    "ADD COLUMN otp_hash VARCHAR(255) NULL AFTER submitted_notes",
    "ADD COLUMN otp_requested_at DATETIME NULL AFTER otp_hash",
    "ADD COLUMN otp_sent_to VARCHAR(30) NULL AFTER otp_requested_at",
    "ADD COLUMN otp_attempts INT NOT NULL DEFAULT 0 AFTER otp_sent_to",
    "ADD COLUMN reviewed_by INT DEFAULT NULL AFTER otp_attempts",
    "ADD COLUMN reviewed_at DATETIME DEFAULT NULL AFTER reviewed_by",
    "ADD COLUMN review_notes TEXT COLLATE utf8mb4_unicode_ci AFTER reviewed_at",
] as $er_ddl) {
    try {
        $conn->query("ALTER TABLE exception_requests $er_ddl");
    } catch (mysqli_sql_exception $e) {
        // 1060 = duplicate column (already applied); 1146 = table missing (fresh DB w/o `vendors`).
        if (!in_array($e->getCode(), [1060, 1146], true)) {
            throw $e;
        }
    }
}

// Schema migration: 5-tier risk_category (mirrors db_backups/risk_category_5tier.sql).
// Guarded by an information_schema check so the full-table UPDATE only ever
// runs once (the first request after this column is out of date), not on
// every single page load like a plain try/catch ALTER would.
$risk_category_col = $conn->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pq_submissions' AND COLUMN_NAME = 'risk_category'")->fetch_assoc();
if (($risk_category_col['COLUMN_TYPE'] ?? '') !== "enum('Very Low','Low','Moderate','High','Critical')") {
    try {
        // Step 1: widen so old & new values are both valid at once.
        $conn->query("ALTER TABLE pq_submissions MODIFY risk_category VARCHAR(20) NULL DEFAULT NULL");
        // Step 2: recompute every row from risk_percentage (same thresholds as pqRiskCategory()).
        $conn->query("UPDATE pq_submissions SET risk_category = CASE
            WHEN max_possible_weighted_score <= 0 THEN NULL
            WHEN risk_percentage <= 15 THEN 'Very Low'
            WHEN risk_percentage <= 29 THEN 'Low'
            WHEN risk_percentage <= 50 THEN 'Moderate'
            WHEN risk_percentage <= 61 THEN 'High'
            ELSE 'Critical' END");
        // Step 3: lock down to the final 5-tier enum.
        $conn->query("ALTER TABLE pq_submissions MODIFY risk_category ENUM('Very Low','Low','Moderate','High','Critical') NULL DEFAULT NULL");
    } catch (mysqli_sql_exception $e) {
        error_log("[config] risk_category 5-tier migration failed: " . $e->getMessage());
    }
}

// Schema migration: add 'Perseorangan' as its own company_type (individual/
// sole-proprietor vendors invited via invite_vendor sub_category). Guarded
// the same way as risk_category above so the ALTER only runs once.
$company_type_col = $conn->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'company_type'")->fetch_assoc();
if (($company_type_col['COLUMN_TYPE'] ?? '') !== "enum('Supplier','Contractor','Perseorangan')") {
    try {
        $conn->query("ALTER TABLE vendors MODIFY company_type ENUM('Supplier','Contractor','Perseorangan') DEFAULT NULL");
    } catch (mysqli_sql_exception $e) {
        error_log("[config] vendors.company_type Perseorangan migration failed: " . $e->getMessage());
    }
}

// Schema migration: add 'registration_submitted' to vendors.registration_status.
//
// register_action.php has inserted this value since commit b6b9fdc (2026-07-10)
// and the whole app reads it (sourcing/dashboard.php filters and counts on it,
// admin/dashboard.php renders a badge, vendor_context.php treats it as an
// unlocked state) — but the enum never gained it. Under MySQL 8.0's strict mode
// that is a hard "Data truncated for column 'registration_status'" error, so
// vendor self-registration fatals outright instead of silently storing ''.
//
// Unlike the guards above, the new type is DERIVED from the column's current
// COLUMN_TYPE rather than hardcoded: appending to whatever this environment
// already has means an environment carrying values not listed here cannot have
// them dropped by the MODIFY. The value is appended LAST on purpose — existing
// rows keep their enum index, so no stored value is remapped.
$reg_status_col = $conn->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'registration_status'")->fetch_assoc();
$reg_status_type = $reg_status_col['COLUMN_TYPE'] ?? '';
if ($reg_status_type !== '' && strpos($reg_status_type, "'registration_submitted'") === false) {
    try {
        // "enum('a','b')" -> "enum('a','b','registration_submitted')"
        $widened = preg_replace('/\)$/', ",'registration_submitted')", $reg_status_type);
        $conn->query("ALTER TABLE vendors MODIFY registration_status $widened
                      COLLATE utf8mb4_unicode_ci NULL DEFAULT 'registered'");
    } catch (mysqli_sql_exception $e) {
        error_log("[config] vendors.registration_status registration_submitted migration failed: " . $e->getMessage());
    }
}

// Schema migration: add 'Perseorangan' as its own sub_company_type value
// (additive — the old 'Individual / Foreman' value stays valid and is still
// what Contractor + Perseorangan-legal-entity vendors use). Guarded the same
// way as company_type above so the ALTER + backfill only run once.
$sub_company_type_col = $conn->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'sub_company_type'")->fetch_assoc();
if (($sub_company_type_col['COLUMN_TYPE'] ?? '') !== "enum('Project','Retail & Maintenance','Individual / Foreman','Distributor','Principal','Retail','Service','Perseorangan')") {
    try {
        $conn->query("ALTER TABLE vendors MODIFY sub_company_type ENUM('Project','Retail & Maintenance','Individual / Foreman','Distributor','Principal','Retail','Service','Perseorangan') DEFAULT NULL");
        // Backfill existing Perseorangan-company_type vendors only — Contractor
        // vendors with a Perseorangan legal entity keep 'Individual / Foreman'.
        $conn->query("UPDATE vendors SET sub_company_type = 'Perseorangan'
            WHERE company_type = 'Perseorangan' AND sub_company_type = 'Individual / Foreman'");
    } catch (mysqli_sql_exception $e) {
        error_log("[config] vendors.sub_company_type Perseorangan migration failed: " . $e->getMessage());
    }
}

// Schema migration: allow 'Perseorangan' as a pq_questions.variant_target so PQ
// questions can be targeted at that business model (mirrors vendors.company_type).
// Guarded via information_schema so the ALTER only runs once, like the blocks above.
$variant_target_col = $conn->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pq_questions' AND COLUMN_NAME = 'variant_target'")->fetch_assoc();
if (($variant_target_col['COLUMN_TYPE'] ?? '') !== "enum('All','Principal','Distributor','Retail','Contractor','Goods','Supplier','Perseorangan')") {
    try {
        $conn->query("ALTER TABLE pq_questions MODIFY variant_target ENUM('All','Principal','Distributor','Retail','Contractor','Goods','Supplier','Perseorangan') COLLATE utf8mb4_unicode_ci DEFAULT 'All'");
    } catch (mysqli_sql_exception $e) {
        error_log("[config] pq_questions.variant_target Perseorangan migration failed: " . $e->getMessage());
    }
}

// Schema migration: Direct Registration feature — audit trail of which
// Sourcing user created a vendor via the impersonation-based direct flow.
// Wrapped in its own transaction so a partial failure here can never leave
// the connection in a half-migrated state for the request that follows.
// Error 1146 (table doesn't exist) is tolerated too, not just 1060
// (duplicate column) — this block is a no-op on any environment where the
// `vendors` table hasn't been provisioned yet.
$conn->begin_transaction();
try {
    $conn->query("ALTER TABLE vendors ADD COLUMN registered_by_sourcing_id INT NULL AFTER sub_company_type");
    $conn->commit();
} catch (mysqli_sql_exception $e) {
    $conn->rollback();
    if (!in_array($e->getCode(), [1060, 1146], true)) {
        throw $e;
    }
}

// Schema migration: vendors.number_of_employees — headcount shown on the
// "Detail Perusahaan" tab, right below Bentuk Badan Hukum. Plain integer, stored
// without any separator; the form adds the thousand separators for display only.
// Nullable because every existing row predates it and the field is optional.
try {
    $conn->query("ALTER TABLE vendors ADD COLUMN number_of_employees INT UNSIGNED NULL DEFAULT NULL AFTER legal_entity_type");
} catch (mysqli_sql_exception $e) {
    // 1060 = duplicate column (already applied); 1146 = table missing (fresh DB).
    if (!in_array($e->getCode(), [1060, 1146], true)) {
        error_log("[config] vendors.number_of_employees migration failed: " . $e->getMessage());
    }
}

// Schema migration: vendors.vendor_eval_gform_submitted_at — timestamp of when
// the vendor confirmed, on the Dokumen Legal tab, that they submitted the
// per-invitation Vendor Evaluation Google Form (prefilled with their Invitation
// ID). NULL until that one-time confirmation; once set, profile.php stops gating
// "Simpan Perubahan" on it. Nullable because every existing row predates it and
// self-registered vendors (no invitation code) never set it.
try {
    $conn->query("ALTER TABLE vendors ADD COLUMN vendor_eval_gform_submitted_at DATETIME NULL DEFAULT NULL AFTER number_of_employees");
} catch (mysqli_sql_exception $e) {
    // 1060 = duplicate column (already applied); 1146 = table missing (fresh DB).
    if (!in_array($e->getCode(), [1060, 1146], true)) {
        error_log("[config] vendors.vendor_eval_gform_submitted_at migration failed: " . $e->getMessage());
    }
}

// Schema migration: vendors.is_active + its deactivation audit trail.
//
// Duplicate registrations are retired by DEACTIVATION, never by DELETE. A
// duplicate row still carries history — PQ submissions, notifications, document
// audits — and none of the child tables have a foreign key back to `vendors`, so
// a DELETE would silently orphan all of it with no way back. Flipping a flag
// keeps the evidence and stays reversible.
//
// is_active defaults to 1 so every existing row stays active; the three audit
// columns are nullable because a row that was never deactivated has nothing to
// record. Vendor Management owns this flag (modules/vendormgt/duplicate_vendors.php).
try {
    $conn->query("ALTER TABLE vendors ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER registration_status");
} catch (mysqli_sql_exception $e) {
    // 1060 = duplicate column (already applied); 1146 = table missing (fresh DB).
    if (!in_array($e->getCode(), [1060, 1146], true)) {
        error_log("[config] vendors.is_active migration failed: " . $e->getMessage());
    }
}
try {
    $conn->query("ALTER TABLE vendors ADD COLUMN deactivated_at DATETIME NULL DEFAULT NULL AFTER is_active");
} catch (mysqli_sql_exception $e) {
    if (!in_array($e->getCode(), [1060, 1146], true)) {
        error_log("[config] vendors.deactivated_at migration failed: " . $e->getMessage());
    }
}
try {
    $conn->query("ALTER TABLE vendors ADD COLUMN deactivated_by INT NULL DEFAULT NULL AFTER deactivated_at");
} catch (mysqli_sql_exception $e) {
    if (!in_array($e->getCode(), [1060, 1146], true)) {
        error_log("[config] vendors.deactivated_by migration failed: " . $e->getMessage());
    }
}
try {
    $conn->query("ALTER TABLE vendors ADD COLUMN deactivation_reason VARCHAR(255) NULL DEFAULT NULL AFTER deactivated_by");
} catch (mysqli_sql_exception $e) {
    if (!in_array($e->getCode(), [1060, 1146], true)) {
        error_log("[config] vendors.deactivation_reason migration failed: " . $e->getMessage());
    }
}

// Schema migration: vendor_invitations.registered_at — records when an
// invite link was actually used to complete registration (set at the same
// time invite_token is cleared, one-time use). Table itself is created by
// modules/shared/invite_vendor_page.php; this only backfills older installs.
try {
    $conn->query("ALTER TABLE vendor_invitations ADD COLUMN registered_at DATETIME NULL DEFAULT NULL AFTER open_count");
} catch (mysqli_sql_exception $e) {
    // 1060 = duplicate column (already applied); 1146 = table missing (fresh DB, table not created yet).
    if (!in_array($e->getCode(), [1060, 1146], true)) {
        error_log("[config] vendor_invitations.registered_at migration failed: " . $e->getMessage());
    }
}
// Schema migration: vendor_invitations.invite_code — the human-readable Invitation
// ID (IVT-YYMMXXX) quoted in the invitation email's subject and body and shown in
// the invitation history. The monthly running number inside it is derived from the
// codes already stored in this column, so no counter table is involved. Used to be
// created only when someone opened the Invite Vendor page; running it here migrates
// an install on its first request instead. NULL on pre-existing rows is fine — a
// UNIQUE index does not collide on NULLs, so older invitations simply keep no ID.
try {
    $conn->query("ALTER TABLE vendor_invitations ADD COLUMN invite_code VARCHAR(32) DEFAULT NULL UNIQUE AFTER status");
} catch (mysqli_sql_exception $e) {
    // 1060 = duplicate column (already applied); 1146 = table missing (fresh DB —
    // invite_vendor_page.php creates it with invite_code already in the definition).
    if (!in_array($e->getCode(), [1060, 1146], true)) {
        error_log("[config] vendor_invitations.invite_code migration failed: " . $e->getMessage());
    }
}

// Schema migration: vendors.director_phone / business_description / is_sppkp —
// director's phone number (separate from director_name), the free-text pitch
// behind "Deteksi Kategori Otomatis" AI suggestion, and the PKP self-declaration
// flag that gates the SPPKP upload. Additive, same idempotent pattern as above.
foreach ([
    'director_phone'       => "VARCHAR(30) NULL DEFAULT NULL",
    'business_description' => "TEXT NULL DEFAULT NULL",
    'is_sppkp'              => "TINYINT(1) NOT NULL DEFAULT 1",
] as $vendor_col => $vendor_col_ddl) {
    try {
        $conn->query("ALTER TABLE vendors ADD COLUMN $vendor_col $vendor_col_ddl");
    } catch (mysqli_sql_exception $e) {
        if (!in_array($e->getCode(), [1060, 1146], true)) {
            error_log("[config] vendors.$vendor_col migration failed: " . $e->getMessage());
        }
    }
}

// Schema migration: vendors.wht_tax_type / wht_tax_code — With Holding Tax
// reference picked by Contractor vendors (values come from a fixed list in
// modules/vendor/profile.php, not a lookup table). Additive + nullable, same
// idempotent pattern as the other vendors ALTER TABLEs above.
foreach ([
    'wht_tax_type' => 'VARCHAR(80)',
    'wht_tax_code' => 'VARCHAR(150)',
] as $wht_col => $wht_col_type) {
    try {
        $conn->query("ALTER TABLE vendors ADD COLUMN $wht_col $wht_col_type NULL DEFAULT NULL");
    } catch (mysqli_sql_exception $e) {
        if (!in_array($e->getCode(), [1060, 1146], true)) {
            error_log("[config] vendors.$wht_col migration failed: " . $e->getMessage());
        }
    }
}

// Schema migration: vendors.bank_holder_statement_letter_file / tax_statement_letter_file
// — new Dokumen Legal uploads (bank account holder name mismatch declaration,
// and the Tax Statement Letter). Additive + nullable, same idempotent pattern
// as the other vendors ALTER TABLEs above.
foreach ([
    'bank_holder_statement_letter_file',
    'tax_statement_letter_file',
] as $vendor_doc_col) {
    try {
        $conn->query("ALTER TABLE vendors ADD COLUMN $vendor_doc_col VARCHAR(255) NULL DEFAULT NULL");
    } catch (mysqli_sql_exception $e) {
        // 1060 = duplicate column (already applied); 1146 = table missing (fresh DB).
        if (!in_array($e->getCode(), [1060, 1146], true)) {
            error_log("[config] vendors.$vendor_doc_col migration failed: " . $e->getMessage());
        }
    }
}

// Schema migration: vendors.data_accuracy_confirmed — the "Konfirmasi
// Kesesuaian Data" declaration checkbox on the Dokumen Legal tab
// (profile.php/profile_action.php). Required on every save, so 1 means this
// profile's current data was last saved with the vendor's declaration on
// record; who/when is already covered by last_updated_by_name/last_updated_at.
try {
    $conn->query("ALTER TABLE vendors ADD COLUMN data_accuracy_confirmed TINYINT(1) NOT NULL DEFAULT 0");
} catch (mysqli_sql_exception $e) {
    if (!in_array($e->getCode(), [1060, 1146], true)) {
        error_log("[config] vendors.data_accuracy_confirmed migration failed: " . $e->getMessage());
    }
}

// Schema migration: pq_answers.calc_details — the working figures behind a
// calculated answer (Aset Lancar / Persediaan / Kewajiban Lancar for the Quick
// Ratio question), stored as JSON so the review screens can show Sourcing/VM
// what the vendor's percentage was actually derived from. Nullable: every
// existing answer, and every question without a calculator, simply has none.
try {
    $conn->query("ALTER TABLE pq_answers ADD COLUMN calc_details TEXT NULL DEFAULT NULL AFTER remarks");
} catch (mysqli_sql_exception $e) {
    // 1060 = duplicate column (already applied); 1146 = table missing (fresh DB).
    if (!in_array($e->getCode(), [1060, 1146], true)) {
        error_log("[config] pq_answers.calc_details migration failed: " . $e->getMessage());
    }
}

// Schema migration: users.verification_sent_at — kapan email verifikasi
// registrasi terakhir dikirim ke akun ini. Menjadi dasar cooldown "kirim
// ulang" di resend_verification.php; disimpan di DB, bukan session, supaya
// menghapus cookie tidak membuka jalan membanjiri inbox seseorang.
try {
    $conn->query("ALTER TABLE users ADD COLUMN verification_sent_at DATETIME NULL DEFAULT NULL AFTER verification_token");
} catch (mysqli_sql_exception $e) {
    // 1060 = duplicate column (already applied); 1146 = table missing (fresh DB).
    if (!in_array($e->getCode(), [1060, 1146], true)) {
        error_log("[config] users.verification_sent_at migration failed: " . $e->getMessage());
    }
}

// Schema migration: multi-Contact-Person (multi-CP) vendor model.
// Idempotent + backward compatible; see includes/vendor_contacts_schema.php.
require_once __DIR__ . '/includes/vendor_contacts_schema.php';
ensure_vendor_contacts_schema($conn);

// Schema migration: vendor account enhancements (username login + profile
// last-updated tracking). Idempotent; see includes/vendor_account_schema.php.
require_once __DIR__ . '/includes/vendor_account_schema.php';
ensure_vendor_account_schema($conn);

// Schema migration: vendors.business_model (the multi-select "Model Bisnis"
// registration question that company_type is derived from) + vendors.is_umkm
// (which routes the vendor to the Perseorangan PQ question set).
// Idempotent; see includes/vendor_business_model.php.
require_once __DIR__ . '/includes/vendor_business_model.php';
ensure_vendor_business_model_schema($conn);

// Schema migration: per-document "last updated by / at" audit trail for the
// Dokumen Legal tab. Idempotent; see includes/vendor_document_audit_schema.php.
require_once __DIR__ . '/includes/vendor_document_audit_schema.php';
ensure_vendor_document_audit_schema($conn);

?>
