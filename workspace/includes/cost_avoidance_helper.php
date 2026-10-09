<?php
// includes/cost_avoidance_helper.php
//
// Shared SQL builders for the Cost Avoidance Savings Report and its charts.
//
// pages/cost_avoidance.php used to SELECT every PO row with no LIMIT and then
// derive tax treatment, currency conversion, nett unit price, cost avoidance and
// team bucketing inside one big PHP loop. Those derivations now live here as SQL
// expression fragments so the page, ajax_cost_avoidance_table.php and
// ajax_cost_avoidance_charts.php all compute the exact same numbers from the
// exact same rules — the way includes/sla_helper.php does for the SLA report.
//
// NUMERIC PARITY: the old loop did its arithmetic in PHP floats (IEEE double).
// Plain MySQL arithmetic on DECIMAL columns uses exact decimal math instead and
// rounds differently, which would make the migrated totals drift from the
// figures users see today. Every amount is therefore pushed to DOUBLE with the
// portable `+ 0e0` idiom (works on both MySQL 8 and the MariaDB used in prod;
// CAST(... AS DOUBLE) is not available on older MariaDB) so the SQL reproduces
// the PHP result bit for bit.
//
// FAIL-OPEN: project_pgroups, purchases_2025 and prs.pillar are all optional in
// some environments (the local Docker DB has no project_pgroups at all). Their
// presence is detected once per request in ca_context() and the generated SQL
// simply omits the corresponding join/filter when they are missing, mirroring
// the guard style of load_project_team_map() in includes/project_team_helper.php.

// Read-only: supplies aon_sku_filter_sql() for ca_is_aon_expr()'s AON exclusion
// below. Same include convention cost_savings_helper.php already uses for the
// same function. Nothing in the AON feature area is modified by this file.
require_once __DIR__ . '/aon_helper.php';
require_once __DIR__ . '/currency_helper.php'; // ca_get_active_currencies(), ca_to_idr(), ca_currency_case_expr()
require_once __DIR__ . '/ca_intercompany_exceptions.php'; // ca_not_intercompany_sql(): the few Intercompany tickets allowed into Cost Avoidance

/**
 * Resolve everything the SQL builders need that is a per-request setting or a
 * schema-shape question. Call once per request and pass the result around.
 */
function ca_context(PDO $pdo): array {
    $mode = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'sla_mapping_mode'")->fetchColumn() ?: 'pgroup';
    $usd_rate = (float)($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'usd_exchange_rate'")->fetchColumn() ?: 16000);

    return [
        'mode'                => $mode,
        'usd_rate'            => $usd_rate,
        // 'currencies' = ACTIVE ones (what a person may pick for a new entry);
        // 'currencies_all' = every currency with a rate, active or not, which is
        // what IDR conversion uses so deactivating one never re-values saved rows.
        'currencies'          => ca_get_active_currencies($pdo),
        'currencies_all'      => ca_get_convertible_currencies($pdo),
        'has_pillar'          => ca_has_column($pdo, 'prs', 'pillar'),
        'has_2025'            => ca_has_table($pdo, 'purchases_2025'),
        'has_project_pgroups' => ca_has_table($pdo, 'project_pgroups'),
        // Per-row exchange-rate snapshot columns (see the fx_rate migration in
        // config/database.php). Fail-open like the other optional bits: without
        // them every conversion simply uses the current currencies.rate_to_idr.
        'has_baseline_fx'     => ca_has_column($pdo, 'prs', 'baseline_fx_rate'),
        'has_manual_fx'       => ca_has_column($pdo, 'manual_cost_avoidance', 'fx_rate'),
    ];
}

/**
 * Clean the quotation / Drive "link" a user typed. It is FREE TEXT (requested
 * 2026-10-01): a URL, a file name, "sent by email", anything. Empty is fine.
 * Because it need not be a URL, every place that renders it as <a href> must
 * go through ca_link_href() so a value like `javascript:...` is never made
 * clickable. Returns ['ok'=>bool, 'value'=>string, 'message'=>string].
 */
function ca_clean_link($v, int $max = 500): array {
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string)$v));
    if ($s === '') return ['ok' => true, 'value' => '', 'message' => ''];
    if (mb_strlen($s) > $max) return ['ok' => false, 'value' => '', 'message' => "Link is too long (max $max characters)."];
    return ['ok' => true, 'value' => $s, 'message' => ''];
}

// The text itself if it is a plain http(s) URL (safe to use as an href), else ''.
function ca_link_href($v): string {
    $s = trim((string)$v);
    return preg_match('#^https?://[^\s<>"\']+$#i', $s) ? $s : '';
}

