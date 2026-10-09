<?php
// workspace/includes/ai_ticket_assigner.php
//
// Shared AI ticket-assignment engine.
//
// Extracted from workspace/pages/ajax_process_batch.php so the SAME code can be
// driven by two callers that must behave identically:
//
//   1. workspace/pages/auto_assign.php  -> ajax_process_batch.php  (browser, a
//      human watching a progress bar; the historical and still-supported path)
//   2. workspace/cron/process_k2_import_queue.php (the K2 parser worker, which
//      chains a run after each successful PR import)
//
// The extraction was deliberately behaviour-preserving: the prompt text, the
// level_4/3/2 -> substring -> 70% similar_text resolution ladder, the Master
// Data enrichment gate, the comma-PIC random pick and the per-line
// `assigned_pic IS NULL` PIC write guard are all exactly as they were. Do not
// "improve" them here without re-testing the browser path -- both callers share
// the consequences now.
//
// The one deliberate behaviour change since: which lines count as pending, and
// the fact that taxonomy is now written independently of the PIC. See
// ai_assign_pending_predicate() and the write block in ai_assign_process_batch().
//
// PURE LIBRARY: no output, no session, no side effects on require. Safe under
// both a web SAPI and CLI.

require_once __DIR__ . '/master_data_enrichment.php';
require_once __DIR__ . '/pr_audit_stamp.php'; // prs.last_updated_by / last_updated_date

/** system_settings key: '1' means the K2 worker may run AI assignment for real. */
if (!defined('AI_ASSIGN_ENABLED_SETTING_KEY')) { define('AI_ASSIGN_ENABLED_SETTING_KEY', 'ai_auto_assign_enabled'); }
/** system_settings key: Jakarta timestamp of the last worker-driven run. Mirrors last_pr_upload_at. */
if (!defined('AI_ASSIGN_LAST_RUN_SETTING_KEY')) { define('AI_ASSIGN_LAST_RUN_SETTING_KEY', 'last_ai_auto_assign_at'); }
/** system_settings key: optional 'Y-m-d' floor on pr_date for WORKER-driven assignment only. */
if (!defined('AI_ASSIGN_MIN_PR_DATE_SETTING_KEY')) { define('AI_ASSIGN_MIN_PR_DATE_SETTING_KEY', 'ai_auto_assign_min_pr_date'); }

/**
 * Whether the K2 worker is allowed to run AI assignment for real.
 *
 * FAIL-CLOSED: a missing row, a missing table, or any read failure returns
 * false. This gate exists so merged code is inert in production until someone
 * deliberately flips it (see tools/toggle_ai_auto_assign.php) -- a DB hiccup
 * must never be read as "go ahead and spend Gemini credits".
 *
 * Only the cron path consults this. The browser path (auto_assign.php) is an
 * explicit human action and is intentionally NOT gated by it.
 */
function ai_assign_live_enabled(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $stmt->execute([AI_ASSIGN_ENABLED_SETTING_KEY]);
        $val = $stmt->fetchColumn();

        return $val !== false && trim((string)$val) === '1';
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Optional floor on `pr_date` for WORKER-driven assignment, as 'Y-m-d', or null
 * for no extra narrowing.
 *
 * Why this exists: the shared eligibility filter's own cutoff is a hardcoded
 * 2026-05-01, so the queue can contain months of genuinely-still-Outstanding but
 * practically-dead tickets. Turning automation on without a floor dumps all of
 * them onto PICs' dashboards at once. This lets an operator open the window
 * deliberately -- start narrow, widen later -- without a deploy.
 *
 * Deliberately NOT applied to the browser dispatcher (auto_assign.php), which
 * passes null: a human clearing old work on purpose is exactly the escape hatch
 * this leaves open.
 *
 * Fail-open to "no narrowing" (the historical behaviour) on any read failure --
 * safe because a broken settings read also fails the kill switch closed, so
 * nothing gets assigned at all in that case.
 */
function ai_assign_min_pr_date(PDO $pdo): ?string
{
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $stmt->execute([AI_ASSIGN_MIN_PR_DATE_SETTING_KEY]);
        $raw = $stmt->fetchColumn();
    } catch (\Throwable $e) {
        return null;
    }

    if ($raw === false) {
        return null;
    }
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }

    // Strict Y-m-d only. This value is interpolated into SQL below (it cannot be
    // bound -- the filter is assembled as a SQL fragment reused across several
    // statements), so anything that is not exactly a calendar date is refused
    // rather than escaped.
    $parsed = DateTime::createFromFormat('Y-m-d', $raw);
    if ($parsed === false || $parsed->format('Y-m-d') !== $raw) {
        error_log('ai_auto_assign_min_pr_date is not a valid Y-m-d date; ignoring it.');
        return null;
    }

    return $raw;
}

