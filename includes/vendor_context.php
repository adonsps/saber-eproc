<?php
// includes/vendor_context.php
//
// Shared helpers for the multi-Contact-Person (multi-CP) vendor model.
// Include this AFTER config.php on vendor-facing pages.
//
//   current_vendor_id($conn)  -> the vendor company id for the logged-in vendor user
//   vendor_is_primary()       -> is the logged-in CP the primary (vendor admin)?
//   vca_log(...)              -> append a row to vendor_contact_audit
//
// Resolution order for the vendor id (robust across the migration window):
//   1. $_SESSION['vendor_id']            (set at login)
//   2. users.vendor_id                   (new multi-CP link)
//   3. vendors.user_id  (legacy 1:1)     (any not-yet-backfilled account)

// Lifecycle thresholds (Phase 4). Overridable via config if ever needed.
if (!defined('VENDOR_CP_INACTIVITY_DAYS')) define('VENDOR_CP_INACTIVITY_DAYS', 120);
if (!defined('VENDOR_CP_REATTEST_DAYS'))   define('VENDOR_CP_REATTEST_DAYS', 180);

// How long an activation (set-password) invite link stays valid. The invite mail
// quotes this number, so keep the mail text and reset_expires driven by it.
if (!defined('VENDOR_CP_INVITE_DAYS'))     define('VENDOR_CP_INVITE_DAYS', 3);
// Minimum gap between two activation mails to the same CP — a vendor-facing
// button that sends mail is a spam vector, and double-clicks are common.
if (!defined('VENDOR_CP_RESEND_COOLDOWN_MIN')) define('VENDOR_CP_RESEND_COOLDOWN_MIN', 5);

