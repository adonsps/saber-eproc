// code untuk appscript. perlu dipindahkan ke appscript dan dideploy
const SECRET_TOKEN = '1ZjIrf4o_EjJR8-F7td7iYgId6wlw8Wuj';
const SCRIPT_VERSION = '2026-06-15-max-7-files-v2';

function doGet() {
  return jsonResponse({
    ok: true,
    version: SCRIPT_VERSION,
    status: 'Apps Script backup endpoint ready.',
  });
}

function doPost(e) {
  try {
    const data = JSON.parse(e.postData.contents || '{}');

    if (data.token !== SECRET_TOKEN) {
      return jsonResponse({
        ok: false,
        error: 'Unauthorized token.',
      });
    }

    const folder = DriveApp.getFolderById(data.folder_id);
    const bytes = Utilities.base64Decode(data.content_base64);
    const blob = Utilities.newBlob(
      bytes,
      data.mime_type || 'application/zip',
      data.file_name
    );

    const file = folder.createFile(blob);
    const retentionResult = deleteOldBackups(
      folder,
      data.backup_name_prefix || 'backup_website_',
      Number(data.retention_days || 3),
      Number(data.max_backup_files || 3)
    );

    return jsonResponse({
      ok: true,
      version: SCRIPT_VERSION,
      fileId: file.getId(),
      fileName: file.getName(),
      deletedCount: retentionResult.deletedCount,
      matchedCount: retentionResult.matchedCount,
      keptCount: retentionResult.keptCount,
      deletedFiles: retentionResult.deletedFiles,
    });
  } catch (error) {
    return jsonResponse({
      ok: false,
      error: String(error && error.message ? error.message : error),
    });
  }
}


function deleteOldBackups(folder, prefix, retentionDays, maxBackupFiles) {
  const cutoff = new Date();
  cutoff.setDate(cutoff.getDate() - retentionDays);

  let deletedCount = 0;
  const backupFiles = [];
  const deletedFiles = [];

  const files = folder.getFiles();

  while (files.hasNext()) {
    const file = files.next();

    if (!file.getName().startsWith(prefix)) {
      continue;
    }

    backupFiles.push(file);
  }

  backupFiles.sort(function (a, b) {
    return b.getDateCreated().getTime() - a.getDateCreated().getTime();
  });

  // hapus file setelah urutan ke-7
  for (let i = maxBackupFiles; i < backupFiles.length; i++) {
    const file = backupFiles[i];

    try {
      file.setTrashed(true);
      deletedFiles.push(file.getName());
      deletedCount++;
    } catch (err) {
      deletedFiles.push('FAILED: ' + file.getName() + ' | ' + err.message);
    }
  }

  return {
    deletedCount: deletedCount,
    matchedCount: backupFiles.length,
    keptCount: Math.min(backupFiles.length, maxBackupFiles),
    deletedFiles: deletedFiles
  };
}

function jsonResponse(data) {
  return ContentService
    .createTextOutput(JSON.stringify(data))
    .setMimeType(ContentService.MimeType.JSON);
}
