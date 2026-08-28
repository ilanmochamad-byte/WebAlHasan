# V2 Fase 5 — Status Kriteria Penerimaan

Implementasi Claude: 26 Agustus 2026. Sumber bukti: `test-results.md`,
`bukti-performa.md`, dan `backup-restore-dan-manifest.md`.

> **Status keseluruhan: AUDIT CODEX SELESAI 28 AGUSTUS 2026 — BELUM LOLOS RILIS.**
> Audit menemukan ekspor 20.004 hasil terpotong menjadi 20.000 baris. Ekspor
> parsial kini ditolak eksplisit, tetapi ekspor penuh di atas batas masih belum
> tersedia. Verifikasi visual PDF, migrasi cPanel produksi, uji perangkat fisik,
> cron, dan smoke test produksi juga masih menunggu. Rincian:
> `audit-codex.md`.

## 0. Baseline implementasi yang dinilai

| Repository | Branch | Baseline | Worktree |
| --- | --- | --- | --- |
| WebAlHasan | `prd-v2-fase-5` dari HEAD lokal `cf2de140e4135758e4994dfce0fc0450248ad6e8` | Fase 4 diterima (`279f9ac` + koreksi auditor) | bersih saat branch dibuat |
| alhasanApps | `prd-v2-fase-5` dari HEAD lokal `da04c3a227372b722498aaf11f40e44464e6f9c0` | Fase 4 (`876ec50`) + credential native push `da04c3a` | bersih saat branch dibuat |

Commit mobile `da04c3a` **tetap menjadi bagian baseline Fase 5** dan tidak
hilang: branch Fase 5 dibuat langsung dari commit lokal tersebut, bukan dari
`origin` yang saat implementasi masih tertinggal. Audit 28 Agustus 2026
membuktikan `origin/prd-v2-fase-4` kini sudah menunjuk ke commit tersebut.

Lingkungan sandbox yang dipakai: PHP 8.4.21 (CLI, NTS), MariaDB 10.11.14,
Node.js 22.23.2, database `webalhasan_test` dengan migrasi 001–009.

## 1. Kriteria penerimaan PRD Fase 5

| # | Kriteria | Status | Bukti |
| --- | --- | --- | --- |
| 1 | Admin dapat menghasilkan laporan seluruh filter dan total ringkasan sama dengan detail | **TERPENUHI** | KL-1 (6 kombinasi filter × 6 pemeriksaan), KL-4a…KL-4u, KR-4a…KR-4d |
| 2 | Pengurus, murobi, dan orang tua tidak dapat melihat laporan di luar cakupannya | **TERPENUHI** | KL-2a…KL-2l, KL-3a…KL-3g, KR-2a…KR-2i, KR-3a…KR-3e, WL-3a…WL-3e, WL-6a…WL-6f |
| 3 | CSV memuat seluruh hasil filter, header terdokumentasi, formula injection dinetralkan | **BELUM TERPENUHI** | Formula injection dan header lulus, tetapi audit pada 20.004 hasil membuktikan hanya 20.000 baris dihasilkan. Koreksi auditor menolak CSV parsial dengan `422`; streaming/chunking masih diperlukan. |
| 4 | Halaman cetak/PDF memuat identitas pesantren, filter, pembuat, waktu, keputusan, dan nomor halaman | **MENUNGGU VERIFIKASI VISUAL PDF** | HTML memuat seluruh penanda, tetapi tes otomatis hanya mencari string CSS; hasil PDF nyata dan nomor halaman belum dirender serta diperiksa visual. |
| 5 | Halaman pertama laporan selesai maksimal 2 detik pada fixture minimal 1.000 pengajuan | **TERPENUHI** | `bukti-performa.md`; 1.028 pengajuan, terburuk **24,0 ms** dari ambang 2.000 ms; 10 skenario lintas peran |
| 6 | Jumlah dan ID perizinan lama sama sebelum/sesudah migrasi | **TERPENUHI PADA SALINAN UJI** | latihan backup/restore: 30 baris warisan sintetis, ID dan sidik jari SHA-256 identik sebelum/sesudah migrasi 009 **dan** sesudah rollback. Migrasi produksi **belum** dijalankan (§4). |
| 7 | Backup dipulihkan pada database `_test` dan seluruh jumlah baris inti cocok dengan manifest | **TERPENUHI** | `bin/v2_phase5_backup_restore_drill.php`: 47 tabel cocok, 17/17 pemeriksaan lulus |
| 8 | Semua tes statis, integrasi, concurrency, lint PHP/TypeScript, dan regresi V1 lulus | **TERPENUHI** | 28 berkas, **2.230 pemeriksaan, 0 gagal** (dua putaran berturut-turut); `npx tsc --noEmit` dan `npx expo lint` bersih |
| 9 | Uji manual web serta Android/iOS untuk pengurus, murobi, admin, dan orang tua lulus | **MENUNGGU VERIFIKASI — TIDAK DINYATAKAN LULUS** | web tercakup smoke test HTTP bersesi (WL-1…WL-8); perangkat fisik belum diuji pada Fase 5. Lihat `uji-manual-tertunda.md`. |
| 10 | WhatsApp off tidak menghasilkan request provider; WhatsApp on hanya dirilis setelah pemeriksaan konfigurasi dan uji admin lulus | **TERPENUHI untuk bagian "off"; bagian "on" DITANGGUHKAN** | KL-10a…KL-10e (penyedia tiruan mencatat **0** panggilan saat kanal mati). Pengaktifan WhatsApp **tidak diuji dan tidak dinyatakan lulus** (§3). |

