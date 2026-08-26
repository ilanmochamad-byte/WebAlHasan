# V2 Fase 4 — Status Kriteria Penerimaan

Audit otomatis: 23 Agustus 2026. Uji perangkat fisik: 24 Agustus 2026.
Keputusan produk: 26 Agustus 2026. Sumber bukti: `test-results.md`.

## 0. Baseline implementasi yang dinilai

| Repository | Branch dan status | Commit utama/koreksi |
| --- | --- | --- |
| WebAlHasan | `prd-v2-fase-4`; HEAD `53e0b89`; worktree bersih dan sama dengan `origin/prd-v2-fase-4` saat keputusan dicatat | Claude `279f9accb8939ae6d1696a3ef17216353ac04b8d`; auditor `443dc68`, `98dd057`, `53e0b89` |
| alhasanApps | `prd-v2-fase-4`; HEAD lokal `da04c3a227372b722498aaf11f40e44464e6f9c0`; worktree bersih tetapi lokal ahead satu commit dari origin | Claude `876ec504f56325111747fc2f18e21a25430efa09`; koreksi native push lokal `da04c3a` |

Commit mobile `da04c3a` mengonfigurasi credential native push, Firebase
Android, EAS projectId, dan entitlement iOS. Statusnya tetap dicatat sebagai
lokal/belum didorong; dokumen ini tidak mengklaim origin sudah memuat commit
tersebut.

## 1. Kriteria penerimaan PRD Fase 4

| # | Kriteria | Status | Bukti |
| --- | --- | --- | --- |
| 1 | Setiap peristiwa yang ditentukan menghasilkan satu notifikasi in-app untuk penerima yang berhak | **TERPENUHI** | integrasi KN-1a…KN-1r, termasuk KN-1c2 untuk relasi kelas nonaktif (9 peristiwa diuji satu per satu) |
| 2 | Pengguna tidak dapat membaca notifikasi pengguna lain melalui perubahan ID | **TERPENUHI** | KN-2a…KN-2e, KA-2a…KA-2e, WN-5a…WN-5d |
| 3 | Push tiba pada perangkat uji Android dan iOS tanpa memuat alasan izin lengkap | **TERPENUHI BERDASARKAN UJI PERANGKAT FISIK** | Android Xiaomi 2409BRN2CY dan iPhone 17 Pro menerima push pada 24 Agustus 2026; lihat §2 |
| 4 | Menonaktifkan push menghentikan enqueue baru tanpa mengganggu in-app | **TERPENUHI** | KN-4e…KN-4i |
| 5 | WhatsApp tidak dapat diaktifkan jika pemeriksaan konfigurasi gagal | **TERPENUHI** | KN-5a…KN-5e (termasuk pemeriksaan penyedia lama), KA-7a…KA-7d, WN-7g/WN-7h |
| 6 | Jika WhatsApp diaktifkan pada masa depan, pesan uji serta satu notifikasi keputusan wajib berhasil dikirim melalui penyedia resmi | **DITANGGUHKAN/NON-BLOCKING — TIDAK DIUJI DAN TIDAK DINYATAKAN LULUS** | keputusan produk 26 Agustus 2026; lihat §3 |
| 7 | Saat WhatsApp mati/tidak siap, pengajuan dan keputusan tetap berhasil tanpa request ke provider | **TERPENUHI** | KN-6a…KN-6j (penyedia mata-mata mencatat **0** panggilan) |
| 8 | Retry event yang sama tidak menghasilkan pesan ganda pada kanal yang sama | **TERPENUHI** | KN-3a…KN-3d, KC-1b…KC-1d |
| 9 | Secret provider tidak muncul di respons API, log, audit, database, atau bundle mobile | **TERPENUHI** | KN-8a…KN-8l, KA-9a…KA-9d, statis §5/§6/§10/§11 |
| 10 | Status kirim dan error aman dapat dilihat admin; perubahan sakelar tercatat pada audit | **TERPENUHI** | KN-9a…KN-9h, KN-10a…KN-10d, KA-6c…KA-6m |

**Sembilan kriteria wajib terpenuhi berdasarkan bukti otomatis dan uji fisik.**
Satu kemampuan opsional, yaitu pengiriman WhatsApp nyata, ditangguhkan dan
tidak dihitung sebagai kriteria wajib saat ini berdasarkan keputusan produk
26 Agustus 2026. WhatsApp **tidak diklaim lulus**. Fase 4 diterima dengan
temuan terbuka pada §5 yang wajib dibawa ke kesiapan rilis.

