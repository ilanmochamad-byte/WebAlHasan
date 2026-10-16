# Kontrak penerbitan dan pemulihan

## Pembacaan dan transaksi

`RaporService::preview` menggunakan matriks/gate Fase 3, rumus Fase 2, rencana dan aset Fase 1. Preview memuat nilai/predikat/target/catatan, rata-rata dari nilai akhir sebelum pembulatan tampilan (disimpan dua desimal sebagai `rata_rata`; sejak koreksi audit ditampilkan bilangan bulat `rata_rata_tampil` sesuai PRD §5.1, snapshot lama tanpa kunci itu tetap tampil seperti PDF-nya), sikap, lima status kehadiran/belum dicatat, kelas/semester, tempat/tanggal, penanda dan kekurangan. Hash preview mencakup snapshot, hash matriks dan versi rencana. Catatan umum maksimal 2000 karakter; alasan maksimal 1000.

Penerbitan memakai transaksi InnoDB **SERIALIZABLE**, parent rencana dikunci dahulu. Cakupan pelaku dan santri dibaca ulang; peserta/absensi santri dikunci termasuk rentang baris yang belum ada, lalu seluruh matriks/penanda/hash dibaca ulang di dalam transaksi. Read SERIALIZABLE mengunci sumber pertemuan/jadwal, bahan dan otorisasi yang dibaca hingga commit. Pendidikan membutuhkan `rapor.finalisasi`; Ganti/Tarik/Buka memakai `rapor.buka_koreksi`; unduhan petugas memakai `rapor.cetak`. Admin berkapasitas admin dan wajib alasan. Tidak mempercayai identitas pengisi historis sebagai izin saat ini.

`current_version` dan `revision` (ID operasi terakhir) harus cocok. Key terbit 16–100 karakter disimpan sebagai SHA-256 per aktor; fingerprint permintaan mencegah penggunaan ulang key dengan isi berbeda. Replay sah memeriksa otorisasi lagi dan mengembalikan **metadata rapor saat ini** dengan `replayed=true`; tidak membuat operasi/versi/outbox baru. Konflik transaksi sementara MariaDB 1020/1205/1213 dicoba ulang maksimal empat kali, jeda 10–40 ms; konflik versi produk tetap 409. Uji mencakup mysqli reporting OFF seperti aplikasi normal.

## Berkas dan database tidak atomik bersama

Urutan: baca gate/snapshot → render PDF dan QR dalam memori → buat nama acak privat dengan mode eksklusif `x+b` → izin 0600, tulis seluruh byte, flush/fsync → insert versi/snapshot/hash/kode → enqueue outbox wajib → riwayat/audit → commit DB. Tidak ada panggilan provider dalam transaksi. Satu versi ter-commit menunjuk tepat satu PDF yang disimpan; GET selalu membaca berkas itu dan memeriksa SHA-256, tidak merender ulang.

Kegagalan wajib biasa menggulung semua perubahan DB, termasuk notifikasi/audit sukses sebelumnya, lalu menghapus berkas yang terbukti tidak direferensikan. Jika hasil commit/koneksi DB tidak pasti, kode **tidak menghapus** berkas tanpa pemeriksaan referensi yang berhasil. Crash sesudah fsync sebelum commit dapat meninggalkan orphan privat; DB menggulung transaksi dan orphan tidak tersedia dari endpoint. Uji proses exit 99 membuktikan kasus ini. `bin/v4_phase4_reconcile.php` hanya menghitung orphan >24 jam dan memverifikasi PDF, **tidak menghapus atau meregenerasi otomatis**. Saat pemulihan manual: hentikan penulis, pastikan database yang benar tersedia, pertahankan backup, buktikan berkas tidak direferensikan sebelum membersihkan. PDF hilang/rusak mengembalikan 503; pulihkan byte kanonis dari salinan tepercaya atau lakukan penarikan/penerbitan versi baru, jangan mengganti diam-diam PDF/hash versi lama.

Tidak mengklaim kehilangan daya/disk, putus koneksi tepat saat commit, fsync direktori, durability hosting, atau backup/restore produksi sudah diuji. Lihat risiko.

## Versi, pembukaan, penanda tangan

Terbit pertama hanya saat current_version=0. Penggantian wajib beralasan dan gate penuh, menghasilkan versi berikutnya. Versi sah lama menjadi Diganti; versi yang sudah Ditarik tetap Ditarik. Tarik mewajibkan rapor Terbit dan membatalkan versi/kodenya. Buka untuk koreksi juga menandai versi/kode lama Ditarik dan parent Perlu Perbaikan. Nilai/sikap/pemeriksaan rapor Terbit ditolak 409 sampai Pendidikan/admin membuka beralasan. Koreksi setelah Buka menggunakan alur lama dan verifikasi ulang; penggantian kemudian membutuhkan gate baru.

