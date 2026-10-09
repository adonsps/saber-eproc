<?php
// includes/master_data_enrichment.php
//
// Master Data is the single source of truth and the sole knowledge base the AI
// maps items against. Every supporting activity that discovers an item —
// ticket/PR import, the SKU Dictionary mapper (which is where the K2 Master AON
// catalog lands), and manual SKU suggestions — therefore has to feed back into
// it, instead of quietly forcing the item onto whichever existing category
// happened to be nearest.
//
// The problem this fixes: the AI is told to "pick the closest broad category if
// the exact item is missing", so a genuinely unknown item never surfaced as
// missing — it just got absorbed by a neighbour, and the further the real item
// was from that neighbour the worse the routing. Now, when the mapping is not a
// confident match against Master Data, the item is written back as its own
// Master Data row for review, so the knowledge base grows instead of drifting.
//
// The row it writes follows the SKU-suggestion schema exactly (see the
// "Submit New SKU" flow in pages/master_data.php and pages/ajax_auto_generate_sku.php):
//   level_0..level_3 — the taxonomy branch of the closest Master Data match
//   level_4          — that closest match's category
//   level_5          — the standardized item name (the new leaf)
//   requested_item_name — the raw text the item arrived as
// which is what makes these rows reviewable in the same Suggestions tab, with
// the same Review/Approve buttons, as a human-submitted SKU suggestion.

// Rows written here are the AI's own, so they are stamped 'ai' in the audit
// columns and in master_data_history.
require_once __DIR__ . '/master_data_audit.php';

