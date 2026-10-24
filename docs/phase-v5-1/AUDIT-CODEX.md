# Audit independen Codex — PRD V5 Fase 1

**24 Oktober 2026 · LULUS tanpa koreksi kode aplikasi.** Keenam kriteria penerimaan Fase 1 terpenuhi pada lingkungan uji lokal. Ini bukan penerimaan seluruh V5, izin deploy, atau instruksi memulai Fase 2.

## Cakupan dan baseline

- Branch tetap `claude/prd-v5-fase-1`, tanpa worktree. Baseline `cb7c181dc27a27172967377a4fc045027d7bc819`.
- Seluruh commit fase diperiksa: `8b588e8` (implementasi, migrasi, UI/API dan tes), `d8b474f` (bukti/handoff), `abce969` (keputusan BB menu admin). Remote branch diverifikasi sama dengan HEAD `abce9693f8a286e6f40c979ea0211691446dd906`; `origin/main` masih `cb7c181` saat audit.
- Dibaca `AGENTS.md`, seluruh `PRD-V5.md`, README fase, keputusan BB, perubahan seluruh berkas fase, layanan/resolver penugasan dan capability, audit, migrator, router serta pengujian terkait. Tidak ada perubahan tes historis oleh implementator maupun auditor.
- Mobile dibaca saja: `/Users/ilanmochamad/alhasanApps`, branch `codex/prd-v4-fase-5`, HEAD `ee6621a`, working tree bersih. Tidak ada perubahan mobile dan tidak ada klaim rilis produksi.
- Pengujian independen: PHP 8.4.14, MariaDB lokal, Node v26.7.0, Chromium Playwright. Target V5 auditor `test_codex_v5_f1_test`, `test_codex_v5_http_test`, `test_codex_v5_browser_test`; regresi terpisah `test_codex_v5_t2_test` dan `test_codex_v5_t6_test`; suite migrasi memakai dua DB `test_v5_f1_migrasi_test`/`test_v5_f1_rollback_test`. Sumber dan target fixture dibatasi skrip ke DB uji; tidak mengakses produksi. Server hanya localhost.
- Permintaan handoff dan working tree awal konsisten dengan giliran auditor; proses Claude yang masih terbuka tidak dihentikan atau dikirimi instruksi. Tidak ditemukan perubahan bersamaan pada berkas terlacak selama audit. Tiga direktori pekerjaan lain (`docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/`) tidak disentuh atau dimasukkan commit.

## Hasil wajib Fase 1

| Kriteria PRD | Penilaian dan bukti independen |
| --- | --- |
| Admin menugaskan pengurus, kunjungan web/API tersimpan dan dapat dibaca, admin membaca rincian/audit | Lulus. Integrasi I-2/I-3/I-6, HTTP H-B sampai H-E dan browser. Capability tetap berasal dari fondasi penugasan; tidak ada role baru. |
| Nonpetugas/akun nonaktif/admin ditolak mutasi klinis | Lulus. Matriks web/API/layanan mencakup admin+petugas, pengurus biasa, pembimbing, guru, murobi, wali dan akun nonaktif. Probe tambahan memakai objek pengguna lama setelah master diarsipkan/dinonaktifkan, role dicabut atau menjadi admin, tahun diarsipkan dan tanggal penugasan digeser. |
| Idempotensi, klik ganda dan konflik isi | Lulus. 8 proses dengan kunci/isi sama menghasilkan satu kunjungan dan tujuh replay; 6 proses dengan isi berbeda menghasilkan satu sukses dan lima konflik. Browser menguji klik ganda dan HTTP menguji replay lintas kanal. |
| Pencabutan saat sesi aktif menutup tulis berikutnya | Lulus. Sesi/token lama ditolak; probe auditor menahan transaksi pencabutan, mendeteksi pekerja sedang menjalankan pembacaan berkunci, lalu commit pencabutan: pekerja 403, tanpa kunjungan/versi/klaim/audit baru. |
| Migrasi/rollback menjaga data lama dan tidak menambah referensi yatim | Lulus. Migrasi naik–turun–naik, manifest jumlah seluruh tabel lama, checksum 17 tabel inti, struktur inti tetap, 18 FK dan nol yatim. Rollback preflight menolak tabel kesehatan berisi data; sebelum migrasi/sesudah rollback layanan 503 dan pusat penugasan lama berjalan. |
| 375 px, simpan–baca ulang, tanpa isi klinis di log umum | Lulus. Browser tiga lebar dan tanpa JavaScript; empat tangkapan 375 px diperiksa visual. Daftar tanpa kolom klinis. HTTP memeriksa log server/audit, probe membuktikan kegagalan snapshot/audit membatalkan seluruh transaksi dan kegagalan log akses menutup rincian. |

