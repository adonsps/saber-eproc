<?php
// includes/aon_reminder.php
//
// The AON reminder schedule: the single place that decides which AON contracts
// are in trouble, and therefore both what the STC pop-up shows
// (includes/aon_stock_alert.php) and what goes to the STC WhatsApp group. One
// definition, so the pop-up and the WA message can never disagree.
//
// TWO INDEPENDENT REMINDERS, each with its own trigger, its own cadence and its
// own section of the message. They were one reminder until Aug 2026; splitting
// them is the point of that change, because "this contract runs out of stock"
// and "this contract runs out of time" are different problems with different
// answers, and an STC reading one digest could not tell which was which.
//
//   QUANTITY   remaining qty is at or below AON_LOW_REMAIN_RATIO (30%) of the
//              contract qty -- aon_remain_is_low(), includes/aon_helper.php
//              Cadence: DAILY, for as long as it stays low. Independent of the
//              expiry calendar: a contract can burn through its quantity in its
//              first month and must be chased then, not six months later.
//              Message carries the PIC's name and how many SKUs are on them.
//
//   EXPIRY     valid_until is approaching. Three stages:
//                T2M     two months out          -- ONCE
//                T1M     one month out           -- ONCE
//                WEEKLY  through the final month -- every Monday, 14:00 WIB
//              Message carries, per contract: contract no, komoditas, vendor,
//              PIC and the expiry date.
//
// Calendar months, not 60/30 days: contracts are written in months, and
// "1 month before 31 March" is 28 February, not "31 days before".
//
// THE MONDAY RULE. The weekly stage fires on the Monday of each week, and if
// that Monday is on the company holiday calendar (company_holidays, the same one
// the SLA countdown uses) it moves forward to the next working day -- Tuesday,
// then Wednesday, and so on. It will not fire before 14:00 Asia/Jakarta on that
// day, so an AON import landing at 09:00 cannot bring the week's message
// forward. That in turn means THE DAILY CRON HAS TO BE REGISTERED AT OR AFTER
// 14:00 WIB, or the weekly stage never gets a chance to run; see
// workspace/cron/aon_reminder_cron.php.
//
// WHEN IT RUNS. The quantity half runs on the data: every AON import (the manual
// upload and the automated K2 queue alike) re-runs this check against what it
// just wrote, so a SKU that only became low because of that import is reminded
// about within seconds rather than at the next cron tick. The import runs
// several times a day; the per-day flag below is what keeps that from meaning
// several messages a day about the same SKU. The expiry half is mostly on the
// clock, because a date does not change when a spreadsheet is uploaded -- the
// one-shot stages fire from whatever trigger first notices them, the weekly one
// only in its own slot.
//
// THE PER-DAY FLAG. aon_reminder_log carries one row per (SKU, stage, key) that
// has been dealt with, and its was_sent column is the 1/0 flag: 1 = this SKU
// really was messaged about for this reason, 0 = the stage was settled without a
// message (see aon_reminder_record). The daily stage keys on the date itself and
// the weekly stage on its send date, so the second and third import of the same
// day find a row already there and stay quiet, while a SKU that has only just
// gone low has no row and is sent. The UNIQUE key on that table is what makes
// this safe against two imports landing at once -- the database, not the code,
// is the thing that cannot race.
//
// Stage windows are decided by the DATE ALONE (aon_reminder_stage_for), which is
// what the pop-up renders. What has already gone out is the separate question
// answered by the log (aon_reminder_due_rows). Keeping those apart is
// deliberate: the pop-up must keep showing a contract every day it is in
// trouble, while WhatsApp must not.
//
// A SKU the STC has taken out of the reminders -- "On Progress" (being
// renegotiated) or "Not Renewal" (with a mandatory reason) -- drops out of both.

require_once __DIR__ . '/aon_helper.php';
require_once __DIR__ . '/sla_rules.php';   // sla_holidays() -- the company holiday calendar

const AON_REMINDER_STAGE_T2M    = 'T2M';
const AON_REMINDER_STAGE_T1M    = 'T1M';
const AON_REMINDER_STAGE_WEEKLY = 'WEEKLY';
const AON_REMINDER_STAGE_LOWQTY = 'LOWQTY';

/**
 * Day of the week the final-month reminder goes out, ISO-8601 (1 = Monday), and
 * the hour it may not go out before, on the Asia/Jakarta clock.
 */
const AON_REMINDER_WEEKLY_DOW  = 1;
const AON_REMINDER_WEEKLY_HOUR = 14;

/**
 * The one timezone every date in this file is reckoned in.
 *
 * Not the ambient PHP timezone: config/database.php leaves the web SAPI on UTC,
 * which is seven hours behind the people reading the message. A reminder that
 * says "expires today" has to agree with the reader's calendar, and between
 * 00:00 and 07:00 WIB a UTC date is still yesterday's.
 */
const AON_REMINDER_TZ = 'Asia/Jakarta';

/** Now, in Jakarta, regardless of what date_default_timezone_set() says. */
function aon_reminder_now() {
    return new DateTime('now', new DateTimeZone(AON_REMINDER_TZ));
}

