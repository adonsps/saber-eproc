<?php
// includes/sla_revision_helper.php
//
// SLA REVISION WORKFLOW — shared business logic
// ------------------------------------------------------------------
// A PIC can never edit SLA data directly. They submit a request; their direct
// superior (users.manager_username) approves or rejects it; only an approval
// changes what SLA Tracking shows. Every step is recorded permanently.
//
// Two request types:
//   date        -> adjust TR Date Technical and/or Canvassing Date Approver 1 (the SLA
//                  clock start and stop) in ONE request, so one approval settles
//                  both instead of the PIC filing two and the Manager reading the
//                  same justification twice
//   sla_status  -> override the computed verdict WITHOUT touching any date
//
// 'pr_date' and 'canvassing_date' are legacy: one date per request, replaced by
// 'date'. Nothing creates them anymore, but they are still labelled and rendered
// here because the audit trail is append-only and old rows must stay readable.
//
// Everything here is shared by:
//   pages/ajax_sla_request_submit.php   (PIC submits)
//   pages/ajax_sla_request_decide.php   (Manager approves/rejects)
//   pages/ajax_sla_request_history.php  (audit trail for one PR line)
//   pages/sla_report.php                (Manager approval inbox, folded in as a modal)
//   includes/sla_helper.php             (layers approved overrides onto the rows)
// so the rules can never drift between where they are shown and where they are
// enforced. Schema + rationale: db_backups/sla_revision_workflow_01_schema.sql.

// The ONE list of SLA statuses this workflow deals in. It answers two questions
// at once, deliberately:
//   1. which statuses a request may ASK FOR, and
//   2. which computed statuses are ELIGIBLE to be overridden at all.
//
// 'In Progress' is excluded because it means canvassing_date_approver_1 is still
// empty — the SLA verdict has not been reached yet, so there is nothing to
// override. 'AON' and 'Out of Scope' are excluded because they are structural
// categories (no canvassing but a PO / Low Value OPEX), not performance verdicts.
//
// Used by the request modal (to disable the option), by the submit endpoint (to
// reject), and by the override layer in get_sla_computed_rows() (to go dormant),
// which is why it lives in exactly one place.
function sla_rev_status_options() {
    return ['Within SLA', 'SLA Breached'];
}

// Human labels for request_type, shared by every screen that renders one.
// Includes the legacy types so historical rows still render with a real label.
function sla_rev_type_labels() {
    return [
        'date'            => 'Date Revision',
        'sla_status'      => 'SLA Status Override',
        'pr_date'         => 'PR Date (TR Date Technical)',   // legacy
        'canvassing_date' => 'Canvassing Approval Date',      // legacy
    ];
}

// The types a NEW request may use. Narrower than sla_rev_type_labels() on
// purpose: the legacy types can be displayed but never submitted again.
function sla_rev_requestable_types() {
    return ['date', 'sla_status'];
}

function sla_rev_type_label($type) {
    $labels = sla_rev_type_labels();
    return $labels[$type] ?? (string)$type;
}

// Composite key for a PR line. `prs` has no surrogate id — its primary key is
// (ticket_no, ticket_line_item) — so every map in this file is keyed this way.
function sla_rev_line_key($ticket_no, $ticket_line_item) {
    return trim((string)$ticket_no) . '|' . trim((string)$ticket_line_item);
}

/**
 * Every active override, keyed by sla_rev_line_key().
 *
 * Memoized per request the same way load_project_team_map() is: the SLA report
 * and its ajax endpoints each build the full row set once, and every row needs
 * a lookup, so this must not become an N+1.
 *
 * Fails soft (returns []) if the table does not exist yet — the workflow simply
 * has no effect, and the report behaves exactly as it did before this feature.
 */
function sla_rev_load_overrides($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    try {
        $rows = $pdo->query("
            SELECT ticket_no, ticket_line_item, override_pr_date, override_canvassing_date,
                   override_sla_status, last_request_id, approved_by_name, approved_at
            FROM sla_revision_overrides
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $cache[sla_rev_line_key($r['ticket_no'], $r['ticket_line_item'])] = $r;
        }
    } catch (PDOException $e) {
        $cache = [];
    }
    return $cache;
}

