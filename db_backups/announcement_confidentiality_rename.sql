-- Announcement confidentiality rename.
-- Internal GP users kept publishing with the wrong confidentiality because the
-- old option names did not say who the audience actually is. New vocabulary:
--   PUBLIC       -> EXTERNAL      (shared with vendors via VSS)
--   RESTRICTED   -> INTERNAL_KLG  (all KLG employees)
--   CONFIDENTIAL -> GP_ONLY       (Group Procurement only)
--
-- MySQL cannot rename an ENUM member while rows still hold the old value, so the
-- ENUM is first widened to both vocabularies, the rows are rewritten, and the
-- ENUM is then narrowed to the new values only.
-- Idempotent: re-running on an already-migrated table is a no-op (the UPDATEs
-- match nothing and the final MODIFY is the current definition).
--
-- The same migration runs automatically from workspace/config/database.php on
-- the first request after deploy; this file is for applying it by hand.

ALTER TABLE announcements MODIFY COLUMN confidentiality
    ENUM('PUBLIC','RESTRICTED','CONFIDENTIAL','EXTERNAL','INTERNAL_KLG','GP_ONLY')
    NOT NULL DEFAULT 'EXTERNAL';

UPDATE announcements SET confidentiality = 'EXTERNAL'     WHERE confidentiality = 'PUBLIC';
UPDATE announcements SET confidentiality = 'INTERNAL_KLG' WHERE confidentiality = 'RESTRICTED';
UPDATE announcements SET confidentiality = 'GP_ONLY'      WHERE confidentiality = 'CONFIDENTIAL';

ALTER TABLE announcements MODIFY COLUMN confidentiality
    ENUM('EXTERNAL','INTERNAL_KLG','GP_ONLY') NOT NULL DEFAULT 'EXTERNAL';
