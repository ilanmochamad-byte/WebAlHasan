# Inventaris halaman dan perubahan navigasi

Paket "Koreksi dan Modernisasi UI/UX V1–V2" — keputusan pengguna 30 Agustus 2026.

Tujuannya: memastikan tidak ada halaman dalam alur paket ini yang tertinggal
dengan navigasi berbeda, dan mencatat dampak CSS bersama pada halaman di luar
cakupan.

---

## A. Halaman yang didesain ulang penuh

Halaman-halaman ini memakai kerangka bersama `App\Ui\Layout` (topbar + sidebar
sesuai kemampuan + breadcrumb + judul + tindakan utama + tab), token warna
`assets/ui/alhasan.css`, keadaan kosong yang menjelaskan, dan konfirmasi yang
menyebut dampak sebelum tindakan berisiko.

| Halaman | Koreksi | Catatan |
| --- | --- | --- |
| `portal/index.php` | 7 | Pintu masuk + beranda seluruh peran. Ditulis ulang. |
| `portal/izin_ringkasan.php` | 6, 7 | **Baru.** Isi ringkasan perizinan yang dulu ada di `portal/index.php`. |
| `admin/admin_akun.php` | 1, 6 | Pusat Akun & Hak Akses. Ditulis ulang. |
| `admin/admin_master_santri.php` | 2, 6 | Formulir wali, detail relasi, impor. Ditulis ulang. |
| `admin/admin_wali.php` | 2, 6 | Konfirmasi dampak identitas bersama. Ditulis ulang. |
| `admin/admin_wali_rekonsiliasi.php` | 2, 6 | **Baru.** Laporan rekonsiliasi + penggabungan satu pasang. |
| `admin/admin_guru.php` | 3, 6 | Tanpa pilihan tugas lama; penugasan nyata. Ditulis ulang. |
| `admin/admin_murobi.php` | 3, 6 | Keterangan approval V2. Ditulis ulang. |
| `admin/admin_pengajian.php` | 4, 6 | **Baru.** Modul terpadu bertab. |
| `admin/_pengajian_jadwal.php` | 4, 6 | **Baru.** Potongan tampilan tab Jadwal. |
| `admin/_pengajian_pertemuan.php` | 4, 6 | **Baru.** Potongan tampilan tab Pertemuan. |
| `admin/_santri_wali_field.php` | 2, 6 | **Baru.** Potongan tampilan blok wali. |
| `admin/admin_laporan_absensi.php` | 5, 6 | Tab penyajian + ringkasan per jenis. Ditulis ulang. |
| `admin/ubah_password.php` | 6, 7 | Berdiri sendiri (tanpa sidebar) — disengaja. |
| `admin/logout.php` | 6, 7 | Menjelaskan dampak sebelum konfirmasi. |

## B. Halaman yang ikut berubah tampilan karena adaptor kerangka

Halaman-halaman berikut **tidak ditulis ulang**, tetapi otomatis memperoleh
kerangka baru karena `admin/_master_ui.php` dan `portal/_ui.php` kini
mendelegasikan ke `App\Ui\Layout`. Isi dan logikanya tidak diubah.

| Halaman | Sumber kerangka |
| --- | --- |
| `admin/admin_pengurus.php`, `admin_kelas.php`, `admin_tahun.php`, `admin_pembimbing.php` | `_master_ui.php` |
| `admin/laporan_absensi_detail.php` | `_master_ui.php` |
| `portal/izin.php`, `izin_detail.php`, `izin_buat.php`, `izin_antrean.php`, `laporan.php`, `notifikasi.php` | `portal/_ui.php` |

**Koreksi audit A-08 (30 Agustus 2026):** `admin/admin_kamar.php` sebelumnya
keliru dicantumkan sebagai pemakai `_master_ui.php`. Halaman itu masih memakai
markup lama dan `admin/sidebar.php`; ia belum memperoleh adaptor `Layout`.
Pemeriksaan 390 px tidak menemukan pelebaran halaman, tetapi kesetaraan navigasi
ponselnya belum terbukti. Ini pengecualian inventaris, bukan bukti modernisasi
halaman kamar telah selesai. Rincian: `hasil-audit-codex.md`.

