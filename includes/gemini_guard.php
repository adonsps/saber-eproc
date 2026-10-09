<?php
// includes/gemini_guard.php
//
// Spend protection for every Gemini call (VSS vendor modules and Workspace).
//
// Why this exists: in Oct 2026 the Gemini keys were hit with hundreds of
// thousands of requests, most of them rejected 402/429s from a client that kept
// retrying. Nothing in the app limited how often a key could be used or stopped
// calling once Google said "payment required" / "too many requests".
//
// Three independent protections, all file-based so no schema change is needed:
//   1. Per-key budget      gemini_guard_allow()   N calls/minute and N calls/day
//   2. Circuit breaker     gemini_guard_report()  402/403/429 pauses that key
//   3. Per-user throttle   gemini_guard_user()    N calls per window per user
//
// Keys are never stored: state files are named by a hash of the key.
// State lives under the system temp dir, so a container recreate resets it —
// acceptable for a safety net; the real ceiling is the prepaid balance.

if (!function_exists('gemini_guard_dir')) {

function gemini_guard_dir(): string {
    $dir = rtrim((string) (getenv('GEMINI_GUARD_DIR') ?: sys_get_temp_dir()), '/\\') . DIRECTORY_SEPARATOR . 'vss_gemini_guard';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function gemini_guard_limit(string $env, int $default): int {
    $v = getenv($env);
    return ($v !== false && ctype_digit((string) $v) && (int) $v > 0) ? (int) $v : $default;
}

/**
 * Read-modify-write one state file under an exclusive lock.
 * $fn receives the decoded array and returns [newState, result].
 * Fails open (returns $failOpen) if the file cannot be used, so a broken temp
 * dir can never take the AI features down.
 */
function gemini_guard_locked(string $name, callable $fn, $failOpen) {
    $path = gemini_guard_dir() . DIRECTORY_SEPARATOR . $name . '.json';
    $fh = @fopen($path, 'c+');
    if (!$fh) {
        return $failOpen;
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            return $failOpen;
        }
        $raw = stream_get_contents($fh);
        $state = $raw !== '' && $raw !== false ? json_decode($raw, true) : [];
        if (!is_array($state)) {
            $state = [];
        }
        [$state, $result] = $fn($state);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($state));
        fflush($fh);
        return $result;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function gemini_guard_key_id(string $apiKey): string {
    return 'key_' . substr(hash('sha256', $apiKey), 0, 16);
}

/**
 * May this key make one more call right now? Counts the call if so
 * (web requests only; CLI is exempt from the budgets, see below).
 * @return array{ok:bool, reason:string, retry_after:int}
 */
function gemini_guard_allow(string $apiKey, ?int $now = null, ?bool $cli = null): array {
    $now = $now ?? time();
    // CLI runs (taxonomy mapper, K2 worker) are deliberate, already-bounded
    // batches: a per-minute budget would fail rows halfway through a run. They
    // still honour the circuit breaker, which is what stops a 402/429 storm.
    $cli = $cli ?? (PHP_SAPI === 'cli');
    $perMin = gemini_guard_limit('GEMINI_GUARD_PER_MIN', 60);
    $perDay = gemini_guard_limit('GEMINI_GUARD_PER_DAY', 5000);

    return gemini_guard_locked(gemini_guard_key_id($apiKey), function (array $s) use ($now, $perMin, $perDay, $cli) {
        $blockedUntil = (int) ($s['blocked_until'] ?? 0);
        if ($blockedUntil > $now) {
            return [$s, ['ok' => false, 'reason' => 'breaker', 'retry_after' => $blockedUntil - $now]];
        }
        if ($cli) {
            return [$s, ['ok' => true, 'reason' => '', 'retry_after' => 0]];
        }
        $minute = intdiv($now, 60);
        $day = intdiv($now, 86400);
        if (($s['minute'] ?? null) !== $minute) { $s['minute'] = $minute; $s['minute_n'] = 0; }
        if (($s['day'] ?? null) !== $day)       { $s['day'] = $day;       $s['day_n'] = 0; }

        if ($s['minute_n'] >= $perMin) {
            return [$s, ['ok' => false, 'reason' => 'per_minute', 'retry_after' => 60 - ($now % 60)]];
        }
        if ($s['day_n'] >= $perDay) {
            return [$s, ['ok' => false, 'reason' => 'per_day', 'retry_after' => 86400 - ($now % 86400)]];
        }
        $s['minute_n']++;
        $s['day_n']++;
        return [$s, ['ok' => true, 'reason' => '', 'retry_after' => 0]];
    }, ['ok' => true, 'reason' => '', 'retry_after' => 0]);
}

/**
 * Tell the guard what HTTP status Google answered with. 402/403 (payment, key
 * revoked or blocked) pause the key for 30 min; 429 (rate/quota) for 5 min.
 * Without this every request keeps knocking on a door Google has closed.
 */
function gemini_guard_report(string $apiKey, int $httpCode, ?int $now = null): void {
    $pause = in_array($httpCode, [402, 403], true) ? 1800 : ($httpCode === 429 ? 300 : 0);
    if ($pause === 0) {
        return;
    }
    $now = $now ?? time();
    gemini_guard_locked(gemini_guard_key_id($apiKey), function (array $s) use ($now, $pause) {
        $s['blocked_until'] = max((int) ($s['blocked_until'] ?? 0), $now + $pause);
        $s['last_status'] = $pause === 300 ? 429 : 402;
        return [$s, null];
    }, null);
}

/**
 * Per-user throttle: at most $limit calls per $windowSeconds for one identity
 * in one bucket (e.g. a vendor user id on the doc-extract endpoint).
 * Returns false when the user is over the limit. Counts the call if allowed.
 */
function gemini_guard_user(string $bucket, string $identity, int $limit, int $windowSeconds, ?int $now = null): bool {
    $now = $now ?? time();
    $name = 'user_' . substr(hash('sha256', $bucket . '|' . $identity), 0, 20);
    return gemini_guard_locked($name, function (array $s) use ($now, $limit, $windowSeconds) {
        $start = (int) ($s['start'] ?? 0);
        if ($start === 0 || $now - $start >= $windowSeconds) {
            $s = ['start' => $now, 'n' => 0];
        }
        if ($s['n'] >= $limit) {
            return [$s, false];
        }
        $s['n']++;
        return [$s, true];
    }, true);
}

}