**Enam kriteria terpenuhi berdasarkan bukti otomatis/audit.** Kriteria 3 belum
terpenuhi; kriteria 4 dan 9 menunggu verifikasi manusia. Kriteria 10 hanya
terpenuhi untuk keadaan WhatsApp-off, sedangkan WhatsApp-on ditangguhkan.
Kriteria 6 terpenuhi pada salinan uji; klaim produksi baru sah setelah migrasi
produksi benar-benar dijalankan dan diverifikasi.

## 2. Persyaratan implementasi PRD Fase 5 (§6 poin 1–11)

| # | Persyaratan | Status |
| --- | --- | --- |
| 1 | Filter admin: tanggal, status, santri, pengurus, murobi, kamar/kelas, tahun ajaran, durasi keputusan, kanal notifikasi | **SELESAI** — seluruh 9 dimensi + basis tanggal, sumber data, dan pencarian teks |
| 2 | Pengurus/murobi melihat sesuai cakupan; orang tua melihat riwayat santri terhubung | **SELESAI** — ditegakkan di SQL (`IzinReportRepository::scopeConditions()`) |
| 3 | Ringkasan jumlah per status dan median durasi keputusan | **SELESAI** — 5 status + total + data warisan + median/min/maks/rata-rata |
| 4 | Detail riwayat, halaman HTML ramah cetak, PDF/bagikan dari aplikasi, ekspor CSV seluruh hasil filter | **SELESAI** — web, REST API, dan aplikasi (expo-print/expo-sharing/expo-file-system SDK 57) |
| 5 | Ringkasan, detail, cetak, dan CSV memakai filter/repository yang konsisten | **SELESAI** — satu `IzinReportFilter` + satu `conditions()`; dibuktikan sidik jari kriteria yang identik |
| 6 | Ukur query dengan fixture minimal 1.000 pengajuan; indeks hanya setelah `EXPLAIN` | **SELESAI** — diukur pada 1.028 dan 20.004 pengajuan; **tidak ada indeks laporan ditambahkan** karena pengukuran tidak mendukungnya (lihat `bukti-performa.md`) |
| 7 | Preflight, backup, migrasi, verifikasi data lama, smoke test, backup/restore pada salinan MySQL | **SELESAI sebagai perkakas dan latihan**; eksekusi pada produksi **belum** dan memerlukan izin pengguna |
| 8 | Regresi seluruh alur V1: login, master data, jadwal, absensi, laporan, cetak, API, aplikasi guru | **SELESAI** — lihat `hasil-regresi.md` |
| 9 | Uji alur V2 pada web dan perangkat nyata untuk seluruh peran | **SEBAGIAN** — web selesai; perangkat nyata MENUNGGU VERIFIKASI |
| 10 | Dokumentasikan deployment cPanel, environment, cron, feature flag WhatsApp, rollback, respons insiden | **SELESAI** — `cpanel-deployment.md`, `migration-and-rollback.md`, `incident-runbook.md` |
| 11 | Jangan mengaktifkan WhatsApp produksi sebelum admin menyetujui provider, template, credential, dan hasil uji | **DIPATUHI** — WhatsApp tetap OFF; preflight dan verifikasi MEMBLOKIR rilis bila menyala |

