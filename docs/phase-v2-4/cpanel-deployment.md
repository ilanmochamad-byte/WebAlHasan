# V2 Fase 4 — Deployment cPanel, Environment, dan Cron

> Fase 4 **tidak** dideploy otomatis. Dokumen ini adalah runbook manual yang
> dijalankan manusia setelah audit Codex menyatakan Fase 4 lolos.

> **Status operasional 26 Agustus 2026:** push telah tiba pada perangkat fisik
> Android dan iOS, tetapi worker produksi belum berjalan otomatis. Cron cPanel
> setiap menit masih harus dipasang atau diverifikasi. WhatsApp ditangguhkan,
> default `OFF`, dan tidak boleh diaktifkan atau dijadwalkan sampai checklist
> aktivasi masa depan selesai.

## 1. Prasyarat

- [ ] Audit Fase 4 oleh Codex selesai dan disetujui.
- [ ] Backup Fase 3 + manifest jumlah baris tersimpan dan sudah diuji restore.
- [ ] Hash commit rilis Fase 3 (`0b14040`) tercatat sebagai titik pemulihan.
- [ ] Versi PHP cPanel diketahui, dan `php -l` seluruh berkas baru/diubah sudah
      dijalankan **dengan versi PHP tersebut** (sandbox memakai PHP 8.4).
- [ ] Ekstensi PHP `openssl` aktif (wajib untuk melindungi token push).
- [ ] Ekstensi `curl` aktif (disarankan; ada jalur cadangan `allow_url_fopen`).
- [ ] `PUSH_TOKEN_KEY` produksi sudah dibuat dan disimpan di tempat aman.
- [ ] WhatsApp: **jangan** disiapkan pada tahap ini kecuali pemilik produk sudah
      menyetujui vendor, template, dan credential (lihat
      `whatsapp-provider-checklist.md`).

## 2. Berkas yang berubah pada Fase 4

Backend (WebAlHasan):

```
database/migrations/008_v2_phase4_notifikasi_push_whatsapp.sql   (baru)
database/rollbacks/008_v2_phase4_notifikasi_push_whatsapp.sql    (baru)
app/Notification/**                                              (baru — 16 berkas)
app/Api/NotificationApiService.php                               (baru)
app/bootstrap.php                                                (diubah — service Fase 4, aditif)
app/Izin/IzinWorkflowService.php                                 (diubah — pemanggilan notify())
api/v1/index.php                                                 (diubah — rute notifikasi, aditif)
portal/notifikasi.php                                            (baru)
portal/_ui.php                                                   (diubah — menu + lencana)
admin/admin_notifikasi.php                                       (baru)
admin/sidebar.php                                                (diubah — menu)
bin/notifikasi_worker.php                                        (baru — CLI, untuk cron)
bin/v2_phase4_preflight.php                                      (baru — CLI)
bin/v2_phase4_verify.php                                         (baru — CLI)
bin/v2_phase4_run_all_tests.sh                                   (baru — hanya sandbox)
tests/v2_phase4_*.php                                            (baru — menolak database non-_test)
tests/v2_phase3_static.php                                       (diubah — batas ruang lingkup)
.env.example                                                     (diubah — nama environment Fase 4)
docs/phase-v2-4/*.md                                             (baru)
```

Aplikasi mobile (alhasanApps):

```
package.json / package-lock.json          (diubah — + expo-notifications ~57.0.13)
app.json                                   (diubah — plugin expo-notifications, kanal `perizinan`)
app.config.ts                              (diubah — easProjectId)
src/notifications/**                       (baru — 3 berkas)
src/app/(app)/(notifikasi)/**              (baru — tab pusat notifikasi)
src/app/notifikasi/[id].tsx                (baru — detail)
src/app/notifikasi/perangkat.tsx           (baru — perangkat & push)
src/app/_layout.tsx                        (diubah — NotificationProvider + rute)
src/components/app-tabs.tsx                (diubah — tab + lencana)
src/api/client.ts, src/api/types.ts        (diubah — endpoint Fase 4, aditif)
src/auth/auth-context.tsx                  (diubah — pencabutan perangkat saat logout)
```

Tidak ada berkas yang dihapus.

