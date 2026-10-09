-- ====================================================================
-- Vendor Evaluation v2 — Consolidated Schema (01 of 3)
--
-- Combines every schema migration needed for Vendor Evaluation v2 into
-- one re-runnable file. Apply this FIRST, before
-- vendor_eval_v2_02_seed_business_data.sql and
-- vendor_eval_v2_03_seed_requestor_contacts.sql.
--
-- Sections (in dependency order):
--   0. requestors.public_token (prerequisite for the v2 public worklist)
--   1. item_taxonomy_db_view + v_item_taxonomy   (Item Category taxonomy)
--   2. vendor_eval_po_taxonomy                   (PO-level taxonomy bridge/cache)
--   3. requestors.manager_email                  (v2 email-CC support)
--   4. vendor_eval_question_sets / questions / taxonomy_map / submissions /
--      answers / import_batches                  (dynamic question-set core)
--   5. rubric + question-type columns             (real evaluation forms)
--   6. question builder image/help columns
--   7. identification fields (question_section, input_type)
--   8. vendor_eval_v2_invite_log                  (send idempotency log)
--   9. prs.master_data_id                         (taxonomy routing source)
--
-- SAFETY:
--   - Additive / re-runnable only: CREATE TABLE IF NOT EXISTS,
--     ADD COLUMN IF NOT EXISTS, CREATE INDEX IF NOT EXISTS, or
--     information_schema-guarded DDL. No DROP, DELETE, or TRUNCATE anywhere
--     in this file.
--   - No data is loaded here (see the 02 and 03 files for seed data).
--   - v1 `vendor_evaluations` and all v1 files are NEVER referenced.
--   - Not wired into workspace/config/database.php auto-migration —
--     this remains a deliberate, manually-applied migration.
--
-- Before applying: confirm DB identity and take a backup.
--   SELECT DATABASE(), USER(), @@hostname, VERSION();
-- ====================================================================


-- ====================================================================
-- 0. Requestor public token prerequisite
--
-- Required by the v2 no-login public worklist
-- (workspace/pages/public_eval_v2_worklist.php), which looks up a requestor
-- by requestors.public_token.
--
-- Ported here (DDL only, no data) from the old
-- feature/vendor-eval-reminder-foundation branch before that branch was
-- deleted as superseded. That branch's eval_reminder_log table was NOT
-- ported — reminder-send idempotency is now covered by
-- vendor_eval_v2_invite_log (see section 8).
--
-- MariaDB permits multiple NULLs under a UNIQUE index, so requestors that
-- have not yet been tokenized remain valid.
-- ====================================================================
ALTER TABLE `requestors`
    ADD COLUMN IF NOT EXISTS `public_token` VARCHAR(64) NULL DEFAULT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS `idx_requestors_public_token`
    ON `requestors` (`public_token`);


