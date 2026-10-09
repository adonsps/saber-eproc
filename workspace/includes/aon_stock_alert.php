<?php
// includes/aon_stock_alert.php
//
// The "your AON SKUs need renegotiating" pop-up shown to the STC who owns them,
// on pages/master_aon.php and on their landing page pages/dashboard_stc.php.
//
// Sibling of includes/sla_alert.php, which warns a PIC/Admin/Category Manager
// that a *ticket* is at its SLA limit. This one is about the *contract* behind
// those tickets: an AON commitment that runs out of quantity, or reaches the end
// of its validity window, stops being an AON at all -- every PR against that SKU
// goes back to being canvassed from scratch. Both are things the owner has to
// act on before the date passes, hence the same shape of warning.
//
// A SKU is worth interrupting the STC over when it is claimed by them, currently
// inside its validity window, not opted out, and either:
//   1. LOW QTY   -- 30% or less of the contract quantity is still available
//                   (contract 100, remaining 30 -- see aon_remain_is_low()), or
//   2. EXPIRING  -- valid_until is inside a reminder stage window: two months
//                   out (T2M) or inside the final month (T1M)
//                   (aon_reminder_stage_for(), includes/aon_reminder.php).
//
// Both at once is possible and is the most urgent case of all, and since Aug 2026
// the pop-up SAYS SO: the list is split into two headed sections, "running out of
// quantity" and "contract period ending", and a SKU with both problems is listed
// under both. One flat list made the two indistinguishable at a glance, which is
// exactly the thing an STC has to be able to tell apart -- one is answered by
// ordering against the contract, the other by renewing it. Answering a SKU in one
// section removes it from the other too; it is one contract, and one decision.
//
// The rules are deliberately the SAME definitions the WhatsApp reminder sends
// on, rather than a second set that happens to look similar: an STC who reads
// "1 month left" in the group chat and then opens Workspace has to find that
// same contract on the pop-up, or one of the two is lying. The cadences differ
// -- the pop-up shows a SKU every day it is in trouble, while WhatsApp sends the
// quantity warning daily, the two- and one-month notices once each, and then
// repeats weekly on Mondays through the final month -- but the set of SKUs in
// trouble is one definition.
//
// A SKU the STC has answered for drops off both. There are two answers, and
// which one was given is recorded (aon_reminder_optouts.status):
//   On Progress   the renewal is already being worked on
//   Not Renewal   the contract is being dropped -- reason mandatory
//
// Scoped to aon_skus.handler = the signed-in user: an STC is warned about the
// SKUs they claimed, exactly as the SLA pop-up warns a PIC about the lines
// assigned to them. Category Manager and Manager can open Master AON too, but
// they do not claim SKUs, so nothing matches and they see no pop-up -- oversight
// roles should not be interrupted by every STC's contract calendar.
//
// The pop-up opens itself on every render UNTIL it is dismissed, then stays
// quiet for the rest of the session (ajax_aon_stock_alert_dismiss.php records
// it; logout.php's session_destroy() clears it, so the next login shows it
// again). After dismissal the amber counter button reopens it on demand.

require_once __DIR__ . '/aon_helper.php';
require_once __DIR__ . '/aon_reminder.php';

/**
 * Width of the whole expiry warning window in days, used ONLY to put "how close
 * to expiry" on the same 0-1 scale as "how low on quantity" when ordering the
 * list. Roughly two months; nothing depends on it being exact, and the stage
 * boundaries themselves are proper calendar months (see aon_reminder.php).
 */
const AON_STOCK_ALERT_WINDOW_DAYS = 62;

/**
 * Session key set once the user closes the pop-up (see
 * pages/ajax_aon_stock_alert_dismiss.php). Cleared by logout's session_destroy().
 */
const AON_STOCK_ALERT_DISMISSED = 'aon_stock_alert_dismissed';

/**
 * The alert-worthy AON SKUs owned by $handler, most urgent first.
 *
 * Queried here rather than filtered out of a list the caller already has, so
 * the Master AON page and the STC dashboard -- which share no query -- can never
 * warn about different sets. The handler scope keeps the result small.
 *
 * Each returned row is the aon_skus row plus:
 *   alert_remain    float|null  effective remaining qty
 *   alert_ratio     float|null  remaining / contract qty
 *   alert_low       bool        low on quantity
 *   alert_expiring  bool        inside a reminder stage window
 *   alert_stage     array|null  aon_reminder_stage_for() metadata
 *   alert_days_left int|null    calendar days until valid_until
 *   alert_score     float       0 = worst; used for the ordering below
 *
 * @param string $handler aon_skus.handler to scope to; '' returns nothing
 */