## 3. Environment server

Isi melalui **cPanel → Setup PHP Environment** atau berkas `.env` di luar
document root. **Jangan pernah** commit nilainya. Daftar lengkap ada pada
`.env.example`; ringkasnya:

| Nama | Wajib | Keterangan |
| --- | --- | --- |
| `PUSH_TOKEN_KEY` | ya (untuk push) | 32 byte acak base64. Buat dengan `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"` |
| `EXPO_ACCESS_TOKEN` | tidak | hanya bila proyek Expo mewajibkan autentikasi pengiriman |
| `PUSH_TIMEOUT_SECONDS` | tidak | default 10 (3–30) |
| `NOTIFIKASI_WORKER_BATCH` | tidak | default 25 (1–100) |
| `WHATSAPP_PROVIDER` | tidak | kosong = mati (default). `fake` = adapter uji. Nilai lain = adapter HTTP generik |
| `WHATSAPP_FAKE_MODE` | tidak | `ok` \| `gagal` \| `gagal_permanen` \| `verify_gagal` |
| `WHATSAPP_FAKE_JOURNAL` | tidak | berkas jurnal adapter uji |
| `WHATSAPP_API_URL` | bila HTTP | endpoint kirim, **wajib HTTPS** |
| `WHATSAPP_API_TOKEN` | bila HTTP | credential |
| `WHATSAPP_AUTH_HEADER` | tidak | default `Authorization` |
| `WHATSAPP_AUTH_PREFIX` | tidak | default `Bearer ` |
| `WHATSAPP_SENDER_ID` | tidak | pengenal pengirim vendor |
| `WHATSAPP_TEMPLATE_NAME` | tidak | nama template resmi |
| `WHATSAPP_FIELD_TO` / `WHATSAPP_FIELD_TEXT` | tidak | nama field body; default `to` / `text` |
| `WHATSAPP_VERIFY_URL` | tidak | endpoint pemeriksaan konfigurasi |
| `WHATSAPP_TIMEOUT_SECONDS` | tidak | default 10 (3–30) |

**Mengganti `PUSH_TOKEN_KEY`** membuat seluruh token perangkat lama tidak dapat
dibuka. Dampaknya terkendali: worker mencabut token yang tidak dapat dibuka
(`token_invalid`) dan aplikasi mendaftar ulang saat dibuka berikutnya. Rencanakan
penggantian pada jam sepi dan beri tahu pengguna bahwa push mungkin tertunda.

## 4. Langkah deployment

1. **Preflight dan backup**
   ```bash
   php bin/v2_phase4_preflight.php
   php bin/verify_restore.php
   ```
   Selesaikan seluruh item "blokir" sebelum melanjutkan.

2. **Unggah kode** sesuai daftar §2 ke document root. Jangan menyalin `.env`
   sandbox. Direktori `tests/` boleh disertakan (seluruh berkas uji menolak
   berjalan di luar database `*_test`) atau dihilangkan sesuai kebijakan.

3. **Isi environment** sesuai §3, lalu muat ulang PHP.

4. **Jalankan migrasi**
   ```bash
   php bin/migrate.php status     # 001 … 007 [diterapkan], 008 [menunggu]
   php bin/migrate.php up
   php bin/migrate.php status     # 001 … 008 [diterapkan]
   ```

5. **Verifikasi**
   ```bash
   php bin/v2_phase4_verify.php storage/backups/v2-phase4/<stempel>/manifest.json
   ```

6. **Lint pada versi PHP produksi**
   ```bash
   find app/Notification bin tests -name '*.php' -newer composer.json 2>/dev/null | xargs -r -n1 php -l
   php -l api/v1/index.php && php -l app/bootstrap.php \
     && php -l app/Izin/IzinWorkflowService.php \
     && php -l portal/notifikasi.php && php -l admin/admin_notifikasi.php
   ```

7. **Pasang cron** (lihat §5).

8. **Smoke test manual pada produksi**
   - buka `portal/notifikasi.php` sebagai admin, pengurus, murobi, dan orang tua;
   - buka `admin/admin_notifikasi.php`, jalankan **Periksa konfigurasi** untuk
     Push, lalu kirim **pesan uji in-app**;
   - buat satu pengajuan uji dan pastikan murobi tujuan menerima notifikasi
     in-app; batalkan pengajuan itu setelah selesai.

