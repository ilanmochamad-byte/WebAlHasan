# Hasil pengujian

Paket "Koreksi dan Modernisasi UI/UX V1–V2" — keputusan pengguna 30 Agustus 2026.
Dijalankan pada branch `codex/perapihan-v1-v2-ui`.

---

## 1. Lingkungan

| Komponen | Versi | Catatan |
| --- | --- | --- |
| PHP | 8.4.21 (CLI, NTS) | sama dengan lingkungan sandbox Fase 5 |
| MariaDB | 10.11.14 | sama dengan lingkungan sandbox Fase 5 |
| Node.js | 22.22.2 | untuk uji browser dan verifikasi PDF |
| Chromium | revisi 1194 (Playwright) | dipakai lewat `CHROMIUM_PATH` |
| poppler-utils | tersedia (`pdftotext`, `pdfinfo`) | verifikasi PDF sungguhan |
| Basis data | `webalhasan_test` | **bukan produksi**; migrasi 001–010 diterapkan |

Data uji seluruhnya sintetis (`SBX`, `F5`, `P5`, dan akhiran acak per berkas uji).
Tidak ada credential, dump, atau data pribadi produksi yang dipakai.
Tidak ada permintaan jaringan keluar dari pengujian: WhatsApp tetap `OFF` dan
push memakai klien tiruan.

---

## 2. Ringkasan angka

| Rangkaian | Berkas | Pemeriksaan lulus | Gagal |
| --- | --- | --- | --- |
| Regresi V1 dan V2 (Fase 1–5) | 29 | **2.174** | 1 berkas (pra-ada, lihat §5) |
| Paket perapihan V1–V2 | 4 | **248** | 0 |
| Uji browser 1440/768/390 px | 1 | **56** | 0 |
| **Total** | **34** | **2.478** | — |

Perintah:

```bash
MOBILE_APP_ROOT=/path/ke/alhasanApps \
CHROMIUM_PATH=/path/ke/chromium \
bash bin/perapihan_run_all_tests.sh

php -S 127.0.0.1:8940 -t . &
BASE_URL=http://127.0.0.1:8940 CHROMIUM_PATH=/path/ke/chromium \
  node tests/browser/uji-perapihan.mjs
```

---

## 3. Rincian rangkaian regresi (bagian A)

```
tests/phase1_static.php                        LULUS   (71)
tests/phase2_static.php                        LULUS   (46)
tests/phase3_static.php                        LULUS   (35)
tests/phase4_static.php                        LULUS   (38)
tests/phase5_static.php                        LULUS   (44)
tests/v2_phase1_static.php                     LULUS   (127)
tests/v2_phase2_static.php                     LULUS   (174)
tests/v2_phase3_static.php                     LULUS   (147)
tests/v2_phase4_static.php                     GAGAL   (temuan pra-ada, §5)
tests/v2_phase5_static.php                     LULUS   (272)
tests/v2_phase5_cetak_pdf.php                  LULUS   (175)   ← PDF sungguhan
tests/phase2_integration.php                   LULUS   (12)
tests/phase3_integration.php                   LULUS   (10)
tests/phase4_integration.php                   LULUS   (14)
tests/phase5_integration.php                   LULUS   (20)
tests/v2_phase1_integration.php                LULUS   (39)
tests/v2_phase2_integration.php                LULUS   (94)
tests/v2_phase2_navigasi_murobi.php            LULUS   (40)
tests/v2_phase2_web_smoke.php                  LULUS   (36)
tests/v2_phase3_api_contract.php               LULUS   (116)
tests/v2_phase4_integration.php                LULUS   (122)
tests/v2_phase4_api_contract.php               LULUS   (92)
tests/v2_phase4_concurrency.php                LULUS   (20)
tests/v2_phase4_web_smoke.php                  LULUS   (46)
tests/v2_phase5_integration.php                LULUS   (143)
tests/v2_phase5_api_contract.php               LULUS   (150)
tests/v2_phase5_web_smoke.php                  LULUS   (79)
tests/v2_phase5_performance.php                LULUS   (12)
```

Catatan: `v2_phase5_cetak_pdf.php` naik dari 76 menjadi **175** pemeriksaan
karena bagian render PDF sungguhan kini benar-benar berjalan (sebelumnya menandai
diri "MENUNGGU VERIFIKASI" karena Chromium tidak dapat diluncurkan). Isi
pemeriksaannya tidak diubah — lihat `perubahan-pengujian.md` §5.

---

## 4. Rincian rangkaian paket perapihan (bagian B)

| Berkas | Lulus | Cakupan |
| --- | --- | --- |
| `tests/perapihan_static.php` | 132 | janji struktural ketujuh koreksi, sifat aditif migrasi 010, lint 43 berkas |
| `tests/perapihan_integration.php` | 53 | KA (akun), KW (wali), KG (guru), KP (pengajian), KL (laporan 30/1/31) |
| `tests/perapihan_akun_concurrency.php` | 7 | admin terakhir pada **tiga proses PHP nyata** |
| `tests/perapihan_web_smoke.php` | 56 | seluruh kriteria penerimaan koreksi ke-7 lewat HTTP |

### Bukti kunci

**Laporan kehadiran (fixture 1 guru + 30 santri):**

| Mode | Ringkasan | Detail | Ekspor CSV |
| --- | --- | --- | --- |
| Santri | 30 | 30 | 30 |
| Guru | 1 | 1 | 1 |
| Gabungan | 31 | 31 | 31 |

Default REST API tanpa `subject_scope` tetap **gabungan (31)**; halaman web
memakai **santri (30)** sebagai tampilan awal. Halaman cetak untuk ketiga mode
menghasilkan 33 / 2 / 34 baris tabel (termasuk baris kepala), selisihnya konsisten.

