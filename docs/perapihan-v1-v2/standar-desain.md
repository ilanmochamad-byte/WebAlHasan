# Standar desain Sistem Al Hasan

Paket "Koreksi dan Modernisasi UI/UX V1–V2" — keputusan pengguna 30 Agustus 2026.
Berlaku untuk halaman admin dan portal internal. **Tidak** berlaku untuk website
publik dan aplikasi React Native.

Satu sumber: `assets/ui/alhasan.css`. Bootstrap 5.3 dipertahankan; berkas ini
hanya menyetel token dan beberapa komponen. **Tidak ada dependency atau aset
eksternal baru** — hanya dua CDN yang sudah dipakai V1 (Bootstrap dan Font Awesome).

---

## 1. Token

Warna hanya boleh berasal dari variabel `:root`. Jangan menulis hex pada halaman.

| Kelompok | Variabel | Nilai |
| --- | --- | --- |
| Identitas | `--ah-green-900/800/700/600/100/050` | `#0b3f27` `#0f5132` `#146c43` `#198754` `#e3f1e9` `#f1f8f4` |
| Teks | `--ah-ink`, `--ah-ink-soft`, `--ah-ink-muted` | `#1c2320` `#4a544e` `#6b756f` |
| Permukaan | `--ah-surface`, `--ah-canvas`, `--ah-line`, `--ah-line-strong` | `#ffffff` `#f4f6f4` `#dfe4e0` `#c6cfc9` |
| Status | `--ah-ok/-bg`, `--ah-info/-bg`, `--ah-warn/-bg`, `--ah-danger/-bg` | pasangan teks + latar |
| Bentuk | `--ah-radius` `12px`, `--ah-radius-sm` `8px`, `--ah-shadow` | |
| Ukuran | `--ah-sidebar-w` `264px`, `--ah-touch` `44px` | |

Hijau `#0f5132` adalah warna yang sudah dipakai halaman masuk V1 — identitasnya
dipertahankan, bukan diganti.

## 2. Kerangka halaman

Setiap halaman internal memakai `App\Ui\Layout::open()`:

```
topbar (sticky)  : tombol menu (ponsel) · merek · nama pengguna · Keluar
sidebar          : kemampuan aktif + kelompok menu sesuai role/capability
main             : breadcrumb → judul + deskripsi + tindakan utama → tab → isi
```

Aturan:

- **satu tata letak untuk semua halaman**; halaman tidak menulis `<html>`,
  `<head>`, atau `<nav>` sendiri;
- menu aktif ditandai `aria-current="page"` **dan** garis tebal di sisi kiri —
  bukan warna saja;
- breadcrumb selalu ada dan selalu menyediakan jalan kembali;
- tindakan utama halaman berada di kanan judul, konsisten di semua halaman.

## 3. Ponsel dan tablet

- Di bawah 992 px sidebar menjadi **laci** yang dibuka tombol menu, ditutup lewat
  latar gelap atau tombol `Escape`; status dibaca pembaca layar lewat
  `aria-expanded`.
- Target sentuh minimum 44 px (`--ah-touch`) untuk tombol, input, dan item menu.
- Di bawah 576 px sub-judul merek disembunyikan agar tombol Keluar tidak
  terdorong keluar layar.
- **Halaman tidak boleh melebar.** Tabel lebar menggulir di dalam
  `.ah-table-wrap` (`overflow-x: auto`), bukan melebarkan dokumen. Diuji otomatis
  pada 1440 / 768 / 390 px.

## 4. Formulir

- Dikelompokkan dalam `<fieldset class="ah-fieldset">` dengan `<legend>` yang
  menamai kelompoknya, dan `ah-fieldset__hint` untuk penjelasan singkat.
- Label selalu ada dan selalu terkait `for`/`id`. Bantuan pengisian memakai
  `aria-describedby`.
- Validasi dijelaskan dekat kolomnya; pesan gagal muncul sebagai `ah-note--danger`.
- **Isian dipertahankan saat validasi gagal** lewat `ah_old_keep()` / `ah_old()`.
  Password dan token tidak pernah dikembalikan ke formulir.
- Formulir yang membuat data memakai token sekali pakai (`ah_form_token`) agar
  pengiriman ulang tidak menghasilkan data ganda.

## 5. Pesan dan keadaan

| Keadaan | Komponen | Aturan |
| --- | --- | --- |
| Berhasil / gagal / peringatan / info | `ah_note()` | selalu memuat **kata** ("Berhasil", "Gagal", …), bukan warna saja |
| Kosong | `ah_empty()` | menjelaskan mengapa kosong **dan** langkah berikutnya |
| Akses ditolak | `App\Ui\Denial` | menyebut sebab dan menawarkan dua jalan keluar |
| Sedang berjalan | tombol dinonaktifkan + `aria-busy` | pengaman sebenarnya tetap idempotensi dan transaksi di server |

## 6. Tindakan berisiko

Konfirmasi wajib **menjelaskan dampaknya**, bukan sekadar "Anda yakin?".
Contoh yang dipakai:

- arsip santri → "relasi wali, riwayat kelas, absensi, dan perizinan lama TIDAK dihapus";
- nonaktifkan akun → "pengguna tidak dapat masuk, dan seluruh perangkat push miliknya dicabut";
- cabut role → "role lain pada akun ini tetap; pencabutan berlaku pada pemeriksaan server berikutnya";
- beri hak admin → dialog terpisah dengan daftar dampak dan kalimat konfirmasi yang harus diketik ulang;
- gabungkan wali → daftar santri terdampak ditampilkan lebih dulu.

## 7. Aksesibilitas

- Tautan **"Lompat ke konten utama"** sebagai elemen pertama yang dapat difokus.
- `:focus-visible` selalu menghasilkan garis tepi 3 px yang terlihat.
- Makna tidak pernah bergantung pada warna atau ikon saja: lencana selalu
  memuat teks, ikon selalu `aria-hidden` dengan teks pendamping.
- Tabel memiliki `<caption>` (kadang tersembunyi secara visual) dan `scope="col"`.
- `prefers-reduced-motion: reduce` mematikan animasi dan transisi.
- Kontras: teks utama `#1c2320` di atas `#ffffff`/`#f4f6f4`, dan teks putih di
  atas `#0f5132`. Keduanya jauh di atas ambang WCAG AA.

## 8. Cetak

`@media print` membuang topbar, sidebar, latar laci, tab, tindakan halaman, dan
seluruh elemen `.ah-no-print`. Halaman cetak/PDF khusus (`laporan_absensi_cetak.php`,
`portal/laporan_cetak.php`) tetap **tidak** memuat kerangka bersidebar sama
sekali, dan margin, pagination, nomor halaman, serta koreksi Safari/Android/iOS
dari Fase 5 tidak diubah.

## 9. Yang sengaja TIDAK dilakukan

- Tidak ada animasi berat, parallax, atau dekorasi tanpa kebutuhan pengguna.
- Tidak ada grafik baru tanpa pertanyaan yang dijawabnya.
- Tidak ada font eksternal baru — tipografi memakai `system-ui`.
- Tidak ada penggantian Bootstrap dengan framework CSS lain.
