<?php
// tools/prepare_manual_invite.php
//
// Menyiapkan satu undangan vendor TANPA mengirim email. Dipakai saat kuota SMTP
// Hostinger habis (rate limit): barisnya tetap masuk ke vendor_invitations
// lengkap dengan invite_code + invite_token, lalu isi emailnya ditulis ke file
// HTML supaya bisa dibuka di browser, di-copy, dan dikirim manual dari Outlook /
// Gmail korporat (@kawanlamacorp.com) yang kuotanya terpisah dari Hostinger.
//
// Script ini TIDAK menyentuh SMTP sama sekali.
//
// PENTING: jalankan di SERVER (SSH/terminal Hostinger), bukan di laptop.
// config.php memakai $host = "localhost", jadi kalau dijalankan lokal barisnya
// masuk ke MySQL XAMPP dan token-nya tidak akan dikenali produksi.
//
// Usage:
//   php tools/prepare_manual_invite.php <email> <sub_category> [nama_pic] [nama_pt] [catatan]
//
// sub_category wajib salah satu dari: Kontraktor | Supplier | Perseorangan
// (nilainya mengunci pilihan tipe perusahaan di register.php)
//
// Contoh:
//   php tools/prepare_manual_invite.php vendor@contoh.com Supplier "Budi" "PT Contoh Jaya"
//   php tools/prepare_manual_invite.php vendor@contoh.com Kontraktor "" "" "Menyusul dari meeting kemarin."
//
// Opsi lewat environment variable:
//   BASE_URL=https://qa.vssportal.online   basis link register (default https://vssportal.online)
//   DRY_RUN=1                              cetak HTML saja, TIDAK insert ke database

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("tools/prepare_manual_invite.php hanya untuk CLI.\n");
}

require __DIR__ . '/../config.php';

// --- Argumen ---------------------------------------------------------------
$invitee_email = trim($argv[1] ?? '');
$sub_category  = trim($argv[2] ?? '');
$invitee_name  = trim($argv[3] ?? '');
$company_name  = trim($argv[4] ?? '');
$personal_note = trim($argv[5] ?? '');

$base_url = rtrim(getenv('BASE_URL') ?: 'https://vssportal.online', '/');
$dry_run  = (bool) getenv('DRY_RUN');

$allowed_sub_categories = ['Kontraktor', 'Supplier', 'Perseorangan'];

if ($invitee_email === '' || !filter_var($invitee_email, FILTER_VALIDATE_EMAIL)) {
    exit("ERROR: alamat email tidak valid atau kosong.\n"
       . "Usage: php tools/prepare_manual_invite.php <email> <sub_category> [nama_pic] [nama_pt] [catatan]\n");
}
if (!in_array($sub_category, $allowed_sub_categories, true)) {
    exit("ERROR: sub_category harus salah satu dari: " . implode(' | ', $allowed_sub_categories) . "\n");
}

// Tolak kalau emailnya sudah punya akun — cek yang sama seperti di form.
$chk = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$chk->bind_param("s", $invitee_email);
$chk->execute();
if ($chk->get_result()->fetch_assoc()) {
    exit("ERROR: {$invitee_email} sudah terdaftar di sistem. Vendor bisa langsung login.\n");
}

