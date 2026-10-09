<?php
// includes/sla_helper.php

// Configurable SLA targets (settings_sla.php -> `sla_rules`), used below to
// replace what used to be a hardcoded `Intercompany ? 3 : 4`.
require_once __DIR__ . '/sla_rules.php';

// Manager-approved SLA adjustments (PIC request -> approval workflow). Loaded
// here because get_sla_computed_rows() below layers the approved overrides on
// top of the raw `prs` values before it computes anything.
require_once __DIR__ . '/sla_revision_helper.php';

function getWorkingDaysDuration($startDate, $endDate, $pdo) {
    if (!$startDate || !$endDate) return null;
    
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    
    // If the dates are inverted due to a data entry error, handle gracefully
    if ($start > $end) return 0;

    // Memoize the full holiday set once per request. A report rendering many rows
    // used to re-query company_holidays for every row (N+1); we now load every
    // holiday a single time and reuse it. Behaviour is identical: the loop only
    // ever tests dates inside [start, end], so holidays outside that span are
    // never consulted, and lookups use the same 'Y-m-d' string keys as before.
    static $holidaySet = null;
    if ($holidaySet === null) {
        $holidaySet = [];
        $allHolidays = $pdo->query("SELECT holiday_date FROM company_holidays")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($allHolidays as $h) {
            $holidaySet[$h] = true;
        }
    }

    static $durationMemo = [];
    $memoKey = $startDate . '|' . $endDate;
    if (isset($durationMemo[$memoKey])) return $durationMemo[$memoKey];

    $workingDays = 0;

    // Loop through each calendar day interval
    while ($start <= $end) {
        $dayOfWeek = $start->format('N'); // 1 = Monday, 7 = Sunday
        $currentDateStr = $start->format('Y-m-d');

        // Count only if it's a weekday AND not found in the holiday blacklist set
        if ($dayOfWeek < 6 && !isset($holidaySet[$currentDateStr])) {
            $workingDays++;
        }
        $start->modify('+1 day');
    }
    
    // Return duration (subtract 1 to calculate net lead time instead of inclusive days)
    return $durationMemo[$memoKey] = max(0, $workingDays - 1);
}

// --- SERVER-SIDE SLA TRACKING HELPERS --------------------------------------
// Shared by pages/sla_report.php and the ajax_sla_*.php endpoints so persona
// rules and business logic (team resolution, SLA status/duration) live in
// exactly one place and can never drift between the page and its endpoints.

// Mirrors the persona resolution that used to live inline in sla_report.php.
function resolve_sla_persona($pdo, $session) {
    $manager_username = $session['username'] ?? '';
    $role = $session['role'] ?? '';
    $full_access_allowlist = ['adonis.sutanto', 'teofilus.franky', 'hadi.risali', 'sigit.ramadan'];

    $project_groups = function_exists('get_project_head_groups')
        ? get_project_head_groups($pdo, $session)
        : [];

    // PIC is checked FIRST and unconditionally. A PIC may only ever see the PR
    // lines assigned to them, so no other persona rule (allowlist, project
    // groups, team mapping) may widen that — hence this branch cannot fall
    // through to the ones below.
    if ($role === 'PIC') {
        $persona = 'PIC';
    } elseif ($role === 'Viewer') {
        // Read-only role with unrestricted sight: COMBINED is the widest persona
        // (project + non-project rows), matching the allowlisted managers above.
        // Safe to widen here because the persona only decides which rows are
        // SEEN; the 'role' carried alongside it is what gates requesting a
        // change, and every SLA write endpoint calls ws_require_write().
        $persona = 'COMBINED';
    } elseif ($role === 'Technical Department') {
        $persona = 'TECHNICAL';
    } elseif ($role === 'Category Manager' || in_array($manager_username, $full_access_allowlist)) {
        // Category Manager gets COMBINED by role, matching dashboard_data_helper.php's
        // persona logic — the allowlist above stays for non-Category-Manager exceptions.
        $persona = 'COMBINED';
    } elseif ($role === 'Manager' && !empty($project_groups)) {
        $persona = 'PROJECT';
    } else {
        $persona = 'NONPROJECT';
    }

    return [
        'persona' => $persona,
        // The raw session role, carried alongside the persona because the two
        // answer different questions: the persona decides which rows you SEE,
        // the role decides whether you may REQUEST a change to one. Kept here so
        // get_sla_computed_rows() does not have to reach into $_SESSION itself.
        'role' => $role,
        'manager_username' => $manager_username,
        // users.name — the value prs.assigned_pic is matched against (this app
        // links PRs to people by name string, not by user id).
        'user_name' => trim((string)($session['name'] ?? '')),
        'team_name' => $session['team_name'] ?? '',
        'project_groups' => $project_groups,
    ];
}

