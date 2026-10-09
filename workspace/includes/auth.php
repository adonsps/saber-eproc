<?php
// includes/auth.php
require_once __DIR__ . '/../../includes/auth_intended.php';
session_start();

/**
 * Gate autentikasi dasar untuk workspace/pages/**. Semua gate lain memanggilnya
 * lebih dulu, jadi return-to-intended cukup dipasang di sini untuk 72 halaman.
 *
 * Tujuan disimpan di sesi (bukan query string) supaya tidak ada permukaan
 * open-redirect dan tidak hilang saat login gagal. XHR dibalas 401 JSON, bukan
 * 302 ke HTML login — kalau tidak, JSON.parse gagal dan sesi habis muncul di UI
 * sebagai "Gagal memuat tabel SLA ... parsererror"; includes/footer.php yang
 * menerjemahkan 401 itu jadi navigasi ke login.
 *
 * intended_url bukan bukti akses: gate halaman tujuan yang memutuskan.
 */
function require_login() {
    if (isset($_SESSION['user_id'])) {
        // Identity is not enough: VSS and Workspace share one session cookie
        // (session.cookie_domain in the Dockerfile), so a plain VSS login also
        // satisfies isset($_SESSION['user_id']) here. Establish that this is a
        // Workspace session BEFORE anything else looks at it.
        ws_enforce_workspace_session();

        // Idle check next: an expired session must not be handed to the scope
        // gate, which would answer with a role-based redirect instead of a
        // session-expired one.
        ws_enforce_idle_timeout();
        ws_enforce_viewer_scope();
        return;
    }

    if (auth_is_ajax()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'session_expired', 'login' => 'login.php']);
        exit;
    }

    auth_remember_intended();
    header("Location: login.php");
    exit;
}

/**
 * The roles that exist on the WORKSPACE side.
 *
 * The two apps' role vocabularies do not overlap: Workspace roles are TitleCase
 * ('Manager', 'PIC', ...) while VSS roles are lowercase snake_case ('vendor_mgt',
 * 'sourcing', 'vendor', ...). That is what makes the check below a reliable test
 * of WHICH app opened the session.
 *
 * Matched case-SENSITIVELY, on purpose. A case-insensitive compare would let the
 * VSS role 'viewer' pass as the Workspace role 'Viewer' — exactly the confusion
 * that workspace/pages/return_to_vss.php already warns about. Every genuine
 * Workspace session copies this value verbatim out of the Workspace users table,
 * so exact matching costs nothing.
 */
function ws_workspace_roles(): array {
    return [
        'Manager',
        'Category Manager',
        'PIC',
        'Admin',
        'STC',
        'QS',
        'Project Director',
        'Project Design',
        'Project Management',
        'Technical Department',
        'Viewer',
    ];
}

/**
 * Refuse sessions that were opened by VSS and never granted Workspace access.
 *
 * Why this is needed: session.cookie_domain scopes one cookie across both apps,
 * and require_login() used to ask only "is someone logged in?". So a VSS-only
 * account (say role 'vendor_mgt') could log in at the VSS side and then simply
 * TYPE a /workspace/pages/... URL. Most pages happened to survive that, because
 * their own inline checks compare against Workspace role names that no VSS role
 * matches — but ~16 scripts gated by require_login() alone had no role check at
 * all, including aon_clear_data.php, which runs DELETE FROM aon_skus.
 *
 * The three *sanctioned* ways into Workspace (workspace/pages/login.php,
 * sso_vss.php, and the Workspace branch of login_action.php) all require a row
 * in the Workspace users table and all write that row's role into the session,
 * so every legitimate session passes this gate untouched. Only sessions that
 * never earned a Workspace role are turned away.
 *
 * 'Unassigned Role' is deliberately NOT in the allowlist: login.php already
 * refuses it at the door, so it must not become a way in through the side.
 */
function ws_enforce_workspace_session() {
    if (in_array((string)($_SESSION['role'] ?? ''), ws_workspace_roles(), true)) {
        return;
    }

    // XHR gets JSON for the same reason the rest of this file does: a 302 to an
    // HTML page surfaces in DataTables as a parse error, not as a refusal.
    if (auth_is_ajax()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'not_a_workspace_user']);
        exit;
    }

    // Send them back to VSS, where their session IS valid, rather than to the
    // Workspace login — they have working credentials, they just have no
    // Workspace account, and a login form would invite them to try again.
    header("Location: ../../index.php?error=" . urlencode(
        'Akun Anda tidak memiliki akses ke Workspace.'
    ));
    exit;
}

