<?php
// config/database.php

$host = getenv('DB_HOST') ?: 'localhost';

// Detect environment based on server path or request URI
$is_qa    = (strpos(__DIR__, '/qa/') !== false || strpos($_SERVER['REQUEST_URI'] ?? '', '/qa/') !== false);
$is_local = (php_sapi_name() === 'cli-server');

if ($host === 'db') {
    // Local Docker Env
    $db   = 'u894129685_workspace';
    $user = 'u894129685_workspace007';
    $pass = 'Xcel007!';
} elseif ($is_local) {
    // Local XAMPP via php -S (cli-server)
    $host = '127.0.0.1';
    $db   = 'u894129685_workspace';
    $user = 'root';
    $pass = '';
} elseif ($is_qa) {
    // Hostinger QA Env
    $db   = 'u894129685_workspace_qa';
    $user = 'u894129685_workspace1';
    $pass = 'Xcel007!';
} else {
    // Hostinger Production Env
    $db   = 'u894129685_workspace';
    $user = 'u894129685_workspace007';
    $pass = 'Xcel007!';
} 
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Turn on strict error reporting
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Fetch data as associative arrays
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Enhance security against SQL injection
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // In production, you might want to log this error rather than display it
    die("Database connection failed: " . $e->getMessage());
}

// Shared connection to the VSS portal database (vendors, taxonomy, VSS user
// credentials). Callers should catch PDOException / Exception around calls,
// same as the inline connections this centralizes.
function get_vss_pdo(): PDO {
    $db_host = getenv('DB_HOST') ?: "localhost";
    if (php_sapi_name() === 'cli-server') {
        $vss_pdo = new PDO("mysql:host=127.0.0.1;dbname=u894129685_vmsportal;charset=utf8mb4", "root", "");
    } else {
        $vss_db_name = getenv('VSS_DB_NAME') ?: 'u894129685_vmsportal';
        $vss_db_user = getenv('VSS_DB_USER') ?: 'u894129685_adonisvm';
        $vss_db_pass = getenv('VSS_DB_PASSWORD') ?: 'Xcel007!';
        $vss_pdo = new PDO("mysql:host=$db_host;dbname=$vss_db_name;charset=utf8mb4", $vss_db_user, $vss_db_pass);
    }
    $vss_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $vss_pdo;
}

// Auto-migrate: create sku_dictionary for fresh environments.
// Operational dictionary rows are populated separately through the established import/manual workflow.
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sku_dictionary (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(255) NOT NULL,
            level_0 VARCHAR(150),
            level_1 VARCHAR(150),
            level_2 VARCHAR(150),
            level_3 VARCHAR(150),
            level_4 VARCHAR(255),
            mapping_status VARCHAR(50),
            group_owner VARCHAR(255),
            area VARCHAR(255),
            detail TEXT,
            uom VARCHAR(50),
            est_price DECIMAL(15,2),
            buying_price DECIMAL(15,2),
            hashtag VARCHAR(255),
            article_sap VARCHAR(255),
            validity_from VARCHAR(50),
            validity_to VARCHAR(50),
            max_delivery_time_days INT,
            min_qty INT,
            warranty VARCHAR(255),
            extend_to_group VARCHAR(255),
            is_reguler VARCHAR(10) DEFAULT 'TRUE',
            is_active VARCHAR(10) DEFAULT 'TRUE',
            auto_create_po VARCHAR(10) DEFAULT 'FALSE',
            modified_by VARCHAR(255),
            modified_date VARCHAR(50)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (\PDOException $e) {
    // Silently ignore table migration errors
}

// Auto-migrate: check and add missing columns in rfqs table
try {
    // Check parent_id
    $stmt = $pdo->query("SHOW COLUMNS FROM rfqs LIKE 'parent_id'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE rfqs ADD COLUMN parent_id INT DEFAULT NULL");
    }

    // Check round_number
    $stmt = $pdo->query("SHOW COLUMNS FROM rfqs LIKE 'round_number'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE rfqs ADD COLUMN round_number INT DEFAULT 1");
    }

    // Check rfq_type
    $stmt = $pdo->query("SHOW COLUMNS FROM rfqs LIKE 'rfq_type'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE rfqs ADD COLUMN rfq_type ENUM('Construction', 'Standard') DEFAULT 'Construction'");
    }

    // Auto-migrate team_name to VARCHAR(255) if it is an ENUM or shorter
    $pdo->exec("ALTER TABLE users MODIFY team_name VARCHAR(255)");

    // Auto-migrate VSS users role to VARCHAR(50)
    try {
        $vss_auto = get_vss_pdo();
        $vss_auto->exec("ALTER TABLE users MODIFY role VARCHAR(50)");
    } catch (\Exception $e) {}


} catch (\PDOException $e) {
    // Silently ignore schema migration errors
}

// Auto-migrate: check and create technical_team_pgroups table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS technical_team_pgroups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        team_name VARCHAR(255) NOT NULL,
        pgroup VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_mapping (team_name, pgroup)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\PDOException $e) {
    // Silently ignore
}

// Auto-migrate: check and create missing rfq_items and rfq_vendor_items tables
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS rfq_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rfq_id INT NOT NULL,
        ticket_no VARCHAR(50) NOT NULL,
        ticket_line_item VARCHAR(50) NOT NULL,
        item_description TEXT,
        qty DECIMAL(10,2) DEFAULT NULL,
        uom VARCHAR(50) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rfq_vendor_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rfq_vendor_id INT NOT NULL,
        rfq_item_id INT NOT NULL,
        price DECIMAL(15,2) DEFAULT NULL,
        lead_time VARCHAR(100) DEFAULT NULL,
        remarks TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\PDOException $e) {
    // Silently ignore table migration errors
}

// Auto-migrate: add final document columns to rfq_vendors
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM rfq_vendors LIKE 'final_cover_letter'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE rfq_vendors ADD COLUMN final_cover_letter VARCHAR(255) DEFAULT NULL");
        $pdo->exec("ALTER TABLE rfq_vendors ADD COLUMN final_boq VARCHAR(255) DEFAULT NULL");
        $pdo->exec("ALTER TABLE rfq_vendors ADD COLUMN final_supporting VARCHAR(255) DEFAULT NULL");
    }
} catch (\PDOException $e) {
    // Silently ignore
}

// Auto-migrate: add deadline-change-request columns to rfqs
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM rfqs LIKE 'pending_deadline'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE rfqs ADD COLUMN pending_deadline DATETIME DEFAULT NULL");
        $pdo->exec("ALTER TABLE rfqs ADD COLUMN deadline_request_by VARCHAR(100) DEFAULT NULL");
        $pdo->exec("ALTER TABLE rfqs ADD COLUMN deadline_request_status ENUM('PENDING', 'APPROVED', 'REJECTED') DEFAULT NULL");
    }
} catch (\PDOException $e) {
    // Silently ignore
}

// Auto-migrate: add mid-tender vendor-addition columns to rfq_vendors + approvals table
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM rfq_vendors LIKE 'addition_status'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE rfq_vendors ADD COLUMN addition_status ENUM('APPROVED', 'PENDING', 'REJECTED') DEFAULT 'APPROVED'");
        $pdo->exec("ALTER TABLE rfq_vendors ADD COLUMN addition_reason TEXT NULL");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS rfq_vendor_approvals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rfq_vendor_id INT NOT NULL,
        committee_username VARCHAR(255) NOT NULL,
        status ENUM('PENDING', 'APPROVED', 'REJECTED') DEFAULT 'PENDING',
        approved_at TIMESTAMP NULL,
        UNIQUE KEY uq_vendor_committee (rfq_vendor_id, committee_username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\PDOException $e) {
    // Silently ignore
}

// Auto-migrate: add description column to rfq_audit_logs
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM rfq_audit_logs LIKE 'description'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE rfq_audit_logs ADD COLUMN description TEXT NULL");
    }
} catch (\PDOException $e) {
    // Silently ignore
}