function get_sla_computed_rows($pdo, $persona_ctx) {
    $persona = $persona_ctx['persona'];
    $manager_username = $persona_ctx['manager_username'];
    $project_groups = $persona_ctx['project_groups'];

    $project_team_map = load_project_team_map($pdo);

    $mode = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'sla_mapping_mode'")->fetchColumn() ?: 'pgroup';

    // SLA starts from TR Date Technical, which is stored in prs.pr_date (the SLA clock
    // used to start at tr_date_gp). pr_date is therefore the report's main sort/filter
    // key as well, so rows without it can't be evaluated and are excluded up front.
    if ($mode === 'pic') {
        $query = "
            SELECT p.ticket_no, p.ticket_line_item, p.ticket_type, p.assigned_pic, p.requestor_name, p.item_description, p.pr_date, p.canvassing_no, p.canvassing_date, p.canvassing_description, p.canvassing_date_approver_1, p.po_number, p.p_group, u.team_name,
                   p.master_data_id, md.level_0, md.level_1, md.level_2, md.level_3, md.level_4
            FROM prs p LEFT JOIN users u ON p.assigned_pic = u.name
            LEFT JOIN master_data md ON p.master_data_id = md.id
            WHERE p.pr_date IS NOT NULL ORDER BY p.pr_date ASC
        ";
    } else {
        $query = "
            SELECT p.ticket_no, p.ticket_line_item, p.ticket_type, p.assigned_pic, p.requestor_name, p.item_description, p.pr_date, p.canvassing_no, p.canvassing_date, p.canvassing_description, p.canvassing_date_approver_1, p.po_number, p.p_group, m.team_name,
                   p.master_data_id, md.level_0, md.level_1, md.level_2, md.level_3, md.level_4
            FROM prs p LEFT JOIN pgroup_team_mappings m ON p.p_group = m.p_group
            LEFT JOIN master_data md ON p.master_data_id = md.id
            WHERE p.pr_date IS NOT NULL ORDER BY p.pr_date ASC
        ";
    }
    $raw_data = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);

    if ($persona === 'PROJECT') {
        $raw_data = array_filter($raw_data, function($row) use ($project_groups, $project_team_map) {
            $project_team = project_team_for($row['p_group'], $project_team_map);
            if ($project_team === null) return false;
            $p_group = $row['p_group'] ?? '';
            return !empty($p_group) && in_array($p_group, array_keys(array_filter(array_flip($project_groups))));
        });
        $raw_data = array_values($raw_data);
    } elseif ($persona === 'NONPROJECT') {
        try {
            $subordinates = get_all_subordinates($pdo, $manager_username);
            $subordinate_pic_names = [];
            $manager_team_names = [];
            $own_team = trim((string)($persona_ctx['team_name'] ?? ''));
            if ($own_team !== '') { $manager_team_names[] = $own_team; }
            foreach ($subordinates as $sub) {
                if (($sub['role'] ?? '') === 'PIC' || ($sub['role'] ?? '') === 'Admin') {
                    $subordinate_pic_names[] = $sub['name'];
                }
                if (!empty($sub['team_name'])) {
                    $manager_team_names[] = $sub['team_name'];
                }
            }
            $manager_team_names = array_unique($manager_team_names);

            // P.Groups this manager's team(s) own per pgroup_team_mappings --
            // resolved directly here (not via $row['team_name']) because that
            // column's source depends on sla_mapping_mode: in 'pic' mode it's
            // joined from the CURRENT assignee's own users.team_name, which is
            // exactly the flawed signal this fix exists to stop relying on. A
            // line reassigned to someone outside the team (e.g. a PO Admin) must
            // stay visible on p_group ownership regardless of that setting.
            $owned_pgroups = [];
            if (!empty($manager_team_names)) {
                $teamPh = implode(',', array_fill(0, count($manager_team_names), '?'));
                $pgStmt = $pdo->prepare("SELECT p_group FROM pgroup_team_mappings WHERE team_name IN ($teamPh)");
                $pgStmt->execute(array_values($manager_team_names));
                $owned_pgroups = $pgStmt->fetchAll(PDO::FETCH_COLUMN);
            }

            $raw_data = array_filter($raw_data, function($row) use ($subordinate_pic_names, $owned_pgroups, $project_team_map) {
                $assigned_pic = trim($row['assigned_pic'] ?? '');
                $project_team = project_team_for($row['p_group'], $project_team_map);
                if ($project_team !== null) return false;
                if (empty($assigned_pic)) return true;
                if (in_array($assigned_pic, $subordinate_pic_names)) return true;
                return in_array($row['p_group'] ?? '', $owned_pgroups, true);
            });
            $raw_data = array_values($raw_data);
        } catch (PDOException $e) {
            $raw_data = [];
        }
    } elseif ($persona === 'PIC') {
        // Read-only, own-rows-only. Matched on users.name <-> prs.assigned_pic,
        // the same string join the rest of the app uses. Fails CLOSED: a session
        // without a name sees nothing rather than everything.
        $own_name = trim((string)($persona_ctx['user_name'] ?? ''));
        if ($own_name === '') {
            $raw_data = [];
        } else {
            $raw_data = array_values(array_filter($raw_data, function($row) use ($own_name) {
                return trim((string)($row['assigned_pic'] ?? '')) === $own_name;
            }));
        }
    } elseif ($persona === 'TECHNICAL') {
        $team_name = $persona_ctx['team_name'] ?? '';
        try {
            $stmt = $pdo->prepare("SELECT pgroup FROM technical_team_pgroups WHERE team_name = ?");
            $stmt->execute([$team_name]);
            $mapped_pgroups = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $raw_data = array_filter($raw_data, function($row) use ($mapped_pgroups) {
                return in_array($row['p_group'], $mapped_pgroups);
            });
            $raw_data = array_values($raw_data);
        } catch (PDOException $e) {
            $raw_data = [];
        }
    }

    $acronym_map = [
        'Corporate Services, General Supplies & Marketing' => 'CSGSM',
        'Technology, Industrial, and General Services' => 'TIGS',
        'FNB and Operational Merchandise' => 'FNBOM',
        'Admin Team' => 'ADMIN',
        'Project Konstruksi Retail' => 'PKR',
        'Project Konstruksi Properti' => 'PKP',
        'Maintenance & Facility Management' => 'MFM'
    ];

    $data = [];
    $unique_teams = [];

    // Targets are configured per ticket category on settings_sla.php; resolution
    // (Intercompany, else the P.Group's whitelist label) lives in sla_rules.php so
    // this report and the role dashboards can never show different targets.
    $sla_rule_days     = sla_rules_load($pdo);
    $sla_pgroup_labels = sla_pgroup_labels($pdo);

    // Manager-approved adjustments — the effective values to layer onto each
    // row. One query, fails soft to [] if the tables aren't deployed yet.
    $overrides = sla_rev_load_overrides($pdo);

    // Requests still waiting for approval. They change nothing about the numbers
    // — only an approval does that — but SLA Tracking marks their cells so a PIC
    // can see what they already filed instead of filing it twice.
    $pending_reqs = sla_rev_load_pending($pdo);

    // Lines whose last request was rejected. Same idea as $pending_reqs — it changes
    // nothing about the numbers, it just gives the table a way to point at a decision
    // the PIC would otherwise have no reason to go looking for.
    $rejected_reqs = sla_rev_load_last_rejected($pdo);

    // Whether this session may submit an adjustment at all. Mirrors the role gate
    // in ajax_sla_request_submit.php step 1: everyone else gets read-only cells.
    // Technical Department is deliberately excluded — SLA Revision (inline edit,
    // request submission, the approvals inbox) is not available to that role at
    // all, even though they can still view SLA Tracking itself.
    // The per-row "is this line eligible" question is answered separately by
    // sla_rev_line_editability() below.
    $may_request = in_array(($persona_ctx['role'] ?? ''), ['PIC', 'Manager', 'Category Manager'], true);

    foreach ($raw_data as $row) {
        // Approved dates are layered on BEFORE computation, so the existing
        // status/duration block below runs unchanged on the effective values.
        // They can't be written into `prs` — the PR import would overwrite them
        // on the next upload (excel_parser.php ~343).
        $line_key = sla_rev_line_key($row['ticket_no'], $row['ticket_line_item']);
        $ov = $overrides[$line_key] ?? null;

        $row['orig_pr_date']         = $row['pr_date'];
        $row['orig_canvassing_date'] = $row['canvassing_date_approver_1'];
        $row['date_overridden']      = false;

        if ($ov) {
            if (!empty($ov['override_pr_date'])) {
                $row['pr_date'] = $ov['override_pr_date'];
                $row['date_overridden'] = true;
            }
            if (!empty($ov['override_canvassing_date'])) {
                $row['canvassing_date_approver_1'] = $ov['override_canvassing_date'];
                $row['date_overridden'] = true;
            }
        }

        $ticketType = trim($row['ticket_type'] ?? '');
        $hasCanvassing = !empty($row['canvassing_date_approver_1']);
        $hasPO = !empty($row['po_number']);
        $slaTarget = sla_target_days($ticketType, $row['p_group'] ?? '', $sla_rule_days, $sla_pgroup_labels, $row['canvassing_description'] ?? '');

        $project_team = project_team_for($row['p_group'], $project_team_map);
        if ($project_team !== null) {
            $team = $project_team;
        } else {
            $raw_team = trim($row['team_name'] ?: 'Unassigned Team');
            $team = $acronym_map[$raw_team] ?? $raw_team;
        }
        $row['team_name'] = $team;

        if (!in_array($team, $unique_teams)) $unique_teams[] = $team;

        if ($ticketType === 'Low Value OPEX') {
            $row['durationDisplay'] = 'N/A';
            $row['statusName'] = 'Out of Scope';
            $row['sphereColor'] = 'bg-gray-400';
            $row['textColor'] = 'text-gray-600';
        } elseif (!$hasCanvassing && $hasPO) {
            $row['durationDisplay'] = 'N/A';
            $row['statusName'] = 'AON';
            $row['sphereColor'] = 'bg-blue-500';
            $row['textColor'] = 'text-blue-700';
        } elseif ($hasCanvassing) {
            $days = getWorkingDaysDuration($row['pr_date'], $row['canvassing_date_approver_1'], $pdo);
            $row['durationDisplay'] = $days . ' Day' . ($days != 1 ? 's' : '') . " / {$slaTarget}D";
            if ($days <= $slaTarget) {
                $row['statusName'] = 'Within SLA';
                $row['sphereColor'] = 'bg-green-500';
                $row['textColor'] = 'text-green-700';
            } else {
                $row['statusName'] = 'SLA Breached';
                $row['sphereColor'] = 'bg-red-500';
                $row['textColor'] = 'text-red-700';
            }
        } else {
            $row['durationDisplay'] = 'Pending';
            $row['statusName'] = 'In Progress';
            $row['sphereColor'] = 'bg-yellow-400';
            $row['textColor'] = 'text-yellow-700';
        }

        // Status override applies only to an already-decided verdict
        // (sla_rev_status_options()). That also makes it go dormant by itself if
        // later data pushes the line back to In Progress / AON / Out of Scope.
        // durationDisplay is left untouched — the day count stays truthful.
        $row['status_overridden']      = false;
        $row['status_before_override'] = null;
        if ($ov && !empty($ov['override_sla_status'])
            && in_array($row['statusName'], sla_rev_status_options(), true)
            && $ov['override_sla_status'] !== $row['statusName']) {

            $row['status_before_override'] = $row['statusName'];
            $row['status_overridden']      = true;
            $row['statusName']             = $ov['override_sla_status'];

            if ($row['statusName'] === 'Within SLA') {
                $row['sphereColor'] = 'bg-green-500';
                $row['textColor']   = 'text-green-700';
            } else {
                $row['sphereColor'] = 'bg-red-500';
                $row['textColor']   = 'text-red-700';
            }
        }
        $row['override_by'] = $ov['approved_by_name'] ?? '';
        $row['override_at'] = $ov['approved_at'] ?? '';

        // Inline editing on SLA Tracking. Computed HERE, after the status block,
        // because eligibility is decided on the EFFECTIVE verdict — the same
        // value the user is looking at. Attached to the row so
        // sla_shape_row_for_client() can ship it without another lookup.
        $row['pending_req']  = $pending_reqs[$line_key] ?? null;
        $row['rejected_req'] = $rejected_reqs[$line_key] ?? null;
        $row['editability'] = sla_rev_line_editability($row, $row['pending_req']);
        if (!$may_request) {
            $row['editability']['can_edit_date']   = false;
            $row['editability']['can_edit_status'] = false;
            $row['editability']['why']             = 'You are not allowed to request an SLA revision.';
        }

        $data[] = $row;
    }
    sort($unique_teams);

    return [
        'rows' => $data,
        'unique_teams' => $unique_teams,
        'mode' => $mode,
    ];
}

