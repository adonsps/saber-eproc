<?php
/**
 * includes/navbar_profile.php
 *
 * Combined profile control for the light navbar (.navbar-vss): avatar +
 * name + role in one button, with Logout inside its dropdown. Replaces the
 * old separate "Logged in as: <name>" label and standalone Logout button.
 *
 * Requires $path_to_root. Optional inputs, all unset again at the end so a
 * value can't leak into a later include:
 *   $navbar_profile_name     — overrides the displayed name. The vendor portal
 *                              passes the company name, since a vendor session
 *                              identifies a company rather than a staff member.
 *   $navbar_profile_subtitle — overrides the line under the name (vendor portal
 *                              passes company_type instead of a role label).
 *   $navbar_profile_fallback — name to use when the session has none.
 *   $navbar_profile_logout   — label for the logout item (default "Logout").
 *
 * Styling lives in assets/css/style.css section 6b.
 */

$np_name = $navbar_profile_name
    ?? $_SESSION['user_name']
    ?? $navbar_profile_fallback
    ?? 'User';

// Initials from the first two words, mirroring the Workspace Pro header.
$np_words    = preg_split('/\s+/', trim($np_name), -1, PREG_SPLIT_NO_EMPTY);
$np_initials = strtoupper(
    mb_substr($np_words[0] ?? 'U', 0, 1) . (isset($np_words[1]) ? mb_substr($np_words[1], 0, 1) : '')
);

// Session roles are machine slugs (sourcing, vendor_mgt, ...) — show a label.
$np_role_labels = [
    'sourcing'        => 'Sourcing Team',
    'vendor_mgt'      => 'Vendor Management',
    'vendormgt_head'  => 'Vendor Management Head',
    'admin'           => 'Administrator',
    'vendor'          => 'Vendor',
];
$np_role_slug = $_SESSION['role'] ?? '';
$np_role      = $navbar_profile_subtitle
    ?? $np_role_labels[$np_role_slug]
    ?? ucwords(str_replace('_', ' ', $np_role_slug));

$np_logout_label = $navbar_profile_logout ?? 'Logout';
?>
<div class="dropdown">
    <button class="btn btn-profile" type="button" data-bs-toggle="dropdown" aria-expanded="false"
            title="<?= htmlspecialchars($np_name . ($np_role !== '' ? ' — ' . $np_role : '')) ?>">
        <span class="profile-avatar"><?= htmlspecialchars($np_initials) ?></span>
        <?php /* Name/role only from xl up — below that the navbar needs the width
                 for the action buttons, so they move into the dropdown instead. */ ?>
        <span class="profile-meta d-none d-xl-block">
            <span class="profile-name"><?= htmlspecialchars($np_name) ?></span>
            <?php if ($np_role !== ''): ?>
                <span class="profile-role"><?= htmlspecialchars($np_role) ?></span>
            <?php endif; ?>
        </span>
        <i class="fas fa-chevron-down profile-caret"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2" style="min-width: 220px;">
        <li class="px-3 py-2 d-xl-none">
            <div class="fw-bold small text-dark"><?= htmlspecialchars($np_name) ?></div>
            <?php if ($np_role !== ''): ?>
                <div class="text-muted" style="font-size: 0.7rem;"><?= htmlspecialchars($np_role) ?></div>
            <?php endif; ?>
        </li>
        <li class="d-xl-none"><hr class="dropdown-divider"></li>
        <li>
            <a class="dropdown-item text-danger fw-bold" href="<?= $path_to_root ?>logout.php">
                <i class="fas fa-sign-out-alt me-2"></i><?= htmlspecialchars($np_logout_label) ?>
            </a>
        </li>
    </ul>
</div>
<?php
// Clear the optional inputs so they can't bleed into an unrelated later include.
unset($navbar_profile_name, $navbar_profile_subtitle, $navbar_profile_fallback, $navbar_profile_logout);
?>
