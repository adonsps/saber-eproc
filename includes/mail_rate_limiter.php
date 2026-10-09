<?php
// includes/mail_rate_limiter.php
//
// Backend guardrail for the shared outbound SMTP mailbox (see includes/mail_config.php;
// now sigit.ramadan@kawanlamacorp.com on Google Workspace). The previous mailbox,
// corporateprocurement@vssportal.online, was flagged as spam by Hostinger (system-originated SMTP
// sends blocked, manual webmail sends unaffected) because at least 9 independent
// call sites across this codebase send straight to SMTP with no shared notion of
// how much mail already went out. This file is the one choke point every sender
// should route through instead of calling PHPMailer::send() directly.
//
// State is a small flock()-guarded append log under storage/ (already gitignored,
// already writable) rather than a DB table: the root app and the workspace/ app
// use separate DB connections, but every PHP file here is served from the same
// filesystem, so a file is the simplest thing both sides can share for free.

if (!defined('MAIL_RATE_LIMIT_LOG_PATH')) {
    define('MAIL_RATE_LIMIT_LOG_PATH', dirname(__DIR__) . '/storage/mail_rate_limit.log');
}

// Hostinger's quota counts per recipient slot (To + Cc + Bcc), not per email —
// tools/PREPARE_MANUAL_INVITE.md documents ~10 invites (~10 slots each) exhausting
// an hourly limit, i.e. roughly 100 slots/hour before a block. Both ceilings below
// sit well under that, and are overridable without a code change once real
// headroom against the current account is known.
if (!defined('MAIL_RATE_LIMIT_BURST_WINDOW_SEC')) {
    define('MAIL_RATE_LIMIT_BURST_WINDOW_SEC', 300); // 5 minutes
}
if (!defined('MAIL_RATE_LIMIT_BURST_MAX_SLOTS')) {
    define('MAIL_RATE_LIMIT_BURST_MAX_SLOTS', (int) (getenv('MAIL_RATE_LIMIT_BURST') ?: 15));
}
if (!defined('MAIL_RATE_LIMIT_HOURLY_WINDOW_SEC')) {
    define('MAIL_RATE_LIMIT_HOURLY_WINDOW_SEC', 3600); // 1 hour
}
if (!defined('MAIL_RATE_LIMIT_HOURLY_MAX_SLOTS')) {
    define('MAIL_RATE_LIMIT_HOURLY_MAX_SLOTS', (int) (getenv('MAIL_RATE_LIMIT_HOURLY') ?: 60));
}

// After the SMTP server refuses a message, no flow sends anything for this long.
if (!defined('MAIL_COOLDOWN_PATH')) {
    define('MAIL_COOLDOWN_PATH', dirname(__DIR__) . '/storage/mail_cooldown.txt');
}
if (!defined('MAIL_COOLDOWN_SEC')) {
    define('MAIL_COOLDOWN_SEC', (int) (getenv('MAIL_COOLDOWN_SEC') ?: 900)); // 15 minutes
}

/**
 * Why the most recent mail_rate_limited_send() in this request did not go out.
 * Call with arguments to record, with none to read.
 *
 * @return array{type:?string,retry_after:int,message:string}
 *         type: null (no failure), 'rate_limited' or 'cooldown' (our own pause — safe
 *         to wait retry_after seconds and retry), 'recipient' (one bad address — skip
 *         it, mailbox is fine), or 'smtp' (the server refused/errored — stop, do not
 *         keep hammering).
 */
function mail_send_status(?string $type = null, int $retryAfter = 0, string $message = ''): array
{
    static $state = ['type' => null, 'retry_after' => 0, 'message' => ''];
    if (func_num_args() > 0) {
        $state = ['type' => $type, 'retry_after' => $retryAfter, 'message' => $message];
    }
    return $state;
}

