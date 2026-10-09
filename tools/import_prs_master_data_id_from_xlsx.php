<?php
/**
 * Backfill prs.master_data_id dari hasil mapping taksonomi (.xlsx).
 *
 * Sumber default: workspace/data/migrate_taxonomy_prs.xlsx
 * Kolom yang dipakai (dicari lewat NAMA header, bukan posisi):
 *   "TICKET NO."        -> prs.ticket_no
 *   "TICKET LINE ITEM"  -> prs.ticket_line_item
 *   "Master Data ID"    -> prs.master_data_id
 * Kolom lain di Excel (Level 0..5, Confidence, Match Score, Mapping Note, dll)
 * hanya ikut ke laporan review, TIDAK ditulis ke database.
 *
 * ATURAN (dari user):
 *   - HANYA isi baris yang master_data_id-nya masih NULL (atau 0).
 *   - Baris yang master_data_id-nya sudah terisi: DI-SKIP, apa pun isinya —
 *     termasuk kalau nilainya berbeda dengan Excel (dilaporkan sebagai
 *     "beda_nilai" supaya bisa direview manual, tapi tidak ditimpa).
 *
 * Pencocokan memakai primary key prs (ticket_no, ticket_line_item), jadi tidak
 * ada tebak-tebakan teks. ticket_line_item dibandingkan setelah dinormalisasi
 * (trim + buang leading zero) supaya "0050" di Excel tetap ketemu "50" di DB.
 *
 * Pakai (CLI):
 *   docker-compose exec app php tools/import_prs_master_data_id_from_xlsx.php
 *   docker-compose exec app php tools/import_prs_master_data_id_from_xlsx.php --apply --confirm-db
 *
 * Pakai (browser) — opsi jadi query string, tanpa "--":
 *   http://localhost:8080/tools/import_prs_master_data_id_from_xlsx.php
 *   http://localhost:8080/tools/import_prs_master_data_id_from_xlsx.php?apply=1&confirm-db=1
 *
 * Opsi:
 *   --file=<path>   sumber .xlsx lain (relatif ke root repo atau absolut)
 *   --sheet=<nama>  pilih sheet tertentu (default: sheet pertama)
 *   --mark-ai       sekalian set prs.assigned_by_ai = 1 untuk baris yang diisi
 *   --limit=<n>     batasi jumlah baris yang di-apply (buat uji coba kecil)
 *
 * DRY-RUN ADALAH DEFAULT. Tanpa --apply DAN --confirm-db sekaligus, tool ini
 * tidak menulis satu baris pun ke database.
 */

$ROOT = dirname(__DIR__);

// Sengaja TIDAK me-require config/database.php: file itu menjalankan blok
// auto-migrate (DDL) saat di-include, dan sebuah dry-run tidak boleh punya efek
// samping apa pun terhadap skema.

const EXPECTED_DB   = 'u894129685_workspace';
const EXPECTED_USER = 'u894129685_workspace007';

// Nama header yang dicari (dicocokkan case-insensitive, tanda baca diabaikan).
const H_TICKET = 'ticket no';
const H_LINE   = 'ticket line item';
const H_MDID   = 'master data id';

$SRC        = $ROOT . '/workspace/data/migrate_taxonomy_prs.xlsx';
$OUT_DIR    = $ROOT . '/db_backups/local_runtime_exports';
$BACKUP_DIR = $ROOT . '/db_backups/local_runtime_backups';

// Jalan di CLI maupun browser. Di CLI opsi datang dari $argv; di browser dari
// query string (?apply=1&confirm-db=1), dinormalisasi ke bentuk "--flag" yang
// sama supaya sisa script tidak perlu tahu bedanya.
$IS_CLI = php_sapi_name() === 'cli';
if ($IS_CLI) {
    $flags = array_slice($argv, 1);
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre style=\"font:13px/1.5 ui-monospace,Consolas,monospace;white-space:pre-wrap\">\n";
    $flags = [];
    foreach ($_GET as $k => $v) {
        if (!is_string($v)) continue;
        // Sumber file dari URL dibatasi ke dalam repo: jangan sampai query
        // string bisa menunjuk file di luar direktori aplikasi.
        if ($k === 'file' && (strpos($v, '..') !== false || $v === '' || $v[0] === '/')) continue;
        $flags[] = ($v === '' || $v === '1') ? "--$k" : "--$k=$v";
    }
}

