<?php
// includes/contract_maintenance_helper.php
//
// Facility/Property team's service & maintenance contract tracker (gas supply,
// HVAC, fire fighting, lift/escalator maintenance, cleaning, chemical
// treatment, etc.), replacing the manual "List Contract Maintenance.xlsx".
//
// business_unit, contract_type, item_category, output_type and site_area are
// app-validated dropdown strings, not FKs to any master table, since this
// codebase has none for any of them (see workspace/config/database.php
// comment on the contract_maintenance table).
//
// output_type can hold 'AON'. That is unrelated to the separate aon_skus /
// Always-On feature elsewhere in this codebase (aon_helper.php, master_aon.php),
// same abbreviation, confirmed by the team to be a different program in this
// context. Do not conflate the two.

/**
 * Access gate: Category Manager role, or one of the named Facility/Property
 * team members who aren't Category Manager. No team_name value distinguishes
 * this team in the users table, so it's a plain username allowlist.
 */
function contract_maintenance_can_access($role, $username) {
    if ((string)$role === 'Category Manager') {
        return true;
    }
    $allowed_usernames = [
        'hadi.risali',
        'rio.tandy',
        'ewaldo.tanton',
        'josia.marxalim',
        'keryn.thenniady',
        'levina.widjojo',
        'nadia.tjiptowardojo',
        'rory.dinzah',
    ];
    return in_array((string)$username, $allowed_usernames, true);
}

/**
 * 2026-09-09: absorbs what used to be the separate "Company" field
 * (contract_maintenance_companies(), now removed) -- the source spreadsheet
 * this feature replaced never had a separate company column; its BU column
 * mixed plain divisions (Property/F&B/KLG) with legal-entity/brand names
 * (e.g. "HCI", "TGI"), so the app now matches that with one merged list
 * instead of two fields writing to two columns for the same real-world
 * "which unit is this contract under" answer.
 */
function contract_maintenance_business_units() {
    return array_merge(['Property', 'Retail', 'F&B', 'KLG'], [
        'PT Eastpower Indonesia Eq', 'PT Golden Dracon', 'PT Everlight Indonesia',
        'PT Golden Living Indonesi', 'PT Golden Inovasi Indones', 'PT Golden Manufaktur Ind',
        'PT.Golden Shenzen Safety', 'PT Golden Excellent Safes', 'PT Golden Xinding Indones',
        'PT Tiga Dua Delapan', 'PT Graha Makmur Lestari', 'PT Anumana Graha Cantika',
        'PT Senang Main Lincah', 'PT Generasi Gelora Gemila', 'PT Citra Bumi Agung',
        'PT Arunika Duta Makmur', 'PT Aspirasi Hidup Indones', 'PT Home Center Indonesia',
        'PT Toys Games Indonesia', 'PT Omni Digitama Internus', 'PT Graha Satwa Paramita',
        'PT Krisna Kreasi Indonesi', 'PT HCI Retail', 'PT Dan Sebagainya Indones',
        'PT Graha Surya Paramita C', 'PT TGI Impor Sejahtera', 'PT Krisna Aspirasi Sejaht',
        'PT Multi Rentalindo', 'PT Solusi Layanan Terpadu', 'PT Graha Sewa Inovasi',
        'PT Dana Kini Indonesia', 'PT Dana Kini Finance', 'PT Alpha Gagas Indonesia',
        'PT Permata Griya Bumi', 'PT Foods Beverages Indone', 'PT Cupbop Indonesia Abadi',
        'PT Cita Rasa Pendopo', 'PT Jasmine Journey Indone', 'PT Kawan Lama Sejahtera',
        'PT Krisbow Indonesia', 'PT Kawan Lama Internusa', 'PT Global Tools Indonesia',
        'PT Emaro Online Indonesia', 'PT Andalan Solusi Kreatif', 'PT Indo Kompresigma',
        'PT Kawan Lama Inovasi', 'PT Sensor Indonesia', 'PT Depoteknik Duta Perkak',
        'PT Miller Weldindo', 'PT Kawan Lama Solusi', 'PT Kawan Lama Persada',
        'PT Kalden Indonesia',
    ]);
}

function contract_maintenance_categories() {
    return [
        'SA', 'Interior', 'HVAC', 'MEP', 'Fire Fighting', 'Flexi/Hoarding',
        'STP', 'Vertical Transportation', 'Gas', 'General Cleaning',
        'Service Mtc', 'Spareparts', 'Chemical',
    ];
}

