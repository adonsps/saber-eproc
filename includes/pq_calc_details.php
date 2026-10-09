<?php
// includes/pq_calc_details.php
//
// The working figures behind a calculated PQ answer.
//
// WHY THIS EXISTS. Quick Ratio and Debt to Equity used to be plain radio
// questions: the vendor picked a band ("120% - 180%") and that band was all that
// reached the reviewer. The calculators on the PQ form asked for the numbers the
// ratio is actually made of, computed the percentage, ticked the matching band,
// and then threw the numbers away. Sourcing / VM / VM Head reviewing that answer
// against the vendor's financial statement had nothing to check it against.
//
// The figures are now kept next to the answer, in pq_answers.calc_details, as
// JSON. The SCORE still comes from the radio band exactly as before -- this is
// evidence shown beside the answer, never an input to scoring.
//
// The stored shape (all amounts in rupiah, result in percent):
//
//   {"type":"quick_ratio","aset_lancar":5000000,"persediaan":1000000,
//    "kewajiban_lancar":2500000,"result":160,"saved_at":"2026-08-12 14:03:11"}
//
//   {"type":"debt_to_equity","total_utang":3000000,"total_ekuitas":4000000,
//    "result":75,"saved_at":"2026-08-18 16:20:04"}
//
// `result` is recomputed here from the amounts rather than trusted from the
// browser, so a reviewer can never be shown a percentage that does not follow
// from the numbers printed above it.

/**
 * Is this the Quick Ratio question -- one of the two the calculators belong to?
 *
 * Same word test pq_form.php uses to decide whether to show the calculator
 * button, kept here so the form, the save and the two review screens cannot
 * drift apart on what counts as a Quick Ratio question.
 */
function pqIsQuickRatioQuestion(?string $question_text): bool
{
    $t = strtolower(trim((string) $question_text));
    return strpos($t, 'quick') !== false || strpos($t, 'rasio cepat') !== false;
}

/**
 * Is this the Debt to Equity question?
 *
 * Mirrors pq_form.php's $is_der test. Quick Ratio is checked first everywhere
 * this pair is used, so a question that somehow tripped both tests is treated
 * as Quick Ratio rather than being wired to two calculators at once.
 */
function pqIsDebtToEquityQuestion(?string $question_text): bool
{
    $t = strtolower(trim((string) $question_text));
    if (pqIsQuickRatioQuestion($t)) return false;
    return strpos($t, 'debt') !== false
        || preg_match('/\bder\b/', $t) === 1
        || strpos($t, 'equity') !== false
        || strpos($t, 'ekuitas') !== false;
}

/**
 * Read one amount as the PQ form posts it.
 *
 * The calculator inputs are formatted Indonesian-style while typing ("5.000.000"),
 * so everything but the digits goes. Rupiah amounts here are whole -- the form
 * never produces a decimal -- which is why this is digits-only rather than the
 * fuller parse in pq_financial_scoring.php.
 *
 * Digits-only also means an amount can never come back negative, which is what
 * lets the band matching treat a Debt to Equity ratio as always >= 0.
 *
 * @return float|null null when there is no number at all (blank input)
 */
function pqCalcParseAmount($raw): ?float
{
    $digits = preg_replace('/[^0-9]/', '', (string) $raw);
    if ($digits === '' || $digits === null) return null;
    return (float) $digits;
}

/**
 * Build the stored JSON from one posted calc_qr[<qid>] group.
 *
 * @param array $posted expects keys aset, persediaan, kewajiban
 * @return string|null null when the vendor never used the calculator, or filled
 *                     it so incompletely that there is no ratio to show. Nothing
 *                     is stored in that case -- an absent calc_details means
 *                     "no figures given", which is what the review screens read.
 */
