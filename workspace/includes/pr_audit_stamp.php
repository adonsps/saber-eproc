<?php
// includes/pr_audit_stamp.php
/**
 * Edit trail for the assigned PIC on a `prs` line.
 *
 * `prs.last_updated_by` / `prs.last_updated_date` answer one question: who last
 * changed this PR line's ASSIGNED PIC, and when.
 *
 * They track `assigned_pic` and nothing else. A taxonomy remap, a baseline
 * price, an OTF recalculation or a plain K2 re-import all leave the two columns
 * exactly as they were -- otherwise one nightly recalculation would restamp the
 * whole table and bury the single fact these columns exist to record. So:
 *   - INSERT  -> stamped only when the new line arrives WITH a PIC on it.
 *   - UPDATE  -> stamped only when `assigned_pic` actually changes value.
 *
 * Writes that set `assigned_pic` build their SET clause with
 * pr_stamp_pic_set_sql() + pr_stamp_pic_params() below, which gate the two
 * columns on that comparison in SQL. Writes that never touch `assigned_pic`
 * simply do not name the columns at all.
 *
 * The Workload Dashboard renders them under the name in its Assigned PIC
 * column; everywhere else they are read straight from `prs`.
 *
 * Timestamps are generated here in PHP, in Asia/Jakarta, rather than with SQL
 * NOW(): the MySQL container runs on UTC (@@time_zone = +00:00) while the app is
 * pinned to WIB in config.php, and prs.created_at is already stored as WIB by
 * includes/excel_parser.php. Using NOW() would make the two columns disagree by
 * seven hours.
 */

if (!function_exists('pr_stamp_by')) {
    /**
     * Resolve who is performing the current `prs` write.
     *
     * @param string|null $actor Explicit actor, when the caller already knows it
     *                           (an import's `imported_by`, or a label such as
     *                           'AI Auto-Assign' for unattended assignment).
     *                           Falls back to the logged-in workspace user, then
     *                           to the CLI script name, then to 'system'.
     */
    function pr_stamp_by($actor = null)
    {
        if (is_string($actor)) {
            $actor = trim($actor);
            if ($actor !== '') {
                return mb_substr($actor, 0, 100);
            }
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            // Same order upload_pr.php already uses for `imported_by`.
            foreach (['username', 'name', 'user_name', 'email'] as $key) {
                $value = trim((string)($_SESSION[$key] ?? ''));
                if ($value !== '') {
                    return mb_substr($value, 0, 100);
                }
            }
        }

        if (PHP_SAPI === 'cli') {
            $script = isset($_SERVER['argv'][0]) ? basename((string)$_SERVER['argv'][0]) : 'cli';
            return mb_substr('cli:' . $script, 0, 100);
        }

        return 'system';
    }
}

if (!function_exists('pr_stamp_now')) {
    /**
     * Current Asia/Jakarta wall-clock time, formatted for a DATETIME column.
     */
    function pr_stamp_now()
    {
        return (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
    }
}

if (!function_exists('pr_stamp_pic_set_sql')) {
    /**
     * SET-clause fragment that stamps the edit trail ONLY when `assigned_pic` is
     * about to change value.
     *
     * MUST be placed FIRST in the SET list. MySQL applies assignments left to
     * right, so the comparison has to run while `assigned_pic` still holds the
     * old value -- listed after the real assignment it would always compare
     * equal and never stamp anything.
     *
     * @param string $new_pic_expr SQL expression for the NEW pic value: '?' for
     *                             an ordinary bound parameter (the usual case),
     *                             or a whole CASE ... END for a rule-driven
     *                             sweep. It is evaluated twice, once per column.
     * @param string $prefix       Table alias plus a dot ('p.') when the UPDATE
     *                             uses one, otherwise ''.
     */
    function pr_stamp_pic_set_sql($new_pic_expr = '?', $prefix = '')
    {
        $changed = "NOT ({$prefix}assigned_pic <=> {$new_pic_expr})";
        return "{$prefix}last_updated_by = IF({$changed}, ?, {$prefix}last_updated_by), "
             . "{$prefix}last_updated_date = IF({$changed}, ?, {$prefix}last_updated_date)";
    }
}

if (!function_exists('pr_stamp_pic_params')) {
    /**
     * The four bound values pr_stamp_pic_set_sql('?') expects, in order:
     * the new PIC (for the first IF), the actor, the new PIC again (second IF),
     * the timestamp.
     *
     * Pass $by/$at explicitly to give every line of a batch the same stamp
     * instead of re-resolving them per statement.
     */
    function pr_stamp_pic_params($new_pic, $by = null, $at = null)
    {
        if ($by === null) { $by = pr_stamp_by(); }
        if ($at === null) { $at = pr_stamp_now(); }
        return [$new_pic, $by, $new_pic, $at];
    }
}
