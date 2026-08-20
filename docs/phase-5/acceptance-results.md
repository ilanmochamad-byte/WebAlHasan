# Hasil Validasi Fase 5

Tanggal pengujian: 20 Agustus 2026. Seluruh pengujian database memakai
`webalhasan_phase5_test` lokal dan fixture sintetis; database produksi tidak diakses.

## Lingkungan

- macOS Darwin 25.6.0 arm64
- PHP 8.4.14
- MariaDB 12.3.2
- Node.js 26.7.0 dan npm 11.19.0
- Expo SDK 57, ekspor web lokal untuk alur aplikasi guru

## Hasil fungsional dan keamanan

- Admin berhasil membuka dashboard, menerapkan rentang tanggal, tahun ajaran, guru,
  kelas, jadwal, dan status, lalu membuka detail, CSV, serta halaman cetak.
- Guru A hanya melihat jadwal #12 miliknya. `teacher_id` Guru B ditolak `403`, filter
  jadwal Guru B tidak mengembalikan datanya, dan detail pertemuan Guru B ditolak
  `403` oleh server.
- Filter status Alpa menghasilkan total ringkasan 200 dan detail 200. Laporan tanpa
  filter status juga diuji dan jumlah lima status sama dengan `detail_count`.
- CSV diuji pada 1.050 baris hasil filter, bukan hanya satu halaman UI; header sesuai
  `csv-format.md`, BOM UTF-8 ada, dan nilai berawalan formula dinetralkan.
- Halaman cetak memuat identitas Pesantren Al Hasan, jenis laporan, filter aktif,
  waktu pembuatan, pembuat, nomor halaman, dan 200 baris hasil filter tanpa navigasi.
- Pembacaan laporan, ekspor, dan cetak tidak mengubah jumlah atau isi absensi.
- Alur aplikasi guru selesai dari login, tab Laporan, filter, detail, cetak, sampai
  berbagi PDF. Dialog cetak dan lembar berbagi PDF telah diuji pada perangkat Android
  dan iOS nyata dan dinyatakan lulus pada 20 Agustus 2026.

## Performa dan indeks

Fixture berisi 50 pertemuan, 50 absensi guru, dan 1.000 absensi santri. Pengukuran
halaman pertama sebelum indeks adalah 11,87 ms; sesudah indeks 11,82 ms. Pengulangan
akhir seluruh suite mencatat 11,81 ms (pengulangan sebelumnya 12,55 ms), jauh di
bawah batas 2.000 ms.

`EXPLAIN` sebelum perubahan memperlihatkan pemindaian tabel pertemuan dan tidak ada
indeks kandidat untuk status. Sesudah pengukuran, migrasi 005 menambah tiga indeks
aditif:

- `pertemuan_pengajian(tanggal_pertemuan, jadwal_id)`
- `absensi_guru(status, pertemuan_id)`
- `absensi_santri(status, pertemuan_id)`

Pada filter status, estimasi baris turun dari 101 menjadi 51. Cabang guru memilih
indeks status; cabang santri mengenal indeks sebagai kandidat tetapi tetap memilih
jalur pertemuan pada fixture kecil. Jalur tanpa status tidak mengalami regresi.

## Backup dan pemulihan

Backup database uji dibuat bersama manifest, dipulihkan ke database sementara
berakhiran `_test`, lalu diverifikasi. Jumlah baris tabel inti sebelum dan sesudah
pemulihan sama:

| Tabel | Baris |
|---|---:|
| `users` | 3 |
| `guru` | 27 |
| `santri` | 380 |
| `jadwal_ngaji` | 7 |
| `pertemuan_pengajian` | 51 |
| `pertemuan_peserta` | 1.000 |
| `absensi_guru` | 51 |
| `absensi_santri` | 1.000 |

Database restore sementara telah dihapus dan fixture laporan dibersihkan dari
database uji setelah pengujian.

## Pemeriksaan otomatis

- Semua file PHP aplikasi dan pengujian: `php -l` lulus.
- Static test Fase 1–5: lulus.
- Integration test Fase 2–5: lulus.
- Aplikasi: `npm run lint` dan `npx tsc --noEmit` lulus.
- Ekspor web Expo: lulus dan menghasilkan seluruh route aplikasi.

## Status penerimaan

Seluruh sepuluh kriteria penerimaan Fase 5 telah diverifikasi. Hasil CSV telah diuji
pada aplikasi spreadsheet, sedangkan cetak dan berbagi PDF telah diuji pada perangkat
Android dan iOS nyata. Implementasi native memakai `expo-print` dan `expo-sharing`
yang kompatibel dengan Expo SDK 57. Hasil implementasi Fase 5 telah di-commit.
