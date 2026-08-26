# V2 Fase 4 — Hasil Pengujian

Dijalankan ulang auditor 23 Agustus 2026 pada sandbox terisolasi (PHP 8.4.14,
MariaDB 12.3.2, Node 26.7.0, npm 11.19.0). Prosedur lengkap:
`testing-sandbox.md`.

Bukti perangkat fisik ditambahkan manusia pada 24 Agustus 2026. Keputusan
produk mengenai penangguhan WhatsApp dicatat pada 26 Agustus 2026. Bukti
manual tersebut tidak mengubah hasil sandbox dan dibedakan secara eksplisit
dari pengujian otomatis.

**Tidak ada satu pun permintaan jaringan keluar selama pengujian.** Push
memakai klien tiruan (`PushClient`), WhatsApp memakai adapter uji.

## 1. Ringkasan

```
--- Statis ---
tests/phase1_static.php                        LULUS   (64 pemeriksaan)
tests/phase2_static.php                        LULUS   (46 pemeriksaan)
tests/phase3_static.php                        LULUS   (34 pemeriksaan)
tests/phase4_static.php                        LULUS   (38 pemeriksaan)
tests/phase5_static.php                        LULUS   (36 pemeriksaan)
tests/v2_phase1_static.php                     LULUS   (126 pemeriksaan)
tests/v2_phase2_static.php                     LULUS   (169 pemeriksaan)
tests/v2_phase3_static.php                     LULUS   (146 pemeriksaan)
tests/v2_phase4_static.php                     LULUS   (283 pemeriksaan)
--- Integrasi regresi V1 ---
tests/phase2_integration.php                   LULUS   (12 pemeriksaan)
tests/phase3_integration.php                   LULUS   (10 pemeriksaan)
tests/phase4_integration.php                   LULUS   (14 pemeriksaan)
tests/phase5_integration.php                   LULUS   (20 pemeriksaan)
--- Integrasi regresi V2 Fase 1-2 ---
tests/v2_phase1_integration.php                LULUS   (39 pemeriksaan)
tests/v2_phase2_integration.php                LULUS   (94 pemeriksaan)
tests/v2_phase2_navigasi_murobi.php            LULUS   (32 pemeriksaan)
tests/v2_phase2_web_smoke.php                  LULUS   (35 pemeriksaan)
--- Kontrak API Fase 3 ---
tests/v2_phase3_api_contract.php               LULUS   (116 pemeriksaan)
--- Fase 4: notifikasi, push, WhatsApp ---
tests/v2_phase4_integration.php                LULUS   (122 pemeriksaan)
tests/v2_phase4_api_contract.php               LULUS   (92 pemeriksaan)
tests/v2_phase4_concurrency.php                LULUS   (20 pemeriksaan)
tests/v2_phase4_web_smoke.php                  LULUS   (46 pemeriksaan)

SELURUH PENGUJIAN OTOMATIS LULUS.
```

**Total: 23 berkas, 1.594 pemeriksaan, 0 gagal.**

Regresi Fase 1–3 dan V1: **tetap lulus tanpa perubahan hasil** (jumlah
pemeriksaan naik pada `phase1_static` dan `v2_phase3_static` karena assertion
baru ditambahkan, bukan karena perilaku berubah).

## 2. Lint dan tipe

| Perintah | Hasil |
| --- | --- |
| `php -l` seluruh berkas PHP baru/diubah | lulus (dijalankan juga otomatis sebagai bagian `tests/v2_phase4_static.php` §13) |
| `npm run lint` (`npx expo lint`) | lulus, 0 error 0 warning |
| `npx tsc --noEmit` | lulus, 0 error |
| `npx expo export -p web` | lulus; seluruh rute Fase 4 ter-bundle (`/(app)/(notifikasi)/notifikasi`, `/notifikasi/[id]`, `/notifikasi/perangkat`) |

## 3. Migrasi

| Langkah | Hasil |
| --- | --- |
| `php bin/migrate.php up` (008) | diterapkan |
| Menjalankan ulang berkas migrasi 008 langsung | tanpa error (idempoten) |
| `php bin/migrate.php rollback` | 008 dilepas |
| Menjalankan ulang berkas rollback langsung | tanpa error (idempoten) |
| `php bin/migrate.php up` lagi | 008 diterapkan kembali |
| Perbandingan data bisnis sebelum/rollback/apply | jumlah dan SHA-256 daftar ID `perizinan`, `izin_pengajuan`, `izin_keputusan`, `izin_riwayat_status` identik |
| `php bin/v2_phase4_preflight.php` | exit 0, 0 blokir, 2 peringatan (PUSH_TOKEN_KEY & WHATSAPP_PROVIDER kosong — kondisi default) |
| `php bin/v2_phase4_verify.php` | **LULUS** (skema, invarian notifikasi, perlindungan token, keutuhan Fase 1–3) |

