# Koreksi Pengelolaan Alumni

Keputusan pengguna 6 September 2026. Branch `feat/koreksi-alumni`.

Paket ini **memulihkan dan memodernkan** alur kelulusan/mutasi santri menjadi
alumni, yang sempat tidak dapat dipakai lewat antarmuka, sekaligus menutup
kelemahan keamanan dan konsistensi pada halaman alumni lama.

## Dokumen

| Berkas | Isi |
| --- | --- |
| [`aturan-bisnis.md`](aturan-bisnis.md) | tujuan fitur, alur individual dan massal, perubahan status santri, penanganan kelas dan kamar, pencegahan duplikasi, audit, data warisan, keamanan |
| [`migrasi-dan-rollback.md`](migrasi-dan-rollback.md) | isi migrasi 011, pemeriksaan sebelum dan sesudah, backfill konservatif, prosedur rollback beserta peringatannya |
| [`cpanel-deployment.md`](cpanel-deployment.md) | panduan deployment cPanel dan **ceklis smoke test** setelah menarik branch |
| [`test-results.md`](test-results.md) | hasil setiap pengujian secara jujur: LULUS / BELUM DIJALANKAN / MEMERLUKAN UJI PRODUKSI |
| [`acceptance-status.md`](acceptance-status.md) | status tiap kriteria penerimaan, risiko, dan pekerjaan yang masih terbuka |

## Berkas yang dibuat paket ini

```
app/MasterData/AlumniRepository.php          seluruh query alumni, prepared statement
app/MasterData/AlumniService.php             satu-satunya pintu perubahan data alumni
app/MasterData/AlumniConflictException.php   konflik bersamaan (dijawab HTTP 409)
admin/admin_kelulusan_santri.php             alur individual dan massal
database/migrations/011_koreksi_alumni.sql   migrasi aditif dan idempoten
database/rollbacks/011_koreksi_alumni.sql    rollback berpasangan
bin/alumni_preflight.php                     laporan kondisi data (hanya membaca)
bin/alumni_backfill.php                      pemasangan referensi santri yang konservatif
bin/alumni_verify.php                        verifikasi pasca-migrasi (hanya membaca)
bin/alumni_run_all_tests.sh                  penjalan seluruh pengujian
tests/alumni_static.php                      180 pemeriksaan statis
tests/alumni_integration.php                 80 pemeriksaan integrasi
tests/alumni_concurrency.php                 13 pemeriksaan permintaan bersamaan
tests/alumni_concurrency_worker.php          proses anak untuk uji bersamaan
tests/alumni_web_smoke.php                   61 pemeriksaan lewat HTTP
```

## Berkas yang diubah

```
admin/admin_alumni.php            ditulis ulang: kerangka bersama, filter aman, arsip/pemulihan
admin/proses_mutasi_alumni.php    endpoint lama: GET dialihkan, POST ditolak 410
admin/admin_master_santri.php     tombol "Luluskan / Mutasi keluar" per baris + tindakan halaman
app/bootstrap.php                 pendaftaran alumni_service()
app/Ui/Navigation.php             menu dan pemetaan skrip untuk halaman kelulusan
tests/penempatan_static.php       PS-15 diperbaiki sasarannya (lihat test-results.md §4)
```

## Menjalankan pengujian

```bash
php bin/migrate.php up
bash bin/alumni_run_all_tests.sh
```

Seluruh rangkaian ber-basis-data menolak berjalan bila `DB_NAME` tidak
berakhiran `_test`.
