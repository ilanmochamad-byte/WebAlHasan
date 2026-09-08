# Hasil pengujian audit independen fondasi V3–V6

Audit Codex, 7–8 September 2026. Branch `codex/audit-fondasi-penugasan-v3-v6`
dari merge `7edb6461621763ae1b4ffc3dbb54f68615fa3521`; implementasi yang
diaudit `c6ed53bc15c2f26cb8cf6a570e9e665cabf60d17`, setelah
`1653ac4392913258aedd44259fcc0d85036a5b44`.

**LULUS untuk fondasi penugasan V3–V6.** Pengujian otomatis lokal, migrasi
MariaDB cPanel, serta smoke interaksi produksi dan Safari lulus. Lihat
[status penerimaan dan temuan](acceptance-status.md).
Dokumen ini menggantikan laporan implementator dengan hasil yang benar-benar
dijalankan auditor; laporan lama masih tersedia dalam riwayat Git.

## Lingkungan dan batas bukti

- PHP 8.4.14 CLI, MariaDB 12.3.2 lokal, database baru
  `codex_penugasan_20260907_test`. Semua pengujian DB menggunakan nama `_test`.
- Skema dasar berasal dari 60 pernyataan CREATE/ALTER SQL warisan repository.
  Tidak mengimpor INSERT atau data produksi. Seluruh migrasi 001–012 diterapkan,
  kemudian fixture sandbox fiktif serta 1.000 data performa disiapkan.
- Migrasi 001 memerlukan tabel dasar; rangkaian 001–012 bukan bootstrap pada
  database tanpa tabel. Yang lulus adalah migrasi di atas **skema dasar kosong**.
- Mobile tersedia di `/Users/ilanmochamad/alhasanApps`, HEAD
  `bad6b352c5c032846fbf971d820194fdd8c6bc49`; tidak diubah.
- Pengujian otomatis memakai server localhost dan Chromium headless dengan aset
  lokal; permintaan eksternal browser diblokir. Smoke lanjutan memakai deploy
  produksi setelah koreksi digabung oleh pengguna melalui `735dc6d`.
- Tes integrasi menghapus fixture mereka; fixture sandbox/perapihan tetap ada
  dalam DB uji. Browser mengarsipkan mapel sintetis yang dibuat. Jangan mengklaim
  seluruh DB kembali kosong setelah semua tes.

## Paket wajib dan pengujian koreksi

| Perintah | Hasil sebenarnya |
| --- | --- |
| `php tests/penugasan_static.php` | LULUS, 281 |
| `php tests/penugasan_integration.php` | LULUS, 161 |
| `php tests/penugasan_concurrency.php` | LULUS, 52; proses PHP dan koneksi InnoDB terpisah |
| `php tests/penugasan_web_smoke.php` | LULUS, 85; HTTP, CSRF, role, IDOR, XSS, profil dan halaman lama |
| `php bin/penugasan_preflight.php` | LULUS, exit 0, tidak ada penghalang lokal |
| `php bin/penugasan_verify.php --murobi=3 --pembimbing=3` | LULUS, 154 termasuk dua perbandingan jumlah eksplisit |
| `php tests/penugasan_resolver_failure.php` | LULUS, 5; driver sintetis `get_result=false`, bukan koneksi nyata |
| `bash tests/penugasan_runner.sh` | LULUS, 3; perintah stub, membuktikan tes dilewati tidak dianggap lulus penuh |
| `bash bin/penugasan_run_all_tests.sh` dengan `MOBILE_APP_ROOT` | LULUS, exit 0; 43 suite regresi (3.421 pemeriksaan) dan 6 suite paket (587 pemeriksaan); tanpa skip |
| `PENUGASAN_RUN_MIGRATION=1 php tests/penugasan_migration.php` | LULUS, 58; drill terpisah, tabel baru fondasi harus kosong |

**741 pemeriksaan paket** = 281 + 161 + 52 + 85 + 5 + 3 + 154.
Preflight, 58 drill migrasi, browser, regresi V1/V2, dan mobile tidak dimasukkan
ke angka 741. Penjumlahan ini menunjukkan jumlah assertion, bukan persentase
penerimaan produk.