$apply     = in_array('--apply', $flags, true);
$confirmDb = in_array('--confirm-db', $flags, true);
$markAi    = in_array('--mark-ai', $flags, true);
$writeMode = $apply && $confirmDb;
$sheetName = null;
$limit     = 0;
foreach ($flags as $f) {
    if (strpos($f, '--file=')  === 0) $SRC       = substr($f, 7);
    if (strpos($f, '--sheet=') === 0) $sheetName = substr($f, 8);
    if (strpos($f, '--limit=') === 0) $limit     = max(0, (int)substr($f, 8));
}
if ($SRC[0] !== '/' && !preg_match('/^[A-Za-z]:/', $SRC)) $SRC = $ROOT . '/' . $SRC;
if ($apply && !$confirmDb) {
    exit("--apply butuh --confirm-db juga. Berhenti tanpa melakukan apa pun.\n");
}


// ============================================================ PEMBACA .XLSX ==
// Cukup untuk file laporan biasa: shared strings, inline string, dan angka.
// Formula dibaca nilai hasil cache-nya kalau ada.

function xlsx_col_index($ref) {
    $n = 0;
    for ($i = 0; $i < strlen($ref); $i++) {
        $ch = $ref[$i];
        if ($ch < 'A' || $ch > 'Z') break;
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

function xlsx_read($path, $wantSheet = null) {
    if (!is_file($path)) throw new RuntimeException("File tidak ditemukan: $path");
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException("Gagal membuka: $path");

    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sst = simplexml_load_string($xml);
        foreach ($sst->si as $si) {
            // <si><t>teks</t></si> atau <si><r><t>pot</t></r><r><t>ongan</t></r></si>
            $shared[] = (isset($si->t) && count($si->r) === 0)
                ? (string)$si->t
                : implode('', array_map(function ($r) { return (string)$r->t; }, iterator_to_array($si->r)));
        }
    }

    // Cari sheet lewat relasi di workbook, jangan asal tebak sheet1.xml.
    $wb   = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
    $rels = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
    $relMap = [];
    foreach ($rels->Relationship as $rel) $relMap[(string)$rel['Id']] = ltrim((string)$rel['Target'], '/');

    $target = null; $picked = null; $names = [];
    foreach ($wb->sheets->sheet as $sh) {
        $nm = (string)$sh['name'];
        $names[] = $nm;
        $isWanted = $wantSheet === null ? ($target === null) : (strcasecmp($nm, $wantSheet) === 0);
        if ($isWanted && $target === null) {
            $t = $relMap[(string)$sh->attributes('r', true)->id] ?? 'worksheets/sheet1.xml';
            $target = strpos($t, 'xl/') === 0 ? $t : 'xl/' . $t;
            $picked = $nm;
        }
    }
    if ($target === null) {
        $zip->close();
        throw new RuntimeException("Sheet '$wantSheet' tidak ada. Yang tersedia: " . implode(', ', $names));
    }

    $sheet = simplexml_load_string($zip->getFromName($target));
    $zip->close();
    if (!$sheet) throw new RuntimeException("Sheet tidak terbaca di: $path");

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
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
            }
            $cells[$idx] = trim($v);
        }
        $rows[(int)$row['r']] = $cells;
    }
    return ['sheet' => $picked, 'rows' => $rows];
}

function norm_header($s) {
    // "TICKET NO." -> "ticket no";  "Master Data ID" -> "master data id"
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', strtolower((string)$s))));
}

/** Normalisasi line item: "0050" / "50.0" / " 50 " -> "50"; non-angka: trim saja. */
function norm_line($s) {
    $s = trim((string)$s);
    if ($s === '') return '';
    if (preg_match('/^\d+(\.0+)?$/', $s)) return (string)(int)$s;
    return $s;
}

function norm_ticket($s) {
    return strtoupper(trim((string)$s));
}

function csv_write($path, array $head, array $rows) {
    $dir = dirname($path);
    @mkdir($dir, 0777, true);
    // Lewat browser prosesnya www-data, lewat CLI biasanya root — folder yang
    // cuma writable oleh root bikin jalur web gagal di sini. Sebutkan siapa yang
    // sedang menulis supaya penyebabnya langsung kelihatan.
    $fh = @fopen($path, 'w');
    if (!$fh) {
        $who = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?';
        throw new RuntimeException(
            "Tidak bisa menulis $path (user: $who, folder "
            . (is_dir($dir) ? 'ada, permission ' . substr(sprintf('%o', fileperms($dir)), -4) : 'TIDAK ADA')
            . "). Perbaiki dengan: docker-compose exec app chmod 777 " . $dir
        );
    }
    fwrite($fh, "\xEF\xBB\xBF");            // BOM, biar Excel baca UTF-8 dengan benar
    fputcsv($fh, $head);
    foreach ($rows as $r) fputcsv($fh, $r);
    fclose($fh);
}


