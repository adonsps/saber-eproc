<?php
// includes/form_draft.php
//
// Generic "keep what the user typed" store for POST → validate → redirect-back
// forms. Without it, a form that bounces on one bad field re-renders from the
// database (or from nothing) and silently discards the whole attempt.
//
// Usage: the action script calls form_draft_save() right before every failure
// redirect and form_draft_clear() once the write actually succeeds; the page
// script calls form_draft_get() and renders from it instead of the stored row.
//
// Drafts live in the session, are scoped to one owner, and expire — a stale one
// must never quietly shadow data saved since.

if (!function_exists('form_draft_save')) {

/**
 * Pull the whitelisted fields out of a POST body.
 * `$checkboxes` are stored as 0/1: an absent key means "unticked", not "absent".
 */
function form_draft_collect(array $post, array $fields, array $checkboxes = []): array
{
    $values = [];
    foreach ($fields as $field) {
        if (array_key_exists($field, $post) && !is_array($post[$field])) {
            $values[$field] = (string) $post[$field];
        }
    }
    foreach ($checkboxes as $field) {
        $values[$field] = !empty($post[$field]) ? 1 : 0;
    }
    return $values;
}

/** Stash a rejected submission under `$form`, owned by `$owner_id`. */
function form_draft_save(string $form, array $values, int $owner_id = 0): void
{
    $_SESSION['form_drafts'][$form] = [
        'owner_id' => $owner_id,
        'saved_at' => time(),
        'values'   => $values,
    ];
}

/** The pending draft, or [] when there is none / it belongs elsewhere / it aged out. */
function form_draft_get(string $form, int $owner_id = 0, int $max_age_seconds = 86400): array
{
    $draft = $_SESSION['form_drafts'][$form] ?? null;
    if (!is_array($draft) || (int) ($draft['owner_id'] ?? 0) !== $owner_id) {
        return [];
    }
    if (time() - (int) ($draft['saved_at'] ?? 0) > $max_age_seconds) {
        form_draft_clear($form);
        return [];
    }
    return is_array($draft['values'] ?? null) ? $draft['values'] : [];
}

/** Drop the draft — on a successful save, or when the user discards it. */
function form_draft_clear(string $form): void
{
    unset($_SESSION['form_drafts'][$form]);
}

}
