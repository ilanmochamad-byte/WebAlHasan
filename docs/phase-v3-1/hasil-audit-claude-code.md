# Hasil audit Claude Code — PRD V3 Fase 1

Auditor: Claude Code (peran auditor V3 sesuai `AGENTS.md`). Tanggal audit: 9 September 2026.
Branch `prd-v3-fase-1`. Rentang commit yang diperiksa: `14548541000e0df523343d82b28650061364bb65..a7658bb` (5 commit implementator) ditambah satu commit koreksi audit ini.

Audit dijalankan secara independen: seluruh suite dieksekusi ulang oleh auditor, bukan dibaca dari `bukti/`. Angka assertion tidak dipakai sebagai pengganti penerimaan produk — setiap kriteria diuji ulang terhadap perilaku yang dapat diamati.

## 1. Lingkungan audit

| Komponen | Nilai |
| --- | --- |
| PHP | 8.4.14 (CLI) |
| MariaDB | 12.3.2 lokal, `sql_mode=STRICT_TRANS_TABLES,...` |
| DB uji V3 | `webalhasan_v3_phase1_test` (terpisah, fixture `sbx_*` fiktif) |
| DB regresi | `webalhasan_phase4_codex_20260823_test` |
| DB probe portabilitas | `test_v3_portabilitas` (dibuat dan dihapus dalam sesi ini) |
| Browser | Chromium (Playwright 1.62.1), 1440/768/375 px |
| Server uji | `php -S 127.0.0.1:8940` dengan router audit sementara di scratchpad (meniru rewrite `.htaccess`); tidak ditambahkan ke repositori |

Tidak ada koneksi, dump, credential, atau deployment produksi. Tidak ada merge ke `main`. Tidak ada pekerjaan Fase 2.

Urutan dijalankan sesuai instruksi: **drill migrasi lebih dulu**, baru suite paket dan browser.

## 2. Reproduksi bukti implementator

Seluruh angka yang diklaim `test-results.md` tereproduksi persis oleh auditor pada eksekusi independen:

| Suite | Klaim implementator | Hasil audit | Cocok |
| --- | --- | --- | --- |
| Statis V3 | 15 | 15, exit 0 | Ya |
| Integrasi V3 | 45 | 45, exit 0 | Ya |
| Konkurensi V3 | 3 | 3, exit 0 | Ya |
| Diagnostik V3 | 3 | 3, exit 0 | Ya |
| Drill migrasi 013 | 7 | 7, exit 0 | Ya |
| Pre-check | 24, exit 0 | 24, exit 0 | Ya |
| Post-check | 273, exit 0 | 273, exit 0 | Ya |
| Browser + API HTTP | 74 | 74, exit 0 | Ya |
| Regresi utama | 49 suite, 4.010 pemeriksaan | 49 suite, **4.011** pemeriksaan, exit 0, tanpa skip | Ya (selisih 1, lihat catatan) |

Catatan selisih: hitungan regresi bergantung jumlah baris fixture pada saat eksekusi, sehingga satu pemeriksaan lebih/kurang antar-jalan adalah wajar dan bukan indikasi regresi. Nol suite gagal dan nol dilewati pada kedua jalan.

## 3. Verifikasi khusus yang diminta

### 3.1 Keamanan halaman data warisan dan keputusan §5.2a — LULUS

Diuji lewat HTTP nyata sebagai lima peran fixture:

- `sbx_admin` → 200; `sbx_pengurus_a`, `sbx_murobi_a`, `sbx_ortu_a`, `sbx_guru_biasa` → **403**. Halaman warisan admin-only.
- POST/PUT/PATCH/DELETE → **405** semuanya, dengan header `Allow: GET`.
- `?hapus=1`, `?hapus=`, `?hapus=99999`, `?id=1&hapus=1` → **405** semuanya. Hapus lewat GET benar-benar ditutup.
- Label **Data warisan** tampil pada judul dan badan halaman.
- Jumlah baris tabel `pelanggaran` tidak berubah setelah seluruh percobaan mutasi.

Peningkatan keamanan nyata dibanding baseline: halaman lama menginterpolasi input ke SQL, mengeluarkan `jenis_pelanggaran` tanpa escape, dan menghapus lewat GET. Versi Fase 1 memakai prepared statement melalui `KatalogService::legacy()` dan meng-escape seluruh sel dengan `ah_e()`. `LEFT JOIN santri` mempertahankan baris yatim dan menampilkannya sebagai `Referensi santri tidak tersedia`, sesuai janji "sistem tidak mengarang identitas".

### 3.2 Peta capability, relasi wali, penempatan nyata, akun aktif — LULUS

Diuji langsung terhadap resolver, bukan lewat UI:

- Admin tanpa penugasan memperoleh `v3.katalog.kelola`/`v3.ambang.kelola`/`v3.pengawasan`/`v3.koreksi`, dan **tidak** memperoleh `v3.pelanggaran.kelola` maupun `v3.murobi.mengetahui`. Admin tidak menyamar menjadi operator berpenugasan.
- Guru tanpa penugasan murobi → **nol** capability V3.
- Orang tua → hanya `v3.publikasi.baca`.
- Pembimbing → capability operasional tanpa capability admin.
- Pengakhiran `pembimbing_assignments`/`murobi_assignments` dan pengarsipan `santri_wali` menghilangkan capability pada pemeriksaan berikutnya, tanpa menghapus akun.
- `v3AppliesToSantri` memakai penempatan nyata (`plotting_kelas` + `plotting_kamar`) dan pencocokan cakupan fondasi; cakupan santri A vs B terbukti terpisah pada tiga peran.

Diperiksa dan dinyatakan benar: asimetri filter status antara `plotting_kelas` (memfilter `status='Aktif'`) dan `plotting_kamar` (tanpa filter) **bukan** cacat — `plotting_kamar` warisan V1 memang tidak punya kolom status, konsisten dengan `AlumniRepository`.

### 3.3 Serialisasi overlap, perubahan tahun/masa berlaku, optimistis, gagal audit, timeout/deadlock — LULUS

Skenario auditor sendiri (di luar suite implementator):

- Rentang poin sama di **tahun ajaran berbeda** diterima; memindahkan ambang ke tahun yang bentrok ditolak **409** dan nilai lama tidak berubah.
- Rentang poin sama dengan **masa berlaku terpisah** diterima; memperpanjang `tanggal_selesai` hingga bertabrakan ditolak **409**; perpanjangan yang tidak bertabrakan diterima. Pemeriksaan overlap benar-benar memperhitungkan interval poin **dan** interval tanggal.
- Optimistic version: penyimpanan dengan versi lama ditolak **409**; `version` naik tepat satu per mutasi.
- Gagal audit: trigger `SIGNAL` pada `audit_logs` membuat transaksi bisnis di-rollback, tanpa baris baru tertinggal.
- Konkurensi nyata: dua proses bersamaan → satu tersimpan, satu **409**, nol duplikasi; lock timeout dan deadlock dua koneksi dipetakan ke konflik aman tanpa baris baru.

Serialisasi memakai penguncian satu baris `schema_migrations` (`FOR UPDATE`). Ini benar untuk volume konfigurasi rendah; risiko yang sudah dicatat implementator (butir 2) tetap berlaku dan wajib dievaluasi ulang saat alur operasional Fase 2 ditulis.

### 3.4 Serializer API baca, pagination, kompatibilitas lama — LULUS

Diperiksa pada respons HTTP sungguhan, bukan pada kode saja:

- `/api/v1/v3/katalog` mengembalikan tepat `id,kode,nama,kategori_id,kategori_nama,tingkat,poin_default,uraian,tanggal_mulai,tanggal_selesai`. **Nol** field internal (`alasan_status`, `created_by`, `updated_by`, `version`, `is_active`, `archived_at`, `created_at`, `updated_at`, `status_efektif`).
- Envelope `{rows,total,page,per_page}`; `per_page` tetap 25; halaman 2 tidak mengulang baris halaman 1 (diuji dengan 27 baris).
- Tanpa token → 401. POST/PUT/PATCH/DELETE pada endpoint baca V3 → **404** (benar-benar baca-saja).
- Orang tua dan guru tanpa murobi → 403 untuk katalog dan ambang. Pembimbing ditolak 403 untuk tahun ajaran di luar cakupan.
- Kompatibilitas lama: kelima role fixture tetap dapat login API; `/profile` tetap memuat `capabilities`, `feature_capabilities`, `roles`, `guru`; `default_mode` tetap berada di dalam struktur `capabilities` lama dan tidak diubah. Menu aplikasi dibangun `ApiAuthService::menus()` yang **tidak** menyentuh `App\Ui\Navigation`; item menu web `v3.katalog` karena itu tidak bocor ke kontrak menu mobile.

### 3.5 Kesiapan struktur fase berikutnya tanpa endpoint operasional — LULUS

Kedua belas tabel V3 terpasang; sembilan tabel operasional (`v3_pelanggaran`, `v3_konseling_*`, `v3_publikasi`, `v3_lampiran`, `v3_poin_ledger`, `v3_murobi_catatan`, `v3_idempotency`) ada dan **kosong**. Tidak ada rute `/v3/pelanggaran|konseling|publikasi|lampiran` di API; percobaan POST menghasilkan 404. `operasional_tersedia=false` dikembalikan untuk kelima peran.

### 3.6 Migrasi/rollback, preservasi data lama, kompatibilitas versi hosting — LULUS DENGAN BATAS