/**
 * Decide whether a send consuming $recipientSlots is allowed right now, and if
 * so, record it. Returns false (and records nothing) if either the burst or the
 * hourly window would be exceeded. Never throws — a filesystem problem here
 * should not be able to take down a caller's whole request; it fails open with
 * an error_log so it's visible without blocking mail entirely.
 */
function mail_rate_limiter_allow(int $recipientSlots, string $flow): bool
{
    $recipientSlots = max(1, $recipientSlots);
    $path = MAIL_RATE_LIMIT_LOG_PATH;
    $dir = dirname($path);

    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        error_log('[mail-rate-limit] cannot create storage dir ' . $dir . '; failing open for flow=' . $flow);
        return true;
    }

    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        error_log('[mail-rate-limit] cannot open ' . $path . '; failing open for flow=' . $flow);
        return true;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            error_log('[mail-rate-limit] cannot lock ' . $path . '; failing open for flow=' . $flow);
            return true;
        }

        $now = time();
        $oldestWindow = max(MAIL_RATE_LIMIT_BURST_WINDOW_SEC, MAIL_RATE_LIMIT_HOURLY_WINDOW_SEC);
        $cutoff = $now - $oldestWindow;

        $entries = [];
        rewind($handle);
        while (($line = fgets($handle)) !== false) {
            $row = json_decode(trim($line), true);
            if (!is_array($row) || !isset($row['ts'], $row['slots'])) {
                continue;
            }
            if ((int) $row['ts'] >= $cutoff) {
                $entries[] = $row;
            }
        }

        $burstCutoff = $now - MAIL_RATE_LIMIT_BURST_WINDOW_SEC;
        $hourlyCutoff = $now - MAIL_RATE_LIMIT_HOURLY_WINDOW_SEC;
        $burstSlots = 0;
        $hourlySlots = 0;
        foreach ($entries as $row) {
            if ((int) $row['ts'] >= $burstCutoff) {
                $burstSlots += (int) $row['slots'];
            }
            if ((int) $row['ts'] >= $hourlyCutoff) {
                $hourlySlots += (int) $row['slots'];
            }
        }

        $allowed = ($burstSlots + $recipientSlots <= MAIL_RATE_LIMIT_BURST_MAX_SLOTS)
            && ($hourlySlots + $recipientSlots <= MAIL_RATE_LIMIT_HOURLY_MAX_SLOTS);

        if ($allowed) {
            $entries[] = ['ts' => $now, 'slots' => $recipientSlots, 'flow' => $flow];
        } else {
            // Seconds until enough old entries age out of whichever window is full,
            // so a caller can wait and retry instead of treating this as a hard failure.
            $waitFor = static function (int $window, int $max, int $used) use ($entries, $now, $recipientSlots): int {
                $excess = $used + $recipientSlots - $max;
                if ($excess <= 0) {
                    return 0;
                }
                $inWindow = array_filter($entries, static fn($r) => (int) $r['ts'] >= $now - $window);
                usort($inWindow, static fn($a, $b) => (int) $a['ts'] <=> (int) $b['ts']);
                $freed = 0;
                foreach ($inWindow as $r) {
                    $freed += (int) $r['slots'];
                    if ($freed >= $excess) {
                        return max(1, (int) $r['ts'] + $window - $now + 1);
                    }
                }
                return $window; // a single send larger than the cap: never fits
            };
            mail_send_status('rate_limited', max(
                $waitFor(MAIL_RATE_LIMIT_BURST_WINDOW_SEC, MAIL_RATE_LIMIT_BURST_MAX_SLOTS, $burstSlots),
                $waitFor(MAIL_RATE_LIMIT_HOURLY_WINDOW_SEC, MAIL_RATE_LIMIT_HOURLY_MAX_SLOTS, $hourlySlots)
            ));
            error_log(sprintf(
                '[mail-rate-limit] blocked flow=%s slots=%d (burst %d/%d in %ds, hourly %d/%d in %ds)',
                $flow,
                $recipientSlots,
                $burstSlots,
                MAIL_RATE_LIMIT_BURST_MAX_SLOTS,
                MAIL_RATE_LIMIT_BURST_WINDOW_SEC,
                $hourlySlots,
                MAIL_RATE_LIMIT_HOURLY_MAX_SLOTS,
                MAIL_RATE_LIMIT_HOURLY_WINDOW_SEC
            ));
        }

        ftruncate($handle, 0);
        rewind($handle);
        foreach ($entries as $row) {
            fwrite($handle, json_encode($row) . "\n");
        }
        fflush($handle);

        return $allowed;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Drop-in replacement for $mail->send() — every sender in this codebase should
 * call this instead of calling send() on its PHPMailer instance directly.
 * $flow is a short label (e.g. 'registration', 'rfq-notification',
 * 'vendor-eval-invite') used only for the error_log line above, to tell which
 * caller is consuming the budget.
 */