Klaim 681 implementator diverifikasi: empat suite awal lulus 527
(281 + 161 + 12 + 73), ditambah 154 verifikasi eksplisit. Runner awal memakai
verify tanpa argumen jumlah, sehingga hanya 152 assertion verify di log runner;
lihat log verify eksplisit terpisah untuk dua pemeriksaan tambahan. Kelulusan
pengujian awal tidak mendeteksi celah yang ditemukan audit ini.

Bukti: [baseline](bukti-audit-codex/baseline.txt),
[runner akhir](bukti-audit-codex/final-suite.txt),
[verify eksplisit](bukti-audit-codex/verify-final.txt).
Runner hanya mencetak ringkasan suite yang lulus; hitungan tidak dikarang dari
inspeksi kode. Tidak ada kegagalan/skip tersisa pada run akhir.

## Konkurensi dan koreksi yang dibuktikan

KP-1…5 menguji pembuatan identik/overlap, dua pengaktifan bentrok, audit
transaksi yang kalah, serta beberapa cakupan sah. Tambahan KA-1…12 membuktikan:

- Muatan lama murobi/pembimbing melawan pusat: tepat satu penugasan untuk
  periode bentrok; tanggal terbalik ditolak. Aktif/nonaktif/arsip/pulihkan tanpa
  alasan ditolak tanpa mutasi; formulir HTTP dengan alasan juga diuji.
- Pulihkan arsip yang bertabrakan ditolak dan arsip tetap utuh; aktivasi lama
  melawan pusat hanya menghasilkan satu penugasan aktif.
- Dua baris berubah ke cakupan yang sama, atau tanggal yang menjadi beririsan:
  tepat satu operasi berhasil. `akhiri` tidak dapat memperpanjang ke periode lain.
- Dua pembuatan berbeda saat trigger audit sengaja gagal: kedua transaksi batal,
  tanpa baris/audit bisnis parsial; trigger dibuang pada cleanup.
- Lock timeout 1205 dan deadlock 1213 nyata dipaksa pada repository pusat,
  murobi, pembimbing, akun, penempatan, alumni: gagal baca tidak menjadi data
  kosong; konflik memakai pesan muat ulang, bukan pesan MySQL mentah.
- Cakupan kamar tidak memberikan hak jenjang; aktivasi setelah master nonaktif
  ditolak. Kegagalan resolver sintetis tidak memberikan hak/fatal boolean.

Tes layanan lama V2 disesuaikan dari pencarian implementasi lama menjadi
pemeriksaan delegasi, validasi master/target, dan audit di pusat. Kriteria
bisnis tidak dihapus. Seluruh penolakan benturan domain tetap diaudit setelah
rollback; kegagalan infrastruktur tidak disamarkan sebagai penolakan bisnis.

## Regresi V1/V2 dan paket sebelumnya

Dijalankan oleh rantai runner lengkap, termasuk seluruh suite resmi V1/V2,
perapihan, kredensial, penempatan, dan alumni:

| Suite | Status | Pemeriksaan |
| --- | --- | --- |
| `tests/phase1_static.php` | LULUS | 75 |
| `tests/phase2_static.php` | LULUS | 46 |
| `tests/phase3_static.php` | LULUS | 35 |
| `tests/phase4_static.php` | LULUS | 38 |
| `tests/phase5_static.php` | LULUS | 44 |
| `tests/v2_phase1_static.php` | LULUS | 127 |
| `tests/v2_phase2_static.php` | LULUS | 174 |
| `tests/v2_phase3_static.php` | LULUS | 147 |
| `tests/v2_phase4_static.php` | LULUS | 289 |
| `tests/v2_phase5_static.php` | LULUS | 272 |
| `tests/v2_phase5_cetak_pdf.php` | LULUS | 76 |
| `tests/phase2_integration.php` | LULUS | 12 |
| `tests/phase3_integration.php` | LULUS | 10 |
| `tests/phase4_integration.php` | LULUS | 14 |
| `tests/phase5_integration.php` | LULUS | 20 |
| `tests/v2_phase1_integration.php` | LULUS | 39 |
| `tests/v2_phase2_integration.php` | LULUS | 94 |
| `tests/v2_phase2_navigasi_murobi.php` | LULUS | 40 |
| `tests/v2_phase2_web_smoke.php` | LULUS | 36 |
| `tests/v2_phase3_api_contract.php` | LULUS | 116 |
| `tests/v2_phase4_integration.php` | LULUS | 122 |
| `tests/v2_phase4_api_contract.php` | LULUS | 92 |
| `tests/v2_phase4_concurrency.php` | LULUS | 20 |
| `tests/v2_phase4_web_smoke.php` | LULUS | 46 |
| `tests/v2_phase5_integration.php` | LULUS | 143 |
| `tests/v2_phase5_api_contract.php` | LULUS | 149 |
| `tests/v2_phase5_web_smoke.php` | LULUS | 79 |
| `tests/v2_phase5_performance.php` | LULUS | 12 |
| `tests/perapihan_static.php` | LULUS | 132 |
| `tests/perapihan_integration.php` | LULUS | 53 |
| `tests/perapihan_akun_concurrency.php` | LULUS | 7 |
| `tests/perapihan_web_smoke.php` | LULUS | 56 |
| `tests/kredensial_static.php` | LULUS | 105 |
| `tests/kredensial_integration.php` | LULUS | 59 |
| `tests/kredensial_web_smoke.php` | LULUS | 51 |
| `tests/penempatan_static.php` | LULUS | 138 |
| `tests/penempatan_integration.php` | LULUS | 62 |
| `tests/penempatan_concurrency.php` | LULUS | 10 |
| `tests/penempatan_web_smoke.php` | LULUS | 47 |
| `tests/alumni_static.php` | LULUS | 180 |
| `tests/alumni_integration.php` | LULUS | 80 |
| `tests/alumni_concurrency.php` | LULUS | 13 |
| `tests/alumni_web_smoke.php` | LULUS | 61 |

Tidak ada lagi kegagalan akibat repository mobile tidak tersedia. Jumlah
beberapa suite berbeda dari laporan implementator karena audit berangkat dari
merge terbaru dan repository mobile yang benar-benar tersedia; tabel di atas
mengikuti output run akhir, bukan angka lama.

Tambahan audit perapihan yang juga dijalankan (852 pemeriksaan, di luar runner):

| Suite | Status | Pemeriksaan |
| --- | --- | --- |
| `perapihan_audit_account_log.php` | LULUS | 36 |
| `perapihan_audit_admin.php` | LULUS | 13 |
| `perapihan_audit_api_compat.php` | LULUS | 12 |
| `perapihan_audit_csv_limit.php` | LULUS | 4 |
| `perapihan_audit_form_feedback.php` | LULUS | 108 |
| `perapihan_audit_http.php` | LULUS | 25 |
| `perapihan_audit_kamar.php` | LULUS | 19 |
| `perapihan_audit_laporan_web.php` | LULUS | 38 |
| `perapihan_audit_merge.php` | LULUS | 4 |
| `perapihan_audit_notifikasi.php` | LULUS | 18 |
| `perapihan_audit_pagination.php` | LULUS | 45 |
| `perapihan_audit_redirect.php` | LULUS | 36 |
| `perapihan_audit_report_matrix.php` | LULUS | 432 |
| `perapihan_audit_wali.php` | LULUS | 16 |
| `perapihan_audit_wali_long_list.php` | LULUS | 46 |

Log individual ada di [direktori bukti](bukti-audit-codex/README.md).
Percobaan awal beberapa smoke HTTP tambahan gagal karena router server uji
mengembalikan 404 untuk URL direktori `/portal/` dan URL dasar tidak konsisten.
Server uji diperbaiki, lalu seluruh suite gagal dijalankan ulang dan lulus;
bukan kegagalan aplikasi yang disembunyikan. Regresi feedback formulir juga
dijalankan ulang setelah alasan wajib ditambahkan pada halaman lama.

## Migrasi dan rollback

