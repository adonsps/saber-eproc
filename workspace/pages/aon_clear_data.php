<?php
// One-time admin utility — delete after use
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';


$deleted = null;
$count   = (int)$pdo->query("SELECT COUNT(*) FROM aon_skus")->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'yes') {
    $pdo->exec("DELETE FROM aon_skus");
    $pdo->exec("ALTER TABLE aon_skus AUTO_INCREMENT = 1");
    // Reset seeds so the masterplan auto-seed runs again on next page load
    $pdo->exec("DELETE FROM aon_seeds");
    $deleted = $count;
    $count   = 0;
}
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Clear AON Data</title>
<style>
  body{font-family:sans-serif;padding:2rem;max-width:480px;margin:auto}
  .box{border:1px solid #e2e8f0;border-radius:12px;padding:1.5rem;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.06)}
  h2{margin-top:0}
  .count{font-size:2.5rem;font-weight:bold;color:#4f46e5}
  .btn-del{background:#dc2626;color:#fff;border:none;padding:.6rem 1.5rem;border-radius:8px;font-size:1rem;font-weight:bold;cursor:pointer}
  .btn-del:hover{background:#b91c1c}
  .btn-back{display:inline-block;margin-top:1rem;color:#4f46e5;text-decoration:none;font-weight:bold}
  .ok{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:1rem;color:#15803d;font-weight:bold;margin-bottom:1rem}
  .warn{background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:1rem;color:#c2410c;margin-bottom:1rem;font-size:.9rem}
</style>
</head>
<body>
<div class="box">
  <h2>Clear Master AON Data</h2>

  <?php if ($deleted !== null): ?>
    <div class="ok">&#10003; Done — <?= $deleted ?> row(s) deleted from <code>aon_skus</code>.</div>
    <a class="btn-back" href="master_aon.php">← Back to Master AON</a>
    <br><br>
    <small style="color:#94a3b8">You can now delete this file (<code>pages/aon_clear_data.php</code>).</small>

  <?php else: ?>
    <p>Current row count in <code>aon_skus</code>:</p>
    <p class="count"><?= number_format($count) ?></p>

    <?php if ($count === 0): ?>
      <p style="color:#64748b">Table is already empty.</p>
      <a class="btn-back" href="master_aon.php">← Back to Master AON</a>
    <?php else: ?>
      <div class="warn">&#9888; This will permanently delete ALL <?= number_format($count) ?> rows. This cannot be undone.</div>
      <form method="POST">
        <input type="hidden" name="confirm" value="yes">
        <button class="btn-del" type="submit"
          onclick="return confirm('Delete all <?= $count ?> rows from aon_skus? This cannot be undone.')">
          Delete All <?= number_format($count) ?> Rows
        </button>
      </form>
      <a class="btn-back" href="master_aon.php">← Cancel</a>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