// ==================================================== 1. BACA & PETAKAN EXCEL ==
echo "== 1. Baca Excel ==\n";
echo "Sumber   : $SRC\n";
$read  = xlsx_read($SRC, $sheetName);
$rows  = $read['rows'];
echo "Sheet    : {$read['sheet']}\n";

// Header di baris pertama yang ada isinya.
$headerRow = null;
foreach ($rows as $rn => $cells) {
    if (implode('', $cells) !== '') { $headerRow = $rn; break; }
}
if ($headerRow === null) exit("Sheet kosong. Berhenti.\n");

$colOf = [];
foreach ($rows[$headerRow] as $i => $v) {
    $h = norm_header($v);
    if ($h !== '' && !isset($colOf[$h])) $colOf[$h] = $i;
}
$headers = [];
foreach ($rows[$headerRow] as $i => $v) $headers[$i] = $v;
ksort($headers);
$ncol = $headers ? max(array_keys($headers)) + 1 : 0;

foreach ([H_TICKET, H_LINE, H_MDID] as $need) {
    if (!isset($colOf[$need])) {
        exit("Kolom '$need' tidak ditemukan di header baris $headerRow.\n"
           . "Header terbaca: " . implode(' | ', $headers) . "\n");
    }
}
$C_TICKET = $colOf[H_TICKET];
$C_LINE   = $colOf[H_LINE];
$C_MDID   = $colOf[H_MDID];
printf("Header   : baris %d (ticket=kol %d, line=kol %d, master_data_id=kol %d)\n",
    $headerRow, $C_TICKET, $C_LINE, $C_MDID);

/** Kumpulkan niat per (ticket, line). Baris Excel ganda untuk key yang sama digabung. */
$want    = [];   // key => ['ticket','line','mdid','excel_rows'=>[n],'raw'=>baris pertama]
$badRows = [];   // baris Excel yang tidak bisa dipakai + alasannya
$dupConflict = [];
$dataRows = 0;

foreach ($rows as $rn => $cells) {
    if ($rn <= $headerRow) continue;
    $flat = [];
    for ($i = 0; $i < $ncol; $i++) $flat[$i] = trim((string)($cells[$i] ?? ''));
    if (implode('', $flat) === '') continue;
    $dataRows++;

    $ticket = norm_ticket($flat[$C_TICKET]);
    $line   = norm_line($flat[$C_LINE]);
    $mdRaw  = $flat[$C_MDID];

    if ($ticket === '' || $line === '') {
        $badRows[] = array_merge([$rn], $flat, ['ticket_no / ticket_line_item kosong']);
        continue;
    }
    if ($mdRaw === '') {
        $badRows[] = array_merge([$rn], $flat, ['Master Data ID kosong di Excel']);
        continue;
    }
    if (!preg_match('/^\d+(\.0+)?$/', $mdRaw) || (int)$mdRaw <= 0) {
        $badRows[] = array_merge([$rn], $flat, ["Master Data ID bukan angka valid: $mdRaw"]);
        continue;
    }
    $mdid = (int)$mdRaw;

    $key = $ticket . '||' . $line;
    if (isset($want[$key])) {
        // Key sama muncul lagi. Kalau id-nya beda, itu ambigu -> buang keduanya.
        if ($want[$key]['mdid'] !== $mdid) {
            $want[$key]['conflict'] = true;
            $want[$key]['ids'][] = $mdid;
        }
        $want[$key]['excel_rows'][] = $rn;
        continue;
    }
    $want[$key] = [
        'ticket'     => $ticket,
        'line'       => $line,
        'mdid'       => $mdid,
        'ids'        => [$mdid],
        'excel_rows' => [$rn],
        'raw'        => $flat,
        'conflict'   => false,
    ];
}

foreach ($want as $key => $w) {
    if ($w['conflict']) {
        $dupConflict[$key] = $w;
        unset($want[$key]);
    }
}

