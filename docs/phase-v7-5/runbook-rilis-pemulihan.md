# Runbook rilis dan pemulihan PRD V7 (izin orang tua melalui pembimbing)

**Belum dieksekusi pada produksi.** Langkah produksi hanya boleh dijalankan sesudah audit Codex Fase 5, keputusan Human Developer, dan instruksi terpisah. Sesi implementasi ini tidak merge, tidak deploy, tidak migrasi produksi, tidak memasang cron, dan tidak merilis aplikasi. Bukti di bawah berasal dari **DB salinan lokal berfixture sintetis** (MariaDB 12.3.2, PHP 8.4.14), bukan hosting.

**Satu rilis utuh.** Migrasi 030 dan 031 dipasang dalam satu jendela pemeliharaan bersama kode Fase 1–5. Preflight dan verifier menolak keadaan sebagian (030 tanpa 031).

## 1. Keadaan yang harus dipastikan dulu

1. **Inventaris produksi nyata, bukan keadaan Git.** `php bin/migrate.php status`, SHA kode yang benar-benar terpasang, versi PHP CLI dan FPM, engine/versi DB, zona waktu DB. Status deploy V5, V6, keamanan PSB, dan redesain Tahap 7–8 belum pernah dikonfirmasi di repositori.
2. **V7 bergantung pada V6.** Preflight mewajibkan 029 (V6 tabungan) sudah tercatat. Gerbang akhir/produksi V6 **masih terbuka** (`docs/phase-v6-5/AUDIT-CODEX.md`). `bin/migrate.php up` menjalankan seluruh migrasi tertunda berurutan, sehingga bila 024–029 belum ada di produksi, V5 dan V6 ikut terpasang sebelum 030. Jangan menjalankan rilis V7 sampai Human Developer memutuskan rilis V5/V6 lebih dahulu sesuai gerbangnya masing-masing.
3. **Langkah manual lama tetap berlaku.** `.htaccess` keamanan PSB (`docs/keamanan-psb/README.md`), `/profil` dan `/pendidikan` Tahap 8, serta isi resmi situs (AY). `bin/deploy-cpanel.sh` tidak menyalin `.htaccess`.
4. **Penugasan pembimbing dan akun.** Pengajuan wali hanya masuk antrean pembimbing bila santri memiliki **tepat satu** pembimbing sah (penugasan aktif pada kamar/kelas santri di tahun ajaran aktif **dan** akun pengurus aktif). Nol atau lebih dari satu kandidat masuk antrean admin. Preflight mencetak jumlah pengurus berpenugasan pembimbing tanpa akun aktif. Admin meninjau penugasan pembimbing/murobi dan akun pengurus/guru **sebelum** membuka fitur kepada orang tua, supaya antrean admin tidak membengkak.
5. **Menu langsung tampil.** Tidak ada sakelar rilis. Begitu kode dan 030–031 terpasang, orang tua berakun aktif melihat "Izin anak" dan dapat mengajukan; pembimbing, murobi, dan admin melihat antreannya. Kode V7 tanpa 030–031 menjawab 503 "belum dipasang" pada halaman/API V7, sedangkan jalur pengurus V2 tetap berjalan; karena itu urutan kode → migrasi dalam satu jendela aman.
6. **Gerbang cakupan murobi belum tertutup (F5-1).** Murobi tujuan masih dapat membaca detail/laporan dan memutus ketika penugasan yang mencakup santri dicabut tetapi penugasan lain tetap aktif. Audit Codex mereproduksi perilaku ini; ketentuan pencabutan hak PRD V7 §5.2 belum dapat dinyatakan terpenuhi untuk pencabutan sebagian. Implementasi V2 saat ini memakai tujuan pengajuan sebagai cakupan dan admin dapat memilih murobi aktif di luar kamar/kelas santri. Human Developer perlu menetapkan apakah V7 mempertahankan hak tujuan sampai penetapan ulang, atau mewajibkan cakupan santri terkini beserta penanganan penetapan/rerouting. Jangan menyebut penetapan ulang manual sebagai bukti celah ini telah ditutup. Keputusan audit dan bukti ada di `AUDIT-CODEX.md`.
7. **Retry bersamaan jalur pengurus V2 (T-1, terbuka sejak Fase 1).** Kiriman bersamaan berkunci sama pada pembuatan pengajuan **pengurus** V2 dapat menjawab 500 pada sebagian kiriman, tanpa baris ganda. Jalur wali V7 tidak terdampak (kunci akun sebelum klaim). Tindak lanjut terpisah, menunggu keputusan.
8. **Server DB:** InnoDB, **CHECK ditegakkan** (MariaDB 10.2.1+ / MySQL 8.0.16+; konsistensi asal/tindakan dan penjaga rollback bergantung padanya) pada sesi **dan** default global MariaDB, binlog bukan `STATEMENT` (transaksi READ COMMITTED), dan **jam DB = jam aplikasi** (`APP_TIMEZONE`, bawaan Asia/Jakarta). Tanggal penugasan aktif, lama tiap tahap, dan laporan membandingkan waktu DB dengan tanggal PHP; preflight **gagal** bila selisih > 5 menit. MySQL dan MariaDB hosting **belum diuji**.
9. **Manifest rilis:** `docs/phase-v7-5/manifest-migrasi.json` berisi SHA-256 31 migrasi, 2 rollback (030–031), dan berkas mobile (hash HEAD Git). Dicocokkan lewat `--rilis` pada §3 langkah 2.