## Pengujian yang dijalankan auditor

Semua bukti berikut berasal dari eksekusi auditor, bukan salinan hasil implementator. Berkas ada di [bukti/audit-codex/](bukti/audit-codex/).

| Rangkaian | Hasil | Berkas bukti |
| --- | --- | --- |
| Statis V5 + lint PHP fase | 48/0 | `statis.txt` |
| Integrasi layanan + konkurensi V5 | 75/0 | `integrasi.txt` |
| HTTP web/API V5 | 82/0 | `http.txt` |
| Migrasi/rollback pada salinan | 26/0 | `migrasi-rollback.txt` |
| Probe auditor pencabutan, kegagalan dan balapan | 27/0 | `probe.txt` |
| Browser V5 375/768/1280 + tanpa JS | 35/0 | `browser.txt`, `tangkapan/*.png` |
| Integrasi fondasi penugasan | 161/0 | `penugasan-integrasi.txt` |
| HTTP pembinaan Tahap 5 | 106/0 | `regresi-tahap5-http.txt` |
| HTTP penilaian/rapor Tahap 7 | 122/0 | `regresi-tahap7-http.txt` |
| HTTP data/penugasan Tahap 6, 024 kosong dilepas dahulu | 132/0 | `regresi-tahap6-http.txt` |
| Browser kerangka sembilan akun, tiga lebar | 107/0 | `browser-kerangka.txt` |
| HTTP Beranda/navigasi Tahap 2, fixture baru | 190/1, hanya oracle 17 menu | `regresi-tahap2-http.txt` |
| Seluruh 31 suite statis | 22 suite exit 0; 7 oracle historis exit 1; 2 exit 77 | `regresi-statis-ringkasan.txt`, `statis-*.txt` |

Pengujian baru [tests/v5_phase1_audit.php](../../tests/v5_phase1_audit.php) dapat diulang setelah fixture dan suite integrasi V5:

```bash
V5F1_DB=test_codex_v5_f1_test bash tests/v5_phase1_db.sh
V5F1_DB=test_codex_v5_f1_test V5_RUN_INTEGRATION=1 php tests/v5_phase1_integration.php
V5F1_DB=test_codex_v5_f1_test V5_RUN_AUDIT=1 php tests/v5_phase1_audit.php
```

Probe memakai trigger kegagalan sementara dan koneksi/proses terpisah hanya pada fixture; trigger dan status master/role/penugasan dipulihkan. Kunjungan sintetis sukses tetap berada di DB uji. Tidak ada trigger kegagalan yang ditambahkan ke migrasi/aplikasi.

Percobaan HTTP Tahap 2 pertama memakai DB yang sudah dimutasi suite Tahap 7/5 dan menghasilkan 180/11. Hasil tersebut tetap disimpan (`regresi-tahap2-http-fixture-terpakai.txt`). Kontrol dengan **fixture resmi Tahap 2 yang baru**, kode dan oracle identik, menghasilkan 190/1; sepuluh selisih hitungan/akses hilang. Satu kegagalan sisanya memang penambahan menu sesuai BB.

## Oracle historis dan pelurusan bukti

Oracle tidak diubah atau dilonggarkan. Pemeriksaan sumber/diff membedakan pembatasan fase lama dari perilaku yang tetap diwajibkan.

| Suite | Hasil audit | Penyebab |
| --- | --- | --- |
| `penugasan_static` | 281/2 | Memastikan tepat tujuh jenis; PRD V5 mewajibkan jenis kedelapan. Integrasi penugasan 161/0. |
| `redesain_web_tahap6_static` | 140/1 | Melarang perubahan Navigation sejak Tahap 6; BB menambah Kesehatan. `app/Auth` tidak berubah. |
| `redesain_web_tahap7_static` | 100/2 | Melarang perubahan katalog, API, database dan bin sejak Tahap 7; perubahan aditif V5 diperlukan. |
| `redesain_web_tahap8_static` | **78/2** | Direktori **database dan api** berbeda dari baseline Tahap 8. Catatan implementator 79/1 diluruskan untuk commit final yang telah melacak migrasi 024; bukti lama tidak ditimpa. |
| `keamanan_psb_static` | 82/1 | Larangan pratinjau lama sudah diganti Tahap 8. |
| `redesain_web_tahap3_static` | 151/1 | Oracle pratinjau yang sama. |
| `redesain_web_tahap4_static` | 95/1 | Larangan perubahan database sejak Tahap 3; migrasi 023/024 sudah diperlukan fase sesudahnya. |
| `v3_phase2_static`, `v3_phase3_static` | exit 77 | Skrip historis memang dilewati pada baseline sekarang; bukan klaim lulus. |

