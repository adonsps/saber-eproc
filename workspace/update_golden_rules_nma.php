<?php
// workspace/update_golden_rules_nma.php
//
// Install the authoritative MASTER TAXONOMY AND SKU NAMING PROTOCOL into the
// ai_golden_rules table, replacing whatever is stored there.
//
// WHY THIS IS NOT JUST A CODE CHANGE. ai_golden_rules.rule_text is fed to Gemini
// verbatim as its system instruction, alongside md_gr_scenario_a_instruction()
// from includes/master_data_enrichment.php. The two have to say the same thing;
// a prompt that argues with itself gets answered inconsistently, and the stored
// text is the half a Category Manager can edit from the UI.
//
// WHAT SCENARIO A CHANGED (2026-08-05, user-supplied protocol):
//   * The DASH is prohibited. level_5 no longer has a "core - attributes" split;
//     the noun and its modifier are MERGED into the first segment.
//   * RULE 2 inverted: it used to ban semicolons, it now bans COMMAS and makes
//     the semicolon the sole delimiter. Commas are what shift columns and split
//     rows in a CSV extraction; a semicolon keeps the whole description locked
//     inside one cell.
//   * Parentheses are prohibited at segment level, and level_5 is ALL CAPS.
//
// This writes the protocol WHOLE rather than patching blocks: the previous
// version of this script did block surgery against the old wording, which cannot
// express a rewrite this broad and would silently half-apply.
//
// Idempotent: a second run finds the text already identical and does nothing.
// The current rule_text is backed up to db_backups/local_runtime_exports/ before
// anything is written, and that file is a straight UPDATE that restores it.
//
// Usage (command line only -- see the guard below):
//   php update_golden_rules_nma.php            # dry run, reports what would change
//   php update_golden_rules_nma.php --apply    # writes it

// COMMAND LINE ONLY. This file lives under the web root; the golden rules steer
// every AI naming decision in the app, so a visitor must not be able to rewrite
// them by requesting a URL.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config/database.php';

$apply = in_array('--apply', $argv ?? [], true);
$nl = "\n";

// Verbatim as supplied. Nowdoc, so nothing in it is interpolated.
$PROTOCOL = <<<'TXT'
MASTER TAXONOMY AND SKU NAMING PROTOCOL

I. CORE DATA ARCHITECTURE (6-LEVEL HIERARCHY)

Every new or raw item must be strictly mapped into the following 6-Tier Data Architecture without exception:

Level 0 (Tower): Macro business domain (e.g., Technology, Industrial, and General Services; Corporate Services, General Supplies & Marketing; FNB and Operational Merchandise).

Level 1 (Category): Broad industrial classification.

Level 2 (Sub-Category): Tactical classification of goods/services.

Level 3 (Commodity): Specific procurement commodity bucket.

Level 4 Standardized: Macro universal category used as an anchor for financial analysis (spend analysis) and taxation rules. Must be completely free of brands, sizes, locations, and timeframes.

Level 5 (SKU Detail Description): Specific component or variant detailing operational, inventory control, and commercial needs. Must follow the Semicolon-Delimited Scenario A Format (Merged Noun-Modifier + Semicolon-Separated Attribute Suffix) and MUST BE WRITTEN IN ALL CAPITAL LETTERS.

Item Original: The raw description from the original PO or ERP system or requested SKU from user.

II. THE GOLDEN RULES (NON-NEGOTIABLE)

RULE 1: ABSOLUTE PRESERVATION OF ITEM ORIGINAL
Never alter, truncate, shorten, or modify the Item Original column by even a single character. It is strictly forbidden to cut strings using ellipses (...). The original description must be written 100% intact for operational audit and legal transaction tracking purposes.

RULE 2: STRICT BAN ON COMMAS IN SKU (LEVEL 5) — MANDATORY SEMICOLON DELIMITERS
The SKU (Level 5) column must not contain any commas (,). All commas from the original description must be removed or replaced with semicolons (;). Semicolons must be utilized as the sole structural delimiter to separate subsequent attributes.
System Guardrail: Commas corrupt data structures, causing severe column-shifting or row-splitting errors during CSV extractions. Semicolons are globally recognized by database parsers as non-delimiting text characters in standard CSV files, meaning they keep the entire Level 5 description securely locked within a single cell.

RULE 3: STANDARDIZATION & ATTRIBUTE DISCIPLINE

Level 4 Standardized (The Anchor): Must strictly contain the Product Type only. Remove ALL brands, sizes, dimensions, colors, and technical specs.