-- ====================================================================
-- 1. Item Taxonomy ("Item Category - DB View")
-- ====================================================================
CREATE TABLE IF NOT EXISTS `item_taxonomy_db_view` (
  `Tower`             VARCHAR(255) NULL,
  `Category`          VARCHAR(255) NULL,
  `Sub Category Code` VARCHAR(50)  NULL,
  `Sub Category`      VARCHAR(255) NULL,
  `Commodity`         VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `item_taxonomy_import_meta` (
  `id`           INT          NOT NULL AUTO_INCREMENT,
  `source_file`  VARCHAR(255) DEFAULT NULL,
  `source_sheet` VARCHAR(255) DEFAULT NULL,
  `row_count`    INT          DEFAULT NULL,
  `imported_by`  VARCHAR(255) DEFAULT NULL,
  `imported_at`  DATETIME     DEFAULT NULL,
  `notes`        TEXT         DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Maps raw sheet columns to stable level_* names:
--   Tower -> level_0, Category -> level_1, Sub Category Code -> sub_category_code,
--   Sub Category -> level_2 (question-set routing key), Commodity -> level_3.
CREATE OR REPLACE VIEW `v_item_taxonomy` AS
SELECT
  `Tower`             AS `level_0`,
  `Category`          AS `level_1`,
  `Sub Category Code` AS `sub_category_code`,
  `Sub Category`      AS `level_2`,
  `Commodity`         AS `level_3`
FROM `item_taxonomy_db_view`;


-- ====================================================================
-- 2. vendor_eval_po_taxonomy — PO-to-Taxonomy Mapping Bridge/Cache
--
-- Keyed at PO level (UNIQUE on po_number). Loaded by
-- tools/run_po_taxonomy_mapping.php (fuzzy text matcher, fallback) and/or
-- tools/refresh_vendor_eval_po_taxonomy_from_master_data.php (authoritative
-- master_data_id source, preferred once prs.master_data_id is populated).
-- taxonomy_level_2 (Sub Category) is the minimum key for question-set
-- selection; taxonomy_level_3 (Commodity) is preferred when available.
-- ====================================================================
CREATE TABLE IF NOT EXISTS `vendor_eval_po_taxonomy` (
  `id`                       INT          NOT NULL AUTO_INCREMENT,
  `po_number`                VARCHAR(255) NOT NULL,
  `vendor_name`              VARCHAR(255) DEFAULT NULL,
  `requestor_names_json`     TEXT         DEFAULT NULL,
  `item_descriptions_json`   TEXT         DEFAULT NULL,
  `line_item_count`          INT          DEFAULT NULL,
  `p_group`                  VARCHAR(50)  DEFAULT NULL,
  `ticket_type`              VARCHAR(100) DEFAULT NULL,
  `taxonomy_level_0`         VARCHAR(255) DEFAULT NULL,
  `taxonomy_level_1`         VARCHAR(255) DEFAULT NULL,
  `sub_category_code`        VARCHAR(50)  DEFAULT NULL,
  `taxonomy_level_2`         VARCHAR(255) DEFAULT NULL,
  `taxonomy_level_3`         VARCHAR(255) DEFAULT NULL,
  `mapping_method`           ENUM(
                               'exact_l3', 'exact_l2', 'fuzzy_l3', 'fuzzy_l2',
                               'ai', 'manual'
                             ) DEFAULT NULL,
  `mapping_confidence`       TINYINT UNSIGNED DEFAULT NULL,
  `mapping_reason`           TEXT         DEFAULT NULL,
  `candidate_taxonomy_json`  TEXT         DEFAULT NULL,
  `dominant_basis`           ENUM('single', 'majority', 'manual') DEFAULT NULL,
  `status`                   ENUM(
                               'pending', 'mapped', 'needs_review',
                               'resolved', 'unresolved'
                             ) NOT NULL DEFAULT 'pending',
  `reviewed_by`              VARCHAR(255) DEFAULT NULL,
  `reviewed_at`              DATETIME     DEFAULT NULL,
  `review_notes`             TEXT         DEFAULT NULL,
  `mapped_at`                DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_po`       (`po_number`),
  KEY        `idx_status`      (`status`),
  KEY        `idx_taxonomy_l2` (`taxonomy_level_2`),
  KEY        `idx_taxonomy_l3` (`taxonomy_level_3`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ====================================================================
-- 3. requestors.manager_email — v2 email-CC support
--
-- Requestors are public/no-login users. Requestor managers are not
-- Workspace/GP users and receive email CC only, so v2 uses a direct
-- manager_email column instead of a manager_username FK.
-- ====================================================================
ALTER TABLE `requestors`
    ADD COLUMN IF NOT EXISTS `manager_email` VARCHAR(255) NULL DEFAULT NULL
        AFTER `whatsapp_number`;

CREATE INDEX IF NOT EXISTS `idx_requestors_manager_email`
    ON `requestors` (`manager_email`);


-- ====================================================================
-- 4. Dynamic Question-Set core
--    (vendor_eval_question_sets, vendor_eval_questions,
--     vendor_eval_question_set_taxonomy_map, vendor_eval_submissions,
--     vendor_eval_answers, vendor_eval_question_import_batches)
--
-- Lives entirely parallel to v1 `vendor_evaluations`; v1 is never
-- referenced, altered, or migrated here.
-- ====================================================================
CREATE TABLE IF NOT EXISTS `vendor_eval_question_sets` (
  `id`          INT             NOT NULL AUTO_INCREMENT,
  `set_code`    VARCHAR(100)    NOT NULL,
  `set_name`    VARCHAR(255)    NOT NULL,
  `version`     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
  `description` TEXT            NULL,
  `created_at`  DATETIME        DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_set_code_version` (`set_code`, `version`),
  KEY        `idx_set_code_active` (`set_code`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vendor_eval_questions` (
  `id`               INT           NOT NULL AUTO_INCREMENT,
  `question_set_id`  INT           NOT NULL,
  `question_no`      TINYINT UNSIGNED NOT NULL,
  `criterion_name`   VARCHAR(255)  NOT NULL,
  `question_text`    TEXT          NOT NULL,
  `weight`           DECIMAL(5,4)  NOT NULL,
  `score_1_label`    VARCHAR(255)  NOT NULL,
  `score_2_label`    VARCHAR(255)  NOT NULL,
  `score_3_label`    VARCHAR(255)  NOT NULL,
  `score_4_label`    VARCHAR(255)  NOT NULL,
  `comment_required` TINYINT(1)    NOT NULL DEFAULT 0,
  `is_active`        TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`       DATETIME      DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_set_question_no` (`question_set_id`, `question_no`),
  CONSTRAINT `fk_question_set`
    FOREIGN KEY (`question_set_id`) REFERENCES `vendor_eval_question_sets` (`id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vendor_eval_question_set_taxonomy_map` (
  `id`               INT          NOT NULL AUTO_INCREMENT,
  `question_set_id`  INT          NOT NULL,
  `map_level`        ENUM('l3','l2','default') NOT NULL,
  `taxonomy_level_2` VARCHAR(255) NOT NULL DEFAULT '**ANY**',
  `taxonomy_level_3` VARCHAR(255) NOT NULL DEFAULT '**ANY**',
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`       DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_map_l2_l3`   (`map_level`, `taxonomy_level_2`, `taxonomy_level_3`),
  KEY        `idx_map_set`    (`question_set_id`),
  KEY        `idx_map_l2`     (`taxonomy_level_2`),
  KEY        `idx_map_l3`     (`taxonomy_level_3`),
  KEY        `idx_map_active` (`is_active`),
  CONSTRAINT `fk_map_question_set`
    FOREIGN KEY (`question_set_id`) REFERENCES `vendor_eval_question_sets` (`id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vendor_eval_submissions` (
  `id`                   INT          NOT NULL AUTO_INCREMENT,
  `po_number`            VARCHAR(255) NOT NULL,
  `vendor_name`          VARCHAR(255) NULL,
  `requestor_name`       VARCHAR(255) NOT NULL,
  `question_set_id`      INT          NOT NULL,
  `taxonomy_level_2`     VARCHAR(255) NULL,
  `taxonomy_level_3`     VARCHAR(255) NULL,
  `total_score`          DECIMAL(6,4) NULL,
  `score_band`           ENUM('A','B','C','D') NULL,
  `status`               ENUM('draft','submitted','invalidated') NOT NULL DEFAULT 'submitted',
  `submitted_at`         DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `invalidation_reason`  TEXT         NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_po_requestor` (`po_number`, `requestor_name`),
  KEY        `idx_sub_set`     (`question_set_id`),
  KEY        `idx_sub_at`      (`submitted_at`),
  CONSTRAINT `fk_sub_question_set`
    FOREIGN KEY (`question_set_id`) REFERENCES `vendor_eval_question_sets` (`id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vendor_eval_answers` (
  `id`                   INT          NOT NULL AUTO_INCREMENT,
  `submission_id`        INT          NOT NULL,
  `question_id`          INT          NOT NULL,
  `question_no`          TINYINT UNSIGNED NOT NULL,
  `criterion_name`       VARCHAR(255) NOT NULL,
  `question_text`        TEXT         NOT NULL,
  `score`                TINYINT UNSIGNED NULL COMMENT 'NULL for question_type=text rows; 1-4 for rating rows',
  `selected_score_label` VARCHAR(255) NOT NULL,
  `weight`               DECIMAL(5,4) NOT NULL,
  `weighted_score`       DECIMAL(6,4) NOT NULL,
  `comment`              TEXT         NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_submission_question` (`submission_id`, `question_id`),
  KEY        `idx_ans_question`       (`question_id`),
  CONSTRAINT `fk_ans_submission`
    FOREIGN KEY (`submission_id`) REFERENCES `vendor_eval_submissions` (`id`)
    ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_ans_question`
    FOREIGN KEY (`question_id`) REFERENCES `vendor_eval_questions` (`id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vendor_eval_question_import_batches` (
  `id`             INT          NOT NULL AUTO_INCREMENT,
  `source_file`    VARCHAR(255) NULL,
  `source_hash`    VARCHAR(64)  NULL,
  `set_codes_json` TEXT         NULL,
  `question_count` INT          NULL,
  `imported_by`    VARCHAR(255) NULL,
  `imported_at`    DATETIME     NULL,
  `notes`          TEXT         NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_source_hash` (`source_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ====================================================================
-- 5. Rubric + question-type columns (real evaluation forms)
-- ====================================================================
ALTER TABLE vendor_eval_questions
    ADD COLUMN IF NOT EXISTS question_type ENUM('rating','text') NOT NULL DEFAULT 'rating'
        COMMENT 'rating = numeric 1-4 scored; text = free-text conclusion (not scored)',
    ADD COLUMN IF NOT EXISTS score_1_description TEXT NULL
        COMMENT 'Rubric description for score 1 (Tidak Bagus)',
    ADD COLUMN IF NOT EXISTS score_2_description TEXT NULL
        COMMENT 'Rubric description for score 2 (Kurang Bagus)',
    ADD COLUMN IF NOT EXISTS score_3_description TEXT NULL
        COMMENT 'Rubric description for score 3 (Bagus)',
    ADD COLUMN IF NOT EXISTS score_4_description TEXT NULL
        COMMENT 'Rubric description for score 4 (Sangat Bagus)',
    ADD COLUMN IF NOT EXISTS comment_prompt TEXT NULL
        COMMENT 'Prompt text shown above comment textarea; NULL uses default wording';


-- ====================================================================
-- 6. Question builder image/help columns
-- ====================================================================
ALTER TABLE `vendor_eval_questions`
    ADD COLUMN IF NOT EXISTS `question_help_text` TEXT NULL
        COMMENT 'Optional short helper text shown under the question prompt'
        AFTER `comment_prompt`,
    ADD COLUMN IF NOT EXISTS `question_image_path` VARCHAR(500) NULL
        COMMENT 'Optional relative path under workspace/uploads/vendor_eval_questions/ for a supporting image'
        AFTER `question_help_text`;


-- ====================================================================
-- 7. Configurable pre-identification fields
--
-- question_section / input_type allowed values are enforced by the app
-- (master_vendor_evaluation_set.php + public_eval_v2.php), not a DB CHECK.
-- ====================================================================
ALTER TABLE `vendor_eval_questions`
  ADD COLUMN IF NOT EXISTS `question_section` VARCHAR(30) NOT NULL DEFAULT 'evaluation' AFTER `question_type`,
  ADD COLUMN IF NOT EXISTS `input_type`       VARCHAR(30) NOT NULL DEFAULT 'text'       AFTER `question_section`;


-- ====================================================================
-- 8. vendor_eval_v2_invite_log — invite send log / idempotency
--
-- idempotency_key = sha256(requestor_name|channel|sorted_po_list|message_hash)
-- ====================================================================
CREATE TABLE IF NOT EXISTS `vendor_eval_v2_invite_log` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `run_id`           VARCHAR(64)     NOT NULL
                           COMMENT 'Tool run identifier: Ymd_His_ + 4-byte random hex',
    `requestor_name`   VARCHAR(255)    NOT NULL,
    `channel`          ENUM('wa','email') NOT NULL,
    `recipient`        VARCHAR(255)    NOT NULL
                           COMMENT 'WA number or email address actually sent to',
    `cc_recipient`     VARCHAR(255)    NULL
                           COMMENT 'CC address for email channel; NULL for WA',
    `pending_po_count` INT             NOT NULL DEFAULT 0,
    `po_numbers_json`  TEXT            NOT NULL
                           COMMENT 'JSON array of all pending PO numbers for this requestor at send time',
    `message_hash`     CHAR(64)        NOT NULL
                           COMMENT 'sha256 of the raw message body (plain text for WA, HTML for email)',
    `idempotency_key`  CHAR(64)        NOT NULL
                           COMMENT 'sha256(requestor_name|channel|sorted_po_csv|message_hash)',
    `status`           ENUM('prepared','sent','failed','skipped') NOT NULL DEFAULT 'prepared',
    `sent_at`          DATETIME        NULL,
    `error_message`    TEXT            NULL,
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_invite_idempotency` (`idempotency_key`),
    KEY `idx_requestor_channel` (`requestor_name`, `channel`),
    KEY `idx_status_created`    (`status`, `created_at`),
    KEY `idx_run_id`            (`run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ====================================================================
-- 9. prs.master_data_id — taxonomy routing source
--
-- main/production already carries this column (auto-migration block in
-- workspace/config/database.php on that branch). This brings the local
-- branch's `prs` table into schema parity so PO-level taxonomy can be
-- sourced from prs.master_data_id -> master_data.id (authoritative,
-- human-curated) via tools/refresh_vendor_eval_po_taxonomy_from_master_data.php,
-- instead of relying solely on the fuzzy item-description matcher.
-- No data is populated by this file.
-- ====================================================================
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'prs'
      AND COLUMN_NAME = 'master_data_id'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE prs ADD COLUMN master_data_id INT NULL AFTER assigned_pic',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'prs'
      AND INDEX_NAME = 'idx_master_data_id'
);
SET @ddl2 := IF(@idx_exists = 0,
    'ALTER TABLE prs ADD INDEX idx_master_data_id (master_data_id)',
    'SELECT 1'
);
PREPARE stmt2 FROM @ddl2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