function contract_maintenance_types() {
    return ['PKS', 'KHS'];
}

function contract_maintenance_output_types() {
    return ['PO', 'PPH', 'AON'];
}

/**
 * Starting Site/Area suggestions for the Select2 dropdown, pulled verbatim
 * from the team's spreadsheet (DATABASE sheet, SITE/STORE column), at the
 * user's explicit request to load all of it. The sheet visibly mixed named
 * outlets/properties (Go Go Curry, LP JABABEKA), region groupings (West
 * Area, Bali Kalimantan Maluku Sumatera Riau), and what reads as a
 * near-complete Indonesian kabupaten/kota list (Trenggalek, Wonosobo...), so
 * per the user's 2026-09-04 request this is now split in two: named
 * outlets/properties stay here as "Site" options, region groupings +
 * kabupaten/kota move to contract_maintenance_area_options() below as
 * "Area" options. The UI asks the user to pick Site or Area first (radio),
 * then shows the matching list -- but both still write into the single
 * free-text site_area column, so this split is presentation-only.
 * Like site_area itself, these are suggestion lists only (Select2 tags:true
 * lets a user type one not here), so an odd or unwanted entry costs nothing
 * beyond appearing in the dropdown once.
 */
function contract_maintenance_site_options() {
    return [
        'HG-ICF', 'WH',
        'Pet Kingdom', 'AZKO', 'Toys Kingdom', 'Pendopo',
        'Rumah Budaya Sumba', 'Ataru', 'KLO', 'Neka',
        'Warung Konco', 'M Stand', 'DC/Manufaktur', 'LP PURI',
        'LP JABABEKA', 'LP BALIKPAPAN', 'LW AS', 'LW PKU',
        'LW DPS', 'LW PIK', 'LP AYB', 'LP KHI',
        'LP Pondok Cabe', 'LP Palem Semi', 'LP Pamulang', 'LP Ciputat',
        'LP Bintaro', 'LP Cinere', 'LP Paskal', 'LP IBCC',
        'LP Sukabumi', 'LP Cianjur', 'LP Cirebon', 'LP Queen City',
        'LP Purwokerto', 'LP Lattanette', 'LP Perintis', 'LP Hertasning',
        'LP Pettarani', 'LP Manado', 'LP Kendari', 'LP Palu',
        'LP Sawojajar', 'LP Banjarmasin', 'LP Cemara Asri', 'LP Padang',
        'LP Palembang', 'LP Batam', 'LP Lampung Rajabasa', '-',
    ];
}

/**
 * "Area" suggestions for the Select2 dropdown: the region groupings and
 * near-complete Indonesian kabupaten/kota list from the same source sheet
 * as contract_maintenance_site_options() above -- see that function's
 * comment for the split rationale.
 */
