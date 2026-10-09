-- ====================================================================
-- Vendor Evaluation v2 -- Legacy "Contractor Evaluation Form (Retail)"
-- reimport (2026-09-17)
--
-- Source: "[2026] Contractor Evaluation Form (Retail) (Responses) (2).xlsx",
-- sheet "Detail Evaluasi Per Proyek", rows 5-38 (34 project/vendor evaluations).
--
-- Context: an earlier historical bulk-import only loaded 14 of these 34 rows,
-- under fabricated po_number values (LEGACY-RETAIL-0001..0014) with no link to
-- the real PR/PO, and with 9 of the 14 given the wrong score_band (the app's
-- ve2_score_grade() thresholds are looser than the business's original A-E
-- rubric, plus the schema had no 'E' band at all). This script:
--   1. Widens score_band to add 'E' (see also the guarded auto-migrate block
--      added to workspace/config/database.php the same day).
--   2. Fixes po_number (to the real PO where confidently matched against prs)
--      and score_band on the 14 existing rows.
--   3. Inserts the 20 rows that were never imported at all.
--
-- IMPORTANT CAVEAT -- read before trusting the 'View' modal for these rows:
-- For 14 of the 34 rows (row 24, plus 13 more found when every missing row was
-- cross-checked), the source Excel's own raw per-question answers do NOT sum to
-- the pivot sheet's stated total for the vendor it's filed under -- almost
-- certainly evaluators picking the wrong contractor while filling out back-to-
-- back Google Form entries for the same project (several rows' computed totals
-- exactly match a DIFFERENT vendor's expected total in the same project group).
-- Per business decision (2026-09-17): the pivot's total_score/score_band is
-- trusted as authoritative and inserted, but NO per-question vendor_eval_answers
-- rows are written for these 14 -- the 'View' modal will show the score with no
-- criterion breakdown, rather than attaching a possibly-wrong vendor's answers.
-- Affected projects: AZKO SUMARECCON BANDUNG (MODELATAMA), Selma Gresik mall x2,
-- RELAYOUT ICF MANTOS x2, INFORMA GRESIK ICON MALL x2, INFORMA ELEKTRONIK GRESIK
-- ICON MALL x3, Toys K square Batam x3.
--
-- Apply with (after verifying identity -- DATABASE()/USER()/VERSION() -- and
-- taking a backup of vendor_eval_submissions + vendor_eval_answers):
--   mysql -h HOST -u USER -p DATABASE < db_backups/vendor_eval_v2_08_legacy_retail_2026_reimport.sql
-- ====================================================================

-- Step 1: widen score_band (idempotent -- MODIFY to the same definition is a no-op).
-- Deliberately OUTSIDE the transaction below: ALTER TABLE causes an implicit commit
-- in InnoDB/MySQL, so running it mid-transaction would silently break the atomicity
-- of the data changes that follow -- if something later failed and rolled back, this
-- schema change would NOT roll back with it, leaving the transaction's "all or
-- nothing" guarantee false. Applying it first, standalone, avoids that.
ALTER TABLE vendor_eval_submissions MODIFY score_band ENUM('A','B','C','D','E') NULL;

START TRANSACTION;

SET @qs11 := (SELECT id FROM vendor_eval_question_sets WHERE set_code = 'VE_PROJECT_RETAIL_V1' AND version = 1);

-- ====================================================================
-- Step 2: fix the 14 existing LEGACY-RETAIL-* rows (po_number + score_band)
-- ====================================================================

-- id 1 (LEGACY-RETAIL-0001): GGC Mantos / PANTJA WARNA ABADI -- no real PO found
UPDATE vendor_eval_submissions SET score_band = 'C' WHERE id = 1;

-- id 2 (LEGACY-RETAIL-0002): Chatime Hotdakk Lubuk Linggau / HUTAMAYASA -- already correct
-- no change needed

-- id 3 (LEGACY-RETAIL-0003): Seoul88 Puri Indah Mal / CAKRA BANGUN SUKSES -- already B
UPDATE vendor_eval_submissions SET po_number = '4200281395' WHERE id = 3;

-- id 4 (LEGACY-RETAIL-0004): Relayout CT Palembang Trade Centre / HUTAMAYASA
UPDATE vendor_eval_submissions SET po_number = '4200277091', score_band = 'E' WHERE id = 4;

-- id 5 (LEGACY-RETAIL-0005): Chatime x Hotdakk Trvoy Hub TMII / HUTAMAYASA -- no real PO found
UPDATE vendor_eval_submissions SET score_band = 'C' WHERE id = 5;

-- id 6 (LEGACY-RETAIL-0006): Chatime AzkoTimika / HUTAMAYASA -- already C
UPDATE vendor_eval_submissions SET po_number = '4200276011' WHERE id = 6;

-- id 7 (LEGACY-RETAIL-0007): Chatime x hotdakk mall ciputra tangerang / HUTAMAYASA
UPDATE vendor_eval_submissions SET po_number = '4200273397', score_band = 'D' WHERE id = 7;

-- id 8 (LEGACY-RETAIL-0008): Chatime x hotdakk 23 semarang / INKREA
UPDATE vendor_eval_submissions SET po_number = '4200275172', score_band = 'D' WHERE id = 8;

-- id 9 (LEGACY-RETAIL-0009): Chatime x hotdakk m ramdan / HUTAMAYASA
UPDATE vendor_eval_submissions SET po_number = '4200277654', score_band = 'C' WHERE id = 9;

-- id 10 (LEGACY-RETAIL-0010): NEKA Leuwiliang Bogor / DINA SARANA TEHNIK -- already B
UPDATE vendor_eval_submissions SET po_number = '4200275662' WHERE id = 10;

-- id 11 (LEGACY-RETAIL-0011): Toys Kingdom K Square Batam / HUTAMAYASA
UPDATE vendor_eval_submissions SET po_number = '4200279446', score_band = 'D' WHERE id = 11;

-- id 12 (LEGACY-RETAIL-0012): Azko Kuningan / CV. Cipta Gemilang Tehnik
UPDATE vendor_eval_submissions SET po_number = '4200277627', score_band = 'C' WHERE id = 12;

-- id 13 (LEGACY-RETAIL-0013): Azko kuningan / MODELATAMA -- already B
UPDATE vendor_eval_submissions SET po_number = '4200277626' WHERE id = 13;

-- id 14 (LEGACY-RETAIL-0014): Azko kuningan / HUTAMAYASA
UPDATE vendor_eval_submissions SET po_number = '4200277625', score_band = 'D' WHERE id = 14;

-- ====================================================================
-- Step 3: insert the 20 rows never imported
-- ====================================================================

-- pivot row 8: 1002004616 PT. ADAMAS GLOBALINDO
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('4200284135', '1002004616 PT. ADAMAS GLOBALINDO', 'MULKI TAMAM', @qs11, NULL, NULL, 3.8500, 'A', 'submitted', '2026-07-16 05:35:15');
SET @sub_id := LAST_INSERT_ID();
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=1), 1, 'Ketersediaan Time Schedule', 'Ketersediaan Time Schedule', 4, 'Sangat Bagus', 0.1000, 0.4000, 'SESUAI KESEPAKATAN');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=2), 2, 'Ketersediaan Pengawas', 'Ketersediaan Pengawas', 4, 'Sangat Bagus', 0.1000, 0.4000, 'TERSEDIA');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=3), 3, 'Waktu Penyelesaian Pekerjaan', 'Waktu Penyelesaian Pekerjaan', 4, 'Sangat Bagus', 0.2500, 1.0000, 'LEBIH CEPAT DARI SPK');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=4), 4, 'Kesiapan Kontraktor (Mobilization Readiness)', 'Kesiapan Kontraktor (Mobilization Readiness)', 4, 'Sangat Bagus', 0.1000, 0.4000, 'MATERIAL ON TIME');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=5), 5, 'Kesesuaian/ Kualitas Hasil Pekerjaan', 'Kesesuaian/ Kualitas Hasil Pekerjaan', 4, 'Sangat Bagus', 0.1000, 0.4000, 'KUALITAS SESUAI');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=6), 6, 'Defect List Diperbaiki Sesuai Batas Waktu', 'Defect List Diperbaiki Sesuai Batas Waktu', 4, 'Sangat Bagus', 0.1000, 0.4000, 'DISELESAIKAN SEBELUM 7 HARI , SETELAH OPENING');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=7), 7, 'K3, HSE dan Kebersihan', 'K3, HSE dan Kebersihan', 1, 'Tidak Bagus', 0.0500, 0.0500, 'HSE TIDAK MENDUKUNG');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=8), 8, 'Jumlah Surat Peringatan (SP)', 'Jumlah Surat Peringatan (SP)', 4, 'Sangat Bagus', 0.1000, 0.4000, 'TIDAK ADA SP');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=9), 9, 'Respon Terhadap SI saat Fitout Berlangsung', 'Respon Terhadap SI saat Fitout Berlangsung', 4, 'Sangat Bagus', 0.0500, 0.2000, 'SESUAI HARAPAN');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=10), 10, 'Administrasi', 'Administrasi', 4, 'Sangat Bagus', 0.0500, 0.2000, 'RAPI');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=11), 11, 'Kesimpulan', 'Ceritakan ringkasan kinerja dan isu vendor dalam performance ini', NULL, '', 0.0000, 0.0000, 'LAYAK UNTUK PROYEK BERIKUTNYA');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=12), 12, 'Nama', 'Silahkan isi nama', NULL, 'MULKI TAMAM', 0.0000, 0.0000, 'MULKI TAMAM');

