-- ====================================================================
-- Vendor Evaluation v2 — Requestor Contact Seed (03 of 3)
--
-- SENSITIVE: contains employee contact data. User approved committing
-- for team deployment.
--
-- Apply LAST, after vendor_eval_v2_01_schema.sql and
-- vendor_eval_v2_02_seed_business_data.sql.
--
-- Contents: 53 requestor contact UPDATEs (email, whatsapp_number,
-- manager_email, manager_username where non-empty), generated
-- 2026-07-06 from local DB u894129685_workspace, programmatically from
-- live rows. Matched on `requestor_name` (UNIQUE key on `requestors`) —
-- rows must already exist (created by the base requestors import); this
-- file only refreshes contact columns.
--
-- Does NOT include:
--   - public_token (per-requestor secret; never exported)
--   - passwords or any users table data
--   - any column not listed above
--
-- Known caveats (see memory: requestor contact backfill 2026-07-03):
--   - 2 rows are resignation placeholders, not contact-ready.
--   - 6 rows fall back to the Adonis default contact (no personal
--     contact captured yet for those requestors).
--
-- Idempotent: UPDATE by requestor_name; safe to re-run. Rows for
-- requestor_names not present on the target are silently no-ops (0 rows
-- affected), not errors.
--
-- Apply with (after verifying identity):
--   mysql -h HOST -u USER -p DATABASE < db_backups/vendor_eval_v2_03_seed_requestor_contacts.sql
-- ====================================================================

START TRANSACTION;

UPDATE `requestors` SET
  `email` = 'ahmad.fadillah@homecenter.co.id',
  `whatsapp_number` = '6281296589658',
  `manager_email` = 'bertha.hapsari@homecenter.co.id'
WHERE `requestor_name` = 'AHMAD FADILLAH';

UPDATE `requestors` SET
  `email` = 'arif.widiyantoro@kawanlamacorp.com',
  `whatsapp_number` = '6281210721911',
  `manager_email` = 'ponijo.rim@kawanlamacorp.com'
WHERE `requestor_name` = 'ARIF WIDIYANTORO.';

UPDATE `requestors` SET
  `email` = 'aris.yulianto@livingworld.co.id',
  `whatsapp_number` = '6285779699661',
  `manager_email` = 'cornelius.singal@livingworld.co.id'
WHERE `requestor_name` = 'ARIS YULIANTO';

UPDATE `requestors` SET
  `email` = 'clara.mario@ruparupa.com',
  `whatsapp_number` = '6287877115621',
  `manager_email` = 'maulana.christanto@ruparupa.com'
WHERE `requestor_name` = 'CLARA ROSALINE MARIO';

UPDATE `requestors` SET
  `email` = 'dahnil.mardian@kawanlamacorp.com',
  `whatsapp_number` = '6285219099779',
  `manager_email` = 'yuuki.renaldi@kawanlamacorp.com'
WHERE `requestor_name` = 'DAHNIL MARDIAN';

UPDATE `requestors` SET
  `email` = 'dahyani.prm@kawanlama.com',
  `whatsapp_number` = '62817209700',
  `manager_email` = 'devi.prm@kawanlama.com'
WHERE `requestor_name` = 'DAHYANI';

UPDATE `requestors` SET
  `email` = 'yasni.made@livingworld.co.id',
  `whatsapp_number` = '6281398585816',
  `manager_email` = 'cristian.suryanto@livingworld.co.id'
WHERE `requestor_name` = 'DESAK MADE MEYASNI';

UPDATE `requestors` SET
  `email` = 'dessy.taniwan@kawanlamacorp.com',
  `whatsapp_number` = '6289652082491',
  `manager_email` = 'patricia.hidris@kawanlamacorp.com'
WHERE `requestor_name` = 'DESSY TANIWAN';

UPDATE `requestors` SET
  `email` = 'dhesta.dhanisia@livingplaza.co.id',
  `whatsapp_number` = '6282110690104',
  `manager_email` = 'dhesta.dhanisia@livingplaza.co.id'
WHERE `requestor_name` = 'DHESTA DHANISIA';

UPDATE `requestors` SET
  `email` = 'adonis.sutanto@kawanlamacorp.com',
  `whatsapp_number` = '6281232934734',
  `manager_email` = 'adonis.sutanto@kawanlamacorp.com'
