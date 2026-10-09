<?php
// includes/vendor_profile_draft.php
//
// Keeps the last REJECTED vendor-profile submission in the session so a failed
// save no longer throws the vendor's typing away. Before this, any validation
// error in profile_action.php (duplicate NIB, invalid phone, oversized file,
// missing SPPKP, ...) redirected back to profile.php, which re-rendered every
// field straight from the `vendors` row — i.e. from the last SUCCESSFUL save —
// so everything typed in that attempt was lost.
//
// Scope note: only text/checkbox state is kept here — a browser never lets the
// server refill an <input type="file">. Documents take the other route:
// profile_action.php commits them before it validates anything, and the
// rollback receipts at the bottom of this file are what let "Buang draf" undo
// that commit.
//
// The session plumbing (scoping, expiry) lives in includes/form_draft.php and is
// shared with the other redirect-back forms; what is profile-specific — which
// fields travel, and which are frozen after PQ submission — stays here.

require_once __DIR__ . '/form_draft.php';
require_once __DIR__ . '/upload_paths.php';

if (!function_exists('vendor_profile_draft_fields')) {

define('VENDOR_PROFILE_DRAFT_FORM', 'vendor_profile');

/** POST names carried over. Anything not listed (tokens, files, delete_files[]) is dropped. */
function vendor_profile_draft_fields(): array
{
    return [
        // Informasi Umum
        'legal_entity_type', 'number_of_employees', 'director_name', 'director_phone', 'establishment_date',
        'contact_person', 'phone', 'pic_finance', 'email_pic_finance',
        'bank_name', 'bank_branch', 'bank_holder_name', 'core_business',
        // Dokumen Legal — the OCR-extracted numbers typed next to each upload
        'nib_number', 'npwp_number', 'npwp_name', 'company_address',
        'director_id_number', 'deed_of_establishment_number',
        'decree_of_legal_entity_number', 'bank_account_number',
        'umkm_cert_number',
    ];
}

/** Checkboxes: absent from POST means "unchecked", so they are stored explicitly as 0/1. */
function vendor_profile_draft_checkboxes(): array
{
    return ['is_sppkp', 'is_umkm', 'bank_holder_is_company'];
}

/**
 * Stash the rejected submission. Call this right before every failure redirect
 * out of profile_action.php.
 */
function vendor_profile_draft_save(int $vendor_id, array $post): void
{
    if ($vendor_id <= 0) return;

    form_draft_save(
        VENDOR_PROFILE_DRAFT_FORM,
        form_draft_collect($post, vendor_profile_draft_fields(), vendor_profile_draft_checkboxes()),
        $vendor_id
    );
}

/**
 * The pending draft for this vendor, or [] when there is none. Drafts belong to
 * one vendor and expire after a day so a stale one can never quietly shadow
 * data another contact person saved in the meantime.
 */
function vendor_profile_draft_get(int $vendor_id): array
{
    return form_draft_get(VENDOR_PROFILE_DRAFT_FORM, $vendor_id);
}

/** Drop the draft — on a successful save, or when the vendor discards it. */
function vendor_profile_draft_clear(): void
{
    form_draft_clear(VENDOR_PROFILE_DRAFT_FORM);
}

/**
 * Values to render the form with: the stored vendor row, overlaid with whatever
 * the vendor last typed. `$locked_fields` are never overlaid — legal_entity_type
 * and is_umkm are frozen after PQ submission and their inputs render disabled,
 * so the browser posts nothing for them and the draft cannot be trusted there.
 */
function vendor_profile_form_values(array $vendor, array $draft, array $locked_fields = []): array
{
    $form = $vendor;
    foreach ($draft as $field => $value) {
        if (in_array($field, $locked_fields, true)) continue;
        $form[$field] = $value;
    }
    return $form;
}

/**
 * Documents are the half a session draft cannot carry, so profile_action.php
 * writes them to disk and to the row BEFORE it validates anything — that is the
 * only way a rejected save stops throwing the vendor's uploads away (a browser
 * never refills an <input type="file">). The cost is that the row now holds a
 * document from an attempt that was never accepted, and "Buang draf" is
 * supposed to put everything back the way it was.
 *
 * So every early commit leaves a receipt here: which column was touched, what
 * it held before, which file this attempt added, and which audit rows it wrote.
 * vendor_profile_doc_rollback_apply() spends the receipt; a successful save
 * throws it away, because at that point the documents are simply saved.
 */

define('VENDOR_PROFILE_DOC_ROLLBACK_KEY', 'vendor_profile_doc_rollback');

/**
 * Remember one early-committed document so it can be put back. `$extra_previous`
 * carries the columns committed WITH the file — the OCR number and its
 * verification status — as column => value-before, so the discard puts the whole
 * document back rather than the file alone.
 */
function vendor_profile_doc_rollback_record(int $vendor_id, string $doc_key, string $db_column, ?string $previous_path, string $new_path, ?int $audit_id = null, array $extra_previous = []): void
{
    if ($vendor_id <= 0 || $doc_key === '' || $new_path === '') return;
    if (!preg_match('/^[a-z_]+$/', $db_column)) return;

    $store = $_SESSION[VENDOR_PROFILE_DOC_ROLLBACK_KEY] ?? null;
    if (!is_array($store) || (int) ($store['owner_id'] ?? 0) !== $vendor_id) {
        $store = ['owner_id' => $vendor_id, 'saved_at' => time(), 'docs' => []];
    }

    $audit_ids = [];
    $existing  = $store['docs'][$doc_key] ?? null;
    if (is_array($existing)) {
        // Rejected twice on the same document: the ORIGINAL stays the rollback
        // target, and the file from the earlier attempt is referenced by
        // nothing any more — drop it instead of leaking it on disk.
        $superseded = (string) ($existing['current'] ?? '');
        if ($superseded !== '' && $superseded !== ($existing['previous'] ?? null)) {
            $abs = vendor_upload_resolve($superseded);
            if ($abs) @unlink($abs);
        }
        $previous_path = $existing['previous'] ?? null;
        $audit_ids     = is_array($existing['audit_ids'] ?? null) ? $existing['audit_ids'] : [];
        // Same reason the ORIGINAL path stays the target: the values the FIRST
        // attempt overwrote are the ones to go back to.
        $extra_previous = is_array($existing['extras'] ?? null) ? $existing['extras'] : [];
    }
    if ($audit_id !== null && $audit_id > 0) $audit_ids[] = $audit_id;

    $extras = [];
    foreach ($extra_previous as $extra_column => $extra_value) {
        if (!is_string($extra_column) || !preg_match('/^[a-z_]+$/', $extra_column)) continue;
        $extras[$extra_column] = ($extra_value === null) ? null : (string) $extra_value;
    }

    $store['saved_at'] = time();
    $store['docs'][$doc_key] = [
        'column'    => $db_column,
        'previous'  => ($previous_path === '' ? null : $previous_path),
        'current'   => $new_path,
        'audit_ids' => $audit_ids,
        'extras'    => $extras,
    ];
    $_SESSION[VENDOR_PROFILE_DOC_ROLLBACK_KEY] = $store;
}

/** Pending receipts for this vendor, or [] when there are none / they aged out. */
function vendor_profile_doc_rollback_get(int $vendor_id, int $max_age_seconds = 86400): array
{
    $store = $_SESSION[VENDOR_PROFILE_DOC_ROLLBACK_KEY] ?? null;
    if (!is_array($store) || (int) ($store['owner_id'] ?? 0) !== $vendor_id) return [];
    if (time() - (int) ($store['saved_at'] ?? 0) > $max_age_seconds) {
        vendor_profile_doc_rollback_clear();
        return [];
    }
    return is_array($store['docs'] ?? null) ? $store['docs'] : [];
}

/**
 * Forget one document's receipt without undoing it. Used when the vendor makes
 * their own decision about that document (deleting it outright) — putting the
 * previous file back afterwards would resurrect what they just threw away.
 */
function vendor_profile_doc_rollback_forget(int $vendor_id, string $doc_key): void
{
    $store = $_SESSION[VENDOR_PROFILE_DOC_ROLLBACK_KEY] ?? null;
    if (!is_array($store) || (int) ($store['owner_id'] ?? 0) !== $vendor_id) return;
    unset($store['docs'][$doc_key]);
    if (empty($store['docs'])) {
        vendor_profile_doc_rollback_clear();
        return;
    }
    $_SESSION[VENDOR_PROFILE_DOC_ROLLBACK_KEY] = $store;
}

/** Drop every receipt — on a successful save, once the documents are real. */
function vendor_profile_doc_rollback_clear(): void
{
    unset($_SESSION[VENDOR_PROFILE_DOC_ROLLBACK_KEY]);
}

/**
 * Put the documents back: restore each column to what it held before the
 * rejected attempt, delete the file that attempt added, and remove the audit
 * rows it wrote. Returns how many documents were reverted.
 *
 * Each column is restored on its own and only while it STILL holds the path we
 * committed — a second contact person who replaced that document in the
 * meantime must not have their work undone by this vendor's discard.
 */
function vendor_profile_doc_rollback_apply(mysqli $conn, int $vendor_id): int
{
    $docs = vendor_profile_doc_rollback_get($vendor_id);
    if (empty($docs)) return 0;

    $reverted  = 0;
    $audit_ids = [];

    foreach ($docs as $rec) {
        $column   = (string) ($rec['column'] ?? '');
        $current  = (string) ($rec['current'] ?? '');
        $previous = $rec['previous'] ?? null;
        if ($current === '' || !preg_match('/^[a-z_]+$/', $column)) continue;

        $stmt = $conn->prepare("UPDATE vendors SET $column = ? WHERE id = ? AND $column = ?");
        $stmt->bind_param("sis", $previous, $vendor_id, $current);
        $stmt->execute();
        if ($stmt->affected_rows <= 0) continue;

        $reverted++;

        // Only once the file itself went back: the OCR number describes the
        // document, so restoring one without the other is what left the row
        // holding a document with no number behind it.
        foreach ((array) ($rec['extras'] ?? []) as $extra_column => $extra_value) {
            if (!is_string($extra_column) || !preg_match('/^[a-z_]+$/', $extra_column)) continue;
            $extra_stmt = $conn->prepare("UPDATE vendors SET $extra_column = ? WHERE id = ?");
            $extra_stmt->bind_param("si", $extra_value, $vendor_id);
            $extra_stmt->execute();
        }

        if ($current !== $previous) {
            $abs = vendor_upload_resolve($current);
            if ($abs) @unlink($abs);
        }
        foreach ((array) ($rec['audit_ids'] ?? []) as $id) {
            if ((int) $id > 0) $audit_ids[] = (int) $id;
        }
    }

    if (!empty($audit_ids)) {
        $in   = implode(',', array_map('intval', $audit_ids));
        $stmt = $conn->prepare("DELETE FROM vendor_document_audit WHERE vendor_id = ? AND id IN ($in)");
        $stmt->bind_param("i", $vendor_id);
        $stmt->execute();
    }

    vendor_profile_doc_rollback_clear();
    return $reverted;
}

}
