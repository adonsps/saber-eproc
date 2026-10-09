<?php
declare(strict_types=1);

// Generates minimal, genuinely valid XLSX fixtures (real ZIP/OOXML bytes, not
// placeholder strings) for the HTTP harness, so client.js exercises the real
// worker's normalize -> preflight -> business-write path instead of failing on
// a non-parseable upload. Synthetic data only, one row per dataset — never
// pulls from Drive or production. Output directory is passed as argv[1].

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../../../workspace/vendor/autoload.php';
require_once __DIR__ . '/../../includes/automation/k2_file_schemas.php';
require_once __DIR__ . '/../../includes/automation/import_state.php';

use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;

$outputDir = $argv[1] ?? null;
if ($outputDir === null || trim($outputDir) === '') {
    fwrite(STDERR, "Usage: generate_fixtures.php <output_dir>\n");
    exit(1);
}
if (!is_dir($outputDir) && !mkdir($outputDir, 0750, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Could not create output directory {$outputDir}\n");
    exit(1);
}

function write_xlsx(string $path, array $headers, array $dataRow): void
{
    $writer = new Writer();
    $writer->openToFile($path);
    // The real normalizer requires a worksheet literally named "Sheet0" (the
    // Google Sheets export convention — see k2_file_schema()['worksheet']),
    // not OpenSpout's default "Sheet1".
    $writer->getCurrentSheet()->setName('Sheet0');
    $writer->addRow(Row::fromValues($headers));
    $writer->addRow(Row::fromValues($dataRow));
    $writer->close();
}

// Catalog: SKU, GROUP OWNER, AREA, DETAIL, UOM, ...
$catalogHeaders = k2_file_schema(K2ImportDatasetType::CATALOG)['headers'];
$catalogRow = array_fill(0, count($catalogHeaders), '');
$catalogRow[0] = 'HARNESS-SKU-1';
$catalogRow[1] = 'Owner';
$catalogRow[2] = 'Area';
$catalogRow[3] = 'Harness synthetic item';
$catalogRow[4] = 'PCS';
write_xlsx($outputDir . '/catalog.xlsx', $catalogHeaders, $catalogRow);

// Price: SKU, AUTO CREATE VENDOR..., VENDOR CODE, VENDOR NAME, VALIDITY FROM, VALIDITY TO, ALOKASI, ...
$priceHeaders = k2_file_schema(K2ImportDatasetType::PRICE)['headers'];
$priceRow = array_fill(0, count($priceHeaders), '');
$priceRow[0] = 'HARNESS-SKU-1';
$priceRow[2] = 'V001';
$priceRow[3] = 'Vendor';
$priceRow[4] = '2026-01-01';
$priceRow[5] = '2026-12-31';
$priceRow[6] = '1';
$priceRow[7] = '10';
$priceRow[8] = '1';
$priceRow[9] = '9';
$priceRow[14] = '1000';
write_xlsx($outputDir . '/price.xlsx', $priceHeaders, $priceRow);

// PR ticket export.
$prHeaders = k2_file_schema(K2ImportDatasetType::PR)['headers'];
$prRow = array_fill(0, count($prHeaders), '');
$prRow[0] = 'HARNESSPR1';
$prRow[1] = 'standard';
$prRow[2] = 'open';
$prRow[3] = 'PO01';
$prRow[4] = 'P001';
$prRow[5] = '1';
$prRow[6] = '1';
$prRow[8] = '2026-07-22';
$prRow[10] = 'Requestor';
$prRow[11] = 'GP001';
$prRow[12] = 'Site';
$prRow[27] = 'HARNESS-SKU-1';
$prRow[28] = 'Harness synthetic item';
$prRow[29] = '1';
$prRow[30] = 'PCS';
write_xlsx($outputDir . '/pr.xlsx', $prHeaders, $prRow);

echo "Generated harness fixtures in {$outputDir}\n";