WHERE `requestor_name` = 'DIMAS OKI TRIANDIKA';

UPDATE `requestors` SET
  `email` = 'dian.natalia@kawanlamacorp.com',
  `whatsapp_number` = '6285881469158',
  `manager_email` = 'rizki.putra@kawanlamacorp.com'
WHERE `requestor_name` = 'E. DIAN NATALIA';

UPDATE `requestors` SET
  `email` = 'adonis.sutanto@kawanlamacorp.com',
  `whatsapp_number` = '6281232934734',
  `manager_email` = 'bertha.hapsari@homecenter.co.id'
WHERE `requestor_name` = 'ELSA DEBORA SIAGIAN';

UPDATE `requestors` SET
  `email` = 'erlinda.putri@fbindonesia.com',
  `whatsapp_number` = '6285712406454',
  `manager_email` = 'sarah.merci@fbindonesia.com'
WHERE `requestor_name` = 'ERLINDA VANIA PUTRI';

UPDATE `requestors` SET
  `email` = 'evelyn.gunawan@fbindonesia.com',
  `whatsapp_number` = '6289611321176',
  `manager_email` = 'setiawan.nugroho@fbindonesia.com'
WHERE `requestor_name` = 'EVELYN NATANIA GUNAWAN';

UPDATE `requestors` SET
  `email` = 'adonis.sutanto@kawanlamacorp.com',
  `whatsapp_number` = '6281232934734',
  `manager_email` = 'adonis.sutanto@kawanlamacorp.com'
WHERE `requestor_name` = 'FAUZI';

UPDATE `requestors` SET
  `email` = 'fransisca.karina@kawanlamaretail.com',
  `whatsapp_number` = '6281213419450',
  `manager_email` = 'vilka.dery@kawanlamaretail.com'
WHERE `requestor_name` = 'FRANSISCA AMELIA PUTRI KARINA';

UPDATE `requestors` SET
  `email` = 'gresia.hartyaningtyas@fbindonesia.com',
  `whatsapp_number` = '6282299564746',
  `manager_email` = 'muhammad.giffari@fbindonesia.com'
WHERE `requestor_name` = 'GRESIA YULI HARTYANINGTYAS';

UPDATE `requestors` SET
  `email` = 'hamdi.abdi@kawanlamacorp.com',
  `whatsapp_number` = '62817209700',
  `manager_email` = 'juanita.sareal@kawanlamaretail.com'
WHERE `requestor_name` = 'HAMDI ABDI';

UPDATE `requestors` SET
  `email` = 'herdi.kuswanto@krisbow.com',
  `whatsapp_number` = '6281314128811',
  `manager_email` = 'candra.kurniawan@krisbow.com'
WHERE `requestor_name` = 'HERDI KUSWANTO';

UPDATE `requestors` SET
  `email` = 'herman.herdyanto@homecenter.co.id',
  `whatsapp_number` = '6287788140027',
  `manager_email` = 'herman.herdyanto@homecenter.co.id'
WHERE `requestor_name` = 'HERMAN HERDYANTO';

UPDATE `requestors` SET
  `email` = 'hesti.rahayu@livingworld.co.id',
  `whatsapp_number` = '6282387672024',
  `manager_email` = 'bobby.abdullah@livingworld.co.id'
WHERE `requestor_name` = 'HESTI RAHAYU';

UPDATE `requestors` SET
  `email` = 'adonis.sutanto@kawanlamacorp.com',
  `whatsapp_number` = '6281232934734',
  `manager_email` = 'adonis.sutanto@kawanlamacorp.com'
WHERE `requestor_name` = 'I GUSTI AGUNG RIKE ARNA WAHYUNI';

UPDATE `requestors` SET
  `email` = 'ika.mustikawati@ahi.id',
  `whatsapp_number` = '6281586096003',
  `manager_email` = 'william.rettob@ahi.id'
WHERE `requestor_name` = 'IKA MUSTIKAWATI';

UPDATE `requestors` SET
  `email` = 'indiana.setiawan@fbindonesia.com',
  `whatsapp_number` = '6287890799191',
  `manager_email` = 'setiawan.nugroho@fbindonesia.com'
WHERE `requestor_name` = 'INDIANA JASMINE SETIAWAN';

