<?php
// workspace/includes/vendor_eval_v2_item_category.php
//
// Barang (goods) vs Jasa (services) classification for Vendor Evaluation v2.
// PURE LIBRARY: no session/auth/output side effects at include time. Safe to
// require from a no-login page (like the rest of the vendor_eval_v2_* set).
//
// There is deliberately no persistent "auto" classification anywhere — a
// PO's category is computed LIVE from its prs.item_description lines every
// time it's displayed (ve2_classify_item_category()). The ONLY thing that
// persists is a human's manual correction, in vendor_eval_po_item_category
// (db_backups/vendor_eval_v2_05_po_item_category_override.sql) — see
// ve2_item_category_overrides()/ve2_item_category_set(). A manual override
// always wins over the live guess for that po_number.
//
// Scope: currently wired ONLY into public_eval_v2_project_worklist.php
// (Form 2 / Project-Ops shared worklist). Do NOT wire this into
// public_eval_v2_worklist.php (the general worklist) without being asked —
// that page is explicitly off-limits for this feature.

/**
 * Canonical Jasa-signal keyword list — single source of truth. A description
 * matching ANY of these (case-insensitive, word-bounded) counts as a "jasa"
 * line item. Includes the 2026-08-19 construction-vocabulary addition
 * (sipil/kerja/renovasi/bangun/bongkar/pembangunan/pekerjaan) after the
 * local audit showed plain "Sipil interior" / "KERJA TAMBAH SIPIL" style
 * descriptions being missed by the original list.
 */
function ve2_item_category_keywords(): array
{
    return [
        'jasa', 'instalasi', 'pemasangan', 'pasang', 'pasangan', 'mep', 'tu',
        'service', 'maintenance', 'perbaikan', 'perawatan', 'sewa', 'rental',
        'konsultasi', 'survey', 'inspeksi', 'training', 'pelatihan',
        'pengecekan', 'kalibrasi', 'overhaul', 'pengerjaan',
        'sipil', 'kerja', 'renovasi', 'bangun', 'bongkar', 'pembangunan', 'pekerjaan',
    ];
}

/**
 * Classifies one PO from its distinct line-item descriptions. A PO is "jasa"
 * when >= 50% of its descriptions match a Jasa keyword, else "barang".
 * 'mixed' flags a PO with BOTH matching and non-matching lines — the ones
 * most likely to warrant a manual override either way.
 * Returns ['category'=>'barang'|'jasa', 'jasa_hits'=>int, 'total'=>int, 'mixed'=>bool].
 * Zero descriptions defaults to 'barang' (the majority case locally) with
 * total=0 so the caller can still show "unclassified" styling if it wants to.
 */
function ve2_classify_item_category(array $descriptions): array
{
    $descriptions = array_values(array_filter(array_map('strval', $descriptions), static fn($d) => trim($d) !== ''));
    $total = count($descriptions);
    if ($total === 0) {
        return ['category' => 'barang', 'jasa_hits' => 0, 'total' => 0, 'mixed' => false];
    }

    static $pattern = null;
    if ($pattern === null) {
        $pattern = '/\b(' . implode('|', array_map('preg_quote', ve2_item_category_keywords())) . ')\b/i';
    }

    $jasa_hits = 0;
    foreach ($descriptions as $d) {
        if (preg_match($pattern, $d)) {
            $jasa_hits++;
        }
    }
    $category = ($jasa_hits / $total >= 0.5) ? 'jasa' : 'barang';
    return [
        'category'  => $category,
        'jasa_hits' => $jasa_hits,
        'total'     => $total,
        'mixed'     => ($jasa_hits > 0 && $jasa_hits < $total),
    ];
}

/**
 * Bulk-fetches manual overrides for a set of POs from
 * vendor_eval_po_item_category. Returns [po_number => ['category','updated_by','updated_at']].
 * Fails closed ([]) on any error, missing table (migration not yet applied),
 * or empty input — callers simply fall back to the live classification.
 */
function ve2_item_category_overrides(PDO $pdo, array $po_numbers): array
{
    $po_numbers = array_values(array_unique(array_filter(
        array_map(static fn($p) => trim((string)$p), $po_numbers),
        static fn($p) => $p !== ''
    )));
    if (empty($po_numbers)) {
        return [];
    }
    try {
        $ph = implode(',', array_fill(0, count($po_numbers), '?'));
        $stmt = $pdo->prepare("
            SELECT po_number, item_category, updated_by, updated_at
              FROM vendor_eval_po_item_category
             WHERE po_number IN ($ph)
        ");
        $stmt->execute($po_numbers);
    } catch (PDOException $e) {
        return [];
    }
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(string)$r['po_number']] = [
            'category'   => (string)$r['item_category'],
            'updated_by' => $r['updated_by'],
            'updated_at' => $r['updated_at'],
        ];
    }
    return $out;
}

/**
 * Writes/updates the manual override for one PO. The only write path onto
 * vendor_eval_po_item_category. Returns true on success, false on any
 * validation failure or DB error (never throws — callers are AJAX endpoints
 * that need a clean boolean).
 */
function ve2_item_category_set(PDO $pdo, string $po_number, string $category, ?string $updated_by = null): bool
{
    $po_number = trim($po_number);
    $category  = trim($category);
    if ($po_number === '' || strlen($po_number) > 255 || !in_array($category, ['barang', 'jasa'], true)) {
        return false;
    }
    $updated_by = $updated_by !== null ? trim($updated_by) : null;
    if ($updated_by !== null) {
        $updated_by = mb_substr($updated_by, 0, 255);
        if ($updated_by === '') {
            $updated_by = null;
        }
    }
    try {
        $stmt = $pdo->prepare("
            INSERT INTO vendor_eval_po_item_category (po_number, item_category, updated_by)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE item_category = VALUES(item_category), updated_by = VALUES(updated_by)
        ");
        return $stmt->execute([$po_number, $category, $updated_by]);
    } catch (PDOException $e) {
        return false;
    }
}
