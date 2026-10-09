<?php

declare(strict_types=1);

// backups/receive_backup.php
//
// Passive HTTP receiver for the nightly VSS backup (backups/backup.php,
// running on Hostinger where the live DB and uploads/ actually are). This
// file ships to BOTH Hostinger and the VPS via the same git checkout, but is
// only meant to ever answer on the VPS — see the two independent gates below:
//
//   1. VSS_BACKUP_RECEIVER_ENABLED — set to 'true' only in
//      docker-compose.prod.yml's environment for the vms_prod_app service.
//      Hostinger's PHP has no docker-compose environment at all, so this is
//      never set there and the endpoint 404s unconditionally, regardless of
//      whether backups/.htaccess lets the request through.
//   2. VSS_BACKUP_RECEIVER_SECRET — shared secret, must match the sender's
//      VSS_BACKUP_RECEIVER_SECRET (Hostinger side). Checked with hash_equals
//      to avoid timing leaks.
//
// Flipping only one of the two does nothing — same two-gate shape as
// workspace/cron/vendor_eval_v2_form2_reminder_cron.php's live-send switches.
//
// Stores the incoming zip under VSS_BACKUP_STORAGE_ROOT (default
// /var/vss_backups/storage, a persistent Docker volume off /var/www — see
// docker-compose.prod.yml) and rotates to the newest max_backup_files,
// deleting the rest. Straight PHP port of deleteOldBackups() in
// backups/apps-script-web-app.gs (the prior, never-deployed Google Drive
// approach this replaces).

header('Content-Type: application/json');

if (getenv('VSS_BACKUP_RECEIVER_ENABLED') !== 'true') {
    http_response_code(404);
    exit(0);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Method not allowed.', 405);
    }

    $secret = (string) (getenv('VSS_BACKUP_RECEIVER_SECRET') ?: '');
    if ($secret === '') {
        throw new RuntimeException('Receiver belum dikonfigurasi (VSS_BACKUP_RECEIVER_SECRET kosong).', 500);
    }

    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);

    if (!is_array($data)) {
        throw new RuntimeException('Payload tidak valid.', 400);
    }

    if (!hash_equals($secret, (string) ($data['token'] ?? ''))) {
        throw new RuntimeException('Unauthorized token.', 401);
    }

    $fileName = basename((string) ($data['file_name'] ?? ''));
    if ($fileName === '' || !preg_match('/^[A-Za-z0-9._-]+\.zip$/', $fileName)) {
        throw new RuntimeException('Nama file tidak valid.', 400);
    }

    $contentBase64 = (string) ($data['content_base64'] ?? '');
    if ($contentBase64 === '') {
        throw new RuntimeException('content_base64 kosong.', 400);
    }

    $bytes = base64_decode($contentBase64, true);
    if ($bytes === false) {
        throw new RuntimeException('Gagal decode base64.', 400);
    }

    $prefix = (string) ($data['backup_name_prefix'] ?? 'backup_website_');
    $maxBackupFiles = max(1, (int) ($data['max_backup_files'] ?? 3));
    $storageRoot = rtrim((string) (getenv('VSS_BACKUP_STORAGE_ROOT') ?: '/var/vss_backups/storage'), '/\\');

    ensureStorageDir($storageRoot);

    $destPath = $storageRoot . DIRECTORY_SEPARATOR . $fileName;
    if (file_put_contents($destPath, $bytes, LOCK_EX) === false) {
        throw new RuntimeException('Gagal menulis file backup ke storage.', 500);
    }

    $retention = rotateOldBackups($storageRoot, $prefix, $maxBackupFiles);

    echo json_encode([
        'ok' => true,
        'fileName' => $fileName,
        'deletedCount' => $retention['deletedCount'],
        'matchedCount' => $retention['matchedCount'],
        'keptCount' => $retention['keptCount'],
        'deletedFiles' => $retention['deletedFiles'],
    ]);
} catch (Throwable $exception) {
    $status = $exception->getCode();
    http_response_code(($status >= 400 && $status < 600) ? $status : 500);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ]);
}

function ensureStorageDir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }

    if (!mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Gagal membuat folder storage: ' . $dir, 500);
    }
}

function rotateOldBackups(string $dir, string $prefix, int $maxBackupFiles): array
{
    $pattern = $dir . DIRECTORY_SEPARATOR . $prefix . '*.zip';
    $files = glob($pattern) ?: [];

    usort($files, static function (string $a, string $b): int {
        return filemtime($b) <=> filemtime($a);
    });

    $deletedFiles = [];
    $deletedCount = 0;

    foreach (array_slice($files, $maxBackupFiles) as $oldFile) {
        if (@unlink($oldFile)) {
            $deletedFiles[] = basename($oldFile);
            $deletedCount++;
        } else {
            $deletedFiles[] = 'FAILED: ' . basename($oldFile);
        }
    }

    return [
        'deletedCount' => $deletedCount,
        'matchedCount' => count($files),
        'keptCount' => min(count($files), $maxBackupFiles),
        'deletedFiles' => $deletedFiles,
    ];
}
