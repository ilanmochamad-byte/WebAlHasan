# Hasil pengujian: Fondasi Penugasan V3–V6

Keputusan pengguna 7 September 2026. Branch `feat/fondasi-penugasan-v3-v6`.

Dokumen ini melaporkan **apa yang benar-benar dijalankan**, bukan apa yang
seharusnya lulus. Empat label dipakai secara ketat:

| Label | Arti |
| --- | --- |
| **LULUS** | benar-benar dijalankan di lingkungan pengembangan, hasilnya hijau |
| **BELUM DIJALANKAN** | tidak dijalankan sama sekali; tidak ada bukti |
| **MEMERLUKAN UJI MYSQL** | hanya dapat dibuktikan pada MySQL/MariaDB produksi atau salinannya |
| **MEMERLUKAN SMOKE TEST** | hanya dapat dibuktikan manusia pada peramban/perangkat sungguhan |

## 1. Lingkungan pengujian

| Hal | Nilai |
| --- | --- |
| PHP | 8.4.14 (CLI, Homebrew, macOS) |
| Basis data | MariaDB 12.3.2 lokal, `binlog_format = MIXED` |
| Database uji | `webalhasan_phase4_codex_20260823_test`, migrasi 001–012 diterapkan; fixture sandbox `sbx_*` |
| Aplikasi mobile | **tidak tersedia** di mesin ini (`alhasanApps` tidak ter-checkout) |
| Composer | tersedia; proyek tidak memakai dependensi Composer |

Seluruh fixture pengujian memakai data fiktif berakhiran acak dan dihapus
kembali pada blok `finally` (dibuktikan: jumlah baris `users`, `guru`,
`pengurus`, `kelas`, `kamar`, `tahun_ajaran`, `mata_pelajaran`, dan tabel
penugasan kembali ke nilai semula setelah tiap rangkaian). Tidak ada data
produksi yang disentuh dan tidak ada permintaan jaringan keluar.

## 2. Ringkasan paket ini

| Rangkaian | Status | Pemeriksaan |
| --- | --- | --- |
| `tests/penugasan_static.php` | **LULUS** | 274 |
| `tests/penugasan_integration.php` | **LULUS** | 161 |
| `tests/penugasan_web_smoke.php` | **LULUS** | 73 |
| `bin/penugasan_preflight.php` | **LULUS** (exit 0, tidak ada penghalang) | 6 bagian |
| `bin/penugasan_verify.php --murobi=3 --pembimbing=3` | **LULUS** (exit 0) | 154 |
| Migrasi 012: naik → rollback → naik → naik lagi (idempoten) | **LULUS** | lihat §6 |
| `bash bin/penugasan_run_all_tests.sh` | **LULUS** untuk bagian B–D | 508 pemeriksaan paket |

**Total pemeriksaan paket ini yang lulus: 662** (274 + 161 + 73 + 154).

## 3. Pemetaan ke 24 pengujian wajib