## 3. WhatsApp — tetap DITANGGUHKAN

**Status: DITANGGUHKAN/NON-BLOCKING — TIDAK DIUJI DAN TIDAK DINYATAKAN LULUS.**

Keputusan produk 26 Agustus 2026 tidak berubah pada Fase 5. Yang dilakukan
Fase 5 hanyalah **memperkuat pengamannya**, bukan mengaktifkannya:

- kalimat prinsip PRD dipertahankan persis: *"Notifikasi: in-app dan push
  didukung; WhatsApp opsional serta dikendalikan admin. **[JANGAN DIUBAH]**"*;
- WhatsApp tetap default `OFF`;
- `bin/v2_phase5_preflight.php` **memblokir** rilis bila `whatsapp_enabled = 1`;
- `bin/v2_phase5_verify.php` memeriksa ulang hal yang sama setelah migrasi;
- `bin/v2_phase5_cron_check.php` melaporkan WhatsApp yang menyala sebagai masalah;
- pengujian membuktikan **0 panggilan** ke penyedia saat kanal mati (KL-10c);
- tidak ada provider dipilih, tidak ada credential dimasukkan, tidak ada
  request nyata dikirim, dan tidak ada pengaktifan pada produksi;
- seluruh checklist aktivasi masa depan pada
  `../phase-v2-4/whatsapp-provider-checklist.md` tetap berlaku dan tetap wajib.

## 4. Temuan terbuka Fase 4 — status pada Fase 5

| # | Temuan Fase 4 | Status Fase 5 |
| --- | --- | --- |
| 1 | Cron worker push produksi belum berjalan otomatis | **DITANGANI SEBAGIAN.** Ditambahkan `bin/v2_phase5_cron_check.php` yang MEMBUKTIKAN dari data apakah cron berjalan (antrean tertahan, jejak sewa worker, receipt tertahan), plus baris cron cPanel siap salin pada `cpanel-deployment.md`. **Cron TIDAK dipasang pada produksi** — memerlukan izin pengguna. Tetap MENUNGGU VERIFIKASI. |
| 2 | Server baru memeriksa tiket awal Expo | **DITANGANI.** Migrasi 009 menambah `tiket_id` dan kolom receipt; `PushReceiptClient` + `ExpoPushClient::getReceipts()` memanggil endpoint resmi `getReceipts`; `NotificationDispatcher::reconcileReceipts()` merekonsiliasi status. Dibuktikan KL-9a…KL-9p (sukses, gagal, belum tersedia, batas percobaan, idempotensi, tanpa kirim ulang). Verifikasi terhadap Expo NYATA tetap menunggu. |
| 3 | Deep-link push Android/iOS foreground/background/cold-start belum lengkap | **BELUM DITANGANI — MENUNGGU PENGUJIAN MANUSIA.** Tidak ada perangkat fisik pada lingkungan kerja ini. Checklist langkah demi langkah disediakan pada `uji-manual-tertunda.md`. **Tidak dinyatakan lulus.** |
| 4 | Commit mobile `da04c3a` masih lokal | **SELESAI.** Audit `git ls-remote` 28 Agustus 2026 membuktikan `origin/prd-v2-fase-4` sudah menunjuk tepat ke `da04c3a`. Commit Fase 5 mobile `0b7e730` masih lokal dan akan didorong sebagai branch baru `prd-v2-fase-5`. |

## 5. Yang TIDAK dikerjakan dan TIDAK diklaim