// Auto-migrate: check and add master_data columns for AI suggestions
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM master_data LIKE 'level_5'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE master_data ADD COLUMN level_5 VARCHAR(255) DEFAULT NULL");
        $pdo->exec("ALTER TABLE master_data ADD COLUMN status VARCHAR(50) DEFAULT 'active'");
        $pdo->exec("ALTER TABLE master_data ADD COLUMN ai_confidence INT DEFAULT NULL");
        $pdo->exec("ALTER TABLE master_data ADD COLUMN google_drive_link VARCHAR(255) DEFAULT NULL");
        $pdo->exec("ALTER TABLE master_data ADD COLUMN item_group_owner VARCHAR(255) DEFAULT NULL");
        
        // Drop the old unique key and add the new composite one
        try {
            $pdo->exec("ALTER TABLE master_data DROP INDEX level_4");
        } catch (\PDOException $e) {
            // Ignore if index doesn't exist
        }
        $pdo->exec("ALTER TABLE master_data ADD UNIQUE KEY unique_level4_5 (level_4, level_5)");
    }

    // Check and add columns for new features: brands, sku tracking, dates
    $stmt = $pdo->query("SHOW COLUMNS FROM master_data LIKE 'available_brands'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE master_data ADD COLUMN available_brands TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE master_data ADD COLUMN sku_number VARCHAR(255) DEFAULT NULL");
        $pdo->exec("ALTER TABLE master_data ADD COLUMN requested_item_name VARCHAR(255) DEFAULT NULL");
        $pdo->exec("ALTER TABLE master_data ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");
        $pdo->exec("ALTER TABLE master_data ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    }

    // Check and add columns for existing SKU similarity feature
    $stmt = $pdo->query("SHOW COLUMNS FROM master_data LIKE 'similar_sku_id'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE master_data ADD COLUMN similar_sku_id INT DEFAULT NULL");
        $pdo->exec("ALTER TABLE master_data ADD COLUMN similar_sku_difference VARCHAR(255) DEFAULT NULL");
        $pdo->exec("ALTER TABLE master_data ADD COLUMN ai_similar_skus_json TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE master_data ADD COLUMN pic_remarks TEXT DEFAULT NULL");
    }
    
    // Check and add column for user remarks similarity
    $stmt = $pdo->query("SHOW COLUMNS FROM master_data LIKE 'user_remarks_similarity'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE master_data ADD COLUMN user_remarks_similarity TEXT DEFAULT NULL");
    }

    // Check and add ticket_ids column: comma-separated PRS ticket_no list, set
    // only when a row is written by AI enrichment (md_enrich_master_data()), so
    // a reviewer can trace a pending taxonomy row back to its source ticket(s).
    $stmt = $pdo->query("SHOW COLUMNS FROM master_data LIKE 'ticket_ids'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE master_data ADD COLUMN ticket_ids TEXT DEFAULT NULL");
    }

    // Check and add level columns for sku_dictionary mapping
    $stmt = $pdo->query("SHOW COLUMNS FROM sku_dictionary LIKE 'mapping_status'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE sku_dictionary ADD COLUMN level_0 VARCHAR(150) AFTER sku");
        $pdo->exec("ALTER TABLE sku_dictionary ADD COLUMN level_1 VARCHAR(150) AFTER level_0");
        $pdo->exec("ALTER TABLE sku_dictionary ADD COLUMN level_2 VARCHAR(150) AFTER level_1");
        $pdo->exec("ALTER TABLE sku_dictionary ADD COLUMN level_3 VARCHAR(150) AFTER level_2");
        $pdo->exec("ALTER TABLE sku_dictionary ADD COLUMN level_4 VARCHAR(255) AFTER level_3");
        $pdo->exec("ALTER TABLE sku_dictionary ADD COLUMN mapping_status VARCHAR(50) AFTER level_4");
    }
    
    // Migrate existing TRUE/FALSE values to Yes/No for sku_dictionary
    $pdo->exec("UPDATE sku_dictionary SET is_reguler = 'Yes' WHERE UPPER(is_reguler) = 'TRUE'");
    $pdo->exec("UPDATE sku_dictionary SET is_reguler = 'No' WHERE UPPER(is_reguler) = 'FALSE'");
    $pdo->exec("UPDATE sku_dictionary SET is_active = 'Yes' WHERE UPPER(is_active) = 'TRUE'");
    $pdo->exec("UPDATE sku_dictionary SET is_active = 'No' WHERE UPPER(is_active) = 'FALSE'");
    $pdo->exec("UPDATE sku_dictionary SET auto_create_po = 'Yes' WHERE UPPER(auto_create_po) = 'TRUE'");
    $pdo->exec("UPDATE sku_dictionary SET auto_create_po = 'No' WHERE UPPER(auto_create_po) = 'FALSE'");
    
    // Check and add master_data_id to prs
    $stmt = $pdo->query("SHOW COLUMNS FROM prs LIKE 'master_data_id'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE prs ADD COLUMN master_data_id INT NULL AFTER assigned_pic");
    }

    // Check and add is_new_store to prs
    $stmt = $pdo->query("SHOW COLUMNS FROM prs LIKE 'is_new_store'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE prs ADD COLUMN is_new_store TINYINT(1) NULL DEFAULT NULL AFTER top_score");
    }

    // Check and add ca_taxonomy_level_2 to prs — Cost Avoidance's Project
    // "Type of Work" taxonomy (level_2), set per baseline group via the
    // save_ca_taxonomy action in pages/cost_avoidance.php.
    $stmt = $pdo->query("SHOW COLUMNS FROM prs LIKE 'ca_taxonomy_level_2'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE prs ADD COLUMN ca_taxonomy_level_2 VARCHAR(255) NULL DEFAULT NULL AFTER is_new_store");
    }

    // Check and add po_create_date to prs — stamped by excel_parser.php the
    // first time po_number goes from empty to non-empty on a line, so it
    // marks when a PO was first created for that ticket line, not when the
    // row itself was first imported.
    $stmt = $pdo->query("SHOW COLUMNS FROM prs LIKE 'po_create_date'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE prs ADD COLUMN po_create_date DATE NULL DEFAULT NULL AFTER po_number");
    }

    // Check and add canvassing_description to prs — the K2 export's CANVASSING
    // DESCRIPTION column (index 16), which was in the source file all along but
    // never imported. STT writes the work-type code ([PR]/[PP]/[PM]/[OS]) at the
    // start of it, and that code now drives the SLA target — see
    // sla_canvassing_rule_key() in includes/sla_rules.php.
    $stmt = $pdo->query("SHOW COLUMNS FROM prs LIKE 'canvassing_description'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE prs ADD COLUMN canvassing_description TEXT NULL DEFAULT NULL AFTER canvassing_date");
    }

    // Check and add ticket_helpdesk_no to prs — the K2 export's TICKET HELPDESK NO.
    // column (far right, index 39, added 2026-10). Free-form text, max ~20 chars
    // observed; nullable because older 39-column files don't carry it.
    $stmt = $pdo->query("SHOW COLUMNS FROM prs LIKE 'ticket_helpdesk_no'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE prs ADD COLUMN ticket_helpdesk_no VARCHAR(64) NULL DEFAULT NULL AFTER canvassing_description");
    }

    // Check and add last_updated_by / last_updated_date to prs — a database-only
    // edit trail, deliberately not surfaced anywhere in the UI. Every write path
    // into `prs` stamps them through includes/pr_audit_stamp.php, so "who changed
    // the PIC on this ticket, and when" can be answered by querying the table.
    // last_updated_date is Asia/Jakarta (generated in PHP, like created_at) —
    // the MySQL container itself runs on UTC.
    $stmt = $pdo->query("SHOW COLUMNS FROM prs LIKE 'last_updated_by'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE prs ADD COLUMN last_updated_by VARCHAR(100) NULL DEFAULT NULL AFTER ca_taxonomy_level_2");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM prs LIKE 'last_updated_date'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE prs ADD COLUMN last_updated_date DATETIME NULL DEFAULT NULL AFTER last_updated_by");
    }
} catch (\PDOException $e) {
    // Silently ignore schema migration errors
}

// Auto-migrate: seed system_settings.last_pr_upload_at once, so the
// dashboard "Last updated" label isn't "unavailable" before the first
// upload runs post-fix. excel_parser.php keeps this key current afterward
// on every completed upload run, regardless of insert/update/skip counts.
try {
    $stmt = $pdo->query("SELECT 1 FROM system_settings WHERE setting_key = 'last_pr_upload_at'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("INSERT INTO system_settings (setting_key, setting_value)
                     SELECT 'last_pr_upload_at', COALESCE(MAX(created_at), NOW()) FROM prs");
    }
} catch (\PDOException $e) {
    // Silently ignore schema migration errors
}

// Auto-migrate: guarantee master_data.id is AUTO_INCREMENT.
// Every path that creates a Master Data row -- the SKU suggestion form, the AI
// taxonomy fallback, and Master Data enrichment (includes/master_data_enrichment.php)
// -- inserts without an id and then reads lastInsertId(). On an environment
// restored from a dump that lost the attribute, the first such insert lands on
// id 0 and the next one dies on a duplicate primary key. Own try block so a
// failure here (e.g. an existing id 0) doesn't take the neighbouring
// migrations down with it.
try {
    $stmt = $pdo->query("
        SELECT EXTRA
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'master_data'
          AND COLUMN_NAME = 'id'
    ");
    // A plain column reports EXTRA as an empty string, so "no auto_increment"
    // has to be told apart from "no such column" by whether a row came back at
    // all -- not by the value being empty.
    $row = $stmt->fetch(PDO::FETCH_NUM);
    if ($row !== false && stripos((string)$row[0], 'auto_increment') === false) {
        $pdo->exec("ALTER TABLE master_data MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT");
    }
} catch (\PDOException $e) {
    error_log('Could not ensure master_data.id is AUTO_INCREMENT: ' . $e->getMessage());
}

// Auto-migrate: master_data audit columns (who created / last modified a row).
// created_at already existed; created_by, last_modified_by and last_modified_at
// are new. last_modified_at carries ON UPDATE CURRENT_TIMESTAMP so it stays
// correct even on a write path that forgets to stamp it.
//
// Rows that predate this feature are left with created_by/last_modified_by
// NULL -- nobody knows who wrote them, and a fake 'OLD_DATA' placeholder here
// previously caused confusion (it could land on genuinely new rows too,
// whenever this migration's first run lagged behind actual usage).
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM master_data LIKE 'created_by'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE master_data ADD COLUMN created_by VARCHAR(255) NULL DEFAULT NULL");
        $pdo->exec("ALTER TABLE master_data ADD COLUMN last_modified_by VARCHAR(255) NULL DEFAULT NULL AFTER created_by");
        $pdo->exec("ALTER TABLE master_data ADD COLUMN last_modified_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER last_modified_by");
    }
} catch (\PDOException $e) {
    error_log('Could not add master_data audit columns: ' . $e->getMessage());
}

