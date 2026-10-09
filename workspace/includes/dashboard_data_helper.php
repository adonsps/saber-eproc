<?php
// includes/dashboard_data_helper.php
// Shared data-assembly for the Manager Workload Dashboard (dashboard_mgr.php).
// Used by both ajax_dashboard_data.php (dashboard meta: charts, filter option
// lists, persona info) and ajax_dashboard_table.php (server-side DataTables
// rows for #prTable), so the persona/business-rule logic that decides which
// PR rows a manager can see lives in exactly one place.
//
// No date-range restriction here: earlier versions split "recent" (last 60
// days) from "historical" and lazy-loaded the rest into the browser once the
// page settled. That pattern only existed to keep a client-side DataTable
// responsive; now that ajax_dashboard_table.php paginates in PHP and ships
// just the current page, the split adds nothing but an extra request, so the
// full persona-scoped row set is always fetched here.

if (!function_exists('get_dashboard_rows')) {
    // $include_extras controls the parts only the dashboard's initial "meta" load
    // needs (workload chart data, pending vendor-eval reminders, last-updated
    // label) -- ajax_dashboard_table.php calls this on every page/sort/filter
    // change and has no use for any of them, so it passes false to skip the
    // extra queries (pending_evals' JOIN+GROUP BY, MAX(created_at)) entirely.
    function get_dashboard_rows(PDO $pdo, array $session, bool $include_extras = true) {
        require_once __DIR__ . '/aon_helper.php';
        require_once __DIR__ . '/pr_status_helper.php';
        require_once __DIR__ . '/sla_rules.php';

        $project_pgroups_available = false;
        try {
            $pdo->query("SELECT 1 FROM project_pgroups LIMIT 1");
            $project_pgroups_available = true;
        } catch (PDOException $e) {
            $project_pgroups_available = false;
        }
        // Reusable SQL fragment appended to PR queries (PR table named/aliased `prs`).
        // Empty string when project_pgroups is unavailable so queries stay valid.
        $project_exclude_sql = "";
        if ($project_pgroups_available) {
            $active_pgroups = $pdo->query("SELECT p_group FROM project_pgroups WHERE status = 'ACTIVE'")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($active_pgroups)) {
                $escaped = array_map(function($g) { return "'" . addslashes($g) . "'"; }, $active_pgroups);
                $project_exclude_sql = " AND prs.p_group NOT IN (" . implode(",", $escaped) . ")";
            }
        }

        // --- CANVASSING_STATUS COMPATIBILITY GUARD ------------------------------
        // prs.canvassing_status may not exist on every environment (e.g. local dev
        // DBs created before this column shipped). Detect it with a read-only SHOW
        // COLUMNS check and swap in a harmless NULL alias when absent so every
        // query below keeps returning a canvassing_status key without ever
        // touching the schema.
        $canvassing_status_available = false;
        try {
            $canvassing_status_available = (bool)$pdo->query("SHOW COLUMNS FROM prs LIKE 'canvassing_status'")->fetch();
        } catch (PDOException $e) {
            $canvassing_status_available = false;
        }
        $canvassing_status_select = $canvassing_status_available ? 'prs.canvassing_status' : 'NULL AS canvassing_status';

        $manager_username = $session['username'] ?? '';
        $role = $session['role'] ?? '';
        $full_access_allowlist = ['adonis.sutanto', 'teofilus.franky', 'hadi.risali'];

        // --- FETCH DATA ---
        // One Workload Dashboard, three personas:
        //   COMBINED   = Full-access allowlist OR Category Manager/Admin: Non-Project rows + every active Project group.
        //   PROJECT    = Manager who heads at least one active Project group (Rio/Fabian):
        //                ONLY their Project group(s); no Non-Project rows.
        //   NONPROJECT = Manager with no Project group (Susi/Jimmy/Michelle):
        //                the original Non-Project behaviour, completely unchanged.
        // get_project_head_groups() fails closed to [] (e.g. project tables not deployed),
        // so a Non-Project manager always resolves to NONPROJECT and behaves as before.
        $project_groups = function_exists('get_project_head_groups')
            ? get_project_head_groups($pdo, $session)
            : [];
        // 'Viewer' (read-only) resolves to the widest persona — see the matching
        // condition in pages/dashboard_mgr.php, which must stay in step with this
        // one: that decides whether the team chart is rendered, this decides
        // whether its data exists.
        if (in_array($manager_username, $full_access_allowlist) || $role === 'Category Manager' || $role === 'Admin' || $role === 'Viewer') {
            $persona = 'COMBINED';
        } elseif ($role === 'Manager' && !empty($project_groups)) {
            $persona = 'PROJECT';
        } else {
            $persona = 'NONPROJECT';
        }

        $all_prs     = [];   // unified display rows (each tagged with _row_type)
        $workload_np = [];   // Non-Project workload: assigned_pic => open-PR count
        $all_pics    = [];   // Non-Project assignable PIC pool (reassign modal + filter)
        $np_pics_by_team = []; // mapping of team_code => [pic_name, ...]
        $np_team_codes = []; // list of team codes for filtering

        // Non-Project team short codes (UI-only; mirrors the analytics acronym map in
        // cost_savings.php). Used to derive per-row team labels for the COMBINED team
        // filter from existing pgroup_team_mappings.team_name -- display only.
        $np_acronym_map = [
            'Corporate Services, General Supplies & Marketing' => 'CSGSM',
            'Technology, Industrial, and General Services'      => 'TIGS',
            'FNB and Operational Merchandise'                   => 'FNBOM',
            'Admin Team'                                        => 'ADMIN',
            'Project Konstruksi Retail'                         => 'PKR',
            'Project Konstruksi Properti'                       => 'PKP',
            'Maintenance & Facility Management'                 => 'MFM',
        ];
        $np_team_code = function($team_name) use ($np_acronym_map) {
            $t = trim((string)$team_name);
            if ($t === '') return '';
            return $np_acronym_map[$t] ?? $t;
        };

        // ===== NON-PROJECT SOURCE (NONPROJECT + COMBINED) ======================
        if ($persona === 'COMBINED') {
            // Category Manager: every Non-Project PR (active Project PGroups excluded).
            $stmt = $pdo->query("SELECT prs.ticket_no, prs.ticket_line_item, prs.master_data_id, prs.pr_date, prs.p_org, prs.p_group, prs.requestor_name, prs.site_allocation, prs.item_description, prs.sku, prs.qty, prs.status_ticket, prs.assigned_pic, prs.assigned_by_ai, prs.last_updated_by, prs.last_updated_date, prs.canvassing_no, {$canvassing_status_select}, prs.canvassing_date, prs.canvassing_description, prs.ticket_helpdesk_no, prs.canvassing_date_approver_1, prs.po_number, prs.po_create_date, prs.po_amount, prs.po_currency, m.team_name, prs.ticket_type, md.level_0, md.level_1, md.level_2, md.level_3, md.level_4, md.level_5 FROM prs LEFT JOIN pgroup_team_mappings m ON prs.p_group = m.p_group LEFT JOIN master_data md ON prs.master_data_id = md.id WHERE prs.status_ticket NOT IN ('Cancel', 'Cancelled', 'Cancel Ticket') AND (prs.pr_date >= '2026-01-01' OR prs.pr_date IS NULL OR (prs.canvassing_no IS NOT NULL AND prs.canvassing_no != '') OR (prs.po_number IS NOT NULL AND prs.po_number != ''))" . $project_exclude_sql . " ORDER BY prs.created_at DESC");
            $np_prs = $stmt->fetchAll();

            $chartStmt = $pdo->query("SELECT assigned_pic, COUNT(*) as count FROM prs WHERE assigned_pic IS NOT NULL AND status_ticket NOT IN ('Cancel', 'Cancelled', 'Cancel Ticket') AND (po_number IS NULL OR po_number = '') AND (pr_date >= '2026-01-01' OR pr_date IS NULL OR (canvassing_no IS NOT NULL AND canvassing_no != '') OR (po_number IS NOT NULL AND po_number != ''))" . $project_exclude_sql . " GROUP BY assigned_pic");
            // TRIM'd here (not just at output) so an untrimmed/whitespace-variant
            // assigned_pic in the DB merges into the same bucket as its clean
            // counterpart, instead of MySQL's exact-match GROUP BY splitting them
            // into two counts for what is visibly the same person.
            foreach ($chartStmt->fetchAll() as $row) {
                $pic = trim((string)($row['assigned_pic'] ?? ''));
                if ($pic === '') continue;
                $workload_np[$pic] = ($workload_np[$pic] ?? 0) + (int)$row['count'];
            }

            $picStmt = $pdo->query("SELECT name FROM users WHERE role IN ('PIC', 'Admin') ORDER BY name ASC");
            $all_pics = $picStmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($np_prs as $r) {
                $r['assigned_pic'] = trim((string)($r['assigned_pic'] ?? ''));
                $r['_row_type'] = 'nonproject';
                $r['np_team'] = $np_team_code($r['team_name'] ?? '');
                $all_prs[] = $r;
            }
        } elseif ($persona === 'NONPROJECT') {
            // For Manager role: Get all PICs managed by this manager recursively
            $subordinates = get_all_subordinates($pdo, $manager_username);
            $np_team_names = [];
            foreach ($subordinates as $sub) {
                if ($sub['role'] === 'PIC' || $sub['role'] === 'Admin') {
                    $all_pics[] = $sub['name'];
                }
                if (!empty($sub['team_name'])) {
                    $np_team_names[] = $sub['team_name'];
                }
            }
            $all_pics = array_unique($all_pics);
            sort($all_pics);

            // The manager's own team counts too, so a manager whose subordinates
            // have no team set still gets their own team button.
            try {
                $own_stmt = $pdo->prepare("SELECT team_name FROM users WHERE username = ? LIMIT 1");
                $own_stmt->execute([$manager_username]);
                $own_team = trim((string)($own_stmt->fetchColumn() ?: ''));
                if ($own_team !== '') { $np_team_names[] = $own_team; }
            } catch (PDOException $e) {
                // Own team is additive only -- a failure just leaves the
                // subordinate-derived list untouched.
            }

            $np_team_names = array_unique($np_team_names);
            foreach ($np_team_names as $tname) {
                $code = $np_team_code($tname);
                if ($code) $np_team_codes[] = $code;
            }
            $np_team_codes = array_unique($np_team_codes);
            sort($np_team_codes);

            // P.Groups this manager's team(s) own per pgroup_team_mappings -- kept
            // separate from $all_pics (current-assignee pool) so a line reassigned
            // outside the team's subordinate list stays visible on ownership, even
            // though it no longer matches any subordinate's name. See dashboard_mgr.php
            // "Reassign PR Ticket": reassignment only ever changes assigned_pic, never
            // p_group, so p_group ownership is the correct/stable visibility signal.
            $owned_pgroups = [];
            if (!empty($np_team_names)) {
                $teamPh = implode(',', array_fill(0, count($np_team_names), '?'));
                $pgStmt = $pdo->prepare("SELECT p_group FROM pgroup_team_mappings WHERE team_name IN ($teamPh)");
                $pgStmt->execute(array_values($np_team_names));
                $owned_pgroups = $pgStmt->fetchAll(PDO::FETCH_COLUMN);
            }

            foreach ($subordinates as $sub) {
                if ($sub['role'] === 'PIC' || $sub['role'] === 'Admin') {
                    $code = $np_team_code($sub['team_name'] ?? '');
                    if ($code !== '') {
                        $np_pics_by_team[$code][$sub['name']] = true;
                    }
                }
            }
            foreach ($np_pics_by_team as $code => $set) {
                $names = array_keys($set); sort($names); $np_pics_by_team[$code] = $names;
            }
            // $np_team_codes stays exactly as derived above: a Procurement Manager
            // only ever sees rows belonging to their own team(s), so rendering the
            // full canonical team list here just produced buttons that filtered the
            // table down to nothing.
            $all_pics = array_unique($all_pics);
            sort($all_pics);

            if (count($all_pics) > 0) {
                // Get PRs assigned to any of this manager's PICs, unassigned PRs,
                // intercompany tickets, or PRs whose p_group is owned by this
                // manager's team(s) regardless of current assignee (fixes: a line
                // reassigned to someone outside the team, e.g. a PO Admin, must stay
                // visible to the owning team).
                $inQuery = implode(',', array_fill(0, count($all_pics), '?'));
                $ownedPgroupSql = '';
                $ownedPgroupParams = [];
                if (!empty($owned_pgroups)) {
                    $pgPh = implode(',', array_fill(0, count($owned_pgroups), '?'));
                    $ownedPgroupSql = " OR prs.p_group IN ($pgPh)";
                    $ownedPgroupParams = array_values($owned_pgroups);
                }
                $prStmt = $pdo->prepare("SELECT prs.ticket_no, prs.ticket_line_item, prs.master_data_id, prs.pr_date, prs.p_org, prs.p_group, prs.requestor_name, prs.site_allocation, prs.item_description, prs.sku, prs.qty, prs.status_ticket, prs.assigned_pic, prs.assigned_by_ai, prs.last_updated_by, prs.last_updated_date, prs.canvassing_no, {$canvassing_status_select}, prs.canvassing_date, prs.canvassing_description, prs.ticket_helpdesk_no, prs.canvassing_date_approver_1, prs.po_number, prs.po_create_date, prs.po_amount, prs.po_currency, prs.ticket_type, md.level_0, md.level_1, md.level_2, md.level_3, md.level_4, md.level_5 FROM prs LEFT JOIN pgroup_team_mappings m ON prs.p_group = m.p_group LEFT JOIN master_data md ON prs.master_data_id = md.id WHERE (prs.assigned_pic IN ($inQuery) OR prs.assigned_pic IS NULL OR LOWER(prs.ticket_type) IN ('intercompany', 'intercompany new store')" . $ownedPgroupSql . ") AND prs.status_ticket NOT IN ('Cancel', 'Cancelled', 'Cancel Ticket') AND (prs.pr_date >= '2026-01-01' OR prs.pr_date IS NULL OR (prs.canvassing_no IS NOT NULL AND prs.canvassing_no != '') OR (prs.po_number IS NOT NULL AND prs.po_number != ''))" . $project_exclude_sql . " ORDER BY prs.created_at DESC");
                $prStmt->execute(array_merge($all_pics, $ownedPgroupParams));
                $np_prs = $prStmt->fetchAll();

                $chartStmt = $pdo->prepare("SELECT assigned_pic, COUNT(*) as count FROM prs WHERE assigned_pic IN ($inQuery) AND status_ticket NOT IN ('Cancel', 'Cancelled', 'Cancel Ticket') AND (po_number IS NULL OR po_number = '') AND (pr_date >= '2026-01-01' OR pr_date IS NULL OR (canvassing_no IS NOT NULL AND canvassing_no != '') OR (po_number IS NOT NULL AND po_number != ''))" . $project_exclude_sql . " GROUP BY assigned_pic");
                $chartStmt->execute($all_pics);
                foreach ($chartStmt->fetchAll() as $row) {
                    $pic = trim((string)($row['assigned_pic'] ?? ''));
                    if ($pic === '') continue;
                    $workload_np[$pic] = ($workload_np[$pic] ?? 0) + (int)$row['count'];
                }
            } else {
                // Manager has no PICs: show all PRs
                $prStmt = $pdo->query("SELECT prs.ticket_no, prs.ticket_line_item, prs.master_data_id, prs.pr_date, prs.p_org, prs.p_group, prs.requestor_name, prs.site_allocation, prs.item_description, prs.sku, prs.qty, prs.status_ticket, prs.assigned_pic, prs.assigned_by_ai, prs.last_updated_by, prs.last_updated_date, prs.canvassing_no, {$canvassing_status_select}, prs.canvassing_date, prs.canvassing_description, prs.ticket_helpdesk_no, prs.canvassing_date_approver_1, prs.po_number, prs.po_create_date, prs.po_amount, prs.po_currency, prs.ticket_type, md.level_0, md.level_1, md.level_2, md.level_3, md.level_4, md.level_5 FROM prs LEFT JOIN pgroup_team_mappings m ON prs.p_group = m.p_group LEFT JOIN master_data md ON prs.master_data_id = md.id WHERE prs.status_ticket NOT IN ('Cancel', 'Cancelled', 'Cancel Ticket') AND (prs.pr_date >= '2026-01-01' OR prs.pr_date IS NULL OR (prs.canvassing_no IS NOT NULL AND prs.canvassing_no != '') OR (prs.po_number IS NOT NULL AND prs.po_number != ''))" . $project_exclude_sql . " ORDER BY prs.created_at DESC");
                $np_prs = $prStmt->fetchAll();
            }
            foreach ($np_prs as $r) {
                $r['assigned_pic'] = trim((string)($r['assigned_pic'] ?? ''));
                $r['_row_type'] = 'nonproject';
                $all_prs[] = $r;
            }
        }

        // ===== PROJECT SOURCE (PROJECT + COMBINED) =============================
        // Mirrors dashboard_project_head.php: NO AI. Effective officer = assigned_pic,
        // then parsed GP OFFICER resolved to an eligible officer, then source-backed
        // Ridwan Arifin, else Unassigned. Assignment is manual via ajax_project_assign.php
        // scoped to ticket_no + p_group; Intercompany rows stay read-only/system-routed.
        $project_workload   = [];   // effective officer => ticket+p_group unit count
        $officers_by_pgroup = [];   // p_group => [officer names]  (JS assign modal pool)
        $project_team_codes = [];   // group_number => 'PKR' | 'PKP' | 'MFM' | 'G#'
        if (($persona === 'PROJECT' || $persona === 'COMBINED') && $project_pgroups_available && !empty($project_groups)) {
            // Short codes are UI-only; canonical full names come from the DB.
            $team_short_codes = [1 => 'PKR', 2 => 'PKP', 3 => 'MFM'];
            $team_code = function($n) use ($team_short_codes) {
                return $team_short_codes[(int)$n] ?? ('G' . (int)$n);
            };
            foreach ($project_groups as $gn) { $project_team_codes[(int)$gn] = $team_code($gn); }

            $group_ph = implode(',', array_fill(0, count($project_groups), '?'));

            // Eligible officer pools + resolver (display hints + assign-modal options).
            $officers_by_group_number = [];
            $officer_resolver = [];
            $ostmt = $pdo->prepare("SELECT p_group, project_group_number, officer_name, officer_nip
                                    FROM project_pgroup_officers
                                    WHERE active = 1 AND project_group_number IN ($group_ph)
                                    ORDER BY officer_name");
            $ostmt->execute($project_groups);
            foreach ($ostmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $pg = $o['p_group']; $gnum = (int)$o['project_group_number'];
                $officers_by_pgroup[$pg][] = $o['officer_name'];
                if (!isset($officers_by_group_number[$gnum]) || !in_array($o['officer_name'], $officers_by_group_number[$gnum], true)) {
                    $officers_by_group_number[$gnum][] = $o['officer_name'];
                }
                $officer_resolver[$pg]['names'][strtolower(trim((string)$o['officer_name']))] = $o['officer_name'];
                $nip = trim((string)$o['officer_nip']);
                if ($nip !== '') { $officer_resolver[$pg]['nips'][$nip] = $o['officer_name']; }
            }

            // Resolve a raw GP OFFICER string to an eligible officer for $p_group (display only).
            $resolve_gp_officer = function($p_group, $gp_officer) use ($officer_resolver) {
                $gp = trim((string)$gp_officer);
                if ($gp === '' || !isset($officer_resolver[$p_group])) return null;
                $map = $officer_resolver[$p_group];
                $lc = strtolower($gp);
                if (isset($map['names'][$lc])) return $map['names'][$lc];
                if (preg_match('/^\d+/', $gp, $m) && isset($map['nips'][$m[0]])) return $map['nips'][$m[0]];
                return null;
            };
            // Source-backed Ridwan Arifin exception (Admin, intentionally NOT an officer):
            // counts toward workload only when the parsed GP OFFICER literally names him.
            $ridwan_user_exists = (bool)$pdo
                ->query("SELECT 1 FROM users WHERE username = 'ridwan.arifin' AND name = 'Ridwan Arifin' LIMIT 1")
                ->fetchColumn();
            $resolve_effective_officer = function($row) use ($resolve_gp_officer, $ridwan_user_exists) {
                $pic = trim((string)($row['assigned_pic'] ?? ''));
                if ($pic !== '') return $pic;
                $resolved = $resolve_gp_officer($row['p_group'] ?? '', $row['gp_officer'] ?? '');
                if ($resolved !== null) return $resolved;
                if ($ridwan_user_exists && strtolower(trim((string)($row['gp_officer'] ?? ''))) === 'ridwan arifin') return 'Ridwan Arifin';
                return null;
            };

            // Main project fetch, scoped to the persona's allowed groups.
            $pstmt = $pdo->prepare("
                SELECT prs.ticket_no, prs.ticket_line_item, prs.master_data_id, prs.pr_date, prs.p_org, prs.p_group, prs.requestor_name, prs.site_allocation,
                       prs.item_description, prs.status_ticket, prs.canvassing_no, {$canvassing_status_select}, prs.canvassing_date, prs.canvassing_description, prs.ticket_helpdesk_no,
                       prs.canvassing_date_approver_1, prs.po_number, prs.po_create_date, prs.po_amount, prs.po_currency, prs.ticket_type, prs.vendor_name, prs.assigned_pic, prs.assigned_by_ai, prs.last_updated_by, prs.last_updated_date,
                       prs.gp_officer, prs.created_at, pp.project_group_number, pp.project_group_name, md.level_0, md.level_1, md.level_2, md.level_3, md.level_4, md.level_5
                FROM prs
                JOIN project_pgroups pp ON prs.p_group = pp.p_group AND pp.status = 'ACTIVE'
                LEFT JOIN master_data md ON prs.master_data_id = md.id
                WHERE pp.project_group_number IN ($group_ph)
                  AND prs.status_ticket NOT IN ('Cancel', 'Cancelled', 'Cancel Ticket')
                  AND (prs.pr_date >= '2026-01-01' OR prs.pr_date IS NULL OR (prs.canvassing_no IS NOT NULL AND prs.canvassing_no != '') OR (prs.po_number IS NOT NULL AND prs.po_number != ''))
                ORDER BY prs.created_at DESC
            ");
            $pstmt->execute($project_groups);
            $project_lines = $pstmt->fetchAll(PDO::FETCH_ASSOC);

            $intercompany_types = ['intercompany', 'intercompany new store'];
            foreach ($project_lines as &$pl) {
                $pl['is_intercompany'] = in_array(strtolower(trim((string)$pl['ticket_type'])), $intercompany_types, true);
            }
            unset($pl);

            // Aggregate to one visible row per ticket_no + p_group (assignment scope).
            $grouped = [];
            foreach ($project_lines as $line) {
                $key = $line['ticket_no'] . '|' . $line['p_group'];
                if (!isset($grouped[$key])) { $line['line_count'] = 1; $grouped[$key] = $line; continue; }
                $g = &$grouped[$key];
                $g['line_count']++;
                // The row shows one representative line per ticket. Pick the earliest-created
                // line (the ticket's first line) so the item description / SKU / qty shown --
                // and the AON classification derived from them -- are stable and predictable,
                // regardless of the SQL fetch order.
                $line_ts = strtotime((string)($line['created_at'] ?? '')) ?: PHP_INT_MAX;
                $rep_ts  = strtotime((string)($g['created_at'] ?? '')) ?: PHP_INT_MAX;
                if ($line_ts < $rep_ts) {
                    $g['created_at']       = $line['created_at'] ?? null;
                    $g['ticket_line_item'] = $line['ticket_line_item'] ?? '';
                    $g['item_description'] = $line['item_description'];
                    $g['sku']              = $line['sku'] ?? null;
                    $g['qty']              = $line['qty'] ?? null;
                    $g['level_0']          = $line['level_0'] ?? null;
                    $g['level_1']          = $line['level_1'] ?? null;
                    $g['level_2']          = $line['level_2'] ?? null;
                    $g['level_3']          = $line['level_3'] ?? null;
                    $g['level_4']          = $line['level_4'] ?? null;
                    $g['level_5']          = $line['level_5'] ?? null;
                }
                if (trim((string)($g['ticket_helpdesk_no'] ?? '')) === '' && trim((string)($line['ticket_helpdesk_no'] ?? '')) !== '') {
                    $g['ticket_helpdesk_no'] = $line['ticket_helpdesk_no'];
                }
                if (trim((string)$g['assigned_pic']) === '' && trim((string)$line['assigned_pic']) !== '') {
                    $g['assigned_pic'] = $line['assigned_pic'];
                }
                if (trim((string)($g['gp_officer'] ?? '')) === '' && trim((string)($line['gp_officer'] ?? '')) !== '') {
                    $g['gp_officer'] = $line['gp_officer'];
                }
                // Initial Quotation Price is keyed by canvassing: keep a separate
                // non-empty canvassing_no for it (first one seen), so a row whose
                // representative line isn't canvassed yet still opens the modal --
                // without changing the canvassing_no / status the row displays.
                if (trim((string)($g['quotation_canvassing_no'] ?? '')) === '' && trim((string)($line['canvassing_no'] ?? '')) !== '') {
                    $g['quotation_canvassing_no'] = $line['canvassing_no'];
                }
                // A project row stands for several PR lines, each with its own
                // PIC edit trail. Show the most recent one: the question the
                // column answers is "when was the PIC on this ticket last
                // changed", not "what did the representative line record".
                if (!empty($line['last_updated_date'])
                    && (empty($g['last_updated_date'])
                        || strtotime((string)$line['last_updated_date']) > strtotime((string)$g['last_updated_date']))) {
                    $g['last_updated_date'] = $line['last_updated_date'];
                    $g['last_updated_by']   = $line['last_updated_by'] ?? null;
                }
                if (!empty($line['is_intercompany'])) { $g['is_intercompany'] = true; }
                unset($g);
            }

            foreach ($grouped as $g) {
                $eff = $resolve_effective_officer($g);
                if ($eff !== null) { $project_workload[$eff] = ($project_workload[$eff] ?? 0) + 1; }
                $resolved_officer = (empty($g['is_intercompany']) && trim((string)$g['assigned_pic']) === '')
                    ? $resolve_gp_officer($g['p_group'], $g['gp_officer'] ?? '')
                    : null;
                $all_prs[] = [
                    '_row_type'            => 'project',
                    'ticket_no'            => $g['ticket_no'],
                    'ticket_line_item'     => $g['ticket_line_item'] ?? '',
                    'master_data_id'       => $g['master_data_id'] ?? null,
                    'pr_date'              => $g['pr_date'],
                    'p_org'                => $g['p_org'],
                    'requestor_name'       => $g['requestor_name'],
                    // Project rows aggregate several lines into one; the
                    // representative line's site carries the value, same as
                    // item_description/sku/qty above.
                    'site_allocation'      => $g['site_allocation'] ?? '',
                    'item_description'     => $g['item_description'],
                    'sku'                  => $g['sku'] ?? null,
                    'qty'                  => $g['qty'] ?? null,
                    'level_0'              => $g['level_0'] ?? null,
                    'level_1'              => $g['level_1'] ?? null,
                    'level_2'              => $g['level_2'] ?? null,
                    'level_3'              => $g['level_3'] ?? null,
                    'level_4'              => $g['level_4'] ?? null,
                    'level_5'              => $g['level_5'] ?? null,
                    'canvassing_no'        => $g['canvassing_no'],
                    'quotation_canvassing_no' => trim((string)($g['canvassing_no'] ?? '')) !== '' ? $g['canvassing_no'] : ($g['quotation_canvassing_no'] ?? ''),
                    'canvassing_status'    => $g['canvassing_status'],
                    'canvassing_date'      => $g['canvassing_date'],
                    // Carries the STT work-type code that decides this row's SLA
                    // target (sla_attach below reads it).
                    'canvassing_description' => $g['canvassing_description'] ?? null,
                    'ticket_helpdesk_no'   => $g['ticket_helpdesk_no'] ?? null,
                    'canvassing_date_approver_1' => $g['canvassing_date_approver_1'] ?? null,
                    'status_ticket'        => $g['status_ticket'],
                    'po_number'            => $g['po_number'],
                    'po_create_date'       => $g['po_create_date'] ?? null,
                    // A ticket with no PO yet has no line amount to show -- the
                    // amount only means something once a PO number exists.
                    'po_amount'            => !empty($g['po_number']) ? (float)($g['po_amount'] ?? 0) : 0,
                    'po_currency'          => $g['po_currency'] ?? null,
                    'ticket_type'          => $g['ticket_type'],
                    'assigned_pic'         => trim((string)($g['assigned_pic'] ?? '')),
                    'assigned_by_ai'       => $g['assigned_by_ai'],
                    'last_updated_by'      => $g['last_updated_by'] ?? null,
                    'last_updated_date'    => $g['last_updated_date'] ?? null,
                    'p_group'              => $g['p_group'],
                    'project_group_number' => (int)$g['project_group_number'],
                    'project_group_name'   => $g['project_group_name'],
                    'team_code'            => $team_code($g['project_group_number']),
                    'is_intercompany'      => !empty($g['is_intercompany']),
                    'line_count'           => $g['line_count'] ?? 1,
                    'effective_officer'    => $eff,
                    'resolved_officer'     => $resolved_officer,
                ];
            }

            // PROJECT persona has no Non-Project source, so seed the Assigned-PIC filter
            // dropdown from the eligible Project officer pool (COMBINED already lists all PICs).
            if ($persona === 'PROJECT') {
                $proj_officer_names = [];
                foreach ($officers_by_group_number as $names) {
                    foreach ($names as $n) { $proj_officer_names[$n] = true; }
                }
                $all_pics = array_keys($proj_officer_names);
                sort($all_pics);
            }
        }

        // --- TEAM FILTER OPTIONS (persona-aware, UI-only) ----------------------
        if ($persona === 'COMBINED') {
            // $np_team_codes = ['CSGSM', 'TIGS', 'FNBOM', 'MFM', 'PKP', 'PKR'];
            $np_team_codes = ['CSGSM', 'TIGS', 'FNBOM', 'MFM', 'PKP', 'PKR', 'ADMIN'];
        }
        $show_team_filter = (count($np_team_codes) + count($project_team_codes)) > 0;

        // --- NON-PROJECT TEAM -> ELIGIBLE PIC POOL (COMBINED only) -------------
        if ($persona === 'COMBINED') {
            try {
                $pmrows = $pdo->query("SELECT name as pic_name, team_name FROM users WHERE role IN ('PIC', 'Admin') AND name IS NOT NULL AND name <> ''")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($pmrows as $pm) {
                    $code = $np_team_code($pm['team_name'] ?? '');
                    if ($code === '') { continue; }
                    $np_pics_by_team[$code][$pm['pic_name']] = true;
                }
            } catch (PDOException $e) {
                // failed
            }
            foreach ($np_pics_by_team as $code => $set) { $names = array_keys($set); sort($names); $np_pics_by_team[$code] = $names; }
        }

        // --- ASSIGNED PIC CANONICALIZATION --------------------------------------
        // Runs unconditionally so the table and the chart both see clean names.
        // Resolution order: registered PIC name (case-insensitive) -> users.gp_officer_id
        // alias -> P.Group's designated owner (pgroup_team_mappings.pic_name).
        $pic_team_lookup = [];
        foreach ($np_pics_by_team as $t => $pics) {
            foreach ($pics as $picName) {
                $key = strtolower(trim((string)$picName));
                if ($key !== '' && !isset($pic_team_lookup[$key])) $pic_team_lookup[$key] = $t;
            }
        }

        $gp_officer_alias_map = [];
        try {
            $gpRows = $pdo->query("SELECT gp_officer_id, name FROM users WHERE gp_officer_id IS NOT NULL AND gp_officer_id != ''")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($gpRows as $row) {
                foreach (explode(',', $row['gp_officer_id']) as $alias) {
                    $key = strtolower(trim($alias));
                    if ($key !== '') $gp_officer_alias_map[$key] = $row['name'];
                }
            }
        } catch (PDOException $e) {}

        $pgroup_pic_map = [];
        try {
            $ppRows = $pdo->query("SELECT p_group, pic_name FROM pgroup_team_mappings WHERE pic_name IS NOT NULL AND pic_name != ''")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ppRows as $row) {
                $pgroup_pic_map[strtoupper(trim((string)$row['p_group']))] = trim((string)$row['pic_name']);
            }
        } catch (PDOException $e) {}

        foreach ($all_prs as &$pr) {
            $rawPic = trim((string)($pr['assigned_pic'] ?? ''));
            if ($rawPic === '') continue;
            if (isset($pic_team_lookup[strtolower($rawPic)])) { $pr['assigned_pic'] = $rawPic; continue; }
            $alias = $gp_officer_alias_map[strtolower($rawPic)] ?? null;
            if ($alias !== null) {
                $pr['assigned_pic'] = $alias;
                continue;
            }
            $pgroupPic = $pgroup_pic_map[strtoupper(trim((string)($pr['p_group'] ?? '')))] ?? null;
            $pr['assigned_pic'] = ($pgroupPic !== null && isset($pic_team_lookup[strtolower($pgroupPic)])) ? $pgroupPic : $rawPic;
        }
        unset($pr);

        // --- CALCULATE SMART STATUS ---
        foreach ($all_prs as &$pr) {
            $pr['display_status'] = compute_pr_display_status(
                $pr['status_ticket'] ?? '',
                $pr['canvassing_status'] ?? '',
                $pr['po_number'] ?? '',
                $pr['canvassing_no'] ?? ''
            );
            $pr['status_abbr'] = pr_status_abbr($pr['display_status'], $pr['status_ticket'] ?? '');
        }
        unset($pr);

        // --- AON CLASSIFICATION (Filter by Type -> AON) ------------------------
        $aon_master = [];
        foreach ($pdo->query(
            "SELECT sku_code, valid_from, valid_until, quantity, used_qty, remain_qty
             FROM aon_skus
             WHERE is_deleted = 0 AND alokasi IS NOT NULL AND alokasi != ''"
        )->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $aon_master[strtoupper(trim((string)$a['sku_code']))] = $a;
        }

        $aon_today = date('Y-m-d');
        foreach ($all_prs as &$pr) {
            // A line that reached "PO Created" is done being monitored: contract
            // validity and remaining qty no longer say anything actionable, so the
            // badge drops to grey instead of staying green/red/yellow. The PO number
            // is only handed to the classifier once display_status confirms the line
            // really is PO Created -- a PO number on an Escalated/Sendback/Cancelled
            // line keeps its live green/red/yellow warning.
            $aon_po = (($pr['display_status'] ?? '') === 'PO Created')
                ? (string)($pr['po_number'] ?? '')
                : '';
            // pr_date decides whether the contract was live yet when the PR was
            // raised -- a line raised before valid_from is regular work, not AON.
            $aon = aon_classify_ticket($aon_master, $pr['item_description'] ?? '', $pr['sku'] ?? '', $pr['qty'] ?? null, $aon_today, $aon_po, $pr['pr_date'] ?? null);
            $pr['aon_status'] = $aon['status'];
            $pr['aon_reason'] = $aon['reason'];
        }
        unset($pr);

        // --- SLA COUNTDOWN (Days remaining against the ticket's SLA target) ----
        // Adds sla_label / sla_status / sla_badge / sla_title / sla_sort to every
        // row, same logic used by every other role dashboard (includes/sla_rules.php).
        sla_attach($pdo, $all_prs);

        // --- UNIFIED WORKLOAD (persona-aware) ----------------------------------
        // Only the initial meta load needs this -- ajax_dashboard_table.php's
        // per-page/filter requests skip it entirely.
        $chart_labels = [];
        $chart_counts = [];
        $chart_raw_data = [];
        if ($include_extras) {
            $workload_merged = $workload_np;
            foreach ($project_workload as $name => $cnt) {
                $workload_merged[$name] = ($workload_merged[$name] ?? 0) + $cnt;
            }
            arsort($workload_merged);
            $chart_labels = array_keys($workload_merged);
            $chart_counts = array_values($workload_merged);

            foreach ($all_prs as $pr) {
                if (empty($pr['assigned_pic'])) continue;
                $teamName = 'Unknown Team';
                if (($pr['_row_type'] ?? '') === 'nonproject') {
                    $picActualTeam = $pic_team_lookup[strtolower($pr['assigned_pic'])] ?? null;
                    $teamName = $picActualTeam ? $picActualTeam : (!empty($pr['np_team']) ? $pr['np_team'] : 'Unknown Team');
                } else {
                    // A PIC who isn't a registered officer for this P.Group (e.g. an Admin
                    // Team member occasionally covering project tickets) counts toward their
                    // own team instead of the project group's -- registered officers still
                    // count toward the project team as before.
                    $isOfficer = in_array($pr['assigned_pic'], $officers_by_pgroup[$pr['p_group'] ?? ''] ?? [], true);
                    $personalTeam = $isOfficer ? null : ($pic_team_lookup[strtolower($pr['assigned_pic'])] ?? null);
                    $teamName = $personalTeam ?: (!empty($pr['team_code']) ? $pr['team_code'] : 'Unknown Team');
                }
                $chart_raw_data[] = [
                    'ticket_no' => $pr['ticket_no'],
                    'assigned_pic' => $pr['assigned_pic'],
                    'team_name' => $teamName,
                    'canvassing_no' => $pr['canvassing_no'] ?? '',
                    'po_number' => $pr['po_number'] ?? '',
                    'po_amount' => (float)($pr['po_amount'] ?? 0),
                    // pr_date + sla_status power the workload chart's own SLA/date
                    // filters (dashboard_mgr.php) -- sla_status comes from
                    // sla_attach() above, already computed per row.
                    'pr_date' => $pr['pr_date'] ?? null,
                    'sla_status' => $pr['sla_status'] ?? 'na',
                    // Everything below mirrors the fields ajax_dashboard_table.php's
                    // gf/per-column/global search reads (dt_search_field/haystack) so
                    // dashboard_mgr.php can replicate the exact same filter predicates
                    // client-side against this raw dataset and keep the workload/SLA
                    // charts in sync with whatever the #prTable toolbar is showing.
                    'item_description' => $pr['item_description'] ?? '',
                    'display_status' => $pr['display_status'] ?? '',
                    'requestor_name' => $pr['requestor_name'] ?? '',
                    'ticket_helpdesk_no' => $pr['ticket_helpdesk_no'] ?? '',
                    'site_allocation' => $pr['site_allocation'] ?? '',
                    'resolved_officer' => $pr['resolved_officer'] ?? '',
                    'ticket_type' => $pr['ticket_type'] ?? '',
                    'aon_status' => $pr['aon_status'] ?? 'none',
                    '_row_type' => $pr['_row_type'] ?? 'nonproject',
                    'np_team' => $pr['np_team'] ?? '',
                    'project_group_number' => $pr['project_group_number'] ?? '',
                    'assigned_by_ai' => (int)($pr['assigned_by_ai'] ?? 0),
                    'p_group' => $pr['p_group'] ?? '',
                    'project_group_name' => $pr['project_group_name'] ?? '',
                    'team_code' => $pr['team_code'] ?? '',
                ];
            }
        }

        $unique_tickets    = array_unique(array_column($all_prs, 'ticket_no'));
        $unique_porgs      = array_filter(array_unique(array_column($all_prs, 'p_org')));
        $unique_requestors = array_filter(array_unique(array_column($all_prs, 'requestor_name')));
        $unique_statuses   = array_filter(array_unique(array_column($all_prs, 'display_status')));
        // array_column skips rows missing the key entirely, and array_filter drops
        // the blanks — lines with no site on them simply have no option to offer.
        $unique_sites      = array_filter(array_map('trim', array_unique(array_column($all_prs, 'site_allocation'))));

        sort($unique_tickets);
        sort($unique_porgs);
        sort($unique_requestors);
        sort($unique_statuses);
        sort($unique_sites);

        // --- LAST UPDATED + PENDING VENDOR-EVAL REMINDERS ------------------------
        // Meta-only: skipped on ajax_dashboard_table.php's per-page/filter requests.
        $last_updated_label = 'unavailable';
        $pending_evals = [];
        if ($include_extras) {
            try {
                date_default_timezone_set('Asia/Jakarta');
                $li = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'last_pr_upload_at'")->fetchColumn();
                if (!empty($li)) { $last_updated_label = date('d M Y, H:i', strtotime($li)) . ' WIB'; }
            } catch (PDOException $e) {
                $last_updated_label = 'unavailable';
            }

            $evalQuery = "
                SELECT p.po_number, p.vendor_name, p.requestor_name, MAX(p.gr_date) as gr_date
                FROM prs p
                LEFT JOIN vendor_evaluations v ON p.po_number = v.po_number
                WHERE p.po_number IS NOT NULL AND p.po_number != ''
                  AND p.vendor_name IS NOT NULL AND p.vendor_name != ''
                  AND v.id IS NULL
                  AND (p.pr_date >= '2026-05-01' OR p.pr_date IS NULL OR (p.canvassing_no IS NOT NULL AND p.canvassing_no != '') OR (p.po_number IS NOT NULL AND p.po_number != ''))
                GROUP BY p.po_number, p.vendor_name, p.requestor_name
                HAVING MAX(p.gr_date) IS NOT NULL AND MAX(p.gr_date) <= CURDATE()
                ORDER BY gr_date ASC
            ";
            $pending_evals = $pdo->query($evalQuery)->fetchAll(PDO::FETCH_ASSOC);
        }

        return [
            'rows'                => array_values($all_prs),
            'persona'             => $persona,
            'chart_labels'        => $chart_labels,
            'chart_counts'        => $chart_counts,
            'chart_raw_data'      => $chart_raw_data,
            'pending_evals'       => $pending_evals,
            'last_updated'        => $last_updated_label,
            'unique_tickets'      => array_values($unique_tickets),
            'unique_porgs'        => array_values($unique_porgs),
            'unique_requestors'   => array_values($unique_requestors),
            'unique_statuses'     => array_values($unique_statuses),
            'unique_sites'        => array_values($unique_sites),
            'all_pics'            => array_values($all_pics),
            'np_pics_by_team'     => $np_pics_by_team,
            'show_team_filter'    => $show_team_filter,
            'officers_by_pgroup'  => $officers_by_pgroup,
            'np_team_codes'       => $np_team_codes,
            'project_team_codes'  => $project_team_codes,
        ];
    }
}