UPDATE `requestors` SET
  `email` = 'indra.winata@kawanlamaretail.com',
  `whatsapp_number` = '62817209700',
  `manager_email` = 'juanita.sareal@kawanlamaretail.com'
WHERE `requestor_name` = 'INDRA WINATA';

UPDATE `requestors` SET
  `email` = 'irfan.rahmadi@kawanlamacorp.com',
  `whatsapp_number` = '628561688866',
  `manager_email` = 'satria.ardianto@kawanlamacorp.com'
WHERE `requestor_name` = 'IRFAN RAHMADI';

UPDATE `requestors` SET
  `email` = 'jessica.ayuh@kawanlamacorp.com',
  `whatsapp_number` = '62895402885914',
  `manager_email` = 'irfan.rahmadi@kawanlamacorp.com'
WHERE `requestor_name` = 'JESSICA ADELILA AYUH';

UPDATE `requestors` SET
  `email` = 'adonis.sutanto@kawanlamacorp.com',
  `whatsapp_number` = '6281232934734',
  `manager_email` = 'adonis.sutanto@kawanlamacorp.com'
WHERE `requestor_name` = 'JOVINUS ERNANDHA';

UPDATE `requestors` SET
  `email` = 'khoiron.gaf@kawanlamacorp.com',
  `whatsapp_number` = '62817209700',
  `manager_email` = 'yuuki.renaldi@kawanlamacorp.com'
WHERE `requestor_name` = 'KHOIRON';

UPDATE `requestors` SET
  `email` = 'kiki.widjayanti@toyskingdom.co.id',
  `whatsapp_number` = '6285697965448',
  `manager_email` = 'ingan.nugroho@toyskingdom.co.id'
WHERE `requestor_name` = 'KIKI WIDJAYANTI';

UPDATE `requestors` SET
  `email` = 'kurnia.annisa@livingworld.co.id',
  `whatsapp_number` = '6282288393232',
  `manager_email` = 'omri.andreas@livingworld.co.id'
WHERE `requestor_name` = 'KURNIA ANNISA';

UPDATE `requestors` SET
  `email` = 'adonis.sutanto@kawanlamacorp.com',
  `whatsapp_number` = '6281232934734',
  `manager_email` = 'rizki.putra@kawanlamacorp.com'
WHERE `requestor_name` = 'LANGGENG BASUKI.';

UPDATE `requestors` SET
  `email` = 'laura.sidauruk@kawanlamacorp.com',
  `whatsapp_number` = '6285268364965',
  `manager_email` = 'bernard.chiong@kawanlamaretail.com'
WHERE `requestor_name` = 'LAURA GISELLA SIDAURUK';

UPDATE `requestors` SET
  `email` = 'jonathan.it@kawanlamaretail.com',
  `whatsapp_number` = '6285204873333',
  `manager_email` = 'irfan.rahmadi@kawanlamacorp.com'
WHERE `requestor_name` = 'LIEM SIEK LIE.';

UPDATE `requestors` SET
  `email` = 'linda.manurung@kawanlamaretail.com',
  `whatsapp_number` = '62816752179',
  `manager_email` = 'adityo.prihandoko@kawanlamacorp.com'
WHERE `requestor_name` = 'LINDA MARISSA MANURUNG';

UPDATE `requestors` SET
  `email` = 'marisa.pardede@elitepeoplesolutions.com',
  `whatsapp_number` = '6281316037069',
  `manager_email` = 'hendra.zaputra@elitepeoplesolutions.com'
WHERE `requestor_name` = 'MARISA PARDEDE';

UPDATE `requestors` SET
  `email` = 'adonis.sutanto@kawanlamacorp.com',
  `whatsapp_number` = '6281232934734',
  `manager_email` = 'adonis.sutanto@kawanlamacorp.com'
WHERE `requestor_name` = 'MARSHA YUNITA GUNAWAN';

UPDATE `requestors` SET
  `email` = 'adonis.sutanto@kawanlamacorp.com',
  `whatsapp_number` = '6281232934734',
  `manager_email` = 'adonis.sutanto@kawanlamacorp.com'
WHERE `requestor_name` = 'MELI YANTI';

UPDATE `requestors` SET
  `email` = 'nadifa.baitis@livingworld.co.id',
  `whatsapp_number` = '6285880571440',
  `manager_email` = 'maria.adelita@livingworld.co.id'