-- pivot row 9: 1002015200 PT. TIARA SAKTI PUTRA MANDIRI
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('4200285234', '1002015200 PT. TIARA SAKTI PUTRA MANDIRI', 'MULKI TAMAM', @qs11, NULL, NULL, 4.0000, 'A', 'submitted', '2026-07-16 05:45:07');
SET @sub_id := LAST_INSERT_ID();
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=1), 1, 'Ketersediaan Time Schedule', 'Ketersediaan Time Schedule', 4, 'Sangat Bagus', 0.1000, 0.4000, 'SESUAI KESEPAKATAN');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=2), 2, 'Ketersediaan Pengawas', 'Ketersediaan Pengawas', 4, 'Sangat Bagus', 0.1000, 0.4000, 'ADA PENGAWAS');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=3), 3, 'Waktu Penyelesaian Pekerjaan', 'Waktu Penyelesaian Pekerjaan', 4, 'Sangat Bagus', 0.2500, 1.0000, 'SESUAI SPK');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=4), 4, 'Kesiapan Kontraktor (Mobilization Readiness)', 'Kesiapan Kontraktor (Mobilization Readiness)', 4, 'Sangat Bagus', 0.1000, 0.4000, 'TIDAK ADA MASALAH  DI LAPANGAN');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=5), 5, 'Kesesuaian/ Kualitas Hasil Pekerjaan', 'Kesesuaian/ Kualitas Hasil Pekerjaan', 4, 'Sangat Bagus', 0.1000, 0.4000, 'SESUAI KESEPAKATAN');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=6), 6, 'Defect List Diperbaiki Sesuai Batas Waktu', 'Defect List Diperbaiki Sesuai Batas Waktu', 4, 'Sangat Bagus', 0.1000, 0.4000, 'DISELESAIKAN SEBELUM OPENING');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=7), 7, 'K3, HSE dan Kebersihan', 'K3, HSE dan Kebersihan', 4, 'Sangat Bagus', 0.0500, 0.2000, 'AREA KERJA KURANG BERSIH');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=8), 8, 'Jumlah Surat Peringatan (SP)', 'Jumlah Surat Peringatan (SP)', 4, 'Sangat Bagus', 0.1000, 0.4000, 'TIDAK ADA SP');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=9), 9, 'Respon Terhadap SI saat Fitout Berlangsung', 'Respon Terhadap SI saat Fitout Berlangsung', 4, 'Sangat Bagus', 0.0500, 0.2000, 'SESUAI DENGAN HARAPAN');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=10), 10, 'Administrasi', 'Administrasi', 4, 'Sangat Bagus', 0.0500, 0.2000, 'RAPI');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=11), 11, 'Kesimpulan', 'Ceritakan ringkasan kinerja dan isu vendor dalam performance ini', NULL, '', 0.0000, 0.0000, 'LAYAK UNTUK PROYEK BERIKUTNYA');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=12), 12, 'Nama', 'Silahkan isi nama', NULL, 'MULKI TAMAM', 0.0000, 0.0000, 'MULKI TAMAM');

