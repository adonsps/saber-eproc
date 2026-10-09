<?php
/**
 * Calculate SLA deadline by adding N working days to a start date,
 * skipping weekends + holidays + joint leave.
 * All arithmetic is done in Asia/Jakarta (WIB) time.
 */
function calculateSlaDeadline($conn, string $startDate, int $workingDays = 3): string {
    $tz = new DateTimeZone('Asia/Jakarta');

    // Load all holiday dates into memory
    $holidays = [];
    $r = $conn->query("SELECT holiday_date FROM holidays");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $holidays[] = $row['holiday_date'];
        }
    }

    $date  = new DateTime($startDate, $tz);
    $added = 0;

    while ($added < $workingDays) {
        $date->modify('+1 day');
        $dayOfWeek = (int)$date->format('w'); // 0=Sun, 6=Sat
        $dateStr   = $date->format('Y-m-d');

        if ($dayOfWeek === 0 || $dayOfWeek === 6) continue;  // skip weekend
        if (in_array($dateStr, $holidays))        continue;  // skip holiday / joint leave

        $added++;
    }

    // Deadline = end of business day on Nth working day (23:59:59 WIB)
    $date->setTime(23, 59, 59);
    return $date->format('Y-m-d H:i:s');
}

/**
 * Determine current SLA status for a vendor row (Admin K2 SLA — 4 working days).
 *
 * When $conn is provided, countdown uses real working-hours arithmetic so the
 * label reflects business time, not calendar days (important over long weekends).
 * Passing null falls back to the original calendar-day display (safe for callers
 * that do not have $conn in scope).
 *
 * Returns: [
 *   'label' => '4 days left' | '3 days left' | '2 days left' | '1 day left' |
 *              'Xh left' | 'Overdue · Xh' | 'On Time' | 'Late' | '—',
 *   'class' => Bootstrap bg-* class string,
 *   'title' => tooltip string with exact deadline datetime,
 * ]
 */
function getSlaStatus(?string $slaDeadline, ?string $registeredAt, $conn = null): array {
    $tz = new DateTimeZone('Asia/Jakarta');

    if (!$slaDeadline) {
        return ['label' => '—', 'class' => 'bg-light text-muted border', 'title' => 'No SLA deadline set'];
    }

    $now      = new DateTime('now', $tz);
    $deadline = new DateTime($slaDeadline, $tz);
    $nowStr   = $now->format('Y-m-d H:i:s');
    $tooltip  = 'Deadline: ' . $deadline->format('d M Y') . ' (WIB)';

    // ── Completed: vendor_code was entered, k2_registered_at is set ──────────
    if ($registeredAt) {
        $done = new DateTime($registeredAt, $tz);
        if ($done <= $deadline) {
            return ['label' => 'On Time', 'class' => 'bg-success', 'title' => $tooltip];
        }
        return ['label' => 'Late', 'class' => 'bg-danger', 'title' => $tooltip];
    }

    // ── Still open: use working-hours countdown when $conn available ──────────
    if ($conn instanceof mysqli) {

        if ($deadline <= $now) {
            // Overdue — show how many working hours past the deadline
            $overHours = countWorkingHours($conn, $slaDeadline, $nowStr);
            $label = $overHours >= 1
                ? 'Overdue · ' . formatWorkingHours($overHours)
                : 'Overdue';
            return ['label' => $label, 'class' => 'bg-danger', 'title' => $tooltip];
        }

        $hoursLeft = countWorkingHours($conn, $nowStr, $slaDeadline);

        // Map working hours → working-day labels (8 working hours = 1 working day)
        if ($hoursLeft >= 24) { // ≥ 3 working days
            $wd = (int) ceil($hoursLeft / 8);
            return ['label' => "{$wd} days left", 'class' => 'bg-primary', 'title' => $tooltip];
        }
        if ($hoursLeft >= 16) { // 2 working days
            return ['label' => '2 days left', 'class' => 'bg-info text-dark', 'title' => $tooltip];
        }
        if ($hoursLeft >= 8) { // 1 working day
            return ['label' => '1 day left', 'class' => 'bg-warning text-dark', 'title' => $tooltip];
        }
        // < 1 working day — show exact hours
        $h = formatWorkingHours($hoursLeft);
        return ['label' => "{$h} left", 'class' => 'bg-warning text-dark', 'title' => $tooltip];

    }

    // ── Fallback: calendar-day display (no $conn) ────────────────────────────
    if ($deadline < $now) {
        $days = $now->diff($deadline)->days ?: 0;
        return ['label' => "Overdue {$days}d", 'class' => 'bg-danger', 'title' => $tooltip];
    }
    $diff = $now->diff($deadline);
    if ($diff->days === 0) return ['label' => 'Due today',    'class' => 'bg-warning text-dark', 'title' => $tooltip];
    if ($diff->days === 1) return ['label' => 'Due tomorrow', 'class' => 'bg-info text-dark',    'title' => $tooltip];
    return ['label' => "{$diff->days}d left", 'class' => 'bg-primary', 'title' => $tooltip];
}