function require_manager() {
    require_login();
    // A Category Manager also gets standard Manager access
    if ($_SESSION['role'] !== 'Manager' && $_SESSION['role'] !== 'Category Manager') {
        // Deliberately NOT widened for 'Viewer': this helper guards every
        // manager-only WRITE surface, and Viewer must fail all of them. Only the
        // landing target is role-aware, so a Viewer who reaches a manager page by
        // URL is bounced to their own dashboard instead of a PIC worklist they
        // have no business seeing.
        header("Location: " . (ws_is_viewer() ? "dashboard_mgr.php" : "dashboard_pic.php"));
        exit;
    }
}

/**
 * Idle limits, in seconds.
 *
 * The idle rule already existed, but ONLY as a JavaScript countdown in
 * includes/footer.php — nothing enforced it server-side, so closing the tab or
 * disabling JS left the session alive until PHP's own garbage collector got to
 * it. These constants move the rule to the server; the JS timer stays as the
 * friendly warning layer and reads the same numbers.
 *
 * The default was raised from 10 to 30 minutes: 10 was cutting people off
 * mid-task on long forms, and the limit is now enforced server-side, so the
 * number finally means what it says.
 *
 * Viewer gets 2 hours because it is a read-only account meant to be left open
 * on a dashboard for long stretches. It cannot change anything, so an abandoned
 * session is a disclosure risk only — not a data-integrity one — which is a
 * different trade-off from the roles that can write.
 *
 * NOTE: php.ini's session.gc_maxlifetime must be >= the LARGEST value here
 * (see the session.ini block in the Dockerfile), otherwise PHP's collector
 * deletes the session file first and the limits below never get to speak.
 */
const WS_IDLE_LIMIT_DEFAULT = 1800;   // 30 minutes
const WS_IDLE_LIMIT_VIEWER  = 7200;   // 2 hours

function ws_idle_limit(): int {
    return ws_is_viewer() ? WS_IDLE_LIMIT_VIEWER : WS_IDLE_LIMIT_DEFAULT;
}

/**
 * Requests that must NOT count as user activity.
 *
 * includes/header.php polls announcement_notifications.php every 45 seconds on
 * every page. Left alone, that poll alone would refresh last_activity forever
 * and no session could ever go idle — the timeout would be enforced in name
 * only. The poll still requires a valid session; it just cannot extend one.
 */
function ws_passive_scripts(): array {
    return ['announcement_notifications.php'];
}

/**
 * Expire a session that has been idle past its role's limit.
 *
 * Only called from require_login(), so it covers every workspace page and every
 * AJAX endpoint that gates itself. XHR gets the same 401 JSON that a missing
 * session produces, because includes/footer.php already translates a 401 into a
 * navigation to login — treating the two identically means the client needs no
 * new case.
 */
function ws_enforce_idle_timeout() {
    $now  = time();
    $last = $_SESSION['last_activity'] ?? null;

    // No marker: a session predating this check, or one opened by the VSS side.
    // Adopt it as active rather than expiring it — logging people out because a
    // field was missing would be a bug, not a security win.
    if ($last === null) {
        $_SESSION['last_activity'] = $now;
        return;
    }

    if (($now - (int)$last) > ws_idle_limit()) {
        $_SESSION = [];
        session_regenerate_id(true);   // drop the old session file, keep a usable one

        if (auth_is_ajax()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'session_expired', 'login' => 'login.php']);
            exit;
        }

        // user_id is gone by now, which is exactly what auth_remember_intended()
        // requires before it will record where to come back to.
        auth_remember_intended();
        header("Location: login.php?error=" . urlencode('Sesi Anda berakhir karena tidak ada aktivitas. Silakan masuk kembali.'));
        exit;
    }

    if (!in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ws_passive_scripts(), true)) {
        $_SESSION['last_activity'] = $now;
    }
}

