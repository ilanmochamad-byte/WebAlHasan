# Hasil pengujian implementator

27 September 2026. Satu agen Codex, tidak ada Claude Code/sub-agent. Database khusus `webalhasan_v3_phase1_test`, **MariaDB 12.3.2 lokal**, server PHP localhost:8940, Expo SDK 57 web localhost:8082, Chromium nyata dari Playwright. Data seluruhnya sintetis. Produksi/cPanel/perangkat Android+iOS tidak diuji sesi ini. Adapter fake tidak dihitung sebagai pengiriman nyata.

## Regresi akhir sebelum handoff

| Rangkaian | Lulus | Gagal | Status |
| --- | ---: | ---: | --- |
| V1/V2 dan fondasi seluruh runner bertingkat | 4.020 | 0 | LULUS OTOMATIS / LULUS UJI MYSQL lokal |
| V3 Fase 1 | 69 | 2 | BELUM TERPENUHI: diagnostics sehat/restore nonzero karena orphan lama |
| V3 Fase 2 | 172 | 0 | LULUS UJI MYSQL |
| V3 Fase 3 | 297 | 0 | LULUS UJI MYSQL |
| V3 Fase 4 | 122 | 0 | LULUS UJI MYSQL |
| V3 Fase 5 | 152 | 0 | LULUS UJI MYSQL (verifier bisnis V3; verifier global belum bersih) |
| PHP lint semua PHP baru/berubah | 29 | 0 | LULUS OTOMATIS |
| TypeScript seluruh mobile | 1 command | 0 | LULUS OTOMATIS |
| ESLint seluruh mobile | 1 command | 0 | LULUS OTOMATIS |
| Unit print-dialog mobile lama | 6 | 0 | LULUS OTOMATIS |
| Preflight Fase 5 | 5 | 0 | LULUS UJI MYSQL |

4.020 = runner V1/V2 2.378 + perapihan 248 + kredensial 215 + penempatan 257 + alumni 334 + fondasi penugasan 588. Ini jumlah pemeriksaan yang dieksekusi runner, bukan jumlah acceptance criteria unik. Jangan menjumlahkan ulang output verifier/runner bertingkat atau run pengembangan awal. Bukti mentah tersanitasi ada di [bukti](bukti/); tabel browser final ditambahkan setelah rangkaian berakhir.

Performa Fase 5: tepat 1.000 INSERT sintetis berpenanda run, daftar pertama 25 rows dari total 1.000 dalam **0,005095005035400391 detik**, 40 halaman menghasilkan 1.000 ID unik; ekspor 1.000 lengkap; 10.001 ditolak 422 tanpa berkas parsial. Semua ID fixture performa run dibersihkan tepat, FK tetap ON. Waktu ini lokal, **MENUNGGU PRODUKSI** untuk staging setara hosting dan target <2 detik di sana.

Migration 019/drill: enam pemeriksaan lulus, up idempoten, rollback indeks, hash seluruh isi tabel bisnis tetap, up kembali dan hash tetap, up kedua kosong. Drill 017/018 pada jendela migrasi lama juga lulus dan memulihkan 019. Tidak ada drill destruktif rollback 013 pada DB yang sudah berisi bisnis V3; suite Fase 1 yang berlaku static/integration/concurrency/diagnostics tetap dijalankan. Semua migrasi produksi **MENUNGGU PRODUKSI**.

Rekonsiliasi global exit nonzero: tiga relasi warisan, yaitu 96 outbox dengan parent izin hilang dan penerima hilang (baris outbox yang sama, bukan 192 baris unik), serta 24 audit login dengan actor hilang. Verifier tidak dilemahkan; pesan asal/waktu tak diketahui diperbaiki. Uji teardown ketiga fixture V2: 6 lulus, sebelum/sesudah `[96,24]`, tidak menambah atau menghapus residu. Poin/duplikasi/status/relasi V3 bisnis nol temuan. Indikator V3 tertunda >30 menit, publikasi tanpa wali aktif dan draf melewati retensi masing-masing 0 saat verifier dijalankan. Tidak menyatakan seluruh database bersih.

Receipt inspeksi hanya baca status/kode/jumlah. Data receipt lokal dari fake provider; bukan bukti Android/iOS atau provider produksi. Push OFF dan WhatsApp OFF menghitung nol request pada suite channels Fase 4; sakelar produksi tetap tidak disentuh.

## Perintah yang benar-benar dijalankan

Dari root WebAlHasan, konfigurasi credential tetap di luar repository:

```sh
DB_NAME=webalhasan_v3_phase1_test php bin/v3_phase4_preflight.php
DB_NAME=webalhasan_v3_phase1_test php bin/v3_phase4_verify.php
DB_NAME=webalhasan_v3_phase1_test php bin/v3_phase5_preflight.php
DB_NAME=webalhasan_v3_phase1_test php bin/migrate.php up
DB_NAME=webalhasan_v3_phase1_test V3_RUN_TESTS=1 php tests/v3_phase5_access.php
DB_NAME=webalhasan_v3_phase1_test V3_RUN_TESTS=1 php tests/v3_phase5_integration.php
DB_NAME=webalhasan_v3_phase1_test V3_RUN_TESTS=1 php tests/v3_phase5_performance.php
DB_NAME=webalhasan_v3_phase1_test V3_RUN_TESTS=1 php tests/v3_phase5_migration.php
DB_NAME=webalhasan_v3_phase1_test V3_RUN_TESTS=1 php tests/v3_phase5_fixture_teardown.php
DB_NAME=webalhasan_v3_phase1_test php bin/v3_phase5_verify.php
DB_NAME=webalhasan_v3_phase1_test MOBILE_APP_ROOT=/Users/ilanmochamad/alhasanApps bash bin/penugasan_run_all_tests.sh
DB_NAME=webalhasan_v3_phase1_test bash bin/v3_phase1_run_tests.sh
DB_NAME=webalhasan_v3_phase1_test bash bin/v3_phase2_run_tests.sh
DB_NAME=webalhasan_v3_phase1_test bash bin/v3_phase3_run_tests.sh
DB_NAME=webalhasan_v3_phase1_test bash bin/v3_phase4_run_tests.sh
DB_NAME=webalhasan_v3_phase1_test bash bin/v3_phase5_run_tests.sh
DB_NAME=webalhasan_v3_phase1_test php bin/v3_verify.php
DB_NAME=webalhasan_v3_phase1_test php bin/v3_push_receipts.php
PERAPIHAN_AUDIT_DB=1 node tests/browser/uji-v3-fase2.mjs
PERAPIHAN_AUDIT_DB=1 node tests/browser/uji-v3-fase3.mjs
PERAPIHAN_AUDIT_DB=1 node tests/browser/uji-v3-fase4.mjs
PERAPIHAN_AUDIT_DB=1 node tests/browser/uji-v3-fase5.mjs
PERAPIHAN_AUDIT_DB=1 node tests/browser/uji-v3-fase5-mobile.mjs
V3_BROWSER_YEAR_LABEL=$(DB_NAME=webalhasan_v3_phase1_test V3_RUN_TESTS=1 php tests/browser/seed-v3-phase5.php) PERAPIHAN_AUDIT_DB=1 node tests/browser/uji-v3-fase1.mjs
```

Runner otomatis V1/V2 juga menjalankan seluruh berkas yang namanya tercantum di bukti v1-v2-fondasi.txt, termasuk API HTTP, keamanan/unggahan, audit/transaksi, ledger, laporan, konkurensi dan fondasi; flags opt-in diatur runner masing-masing. Runner Fase 1–5 menjalankan semua suite tercantum di source runner. Drill memakai migrator secara internal, tidak command rollback produksi.

Dari root mobile:

```sh
npx tsc --noEmit
npm run lint
npm run test:print-dialog
CI=1 EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8940/api/v1 npx expo start --web --clear --port 8082
```

PHP lint dijalankan sebagai `php -l` untuk tiap 29 berkas di bukti php-lint.txt. Server web: `DB_NAME=webalhasan_v3_phase1_test PERAPIHAN_AUDIT_DB=1 php -S 127.0.0.1:8940 tests/v3_phase2_router.php`. Inspeksi read-only tambahan menghitung orphan/group tanggal/event serta `SELECT VERSION()`; tidak mencetak credential/token. `git status/log -5/branch/remote`, fetch kedua origin, ancestry baseline dan diff/check juga diperiksa.

Harness Expo web mengarahkan request API ke backend localhost sebelum request keluar, memblokir tujuan jaringan lain, dan menambahkan CORS hanya di browser harness. Konfigurasi/cache bundler sempat tetap memuat alamat API yang ada pada lingkungan perangkat; tidak dijadikan bukti akses produksi. Run UI final seluruh API diarahkan lokal. Keadaan kosong/503/retry/401 memakai respons terkontrol; operasi bisnis, scope dan konflik memakai backend/database nyata.

## Koreksi selama pengembangan

Probe awal menemukan options wali masih memperoleh katalog internal; izin Human Developer kemudian diberikan untuk koreksi baseline dan regresi. Kesalahan fixture test (alias murobi, metode acknowledge dan input preview), signature Denial dua argumen, static test yang mengasumsikan `private parentFrom`, guard drill migrasi lama dan fixture teardown diperbaiki sebelum run final. Browser Fase 1 kini menguji capability/endpoint yang memang sudah ada di Fase 5, tetap menolak mutasi kosong dan akses tanpa hak. Pembuatan ambang memakai tahun sintetis nonaktif agar tidak bertabrakan rentang fixture yang sudah ada; API baca tetap diuji pada tahun aktif. Fokus native pada web dikoreksi dan dibuktikan login/form UI. Run awal yang gagal tidak diklaim lulus.

Timeout20s/GET max3 ada pada client; pengujian jaringan native, keyboard/perangkat, deep-link cold start dan push nyata menggunakan panduan uji fisik. Safari/pembaca layar/UAT 95%, backup/cron/cPanel/smoke Fase 3 dan receipt final produksi belum memiliki bukti sesi ini.

## Bukti browser final

| Suite | Lulus | Gagal |
| --- | ---: | ---: |
| Expo web UI Fase 5 | 20 | 0 |
| Browser Fase 1 | 74 | 0 |
| Browser Fase 2 | 40 | 0 |
| Browser Fase 3 | 53 | 0 |
| Browser Fase 4 | 34 | 0 |
| Browser laporan Fase 5 | 55 | 0 |

Total browser **276 lulus / 0 gagal**. Empat peran laporan × 1440/375 px, HTML/CSV/cetak/PDF nyata; alur Expo web 375 px memakai backend lokal, dua sesi, data kasus sama di website, murobi, wali, IDOR, konflik versi dan keadaan kosong/gagal/retry/401. PDF dan screenshot tersimpan selama sesi di `/tmp/v3-phase5-browser/`, screenshot Expo web di `/tmp/v3-phase5-mobile/`; fixture output tidak dimasukkan sebagai bisnis produksi. Tidak membuktikan perangkat fisik.
