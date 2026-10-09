# Panduan: Kirim Undangan Vendor Secara Manual

Panduan pemakaian `tools/prepare_manual_invite.php`.

## Kapan dipakai

Saat form Invite Vendor gagal dengan pesan:

> Gagal mengirim email undangan. Periksa koneksi SMTP / coba lagi beberapa saat lagi.

dan penyebabnya **kuota SMTP Hostinger habis**, bukan SMTP-nya mati.

Cara membedakannya: cek log server, cari baris berawalan `[invite]`. Kalau isinya
menyebut quota / rate limit / "too many recipients", berarti kena kuota.

```bash
docker compose -f docker-compose.prod.yml logs vms_prod_app | grep '\[invite\]'
```

### Kenapa kuota cepat habis

Kuota SMTP Hostinger dihitung per **penerima**, bukan per email. Satu undangan
mengirim ke:

| Penerima | Jumlah |
| --- | --- |
| To — vendor | 1 |
| Cc — daftar statis di `modules/shared/invite_vendor_page.php` | 8 |
| Bcc — `REGISTRATION_BCC_RECIPIENTS` di `config.php` | 1 |
| **Total per undangan** | **10** |

Jadi 1 undangan memakan 10 jatah. Kalau limitnya 100/jam, 10 undangan saja sudah
habis.

### Apa yang dilakukan script ini

Menyiapkan undangan **tanpa mengirim apa pun** — tidak menyentuh SMTP sama
sekali. Barisnya tetap masuk ke tabel `vendor_invitations` lengkap dengan
`invite_code` dan `invite_token`, lalu isi emailnya ditulis ke file HTML.
Pengirimannya kamu lakukan sendiri dari Outlook / Gmail korporat, yang kuotanya
terpisah dari Hostinger.

---

## Prasyarat

- Akses SSH ke VPS: `ssh root@187.77.112.238` (pakai key `id_ed25519`; login
  password ditolak — lihat `INFRASTRUCTURE.md`).
- Mailbox `@kawanlamacorp.com` untuk mengirim emailnya.

> **Wajib dijalankan di server, bukan di laptop.**
> `config.php` memakai `DB_HOST` dari environment, yang hanya terisi di dalam
> container. Kalau dijalankan di laptop, barisnya masuk ke MySQL lokal dan
> token-nya **tidak akan dikenali produksi** — vendor akan melihat halaman
> "undangan tidak valid" saat mengklik link.

---

## Langkah 1 — Siapkan undangan

SSH ke VPS, masuk ke folder aplikasi, lalu:

```bash
docker compose -f docker-compose.prod.yml exec vms_prod_app \
  php tools/prepare_manual_invite.php <email> <sub_category> [nama_pic] [nama_pt] [catatan]
```

### Argumen

| Posisi | Argumen | Wajib | Keterangan |
| --- | --- | --- | --- |
| 1 | `email` | ya | Alamat vendor. Ditolak kalau sudah punya akun di tabel `users`. |
| 2 | `sub_category` | ya | `Kontraktor`, `Supplier`, atau `Perseorangan`. Mengunci pilihan tipe perusahaan di `register.php`. |
| 3 | `nama_pic` | tidak | Nama orangnya. Muncul di sapaan. |
| 4 | `nama_pt` | tidak | Nama perusahaan. Muncul di sapaan dan di subjek email. |
| 5 | `catatan` | tidak | Catatan pribadi, tampil sebagai blok biru di dalam email. |

Argumen opsional yang dilewati tetap harus ditulis sebagai `""` kalau argumen
sesudahnya diisi:

```bash
# hanya catatan, tanpa nama PIC dan PT
php tools/prepare_manual_invite.php vendor@contoh.com Kontraktor "" "" "Menyusul dari meeting kemarin."
```

### Environment variable

| Variabel | Default | Guna |
| --- | --- | --- |
| `BASE_URL` | `https://vssportal.online` | Basis link register. Isi `https://qa.vssportal.online` untuk QA. |
| `DRY_RUN` | *(kosong)* | Isi `1` untuk mencetak HTML **tanpa** menulis ke database. Berguna untuk melihat hasilnya dulu. |

### Contoh

```bash
docker compose -f docker-compose.prod.yml exec vms_prod_app \
  php tools/prepare_manual_invite.php budi@contohjaya.co.id Supplier "Budi Santoso" "PT Contoh Jaya"
```

Keluarannya:

```
=====================================================================
 UNDANGAN SIAP DIKIRIM MANUAL
=====================================================================

Invitation ID : IVT-2608019
Token         : c49436a414e0cd4ea16e369fefe0d71e
Sub kategori  : Supplier
Body HTML     : /var/www/html/tools/manual_invites/IVT-2608019.html

---------------------------------------------------------------------
To      : budi@contohjaya.co.id
Cc      : hadi.risali@kawanlamacorp.com; teofilus.franky@kawanlamacorp.com;
          andreas.yonathan@kawanlamacorp.com; adonis.sutanto@kawanlamacorp.com;
          alvin.anggawijaya@kawanlamacorp.com; jason.sunjaya@kawanlamacorp.com;
          sigit.ramadan@kawanlamacorp.com; group_gp@kawanlamaretail.com
Bcc     : klgprocurement@gmail.com
Subject : Undangan Pendaftaran Vendor VSS — PT Contoh Jaya [IVT-2608019]
---------------------------------------------------------------------
```

Blok To/Cc/Bcc/Subject itu sudah persis sama dengan yang dikirim sistem, jadi
tinggal disalin.