// =============================================================
// PQ SLA: Sourcing → VM forward_head tracking (HOUR-BASED)
//
// Standard: 16 working hours (= 2 working days × 8h/day)
// Working window: 09:00–12:00 + 13:00–18:00 (Asia/Jakarta business hours)
// Lunch break 12:00–13:00 NOT counted.
// Weekends + holidays/joint leave (from `holidays` table) excluded.
// =============================================================

// Working day windows, in seconds-from-midnight
const PQ_SLA_MORNING_START   = 32400;  // 09:00:00
const PQ_SLA_MORNING_END     = 43200;  // 12:00:00
const PQ_SLA_AFTERNOON_START = 46800;  // 13:00:00
const PQ_SLA_AFTERNOON_END   = 64800;  // 18:00:00
const PQ_SLA_HOURS_PER_DAY   = 8.0;    // 3h morning + 5h afternoon
const PQ_SLA_TARGET_HOURS    = 16.0;   // 2 working days
const K2_SLA_HOURS           = 24.0;   // 4 working days

/**
 * Load holiday dates once per request (national holidays + joint leave).
 */
function _pqLoadHolidays($conn): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    $cached = [];
    $r = $conn->query("SELECT holiday_date FROM holidays");
    if ($r) while ($row = $r->fetch_assoc()) $cached[] = $row['holiday_date'];
    return $cached;
}

/**
 * Is the cursor inside a non-working period? Skip forward to next working second.
 * Returns true if cursor was moved, false if already inside a working window.
 */
function _pqAdvanceToWorkingSecond(DateTime &$cursor, array $holidays): bool {
    $dow     = (int)$cursor->format('w');
    $dateStr = $cursor->format('Y-m-d');

    // Weekend or holiday → jump to 09:00 next day
    if ($dow === 0 || $dow === 6 || in_array($dateStr, $holidays)) {
        $cursor->modify('+1 day');
        $cursor->setTime(9, 0, 0);
        return true;
    }

    $sec = (int)$cursor->format('H') * 3600 + (int)$cursor->format('i') * 60 + (int)$cursor->format('s');

    if ($sec < PQ_SLA_MORNING_START) {
        $cursor->setTime(9, 0, 0);
        return true;
    }
    if ($sec >= PQ_SLA_MORNING_END && $sec < PQ_SLA_AFTERNOON_START) {
        $cursor->setTime(13, 0, 0);
        return true;
    }
    if ($sec >= PQ_SLA_AFTERNOON_END) {
        $cursor->modify('+1 day');
        $cursor->setTime(9, 0, 0);
        return true;
    }
    return false;
}

/**
 * Compute the SLA deadline by adding N working hours to a start timestamp.
 *
 * @param mysqli $conn
 * @param string $startDate  'Y-m-d H:i:s'
 * @param float  $hours      target working hours (default 16 = 2 working days)
 * @return string 'Y-m-d H:i:s'
 */
