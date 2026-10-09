-- ====================================================================
-- Perseorangan PQ Question Set — schema tweak + seed
--
-- Gives the 'Perseorangan' company_type its own standalone PQ question
-- set, copied from the Contractor 'Individual / Foreman' set. It creates
-- both the question rows (pq_questions.variant_target = 'Perseorangan')
-- and their weights (pq_weight.sub_variant_target = 'Perseorangan').
--
-- Idempotent and safe to re-run on any environment (local Docker/QA/prod).
--
-- ---------------------------------------------------------------
-- WHEN DO I NEED TO RUN THIS?
--
--   Schema (section 0, the variant_target enum widening): NORMALLY NEVER
--   by hand. config.php applies the exact same ALTER automatically the
--   first time any page loads (see its pq_questions.variant_target
--   migration block). It is repeated here only so this file is
--   self-contained if you run it before opening a page.
--
--   Seed data (sections 1-2): YES — run once per environment. config.php
--   does NOT insert these rows; only this file does. Without them a
--   Perseorangan vendor gets an empty PQ form.
--
--   Requires the Contractor 'Individual / Foreman' question + weight rows
--   to already exist (they ship in db_backups/vmsportal_dump.sql).
--
-- ---------------------------------------------------------------
-- HOW TO RUN IT (local Docker)
--
--   From the repo root, with the stack up (docker compose up -d):
--
--     docker exec -i workspace-db-1 \
--       mysql -u <db_user> -p<db_pass> u894129685_vmsportal \
--       < db_backups/seed_perseorangan_from_individual_foreman.sql
--
--   - <db_user>/<db_pass> are the VMS portal credentials in config.php.
--   - 'workspace-db-1' is the MySQL container from docker-compose.yml;
--     run `docker ps` if your container name differs.
--   - QA/prod: pipe the same file into that environment's mysql client.
--
--   Verify afterwards:
--     SELECT q.id, w.weight, q.question_text
--     FROM pq_questions q JOIN pq_weight w ON w.question_id = q.id
--     WHERE w.sub_variant_target = 'Perseorangan';
--   -> expect the 3 Individual/Foreman questions (weights 55 / 15 / 30).
-- ====================================================================

-- 0) Ensure 'Perseorangan' is a valid variant_target (mirrors config.php).
--    ALTER ... MODIFY is idempotent: harmless if the enum already has it.
ALTER TABLE pq_questions
    MODIFY variant_target
    ENUM('All','Principal','Distributor','Retail','Contractor','Goods','Supplier','Perseorangan')
    COLLATE utf8mb4_unicode_ci DEFAULT 'All';

-- 1) Copy the question rows.
INSERT INTO pq_questions (section_id, variant_target, question_text, help_text, is_active)
SELECT q.section_id, 'Perseorangan', q.question_text, q.help_text, q.is_active
FROM pq_questions q
JOIN pq_weight w ON w.question_id = q.id AND w.sub_variant_target = 'Individual / Foreman'
WHERE NOT EXISTS (
    SELECT 1 FROM pq_questions q2
    JOIN pq_weight w2 ON w2.question_id = q2.id AND w2.sub_variant_target = 'Perseorangan'
    WHERE q2.question_text = q.question_text
);

-- 2) Copy the matching weight rows onto the freshly-created Perseorangan questions
--    (matched back to their Individual/Foreman source by identical question text).
INSERT INTO pq_weight (question_id, sub_variant_target, weight, help_text)
SELECT qp.id, 'Perseorangan', w.weight, w.help_text
FROM pq_questions qp
JOIN pq_weight w ON w.sub_variant_target = 'Individual / Foreman'
JOIN pq_questions q ON q.id = w.question_id AND q.question_text = qp.question_text
WHERE qp.variant_target = 'Perseorangan'
  AND NOT EXISTS (
      SELECT 1 FROM pq_weight w2 WHERE w2.question_id = qp.id AND w2.sub_variant_target = 'Perseorangan'
  );
