# Hasil pengujian implementator — 8–9 September 2026

Lingkungan: PHP 8.4.14, MariaDB 12.3.2 lokal, database **baru** `webalhasan_v3_phase1_test`. Skema dasar hanya CREATE/ALTER tanpa data dump; fixture resmi fiktif dan 1.000 data performa. Tidak ada koneksi/deploy/migrasi produksi. Ini bukti implementator, bukan audit akhir Claude Code.

| Suite | Hasil | Bukti |
| --- | --- | --- |
| Statis/lint V3 | LULUS — 15 | `bukti/paket.txt` |
| Integrasi V3 | LULUS — 45 | `bukti/paket.txt` |
| Konkurensi V3 | LULUS — 3, dua proses + lock timeout/deadlock nyata | `bukti/paket.txt` |
| Diagnostik fixture V3 | LULUS — 3 (sehat 0, rusak nonzero, pulih 0) | `bukti/paket.txt` |
| Drill migrasi 013 | LULUS — 7 | `bukti/migrasi.txt` |
| Pre-check | LULUS — 24, exit 0 | `bukti/pre-check.txt` |
| Post-check | LULUS — 273, exit 0 | `bukti/post-check.txt` |
| Browser + API HTTP V3 | LULUS — 74 | `bukti/browser.txt`, `browser-results.json` |
| Regresi utama V1/V2/akun/penempatan/alumni/fondasi | LULUS — 49 suite, 4.010 pemeriksaan, exit 0 tanpa skip | `bukti/regresi.txt` |
| Regresi tambahan perapihan | LULUS — 15 suite, semua exit 0 | `bukti/regresi-tambahan.txt` |
| `git diff --check` | LULUS | Pemeriksaan sebelum commit |

## Apa yang benar-benar dibuktikan

- Migrasi maju 001–013 di atas struktur lama kosong; runner ulang, rollback 013, pemasangan ulang, runner ulang setelah pemasangan.
- Hash isi **seluruh tabel non-V3** identik sebelum/sesudah drill, termasuk satu catatan pelanggaran warisan fiktif yang sengaja dibuat sebelum manifest.
- Kolom, FK, indeks, unique, CHECK terpasang. Uji SQL invalid membuktikan poin negatif, FK kategori yatim, dan rentang negatif ditolak database. Duplikat kode benar-benar ditolak unique.
- Admin membuat/membaca kategori, jenis, ambang lewat service dan web. Overlap batas inklusif ditolak; tanggal/enum/poin/ID/filter array tidak valid ditolak. Pagination 25+2 tidak mengulang baris.
- Penyimpanan versi lama ditolak 409; audit sebelum/sesudah satu per mutasi. Trigger audit gagal memicu rollback tanpa baris bisnis baru.
- Pengurus tanpa hak admin dan role palsu ditolak. Capability pembimbing/murobi hanya dari penugasan aktif; berakhirnya penugasan menghilangkan hak. Relasi wali diarsipkan menghilangkan hak publikasi. Cakupan santri A vs B diuji pada tiga peran.
- Dua worker bersamaan menghasilkan satu penyimpanan dan satu konflik. Lock timeout sungguhan dipetakan ke konflik aman, tanpa data baru. Siklus kunci dua koneksi juga membuktikan deadlock nyata menjadi konflik aman.
- Browser: 1440/768/375 px, tiga tab, label kontrol, tanpa overflow formulir. Tampilan ditinjau dari screenshot fiktif. CSRF hilang/palsu 419; XSS menjadi teks; input aman dipertahankan saat duplikasi; aktif/nonaktif lewat web; hapus GET dan POST warisan 405.
- HTTP API: tanpa token 401; kelima role fixture login/profile lama tetap tersedia; katalog/ambang mengikuti hak; orang tua/guru tanpa murobi ditolak; mutasi Fase 2 tidak ada (404).
- Regresi kontrak V1/V2 dan fondasi membuktikan mode/default_mode/menu lama tetap sama. Tidak ada kode `alhasanApps` yang diedit.

## Koreksi selama pengujian

1. Dua tes guard statis awal gagal karena guard hanya terjangkau melalui layout. Pemanggilan guard eksplisit ditambahkan; suite statis dan runner lengkap diulang hingga exit 0.
2. Diagnostik sempat mendeteksi audit/outbox yatim sisa cleanup regresi lama. Itu deteksi benar. Sisa fixture fiktif yang dapat dibuktikan dibersihkan pada DB khusus sesi ini; pemeriksaan tidak dilonggarkan. Fixture overlap terpisah membuktikan exit nonzero.
3. Assertion jumlah audit awal menganggap audit lama hilang saat rollback skema. Audit memang dipertahankan. Tes diperbaiki membandingkan pertambahan audit dari baseline sebelum mutasi; seluruh paket diulang.
4. Assertion riwayat pengakhiran browser awal membaca teks saat panel audit tertutup; tes diperbaiki membuka panel seperti pengguna, lalu seluruh browser diulang hingga exit 0.

## Batas bukti