| # | Pengujian wajib | Status | Bukti |
| --- | --- | --- | --- |
| 1 | Role dasar lama tetap berfungsi | **LULUS** | FI-1 (8), FS-2/FS-3, verify (`roles` = 4) |
| 2 | Murobi tetap capability dari penugasan guru | **LULUS** | FI-2 (4), FI-8 (nonaktif/aktif murobi dari pusat) |
| 3 | Pembimbing tetap capability dari penugasan pengurus | **LULUS** | FI-3 (4), `PembimbingService::activeForPengurus` membaca penugasan dari pusat |
| 4 | Admin dapat membuat setiap jenis penugasan baru | **LULUS** | FI-4 (12), FW-5 (tujuh jenis + master mata pelajaran lewat formulir) |
| 5 | Non-admin tidak dapat mengelola penugasan | **LULUS** | FI-5 (10: guru, pengurus, orang tua, anonim; buat/ubah/akhiri/nonaktif/mapel), FW-2 (403 halaman + POST tanpa perubahan) |
| 6 | Penugasan belum mulai tidak menghasilkan capability | **LULUS** | FI-6 (2) |
| 7 | Penugasan aktif menghasilkan capability yang benar | **LULUS** | FI-7 (19), FW-5c |
| 8 | Penugasan berakhir tidak lagi menghasilkan capability | **LULUS** | FI-8 (11: lampau, akhiri, nonaktif, aktifkan kembali) |
| 9 | Pengurus dapat memiliki beberapa penugasan sekaligus | **LULUS** | FI-7/9 (13 capability dari 4 penugasan), FW-10i |
| 10 | Panitia PSB tidak otomatis bendahara PSB | **LULUS** | FI-10, FW-5l |
| 11 | Bendahara PSB tidak otomatis panitia PSB | **LULUS** | FI-11 |
| 12 | Guru hanya memperoleh capability mapel pada kelas/semester/tahun yang ditugaskan | **LULUS** | FI-12 (11) |
| 13 | Penugasan duplikat ditolak | **LULUS** | FI-13 (5, termasuk audit), FW-6b |
| 14 | Penugasan bertumpang tindih tidak sah ditolak | **LULUS** | FI-14 (10: 6 ditolak, 3 diterima, ubah+aktifkan), FW-6a |
| 15 | Perubahan penugasan dan audit transaksional | **LULUS** | FI-15 (5: tabel audit di-rename → mutasi batal penuh; audit sukses; delta capability) |
| 16 | IDOR dan manipulasi parameter ditolak | **LULUS** | FI-16 (14), FW-13 (2) |
| 17 | CSRF hilang/tidak valid ditolak | **LULUS** | FW-3 (2: 419 tanpa perubahan) |
| 18 | Halaman lama murobi/pembimbing tetap berfungsi | **LULUS** | FW-8 (5: GET 200 + tautan, POST lama menyimpan, tampil di pusat); regresi `perapihan_audit_form_feedback`/`pagination` |
| 19 | Endpoint profil lama tetap kompatibel | **LULUS** | FI-19 (5), FW-10 (13) |
| 20 | Mode dan menu aplikasi lama tidak berubah | **LULUS** | FI-20 (3), FW-10g/10i/10j/10k |
| 21 | Login guru, pengurus, admin, orang tua tetap berfungsi | **LULUS** | FI-21 (4, `AuthService::attempt`), FW-11 (4, HTTP), FW-10a (API) |
| 22 | Jadwal, absensi, laporan, perizinan V1–V2 tidak regresi | **LULUS** (otomatis) | rangkaian regresi §4; FW-10l/10m |
| 23 | Tidak ada capability dari manipulasi sesi/respons klien | **LULUS** | FI-23 (5), FS-10 |
| 24 | Audit menyimpan perubahan tanpa data sensitif | **LULUS** | FI-24 (10), FW-7g (IP + user agent) |

Tambahan di luar daftar: FI-25 (capability tidak efektif bila akun/role dasar/
master/mata pelajaran tidak valid, 8 pemeriksaan), FI-26 (tahun ajaran: PSB
boleh belum aktif, arsip ditolak, non-PSB menunggu Aktif), FW-12 (escape XSS),
FS-15/16 (tidak ada fitur bisnis dan tidak ada menu mobile baru).

## 4. Regresi paket sebelumnya

Dijalankan `bash bin/alumni_run_all_tests.sh` (mencakup seluruh rantai V1, V2,
perapihan, kredensial, penempatan, alumni) pada database uji yang sama setelah
migrasi 012 terpasang dan seluruh kode paket ini ada:

