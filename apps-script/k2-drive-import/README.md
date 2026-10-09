# K2 Drive Import Apps Script

This Apps Script project schedules the K2/PSS Google Drive automation client. It discovers the three expected XLSX files in a configured source folder, registers a deterministic batch with the VSS machine API, uploads files for AON and PR imports when the server state allows it, and moves the exact registered Drive files to Archive only after import success.

## Required Script Properties

Set these in Apps Script Project Settings > Script Properties:

- `SOURCE_FOLDER_ID`
- `ARCHIVE_FOLDER_ID`
- `VSS_BASE_URL`
- `VSS_PUBLIC_API_BASE_PATH` (optional when `VSS_BASE_URL` already includes the public prefix)
- `VSS_CLIENT_ID`
- `VSS_HMAC_SECRET`
- `PR_FILENAME`
- `CATALOG_FILENAME`
- `PRICE_FILENAME`
- `NOTIFICATION_EMAILS`
- `TIMEZONE`
- `DRY_RUN`
- `FILE_MINIMUM_AGE_MINUTES`
- `API_MAX_RETRIES`
- `ALLOW_NON_WORKDAY_RECOVERY`
- `RECOVERY_BATCH_LIMIT`
- `RECOVERY_RECORD_LIMIT`
- `RECOVERY_RETENTION_DAYS`
- `TERMINAL_RECOVERY_RETENTION_DAYS`
- `OPTIONAL_HOLIDAY_DATES`

Non-secret defaults:

- `TIMEZONE=Asia/Jakarta`
- `PR_FILENAME=Report Ticket Monitoring GP Officer.xlsx`
- `CATALOG_FILENAME=Master Item Catalog.xlsx`
- `PRICE_FILENAME=Price Per Vendor And Tiering.xlsx`
- `DRY_RUN=true`
- `FILE_MINIMUM_AGE_MINUTES=10`
- `ALLOW_NON_WORKDAY_RECOVERY=false`
- `RECOVERY_BATCH_LIMIT=2`
- `RECOVERY_RECORD_LIMIT=20`
- `RECOVERY_RETENTION_DAYS=30`
- `TERMINAL_RECOVERY_RETENTION_DAYS=7`

Do not put real secrets, production endpoint URLs, or recipient addresses in source code.

## Public API Path

PHP must be configured with `K2_AUTOMATION_PUBLIC_API_BASE_PATH` set to the externally visible route that contains the K2 automation endpoints. Reverse proxies and URL rewrites must preserve that public path for requests reaching PHP, or must set the same value explicitly on both PHP and Apps Script.

For example, if the public endpoint URL shape is:

```text
https://example.invalid/vms/workspace/api/k2_automation/import_aon.php
```

then PHP signs:

```text
/vms/workspace/api/k2_automation/import_aon.php
```

Apps Script derives the same path from `VSS_BASE_URL=https://example.invalid/vms`, or from `VSS_PUBLIC_API_BASE_PATH=/vms/workspace/api/k2_automation` when an explicit override is needed. `/workspace/...` and `/vms/workspace/...` are deliberately not interchangeable.

## Setup

1. Create a new Apps Script project.
2. Copy the `.gs` files and `appsscript.json` from this directory into the project.
3. Set the script timezone to `Asia/Jakarta`.
4. Add Script Properties.
5. Keep `DRY_RUN=true` for first validation.
6. Run `installK2DiscoverySweepTrigger()` manually only when ready.

### Scheduling

`installK2DiscoverySweepTrigger()` (→ `K2TriggerService.installDiscoverySweep()`) installs a
**single trigger that fires every 5 minutes all day**. It no-ops outside a slot's catch window,
so the effective schedule is:

| Slot | Files land | Discovery window (`windowStartMin` - `windowEndMin`) |
| --- | --- | --- |
| morning | ~09:36 WIB (was ~08:00 before Oct 2026) | **08:15 - 10:00** |
| midday | ~12:00-12:10 WIB | **12:15 - 12:45** |
| afternoon | ~15:00 WIB, seen as late as ~16:15 | **15:15 - 17:00** |

Sweeping a window rather than firing once lets a slot keep retrying until the files have landed
*and* settled, and absorbs Apps Script's trigger drift. The first tick that finds a ready batch
registers it; later ticks in the same window no-op (they short-circuit on the
`k2sweep_done_<date>|<slot>` marker before touching Drive, so a long window is cheap on a normal
day). Weekends and configured holidays are skipped (`isWorkday`).

