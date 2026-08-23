# V2 Fase 4 — Status Kriteria Penerimaan

Tanggal: 23 Agustus 2026. Sumber bukti: `test-results.md`.

## 1. Kriteria penerimaan PRD Fase 4

| # | Kriteria | Status | Bukti |
| --- | --- | --- | --- |
| 1 | Setiap peristiwa yang ditentukan menghasilkan satu notifikasi in-app untuk penerima yang berhak | **TERPENUHI** | integrasi KN-1a…KN-1r, termasuk KN-1c2 untuk relasi kelas nonaktif (9 peristiwa diuji satu per satu) |
| 2 | Pengguna tidak dapat membaca notifikasi pengguna lain melalui perubahan ID | **TERPENUHI** | KN-2a…KN-2e, KA-2a…KA-2e, WN-5a…WN-5d |
| 3 | Push tiba pada perangkat uji Android dan iOS tanpa memuat alasan izin lengkap | **MENUNGGU SMOKE TEST PERANGKAT** | lihat §2 |
| 4 | Menonaktifkan push menghentikan enqueue baru tanpa mengganggu in-app | **TERPENUHI** | KN-4e…KN-4i |
| 5 | WhatsApp tidak dapat diaktifkan jika pemeriksaan konfigurasi gagal | **TERPENUHI** | KN-5a…KN-5e (termasuk pemeriksaan penyedia lama), KA-7a…KA-7d, WN-7g/WN-7h |
| 6 | Saat WhatsApp aktif dan provider siap, pesan uji serta satu notifikasi keputusan berhasil dikirim | **KONDISIONAL — BELUM DIUJI DENGAN PENYEDIA NYATA** | lihat §3 |
| 7 | Saat WhatsApp mati/tidak siap, pengajuan dan keputusan tetap berhasil tanpa request ke provider | **TERPENUHI** | KN-6a…KN-6j (penyedia mata-mata mencatat **0** panggilan) |
| 8 | Retry event yang sama tidak menghasilkan pesan ganda pada kanal yang sama | **TERPENUHI** | KN-3a…KN-3d, KC-1b…KC-1d |
| 9 | Secret provider tidak muncul di respons API, log, audit, database, atau bundle mobile | **TERPENUHI** | KN-8a…KN-8l, KA-9a…KA-9d, statis §5/§6/§10/§11 |
| 10 | Status kirim dan error aman dapat dilihat admin; perubahan sakelar tercatat pada audit | **TERPENUHI** | KN-9a…KN-9h, KN-10a…KN-10d, KA-6c…KA-6m |

**8 dari 10 kriteria terpenuhi. Fase 4 belum selesai/belum diterima.** Dua
sisanya (nomor 3 dan 6) memerlukan perangkat nyata dan penyedia nyata;
keduanya **tidak diklaim lulus**.

## 2. Kriteria 3 — push pada perangkat Android dan iOS

**Status: MENUNGGU SMOKE TEST MANUSIA.**

Keputusan penerimaan Fase 3 (23 Agustus 2026) menerima iPhone fisik dan
simulator Android 16 sebagai bukti pengganti untuk gerbang Fase 3. Pengecualian
itu **tidak berlaku** untuk kriteria ini: PRD Fase 4 secara khusus mensyaratkan
push benar-benar **tiba** pada perangkat Android dan iOS.

Yang tidak tersedia di sandbox audit:

- perangkat Android maupun iOS fisik;
- development build (EAS) — push jarak jauh tidak berfungsi di Expo Go sejak
  SDK 53;
- credential push FCM (Android) dan APNs (iOS);
- Expo `projectId` dari `eas init`.

Yang **sudah** dibuktikan tanpa perangkat nyata:

| Aspek | Bukti |
| --- | --- |
| `expo-notifications` versi selaras SDK 57 (`~57.0.13`), tanpa upgrade SDK | statis §11 |
| Kanal Android `perizinan` dibuat aplikasi **sebelum** meminta izin | statis §11 |
| `channelId` yang dikirim server sama dengan kanal aplikasi | integrasi KN-7w |
| Izin tidak diminta berulang tanpa kebutuhan (`canAskAgain`) | statis §11 |
| Token diambil dengan `projectId` sesuai dokumentasi SDK 57 | statis §11 |
| Registrasi menolak simulator/emulator dengan pesan yang jelas | statis §11 |
| Handler foreground memakai API SDK 57 (`shouldShowBanner`/`shouldShowList`) | statis §11 |
| Foreground, ketukan, dan cold start ditangani | statis §11 |
| Enqueue push hanya untuk penerima berperangkat aktif | KN-4e…KN-4h |
| Akun nonaktif langsung kehilangan seluruh token dan tidak dibaca worker | KN-11g, KN-11h |
| Identitas instalasi stabil di SecureStore; registrasi otomatis tidak memunculkan dialog | statis §11 |
| Payload push hanya memuat penunjuk sumber daya | KN-7w, KN-7x |
| **Isi push tidak memuat alasan izin** | KN-7y, KN-1m |
| Tiket sukses → `Sent`; `DeviceNotRegistered` → token dicabut | KN-7u, KN-7z |
| Token tidak pernah bocor ke API, audit, log, atau bundle | KN-8a…KN-8l |

Prosedur pembuktian yang tersisa: `mobile-build-and-smoke-test.md`.

## 3. Kriteria 6 — pengiriman WhatsApp nyata

**Status: KONDISIONAL — BELUM DIUJI.**

