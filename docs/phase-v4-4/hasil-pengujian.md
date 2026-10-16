# Perintah dan hasil aktual

Semua pengujian lokal pada 16 Oktober 2026 (Asia/Jakarta), bukan produksi. MariaDB 12.3.2 memiliki datadir `/private/tmp/webalhasan-v4-fase4-db`, socket `/private/tmp/webalhasan-v4-fase4.sock`, `--skip-networking`. PHP 8.4.14 dan server `http://127.0.0.1:8964`. Eskalasi alat dipakai untuk socket/HTTP lokal dan jaringan Git/Composer; tidak ada penolakan auto-review yang masih memblokir.

## Reproduksi

Database bisnis bernama `webalhasan_v4_fase2_test` karena guard suite Fase 2–3 mensyaratkannya. Instance Fase 4 terpisah dari uji fase/auditor lain. Dump repo diimpor; hanya kelas/akun/identitas/aset sintetis yang dimasukkan bukti. Sebelum screenshot/PDF publikasi, seluruh nama/NIS kelas uji diganti menjadi sintetis. Semua secret/token ada dalam env/file privat `/private/tmp`, tidak dikomit.

Konfigurasi aktual:

```sh
export DB_HOST=localhost DB_USER=root DB_PASSWORD=''
export DB_NAME=webalhasan_v4_fase2_test APP_ENV=testing APP_DEBUG=false
export APP_URL=http://127.0.0.1:8964 V4_TEST_URL=http://127.0.0.1:8964
export API_TOKEN_HASH_SECRET='<secret-uji>' V4_TEST_PASSWORD='<password-uji>'
export PUSH_TOKEN_KEY='<64-hex-uji>'
export V4_HTTP_TOKENS=/private/tmp/v4-fase4-tokens.json
export PHP_INI_SCAN_DIR=:/private/tmp/v4-fase4-php-ini
export V4_EVIDENCE_DIR=docs/phase-v4-4 V4_SCREENSHOT=docs/phase-v4-4/regresi-fase1-375.png
# socket.ini: mysqli.default_socket=/private/tmp/webalhasan-v4-fase4.sock
php -d upload_max_filesize=10M -d post_max_size=12M -S 127.0.0.1:8964 -t . tests/v4_phase1_router.php
```

`jalankan-final.sh` menyimpan urutan perintah rangkaian akhir; `jalankan-regresi-v4.sh` menyimpan regresi Fase 1–3 dan `jalankan-regresi-v1-v3.sh` menyimpan suite lama. Script mengasumsikan env privat telah dibuat, dependensi Composer/Playwright terpasang dan DB/server uji khusus tersedia. **Script final mereset DB bisnis uji itu**, bukan untuk DB produksi/pekerjaan lain. Salinan percobaan sebelumnya disimpan privat di `/private/tmp/v4-fase4-before-final.sql`. Fixture resmi menggunakan path ID sementara Fase 2–3; jangan jalankan bersamaan dengan suite lain yang memakai path tersebut.

Urutan migrasi akhir: 001–021 dari salinan direktori migrasi sementara → `v4_phase1_preflight.php`/manifest → 022 → `up` ulang → verifier + perbandingan jumlah baris V1–V3. Baris warisan tetap. Skema DB regresi `webalhasan_v3_phase1_test` dibuat dari CREATE/ALTER dump tanpa INSERT, migrasi diterapkan, seeder V2 resmi dijalankan, satu semester nonaktif sintetis ditambahkan seperti baseline Fase 3. Tidak membersihkan residu warisan sembarangan.

