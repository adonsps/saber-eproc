<?php
// workspace/nma_rename_master_data.php
//
// Rewrite existing Master Data names into GOLDEN RULE 3's Scenario A shape: the
// HEAD NOUN first with its modifiers MERGED into one opening segment, then each
// critical technical attribute as its own segment behind a semicolon.
//
//   level_4  PEOPLE COUNTING SENSOR   ->  SENSOR PEOPLE COUNTING
//   level_5  PEOPLE COUNTING SENSOR - 3D GEN 2 2.5MM 25W
//            ->  SENSOR PEOPLE COUNTING 3D GEN 2; 2.5MM; 25W
//
// No dash survives: RULE 3 prohibits it at segment level, so the " - " older
// names used between core and attributes becomes a segment break.
//
// From now on the four prompts that mint names (SKU Dictionary mapping, the
// ticket batch, AI autofill, the SKU auto-generator) all carry
// md_gr_nma_instruction(), so new rows arrive in this shape already. This script
// is for the rows written before that.
//
// WHY THE AI AND NOT A REGEX. Reordering needs to know which word is the head
// noun. This catalogue mixes head-final English ("NETWORK SWITCH" -> "SWITCH
// NETWORK") with head-initial Indonesian ("KABEL UTP", already correct) and is
// full of fixed compounds that must not be split ("ACCESS POINT"). A "last word
// moves to the front" rule was measured against these very categories and
// damaged about a quarter of them, reversing ones that were already right. So
// Gemini picks the word order; md_standardize_category_name() and
// md_sanitize_hybrid_name() then enforce everything mechanical -- case, symbols,
// specs out of level_4, the JASA prefix, the segment separators.
//
// SCOPE
//   * 'ai_suggested' rows are taken whole: those are the pending rows a Category
//     Manager is reviewing, and they are the ones with clean, AI-minted
//     categories.
//   * On every OTHER status, HAVING A level_5 is the gate. level_5 IS the item
//     name -- it is what the catalogue shows per SKU -- so a head-final one
//     ("NOTEBOOK BATTERY - DELL PRECISION 3561") is exactly the defect this
//     fixes. Legacy level_4 values, by contrast, are mostly NOT categories at
//     all: raw descriptions carrying ERP codes, brands and whole spec strings
//     ("[34552] 10494588 COOKER HOOD ELECTROLUX ECT-9740"). Reordering those
//     would only scramble them differently, so a row with no level_5 and no
//     AI-minted category is left alone and nothing runs table-wide.
//   * A row whose level_4 is blank or a 'pending_' placeholder still gets its
//     level_5 re-ordered -- the level_5 head serves as the phrase to re-order
//     and level_4 is left exactly as it was. Before, the WHERE clause dropped
//     these rows entirely, so the two names most in need of it ("PRICE TICKET
//     UKURAN ...", "KABEL NYM 4X4 MM 50 METER") were never reached.
//   * status = 'registered' is EXCLUDED unless --include-registered is passed:
//     that SKU name is already registered in K2-PSS and renaming it here would
//     desync the two systems. Same rule as normalize_master_data_l5.php.
//   * A rename that would collide with an existing (level_4, level_5) pair is
//     skipped and reported: that unique key is what keeps the catalogue free of
//     duplicates, and merging is a decision, not a rename.
//
// Every run writes a rollback script to db_backups/local_runtime_exports/
// before touching anything, so an --apply can always be undone.
//
// REVIEW THEN REPLAY. Every run saves the AI's word-order answers as a plan
// file, and --replay=<plan> commits those answers instead of asking again. Use
// it: a bare --apply re-asks, and the AI is not deterministic -- a second call on
// the same prompt returned WORSE names for some rows ("SOUND SYSTEM AND ACC" ->
// "SYSTEM SOUND AND ACC") and left others head-final, so the plan that got
// written was not the plan that was reviewed. Replaying only fixes the word
// order; collisions and already-correct rows are still re-evaluated against the
// live table, so a stale plan can't write a duplicate.
//
// Usage (command line only -- see the guard below):
//   php nma_rename_master_data.php                        # dry run, prints every rename + a plan file
//   php nma_rename_master_data.php --apply --replay=<plan>  # commit exactly that plan
//   php nma_rename_master_data.php --apply                # re-ask the AI, then commit
//   php nma_rename_master_data.php --status=ai_suggested,approved_new
//   php nma_rename_master_data.php --include-registered   # also rename ERP-registered SKUs
//   php nma_rename_master_data.php --limit=5              # try a handful first