/** Today's date in Jakarta, 'Y-m-d'. The default for every $today below. */
function aon_reminder_today() {
    return aon_reminder_now()->format('Y-m-d');
}

/**
 * Stage metadata: display label, short WA heading, and the badge colours the
 * pop-up and the Master AON table use. Ordered least urgent first.
 *
 * 'months' is the size of the expiry window in calendar months, and is absent
 * for LOWQTY, which is not a date window at all.
 * 'cadence' is how often the stage repeats: once | daily | weekly.
 */
function aon_reminder_stages() {
    return [
        AON_REMINDER_STAGE_T2M => [
            'months'  => 2,
            'cadence' => 'once',
            'label'   => '2 months to expiry',
            'short'   => '2-month notice',
            'heading' => '2 MONTHS TO EXPIRY',
            'class'   => 'bg-amber-100 text-amber-800 border-amber-300',
        ],
        AON_REMINDER_STAGE_T1M => [
            'months'  => 1,
            'cadence' => 'once',
            'label'   => 'final month — reminded every Monday',
            'short'   => '1-month notice',
            'heading' => '1 MONTH TO EXPIRY',
            'class'   => 'bg-red-100 text-red-800 border-red-300',
        ],
        // Shares T1M's window on purpose: it is not a separate warning, it is the
        // repeat of the same one. Never surfaces as a badge -- the pop-up shows
        // the contract as T1M, and this only decides how often WhatsApp repeats.
        AON_REMINDER_STAGE_WEEKLY => [
            'months'  => 1,
            'cadence' => 'weekly',
            'label'   => 'final month — weekly reminder',
            'short'   => 'weekly notice',
            'heading' => 'FINAL MONTH',
            'class'   => 'bg-red-100 text-red-800 border-red-300',
        ],
        AON_REMINDER_STAGE_LOWQTY => [
            'cadence' => 'daily',
            'label'   => 'down to the last ' . (int)round(AON_LOW_REMAIN_RATIO * 100) . '% of contract quantity',
            'short'   => 'low quantity',
            'heading' => 'QUANTITY RUNNING OUT',
            'class'   => 'bg-red-100 text-red-800 border-red-300',
        ],
    ];
}

/**
 * The date a contract ending on $valid_until enters the given stage's window.
 * Returns 'Y-m-d', or null when the date cannot be read or the stage is not a
 * date window at all (LOWQTY).
 */
function aon_reminder_window_opens($valid_until, $stage) {
    $until = substr((string)$valid_until, 0, 10);
    $ts    = strtotime($until);
    if ($ts === false || $until === '' || $until === '0000-00-00') return null;

    $months = aon_reminder_stages()[$stage]['months'] ?? null;
    if ($months === null) return null;

    // Anchored to the first of the month before subtracting, then the day put
    // back: PHP's "-1 month" on 31 March gives 3 March, which would open the
    // window three days late. Clamping to the month's last day instead means a
    // contract ending on the 31st is warned about from the 28th/30th.
    $d = new DateTime($until);
    $day = (int)$d->format('j');
    $d->modify('first day of this month')->modify('-' . $months . ' month');
    $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($day, (int)$d->format('t')));
    return $d->format('Y-m-d');
}

/**
 * Which expiry window is this contract in TODAY, purely by date.
 *
 * Returns null when the contract has no end date, has already expired, or is
 * still further out than two months. Otherwise:
 *   stage      T2M | T1M   -- T1M once inside the final month
 *   label / short / heading / class   from aon_reminder_stages()
 *   days_left  whole calendar days from today to valid_until (0 = expires today)
 *
 * Never returns WEEKLY: that is a cadence inside the T1M window, not a window of
 * its own, and nothing on screen should say "weekly" where the contract's actual
 * status belongs.
 *
 * Says nothing about quantity -- that is aon_remain_is_low()'s job, and the two
 * conditions are independent.
 */
function aon_reminder_stage_for($valid_until, $today = null) {
    $today = $today ?: aon_reminder_today();
    $until = substr((string)$valid_until, 0, 10);
    if ($until === '' || $until === '0000-00-00' || strtotime($until) === false) return null;
    if ($until < $today) return null;                      // already lapsed

    $stage = null;
    if (($d = aon_reminder_window_opens($until, AON_REMINDER_STAGE_T1M)) !== null && $today >= $d) {
        $stage = AON_REMINDER_STAGE_T1M;
    } elseif (($d = aon_reminder_window_opens($until, AON_REMINDER_STAGE_T2M)) !== null && $today >= $d) {
        $stage = AON_REMINDER_STAGE_T2M;
    }
    if ($stage === null) return null;

    $meta = aon_reminder_stages()[$stage];
    $meta['stage']     = $stage;
    $meta['days_left'] = (int)floor((strtotime($until) - strtotime($today)) / 86400);
    return $meta;
}

// ── The Monday slot ─────────────────────────────────────────────────────────

