# Penugasan lintas guru/pengurus — Fase 3: penugasan Keamanan di website

**Status (31 Oktober 2026): diimplementasikan Claude Code, menunggu audit independen Codex.** Belum merge, belum deploy, migrasi 033 belum dijalankan di produksi. Fase 4 belum dimulai.

| Butir | Nilai |
| --- | --- |
| Workstream | Penugasan lintas guru/pengurus dan Keamanan Fase 1–5 — implementator Claude Code, auditor Codex (`AGENTS.md`, keputusan 30 Oktober 2026) |
| Rancangan | [`../rancangan.md`](../rancangan.md) §3.3, PK-5, PK-6, PK-T2, PK-T4 |
| Branch | `claude/penugasan-keamanan-fase-3` |
| Baseline | `6c9d544` (audit Codex Fase 2 = `origin/claude/penugasan-keamanan-fase-2`). `origin/main` `d9f576a` (PR #104) memuatnya; `git diff 6c9d544 origin/main` kosong. Tidak bekerja di `main`, tanpa worktree. |
| Mobile | Tidak diubah. Layar Keamanan aplikasi dan endpoint REST baca-saja = Fase 4 (PK-T3). |
| Migrasi | `033_penugasan_keamanan_fase3_keamanan.sql` + rollback berpasangan berpenjaga data |
| Lingkungan uji | PHP 8.4.14, MariaDB 12.3.2 lokal, Chromium Playwright; DB `test_*_test` dari rantai fixture Fase 1/2 (`tests/penugasan_keamanan_f3_db.sh`, kini memasang 032 dan 033). Produksi MariaDB 10.6.28 belum diuji. Direktori belum terlacak milik pekerjaan lain (`docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/`, `tests/browser/tangkapan-penugasan-keamanan-f1/`) tidak disentuh. |

## Yang dikerjakan

Prinsip: **Keamanan hanya membaca.** Hak dihitung ulang dari penugasan Keamanan aktif milik akun sendiri pada setiap permintaan; tidak ada satu pun jalur tulis yang memeriksa capability Keamanan, dan halaman Keamanan hanya menerima GET/HEAD.

### 1. Jenis penugasan dan migrasi

- `PenugasanJenis::KEAMANAN` (`keamanan`, label "Keamanan"): subjek pengurus (termasuk identitas pengurus milik guru, Fase 1), role dasar `pengurus`, cakupan `CAKUPAN_SELURUH`, `wajib_tahun_aktif = false` (tidak gugur saat semester berganti, PK-5), satu capability `keamanan.pantau`.
- Diurutkan sebelum Panitia/Bendahara PSB, sehingga tidak tampil di kelompok "modul mendatang" pusat penugasan. Data Guru menautkan penugasan Keamanan milik identitas guru.
- Migrasi `033`: satu tabel baru `keamanan_assignments` (bentuk sama dengan `kesehatan_assignments`), kosong, tanpa ALTER/DML tabel lama. Rollback ditolak bila tabel berisi baris apa pun, termasuk penugasan yang sudah nonaktif/berakhir. Kode tetap berjalan sebelum 033 dan sesudah rollback: pusat penugasan menampilkan "belum dipasang", tidak ada capability atau menu.
- `Capabilities` tidak diubah: fondasi generik menghasilkan capability dari tabel baru, termasuk syarat identitas guru aktif (Fase 1).

### 2. Hak baca (`App\Keamanan\KeamananAkses`)

- **Sah** hanya bila `featureSource(keamanan.pantau)` = `penugasan` atau `keduanya`. Pengawasan admin (`sumber = admin`) tidak membuka halaman Keamanan; admin membaca seluruh data dari menunya sendiri, dan peta menu admin tidak berubah.
- Halaman Keamanan memasang **penanda** pada array akun sesudah memeriksa hak. Layanan yang ada (`IzinLaporanService`, `PelanggaranService`, `KonselingService`) membaca penanda itu, **memeriksa ulang** hak, lalu memakai mode baca `keamanan`. Penanda hanya mempersempit ke mode itu; tanpa penugasan sah, layanan menolak 403. Pola sama dengan penanda laporan kehadiran Bagian Pendidikan (keputusan H).
- Parameter request (`peran=keamanan`) tidak pernah memilih cakupan Keamanan.

### 3. Jalur baca

| Bagian | Isi | Cakupan |
| --- | --- | --- |
| Kehadiran pengajian | Laporan seluruh jadwal lewat `admin/_laporan_guard.php` (pola keputusan H): daftar, rincian pertemuan, cetak | Seluruh jadwal. Akun guru yang juga Keamanan membaca seluruh jadwal hanya saat peran aktif Pengurus; sebagai Guru tetap jadwal sendiri |
| Perizinan | `portal/keamanan_izin.php` (daftar + kartu, saringan asal/tahap/hasil/tanggal), `keamanan_izin_detail.php` (alasan, catatan pengurus/wali, keputusan + alasan, koreksi, riwayat status), `keamanan_izin_cetak.php` | Seluruh santri aktif, asal Pengurus (V2) dan Wali (V7), semua tahap. Daftar = `IzinLaporanRepository` cakupan `keamanan` (satu pembangun WHERE dengan laporan V7) |
| Pelanggaran | `keamanan_pelanggaran.php` (chip status, cari, periode), `_detail.php` (uraian, saksi, poin, riwayat revisi, lampiran, tindak lanjut), `_cetak.php`, `keamanan_lampiran.php` | Seluruh santri aktif. Tautan ke kasus Rahasia tidak tampil |
| Konseling | `keamanan_konseling.php` (chip status, cari), `_detail.php` (tujuan, sesi, hasil, tindak lanjut, revisi, tautan pelanggaran), `_cetak.php` | Seluruh santri aktif, **hanya kasus Internal**: penyaring kerahasiaan sama dengan murobi (`kerahasiaan <> 'Rahasia'`) pada daftar, angka chip, pencarian, rincian, dan tautan |

Tidak ada tombol ajukan, tinjau, putus, koreksi, batal, catat, publikasi, atau tandai mengetahui. Kasus Rahasia, ID yang tidak ada, dan data santri nonaktif sama-sama menghasilkan 404 pada halaman Keamanan.

### 4. Cetak tanpa CSV (PK-T2)

- Cetak = halaman HTML ramah cetak dengan tombol "Cetak / Simpan PDF" (pola cetak sistem): laporan izin (seluruh baris saringan, paling banyak 5.000), rincian pelanggaran, rincian kasus, dan laporan kehadiran yang sudah ada.
- CSV ditolak di server, bukan hanya disembunyikan: `export_laporan_absensi.php` → 403 bagi Keamanan (Bagian Pendidikan/admin/guru tetap dapat), `IzinLaporanService::ekspor()` dengan cakupan Keamanan → 403.

### 5. Audit akses

- Setiap pembukaan rincian izin/pelanggaran/kasus, setiap unduhan lampiran, dan setiap cetak laporan izin dicatat di `audit_logs` (`keamanan.izin.dilihat`, `keamanan.izin.dicetak`, `keamanan.pelanggaran.dilihat`, `keamanan.pelanggaran.lampiran.diunduh`, `keamanan.konseling.kasus.dilihat`).
- Isi jejak hanya ID, asal, status, saringan, dan jumlah. `AuditLogger` menyaring setiap aksi `keamanan.*` ke daftar kunci metadata, sehingga alasan, uraian, atau isi konseling tidak pernah masuk log walau pemanggil keliru.
- Bila audit gagal disimpan, rincian tidak dikembalikan (503).

### 6. Menu

Grup **Keamanan** pada peran Pengurus: Kehadiran pengajian · Perizinan santri · Pembinaan santri (tab Pelanggaran/Konseling). Bilah bawah HP memakai ikon `fa-user-shield` dan label pendek "Keamanan" agar tidak tertukar dengan "Perizinan" pengurus. Peta menu admin, guru, Bagian Pendidikan, dan orang tua tidak berubah.

### Berkas

| Lapisan | Berkas |
| --- | --- |
| Penugasan | `app/Penugasan/PenugasanJenis.php`, `PenugasanService.php` (pemeriksaan tabel), `PenugasanRepository.php` (komentar), `admin/admin_guru.php` |
| Keamanan (baru) | `app/Keamanan/KeamananAkses.php`, `KeamananService.php`, `KeamananException.php`, `portal/_keamanan.php`, sepuluh `portal/keamanan_*.php` |
| Layanan baca | `app/Izin/IzinLaporanService.php`, `IzinLaporanRepository.php`, `app/V3/PelanggaranService.php`, `PelanggaranRepository.php`, `KonselingService.php`, `KonselingRepository.php` |
| Lain | `app/Audit/AuditLogger.php`, `app/Ui/Navigation.php`, `app/bootstrap.php`, `admin/_laporan_guard.php`, `admin/admin_laporan_absensi.php` |
| Basis data | `database/migrations/033_…`, `database/rollbacks/033_…` |
| Tidak diubah | `app/Auth`, akun/master, kesehatan, BSA, notifikasi, V4, REST `api/v1`, alur tulis izin (`IzinWorkflowService`, `IzinWaliService`, `IzinRepository`, `IzinRouter`, `PemisahanTugas`), mobile |

## Penafsiran implementator (menunggu tinjauan auditor)

- **PF3-1** Admin tidak membuka halaman Keamanan lewat hak pengawasan; halaman Keamanan hanya untuk penugasan milik sendiri. Admin yang juga memegang penugasan Keamanan (sumber `keduanya`) dapat membukanya sebagai Keamanan.
- **PF3-2** Jalur baca memakai layanan yang sudah diaudit dengan mode baca baru, bukan lapisan data kedua. Halaman pembinaan/perizinan biasa tidak berubah dan tetap menolak akun Keamanan yang tidak punya hak lain.
- **PF3-3** "Seluruh santri aktif" dibaca pada saat permintaan: santri `is_active = 1` dan belum diarsipkan. Riwayat izin/pembinaan santri yang sudah nonaktif tidak terbaca. Izin dan pembinaan semua tahun ajaran santri aktif ikut terbaca (riwayat).
- **PF3-4** Daftar izin, seperti laporan V7, tanpa alasan. Isi lengkap (PK-T4) ada di rincian, yang pembukaannya diaudit. Rincian memuat juga pengajuan wali yang masih di tahap pembimbing.
- **PF3-5** Pelanggaran yang ditindaklanjuti kasus Rahasia tetap terbaca (pelanggaran tidak berkerahasiaan); yang tertutup hanya kasus dan tautannya. Sama dengan aturan murobi.
- **PF3-6** "Cetak PDF" = halaman cetak HTML lewat dialog cetak peramban, sesuai pola sistem. Laporan agregat pembinaan (`v3_laporan.php`) tidak dibuka untuk Keamanan pada fase ini; angka ringkasan pembinaan tersedia lewat chip status daftar.
- **PF3-7** Daftar tidak diaudit per pembukaan (sama dengan modul lain); rincian, unduhan, dan cetak diaudit.
- **PF3-8** Lampiran pelanggaran termasuk "catatan pelanggaran lengkap" (PK-T4), sehingga dapat diunduh dengan audit.
- **PF3-9** Rollback 033 ditolak bila ada baris apa pun, termasuk penugasan nonaktif, agar riwayat siapa yang pernah memegang hak baca tidak hilang.

## Hasil pengujian implementator (sebelum audit, lokal)

| Rangkaian | Hasil | Berkas |
| --- | --- | --- |
| Statis `tests/penugasan_keamanan_f3_static.php` S-1…S-9: katalog, capability tidak dipakai jalur tulis (pemindaian kode tanpa komentar), sepuluh halaman tanpa POST/CSRF/CSV/mutasi, mode baca dan penyaring kerahasiaan, audit, kehadiran, migrasi aditif, batas berkas terhadap baseline, lint | **73/0** | `bukti/statis.txt` |
| Integrasi `tests/penugasan_keamanan_f3_integration.php` I-1…I-10: hak K/G/N/admin, daftar izin = oracle SQL santri aktif (1.040), rincian lengkap + audit tanpa isi + audit gagal → 503, pelanggaran/konseling tanpa Rahasia di daftar/angka/pencarian/rincian/tautan, lampiran, penolakan seluruh jalur tulis, penanda tanpa hak, pencabutan penugasan dan guru nonaktif, pusat penugasan | **44/0** | `bukti/integrasi.txt` |
| HTTP web `tests/penugasan_keamanan_f3_http.php` H-1…H-9 (server PHP lokal, skenario `penugasan_keamanan_f3_seed.php`): menu, daftar/rincian/cetak, 404 santri nonaktif dan Rahasia, POST → 405, CSV → 403 (kontrol: Pendidikan tetap CSV), guru+Keamanan per peran, halaman biasa tidak melebar, pusat penugasan, audit, log server | **44/0** | `bukti/http.txt` |
| Migrasi `tests/penugasan_keamanan_f3_migration.php` M-0…M-6: rollback saat kosong, kode sebelum 033, naik (sidik tabel lain sama), jalankan ulang, FK/CHECK, rollback ditolak sesudah ada penugasan nonaktif | **19/0** | `bukti/migrasi.txt` |
| Browser `tests/browser/uji-penugasan-keamanan-f3.mjs` 375/768/1280 + tanpa JavaScript 375: menu, daftar/rincian, tanpa formulir POST, tanpa geser horizontal, saringan GET tanpa JS, guru berpindah ke peran Pengurus; log server 0 galat PHP; lima tangkapan diperiksa visual, tidak di-commit | **56/0** | `bukti/browser.txt`, `bukti/browser-log.txt` |
| Ikon navigasi FA 6.0.0 (`tests/navigasi_ikon_static.php`) | lulus (37) | `bukti/navigasi-ikon.txt` |

Perintah:

```bash
php tests/penugasan_keamanan_f3_static.php
bash tests/penugasan_keamanan_f3_db.sh && php tests/penugasan_keamanan_f3_integration.php
PKF3_DB=test_pk_f3_http_test bash tests/penugasan_keamanan_f3_db.sh && PKF3_DB=test_pk_f3_http_test php tests/penugasan_keamanan_f3_http.php
PKF3_DB=test_pk_f3_mig_test bash tests/penugasan_keamanan_f3_db.sh && PKF3_DB=test_pk_f3_mig_test php tests/penugasan_keamanan_f3_migration.php
PKF3_DB=test_pk_f3_browser_test bash tests/penugasan_keamanan_f3_db.sh
PKF3_DB=test_pk_f3_browser_test php tests/penugasan_keamanan_f3_seed.php > /tmp/pkf3-ids.json
DB_NAME=test_pk_f3_browser_test php -S 127.0.0.1:8983 tests/redesain_web_tahap1_router.php &
(cd tests/browser && BASE_URL=http://127.0.0.1:8983 SEED_JSON=/tmp/pkf3-ids.json node uji-penugasan-keamanan-f3.mjs)
bash docs/penugasan-keamanan/fase-3/perintah-regresi.sh
```

Seed menulis satu lampiran sintetis `storage/private/v3/pkf3-*.pdf` (diabaikan Git); uji integrasi/HTTP menghapusnya saat selesai, dan berkas dari seed browser dihapus manual.

Temuan tampilan saat implementasi: tangkapan 375px menunjukkan bilah bawah memuat "Perizinan" (pengurus) dan "Izin" (Keamanan) dengan ikon pintu yang sama. Butir Keamanan diganti ikon `fa-user-shield` dan label pendek "Keamanan"; percobaan pertama `fa-shield-halved` ditolak uji ikon (tidak ada di FA 6.0.0), lalu diganti.

### Kontrol pada kode baseline `6c9d544`

Uji baru dijalankan pada klon `6c9d544` (`.env`, symlink `vendor/` dan `storage/private/v4`) untuk membuktikan uji mendeteksi ketiadaan fitur:

- Statis **9/25**.
- Integrasi dan HTTP berhenti pada penyiapan (0 assertion): tabel `keamanan_assignments` dan jenis Keamanan tidak ada.

Berkas: `bukti/kontrol-baseline/f3_*`.

## Regresi

`perintah-regresi.sh` (turunan skrip Fase 2, ditambah penugasan Fase 2 dan HTTP Tahap 6) dijalankan dua kali berurutan dengan suite, urutan, dan fixture yang sama: **kode kini** (`bukti/regresi/`) dan **klon baseline `6c9d544`** (`bukti/kontrol-baseline/regresi/`). Perbandingan per suite: `bukti/perbandingan-regresi.txt`.

**Seluruh suite non-statis menghasilkan exit dan hitungan identik dengan baseline.**

| Kelompok | Kini = baseline |
| --- | --- |
| Penugasan Fase 1: integrasi / probe audit / probe token / HTTP | 70/0 · 20/0 · 10/0 · 34/0 |
| Penugasan Fase 2: integrasi / HTTP / probe audit Codex / probe V4 Codex | 53/0 · 28/0 · 24/0 · 9/0 |
| V7 Fase 5: E2E / HTTP / probe cakupan / probe pencabutan | 82/1 · 40/0 · 6/0 · 24/0 |
| V7 Fase 4 HTTP; Fase 3 integrasi / notifikasi / HTTP (laporan lintas asal) | 54/0; 112/0 · 49/0 · 43/0 |
| V7 Fase 2 integrasi / HTTP / probe audit; Fase 1 integrasi / HTTP | 119/0 · 61/0 · 34/0; 73/2 · 71/0 |
| Fondasi penugasan; V2 integrasi Fase 1/2/4/5; konkurensi | 161/0; 39/0 · 94/0 · 124/0 · 145/0; 20/0 |
| V2 API Fase 3/4/5; web Fase 2/4/5 | 95/21 · 91/1 · 150/0; 36/0 · 46/0 · 79/0 |
| V3 integrasi Fase 2/3/4/5; akses Fase 5 | 67/0 · 121/0 · 65/0 · 43/0; 5/0 |
| HTTP redesain Tahap 2 / 4 (kehadiran, cetak) / 5 (pembinaan) / 6 (data pesantren, penugasan) / 7 | 185/6 · 132/0 · 106/0 · 125/7 · 122/0 |

### Kegagalan yang identik dengan baseline (oracle historis)

Tidak dilonggarkan: V7 Fase 5 E2E 82/1 (preflight V7 menolak migrasi sesudah 031), V7 Fase 1 73/2, V2 API Fase 3/4 95/21 · 91/1, Tahap 2 HTTP 185/6 (peta menu lama BB–BE), Tahap 6 HTTP 125/7 (rollback 023 mengasumsikan migrasi terakhir) — sama dengan catatan Fase 2.

### Kesalahan penyiapan yang diulang

- Dua probe audit Codex Fase 2 pertama kali dijalankan skrip regresi pada DB baru tanpa integrasi Fase 2 dan tanpa arsip `464d9a1` (`PKF2_BASELINE`), sehingga keduanya berhenti pada penyiapan di kedua sisi (`bukti/*/diagnostik-pk-fase2-probe-*-penyiapan-salah.txt`). Diulang sesudah integrasi Fase 2 pada DB yang sama: probe V4 **9/0** kini = baseline. Baris `pk-fase2-probe-*` pada `ringkasan.txt` masih merekam percobaan salah itu; berkas individualnya memuat hasil ulang.
- Probe audit Fase 2 (riwayat/replay) diulang pukul 00.13 WIB dan berhenti di **kedua sisi** pada "Penugasan pembimbing tidak berlaku pada tanggal kejadian": probe membuat penugasan bermula hari ini lalu mencatat pelanggaran satu jam sebelumnya, yang jatuh pada hari kemarin di antara 00.00–01.00. Layanan benar menolak; ini artefak jam fixture probe (`bukti/*/diagnostik-pk-fase2-probe-audit-jendela-tengah-malam.txt`). Diulang pukul 01.02 WIB sesudah integrasi Fase 2 pada DB yang sama: **24/0** kini = baseline (`bukti/*/regresi/pk-fase2-probe-audit.txt`).

### Statis (48 suite kini, 47 baseline)

Kini **18 exit 0, 28 exit 1, 2 exit 77** (termasuk suite baru Fase 3 73/0). Perbedaan terhadap baseline seluruhnya dijelaskan berikut; oracle tidak diubah.

1. **Berubah karena Fase 3 sengaja menambah migrasi/jenis atau mengubah berkas yang dikunci oracle lama:**
   - Penugasan Fase 1 80/2 → **104/4**: "032 adalah migrasi terakhir", "Fase 1 tidak menambah jenis penugasan (Keamanan adalah Fase 3)", batas berkas Fase 1; bertambah lint berkas baru.
   - Penugasan Fase 2 57/1 → **86/2**: batas berkas Fase 2 dan "Penugasan tidak diubah".
   - V6 Fase 5 65/9 → **64/10** dan perbaikan tagihan satu santri 20/4 → **19/5**: "`app/Audit/` tidak berubah".
   - Ikon navigasi 36 → **37** pemeriksaan (ikon baru), tetap lulus.
2. **Berbeda karena lingkungan klon, bukan kode:** klon di folder sementara tidak memiliki repo mobile tetangga dan memuat symlink `vendor/`/`storage/private/v4`, sehingga `phase5_static`, V2 Fase 3/4/5, dan batas berkas Fase 2 berbeda (sama dengan catatan Fase 2). Di pohon kerja, V2 Fase 4 287/1 dan Fase 5 339/3 gagal pada versi paket mobile yang belum di-commit milik pekerjaan lain.
3. **Selebihnya identik.** Dua suite V3 keluar 77 (dilewati), sama dengan baseline.

### Uji migrasi Fase 1 (di luar skrip regresi)

`tests/penugasan_keamanan_f1_migration.php` mengasumsikan 032 migrasi terakhir dan menghitung sidik **seluruh** tabel jenis penugasan:

- dengan penyiapan resminya (`PKF1_TANPA_032=1`) DB masih memuat 032 karena 033 kini terakhir: **7/9** (`bukti/regresi/pk-fase1-migrasi-oracle-historis.txt`);
- dengan 033 dan 032 dilepas manual: berhenti sesudah 6/0 karena sidik membaca tabel Keamanan yang belum ada (`bukti/regresi/pk-fase1-migrasi-tanpa-032-033.txt`).

Ini oracle historis akibat penambahan sah 033; uji migrasi Fase 3 menggantikan cakupannya untuk 033.

## Batas

- **MariaDB 10.6.28 (produksi), MySQL/cPanel, dan UAT belum diuji.** Migrasi 033 belum dijalankan di produksi.
- **Mobile dan REST tidak diubah.** Aplikasi tidak menampilkan Keamanan sampai Fase 4.
- Cetak memakai pemecahan halaman bawaan peramban (tanpa nomor halaman hitungan server seperti laporan izin V2).
- Fase 3 bukan izin rilis: keputusan merge, deploy, dan migrasi produksi 032/033 milik Human Developer; gerbang produksi V5/V6/V7 yang terbuka tidak berubah.

## Handoff

Implementasi Fase 3 selesai pada `claude/penugasan-keamanan-fase-3` dari baseline `6c9d544`. Untuk auditor Codex:

- Fokus pemeriksaan: (1) penanda `KeamananAkses` tidak dapat dipakai untuk memperluas hak di jalur mana pun (layanan selalu memeriksa ulang `sah()`); (2) kasus Rahasia tidak terbaca lewat daftar, angka chip, pencarian, rincian, tautan pelanggaran, cetak, atau lampiran; (3) tidak ada jalur tulis/CSV yang terbuka bagi Keamanan; (4) jejak audit tanpa isi; (5) penafsiran PF3-1…PF3-9.
- Kontrol baseline dan regresi: `bukti/kontrol-baseline/`, `bukti/perbandingan-regresi.txt`.
- Tidak merge, deploy, migrasi produksi, rilis aplikasi, atau memulai Fase 4.