## 4. Pengujian yang diwajibkan PRD Fase 4

| # | Pengujian wajib | Bukti | Hasil |
| --- | --- | --- | --- |
| 1 | Setiap peristiwa menghasilkan tepat satu notifikasi in-app untuk penerima berhak | integrasi KN-1a…KN-1r | **LULUS** |
| 2 | Pengguna tidak dapat membaca notifikasi pengguna lain lewat manipulasi ID | integrasi KN-2a…KN-2e; kontrak KA-2a…KA-2e; web WN-5a…WN-5d | **LULUS** |
| 3 | Retry event yang sama tidak membuat notifikasi/pesan ganda | integrasi KN-3a…KN-3d; concurrency KC-1c/KC-1d | **LULUS** |
| 4 | Menonaktifkan push menghentikan enqueue push baru tanpa mengganggu in-app | integrasi KN-4e…KN-4i | **LULUS** |
| 5 | WhatsApp tidak dapat diaktifkan jika konfigurasi gagal atau hasil Lulus berasal dari penyedia lama | integrasi KN-5a…KN-5e; kontrak KA-7a…KA-7d; web WN-7g/WN-7h | **LULUS** |
| 6 | Saat WhatsApp mati/tidak siap: nol request penyedia, transaksi tetap berhasil | integrasi KN-6a…KN-6j | **LULUS** |
| 7 | Fake adapter memverifikasi enqueue, send, fail, retry, dedup | integrasi KN-7a…KN-7t | **LULUS** |
| 8 | Secret tidak muncul di API, database, audit, log, source, bundle mobile | integrasi KN-8a…KN-8l; kontrak KA-9a…KA-9d; statis §5, §6, §10, §11 | **LULUS** |
| 9 | Admin dapat melihat status aman dan error pengiriman | integrasi KN-9a…KN-9h; kontrak KA-6c…KA-6m; web WN-7b…WN-7c | **LULUS** |
| 10 | Perubahan sakelar tercatat pada audit | integrasi KN-10a…KN-10d; kontrak KA-6m | **LULUS** |
| 11 | Concurrency worker tidak mengirim event yang sama dua kali | concurrency KC-1…KC-3 | **LULUS** |
| 12 | Deep link menolak akses pengguna yang tidak berhak | kontrak KA-5a…KA-5c | **LULUS** |
| 13 | Logout, penghapusan perangkat, token invalid, dan penonaktifan akun mencabut registrasi | integrasi KN-7z, KN-11a…KN-11h; kontrak KA-8a…KA-8e | **LULUS** |
| 14 | Regresi Fase 1–3 dan V1 tetap lulus | 19 berkas uji lama | **LULUS** |
| 15 | `php -l`, tes API, kontrak, integrasi DB, otorisasi, idempotensi, concurrency, `npm run lint`, `npx tsc --noEmit` | §1–§2 | **LULUS** |

## 5. Bukti concurrency (KC-1)

Dua proses PHP nyata memulai putaran worker pada detik yang sama, dengan 12
baris antrean WhatsApp dan adapter uji yang menulis jurnal ber-lock.

| Pemeriksaan | Hasil |
| --- | --- |
| Kedua proses selesai tanpa galat | ya |
| Total terkirim kedua worker | **12 dari 12** |
| Pesan pada jurnal adapter uji | **12** |
| Peristiwa yang terkirim dua kali | **0** |
| Baris berstatus selain `Sent` setelah selesai | 0 |
| Baris dengan `percobaan > 1` | 0 |
| Baris tertinggal terkunci | 0 |
| Nomor tujuan pada jurnal | tersamar (`••••7000001`) |

Lapisan kedua diuji terpisah: dua pemilik klaim berbeda memperoleh himpunan
baris yang **saling lepas**, dan pemilik lain tidak dapat menyelesaikan baris
yang bukan klaimnya (KC-2b, KC-2d).

Heartbeat juga diuji: pemilik dapat memperpanjang sewa proses, sedangkan
worker lain tidak dapat memperpanjang sewa yang bukan miliknya (KC-3c/KC-3d).

## 6. Bukti retry dan backoff (KN-7i…KN-7r)

| Pemeriksaan | Hasil |
| --- | --- |
| Kegagalan sementara → status `Failed`, `percobaan = 1`, `gagal_permanen = 0` | ya |
| `tersedia_pada` terisi (backoff dijadwalkan) | ya |
| Riwayat percobaan tercatat pada `notifikasi_percobaan` | ya |
| Putaran berikutnya tidak mencoba sebelum waktunya | ya |
| Kegagalan permanen → `gagal_permanen = 1`, tidak diambil lagi | ya |
| Percobaan ulang admin memakai baris yang SAMA (jumlah baris outbox tidak berubah) | ya |
| Backoff: `60 s` pada percobaan 1, dibatasi `3600 s` | ya |

