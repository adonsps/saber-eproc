<?php
/**
 * Backfill SLA Revision dari kolom "Naik Banding"
 * (temp_project/Proses Naik Banding Tiket to Canvassing Lv1.xlsx).
 *
 * SATU tool untuk seluruh alur: baca Excel -> saring scope -> hitung status SLA
 * efektif -> laporan review .xlsx -> (opsional) tulis ke database. Tidak butuh
 * Python dan tidak butuh library apa pun: .xlsx dibaca dan ditulis langsung
 * dengan ZipArchive + SimpleXML yang sudah ada di PHP container.
 *
 * ATURAN SCOPE (dari user):
 *   - kolom "Naik Banding" BUKAN "SETYO"
 *   - kolom "konfirmasi" HARUS "ok"
 *
 * Excel-nya ticket-level dan tidak punya kolom line item, sedangkan
 * sla_revision_requests/_overrides bekerja per (ticket_no, ticket_line_item).
 * Tool ini yang menjembatani: satu catatan naik banding di-fan-out ke setiap
 * line item tiket tersebut YANG STATUS EFEKTIFNYA MASIH 'SLA Breached'.
 *
 * Status efektif dibaca lewat get_sla_computed_rows(), bukan langsung dari
 * `prs`, supaya override yang sudah aktif ikut diperhitungkan dan tidak
 * ditulis ulang percuma.
 *
 * Output: db_backups/local_runtime_exports/Migrasi Naik Banding - Review.xlsx
 *
 * Pakai:
 *   docker-compose exec app php tools/import_sla_revision_from_naik_banding.php
 *   docker-compose exec app php tools/import_sla_revision_from_naik_banding.php --apply --confirm-db
 *
 * Opsi:
 *   --file=<path>      sumber .xlsx lain
 *   --skip-rejected    lewati line yang keputusan sla_status-nya pernah ditolak
 *
 * DRY-RUN ADALAH DEFAULT. Tanpa --apply DAN --confirm-db sekaligus, tool ini
 * tidak menulis satu baris pun ke database.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$ROOT = dirname(__DIR__);

// Sengaja TIDAK me-require config/database.php: file itu menjalankan blok
// auto-migrate (DDL) saat di-include, dan sebuah dry-run tidak boleh punya efek
// samping apa pun terhadap skema.
require_once $ROOT . '/workspace/includes/sla_helper.php';           // + sla_rules, sla_revision_helper
require_once $ROOT . '/workspace/includes/project_team_helper.php';  // load_project_team_map()

// ---------------------------------------------------------------- konfigurasi
const EXPECTED_DB   = 'u894129685_workspace';
const EXPECTED_USER = 'u894129685_workspace007';
const TARGET_STATUS = 'Within SLA';
const FROM_STATUS   = 'SLA Breached';

// Identitas sintetis untuk baris backfill. Tidak ada foreign key di
// sla_revision_requests (sudah dicek), jadi user_id 0 aman dan justru membuat
// baris ini gampang dibedakan dari request asli.
const MIG_USER_ID  = 0;
const MIG_NAME     = 'Data Migration';
const MIG_USERNAME = 'migration';
const MIG_PREFIX   = '[Migrasi] ';

// Layout sumber: baris 1 judul laporan, baris 2 header, data mulai baris 3.
const HEADER_ROW     = 2;
const FIRST_DATA_ROW = 3;

// Indeks kolom (0-based) sesuai header di baris 2.
const C_TICKET = 0, C_TYPE = 1, C_PIC = 2, C_TEAM = 3;
const C_TR_DATE = 4, C_CVS_APPV = 5, C_DURATION = 6, C_SLA_STATUS = 7;
const C_NAIK_BANDING = 13, C_KONFIRMASI = 14;

// Kolom yang isinya tanggal — dipakai untuk mengubah serial number Excel jadi
// tanggal terbaca. Hanya untuk tampilan laporan; perhitungan SLA tetap dari DB.
const DATE_COLS = [C_TR_DATE, C_CVS_APPV];

$SRC     = $ROOT . '/temp_project/Proses Naik Banding Tiket to Canvassing Lv1.xlsx';
$OUT_DIR = $ROOT . '/db_backups/local_runtime_exports';
$OUT_XLSX   = $OUT_DIR . '/Migrasi Naik Banding - Review.xlsx';
$BACKUP_DIR = $ROOT . '/db_backups/local_runtime_backups';

$flags = array_slice($argv, 1);
$apply        = in_array('--apply', $flags, true);
$confirmDb    = in_array('--confirm-db', $flags, true);
$skipRejected = in_array('--skip-rejected', $flags, true);
$writeMode    = $apply && $confirmDb;
foreach ($flags as $f) {
    if (strpos($f, '--file=') === 0) $SRC = substr($f, 7);
}
if ($apply && !$confirmDb) {
    exit("--apply butuh --confirm-db juga. Berhenti tanpa melakukan apa pun.\n");
}


// ============================================================ PEMBACA .XLSX ==
// Cukup untuk file laporan biasa: shared strings, inline string, angka, dan
// serial tanggal. Tidak menangani formula (dibaca nilai hasilnya kalau ada).

function xlsx_col_index($ref) {
    // "BC12" -> 54 (0-based)
    $n = 0;
    for ($i = 0; $i < strlen($ref); $i++) {
        $ch = $ref[$i];
        if ($ch < 'A' || $ch > 'Z') break;
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

function xlsx_serial_to_date($serial) {
    if (!is_numeric($serial)) return (string)$serial;
    // Epoch Excel 1900 dengan bug tahun kabisat 1900 yang terkenal itu.
    $ts = ((int)$serial - 25569) * 86400;
    return gmdate('Y-m-d', $ts);
}

function xlsx_read($path) {
    if (!is_file($path)) throw new RuntimeException("File tidak ditemukan: $path");
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException("Gagal membuka: $path");

    // Shared strings
    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sst = simplexml_load_string($xml);
        foreach ($sst->si as $si) {
            // <si><t>teks</t></si> atau <si><r><t>pot</t></r><r><t>ongan</t></r></si>
            $shared[] = isset($si->t) && count($si->r) === 0
                ? (string)$si->t
                : implode('', array_map(function ($r) { return (string)$r->t; }, iterator_to_array($si->r)));
        }
    }

    // Sheet pertama, lewat relasi di workbook (jangan asal tebak sheet1.xml).
    $target = 'xl/worksheets/sheet1.xml';
    $wb = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
    if ($wb) {
        $rid = (string)$wb->sheets->sheet[0]->attributes('r', true)->id;
        $rels = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
        foreach ($rels->Relationship as $rel) {
            if ((string)$rel['Id'] === $rid) {
                $t = ltrim((string)$rel['Target'], '/');
                $target = strpos($t, 'xl/') === 0 ? $t : 'xl/' . $t;
            }
        }
    }
    $sheet = simplexml_load_string($zip->getFromName($target));
    $zip->close();
    if (!$sheet) throw new RuntimeException("Sheet tidak terbaca di: $path");

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $r = (int)$row['r'];
        $cells = [];
        foreach ($row->c as $c) {
            $idx  = xlsx_col_index((string)$c['r']);
            $type = (string)$c['t'];
            if ($type === 's') {
                $v = $shared[(int)$c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $v = (string)$c->is->t;
            } else {
                $v = isset($c->v) ? (string)$c->v : '';
                if ($v !== '' && is_numeric($v) && in_array($idx, DATE_COLS, true)) {
                    $v = xlsx_serial_to_date($v);
                }
            }
            $cells[$idx] = trim($v);
        }
        $rows[$r] = $cells;
    }
    return $rows;
}

function cel($row, $i) { return trim((string)($row[$i] ?? '')); }


// ============================================================ PENULIS .XLSX ==
// Penulis minimal multi-sheet dengan inline string. Cukup untuk laporan review:
// header tebal berlatar biru, freeze pane, autofilter, dan sorotan kuning.

function xl_esc($s) {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)$s);
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xl_col_name($i) {
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

/**
 * @param array $sheets [ ['name'=>..., 'rows'=>[[cell,...],...], 'highlight'=>[rowIdx=>true]], ... ]
 *                      baris ke-0 tiap sheet diperlakukan sebagai header.
 */
