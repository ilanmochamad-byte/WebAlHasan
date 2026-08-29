# Penutupan PRD V2 Fase 5

Tanggal keputusan: **29 Agustus 2026**
Keputusan: **SELESAI PRODUKSI DENGAN RISIKO RESIDUAL DITERIMA PEMILIK PRODUK**

Dokumen ini membedakan bukti yang benar-benar lulus dari skenario yang tidak
diulang. Instruksi pemilik produk untuk menutup Fase 5 merupakan penerimaan
risiko residual, bukan pengubahan hasil kosong menjadi klaim lulus.

## 1. Audit otomatis final

| Gerbang | Hasil |
| --- | --- |
| Seluruh suite web/API/migrasi/receipt/regresi V1–V2 | **29 berkas, 2.337 pemeriksaan, 0 gagal** |
| PDF sungguhan Chromium (lanskap, potret, simulasi Safari) | **76 pemeriksaan lulus** |
| Pembatalan cetak iOS | **6 uji terarah lulus** |
| `npx tsc --noEmit` | **LULUS** |
| `npx expo lint` | **LULUS** |

Koreksi terakhir memperlakukan penutupan dialog cetak iOS
`PrintIncompleteException` sebagai hasil `dibatalkan`. Galat printer lain tetap
diteruskan dan tetap tampil sebagai kegagalan. Kedua modul laporan menggunakan
normalisasi yang sama, dan perilakunya dikunci oleh uji regresi.

## 2. Checklist manual singkat berbasis risiko

| Area | Bukti aktual | Keputusan |
| --- | --- | --- |
| Web lintas cakupan | Screenshot produksi admin, pengurus, murobi, dan orang tua; isolasi cakupan juga diuji pada layanan, HTTP, dan HTML | **LULUS** |
| Filter dan ringkasan | Total 4; filter Diajukan 3; filter Disetujui 1 | **LULUS** |
| CSV produksi | Dua berkas berisi 4 dan 1 hasil; 30 kolom; BOM UTF-8; nol sel formula berbahaya | **LULUS** |
| PDF web | Perizinan dan absensi diperiksa pada Safari; pagination dan isi setelah koreksi sesuai | **LULUS** |
| PDF perangkat | PDF fisik Android dan iOS A4 lanskap, margin sekitar 1 cm, nomor halaman dan baris lengkap | **LULUS** |
| Migrasi produksi | Preflight, backup 47 tabel, restore `_test`, migrasi 009, verifikasi 22/22 pada `_test` dan produksi | **LULUS** |
| Cron | Jalur PHP benar `/opt/alt/php83/usr/bin/php`; worker per menit dan receipt per 15 menit berjalan; pemeriksaan kesehatan 6/6 | **LULUS** |
| Push nyata | Perangkat aktif 1; notifikasi diterima pengguna sebelum 15 menit; worker berakhir pada menunggu 0, terkirim 7, gagal 0 | **LULUS** |
| Receipt nyata | 29 Agustus 22:15 WIB: diperiksa 3, terkirim 3, gagal 0; status 22:23 WIB: menunggu 0, terkirim 3 | **LULUS** |
| WhatsApp | Tetap `OFF`; nol request provider saat mati | **LULUS untuk OFF; ON DITANGGUHKAN** |
| Pembatalan cetak iOS | Koreksi sumber + 6 uji otomatis lulus; uji ulang fisik memerlukan build baru | **DITERIMA sebagai risiko residual non-blocking** |

Receipt historis `Tidak Tersedia: 1` bukan receipt tertahan dan tidak
menunjukkan kegagalan cron. Setelah rekonsiliasi final, `Menunggu: 0`.

## 3. Risiko residual yang diterima

Butir berikut **tidak dinyatakan lulus** dan dipindahkan menjadi regresi
pascarilis atas keputusan pemilik produk:

1. matriks lengkap pengurus, murobi, admin, dan orang tua pada Android serta
   iOS belum diulang untuk setiap tombol;
2. deep-link push foreground, background, cold start, akun berganti, dan data
   di luar cakupan belum diuji satu per satu pada kedua sistem operasi;
3. Dynamic Type, keadaan offline, dan akun multi-peran belum diuji lengkap;
4. skenario destruktif menghapus aplikasi untuk memicu
   `DeviceNotRegistered` tidak dilakukan;
5. pembatalan dialog cetak iOS setelah koreksi belum diulang pada build fisik.

Risiko akses data pada deep-link diperkecil oleh otorisasi server yang selalu
dihitung ulang dan telah lulus pengujian lintas cakupan. Risiko UX pembatalan
cetak diperkecil oleh klasifikasi exception yang sempit dan uji bahwa galat
printer nyata tidak disembunyikan.

## 4. Keputusan akhir

- Batas ekspor CSV final: **20.000 baris** per permintaan.
- Hasil di atas batas ditolak `422 EXPORT_TOO_LARGE`; CSV parsial dilarang.
- WhatsApp tetap **DITANGGUHKAN**, tidak diuji, dan tidak dinyatakan lulus.
- Fase 5 dinyatakan **selesai** dengan risiko residual pada §3 diterima pemilik
  produk.
- PRD V2 tidak mendefinisikan Fase 6. Pekerjaan berikutnya harus dibuat sebagai
  PRD/roadmap baru, bukan dianggap kelanjutan otomatis Fase 5.