function ca_has_table(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function ca_has_column(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

// Multi-currency IDR conversion (ca_to_idr(), ca_currency_case_expr()) now
// lives in includes/currency_helper.php, required above. Manual Cost
// Avoidance's PHP-side aggregation (ca_manual_aggregate() below) and the SQL
// builders (ca_po_idr_expr()/ca_baseline_idr_expr() further down) both call
// into it so the PHP and SQL sides of "convert to IDR" stay defined next to
// each other and in sync across every supported currency, not just USD.

/**
 * KPI target percentage per team code. Project team targets are managed via
 * db_backups/project_analytics_targets.sql; the fallbacks below are the approved
 * defaults used when a settings row is not present.
 */
function ca_team_targets(PDO $pdo): array {
    $raw = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'target_%'")
               ->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'CSGSM' => (float)($raw['target_CSGSM'] ?? 2.5),
        'TIGS'  => (float)($raw['target_TIGS']  ?? 4.5),
        'FNBOM' => (float)($raw['target_FNBOM'] ?? 2.5),
        'ADMIN' => (float)($raw['target_ADMIN'] ?? 0),
        'PKR'   => (float)($raw['target_PKR']   ?? 4.5),
        'PKP'   => (float)($raw['target_PKP']   ?? 4.5),
        'MFM'   => (float)($raw['target_MFM']   ?? 4.5),
    ];
}

/**
 * project_group_number -> short team code. Mirrors the pp.project_group_number
 * CASE inside ca_team_expr() (1=PKR, 2=PKP, 3=MFM) — kept as its own map here
 * because ca_resolve_session_team_scope() needs it in plain PHP, not SQL.
 */
const CA_PROJECT_GROUP_CODE = [1 => 'PKR', 2 => 'PKP', 3 => 'MFM'];

/**
 * Short team code -> v_item_taxonomy.level_1, for the three Project teams
 * only. Confirmed live against v_item_taxonomy 2026-07-31. L0 is deliberately
 * NOT hardcoded here (PKR/PKP share L0="Project / Construction" but differ at
 * L1) — look it up live via ca_project_level2_options()'s caller if ever
 * needed, per the feasibility report §2.
 */
const CA_PROJECT_TEAM_LEVEL1 = [
    'PKR' => 'Project - Retail',
    'PKP' => 'Project - Property',
    'MFM' => 'Maintenance & Facility Management',
];

/**
 * THE Project taxonomy (Level 2 "type of work") -- source of truth from the
 * business (confirmed by user, 2026-09-29; list dated 2026-09-17), per team:
 * code => label. Deliberately in code, NOT read from v_item_taxonomy: that
 * table disagrees between environments (local lists every code under all 3
 * teams; production still has the older, code-less list), which confused the
 * pickers. Stored value is the label (prs.ca_taxonomy_level_2).
 */
const CA_PROJECT_TAXONOMY = [
    'PKR' => [   // Project - Retail
        'J1' => 'Civil-Architecture',
        'J2' => 'FF&E (Furniture, Fixture & Equipment)',
        'J3' => 'HVAC',
        'J4' => 'MEP',
    ],
    'PKP' => [   // Project - Property
        'I1' => 'Pondasi',
        'I2' => 'SAP',
        'I3' => 'ME&HVAC',
        'I4' => 'Infrastructure',
        'I5' => 'Equipment',
        'I6' => 'Consultant',
    ],
    'MFM' => [   // Maintenance & Facility Management
        'K1' => 'Equipment / Spareparts',
        'K2' => 'HVAC',
        'K3' => 'MEP',
        'K4' => 'Structure / Architecture',
    ],
];

/**
 * Valid Level 2 ("type of work") taxonomy options under one Project level_1,
 * for the Input Baselines "type of work" dropdown. Also doubles as the
 * tamper-check set for save_ca_taxonomy. The 3 Project teams come from
 * CA_PROJECT_TAXONOMY (in code order); any other level_1 still reads
 * v_item_taxonomy.
 */
function ca_project_level2_options(PDO $pdo, string $level1): array {
    $team = array_search($level1, CA_PROJECT_TEAM_LEVEL1, true);
    if ($team !== false && isset(CA_PROJECT_TAXONOMY[$team])) {
        return array_values(CA_PROJECT_TAXONOMY[$team]);
    }
    $stmt = $pdo->prepare("
        SELECT DISTINCT level_2 FROM v_item_taxonomy
        WHERE level_1 = ? AND level_2 IS NOT NULL AND level_2 != ''
        ORDER BY level_2 ASC
    ");
    $stmt->execute([$level1]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Manager user_ids granted unrestricted (Category-Manager-equivalent) Cost
 * Avoidance access despite holding the Manager role, not the CM role.
 * Currently: Hadi Sanjaya Risali (user_id 32) — confirmed live 2026-07-31
 * per the feasibility report's Addendum #2 §B2.
 */
const PROJECT_UNRESTRICTED_MANAGER_IDS = [32];

/**
 * Resolve a Cost Avoidance session's team scope. Six-branch precedence per
 * Addendum #2's confirmed access matrix, checked in this order:
 *   1. Category Manager                                      -> unrestricted
 *   2. Manager in PROJECT_UNRESTRICTED_MANAGER_IDS            -> unrestricted
 *   3. Manager heading >=1 active project_head_groups row     -> team-code union
 *   4. Manager with users.team_name populated                 -> own team only
 *   5. PIC active in project_pgroup_officers                  -> team-code union
 *   6. PIC with users.team_name populated                     -> own team only
 *   7. anything else (no resolvable team)                     -> deny
 *
 * SELECT DISTINCT is used (not LIMIT 1) for both project tables per Addendum
 * #1 §A4: nothing in the schema prevents an officer/head from spanning more
 * than one project_group_number, and Rio Tandy Darmawan (heads both PKR and
 * MFM) is a live example for heads today.
 *
 * @return array|null null = unrestricted, [] = deny, non-empty = allowed team codes
 */
function ca_resolve_session_team_scope(PDO $pdo, int $userId, string $role): ?array {
    if ($role === 'Category Manager') {
        return null;
    }

    // Viewer is an unrestricted READ role: it resolves to the same null scope as
    // a Category Manager (no team filter) without any of the write capability,
    // which cost_avoidance.php's POST handler and the baselines endpoint refuse
    // separately via ws_require_write(). Placed before the Manager branch so it
    // never falls through to the team_name / project-roster lookups, which a
    // Viewer account has no rows in and which would resolve it to a deny.
    if ($role === 'Viewer') {
        return null;
    }

    if ($role === 'Manager') {
        if (in_array($userId, PROJECT_UNRESTRICTED_MANAGER_IDS, true)) {
            return null;
        }

        $teams = ca_project_group_teams_for($pdo, 'project_head_groups', 'head_user_id', $userId);
        if ($teams) {
            return $teams;
        }

        $teamCode = ca_user_team_code($pdo, $userId);
        return $teamCode !== null ? [$teamCode] : [];
    }

    if ($role === 'PIC') {
        $teams = ca_project_group_teams_for($pdo, 'project_pgroup_officers', 'officer_user_id', $userId);
        if ($teams) {
            return $teams;
        }

        $teamCode = ca_user_team_code($pdo, $userId);
        return $teamCode !== null ? [$teamCode] : [];
    }

    return [];
}

/**
 * Distinct team codes a user resolves to via one of the two project roster
 * tables (project_head_groups.head_user_id or project_pgroup_officers.officer_user_id),
 * across their active rows. Returns [] if the user has no active rows there
 * at all — callers fall through to the users.team_name check in that case.
 */
function ca_project_group_teams_for(PDO $pdo, string $table, string $userColumn, int $userId): array {
    $stmt = $pdo->prepare("SELECT DISTINCT project_group_number FROM {$table} WHERE {$userColumn} = ? AND active = 1");
    $stmt->execute([$userId]);
    $groupNumbers = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $codes = [];
    foreach ($groupNumbers as $n) {
        $code = CA_PROJECT_GROUP_CODE[(int)$n] ?? null;
        if ($code !== null) {
            $codes[$code] = true;
        }
    }
    return array_keys($codes);
}

/**
 * A user's own team, translated from the full descriptive name users.team_name
 * stores (confirmed live: e.g. "Technology, Industrial, and General Services",
 * not "TIGS") to the short code ca_team_expr()/$filters['team'] use. Reuses
 * ca_manual_team_options()'s full-name map — the same bridge
 * ca_manual_aggregate() already relies on. Returns null when unset, blank, or
 * not one of the 7 recognized names.
 */
function ca_user_team_code(PDO $pdo, int $userId): ?string {
    $stmt = $pdo->prepare("SELECT team_name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $full = trim((string)($stmt->fetchColumn() ?: ''));
    if ($full === '') {
        return null;
    }
    $full_to_code = array_flip(ca_manual_team_options());
    return $full_to_code[$full] ?? null;
}

/**
 * Manual Cost Avoidance team dropdown: short code => full descriptive name.
 * manual_cost_avoidance.team stores the FULL name, while the charts, targets and
 * team filter buttons are all keyed by the short code, so this is the bridge
 * between the two. Kept separate from the PO-side acronym CASE in
 * ca_team_expr(): that one has to match the raw pgroup_team_mappings.team_name
 * values coming out of PO data and cannot be reworded freely.
 */
function ca_manual_team_options(): array {
    return [
        'CSGSM' => 'Corporate Services, General Supplies & Marketing',
        'TIGS'  => 'Technology, Industrial, and General Services',
        'FNBOM' => 'FNB and Operational Merchandise',
        'ADMIN' => 'Admin Team',
        'PKR'   => 'Project Konstruksi Retail',
        'PKP'   => 'Project Konstruksi Properti',
        'MFM'   => 'Maintenance & Facility Management',
    ];
}

/**
 * Assert that a Manual Cost Avoidance `team` value — either a submitted
 * m_team form value (full name) or an existing row's stored `team` column
 * (which the "Legacy rows saved before the dropdown stored full names
 * already hold a code" comment in ca_manual_aggregate() notes can
 * occasionally already be a bare code) — is within a session's resolved
 * team scope. Used two ways by the create/update/delete write actions:
 *   - write-VALUE check: is the team being submitted one the session may write to.
 *   - ownership check: is the team an EXISTING row already belongs to (via
 *     ca_manual_ca_owner_team()) one the session may touch at all — the
 *     genuinely new gap Addendum #3 §C2 found, independent of the value check.
 * Throws RuntimeException if disallowed; no-ops for an unrestricted session.
 *
 * $allowedTeams: null = unrestricted (no-op). [] = deny (always throws).
 */
function ca_assert_manual_team_allowed(?array $allowedTeams, ?string $teamValue): void {
    if ($allowedTeams === null) {
        return;
    }
    if (empty($allowedTeams)) {
        throw new RuntimeException('Cost Avoidance: session has no allowed team scope.');
    }

    $submitted = trim((string)$teamValue);
    $full_to_code = array_flip(ca_manual_team_options());
    // Same tolerance ca_manual_aggregate() already has for legacy rows: if
    // it's not a recognized full name, assume it's already a bare code.
    $code = $full_to_code[$submitted] ?? $submitted;

    if (!in_array($code, $allowedTeams, true)) {
        throw new RuntimeException('Cost Avoidance: team is outside your allowed team scope.');
    }
}

/**
 * An existing manual_cost_avoidance row's stored `team` value, by id. Feeds
 * ca_assert_manual_team_allowed()'s ownership check on update/delete. Returns
 * null if the id doesn't exist (or is soft-deleted) — ca_assert_manual_team_allowed()
 * then rejects for any team-scoped session, matching "can't verify ownership
 * of a row that isn't there, so don't allow the write."
 */
function ca_manual_ca_owner_team(PDO $pdo, int $id): ?string {
    $stmt = $pdo->prepare("SELECT team FROM manual_cost_avoidance WHERE id = ?");
    $stmt->execute([$id]);
    $team = $stmt->fetchColumn();
    return $team !== false ? $team : null;
}

/**
 * Aggregate the Manual (non-PO) Cost Avoidance side for the charts and the
 * Manual tab KPI. Deliberately still PHP over a fetched result set rather than
 * SQL: manual_cost_avoidance holds a few hundred rows at most and was never part
 * of what made the page slow, so pushing it to SQL would add a second dialect of
 * the same rules for no measurable gain.
 *
 * 'before' is the Manual equivalent of the PO chart's "Total Spend": the actual
 * final value (avoided_price * quantity), so both charts read the same way — a
 * baseline bar next to the Cost Avoidance bar.
 *
 * $forcedTeams is the session's resolved scope from ca_resolve_session_team_scope():
 * null (default) = unrestricted, no WHERE added — this is what the Mixed chart's
 * caller in ajax_cost_avoidance_charts.php MUST keep relying on (never pass this
 * argument there, per Addendum #3 §C2 — that chart stays unrestricted for every
 * session). [] = deny (matches nothing). Non-empty = only entries whose `team`
 * column (stored as the FULL name, see ca_manual_team_options()) is in scope.
 */
function ca_manual_aggregate(PDO $pdo, array $filters, array $currencies, ?array $forcedTeams = null): array {
    $where = "WHERE mca.is_active = 1";
    $params = [];
    list($start, $end) = ca_date_range($filters['g_filter'], $filters['g_from'], $filters['g_to']);
    if ($start && $end) {
        $where .= " AND mca.entry_date >= ? AND mca.entry_date <= ?";
        $params[] = $start;
        $params[] = $end;
    }

    if ($forcedTeams !== null) {
        if (empty($forcedTeams)) {
            $where .= " AND 1 = 0";
        } else {
            // Match both the full name (current dropdown writes) and the bare
            // code (legacy rows — see the same fallback a few lines below in
            // the $full_to_code lookup) so a forced scope cannot miss older
            // entries just because of how they happen to be stored.
            $team_options = ca_manual_team_options();
            $values = [];
            foreach ($forcedTeams as $code) {
                $values[] = $team_options[$code] ?? $code;
                $values[] = $code;
            }
            $values = array_unique($values);
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            $where .= " AND mca.team IN ({$placeholders})";
            foreach ($values as $value) {
                $params[] = $value;
            }
        }
    }

    // mca.category holds the level_4 taxonomy value; master_data supplies its
    // parent level_2 grouping (fewer, broader buckets) for the breakdown cards.
    $stmt = $pdo->prepare("
        SELECT mca.*, md.level_2 AS category_level2
        FROM manual_cost_avoidance mca
        LEFT JOIN master_data md ON md.level_4 = mca.category
        {$where}
        ORDER BY mca.entry_date DESC, mca.id DESC
    ");
    $stmt->execute($params);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $full_to_code = array_flip(ca_manual_team_options());
    $total_idr = 0;
    $category_breakdown = [];
    $team_financials = [];

    foreach ($entries as $row) {
        // fx_rate = the snapshot rate the entry was made at (NULL on legacy rows
        // -> current rate). See ca_to_idr().
        $row_rate = isset($row['fx_rate']) && $row['fx_rate'] !== null ? (float)$row['fx_rate'] : null;
        $amount_idr = ca_to_idr((float)$row['total_cost_avoidance'], $row['currency'], $currencies, $row_rate, true);
        $total_idr += $amount_idr;

        $cat = $row['category_level2'] ?: 'Uncategorized';
        if (!isset($category_breakdown[$cat])) $category_breakdown[$cat] = 0;
        $category_breakdown[$cat] += $amount_idr;

        // Legacy rows saved before the dropdown stored full names already hold a
        // code, so fall back to the raw stored value.
        $raw_team = $row['team'] ?: 'Unassigned';
        $team = $full_to_code[$raw_team] ?? $raw_team;
        if (!isset($team_financials[$team])) {
            $team_financials[$team] = ['before' => 0, 'avoidance' => 0];
        }
        $final_unit = $row['avoided_price'] !== null ? (float)$row['avoided_price'] : 0;
        $team_financials[$team]['before'] += ca_to_idr($final_unit, $row['currency'], $currencies, $row_rate, true) * (float)$row['quantity'];
        $team_financials[$team]['avoidance'] += $amount_idr;
    }

    arsort($category_breakdown);
    ksort($team_financials);

    return [
        'entries'            => $entries,
        'total_idr'          => $total_idr,
        'category_breakdown' => $category_breakdown,
        'team_financials'    => $team_financials,
    ];
}

/**
 * Quick-range date filter shared by the page and the AJAX endpoints. Moved
 * verbatim from pages/cost_avoidance.php's getDateRange() so a filter value
 * always resolves to the same window no matter who asks.
 */
function ca_date_range(?string $filter, ?string $from, ?string $to): array {
    $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    if ($filter === 'this_month') {
        return [
            (clone $now)->modify('first day of this month')->format('Y-m-d'),
            (clone $now)->modify('last day of this month')->format('Y-m-d'),
        ];
    } elseif ($filter === 'last_month') {
        return [
            (clone $now)->modify('first day of last month')->format('Y-m-d'),
            (clone $now)->modify('last day of last month')->format('Y-m-d'),
        ];
    } elseif ($filter === 'last_2_weeks') {
        return [
            (clone $now)->modify('-14 days')->format('Y-m-d'),
            $now->format('Y-m-d'),
        ];
    } elseif ($filter === 'custom' && !empty($from) && !empty($to)) {
        return [$from, $to];
    }
    return [null, null];
}

/**
 * Normalize the client-supplied filter payload into a fixed shape, so a missing
 * or malformed field can never reach the query builder. Counterpart of
 * sla_normalize_gf() in includes/sla_helper.php.
 */
function ca_normalize_filters($raw): array {
    $f = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($f)) $f = [];

    $filter = (string)($f['g_filter'] ?? 'all');
    if (!in_array($filter, ['all', 'this_month', 'last_month', 'last_2_weeks', 'custom'], true)) {
        $filter = 'all';
    }
    $from = trim((string)($f['g_from'] ?? ''));
    $to   = trim((string)($f['g_to'] ?? ''));
    // Anything that is not a bare ISO date is discarded rather than passed on.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to = '';

    return [
        'team'     => trim((string)($f['team'] ?? '')),
        'g_filter' => $filter,
        'g_from'   => $from,
        'g_to'     => $to,
    ];
}

// --- SQL EXPRESSION FRAGMENTS ----------------------------------------------
// Every fragment below assumes the FROM/JOIN aliases produced by ca_from_where():
//   p  = prs
//   m  = users (mode 'pic') or pgroup_team_mappings (mode 'pgroup')
//   pp = deduplicated ACTIVE project_pgroups (absent when the table is missing)

/**
 * Team bucket for a PR line. Ports the resolution at pages/cost_avoidance.php's
 * per-row loop: an ACTIVE Project PGroup wins outright (PKR / PKP / MFM by group
 * number), otherwise the Non-Project pillar/team name is mapped through the
 * acronym table. The `pillar ?: team_name ?: 'Unassigned'` chain is reproduced
 * with NULLIF so that an empty string falls through exactly like PHP's `?:`, and
 * TRIM is applied after the chain (not inside it) for the same reason.
 */
function ca_team_expr(array $ctx): string {
    $pillar = $ctx['has_pillar'] ? "NULLIF(p.pillar, ''), " : "";
    $raw_team = "TRIM(COALESCE({$pillar}NULLIF(m.team_name, ''), 'Unassigned'))";

    $acronym = "
        CASE {$raw_team}
            WHEN 'Corporate Services, General Supplies & Marketing' THEN 'CSGSM'
            WHEN 'Technology, Industrial, and General Services'     THEN 'TIGS'
            WHEN 'FNB and Operational Merchandise'                  THEN 'FNBOM'
            WHEN 'Admin Team'                                       THEN 'ADMIN'
            WHEN 'Project Konstruksi Retail'                        THEN 'PKR'
            WHEN 'Project Konstruksi Properti'                      THEN 'PKP'
            WHEN 'Maintenance & Facility Management'                THEN 'MFM'
            ELSE {$raw_team}
        END";

    if (!$ctx['has_project_pgroups']) {
        return trim($acronym);
    }

    return trim("
        CASE
            WHEN pp.project_group_number = 1 THEN 'PKR'
            WHEN pp.project_group_number = 2 THEN 'PKP'
            WHEN pp.project_group_number = 3 THEN 'MFM'
            WHEN pp.project_group_number IS NOT NULL THEN CONCAT('G', pp.project_group_number)
            ELSE {$acronym}
        END");
}

/**
 * The USD exchange rate as a SQL numeric literal.
 */
/**
 * PO amount converted to IDR across every active currency (see
 * ca_currency_case_expr() in currency_helper.php). `+ 0e0` — see the NUMERIC
 * PARITY note at the top. ca_effective_currencies() falls back to a
 * USD-only map for callers (e.g. cost_savings_helper.php's cs_context())
 * that only supply $ctx['usd_rate'], not the full $ctx['currencies'] map.
 */
function ca_po_idr_expr(array $ctx, bool $freezeAtBaseline = false): string {
    $cur = ca_effective_currencies($ctx);
    $strict = ca_is_strict_ctx($ctx);
    // Cost Avoidance (nett price, table, charts): a PO in the same foreign
    // currency as its baseline converts at the baseline's frozen rate, so an
    // exchange-rate edit cannot show up as fake avoidance. Other callers (Cost
    // Savings) keep the plain current-rate conversion.
    if ($freezeAtBaseline && $strict && !empty($ctx['has_baseline_fx'])) {
        return ca_currency_case_expr_frozen('p.po_amount', 'p.po_currency', 'p.baseline_currency', 'p.baseline_fx_rate', $cur);
    }
    return ca_currency_case_expr('p.po_amount', "p.po_currency", $cur, $strict);
}

/**
 * Whether the row's purchasing org falls under a PPN-inclusive prefix.
 * LEFT(UPPER(p_org), LENGTH(prefix)) = prefix is the exact SQL equivalent of the
 * old `strpos(strtoupper($p_org), $prefix) === 0`, and — unlike a LIKE — needs no
 * wildcard escaping on the stored prefix.
 */
function ca_is_inclusive_expr(): string {
    return "EXISTS (SELECT 1 FROM tax_rules t
                    WHERE LEFT(UPPER(p.p_org), CHAR_LENGTH(t.p_org_prefix)) = t.p_org_prefix)";
}

/** Nett unit price: PO total in IDR, stripped of PPN when inclusive, per unit. */
function ca_nett_unit_expr(array $ctx): string {
    $idr = ca_po_idr_expr($ctx, true);
    $inc = ca_is_inclusive_expr();
    return "((CASE WHEN {$inc} THEN {$idr} / 1.11 ELSE {$idr} END) / (p.qty + 0e0))";
}

/** Baseline (quotation) unit price converted to IDR, across every active currency. */
function ca_baseline_idr_expr(array $ctx): string {
    if (!empty($ctx['has_baseline_fx'])) {
        // Honour the rate the baseline was entered at (prs.baseline_fx_rate);
        // NULL (legacy) rows still convert at the current rate.
        return ca_currency_case_expr_rate('p.baseline_price', "p.baseline_currency", 'p.baseline_fx_rate', ca_effective_currencies($ctx), ca_is_strict_ctx($ctx));
    }
    return ca_currency_case_expr('p.baseline_price', "p.baseline_currency", ca_effective_currencies($ctx), ca_is_strict_ctx($ctx));
}

/** Cost avoidance for one PR line: (baseline - nett) * qty, all in IDR. */
function ca_avoidance_expr(array $ctx): string {
    return "((" . ca_baseline_idr_expr($ctx) . " - " . ca_nett_unit_expr($ctx) . ") * (p.qty + 0e0))";
}

/** Rows that already carry a quotation baseline belong to the Savings Report. */
function ca_has_baseline_cond(): string {
    return "(p.baseline_price IS NOT NULL AND p.baseline_price <> 0)";
}

/**
 * Is this PR line an AON ("Always On") item? AON items are under contract —
 * their unit price is fixed for the life of that contract — so a "cost
 * avoidance" cannot meaningfully be measured against them, and Cost Avoidance
 * excludes them entirely, for every team and every session.
 *
 * A line counts as AON if EITHER test matches:
 *
 *   1. REGISTERED — its SKU is on the Master AON list. Reuses
 *      aon_sku_filter_sql() from includes/aon_helper.php verbatim, so what
 *      counts as "on the AON list" can never drift from the AON feature's own
 *      definition. That helper deliberately ignores valid_from/valid_until and
 *      so does this: an expired contract is excluded too, consistent with Cost
 *      Savings' AON mode (includes/cost_savings_helper.php) — deciding when a
 *      contract is "active" is the AON feature's business, not this one's.
 *
 *   2. LABELED — the item description carries an AON marker even though the SKU
 *      was never registered. Live data showed the two tests disagree in BOTH
 *      directions (registered items whose description has no marker, and
 *      "(AON)"/"[AON]"-labeled items whose SKU is absent from aon_skus), so
 *      neither alone covers the set. The union deliberately over-excludes the
 *      unregistered-but-labeled lines: showing a contracted item is the worse
 *      failure, and an unregistered one is an AON-data gap to fix on the Master
 *      AON page, not something to paper over here.
 *
 * The name test is the SQL counterpart of aon_helper.php's aon_name_flags_aon()
 * — the same \bAON\b word-boundary match, verified to agree with that PHP
 * function on "AONE", "XAON", "CANON", "BEACON" and "PT AON-INDO", so unrelated
 * substrings cannot false-positive. It runs against p.item_description, the
 * column Input Baselines actually displays and groups by.
 *
 * READ-ONLY: reads aon_skus through aon_sku_filter_sql() and nothing else. No
 * AON table, page or helper is written or modified by this feature.
 *
 * @param string $alias table alias used for `prs` in the caller's query
 */
function ca_is_aon_expr(string $alias = 'p'): string {
    // Only fixed internal aliases are ever passed; guard anyway so this can
    // never become an injection surface, exactly as aon_sku_filter_sql() does.
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        $alias = 'p';
    }
    // '\\bAON\\b' in the emitted SQL: the backslashes are doubled because MySQL
    // consumes one level of escaping in a string literal before the regex
    // engine ever sees \b.
    return "(" . aon_sku_filter_sql($alias)
         . " OR {$alias}.item_description REGEXP '\\\\bAON\\\\b')";
}

/**
 * FROM-clause JOINs needed by ca_team_expr()'s m./pp. aliases. Factored out of
 * ca_from_where() so ca_assert_item_team_allowed() can build a minimal
 * `SELECT 1 FROM prs p <joins> WHERE ...` existence check against the exact
 * same team resolution, without duplicating the join logic.
 */
function ca_team_joins(array $ctx): string {
    $team_join = ($ctx['mode'] === 'pic')
        ? "LEFT JOIN users m ON p.assigned_pic = m.name"
        : "LEFT JOIN pgroup_team_mappings m ON p.p_group = m.p_group";

    // Deduplicated so a p_group listed twice cannot multiply the row (and thus
    // double-count it in the chart SUMs). The old PHP map was keyed by p_group,
    // which collapsed duplicates implicitly.
    $project_join = $ctx['has_project_pgroups']
        ? "LEFT JOIN (
               SELECT p_group, MAX(project_group_number) AS project_group_number
               FROM project_pgroups WHERE status = 'ACTIVE' GROUP BY p_group
           ) pp ON pp.p_group = p.p_group"
        : "";

    return "{$team_join} {$project_join}";
}

/**
 * Build a bound "team code expression IN (...)" clause plus its params, one
 * placeholder per code. Shared by ca_from_where(), ca_group_where() and
 * ca_assert_item_team_allowed() so the empty-set "deny everything" and the
 * placeholder-naming convention stay identical everywhere a forced team scope
 * is applied. $paramPrefix must be unique per call site sharing a query (e.g.
 * ca_group_where() already binds :grp_team, so its forced-scope placeholders
 * use a different prefix).
 *
 * @return array{0:string,1:array} [sql fragment ("AND expr IN (...)" or "AND 1=0"), params]
 */
function ca_forced_team_clause(array $ctx, array $forcedTeams, string $paramPrefix): array {
    if (empty($forcedTeams)) {
        return [' AND 1 = 0', []];
    }
    $params = [];
    $placeholders = [];
    foreach (array_values($forcedTeams) as $i => $code) {
        $key = "{$paramPrefix}{$i}";
        $placeholders[] = ":{$key}";
        $params[$key] = $code;
    }
    return [' AND ' . ca_team_expr($ctx) . ' IN (' . implode(', ', $placeholders) . ')', $params];
}

/**
 * Build the FROM + JOIN + WHERE shared by every Cost Avoidance query, together
 * with its bound parameters.
 *
 * $scope: 'report'    = lines with a baseline (Savings Report tab)
 *         'baselines' = lines without one (Input Baselines tab)
 *         'all'       = both (chart spend counts every PO, not just priced ones)
 *
 * $bfilters is the Input Baselines tab's own canvassing-date filter, which
 * narrows that tab ON TOP of the global one (the page applied the global window
 * in SQL and then re-checked the baseline window per row in PHP). Pass null for
 * every scope other than 'baselines'.
 *
 * $forcedTeams is the session's resolved scope from ca_resolve_session_team_scope()
 * — deliberately a SECOND, independent team parameter from $filters['team']
 * (Addendum #3 §C1's correction to the original single-list design). The two
 * never merge: $filters['team'] is still the client-chosen Quick-Filter value,
 * still whatever an $unfiltered-for-totals variant resets to '', and still
 * applied with plain `=`. $forcedTeams is mandatory-when-non-null, applied with
 * `IN (...)`, and is NEVER reset by an $unfiltered variant — a team-scoped
 * session's totals must stay scoped to their team, not silently become the
 * grand total. null = unrestricted (no clause added, today's behavior). []
 * = deny (matches nothing).
 *
 * @return array{0:string,1:array} [sql, params]
 */
function ca_from_where(array $ctx, array $filters, string $scope = 'all', ?array $bfilters = null, ?array $forcedTeams = null): array {
    $params = [];
    $joins = ca_team_joins($ctx);

    // Lines whose SKU / item+vendor appear in the legacy 2025 purchase history
    // used to be dropped here. They no longer are (2026-10-05, requested): a PO
    // line the dashboard prices must also show in the Savings Report, e.g. PO
    // 4200290410 lost 3 of its 11 lines to this rule.
    $exclusion = "";

    // AON (contract-priced) lines are out of scope for cost avoidance entirely —
    // see ca_is_aon_expr(). Unconditional and scope-independent, so it applies
    // to 'baselines', 'report' and 'all' alike, and it lands in the same WHERE
    // as the team filters below — ANDed with both $filters['team'] and the
    // forced-scope IN(...) — so no session of any kind can reach an AON line.
    // Every Cost Avoidance read goes through this builder, so this one line is
    // what covers the Input Baselines list/detail/badge, the Savings Report
    // table, and both charts.
    $exclusion .= "
            AND NOT " . ca_is_aon_expr('p');

    $where = "p.po_number IS NOT NULL AND p.po_number != '' AND p.po_amount > 0 AND p.qty > 0
              AND " . ca_not_intercompany_sql('p') . "
              {$exclusion}";

    if ($scope === 'report') {
        $where .= " AND " . ca_has_baseline_cond();
    } elseif ($scope === 'baselines') {
        $where .= " AND NOT " . ca_has_baseline_cond();
    }

    // canvassing_date is a DATE column, so it is compared against bare ISO dates.
    // The old PHP appended ' 00:00:00' / ' 23:59:59' and, in the Input Baselines
    // branch, compared them as strings — which silently dropped rows canvassed on
    // exactly the start date ('2026-01-01' < '2026-01-01 00:00:00'). Comparing as
    // dates fixes that; see the plan's behaviour-change note.
    list($start, $end) = ca_date_range($filters['g_filter'], $filters['g_from'], $filters['g_to']);
    if ($start && $end) {
        $where .= " AND p.canvassing_date >= :g_start AND p.canvassing_date <= :g_end";
        $params['g_start'] = $start;
        $params['g_end']   = $end;
    }

    if ($bfilters !== null) {
        list($bstart, $bend) = ca_date_range($bfilters['g_filter'], $bfilters['g_from'], $bfilters['g_to']);
        if ($bstart && $bend) {
            // A row with no canvassing_date was dropped by the old check too
            // (`if (!$c_date || ...) continue;`), which IS NOT NULL reproduces.
            $where .= " AND p.canvassing_date IS NOT NULL
                        AND p.canvassing_date >= :b_start AND p.canvassing_date <= :b_end";
            $params['b_start'] = $bstart;
            $params['b_end']   = $bend;
        }
    }

    if ($filters['team'] !== '') {
        // The team is a derived expression, so it cannot be referenced by alias
        // in WHERE — the CASE is repeated here rather than duplicated in source.
        $where .= " AND " . ca_team_expr($ctx) . " = :team";
        $params['team'] = $filters['team'];
    }

    if ($forcedTeams !== null) {
        [$clause, $clauseParams] = ca_forced_team_clause($ctx, $forcedTeams, 'forced_team');
        $where .= $clause;
        $params = array_merge($params, $clauseParams);
    }

    $sql = "FROM prs p {$joins} WHERE {$where}";

    return [$sql, $params];
}

/**
 * Identify one Input Baselines group. The page bucketed this tab in PHP with
 * md5(item_description . vendor_name . uom . team); the same four values are the
 * GROUP BY key here, and this predicate re-selects a single bucket when its PO
 * lines are lazily fetched. `<=>` is the null-safe equality MySQL needs for uom,
 * which is nullable and grouped as a single bucket when absent.
 *
 * $forcedTeams (session's resolved scope) is ANDed in addition to the exact
 * :grp_team match above, not in place of it — per Addendum #1 §A1 point 2, the
 * exact match alone must not be trusted as the access control (a coincidental
 * AND-conflict is fragile against refactors); this makes the scope check
 * explicit here too, the same as ca_from_where(). null = unrestricted (no
 * clause added). [] = deny (matches nothing).
 *
 * @return array{0:string,1:array} [sql fragment, extra params]
 */
function ca_group_where(array $ctx, array $group, ?array $forcedTeams = null): array {
    $sql = " AND p.item_description <=> :grp_item
             AND p.vendor_name      <=> :grp_vendor
             AND p.uom              <=> :grp_uom
             AND " . ca_team_expr($ctx) . " = :grp_team";
    $params = [
        'grp_item'   => $group['item'],
        'grp_vendor' => $group['vendor'],
        'grp_uom'    => ($group['uom'] === '' ? null : $group['uom']),
        'grp_team'   => $group['team'],
    ];

    if ($forcedTeams !== null) {
        [$clause, $clauseParams] = ca_forced_team_clause($ctx, $forcedTeams, 'grp_forced_team');
        $sql .= $clause;
        $params = array_merge($params, $clauseParams);
    }

    return [$sql, $params];
}

/**
 * Assert that a `prs` line is within a session's resolved team scope before a
 * write touches it (save_header / save_detail / update_po_currency_header /
 * update_po_currency_detail — none of which check team today, per Addendum #1
 * §A2). Throws RuntimeException if the line is out of scope; no-ops for an
 * unrestricted session.
 *
 * Two identifying modes, matching the two write-action shapes in
 * cost_avoidance.php:
 *   - $item/$vendor/$uom for the 4-column group-key writes (save_header,
 *     update_po_currency_header) — same <=> null-safe match as ca_group_where().
 *   - $ticketNo/$lineItem for the single-PO-line writes (save_detail,
 *     update_po_currency_detail).
 * Pass exactly one pair; $ticketNo/$lineItem take precedence if both are given.
 *
 * $allowedTeams is the resolved scope from ca_resolve_session_team_scope():
 * null = unrestricted (no-op). [] = deny (always throws — a session with no
 * resolvable team scope has nothing to write against).
 */
function ca_assert_item_team_allowed(
    PDO $pdo,
    array $ctx,
    ?array $allowedTeams,
    ?string $item = null,
    ?string $vendor = null,
    ?string $uom = null,
    ?string $ticketNo = null,
    ?string $lineItem = null
): void {
    if ($allowedTeams === null) {
        return;
    }
    if (empty($allowedTeams)) {
        throw new RuntimeException('Cost Avoidance: session has no allowed team scope.');
    }

    if ($ticketNo !== null && $lineItem !== null) {
        $identify = "p.ticket_no = :id_ticket_no AND p.ticket_line_item = :id_line_item";
        $params = ['id_ticket_no' => $ticketNo, 'id_line_item' => $lineItem];
    } else {
        $identify = "p.item_description <=> :id_item AND p.vendor_name <=> :id_vendor AND p.uom <=> :id_uom";
        $params = ['id_item' => $item, 'id_vendor' => $vendor, 'id_uom' => ($uom === '' ? null : $uom)];
    }

    [$clause, $clauseParams] = ca_forced_team_clause($ctx, $allowedTeams, 'assert_team');
    // ca_forced_team_clause() always starts with " AND " for use after an
    // existing WHERE; strip that leading " AND " once for this bare WHERE.
    $clause = preg_replace('/^\s*AND\s+/', '', $clause, 1);
    $params = array_merge($params, $clauseParams);

    $joins = ca_team_joins($ctx);
    $stmt = $pdo->prepare("SELECT 1 FROM prs p {$joins} WHERE {$identify} AND {$clause} LIMIT 1");
    $stmt->execute($params);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Cost Avoidance: item is outside your allowed team scope.');
    }
}

/**
 * Write-path guard: refuse to write a baseline / currency / taxonomy against an
 * AON (contract-priced) item. Throws RuntimeException when the target is AON.
 *
 * ca_is_aon_expr() already removes these lines from every Cost Avoidance READ,
 * so the UI cannot offer one — but the write actions in pages/cost_avoidance.php
 * build their own UPDATE statements and never go through ca_from_where(), so a
 * stale tab opened before this feature shipped, or a hand-crafted POST, could
 * still target one. This is defense in depth for exactly that, and it LAYERS
 * WITH ca_assert_item_team_allowed() rather than replacing it: a write must pass
 * both. AON status is a property of the item, not of the session, so unlike the
 * team check this one has no unrestricted/no-op path — it applies to every
 * session including Category Manager.
 *
 * Same two identifying modes, and the same "pass exactly one pair" contract, as
 * ca_assert_item_team_allowed() above:
 *   - $item/$vendor/$uom for the 4-column group-key writes (save_header,
 *     update_po_currency_header, save_ca_taxonomy)
 *   - $ticketNo/$lineItem for the single-PO-line writes (save_detail,
 *     update_po_currency_detail)
 * $ticketNo/$lineItem take precedence if both are given.
 *
 * A group-key write is rejected when ANY line in the group is AON. Groups are
 * homogeneous in practice (a live check found zero groups mixing AON and
 * non-AON lines), and erring toward rejection is the safe direction for a guard
 * whose whole job is to stop a contracted item being priced.
 *
 * No team joins are needed: AON status does not depend on team resolution.
 */
function ca_assert_item_not_aon(
    PDO $pdo,
    ?string $item = null,
    ?string $vendor = null,
    ?string $uom = null,
    ?string $ticketNo = null,
    ?string $lineItem = null
): void {
    if ($ticketNo !== null && $lineItem !== null) {
        $identify = "p.ticket_no = :aon_ticket_no AND p.ticket_line_item = :aon_line_item";
        $params = ['aon_ticket_no' => $ticketNo, 'aon_line_item' => $lineItem];
    } else {
        $identify = "p.item_description <=> :aon_item AND p.vendor_name <=> :aon_vendor AND p.uom <=> :aon_uom";
        $params = ['aon_item' => $item, 'aon_vendor' => $vendor, 'aon_uom' => ($uom === '' ? null : $uom)];
    }

    $stmt = $pdo->prepare("SELECT 1 FROM prs p WHERE {$identify} AND " . ca_is_aon_expr('p') . " LIMIT 1");
    $stmt->execute($params);
    if ($stmt->fetchColumn()) {
        throw new RuntimeException('Cost Avoidance: item is an AON (contract) item and cannot be modified here.');
    }
}

// Scoped by the same 4-column group key as save_header; level_2 is
// re-validated server-side against v_item_taxonomy (mirrors
// master_vendor_evaluation.php's tamper-check pattern) so a crafted value
// outside the group's team's level_1 can never be written. Moved here from
// pages/cost_avoidance.php 2026-09-17 so ajax_project_quotation_save.php's
// Initial Quotation Price modal can reuse it too, not just Cost Avoidance's own page.
function ca_apply_save_ca_taxonomy(PDO $pdo, array $ctx, ?array $team_scope, array $post): array {
    $item   = (string)($post['item'] ?? '');
    $vendor = (string)($post['vendor'] ?? '');
    $uom    = (($post['uom'] ?? '') !== '') ? $post['uom'] : null;
    $team   = (string)($post['team'] ?? '');
    $level2 = trim((string)($post['ca_taxonomy_level_2'] ?? ''));

    try {
        ca_assert_item_team_allowed($pdo, $ctx, $team_scope, $item, $vendor, $uom);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'scope_error' => true, 'error' => 'Outside your assigned team scope.'];
    }

    // Layered on top of the team check above — both must pass.
    try {
        ca_assert_item_not_aon($pdo, $item, $vendor, $uom);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'aon_error' => true, 'error' => 'AON (contract) item — cannot be modified.'];
    }

    $level1 = CA_PROJECT_TEAM_LEVEL1[$team] ?? null;
    if ($level1 === null || !in_array($level2, ca_project_level2_options($pdo, $level1), true)) {
        return ['ok' => false, 'error' => 'invalid Level 2 taxonomy selection for this team'];
    }

    $joins = ca_team_joins($ctx);
    $stmt = $pdo->prepare("
        UPDATE prs p {$joins}
        SET p.ca_taxonomy_level_2 = ?
        WHERE p.item_description = ? AND p.vendor_name = ? AND p.uom <=> ?
        AND " . ca_team_expr($ctx) . " = ?
    ");
    $stmt->execute([$level2, $item, $vendor, $uom, $team]);
    return ['ok' => true, 'rows' => $stmt->rowCount(), 'error' => null];
}

/**
 * Global-search predicate for the Input Baselines group list. Narrower than
 * ca_search_where() because a group only exposes item / vendor / uom / team.
 *
 * @return array{0:string,1:array} [sql fragment (may be ''), extra params]
 */
function ca_baselines_search_where(array $ctx, string $search): array {
    $search = trim($search);
    if ($search === '') return ['', []];

    $words = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
    $cols = ['p.item_description', 'p.vendor_name', 'p.uom', 'p.sku', ca_team_expr($ctx)];

    $clauses = [];
    $params = [];
    foreach ($words as $i => $word) {
        $ors = [];
        // Distinct placeholder per (word, column) — see the note in ca_search_where().
        foreach ($cols as $j => $col) {
            $key = "bsearch{$i}_{$j}";
            $ors[] = "{$col} LIKE :{$key}";
            $params[$key] = '%' . $word . '%';
        }
        $clauses[] = '(' . implode(' OR ', $ors) . ')';
    }

    return [' AND ' . implode(' AND ', $clauses), $params];
}

/**
 * Global-search predicate for the Savings Report table. Every whitespace-
 * separated word must match at least one of the searchable source columns
 * (AND across words, OR across columns) — the same smart-search semantics
 * DataTables applied client-side, but over the underlying column values rather
 * than the rendered cell text.
 *
 * @return array{0:string,1:array} [sql fragment (may be ''), extra params]
 */
function ca_search_where(array $ctx, string $search): array {
    $search = trim($search);
    if ($search === '') return ['', []];

    $words = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
    $cols = ['p.po_number', 'p.sku', 'p.item_description', 'p.vendor_name', 'p.p_org', ca_team_expr($ctx)];

    $clauses = [];
    $params = [];
    foreach ($words as $i => $word) {
        $ors = [];
        // One placeholder per (word, column) pair rather than one per word, even
        // though the bound value is identical: PDO with ATTR_EMULATE_PREPARES =>
        // false rejects a named placeholder that appears more than once in a
        // statement ("Invalid parameter number"), and each word is tested against
        // every searchable column.
        foreach ($cols as $j => $col) {
            $key = "search{$i}_{$j}";
            $ors[] = "{$col} LIKE :{$key}";
            $params[$key] = '%' . $word . '%';
        }
        $clauses[] = '(' . implode(' OR ', $ors) . ')';
    }

    return [' AND ' . implode(' AND ', $clauses), $params];
}

// --- MANUAL COST AVOIDANCE IN THE SAVINGS REPORT ---------------------------
//
// The Savings Report (ajax_cost_avoidance_table.php) is a `prs`-only engine:
// it reads PO lines that carry a quotation baseline. Cost avoidance the project
// teams captured OUTSIDE the K2/prs pipeline (the "Monitoring GP Project"
// workbook backfilled into manual_cost_avoidance) therefore never appeared
// there — MFM/PKP in particular, whose POs largely don't flow through prs.
//
// The builders below add exactly that data — and ONLY that data — as a second
// UNION arm the report endpoint appends to its `prs` query. The `prs` side is
// left completely untouched (byte-for-byte the same COUNT/SUM/SELECT); manual
// rows are added on top, never merged into the `prs` expressions.
//
// SUBSET, not all manual entries: only the GP-imported rows are surfaced here.
// They are the rows that carry a po_number / canvassing_no / "GP: BU=" remark —
// columns the interactive Manual Input form never writes — so every other
// team's ordinary manual entries stay on the Manual tab and out of this report.

/**
 * SQL predicate selecting only the GP-imported manual_cost_avoidance rows (see
 * the section note above). $alias is the table alias used for
 * manual_cost_avoidance in the caller's query.
 */
function ca_manual_gp_subset_cond(string $alias = 'mca'): string {
    // source_type = 'DASHBOARD': entries made through the dashboards' Manual
    // Input modal (ajax_manual_ca_save.php) are meant to appear on this report
    // straight away, exactly like the GP backfill.
    return "({$alias}.po_number IS NOT NULL OR {$alias}.canvassing_no IS NOT NULL"
         . " OR {$alias}.remarks LIKE 'GP: BU=%' OR {$alias}.source_type = 'DASHBOARD')";
}

/**
 * Fold manual_cost_avoidance.team (stored as the FULL descriptive name — see
 * ca_manual_team_options()) to the short team code the Savings Report uses,
 * mirroring the acronym CASE inside ca_team_expr(). Legacy rows already holding
 * a bare code fall through the ELSE unchanged, exactly as ca_manual_aggregate()
 * tolerates them.
 */
function ca_manual_team_code_expr(string $alias = 'mca'): string {
    return "CASE {$alias}.team
                WHEN 'Corporate Services, General Supplies & Marketing' THEN 'CSGSM'
                WHEN 'Technology, Industrial, and General Services'     THEN 'TIGS'
                WHEN 'FNB and Operational Merchandise'                  THEN 'FNBOM'
                WHEN 'Admin Team'                                       THEN 'ADMIN'
                WHEN 'Project Konstruksi Retail'                        THEN 'PKR'
                WHEN 'Project Konstruksi Properti'                      THEN 'PKP'
                WHEN 'Maintenance & Facility Management'                THEN 'MFM'
                ELSE {$alias}.team
            END";
}

/**
 * FROM + WHERE (and bound params) for the manual GP arm of the Savings Report.
 * Deliberately the manual mirror of ca_from_where()'s 'report' scope: same
 * global date window (on entry_date), same $filters['team'] `=` match and same
 * $forcedTeams `IN (...)` session scope — so the manual side is filtered exactly
 * like the prs side. $pfx namespaces this arm's placeholders so it can sit in
 * the same prepared statement as the prs arm without a name collision.
 *
 * null $forcedTeams = unrestricted (no clause). [] = deny (1=0), matching
 * ca_from_where()'s contract.
 *
 * @return array{0:string,1:array} [sql ("FROM ... WHERE ..."), params]
 */
function ca_manual_report_from_where(array $ctx, array $filters, ?array $forcedTeams, string $pfx = 'm'): array {
    $params = [];
    // total_cost_avoidance > 0 = only REALIZED savings reach the Savings Report,
    // matching how the prs side behaves: an in-progress canvassing (no winner
    // vendor / no PO / no saving yet) never appears on the report. Zero-saving GP
    // rows stay on the Manual Input tab (ca_manual_aggregate() has no such gate),
    // so nothing is lost — they just don't clutter the realized-savings report.
    $where  = "mca.is_active = 1 AND mca.total_cost_avoidance > 0 AND " . ca_manual_gp_subset_cond('mca');

    list($start, $end) = ca_date_range($filters['g_filter'], $filters['g_from'], $filters['g_to']);
    if ($start && $end) {
        $where .= " AND mca.entry_date >= :{$pfx}_start AND mca.entry_date <= :{$pfx}_end";
        $params["{$pfx}_start"] = $start;
        $params["{$pfx}_end"]   = $end;
    }

    if ($filters['team'] !== '') {
        $where .= " AND " . ca_manual_team_code_expr('mca') . " = :{$pfx}_team";
        $params["{$pfx}_team"] = $filters['team'];
    }

    if ($forcedTeams !== null) {
        if (empty($forcedTeams)) {
            $where .= " AND 1 = 0";
        } else {
            $ph = [];
            foreach (array_values($forcedTeams) as $i => $code) {
                $key = "{$pfx}_forced{$i}";
                $ph[] = ":{$key}";
                $params[$key] = $code;
            }
            $where .= " AND " . ca_manual_team_code_expr('mca') . " IN (" . implode(', ', $ph) . ")";
        }
    }

    return ["FROM manual_cost_avoidance mca WHERE {$where}", $params];
}

/**
 * Search predicate for the manual GP arm — the manual counterpart of
 * ca_search_where(), over the columns a manual row actually exposes. Same
 * AND-across-words / OR-across-columns semantics; $pfx keeps its placeholders
 * distinct from the prs arm's in a shared statement.
 *
 * @return array{0:string,1:array} [sql fragment (may be ''), params]
 */
function ca_manual_report_search_where(array $ctx, string $search, string $pfx = 'm'): array {
    $search = trim($search);
    if ($search === '') return ['', []];

    $words = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
    $cols  = ['mca.po_number', 'mca.item_description', 'mca.vendor_name', ca_manual_team_code_expr('mca')];

    $clauses = [];
    $params  = [];
    foreach ($words as $i => $word) {
        $ors = [];
        foreach ($cols as $j => $col) {
            $key = "{$pfx}search{$i}_{$j}";
            $ors[] = "{$col} LIKE :{$key}";
            $params[$key] = '%' . $word . '%';
        }
        $clauses[] = '(' . implode(' OR ', $ors) . ')';
    }

    return [' AND ' . implode(' AND ', $clauses), $params];
}

/**
 * SELECT column list for the manual GP arm, positionally matching the prs arm's
 * SELECT in ajax_cost_avoidance_table.php so the two can be UNION ALL'd. Every
 * money value is converted to IDR the same way the prs side converts (via
 * ca_currency_case_expr) — GP rows are all IDR today, so this is an identity in
 * practice, but it keeps the two arms defined by the same rule. The trailing
 * srt_* columns are the sort keys the outer ORDER BY references; row_source
 * lets the client tell a manual row from a PO line (it has no prs ticket line
 * to edit). No FROM/WHERE here — the caller appends ca_manual_report_from_where().
 */
function ca_manual_report_select(array $ctx): string {
    $cur          = ca_effective_currencies($ctx);
    // Rate-aware when the fx_rate snapshot column exists (see ca_context()).
    $conv = !empty($ctx['has_manual_fx'])
        ? function($col) use ($cur, $ctx) { return ca_currency_case_expr_rate($col, 'mca.currency', 'mca.fx_rate', $cur, ca_is_strict_ctx($ctx)); }
        : function($col) use ($cur, $ctx) { return ca_currency_case_expr($col, 'mca.currency', $cur, ca_is_strict_ctx($ctx)); };
    $avoidUnitIdr = $conv('mca.avoided_price');
    $baseUnitIdr  = $conv('mca.unit_price_before');
    $caIdr        = $conv('mca.total_cost_avoidance');
    $team         = ca_manual_team_code_expr('mca');

    return "SELECT
                NULL                                       AS ticket_no,
                NULL                                       AS ticket_line_item,
                mca.po_number                              AS po_number,
                NULL                                       AS p_org,
                NULL                                       AS sku,
                mca.item_description                       AS item_description,
                mca.uom                                    AS uom,
                mca.vendor_name                            AS vendor_name,
                (mca.quantity + 0e0)                       AS qty,
                (COALESCE({$avoidUnitIdr}, 0) * (mca.quantity + 0e0)) AS po_amount,
                'IDR'                                      AS po_currency,
                (COALESCE(mca.unit_price_before, 0) + 0e0) AS baseline_price,
                'IDR'                                      AS baseline_currency,
                mca.quotation_link                         AS quotation_link,
                {$team}                                    AS team,
                0                                          AS is_inclusive,
                COALESCE({$avoidUnitIdr}, 0)               AS nett_unit_price,
                COALESCE({$baseUnitIdr}, 0)                AS baseline_idr,
                COALESCE({$caIdr}, 0)                      AS cost_avoidance,
                (COALESCE({$avoidUnitIdr}, 0) * (mca.quantity + 0e0))  AS srt_po_idr,
                (COALESCE({$baseUnitIdr}, 0) * (mca.quantity + 0e0))   AS srt_baseline_total_idr,
                mca.created_at                             AS srt_created,
                (CASE WHEN mca.source_type = 'DASHBOARD' THEN 'DASHBOARD' ELSE 'MANUAL' END) AS row_source";
}