/**
 * Legacy-aware aliases for "this counts as a $type pending request": a 'date'
 * request also matches the old single-date 'pr_date'/'canvassing_date' rows,
 * so a still-pending legacy request is recognized as "the date one" for
 * per-field tooltip text (sla_shape_row_edit_state()'s $pd map).
 *
 * Does NOT decide whether a new request is blocked — a line with ANY pending
 * request (either type) blocks BOTH date and status edits, checked directly
 * via $has_pending in sla_rev_line_editability() and, server-side, by the
 * any-type check in ajax_sla_request_submit.php step 4.
 */
function sla_rev_blocking_types($type) {
    return ($type === 'date')
        ? ['date', 'pr_date', 'canvassing_date']
        : ['sla_status'];
}

/**
 * Every request still waiting for approval, as
 *   sla_rev_line_key() => [ request_type => row, ... ].
 *
 * Same shape and same reasoning as sla_rev_load_overrides() above: memoized per
 * request because every row of the SLA table needs a lookup, and fails soft to
 * [] when the table is not deployed yet — the report then simply shows no
 * pending markers.
 *
 * Nested by request_type because a line may legitimately have a 'date' request
 * and an 'sla_status' request pending at the same time (they are approved
 * independently). ORDER BY id ASC means the newest of a type wins the slot,
 * which is the one the guard in ajax_sla_request_submit.php would find.
 */
function sla_rev_load_pending($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    try {
        $rows = $pdo->query("
            SELECT ticket_no, ticket_line_item, request_type,
                   old_value, old_pr_date, old_canvassing_date,
                   requested_pr_date, requested_canvassing_date, requested_value,
                   remarks, reason, evidence_text, additional_notes,
                   requested_by_name, created_at
            FROM sla_revision_requests
            WHERE status = 'pending'
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $key = sla_rev_line_key($r['ticket_no'], $r['ticket_line_item']);
            $cache[$key][$r['request_type']] = $r;
        }
    } catch (PDOException $e) {
        $cache = [];
    }
    return $cache;
}

/**
 * Does this computed row match the "Adjusted" status filter?
 *
 * 'Adjusted' is NOT a statusName value — it is a cross-cutting question about the
 * revision workflow: has this line been touched by it at all? That means an approved
 * override (of the STATUS, i.e. the purple "Rev" badge, or of one of the DATES) OR a
 * request that was rejected. Rejections count because the filter's job is "show me the
 * lines that went through revision", and a refused request is part of that story —
 * without it those lines are unreachable from the legend.
 *
 * Lives here, called from both sla_gf_filter() and ajax_sla_charts.php: the two used to
 * spell the same rule out separately, which is exactly how they drift apart.
 *
 * Reads $row['rejected_req'], attached by get_sla_computed_rows(). Absent for callers
 * that shape a row from elsewhere, which degrades to "override only" rather than erroring.
 */
function sla_rev_is_adjusted_line($row) {
    return !empty($row['status_overridden'])
        || !empty($row['date_overridden'])
        || !empty($row['rejected_req']);
}

/**
 * Lines whose MOST RECENT revision request was rejected, keyed by line key.
 *
 * Drives the red "View History" marker on SLA Tracking: a rejection is otherwise
 * invisible in the table — nothing about the line changed, which is exactly why the
 * PIC needs a reason to go look at the history.
 *
 * Only the LATEST request counts. A line the PIC re-submitted (now pending) or that
 * was later approved is no longer "rejected", and marking it so would contradict the
 * pending/approved markers already on its cells. Highest id wins: the rows arrive in
 * id order and each line's entry is overwritten as later ones are seen.
 *
 * Fails soft to [] like every other loader here, so SLA Tracking still renders on an
 * install where the revision tables aren't deployed yet.
 */
function sla_rev_load_last_rejected($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;

    $latest = [];
    try {
        $rows = $pdo->query("
            SELECT ticket_no, ticket_line_item, status, request_type,
                   decided_by_name, decided_at, decision_notes
            FROM sla_revision_requests
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $latest[sla_rev_line_key($r['ticket_no'], $r['ticket_line_item'])] = $r;
        }
    } catch (PDOException $e) {
        $latest = [];
    }

    $cache = [];
    foreach ($latest as $key => $r) {
        if ($r['status'] === 'rejected') $cache[$key] = $r;
    }
    return $cache;
}

