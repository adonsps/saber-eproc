<?php
declare(strict_types=1);

// Tests for the AI ticket-assignment engine shared by the browser dispatcher
// (workspace/pages/ajax_process_batch.php) and the K2 parser worker
// (workspace/cron/process_k2_import_queue.php).
//
// Business-data tests: this CREATEs and DELETEs FROM prs / master_data /
// system_settings / ai_golden_rules. Point K2_AUTOMATION_TEST_DSN at a genuinely
// disposable database, NEVER the shared app database.
//
// No network: every case injects a stub in place of call_gemini_api(), so the
// suite never contacts Gemini and never needs an API key. A test that reaches
// the real API is a bug in the test, not a feature.
//
// Usage:
//   K2_AUTOMATION_TEST_DSN='mysql:host=db;dbname=k2_tmp;charset=utf8mb4' \
//   K2_AUTOMATION_TEST_USER=root K2_AUTOMATION_TEST_PASSWORD=... \
//   docker-compose exec -T app php workspace/automation/test_ai_auto_assign_db.php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

// Defines k2_worker_run_ai_assignment() and K2_WORKER_AI_ASSIGN_LOCK_NAME. Safe
// to require: the worker's self-execution guard compares SCRIPT_FILENAME against
// its own path, which under this runner is this file, so it only defines.
require_once __DIR__ . '/../cron/process_k2_import_queue.php';
require_once __DIR__ . '/../includes/ai_ticket_assigner.php';
// Defines gemini_key_ticket_assign(), which ai_assign_process_batch() reads
// (as the key argument passed alongside the $gemini stub) regardless of
// whether the stub itself uses it -- pure env-var getters, no side effects,
// safe to require directly without pulling in config/database.php.
require_once __DIR__ . '/../config/gemini_api.php';
require_once __DIR__ . '/../includes/automation/diagnostics_logger.php';

$dsn = getenv('K2_AUTOMATION_TEST_DSN') ?: '';
$user = getenv('K2_AUTOMATION_TEST_USER') ?: '';
$password = getenv('K2_AUTOMATION_TEST_PASSWORD') ?: '';

if ($dsn === '' || $user === '') {
    fwrite(STDERR, "K2_AUTOMATION_TEST_DSN and K2_AUTOMATION_TEST_USER are required.\n");
    exit(1);
}

$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$pdo = new PDO($dsn, $user, $password, $pdoOptions);

/**
 * Stand-in for call_gemini_api(). Answers exactly the batch it was handed by
 * reading the IDs back out of the prompt, and counts its own invocations so a
 * test can assert that no call happened at all.
 */
final class AiAssignGeminiStub
{
    public int $calls = 0;

    /** @param array<string,string> $mapping "<ticket>_<line>" => category to return */
    public function __construct(
        private readonly array $mapping = [],
        private readonly string $fallbackCategory = ''
    ) {
    }

    public function __invoke(string $prompt): string
    {
        $this->calls++;

        preg_match_all('/^ID: (\S+) \| Desc:/m', $prompt, $matches);
        $response = [];
        foreach ($matches[1] as $id) {
            $category = $this->mapping[$id] ?? $this->fallbackCategory;
            if ($category === '') {
                continue;
            }
            $response[$id] = [
                'category' => $category,
                'confidence' => 100,
                'new_level_4' => '',
                'new_level_5' => '',
            ];
        }

        return (string) json_encode($response);
    }
}

/** Always throws, standing in for a Gemini outage. */
final class AiAssignFailingStub
{
    public int $calls = 0;

    public function __invoke(string $prompt): string
    {
        $this->calls++;
        throw new RuntimeException('simulated API outage');
    }
}

/**
 * For Master Data enrichment cases: unlike AiAssignGeminiStub (always
 * confidence 100, no new_level_4/5), this answers with whatever full
 * proposal each test hands it -- including a below-threshold confidence and
 * a freshly-worded new_level_4/new_level_5, the way the real Gemini call
 * reworks level_5 differently on every invocation.
 */
final class AiEnrichmentGeminiStub
{
    public int $calls = 0;

    /** @param array<string,array{category:string,confidence:int,new_level_4:string,new_level_5:string}> $mapping */
    public function __construct(private readonly array $mapping = [])
    {
    }

    public function __invoke(string $prompt): string
    {
        $this->calls++;

        preg_match_all('/^ID: (\S+) \| Desc:/m', $prompt, $matches);
        $response = [];
        foreach ($matches[1] as $id) {
            if (isset($this->mapping[$id])) {
                $response[$id] = $this->mapping[$id];
            }
        }

        return (string) json_encode($response);
    }
}

ai_test_create_schema($pdo);

$tests = [];

// --- Gate: ai_assign_live_enabled -------------------------------------------

$tests['kill switch is OFF when the settings row is absent'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_assert_same(false, ai_assign_live_enabled($pdo));
};

$tests['kill switch is OFF for any value other than exactly 1'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    foreach (['0', '', 'true', 'yes', 'ON', '2', '01'] as $value) {
        ai_test_set_switch($pdo, $value);
        ai_test_assert_same(false, ai_assign_live_enabled($pdo), "value '{$value}' must not enable the switch");
    }
};

$tests['kill switch is ON only for 1'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_set_switch($pdo, '1');
    ai_test_assert_same(true, ai_assign_live_enabled($pdo));
};

