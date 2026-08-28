# Koreksi pagination laporan absensi pada Safari

Tanggal: 28 Agustus 2026  
Branch: `fix/prd-v2-fase-5-absensi-safari-pagination`

## Bukti kegagalan produksi

PDF Safari A4 lanskap untuk 36 baris absensi memiliki empat halaman fisik,
sementara footer server menyatakan total tiga halaman. Urutannya:

1. halaman fisik 1: baris 1–10, `Halaman 1 dari 3`;
2. halaman fisik 2: baris 11–24, tanpa footer;
3. halaman fisik 3: hanya footer `Halaman 2 dari 3`;
4. halaman fisik 4: baris 25–36, `Halaman 3 dari 3`.

Data tidak hilang, tetapi hubungan halaman fisik dan nomor server salah. Ini
adalah halaman hantu dan tidak memenuhi kriteria cetak Fase 5.

## Akar masalah

Perkiraan tinggi mengizinkan 14 baris pada lembar lanjutan kedua. Safari
mengukur baris sedikit lebih tinggi daripada Chromium. Tabel tetap berada pada
halaman kedua, tetapi ruang tersisa tidak cukup untuk footer sehingga footer
dipindahkan ke halaman tersendiri.

## Koreksi

`PrintRenderer` menaikkan reservasi tinggi kepala/cadangan lembar lanjutan dari
18 mm menjadi 26 mm. Delapan milimeter tambahan membuat pola 36 baris menjadi
`10/13/13`. Font, lebar kolom, skala, data, query, API, dan laporan perizinan
tidak berubah.

## Regresi

`tests/v2_phase5_cetak_pdf.php` menambahkan fixture sintetis yang bentuknya
meniru keluaran produksi Safari: enam filter aktif, 36 baris, nama guru tiga
kata, kelas, tanggal, jadwal, peserta pendek/panjang, pencatat, dan waktu
perubahan.

Gerbang khususnya:

- tiga lembar logis;
- pembagian persis `10/13/13`;
- jumlah halaman fisik sama dengan jumlah lembar;
- penomoran berurutan dan tidak ada `Halaman 0`;
- seluruh 36 baris tetap dicetak.

## Batas verifikasi

PDF Chromium hasil koreksi sudah dirender dan diperiksa visual tanpa halaman
kosong. Uji Safari produksi harus diulang setelah deployment karena Safari
nyata merupakan mesin yang menemukan perbedaan awal.