function xlsx_write($path, array $sheets) {
    @mkdir(dirname($path), 0777, true);
    if (is_file($path)) @unlink($path);

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Tidak bisa menulis $path (masih terbuka di Excel?)");
    }

    $n = count($sheets);
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    for ($i = 1; $i <= $n; $i++) {
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $zip->addFromString('[Content_Types].xml', $ct . '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');

    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($sheets as $i => $s) {
        $id = $i + 1;
        // Nama sheet Excel: maks 31 karakter, tanpa : \ / ? * [ ]
        $name = mb_substr(preg_replace('/[:\\\\\/?*\[\]]/', '-', $s['name']), 0, 31);
        $wb   .= '<sheet name="' . xl_esc($name) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
        $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
    }
    $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $zip->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);

    // s=0 normal, s=1 header (putih tebal di biru), s=2 sorotan kuning
    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="4"><fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E78"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF2CC"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
        . '<cellXfs count="3"><xf/>'
        . '<xf fontId="1" fillId="2" applyFont="1" applyFill="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf fillId="3" applyFill="1"/></cellXfs>'
        . '</styleSheet>');

    foreach ($sheets as $i => $s) {
        $rows = $s['rows'];
        $ncol = 0;
        foreach ($rows as $r) $ncol = max($ncol, count($r));

        // Lebar kolom dari isi terpanjang, dibatasi supaya tidak melebar liar.
        $widths = [];
        foreach ($rows as $r) {
            foreach (array_values($r) as $c => $v) {
                $widths[$c] = min(60, max($widths[$c] ?? 10, mb_strlen((string)$v) + 2));
            }
        }
        $cols = '<cols>';
        foreach ($widths as $c => $w) {
            $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $cols .= '</cols>';

        $body = '';
        foreach ($rows as $ri => $r) {
            $body .= '<row r="' . ($ri + 1) . '">';
            foreach (array_values($r) as $ci => $v) {
                $ref = xl_col_name($ci) . ($ri + 1);
                if ($ri === 0)                          $st = ' s="1"';
                elseif (!empty($s['highlight'][$ri]))   $st = ' s="2"';
                else                                    $st = '';
                if ($v !== '' && is_numeric($v) && !preg_match('/^0\d|[-\/]/', (string)$v)) {
                    $body .= '<c r="' . $ref . '"' . $st . '><v>' . $v . '</v></c>';
                } else {
                    $body .= '<c r="' . $ref . '"' . $st . ' t="inlineStr"><is><t xml:space="preserve">' . xl_esc($v) . '</t></is></c>';
                }
            }
            $body .= '</row>';
        }

        $dim = 'A1:' . xl_col_name(max(0, $ncol - 1)) . max(1, count($rows));
        $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="' . $dim . '"/>'
            . '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . $cols
            . '<sheetData>' . $body . '</sheetData>'
            . (empty($s['nofilter']) ? '<autoFilter ref="' . $dim . '"/>' : '')
            . '</worksheet>');
    }

    $zip->close();
}