## 2. Kriteria 3 — push pada perangkat Android dan iOS

**Status: TERPENUHI BERDASARKAN UJI PERANGKAT FISIK 24 AGUSTUS 2026.**

Keputusan penerimaan Fase 3 (23 Agustus 2026) menerima iPhone fisik dan
simulator Android 16 sebagai bukti pengganti untuk gerbang Fase 3. Pengecualian
itu **tidak berlaku** untuk kriteria ini: PRD Fase 4 secara khusus mensyaratkan
push benar-benar **tiba** pada perangkat Android dan iOS.

Sandbox audit semula tidak memiliki perangkat fisik, development build, atau
credential FCM/APNs. Pembuktian berikut kemudian dilakukan manusia:

| Platform | Perangkat dan akun | Bukti hasil |
| --- | --- | --- |
| Android | Xiaomi 2409BRN2CY, akun murobi, development build EAS | Firebase berhasil diinisialisasi; perangkat terdaftar dan Push aktif; push pengajuan `#2` tiba dengan isi “Ada pengajuan izin #2 menunggu keputusan Anda. Buka aplikasi untuk melihat detail.”; tidak memuat nama santri, alasan izin, nomor telepon, token, atau data sensitif. |
| iOS | iPhone 17 Pro, akun orang tua, development build EAS dengan entitlement APNs | Perangkat terdaftar dan Push aktif; push keputusan pengajuan `#2` tiba setelah worker dijalankan; kedatangan dikonfirmasi manusia. Screenshot layar kunci belum dimasukkan ke repository. |
| Admin | Panel kanal notifikasi | Push aktif; pemeriksaan konfigurasi `Lulus`; dua perangkat terdaftar dan aktif. |

Keterbatasan bukti yang tetap dicatat:

- Push iPhone sempat menunggu karena cron belum berjalan dan baru tiba setelah
  tombol **Jalankan worker sekali** ditekan.
- Screenshot isi layar kunci iPhone tidak tersedia di repository. Kerahasiaan
  payload tetap dibuktikan oleh pengujian otomatis, tetapi bukti visual iPhone
  tidak boleh diklaim tersedia.
- Deep-link Android sempat gagal ketika `adb reverse` ke Metro terputus.
  Aplikasi kembali normal setelah koneksi dipulihkan, tetapi cold-start dan
  background lengkap belum dibuktikan secara fisik.

Yang tidak tersedia pada sandbox audit awal:

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

Catatan pelaksanaan dan pengujian lanjutan: `mobile-build-and-smoke-test.md`.

## 3. Kriteria 6 — pengiriman WhatsApp nyata

**Status: DITANGGUHKAN/NON-BLOCKING — TIDAK DIUJI DAN TIDAK DINYATAKAN LULUS.**

Pada 26 Agustus 2026 pemilik produk menunda WhatsApp sampai batas waktu yang
tidak ditentukan karena penyedia resmi, WhatsApp Business Account, nomor
bisnis, template utility, opt-in pengguna, dan credential produksi belum
tersedia. Kanal tetap `OFF`, pemeriksaan konfigurasi belum lulus, dan tidak ada
request kepada penyedia. Adapter uji bukan bukti pengiriman nyata.

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

Sebelum kemampuan WhatsApp dapat diaktifkan pada masa depan, seluruh syarat
berikut tetap wajib:

1. penyedia resmi disetujui;
2. opt-in dan opt-out pengguna tersedia;
3. template utility disetujui;
4. credential hanya berada di environment;
5. ketiga lapisan pemeriksaan konfigurasi lulus;
6. pesan uji dan satu notifikasi keputusan tiba melalui penyedia nyata; dan
7. pengujian keamanan, privasi, deduplikasi, retry, serta nol-request-saat-mati
   diulang.

Prosedur aktivasi masa depan tetap disimpan pada
`whatsapp-provider-checklist.md`; keberadaan prosedur ini bukan bukti kelulusan.

## 4. Persyaratan implementasi PRD Fase 4 (§6 poin 1–12)