// Auto-migrate: master_data_history — one row per applied INSERT / UPDATE on
// master_data, snapshotting the taxonomy columns as they stood after the write.
// DELETE is deliberately not recorded.
//
// master_data_id is INT, not BIGINT, because a foreign key has to match the type
// of the column it references and master_data.id is INT AUTO_INCREMENT.
// ON DELETE CASCADE, because the Reject flows in pages/master_data.php delete
// suggestion rows outright; a RESTRICT constraint would break them.
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS master_data_history (
            id BIGINT NOT NULL AUTO_INCREMENT,
            master_data_id INT NOT NULL,
            action ENUM('INSERT','UPDATE') NOT NULL,
            level_0 VARCHAR(150) DEFAULT NULL,
            level_1 VARCHAR(150) DEFAULT NULL,
            level_2 VARCHAR(150) DEFAULT NULL,
            level_3 VARCHAR(150) DEFAULT NULL,
            level_4 VARCHAR(255) DEFAULT NULL,
            level_5 VARCHAR(255) DEFAULT NULL,
            pic_names VARCHAR(255) DEFAULT NULL,
            created_by VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_mdh_master_data_id (master_data_id),
            KEY idx_mdh_created_at (created_at),
            CONSTRAINT fk_mdh_master_data FOREIGN KEY (master_data_id)
                REFERENCES master_data (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (\PDOException $e) {
    error_log('Could not create master_data_history: ' . $e->getMessage());
}

// Auto-migrate: indexes on prs for Manager Dashboard performance.
// Kept in its OWN try block (not folded into the master_data block above) so a
// failure in an unrelated migration there can never skip these — the dashboard
// depends on idx_created_at, and a teammate pulling this branch must get it on
// first request regardless of what else drifted in their DB. Each ADD INDEX is
// independently guarded and idempotent: once the index exists, re-running throws
// "Duplicate key name", which the inner catch swallows, so this is a no-op on
// every environment after the first request (local Docker and prod alike).
// Companion manual/reviewable copy: db_backups/prs_dashboard_indexes.sql.
try {
    try { $pdo->exec("ALTER TABLE prs ADD INDEX idx_assigned_pic (assigned_pic)"); } catch (\PDOException $e) {}
    try { $pdo->exec("ALTER TABLE prs ADD INDEX idx_status_ticket (status_ticket)"); } catch (\PDOException $e) {}
    try { $pdo->exec("ALTER TABLE prs ADD INDEX idx_pr_date (pr_date)"); } catch (\PDOException $e) {}
    try { $pdo->exec("ALTER TABLE prs ADD INDEX idx_p_group (p_group)"); } catch (\PDOException $e) {}
    // THE dashboard sort key: pages/ajax_dashboard_data.php ends every PR query
    // with "ORDER BY prs.created_at DESC". Without this index that sort filesorts
    // the whole table (20k+ rows) and the dashboard appears to hang. An ascending
    // index is scanned backwards for DESC, so plain (created_at) is sufficient.
    try { $pdo->exec("ALTER TABLE prs ADD INDEX idx_created_at (created_at)"); } catch (\PDOException $e) {}
    // SLA Report (pages/sla_report.php) server-side query keys: the main sort/filter
    // column is pr_date (TR Date Technical, the SLA start date — covered by idx_pr_date
    // above), plus the canvassing approval date that ends the working-days calc,
    // po_number for AON detection, and master_data_id (the taxonomy join key).
    // idx_tr_date_gp is kept for existing installs; the SLA clock no longer uses it.
    try { $pdo->exec("ALTER TABLE prs ADD INDEX idx_tr_date_gp (tr_date_gp)"); } catch (\PDOException $e) {}
    try { $pdo->exec("ALTER TABLE prs ADD INDEX idx_canvassing_date_approver_1 (canvassing_date_approver_1)"); } catch (\PDOException $e) {}
    try { $pdo->exec("ALTER TABLE prs ADD INDEX idx_po_number (po_number)"); } catch (\PDOException $e) {}
    try { $pdo->exec("ALTER TABLE prs ADD INDEX idx_master_data_id (master_data_id)"); } catch (\PDOException $e) {}
    // Cost Avoidance (pages/cost_avoidance.php) server-side query keys. Without
    // these the Savings Report / chart queries scan the whole table on every
    // request: EXPLAIN reported "type: ALL, key: NULL, rows: 30934" before, and
    // a range scan of 3 rows (report) / 2888 rows (baseline queue) after.
    // canvassing_date is the global date filter; baseline_price is what splits
    // priced Savings Report lines from the Input Baselines queue.
    try { $pdo->exec("ALTER TABLE prs ADD INDEX idx_canvassing_date (canvassing_date)"); } catch (\PDOException $e) {}
    try { $pdo->exec("ALTER TABLE prs ADD INDEX idx_baseline_price (baseline_price)"); } catch (\PDOException $e) {}
    // Vendor Evaluation v2 PO Worklist (pages/ajax_vendor_eval_v1_table.php):
    // its base query GROUPs BY po_number, vendor_name over the whole table.
    // idx_po_number alone only orders by po_number, so MySQL still has to scan
    // every row and filesort/temp-table the GROUP BY; a composite index lets it
    // read the group order straight off the index instead.
    try { $pdo->exec("ALTER TABLE prs ADD INDEX idx_po_vendor_group (po_number, vendor_name)"); } catch (\PDOException $e) {}
} catch (\PDOException $e) {
    // Silently ignore — prs table may not exist yet on a brand-new environment;
    // the indexes will be added on a later request once it does.
}

// Auto-migrate: index on purchases_2025 for the Cost Savings server-side
// rewrite. Every request builds the 2025 benchmark once — a GROUP BY over the
// whole table (see cs_benchmark_aggregate_sql() in includes/cost_savings_helper.php)
// — and EXPLAIN reported "type: ALL, key: NULL, rows: 36951" before this index.
// Covers every column that aggregate reads, so it can be served from the index
// without touching the rows. Companion manual copy: db_backups/cost_savings_indexes.sql.
try {
    try { $pdo->exec("ALTER TABLE purchases_2025 ADD INDEX idx_sku_uom_benchmark (sku, uom, p_org, qty, po_amount)"); } catch (\PDOException $e) {}
} catch (\PDOException $e) {
    // Silently ignore — purchases_2025 is optional (Cost Savings degrades to
    // "no 2025 match" without it); the index is added on a later request if the
    // table shows up.
}

// users.name is a JOIN key, not just a display column: the SLA report's 'pic' mapping
// mode joins "prs p LEFT JOIN users u ON p.assigned_pic = u.name" (includes/sla_helper.php).
// users only indexes id (PK) and username (UNIQUE), so that join has no usable index and
// degrades into a scan of users per prs row. Same idempotent guard style as the block above.
try {
    try { $pdo->exec("ALTER TABLE users ADD INDEX idx_name (name)"); } catch (\PDOException $e) {}
} catch (\PDOException $e) {
    // Silently ignore — users table may not exist yet on a brand-new environment.
}

// Auto-migrate: check and create existing_skus table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS existing_skus (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sku VARCHAR(255) NOT NULL,
        group_owner VARCHAR(255) DEFAULT NULL,
        area VARCHAR(255) DEFAULT NULL,
        detail TEXT DEFAULT NULL,
        uom VARCHAR(50) DEFAULT NULL,
        est_price DECIMAL(15,2) DEFAULT NULL,
        buying_price DECIMAL(15,2) DEFAULT NULL,
        hashtag VARCHAR(255) DEFAULT NULL,
        article_sap VARCHAR(100) DEFAULT NULL,
        validity_from DATE DEFAULT NULL,
        validity_to DATE DEFAULT NULL,
        max_delivery_time_days INT DEFAULT NULL,
        min_qty DECIMAL(10,2) DEFAULT NULL,
        warranty VARCHAR(255) DEFAULT NULL,
        extend_to_group VARCHAR(255) DEFAULT NULL,
        is_reguler TINYINT(1) DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        auto_create_po_based_on_vendor_contract TINYINT(1) DEFAULT NULL,
        modified_by VARCHAR(255) DEFAULT NULL,
        modified_date DATETIME DEFAULT NULL,
        FULLTEXT KEY idx_detail (detail)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\PDOException $e) {
    // Silently ignore table migration errors
}

// Auto-migrate: check and create ai_golden_rules table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_golden_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rule_text TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\PDOException $e) {
    // Silently ignore table migration errors
}

