# Runbook rilis dan pemulihan — penugasan lintas guru/pengurus dan Keamanan

**Belum dieksekusi pada produksi.** Langkah produksi hanya boleh dijalankan sesudah audit Codex Fase 5, keputusan Human Developer, dan instruksi terpisah. Sesi implementasi ini tidak merge, tidak deploy, tidak migrasi produksi, tidak memasang cron, dan tidak merilis aplikasi. Bukti di bawah berasal dari **DB salinan lokal berfixture sintetis** (MariaDB 12.3.2, PHP 8.4.14), bukan hosting.

**Satu rilis utuh.** Kode Fase 1–4 dan migrasi 032 (identitas pengurus milik guru) serta 033 (penugasan Keamanan) dipasang dalam satu jendela pemeliharaan. Pemisahan tugas per akun (Fase 2) tidak memiliki migrasi; aturannya berlaku begitu kode terpasang. Fase 1 tanpa Fase 2 tidak boleh dirilis sendiri, karena satu orang dapat menjadi pembimbing dan murobi santri yang sama. Preflight dan verifier menolak keadaan sebagian (032 tanpa 033).

## 1. Keadaan yang harus dipastikan dulu

1. **Inventaris produksi nyata, bukan keadaan Git.** `php bin/migrate.php status`, SHA kode yang benar-benar terpasang, versi PHP CLI dan FPM, engine/versi DB, zona waktu DB. Catatan sebelumnya (30 Oktober 2026) menyebut produksi MariaDB 10.6.28 dengan migrasi terakhir 031. Catatan itu bukan pengganti inventaris ulang. **MariaDB 10.6 dan MySQL belum pernah diuji** untuk alat dan migrasi ini.
2. **Ketergantungan.** Preflight mewajibkan 031 (V7 Fase 2) sudah tercatat, karena Keamanan membaca izin asal Pengurus dan Wali. `bin/migrate.php up` menjalankan seluruh migrasi tertunda berurutan. Bila 024–031 belum ada di server, V5/V6/V7 ikut terpasang sebelum 032. Gerbang produksi V5/V6/V7 **masih terbuka** dan tidak ditutup oleh rilis ini.
3. **Langkah manual lama tetap berlaku.** `.htaccess` keamanan PSB (`docs/keamanan-psb/README.md`), `/profil` dan `/pendidikan` Tahap 8, serta isi resmi situs (AY). `bin/deploy-cpanel.sh` tidak menyalin `.htaccess`.
4. **Data ganda guru/pengurus.** Satu orang di produksi tercatat terpisah sebagai guru dan sebagai pengurus berakun. Keputusan Human Developer 30 Oktober 2026: **peringatan saja**, tanpa penggabungan otomatis. Preflight mencetak:
   - jumlah guru aktif yang namanya sama dengan pengurus biasa aktif;
   - jumlah akun guru yang memegang pengurus biasa (akun ganda warisan).

   Saat menugaskan orang itu, admin memilihnya dari kelompok **Pengurus**. Memilihnya dari kelompok Guru ditolak dengan pesan konflik bila akun gurunya sudah memegang pengurus biasa. Dua akun **terpisah** milik satu orang tidak dikenali pemisahan tugas Fase 2 (batas tercatat sejak Fase 2).
5. **Menu langsung tampil.** Tidak ada sakelar rilis. Sesudah kode dan 033 terpasang, jenis "Keamanan" muncul di halaman Penugasan. Menu Keamanan hanya tampil bagi pemegang penugasan Keamanan aktif pada peran Pengurus. Tanpa 032/033, kode yang sama menampilkan "belum dipasang" pada pilihan guru dan jenis Keamanan, sedangkan modul lain tetap berjalan. Karena itu urutan kode → migrasi dalam satu jendela aman.
6. **Server DB:** InnoDB, **CHECK ditegakkan** (MariaDB 10.2.1+ / MySQL 8.0.16+) pada sesi **dan** default global MariaDB. CHECK rentang tanggal 033 dan penjaga data rollback 032–033 bergantung padanya. **Jam DB = jam aplikasi** (`APP_TIMEZONE`, bawaan Asia/Jakarta), karena masa berlaku penugasan dibandingkan dengan `CURDATE()`. Preflight **gagal** bila selisih > 5 menit.
7. **Manifest rilis:** `docs/penugasan-keamanan/fase-5/manifest-migrasi.json` berisi SHA-256 33 migrasi, 2 rollback (032–033), `composer.lock`, dan berkas mobile (hash HEAD Git). Dicocokkan lewat `--rilis` pada §3 langkah 2.