| Rangkaian | Status | Lulus |
| --- | --- | --- |
| `phase1_static` … `phase4_static` | **LULUS** | 75 / 46 / 35 / 38 |
| `phase5_static` | **GAGAL — sebab lingkungan** (3 pemeriksaan berkas aplikasi mobile; `alhasanApps` tidak ada di mesin ini; sama seperti laporan paket alumni) | — |
| `v2_phase1_static`, `v2_phase2_static` | **LULUS** | 127 / 174 |
| `v2_phase3_static` | **GAGAL** pada run pertama: (a) `MOBILE_APP_ROOT` tidak ada — lingkungan; (b) patokan lama "jumlah migrasi = 10" — **diselaraskan** paket ini mengikuti preseden PS-15 paket penempatan (lihat §7); setelah penyelarasan hanya (a) yang tersisa | — |
| `v2_phase4_static` | **GAGAL — sebab lingkungan** (`MOBILE_APP_ROOT`) | — |
| `v2_phase5_static`, `v2_phase5_cetak_pdf` | **LULUS** | 240 / 76 |
| `phase2` … `phase5_integration` | **LULUS** | 12 / 10 / 14 / 20 |
| `v2_phase1_integration`, `v2_phase2_integration`, `v2_phase2_navigasi_murobi`, `v2_phase2_web_smoke` | **LULUS** | 39 / 94 / 40 / 36 |
| `v2_phase3_api_contract` | **LULUS** | 116 |
| `v2_phase4_integration`, `api_contract`, `concurrency`, `web_smoke` | **LULUS** | 122 / 92 / 20 / 46 |
| `v2_phase5_integration`, `api_contract`, `web_smoke`, `performance` | **LULUS** | 143 / 150 / 79 / 12 |
| `perapihan_static`, `integration`, `akun_concurrency`, `web_smoke` | **LULUS** | 132 / 53 / 7 / 56 |
| `kredensial_static`, `integration`, `web_smoke` | **LULUS** | 105 / 59 / 51 |
| `penempatan_static`, `integration`, `concurrency`, `web_smoke` | **LULUS** | 138 / 62 / 10 / 47 |
| `alumni_static`, `integration`, `concurrency`, `web_smoke` | **LULUS** | 180 / 80 / 13 / 61 |

Seluruh rangkaian **integrasi, API, concurrency, dan smoke web** V1–V2 lulus.
Tiga kegagalan statis seluruhnya berasal dari ketiadaan folder `alhasanApps`
pada mesin ini — paket ini memang **tidak mengubah** repositori itu — sehingga
tidak dapat dinilai di sini dan masuk **BELUM DIJALANKAN** untuk pemeriksaan
berkas aplikasi mobile.

## 5. Yang tidak dapat dibuktikan di lingkungan ini

| Hal | Status | Cara membuktikan |
| --- | --- | --- |
| Migrasi 012 pada MySQL produksi/salinan produksi (versi MySQL hosting, ukuran tabel sebenarnya) | **MEMERLUKAN UJI MYSQL** | `cpanel-deployment.md` §4–§8 pada salinan `_test` dari backup produksi |
| Aplikasi perangkat yang sudah terpasang tetap login/profil/mode/jadwal/absensi/laporan/perizinan | **MEMERLUKAN SMOKE TEST** (kontrak API-nya **LULUS** FW-10) | `cpanel-deployment.md` §9.1 |
| Tampilan Pusat Penugasan pada 1440/768/390 px, Safari fisik, `<details>` di iOS | **MEMERLUKAN SMOKE TEST** | `cpanel-deployment.md` §9.2 |
| Pembaca layar | **BELUM DIJALANKAN** | — |
| `npm run lint` / `npx tsc --noEmit` aplikasi mobile | **BELUM DIJALANKAN** (tidak relevan: `alhasanApps` tidak diubah) | — |
| Pemeriksaan berkas aplikasi mobile pada `phase5_static`, `v2_phase3_static`, `v2_phase4_static` | **BELUM DIJALANKAN** (lingkungan) | jalankan dengan `MOBILE_APP_ROOT` |

## 6. Migrasi dan rollback

Pada database uji: `php bin/migrate.php up` (012 diterapkan) → `rollback`
(enam tabel dan lima kolom jejak dilepas, baris murobi/pembimbing tetap 3/3) →
`up` (diterapkan ulang) → `up` (tidak ada migrasi baru) → seluruh pernyataan
guarded tidak mengeluh saat dijalankan ulang. `SHOW CREATE TABLE` memastikan
kunci unik, CHECK, dan kunci asing terpasang sesuai berkas migrasi.

## 7. Perubahan pada pengujian lama

`tests/v2_phase3_static.php`: patokan "jumlah berkas migrasi === 10" diganti
pemeriksaan maksud aslinya ("Fase 3 sendiri tidak menambah migrasi"), mengikuti
preseden PS-15 pada paket penempatan (6 September 2026). Tanpa penyesuaian,
setiap paket yang sah menambah migrasi (011 alumni, 012 ini) dilaporkan sebagai
kegagalan Fase 3. Tidak ada pengujian lama lain yang diubah.
