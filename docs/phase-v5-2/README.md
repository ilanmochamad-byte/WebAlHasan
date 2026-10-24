# PRD V5 Fase 2 — Obat, Rujukan, Koreksi, dan Pembaca Internal

**Status (24 Oktober 2026):** diimplementasikan Claude Code, **lulus audit independen Codex setelah tiga koreksi terarah konsistensi pembacaan dan larangan admin menulis klinis**. Laporan, hasil eksekusi auditor, tinjauan Q-1…Q-11 dan batas uji di [AUDIT-CODEX.md](AUDIT-CODEX.md). Bukti implementator di bawah dipertahankan sebagai riwayat handoff. Belum merge, belum deploy, belum migrasi produksi. Fase 3 belum dimulai.

| Butir | Nilai |
| --- | --- |
| Workstream | PRD V5 Fase 1–5 kesehatan santri — implementator Claude Code, auditor Codex (`AGENTS.md`) |
| Branch | `claude/prd-v5-fase-2` |
| Baseline | `1852fdba5e706c602a66c026c6d7ed92fa055797` — commit audit Codex yang meluluskan Fase 1 (`audit(v5): luluskan Fase 1 dengan bukti independen Codex`). Diverifikasi: `origin/claude/prd-v5-fase-1` = `1852fdb`; `origin/main` `241d09e` (merge PR #80) memuat `1852fdb` dan `git diff 1852fdb origin/main` kosong. Branch dibuat dari `1852fdb`, bukan dari `main`. |
| Mobile | `~/alhasanApps` tidak dibaca ulang dan tidak diubah (Fase 2 hanya web/API; aplikasi = Fase 4). API V5 hanya bertambah rute baru. |
| Lingkungan uji | PHP 8.4.14, MariaDB 12.3.2 lokal, Node v26, Chromium Playwright; seluruh DB `test_*_test` dengan fixture sintetis (`sbx_v5_*`, santri `V5S01xx`). Tidak ada data produksi yang dibuka/diubah. |

## Yang dikerjakan (persyaratan Fase 2)

1. **Obat, rujukan, hasil, tindak lanjut pada kunjungan yang sama** — migrasi aditif `025_v5_fase2_rincian_kesehatan.sql` (hanya `CREATE TABLE IF NOT EXISTS`, tabel 024 tidak diubah) + rollback berpasangan:
   - `kesehatan_obat` — nama, jumlah (desimal, opsional), satuan, petunjuk yang ditulis petugas, waktu pemberian, pencatat. **Tidak ada stok**: tidak ada tabel/kolom persediaan, pembelian, sisa, atau kedaluwarsa, dan tidak ada saran obat/dosis.
   - `kesehatan_rujukan` — tujuan, tanggal, alasan/catatan, status `Menunggu hasil|Hasil diketahui` (CHECK konsisten dengan isi), hasil, tanggal hasil. Tanpa integrasi fasilitas luar.
   - `kesehatan_tindak_lanjut` — uraian, target, status `Terbuka|Selesai`, tanggal selesai.
   - `kesehatan_versi_rincian` — snapshot JSON obat/rujukan/tindak lanjut aktif untuk setiap versi; kunci asing gabungan ke `kesehatan_kunjungan_versi (kunjungan_id, versi)`, terkait klaim idempotensi yang menghasilkannya.
   Rincian yang dihapus lewat koreksi diberi `versi_dibatalkan` — **tidak pernah `DELETE`**.
2. **Riwayat per santri tanpa duplikasi** — daftar tetap satu baris per kunjungan; penanda jumlah obat, rujukan menunggu hasil, dan tindak lanjut terbuka dihitung lewat subkueri. `portal/kesehatan.php?santri_id=` menjadi "Riwayat kesehatan santri" (urut terbaru); detail menautkan ke riwayat santrinya.
3. **Koreksi berversi** (`KesehatanService::koreksi()`, `hasilRujukan()`, satu jalur privat `terapkan()`): alasan wajib (≥ 5 karakter) bila kunjungan Tercatat, versi monoton, optimistic version (kunci tulis baris + `UPDATE … WHERE versi = ?`), idempotensi per akun, snapshot isi + rincian per versi, audit metadata. Riwayat versi (`portal/kesehatan_riwayat.php`, `GET …/versi`) menampilkan pelaku, waktu, alasan, dan perubahan sebelum–sesudah — hanya petugas dan admin, setiap pembukaan tercatat (`aksi = baca_versi`). Alur **Draf** (P-5 Fase 1) kini aktif: "Simpan sebagai draf", "Lanjutkan draf", "Tetapkan sebagai tercatat".
4. **Pembaca pembimbing dan murobi** — `akses()` menambah sumber `pembimbing`, `murobi`, `pembimbing_murobi` (cakupan `binaan`) bila akun tidak memegang hak seluruh santri. Klausa cakupan `KesehatanRepository::cakupanSql()` sama dengan profil santri binaan D-10 (`ProfilSantriService::binaanSql`) dan dipasang pada SQL daftar, ringkasan, dan detail (per baris), ditambah `status = 'Tercatat'`. Dihitung ulang setiap permintaan sehingga pencabutan penugasan, pindah kamar, kelas selesai, master/akun nonaktif, role dicabut, atau santri nonaktif menutup akses pada sesi/token lama. Tidak ada tombol/endpoint mutasi bagi mereka; seluruh percobaan ditolak 403 di layanan.
5. **Penyaring/laporan operasional internal** — santri, cari nama/NIS, tanggal dari–sampai, status rujukan (menunggu/diketahui/ada/tanpa), tindak lanjut (ada yang terbuka/tidak), status catatan (petugas/admin saja). Ringkasan angka (kunjungan, dengan obat, rujukan menunggu, tindak lanjut terbuka, draf) dihitung dari kueri yang **sama** dengan daftar, sehingga hak per baris tidak berkurang.

### Antarmuka

- Web: `portal/kesehatan.php` (penyaring + ringkasan), `kesehatan_buat.php` (rincian + draf), `kesehatan_detail.php` (obat/rujukan/tindak lanjut), baru `kesehatan_koreksi.php`, `kesehatan_hasil_rujukan.php`, `kesehatan_riwayat.php`, dan bagian formulir bersama `_kesehatan_form.php` (berjalan tanpa JavaScript: baris kosong tambahan, centang "Hapus" untuk baris lama).
- Menu: pembimbing → **Kesehatan** di grup "Santri binaan"; murobi → **Kesehatan** di grup "Murobi"; petugas dan admin tetap seperti Fase 1.
- API (token, `Cache-Control: private, no-store`), aditif:

  | Metode & jalur | Hak | Keterangan |
  | --- | --- | --- |
  | `GET /api/v1/v5/kesehatan/akses` | semua akun | kini juga `cakupan`, `riwayat_versi`, `binaan` |
  | `GET /api/v1/v5/kesehatan/kunjungan` | petugas, admin, pembimbing, murobi | penyaring `dari, sampai, rujukan, tindak_lanjut`; baris + `penanda`, `ringkasan`, `filter`; tanpa isi klinis |
  | `POST /api/v1/v5/kesehatan/kunjungan` | petugas | badan Fase 1 + `status` (`Draf`/`Tercatat`, bawaan Tercatat), `obat[]`, `rujukan[]`, `tindak_lanjut[]` |
  | `GET /api/v1/v5/kesehatan/kunjungan/{id}` | petugas, admin, pembimbing/murobi (dalam cakupan, Tercatat) | + `obat`, `rujukan`, `tindak_lanjut`; tercatat di log akses |
  | `POST /api/v1/v5/kesehatan/kunjungan/{id}/koreksi` | petugas | `versi`, `alasan`, `status` (kosong = tetap), keadaan lengkap (rincian lama dengan `id`, baru tanpa `id`, tidak dikirim = dibatalkan); `200 {id, versi, replayed}` / `409` / `422` |
  | `POST /api/v1/v5/kesehatan/kunjungan/{id}/rujukan/{rid}/hasil` | petugas | `versi`, `alasan`, `hasil`, `tanggal_hasil?`; `200 {id, versi, replayed}` |
  | `GET /api/v1/v5/kesehatan/kunjungan/{id}/versi` | petugas, admin | riwayat versi + perubahan sebelum–sesudah; tercatat di log akses |
  | `GET /api/v1/v5/kesehatan/kunjungan/{id}/akses` | admin | tetap seperti Fase 1 |

- Operasi: `bin/v5_phase2_preflight.php [manifest]`, `bin/v5_phase2_verify.php [--rollback] [manifest]`, `bin/v5_phase2_rollback_preflight.php`; fixture `tests/v5_phase2_seed.php`, DB uji `tests/v5_phase2_db.sh`, pekerja konkurensi `tests/v5_phase2_worker.php`.

## Kriteria penerimaan → bukti

| Kriteria Fase 2 | Bukti |
| --- | --- |
| Satu kunjungan dengan dua obat dan satu rujukan → satu baris daftar, dua obat pada detail, satu rujukan yang dapat diperbarui hasilnya | `bukti/integrasi.txt` J-2, J-3; `bukti/http.txt` K-B, K-C, K-F; `bukti/browser.txt` C-B, C-C |
| Koreksi tanpa alasan ditolak; koreksi sah → versi baru + riwayat sebelum/sesudah; dua koreksi bersamaan dari versi sama → satu sukses, satu konflik, tanpa kehilangan data | `bukti/integrasi.txt` J-4, J-6 (6 proses: 1 sukses + 5×409; 6 kiriman sama: 1 versi + 5 replay); `bukti/http.txt` K-D, K-F; `bukti/browser.txt` C-D, C-E |
| Pembimbing/murobi terkait membaca, seluruh percobaan tulisnya ditolak; petugas tanpa penugasan dan pembimbing/murobi di luar cakupan ditolak/hasil kosong | `bukti/integrasi.txt` J-1, J-7, J-8; `bukti/http.txt` K-A (8 halaman × 11 akun), K-F, K-G; `bukti/browser.txt` C-G |
| Perubahan cakupan/pencabutan pada sesi lama segera menutup detail | `bukti/integrasi.txt` J-9 (nonaktif, berakhir kemarin, pindah kamar, kelas selesai, master nonaktif, akun nonaktif, santri nonaktif, role dicabut), J-10; `bukti/http.txt` K-G (sesi web + token API lama) |
| Tidak ada perubahan stok atau tabel PSB/keuangan/izin | `bukti/integrasi.txt` J-12 (tanpa tabel stok; jumlah baris seluruh tabel non-kesehatan tetap; checksum `psb_pendaftar`, `psb_pembayaran`, penugasan PSB, `izin_*`, `perizinan`, `wali`, `santri_wali` identik); `bukti/statis.txt` T-1 |

## Hasil pengujian (lokal)

Seluruh hasil berikut dijalankan pada kode final branch (ringkasan `bukti/ringkasan-akhir.txt`).

| Rangkaian | Hasil |
| --- | --- |
| Statis V5 Fase 2 `tests/v5_phase2_static.php` (`bukti/statis.txt`) | 63/0 |
| Integrasi layanan + konkurensi `tests/v5_phase2_integration.php` (`bukti/integrasi.txt`) | 134/0 |
| HTTP web + API `tests/v5_phase2_http.php` (`bukti/http.txt`) | 87/0 |
| Migrasi/rollback 025 pada salinan `tests/v5_phase2_migration.php` (`bukti/migrasi-rollback.txt`) | 29/0 |
| Browser 375/768/1280 + tanpa JS `tests/browser/uji-v5-fase2.mjs` (`bukti/browser.txt`, `tangkapan/*.png` diperiksa visual) | 58/0, log server 0 galat PHP |

Cara mengulang:

```bash
php tests/v5_phase2_static.php
bash tests/v5_phase2_db.sh && V5_RUN_INTEGRATION=1 php tests/v5_phase2_integration.php
V5F2_DB=test_v5_f2_http_test bash tests/v5_phase2_db.sh && V5F2_DB=test_v5_f2_http_test php tests/v5_phase2_http.php
V5_RUN_MIGRATION=1 php tests/v5_phase2_migration.php
V5F2_DB=test_v5_f2_browser_test bash tests/v5_phase2_db.sh
DB_NAME=test_v5_f2_browser_test php -S 127.0.0.1:8997 tests/redesain_web_tahap1_router.php &
(cd tests/browser && BASE_URL=http://127.0.0.1:8997 node uji-v5-fase2.mjs)
```

**Jalankan skrip DB secara berurutan.** Skrip fixture berantai (`redesain_web_tahap3/4_db.sh`) memakai DB perantara bernama tetap; percobaan pertama saya menjalankan tujuh pembangunan DB paralel dan menghasilkan kegagalan "fixture tidak tersedia" di hampir semua rangkaian. Hasil itu dibuang dan seluruh regresi dijalankan ulang berurutan (`bukti/regresi/`).

## Regresi

| Rangkaian | Hasil | Catatan |
| --- | --- | --- |
| Seluruh 32 suite statis (`bukti/regresi/statis-ringkasan.txt`) | 22 exit 0; 8 exit 1 (7 oracle historis yang sama dengan audit Fase 1 + `v5_phase1_static`); 2 exit 77 | Identik dengan hasil audit Codex Fase 1 di `1852fdb` (`docs/phase-v5-1/bukti/audit-codex/regresi-statis-ringkasan.txt`), kecuali `v5_phase1_static` 44/4 (oracle batas Fase 1, lihat di bawah) dan `v5_phase2_static` baru 63/0. |
| Integrasi fondasi penugasan `tests/penugasan_integration.php` | 161/0 | |
| HTTP pembinaan Tahap 5 | 106/0 | |
| HTTP penilaian/rapor Tahap 7 | 122/0 | |
| HTTP data/penugasan Tahap 6, sesudah 025 lalu 024 di-rollback (tabel kosong) | 132/0 | Cara yang sama dengan Fase 1: oracle H6-A mengasumsikan 023 migrasi terakhir. |
| HTTP Beranda/navigasi Tahap 2 | 188/3 | Hanya oracle peta menu (lihat di bawah). |
| V5 Fase 1 integrasi / probe audit Codex / HTTP | 71/4 · 27/0 · 79/3 | Hanya oracle "pembimbing/murobi ditolak membaca" (lihat di bawah). |
| V5 Fase 1 browser 375/768/1280 + tanpa JS | 35/0 | |
| Browser kerangka (Tahap 1) sembilan akun × 375/768/1280 | 107/0 | `bukti/regresi/browser-kerangka-sembilan-akun.txt` |
| Browser kerangka akun V5 (petugas, admin+petugas, pembimbing, murobi, pembimbing+murobi) | 91/0 | `bukti/regresi/browser-kerangka-akun-v5.txt` |
| V5 Fase 1 migrasi/rollback 024 | 17/9 | Oracle "024 migrasi terakhir" (lihat di bawah). |

## Oracle historis yang kini gagal (tidak diubah, tidak dilonggarkan)

Kegagalan berikut muncul karena Fase 2 **memang** menambah pembaca, alur koreksi, migrasi, dan menu sesuai PRD V5 Fase 2. Perilaku yang dilindungi oracle tetap diuji ulang di rangkaian Fase 2.

| Suite | Pemeriksaan | Sebab dan pengganti |
| --- | --- | --- |
| `v5_phase1_static` 48/0 → 44/4 | 024 migrasi terakhir; "Fase 1 tanpa jalur ubah"; "status Tercatat" tetap; "tanpa tombol ubah" | Migrasi 025, koreksi berversi, alur Draf, dan tombol Koreksi adalah isi Fase 2. Diuji `v5_phase2_static` T-1/T-2/T-4. |
| `v5_phase1_integration` 75/0 → 71/4, `v5_phase1_http` 82/0 → 79/3 | `sbx_pengurus_a` (pembimbing) dan `sbx_murobi_c` (murobi) ditolak membaca | PRD Fase 2 mewajibkan pembaca binaan. Kedua akun kini `baca=true` dengan cakupan binaan; santri V5 Fase 1 berada di luar cakupannya sehingga daftar kosong dan detail 403. Seluruh percobaan tulis mereka tetap 403 (I-5/H-D lulus). |
| `v5_phase1_migration` 26/0 → 17/9 | Rollback terakhir = 024; 18 kunci asing tabel `kesehatan_%` | `bin/migrate.php rollback` kini melepas 025 lebih dahulu; `bin/v5_phase1_verify.php` menghitung 31 baris kunci asing karena tabel 025 juga berawalan `kesehatan_`. Sesudah 025 pakai `bin/v5_phase2_verify.php`. Rantai rollback 025 → 024 pada tabel kosong dijalankan di regresi Tahap 6 (`bukti/regresi/redesain-tahap6-rollback-025-024.txt`). |
| `redesain_web_tahap2_http` 190/1 → 188/3 | Peta menu admin, `sbx_murobi_a`, `sbx_pengurus_a` | Admin 18 menu = keputusan BB (sudah gagal sejak Fase 1). Baru: "Kesehatan" untuk pembimbing (grup Santri binaan) dan murobi (grup Murobi) — dipertahankan oleh keputusan Human Developer BC (Q-1). |

Tujuh kegagalan statis lain (`keamanan_psb`, `penugasan` FS-2/FS-4, `redesain_web_tahap3/4/6/7/8`) identik dengan hasil audit Fase 1; `v3_phase2/3_static` tetap dilewati (exit 77).

## Penafsiran yang perlu ditinjau auditor/Human Developer

Q-1 sudah diputuskan Human Developer; Q-2…Q-11 telah ditinjau auditor untuk batas Fase 2 di [AUDIT-CODEX.md](AUDIT-CODEX.md). Uraian di bawah mempertahankan penafsiran saat handoff implementator.

- **Q-1 Menu Kesehatan untuk pembimbing dan murobi — DIPUTUSKAN Human Developer 24 Oktober 2026 (keputusan BC, `docs/redesain-web/keputusan.md`).** Menu "Kesehatan" di grup "Santri binaan" (pembimbing) dan "Murobi" (murobi), hanya bila penugasan aktifnya ada, **dipertahankan**. Hak server tidak bergantung pada menu. Oracle Tahap 2 untuk menu kedua akun tetap tidak diubah dan dicatat sebagai kegagalan yang diharapkan.
- **Q-2 Cakupan mengikuti penempatan saat ini.** Pembimbing/murobi membaca **seluruh** riwayat kunjungan Tercatat santri yang **sekarang** berada di kamar/kelas binaannya pada tahun ajaran aktif (aturan D-10), termasuk kunjungan sebelum penugasannya. Santri pindah → pembimbing lama kehilangan seluruh riwayat, pembimbing baru memperolehnya. Santri nonaktif/diarsipkan tidak terbaca pembaca binaan (petugas tetap, P-7).
- **Q-3 Hak gabungan, bukan peran aktif sesi.** API tidak mengenal peran aktif, jadi layanan menggabungkan seluruh penugasan binaan akun (`pembimbing_murobi`). Log akses mencatat sumber yang membuka baris tersebut (pembimbing diutamakan bila keduanya).
- **Q-4 Batas baca binaan.** Pembaca binaan membaca versi terbaru beserta obat/rujukan/tindak lanjut, tetapi tidak membaca riwayat versi, alasan koreksi, riwayat akses, draf, atau pemilih santri. "Di luar cakupan" dan "tidak ada" sama-sama 403 (konvensi A-5); petugas/admin tetap 404 untuk ID tidak ada.
- **Q-5 Draf.** Draf boleh dilanjutkan petugas aktif mana pun; alasan tidak wajib selama Draf, tetapi setiap simpanan tetap versi baru. Tercatat → Draf ditolak. Admin melihat draf (pengawasan). Koreksi tanpa `status` mempertahankan status yang ada.
- **Q-6 Satu jalur perubahan.** Setiap perubahan sesudah tersimpan — termasuk hasil rujukan dan status tindak lanjut — menaikkan versi lewat `terapkan()`. "Catat hasil rujukan" hanya formulir/endpoint ringkas untuk jalur yang sama (alasan wajib bila Tercatat). Hasil yang sudah tercatat diubah lewat koreksi penuh.
- **Q-7 Alasan minimal 5 karakter**, sama dengan alasan beralasan Tahap 6. Pesan galat tidak memuat nilai masukan.
- **Q-8 Bentuk rincian.** Obat: nama wajib (≤ 200), jumlah opsional desimal positif (koma/titik, ≤ 2 desimal), satuan dan petunjuk teks bebas tulisan petugas, waktu pemberian bawaan = waktu kunjungan. Batas 20 obat, 5 rujukan, 10 tindak lanjut per kunjungan. Rujukan: status diturunkan dari isi; tanggal rujukan ≥ tanggal kunjungan dan ≤ 30 hari ke depan; tanggal hasil ≥ tanggal rujukan dan tidak di masa depan. Tindak lanjut melekat pada kunjungan (bukan per rujukan); tindak lanjut sesudah hasil rujukan dicatat sebagai baris tindak lanjut kunjungan. Format ini dapat disesuaikan setelah uji klinik nyata.
- **Q-9 Santri kunjungan tidak dapat dikoreksi.** Kunjungan yang salah santri tidak dapat dipindahkan ke santri lain (akan menggeser cakupan pembaca). Penanganannya (mis. pembatalan beralasan) memerlukan keputusan Human Developer.
- **Q-10 Laporan operasional Fase 2** = penyaring + ringkasan angka di daftar web/API. Laporan cetak/ekspor dan agregat tetap Fase 5.
- **Q-11 Audit.** `audit_logs` hanya menerima versi, status, kode bagian (`isi_klinis`, `waktu_kunjungan`, `status`, `obat`, `rujukan`, `tindak_lanjut`), jumlah rincian, operasi, penugasan, sumber, kanal. Alasan dan isi rincian hanya di tabel kesehatan privat.

## Batas yang belum diuji / terbuka

- Tidak ada uji cPanel, produksi, atau MySQL non-MariaDB (lokal MariaDB 12.3.2). Kunci asing gabungan, `CHECK (JSON_VALID(...))`, `JSON_LENGTH`, dan `FOR UPDATE` didukung MySQL 8.0.16+/MariaDB 10.4+, tetapi belum dijalankan di hosting.
- Konkurensi diuji untuk koreksi dari versi sama (6 proses, dua petugas) dan kiriman ganda kunci sama. Koreksi yang bersamaan dengan pencabutan penugasan diuji berurutan (J-10), bukan dengan kunci yang ditahan; deadlock 1205/1213 dipetakan 409 tetapi tidak direproduksi. Ini bukan bukti seluruh interleaving.
- Performa daftar dengan subkueri penanda dan klausa cakupan EXISTS belum diukur pada volume besar (indeks `kunjungan_id, versi_dibatalkan` dan `status, versi_dibatalkan` tersedia).
- Safari fisik, pembaca layar, perangkat iOS/Android, dan aplikasi mobile belum diuji (aplikasi = Fase 4). Browser memakai intersepsi aset CDN lokal seperti runner resmi.
- Ringkasan wali, notifikasi InApp/Push (Fase 3), paritas aplikasi (Fase 4), laporan/rilis (Fase 5) **belum** dikerjakan.

## Langkah rilis (untuk keputusan Human Developer kelak — bukan izin deploy)

1. Backup terverifikasi DB dan berkas; 024 harus sudah terpasang (`bin/v5_phase1_verify.php` lulus sebelum 025).
2. `php bin/v5_phase2_preflight.php /jalur/aman/manifest-025.json`
3. `php bin/migrate.php up` (menerapkan 025).
4. `php bin/v5_phase2_verify.php /jalur/aman/manifest-025.json` — wajib tanpa `[gagal]`.

Rollback hanya bila `php bin/v5_phase2_rollback_preflight.php` lulus (empat tabel 025 kosong); lalu `php bin/migrate.php rollback` (melepas 025 saja) dan `php bin/v5_phase2_verify.php --rollback manifest`. Bila sudah ada rincian kesehatan, pulihkan backup atau buat migrasi korektif — jangan rollback. Sesudah rollback 025 (024 tetap), layanan kesehatan menjawab 503 "belum dipasang" dan menu Kesehatan disembunyikan; pusat penugasan tetap berjalan.

## Handoff ke auditor

> *"Audit implementasi Fase 2 PRD V5 yang dibuat Claude Code pada branch `claude/prd-v5-fase-2`. Baca AGENTS.md, PRD-V5.md, dan docs/phase-v5-2/README.md, periksa seluruh commit fase di atas `1852fdb`, jalankan pengujian secara independen, dan lakukan koreksi terarah jika diperlukan. Commit dan push hasil audit, lalu berhenti. Jangan melanjutkan ke Fase 3 sebelum seluruh kriteria penerimaan Fase 2 terpenuhi."*

Implementator berhenti di sini: tidak merge, tidak deploy, tidak migrasi produksi, tidak memulai Fase 3.
