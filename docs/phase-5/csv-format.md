# Format CSV Laporan Absensi Fase 5

Endpoint web admin `admin/export_laporan_absensi.php` mengekspor **seluruh** baris
yang cocok dengan filter aktif. Parameter `page` dan `per_page` diabaikan oleh jalur
ekspor. Berkas memakai pemisah koma, kutip CSV standar, encoding UTF-8, dan BOM
UTF-8 agar nama beraksara non-ASCII terbaca di aplikasi spreadsheet.

Nilai teks yang diawali `=`, `+`, `-`, atau `@` diberi awalan apostrof sebelum
ditulis. Perlakuan ini mencegah nilai data dijalankan sebagai formula ketika berkas
dibuka di spreadsheet; apostrof bukan bagian dari data di basis data.

## Header dan arti kolom

| Header | Isi |
|---|---|
| ID Pertemuan | ID unik pertemuan pengajian. |
| Tanggal | Tanggal pertemuan `YYYY-MM-DD`. |
| ID Jadwal | ID pola jadwal sumber pertemuan. |
| Tahun Ajaran | Gabungan tahun dan semester. |
| Guru Jadwal | Guru pemilik jadwal. |
| Kelas | Nama kelas pada jadwal. |
| Fan Ilmu | Mata/fan ilmu pengajian. |
| Kitab | Kitab pada jadwal. |
| Tempat | Tempat pengajian. |
| Jenis Peserta | `Guru` atau `Santri`. |
| Nomor Identitas | NIP snapshot guru atau NIS snapshot santri. |
| Nama Peserta | Nama guru atau nama santri snapshot. |
| Status Absensi | `Hadir`, `Terlambat`, `Izin`, `Sakit`, atau `Alpa`. |
| Catatan | Catatan absensi; kosong bila tidak ada. |
| Pencatat | Nama akun yang terakhir mencatat. |
| Waktu Pencatatan | Waktu pencatatan absensi. |
| Waktu Perubahan | Waktu perubahan terakhir. |

Jumlah baris data CSV harus sama dengan `summary.detail_count` untuk filter yang
sama, tidak termasuk satu baris header.
