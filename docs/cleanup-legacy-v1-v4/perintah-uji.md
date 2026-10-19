# Reproduksi pengujian cleanup

Gunakan instance MariaDB baru tanpa jaringan (`--skip-networking`) dengan socket dan datadir privat. Jangan menggunakan `.env`, DB, storage, dump lampiran atau identitas produksi. Semua nama DB harus berakhiran `_test`. Akun aplikasi dibatasi ke database uji; suite restore lama memerlukan hak membuat database sementara `_test` di **instance uji tersebut**, bukan hak global/produksi.

Lingkungan eksekusi: `/private/tmp/cleanup-legacy-20261019/`, mode direktori 0700; konfigurasi/secret/token 0600. PHP 8.4.14, MariaDB 12.3.2, Node 26.7.0, Playwright 1.62.1/Chromium cache 1234. Tidak memasang/mengubah dependency. Socket `db.sock`, server preservasi `127.0.0.1:8985`, server V4 `127.0.0.1:8987`.

## Probe cleanup yang baru

1. Buat DB kosong `cleanup_legacy_test`. Ekstrak **hanya** CREATE/ALTER struktur dari berkas skema V1 yang sudah terlacak (`k1807225_webalhasan.sql`), tanpa INSERT/nilai baris; ekstrak ke lokasi privat di luar Git. Normalisasi AUTO_INCREMENT tabel ke 1 pada struktur sintetis tersebut. Jangan mengimpor dump pribadi. Terapkan migrasi 001–022 pada DB kosong melalui `php bin/migrate.php up`.
2. Set environment proses: `APP_ENV=testing`, `APP_DEBUG=false`, `DB_HOST=localhost`, `DB_NAME=cleanup_legacy_test`, akun SQL terbatas, `mysqli.default_socket` khusus lewat berkas INI privat. Isi `APP_BASE_PATH` kosong, `APP_URL=http://127.0.0.1:8985`, `SESSION_SECURE_COOKIE=false`, nama sesi uji khusus dan secret API acak. **Set `IZIN_LEGACY_ENABLED=true` pada proses PHP HTTP**, untuk membuktikan konfigurasi lama tidak membuka penulisan.
3. Buat fixture lama lalu sentinel cleanup, hanya di DB sintetis baru:

```sh
# Log seed lama memuat password fixture publik; simpan privat, jangan commit.
V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php > /private/tmp/cleanup-seed-private.log
CLEANUP_RUN_TESTS=1 php tests/cleanup_legacy_fixture.php
php bin/v2_phase1_backfill.php
# Backfill ini bagian setup fixture, bukan tindakan cleanup/prod.
```

4. Dari checkout/salinan uji, server di terminal tersendiri dengan environment DB yang sama:

```sh
php -S 127.0.0.1:8985 tests/v4_phase1_router.php
```

5. Jalankan probe pada localhost:

```sh
CLEANUP_RUN_TESTS=1 CLEANUP_TEST_URL=http://127.0.0.1:8985 php tests/cleanup_legacy_http.php
BASE_URL=http://127.0.0.1:8985 PERAPIHAN_AUDIT_DB=1 \
  CLEANUP_BROWSER_EVIDENCE=/private/tmp/cleanup-browser \
  node tests/browser/uji-cleanup-legacy.mjs
php tests/v2_phase2_static.php
```

Hasil diharapkan 156/0 HTTP/preservasi dan 26/0 browser. Jika skema bertambah, total assertion preservasi bertambah; jangan memaksakan angka lama. Output hanya label/hitungan agregat sintetis, tanpa nilai baris/password/token/cookie. Browser memakai helper audit dengan aset lokal dan memblokir origin lain. Screenshot yang dibuat hanya fixture sintetis, tidak dipakai sebagai tempat keluaran di direktori pekerjaan lain.

Uji HTTP memerlukan sentinel nonkosong. Ia login sebelum snapshot, membaca seluruh tabel dan membandingkan jumlah serta sidik jari representasi seluruh kolom/ID, lalu 14 pemeriksaan relasi/nilai warisan dan jumlah akun wali. Ia tidak menghapus fixture DB; hanya menghapus cookie sementara miliknya.

## Regresi yang benar-benar dijalankan