/** Records when the worker last completed an assignment run. Never throws. */
function ai_assign_touch_last_run(PDO $pdo, ?string $jakartaTimestamp = null): void
{
    try {
        // Same idiom excel_parser.php uses for last_pr_upload_at.
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([
                AI_ASSIGN_LAST_RUN_SETTING_KEY,
                $jakartaTimestamp ?? (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s'),
            ]);
    } catch (\Throwable $e) {
        // Bookkeeping only -- never break an otherwise successful run.
    }
}

function ai_assign_table_exists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$tableName]);
    return (bool)$stmt->fetchColumn();
}

/**
 * The "this PR line belongs to a Project p_group" exclusion, on its own.
 * Needed separately from the full filter below because the original code also
 * appends it to the per-line SELECT and to both UPDATE statements.
 */
function ai_assign_project_exclusion_sql(PDO $pdo): string
{
    if (ai_assign_table_exists($pdo, 'project_pgroups')) {
        return "AND NOT EXISTS (
            SELECT 1 FROM project_pgroups pp
            WHERE pp.p_group = prs.p_group
            AND pp.status = 'ACTIVE'
        )";
    }

    error_log('project_pgroups table not found; AI batch project exclusion disabled until migration is applied.');
    return "";
}

/**
 * What makes a line still "pending" for AI assignment.
 *
 * Historically this was `assigned_pic IS NULL` alone, which quietly meant a line
 * could never gain a taxonomy once anything had stamped a PIC on it. Import-time
 * system routing in excel_parser.php (AON SKU / Intercompany / GP Project) does
 * exactly that, and it never writes master_data_id -- so those lines sat with a
 * PIC and a NULL taxonomy, invisible to every batch, and rendered "Unmapped" in
 * the dashboard modal forever.
 *
 * A line is pending if EITHER half of the assignment is still missing. The two
 * writes stay independently guarded (see ai_assign_process_batch()), so picking
 * a ticket up for its missing taxonomy cannot disturb a PIC that is already set.
 *
 * Written as a bare predicate, not an `AND ...` fragment, because it is always
 * the first term after WHERE.
 */
function ai_assign_pending_predicate(): string
{
    return "(assigned_pic IS NULL OR master_data_id IS NULL OR master_data_id = 0)";
}

/**
 * The full "is this line eligible for AI assignment" filter, minus the
 * pending part (ai_assign_pending_predicate()).
 *
 * $minPrDate optionally raises the pr_date floor above the built-in 2026-05-01
 * (see ai_assign_min_pr_date()). It must already be validated as 'Y-m-d' --
 * ai_assign_min_pr_date() is the only intended source. Undated lines keep riding
 * along either way, matching the built-in cutoff's own behaviour.
 */
function ai_assign_exclusion_sql(PDO $pdo, ?string $minPrDate = null): string
{
    $project_exclusion_sql = ai_assign_project_exclusion_sql($pdo);

    $window_sql = '';
    if ($minPrDate !== null) {
        $parsed = DateTime::createFromFormat('Y-m-d', $minPrDate);
        if ($parsed === false || $parsed->format('Y-m-d') !== $minPrDate) {
            throw new InvalidArgumentException('ai_assign_exclusion_sql(): minimum pr_date must be a Y-m-d date.');
        }
        $window_sql = "AND (pr_date >= '{$minPrDate}' OR pr_date IS NULL)";
    }

    return "AND LOWER(status_ticket) NOT IN (
        'canceled', 'cancelled', 'cancelled ticket', 'canceled ticket',
        'rejected', 'done', 'closed', 'waiting for requestor',
        'draft', 'waiting for approval', 'waiting for buyer response', 'on review'
    )
    AND LOWER(IFNULL(ticket_type, '')) != 'low value opex'
    $project_exclusion_sql
    AND (pr_date >= '2026-05-01' OR pr_date IS NULL)
    $window_sql";
}