// Auto-migrate: guarantee ai_golden_rules.id is AUTO_INCREMENT.
// Both save paths (the Category Manager form in pages/master_data.php and the AI
// updater in pages/ajax_ai_golden_rule_update.php) clear the table and then
// INSERT without an id. On a copy restored from the MariaDB prod dump the column
// lost AUTO_INCREMENT (schema drift -- same as master_data.id above), so that
// INSERT dies with "Field 'id' doesn't have a default value" AFTER the clear has
// already happened, and the golden rules are silently destroyed. That is not
// hypothetical: it emptied this table locally on 2026-08-05.
// The clear is now a transactional DELETE rather than a TRUNCATE so a failed
// INSERT rolls back, but this migration removes the cause rather than the
// symptom. Idempotent: on a correct schema (e.g. prod) it is a no-op.
try {
    $stmt = $pdo->query("
        SELECT EXTRA
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'ai_golden_rules'
          AND COLUMN_NAME = 'id'
    ");
    // Same detection as master_data.id: a plain column reports EXTRA as an empty
    // string, so "no auto_increment" is told apart from "no such column" by
    // whether a row came back at all.
    $row = $stmt->fetch(PDO::FETCH_NUM);
    if ($row !== false && stripos((string)$row[0], 'auto_increment') === false) {
        try { $pdo->exec("ALTER TABLE ai_golden_rules ADD PRIMARY KEY (id)"); } catch (\PDOException $e) {}
        $pdo->exec("ALTER TABLE ai_golden_rules MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT");
    }
} catch (\PDOException $e) {
    error_log('Could not ensure ai_golden_rules.id is AUTO_INCREMENT: ' . $e->getMessage());
}

// Auto-migrate: create announcements and announcement_reads tables
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        title            VARCHAR(500)  NOT NULL,
        summary          TEXT          NOT NULL,
        content          LONGTEXT      NOT NULL,
        confidentiality  ENUM('EXTERNAL','INTERNAL_KLG','GP_ONLY') NOT NULL DEFAULT 'EXTERNAL',
        urgency          ENUM('URGENT','NOT_URGENT') NOT NULL DEFAULT 'NOT_URGENT',
        optional_link    VARCHAR(2048) DEFAULT NULL,
        created_by       INT           NOT NULL,
        attachment_path  VARCHAR(500)  DEFAULT NULL,
        attachment_name  VARCHAR(500)  DEFAULT NULL,
        attachment_mime  VARCHAR(100)  DEFAULT NULL,
        status           ENUM('DRAFT','PUBLISHED') NOT NULL DEFAULT 'DRAFT',
        is_active        TINYINT(1)    NOT NULL DEFAULT 1,
        view_count       INT           NOT NULL DEFAULT 0,
        created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_status_active (status, is_active),
        KEY idx_created_by (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS announcement_reads (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        announcement_id  INT NOT NULL,
        user_id          INT NOT NULL,
        read_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_read (announcement_id, user_id),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\PDOException $e) {
    // Silently ignore table migration errors
}

// Auto-migrate: repair the announcement tables' `id` column — copies imported
// into the Docker MySQL 8 local DB from the MariaDB prod dump lost the
// AUTO_INCREMENT on the primary key (schema drift). Under MySQL 8's strict mode
// that makes every INSERT fail with "Field 'id' doesn't have a default value"
// (creating an announcement, marking one read, adding an attachment).
// CREATE TABLE IF NOT EXISTS above can't fix an already-existing table, so patch
// the columns here. Idempotent: on a correct schema (e.g. prod) this is a no-op.
foreach (['announcements', 'announcement_reads', 'announcement_attachments'] as $annTable) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$annTable` LIKE 'id'");
        $col  = $stmt->fetch();
        if ($col && stripos($col['Extra'] ?? '', 'auto_increment') === false) {
            // id must be a key before it can be AUTO_INCREMENT; add the PK if the
            // drifted copy is missing it (harmless error if it already exists).
            try { $pdo->exec("ALTER TABLE `$annTable` ADD PRIMARY KEY (id)"); } catch (\PDOException $e) {}
            $pdo->exec("ALTER TABLE `$annTable` MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT");
        }
    } catch (\PDOException $e) {
        // Silently ignore schema migration errors
    }
}

// Auto-migrate: create announcement_attachments table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS announcement_attachments (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        announcement_id INT NOT NULL,
        path            VARCHAR(500) NOT NULL,
        name            VARCHAR(500) NOT NULL,
        mime            VARCHAR(100) NOT NULL,
        created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_announcement_id (announcement_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\PDOException $e) {
    // Silently ignore table migration errors
}

// Auto-migrate: add view_count column to announcements table
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM announcements LIKE 'view_count'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE announcements ADD COLUMN view_count INT NOT NULL DEFAULT 0");
    }
} catch (\PDOException $e) {
    // Silently ignore schema migration errors
}

// Auto-migrate: add wa_notified_at to announcements. Stamps when the WhatsApp
// group teaser was sent for a CONFIDENTIAL announcement so it fires exactly once
// on publish and never again on later edits/re-saves (see announcement_helper.php).
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM announcements LIKE 'wa_notified_at'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE announcements ADD COLUMN wa_notified_at DATETIME NULL DEFAULT NULL");
    }
} catch (\PDOException $e) {
    // Silently ignore schema migration errors
}

// Auto-migrate: rename the confidentiality options to the unambiguous labels
// GP asked for — PUBLIC -> EXTERNAL, RESTRICTED -> INTERNAL_KLG,
// CONFIDENTIAL -> GP_ONLY. Internal users kept mis-picking the old wording, so
// the stored values (not just the UI text) move with it and already-published
// announcements are converted in place.
//
// Three steps, because MySQL cannot rename an ENUM member while rows still hold
// the old one: widen the ENUM to accept both vocabularies, rewrite the rows,
// then narrow it to the new vocabulary only. Idempotent — once the column no
// longer mentions 'PUBLIC' the whole block is skipped.
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM announcements LIKE 'confidentiality'");
    $col  = $stmt->fetch();
    if ($col && stripos($col['Type'] ?? '', "'PUBLIC'") !== false) {
        $pdo->exec("ALTER TABLE announcements MODIFY COLUMN confidentiality
            ENUM('PUBLIC','RESTRICTED','CONFIDENTIAL','EXTERNAL','INTERNAL_KLG','GP_ONLY')
            NOT NULL DEFAULT 'EXTERNAL'");

        $pdo->exec("UPDATE announcements SET confidentiality = 'EXTERNAL'     WHERE confidentiality = 'PUBLIC'");
        $pdo->exec("UPDATE announcements SET confidentiality = 'INTERNAL_KLG' WHERE confidentiality = 'RESTRICTED'");
        $pdo->exec("UPDATE announcements SET confidentiality = 'GP_ONLY'      WHERE confidentiality = 'CONFIDENTIAL'");

        $pdo->exec("ALTER TABLE announcements MODIFY COLUMN confidentiality
            ENUM('EXTERNAL','INTERNAL_KLG','GP_ONLY') NOT NULL DEFAULT 'EXTERNAL'");
    }
} catch (\PDOException $e) {
    error_log('Could not migrate announcements.confidentiality to the new labels: ' . $e->getMessage());
}

// Auto-migrate: add create_announcement column to users table
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'create_announcement'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN create_announcement TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (\PDOException $e) {
    // Silently ignore schema migration errors
}

// Auto-migrate: create aon_skus table for the Master AON feature
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS aon_skus (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        sku_code       VARCHAR(100)  NOT NULL,
        item_name      VARCHAR(255)  NOT NULL,
        previous_item  VARCHAR(255)  DEFAULT NULL,
        category       VARCHAR(100)  DEFAULT NULL,
        vendor         VARCHAR(255)  DEFAULT NULL,
        quantity       DECIMAL(15,4) DEFAULT NULL,
        price          DECIMAL(15,2) DEFAULT NULL,
        price_2025     DECIMAL(15,2) DEFAULT NULL,
        effective_year SMALLINT      NOT NULL DEFAULT 2026,
        valid_from     DATE          DEFAULT NULL,
        valid_until    DATE          DEFAULT NULL,
        handler        VARCHAR(255)  DEFAULT NULL,
        status         VARCHAR(20)   NOT NULL DEFAULT 'ACTIVE',
        is_deleted     TINYINT(1)    NOT NULL DEFAULT 0,
        created_by     VARCHAR(255)  DEFAULT NULL,
        created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sku_code (sku_code),
        KEY idx_effective_year (effective_year),
        KEY idx_status_deleted (status, is_deleted)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\PDOException $e) {
    // Silently ignore table migration errors
}

// Auto-migrate: add new columns to aon_skus (used_qty, remain_qty, flag, flag_detail, alokasi)
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM aon_skus LIKE 'used_qty'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE aon_skus ADD COLUMN used_qty DECIMAL(15,4) DEFAULT NULL AFTER quantity");
        $pdo->exec("ALTER TABLE aon_skus ADD COLUMN remain_qty DECIMAL(15,4) DEFAULT NULL AFTER used_qty");
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM aon_skus LIKE 'flag'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE aon_skus ADD COLUMN flag VARCHAR(200) DEFAULT NULL");
        $pdo->exec("ALTER TABLE aon_skus ADD COLUMN flag_detail TEXT DEFAULT NULL");
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM aon_skus LIKE 'alokasi'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE aon_skus ADD COLUMN alokasi DECIMAL(15,4) DEFAULT NULL");
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM aon_skus LIKE 'updated_by'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE aon_skus ADD COLUMN updated_by VARCHAR(255) DEFAULT NULL AFTER created_by");
    }
} catch (\PDOException $e) {
    // Silently ignore
}

// Auto-migrate: add contract_no to aon_skus -- the contract ("No PPH") the SKU
// is bought under. Nullable with no default: the masterplan and K2 imports do
// not carry it, so it is filled in from Master AON's row editor, and every
// insert that does not name it has to keep working.
//
// The expiry reminder quotes it (includes/aon_reminder.php) so the STC group can
// identify the contract without opening Workspace; a SKU that has none is still
// reminded about, just without a number.
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM aon_skus LIKE 'contract_no'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE aon_skus ADD COLUMN contract_no VARCHAR(150) DEFAULT NULL AFTER vendor");
    }
} catch (\PDOException $e) {
    // Silently ignore
}

// Auto-migrate: restore alokasi once for rows nulled by a price CSV upload without ALOKASI column
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM aon_seeds WHERE name = 'fix_alokasi_null'");
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("UPDATE aon_skus SET alokasi = '1' WHERE is_deleted = 0 AND (alokasi IS NULL OR alokasi = '' OR alokasi = '0')");
        $pdo->exec("INSERT IGNORE INTO aon_seeds (name) VALUES ('fix_alokasi_null')");
    }
} catch (\PDOException $e) {
    // Silently ignore
}

// Auto-migrate: convert qty columns from DECIMAL to VARCHAR to preserve raw CSV values
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM aon_skus LIKE 'quantity'");
    $col = $stmt->fetch();
    if ($col && stripos($col['Type'], 'decimal') !== false) {
        $pdo->exec("ALTER TABLE aon_skus MODIFY COLUMN quantity VARCHAR(100) DEFAULT NULL");
        $pdo->exec("ALTER TABLE aon_skus MODIFY COLUMN used_qty VARCHAR(100) DEFAULT NULL");
        $pdo->exec("ALTER TABLE aon_skus MODIFY COLUMN remain_qty VARCHAR(100) DEFAULT NULL");
        $pdo->exec("ALTER TABLE aon_skus MODIFY COLUMN alokasi VARCHAR(100) DEFAULT NULL");
    }
} catch (\PDOException $e) {
    // Silently ignore
}

// Auto-migrate: widen previous_item to TEXT to support multiple 2025 SKU names
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM aon_skus LIKE 'previous_item'");
    $col = $stmt->fetch();
    if ($col && stripos($col['Type'], 'varchar') !== false) {
        $pdo->exec("ALTER TABLE aon_skus MODIFY COLUMN previous_item TEXT DEFAULT NULL");
    }
} catch (\PDOException $e) {
    // Silently ignore
}

// Auto-migrate: widen price_2025 to VARCHAR to support multiple pipe-delimited
// prices, aligned with previous_item, when a SKU was purchased under 2+
// distinct item names in purchases_2025 (2025 vs 2026 price comparison).
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM aon_skus LIKE 'price_2025'");
    $col = $stmt->fetch();
    if ($col && stripos($col['Type'], 'decimal') !== false) {
        $pdo->exec("ALTER TABLE aon_skus MODIFY COLUMN price_2025 VARCHAR(255) DEFAULT NULL");
    }
} catch (\PDOException $e) {
    // Silently ignore
}

// Auto-seed: masterplan 2026 — runs once, seeds aon_skus from the static CSV
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS aon_seeds (
        name       VARCHAR(100) PRIMARY KEY,
        seeded_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $already = (int)$pdo->query("SELECT COUNT(*) FROM aon_seeds WHERE name = 'masterplan_2026'")->fetchColumn();
    if ($already === 0) {
        $seed_csv = __DIR__ . '/../data/aon_masterplan_2026.csv';
        if (file_exists($seed_csv)) {
            require_once __DIR__ . '/../includes/aon_masterplan_parser.php';
            parse_and_insert_masterplan($seed_csv, 'system_seed');
            $pdo->exec("INSERT IGNORE INTO aon_seeds (name) VALUES ('masterplan_2026')");
        }
    }
} catch (\PDOException $e) {
    // Silently ignore — seed will retry on next request if it fails
}

// Auto-seed: AON contract numbers ("No PPH") — runs once, stamps
// aon_skus.contract_no from the STC contract register. The same 17 contracts,
// in the same order, are mirrored in db_backups/aon_contract_no_01_seed.sql for
// anyone applying by hand — keep the two in step if either changes.
//
// Vendor AND PIC must both match, which is how the register is read: the same
// vendor can sit under two STCs on two different contracts, so the vendor name
// alone is not an identity. A SKU nobody has claimed yet (handler NULL/'') is
// deliberately left empty — there is no PIC to attribute a contract to, and
// Master AON's row editor fills it in once someone claims the row.
//
// Vendor is matched on a distinctive substring rather than the full name: the
// register writes "PT Galva" where aon_skus stores "PT. GALVA TECHNOLOGIES",
// and one vendor is stored misspelled ("SINAR METRINDO PEKASA"). PIC is a
// prefix match — "Steinner" has to reach "Steinner Kiamidi", "Lily" has to
// reach "Lilyawati Widjaja".
//
// Only ever writes a contract_no that is still empty, so a correction typed
// into Master AON is never overwritten.
try {
    $already = (int)$pdo->query("SELECT COUNT(*) FROM aon_seeds WHERE name = 'aon_contract_no_2026'")->fetchColumn();
    if ($already === 0) {
        // [vendor key, PIC prefix, contract no]
        $aon_contracts = [
            ['%GALVA TECHNOLOGIES%',      'Steinner%', 'PPH/1306/GP/KLG/VII/2026'],
            ['%TOFFIN%',                  'Steinner%', 'PPH/1188/GP/KLG/VI/2026'],
            ['%SARANA MULIA SANTOSO%',    'Steinner%', 'PPH/1209/GP/KLG/VI/2026'],
            ['%METTA NOVALINA%',          'Steinner%', 'PPH/1023/GP/KLG/V/2026'],
            ['%BINTANG CITRA JAYA%',      'Steinner%', 'PPH/1021/GP/KLG/V/2026'],
            ['%INDOMETIC%',               'Steinner%', 'PPH/1022/GP/KLG/V/2026'],
            ['%INDIKA TIRTA MANDIRI%',    'Steinner%', 'PPH/1103/GP/KLG/VI/2026'],
            ['%FASCO SANWIRA%',           'Steinner%', 'PPH/1205/GP/KLG/VI/2026'],
            ['%WELLEN BROTHERS%',         'Lily%',     'PPH/1334/STC/KLG/VII/2026'],
            ['%BUMI MEDIA KREASINDO%',    'Lily%',     'PPH/1336/STC/KLG/VII/2026'],
            ['%SUGIH PERKASA%',           'Lily%',     'PPH/1098/STC/KLG/VI/2026'],
            ['%NUSANTARA MANDIRI GRAFIKA%','Lily%',    'PPH/1340/STC/KLG/VII/2026'],
            ['%BINO MITRA SEJATI%',       'Lily%',     'PPH/1362/STC/KLG/VII/2026'],
            ['%GUNUNG MADU%',             'Steinner%', 'PPH/985/PROJECT PROP/KLG/V/2026'],
            ['%HOSHIZAKI%',               'Steinner%', 'PPH/1024/GP/KLG/V/2026'],
            ['%INTIMUARA%',               'Steinner%', 'PPH/1001/PROJECT RETAIL/KLG/V/2026'],
            ['%SINAR METRIND%',           'Steinner%', 'PPH/982/PROJECT RETAIL/KLG/V/2026'],
        ];
        $upd = $pdo->prepare("UPDATE aon_skus SET contract_no = ?
                               WHERE is_deleted = 0
                                 AND (contract_no IS NULL OR contract_no = '')
                                 AND vendor LIKE ? AND handler LIKE ?");
        foreach ($aon_contracts as [$vendor_key, $pic_key, $contract_no]) {
            $upd->execute([$contract_no, $vendor_key, $pic_key]);
        }
        $pdo->exec("INSERT IGNORE INTO aon_seeds (name) VALUES ('aon_contract_no_2026')");
    }
} catch (\PDOException $e) {
    // Silently ignore — seed retries on the next request if it fails
}

// Auto-migrate: create aon_alert_reviews table — tracks STC's contract/MOU
// renewal follow-up per SKU flagged by the Repeat Purchase Alert feature.
// One row per SKU (upserted), not per alert instance.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS aon_alert_reviews (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        sku_code    VARCHAR(100) NOT NULL,
        is_checked  TINYINT(1)   NOT NULL DEFAULT 0,
        remarks     TEXT         DEFAULT NULL,
        checked_by  VARCHAR(255) DEFAULT NULL,
        checked_at  TIMESTAMP    NULL DEFAULT NULL,
        updated_by  VARCHAR(255) DEFAULT NULL,
        created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sku_code (sku_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\PDOException $e) {
    // Silently ignore table migration errors
}

// Auto-migrate: add review_status to aon_alert_reviews — replaces the plain
// Checked/Not-Checked flag with an explicit STC decision (On Review vs
// Ignore) for the Repeat Purchase Alert page. is_checked stays as the
// "has been actioned" flag (kept for the existing Show Unchecked Only
// filter); review_status records which of the two options was chosen.
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM aon_alert_reviews LIKE 'review_status'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE aon_alert_reviews ADD COLUMN review_status VARCHAR(20) DEFAULT NULL AFTER is_checked");
    }
} catch (\PDOException $e) {
    // Silently ignore schema migration errors
}

// Auto-migrate: create aon_reminder_log — one row per (SKU, stage) actually
// sent to the STC WhatsApp group, which is what stops the AON contract-expiry
// reminder from repeating itself (see includes/aon_reminder.php). The UNIQUE
// key is the dedup: T-2M and T-1M can each only ever land once per contract,
// while the weekly stage is keyed by ISO week so it lands at most once a week.
// Same shape as rfq_notifications_log, which does this job for RFQ deadlines.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS aon_reminder_log (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        sku_code     VARCHAR(100) NOT NULL,
        stage        VARCHAR(20)  NOT NULL,
        stage_key    VARCHAR(30)  NOT NULL DEFAULT '',
        valid_until  DATE         DEFAULT NULL,
        days_left    INT          DEFAULT NULL,
        -- 0 = the stage was never actually sent, it was overtaken by a later one
        -- (a contract already inside its final month when the feature went live
        -- must not fire a T-2M reminder afterwards). Recorded rather than sent so
        -- the log never claims a message went out that did not.
        was_sent     TINYINT(1)   NOT NULL DEFAULT 1,
        -- The scheduler own idea of the current date (Asia/Jakarta), which is
        -- what the weekly cadence is measured against. Distinct from sent_at: that is a
        -- DB-server write timestamp, and pacing a schedule off it would make the
        -- cadence depend on the DB's clock and timezone rather than the calendar
        -- the reminders are defined in.
        sent_on      DATE         DEFAULT NULL,
        sent_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sku_stage (sku_code, stage, stage_key),
        KEY idx_sent_at (sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\PDOException $e) {
    // Silently ignore table migration errors
}

// Auto-migrate: add was_sent to aon_reminder_log. Separate from the CREATE
// above because CREATE TABLE IF NOT EXISTS is a no-op on an environment that
// already built the table from the first version of this migration — the column
// only ever lands via an ALTER there.
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM aon_reminder_log LIKE 'was_sent'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE aon_reminder_log ADD COLUMN was_sent TINYINT(1) NOT NULL DEFAULT 1 AFTER days_left");
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM aon_reminder_log LIKE 'sent_on'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE aon_reminder_log ADD COLUMN sent_on DATE DEFAULT NULL AFTER was_sent");
        // Existing rows predate the column; the write date is the best available
        // stand-in and is what the old code was pacing off anyway.
        $pdo->exec("UPDATE aon_reminder_log SET sent_on = DATE(sent_at) WHERE sent_on IS NULL");
    }
} catch (\PDOException $e) {
    // Silently ignore schema migration errors
}

// Auto-migrate: create aon_reminder_optouts — the STC's "we are not extending
// this contract, stop reminding me" decision, with the mandatory remark that
// justifies it. One row per SKU (upserted); is_active = 0 is an un-ignore, kept
// as a row rather than deleted so who decided what, and when, survives.
// Deliberately NOT folded into aon_alert_reviews: that table's one row per SKU
// records a Repeat Purchase Alert decision, and overloading it would make one
// STC's answer to a different question silence this one.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS aon_reminder_optouts (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        sku_code     VARCHAR(100) NOT NULL,
        is_active    TINYINT(1)   NOT NULL DEFAULT 1,
        remarks      TEXT         DEFAULT NULL,
        ignored_by   VARCHAR(255) DEFAULT NULL,
        ignored_at   TIMESTAMP    NULL DEFAULT NULL,
        updated_by   VARCHAR(255) DEFAULT NULL,
        created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sku_code (sku_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\PDOException $e) {
    // Silently ignore table migration errors
}

// Auto-migrate: add status to aon_reminder_optouts — WHICH answer the STC gave
// the pop-up, now that it offers two: ON_PROGRESS (the renewal is already being
// worked on) or NOT_RENEWAL (the contract is being dropped, reason mandatory).
// Both stop the reminders, so is_active alone can no longer say what was decided.
// Nullable with no default on purpose: rows written before the two-button pop-up
// genuinely have no answer to record, and inventing one for them would put words
// in an STC's mouth. See includes/aon_reminder.php.
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM aon_reminder_optouts LIKE 'status'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE aon_reminder_optouts ADD COLUMN status VARCHAR(20) DEFAULT NULL AFTER is_active");
    }
} catch (\PDOException $e) {
    // Silently ignore schema migration errors
}

// Auto-migrate: ensure sku_dictionary.sku has a UNIQUE constraint for ON DUPLICATE KEY upserts
try {
    $stmt = $pdo->query("SHOW INDEX FROM sku_dictionary WHERE Key_name = 'uq_sku' AND Column_name = 'sku'");
    if ($stmt->rowCount() === 0) {
        // Deduplicate first: keep the highest-id row for each duplicate SKU
        $pdo->exec("DELETE d1 FROM sku_dictionary d1
                    INNER JOIN sku_dictionary d2 ON d1.sku = d2.sku AND d1.id < d2.id");
        $pdo->exec("ALTER TABLE sku_dictionary ADD UNIQUE KEY uq_sku (sku)");
    }
} catch (\PDOException $e) {
    // Silently ignore — upsert will fall back to a safe no-op insert attempt
}

// Auto-migrate: ensure sku_dictionary.detail has a FULLTEXT constraint for semantic/ecommerce search
try {
    $stmt = $pdo->query("SHOW INDEX FROM sku_dictionary WHERE Key_name = 'ft_detail' AND Column_name = 'detail'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE sku_dictionary ADD FULLTEXT KEY ft_detail (detail)");
    }
} catch (\PDOException $e) {
    // Silently ignore
}

// Auto-migrate: one-time backfill — recompute previous_item/price_2025 for
// existing aon_skus rows straight from purchases_2025 (per-item-name,
// vendor-resolved unit price — see aon_resolve_vendor_price() and
// aon_build_2025_pairs() in aon_helper.php). previous_item/price_2025 are
// only ever filled in by the AON CSV upload when currently blank (see
// aon_parser.php's ON DUPLICATE KEY UPDATE), so any SKU seeded earlier from
// the static masterplan CSV's own blended "Avg Price 2025" column — or from
// an even older blended-across-vendors calc — never gets corrected by a
// later upload. This runs once (per seed name below) to bring every SKU's
// stored 2025 price in line with what purchases_2025 actually shows,
// without changing the ongoing upsert precedence (so ordinary manual edits
// after this point are unaffected by future re-uploads, same as before).
// A 2025 name must never be shown without a real price behind it — when no
// purchases_2025 match is found, both are cleared rather than leaving a
// stale catalog-only name (see the matching fix in aon_parser.php).
//
// Re-runs under a new seed name whenever the arithmetic behind price_2025
// changes, so already-stored values are brought in line instead of being
// frozen at whatever the rules were on the day they were written. The current
// name covers netting PPN out of the FF**/FY**/DF** POrgs' gross PO amounts
// (aon_net_amount_sql()) — without it those SKUs kept a ~11%-inflated 2025
// baseline and every 2026-vs-2025 comparison built on it read wrong.
try {
    $seed_name = 'fix_price_2025_ex_ppn';
    $seed_check = $pdo->prepare("SELECT COUNT(*) FROM aon_seeds WHERE name = ?");
    $seed_check->execute([$seed_name]);
    $already = (int)$seed_check->fetchColumn();
    $has_purchases_2025 = $pdo->query("SHOW TABLES LIKE 'purchases_2025'")->rowCount() > 0;

    if ($already === 0 && $has_purchases_2025) {
        require_once __DIR__ . '/../includes/aon_helper.php';

        $raw_seed = [];
        $net_amount_seed = aon_net_amount_sql('');
        $r = $pdo->query(
            "SELECT sku, TRIM(item_description) AS item_name, TRIM(vendor_name) AS vendor_name,
                    SUM($net_amount_seed) AS amount, SUM(qty) AS qty
             FROM purchases_2025
             WHERE qty > 0 AND item_description IS NOT NULL AND TRIM(item_description) != ''
             GROUP BY sku, TRIM(item_description), TRIM(vendor_name)
             ORDER BY sku, TRIM(item_description), TRIM(vendor_name)"
        );
        foreach ($r as $row) {
            if ($row['sku'] === null || $row['sku'] === '') continue;
            $raw_seed[$row['sku']][$row['item_name']][$row['vendor_name']] = [
                'qty'    => (float)$row['qty'],
                'amount' => (float)$row['amount'],
            ];
        }

        $rows = $pdo->query(
            "SELECT a.id, a.sku_code, a.vendor, COALESCE(d.detail, a.item_name) AS ref_name
             FROM aon_skus a
             LEFT JOIN sku_dictionary d ON d.sku = a.sku_code
             WHERE a.is_deleted = 0"
        )->fetchAll(PDO::FETCH_ASSOC);

        $sku_vendor_seed = [];
        foreach ($rows as $row) $sku_vendor_seed[$row['sku_code']] = $row['vendor'];

        $resolved_seed = aon_resolve_vendor_price($raw_seed, $sku_vendor_seed);

        $by_sku_seed  = [];
        $by_name_seed = [];
        foreach ($resolved_seed as $sku => $names) {
            foreach ($names as $name => $price) {
                if ($price === null) continue;
                $entry = ['sku' => $sku, 'name' => $name, 'price' => $price];
                $by_sku_seed[$sku][] = $entry;
                $by_name_seed[strtoupper($name)][] = $entry;
            }
        }

        $upd = $pdo->prepare("UPDATE aon_skus SET previous_item = ?, price_2025 = ? WHERE id = ?");
        $seed_insert = $pdo->prepare("INSERT IGNORE INTO aon_seeds (name) VALUES (?)");

        // Non-destructive backfill: only rows with a complete, deterministic
        // 2025 result are updated. A SKU with no purchases_2025 match — or one
        // whose resolver yields an incomplete previous_item/price_2025 pair — is
        // left exactly as-is, so a masterplan-seeded price is never erased just
        // because that SKU wasn't purchased in 2025. Both fields are always
        // written together (never one alone), and a blank/NULL is never written
        // over an existing value. Wrapped in a transaction so the seed marker is
        // committed only if every safe update succeeds; a mid-run failure rolls
        // back and retries on the next request instead of half-completing. The
        // ORDER BY on the purchases_2025 query above makes the resolved
        // vendor/price selection and the multi-value concatenation order stable.
        $pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $pairs = aon_build_2025_pairs($row['sku_code'], $row['ref_name'], $by_sku_seed, $by_name_seed);

                if (count($pairs) > 1) {
                    $prev  = implode('|', array_column($pairs, 'name'));
                    $price = implode('|', array_map(
                        fn($p) => rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.'),
                        array_column($pairs, 'price')
                    ));
                } elseif (count($pairs) === 1) {
                    $prev  = $row['ref_name'];
                    $price = (string)$pairs[0]['price'];
                } else {
                    $prev  = null;
                    $price = null;
                }

                // Skip unless BOTH paired values are present. No match or an
                // incomplete pair => leave previous_item/price_2025 untouched.
                if ($prev === null || trim((string)$prev) === '' ||
                    $price === null || trim((string)$price) === '') {
                    continue;
                }

                $upd->execute([$prev, $price, $row['id']]);
            }

            $seed_insert->execute([$seed_name]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
} catch (\Throwable $e) {
    // Silently ignore — backfill will retry on next request if it fails
    // (any partial work was already rolled back inside the transaction above).
}

// Auto-migrate: check and create po_line_corrections table (Cost Savings Matrix)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS po_line_corrections (
        id                       INT AUTO_INCREMENT PRIMARY KEY,
        ticket_no                VARCHAR(50)   NOT NULL,
        ticket_line_item         VARCHAR(50)   NOT NULL,
        po_number                VARCHAR(255)  NOT NULL,
        sku                      VARCHAR(100)  NOT NULL,
        uom                      VARCHAR(50)   NOT NULL,
        correction_type          VARCHAR(160)  NOT NULL,
        old_team                 VARCHAR(50)   NULL,
        new_team                 VARCHAR(50)   NULL,
        old_benchmark_unit_price DECIMAL(15,2) NULL,
        new_benchmark_unit_price DECIMAL(15,2) NULL,
        old_current_unit_price   DECIMAL(15,2) NULL,
        new_current_unit_price   DECIMAL(15,2) NULL,
        old_uom                  VARCHAR(50)   NULL,
        new_uom_2025             VARCHAR(50)   NULL,
        new_uom_current          VARCHAR(50)   NULL,
        reason                   VARCHAR(500)  NULL,
        corrected_by             VARCHAR(255)  NULL,
        is_active                TINYINT(1)    NOT NULL DEFAULT 1,
        created_at               TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_line_active (ticket_no, ticket_line_item, po_number, is_active),
        KEY idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\PDOException $e) {
    // Silently ignore table migration errors
}

// Auto-migrate: create manual_cost_avoidance table (Manual Input Cost Avoidance —
// records of avoided cost NOT tied to any PR/PO; fully separate from `prs`).
// Full/final schema — `category` is VARCHAR(255) to match master_data.level_4 so
// the AI classifier can store the full level_4 category verbatim without hitting
// "Data too long for column 'category'" (SQLSTATE 22001).
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS manual_cost_avoidance (
        id                    INT AUTO_INCREMENT PRIMARY KEY,
        entry_date            DATE          NOT NULL,
        vendor_name           VARCHAR(255)  NOT NULL,
        item_description      VARCHAR(500)  NOT NULL,
        quantity              DECIMAL(15,4) NOT NULL DEFAULT 1,
        uom                   VARCHAR(50)   NULL,
        unit_price_before     DECIMAL(15,2) NULL,
        avoided_price         DECIMAL(15,2) NULL,
        total_cost_avoidance  DECIMAL(15,2) NOT NULL,
        currency              VARCHAR(5)    NOT NULL DEFAULT 'IDR',
        category              VARCHAR(255)  NULL,
        team                  VARCHAR(50)   NULL,
        remarks               TEXT          NULL,
        quotation_link        VARCHAR(500)  NULL,
        source_type           VARCHAR(20)   NOT NULL DEFAULT 'MANUAL',
        is_active             TINYINT(1)    NOT NULL DEFAULT 1,
        created_by            VARCHAR(255)  NULL,
        updated_by            VARCHAR(255)  NULL,
        created_at            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_entry_date (entry_date),
        KEY idx_active (is_active),
        KEY idx_category (category),
        KEY idx_team (team),
        CONSTRAINT fk_manual_ca_category
            FOREIGN KEY (category) REFERENCES master_data (level_4)
            ON UPDATE CASCADE
            ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\PDOException $e) {
    // Silently ignore table migration errors
}

// Auto-migrate: add GP-monitoring backfill columns to manual_cost_avoidance.
// tools/import_gp_monitoring_cost_avoidance.php loads legacy rows from the
// "Monitoring GP Project" workbook that are NOT already in `prs`. These columns
// let it store the source PO / canvassing number and the sheet's raw taxonomy
// label (J1/K1/I1...) WITHOUT touching the FK-constrained `category` column
// (which must reference master_data.level_4). All nullable -> MySQL 8.0
// strict-mode safe; existing insert/update/aggregate code ignores them.
// canvassing_no is the idempotency key for re-running the importer.
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM manual_cost_avoidance LIKE 'po_number'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE manual_cost_avoidance ADD COLUMN po_number VARCHAR(255) NULL AFTER quotation_link");
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM manual_cost_avoidance LIKE 'canvassing_no'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE manual_cost_avoidance ADD COLUMN canvassing_no VARCHAR(255) NULL AFTER po_number");
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM manual_cost_avoidance LIKE 'taxonomy_category'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE manual_cost_avoidance ADD COLUMN taxonomy_category VARCHAR(255) NULL AFTER category");
    }
    $stmt = $pdo->query("SHOW INDEX FROM manual_cost_avoidance WHERE Key_name = 'idx_canvassing_no'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE manual_cost_avoidance ADD INDEX idx_canvassing_no (canvassing_no)");
    }
} catch (\PDOException $e) {
    // Silently ignore schema migration errors
}

// Auto-migrate: create vendor_quotation_last_price table (Project Head
// Command Center — "Initial Quotation Price" captured during canvassing,
// before a PO exists to hold it). One row per canvas (scope='canvas', keyed
// by canvassing_no — used when a project negotiates one lump price across
// several services under the same canvassing, confirmed by Fabian 2026-08-13)
// or one row per line item (scope='item', keyed by ticket_no+ticket_line_item).
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS vendor_quotation_last_price (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        scope             ENUM('canvas','item') NOT NULL,
        canvassing_no     VARCHAR(255)  NULL,
        ticket_no         VARCHAR(50)   NULL,
        ticket_line_item  VARCHAR(50)   NULL,
        last_price        DECIMAL(18,2) NULL,
        gdrive_link       VARCHAR(500)  NULL,
        notes             TEXT          NULL,
        created_by        VARCHAR(255)  NULL,
        updated_by        VARCHAR(255)  NULL,
        created_at        TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at        TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_vqlp_canvas (canvassing_no),
        UNIQUE KEY uq_vqlp_item (ticket_no, ticket_line_item)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\PDOException $e) {
    // Silently ignore schema migration errors
}

// Auto-migrate: add currency to vendor_quotation_last_price (reuses the
// `currencies` table below via includes/currency_helper.php) so each captured
// price records which currency it was negotiated in, not just IDR.
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM vendor_quotation_last_price LIKE 'currency'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE vendor_quotation_last_price ADD COLUMN currency VARCHAR(5) NOT NULL DEFAULT 'IDR' AFTER last_price");
    }
} catch (\PDOException $e) {
    // Silently ignore schema migration errors
}

// Auto-migrate: per-row exchange-rate SNAPSHOT for Initial Quotation Price /
// Manual Cost Avoidance. currencies.rate_to_idr is a single overwritten
// "current" rate, so without this a baseline entered last month silently
// changes IDR value whenever the rate is edited. The user can edit the rate
// when entering a price; it is stored beside the original amount + currency.
// NULL = legacy row (or IDR) -> conversion falls back to the current rate, so
// nothing existing moves. Nullable -> MySQL 8.0 strict-mode safe.
foreach ([
    ['vendor_quotation_last_price', 'fx_rate',          'DECIMAL(18,4) NULL AFTER currency'],
    ['prs',                         'baseline_fx_rate', 'DECIMAL(18,4) NULL AFTER baseline_currency'],
    ['manual_cost_avoidance',       'fx_rate',          'DECIMAL(18,4) NULL AFTER currency'],
] as [$fxTable, $fxCol, $fxDef]) {
    // One try per column so a failure on one table never skips the others.
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM {$fxTable} LIKE '{$fxCol}'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE {$fxTable} ADD COLUMN {$fxCol} {$fxDef}");
        }
    } catch (\PDOException $e) {
        // Silently ignore schema migration errors
    }
}
unset($fxTable, $fxCol, $fxDef);

// Auto-migrate: edit history for Initial Quotation Price and Manual Cost
// Avoidance ("who changed what price, when"). Append-only; written by
// includes/cost_avoidance_history.php inside the same transaction as the save.
// entity_type: QUOTATION_PROJECT (entity_key = canvassing_no),
// QUOTATION_TICKET (entity_key = "ticket_no|line"), MANUAL (entity_key = id).
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cost_avoidance_edit_history (
        id            BIGINT AUTO_INCREMENT PRIMARY KEY,
        entity_type   VARCHAR(30)   NOT NULL,
        entity_key    VARCHAR(255)  NOT NULL,
        action        VARCHAR(10)   NOT NULL,
        old_price     DECIMAL(18,2) NULL,
        old_currency  VARCHAR(5)    NULL,
        old_fx_rate   DECIMAL(18,4) NULL,
        new_price     DECIMAL(18,2) NULL,
        new_currency  VARCHAR(5)    NULL,
        new_fx_rate   DECIMAL(18,4) NULL,
        detail        TEXT          NULL,
        source        VARCHAR(20)   NOT NULL DEFAULT 'MODAL',
        actor_id      INT           NULL,
        actor_name    VARCHAR(255)  NULL,
        created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_cah_entity (entity_type, entity_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\PDOException $e) {
    // Silently ignore schema migration errors
}

// Auto-migrate: create currencies table (Cost Avoidance multi-currency —
// includes/currency_helper.php reads this for every IDR conversion, replacing
// the old single system_settings.usd_exchange_rate key). Seeds IDR (base,
// always active) and USD (backfilled from the legacy usd_exchange_rate
// setting so existing figures don't move), plus SGD/EUR/CNY/JPY left
// INACTIVE with rate 0 until an admin configures a real rate and activates
// them from the Cost Avoidance Settings tab — never fabricate a starting
// rate for a currency nobody has confirmed yet.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS currencies (
        code         VARCHAR(5)    NOT NULL PRIMARY KEY,
        name         VARCHAR(50)   NOT NULL,
        symbol       VARCHAR(10)   NOT NULL,
        rate_to_idr  DECIMAL(18,4) NOT NULL DEFAULT 0,
        is_active    TINYINT(1)    NOT NULL DEFAULT 1,
        updated_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $legacy_usd_rate = (float)($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'usd_exchange_rate'")->fetchColumn() ?: 16000);

    $seedStmt = $pdo->prepare("INSERT IGNORE INTO currencies (code, name, symbol, rate_to_idr, is_active) VALUES (?, ?, ?, ?, ?)");
    $seedStmt->execute(['IDR', 'Indonesian Rupiah', 'Rp', 1, 1]);
    $seedStmt->execute(['USD', 'US Dollar', '$', $legacy_usd_rate, 1]);
    $seedStmt->execute(['SGD', 'Singapore Dollar', 'S$', 0, 0]);
    $seedStmt->execute(['EUR', 'Euro', "\xE2\x82\xAC", 0, 0]);
    $seedStmt->execute(['CNY', 'Chinese Yuan', 'CN' . "\xC2\xA5", 0, 0]);
    $seedStmt->execute(['JPY', 'Japanese Yen', "\xC2\xA5", 0, 0]);
} catch (\PDOException $e) {
    // Silently ignore table migration errors
}

// Auto-migrate: create rfq_notifications_log table for tracking cron notifications
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS rfq_notifications_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rfq_id INT NOT NULL,
        notification_type VARCHAR(50) NOT NULL,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY idx_unique_rfq_notif (rfq_id, notification_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\PDOException $e) {
    // Silently ignore table migration errors
}

// Auto-migrate: add is_active column to users
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_active'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
    }
} catch (\PDOException $e) {
    // Silently ignore schema migration errors
}

// Auto-migrate: add 'Viewer' (the read-only role) to the users.role ENUM.
//
// ADDITIVE, derived from the column's CURRENT definition — deliberately NOT a
// hardcoded ENUM list. The previous version of this block did spell the list out,
// and it had drifted badly: it named 8 values while the live column carried 11
// ('Project Design', 'Project Management' and 'Technical Department' were added
// through some other path and never made it back into this file). That was
// harmless only because its guard tested for 'STC', which the column already had,
// so the stale ALTER never fired. Re-pointing that guard at a new value would
// have armed it and silently dropped three live roles — Technical Department
// among them, which has its own dashboard.
//
// Reading the existing type and appending keeps this immune to that whole class
// of bug: whatever the column holds today survives, whoever added it.
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
    $col = $stmt->fetch();
    if ($col && stripos($col['Type'], "'Viewer'") === false
        && preg_match('/^enum\((.*)\)$/i', trim($col['Type']), $m)) {
        $null    = ($col['Null'] === 'YES') ? 'NULL' : 'NOT NULL';
        $default = ($col['Default'] !== null)
            ? " DEFAULT " . $pdo->quote($col['Default'])
            : (($col['Null'] === 'YES') ? ' DEFAULT NULL' : '');
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM({$m[1]}, 'Viewer') {$null}{$default}");
    }
} catch (\PDOException $e) {
    // Silently ignore schema migration errors
}

// Auto-migrate: create pr_admin_routing_rules table and seed initial rules
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pr_admin_routing_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rule_type VARCHAR(50) NOT NULL,
        rule_value VARCHAR(255) NOT NULL,
        pic_name VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $stmt = $pdo->query("SELECT COUNT(*) FROM pr_admin_routing_rules");
    if ((int)$stmt->fetchColumn() === 0) {
        $seed = [
            ['PORG', 'FF30', 'Felanny Lieke'],
            ['PORG', 'FY30', 'Felanny Lieke'],
            ['PORG', 'II30', 'Felanny Lieke'],
            ['PORG', 'IQ30', 'Felanny Lieke'],
            ['PORG', 'IK30', 'Felanny Lieke'],
            ['PORG', 'DF30', 'Felanny Lieke'],
            ['PGROUP', 'PAC', 'Felanny Lieke'],
            ['PGROUP', 'PAF', 'Felanny Lieke'],
            ['PGROUP', 'PAO', 'Felanny Lieke'],
            ['PGROUP', 'PAR', 'Felanny Lieke'],
            ['PGROUP', 'PAU', 'Felanny Lieke'],
            ['PGROUP', 'PCF', 'Felanny Lieke'],
            ['PGROUP', 'PIN', 'Felanny Lieke'],
            ['PGROUP', 'PIV', 'Felanny Lieke'],
            ['PGROUP', 'P66', 'Felanny Lieke'],
            ['PGROUP', 'P90', 'Felanny Lieke'],
            ['PGROUP', 'P99', 'Felanny Lieke'],
            ['PGROUP_LABEL', 'Team GP Project', 'Ridwan Arifin'],
            ['PGROUP_LABEL', 'Team GP Non Project', 'Agustriani Utami']
        ];
        $insert = $pdo->prepare("INSERT INTO pr_admin_routing_rules (rule_type, rule_value, pic_name) VALUES (?, ?, ?)");
        foreach ($seed as $row) {
            $insert->execute($row);
        }
    }
} catch (\PDOException $e) {
    // Silently ignore migration errors
}

// Auto-migrate: SLA REVISION WORKFLOW — the PIC request / Manager approval
// flow on SLA Tracking (pages/sla_report.php, including its approvals modal).
// Mirrors db_backups/sla_revision_workflow_01_schema.sql, which carries the
// full rationale; keep the two in sync.
//
// Why the approved values live in their OWN table instead of being written
// back into `prs`: the PR Excel import upserts every line with
// `ON DUPLICATE KEY UPDATE ... pr_date = VALUES(pr_date),
//  canvassing_date_approver_1 = VALUES(canvassing_date_approver_1)`
// (includes/excel_parser.php ~line 343), so an approved date adjustment
// written into `prs` would be silently reverted by the next upload. The
// override is layered on top of the raw row at computation time inside
// get_sla_computed_rows() (includes/sla_helper.php) instead, which also keeps
// the untouched original in `prs` for the audit trail to compare against.
try {
    // Rename from the old "adjustment" naming to the feature's name, SLA Revision.
    //
    // MUST run before the CREATE statements below. If those went first they would
    // create empty tables under the new names, the rename would then find its
    // target already taken and skip, and every existing request would be stranded
    // in a table nothing reads anymore.
    //
    // Idempotent by construction: it only fires when the old name still exists AND
    // the new one does not, so a second run — or a fresh install that never had
    // the old names — does nothing.
    $tblStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables
                              WHERE table_schema = DATABASE() AND table_name = ?");
    foreach ([
        'sla_adjustment_requests' => 'sla_revision_requests',
        'sla_line_overrides'      => 'sla_revision_overrides',
    ] as $old => $new) {
        $tblStmt->execute([$old]); $has_old = (int)$tblStmt->fetchColumn();
        $tblStmt->execute([$new]); $has_new = (int)$tblStmt->fetchColumn();
        if ($has_old && !$has_new) $pdo->exec("RENAME TABLE `$old` TO `$new`");
    }

    // The indexes carried the old prefix too, so a table that arrived via the rename
    // above has them under the old name and needs the new one added. ADD only when
    // MISSING: this file is included by EVERY request, and an unconditional
    // DROP + ADD re-wrote all four indexes on each one — seconds of ALTER TABLE per
    // page load, and concurrent requests contending on the same metadata lock. The
    // old-named index is left in place; an index name is invisible to queries, so a
    // stale duplicate label costs nothing next to rebuilding on every hit.
    //
    // Guarded on the table existing: on a fresh install it does not yet (the CREATE
    // below makes it, indexes included), and an ALTER against a missing table throws
    // — which the outer catch would swallow together with the CREATE statements that
    // follow, leaving the feature with no tables at all.
    $tblStmt->execute(['sla_revision_requests']);
    if ((int)$tblStmt->fetchColumn()) {
        $idxStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics
                                  WHERE table_schema = DATABASE() AND table_name = 'sla_revision_requests'
                                    AND index_name = ?");
        foreach ([
            'line'      => '(ticket_no, ticket_line_item)',
            'approver'  => '(approver_username, status)',
            'status'    => '(status)',
            'requester' => '(requested_by_username)',
        ] as $ix => $cols) {
            $idxStmt->execute(['idx_sla_rev_' . $ix]);
            if ((int)$idxStmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE sla_revision_requests ADD INDEX idx_sla_rev_$ix $cols");
            }
        }
    }

    // Permanent audit trail. Append-only: one INSERT on submit, one UPDATE on
    // decision. Never deleted, never overwritten — a re-submission for the
    // same PR line is a new row, so prior rejections survive forever.
    // request_type: 'date' carries BOTH dates in one request (either or both may
    // be filled); 'sla_status' uses old_value/requested_value. 'pr_date' and
    // 'canvassing_date' are legacy — one date per request, superseded by 'date'.
    // They stay in the ENUM (and reason/evidence_text/additional_notes stay as
    // nullable columns) because the audit trail is never rewritten: old rows must
    // remain readable exactly as they were submitted.
    $pdo->exec("CREATE TABLE IF NOT EXISTS sla_revision_requests (
        id                    INT AUTO_INCREMENT PRIMARY KEY,
        ticket_no             VARCHAR(50)  NOT NULL,
        ticket_line_item      VARCHAR(50)  NOT NULL,
        request_type          ENUM('date','sla_status','pr_date','canvassing_date') NOT NULL,
        old_value             VARCHAR(50)  NULL,
        requested_value       VARCHAR(50)  NULL,
        old_pr_date               DATE     NULL,
        old_canvassing_date       DATE     NULL,
        requested_pr_date         DATE     NULL,
        requested_canvassing_date DATE     NULL,
        remarks               TEXT         NULL,
        reason                TEXT         NULL,
        evidence_text         TEXT         NULL,
        evidence_url          VARCHAR(500) NULL,
        additional_notes      TEXT         NULL,
        requested_by_user_id  INT          NOT NULL,
        requested_by_name     VARCHAR(100) NOT NULL,
        requested_by_username VARCHAR(50)  NOT NULL,
        approver_username     VARCHAR(255) NULL,
        approver_name         VARCHAR(100) NULL,
        status                ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        decided_by_user_id    INT          NULL,
        decided_by_name       VARCHAR(100) NULL,
        decided_at            DATETIME     NULL,
        decision_notes        TEXT         NULL,
        created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sla_rev_line      (ticket_no, ticket_line_item),
        INDEX idx_sla_rev_approver  (approver_username, status),
        INDEX idx_sla_rev_status    (status),
        INDEX idx_sla_rev_requester (requested_by_username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Currently active effective values. Derived — rebuildable at any time by
    // replaying the approved requests above. Written ONLY by the approval path.
    $pdo->exec("CREATE TABLE IF NOT EXISTS sla_revision_overrides (
        ticket_no                VARCHAR(50)  NOT NULL,
        ticket_line_item         VARCHAR(50)  NOT NULL,
        override_pr_date         DATE         NULL,
        override_canvassing_date DATE         NULL,
        override_sla_status      VARCHAR(30)  NULL,
        last_request_id          INT          NULL,
        approved_by_name         VARCHAR(100) NULL,
        approved_at              DATETIME     NULL,
        updated_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (ticket_no, ticket_line_item)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\PDOException $e) {
    // Silently ignore table migration errors
}

// Auto-migrate: contract_maintenance -- Facility/Property team's service &
// maintenance contract tracker (gas supply, HVAC, fire fighting, lift/escalator
// maintenance, cleaning, etc.), replacing a manual spreadsheet. business_unit,
// contract_type, item_category, output_type and site_area are app-validated
// dropdown strings, not FKs -- this codebase has no master table for any of them.
// output_type can hold 'AON', which is unrelated to the separate aon_skus/
// Always-On feature elsewhere in this file -- same abbreviation, different program.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS contract_maintenance (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        item_category     VARCHAR(100)  NOT NULL,
        description       VARCHAR(255)  DEFAULT NULL,
        supplier          VARCHAR(255)  DEFAULT NULL,
        contract_type     VARCHAR(10)   NOT NULL,
        business_unit     VARCHAR(500)  NOT NULL,
        site_area         VARCHAR(1000) DEFAULT NULL,
        contract_end_date DATE          DEFAULT NULL,
        output_type       VARCHAR(10)   DEFAULT NULL,
        output_end_date   DATE          DEFAULT NULL,
        docs_reference    VARCHAR(255)  DEFAULT NULL,
        gdrive_link       VARCHAR(500)  DEFAULT NULL,
        remarks           TEXT          DEFAULT NULL,
        is_deleted        TINYINT(1)    NOT NULL DEFAULT 0,
        created_by        VARCHAR(100)  DEFAULT NULL,
        updated_by        VARCHAR(100)  DEFAULT NULL,
        created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_business_unit (business_unit),
        KEY idx_contract_end_date (contract_end_date),
        KEY idx_output_end_date (output_end_date),
        KEY idx_is_deleted (is_deleted)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\PDOException $e) {
    // Silently ignore table migration errors
}

// Auto-migrate: widen contract_maintenance.business_unit and .site_area
// (2026-09-11) -- both became multi-select in the Add/Edit Contract modal
// (comma-separated values in the same free-text column, matching this
// table's existing non-FK'd style; see contract_maintenance_helper.php).
// VARCHAR(500)/VARCHAR(1000) chosen to comfortably hold several joined
// values while staying within InnoDB's index key-length limit for
// idx_business_unit under utf8mb4 (500*4=2000 bytes < 3072 byte limit).
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM contract_maintenance LIKE 'business_unit'");
    $col = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($col && strtolower($col['Type']) !== 'varchar(500)') {
        $pdo->exec("ALTER TABLE contract_maintenance MODIFY COLUMN business_unit VARCHAR(500) NOT NULL");
    }
} catch (\PDOException $e) {
    // Silently ignore table migration errors
}
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM contract_maintenance LIKE 'site_area'");
    $col = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($col && strtolower($col['Type']) !== 'varchar(1000)') {
        $pdo->exec("ALTER TABLE contract_maintenance MODIFY COLUMN site_area VARCHAR(1000) DEFAULT NULL");
    }
} catch (\PDOException $e) {
    // Silently ignore table migration errors
}

// Auto-migrate: drop contract_maintenance.company_name (2026-09-09). The
// separate "Company" field it backed is gone -- the source spreadsheet this
// feature replaced never had a distinct company column, its BU column mixed
// plain divisions with legal-entity/brand names, so that's now one merged
// business_unit selection list (see contract_maintenance_business_units())
// instead of two fields writing to two columns.
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM contract_maintenance LIKE 'company_name'");
    if ($stmt->rowCount() > 0) {
        $pdo->exec("ALTER TABLE contract_maintenance DROP COLUMN company_name");
    }
} catch (\PDOException $e) {
    // Silently ignore
}

// Auto-migrate: widen vendor_eval_submissions.score_band to include 'E'
// (Sangat Kurang). The original ENUM('A','B','C','D') couldn't represent the
// business's actual 5-band grading rubric (used e.g. in the "Contractor
// Evaluation Form (Retail)" legacy Google Form), which silently forced any
// E-grade evaluation up to C or D on import -- see the 2026-09-17 vendor-eval
// legacy reimport (db_backups/vendor_eval_v2_08_legacy_retail_2026_reimport.sql).
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM vendor_eval_submissions LIKE 'score_band'");
    $col = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($col && strpos($col['Type'], "'E'") === false) {
        $pdo->exec("ALTER TABLE vendor_eval_submissions MODIFY score_band ENUM('A','B','C','D','E') NULL");
    }
} catch (\PDOException $e) {
    // Silently ignore -- table may not exist yet on a brand-new environment
}

// Auto-migrate: contract_maintenance_reminder_log -- audit/dedup trail for the
// WhatsApp reminder feature (manual multi-select "Shoot Reminder" button, and
// the auto cron that fires once per contract PER STAGE at 3, 2 and 1 calendar
// month(s) before expiry). No UNIQUE constraint: a manual send is a
// deliberate, repeatable action (someone may remind the group again later
// about the same contract), so only the auto cron path enforces "once per
// (contract, stage)" itself via a NOT EXISTS check against this table -- see
// includes/contract_maintenance_reminder.php.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS contract_maintenance_reminder_log (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        contract_id  INT          NOT NULL,
        stage        VARCHAR(20)  NOT NULL,
        sent_by      VARCHAR(100) DEFAULT NULL,
        created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_contract_stage (contract_id, stage)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\PDOException $e) {
    // Silently ignore table migration errors
}
?>
