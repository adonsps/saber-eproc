<?php
// includes/sourcing_note_helper.php
//
// Helpers for the Sourcing "SUMMARY NOTE" (pq_submissions.sourcing_notes).
//
// Business rule: when a vendor's risk_category is High or Critical, the sourcing
// team must document the REASON the vendor is being registered. The vendor's
// TYPE is already canonical on the vendors table (company_type / sub_company_type),
// so it is auto-filled from there (never re-typed) to avoid discrepancies. Both
// are composed into the existing free-text sourcing_notes column with stable
// labels so they can be parsed back for editing. Used by:
//   - modules/sourcing/review_pq.php  (UI render + prefill)
//   - modules/admin/review_pq.php     (UI render + prefill)
//   - modules/sourcing/review_action.php (server-side backstop + authoritative rebuild)
//
// risk_category values come from includes/pq_risk_helper.php and are exactly:
//   'Very Low','Low','Moderate','High','Critical'

if (!function_exists('sourcingNoteRequiresStructure')) {

    // Labels used to compose/parse the structured note. Kept as constants so the
    // render, prefill, and rebuild paths never drift.
    define('SOURCING_NOTE_TYPE_LABEL', 'Vendor Type:');
    define('SOURCING_NOTE_REASON_LABEL', 'Reason for Registration:');

    /** Only High/Critical vendors must document type + reason. */
    function sourcingNoteRequiresStructure(?string $risk_category): bool
    {
        return in_array($risk_category, ['High', 'Critical'], true);
    }

    /**
     * Canonical vendor type from the registration enums, e.g. "Supplier / Retail".
     * This is the single source of truth for the type shown/stored — sourcing never
     * re-types it, so it can't diverge from vendors.company_type/sub_company_type.
     */
    function formatRegisteredVendorType(?string $companyType, ?string $subCompanyType): string
    {
        $companyType    = trim((string) $companyType);
        $subCompanyType = trim((string) $subCompanyType);
        if ($subCompanyType === '' || strcasecmp($subCompanyType, $companyType) === 0) {
            return $companyType !== '' ? $companyType : $subCompanyType;
        }
        if ($companyType === '') {
            return $subCompanyType;
        }
        return $companyType . ' / ' . $subCompanyType;
    }

    /** Build the composed, human-readable + parseable sourcing note. */
    function composeSourcingNote(string $vendorType, string $reason): string
    {
        $vendorType = trim($vendorType);
        $reason     = trim($reason);
        return SOURCING_NOTE_TYPE_LABEL . ' ' . $vendorType . "\n\n"
             . SOURCING_NOTE_REASON_LABEL . ' ' . $reason;
    }

    /**
     * Parse a composed note back into its parts.
     *
     * @return array{vendor_type:string,reason:string,is_structured:bool}
     *   is_structured is false for legacy/free-text notes (labels absent).
     */
    function parseSourcingNote(?string $notes): array
    {
        $notes = (string) $notes;
        $result = ['vendor_type' => '', 'reason' => '', 'is_structured' => false];

        // Vendor Type = everything after its label up to the Reason label (or EOF).
        $typeRe = '/' . preg_quote(SOURCING_NOTE_TYPE_LABEL, '/')
                . '\s*(.*?)\s*(?=' . preg_quote(SOURCING_NOTE_REASON_LABEL, '/') . '|$)/s';
        $reasonRe = '/' . preg_quote(SOURCING_NOTE_REASON_LABEL, '/') . '\s*(.*)$/s';

        $hasType   = preg_match($typeRe, $notes, $mType) === 1;
        $hasReason = preg_match($reasonRe, $notes, $mReason) === 1;

        if ($hasType || $hasReason) {
            $result['vendor_type']   = $hasType ? trim($mType[1]) : '';
            $result['reason']        = $hasReason ? trim($mReason[1]) : '';
            $result['is_structured'] = $hasType && $hasReason;
        }
        return $result;
    }

    /**
     * Extract the sourcing-provided REASON from a posted note. Composed notes carry
     * it after the Reason label; a plain legacy note is treated as reason text.
     */
    function extractSourcingReason(?string $notes): string
    {
        $notes  = trim((string) $notes);
        $parsed = parseSourcingNote($notes);
        return $parsed['is_structured'] ? $parsed['reason'] : $notes;
    }
}