if (!function_exists('current_vendor_id')) {

    /**
     * Resolve the vendor company id for the currently logged-in vendor user.
     * Returns null if there is no vendor user in session or none can be resolved.
     */
    function current_vendor_id(mysqli $conn): ?int
    {
        if (($_SESSION['role'] ?? '') !== 'vendor') {
            return null;
        }
        if (!empty($_SESSION['vendor_id'])) {
            return (int) $_SESSION['vendor_id'];
        }

        $user_id = (int) ($_SESSION['user_id'] ?? 0);
        if ($user_id <= 0) {
            return null;
        }

        // Try the new users.vendor_id link first, then fall back to the legacy
        // vendors.user_id 1:1 mapping so pre-backfill accounts still resolve.
        $stmt = $conn->prepare(
            "SELECT COALESCE(u.vendor_id, v.id) AS vendor_id
               FROM users u
          LEFT JOIN vendors v ON v.user_id = u.id
              WHERE u.id = ?
              LIMIT 1"
        );
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row && $row['vendor_id'] !== null) {
            $_SESSION['vendor_id'] = (int) $row['vendor_id'];
            return (int) $row['vendor_id'];
        }
        return null;
    }

    /**
     * Soft signal only: is this a free/public email provider (gmail, yahoo, …)?
     * Used to FLAG (never block) CPs on personal email so the primary/procurement
     * can eyeball them. A departing employee keeps a personal inbox, so a free
     * address is a weaker guarantee that the person still represents the vendor.
     */
    function is_free_email_domain(string $email): bool
    {
        $at = strrchr($email, '@');
        if ($at === false) return false;
        $domain = strtolower(substr($at, 1));
        static $free = [
            'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.id', 'ymail.com',
            'hotmail.com', 'outlook.com', 'live.com', 'msn.com', 'icloud.com',
            'me.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'mail.com',
        ];
        return in_array($domain, $free, true);
    }

    /**
     * Registration statuses in which the vendor profile is LOCKED (read-only).
     *
     * Rationale: the profile is only frozen once the vendor has actually SUBMITTED
     * their Pra-Kualifikasi (PQ) for evaluation ('pq_submitted'); from that point
     * the submitted data must not change underneath the procurement review. It
     * stays locked while the review is in flight ('under_review_vm',
     * 'under_review_vm_head') and while VM has handed it back to sourcing
     * internally ('returned_to_sourcing' — the case is still inside procurement,
     * not with the vendor). A final 'rejected' also stays locked.
     *
     * It is EDITABLE (unlocked) when:
     *   - the profile is still being built (registration_submitted / registered /
     *     migrated / min_info_submitted) -> not yet in the pipeline;
     *   - the profile is complete and the vendor was invited to fill the PQ, but
     *     has NOT submitted it yet ('invited_pq'), or saved it as a draft
     *     ('draft') -> the vendor is still preparing their data;
     *   - procurement sent it back to the vendor for a fix ('revision_requested');
     *   - procurement APPROVED it ('approved') -> vendor may maintain data again.
     *
     * i.e. LOCK only when the data is complete and handed to procurement for
     * checking; UNLOCK while the vendor is still filling it, or once it is sent
     * back / accepted.
     */
    function vendor_profile_locked_statuses(): array
    {
        return [
            'pq_submitted',
            'under_review_vm',
            'under_review_vm_head',
            'returned_to_sourcing',
            'rejected',
        ];
    }

    /**
     * True when the vendor profile is locked for editing given its current
     * registration_status. Unknown/empty status is treated as editable so a
     * brand-new or legacy record is never wrongly frozen.
     */
    function vendor_profile_is_locked(?string $status): bool
    {
        return in_array((string) $status, vendor_profile_locked_statuses(), true);
    }

    /**
     * Staff roles allowed to edit a vendor profile through the lock, and what
     * each of them may touch.
     *
     * vendor_profile_is_locked() above exists to stop the VENDOR changing data
     * that procurement is already reviewing. It was never meant to stop
     * procurement itself: mid-review, a reviewer routinely has to fix a typo or
     * replace a wrong legal document, and bouncing the whole case back to the
     * vendor just to change one field is expensive. So a staff user who opened
     * the profile through modules/shared/edit_vendor_profile.php (an
     * impersonation session, exactly like Direct Registration) edits straight
     * through the lock:
     *
     *   sourcing / vendor_mgt / vendormgt_head -> profile fields AND legal documents
     *
     * Sourcing used to get profile fields only (documents read-only). That was
     * widened in Sept 2026 (ClickUp 86eyuz7e0): STT/STC are the ones the vendor
     * sends the corrected file to, and with no way to attach it here they were
     * falling back to having it swapped in through the database. The two
     * permission levels are still kept apart below so they can diverge again
     * without touching the pages that consult them.
     *
     * A real vendor session has no impersonator, so for them nothing changes:
     * the lock stays exactly as strict as it was.
     */

    /**
     * Role of the staff user driving the current impersonation session, or ''
     * for a normal vendor login. Reading it from the impersonator -- not from
     * $_SESSION['role'], which is 'vendor' for the duration -- is what stops a
     * vendor ever granting themselves the override.
     */
    function vendor_staff_editor_role(): string
    {
        $role = $_SESSION['impersonator']['role'] ?? '';
        return is_string($role) ? $role : '';
    }

    /**
     * Staff roles that may open a correction session at all (profile fields).
     * The single list behind edit_vendor_profile.php's gate and both
     * view_profile.php "Koreksi" buttons, so none of them carries its own copy.
     */
    function vendor_staff_editor_roles(): array
    {
        return ['sourcing', 'vendor_mgt', 'vendormgt_head'];
    }

    /**
     * Staff roles whose correction session may also add/replace/delete legal
     * documents. Currently every editor role -- see the note above.
     */
    function vendor_staff_document_editor_roles(): array
    {
        return vendor_staff_editor_roles();
    }

    /** True when a staff impersonator may edit profile fields through the lock. */
    function vendor_staff_can_edit_profile(): bool
    {
        return in_array(vendor_staff_editor_role(), vendor_staff_editor_roles(), true);
    }

    /** True when a staff impersonator may also add/replace/delete legal documents. */
    function vendor_staff_can_edit_documents(): bool
    {
        return in_array(vendor_staff_editor_role(), vendor_staff_document_editor_roles(), true);
    }

    /**
     * True while the current vendor session is a staff correction session opened
     * via modules/shared/edit_vendor_profile.php — as opposed to a real vendor
     * login, or the Direct Registration impersonation (which needs the whole
     * vendor portal to finish onboarding, so it deliberately does NOT count here).
     */
    function vendor_staff_edit_session(): bool
    {
        return ($_SESSION['impersonator']['purpose'] ?? '') === 'staff_profile_edit';
    }

    /**
     * A staff correction session may only ever be on profile.php — it exists
     * solely to fix a locked profile/document, not to browse the rest of the
     * vendor's account (dashboard, PQ, products, contacts) under their identity.
     * Call right after each vendor page's own role check.
     *
     * $on_blocked defaults to redirecting to profile.php (for normal page
     * views); pass a callable for endpoints that must fail a different way
     * (e.g. an AJAX handler that needs to die() or emit JSON instead).
     */
    function vendor_staff_edit_restrict(?callable $on_blocked = null): void
    {
        if (!vendor_staff_edit_session()) {
            return;
        }
        if ($on_blocked !== null) {
            $on_blocked();
            return;
        }
        header("Location: profile.php");
        exit();
    }

    /**
     * Real actor behind a vendor-profile write: the staff impersonator when the
     * lock override is in effect, otherwise the session itself (a real vendor
     * login, or Direct Registration — which keeps recording saves under the
     * vendor account it is building, since $status_locked is false that early
     * in the lifecycle).
     *
     * 'name' keeps the "(Sourcing)"/"(Vendor Management)" suffix for the
     * whole-profile last_updated_by_name banner, which has no separate role
     * column to fall back on. 'plain_name' is the bare name — used by the
     * per-document audit (vendor_document_audit already has its own actor_role
     * column, so the row's actor_name doesn't need the suffix baked in).
     */
    function vendor_profile_actor(bool $status_locked): array
    {
        if ($status_locked && vendor_staff_can_edit_profile()) {
            $role       = vendor_staff_editor_role();
            $label      = $role === 'sourcing' ? 'Sourcing' : 'Vendor Management';
            $plain_name = trim($_SESSION['impersonator']['user_name'] ?? 'Tim Procurement');
            return [
                'id'         => (int) ($_SESSION['impersonator']['user_id'] ?? ($_SESSION['user_id'] ?? 0)),
                'name'       => $plain_name . " ({$label})",
                'plain_name' => $plain_name,
                'role'       => $role,
            ];
        }
        return [
            'id'         => (int) ($_SESSION['user_id'] ?? 0),
            'name'       => $_SESSION['user_name'] ?? '',
            'plain_name' => $_SESSION['user_name'] ?? '',
            'role'       => $_SESSION['role'] ?? 'vendor',
        ];
    }

    /**
     * True when the logged-in CP is the vendor's primary (owner/admin).
     * Defaults to true for legacy sessions with no contact_role recorded yet,
     * so existing single-login vendors are never locked out of anything.
     */
    function vendor_is_primary(): bool
    {
        $role = $_SESSION['contact_role'] ?? null;
        return $role === null || $role === 'primary';
    }

    /**
     * True when the primary should re-confirm the CP list: the vendor has at
     * least one active MEMBER and the list has not been attested/managed within
     * VENDOR_CP_REATTEST_DAYS. Single-CP vendors never need attestation.
     */
    function vendor_needs_reattestation(mysqli $conn, int $vendor_id): bool
    {
        $days = (int) VENDOR_CP_REATTEST_DAYS;
        $sql = "SELECT
                    (SELECT COUNT(*) FROM users
                      WHERE vendor_id = ? AND contact_role = 'member'
                        AND contact_status = 'active' AND is_active = 1) AS active_members,
                    (SELECT cp_last_attested_at FROM vendors WHERE id = ?) AS attested_at";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $vendor_id, $vendor_id);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        if (!$r || (int) $r['active_members'] < 1) {
            return false;
        }
        if (empty($r['attested_at'])) {
            return true;
        }
        return strtotime($r['attested_at']) < strtotime("-{$days} days");
    }

    /**
     * Record that the CP list is current (resets the re-attestation clock).
     * Any active management of the list (add/revoke/transfer) also calls this.
     */
    function touch_cp_attestation(mysqli $conn, int $vendor_id): void
    {
        try {
            $stmt = $conn->prepare("UPDATE vendors SET cp_last_attested_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $vendor_id);
            $stmt->execute();
        } catch (Throwable $e) {
            error_log("[touch_cp_attestation] failed: " . $e->getMessage());
        }
    }

    /**
     * True when this CP was invited but has never completed activation, i.e. they
     * never opened the emailed link and set a password.
     *
     * Primary signal is contact_status = 'invited' (set by add_cp). The fallback
     * covers members invited BEFORE that status was used, whose row still says
     * 'active': an invited member is recognisable by invited_by_user_id being set
     * while they have neither verified nor ever logged in.
     *
     * @param array $c a users row incl. contact_status, contact_role, is_active,
     *                 verified_at, last_login_at, invited_by_user_id
     */
    function cp_activation_pending(array $c): bool
    {
        $status = (string) ($c['contact_status'] ?? 'active');
        if ($status === 'revoked') {
            return false;
        }
        if ($status === 'invited') {
            return true;
        }
        return ($c['contact_role'] ?? '') === 'member'
            && !empty($c['invited_by_user_id'])
            && empty($c['verified_at'])
            && empty($c['last_login_at']);
    }

    /**
     * True when the CP's activation link is no longer usable — either it ran past
     * its VENDOR_CP_INVITE_DAYS window, or there is no token at all (a pending CP
     * whose token was consumed/cleared can no longer activate either way).
     * Only meaningful for rows where cp_activation_pending() is true.
     */
    function cp_invite_link_expired(array $c): bool
    {
        if (empty($c['reset_token']) || empty($c['reset_expires'])) {
            return true;
        }
        return strtotime((string) $c['reset_expires']) < time();
    }

    /**
     * When each CP of this vendor was last sent an activation mail, as
     * [user_id => 'Y-m-d H:i:s']. Read from vendor_contact_audit so no extra
     * column is needed — every invite/resend already writes a row there.
     */
    function cp_last_invite_sent_map(mysqli $conn, int $vendor_id): array
    {
        $map = [];
        try {
            $stmt = $conn->prepare(
                "SELECT target_user_id, MAX(created_at) AS sent_at
                   FROM vendor_contact_audit
                  WHERE vendor_id = ?
                    AND action IN ('cp_invited', 'cp_invite_resent')
                    AND target_user_id IS NOT NULL
                  GROUP BY target_user_id"
            );
            $stmt->bind_param("i", $vendor_id);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
                $map[(int) $r['target_user_id']] = $r['sent_at'];
            }
        } catch (Throwable $e) {
            error_log("[cp_last_invite_sent_map] failed: " . $e->getMessage());
        }
        return $map;
    }

    /**
     * Append an entry to the CP audit trail. Best-effort: never throws.
     *
     * @param array $opts keys: target_user_id, target_email, details
     */
    function vca_log(mysqli $conn, int $vendor_id, string $action, array $opts = []): void
    {
        try {
            $actor_user_id  = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $actor_name     = $_SESSION['user_name'] ?? null;
            $actor_role     = $_SESSION['role'] ?? null;
            $target_user_id = isset($opts['target_user_id']) ? (int) $opts['target_user_id'] : null;
            $target_email   = $opts['target_email'] ?? null;
            $details        = $opts['details'] ?? null;

            $stmt = $conn->prepare(
                "INSERT INTO vendor_contact_audit
                    (vendor_id, actor_user_id, actor_name, actor_role, target_user_id, target_email, action, details)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            // Types must match column order:
            // vendor_id(i), actor_user_id(i), actor_name(s), actor_role(s),
            // target_user_id(i), target_email(s), action(s), details(s)
            $stmt->bind_param(
                "iississs",
                $vendor_id,
                $actor_user_id,
                $actor_name,
                $actor_role,
                $target_user_id,
                $target_email,
                $action,
                $details
            );
            $stmt->execute();
        } catch (Throwable $e) {
            error_log("[vca_log] failed: " . $e->getMessage());
        }
    }

    /**
     * Append one row to the per-document audit trail (vendor_document_audit —
     * schema in includes/vendor_document_audit_schema.php). $doc_key matches the
     * file input name used throughout profile.php / profile_action.php (e.g.
     * 'npwp_file', 'nib_file'), so a row here maps 1:1 to a Dokumen Legal row.
     * Best-effort: never throws, so a logging failure can't block the save it
     * is recording.
     *
     * $inserted_id comes back set only when the row really landed — the vendor
     * profile's early document commit needs it so "Buang draf" can take the row
     * out again (includes/vendor_profile_draft.php).
     */
    function vendor_doc_audit_log(mysqli $conn, int $vendor_id, string $doc_key, string $action, array $actor, ?int &$inserted_id = null): void
    {
        $inserted_id = null;
        try {
            // Explicit timestamp, not the column's DEFAULT CURRENT_TIMESTAMP:
            // the DB server's clock runs in UTC while every other "last
            // updated" timestamp in this app (e.g. vendors.last_updated_at)
            // is written from PHP's date(), which config.php pins to Asia/
            // Jakarta (WIB). Relying on the DB default would silently show
            // every row 7 hours behind.
            $stmt = $conn->prepare(
                "INSERT INTO vendor_document_audit
                    (vendor_id, doc_key, action, actor_user_id, actor_name, actor_role, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $actor_id   = (int) ($actor['id'] ?? 0);
            $actor_name = (string) ($actor['plain_name'] ?? $actor['name'] ?? '');
            $actor_role = (string) ($actor['role'] ?? '');
            $created_at = date('Y-m-d H:i:s');
            $stmt->bind_param("ississs", $vendor_id, $doc_key, $action, $actor_id, $actor_name, $actor_role, $created_at);
            if ($stmt->execute()) {
                $inserted_id = (int) $conn->insert_id ?: null;
            }
        } catch (Throwable $e) {
            error_log("[vendor_doc_audit_log] failed: " . $e->getMessage());
        }
    }

    /**
     * Latest audit row per document, as [doc_key => ['action'=>, 'actor_name'=>,
     * 'actor_role'=>, 'created_at'=>]]. Used to caption each Dokumen Legal row
     * with who last touched it and when, for the vendor's own profile.php and
     * both view_profile.php pages alike.
     */
    function vendor_doc_last_updates(mysqli $conn, int $vendor_id): array
    {
        $map = [];
        try {
            $stmt = $conn->prepare(
                "SELECT a.doc_key, a.action, a.actor_name, a.actor_role, a.created_at
                   FROM vendor_document_audit a
                   JOIN (
                       SELECT doc_key, MAX(id) AS max_id
                         FROM vendor_document_audit
                        WHERE vendor_id = ?
                        GROUP BY doc_key
                   ) latest ON latest.max_id = a.id"
            );
            $stmt->bind_param("i", $vendor_id);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
                $map[$r['doc_key']] = $r;
            }
        } catch (Throwable $e) {
            error_log("[vendor_doc_last_updates] failed: " . $e->getMessage());
        }
        return $map;
    }
}