function mail_rate_limited_send(\PHPMailer\PHPMailer\PHPMailer $mail, string $flow): bool
{
    $slots = count($mail->getToAddresses())
        + count($mail->getCcAddresses())
        + count($mail->getBccAddresses());

    mail_send_status(null); // clear any previous failure

    // After the server refused a message, stay quiet for a while. Without this every
    // caller keeps knocking on a restricted mailbox (and each failed verification
    // mail even triggers a second "failure alert" mail), which only prolongs the block.
    $cooldown = mail_cooldown_remaining();
    if ($cooldown > 0) {
        mail_send_status('cooldown', $cooldown, 'Pengiriman dijeda sementara setelah email ditolak server.');
        error_log('[mail-rate-limit] cooling down ' . $cooldown . 's, skipped flow=' . $flow);
        return false;
    }

    if (!mail_rate_limiter_allow($slots, $flow)) {
        return false; // mail_send_status() now says 'rate_limited' + retry_after
    }

    try {
        $ok = $mail->send();
        if (!$ok) {
            mail_note_smtp_failure((string) $mail->ErrorInfo, $mail, $flow);
        }
        return $ok;
    } catch (\Exception $e) {
        mail_note_smtp_failure($e->getMessage(), $mail, $flow);
        throw $e;
    }
}

/** Seconds of cooldown left (0 = free to send). Fails open on any file problem. */
function mail_cooldown_remaining(): int
{
    $raw = @file_get_contents(MAIL_COOLDOWN_PATH);
    $until = $raw === false ? 0 : (int) trim($raw);
    return max(0, $until - time());
}

/**
 * Record a failed send. A bad single recipient ("recipients failed") says nothing
 * about the mailbox and must not pause everyone else; anything else (data not
 * accepted, auth, connection, quota) is treated as the mailbox being unhappy and
 * starts the cooldown. The server's own reply line is logged — "data not accepted"
 * alone never says WHY Hostinger refused.
 */
function mail_note_smtp_failure(string $message, \PHPMailer\PHPMailer\PHPMailer $mail, string $flow): void
{
    $reply = '';
    try {
        $smtp = $mail->getSMTPInstance();
        $reply = trim((string) $smtp->getLastReply());
    } catch (\Throwable $t) {
        // no SMTP instance — nothing more to report
    }

    if (stripos($message, 'recipients failed') !== false) {
        mail_send_status('recipient', 0, $message);
        error_log('[mail-rate-limit] recipient refused flow=' . $flow . ': ' . $message);
        return;
    }

    mail_send_status('smtp', 0, $message . ($reply !== '' ? ' | ' . $reply : ''));
    error_log('[mail-rate-limit] SMTP refused flow=' . $flow . ': ' . $message
        . ($reply !== '' ? ' | server reply: ' . $reply : '')
        . ' | cooldown ' . MAIL_COOLDOWN_SEC . 's');

    $dir = dirname(MAIL_COOLDOWN_PATH);
    if (is_dir($dir) || @mkdir($dir, 0775, true)) {
        @file_put_contents(MAIL_COOLDOWN_PATH, (string) (time() + MAIL_COOLDOWN_SEC), LOCK_EX);
    }
}
