<?php
// includes/master_data_audit.php
//
// Audit trail for master_data: who wrote a row, when, and a snapshot of the
// taxonomy columns at that moment (master_data_history).
//
// Only INSERT and UPDATE are recorded, and an UPDATE is only recorded when one
// of the tracked columns actually changed -- a write that merely clears
// suggested_pic_names or refreshes an AI cache leaves no history row.

// Guarded the same way includes/master_data_enrichment.php is: this file is
// pulled in through both '../includes/...' and __DIR__ forms, and a second
// declaration would be fatal.

// The columns snapshotted into master_data_history.
if (!defined('MD_AUDIT_COLUMNS')) {
    define('MD_AUDIT_COLUMNS', ['level_0', 'level_1', 'level_2', 'level_3', 'level_4', 'level_5', 'pic_names']);
    // created_by / last_modified_by value for anything the AI wrote on its own.
    define('MD_AUDIT_ACTOR_AI', 'ai');
    // Fallback for CLI / cron writes, which have no logged-in user to attribute to.
    define('MD_AUDIT_ACTOR_SYSTEM', 'SYSTEM');
}

if (!function_exists('md_audit_actor')) {

/**
 * Resolve the actor string stored in created_by / last_modified_by.
 *
 * Pass MD_AUDIT_ACTOR_AI explicitly from the AI write paths; otherwise the
 * logged-in user_id is used, falling back to the username and then to SYSTEM
 * so a cron write is never attributed to whoever happened to log in last.
 */
function md_audit_actor(?string $override = null): string {
    if ($override !== null && trim($override) !== '') {
        return trim($override);
    }
    // Read $_SESSION directly rather than gating on session_status(): that is
    // how the rest of the app reads it, and a CLI/cron caller simply has none.
    if (isset($_SESSION)) {
        $uid = $_SESSION['user_id'] ?? null;
        if ($uid !== null && trim((string)$uid) !== '') {
            return trim((string)$uid);
        }
        $uname = trim((string)($_SESSION['username'] ?? ''));
        if ($uname !== '') {
            return $uname;
        }
    }
    return MD_AUDIT_ACTOR_SYSTEM;
}

/**
 * Read the tracked columns for the given ids, keyed by id.
 *
 * Call this BEFORE an UPDATE and hand the result to md_audit_log_update();
 * that is what makes "only record changes are applied" true rather than
 * approximately true.
 */
function md_audit_snapshot(PDO $pdo, array $ids): array {
    $ids = array_values(array_filter(array_unique(array_map('intval', $ids)), static fn($i) => $i > 0));
    if (empty($ids)) {
        return [];
    }
    try {
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $cols = implode(', ', MD_AUDIT_COLUMNS);
        $stmt = $pdo->prepare("SELECT id, $cols FROM master_data WHERE id IN ($ph)");
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['id']] = $row;
        }
        return $out;
    } catch (\PDOException $e) {
        error_log('master_data audit snapshot failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Resolve the ids of rows whose level_4/level_5 pair matches, for the write
 * paths that key on level_4 (or level_4 + level_5) instead of on an id.
 */
function md_audit_ids_by_level(PDO $pdo, string $level_4, $level_5 = false, array $statuses = []): array {
    try {
        $sql    = "SELECT id FROM master_data WHERE level_4 = ?";
        $params = [$level_4];
        // false means "any level_5"; null is a real value here, since the write
        // paths that omit level_5 leave it NULL and the unique key is (l4, l5).
        if ($level_5 !== false) {
            $sql .= " AND level_5 <=> ?";
            $params[] = $level_5;
        }
        if (!empty($statuses)) {
            $sql .= " AND status IN (" . implode(',', array_fill(0, count($statuses), '?')) . ")";
            $params = array_merge($params, $statuses);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (\PDOException $e) {
        error_log('master_data audit id lookup failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Write one history row, snapshotting the CURRENT state of the master_data row.
 *
 * Auditing is a side effect of the write, never the point of it: a failure here
 * is logged and swallowed so it cannot take down the operation that succeeded.
 */
function md_audit_write(PDO $pdo, int $master_data_id, string $action, string $actor): bool {
    if ($master_data_id <= 0) {
        return false;
    }
    $action = strtoupper($action) === 'INSERT' ? 'INSERT' : 'UPDATE';
    try {
        $cols = implode(', ', MD_AUDIT_COLUMNS);
        $stmt = $pdo->prepare(
            "INSERT INTO master_data_history (master_data_id, action, $cols, created_by, created_at)
             SELECT id, ?, $cols, ?, NOW() FROM master_data WHERE id = ?"
        );
        $stmt->execute([$action, $actor, $master_data_id]);
        return $stmt->rowCount() > 0;
    } catch (\PDOException $e) {
        error_log('master_data audit write failed: ' . $e->getMessage());
        return false;
    }
}

/** Record a freshly inserted row. */
function md_audit_log_insert(PDO $pdo, int $master_data_id, ?string $actor = null): void {
    md_audit_write($pdo, $master_data_id, 'INSERT', md_audit_actor($actor));
}

/**
 * Record the rows in $before whose tracked columns differ from what is in the
 * table now. Rows the UPDATE did not actually change are skipped.
 */
function md_audit_log_update(PDO $pdo, array $before, ?string $actor = null): int {
    if (empty($before)) {
        return 0;
    }
    $after  = md_audit_snapshot($pdo, array_keys($before));
    $actor  = md_audit_actor($actor);
    $logged = 0;
    foreach ($after as $id => $now) {
        $was = $before[$id] ?? null;
        if ($was !== null && !md_audit_row_changed($was, $now)) {
            continue;
        }
        if (md_audit_write($pdo, (int)$id, 'UPDATE', $actor)) {
            $logged++;
        }
    }
    return $logged;
}

/**
 * An INSERT ... ON DUPLICATE KEY UPDATE resolves to either action at runtime.
 * MySQL reports 1 affected row for a genuine insert and 2 when the duplicate
 * branch changed something, which is the only reliable way to tell them apart.
 * lastInsertId() is only meaningful on the insert branch, so the duplicate
 * branch is resolved through the $before snapshot keyed on (level_4, level_5).
 */
function md_audit_log_upsert(PDO $pdo, PDOStatement $stmt, array $before, string $level_4, $level_5 = false, ?string $actor = null): void {
    $affected = $stmt->rowCount();
    if ($affected === 1) {
        $id = (int)$pdo->lastInsertId();
        if ($id <= 0) {
            $ids = md_audit_ids_by_level($pdo, $level_4, $level_5);
            $id  = empty($ids) ? 0 : (int)end($ids);
        }
        md_audit_log_insert($pdo, $id, $actor);
    } elseif ($affected >= 2 && !empty($before)) {
        // 2 affected rows means the duplicate branch fired and changed something.
        md_audit_log_update($pdo, $before, $actor);
    }
}

function md_audit_row_changed(array $before, array $after): bool {
    foreach (MD_AUDIT_COLUMNS as $col) {
        if ((string)($before[$col] ?? '') !== (string)($after[$col] ?? '')) {
            return true;
        }
    }
    return false;
}

} // function_exists('md_audit_actor')
