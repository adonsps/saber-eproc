<?php
// includes/sla_alert.php
//
// The "your tickets are at their SLA limit" pop-up, shown on the worklist a role
// lands on: dashboard_pic.php (PIC), dashboard_admin.php (Admin) and
// dashboard_mgr.php (Category Manager).
//
// Was includes/aon_sla_alert.php, which warned only about AON tickets that were
// Outstanding and yellow. Every one of those limits has been lifted: an SLA is
// an SLA whatever the ticket carries, a line that has been canvassed but has no
// PO is still burning the same clock, and a line already past its target is the
// one that matters most.
//
// A ticket line is worth interrupting someone over when both are true:
//   1. it is still open  -- display_status is 'Outstanding' (nothing canvassed
//      yet) or 'On Progress' (canvassed, no PO). Anything with a PO, cancelled,
//      escalated or sent back belongs to a different conversation.
//   2. its SLA badge is YELLOW or RED -- sla_status 'due_soon' (1 or 0 working
//      days left) or 'overdue' (past the target). Green is not urgent.
//
// Deliberately NOT 'na' (no PR date, countdown never started): the pop-up says
// "your badge is yellow or red", and an 'na' line renders a grey N/A badge. The
// dashboards' own SLA quick-filter buttons fold 'na' into Overdue, which is why
// clicking through from here asks them for an EXACT bucket instead -- see
// window.slaAlertApplyFilter on each page. Without that the table would answer a
// click on "379 lines" with 415 rows.
//
// The pop-up does not list the lines. It reports four numbers -- the two open
// statuses crossed with the two SLA colours -- and hands the reader off to the
// worklist itself, which is where the lines can actually be worked:
//
//   Outstanding / Overdue      <- worst: not even started, already late
//   Outstanding / Due soon
//   On Progress / Overdue
//   On Progress / Due soon
//
// Red is always above yellow inside each half. Blocks with nothing in them are
// not rendered at all. Each block is a button: it applies that exact status +
// SLA pair to the page's own quick filters and closes. An earlier version listed
// every line as a card, which on a Category Manager account meant a thousand of
// them -- a scroll, not an answer.
//
// The pop-up opens itself on every worklist render UNTIL the user dismisses it,
// after which it stays quiet for the rest of the session (the dismissal is
// recorded server-side by ajax_sla_alert_dismiss.php, and logout.php's
// session_destroy() clears it, so the next login shows it again).
//
// Deliberately NOT armed by the login page: this app has several ways in --
// pages/login.php, the VSS single sign-on handoff in pages/sso_vss.php, and the
// root login_action.php -- and a warning that silently depends on which door the
// user came through is a warning that will eventually not fire. Keying off "has
// this person dismissed it yet" instead means every entry path behaves the same
// and no future login route has to remember to opt in.
//
// After dismissal the pop-up stays reachable from the counter button rendered
// beside it, so a user can pull the list back up without logging out.

// No requires: this file only counts and draws. Every caller hands it rows that
// its own page already enriched -- compute_pr_display_status() for
// display_status, sla_attach() for the sla_* keys -- and the counts are only
// ever as trustworthy as that source. Which is why the Category Manager's page
// feeds this from get_dashboard_rows(), the same helper behind its table, rather
// than from a query of its own: see the note in dashboard_mgr.php.

/**
 * The four blocks, in render order: [display_status, sla_status].
 * Read by sla_alert_partition() and by the renderer, so the ordering rule lives
 * in exactly one place.
 */
function sla_alert_blocks() {
    return [
        ['section' => 'Outstanding', 'sla' => 'overdue'],
        ['section' => 'Outstanding', 'sla' => 'due_soon'],
        ['section' => 'On Progress', 'sla' => 'overdue'],
        ['section' => 'On Progress', 'sla' => 'due_soon'],
    ];
}

/**
 * Picks the alert-worthy lines out of an already-enriched PR set.
 *
 * $rows must already carry display_status (compute_pr_display_status) and the
 * sla_* keys (sla_attach).
 *
 * Returns the matching rows ordered by the four blocks above and, inside a
 * block, most urgent first (fewest days remaining, then oldest PR date).
 */