| # | Persyaratan | Status |
| --- | --- | --- |
| 1 | Notifikasi in-app pada pengajuan, routing admin, penetapan murobi, keputusan, pembatalan, koreksi | **SELESAI** — 9 peristiwa |
| 2 | Pusat notifikasi web/mobile, jumlah belum dibaca, detail, tandai dibaca, pagination | **SELESAI** — `portal/notifikasi.php`, tab `(notifikasi)` di aplikasi |
| 3 | Registrasi dan pencabutan token push per pengguna/perangkat dengan `expo-notifications` | **SELESAI** |
| 4 | Push tanpa alasan lengkap; deep link membuka detail setelah otorisasi | **SELESAI** untuk payload/otorisasi dan kedatangan push; uji fisik cold-start/background lengkap masih menjadi temuan terbuka |
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
| Screenshot layar kunci iPhone tidak tersedia | Bukti visual isi iOS tidak berada di repository | Kedatangan dikonfirmasi manusia dan payload aman dibuktikan otomatis; jangan mengklaim screenshot tersedia |
| Cron worker push produksi belum dipasang/diverifikasi | Push menunggu sampai worker dijalankan manual | Pasang cron cPanel setiap menit, verifikasi log/status, dan simpan bukti operasional |
| Server belum mengambil push receipt akhir FCM/APNs | Status `Sent` hanya membuktikan tiket awal Expo diterima, bukan delivery end-to-end final | Tambahkan pengambilan receipt dan rekonsiliasi status sebelum mengklaim akurasi delivery penuh |
| Deep-link Android cold-start/background belum diuji lengkap | Ketukan notifikasi dapat gagal bila koneksi development ke Metro terputus | Ulangi pada development/release build dengan koneksi stabil; catat cold-start dan background terpisah |
| Commit mobile `da04c3a` masih lokal | Konfigurasi native push belum tersedia pada origin bagi auditor/mesin lain | Push commit setelah peninjauan; jangan menyatakan origin sudah memuatnya sebelum benar-benar didorong |
| Penyedia WhatsApp belum dipilih | Kemampuan opsional ditangguhkan dan tidak diuji nyata | Default mati; checklist dan tiga lapis pengaman tetap wajib sebelum aktivasi masa depan |
| PHP/MariaDB cPanel berbeda dari sandbox (PHP 8.4 / MariaDB 10.11) | `CHECK` constraint diabaikan MySQL 5.7 | Aturan yang sama juga ditegakkan lapisan aplikasi dan klausa WHERE; `php -l` wajib diulang pada versi cPanel |
| Cron cPanel dapat tumpang tindih atau batch berjalan lama | Pesan ganda | Dua lapis sewa dengan heartbeat proses + klaim baris terbukti pada uji concurrency |
| `PUSH_TOKEN_KEY` hilang atau diganti | Token lama tidak dapat dibuka | Worker mencabut token yang tidak dapat dibuka; aplikasi mendaftar ulang. Prosedur ada pada `cpanel-deployment.md` §3 |
| Pengguna menolak izin notifikasi perangkat | Push tidak tiba pada perangkat itu | In-app tetap menjadi sumber status utama; layar perangkat menjelaskan keadaannya |

## 5b. Uji browser sungguhan (tambahan, 23 Agustus 2026)

Selain rangkaian uji otomatis, website dan aplikasi dijalankan di Chromium
sungguhan (Playwright) — halaman benar-benar dirender dan tombol diklik.

| Permukaan | Skrip | Hasil |
| --- | --- | --- |
| Website portal + panel admin | `tests/browser/uji-website.mjs` | **37 pemeriksaan, 0 gagal** |
| Aplikasi React Native (bundel yang sama, lewat `react-native-web`) | `tests/browser/uji-aplikasi.mjs` | **25 pemeriksaan, 0 gagal** |

Rinciannya pada `test-results.md` §9. Dua perbaikan khusus jalur web muncul
dari uji ini (`notification-context.tsx`, `app-tabs.web.tsx`); keduanya tidak
menyentuh logika notifikasi, registrasi push, maupun kontrak API.

Uji browser ini sendiri tidak membuktikan kedatangan push karena
`expo-notifications` melaporkan `tidak_didukung` pada web sesuai dokumentasi
SDK 57. Kriteria 3 kemudian dipenuhi melalui uji perangkat fisik 24 Agustus
2026 pada §2. Kriteria 6 tetap **DITANGGUHKAN/NON-BLOCKING** dan tidak
dinyatakan lulus sebagaimana §3.

## 6. Batas ruang lingkup

Fase 5 (laporan, ekspor CSV/PDF perizinan, migrasi produksi, kesiapan rilis)
**tidak** dikerjakan. `tests/v2_phase3_static.php` memeriksa bahwa tidak ada
migrasi 009 dan tidak ada berkas uji `v2_phase5_*`.