## 2. Backup dan restore point

1. Jadwalkan pemeliharaan. Hentikan penulis dan cron worker notifikasi sebelum backup.
2. Backup konsisten dengan credential dari berkas opsi privat (bukan argumen baris perintah):
   ```bash
   mariadb-dump --defaults-extra-file=/jalur/privat/opsi.cnf --single-transaction --routines --triggers --hex-blob NAMA_DB > /jalur/privat/backup-v7.sql
   sha256sum /jalur/privat/backup-v7.sql
   ```
   Simpan juga `.env`, `PUSH_TOKEN_KEY`, dan secret token API terenkripsi di luar document root. **Jangan** taruh dump/kunci di Git atau `public_html`. Dump memuat alasan izin dan identitas santri/wali: perlakukan sebagai data pribadi rahasia.
3. Restore ke **DB salinan berbeda**, lalu `DB_NAME=SALINAN php bin/v7_preflight.php` (sebelum V7) atau `bin/v7_verify.php MANIFEST` (sesudah V7). Restore yang belum diverifikasi bukan restore point.
4. Latihan lokal (hanya DB `test_*_test`, `V7_DRILL=1`, menolak `APP_ENV=production`):
   ```bash
   V7_DRILL=1 DB_NAME=test_…_test php bin/v7_backup_restore_drill.php --metode=writer   # BackupWriter aplikasi
   V7_DRILL=1 DB_NAME=test_…_test php bin/v7_backup_restore_drill.php --metode=dump     # mariadb-dump runbook
   ```
   Hasil lokal: `bukti/rilis.txt` R-7, writer 11/0 dan dump 12/0. Langkahnya: preflight + manifest privat, backup, restore lewat klien, verifier + manifest pada pulihan (isi seluruh tabel lama, irisan V2, isi V7), hash isi 17 tabel (V7, perizinan V2/warisan, identitas, penugasan, outbox, migrasi) sumber = pulihan, ringkasan laporan V7 sama, dan rollback 031 berisi data ditolak.
5. **Routine/trigger:** V7 tidak membuat routine atau trigger. Inventaris routine/trigger dan hak akun pemulihan hosting (audit V5 mencatat akun uji tanpa ALTER ROUTINE) tetap wajib sebelum backup disebut restore point.

**Koreksi preservasi Codex Fase 5:** manifest privat yang dibuat sebelum koreksi harus dibuat ulang. Hash kini membedakan NULL, NUL, dan batas tiap kolom dengan panjang byte; `audit_logs` ikut jumlah/hash tabel lama. Hanya `schema_migrations` dikecualikan dari snapshot migrasi karena catatannya memang berubah, lalu dibandingkan tersendiri dalam latihan restore. Opsi `--irisan-v2` tetap dipakai untuk aktivitas V7 yang sah; manifest seluruh tabel dibandingkan hanya tanpa lalu lintas. Manifest SHA migrasi `manifest-migrasi.json` tidak berubah karena SQL dan berkas paket ter-commit tetap sama.