> **Untuk auditor:** halaman kelompok B adalah tempat paling mungkin munculnya
> cacat tampilan sisa (mis. markup lama yang mengandalkan lebar kolom Bootstrap
> di dalam kerangka flex baru). Prioritaskan pemeriksaan visual di sini.

## C. Halaman kompatibilitas (alamat lama tetap berfungsi)

| Alamat lama | Perilaku | Tujuan |
| --- | --- | --- |
| `admin/admin_login.php` | GET → 302 (membawa `pesan` dan `next` yang sah) | `portal/index.php` |
| `admin/admin_jadwal_ngaji.php` | GET → 302 dengan filter terbawa; **POST diteruskan** | `admin/admin_pengajian.php?tab=jadwal` |
| `admin/pertemuan_pengajian.php` | GET → 302 dengan konteks terbawa; **POST diteruskan** | `admin/admin_pengajian.php?tab=pertemuan` |
| `admin/admin_akun_perizinan.php` | GET → 302 dengan tab peran; **POST diteruskan** | `admin/admin_akun.php` |
| `admin/sidebar.php` | Komponen menu tanpa guard admin | dipakai halaman kelompok D |

POST sengaja **tidak** dialihkan: pengalihan akan membuang badan permintaan dan
melewati `Csrf::requireValid` serta validasi mutasi. Ia diteruskan (`require`) ke
modul tujuan yang menjalankan guard-nya sendiri.

## D. Halaman di luar cakupan desain ulang

Halaman berikut **tidak** termasuk alur paket ini (PSB, keuangan, alumni, konten
website). Logikanya tidak disentuh. Ia tetap memakai struktur kolom Bootstrap
lamanya dan memuat `admin/sidebar.php`, yang kini menghasilkan menu yang **sama
isinya** dengan sidebar baru (dan tidak lagi menyeret guard khusus admin).

`admin_dashboard.php`, `admin_data.php`, `admin_santri.php`, `admin_rekap_santri.php`,
`admin_alumni.php`, `admin_pembayaran_psb.php`, `admin_rekap_keuangan.php`,
`admin_berita.php`, `admin_galeri.php`, `admin_download.php`, `admin_pelanggaran.php`,
`admin_notifikasi.php`, `admin_izin.php` (arsip).

### Dampak CSS bersama pada kelompok D

`assets/ui/alhasan.css` dimuat pada halaman kelompok D melalui `admin/sidebar.php`.
Yang perlu diketahui auditor:

1. Sebagian besar aturan berada di bawah kelas `ah-*` atau `body.ah`, sehingga
   **tidak menyentuh** markup lama.
2. Yang **memang** berlaku global adalah blok `:root` (variabel token) dan empat
   variabel Bootstrap yang di-override di sana: `--bs-body-bg`, `--bs-body-color`,
   `--bs-link-color`, `--bs-border-color`. Efeknya: latar dan warna tautan halaman
   lama ikut menyesuaikan palet baru. Ini disengaja agar sistem terasa satu.
3. `admin/sidebar.php` menyuntikkan satu blok `<style>` kecil yang mengembalikan
   sidebar ke aliran normal (`position: static`) karena halaman lama memakai grid
   Bootstrap, bukan kerangka flex baru.
4. Halaman cetak (`laporan_absensi_cetak.php`, `portal/laporan_cetak.php`) **tidak**
   memuat berkas ini dan tidak memuat kerangka bersidebar. Tidak ada perubahan
   margin, pagination, nomor halaman, atau koreksi Safari/Android/iOS Fase 5.

## E. Halaman yang sengaja TIDAK memakai kerangka bersidebar

| Halaman | Alasan |
| --- | --- |
| `portal/index.php` (mode anonim) | Halaman masuk berdiri sendiri |
| `admin/ubah_password.php` | Pemegang password sementara belum boleh berpindah modul |
| `admin/logout.php` | Halaman konfirmasi singkat |
| `admin/laporan_absensi_cetak.php` | Cetak/PDF tanpa sidebar (keputusan pengguna) |
| `portal/laporan_cetak.php` | Idem |
| `App\Ui\Denial` (403) | Halaman penolakan; menawarkan jalan keluar, bukan menu |