Daftar ini sengaja eksplisit agar auditor tidak perlu menebak.

| Tidak dikerjakan | Alasan |
| --- | --- |
| Migrasi 009 pada database PRODUKSI | PRD Fase 5 melarangnya dalam pekerjaan ini; hanya dijalankan pada `webalhasan_test` dan salinan restore |
| Restore pada database produksi | Sama seperti di atas |
| Pemasangan cron pada cPanel produksi | Memerlukan izin pengguna |
| Pengaktifan WhatsApp | DITANGGUHKAN oleh keputusan produk |
| Uji push/deep-link pada perangkat Android/iOS fisik | Perangkat tidak tersedia pada lingkungan ini |
| Merge ke `main`, push ke origin, deploy | Dilarang tanpa instruksi terpisah pengguna |
| Fase berikutnya | Di luar ruang lingkup |

## 6. Risiko yang tercatat

| Risiko | Dampak | Mitigasi |
| --- | --- | --- |
| Migrasi produksi belum dijalankan | Klaim "ID perizinan lama tidak berubah" baru terbukti pada salinan uji | Jalankan `bin/v2_phase5_preflight.php` → migrasi → `bin/v2_phase5_verify.php` pada produksi dan simpan keluarannya |
| Cron produksi belum dipasang | Push menunggu sampai worker dijalankan manual | Pasang dua baris cron pada `cpanel-deployment.md`, lalu jalankan `bin/v2_phase5_cron_check.php` setiap jam |
| Receipt akhir belum diverifikasi terhadap Expo nyata | Rekonsiliasi terbukti benar terhadap klien tiruan, belum terhadap penyedia sungguhan | Setelah cron aktif, bandingkan sebaran `receipt_status` dengan pengamatan perangkat |
| Deep-link cold-start Android belum diuji | Ketukan notifikasi dapat gagal pada kondisi tertentu | Checklist `uji-manual-tertunda.md` §2 |
| MySQL 5.7 cPanel vs MariaDB 10.11 sandbox | `CHECK` diabaikan MySQL 5.7 | Aturan yang sama ditegakkan lapisan aplikasi; median laporan sengaja memakai `LIMIT/OFFSET`, bukan window function, agar berjalan pada MySQL 5.7 |
| Ekspor sangat besar | Memori server | `MAX_EXPORT_ROWS` 20.000 dengan penanda `terpotong` yang ditampilkan ke pengguna — tidak pernah memotong diam-diam |
| Satu baris outbox dapat menyebar ke beberapa perangkat | Receipt yang disimpan mewakili perangkat pertama | Dicatat pada kode dan dokumen; in-app tetap sumber status utama; pencabutan token dari receipt hanya dilakukan bila pemetaannya tidak ambigu |
| Laporan memuat data pribadi santri | Kebocoran lewat cache/berkas | `Cache-Control: private, no-store` pada cetak dan CSV; `nosniff`; CSV mobile ditulis ke cache aplikasi, bukan penyimpanan bersama |

## 7. Kesiapan audit

Yang dapat diulang auditor persis seperti yang dilaporkan:

```bash
# 1. Sandbox
#    (prosedur lengkap: testing-sandbox.md)
php bin/migrate.php up
V2_PHASE3_SEED=1     php bin/v2_phase3_sandbox_seed.php
V2_PHASE5_FIXTURE=1  php bin/v2_phase5_fixture.php --jumlah=1000

# 2. Seluruh pengujian otomatis Fase 1–5
MOBILE_APP_ROOT=/path/ke/alhasanApps bash bin/v2_phase5_run_all_tests.sh

# 3. Performa dan EXPLAIN
V2_PHASE5_UKUR=1 php bin/v2_phase5_ukur_laporan.php --ulang=9 --explain

# 4. Latihan backup → restore → migrasi → rollback
V2_PHASE5_DRILL=1 php bin/v2_phase5_backup_restore_drill.php

# 5. Kesiapan cron
php bin/v2_phase5_cron_check.php
```

Hasil yang diharapkan: **2.230 pemeriksaan lulus, 0 gagal**.
