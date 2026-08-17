# Migrasi dan rollback Fase 2

Migrasi `002_phase2_master_data.sql` bersifat aditif. ID guru, santri, kelas, tahun ajaran, dan baris penempatan lama tidak diubah. Kolom ayah/ibu pada `santri` tidak dihapus; nilainya disalin ke `wali` dan `santri_wali` supaya hasil dapat dibandingkan sebelum ada persetujuan penghapusan struktur lama.

## Sebelum produksi

1. Jalankan `php bin/preflight.php` dan simpan backup, manifest jumlah baris, serta laporan duplikasi.
2. Pastikan tidak ada duplikasi NIP non-kosong, pasangan tahun–semester, nama+jenjang kelas, atau lebih dari satu penempatan kelas aktif untuk santri dan tahun yang sama.
3. Uji pada salinan basis data, lalu jalankan `php bin/migrate.php up`.
4. Bandingkan jumlah dan ID tabel lama. Verifikasi pula bahwa wali ayah/ibu yang tidak kosong memiliki relasi.
5. Jangan menjalankan rollback pada produksi hanya untuk membatalkan rilis aplikasi. Kode lama tetap dapat membaca kolom lama setelah migrasi naik.

Query verifikasi minimum setelah migrasi pada salinan database:

```sql
SELECT COUNT(*) AS semester_aktif FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL;
SELECT nis, COUNT(*) FROM santri GROUP BY nis HAVING COUNT(*) > 1;
SELECT nip, COUNT(*) FROM guru WHERE nip IS NOT NULL AND TRIM(nip) <> '' GROUP BY nip HAVING COUNT(*) > 1;
SELECT id_santri, id_tahun, COUNT(*) FROM plotting_kelas WHERE status = 'Aktif' GROUP BY id_santri, id_tahun HAVING COUNT(*) > 1;
SELECT s.id, s.nis, 'Ayah' hubungan FROM santri s LEFT JOIN wali w ON w.legacy_santri_id=s.id AND w.legacy_hubungan='Ayah' LEFT JOIN santri_wali sw ON sw.santri_id=s.id AND sw.wali_id=w.id WHERE TRIM(s.nama_ayah)<>'' AND sw.id IS NULL
UNION ALL
SELECT s.id, s.nis, 'Ibu' hubungan FROM santri s LEFT JOIN wali w ON w.legacy_santri_id=s.id AND w.legacy_hubungan='Ibu' LEFT JOIN santri_wali sw ON sw.santri_id=s.id AND sw.wali_id=w.id WHERE TRIM(s.nama_ibu)<>'' AND sw.id IS NULL;
```

Hasil yang diharapkan: `semester_aktif = 1`; empat query duplikasi/relasi lainnya menghasilkan nol baris. Bandingkan pula `row_counts` dari manifest backup dengan jumlah baris tabel lama sesudah migrasi. ID maksimum saja tidak cukup untuk membuktikan tidak ada baris hilang.

## Pemulihan

Rollback `002` menghapus struktur dan data baru Fase 2. Karena itu rollback hanya aman pada staging atau setelah pemulihan backup penuh. Data lama pada `guru`, `santri`, `tahun_ajaran`, `kelas`, `plotting_kelas`, termasuk kolom ayah/ibu, tetap tersedia, tetapi perubahan master yang dibuat setelah migrasi harus dipulihkan dari backup bila rollback benar-benar diperlukan.