function sla_alert_rows(array $rows) {
    $out = [];

    // Block index per row, so the sort below reproduces the render order exactly
    // and a caller that ignores sla_alert_partition() still gets a sane list.
    $rank = [];
    foreach (sla_alert_blocks() as $i => $b) { $rank[$b['section'] . '|' . $b['sla']] = $i; }

    foreach ($rows as $r) {
        $key = ($r['display_status'] ?? '') . '|' . ($r['sla_status'] ?? '');
        if (!isset($rank[$key])) continue;
        $r['sla_alert_block'] = $rank[$key];
        $out[] = $r;
    }

    usort($out, function ($a, $b) {
        if ($a['sla_alert_block'] !== $b['sla_alert_block']) {
            return $a['sla_alert_block'] <=> $b['sla_alert_block'];
        }
        // Most negative (deepest overdue) first within a red block; 0 before 1
        // within a yellow one. Same comparison serves both.
        $ra = (int)($a['sla_remaining'] ?? 0);
        $rb = (int)($b['sla_remaining'] ?? 0);
        if ($ra !== $rb) return $ra <=> $rb;
        return strcmp((string)($a['pr_date'] ?? ''), (string)($b['pr_date'] ?? '')); // oldest first
    });

    return $out;
}

/**
 * Splits sla_alert_rows() output into the four render blocks, keyed
 * "<display_status>|<sla_status>". Blocks with no rows are present but empty,
 * so the renderer can skip them without re-deriving the ordering.
 */
function sla_alert_partition(array $alerts) {
    $part = [];
    foreach (sla_alert_blocks() as $b) { $part[$b['section'] . '|' . $b['sla']] = []; }
    foreach ($alerts as $a) {
        $k = ($a['display_status'] ?? '') . '|' . ($a['sla_status'] ?? '');
        if (isset($part[$k])) $part[$k][] = $a;
    }
    return $part;
}

/**
 * The headline numbers for one set of lines.
 *
 * lines    how many PR lines
 * tickets  how many DISTINCT ticket numbers those lines belong to. Counted per
 *          set, so the four blocks' ticket counts do not add up to the total --
 *          one ticket can have an Outstanding line and an On Progress line, and
 *          it is genuinely one ticket in each. sla_alert_tickets_overlap()
 *          below says whether that is happening, so the pop-up can admit it
 *          rather than look like it cannot add up.
 * max      largest whole days past target (overdue lines only, else null)
 * avg      mean days past target (overdue lines only, else null)
 *
 * Days come from sla_remaining, which sla_evaluate() computes in WORKING days
 * (weekends and holidays excluded) -- the same number the row's SLA badge and
 * tooltip show, so a reader can always find the line behind "max".
 */
function sla_alert_stats(array $rows) {
    $tickets = [];
    $days    = [];
    foreach ($rows as $r) {
        $t = trim((string)($r['ticket_no'] ?? ''));
        if ($t !== '') $tickets[$t] = true;
        $rem = (int)($r['sla_remaining'] ?? 0);
        if ($rem < 0) $days[] = -$rem;
    }
    return [
        'lines'   => count($rows),
        'tickets' => count($tickets),
        'max'     => $days ? max($days) : null,
        'avg'     => $days ? array_sum($days) / count($days) : null,
    ];
}

/**
 * True when at least one ticket has lines in more than one of the four blocks,
 * i.e. when the per-block ticket counts deliberately overlap.
 */
function sla_alert_tickets_overlap(array $alerts) {
    $seen = [];
    foreach ($alerts as $a) {
        $t = trim((string)($a['ticket_no'] ?? ''));
        if ($t === '') continue;
        $b = (int)($a['sla_alert_block'] ?? 0);
        if (isset($seen[$t]) && $seen[$t] !== $b) return true;
        $seen[$t] = $b;
    }
    return false;
}

/**
 * Session key set once the user closes the pop-up (see
 * pages/ajax_sla_alert_dismiss.php). Cleared by logout's session_destroy().
 */
const SLA_ALERT_DISMISSED = 'sla_alert_dismissed';

/**
 * Recorded when the user closes the pop-up, so it stops opening by itself for
 * the rest of this login session.
 */
