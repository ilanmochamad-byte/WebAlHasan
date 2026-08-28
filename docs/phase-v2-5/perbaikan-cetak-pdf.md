# Perbaikan cetak/PDF laporan — Fase 5

Dokumen ini menjelaskan perbaikan dua cacat visual pada hasil cetak/PDF laporan
perizinan (dan laporan absensi V1 yang mengidap cacat yang sama), beserta bukti
yang mendasarinya.

Branch: `fix/prd-v2-fase-5-print-pdf`.
Tidak ada perubahan pada query, cakupan role, kontrak API, format CSV,
database, migrasi, cron, maupun notifikasi.

---

## 1. Bukti yang dianalisis

PDF produksi `Laporan Perizinan Santri - Pesantren Al Hasan.pdf` (dibuat lewat
Safari) diperiksa langsung, bukan disimpulkan dari kode:

| Yang diukur | Nilai |
| --- | --- |
| `MediaBox` | `0 0 595.2 841.68` → **A4 potret**, walaupun CSS meminta lanskap |
| Jumlah halaman | 1 |
| Teks akhir dokumen | `Halaman 0` |
| Pemenggalan kata | `N o.`, `Sumb er`, `Disetuju i`, `Diajuka n`, `Keputusa n`, `Dura si`, `terse dia`, `FATHUROH MAN`, `IBTIDA PA` |

---

## 2. Akar masalah

### 2.1 `Halaman 0` — penomoran halaman tidak pernah bekerja

CSS lama memakai dua mekanisme sekaligus, dan **keduanya tidak berfungsi** pada
mesin cetak peramban:

```css
@page { @bottom-center { content: "Halaman " counter(page) " dari " counter(pages) } }
.page-footer { position: fixed; bottom: -11mm }
.page-footer:after { content: "Halaman " counter(page) }
```

1. Kotak margin `@page { @bottom-center }` hanya didukung mesin Paged Media
   (Prince, WeasyPrint, Paged.js). Chrome, Safari, dan WebKit di balik
   `expo-print` **mengabaikannya sepenuhnya** — ia tidak pernah mencetak apa pun.
2. `counter(page)` di dalam elemen `position: fixed` **bukan** penghitung
   konteks halaman. WebKit/Safari mengevaluasinya menjadi **0**; itulah sumber
   `Halaman 0` pada PDF produksi. Chromium pun tidak konsisten.
3. `bottom: -11mm` menaruh footer **di luar kotak halaman**, sehingga mesin
   cetak menambah satu halaman hantu. Terbukti: dokumen 3 baris menghasilkan
   2 halaman, halaman kedua berisi `Halaman 0 Halaman 2`.

Jadi tidak ada satu pun mekanisme nomor halaman yang benar-benar bekerja —
bukan sekadar salah angka.

### 2.2 Kata terpotong di tengah

`th, td { overflow-wrap: anywhere }` pada tabel `table-layout: fixed`
mengizinkan mesin cetak memutus kata di **posisi karakter mana pun** demi
mempersempit kolom. Pada orientasi potret kolom menyempit drastis (11 kolom pada
lebar 190 mm), sehingga hampir setiap sel terpotong.

### 2.3 Orientasi tidak dipatuhi

`@page { size: A4 landscape }` bersifat **anjuran**. Chrome menghormatinya
(841,92 × 594,96 pt), sedangkan Safari dan dialog cetak macOS memakai orientasi
pilihan pengguna (terbukti 595,2 × 841,68 pt pada PDF produksi). `expo-print`
tidak membacanya sama sekali dan memakai bawaannya sendiri: **US Letter potret
612 × 792**.

---

## 3. Perbaikan

### 3.1 Nomor halaman dipindahkan ke server

Kelas baru `app/Report/PrintLayout.php` memecah dokumen menjadi **lembar**, dan
setiap lembar membawa teks `Halaman i dari n` miliknya sendiri sebagai teks
biasa. Nilainya dihitung PHP, sehingga:

- identik pada Chrome, Safari, `expo-print`, dan mesin apa pun berikutnya;
- dapat diuji tanpa peramban;
- mustahil menghasilkan `Halaman 0`, karena angkanya tidak pernah berasal dari
  `counter(page)`;
- tidak ada elemen `position: fixed`, sehingga halaman hantu hilang.

Pemisah halaman dibuat eksplisit (`.lembar { break-after: page }`), bukan hasil
tebakan mesin cetak — itulah yang membuat **jumlah lembar = jumlah halaman
fisik PDF** pada orientasi mana pun.

Nomor halaman **tidak dihilangkan**; PRD mensyaratkannya dan ia kini justru
menjadi satu-satunya sumber penomoran pada dokumen.

### 3.2 Anggaran tinggi untuk dua orientasi sekaligus

Karena orientasi tidak dapat dipaksakan, satu lembar harus muat pada A4 lanskap
**maupun** potret. Keduanya dianggarkan terpisah, masing-masing dengan lebar,
tinggi, dan ukuran huruf orientasinya sendiri:

| | Lebar area | Tinggi area | Huruf |
| --- | --- | --- | --- |
| Lanskap | 277 mm | 176 mm | 8 pt |
| Potret | 190 mm | 263 mm | 7,2 pt |

Sebuah baris ditambahkan ke lembar berjalan hanya bila ia masih muat pada
**kedua** anggaran. Perkiraan tinggi selalu dibulatkan ke atas: meremehkan
tinggi jauh lebih berbahaya daripada melebihkannya, karena itulah yang membuat
lembar meluber dan nomor halaman meleset.

### 3.3 Pemenggalan kata dihentikan

| Sebelum | Sesudah |
| --- | --- |
| `overflow-wrap: anywhere` pada `th, td` | `overflow-wrap: break-word` + `word-break: normal` + `hyphens: none` |
| — | `th { overflow-wrap: normal }` — judul kolom hanya membungkus pada spasi |
| — | `.utuh { white-space: nowrap }` untuk tanggal, agar `2026-08-25` tidak pecah pada tanda hubung |

Lebar kolom dipilih ulang sehingga **setiap kata judul muat utuh pada orientasi
tersempit**, termasuk padding sel yang sebelumnya terlupa dikurangkan (asal
cacat `Duras i`).

- Perizinan (11 kolom): `4, 7, 12, 11, 10, 12, 11, 7, 10, 10, 6`
- Absensi (10 kolom): `4, 8, 11, 9, 7, 9, 11, 7, 16, 18`

### 3.4 Orientasi: dipaksa bila bisa, dijelaskan bila tidak

| Jalur | Perlakuan |
| --- | --- |
| Chrome / Chromium | `@page { size: A4 landscape }` dihormati → A4 lanskap (terbukti 841,9 × 595 pt) |
| Safari / dialog cetak macOS | Tidak dapat dipaksa. Halaman menampilkan petunjuk cetak lanskap yang jelas (tersembunyi saat mencetak), dan **hasil potret tetap terbaca**: huruf mengecil ke 7,2 pt, tidak ada kata terpotong, nomor halaman tetap benar |
| Expo Print (Android/iOS/web) | Ukuran kertas diminta lewat opsi `width: 842, height: 595` (A4 lanskap pada 72 PPI) yang tersedia **lintas platform** pada SDK 57, plus `orientation: Print.Orientation.landscape` untuk dialog cetak iOS |

Rujukan Expo: <https://docs.expo.dev/versions/v57.0.0/sdk/print/> —
`width`/`height` tersedia di seluruh platform bila sumbernya HTML (bawaan
612 × 792 US Letter); `orientation` hanya iOS dan hanya pada `printAsync`
(`FilePrintOptions` milik `printToFileAsync` tidak memilikinya).

### 3.5 Baris data tidak terbelah antarhalaman

`tr, td, th { break-inside: avoid; page-break-inside: avoid }`, ditambah
pemecahan lembar di sisi server yang menempatkan setiap baris utuh pada satu
lembar. Judul kolom diulang pada setiap lembar, dan identitas pesantren, waktu
pembuatan, serta nomor halaman dibawa pada **setiap** lembar sehingga satu
halaman yang terlepas dari berkasnya tetap dapat dipertanggungjawabkan.

---

## 4. Berkas yang berubah

### Repositori web (`WebAlHasan`)

| Berkas | Perubahan |
| --- | --- |
| `app/Report/PrintLayout.php` | **BARU.** Pemecahan lembar, anggaran dua orientasi, footer nomor halaman, petunjuk orientasi, dan CSS cetak bersama |
| `app/Report/IzinPrintRenderer.php` | Ditulis ulang di atas `PrintLayout`; lebar kolom dan sel baris dirapikan |
| `app/Report/PrintRenderer.php` | Ditulis ulang di atas `PrintLayout`; **tanda tangan publik `report()` tidak berubah** |
| `tests/v2_phase5_cetak_pdf.php` | **BARU.** Regresi cetak/PDF (lihat §5) |
| `tests/browser/cetak-pdf.mjs` | **BARU.** Perender PDF Chromium untuk pengujian, dengan mode peniru Safari |
| `tests/phase5_static.php` | Pemeriksaan `counter(page)` diganti pemeriksaan nomor halaman sungguhan |
| `tests/phase5_integration.php` | idem |
| `tests/v2_phase5_static.php` | idem, ditambah pemeriksaan jalur Expo Print |
| `tests/v2_phase5_integration.php` | idem |
| `tests/v2_phase5_api_contract.php` | idem |
| `tests/v2_phase5_web_smoke.php` | idem |
| `bin/v2_phase5_run_all_tests.sh` | Menjalankan pengujian cetak/PDF baru |

### Repositori aplikasi (`alhasanApps`)

