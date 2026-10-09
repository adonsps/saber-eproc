-- Phase A migration/seed for GP Project PGroup assignment.
-- Review before running. This file is intentionally not auto-executed by the app.
-- Project detection rule: active p_group exists in project_pgroups.
-- Resolved mapping decisions: D97/GLM/MRU/I84 are Group 2; P61 is Group 3.
-- K2-only Project New Store warning PGroups are intentionally omitted from all seeds.

-- PRECHECK: inspect this result before running the seed sections.
-- Any row with precheck_status = 'MISSING' must be resolved before continuing.
SELECT
    expected.expected_use,
    expected.expected_name,
    expected.username,
    u.id AS matched_user_id,
    u.name AS matched_user_name,
    u.role,
    u.manager_username,
    CASE WHEN u.id IS NULL THEN 'MISSING' ELSE 'OK' END AS precheck_status
FROM (
    SELECT 'Project Head Group 1/3' AS expected_use, 'Rio Tandy Darmawan' AS expected_name, 'rio.tandy' AS username
    UNION ALL SELECT 'Project Head Group 2', 'Fabian Adhinata Suhalim', 'fabian.suhalim'
    UNION ALL SELECT 'Group 1 Officer', 'William', 'william.pcr'
    UNION ALL SELECT 'Group 1 Officer', 'Gary Nata Permana', 'gary.permana'
    UNION ALL SELECT 'Group 1 Officer', 'Michael', 'michael.pcr'
    UNION ALL SELECT 'Group 1 Officer', 'Irsantya Lamara Putrilia S', 'irsantya.putrilia'
    UNION ALL SELECT 'Group 2 Officer', 'Hindra Jaya Zefran', 'hindra.zefran'
    UNION ALL SELECT 'Group 2 Officer', 'Rius Wintan', 'rius.wintan'
    UNION ALL SELECT 'Group 2 Officer', 'Theodore Seamditia', 'theodore.seamditia'
    UNION ALL SELECT 'Group 3 Officer', 'Josia Marxalim', 'josia.marxalim'
    UNION ALL SELECT 'Group 3 Officer', 'Levina Kristinningsih Widjojo', 'levina.widjojo'
    UNION ALL SELECT 'Group 3 Officer', 'Nadia Valencia Tjiptowardojo', 'nadia.tjiptowardojo'
    UNION ALL SELECT 'Group 3 Officer', 'Rory Dinzah', 'rory.dinzah'
    UNION ALL SELECT 'Group 3 Officer', 'Ewaldo Tanton', 'ewaldo.tanton'
    UNION ALL SELECT 'Group 3 Officer', 'Keryn Stefany Thenniady', 'keryn.thenniady'
) expected
LEFT JOIN users u ON u.username = expected.username
ORDER BY expected.expected_use, expected.expected_name;

