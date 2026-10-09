<?php
// includes/pq_financial_scoring.php
//
// Scoring for the MONEY questions -- "Pendapatan per Tahun", "Jumlah Modal" --
// where the vendor types an amount instead of picking an option, and the score
// is worked out from which band that amount falls in.
//
// WHY THIS EXISTS. Those bands used to live only in calculateFinancialScore()
// in modules/sourcing/update_assessment.php: a hardcoded ladder of thresholds
// per sub-category. The option list on Manage PQ was pure decoration for these
// questions -- editing "(1) <= 9,99 Miliar" changed the words on the screen and
// nothing else, because the number came from PHP. So a vendor with Rp 150 juta
// of capital scored 0 against a Principal ladder that starts at Rp 2 miliar,
// while Manage PQ plainly said 1, and there was no way to reconcile the two
// except by editing PHP.
//
// The bands are now READ FROM THE OPTION LIST, so the thing Manage PQ shows is
// the thing that scores. The hardcoded ladder stays as the fallback for any
// question whose options cannot be parsed into bands, so nothing silently
// starts scoring zero if someone writes a label this cannot read.
//
// THE LABEL GRAMMAR, which is what the existing data already uses:
//
//   (0) < 2 Miliar                 everything below 2 miliar
//   (1) <= 9,99 Miliar             everything up to and including 9,99 miliar
//   (1) 2 Miliar - 14,99 Miliar    a closed range
//   (5) > 50 Miliar                strictly above 50 miliar
//
// Indonesian number formatting throughout: "," is the decimal separator and "."
// groups thousands, so "9,99 Miliar" is 9.99e9 and "1.500 Juta" is 1.5e9.
//
// HOW A BAND IS CHOSEN. Each option is reduced to a LOWER BOUND and whether
// that bound is inclusive, then the bands are tried from the highest bound down
// and the first one the amount satisfies wins. Deliberately not a set of
// closed intervals: the real data has gaps -- "<= 9,99 Miliar" followed by
// "10 Miliar - 50 Miliar" leaves 9.995 miliar in no band at all -- and a
// descending scan puts such an amount in the band below it rather than failing
// to score it. It also reproduces the old ladder exactly, which is what makes
// this a safe swap for the questions whose labels already agreed with it.

/**
 * Is this a money question -- one scored from a typed amount rather than from a
 * chosen option?
 *
 * The definition is the question's own text, which is how
 * update_assessment.php and review_pq.php have always decided it. Centralised
 * here so the three places that ask cannot drift apart.
 */
function pqIsFinancialQuestion(?string $question_text): bool
{
    $t = strtolower((string) $question_text);
    return strpos($t, 'income') !== false
        || strpos($t, 'pendapatan') !== false
        || strpos($t, 'capital') !== false
        || strpos($t, 'modal') !== false;
}

/**
 * Which ladder a money question uses: 'income' or 'capital', or null when it is
 * not a money question at all. Same word test the callers used inline.
 */
function pqFinancialType(?string $question_text): ?string
{
    $t = strtolower((string) $question_text);
    if (strpos($t, 'income') !== false || strpos($t, 'pendapatan') !== false) return 'income';
    if (strpos($t, 'capital') !== false || strpos($t, 'modal') !== false)     return 'capital';
    return null;
}

/**
 * Read one Indonesian-formatted money figure, with or without a unit word.
 *
 * "9,99 Miliar" -> 9.99e9   "50 Miliar" -> 5.0e10   "1.500 Juta" -> 1.5e9
 * "2000000000"  -> 2.0e9
 *
 * @return float|null null when there is no number to read
 */
function pqParseMoney(?string $text): ?float
{
    $t = strtolower(trim((string) $text));
    if ($t === '') return null;

    if (!preg_match('/(\d[\d.,]*)/', $t, $m)) return null;

    // "." groups thousands and "," is the decimal point, so the dots go and the
    // comma becomes one. Doing it in this order matters: "1.234,5" -> "1234.5".
    $num = str_replace('.', '', $m[1]);
    $num = str_replace(',', '.', $num);
    if (!is_numeric($num)) return null;
    $value = (float) $num;

    $units = [
        'triliun' => 1000000000000.0,
        'trilyun' => 1000000000000.0,
        'miliar'  => 1000000000.0,
        'milyar'  => 1000000000.0,
        'milliar' => 1000000000.0,
        'juta'    => 1000000.0,
        'ribu'    => 1000.0,
    ];
    foreach ($units as $word => $mult) {
        if (strpos($t, $word) !== false) return $value * $mult;
    }
    return $value;
}

/**
 * Turn an option list into scoring bands.
 *
 * @return array<int,array{score:int,min:float,inclusive:bool,label:string}>
 *         sorted highest band first, or [] when the labels carry no bands at
 *         all (which is the signal to fall back to the hardcoded ladder).
 */