// ==================================================== 1. BACA & SARING EXCEL ==
echo "== 1. Baca Excel ==\n";
echo "Sumber   : $SRC\n";
$sheet = xlsx_read($SRC);

$headers = [];
foreach ($sheet[HEADER_ROW] ?? [] as $i => $v) $headers[$i] = $v;
ksort($headers);
$headers = array_values($headers) ?: ['(header tidak terbaca)'];

/** Alasan kenapa sebuah baris dibuang; null kalau masuk scope. */
function scope_reason($row) {
    if (cel($row, C_TICKET) === '')            return 'ticket_no kosong';
    $nb = cel($row, C_NAIK_BANDING);
    $kf = cel($row, C_KONFIRMASI);
    if ($nb === '')                            return 'kolom Naik Banding kosong';
    if (strtoupper($nb) === 'SETYO')           return 'Naik Banding = SETYO';
    if (strtolower($kf) !== 'ok')              return 'konfirmasi = ' . ($kf !== '' ? $kf : 'kosong');
    return null;
}

$scope = [];          // ticket_no => data tiket + catatan gabungan
$excludedRows = [];   // baris mentah + alasannya
$inScopeCount = 0;

foreach ($sheet as $rn => $row) {
    if ($rn < FIRST_DATA_ROW) continue;
    $flat = [];
    for ($i = 0; $i < count($headers); $i++) $flat[$i] = cel($row, $i);
    if (implode('', $flat) === '') continue;

    $reason = scope_reason($row);
    if ($reason !== null) {
        $excludedRows[] = array_merge($flat, [$reason]);
        continue;
    }
    $inScopeCount++;

    // Dedup ke level tiket: satu tiket = satu catatan gabungan, nanti di-fan-out
    // ke line item yang statusnya masih SLA Breached.
    $t = cel($row, C_TICKET);
    if (!isset($scope[$t])) {
        $scope[$t] = [
            'ticket_no'  => $t,
            'type'       => cel($row, C_TYPE),
            'pic'        => cel($row, C_PIC),
            'team'       => cel($row, C_TEAM),
            'tr_date'    => cel($row, C_TR_DATE),
            'cvs_appv'   => cel($row, C_CVS_APPV),
            'notes'      => [],
            'excel_rows' => 0,
        ];
    }
    $scope[$t]['excel_rows']++;
    $note = cel($row, C_NAIK_BANDING);
    if (!in_array($note, $scope[$t]['notes'], true)) $scope[$t]['notes'][] = $note;
}