## 7. Bukti kebocoran secret (KN-8, KA-9)

| Pemeriksaan | Hasil |
| --- | --- |
| Token perangkat pada database dalam bentuk terbaca | **0 baris** |
| `token_hash` bukan heksadesimal 64 karakter | **0 baris** |
| Token perangkat pada `audit_logs` | **0 baris** |
| Token perangkat pada isi/payload notifikasi | **0 baris** |
| Nomor telepon pada audit kanal | **0 baris** |
| Token pada respons daftar perangkat / status admin / daftar kegagalan | tidak ada |
| Kunci `PUSH_TOKEN_KEY` pada respons admin | tidak ada |
| Token/credential tertanam pada berkas sumber Fase 4 | tidak ada |
| `console.log` pada berkas notifikasi mobile | tidak ada |
| `PUSH_TOKEN_KEY` / `WHATSAPP_API_TOKEN` / `EXPO_ACCESS_TOKEN` pada `app.json` atau `app.config.ts` | tidak ada |
| Sandi token dapat dibuka dengan kunci yang benar, gagal dengan kunci lain | ya |

`SafeError` diuji perilakunya secara langsung: token Expo, bearer token, JWT,
nilai `api_key` pada JSON, credential pada query string, dan nomor telepon
semuanya disamarkan; pesan dipotong ke 255 karakter.

## 8. Bukti manual pasca-sandbox dan kemampuan yang ditangguhkan

| Kriteria | Status | Alasan |
| --- | --- | --- |
| Push benar-benar **tiba** pada perangkat Android nyata | **TERPENUHI BERDASARKAN UJI FISIK** | Xiaomi 2409BRN2CY, akun murobi, development build EAS; push pengajuan `#2` tiba dan isi yang diamati tidak memuat data sensitif |
| Push benar-benar **tiba** pada perangkat iOS nyata | **TERPENUHI BERDASARKAN UJI FISIK** | iPhone 17 Pro, akun orang tua, development build EAS dengan entitlement APNs; push keputusan `#2` tiba setelah worker manual; screenshot layar kunci tidak tersedia di repository |
| Pesan uji WhatsApp **nyata** terkirim | **DITANGGUHKAN/NON-BLOCKING — TIDAK DIUJI DAN TIDAK LULUS** | Keputusan produk 26 Agustus 2026; penyedia resmi dan prasyarat bisnis belum tersedia |
| Satu notifikasi keputusan **nyata** via WhatsApp | **DITANGGUHKAN/NON-BLOCKING — TIDAK DIUJI DAN TIDAK LULUS** | Idem; adapter uji bukan bukti pengiriman nyata |

Catatan uji fisik dan langkah yang masih terbuka ada pada
`mobile-build-and-smoke-test.md`. Checklist WhatsApp tetap dipertahankan untuk
aktivasi masa depan pada `whatsapp-provider-checklist.md`.

Yang **sudah** dibuktikan untuk push tanpa perangkat nyata: baris outbox
diantrekan hanya untuk penerima berperangkat aktif, payload push hanya memuat
penunjuk sumber daya dan tidak memuat alasan izin, `channelId` yang dikirim
server sama dengan kanal Android yang dibuat aplikasi, tiket sukses menandai
baris `Sent`, dan tiket `DeviceNotRegistered` otomatis mencabut token.

### 8.1 Temuan terbuka setelah uji perangkat

1. Cron worker push produksi belum berjalan otomatis. Push iPhone baru tiba
   setelah tombol **Jalankan worker sekali** ditekan; cron cPanel setiap menit
   masih harus dipasang atau diverifikasi.
2. Server baru memeriksa tiket awal Expo dan belum mengambil push receipt akhir
   dari FCM/APNs. Status `Sent` belum boleh dipahami sebagai bukti delivery
   end-to-end final yang sepenuhnya akurat.
3. Deep-link Android sempat gagal ketika `adb reverse` ke Metro terputus.
   Aplikasi kembali normal setelah koneksi dipulihkan, tetapi pengujian fisik
   cold-start dan background lengkap belum tercatat.
4. Commit mobile `da04c3a` yang mengonfigurasi credential native push, Firebase
   Android, EAS projectId, dan entitlement iOS masih lokal dan belum didorong
   ke `origin/prd-v2-fase-4` saat keputusan produk dicatat.

## 9. Uji browser sungguhan (Chromium/Playwright)

Berbeda dari smoke test HTTP yang memeriksa kode status dan potongan HTML,
dua skrip berikut benar-benar MERENDER halaman, MENGKLIK tombol, dan mengambil
tangkapan layar. Keduanya berada di `tests/browser/` dan bersifat opsional —
tidak menjadi bagian rangkaian uji wajib, karena memerlukan Playwright.