The afternoon window is deliberately longer than the other two: the 15:00 drop has been observed
landing past 16:00. Morning was likewise pushed out to 10:00 when its drop drifted to ~09:36. **Widening a window means changing two things, not one** — this schedule *and*
the arrival window below, which is a separate filter on the file itself. Widening only the
schedule leaves the sweep ticking over files it will always reject as ineligible.

> **`processingHour` (9 / 12 / 15) in `SLOT_DEFS` is metadata, not a run time.** It only labels the
> slot and fills `scheduled_local` / `scheduled_slot_at_utc`. The morning batch is therefore stamped
> **09:00 while actually running anywhere from 08:15 to 10:00** — do not read `scheduled_slot_at_utc` as an execution
> time. `TriggerService.gs` is the source of truth for when anything actually happens.

`installTimeTriggers()` still exists and installs the **retired** fixed 09:00/12:00/15:00 discovery
triggers. `installDiscoverySweep()` deliberately calls `removeTimeTriggers()` first so the two can
never both fire and double-register a batch. Do not run `installTimeTriggers()` unless you
intend to go back to the old scheme; `runScheduledImport` remains available for manual nudges.

Run `removeDiscoverySweep()` to disable the scheduler (and `removeTimeTriggers()` if any legacy
fixed triggers are still installed).

## File Discovery

The script searches only inside `SOURCE_FOLDER_ID` and matches exact filenames:

- PR: `Report Ticket Monitoring GP Officer.xlsx`
- Catalog: `Master Item Catalog.xlsx`
- Price: `Price Per Vendor And Tiering.xlsx`

A file is only eligible if its Drive **last-modified time** falls inside its slot's arrival window.
This is a filter on the file, and is separate from the discovery schedule above — each slot's
cap is its `arrivalEndMin` plus `:59` seconds (`buildSlot()` in `TriggerService.gs`):

| Slot | Arrival window (file modified time, WIB) |
| --- | --- |
| morning | 07:00:00 - 09:45:59 |
| midday | 10:00:00 - 12:14:59 |
| afternoon | 13:00:00 - 16:29:59 |

**Invariant:** `windowEndMin` ≥ `arrivalEndMin` + `FILE_MINIMUM_AGE_MINUTES` (default 10) + one
5-minute tick — enforced by a test. An eligible file still has to clear the settle before any
tick will take it, so a file modified at the cap only becomes importable at cap + 10 and needs at
least one more sweep tick after that. Afternoon: cap 16:29:59 → eligible 16:39:59 → four ticks
before the window closes at 17:00. Morning: cap 09:45:59 → eligible 09:55:59 → exactly one tick
(10:00); morning's cap must stay below midday's 10:00 arrival start. A file that only becomes eligible after the final tick is never
imported at all — not that day, and not the next, because the next day's windows filter on their
own arrival ranges.

Files must be old enough and stable before hashing. Duplicate candidates are resolved deterministically by newest modified timestamp and Drive file ID; exact timestamp ties are treated as ambiguous.

## Security

Every API request is signed with HMAC-SHA256 using the Phase 4 canonical string:

```text
HTTP_METHOD
NORMALIZED_PATH
CLIENT_ID
TIMESTAMP
NONCE
REQUEST_ID
OPERATION
BATCH_UID
METADATA_SHA256
FILE_MANIFEST_SHA256
```

Each transport retry uses a new nonce, timestamp, and signature. The logical request ID remains stable for a retry of the same operation.

The archive flow uses `start_archive.php` to create or replay the archive attempt and returns the registered three-file manifest. `archive_result.php` completes that same logical request ID; it must not create a second archive attempt.

## Dry Run

`DRY_RUN=true` prevents real API calls, Drive moves, archive-result mutations, and email delivery. It may still discover files, hash blobs, validate metadata, and log summaries.

Client dry-run is separate from server-side AON preflight.

## Asynchronous AON/PR processing

