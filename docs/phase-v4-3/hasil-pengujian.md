# Perintah dan hasil pengujian

Seluruh perintah dijalankan lokal 16 Oktober 2026, bukan produksi. MariaDB 12.3.2 memakai datadir baru `/private/tmp/webalhasan-v4-fase3-db`, socket `/private/tmp/webalhasan-v4-fase3.sock`, `--skip-networking`. PHP 8.4.14. Bind socket/HTTP dan akses jaringan Git awal terhalang sandbox; dijalankan ulang melalui eskalasi alat yang disetujui. Tidak ada penolakan auto-review yang masih memblokir pekerjaan.

## Persiapan dan reproduksi

DB bisnis uji bernama **`webalhasan_v4_fase2_test`** karena guard suite Fase 2 memang mensyaratkan nama itu; instance/socket/datadir tetap khusus Fase 3, terpisah dari instance Codex/Claude fase sebelumnya. Impor dump repo `k1807225_webalhasan.sql` ke DB baru. Rangkaian stateful harus dimulai dari fixture baru, bukan dijalankan di produksi/DB yang berisi pekerjaan lain. Salinan uji percobaan sebelumnya dicadangkan privat ke `/private/tmp/v4-fase3-before-final.sql` sebelum rangkaian akhir dibuat ulang. DB regresi V1–V3 tidak dibersihkan untuk menyembunyikan residu.

Konfigurasi aktual (secret/password hanya tersimpan privat, tidak dimasukkan bukti):

```sh
export DB_HOST=localhost DB_USER=root DB_PASSWORD='' DB_NAME=webalhasan_v4_fase2_test
export APP_ENV=testing APP_DEBUG=false
export API_TOKEN_HASH_SECRET='<secret-uji>' V4_TEST_PASSWORD='<password-uji-minimal-12-karakter>'
export V4_HTTP_TOKENS=/private/tmp/v4-fase3-tokens.json V4_TEST_URL=http://127.0.0.1:8963
export PHP_INI_SCAN_DIR=:/private/tmp/v4-fase3-php-ini
export V4_EVIDENCE_DIR=docs/phase-v4-3 V4_SCREENSHOT=docs/phase-v4-3/regresi-fase1-375.png
# /private/tmp/v4-fase3-php-ini/socket.ini:
# mysqli.default_socket=/private/tmp/webalhasan-v4-fase3.sock
```

Gunakan `/opt/homebrew/bin/php` pada lingkungan ini. Berikut urutan aktual rangkaian akhir (semua exit 0 kecuali penolakan rollback yang memang diharapkan):

```sh
php bin/migrate.php up
php bin/v4_phase1_preflight.php /private/tmp/v4-fase3-baseline.json
php bin/migrate.php up
php bin/v4_phase1_verify.php /private/tmp/v4-fase3-baseline.json
php tests/v4_phase1_seed.php /private/tmp/v4-fase3-tokens.json
php tests/v4_phase1_integration.php
# Server proses terpisah, env sama:
php -S 127.0.0.1:8963 -t . tests/v4_phase1_router.php
node tests/browser/uji-v4-fase1.mjs
php tests/v4_phase2_integration.php
php tests/v4_phase2_web_seed.php
php tests/v4_phase2_concurrency.php
node tests/browser/uji-v4-fase2.mjs
node tests/browser/uji-v4-fase2-guru.mjs
php tests/v4_phase3_seed.php
php tests/v4_phase3_integration.php
php tests/v4_phase3_concurrency.php
node tests/browser/uji-v4-fase3.mjs
php tests/v4_phase3_transaction.php
php bin/v4_phase3_verify.php
php bin/v4_phase3_rollback_preflight.php # exit 1: data bisnis ada
php bin/migrate.php rollback           # exit 1: guard SQL menolak data bisnis
php bin/v4_phase3_verify.php            # exit 0: skema/versi/riwayat tetap utuh
```

`tests/v4_phase3_seed.php` menambah empat akun sintetis/penugasan dan token privat untuk pembimbing/murobi dalam/luar cakupan. Alias seluruh nama santri kelas uji dipakai agar screenshot tidak memuat identitas salinan dump. ID fixture noncredential di `/private/tmp/v4-fase3-ids.json`; token tidak dicetak. Hanya suite uji yang mengubah penugasan/relasi untuk pengujian pencabutan, lalu memulihkannya melalui `finally`.

