-- AON contract numbers (No PPH) — backfill for aon_skus.contract_no
--
-- Source: STC contract register, 17 live 2026 contracts (screenshot handover,
-- 2026-08-19). The contract_no COLUMN itself is created by the guarded
-- auto-migrate block in workspace/config/database.php — this file only carries
-- the DATA, and the same statements are mirrored by the 'aon_contract_no_2026'
-- auto-seed block in that file so every environment picks them up on deploy
-- without anyone running SQL by hand.
--
-- Matching rule (as agreed): vendor name AND PIC must BOTH match. A SKU that is
-- still unclaimed (handler NULL/'') is deliberately NOT filled — an unclaimed
-- SKU has no owner to attribute the contract to, and Master AON's row editor is
-- the place to set it once someone claims it.
--
-- Vendor is matched on a distinctive substring rather than the full string
-- because the register writes "PT Galva" while aon_skus stores "PT. GALVA" —
-- the punctuation and the Tbk/legal suffixes do not agree between the two.
-- PIC is matched on a prefix: the register writes "Steinner" / "Lily" where
-- aon_skus stores "Steinner Kiamidi" / "Lilyawati Widjaja".
--
-- Every statement is re-runnable: it only writes rows whose contract_no is
-- still empty, so a manual correction made in Master AON is never overwritten.
--
-- Apply order: standalone, no dependencies beyond the aon_skus table itself.

-- 1. PT GALVA TECHNOLOGIES Tbk — Sound System — exp 30 Jun 2027
UPDATE aon_skus SET contract_no = 'PPH/1306/GP/KLG/VII/2026'
 WHERE is_deleted = 0 AND (contract_no IS NULL OR contract_no = '')
   AND vendor LIKE '%GALVA TECHNOLOGIES%' AND handler LIKE 'Steinner%';

-- 2. PT Toffin Indonesia
UPDATE aon_skus SET contract_no = 'PPH/1188/GP/KLG/VI/2026'
 WHERE is_deleted = 0 AND (contract_no IS NULL OR contract_no = '')
   AND vendor LIKE '%TOFFIN%' AND handler LIKE 'Steinner%';

-- 3. PT Sarana Mulia Santoso
UPDATE aon_skus SET contract_no = 'PPH/1209/GP/KLG/VI/2026'
 WHERE is_deleted = 0 AND (contract_no IS NULL OR contract_no = '')
   AND vendor LIKE '%SARANA MULIA SANTOSO%' AND handler LIKE 'Steinner%';

-- 4. Metta Novalina — Equipment SS
UPDATE aon_skus SET contract_no = 'PPH/1023/GP/KLG/V/2026'
 WHERE is_deleted = 0 AND (contract_no IS NULL OR contract_no = '')
   AND vendor LIKE '%METTA NOVALINA%' AND handler LIKE 'Steinner%';

-- 5. CV Bintang Citra Jaya — Equipment SS
UPDATE aon_skus SET contract_no = 'PPH/1021/GP/KLG/V/2026'
 WHERE is_deleted = 0 AND (contract_no IS NULL OR contract_no = '')
   AND vendor LIKE '%BINTANG CITRA JAYA%' AND handler LIKE 'Steinner%';

-- 6. PT Indometic — Equipment SS
UPDATE aon_skus SET contract_no = 'PPH/1022/GP/KLG/V/2026'
 WHERE is_deleted = 0 AND (contract_no IS NULL OR contract_no = '')
   AND vendor LIKE '%INDOMETIC%' AND handler LIKE 'Steinner%';

-- 7. PT Indika Tirta Mandiri — Filter Air
UPDATE aon_skus SET contract_no = 'PPH/1103/GP/KLG/VI/2026'
 WHERE is_deleted = 0 AND (contract_no IS NULL OR contract_no = '')
   AND vendor LIKE '%INDIKA TIRTA MANDIRI%' AND handler LIKE 'Steinner%';

-- 8. PT Fasco Sanwira
UPDATE aon_skus SET contract_no = 'PPH/1205/GP/KLG/VI/2026'
 WHERE is_deleted = 0 AND (contract_no IS NULL OR contract_no = '')
   AND vendor LIKE '%FASCO SANWIRA%' AND handler LIKE 'Steinner%';

-- 9. PT Wellen Brothers
UPDATE aon_skus SET contract_no = 'PPH/1334/STC/KLG/VII/2026'
 WHERE is_deleted = 0 AND (contract_no IS NULL OR contract_no = '')
   AND vendor LIKE '%WELLEN BROTHERS%' AND handler LIKE 'Lily%';

-- 10. PT Bumi Media Kreasindo
UPDATE aon_skus SET contract_no = 'PPH/1336/STC/KLG/VII/2026'
 WHERE is_deleted = 0 AND (contract_no IS NULL OR contract_no = '')
   AND vendor LIKE '%BUMI MEDIA KREASINDO%' AND handler LIKE 'Lily%';

-- 11. PT Sugih Perkasa Advertising
UPDATE aon_skus SET contract_no = 'PPH/1098/STC/KLG/VI/2026'
 WHERE is_deleted = 0 AND (contract_no IS NULL OR contract_no = '')
   AND vendor LIKE '%SUGIH PERKASA%' AND handler LIKE 'Lily%';

-- 12. PT Nusantara Mandiri Grafika — exp 31 Jan 2027
UPDATE aon_skus SET contract_no = 'PPH/1340/STC/KLG/VII/2026'
 WHERE is_deleted = 0 AND (contract_no IS NULL OR contract_no = '')
   AND vendor LIKE '%NUSANTARA MANDIRI GRAFIKA%' AND handler LIKE 'Lily%';

-- 13. PT Bino Mitra Sejati — exp 31 Jan 2027
UPDATE aon_skus SET contract_no = 'PPH/1362/STC/KLG/VII/2026'
 WHERE is_deleted = 0 AND (contract_no IS NULL OR contract_no = '')
   AND vendor LIKE '%BINO MITRA SEJATI%' AND handler LIKE 'Lily%';

-- 14. PT Gunung Madu Solution
UPDATE aon_skus SET contract_no = 'PPH/985/PROJECT PROP/KLG/V/2026'
 WHERE is_deleted = 0 AND (contract_no IS NULL OR contract_no = '')
   AND vendor LIKE '%GUNUNG MADU%' AND handler LIKE 'Steinner%';

-- 15. PT Hoshizaki Indonesia
UPDATE aon_skus SET contract_no = 'PPH/1024/GP/KLG/V/2026'
 WHERE is_deleted = 0 AND (contract_no IS NULL OR contract_no = '')
   AND vendor LIKE '%HOSHIZAKI%' AND handler LIKE 'Steinner%';

-- 16. PT Intimuara Electrindo
UPDATE aon_skus SET contract_no = 'PPH/1001/PROJECT RETAIL/KLG/V/2026'
 WHERE is_deleted = 0 AND (contract_no IS NULL OR contract_no = '')
   AND vendor LIKE '%INTIMUARA%' AND handler LIKE 'Steinner%';

-- 17. PT Sinar Metrindo Perkasa
--     aon_skus spells this "PT SINAR METRINDO PEKASA" (missing R), so the key
--     stops at METRIND to catch both spellings.
UPDATE aon_skus SET contract_no = 'PPH/982/PROJECT RETAIL/KLG/V/2026'
 WHERE is_deleted = 0 AND (contract_no IS NULL OR contract_no = '')
   AND vendor LIKE '%SINAR METRIND%' AND handler LIKE 'Steinner%';
