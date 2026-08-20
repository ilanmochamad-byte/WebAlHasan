# Status penerimaan Fase 3

Tanggal penutupan verifikasi: 20 Agustus 2026. Pengujian MySQL telah dijalankan pada hosting cPanel dan seluruh kriteria dinyatakan lulus.

## Dependensi Fase 1 dan Fase 2

Dependensi yang diperlukan tersedia tanpa dibangun ulang: PHP 8.4 dengan `mysqli`, `mbstring`, `json`, dan `session`; bootstrap/config environment; migrator SQL berversi; autentikasi dan role admin/guru; CSRF; audit; master guru, santri, tahun ajaran, kelas, dan keanggotaan historis.

## Hasil per kriteria

| Kriteria penerimaan | Implementasi/pengujian | Status workspace |
|---|---|---|
| Jadwal lama mempertahankan relasi dan data lama | Migrasi aditif, query verifikasi, preflight relasi | Terverifikasi pada MySQL hosting cPanel |
| Jam gagal tercatat dan nilai asli tersedia | `jadwal_jam_migration_report`, kolom `jam` tidak diubah, preflight CSV | Terverifikasi pada MySQL hosting cPanel; laporan parsing diperiksa |
| Admin membuat/mengubah dan memfilter semester aktif | UI + repository/service + skenario integrasi | Terverifikasi pada MySQL hosting cPanel |
| Bentrok guru aktif ditolak | Pemeriksaan overlap pada semester aktif + skenario integrasi | Terverifikasi pada MySQL hosting cPanel |
| Pertemuan jadwal–tanggal tidak dapat diduplikasi | Unique constraint + transaksi + skenario integrasi | Terverifikasi pada MySQL hosting cPanel |
| Pembukaan menyimpan snapshot santri | `pertemuan_peserta` + transaksi + skenario perubahan kelas | Terverifikasi pada MySQL hosting cPanel |
| Jadwal nonaktif tidak menjadi tugas guru | Query tugas aktif + skenario integrasi | Terverifikasi pada MySQL hosting cPanel |
| Semua PHP baru/diubah lolos `php -l` | Lint seluruh berkas PHP terlacak | Terverifikasi |

`tests/phase3_static.php` memverifikasi struktur migrasi, preservasi kolom lama, laporan parsing, constraint unik, aturan overlap, transaksi, snapshot, otorisasi pemilik, CSRF, fitur UI, serta parser format jam lama. `tests/phase3_integration.php` menolak berjalan kecuali `PHASE3_RUN_INTEGRATION=1` dan nama database berakhiran `_test`.

Migrasi dan pengujian integrasi telah dijalankan pada MySQL hosting cPanel dan seluruh kriteria dinamis lulus. Semua kriteria Fase 3 pada `PRD.md` telah ditandai selesai. Hasil implementasi Fase 3 telah di-commit.
