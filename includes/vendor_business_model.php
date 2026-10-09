<?php
// includes/vendor_business_model.php
//
// Two vendor self-declarations that steer the whole pre-qualification track:
//
//   1. vendors.business_model — the multi-select "Model Bisnis" question asked at
//      registration. A vendor commonly does more than one kind of work, so the
//      answer is a SET of codes, not a single value, and company_type is DERIVED
//      from it rather than picked by the vendor: any answer that includes
//      construction/MEP work makes the vendor a Contractor (the stricter of the
//      two review tracks), everything else is a Supplier. Choosing (a) and (b)
//      together therefore yields Contractor, because (b) is present.
//
//      The raw answers are kept verbatim so Sourcing/STT can read what the vendor
//      actually claimed before assigning the Vendor Sub-Category on review_pq.php.
//
//   2. vendors.is_umkm — declared on the vendor profile, right under the PKP/SPPKP
//      declaration. An UMKM vendor is pre-qualified with the standalone
//      'Perseorangan' question set no matter what its company_type is, exactly
//      like a company_type = 'Perseorangan' vendor. Since the PQ question set is
//      keyed by pq_weight.sub_variant_target, that also pins sub_company_type to
//      'Perseorangan' (see the auto-classify blocks in review_pq.php / pq_form.php).
//
// Idempotent + MySQL 8 (local) and MariaDB (prod) safe: the ALTERs run in
// try/catch and duplicate-column errors (1060) are swallowed, matching the
// existing idiom in includes/vendor_account_schema.php and config.php.

if (!function_exists('ensure_vendor_business_model_schema')) {

    /**
     * Add vendors.business_model + vendors.is_umkm. Errors are logged, never fatal.
     */
    function ensure_vendor_business_model_schema(mysqli $conn): void
    {
        $vendorCols = [
            // Comma-separated codes from vendor_business_model_options(); NULL for
            // vendors registered before this question existed and for the Direct
            // Registration flow, where Sourcing picks company_type by hand.
            "business_model VARCHAR(255) NULL DEFAULT NULL",
            "is_umkm TINYINT(1) NOT NULL DEFAULT 0",
            // Sertifikasi UMKM (Surat Keterangan UMKM) — required in the Dokumen
            // Legal tab once is_umkm is checked, mirroring sppkp_path/is_sppkp.
            // Unlike SPPKP this one carries an OCR-extracted number + status, so
            // it follows the *_file / *_number / *_status trio used by NIB etc.
            "umkm_cert_file VARCHAR(255) NULL DEFAULT NULL",
            "umkm_cert_number VARCHAR(100) NULL DEFAULT NULL",
            "umkm_cert_status VARCHAR(20) NULL DEFAULT NULL",
        ];
        foreach ($vendorCols as $ddl) {
            try {
                $conn->query("ALTER TABLE vendors ADD COLUMN $ddl");
            } catch (mysqli_sql_exception $e) {
                if ((int) $e->getCode() !== 1060) {
                    error_log("[vendor_business_model] add vendors column failed: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * The "Model Bisnis" catalogue: stored code => label shown to the vendor.
     * Codes are what lands in vendors.business_model, so they must stay stable.
     */
    function vendor_business_model_options(): array
    {
        return [
            'jasa_umum'       => 'Kami Penyedia Jasa Umum (Non-Konstruksi)',
            'jasa_konstruksi' => 'Kami Penyedia Jasa Konstruksi/MEP',
            'jasa_ekspedisi'  => 'Kami Penyedia Jasa Ekspedisi',
            'barang_umum'     => 'Kami Produsen/Distributor Barang Umum',
        ];
    }

    /**
     * The one option that forces the Contractor track.
     */
    function vendor_business_model_contractor_code(): string
    {
        return 'jasa_konstruksi';
    }

    /**
     * Keep only known codes, de-duplicated and in catalogue order, from whatever
     * the form posted. Returns [] when nothing valid was submitted.
     *
     * @param mixed $raw $_POST['business_model'] — expected array, but never trusted
     * @return string[]
     */
    function sanitize_business_model_codes($raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        $submitted = array_map(static fn($v) => trim((string) $v), $raw);
        return array_values(array_filter(
            array_keys(vendor_business_model_options()),
            static fn($code) => in_array($code, $submitted, true)
        ));
    }

    /**
     * Derive company_type from the vendor's answers.
     * Construction/MEP anywhere in the selection wins, because a vendor that does
     * any construction work must go through the Contractor assessment.
     * NULL when nothing was selected — callers must reject that.
     */
    function company_type_from_business_model(array $codes): ?string
    {
        if (empty($codes)) {
            return null;
        }
        return in_array(vendor_business_model_contractor_code(), $codes, true)
            ? 'Contractor'
            : 'Supplier';
    }

    /**
     * Decode a stored vendors.business_model value into display labels.
     * Unknown/legacy codes are dropped rather than shown raw.
     *
     * @return string[] label list, empty when the vendor never answered
     */
    function business_model_labels(?string $stored): array
    {
        $options = vendor_business_model_options();
        return array_values(array_map(
            static fn($code) => $options[$code],
            sanitize_business_model_codes((string) $stored)
        ));
    }

    /**
     * Does this vendor get the standalone 'Perseorangan' PQ question set?
     *
     * True for the dedicated 'Perseorangan' company_type AND for any vendor that
     * declared itself UMKM on its profile. Every stage of the PQ pipeline
     * (pq_form, submit_pq, review_pq, update_assessment, approve_pq) must ask this
     * the same way — if the vendor answers one question set and Sourcing scores
     * another, the weighted score silently collapses to zero.
     *
     * @param mixed $isUmkm vendors.is_umkm (string from mysqli, int, or null)
     */
    function vendor_uses_perseorangan_pq(?string $companyType, $isUmkm): bool
    {
        return $companyType === 'Perseorangan' || !empty($isUmkm);
    }
}
