<?php
// modules/admin/holidays.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: " . $path_to_root . "login.php");
    exit();
}

// FIX: cast to int — original interpolated raw $_GET['year'] directly into SQL (injection risk)
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Clamp to a sane range so a crafted value can't return garbage
if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}

$stmt = $conn->prepare("SELECT * FROM holidays WHERE YEAR(holiday_date) = ? ORDER BY holiday_date ASC");
$stmt->bind_param("i", $year);
$stmt->execute();
$res = $stmt->get_result();

$page_title = "Holiday & Joint Leave Configuration";
include $path_to_root . 'includes/header.php';
?>

<nav class="navbar navbar-vss mb-4">
    <div class="container-fluid">
        <span class="navbar-brand fw-bold d-flex align-items-center gap-2"><?= vss_brand_logo() ?>Holiday Configuration</span>
        <a href="dashboard.php" class="btn btn-sm btn-nav">
            <i class="fas fa-arrow-left me-1"></i> Back to Admin Dashboard
        </a>
    </div>
</nav>

<div class="container py-3">
    <div class="row g-4">

        <!-- Add Holiday Form -->
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white fw-bold">
                    <i class="fas fa-plus-circle me-2"></i> Tambah Hari Libur
                </div>
                <div class="card-body">
                    <form action="holidays_action.php" method="POST">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Tanggal</label>
                            <input type="date" name="holiday_date" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Nama Hari Libur</label>
                            <input type="text" name="holiday_name" class="form-control" placeholder="Contoh: Hari Raya Idul Fitri" required maxlength="100">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Tipe</label>
                            <select name="type" class="form-select" required>
                                <option value="national">Libur Nasional</option>
                                <option value="joint_leave">Cuti Bersama</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 fw-bold">
                            <i class="fas fa-save me-1"></i> Simpan
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Holiday List -->
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Daftar Hari Libur Tahun <?= htmlspecialchars((string)$year) ?></strong>
                    <form method="GET" class="d-flex gap-2">
                        <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php for ($y = (int)date('Y') - 1; $y <= (int)date('Y') + 2; $y++): ?>
                                <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </form>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Tanggal</th>
                                <th>Nama</th>
                                <th>Tipe</th>
                                <th style="width:80px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($res && $res->num_rows > 0): ?>
                                <?php while ($h = $res->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?= date('d M Y (D)', strtotime($h['holiday_date'])) ?></strong></td>
                                        <td><?= htmlspecialchars($h['holiday_name']) ?></td>
                                        <td>
                                            <?php if ($h['type'] === 'joint_leave'): ?>
                                                <span class="badge bg-warning text-dark">Cuti Bersama</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Libur Nasional</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form action="holidays_action.php" method="POST"
                                                  onsubmit="return confirm('Hapus hari libur ini?');" class="d-inline">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">Belum ada hari libur tahun ini.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include $path_to_root . 'includes/footer.php'; ?>