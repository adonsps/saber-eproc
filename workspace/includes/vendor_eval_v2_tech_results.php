<?php
// workspace/includes/vendor_eval_v2_tech_results.php
//
// Read-only, PGroup-scoped Vendor Evaluation Form 2 result helpers for the
// Technical Department external-user results page
// (pages/vendor_eval_results_tech.php) and its drill-down endpoint
// (pages/ajax_vendor_eval_tech_detail.php).
//
// PURE READ-ONLY LIBRARY: defines functions only. No DB writes, no output, no
// session/auth side effects at include time, no WA/email/Gemini/external API.
// Every DB access uses prepared statements. Safe to require from header.php's
// guarded nav block exactly like vendor_eval_v2_routing.php.
//
// Scope model (deliberately SEPARATE from the live Form 2 sender/eligibility
// path): a Technical Department account's scope is the set of PGroups mapped to
// its team in technical_team_pgroups, filtered to this feature's own in-scope
// pattern (*97 / A77 / FYQ) — see ve2_tech_is_in_scope_pgroup(). The shared
// ve2_form2_is_in_scope_pgroup() (used by the sender/public form) is NEVER
// touched or called by name here.
//
// Requires (both pure, function-only libraries):
//   includes/vendor_eval_v2_results_helper.php   -> ve2_score_grade()
//   includes/vendor_eval_v2_form2_eligibility.php -> ve2_form2_po_eligibility()
// It does NOT require auth.php (kept side-effect-free); the AJAX gate below
// references require_login(), so the ENDPOINT that calls it must have loaded
// includes/auth.php first — the page/endpoint always do.

require_once __DIR__ . '/vendor_eval_v2_results_helper.php';   // ve2_score_grade()
require_once __DIR__ . '/vendor_eval_v2_form2_eligibility.php'; // ve2_form2_po_eligibility()

/**
 * Feature-local scope predicate for the Technical Department results page:
 * a PGroup is in scope when it ends in "97" or "99" (e.g. A97, AB97, X97,
 * K99, Q99), or equals 'A77', 'FYQ', or 'FPO'. Intentionally broader than —
 * and independent of — the shared ve2_form2_is_in_scope_pgroup(), which stays
 * untouched. Extended 2026-08 to cover the real PKR team roster (adds *99 and
 * the FPO one-off, alongside the original *97/A77/FYQ set).
 */
function ve2_tech_is_in_scope_pgroup(string $pg): bool
{
    $pg = trim($pg);
    if ($pg === '') {
        return false;
    }
    return $pg === 'A77' || $pg === 'FYQ' || $pg === 'FPO' || (bool)preg_match('/(97|99)$/', $pg);
}

/**
 * The Form 2 teams: the ONLY Technical Department teams that are
 * Vendor-Evaluation-only (minimal sidebar + dashboard_tech.php redirect).
 *
 * This is an ALLOWLIST on purpose. It replaced an "everyone except General
 * Affairs" blocklist (2026-08-21/24) that was wrong in the other direction:
 * every non-Form-2 technical team — MBO and the rest — inherited the
 * eval-only sidebar and lost the worklist / Announcements / SKU Dictionary
 * they are supposed to have. Anything not listed here keeps its original
 * surface, so a team added to prod later fails OPEN to the normal dashboard
 * rather than being silently locked into Vendor Evaluations.
 *
 * NOT derivable from technical_team_pgroups: that table also scopes every
 * technical team's dashboard_tech.php worklist and SLA persona filter (see
 * sla_helper.php), so nearly every team has *97 / *99 rows and a
 * mapping-based test re-locks the exact teams this list exists to free.
 *
 * Roster confirmed by the product owner 2026-08-27. Adding a Form 2 team is
 * a code change here and nowhere else.
 */
const VE2_TECH_EVAL_ONLY_TEAMS = [
    'Team New Store',
    'Team Retail Barang',
    'Project CT&M',
    'Project NEKA',
    'Team Industrial',
];

/**
 * True when the account's team is one of VE2_TECH_EVAL_ONLY_TEAMS above.
 *
 * team_name is free text (see technical_teams.php / user_management.php, both
 * of which placeholder "General Affairs (GA)"), so accounts can be stored with
 * a trailing "(CODE)" suffix. Match the listed name as a prefix followed by a
 * non-alphanumeric boundary — "Project NEKA (NEK)" matches 'Project NEKA',
 * "Project NEKAT" does not. Case- and whitespace-insensitive.
 *
 * Single source of truth for the gate — used by header.php's sidebar gate and
 * dashboard_tech.php's redirect gate, so the two can't drift apart again.
 */
function ve2_tech_is_eval_only_team(string $team_name): bool
{
    // Collapse runs of whitespace so "Team  New Store" still matches.
    $team_name = trim(preg_replace('/\s+/u', ' ', $team_name) ?? '');
    if ($team_name === '') {
        return false;
    }
    foreach (VE2_TECH_EVAL_ONLY_TEAMS as $eval_team) {
        if (strcasecmp($team_name, $eval_team) === 0) {
            return true;
        }
        $len = strlen($eval_team);
        if (strlen($team_name) > $len
            && strncasecmp($team_name, $eval_team, $len) === 0
            && !ctype_alnum($team_name[$len])
        ) {
            return true;
        }
    }
    return false;
}

