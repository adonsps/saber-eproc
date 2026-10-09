-- Migrasi risk_category ke skema 5-TIER RISK LOGIC (dijalankan 2026-07-16).
--
-- Latar belakang: kolom lama ENUM('Low Risk','Medium Risk','High Risk') tidak
-- cocok dengan label yang ditulis kode (Very Low..Critical dari
-- update_assessment.php, Excellent..Bad dari approve_action.php), sehingga di
-- MySQL 8 strict mode setiap save gagal dengan "Data truncated for column
-- 'risk_category'". Semua jalur kode kini memakai satu sumber logika di
-- includes/pq_risk_helper.php (pqRiskPercentage + pqRiskCategory).
--
-- URUTAN 3 LANGKAH INI WAJIB:
-- tidak bisa langsung ALTER ke enum baru (data lama 'High Risk' dll. tidak ada
-- di enum baru -> ALTER gagal di strict mode), dan tidak bisa UPDATE duluan
-- (nilai baru 'Moderate' dll. belum sah di enum lama). Maka: longgarkan dulu
-- ke VARCHAR, baru isi datanya, baru kunci ke enum final.

-- LANGKAH 1: longgarkan kolom agar nilai lama & baru sama-sama sah sementara.
ALTER TABLE pq_submissions MODIFY risk_category VARCHAR(20) NULL DEFAULT NULL;

-- LANGKAH 2: hitung ulang semua kategori dari risk_percentage (ambang sama
-- dengan pqRiskCategory); baris tanpa skor (max_possible_weighted_score <= 0)
-- di-NULL-kan.
UPDATE pq_submissions SET risk_category = CASE
    WHEN max_possible_weighted_score <= 0 THEN NULL
    WHEN risk_percentage <= 15 THEN 'Very Low'
    WHEN risk_percentage <= 29 THEN 'Low'
    WHEN risk_percentage <= 50 THEN 'Moderate'
    WHEN risk_percentage <= 61 THEN 'High'
    ELSE 'Critical' END;

-- LANGKAH 3: kunci kolom ke enum final 5 tingkat.
ALTER TABLE pq_submissions MODIFY risk_category ENUM('Very Low','Low','Moderate','High','Critical') NULL DEFAULT NULL;
