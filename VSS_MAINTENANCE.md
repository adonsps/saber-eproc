# VSS Maintenance Mode

How to close the vendor portal (VSS) so no vendor data flows in while you work on
the database.

## Why there are two switches

Maintenance state lives in **two** places, and the gate treats either one as
"maintenance is on":

| Source | Where | Survives a DB restore? |
| --- | --- | --- |
| Flag file | `storage/maintenance.flag` on the VSS host | **Yes** |
| DB row | `maintenance_settings.is_active` in `u894129685_vmsportal` | No |

The DB row alone used to be the whole mechanism, and it could not protect the one
job it existed for. Restoring a dump into `u894129685_vmsportal` either overwrites
the singleton row with the dump's own `is_active = 0` or drops the table outright
— and the next page hit then rebuilt the table seeded as "portal open". Either
way maintenance switched itself off in the middle of the restore and vendors kept
inserting rows, which is how a restore ends up fighting live traffic over
`AUTO_INCREMENT` ids.

The flag file lives outside the database, so nothing a restore does can clear it.

## Turning it on before database work

From the repo root on the VSS host:

```bash
docker-compose exec app php tools/vss_maintenance.php on "restore vmsportal dump"
docker-compose exec app php tools/vss_maintenance.php status   # confirm before you start
```

`status` prints the effective state, which source it came from, and both
underlying values. Do not start the restore until it reads `effective : ON`.

The Category-Manager UI at **workspace → Maintenance Settings** does the same
thing — it writes the flag file first, then the DB row — so either route is safe.
The UI shows both indicators so you can see the file flag is really in place.

Turning it off again:

```bash
docker-compose exec app php tools/vss_maintenance.php off "restore selesai"
```

## What gets blocked

Blocked (redirected to `maintenance.php`, HTTP 503):

- every logged-in vendor page and action endpoint;
- **every anonymous vendor-facing page** — `register.php`, `register_action.php`,
  `forgot_password.php`, `reset_password.php`, `verify.php`. These write to
  `users` / `vendors` *before* a vendor session exists, so a gate keyed on
  `$_SESSION['role'] === 'vendor'` let exactly the inserts we care about through.

JSON endpoints (`check_nib.php`, `*_ai.php`) get an HTTP 503 JSON body with
`"maintenance": true` rather than an HTML redirect their callers cannot parse.

Never blocked:

- `login.php`, `login_action.php`, `logout.php`, `maintenance.php`;
- anyone holding an internal role — `sourcing`, `vendor_mgt`, `vendormgt_head`,
  `admin`, `project`, `requestor`. Otherwise nobody could turn maintenance off.
- the workspace app under `workspace/` — it is the internal procurement app on a
  different database and never loads the VSS `config.php`.

## Fail-closed behaviour

When the gate cannot determine the state it holds vendors off rather than guess:

- VSS database unreachable → vendor traffic gets the maintenance page; internal
  users get a self-contained 503 page. (Previously an uncaught
  `mysqli_sql_exception` printed the DB host and username into the browser.)
- `maintenance_settings` missing or its row absent → the table is recreated
  seeded **`is_active = 1`**, not 0, and stays that way until someone turns it
  off deliberately.

One consequence worth knowing: a genuinely fresh install also comes up in
maintenance mode. That is the safe direction to be wrong in, it is visible on the
admin page, and it clears with one `off` command.

## Files

- `includes/maintenance_gate.php` — the gate; both sources, the audience rule,
  the redirect/JSON split, and the offline page.
- `config.php` — calls `vss_maintenance_ensure_tables()` then
  `vss_maintenance_guard($conn)` on every VSS request.
- `tools/vss_maintenance.php` — CLI `status` / `on` / `off`.
- `workspace/pages/maintenance_settings.php` — Category Manager UI + audit log.
- `maintenance.php` — the vendor-facing maintenance page.