// Normalizes a client-supplied gf filter (JSON string or already-decoded array)
// into a fixed shape: ['team','pic','status','tax'=>[5 strings],'from','to'].
function sla_normalize_gf($raw) {
    $gf = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($gf)) $gf = [];
    $tax = (isset($gf['tax']) && is_array($gf['tax'])) ? array_values($gf['tax']) : [];
    while (count($tax) < 5) $tax[] = '';
    $tax = array_slice($tax, 0, 5);

    $teamRaw = $gf['team'] ?? [];
    if (is_string($teamRaw)) {
        $team = $teamRaw === '' ? [] : [$teamRaw];
    } else if (is_array($teamRaw)) {
        $team = array_map(function($v) { return (string)$v; }, array_values($teamRaw));
    } else {
        $team = [];
    }

    // Same shape as 'team' — a multi-select list of PIC names (or 'Unassigned'),
    // set by clicking a bar segment when the bar chart's dimension is 'pic'.
    $picRaw = $gf['pic'] ?? [];
    if (is_string($picRaw)) {
        $pic = $picRaw === '' ? [] : [$picRaw];
    } else if (is_array($picRaw)) {
        $pic = array_map(function($v) { return (string)$v; }, array_values($picRaw));
    } else {
        $pic = [];
    }

    return [
        'team' => $team,
        'pic' => $pic,
        'status' => (string)($gf['status'] ?? ''),
        'tax' => array_map(function($v) { return (string)$v; }, $tax),
        'from' => (string)($gf['from'] ?? ''),
        'to' => (string)($gf['to'] ?? ''),
    ];
}

