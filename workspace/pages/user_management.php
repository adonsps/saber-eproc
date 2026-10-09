<?php
// pages/user_management.php
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';
require_once '../includes/header.php';

if ($_SESSION['role'] !== 'Category Manager') {
    echo "<div class='p-8 text-center mt-10'><h2 class='text-2xl font-bold text-red-600'>Akses Ditolak. Khusus Category Manager.</h2></div>";
    require_once '../includes/footer.php'; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_user') {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $selected_managers = $_POST['manager_usernames'] ?? [];
        $first_manager = (!empty($selected_managers) && $selected_managers[0] !== '') ? $selected_managers[0] : null;

        $create_announcement = isset($_POST['create_announcement']) ? 1 : 0;
        $team_name = !empty($_POST['team_name']) ? $_POST['team_name'] : null;
        $gp_officer_id = !empty($_POST['gp_officer_id']) ? $_POST['gp_officer_id'] : null;

        $stmt = $pdo->prepare("INSERT INTO users (username, name, email, phone_number, role, password, manager_username, create_announcement, team_name, gp_officer_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$_POST['username'], $_POST['name'], $_POST['email'], $_POST['phone_number'], $_POST['role'], $hash, $first_manager, $create_announcement, $team_name, $gp_officer_id]);

        // Insert into VSS DB to ensure SSO works
        try {
            $vss_pdo = get_vss_pdo();

            $vss_check = $vss_pdo->prepare("SELECT id FROM users WHERE email = ?");
            $vss_check->execute([$_POST['email']]);
            if (!$vss_check->fetch()) {
                $vss_insert = $vss_pdo->prepare("INSERT INTO users (full_name, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, 1)");
                // Fallback to sourcing role in VSS for Procurement users, requestor for Technical
                $vss_role = ($_POST['role'] === 'Technical Department') ? 'requestor' : 'sourcing';
                $vss_insert->execute([$_POST['name'], $_POST['email'], $hash, $vss_role]);
            }
        } catch (PDOException $e) {
            error_log("Failed to insert user into VSS DB: " . $e->getMessage());
        }

        try {
            if (!empty($selected_managers)) {
                $stmt_mgr = $pdo->prepare("INSERT INTO user_managers (username, manager_username) VALUES (?, ?)");
                foreach ($selected_managers as $mgr) {
                    if (!empty($mgr)) {
                        $stmt_mgr->execute([$_POST['username'], $mgr]);
                    }
                }
            }
        } catch (PDOException $e) {}
        $msg = "User berhasil ditambahkan!";
    } elseif ($_POST['action'] === 'bulk_edit_users') {
        // Batch save: the table is one big form, so a Category Manager can edit
        // several users at once without losing the other rows on every submit.
        $rows = $_POST['u'] ?? [];

        // Snapshot current data so we only touch rows that really changed.
        $current = [];
        foreach ($pdo->query("SELECT username, name, email, phone_number, role, team_name, gp_officer_id, manager_username, create_announcement FROM users")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $current[$c['username']] = $c;
        }

        $existing_managers = [];
        try {
            foreach ($pdo->query("SELECT username, manager_username FROM user_managers")->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $existing_managers[$m['username']][] = $m['manager_username'];
            }
        } catch (PDOException $e) {}

        $upd = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone_number = ?, role = ?, manager_username = ?, create_announcement = ?, team_name = ?, gp_officer_id = ? WHERE username = ?");

        $saved_count = 0;
        $skipped_names = [];

        foreach ($rows as $row) {
            $username = trim($row['username'] ?? '');
            if ($username === '' || !isset($current[$username])) continue;

            $old = $current[$username];

            $new_name  = trim($row['name'] ?? '');
            $new_email = trim($row['email'] ?? '');
            $new_phone = trim($row['phone_number'] ?? '');
            $new_role  = $row['role'] ?? $old['role'];
            $new_team  = trim($row['team_name'] ?? '');
            $new_gp    = trim($row['gp_officer_id'] ?? '');
            $new_mgr   = trim($row['manager_username'] ?? '');
            $new_ann   = !empty($row['create_announcement']) ? 1 : 0;

            if ($new_name === '') { $skipped_names[] = $username; continue; }

            $unchanged = $new_name  === (string)$old['name']
                && $new_email === (string)($old['email'] ?? '')
                && $new_phone === (string)($old['phone_number'] ?? '')
                && $new_role  === (string)$old['role']
                && $new_team  === (string)($old['team_name'] ?? '')
                && $new_gp    === (string)($old['gp_officer_id'] ?? '')
                && $new_mgr   === (string)($old['manager_username'] ?? '')
                && $new_ann   === (int)($old['create_announcement'] ?? 0);
            if ($unchanged) continue;

            $upd->execute([
                $new_name,
                $new_email,
                $new_phone,
                $new_role,
                $new_mgr !== '' ? $new_mgr : null,
                $new_ann,
                $new_team !== '' ? $new_team : null,
                $new_gp !== '' ? $new_gp : null,
                $username
            ]);
            $saved_count++;

            // Keep VSS in sync (same rule as the single-row edit above)
            if ($new_email !== '') {
                try {
                    $vss_pdo = get_vss_pdo();
                    $vss_role = ($new_role === 'Technical Department') ? 'requestor' : 'sourcing';
                    $vss_pdo->prepare("UPDATE users SET full_name = ?, role = ? WHERE email = ?")
                            ->execute([$new_name, $vss_role, $new_email]);
                } catch (PDOException $e) {
                    error_log("Failed to update user in VSS DB: " . $e->getMessage());
                }
            }

            $old_mgrs = $existing_managers[$username] ?? [];
            $new_mgrs = $new_mgr !== '' ? [$new_mgr] : [];
            if ($old_mgrs !== $new_mgrs) {
                try {
                    $pdo->prepare("DELETE FROM user_managers WHERE username = ?")->execute([$username]);
                    if (!empty($new_mgrs)) {
                        $stmt_mgr = $pdo->prepare("INSERT INTO user_managers (username, manager_username) VALUES (?, ?)");
                        foreach ($new_mgrs as $mgr) { $stmt_mgr->execute([$username, $mgr]); }
                    }
                } catch (PDOException $e) {}
            }
        }

        if ($saved_count > 0) {
            $msg = "$saved_count user berhasil diupdate!";
        } else {
            $msg = "Tidak ada perubahan yang disimpan.";
        }
        if (!empty($skipped_names)) {
            $msg .= " Dilewati (nama kosong): " . htmlspecialchars(implode(', ', $skipped_names)) . ".";
        }
    } elseif ($_POST['action'] === 'reset_password') {
        $hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ? WHERE username = ?")->execute([$hash, $_POST['username']]);

        // Login actually authenticates against VSS's password_hash, not this
        // Workspace column (see workspace/pages/login.php) — reset it there too.
        $stmt_email = $pdo->prepare("SELECT email FROM users WHERE username = ?");
        $stmt_email->execute([$_POST['username']]);
        $target_email = $stmt_email->fetchColumn();

        if (!empty($target_email)) {
            try {
                $vss_pdo = get_vss_pdo();

                $vss_stmt = $vss_pdo->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE email = ?");
                $vss_stmt->execute([$hash, $target_email]);

                $msg = $vss_stmt->rowCount() > 0
                    ? "Password berhasil di-reset (Workspace & VSS)!"
                    : "Password Workspace direset, tapi email ini tidak ditemukan di VSS — password VSS TIDAK berubah.";
            } catch (PDOException $e) {
                $msg = "Password Workspace direset, tapi gagal menghubungi database VSS — password VSS TIDAK berubah.";
            }
        } else {
            $msg = "Password Workspace direset, tapi user ini tidak punya email terdaftar — password VSS TIDAK berubah.";
        }
    } elseif ($_POST['action'] === 'activate_user') {
        $pdo->prepare("UPDATE users SET is_active = 1 WHERE username = ?")->execute([$_POST['username']]);
        $msg = "User berhasil diaktifkan!";
    } elseif ($_POST['action'] === 'deactivate_user') {
        $pdo->prepare("UPDATE users SET is_active = 0 WHERE username = ?")->execute([$_POST['username']]);
        $msg = "User berhasil dinonaktifkan!";
    }
}