// COMMAND LINE ONLY. This file lives under the web root; without this guard any
// visitor could rewrite category names across Master Data by requesting it.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/master_data_enrichment.php';
require_once __DIR__ . '/config/gemini_api.php';
require_once __DIR__ . '/includes/master_data_audit.php';

$argv_list = $argv ?? [];
$apply  = in_array('--apply', $argv_list, true);
// Every status whose names are still ours to change. 'registered' is not here on
// purpose -- see SCOPE.
$status = ['ai_suggested', 'approved_new', 'user_suggested', 'active'];
$limit  = 0;
foreach ($argv_list as $arg) {
    if (strpos($arg, '--status=') === 0) {
        $status = array_values(array_filter(array_map('trim', explode(',', substr($arg, 9)))));
    }
    if (strpos($arg, '--limit=') === 0) {
        $limit = max(0, (int)substr($arg, 8));
    }
    if (strpos($arg, '--replay=') === 0) {
        $replay_path = trim(substr($arg, 9));
    }
}
$replay_path = $replay_path ?? '';

if (in_array('--include-registered', $argv_list, true) && !in_array('registered', $status, true)) {
    $status[] = 'registered';
}
$nl = "\n";

/**
 * The set of distinct words in a name, normalized the same way the standardizers
 * normalize one. Used to prove that a level_5 the AI handed back is the SAME
 * WORDS re-delimited and nothing more.
 *
 * A SET, not a list: order and segmentation are exactly what this operation is
 * allowed to change, and a repeated word collapsing (a legacy level_5 that
 * echoed its own core) is a fix rather than a loss. Anything else -- a word
 * appearing that was not there, or one disappearing -- fails the comparison.
 */
function nma_token_set($text): array {
    $clean = mb_strtoupper(md_gr_clean_symbols(md_gr_strip_codes((string)$text)), 'UTF-8');
    if ($clean === '') { return []; }
    $tokens = array_values(array_unique(preg_split('/\s+/u', $clean)));
    sort($tokens);
    return $tokens;
}

if (empty($status)) {
    echo "No --status values given." . $nl;
    exit(1);
}

