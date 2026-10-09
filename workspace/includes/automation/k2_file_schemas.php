<?php
declare(strict_types=1);

function k2_file_schemas(): array
{
    return [
        'pr' => [
            'label' => 'PR',
            'source_filename' => 'Report Ticket Monitoring GP Officer.xlsx',
            'fixture_filename' => 'Report Ticket Monitoring GP Officer (2).xlsx',
            'worksheet' => 'Sheet0',
            'expected_range' => 'A1:AN60794',
            'expected_total_rows' => 60794,
            'expected_column_count' => 40,
            // K2 added TICKET HELPDESK NO. as the far-right column (2026-10); files without it still validate.
            'optional_trailing_headers' => ['TICKET HELPDESK NO.'],
            'mandatory_headers' => [
                'TICKET NO.',
                'TICKET LINE ITEM',
                'P.GROUP',
                'GP OFFICER',
                'SKU',
                'ITEM DESCRIPTION',
            ],
            'headers' => [
                'TICKET NO.',
                'TICKET TYPE',
                'STATUS TICKET',
                'P.ORG',
                'P.GROUP',
                'TICKET LINE ITEM',
                'TICKET QTY',
                'TICKET QTY TO BE CANVASSING',
                'TR DATE TECHNICAL',
                'TR DATE GP',
                'SOLVER',
                'GP OFFICER',
                'SITE ALLOCATION',
                'CANVASSING NO.',
                'STATUS CANVASSING',
                'CANVASSING DATE',
                'CANVASSING DESCRIPTION',
                'CANVASSING DATE APPROVER 1',
                'CANVASSING DATE APPROVER 2',
                'CANVASSING DATE APPROVER 3',
                'CANVASSING DATE APPROVER 4',
                'CANVASSING DATE APPROVER 5',
                'CANVASSING DATE APPROVER 6',
                'PO NUMBER',
                'STATUS PO',
                'PO SENT DATE',
                'VENDOR NAME',
                'SKU',
                'ITEM DESCRIPTION',
                'QTY',
                'UOM',
                'PO AMOUNT',
                'DELIVERY DATE',
                'NEW STORE / OPERATIONAL',
                'REMARKS',
                'DO DATE',
                'GR DATE',
                'QR CODE SCAN DATE',
                'AUTO CREATE PO BASED ON VENDOR CONTRACT',
                'TICKET HELPDESK NO.',
            ],
            'date_headers' => [
                'TR DATE TECHNICAL',
                'TR DATE GP',
                'CANVASSING DATE',
                'CANVASSING DATE APPROVER 1',
                'CANVASSING DATE APPROVER 2',
                'CANVASSING DATE APPROVER 3',
                'CANVASSING DATE APPROVER 4',
                'CANVASSING DATE APPROVER 5',
                'CANVASSING DATE APPROVER 6',
                'PO SENT DATE',
                'DELIVERY DATE',
                'DO DATE',
                'GR DATE',
                'QR CODE SCAN DATE',
            ],
            'identifier_headers' => [
                'TICKET NO.',
                'TICKET LINE ITEM',
                'P.GROUP',
                'GP OFFICER',
                'SKU',
            ],
            'numeric_headers' => [
                'TICKET QTY',
                'TICKET QTY TO BE CANVASSING',
                'QTY',
                'PO AMOUNT',
            ],
        ],
        'catalog' => [
            'label' => 'Master Item Catalog',
            'source_filename' => 'Master Item Catalog.xlsx',
            'fixture_filename' => 'Master Item Catalog.xlsx',
            'worksheet' => 'Sheet0',
            'expected_range' => 'A1:T36331',
            'expected_total_rows' => 36331,
            'expected_column_count' => 20,
            'mandatory_headers' => [
                'SKU',
                'GROUP OWNER',
                'DETAIL',
                'ARTICLE SAP',
                'IS ACTIVE',
                'AUTO CREATE PO BASED ON VENDOR CONTRACT',
            ],
            'headers' => [
                'SKU',
                'GROUP OWNER',
                'AREA',
                'DETAIL',
                'UOM',
                'EST PRICE',
                'BUYING PRICE',
                'HASHTAG',
                'ARTICLE SAP',
                'VALIDITY FROM',
                'VALIDITY TO',
                'MAX DELIVERY TIME (DAYS)',
                'MIN QTY',
                'WARRANTY',
                'EXTEND TO GROUP',
                'IS REGULER',
                'IS ACTIVE',
                'AUTO CREATE PO BASED ON VENDOR CONTRACT',
                'MODIFIED BY',
                'MODIFIED DATE',
            ],
            'date_headers' => [
                'VALIDITY FROM',
                'VALIDITY TO',
                'MODIFIED DATE',
            ],
            'identifier_headers' => [
                'SKU',
                'ARTICLE SAP',
            ],
            'numeric_headers' => [
                'EST PRICE',
                'BUYING PRICE',
                'MAX DELIVERY TIME (DAYS)',
                'MIN QTY',
            ],
        ],
        'price' => [
            'label' => 'Price Per Vendor And Tiering',
            'source_filename' => 'Price Per Vendor And Tiering.xlsx',
            'fixture_filename' => 'Price Per Vendor And Tiering.xlsx',
            'worksheet' => 'Sheet0',
            'expected_range' => 'A1:O12458',
            'expected_total_rows' => 12458,
            'expected_column_count' => 15,
            'mandatory_headers' => [
                'SKU',
                'VENDOR CODE',
                'VENDOR NAME',
                'VALIDITY FROM',
                'VALIDITY TO',
                'ALOKASI',
                'CONTRACT QUANTITY',
                'REMAIN CONTRACT QUANTITY',
                'PRICE',
            ],
            'headers' => [
                'SKU',
                'AUTO CREATE VENDOR BASED ON CONTRACT',
                'VENDOR CODE',
                'VENDOR NAME',
                'VALIDITY FROM',
                'VALIDITY TO',
                'ALOKASI',
                'CONTRACT QUANTITY',
                'USED QUANTITY',
                'REMAIN CONTRACT QUANTITY',
                'BUYING PRICE',
                'NEGOTIATION PRICE',
                'LOW QTY',
                'HIGH QTY',
                'PRICE',
            ],
            'date_headers' => [
                'VALIDITY FROM',
                'VALIDITY TO',
            ],
            'identifier_headers' => [
                'SKU',
                'VENDOR CODE',
            ],
            'numeric_headers' => [
                'ALOKASI',
                'CONTRACT QUANTITY',
                'USED QUANTITY',
                'REMAIN CONTRACT QUANTITY',
                'BUYING PRICE',
                'NEGOTIATION PRICE',
                'LOW QTY',
                'HIGH QTY',
                'PRICE',
            ],
        ],
    ];
}

function k2_file_schema(string $datasetType): array
{
    $schemas = k2_file_schemas();
    if (!isset($schemas[$datasetType])) {
        throw new InvalidArgumentException("Unknown K2 dataset type: {$datasetType}");
    }

    return $schemas[$datasetType];
}

function k2_detect_file_schema_from_headers(array $headers): ?string
{
    foreach (k2_file_schemas() as $datasetType => $schema) {
        $legacyHeaders = array_slice($schema['headers'], 0, count($schema['headers']) - count($schema['optional_trailing_headers'] ?? []));
        if ($headers === $schema['headers'] || $headers === $legacyHeaders) {
            return $datasetType;
        }
    }

    return null;
}