1. CREATE/ALTER skema dasar tanpa data → migrasi 001–012: LULUS.
2. Migrasi 012 pada MariaDB cPanel produksi: LULUS untuk migrasi maju dan
   verifikasi pascadeploy. Server `10.6.27-MariaDB-cll-lve`; preflight tidak
   menemukan penghalang; verify dengan jumlah aktual murobi 1/pembimbing 9
   menyelesaikan 154 pemeriksaan dan exit 0. Percobaan sebelumnya memakai
   ekspektasi murobi 12 yang salah sehingga satu pembandingan gagal; tidak ada
   kegagalan skema atau kehilangan data yang teramati.
3. Runner migrasi ulang dan SQL guarded 012 dijalankan langsung ulang: LULUS.
4. Rollback 012: enam tabel fondasi dilepas; seluruh ID/nilai/jumlah kolom lama
   murobi/pembimbing identik dengan snapshot sebelum drill.
5. Migrasi ulang setelah rollback: LULUS, nilai lama tetap, tanpa backfill.
6. Verify memeriksa FK, indeks, CHECK, empat role, lima kolom jejak tambahan dan
   kolom lama. Drill memeriksa 29 relasi FK tanpa yatim dan membuktikan CHECK
   tanggal serta FK subjek benar-benar menolak INSERT invalid pada lima tabel.
7. Transaksi/konkurensi nyata MariaDB: LULUS sebagaimana bagian sebelumnya.

Bukti: [migrasi awal](bukti-audit-codex/migration-initial.txt),
[drill 58 pemeriksaan](bukti-audit-codex/migration-drill.txt), verify di atas.
Skema/data SQL warisan repository tidak dianggap salinan produksi representatif.
Bukti cPanel berasal dari produksi yang benar-benar dideploy. Rollback tidak
dijalankan pada produksi; siklus rollback/migrasi ulang tetap dibuktikan pada DB
uji terpisah. MariaDB produksi memakai zona waktu sistem `WIB`, sesi `SYSTEM`,
dan tanggal DB yang sesuai. SQL migrasi/rollback tidak diubah. Lihat
[panduan migrasi](migrasi-dan-rollback.md).

## Browser, aksesibilitas, dan mobile

`tests/browser/uji-penugasan.mjs`: **78 pemeriksaan lulus** di Chromium headless,
8 tab × 3 lebar (1440/768/390), HTTP 200, label, overflow halaman/form, keyboard
Tab/Enter, pesan tanggal invalid dan retensi isian, serta tidak ada galat JS.
Data tabel panjang memiliki wadah scroll tersendiri; formulir utama tidak
scroll horizontal. Smoke HTTP FW-12 membuktikan escape data berbahaya.

Tombol Simpan mapel terpotong ditemukan pada 768 px dengan data terisi,
diperbaiki, lalu seluruh 78 pemeriksaan browser diulang dan lulus. Label
kontrol orang/tahun pada mode ubah juga diperbaiki. Screenshot akhir yang
diperiksa visual: [desktop](bukti-audit-codex/1440-guru_mapel.png),
[tablet](bukti-audit-codex/768-mata_pelajaran.png),
[390 px](bukti-audit-codex/390-murobi.png).
[Bukti JSON](bukti-audit-codex/browser-results.json) dan
[output browser](bukti-audit-codex/browser.txt) disimpan.

Safari produksi diuji melalui sesi Administrator: desktop, Responsive Design
Mode 768 px, dan 390 px. Menu responsif dapat dibuka/ditutup, tab penugasan dapat
dipindah, label kontrol terbaca pada accessibility tree, fokus keyboard mencapai
kontrol formulir, dan formulir utama tidak memunculkan scroll horizontal. Smoke
interaksi juga memeriksa pesan benturan, perubahan, aktif/nonaktif, pengakhiran,
arsip, dan akses role. VoiceOver fisik dan Safari iOS belum dijalankan.

