# Runbook rilis bersama dan pemulihan PRD V6 (keuangan santri/BSA)

**Belum dieksekusi pada produksi.** Langkah produksi hanya boleh dijalankan sesudah audit Codex Fase 5, keputusan Human Developer, dan instruksi terpisah. Sesi implementasi ini tidak merge, tidak deploy, tidak migrasi produksi, tidak memasang cron, dan tidak merilis aplikasi. Bukti di bawah berasal dari **DB salinan lokal berfixture sintetis** (MariaDB 12.3.2, PHP 8.4.14), bukan hosting.

**Tagihan dan tabungan dirilis bersama.** Migrasi 027, 028, dan 029 dipasang dalam satu jendela pemeliharaan. Preflight menolak keadaan sebagian (mis. 028 tanpa 029).

## 1. Keadaan yang harus dipastikan dulu

1. **Inventaris produksi nyata, bukan keadaan Git.** `php bin/migrate.php status`, SHA kode yang benar-benar terpasang, versi PHP CLI dan FPM, engine/versi DB, zona waktu DB. Status deploy Tahap 7–8, keamanan PSB, dan V5 belum pernah dikonfirmasi di repositori.
2. **V5 ikut terbawa bila belum terpasang.** `bin/migrate.php up` menjalankan seluruh migrasi tertunda berurutan. Jika 024–026 (V5 kesehatan) belum ada di produksi, migrasi itu ikut terpasang sebelum 027. Human Developer memutuskan dahulu apakah V5 dirilis pada jendela yang sama. Bila tidak, **jangan jalankan rilis V6** sampai V5 diputuskan. Rilis V5 punya gerbang sendiri (`docs/phase-v5-5/`).
3. **Langkah manual lama tetap berlaku.** `.htaccess` keamanan PSB (`docs/keamanan-psb/README.md`), `/profil` dan `/pendidikan` Tahap 8, serta isi resmi situs (AY). `bin/deploy-cpanel.sh` tidak menyalin `.htaccess`.
4. **Penugasan BSA (K-1).** Seluruh baris `bendahara_bulanan_assignments` yang aktif dan dalam masa berlaku otomatis menjadi **Petugas BSA** dengan hak mutasi uang. Preflight mencetak jumlahnya. Admin meninjau dan mengakhiri penugasan yang tidak dikehendaki **sebelum** deploy kode V6.
5. **Menu Keuangan langsung tampil** bagi admin, petugas BSA, dan orang tua begitu 027 terpasang (keputusan BE). Tidak ada sakelar rilis. Karena itu kode dan 027–029 dipasang bersama, lalu smoke dijalankan dalam jendela pemeliharaan.
6. **R-10 (santri keluar bersaldo).** Keputusan Human Developer 26 Oktober 2026: dilaporkan saja. Jalur pengembalian saldo santri nonaktif **belum ada**. Admin melihat daftarnya di laporan, dan verifier mencetaknya sebagai `[info]`. Kebijakan pengembalian tetap keputusan terbuka sebelum rilis.
7. **Server DB:** InnoDB, **CHECK ditegakkan** (MariaDB 10.2.1+ / MySQL 8.0.16+; penjaga saldo dan penjaga rollback bergantung padanya), kolom terbangkit 028, binlog bukan `STATEMENT` (transaksi READ COMMITTED), dan **jam DB = jam aplikasi** (`APP_TIMEZONE`, bawaan Asia/Jakarta). Preflight juga menolak MariaDB dengan `check_constraint_checks` sesi atau default global nonaktif; versi saja tidak cukup. Batas hari/bulan laporan dan rekonsiliasi membandingkan `created_at` DB dengan tanggal PHP. Bila zona waktu berbeda, transaksi dini hari bergeser hari/bulan, sehingga preflight **gagal** bila selisih > 5 menit. MySQL dan MariaDB hosting **belum diuji**.
8. **Manifest rilis:** `docs/phase-v6-5/manifest-migrasi.json` berisi SHA-256 29 migrasi, 6 rollback (024–029), `composer.lock`, dan berkas mobile (hash HEAD Git). Dicocokkan lewat `--rilis` pada langkah 3.

## 2. Backup dan restore point

