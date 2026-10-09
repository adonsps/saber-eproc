-- ============================================================================
-- K2 Drive Automation - Phase 4 API Nonce Schema
-- ----------------------------------------------------------------------------
-- Scope:
--   Replay-protection nonce storage for machine-authenticated K2 automation API.
--
-- Safety:
--   * Additive / re-runnable only: CREATE TABLE IF NOT EXISTS.
--   * No DROP, TRUNCATE, DELETE, or data loads.
--   * No credentials, URLs, Drive folder IDs, or production configuration.
--   * Manual review required before applying to any database.
-- ============================================================================

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