// Mirrors the JS getFilteredRows() predicate (team / status / taxonomy w/ "Unmapped" /
// date range). $ignoreStatus mirrors opts.ignoreStatus (charts skip the status filter).
function sla_gf_filter($rows, $gf, $ignoreStatus = false) {
    $team = $gf['team'];
    $pic = $gf['pic'];
    $status = $gf['status'];
    $tax = $gf['tax'];
    $from = $gf['from'];
    $to = $gf['to'];

    return array_values(array_filter($rows, function($r) use ($team, $pic, $status, $tax, $from, $to, $ignoreStatus) {
        if (!empty($team) && !in_array($r['team_name'] ?? '', $team, true)) return false;
        if (!empty($pic)) {
            $rowPic = trim((string)($r['assigned_pic'] ?? '')) ?: 'Unassigned';
            if (!in_array($rowPic, $pic, true)) return false;
        }
        if (!$ignoreStatus && $status !== '') {
            // 'Adjusted' is not a statusName value — see sla_rev_is_adjusted_line(),
            // which is the one definition of the rule (approved status/date override,
            // or a rejected request) and is shared with ajax_sla_charts.php.
            if ($status === 'Adjusted') {
                if (!sla_rev_is_adjusted_line($r)) return false;
            } elseif (($r['statusName'] ?? '') !== $status) {
                return false;
            }
        }
        for ($i = 0; $i < 5; $i++) {
            if ($tax[$i] !== '') {
                $lvVal = trim((string)($r['level_' . $i] ?? ''));
                if ($lvVal === '') $lvVal = 'Unmapped';
                if ($lvVal !== $tax[$i]) return false;
            }
        }
        if ($from !== '' || $to !== '') {
            $d = date('Y-m-d', strtotime($r['pr_date']));
            if ($from !== '' && $d < $from) return false;
            if ($to !== '' && $d > $to) return false;
        }
        return true;
    }));
}