`import_aon.php`/`import_pr.php` only validate and QUEUE an attempt — they never run XLSX
normalization or the business-data write inline. A separate PHP CLI worker
(`workspace/cron/process_k2_import_queue.php`, run on a Hostinger cron schedule) claims
queued attempts and performs the slow work out of band. This is why the batch/archive
states include `AON_QUEUED`/`PR_QUEUED` in addition to `AON_PROCESSING`/`PR_PROCESSING`.

This script does not need to distinguish "queued" from "processing" explicitly: both are
in-progress, both keep `next_action` at `'none'`, and both are polled the same way via
`batch_status` on the next scheduled or recovery run. `runAon()`/`runPr()` simply return
as soon as the server acknowledges the queue write; a later poll notices `AON_SUCCESS`/
`IMPORT_SUCCESS` (or a `*_FAILED_RETRYABLE`/`*_FAILED_FINAL` state) once the worker
finishes. No change here is required to accommodate slower processing — the design
already treated a synchronous completion as just one possible timing of the same
next-action-driven poll loop.

### Recovery-only entry point

`runK2RecoveryOnly()` is a dedicated entry point that only polls/advances existing recovery
records (queued/processing AON or PR attempts, PR-after-AON-success, archive-after-PR-success) —
it never performs Drive source discovery and never registers a new batch, so it is safe to run
much more often than the discovery sweep without risking a duplicate upload
or a duplicate batch registration. It is a thin `ScriptLock` wrapper around the exact same
`K2Main.processRecoveryRecords()` the normal scheduled run already uses for its own recovery pass.

**Recommended trigger cadence: every 5–10 minutes.** This closes most of the gap between a worker
finishing a queued AON/PR attempt and the next scheduled/recovery poll noticing it (worker runs on
a short cron interval per `db_backups/k2_drive_automation_migration_readme.md`, but Apps
Script only learns about the result on its own next invocation). The discovery sweep is unchanged
by this — `installDiscoverySweep()` installs only the every-5-minute `runK2DiscoverySweep` trigger,
and `runK2RecoveryOnly` is a separate trigger you add yourself.

**No trigger for `runK2RecoveryOnly()` is installed by this codebase.** Add it manually in the
Apps Script UI (Triggers → Add Trigger → choose `runK2RecoveryOnly` → time-driven → every 5 or 10
minutes) only when an operator has reviewed and approved it for the target environment.

## Archive

Archive moves use exact Drive file IDs from the registered batch. The script never archives when registration, AON, PR, or server state is unclear. If an archive move partially fails, later runs reconcile by Drive file ID and retry only missing files.

`ARCHIVE_FOLDER_ID` is pending and must be supplied by the operator before production.

## Recovery

After successful registration, the script stores one compact Script Properties recovery record keyed by batch UID. The record contains only safe manifest, state, attempt, request-ID, archive marker, terminal status, and notification marker data; it does not store the HMAC secret. Each workday trigger run validates configuration, loads bounded pending recovery records before source discovery, checks server status by saved batch UID, and resumes using the exact registered Drive file IDs. Completed records are cleared immediately after the server reports `COMPLETED`.

By default, recovery does not run on weekends or configured holidays. Set `ALLOW_NON_WORKDAY_RECOVERY=true` only when operators want pending recovery to continue on non-workdays; even then, new source-slot discovery and registration remain disabled. Final non-retryable recovery records are marked terminal, notified once, skipped by normal retry scans, and removed after `TERMINAL_RECOVERY_RETENTION_DAYS`.

## Validation

Use a harmless test folder and synthetic files to validate permissions. Keep `DRY_RUN=true` until Drive discovery, hashing, signing, and notification summaries are verified.

## Production Readiness

Deployment remains blocked until:

- `ARCHIVE_FOLDER_ID` is known;
- `VSS_BASE_URL`, `VSS_CLIENT_ID`, and `VSS_HMAC_SECRET` are provisioned;
- Hostinger endpoint runtime is ready;
- the PHP runtime has ext-zip/ZipArchive for real XLSX processing;
- production `K2_AON_MAX_REMOVAL_RATIO` is explicitly approved.

## Rollback

1. Run `removeTimeTriggers()`.
2. Set `DRY_RUN=true`.
3. Rotate the HMAC secret if there is any credential concern.
4. Leave the manual upload pages available as fallback.

Apps Script itself has no recurring cost expectation beyond normal Google Workspace quota usage for Drive reads, UrlFetch calls, and email notifications.
