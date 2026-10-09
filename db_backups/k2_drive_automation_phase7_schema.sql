-- ============================================================================
-- K2 Drive Automation - Phase 7 Additive Schema
-- ----------------------------------------------------------------------------
-- Scope:
--   Durable logical request identity and attempt-number validation for machine
--   operation retries.
--
-- Safety:
--   * Additive only. Review before applying to any non-disposable database.
--   * No credentials, URLs, Drive folder IDs, or production configuration.
-- ============================================================================

ALTER TABLE `k2_automation_operation_requests`
  ADD COLUMN `logical_request_id` VARCHAR(128) NOT NULL AFTER `idempotency_key`,
  ADD COLUMN `attempt_number` SMALLINT UNSIGNED NOT NULL AFTER `logical_request_id`,
  ADD UNIQUE KEY `uq_k2_auto_operation_logical_request` (`batch_id`, `operation_type`, `logical_request_id`);