// The pending request of $type for one line, or null. Resolves through
// sla_rev_blocking_types() so a legacy 'pr_date' request correctly reads as
// "a date request is pending".
function sla_rev_pending_of_type($pending_for_line, $type) {
    if (empty($pending_for_line)) return null;
    foreach (sla_rev_blocking_types($type) as $t) {
        if (!empty($pending_for_line[$t])) return $pending_for_line[$t];
    }
    return null;
}

/**
 * May this line be edited right now, and if not, why?
 *
 * THE eligibility rule, in one place. It is consumed by three things that must
 * never drift apart: the inline-edit cells on SLA Tracking (which decide what to
 * offer), ajax_sla_siblings.php (which decides what to recommend), and
 * sla_rev_requestable_lines(). ajax_sla_request_submit.php re-derives the same
 * verdict from the database on write, so a hand-crafted POST still cannot get
 * past it — this function only decides what the UI shows.
 *
 * $row is a computed row from get_sla_computed_rows(); $pending_for_line is the
 * entry from sla_rev_load_pending() for that line (or null).
 *
 * ELIGIBILITY = SLA Breached, and nothing else. An adjustment exists to contest
 * a breach; a line that met its SLA has nothing to contest.
 */
function sla_rev_line_editability($row, $pending_for_line) {
    $effective = (string)($row['statusName'] ?? '');
    $original  = (string)($row['status_before_override'] ?? '');

    $is_breached  = ($effective === 'SLA Breached');
    $was_breached = ($original === 'SLA Breached'); // an approved override moved it off Breached

    $pending_date   = sla_rev_pending_of_type($pending_for_line, 'date');
    $pending_status = sla_rev_pending_of_type($pending_for_line, 'sla_status');
    $has_pending    = ($pending_date !== null || $pending_status !== null);

    // A status override can only be asked for on a line whose verdict already
    // exists — same expression sla_shape_row_for_client() uses for 'ovr_ok'.
    $status_decided = in_array(($original !== '' ? $original : $effective), sla_rev_status_options(), true);

    // The read-only 'Viewer' role can never request an adjustment, whatever the
    // line's breach state. Done here rather than in the page because all three
    // consumers named above go through this function — the inline-edit cells, the
    // sibling recommendations and sla_rev_requestable_lines() all go quiet at once.
    // Only the two capability flags are forced: pending/breach fields stay honest
    // so a Viewer still SEES that a line has a request waiting.
    // function_exists() guards CLI/cron callers that never load auth.php.
    $viewer_read_only = function_exists('ws_is_viewer') && ws_is_viewer();

    // Pending is reported first: it is the reason the user can actually act on.
    $why = '';
    if ($viewer_read_only) {
        $why = 'Akun Anda bersifat lihat-saja (view only).';
    } elseif (!$is_breached) {
        $why = $was_breached
            ? 'Already adjusted — this line is no longer SLA Breached.'
            : 'This line is not SLA Breached.';
    } elseif ($has_pending) {
        $why = 'A request is still waiting for approval.';
    }

    return [
        // A pending request of EITHER type locks BOTH fields — a line with a
        // pending Date Adjustment must not also accept a new SLA Status Override
        // (or another Date Adjustment) until that first one is decided. Mirrors
        // the single any-type check in ajax_sla_request_submit.php step 4.
        'can_edit_date'   => !$viewer_read_only && $is_breached && !$has_pending,
        'can_edit_status' => !$viewer_read_only && $is_breached && $status_decided && !$has_pending,
        'why'             => $why,
        'pending_date'    => $pending_date,
        'pending_status'  => $pending_status,
        'has_pending'     => $has_pending,
        'was_breached'    => $was_breached,
        'status_decided'  => $status_decided,
    ];
}

// Label for a request's status. Kept here so the approval inbox and the history
// modal always say the same thing.
function sla_rev_status_label($status) {
    switch ((string)$status) {
        case 'pending':  return 'Pending Approval';
        case 'approved': return 'Approved';
        case 'rejected': return 'Rejected';
        default:         return '';
    }
}

/**
 * Resolves the PIC's direct superior from users.manager_username.
 *
 * Returns ['username' => ..., 'name' => ...] or null. null is NOT an error: the
 * request is still accepted and lands in the Category Manager queue instead
 * (approver_username stays NULL). Blocking submission on a missing manager
 * would punish the PIC for a data gap they cannot fix themselves.
 */