**Admin terakhir pada permintaan bersamaan:**

```
KC-0  Kondisi awal: tepat 3 admin aktif
KC-1a Setelah 3 pencabutan bersamaan, sistem TETAP memiliki admin aktif [1]
KC-1b Tepat 2 pencabutan berhasil, sisanya ditolak [2]
KC-1c Permintaan yang ditolak menjelaskan sebabnya kepada pengguna
KC-2b Setelah penonaktifan bersamaan, sistem TETAP memiliki admin aktif [1]
KC-2c Tepat 2 penonaktifan berhasil, sisanya ditolak [2]
```

**Pintu masuk (lima peran, HTTP sungguhan):** admin, murobi, guru non-murobi,
pengurus, dan orang tua seluruhnya mendarat pada `/portal/index.php`; guru
non-murobi mendapat 200 di beranda dan **403 pada keenam halaman perizinan**.

---

## 5. Temuan yang SUDAH ADA sebelum paket ini

`tests/v2_phase4_static.php` gagal dengan 7 pemeriksaan, **juga pada baseline
`c65390d` sebelum satu baris pun diubah**:

```
[gagal] Berkas mobile Fase 4 tersedia: src/app/(app)/(notifikasi)/notifikasi.tsx
[gagal] Layar notifikasi menyediakan LoadingState / EmptyState / ErrorState
[gagal] Layar notifikasi menyediakan Tandai semua dibaca / Halaman
[gagal] Tab notifikasi menampilkan lencana jumlah belum dibaca
```

Sebab: redesign UI aplikasi mobile (`alhasanApps` PR #8, sudah masuk `main`)
memindahkan layar notifikasi ke `src/app/notifikasi/index.tsx`, sementara berkas
uji masih menuntut path lama. **Bukan** akibat paket ini dan **tidak** ditutup-tutupi.
Membutuhkan keputusan pengguna — lihat `risiko-dan-uji-tertunda.md`.

---

## 6. Uji browser sungguhan

`tests/browser/uji-perapihan.mjs`, Chromium, tiga lebar: **1440 / 768 / 390 px**.
Bootstrap dan Font Awesome dilayani dari salinan lokal karena sandbox tidak punya
jaringan keluar; **kode aplikasi tidak diubah**, penggantian hanya di dalam
peramban uji.

56 pemeriksaan, 0 gagal. Cakupan: judul dan tombol halaman masuk (tinggi ≥ 44 px),
beranda per peran, laci navigasi ponsel (buka lewat tombol, tutup lewat `Escape`),
perpindahan tab modul Pengajian, tab penyajian laporan, gulir tabel di dalam
wadahnya, halaman tidak melebar, tautan lompat ke konten dan fokus keyboard,
media cetak tanpa sidebar, serta halaman penolakan untuk guru non-murobi.

### Cacat yang DITEMUKAN dan diperbaiki oleh uji ini

Pada 390 px, tombol **Keluar** terdorong 4 px keluar layar sehingga halaman
melebar (`documentElement.scrollWidth` 394 > 390). Diperbaiki di
`assets/ui/alhasan.css`: merek boleh terpotong, tombol tidak menyusut, padding
topbar mengecil, sub-judul merek disembunyikan di bawah 576 px. Setelah perbaikan
`scrollWidth` = 390.

### Tangkapan layar

| Folder | Isi |
| --- | --- |
| `tests/browser/tangkapan-sebelum/` | **SEBELUM** — baseline `c65390d`, 20 gambar (desktop + ponsel) |
| `tests/browser/tangkapan-perapihan/` | **SESUDAH** — branch ini, 20 gambar (desktop + tablet + ponsel) |

Dibuat dengan akun sandbox sintetis (`sbx_admin`, `sbx_guru_biasa`) dan tidak
memuat informasi pribadi. Format JPEG kualitas sedang agar ringan.

Perbandingan yang paling menjelaskan:

| Sebelum | Sesudah | Yang berubah |
| --- | --- | --- |
| `01-desktop-masuk-lama.jpg` | `01-desktop-masuk.jpg` | halaman masuk lama "Login Sistem" → "Masuk Sistem Al Hasan" satu pintu |
| `12-ponsel-tujuan-setelah-masuk.jpg` | `13-ponsel-beranda.jpg` | dashboard lama tanpa menu yang dapat dibuka di ponsel → beranda dengan laci navigasi |
| `06-desktop-akun.jpg` | `05-desktop-akun.jpg` | dropdown role Guru/Admin → role aktual + tombol beri/cabut per role |
| `03-desktop-jadwal.jpg` + `04-desktop-pertemuan.jpg` | `03-desktop-pengajian-pertemuan.jpg` | dua menu terpisah → satu modul bertab |
| `05-desktop-laporan-absensi.jpg` | `04-desktop-laporan-gabungan.jpg` | satu daftar campur → tab Santri/Guru/Gabungan dengan jumlah per jenis |

---

## 7. Yang TIDAK diuji di sini

Tidak boleh diklaim lulus tanpa bukti terpisah:

- **Safari fisik** (macOS/iOS). Chromium **bukan** bukti Safari.
- Perangkat Android/iOS fisik untuk halaman web.
- Migrasi 010 pada basis data produksi.
- Cron, deployment cPanel, dan smoke test produksi.
- Kedatangan push dan WhatsApp (di luar cakupan paket ini; WhatsApp tetap
  DITANGGUHKAN sejak Fase 4).
- Audit aksesibilitas otomatis (axe/Lighthouse).

Rincian dan langkah lanjutan: `risiko-dan-uji-tertunda.md`.