$tests['kill switch fails closed when system_settings is missing'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_set_switch($pdo, '1');
    $pdo->exec('RENAME TABLE system_settings TO system_settings_hidden');
    try {
        ai_test_assert_same(false, ai_assign_live_enabled($pdo), 'a missing table must never read as "go ahead"');
    } finally {
        $pdo->exec('RENAME TABLE system_settings_hidden TO system_settings');
    }
};

// --- Queue accounting --------------------------------------------------------

$tests['queue counts only eligible pending tickets'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    // "Pending" is missing a PIC, a taxonomy, or both -- a PIC alone does NOT
    // settle a line (see ai_assign_pending_predicate()). T002 carries both and
    // is the only genuinely finished ticket here; T007 is the case that used to
    // be wrongly treated as done and left permanently Unmapped.
    ai_test_add_pr($pdo, 'T001', '10');                                        // eligible
    ai_test_add_pr($pdo, 'T002', '10', ['assigned_pic' => 'Someone',
                                        'master_data_id' => 1]);               // fully assigned
    ai_test_add_pr($pdo, 'T003', '10', ['status_ticket' => 'Cancelled']);      // excluded status
    ai_test_add_pr($pdo, 'T004', '10', ['ticket_type' => 'Low Value Opex']);   // excluded type
    ai_test_add_pr($pdo, 'T005', '10', ['pr_date' => '2026-04-30']);           // before the cutoff
    ai_test_add_pr($pdo, 'T006', '10', ['pr_date' => null]);                   // undated is eligible
    ai_test_add_pr($pdo, 'T007', '10', ['assigned_pic' => 'Someone']);         // PIC but no taxonomy

    ai_test_assert_same(3, ai_assign_queue_count($pdo));
};

$tests['tickets belonging to an active Project p_group are excluded'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', 'Budi');
    $pdo->exec("INSERT INTO project_pgroups (p_group, status) VALUES ('PRJ1', 'ACTIVE'), ('PRJ2', 'INACTIVE')");

    ai_test_add_pr($pdo, 'T010', '10', ['p_group' => 'PRJ1']);  // active project -> excluded
    ai_test_add_pr($pdo, 'T011', '10', ['p_group' => 'PRJ2']);  // inactive project -> eligible
    ai_test_add_pr($pdo, 'T012', '10', ['p_group' => 'P001']);  // ordinary -> eligible

    ai_test_assert_same(2, ai_assign_queue_count($pdo));

    // And the exclusion also guards the write: even if the AI answered for the
    // project line, the UPDATE carries the same NOT EXISTS clause.
    $stub = new AiAssignGeminiStub([], 'FIRE EXTINGUISHER');
    ai_assign_process_batch($pdo, 5, $stub);

    ai_test_assert_same(null, ai_test_fetch_pr($pdo, 'T010', '10')['assigned_pic']);
    ai_test_assert_same('Budi', ai_test_fetch_pr($pdo, 'T011', '10')['assigned_pic']);
    ai_test_assert_same('Budi', ai_test_fetch_pr($pdo, 'T012', '10')['assigned_pic']);
};

// --- The pr_date window (worker-only) ----------------------------------------

$tests['no window is configured by default'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_assert_same(null, ai_assign_min_pr_date($pdo));
};

$tests['a window narrows the queue without touching the browser path'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_add_pr($pdo, 'T050', '10', ['pr_date' => '2026-06-15']);  // old
    ai_test_add_pr($pdo, 'T051', '10', ['pr_date' => '2026-07-20']);  // old
    ai_test_add_pr($pdo, 'T052', '10', ['pr_date' => '2026-08-05']);  // recent

    // Unwindowed (what auto_assign.php always sees) counts all three.
    ai_test_assert_same(3, ai_assign_queue_count($pdo));

    ai_test_set_setting($pdo, AI_ASSIGN_MIN_PR_DATE_SETTING_KEY, '2026-08-01');
    ai_test_assert_same('2026-08-01', ai_assign_min_pr_date($pdo));
    ai_test_assert_same(1, ai_assign_queue_count($pdo, ai_assign_min_pr_date($pdo)));

    // The browser path passes nothing and is therefore unaffected.
    ai_test_assert_same(3, ai_assign_queue_count($pdo));
};

$tests['a window stops the worker assigning tickets outside it'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_set_switch($pdo, '1');
    ai_test_set_setting($pdo, AI_ASSIGN_MIN_PR_DATE_SETTING_KEY, '2026-08-01');
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', 'Budi');
    ai_test_add_pr($pdo, 'T060', '10', ['pr_date' => '2026-06-15']);
    ai_test_add_pr($pdo, 'T061', '10', ['pr_date' => '2026-08-05']);

    $stub = new AiAssignGeminiStub([], 'FIRE EXTINGUISHER');
    $summary = ai_test_with_env(
        ['AI_ASSIGN_SLEEP_MS_BETWEEN_BATCHES' => '0'],
        fn(): array => k2_worker_run_ai_assignment($pdo, ai_test_logger(), $stub)
    );

    ai_test_assert_same(1, $summary['tickets_assigned']);
    ai_test_assert_same(null, ai_test_fetch_pr($pdo, 'T060', '10')['assigned_pic'], 'out-of-window ticket must be left alone');
    ai_test_assert_same('Budi', ai_test_fetch_pr($pdo, 'T061', '10')['assigned_pic']);
};