SKU (Level 5) (Scenario A): To streamline data queries and prevent search paralysis, the primary Noun and Modifier are merged into the very first text segment of the SKU, with no punctuation separating them. All subsequent technical attributes (e.g., Capacity, Size, Color, Volume, or Power) must follow and be separated strictly by semicolons (;). The entire SKU string must be written in ALL CAPITAL LETTERS. Semicolons are used as the only delimiter. Parentheses and dashes are prohibited at the segment level.

Correct SKU Level 5 Example: NOTEBOOK DESIGNER; 14.5 INCH; RAM 32GB; SSD 512GB; VGA 6GB; WINDOWS 11 PRO

Correct SKU Level 5 Example: RAM SODIMM; 16GB; DDR5; PC4800; LENOVO THINKPAD E14 GEN6

Correct SKU Level 5 Example: JASA UJI LAB BPOM; INCL. PENDAFTARAN DESAIN

Incorrect SKU Level 5 (Commas): NOTEBOOK, DESIGNER, 14.5 INCH, RAM 32GB (Will break CSV column mapping)

Incorrect SKU Level 5 (Brackets/Dashes): NOTEBOOK (DESIGNER) - 14.5 INCH; RAM 32GB (Brackets break database search queries)

III. TAXATION LOGIC (THE "JASA" FLAG RULE)
To enable the Finance Team to automatically deduct withholding tax (PPh Pasal 23/21) within the ERP system:

Service Criteria: If the Item Original description involves a service, repair, installation, rental, cleaning, printing, license renewal, or any service-based procurement method (keywords: Jasa, Service, Installation, Instalasi, Renewal, Sewa, Cetak, Customization, Refill, Maintenance, Bongkar Pasang).

Action: Prepend the word JASA to the Level 4 Standardized name and the first segment of SKU Level 5.

Examples: JASA SOFTWARE LICENSE, JASA REFILL APAR, JASA PROGRAMMATIC ADS.

If the item is a pure physical goods purchase without vendor services, do not use the prefix "JASA".

IV. CLASSIFICATION FORKING (CONTEXT-DRIVEN TAXONOMY)
The AI must never map items based purely on raw text matching; it must actively analyze the Context of Use (Intent), Technical Capacity, and the Managing Procurement Team. The same item type can belong to different Towers depending on its deployment in the field.

Forking Guide 1 (Shredders):

Standard office/HR document shredder → Tower: Corporate Services → Commodity: Office Equipment & Supplies.

High-security data destruction / IT hard drive shredder → Tower: Technology → Commodity: Peripheral.

Industrial capacity / DC logistics cardboard waste processing → Tower: Technology/Industrial → Commodity: Warehouse/Industrial Equipment.

Forking Guide 2 (Stickers / Labels / Tissue):

Applied directly to consumer sales cups/packaging → Tower: FNB Merchandise → Sub-Cat: Packaging / Amenities.

Promotional flyers or event stickers → Tower: Corporate Services → Cat: Marketing.

Restroom tissue or logistics printer barcode labels → Tower: Corporate Services → Commodity: Office Equipment & Supplies.

V. SHARED SKU & COMMERCIAL TIERING PARADIGM
When handling a single service item type that exhibits massive price variance due to geography (e.g., Billboard rental in Jakarta is extremely expensive vs. Surakarta which is significantly cheaper):

Master Data Solution: Do not split the SKU by city name (e.g., Billboard_Jakarta, Billboard_Solo). Use a single Shared SKU (BILLBOARD ADVERTISING PLACEMENT), as the ERP system tracks regionality via secondary data dimensions (Cost Center / Region Tag).

Commercial Tiering Approach: If the ERP reporting system is limited and price trends at the SKU level must look stable, split the SKU by Strategic Class / Commercial Value rather than location:

Semicolon-Standardized SKU Format: JASA PENYEDIAAN MEDIA BILLBOARD; JL LAKSDA ADISUCIPTO YOGYAKARTA; UK. 5X10M 1 SISI; PERIODE SEWA 12 BULAN

VI. CUMULATIVE KNOWLEDGE BASE (UNIVERSAL STANDARDS)
Consistently use the following Level 4/SKU standardization keywords:

Notebook / Laptop / Macbook → Commodity: End User Computing | Level 4: LAPTOP | SKU Level 5 Naming: LAPTOP [MODIFIER]; [ATTRIBUTES...]

Mini PC / Desktop → Commodity: End User Computing | Level 4: MINI PC | SKU Level 5 Naming: MINI PC [MODIFIER]; [ATTRIBUTES...]