/**
 * The account's scope = its team's mapped PGroups (technical_team_pgroups),
 * kept only where ve2_tech_is_in_scope_pgroup() holds. Deduped, order-stable.
 * Fails closed (returns []) on any error or when the team has no in-scope
 * mapping. This resolved list feeds every query below; which teams are
 * eval-only is decided separately by ve2_tech_is_eval_only_team() above, not
 * here, so a stray mapping row (like the General Affairs one that caused the
 * original bug) can never re-trigger the redirect.
 */
function ve2_tech_resolve_pgroups(PDO $pdo, string $team_name): array
{
    $team_name = trim($team_name);
    if ($team_name === '') {
        return [];
    }
    try {
        $stmt = $pdo->prepare("SELECT DISTINCT pgroup FROM technical_team_pgroups WHERE team_name = ?");
        $stmt->execute([$team_name]);
        $all = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return [];
    }

    $scoped = [];
    foreach ($all as $pg) {
        $pg = trim((string)$pg);
        if ($pg !== '' && ve2_tech_is_in_scope_pgroup($pg) && !isset($scoped[$pg])) {
            $scoped[$pg] = true;
        }
    }
    return array_keys($scoped);
}

/**
 * Distinct calendar years with at least one submitted Form 2 evaluation in
 * scope, newest first — populates the dashboard's Year filter dropdown.
 * Read-only, fails closed ([]) on any error or empty input.
 */
function ve2_tech_available_years(PDO $pdo, array $pgroups): array
{
    if (empty($pgroups)) {
        return [];
    }
    try {
        $ph = implode(',', array_fill(0, count($pgroups), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT YEAR(s.submitted_at) AS yr
              FROM vendor_eval_submissions s
              JOIN (SELECT po_number, MAX(p_group) AS p_group FROM prs GROUP BY po_number) p
                ON p.po_number = s.po_number
             WHERE s.team IN ('ops','project') AND s.status = 'submitted'
               AND s.submitted_at IS NOT NULL
               AND p.p_group COLLATE utf8mb4_unicode_ci IN ($ph)
             ORDER BY yr DESC
        ");
        $stmt->execute(array_values($pgroups));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Business Unit name(s) per PGroup, from pgroup_business_unit_mappings — the
 * SAME source the Manager Form 2 results table uses (see
 * vendor_eval_v2_results_helper.php). Returns [] if the (manually-applied)
 * mapping table doesn't exist on this environment. Keyed p_group => "BU name"
 * (multiple names for one PGroup joined with ", ").
 */
function ve2_tech_pgroup_business_units(PDO $pdo, array $pgroups): array
{
    if (empty($pgroups) || !ve2_has_table($pdo, 'pgroup_business_unit_mappings')) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($pgroups), '?'));
    $sql = "
        SELECT p_group,
               GROUP_CONCAT(DISTINCT business_unit_name ORDER BY business_unit_name SEPARATOR ', ') AS bu
          FROM pgroup_business_unit_mappings
         WHERE p_group COLLATE utf8mb4_unicode_ci IN ($ph)
         GROUP BY p_group
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($pgroups));
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[(string)$r['p_group']] = $r['bu'];
    }
    return $map;
}

/**
 * Distinct site allocations per PO, for the summary-dashboard site counts.
 * A "site" is prs.site_allocation (one PO can have many sites; one site can
 * belong to many POs). Returns [po_number => [site_allocation, ...]] with
 * blanks removed. Read-only, fails closed ([]) on any error or empty input.
 * Callers derive "total unique sites" (distinct across a PO set) and
 * "sites per vendor" (distinct across that vendor's POs) from this map.
 */
