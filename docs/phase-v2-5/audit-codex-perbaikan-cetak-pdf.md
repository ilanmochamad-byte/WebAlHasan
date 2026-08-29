# Audit Codex — perbaikan cetak/PDF Fase 5

Tanggal audit: 28 Agustus 2026  
Branch: `fix/prd-v2-fase-5-print-pdf`

## Ruang lingkup

- Web `92024a3` dari basis produksi `3adda35`.
- Mobile `846a708` dari basis `0b7e730`.
- Renderer laporan perizinan V2 dan absensi V1.
- Opsi A4 lanskap `expo-print` pada aplikasi.
- PDF satu halaman, banyak halaman, lanskap, potret, dan data batas.

## Temuan audit

### Alasan 2.000 karakter merusak penomoran fisik

Layanan menerima alasan izin dan alasan keputusan hingga 2.000 karakter.
Fixture Claude hanya memakai alasan pendek. Saat kedua kolom diisi mendekati
batas, `PrintLayout` memaksa satu baris raksasa ke satu lembar agar pemecahan
selalu maju. Chromium kemudian memecahnya menjadi empat halaman fisik, tetapi
footer server tetap `Halaman 1 dari 1`.

Temuan direproduksi pada tiga mode: CSS A4 lanskap, lanskap eksplisit, dan
potret peniru Safari. Ketiganya gagal dengan rasio `4 halaman fisik : 1
lembar server`.

## Koreksi terarah

1. Tambahkan `PrintLayout::pecahTeks()` untuk memecah teks panjang pada batas
   kata tanpa membuang isi.
2. Pecah alasan izin dan alasan keputusan menjadi fragmen maksimum 180
   karakter.
3. Cetak fragmen kedua dan seterusnya sebagai baris `Lanjutan` yang tetap
   memuat ID pengajuan.
4. Tambahkan fixture dengan dua alasan mendekati 2.000 karakter dan penanda
   awal/akhir untuk membuktikan isi tidak hilang.
5. Isi seluruh filter produksi pada fixture agar tinggi header nyata ikut
   diverifikasi.

Tidak ada perubahan pada query, database, migrasi, API, cakupan role, CSV,
cron, notifikasi, atau kontrak mobile.

## Bukti pengujian

- PHP lint untuk renderer, layout, dan pengujian: lulus.
- TypeScript `npx tsc --noEmit`: lulus.
- Expo lint: lulus.
- PDF Chromium nyata: seluruh kasus lulus pada lanskap dan potret.
- Kasus alasan maksimum setelah koreksi: 5 lembar server = 5 halaman fisik,
  nomor `1..5`, isi awal dan akhir kedua alasan tersedia.
- PDF 40 pengajuan: 6/6 halaman dan nomor berurutan.
- PDF absensi 400 baris: 41/41 halaman dan nomor berurutan.
- Pemeriksaan visual halaman pertama, lanjutan, terakhir, dan data batas:
  tidak ada clipping, overlap, halaman hantu, atau kata terpotong sembarang.
- Regresi gabungan: 28 berkas lulus. Satu berkas V1
  `tests/phase5_integration.php` tidak dapat menyelesaikan latihan restore pada
  mesin auditor karena akun MariaDB lokal tidak memiliki hak membuat database
  restore dinamis. Kegagalan terjadi pada setup restore, bukan pada assertion
  produk; berkas yang sama sudah dilaporkan lulus pada lingkungan Claude.

## Keputusan

**Perbaikan visual web dan mobile layak dilanjutkan ke uji manual.** Temuan
otomatis yang dapat direproduksi sudah ditutup. Sebelum merge/deploy tetap
wajib:

1. cetak ulang dari Safari macOS nyata;
2. buat/bagikan PDF lewat `expo-print` pada Android dan iOS nyata;
3. pastikan orientasi, nomor halaman, dan kata utuh pada berkas perangkat;
4. audit commit koreksi dan push kedua branch tanpa menyentuh `main` langsung.

Status ini tidak mengubah status keseluruhan Fase 5 saat audit dilakukan: uji
perangkat empat peran, CSV di atas 20.000 baris, dan WhatsApp-on masih mengikuti
gerbang rilis yang terdokumentasi. Keputusan produk 29 Agustus 2026 kemudian
menetapkan 20.000 sebagai batas resmi ekspor, sehingga butir CSV ditutup dengan
kontrak penolakan `422 EXPORT_TOO_LARGE` tanpa berkas parsial.