function contract_maintenance_area_options() {
    return [
        'West Area', 'East Area', 'Banten Jawa Barat', 'Bali Kalimantan Maluku Sumatera Riau',
        'DKI Jakarta', 'Yogyakarta Jawa Tengah Jawa Timur', 'Bali Maluku', 'Banten Jakarta Jawa Barat',
        'Bengkulu Jambi Riau Kep. Riau Lampung Sumatera', 'Gorontalo Kalimantan Sulawesi', 'Cilacap Banyuwangi Manado', 'Cilacap Banyuwangi',
        'Bukit Tinggi Padang Medan', 'Bukit Tinggi Padang Rantau Prapat Pematang Siantar Tebing Tinggi',
        'Aceh Barat', 'Aceh Barat Daya', 'Aceh Besar', 'Aceh Jaya',
        'Aceh Selatan', 'Aceh Singkil', 'Aceh Tamiang', 'Aceh Tengah',
        'Aceh Tenggara', 'Aceh Timur', 'Aceh Utara', 'Agam',
        'Alor', 'Ambon', 'Asahan', 'Asmat',
        'Badung', 'Balangan', 'Balikpapan', 'Banda Aceh',
        'Bandar Lampung', 'Pangkalan Bun', 'Bandung', 'Bandung Barat',
        'Banggai', 'Banggai Kepulauan', 'Banggai Laut', 'Bangka',
        'Bangka Barat', 'Bangka Selatan', 'Bangka Tengah', 'Bangkalan',
        'Bangli', 'All Area', 'Banjar', 'Banjarbaru',
        'Banjarmasin', 'Banjarnegara', 'Bantaeng', 'Bantul',
        'Banyuasin', 'Banyumas', 'Banyuwangi', 'Barito Kuala',
        'Barito Selatan', 'Barito Timur', 'Barito Utara', 'Barru',
        'Batam', 'Batang', 'Batanghari', 'Batu',
        'Batu Bara', 'Baubau', 'Bekasi', 'Belitung',
        'Belitung Timur', 'Belu', 'Bener Meriah', 'Bengkalis',
        'Bengkayang', 'Bengkulu', 'Bengkulu Selatan', 'Bengkulu Tengah',
        'Bengkulu Utara', 'Berau', 'Biak Numfor', 'Bima',
        'Binjai', 'Bintan', 'Bireuen', 'Bitung',
        'Blitar', 'Blora', 'Boalemo', 'Bogor',
        'Bojonegoro', 'Bolaang Mongondow', 'Bolaang Mongondow Selatan', 'Bolaang Mongondow Timur',
        'Bolaang Mongondow Utara', 'Bombana', 'Bondowoso', 'Bone',
        'Bone Bolango', 'Bontang', 'Boven Digoel', 'Boyolali',
        'Brebes', 'Bukittinggi', 'Buleleng', 'Bulukumba',
        'Bulungan', 'Bungo', 'Buol', 'Buru',
        'Buru Selatan', 'Buton', 'Buton Selatan', 'Buton Tengah',
        'Buton Utara', 'Ciamis', 'Cianjur', 'Cilacap',
        'Cilegon', 'Cimahi', 'Cirebon', 'Dairi',
        'Deiyai', 'Deli Serdang', 'Demak', 'Denpasar',
        'Depok', 'Dharmasraya', 'Dogiyai', 'Dompu',
        'Donggala', 'Dumai', 'Empat Lawang', 'Ende',
        'Enrekang', 'Fakfak', 'Flores Timur', 'Garut',
        'Gayo Lues', 'Gianyar', 'Gorontalo', 'Gorontalo Utara',
        'Gowa', 'Gresik', 'Grobogan', 'Gunung Mas',
        'Gunungkidul', 'Gunungsitoli', 'Halmahera Barat', 'Halmahera Selatan',
        'Halmahera Tengah', 'Halmahera Timur', 'Halmahera Utara', 'Hulu Sungai Selatan',
        'Hulu Sungai Tengah', 'Hulu Sungai Utara', 'Humbang Hasundutan', 'Indragiri Hilir',
        'Indragiri Hulu', 'Indramayu', 'Intan Jaya', 'Jakarta Barat',
        'Jakarta Pusat', 'Jakarta Selatan', 'Jakarta Timur', 'Jakarta Utara',
        'Jambi', 'Jayapura', 'Jayawijaya', 'Jember',
        'Jembrana', 'Jeneponto', 'Jepara', 'Jombang',
        'Kaimana', 'Kampar', 'Kapuas', 'Kapuas Hulu',
        'Karanganyar', 'Karangasem', 'Karawang', 'Karimun',
        'Karo', 'Katingan', 'Kaur', 'Kayong Utara',
        'Kebumen', 'Kediri', 'Keerom', 'Kendal',
        'Kendari', 'Kepahiang', 'Kepulauan Anambas', 'Kepulauan Aru',
        'Kepulauan Mentawai', 'Kepulauan Meranti', 'Kepulauan Sangihe', 'Kepulauan Selayar',
        'Kepulauan Seribu', 'Kepulauan Siau Tagulandang Biaro', 'Kepulauan Sula', 'Kepulauan Talaud',
        'Kepulauan Tanimbar', 'Kepulauan Yapen', 'Kerinci', 'Ketapang',
        'Klaten', 'Klungkung', 'Kolaka', 'Kolaka Timur',
        'Kolaka Utara', 'Konawe', 'Konawe Kepulauan', 'Konawe Selatan',
        'Konawe Utara', 'Kotabaru', 'Kotamobagu', 'Kotawaringin Barat',
        'Kotawaringin Timur', 'Kuantan Singingi', 'Kubu Raya', 'Kudus',
        'Kulon Progo', 'Kuningan', 'Kupang', 'Kutai Barat',
        'Kutai Kartanegara', 'Kutai Timur', 'Labuhanbatu', 'Labuhanbatu Selatan',
        'Labuhanbatu Utara', 'Lahat', 'Lamandau', 'Lamongan',
        'Lampung Barat', 'Lampung Selatan', 'Lampung Tengah', 'Lampung Timur',
        'Lampung Utara', 'Landak', 'Langkat', 'Langsa',
        'Lanny Jaya', 'Lebak', 'Lebong', 'Lembata',
        'Lhokseumawe', 'Lima Puluh Kota', 'Lingga', 'Lombok Barat',
        'Lombok Tengah', 'Lombok Timur', 'Lombok Utara', 'Lumajang',
        'Lubuk Linggau', 'Luwu', 'Luwu Timur', 'Luwu Utara',
        'Madiun', 'Magelang', 'Magetan', 'Mahakam Ulu',
        'Majalengka', 'Majene', 'Makassar', 'Malaka',
        'Malang', 'Malinau', 'Maluku Barat Daya', 'Maluku Tengah',
        'Maluku Tenggara', 'Mamasa', 'Mamberamo Raya', 'Mamberamo Tengah',
        'Mamuju', 'Mamuju Tengah', 'Manado', 'Mandailing Natal',
        'Manggarai', 'Manggarai Barat', 'Manggarai Timur', 'Manokwari',
        'Manokwari Selatan', 'Mappi', 'Maros', 'Mataram',
        'Maybrat', 'Medan', 'Melawi', 'Mempawah',
        'Merangin', 'Merauke', 'Mesuji', 'Metro',
        'Mimika', 'Minahasa', 'Minahasa Selatan', 'Minahasa Tenggara',
        'Minahasa Utara', 'Mojokerto', 'Morowali', 'Morowali Utara',
        'Muara Enim', 'Muaro Jambi', 'Mukomuko', 'Muna',
        'Muna Barat', 'Murung Raya', 'Musi Banyuasin', 'Musi Rawas',
        'Musi Rawas Utara', 'Nabire', 'Nagan Raya', 'Nagekeo',
        'Natuna', 'Nduga', 'Ngada', 'Nganjuk',
        'Ngawi', 'Nias', 'Nias Barat', 'Nias Selatan',
        'Nias Utara', 'Nunukan', 'Ogan Ilir', 'Ogan Komering Ilir',
        'Ogan Komering Ulu', 'Ogan Komering Ulu Selatan', 'Ogan Komering Ulu Timur', 'Pacitan',
        'Padang', 'Padang Lawas', 'Padang Lawas Utara', 'Padang Panjang',
        'Padang Pariaman', 'Padangsidimpuan', 'Pagar Alam', 'Pakpak Bharat',
        'Palangka Raya', 'Palembang', 'Palopo', 'Palu',
        'Pamekasan', 'Pandeglang', 'Pangandaran', 'Pangkajene Dan Kepulauan',
        'Pangkalpinang', 'Paniai', 'Parepare', 'Pariaman',
        'Parigi Moutong', 'Pasaman', 'Pasaman Barat', 'Pasangkayu',
        'Paser', 'Pasuruan', 'Pati', 'Payakumbuh',
        'Pegunungan Arfak', 'Pegunungan Bintang', 'Pekalongan', 'Pekanbaru',
        'Pelalawan', 'Pemalang', 'Pematangsiantar', 'Penajam Paser Utara',
        'Penukal Abab Lematang Ilir', 'Pesawaran', 'Pesisir Barat', 'Pesisir Selatan',
        'Pidie', 'Pidie Jaya', 'Pinrang', 'Pohuwato',
        'Polewali Mandar', 'Ponorogo', 'Pontianak', 'Poso',
        'Prabumulih', 'Pringsewu', 'Probolinggo', 'Pulang Pisau',
        'Pulau Morotai', 'Pulau Taliabu', 'Puncak', 'Puncak Jaya',
        'Purbalingga', 'Purwakarta', 'Purworejo', 'Raja Ampat',
        'Rejang Lebong', 'Rembang', 'Rokan Hilir', 'Rokan Hulu',
        'Rote Ndao', 'Sabang', 'Sabu Raijua', 'Salatiga',
        'Samarinda', 'Sambas', 'Samosir', 'Sampang',
        'Sanggau', 'Sarmi', 'Sarolangun', 'Sawahlunto',
        'Sekadau', 'Seluma', 'Semarang', 'Seram Bagian Barat',
        'Seram Bagian Timur', 'Serang', 'Serdang Bedagai', 'Seruyan',
        'Siak', 'Sibolga', 'Sidenreng Rappang', 'Sidoarjo',
        'Sigi', 'Sijunjung', 'Sikka', 'Simalungun',
        'Simeulue', 'Singkawang', 'Sinjai', 'Sintang',
        'Situbondo', 'Sleman', 'Solok', 'Solok Selatan',
        'Soppeng', 'Sorong', 'Sorong Selatan', 'Sragen',
        'Subang', 'Subulussalam', 'Sukabumi', 'Sukamara',
        'Sukoharjo', 'Sumba Barat', 'Sumba Barat Daya', 'Sumba Tengah',
        'Sumba Timur', 'Sumbawa', 'Sumbawa Barat', 'Sumedang',
        'Sumenep', 'Sungai Penuh', 'Supiori', 'Surabaya',
        'Surakarta', 'Tabalong', 'Tabanan', 'Takalar',
        'Tambrauw', 'Tana Tidung', 'Tana Toraja', 'Tanah Bumbu',
        'Tanah Datar', 'Tanah Laut', 'Tangerang', 'Tangerang Selatan',
        'Tanggamus', 'Tanjung Jabung Barat', 'Tanjung Jabung Timur', 'Tanjungbalai',
        'Tanjungpinang', 'Tapanuli Selatan', 'Tapanuli Tengah', 'Tapanuli Utara',
        'Tapin', 'Tarakan', 'Tasikmalaya', 'Tebing Tinggi',
        'Tebo', 'Tegal', 'Teluk Bintuni', 'Teluk Wondama',
        'Temanggung', 'Ternate', 'Tidore Kepulauan', 'Timor Tengah Selatan',
        'Timor Tengah Utara', 'Toba', 'Tojo Una Una', 'Tolikara',
        'Tolitoli', 'Tomohon', 'Toraja Utara', 'Trenggalek',
        'Tual', 'Tuban', 'Tulang Bawang', 'Tulang Bawang Barat',
        'Tulungagung', 'Wajo', 'Wakatobi', 'Waropen',
        'Way Kanan', 'Wonogiri', 'Wonosobo', 'Yahukimo',
        'Yalimo', 'Yogyakarta', 'Sumatera Pontianak', 'Jabodetabek Bandung',
        'Makassar Ambon Lombok NTT', 'Jawa Timur Bali', 'Jawa Tengah',
    ];
}