$excludedReasons = [];
foreach ($excludedRows as $r) {
    $k = end($r);
    $excludedReasons[$k] = ($excludedReasons[$k] ?? 0) + 1;
}
printf("Baris data       : %d\n", $inScopeCount + count($excludedRows));
printf("Masuk scope      : %d baris -> %d tiket unik\n", $inScopeCount, count($scope));
printf("Dikecualikan     : %d baris\n", count($excludedRows));
foreach ($excludedReasons as $r => $c) printf("    %-32s %d\n", $r, $c);


// ================================================ 2. HITUNG STATUS SLA EFEKTIF ==
echo "\n== 2. Hitung status SLA ==\n";
$pdo = new PDO(
    'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';dbname=' . EXPECTED_DB . ';charset=utf8mb4',
    EXPECTED_USER,
    // Sama dengan kredensial di config/database.php untuk host 'db'; bisa
    // ditimpa lewat env supaya tool ini tidak jadi tempat kedua yang mengunci
    // password.
    getenv('WORKSPACE_DB_PASSWORD') ?: 'Xcel007!',
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
    ]
);
$GLOBALS['pdo'] = $pdo;

$ident = $pdo->query('SELECT DATABASE() db, USER() u, VERSION() v')->fetch();
$identUser = explode('@', $ident['u'])[0];
printf("DB       : %s\nUSER     : %s\nVERSION  : %s\nMODE     : %s\n",
    $ident['db'], $ident['u'], $ident['v'],
    $writeMode ? 'APPLY (menulis DB)' : 'DRY RUN (tidak menulis)');
if ($ident['db'] !== EXPECTED_DB || $identUser !== EXPECTED_USER) {
    exit("Identity gate GAGAL: database/user tidak sesuai yang diharapkan. Berhenti.\n");
}

// Persona COMBINED = akses penuh, supaya tool tidak menyaring baris berdasarkan
// siapa yang kebetulan menjalankannya.
$computed = get_sla_computed_rows($pdo, [
    'persona'          => 'COMBINED',
    'role'             => 'Category Manager',
    'manager_username' => MIG_USERNAME,
    'user_name'        => MIG_NAME,
    'team_name'        => '',
    'project_groups'   => [],
]);
printf("Baris SLA terkomputasi  : %d\n", count($computed['rows']));

$byTicket = [];
foreach ($computed['rows'] as $r) $byTicket[trim((string)$r['ticket_no'])][] = $r;

$pending   = sla_rev_load_pending($pdo);
$overrides = sla_rev_load_overrides($pdo);