Sistem tidak memilih vendor, tidak membuat akun, dan tidak membeli layanan.
Sesuai instruksi, penyedia nyata baru boleh disiapkan setelah pemilik produk
menyetujui vendor, template, dan credential.

Yang **sudah** dibuktikan dengan adapter uji:

| Aspek | Bukti |
| --- | --- |
| Kontrak penyedia netral vendor (interface + 3 implementasi) | statis §4 |
| Default `belum-dipilih`: WhatsApp mati, tanpa koneksi jaringan | KN-6a…KN-6c |
| Adapter HTTP berhenti sebelum koneksi bila environment belum lengkap | KN-6d, KN-6e |
| Enqueue → send → Sent | KN-7e…KN-7g |
| Kegagalan sementara → Failed + backoff + riwayat percobaan | KN-7i…KN-7n |
| Kegagalan permanen → retry berhenti | KN-7o |
| Percobaan ulang admin memakai baris yang sama (tanpa duplikat) | KN-7p…KN-7r |
| Deduplikasi peristiwa/kanal/penerima | KN-3a…KN-3d |
| Concurrency dua worker: 12 baris → 12 pesan, 0 ganda | KC-1b…KC-1d |
| Sewa proses diperpanjang pemilik; worker lain tidak dapat memperpanjang | KC-3c, KC-3d |
| Adapter uji menyatakan dirinya bukan pengiriman nyata, dan panel admin menuliskannya | statis §4 |
| Adapter uji ditolak pada `APP_ENV=production` | KN-7c |

Prosedur pembuktian yang tersisa: `whatsapp-provider-checklist.md`.

## 4. Persyaratan implementasi PRD Fase 4 (§6 poin 1–12)

| # | Persyaratan | Status |
| --- | --- | --- |
| 1 | Notifikasi in-app pada pengajuan, routing admin, penetapan murobi, keputusan, pembatalan, koreksi | **SELESAI** — 9 peristiwa |
| 2 | Pusat notifikasi web/mobile, jumlah belum dibaca, detail, tandai dibaca, pagination | **SELESAI** — `portal/notifikasi.php`, tab `(notifikasi)` di aplikasi |
| 3 | Registrasi dan pencabutan token push per pengguna/perangkat dengan `expo-notifications` | **SELESAI** |
| 4 | Push tanpa alasan lengkap; deep link membuka detail setelah otorisasi | **SELESAI** (kedatangan push: menunggu perangkat) |
| 5 | Halaman admin untuk status kanal, pengujian konfigurasi, sakelar on/off | **SELESAI** — `admin/admin_notifikasi.php` |
| 6 | Adapter provider WhatsApp dan konfigurasi environment tanpa secret pada database/log/audit | **SELESAI** |
| 7 | Outbox dengan unique event/channel/recipient | **SELESAI** (kunci unik sejak migrasi 006, dipakai penuh pada Fase 4) |
| 8 | Worker/cron cPanel untuk push dan WhatsApp; perintah manual yang aman | **SELESAI** — `bin/notifikasi_worker.php` (`--status`, `--uji-coba`, `--kanal`, `--batas`) |
| 9 | Catat `Queued`, `Sent`, `Failed`, jumlah percobaan, error aman, waktu terakhir | **SELESAI** |
| 10 | Retry terbatas dengan backoff; kegagalan permanen dapat dilihat admin | **SELESAI** — maks. 5 percobaan, backoff 60 s → 3600 s |
| 11 | Jika WhatsApp off/tidak siap, pengajuan tetap berhasil dan in-app/push berjalan sesuai pengaturan | **SELESAI** |
| 12 | Audit perubahan kanal dan pengujian konfigurasi tanpa menyimpan credential | **SELESAI** — `notifikasi_pengaturan_audit` + `audit_logs` |

## 5. Risiko yang tercatat

| Risiko | Dampak | Mitigasi |
| --- | --- | --- |
| Push belum diuji pada perangkat nyata | Kriteria 3 belum terpenuhi; kemungkinan masalah credential/build baru terlihat saat smoke test | Checklist rinci tersedia; in-app tidak terpengaruh apa pun hasilnya |
| Penyedia WhatsApp belum dipilih | Kriteria 6 belum terpenuhi | Default mati; tiga lapis pengaman mencegah aktivasi tanpa pemeriksaan lulus |
| PHP/MariaDB cPanel berbeda dari sandbox (PHP 8.4 / MariaDB 10.11) | `CHECK` constraint diabaikan MySQL 5.7 | Aturan yang sama juga ditegakkan lapisan aplikasi dan klausa WHERE; `php -l` wajib diulang pada versi cPanel |
| Cron cPanel dapat tumpang tindih atau batch berjalan lama | Pesan ganda | Dua lapis sewa dengan heartbeat proses + klaim baris terbukti pada uji concurrency |
| `PUSH_TOKEN_KEY` hilang atau diganti | Token lama tidak dapat dibuka | Worker mencabut token yang tidak dapat dibuka; aplikasi mendaftar ulang. Prosedur ada pada `cpanel-deployment.md` §3 |
| Pengguna menolak izin notifikasi perangkat | Push tidak tiba pada perangkat itu | In-app tetap menjadi sumber status utama; layar perangkat menjelaskan keadaannya |

## 6. Batas ruang lingkup

Fase 5 (laporan, ekspor CSV/PDF perizinan, migrasi produksi, kesiapan rilis)
**tidak** dikerjakan. `tests/v2_phase3_static.php` memeriksa bahwa tidak ada
migrasi 009 dan tidak ada berkas uji `v2_phase5_*`.