printf("Baris data       : %d\n", $dataRows);
printf("Key unik dipakai : %d\n", count($want));
printf("Baris dibuang    : %d\n", count($badRows));
if ($dupConflict) {
    printf("Key ambigu       : %d  (Master Data ID berbeda untuk ticket+line yang sama -> tidak diproses)\n",
        count($dupConflict));
}


// ============================================================ 2. BACA DATABASE ==
echo "\n== 2. Baca database ==\n";
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
    ]
);

$ident = $pdo->query('SELECT DATABASE() db, USER() u, VERSION() v')->fetch();
$identUser = explode('@', $ident['u'])[0];
printf("DB       : %s\nUSER     : %s\nVERSION  : %s\nMODE     : %s\n",
    $ident['db'], $ident['u'], $ident['v'],
    $writeMode ? 'APPLY (menulis DB)' : 'DRY RUN (tidak menulis)');
if ($ident['db'] !== EXPECTED_DB || $identUser !== EXPECTED_USER) {
    exit("Identity gate GAGAL: database/user tidak sesuai yang diharapkan. Berhenti.\n");
}

// Semua baris prs, di-index dengan key yang sudah dinormalisasi sama persis
// seperti sisi Excel. Tabelnya puluhan ribu baris — muat di memori, dan jauh
// lebih murah daripada satu query per baris Excel.
$prs = [];
$q = $pdo->query("SELECT ticket_no, ticket_line_item, master_data_id, item_description FROM prs");
while ($r = $q->fetch()) {
    $prs[norm_ticket($r['ticket_no']) . '||' . norm_line($r['ticket_line_item'])] = $r;
}
printf("Baris prs        : %d\n", count($prs));

$validMd = [];
foreach ($pdo->query("SELECT id FROM master_data") as $r) $validMd[(int)$r['id']] = true;
printf("Baris master_data: %d\n", count($validMd));


// ============================================================== 3. EVALUASI ==
$HEAD = ['ticket_no', 'ticket_line_item', 'master_data_id_excel', 'master_data_id_db_sekarang',
         'item_description_db', 'keterangan', 'baris_excel'];

$plan        = [];
$fillRows    = [];
$skipRows    = [];
$skipReasons = [];

$addSkip = function ($w, $dbVal, $desc, $reason, $bucket) use (&$skipRows, &$skipReasons) {
    $skipRows[] = [$w['ticket'], $w['line'], $w['mdid'], $dbVal, $desc, $reason,
                   implode(',', $w['excel_rows'])];
    $skipReasons[$bucket] = ($skipReasons[$bucket] ?? 0) + 1;
};

foreach ($want as $key => $w) {
    $pr = $prs[$key] ?? null;

    if ($pr === null) {
        $addSkip($w, '', '', 'tidak ada baris prs dengan ticket_no + ticket_line_item ini',
                 'tidak ada di tabel prs');
        continue;
    }
    if (!isset($validMd[$w['mdid']])) {
        $addSkip($w, $pr['master_data_id'], $pr['item_description'],
                 'Master Data ID ' . $w['mdid'] . ' tidak ada di tabel master_data',
                 'master_data_id tidak dikenal');
        continue;
    }

    $dbVal = $pr['master_data_id'];
    if ($dbVal !== null && (int)$dbVal !== 0) {
        // Aturan user: yang sudah terisi TIDAK ditimpa, cuma dilaporkan.
        $same = (int)$dbVal === $w['mdid'];
        $addSkip($w, $dbVal, $pr['item_description'],
                 $same ? 'sudah terisi dengan nilai yang sama'
                       : 'sudah terisi dengan nilai BERBEDA (' . $dbVal . ') - tidak ditimpa, review manual',
                 $same ? 'sudah terisi (nilai sama)' : 'sudah terisi (nilai BEDA)');
        continue;
    }

    $fillRows[] = [$w['ticket'], $w['line'], $w['mdid'], '(NULL)', $pr['item_description'], '',
                   implode(',', $w['excel_rows'])];
    $plan[] = ['ticket' => $w['ticket'], 'line' => $pr['ticket_line_item'],
               'ticket_db' => $pr['ticket_no'], 'mdid' => $w['mdid']];
}

foreach ($dupConflict as $w) {
    $pr = $prs[$w['ticket'] . '||' . $w['line']] ?? null;
    $addSkip($w, $pr['master_data_id'] ?? '', $pr['item_description'] ?? '',
             'Excel memberi Master Data ID berbeda untuk ticket+line yang sama: '
             . implode(', ', array_unique($w['ids'])),
             'ambigu di Excel');
}