function pqCalcEncodeQuickRatio(array $posted): ?string
{
    $aset = pqCalcParseAmount($posted['aset'] ?? null);
    $pers = pqCalcParseAmount($posted['persediaan'] ?? null);
    $kew  = pqCalcParseAmount($posted['kewajiban'] ?? null);

    // Persediaan may legitimately be 0 (a service contractor holds no stock),
    // so only the two figures the ratio cannot be computed without are required.
    if ($aset === null || $kew === null || $kew <= 0) return null;
    if ($pers === null) $pers = 0.0;

    return json_encode([
        'type'             => 'quick_ratio',
        'aset_lancar'      => $aset,
        'persediaan'       => $pers,
        'kewajiban_lancar' => $kew,
        'result'           => round((($aset - $pers) / $kew) * 100, 2),
        'saved_at'         => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Build the stored JSON from one posted calc_der[<qid>] group.
 *
 * @param array $posted expects keys utang, ekuitas
 * @return string|null null when there is no ratio to show -- same contract as
 *                     pqCalcEncodeQuickRatio(). Total Utang of 0 is a real
 *                     answer (a debt-free vendor, ratio 0%); Total Ekuitas of 0
 *                     is not, since the ratio divides by it.
 */
function pqCalcEncodeDebtToEquity(array $posted): ?string
{
    $utang   = pqCalcParseAmount($posted['utang'] ?? null);
    $ekuitas = pqCalcParseAmount($posted['ekuitas'] ?? null);

    if ($ekuitas === null || $ekuitas <= 0) return null;
    if ($utang === null) return null;

    return json_encode([
        'type'          => 'debt_to_equity',
        'total_utang'   => $utang,
        'total_ekuitas' => $ekuitas,
        'result'        => round(($utang / $ekuitas) * 100, 2),
        'saved_at'      => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Read a stored calc_details value back, whichever calculator wrote it.
 *
 * @return array|null null for anything that is not a usable record, including
 *                    rows written before this column existed and any JSON that
 *                    has since been corrupted. Callers treat null as "vendor
 *                    gave no figures" and show nothing.
 *
 * The returned shape is deliberately uniform across both calculators so the
 * render helpers below need no per-type branching:
 *
 *   type     machine name, as stored
 *   label    what to call the ratio on screen
 *   amounts  form-field key => rupiah amount, for repopulating the calculator
 *   rows     display label => rupiah amount, in the order they should be listed
 *   result   the percentage, recomputed here (see the file header)
 */
function pqCalcDecode(?string $json): ?array
{
    if ($json === null || trim($json) === '') return null;

    $data = json_decode($json, true);
    if (!is_array($data)) return null;

    $type = $data['type'] ?? '';

    if ($type === 'quick_ratio') {
        if (!isset($data['aset_lancar'], $data['kewajiban_lancar'])) return null;

        $aset = (float) $data['aset_lancar'];
        $pers = (float) ($data['persediaan'] ?? 0);
        $kew  = (float) $data['kewajiban_lancar'];
        if ($kew <= 0) return null;

        return [
            'type'    => 'quick_ratio',
            'label'   => 'Quick Ratio',
            'amounts' => ['aset' => $aset, 'persediaan' => $pers, 'kewajiban' => $kew],
            'rows'    => [
                'Total Aset Lancar' => $aset,
                'Total Persediaan'  => $pers,
                'Kewajiban Lancar'  => $kew,
            ],
            // Recomputed, never taken from the stored field -- see the file header.
            'result'   => round((($aset - $pers) / $kew) * 100, 2),
            'saved_at' => $data['saved_at'] ?? null,
        ];
    }

    if ($type === 'debt_to_equity') {
        if (!isset($data['total_utang'], $data['total_ekuitas'])) return null;

        $utang   = (float) $data['total_utang'];
        $ekuitas = (float) $data['total_ekuitas'];
        if ($ekuitas <= 0) return null;

        return [
            'type'    => 'debt_to_equity',
            'label'   => 'Debt to Equity',
            'amounts' => ['utang' => $utang, 'ekuitas' => $ekuitas],
            'rows'    => [
                'Total Utang (Liabilities)' => $utang,
                'Total Ekuitas (Modal)'     => $ekuitas,
            ],
            'result'   => round(($utang / $ekuitas) * 100, 2),
            'saved_at' => $data['saved_at'] ?? null,
        ];
    }

    return null;
}

/**
 * The same figures, sized for the Vendor Response column of the PQ Result PDF.
 *
 * Kept deliberately plain -- inline-styled text and <br>, no nested table --
 * because TCPDF's writeHTML() renders this inside a table cell that is already
 * only 28% of the page width, and nested tables there come out mangled.
 *
 * Reads as a continuation of the answer above it, so callers append it to the
 * response cell rather than giving it a column of its own.
 *
 * @return string '' when there are no figures, so callers can concatenate blind.
 */
function pqCalcPdfHtml(?string $json): string
{
    $d = pqCalcDecode($json);
    if ($d === null) return '';

    $html = '<br><span style="color:#495057; font-size:8px;">'
          . '<em>Rincian ' . htmlspecialchars($d['label']) . ':</em><br>';
    foreach ($d['rows'] as $label => $amount) {
        $html .= htmlspecialchars($label) . ': Rp ' . number_format((float) $amount, 0, ',', '.') . '<br>';
    }
    $html .= 'Hasil Perhitungan: <strong>' . number_format($d['result'], 2, ',', '.') . '%</strong>'
           . '</span>';

    return $html;
}

/**
 * The evidence block shown beside the answer on the review screens.
 *
 * One function for all three reviewer roles (Sourcing review_pq, VM and VM Head
 * approve_pq) so they cannot show the same vendor different numbers.
 *
 * @return string '' when there are no figures, so callers can echo it blind.
 */
function pqCalcSummaryHtml(?string $json): string
{
    $d = pqCalcDecode($json);
    if ($d === null) return '';

    $pct = number_format($d['result'], 2, ',', '.') . '%';

    $html  = '<div class="mb-2 p-2 bg-white rounded border border-info border-opacity-50 small">';
    $html .= '<div class="fw-bold text-muted mb-2 text-uppercase" style="font-size: 0.6rem;">'
           . '<i class="fas fa-calculator me-1 text-info"></i>Rincian Perhitungan '
           . htmlspecialchars($d['label']) . '</div>';
    $html .= '<table class="table table-sm mb-1" style="font-size: 0.72rem;"><tbody>';
    foreach ($d['rows'] as $label => $amount) {
        $value = 'Rp ' . number_format((float) $amount, 0, ',', '.');
        $html .= '<tr><td class="ps-0 py-1 border-0 text-muted">' . htmlspecialchars($label) . '</td>'
               . '<td class="pe-0 py-1 border-0 text-end fw-bold text-dark">' . htmlspecialchars($value) . '</td></tr>';
    }
    $html .= '</tbody></table>';
    $html .= '<div class="d-flex justify-content-between align-items-center border-top pt-1">'
           . '<span class="fw-bold text-dark" style="font-size: 0.7rem;">' . htmlspecialchars($d['label']) . '</span>'
           . '<span class="badge bg-info text-white">' . htmlspecialchars($pct) . '</span></div>';

    if (!empty($d['saved_at'])) {
        $html .= '<div class="text-muted mt-1" style="font-size: 0.6rem;">Diisi vendor: '
               . htmlspecialchars($d['saved_at']) . '</div>';
    }
    $html .= '</div>';

    return $html;
}