| Berkas | Perubahan |
| --- | --- |
| `src/report/print-page.ts` | **BARU.** Konstanta ukuran halaman A4 lanskap untuk `expo-print` |
| `src/report/izin-report-document.ts` | `printAsync`/`printToFileAsync` memakai opsi A4 lanskap |
| `src/report/report-document.ts` | idem |

---

## 5. Pengujian

### 5.1 Pengujian baru: `tests/v2_phase5_cetak_pdf.php`

Pemeriksaan lama hanya mencari string `counter(page)` pada HTML. Itu tidak
pernah dapat membuktikan hasil PDF: cacatnya justru muncul **ketika mesin cetak
mengevaluasi** `counter(page)`. Pengujian baru bekerja pada dua tingkat.

**Bagian A — deterministik, tanpa peramban.** Selalu dijalankan.
Memeriksa pemecahan lembar, dokumen kosong, dokumen satu lembar, dokumen banyak
lembar, urutan nomor halaman `1..n`, ketiadaan `Halaman 0`, ketiadaan
`counter(page)`/`position:fixed`, pengulangan judul kolom, kehadiran identitas
dan waktu pada setiap lembar, dan bahwa setiap baris data dirender tepat sekali.

**Bagian B — PDF sungguhan lewat Chromium (Playwright)** pada tiga orientasi:

| Mode | Arti |
| --- | --- |
| `css` | Mesin menghormati `@page` (perilaku Chrome) |
| `lanskap` | Pengguna memilih lanskap sendiri |
| `potret` | Mesin **mengabaikan** `@page` dan memakai potret — meniru Safari / dialog cetak macOS |

Untuk tiap PDF diperiksa: jumlah halaman fisik (`pdfinfo`) sama dengan jumlah
lembar; nomor halaman `1..n` berurutan, satu per halaman; tidak ada
`Halaman 0`; orientasi kertas; kata-kata yang dulu terpotong kini utuh pada
teks hasil `pdftotext -layout`; dan tanggal tidak pecah pada tanda hubung.

Bila Node, Playwright, atau poppler tidak tersedia, Bagian B ditandai
**MENUNGGU VERIFIKASI** — tidak pernah dilaporkan lulus tanpa bukti.

```bash
V2_PHASE5_PDF_KELUARAN=/tmp/pdfbukti php tests/v2_phase5_cetak_pdf.php
```

### 5.2 Pengujian lama yang diperkuat

Enam pemeriksaan yang hanya mencari string `counter(page)` **diganti** dengan
pemeriksaan hasil: nomor halaman `1..n` berurutan, total konsisten, tidak ada
`Halaman 0`, dan tidak ada `counter(page)`/`position:fixed` pada CSS yang
dihasilkan. Tidak ada pemeriksaan yang dilonggarkan agar menerima keluaran
rusak; seluruhnya menjadi lebih ketat.

### 5.3 Hasil

```
MOBILE_APP_ROOT=/path/ke/alhasanApps bash bin/v2_phase5_run_all_tests.sh
```

| Sebelum | Sesudah |
| --- | --- |
| 28 berkas uji, 2.230 pemeriksaan, 0 gagal | **29 berkas uji, 2.376 pemeriksaan, 0 gagal** |

Aplikasi (`alhasanApps`): `npx tsc --noEmit` tidak menghasilkan galat baru
(dua galat yang tersisa berasal dari `expo-env.d.ts` yang memang dibangkitkan
saat `expo start` dan tidak disimpan di repositori); `npx expo lint` keluar 0.

---

## 6. PDF pembanding

Dibangkitkan oleh `tests/v2_phase5_cetak_pdf.php`:

| Berkas | Isi |
| --- | --- |
| `izin-1hal-{css,lanskap,potret}.pdf` | Perizinan 3 baris → 1 halaman, `Halaman 1 dari 1` |
| `izin-banyakhal-{css,lanskap,potret}.pdf` | Perizinan 40 baris → 6 halaman, `Halaman 1 dari 6` … `Halaman 6 dari 6` |
| `absensi-1hal-{css,lanskap,potret}.pdf` | Absensi 3 baris → 1 halaman |
| `absensi-banyakhal-{css,lanskap,potret}.pdf` | Absensi 400 baris → 41 halaman |

Pada seluruh dua belas berkas: `grep -c "Halaman 0"` = 0, jumlah halaman fisik
sama dengan jumlah lembar, dan tidak ada kata yang terpotong.

---

## 7. Yang masih MENUNGGU VERIFIKASI

| Hal | Alasan |
| --- | --- |
| Hasil PDF pada Safari macOS **nyata** | Perilaku Safari ditiru dengan menyuntikkan `@page{size:A4 portrait}` di akhir cascade pada Chromium. Itu membuktikan kasus "mesin mengabaikan `@page`", bukan Safari itu sendiri |
| Hasil PDF dari perangkat Android/iOS **nyata** lewat `expo-print` | Memerlukan perangkat fisik; belum tersedia |
| Tampilan cetak dari dialog cetak macOS | idem |

Ketiganya perlu dicoba manusia sebelum kriteria orientasi dinyatakan penuh.
