# Inventarisasi awal PRD V3 Fase 1

Baseline: `14548541000e0df523343d82b28650061364bb65`, 8 September 2026.
Status: inventarisasi kode/skema lokal selesai. Hasil implementasi dan pengujian tercatat dalam test-results.md.

## Temuan baseline sebelum perubahan Fase 1

- `k1807225_webalhasan.sql` mendefinisikan tabel `pelanggaran`: `id`, `id_santri`, `tgl_pelanggaran`, `jenis_pelanggaran`, `poin`, `hukuman`. Inventarisasi hanya memakai definisi struktur; data dump tidak disalin ke dokumentasi atau fixture.
- `admin/admin_pelanggaran.php` menyediakan tambah dengan interpolasi input SQL, daftar dengan INNER JOIN santri, keluaran jenis pelanggaran tanpa escape, serta DELETE melalui GET `hapus`.
- Guard admin bersama sudah memeriksa role dan CSRF POST. Guard tersebut tidak melindungi aksi hapus GET maupun menggantikan prepared statement.
- `app/Ui/Navigation.php` menampilkan menu lama Pelanggaran pada kelompok Lain-lain. `admin/admin_dashboard.php` memuat pintasan Catat Pelanggaran Santri.
- Pencarian berkas PHP/SQL tidak menemukan service atau tabel konseling khusus. Istilah pembinaan juga muncul pada konten artikel; itu bukan data bisnis konseling.
- Migrasi tertinggi adalah `012_fondasi_penugasan_v3_v6.sql`. Paket Fase 1 menambahkan migrasi 013 setelah nomor tersebut.

## Strategi konservatif

Tabel lama tidak dihapus, diganti, atau diisi ulang. Tidak ada backfill otomatis berdasarkan nama. Data lama tidak mempunyai pelaku, tahun ajaran, katalog, snapshot aturan, atau penugasan yang dapat dipastikan dari struktur ini. Karena itu baris lama dipertahankan sebagai **Data warisan**, tidak langsung dimasukkan ke ledger poin V3.

Jika kelak ada pemetaan pasti, gunakan ID sumber yang unik, cek keberadaan santri berdasarkan ID, pertahankan teks dan poin asli, dan simpan provenance. Pelaku/penugasan/tahun yang tidak diketahui tetap tidak diketahui. Baris yatim tetap dipertahankan; tampilan warisan memerlukan LEFT JOIN agar tidak menghilangkannya dari daftar.

## Keputusan pengguna 8 September 2026

URL/menu lama dipertahankan dengan tampilan baca-saja berlabel **Data warisan**. Pengguna menyetujui penutupan pencatatan dan penghapusan lama. Keputusan dicatat di PRD-V3.md §5.2a.
