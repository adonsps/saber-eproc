-- ====================================================================
-- Vendor Evaluation v2 — Form 2 Operations-team question set
--   VE_PROJECT_RETAIL_OPS_V1
--
-- Apply AFTER vendor_eval_v2_01_schema.sql / 02_seed_business_data.sql /
-- 03_seed_requestor_contacts.sql / 04_po_question_set_override.sql /
-- 05_form2_dual_submissions.sql / 05_po_item_category_override.sql /
-- 06_form2_team_contacts.sql (see db_backups/vendor_eval_v2_deployment_
-- handover.txt for the authoritative apply order — note that doc is stale,
-- it does not mention 05_po_item_category_override.sql either; this file
-- is simply the next unused number, 07).
--
-- Source: "2026_Contractor_Evaluation_Form_Retail_Operation_POV.xlsx"
-- (business-provided 2026-08-31). This is a DISTINCT rubric from the
-- existing VE_PROJECT_RETAIL_V1 set — several criteria are new
-- ("PARAMETER BARU (Operation)" in the source: merchandising impact
-- during works, store/ops coordination, grand-opening readiness,
-- post-handover complaint response) and several of VE_PROJECT_RETAIL_V1's
-- criteria (Ketersediaan Time Schedule/Pengawas, Kesiapan Kontraktor,
-- Jumlah Surat Peringatan, Administrasi) are dropped. Weights differ too.
-- Sum of weighted criteria = 1.0000.
--
-- Scope: used ONLY for team='ops' submissions on Form 2
-- (workspace/pages/public_eval_v2_project.php dispatches team='ops' to
-- this set_code, team='project' keeps using VE_PROJECT_RETAIL_V1
-- unchanged). Loaded via ve2_load_forced_set() — a plain
-- `WHERE set_code = ? AND is_active = 1` lookup — so this set
-- intentionally gets NO row in vendor_eval_question_set_taxonomy_map:
-- Form 1's resolveQuestionSet() only ever joins through that map table,
-- so omitting the map row is what keeps this set unreachable from Form 1's
-- L3/L2/Default routing chain (mirrors how VE_PROJECT_RETAIL_V1 itself is
-- excluded from the L2 branch for Form 2's PGroups, just automatically).
--
-- Question layout mirrors VE_PROJECT_RETAIL_V1's convention:
--   - 9 weighted rating questions (question_no 1-9), generic score labels
--     (Tidak Bagus/Kurang Bagus/Bagus/Sangat Bagus) with the full rubric
--     text from the source per score level in score_N_description, and
--     the source's evaluator guidance notes (column K) carried into
--     question_help_text where present. comment_required=1 on all 9,
--     matching VE_PROJECT_RETAIL_V1's convention of requiring supporting
--     evidence per criterion.
--   - question_no 10: free-text "additional notes" (source: "Keterangan
--     tambahan dari Operation (jika ada)") — optional, question_type='text'.
--   - question_no 11: "Nama" identification field (who is filling the
--     form), question_section='identification' — same pattern as
--     VE_PROJECT_RETAIL_V1's question 12.
--
-- IDEMPOTENCY: safe to re-run. Set matched on (set_code, version)
-- [uq_set_code_version]; questions on (question_set_id, question_no)
-- [uq_set_question_no]. Re-running updates existing rows in place.
--
-- Apply with (after verifying identity):
--   mysql -h HOST -u USER -p DATABASE < db_backups/vendor_eval_v2_07_form2_ops_question_set.sql
-- ====================================================================

START TRANSACTION;

INSERT INTO `vendor_eval_question_sets`
  (`set_code`, `set_name`, `version`, `is_active`, `description`)
VALUES
  ('VE_PROJECT_RETAIL_OPS_V1', 'Vendor Evaluation Form (Project - Retail, Operation POV)', 1, 1,
   'Contractor Evaluation Form (Retail) - Operation POV. Used for team=ops submissions on Form 2 (New Store). Source: 2026_Contractor_Evaluation_Form_Retail_Operation_POV.xlsx.')
ON DUPLICATE KEY UPDATE
  set_name = VALUES(set_name), is_active = VALUES(is_active), description = VALUES(description);

SET @qs_ops := (SELECT id FROM vendor_eval_question_sets WHERE set_code = 'VE_PROJECT_RETAIL_OPS_V1' AND version = 1);

INSERT INTO `vendor_eval_questions`
  (`question_set_id`, `question_no`, `criterion_name`, `question_text`, `weight`,
   `score_1_label`, `score_2_label`, `score_3_label`, `score_4_label`,
   `comment_required`, `is_active`, `question_type`, `question_section`, `input_type`,
   `score_1_description`, `score_2_description`, `score_3_description`, `score_4_description`,
   `comment_prompt`, `question_help_text`, `question_image_path`)
VALUES
  (@qs_ops, 1, 'Waktu Penyelesaian Pekerjaan', 'Waktu Penyelesaian Pekerjaan', 0.2000,
   'Tidak Bagus', 'Kurang Bagus', 'Bagus', 'Sangat Bagus', 1, 1, 'rating', 'evaluation', 'text',
   'Terlambat tidak sesuai SPK',
   'Sesuai SPK namun ada perbaikan (defect)',
   'Seluruh pekerjaan diselesaikan tepat sesuai SPK (tanpa defect)',
   'Seluruh pekerjaan diselesaikan lebih cepat dari jadwal SPK (tanpa defect)',
   'Ceritakan poin / bukti pendukung alasan pemilihan nilai tersebut',
   'Pekerjaan selesai tapi tidak sesuai standard -> deffect. Pekerjaan dianggap selesai jika sudah dikerjakan sesuai scope of work dan sesuai BA dari team project kepada kontraktor. Waktu selesai berdampak langsung ke jadwal opening toko.',
   NULL),

  (@qs_ops, 2, 'Dampak Terhadap Merchandising Toko Selama Pekerjaan Berlangsung', 'Dampak Terhadap Merchandising Toko Selama Pekerjaan Berlangsung', 0.1500,
   'Tidak Bagus', 'Kurang Bagus', 'Bagus', 'Sangat Bagus', 1, 1, 'rating', 'evaluation', 'text',
   'Pekerjaan sangat mengganggu merchandising toko (merchandising toko dibatasi signifikan) tanpa koordinasi sebelumnya dengan tim store',
   'Pekerjaan mengganggu operasional (bising, debu, akses pelanggan terhambat) dan menimbulkan komplain, koordinasi minim',
   'Ada gangguan minor terhadap merchandising, namun sudah dikoordinasikan sebelumnya dengan tim store',
   'Tidak ada gangguan terhadap merchandising toko, pekerjaan dilakukan di luar jam operasional/area kerja disterilkan dari akses pelanggan',
   'Ceritakan poin / bukti pendukung alasan pemilihan nilai tersebut',
   'Dinilai berdasarkan laporan/komplain dari tim store selama masa pekerjaan berlangsung (kebisingan, debu, akses).',
   NULL),

  (@qs_ops, 3, 'Koordinasi Dengan Tim Store/Operasional', 'Koordinasi Dengan Tim Store/Operasional', 0.0500,
   'Tidak Bagus', 'Kurang Bagus', 'Bagus', 'Sangat Bagus', 1, 1, 'rating', 'evaluation', 'text',
   'Tidak pernah berkoordinasi, informasi pekerjaan diketahui tim store secara mendadak/last minute',
   'Ada koordinasi namun sering terlambat atau informasi tidak lengkap',
   'Koordinasi rutin dan tepat waktu (jadwal, progress, kebutuhan akses) sesuai kesepakatan',
   'Proaktif menginformasikan progress & kendala, selalu responsif terhadap kebutuhan tim store',
   'Ceritakan poin / bukti pendukung alasan pemilihan nilai tersebut',
   'Mencakup koordinasi jadwal kerja, kebutuhan akses, dan update progress pekerjaan ke tim store.',
   NULL),

  (@qs_ops, 4, 'Kesesuaian/Kualitas Hasil Pekerjaan', 'Kesesuaian/Kualitas Hasil Pekerjaan', 0.2000,
   'Tidak Bagus', 'Kurang Bagus', 'Bagus', 'Sangat Bagus', 1, 1, 'rating', 'evaluation', 'text',
   'Saat opening ada major deffect',
   'Saat opening ada deffect yg dapat diselesaikan, tidak sesuai dengan design, namun masih acc team site',
   'Saat opening ada minor deffect yg dapat diselesaikan sesuai dengan design',
   'Design & spesifikasi material sesuai, saat opening tanpa deffect',
   'Ceritakan poin / bukti pendukung alasan pemilihan nilai tersebut',
   'Minor deffect: tidak mengganggu operasional dan tidak mengganggu branding (gate + logo).',
   NULL),

  (@qs_ops, 5, 'Kesiapan Area Saat Grand Opening', 'Kesiapan Area Saat Grand Opening', 0.1500,
   'Tidak Bagus', 'Kurang Bagus', 'Bagus', 'Sangat Bagus', 1, 1, 'rating', 'evaluation', 'text',
   'Banyak item belum siap saat opening (utilitas/listrik/AC bermasalah, signage/branding belum terpasang), toko tidak bisa buka sesuai jadwal',
   'Sebagian item belum siap, ada beberapa item menyusul setelah opening dan berdampak ke operasional',
   'Hampir seluruh item siap, minor item menyusul cepat tanpa mengganggu operasional',
   '100% siap (utilitas, signage/branding, VM), toko dapat langsung beroperasi normal sesuai jadwal',
   'Ceritakan poin / bukti pendukung alasan pemilihan nilai tersebut',
   'Dinilai pada H-1 s/d hari opening toko.',
   NULL),

  (@qs_ops, 6, 'Defect List Diperbaiki Sesuai Batas Waktu', 'Defect List Diperbaiki Sesuai Batas Waktu', 0.0500,
   'Tidak Bagus', 'Kurang Bagus', 'Bagus', 'Sangat Bagus', 1, 1, 'rating', 'evaluation', 'text',
   'Diselesaikan >21 hari',
   'Diselesaikan 15 - 21 hari',
   'Diselesaikan 8 - 14 hari',
   'Diselesaikan 0 - 7 hari',
   'Ceritakan poin / bukti pendukung alasan pemilihan nilai tersebut',
   'Diselesaikan maks H+14 hari dari Opening Store.',
   NULL),

  (@qs_ops, 7, 'K3, HSE dan Kebersihan', 'K3, HSE dan Kebersihan', 0.0500,
   'Tidak Bagus', 'Kurang Bagus', 'Bagus', 'Sangat Bagus', 1, 1, 'rating', 'evaluation', 'text',
   'Tidak mengikuti ketentuan K3/HSE yang disepakati di SPK',
   'Mengikuti tapi tidak konsisten',
   'Mengikuti dengan konsisten',
   'Selalu aman, area sangat rapi dan bersih, cepat tangani risiko, pekerjaan berjalan lancar tanpa gangguan, komunikasi sangat bagus.',
   'Ceritakan poin / bukti pendukung alasan pemilihan nilai tersebut',
   NULL,
   NULL),

  (@qs_ops, 8, 'Respon Terhadap SI Saat Fitout Berlangsung', 'Respon Terhadap SI Saat Fitout Berlangsung', 0.1000,
   'Tidak Bagus', 'Kurang Bagus', 'Bagus', 'Sangat Bagus', 1, 1, 'rating', 'evaluation', 'text',
   'Tidak mau menyesuaikan perubahan pekerjaan',
   'Respon terhadap SI lambat (lbh dari 2 hari kerja)',
   'Respon terhadap SI, kurang dari 2 hari, pelaksanaan lbh lambat dari jadwal',
   'Respon terhadap SI, kurang dari 2 hari, pelaksanaan pekerjaan sesuai jadwal',
   'Ceritakan poin / bukti pendukung alasan pemilihan nilai tersebut',
   'Respon: memasukkan jadwal pelaksanaan SI dan penawaran harga.',
   NULL),

  (@qs_ops, 9, 'Respon Terhadap Komplain Operasional Pasca Opening/Handover', 'Respon Terhadap Komplain Operasional Pasca Opening/Handover', 0.0500,
   'Tidak Bagus', 'Kurang Bagus', 'Bagus', 'Sangat Bagus', 1, 1, 'rating', 'evaluation', 'text',
   'Tidak responsif terhadap komplain/isu operasional (>7 hari kerja atau tidak ditindaklanjuti)',
   'Respon 4 - 7 hari kerja',
   'Respon 1 - 3 hari kerja',
   'Respon <24 jam / same day, isu langsung ditindaklanjuti hingga selesai',
   'Ceritakan poin / bukti pendukung alasan pemilihan nilai tersebut',
   'Komplain di luar defect list yang sudah tercatat saat opening (lihat poin VI).',
   NULL),

  (@qs_ops, 10, 'Keterangan Tambahan', 'Keterangan tambahan dari Operation (jika ada)', 0.0000,
   '', '', '', '', 0, 1, 'text', 'evaluation', 'text',
   NULL, NULL, NULL, NULL, NULL, NULL, NULL),

  (@qs_ops, 11, 'Nama', 'Silahkan isi nama', 0.0000,
   '', '', '', '', 1, 1, 'text', 'identification', 'text',
   NULL, NULL, NULL, NULL, NULL, NULL, NULL)

ON DUPLICATE KEY UPDATE
  criterion_name = VALUES(criterion_name), question_text = VALUES(question_text),
  weight = VALUES(weight), score_1_label = VALUES(score_1_label), score_2_label = VALUES(score_2_label),
  score_3_label = VALUES(score_3_label), score_4_label = VALUES(score_4_label),
  comment_required = VALUES(comment_required), is_active = VALUES(is_active),
  question_type = VALUES(question_type), question_section = VALUES(question_section),
  input_type = VALUES(input_type),
  score_1_description = VALUES(score_1_description), score_2_description = VALUES(score_2_description),
  score_3_description = VALUES(score_3_description), score_4_description = VALUES(score_4_description),
  comment_prompt = VALUES(comment_prompt), question_help_text = VALUES(question_help_text),
  question_image_path = VALUES(question_image_path);

COMMIT;

-- End of seed. Set=1 (VE_PROJECT_RETAIL_OPS_V1) Questions=11 (9 rated + 1 text + 1 identification)
-- Sum of rated weights = 1.0000 (0.20+0.15+0.05+0.20+0.15+0.05+0.05+0.10+0.05)
