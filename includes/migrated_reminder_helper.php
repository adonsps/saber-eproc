<?php
// includes/migrated_reminder_helper.php
//
// Reminder email for vendors that were bulk-migrated into VSS (registration_status
// = 'migrated') but have never signed in and never filled the Pra-Kualifikasi (PQ)
// form. Their account already exists — it was seeded with a shared default
// password — so this is an *activation* reminder, not an invitation: it hands them
// their login details instead of a registration link.
//
// The mail body, the recipient query and the send/log routine all live here (not in
// the page) so a cron/CLI blast can reuse them without dragging the UI along.

require_once __DIR__ . '/profile_helper.php';       // isVendorProfileComplete()
require_once __DIR__ . '/notification_helper.php';  // _buildMailer() / _sendMail() / _appUrl()

use PHPMailer\PHPMailer\PHPMailer;

// The password every migrated account was seeded with. MUST stay in sync with the
// $default_password check in login_action.php — that check is what makes the
// "you will be asked to create a new password" promise in this email true.
if (!defined('MIGRATED_DEFAULT_PASSWORD')) {
    define('MIGRATED_DEFAULT_PASSWORD', 'Vendor123');
}

// Support address quoted in the footer — same one the invitation email uses.
if (!defined('MIGRATED_REMINDER_SUPPORT_EMAIL')) {
    define('MIGRATED_REMINDER_SUPPORT_EMAIL', 'sigit.ramadan@kawanlamacorp.com');
}

// Used when the page is opened outside production (localhost/docker), so a test
// send never mails a link that only resolves on the operator's own machine.
if (!defined('MIGRATED_REMINDER_FALLBACK_BASE_URL')) {
    define('MIGRATED_REMINDER_FALLBACK_BASE_URL', 'https://vssportal.online');
}

// "Panduan Penggunaan VSS" — the vendor-facing how-to (Google Drive / Docs / video,
// wherever it is published). Leave empty and the whole guidance block is omitted
// from the email rather than shipping a dead link; the send page warns when unset.
if (!defined('MIGRATED_REMINDER_GUIDE_URL')) {
    define('MIGRATED_REMINDER_GUIDE_URL', 'https://docs.google.com/document/d/1LnKeaEBQ4y5OB-NBhAoGKdniWLhIkZxX8oKr8vO0wAQ/edit?usp=sharing');
}

// A vendor already reminded within this window is skipped unless the operator
// explicitly forces a resend — cheap insurance against a double-click blasting
// 600 vendors twice.
if (!defined('MIGRATED_REMINDER_MIN_RESEND_HOURS')) {
    define('MIGRATED_REMINDER_MIN_RESEND_HOURS', 24);
}

// Send gate. An unapproved run of this page (~600 vendors) got the old
// corporateprocurement@vssportal.online mailbox restricted by Hostinger, so
// sending is OFF unless the server's .env sets MIGRATED_REMINDER_SEND_ENABLED=1 —
// only someone with VPS access can switch it on, and only for as long as needed.
if (!defined('MIGRATED_REMINDER_DISABLED_MESSAGE')) {
    define('MIGRATED_REMINDER_DISABLED_MESSAGE', 'Pengiriman Reminder Vendor Migrasi sedang dinonaktifkan. Untuk informasi lebih lanjut, silakan hubungi tim VSS.');
}

// Even when enabled, at most this many reminders go out per rolling 24 hours,
// across all operators — keeps a large batch spread over days instead of hitting
// the shared mailbox all at once.
if (!defined('MIGRATED_REMINDER_DAILY_CAP')) {
    define('MIGRATED_REMINDER_DAILY_CAP', max(0, (int) (getenv('MIGRATED_REMINDER_DAILY_CAP') ?: 50)));
}

function migratedReminderSendEnabled(): bool
{
    return getenv('MIGRATED_REMINDER_SEND_ENABLED') === '1';
}

