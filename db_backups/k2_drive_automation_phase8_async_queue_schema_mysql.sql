-- ============================================================================
-- K2 Drive Automation - Phase 8 Additive Schema (MySQL 8.0+ variant)
-- ----------------------------------------------------------------------------
-- ENGINE SCOPE: this file targets MySQL 8.0+ specifically — it uses
-- `ALTER TABLE ... DROP CHECK constraint_name`, which is MySQL syntax and is
-- REJECTED by MariaDB (error 1064). Local Docker dev runs MySQL 8.0, so this
-- is the file the local test suite (test_migration_paths_db.php and friends)
-- applies. Production (Hostinger) runs MariaDB 11.8.8 and MUST use
-- `k2_drive_automation_phase8_async_queue_schema_mariadb.sql` instead — see
-- that file and db_backups/k2_drive_automation_migration_readme.md for the
-- MariaDB-specific application method (direct `mariadb` CLI file piping, not
-- a per-statement PHP exec loop). Discovered/fixed 2026-07-27 when a first
-- production apply attempt of this exact file failed on MariaDB.
--
-- Scope:
--   Async import queue support. XLSX normalization/preflight/business writes
--   for AON and PR operations now run out-of-band in a PHP CLI worker
--   (workspace/cron/process_k2_import_queue.php) instead of inline during the
--   HTTP request that receives the upload. These columns let the worker:
--     * find the private-storage location of the staged upload(s) for a
--       queued attempt (`staged_file_manifest`)
--     * atomically claim exactly one queued attempt per worker invocation
--       (`worker_claim_token`, `worker_claimed_at`, `worker_hostname`,
--       `worker_pid`)
--   This file also widens the batch `state` enum (and the mirrored
--   `k2_automation_operation_requests.response_batch_state` enum) with
--   AON_QUEUED and PR_QUEUED, so a batch can be observed as "queued, no
--   worker has claimed it yet" distinctly from "a worker is actively
--   processing it" (AON_PROCESSING/PR_PROCESSING). Lease expiry for a claimed
--   attempt is tracked via the existing `worker_claimed_at` column plus
--   K2_AUTOMATION_WORKER_LEASE_SECONDS (application-level, no schema needed).
--   Existing stale-operation recovery (PdoK2ImportRunRepository::
--   recoverStaleProcessingBatch) is unchanged and continues to reap attempts
--   stuck in STARTED past the configured timeout, whether or not a worker
--   ever claimed them.
--
-- Safety:
--   * Additive only. Review before applying to any non-disposable database.
--   * No credentials, URLs, Drive folder IDs, or production configuration.
--   * Apply only to a database that already has the Phase 7 columns
--     (`logical_request_id`, `attempt_number`) on
--     k2_automation_operation_requests. Do not apply to a database that
--     already has the fresh-install schema applied (it includes these
--     columns already and re-applying will fail loudly).
-- ============================================================================

ALTER TABLE `k2_automation_operation_attempts`
  ADD COLUMN `staged_file_manifest` JSON DEFAULT NULL AFTER `attempt_idempotency_key`,
  ADD COLUMN `worker_claim_token` VARCHAR(128) DEFAULT NULL AFTER `status`,
  ADD COLUMN `worker_claimed_at` DATETIME(6) DEFAULT NULL AFTER `worker_claim_token`,
  ADD COLUMN `worker_hostname` VARCHAR(255) DEFAULT NULL AFTER `worker_claimed_at`,
  ADD COLUMN `worker_pid` INT UNSIGNED DEFAULT NULL AFTER `worker_hostname`,
  ADD KEY `idx_k2_auto_attempt_queue_claim` (`status`, `worker_claim_token`, `started_at`);

-- ----------------------------------------------------------------------------
-- Batch-level QUEUED states.
-- ----------------------------------------------------------------------------
-- The attempt-level columns above are not enough on their own: the batch
-- `state` enum still jumps straight from DETECTED/AON_SUCCESS to
-- AON_PROCESSING/PR_PROCESSING, which is what a synchronous in-request
-- importer would do. The async worker needs a batch-visible distinction
-- between "queued, no worker has claimed it yet" (AON_QUEUED/PR_QUEUED) and
-- "a worker has claimed the attempt and is actively normalizing/writing"
-- (AON_PROCESSING/PR_PROCESSING), because batch_status responses report
-- this to Apps Script and next_action must stay NONE for both sub-phases.
ALTER TABLE `k2_automation_import_batches`
  MODIFY COLUMN `state` ENUM(
    'DETECTED',
    'AON_QUEUED',
    'AON_PROCESSING',
    'AON_SUCCESS',
    'AON_FAILED_RETRYABLE',
    'AON_FAILED_FINAL',
    'PR_QUEUED',
    'PR_PROCESSING',
    'PR_FAILED_RETRYABLE',
    'PR_FAILED_FINAL',
    'IMPORT_SUCCESS',
    'ARCHIVE_PENDING',
    'COMPLETED'
  ) NOT NULL DEFAULT 'DETECTED';

ALTER TABLE `k2_automation_import_batches`
  DROP CHECK `chk_k2_auto_batch_archive_pair`;

ALTER TABLE `k2_automation_import_batches`
  ADD CONSTRAINT `chk_k2_auto_batch_archive_pair`
    CHECK (
      (
        `state` IN (
          'DETECTED',
          'AON_QUEUED',
          'AON_PROCESSING',
          'AON_SUCCESS',
          'AON_FAILED_RETRYABLE',
          'AON_FAILED_FINAL',
          'PR_QUEUED',
          'PR_PROCESSING',
          'PR_FAILED_RETRYABLE',
          'PR_FAILED_FINAL',
          'IMPORT_SUCCESS'
        )
        AND `archive_state` = 'NOT_READY'
      )
      OR (
        `state` = 'ARCHIVE_PENDING'
        AND `archive_state` IN ('PENDING', 'PROCESSING', 'FAILED_RETRYABLE', 'FAILED_FINAL')
      )
      OR (
        `state` = 'COMPLETED'
        AND `archive_state` = 'COMPLETED'
      )
    );

ALTER TABLE `k2_automation_operation_requests`
  MODIFY COLUMN `response_batch_state` ENUM(
    'DETECTED',
    'AON_QUEUED',
    'AON_PROCESSING',
    'AON_SUCCESS',
    'AON_FAILED_RETRYABLE',
    'AON_FAILED_FINAL',
    'PR_QUEUED',
    'PR_PROCESSING',
    'PR_FAILED_RETRYABLE',
    'PR_FAILED_FINAL',
    'IMPORT_SUCCESS',
    'ARCHIVE_PENDING',
    'COMPLETED'
  ) DEFAULT NULL;