- **MEMERLUKAN UJI MYSQL:** migrasi 013 pada salinan produksi representatif MariaDB cPanel 10.6.27 atau MySQL target. MariaDB lokal 12.3.2 bukan versi hosting produksi.
- **MEMERLUKAN SMOKE TEST:** Safari/iOS, perangkat Android/iOS fisik, dan smoke cPanel. Browser lokal memakai Chromium headless dengan aset lokal dan request eksternal diblokir.
- Pengakhiran katalog lewat web, pembacaan tanggal akhir dan alasan audit telah diuji. Fase 2–5 tidak diuji sebagai fitur karena belum dibuka.
- Fixture tetap ada dalam DB uji; DB tidak diklaim kosong. Tidak ada penghapusan data produksi.

## Hasil setiap suite regresi utama

| Suite | Status | Pemeriksaan |
| --- | --- | --- |
| `tests/phase1_static.php` | LULUS | 76 |
| `tests/phase2_static.php` | LULUS | 46 |
| `tests/phase3_static.php` | LULUS | 35 |
| `tests/phase4_static.php` | LULUS | 38 |
| `tests/phase5_static.php` | LULUS | 44 |
| `tests/v2_phase1_static.php` | LULUS | 127 |
| `tests/v2_phase2_static.php` | LULUS | 174 |
| `tests/v2_phase3_static.php` | LULUS | 147 |
| `tests/v2_phase4_static.php` | LULUS | 289 |
| `tests/v2_phase5_static.php` | LULUS | 272 |
| `tests/v2_phase5_cetak_pdf.php` | LULUS | 76 |
| `tests/phase2_integration.php` | LULUS | 12 |
| `tests/phase3_integration.php` | LULUS | 10 |
| `tests/phase4_integration.php` | LULUS | 14 |
| `tests/phase5_integration.php` | LULUS | 20 |
| `tests/v2_phase1_integration.php` | LULUS | 39 |
| `tests/v2_phase2_integration.php` | LULUS | 94 |
| `tests/v2_phase2_navigasi_murobi.php` | LULUS | 40 |
| `tests/v2_phase2_web_smoke.php` | LULUS | 36 |
| `tests/v2_phase3_api_contract.php` | LULUS | 116 |
| `tests/v2_phase4_integration.php` | LULUS | 122 |
| `tests/v2_phase4_api_contract.php` | LULUS | 92 |
| `tests/v2_phase4_concurrency.php` | LULUS | 20 |
| `tests/v2_phase4_web_smoke.php` | LULUS | 46 |
| `tests/v2_phase5_integration.php` | LULUS | 143 |
| `tests/v2_phase5_api_contract.php` | LULUS | 149 |
| `tests/v2_phase5_web_smoke.php` | LULUS | 79 |
| `tests/v2_phase5_performance.php` | LULUS | 12 |
| `tests/perapihan_static.php` | LULUS | 132 |
| `tests/perapihan_integration.php` | LULUS | 53 |
| `tests/perapihan_akun_concurrency.php` | LULUS | 7 |
| `tests/perapihan_web_smoke.php` | LULUS | 56 |
| `tests/kredensial_static.php` | LULUS | 105 |
| `tests/kredensial_integration.php` | LULUS | 59 |
| `tests/kredensial_web_smoke.php` | LULUS | 51 |
| `tests/penempatan_static.php` | LULUS | 138 |
| `tests/penempatan_integration.php` | LULUS | 62 |
| `tests/penempatan_concurrency.php` | LULUS | 10 |
| `tests/penempatan_web_smoke.php` | LULUS | 47 |
| `tests/alumni_static.php` | LULUS | 180 |
| `tests/alumni_integration.php` | LULUS | 80 |
| `tests/alumni_concurrency.php` | LULUS | 13 |
| `tests/alumni_web_smoke.php` | LULUS | 61 |
| `tests/penugasan_resolver_failure.php` | LULUS | 5 |
| `tests/penugasan_runner.sh` | LULUS | 3 |
| `tests/penugasan_static.php` | LULUS | 282 |
| `tests/penugasan_integration.php` | LULUS | 161 |
| `tests/penugasan_concurrency.php` | LULUS | 52 |
| `tests/penugasan_web_smoke.php` | LULUS | 85 |

## Hasil setiap suite regresi tambahan

| Suite | Status | Pemeriksaan |
| --- | --- | --- |
| `perapihan_audit_account_log.php` | LULUS, exit 0 | 36 |
| `perapihan_audit_admin.php` | LULUS, exit 0 | 13 |
| `perapihan_audit_api_compat.php` | LULUS, exit 0 | 12 |
| `perapihan_audit_csv_limit.php` | LULUS, exit 0 | 4 |
| `perapihan_audit_form_feedback.php` | LULUS, exit 0 | 108 |
| `perapihan_audit_http.php` | LULUS, exit 0 | 25 |
| `perapihan_audit_kamar.php` | LULUS, exit 0 | 19 |
| `perapihan_audit_laporan_web.php` | LULUS, exit 0 | 38 |
| `perapihan_audit_merge.php` | LULUS, exit 0 | 4 |
| `perapihan_audit_notifikasi.php` | LULUS, exit 0 | 18 |
| `perapihan_audit_pagination.php` | LULUS, exit 0 | 45 |
| `perapihan_audit_redirect.php` | LULUS, exit 0 | 36 |
| `perapihan_audit_report_matrix.php` | LULUS, exit 0 | 432 |
| `perapihan_audit_wali.php` | LULUS, exit 0 | 16 |
| `perapihan_audit_wali_long_list.php` | LULUS, exit 0 | 46 |
