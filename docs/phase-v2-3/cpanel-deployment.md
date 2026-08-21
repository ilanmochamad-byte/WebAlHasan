# V2 Fase 3 — Prosedur Deployment cPanel (Backend)

> Fase 3 **tidak** dideploy otomatis. Dokumen ini adalah runbook manual yang
> dijalankan manusia setelah audit Codex menyatakan Fase 3 lolos.

## 1. Prasyarat

- [ ] Audit Fase 3 oleh Codex selesai dan disetujui.
- [ ] Backup Fase 2 + manifest jumlah baris tersimpan dan sudah diuji restore.
- [ ] Hash commit rilis Fase 2 (`f2f674d`, merge `c30add9`) tercatat sebagai titik pemulihan.
- [ ] Versi PHP cPanel diketahui, dan `php -l` seluruh berkas baru/diubah sudah
      dijalankan **dengan versi PHP tersebut** (sandbox memakai PHP 8.4).
- [ ] `.env` produksi sudah memuat `API_TOKEN_HASH_SECRET` yang kuat.

## 2. Berkas yang berubah pada Fase 3

Backend (WebAlHasan):

```
api/v1/index.php                    (diubah — router, aditif)
app/Api/IzinApiService.php          (baru)
app/Api/ApiAuthService.php          (diubah — capability + kelayakan login)
app/Api/ApiAuthRepository.php       (diubah — kolom relasi akun)
app/Auth/ApiTokenAuthenticator.php  (diubah — assertScheduleAccess + relasi akun)
app/bootstrap.php                   (diubah — izin_api_service())
portal/_ui.php                      (diubah — penonaktifan tombol saat submit)
docs/phase-v2-3/*.md                (baru)
docs/api-v1.md                      (diubah — bagian perizinan V2)
bin/v2_phase3_sandbox_seed.php      (baru — CLI, hanya database *_test)
tests/v2_phase3_*.php               (baru — tidak dipakai produksi)
tests/v2_phase1_static.php          (diubah — assertion Fase 3)
tests/v2_phase2_static.php          (diubah — assertion Fase 3)
```

Tidak ada berkas yang dihapus. Tidak ada migrasi baru.

## 3. Langkah deployment

1. **Aktifkan mode pemeliharaan singkat** (opsional; perubahan ini kompatibel
   mundur sehingga downtime tidak wajib).
2. **Backup**
   ```bash
   php bin/v2_phase2_preflight.php
   php bin/verify_restore.php
   ```
3. **Unggah kode.** Salin berkas pada daftar §2 ke document root cPanel
   (`public_html` atau subfolder aplikasi). Jangan menyalin:
   - `.env` sandbox,
   - direktori `tests/` bila kebijakan Anda melarang berkas uji di produksi
     (opsional; berkas uji menolak berjalan di luar database `*_test`),
   - `bin/v2_phase3_sandbox_seed.php` bila tidak diperlukan (skrip ini menolak
     berjalan pada database non-`_test`, jadi aman bila tetap ikut).
4. **Verifikasi struktur database** (tidak ada perubahan yang diterapkan):
   ```bash
   php bin/migrate.php status     # 001 … 007 [diterapkan]
   php bin/v2_phase2_verify.php
   ```
5. **Lint pada versi PHP produksi**
   ```bash
   for f in api/v1/index.php app/Api/IzinApiService.php app/Api/ApiAuthService.php \
            app/Api/ApiAuthRepository.php app/Auth/ApiTokenAuthenticator.php \
            app/bootstrap.php portal/_ui.php; do php -l "$f"; done
   ```
6. **Smoke test produksi (baca-saja lebih dulu)**
   ```bash
   curl -s https://<domain>/api/v1/ | head
   # login akun uji internal (bukan akun wali/santri sungguhan)
   ```
   Lalu jalankan checklist pada `acceptance-status.md` §Smoke test produksi.
7. **Pantau `error_log`** selama 30 menit pertama.

## 4. Konfigurasi Apache/.htaccess

Fase 3 tidak mengubah `.htaccess`. Rewrite `/api/v1/*` ke `api/v1/index.php`
yang sudah ada tetap dipakai. Pastikan header `Authorization` diteruskan ke PHP
(sudah ditangani `ApiTokenAuthenticator` melalui `HTTP_AUTHORIZATION`,
`REDIRECT_HTTP_AUTHORIZATION`, dan `apache_request_headers()`).

Bila endpoint baru mengembalikan `401` padahal token benar, penyebab paling umum
adalah header `Authorization` yang dibuang Apache. Tambahkan pada `.htaccess`:

```apache
RewriteEngine On
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]
```

## 5. Environment

Tidak ada variabel environment baru pada Fase 3. Yang dipakai tetap:

| Variabel | Catatan |
| --- | --- |
| `API_TOKEN_HASH_SECRET` | Wajib kuat; jangan pernah di-commit |
| `API_TOKEN_TTL_DAYS` | Default 30 |
| `APP_TIMEZONE` | `Asia/Jakarta` |
| `IZIN_LEGACY_ENABLED` | Tetap `false` |

## 6. Cron

Tidak ada. Cron/worker adalah pekerjaan Fase 4 (notifikasi) dan **tidak**
dipasang pada Fase 3.

## 7. Rollback

Lihat `migration-and-rollback.md`. Ringkasnya: kembalikan berkas pada daftar §2
ke versi commit rilis Fase 2 (`c30add9`). Tidak ada rollback database.

## 8. Larangan

- Jangan menjalankan seed fixture (`bin/v2_phase3_sandbox_seed.php`) pada
  database produksi. Skrip menolak, tetapi jangan mencoba.
- Jangan mengaktifkan `APP_DEBUG=true` di produksi.
- Jangan menyalin `.env` sandbox.
- Jangan mengaktifkan apa pun terkait notifikasi/push/WhatsApp — belum ada pada
  Fase 3.