function aon_stock_alert_rows(PDO $pdo, $handler) {
    $handler = trim((string)$handler);
    if ($handler === '') return [];

    // Jakarta, not the ambient PHP timezone: the web SAPI runs on UTC, and
    // between midnight and 07:00 WIB that is still yesterday -- which would leave
    // a contract that lapsed last night on the list as though it were still live.
    $today = aon_reminder_today();

    // Same "is a real AON row" filter Master AON itself applies: not
    // soft-deleted, and with an allocation. Rows outside the validity window are
    // dropped in SQL -- an expired contract is a different (and later)
    // conversation than one about to expire, and one that has not started yet
    // cannot be running out.
    $where = "FROM aon_skus
             WHERE is_deleted = 0
               AND alokasi IS NOT NULL AND alokasi != ''
               AND handler = ?
               AND (valid_from  IS NULL OR valid_from  <= ?)
               AND (valid_until IS NULL OR valid_until >= ?)";
    $cols  = "id, sku_code, item_name, vendor, category, handler,
              quantity, used_qty, remain_qty, price, valid_from, valid_until";

    try {
        // contract_no is selected defensively, exactly as aon_reminder_due_rows()
        // does it: an environment whose auto-migrate has not landed yet still has
        // every other column, and a card without a contract number beats no
        // pop-up at all.
        try {
            $stmt = $pdo->prepare("SELECT {$cols}, contract_no {$where}");
            $stmt->execute([$handler, $today, $today]);
        } catch (PDOException $inner) {
            $stmt = $pdo->prepare("SELECT {$cols} {$where}");
            $stmt->execute([$handler, $today, $today]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Master AON table unavailable -- the page still renders, just without
        // the warning.
        error_log('aon_stock_alert_rows: ' . $e->getMessage());
        return [];
    }

    // Contracts the STC has said outright are not being extended. Excluded here
    // rather than merely styled differently: the whole point of the Ignore
    // button is that the item stops interrupting anyone.
    $optouts = aon_reminder_optouts($pdo);

    $out = [];
    foreach ($rows as $r) {
        if (isset($optouts[strtoupper(trim((string)$r['sku_code']))])) continue;

        $remain = aon_effective_remain_qty($r['quantity'] ?? null, $r['used_qty'] ?? null, $r['remain_qty'] ?? null);
        $ratio  = aon_remain_ratio($r['quantity'] ?? null, $remain);
        $low    = aon_remain_is_low($r['quantity'] ?? null, $remain);

        // Which reminder window the contract is in today, by date alone -- the
        // same call the WhatsApp cron makes.
        $stage    = aon_reminder_stage_for($r['valid_until'] ?? null, $today);
        $expiring = ($stage !== null);

        if (!$low && !$expiring) continue;

        // How deep into the danger zone this SKU is, on one scale, so a contract
        // down to its last 2% outranks one that has only just entered the
        // two-month window. 0 = exhausted / expiring today, 1 = just at the
        // threshold; whichever of the two conditions bites hardest wins.
        $qtyScore  = $low      ? min(1.0, $ratio / AON_LOW_REMAIN_RATIO) : 1.0;
        $timeScore = $expiring ? min(1.0, max(0, $stage['days_left']) / AON_STOCK_ALERT_WINDOW_DAYS) : 1.0;

        $r['alert_remain']    = $remain;
        $r['alert_ratio']     = $ratio;
        $r['alert_low']       = $low;
        $r['alert_expiring']  = $expiring;
        $r['alert_stage']     = $stage;
        $r['alert_days_left'] = $expiring ? (int)$stage['days_left'] : null;
        $r['alert_score']     = min($qtyScore, $timeScore);
        $out[] = $r;
    }

    usort($out, function ($a, $b) {
        // Hit by both conditions first -- that SKU is out of room in two
        // directions at once and has the least slack of anything on the list.
        $ba = ($a['alert_low'] && $a['alert_expiring']) ? 0 : 1;
        $bb = ($b['alert_low'] && $b['alert_expiring']) ? 0 : 1;
        if ($ba !== $bb) return $ba <=> $bb;
        if ($a['alert_score'] != $b['alert_score']) return $a['alert_score'] <=> $b['alert_score'];
        return strcmp((string)$a['sku_code'], (string)$b['sku_code']);
    });

    return $out;
}

/**
 * Recorded when the STC closes the pop-up, so it stops opening by itself for the
 * rest of this login session.
 */
function aon_stock_alert_mark_dismissed() {
    $_SESSION[AON_STOCK_ALERT_DISMISSED] = 1;
}

/**
 * Should the pop-up open by itself on this render? True until the user dismisses
 * it. Read-only -- rendering the page must not count as having seen it, or a
 * stray reload would silence the warning.
 */
function aon_stock_alert_should_autoshow() {
    return empty($_SESSION[AON_STOCK_ALERT_DISMISSED]);
}

/**
 * Human-readable reason line for one alert row, e.g.
 * "30 of 100 left (30%) · expires in 6 working days".
 */
function aon_stock_alert_reason(array $a) {
    $parts = [];
    if (!empty($a['alert_low'])) {
        $pct = $a['alert_ratio'] !== null ? round($a['alert_ratio'] * 100) : null;
        $parts[] = aon_stock_alert_num($a['alert_remain']) . ' of ' . aon_stock_alert_num($a['quantity']) . ' left'
            . ($pct !== null ? ' (' . $pct . '%)' : '');
    }
    if (!empty($a['alert_expiring'])) {
        $d = (int)$a['alert_days_left'];
        $parts[] = ($d <= 0 ? 'expires today' : 'expires in ' . $d . ' day' . ($d === 1 ? '' : 's'))
            . ' (' . ($a['alert_stage']['label'] ?? '') . ')';
    }
    return implode(' · ', $parts);
}

/**
 * Compact number for the pop-up: thousands separated, trailing zeros dropped.
 * Local to this file so the alert reads the same on both pages (Master AON's own
 * aon_num() is defined inside that page and is not available on the dashboard).
 */
function aon_stock_alert_num($v) {
    if ($v === null || $v === '' || !is_numeric($v)) return '—';
    return rtrim(rtrim(number_format((float)$v, 2, '.', ','), '0'), '.');
}

/**
 * Amber counter button that reopens the pop-up on demand. Render it in the page
 * header next to the other controls; returns '' when there is nothing to warn
 * about. Sized to match the buttons it sits beside on Master AON (h-9 pills).
 */
function aon_stock_alert_button_html(array $alerts) {
    $n = count($alerts);
    if ($n === 0) return '';
    return '<button type="button" id="aonStockAlertOpen"'
        . ' class="h-9 box-border inline-flex items-center gap-1.5 px-3 rounded-lg border border-amber-300 bg-amber-100 text-amber-800 font-bold text-xs shadow-sm hover:bg-amber-200 transition whitespace-nowrap focus:outline-none"'
        . ' title="AON SKUs running out of quantity or validity">'
        . '<svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>'
        . '<span class="uppercase tracking-wider">AON Alert:</span>'
        . '<span class="font-bold">' . $n . '</span>'
        . '</button>';
}

/**
 * Thin bar shown above the Master AON table while the "Show these SKUs" filter
 * is on, so the STC can tell the list is deliberately narrowed and undo it.
 */
function aon_stock_alert_banner_html(array $alerts) {
    if (empty($alerts)) return '';
    return '<div id="aonStockAlertBanner" class="hidden items-center justify-between gap-3 mb-3 px-4 py-2 rounded-lg bg-amber-50 border border-amber-300">'
        . '<span class="text-xs font-bold text-amber-900">Showing only your AON SKUs that are running out of quantity or validity (' . count($alerts) . ').</span>'
        . '<button type="button" id="aonStockAlertClearFilter" class="text-xs font-bold text-amber-700 hover:text-amber-900 underline focus:outline-none">Show all SKUs</button>'
        . '</div>';
}

/**
 * One alert card. Called once per SKU per section, so a SKU that is both low on
 * quantity and near expiry renders twice -- once under each heading. That is the
 * point of the split: an STC scanning "contract period ending" must see every
 * contract that is ending, including the ones that are also running out of
 * stock. The badges still show both conditions on either copy, so neither card
 * hides half the story, and the opt-out handler removes every card carrying the
 * SKU rather than just the one that was clicked.
 */
function aon_stock_alert_card_html(array $a, $section) {
    $e    = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES); };
    $days = $a['alert_days_left'];
    $pct  = $a['alert_ratio'] !== null ? round($a['alert_ratio'] * 100) : null;

    ob_start();
    ?>
    <div class="aon-stock-card border border-gray-200 rounded-lg p-3 hover:border-amber-300 hover:bg-amber-50/40 transition"
         data-sku="<?= $e($a['sku_code']) ?>" data-section="<?= $e($section) ?>">
        <div class="flex flex-wrap items-center gap-2 mb-1">
            <span class="text-sm font-bold text-gray-900 font-mono"><?= $e($a['sku_code']) ?></span>
            <?php if (!empty($a['alert_low'])): ?>
                <span class="bg-red-100 text-red-800 border-red-300 text-[10px] font-bold px-2 py-0.5 rounded border"
                      title="<?= $e(aon_stock_alert_num($a['alert_remain'])) ?> of <?= $e(aon_stock_alert_num($a['quantity'])) ?> remaining">
                    Qty low<?= $pct !== null ? ' · ' . $pct . '%' : '' ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($a['alert_expiring'])): ?>
                <!-- The stage, not just the day count: this is the label the STC
                     WhatsApp reminder uses for the same contract. -->
                <span class="<?= $e($a['alert_stage']['class']) ?> text-[10px] font-bold px-2 py-0.5 rounded border"
                      title="Valid until <?= $e(date('d M Y', strtotime($a['valid_until']))) ?> — <?= $e($a['alert_stage']['label']) ?>">
                    <?= $days <= 0 ? 'Expires today' : 'Expires in ' . (int)$days . ' day' . ($days == 1 ? '' : 's') ?>
                </span>
            <?php endif; ?>
        </div>
        <p class="text-xs sm:text-sm text-gray-700 break-words"><?= $e($a['item_name']) ?></p>
        <!-- Each fact is its own flex item so a long vendor name wraps to its
             own line on a phone instead of dragging the row sideways. -->
        <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-gray-500 mt-1">
            <span>Remaining <?= $e(aon_stock_alert_num($a['alert_remain'])) ?> / <?= $e(aon_stock_alert_num($a['quantity'])) ?></span>
            <span>Valid until <?= !empty($a['valid_until']) ? $e(date('d M Y', strtotime($a['valid_until']))) : '—' ?></span>
            <span class="break-all">Vendor <?= $e($a['vendor'] !== null && $a['vendor'] !== '' ? $a['vendor'] : '—') ?></span>
            <?php if (!empty($a['contract_no'])): ?>
                <!-- The contract number the WhatsApp reminder quotes, so the two
                     can be matched up without opening the row. -->
                <span class="break-all font-mono">Contract <?= $e($a['contract_no']) ?></span>
            <?php endif; ?>
        </div>

        <!-- Two answers, not one. "Ignore" collapsed two different
             decisions into the same silence: a renewal already in
             hand and a contract being dropped both stopped the
             reminders, and afterwards nothing said which had
             happened. These record which, and only the second one
             demands a reason.

             Their own row under a divider, sized and filled like the
             Dismiss / Show these SKUs buttons in the footer. They used
             to sit inline at the end of the facts line above, at 11px
             on a white ground among grey text -- which read as part of
             the caption, and STCs did not register that the two most
             important controls on the card were controls at all. A
             button has to look like one to get pressed. -->
        <div class="flex flex-col sm:flex-row gap-2 mt-3 pt-3 border-t border-gray-100">
            <button type="button" class="aon-stock-optout flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg border border-indigo-300 bg-indigo-50 text-xs font-bold text-indigo-700 hover:bg-indigo-100 hover:border-indigo-400 shadow-sm transition focus:outline-none"
                    data-kind="<?= $e(AON_REMINDER_OPTOUT_ON_PROGRESS) ?>"
                    data-sku="<?= $e($a['sku_code']) ?>" data-item="<?= $e($a['item_name']) ?>"
                    title="Renewal or renegotiation already in progress — stop reminding me">
                <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                On Progress
            </button>
            <button type="button" class="aon-stock-optout flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg border border-red-300 bg-red-50 text-xs font-bold text-red-700 hover:bg-red-100 hover:border-red-400 shadow-sm transition focus:outline-none"
                    data-kind="<?= $e(AON_REMINDER_OPTOUT_NOT_RENEWAL) ?>"
                    data-sku="<?= $e($a['sku_code']) ?>" data-item="<?= $e($a['item_name']) ?>"
                    title="This contract is not being renewed — stop reminding me">
                <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                Not Renewal
            </button>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * The pop-up itself, its lead-in cue, and the small vanilla script driving both.
 *
 * Call this EARLY in the page -- before the table markup, not after. The cue is
 * server-rendered visible so it lands in the first paint (instant acknowledgement
 * that something needs attention, and pointer-events:none so it cannot be clicked
 * while the page is still building); the modal itself waits until the page has
 * settled, then the cue slides out and the modal eases in. Same two-element
 * arrangement, and for the same reason, as includes/sla_alert.php.
 *
 * @param array       $alerts     aon_stock_alert_rows() output
 * @param bool        $autoshow   open by itself once ready (i.e. not yet dismissed)
 * @param string|null $filter_url where "Show these SKUs" should go when this page
 *                                has no AON table to narrow (the STC dashboard).
 *                                Null on Master AON, which filters in place via
 *                                window.aonStockAlertFilter.
 */