Angka baseline implementator di `bukti/regresi/statis-banding.txt` tetap merupakan bukti implementator. Auditor menjalankan seluruh suite di branch final dan memeriksa diff/sumber penyebabnya; auditor tidak melakukan checkout baseline ulang. `navigasi_ikon_static` juga lulus, termasuk `fa-kit-medical` terhadap fixture Font Awesome Free 6.0.0. Browser memakai intersepsi aset lokal seperti runner resmi; ini bukan verifikasi CDN/hosting produksi.

## Tinjauan penafsiran P-1 sampai P-10

| Butir | Kesimpulan auditor untuk Fase 1 |
| --- | --- |
| P-1 | Mengikuti keputusan eksplisit BB: 18 menu admin. Tidak mengubah 17 menu lainnya. |
| P-2 | Diterima: kesehatan tidak dibatasi semester; status tahun nonaktif tanpa arsip tetap memberi hak, arsip tahun menutup hak. Probe memeriksa keduanya. |
| P-3 | Diterima: larangan admin mengubah isi klinis juga berlaku bagi admin+petugas. Tidak ada impersonasi petugas melalui capability pengawasan. |
| P-4 | Diterima: tanggal selesai inklusif mengikuti fondasi. Pengakhiran hari ini berlaku sampai akhir hari; pencabutan segera memakai Nonaktifkan atau tanggal selesai yang sudah lewat. Tidak mengubah semantik penugasan lama. |
| P-5 | Diterima dalam batas fase: UI Fase 1 membuat Tercatat; skema sudah mengenal Draf. Alur draf/penyuntingan tetap harus ditangani saat Fase 2 diperintahkan. |
| P-6 | Diterima: daftar tanpa isi klinis; rincian hanya keluar setelah log akses berhasil. Kegagalan log akses diuji 503. |
| P-7 | Diterima: petugas aktif dapat membaca riwayat santri yang kemudian nonaktif; pencatatan baru tetap memerlukan santri aktif. Riwayat tidak hilang karena santri dinonaktifkan. |
| P-8 | Diterima: idempotensi per akun tanpa salinan respons; replay memeriksa otorisasi terbaru dan penugasan di dalam transaksi. |
| P-9 | Diterima: batas panjang dan waktu merupakan validasi operasional; toleransi jam lima menit tidak mengubah fakta klinis. Isian gagal tidak disimpan ke sesi. |
| P-10 | Diterima: nama kolom metadata `aksi` membedakannya dari isi klinis `tindakan`. |

Tinjauan ini menilai implementasi terhadap PRD dan batas fase; bukan keputusan produk baru untuk fase mendatang.

## Batas dan handoff

- Tidak ada pemeriksaan cPanel/produksi, MySQL hosting, Safari fisik, pembaca layar atau aplikasi/perangkat iOS/Android. Kelulusan lokal tidak membuktikan hosting sudah siap/deploy.
- Interleaving pencabutan yang memegang kunci lebih dahulu telah diuji. Deadlock 1205/1213, seluruh kemungkinan perubahan role bersamaan, beban besar dan semua urutan balapan lain belum direproduksi. Pengujian bukan bukti seluruh interleaving terliput.
- Rollback aman mensyaratkan preflight kosong, backup terverifikasi dan tidak ada penulis aktif selama operasi. SQL rollback menghapus tabel V5; `bin/migrate.php rollback` tidak otomatis memanggil preflight. Jangan menjalankannya ketika ada data kesehatan; gunakan migrasi korektif atau prosedur pemulihan terencana. Tidak ada rollback produksi dalam audit ini.
- Commit auditor menambah probe/bukti dan memperbarui status/penjelasan hasil uji. **Tidak ada koreksi kode aplikasi**, migrasi baru, merge, deploy atau pekerjaan Fase 2.

**Handoff:** Fase 1 memenuhi kriteria wajib berdasarkan bukti di atas. Human Developer menentukan langkah berikutnya; Fase 2 tetap belum dimulai dan memerlukan instruksi terpisah.
