# V2 Fase 4 — Migrasi 008, Rollback, dan Pemulihan

## 1. Ringkas

| | |
| --- | --- |
| Migrasi | `database/migrations/008_v2_phase4_notifikasi_push_whatsapp.sql` |
| Rollback | `database/rollbacks/008_v2_phase4_notifikasi_push_whatsapp.sql` |
| Preflight | `php bin/v2_phase4_preflight.php` |
| Verifikasi | `php bin/v2_phase4_verify.php [/path/manifest.json]` |
| Sifat | aditif, idempoten, kompatibel MySQL 5.7+/8.x dan MariaDB 10.2+ |

Migrasi 008 **tidak** memuat `DROP TABLE`, `DROP COLUMN`, `DELETE`, atau
`TRUNCATE`. Satu-satunya bentuk `UPDATE` adalah `ON UPDATE CURRENT_TIMESTAMP`
pada definisi kolom dan `ON DUPLICATE KEY UPDATE` saat menyemai dua baris sewa
worker. Diverifikasi otomatis oleh `tests/v2_phase4_static.php`.

## 2. Urutan yang WAJIB diikuti

```bash
# 1. Preflight: backup + manifest + inventaris + laporan konflik.
php bin/v2_phase4_preflight.php
#    exit 0 = aman dilanjutkan
#    exit 3 = ada konflik yang MEMBLOKIR — selesaikan lebih dulu
#    exit 2 = prasyarat lingkungan belum lengkap

# 2. Uji lengkap pada salinan berakhiran _test terlebih dahulu.
#    (lihat docs/phase-v2-4/testing-sandbox.md)

# 3. Migrasi.
php bin/migrate.php up
php bin/migrate.php status

# 4. Verifikasi, bandingkan dengan manifest preflight.
php bin/v2_phase4_verify.php storage/backups/v2-phase4/<stempel>/manifest.json
```

Keluaran preflight (`storage/backups/v2-phase4/<stempel>/`):

| Berkas | Isi |
| --- | --- |
| `database.sql` | backup lengkap |
| `manifest.json` | jumlah baris per tabel + seluruh ID `perizinan` lama |
| `inventory.json` | kolom `notifikasi_outbox`, `perangkat_push`, `pengaturan_notifikasi` sebelum migrasi |
| `conflicts.json` | daftar blokir, peringatan, kesiapan environment (nama saja), kesiapan penerima |

Direktori `storage/backups/` sudah masuk `.gitignore` sehingga backup tidak
pernah ikut ke repositori.

## 3. Apa yang diperiksa preflight

**Memblokir (exit 3):**

- kunci unik `notifikasi_event_channel_recipient_unique` hilang;
- ada kombinasi `(event_key, kanal, penerima_user_id)` duplikat;
- baris `pengaturan_notifikasi` singleton bukan tepat satu;
- `inapp_enabled` bernilai 0 (CHECK migrasi 008 akan menolaknya);
- `whatsapp_enabled = 1` tanpa `whatsapp_check_status = 'Lulus'`;
- ekstensi PHP `openssl` tidak aktif.

**Peringatan (tidak memblokir):**

- `PUSH_TOKEN_KEY` belum diisi — registrasi perangkat akan ditolak sampai
  environment diisi;
- `WHATSAPP_PROVIDER` belum diisi — kondisi default yang memang dikehendaki;
- perangkat push yatim;
- tidak ada akun admin aktif.

Preflight **tidak pernah mencetak nilai environment**, hanya "terisi"/"kosong".

## 4. Apa yang diperiksa verifikasi

1. **Skema**: seluruh kolom, tabel, dan indeks Fase 4 tersedia.
2. **Invarian notifikasi**: tidak ada duplikat `(event_key, kanal, penerima)`;
   in-app tetap aktif; WhatsApp tidak menyala tanpa pemeriksaan lulus; seluruh
   baris in-app berstatus `Sent`; tidak ada riwayat percobaan yatim.
3. **Perlindungan token**: seluruh `token_hash` berbentuk heksadesimal 64
   karakter; tidak ada token terbaca pada `token_terlindungi`, isi notifikasi,
   atau audit kanal.
4. **Keutuhan Fase 1–3**: jumlah dan ID `perizinan` lama sama dengan manifest;
   tidak ada pengajuan tanpa santri; tetap satu keputusan per pengajuan.

## 5. Rollback

```bash
php bin/migrate.php rollback     # melepas 008 (migrasi terakhir)
```

**Yang dilepas:** tabel `notifikasi_percobaan`, `notifikasi_pengaturan_audit`,
`notifikasi_worker_lock`; kolom operasional pada `notifikasi_outbox`,
`perangkat_push`, `pengaturan_notifikasi`; indeks Fase 4; CHECK in-app.