Mobile: `npm run lint`, `./node_modules/.bin/tsc --noEmit`, dan
`npm run test:print-dialog` semuanya exit 0; 6 tes cetak lulus. Node memberi
peringatan tipe modul pada file tes, tanpa kegagalan. Client asli juga diuji
18 pemeriksaan via `perapihan_audit_notifikasi.php`.
[Bukti mobile](bukti-audit-codex/mobile-checks.txt).

FI-19/20, FW-10, dan seluruh kontrak API V1/V2 membuktikan field lama, role,
mode, `default_mode`, jadwal, absensi, laporan, serta perizinan tetap tersedia;
capability baru tambahan dan bukan menu V3–V6. Tidak ditemukan kebutuhan
perubahan kode mobile. Sesi produksi Orang Tua, Guru, dan Pengurus juga memuat
halaman yang sesuai, tidak menampilkan menu bisnis V3–V6, dan menerima 403 saat
membuka halaman admin langsung. Smoke aplikasi Android/iOS terpasang belum
dijalankan.

## Smoke interaksi produksi dan audit

Smoke 8 September 2026 hanya memakai akun serta data berawalan `SMOKE AUDIT`.
Data lama tidak diubah. [Bukti ringkas produksi](bukti-audit-codex/production-smoke-20260908.md)
mencatat detail tanpa kredensial atau token sesi. Hasil yang diverifikasi:

- master mata pelajaran dan tujuh jenis penugasan dapat dibuat; duplikasi/
  periode tumpang tindih ditolak pada pusat serta halaman lama murobi dan
  pembimbing dengan pesan domain yang dapat dipahami;
- perubahan, aktif/nonaktif, pengakhiran, arsip murobi/pembimbing, dan arsip
  mata pelajaran konsisten di halaman pusat/lama; penugasan uji lain berakhir
  dan nonaktif karena UI fondasi tidak menyediakan arsip untuk jenis tersebut;
- profil role dasar tetap empat; sesi Orang Tua, Guru, dan Pengurus tidak
  memperoleh menu V3–V6 atau akses admin;
- `audit_logs` memuat 34 mutasi untuk delapan entitas uji yang tepat, seluruhnya
  oleh Administrator, termasuk pembuatan, perubahan, status, pengakhiran, dan
  arsip. Terdapat 20 audit `penugasan.capability_berubah` dengan pemicu entitas
  yang tepat serta capability bertambah/berkurang, dan tiga audit
  `penugasan.tolak_tumpang_tindih` untuk guru mapel, murobi, dan pembimbing;
- seluruh data uji yang dapat diarsipkan melalui UI telah diarsipkan; sisanya
  ditinggalkan dalam keadaan berakhir dan nonaktif. Tidak ada hard delete.

## Reproduksi lokal

Set `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` ke database uji terpisah,
`APP_ENV=testing`, `APP_URL` ke localhost, dan
`MOBILE_APP_ROOT=/Users/ilanmochamad/alhasanApps`. Jangan memakai konfigurasi
produksi. Siapkan skema dasar tanpa data, jalankan migrasi dan fixture:

```sh
php bin/migrate.php up
V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php
V2_PHASE5_FIXTURE=1 php bin/v2_phase5_fixture.php --jumlah=1000
PENUGASAN_RUN_MIGRATION=1 php tests/penugasan_migration.php
bash bin/penugasan_run_all_tests.sh
php bin/penugasan_verify.php --murobi=3 --pembimbing=3
```

Drill dilakukan sebelum browser menambah mapel. Untuk browser, jalankan server
PHP localhost dengan konfigurasi DB yang sama; `BASE_URL` harus cocok dengan
`APP_URL`. Jalankan dari folder repository:

```sh
PERAPIHAN_AUDIT_DB=1 BASE_URL=http://127.0.0.1:8879 node tests/browser/uji-penugasan.mjs
```

Dependensi browser berada di `tests/browser/package.json`; Chromium harus sudah
tersedia. Tes ini hanya memakai akun fixture sandbox. Regresi audit perapihan
tambahan memerlukan manifest fixture perapihan/UI dan router localhost yang
melayani `/portal/` serta `/api/v1` sebagaimana konfigurasi web sebenarnya.
Audit berhenti pada fondasi; tidak mengimplementasikan PRD V3.