$users = $pdo->query("SELECT * FROM users ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$all_managers = $pdo->query("SELECT username, name FROM users ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch user managers mapping for the UI
$user_managers_map = [];
try {
    $mappings = $pdo->query("SELECT username, manager_username FROM user_managers")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($mappings as $m) {
        $user_managers_map[$m['username']][] = $m['manager_username'];
    }
} catch (PDOException $e) {}

// Fallback to single manager from users table
foreach ($users as $u) {
    if (!empty($u['manager_username']) && !in_array($u['manager_username'], $user_managers_map[$u['username']] ?? [])) {
        $user_managers_map[$u['username']][] = $u['manager_username'];
    }
}

$teams = $pdo->query("SELECT DISTINCT team_name FROM users WHERE team_name IS NOT NULL AND team_name != '' ORDER BY team_name ASC")->fetchAll(PDO::FETCH_COLUMN);
?>

<style>
    /* Rows with unsaved edits stand out so the Category Manager can double-check
       exactly what they touched before hitting Simpan. */
    tr.row-changed > td { background-color: #fffbeb !important; }
    tr.row-changed > td:first-child { box-shadow: inset 4px 0 0 0 #f59e0b; }
    tr.row-changed { font-weight: 700; }
    .field-changed {
        border-color: #d97706 !important;
        background-color: #fef3c7 !important;
        font-weight: 700;
        box-shadow: 0 0 0 1px #d97706;
    }
    #unsavedBar { transition: opacity .15s ease, transform .15s ease; }
</style>

<div class="max-w-7xl mx-auto py-8">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-3xl font-bold text-gray-800">Manajemen Pengguna</h2>
    </div>

    <!-- ═══ STICKY UNSAVED-CHANGES BAR (only visible when something changed) ═══ -->
    <div id="unsavedBar" class="hidden sticky top-0 z-30 mb-4">
        <div class="bg-amber-50 border border-amber-300 shadow-lg rounded-lg px-4 py-3 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2 text-sm text-amber-900">
                <svg class="w-5 h-5 flex-shrink-0 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path></svg>
                <span><strong id="unsavedCount">0</strong> baris belum disimpan <span class="font-normal text-amber-700">— baris yang berubah ditandai kuning di bawah.</span></span>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" id="discardChangesBtn" class="px-3 py-2 text-xs font-bold text-slate-600 bg-white border border-slate-300 rounded hover:bg-slate-100 transition">Batalkan Perubahan</button>
                <button type="submit" form="bulkEditForm" class="px-5 py-2 text-xs font-bold text-white bg-indigo-600 rounded shadow hover:bg-indigo-700 transition">Simpan Semua Perubahan</button>
            </div>
        </div>
    </div>

    <?php if(isset($msg)): ?><div class="bg-green-100 text-green-800 p-4 mb-4 rounded font-bold"><?= $msg ?></div><?php endif; ?>

    <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200 mb-8">
        <h3 class="text-lg font-bold border-b pb-2 mb-4">Tambah User Baru</h3>
        <form method="POST" class="grid grid-cols-1 md:grid-cols-6 gap-4 items-end">
            <input type="hidden" name="action" value="add_user">
            <div><label class="block text-xs font-bold mb-1">Username</label><input type="text" name="username" required class="w-full p-2 border rounded text-xs"></div>
            <div><label class="block text-xs font-bold mb-1">Nama Lengkap</label><input type="text" name="name" required class="w-full p-2 border rounded text-xs"></div>
            <div><label class="block text-xs font-bold mb-1">Email</label><input type="email" name="email" class="w-full p-2 border rounded text-xs"></div>
            <div><label class="block text-xs font-bold mb-1">No. WhatsApp</label><input type="text" name="phone_number" placeholder="628..." class="w-full p-2 border rounded text-xs"></div>
            <div>
                <label class="block text-xs font-bold mb-1">Role</label>
                <select name="role" required class="w-full p-2 border rounded text-xs">
                    <option value="Technical Department">Technical Department</option>
                    <option value="Category Manager">Category Manager</option>
                    <option value="Manager">Procurement Manager</option>
                    <option value="PIC">PIC</option>
                    <option value="Project Director">Project Director</option>
                    <option value="QS">QS</option>
                    <option value="STC">STC</option>
                    <option value="Admin">Admin</option>
                    <option value="Unassigned Role">Unassigned Role</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold mb-1">Team</label>
                <input type="text" name="team_name" list="team_list_add" class="w-full p-2 border rounded text-xs" placeholder="e.g. General Affairs (GA)">
                <datalist id="team_list_add">
                    <?php foreach($teams as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label class="block text-xs font-bold mb-1">Manager</label>
                <select name="manager_usernames[]" class="w-full p-2 border rounded text-xs">
                    <option value="">-- Tidak Ada --</option>
                    <?php foreach($all_managers as $m): ?>
                        <option value="<?= htmlspecialchars($m['username']) ?>"><?= htmlspecialchars($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label class="block text-xs font-bold mb-1">GP Officer ID(s)</label><input type="text" name="gp_officer_id" class="w-full p-2 border rounded text-xs" placeholder="e.g. EWALDO TANTON, 183645 EWALDO"></div>
            <div><label class="block text-xs font-bold mb-1">Password Default</label><input type="text" name="password" required class="w-full p-2 border rounded text-xs" value="password123"></div>
            <div class="flex items-center gap-2 pb-2">
                <input type="checkbox" name="create_announcement" value="1" id="add_create_announcement" class="rounded border-gray-300 text-indigo-600">
                <label for="add_create_announcement" class="text-xs font-bold text-gray-700">Create Announcement</label>
            </div>
            <div class="md:col-span-6 flex justify-end"><button type="submit" class="bg-indigo-600 text-white font-bold py-2 px-6 rounded text-sm shadow">Tambah User</button></div>
        </form>
    </div>

    <form method="POST" id="bulkEditForm" class="bg-white shadow-md rounded-lg p-6 border border-gray-200 overflow-x-auto">
        <input type="hidden" name="action" value="bulk_edit_users">
        <table class="w-full text-left text-sm whitespace-nowrap" id="userTable">
            <thead class="bg-gray-100 border-b">
                <tr>
                    <th class="p-3 cursor-pointer select-none sortable-header text-indigo-700 hover:text-indigo-900" data-sort="username">Username <span class="sort-icon text-xs text-gray-400">&#8597;</span></th>
                    <th class="p-3 cursor-pointer select-none sortable-header text-indigo-700 hover:text-indigo-900" data-sort="name">Nama <span class="sort-icon text-xs text-gray-400">&#8597;</span></th>
                    <th class="p-3 cursor-pointer select-none sortable-header text-indigo-700 hover:text-indigo-900" data-sort="email">Email <span class="sort-icon text-xs text-gray-400">&#8597;</span></th>
                    <th class="p-3 cursor-pointer select-none sortable-header text-indigo-700 hover:text-indigo-900" data-sort="phone">WhatsApp <span class="sort-icon text-xs text-gray-400">&#8597;</span></th>
                    <th class="p-3 cursor-pointer select-none sortable-header text-indigo-700 hover:text-indigo-900" data-sort="role">Role <span class="sort-icon text-xs text-gray-400">&#8597;</span></th>
                    <th class="p-3 cursor-pointer select-none sortable-header text-indigo-700 hover:text-indigo-900" data-sort="team">Team <span class="sort-icon text-xs text-gray-400">&#8597;</span></th>
                    <th class="p-3 cursor-pointer select-none sortable-header text-indigo-700 hover:text-indigo-900" data-sort="gp">GP Officer ID(s) <span class="sort-icon text-xs text-gray-400">&#8597;</span></th>
                    <th class="p-3 cursor-pointer select-none sortable-header text-indigo-700 hover:text-indigo-900" data-sort="manager">Manager <span class="sort-icon text-xs text-gray-400">&#8597;</span></th>
                    <th class="p-3">Announcement</th>
                    <th class="p-3 text-center cursor-pointer select-none sortable-header text-indigo-700 hover:text-indigo-900" data-sort="status">Status <span class="sort-icon text-xs text-gray-400">&#8597;</span></th>
                    <th class="p-3 text-right">Aksi</th>
                </tr>
                <tr class="bg-gray-50 border-b">
                    <th class="p-2"></th>
                    <th class="p-2"></th>
                    <th class="p-2"></th>
                    <th class="p-2"></th>
                    <th class="p-2">
                        <select class="w-full p-1 text-xs border rounded column-filter font-normal" data-col="role">
                            <option value="">All Roles</option>
                            <option value="Technical Department">Technical Department</option>
                            <option value="Category Manager">Cat. Manager</option>
                            <option value="Manager">Procurement Manager</option>
                            <option value="PIC">PIC</option>
                            <option value="Project Director">Project Director</option>
                            <option value="QS">QS</option>
                            <option value="STC">STC</option>
                            <option value="Admin">Admin</option>
                            <option value="Unassigned Role">Unassigned Role</option>
                        </select>
                    </th>
                    <th class="p-2">
                        <select class="w-full p-1 text-xs border rounded column-filter font-normal" data-col="team">
                            <option value="">All Teams</option>
                            <?php foreach($teams as $t): ?>
                                <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </th>
                    <th class="p-2"></th>
                    <th class="p-2">
                        <select class="w-full p-1 text-xs border rounded column-filter font-normal" data-col="manager">
                            <option value="">All Managers</option>
                            <?php foreach($all_managers as $m): ?>
                                <option value="<?= htmlspecialchars($m['username']) ?>"><?= htmlspecialchars($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </th>
                    <th class="p-2"></th>
                    <th class="p-2 text-center">
                        <select class="w-full p-1 text-xs border rounded column-filter font-normal" data-col="status">
                            <option value="">All Status</option>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </th>
                    <th class="p-2"></th>
                </tr>
            </thead>
            <tbody id="userTableBody">
                <?php $row_i = 0; foreach($users as $u): $row_i++; ?>
                <tr class="border-b hover:bg-gray-50 user-row"
                    data-username="<?= htmlspecialchars($u['username']) ?>"
                    data-name="<?= htmlspecialchars($u['name']) ?>"
                    data-email="<?= htmlspecialchars($u['email'] ?? '') ?>"
                    data-phone="<?= htmlspecialchars($u['phone_number'] ?? '') ?>"
                    data-role="<?= htmlspecialchars($u['role']) ?>"
                    data-team="<?= htmlspecialchars($u['team_name'] ?? '') ?>"
                    data-gp="<?= htmlspecialchars($u['gp_officer_id'] ?? '') ?>"
                    <?php $my_managers = $user_managers_map[$u['username']] ?? []; ?>
                    data-manager="<?= htmlspecialchars(implode(',', $my_managers)) ?>"
                    data-status="<?= (!isset($u['is_active']) || $u['is_active']) ? 'Active' : 'Inactive' ?>">
                    <td class="p-3 font-bold text-gray-700">
                        <?= htmlspecialchars($u['username']) ?>
                        <span class="js-changed-badge hidden ml-1 align-middle bg-amber-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded">DIUBAH</span>
                        <input type="hidden" name="u[<?= $row_i ?>][username]" value="<?= htmlspecialchars($u['username']) ?>">
                    </td>
                    <td class="p-3">
                        <input type="text" name="u[<?= $row_i ?>][name]" value="<?= htmlspecialchars($u['name']) ?>" class="js-field p-1 border rounded w-32 text-xs">
                    </td>
                    <td class="p-3"><input type="email" name="u[<?= $row_i ?>][email]" value="<?= htmlspecialchars($u['email'] ?? '') ?>" class="js-field p-1 border rounded w-32 text-xs"></td>
                    <td class="p-3"><input type="text" name="u[<?= $row_i ?>][phone_number]" value="<?= htmlspecialchars($u['phone_number'] ?? '') ?>" class="js-field p-1 border rounded w-28 text-xs"></td>
                    <td class="p-3">
                        <select name="u[<?= $row_i ?>][role]" class="js-field p-1 border rounded text-xs">
                            <option value="Technical Department" <?= $u['role']=='Technical Department'?'selected':'' ?>>Technical Department</option>
                            <option value="Category Manager" <?= $u['role']=='Category Manager'?'selected':'' ?>>Cat. Manager</option>
                            <option value="Manager" <?= $u['role']=='Manager'?'selected':'' ?>>Procurement Manager</option>
                            <option value="PIC" <?= $u['role']=='PIC'?'selected':'' ?>>PIC</option>
                            <option value="Project Director" <?= $u['role']=='Project Director'?'selected':'' ?>>Project Director</option>
                            <option value="QS" <?= $u['role']=='QS'?'selected':'' ?>>QS</option>
                            <option value="STC" <?= $u['role']=='STC'?'selected':'' ?>>STC</option>
                            <option value="Admin" <?= $u['role']=='Admin'?'selected':'' ?>>Admin</option>
                            <option value="Unassigned Role" <?= $u['role']=='Unassigned Role'?'selected':'' ?>>Unassigned Role</option>
                        </select>
                    </td>
                    <td class="p-3">
                        <input type="text" name="u[<?= $row_i ?>][team_name]" list="team_list_edit_<?= $row_i ?>" class="js-field p-1 border rounded w-32 text-xs" value="<?= htmlspecialchars($u['team_name'] ?? '') ?>">
                        <datalist id="team_list_edit_<?= $row_i ?>">
                            <?php foreach($teams as $t): ?>
                                <option value="<?= htmlspecialchars($t) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </td>
                    <td class="p-3"><input type="text" name="u[<?= $row_i ?>][gp_officer_id]" value="<?= htmlspecialchars($u['gp_officer_id'] ?? '') ?>" class="js-field p-1 border rounded w-32 text-xs" title="Comma separated IDs"></td>
                    <td class="p-3">
                        <?php $my_managers = $user_managers_map[$u['username']] ?? []; ?>
                        <select name="u[<?= $row_i ?>][manager_username]" class="js-field p-1 border rounded w-32 text-xs">
                            <option value="">-- Tidak Ada --</option>
                            <?php foreach($all_managers as $m): ?>
                                <?php if ($m['username'] !== $u['username']): ?>
                                    <option value="<?= htmlspecialchars($m['username']) ?>" <?= in_array($m['username'], $my_managers) ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="p-3">
                        <label class="inline-flex items-center gap-2 text-xs font-semibold text-gray-700">
                            <input type="hidden" name="u[<?= $row_i ?>][create_announcement]" value="0">
                            <input type="checkbox" name="u[<?= $row_i ?>][create_announcement]" value="1" class="js-field rounded border-gray-300 text-indigo-600" <?= !empty($u['create_announcement']) ? 'checked' : '' ?>>
                            Create
                        </label>
                    </td>
                    <td class="p-3 text-center">
                        <?php if (!isset($u['is_active']) || $u['is_active']): ?>
                            <span class="bg-green-100 text-green-800 text-xs font-bold px-2 py-1 rounded">Active</span>
                        <?php else: ?>
                            <span class="bg-red-100 text-red-800 text-xs font-bold px-2 py-1 rounded">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="p-3">
                        <div class="flex justify-end gap-2">
                            <button type="button" class="js-open-reset-modal bg-red-100 text-red-700 px-2 py-1 rounded font-bold text-xs border border-red-200" data-username="<?= htmlspecialchars($u['username']) ?>">Reset Pass</button>
                            <button type="button" class="js-toggle-active <?= (!isset($u['is_active']) || $u['is_active']) ? 'bg-orange-100 text-orange-700 border-orange-200' : 'bg-green-100 text-green-700 border-green-200' ?> px-2 py-1 rounded font-bold text-xs border"
                                data-username="<?= htmlspecialchars($u['username']) ?>"
                                data-action="<?= (!isset($u['is_active']) || $u['is_active']) ? 'deactivate_user' : 'activate_user' ?>"
                                data-verb="<?= (!isset($u['is_active']) || $u['is_active']) ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                <?= (!isset($u['is_active']) || $u['is_active']) ? 'Deactivate' : 'Activate' ?>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </form>

    <!-- Row actions live outside #bulkEditForm (forms cannot be nested) and are
         submitted by JS from the per-row buttons. -->
    <form method="POST" id="rowActionForm">
        <input type="hidden" name="action" id="rowActionType" value="">
        <input type="hidden" name="username" id="rowActionUsername" value="">
        <input type="hidden" name="new_password" value="password123">
    </form>
</div>
<script>
$(document).ready(function() {
    // Filtering logic
    function applyFilters() {
        var role = $('select[data-col="role"]').val();
        var team = $('select[data-col="team"]').val();
        var manager = $('select[data-col="manager"]').val();
        var status = $('select[data-col="status"]').val();

        $('.user-row').each(function() {
            var $row = $(this);
            var matchRole = !role || $row.attr('data-role') === role;
            var matchTeam = !team || $row.attr('data-team') === team;
            
            var rowManagers = ($row.attr('data-manager') || '').split(',');
            var matchManager = !manager || rowManagers.includes(manager);
            
            var matchStatus = !status || $row.attr('data-status') === status;

            if (matchRole && matchTeam && matchManager && matchStatus) {
                $row.show();
            } else {
                $row.hide();
            }
        });
    }

    $('.column-filter').on('change', applyFilters);

    // Sorting logic
    $('th.sortable-header').on('click', function() {
        var sortKey = $(this).data('sort');
        var order = $(this).data('order') === 'asc' ? 'desc' : 'asc';
        
        // Reset all headers
        $('th.sortable-header').data('order', '');
        $('th.sortable-header').find('.sort-icon').html('&#8597;');
        
        // Set current header
        $(this).data('order', order);
        $(this).find('.sort-icon').html(order === 'asc' ? '&#8593;' : '&#8595;');

        var rows = $('.user-row').get();
        rows.sort(function(a, b) {
            var A = $(a).attr('data-' + sortKey).toUpperCase();
            var B = $(b).attr('data-' + sortKey).toUpperCase();
            
            if (A < B) return order === 'asc' ? -1 : 1;
            if (A > B) return order === 'asc' ? 1 : -1;
            return 0;
        });

        $.each(rows, function(index, row) {
            $('#userTableBody').append(row);
        });
    });
});
</script>

<!-- ═══════════════ GENERIC CONFIRM MODAL (replaces window.confirm) ═══════════════ -->
<div id="appConfirmModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md">
        <div class="px-6 py-5 border-b border-slate-100 flex items-start gap-3">
            <div id="appConfirmIcon" class="w-9 h-9 flex-shrink-0 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path></svg>
            </div>
            <h3 id="appConfirmTitle" class="text-base font-bold text-slate-800 pt-1"></h3>
        </div>
        <div id="appConfirmBody" class="px-6 py-5 text-sm text-slate-600 leading-relaxed"></div>
        <div class="px-6 py-4 bg-slate-50 rounded-b-xl flex justify-end gap-3">
            <button id="appConfirmCancel" type="button" class="px-4 py-2 text-sm font-bold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-100 transition">Batal</button>
            <button id="appConfirmOk" type="button" class="px-4 py-2 text-sm font-bold text-white rounded-lg transition">Ya</button>
        </div>
    </div>
</div>

<script>
/* Small promise-free confirm dialog so the page never falls back to the raw
   browser confirm()/alert() boxes. */
(function () {
    var modal   = document.getElementById('appConfirmModal');
    var titleEl = document.getElementById('appConfirmTitle');
    var bodyEl  = document.getElementById('appConfirmBody');
    var iconEl  = document.getElementById('appConfirmIcon');
    var okBtn   = document.getElementById('appConfirmOk');
    var cancelBtn = document.getElementById('appConfirmCancel');
    var onConfirm = null;

    var TONES = {
        amber:  { icon: 'bg-amber-100 text-amber-600',   btn: 'bg-amber-600 hover:bg-amber-700' },
        red:    { icon: 'bg-red-100 text-red-600',       btn: 'bg-red-600 hover:bg-red-700' },
        indigo: { icon: 'bg-indigo-100 text-indigo-600', btn: 'bg-indigo-600 hover:bg-indigo-700' }
    };

    function close() {
        modal.classList.add('hidden');
        onConfirm = null;
    }

    // opts: { title, html, confirmText, cancelText, tone, alertOnly, onConfirm }
    window.appConfirm = function (opts) {
        var tone = TONES[opts.tone] || TONES.amber;
        titleEl.textContent = opts.title || 'Konfirmasi';
        bodyEl.innerHTML = opts.html || '';
        iconEl.className = 'w-9 h-9 flex-shrink-0 rounded-full flex items-center justify-center ' + tone.icon;
        okBtn.className = 'px-4 py-2 text-sm font-bold text-white rounded-lg transition ' + tone.btn;
        okBtn.textContent = opts.confirmText || 'Ya';
        cancelBtn.textContent = opts.cancelText || 'Batal';
        cancelBtn.classList.toggle('hidden', !!opts.alertOnly);
        onConfirm = opts.onConfirm || null;
        modal.classList.remove('hidden');
        okBtn.focus();
    };

    okBtn.addEventListener('click', function () {
        var cb = onConfirm;
        close();
        if (cb) cb();
    });
    cancelBtn.addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) close();
    });
})();
</script>

<!-- ═══════════════════════ RESET PASSWORD MODAL ═══════════════════════ -->
<div id="resetPassModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm">
        <div class="px-6 py-5 border-b border-slate-100">
            <h3 class="text-base font-bold text-slate-800">Reset Password?</h3>
        </div>
        <div class="px-6 py-5 text-sm text-slate-600">
            This will reset <strong id="resetPassModalUsername" class="text-slate-800"></strong>'s password back to the default (<code class="bg-slate-100 px-1 rounded">password123</code>) in both Workspace and VSS.
            <p id="resetPassUnsavedWarn" class="hidden mt-3 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-3 py-2 text-xs font-semibold"></p>
        </div>
        <div class="px-6 py-4 bg-slate-50 rounded-b-xl flex justify-end gap-3">
            <button id="cancelResetPassBtn" type="button" class="px-4 py-2 text-sm font-bold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-100 transition">Cancel</button>
            <button id="confirmResetPassBtn" type="button" class="px-4 py-2 text-sm font-bold text-white bg-red-600 rounded-lg hover:bg-red-700 transition">Reset Password</button>
        </div>
    </div>
</div>

<script>
(function () {
    var resetModal = document.getElementById('resetPassModal');
    var resetModalUsername = document.getElementById('resetPassModalUsername');
    var confirmResetBtn = document.getElementById('confirmResetPassBtn');
    var cancelResetBtn = document.getElementById('cancelResetPassBtn');
    var actionForm = document.getElementById('rowActionForm');
    var actionType = document.getElementById('rowActionType');
    var actionUsername = document.getElementById('rowActionUsername');
    var pendingUsername = null;

    var unsavedWarn = document.getElementById('resetPassUnsavedWarn');

    document.querySelectorAll('.js-open-reset-modal').forEach(function (btn) {
        btn.addEventListener('click', function () {
            pendingUsername = this.dataset.username;
            resetModalUsername.textContent = pendingUsername;

            // Surface pending table edits here instead of stacking a second dialog
            var pending = window.unsavedRowCount ? window.unsavedRowCount() : 0;
            if (pending > 0) {
                unsavedWarn.textContent = 'Perhatian: ' + pending + ' baris yang belum disimpan akan hilang jika Anda melanjutkan.';
                unsavedWarn.classList.remove('hidden');
            } else {
                unsavedWarn.classList.add('hidden');
            }
            resetModal.classList.remove('hidden');
        });
    });

    function closeResetModal() {
        resetModal.classList.add('hidden');
        pendingUsername = null;
    }

    cancelResetBtn.addEventListener('click', closeResetModal);
    resetModal.addEventListener('click', function (e) {
        if (e.target === resetModal) closeResetModal();
    });
    confirmResetBtn.addEventListener('click', function () {
        if (!pendingUsername) return;
        actionType.value = 'reset_password';
        actionUsername.value = pendingUsername;
        if (window.allowUnload) window.allowUnload();
        actionForm.submit();
    });

    // Activate / Deactivate
    document.querySelectorAll('.js-toggle-active').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var verb = this.dataset.verb;
            var username = this.dataset.username;
            var action = this.dataset.action;
            var deactivating = action === 'deactivate_user';

            var html = '<p>' + verb + ' user <strong class="text-slate-800">' + escapeHtml(username) + '</strong>?</p>';
            var pending = window.unsavedRowCount ? window.unsavedRowCount() : 0;
            if (pending > 0) {
                html += '<p class="mt-3 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-3 py-2 text-xs font-semibold">'
                     +  'Perhatian: ' + pending + ' baris yang belum disimpan akan hilang jika Anda melanjutkan.</p>';
            }

            window.appConfirm({
                title: verb + ' User?',
                html: html,
                tone: deactivating ? 'red' : 'indigo',
                confirmText: deactivating ? 'Deactivate' : 'Activate',
                onConfirm: function () {
                    actionType.value = action;
                    actionUsername.value = username;
                    if (window.allowUnload) window.allowUnload();
                    actionForm.submit();
                }
            });
        });
    });

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
})();
</script>

