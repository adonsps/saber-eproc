<?php
// includes/profile_helper.php
//
// Shared vendor-profile completeness check. This is the single source of truth
// for "is this vendor's profile fully filled in" — used by the vendor dashboard
// (to unlock the PQ form) and by Sourcing (to gate the Exception Request button).
//
// The rules mirror what used to be inlined in modules/vendor/dashboard.php:
//   - Bentuk Badan Hukum must be chosen
//   - Documents required for EVERYONE: KTP direktur, buku rekening, NPWP, KEB,
//     Tax Statement Letter
//   - Conditional on self-declaration: SPPKP (jika is_sppkp), Sertifikasi UMKM
//     (jika is_umkm) — mirrors the required-on-submit checks in profile_action.php
//   - Extra documents for Badan Usaha: NIB, Akta Pendirian, SK Pengesahan
//   - Extra for Kontraktor (when Badan Usaha): SBUJK

/**
 * A required text field is "filled" only if it contains at least one letter
 * or digit. Catches vendors typing placeholders like "-", ".", "--" or "n/a"-
 * style dashes into required fields (e.g. bank details) just to get past the
 * HTML `required` attribute, which only checks for non-empty.
 */
function vendor_is_blank_placeholder(string $value): bool
{
    return trim($value) === '' || !preg_match('/[a-zA-Z0-9]/', $value);
}

/**
 * True when $value contains a character that doesn't belong in a bank name /
 * branch / account holder name (e.g. "@", "#") — catches keyboard-mash junk
 * like "@bca-" that has letters/digits so vendor_is_blank_placeholder() alone
 * lets it through. Allows letters, digits, spaces and the punctuation real
 * company/bank names use ( . , ' & - / ( ) ).
 */
function vendor_has_invalid_name_characters(string $value): bool
{
    return !preg_match('/^[A-Za-z0-9\s.,\'&\/()-]*$/', $value);
}

/**
 * Same idea as vendor_has_invalid_name_characters(), scoped to a bank account
 * number: digits, spaces and dashes only.
 */
function vendor_has_invalid_account_number_characters(string $value): bool
{
    return !preg_match('/^[0-9\s-]*$/', $value);
}

/**
 * True when the trimmed value starts or ends with a hyphen — the "-word" /
 * "word-" shape (e.g. "-sadad", "sda -", "-ms dfsf-") vendors use to slip a
 * value past vendor_is_blank_placeholder() and vendor_has_invalid_name_characters()
 * (both of which allow "-" since real names like "KCU M.H. Thamrin" use it
 * mid-string) while still not actually naming anything. Real bank/branch/
 * holder names never start or end with a bare hyphen.
 */
function vendor_has_edge_dash(string $value): bool
{
    $trimmed = trim($value);
    return $trimmed !== '' && ($trimmed[0] === '-' || substr($trimmed, -1) === '-');
}

/**
 * @param array $vendor A row from the vendors table (assoc).
 * @return array{complete: bool, missing: string[]} List of human-readable
 *         missing items (empty when complete).
 */
function isVendorProfileComplete(array $vendor): array
{
    $missing = [];

    $legal_entity_type = $vendor['legal_entity_type'] ?? null;

    // 1. Bentuk Badan Hukum
    if (empty($legal_entity_type)) {
        $missing[] = "Bentuk Badan Hukum (di tab Informasi Umum)";
    }

    // 2. Dokumen WAJIB UNTUK SEMUA
    $base_req = [
        'director_id_card_file'           => 'KTP Direktur / Pemilik',
        'bank_cover_path'                 => 'Halaman Depan Buku Rekening',
        'npwp_path'                       => 'Dokumen NPWP',
        'business_ethics_commitment_file' => 'Komitmen Etika Bisnis (KEB)',
        'tax_statement_letter_file'       => 'Tax Statement Letter',
    ];
    foreach ($base_req as $db_field => $doc_name) {
        if (empty($vendor[$db_field])) {
            $missing[] = $doc_name;
        }
    }

    // 3. SPPKP (WAJIB jika vendor declare PKP) dan Sertifikasi UMKM (WAJIB jika
    // vendor declare UMKM) — mirrors the conditional-required checks enforced
    // server-side on submit in modules/vendor/profile_action.php.
    if (!empty($vendor['is_sppkp']) && empty($vendor['sppkp_path'])) {
        $missing[] = 'SPPKP';
    }
    if (!empty($vendor['is_umkm']) && empty($vendor['umkm_cert_file'])) {
        $missing[] = 'Sertifikasi UMKM';
    }

    // 4. Dokumen WAJIB KHUSUS BADAN USAHA
    if ($legal_entity_type === 'Badan Usaha') {
        $bu_req = [
            'nib_path'                    => 'Dokumen NIB',
            'deed_of_establishment_file'  => 'Akta Pendirian',
            'decree_of_legal_entity_file' => 'SK Pengesahan Badan Hukum',
        ];
        foreach ($bu_req as $db_field => $doc_name) {
            if (empty($vendor[$db_field])) {
                $missing[] = $doc_name;
            }
        }

        // 5. SBUJK (Khusus Kontraktor JIKA Badan Usaha)
        $ctype = strtolower($vendor['company_type'] ?? '');
        if (strpos($ctype, 'contractor') !== false || strpos($ctype, 'kontraktor') !== false) {
            if (empty($vendor['business_entity_cert_file'])) {
                $missing[] = 'Sertifikat Badan Usaha Jasa Konstruksi (SBUJK)';
            }
        }
    }

    return [
        'complete' => empty($missing),
        'missing'  => $missing,
    ];
}
