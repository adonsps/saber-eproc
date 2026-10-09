-- Dump: Exception Requests table (CREATE)
-- Standalone CREATE for the `exception_requests` table — the source of truth for
-- the Exception Request feature (formerly "Fast Track"). One row per submit/review
-- cycle, mirroring the pq_submissions pattern: every re-submission creates a new
-- row, so the VM Head's rejection notes from previous cycles are preserved.
--
-- Use this on a fresh database instead of running
-- fast_track_requests_table.sql + exception_request_rename.sql in sequence; it
-- produces the same final schema (correct table name and FK constraint name)
-- directly.
--
-- Run once against DB u894129685_vmsportal.

CREATE TABLE `exception_requests` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `vendor_id` INT NOT NULL,
  `status` ENUM('under_review_vm_head','approved','rejected')
      COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'under_review_vm_head',
  `submitted_by` INT DEFAULT NULL,
  `submitted_at` DATETIME DEFAULT NULL,
  `reason` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submitted_notes` TEXT COLLATE utf8mb4_unicode_ci,
  `reviewed_by` INT DEFAULT NULL COMMENT 'VM Head user who approved/rejected',
  `reviewed_at` DATETIME DEFAULT NULL,
  `review_notes` TEXT COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `vendor_id` (`vendor_id`),
  CONSTRAINT `fk_exception_request_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
