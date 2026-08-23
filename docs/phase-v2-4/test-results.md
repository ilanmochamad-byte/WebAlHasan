# V2 Fase 4 — Hasil Pengujian

Dijalankan 23 Agustus 2026 pada sandbox terisolasi (PHP 8.4.21, MariaDB
10.11.14, Node 22.22.2). Prosedur lengkap: `testing-sandbox.md`.

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
tests/v2_phase4_static.php                     LULUS   (275 pemeriksaan)
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
tests/v2_phase4_integration.php                LULUS   (118 pemeriksaan)
tests/v2_phase4_api_contract.php               LULUS   (92 pemeriksaan)
tests/v2_phase4_concurrency.php                LULUS   (18 pemeriksaan)
tests/v2_phase4_web_smoke.php                  LULUS   (46 pemeriksaan)

SELURUH PENGUJIAN OTOMATIS LULUS.
```

**Total: 23 berkas, 1.580 pemeriksaan, 0 gagal.**

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
| `php bin/v2_phase4_preflight.php` | exit 0, 0 blokir, 2 peringatan (PUSH_TOKEN_KEY & WHATSAPP_PROVIDER kosong — kondisi default) |
| `php bin/v2_phase4_verify.php` | **LULUS** (skema, invarian notifikasi, perlindungan token, keutuhan Fase 1–3) |

## 4. Pengujian yang diwajibkan PRD Fase 4

| # | Pengujian wajib | Bukti | Hasil |
| --- | --- | --- | --- |
| 1 | Setiap peristiwa menghasilkan tepat satu notifikasi in-app untuk penerima berhak | integrasi KN-1a…KN-1r | **LULUS** |
| 2 | Pengguna tidak dapat membaca notifikasi pengguna lain lewat manipulasi ID | integrasi KN-2a…KN-2e; kontrak KA-2a…KA-2e; web WN-5a…WN-5d | **LULUS** |
| 3 | Retry event yang sama tidak membuat notifikasi/pesan ganda | integrasi KN-3a…KN-3d; concurrency KC-1c/KC-1d | **LULUS** |
| 4 | Menonaktifkan push menghentikan enqueue push baru tanpa mengganggu in-app | integrasi KN-4e…KN-4i | **LULUS** |
| 5 | WhatsApp tidak dapat diaktifkan jika konfigurasi gagal | integrasi KN-5a…KN-5c; kontrak KA-7a…KA-7d; web WN-7g/WN-7h | **LULUS** |
| 6 | Saat WhatsApp mati/tidak siap: nol request penyedia, transaksi tetap berhasil | integrasi KN-6a…KN-6j | **LULUS** |
| 7 | Fake adapter memverifikasi enqueue, send, fail, retry, dedup | integrasi KN-7a…KN-7t | **LULUS** |
| 8 | Secret tidak muncul di API, database, audit, log, source, bundle mobile | integrasi KN-8a…KN-8l; kontrak KA-9a…KA-9d; statis §5, §6, §10, §11 | **LULUS** |
| 9 | Admin dapat melihat status aman dan error pengiriman | integrasi KN-9a…KN-9h; kontrak KA-6c…KA-6m; web WN-7b…WN-7c | **LULUS** |
| 10 | Perubahan sakelar tercatat pada audit | integrasi KN-10a…KN-10d; kontrak KA-6m | **LULUS** |
| 11 | Concurrency worker tidak mengirim event yang sama dua kali | concurrency KC-1…KC-3 | **LULUS** |
| 12 | Deep link menolak akses pengguna yang tidak berhak | kontrak KA-5a…KA-5c | **LULUS** |
| 13 | Logout mencabut/menonaktifkan registrasi perangkat | integrasi KN-11a…KN-11f; kontrak KA-8a…KA-8e | **LULUS** |
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

## 8. Yang BELUM diuji dan tidak diklaim lulus

| Kriteria | Status | Alasan |
| --- | --- | --- |
| Push benar-benar **tiba** pada perangkat Android nyata | **MENUNGGU SMOKE TEST MANUSIA** | Sandbox cloud tanpa perangkat, tanpa credential FCM, tanpa development build |
| Push benar-benar **tiba** pada perangkat iOS nyata | **MENUNGGU SMOKE TEST MANUSIA** | Idem; iOS memerlukan macOS + Xcode + APNs |
| Pesan uji WhatsApp **nyata** terkirim | **KONDISIONAL — BELUM DIUJI** | Belum ada penyedia yang disetujui pemilik produk |
| Satu notifikasi keputusan **nyata** via WhatsApp | **KONDISIONAL — BELUM DIUJI** | Idem |

Checklist untuk keempatnya: `mobile-build-and-smoke-test.md` dan
`whatsapp-provider-checklist.md`.

Yang **sudah** dibuktikan untuk push tanpa perangkat nyata: baris outbox
diantrekan hanya untuk penerima berperangkat aktif, payload push hanya memuat
penunjuk sumber daya dan tidak memuat alasan izin, `channelId` yang dikirim
server sama dengan kanal Android yang dibuat aplikasi, tiket sukses menandai
baris `Sent`, dan tiket `DeviceNotRegistered` otomatis mencabut token.