/**
 * The date the weekly final-month reminder goes out in $today's week.
 *
 * Monday, unless Monday is a company holiday -- then the next working day, which
 * is what "kalau seninnya holiday, dikirim ke besoknya" asks for. Weekends are
 * skipped too, so a week whose Monday to Friday are all holidays produces no
 * usable day and falls back to the Monday itself rather than spilling into the
 * next week's slot.
 *
 * Uses "monday this week", which is ISO: for a Sunday that is the Monday six
 * days back, not tomorrow. A Sunday therefore belongs to the week that has
 * already had its send, which is the intended reading -- the alternative would
 * give one week two slots.
 *
 * @return string 'Y-m-d'
 */
function aon_reminder_weekly_send_date(PDO $pdo, $today = null) {
    $today = $today ?: aon_reminder_today();

    $monday = new DateTime($today, new DateTimeZone(AON_REMINDER_TZ));
    $monday->modify('monday this week');
    if (AON_REMINDER_WEEKLY_DOW > 1) {
        $monday->modify('+' . (AON_REMINDER_WEEKLY_DOW - 1) . ' day');
    }
    $fallback = $monday->format('Y-m-d');

    $holidays = [];
    foreach (sla_holidays($pdo) as $h) $holidays[$h] = true;

    $d = clone $monday;
    for ($i = 0; $i < 7; $i++) {
        $s = $d->format('Y-m-d');
        if ((int)$d->format('N') <= 5 && !isset($holidays[$s])) return $s;
        $d->modify('+1 day');
    }
    return $fallback;
}

/**
 * Is the weekly slot open right now?
 *
 * Two conditions, both required: today IS this week's send date, and the Jakarta
 * clock has passed 14:00. The hour is what stops an AON import at nine in the
 * morning from firing the week's message early -- the imports run several times
 * a day and would otherwise decide the schedule between them.
 *
 * @param bool $ignore_clock preview only: answer as if it were already 14:00, so
 *                           a dry run on the right day can show the message.
 */
function aon_reminder_weekly_is_open(PDO $pdo, $today = null, $ignore_clock = false) {
    $today = $today ?: aon_reminder_today();
    if ($today !== aon_reminder_weekly_send_date($pdo, $today)) return false;
    if ($ignore_clock) return true;
    return (int)aon_reminder_now()->format('G') >= AON_REMINDER_WEEKLY_HOUR;
}

// ── Opt-outs ────────────────────────────────────────────────────────────────

/** The contract is being renegotiated/extended right now. */
const AON_REMINDER_OPTOUT_ON_PROGRESS = 'ON_PROGRESS';
/** The contract is being dropped. Requires a reason. */
const AON_REMINDER_OPTOUT_NOT_RENEWAL = 'NOT_RENEWAL';

/**
 * The two answers an STC can give the pop-up, with the wording and colours each
 * one uses. Both stop the reminders; they differ in what they mean and in
 * whether a reason has to be given.
 */
function aon_reminder_optout_kinds() {
    return [
        AON_REMINDER_OPTOUT_ON_PROGRESS => [
            'label'           => 'On Progress',
            'title'           => 'Renewal already in progress?',
            'blurb'           => 'This contract is being renegotiated or extended, so it does not need chasing.',
            'remarks_required'=> false,
            'class'           => 'bg-indigo-100 text-indigo-800 border-indigo-300',
        ],
        AON_REMINDER_OPTOUT_NOT_RENEWAL => [
            'label'           => 'Not Renewal',
            'title'           => 'This contract is not being renewed?',
            'blurb'           => 'The contract is being dropped. Please say why — this is the only record of that decision.',
            'remarks_required'=> true,
            'class'           => 'bg-red-100 text-red-800 border-red-300',
        ],
    ];
}

/**
 * Normalize a status coming off the wire to one of the two kinds above, or null
 * when it is neither. Legacy rows written before the two-button pop-up carry no
 * status at all, which is why null stays a valid value.
 */
function aon_reminder_optout_status($value) {
    $value = strtoupper(trim((string)$value));
    return isset(aon_reminder_optout_kinds()[$value]) ? $value : null;
}

/** Display label for a stored status; falls back for the legacy no-status rows. */
function aon_reminder_optout_label($status) {
    $status = aon_reminder_optout_status($status);
    return $status === null ? 'Reminders off' : aon_reminder_optout_kinds()[$status]['label'];
}

/**
 * Active opt-outs keyed by upper-cased SKU code, memoized per request.
 * These SKUs are excluded from the pop-up and from every WA reminder.
 */