$sortRows = function (&$rows) {
    usort($rows, function ($a, $b) { return [$a[0], (int)$a[1]] <=> [$b[0], (int)$b[1]]; });
};
$sortRows($fillRows);
$sortRows($skipRows);

$applyPlan = $plan;
if ($limit > 0 && count($applyPlan) > $limit) {
    $applyPlan = array_slice($applyPlan, 0, $limit);
}

echo "\n--------------------------------------------------------\n";
printf("AKAN DIISI : %d baris prs (master_data_id NULL -> nilai dari Excel)\n", count($plan));
printf("DI-SKIP    : %d\n", count($skipRows));
arsort($skipReasons);
foreach ($skipReasons as $reason => $n) printf("    %-46s %d\n", $reason, $n);
if ($limit > 0) printf("\n--limit=%d aktif: hanya %d baris pertama yang akan di-apply.\n", $limit, count($applyPlan));
if ($markAi) echo "\n--mark-ai aktif: baris yang diisi juga di-set assigned_by_ai = 1.\n";


// ======================================================= 4. LAPORAN REVIEW ==
$stamp = date('Ymd_His');
@mkdir($OUT_DIR, 0777, true);
$fFill = "$OUT_DIR/migrate_master_data_id_AKAN_DIISI_$stamp.csv";
$fSkip = "$OUT_DIR/migrate_master_data_id_DISKIP_$stamp.csv";
try {
    csv_write($fFill, $HEAD, $fillRows);
    csv_write($fSkip, $HEAD, $skipRows);
    echo "\nLaporan review:\n  $fFill\n  $fSkip\n";
    if ($badRows) {
        $fBad = "$OUT_DIR/migrate_master_data_id_BARIS_INVALID_$stamp.csv";
        csv_write($fBad, array_merge(['baris_excel'], array_values($headers), ['alasan']), $badRows);
        echo "  $fBad\n";
    }
} catch (RuntimeException $e) {
    echo "\nLaporan review GAGAL ditulis: " . $e->getMessage() . "\n";
    echo "(tidak menghentikan proses - angka di atas tetap valid)\n";
}

if (!$writeMode) {
    echo "\nDRY RUN selesai. Tidak ada perubahan database.\n";
    echo "Untuk apply: tambahkan --apply --confirm-db (akan backup dulu).\n";
    exit(0);
}


// ================================================== 5. APPLY (butuh 2 flag) ==
echo "\n== 5. Apply ==\n";
if (!$applyPlan) {
    echo "Tidak ada yang perlu diisi. Selesai.\n";
    exit(0);
}

// Backup: cukup kolom kunci + nilai lama dari baris yang akan disentuh, supaya
// rollback-nya trivial (UPDATE balik ke NULL) tanpa dump 33k baris.
@mkdir($BACKUP_DIR, 0777, true);
$fBak = "$BACKUP_DIR/prs_master_data_id_before_$stamp.csv";
csv_write($fBak, ['ticket_no', 'ticket_line_item', 'master_data_id_lama', 'master_data_id_baru'],
    array_map(function ($p) {
        return [$p['ticket_db'], $p['line'], '', $p['mdid']];
    }, $applyPlan));
echo "Backup nilai lama: $fBak\n";

$sql = $markAi
    ? "UPDATE prs SET master_data_id = ?, assigned_by_ai = 1
        WHERE ticket_no = ? AND ticket_line_item = ? AND (master_data_id IS NULL OR master_data_id = 0)"
    : "UPDATE prs SET master_data_id = ?
        WHERE ticket_no = ? AND ticket_line_item = ? AND (master_data_id IS NULL OR master_data_id = 0)";
// Guard master_data_id IS NULL di WHERE sengaja diulang di sini: kalau ada yang
// mengisi baris itu antara pembacaan dan apply, update-nya batal sendiri.

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare($sql);
    $n = 0; $noop = 0;
    foreach ($applyPlan as $p) {
        $upd->execute([$p['mdid'], $p['ticket_db'], $p['line']]);
        if ($upd->rowCount() > 0) $n++; else $noop++;
    }
    $pdo->commit();
    printf("\nAPPLY selesai: %d baris prs terisi.\n", $n);
    if ($noop) printf("%d baris tidak berubah (keburu terisi orang lain / nilai sama).\n", $noop);
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\nGAGAL, transaksi di-rollback: " . $e->getMessage() . "\n";
    exit(1);
}