function addWorkingHours($conn, string $startDate, float $hours = PQ_SLA_TARGET_HOURS): string {
    $holidays         = _pqLoadHolidays($conn);
    $cursor           = new DateTime($startDate);
    $remainingSeconds = (int)round($hours * 3600);

    // Jump cursor to next working second if currently outside the window
    while (_pqAdvanceToWorkingSecond($cursor, $holidays));

    while ($remainingSeconds > 0) {
        $sec = (int)$cursor->format('H') * 3600 + (int)$cursor->format('i') * 60 + (int)$cursor->format('s');

        // How many seconds left in the current window?
        $windowEnd = ($sec < PQ_SLA_MORNING_END) ? PQ_SLA_MORNING_END : PQ_SLA_AFTERNOON_END;
        $left      = $windowEnd - $sec;

        if ($remainingSeconds <= $left) {
            $cursor->modify("+{$remainingSeconds} seconds");
            $remainingSeconds = 0;
        } else {
            $remainingSeconds -= $left;
            // Jump cursor to end of window, then advance to next working second
            if ($sec < PQ_SLA_MORNING_END) $cursor->setTime(12, 0, 0);
            else                            $cursor->setTime(18, 0, 0);
            while (_pqAdvanceToWorkingSecond($cursor, $holidays));
        }
    }

    return $cursor->format('Y-m-d H:i:s');
}

/**
 * Count actual working hours between two timestamps.
 * Partial windows are summed precisely (to the second).
 *
 * @return float working hours (e.g. 12.5 = 12h30m)
 */
function countWorkingHours($conn, string $from, string $to): float {
    $holidays  = _pqLoadHolidays($conn);
    $start     = new DateTime($from);
    $end       = new DateTime($to);

    if ($end <= $start) return 0.0;

    $cursor       = clone $start;
    $totalSeconds = 0;

    while ($cursor < $end) {
        if (_pqAdvanceToWorkingSecond($cursor, $holidays)) continue;
        if ($cursor >= $end) break;

        $sec       = (int)$cursor->format('H') * 3600 + (int)$cursor->format('i') * 60 + (int)$cursor->format('s');
        $winEndSec = ($sec < PQ_SLA_MORNING_END) ? PQ_SLA_MORNING_END : PQ_SLA_AFTERNOON_END;

        $windowEnd = clone $cursor;
        $h = intdiv($winEndSec, 3600);
        $m = intdiv($winEndSec % 3600, 60);
        $windowEnd->setTime($h, $m, 0);

        // Take the lesser of $end vs $windowEnd
        $segmentEnd    = ($end < $windowEnd) ? $end : $windowEnd;
        $totalSeconds += $segmentEnd->getTimestamp() - $cursor->getTimestamp();
        $cursor        = clone $segmentEnd;
    }

    return round($totalSeconds / 3600.0, 2);
}

/**
 * Open a new SLA leg when sourcing fires approve_sourcing.
 */
