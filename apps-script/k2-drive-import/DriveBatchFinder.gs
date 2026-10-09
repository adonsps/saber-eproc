var K2DriveBatchFinder = (function () {
  function discoverSourceFiles(config, slot, now, driveFiles) {
    var files = driveFiles || listDriveFiles(config.sourceFolderId);
    var result = {};
    var errors = [];
    [
      { dataset: 'pr', filename: config.filenames.pr },
      { dataset: 'catalog', filename: config.filenames.catalog },
      { dataset: 'price', filename: config.filenames.price }
    ].forEach(function (spec) {
      var selection = selectEligibleFile(files, spec.filename, config.sourceFolderId, slot, now, config.fileMinimumAgeMinutes);
      if (selection.error) errors.push({ dataset_type: spec.dataset, code: selection.error, candidates: selection.candidate_count });
      else result[spec.dataset] = Object.assign({ dataset_type: spec.dataset }, selection.file);
    });
    return { success: errors.length === 0, files: result, errors: errors };
  }

  function selectEligibleFile(files, filename, sourceFolderId, slot, now, minAgeMinutes) {
    var start = new Date(slot.arrival_start_utc.replace('.000000Z', '.000Z'));
    var end = new Date(slot.arrival_end_utc.replace('.000000Z', '.000Z'));
    var eligible = files.filter(function (file) {
      var modified = new Date(file.modifiedTime);
      return file.name === filename &&
        hasParent(file, sourceFolderId) &&
        modified >= start &&
        modified <= end &&
        ageMinutes(file, now) >= minAgeMinutes &&
        !file.processed;
    }).sort(function (a, b) {
      var byModified = new Date(b.modifiedTime).getTime() - new Date(a.modifiedTime).getTime();
      if (byModified !== 0) return byModified;
      return String(a.id).localeCompare(String(b.id));
    });
    if (eligible.length === 0) return { error: 'FILE_MISSING', candidate_count: 0 };
    if (eligible.length > 1 && new Date(eligible[0].modifiedTime).getTime() === new Date(eligible[1].modifiedTime).getTime()) {
      return { error: 'FILE_AMBIGUOUS', candidate_count: eligible.length };
    }
    return { file: eligible[0], candidate_count: eligible.length };
  }

  function checkFileStability(before, after, minAgeMinutes, now) {
    return before.id === after.id &&
      Number(before.size) === Number(after.size) &&
      new Date(before.modifiedTime).getTime() === new Date(after.modifiedTime).getTime() &&
      ageMinutes(after, now) >= minAgeMinutes;
  }

  function verifySelectedFilesStable(config, selectedFiles, now, rereadFile) {
    var intervalMs = Math.max(0, Math.min(10, config.fileStabilityIntervalSeconds || 0)) * 1000;
    if (intervalMs > 0 && !config.dryRun) {
      Utilities.sleep(intervalMs);
    }
    var errors = [];
    var stable = {};
    Object.keys(selectedFiles).forEach(function (datasetType) {
      var before = selectedFiles[datasetType];
      var after = rereadFile ? rereadFile(before) : rereadDriveRecord(before);
      if (!checkFileStability(before, after, config.fileMinimumAgeMinutes, now)) {
        errors.push({ dataset_type: datasetType, code: 'FILE_NOT_STABLE', drive_file_id: before.id });
      } else {
        stable[datasetType] = Object.assign({}, before, after);
      }
    });
    return { success: errors.length === 0, files: stable, errors: errors };
  }

  function metadataForFile(file, datasetType, slot, timezone, sha256) {
    return {
      dataset_type: datasetType,
      original_filename: file.name,
      drive_file_id: file.id,
      modified_timestamp: K2BatchIdentity.utcIso(file.modifiedTime),
      file_size_bytes: Number(file.size),
      sha256_content_hash: sha256,
      scheduled_slot_at_utc: slot.scheduled_slot_at_utc,
      schedule_timezone: timezone
    };
  }

  function listDriveFiles(folderId) {
    var folder = DriveApp.getFolderById(folderId);
    var iterator = folder.getFiles();
    var files = [];
    while (iterator.hasNext()) {
      var file = iterator.next();
      files.push(fileToRecord(file, folderId));
    }
    return files;
  }

  function fileToRecord(file, parentId) {
    return {
      id: file.getId(),
      name: file.getName(),
      size: file.getSize(),
      modifiedTime: file.getLastUpdated().toISOString(),
      parentIds: [parentId],
      file: file
    };
  }

  function rereadDriveRecord(record) {
    if (record.file && record.file.getId) {
      return fileToRecord(record.file, (record.parentIds || [])[0]);
    }
    var driveFile = DriveApp.getFileById(record.id);
    return {
      id: driveFile.getId(),
      name: driveFile.getName(),
      size: driveFile.getSize(),
      modifiedTime: driveFile.getLastUpdated().toISOString(),
      parentIds: record.parentIds || [],
      file: driveFile
    };
  }

  function readFileById(fileId, injectedFiles) {
    var injected = findInjectedFileById(fileId, injectedFiles);
    if (injected) return injected;
    return driveFileToRecord(DriveApp.getFileById(fileId));
  }

  function findInjectedFileById(fileId, injectedFiles) {
    if (!injectedFiles) return null;
    if (injectedFiles[fileId]) return injectedFiles[fileId];
    for (var i = 0; i < injectedFiles.length; i++) {
      if (String(injectedFiles[i].id) === String(fileId)) return injectedFiles[i];
    }
    return null;
  }

  function driveFileToRecord(driveFile) {
    var parentIds = [];
    if (driveFile.getParents) {
      var parents = driveFile.getParents();
      while (parents.hasNext()) {
        parentIds.push(parents.next().getId());
      }
    }
    return {
      id: driveFile.getId(),
      name: driveFile.getName(),
      size: driveFile.getSize(),
      modifiedTime: driveFile.getLastUpdated().toISOString(),
      parentIds: parentIds,
      file: driveFile
    };
  }

  function recordMatchesRegistered(record, registeredFile) {
    return String(record.id) === String(registeredFile.drive_file_id) &&
      String(record.name) === String(registeredFile.original_filename) &&
      Number(record.size) === Number(registeredFile.file_size_bytes) &&
      K2BatchIdentity.utcIso(record.modifiedTime) === K2BatchIdentity.utcIso(registeredFile.modified_timestamp);
  }

  function hasParent(file, folderId) {
    return (file.parentIds || []).indexOf(folderId) !== -1;
  }

  function ageMinutes(file, now) {
    return (now.getTime() - new Date(file.modifiedTime).getTime()) / 60000;
  }

  return {
    discoverSourceFiles: discoverSourceFiles,
    selectEligibleFile: selectEligibleFile,
    checkFileStability: checkFileStability,
    verifySelectedFilesStable: verifySelectedFilesStable,
    metadataForFile: metadataForFile,
    readFileById: readFileById,
    recordMatchesRegistered: recordMatchesRegistered,
    hasParent: hasParent,
    fileToRecord: fileToRecord
  };
})();