<script>
/* ═══════════ UNSAVED-CHANGE TRACKING FOR THE BULK EDIT TABLE ═══════════
   Every editable field remembers the value it was rendered with. As soon as a
   field differs from that baseline the field, its row, and the sticky save bar
   light up — so a Category Manager can edit many users, see exactly what they
   touched, then save everything in one submit. */
(function () {
    var form        = document.getElementById('bulkEditForm');
    var tbody       = document.getElementById('userTableBody');
    var bar         = document.getElementById('unsavedBar');
    var counter     = document.getElementById('unsavedCount');
    var discardBtn  = document.getElementById('discardChangesBtn');
    if (!form || !tbody) return;

    var fields = Array.prototype.slice.call(tbody.querySelectorAll('.js-field'));
    var suppressUnload = false;

    function currentValue(el) {
        return el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
    }

    // Baseline = what the server rendered
    fields.forEach(function (el) { el.dataset.original = currentValue(el); });

    function changedRowCount() {
        return tbody.querySelectorAll('tr.row-changed').length;
    }

    function refreshBar() {
        var n = changedRowCount();
        counter.textContent = n;
        bar.classList.toggle('hidden', n === 0);
    }

    // Field name -> the data-* attribute the filter/sort code reads, so those
    // keep working against what is currently on screen.
    var DATA_ATTR = {
        name: 'data-name', email: 'data-email', phone_number: 'data-phone',
        role: 'data-role', team_name: 'data-team', gp_officer_id: 'data-gp',
        manager_username: 'data-manager'
    };

    function syncRow(row) {
        var rowFields = row.querySelectorAll('.js-field');
        var dirty = false;
        Array.prototype.forEach.call(rowFields, function (el) {
            var isDirty = currentValue(el) !== el.dataset.original;
            el.classList.toggle('field-changed', isDirty);
            if (isDirty) dirty = true;

            var key = (el.name.match(/\[([a-z_]+)\]$/) || [])[1];
            if (key && DATA_ATTR[key]) row.setAttribute(DATA_ATTR[key], el.value);
        });
        row.classList.toggle('row-changed', dirty);
        var badge = row.querySelector('.js-changed-badge');
        if (badge) badge.classList.toggle('hidden', !dirty);
    }

    tbody.addEventListener('input', function (e) {
        var el = e.target;
        if (!el.classList || !el.classList.contains('js-field')) return;
        syncRow(el.closest('tr'));
        refreshBar();
    });
    tbody.addEventListener('change', function (e) {
        var el = e.target;
        if (!el.classList || !el.classList.contains('js-field')) return;
        syncRow(el.closest('tr'));
        refreshBar();
    });

    // Revert everything back to the rendered baseline
    discardBtn.addEventListener('click', function () {
        var n = changedRowCount();
        window.appConfirm({
            title: 'Batalkan Perubahan?',
            html: '<p>' + n + ' baris yang Anda ubah akan dikembalikan ke data semula. Tindakan ini tidak dapat diurungkan.</p>',
            tone: 'amber',
            confirmText: 'Ya, Batalkan',
            cancelText: 'Kembali',
            onConfirm: function () {
                fields.forEach(function (el) {
                    if (el.type === 'checkbox') {
                        el.checked = el.dataset.original === '1';
                    } else {
                        el.value = el.dataset.original;
                    }
                });
                Array.prototype.forEach.call(tbody.querySelectorAll('tr'), syncRow);
                refreshBar();
            }
        });
    });

    // Only changed rows are POSTed — keeps the request small and well under
    // PHP's max_input_vars no matter how many users are listed.
    form.addEventListener('submit', function (e) {
        var changedRows = tbody.querySelectorAll('tr.row-changed');
        if (changedRows.length === 0) {
            e.preventDefault();
            return;
        }

        var blank = null;
        Array.prototype.forEach.call(changedRows, function (row) {
            var nameInput = row.querySelector('input[name$="[name]"]');
            if (nameInput && nameInput.value.trim() === '' && !blank) blank = row;
        });
        if (blank) {
            e.preventDefault();
            window.appConfirm({
                title: 'Nama Belum Diisi',
                html: '<p>Kolom <strong class="text-slate-800">Nama</strong> tidak boleh kosong. Periksa kembali baris yang ditandai.</p>',
                tone: 'red',
                alertOnly: true,
                confirmText: 'Mengerti',
                onConfirm: function () { blank.querySelector('input[name$="[name]"]').focus(); }
            });
            return;
        }

        Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (row) {
            if (row.classList.contains('row-changed')) return;
            Array.prototype.forEach.call(row.querySelectorAll('input, select'), function (el) {
                el.disabled = true;
            });
        });
        suppressUnload = true;
    });

    window.addEventListener('beforeunload', function (e) {
        if (suppressUnload || changedRowCount() === 0) return;
        e.preventDefault();
        e.returnValue = '';
    });

    // Read by the row-action modals (reset password / activate / deactivate),
    // which navigate away and would otherwise silently drop pending edits.
    window.unsavedRowCount = function () { return changedRowCount(); };
    window.allowUnload = function () { suppressUnload = true; };
})();
</script>
<?php require_once '../includes/footer.php'; ?>
