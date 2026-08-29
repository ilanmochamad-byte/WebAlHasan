# V2 Fase 5 — Status Kriteria Penerimaan

Implementasi Claude: 26 Agustus 2026. Sumber bukti: `test-results.md`,
`bukti-performa.md`, dan `backup-restore-dan-manifest.md`.

> **Status keseluruhan: 29 AGUSTUS 2026 — SELESAI PRODUKSI DENGAN RISIKO
> RESIDUAL DITERIMA PEMILIK PRODUK.** Seluruh gerbang otomatis, migrasi,
> restore, cron, receipt nyata, laporan web/CSV, dan PDF lintas perangkat sudah
> memiliki bukti. Matriks manual lengkap dan beberapa skenario perangkat tidak
> diklaim lulus; pemilik produk menerima keduanya sebagai risiko pascarilis.
> Rincian keputusan: `penutupan-fase5.md`.

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
| 3 | CSV memuat seluruh hasil filter sampai batas produk 20.000 baris, header terdokumentasi, formula injection dinetralkan; hasil di atas batas ditolak tanpa berkas parsial | **TERPENUHI** | Keputusan produk 29 Agustus 2026 menetapkan batas 20.000. Audit 20.004 hasil menghasilkan `422 EXPORT_TOO_LARGE`; dua CSV produksi berisi 4 dan 1 hasil sesuai filter, 30 kolom, BOM UTF-8, dan nol sel formula berbahaya. |
| 4 | Halaman cetak/PDF memuat identitas pesantren, filter, pembuat, waktu, keputusan, dan nomor halaman | **TERPENUHI** | PDF nyata diperiksa pada Safari macOS, Android, dan iOS: identitas/filter/pembuat/waktu/keputusan/footer tampil; A4 lanskap perangkat memiliki margin sekitar 1 cm dan nomor halaman benar. Safari potret tetap terbaca, dengan lanskap direkomendasikan untuk nama panjang. |
| 5 | Halaman pertama laporan selesai maksimal 2 detik pada fixture minimal 1.000 pengajuan | **TERPENUHI** | `bukti-performa.md`; 1.028 pengajuan, terburuk **24,0 ms** dari ambang 2.000 ms; 10 skenario lintas peran |
| 6 | Jumlah dan ID perizinan lama sama sebelum/sesudah migrasi | **TERPENUHI PADA PRODUKSI** | Preflight produksi mencatat 0 baris warisan dengan sidik jari yang sama; verifikasi pascamigrasi 009 lulus 22/22 tanpa perubahan jumlah/ID. |
| 7 | Backup dipulihkan pada database `_test` dan seluruh jumlah baris inti cocok dengan manifest | **TERPENUHI PADA CPANEL** | Backup 47 tabel dipulihkan ke database `_test`, migrasi 009 diterapkan, dan verifikasi lulus 22/22 terhadap manifest produksi. |
| 8 | Semua tes statis, integrasi, concurrency, lint PHP/TypeScript, dan regresi V1 lulus | **TERPENUHI** | **29 berkas, 2.337 pemeriksaan, 0 gagal**; 6 uji pembatalan cetak iOS; `npx tsc --noEmit` dan `npx expo lint` bersih |
| 9 | Uji manual web serta Android/iOS untuk pengurus, murobi, admin, dan orang tua lulus | **DITUTUP DENGAN PENERIMAAN RISIKO** | Web empat cakupan, PDF Safari/Android/iOS, CSV, push, dan receipt nyata lulus. Matriks empat peran × dua OS, deep-link lengkap, Dynamic Type, offline, serta uji fisik pembatalan cetak tidak dinyatakan lulus; pemilik produk menerimanya sebagai risiko residual. |
| 10 | WhatsApp off tidak menghasilkan request provider; WhatsApp on hanya dirilis setelah pemeriksaan konfigurasi dan uji admin lulus | **TERPENUHI untuk bagian "off"; bagian "on" DITANGGUHKAN** | KL-10a…KL-10e (penyedia tiruan mencatat **0** panggilan saat kanal mati). Pengaktifan WhatsApp **tidak diuji dan tidak dinyatakan lulus** (§3). |

**Sembilan kriteria ditutup berdasarkan bukti otomatis, produksi, audit, dan
penerimaan risiko eksplisit untuk kriteria 9.** Kriteria 10 terpenuhi untuk
keadaan WhatsApp-off; WhatsApp-on tetap ditangguhkan, tidak diuji, tidak
dinyatakan lulus, dan non-blocking sesuai keputusan produk.

## 2. Persyaratan implementasi PRD Fase 5 (§6 poin 1–11)