if (!function_exists('md_enrichment_cutoff_date')) {

/**
 * Ticket lines dated before this are left exactly as they were: no Master Data
 * row is created for them, no matter how poor their mapping is. Enrichment
 * applies to items arriving from this date forward only, so switching the
 * feature on doesn't retro-generate a suggestion for every historical PR line
 * ever imported.
 *
 * Set to the day the rule went live (2026-07-31). Sources with no date of their
 * own — the SKU Dictionary mapper is triggered by hand, per selection — are not
 * subject to it; the operator's act of selecting the rows is the intent.
 */
function md_enrichment_cutoff_date(): string {
    return '2026-07-31';
}

/**
 * Match strength (0-100) at or above which an item counts as "already in Master
 * Data" and is left alone. Below it, the item is treated as new and written
 * back for review.
 */
function md_enrichment_min_match(): int {
    return 95;
}

/**
 * How well an AI mapping actually matched Master Data, as the weaker of the two
 * independent signals:
 *
 *   $confidence — the AI's own 0-100 confidence that this item belongs in the
 *                 category it chose.
 *   $resolution_similarity — how closely the category string the AI returned
 *                 matches a real Master Data row: 100 when it named an actual
 *                 category, the similar_text() percentage when its answer had
 *                 to be fuzzy-resolved onto one, 0 when nothing matched at all.
 *
 * Taking the minimum means either kind of doubt is enough to flag the item:
 * a confident answer that resolves onto a barely-related category is just as
 * much a miss as a real category picked with low confidence.
 */
function md_match_score($confidence, $resolution_similarity): float {
    return min((float)$confidence, (float)$resolution_similarity);
}

/**
 * Should this mapping enrich Master Data? True when the match is weaker than
 * the threshold and — for dated sources — the item is from the cutoff forward.
 *
 * @param string|null $source_date ticket/PR date (Y-m-d); null for undated
 *                                 sources, which are never date-gated
 */
function md_should_enrich($confidence, $resolution_similarity, ?string $source_date = null): bool {
    if (md_match_score($confidence, $resolution_similarity) >= md_enrichment_min_match()) {
        return false;
    }
    if ($source_date === null) {
        return true;
    }
    $source_date = trim($source_date);
    // An undated line can't be shown to be new, so it is treated as historical
    // and left alone — "before, don't change".
    return $source_date !== '' && $source_date >= md_enrichment_cutoff_date();
}

// ---------------------------------------------------------------------------
// GOLDEN-RULE NAME BUILDERS
//
// The house naming standard lives in the ai_golden_rules table -- the same text
// that is fed to Gemini as a system instruction. The parts of it that constrain
// what may be written into level_4 / level_5 are enforced here in code as well,
// because the AI is not the only writer and its output drifts:
//
//   RULE 2  NO COMMAS in level_5, and the semicolon is the sole structural
//           delimiter that replaces them. A comma column-shifts or row-splits a
//           CSV extraction; a semicolon is read as ordinary text and keeps the
//           whole description inside one cell. Commas also go from level_4, and
//           pic_names is itself a comma-separated column.
//   RULE 3  level_4 is the anchor used for spend analysis: PRODUCT TYPE ONLY,
//           free of brands, sizes, dimensions, colors and specs. level_5 is
//           Scenario A -- the noun and its modifier MERGED into one first
//           segment with no punctuation between them, then the critical
//           attributes, each separated by a semicolon ("SENSOR PEOPLE COUNTING
//           3D GEN 2; 2.5MM; 2510M; 25W"). ALL CAPITALS. Dashes and parentheses
//           are prohibited at segment level, so there is no core/attribute dash
//           any more.
//   III     service-intent items take a "JASA " prefix so Finance can deduct
//           withholding tax (PPh 23/21) automatically.
//
// What actually arrives from an import looks nothing like that. Real strings
// this has to survive:
//   "[36708] [AON] PDT UROVO CT58S ANDROID 4GB/64GB (INCLUDE USB CABLE C, ...)"
//   "38233 - DIGITAL VIDEO RECORDER 16CH DH-XVR4216AN-I/T"
// so raw text is stripped of ticket/ERP codes and symbols before it is allowed
// anywhere near a level_4 or level_5 column.
// ---------------------------------------------------------------------------

/**
 * The Noun-Modifier-Attribute (NMA) instruction every prompt that mints a
 * category or a SKU name shares, so all four of them ask for the same shape:
 * the HEAD NOUN leads, its modifiers merge into the first segment, and each
 * attribute follows as its own semicolon-delimited segment.
 *
 * WHY THIS LIVES IN THE PROMPT AND NOT IN PHP. Reordering a phrase means
 * knowing which word is the head noun, and that is a language question this
 * catalogue cannot answer mechanically: it mixes head-final English ("NETWORK
 * SWITCH" -> "SWITCH NETWORK") with head-initial Indonesian ("KABEL UTP", which
 * is already correct), and it is full of fixed compounds that must never be
 * split ("ACCESS POINT" -> "POINT ACCESS" is nonsense). A "move the last word to
 * the front" rule was tried against the real pending categories and actively
 * damaged roughly a quarter of them, including ones that were already right. So
 * the AI decides the word order and md_standardize_category_name() /
 * md_sanitize_hybrid_name() enforce everything that IS mechanical -- case,
 * symbols, specs, the JASA prefix, the segment separators.
 */
function md_gr_nma_instruction(): string {
    return "NOUN-MODIFIER-ATTRIBUTE ORDER, SEMICOLON-DELIMITED (GOLDEN RULE 3, SCENARIO A).\n"
         . "   - Write the category with its HEAD NOUN FIRST, then the modifiers. Reorder head-final phrases: \"SENSOR PEOPLE COUNTING\" (not \"PEOPLE COUNTING SENSOR\"), \"CAMERA CCTV\", \"SWITCH NETWORK\", \"RECORDER DIGITAL VIDEO\".\n"
         . "   - Leave a phrase whose noun ALREADY leads exactly as it is: \"CABLE UTP\", \"JASA INSTALASI SECURITY SYSTEM\".\n"
         . "   - Never split a fixed compound noun: \"ACCESS POINT\", \"HARD DISK\" stay whole.\n"
         . "   - The SKU name (level_5) is the item detail description. Its shape is exactly:\n"
         . "         <NOUN MODIFIER MERGED>; <ATTRIBUTE>; <ATTRIBUTE>; <ATTRIBUTE>\n"
         . "     Example: \"NOTEBOOK DESIGNER; 14.5 INCH; RAM 32GB; SSD 512GB; VGA 6GB; WINDOWS 11 PRO\".\n"
         . "     Example: \"SENSOR PEOPLE COUNTING 3D GEN 2; 2.5MM; 2510M; 25W\".\n"
         . "     Example: \"PRINTER DOT MATRIX INDUSTRIAL THERMAL TRANSFER; 4 INCH; 203 DPI\".\n"
         . "   - FIRST SEGMENT: the noun and its modifiers MERGED, with NO punctuation between them -- including generation/version words (\"3D\", \"GEN 2\", \"INDOOR\", \"MANAGED\"). Never let a brand, model, size or spec take the lead position.\n"
         . "   - EVERY LATER SEGMENT: one CRITICAL TECHNICAL ATTRIBUTE -- a measurement, capacity, size, colour, volume, power, material or model identity.\n"
         . "   - The semicolon is the ONLY delimiter. NO COMMAS anywhere (GOLDEN RULE 2 -- a comma column-shifts a CSV extraction). NO dashes and NO parentheses at segment level. ALL CAPITAL LETTERS. Never a trailing semicolon.\n"
         . "     Wrong: \"NOTEBOOK, DESIGNER, 14.5 INCH\" (commas). Wrong: \"NOTEBOOK (DESIGNER) - 14.5 INCH\" (brackets and dash).\n";
}

/**
 * Does this text describe a service rather than physical goods? Keyword list is
 * GOLDEN RULE III's, which is what the Finance tax flag keys off.
 *
 * "MAINTENANCE FREE" is excluded explicitly: it is a battery/equipment property,
 * not a service engagement, and it is common enough in this catalog to be worth
 * the special case.
 */
function md_gr_is_service($text): bool {
    $hay = ' ' . mb_strtolower(trim((string)$text), 'UTF-8') . ' ';
    if (preg_match('/\bmaintenance[\s-]free\b/u', $hay)) {
        return false;
    }
    return (bool)preg_match(
        '/\b(jasa|service|services|servis|instalasi|installation|install|renewal|'
        . 'sewa|rental|cetak|printing|customization|kustomisasi|refill|isi ulang|'
        . 'maintenance|perawatan|perbaikan|repair|cleaning|pembersihan|'
        . 'bongkar pasang|kalibrasi|calibration)\b/u',
        $hay
    );
}

/**
 * Remove the ticket/ERP bookkeeping that rides in front of a description and is
 * never part of the item's identity:
 *   "[36708] [AON] PDT UROVO ..."  -> "PDT UROVO ..."
 *   "38233 - DIGITAL VIDEO ..."    -> "DIGITAL VIDEO ..."
 *   "10470775 CCTV CVI ..."        -> "CCTV CVI ..."
 */
function md_gr_strip_codes($text): string {
    $text = (string)$text;
    $text = preg_replace('/\[[^\]]*\]/u', ' ', $text);          // [27542], [AON]
    $text = preg_replace('/^\s*\d{3,}\s*[-\x{2013}]\s*/u', '', $text); // "38233 - "
    $text = preg_replace('/^\s*\d{6,}\s+/u', '', $text);        // bare material code
    return trim((string)$text);
}

/**
 * Strip every symbol that either poisons a CSV/ERP export or is pure noise,
 * leaving letters, digits and single spaces.
 *
 * Two symbols are translated rather than dropped, because dropping them loses
 * meaning: an inch mark becomes the word INCH, and "&" becomes AND. Decimal
 * points inside numbers survive ("1.5M3"); every other dot goes.
 *
 * Hyphens are flattened to spaces on purpose. RULE 3 prohibits the dash at
 * segment level outright, so a model code like "RG-S5000-24GT4MS-P-E" becomes
 * "RG S5000 24GT4MS P E" -- one attribute, not five.
 */
function md_gr_clean_symbols($text, bool $keep_semicolons = false): string {
    $text = (string)$text;
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
    $text = preg_replace('/(\d)\s*"/u', '$1 INCH', $text);
    $text = preg_replace('/\s*&\s*/u', ' AND ', $text);
    // Park decimal points out of reach of the symbol sweep, then restore them.
    // The placeholder has to be exempted from the sweep as well -- it is neither
    // a letter nor a digit, so without the exemption it was swept away with
    // everything else and "1.5M3" came out as "1 5M3", losing the very decimal
    // this is here to protect (GOLDEN RULE 3's own example is CALIBRATION GAS -
    // 1.5M3).
    $text = preg_replace('/(?<=\d)\.(?=\d)/u', "\x01", $text);
    // RULE 2 is a ban on COMMAS, and the semicolon is the sole delimiter that
    // replaces them. So when the caller is building SKU segments a comma becomes
    // a separator rather than being swept away, and existing separators are
    // parked out of reach of the symbol sweep. Everywhere else -- level_4 above
    // all -- both characters are still swept to a space: level_4 is an anchor,
    // not a delimited list.
    if ($keep_semicolons) {
        $text = str_replace([',', ';'], "\x02", $text);
    }
    $text = preg_replace('/[^\p{L}\p{N}\s\x{01}\x{02}]+/u', ' ', $text);
    $text = str_replace("\x01", '.', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    if ($keep_semicolons) {
        $text = str_replace("\x02", ';', $text);
        // Parsing form: a bare separator, no padding, none dangling at either
        // end. The display spacing ("; ") is applied once at the end, by
        // md_gr_format_attributes(), so that re-parsing a formatted name is
        // idempotent.
        $text = preg_replace('/\s*;\s*/u', ';', $text);
        $text = preg_replace('/;{2,}/u', ';', $text);
        $text = trim($text, '; ');
    }
    return trim((string)$text);
}

/**
 * Normalize an attribute tail into GOLDEN RULE 3's "ATTR; ATTR; ATTR" list:
 * trimmed segments, one separator between them, no empties.
 *
 * PHP never INVENTS a separator here. Deciding where one attribute ends and the
 * next begins is the same language judgment as word order -- "CORE I7 32GB RAM
 * 1TB SSD" has to become "CORE I7; 32GB RAM; 1TB SSD" and no token-level rule
 * gets that right -- so the split comes from the AI (see md_gr_nma_instruction)
 * and this only enforces the shape of what comes back.
 */
function md_gr_format_attributes(string $tail): string {
    $segments = [];
    foreach (explode(';', $tail) as $segment) {
        $segment = trim(preg_replace('/\s+/u', ' ', $segment));
        if ($segment !== '') {
            $segments[] = $segment;
        }
    }
    return implode(md_gr_segment_separator(), $segments);
}

/**
 * The one place the SKU segment separator is spelled out. RULE 2 makes the
 * semicolon the sole structural delimiter; every worked example in the protocol
 * writes it with a single trailing space ("NOTEBOOK DESIGNER; 14.5 INCH; RAM
 * 32GB"), so that is the stored form.
 */
function md_gr_segment_separator(): string {
    return '; ';
}

/**
 * May this token stand in the FIRST SEGMENT (the merged noun and modifier), or
 * is it an attribute that belongs in a segment of its own?
 *
 * md_gr_is_spec_token() is too blunt for this job: it calls every bare number a
 * spec, which is right for level_4 but would tear "GEN 2", "LAYER 3" and
 * "WINDOWS 11" apart and strand the number as its own attribute. A short bare
 * number reads as a generation/version designator and stays with its noun; a
 * long one is a model or a capacity and gets its own segment, which is what keeps
 * legacy junk like the "4500" in "FINGERPRINT SCANNER MINI U ARE U 4500" out of
 * the modifier block.
 */
function md_gr_is_modifier_token(string $token): bool {
    if (!md_gr_is_spec_token($token)) {
        return true;
    }
    // A bare number of one or two digits is a designator, not a measurement.
    return (bool)preg_match('/^\d{1,2}$/u', $token);
}

/**
 * Repair decimal points that an earlier version of md_gr_clean_symbols() split
 * into two numbers ("2.5MM" stored as "2 5MM"), using the raw original
 * description -- which GOLDEN RULE 1 keeps intact -- as the source of truth.
 *
 * Only a split that the original actually contradicts is repaired: for every
 * "<digits>.<digits>" in the original, the same digits separated by a space are
 * rejoined in $text. Anything the original does not vouch for is left alone, so
 * a genuine "GEN 2 2510M" is never turned into "2.2510M".
 */
function md_gr_restore_decimals($text, $original): string {
    $text = (string)$text;
    if ($text === '' || trim((string)$original) === '') {
        return $text;
    }
    if (!preg_match_all('/(\d+)\.(\d+)/u', (string)$original, $m, PREG_SET_ORDER)) {
        return $text;
    }
    foreach ($m as $pair) {
        $text = preg_replace(
            '/(?<!\d)' . preg_quote($pair[1], '/') . '\s+' . preg_quote($pair[2], '/') . '(?!\d)/u',
            $pair[1] . '.' . $pair[2],
            $text
        );
    }
    return $text;
}

/**
 * Is this token a measurement or a model identity rather than a product type?
 * Used to keep specs out of level_4 (RULE 3) and to find where the attribute
 * tail of a level_5 begins.
 *
 * Any token carrying a digit qualifies -- 32GB, 16CH, 5X10M, 203DPI, XVR4216AN
 * -- and so does a bare number, which is always a count or a dimension
 * ("24 PORT", "LAYER 3"). The one exception is a short letter+digit pair, which
 * reads as product type rather than spec and is kept: 3D, 4K, A4, V2.
 */
function md_gr_is_spec_token(string $token): bool {
    if (!preg_match('/\d/u', $token)) {
        return false;
    }
    if (mb_strlen($token, 'UTF-8') > 2) {
        return true;
    }
    // Two characters or fewer: a spec only if there is no letter alongside the
    // digit, so "3D" survives and "24" does not.
    return !preg_match('/\p{L}/u', $token);
}

/**
 * Split a cleaned description into the Standardized Core and the attribute tail
 * that GOLDEN RULE 3 builds level_4 and level_5 from.
 *
 * The core is the first run of non-spec tokens, capped at 6 words so a whole
 * sentence can't become a category. Leading spec tokens are skipped rather than
 * ending the search: plenty of descriptions open with a part number
 * ("ZT23142-T0P000FZ TT PRINTER ZT231"), and stopping there would leave no core
 * at all. Everything outside the core run is the attribute tail.
 *
 * Deliberately shallow: this cannot tell a brand from a product type without a
 * brand list, so "PDT UROVO CT58S" yields the core "PDT UROVO". It is the
 * fallback for when the AI did not propose a category, not the primary path --
 * see md_enrich_master_data().
 */
function md_gr_split_core_attributes(string $clean): array {
    $tokens = $clean === '' ? [] : preg_split('/\s+/u', $clean);
    $core = [];
    $rest = [];
    $done = false;
    foreach ($tokens as $token) {
        $is_spec = md_gr_is_spec_token($token);
        if (!$done && !$is_spec && count($core) < 6) {
            $core[] = $token;
            continue;
        }
        // The core run has ended once a spec token follows it.
        if (!empty($core)) {
            $done = true;
        }
        $rest[] = $token;
    }
    return ['core' => implode(' ', $core), 'attributes' => implode(' ', $rest)];
}

/**
 * Normalize a proposed category into a level_4 that satisfies RULE 3: product
 * type only, uppercase, no symbols, no specs, "JASA " prefixed when the item is
 * a service (RULE III).
 *
 * $service_hint carries the intent of the ORIGINAL description, because the
 * proposed name alone may have had its service wording stripped already.
 *
 * Returns '' when nothing usable survives, which callers treat as "no category".
 */
function md_standardize_category_name($name, $service_hint = null): string {
    $clean = md_gr_clean_symbols(md_gr_strip_codes($name));
    if ($clean === '') {
        return '';
    }
    $clean = mb_strtoupper($clean, 'UTF-8');

    // RULE 3: the anchor holds no capacities, dimensions or model numbers.
    $kept = array_filter(preg_split('/\s+/u', $clean), function($token) {
        return !md_gr_is_spec_token($token);
    });
    $clean = trim(implode(' ', $kept));
    if ($clean === '') {
        return '';
    }

    // RULE III: prefix once, never twice.
    $is_service = md_gr_is_service($service_hint !== null ? $service_hint : $name)
        || md_gr_is_service($clean);
    if ($is_service && mb_strpos($clean, 'JASA ') !== 0 && $clean !== 'JASA') {
        $clean = 'JASA ' . $clean;
    }

    // level_4 is VARCHAR(255); cut on a character boundary, not a byte one.
    return mb_substr($clean, 0, 255, 'UTF-8');
}

/**
 * Drop from an attribute tail every word the core already says, so a level_5
 * never states the same thing in two of its segments.
 *
 * Whole-token comparison only: "SYSTEM" is not removed by a core containing
 * "SYSTEMS", which keeps the rule predictable and stops it eating attributes
 * that merely resemble the core.
 */
function md_gr_strip_core_echo(string $tail, string $core): string {
    if ($tail === '' || $core === '') {
        return $tail;
    }
    $core_tokens = array_flip(preg_split('/\s+/u', $core));
    // Per attribute segment, so the separator survives and a segment boundary is
    // never mistaken for part of a word ("2;2.5MM" is two tokens, not one).
    $segments = [];
    foreach (explode(';', $tail) as $segment) {
        $kept = [];
        foreach (preg_split('/\s+/u', $segment) as $token) {
            if ($token !== '' && !isset($core_tokens[$token])) {
                $kept[] = $token;
            }
        }
        $segments[] = implode(' ', $kept);
    }
    // A segment emptied by the strip is dropped rather than left as ";;".
    return md_gr_format_attributes(implode(';', $segments));
}

/**
 * Sanitize a level_5 that was already written in the Scenario A format, rather
 * than rebuilding it from the description.
 *
 * The AI is asked for "NOUN MODIFIER; ATTR; ATTR" outright and its segmentation is
 * normally better than anything reassembled from raw text -- but its CORE is not
 * authoritative. RULE 3 is explicit that the level_5 core matches level_4, and
 * Finance groups spend by ignoring everything after the dash, so a core that
 * drifts from level_4 silently breaks that grouping. Real drift this caught:
 * level_4 "JASA SECURITY SYSTEM MATERIAL" against level_5 "SECURITY SYSTEM
 * MATERIAL - INSTALLATION", the JASA prefix (RULE III) missing from the core.
 *
 * So $core LEADS outright. What is left of the writer's head after the core's own
 * words are taken out is not thrown away, though: Scenario A MERGES the noun and
 * its modifier into the very first segment, with no punctuation between them, so
 * those leftover words stay in that segment in the order the writer had them --
 * "SENSOR PEOPLE COUNTING" plus a head of "SENSOR PEOPLE COUNTING 3D GEN 2"
 * gives "SENSOR PEOPLE COUNTING 3D GEN 2; ...". Only the leftovers that are
 * measurements or model identities are demoted to the front of the attribute
 * list (md_gr_is_modifier_token), which is what stops "FINGERPRINT MINI SOLUTION
 * U ARE U" + "FINGERPRINT SCANNER MINI U ARE U 4500" putting its 4500 in the
 * first segment.
 *
 * NO DASH ANYWHERE. RULE 3 prohibits dashes and parentheses at segment level, so
 * the " - " that older names used between core and attributes is converted to a
 * segment break on the way in, and hyphens inside model codes stay flattened to
 * spaces by md_gr_clean_symbols(). Segments are joined by RULE 2's semicolon,
 * which is the sole structural delimiter.
 */
function md_sanitize_hybrid_name($text, string $core = ''): string {
    // Legacy names separated core from attributes with " - ", and Scenario A
    // prohibits that dash, so it becomes a segment break before anything is
    // parsed. Doing it here rather than in md_gr_clean_symbols() keeps a hyphen
    // INSIDE a model code ("RG-S5000-24GT4MS") flattening to a space as before,
    // instead of exploding one attribute into several.
    $text     = (string)$text;
    $prepared = preg_replace('/\s+-\s+/u', ';', $text);
    $clean    = mb_strtoupper(md_gr_clean_symbols(md_gr_strip_codes($prepared), true), 'UTF-8');

    $segments = ($clean === '') ? [] : explode(';', $clean);
    $head     = trim((string)array_shift($segments));
    $tail     = md_gr_format_attributes(implode(';', $segments));
    $core     = mb_strtoupper(trim($core), 'UTF-8');

    // No level_4 to anchor to: the writer's own head has to serve as the core.
    if ($core === '') {
        $core = $head;
        $head = '';
    }
    if ($core === '') {
        return '';
    }

    // RULE III applies to the SKU too ("prepend JASA to the Level 4 Standardized
    // name and the first segment of SKU Level 5"). Without this, a service whose
    // level_4 is missing the prefix demoted the word into the attribute list and
    // produced "MANAGED PRINT SERVICE; JASA" -- the tax flag reduced to a stray
    // attribute. The service test reads the ORIGINAL name, since the level_4
    // being anchored to is exactly the value that may have lost it.
    $is_service = md_gr_is_service($text) || md_gr_is_service($core);
    if ($is_service && mb_strpos($core, 'JASA ') !== 0 && $core !== 'JASA') {
        $core = 'JASA ' . $core;
    }

    // Split the head's leftovers: modifiers merge into the first segment behind
    // the core, measurements and model identities go to the front of the
    // attribute list.
    $core_tokens = array_flip(preg_split('/\s+/u', $core));
    $modifiers = [];
    $demoted   = [];
    foreach (($head === '' ? [] : preg_split('/\s+/u', $head)) as $token) {
        if ($token === '' || isset($core_tokens[$token])) {
            continue;
        }
        if (md_gr_is_modifier_token($token)) {
            $modifiers[] = $token;
        } else {
            $demoted[] = $token;
        }
    }

    // Scenario A's first segment: noun and modifier merged, no punctuation
    // between them.
    $segment_1 = trim($core . ' ' . implode(' ', $modifiers));
    if (!empty($demoted)) {
        $tail = md_gr_format_attributes(implode(' ', $demoted) . ';' . $tail);
    }

    $tail = md_gr_strip_core_echo($tail, $segment_1);
    $name = ($tail === '') ? $segment_1 : $segment_1 . md_gr_segment_separator() . $tail;
    return mb_substr(trim($name), 0, 255, 'UTF-8');
}

/**
 * Build the level_5 leaf: the Hybrid SKU Format from RULE 3, "CORE - ATTRIBUTES".
 *
 * Deterministic on purpose -- the same description and core always produce the
 * same level_5, so the (level_4, level_5) unique key turns a re-import of the
 * same item into a no-op instead of piling up near-identical suggestions.
 *
 * Exactly one dash is emitted, and only when there is an attribute tail to put
 * after it; an item with no specs stays a bare core ("JASA MANAGED PRINT
 * SERVICE"). When $core is empty the description's own core/attribute split is
 * used instead.
 *
 * Returns '' when nothing usable is left, which callers treat as "can't enrich".
 */
function md_standardize_item_name($description, $core = ''): string {
    $clean = md_gr_clean_symbols(md_gr_strip_codes($description));
    if ($clean === '') {
        return '';
    }
    $clean = mb_strtoupper($clean, 'UTF-8');
    $core  = mb_strtoupper(trim((string)$core), 'UTF-8');

    // What to look for inside the description. RULE III may have put a "JASA "
    // prefix on the core that the description itself never had, so the bare core
    // is what gets searched -- otherwise the match fails and the entire
    // description is repeated after the dash.
    $probe = (mb_strpos($core, 'JASA ') === 0)
        ? trim(mb_substr($core, 5, null, 'UTF-8'))
        : $core;

    if ($core === '') {
        $split = md_gr_split_core_attributes($clean);
        $core  = $split['core'];
        $tail  = $split['attributes'];
    } elseif ($probe !== '' && mb_strpos($clean, $probe) === 0) {
        // The description already opens with the core -- don't say it twice.
        $tail = trim(mb_substr($clean, mb_strlen($probe, 'UTF-8'), null, 'UTF-8'));
    } elseif ($probe !== '' && ($at = mb_strpos($clean, $probe)) !== false) {
        // The core sits mid-description (a part number came first); lift it out
        // so it isn't repeated inside the attribute tail.
        $tail = trim(
            mb_substr($clean, 0, $at, 'UTF-8') . ' '
            . mb_substr($clean, $at + mb_strlen($probe, 'UTF-8'), null, 'UTF-8')
        );
        $tail = preg_replace('/\s+/u', ' ', $tail);
    } else {
        $tail = $clean;
    }

    // The core is level_4 and the tail must not restate it -- see
    // md_gr_strip_core_echo(). Matters most when the core words are scattered
    // through the description rather than sitting together at the front.
    $tail = md_gr_strip_core_echo($tail, $core);

    $name = ($core === '') ? $tail : ($tail === '' ? $core : $core . md_gr_segment_separator() . $tail);
    // level_5 is VARCHAR(255); cut on a character boundary, not a byte one.
    return mb_substr(trim($name), 0, 255, 'UTF-8');
}

/**
 * Write a discovered item back into Master Data as a pending suggestion.
 *
 * The row is created with status 'ai_suggested' and an EMPTY pic_names: it must
 * not start routing tickets before a Category Manager has looked at it. The PIC
 * of the closest existing category rides along in suggested_pic_names instead,
 * so the Suggestions tab shows it as a proposal ("Suggested PIC: … By: System")
 * with the usual approve/edit buttons, exactly like a human-submitted SKU
 * suggestion.
 *
 * LEVEL 4 IS MINTED FRESH, NOT INHERITED. This function used to file the new
 * leaf under the closest existing category, which reproduced the exact drift the
 * feature exists to stop: a weak match means the item does NOT belong to that
 * neighbour, so hanging it there just buried the mismatch one level deeper. It
 * also produced categories like "10470775 CCTV CVI COOPER KIT 2MP 8CH 4AM KRIS"
 * -- the neighbour's own unclean level_4, copied forward. The category is now
 * built for the item itself, in preference order:
 *   1. the AI's proposed new category, when it returned one
 *   2. the product-type core derived from the description
 *   3. the closest match's category, only as a last resort when both are blank
 * Only level_0..level_3 are inherited, as taxonomy context for the reviewing
 * Category Manager to confirm or correct.
 *
 * Idempotent: the (level_4, level_5) unique key turns a repeat of the same item
 * into a no-op rather than a duplicate or an error.
 *
 * @param array $branch    level_0..level_4 of the closest Master Data match
 * @param array $proposal  optional AI proposal: ['level_4' => …, 'level_5' => …]
 * @param ?string $ticket_no  the PRS ticket_no that triggered this write, when
 *                            known. Recorded into ticket_ids (comma-separated,
 *                            deduped) so a reviewer can trace this row back to
 *                            its source ticket(s) in K2/PRS. Accumulates across
 *                            calls: a later ticket matching the same existing
 *                            (level_4, level_5) row gets appended, not just the
 *                            first one that created the row.
 * @return int|null the new master_data.id, or null when nothing was written
 *                  (already known, unusable name, or the insert failed)
 */
function md_enrich_master_data(
    PDO $pdo,
    array $branch,
    $item_description,
    $confidence = null,
    ?string $suggested_pic = null,
    string $suggested_by = 'System',
    array $proposal = [],
    ?string $ticket_no = null
): ?int {
    $level_4 = md_standardize_category_name($proposal['level_4'] ?? '', $item_description);
    if ($level_4 === '') {
        $split   = md_gr_split_core_attributes(
            mb_strtoupper(md_gr_clean_symbols(md_gr_strip_codes($item_description)), 'UTF-8')
        );
        $level_4 = md_standardize_category_name($split['core'], $item_description);
    }
    if ($level_4 === '') {
        $level_4 = md_standardize_category_name($branch['level_4'] ?? '', $item_description);
    }

    // A level_5 the AI wrote to the golden-rule format is better than one
    // reassembled from the raw description, so it is kept as written and only
    // sanitized -- the AI is not trusted to have kept symbols out, but it is
    // trusted to have picked the core/attribute split.
    $proposed_5 = trim((string)($proposal['level_5'] ?? ''));
    $level_5 = ($proposed_5 !== '')
        ? md_sanitize_hybrid_name($proposed_5, $level_4)
        : md_standardize_item_name($item_description, $level_4);

    // No leaf name, or no category to hang it under, means there is nothing
    // reviewable to write — a row keyed on a blank pair would collide with
    // every other such row anyway.
    if ($level_5 === '' || $level_4 === '') {
        return null;
    }
    // The description is already the category itself — that is a match, not a
    // new leaf.
    if (mb_strtoupper($level_4, 'UTF-8') === $level_5) {
        return null;
    }

    // The unique key below is (level_4, level_5), but level_5 is reworded by
    // the AI fresh on every run -- the same recurring item comes back phrased
    // differently each time it's re-evaluated, so that key alone lets the same
    // item mint a new row on every batch that re-encounters it (it's never
    // recognized as a repeat because a still-pending suggestion isn't in the
    // "approved categories" list the AI is shown next time). requested_item_name
    // is a straight, un-reworded copy of item_description, so it stays stable
    // across those repeats -- check it first and reuse whatever row already
    // exists for this exact PR item instead of minting a sibling.
    $requested_name = mb_substr(trim((string)$item_description), 0, 255, 'UTF-8');
    if ($requested_name !== '') {
        $findStmt = $pdo->prepare(
            "SELECT id FROM master_data WHERE requested_item_name = ? LIMIT 1"
        );
        $findStmt->execute([$requested_name]);
        $existing_id = $findStmt->fetchColumn();
        if ($existing_id) {
            md_append_ticket_id($pdo, (int)$existing_id, $ticket_no);
            return null;
        }
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO master_data
                (level_0, level_1, level_2, level_3, level_4, level_5, pic_names,
                 suggested_pic_names, suggested_by, status, ai_confidence, requested_item_name, ticket_ids,
                 created_by, last_modified_by)
             VALUES (?, ?, ?, ?, ?, ?, '', ?, ?, 'ai_suggested', ?, ?, ?, 'ai', 'ai')
             ON DUPLICATE KEY UPDATE
                ticket_ids = CASE
                    WHEN ? = '' THEN ticket_ids
                    WHEN FIND_IN_SET(?, ticket_ids) > 0 THEN ticket_ids
                    ELSE CONCAT_WS(',', ticket_ids, ?)
                END"
        );
        $ticket_ids_value = ($ticket_no !== null && trim($ticket_no) !== '') ? trim($ticket_no) : null;
        $ticket_no_check  = (string)($ticket_no ?? '');
        $stmt->execute([
            (string)($branch['level_0'] ?? ''),
            (string)($branch['level_1'] ?? ''),
            (string)($branch['level_2'] ?? ''),
            (string)($branch['level_3'] ?? ''),
            $level_4,
            $level_5,
            ($suggested_pic !== null && trim($suggested_pic) !== '') ? trim($suggested_pic) : null,
            $suggested_by,
            $confidence !== null ? (int)$confidence : null,
            $requested_name,
            $ticket_ids_value,
            $ticket_no_check,
            $ticket_no_check,
            $ticket_no_check,
        ]);

        // affected-rows is 1 for a genuine new INSERT, 0 when ON DUPLICATE KEY
        // fired but changed nothing, and 2 when it fired and updated ticket_ids
        // (an existing pending/approved row gained a new source ticket). Only a
        // real new row should be reported to callers as "something was written".
        if ($stmt->rowCount() !== 1) {
            return null;
        }
        $new_id = (int)$pdo->lastInsertId();
        if ($new_id > 0) {
            md_audit_log_insert($pdo, $new_id, MD_AUDIT_ACTOR_AI);
            return $new_id;
        }
        return null;
    } catch (\PDOException $e) {
        // Enrichment is a side effect of mapping, never the point of it: a
        // failure here must not take down an import or a bulk mapping run.
        error_log('Master Data enrichment insert failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Force a level_5 into the house Hybrid SKU Format before it is stored: the
 * Standardized Core (which is level_4 — the noun + modifier), a single dash,
 * then the critical technical attributes. GOLDEN RULE 3.
 *
 * Applied on EVERY write path that lets a human type level_5 (the single-row
 * edit forms in pages/master_data.php, and the CSV import), because a
 * hand-typed level_5 drifts exactly the same way the AI's does — a core that
 * no longer matches level_4 silently breaks the Finance grouping that ignores
 * everything after the dash, and stray semicolons/commas (RULE 2) corrupt the
 * CSV and ERP exports.
 *
 * A blank level_5 stays blank: plenty of taxonomy rows are category-only, and
 * defaulting them to the level_4 name would invent leaves nobody asked for.
 */
function md_l5_golden_rule(string $l5, string $l4): string {
    $l5 = trim($l5);
    if ($l5 === '') {
        return '';
    }
    $l4 = trim($l4);
    // A SKU request that hasn't been categorised yet carries a placeholder
    // level_4 ("pending_<uniqid>", see the Submit New SKU flow). That is not a
    // category and must never become the core of a name; with no core the
    // level_5's own head is used instead.
    if (stripos($l4, 'pending_') === 0) {
        $l4 = '';
    }
    $standard = md_sanitize_hybrid_name($l5, $l4);
    return $standard !== '' ? $standard : $l5;
}

/**
 * The set of names a pic_names cell is allowed to reference — the same list
 * the Taxonomy tab's PIC multi-select is built from (pages/master_data.php).
 * Kept here so the CSV import validator checks against the identical
 * source of truth rather than a second copy of this query.
 */
function md_valid_pic_names(PDO $pdo): array {
    $stmt = $pdo->query("SELECT name FROM users WHERE role IN ('PIC', 'Admin') ORDER BY name ASC");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Validate and normalize a raw pic_names cell ("John, Jane") against the valid
 * PIC list. Returns ['value' => normalized string, 'invalid' => [bad names]].
 * An empty/whitespace-only cell yields a valid empty value with no invalid
 * names — callers that require pic_names to be non-blank check that
 * separately, same as they check any other required field.
 */
function md_validate_pic_names(string $raw, array $valid_pics): array {
    $lookup = [];
    foreach ($valid_pics as $name) {
        $lookup[mb_strtolower(trim($name), 'UTF-8')] = trim($name);
    }
    $names = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn($n) => $n !== ''));
    $normalized = [];
    $invalid = [];
    foreach ($names as $name) {
        $key = mb_strtolower($name, 'UTF-8');
        if (isset($lookup[$key])) {
            $normalized[] = $lookup[$key];
        } else {
            $invalid[] = $name;
        }
    }
    return ['value' => implode(', ', array_unique($normalized)), 'invalid' => $invalid];
}

/**
 * Appends a PRS ticket_no onto an existing master_data row's ticket_ids list
 * (comma-separated, deduped) -- regardless of that row's status or origin.
 * Unlike md_enrich_master_data(), which only ever writes newly-created
 * ai_suggested rows, this covers every path that links a ticket to a
 * master_data row: the AI's confident-match write-back, manual PR taxonomy
 * mapping (ajax_update_pr_taxonomy.php), and SLA batch mapping
 * (ajax_sla_save_taxonomy_batch.php). Safe/cheap to call redundantly -- a
 * repeat of the same ticket on the same row is a no-op via FIND_IN_SET.
 */
function md_append_ticket_id(PDO $pdo, int $master_data_id, ?string $ticket_no): void {
    if ($master_data_id <= 0 || $ticket_no === null || trim($ticket_no) === '') {
        return;
    }
    $ticket_no = trim($ticket_no);
    try {
        $stmt = $pdo->prepare(
            "UPDATE master_data SET ticket_ids = CASE
                WHEN ticket_ids IS NULL OR ticket_ids = '' THEN ?
                WHEN FIND_IN_SET(?, ticket_ids) > 0 THEN ticket_ids
                ELSE CONCAT_WS(',', ticket_ids, ?)
            END
            WHERE id = ?"
        );
        $stmt->execute([$ticket_no, $ticket_no, $ticket_no, $master_data_id]);
    } catch (\PDOException $e) {
        // A tracing side effect must never take down the mapping it is
        // recording -- same principle as md_enrich_master_data() above.
        error_log('Master Data ticket_ids append failed: ' . $e->getMessage());
    }
}

}
