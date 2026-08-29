# Audit Codex — PRD V2 Fase 5

Tanggal audit: 28 Agustus 2026

Branch web: `prd-v2-fase-5`

Branch mobile: `prd-v2-fase-5`

## Keputusan

**Fase 5 belum lolos rilis produksi.** Implementasi inti laporan dan isolasi
cakupan bekerja pada pengujian layanan, tetapi masih ada satu ketidaksesuaian
fungsional yang terbukti dan beberapa gerbang manual/produksi yang belum
dijalankan.

## Temuan audit

### 1. CSV tidak memuat seluruh hasil filter — temuan historis, ditutup 29 Agustus 2026

Audit membuat 20.004 pengajuan sintetis dengan filter yang sama. Hasil aktual:

```text
ringkasan_total = 20004
baris_csv       = 20000
batas           = 20000
terpotong       = true
```

Ini bertentangan dengan kriteria penerimaan “CSV memuat seluruh hasil filter”.
Tes implementor hanya membuktikan ekspor mengabaikan pagination pada dataset
di bawah batas dan justru menerima perilaku `terpotong` sebagai pagar memori.

Koreksi auditor: `IzinReportService::csv()` sekarang menolak hasil di atas
batas dengan HTTP `422` dan kode `EXPORT_TOO_LARGE`, sehingga sistem tidak lagi
mengirim berkas parsial yang tampak lengkap. Pada 29 Agustus 2026 pemilik
produk menetapkan 20.000 baris sebagai batas resmi ekspor per permintaan.
Dengan keputusan tersebut temuan ini ditutup: hasil sampai batas wajib lengkap,
sedangkan hasil di atas batas wajib ditolak dan pengguna mempersempit filter.

### 2. PDF belum diverifikasi secara visual

HTML cetak memuat identitas pesantren, filter, pembuat, waktu, keputusan, dan
deklarasi CSS nomor halaman. Namun pengujian otomatis hanya mencari string
seperti `counter(page)`/`counter(pages)`; belum ada PDF nyata yang dirender dan
diperiksa untuk memastikan nomor halaman benar-benar tampil serta tabel tidak
terpotong. Gerbang ini tetap menunggu uji visual PDF pada browser/perangkat.

### 3. Gerbang manual dan produksi masih terbuka

- Android dan iOS nyata untuk pengurus, murobi, admin, dan orang tua belum diuji.
- Deep-link push foreground/background/cold-start belum diuji pada perangkat nyata.
- Receipt Expo nyata, cron cPanel, migrasi/restore produksi, dan smoke test produksi belum diverifikasi.
- WhatsApp-on tetap ditangguhkan; WhatsApp-off terbukti tidak memanggil provider.

### 4. Advisori dependensi mobile

`npm audit --omit=dev` melaporkan 16 advisori (12 moderat, 4 tinggi), terutama
rantai Metro → `image-size` dan perkakas Expo → `uuid`. Build, TypeScript, dan
lint tetap lulus. Jangan menjalankan `npm audit fix --force` karena usulnya
menurunkan Expo secara breaking; evaluasi pembaruan yang kompatibel dengan SDK
57 secara terpisah sebelum rilis.

## Pengujian auditor

| Pemeriksaan | Hasil |
| --- | --- |
| Status Git, lima commit terakhir, diff Fase 5, dan `git diff --check` | Lulus; working tree awal bersih |
| 10 suite statis PHP | 1.194 pemeriksaan lulus |
| Integrasi V1, integrasi V2 Fase 1–5, concurrency, dan performa | Lulus pada database sintetis sementara |
| `tests/v2_phase5_integration.php` | 143 pemeriksaan lulus |
| `tests/v2_phase5_performance.php` | 12 pemeriksaan lulus pada 1.000 fixture |
| Empat suite HTTP berserver anak | Tidak dapat diverifikasi ulang penuh: sandbox menolak koneksi DB dari proses server anak; log menunjukkan kegagalan lingkungan, bukan assertion bisnis |
| Endpoint API melalui server uji langsung | Login sintetis dan endpoint dasar menjawab 200 |
| `npx tsc --noEmit` | Lulus setelah `npm ci` memulihkan instalasi lokal yang tidak lengkap |
| `npx expo lint` | Lulus |
| `npx expo export -p web` | Lulus; 31 rute statis termasuk `/izin/laporan` |
| Uji 20.004 hasil | Gagal kriteria CSV: hanya 20.000 baris sebelum koreksi penolakan parsial |

Pemeriksaan remote read-only juga membuktikan baseline mobile `da04c3a` sudah
ada pada `origin/prd-v2-fase-4`. Branch Fase 5 web dan mobile belum ada di
GitHub pada saat audit; keduanya perlu didorong setelah commit audit dibuat.

Database audit hanya berisi data sintetis, dibuat sementara, lalu dihentikan
dan dihapus. Database produksi tidak disentuh.

## Syarat sebelum menyatakan Fase 5 selesai

1. Pertahankan kontrak batas produk 20.000 baris: hasil sampai batas harus
   lengkap; hasil di atas batas harus ditolak `422` tanpa berkas parsial.
2. Render PDF multi-halaman dan periksa visual identitas, filter, pembuat,
   waktu, keputusan, nomor halaman, serta pemenggalan tabel.
3. Selesaikan checklist perangkat Android/iOS untuk empat peran.
4. Jalankan preflight, backup/restore `_test`, migrasi, verifikasi, cron, dan
   smoke test pada staging/cPanel sesuai runbook.
5. Pertahankan WhatsApp `OFF` sampai seluruh prasyarat provider dan uji admin
   disetujui.
