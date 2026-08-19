# Migrasi dan rollback Fase 3

Migrasi `003_phase3_schedules_meetings.sql` bersifat aditif. Semua ID dan baris `jadwal_ngaji` dipertahankan; kolom relasi `id_tahun`, `id_kelas`, dan `id_guru` tidak diganti. Kolom `jam` lama juga tidak dihapus atau diubah oleh migrasi.

Hari jadwal tidak ada pada skema lama dan tidak dapat disimpulkan dari `waktu_sholat` atau `jam`. Karena itu baris lama mendapat `hari = NULL` sampai admin melengkapinya melalui UI. Jadwal baru wajib memiliki hari, waktu mulai, dan waktu selesai.

## Urutan penerapan yang aman

1. Jalankan `php bin/preflight.php` dan simpan backup penuh, manifest jumlah baris, serta laporan duplikasi.
2. Jalankan `php bin/phase3_preflight.php --output=/lokasi/aman/laporan` pada basis data sumber. Hentikan migrasi bila laporan relasi berisi jadwal tanpa tahun ajaran, kelas, atau guru.
3. Periksa `unparsed-jam.csv`. File selalu dibuat, termasuk bila hanya berisi header.
4. Pulihkan backup ke database staging bernama `*_test`, lalu jalankan `php bin/migrate.php up` di staging.
5. Jalankan `php bin/phase3_migration_report.php`. Pastikan jumlah `total` sama dengan jumlah baris `jadwal_ngaji` sebelum migrasi, setiap kegagalan mempunyai `original_jam`, relasi yatim kosong, dan duplikasi pertemuan kosong.
6. Jalankan `PHASE3_RUN_INTEGRATION=1 php tests/phase3_integration.php` hanya pada database `*_test`.
7. Bandingkan seluruh kolom lama sebelum/sesudah dengan backup. Setelah verifikasi staging berhasil, jadwalkan penerapan produksi tanpa menjalankan rollback destruktif.

Migrasi menerima bentuk aman `HH.MM - HH.MM WIB`, `HH:MM-HH:MM`, tanda pisah en/em dash, dan pemisah `s/d`. Rentang lintas tengah malam atau waktu selesai yang tidak lebih akhir dari waktu mulai tidak ditebak. Nilai tersebut dicatat di `jadwal_jam_migration_report`, sementara nilai asli tetap berada di `jadwal_ngaji.jam` dan disalin pula ke `original_jam` pada laporan.

## Query verifikasi minimum

```sql
SELECT COUNT(*) FROM jadwal_ngaji;
SELECT id, id_tahun, id_kelas, id_guru, fan_ilmu, nama_kitab, jam, tempat
FROM jadwal_ngaji
WHERE id_tahun IS NULL OR id_kelas IS NULL OR id_guru IS NULL
   OR fan_ilmu IS NULL OR nama_kitab IS NULL OR jam IS NULL OR tempat IS NULL;

SELECT jadwal_id, original_jam, normalized_candidate, reason
FROM jadwal_jam_migration_report
ORDER BY jadwal_id;

SELECT jadwal_id, tanggal_pertemuan, COUNT(*)
FROM pertemuan_pengajian
GROUP BY jadwal_id, tanggal_pertemuan
HAVING COUNT(*) > 1;

SELECT pertemuan_id, santri_id, COUNT(*)
FROM pertemuan_peserta
GROUP BY pertemuan_id, santri_id
HAVING COUNT(*) > 1;
```

Query pertama harus sama dengan manifest pra-migrasi. Query kelengkapan dan dua query duplikasi harus menghasilkan nol baris. Query laporan parsing boleh berisi baris, tetapi setiap baris wajib mempertahankan `original_jam`.

## Rollback

`database/rollbacks/003_phase3_schedules_meetings.sql` hanya untuk staging atau pemulihan terencana dari backup penuh. Rollback menghapus pertemuan, snapshot peserta, laporan parsing, dan metadata Fase 3; karena itu jangan menjalankannya pada produksi untuk sekadar membatalkan rilis aplikasi.

Jika aplikasi perlu dikembalikan sementara, pertahankan skema hasil migrasi dan deploy kode versi sebelumnya: kolom serta tabel tambahan tidak mengganggu query lama, dan `jadwal_ngaji.jam` tetap tersedia. Jika rollback skema benar-benar diperlukan di staging, ekspor dahulu tabel `pertemuan_pengajian`, `pertemuan_peserta`, `jadwal_jam_migration_report`, dan kolom baru `jadwal_ngaji`, lalu jalankan `php bin/migrate.php rollback`.