// --- invite_code: urutan bulanan IVT-yymmNNN, logika sama dengan form -------
$period      = date('ym'); // WIB — config.php mengunci Asia/Jakarta
$invite_code = '';
$seqStmt = $conn->prepare("
    SELECT MAX(CAST(SUBSTRING(invite_code, 9) AS UNSIGNED)) AS max_seq
    FROM vendor_invitations
    WHERE invite_code REGEXP ?
");
if ($seqStmt) {
    $seq_pattern = '^IVT-' . $period . '[0-9]+$';
    $seqStmt->bind_param("s", $seq_pattern);
    if ($seqStmt->execute()) {
        $next_seq    = (int)($seqStmt->get_result()->fetch_assoc()['max_seq'] ?? 0) + 1;
        $invite_code = 'IVT-' . $period . sprintf('%03d', $next_seq);
    }
}
if ($invite_code === '') {
    // Bentuk berdash sengaja diabaikan REGEXP di atas, jadi tidak mengganggu urutan.
    $invite_code = 'IVT-' . $period . '-' . strtoupper(bin2hex(random_bytes(2)));
    fwrite(STDERR, "WARNING: lookup urutan invite_code gagal, pakai kode cadangan {$invite_code}\n");
}

$invite_token  = bin2hex(random_bytes(16)); // 32 hex — dicocokkan oleh register.php
$register_link = $base_url . '/register.php?invite=' . $invite_token;

// --- Sapaan & subjek: aturan sama dengan form ------------------------------
if ($company_name !== '' && $invitee_name !== '') {
    $greeting_name = $invitee_name . ' (' . $company_name . ')';
} elseif ($company_name !== '') {
    $greeting_name = $company_name;
} elseif ($invitee_name !== '') {
    $greeting_name = $invitee_name;
} else {
    $greeting_name = 'Calon Mitra Vendor';
}

if ($company_name !== '') {
    $invite_target = $company_name;
} elseif ($invitee_name !== '') {
    $invite_target = $invitee_name;
} else {
    $invite_target = '';
}

$subject = 'Undangan Pendaftaran Vendor VSS'
         . ($invite_target !== '' ? ' — ' . $invite_target : '')
         . ' [' . $invite_code . ']';

$salutation_html = 'Halo Bapak/Ibu ' . htmlspecialchars($greeting_name) . ',';

$note_block = '';
if ($personal_note !== '') {
    // bgcolor sebagai atribut, bukan CSS background — atribut lolos sanitizer Gmail.
    $note_block = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 30px;">'
                . '<tr><td bgcolor="#f8f9fa" style="padding:12px 16px;border-left:4px solid #007bff;color:#333333;font-size:14px;">'
                . nl2br(htmlspecialchars($personal_note))
                . '</td></tr></table>';
}

// Banner: di form dikirim sebagai lampiran inline (cid:bannerImage), yang hanya
// jalan lewat PHPMailer. Untuk tempel-manual dipakai URL absolut supaya gambarnya
// tetap tampil di Outlook. width="600" ditulis sebagai atribut supaya lebarnya
// tidak hilang saat ditempel.
$banner_url = $base_url . '/assets/img/Banner%20for%20VSS.png';
$banner_img_tag =
    '<a href="' . $register_link . '" target="_blank" style="text-decoration: none;">'
  . '<img src="' . $banner_url . '" alt="Banner VSS Kawan Lama Group" width="600" style="width:600px; max-width:100%; display:block; border:0;">'
  . '</a>';

// Isi teksnya sama dengan modules/shared/invite_vendor_page.php — kalau di sana
// diubah, samakan juga di sini.
//
// TAPI strukturnya sengaja BERBEDA: halaman form memakai <div> + CSS karena
// dikirim utuh oleh PHPMailer, sedangkan versi ini memakai <table> dengan atribut
// HTML (width/align/bgcolor). Alasannya email ini ditempel lewat clipboard ke
// Gmail/Outlook, dan sanitizer compose membuang max-width, box-shadow,
// border-radius, serta background pada <div> — sehingga kartu 600px-nya lenyap
// dan isinya melebar. Atribut tabel jauh lebih tahan terhadap sanitizer itu.
$body = <<<HTML
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f4f7f6" style="background-color:#f4f7f6;">
  <tr>
    <td align="center" style="padding: 24px 10px;">

      <table role="presentation" width="600" align="center" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="width:600px; max-width:600px; background-color:#ffffff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;">
        <tr>
          <td align="center" style="line-height:0;">{$banner_img_tag}</td>
        </tr>
        <tr>
          <td style="padding: 30px; color: #333333; line-height: 1.6; font-size: 15px;">

            <p style="margin-top: 0;"><strong>{$salutation_html}</strong></p>

            <p>Punya produk unggulan dan ingin menjadi bagian dari jaringan bisnis <strong>Kawan Lama Group</strong>? Kini, langkah Anda untuk bergabung jadi jauh lebih mudah, cepat, dan transparan!</p>

            <p>Perkenalkan, <strong>Vendor Sourcing System (VSS)</strong>, portal digital terbaru dari Tim Corporate Procurement Kawan Lama Group yang dirancang khusus untuk mempermudah langkah Anda menjadi mitra kami.</p>

            <p>Melalui portal VSS, Anda bisa melakukan semuanya secara mandiri dalam satu pintu:</p>

            <ul style="padding-left: 20px;">
              <li style="margin-bottom: 10px;">&#128187; <strong>Self-Registration (Daftar Mandiri):</strong> Tidak perlu lagi kirim dokumen fisik. Buat akun perusahaan Anda kapan saja dan di mana saja.</li>
              <li style="margin-bottom: 10px;">&#128221; <strong>Lengkapi Profil &amp; Pra-Kualifikasi:</strong> Isi data perusahaan dan lengkapi form kelayakan secara <em>online</em> dengan sistem yang sangat <em>user-friendly</em>.</li>
              <li style="margin-bottom: 10px;">&#128230; <strong>Tawarkan Produk Terbaik:</strong> Jadikan ini sebagai etalase Anda! Langsung tawarkan produk dan layanan terbaik Anda kepada tim kami.</li>
            </ul>

            <p style="margin-bottom: 30px;">Jangan lewatkan kesempatan untuk berkembang dan berkolaborasi bersama kami. Yuk, mulai proses pendaftaran Anda sekarang juga!</p>

            {$note_block}

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 30px;">
              <tr>
                <td align="center">
                  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center">
                    <tr>
                      <td bgcolor="#007bff" align="center" style="background-color:#007bff; border-radius:6px;">
                        <a href="{$register_link}" target="_blank" style="color: #ffffff; display: inline-block; padding: 14px 28px; text-decoration: none; font-size: 16px; font-weight: bold; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;">Daftar Sekarang</a>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 20px 0;">
              <tr><td height="1" bgcolor="#eeeeee" style="line-height:1px; font-size:1px;">&nbsp;</td></tr>
            </table>

            <p style="font-size: 13px; color: #666666; margin-bottom: 20px;">
              Jika Bapak/Ibu mengalami kendala atau memiliki pertanyaan seputar cara penggunaan portal, jangan ragu untuk membalas email ini atau menghubungi tim support kami di <strong>sigit.ramadan@kawanlamacorp.com</strong>.
            </p>

            <p style="margin-bottom: 5px;">Salam hangat,</p>
            <p style="margin-top: 0; font-weight: bold; color: #000000;">Tim Corporate Procurement<br>Kawan Lama Group</p>

            <p style="font-size: 12px; color: #999999; margin: 24px 0 0;">
              Nomor Undangan: <strong style="color: #666666;">{$invite_code}</strong>
              &nbsp;&middot;&nbsp; Mohon sertakan nomor ini bila menghubungi kami terkait undangan ini.
            </p>

          </td>
        </tr>
      </table>

    </td>
  </tr>
</table>
HTML;

// --- Catat ke riwayat ------------------------------------------------------
// Kolom status hanya punya ENUM('sent','failed'), jadi penanda "dikirim tangan"
// dititipkan di invited_by_name — terlihat di tabel riwayat tanpa perlu ALTER TABLE.
$inviter_name = '(manual)';
$invited_by   = null;
$log_status   = 'sent';
$cc_joined    = '';

if ($dry_run) {
    echo "DRY_RUN aktif — tidak menulis ke database.\n";
} else {
    $logStmt = $conn->prepare("
        INSERT INTO vendor_invitations
            (invitee_email, invitee_name, company_name, sub_category, cc_emails, personal_note, invited_by_id, invited_by_name, status, invite_code, invite_token)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$logStmt) {
        exit("ERROR: prepare insert gagal: " . $conn->error . "\n");
    }
    $logStmt->bind_param(
        "ssssssissss",
        $invitee_email, $invitee_name, $company_name, $sub_category,
        $cc_joined, $personal_note, $invited_by, $inviter_name,
        $log_status, $invite_code, $invite_token
    );
    if (!$logStmt->execute()) {
        exit("ERROR: insert riwayat gagal: " . $logStmt->error . "\n");
    }
    error_log("[invite] MANUAL prepared {$invite_code} for {$invitee_email} (belum dikirim, menunggu kirim tangan)");
}

// --- Tulis file HTML siap tempel -------------------------------------------
$out_dir = __DIR__ . '/manual_invites';
if (!is_dir($out_dir) && !@mkdir($out_dir, 0775, true)) {
    exit("ERROR: gagal membuat folder {$out_dir}\n");
}
$out_file = $out_dir . '/' . $invite_code . '.html';
if (file_put_contents($out_file, $body) === false) {
    exit("ERROR: gagal menulis {$out_file}\n");
}

// --- Ringkasan untuk operator ----------------------------------------------
echo "\n";
echo "=====================================================================\n";
echo " UNDANGAN SIAP DIKIRIM MANUAL" . ($dry_run ? "  [DRY RUN]" : "") . "\n";
echo "=====================================================================\n\n";
echo "Invitation ID : {$invite_code}\n";
echo "Token         : {$invite_token}\n";
echo "Sub kategori  : {$sub_category}\n";
echo "Body HTML     : {$out_file}\n\n";
echo "---------------------------------------------------------------------\n";
echo "To      : {$invitee_email}\n";
echo "Cc      : hadi.risali@kawanlamacorp.com; teofilus.franky@kawanlamacorp.com;\n";
echo "          andreas.yonathan@kawanlamacorp.com; adonis.sutanto@kawanlamacorp.com;\n";
echo "          alvin.anggawijaya@kawanlamacorp.com; jason.sunjaya@kawanlamacorp.com;\n";
echo "          sigit.ramadan@kawanlamacorp.com; group_gp@kawanlamaretail.com\n";
echo "Bcc     : klgprocurement@gmail.com\n";
echo "Subject : {$subject}\n";
echo "---------------------------------------------------------------------\n\n";
echo "Link registrasi (sudah berisi token, JANGAN diubah):\n";
echo "  {$register_link}\n\n";
echo "Langkah kirim:\n";
echo "  1. Buka file HTML di atas lewat browser.\n";
echo "  2. Ctrl+A lalu Ctrl+C (menyalin sebagai rich text, bukan kode).\n";
echo "  3. Tempel ke email baru di Outlook, isi To/Cc/Bcc/Subject seperti di atas.\n";
echo "  4. Pastikan tombol \"Daftar Sekarang\" mengarah ke link bertoken di atas,\n";
echo "     karena token itulah yang menyalakan status \"Dibuka\" di riwayat.\n\n";