/**
 * The four surfaces a Viewer may run, plus the read-only AJAX feeds they call.
 *
 * An allowlist, not a denylist, and enforced by script name rather than by
 * editing each page: a Viewer session is an ordinary logged-in session, so
 * every one of the ~24 workspace scripts whose only gate is require_login()
 * would otherwise be reachable by URL — including writers like
 * ajax_aon_sku_edit.php and user_management.php that have nothing to do with
 * the four areas. Default-deny means a page added later is closed to Viewer
 * until someone lists it here, which is the safe direction to fail.
 *
 * Endpoints that mix read and write (ajax_cost_avoidance_baselines.php) are
 * listed here and refuse the write half separately via ws_require_write().
 */
function ws_viewer_allowed_scripts(): array {
    return [
        // Pages
        'dashboard_mgr.php',
        'cost_avoidance.php',
        'cost_savings.php',
        'sla_report.php',

        // Dashboard feeds (read)
        'ajax_dashboard_data.php',
        'ajax_dashboard_table.php',
        'ajax_get_pr_taxonomy.php',

        // Cost Avoidance feeds
        'ajax_cost_avoidance_charts.php',
        'ajax_cost_avoidance_table.php',
        'ajax_cost_avoidance_baselines.php',
        'ajax_search_vendors.php',

        // Cost Savings feeds (read)
        'ajax_cost_savings_summary.php',
        'ajax_cost_savings_table.php',

        // Account self-service. Its only write is an UPDATE of the caller's OWN
        // password, scoped by $_SESSION['username'] — not a data mutation, and
        // without it a Viewer could never change the password they were issued.
        'profile.php',

        // SLA Tracking feeds (read)
        'ajax_sla_table.php',
        'ajax_sla_charts.php',
        'ajax_sla_items.php',
        'ajax_sla_siblings.php',
        'ajax_sla_get_pr_taxonomy.php',
        'ajax_sla_request_history.php',
        'ajax_sla_chat.php',
    ];
}

/**
 * Default-deny page gate for Viewer. No-op for every other role.
 *
 * Called from require_login() so it covers the whole workspace from one place.
 * Refuses XHR with 403 JSON for the same reason ws_require_write() does — a
 * redirect to HTML surfaces in DataTables as a parse error, not as a refusal.
 */