function sla_alert_mark_dismissed() {
    $_SESSION[SLA_ALERT_DISMISSED] = 1;
}

/**
 * Should the pop-up open by itself on this render? True until the user dismisses
 * it. Read-only -- rendering the page must not count as having seen it, or a
 * stray reload would silence the warning.
 */
function sla_alert_should_autoshow() {
    return empty($_SESSION[SLA_ALERT_DISMISSED]);
}

/**
 * How many of the alerts are red (past target) rather than yellow.
 */
function sla_alert_overdue_count(array $alerts) {
    $n = 0;
    foreach ($alerts as $a) { if (($a['sla_status'] ?? '') === 'overdue') $n++; }
    return $n;
}

/**
 * Counter button that reopens the pop-up on demand. Render it in the page header
 * next to the other pills; returns '' when there is nothing to warn about.
 *
 * Sized to sit flush with the "Last updated" box it stands beside: same
 * text-xs / px-3 py-2 / rounded-lg / 1px border box model, and a 14px icon that
 * fits inside the 16px line box so it cannot push the button taller. It turns
 * red as soon as anything on the list is actually past its target, so the colour
 * of the button matches the colour of the worst badge behind it.
 */
function sla_alert_button_html(array $alerts) {
    $n = count($alerts);
    if ($n === 0) return '';
    $red = sla_alert_overdue_count($alerts) > 0;

    $cls  = $red
        ? 'bg-red-100 hover:bg-red-200 border-red-300'
        : 'bg-amber-100 hover:bg-amber-200 border-amber-300';
    $icon = $red ? 'text-red-600'  : 'text-amber-600';
    $lbl  = $red ? 'text-red-700'  : 'text-amber-700';
    $num  = $red ? 'text-red-900'  : 'text-amber-900';

    return '<button type="button" id="slaAlertOpen"'
        . ' class="flex items-center gap-1.5 text-xs ' . $cls . ' border rounded-lg px-3 py-2 shadow-sm transition focus:outline-none"'
        . ' title="Open ticket lines that are overdue or due today/tomorrow">'
        . '<svg class="h-3.5 w-3.5 flex-shrink-0 ' . $icon . '" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>'
        . '<span class="font-bold ' . $lbl . ' uppercase tracking-wider">SLA Alert:</span>'
        . '<span class="font-medium ' . $num . '">' . $n . '</span>'
        . '</button>';
}

/**
 * The pop-up itself, its lead-in cue, and the small vanilla script driving both.
 *
 * Call this EARLY in the page -- before the worklist table markup, not after.
 *
 * Two problems pull in opposite directions here, so the answer is two elements:
 *
 *  - Opening the modal only once JavaScript runs made it appear seconds late,
 *    because nothing runs until DataTables has finished building the worklist
 *    (several thousand rows on an Admin account).
 *  - Opening it in the first paint instead meant its buttons existed before the
 *    handlers that make them work, so an early click did nothing.
 *
 * So the CUE is server-rendered visible and lands in the first paint -- instant
 * acknowledgement that something needs attention, and deliberately
 * pointer-events:none so it cannot be clicked while the page is still building.
 * The MODAL stays hidden until the page settles (or until a page calls
 * window.slaAlert.ready() itself), then the cue slides out and the modal eases
 * in. The warning is therefore never silent and never a dead end.
 *
 * Each block button calls window.slaAlertApplyFilter(display_status, sla_status),
 * which every worklist page registers against its own table (see the
 * "SLA pop-up hand-off" block in dashboard_pic/admin/mgr.php). If a page has not
 * registered one the button simply closes the pop-up, so this is never dead.
 *
 * @param array $alerts   sla_alert_rows() output
 * @param bool  $autoshow open by itself once ready (i.e. not yet dismissed)
 */