| Suite / operasi | Hasil final | Bukti |
| --- | --- | --- |
| Fase 4 integrasi | exit 0, 45 lulus | `integrasi.txt` |
| HTTP/API/browser Fase 4 | exit 0, 92 lulus | `http-browser.txt` |
| Konkurensi/pemulihan | exit 0, 22 lulus, 6 koneksi per putaran | `konkurensi.txt` |
| Siklus ganti/tarik/buka/koreksi | exit 0, 16 lulus | `siklus.txt` |
| Push dan retry mock | exit 0, 10 lulus | `push.txt` |
| Receipt mock setelah review | exit 0, 3 lulus | `receipt.txt` |
| Navigasi UI setelah review | exit 0, 4 lulus | `http-navigasi.txt` |
| API preview POST/admin form/internal folder | exit 0 | `http-pratinjau.txt` |
| Komponen nilai akhir wajib hilang | exit 0, 4 lulus + verifier | `nilai-belum-lengkap.txt` |
| Validasi catatan nonteks | exit 0, 2 lulus | `validasi-tambahan.txt` |
| PDF teks/render, satu byte, scan QR | exit 0; 1 halaman, dua PNG tanda tangan + QR vektor | `pdf-hash.txt`, `qr.txt`, `pdf-inspeksi.txt`, `pdf-v1-1.png` |
| PDF 30 mapel/catatan panjang | exit 0; 6 halaman, mapel 30 dan penanda terbaca, tanpa halaman kosong; ditinjau visual | `pdf-banyakhal.txt`, `pdf-banyakhal-kontak.jpg` |
| Verifier Fase 4 setelah seluruh siklus dan penolakan rollback | exit 0, 7 PDF versi tetap utuh | `verifier-fase4-final.txt`, `verifier-setelah-rollback-ditolak.txt` |
| Rollback berisi data | exit 1 sesuai harapan, preflight dan SQL guard; data utuh | `rollback-ditolak.txt`, `rollback-sql-ditolak.txt` |
| Rollback kosong → up → up | exit 0 pada `webalhasan_v4_schema_test` terpisah | `rollback-kosong.txt` |
| Fase 1 | 29 integrasi + 23 HTTP/browser lulus | `regresi-fase1*.txt` |
| Fase 2 | 54 integrasi + 2 konkurensi + 25 HTTP + 8 guru lulus | `regresi-fase2*.txt` |
| Fase 3 | 73 integrasi + 4 konkurensi + 56 HTTP + 9 transaksi lulus | `regresi-fase3*.txt` |
| V1–V3 | 14 integrasi, 14/15 statis lulus; satu gagal warisan | `regresi-ringkasan.txt`, `regresi-*.txt` |
| Ulang V2 notifikasi setelah review | exit 0 | `regresi-v2_phase5-setelah-review.txt` |
| Lint/diff-check/advisory | lulus; 0 advisory pada tanggal uji | `lint.txt`, `composer-audit.txt` |
| Log HTTP | kode QR aktual tidak ada; 0 warning/fatal | `inspeksi-log.txt` |

Pemeriksaan tambahan setelah rangkaian akhir:

```sh
php tests/v4_phase4_receipt.php
php tests/v4_phase4_missing_grade.php
node tests/browser/uji-v4-fase4-navigasi.mjs
node tests/browser/uji-v4-fase4-pratinjau.mjs
php bin/v4_phase4_verify.php
php tests/v4_phase4_qr.php
composer audit --locked --no-interaction
DB_NAME=webalhasan_v3_phase1_test V2_PHASE5_RUN_INTEGRATION=1 php tests/v2_phase5_integration.php
```

Python bundel yang digunakan untuk teks/render: `/Users/ilanmochamad/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/bin/python3` dengan pypdf, pypdfium2 dan Pillow. `tests/v4_phase4_pdf.py` menulis kode/berkas satu-byte privat untuk pengujian HTTP berikutnya, tanpa mencetak kode. Scan QR dilakukan pada PNG hasil render seluruh halaman. Screenshot desktop/375 px ditinjau; koreksi navigasi rencana dan pemisahan pemilihan kelas/pratinjau diuji lagi setelah review.

## Percobaan awal dan koreksi

- Preflight pertama sebelum migrasi fondasi tidak menemukan `schema_migrations`; urutan diperbaiki menjadi baseline 001–021 → manifest → 022. Tidak diklaim sebagai migrasi lulus awal.
- Fixture awal salah memakai kolom `wali.nama_wali` dan mencoba NULL pada absensi NOT NULL. Awalnya reporting mysqli dimatikan oleh pembukaan koneksi, sehingga kegagalan fixture tidak terlihat. Guard fixture kini menyalakan strict reporting **setelah** koneksi; uji absensi hilang memakai DELETE + pemulihan baris lengkap, termasuk timestamp. Percobaan itu dibuang, DB dibuat baru sebelum hasil final. `integrasi-awal.txt`/`integrasi-deux.txt` dipertahankan sebagai percobaan gagal, bukan bukti penerbitan sah.
- Uji konkurensi awal menemukan error MariaDB 1020; versi awal menghasilkan 503. Kode numerik kini dipertahankan pada exception, retry terbatas bekerja pada mysqli OFF/strict. Log `konkurensi-awal.txt`, `konkurensi-debug.txt`, `konkurensi-report-off.txt` menunjukkan percobaan awal; hanya `konkurensi.txt` adalah hasil final DB baru.
- Uji siklus awal salah memilih kolom `status` tanpa alias; diperbaiki di suite. Uji PDF awal salah menghitung SVG QR sebagai raster ketiga dan memakai indeks halaman negatif pypdfium2; diperbaiki menjadi dua PNG + QR vektor dan pemindaian QR aktual. Tidak ada pelonggaran pengujian hash atau gate.
- Review diff menemukan blok pengecekan V4 tersalin juga ke helper receipt yang tidak mempunyai konteks baris; salinan dihapus. Suite receipt token mati ditambahkan, regresi V2 notifikasi diulang, keduanya lulus. API preview catatan dipindah ke body POST agar aplikasi tidak meminta catatan sensitif di URL.

Whitespace akhir baris pada keluaran teks dinormalisasi sebelum commit; isi hasil dan kegagalan dipertahankan.

Tidak ada klaim uji produksi, pengiriman fisik, atau verifikasi hosting. Batas lain ada pada `risiko-terbuka.md`.