| # | Persyaratan | Status |
| --- | --- | --- |
| 1 | Filter admin: tanggal, status, santri, pengurus, murobi, kamar/kelas, tahun ajaran, durasi keputusan, kanal notifikasi | **SELESAI** — seluruh 9 dimensi + basis tanggal, sumber data, dan pencarian teks |
| 2 | Pengurus/murobi melihat sesuai cakupan; orang tua melihat riwayat santri terhubung | **SELESAI** — ditegakkan di SQL (`IzinReportRepository::scopeConditions()`) |
| 3 | Ringkasan jumlah per status dan median durasi keputusan | **SELESAI** — 5 status + total + data warisan + median/min/maks/rata-rata |
| 4 | Detail riwayat, halaman HTML ramah cetak, PDF/bagikan dari aplikasi, ekspor CSV sampai batas produk 20.000 baris | **SELESAI** — web, REST API, dan aplikasi; hasil di atas batas ditolak eksplisit tanpa CSV parsial |
| 5 | Ringkasan, detail, cetak, dan CSV memakai filter/repository yang konsisten | **SELESAI** — satu `IzinReportFilter` + satu `conditions()`; dibuktikan sidik jari kriteria yang identik |
| 6 | Ukur query dengan fixture minimal 1.000 pengajuan; indeks hanya setelah `EXPLAIN` | **SELESAI** — diukur pada 1.028 dan 20.004 pengajuan; **tidak ada indeks laporan ditambahkan** karena pengukuran tidak mendukungnya (lihat `bukti-performa.md`) |
| 7 | Preflight, backup, migrasi, verifikasi data lama, smoke test, backup/restore pada salinan MySQL | **SELESAI** — preflight, backup 47 tabel, restore `_test`, migrasi 009, verifikasi 22/22, serta checklist produksi berbasis risiko selesai |
| 8 | Regresi seluruh alur V1: login, master data, jadwal, absensi, laporan, cetak, API, aplikasi guru | **SELESAI** — lihat `hasil-regresi.md` |
| 9 | Uji alur V2 pada web dan perangkat nyata untuk seluruh peran | **DITUTUP DENGAN RISIKO RESIDUAL DITERIMA** — bukti lintas kanal tersedia; matriks lengkap tetap menjadi regresi pascarilis |
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
| 1 | Cron worker push produksi belum berjalan otomatis | **SELESAI.** Cron push per menit dan receipt per 15 menit berjalan dengan PHP `/opt/alt/php83/usr/bin/php`; kesehatan 6/6, perangkat aktif 1, antrean push 0. |
| 2 | Server baru memeriksa tiket awal Expo | **SELESAI.** Pada 22:15 WIB tiga receipt nyata diperiksa dan ketiganya menjadi `Terkirim`; pada 22:23 WIB `Menunggu: 0`, `Terkirim: 3`, `Gagal: 0`. |
| 3 | Deep-link push Android/iOS foreground/background/cold-start belum lengkap | **RISIKO RESIDUAL DITERIMA.** Matriks lengkap tidak dinyatakan lulus dan dipindahkan ke regresi pascarilis; otorisasi server lintas cakupan tetap lulus otomatis. |
| 4 | Commit mobile masih lokal | **SELESAI UNTUK BASELINE PRODUKSI.** Koreksi entitlement iOS `2016bf8` sudah masuk `main`; koreksi pembatalan cetak disiapkan pada branch penutupan terpisah. |

## 5. Yang TIDAK dikerjakan dan TIDAK diklaim

Daftar ini sengaja eksplisit agar auditor tidak perlu menebak.

| Tidak dikerjakan | Alasan |
| --- | --- |
| Pengaktifan WhatsApp | DITANGGUHKAN oleh keputusan produk |
| Uji deep-link lengkap pada perangkat Android/iOS fisik | Receipt dan push nyata lulus; seluruh keadaan deep-link diterima sebagai risiko residual pascarilis |
| Merge ke `main`, push ke origin, deploy | Dilarang tanpa instruksi terpisah pengguna |
| Fase berikutnya | Di luar ruang lingkup |

## 6. Risiko yang tercatat

| Risiko | Dampak | Mitigasi |
| --- | --- | --- |
| Deep-link dan cakupan lintas akun pada perangkat belum diuji lengkap | Ketukan notifikasi dapat gagal pada keadaan tertentu | Risiko diterima; jalankan checklist `uji-manual-tertunda.md` §2 sebagai regresi pascarilis |
| Pembatalan cetak iOS belum diuji ulang pada build fisik | Koreksi otomatis lulus tetapi binary baru belum diuji manusia | Ulangi tutup dialog pada build berikutnya; classifier sempit dan galat printer nyata tetap diteruskan |
| MySQL 5.7 cPanel vs MariaDB 10.11 sandbox | `CHECK` diabaikan MySQL 5.7 | Aturan yang sama ditegakkan lapisan aplikasi; median laporan sengaja memakai `LIMIT/OFFSET`, bukan window function, agar berjalan pada MySQL 5.7 |
| Ekspor sangat besar | Memori server | Batas produk `MAX_EXPORT_ROWS` 20.000; hasil lebih besar ditolak `422 EXPORT_TOO_LARGE` dan pengguna diminta mempersempit filter — tidak pernah memotong diam-diam |
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

Hasil audit penutupan: **2.337 pemeriksaan lulus, 0 gagal**.