// Line yang keputusan sla_status-nya pernah ditolak manager.
$rejected = [];
foreach ($pdo->query("
        SELECT ticket_no, ticket_line_item, MAX(decided_at) last_rejected
        FROM sla_revision_requests
        WHERE request_type = 'sla_status' AND status = 'rejected'
        GROUP BY ticket_no, ticket_line_item") as $r) {
    $rejected[sla_rev_line_key($r['ticket_no'], $r['ticket_line_item'])] = $r['last_rejected'];
}


// ============================================================== 3. EVALUASI ==
$plan = [];
$migrateRows = [];
$skipRows = [];
$konflikRows = [];
$skipReasons = [];

$PREVIEW_HEAD = ['ticket_no', 'ticket_line_item', 'assigned_pic', 'status_efektif_sekarang',
                 'status_tujuan', 'durasi', 'keterangan', 'naik_banding'];

foreach ($scope as $ticket => $info) {
    $note  = implode(' | ', $info['notes']);
    $lines = $byTicket[$ticket] ?? [];

    if (!$lines) {
        // pr_date NULL -> baris tidak pernah masuk perhitungan SLA sama sekali
        $reason = 'tiket tidak punya baris SLA yang bisa dinilai (pr_date kosong?)';
        $skipRows[] = [$ticket, '', $info['pic'], '', TARGET_STATUS, '', $reason, $note];
        $skipReasons['tiket tanpa baris SLA'] = ($skipReasons['tiket tanpa baris SLA'] ?? 0) + 1;
        continue;
    }

    foreach ($lines as $r) {
        $line = trim((string)$r['ticket_line_item']);
        $key  = sla_rev_line_key($ticket, $line);
        $eff  = $r['statusName'];
        $skip = null;

        if ($eff !== FROM_STATUS) {
            $skip = ($eff === TARGET_STATUS && !empty($overrides[$key]['override_sla_status']))
                ? 'sudah di-override ke Within SLA sebelumnya'
                : "status efektif '$eff', bukan SLA Breached";
        } elseif (isset($pending[$key])) {
            // Ada request yang masih menunggu keputusan manusia. Backfill tidak
            // boleh mendahului keputusan itu.
            $skip = 'ada request pending (' . implode('/', array_keys($pending[$key])) . ')';
        } elseif ($skipRejected && isset($rejected[$key])) {
            $skip = 'pernah ditolak manager (--skip-rejected)';
        }

        $isKonflik = !$skip && isset($rejected[$key]);
        $ket = $skip ?: ($isKonflik
            ? 'PERHATIAN: sla_status pernah DITOLAK ' . $rejected[$key] . ' - backfill akan membalik keputusan itu'
            : '');

        $out = [$ticket, $line, $info['pic'], $eff, TARGET_STATUS,
                $r['durationDisplay'] ?? '', $ket, $note];

        if ($skip) {
            $skipRows[] = $out;
            $bucket = trim(preg_replace('/\(.*\)|\d{4}-\d{2}-\d{2}.*/', '', $skip));
            $skipReasons[$bucket] = ($skipReasons[$bucket] ?? 0) + 1;
        } else {
            $migrateRows[] = $out;
            if ($isKonflik) $konflikRows[] = $out;
            $plan[] = ['ticket_no' => $ticket, 'line' => $line, 'note' => $note];
        }
    }
}

$sortRows = function (&$rows) {
    usort($rows, function ($a, $b) {
        return [$a[0], (int)$a[1]] <=> [$b[0], (int)$b[1]];
    });
};
$sortRows($migrateRows);
$sortRows($skipRows);
$sortRows($konflikRows);

$tickets = count(array_unique(array_column($plan, 'ticket_no')));

echo "\n--------------------------------------------------------\n";
printf("AKAN DIMIGRASI : %d line item  (%d tiket)\n", count($migrateRows), $tickets);
printf("DI-SKIP        : %d line item\n", count($skipRows));
foreach ($skipReasons as $reason => $n) printf("    %-52s %d\n", $reason, $n);
if ($konflikRows) {
    printf("\nPERLU KONFIRMASI: %d line yang akan dimigrasi pernah DITOLAK manager.\n", count($konflikRows));
}


// ======================================================= 4. LAPORAN REVIEW ==
$ringkasan = [
    ['Item', 'Jumlah'],
    ['Line item yang bisa diubah jadi Within SLA', count($migrateRows)],
    ['  - di antaranya pernah DITOLAK manager', count($konflikRows)],
    ['Tiket yang tersentuh', $tickets],
    ['Line item di-skip', count($skipRows)],
    ['Tiket in-scope dari Excel', count($scope)],
    ['Baris Excel dibuang (SETYO / Not ok / kosong)', count($excludedRows)],
    [],
    ['Alasan di-skip', 'Jumlah'],
];
arsort($skipReasons);
foreach ($skipReasons as $r => $n) $ringkasan[] = [$r, $n];

// Sorot baris konflik di sheet "Bisa Diubah" (indeks +1 karena baris 0 header).
$hl = [];
foreach ($migrateRows as $i => $r) {
    if (strpos($r[6], 'PERHATIAN') === 0) $hl[$i + 1] = true;
}

try {
    xlsx_write($OUT_XLSX, [
        ['name' => 'Ringkasan', 'rows' => $ringkasan, 'nofilter' => true],
        ['name' => sprintf('Bisa Diubah (%d)', count($migrateRows)),
         'rows' => array_merge([$PREVIEW_HEAD], $migrateRows), 'highlight' => $hl],
        ['name' => sprintf('Perlu Konfirmasi (%d)', count($konflikRows)),
         'rows' => array_merge([$PREVIEW_HEAD], $konflikRows)],
        ['name' => sprintf('Di-skip (%d)', count($skipRows)),
         'rows' => array_merge([$PREVIEW_HEAD], $skipRows)],
        ['name' => sprintf('Excluded (%d)', count($excludedRows)),
         'rows' => array_merge([array_merge($headers, ['alasan_dikecualikan'])], $excludedRows)],
    ]);
    echo "\nLaporan review : $OUT_XLSX\n";
} catch (RuntimeException $e) {
    echo "\nLaporan review GAGAL ditulis: " . $e->getMessage() . "\n";
    echo "(tidak menghentikan proses — angka di atas tetap valid)\n";
}

if (!$writeMode) {
    echo "\nDRY RUN selesai. Tidak ada perubahan database.\n";
    echo "Untuk apply: tambahkan --apply --confirm-db (akan backup dulu).\n";
    exit(0);
}


// ================================================== 5. APPLY (butuh 2 flag) ==
echo "\n== 5. Apply ==\n";
@mkdir($BACKUP_DIR, 0777, true);
$stamp = date('Ymd_His');
foreach (['sla_revision_requests', 'sla_revision_overrides'] as $tbl) {
    $path = "$BACKUP_DIR/{$tbl}_{$stamp}.csv";
    $bfh = fopen($path, 'w');
    $q = $pdo->query("SELECT * FROM $tbl");
    $first = true;
    while ($r = $q->fetch()) {
        if ($first) { fputcsv($bfh, array_keys($r)); $first = false; }
        fputcsv($bfh, $r);
    }
    fclose($bfh);
    echo "Backup: $path\n";
}

$pdo->beginTransaction();
try {
    $ins = $pdo->prepare("
        INSERT INTO sla_revision_requests
            (ticket_no, ticket_line_item, request_type, old_value, requested_value,
             remarks, requested_by_user_id, requested_by_name, requested_by_username,
             approver_username, approver_name, status,
             decided_by_user_id, decided_by_name, decided_at, decision_notes)
        VALUES (?, ?, 'sla_status', ?, ?, ?, ?, ?, ?, NULL, NULL, 'approved', ?, ?, NOW(), ?)
    ");
    $n = 0;
    foreach ($plan as $p) {
        $ins->execute([
            $p['ticket_no'], $p['line'], FROM_STATUS, TARGET_STATUS,
            MIG_PREFIX . $p['note'],
            MIG_USER_ID, MIG_NAME, MIG_USERNAME,
            MIG_USER_ID, MIG_NAME,
            'Disetujui via migrasi data naik banding (konfirmasi = ok).',
        ]);
        // Satu-satunya jalur yang boleh menulis tabel override.
        sla_rev_apply_approved($pdo, [
            'id'               => (int)$pdo->lastInsertId(),
            'ticket_no'        => $p['ticket_no'],
            'ticket_line_item' => $p['line'],
            'request_type'     => 'sla_status',
            'requested_value'  => TARGET_STATUS,
        ], MIG_NAME);
        $n++;
    }
    $pdo->commit();
    printf("\nAPPLY selesai: %d request + override ditulis.\n", $n);
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\nGAGAL, transaksi di-rollback: " . $e->getMessage() . "\n";
    exit(1);
}