// Mirrors sla_report.php's trend-chart period bucket precomputation (fixed
// last-N-periods-including-the-current-one per granularity), so the endpoint
// building trend datasets and the page itself always agree on the same keys.
function sla_trend_buckets() {
    $now = new DateTime('now'); // caller must already have set the Asia/Jakarta timezone

    $week_keys = [];
    for ($i = 7; $i >= 0; $i--) {
        $d = clone $now;
        $d->modify("-{$i} week");
        $week_keys[] = $d->format('o-\WW');
    }
    $week_labels = array_map(function($w) {
        return "Week " . substr($w, 6) . " '" . substr($w, 2, 2);
    }, $week_keys);

    $month_anchor = new DateTime($now->format('Y-m') . '-01');
    $month_keys = [];
    for ($i = 11; $i >= 0; $i--) {
        $d = clone $month_anchor;
        $d->modify("-{$i} month");
        $month_keys[] = $d->format('Y-m');
    }
    $month_labels = array_map(function($m) {
        return DateTime::createFromFormat('Y-m-d', $m . '-01')->format("M 'y");
    }, $month_keys);

    $year_keys = [];
    for ($i = 4; $i >= 0; $i--) {
        $d = clone $now;
        $d->modify("-{$i} year");
        $year_keys[] = $d->format('Y');
    }
    $year_labels = $year_keys;

    return [
        'week'  => ['keys' => $week_keys, 'labels' => $week_labels],
        'month' => ['keys' => $month_keys, 'labels' => $month_labels],
        'year'  => ['keys' => $year_keys, 'labels' => $year_labels],
    ];
}