- Drill dijalankan lebih dulu: runner ulang idempoten, rollback 013 melepas seluruh tabel V3, pemasangan ulang berhasil, runner ulang idempoten lagi.
- Preservasi data lama terbukti kuat: hash isi **seluruh** tabel non-V3 identik sebelum/sesudah rollback dan sesudah pemasangan ulang, termasuk satu baris `pelanggaran` warisan fiktif yang sengaja dibuat sebelum manifest.
- Migrasi bersifat menambah saja; tidak ada `DROP`/`DELETE`/`TRUNCATE`/`ALTER`/`INSERT` pada 013, dan migrasi 001–012 tidak disentuh.
- **Probe portabilitas auditor:** seluruh 12 tabel V3 terpasang bersih pada database kosong di bawah `sql_mode` ketat ala MySQL 8 (`STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,ONLY_FULL_GROUP_BY,NO_ENGINE_SUBSTITUTION`) tanpa error maupun warning. Konstruksi yang dipakai (CHECK, kolom `GENERATED ... STORED`, `utf8mb4_unicode_ci`, ENUM) semuanya didukung MariaDB 10.2+ dan MySQL 8.
- **Batas yang tetap berlaku:** probe di atas tetap berjalan pada mesin MariaDB 12.3.2. Ia mempersempit risiko sintaksis/sql_mode, tetapi **bukan** pengganti uji pada MariaDB cPanel 10.6.27 atau MySQL 8 sungguhan. Status `MEMERLUKAN UJI MYSQL` tidak dicabut.

### 3.7 Responsivitas 375 px dan pengakhiran/nonaktif katalog dengan audit — LULUS

- 1440/768/375 px × tiga tab: HTTP 200, tanpa scroll horizontal pada formulir utama, setiap kontrol formulir punya label terkait. Tangkapan layar 375 px ditinjau langsung oleh auditor: formulir satu kolom, label terbaca, tabel dibungkus kontainer responsif.
- Pengakhiran katalog lewat web tersimpan dan terbaca kembali; alasan pengakhiran tampil pada panel riwayat audit.
- Pada tingkat service: pengakhiran menulis **tepat satu** entri audit, memuat nilai sebelum (`"tanggal_selesai":null`) dan sesudah, tanpa credential; `version` naik satu; katalog nonaktif hilang dari API baca aktif tetapi barisnya tetap ada (tanpa hard delete); perubahan data lama tanpa alasan ditolak 422.

### 3.8 Regresi V1/V2 sesudah migrasi V3 — LULUS (celah bukti ditutup auditor)

Bukti implementator menjalankan regresi pada database yang **belum** dipasangi 013, sehingga kriteria "regresi lulus setelah migrasi V3" belum benar-benar dibuktikan. Auditor menutup celah ini:

| Jalan | Suite | Pemeriksaan | Gagal | Dilewati |
| --- | --- | --- | --- | --- |
| Sebelum 013 | 49 | 4.011 | 0 | 0 |
| **Sesudah 013 diterapkan** | 49 | 4.011 | 0 | 0 |

Hasil identik. Migrasi V3 tidak menimbulkan regresi pada login, profil, mode, jadwal, absensi, laporan, dan perizinan V1/V2.

## 4. Temuan audit dan koreksi terarah

### T-1 (sedang, dikoreksi) — post-check mencampur temuan warisan dengan blocker V3

`bin/v3_verify.php` memindai **seluruh** foreign key database dan melaporkan setiap referensi yatim sebagai kegagalan V3. Pada database yang pernah menjalankan regresi V1/V2, suite lama mematikan `FOREIGN_KEY_CHECKS` saat membersihkan akun fixture dan meninggalkan baris yatim di `notifikasi_outbox` dan `audit_logs`.

Bukti bahwa ini **bukan** akibat V3: pada DB regresi, baris yatim tertua bertanggal 2026-08-23 dan terbaru 2026-09-09 06:08:51, sedangkan migrasi 013 baru diterapkan 06:16:13. Seluruh baris yatim mendahului migrasi.

Dampak nyata: operator cPanel yang menjalankan post-check pada salinan produksi yang memiliki yatim warisan akan memperoleh `BLOCKER` yang seolah-olah menuduh migrasi 013, dan berisiko "memperbaiki" dengan menghapus catatan bisnis lama — melanggar PRD §5.2 ("catatan bisnis tidak dihapus permanen").

**Koreksi yang diterapkan** (`bin/v3_verify.php`): yatim pada tabel `v3_*` tetap blocker; yatim pada tabel warisan dilaporkan terpisah dengan penanda `[warisan]` beserta jumlah barisnya dan peringatan eksplisit agar tidak menghapus catatan lama. **Exit code tidak dilonggarkan** — keduanya tetap menghasilkan exit nonzero.

Verifikasi setelah koreksi:

- DB V3 bersih: 273 pemeriksaan, 0 gagal, exit **0** (identik sebelum koreksi).
- DB regresi dengan yatim warisan: exit **1**, pesan menyebut 3 referensi yatim warisan dan menyatakan struktur V3 lulus.
- `tests/v3_phase1_diagnostics.php` tetap lulus 3/3, termasuk "fixture rusak exit nonzero".
- Seluruh paket V3 dijalankan ulang setelah koreksi: 66 pemeriksaan, 0 gagal, exit 0.

### T-2 (rendah, tidak dikoreksi) — `v3Capabilities` membuang pembedaan sumber `keduanya`

`Capabilities::featureCapabilities()` menandai admin yang **juga** memegang penugasan nyata dengan `sumber = keduanya`, dan docblock fondasi mewajibkan modul masa depan membedakan pelaku admin dari pelaku operasional lewat `featureSource()`. `v3Capabilities()` menuliskan `sumber = penugasan` secara harfiah untuk setiap capability operasional, sehingga admin-merangkap-pembimbing tercatat sebagai murni `penugasan`.

Tidak berdampak pada Fase 1 (tidak ada mutasi operasional). Menjadi relevan pada Fase 2–3 ketika koreksi admin wajib beralasan dan dibedakan dari tindakan pembimbing. Tidak dikoreksi karena berada di luar cakupan Fase 1 dan perbaikannya sebaiknya menyertai kode yang benar-benar memakainya. **Harus ditangani sebelum mutasi operasional Fase 2 ditulis.**

### T-3 (informasi) — santri tanpa penempatan tidak pernah masuk cakupan

`v3AppliesToSantri` memerlukan sedikitnya satu penempatan kelas atau kamar aktif pada tahun ajaran tersebut. Santri tanpa penempatan ditolak walaupun pembimbing memegang cakupan setahun penuh. Ini perilaku konservatif yang aman dan konsisten dengan PRD §5.3, dicatat agar Fase 2 tidak menganggapnya bug.

## 5. Kriteria penerimaan Fase 1

| Kriteria PRD | Status audit |
| --- | --- |
| Migrasi lulus dan post-check tanpa FK yatim/kehilangan baris lama | LULUS pada MariaDB lokal; **belum** pada salinan produksi representatif |
| Admin membuat kategori, jenis, ambang lewat web lalu membaca nilai sama | LULUS — service dan browser |
| Kode duplikat dan rentang ambang tumpang tindih ditolak tanpa menambah baris | LULUS — termasuk skenario pindah tahun dan perpanjangan masa berlaku |
| Pengurus/guru/orang tua tanpa penugasan-relasi aktif memperoleh 403 atau hasil kosong | LULUS |
| Penugasan aktif menghasilkan capability benar; hilang setelah tanggal selesai tanpa menghapus akun | LULUS |
| Seluruh mutasi katalog punya satu entri audit dengan pelaku dan nilai sebelum/sesudah, tanpa credential | LULUS |
| Regresi V1/V2 lulus | LULUS — dan dibuktikan juga **sesudah** migrasi 013 |
| Halaman katalog dapat dipakai pada 375 px tanpa scroll horizontal formulir | LULUS |

## 6. Yang tetap terbuka (bukan kegagalan, tetapi belum dibuktikan)

1. ~~Uji pada salinan produksi representatif~~ **DITUTUP 10 September 2026.** Migrasi 013 dijalankan pada MariaDB hosting cPanel; `bin/v3_verify.php` menghasilkan 273 pemeriksaan LULUS tanpa blocker. Dijalankan langsung pada basis data produksi, bukan pada salinan uji. Temuan T-1 sekaligus terkonfirmasi: produksi tidak memiliki referensi yatim, sehingga yatim yang ditemukan auditor memang residu fixture lokal.
2. **Smoke test Safari/iOS, perangkat fisik Android/iOS, dan cPanel.** Bukti browser memakai Chromium headless dengan aset lokal dan request eksternal diblokir.
3. **T-2** wajib ditangani sebelum mutasi operasional Fase 2.
4. Migrasi 013 kini **terpasang** pada DB regresi lokal `webalhasan_phase4_codex_20260823_test` (diterapkan auditor untuk membuktikan §3.8). Ini database uji lokal, bukan produksi.

## 7. Rekomendasi

Fase 1 memenuhi seluruh kriteria penerimaan PRD yang dapat dibuktikan pada lingkungan lokal, dengan satu koreksi terarah yang sudah diterapkan dan diuji ulang. Auditor **merekomendasikan penerimaan Fase 1 secara bersyarat**: syaratnya adalah butir 1 dan 2 pada bagian 6 dijalankan oleh Human Developer/operator sebelum merge ke `main` atau deployment.

Auditor tidak melakukan merge ke `main`, tidak melakukan deployment, dan tidak mengerjakan Fase 2. Keputusan penerimaan berada pada Human Developer.
