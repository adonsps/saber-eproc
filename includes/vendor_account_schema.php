<?php
// includes/vendor_account_schema.php
//
// Account-level enhancements for the vendor portal, layered on top of the
// multi-Contact-Person model (see includes/vendor_contacts_schema.php):
//
//   1. users.username  -> an alternate login id, so a vendor whose EMAIL inbox
//      is compromised/inaccessible can still sign in with username + password.
//      Unique across all users; every existing/active vendor account is
//      backfilled with a generated default so username-login works immediately.
//
//   2. vendors.last_updated_by_* / last_updated_at -> "who last changed the
//      profile, and when" (stored in Asia/Jakarta / WIB, matching config.php's
//      default timezone) so procurement can trace profile edits.
//
// Idempotent + MySQL 8 (local) and MariaDB (prod) safe: columns/indexes are
// added in try/catch and duplicate errors (1060 column, 1061 key) are swallowed,
// matching the existing ALTER-in-try/catch idiom in config.php.

if (!function_exists('ensure_vendor_account_schema')) {

    /**
     * Add username + last-updated tracking columns and backfill usernames.
     * Errors are logged, never fatal.
     */
    function ensure_vendor_account_schema(mysqli $conn): void
    {
        // 1. users.username (alternate login id).
        try {
            $conn->query("ALTER TABLE users ADD COLUMN username VARCHAR(50) NULL DEFAULT NULL");
        } catch (mysqli_sql_exception $e) {
            if ((int) $e->getCode() !== 1060) {
                error_log("[vendor_account_schema] add users.username failed: " . $e->getMessage());
            }
        }

        // Unique index. MySQL/MariaDB allow multiple NULLs under a UNIQUE index,
        // so this is safe to add BEFORE the backfill fills the NULLs in.
        try {
            $conn->query("ALTER TABLE users ADD UNIQUE INDEX uniq_users_username (username)");
        } catch (mysqli_sql_exception $e) {
            if ((int) $e->getCode() !== 1061) {
                error_log("[vendor_account_schema] add users.username index failed: " . $e->getMessage());
            }
        }

        // 2. Profile "last updated by / at" tracking on vendors.
        $vendorCols = [
            "last_updated_by_user_id INT NULL DEFAULT NULL",
            "last_updated_by_name VARCHAR(255) NULL DEFAULT NULL",
            "last_updated_at DATETIME NULL DEFAULT NULL",
        ];
        foreach ($vendorCols as $ddl) {
            try {
                $conn->query("ALTER TABLE vendors ADD COLUMN $ddl");
            } catch (mysqli_sql_exception $e) {
                if ((int) $e->getCode() !== 1060) {
                    error_log("[vendor_account_schema] add vendors column failed: " . $e->getMessage());
                }
            }
        }

        // 3. Backfill: give every vendor account still missing a username a
        //    generated, unique default. Guarded by WHERE username IS NULL, so
        //    once every account has one this matches zero rows and is a no-op.
        try {
            backfill_vendor_usernames($conn);
        } catch (Throwable $e) {
            error_log("[vendor_account_schema] username backfill failed: " . $e->getMessage());
        }

        // 4. Backfill the PRIMARY contact's personal phone (users.phone) from the
        //    company phone (vendors.phone). The multi-CP migration added
        //    users.phone as NULL and never populated it for pre-existing primary
        //    accounts, so the Narahubung page showed "—" for them even though the
        //    profile has a company phone. Member CPs set their own phone when
        //    invited, so only untouched primaries are filled here. Guarded by
        //    WHERE users.phone IS NULL/'' -> no-op after the first run.
        try {
            $conn->query(
                "UPDATE users u
                   JOIN vendors v ON v.id = u.vendor_id
                    SET u.phone = v.phone
                  WHERE u.contact_role = 'primary'
                    AND (u.phone IS NULL OR u.phone = '')
                    AND v.phone IS NOT NULL AND v.phone <> ''"
            );
        } catch (mysqli_sql_exception $e) {
            error_log("[vendor_account_schema] primary phone backfill failed: " . $e->getMessage());
        }
    }

    /**
     * Turn an email/name into a clean username base: lowercase, only a-z 0-9
     * and . _ - , collapsed, trimmed. Falls back to 'vendor' when empty.
     */
    function make_username_base(string $seed): string
    {
        // Prefer the local-part if an email was passed in.
        $at = strpos($seed, '@');
        if ($at !== false) {
            $seed = substr($seed, 0, $at);
        }
        $seed = strtolower($seed);
        $seed = preg_replace('/[^a-z0-9._-]+/', '', $seed);
        $seed = trim((string) $seed, '._-');
        if ($seed === '' || strlen($seed) < 3) {
            $seed = 'vendor' . ($seed !== '' ? $seed : '');
        }
        return substr($seed, 0, 40);
    }

    /**
     * Is this username already taken by another user? (case-insensitive)
     */
    function username_is_taken(mysqli $conn, string $username, int $exclude_user_id = 0): bool
    {
        $stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND id <> ? LIMIT 1");
        $stmt->bind_param("si", $username, $exclude_user_id);
        $stmt->execute();
        return (bool) $stmt->get_result()->fetch_assoc();
    }

    /**
     * Generate a unique username from a seed, appending 2,3,4… on collision.
     */
    function generate_unique_username(mysqli $conn, string $seed, int $exclude_user_id = 0): string
    {
        $base = make_username_base($seed);
        $candidate = $base;
        $n = 1;
        while (username_is_taken($conn, $candidate, $exclude_user_id)) {
            $n++;
            $candidate = substr($base, 0, 38) . $n;
        }
        return $candidate;
    }

    /**
     * Assign a generated username to every vendor account that lacks one.
     */
    function backfill_vendor_usernames(mysqli $conn): void
    {
        $res = $conn->query(
            "SELECT id, email, full_name FROM users
              WHERE role = 'vendor' AND (username IS NULL OR username = '')"
        );
        if (!$res) {
            return;
        }
        $upd = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
        while ($row = $res->fetch_assoc()) {
            $seed = !empty($row['email']) ? $row['email'] : ($row['full_name'] ?? '');
            $username = generate_unique_username($conn, (string) $seed, (int) $row['id']);
            $uid = (int) $row['id'];
            $upd->bind_param("si", $username, $uid);
            try {
                $upd->execute();
            } catch (mysqli_sql_exception $e) {
                // Extremely unlikely race on the unique index — log and move on.
                error_log("[vendor_account_schema] backfill username for user {$uid} failed: " . $e->getMessage());
            }
        }
    }
}