/** Reminders actually sent (status = 'sent') in the last 24 hours, by anyone. */
function migratedReminderSentLast24h(mysqli $conn): int
{
    $res = $conn->query("SELECT COUNT(*) AS n FROM vendor_migration_reminders
                         WHERE status = 'sent' AND created_at >= (NOW() - INTERVAL 24 HOUR)");
    return $res ? (int) ($res->fetch_assoc()['n'] ?? 0) : 0;
}

/**
 * Absolute base URL of the VSS portal, WITHOUT a trailing slash (e.g.
 * "https://vssportal.online"). Derived from the current request so the mail
 * always points at the environment it was sent from; falls back to the production
 * URL when there is no usable host (CLI) or when running locally.
 */
function migratedReminderPortalBase(): string
{
    if (defined('VSS_PORTAL_BASE_URL') && VSS_PORTAL_BASE_URL !== '') {
        return rtrim(VSS_PORTAL_BASE_URL, '/');
    }

    $derived = _appUrl(''); // scheme://host/<app root>/
    $host    = strtolower((string) parse_url($derived, PHP_URL_HOST));

    if ($derived === '' || in_array($host, ['localhost', '127.0.0.1', '::1', 'app', 'db'], true)) {
        return rtrim(MIGRATED_REMINDER_FALLBACK_BASE_URL, '/');
    }

    return rtrim($derived, '/');
}

/**
 * True when the account still carries the seeded default password — i.e. the
 * vendor has never changed it, so quoting it in the email is still correct and
 * login_action.php will force them through reset_password.php on the way in.
 *
 * Results are memoised per hash: bcrypt costs ~60ms a call and the migration
 * seeded hundreds of accounts from the same handful of hashes, so verifying each
 * distinct hash once turns a ~60s page load into a fraction of a second.
 */
function migratedReminderUsesDefaultPassword(?string $password_hash): bool
{
    static $memo = [];

    if ($password_hash === null || $password_hash === '') {
        return false;
    }
    if (!array_key_exists($password_hash, $memo)) {
        $memo[$password_hash] = password_verify(MIGRATED_DEFAULT_PASSWORD, $password_hash);
    }

    return $memo[$password_hash];
}

/** Create the send-history table on first use (same pattern as vendor_invitations). */
function migratedReminderEnsureTable(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS vendor_migration_reminders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vendor_id INT NOT NULL,
        user_id INT DEFAULT NULL,
        recipient_email VARCHAR(255) NOT NULL,
        company_name VARCHAR(255) DEFAULT NULL,
        contact_person VARCHAR(255) DEFAULT NULL,
        profile_complete TINYINT(1) NOT NULL DEFAULT 0,
        default_password TINYINT(1) NOT NULL DEFAULT 1,
        status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
        error_message TEXT DEFAULT NULL,
        sent_by_id INT DEFAULT NULL,
        sent_by_name VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_vendor (vendor_id),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // "Hentikan" signal. The browser sends a batch as several chunked requests, so
    // stopping the loop in JS only stops the *next* request — the chunk already
    // running server-side would keep mailing. The stop is therefore recorded here
    // and re-read between vendors. A table (not a temp file) so the signal is
    // visible to whichever PHP worker is running the chunk.
    $conn->query("CREATE TABLE IF NOT EXISTS vendor_migration_reminder_stops (
        run_id VARCHAR(64) NOT NULL PRIMARY KEY,
        requested_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Record a stop request for one send run. Ids are minted fresh by the browser per
 * run, so a stale signal can never abort a later batch.
 */
function migratedReminderRequestStop(mysqli $conn, string $run_id, ?int $user_id = null): void
{
    if ($run_id === '') return;

    $stmt = $conn->prepare("INSERT IGNORE INTO vendor_migration_reminder_stops (run_id, requested_by) VALUES (?, ?)");
    if ($stmt) {
        $stmt->bind_param("si", $run_id, $user_id);
        $stmt->execute();
    }

    // No run outlives a browser tab; drop yesterday's signals on the way past.
    $conn->query("DELETE FROM vendor_migration_reminder_stops WHERE created_at < (NOW() - INTERVAL 1 DAY)");
}

/**
 * True once the operator has pressed "Hentikan" for this run. Checked between
 * vendors — never mid-message, so no email is ever cut off part-sent.
 */
function migratedReminderIsStopped(mysqli $conn, string $run_id): bool
{
    if ($run_id === '') return false;

    $stmt = $conn->prepare("SELECT 1 FROM vendor_migration_reminder_stops WHERE run_id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param("s", $run_id);
    $stmt->execute();

    return (bool) $stmt->get_result()->fetch_assoc();
}

/**
 * The recipient set: vendors still sitting at 'migrated' that have never opened a
 * PQ submission. Test accounts and deactivated logins are excluded — a login
 * reminder to an account that cannot log in is just noise.
 *
 * Each row carries the whole vendors record (isVendorProfileComplete() needs the
 * document columns) plus the login account and this vendor's reminder history.
 *
 * @param array $filters  search (string), reminder ('all'|'never'|'sent'),
 *                        profile ('all'|'incomplete'|'complete'),
 *                        vendor_ids (int[] — restrict to these, used by the sender)
 * @return array<int, array> keyed by vendor id
 */
function migratedReminderFetchTargets(mysqli $conn, array $filters = []): array
{
    $where  = [
        "v.registration_status = 'migrated'",
        "u.is_active = 1",
        "u.is_test_account = 0",
        "u.email <> ''",
        "NOT EXISTS (SELECT 1 FROM pq_submissions ps WHERE ps.vendor_id = v.id)",
    ];
    $params = [];
    $types  = '';

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $where[]  = "(v.company_name LIKE ? OR v.contact_person LIKE ? OR u.email LIKE ?)";
        $like     = '%' . $search . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types   .= 'sss';
    }

    $vendor_ids = array_values(array_filter(array_map('intval', (array) ($filters['vendor_ids'] ?? []))));
    if ($vendor_ids !== []) {
        // Ints only (cast above), so inlining them cannot carry an injection.
        $where[] = "v.id IN (" . implode(',', $vendor_ids) . ")";
    }

    $reminder = $filters['reminder'] ?? 'all';
    if ($reminder === 'never') {
        $where[] = "r.send_count IS NULL";
    } elseif ($reminder === 'sent') {
        $where[] = "r.send_count IS NOT NULL";
    }

    $sql = "SELECT v.*,
                   u.id AS login_user_id, u.email AS login_email, u.username AS login_username,
                   u.password_hash, u.last_login_at,
                   r.last_sent_at, r.send_count
            FROM vendors v
            JOIN users u ON u.id = v.user_id
            LEFT JOIN (
                SELECT vendor_id, MAX(created_at) AS last_sent_at, COUNT(*) AS send_count
                FROM vendor_migration_reminders
                WHERE status = 'sent'
                GROUP BY vendor_id
            ) r ON r.vendor_id = v.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY v.company_name ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('[migrated-reminder] target query prepare failed: ' . $conn->error);
        return [];
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $profile_filter = $filters['profile'] ?? 'all';
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        // Completeness is a PHP rule (profile_helper.php), not a column, so it is
        // applied here rather than in the WHERE clause.
        $check                   = isVendorProfileComplete($row);
        $row['profile_complete'] = $check['complete'];
        $row['profile_missing']  = $check['missing'];
        $row['uses_default_password'] = migratedReminderUsesDefaultPassword($row['password_hash']);

        if ($profile_filter === 'incomplete' && $row['profile_complete']) continue;
        if ($profile_filter === 'complete'  && !$row['profile_complete']) continue;

        $rows[(int) $row['id']] = $row;
    }

    return $rows;
}

/**
 * Compose the reminder for one vendor.
 *
 * @param array $vendor Row from migratedReminderFetchTargets().
 * @param array $opts   portal_base (string), with_banner (bool)
 * @return array{subject: string, html: string, text: string}
 */
function migratedReminderBuildEmail(array $vendor, array $opts = []): array
{
    $portal_base = rtrim((string) ($opts['portal_base'] ?? migratedReminderPortalBase()), '/');
    $with_banner = (bool) ($opts['with_banner'] ?? false);
    $with_logo   = (bool) ($opts['with_logo'] ?? false);

    $login_url  = $portal_base . '/login.php';
    $forgot_url = $portal_base . '/forgot_password.php';

    $company   = trim((string) ($vendor['company_name'] ?? ''));
    $contact   = trim((string) ($vendor['contact_person'] ?? ''));
    $email     = trim((string) ($vendor['login_email'] ?? $vendor['email'] ?? ''));
    $username  = trim((string) ($vendor['login_username'] ?? ''));
    $missing   = (array) ($vendor['profile_missing'] ?? []);
    $is_default_pw = (bool) ($vendor['uses_default_password'] ?? true);

    // Salutation: PIC on the first line, company name on the line below.
    if ($contact !== '') {
        $greeting_line1 = $contact;
        $greeting_line2 = $company;
    } elseif ($company !== '') {
        $greeting_line1 = $company;
        $greeting_line2 = '';
    } else {
        $greeting_line1 = 'Mitra Vendor Kawan Lama Group';
        $greeting_line2 = '';
    }

    $subject = 'Informasi Akses Vendor Portal – Corporate Procurement Kawan Lama Group';

    // ---- Account box -------------------------------------------------------
    // The password line is only truthful while the account still holds the seeded
    // password; once the vendor has set their own, we point at "forgot password"
    // instead of quoting a password that no longer works.
    if ($is_default_pw) {
        $password_row_html =
              '<tr>'
            . '<td style="padding:6px 0;color:#666666;width:150px;">Kata Sandi Sementara</td>'
            . '<td style="padding:6px 0;color:#111111;font-weight:bold;">'
            . '<code style="background:#eef4ff;padding:3px 8px;border-radius:4px;font-size:15px;letter-spacing:0.5px;">'
            . htmlspecialchars(MIGRATED_DEFAULT_PASSWORD) . '</code></td>'
            . '</tr>';
        $password_note_html =
              '<p>Saat pertama kali login, sistem akan meminta Bapak/Ibu '
            . '<strong>merubah kata sandi pribadi</strong> untuk keamanan akun dan jangan dibagikan ke pihak lain.</p>';
        $password_row_text  = 'Kata Sandi Sementara : ' . MIGRATED_DEFAULT_PASSWORD;
        $password_note_text =
              "Saat pertama kali login, sistem akan meminta Bapak/Ibu merubah kata sandi pribadi untuk\n"
            . "keamanan akun dan jangan dibagikan ke pihak lain.";
    } else {
        $password_row_html =
              '<tr>'
            . '<td style="padding:6px 0;color:#666666;width:150px;">Kata Sandi</td>'
            . '<td style="padding:6px 0;color:#111111;">Kata sandi yang sudah Anda buat sendiri</td>'
            . '</tr>';
        $password_note_html =
              '<p>Akun Anda sudah memiliki kata sandi pribadi. Jika lupa, silakan atur ulang melalui '
            . '<a href="' . $forgot_url . '" target="_blank" style="color:#007bff;">halaman Lupa Kata Sandi</a>.</p>';
        $password_row_text  = 'Kata Sandi           : kata sandi yang sudah Anda buat sendiri';
        $password_note_text = 'Jika lupa kata sandi, atur ulang di: ' . $forgot_url;
    }

    $username_row_html = $username !== ''
        ? '<tr><td style="padding:6px 0;color:#666666;">Nama Pengguna</td>'
          . '<td style="padding:6px 0;color:#111111;">' . htmlspecialchars($username) . '</td></tr>'
        : '';
    $username_row_text = $username !== '' ? "Nama Pengguna        : " . $username . "\n" : '';

    // ---- Numbered checklist -------------------------------------------------
    // Flattened: each missing document is its own numbered step (not nested under
    // a "Lengkapi Profil Perusahaan" item), with the PQ form as the final step.
    // A vendor with no gaps simply gets a one-item list — the PQ step alone.
    $pq_note = '*Pengisian Formulir Pra-Kualifikasi menjadi syarat agar perusahaan Bapak/Ibu dapat kami proses '
             . 'sebagai mitra resmi dan diikutsertakan dalam permintaan proses tender berikutnya.';

    $step_items_html = '';
    foreach (array_slice($missing, 0, 12) as $item) {
        $step_items_html .= '<li style="margin-bottom:12px;color:#b02a37;font-weight:normal;">' . htmlspecialchars($item) . '</li>';
    }
    $step_items_html .= '<li style="margin-bottom:12px;color:#b02a37;font-weight:normal;">'
        . 'Isi Formulir Pra-Kualifikasi<br>'
        . '<p style="margin:4px 0 0;color:#333333;font-style:italic;font-size:12px;">' . htmlspecialchars($pq_note) . '</p>'
        . '</li>';

    $step_no = 1;
    $step_items_text = '';
    foreach (array_slice($missing, 0, 12) as $item) {
        $step_items_text .= "  {$step_no}. {$item}\n";
        $step_no++;
    }
    $step_items_text .= "  {$step_no}. Isi Formulir Pra-Kualifikasi\n     {$pq_note}\n";

    // ---- Guidance link ----------------------------------------------------
    // Sits under the main CTA as a lighter, outlined button so it reads as the
    // secondary action. target="_blank" so it opens in a new tab and the vendor
    // does not lose the email. Omitted entirely when no URL is configured.
    $guide_url   = trim((string) ($opts['guide_url'] ?? MIGRATED_REMINDER_GUIDE_URL));
    $guide_html  = '';
    $guide_text  = '';
    if ($guide_url !== '') {
        $guide_html =
              '<div style="margin-top:14px;">'
            . '<a href="' . htmlspecialchars($guide_url, ENT_QUOTES) . '" target="_blank" rel="noopener"'
            . ' style="display:inline-block;padding:11px 24px;border:1px solid #007bff;border-radius:6px;'
            . 'color:#007bff;background:#ffffff;text-decoration:none;font-size:14px;font-weight:bold;">'
            . '&#128214; Lihat Panduan Penggunaan VSS'
            . '</a>'
            . '</div>';
        $guide_text = "Panduan penggunaan VSS:\n" . $guide_url . "\n\n";
    }

    $banner_html = $with_banner
        ? '<a href="' . $login_url . '" target="_blank" style="text-decoration:none;">'
          . '<img src="cid:bannerImage" alt="Banner VSS Kawan Lama Group" style="width:100%;max-width:100%;display:block;border:none;">'
          . '</a>'
        : '';

    $greeting_html = htmlspecialchars($greeting_line1) . ','
        . ($greeting_line2 !== '' ? '<br>' . htmlspecialchars($greeting_line2) : '');
    $greeting_text = $greeting_line1 . ',' . ($greeting_line2 !== '' ? "\n" . $greeting_line2 : '');
    $email_html    = htmlspecialchars($email);
    $support       = MIGRATED_REMINDER_SUPPORT_EMAIL;

    // Greeting and logo share one row (table, not flex — Outlook/older mail
    // clients ignore flexbox) so the logo sits level with "Halo Bapak/Ibu"
    // instead of stacking above it with a gap.
    $greeting_row_html = $with_logo
        ? '<table style="width:100%;border-collapse:collapse;margin-bottom:0;"><tr>'
          . '<td style="vertical-align:middle;">'
          . '<p style="margin:0;"><strong>Halo Bapak/Ibu ' . $greeting_html . '</strong></p>'
          . '</td>'
          . '<td style="vertical-align:middle;text-align:right;width:150px;padding-left:16px;">'
          . '<img src="cid:klgLogo" alt="Kawan Lama Group" style="max-width:150px;width:100%;height:auto;display:inline-block;border:none;">'
          . '</td>'
          . '</tr></table>'
        : '<p style="margin-top:0;"><strong>Halo Bapak/Ibu ' . $greeting_html . '</strong></p>';

    $html = <<<HTML
<div style="background-color:#f4f7f6;padding:40px 10px;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;">
  <div style="max-width:600px;margin:0 auto;background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 4px 10px rgba(0,0,0,0.05);">

    {$banner_html}

    <div style="padding:30px;color:#333333;line-height:1.6;font-size:15px;">

      {$greeting_row_html}

      <p>Perkenalkan, kami dari <strong>Tim Corporate Procurement Kawan Lama Group</strong>, grup usaha yang menaungi
      AZKO, Informa, Selma, Toys Kingdom, Chatime, Krisbow, dan sejumlah unit bisnis lainnya di Indonesia.
      Terima kasih atas kerja sama Bapak/Ibu selama ini sebagai mitra kami.</p>

      <p>Kami ingin menginformasikan bahwa mulai saat ini, seluruh proses kemitraan vendor mulai dari profil
      perusahaan dan dokumen legal melalui satu sistem baru. Bapak/Ibu dimohon untuk memperbarui data
      perusahaan Anda melalui tautan yang tertera di bawah.</p>

      <!-- Detail akun -->
      <div style="margin:26px 0;padding:18px 20px;background:#f8f9fa;border-left:4px solid #007bff;border-radius:4px;">
        <p style="margin:0 0 12px;font-weight:bold;color:#111111;">&#128273; Detail Akun Anda</p>
        <table style="width:100%;border-collapse:collapse;font-size:14px;">
          <tr>
            <td style="padding:6px 0;color:#666666;width:150px;">Email / ID Login</td>
            <td style="padding:6px 0;color:#111111;font-weight:bold;">{$email_html}</td>
          </tr>
          {$username_row_html}
          {$password_row_html}
        </table>
      </div>

      {$password_note_html}

      <p style="margin-top:26px;"><strong>Berikut yang perlu Bapak/Ibu selesaikan:</strong></p>
      <ol style="padding-left:20px;margin-top:8px;">
        {$step_items_html}
      </ol>

      <div style="text-align:center;margin:32px 0;">
        <a href="{$login_url}" target="_blank" style="background-color:#007bff;color:#ffffff;display:inline-block;padding:14px 28px;text-decoration:none;border-radius:6px;font-size:16px;font-weight:bold;">
          &#128073; Masuk ke Portal VSS
        </a>
        {$guide_html}
        <p style="margin:12px 0 0;font-size:12px;color:#999999;">
          Atau salin tautan ini ke peramban Anda:<br>
          <span style="color:#666666;">{$login_url}</span>
        </p>
      </div>

      <hr style="border:none;border-top:1px solid #eeeeee;margin:20px 0;">

      <p style="font-size:13px;color:#666666;margin-bottom:16px;">
        Jika Bapak/Ibu mengalami kendala saat login atau ada pertanyaan seputar penggunaan portal,
        dapat menghubungi tim support kami di <strong>{$support}</strong>.
      </p>

      <p style="font-size:13px;color:#666666;margin-bottom:20px;">
        Terima kasih atas perhatian dan kerja sama Bapak/Ibu.
      </p>

      <p style="margin-bottom:5px;">Salam,</p>
      <p style="margin-top:0;font-weight:bold;color:#000000;">Tim Corporate Procurement<br>Kawan Lama Group</p>

    </div>
  </div>
</div>
HTML;

    $text =
          "Halo Bapak/Ibu {$greeting_text}\n\n"
        . "Perkenalkan, kami dari Tim Corporate Procurement Kawan Lama Group, grup usaha yang menaungi\n"
        . "AZKO, Informa, Selma, Toys Kingdom, Chatime, Krisbow, dan sejumlah unit bisnis lainnya di\n"
        . "Indonesia. Terima kasih atas kerja sama Bapak/Ibu selama ini sebagai mitra kami.\n\n"
        . "Kami ingin menginformasikan bahwa mulai saat ini, seluruh proses kemitraan vendor mulai dari\n"
        . "profil perusahaan dan dokumen legal melalui satu sistem baru. Bapak/Ibu dimohon untuk\n"
        . "memperbarui data perusahaan Anda melalui tautan yang tertera di bawah.\n\n"
        . "DETAIL AKUN ANDA\n"
        . "Email / ID Login     : {$email}\n"
        . $username_row_text
        . $password_row_text . "\n\n"
        . $password_note_text . "\n\n"
        . "BERIKUT YANG PERLU DISELESAIKAN\n"
        . $step_items_text . "\n"
        . "Masuk ke portal VSS:\n{$login_url}\n\n"
        . $guide_text
        . "Jika Bapak/Ibu mengalami kendala saat login atau ada pertanyaan seputar penggunaan portal,\n"
        . "dapat menghubungi tim support kami di {$support}.\n\n"
        . "Terima kasih atas perhatian dan kerja sama Bapak/Ibu.\n\n"
        . "Salam,\nTim Corporate Procurement\nKawan Lama Group\n";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/**
 * Send the reminder to one vendor and write the result to vendor_migration_reminders.
 *
 * @param array $opts portal_base, sent_by_id, sent_by_name, force (bool — ignore the
 *                    resend window), mailer (PHPMailer to reuse across a batch)
 * @return array{status: 'sent'|'failed'|'skipped'|'throttled', message: string,
 *               retry_after?: int, fatal?: bool}
 */
function migratedReminderSendOne(mysqli $conn, array $vendor, array $opts = []): array
{
    // Last line of defence for any caller (page, future CLI/cron): no gate, no mail.
    if (!migratedReminderSendEnabled()) {
        return ['status' => 'skipped', 'message' => MIGRATED_REMINDER_DISABLED_MESSAGE];
    }

    $vendor_id = (int) ($vendor['id'] ?? 0);
    $email     = trim((string) ($vendor['login_email'] ?? $vendor['email'] ?? ''));

    if ($vendor_id === 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['status' => 'skipped', 'message' => 'Alamat email tidak valid.'];
    }

    // Resend guard — a vendor mailed recently is left alone unless forced.
    if (empty($opts['force'])) {
        $guard = $conn->prepare("SELECT created_at FROM vendor_migration_reminders
                                 WHERE vendor_id = ? AND status = 'sent'
                                   AND created_at >= (NOW() - INTERVAL ? HOUR)
                                 LIMIT 1");
        if ($guard) {
            $hours = (int) MIGRATED_REMINDER_MIN_RESEND_HOURS;
            $guard->bind_param("ii", $vendor_id, $hours);
            $guard->execute();
            if ($guard->get_result()->fetch_assoc()) {
                return [
                    'status'  => 'skipped',
                    'message' => 'Sudah dikirim dalam ' . $hours . ' jam terakhir.',
                ];
            }
        }
    }

    // A reused mailer keeps the SMTP session open across a batch (much faster than
    // reconnecting per vendor); recipients/attachments are cleared before each send.
    $reuse = isset($opts['mailer']) && $opts['mailer'] instanceof PHPMailer;
    $mail  = $reuse ? $opts['mailer'] : _buildMailer();
    if ($reuse) {
        $mail->clearAllRecipients();
        $mail->clearAttachments();
    }

    $banner_file = dirname(__DIR__) . '/assets/img/Banner for VSS.png';
    $with_banner = is_file($banner_file);
    if ($with_banner) {
        $mail->addEmbeddedImage($banner_file, 'bannerImage');
    }

    $logo_file = dirname(__DIR__) . '/klg_Logo.png';
    $with_logo = is_file($logo_file);
    if ($with_logo) {
        $mail->addEmbeddedImage($logo_file, 'klgLogo');
    }

    $content = migratedReminderBuildEmail($vendor, [
        'portal_base' => $opts['portal_base'] ?? migratedReminderPortalBase(),
        'with_banner' => $with_banner,
        'with_logo'   => $with_logo,
    ]);

    $contact_name = trim((string) ($vendor['contact_person'] ?? ''));
    $mail->addAddress($email, $contact_name !== '' ? $contact_name : $email);
    $mail->isHTML(true);
    $mail->Subject = $content['subject'];
    $mail->Body    = $content['html'];
    $mail->AltBody = $content['text'];

    $ok = _sendMail($mail);

    // Our own send cap said "not yet": nothing reached SMTP, so this is neither a
    // failure nor a history row. The caller waits retry_after seconds and tries
    // this same vendor again.
    $why = mail_send_status();
    if (!$ok && in_array($why['type'], ['rate_limited', 'cooldown'], true)) {
        return [
            'status'      => 'throttled',
            'message'     => 'Kuota kirim penuh, menunggu giliran.',
            'retry_after' => (int) $why['retry_after'],
        ];
    }

    // A real SMTP refusal (e.g. "data not accepted" while the mailbox is blocked)
    // is fatal for the whole run: every following message would be refused too, and
    // retrying a blocked mailbox is exactly what prolongs the block.
    $error = $ok ? null : 'Pengiriman SMTP gagal: ' . mb_substr((string) $why['message'], 0, 200);

    // History write is best-effort: a logging failure must not hide a sent mail.
    $log = $conn->prepare("INSERT INTO vendor_migration_reminders
        (vendor_id, user_id, recipient_email, company_name, contact_person, profile_complete,
         default_password, status, error_message, sent_by_id, sent_by_name)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($log) {
        $user_id     = (int) ($vendor['login_user_id'] ?? 0) ?: null;
        $company     = (string) ($vendor['company_name'] ?? '');
        $complete    = !empty($vendor['profile_complete']) ? 1 : 0;
        $default_pw  = !empty($vendor['uses_default_password']) ? 1 : 0;
        $status      = $ok ? 'sent' : 'failed';
        $sent_by_id  = isset($opts['sent_by_id']) ? (int) $opts['sent_by_id'] : null;
        $sent_by     = (string) ($opts['sent_by_name'] ?? '');
        $log->bind_param(
            "iisssiissis",
            $vendor_id, $user_id, $email, $company, $contact_name,
            $complete, $default_pw, $status, $error, $sent_by_id, $sent_by
        );
        if (!$log->execute()) {
            error_log("[migrated-reminder] history insert failed for {$email}: " . $log->error);
        }
    }

    return [
        'status'  => $ok ? 'sent' : 'failed',
        'message' => $ok ? 'Terkirim.' : 'Gagal mengirim email.',
        // SMTP refused the message itself: stop the run. A single bad address
        // ('recipient') is just that vendor's failure — the mailbox is fine.
        'fatal'   => !$ok && $why['type'] === 'smtp',
    ];
}