Prosedur menjalankan: `testing-sandbox.md` §Uji browser.

### 9.1 Website portal dan panel admin — `tests/browser/uji-website.mjs`

**37 pemeriksaan, 0 gagal** (dijalankan tiga kali berturut-turut dengan hasil
sama; skrip mengembalikan status baca akun `sbx_%` lebih dulu agar dapat
diulang).

| Kelompok | Pemeriksaan | Hasil |
| --- | --- | --- |
| Pusat notifikasi murobi | B-1…B-14: halaman terbuka, lencana jumlah, penanda "Baru", filter, panel detail, tandai dibaca, tandai semua | LULUS |
| Kerahasiaan | B-5, B-9, B-17: alasan izin dan catatan pengurus tidak pernah tampil pada notifikasi | LULUS |
| Isolasi antar akun | B-15…B-18: orang tua hanya melihat miliknya, ditolak pada notifikasi murobi, ditolak 403 pada panel admin | LULUS |
| Panel kanal admin | B-19…B-33: tiga kanal tampil, in-app tidak dapat dimatikan, hanya NAMA environment yang tampil, pemeriksaan konfigurasi, push dapat dinyalakan/dimatikan, WhatsApp DITOLAK menyala, pesan uji, daftar kegagalan, audit | LULUS |
| Regresi | B-34…B-36: daftar perizinan, antrean, dashboard admin V1 | LULUS |
| Galat JavaScript | B-37 | LULUS (0 galat) |

Catatan lingkungan: sandbox memblokir CDN eksternal, sehingga Bootstrap,
FontAwesome, dan chart.js tidak termuat. Tangkapan layar karenanya tampil
tanpa gaya, dan `Chart is not defined` pada dashboard V1 berasal dari CDN yang
diblokir — bukan cacat kode dan bukan bagian Fase 4.

### 9.2 Aplikasi React Native — `tests/browser/uji-aplikasi.mjs`

**25 pemeriksaan, 0 gagal.**

Kode yang dijalankan adalah bundel React Native yang sama dengan build
perangkat, dirender lewat `react-native-web` (`npx expo export -p web`) dan
disajikan satu origin dengan API melalui `tests/phase5_web_router.php`. Ini
BUKAN produk web; ia hanya permukaan pengujian, sama seperti yang dipakai
Fase 3.

| Kelompok | Pemeriksaan | Hasil |
| --- | --- | --- |
| Sesi | A-1: login dan bundel berjalan | LULUS |
| Pusat notifikasi | A-2…A-7: data dimuat dari API, ringkasan jumlah, filter | LULUS |
| Kerahasiaan | A-4, A-9, A-10, A-22: alasan izin tidak pernah tampil; layar menyatakan alasan tidak dikirim lewat notifikasi | LULUS |
| Detail dan deep link | A-8, A-11…A-13: detail terbuka, menandai dibaca, navigasi ke detail izin | LULUS |
| Perangkat & push | A-14…A-18: layar terbuka, menyatakan perlu development build dan perangkat nyata, tidak menampilkan token | LULUS |
| Tandai semua | A-19 | LULUS |
| Isolasi antar akun | A-20…A-22: orang tua ditolak pada notifikasi murobi (403 dari server) | LULUS |
| Regresi | A-23…A-24: layar perizinan Fase 2/3 dan jadwal V1 | LULUS |
| Galat JavaScript | A-25 | LULUS (0 galat) |

**Yang TIDAK diuji di browser:** kedatangan push. `expo-notifications`
mengembalikan `tidak_didukung` pada web sesuai dokumentasi SDK 57, dan layar
memang melaporkannya apa adanya (A-6). Kriteria 3 PRD kemudian dipenuhi melalui
uji perangkat fisik 24 Agustus 2026 sebagaimana §8; hasil browser tidak dipakai
sebagai bukti penggantinya.

### 9.3 Dua perbaikan yang ditemukan uji browser ini

| Temuan | Berkas | Sifat |
| --- | --- | --- |
| `Notifications.useLastNotificationResponse()` melempar pada web (`getLastNotificationResponse` tidak tersedia), menggagalkan render seluruh aplikasi pada permukaan uji web yang dipakai sejak Fase 3 | `src/notifications/notification-context.tsx` | Penjagaan khusus web; perilaku Android/iOS tidak berubah sama sekali |
| Tab "Notifikasi" tidak ada pada `app-tabs.web.tsx` (varian web punya daftar tab tersendiri yang belum diperbarui Fase 4), sehingga pusat notifikasi tidak dapat dicapai pada build web | `src/components/app-tabs.web.tsx` | Penyetaraan permukaan uji web dengan versi native; `app-tabs.tsx` (Android/iOS) sudah benar sejak awal |

Keduanya hanya menyentuh jalur web. Tidak ada perubahan pada logika
notifikasi, registrasi push, maupun kontrak API.