function aon_stock_alert_render(array $alerts, $autoshow, $filter_url = null) {
    if (empty($alerts)) return;

    // Two lists, not one, and a SKU in trouble both ways is in both of them --
    // see aon_stock_alert_card_html(). $n stays the count of DISTINCT SKUs, so
    // the cue and the header button never inflate the number of things to do.
    $lowRows = [];
    $expRows = [];
    foreach ($alerts as $a) {
        if (!empty($a['alert_low']))      $lowRows[] = $a;
        if (!empty($a['alert_expiring'])) $expRows[] = $a;
    }
    $n    = count($alerts);
    $nLow = count($lowRows);
    $nExp = count($expRows);
    $pct  = (int)round(AON_LOW_REMAIN_RATIO * 100);
    $e    = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES); };
    ?>
    <style>
    /* Hand-written rather than Tailwind utilities: the Play CDN generates its
       classes asynchronously, and this has to be correct in the first paint. */
    #aonStockAlertCue {
        position: fixed; top: 16px; left: 50%; z-index: 9999;
        display: flex; align-items: center; gap: 10px;
        padding: 10px 16px; border-radius: 9999px;
        background: #fffbeb; border: 1px solid #fcd34d;
        box-shadow: 0 10px 25px -5px rgba(0,0,0,.18);
        font-size: 12px; color: #78350f; white-space: nowrap;
        pointer-events: none;            /* cannot be clicked before it is usable */
        animation: aonStockCueIn .22s cubic-bezier(.16,1,.3,1) both;
    }
    #aonStockAlertCue.is-leaving { animation: aonStockCueOut .18s ease forwards; }
    #aonStockAlertCue .aon-cue-dot {
        width: 8px; height: 8px; border-radius: 9999px; background: #f59e0b;
        flex: none; animation: aonStockCuePulse .8s ease-in-out infinite;
    }
    #aonStockAlertCue .aon-cue-bar {
        position: relative; width: 46px; height: 3px; border-radius: 9999px;
        background: #fde68a; overflow: hidden; flex: none;
    }
    #aonStockAlertCue .aon-cue-bar::after {
        content: ''; position: absolute; inset: 0; border-radius: 9999px;
        background: #d97706; animation: aonStockCueBar .7s ease-in-out infinite;
    }
    @keyframes aonStockCueIn   { from { opacity: 0; transform: translate(-50%, -20px); } to { opacity: 1; transform: translate(-50%, 0); } }
    @keyframes aonStockCueOut  { to   { opacity: 0; transform: translate(-50%, -20px); } }
    @keyframes aonStockCuePulse{ 0%, 100% { opacity: 1; } 50% { opacity: .3; } }
    @keyframes aonStockCueBar  { 0% { transform: translateX(-115%); } 100% { transform: translateX(115%); } }

    #aonStockAlertModal          { opacity: 0; transition: opacity .22s ease; }
    #aonStockAlertModal.is-open  { opacity: 1; }
    #aonStockAlertPanel {
        opacity: 0; transform: translateY(30px) scale(.98);
        transition: opacity .3s ease, transform .34s cubic-bezier(.16,1,.3,1);
    }
    #aonStockAlertModal.is-open #aonStockAlertPanel { opacity: 1; transform: none; }

    /* The section headings stay put while their cards scroll under them, so a
       long list never leaves the reader wondering which half they are in. */
    .aon-stock-section-head {
        position: sticky; top: 0; z-index: 1;
        margin: 0 -4px; padding: 8px 4px;
        background: #fff;
    }

    @media (prefers-reduced-motion: reduce) {
        #aonStockAlertCue, #aonStockAlertModal, #aonStockAlertPanel,
        #aonStockAlertCue .aon-cue-dot, #aonStockAlertCue .aon-cue-bar::after {
            animation: none !important; transition: none !important;
        }
        #aonStockAlertPanel { opacity: 1; transform: none; }
    }
    </style>

    <?php if ($autoshow): ?>
    <div id="aonStockAlertCue" role="status" aria-live="polite">
        <span class="aon-cue-dot"></span>
        <span><strong><?= $n ?></strong> AON SKU<?= $n === 1 ? '' : 's' ?> running out</span>
        <span class="aon-cue-bar"></span>
    </div>
    <?php endif; ?>

    <div id="aonStockAlertModal" class="hidden fixed inset-0 z-[9998] items-end sm:items-center justify-center p-0 sm:p-4 bg-gray-900/60">
        <div id="aonStockAlertPanel" class="bg-white w-full sm:max-w-3xl rounded-t-2xl sm:rounded-xl shadow-2xl border-t sm:border border-amber-200 flex flex-col max-h-[85vh]">

            <div class="flex items-start gap-3 sm:gap-4 p-4 sm:p-5 border-b border-amber-200 bg-amber-50 rounded-t-2xl sm:rounded-t-xl">
                <div class="flex-shrink-0 mt-0.5">
                    <svg class="h-6 w-6 sm:h-8 sm:w-8 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-base sm:text-lg font-bold text-amber-900">Your AON contracts need attention</h3>
                    <p class="text-xs sm:text-sm text-amber-800 mt-1">
                        <!-- Rewritten by the ignore handler once the list changes, so the
                             breakdown can never outlive the cards it is describing. -->
                        <span id="aonStockAlertSummary">
                            <span class="font-bold"><?= $n ?></span> of your AON SKU<?= $n === 1 ? '' : 's' ?> need attention
                            <?php if ($nLow > 0 && $nExp > 0): ?>
                                &mdash; <span class="font-bold"><?= $nLow ?></span> running out of quantity,
                                <span class="font-bold"><?= $nExp ?></span> running out of contract time.
                            <?php elseif ($nLow > 0): ?>
                                &mdash; down to the last <span class="font-bold"><?= $pct ?>%</span> of contract quantity.
                            <?php else: ?>
                                &mdash; approaching the <span class="font-bold">end of the contract period</span>.
                            <?php endif; ?>
                        </span>
                        Renew or renegotiate them now, or PRs against them stop being covered by an AON.
                    </p>
                    <p class="text-[11px] text-amber-700 mt-1.5">
                        Already renegotiating one of these? Press <span class="font-bold">On Progress</span>.
                        Not renewing it at all? Press <span class="font-bold">Not Renewal</span> and say why.
                        Either way it stops appearing here and in the STC WhatsApp reminders.
                    </p>
                </div>
                <button type="button" class="aon-stock-alert-close text-amber-500 hover:text-amber-800 p-1 rounded focus:outline-none" title="Close">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="overflow-y-auto overscroll-contain p-4 sm:p-5 space-y-5">

                <!-- QUANTITY. Listed first because it is the faster-moving of the
                     two: a contract's end date is fixed and visible months out,
                     while remaining stock can fall through the threshold between
                     one import and the next. -->
                <section class="aon-stock-section space-y-3" data-section="qty" <?= $nLow === 0 ? 'hidden' : '' ?>>
                    <div class="aon-stock-section-head flex items-center gap-2 border-b-2 border-red-200 pb-1.5">
                        <span class="text-base">📦</span>
                        <h4 class="text-xs font-bold uppercase tracking-wider text-red-800">Running out of quantity</h4>
                        <span class="aon-stock-section-count text-[10px] font-bold px-2 py-0.5 rounded-full bg-red-100 text-red-800 border border-red-200"><?= $nLow ?></span>
                        <span class="text-[11px] text-gray-500 ml-auto hidden sm:inline"><?= $pct ?>% or less of the contract qty left</span>
                    </div>
                    <?php foreach ($lowRows as $a) echo aon_stock_alert_card_html($a, 'qty'); ?>
                </section>

                <!-- VALIDITY. -->
                <section class="aon-stock-section space-y-3" data-section="exp" <?= $nExp === 0 ? 'hidden' : '' ?>>
                    <div class="aon-stock-section-head flex items-center gap-2 border-b-2 border-amber-200 pb-1.5">
                        <span class="text-base">📅</span>
                        <h4 class="text-xs font-bold uppercase tracking-wider text-amber-800">Contract period ending</h4>
                        <span class="aon-stock-section-count text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 border border-amber-200"><?= $nExp ?></span>
                        <span class="text-[11px] text-gray-500 ml-auto hidden sm:inline">two months out or less</span>
                    </div>
                    <?php foreach ($expRows as $a) echo aon_stock_alert_card_html($a, 'exp'); ?>
                </section>

                <p id="aonStockAlertEmpty" class="hidden text-center text-sm text-gray-500 py-6">
                    Every SKU on this list has been answered. Nothing left to chase.
                </p>
            </div>

            <div class="p-4 border-t bg-gray-50 rounded-b-xl flex flex-col sm:flex-row gap-2 sm:justify-end">
                <button type="button" class="aon-stock-alert-close w-full sm:w-auto px-4 py-2 text-sm font-bold text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 focus:outline-none">Dismiss</button>
                <button type="button" id="aonStockAlertShowRows" class="w-full sm:w-auto px-4 py-2 text-sm font-bold text-white bg-amber-600 hover:bg-amber-700 rounded-lg shadow-sm focus:outline-none">Show these SKUs &rarr;</button>
            </div>
        </div>
    </div>

    <!-- Opt-out confirmation, shared by both answers. A layer above the pop-up
         rather than an inline form inside the card: dropping a contract out of
         every future reminder deserves a deliberate stop, not a stray click in a
         scrolling list. z-index sits above the pop-up's own 9998.
         One modal for both kinds, re-labelled per click — two near-identical
         dialogs would be two places for the wording and the wiring to drift. -->
    <div id="aonStockIgnoreModal" class="hidden fixed inset-0 z-[10000] items-center justify-center p-4 bg-gray-900/60">
        <div class="bg-white w-full max-w-md rounded-xl shadow-2xl border border-gray-200">
            <div class="px-5 py-4 border-b border-gray-100">
                <h4 id="aonStockIgnoreTitle" class="text-base font-bold text-gray-900">Stop reminders for this SKU?</h4>
                <p id="aonStockIgnoreSku" class="text-xs text-gray-500 mt-1 font-mono break-all"></p>
            </div>
            <div class="px-5 py-4">
                <p id="aonStockIgnoreBlurb" class="text-xs text-gray-600 mb-3"></p>
                <label class="block text-xs font-bold text-gray-700 mb-1">
                    <span id="aonStockIgnoreLabel">Why is this contract not being renewed?</span>
                    <span id="aonStockIgnoreReq" class="text-red-500">*</span>
                </label>
                <textarea id="aonStockIgnoreRemarks" rows="3" maxlength="1000"
                    class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400"
                    placeholder="e.g. vendor discontinued the item; replaced by SKU 12345"></textarea>
                <p id="aonStockIgnoreError" class="hidden mt-1.5 text-xs font-semibold text-red-600"></p>
                <p class="mt-2 text-[11px] text-gray-500">
                    This SKU will stop appearing in this pop-up and in the STC WhatsApp reminders.
                    You can switch reminders back on from its Details on the Master AON page.
                </p>
            </div>
            <div class="px-5 py-4 bg-gray-50 rounded-b-xl flex flex-col sm:flex-row gap-2 sm:justify-end">
                <button type="button" id="aonStockIgnoreCancel" class="w-full sm:w-auto px-4 py-2 text-sm font-bold text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 focus:outline-none">Cancel</button>
                <button type="button" id="aonStockIgnoreConfirm" class="w-full sm:w-auto px-5 py-2 text-sm font-bold text-white bg-red-600 hover:bg-red-700 rounded-lg shadow-sm focus:outline-none">Confirm</button>
            </div>
        </div>
    </div>

    <script>
    // No DOMContentLoaded wrapper on purpose: this runs the moment the parser
    // reaches it, so the cue is already live and the close controls already work
    // while the rest of the page is still loading.
    (function () {
        var modal       = document.getElementById('aonStockAlertModal');
        var cue         = document.getElementById('aonStockAlertCue');
        var FILTER_URL  = <?= json_encode($filter_url) ?>;
        var autoPending = <?= $autoshow ? 'true' : 'false' ?>;
        var dismissed   = false;
        var wantFilter  = false;   // "Show these SKUs" hit before the table was ready

        function show() {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            // Force a style flush rather than waiting on requestAnimationFrame: rAF
            // callbacks queue behind whatever the main thread is doing, so on a busy
            // page the pop-up would sit at opacity 0 for a while.
            void modal.offsetWidth;
            modal.classList.add('is-open');
        }

        // Closing is what marks the warning as seen -- reported to the server once
        // so it survives a reload, and so logging out resets it.
        function hide() {
            autoPending = false;
            modal.classList.remove('is-open');
            setTimeout(function () {
                modal.classList.remove('flex');
                modal.classList.add('hidden');
            }, 220);                        // matches the fade-out above
            if (dismissed) return;
            dismissed = true;
            // Nothing to recover on failure: worst case it opens once more.
            try { fetch('ajax_aon_stock_alert_dismiss.php', { method: 'POST', keepalive: true }).catch(function () {}); } catch (e) {}
        }

        function dropCue() {
            if (!cue) return;
            var el = cue;          // captured: `cue` is nulled before the timer runs
            cue = null;
            el.classList.add('is-leaving');
            setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 260);
        }

        // Hands the cue over to the pop-up. Idempotent -- several things race here.
        function ready() {
            if (!autoPending) { dropCue(); return; }
            autoPending = false;
            dropCue();
            show();   // same tick, on purpose: a timer here would queue behind
                      // whatever the page is still busy with, stretching the cue
                      // out past the moment it was supposed to hand over.
        }

        // Backdrop click closes; clicks inside the panel must not bubble out.
        modal.addEventListener('click', function (ev) { if (ev.target === modal) hide(); });
        document.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Escape') return;
            // The ignore confirmation sits on top, so it is what Escape answers.
            // Without this, dismissing the confirmation would also dismiss the
            // warning underneath it -- and dismissal is recorded server-side.
            if (ignoreOpen()) { closeIgnore(); return; }
            hide();
        });
        // Delegated, so every control works no matter where it sits in the page
        // relative to this script and no matter how much is still loading.
        document.addEventListener('click', function (ev) {
            var t = ev.target.closest
                ? ev.target.closest('.aon-stock-alert-close, #aonStockAlertOpen, #aonStockAlertShowRows')
                : null;
            if (!t) return;
            if (t.id === 'aonStockAlertOpen') { show(); return; }
            if (t.id === 'aonStockAlertShowRows') {
                // On a page with no AON table (the STC dashboard) this hands over
                // to Master AON, which reads ?aon_alert=1 and applies the same
                // filter on arrival. Otherwise it narrows the table in place.
                if (FILTER_URL) { window.location.href = FILTER_URL; return; }
                hide();
                // Bound here rather than beside the table so this button is never
                // dead and never has to be hidden. If the table has not finished
                // wiring up, the click is remembered and replayed.
                if (window.aonStockAlertFilter) { window.aonStockAlertFilter(); }
                else { wantFilter = true; }
                return;
            }
            hide();
        });

        // The cue should last exactly as long as the page really takes to become
        // usable -- no arbitrary timer, and explicitly NOT window.load, which also
        // waits on images and CDN assets long after the page is interactive.
        //
        // requestAnimationFrame is the honest signal: while the table script is
        // building and paginating the SKU list no frame is delivered at all; once
        // frames arrive at a normal cadence again the page really is ready.
        // Roughly 50ms of calm is enough.
        function openWhenSettled() {
            var prev = 0, calm = 0;
            function tick(ts) {
                calm = (prev && ts - prev < 60) ? calm + 1 : 0;
                prev = ts;
                if (calm >= 3) { ready(); return; }
                requestAnimationFrame(tick);
            }
            requestAnimationFrame(tick);
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', openWhenSettled);
        } else {
            openWhenSettled();
        }

        // Absolute ceiling, and nothing more: frames stop being delivered in a
        // background tab, and a JS error downstream could break the signal above --
        // either way the cue must never be left on screen with the warning never
        // arriving.
        setTimeout(ready, 20000);

        // ── Opt-out: "On Progress" / "Not Renewal" ───────────────────────────
        // Both stop the reminders. The wording, and whether a reason is required,
        // come straight from aon_reminder_optout_kinds() so the dialog can never
        // promise something the server then refuses.
        var IG_KINDS  = <?= json_encode(aon_reminder_optout_kinds()) ?>;
        var igModal   = document.getElementById('aonStockIgnoreModal');
        var igSkuEl   = document.getElementById('aonStockIgnoreSku');
        var igTitle   = document.getElementById('aonStockIgnoreTitle');
        var igBlurb   = document.getElementById('aonStockIgnoreBlurb');
        var igLabel   = document.getElementById('aonStockIgnoreLabel');
        var igReq     = document.getElementById('aonStockIgnoreReq');
        var igRemarks = document.getElementById('aonStockIgnoreRemarks');
        var igError   = document.getElementById('aonStockIgnoreError');
        var igConfirm = document.getElementById('aonStockIgnoreConfirm');
        var igSku     = null;
        var igKind    = null;

        function ignoreOpen() { return igModal && !igModal.classList.contains('hidden'); }

        function igMeta() { return (igKind && IG_KINDS[igKind]) || IG_KINDS['NOT_RENEWAL']; }

        function openIgnore(sku, item, kind) {
            igSku  = sku;
            igKind = (kind && IG_KINDS[kind]) ? kind : 'NOT_RENEWAL';
            var meta = igMeta();

            igSkuEl.textContent = sku + (item ? ' — ' + item : '');
            igTitle.textContent = meta.title;
            igBlurb.textContent = meta.blurb;
            igLabel.textContent = meta.remarks_required
                ? 'Why is this contract not being renewed?'
                : 'Any notes? (optional)';
            igReq.classList.toggle('hidden', !meta.remarks_required);
            igRemarks.placeholder = meta.remarks_required
                ? 'e.g. vendor discontinued the item; replaced by SKU 12345'
                : 'e.g. negotiating with the vendor, new price expected next week';
            igConfirm.textContent = meta.label;
            // Not Renewal is the destructive-looking one; On Progress is routine
            // and should not be dressed up as a warning.
            igConfirm.classList.toggle('bg-red-600',      !!meta.remarks_required);
            igConfirm.classList.toggle('hover:bg-red-700', !!meta.remarks_required);
            igConfirm.classList.toggle('bg-indigo-600',      !meta.remarks_required);
            igConfirm.classList.toggle('hover:bg-indigo-700', !meta.remarks_required);

            igRemarks.value = '';
            igError.classList.add('hidden');
            igModal.classList.remove('hidden');
            igModal.classList.add('flex');
            igRemarks.focus();
        }

        function closeIgnore() {
            igSku  = null;
            igKind = null;
            igModal.classList.remove('flex');
            igModal.classList.add('hidden');
        }

        function failIgnore(msg) {
            igError.textContent = msg;
            igError.classList.remove('hidden');
            igConfirm.disabled = false;
            igConfirm.textContent = igMeta().label;
        }

        // Keeps the header honest after a card leaves the list: the counter
        // button, the summary sentence, the two section headings and the empty
        // state all come off the same live count rather than the numbers rendered
        // on the server.
        //
        // Counts DISTINCT SKUs, not cards: a SKU that is both low on quantity and
        // near expiry has a card in each section, and it is still one contract to
        // deal with. Each section keeps its own card count beside its heading,
        // and a section that empties hides itself rather than sitting there as a
        // heading with nothing under it.
        function refreshCount() {
            var seen = {};
            var total = 0;
            document.querySelectorAll('#aonStockAlertModal .aon-stock-card').forEach(function (c) {
                var sku = c.getAttribute('data-sku') || '';
                if (seen[sku]) return;
                seen[sku] = true;
                total++;
            });

            document.querySelectorAll('#aonStockAlertModal .aon-stock-section').forEach(function (sec) {
                var n = sec.querySelectorAll('.aon-stock-card').length;
                var badge = sec.querySelector('.aon-stock-section-count');
                if (badge) badge.textContent = n;
                sec.hidden = (n === 0);
            });

            var summary = document.getElementById('aonStockAlertSummary');
            if (summary) {
                summary.innerHTML = total === 0
                    ? 'Nothing left on this list.'
                    : '<span class="font-bold">' + total + '</span> of your AON SKU' + (total === 1 ? '' : 's')
                      + ' still need' + (total === 1 ? 's' : '') + ' attention.';
            }
            var empty = document.getElementById('aonStockAlertEmpty');
            if (empty) empty.classList.toggle('hidden', total > 0);

            var btn = document.getElementById('aonStockAlertOpen');
            if (btn) {
                if (total === 0) {
                    btn.remove();          // nothing left to reopen the pop-up for
                } else {
                    var num = btn.querySelector('span:last-child');
                    if (num) num.textContent = total;
                }
            }
            return total;
        }

        if (igModal) {
            igModal.addEventListener('click', function (ev) { if (ev.target === igModal) closeIgnore(); });
            document.getElementById('aonStockIgnoreCancel').addEventListener('click', closeIgnore);

            // Delegated for the same reason the controls above are: the cards are
            // in the DOM before this script, and this must work on the first click.
            document.addEventListener('click', function (ev) {
                var t = ev.target.closest ? ev.target.closest('.aon-stock-optout') : null;
                if (!t) return;
                openIgnore(t.getAttribute('data-sku'), t.getAttribute('data-item'), t.getAttribute('data-kind'));
            });

            igConfirm.addEventListener('click', function () {
                var sku     = igSku;
                var kind    = igKind;
                var meta    = igMeta();
                var remarks = igRemarks.value.trim();
                if (!sku) return;
                // Checked here as well as server-side so the common mistake gets an
                // answer without a round trip; the server is still the authority.
                if (meta.remarks_required && remarks === '') {
                    failIgnore('Please write why this contract is not being renewed.');
                    return;
                }

                igConfirm.disabled = true;
                igConfirm.textContent = 'Saving…';
                fetch('ajax_aon_reminder_ignore.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ sku_code: sku, active: true, status: kind, remarks: remarks })
                })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
                .then(function (res) {
                    if (!res.ok || res.body.status !== 'success') {
                        failIgnore(res.body.message || 'Could not save. Please try again.');
                        return;
                    }
                    igConfirm.disabled = false;
                    igConfirm.textContent = meta.label;
                    closeIgnore();

                    // Every card for this SKU, in BOTH sections -- the answer is
                    // about the contract, not about which heading it was clicked
                    // under, so leaving the other copy behind would be the pop-up
                    // asking the same question twice.
                    document.querySelectorAll('#aonStockAlertModal .aon-stock-card[data-sku="' + CSS.escape(sku) + '"]')
                        .forEach(function (card) { card.remove(); });
                    // The table row behind the pop-up describes the same SKU, so it
                    // must not keep claiming a reminder is coming. Master AON
                    // supplies this; the dashboard has no table and no hook.
                    if (window.aonMasterMarkIgnored) window.aonMasterMarkIgnored(sku, remarks, kind);

                    if (refreshCount() === 0) hide();
                })
                .catch(function () { failIgnore('Network error — please try again.'); });
            });
        }

        window.aonStockAlert = {
            show: show,
            hide: hide,
            ready: ready,
            wantsFilter: function () { return wantFilter; }
        };
    })();
    </script>
    <?php
}