## 2. Backup dan restore point

1. Jadwalkan pemeliharaan. Hentikan penulis dan cron worker notifikasi sebelum backup.
2. Backup konsisten dengan credential dari berkas opsi privat (bukan argumen baris perintah):
   ```bash
   mariadb-dump --defaults-extra-file=/jalur/privat/opsi.cnf --single-transaction --routines --triggers --hex-blob NAMA_DB > /jalur/privat/backup-pk.sql
   sha256sum /jalur/privat/backup-pk.sql
   ```
   Simpan juga `.env`, `PUSH_TOKEN_KEY`, dan secret token API terenkripsi di luar document root. **Jangan** taruh dump/kunci di Git atau `public_html`. Dump memuat alasan izin, catatan pembinaan, dan identitas santri/wali: perlakukan sebagai data pribadi rahasia.
3. Restore ke **DB salinan berbeda**, lalu `DB_NAME=SALINAN php bin/penugasan_keamanan_preflight.php` (sebelum rilis) atau `bin/penugasan_keamanan_verify.php MANIFEST` (sesudah rilis). Restore yang belum diverifikasi bukan restore point.
4. Latihan lokal (hanya DB `test_*_test`, `PK_DRILL=1`, menolak `APP_ENV=production`):
   ```bash
   PK_DRILL=1 DB_NAME=test_…_test php bin/penugasan_keamanan_backup_restore_drill.php --metode=writer   # BackupWriter aplikasi
   PK_DRILL=1 DB_NAME=test_…_test php bin/penugasan_keamanan_backup_restore_drill.php --metode=dump     # mariadb-dump runbook
   ```
   Hasil lokal: `bukti/rilis.txt` R-7, writer 11/0 dan dump 12/0. Langkah latihan:
   - preflight dan manifest privat;
   - backup, lalu restore lewat klien;
   - verifier + manifest pada DB pulihan;
   - hash isi 25 tabel (identitas, akun/role, seluruh penugasan, izin, pembinaan V3, kehadiran, audit, migrasi) sumber = pulihan, dan himpunan akun Keamanan sama;
   - rollback 033 berisi data ditolak.
5. **Routine/trigger:** workstream ini tidak membuat routine atau trigger. Inventaris routine/trigger dan hak akun pemulihan hosting tetap wajib sebelum backup disebut restore point (audit V5 mencatat akun uji tanpa ALTER ROUTINE).

## 3. Urutan rilis (sesudah izin Human Developer)

1. Merge oleh Human Developer. Deploy kode lewat `bin/deploy-cpanel.sh --dry-run`, lalu tanpa argumen dari `main` yang bersih. Pastikan `bin/`, `database/`, `docs/`, `tests/`, dan `storage/` ditolak HTTP. Skrip `bin/*` menolak non-CLI dengan 404.
2. Preflight dan manifest privat (di luar document root, 0600):
   ```bash
   php bin/penugasan_keamanan_preflight.php /jalur/privat/manifest-pk.json --rilis=docs/penugasan-keamanan/fase-5/manifest-migrasi.json
   ```
   Harus exit 0:
   - server, CHECK sesi/global, InnoDB, jam DB = jam aplikasi;
   - tabel prasyarat (termasuk seluruh tabel penugasan), 031 tercatat, tepat empat role dasar;
   - 032–033 berurutan dan tidak sebagian; tidak ada migrasi > 033;
   - tipe kolom induk kunci asing (`guru.id` INT bertanda, `pengurus.id`/`users.id` BIGINT UNSIGNED, `tahun_ajaran.id` INT) dan mesin InnoDB tabel induk;
   - daftar migrasi/rollback manifest **lengkap** dan setiap SHA-256 sama dengan yang diaudit (35 SHA);
   - data ganda nama, akun ganda warisan, akun guru, dan penugasan aktif per jenis dicetak untuk ditinjau.
