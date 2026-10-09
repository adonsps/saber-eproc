-- ====================================================================
-- SLA Revision Workflow — schema for the PIC request / Manager approval
-- flow on SLA Tracking (workspace/pages/sla_report.php).
--
-- Ships with:
--   workspace/includes/sla_revision_helper.php     (all sla_rev_* functions)
--   workspace/pages/sla_requests.php               (Manager approval inbox)
--   workspace/pages/sla_my_requests.php            (submitter's own requests)
--   workspace/pages/ajax_sla_request_{submit,decide,history}.php
--
-- BOTH tables below are also created automatically, and idempotently, on
-- every request by the auto-migration block in workspace/config/database.php
-- (same convention as sla_tracking_schema.sql). This file exists so a DBA can
-- apply them ahead of the deploy instead of letting DDL run from a web
-- request. Running it manually is safe and makes the auto-apply a no-op.
-- Verified on MySQL 8 (local Docker); the syntax is identical on MariaDB
-- (production).
--
-- RENAMED FROM THE ORIGINAL "ADJUSTMENT" NAMING
-- ---------------------------------------------
-- These tables shipped as `sla_adjustment_requests` and `sla_line_overrides`,
-- with indexes prefixed idx_sla_adj_. They were renamed to match the feature's
-- name, SLA Revision. An existing database is migrated by the rename step at
-- the top of the same auto-migration block, which fires only when the old name
-- still exists and the new one does not — so it is safe to re-run and a no-op
-- on a fresh install. Nothing but the names changed: no column, type, index
-- definition or row was touched.
--
-- WHY A SEPARATE OVERRIDE TABLE INSTEAD OF WRITING BACK INTO `prs`
-- ----------------------------------------------------------------
-- The PR Excel import upserts every PR line with
--     ON DUPLICATE KEY UPDATE ... pr_date = VALUES(pr_date),
--                                 canvassing_date_approver_1 = VALUES(...)
-- (see workspace/includes/excel_parser.php around line 343). An approved date
-- revision written straight into `prs` would therefore be silently reverted
-- by the next PR upload. Approved values live in sla_revision_overrides instead
-- and are layered on top of the raw `prs` row at computation time inside
-- get_sla_computed_rows() (workspace/includes/sla_helper.php). Side benefit:
-- the raw value stays in `prs` untouched, so the audit trail always has the
-- true original to compare against.
-- ====================================================================


-- --------------------------------------------------------------------
-- 1. sla_revision_requests — the permanent audit trail
--
-- APPEND-ONLY. Exactly two writes ever touch a row:
--   * one INSERT when a PIC submits;
--   * one UPDATE when the Manager decides (status + decided_* +
--     decision_notes only).
-- Never DELETE, never overwrite an earlier request. A re-submission for the
-- same PR line is a NEW row, so a prior rejection and its reason survive
-- forever — same design as `exception_requests` in the VSS database.
--
-- One submission may cover several line items of the same ticket. That is
-- still one row PER LINE: each line is approved on its own and gets its own
-- override. The two list screens fold those rows back into the submission for
-- display only (sla_rev_group_key()).
--
-- The old_* columns snapshot the EFFECTIVE values at submit time (i.e. after
-- any previously approved override), not the raw prs values, so the History
-- view reads as a coherent chain of what each requester actually saw.
--
-- approver_username snapshots users.manager_username at submit time. If the
-- PIC has no manager on file it stays NULL and the request falls through to
-- the Category Manager queue rather than being blocked.
--
-- REQUEST TYPES
--   'date'        one request carrying BOTH dates (requested_pr_date and/or
--                 requested_canvassing_date; a NULL means "not part of this
--                 request"). The two are read together when judging an SLA, so
--                 one approval settles both.
--   'sla_status'  overrides the computed verdict without touching a date; uses
--                 old_value / requested_value.
--   'pr_date', 'canvassing_date'
--                 LEGACY — one date per request, superseded by 'date'. Nothing
--                 creates them anymore. They stay in the ENUM, and reason /
--                 evidence_text / additional_notes stay as nullable columns,
--                 because the audit trail is never rewritten: rows filed under
--                 the old shape must remain readable exactly as submitted.
--                 New rows put the whole justification in `remarks`.
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sla_revision_requests (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    ticket_no             VARCHAR(50)  NOT NULL,
    ticket_line_item      VARCHAR(50)  NOT NULL,
    request_type          ENUM('date','sla_status','pr_date','canvassing_date') NOT NULL,
    old_value             VARCHAR(50)  NULL,
    requested_value       VARCHAR(50)  NULL,
    old_pr_date               DATE     NULL,
    old_canvassing_date       DATE     NULL,
    requested_pr_date         DATE     NULL,
    requested_canvassing_date DATE     NULL,
    remarks               TEXT         NULL,
    reason                TEXT         NULL,
    evidence_text         TEXT         NULL,
    evidence_url          VARCHAR(500) NULL,
    additional_notes      TEXT         NULL,
    requested_by_user_id  INT          NOT NULL,
    requested_by_name     VARCHAR(100) NOT NULL,
    requested_by_username VARCHAR(50)  NOT NULL,
    approver_username     VARCHAR(255) NULL,
    approver_name         VARCHAR(100) NULL,
    status                ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    decided_by_user_id    INT          NULL,
    decided_by_name       VARCHAR(100) NULL,
    decided_at            DATETIME     NULL,
    decision_notes        TEXT         NULL,
    created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sla_rev_line      (ticket_no, ticket_line_item),
    INDEX idx_sla_rev_approver  (approver_username, status),
    INDEX idx_sla_rev_status    (status),
    INDEX idx_sla_rev_requester (requested_by_username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------------------
-- 2. sla_revision_overrides — the currently active effective values
--
-- Derived table: it can be rebuilt at any time by replaying the approved
-- rows of sla_revision_requests in created_at order. Written ONLY by the
-- approval path (ajax_sla_request_decide.php -> sla_rev_apply_approved()),
-- never by a PIC, never by the PR import.
--
-- A NULL column means "no override for this field" — the raw prs value wins.
-- override_sla_status only takes effect while the COMPUTED status is one of
-- 'Within SLA' / 'SLA Breached' (see sla_rev_status_options()). If later data
-- pushes the line back to 'In Progress' / 'AON' / 'Out of Scope' — e.g. a PR
-- re-upload clears canvassing_date_approver_1 — the override goes dormant
-- rather than being deleted, and revives if the line becomes evaluable again.
-- Rationale: 'In Progress' means the SLA has not been decided yet, so there
-- is nothing for an override to override.
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sla_revision_overrides (
    ticket_no                VARCHAR(50)  NOT NULL,
    ticket_line_item         VARCHAR(50)  NOT NULL,
    override_pr_date         DATE         NULL,
    override_canvassing_date DATE         NULL,
    override_sla_status      VARCHAR(30)  NULL,
    last_request_id          INT          NULL,
    approved_by_name         VARCHAR(100) NULL,
    approved_at              DATETIME     NULL,
    updated_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ticket_no, ticket_line_item)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------------------
-- 3. Migration for a database that still carries the old names.
--
-- Optional here — workspace/config/database.php performs the same steps on
-- every request. Included so a DBA can do it ahead of the deploy. Run this
-- BEFORE the CREATE statements above, or they will create empty tables under
-- the new names and leave the real data stranded under the old ones.
-- --------------------------------------------------------------------
-- RENAME TABLE sla_adjustment_requests TO sla_revision_requests;
-- RENAME TABLE sla_line_overrides      TO sla_revision_overrides;
-- ALTER TABLE sla_revision_requests
--     DROP INDEX idx_sla_adj_line,      ADD INDEX idx_sla_rev_line      (ticket_no, ticket_line_item),
--     DROP INDEX idx_sla_adj_approver,  ADD INDEX idx_sla_rev_approver  (approver_username, status),
--     DROP INDEX idx_sla_adj_status,    ADD INDEX idx_sla_rev_status    (status),
--     DROP INDEX idx_sla_adj_requester, ADD INDEX idx_sla_rev_requester (requested_by_username);