function ve2_tech_po_sites(PDO $pdo, array $po_numbers): array
{
    $po_numbers = array_values(array_unique(array_filter(
        array_map(static fn($p) => trim((string)$p), $po_numbers),
        static fn($p) => $p !== ''
    )));
    if (empty($po_numbers)) {
        return [];
    }
    try {
        $ph = implode(',', array_fill(0, count($po_numbers), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT po_number, site_allocation
              FROM prs
             WHERE po_number IN ($ph)
               AND site_allocation IS NOT NULL AND site_allocation <> ''
        ");
        $stmt->execute($po_numbers);
    } catch (PDOException $e) {
        return [];
    }
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[(string)$r['po_number']][] = (string)$r['site_allocation'];
    }
    return $map;
}

/**
 * All Form 2 (team ops/project) submissions whose PO's PGroup is in $pgroups,
 * grouped one row per PO. The PGroup for a PO comes from prs.p_group (a
 * submission row carries no PGroup of its own), pre-aggregated to one row per
 * po_number so the join can't fan out.
 *
 * Each returned row:
 *   po_number, vendor_name, p_group, business_unit (?string),
 *   taxonomy_level_2, taxonomy_level_3,
 *   ops (?['total_score','score_band','submitted_at']),
 *   project (?[...]),
 *   both_submitted (bool),
 *   final_score (?float),  final_grade (?string A|B|C|D),  // both teams only
 *   last_submitted_at (?string)
 *
 * Includes POs with only one team submitted (partially evaluated) as well as
 * both — the caller splits Results (both) from the partial side of Outstanding.
 * Sorted by last_submitted_at descending.
 */
function ve2_tech_form2_results(PDO $pdo, array $pgroups, ?int $year = null, ?int $month = null): array
{
    if (empty($pgroups)) {
        return [];
    }
    $bu_map = ve2_tech_pgroup_business_units($pdo, $pgroups);

    $ph = implode(',', array_fill(0, count($pgroups), '?'));
    $params = array_values($pgroups);

    // Optional global time filter (dashboard Year/Month controls): narrows to
    // submissions whose submitted_at falls in the selected window. A PO whose
    // OTHER team submitted outside the window drops out of "both_submitted"
    // for this call, which is intentional — it reflects "what was submitted in
    // this period", not full PO history (see ve2_tech_vendor_profile()/
    // ve2_tech_site_pos() for the always-full-history views used by modals).
    $date_sql = '';
    if ($year !== null && $month !== null) {
        $date_sql = ' AND s.submitted_at BETWEEN ? AND ? ';
        $from = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $params[] = $from;
        $params[] = date('Y-m-t 23:59:59', strtotime($from));
    } elseif ($year !== null) {
        $date_sql = ' AND YEAR(s.submitted_at) = ? ';
        $params[] = $year;
    } elseif ($month !== null) {
        $date_sql = ' AND MONTH(s.submitted_at) = ? ';
        $params[] = $month;
    }

    $sql = "
        SELECT s.po_number, s.vendor_name, s.taxonomy_level_2, s.taxonomy_level_3,
               s.team, s.total_score, s.score_band, s.submitted_at, p.p_group
          FROM vendor_eval_submissions s
          JOIN (SELECT po_number, MAX(p_group) AS p_group FROM prs GROUP BY po_number) p
            ON p.po_number = s.po_number
         WHERE s.team IN ('ops','project') AND s.status = 'submitted'
           AND p.p_group COLLATE utf8mb4_unicode_ci IN ($ph)
           $date_sql
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $by_po = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $po = (string)$r['po_number'];
        if (!isset($by_po[$po])) {
            $by_po[$po] = [
                'po_number'        => $po,
                'vendor_name'      => $r['vendor_name'],
                'p_group'          => $r['p_group'],
                'business_unit'    => $bu_map[(string)$r['p_group']] ?? null,
                'taxonomy_level_2' => $r['taxonomy_level_2'],
                'taxonomy_level_3' => $r['taxonomy_level_3'],
                'ops'              => null,
                'project'          => null,
            ];
        }
        // Prefer a non-empty vendor/taxonomy if the first slot lacked it.
        if (empty($by_po[$po]['vendor_name']) && !empty($r['vendor_name'])) {
            $by_po[$po]['vendor_name'] = $r['vendor_name'];
        }
        if (empty($by_po[$po]['taxonomy_level_2']) && !empty($r['taxonomy_level_2'])) {
            $by_po[$po]['taxonomy_level_2'] = $r['taxonomy_level_2'];
        }
        if (empty($by_po[$po]['taxonomy_level_3']) && !empty($r['taxonomy_level_3'])) {
            $by_po[$po]['taxonomy_level_3'] = $r['taxonomy_level_3'];
        }
        $by_po[$po][$r['team']] = [
            'total_score'  => $r['total_score'] !== null ? (float)$r['total_score'] : null,
            'score_band'   => $r['score_band'],
            'submitted_at' => $r['submitted_at'],
        ];
    }

    $out = [];
    foreach ($by_po as $row) {
        $ops  = $row['ops'];
        $proj = $row['project'];
        $both = ($ops !== null && $proj !== null);

        $final_score = null;
        $final_grade = null;
        if ($both && $ops['total_score'] !== null && $proj['total_score'] !== null) {
            $final_score = round(((float)$ops['total_score'] + (float)$proj['total_score']) / 2, 4);
            $final_grade = ve2_score_grade($final_score);
        }

        $dates = array_filter([$ops['submitted_at'] ?? null, $proj['submitted_at'] ?? null]);
        $row['both_submitted']    = $both;
        $row['final_score']       = $final_score;
        $row['final_grade']       = $final_grade;
        $row['last_submitted_at'] = !empty($dates) ? max($dates) : null;
        $out[] = $row;
    }

    usort($out, static function ($a, $b) {
        return strcmp((string)($b['last_submitted_at'] ?? ''), (string)($a['last_submitted_at'] ?? ''));
    });
    return $out;
}

/**
 * "Never started" Outstanding: Form 2-eligible POs in scope with ZERO team
 * submissions. Candidate universe is Form 2's own readiness gate
 * (ve2_form2_po_eligibility(): new-store + all sites opened), intersected with
 * $pgroups. $exclude_pos is the set of POs that already have >= 1 submission
 * (from ve2_tech_form2_results()) so we never re-list a partially evaluated PO
 * here.
 *
 * NOTE (documented limitation): ve2_form2_po_eligibility() applies its own,
 * narrower PGroup gate (A77 / [A-Z]97). PGroups that match this feature's
 * broader pattern but not that gate (FYQ, multi-letter *97) therefore surface
 * nothing "never started" until Form 2 eligibility is separately extended.
 * Their completed/partial results still appear via ve2_tech_form2_results().
 *
 * Each returned row:
 *   po_number, vendor_name, taxonomy_level_2 (null), taxonomy_level_3 (null),
 *   store_opening_date (?string), ops_submitted (false), project_submitted (false)
 */
