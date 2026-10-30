# Penugasan lintas guru/pengurus — Fase 1: identitas pengurus milik guru

**Status (30 Oktober 2026): implementasi Claude Code selesai dan diserahkan untuk audit independen Codex.** Belum diaudit, belum merge, belum deploy, belum migrasi produksi. Fase 2 belum dimulai. Fase 1 **bukan** izin rilis parsial (lihat "Risiko rilis terpisah").

| Butir | Nilai |
| --- | --- |
| Workstream | Penugasan lintas guru/pengurus dan Keamanan Fase 1–5 — implementator Claude Code, auditor Codex (`AGENTS.md`, keputusan 30 Oktober 2026) |
| Rancangan | [`../rancangan.md`](../rancangan.md) (PK-1…PK-7, PK-T1…PK-T4) |
| Branch | `claude/penugasan-keamanan-fase-1` |
| Baseline | `8917295` (ujung `origin/claude/rancangan-penugasan-keamanan`: rancangan + jawaban PK-T1…PK-T4). `origin/main` `b88078c` (PR #102) baru memuat rancangan `05ce51d`; `git diff 8917295 origin/main` hanya pada `AGENTS.md` dan `rancangan.md` (jawaban PK-T). Merge branch ini ke `main` ikut membawa `8917295`. Tidak bekerja di `main`, tanpa worktree. |
| Mobile | Tidak diubah (paritas aplikasi = Fase 4). |
| Lingkungan uji | PHP 8.4, MariaDB 12.3 lokal, Chromium Playwright; seluruh DB `test_*_test` dari rantai fixture sintetis V1–V7 (`tests/v7_phase5_db.sh`) + `bin/migrate.php up`. Produksi memakai **MariaDB 10.6.28**; versi itu belum diuji (lihat "Batas"). Direktori belum terlacak milik pekerjaan lain (`docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/`) tidak disentuh. |

## Yang dikerjakan

1. **Migrasi aditif `032_penugasan_keamanan_fase1_identitas_guru.sql`**, idempoten: tiga langkah bersyarat `information_schema` + `PREPARE`, pola yang sama dengan 023 dan 030.
   - Kolom `pengurus.guru_id INT NULL` (tipe sama dengan `guru.id`).
   - `UNIQUE pengurus_guru_unique`: satu guru paling banyak satu identitas pengurus.
   - `FOREIGN KEY pengurus_guru_fk → guru(id)`.
   - Tanpa INSERT/UPDATE/DELETE, sehingga seluruh baris lama tetap `guru_id = NULL`.
   - **Rollback berpasangan** didahului penjaga data SQL (tabel sementara ber-CHECK `jumlah = 0`). Bila sudah ada identitas milik guru, rollback berhenti sebelum mengubah skema dan 032 tetap tercatat.
2. **Identitas pengurus milik guru** (PK-1, PK-3), dibentuk di `PenugasanService::buat()` dalam satu transaksi saat admin menugaskan seorang guru ke jenis berbasis pengurus (Pembimbing, Bagian Pendidikan, Petugas Kesehatan, Petugas BSA, Panitia PSB, Bendahara PSB):
   - Urutan kunci tetap: guru → identitas → akun guru.
   - Membuat atau memakai ulang baris `pengurus`: nama dan HP disalin dari data guru, jabatan "Guru", `nomor_identitas` NULL.
   - Bila guru sudah berakun, akun itu dihubungkan ke identitas (`users.pengurus_id`) dan diberi role `pengurus`. Akun baru tidak pernah dibuat.
   - Audit wajib (gagal audit = transaksi batal): `penugasan.identitas_guru.buat`, `penugasan.identitas_guru.sinkron`, `account_identitas_guru_linked`.
   - Ditolak (tanpa sisa baris):
     - guru nonaktif, diarsipkan, atau tidak ada;
     - nilai `guru:` pada jenis khusus guru;
     - pelaku bukan admin;
     - akun guru yang sudah terhubung ke pengurus lain (akun ganda warisan).
   - Galat kunci 1205/1213 → `409` dapat diulang; 1062 → konflik.
3. **Murobi dan Guru Mapel tetap khusus guru** (PK-2): tanpa kelompok tambahan dan tanpa nilai `guru:`.
4. **Hak mengikuti data guru** (`App\Auth\Capabilities::syaratIdentitasGuru`, diterapkan di `linkedPengurusId` dan `activeScopes`):
   - Identitas milik guru hanya sah bila `pengurus.guru_id = users.guru_id` akun itu **dan** guru aktif serta belum diarsipkan.
   - Menonaktifkan guru langsung menggugurkan penugasan jenis pengurus miliknya pada permintaan berikutnya, termasuk sesi lama.
   - Pengurus biasa (`guru_id NULL`) tidak berubah. Tanpa kolom 032, syarat ini kosong.
5. **Sinkron status/nama dari Data Guru**: `MasterDataService::saveGuru` dan `setGuruState` memperbarui identitas dalam transaksi yang sama (audit `master.identitas_guru.sinkron`). Data Pengurus menolak mengubah atau mengarsipkan identitas milik guru (`tolakIdentitasGuru`).
6. **Aturan akun**:
   - `AccountService::createTeacher` menghubungkan identitas bila sudah ada.
   - `requireMasterRelation` hanya menerima pengurus milik guru akun itu sendiri.
   - Identitas milik guru tidak dapat dibuatkan akun lain atau dipinjam akun lain.
   - Akun ganda warisan tetap berperilaku sama.
   - `PerizinanAccountRepository` tidak menawarkan identitas milik guru.
7. **Halaman admin**:
   - `admin/admin_penugasan.php`: formulir tambah jenis berbasis pengurus memakai pemilih "Pengurus atau guru" dengan kelompok **Pengurus** dan **Guru**. Nilai `guru:ID` hanya dari integer, label di-escape. Mode ubah tidak dapat mengganti orang. Pengurus biasa tidak lagi menampilkan baris identitas milik guru, agar satu orang tidak muncul dua kali.
   - `admin/admin_pengurus.php`: lencana **Guru**, menu tanpa ubah/status, "Buka data guru".
   - `admin/admin_guru.php`: bagian "Penugasan sebagai pengurus".
   - `PenugasanJenis`: teks "pengurus atau guru".
8. **Peringatan nama sama** (keputusan Human Developer 30 Oktober 2026, "Peringatan saja"):
   - Data lama dapat mencatat satu orang dua kali, sebagai guru dan sebagai pengurus biasa. Pada salinan produksi yang diperlihatkan Human Developer, ada satu orang seperti itu: data gurunya tanpa akun, sedangkan data pengurusnya punya akun pengurus sendiri dengan penugasan aktif.
   - Bila guru semacam itu dipilih dari kelompok Guru, terbentuk identitas pengurus kedua dan akun pengurus lamanya tidak dipakai.
   - `PenugasanService::opsi()` menandai guru yang namanya (huruf kecil, spasi dirapikan) sama dengan **pengurus biasa aktif**. Formulir menambahkan "— nama sama dengan pengurus" pada pilihannya dan menampilkan kotak peringatan yang menyebut nama-nama itu, dengan saran memilih dari kelompok Pengurus.
   - **Hanya peringatan**: pilihan tidak dikunci karena nama sama belum tentu orang sama. Tidak ada data yang diubah atau digabung otomatis.
   - Identitas milik guru sendiri tidak memicu tanda.
   - Penggabungan akun/data ganda tetap di luar Fase 1; bila diperlukan, itu tahap tersendiri atas keputusan Human Developer.

## Penafsiran (menunggu tinjauan auditor)

- **PF-1** Jabatan identitas = "Guru" dan `nomor_identitas` NULL: data identitas berasal dari data guru, bukan isian admin.
- **PF-2** Identitas tidak dihapus ketika penugasan terakhir berakhir. Identitas dipakai ulang; hak tetap dihitung dari penugasan aktif.
- **PF-3** Akun ganda warisan (akun guru yang sudah memegang pengurus biasa) **ditolak** saat gurunya dipilih dari kelompok Guru; admin memakai pengurus lamanya dari kelompok Pengurus. Hak akun itu tidak berubah.
- **PF-4** Kecocokan nama untuk peringatan hanya membandingkan huruf kecil dan spasi, tanpa membuang gelar atau tanda baca, untuk menghindari tanda palsu yang berlebihan. Batasnya: nama yang ditulis berbeda (mis. dengan gelar) tidak ditandai.
- **PF-5** Peringatan hanya di formulir tambah penugasan; Data Guru/Data Pengurus tidak menampilkannya.

## Hasil pengujian (kode final, lokal)

| Rangkaian | Hasil | Berkas |
| --- | --- | --- |
| Statis `tests/penugasan_keamanan_f1_static.php`: migrasi/rollback, urutan kunci, audit wajib, syarat `Capabilities`, escape/nilai integer, peringatan, batas berkas terhadap baseline, lint | 55/0 | `bukti/statis.txt` |
| Integrasi `tests/penugasan_keamanan_f1_integration.php` I-1…I-12, termasuk konkurensi dua proses (`…_worker.php`) dan peringatan nama sama | 70/0 | `bukti/integrasi.txt` |
| HTTP web lewat server PHP lokal `tests/penugasan_keamanan_f1_http.php`: formulir, CSRF/GET/non-admin, guru beralih peran Pengurus, nonaktif dari Data Guru pada sesi yang sama, Data Pengurus/Guru | 34/0 | `bukti/http.txt` |
| Migrasi–rollback–preservasi pada salinan 031 `tests/penugasan_keamanan_f1_migration.php`: naik, ulang naik (idempoten), penjaga rollback, rollback bersih, naik lagi | 22/0 | `bukti/migrasi-rollback.txt` |
| Browser 375/768/1280 + tanpa JavaScript `tests/browser/uji-penugasan-keamanan-f1.mjs`. Log server 0 galat PHP; tangkapan diperiksa visual dan tidak di-commit. | 41/0 | `bukti/browser.txt` |

Perintah:

```bash
php tests/penugasan_keamanan_f1_static.php
bash tests/penugasan_keamanan_f1_db.sh && php tests/penugasan_keamanan_f1_integration.php
PKF1_DB=test_pk_f1_http_test bash tests/penugasan_keamanan_f1_db.sh && PKF1_DB=test_pk_f1_http_test php tests/penugasan_keamanan_f1_http.php
PKF1_TANPA_032=1 PKF1_DB=test_pk_f1_mig_test bash tests/penugasan_keamanan_f1_db.sh && PKF1_DB=test_pk_f1_mig_test php tests/penugasan_keamanan_f1_migration.php
PKF1_DB=test_pk_f1_browser_test bash tests/penugasan_keamanan_f1_db.sh
DB_NAME=test_pk_f1_browser_test php -S 127.0.0.1:8973 tests/redesain_web_tahap1_router.php &
(cd tests/browser && BASE_URL=http://127.0.0.1:8973 node uji-penugasan-keamanan-f1.mjs)
```

### Kontrol pada kode baseline `8917295`

Rangkaian baru dijalankan pada `git archive 8917295` dengan migrasi 032 dan berkas uji Fase 1 disalin. Tujuannya membuktikan bahwa uji ini benar-benar mendeteksi ketiadaan fitur:

- statis 18/20;
- HTTP 15/19;
- integrasi berhenti pada I-1 (2/2 lalu galat karena `opsi()` belum memiliki `subjek_guru`).

Berkas: `bukti/kontrol-baseline/f1_*`.

### Regresi

| Rangkaian | Kini | Baseline `8917295` | Keterangan |
| --- | --- | --- | --- |
| Fondasi penugasan `penugasan_integration` | 161/0 | — | |
| `penugasan_concurrency` | 52/0 | — | |
| `penugasan_web_smoke` | 86/0 | — | |
| `penugasan_static` | 281/2 | 281/2 (identik) | Oracle historis "tujuh jenis" (FS-2, FS-4) sejak Petugas Kesehatan V5; tidak dilonggarkan |
| `kredensial_integration` / `_static` / `_web_smoke` | 59/0 · 105/0 · 51/0 | — | |
| HTTP Tahap 2 `redesain_web_tahap2_http` | 185/6 | 185/6 (identik) | Oracle peta menu lama (keputusan BB–BE); tidak dilonggarkan |
| HTTP Tahap 6 `redesain_web_tahap6_http` (Data Guru/Pengurus) | 125/7 | 125/7 (identik) | Oracle rollback "023 terakhir"; dengan `migrate up` yang dirollback adalah 032. Tidak dilonggarkan. |

Berkas: `bukti/regresi/`, `bukti/kontrol-baseline/`.

## Kecocokan dengan basis data produksi

Pada 30 Oktober 2026, Human Developer memperlihatkan isi phpMyAdmin produksi. Dump itu tidak disimpan di repositori karena memuat data pribadi. Hasil pencocokan:

- Migrasi terakhir yang tercatat adalah **031**, sehingga 032 menjadi langkah berikutnya.
- `guru.id` bertipe `int(11)`, sama dengan `pengurus.guru_id INT`. Kedua tabel InnoDB.
- Nama indeks/kunci baru belum dipakai.
- Pola `information_schema` + `PREPARE` sudah berhasil di produksi lewat 023 dan 030.
- Tidak ada akun yang memegang guru dan pengurus sekaligus. Setelah 032 terpasang, seluruh `guru_id` NULL, sehingga hak semua akun sama seperti sebelumnya dan rollback tetap mungkin sampai identitas pertama dibuat.
- Satu orang tercatat ganda guru/pengurus; ditangani dengan peringatan (butir 8).

## Batas

- **MariaDB 10.6.28 (produksi) belum diuji**; semua uji di MariaDB 12. Fitur yang dipakai (FK, UNIQUE, CHECK pada penjaga rollback, `PREPARE`) tersedia di 10.6, tetapi belum dieksekusi di versi itu. MySQL/cPanel/smoke hosting juga belum.
- Mobile, Push, perangkat fisik, dan UAT tidak diuji (tidak ada perubahan mobile).
- Tidak ada fitur penggabungan data ganda guru/pengurus (lihat butir 8).
- Gerbang produksi V5/V6/V7 yang masih terbuka tidak berubah.

## Risiko rilis terpisah

Fase 1 memungkinkan satu akun guru memegang peran Murobi dan Pembimbing sekaligus. **Pemisahan tugas pengaju ≠ pemutus per akun baru dikerjakan di Fase 2.**

Akibatnya, bila Fase 1 dirilis sendiri, seorang guru yang menjadi pembimbing **dan** murobi atas santri yang sama dapat mengajukan izin lalu memutusnya sendiri. Fase 1 sebaiknya dirilis bersama Fase 2, atau, bila dirilis lebih dulu, guru tidak ditugaskan sebagai pembimbing untuk santri yang juga ia murobi-i. Keputusan rilis tetap milik Human Developer.

## Handoff

Tidak merge, deploy, migrasi produksi, atau memulai Fase 2. Berikutnya: audit independen Codex dengan prompt di [`../rancangan.md`](../rancangan.md) §7 (Fase 1, branch `claude/penugasan-keamanan-fase-1`).