$tests['a malformed window is ignored rather than obeyed or fatal'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    foreach (['not-a-date', '2026-13-45', "2026-08-01' OR '1'='1", '01/08/2026', '2026-8-1'] as $bad) {
        ai_test_set_setting($pdo, AI_ASSIGN_MIN_PR_DATE_SETTING_KEY, $bad);
        ai_test_assert_same(null, ai_assign_min_pr_date($pdo), "'{$bad}' must not be accepted as a window");
    }

    // And the SQL builder refuses one directly, rather than interpolating it.
    $threw = false;
    try {
        ai_assign_exclusion_sql($pdo, "2026-08-01' OR '1'='1");
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    ai_test_assert_true($threw, 'exclusion SQL must refuse a non-date window');
};

// --- Assignment --------------------------------------------------------------

$tests['assignment writes the PIC, the AI flag and the master_data_id'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', 'Budi');
    ai_test_add_pr($pdo, 'T100', '10', ['item_description' => 'APAR 6KG']);

    $stub = new AiAssignGeminiStub(['T100_10' => 'FIRE EXTINGUISHER']);
    $result = ai_assign_process_batch($pdo, 5, $stub);

    ai_test_assert_same('processing', $result['status']);
    ai_test_assert_same(1, $result['successCount']);
    ai_test_assert_same(0, (int) $result['remaining']);

    $row = ai_test_fetch_pr($pdo, 'T100', '10');
    ai_test_assert_same('Budi', $row['assigned_pic']);
    ai_test_assert_same(1, (int) $row['assigned_by_ai']);
    ai_test_assert_same(1, (int) $row['master_data_id']);
};

$tests['assignment never overwrites a PIC that is already set'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', 'Budi');
    // One ticket, two lines: line 10 was system-routed at import time
    // (AON/Intercompany, assigned_by_ai = 0) and line 20 is still open. The
    // ticket is therefore in the queue and BOTH lines are handed to the AI --
    // the per-line `assigned_pic IS NULL` guard is what protects line 10.
    ai_test_add_pr($pdo, 'T101', '10', ['assigned_pic' => 'Manual Choice', 'assigned_by_ai' => 0]);
    ai_test_add_pr($pdo, 'T101', '20');

    $stub = new AiAssignGeminiStub([
        'T101_10' => 'FIRE EXTINGUISHER',
        'T101_20' => 'FIRE EXTINGUISHER',
    ]);
    $result = ai_assign_process_batch($pdo, 5, $stub);

    ai_test_assert_same(1, $stub->calls);
    ai_test_assert_same(1, $result['successCount']);

    $protected = ai_test_fetch_pr($pdo, 'T101', '10');
    ai_test_assert_same('Manual Choice', $protected['assigned_pic'], 'a hand-set PIC must survive');
    ai_test_assert_same(0, (int) $protected['assigned_by_ai']);

    $assigned = ai_test_fetch_pr($pdo, 'T101', '20');
    ai_test_assert_same('Budi', $assigned['assigned_pic']);
    ai_test_assert_same(1, (int) $assigned['assigned_by_ai']);
};

$tests['an empty queue makes no API call at all'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    $stub = new AiAssignGeminiStub([]);
    $result = ai_assign_process_batch($pdo, 5, $stub);

    ai_test_assert_same('complete', $result['status']);
    ai_test_assert_same(0, (int) $result['remaining']);
    ai_test_assert_same(0, $stub->calls);
};

$tests['batch size caps how many tickets one call covers'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', 'Budi');
    $mapping = [];
    for ($i = 1; $i <= 7; $i++) {
        $ticket = sprintf('T2%02d', $i);
        ai_test_add_pr($pdo, $ticket, '10');
        $mapping[$ticket . '_10'] = 'FIRE EXTINGUISHER';
    }

    $stub = new AiAssignGeminiStub($mapping);
    $result = ai_assign_process_batch($pdo, 3, $stub);

    ai_test_assert_same(1, $stub->calls);
    ai_test_assert_same(3, $result['successCount']);
    ai_test_assert_same(4, (int) $result['remaining']);
};

$tests['a PIC name is trimmed before it is written'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    // Real master_data has ~27 rows whose pic_names carry trailing spaces.
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', 'Budi Santoso  ');
    ai_test_add_pr($pdo, 'T310', '10');

    $stub = new AiAssignGeminiStub(['T310_10' => 'FIRE EXTINGUISHER']);
    ai_assign_process_batch($pdo, 5, $stub);

    // LENGTH(), not <> TRIM(): utf8mb4 is PAD SPACE, so "Budi Santoso" =
    // "Budi Santoso  " is TRUE and a TRIM comparison cannot see the difference.
    $stmt = $pdo->prepare('SELECT assigned_pic, LENGTH(assigned_pic) len FROM prs
                           WHERE ticket_no = ? AND ticket_line_item = ?');
    $stmt->execute(['T310', '10']);
    $row = $stmt->fetch();

    ai_test_assert_same('Budi Santoso', $row['assigned_pic']);
    ai_test_assert_same(12, (int) $row['len'], 'trailing spaces must not reach prs.assigned_pic');
};

$tests['a multi-PIC category still trims whichever name is picked'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', ' Budi Santoso ,  Siti Rahayu  ');
    // Several tickets so the random pick lands on both names across the run.
    for ($i = 1; $i <= 6; $i++) {
        ai_test_add_pr($pdo, sprintf('T32%d', $i), '10');
    }

    $stub = new AiAssignGeminiStub([], 'FIRE EXTINGUISHER');
    ai_assign_process_batch($pdo, 6, $stub);

    $rows = $pdo->query('SELECT DISTINCT assigned_pic, LENGTH(assigned_pic) len FROM prs
                         WHERE assigned_pic IS NOT NULL')->fetchAll();
    ai_test_assert_true($rows !== [], 'expected at least one assignment');
    foreach ($rows as $row) {
        ai_test_assert_same(
            strlen(trim($row['assigned_pic'])),
            (int) $row['len'],
            "PIC '{$row['assigned_pic']}' reached prs untrimmed"
        );
    }
};

$tests['an unresolvable category is reported, not written'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', 'Budi');
    ai_test_add_pr($pdo, 'T300', '10', ['item_description' => 'SOMETHING UNMAPPABLE']);

    // Deliberately nothing like the one category on file, so every rung of the
    // resolution ladder (exact -> substring -> 70% similar_text) misses.
    $stub = new AiAssignGeminiStub(['T300_10' => 'ZZZZZZZZ']);
    $result = ai_assign_process_batch($pdo, 5, $stub);

    ai_test_assert_same(0, $result['successCount']);
    ai_test_assert_same(1, count($result['failedDetails']));
    ai_test_assert_same(null, ai_test_fetch_pr($pdo, 'T300', '10')['assigned_pic']);
};

// --- Master Data enrichment: recurring item, reworded proposal ---------------
//
// Regression cover for the production bug this fix addresses: a recurring
// item that never confidently matches an approved category used to mint a
// brand-new Master Data row on every batch that re-encountered it, because
// the AI reworded new_level_5 differently each run and the only duplicate
// guard was the exact (level_4, level_5) unique key. requested_item_name is
// a plain copy of item_description, untouched by the AI, so it stays stable
// across those reworded repeats -- md_enrich_master_data() now checks it
// first and reuses the existing row instead of minting a sibling.

$tests['a recurring item reworded across runs does not mint a duplicate Master Data row'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_add_master_data($pdo, 1, 'UNRELATED CATEGORY', 'Budi');
    $itemDescription = '[99999] RECURRING WIDGET MODEL X';

    // Run 1: the item appears on T900, below the 95% enrichment threshold.
    ai_test_add_pr($pdo, 'T900', '10', ['item_description' => $itemDescription, 'pr_date' => '2026-08-01']);
    $stub1 = new AiEnrichmentGeminiStub([
        'T900_10' => ['category' => 'UNRELATED CATEGORY', 'confidence' => 40,
                       'new_level_4' => 'WIDGET', 'new_level_5' => 'WIDGET MODEL X; VARIANT ONE'],
    ]);
    ai_assign_process_batch($pdo, 5, $stub1);

    $countAfterRun1 = (int) $pdo->query(
        'SELECT COUNT(*) FROM master_data WHERE requested_item_name = ' . $pdo->quote($itemDescription)
    )->fetchColumn();
    ai_test_assert_same(1, $countAfterRun1, 'first run must create exactly one pending row');

    // Run 2, days later: the same item recurs on a different ticket. The AI
    // words new_level_5 differently -- as it does for real -- so the unique
    // key on (level_4, level_5) alone would not have caught this.
    ai_test_add_pr($pdo, 'T901', '10', ['item_description' => $itemDescription, 'pr_date' => '2026-08-05']);
    $stub2 = new AiEnrichmentGeminiStub([
        'T901_10' => ['category' => 'UNRELATED CATEGORY', 'confidence' => 40,
                       'new_level_4' => 'WIDGET', 'new_level_5' => 'WIDGET MODEL X VARIANT ONE (DIFFERENT WORDING)'],
    ]);
    ai_assign_process_batch($pdo, 5, $stub2);

    $countAfterRun2 = (int) $pdo->query(
        'SELECT COUNT(*) FROM master_data WHERE requested_item_name = ' . $pdo->quote($itemDescription)
    )->fetchColumn();
    ai_test_assert_same(1, $countAfterRun2, 'second run must reuse the first pending row, not mint a sibling');

    $ticketIds = (string) $pdo->query(
        'SELECT ticket_ids FROM master_data WHERE requested_item_name = ' . $pdo->quote($itemDescription)
    )->fetchColumn();
    ai_test_assert_true(str_contains($ticketIds, 'T900'), 'the original ticket must still be on the row');
    ai_test_assert_true(str_contains($ticketIds, 'T901'), 'the second run\'s ticket must be appended to the same row');
};

// --- Pending: taxonomy counts as unfinished work too -------------------------
//
// Regression cover for the "Unmapped" dashboard bug: eligibility used to be
// `assigned_pic IS NULL` alone, so a line that import-time routing had stamped a
// PIC on could never gain a taxonomy, and master_data_id was only written inside
// the PIC branch. See ai_assign_pending_predicate().

$tests['a line with a PIC but no taxonomy is still pending'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', 'Budi');
    // Exactly what excel_parser.php leaves behind: system-routed PIC, no taxonomy.
    ai_test_add_pr($pdo, 'T400', '10', [
        'assigned_pic'   => 'System Routed',
        'assigned_by_ai' => 0,
        'master_data_id' => null,
        'item_description' => 'APAR 6KG',
    ]);

    ai_test_assert_same(1, ai_assign_queue_count($pdo), 'the queue must see it');

    $stub = new AiAssignGeminiStub(['T400_10' => 'FIRE EXTINGUISHER']);
    $result = ai_assign_process_batch($pdo, 5, $stub);

    $row = ai_test_fetch_pr($pdo, 'T400', '10');
    ai_test_assert_same(1, (int) $row['master_data_id'], 'taxonomy must be filled in');
    ai_test_assert_same('System Routed', $row['assigned_pic'], 'the routed PIC must survive');
    ai_test_assert_same(0, (int) $row['assigned_by_ai'], 'and must not be re-flagged as AI-assigned');
    ai_test_assert_same(0, (int) $result['remaining'], 'the queue must drain, not loop');
};

// The one that keeps the worker alive: successCount feeds its zero-progress
// detector, which aborts a run after two batches that report nothing. A batch
// that only filled in taxonomy has to count, or the worker stops and re-sends
// the same tickets to Gemini on every future run.
$tests['a taxonomy-only batch still reports progress'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', 'Budi');
    ai_test_add_pr($pdo, 'T401', '10', [
        'assigned_pic'   => 'System Routed',
        'assigned_by_ai' => 0,
        'item_description' => 'APAR 6KG',
    ]);

    $stub = new AiAssignGeminiStub(['T401_10' => 'FIRE EXTINGUISHER']);
    $result = ai_assign_process_batch($pdo, 5, $stub);

    ai_test_assert_same(1, $result['successCount'], 'a taxonomy-only write is progress');
};

$tests['a hand-set taxonomy is never overwritten'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', 'Budi');
    ai_test_add_master_data($pdo, 2, 'SAFETY HELMET', 'Sari');
    // A Category Manager already corrected this line by hand via
    // ajax_update_pr_taxonomy.php; the AI disagreeing must not undo that.
    ai_test_add_pr($pdo, 'T402', '10', ['master_data_id' => 2, 'item_description' => 'APAR 6KG']);

    $stub = new AiAssignGeminiStub(['T402_10' => 'FIRE EXTINGUISHER']);
    ai_assign_process_batch($pdo, 5, $stub);

    $row = ai_test_fetch_pr($pdo, 'T402', '10');
    ai_test_assert_same(2, (int) $row['master_data_id'], 'the human mapping wins');
    ai_test_assert_same('Budi', $row['assigned_pic'], 'the missing PIC is still filled in');
};

$tests['taxonomy is written even when no PIC resolves'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    // pic_names NULL: the category exists and maps cleanly, but there is nobody
    // to route to. The taxonomy is still a good fact about the line.
    $pdo->prepare("INSERT INTO master_data (id, level_0, level_1, level_2, level_3, level_4, pic_names, status)
                    VALUES (1, 'L0', 'L1', 'L2', 'L3', 'FIRE EXTINGUISHER', NULL, 'active')")
        ->execute();
    ai_test_add_pr($pdo, 'T403', '10', ['item_description' => 'APAR 6KG']);

    $stub = new AiAssignGeminiStub(['T403_10' => 'FIRE EXTINGUISHER']);
    $result = ai_assign_process_batch($pdo, 5, $stub);

    $row = ai_test_fetch_pr($pdo, 'T403', '10');
    ai_test_assert_same(1, (int) $row['master_data_id'], 'taxonomy survives a PIC failure');
    ai_test_assert_same(null, $row['assigned_pic'], 'and the PIC is honestly left unset');
    ai_test_assert_same(1, count($result['failedDetails']), 'the PIC failure is still reported');
};

$tests['a fully assigned line is not pending'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', 'Budi');
    ai_test_add_pr($pdo, 'T404', '10', [
        'assigned_pic'   => 'Budi',
        'assigned_by_ai' => 1,
        'master_data_id' => 1,
    ]);

    ai_test_assert_same(0, ai_assign_queue_count($pdo), 'nothing left to do');

    $stub = new AiAssignGeminiStub([]);
    $result = ai_assign_process_batch($pdo, 5, $stub);
    ai_test_assert_same('complete', $result['status']);
    ai_test_assert_same(0, $stub->calls, 'and no API call is worth making');
};

// master_data_id = 0 is the other shape of "no taxonomy" -- older rows carry it
// instead of NULL, and the dashboard renders both as Unmapped.
$tests['a zero master_data_id counts as pending'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', 'Budi');
    ai_test_add_pr($pdo, 'T405', '10', [
        'assigned_pic'   => 'System Routed',
        'assigned_by_ai' => 0,
        'master_data_id' => 0,
        'item_description' => 'APAR 6KG',
    ]);

    ai_test_assert_same(1, ai_assign_queue_count($pdo));

    $stub = new AiAssignGeminiStub(['T405_10' => 'FIRE EXTINGUISHER']);
    ai_assign_process_batch($pdo, 5, $stub);

    ai_test_assert_same(1, (int) ai_test_fetch_pr($pdo, 'T405', '10')['master_data_id']);
};

// --- Worker phase ------------------------------------------------------------

$tests['worker makes zero API calls while the kill switch is OFF'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', 'Budi');
    ai_test_add_pr($pdo, 'T400', '10');

    $stub = new AiAssignGeminiStub(['T400_10' => 'FIRE EXTINGUISHER']);
    $summary = k2_worker_run_ai_assignment($pdo, ai_test_logger(), $stub);

    ai_test_assert_same(false, $summary['ran']);
    ai_test_assert_same(0, $stub->calls);
    ai_test_assert_same(null, ai_test_fetch_pr($pdo, 'T400', '10')['assigned_pic']);
};

$tests['worker drains the queue once the kill switch is ON'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_set_switch($pdo, '1');
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', 'Budi');
    $mapping = [];
    for ($i = 1; $i <= 7; $i++) {
        $ticket = sprintf('T5%02d', $i);
        ai_test_add_pr($pdo, $ticket, '10');
        $mapping[$ticket . '_10'] = 'FIRE EXTINGUISHER';
    }

    $stub = new AiAssignGeminiStub($mapping);
    $summary = ai_test_with_env(
        ['AI_ASSIGN_TICKETS_PER_BATCH' => '3', 'AI_ASSIGN_SLEEP_MS_BETWEEN_BATCHES' => '0'],
        fn(): array => k2_worker_run_ai_assignment($pdo, ai_test_logger(), $stub)
    );

    ai_test_assert_same(true, $summary['ran']);
    ai_test_assert_same(7, $summary['tickets_assigned']);
    ai_test_assert_same(0, $summary['errors']);
    ai_test_assert_same(0, ai_assign_queue_count($pdo));
};

$tests['worker stops at the per-run batch cap and leaves the rest'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_set_switch($pdo, '1');
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', 'Budi');
    $mapping = [];
    for ($i = 1; $i <= 10; $i++) {
        $ticket = sprintf('T6%02d', $i);
        ai_test_add_pr($pdo, $ticket, '10');
        $mapping[$ticket . '_10'] = 'FIRE EXTINGUISHER';
    }

    $stub = new AiAssignGeminiStub($mapping);
    $summary = ai_test_with_env(
        [
            'AI_ASSIGN_MAX_BATCHES_PER_RUN' => '2',
            'AI_ASSIGN_TICKETS_PER_BATCH' => '3',
            'AI_ASSIGN_SLEEP_MS_BETWEEN_BATCHES' => '0',
        ],
        fn(): array => k2_worker_run_ai_assignment($pdo, ai_test_logger(), $stub)
    );

    ai_test_assert_same(2, $summary['batches']);
    ai_test_assert_same(6, $summary['tickets_assigned']);
    ai_test_assert_same(2, $stub->calls);
    // The remainder of the backlog is deliberately left for the next run.
    ai_test_assert_same(4, ai_assign_queue_count($pdo));
};

$tests['worker gives up after repeated API failures instead of looping'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_set_switch($pdo, '1');
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', 'Budi');
    ai_test_add_pr($pdo, 'T700', '10');

    $stub = new AiAssignFailingStub();
    $summary = ai_test_with_env(
        ['AI_ASSIGN_SLEEP_MS_BETWEEN_BATCHES' => '0'],
        fn(): array => k2_worker_run_ai_assignment($pdo, ai_test_logger(), $stub)
    );

    // Three consecutive failures aborts, well short of the 20-batch cap.
    ai_test_assert_same(3, $stub->calls);
    ai_test_assert_same(3, $summary['errors']);
    ai_test_assert_same(0, $summary['tickets_assigned']);
};

$tests['worker stops when batches stop making progress'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_set_switch($pdo, '1');
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', 'Budi');
    for ($i = 1; $i <= 5; $i++) {
        ai_test_add_pr($pdo, sprintf('T8%02d', $i), '10');
    }

    // Every answer is unresolvable, so nothing is ever assigned and the next
    // batch would re-select the same tickets. Without the no-progress guard this
    // would burn the full 20-batch cap on tickets it can never place.
    $stub = new AiAssignGeminiStub([], 'ZZZZZZZZ');
    $summary = ai_test_with_env(
        ['AI_ASSIGN_SLEEP_MS_BETWEEN_BATCHES' => '0'],
        fn(): array => k2_worker_run_ai_assignment($pdo, ai_test_logger(), $stub)
    );

    ai_test_assert_same(2, $summary['batches']);
    ai_test_assert_same(2, $stub->calls);
    ai_test_assert_same(0, $summary['tickets_assigned']);
};

$tests['worker skips when another run already holds the lock'] = function () use ($pdo, $dsn, $user, $password, $pdoOptions): void {
    ai_test_reset($pdo);
    ai_test_set_switch($pdo, '1');
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', 'Budi');
    ai_test_add_pr($pdo, 'T900', '10');

    // A second connection stands in for an overlapping cron tick.
    $other = new PDO($dsn, $user, $password, $pdoOptions);
    $other->query("SELECT GET_LOCK('" . K2_WORKER_AI_ASSIGN_LOCK_NAME . "', 0)")->fetchColumn();

    $stub = new AiAssignGeminiStub(['T900_10' => 'FIRE EXTINGUISHER']);
    try {
        $summary = k2_worker_run_ai_assignment($pdo, ai_test_logger(), $stub);
    } finally {
        $other->query("SELECT RELEASE_LOCK('" . K2_WORKER_AI_ASSIGN_LOCK_NAME . "')")->fetchColumn();
    }

    ai_test_assert_same(false, $summary['ran']);
    ai_test_assert_same(0, $stub->calls);
    ai_test_assert_same(null, ai_test_fetch_pr($pdo, 'T900', '10')['assigned_pic']);
};

// --- Caps --------------------------------------------------------------------

$tests['default caps clear a peak production day without any env config'] = function (): void {
    // Production's cron is registered through a control panel and passes NO
    // environment, so these defaults are what actually runs. Measured inflow is a
    // mean of 38 AI-eligible tickets per working day, peaking over 80, across 3
    // import slots -- so one run must be able to clear a peak day on its own or
    // the queue grows forever. Change these numbers only with fresh measurements.
    $config = ai_test_with_env([
        'AI_ASSIGN_MAX_BATCHES_PER_RUN' => '',
        'AI_ASSIGN_TICKETS_PER_BATCH' => '',
        'AI_ASSIGN_MAX_SECONDS_PER_RUN' => '',
        'AI_ASSIGN_SLEEP_MS_BETWEEN_BATCHES' => '',
    ], fn(): array => k2_worker_ai_assign_config());

    ai_test_assert_same(15, $config['ticketsPerBatch']);
    ai_test_assert_same(600, $config['maxSeconds']);
    ai_test_assert_same(20, $config['maxBatches']);

    // ~98s per call measured against the real API; the time cap is what binds.
    $batchesPerRun = (int) floor($config['maxSeconds'] / 98);
    $ticketsPerRun = min($batchesPerRun, $config['maxBatches']) * $config['ticketsPerBatch'];
    ai_test_assert_true(
        $ticketsPerRun >= 82,
        "one run must clear a peak day (82 tickets); capacity is {$ticketsPerRun}"
    );
};

$tests['caps fall back to defaults when env values are junk or out of range'] = function (): void {
    $config = ai_test_with_env([
        'AI_ASSIGN_TICKETS_PER_BATCH' => '0',
        'AI_ASSIGN_MAX_SECONDS_PER_RUN' => '-5',
        'AI_ASSIGN_MAX_BATCHES_PER_RUN' => 'not-a-number',
    ], fn(): array => k2_worker_ai_assign_config());

    ai_test_assert_same(15, $config['ticketsPerBatch'], 'zero must not disable batching');
    ai_test_assert_same(600, $config['maxSeconds'], 'negative must not become an instant timeout');
    ai_test_assert_same(20, $config['maxBatches'], 'junk must not become 0 batches');
};

// --- The seam: which claim outcomes chain into AI assignment -----------------

$tests['AI assignment is chained only after a PR import that succeeded'] = function (): void {
    // The one case that must trigger it.
    ai_test_assert_same(true, k2_worker_should_run_ai_assignment(
        K2WorkerOutcome::SUCCESS, K2ImportOperationType::PR
    ), 'a successful PR import is the only thing that adds to the assignment queue');

    // An AON-only run cannot have created unassigned PR lines.
    ai_test_assert_same(false, k2_worker_should_run_ai_assignment(
        K2WorkerOutcome::SUCCESS, K2ImportOperationType::AON
    ), 'AON success must not trigger assignment');

    // A failed PR rolled its business write back: the queue is unchanged, and a
    // retry is still pending. Assigning here would spend credits for nothing.
    foreach ([K2WorkerOutcome::RETRYABLE_FAILURE, K2WorkerOutcome::FINAL_FAILURE, K2WorkerOutcome::INTERNAL_ERROR] as $outcome) {
        ai_test_assert_same(false, k2_worker_should_run_ai_assignment(
            $outcome, K2ImportOperationType::PR
        ), "PR outcome {$outcome} must not trigger assignment");
    }

    // Idle tick: nothing was claimed at all.
    ai_test_assert_same(false, k2_worker_should_run_ai_assignment(
        K2WorkerOutcome::NOTHING_CLAIMABLE, null
    ), 'an idle worker tick must not trigger assignment');
};

$tests['worker records the last run timestamp'] = function () use ($pdo): void {
    ai_test_reset($pdo);
    ai_test_set_switch($pdo, '1');
    ai_test_add_master_data($pdo, 1, 'FIRE EXTINGUISHER', 'Budi');
    ai_test_add_pr($pdo, 'TA00', '10');

    $stub = new AiAssignGeminiStub(['TA00_10' => 'FIRE EXTINGUISHER']);
    ai_test_with_env(
        ['AI_ASSIGN_SLEEP_MS_BETWEEN_BATCHES' => '0'],
        fn(): array => k2_worker_run_ai_assignment($pdo, ai_test_logger(), $stub)
    );

    $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $stmt->execute([AI_ASSIGN_LAST_RUN_SETTING_KEY]);
    ai_test_assert_true($stmt->fetchColumn() !== false, 'last_ai_auto_assign_at should have been written');
};

// --- Runner ------------------------------------------------------------------

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS  {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL  {$name}\n      " . $e->getMessage() . "\n";
    }
}

echo "\n" . count($tests) . " test(s), {$failures} failure(s)\n";
exit($failures > 0 ? 1 : 0);

// --- Helpers -----------------------------------------------------------------

function ai_test_logger(): K2AutomationDiagnosticsLogger
{
    // Writes to a temp path; these assertions are about behaviour, not log output.
    return new K2AutomationDiagnosticsLogger(
        sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ai_assign_test_diagnostics.log'
    );
}

/** Runs $callback with the given env vars set, restoring them afterwards. */
function ai_test_with_env(array $vars, callable $callback): mixed
{
    foreach ($vars as $name => $value) {
        putenv("{$name}={$value}");
    }
    try {
        return $callback();
    } finally {
        foreach (array_keys($vars) as $name) {
            putenv((string) $name);
        }
    }
}

function ai_test_set_switch(PDO $pdo, string $value): void
{
    ai_test_set_setting($pdo, AI_ASSIGN_ENABLED_SETTING_KEY, $value);
}

function ai_test_set_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([$key, $value]);
}

/**
 * pr_date defaults to 2026-07-01: inside the AI queue window (>= 2026-05-01) but
 * BEFORE the Master Data enrichment cutoff (2026-07-31), so md_should_enrich()
 * stays false and these tests never reach md_enrich_master_data(). Enrichment is
 * pre-existing behaviour with its own schema needs and is not what is under test
 * here.
 */
function ai_test_add_pr(PDO $pdo, string $ticket, string $line, array $overrides = []): void
{
    $row = array_merge([
        'ticket_no' => $ticket,
        'ticket_line_item' => $line,
        'p_group' => 'P001',
        'pr_date' => '2026-07-01',
        'item_description' => 'TEST ITEM',
        'status_ticket' => 'Outstanding',
        'ticket_type' => 'Normal',
        'assigned_pic' => null,
        'assigned_by_ai' => null,
        'master_data_id' => null,
    ], $overrides);

    $pdo->prepare('INSERT INTO prs (ticket_no, ticket_line_item, p_group, pr_date, item_description,
                                    status_ticket, ticket_type, assigned_pic, assigned_by_ai, master_data_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(array_values($row));
}

function ai_test_add_master_data(PDO $pdo, int $id, string $level4, string $pic): void
{
    $pdo->prepare("INSERT INTO master_data (id, level_0, level_1, level_2, level_3, level_4, pic_names, status)
                    VALUES (?, 'L0', 'L1', 'L2', 'L3', ?, ?, 'active')")
        ->execute([$id, $level4, $pic]);
}

function ai_test_fetch_pr(PDO $pdo, string $ticket, string $line): array
{
    $stmt = $pdo->prepare('SELECT * FROM prs WHERE ticket_no = ? AND ticket_line_item = ?');
    $stmt->execute([$ticket, $line]);

    return $stmt->fetch() ?: [];
}

function ai_test_reset(PDO $pdo): void
{
    $pdo->exec('DELETE FROM prs');
    $pdo->exec('DELETE FROM master_data_history');
    $pdo->exec('DELETE FROM master_data');
    $pdo->exec('DELETE FROM ai_golden_rules');
    $pdo->exec('DELETE FROM system_settings');
    $pdo->exec('DELETE FROM project_pgroups');
}

function ai_test_assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message !== '' ? $message . ' -- ' : '')
            . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function ai_test_assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function ai_test_create_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(50) NOT NULL PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS prs (
        ticket_no VARCHAR(20) NOT NULL,
        ticket_line_item VARCHAR(10) NOT NULL,
        p_group VARCHAR(50) NULL,
        pr_date DATE NULL,
        item_description VARCHAR(255) NULL,
        status_ticket VARCHAR(100) NULL,
        ticket_type VARCHAR(100) NULL,
        assigned_pic VARCHAR(100) NULL,
        assigned_by_ai TINYINT NULL,
        master_data_id INT NULL,
        PRIMARY KEY (ticket_no, ticket_line_item)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // AUTO_INCREMENT (an explicit id, as every existing caller passes, still
    // works alongside it) and the extra nullable columns + the unique key on
    // (level_4, level_5) are here so md_enrich_master_data() -- exercised by
    // the enrichment tests below -- runs against the same shape it does in
    // production, not a stand-in that silently swallows its INSERT.
    $pdo->exec('CREATE TABLE IF NOT EXISTS master_data (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        level_0 VARCHAR(150) NULL,
        level_1 VARCHAR(150) NULL,
        level_2 VARCHAR(150) NULL,
        level_3 VARCHAR(150) NULL,
        level_4 VARCHAR(150) NULL,
        level_5 VARCHAR(255) NULL,
        pic_names VARCHAR(255) NULL,
        suggested_pic_names VARCHAR(255) NULL,
        suggested_by VARCHAR(255) NULL,
        status VARCHAR(20) NULL,
        ai_confidence INT NULL,
        requested_item_name VARCHAR(255) NULL,
        ticket_ids TEXT NULL,
        created_by VARCHAR(255) NULL,
        last_modified_by VARCHAR(255) NULL,
        UNIQUE KEY unique_level4_5 (level_4, level_5)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Minimal stand-in for the audit trail md_audit_log_insert() writes to --
    // present so a successful enrichment insert doesn't error-log against a
    // missing table; no test here asserts against it.
    $pdo->exec('CREATE TABLE IF NOT EXISTS master_data_history (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        master_data_id INT NOT NULL,
        action VARCHAR(10) NULL,
        level_0 VARCHAR(150) NULL,
        level_1 VARCHAR(150) NULL,
        level_2 VARCHAR(150) NULL,
        level_3 VARCHAR(150) NULL,
        level_4 VARCHAR(255) NULL,
        level_5 VARCHAR(255) NULL,
        pic_names VARCHAR(255) NULL,
        created_by VARCHAR(255) NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_golden_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rule_text TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Present so the project-exclusion branch is the one under test. When this
    // table is absent the engine logs and falls back to no exclusion, which is
    // the pre-existing behaviour on an un-migrated database.
    $pdo->exec('CREATE TABLE IF NOT EXISTS project_pgroups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        p_group VARCHAR(50) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT \'ACTIVE\'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
