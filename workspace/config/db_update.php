<?php
require_once 'database.php';

echo "Starting Database Update...<br>";

try {
    // 1. Create master_vendor table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS master_vendor (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vp_number VARCHAR(100),
            vendor_code VARCHAR(100),
            description VARCHAR(255),
            account_group VARCHAR(100),
            p_org VARCHAR(100),
            tax_code VARCHAR(50),
            currency VARCHAR(10),
            ivp VARCHAR(100),
            line_of_business VARCHAR(255),
            top VARCHAR(100),
            email TEXT,
            exclusive_vendor VARCHAR(50),
            is_active BOOLEAN DEFAULT 1,
            modified_by VARCHAR(100),
            modified_date DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY(vendor_code, p_org),
            INDEX(description)
        )
    ");
    echo "Table master_vendor ensured.<br>";

    // Attempt to update the unique key if it was previously just vendor_code
    try {
        $pdo->exec("ALTER TABLE master_vendor DROP INDEX vendor_code, ADD UNIQUE KEY vendor_code_porg (vendor_code, p_org)");
        echo "Updated master_vendor unique key to include p_org.<br>";
    } catch (PDOException $e) {
        // Ignore if the old index doesn't exist or is already updated
    }

    // 2. Create quant_eval_config table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS quant_eval_config (
            id INT PRIMARY KEY DEFAULT 1,
            qualitative_weight_percent INT DEFAULT 50,
            otf_weight_percent INT DEFAULT 25,
            top_weight_percent INT DEFAULT 25,
            otf_score_3_max_days INT DEFAULT 0,
            otf_score_2_max_days INT DEFAULT 2,
            otf_score_1_min_days INT DEFAULT 3
        )
    ");
    echo "Table quant_eval_config ensured.<br>";

    // Initialize config if empty
    // Initialize config if empty
    $stmt = $pdo->query("SELECT COUNT(*) FROM quant_eval_config");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO quant_eval_config (id, qualitative_weight_percent, otf_weight_percent, top_weight_percent, otf_score_3_max_days, otf_score_2_max_days, otf_score_1_min_days) VALUES (1, 50, 25, 25, 0, 2, 3)");
        echo "Initialized quant_eval_config default values.<br>";
    } else {
        // Ensure the qualitative weight column exists if already created
        try {
            $pdo->exec("ALTER TABLE quant_eval_config ADD COLUMN qualitative_weight_percent INT DEFAULT 50 AFTER id");
            echo "Added qualitative_weight_percent to quant_eval_config.<br>";
        } catch (PDOException $e) {
            // Ignore if column already exists
        }
    }

    // 3. Create quant_top_scores table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS quant_top_scores (
            top_value VARCHAR(100) PRIMARY KEY,
            score INT DEFAULT 0
        )
    ");
    echo "Table quant_top_scores ensured.<br>";

} catch (PDOException $e) {
    echo "Error creating tables: " . $e->getMessage() . "<br>";
}

// Ensure the old PR columns exist
try {
    $pdo->exec("ALTER TABLE prs ADD COLUMN otf_score INT DEFAULT NULL, ADD COLUMN otf_status VARCHAR(50) DEFAULT NULL, ADD COLUMN top_score INT DEFAULT NULL");
    echo "Columns otf_score/otf_status/top_score added to prs successfully.<br>";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Columns otf_score/otf_status/top_score already exist (partially or fully).<br>";
        // Attempt to add top_score individually if otf columns already exist
        try {
            $pdo->exec("ALTER TABLE prs ADD COLUMN top_score INT DEFAULT NULL");
            echo "Column top_score added to prs successfully.<br>";
        } catch (PDOException $ex) {
            // Ignore if already exists
        }
    } else {
        echo "Warning during column addition: " . $e->getMessage() . "<br>";
    }
}

// Migrate team mapping to users table
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN team_name ENUM(
        'Corporate Services, General Supplies & Marketing',
        'Technology, Industrial, and General Services',
        'FNB and Operational Merchandise',
        'Admin Team',
        'Project Konstruksi Retail',
        'Project Konstruksi Properti',
        'Maintenance & Facility Management'
    ) DEFAULT NULL");
    echo "Added team_name column to users table.<br>";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        try {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN team_name ENUM(
                'Corporate Services, General Supplies & Marketing',
                'Technology, Industrial, and General Services',
                'FNB and Operational Merchandise',
                'Admin Team',
                'Project Konstruksi Retail',
                'Project Konstruksi Properti',
                'Maintenance & Facility Management'
            ) DEFAULT NULL");
            echo "Modified team_name column in users table to ENUM.<br>";
        } catch (PDOException $ex) {
            echo "Warning modifying team_name to ENUM: " . $ex->getMessage() . "<br>";
        }
    } else {
        echo "Warning adding team_name to users: " . $e->getMessage() . "<br>";
    }
}

try {
    // Migrate data from pic_team_mappings to users table
    $pdo->exec("
        UPDATE users u
        INNER JOIN pic_team_mappings m ON u.name = m.pic_name
        SET u.team_name = m.team_name
        WHERE u.team_name IS NULL OR u.team_name = ''
    ");
    echo "Migrated team names from pic_team_mappings to users table.<br>";
} catch (PDOException $e) {
    echo "Warning migrating team names: " . $e->getMessage() . "<br>";
}

try {
    $pdo->exec("ALTER TABLE users ADD COLUMN gp_officer_id VARCHAR(255) DEFAULT NULL");
    echo "Added gp_officer_id column to users table.<br>";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column gp_officer_id already exists in users table.<br>";
    } else {
        echo "Warning adding gp_officer_id to users: " . $e->getMessage() . "<br>";
    }
}

try {
    $pdo->exec("ALTER TABLE allowed_pgroups ADD COLUMN label ENUM('Team GP Project', 'Team GP Non Project') DEFAULT 'Team GP Non Project'");
    echo "Added label column to allowed_pgroups table.<br>";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column label already exists in allowed_pgroups table.<br>";
    } else {
        echo "Warning adding label to allowed_pgroups: " . $e->getMessage() . "<br>";
    }
}

try {
    $pdo->exec("ALTER TABLE allowed_pgroups ADD COLUMN team_name ENUM(
        'Corporate Services, General Supplies & Marketing',
        'Technology, Industrial, and General Services',
        'FNB and Operational Merchandise',
        'Admin Team',
        'Project Konstruksi Retail',
        'Project Konstruksi Properti',
        'Maintenance & Facility Management'
    ) DEFAULT NULL");
    echo "Added team_name column to allowed_pgroups table.<br>";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column team_name already exists in allowed_pgroups table.<br>";
    } else {
        echo "Warning adding team_name to allowed_pgroups: " . $e->getMessage() . "<br>";
    }
}

// Note: Recalculation logic has been moved to quant_eval.php and can be triggered from the UI.
echo "Done.";
