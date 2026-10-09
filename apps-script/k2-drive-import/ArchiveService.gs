var K2ArchiveService = (function () {
  function canArchive(serverState) {
    return serverState &&
      (serverState.state === 'IMPORT_SUCCESS' ||
        (serverState.state === 'ARCHIVE_PENDING' && ['PENDING', 'PROCESSING', 'FAILED_RETRYABLE'].indexOf(serverState.archive_state) !== -1));
  }

  function moveRegisteredFiles(config, registeredFiles, recoveryStore, recoveryRecord, injected) {
    if (config.dryRun) return { success: true, dry_run: true, moved: [], failed: [] };
    K2Config.assertValidFor(config, 'archive');
    injected = injected || {};
    var archiveFolder = DriveApp.getFolderById(config.archiveFolderId);
    var moved = [];
    var failed = [];
    registeredFiles.forEach(function (file) {
      try {
        var record = K2DriveBatchFinder.readFileById(file.drive_file_id, injected.driveFiles);
        if (!K2DriveBatchFinder.recordMatchesRegistered(record, file)) {
          throw new Error('Registered Drive file metadata no longer matches.');
        }
        if (K2DriveBatchFinder.hasParent(record, config.archiveFolderId)) {
          moved.push({ dataset_type: file.dataset_type, drive_file_id: file.drive_file_id, filename: file.original_filename, already_archived: true });
          markArchived(recoveryStore, recoveryRecord, file.drive_file_id);
          return;
        }
        if (!K2DriveBatchFinder.hasParent(record, config.sourceFolderId)) {
          throw new Error('Registered Drive file is not in an acceptable source location.');
        }
        if (record.moveError) throw new Error(record.moveError);
        var driveFile = record.file || DriveApp.getFileById(file.drive_file_id);
        driveFile.moveTo(archiveFolder);
        if (record.parentIds) {
          record.parentIds = record.parentIds.filter(function (id) { return id !== config.sourceFolderId; });
          if (record.parentIds.indexOf(config.archiveFolderId) === -1) record.parentIds.push(config.archiveFolderId);
        }
        moved.push({ dataset_type: file.dataset_type, drive_file_id: file.drive_file_id, filename: file.original_filename });
        markArchived(recoveryStore, recoveryRecord, file.drive_file_id);
      } catch (e) {
        failed.push({ dataset_type: file.dataset_type, drive_file_id: file.drive_file_id, filename: file.original_filename, error: String(e.message || e) });
      }
    });
    return { success: failed.length === 0, moved: moved, failed: failed };
  }

  function reconcilePartialArchive(registeredFiles, movedIds) {
    var moved = {};
    (movedIds || []).forEach(function (id) { moved[id] = true; });
    return registeredFiles.filter(function (file) {
      return !moved[file.drive_file_id];
    });
  }

  function markArchived(recoveryStore, recoveryRecord, driveFileId) {
    if (!recoveryStore || !recoveryRecord) return;
    var ids = recoveryRecord.archive_completed_file_ids || [];
    if (ids.indexOf(driveFileId) === -1) ids.push(driveFileId);
    recoveryRecord.archive_completed_file_ids = ids;
    recoveryStore.update(recoveryRecord, { archive_completed_file_ids: ids });
  }

  return {
    canArchive: canArchive,
    moveRegisteredFiles: moveRegisteredFiles,
    reconcilePartialArchive: reconcilePartialArchive
  };
})();
