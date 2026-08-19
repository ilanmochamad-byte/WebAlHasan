# Laporan parsing jam pada dump sumber

Sumber pemeriksaan: `k1807225_webalhasan.sql` yang dilacak di workspace. Ini bukan hasil basis data produksi yang sedang berjalan.

| Ringkasan | Jumlah |
|---|---:|
| Baris `jadwal_ngaji` pada dump | 5 |
| Dapat diparsing aman | 5 |
| Gagal diparsing | 0 |

Kelima baris menggunakan nilai `05.00 - 06.00 WIB`, yang dipetakan menjadi `05:00:00`–`06:00:00`. Nilai `jam` sumber tetap dipertahankan oleh migrasi.

Kegagalan aktual pada database target belum dapat dilaporkan tanpa menjalankan preflight terhadap salinan database tersebut. Jalankan `bin/phase3_preflight.php`; semua kegagalan akan masuk `unparsed-jam.csv`, lalu setelah migrasi tersedia di tabel `jadwal_jam_migration_report` dan keluaran `bin/phase3_migration_report.php`.