**Yang TIDAK disentuh:** seluruh data bisnis Fase 1–3 dan V1. Baris
`notifikasi_outbox` (termasuk notifikasi in-app yang sudah dibaca pengguna),
`perangkat_push`, dan `pengaturan_notifikasi` tetap ada — hanya kolom jejak
operasional yang hilang. Tabel `perizinan`, `izin_*`, dan `audit_logs` tidak
disentuh sama sekali.

**Kehilangan data yang disengaja:** riwayat percobaan pengiriman dan audit
khusus kanal ikut terhapus bersama tabelnya. Jejak perubahan sakelar juga
tercatat pada `audit_logs` umum yang TIDAK dihapus, sehingga pertanggungjawaban
tetap dapat ditelusuri.

**Konsekuensi operasional setelah rollback:**

- worker tidak dapat berjalan (tabel sewa hilang) — matikan cron lebih dulu;
- token perangkat tetap tersimpan tetapi kolom `push_aktif` hilang, sehingga
  seluruh perangkat aktif diperlakukan sama;
- in-app dapat dimatikan lagi secara teknis (CHECK hilang) — jangan lakukan.

Rollback aman dijalankan berulang (`DROP … IF EXISTS` dan pemeriksaan
INFORMATION_SCHEMA). Pelepasan CHECK memilih sintaks MariaDB
`DROP CONSTRAINT` atau MySQL `DROP CHECK` secara dinamis agar tetap kompatibel
dengan versi server yang didokumentasikan.

## 6. Menjalankan ulang migrasi

Migrasi 008 idempoten: setiap `ALTER TABLE` dibungkus pemeriksaan
INFORMATION_SCHEMA dan setiap `CREATE TABLE` memakai `IF NOT EXISTS`.
Menjalankan berkasnya langsung dua kali tidak menghasilkan error
"duplicate column/key/constraint". Ini sudah diuji pada sandbox:
apply → jalankan ulang → rollback → jalankan ulang rollback → apply lagi.

## 7. Pemulihan dari backup

```bash
# 1. Buat database pemulihan terpisah (JANGAN menimpa produksi).
mysql -u <user> -p -e "CREATE DATABASE webalhasan_restore_test
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Muat backup preflight.
mysql -u <user> -p webalhasan_restore_test \
  < storage/backups/v2-phase4/<stempel>/database.sql

# 3. Bandingkan jumlah baris dengan manifest.
DB_NAME=webalhasan_restore_test php bin/verify_restore.php \
  storage/backups/v2-phase4/<stempel>/manifest.json

# 4. Verifikasi invarian Fase 4 pada salinan pulihan.
DB_NAME=webalhasan_restore_test php bin/v2_phase4_verify.php \
  storage/backups/v2-phase4/<stempel>/manifest.json
```

Setelah pemulihan produksi (jika sampai diperlukan):

1. **Matikan cron notifikasi terlebih dahulu** agar worker tidak memproses
   antrean dari salinan lama.
2. Kosongkan sewa yang tertinggal:
   `UPDATE notifikasi_worker_lock SET pemilik = '', kedaluwarsa_pada = '1970-01-02 00:00:00';`
3. Lepaskan klaim baris yang menggantung:
   `UPDATE notifikasi_outbox SET locked_by = NULL, locked_until = NULL WHERE locked_until < NOW();`
4. Periksa apakah ada baris `Queued` yang sebenarnya sudah terkirim sebelum
   pemulihan. Bila ragu, tandai `gagal_permanen = 1` dengan
   `error_kode = 'PEMULIHAN_MANUAL'` daripada mengirim ulang: pesan ganda lebih
   merugikan daripada pesan yang hilang, dan notifikasi in-app tetap ada.
5. Nyalakan kembali cron.

## 8. Catatan kompatibilitas cPanel

- Migrasi memakai `CHECK` constraint dan kolom `GENERATED`, sama seperti
  migrasi 006/007 yang sudah lolos pada lingkungan ini. MariaDB 10.2+ dan
  MySQL 8.0.16+ menegakkannya; MySQL 5.7 mem-parsing tetapi mengabaikan CHECK.
  **Karena itu lapisan aplikasi dan klausa WHERE repositori tetap menegakkan
  aturan yang sama** — CHECK adalah pengaman ketiga, bukan satu-satunya.
- `token_terlindungi` disimpan sebagai base64 (ASCII) walaupun kolomnya
  VARBINARY, agar bebas dari risiko konversi charset pada konfigurasi server
  yang tidak kita kendalikan.
- Uji ulang `php -l` dan migrasi pada versi PHP/MariaDB cPanel sebelum rilis
  produksi; sandbox audit memakai PHP 8.4 dan MariaDB 10.11.
