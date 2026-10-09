# K2 Drive Automation Migration Plan

This directory now has two explicit deployment paths for the K2 Drive automation tables.

## Database engine — MySQL vs. MariaDB

**Local Docker dev runs real MySQL 8.0. Hostinger production runs MariaDB 11.8.8.**
These are not interchangeable for Phase 8: MySQL's `ALTER TABLE ... DROP CHECK
constraint_name` is rejected by MariaDB with error 1064 ("You have an error in
your SQL syntax"), which requires `DROP CONSTRAINT constraint_name` instead.
This was discovered on 2026-07-27 during a first production apply attempt.

Two Phase 8 files exist for exactly this reason — **use the one matching your
target engine**:

- `db_backups/k2_drive_automation_phase8_async_queue_schema_mysql.sql` — MySQL 8.0+. This is the file the local Docker test suite applies.
- `db_backups/k2_drive_automation_phase8_async_queue_schema_mariadb.sql` — MariaDB 10.2.1+, verified specifically against MariaDB 11.8.8 (production's exact version) in a disposable local container. **Production must use this file.**

Every other statement in both files (column additions, enum widening, the new
index, and the CHECK clause itself) is byte-for-byte identical — the only
difference is that one line.

**Application method for MariaDB is required, not optional:** apply via direct
`mariadb` CLI file piping —
```
mariadb -u<user> -p <database> < k2_drive_automation_phase8_async_queue_schema_mariadb.sql
```
Do **not** apply it via a naive PHP per-statement semicolon-split-and-`exec()`
loop (the pattern this repo's own PHP test helpers use for local MySQL
testing). That approach was empirically found, during the 2026-07-27 parity
investigation, to silently fail to create the table-level CHECK constraint on
MariaDB — the ALTER statements reported no error, but `SHOW CREATE TABLE`
afterward showed no CHECK constraint at all — even though the identical
PHP-loop approach works correctly and reliably on MySQL 8.0. Piping the whole
file to the CLI in one process is the only method verified to work reliably on
MariaDB, and matches how production's existing phase2/phase4 schema was
evidently originally applied.

The `fresh_install_schema.sql` file and the `phase2`/`phase4`/`phase7` files
are all pure `CREATE TABLE`/additive-`ALTER ADD COLUMN` statements with no
`DROP CHECK`/`DROP CONSTRAINT` step, so they are already engine-portable
as-is and do not need MySQL/MariaDB variants.

## Fresh Installation

Apply only:

```text
db_backups/k2_drive_automation_fresh_install_schema.sql
```

This schema contains the complete final automation schema exactly once, including API nonce storage, the Phase 7 operation request columns/indexes, and the Phase 8 async worker queue columns:

- `logical_request_id`
- `attempt_number`
- `uq_k2_auto_operation_logical_request`
- `staged_file_manifest`, `worker_claim_token`, `worker_claimed_at`, `worker_hostname`, `worker_pid`
- `idx_k2_auto_attempt_queue_claim`
- `AON_QUEUED` and `PR_QUEUED` batch states (on both `k2_automation_import_batches.state` and `k2_automation_operation_requests.response_batch_state`), distinguishing "queued, unclaimed" from "a worker is actively processing it"

Do not apply `k2_drive_automation_phase7_schema.sql` or either Phase 8 variant (`_mysql.sql` / `_mariadb.sql`) after the fresh-install schema.

## Existing Phase 2/4 Installation

For a database that already has the Phase 2 import-state tables and Phase 4 nonce table, but does not yet have the Phase 7 operation request columns/index:

```text
db_backups/k2_drive_automation_phase7_schema.sql
```

Apply only that Phase 7 additive migration for this upgrade path, then continue to the Phase 8 step below.

## Existing Phase 7 Installation (adding the async worker queue)

For a database that already has the Phase 7 operation-request columns but not yet the Phase 8 worker-queue columns on `k2_automation_operation_attempts`, apply the variant matching your database engine (see "Database engine — MySQL vs. MariaDB" above):

```text
db_backups/k2_drive_automation_phase8_async_queue_schema_mysql.sql      (MySQL 8.0+ — local Docker dev)
db_backups/k2_drive_automation_phase8_async_queue_schema_mariadb.sql    (MariaDB 10.2.1+ — Hostinger production)
```

**Before applying either, check whether `logical_request_id`/`attempt_number` already exist on `k2_automation_operation_requests`** — if they do, Phase 7 must be skipped entirely (do not apply `k2_drive_automation_phase7_schema.sql`), since applying it on top of a database that already has those columns fails with a duplicate-column error. This was confirmed to be production's actual state as of 2026-07-27.

Applying the chosen Phase 8 variant is required before running `workspace/cron/process_k2_import_queue.php` against that database — the worker's atomic claim query and staged-file lookup depend on these columns, and `import_aon.php`/`import_pr.php` depend on the widened `AON_QUEUED`/`PR_QUEUED` batch states in the same file to queue an operation instead of running it inline.

## Important Guardrail

Never apply the Phase 7 or either Phase 8 additive migration after the final fresh-install schema. The fresh-install schema already includes those columns/keys, so applying either additive migration afterward should be treated as an operator error and must be stopped or clearly fail during review/testing.

Do not execute these migrations against production without an approved deployment window, backup, and operator review.

## CLI worker (Phase 8 async queue)

`workspace/cron/process_k2_import_queue.php` must be invoked by a Hostinger cron job on a
short interval (e.g. every 1-2 minutes) with `--limit=1` (or a small number). It claims at
most that many queued AON/PR attempts, runs XLSX normalization/preflight and the business
write outside the HTTP request, and exits. It is CLI-only and refuses to run under a web
SAPI. Run `php workspace/automation/check_k2_automation_readiness.php` first — it validates
the private storage path, worker timing configuration, and migration presence described
below.

## Environment variables (no real values — configure per environment)

Existing (HTTP API / manual synchronous parsing, unchanged by the async queue):

- `K2_AUTOMATION_CLIENT_ID`, `K2_AUTOMATION_HMAC_SECRET` — signing identity for the Apps Script client.
- `K2_AUTOMATION_PUBLIC_API_BASE_PATH` — externally visible route prefix (e.g. `/vms/workspace/api/k2_automation`).
- `K2_AUTOMATION_ALLOWED_CLOCK_SKEW_SECONDS` (default `300`), `K2_AUTOMATION_MAX_UPLOAD_BYTES` (default `67108864`), `K2_AUTOMATION_LOCK_SECONDS` (default `900`), `K2_AUTOMATION_STALE_OPERATION_SECONDS` (default `1800`).
- `K2_AUTOMATION_TEMP_DIR`, `K2_AUTOMATION_IMPORTED_BY` (default `k2_automation`).
- `K2_AON_MAX_REMOVAL_RATIO` — required once any AON import would soft-delete active SKUs; must be set for production.
- `K2_AON_GLOBAL_LOCK_TIMEOUT_SECONDS` (default `30`).

New for the async queue (Phase 8):

- `K2_AUTOMATION_STORAGE_ROOT` — **required for production.** Absolute path outside `public_html`/the application checkout where staged AON/PR uploads live before a worker processes them. Rejected if relative, containing `..`, inside the checkout, or under a `public_html`/`htdocs`/`www`/`wwwroot` segment. Defaults to a sibling directory of the checkout, which is only appropriate for local/dev use — set this explicitly in production.
- `K2_AUTOMATION_STAGED_RETENTION_SECONDS` (default `86400`) — general sweep window for orphaned staged files.
- `K2_AUTOMATION_FAILED_RETENTION_SECONDS` (default `259200`, 3 days) — how long a **failed** attempt's staged files are retained for diagnosis before the worker's sweep removes them.
- `K2_AUTOMATION_WORKER_LEASE_SECONDS` (default `1800`) — how long a worker's claim on one attempt is honored before another worker may reclaim it (covers a crashed/killed worker process).
- `K2_AUTOMATION_WORKER_LOCK_SECONDS` (default `1800`) — batch run-lock duration the worker holds while normalizing/writing; must comfortably exceed the slowest observed operation (PR normalization has been observed around 340s).
- `K2_AUTOMATION_DIAGNOSTIC_LOG_PATH` — path to the structured JSON-lines diagnostic log written by both the HTTP handlers and the worker. Defaults to a file under the system temp directory; set explicitly so it survives in a known, log-rotated location in production.

Test-only (never set in production):

- `K2_AUTOMATION_TEST_DSN`, `K2_AUTOMATION_TEST_USER`, `K2_AUTOMATION_TEST_PASSWORD` — when `K2_AUTOMATION_TEST_DSN` is present, both the HTTP bootstrap and the CLI worker connect to it instead of `workspace/config/database.php`. Point this only at a disposable local/CI database.
