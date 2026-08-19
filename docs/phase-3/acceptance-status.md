# Status penerimaan Fase 3

## Dependensi Fase 1 dan Fase 2

Dependensi yang diperlukan tersedia tanpa dibangun ulang: PHP 8.4 dengan `mysqli`, `mbstring`, `json`, dan `session`; bootstrap/config environment; migrator SQL berversi; autentikasi dan role admin/guru; CSRF; audit; master guru, santri, tahun ajaran, kelas, dan keanggotaan historis.

## Hasil per kriteria

| Kriteria penerimaan | Implementasi/pengujian | Status workspace |
|---|---|---|
| Jadwal lama mempertahankan relasi dan data lama | Migrasi aditif, query verifikasi, preflight relasi | Terverifikasi statis pada dump; menunggu staging MySQL |
| Jam gagal tercatat dan nilai asli tersedia | `jadwal_jam_migration_report`, kolom `jam` tidak diubah, preflight CSV | Dump: 0 gagal; menunggu data target |
| Admin membuat/mengubah dan memfilter semester aktif | UI + repository/service + skenario integrasi | Menunggu MySQL test |
| Bentrok guru aktif ditolak | Pemeriksaan overlap pada semester aktif + skenario integrasi | Menunggu MySQL test |
| Pertemuan jadwal–tanggal tidak dapat diduplikasi | Unique constraint + transaksi + skenario integrasi | Menunggu MySQL test |
| Pembukaan menyimpan snapshot santri | `pertemuan_peserta` + transaksi + skenario perubahan kelas | Menunggu MySQL test |
| Jadwal nonaktif tidak menjadi tugas guru | Query tugas aktif + skenario integrasi | Menunggu MySQL test |
| Semua PHP baru/diubah lolos `php -l` | Lint seluruh berkas PHP terlacak | Terverifikasi |

`tests/phase3_static.php` memverifikasi struktur migrasi, preservasi kolom lama, laporan parsing, constraint unik, aturan overlap, transaksi, snapshot, otorisasi pemilik, CSRF, fitur UI, serta parser format jam lama. `tests/phase3_integration.php` menolak berjalan kecuali `PHASE3_RUN_INTEGRATION=1` dan nama database berakhiran `_test`.

Kriteria dinamis di `PRD.md` tidak boleh dicentang sebelum migrasi dan pengujian integrasi berhasil pada salinan MySQL. Workspace ini tidak memiliki `.env` database lokal, sehingga tidak ada migrasi yang dijalankan terhadap produksi maupun database lain.