## 3. Urutan rilis (sesudah izin Human Developer)

1. Merge oleh Human Developer. Deploy kode lewat `bin/deploy-cpanel.sh --dry-run`, lalu tanpa argumen dari `main` yang bersih. Pastikan `bin/`, `database/`, `docs/`, `tests/`, dan `storage/` ditolak HTTP. Skrip `bin/*` menolak non-CLI dengan 404.
2. Preflight dan manifest privat (di luar document root, 0600):
   ```bash
   php bin/v7_preflight.php /jalur/privat/manifest-v7.json --rilis=docs/phase-v7-5/manifest-migrasi.json
   ```
   Harus exit 0:
   - server, CHECK sesi/global, binlog, InnoDB, jam DB = jam aplikasi;
   - tabel prasyarat, 029 tercatat, role dasar tanpa role baru;
   - 030–031 berurutan dan tidak sebagian; tidak ada migrasi > 031;
   - ENUM status V2 masih diawali lima nilai lama; seluruh baris berstatus V2; tipe kolom induk kunci asing sesuai;
   - daftar migrasi/rollback manifest **lengkap** dan setiap SHA-256 sama dengan yang diaudit (33 SHA);
   - jumlah pembimbing tanpa akun, orang tua yang dapat mengajukan, dan sakelar notifikasi dicetak untuk ditinjau.
3. `php bin/migrate.php up`, lalu ulangi `up` sekali. Ulangan tidak boleh memasang migrasi baru.
4. **Segera** (sebelum pengajuan pertama): `php bin/v7_verify.php /jalur/privat/manifest-v7.json`, harus exit 0. Verifier ini:
   - menjalankan verifier Fase 2 (skema 030–031, invarians alur) dan Fase 3 (notifikasi) sebagai proses terpisah;
   - memeriksa transisi status dan rantai riwayat setiap pengajuan wali, pasangan riwayat–audit, keputusan tunggal, antrean murobi, benturan tanggal lintas jalur, referensi yatim, dan laporan = sumber;
   - mencocokkan jumlah baris **dan isi** seluruh tabel lama (identitas, PSB, V1–V6, perizinan V2) serta irisan V2 dengan manifest.
5. Smoke (`checklist-smoke.md`), sebaiknya pada **staging hasil restore backup produksi** dengan kode yang sama. Pengajuan, riwayat, dan audit tidak pernah dihapus; smoke di produksi meninggalkan baris di laporan selamanya. Bila smoke produksi dipilih, gunakan santri, wali, dan akun smoke sintetis (`SMOKE V7 …`), akhiri setiap pengajuan smoke dengan pembatalan beralasan atau keputusan, lalu nonaktifkan santri/akun smoke dan akhiri penugasan smoke. Pilihan ini keputusan Human Developer.
6. Aplikasi: Fase 5 tidak mengubah kode aplikasi. Layar V7 berasal dari Fase 4 (JavaScript, tanpa modul native baru; `expo-screen-capture` sudah diwajibkan sejak V5). Pasang web/API dahulu, lalu rilis aplikasi. Perangkat fisik, Push nyata, dan cold start Push tetap gerbang terbuka.

Lokal teruji (`bukti/rilis.txt` R-1…R-5): salinan V6 (029) + perizinan warisan V1 → preflight `--rilis` (33 SHA cocok, manifest privat 0600) → `up` memasang tepat 030 dan 031 → verifier + manifest lulus (isi seluruh tabel lama tetap) → turun tanpa data mengembalikan snapshot persis → naik lagi lulus.

## 4. Worker dan cron

