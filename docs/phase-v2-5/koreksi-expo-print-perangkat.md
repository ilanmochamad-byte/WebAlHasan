# Koreksi pagination PDF pada aplikasi perangkat

Tanggal: 29 Agustus 2026  
Branch web dan mobile: `codex/fix-fase5-expo-print-pagination`

## Bukti masalah

PDF `alhasanApps.pdf` dibuat langsung oleh iOS 26.6.1 melalui jalur berbagi
PDF aplikasi. Ukuran kertas sudah benar, yaitu A4 lanskap 841,89 × 595,28 pt,
tetapi tiga lembar yang dihitung server berubah menjadi enam halaman fisik.

- halaman fisik 1, 3, dan 5 memuat tabel;
- halaman fisik 2, 4, dan 6 hanya memuat lanjutan baris 10, 23, dan 36 serta
  footer `Halaman 1/2/3 dari 3`;
- seluruh 36 baris masih ada, tetapi kontrak satu lembar server = satu halaman
  fisik tidak terpenuhi.

## Akar masalah

`expo-print` iOS memakai `WKWebView` dan `UIPrintPageRenderer`. Ukuran halaman
842 × 595 sudah diterapkan dengan benar, tetapi HTML sebelumnya membiarkan
`text-size-adjust` dan `line-height: normal` bergantung pada mesin. WebKit iOS
menghasilkan baris tabel lebih tinggi daripada Safari desktop sehingga setiap
lembar meluber sedikit ke halaman berikutnya.

Menurunkan skala dari dialog cetak bukan solusi karena hasilnya bergantung pada
pengguna dan turut mengecilkan lebar kolom serta seluruh tipografi.

## Koreksi

1. HTML mengunci `-webkit-text-size-adjust: 100%` dan `text-size-adjust: 100%`.
2. Sel tabel memakai `line-height: 1.15`, selaras dengan anggaran tinggi pada
   `PrintLayout`, tanpa mengubah ukuran font, skala halaman, atau pembagian
   server `10/13/13`.
3. Meta viewport mengikuti bentuk yang direkomendasikan dokumentasi Expo Print
   SDK 57 agar WebView tidak melakukan zoom tambahan.
4. `printToFileAsync` mobile menetapkan `textZoom: 100` secara eksplisit untuk
   Android. Opsi ini khusus Android; iOS memakai perlindungan CSS di atas.
5. Opsi cetak dan PDF iOS memakai margin horizontal native 29 pt (sekitar
   1,02 cm) di kiri dan kanan. Android dan peramban tetap memakai margin
   horizontal 10 mm dari aturan `@page`; margin vertikal native tidak ditambah
   agar pembagian halaman tetap stabil.

## Verifikasi

- PDF nyata Chromium untuk lanskap, potret, dan mode CSS: seluruh jumlah
  halaman fisik sama dengan jumlah lembar server.
- Fixture absensi produksi 36 baris: tetap `10/13/13`, tiga halaman, footer
  `1/3` sampai `3/3`, tanpa `Halaman 0`.
- Harness simulator iOS 26.2 memakai `WKWebView`, `viewPrintFormatter`, dan
  `UIPrintPageRenderer` yang sama dengan implementasi native `expo-print`:
  menghasilkan tepat tiga halaman A4 lanskap; baris 1–36 lengkap tanpa hilang
  atau duplikasi.
- `php tests/v2_phase5_static.php`, `npx tsc --noEmit`, dan `npx expo lint`
  lulus.

Uji ulang pada perangkat iOS fisik dan satu perangkat Android nyata tetap
wajib sebelum gerbang manual perangkat dinyatakan selesai.