function openPqSlaLeg($conn, int $submission_id, int $sourcing_reviewer_id): void {
    $r   = $conn->query("SELECT COALESCE(MAX(leg_number), 0) AS cnt FROM pq_sla_logs WHERE submission_id = $submission_id");
    $leg = (int)($r->fetch_assoc()['cnt'] ?? 0) + 1;

    $now      = date('Y-m-d H:i:s');
    $deadline = addWorkingHours($conn, $now, PQ_SLA_TARGET_HOURS);

    $stmt = $conn->prepare(
        "INSERT INTO pq_sla_logs
            (submission_id, leg_number, sourcing_submitted_at, sourcing_reviewer_id, sla_deadline)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("iisis", $submission_id, $leg, $now, $sourcing_reviewer_id, $deadline);
    $stmt->execute();

    // Update active deadline on pq_submissions (for dashboard)
    $stmt2 = $conn->prepare("UPDATE pq_submissions SET pq_sla_deadline = ? WHERE id = ?");
    $stmt2->bind_param("si", $deadline, $submission_id);
    $stmt2->execute();
}

/**
 * Close the current open leg. Called on 'forward_head' or 'returned_to_sourcing'.
 * On the first 'forward_head' ever, denormalizes the final result onto pq_submissions.
 */
function closePqSlaLeg($conn, int $submission_id, int $vm_reviewer_id, string $action_type): void {
    $r = $conn->query(
        "SELECT id, sourcing_submitted_at, sla_deadline
         FROM pq_sla_logs
         WHERE submission_id = $submission_id AND vm_action_at IS NULL
         ORDER BY id DESC LIMIT 1"
    );
    $leg = $r ? $r->fetch_assoc() : null;
    if (!$leg) return;

    $now            = date('Y-m-d H:i:s');
    $elapsed_hours  = countWorkingHours($conn, $leg['sourcing_submitted_at'], $now);
    $sla_met        = ($now <= $leg['sla_deadline']) ? 1 : 0;

    $stmt = $conn->prepare(
        "UPDATE pq_sla_logs
         SET vm_action_at          = ?,
             vm_action_type        = ?,
             vm_reviewer_id        = ?,
             working_hours_elapsed = ?,
             sla_met               = ?
         WHERE id = ?"
    );
    $stmt->bind_param("ssidii", $now, $action_type, $vm_reviewer_id, $elapsed_hours, $sla_met, $leg['id']);
    $stmt->execute();

    if ($action_type === 'forward_head') {
        // First forward_head only — stamp final result on pq_submissions
        $stmt2 = $conn->prepare(
            "UPDATE pq_submissions
             SET pq_sla_first_forward_at = IF(pq_sla_first_forward_at IS NULL, ?, pq_sla_first_forward_at),
                 pq_sla_final_hours      = IF(pq_sla_final_hours IS NULL,      ?, pq_sla_final_hours),
                 pq_sla_final_met        = IF(pq_sla_final_met IS NULL,        ?, pq_sla_final_met)
             WHERE id = ?"
        );
        $stmt2->bind_param("sdii", $now, $elapsed_hours, $sla_met, $submission_id);
        $stmt2->execute();
    }
}

/**
 * Format working hours as a compact label.
 * 0.5 → "30m", 1.5 → "1h30m", 16 → "16h" (= 2 working days)
 */
function formatWorkingHours(float $hours): string {
    if ($hours < 1)   return round($hours * 60) . 'm';
    $h = (int)floor($hours);
    $m = (int)round(($hours - $h) * 60);
    return $m > 0 ? "{$h}h{$m}m" : "{$h}h";
}

/**
 * Compute the dashboard badge for a submission, given its denormalized fields.
 * Cheap — uses only the row data + holidays cache, no extra queries.
 *
 * @param mysqli      $conn
 * @param string|null $pq_sla_deadline           Current active deadline
 * @param string|null $pq_sla_first_forward_at   Set once VM forwarded to Head
 * @param float|null  $pq_sla_final_hours        Final elapsed hours (if forwarded)
 * @param int|null    $pq_sla_final_met          1=on time, 0=breached
 * @return array{label:string,class:string,title:string}
 */
function getPqSlaBadge($conn, ?string $pq_sla_deadline, ?string $pq_sla_first_forward_at,
                       ?float $pq_sla_final_hours = null, ?int $pq_sla_final_met = null): array {

    // CASE 1: VM already forwarded to Head → final result
    if ($pq_sla_first_forward_at) {
        $h = formatWorkingHours((float)($pq_sla_final_hours ?? 0));
        if ($pq_sla_final_met) {
            return ['label' => "✓ On Time · {$h}", 'class' => 'bg-success',
                    'title' => "Forwarded within 16-hour SLA ({$h} working hours)"];
        }
        return ['label' => "✗ Breached · {$h}", 'class' => 'bg-danger',
                'title' => "Exceeded 16-hour SLA (took {$h} working hours)"];
    }

    // CASE 2: Nothing in flight
    if (!$pq_sla_deadline) {
        return ['label' => '—', 'class' => 'bg-light text-muted border',
                'title' => 'Not in VM review queue'];
    }

    // CASE 3: Open leg — show countdown
    $now      = date('Y-m-d H:i:s');
    $deadline = $pq_sla_deadline;

    if ($now > $deadline) {
        $over = countWorkingHours($conn, $deadline, $now);
        $h    = formatWorkingHours($over);
        return ['label' => "Overdue · {$h}", 'class' => 'bg-danger',
                'title' => "Past SLA deadline ({$deadline})"];
    }

    $left = countWorkingHours($conn, $now, $deadline);
    $h    = formatWorkingHours($left);

    if ($left < 1)  return ['label' => "Due in {$h}", 'class' => 'bg-warning text-dark',
                            'title' => "Deadline: {$deadline}"];
    if ($left < 4)  return ['label' => "Due today · {$h}", 'class' => 'bg-warning text-dark',
                            'title' => "Deadline: {$deadline}"];
    if ($left < 8)  return ['label' => "{$h} left", 'class' => 'bg-info text-dark',
                            'title' => "Deadline: {$deadline}"];
    return                 ['label' => "{$h} left", 'class' => 'bg-primary',
                            'title' => "Deadline: {$deadline}"];
}