function ws_enforce_viewer_scope() {
    if (!ws_is_viewer()) {
        return;
    }

    $script = basename($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
    if (in_array($script, ws_viewer_allowed_scripts(), true)) {
        return;
    }

    if (auth_is_ajax()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Halaman ini tidak tersedia untuk akun lihat-saja (view only).']);
        exit;
    }

    // Landing page of the role. Guarded against a redirect loop: dashboard_mgr.php
    // is itself on the allowlist above, so this can only fire once.
    header("Location: dashboard_mgr.php");
    exit;
}

/**
 * The read-only 'Viewer' role: admitted to Dashboard, Cost Avoidance, Cost
 * Savings and SLA Tracking, and refused every Create/Update/Delete in the app.
 *
 * Kept as one predicate so the role name is written down once. Everything else
 * asks this question rather than comparing $_SESSION['role'] itself.
 */
function ws_is_viewer(): bool {
    // Case-insensitive ON PURPOSE. The same account carries the role in two
    // spellings: 'Viewer' in the Workspace users table, 'viewer' in the VSS one.
    // Normally login_action.php overwrites the session with the Workspace value,
    // but pages/return_to_vss.php re-reads the VSS row and overwrites it BACK to
    // the lowercase form. With an exact === match, one visit to that page silently
    // turned every gate below into a no-op — writes allowed, page allowlist
    // bypassed — because 'viewer' !== 'Viewer'. Comparing case-insensitively means
    // no code path can strip the restrictions just by re-spelling the role.
    return strcasecmp((string)($_SESSION['role'] ?? ''), 'Viewer') === 0;
}

/**
 * Refuse any state-changing request made by a Viewer session.
 *
 * Call this at the top of every write endpoint and inside every inline POST
 * handler on an admitted page — hiding the button is not a gate, since each
 * endpoint stays independently reachable by URL.
 *
 * A blanket "Viewer + POST = deny" rule was rejected on purpose: the read
 * endpoints behind these pages (ajax_dashboard_table.php, ajax_sla_table.php,
 * the Cost Savings tables) are DataTables serverSide and fetch over POST, so
 * such a rule would break the very pages a Viewer is here to look at.
 *
 * Answers XHR with 403 JSON rather than a redirect, matching require_login():
 * a 302 to login HTML lands in DataTables as a "parsererror", not as a refusal.
 */
function ws_require_write() {
    require_login();

    if (!ws_is_viewer()) {
        return;
    }

    if (auth_is_ajax()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Akun Anda bersifat lihat-saja (view only) dan tidak dapat mengubah data.']);
        exit;
    }

    header("Location: dashboard_mgr.php");
    exit;
}

/**
 * Read gate for the Command Center dashboard and its read-only AJAX feeds.
 *
 * Exists because require_manager() could not be widened to admit Viewer without
 * simultaneously opening every manager-only write surface that relies on it.
 * The write paths on this dashboard (PIC reassign, taxonomy update, WA blast)
 * keep their require_manager()/ws_require_write() gate, so admitting Viewer here
 * grants sight only.
 */
function require_dashboard_view() {
    require_login();
    $allowed = ['Manager', 'Category Manager', 'Viewer'];
    if (!in_array($_SESSION['role'] ?? '', $allowed, true)) {
        header("Location: dashboard_pic.php");
        exit;
    }
}

/**
 * Cost Avoidance access gate — six-branch team-scoped logic via
 * ca_resolve_session_team_scope() (includes/cost_avoidance_helper.php),
 * replacing the old require_manager()-only gate on cost_avoidance.php.
 * Unlike require_manager()/require_category_manager(), the resolver is
 * DB-backed (reads project_head_groups/project_pgroup_officers/users), so
 * this takes $pdo explicitly and MUST be called after config/database.php
 * AND includes/cost_avoidance_helper.php are both loaded — same convention
 * as get_project_head_groups()/get_store_opening_officer_groups() below.
 *
 * All four Cost Avoidance tabs stay visible for every admitted role (no
 * tab-level hiding) — this only decides admit/deny and, on admit, returns
 * the resolved scope for the page to thread through every query/write.
 *
 * Denies (resolved scope === []) with the same silent, role-routed redirect
 * every other gate in this file uses: Manager/Category Manager ->
 * dashboard_mgr.php, everyone else -> dashboard_pic.php. No flash message,
 * matching precedent (denial here is never explained, same as
 * require_manager()/require_sla_access()/require_category_manager() today).
 *
 * @return array|null the resolved scope: null = unrestricted, non-empty = allowed team codes
 */
function require_cost_avoidance_access(PDO $pdo): ?array {
    require_login();

    $role = $_SESSION['role'] ?? '';
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $scope = ca_resolve_session_team_scope($pdo, $userId, $role);

    if ($scope !== null && empty($scope)) {
        $target = ($role === 'Manager' || $role === 'Category Manager') ? 'dashboard_mgr.php' : 'dashboard_pic.php';
        header("Location: {$target}");
        exit;
    }

    return $scope;
}

// PIC is admitted READ-ONLY: resolve_sla_persona() gives them the 'PIC' persona,
// which restricts every row to prs.assigned_pic = their own name, and the page
// offers them no write action other than submitting an adjustment request.
// Technical Department was removed from this list: SLA is no longer part of that
// role's surface at all (the SLA column is gone from dashboard_tech.php and the
// sidebar link is hidden in header.php), so the page itself must refuse them too
// — a hidden link is not a gate.
function require_sla_access() {
    require_login();
    // Viewer is read-only everywhere: it sees every SLA row (persona COMBINED,
    // see resolve_sla_persona()) but ws_require_write() blocks the adjustment
    // request + taxonomy endpoints that also sit behind this gate.
    $allowed = ['Manager', 'Category Manager', 'PIC', 'Viewer'];
    if (!in_array($_SESSION['role'] ?? '', $allowed, true)) {
        // Role-routed like require_cost_avoidance_access() above, so a denied
        // Technical Department user lands on their own worklist instead of an
        // empty PIC dashboard.
        $target = ($_SESSION['role'] ?? '') === 'Technical Department' ? 'dashboard_tech.php' : 'dashboard_pic.php';
        header("Location: {$target}");
        exit;
    }
}

// NEW FUNCTION: Strict lock for Master Data
function require_category_manager() {
    require_login();
    if ($_SESSION['role'] !== 'Category Manager') {
        // Boot standard Managers back to the Command Center
        header("Location: dashboard_mgr.php");
        exit;
    }
}

/**
 * Returns the list of Project group numbers the current user may access on the
 * Project Head dashboard.
 *   - Category Manager  -> every active group.
 *   - A user listed as an active head in project_head_groups -> only their group(s).
 *   - Everyone else     -> empty array (no access).
 * Fails closed (returns []) if the project_head_groups table is missing.
 * Does NOT alter require_manager()/require_login() behaviour.
 */
function get_project_head_groups($pdo, $session) {
    try {
        $role = $session['role'] ?? '';
        // 'Viewer' sees every active group, like a Category Manager. Needed for the
        // COMBINED persona to mean anything on the dashboard: the Project row source
        // is gated on this list being non-empty, so a Viewer would otherwise get a
        // team chart with nothing in it. Read-only either way — the one write path
        // that consults this list, ajax_project_assign.php, refuses Viewer outright.
        if ($role === 'Category Manager' || $role === 'Viewer') {
            $stmt = $pdo->query("SELECT DISTINCT project_group_number FROM project_head_groups WHERE active = 1 ORDER BY project_group_number");
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        $username = trim((string)($session['username'] ?? ''));
        if ($username === '') return [];
        $stmt = $pdo->prepare("SELECT DISTINCT project_group_number FROM project_head_groups WHERE active = 1 AND head_username = ? ORDER BY project_group_number");
        $stmt->execute([$username]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        return [];
    }
}
/**
 * Returns the Project group numbers (restricted to 1=PKR, 2=PKP) the current
 * user may view/edit Store Opening Dates for, via project_pgroup_officers.
 * Distinct from get_project_head_groups() (Project Heads, all groups): this is
 * the officer-level grant used by vendor_eval_v2_results.php's Store Opening
 * Date tab, additive to (not a replacement for) require_manager() there.
 * Fails closed (returns []) if project_pgroup_officers is missing/unreadable.
 */
function get_store_opening_officer_groups($pdo, $session) {
    try {
        $user_id = (int)($session['user_id'] ?? 0);
        if ($user_id <= 0) return [];
        $stmt = $pdo->prepare("
            SELECT DISTINCT project_group_number
            FROM project_pgroup_officers
            WHERE active = 1 AND officer_user_id = ? AND project_group_number IN (1, 2)
            ORDER BY project_group_number
        ");
        $stmt->execute([$user_id]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        return [];
    }
}

function formatJakartaDate($dateString) {
    if (empty($dateString)) return '-';
    $dt = new DateTime($dateString, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
    return $dt->format('d F Y H:i');
}

/**
 * Recursively fetches all subordinate users under a given manager username.
 * Returns an array of user rows (associative arrays).
 */
function get_all_subordinates($pdo, $manager_username) {
    $stmt = $pdo->query("SELECT username, name, role, manager_username, team_name FROM users");
    $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mappings = [];
    try {
        $stmt_mgrs = $pdo->query("SELECT username, manager_username FROM user_managers");
        if ($stmt_mgrs) {
            $mappings = $stmt_mgrs->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // Table might be removed
    }

    $user_managers = [];
    foreach ($mappings as $m) {
        $user_managers[$m['username']][] = $m['manager_username'];
    }
    
    // Also include manager_username from users table to prevent conflict/stale mappings
    foreach ($all_users as $u) {
        if (!empty($u['manager_username']) && !in_array($u['manager_username'], $user_managers[$u['username']] ?? [])) {
            $user_managers[$u['username']][] = $u['manager_username'];
        }
    }

    $subordinates = [];
    $to_process = [$manager_username];
    $processed = [];

    while(!empty($to_process)) {
        $current_manager = array_shift($to_process);
        if (in_array($current_manager, $processed)) continue;
        $processed[] = $current_manager;

        foreach ($all_users as $user) {
            $username = $user['username'];
            $managers = $user_managers[$username] ?? [];
            if (in_array($current_manager, $managers)) {
                if (!isset($subordinates[$username])) {
                    $user['manager_username'] = !empty($managers) ? $managers[0] : null;
                    $subordinates[$username] = $user;
                    $to_process[] = $username;
                }
            }
        }
    }
    return array_values($subordinates);
}
?>
