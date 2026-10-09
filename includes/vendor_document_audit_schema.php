<?php
// includes/vendor_document_audit_schema.php
//
// Per-document "last updated by / at" trail for the vendor Dokumen Legal tab.
// vendors.last_updated_by_* (see vendor_account_schema) only covers the whole
// profile; this table lets each document row (NPWP, NIB, SBUJK, ...) show its
// own last change, since a single save might touch only documents (VM's
// override) or only profile fields (Sourcing's, or the vendor's own).
//
// Idempotent, MySQL 8 + MariaDB safe, following the same pattern as
// ensure_vendor_contacts_schema().

if (!function_exists('ensure_vendor_document_audit_schema')) {

    function ensure_vendor_document_audit_schema(mysqli $conn): void
    {
        $conn->query("CREATE TABLE IF NOT EXISTS vendor_document_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT NOT NULL,
            doc_key VARCHAR(60) NOT NULL,
            action ENUM('uploaded','deleted') NOT NULL,
            actor_user_id INT DEFAULT NULL,
            actor_name VARCHAR(255) DEFAULT NULL,
            actor_role VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_vda_vendor_doc (vendor_id, doc_key),
            KEY idx_vda_vendor (vendor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