-- pivot row 24: 1002010227 PT. MODELATAMA BANTENG SEJATI
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('LEGACY-RETAIL-0018', '1002010227 PT. MODELATAMA BANTENG SEJATI', 'MULKI TAMAM', @qs11, NULL, NULL, 3.9000, 'A', 'submitted', '2026-07-16 17:52:29');
SET @sub_id := LAST_INSERT_ID();
-- (no per-question answers -- source data didn't reconcile with the pivot total, see caveat above)

-- pivot row 23: 1002000732 PT. AIRTEKINDO PRIMA / 1002014312 PT. TICA DAIWA INDONESIA
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('LEGACY-RETAIL-0017', '1002000732 PT. AIRTEKINDO PRIMA / 1002014312 PT. TICA DAIWA INDONESIA', 'MULKI TAMAM', @qs11, NULL, NULL, 3.5000, 'A', 'submitted', '2026-07-16 17:58:12');
SET @sub_id := LAST_INSERT_ID();
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=1), 1, 'Ketersediaan Time Schedule', 'Ketersediaan Time Schedule', 2, 'Kurang Bagus', 0.1000, 0.2000, 'TIDAK ADA TIME SCHEDULE');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=2), 2, 'Ketersediaan Pengawas', 'Ketersediaan Pengawas', 2, 'Kurang Bagus', 0.1000, 0.2000, 'TIDAK ADA PENGAWAS');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=3), 3, 'Waktu Penyelesaian Pekerjaan', 'Waktu Penyelesaian Pekerjaan', 4, 'Sangat Bagus', 0.2500, 1.0000, 'SESUAI SPK');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=4), 4, 'Kesiapan Kontraktor (Mobilization Readiness)', 'Kesiapan Kontraktor (Mobilization Readiness)', 4, 'Sangat Bagus', 0.1000, 0.4000, 'TIDAK ADA MASALAH DILAPANGAN');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=5), 5, 'Kesesuaian/ Kualitas Hasil Pekerjaan', 'Kesesuaian/ Kualitas Hasil Pekerjaan', 4, 'Sangat Bagus', 0.1000, 0.4000, 'OPENING TANPA DEPECT');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=6), 6, 'Defect List Diperbaiki Sesuai Batas Waktu', 'Defect List Diperbaiki Sesuai Batas Waktu', 4, 'Sangat Bagus', 0.1000, 0.4000, 'TIDAK ADA DEPECT');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=7), 7, 'K3, HSE dan Kebersihan', 'K3, HSE dan Kebersihan', 2, 'Kurang Bagus', 0.0500, 0.1000, 'K3 HSE TIDAK MENDUKUNG , & KEBERSIHAN CUKUP');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=8), 8, 'Jumlah Surat Peringatan (SP)', 'Jumlah Surat Peringatan (SP)', 4, 'Sangat Bagus', 0.1000, 0.4000, 'TIDAK ADA SP');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=9), 9, 'Respon Terhadap SI saat Fitout Berlangsung', 'Respon Terhadap SI saat Fitout Berlangsung', 4, 'Sangat Bagus', 0.0500, 0.2000, 'SESUAI KESEPAKATAN');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=10), 10, 'Administrasi', 'Administrasi', 4, 'Sangat Bagus', 0.0500, 0.2000, 'BAIK');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=11), 11, 'Kesimpulan', 'Ceritakan ringkasan kinerja dan isu vendor dalam performance ini', NULL, '', 0.0000, 0.0000, 'LAYAK PROYEK BERIKUTNYA');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=12), 12, 'Nama', 'Silahkan isi nama', NULL, 'MULKI TAMAM', 0.0000, 0.0000, 'MULKI TAMAM');

