<?php
// includes/pq_risk_helper.php
//
// pq_submissions.risk_percentage / risk_category WAJIB memakai dua fungsi ini
// agar konsisten dengan enum kolomnya:
//   risk_category ENUM('Very Low','Low','Moderate','High','Critical')

/** Persentase risiko dari skor tertimbang: (max - total) / max * 100, clamp 0-100. */
function pqRiskPercentage(float $total_weighted_score, float $max_possible_score): float
{
    if ($max_possible_score <= 0) {
        return 0.0;
    }
    $pct = (($max_possible_score - $total_weighted_score) / $max_possible_score) * 100;
    return max(0.0, min(100.0, $pct));
}

/** Kategori 5-TIER RISK LOGIC dari persentase risiko. */
function pqRiskCategory(float $risk_percentage): string
{
    if ($risk_percentage <= 15) return 'Very Low';
    if ($risk_percentage <= 29) return 'Low';
    if ($risk_percentage <= 50) return 'Moderate';
    if ($risk_percentage <= 61) return 'High';
    return 'Critical';
}

/**
 * Terjemahkan company_type / sub_company_type vendor ke label yang dipakai
 * pq_questions.variant_target dan pq_weight.sub_variant_target.
 *
 * Set pertanyaan Perseorangan berdiri sendiri: variant_target DAN
 * sub_variant_target sama-sama 'Perseorangan' (bukan lagi memakai ulang baris
 * 'Individual / Foreman' milik Contractor). Vendor memakai set itu bila
 * sub_company_type-nya 'Perseorangan' — mencakup company_type = 'Perseorangan'
 * maupun vendor yang mendeklarasikan dirinya UMKM, yang company_type-nya tetap
 * Supplier/Contractor. Contractor + legal entity Perseorangan tidak termasuk:
 * sub_company_type-nya 'Individual / Foreman' dan setnya memang berbeda.
 *
 * @return array{0:string,1:string} [main_type_for_match, pq_weight_sub_type]
 */
function pqScoringVariants(?string $companyType, ?string $subCompanyType): array
{
    if ($subCompanyType === 'Perseorangan') {
        return ['Perseorangan', 'Perseorangan'];
    }
    return [(string) $companyType, (string) $subCompanyType];
}

/**
 * Apakah satu pertanyaan PQ berlaku untuk kombinasi tipe vendor tertentu?
 *
 * $allSubTargets adalah GROUP_CONCAT seluruh pq_weight.sub_variant_target milik
 * pertanyaan tersebut; kosong berarti pertanyaan berlaku untuk semua sub-tipe.
 */
function pqQuestionApplies(?string $variantTarget, ?string $allSubTargets, string $mainType, string $subType): bool
{
    if (!empty($variantTarget) && stripos($variantTarget, $mainType) === false) {
        return false;
    }
    if (!empty($allSubTargets) && stripos($allSubTargets, $subType) === false) {
        return false;
    }
    return true;
}