Regresi dijalankan pada **salinan sumber** di `/private/tmp/cleanup-legacy-20261019/web`, bukan worktree Git. Salinan berasal dari berkas terlacak ditambah perubahan cleanup/test baru, tanpa `.env`, dump berisi data, storage privat lama, atau tiga direktori untracked pekerjaan lain. Vendor/Playwright yang terpasang dipakai dari lock yang sama. Semua output/fixture lama yang hardcode `/private/tmp/v4-fase*` diubah pada **harness salinan saja** menjadi `/private/tmp/cleanup-legacy-20261019/v4-fase*`. Guard nama DB V4 di salinan menunjuk `cleanup_v4_final_test`, tetap hanya DB uji. Tidak mengubah assertion, service/core, atau file harness asli agar cocok dengan fixture.

V1–V3: DB `webalhasan_v3_phase1_test`, struktur kosong + 001–022 + seed SBX, tambah satu semester Genap nonaktif sintetis. Nyalakan opt-in `PHASE2_RUN_INTEGRATION`, `PHASE3_RUN_INTEGRATION`, `PHASE4_RUN_INTEGRATION`, `PHASE5_RUN_INTEGRATION`, `PENUGASAN_RUN_INTEGRATION`, `V2_PHASE1_RUN_INTEGRATION`, `V2_PHASE2_RUN_INTEGRATION`, `V2_PHASE4_RUN_INTEGRATION`, `V2_PHASE5_RUN_INTEGRATION`, `PERAPIHAN_RUN_INTEGRATION`, `KREDENSIAL_RUN_INTEGRATION`, `PENEMPATAN_RUN_INTEGRATION`, `ALUMNI_RUN_INTEGRATION`, dan `V3_RUN_TESTS` bernilai 1. Daftar suite persis pada `regresi-v1-v3.txt`; jalankan satu per satu, catat exit code, jangan hanya menghitung label lulus. HTTP V2 memakai `V2_PHASE2_RUN_WEB=1`, port `8986`.

V4: DB sintetis baru `cleanup_v4_final_test`, struktur kosong + 001–022. Siapkan admin ID 1, lima semester (ID 4 aktif), tiga kelas (ID 2 Tsanawi; ID 3 Ibtida), 30 santri sintetis di kelas 2/semester 4, satu wali kosong, kemudian `tests/v4_phase1_seed.php` dengan password acak privat. Jadwal sintetis kelas 2/semester 4 untuk guru fixture menggunakan enum sah `Ba'da Isya`, Senin 20:00–21:00. Urutan:

- `v4_phase1_integration`, `v4_phase2_integration`, `v4_phase2_concurrency`, `v4_phase3_seed`, `v4_phase3_integration`, `v4_phase3_concurrency`.
- Sediakan server port 8987, `V4_TEST_URL`, `V4_HTTP_TOKENS` dan `V4_TEST_PASSWORD` privat yang cocok akun fixture. `v4_phase5_seed` dipakai hanya untuk menyamakan password seluruh akun V4 sintetis sebelum browser; password admin sintetis juga disamakan.
- `tests/browser/uji-v4-fase3.mjs`, **baru** `v4_phase3_transaction` (browser menyelesaikan prasyarat status bahan), lalu `v4_phase4_seed`, `v4_phase4_integration`.
- Isi `PUSH_TOKEN_KEY` dengan 32 byte acak base64 privat, lalu `v4_phase4_push` (mock saja), `v4_phase4_concurrency`.
- Python bundled dengan pypdf/pypdfium2 menjalankan `tests/v4_phase4_pdf.py` untuk mengekstrak kode verifikasi dari PDF sintetis; ini prasyarat `v4_phase4_lifecycle`.
- `v4_phase4_lifecycle`, `v4_phase4_document`, `v4_phase4_audit_claude`, `v4_phase5_projection`.

Semua file/hook/trigger kegagalan dan koreksi status suite tersebut hanya berlaku pada DB regresi sintetis, terpisah dari DB preservasi. Dua setup V4 awal yang belum lengkap ditinggalkan sebagai bukti privat; tidak menghapus residu historis agar verifier lulus.

Tidak menjalankan script runbook lama secara buta karena beberapa path output historis menunjuk folder fase lain. Jangan menggunakan `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, atau `output/` sebagai tempat hasil tes.