function sla_team_colors() {
    return ['#4f46e5', '#ec4899', '#f59e0b', '#14b8a6', '#6366f1', '#10b981'];
}

// Grouping key for a row in the distinct-item list and the chatbot's item stats: the
// item description, or the '(No Description)' bucket when it is blank. Shared so the
// two endpoints can never disagree on what counts as one distinct item.
function sla_item_key($row) {
    $item = trim((string)($row['item_description'] ?? ''));
    return $item !== '' ? $item : '(No Description)';
}

// Builds the "Distinct Item List" payload. $allRows is the FULL persona-scoped set (the
// fixed "/ N" denominator, which ignores gf entirely); $filteredRows is that set with every
// global filter applied, including status.
// Shared by ajax_sla_items.php (search / column-sort changes) and ajax_sla_charts.php
// (which returns it alongside the charts so a filter change costs one request, not two) —
// both must produce byte-identical payloads, so the logic lives here rather than in either.
function sla_build_item_list($allRows, $filteredRows, $search, $sortCol, $sortDir) {
    if (!in_array($sortCol, ['name', 'count', 'pct'], true)) $sortCol = 'count';
    if (!in_array($sortDir, ['asc', 'desc'], true)) $sortDir = 'desc';

    // Overall (unfiltered) PR-line count per item — fixed denominator, ignores gf entirely.
    $itemOverallCounts = [];
    foreach ($allRows as $r) {
        $key = sla_item_key($r);
        $itemOverallCounts[$key] = ($itemOverallCounts[$key] ?? 0) + 1;
    }
    $itemTotalCount = count($itemOverallCounts);

    $counts = [];
    foreach ($filteredRows as $r) {
        $key = sla_item_key($r);
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    $term = strtolower(trim((string)$search));
    $keys = array_keys($counts);
    if ($term !== '') {
        $keys = array_values(array_filter($keys, function($k) use ($term) {
            return strpos(strtolower($k), $term) !== false;
        }));
    }

    $entries = array_map(function($k) use ($counts, $itemOverallCounts) {
        $overall = $itemOverallCounts[$k] ?? $counts[$k];
        $pct = $overall ? ($counts[$k] / $overall * 100) : 0;
        return ['name' => $k, 'count' => $counts[$k], 'overall' => $overall, 'pct' => $pct];
    }, $keys);

    $dirMul = ($sortDir === 'asc') ? 1 : -1;
    usort($entries, function($a, $b) use ($sortCol, $dirMul) {
        if ($sortCol === 'name') {
            return $dirMul * strcmp($a['name'], $b['name']);
        }
        $diff = ($a[$sortCol] <=> $b[$sortCol]) * $dirMul;
        return $diff !== 0 ? $diff : strcmp($a['name'], $b['name']);
    });

    // Shown count is the match count BEFORE the render cap (the client's counter reports
    // how many matched, not how many were rendered).
    $itemShownCount = count($entries);
    $RENDER_CAP = 500;
    $capped = $itemShownCount > $RENDER_CAP;
    if ($capped) $entries = array_slice($entries, 0, $RENDER_CAP);

    return [
        'itemTotalCount' => $itemTotalCount,
        'itemShownCount' => $itemShownCount,
        'capped' => $capped,
        'items' => $entries,
    ];
}

// Mirrors the compact per-row payload sla_report.php used to embed as $sla_rows_js
// (ticket/type/pic/po/status/dur/team/item/lv[0..4]/d/cv/wk/mo/yr).
function sla_shape_row_for_client($row) {
    // 'd' (and the wk/mo/yr period keys derived from it) is the SLA start date:
    // TR Date Technical, stored in prs.pr_date.
    $tr_ts = strtotime($row['pr_date']);
    return [
        'ticket' => $row['ticket_no'],
        'type'   => trim((string)($row['ticket_type'] ?? '')) ?: 'Unspecified',
        'pic'    => trim((string)($row['assigned_pic'] ?? '')) ?: 'Unassigned',
        'po'     => $row['po_number'] ?: '',
        'status' => $row['statusName'],
        'dur'    => $row['durationDisplay'],
        'team'   => $row['team_name'],
        'line_item' => trim((string)($row['ticket_line_item'] ?? '')),
        // Same rule as the "Escalated" legend filter and the purple "Rev" badge —
        // see sla_rev_is_adjusted_line().
        'escalated' => sla_rev_is_adjusted_line($row),
        'item'   => trim((string)($row['item_description'] ?? '')),
        'lv'     => [
            trim((string)($row['level_0'] ?? '')),
            trim((string)($row['level_1'] ?? '')),
            trim((string)($row['level_2'] ?? '')),
            trim((string)($row['level_3'] ?? '')),
            trim((string)($row['level_4'] ?? '')),
        ],
        'd'  => date('Y-m-d', $tr_ts),
        // Canvassing No + its own (raw) date — distinct from 'cv' below, which is
        // canvassing_date_approver_1 (the date that actually stops the SLA clock).
        'cno' => trim((string)($row['canvassing_no'] ?? '')),
        'cnd' => !empty($row['canvassing_date']) ? date('Y-m-d', strtotime($row['canvassing_date'])) : '',
        // Free text from K2, written by STT. Its leading [PR]/[PP]/[PM]/[OS] code is
        // what set this line's SLA target -- see sla_canvassing_rule_key().
        'cdesc' => trim((string)($row['canvassing_description'] ?? '')),
        'cv' => !empty($row['canvassing_date_approver_1']) ? date('Y-m-d', strtotime($row['canvassing_date_approver_1'])) : '',
        'wk' => date('o-\WW', $tr_ts),
        'mo' => date('Y-m', $tr_ts),
        'yr' => date('Y', $tr_ts),
        'porg' => trim((string)($row['p_org'] ?? '')),
        'pgroup' => trim((string)($row['p_group'] ?? '')),
        // Adjustment workflow: the active override (the request history itself
        // is fetched on demand by ajax_sla_request_history.php).
        'ovr_status'  => !empty($row['status_overridden']),
        'ovr_from'    => (string)($row['status_before_override'] ?? ''),
        'ovr_date'    => !empty($row['date_overridden']),
        'ovr_by'      => (string)($row['override_by'] ?? ''),
        'ovr_at'      => (string)($row['override_at'] ?? ''),
        // Raw prs values, for the "original vs adjusted" tooltips.
        'orig_d'  => !empty($row['orig_pr_date']) ? date('Y-m-d', strtotime($row['orig_pr_date'])) : '',
        'orig_cv' => !empty($row['orig_canvassing_date']) ? date('Y-m-d', strtotime($row['orig_canvassing_date'])) : '',
        // Whether this line is eligible for an SLA Status Override request.
        'ovr_ok'  => in_array((string)($row['status_before_override'] ?? $row['statusName']), sla_rev_status_options(), true),
    ] + sla_shape_row_edit_state($row);
}

/**
 * The inline-edit state of one row, for the SLA Tracking cells.
 *
 * Split out of sla_shape_row_for_client() because it is a different concern with
 * a different audience: everything above describes the PR line, everything here
 * describes what THIS user may do to it and what is already in flight. Keys are
 * short — this ships once per row, per draw, for up to a full page of rows.
 *
 * Reads $row['editability'] / $row['pending_req'], which get_sla_computed_rows()
 * attaches. Degrades to "not editable, nothing pending" when they are absent, so
 * any caller that shapes a row from somewhere else still gets valid output.
 */
function sla_shape_row_edit_state($row) {
    $ed = $row['editability'] ?? null;
    $pd_date   = $ed['pending_date']   ?? null;
    $pd_status = $ed['pending_status'] ?? null;

    // Whichever request is pending carries the "who and when" — they are almost
    // always the same submission, and the tooltip only has room for one line.
    $src = $pd_date ?: $pd_status;

    // "from -> to" per field, so a cell can label itself without knowing anything
    // about request types. Built from sla_rev_change_pairs(), which is what the
    // approval inbox and the History modal render too — that is also what makes
    // the legacy single-date request types come out right here for free.
    $pd = ['d' => '', 'cv' => '', 'st' => ''];
    // Both labels map to 'cv': 'Canvassing Date Approver 1' from the current 'date'
    // request type, 'Canvassing Date' from the legacy single-date 'canvassing_date' type.
    $label_to_field = ['PR Date' => 'd', 'Canvassing Date Approver 1' => 'cv', 'Canvassing Date' => 'cv', 'SLA Status' => 'st'];
    foreach ([$pd_date, $pd_status] as $req) {
        if (!$req) continue;
        foreach (sla_rev_change_pairs($req) as $pair) {
            $f = $label_to_field[$pair['label']] ?? null;
            if ($f !== null) $pd[$f] = $pair['from'] . ' → ' . $pair['to'];
        }
    }

    $out = [
        'can_ed' => !empty($ed['can_edit_date']),    // PR Date + Canvassing Date
        'can_es' => !empty($ed['can_edit_status']),  // SLA Status
        'ed_why' => (string)($ed['why'] ?? ''),
        // A pending request on ANY field of this line locks every field (see
        // sla_rev_line_editability()). A field that isn't itself the pending one
        // still needs to react like a locked/pending cell on the client — this is
        // what tells editCell() to do that instead of treating it the same as a
        // "not eligible at all" cell (not breached / already adjusted).
        'has_pending' => !empty($ed['has_pending']),
        // What is already waiting for approval, so the cell can say so instead of
        // silently refusing the double-click.
        'pd'     => $pd,
        'pd_by'  => $src ? (string)($src['requested_by_name'] ?? '') : '',
        'pd_at'  => ($src && !empty($src['created_at'])) ? date('d M Y H:i', strtotime($src['created_at'])) : '',
        // Legacy rows kept the justification in reason/evidence/notes — folded by
        // the same helper every other screen uses.
        'pd_rm'  => $src ? sla_rev_remarks_text($src) : '',
    ];

    // The last request on this line was rejected — the table turns its History link
    // red so the decision is discoverable. Added ONLY when it applies: this shape
    // ships once per row for 30k+ rows, so the common case must stay as small as it
    // was (the client reads a missing key as "not rejected").
    $rj = $row['rejected_req'] ?? null;
    if ($rj) {
        $type_labels  = sla_rev_type_labels();
        $out['rj']    = (string)($type_labels[$rj['request_type']] ?? $rj['request_type']);
        $out['rj_by'] = (string)($rj['decided_by_name'] ?? '');
        $out['rj_at'] = !empty($rj['decided_at']) ? date('d M Y H:i', strtotime($rj['decided_at'])) : '';
        $out['rj_why'] = (string)($rj['decision_notes'] ?? '');
    }

    return $out;
}
?>