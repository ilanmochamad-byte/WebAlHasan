# V2 Fase 3 — Build Aplikasi dan Checklist Smoke Test Android/iOS

Repositori aplikasi: `alhasanApps`, branch `prd-v2-fase-3`.
Expo SDK 57, React Native 0.86, Expo Router, TypeScript strict — **tidak diubah**.

## 1. Prasyarat

```bash
cd /path/ke/alhasanApps
npm ci
cp .env.example .env.local     # lalu isi EXPO_PUBLIC_API_BASE_URL
```

`.env.local` (tidak di-commit):

```
EXPO_PUBLIC_API_BASE_URL=https://<host-staging>/api/v1
```

Alamat API bukan secret, tetapi tetap tidak ditulis di source code. Gunakan
host **staging**, bukan produksi, untuk smoke test.

## 2. Pemeriksaan otomatis

```bash
npx expo lint            # setara `npm run lint`
npx tsc --noEmit
npx expo export -p web   # memastikan seluruh rute ter-bundle
```

Jika `tsc` mengeluh tentang rute (fitur `typedRoutes` aktif), jalankan
`npx expo start` sekali agar `.expo/types/router.d.ts` diregenerasi, lalu ulangi.

## 3. Menjalankan pada perangkat

```bash
# Android (perangkat/emulator terhubung, USB debugging aktif)
npx expo run:android

# iOS (memerlukan macOS + Xcode; perangkat terdaftar pada tim developer)
npx expo run:ios
```

Aplikasi memakai `expo-dev-client`, sehingga build development di atas adalah
jalur yang benar (bukan Expo Go).

Untuk build yang dapat dibagikan ke penguji, gunakan EAS sesuai konfigurasi
proyek yang berlaku; Fase 3 tidak mengubah konfigurasi build.

## 4. Akun uji

Gunakan akun **staging** yang setara dengan fixture sandbox:

| Peran | Yang harus dimiliki akun |
| --- | --- |
| Pengurus | role `pengurus`, relasi `pengurus` aktif, ≥1 penugasan pembimbing aktif |
| Murobi | role `guru`, relasi `guru` aktif, ≥1 `murobi_assignments` aktif pada tahun ajaran aktif |
| Guru non-murobi | role `guru` tanpa penugasan murobi (untuk uji negatif) |
| Admin | role `admin` |
| Orang tua | role `orang_tua`, relasi `wali` aktif dengan ≥1 santri |

Siapkan pula minimal satu santri yang menghasilkan **dua** kandidat murobi dan
satu santri **tanpa** kandidat, agar antrean admin dapat diuji.

## 5. Checklist smoke test — Android

Isi kolom hasil saat pengujian dilakukan. **Jangan menandai lulus tanpa
menjalankannya pada perangkat nyata.**

