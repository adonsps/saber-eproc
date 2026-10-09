<?php
// includes/sla_rules.php
//
// Single source of truth for the *configurable* SLA targets (in working days)
// and for turning a PR row into a dashboard-ready SLA verdict.
//
// The targets used to be hardcoded in sla_helper.php as `Intercompany ? 3 : 4`.
// They now live in the `sla_rules` table and are editable from
// pages/settings_sla.php, while resolution stays here so the SLA Report and
// every role dashboard can never disagree about a ticket's target.
//
// Resolution order for one PR line:
//   0. Canvassing Description starts with a work-type code written by STT
//      ([PR]/[PP]/[PM]/[OS]) -> that code's rule. This WINS OVER EVERYTHING
//      below, Intercompany included.
//   1. ticket_type is Intercompany / Intercompany New Store -> `intercompany`
//   2. otherwise look the P.Group up in the whitelist (allowed_pgroups.label):
//        'Team GP Project'     -> `gp_project`
//        'Team GP Non Project' -> `gp_non_project`
//   3. P.Group not whitelisted / unknown -> `default`
//
// Step 0 only kicks in once canvassing exists -- there is no description before
// that -- so a line's target can legitimately change from its P.Group default to
// its coded one the moment the canvassing is raised. That is the rule as
// specified, not drift.
//
// Every read fails soft: if the `sla_rules` table has not been deployed yet the
// hardcoded defaults below are used, which reproduce the previous behaviour
// exactly (3 days intercompany, 4 days everything non-project).

const SLA_RULE_INTERCOMPANY   = 'intercompany';
const SLA_RULE_GP_PROJECT     = 'gp_project';
const SLA_RULE_GP_NON_PROJECT = 'gp_non_project';
const SLA_RULE_DEFAULT        = 'default';

// Work-type codes STT writes at the START of the Canvassing Description.
const SLA_RULE_CANV_PR = 'canv_pr'; // [PR] Project Retail
const SLA_RULE_CANV_PP = 'canv_pp'; // [PP] Project Property
const SLA_RULE_CANV_PM = 'canv_pm'; // [PM] Project Maintenance
const SLA_RULE_CANV_OS = 'canv_os'; // [OS] Outsourcing (non project)

// Canonical rule catalogue: order, display copy and the fallback day counts used
// whenever the table is missing or a row has not been seeded.
function sla_rule_catalogue() {
    return [
        SLA_RULE_INTERCOMPANY => [
            'label' => 'Intercompany',
            'hint'  => 'Type Intercompany or Intercompany New Store, any P.Group.',
            'days'  => 3,
            'sort'  => 1,
        ],
        SLA_RULE_GP_PROJECT => [
            'label' => 'Team GP Project',
            'hint'  => 'P.Group labelled "Team GP Project" on the whitelist.',
            'days'  => 3,
            'sort'  => 2,
        ],
        SLA_RULE_GP_NON_PROJECT => [
            'label' => 'Team GP Non Project',
            'hint'  => 'P.Group labelled "Team GP Non Project" on the whitelist.',
            'days'  => 4,
            'sort'  => 3,
        ],
        SLA_RULE_DEFAULT => [
            'label' => 'Unmapped P.Group',
            'hint'  => 'Fallback: P.Group not on the whitelist and no PIC assigned.',
            'days'  => 4,
            'sort'  => 4,
        ],
        SLA_RULE_CANV_PR => [
            'label' => 'Project Retail',
            'hint'  => 'Canvassing Description starts with [PR]. Overrides Intercompany and the P.Group label.',
            'days'  => 3,
            'sort'  => 5,
        ],
        SLA_RULE_CANV_PP => [
            'label' => 'Project Property',
            'hint'  => 'Canvassing Description starts with [PP]. Overrides Intercompany and the P.Group label.',
            'days'  => 3,
            'sort'  => 6,
        ],
        SLA_RULE_CANV_PM => [
            'label' => 'Project Maintenance',
            'hint'  => 'Canvassing Description starts with [PM]. Overrides Intercompany and the P.Group label.',
            'days'  => 7,
            'sort'  => 7,
        ],
        SLA_RULE_CANV_OS => [
            'label' => 'Outsourcing (Non Project)',
            'hint'  => 'Canvassing Description starts with [OS]. Overrides Intercompany and the P.Group label.',
            'days'  => 12,
            'sort'  => 8,
        ],
    ];
}

