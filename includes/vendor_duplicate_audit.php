<?php
// includes/vendor_duplicate_audit.php
//
// Finds the companies that hold more than one row in the register, and says
// which row is the one worth keeping.
//
// Registration only ever de-duplicated on email, so a company could sign up once
// per mailbox it owned. This groups the register by fuzzy company name — using
// includes/vendor_name_similarity.php, the same matcher the registration gate
// runs — then ranks each group by how far the vendor got through the pipeline.
//
// Extracted so the CLI audit (tools/audit_duplicate_vendors.php) and the VM
// screen (modules/vendormgt/duplicate_vendors.php) compute the SAME clusters and
// the SAME keeper. Two implementations of "which row survives" would eventually
// disagree, and they would disagree about live vendor accounts.
//
// READ-ONLY: every query here is a SELECT. Deactivation is a separate, gated
// action (modules/vendormgt/vendor_active_action.php).

require_once __DIR__ . '/vendor_name_similarity.php';

if (!function_exists('vendor_dup_status_rank')) {

/**
 * Pipeline ranking — lower is further along, so lower wins the keeper slot.
 *
 * The order is the business rule as given: approved > VM review > Sourcing
 * review / revision > PQ submitted > Full Profile (the `invited_pq` status,
 * which the VM and Sourcing dashboards both label "Full Profile") > migrated >
 * registered.
 *
 * `revision_requested` sits between the two review stages because the status
 * alone does not record which stage sent it back.
 *
 * Statuses below the rule's last named stage are ranked PROVISIONALLY — the
 * audit flags any cluster whose outcome actually turns on one of them, so the
 * placement can be confirmed before a vendor is deactivated over it.
 */
define("VENDOR_DUP_STATUS_RANK", [
    'approved'               => 1,
    'under_review_vm_head'   => 2,
    'under_review_vm'        => 3,
    'under_review_sourcing'  => 4,
    'returned_to_sourcing'   => 5,
    'revision_requested'     => 6,
    'pq_submitted'           => 7,
    'invited_pq'             => 8,   // "Full Profile"
    'migrated'               => 9,
    // ---- not covered by the stated rule ----
    'min_info_submitted'     => 10,
    'draft'                  => 11,
    'registration_submitted' => 12,
    'registered'             => 13,
    'rejected'               => 14,
]);

/** Statuses the stated rule never mentions; their placement above is a guess. */
define("VENDOR_DUP_UNRULED", ['min_info_submitted', 'draft', 'rejected']);

/** Tables that hang off a vendor row. None has a foreign key back to `vendors`,
 *  which is exactly why duplicates are deactivated rather than deleted. */
define("VENDOR_DUP_CHILD_TABLES", [
    'users'                      => 'vendor_id',
    'pq_submissions'             => 'vendor_id',
    'vendor_products'            => 'vendor_id',
    'exception_requests'         => 'vendor_id',
    'product_sample_requests'    => 'vendor_id',
    'notifications'              => 'vendor_id',
    'vendor_contact_audit'       => 'vendor_id',
    'vendor_document_audit'      => 'vendor_id',
    'vendor_migration_reminders' => 'vendor_id',
]);

function vendor_dup_status_rank(?string $status): int
{
    return VENDOR_DUP_STATUS_RANK[$status ?? ''] ?? 99;
}

/** Human label for a pipeline status, for screens that shouldn't show raw enums. */
function vendor_dup_status_label(?string $status): string
{
    static $labels = [
        'approved'               => 'Approved',
        'under_review_vm_head'   => 'Under Review VM Head',
        'under_review_vm'        => 'Under Review VM',
        'under_review_sourcing'  => 'Under Review Sourcing',
        'returned_to_sourcing'   => 'Returned to Sourcing',
        'revision_requested'     => 'Revision Requested',
        'pq_submitted'           => 'PQ Submitted',
        'invited_pq'             => 'Full Profile',
        'migrated'               => 'Migrated',
        'min_info_submitted'     => 'Min Info Submitted',
        'draft'                  => 'Draft',
        'registration_submitted' => 'Registration Submitted',
        'registered'             => 'Registered',
        'rejected'               => 'Rejected',
    ];
    return $labels[$status ?? ''] ?? ($status ?: 'Unknown');
}

/**
 * Build the full duplicate picture.
 *
 * Options:
 *   threshold  float  character-similarity bar, default 95
 *   use_word   bool   also link by whole-word containment, default true
 *   max_extra  int    words the longer name may add and still count, default 1
 *   word_only  bool   return ONLY what the word signal adds (diagnostic)
 *
 * Returns:
 *   plan          list of ['keeper'=>row, 'drops'=>[row,…], 'decisive'=>bool]
 *   pair_reason   [idA][idB] => why they were linked, for display
 *   total         vendors considered
 *   flagged       how many rows sit in the DROP position
 *   orphan_rows   dependent rows hanging off those
 *   char_pairs / word_pairs / compared   matcher statistics
 *
 * Every vendor row is loaded, active or not: a vendor already deactivated must
 * still appear in its cluster, or the screen would show a lone "keeper" and no
 * explanation of what was done to the rest.
 */
function vendor_dup_build_plan(mysqli $conn, array $opts = []): array
{
    $threshold = (float) ($opts['threshold'] ?? 95.0);
    $useWord   = (bool)  ($opts['use_word']  ?? true);
    $maxExtra  = (int)   ($opts['max_extra'] ?? 1);
    $wordOnly  = (bool)  ($opts['word_only'] ?? false);
    if ($wordOnly) { $useWord = true; }

    // ---- load the register -------------------------------------------------
    $hasActive = $conn->query("SHOW COLUMNS FROM vendors LIKE 'is_active'")->num_rows > 0;
    $activeCol = $hasActive
        ? "v.is_active, v.deactivated_at, v.deactivated_by, v.deactivation_reason"
        : "1 AS is_active, NULL AS deactivated_at, NULL AS deactivated_by, NULL AS deactivation_reason";

    $vendors = [];
    $res = $conn->query(
        "SELECT v.id, v.company_name, v.email, v.registration_status, v.vendor_code,
                v.user_id, v.created_at, v.updated_at, {$activeCol}
           FROM vendors v
          WHERE v.company_name IS NOT NULL AND v.company_name <> ''
          ORDER BY v.id"
    );
    while ($row = $res->fetch_assoc()) {
        $row['id']        = (int) $row['id'];
        $row['is_active'] = (int) $row['is_active'];
        $row['norm']      = vendor_name_normalize($row['company_name']);
        $row['len']       = strlen($row['norm']);
        $vendors[$row['id']] = $row;
    }
    $total = count($vendors);

    // ---- how much history hangs off each row -------------------------------
    $childCounts = [];
    foreach (VENDOR_DUP_CHILD_TABLES as $table => $col) {
        try {
            $r = $conn->query("SELECT `$col` AS vid, COUNT(*) c FROM `$table` WHERE `$col` IS NOT NULL GROUP BY `$col`");
            while ($cr = $r->fetch_assoc()) {
                $childCounts[(int) $cr['vid']][$table] = (int) $cr['c'];
            }
        } catch (mysqli_sql_exception $e) {
            error_log("[vendor_dup] skipped child table {$table}: " . $e->getMessage());
        }
    }

    // ---- cluster by name (union-find over the matching pairs) --------------
    // Transitive on purpose: if A matches B and B matches C, all three are the
    // same company even when A and C fall just under the bar on their own.
    $parent = [];
    foreach ($vendors as $id => $_) { $parent[$id] = $id; }
    $find = function (int $x) use (&$parent, &$find): int {
        while ($parent[$x] !== $x) { $parent[$x] = $parent[$parent[$x]]; $x = $parent[$x]; }
        return $x;
    };
    $union = function (int $a, int $b) use (&$parent, $find): void {
        $ra = $find($a); $rb = $find($b);
        if ($ra !== $rb) { $parent[max($ra, $rb)] = min($ra, $rb); }
    };

    $ids = array_keys($vendors);
    $n = count($ids);
    $pairReason = [];
    $compared = $charPairs = $wordPairs = 0;

    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $a = $vendors[$ids[$i]];
            $b = $vendors[$ids[$j]];

            // CHAR — typos, punctuation, case. Skipped when the two lengths put
            // the threshold out of reach before a character is compared.
            $pct = 0.0;
            $charHit = false;
            if (vendor_name_length_can_match($a['len'], $b['len'], $threshold)) {
                $compared++;
                $pct = vendor_name_similarity($a['norm'], $b['norm']);
                $charHit = $pct >= $threshold;
            }

            // WORD — the inserted/dropped-word case CHAR structurally cannot see.
            $wordHit = false;
            $word = null;
            if ($useWord && !$charHit) {
                $word = vendor_name_token_overlap($a['norm'], $b['norm']);
                $wordHit = $word['pct'] >= 100.0
                    && $word['min_tokens'] >= 2
                    && $word['extra'] <= $maxExtra;
            }

            if (!$charHit && !$wordHit)  { continue; }
            if ($wordOnly && $charHit)   { continue; }

            $charHit ? $charPairs++ : $wordPairs++;
            $union($a['id'], $b['id']);

            $reason = $charHit
                ? ['signal' => 'char', 'label' => sprintf('%.1f%% name match', $pct)]
                : ['signal' => 'word', 'label' => sprintf('same words, +%d extra', $word['extra'])];
            $pairReason[$a['id']][$b['id']] = $reason;
            $pairReason[$b['id']][$a['id']] = $reason;
        }
    }

    $clusters = [];
    foreach ($ids as $id) { $clusters[$find($id)][] = $id; }
    $clusters = array_values(array_filter($clusters, static fn($c) => count($c) > 1));
    usort($clusters, static fn($a, $b) => count($b) <=> count($a));

    // ---- pick the keeper in each cluster -----------------------------------
    $plan = [];
    $flagged = 0;
    $orphanRows = 0;

    foreach ($clusters as $members) {
        $rows = [];
        foreach ($members as $id) {
            $v = $vendors[$id];
            $kids = $childCounts[$id] ?? [];
            $v['child_total'] = array_sum($kids);
            $v['children']    = $kids;
            $v['rank']        = vendor_dup_status_rank($v['registration_status']);
            $rows[] = $v;
        }

        // Furthest through the pipeline, then the row carrying the most history,
        // then the oldest — so at a genuine tie the account with a past survives.
        usort($rows, static function ($a, $b) {
            return [$a['rank'], -$a['child_total'], $a['id']]
               <=> [$b['rank'], -$b['child_total'], $b['id']];
        });

        $keeper = $rows[0];
        $drops  = array_slice($rows, 1);

        // Does a provisionally-ranked status actually change this outcome? It
        // does if the keeper holds one, or if the cluster spans more than one
        // rank — i.e. wherever the guess is load-bearing rather than incidental.
        $unruledHere = array_intersect(VENDOR_DUP_UNRULED, array_column($rows, 'registration_status'));
        $decisive = $unruledHere !== [] && (
            in_array($keeper['registration_status'], VENDOR_DUP_UNRULED, true)
            || count(array_unique(array_column($rows, 'rank'))) > 1
        );

        foreach ($drops as $d) { $orphanRows += $d['child_total']; }
        $flagged += count($drops);

        $plan[] = ['keeper' => $keeper, 'drops' => $drops, 'decisive' => $decisive];
    }

    return [
        'plan'        => $plan,
        'pair_reason' => $pairReason,
        'total'       => $total,
        'flagged'     => $flagged,
        'orphan_rows' => $orphanRows,
        'compared'    => $compared,
        'char_pairs'  => $charPairs,
        'word_pairs'  => $wordPairs,
        'threshold'   => $threshold,
        'max_extra'   => $maxExtra,
    ];
}

/** Why two rows were linked, for display. Falls back when the link was transitive. */
function vendor_dup_reason(array $pairReason, int $keeperId, int $dropId): array
{
    return $pairReason[$keeperId][$dropId]
        ?? ['signal' => 'transitive', 'label' => 'linked through another row'];
}

/** True when any row in the cluster was tied in by words rather than spelling —
 *  the roughly half-precise signal, so these need a human before any action. */
function vendor_dup_cluster_needs_check(array $entry, array $pairReason): bool
{
    foreach ($entry['drops'] as $d) {
        if (vendor_dup_reason($pairReason, $entry['keeper']['id'], $d['id'])['signal'] === 'word') {
            return true;
        }
    }
    return false;
}

}
