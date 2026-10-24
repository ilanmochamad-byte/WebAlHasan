# Runbook rilis dan pemulihan PRD V5 (kesehatan santri)

**Belum dieksekusi pada produksi.** Langkah produksi hanya setelah audit Codex, keputusan Human Developer, dan instruksi terpisah. Sesi implementasi ini tidak merge, tidak deploy, tidak migrasi produksi, tidak memasang cron, tidak mengubah kanal, dan tidak merilis aplikasi. Bukti di bawah berasal dari **DB salinan lokal berfixture sintetis**, bukan hosting.

## 1. Keadaan yang harus dipastikan dulu

- PR Fase 1–4 V5 sudah di-merge ke `main` (PR #80–#83 web, #14 mobile), tetapi **deploy dan migrasi produksi 024–026 belum pernah dikonfirmasi**. Jangan anggap produksi berada pada keadaan Git. Ambil inventaris nyata: `php bin/migrate.php status`, SHA kode yang benar-benar terpasang, versi PHP CLI/FPM, engine/versi DB.
- Rilis V5 memerlukan **023 tercatat**. Migrasi V5 adalah `024_v5_fase1_kesehatan.sql`, `025_v5_fase2_rincian_kesehatan.sql`, `026_v5_fase3_ringkasan_wali.sql` (aditif: hanya `CREATE TABLE IF NOT EXISTS` + data capability; tidak ada DROP/DELETE/UPDATE data lama). **Fase 4 dan 5 tidak menambah migrasi.**
- DB wajib InnoDB dengan **CHECK yang ditegakkan** dan kunci asing gabungan: MariaDB 10.4+ / MySQL 8.0.16+. Lokal teruji MariaDB 12.3.2 dan PHP 8.4.14; MariaDB hosting (mis. 10.6) dan MySQL lain **belum diuji**.
- Manifest rilis: `docs/phase-v5-5/manifest-migrasi.json` (SHA-256 migrasi 001–026, rollback 024–026, `composer.lock`, `package-lock.json`/`app.json` mobile). Bandingkan dengan berkas di server lewat `--rilis` (langkah 3).

## 2. Backup dan restore point

1. Jadwalkan pemeliharaan. Hentikan penulis yang relevan dan cron worker notifikasi sebelum mengambil backup.
2. Backup konsisten: `mariadb-dump --single-transaction --routines --triggers NAMA_DB > backup.sql` dengan credential dari berkas opsi privat (bukan argumen baris perintah). Simpan juga konfigurasi privat (`.env`, `PUSH_TOKEN_KEY`, secret token API) terenkripsi di luar document root. **Jangan** memasukkan dump/kunci ke Git atau `public_html`.
3. Hitung `sha256sum backup.sql`, catat di manifest privat. Restore ke **DB salinan berbeda** (`mariadb SALINAN < backup.sql`), lalu jalankan `DB_NAME=SALINAN php bin/v5_verify.php` (bila 024–026 sudah ada) atau verifier fase yang relevan. Restore yang belum diverifikasi bukan restore point.
4. Latihan lokal melalui BackupWriter (berbeda dari dump trigger/routine): `V5_DRILL=1 DB_NAME=test_…_test php bin/v5_backup_restore_drill.php` → `bukti/rilis.txt` S-4 (backup 101 tabel → restore klien `mariadb` → verifier + manifest lulus, hash isi 20 tabel sumber = pulihan, angka laporan sama, rollback berisi data ditolak). Skrip menolak DB non-`_test` dan `APP_ENV=production`.

**Bukti auditor:** latihan BackupWriter 12/0 dan perintah runbook mariadb-dump/restore 7/0 untuk seluruh tabel aplikasi + trigger sintetis. Probe procedure tambahan gagal karena akun uji tidak memiliki ALTER ROUTINE pada DB pulihan (6/2); sebelum rilis, inventaris trigger/routine dan pastikan akun pemulihan punya hak CREATE/ALTER ROUTINE/EXECUTE serta izin DEFINER yang diperlukan pada engine target. Jangan mengabaikan kode keluar klien restore. Verifier yang lulus atas tabel tidak membuktikan routine sudah dipulihkan. Lihat `AUDIT-CODEX.md` dan `bukti/audit-codex/backup-routine-gagal-izin.txt`. Hentikan seluruh penulis saat latihan dan verifikasi sidik jari.

## 3. Urutan rilis (setelah izin)

1. Merge oleh Human Developer; deploy kode dengan `bin/deploy-cpanel.sh --dry-run` lalu tanpa argumen dari `main` yang bersih. Skrip menyalin `admin/ api/ app/ assets/ bin/ database/ portal/` dan PHP akar; ia tidak menghapus berkas dan **tidak menyalin `.htaccess`** — langkah manual `.htaccess` keamanan PSB tetap berlaku. Pastikan `bin/`, `database/`, `docs/`, `tests/`, `storage/` ditolak HTTP (skrip `bin/*` juga menolak non-CLI dengan 404).
2. `php bin/v5_preflight.php /jalur/privat/manifest-v5.json --rilis=docs/phase-v5-5/manifest-migrasi.json`
   - harus exit 0: prasyarat tabel, 023 tercatat, role dasar, migrasi V5 berurutan, tidak ada migrasi > 026, **SHA-256 setiap migrasi dan rollback sama dengan manifest yang diaudit**;
   - manifest privat wajib lengkap: seluruh jumlah baris seluruh tabel lama dan sidik jari (hash) ID/status santri, wali, relasi santri–wali; tanpa isi.
3. `php bin/migrate.php up` (menerapkan 024–026 yang belum ada). Ulangi `up` sekali: tidak boleh ada migrasi baru (idempoten).
4. `php bin/v5_verify.php /jalur/privat/manifest-v5.json` harus exit 0. Ia menjalankan verifier Fase 1–3, pemeriksaan lintas fase (audit per versi, rentang versi rincian, 0 kunjungan/versi/peristiwa/notifikasi efektif ganda, ringkasan hanya dari versi Tercatat), mencocokkan laporan dengan kueri sumber, dan membandingkan jumlah baris tabel lama + sidik jari santri/wali/relasi.
5. Jalankan `checklist-smoke.md` dengan **akun dan santri sintetis** (tanpa data klinis nyata), lalu admin menugaskan petugas kesehatan nyata.
6. Aplikasi: Fase 4 menambah modul native `expo-screen-capture`; **build native baru wajib** (binary lama gagal tertutup di layar kesehatan). Web/API dipasang lebih dahulu; aplikasi baru sesudahnya. Butir Fase 4 yang terbuka (runtime iOS terbaru, ketukan Push provider, cold start non-dev) harus dibuktikan sebelum rilis toko.

Lokal teruji (`bukti/rilis.txt` S-1): salinan Tahap 7 dengan V5 dilepas → preflight → `up` (tepat 024, 025, 026) → verifier + manifest lulus → `up` kedua kosong → rollback 026/025/024 kosong → jumlah baris 86 tabel lama dan sidik jari identik dengan sebelum migrasi → migrasi ulang lulus.

## 4. Worker/cron

V5 memakai worker outbox V2 yang sama; tidak ada worker baru. InApp dibuat di dalam transaksi publikasi; worker Push memeriksa ulang status ringkasan, versi, relasi wali, status santri, dan sakelar Push tepat sebelum request provider. WhatsApp tetap **Ditangguhkan** (tidak pernah diantrekan untuk V5).

Contoh baris cron (sesuaikan jalur PHP/akun; log di luar document root; **belum dipasang**):

```cron
* * * * * /usr/local/bin/php /home/AKUN/public_html/bin/notifikasi_worker.php --kanal=push >> /home/AKUN/logs/notifikasi_worker.log 2>&1
*/15 * * * * /usr/local/bin/php /home/AKUN/public_html/bin/notifikasi_worker.php --receipts >> /home/AKUN/logs/notifikasi_receipt.log 2>&1
0 * * * * /usr/local/bin/php /home/AKUN/public_html/bin/v2_phase5_cron_check.php
```

Sebelum memasang: `php bin/notifikasi_worker.php --status` dan `--uji-coba` (tidak mengirim apa pun), `php bin/v2_phase5_cron_check.php`. Bukti lokal (Push/WhatsApp OFF, 0 request provider): `bukti/worker-cron.txt`. Pastikan user cron, working directory, `.env`/`PUSH_TOKEN_KEY` identik dengan PHP web, rotasi log, dan bahwa log tidak memuat isi notifikasi (judul/isi V5 generik dari katalog). Exactly-once provider saat jawaban jaringan hilang tidak dijamin; deduplikasi berlaku di outbox (peristiwa × kanal × penerima).

## 5. Rollback aman

- **Kode lebih dahulu.** Rollback utama adalah kode web ke restore point yang kompatibel; tabel V5 aditif boleh tetap ada (kode lama tidak membacanya). Aplikasi yang memanggil `/v5/kesehatan/laporan` pada server tanpa Fase 5 menerima 404 untuk layar laporan saja.
- **DB hanya bila tabel kosong.** Urutan 026 → 025 → 024, masing-masing: `php bin/v5_phaseN_rollback_preflight.php && php bin/migrate.php rollback`. Sejak Fase 5 **setiap berkas rollback V5 juga memuat penjaga SQL** (tabel `TEMPORARY` dengan `CHECK (jumlah = 0)` atas seluruh tabel fase itu; 026 juga notifikasi V5). Bila ada data, `migrate.php rollback` gagal sebelum DROP apa pun dan migrasi tetap tercatat (`bukti/rilis.txt` S-3, latihan S-4 f). Penjaga bergantung pada CHECK yang ditegakkan; pada MySQL < 8.0.16 CHECK diabaikan sehingga preflight menjadi satu-satunya penjaga — jangan rollback di versi tersebut.
- **Jika sudah ada catatan klinis/ringkasan:** jangan DROP/TRUNCATE paksa. Lakukan forward fix, atau restore konsisten dari backup terverifikasi setelah menilai data yang masuk sesudah restore point (catatan klinis baru akan hilang). Ringkasan yang pernah dilihat wali tidak boleh hilang diam-diam; koreksi lewat Ganti/Tarik beralasan.
- Penugasan kesehatan diakhiri lewat admin (bukan dihapus) bila layanan perlu dihentikan sementara; sesi lama kehilangan hak pada permintaan berikutnya.

## 6. Pemantauan sesudah rilis

- `php bin/v5_verify.php` hanya membaca dan aman dijalankan berkala; gagal = investigasi, jangan "membersihkan" data agar hijau.
- Laporan admin (`portal/kesehatan_laporan.php`, bagian Pengawasan): koreksi wajib-alasan tanpa alasan harus 0; antrean notifikasi kesehatan tidak menumpuk `Queued`.