function pqFinancialTiersFromOptions(?string $help_text): array
{
    $opts = [];
    if ($help_text === null || trim($help_text) === '') return [];

    preg_match_all('/\((\d+)\)\s*(.*?)(?=\(\d+\)|$)/s', $help_text, $m, PREG_SET_ORDER);
    foreach ($m as $row) {
        $score = (int) $row[1];
        $label = trim($row[2]);
        if ($label === '') continue;

        $min = null;
        $inclusive = true;

        // A range "X - Y" is recognised before the comparison forms, because its
        // first number is the lower bound and a naive "<" test would miss it.
        // The dash may be a hyphen, en dash or em dash, and both sides may carry
        // their own unit word ("500 Juta - 2 Miliar").
        if (preg_match('/^([^<>=]*?\d[\d.,]*\s*\p{L}*)\s*[-–—]\s*(.+)$/u', $label, $r)) {
            $min = pqParseMoney($r[1]);
            $inclusive = true;
        } elseif (preg_match('/^(>=|=>|≥)\s*(.+)$/u', $label, $r)) {
            $min = pqParseMoney($r[2]);
            $inclusive = true;
        } elseif (preg_match('/^(>)\s*(.+)$/u', $label, $r)) {
            $min = pqParseMoney($r[2]);
            $inclusive = false;                     // strictly above
        } elseif (preg_match('/^(<=|=<|≤|<)\s*(.+)$/u', $label, $r)) {
            // An upper bound describes the BOTTOM band: anything at or under it,
            // with nothing below. Its lower bound is therefore zero.
            $min = 0.0;
            $inclusive = true;
        } else {
            $only = pqParseMoney($label);
            if ($only !== null) { $min = $only; $inclusive = true; }
        }

        if ($min === null) continue;                // not a band -- skip it
        $opts[] = ['score' => $score, 'min' => $min, 'inclusive' => $inclusive, 'label' => $label];
    }

    if (empty($opts)) return [];

    // Highest band first. At an equal bound the EXCLUSIVE one is the higher
    // band: "> 50 Miliar" sits above a range that ends at 50 Miliar.
    usort($opts, function ($a, $b) {
        if ($a['min'] != $b['min']) return $b['min'] <=> $a['min'];
        return ($a['inclusive'] ? 1 : 0) <=> ($b['inclusive'] ? 1 : 0);
    });

    return $opts;
}

/**
 * Score an amount against the bands configured on the question.
 *
 * @return int|null null when the options carry no usable bands, which tells the
 *                  caller to fall back to the hardcoded ladder rather than
 *                  score the vendor zero on a label this could not read.
 */
function pqFinancialScoreFromOptions($amount, ?string $help_text): ?int
{
    $tiers = pqFinancialTiersFromOptions($help_text);
    if (empty($tiers)) return null;

    // Amounts arrive as raw digits from the review form ("150000000"), so only
    // digits and a decimal point survive -- matching calculateFinancialScore().
    $value = (float) preg_replace('/[^0-9.]/', '', (string) $amount);

    foreach ($tiers as $t) {
        if ($t['inclusive'] ? ($value >= $t['min']) : ($value > $t['min'])) {
            return $t['score'];
        }
    }

    // Below every band: the lowest configured score, not a hardcoded 0, since
    // a question may legitimately start at 1.
    return (int) end($tiers)['score'];
}

/**
 * Re-score every stored answer to one money question against its CURRENT bands.
 *
 * The counterpart of the label re-mapping that choice questions get: when the
 * bands on a money question move, the vendor's typed amount has not changed but
 * the score it earns has, and the stored score is the one the review screen
 * shows. Without this, editing "(1) <= 9,99 Miliar" in Manage PQ leaves every
 * existing submission on the number the old bands gave it.
 *
 * The AMOUNT is never touched -- only the score derived from it. Answers with
 * no amount recorded are skipped, since there is nothing to derive from.
 *
 * @param int[] $only_submissions the submissions this edit may touch: the ones
 *        still with Sourcing (see PQ_SCORING_OPEN_STATUSES in
 *        includes/pq_question_change_log.php). A submission already forwarded
 *        to VM or approved keeps the score it was decided on, so an empty list
 *        means there is nothing to re-derive.
 * @return array{answers:int,submissions:int[]} rows changed and whose
 *         submissions they were, for the caller to re-total.
 */
function pqRedriveFinancialAnswers(mysqli $conn, int $question_id, ?string $help_text, array $only_submissions): array
{
    $out = ['answers' => 0, 'submissions' => []];
    if ($question_id <= 0 || empty($only_submissions)) return $out;
    if (empty(pqFinancialTiersFromOptions($help_text))) return $out;   // nothing to score against

    $sids = implode(',', array_map('intval', $only_submissions));
    $res = $conn->query(
        "SELECT id, submission_id, selected_score, remarks
         FROM pq_answers
         WHERE question_id = " . (int) $question_id . "
           AND submission_id IN ($sids)
           AND remarks IS NOT NULL AND remarks <> ''"
    );
    if (!$res) return $out;

    $touched = [];
    $upd = $conn->prepare("UPDATE pq_answers SET selected_score = ? WHERE id = ?");
    while ($row = $res->fetch_assoc()) {
        $new = pqFinancialScoreFromOptions($row['remarks'], $help_text);
        if ($new === null) continue;
        $old = $row['selected_score'] !== null ? (int) $row['selected_score'] : null;
        if ($old === $new) continue;

        $id = (int) $row['id'];
        $upd->bind_param('ii', $new, $id);
        $upd->execute();
        $out['answers']++;
        $touched[(int) $row['submission_id']] = true;
    }
    $out['submissions'] = array_keys($touched);
    return $out;
}