function aon_reminder_optouts(PDO $pdo, $force = false) {
    static $cache = null;
    if ($cache !== null && !$force) return $cache;

    $out = [];
    try {
        // status is selected defensively: an environment that has not run the
        // auto-migrate yet still has the rest of the table, and losing the label
        // is a far better failure than losing every opt-out.
        try {
            $rows = $pdo->query(
                "SELECT sku_code, status, remarks, ignored_by, ignored_at
                 FROM aon_reminder_optouts WHERE is_active = 1"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $inner) {
            $rows = $pdo->query(
                "SELECT sku_code, remarks, ignored_by, ignored_at
                 FROM aon_reminder_optouts WHERE is_active = 1"
            )->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($rows as $r) {
            $r['status'] = aon_reminder_optout_status($r['status'] ?? null);
            $out[strtoupper(trim((string)$r['sku_code']))] = $r;
        }
    } catch (PDOException $e) {
        // Table not deployed yet -- nothing is opted out, so everything is
        // reminded. Failing this way round is the safe one: a missing table must
        // not silence a contract that is about to lapse.
        error_log('aon_reminder_optouts: ' . $e->getMessage());
    }
    return $cache = $out;
}

/**
 * Record (or lift) an STC's "stop reminding me about this SKU" decision.
 *
 * Remarks are mandatory for Not Renewal -- a contract being dropped has to carry
 * the reason it was dropped, because nothing else in the system records it. On
 * Progress needs no remark: the contract is still being worked on, and the
 * decision reverses itself the moment the new dates are imported.
 *
 * @param string|null $status one of aon_reminder_optout_kinds(), or null (legacy)
 * @return array{ok:bool,error:?string}
 */
function aon_reminder_set_optout(PDO $pdo, $sku_code, $active, $remarks, $actor, $status = null) {
    $sku_code = trim((string)$sku_code);
    $remarks  = trim((string)$remarks);
    $active   = $active ? 1 : 0;
    $status   = aon_reminder_optout_status($status);

    if ($sku_code === '') return ['ok' => false, 'error' => 'Missing SKU code.'];

    // Unknown/absent status is treated as the stricter of the two, so a caller
    // that predates the two-button pop-up cannot drop a contract without a reason.
    $needs_remarks = $status === null
        || !empty(aon_reminder_optout_kinds()[$status]['remarks_required']);
    if ($active && $needs_remarks && $remarks === '') {
        return ['ok' => false, 'error' => 'Please write why this contract is not being renewed.'];
    }

    try {
        // ignored_by / ignored_at record who took it OUT of the reminders and
        // when; lifting the opt-out leaves that history in place rather than
        // blanking it, and updated_by carries whoever lifted it.
        $stmt = $pdo->prepare(
            "INSERT INTO aon_reminder_optouts (sku_code, is_active, status, remarks, ignored_by, ignored_at, updated_by)
             VALUES (?, ?, ?, ?, ?, IF(? = 1, NOW(), NULL), ?)
             ON DUPLICATE KEY UPDATE
                is_active  = VALUES(is_active),
                status     = VALUES(status),
                remarks    = VALUES(remarks),
                ignored_by = IF(VALUES(is_active) = 1, VALUES(ignored_by), ignored_by),
                ignored_at = IF(VALUES(is_active) = 1, NOW(), ignored_at),
                updated_by = VALUES(updated_by)"
        );
        $stmt->execute([$sku_code, $active, $status, ($remarks !== '' ? $remarks : null), $actor, $active, $actor]);
        aon_reminder_optouts($pdo, true);   // drop the memo so this request sees it
        return ['ok' => true, 'error' => null];
    } catch (PDOException $e) {
        error_log('aon_reminder_set_optout: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save. Please try again.'];
    }
}

// ── What is owed to the group right now ─────────────────────────────────────

/**
 * The dedup key for one reason on one day.
 *
 *   daily   the date itself   -- "has this SKU been sent for this reason TODAY"
 *   weekly  the week's send date -- "…this WEEK", and a slot that slid off a
 *           holiday Monday keeps its own identity rather than colliding with the
 *           Monday that was skipped
 *   once    ''                -- "…EVER"
 */
function aon_reminder_stage_key($stage, $today, $weekly_send_date = null) {
    switch (aon_reminder_stages()[$stage]['cadence'] ?? 'once') {
        case 'daily':  return (string)$today;
        case 'weekly': return (string)($weekly_send_date !== null ? $weekly_send_date : $today);
        default:       return '';
    }
}

/**
 * Every reminder that is due right now and has not gone out yet.
 *
 * A SKU is due when at least one of its reasons -- expiring, low quantity -- is
 * unsettled in the log. A SKU that is both low AND expiring produces two log
 * rows and appears in BOTH sections of the message: the log counts reasons,
 * because it is the reasons that each have their own cadence, and the message is
 * split by reason so the reader can tell the two problems apart.
 *
 * Excludes: expired and not-yet-started contracts, contracts further out than
 * two months that are not also low on quantity, SKUs the STC has opted out of,
 * soft-deleted / unallocated rows -- the same "is a real AON row" filter
 * Master AON itself applies -- and SKUs with no contract_no yet (see
 * $hasContractNoCol below: only when the column actually exists, so an
 * environment missing the migration falls back to including everything rather
 * than excluding it all).
 *
 * Unclaimed SKUs ARE included. The pop-up is scoped to the STC who claimed a
 * SKU, which leaves a contract nobody has claimed warning nobody; a group
 * message is exactly the right place to close that gap.
 *
 * @param bool $ignore_log true = report everything currently in trouble
 *                         regardless of what has already been sent today. Only
 *                         for previews; sending on this would repeat itself.
 * @return array<int,array> aon_skus rows plus alert_low / alert_expiring (is it
 *                          in trouble) and due_low / due_expiring (is that the
 *                          reason it is in THIS message), plus stage /
 *                          days_left / reasons / superseded
 */
function aon_reminder_due_rows(PDO $pdo, $today = null, $ignore_log = false) {
    $today = $today ?: aon_reminder_today();

    // The same validity filter the pop-up applies: an expired contract is a
    // different (and later) conversation than one about to expire, and one that
    // has not started yet cannot be running out.
    $where = "FROM aon_skus
             WHERE is_deleted = 0
               AND alokasi IS NOT NULL AND alokasi != ''
               AND (valid_from  IS NULL OR valid_from  <= ?)
               AND (valid_until IS NULL OR valid_until >= ?)
             ORDER BY valid_until ASC, sku_code ASC";
    $cols = "id, sku_code, item_name, vendor, category, handler, quantity, used_qty, remain_qty,
             valid_from, valid_until";

    // contract_no is selected defensively, exactly as the opt-out status is: an
    // environment whose auto-migrate has not landed yet still has every other
    // column. Whether the column itself came back decides how the missing-value
    // filter below behaves -- see $hasContractNoCol.
    $hasContractNoCol = true;
    try {
        try {
            $stmt = $pdo->prepare("SELECT {$cols}, contract_no {$where}");
            $stmt->execute([$today, $today]);
        } catch (PDOException $inner) {
            $hasContractNoCol = false;
            $stmt = $pdo->prepare("SELECT {$cols} {$where}");
            $stmt->execute([$today, $today]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('aon_reminder_due_rows: ' . $e->getMessage());
        return [];
    }

    $optouts = aon_reminder_optouts($pdo);
    $sent    = $ignore_log ? [] : aon_reminder_log_index($pdo);
    $due     = [];

    // Resolved once for the whole run: the answer is the same for every row, and
    // it costs a holiday-table read. On a preview the clock is ignored, so a dry
    // run on the right Monday can show what the 14:00 send will say.
    $weeklySend = aon_reminder_weekly_send_date($pdo, $today);
    $weeklyOpen = aon_reminder_weekly_is_open($pdo, $today, $ignore_log);

    foreach ($rows as $r) {
        $code = strtoupper(trim((string)$r['sku_code']));
        if (isset($optouts[$code])) continue;

        // Only chase SKUs that already have a contract number. One with no
        // contract_no yet is still being set up, not a contract to renew or
        // restock -- reminding an STC about it points them at nothing to act on.
        // Gated on $hasContractNoCol, not just an empty value: an environment
        // where the column itself is missing can't tell "no contract" from "not
        // asked", so it falls back to the old behaviour instead of silently
        // excluding every SKU.
        if ($hasContractNoCol && trim((string)($r['contract_no'] ?? '')) === '') continue;

        $stage  = aon_reminder_stage_for($r['valid_until'], $today);
        $remain = aon_effective_remain_qty($r['quantity'] ?? null, $r['used_qty'] ?? null, $r['remain_qty'] ?? null);
        $low    = aon_remain_is_low($r['quantity'] ?? null, $remain);
        if ($stage === null && !$low) continue;

        $log          = $sent[$code] ?? [];
        $reasons      = [];
        $superseded   = [];
        $due_expiring = false;
        $due_low      = false;

        if ($stage !== null) {
            $s   = $stage['stage'];
            $key = aon_reminder_stage_key($s, $today);
            if (!isset($log[$s . '|' . $key])) {
                $reasons[]    = [$s, $key];
                $due_expiring = true;
            }
            // A contract that reached its final month without ever getting its
            // two-month notice never will. Settled silently rather than fired
            // late, so the log never claims a message went out that did not.
            if ($s === AON_REMINDER_STAGE_T1M
                && !isset($log[AON_REMINDER_STAGE_T2M . '|'])) {
                $superseded[] = [AON_REMINDER_STAGE_T2M, ''];
            }
            // The Monday repeat, on top of the one-shot above. Both can be due in
            // the same run -- a contract entering its final month ON a Monday
            // gets its 1-month notice and starts its weekly cadence at once,
            // which is one appearance in the message and two rows in the log.
            if ($s === AON_REMINDER_STAGE_T1M && $weeklyOpen) {
                $wk = aon_reminder_stage_key(AON_REMINDER_STAGE_WEEKLY, $today, $weeklySend);
                if (!isset($log[AON_REMINDER_STAGE_WEEKLY . '|' . $wk])) {
                    $reasons[]    = [AON_REMINDER_STAGE_WEEKLY, $wk];
                    $due_expiring = true;
                }
            }
        }

        if ($low) {
            $key = aon_reminder_stage_key(AON_REMINDER_STAGE_LOWQTY, $today);
            if (!isset($log[AON_REMINDER_STAGE_LOWQTY . '|' . $key])) {
                $reasons[] = [AON_REMINDER_STAGE_LOWQTY, $key];
                $due_low   = true;
            }
        }

        if (empty($reasons)) continue;

        $r['alert_low']      = $low;
        $r['alert_remain']   = $remain;
        $r['alert_ratio']    = aon_remain_ratio($r['quantity'] ?? null, $remain);
        $r['alert_expiring'] = ($stage !== null);
        // What this SKU is in THIS message for, which is not the same question:
        // a contract can be inside its expiry window and still appear only under
        // "quantity", because its expiry notice already went out weeks ago.
        $r['due_low']        = $due_low;
        $r['due_expiring']   = $due_expiring;
        $r['stage']          = $stage['stage'] ?? AON_REMINDER_STAGE_LOWQTY;
        $r['stage_meta']     = aon_reminder_stages()[$r['stage']];
        $r['days_left']      = $stage['days_left'] ?? null;
        $r['reasons']        = $reasons;
        $r['superseded']     = $superseded;
        $due[] = $r;
    }

    return $due;
}

/**
 * The reminder log collapsed to what the scheduler asks of it: per SKU, the set
 * of (stage, key) pairs already settled. Rows with was_sent = 0 settle their
 * stage too -- they were deliberately never sent, and must not be retried.
 *
 * @return array<string,array<string,bool>> SKU => { "STAGE|key" => true }
 */
function aon_reminder_log_index(PDO $pdo) {
    $index = [];
    try {
        $rows = $pdo->query(
            "SELECT sku_code, stage, stage_key FROM aon_reminder_log"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // No log table means nothing has been sent. The UNIQUE key is the real
        // guard against duplicates, so the worst case is one repeated reminder.
        error_log('aon_reminder_log_index: ' . $e->getMessage());
        return [];
    }
    foreach ($rows as $r) {
        $code = strtoupper(trim((string)$r['sku_code']));
        $index[$code][$r['stage'] . '|' . (string)$r['stage_key']] = true;
    }
    return $index;
}

/**
 * Write one due row's reasons (and the stages they overtook) to the log.
 *
 * INSERT IGNORE so a concurrent or repeated run cannot double-insert past the
 * UNIQUE key -- the log is the dedup, so it has to be the thing that cannot
 * race. Two imports finishing at the same moment therefore settle on one row
 * each way rather than both deciding the SKU is unsent.
 */
function aon_reminder_record(PDO $pdo, array $row, $was_sent = true, $today = null) {
    $today = $today ?: aon_reminder_today();
    try {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO aon_reminder_log (sku_code, stage, stage_key, valid_until, days_left, was_sent, sent_on)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $valid_until = substr((string)($row['valid_until'] ?? ''), 0, 10);
        $valid_until = $valid_until !== '' ? $valid_until : null;
        $days_left   = $row['days_left'] !== null ? (int)$row['days_left'] : null;

        foreach (($row['reasons'] ?? []) as $reason) {
            $stmt->execute([
                $row['sku_code'], $reason[0], (string)$reason[1],
                $valid_until, $days_left, $was_sent ? 1 : 0, $today,
            ]);
        }
        foreach (($row['superseded'] ?? []) as $s) {
            $stmt->execute([
                $row['sku_code'], $s[0], (string)$s[1],
                $valid_until, $days_left, 0, $today,
            ]);
        }
        return true;
    } catch (PDOException $e) {
        error_log('aon_reminder_record: ' . $e->getMessage());
        return false;
    }
}

// ── The WhatsApp message ────────────────────────────────────────────────────

/**
 * The STC WhatsApp group IDs, from config/aon_reminder_wa.php.
 * Empty until someone fills that file in, which is what keeps every sender in
 * dry run: there is no sensible default for "which group", and guessing one
 * would mean messaging the wrong people.
 */
function aon_reminder_group_ids() {
    $path = __DIR__ . '/../config/aon_reminder_wa.php';
    if (!is_file($path)) return [];
    $config = require $path;
    $out = [];
    foreach (($config['groups'] ?? []) as $id) {
        $id = trim((string)$id);
        if ($id !== '') $out[] = $id;
    }
    return $out;
}

/**
 * Where the reminder points: Master AON itself, the page the message is asking
 * the reader to go and act on.
 *
 * It used to point at the login page instead, on the reasoning that a group
 * message is mostly read without a session and a deep link would only bounce to
 * login and lose the destination. Half of that is still true -- require_login()
 * (includes/auth.php) redirects to login.php and does NOT remember where the
 * reader was heading -- but it argues for the deep link rather than against it:
 *
 *   session alive  ->  lands ON Master AON, pop-up waiting, one tap instead of
 *                      login page -> menu -> Master AON
 *   session dead   ->  lands on login.php, exactly where the old link put them
 *
 * So the change can only ever save taps and never costs any. What it does NOT
 * do is carry a logged-out reader through to Master AON after signing in; that
 * needs require_login() to preserve the destination, which is a change to every
 * page's gate and deliberately not made here.
 *
 * NOTE: this path has no /vms/ segment, unlike every other production URL in
 * this repo (e.g. the Form 2 cron's https://vssportal.online/vms/workspace/pages).
 * It is the address supplied for this reminder; if the link ever 404s, that
 * difference is the first thing to check.
 */
function aon_reminder_default_link() {
    return 'https://vssportal.online/workspace/pages/master_aon.php';
}

/**
 * The quantity half of the digest: who has how many SKUs running out of stock.
 *
 * Counts per owner, not the SKUs themselves. Spelling out forty-odd SKU codes
 * and item names produced a wall of text nobody reads on a phone, and the detail
 * is one tap away in Workspace -- so this section's only job is to tell each STC
 * HOW MANY SKUs are waiting on them, which is what was asked for.
 */
function aon_reminder_qty_section(array $rows) {
    $byOwner = [];
    foreach ($rows as $d) {
        $owner = trim((string)($d['handler'] ?? ''));
        if (!isset($byOwner[$owner])) $byOwner[$owner] = 0;
        $byOwner[$owner]++;
    }

    // Busiest first so the longest queue is the first thing read; unclaimed last,
    // being the section with no obvious reader.
    uksort($byOwner, function ($a, $b) use ($byOwner) {
        if (($a === '') !== ($b === '')) return $a === '' ? 1 : -1;
        if ($byOwner[$a] !== $byOwner[$b]) return $byOwner[$b] <=> $byOwner[$a];
        return strcasecmp($a, $b);
    });

    $pct = (int)round(AON_LOW_REMAIN_RATIO * 100);
    $s  = "📦 *SISA KUANTITAS KONTRAK*\n";
    $s .= "*" . count($rows) . "* SKU dengan sisa kuantitas ≤ {$pct}% dari kontrak — perlu diupdate:\n";
    foreach ($byOwner as $owner => $count) {
        $s .= '• *' . ($owner === '' ? 'BELUM DI-CLAIM (tanpa STC)' : strtoupper($owner)) . '* — '
            . $count . " SKU\n";
    }
    return $s;
}

/**
 * The expiry half of the digest: who has how many contracts approaching
 * valid_until, mirroring aon_reminder_qty_section() above.
 *
 * Counts per owner rather than spelling out each contract -- the same reason
 * as the quantity section: a wall of contract numbers, vendors, and item names
 * is not read on a phone, and every one of those fields is one tap away in
 * Workspace anyway.
 */
function aon_reminder_expiry_section(array $rows) {
    $byOwner = [];
    foreach ($rows as $d) {
        $owner = trim((string)($d['handler'] ?? ''));
        if (!isset($byOwner[$owner])) $byOwner[$owner] = 0;
        $byOwner[$owner]++;
    }

    uksort($byOwner, function ($a, $b) use ($byOwner) {
        if (($a === '') !== ($b === '')) return $a === '' ? 1 : -1;
        if ($byOwner[$a] !== $byOwner[$b]) return $byOwner[$b] <=> $byOwner[$a];
        return strcasecmp($a, $b);
    });

    $s  = "📅 *MASA KONTRAK AKAN BERAKHIR*\n";
    $s .= "*" . count($rows) . "* SKU dengan kontrak akan berakhir — perlu diupdate:\n";
    foreach ($byOwner as $owner => $count) {
        $s .= '• *' . ($owner === '' ? 'BELUM DI-CLAIM (tanpa STC)' : strtoupper($owner)) . '* — '
            . $count . " SKU\n";
    }
    return $s;
}

/**
 * One digest per run rather than one message per SKU: a group that gets fifty
 * separate notifications stops reading any of them.
 *
 * Two clearly separated sections, quantity and expiry, because they are two
 * different problems: one is answered by ordering more against the contract, the
 * other by renewing it before the date. A SKU that is due for both appears in
 * both -- it has both problems, and hiding one of them behind the other is what
 * the split was asked to fix.
 *
 * Reads due_low / due_expiring, NOT alert_low / alert_expiring: the message must
 * describe what is being sent now, and a contract whose expiry notice went out
 * three weeks ago belongs only under quantity today.
 */
function aon_reminder_build_message(array $due, $link = null, $today = null) {
    $today = $today ?: aon_reminder_today();
    $link  = $link ?: aon_reminder_default_link();

    $lowRows = [];
    $expRows = [];
    foreach ($due as $d) {
        if (!empty($d['due_low']))      $lowRows[] = $d;
        if (!empty($d['due_expiring'])) $expRows[] = $d;
    }

    // Written in Indonesian throughout -- this goes to an Indonesian-speaking STC
    // group, and a message that switches language halfway reads as a machine
    // notice rather than something a colleague is being asked to act on. Note
    // that Indonesian does not inflect for plural, so there is no singular /
    // plural branching here the way the English wording needed.
    $m  = "⏰ *AON REMINDER — GP WORKSPACE*\n";
    $m .= date('d M Y', strtotime($today)) . "\n";

    if (!empty($lowRows)) $m .= "\n" . aon_reminder_qty_section($lowRows);
    if (!empty($expRows)) $m .= "\n" . aon_reminder_expiry_section($expRows);

    $m .= "\nMohon dicek di Workspace dan segera diproses perpanjangan/negosiasi kontraknya.\n\n";
    $m .= "*Buka Workspace:*\n";
    // Bare URL on its own line so WhatsApp auto-detects and hyperlinks it.
    $m .= $link;

    return $m;
}

// ── Sending ─────────────────────────────────────────────────────────────────

/**
 * Work out what is due, send it to the STC group, and record it. The ONE entry
 * point every caller uses -- the AON import hook, the Category Manager's manual
 * button, and the daily cron -- so all three agree on what is due, what the
 * message says, and what counts as sent.
 *
 * NEVER THROWS. It is called from inside the import flow, and a WhatsApp outage
 * must not fail an import that has already written its data correctly. Every
 * failure comes back in the return value instead.
 *
 * DRY RUN when no group is configured (or $opts['dry_run']): the message is
 * built and returned, and NOTHING is recorded -- a preview must not consume the
 * one-shot T-2M stage or today's slot for a SKU nobody was actually told about.
 *
 * Sending happens BEFORE the log is written, and the log is written only when at
 * least one group accepted. Recording after a total failure would silence a
 * contract nobody was told about; recording only after a total success would
 * re-send to every group whenever one of them is misconfigured.
 *
 * @param array $opts today | link | dry_run | source (free-text, for the log)
 * @return array{
 *   due:int, low:int, expiring:int, sent:bool, dry_run:bool, groups:int,
 *   accepted:int, recorded:int, weekly_open:bool, weekly_send_date:string,
 *   message:string, rows:array, error:?string
 * }
 */
function aon_reminder_dispatch(PDO $pdo, array $opts = []) {
    $today  = $opts['today'] ?? aon_reminder_today();
    $link   = $opts['link']  ?? aon_reminder_default_link();
    $source = (string)($opts['source'] ?? 'unknown');

    $out = [
        'due' => 0, 'low' => 0, 'expiring' => 0, 'sent' => false, 'dry_run' => false,
        'groups' => 0, 'accepted' => 0, 'recorded' => 0,
        'weekly_open' => false, 'weekly_send_date' => '',
        'message' => '', 'rows' => [], 'error' => null,
    ];

    try {
        $groups = aon_reminder_group_ids();
        $dry    = !empty($opts['dry_run']) || empty($groups);

        $out['groups']  = count($groups);
        $out['dry_run'] = $dry;

        // Reported so a cron run can say WHY the weekly stage did or did not
        // fire, which is otherwise invisible and looks like a bug on a Monday.
        $out['weekly_send_date'] = aon_reminder_weekly_send_date($pdo, $today);
        $out['weekly_open']      = aon_reminder_weekly_is_open($pdo, $today);

        $due = aon_reminder_due_rows($pdo, $today);
        $out['due']  = count($due);
        $out['rows'] = $due;
        foreach ($due as $d) {
            if (!empty($d['due_low']))      $out['low']++;
            if (!empty($d['due_expiring'])) $out['expiring']++;
        }
        if (empty($due)) return $out;

        $message = aon_reminder_build_message($due, $link, $today);
        $out['message'] = $message;
        if ($dry) return $out;

        require_once __DIR__ . '/fonnte.php';

        $accepted = 0;
        foreach ($groups as $groupId) {
            $res = sendFonnteGroupChecked($groupId, $message);
            if (!empty($res['ok'])) $accepted++;
        }
        $out['accepted'] = $accepted;

        if ($accepted === 0) {
            // Left due on purpose, so the next import or the cron retries it.
            $out['error'] = 'No WhatsApp group accepted the message.';
            return $out;
        }

        $out['sent'] = true;
        foreach ($due as $d) {
            if (aon_reminder_record($pdo, $d, true, $today)) $out['recorded']++;
        }
        error_log(sprintf(
            'aon_reminder_dispatch: source=%s due=%d low=%d exp=%d accepted=%d recorded=%d',
            $source, $out['due'], $out['low'], $out['expiring'], $out['accepted'], $out['recorded']
        ));
        return $out;
    } catch (Throwable $e) {
        // Deliberately swallowed: see the docblock. The caller is usually an
        // import that has already succeeded, and there is nothing it could
        // usefully do about a failure here.
        error_log('aon_reminder_dispatch(' . $source . '): ' . $e->getMessage());
        $out['error'] = 'Reminder dispatch failed.';
        return $out;
    }
}

/**
 * The hook every AON import calls once its data is safely written.
 *
 * Separate from aon_reminder_dispatch() only to give the import path one
 * obvious, self-documenting name and to pin the source label. MUST be called
 * AFTER the import's transaction has committed: sending on uncommitted data
 * would announce SKUs that a rollback then erases, and the log rows recording
 * the send would roll back with it -- so the same reminder would go out again.
 *
 * In practice this now mostly carries the QUANTITY half of the digest: an import
 * is what changes remaining quantity, while the expiry calendar moves on its own
 * and its weekly repeat is gated to its 14:00 Monday slot regardless of who
 * calls this.
 */
function aon_reminder_after_import(PDO $pdo, $source = 'import') {
    return aon_reminder_dispatch($pdo, ['source' => $source]);
}
