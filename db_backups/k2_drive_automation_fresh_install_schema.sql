-- ============================================================================
-- K2 Drive Automation - Fresh Install Schema
-- ----------------------------------------------------------------------------
-- Scope:
--   Complete final automation schema for a new installation. This file includes
--   durable batch/import state (including the Phase 8 AON_QUEUED/PR_QUEUED
--   states), source-file identity, operation attempts, Phase 7 logical
--   request identity columns/indexes, Phase 8 async worker queue columns,
--   and API nonce storage exactly once.
--
-- Safety:
--   * Fresh install only: apply to an empty target schema.
--   * Do not apply k2_drive_automation_phase7_schema.sql or either Phase 8
--     variant (k2_drive_automation_phase8_async_queue_schema_mysql.sql /
--     _mariadb.sql) after this file.
--   * No credentials, URLs, Drive folder IDs, or production configuration.
--   * Manual review required before applying to any non-disposable database.
--   * This file's CREATE TABLE statements (including the inline CHECK
--     constraint) are engine-portable as-is — MySQL/MariaDB only diverge on
--     the ALTER-time DROP CHECK/DROP CONSTRAINT syntax the Phase 8 upgrade
--     path uses, which does not apply here since these tables are created
--     fresh, never altered.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `k2_automation_import_batches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_uid` VARCHAR(80) NOT NULL,
  `source_manifest_hash` CHAR(64) NOT NULL,
  `scheduled_slot_at_utc` DATETIME(6) DEFAULT NULL,
  `schedule_timezone` VARCHAR(64) DEFAULT NULL,
  `state` ENUM(
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
  ) NOT NULL DEFAULT 'DETECTED',
  `archive_state` ENUM(
    'NOT_READY',
    'PENDING',
    'PROCESSING',
    'FAILED_RETRYABLE',
    'FAILED_FINAL',
    'COMPLETED'
  ) NOT NULL DEFAULT 'NOT_READY',
  `aon_attempt_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `pr_attempt_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `archive_attempt_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `aon_inserted_count` INT UNSIGNED DEFAULT NULL,
  `aon_updated_count` INT UNSIGNED DEFAULT NULL,
  `aon_skipped_count` INT UNSIGNED DEFAULT NULL,
  `aon_warning_count` INT UNSIGNED DEFAULT NULL,
  `pr_inserted_count` INT UNSIGNED DEFAULT NULL,
  `pr_updated_count` INT UNSIGNED DEFAULT NULL,
  `pr_skipped_count` INT UNSIGNED DEFAULT NULL,
  `pr_warning_count` INT UNSIGNED DEFAULT NULL,
  `last_error_phase` ENUM('detect', 'aon', 'pr', 'archive') DEFAULT NULL,
  `last_error_code` VARCHAR(100) DEFAULT NULL,
  `last_error_message` TEXT DEFAULT NULL,
  `last_error_retryable` TINYINT(1) DEFAULT NULL,
  `last_error_at` DATETIME(6) DEFAULT NULL,
  `run_lock_token` VARCHAR(128) DEFAULT NULL,
  `run_lock_acquired_at` DATETIME(6) DEFAULT NULL,
  `run_lock_expires_at` DATETIME(6) DEFAULT NULL,
  `detected_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `aon_started_at` DATETIME(6) DEFAULT NULL,
  `aon_completed_at` DATETIME(6) DEFAULT NULL,
  `pr_started_at` DATETIME(6) DEFAULT NULL,
  `pr_completed_at` DATETIME(6) DEFAULT NULL,
  `import_completed_at` DATETIME(6) DEFAULT NULL,
  `archive_pending_at` DATETIME(6) DEFAULT NULL,
  `archive_started_at` DATETIME(6) DEFAULT NULL,
  `archive_completed_at` DATETIME(6) DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_k2_auto_batch_uid` (`batch_uid`),
  UNIQUE KEY `uq_k2_auto_batch_manifest` (`source_manifest_hash`),
  KEY `idx_k2_auto_batch_state` (`state`, `archive_state`),
  KEY `idx_k2_auto_batch_scheduled_slot` (`scheduled_slot_at_utc`),
  KEY `idx_k2_auto_batch_lock` (`run_lock_expires_at`),
  KEY `idx_k2_auto_batch_detected` (`detected_at`),
  CONSTRAINT `chk_k2_auto_batch_archive_pair`
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
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `k2_automation_source_files` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dataset_type` ENUM('pr', 'catalog', 'price') NOT NULL,
  `drive_file_id` VARCHAR(255) NOT NULL,
  `original_filename` VARCHAR(512) NOT NULL,
  `drive_modified_at` DATETIME(6) NOT NULL,
  `file_size_bytes` BIGINT UNSIGNED NOT NULL,
  `sha256_content_hash` CHAR(64) NOT NULL,
  `file_identity_hash` CHAR(64) NOT NULL,
  `content_identity_hash` CHAR(64) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_k2_auto_source_id_dataset` (`id`, `dataset_type`),
  UNIQUE KEY `uq_k2_auto_drive_file_version` (`drive_file_id`, `drive_modified_at`),
  UNIQUE KEY `uq_k2_auto_file_identity` (`file_identity_hash`),
  KEY `idx_k2_auto_file_dataset_hash` (`dataset_type`, `sha256_content_hash`),
  KEY `idx_k2_auto_file_content_identity` (`content_identity_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `k2_automation_import_batch_files` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` BIGINT UNSIGNED NOT NULL,
  `dataset_type` ENUM('pr', 'catalog', 'price') NOT NULL,
  `source_file_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_k2_auto_batch_dataset` (`batch_id`, `dataset_type`),
  UNIQUE KEY `uq_k2_auto_batch_source_file` (`batch_id`, `source_file_id`),
  KEY `idx_k2_auto_batch_file_source_dataset` (`source_file_id`, `dataset_type`),
  CONSTRAINT `fk_k2_auto_file_batch`
    FOREIGN KEY (`batch_id`) REFERENCES `k2_automation_import_batches` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_k2_auto_file_source_dataset`
    FOREIGN KEY (`source_file_id`, `dataset_type`)
    REFERENCES `k2_automation_source_files` (`id`, `dataset_type`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `k2_automation_operation_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` BIGINT UNSIGNED NOT NULL,
  `operation_type` ENUM('detect', 'aon', 'pr', 'archive') NOT NULL,
  `idempotency_key` VARCHAR(96) NOT NULL,
  `logical_request_id` VARCHAR(128) NOT NULL,
  `attempt_number` SMALLINT UNSIGNED NOT NULL,
  `request_status` ENUM(
    'RECEIVED',
    'RUNNING',
    'SUCCEEDED',
    'FAILED_RETRYABLE',
    'FAILED_FINAL',
    'REPLAYED'
  ) NOT NULL DEFAULT 'RECEIVED',
  `response_batch_state` ENUM(
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
  ) DEFAULT NULL,
  `response_archive_state` ENUM(
    'NOT_READY',
    'PENDING',
    'PROCESSING',
    'FAILED_RETRYABLE',
    'FAILED_FINAL',
    'COMPLETED'
  ) DEFAULT NULL,
  `response_http_status` SMALLINT UNSIGNED DEFAULT NULL,
  `response_body_hash` CHAR(64) DEFAULT NULL,
  `duplicate_replay_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `first_seen_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `last_seen_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `completed_at` DATETIME(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_k2_auto_operation_request_key` (`idempotency_key`),
  UNIQUE KEY `uq_k2_auto_operation_logical_request` (`batch_id`, `operation_type`, `logical_request_id`),
  KEY `idx_k2_auto_operation_request_batch` (`batch_id`, `operation_type`, `request_status`),
  CONSTRAINT `fk_k2_auto_operation_request_batch`
    FOREIGN KEY (`batch_id`) REFERENCES `k2_automation_import_batches` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `k2_automation_operation_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` BIGINT UNSIGNED NOT NULL,
  `operation_request_id` BIGINT UNSIGNED DEFAULT NULL,
  `operation_type` ENUM('detect', 'aon', 'pr', 'archive') NOT NULL,
  `attempt_number` SMALLINT UNSIGNED NOT NULL,
  `attempt_idempotency_key` VARCHAR(96) NOT NULL,
  `staged_file_manifest` JSON DEFAULT NULL,
  `status` ENUM(
    'STARTED',
    'SUCCEEDED',
    'FAILED_RETRYABLE',
    'FAILED_FINAL'
  ) NOT NULL DEFAULT 'STARTED',
  `worker_claim_token` VARCHAR(128) DEFAULT NULL,
  `worker_claimed_at` DATETIME(6) DEFAULT NULL,
  `worker_hostname` VARCHAR(255) DEFAULT NULL,
  `worker_pid` INT UNSIGNED DEFAULT NULL,
  `inserted_count` INT UNSIGNED DEFAULT NULL,
  `updated_count` INT UNSIGNED DEFAULT NULL,
  `skipped_count` INT UNSIGNED DEFAULT NULL,
  `warning_count` INT UNSIGNED DEFAULT NULL,
  `error_code` VARCHAR(100) DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `error_retryable` TINYINT(1) DEFAULT NULL,
  `started_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `finished_at` DATETIME(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_k2_auto_attempt_key` (`attempt_idempotency_key`),
  UNIQUE KEY `uq_k2_auto_attempt_number` (`batch_id`, `operation_type`, `attempt_number`),
  KEY `idx_k2_auto_attempt_status` (`batch_id`, `operation_type`, `status`),
  KEY `idx_k2_auto_attempt_queue_claim` (`status`, `worker_claim_token`, `started_at`),
  CONSTRAINT `fk_k2_auto_attempt_batch`
    FOREIGN KEY (`batch_id`) REFERENCES `k2_automation_import_batches` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_k2_auto_attempt_request`
    FOREIGN KEY (`operation_request_id`) REFERENCES `k2_automation_operation_requests` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `k2_automation_api_nonces` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` VARCHAR(128) NOT NULL,
  `nonce` VARCHAR(128) NOT NULL,
  `request_id` VARCHAR(128) NOT NULL,
  `request_timestamp` DATETIME(6) NOT NULL,
  `expires_at` DATETIME(6) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_k2_auto_api_client_nonce` (`client_id`, `nonce`),
  KEY `idx_k2_auto_api_nonce_expires` (`expires_at`),
  KEY `idx_k2_auto_api_nonce_request` (`client_id`, `request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
