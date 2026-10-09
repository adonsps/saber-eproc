<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

$projectRoot = realpath(__DIR__ . '/..');

if ($projectRoot === false) {
    throw new RuntimeException('Project root tidak ditemukan.');
}

return [
    // URL endpoint receiver di VPS (backups/receive_backup.php). Diisi lewat .env.
    'remote_receiver_url' => getenv('VSS_BACKUP_RECEIVER_URL') ?: '',
    // Shared secret yang harus cocok dengan RECEIVER_SECRET di .env sisi VPS.
    'remote_receiver_secret' => getenv('VSS_BACKUP_RECEIVER_SECRET') ?: '',

    'project_root' => $projectRoot,
    'backup_name_prefix' => 'backup_website_',
    'retention_days' => 3,
    'max_backup_files' => 3,

    'paths_to_backup' => [
        'uploads',
    ],

    /*
     * SQL dump yang sudah ada akan dimasukkan ke ZIP.
     */
    'include_existing_sql_dumps' => false,
    'sql_dump_dir' => $projectRoot . '/db_backups',
    'sql_dump_pattern' => '*.sql',

    /*
     * Live database dump dari database yang sedang berjalan.
     * Credential diambil dari config aplikasi yang sudah ada.
     */
    'include_live_database_dump' => true,
    'live_database_sources' => [
        [
            'label' => 'vss',
            'config_file' => $projectRoot . '/config.php',
            'dump_file_prefix' => 'vss_live_database_',
        ],
        [
            'label' => 'workspace',
            'config_file' => $projectRoot . '/workspace/config/database.php',
            'dump_file_prefix' => 'workspace_live_database_',
        ],
    ],

    'local_backup_dir' => __DIR__ . '/storage',
    'use_system_temp_zip' => true,
    'delete_temp_zip_on_failure' => true,
    'log_file' => __DIR__ . '/logs/backup.log',

    'exclude_names' => [
        '.git',
        '.svn',
        'node_modules',
        'storage',
        'logs',
    ],

    'delete_local_zip_after_upload' => true,
];