-- pivot row 34: 1002001321 PT. HUTAMAYASA KREASI
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('LEGACY-RETAIL-0019', '1002001321 PT. HUTAMAYASA KREASI', 'Ade denda', @qs11, NULL, NULL, 3.2000, 'B', 'submitted', '2026-07-17 14:27:34');
SET @sub_id := LAST_INSERT_ID();
-- (no per-question answers -- source data didn't reconcile with the pivot total, see caveat above)

-- pivot row 33: 1002000732 PT. AIRTEKINDO PRIMA / 1002014312 PT. TICA DAIWA INDONESIA
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('LEGACY-RETAIL-0020', '1002000732 PT. AIRTEKINDO PRIMA / 1002014312 PT. TICA DAIWA INDONESIA', 'Ade', @qs11, NULL, NULL, 3.3000, 'B', 'submitted', '2026-07-17 14:34:03');
SET @sub_id := LAST_INSERT_ID();
-- (no per-question answers -- source data didn't reconcile with the pivot total, see caveat above)

-- pivot row 35: 1002010227 PT. MODELATAMA BANTENG SEJATI
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('4200280210', '1002010227 PT. MODELATAMA BANTENG SEJATI', 'ade', @qs11, NULL, NULL, 2.7500, 'B', 'submitted', '2026-07-17 14:38:05');
SET @sub_id := LAST_INSERT_ID();
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=1), 1, 'Ketersediaan Time Schedule', 'Ketersediaan Time Schedule', 1, 'Tidak Bagus', 0.1000, 0.1000, 'Tidak ada');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=2), 2, 'Ketersediaan Pengawas', 'Ketersediaan Pengawas', 2, 'Kurang Bagus', 0.1000, 0.2000, 'Hanya datang sekali');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=3), 3, 'Waktu Penyelesaian Pekerjaan', 'Waktu Penyelesaian Pekerjaan', 3, 'Bagus', 0.2500, 0.7500, 'Sesuai spk');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=4), 4, 'Kesiapan Kontraktor (Mobilization Readiness)', 'Kesiapan Kontraktor (Mobilization Readiness)', 3, 'Bagus', 0.1000, 0.3000, 'Sesuai');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=5), 5, 'Kesesuaian/ Kualitas Hasil Pekerjaan', 'Kesesuaian/ Kualitas Hasil Pekerjaan', 3, 'Bagus', 0.1000, 0.3000, 'Tidak ada defect');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=6), 6, 'Defect List Diperbaiki Sesuai Batas Waktu', 'Defect List Diperbaiki Sesuai Batas Waktu', 3, 'Bagus', 0.1000, 0.3000, 'Selesai sblm opening');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=7), 7, 'K3, HSE dan Kebersihan', 'K3, HSE dan Kebersihan', 3, 'Bagus', 0.0500, 0.1500, 'Mengikuti');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=8), 8, 'Jumlah Surat Peringatan (SP)', 'Jumlah Surat Peringatan (SP)', 4, 'Sangat Bagus', 0.1000, 0.4000, 'Tidak ada');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=9), 9, 'Respon Terhadap SI saat Fitout Berlangsung', 'Respon Terhadap SI saat Fitout Berlangsung', 3, 'Bagus', 0.0500, 0.1500, 'Langsung di kerjakan');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=10), 10, 'Administrasi', 'Administrasi', 2, 'Kurang Bagus', 0.0500, 0.1000, 'Perlu dikejar tamkur nya');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=11), 11, 'Kesimpulan', 'Ceritakan ringkasan kinerja dan isu vendor dalam performance ini', NULL, '', 0.0000, 0.0000, 'Cukup baik');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=12), 12, 'Nama', 'Silahkan isi nama', NULL, 'ade', 0.0000, 0.0000, 'ade');