SET @schema_name := DATABASE();
SET @pgroup_type_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'allowed_pgroups'
      AND COLUMN_NAME = 'pgroup_type'
);
SET @pgroup_type_ddl := IF(
    @pgroup_type_exists = 0,
    'ALTER TABLE allowed_pgroups ADD COLUMN pgroup_type VARCHAR(20) NOT NULL DEFAULT ''NON_PROJECT'' AFTER status',
    'SELECT ''allowed_pgroups.pgroup_type already exists'' AS info'
);
PREPARE stmt FROM @pgroup_type_ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS project_head_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_group_number TINYINT UNSIGNED NOT NULL,
    project_group_name VARCHAR(100) NOT NULL,
    head_user_id INT NOT NULL,
    head_name VARCHAR(255) NOT NULL,
    head_username VARCHAR(100) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_head_group (project_group_number, head_username),
    KEY idx_project_head_groups_head_username (head_username),
    KEY idx_project_head_groups_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_pgroups (
    p_group VARCHAR(20) PRIMARY KEY,
    p_org VARCHAR(50) NULL,
    company VARCHAR(50) NULL,
    pgroup_description VARCHAR(255) NULL,
    project_group_number TINYINT UNSIGNED NOT NULL,
    project_group_name VARCHAR(100) NOT NULL,
    head_user_id INT NOT NULL,
    head_name VARCHAR(255) NOT NULL,
    head_username VARCHAR(100) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    source_file VARCHAR(255) NULL,
    source_page VARCHAR(50) NULL,
    source_text TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_project_pgroups_group (project_group_number),
    KEY idx_project_pgroups_head_username (head_username),
    KEY idx_project_pgroups_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_pgroup_officers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    p_group VARCHAR(20) NOT NULL,
    project_group_number TINYINT UNSIGNED NOT NULL,
    officer_nip VARCHAR(20) NOT NULL,
    officer_user_id INT NOT NULL,
    officer_name VARCHAR(255) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_project_pgroup_officer (p_group, officer_user_id),
    KEY idx_project_pgroup_officers_group (project_group_number),
    KEY idx_project_pgroup_officers_user (officer_user_id),
    KEY idx_project_pgroup_officers_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    imported_by VARCHAR(100) NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source_generated_at DATETIME NULL,
    total_rows INT NOT NULL DEFAULT 0,
    inserted_rows INT NOT NULL DEFAULT 0,
    updated_rows INT NOT NULL DEFAULT 0,
    skipped_rows INT NOT NULL DEFAULT 0,
    warning_count INT NOT NULL DEFAULT 0,
    warnings_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_import_batches_imported_at (imported_at),
    KEY idx_import_batches_imported_by (imported_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO project_head_groups
    (project_group_number, project_group_name, head_user_id, head_name, head_username, active)
SELECT
    heads.project_group_number,
    heads.project_group_name,
    u.id AS head_user_id,
    u.name AS head_name,
    heads.head_username,
    1 AS active
FROM (
    SELECT 1 AS project_group_number, 'Project Konstruksi Retail' AS project_group_name, 'rio.tandy' AS head_username
    UNION ALL SELECT 2, 'Project Konstruksi Properti', 'fabian.suhalim'
    UNION ALL SELECT 3, 'Maintenance & Facility Management', 'rio.tandy'
) heads
JOIN users u ON u.username = heads.head_username
ON DUPLICATE KEY UPDATE
    project_group_name = VALUES(project_group_name),
    head_user_id = VALUES(head_user_id),
    head_name = VALUES(head_name),
    active = VALUES(active),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO project_pgroups
    (p_group, p_org, company, pgroup_description, project_group_number, project_group_name, head_user_id, head_name, head_username, status, source_file, source_page, source_text)
SELECT
    pg.p_group,
    pg.p_org,
    pg.company,
    pg.pgroup_description,
    pg.project_group_number,
    pg.project_group_name,
    u.id AS head_user_id,
    u.name AS head_name,
    pg.head_username,
    'ACTIVE' AS status,
    pg.source_file,
    pg.source_page,
    pg.source_text
FROM (
    SELECT 'A97' AS p_group, 'RA30' AS p_org, 'R100' AS company, 'Project - AHI' AS pgroup_description, 1 AS project_group_number, 'Project Konstruksi Retail' AS project_group_name, 'rio.tandy' AS head_username, 'PGroup Mapping Project.pdf' AS source_file, 'page 4' AS source_page, 'R100 | RA30 | A97 | Project - ACE | RETAIL | PROJECT | Group 1' AS source_text
    -- A77 is provisional: not present in "PGroup Mapping Project.pdf"; head/group mirrored from A97 per explicit request.
    UNION ALL SELECT 'A77', 'RA30', 'R100', 'PROJECT - NEKA', 1, 'Project Konstruksi Retail', 'rio.tandy', NULL, NULL, 'PROVISIONAL: mirrored from A97 (head_username=rio.tandy, project_group_number=1, p_org=RA30, company=R100 confirmed from direct source data); not sourced from PGroup Mapping Project.pdf, A77 does not appear in that document'
    UNION ALL SELECT 'F97', 'FF30', 'F100', 'Project - CT', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 4', 'F100 | FF30 | F97 | Project - CT | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'DKH', 'TD30', 'T100', 'Project - DKI', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 4', 'T100 | TD30 | DKH | Project - DKI | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'GSO', 'SG30', 'S120', 'Project - GSI', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 4', 'S120 | SG30 | GSO | Project - GSI | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'R97', 'RR30', 'R140', 'Project - GSP', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 4', 'R140 | RR30 | R97 | Project - GSP | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'H97', 'RH30', 'R110', 'Project - HCI', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 4', 'R110 | RH30 | H97 | Project - HCI | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'U97', 'RU30', 'R150', 'Project - KKI', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 4', 'R150 | RU30 | U97 | Project - KKI | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'I97', 'II30', 'I100', 'Project - KLS', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 4', 'I100 | II30 | I97 | Project - KLS | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'V97', 'IV30', 'I180', 'Project - KLV', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 4', 'I180 | IV30 | V97 | Project - KLV | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'W97', 'IW30', 'I160', 'Project - ASK', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 4', 'I160 | IW30 | W97 | Project - ASK | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'K99', 'IK30', 'I110', 'Project - Krisbow', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 4', 'I110 | IK30 | K99 | Project - Krisbow | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'MRH', 'SM30', 'S100', 'Project - MRI', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 4', 'S100 | SM30 | MRH | Project - MRI | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'M97', 'IM30', 'I150', 'Project - MRO', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 4', 'I150 | IM30 | M97 | Project - MRO | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'O97', 'RO30', 'R130', 'Project - OMNI', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 4', 'R130 | RO30 | O97 | Project - OMNI | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'SLH', 'SS30', 'S110', 'Project - SLT', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 5', 'S110 | SS30 | SLH | Project - SLT | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'S97', 'IS30', 'I190', 'Project - SRI', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 5', 'I190 | IS30 | S97 | Project - SRI | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'T97', 'RT30', 'R120', 'Project - TGI', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 5', 'R120 | RT30 | T97 | Project - TGI | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'J97', 'RJ30', 'R160', 'Project HCIR', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 5', 'R160 | RJ30 | J97 | Project HCIR | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'Q99', 'IQ30', 'IQ100', 'Project - KLO', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 6', 'IQ100 | IQ30 | Q99 | Project - KLO | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'FYQ', 'FY30', 'F130', 'PROJECT', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 6', 'F130 | FY30 | FYQ | PROJECT | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'FPO', 'FP30', 'F120', 'Project - CRP', 1, 'Project Konstruksi Retail', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 8', 'F120 | FP30 | FPO | Project - CRP | RETAIL | PROJECT | Group 1'
    UNION ALL SELECT 'I84', 'II30', 'I100', 'Prjct Building-KLS', 2, 'Project Konstruksi Properti', 'fabian.suhalim', 'PGroup Mapping Project.pdf', 'page 4', 'I100 | II30 | I84 | Prjct Building-KLS | PROJECT | Group 2'
    UNION ALL SELECT 'E97', 'AE30', 'A100', 'Project - PGB', 2, 'Project Konstruksi Properti', 'fabian.suhalim', 'PGroup Mapping Project.pdf', 'page 4', 'A100 | AE30 | E97 | Project - PGB | PROJECT | Group 2'
    UNION ALL SELECT 'P97', 'PT30', 'P100', 'Project -Non M 328', 2, 'Project Konstruksi Properti', 'fabian.suhalim', 'PGroup Mapping Project.pdf', 'page 5', 'P100 | PT30 | P97 | Project -Non M 328 | PROPERTY | PROJECT | Group 2'
    UNION ALL SELECT 'PIJ', 'PI30', 'P110', 'Project -Non M GML', 2, 'Project Konstruksi Properti', 'fabian.suhalim', 'PGroup Mapping Project.pdf', 'page 5', 'P110 | PI30 | PIJ | Project -Non M GML | PROPERTY | PROJECT | Group 2'
    UNION ALL SELECT 'MIN', 'MI30', 'M130', 'Project - GII', 2, 'Project Konstruksi Properti', 'fabian.suhalim', 'PGroup Mapping Project.pdf', 'page 6', 'M130 | MI30 | MIN | Project - GII | PROPERTY | PROJECT | Group 2'
    UNION ALL SELECT 'MSN', 'MS30', 'M150', 'Project - GSSI', 2, 'Project Konstruksi Properti', 'fabian.suhalim', 'PGroup Mapping Project.pdf', 'page 7', 'M150 | MS30 | MSN | Project - GSSI | PROPERTY | PROJECT | Group 2'
    UNION ALL SELECT 'PDD', 'PD30', 'P190', 'PROJECT - SKI', 2, 'Project Konstruksi Properti', 'fabian.suhalim', 'PGroup Mapping Project.pdf', 'page 7', 'P190 | PD30 | PDD | PROJECT - SKI | PROPERTY | PROJECT | Group 2'
    UNION ALL SELECT 'MXN', NULL, 'M170', 'Project - GXI', 2, 'Project Konstruksi Properti', 'fabian.suhalim', 'PGroup Mapping Project.pdf', 'page 7', 'M170 | MXN | Project - GXI | PROPERTY | PROJECT | Group 2'
    UNION ALL SELECT 'PCD', 'PC30', 'P170', 'PROJECT', 2, 'Project Konstruksi Properti', 'fabian.suhalim', 'PGroup Mapping Project.pdf', 'page 7', 'P170 | PC30 | PCD | PROJECT | PROPERTY | PROJECT | Group 2'
    UNION ALL SELECT 'PMD', 'PM30', 'P180', 'PROJECT ARU', 2, 'Project Konstruksi Properti', 'fabian.suhalim', 'PGroup Mapping Project.pdf', 'page 7', 'P180 | PM30 | PMD | PROJECT ARU | PROPERTY | PROJECT | Group 2'
    UNION ALL SELECT 'A81', 'RA30', 'R100', 'Project 328 - AHI', 2, 'Project Konstruksi Properti', 'fabian.suhalim', 'PGroup Mapping Project.pdf', 'page 8', 'R100 | RA30 | A81 | Project 328 - AHI | RETAIL | PROJECT | Group 2'
    UNION ALL SELECT 'KDI', 'KD30', 'I240', 'Project - KDI', 2, 'Project Konstruksi Properti', 'fabian.suhalim', 'PGroup Mapping Project.pdf', 'page 8', 'I240 | KD30 | KDI | Project - KDI | PROPERTY | PROJECT | Group 2'
    UNION ALL SELECT 'D97', 'MD30', 'M100', 'Project - GDR', 2, 'Project Konstruksi Properti', 'fabian.suhalim', 'PGroup Mapping Project.pdf', 'page 8', 'M100 | MD30 | D97 | Project - GDR | RETAIL | PROJECT | Group 2'
    UNION ALL SELECT 'GLM', 'MG30', 'M120', 'Project - GLI', 2, 'Project Konstruksi Properti', 'fabian.suhalim', 'PGroup Mapping Project.pdf', 'page 8', 'M120 | MG30 | GLM | Project - GLI | RETAIL | PROJECT | Group 2'
    UNION ALL SELECT 'MRU', NULL, NULL, NULL, 2, 'Project Konstruksi Properti', 'fabian.suhalim', 'PGroup Mapping Project.pdf', 'page 8', 'MRU | PROPERTY | PROJECT | Group 2'
    UNION ALL SELECT 'A86', 'RA30', 'R100', 'EBM - ACE', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 1', 'R100 | RA30 | A86 | EBM - ACE | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'DKN', 'TD30', 'T100', 'EBM - DKI', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 1', 'T100 | TD30 | DKN | EBM - DKI | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'D84', 'MD30', 'M100', 'EBM - GDR', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 1', 'M100 | MD30 | D84 | EBM - GDR | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'GLA', 'MG30', 'M120', 'EBM - GLI', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 1', 'M120 | MG30 | GLA | EBM - GLI | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'GSC', 'SG30', 'S120', 'EBM - GSI', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 1', 'S120 | SG30 | GSC | EBM - GSI | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'H85', 'RH30', 'R110', 'EBM - HCI', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 1', 'R110 | RH30 | H85 | EBM - HCI | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'O85', 'RO30', 'R130', 'EBM - OMNI', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 1', 'R130 | RO30 | O85 | EBM - OMNI | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'T86', 'RT30', 'R120', 'EBM - TGI', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 1', 'R120 | RT30 | T86 | EBM - TGI | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'P98', 'PT30', 'P100', 'EBM 328', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 1', 'P100 | PT30 | P98 | EBM 328 | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'F85', 'FF30', 'F100', 'EBM FBI', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 1', 'F100 | FF30 | F85 | EBM FBI | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'PIK', 'PI30', 'P110', 'EBM GML', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 1', 'P110 | PI30 | PIK | EBM GML | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'R84', 'RR30', 'R140', 'EBM GSP', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 1', 'R140 | RR30 | R84 | EBM GSP | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'U86', 'RU30', 'R150', 'EBM KKI', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 1', 'R150 | RU30 | U86 | EBM KKI | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'I85', 'II30', 'I100', 'EBM KLS', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 2', 'I100 | II30 | I85 | EBM KLS | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'V86', 'IV30', 'I180', 'EBM KLV', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 2', 'I180 | IV30 | V86 | EBM KLV | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'W86', 'IW30', 'I160', 'EBM ASK', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 2', 'I160 | IW30 | W86 | EBM ASK | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'K85', 'IK30', 'I110', 'EBM KWI', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 2', 'I110 | IK30 | K85 | EBM KWI | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'MRS', 'SM30', 'S100', 'EBM MRI', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 2', 'S100 | SM30 | MRS | EBM MRI | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'M85', 'IM30', 'I150', 'EBM MRO', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 2', 'I150 | IM30 | M85 | EBM MRO | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'E86', 'AE30', 'A100', 'EBM PGB', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 2', 'A100 | AE30 | E86 | EBM PGB | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'SLM', 'SS30', 'S110', 'EBM SLT', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 2', 'S110 | SS30 | SLM | EBM SLT | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'S86', 'IS30', 'I190', 'EBM SRI', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 2', 'I190 | IS30 | S86 | EBM SRI | PROPERTY | PROJECT | Group 3'
    UNION ALL SELECT 'P61', 'PT30', 'P100', 'PROJECT/MTSUITES', 3, 'Maintenance & Facility Management', 'rio.tandy', 'PGroup Mapping Project.pdf', 'page 7', 'P100 | PT30 | P61 | PROJECT/MTSUITES | PROPERTY | PROJECT | Group 3'
) pg
JOIN users u ON u.username = pg.head_username
ON DUPLICATE KEY UPDATE
    p_org = VALUES(p_org),
    company = VALUES(company),
    pgroup_description = VALUES(pgroup_description),
    project_group_number = VALUES(project_group_number),
    project_group_name = VALUES(project_group_name),
    head_user_id = VALUES(head_user_id),
    head_name = VALUES(head_name),
    head_username = VALUES(head_username),
    status = VALUES(status),
    source_file = VALUES(source_file),
    source_page = VALUES(source_page),
    source_text = VALUES(source_text),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO project_pgroup_officers
    (p_group, project_group_number, officer_nip, officer_user_id, officer_name, active)
SELECT
    pp.p_group,
    pp.project_group_number,
    officers.officer_nip,
    u.id AS officer_user_id,
    u.name AS officer_name,
    1 AS active
FROM project_pgroups pp
JOIN (
    SELECT 1 AS project_group_number, '167855' AS officer_nip, 'william.pcr' AS officer_username
    UNION ALL SELECT 1, '184394', 'gary.permana'
    UNION ALL SELECT 1, '189254', 'michael.pcr'
    UNION ALL SELECT 1, '195079', 'irsantya.putrilia'
    UNION ALL SELECT 2, '167854', 'hindra.zefran'
    UNION ALL SELECT 2, '193274', 'rius.wintan'
    UNION ALL SELECT 2, '189931', 'theodore.seamditia'
    UNION ALL SELECT 3, '189361', 'josia.marxalim'
    UNION ALL SELECT 3, '182754', 'levina.widjojo'
    UNION ALL SELECT 3, '186789', 'nadia.tjiptowardojo'
    UNION ALL SELECT 3, '033690', 'rory.dinzah'
    UNION ALL SELECT 3, '183645', 'ewaldo.tanton'
    UNION ALL SELECT 3, '193035', 'keryn.thenniady'
) officers ON officers.project_group_number = pp.project_group_number
JOIN users u ON u.username = officers.officer_username
WHERE pp.status = 'ACTIVE'
ON DUPLICATE KEY UPDATE
    project_group_number = VALUES(project_group_number),
    officer_nip = VALUES(officer_nip),
    officer_name = VALUES(officer_name),
    active = VALUES(active),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO allowed_pgroups
    (pgroup_code, status, pgroup_type, suggested_by)
SELECT
    pp.p_group,
    'ACTIVE' AS status,
    'PROJECT' AS pgroup_type,
    NULL AS suggested_by
FROM project_pgroups pp
WHERE pp.status = 'ACTIVE'
ON DUPLICATE KEY UPDATE
    status = 'ACTIVE',
    pgroup_type = 'PROJECT';