| No | Perangkat/OS | Langkah | Hasil yang diharapkan | Hasil |
| --- | --- | --- | --- | --- |
| A-01 | Android __ / OS __ | Buka aplikasi, login sebagai **pengurus** | Login sukses; tab **Beranda** dan **Perizinan** tampil; tab Jadwal & Laporan **tidak** tampil | ☐ |
| A-02 | | Buka tab Perizinan | Mode "Pengurus"; antrean tampil; loading state terlihat lalu digantikan data atau empty state | ☐ |
| A-03 | | Ketuk "Buat pengajuan izin" → cari santri | Hanya santri dalam cakupan pembimbing yang muncul; pencarian bekerja | ☐ |
| A-04 | | Isi tanggal terbalik lalu Tinjau | Pesan validasi lokal muncul, tidak ada request terkirim | ☐ |
| A-05 | | Isi data benar → Tinjau → Kirim | Berpindah ke detail pengajuan; status `Diajukan` atau `Perlu Penetapan Admin` | ☐ |
| A-06 | | Ketuk "Kirim pengajuan" dua kali cepat pada layar konfirmasi | Tombol nonaktif setelah ketukan pertama; **hanya satu** pengajuan terbuat | ☐ |
| A-07 | | Aktifkan mode pesawat lalu tarik-untuk-muat-ulang | Pesan offline yang dapat ditindaklanjuti, bukan crash | ☐ |
| A-08 | | Matikan mode pesawat, kirim ulang pengajuan yang tadi gagal karena offline | Tidak muncul pengajuan ganda (kunci idempotensi sama) | ☐ |
| A-09 | | Kembali ke daftar, buka pengajuan, coba batalkan tanpa alasan | Ditolak `422` dengan pesan jelas | ☐ |
| A-10 | | Batalkan dengan alasan | Status menjadi `Dibatalkan`; riwayat bertambah, tidak ada yang hilang | ☐ |
| A-11 | Android __ | Login sebagai **murobi** | Tab Perizinan, Jadwal, dan Laporan tampil | ☐ |
| A-12 | | Buka antrean murobi | Hanya pengajuan yang diarahkan kepadanya | ☐ |
| A-13 | | Setujui dengan alasan | `201`; status `Disetujui`; riwayat memuat peristiwa keputusan | ☐ |
| A-14 | | Coba putuskan lagi pengajuan yang sama | Ditolak `409` tanpa menimpa keputusan pertama | ☐ |
| A-15 | Android __ | Login sebagai **guru non-murobi** | Tab Perizinan **tidak** tampil; Jadwal & Laporan tetap normal | ☐ |
| A-16 | Android __ | Login sebagai **admin** | Seluruh tab tampil; mode Admin tersedia | ☐ |
| A-17 | | Buka antrean admin → pengajuan `Perlu Penetapan Admin` | Kandidat routing tampil; pilih murobi + alasan → tetapkan | ☐ |
| A-18 | | Pada pengajuan tanpa murobi, putuskan sebagai Admin Pengganti tanpa alasan penggantian | Ditolak `422` | ☐ |
| A-19 | | Ulangi dengan alasan penggantian | `201`; kapasitas tercatat `Admin Pengganti` | ☐ |
| A-20 | Android __ | Login sebagai **orang tua** | Hanya tab Beranda + Perizinan; daftar anak tampil | ☐ |
| A-21 | | Buka detail izin anak | Status & keputusan terbaca; **tidak ada** tombol setujui/tolak/batal/buat | ☐ |
| A-22 | | Konfirmasi hasil keputusan A-13 terlihat oleh orang tua dan pengurus | Konsisten di ketiga peran | ☐ |
| A-23 | Android __ | Logout dari salah satu akun | Kembali ke layar login; sesi tidak dapat dipakai lagi | ☐ |

## 6. Checklist smoke test — iOS

Ulangi seluruh baris A-01 s.d. A-23 pada perangkat iOS. Tambahan khusus iOS:

| No | Perangkat/OS | Langkah | Hasil yang diharapkan | Hasil |
| --- | --- | --- | --- | --- |
| I-01 | iPhone __ / iOS __ | Periksa tab bar native | Label & ikon tampil benar; tab tersembunyi tidak dapat dicapai lewat gestur | ☐ |
| I-02 | | Uji `SecureStore` setelah aplikasi ditutup penuh dan dibuka lagi | Sesi tetap ada (token tersimpan aman) | ☐ |
| I-03 | | Uji tampilan pada mode gelap dan terang | Kontras teks/tombol tetap terbaca | ☐ |
| I-04 | | Uji dengan Dynamic Type diperbesar | Tidak ada teks terpotong pada kartu pengajuan | ☐ |

## 7. Checklist regresi aplikasi guru (wajib, kedua platform)

| No | Langkah | Hasil yang diharapkan | Hasil |
| --- | --- | --- | --- |
| G-01 | Login guru → Beranda | Jadwal hari ini & jadwal berikutnya tampil seperti sebelumnya | ☐ |
| G-02 | Tab Jadwal → filter tanggal | Daftar jadwal tampil, pagination normal | ☐ |
| G-03 | Buka detail tugas → buka pertemuan | Pertemuan terbuka; retry tidak membuat pertemuan ganda | ☐ |
| G-04 | Simpan absensi | Tersimpan; koreksi meminta alasan seperti sebelumnya | ☐ |
| G-05 | Tab Laporan → filter → cetak/bagikan PDF | Laporan dan PDF berfungsi seperti Fase 5 V1 | ☐ |
| G-06 | Logout lalu coba akses dengan token lama (bila diuji lewat alat) | `401` | ☐ |

## 8. Pelaporan

Setelah pengujian, catat pada `acceptance-status.md`:

- merek/model dan versi OS perangkat Android dan iOS yang dipakai;
- tanggal pengujian dan nama penguji;
- nomor baris checklist yang gagal beserta bukti (tangkapan layar/log);
- keputusan: lulus / lulus dengan catatan / gagal.

Selama checklist ini belum dijalankan pada perangkat nyata, kriteria penerimaan
Fase 3 nomor 10 **tetap berstatus MENUNGGU** dan Fase 3 tidak boleh dinyatakan
selesai.