WHERE `requestor_name` = 'NADIFA KAMELIA BAITIS';

UPDATE `requestors` SET
  `email` = 'ayu.megawati@livingworld.co.id',
  `whatsapp_number` = '62881037561808',
  `manager_email` = 'bobby.erliawan@livingworld.co.id'
WHERE `requestor_name` = 'NI LUH AYU MEGAWATI, S.E';

UPDATE `requestors` SET
  `email` = 'niki.sari@kawanlamacorp.com',
  `whatsapp_number` = '6281282732766',
  `manager_email` = 'rizki.putra@kawanlamacorp.com'
WHERE `requestor_name` = 'NIKI PURNAMA SARI';

UPDATE `requestors` SET
  `email` = 'paulina.widyawati@kawanlamacorp.com',
  `whatsapp_number` = '6281280647072',
  `manager_email` = 'rizki.putra@kawanlamacorp.com'
WHERE `requestor_name` = 'PAULINA WILLY WIDYAWATI';

UPDATE `requestors` SET
  `email` = 'puja.sihotang@livingworld.co.id',
  `whatsapp_number` = '6285358577894',
  `manager_email` = 'winny.wuryandari@livingplaza.co.id'
WHERE `requestor_name` = 'PUJA DIBA SIHOTANG.';

UPDATE `requestors` SET
  `email` = 'restipah.wulandari@livingworld.co.id',
  `whatsapp_number` = '6282111959061',
  `manager_email` = 'yayuk.sofamardiana@livingworld.co.id'
WHERE `requestor_name` = 'RESTIPAH WULANDARI';

UPDATE `requestors` SET
  `email` = 'ria.anjani@kawanlamacorp.com',
  `whatsapp_number` = '6285781546090',
  `manager_email` = 'irfan.rahmadi@kawanlamacorp.com'
WHERE `requestor_name` = 'RIA DWI ANJANI';

UPDATE `requestors` SET
  `email` = 'ribka.yuliana@kawanlamacorp.com',
  `whatsapp_number` = '6287886079767',
  `manager_email` = 'suharno.tan@kawanlamacorp.com'
WHERE `requestor_name` = 'RIBKA YULIANA SLAMET';

UPDATE `requestors` SET
  `email` = 'rizki.rinanda@livingworld.co.id',
  `whatsapp_number` = '6281364555004',
  `manager_email` = 'bobby.abdullah@livingworld.co.id'
WHERE `requestor_name` = 'RIZKI RINANDA';

UPDATE `requestors` SET
  `email` = 'sherla.leonardy@kawanlamacorp.com',
  `whatsapp_number` = '628988999597',
  `manager_email` = 'arif.solomon@kawanlamacorp.com'
WHERE `requestor_name` = 'SHERLA LEONARDY';

UPDATE `requestors` SET
  `email` = 'suparman.gaf@kawanlamacorp.com',
  `whatsapp_number` = '6289506898070',
  `manager_email` = 'rizki.putra@kawanlamacorp.com'
WHERE `requestor_name` = 'SUPARMAN';

UPDATE `requestors` SET
  `email` = 'suparni.ith@kawanlamacorp.com',
  `whatsapp_number` = '6281385944430',
  `manager_email` = 'chandra.kurniawan@kawanlamacorp.com'
WHERE `requestor_name` = 'SUPARNI';

UPDATE `requestors` SET
  `email` = 'vanidya.okstefiany@kawanlama-mfg.com',
  `whatsapp_number` = '6283812768205',
  `manager_email` = 'peter.pranadjaja@homecenter.co.id'
WHERE `requestor_name` = 'VANIDYA OKSTEFIANY';

UPDATE `requestors` SET
  `email` = 'widi.hartanto@kawanlamacorp.com',
  `whatsapp_number` = '6281210721911',
  `manager_email` = 'ponijo.rim@kawanlamacorp.com'
WHERE `requestor_name` = 'WIDI HARTANTO..';

UPDATE `requestors` SET
  `email` = 'yeti.ofpiyani@ahi.id',
  `whatsapp_number` = '628815321311',
  `manager_email` = 'yoelius.saputra@ahi.id'
WHERE `requestor_name` = 'YETI OFPIYANI';

COMMIT;

-- End of seed. Requestor contact rows updated: 53