function ve2_tech_unstarted(PDO $pdo, array $pgroups, array $exclude_pos = []): array
{
    if (empty($pgroups)) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($pgroups), '?'));
    $sql = "
        SELECT p.po_number,
               MAX(p.vendor_name) AS vendor_name,
               MAX(p.p_group)     AS p_group
          FROM prs p
         WHERE p.po_number IS NOT NULL AND p.po_number != ''
           AND p.is_new_store = 1
           AND p.p_group COLLATE utf8mb4_unicode_ci IN ($ph)
         GROUP BY p.po_number
         ORDER BY p.po_number
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($pgroups));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $bu_map  = ve2_tech_pgroup_business_units($pdo, $pgroups);
    $exclude = array_flip(array_map('strval', $exclude_pos));

    // Candidates still needing an eligibility check (excludes ones we'd throw
    // away anyway). Batched via ve2_form2_po_eligibility_bulk() — same rules
    // as the single-PO ve2_form2_po_eligibility(), but ~3 queries total
    // instead of ~4 PER candidate PO (measured ~0.9s for a few hundred POs
    // in the per-PO loop this replaced; this dashboard reloads on every
    // P.Group/Year/Month click, so that cost was paid on every click).
    $candidate_pos = [];
    foreach ($rows as $c) {
        $po = (string)$c['po_number'];
        if (!isset($exclude[$po])) {
            $candidate_pos[] = $po;
        }
    }
    $elig_by_po = ve2_form2_po_eligibility_bulk($pdo, $candidate_pos);

    $out = [];
    foreach ($rows as $c) {
        $po = (string)$c['po_number'];
        if (isset($exclude[$po])) {
            continue; // already has a submission -> handled as "partial", not "never started"
        }
        $elig = $elig_by_po[$po] ?? null;
        if ($elig === null || !$elig['eligible']) {
            continue;
        }
        $out[$po] = [
            'po_number'         => $po,
            'vendor_name'       => $c['vendor_name'],
            'p_group'           => $c['p_group'],
            'business_unit'     => $bu_map[(string)$c['p_group']] ?? null,
            'taxonomy_level_2'  => null,
            'taxonomy_level_3'  => null,
            'store_opening_date'=> $elig['max_open_date'],
            'ops_submitted'     => false,
            'project_submitted' => false,
        ];
    }
    return array_values($out);
}

/**
 * AJAX-safe access gate for the Technical Department drill-down endpoint,
 * mirroring ve2_require_ajax_access() in includes/vendor_eval_v2_access.php:
 * emits a 403 JSON body (never a redirect an XHR would choke on) and exits
 * unless the caller is a Technical Department account whose team has at least
 * one in-scope PGroup.
 *
 * The caller MUST have loaded includes/auth.php first (for require_login()).
 * Returns ['team_name' => string, 'pgroups' => string[]].
 */
function ve2_tech_require_ajax_access(PDO $pdo): array
{
    require_login();

    $role = $_SESSION['role'] ?? '';
    if ($role !== 'Technical Department') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Anda tidak memiliki akses ke hasil Evaluasi Vendor.']);
        exit;
    }

    $team    = trim((string)($_SESSION['team_name'] ?? ''));
    $pgroups = ve2_tech_resolve_pgroups($pdo, $team);
    if (empty($pgroups)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Tim Anda belum dipetakan ke Purchasing Group manapun.']);
        exit;
    }

    return ['team_name' => $team, 'pgroups' => $pgroups];
}

/**
 * True if $po_number's PGroup (from prs) is within $pgroups — the scope guard
 * the drill-down endpoint applies before returning any answers, so a Technical
 * Department user can never read a PO outside their team's scope by guessing
 * the number. Fails closed (false) on any error or unknown PO.
 */