Printer Eco Tank / Ink Tank → Level 4: PRINTER INKJET.

Printer Dot Matrix / TT Printer / Zebra → Level 4: PRINTER DOT MATRIX.

CCTV / Dome Camera / Siren / Keypad / Alarm / Deactivator → Commodity: Enclosures, Racks & Security Equipment.

SaaS / Subscription-based Applications → Commodity: Software License/Maintenance/SaaS | Level 4: JASA SOFTWARE LICENSE.

VII. AI COGNITIVE EXECUTION EXAMPLE
Raw Input Data:  [AON] ZT23142-T0P000FZ; TT PRINTER ZT231; 4", 203 DPI, TEAR, USB
AI Logic Breakdown:

Intent Analysis: Industrial thermal transfer barcode printer.

Cleaning: Strip bracket codes, Zebra brand, model ZT231, 4" size, DPI, and cord variants.

Scenario A formatting: Merged Noun-Modifier (PRINTER DOT MATRIX INDUSTRIAL THERMAL TRANSFER), followed by semicolon-separated technical attributes. No commas or dashes are permitted.

Final Output:

Level 0: Technology, Industrial, and General Services

Level 1: Technology

Level 2: Hardware

Level 3: Peripheral

Level 4 Standardized: PRINTER DOT MATRIX

SKU (Level 5): PRINTER DOT MATRIX INDUSTRIAL THERMAL TRANSFER; 4 INCH; 203 DPI

Item Original:  [AON] ZT23142-T0P000FZ; TT PRINTER ZT231; 4", 203 DPI, TEAR, UK/AU/JP/EU CORDS, USB, SER, ETHER, BTLE, USB HOST, EZPL
TXT;

try {
    $rows = $pdo->query("SELECT id, rule_text FROM ai_golden_rules ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "ai_golden_rules is empty -- inserting the protocol as row 1." . $nl;
        if ($apply) {
            $pdo->prepare("INSERT INTO ai_golden_rules (rule_text) VALUES (?)")->execute([$PROTOCOL]);
            echo "Inserted." . $nl;
        } else {
            echo "DRY RUN -- nothing was written. Re-run with --apply to commit." . $nl;
        }
        return;
    }
    if (count($rows) > 1) {
        // Every prompt reads row 1 only; a second row would be dead text that
        // still looks authoritative to whoever opens the editor.
        echo "  WARNING: ai_golden_rules has " . count($rows) . " rows. Only the lowest id is the live protocol." . $nl;
    }

    $target = $rows[0];
    // The stored copy is authored through the web UI and comes back CRLF, so a
    // raw !== against an LF nowdoc would report a difference on every run.
    $normalize = function ($s) {
        return str_replace("\r\n", "\n", rtrim((string)$s));
    };
    if ($normalize($target['rule_text']) === $normalize($PROTOCOL)) {
        echo "Nothing to do -- row {$target['id']} already holds this protocol." . $nl;
        return;
    }

    echo "Row {$target['id']}: " . strlen((string)$target['rule_text']) . " chars -> " . strlen($PROTOCOL) . " chars." . $nl;
    echo "  RULE 2 currently reads: " . (strpos((string)$target['rule_text'], 'RULE 2: STRICT BAN ON COMMAS') !== false
        ? 'ban on commas (already Scenario A)'
        : 'something else -- being replaced') . $nl;

    $export_dir = __DIR__ . '/../db_backups/local_runtime_exports';
    if (!is_dir($export_dir)) { @mkdir($export_dir, 0777, true); }
    $backup_path = $export_dir . '/ai_golden_rules_backup_' . date('Ymd_His') . '.sql';
    file_put_contents(
        $backup_path,
        "-- Backup of ai_golden_rules before update_golden_rules_nma.php, generated " . date('c') . "\n"
        . sprintf("UPDATE ai_golden_rules SET rule_text = %s WHERE id = %d;\n", $pdo->quote($target['rule_text']), $target['id'])
    );
    echo "Backup written to: " . $backup_path . $nl;

    if (!$apply) {
        echo $nl . "DRY RUN -- nothing was written. Re-run with --apply to commit." . $nl;
        return;
    }

    $pdo->prepare("UPDATE ai_golden_rules SET rule_text = ? WHERE id = ?")->execute([$PROTOCOL, $target['id']]);
    echo $nl . "Applied. Row {$target['id']} now holds the MASTER TAXONOMY AND SKU NAMING PROTOCOL." . $nl;
} catch (PDOException $e) {
    echo "Error updating the golden rules: " . $e->getMessage() . $nl;
}