// The work-type code STT writes at the START of the Canvassing Description, as a
// rule key -- or null when the line carries no recognised code, which sends
// sla_rule_key_for() on to the ticket_type / P.Group rules as before.
//
// Square brackets are the agreed format ([PM] ...); round brackets are accepted
// too because the change was first specified that way. The pattern is anchored at
// the start of the string on purpose: an unanchored "PR" or "OS" would match
// inside ordinary words and silently rewrite a ticket's SLA.
function sla_canvassing_rule_key($canvassing_description) {
    $text = ltrim((string)$canvassing_description);
    if ($text === '') return null;
    if (!preg_match('/^[\[\(]\s*(PR|PP|PM|OS)\s*[\]\)]/i', $text, $m)) return null;

    $map = [
        'PR' => SLA_RULE_CANV_PR,
        'PP' => SLA_RULE_CANV_PP,
        'PM' => SLA_RULE_CANV_PM,
        'OS' => SLA_RULE_CANV_OS,
    ];
    return $map[strtoupper($m[1])];
}

// The code itself ('[PM]') for display, or '' when there is none. Kept beside the
// parser so the badge/tooltip never re-implements the pattern.
function sla_canvassing_code($canvassing_description) {
    $key = sla_canvassing_rule_key($canvassing_description);
    if ($key === null) return '';
    $codes = [
        SLA_RULE_CANV_PR => '[PR]',
        SLA_RULE_CANV_PP => '[PP]',
        SLA_RULE_CANV_PM => '[PM]',
        SLA_RULE_CANV_OS => '[OS]',
    ];
    return $codes[$key];
}