-- pivot row 31: 1002001321 PT. HUTAMAYASA KREASI
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('LEGACY-RETAIL-0021', '1002001321 PT. HUTAMAYASA KREASI', 'MULKI TAMAM', @qs11, NULL, NULL, 2.4000, 'C', 'submitted', '2026-07-17 20:29:16');
SET @sub_id := LAST_INSERT_ID();
-- (no per-question answers -- source data didn't reconcile with the pivot total, see caveat above)

-- pivot row 32: 1002010227 PT. MODELATAMA BANTENG SEJATI
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('LEGACY-RETAIL-0022', '1002010227 PT. MODELATAMA BANTENG SEJATI', 'MULKI TAMAM', @qs11, NULL, NULL, 3.2500, 'B', 'submitted', '2026-07-17 20:38:37');
SET @sub_id := LAST_INSERT_ID();
-- (no per-question answers -- source data didn't reconcile with the pivot total, see caveat above)

-- pivot row 22: 1002015200 PT. TIARA SAKTI PUTRA MANDIRI
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('LEGACY-RETAIL-0023', '1002015200 PT. TIARA SAKTI PUTRA MANDIRI', 'MULKI TAMAM', @qs11, NULL, NULL, 3.8500, 'A', 'submitted', '2026-07-17 20:50:39');
SET @sub_id := LAST_INSERT_ID();
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=1), 1, 'Ketersediaan Time Schedule', 'Ketersediaan Time Schedule', 4, 'Sangat Bagus', 0.1000, 0.4000, 'TIME SCHEDULE LENGKAP');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=2), 2, 'Ketersediaan Pengawas', 'Ketersediaan Pengawas', 4, 'Sangat Bagus', 0.1000, 0.4000, 'PENGAWAS DEDICATED DI LOKASI');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=3), 3, 'Waktu Penyelesaian Pekerjaan', 'Waktu Penyelesaian Pekerjaan', 4, 'Sangat Bagus', 0.2500, 1.0000, 'SESUAI SPK');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=4), 4, 'Kesiapan Kontraktor (Mobilization Readiness)', 'Kesiapan Kontraktor (Mobilization Readiness)', 4, 'Sangat Bagus', 0.1000, 0.4000, 'TIDAK ADA MASALAH DILAPANGAN');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=5), 5, 'Kesesuaian/ Kualitas Hasil Pekerjaan', 'Kesesuaian/ Kualitas Hasil Pekerjaan', 4, 'Sangat Bagus', 0.1000, 0.4000, 'SESUAI KESEPAKATAN');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=6), 6, 'Defect List Diperbaiki Sesuai Batas Waktu', 'Defect List Diperbaiki Sesuai Batas Waktu', 4, 'Sangat Bagus', 0.1000, 0.4000, 'TIDAK ADA DEPECT');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=7), 7, 'K3, HSE dan Kebersihan', 'K3, HSE dan Kebersihan', 1, 'Tidak Bagus', 0.0500, 0.0500, 'HSE K3 TIDAK ADA & KEBERSIHAN CUKUP');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=8), 8, 'Jumlah Surat Peringatan (SP)', 'Jumlah Surat Peringatan (SP)', 4, 'Sangat Bagus', 0.1000, 0.4000, 'TIDAK ADA SP');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=9), 9, 'Respon Terhadap SI saat Fitout Berlangsung', 'Respon Terhadap SI saat Fitout Berlangsung', 4, 'Sangat Bagus', 0.0500, 0.2000, 'SESUAI KESEPAKATAN');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=10), 10, 'Administrasi', 'Administrasi', 4, 'Sangat Bagus', 0.0500, 0.2000, 'RAPI');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=11), 11, 'Kesimpulan', 'Ceritakan ringkasan kinerja dan isu vendor dalam performance ini', NULL, '', 0.0000, 0.0000, 'LAYAK DI TUNJUK UNTUK PROYEK BERIKUTNYA');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=12), 12, 'Nama', 'Silahkan isi nama', NULL, 'MULKI TAMAM', 0.0000, 0.0000, 'MULKI TAMAM');

