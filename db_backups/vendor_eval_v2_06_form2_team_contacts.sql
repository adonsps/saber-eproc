-- ====================================================================
-- Vendor Evaluation v2 — Form 2 team contact roster (06 of 6)
--
-- Additive only. Apply AFTER vendor_eval_v2_01_schema.sql /
-- vendor_eval_v2_02_seed_business_data.sql / vendor_eval_v2_03_seed_
-- requestor_contacts.sql / vendor_eval_v2_04_po_question_set_override.sql /
-- vendor_eval_v2_05_form2_dual_submissions.sql (see db_backups/
-- vendor_eval_v2_deployment_handover.txt for the authoritative apply
-- order — this file is a new step 6).
--
-- vendor_eval_v2_form2_team_contacts — a simple, editable roster of people
-- to notify/remind about Form 2 (Ops/Project) submissions. Distribution/
-- reminder targeting only, NOT access control: which team (ops vs project)
-- a person submits under is still self-declared per submission, not tied
-- to identity, so this table intentionally has no role/team column.
--
-- whatsapp_number is normalized to the same convention already used by
-- requestors.whatsapp_number (country-code-prefixed digits only, no '+',
-- spaces, or dashes — e.g. '6281234567890'), for consistency with future
-- Fonnte WA sending.
--
-- Before applying: confirm DB identity and take a backup.
--   SELECT DATABASE(), USER(), @@hostname, VERSION();
-- ====================================================================

CREATE TABLE IF NOT EXISTS `vendor_eval_v2_form2_team_contacts` (
  `id`               INT          NOT NULL AUTO_INCREMENT,
  `name`             VARCHAR(255) NOT NULL,
  `email`            VARCHAR(255) DEFAULT NULL,
  `whatsapp_number`  VARCHAR(50)  DEFAULT NULL,
  `notes`            VARCHAR(255) DEFAULT NULL,
  `added_at`         DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: 10 people (Ops + Project distribution list), 2026-07-28.
-- Idempotent on (name, email) — safe to re-run.

INSERT INTO `vendor_eval_v2_form2_team_contacts` (`name`, `email`, `whatsapp_number`)
SELECT * FROM (SELECT
    'Mulki Tamam'        AS name, 'mulki.tamam@kawanlamaretail.com'        AS email, '6287881228456'  AS whatsapp_number
  UNION ALL SELECT 'Ade Kusmana',       'ade.kusmana@kawanlamaretail.com',       '6287775756892'
  UNION ALL SELECT 'Benny Sulasmanto',  'benny.sulasmanto@kawanlamaretail.com',  '6281293958258'
  UNION ALL SELECT 'Anggoro Warsiyadi', 'anggoro.warsiyadi@kawanlamaretail.com', '6281283381725'
  UNION ALL SELECT 'Juanita Sareal',    'juanita.sareal@kawanlamaretail.com',    '628161412356'
  UNION ALL SELECT 'Dede Sulaiman',     'dede.sulaiman@kawanlamaretail.com',     '6281519533373'
  UNION ALL SELECT 'Maulidin TDP',      'maulidin.tdp@kawanlamaretail.com',      '6281584000783'
  UNION ALL SELECT 'Ardy Julianto',     'ardy.julianto@kawanlamaretail.com',     '6282221993281'
  UNION ALL SELECT 'Aditya Anggoro',    'aditya.anggoro@ahi.id',                 '6283892289349'
  UNION ALL SELECT 'Mochamat Iqbal',    'mochamat.iqbal@ahi.id',                 '6281332335113'
) AS seed
WHERE NOT EXISTS (
  SELECT 1 FROM `vendor_eval_v2_form2_team_contacts` existing
   WHERE existing.`name` = seed.name AND existing.`email` = seed.email
);