3. `php bin/migrate.php up`, lalu ulangi `up` sekali. Ulangan tidak boleh memasang migrasi baru.
4. **Segera** (sebelum penugasan pertama): `php bin/penugasan_keamanan_verify.php /jalur/privat/manifest-pk.json`, harus exit 0. Verifier ini:
   - menjalankan verifier fondasi penugasan (`bin/penugasan_verify.php`) sebagai proses terpisah;
   - memeriksa skema 032–033 (tipe, UNIQUE, kunci asing, CHECK, kolom persis);
   - memeriksa identitas pengurus milik guru: jabatan "Guru", sinkron dengan Data Guru, hanya dipegang akun guru pemiliknya, setiap pembentukan dan penautan diaudit, identitas guru nonaktif tanpa capability;
   - memeriksa penugasan Keamanan: setiap baris diaudit, pengakhiran beralasan, akun `keamanan.pantau` menurut resolver = SQL independen, audit `keamanan.*` hanya metadata dan berpelaku pemegang Keamanan;
   - memeriksa pemisahan tugas sejak 032 tercatat: tidak ada keputusan/koreksi izin oleh akun pengaju atau peninjau pengajuan yang sama;
   - mencocokkan jumlah baris **dan isi** seluruh tabel lama (`pengurus` tanpa kolom baru) dengan manifest.
5. Smoke (`checklist-smoke.md`), sebaiknya pada **staging hasil restore backup produksi** dengan kode yang sama. Penugasan, identitas guru, dan audit tidak dihapus, sehingga smoke di produksi meninggalkan baris selamanya dan **menahan rollback DB** (§5). Bila smoke produksi dipilih, gunakan akun smoke sintetis (`SMOKE PK …`), lalu akhiri penugasan smoke dengan alasan dan nonaktifkan akun smoke. Pilihan ini keputusan Human Developer.
6. Aplikasi: Fase 5 tidak mengubah kode aplikasi. Layar Keamanan dan "Identitas & penugasan" berasal dari Fase 4 (JavaScript, tanpa modul native baru; `expo-screen-capture` sudah diwajibkan sejak V5). Pasang web/API dahulu, lalu rilis aplikasi. Aplikasi lama tetap berjalan terhadap server baru. Aplikasi baru terhadap server lama menyembunyikan bagian yang endpoint-nya belum ada (404). Perangkat fisik, runtime iOS, dan build distribusi belum diuji.

Lokal teruji (`bukti/rilis.txt` R-1…R-5): salinan 031 berisi data V1–V7 → preflight `--rilis` (35 SHA cocok, manifest privat 0600) → `up` memasang tepat 032 dan 033 → verifier + manifest lulus (isi seluruh tabel lama tetap) → turun tanpa data mengembalikan snapshot persis → naik lagi lulus.

## 4. Worker dan cron

Tidak ada worker atau cron baru. Keamanan tidak mengirim notifikasi. Pemeriksaan berkala opsional (hanya membaca):

```cron
50 2 * * * umask 077; /usr/local/bin/php /home/AKUN/public_html/bin/penugasan_keamanan_verify.php >> /home/AKUN/logs/penugasan_keamanan_verify.log 2>&1
```