| Suite | Exit | Hasil akhir / bukti |
| --- | --- | --- |
| Fase 3 integrasi | 0 | 70 lulus, `integrasi-mariadb.txt` |
| Fase 3 transaksi/audit tahap akhir | 0 | 9 lulus, `transaksi-audit.txt` |
| Fase 3 konkurensi | 0 | 4 lulus, `konkurensi.txt`: dua putaran enam proses PHP/koneksi DB masing-masing, satu 200 + lima 409 |
| Fase 3 HTTP/browser | 0 | 56 lulus, `http-browser.txt`; tiga screenshot `browser-*-375.png` |
| Fase 1 integrasi / HTTP | 0 | 29 / 23 lulus, `regresi-fase1*.txt` |
| Fase 2 integrasi / konkurensi | 0 | 54 / 2 lulus, `regresi-fase2.txt`, `regresi-fase2-konkurensi.txt` |
| Fase 2 HTTP pengganti / guru | 0 | 25 / 8 lulus, `regresi-fase2-http*.txt` |
| Verifier 020 + FK V4 + manifest lama | 0 | `verifier.txt`, nol referensi yatim V4 sebelum fixture bisnis; baris V1–V3 tidak berubah saat migrasi |
| Verifier 021 | 0 | 7 lulus, `verifier-fase3.txt`; setelah penolakan rollback juga lulus |
| Latihan rollback kosong → up → up | 0 | `rollback-latihan.txt`, hanya DB `webalhasan_v4_schema_test` terpisah |
| Penolakan rollback berisi data | 1 sesuai harapan | `rollback-ditolak.txt`, `rollback-guard-sql.txt`; skema dan data tetap |

Uji kegagalan audit awal dan akhir sengaja memunculkan pesan generik `Audit log gagal disimpan.`; ini bukan kegagalan suite. Trigger pertama menggagalkan audit awal. Trigger kedua hanya menggagalkan `v4.bahan.*` setelah penulisan komponen/sikap dan audit pertamanya berhasil. Uji membuktikan semua baris bisnis, riwayat, dan audit sukses pertama ikut digulung.

Browser Chromium headless memeriksa 375 px, font 24 px, desktop 1440 px, XSS tidak berjalan, CSRF 419, bearer 401, akses silang 403, pencabutan penugasan pada request HTTP baru, dan konflik versi 409. Screenshot tiga peran diperiksa visual; penantian transisi sidebar ditambahkan sebelum screenshot setelah pergantian viewport. Tombol/form tetap dapat digunakan. Belum ada pengujian browser Safari atau perangkat fisik.

Percobaan awal: `http-browser-awal.txt` gagal pada ekspektasi adab Baik padahal fixture integrasi sudah dikoreksi murobi ke Sangat Baik. `http-browser-hash-awal.txt` gagal pada gate karena klien menghitung serialisasi JSON berbeda dari PHP; API kini menyediakan hash matriks server dan klien uji memakainya. Kedua log dipertahankan. Perbaikan cakupan kamar dan tampilan alasan pengembalian diperiksa dalam rangkaian akhir dari fixture baru. Bukti fase sebelumnya dipertahankan; dua suite browser Fase 2 hanya ditambah pilihan direktori screenshot agar regresi tidak menimpa artefak lama.

## Regresi V1–V3

DB baru `webalhasan_v3_phase1_test` pada instance/socket Fase 3. CREATE/ALTER tanpa INSERT diekstrak dari dump repo (`/private/tmp/v4-regression-schema.sql`), migrasi 001–021 diterapkan, fixture resmi `V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php`, dan satu semester nonaktif sintetis `2026/2027 Genap` ditambahkan sebagaimana kebutuhan fixture yang dicatat Fase 2. Socket diwariskan worker melalui `PHP_INI_SCAN_DIR` sejak awal; tidak melonggarkan guard.

```sh
export DB_NAME=webalhasan_v3_phase1_test V3_RUN_TESTS=1
export PHASE2_RUN_INTEGRATION=1 PHASE3_RUN_INTEGRATION=1 PHASE4_RUN_INTEGRATION=1 PHASE5_RUN_INTEGRATION=1
export PENUGASAN_RUN_INTEGRATION=1
export V2_PHASE1_RUN_INTEGRATION=1 V2_PHASE2_RUN_INTEGRATION=1 V2_PHASE4_RUN_INTEGRATION=1 V2_PHASE5_RUN_INTEGRATION=1
# Setiap baris regresi-ringkasan.txt berasal dari:
php tests/<nama-suite>.php
```

Baris password sintetis bawaan seeder resmi disamarkan di `regresi-seed.txt`; tidak ada perubahan pada hasil/exit uji.

Seluruh **14 suite integrasi exit 0**, **14 dari 15 suite statis exit 0**. Daftar tepat dan exit tersedia di `regresi-ringkasan.txt`; keluaran setiap suite `regresi-<nama-suite>.txt`. `phase5_static` masih exit 1 pada path mobile lama `/src/app/(app)/(reports)/reports.tsx`, sama dengan baseline audit. Tidak ada klaim seluruh regresi hijau atau seluruh residu fixture lama bebas referensi yatim.

## Pemeriksaan akhir

PHP lint pada seluruh file PHP produksi/uji yang diubah/ditambahkan dan `git diff --check`: lihat `lint.txt`. Diff ditinjau terhadap `fe2ed92`, termasuk file baru, akses, batas fase, atribusi, audit atomik, skema dan rollback. `SHA256SUMS` merekam sumber dan bukti dalam commit. Tidak ada Claude Code yang dijalankan bersamaan, tidak ada perubahan mobile, worktree, merge atau deploy.