/** How many distinct tickets are currently waiting for AI assignment. */
function ai_assign_queue_count(PDO $pdo, ?string $minPrDate = null): int
{
    $exclusion_sql = ai_assign_exclusion_sql($pdo, $minPrDate);
    $pending_sql   = ai_assign_pending_predicate();

    return (int)$pdo->query("SELECT COUNT(DISTINCT ticket_no) FROM prs WHERE $pending_sql $exclusion_sql")
                    ->fetchColumn();
}

/**
 * Classifies and assigns ONE batch of unassigned tickets.
 *
 * Returns either
 *   ['status' => 'complete', 'remaining' => 0]                       (nothing to do)
 * or
 *   ['status' => 'processing', 'successCount' => int, 'fallbackDetails' => [],
 *    'failedDetails' => [], 'remaining' => int, 'estimated_tokens' => int]
 *
 * -- the exact shape ajax_process_batch.php has always returned, because
 * auto_assign.php's JavaScript reads these keys by name.
 *
 * Throws on API/JSON failure; callers decide how to report it (the endpoint
 * turns it into {status:error}, the worker logs it).
 *
 * $gemini exists only so tests can inject a stub; it defaults to the real
 * call_gemini_api(). config/gemini_api.php is required lazily, so merely
 * loading this library never pulls in the API key or makes a call.
 */