function sla_alert_render(array $alerts, $autoshow) {
    if (empty($alerts)) return;

    $n       = count($alerts);
    $nRed    = sla_alert_overdue_count($alerts);
    $part    = sla_alert_partition($alerts);
    $all     = sla_alert_stats($alerts);
    $overlap = sla_alert_tickets_overlap($alerts);
    $e       = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES); };

    // Accent follows the worst thing on the list, so the pop-up, its cue and the
    // header button all read the same colour.
    $red      = $nRed > 0;
    $cueBg    = $red ? '#fef2f2' : '#fffbeb';
    $cueBd    = $red ? '#fca5a5' : '#fcd34d';
    $cueFg    = $red ? '#7f1d1d' : '#78350f';
    $cueDot   = $red ? '#ef4444' : '#f59e0b';
    $cueTrack = $red ? '#fecaca' : '#fde68a';
    $cueBar   = $red ? '#dc2626' : '#d97706';
    $hdrCls   = $red ? 'border-red-200 bg-red-50' : 'border-amber-200 bg-amber-50';
    $hdrIcon  = $red ? 'text-red-500' : 'text-amber-500';
    $hdrTitle = $red ? 'text-red-900' : 'text-amber-900';
    $hdrText  = $red ? 'text-red-800' : 'text-amber-800';
    $hdrClose = $red ? 'text-red-500 hover:text-red-800' : 'text-amber-500 hover:text-amber-800';
    $panelBd  = $red ? 'border-red-200' : 'border-amber-200';
    ?>
    <style>
    /* Hand-written rather than Tailwind utilities: the Play CDN generates its
       classes asynchronously, and this has to be correct in the first paint. */
    #slaAlertCue {
        position: fixed; top: 16px; left: 50%; z-index: 9999;
        display: flex; align-items: center; gap: 10px;
        padding: 10px 16px; border-radius: 9999px;
        background: <?= $cueBg ?>; border: 1px solid <?= $cueBd ?>;
        box-shadow: 0 10px 25px -5px rgba(0,0,0,.18);
        font-size: 12px; color: <?= $cueFg ?>; white-space: nowrap;
        pointer-events: none;            /* cannot be clicked before it is usable */
        animation: slaCueIn .22s cubic-bezier(.16,1,.3,1) both;
    }
    #slaAlertCue.is-leaving { animation: slaCueOut .18s ease forwards; }
    #slaAlertCue .sla-cue-dot {
        width: 8px; height: 8px; border-radius: 9999px; background: <?= $cueDot ?>;
        flex: none; animation: slaCuePulse .8s ease-in-out infinite;
    }
    #slaAlertCue .sla-cue-bar {
        position: relative; width: 46px; height: 3px; border-radius: 9999px;
        background: <?= $cueTrack ?>; overflow: hidden; flex: none;
    }
    #slaAlertCue .sla-cue-bar::after {
        content: ''; position: absolute; inset: 0; border-radius: 9999px;
        background: <?= $cueBar ?>; animation: slaCueBar .7s ease-in-out infinite;
    }
    @keyframes slaCueIn   { from { opacity: 0; transform: translate(-50%, -20px); } to { opacity: 1; transform: translate(-50%, 0); } }
    @keyframes slaCueOut  { to   { opacity: 0; transform: translate(-50%, -20px); } }
    @keyframes slaCuePulse{ 0%, 100% { opacity: 1; } 50% { opacity: .3; } }
    @keyframes slaCueBar  { 0% { transform: translateX(-115%); } 100% { transform: translateX(115%); } }

    #slaAlertModal      { opacity: 0; transition: opacity .22s ease; }
    #slaAlertModal.is-open { opacity: 1; }
    #slaAlertPanel {
        opacity: 0; transform: translateY(30px) scale(.98);
        transition: opacity .3s ease, transform .34s cubic-bezier(.16,1,.3,1);
    }
    #slaAlertModal.is-open #slaAlertPanel { opacity: 1; transform: none; }

    /* The four block buttons. Written out rather than assembled from utilities
       because the accent bar and the arrow's slide have to be right in the first
       paint, before the Tailwind CDN has generated anything.

       Overdue vs Due soon is carried by the left accent bar and by the line of
       detail under the numbers ("Max 51 days overdue" / "Due today or tomorrow"),
       NOT by a badge of its own. Badges are reserved for Outstanding and On
       Progress; a second row of them here just competed with the numbers, which
       are the thing worth reading. */
    .sla-alert-block {
        display: flex; align-items: center; gap: 14px; width: 100%;
        padding: 10px 14px; border: 1px solid #e5e7eb; border-left-width: 3px;
        border-radius: 8px; background: #fff; text-align: left; cursor: pointer;
        transition: border-color .15s ease, background-color .15s ease;
    }
    .sla-alert-block.is-overdue  { border-left-color: #ef4444; }
    .sla-alert-block.is-due-soon { border-left-color: #f59e0b; }
    .sla-alert-block.is-overdue:hover  { border-color: #fca5a5; border-left-color: #ef4444; background: #fef2f2; }
    .sla-alert-block.is-due-soon:hover { border-color: #fcd34d; border-left-color: #f59e0b; background: #fffbeb; }
    .sla-alert-block:focus-visible { outline: 2px solid #6366f1; outline-offset: 2px; }

    /* Numbers first: the count is the message, the unit is only there to name it. */
    .sla-alert-figure { font-size: 15px; font-weight: 700; color: #111827; line-height: 1.3; }
    .sla-alert-figure .u { font-size: 13px; font-weight: 500; color: #6b7280; }
    .sla-alert-figure .sep { color: #d1d5db; margin: 0 5px; font-weight: 400; }
    .sla-alert-detail { font-size: 11px; color: #6b7280; margin-top: 2px; }
    .sla-alert-detail b { font-weight: 700; color: #374151; }

    .sla-alert-go { margin-left: auto; flex: none; color: #d1d5db; transition: transform .15s ease, color .15s ease; }
    .sla-alert-block.is-overdue:hover  .sla-alert-go { color: #ef4444; transform: translateX(3px); }
    .sla-alert-block.is-due-soon:hover .sla-alert-go { color: #f59e0b; transform: translateX(3px); }

    @media (prefers-reduced-motion: reduce) {
        #slaAlertCue, #slaAlertModal, #slaAlertPanel, .sla-alert-block, .sla-alert-go,
        #slaAlertCue .sla-cue-dot, #slaAlertCue .sla-cue-bar::after {
            animation: none !important; transition: none !important;
        }
        #slaAlertPanel { opacity: 1; transform: none; }
        .sla-alert-block:hover .sla-alert-go { transform: none; }
    }
    </style>

    <?php if ($autoshow): ?>
    <div id="slaAlertCue" role="status" aria-live="polite">
        <span class="sla-cue-dot"></span>
        <span><strong><?= $n ?></strong> ticket line<?= $n === 1 ? '' : 's' ?> at the SLA limit</span>
        <span class="sla-cue-bar"></span>
    </div>
    <?php endif; ?>

    <div id="slaAlertModal" class="hidden fixed inset-0 z-[9998] items-end sm:items-center justify-center p-0 sm:p-4 bg-gray-900/60">
        <div id="slaAlertPanel" class="bg-white w-full sm:max-w-2xl rounded-t-2xl sm:rounded-xl shadow-2xl border-t sm:border <?= $panelBd ?> flex flex-col max-h-[85vh]">

            <div class="flex items-start gap-3 sm:gap-4 p-4 sm:p-5 border-b <?= $hdrCls ?> rounded-t-2xl sm:rounded-t-xl">
                <div class="flex-shrink-0 mt-0.5">
                    <svg class="h-6 w-6 sm:h-8 sm:w-8 <?= $hdrIcon ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-base sm:text-lg font-bold <?= $hdrTitle ?> leading-snug">Tickets are at their SLA limit</h3>
                    <!-- One sentence, and only the two numbers that are not already
                         on a block below. The red/yellow split used to be spelled out
                         here too and wrapped into three ragged lines saying what the
                         four rows underneath say better. -->
                    <p class="text-xs sm:text-sm <?= $hdrText ?> mt-1">
                        <span class="font-bold"><?= $n ?></span> open line<?= $n === 1 ? '' : 's' ?>
                        across <span class="font-bold"><?= $all['tickets'] ?></span> ticket<?= $all['tickets'] === 1 ? '' : 's' ?>.
                        Pick a group to filter the worklist to exactly those lines.
                    </p>
                </div>
                <button type="button" class="sla-alert-close <?= $hdrClose ?> p-1 rounded focus:outline-none" title="Close">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="overflow-y-auto overscroll-contain p-4 sm:px-5 sm:py-4 space-y-4">
                <?php
                // Reported section by section rather than as four flat numbers, so
                // the reader can tell at a glance how much of the backlog has not
                // been started at all (Outstanding) from how much is merely
                // unfinished (On Progress) -- two different follow-ups.
                $sections = [
                    'Outstanding' => 'Nothing canvassed on these lines yet.',
                    'On Progress' => 'Canvassing started, no PO raised yet.',
                ];
                foreach ($sections as $section => $sectionHint):
                    $secRows = array_merge($part[$section . '|overdue'], $part[$section . '|due_soon']);
                    if (empty($secRows)) continue;
                    $sec      = sla_alert_stats($secRows);
                    $secBadge = $section === 'Outstanding'
                        ? 'bg-red-100 text-red-800 border-red-200'
                        : 'bg-blue-100 text-blue-800 border-blue-200';
                ?>
                <section>
                    <!-- Section totals sit hard against the right edge so they line
                         up with the block figures below them rather than trailing off
                         after a caption of variable length. The caption drops to its
                         own line, which is also what keeps this from wrapping badly
                         on a phone. -->
                    <div class="pb-2 mb-2.5 border-b border-gray-200">
                        <div class="flex items-baseline gap-2">
                            <span class="<?= $secBadge ?> text-[10px] font-bold px-2 py-0.5 rounded border uppercase tracking-wider"><?= $e($section) ?></span>
                            <span class="ml-auto text-xs text-gray-500 whitespace-nowrap">
                                <span class="font-bold text-gray-700"><?= $sec['lines'] ?></span> line<?= $sec['lines'] === 1 ? '' : 's' ?>
                                <span class="text-gray-400 mx-1">&middot;</span>
                                <span class="font-bold text-gray-700"><?= $sec['tickets'] ?></span> ticket<?= $sec['tickets'] === 1 ? '' : 's' ?>
                            </span>
                        </div>
                        <!-- Above the rule, not below it: under the rule this reads as a
                             caption for the first block rather than for the section. -->
                        <p class="text-[11px] text-gray-400 mt-1"><?= $e($sectionHint) ?></p>
                    </div>

                    <div class="space-y-2">
                    <?php
                    // Red first, always -- see the block order at the top of this file.
                    $blocks = [
                        'overdue'  => ['overdue',              'is-overdue'],
                        'due_soon' => ['due today or tomorrow', 'is-due-soon'],
                    ];
                    foreach ($blocks as $slaKey => $blockDef):
                        list($blockWord, $blockCls) = $blockDef;
                        $rows = $part[$section . '|' . $slaKey];
                        if (empty($rows)) continue;
                        $st = sla_alert_stats($rows);
                    ?>
                        <button type="button" class="sla-alert-block <?= $blockCls ?>"
                                data-sla-status="<?= $e($section) ?>" data-sla-bucket="<?= $e($slaKey) ?>"
                                title="Filter the worklist to <?= $e($section) ?> lines that are <?= $e($blockWord) ?>">
                            <span class="min-w-0">
                                <span class="sla-alert-figure block">
                                    <?= $st['lines'] ?> <span class="u">line<?= $st['lines'] === 1 ? '' : 's' ?></span><span class="sep">&middot;</span><?= $st['tickets'] ?> <span class="u">ticket<?= $st['tickets'] === 1 ? '' : 's' ?></span>
                                </span>
                                <?php if ($slaKey === 'overdue' && $st['max'] !== null): ?>
                                    <!-- Working days, matching the row's own SLA badge and tooltip. -->
                                    <span class="sla-alert-detail block">
                                        Max <b><?= (int)$st['max'] ?></b> day<?= (int)$st['max'] === 1 ? '' : 's' ?> overdue
                                        &middot; avg <b><?= number_format($st['avg'], 1) ?></b> days
                                    </span>
                                <?php else: ?>
                                    <span class="sla-alert-detail block">Due today or tomorrow &middot; 1 or 0 working days left</span>
                                <?php endif; ?>
                            </span>
                            <svg class="sla-alert-go h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                        </button>
                    <?php endforeach; ?>
                    </div>
                </section>
                <?php endforeach; ?>

                <?php if ($overlap): ?>
                <p class="text-[11px] text-gray-400 pt-1">
                    Ticket counts are per group and do not add up to <?= $all['tickets'] ?>: a ticket with
                    lines in more than one group is counted once in each.
                </p>
                <?php endif; ?>
            </div>

            <div class="p-4 border-t bg-gray-50 rounded-b-xl flex justify-end">
                <button type="button" class="sla-alert-close w-full sm:w-auto px-4 py-2 text-sm font-bold text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 focus:outline-none">Dismiss</button>
            </div>
        </div>
    </div>

    <script>
    // No DOMContentLoaded wrapper on purpose: this runs the moment the parser
    // reaches it, so the cue is already live and the close controls already work
    // while the rest of the worklist (and jQuery, and DataTables) is still loading.
    (function () {
        var modal      = document.getElementById('slaAlertModal');
        var cue        = document.getElementById('slaAlertCue');
        var autoPending = <?= $autoshow ? 'true' : 'false' ?>;
        var dismissed  = false;
        var pending    = null;   // a block clicked before the worklist was ready

        function show() {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            // Force a style flush rather than waiting on requestAnimationFrame: rAF
            // callbacks queue behind whatever the main thread is doing, so on a busy
            // page the pop-up would sit at opacity 0 for a while. Opacity and
            // transform then animate on the compositor and stay smooth regardless.
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
            try { fetch('ajax_sla_alert_dismiss.php', { method: 'POST', keepalive: true }).catch(function () {}); } catch (e) {}
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
            // A block pressed while the worklist was still building: the click was
            // remembered rather than dropped, so honour it now that it can work.
            if (pending && window.slaAlertApplyFilter) {
                var p = pending; pending = null;
                window.slaAlertApplyFilter(p.status, p.bucket);
            }
            if (!autoPending) { dropCue(); return; }
            autoPending = false;
            dropCue();
            show();   // same tick, on purpose: a timer here would queue behind
                      // whatever the page is still busy with, stretching the cue
                      // out past the moment it was supposed to hand over.
        }

        // Backdrop click closes; clicks inside the panel must not bubble out.
        modal.addEventListener('click', function (ev) { if (ev.target === modal) hide(); });
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') hide(); });
        // Delegated, so every control works no matter where it sits in the page
        // relative to this script and no matter how much is still loading.
        document.addEventListener('click', function (ev) {
            var t = ev.target.closest
                ? ev.target.closest('.sla-alert-close, #slaAlertOpen, .sla-alert-block')
                : null;
            if (!t) return;
            if (t.id === 'slaAlertOpen') { show(); return; }
            if (t.classList.contains('sla-alert-block')) {
                var status = t.getAttribute('data-sla-status');
                var bucket = t.getAttribute('data-sla-bucket');
                hide();
                // Bound here rather than beside the table so these buttons are never
                // dead and never have to be hidden. A page that registers no handler
                // (or a worklist still building) just closes; the click is replayed
                // from ready() once the handler exists.
                if (window.slaAlertApplyFilter) { window.slaAlertApplyFilter(status, bucket); }
                else { pending = { status: status, bucket: bucket }; }
                return;
            }
            hide();
        });

        // The cue should last exactly as long as the page really takes to become
        // usable -- no arbitrary timer, and explicitly NOT window.load, which also
        // waits on images and CDN assets long after the worklist is interactive.
        // That wait is what made the cue overstay.
        //
        // requestAnimationFrame is the honest signal. While DataTables is building
        // the worklist it holds the main thread and no frame is delivered at all;
        // once frames arrive at a normal cadence again the page really is ready,
        // and that is the moment to hand over. Roughly 50ms of calm is enough.
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
        // arriving. Deliberately generous, because a ceiling that undercuts a
        // genuinely slow worklist would fire mid-build and pop the warning while
        // the page is still working, which is the very thing it is meant to avoid.
        setTimeout(ready, 20000);

        window.slaAlert = { show: show, hide: hide, ready: ready };
    })();
    </script>
    <?php
}