V7 memakai worker outbox V2 yang sama; tidak ada worker baru. InApp diantre di dalam transaksi mutasi; Push hanya bila sakelar Push menyala dan penerima berperangkat. Worker memeriksa ulang versi pengajuan dan hak penerima tepat sebelum provider. WhatsApp tidak pernah diantrekan untuk V7. Cron sama dengan runbook V5 (`docs/phase-v5-5/runbook-rilis-pemulihan.md` §4), **belum dipasang**.

Pemeriksaan berkala opsional (hanya membaca). Keluaran verifier memuat ID pengajuan dan hitungan, bukan alasan izin: simpan log khusus operator di luar document root, direktori privat 0700 dan berkas 0600. Hentikan penulis saat verifikasi restore/migrasi. Pemeriksaan berkala saat lalu lintas aktif dapat memerlukan pengulangan pada jendela tenang, karena verifier Fase 2–3 berjalan sebagai proses terpisah tanpa satu snapshot bersama. Jangan berikan manifest pada pemeriksaan berkala; perbandingan isi tabel lama hanya bermakna tanpa lalu lintas. Opsi `--irisan-v2 MANIFEST` membandingkan irisan V2 saja, yang tetap sama walau V7 dipakai, tetapi berubah sah bila jalur pengurus V2 dipakai.

```cron
40 2 * * * umask 077; /usr/local/bin/php /home/AKUN/public_html/bin/v7_verify.php >> /home/AKUN/logs/v7_verify.log 2>&1
```

## 5. Rollback aman

- **Kode lebih dahulu.** Rollback utama adalah kode web ke restore point yang kompatibel. Kolom/tabel V7 aditif boleh tetap ada. Kode sebelum V7 tidak mengenal status tahap pembimbing; bila sudah ada pengajuan wali, kode lama menampilkan baris itu di layar V2 tanpa pemahaman tahapnya, sehingga rollback kode sesudah ada pengajuan wali memerlukan penilaian Human Developer.
- **DB hanya bila data V7 kosong.** Urutannya 031 → 030, masing-masing `php bin/v7_phaseN_rollback_preflight.php && php bin/migrate.php rollback`. Kedua berkas rollback memuat **penjaga SQL** (tabel `TEMPORARY` dengan `CHECK (jumlah = 0)`): bila ada data, rollback gagal sebelum DROP/ALTER apa pun dan migrasi tetap tercatat. Uji lokal R-6: pengajuan wali tahap Fase 1 → rollback 031 diizinkan, rollback 030 ditolak; sesudah tinjauan/keputusan → rollback 031 ditolak; isi tetap, tanpa tabel penjaga tersisa. Pada MySQL < 8.0.16 CHECK diabaikan, sehingga jangan rollback di versi tersebut.
- **Jika sudah ada pengajuan wali:** jangan DROP/TRUNCATE/DELETE. Lakukan forward fix, atau restore konsisten dari backup terverifikasi sesudah menilai pengajuan yang masuk setelah restore point (pengajuan itu hilang dan wali harus mengajukan ulang).
- Menutup jalur wali sementara tanpa rollback: tidak ada sakelar. Pilihan yang tersedia adalah mengakhiri penugasan pembimbing (pengajuan baru masuk antrean admin) atau kode restore point; keduanya keputusan Human Developer.

## 6. Pemantauan sesudah rilis

- `bin/v7_verify.php` aman dijalankan berkala. Gagal berarti investigasi; jangan "membersihkan" data agar hijau.
- Antrean admin (`portal/izin_wali.php`, saringan antrean): pengajuan "Perlu penetapan pembimbing" dan "Perlu penetapan murobi" menandakan penugasan yang belum tunggal. Tindak lanjuti lewat data penugasan, bukan dengan menetapkan akun di luar penugasan.
- Laporan V7 (`portal/izin_wali_laporan.php`) memisahkan asal wali/pengurus dan tahap. Ekspor tercatat di `audit_logs`; berkas ekspor memuat data santri: jangan dibagikan di luar yang berhak.
- Notifikasi: InApp generik tanpa nama/alasan/tanggal. Bila Push dinyalakan, pantau `notifikasi_percobaan` untuk kegagalan provider; enqueue yang gagal tercatat `izin_wali_notifikasi_gagal` tanpa pemulihan otomatis (batas Fase 3).