-- pivot row 21: 1002010227 PT. MODELATAMA BANTENG SEJATI
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('LEGACY-RETAIL-0024', '1002010227 PT. MODELATAMA BANTENG SEJATI', 'MULKI TAMAM', @qs11, NULL, NULL, 3.2500, 'B', 'submitted', '2026-07-17 20:58:10');
SET @sub_id := LAST_INSERT_ID();
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=1), 1, 'Ketersediaan Time Schedule', 'Ketersediaan Time Schedule', 1, 'Tidak Bagus', 0.1000, 0.1000, 'TIDAK ADA SCHEDULE');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=2), 2, 'Ketersediaan Pengawas', 'Ketersediaan Pengawas', 1, 'Tidak Bagus', 0.1000, 0.1000, 'TIDAK ADA PENGAWAS');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=3), 3, 'Waktu Penyelesaian Pekerjaan', 'Waktu Penyelesaian Pekerjaan', 4, 'Sangat Bagus', 0.2500, 1.0000, 'SESUAI KESEPAKATAN');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=4), 4, 'Kesiapan Kontraktor (Mobilization Readiness)', 'Kesiapan Kontraktor (Mobilization Readiness)', 4, 'Sangat Bagus', 0.1000, 0.4000, 'TIDAK ADA MASALAH DI LAPANGAN');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=5), 5, 'Kesesuaian/ Kualitas Hasil Pekerjaan', 'Kesesuaian/ Kualitas Hasil Pekerjaan', 4, 'Sangat Bagus', 0.1000, 0.4000, 'SESUAI KESEPAKATAN');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=6), 6, 'Defect List Diperbaiki Sesuai Batas Waktu', 'Defect List Diperbaiki Sesuai Batas Waktu', 4, 'Sangat Bagus', 0.1000, 0.4000, 'TIDAK ADA DEPECT');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=7), 7, 'K3, HSE dan Kebersihan', 'K3, HSE dan Kebersihan', 1, 'Tidak Bagus', 0.0500, 0.0500, 'HSE K3 TIDAK MENDUKUNG & KEBERSIHAN CUKUP');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=8), 8, 'Jumlah Surat Peringatan (SP)', 'Jumlah Surat Peringatan (SP)', 4, 'Sangat Bagus', 0.1000, 0.4000, 'TIDAK ADA SP');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=9), 9, 'Respon Terhadap SI saat Fitout Berlangsung', 'Respon Terhadap SI saat Fitout Berlangsung', 4, 'Sangat Bagus', 0.0500, 0.2000, 'SESUAI KESEPAKATN');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=10), 10, 'Administrasi', 'Administrasi', 4, 'Sangat Bagus', 0.0500, 0.2000, 'RAPI');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=11), 11, 'Kesimpulan', 'Ceritakan ringkasan kinerja dan isu vendor dalam performance ini', NULL, '', 0.0000, 0.0000, 'HSE K3 HARUS DITINGKATKAN');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=12), 12, 'Nama', 'Silahkan isi nama', NULL, 'MULKI TAMAM', 0.0000, 0.0000, 'MULKI TAMAM');