1. Jadwalkan pemeliharaan. Hentikan penulis dan cron worker notifikasi sebelum backup.
2. Backup konsisten dengan credential dari berkas opsi privat (bukan argumen baris perintah):
   ```bash
   mariadb-dump --defaults-extra-file=/jalur/privat/opsi.cnf --single-transaction --routines --triggers --hex-blob NAMA_DB > /jalur/privat/backup-v6.sql
   sha256sum /jalur/privat/backup-v6.sql
   ```
   Simpan juga `.env`, `PUSH_TOKEN_KEY`, dan secret token API terenkripsi di luar document root. **Jangan** taruh dump/kunci di Git atau `public_html`.
3. Restore ke **DB salinan berbeda**, lalu `DB_NAME=SALINAN php bin/v6_preflight.php` (sebelum V6) atau `bin/v6_verify.php` (sesudah V6). Restore yang belum diverifikasi bukan restore point.
4. Latihan lokal (hanya DB `test_*_test`, `V6_DRILL=1`, menolak `APP_ENV=production`):
   ```bash
   V6_DRILL=1 DB_NAME=test_…_test php bin/v6_backup_restore_drill.php --metode=writer   # BackupWriter aplikasi
   V6_DRILL=1 DB_NAME=test_…_test php bin/v6_backup_restore_drill.php --metode=dump     # mariadb-dump runbook
   ```
   Hasil lokal: `bukti/rilis.txt` S-6, writer 11/0 dan dump 12/0. Langkahnya: preflight + manifest, backup, restore lewat klien, verifier + manifest pada pulihan, hash isi 24 tabel (V6, identitas, penugasan, outbox, migrasi) sumber = pulihan, angka laporan sama, dan rollback 029 berisi data ditolak.
5. **Routine/trigger:** V6 tidak membuat routine atau trigger. Audit V5 mencatat akun uji tanpa hak ALTER ROUTINE. Inventaris routine/trigger dan hak akun pemulihan hosting tetap wajib sebelum backup disebut restore point.

## 3. Urutan rilis (sesudah izin Human Developer)

1. Merge oleh Human Developer. Deploy kode lewat `bin/deploy-cpanel.sh --dry-run`, lalu tanpa argumen dari `main` yang bersih. Pastikan `bin/`, `database/`, `docs/`, `tests/`, dan `storage/` ditolak HTTP. Skrip `bin/*` menolak non-CLI dengan 404.
2. Preflight dan manifest privat (di luar document root, 0600):
   ```bash
   php bin/v6_preflight.php /jalur/privat/manifest-v6.json --rilis=docs/phase-v6-5/manifest-migrasi.json
   ```
   Harus exit 0, dengan syarat berikut:
   - server, CHECK, binlog, InnoDB, dan jam DB = jam aplikasi;
   - tabel prasyarat, 023 tercatat, role dasar;
   - status 024–026 dicetak; 027–029 berurutan dan tidak sebagian; tidak ada migrasi > 029;
   - daftar migrasi/rollback manifest **lengkap** dan setiap SHA-256 sama dengan yang diaudit;
   - jumlah penugasan bendahara lama (K-1) dan santri nonaktif (R-10) dicetak untuk ditinjau.
3. `php bin/migrate.php up`, lalu ulangi `up` sekali. Ulangan tidak boleh memasang migrasi baru (idempoten).
4. **Segera** (sebelum transaksi pertama): `php bin/v6_verify.php /jalur/privat/manifest-v6.json`, harus exit 0. Verifier ini:
   - menjalankan verifier Fase 1–3 berantai;
   - memeriksa invarians saldo per santri menurut jenis, tagihan (total − pembayaran sah = sisa), dan rekonsiliasi kas vs perpindahan internal;
   - memeriksa jejak koreksi dan audit setiap baris uang, serta notifikasi V6;
   - mencocokkan jumlah baris **seluruh** tabel lama dan sidik jari identitas santri/wali/relasi/akun.

   Manifest diambil sebelum tabel V6 ada, sehingga isi uang tidak dibandingkan (dicetak `[info]`).
5. Smoke (`checklist-smoke.md`), sebaiknya pada **staging hasil restore backup produksi** dengan kode yang sama. Transaksi BSA tidak pernah dihapus; smoke di produksi meninggalkan baris ledger/tagihan selamanya di laporan. Bila smoke produksi tetap dipilih, gunakan santri dan akun smoke sintetis dan pasangkan setiap transaksi dengan koreksi/pembatalan beralasan sampai saldo dan sisa Rp0. Santri smoke lalu dinonaktifkan dan penugasan smoke diakhiri. Pilihan ini keputusan Human Developer.
6. Admin menugaskan Petugas BSA nyata dengan cakupan unitnya.
7. Aplikasi: Fase 5 menambah satu layar JavaScript (tanpa modul native baru). Modul `expo-screen-capture` sudah diwajibkan sejak V5 dan Fase 4, sehingga binary lama tanpa modul itu gagal tertutup di Keuangan. Pasang web/API dahulu, lalu rilis aplikasi. Perangkat fisik, Push nyata, dan cold start Push tetap gerbang terbuka.

