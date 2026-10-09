<?php
// includes/vendor_contacts_schema.php
//
// Multi-Contact-Person (multi-CP) data-model foundation for the Vendor portal.
//
// Historically a vendor had exactly ONE login: users.role='vendor' linked 1:1 to
// vendors.user_id. This migration lets a single vendor company have MANY contact
// persons (CPs), each with their own login, while keeping every existing vendor
// working unchanged.
//
// Design:
//   - users.vendor_id      -> the vendor company this CP belongs to (mirrors vendors.id)
//   - users.contact_role   -> 'primary' (the vendor admin/owner) | 'member'
//   - users.contact_status -> 'active' | 'invited' | 'revoked'
//   - vendors.user_id STILL points at the PRIMARY contact, so all legacy
//     "SELECT ... FROM vendors WHERE user_id = ?" queries keep resolving.
//
// The migration is idempotent and safe to run on every request (it matches the
// existing config.php ALTER-in-try/catch idiom). It is written to work on BOTH
// MySQL 8 (local Docker) and MariaDB (prod) — no "IF NOT EXISTS" on columns/indexes,
// duplicate errors are swallowed by code instead.

if (!function_exists('ensure_vendor_contacts_schema')) {

    /**
     * Add the multi-CP columns/tables and backfill existing vendors as 'primary'.
     * Errors are logged, never fatal — schema drift must not take the app down.
     */
    function ensure_vendor_contacts_schema(mysqli $conn): void
    {
        // 1. New columns on `users`. Each added individually so one duplicate
        //    (error 1060) does not block the others.
        $userCols = [
            "vendor_id INT NULL DEFAULT NULL",
            "contact_role ENUM('primary','member') NULL DEFAULT NULL",
            "contact_status ENUM('active','invited','revoked') NOT NULL DEFAULT 'active'",
            "phone VARCHAR(50) NULL DEFAULT NULL",
            "invited_by_user_id INT NULL DEFAULT NULL",
            "verified_at DATETIME NULL DEFAULT NULL",
            "last_login_at DATETIME NULL DEFAULT NULL",
            // Phase 3: self-service email change held pending until the NEW address
            // is verified (the link is sent only to the new inbox).
            "pending_email VARCHAR(150) NULL DEFAULT NULL",
            "email_change_token VARCHAR(64) NULL DEFAULT NULL",
            "email_change_expires DATETIME NULL DEFAULT NULL",
        ];
        foreach ($userCols as $ddl) {
            try {
                $conn->query("ALTER TABLE users ADD COLUMN $ddl");
            } catch (mysqli_sql_exception $e) {
                if ((int)$e->getCode() !== 1060) { // 1060 = Duplicate column name
                    error_log("[vendor_cp_schema] add users column failed: " . $e->getMessage());
                }
            }
        }

        // 1b. Phase 4: when the primary last confirmed the CP list is current.
        try {
            $conn->query("ALTER TABLE vendors ADD COLUMN cp_last_attested_at DATETIME NULL DEFAULT NULL");
        } catch (mysqli_sql_exception $e) {
            if ((int)$e->getCode() !== 1060) {
                error_log("[vendor_cp_schema] add vendors.cp_last_attested_at failed: " . $e->getMessage());
            }
        }

        // 2. Index on users.vendor_id (member lookups resolve by vendor).
        try {
            $conn->query("ALTER TABLE users ADD INDEX idx_users_vendor_id (vendor_id)");
        } catch (mysqli_sql_exception $e) {
            if ((int)$e->getCode() !== 1061) { // 1061 = Duplicate key name
                error_log("[vendor_cp_schema] add users index failed: " . $e->getMessage());
            }
        }

        // 3. Audit trail for every CP lifecycle action (invite / revoke / transfer /
        //    email change / re-attestation). We never hard-delete CPs, so this is the
        //    record of who did what.
        $conn->query("CREATE TABLE IF NOT EXISTS vendor_contact_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT NOT NULL,
            actor_user_id INT DEFAULT NULL,
            actor_name VARCHAR(255) DEFAULT NULL,
            actor_role VARCHAR(50) DEFAULT NULL,
            target_user_id INT DEFAULT NULL,
            target_email VARCHAR(255) DEFAULT NULL,
            action VARCHAR(50) NOT NULL,
            details TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_vca_vendor (vendor_id),
            KEY idx_vca_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 4. Backfill: every existing vendor login becomes its company's PRIMARY CP.
        //    Guarded by WHERE, so after the first run it matches zero rows and is a
        //    no-op on subsequent requests. Multi-table UPDATE works on MySQL + MariaDB.
        try {
            $conn->query("UPDATE users u
                             JOIN vendors v ON v.user_id = u.id
                              SET u.vendor_id    = v.id,
                                  u.contact_role = 'primary'
                            WHERE u.role = 'vendor'
                              AND (u.vendor_id IS NULL OR u.contact_role IS NULL)");
        } catch (mysqli_sql_exception $e) {
            error_log("[vendor_cp_schema] primary backfill failed: " . $e->getMessage());
        }
    }
}