-- pivot row 29: 1002001321 PT. HUTAMAYASA KREASI
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('4200278505', '1002001321 PT. HUTAMAYASA KREASI', 'Ade Denda', @qs11, NULL, NULL, 2.9000, 'B', 'submitted', '2026-07-18 09:43:28');
SET @sub_id := LAST_INSERT_ID();
-- (no per-question answers -- source data didn't reconcile with the pivot total, see caveat above)

-- pivot row 30: 1002010227 PT. MODELATAMA BANTENG SEJATI
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('4200284140', '1002010227 PT. MODELATAMA BANTENG SEJATI', 'ADE DENDA', @qs11, NULL, NULL, 2.2500, 'C', 'submitted', '2026-07-18 10:09:56');
SET @sub_id := LAST_INSERT_ID();
-- (no per-question answers -- source data didn't reconcile with the pivot total, see caveat above)

-- pivot row 28: 1002000732 PT. AIRTEKINDO PRIMA / 1002014312 PT. TICA DAIWA INDONESIA
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('4200284139', '1002000732 PT. AIRTEKINDO PRIMA / 1002014312 PT. TICA DAIWA INDONESIA', 'ade', @qs11, NULL, NULL, 2.9000, 'B', 'submitted', '2026-07-18 10:16:33');
SET @sub_id := LAST_INSERT_ID();
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=1), 1, 'Ketersediaan Time Schedule', 'Ketersediaan Time Schedule', 1, 'Tidak Bagus', 0.1000, 0.1000, 'tidak ada TS');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=2), 2, 'Ketersediaan Pengawas', 'Ketersediaan Pengawas', 3, 'Bagus', 0.1000, 0.3000, 'pic datang 3 x selebih nya mandor');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=3), 3, 'Waktu Penyelesaian Pekerjaan', 'Waktu Penyelesaian Pekerjaan', 3, 'Bagus', 0.2500, 0.7500, 'on time');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=4), 4, 'Kesiapan Kontraktor (Mobilization Readiness)', 'Kesiapan Kontraktor (Mobilization Readiness)', 3, 'Bagus', 0.1000, 0.3000, 'sesuai');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=5), 5, 'Kesesuaian/ Kualitas Hasil Pekerjaan', 'Kesesuaian/ Kualitas Hasil Pekerjaan', 3, 'Bagus', 0.1000, 0.3000, 'tidak ada defect');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=6), 6, 'Defect List Diperbaiki Sesuai Batas Waktu', 'Defect List Diperbaiki Sesuai Batas Waktu', 4, 'Sangat Bagus', 0.1000, 0.4000, '-');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=7), 7, 'K3, HSE dan Kebersihan', 'K3, HSE dan Kebersihan', 3, 'Bagus', 0.0500, 0.1500, 'mengikuti');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=8), 8, 'Jumlah Surat Peringatan (SP)', 'Jumlah Surat Peringatan (SP)', 3, 'Bagus', 0.1000, 0.3000, '-');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=9), 9, 'Respon Terhadap SI saat Fitout Berlangsung', 'Respon Terhadap SI saat Fitout Berlangsung', 3, 'Bagus', 0.0500, 0.1500, 'respon cepat');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=10), 10, 'Administrasi', 'Administrasi', 3, 'Bagus', 0.0500, 0.1500, 'on time');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=11), 11, 'Kesimpulan', 'Ceritakan ringkasan kinerja dan isu vendor dalam performance ini', NULL, '', 0.0000, 0.0000, 'tidak buat TS untuk yang lain nya baik');
INSERT INTO vendor_eval_answers (submission_id, question_id, question_no, criterion_name, question_text, score, selected_score_label, weight, weighted_score, comment)
VALUES (@sub_id, (SELECT id FROM vendor_eval_questions WHERE question_set_id=@qs11 AND question_no=12), 12, 'Nama', 'Silahkan isi nama', NULL, 'ade', 0.0000, 0.0000, 'ade');