9. **Nyalakan push** hanya setelah smoke test perangkat nyata (lihat
   `mobile-build-and-smoke-test.md`) berhasil.

10. **WhatsApp tetap MATI.** Keputusan produk 26 Agustus 2026 menangguhkan
    kemampuan ini sampai batas waktu yang tidak ditentukan. Aktivasi masa
    depan tetap memerlukan persetujuan penyedia, WABA/nomor bisnis, template
    utility, opt-in/opt-out, credential environment, dan seluruh checklist
    `whatsapp-provider-checklist.md`.

## 5. Konfigurasi cron cPanel

**cPanel → Advanced → Cron Jobs.** Gunakan path PHP CLI absolut milik cPanel
(sering `/usr/local/bin/php` atau `/opt/cpanel/ea-php82/root/usr/bin/php`).

Untuk push produksi, pasang satu putaran setiap menit:

```
* * * * * /usr/local/bin/php /home/AKUN/public_html/bin/notifikasi_worker.php --kanal=push >> /home/AKUN/logs/notifikasi-push.log 2>&1
```

Bila WhatsApp kelak diaktifkan setelah seluruh gerbang masa depan lulus,
kanalnya dapat dijadwalkan terpisah:

```
*/15 * * * * /usr/local/bin/php /home/AKUN/public_html/bin/notifikasi_worker.php --kanal=whatsapp >> /home/AKUN/logs/notifikasi-wa.log 2>&1
```

Catatan penting:

- **Tumpang tindih aman.** Sewa proses (`notifikasi_worker_lock`) dan klaim
  baris membuat putaran yang tumpang tindih tidak pernah mengirim pesan ganda.
  Proses kedua keluar dengan status 0 dan pesan "dilewati".
- **Kanal mati = tidak ada koneksi keluar.** Worker berhenti sebelum mengklaim
  baris apa pun, jadi cron tetap aman dijalankan sebelum push/WhatsApp dinyalakan.
- **Exit code 0** dipakai juga saat ada pengiriman gagal, supaya cPanel tidak
  membanjiri email operator. Pantau kegagalan lewat halaman
  **Kanal Notifikasi → Pengiriman gagal**.
- **Status `Sent` saat ini berasal dari tiket awal Expo.** Worker belum
  mengambil push receipt akhir FCM/APNs, sehingga status itu tidak boleh
  dianggap sebagai bukti delivery end-to-end yang sepenuhnya akurat.
- **Rotasi log**: berkas log cron tumbuh terus. Tambahkan rotasi bulanan atau
  arahkan ke `/dev/null` setelah operasi stabil. Worker tidak pernah mencetak
  token, nomor tujuan, atau credential.

Perintah manual yang aman untuk pemeriksaan:

```bash
php bin/notifikasi_worker.php --status                 # hanya melaporkan antrean
php bin/notifikasi_worker.php --uji-coba               # tidak mengklaim, tidak mengirim
php bin/notifikasi_worker.php --kanal=push --batas=5   # satu putaran kecil
```

## 6. Rencana rollback deployment

1. Matikan cron notifikasi.
2. Kembalikan kode ke commit Fase 3 (`0b14040`).
3. Bila skema harus ikut mundur: `php bin/migrate.php rollback` — baca
   konsekuensinya pada `migration-and-rollback.md` §5.
4. Bila data harus dipulihkan: ikuti `migration-and-rollback.md` §7.

Karena Fase 4 aditif, mengembalikan kode saja (tanpa rollback skema) sudah
mengembalikan perilaku sistem ke Fase 3: kolom dan tabel tambahan tetap ada
tetapi tidak dipakai.

## 7. Yang TIDAK dilakukan pada deployment ini

- Tidak menyalakan WhatsApp produksi.
- Tidak mengirim pesan uji kepada wali atau pengurus (pesan uji hanya kepada
  admin yang menekan tombol).
- Tidak menghapus modul lama mana pun.
- Tidak mengubah kontrak API V1 maupun Fase 3.