function sla_rev_resolve_approver($pdo, $username) {
    $username = trim((string)$username);
    if ($username === '') return null;

    try {
        $stmt = $pdo->prepare("SELECT manager_username FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $manager_username = trim((string)($stmt->fetchColumn() ?: ''));
        if ($manager_username === '') return null;

        $stmt = $pdo->prepare("SELECT username, name FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$manager_username]);
        $manager = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$manager) return null;

        return ['username' => $manager['username'], 'name' => $manager['name']];
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * May this session decide (approve/reject) this request?
 *   - Category Manager: yes, always (already the super-user role elsewhere in
 *     the app, and the fallback queue for requests with no resolved approver).
 *   - Manager: only when they are the snapshotted approver, OR when the request
 *     has no approver on file and the requester is somewhere under them.
 * Everyone else: no.
 *
 * Deliberately strict: a Manager must never be able to decide another
 * Manager's queue by POSTing a request_id directly.
 */
function sla_rev_can_decide($pdo, $session, $request) {
    $role     = $session['role'] ?? '';
    $username = trim((string)($session['username'] ?? ''));

    if ($role === 'Category Manager') return true;
    if ($role !== 'Manager') return false;
    if ($username === '') return false;

    $approver = trim((string)($request['approver_username'] ?? ''));
    if ($approver !== '') {
        return $approver === $username;
    }

    // No approver was resolvable at submit time (the PIC had no
    // manager_username). Fall back to the subordinate tree so an orphaned
    // request is not stuck waiting on a Category Manager who may never look.
    try {
        $subordinates = get_all_subordinates($pdo, $username);
        foreach ($subordinates as $sub) {
            if (($sub['username'] ?? '') === ($request['requested_by_username'] ?? '')) {
                return true;
            }
        }
    } catch (PDOException $e) {
        return false;
    }
    return false;
}

/**
 * Validates a submitted request. Returns [true, $clean] or [false, $errorMessage].
 * Error strings are Indonesian, matching every other user-facing message here.
 *
 * Only shape/format is checked. Whether the PR line is eligible (owned by the
 * requester, evaluable, no pending duplicate) is checked by the endpoint, which
 * needs the database to answer.
 */
function sla_rev_validate($post) {
    $type = trim((string)($post['request_type'] ?? ''));
    if (!in_array($type, sla_rev_requestable_types(), true)) {
        return [false, 'Unrecognized request type.'];
    }

    $clean = [
        'request_type'              => $type,
        'requested_value'           => null,
        'requested_pr_date'         => null,
        'requested_canvassing_date' => null,
    ];

    if ($type === 'date') {
        $pr = trim((string)($post['requested_pr_date'] ?? ''));
        $cv = trim((string)($post['requested_canvassing_date'] ?? ''));
        if ($pr === '' && $cv === '') {
            return [false, 'Fill in at least one date you want to adjust.'];
        }
        foreach (['PR Date' => $pr, 'Canvassing Approval Date' => $cv] as $label => $value) {
            if ($value === '') continue;
            $err = sla_rev_validate_date($value, $label);
            if ($err !== null) return [false, $err];
        }
        $clean['requested_pr_date']         = $pr !== '' ? $pr : null;
        $clean['requested_canvassing_date'] = $cv !== '' ? $cv : null;
    } else {
        $requested = trim((string)($post['requested_value'] ?? ''));
        if ($requested === '') {
            return [false, 'Please select the requested SLA status.'];
        }
        if (!in_array($requested, sla_rev_status_options(), true)) {
            return [false, 'The requested SLA status is not valid.'];
        }
        $clean['requested_value'] = $requested;
    }

    // One free-text field instead of the separate reason / evidence / notes it
    // replaced: the three were answering the same question and the Manager read
    // them as one block anyway.
    $remarks = trim((string)($post['remarks'] ?? ''));
    if (mb_strlen($remarks) < 10) {
        return [false, 'Remarks are required, at least 10 characters.'];
    }
    $clean['remarks'] = $remarks;

    // Optional link (Google Drive / SharePoint / screenshot). When present it
    // must be a real http(s) URL — it is rendered as a clickable link on the
    // approval page, so javascript:/data: schemes must never get through.
    $evidence_url = trim((string)($post['evidence_url'] ?? ''));
    if ($evidence_url !== '') {
        if (!preg_match('#^https?://#i', $evidence_url) || !filter_var($evidence_url, FILTER_VALIDATE_URL)) {
            return [false, 'The evidence link must be a valid URL starting with http:// or https://.'];
        }
        if (mb_strlen($evidence_url) > 500) {
            return [false, 'The evidence link is too long (maximum 500 characters).'];
        }
    }
    $clean['evidence_url'] = $evidence_url !== '' ? $evidence_url : null;

    return [true, $clean];
}

// Returns an Indonesian error string, or null when the date is acceptable.
function sla_rev_validate_date($value, $label) {
    $d = DateTime::createFromFormat('Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) {
        return 'Invalid ' . $label . ' format. Use the YYYY-MM-DD format.';
    }
    // A date far in the future is almost always a typo (e.g. year 20255) and
    // would silently produce an absurd lead time, so reject it up front.
    if ($value > date('Y-m-d', strtotime('+1 year'))) {
        return 'The requested ' . $label . ' is too far in the future.';
    }
    if ($value < '2000-01-01') {
        return 'The requested ' . $label . ' is too far in the past.';
    }
    return null;
}

/**
 * The value changes a request asks for, as a list of
 * ['label' => ..., 'from' => ..., 'to' => ...] with dates already formatted.
 *
 * A 'date' request can carry two changes at once, a status request carries one,
 * and the legacy types carry one in old_value/requested_value. The approval
 * inbox and the History modal both render through here so they can never
 * describe the same request differently.
 */
function sla_rev_change_pairs($r) {
    $fmt = function ($v) {
        if (empty($v)) return '-';
        $ts = strtotime((string)$v);
        return $ts ? date('d M Y', $ts) : '-';
    };

    switch ($r['request_type']) {
        case 'date':
            $pairs = [];
            if (!empty($r['requested_pr_date'])) {
                $pairs[] = ['label' => 'PR Date', 'from' => $fmt($r['old_pr_date']), 'to' => $fmt($r['requested_pr_date'])];
            }
            if (!empty($r['requested_canvassing_date'])) {
                $pairs[] = ['label' => 'Canvassing Date Approver 1', 'from' => $fmt($r['old_canvassing_date']), 'to' => $fmt($r['requested_canvassing_date'])];
            }
            return $pairs;
        case 'pr_date':         // legacy
            return [['label' => 'PR Date', 'from' => $fmt($r['old_value']), 'to' => $fmt($r['requested_value'])]];
        case 'canvassing_date': // legacy
            return [['label' => 'Canvassing Date', 'from' => $fmt($r['old_value']), 'to' => $fmt($r['requested_value'])]];
        default:
            return [['label' => 'SLA Status',
                     'from'  => (string)($r['old_value'] ?? '-') ?: '-',
                     'to'    => (string)($r['requested_value'] ?? '-') ?: '-']];
    }
}

/**
 * What makes two request rows "the same submission".
 *
 * One PIC submission covering 8 line items is 8 rows in sla_revision_requests —
 * it has to be, since each line is approved on its own and gets its own override.
 * Both list screens fold those rows back into the submission they came from, and
 * they must fold identically, so the rule lives here rather than in either page.
 *
 * Everything the requester fills in once for the whole batch is in the key; only
 * the line item and the old_* snapshot (each line's own current value) differ
 * inside a group.
 *
 * Deliberately NOT created_at: a large batch can straddle a second boundary and
 * would split into two look-alike rows. Deliberately IS the decision: two lines
 * approved separately, with different notes, are two outcomes and must not be
 * folded into one.
 */
function sla_rev_group_key($r) {
    return md5(implode('|', [
        $r['ticket_no'], $r['request_type'], $r['requested_by_username'], $r['status'],
        (string)($r['requested_value'] ?? ''), (string)($r['requested_pr_date'] ?? ''),
        (string)($r['requested_canvassing_date'] ?? ''),
        sla_rev_remarks_text($r), (string)($r['evidence_url'] ?? ''),
        (string)($r['decided_at'] ?? ''), (string)($r['decision_notes'] ?? ''),
    ]));
}

// The requester's justification. Legacy rows kept reason / evidence / notes in
// three columns; they are folded into one block so every screen shows the same
// thing regardless of when the request was filed.
function sla_rev_remarks_text($r) {
    $remarks = trim((string)($r['remarks'] ?? ''));
    if ($remarks !== '') return $remarks;

    $parts = [];
    foreach (['reason' => 'Reason', 'evidence_text' => 'Evidence', 'additional_notes' => 'Notes'] as $col => $label) {
        $v = trim((string)($r[$col] ?? ''));
        if ($v !== '') $parts[] = $label . ': ' . $v;
    }
    return implode("\n", $parts);
}

/**
 * Every PR line this user could file a revision against, each with the state of
 * its latest request. Consumed by pages/ajax_sla_siblings.php, which turns it
 * into the "other line items of the same ticket" recommendation on SLA Tracking.
 *
 * ELIGIBILITY = SLA Breached, and nothing else — decided by
 * sla_rev_line_editability(), the same call the inline-edit cells go through.
 * This only decides what to OFFER; ajax_sla_request_submit.php re-checks the
 * same rule before writing, so a hand-crafted POST cannot bypass the list.
 *
 * Lines that are NOT breached are still returned when they carry a request, so a
 * caller can show the state of anything already filed — including requests made
 * before the Breached-only rule existed. Those come back with can_request = false.
 *
 * MUST be called after includes/sla_helper.php is loaded: it uses
 * resolve_sla_persona() / get_sla_computed_rows() from there. (sla_helper.php is what
 * includes THIS file, so requiring it here would be circular — same convention as
 * require_cost_avoidance_access() in includes/auth.php, which documents its
 * dependency rather than requiring it.)
 */
function sla_rev_requestable_lines($pdo, $session) {
    $role = $session['role'] ?? '';
    $name = trim((string)($session['name'] ?? ''));

    // Persona-scoped, exactly like the SLA table: a user can never be offered a
    // line they cannot even see on the report.
    $ctx      = resolve_sla_persona($pdo, $session);
    $computed = get_sla_computed_rows($pdo, $ctx);

    // Latest request per PR line, whoever filed it — this is the LINE's state, not
    // the current user's. Folded in PHP after one ordered pass: sla_revision_requests
    // is an append-only trail measured in requests, not PR lines, so it stays far
    // smaller than `prs` and a per-line subquery would be the more expensive shape.
    $latest = [];
    try {
        // The whole row, not a subset: the request tabs group lines by submission
        // (sla_rev_group_key), which reads the requested values, remarks and the
        // decision — none of which a narrower SELECT would carry.
        $stmt = $pdo->query("SELECT * FROM sla_revision_requests ORDER BY id ASC");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $latest[sla_rev_line_key($r['ticket_no'], $r['ticket_line_item'])] = $r;
        }
    } catch (PDOException $e) {
        $latest = []; // table not deployed yet — every line reads as "not yet filed"
    }

    // The same pending map the SLA table reads, so "already waiting for approval"
    // means one thing across the whole feature.
    $pending = sla_rev_load_pending($pdo);

    $items = [];
    foreach ($computed['rows'] as $row) {
        // A PIC only ever handles their own lines (mirrors the submit endpoint).
        if ($role === 'PIC' && trim((string)($row['assigned_pic'] ?? '')) !== $name) continue;

        $key = sla_rev_line_key($row['ticket_no'], $row['ticket_line_item']);
        $req = $latest[$key] ?? null;

        $effective = (string)$row['statusName'];

        // THE eligibility rule — shared with the inline-edit cells on SLA Tracking.
        $ed = sla_rev_line_editability($row, $pending[$key] ?? null);

        $is_breached  = ($effective === 'SLA Breached');
        $was_breached = $ed['was_breached'];

        if (!$is_breached && !$was_breached && $req === null) continue;

        $items[] = [
            'ticket'      => (string)$row['ticket_no'],
            'line_item'   => trim((string)$row['ticket_line_item']),
            'item'        => trim((string)($row['item_description'] ?? '')),
            'pic'         => trim((string)($row['assigned_pic'] ?? '')) ?: 'Unassigned',
            'status'      => $effective,
            'dur'         => (string)($row['durationDisplay'] ?? ''),
            'd'           => !empty($row['pr_date']) ? date('Y-m-d', strtotime($row['pr_date'])) : '',
            'cv'          => !empty($row['canvassing_date_approver_1']) ? date('Y-m-d', strtotime($row['canvassing_date_approver_1'])) : '',
            'cno'         => trim((string)($row['canvassing_no'] ?? '')),
            // Drives the SLA Status Override option in the request form, same
            // expression sla_shape_row_for_client() uses for the table.
            'ovr_ok'      => $ed['status_decided'],
            'req_status'  => $req ? (string)$req['status'] : '',
            'req_label'   => $req ? sla_rev_status_label($req['status']) : 'Not Requested',
            'req_type'    => $req ? sla_rev_type_label($req['request_type']) : '',
            'req_at'      => $req && !empty($req['created_at']) ? date('d M Y H:i', strtotime($req['created_at'])) : '',
            // Sortable twin of req_at: 'd M Y H:i' sorts alphabetically, not by time.
            'req_at_iso'  => $req && !empty($req['created_at']) ? date('Y-m-d H:i:s', strtotime($req['created_at'])) : '',
            // Submission-level fields. Lines sharing req_key came from ONE submit and
            // are shown as one row on the request tabs; the rest is what that row and
            // its detail view need, so neither has to go back to the database.
            'req_key'     => $req ? sla_rev_group_key($req) : '',
            'req_pairs'   => $req ? sla_rev_change_pairs($req) : [],
            'req_remarks' => $req ? sla_rev_remarks_text($req) : '',
            'req_evid'    => $req ? (string)($req['evidence_url'] ?? '') : '',
            'req_by'      => $req ? (string)$req['requested_by_name'] : '',
            'req_appr'    => $req ? (string)($req['approver_name'] ?? '') : '',
            'dec_by'      => $req ? (string)($req['decided_by_name'] ?? '') : '',
            'dec_at'      => $req && !empty($req['decided_at']) ? date('d M Y H:i', strtotime($req['decided_at'])) : '',
            'dec_notes'   => $req ? (string)($req['decision_notes'] ?? '') : '',
            // Per-type, because a line can have a date request pending while a
            // status override is still free to file (they are approved
            // independently). 'can_request' stays as the "anything at all"
            // answer the sorting and the tabs below are written against.
            'can_edit_date'   => $ed['can_edit_date'],
            'can_edit_status' => $ed['can_edit_status'],
            'can_request' => $ed['can_edit_date'] || $ed['can_edit_status'],
            // Why the button is off, so the UI never has to guess.
            'blocked_why' => $ed['why'],
        ];
    }

    // Still-actionable lines first, then longest-waiting by PR date.
    usort($items, function ($a, $b) {
        if ($a['can_request'] !== $b['can_request']) return $a['can_request'] ? -1 : 1;
        return strcmp($a['d'], $b['d']);
    });

    return $items;
}


/**
 * Writes an approved request into sla_revision_overrides.
 *
 * The ONLY function in the codebase that writes that table — that is what makes
 * "every adjustment requires Manager approval" true by construction rather than
 * by convention. Callers must already be inside a transaction together with the
 * UPDATE that marks the request approved.
 *
 * Only the columns belonging to $request['request_type'] are touched, so an
 * approved SLA Status Override can never disturb a date, and vice versa. A
 * 'date' request writes only the date(s) it actually asked to change.
 */
function sla_rev_apply_approved($pdo, $request, $decided_by_name) {
    $set = [];
    switch ($request['request_type']) {
        case 'date':
            if (!empty($request['requested_pr_date']))         $set['override_pr_date']         = $request['requested_pr_date'];
            if (!empty($request['requested_canvassing_date'])) $set['override_canvassing_date'] = $request['requested_canvassing_date'];
            break;
        case 'sla_status':      $set['override_sla_status']      = $request['requested_value']; break;
        case 'pr_date':         $set['override_pr_date']         = $request['requested_value']; break; // legacy
        case 'canvassing_date': $set['override_canvassing_date'] = $request['requested_value']; break; // legacy
        default:
            throw new RuntimeException('Unknown request_type: ' . $request['request_type']);
    }
    if (empty($set)) {
        throw new RuntimeException('This request carries no applicable value.');
    }

    // Column names come from the switch above, never from input.
    $cols = array_keys($set);
    $assign = [];
    foreach ($cols as $c) $assign[] = "{$c} = VALUES({$c})";

    $sql = "INSERT INTO sla_revision_overrides
                (ticket_no, ticket_line_item, " . implode(', ', $cols) . ", last_request_id, approved_by_name, approved_at)
            VALUES (?, ?, " . implode(', ', array_fill(0, count($cols), '?')) . ", ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                " . implode(",\n                ", $assign) . ",
                last_request_id  = VALUES(last_request_id),
                approved_by_name = VALUES(approved_by_name),
                approved_at      = VALUES(approved_at)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge(
        [$request['ticket_no'], $request['ticket_line_item']],
        array_values($set),
        [$request['id'], $decided_by_name]
    ));
}
?>