Lokal teruji (`bukti/rilis.txt` S-1): salinan V6 tanpa data uang diturunkan 029 → 028 → 027 lewat preflight + rollback berpenjaga, lalu preflight `--rilis` (35 SHA cocok, manifest privat 0600). Sesudah itu `up` memasang tepat 027, 028, 029 dalam satu langkah, dan verifier + manifest lulus (tabel lama dan identitas tetap).

## 4. Worker dan cron

V6 memakai worker outbox V2 yang sama; tidak ada worker baru. InApp diantre di dalam transaksi mutasi, dan Push hanya bila sakelar Push menyala. Worker memeriksa ulang keadaan sumber, santri aktif, dan relasi wali sebelum provider. WhatsApp tidak pernah diantrekan untuk V6. Cron sama dengan runbook V5 (`docs/phase-v5-5/runbook-rilis-pemulihan.md` §4), **belum dipasang**.

Pemeriksaan berkala opsional (hanya membaca). Verifier mencetak nominal agregat keuangan: simpan log khusus operator di luar document root, direktori privat 0700 dan berkas 0600; jangan teruskan ke log umum. Hentikan penulis saat verifikasi restore/migrasi; pemeriksaan berkala saat transaksi aktif dapat memerlukan pengulangan pada jendela tenang karena seluruh verifier berantai tidak memakai satu snapshot lintas proses.

```cron
30 2 * * * umask 077; /usr/local/bin/php /home/AKUN/public_html/bin/v6_verify.php >> /home/AKUN/logs/v6_verify.log 2>&1
```

## 5. Rollback aman

- **Kode lebih dahulu.** Rollback utama adalah kode web ke restore point yang kompatibel. Tabel V6 aditif boleh tetap ada, dan kode lama tidak membacanya. Aplikasi yang memanggil `/v6/bsa/laporan` pada server tanpa Fase 5 menerima 404 untuk layar laporan saja.
- **DB hanya bila tabel V6 kosong.** Urutannya 029 → 028 → 027, masing-masing `php bin/v6_phaseN_rollback_preflight.php && php bin/migrate.php rollback`. Setiap berkas rollback V6 memuat **penjaga SQL** (tabel `TEMPORARY` dengan `CHECK (jumlah = 0)`). Bila ada data, rollback gagal sebelum DROP apa pun, dan migrasi tetap tercatat. Uji lokal S-5: rollback 029, 028, dan 027 yang dijalankan langsung lewat klien pada salinan berisi semuanya ditolak; 16 tabel V6 dan isi uang tetap. Pada MySQL < 8.0.16 CHECK diabaikan, sehingga jangan rollback di versi tersebut.
- **Jika sudah ada transaksi uang:** jangan DROP/TRUNCATE/DELETE. Lakukan forward fix, atau restore konsisten dari backup terverifikasi setelah menilai transaksi yang masuk sesudah restore point (transaksi itu akan hilang dan harus dicatat ulang dari bukti kas). Koreksi uang selalu lewat pembalik/pembatalan beralasan.
- Hentikan layanan BSA sementara dengan mengakhiri penugasan Petugas BSA lewat admin (bukan menghapus). Sesi dan token lama kehilangan hak pada permintaan berikutnya.

## 6. Pemantauan sesudah rilis

- `bin/v6_verify.php` aman dijalankan berkala. Gagal berarti investigasi; jangan "membersihkan" data agar hijau.
- Laporan admin (`portal/bsa_laporan.php`): pita "Rekonsiliasi periode: selisih Rp0" harus hijau. Pembayaran dari tabungan tidak menambah penerimaan kas baru. Daftar santri nonaktif bersaldo ditindaklanjuti sesuai keputusan R-10.
- Rekonsiliasi harian (`portal/bsa_rekonsiliasi.php`) dicocokkan dengan kas fisik dan mutasi rekening oleh BSA. Sistem tidak membuktikan dana transfer benar-benar masuk.
- Ekspor CSV tercatat di `audit_logs` (`bsa.laporan.ekspor`). Berkas ekspor berisi data keuangan pribadi: jangan dibagikan di luar BSA/admin/wali yang berhak.