`v4_rapor_operasi` menyimpan aktor, kapasitas, tindakan, versi, alasan, waktu dan hash idempotensi. Alasan status lama ditelusuri dari operasi, bukan menimpa alasan penerbitan atau snapshot. Wali dapat melihat status/versi/waktu historis; snapshot dan PDF yang tidak sah tidak ditawarkan lagi. Petugas berwenang tetap dapat memeriksa/mencetak PDF historis dengan statusnya pada halaman riwayat.

Perubahan khusus admin di `RencanaService::changeSigners` memerlukan alasan, versi rencana, dua pasangan penanda/aset aktif dan tempat. Riwayat privat menyimpan sebelum/sesudah; audit umum hanya metadata. Tidak mengubah mapel/target/bobot. Snapshot menyertakan identitas dan byte gambar yang sah saat penerbitan; PDF terdahulu tidak bergantung pada aset aktif saat ini. Gambar hanya dikirim dalam respons terotorisasi.

## Verifikasi publik dan notifikasi

QR menggunakan kode acak 192 bit dalam **fragment URL**, dipindahkan JavaScript lokal ke form dan dihapus dari address bar. Server menerima kode hanya lewat POST, menyimpan SHA-256 kode, dan mengembalikan whitelist status/version/tanggal terbit serta cocok bila ada PDF. Tidak merender snapshot atau membuka PDF publik. Pembatasan DB per IP: 20 pemeriksaan per menit, termasuk kode acak; tidak percaya X-Forwarded-For. IP disimpan sebagai hash bucket dan entri lama dibersihkan. Unggahan maksimal 10 MB, header `%PDF-`, hash sementara dari temporary upload PHP; tidak dipindah/disalin/diurai/disimpan oleh aplikasi. PHP menghapus temporary upload setelah request. CSP dan Referrer-Policy membatasi rujukan keluar.

Seluruh wali berakun/role orang_tua dan relasi aktif menerima tepat satu InApp/event versi; Push diantrekan sesuai sakelar, termasuk penerima yang belum mempunyai perangkat (worker menangani keadaan tersebut). WhatsApp tidak diantrekan untuk rapor. Producer memperlakukan outbox wajib sebagai bagian transaksi. Dedup memakai unique event/channel/user yang sudah ada. Dispatcher membaca sakelar, relasi dan status/versi lagi sebelum mengirim; memaksa judul/isi generik dan whitelist `tipe,rapor_id`. Receipt/backoff/retry tetap menggunakan fondasi V2. Tidak ada nama, NIS, nilai, gambar atau kode QR dalam payload.

## Endpoint dan dependensi

- GET `/api/v1/v4/rapor`: daftar wali; GET `/rapor/{id}`: detail; GET `/rapor/{id}/pdf/{version}`: PDF. Flag `petugas=1` meminta pemeriksaan capability petugas, bukan memberi izin.
- GET `/api/v1/v4/rapor/pratinjau?rencana_id=...&santri_id=...`: preview petugas tanpa catatan dalam URL. POST ke path pratinjau yang sama menerima catatan umum di body privat; POST `/api/v1/v4/rapor`: Terbit/Ganti/Tarik/Buka, hash preview, current_version, revision, alasan, catatan, key.
- POST `/api/v1/v4/rencana/penanda`: tindakan khusus admin.
- Web: `portal/rapor_semester.php`, `rapor_wali.php`, `rapor_pdf.php`, `rapor_penanda.php`; publik `verifikasi_rapor.php`. Semua respons privat memakai private/no-store; web mutasi CSRF, API bearer. Wali diperiksa ulang di daftar/detail/PDF, termasuk ID langsung.

Composer mengunci Dompdf 3.1.6 dan PHP-QRCode 5.0.5. Runtime PHP + gd/mbstring/dom/openssl sesuai kebutuhan dependensi. Dompdf remote/PHP/JavaScript dimatikan, chroot/temp/font cache privat; HTML hanya dari template dan konten ter-escape. `vendor/` diabaikan Git, dipasang dengan `composer install --no-dev --no-scripts --prefer-dist` dari lock. [Dokumentasi Dompdf](https://github.com/dompdf/dompdf), [output SVG PHP-QRCode](https://php-qrcode.readthedocs.io/en/v5.0.x/Built-In-Output/QRMarkupSVG.html). Tidak memasukkan kode dependensi ke repo.

Migrasi 022 hanya menambah tiga tabel; tidak backfill/update bisnis V1–V3 atau snapshot lama. Manifest diambil setelah 001–021 dan sebelum 022; verifier membuktikan jumlah baris warisan tidak berubah. Rollback 022 hanya untuk bisnis Fase 4 kosong; preflight dan guard CHECK SQL menolak data. Latihan dilakukan pada schema DB terpisah. CHECK/durability/aturan `.htaccess` di hosting belum dibuktikan. Dokumen ini menjelaskan kontrak dan uji Fase 4, bukan runbook rilis produksi Fase 5.