function ai_assign_process_batch(PDO $pdo, int $ticketLimit = 5, ?callable $gemini = null, ?string $minPrDate = null): array
{
    if ($ticketLimit < 1) {
        throw new InvalidArgumentException('ai_assign_process_batch(): ticket limit must be at least 1.');
    }

    if ($gemini === null) {
        require_once __DIR__ . '/../config/gemini_api.php';
        $gemini = 'call_gemini_api';
    }

    $project_exclusion_sql = ai_assign_project_exclusion_sql($pdo);
    $exclusion_sql = ai_assign_exclusion_sql($pdo, $minPrDate);
    $pending_sql   = ai_assign_pending_predicate();

    $countStmt = $pdo->query("SELECT COUNT(DISTINCT ticket_no) FROM prs WHERE $pending_sql $exclusion_sql");
    $total_remaining_tickets = $countStmt->fetchColumn();

    if ($total_remaining_tickets == 0) {
        return ['status' => 'complete', 'remaining' => 0];
    }

    // Fetch up to $ticketLimit unique pending Tickets (default 5, reduced
    // from 10 historically to avoid API/Gateway timeouts). "Pending" means
    // missing a PIC, a taxonomy, or both -- see ai_assign_pending_predicate().
    $stmt = $pdo->query("SELECT DISTINCT ticket_no FROM prs WHERE $pending_sql $exclusion_sql LIMIT " . (int)$ticketLimit);
    $tickets_to_process = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $inQuery = implode(',', array_fill(0, count($tickets_to_process), '?'));
    // pr_date rides along so Master Data enrichment can be limited to lines
    // dated from the cutoff forward -- see md_should_enrich().
    $stmt = $pdo->prepare("SELECT ticket_no, ticket_line_item, item_description, pr_date FROM prs WHERE ticket_no IN ($inQuery) $project_exclusion_sql");
    $stmt->execute($tickets_to_process);
    $unassigned_items = $stmt->fetchAll();

    // Fetch Master Data
    $stmt = $pdo->query("SELECT id, level_0, level_1, level_2, level_3, level_4, pic_names FROM master_data WHERE status IN ('active', 'registered')");
    $master_data = $stmt->fetchAll();

    $categoriesList = [];
    $level4_map = []; $level3_map = []; $level2_map = [];
    $taxonomy_tree = []; // To help the AI pick level_0 to level_3 on fallback
    // master_data.id => its full branch + PIC. Master Data enrichment copies the
    // branch of whichever row a line resolved to, and the match can land on a
    // level_2/level_3 row just as easily as a level_4 one -- so the lookup is
    // keyed by id rather than by category name.
    $branch_by_id = [];

    foreach ($master_data as $row) {
        if (!empty($row['level_4'])) $categoriesList[] = trim($row['level_4']);
        if (!empty($row['level_3'])) $categoriesList[] = trim($row['level_3']);
        if (!empty($row['level_2'])) $categoriesList[] = trim($row['level_2']);

        $l4 = strtolower(trim($row['level_4']));
        $l3 = strtolower(trim($row['level_3']));
        $l2 = strtolower(trim($row['level_2']));

        $level4_map[$l4] = ['pic' => $row['pic_names'], 'id' => $row['id']];
        if (!isset($level3_map[$l3]) && !empty($l3)) $level3_map[$l3] = ['pic' => $row['pic_names'], 'id' => $row['id']];
        if (!isset($level2_map[$l2]) && !empty($l2)) $level2_map[$l2] = ['pic' => $row['pic_names'], 'id' => $row['id']];

        // Build a mapping to retrieve higher levels easily if needed.
        // level_4 keeps its original casing and pic_names comes along too, so a
        // Master Data enrichment row can copy the whole branch of the closest
        // match in one lookup.
        $taxonomy_tree[$l4] = [
            'level_0' => $row['level_0'],
            'level_1' => $row['level_1'],
            'level_2' => $row['level_2'],
            'level_3' => $row['level_3'],
            'level_4' => $row['level_4'],
            'pic'     => $row['pic_names']
        ];
        $branch_by_id[(int)$row['id']] = $taxonomy_tree[$l4];
    }
    $categoriesList = array_unique($categoriesList);

    // Fetch Golden Rules
    $golden_rules = "";
    $grStmt = $pdo->query("SELECT rule_text FROM ai_golden_rules ORDER BY id ASC");
    $rules = $grStmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($rules)) {
        $golden_rules = "GOLDEN RULES:\n" . implode("\n", $rules) . "\n\n";
    }

    // Build Prompt
    $prDataString = "";
    foreach ($unassigned_items as $item) {
        $cleanDesc = str_replace(['"', '{', '}', "\n"], " ", $item['item_description']);
        $unique_id = $item['ticket_no'] . "_" . $item['ticket_line_item'];
        $prDataString .= "ID: " . $unique_id . " | Desc: " . $cleanDesc . "\n";
    }

    // new_level_4/new_level_5 are what turns a bad fit into a Master Data row
    // instead of a silent mis-route: when the item does not really belong to any
    // approved category, the AI still names the nearest one (so the line can be
    // routed today) but also proposes the category the item SHOULD have had,
    // written to the golden-rule format. md_enrich_master_data() creates that
    // category as a pending row for the Category Manager to approve.
    $min_match = md_enrichment_min_match();
    $prompt = "You are an expert procurement taxonomy classifier.
    Exact approved categories:\n" . implode(", ", $categoriesList) . "\n\n
    " . $golden_rules . "
    List of PR Line Items:\n" . $prDataString . "\n\n
    Task: Map each line item to the single most accurate category and provide a confidence level (0-100).
    CRITICAL RULES:
    1. Output ONLY a valid JSON object. Do NOT add markdown, greetings, or conversational text.
    2. Format: {\"ID\": {\"category\": \"CategoryName\", \"confidence\": 95, \"new_level_4\": \"\", \"new_level_5\": \"\"}}
    3. SMART MAPPING: If exact specific item is missing, pick the closest broad category.
    4. NEW CATEGORY: when the item does not genuinely belong to ANY approved category, keep the closest one in \"category\" with a confidence below {$min_match}, and additionally propose:
       - \"new_level_4\": the category this item should have. PRODUCT TYPE ONLY (GOLDEN RULE 3) - no brands, models, sizes, capacities, colors or specs. Prefix \"JASA \" for services (GOLDEN RULE III). UPPERCASE.
       - \"new_level_5\": the SKU detail description for this item, Scenario A - the new_level_4 noun and its modifiers MERGED into the first segment, then each critical technical attribute as its own segment, separated by \"; \". ALL CAPITALS. No commas, no dashes, no parentheses (GOLDEN RULE 2 and 3).
       Leave BOTH empty when the item does belong to an approved category.
    5. " . md_gr_nma_instruction() . "
    Example: {\"T123_10\": {\"category\": \"Server/Storage\", \"confidence\": 80, \"new_level_4\": \"\", \"new_level_5\": \"\"}, \"T123_20\": {\"category\": \"32721 - FIRE EXTINGUISHER\", \"confidence\": 95, \"new_level_4\": \"\", \"new_level_5\": \"\"}, \"T123_30\": {\"category\": \"Peripheral\", \"confidence\": 45, \"new_level_4\": \"PRINTER DOT MATRIX\", \"new_level_5\": \"PRINTER DOT MATRIX INDUSTRIAL THERMAL TRANSFER; 4 INCH; 203 DPI\"}}";

    // Token Estimation (Roughly 4 characters per token for Gemini)
    $estimated_tokens = ceil(strlen($prompt) / 4);

    // API Call
    $jsonResponse = $gemini($prompt, 3, gemini_key_ticket_assign());

    // --- DATABASE KEEP-ALIVE PING (PREVENT ERROR 2006 TIMEOUT) ---
    // The API call can take a while, causing the MySQL connection to drop.
    try {
        $pdo->query("SELECT 1");
    } catch (\PDOException $e) {
        // Reconnect using variables defined in database.php. Guarded because the
        // CLI worker can be running on a connection built from
        // K2_AUTOMATION_TEST_DSN instead, in which case those globals do not
        // exist and there is nothing to reconnect with -- better to let the next
        // query surface the real error than to construct a bogus PDO here.
        global $dsn, $user, $pass, $options;
        if (is_string($dsn ?? null) && $dsn !== '' && is_array($options ?? null)) {
            $pdo = new PDO($dsn, $user, $pass, $options);
        }
    }

    if (empty($jsonResponse)) {
        throw new Exception("Gemini API returned an empty response (Possible Rate Limit hit or Network Timeout).");
    }

    $cleanResponse = str_replace(['```json', '```'], '', $jsonResponse);
    $ai_mapping = json_decode(trim($cleanResponse), true);

    if (!$ai_mapping) {
        $preview = htmlspecialchars(substr($jsonResponse, 0, 100)) . '...';
        throw new Exception("JSON Parsing Failed. Gemini returned: " . $preview);
    }

    $touched_tickets = [];
    $fallbackDetails = [];
    $failedDetails = [];

    // Process Line Items
    foreach ($unassigned_items as $item) {
        $unique_id = $item['ticket_no'] . "_" . $item['ticket_line_item'];
        $ticket_no = $item['ticket_no'];

        if (!isset($ai_mapping[$unique_id])) continue;

        // Handle both old plain string format and new object format gracefully just in case
        $mapping_result = $ai_mapping[$unique_id];
        $assigned_category = is_array($mapping_result) ? ($mapping_result['category'] ?? '') : $mapping_result;
        $confidence = is_array($mapping_result) ? (int)($mapping_result['confidence'] ?? 100) : 100;
        // Only set when the AI judged the item to be a poor fit for every
        // approved category -- see the NEW CATEGORY rule in the prompt above.
        $ai_proposal = [
            'level_4' => is_array($mapping_result) ? trim((string)($mapping_result['new_level_4'] ?? '')) : '',
            'level_5' => is_array($mapping_result) ? trim((string)($mapping_result['new_level_5'] ?? '')) : '',
        ];

        $search_key = strtolower(trim($assigned_category));
        $pic_string = null;
        $mapped_id = null;
        $best_l4_match = $assigned_category;

        // How well the category the AI returned resolves onto a row that really
        // exists in Master Data: 100 when it named one (exactly, or as a
        // substring of a code-prefixed category like "32721 - FIRE EXTINGUISHER"),
        // the similar_text percentage when its answer had to be fuzzy-matched,
        // and 0 when nothing matched at all. Half of the enrichment test below --
        // see md_match_score().
        $resolution_similarity = 0;

        if (isset($level4_map[$search_key])) {
            $pic_string = $level4_map[$search_key]['pic'];
            $mapped_id = $level4_map[$search_key]['id'];
            $resolution_similarity = 100;
        } elseif (isset($level3_map[$search_key])) {
            $pic_string = $level3_map[$search_key]['pic'];
            $mapped_id = $level3_map[$search_key]['id'];
            $resolution_similarity = 100;
        } elseif (isset($level2_map[$search_key])) {
            $pic_string = $level2_map[$search_key]['pic'];
            $mapped_id = $level2_map[$search_key]['id'];
            $resolution_similarity = 100;
        } else {
            $substring_found = false;
            foreach ($level4_map as $l4_key => $mapData) {
                if (strlen($l4_key) > 2 && (strpos($search_key, $l4_key) !== false || strpos($l4_key, $search_key) !== false)) {
                    $pic_string = $mapData['pic'];
                    $mapped_id = $mapData['id'];
                    $best_l4_match = $l4_key;
                    $resolution_similarity = 100;
                    $substring_found = true; break;
                }
            }
            if (!$substring_found) {
                $highest_sim = 0; $best_match = '';
                foreach ($level4_map as $l4_key => $mapData) {
                    similar_text($search_key, $l4_key, $percent);
                    if ($percent > $highest_sim) { $highest_sim = $percent; $best_match = $l4_key; }
                }
                if ($highest_sim >= 70) {
                    $pic_string = $level4_map[$best_match]['pic'];
                    $mapped_id = $level4_map[$best_match]['id'];
                    $best_l4_match = $best_match;
                    $resolution_similarity = $highest_sim;
                }
            }
        }

        // --- MASTER DATA ENRICHMENT ------------------------------------------
        // Master Data is the AI's only knowledge base, so an item it could not
        // confidently place has to be written back into it rather than silently
        // absorbed by the nearest category. Below the match threshold the item
        // becomes its own pending Master Data row (the closest match's taxonomy
        // branch + the item as a new level_5 leaf), reviewable in the Master Data
        // > Suggestions tab. Dated from the cutoff forward only -- historical
        // lines are left exactly as they were.
        if (md_should_enrich($confidence, $resolution_similarity, $item['pr_date'] ?? null)) {
            // Inherit level_0..level_3 from whichever Master Data row the line
            // actually resolved to -- keyed by id, so a level_3 or level_2 match
            // gives the branch just as a level_4 match does. Its level_4 rides
            // along only as md_enrich_master_data()'s last-resort fallback: the
            // new row gets its OWN category, because a sub-threshold match means
            // the item does not belong to this neighbour. When nothing resolved
            // at all there is no branch to inherit and the upper levels are left
            // blank, the same shape a human-submitted SKU suggestion arrives in,
            // for the Category Manager to complete.
            $enrich_branch = ($mapped_id !== null && isset($branch_by_id[$mapped_id]))
                ? $branch_by_id[$mapped_id]
                : [
                    'level_0' => '', 'level_1' => '', 'level_2' => '', 'level_3' => '',
                    'level_4' => trim((string)$assigned_category),
                    'pic'     => null,
                ];

            $score = (int)md_match_score($confidence, $resolution_similarity);
            $new_md_id = md_enrich_master_data(
                $pdo,
                $enrich_branch,
                $item['item_description'],
                $score,
                $enrich_branch['pic'] ?? null,   // closest category's PIC, as a proposal only
                'System',
                $ai_proposal,
                $ticket_no   // records which PRS ticket produced this row, for traceability
            );

            if ($new_md_id !== null) {
                $fallbackDetails[] = "<strong>$ticket_no (Line " . $item['ticket_line_item'] . ")</strong>: "
                    . "match {$score}% (below " . md_enrichment_min_match() . "%) against <em>'"
                    . htmlspecialchars((string)$best_l4_match) . "'</em>. Added to Master Data for review.";
            }
        }

        // Taxonomy is written INDEPENDENTLY of the PIC, and before it.
        //
        // This used to sit inside the `if ($pic_string !== null)` branch below,
        // which meant a line whose category resolved but whose PIC did not got
        // neither -- the taxonomy was thrown away because of an unrelated
        // failure. They are two separate facts about the line; a missing PIC is
        // no reason to discard a good category.
        //
        // The `master_data_id IS NULL OR = 0` guard is what keeps this safe to
        // run over lines that already have a PIC: a mapping a human set by hand
        // (ajax_update_pr_taxonomy.php, or the SLA taxonomy endpoints) always
        // wins, and re-processing a ticket can never overwrite it.
        if ($mapped_id !== null) {
            // Not stamped: prs.last_updated_by/last_updated_date track assigned_pic
            // only, and this statement fills in taxonomy, not the PIC.
            $lineUpdateStmt = $pdo->prepare("UPDATE prs SET master_data_id = ? WHERE ticket_no = ? AND ticket_line_item = ? AND (master_data_id IS NULL OR master_data_id = 0) $project_exclusion_sql");
            // Counted as progress. successCount feeds the worker's zero-progress
            // detector (process_k2_import_queue.php), which aborts the run after
            // two batches that report nothing -- so a batch that only filled in
            // taxonomy has to say so, or the worker stops and the very same
            // tickets are re-selected and re-sent to Gemini on every future run.
            if ($lineUpdateStmt->execute([$mapped_id, $ticket_no, $item['ticket_line_item']]) && $lineUpdateStmt->rowCount() > 0) {
                $touched_tickets[$ticket_no] = true;
                md_append_ticket_id($pdo, (int)$mapped_id, $ticket_no);
            }
        }

        if ($pic_string !== null) {
            if (strpos($pic_string, ',') !== false) {
                $combo_pics = array_map('trim', explode(',', $pic_string));
                $pic_string = $combo_pics[array_rand($combo_pics)];
            }

            // Single-PIC categories were the one path that skipped trimming: the
            // comma branch above has always trimmed each name, so an untrimmed
            // single name was an oversight rather than a decision. 27 of ~1,065
            // master_data.pic_names rows carry trailing spaces, and copying those
            // through verbatim splits one person into two for any PHP-side
            // grouping or exact match. SQL comparison hides it -- utf8mb4 is a
            // PAD SPACE collation, so "Name" = "Name  " is TRUE and even
            // DISTINCT/GROUP BY collapse them -- which is what let this go
            // unnoticed. Use LENGTH(), not <> TRIM(), to audit for it.
            $pic_string = trim($pic_string);

            // Each line keeps its own resolved PIC -- a ticket can legitimately
            // have different lines routed to different PICs, so this writes
            // per-line rather than collapsing the ticket to one winner.
            // `assigned_pic IS NULL` keeps this to lines still unassigned; a
            // ticket can be part system-routed (AON/Intercompany stamped at
            // import time) and part AI-assignable.
            // Stamped as 'AI Auto-Assign' rather than the session user: this runs
            // unattended from the import worker as often as from a human click,
            // and the edit trail should say the machine filled the PIC in.
            $picUpdateStmt = $pdo->prepare("UPDATE prs SET " . pr_stamp_pic_set_sql()
                . ", assigned_pic = ?, assigned_by_ai = 1 WHERE ticket_no = ? AND ticket_line_item = ? AND assigned_pic IS NULL $project_exclusion_sql");
            $picUpdateParams = array_merge(
                pr_stamp_pic_params($pic_string, pr_stamp_by('AI Auto-Assign')),
                [$pic_string, $ticket_no, $item['ticket_line_item']]
            );
            if ($picUpdateStmt->execute($picUpdateParams) && $picUpdateStmt->rowCount() > 0) {
                $touched_tickets[$ticket_no] = true;
            }
        } else {
            $failedDetails[] = "<strong>$ticket_no (Line " . $item['ticket_line_item'] . ")</strong>: AI guessed <em>'$assigned_category'</em>";
        }
    }

    // successCount reports tickets touched (matches the auto_assign.php progress
    // bar, which counts against total tickets, not total lines).
    $successCount = count($touched_tickets);

    $countStmt = $pdo->query("SELECT COUNT(DISTINCT ticket_no) FROM prs WHERE $pending_sql $exclusion_sql");
    $new_remaining = $countStmt->fetchColumn();

    return [
        'status' => 'processing',
        'successCount' => $successCount,
        'fallbackDetails' => $fallbackDetails,
        'failedDetails' => $failedDetails,
        'remaining' => $new_remaining,
        'estimated_tokens' => $estimated_tokens
    ];
}