// Idempotent create + seed. Called from the settings page (manager-gated) so the
// feature bootstraps itself on an environment where the table has not shipped.
function sla_rules_bootstrap(PDO $pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sla_rules (
                rule_key   VARCHAR(50)  NOT NULL,
                rule_label VARCHAR(120) NOT NULL,
                sla_days   INT          NOT NULL DEFAULT 4,
                sort_order INT          NOT NULL DEFAULT 0,
                updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (rule_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $ins = $pdo->prepare("INSERT IGNORE INTO sla_rules (rule_key, rule_label, sla_days, sort_order) VALUES (?, ?, ?, ?)");
        foreach (sla_rule_catalogue() as $key => $def) {
            $ins->execute([$key, $def['label'], $def['days'], $def['sort']]);
        }
        return true;
    } catch (PDOException $e) {
        error_log('sla_rules_bootstrap: ' . $e->getMessage());
        return false;
    }
}

// rule_key => days. Memoized per request; pass $force to re-read after a save.
function sla_rules_load(PDO $pdo, $force = false) {
    static $cache = null;
    if ($cache !== null && !$force) return $cache;

    $rules = [];
    foreach (sla_rule_catalogue() as $key => $def) { $rules[$key] = (int)$def['days']; }

    try {
        foreach ($pdo->query("SELECT rule_key, sla_days FROM sla_rules")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $days = (int)$r['sla_days'];
            if ($days > 0 && isset($rules[$r['rule_key']])) $rules[$r['rule_key']] = $days;
        }
    } catch (PDOException $e) {
        // Table not deployed yet -- keep the catalogue defaults.
    }

    return $cache = $rules;
}

// Full rows for the settings screen (label/hint/days), catalogue-ordered.
function sla_rules_for_settings(PDO $pdo) {
    $days = sla_rules_load($pdo, true);
    $rows = [];
    foreach (sla_rule_catalogue() as $key => $def) {
        $rows[] = [
            'rule_key'   => $key,
            'rule_label' => $def['label'],
            'hint'       => $def['hint'],
            'sla_days'   => $days[$key],
        ];
    }
    return $rows;
}

function sla_rules_update(PDO $pdo, $rule_key, $days) {
    $days = (int)$days;
    if (!array_key_exists($rule_key, sla_rule_catalogue())) return false;
    if ($days < 1 || $days > 365) return false;
    try {
        $def = sla_rule_catalogue()[$rule_key];
        $stmt = $pdo->prepare("
            INSERT INTO sla_rules (rule_key, rule_label, sla_days, sort_order) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE sla_days = VALUES(sla_days)
        ");
        $stmt->execute([$rule_key, $def['label'], $days, $def['sort']]);
        return true;
    } catch (PDOException $e) {
        error_log('sla_rules_update: ' . $e->getMessage());
        return false;
    }
}

// P.Group code (upper-cased) => whitelist label. Memoized per request.
function sla_pgroup_labels(PDO $pdo, $force = false) {
    static $cache = null;
    if ($cache !== null && !$force) return $cache;

    $map = [];
    try {
        foreach ($pdo->query("SELECT pgroup_code, label FROM allowed_pgroups")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[strtoupper(trim((string)$r['pgroup_code']))] = trim((string)$r['label']);
        }
    } catch (PDOException $e) {
        // Whitelist unavailable -- everything non-intercompany falls back to `default`.
    }
    return $cache = $map;
}

// company_holidays.id is a PRIMARY KEY that was created without AUTO_INCREMENT,
// so every INSERT omitting it lands on id = 0. The first "Add Holiday" worked by
// luck; every one after it collided on the PK and was swallowed silently by the
// INSERT IGNORE. Holidays now drive the dashboard SLA countdown, so being unable
// to add more than one is a real defect -- this repairs the column in place.
// Idempotent, manager-gated (called from settings_sla.php) and safe to re-run.
function sla_holidays_ensure_schema(PDO $pdo) {
    try {
        $col = $pdo->query("SHOW COLUMNS FROM company_holidays LIKE 'id'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) return false;
        if (stripos($col['Extra'] ?? '', 'auto_increment') !== false) return true;

        // Re-home any row already parked on id = 0 so the ALTER cannot trip over it.
        $max = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM company_holidays")->fetchColumn();
        $pdo->exec("UPDATE company_holidays SET id = " . ($max + 1) . " WHERE id = 0");
        $pdo->exec("ALTER TABLE company_holidays MODIFY id INT NOT NULL AUTO_INCREMENT");
        return true;
    } catch (PDOException $e) {
        error_log('sla_holidays_ensure_schema: ' . $e->getMessage());
        return false;
    }
}

// Company holidays (settings_sla.php) as a sorted list of 'Y-m-d' strings.
// Memoized per request; the list is small, so range checks below just scan it.
function sla_holidays(PDO $pdo, $force = false) {
    static $cache = null;
    if ($cache !== null && !$force) return $cache;

    $days = [];
    try {
        foreach ($pdo->query("SELECT holiday_date FROM company_holidays")->fetchAll(PDO::FETCH_COLUMN) as $h) {
            $d = substr((string)$h, 0, 10);
            if ($d !== '' && $d !== '0000-00-00') $days[$d] = true;
        }
    } catch (PDOException $e) {
        // Holiday table unavailable -- every calendar day counts.
    }
    $days = array_keys($days);
    sort($days);
    return $cache = $days;
}

// Weekdays (Mon-Fri) in the inclusive span [$fromTs, $toTs]. Closed form rather
// than a day-by-day loop, so a two-year-old PR costs the same as a fresh one.
function sla_count_weekdays($fromTs, $toTs) {
    if ($toTs < $fromTs) return 0;
    $n     = (int)round(($toTs - $fromTs) / 86400) + 1;
    $count = intdiv($n, 7) * 5;
    $dow   = (int)date('N', $fromTs); // 1 = Mon ... 7 = Sun
    for ($i = 0, $rem = $n % 7; $i < $rem; $i++) {
        if ((($dow - 1 + $i) % 7) + 1 < 6) $count++;
    }
    return $count;
}

// SLA days consumed between $start and $end: WORKING days only -- weekends never
// count, and neither does any date on the company holiday calendar
// (settings_sla.php -> Calendar Holidays).
//
// The window is (start, end]: the PR date itself is day 0, and each following
// working day burns one SLA day. Memoized per date pair because a dashboard
// renders thousands of rows over a handful of distinct dates.
function sla_elapsed_days(PDO $pdo, $start, $end) {
    static $memo = [];
    $key = $start . '|' . $end;
    if (isset($memo[$key])) return $memo[$key];

    $startTs = strtotime($start);
    $endTs   = strtotime($end);
    if ($startTs === false || $endTs === false) return $memo[$key] = null;

    // Floored at 0 so a canvassing date that predates the PR date (bad source
    // data) never reads as extra headroom.
    $fromTs = $startTs + 86400; // first day that can burn SLA
    if ($endTs < $fromTs) return $memo[$key] = 0;

    $elapsed = sla_count_weekdays($fromTs, $endTs);

    // Drop holidays inside the window. Only weekday holidays matter -- a holiday
    // falling on a weekend was never counted in the first place.
    $from = date('Y-m-d', $fromTs);
    $to   = date('Y-m-d', $endTs);
    foreach (sla_holidays($pdo) as $h) {
        if ($h < $from) continue;
        if ($h > $to) break;               // list is sorted
        if ((int)date('N', strtotime($h)) < 6) $elapsed--;
    }

    return $memo[$key] = max(0, $elapsed);
}

function sla_is_intercompany($ticket_type) {
    return in_array(strtolower(trim((string)$ticket_type)), ['intercompany', 'intercompany new store'], true);
}

// The rule key that applies to one PR line. $canvassing_description is last and
// optional so the pre-existing three-argument callers keep working (they simply
// resolve as they always did, on ticket_type + P.Group).
function sla_rule_key_for($ticket_type, $p_group, array $pgroup_labels, $canvassing_description = '') {
    // The STT code wins outright -- it is the most specific statement anyone has
    // made about what kind of work this line is.
    $canv = sla_canvassing_rule_key($canvassing_description);
    if ($canv !== null) return $canv;

    if (sla_is_intercompany($ticket_type)) return SLA_RULE_INTERCOMPANY;

    $label = $pgroup_labels[strtoupper(trim((string)$p_group))] ?? '';
    if ($label === 'Team GP Project')     return SLA_RULE_GP_PROJECT;
    if ($label === 'Team GP Non Project') return SLA_RULE_GP_NON_PROJECT;
    return SLA_RULE_DEFAULT;
}

// Days configured for one rule key, with the same fail-soft fallback everywhere.
function sla_days_for_rule(array $rules, $rule_key) {
    return (int)($rules[$rule_key] ?? $rules[SLA_RULE_DEFAULT] ?? 4);
}

// Target working days for one PR line.
function sla_target_days($ticket_type, $p_group, array $rules, array $pgroup_labels, $canvassing_description = '') {
    return sla_days_for_rule($rules, sla_rule_key_for($ticket_type, $p_group, $pgroup_labels, $canvassing_description));
}

// Turns one PR row into the countdown the dashboards render.
//
// The column shows DAYS REMAINING, not days used: `remaining = target - elapsed`,
// where `elapsed` is WORKING days from the PR date -- weekends and the company
// holiday calendar are both skipped (see sla_elapsed_days). A PR raised today on a
// 3-day target shows "3 days"; the same PR three working days later shows
// "0 days"; a working day after that, "-1 day".
//
// Elapsed stops counting once canvassing is approved, so a finished line freezes
// at the headroom it had left (or how far past the target it landed) instead of
// drifting further negative forever.
//
// Colour follows the remaining count:
//   remaining < 0   -> red    (past the target)
//   remaining 0 or 1 -> yellow (due today or tomorrow)
//   remaining >= 2  -> green  (comfortable)
//
// A PO already raised (po_number present) closes the line for SLA purposes and
// wins over every other check below -- once the PO exists the countdown is no
// longer relevant, whatever the PR date situation was.
//
// Returns ['elapsed','remaining','target','status','label','badge_class','sort'];
// `sort` is the remaining count, so an ascending sort floats the most urgent rows
// to the top; 'na' rows sink to the bottom and 'done' rows sink below those.
function sla_evaluate(PDO $pdo, array $row, array $rules, array $pgroup_labels) {
    $canv_desc = $row['canvassing_description'] ?? '';
    $rule_key  = sla_rule_key_for($row['ticket_type'] ?? '', $row['p_group'] ?? '', $pgroup_labels, $canv_desc);
    $target    = sla_days_for_rule($rules, $rule_key);

    // Which rule produced the target, so the tooltip can say so instead of the
    // reader having to reverse-engineer it from the day count.
    $catalogue = sla_rule_catalogue();
    $rule_meta = [
        'rule_key'   => $rule_key,
        'rule_label' => $catalogue[$rule_key]['label'] ?? $rule_key,
        'canv_code'  => sla_canvassing_code($canv_desc),
    ];

    // A cancelled canvassing closes the line for good -- it will not be
    // progressed to a PO -- so the SLA countdown stops here and the line is
    // treated as done: never counted as overdue / due-soon, and sunk to the
    // bottom of an urgency sort. Checked before the PO branch because a
    // cancelled line usually has no PO to fall back on. canvassing_status is the
    // same signal the dashboards' smart-status uses for 'Canvassing Cancelled';
    // callers that want this must SELECT it (all the worklist dashboards do).
    if (strpos(strtolower(trim((string)($row['canvassing_status'] ?? ''))), 'cancel') !== false) {
        return $rule_meta + [
            'elapsed' => null, 'remaining' => null, 'target' => $target, 'status' => 'done',
            'label' => 'Cancelled', 'badge_class' => 'bg-gray-300 text-gray-700 border-gray-400', 'sort' => 10000,
        ];
    }

    if (trim((string)($row['po_number'] ?? '')) !== '') {
        return $rule_meta + [
            'elapsed' => null, 'remaining' => null, 'target' => $target, 'status' => 'done',
            'label' => 'Done', 'badge_class' => 'bg-gray-300 text-gray-700 border-gray-400', 'sort' => 10000,
        ];
    }

    $na = $rule_meta + [
        'elapsed' => null, 'remaining' => null, 'target' => $target, 'status' => 'na',
        'label' => 'N/A', 'badge_class' => 'bg-gray-100 text-gray-500 border-gray-200', 'sort' => 9999,
    ];

    // The countdown is anchored on the PR date, which is the column it sits beside.
    $start = trim((string)($row['pr_date'] ?? '')) ?: trim((string)($row['tr_date_gp'] ?? ''));
    if ($start === '' || strpos($start, '0000-00-00') === 0) return $na;

    $end = trim((string)($row['canvassing_date_approver_1'] ?? '')) ?: trim((string)($row['canvassing_date'] ?? ''));
    if ($end === '' || strpos($end, '0000-00-00') === 0) $end = date('Y-m-d');

    $elapsed = sla_elapsed_days($pdo, substr($start, 0, 10), substr($end, 0, 10));
    if ($elapsed === null) return $na;

    $remaining = $target - $elapsed;

    if ($remaining < 0) {
        $status = 'overdue';
        $badge  = 'bg-red-100 text-red-800 border-red-200';
    } elseif ($remaining <= 1) {
        $status = 'due_soon';
        $badge  = 'bg-yellow-100 text-yellow-800 border-yellow-200';
    } else {
        $status = 'on_track';
        $badge  = 'bg-green-100 text-green-800 border-green-200';
    }

    return $rule_meta + [
        'elapsed'     => $elapsed,
        'remaining'   => $remaining,
        'target'      => $target,
        'status'      => $status,
        'label'       => $remaining . ' ' . (abs($remaining) === 1 ? 'day' : 'days'),
        'badge_class' => $badge,
        'sort'        => $remaining,
    ];
}

// Which rule set this line's target, as one sentence appended to every tooltip.
// Written out because the same day count can come from two different rules, and
// a coded line is the one case where the target does not follow the P.Group.
function sla_rule_sentence($eval) {
    $label = (string)($eval['rule_label'] ?? '');
    if ($label === '') return '';
    $code = (string)($eval['canv_code'] ?? '');
    return ' Rule: ' . $label . ' (' . $eval['target'] . ' days)'
        . ($code !== '' ? ', from the ' . $code . ' code in the Canvassing Description' : '') . '.';
}

// Human-readable tooltip for the badge.
function sla_tooltip($eval) {
    if ($eval['status'] === 'done') {
        return 'PO already created -- the SLA countdown is closed for this line (target was ' . $eval['target'] . ' days).' . sla_rule_sentence($eval);
    }
    if ($eval['status'] === 'na') {
        return 'No PR date on this line, the SLA countdown cannot start (target ' . $eval['target'] . ' days).' . sla_rule_sentence($eval);
    }
    $base = $eval['target'] . '-day SLA, ' . $eval['elapsed'] . ' working day(s) used since the PR date (weekends and holidays excluded). ';
    if ($eval['remaining'] < 0)   return $base . abs($eval['remaining']) . ' day(s) past the target.' . sla_rule_sentence($eval);
    if ($eval['remaining'] === 0) return $base . 'Due today.' . sla_rule_sentence($eval);
    return $base . $eval['remaining'] . ' day(s) left.' . sla_rule_sentence($eval);
}

// Server-rendered badge used by the non-AJAX dashboards.
function sla_badge_html($eval) {
    return '<span class="' . $eval['badge_class'] . ' py-1 px-2 rounded-md text-[10px] font-bold border inline-block w-full text-center" title="'
        . htmlspecialchars(sla_tooltip($eval), ENT_QUOTES) . '">' . htmlspecialchars($eval['label']) . '</span>';
}

// Attaches sla_* keys to every row of a fetched PR set, in place.
function sla_attach(PDO $pdo, array &$rows) {
    if (empty($rows)) return;
    $rules  = sla_rules_load($pdo);
    $labels = sla_pgroup_labels($pdo);
    foreach ($rows as &$r) {
        $eval = sla_evaluate($pdo, $r, $rules, $labels);
        $r['sla_elapsed']   = $eval['elapsed'];
        $r['sla_remaining'] = $eval['remaining'];
        $r['sla_target']    = $eval['target'];
        $r['sla_status']    = $eval['status'];
        $r['sla_label']     = $eval['label'];
        $r['sla_sort']      = $eval['sort'];
        $r['sla_title']     = sla_tooltip($eval);
        $r['sla_badge']     = $eval['badge_class'];
        $r['sla_rule_key']   = $eval['rule_key'];
        $r['sla_rule_label'] = $eval['rule_label'];
        $r['sla_canv_code']  = $eval['canv_code'];
    }
    unset($r);
}