---

## Langkah 2 — Tarik file HTML ke laptop

File HTML-nya ada **di dalam container**. Di produksi hanya `uploads/` yang
di-mount sebagai volume, sisanya ikut image — jadi file ini akan **hilang saat
redeploy**. Tarik dulu ke host:

```bash
docker compose -f docker-compose.prod.yml exec vms_prod_app \
  cat tools/manual_invites/IVT-2608019.html > IVT-2608019.html
```

Lalu salin ke laptop:

```bash
scp root@187.77.112.238:~/IVT-2608019.html .
```

---

## Langkah 3 — Kirim dari Outlook

1. Buka file HTML tadi di **browser** (bukan di editor teks).
2. `Ctrl+A` lalu `Ctrl+C` — yang tersalin adalah tampilan emailnya sebagai rich
   text, bukan kodenya.
3. Buat email baru di Outlook, klik badan email, `Ctrl+V`.
4. Isi To / Cc / Bcc / Subject persis seperti yang dicetak di Langkah 1.
5. **Sebelum kirim, cek tombol "Daftar Sekarang"** — arahkan kursor ke situ dan
   pastikan link-nya mengandung `?invite=<token>` yang sama dengan output tadi.

Langkah 5 itu bukan formalitas. Token itulah yang membuat `register.php`
mengenali undangannya. Kalau token-nya hilang atau berubah:

- Status **"Dibuka"** di tabel riwayat tidak akan pernah menyala.
- Pilihan tipe perusahaan di form registrasi tidak terkunci ke `sub_category`
  yang kamu tentukan.

---

## Verifikasi

Setelah dikirim, buka halaman **Invite Vendor**. Undangannya muncul di tabel
riwayat dengan:

- Kolom **Invitation ID** berisi kode dari Langkah 1.
- Kolom **Diundang oleh** berisi `(manual)` — penanda bahwa ini dikirim tangan,
  bukan oleh sistem.

Saat vendor mengklik link di email, kolom **Dibuka** akan terisi otomatis dalam
beberapa detik (tabelnya auto-refresh). Itu konfirmasi bahwa token-nya bekerja.

---

## Batasan yang perlu diketahui

**Status di database ditulis `sent` walaupun sistem belum mengirim apa pun.**
Kolom `vendor_invitations.status` hanya punya ENUM `sent`/`failed`, jadi
penanda "manual" dititipkan di kolom `invited_by_name`. Konsekuensinya: laporan
apa pun yang menghitung `status='sent'` akan ikut menghitung undangan manual
ini. Kalau nanti perlu dibedakan secara tegas, `status` harus di-`ALTER` untuk
menerima nilai `manual` dan tampilan riwayat ikut disesuaikan.

**Tampilan email hasil tempel tidak 100% identik dengan yang dikirim sistem.**
Template di tool ini memakai layout `<table>` dengan atribut HTML
(`width`/`bgcolor`/`align`) karena sanitizer compose Gmail membuang properti CSS
seperti `max-width`, `box-shadow`, dan `border-radius`. Struktur ini sengaja
berbeda dari `modules/shared/invite_vendor_page.php` yang masih memakai `<div>`.

**Isi teksnya diduplikasi.** Kalau kalimat di
`modules/shared/invite_vendor_page.php` diubah, ubah juga di
`tools/prepare_manual_invite.php`. Sudah ada komentar penunjuk di kedua file.

**Banner memakai URL absolut**, bukan lampiran inline seperti email dari sistem.
Artinya penerima yang memblokir gambar eksternal tidak akan melihat banner-nya.

---

## Troubleshooting

| Pesan | Artinya | Tindakan |
| --- | --- | --- |
| `ERROR: alamat email tidak valid atau kosong` | Argumen ke-1 kosong atau bukan email | Perbaiki argumennya |
| `ERROR: sub_category harus salah satu dari...` | Argumen ke-2 salah eja | Pakai persis `Kontraktor`, `Supplier`, atau `Perseorangan` (huruf besar di awal) |
| `ERROR: ... sudah terdaftar di sistem` | Email itu sudah punya akun | Tidak perlu diundang, vendor tinggal login |
| `WARNING: lookup urutan invite_code gagal` | Query urutan bulanan gagal | Undangan tetap jalan dengan kode cadangan berformat `IVT-yymm-XXXX`. Cek koneksi database. |
| `hanya untuk CLI` | Dijalankan lewat browser | Script ini sengaja menolak akses web. Jalankan lewat SSH. |

---

## Catatan

Ini solusi darurat. Akar masalahnya adalah kuota mailbox Hostinger, dan
selama pengirimnya masih mailbox itu, masalahnya akan berulang.

Perbaikan permanennya adalah memindahkan pengiriman ke provider lain — Gmail
Workspace `@kawanlamacorp.com` atau layanan transaksional seperti Brevo — dengan
mengubah blok `_buildMailer()` di `includes/notification_helper.php`. Setelah
itu jalur manual ini tidak diperlukan lagi, dan tampilan emailnya juga otomatis
kembali persis seperti desain aslinya karena tidak lewat copy-paste.

Perbaikan sampingan yang murah: 7 dari 8 alamat Cc statis adalah orang per orang,
padahal `group_gp@kawanlamaretail.com` sudah berupa alias grup. Kalau ketujuh
orang itu memang anggota grup tersebut, memangkasnya menjadi 1 alias menurunkan
beban dari 10 penerima jadi 3 per undangan — tanpa ada yang berhenti menerima
tembusan.