function ve2_tech_po_in_scope(PDO $pdo, string $po_number, array $pgroups): bool
{
    $po_number = trim($po_number);
    if ($po_number === '' || empty($pgroups)) {
        return false;
    }
    try {
        $ph = implode(',', array_fill(0, count($pgroups), '?'));
        $stmt = $pdo->prepare("
            SELECT 1
              FROM prs
             WHERE po_number = ?
               AND p_group COLLATE utf8mb4_unicode_ci IN ($ph)
             LIMIT 1
        ");
        $stmt->execute(array_merge([$po_number], array_values($pgroups)));
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * True if $vendor_name has at least one in-scope Form 2 submission (a PO whose
 * PGroup is within $pgroups). The vendor-profile endpoint's scope guard: a
 * Technical Department user can only pull a profile for a vendor they can
 * already see in their own scoped results, never an arbitrary vendor name.
 * Fails closed (false) on any error or blank input.
 */
function ve2_tech_vendor_in_scope(PDO $pdo, string $vendor_name, array $pgroups): bool
{
    $vendor_name = trim($vendor_name);
    if ($vendor_name === '' || empty($pgroups)) {
        return false;
    }
    try {
        $ph = implode(',', array_fill(0, count($pgroups), '?'));
        $stmt = $pdo->prepare("
            SELECT 1
              FROM vendor_eval_submissions s
              JOIN (SELECT po_number, MAX(p_group) AS p_group FROM prs GROUP BY po_number) p
                ON p.po_number = s.po_number
             WHERE s.vendor_name = ?
               AND s.team IN ('ops','project') AND s.status = 'submitted'
               AND p.p_group COLLATE utf8mb4_unicode_ci IN ($ph)
             LIMIT 1
        ");
        $stmt->execute(array_merge([$vendor_name], array_values($pgroups)));
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * One vendor's full quality history within the account's PGroup scope, both
 * teams combined per PO. Powers the read-only vendor-profile modal. Returns two
 * buckets — 'all_time' and 'ytd' (last_submitted_at in the current calendar
 * year) — each shaped:
 *   ['po_count'      => int,    // completed (both-team) POs only
 *    'partial_count' => int,    // POs with just one team so far
 *    'avg_score'     => ?float, // mean final_score over completed POs
 *    'avg_grade'     => ?string,
 *    'grade_dist'    => ['A'=>n,'B'=>n,'C'=>n,'D'=>n],
 *    'pos'           => [ ['po_number','p_group','final_score','final_grade',
 *                          'last_submitted_at'], ... ]  // completed, newest first
 *   ]
 * Scoped identically to ve2_tech_form2_results() (prs.p_group IN $pgroups).
 * Read-only, fails closed to empty buckets on error/blank input.
 */
function ve2_tech_vendor_profile(PDO $pdo, string $vendor_name, array $pgroups): array
{
    $empty = static function (): array {
        return [
            'po_count'      => 0,
            'partial_count' => 0,
            'avg_score'     => null,
            'avg_grade'     => null,
            'grade_dist'    => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0],
            'pos'           => [],
        ];
    };
    $result = ['all_time' => $empty(), 'ytd' => $empty()];

    $vendor_name = trim($vendor_name);
    if ($vendor_name === '' || empty($pgroups)) {
        return $result;
    }

    try {
        $ph = implode(',', array_fill(0, count($pgroups), '?'));
        $stmt = $pdo->prepare("
            SELECT s.po_number, s.team, s.total_score, s.submitted_at, p.p_group
              FROM vendor_eval_submissions s
              JOIN (SELECT po_number, MAX(p_group) AS p_group FROM prs GROUP BY po_number) p
                ON p.po_number = s.po_number
             WHERE s.vendor_name = ?
               AND s.team IN ('ops','project') AND s.status = 'submitted'
               AND p.p_group COLLATE utf8mb4_unicode_ci IN ($ph)
        ");
        $stmt->execute(array_merge([$vendor_name], array_values($pgroups)));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return $result;
    }

    // Pair ops/project per PO.
    $by_po = [];
    foreach ($rows as $r) {
        $po = (string)$r['po_number'];
        if (!isset($by_po[$po])) {
            $by_po[$po] = ['po_number' => $po, 'p_group' => $r['p_group'], 'ops' => null, 'project' => null];
        }
        $by_po[$po][$r['team']] = [
            'total_score'  => $r['total_score'] !== null ? (float)$r['total_score'] : null,
            'submitted_at' => $r['submitted_at'],
        ];
    }

    $ytd_start = date('Y') . '-01-01 00:00:00';

    // Accumulate into an all-time list and a YTD list, then fold each into a bucket.
    $completed = ['all_time' => [], 'ytd' => []];
    $partial   = ['all_time' => 0, 'ytd' => 0];
    foreach ($by_po as $row) {
        $ops  = $row['ops'];
        $proj = $row['project'];
        $dates = array_filter([$ops['submitted_at'] ?? null, $proj['submitted_at'] ?? null]);
        $last  = !empty($dates) ? max($dates) : null;
        $is_ytd = $last !== null && strcmp((string)$last, $ytd_start) >= 0;

        $both = ($ops !== null && $proj !== null
            && $ops['total_score'] !== null && $proj['total_score'] !== null);
        if (!$both) {
            $partial['all_time']++;
            if ($is_ytd) $partial['ytd']++;
            continue;
        }
        $final_score = round(((float)$ops['total_score'] + (float)$proj['total_score']) / 2, 4);
        $entry = [
            'po_number'         => $row['po_number'],
            'p_group'           => $row['p_group'],
            'final_score'       => $final_score,
            'final_grade'       => ve2_score_grade($final_score),
            'last_submitted_at' => $last,
        ];
        $completed['all_time'][] = $entry;
        if ($is_ytd) $completed['ytd'][] = $entry;
    }

    foreach (['all_time', 'ytd'] as $scope) {
        $list = $completed[$scope];
        usort($list, static fn($a, $b) => strcmp((string)($b['last_submitted_at'] ?? ''), (string)($a['last_submitted_at'] ?? '')));
        $dist   = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
        $scores = [];
        foreach ($list as $e) {
            if (isset($dist[$e['final_grade']])) $dist[$e['final_grade']]++;
            $scores[] = (float)$e['final_score'];
        }
        $avg = $scores ? round(array_sum($scores) / count($scores), 2) : null;
        $result[$scope] = [
            'po_count'      => count($list),
            'partial_count' => $partial[$scope],
            'avg_score'     => $avg,
            'avg_grade'     => $avg !== null ? ve2_score_grade($avg) : null,
            'grade_dist'    => $dist,
            'pos'           => $list,
        ];
    }

    return $result;
}

/**
 * One vendor's quality history narrowed to a specific Year and/or Month —
 * the vendor-profile modal's "for instance this month or last month" filter.
 * Same shape as one bucket of ve2_tech_vendor_profile() (po_count,
 * partial_count, avg_score, avg_grade, grade_dist, pos), computed fresh
 * against last_submitted_at rather than reusing the fixed all-time/YTD split.
 * $year=null,$month=null is equivalent to the "all_time" bucket. Scoped
 * identically to ve2_tech_vendor_profile(). Read-only, fails closed to an
 * empty bucket on error/blank input.
 */
function ve2_tech_vendor_profile_range(PDO $pdo, string $vendor_name, array $pgroups, ?int $year = null, ?int $month = null): array
{
    $empty = [
        'po_count'      => 0,
        'partial_count' => 0,
        'avg_score'     => null,
        'avg_grade'     => null,
        'grade_dist'    => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0],
        'pos'           => [],
    ];

    $vendor_name = trim($vendor_name);
    if ($vendor_name === '' || empty($pgroups)) {
        return $empty;
    }

    try {
        $ph = implode(',', array_fill(0, count($pgroups), '?'));
        $params = array_merge([$vendor_name], array_values($pgroups));

        $date_sql = '';
        if ($year !== null && $month !== null) {
            $date_sql = ' AND s.submitted_at BETWEEN ? AND ? ';
            $from = sprintf('%04d-%02d-01 00:00:00', $year, $month);
            $params[] = $from;
            $params[] = date('Y-m-t 23:59:59', strtotime($from));
        } elseif ($year !== null) {
            $date_sql = ' AND YEAR(s.submitted_at) = ? ';
            $params[] = $year;
        } elseif ($month !== null) {
            $date_sql = ' AND MONTH(s.submitted_at) = ? ';
            $params[] = $month;
        }

        $stmt = $pdo->prepare("
            SELECT s.po_number, s.team, s.total_score, s.submitted_at, p.p_group
              FROM vendor_eval_submissions s
              JOIN (SELECT po_number, MAX(p_group) AS p_group FROM prs GROUP BY po_number) p
                ON p.po_number = s.po_number
             WHERE s.vendor_name = ?
               AND s.team IN ('ops','project') AND s.status = 'submitted'
               AND p.p_group COLLATE utf8mb4_unicode_ci IN ($ph)
               $date_sql
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return $empty;
    }

    // Pair ops/project per PO — a submission from OUTSIDE the selected window
    // (the other team submitted in a different month) simply isn't in $rows,
    // so that PO surfaces as partial here even if it's "complete" all-time.
    $by_po = [];
    foreach ($rows as $r) {
        $po = (string)$r['po_number'];
        if (!isset($by_po[$po])) {
            $by_po[$po] = ['po_number' => $po, 'p_group' => $r['p_group'], 'ops' => null, 'project' => null];
        }
        $by_po[$po][$r['team']] = [
            'total_score'  => $r['total_score'] !== null ? (float)$r['total_score'] : null,
            'submitted_at' => $r['submitted_at'],
        ];
    }

    $completed = [];
    $partial_count = 0;
    foreach ($by_po as $row) {
        $ops  = $row['ops'];
        $proj = $row['project'];
        $both = ($ops !== null && $proj !== null
            && $ops['total_score'] !== null && $proj['total_score'] !== null);
        if (!$both) {
            $partial_count++;
            continue;
        }
        $final_score = round(((float)$ops['total_score'] + (float)$proj['total_score']) / 2, 4);
        $dates = array_filter([$ops['submitted_at'] ?? null, $proj['submitted_at'] ?? null]);
        $completed[] = [
            'po_number'         => $row['po_number'],
            'p_group'           => $row['p_group'],
            'final_score'       => $final_score,
            'final_grade'       => ve2_score_grade($final_score),
            'last_submitted_at' => !empty($dates) ? max($dates) : null,
        ];
    }

    usort($completed, static fn($a, $b) => strcmp((string)($b['last_submitted_at'] ?? ''), (string)($a['last_submitted_at'] ?? '')));
    $dist   = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
    $scores = [];
    foreach ($completed as $e) {
        if (isset($dist[$e['final_grade']])) $dist[$e['final_grade']]++;
        $scores[] = (float)$e['final_score'];
    }
    $avg = $scores ? round(array_sum($scores) / count($scores), 2) : null;

    return [
        'po_count'      => count($completed),
        'partial_count' => $partial_count,
        'avg_score'     => $avg,
        'avg_grade'     => $avg !== null ? ve2_score_grade($avg) : null,
        'grade_dist'    => $dist,
        'pos'           => $completed,
    ];
}

/**
 * Every distinct site (prs.site_allocation) within the account's PGroup scope,
 * with a rollup of its POs and how many are evaluated. Powers the "Site
 * Allocations" dashboard section. One site can span many POs and vice-versa;
 * this counts DISTINCT po_number per site. Each returned row:
 *   ['site_allocation','p_group','business_unit'(?string),
 *    'po_count'(int), 'evaluated_count'(int),
 *    'best_grade'(?string A-D), 'avg_score'(?float)]
 * "evaluated" = a PO with both teams submitted (a final grade). Sorted
 * evaluated-first, then by PO count, then name. Read-only, fails closed to [].
 */
function ve2_tech_scope_sites(PDO $pdo, array $pgroups, ?int $year = null, ?int $month = null): array
{
    if (empty($pgroups)) {
        return [];
    }
    try {
        $ph = implode(',', array_fill(0, count($pgroups), '?'));
        $stmt = $pdo->prepare("
            SELECT site_allocation, po_number, MAX(p_group) AS p_group
              FROM prs
             WHERE p_group COLLATE utf8mb4_unicode_ci IN ($ph)
               AND site_allocation IS NOT NULL AND site_allocation <> ''
               AND po_number IS NOT NULL AND po_number <> ''
             GROUP BY site_allocation, po_number
        ");
        $stmt->execute(array_values($pgroups));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }

    // Final grade per completed PO (both teams submitted). Time-filtered so the
    // "evaluated" badge/count reflects the dashboard's active Year/Month window;
    // the site roster itself (query above) is NOT time-filtered — a site's
    // existence doesn't depend on when it happened to be evaluated.
    $grade_by_po = [];
    foreach (ve2_tech_form2_results($pdo, $pgroups, $year, $month) as $r) {
        if ($r['both_submitted'] && $r['final_grade'] !== null) {
            $grade_by_po[(string)$r['po_number']] = [
                'grade' => $r['final_grade'],
                'score' => $r['final_score'],
            ];
        }
    }
    $bu_map = ve2_tech_pgroup_business_units($pdo, $pgroups);

    $rank = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1];
    $sites = [];
    foreach ($rows as $r) {
        $site = (string)$r['site_allocation'];
        $po   = (string)$r['po_number'];
        if (!isset($sites[$site])) {
            $sites[$site] = [
                'site_allocation' => $site,
                'p_group'         => (string)$r['p_group'],
                'business_unit'   => $bu_map[(string)$r['p_group']] ?? null,
                'po_count'        => 0,
                'evaluated_count' => 0,
                '_scores'         => [],
                '_best'           => null,
            ];
        }
        $sites[$site]['po_count']++;
        if (isset($grade_by_po[$po])) {
            $sites[$site]['evaluated_count']++;
            $sites[$site]['_scores'][] = (float)$grade_by_po[$po]['score'];
            $g = $grade_by_po[$po]['grade'];
            $cur = $sites[$site]['_best'];
            if ($cur === null || ($rank[$g] ?? 0) > ($rank[$cur] ?? 0)) {
                $sites[$site]['_best'] = $g;
            }
        }
    }

    $out = [];
    foreach ($sites as $s) {
        $s['best_grade'] = $s['_best'];
        $s['avg_score']  = $s['_scores'] ? round(array_sum($s['_scores']) / count($s['_scores']), 2) : null;
        unset($s['_scores'], $s['_best']);
        $out[] = $s;
    }
    usort($out, static function ($a, $b) {
        return [$b['evaluated_count'], $b['po_count'], $a['site_allocation']]
             <=> [$a['evaluated_count'], $a['po_count'], $b['site_allocation']];
    });
    return $out;
}

/**
 * True if $site has at least one PO within $pgroups — the site-modal endpoint's
 * scope guard. Fails closed (false) on error / blank input.
 */
function ve2_tech_site_in_scope(PDO $pdo, string $site, array $pgroups): bool
{
    $site = trim($site);
    if ($site === '' || empty($pgroups)) {
        return false;
    }
    try {
        $ph = implode(',', array_fill(0, count($pgroups), '?'));
        $stmt = $pdo->prepare("
            SELECT 1 FROM prs
             WHERE site_allocation = ?
               AND p_group COLLATE utf8mb4_unicode_ci IN ($ph)
             LIMIT 1
        ");
        $stmt->execute(array_merge([$site], array_values($pgroups)));
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * POs at one site (within scope) with each PO's evaluation status/grade, for the
 * site drill-down modal. Each row:
 *   ['po_number','vendor_name','p_group','status'('completed'|'partial'|'none'),
 *    'final_score'(?float),'final_grade'(?string),
 *    'ops'(?['score'=>float,'band'=>?string]), 'project'(?[...])]
 * The per-team 'ops'/'project' slots let the UI show whether a grade is a true
 * final (both teams) or just one team's entry. Sorted: completed first (best
 * grade first), then partial, then not-evaluated. Read-only, fails closed to [].
 */
function ve2_tech_site_pos(PDO $pdo, string $site, array $pgroups): array
{
    $site = trim($site);
    if ($site === '' || empty($pgroups)) {
        return [];
    }
    try {
        $ph = implode(',', array_fill(0, count($pgroups), '?'));
        $stmt = $pdo->prepare("
            SELECT po_number, MAX(vendor_name) AS vendor_name, MAX(p_group) AS p_group
              FROM prs
             WHERE site_allocation = ?
               AND p_group COLLATE utf8mb4_unicode_ci IN ($ph)
               AND po_number IS NOT NULL AND po_number <> ''
             GROUP BY po_number
        ");
        $stmt->execute(array_merge([$site], array_values($pgroups)));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }

    // Classify each PO by evaluation state, keeping each team's own score/band.
    $team_slot = static function ($slot): ?array {
        if ($slot === null || ($slot['total_score'] ?? null) === null) {
            return null;
        }
        return ['score' => (float)$slot['total_score'], 'band' => $slot['score_band'] ?? null];
    };
    $status_by_po = [];
    foreach (ve2_tech_form2_results($pdo, $pgroups) as $r) {
        $status_by_po[(string)$r['po_number']] = [
            'status'      => $r['both_submitted'] ? 'completed' : 'partial',
            'final_score' => $r['final_score'],
            'final_grade' => $r['final_grade'],
            'ops'         => $team_slot($r['ops']),
            'project'     => $team_slot($r['project']),
        ];
    }

    $rank = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1];
    $out = [];
    foreach ($rows as $r) {
        $po = (string)$r['po_number'];
        $ev = $status_by_po[$po] ?? ['status' => 'none', 'final_score' => null, 'final_grade' => null, 'ops' => null, 'project' => null];
        $out[] = [
            'po_number'   => $po,
            'vendor_name' => $r['vendor_name'],
            'p_group'     => $r['p_group'],
            'status'      => $ev['status'],
            'final_score' => $ev['final_score'],
            'final_grade' => $ev['final_grade'],
            'ops'         => $ev['ops'],
            'project'     => $ev['project'],
        ];
    }
    $order = ['completed' => 0, 'partial' => 1, 'none' => 2];
    usort($out, static function ($a, $b) use ($order, $rank) {
        return [$order[$a['status']], -($rank[$a['final_grade'] ?? ''] ?? 0), $a['po_number']]
             <=> [$order[$b['status']], -($rank[$b['final_grade'] ?? ''] ?? 0), $b['po_number']];
    });
    return $out;
}

/**
 * Both teams' submissions + their per-criterion answers for one PO, for the
 * read-only drill-down modal. Returns:
 *   ['ops' => ?slot, 'project' => ?slot, 'final' => ?['score'=>float,'grade'=>string]]
 * where each slot is:
 *   ['requestor_name','total_score','score_band','submitted_at',
 *    'answers' => [ ['criterion_name','score','selected_score_label',
 *                    'weight','weighted_score','comment','is_rating'(bool)], ... ]]
 * question_type is inferred from score (NULL => text/identification row).
 */
function ve2_tech_po_detail(PDO $pdo, string $po_number): array
{
    $result = ['ops' => null, 'project' => null, 'final' => null];

    $sub_stmt = $pdo->prepare("
        SELECT id, team, requestor_name, total_score, score_band, submitted_at
          FROM vendor_eval_submissions
         WHERE po_number = ? AND team IN ('ops','project') AND status = 'submitted'
    ");
    $sub_stmt->execute([$po_number]);
    $subs = $sub_stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($subs)) {
        return $result;
    }

    $ans_stmt = $pdo->prepare("
        SELECT criterion_name, question_no, score, selected_score_label,
               weight, weighted_score, comment
          FROM vendor_eval_answers
         WHERE submission_id = ?
         ORDER BY question_no
    ");

    $scores = [];
    foreach ($subs as $s) {
        $team = $s['team'];
        $ans_stmt->execute([(int)$s['id']]);
        $answers = [];
        foreach ($ans_stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $answers[] = [
                'criterion_name'      => (string)$a['criterion_name'],
                'score'               => $a['score'] !== null ? (int)$a['score'] : null,
                'selected_score_label'=> (string)$a['selected_score_label'],
                'weight'              => (float)$a['weight'],
                'weighted_score'      => (float)$a['weighted_score'],
                'comment'             => $a['comment'] !== null ? (string)$a['comment'] : null,
                'is_rating'           => $a['score'] !== null,
            ];
        }
        $result[$team] = [
            'requestor_name' => (string)$s['requestor_name'],
            'total_score'    => $s['total_score'] !== null ? (float)$s['total_score'] : null,
            'score_band'     => $s['score_band'],
            'submitted_at'   => $s['submitted_at'],
            'answers'        => $answers,
        ];
        if ($s['total_score'] !== null) {
            $scores[$team] = (float)$s['total_score'];
        }
    }

    if (isset($scores['ops'], $scores['project'])) {
        $avg = round(($scores['ops'] + $scores['project']) / 2, 4);
        $result['final'] = ['score' => $avg, 'grade' => ve2_score_grade($avg)];
    }

    return $result;
}
