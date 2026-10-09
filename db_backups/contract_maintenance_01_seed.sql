-- db_backups/contract_maintenance_01_seed.sql
-- One-time historical load of the ~55 real rows from the team's
-- 'List Contract Maintenance.xlsx' (Rekap sheet) into contract_maintenance.
-- Table is created by workspace/config/database.php's guarded auto-migrate
-- block -- apply this AFTER that block has run at least once.
--
-- Idempotency: NOT idempotent by design (plain INSERTs, no dedup key on this
-- table) -- this is a one-time seed. Do not re-run against a database that
-- already has these rows, or they will be duplicated. Before applying, confirm
-- SELECT DATABASE(), USER(), VERSION(); matches CLAUDE.md's Local DB Identity
-- Guard, and that `SELECT COUNT(*) FROM contract_maintenance` is 0.
--
-- One spreadsheet row (row 23: site 'LP Sawojajar', remark 'apa ada?') was a
-- placeholder note with no category/supplier/type/BU -- not a real contract,
-- excluded here. 55 rows follow.

INSERT INTO contract_maintenance
    (item_category, description, supplier, contract_type, business_unit,
     site_area, contract_end_date, output_type, output_end_date,
     docs_reference, remarks)
VALUES
    ('Gas', 'Supply Gas Pipa', 'PT Jababeka Infrastruktur', 'PKS', 'Property', 'LP JABABEKA', NULL, NULL, NULL, NULL, 'ongoing approval'),
    ('Gas', 'Supply Gas Pipa', 'PT Wira Energi', 'PKS', 'Property', 'LP PURI', NULL, NULL, NULL, NULL, 'ongoing approval 3 tahun'),
    ('Gas', 'Supply Gas Pipa', 'PT Liqtro Sanurbali', 'PKS', 'Property', 'LW DPS', NULL, NULL, '2026-10-30', 'PKS LPG - LW DPS', NULL),
    ('Gas', 'Supply Gas Pipa', 'PT Wira Energi', 'PKS', 'Property', 'LW AS', NULL, NULL, '2029-02-17', 'diperpanjang ke 2033', NULL),
    ('Gas', 'Supply Gas Pipa', 'PT Sinar Indrapura Anugrah Khatulistiwa', 'PKS', 'Property', 'LW PKU', NULL, NULL, '2025-12-31', NULL, NULL),
    ('Gas', 'Supply Gas Pipa', 'PT Sepinggan', 'PKS', 'Property', 'LP BALIKPAPAN', NULL, NULL, '2025-12-31', NULL, 'ongoing approval'),
    ('Gas', 'Instalasi Gas', 'PT Delima Anugerah Suplindo', 'KHS', 'Property', 'LW PKU', '2026-12-31', NULL, NULL, '1. Instalasi Gas', NULL),
    ('Gas', 'Instalasi Gas', 'PT Delima Anugerah Suplindo', 'KHS', 'Property', 'LW DPS', '2026-12-31', NULL, NULL, NULL, NULL),
    ('Gas', 'Instalasi Gas', 'PT Delima Anugerah Suplindo', 'KHS', 'Property', 'LW AS', '2026-12-31', NULL, NULL, NULL, NULL),
    ('Gas', 'Instalasi Gas', 'PT Delima Anugerah Suplindo', 'KHS', 'Property', 'LP JABABEKA', '2026-12-31', NULL, NULL, NULL, NULL),
    ('Gas', 'Instalasi Gas', 'PT Delima Anugerah Suplindo', 'KHS', 'Property', 'LP PURI', '2026-12-31', NULL, NULL, NULL, NULL),
    ('Gas', 'Instalasi Gas', NULL, '-', 'Property', 'LP BALIKPAPAN', NULL, NULL, NULL, NULL, NULL),
    ('Service Mtc', 'Maintenance Chiller', 'PT Berca Carrier Indonesia', 'KHS', 'Property', 'LW AS', '2027-12-31', 'PO', '2026-12-31', 'Prev Mtc - LW AS', NULL),
    ('Service Mtc', 'Maintenance Chiller', 'PT Berca Carrier Indonesia', 'KHS', 'Property', 'LW PKU', '2027-12-31', 'PO', '2026-12-31', 'Prev Mtc - LW PKU', NULL),
    ('Service Mtc', 'Maintenance Chiller', 'PT Berca Carrier Indonesia', 'KHS', 'Property', 'LW DPS', '2027-12-31', 'PO', '2026-12-31', 'Prev Mtc - LW DPS', 'lagi diminta sama pak anton'),
    ('Service Mtc', 'Maintenance Chiller', 'PT Berca Carrier Indonesia', 'KHS', 'Property', 'LP BALIKPAPAN', '2027-12-31', 'PO', '2026-12-31', 'Prev Mtc - LP BPN', 'remind pak angga 8/9; minta diperjelas dulu, mau diapakan chillernya'),
    ('Service Mtc', 'Chemical Treatment CT', 'PT Omega Interbuana Chemindo', 'KHS', 'Property', 'LP Sawojajar', '2028-12-31', 'PO', '2026-12-31', 'Chemical CT - LP SAWOJAJAR', 'req. pembanding'),
    ('Service Mtc', 'Chemical Treatment CT', 'PT Omega Interbuana Chemindo', 'KHS', 'Property', 'LW PKU', '2028-12-31', 'PO', '2026-12-31', 'Chemical CT - LW PKU', 'req. pembanding'),
    ('Service Mtc', 'Chemical Treatment CT', 'PT Omega Interbuana Chemindo', 'KHS', 'Property', 'LW AS', '2028-12-31', 'PO', '2026-12-31', 'Chemical CT - LW AS', 'req. pembanding'),
    ('Service Mtc', 'Chemical Treatment CT', 'PT Omega Interbuana Chemindo', 'KHS', 'Property', 'LW DPS', '2028-12-31', 'PO', '2026-12-31', 'Chemical CT - LP SAWOJAJAR', 'req. pembanding'),
    ('Fire Fighting', 'Sprinkler & Smoke Detector', 'Jamal Hendra', 'KHS', 'Property', 'LW PKU', '2026-12-31', NULL, NULL, 'Sprinkler - LW PKU', 'req. pembanding'),
    ('Fire Fighting', 'Sprinkler & Smoke Detector', 'PT Hamparan Daya Cipta', 'KHS', 'Property', 'LW DPS', '2026-12-31', NULL, NULL, 'Sprinkler - LW DPS', 'req. pembanding; belum approve karena naik harga jauh'),
    ('Fire Fighting', 'Sprinkler & Smoke Detector', 'CV Mitra Termuda Jaya', 'KHS', 'Property', 'LW AS', '2026-12-31', 'AON', '2026-12-31', 'Sprinkler  - LW AS, LP PURI, LP JBBK', NULL),
    ('Fire Fighting', 'Sprinkler & Smoke Detector', 'CV Mitra Termuda Jaya', 'KHS', 'Property', 'LP PURI', '2026-12-31', 'AON', '2026-12-31', NULL, NULL),
    ('Fire Fighting', 'Sprinkler & Smoke Detector', 'CV Mitra Termuda Jaya', 'KHS', 'Property', 'LP JABABEKA', '2026-12-31', 'AON', '2026-12-31', NULL, NULL),
    ('Flexi/Hoarding', 'Pasang Flexi Hoarding', 'Suriyanto', 'KHS', 'Property', 'LW PKU', '2026-12-31', 'PO', '2026-12-31', 'Bongpas Hoarding Flexi - LW PKU', NULL),
    ('Flexi/Hoarding', 'Cetak Flexi', 'PT Nusantara Mandiri', 'KHS', 'Property', 'LW PKU', '2026-06-30', 'PO', '2026-06-30', 'LW PKU', NULL),
    ('Flexi/Hoarding', 'Pasang Flexi Hoarding', 'PT HDM Karya Utama', 'KHS', 'Property', 'LW AS', '2026-06-30', 'PO', '2026-06-30', 'Bongpas Hoarding Flexi  - LW AS', NULL),
    ('Flexi/Hoarding', 'Pasang Flexi Hoarding', 'PT HDM Karya Utama', 'KHS', 'Property', 'LP JABABEKA', '2026-06-30', NULL, NULL, NULL, NULL),
    ('Flexi/Hoarding', 'Pasang Flexi Hoarding', 'PT HDM Karya Utama', 'KHS', 'Property', 'LP PURI', '2026-06-30', NULL, NULL, NULL, NULL),
    ('Flexi/Hoarding', 'Cetak Flexi', 'PT Wellen Brothers', 'KHS', 'Property', 'LW AS', '2026-12-31', 'PO', '2026-06-30', 'Cetak Flexi - LW AS, LP JBBK, LP PURI', NULL),
    ('Flexi/Hoarding', 'Cetak Flexi', 'PT Wellen Brothers', 'KHS', 'Property', 'LP JABABEKA', '2026-12-31', NULL, NULL, NULL, NULL),
    ('Flexi/Hoarding', 'Cetak Flexi', 'PT Wellen Brothers', 'KHS', 'Property', 'LP PURI', '2026-12-31', NULL, NULL, NULL, NULL),
    ('Chemical', 'Chemical STP & AC', 'PT Mitra Alam Sahabat', 'KHS', 'KLG', NULL, '2026-12-31', 'AON', '2026-12-31', 'Chemical - PT MITRA ALAM SAHABAT', NULL),
    ('General Cleaning', 'Jasa Kuras Limbah', 'PT Hadi Towing Transport', 'KHS', 'Property', 'LW DPS', '2026-06-30', 'PO', '2026-06-30', NULL, NULL),
    ('Vertical Transportation', 'Service Maintenance Lift & Esc', 'PT Berca Schindler Lifts', 'PKS', 'KLG', NULL, '2027-12-31', NULL, NULL, 'Kontrak Service Maintenance Lift & Escalator', NULL),
    ('Vertical Transportation', 'Service Maintenance Lift & Esc', 'PT Hyundai Elevator Indonesia', 'PKS', 'KLG', NULL, '2027-12-31', NULL, NULL, NULL, NULL),
    ('Vertical Transportation', 'Service Maintenance Lift & Esc', 'PT Jaya Kencana', 'PKS', 'KLG', NULL, '2027-12-31', NULL, NULL, NULL, NULL),
    ('Vertical Transportation', 'Service Maintenance Lift & Esc', 'PT Kone Indo Elevator', 'PKS', 'KLG', NULL, '2027-12-31', NULL, NULL, NULL, NULL),
    ('Vertical Transportation', 'Service Maintenance Lift & Esc', 'PT Pillar Utama Contrindo', 'PKS', 'KLG', NULL, '2027-12-31', NULL, NULL, NULL, NULL),
    ('Vertical Transportation', 'Service Maintenance Lift & Esc', 'PT Reality Mitra Persada', 'PKS', 'KLG', NULL, NULL, NULL, NULL, NULL, NULL),
    ('Vertical Transportation', 'Service Maintenance Lift & Esc', 'PT Rekan Sukses Sejahtera', 'PKS', 'KLG', NULL, '2027-12-31', NULL, NULL, NULL, NULL),
    ('Vertical Transportation', 'Service Maintenance Lift & Esc', 'PT Sei Liput Perdana', 'PKS', 'KLG', NULL, NULL, NULL, NULL, NULL, NULL),
    ('Spareparts', 'Spareparts Lift & Esc', 'PT Berca Schindler Lifts', 'KHS', 'KLG', NULL, '2026-12-31', NULL, NULL, 'Spareparts Lift & Escalator', NULL),
    ('Spareparts', 'Spareparts Lift & Esc', 'PT Hyundai Elevator Indonesia', 'KHS', 'KLG', NULL, '2026-12-31', NULL, NULL, NULL, NULL),
    ('Spareparts', 'Spareparts Lift & Esc', 'PT Jaya Kencana', 'KHS', 'KLG', NULL, '2026-12-31', NULL, NULL, NULL, NULL),
    ('Spareparts', 'Spareparts Lift & Esc', 'PT Kone Indo Elevator', 'KHS', 'KLG', NULL, NULL, NULL, NULL, NULL, NULL),
    ('Spareparts', 'Spareparts Lift & Esc', 'PT Pillar Utama Contrindo', 'KHS', 'KLG', NULL, '2026-12-31', NULL, NULL, NULL, NULL),
    ('Spareparts', 'Spareparts Lift & Esc', 'PT Reality Mitra Persada', 'KHS', 'KLG', NULL, '2026-12-31', NULL, NULL, NULL, NULL),
    ('Spareparts', 'Spareparts Lift & Esc', 'PT Rekan Sukses Sejahtera', 'KHS', 'KLG', NULL, '2026-12-31', NULL, NULL, NULL, NULL),
    ('Spareparts', 'Spareparts Lift & Esc', 'PT Sei Liput Perdana', 'KHS', 'KLG', NULL, '2026-12-31', NULL, NULL, NULL, NULL),
    ('General Cleaning', 'General Cleaning AC', 'PT Home Center Indonesi (CNC)', 'KHS', 'KLG', NULL, '2028-12-31', 'PO', '2026-12-31', 'Cleaning AC - CNC', NULL),
    ('Interior', 'Bongkar Bare Tenant', 'Agus Nugroho', 'KHS', 'Property', 'LW PKU', '2026-12-31', NULL, NULL, 'Bongkar Bare - LW PKU', NULL),
    ('Interior', 'Bongkar Bare Tenant', 'Heliyanto', 'KHS', 'Property', 'LW AS', '2026-12-31', NULL, NULL, 'Bongkar Bare - LW AS', NULL),
    ('Interior', 'Bongkar Bare Tenant', NULL, 'KHS', 'Property', 'LW DPS', NULL, NULL, NULL, 'Bongkar Bare - LW DPS', NULL);