- Keluaran verifier memuat ID dan hitungan, bukan isi izin/pembinaan. Tetap simpan log khusus operator di luar document root (direktori 0700, berkas 0600).
- Jangan berikan manifest pada pemeriksaan berkala. Perbandingan isi tabel lama hanya bermakna tanpa lalu lintas.
- Opsi `--irisan-baca MANIFEST` membandingkan data yang hanya dibaca Keamanan: izin, pembinaan, kehadiran, santri, guru, dan pengurus biasa. Irisan ini tetap sama walau Keamanan dipakai dan identitas guru dibentuk, tetapi berubah sah bila modul lain dipakai.
- Verifier rilis V5/V6/V7 tetap lulus pada DB sesudah 032–033 (`bukti/verifier-lama-*.txt`). Preflight V5/V6/V7 menolak migrasi sesudah versinya, karena alat itu hanya untuk sebelum rilisnya masing-masing.

## 5. Rollback aman

- **Kode lebih dahulu.** Rollback utama adalah kode web ke restore point yang kompatibel. Kolom/tabel 032–033 aditif boleh tetap ada.
  - Kode sebelum Fase 1 tidak mengenal identitas milik guru. Akun guru yang sudah terhubung ke identitas tetap memegang role pengurus dan `pengurus_id`, tetapi status identitas tidak lagi disinkronkan dari Data Guru.
  - Kode sebelum Fase 2 membuang pemisahan tugas per akun.

  Rollback kode sesudah identitas guru atau penugasan Keamanan dibuat memerlukan penilaian Human Developer.
- **DB hanya bila data kosong.** Urutannya 033 → 032. Sebelum setiap langkah:
  ```bash
  php bin/penugasan_keamanan_rollback_preflight.php && php bin/migrate.php rollback
  ```
  - 033 hanya bila `keamanan_assignments` kosong, termasuk penugasan yang sudah diakhiri. Riwayat pemegang hak baca tidak boleh hilang.
  - 032 hanya bila belum ada identitas pengurus milik guru.
  - Kedua berkas rollback memuat **penjaga SQL** (tabel `TEMPORARY` dengan `CHECK (jumlah = 0)`). Bila ada data, rollback gagal sebelum DROP/ALTER apa pun dan migrasi tetap tercatat.
  - Uji lokal R-6:
    - identitas guru tanpa Keamanan → rollback 033 diizinkan, rollback 032 ditolak;
    - sesudah penugasan Keamanan, termasuk yang sudah diakhiri → rollback 033 ditolak.

    Isi tetap, tanpa tabel penjaga tersisa. Pada MySQL < 8.0.16 CHECK diabaikan, sehingga jangan rollback di versi tersebut. Preflight rollback menolak bila CHECK tidak aktif.
- **Jika sudah ada data:** jangan DROP/TRUNCATE/DELETE. Lakukan forward fix, atau restore konsisten dari backup terverifikasi sesudah menilai perubahan yang masuk setelah restore point.
- **Menutup akses tanpa rollback:**
  - akhiri atau nonaktifkan penugasan Keamanan di halaman Penugasan. Sesi web dan token aplikasi lama ditolak pada permintaan berikutnya (E-5);
  - menonaktifkan guru di Data Guru menggugurkan seluruh penugasan berbasis pengurus miliknya (E-4).

## 6. Pemantauan sesudah rilis

- `bin/penugasan_keamanan_verify.php` aman dijalankan berkala. Gagal berarti investigasi; jangan "membersihkan" data agar hijau.
- `[info]` verifier yang perlu ditindaklanjuti admin:
  - penugasan Keamanan aktif tanpa akun sah;
  - akun terhubung identitas tanpa role pengurus;
  - guru berakun yang identitasnya belum terhubung;
  - pengajuan sejak rilis yang murobinya guru milik akun pengaju/peninjau. Keputusan tetap ditolak per akun; tinjau penugasan murobinya.
- Jejak akses Keamanan (`audit_logs`, `keamanan.*`) hanya memuat ID, status, asal, dan jumlah. Cetak PDF Keamanan memuat data santri: jangan dibagikan di luar yang berhak.