-- pivot row 26: 1002001321 PT. HUTAMAYASA KREASI
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('4200271321', '1002001321 PT. HUTAMAYASA KREASI', 'ADE', @qs11, NULL, NULL, 2.9000, 'B', 'submitted', '2026-07-18 10:26:37');
SET @sub_id := LAST_INSERT_ID();
-- (no per-question answers -- source data didn't reconcile with the pivot total, see caveat above)

-- pivot row 27: 1002010227 PT. MODELATAMA BANTENG SEJATI
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('4200271725', '1002010227 PT. MODELATAMA BANTENG SEJATI', 'ade', @qs11, NULL, NULL, 2.6500, 'C', 'submitted', '2026-07-18 10:29:47');
SET @sub_id := LAST_INSERT_ID();
-- (no per-question answers -- source data didn't reconcile with the pivot total, see caveat above)

-- pivot row 25: 1002000732 PT. AIRTEKINDO PRIMA / 1002014312 PT. TICA DAIWA INDONESIA
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('4200271728', '1002000732 PT. AIRTEKINDO PRIMA / 1002014312 PT. TICA DAIWA INDONESIA', 'ade', @qs11, NULL, NULL, 3.5000, 'A', 'submitted', '2026-07-18 10:36:47');
SET @sub_id := LAST_INSERT_ID();
-- (no per-question answers -- source data didn't reconcile with the pivot total, see caveat above)

-- pivot row 37: 1002001321 PT. HUTAMAYASA KREASI
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('4200278319', '1002001321 PT. HUTAMAYASA KREASI', 'Benny', @qs11, NULL, NULL, 3.5500, 'A', 'submitted', '2026-07-18 15:14:57');
SET @sub_id := LAST_INSERT_ID();
-- (no per-question answers -- source data didn't reconcile with the pivot total, see caveat above)

-- pivot row 38: 1002010227 PT. MODELATAMA BANTENG SEJATI
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('LEGACY-RETAIL-0025', '1002010227 PT. MODELATAMA BANTENG SEJATI', 'Benny', @qs11, NULL, NULL, 2.4500, 'C', 'submitted', '2026-07-18 15:19:02');
SET @sub_id := LAST_INSERT_ID();
-- (no per-question answers -- source data didn't reconcile with the pivot total, see caveat above)

-- pivot row 36: 1002000732 PT. AIRTEKINDO PRIMA / 1002014312 PT. TICA DAIWA INDONESIA
INSERT INTO vendor_eval_submissions
  (po_number, vendor_name, requestor_name, question_set_id, taxonomy_level_2, taxonomy_level_3,
   total_score, score_band, status, submitted_at)
VALUES
  ('4200278686', '1002000732 PT. AIRTEKINDO PRIMA / 1002014312 PT. TICA DAIWA INDONESIA', 'Benny', @qs11, NULL, NULL, 2.7500, 'C', 'submitted', '2026-07-18 15:22:54');
SET @sub_id := LAST_INSERT_ID();
-- (no per-question answers -- source data didn't reconcile with the pivot total, see caveat above)

COMMIT;