try {
    $in = implode(',', array_fill(0, count($status), '?'));
    // A row qualifies on either name: a usable level_4 (the category to re-order)
    // or a level_5 (the item name, which needs re-ordering whether or not the row
    // has a category behind it).
    $stmt = $pdo->prepare("
        SELECT id, status, level_4, level_5, requested_item_name
        FROM master_data
        WHERE status IN ($in)
          AND (
                (level_4 IS NOT NULL AND TRIM(level_4) <> '' AND level_4 NOT LIKE 'pending\_%')
             OR (level_5 IS NOT NULL AND TRIM(level_5) <> '')
              )
        ORDER BY id
    ");
    $stmt->execute($status);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Outside the AI backlog, level_5 is the gate: those rows' level_4 values are
    // legacy raw descriptions rather than categories, and re-ordering a
    // description only scrambles it differently (see SCOPE).
    $rows = array_values(array_filter($rows, function($r) {
        return ($r['status'] ?? '') === 'ai_suggested'
            || trim((string)$r['level_5']) !== '';
    }));

    if ($limit > 0) {
        $rows = array_slice($rows, 0, $limit);
    }

    if (empty($rows)) {
        echo "No rows match status " . implode(',', $status) . "." . $nl;
        return;
    }

    // Every (level_4, level_5) pair in the table, so a rename that would collide
    // with the unique key is caught here rather than as a failed UPDATE mid-run.
    $taken = [];
    foreach ($pdo->query("SELECT id, level_4, level_5 FROM master_data")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $taken[mb_strtolower(trim((string)$r['level_4']) . '|' . trim((string)$r['level_5']), 'UTF-8')] = (int)$r['id'];
    }

    // The single phrase per row that the AI is asked to re-order -- the CORE that
    // both names are then built from. A usable level_4 IS that core. When there
    // isn't one, level_5's own first segment serves instead,
    // so a row with a blank or 'pending_' level_4 still gets its item name fixed
    // and its level_4 is left untouched.
    $core_source = [];
    foreach ($rows as $r) {
        $l4 = trim((string)$r['level_4']);
        $l4_usable = $l4 !== '' && stripos($l4, 'pending_') !== 0;
        $core_source[(int)$r['id']] = [
            'usable' => $l4_usable,
            'phrase' => $l4_usable
                ? $l4
                : trim(preg_split('/\s+-\s+/u', (string)$r['level_5'], 2)[0]),
        ];
    }

    // One call for the whole set: the reordering decision needs no per-row
    // context beyond the row itself, and 20 names in one prompt is far cheaper
    // and far less rate-limit-prone than 20 calls.
    // Each PHRASE is labelled with where it came from. A phrase lifted off a
    // level_5 head is a raw item name that still has the item's attributes stuck
    // to it, and the AI has to be told so -- unlabelled, it obeyed "reorder, never
    // substitute" literally and carried them into the core ("PRICE TICKET BAHAN").
    $items = '';
    foreach ($rows as $r) {
        $src = $core_source[(int)$r['id']];
        $items .= "ID {$r['id']} | PHRASE: {$src['phrase']}"
               . ' | PHRASE IS: ' . ($src['usable'] ? 'a category' : 'a raw item name -- return ONLY its product-type head')
               . " | SKU NAME: {$r['level_5']}"
               . " | ORIGINAL: " . trim((string)$r['requested_item_name']) . "\n";
    }

    // Two answers per row: the CORE (word order) and the re-delimited SKU NAME
    // (where each attribute segment begins and ends). The second
    // one is why nma_token_set() exists -- an earlier version let the AI re-word
    // level_5 freely and it quietly invented attributes ("CAR") and dropped model
    // identities ("UROVO CT58S"), so the answer is now only accepted when it is
    // provably the same words rearranged, and the mechanical rebuild takes over
    // when it isn't.
    $prompt  = "You are an expert procurement master-data cataloguer.\n\n";
    $prompt .= md_gr_nma_instruction() . "\n";
    $prompt .= "For each row below: rewrite the PHRASE into that order, and re-punctuate the SKU NAME into the NMA shape.\n";
    $prompt .= "The PHRASE is the head of the row's names: it becomes the category AND the start of the SKU NAME.\n";
    $prompt .= "Re-punctuating the SKU NAME means deciding where each attribute segment begins and ends. It is NOT a re-wording: ORIGINAL is context only, and you must never rewrite it.\n\n";
    $prompt .= "ROWS:\n" . $items . "\n";
    $prompt .= "CRITICAL RULES:\n";
    $prompt .= "1. Output ONLY a valid JSON object, no markdown and no commentary.\n";
    $prompt .= "2. Format: {\"<ID>\": {\"core\": \"...\", \"sku_name\": \"...\"}}\n";
    $prompt .= "3. core is PRODUCT TYPE ONLY (GOLDEN RULE 3): no brands, models, sizes, capacities, colors or specs. UPPERCASE. Keep any \"JASA \" prefix at the very front (GOLDEN RULE III).\n";
    // A PHRASE taken from a level_5 head (a row with no category of its own)
    // still carries the item's attributes, and the bare unit words in them
    // survive the spec stripper -- every token it drops has to contain a digit.
    // Without this the AI kept them: "KABEL NYM 4X4 MM 50 METER" came back as
    // the core "KABEL NYM MM METER" instead of "KABEL NYM".
    $prompt .= "   A bare unit of measure is a spec too, so drop it as well: MM, CM, M, METER, INCH, KG, GR, LITER, PCS, SET, ROLL, WATT, VOLT, AMPERE, PORT, CHANNEL.\n";
    $prompt .= "4. Use the same words the PHRASE already has -- reorder them, never substitute or translate them. Dropping a brand/model/spec word is expected under rule 3; dropping a product-type word is not.\n";
    $prompt .= "   When PHRASE IS a raw item name, keep only the words that name the product type and leave every attribute out -- they are already in the SKU NAME and will be re-attached as their own segments.\n";
    $prompt .= "5. When a PHRASE is already in NMA order, return it unchanged.\n";
    $prompt .= "6. sku_name must use EXACTLY the words already in SKU NAME -- every one of them, and not one more. You are only allowed to move them and to place the segment separators. Never add a word, never drop a model code or a measurement, never translate.\n";
    $prompt .= "7. sku_name carries NO dash and NO parentheses. Any dash the old SKU NAME still has is a leftover separator: replace it with a segment break, or merge the words if they belong in the first segment.\n";
    $prompt .= "Example: for SKU NAME \"SENSOR PEOPLE COUNTING - 3D GEN 2 2.5MM 2510M 25W\"\n";
    $prompt .= "  {\"3404\": {\"core\": \"SENSOR PEOPLE COUNTING\", \"sku_name\": \"SENSOR PEOPLE COUNTING 3D GEN 2; 2.5MM; 2510M; 25W\"}}\n";
    $prompt .= "Example: for SKU NAME \"NOTEBOOK BATTERY - DELL PRECISION 3561 GRT01\"\n";
    $prompt .= "  {\"3396\": {\"core\": \"BATTERY NOTEBOOK\", \"sku_name\": \"BATTERY NOTEBOOK; DELL PRECISION 3561; GRT01\"}}\n";

    // Where the plan (and the rollback script) go. Needed before the AI call so a
    // fresh set of answers can be saved for --replay.
    $export_dir = __DIR__ . '/../db_backups/local_runtime_exports';
    if (!is_dir($export_dir)) { @mkdir($export_dir, 0777, true); }
    $plan_path = '';

    if ($replay_path !== '') {
        // Replay the answers a previous run already printed, instead of asking
        // again. The AI is not deterministic: re-asking on --apply meant the plan
        // that got written was not the plan anyone reviewed, and a second call on
        // the same prompt really did return WORSE names for some rows ("SOUND
        // SYSTEM AND ACC" came back as "SYSTEM SOUND AND ACC") while silently
        // leaving others head-final. Only the word-order answers are replayed --
        // every stage after this one re-runs against the live table, so collisions
        // and already-correct rows are still evaluated fresh.
        if (!is_file($replay_path)) {
            echo "No such plan file: {$replay_path}" . $nl;
            exit(1);
        }
        $parsed = json_decode((string)file_get_contents($replay_path), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed)) {
            echo "Plan file is not valid JSON: {$replay_path}" . $nl;
            exit(1);
        }
        echo "Replaying " . count($parsed) . " saved answer(s) from " . $replay_path . $nl;
    } else {
        echo "Asking the AI to re-order " . count($rows) . " row(s)..." . $nl;
        $ai_text = call_gemini_api($prompt, 3, gemini_key_admin_misc());
        $ai_text = trim(preg_replace('/```(json)?/i', '', $ai_text));
        $parsed  = json_decode($ai_text, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed)) {
            echo "AI returned unusable JSON:" . $nl . substr($ai_text, 0, 500) . $nl;
            exit(1);
        }
        $plan_path = $export_dir . '/nma_rename_master_data_plan_' . date('Ymd_His') . '.json';
        file_put_contents($plan_path, json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    $to_rename = [];
    $skipped_missing    = 0;
    $skipped_unchanged  = 0;
    $skipped_collision  = 0;
    $rejected_sku_name  = 0;

    foreach ($rows as $r) {
        $answer = $parsed[(string)$r['id']] ?? null;
        if (!is_array($answer)) { $skipped_missing++; continue; }

        // The AI decides the word order; these decide the format.
        $new_core = md_standardize_category_name($answer['core'] ?? '', $r['requested_item_name'] ?: $r['level_5']);
        if ($new_core === '') { $skipped_missing++; continue; }

        // The core is written back as the category only when there was a real
        // category to begin with. A blank or 'pending_' level_4 is a placeholder
        // the review flow owns -- this script must not turn it into a category
        // as a side effect of fixing the item name.
        $new_l4 = $core_source[(int)$r['id']]['usable'] ? $new_core : $r['level_4'];

        // Repair on the way any decimal a previous sanitizer split ("2 5MM" ->
        // "2.5MM"), which the untouched original description can still vouch for.
        // A row that has no level_5 keeps not having one -- there is no item name
        // here to invent.
        $old_l5 = md_gr_restore_decimals((string)$r['level_5'], $r['requested_item_name']);

        // Where each attribute segment begins and ends is a language
        // judgment PHP cannot make ("CORE I7 32GB RAM 1TB SSD" has to become
        // "CORE I7;32GB RAM;1TB SSD"), so the AI places them -- but only after
        // proving it changed nothing except the punctuation. A name that fails
        // that check is discarded and the row falls back to the mechanical
        // rebuild, which keeps the old tail verbatim behind the new core.
        $proposed = md_gr_restore_decimals(trim((string)($answer['sku_name'] ?? '')), $r['requested_item_name']);
        $basis = $old_l5;
        if ($old_l5 !== '' && $proposed !== '') {
            if (nma_token_set($proposed) === nma_token_set($old_l5)) {
                $basis = $proposed;
            } else {
                $rejected_sku_name++;
                echo "  WORDS CHANGED (using mechanical rebuild) id={$r['id']}: '{$proposed}'" . $nl;
            }
        }

        $new_l5 = ($basis === '') ? $r['level_5'] : md_sanitize_hybrid_name($basis, $new_core);
        if ($new_l5 === '') { $new_l5 = $r['level_5']; }

        if ($new_l4 === $r['level_4'] && $new_l5 === $r['level_5']) { $skipped_unchanged++; continue; }

        // Keyed exactly like $taken above, so a NULL level_5 (a category-only row)
        // compares against the table the same way it was indexed.
        $key = mb_strtolower(trim((string)$new_l4) . '|' . trim((string)$new_l5), 'UTF-8');
        if (isset($taken[$key]) && $taken[$key] !== (int)$r['id']) {
            $skipped_collision++;
            echo "  COLLISION (skipped) id={$r['id']}: '{$new_l4}' / '{$new_l5}' already on id={$taken[$key]}" . $nl;
            continue;
        }
        $taken[$key] = (int)$r['id'];

        $to_rename[] = [
            'id'     => (int)$r['id'],
            'old_l4' => $r['level_4'], 'new_l4' => $new_l4,
            'old_l5' => $r['level_5'], 'new_l5' => $new_l5,
        ];
    }

    echo $nl . "Rows considered            : " . count($rows) . $nl;
    echo "  already in NMA order     : " . $skipped_unchanged . $nl;
    echo "  no usable AI answer      : " . $skipped_missing . $nl;
    echo "  unique-key collisions    : " . $skipped_collision . $nl;
    echo "  AI SKU name rejected     : " . $rejected_sku_name . " (words changed -- rebuilt mechanically)" . $nl;
    echo "  TO RENAME                : " . count($to_rename) . $nl . $nl;

    foreach ($to_rename as $r) {
        echo "  id={$r['id']}" . $nl;
        echo "    L4: {$r['old_l4']}" . $nl . "     -> {$r['new_l4']}" . $nl;
        echo "    L5: {$r['old_l5']}" . $nl . "     -> {$r['new_l5']}" . $nl;
    }
    echo $nl;

    if (empty($to_rename)) {
        echo "Nothing to do." . $nl;
        return;
    }

    // --- Rollback script (written on dry runs too, so it can be reviewed) ---
    $rollback_path = $export_dir . '/nma_rename_master_data_rollback_' . date('Ymd_His') . '.sql';
    $sql = "-- Rollback for nma_rename_master_data.php, generated " . date('c') . "\n"
         . "-- Restores level_4 / level_5 on the " . count($to_rename) . " row(s) it renames.\n"
         . "START TRANSACTION;\n";
    foreach ($to_rename as $r) {
        $sql .= sprintf(
            "UPDATE master_data SET level_4 = %s, level_5 = %s WHERE id = %d;\n",
            $pdo->quote($r['old_l4']), $pdo->quote($r['old_l5']), $r['id']
        );
    }
    $sql .= "COMMIT;\n";
    file_put_contents($rollback_path, $sql);
    echo "Rollback script written to: " . $rollback_path . $nl;

    if (!$apply) {
        echo $nl . "DRY RUN -- nothing was written." . $nl;
        if ($plan_path !== '') {
            // Replaying is the recommended path: it commits exactly the plan
            // printed above, where a bare --apply would ask the AI again and can
            // write a different one.
            echo "Commit THIS plan:  php nma_rename_master_data.php --apply --replay=" . $plan_path . $nl;
            echo "Or --apply alone to re-ask the AI for a fresh plan." . $nl;
        }
        return;
    }

    // Gemini picks the new word order, so the rename is attributed to 'ai'.
    $upd = $pdo->prepare("UPDATE master_data SET level_4 = ?, level_5 = ?, last_modified_by = ? WHERE id = ?");
    $pdo->beginTransaction();
    try {
        $written = 0;
        foreach ($to_rename as $r) {
            $before = md_audit_snapshot($pdo, [$r['id']]);
            $upd->execute([$r['new_l4'], $r['new_l5'], MD_AUDIT_ACTOR_AI, $r['id']]);
            $written += $upd->rowCount();
            md_audit_log_update($pdo, $before, MD_AUDIT_ACTOR_AI);
        }
        $pdo->commit();
        echo $nl . "Applied. {$written} row(s) renamed into NMA order." . $nl;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
} catch (PDOException $e) {
    echo "Error renaming to NMA order: " . $e->getMessage() . $nl;
} catch (Exception $e) {
    echo "AI error: " . $e->getMessage() . $nl;
}