/**
 * business_unit and site_area hold comma-separated values now that both
 * support multi-select in the Add/Edit Contract modal (2026-09-11) -- still
 * one free-text column each, not a junction table, matching this feature's
 * existing non-FK'd style. Use this to get back the individual values, e.g.
 * for dropdown-option merging or per-value dashboard aggregation.
 */
function contract_maintenance_split_multi($value) {
    if ($value === null || $value === '') {
        return [];
    }
    $parts = array_map('trim', explode(',', (string)$value));
    return array_values(array_filter($parts, fn($p) => $p !== ''));
}

/**
 * Expiry status for a contract row, based on output_end_date only (the
 * team's call: expiry is judged by the output/PO document's own end date,
 * not the master contract_end_date, which is kept on the record only as
 * reference). Buckets mirror what the team used to flag by hand with a
 * pink highlight.
 */
function contract_maintenance_status($output_end_date, $today = null) {
    $today = $today ?: date('Y-m-d');

    if (empty($output_end_date)) {
        return ['key' => 'unknown', 'label' => 'No Date', 'soonest' => null];
    }

    $days = (strtotime($output_end_date) - strtotime($today)) / 86400;

    if ($days < 0) {
        return ['key' => 'expired', 'label' => 'Expired', 'soonest' => $output_end_date];
    }
    if ($days <= 90) {
        return ['key' => 'expiring_90', 'label' => '90 Days', 'soonest' => $output_end_date];
    }
    if ($days <= 180) {
        return ['key' => 'expiring_180', 'label' => '180 Days', 'soonest' => $output_end_date];
    }
    return ['key' => 'active', 'label' => 'Active', 'soonest' => $output_end_date];
}
