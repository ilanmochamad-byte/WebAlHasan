# Audit Codex — pencarian santri, IBTIDA PA/PI, dan versi aplikasi

**5 November 2026 — LULUS audit teknis lokal setelah koreksi F-1 (P2).** Tidak ada temuan fungsi yang masih memblokir pada lingkungan uji ini. Hasil ini tidak menyatakan paritas produksi atau kelayakan build distribusi; batas native di bawah merupakan bagian keputusan.

## Dasar dan cakupan

| Repositori | Branch yang diaudit dan dipakai untuk koreksi | Baseline main | Seluruh commit implementasi fase |
| --- | --- | --- | --- |
| WebAlHasan | `claude/pencarian-santri-absensi-ibtida` | `1c652e3` | `5e60638` |
| alhasanApps | `claude/pencarian-santri-absensi-ibtida` | `bd7833f`, versi 1.2.0 | `e4a0642`, versi 1.3.0 |

`git fetch origin` berhasil pada kedua repo; main remote masih baseline di atas. `AGENTS.md` kedua repo, handoff `implementasi/README.md`, seluruh perubahan fase dan rencana dari branch dokumentasi `codex/rencana-pencarian-santri-aplikasi` dibaca. Rencana pada branch fitur identik dengan branch dokumentasi. Tidak membuat worktree/subagen, bekerja di main, merge, deploy, migrasi produksi, atau merilis aplikasi.

Semua pengujian menggunakan data sintetis lokal. Aplikasi diuji dari arsip Git di `/private/tmp/pcs-codex-mobile`, tanpa `.env*`, dengan `npm ci` dari lock ter-commit (bukan dependensi kotor checkout pengguna). Dokumentasi [Expo SDK 57](https://docs.expo.dev/versions/v57.0.0/) dibaca sebelum koreksi mobile. PHP 8.4.14, MariaDB 12.3.2, Node 26.7.0; Chromium/Playwright, Maestro dan emulator/simulator lokal.

## F-1 (P2) — pencarian laporan kehadiran Keamanan terlewat, ditutup

Inventaris Claude menyebut daftar kehadiran berhalaman tidak diubah karena memakai `ReportFilter` bersama. Rencana telah mengizinkan perubahan API minimal untuk daftar besar; ketergantungan itu tidak membatalkan kebutuhan pencarian. Sebelum koreksi, `q` diabaikan dan tidak ada kolom pencarian di aplikasi.

Koreksi auditor menambah kolom nama/NIS dan reset halaman pada `src/app/keamanan/kehadiran/index.tsx`, parameter klien, serta `q` pada `GET /keamanan/kehadiran`. Server memvalidasi skalar/panjang, mencocokkan **snapshot nama/NIS yang ditampilkan laporan**, memperlakukan `%`/`_` secara harfiah, dan menerapkan predikat sebelum paginasi melalui sumber SQL yang sama untuk detail, ringkasan dan ringkasan jadwal. Cakupan santri aktif serta pemeriksaan penugasan tetap berlaku. Parameter pencarian diteruskan eksplisit oleh layanan Keamanan; endpoint `/reports` guru lama tetap mengabaikan `q`, dengan bentuk/hasil respons lama.

Probe independen yang sama (`tests/pencarian_santri_audit.php`) **6 lulus/9 gagal → 15 lulus/0 gagal**. Probe browser/HTTP tambahan **14/0** membuktikan santri di luar halaman pertama dari **48 catatan** ditemukan lewat nama maupun NIS setelah pengguna berada di halaman 2, halaman kembali ke 1, ID/angka cocok dengan oracle seluruh halaman, kosongkan pencarian memulihkan daftar, parameter tidak sah 422, dan `/reports` tidak berubah. Tidak ada kenaikan versi kedua: koreksi masih paket fitur 1.3.0 yang sama.

## Bukti fungsi dan regresi independen

Bukti lengkap ada di [`audit-codex/`](audit-codex/). Angka adalah lulus/gagal, kecuali dinyatakan exit.

| Pemeriksaan | Hasil | Bukti |
| --- | --- | --- |
| Absensi, rincian laporan guru dan rincian Keamanan (Expo web) | **53/0** | `expo-absensi.txt`, `tangkapan-absensi/` |
| Daftar izin/pelanggaran Keamanan serta pembinaan berhalaman | **24/0** | `expo-daftar.txt` |
| Probe koreksi laporan kehadiran: layanan / browser+HTTP | **15/0 · 14/0** | `probe-awal.txt`, `probe-akhir.txt`, `expo-probe.txt` |
| Kontrak pencarian server, izin dan V3 | **26/0**, diulang setelah koreksi | `kontrak-http.txt`, `regresi-web/pencarian-santri-http.txt` |
| Unit seluruh aplikasi / TypeScript / lint Expo | **77/0 / exit 0 / exit 0** | `mobile-unit.txt`, `mobile-tsc.txt`, `mobile-lint.txt` |
| Probe CLI versi independen | **8/0** | `versi-probe.txt`, `versi-probe.mjs` |
| Regresi aplikasi kelas gabungan / Keamanan | **57/0 · 46/0** | `expo-regresi-jkg.txt`, `expo-regresi-keamanan.txt` |
| Regresi V1 jadwal / absensi / laporan | **10/0 · 14/0 · 20/0** | `regresi-phase{3,4,5}_integration.txt` |
| Integrasi/konkurensi gabungan / HTTP web+REST+CSV | **71/0 · 32/0** | `regresi-jkg-integrasi.txt`, `regresi-jkg-http.txt` |
| Keamanan F4 HTTP / F3 HTTP / F3 integrasi | **66/0 · 44/0 · 44/0** | `regresi-web/` |
| V7 F3 integrasi / HTTP / pembinaan Tahap 5 HTTP | **112/0 · 43/0 · 106/0** | `regresi-web/` |
| Lint delapan berkas PHP aplikasi terkait dan dua probe | **10/10** | `lint-php.txt` |

Suite absensi membuktikan nama tanpa membedakan kapital, potongan NIS, hapus/kosong pencarian, dua nama sama dengan NIS berbeda, hitungan Semua/PA/PI serta kelas asal snapshot. Draf status **dan catatan berbeda** pada PA/PI tetap setelah pindah tab/kata kunci. Simpan ketika satu peserta terlihat tetap mengirim lima `student_id` unik; DB memuat tepat satu guru dan lima santri dengan status/catatan pada ID yang benar, lalu dibaca ulang. “Semua hadir (5)” juga mengubah status PI tersembunyi sesuai keterangan, tanpa mengganti catatan. Ringkasan tetap seluruh peserta.

Kelas hilang/di luar `task.classes` masuk `Kelas belum tercatat`, tanpa ditebak PA/PI. Satu kelas, gabungan non-IBTIDA, dan respons server lama tanpa `classes`/kelas peserta diuji, termasuk simpan. Kelas hilang/non-IBTIDA/server lama disimulasikan pada respons browser; bukan klaim DB nyata memuat snapshot rusak. Pencarian daftar berhalaman diuji dari halaman lain, hasil dibanding seluruh cakupan API akun yang sama; kasus Rahasia/nonaktif/di luar cakupan tetap tersembunyi. Galat 503, coba lagi, keadaan kosong dan pencabutan penugasan dicakup suite daftar/regresi Keamanan.

Penafsiran P-1–P-4 dan P-6 diterima: tab untuk seluruh gabungan, label pendek hanya bila nama kelas berbeda pada kata bagian yang terverifikasi, kelompok tidak dikenal eksplisit, Semua hadir menyasar seluruh peserta, pencarian lokal per kata dan server per potongan, NIS V3 aditif. P-5 diterima untuk batas perubahan aplikasi pada pemeriksa versi. Pengecualian inventaris laporan kehadiran diperbaiki melalui F-1; daftar pendek rekomendasi/publikasi/antrean tetap mengikuti inventaris dan kontrak lamanya.

## Versi

`origin/main:app.json` **1.2.0**, fitur dan salinan kerja final **1.3.0**; hanya `expo.version` berubah pada `app.json`. `versi:periksa` mode HEAD dan `--kerja` lulus terhadap main yang baru di-fetch. Tiga perintah `versi:*` tersedia; EAS tetap `appVersionSource: remote`/`autoIncrement` untuk nomor build.

Selain enam unit versi Claude, probe auditor membuat repo Git sementara dan benar-benar menjalankan CLI: perubahan kerja/HEAD tanpa kenaikan ditolak exit 1, HEAD 1.0.1 ditolak, bump minor menghasilkan 1.3.0, pengulangan idempoten, koreksi paket yang sama tidak memerlukan bump lagi, dan main yang maju ke 1.4.0 membuat branch 1.3.0 ditolak walaupun merge-base lama masih 1.2.0. Probe **8/0**, tanpa mengubah versi checkout pengguna.

## Android dan iOS — hasil dan batas build

Auditor **menjalankan ulang secara independen artefak Release lokal dari handoff**, tidak membangunnya ulang. Hash binary/bundel cocok dengan bukti Claude dan instalasi yang diperiksa. Seluruh **308 berkas src/assets** pada salinan sumber build cocok dengan `e4a0642`; lock cocok. `package.json` build hanya belum memuat tiga skrip versi (dependensi dan isi selain scripts identik). Manifest lengkap: `native-manifest.txt`.

| | Android | iOS |
| --- | --- | --- |
| Lingkungan | emulator Android 16/API 36, `emulator-5554` | iPhone 17 Pro simulator iOS 26.2 |
| Jenis | APK Release uji lokal, tanda tangan debug | Release arm64 simulator, ad-hoc Xcode |
| Versi pengguna | 1.3.0 | 1.3.0 |
| SHA-256 | APK `842b193ea9668d4947877c1cc57d2154d1fafc19a7ae97631f0ddbef2b81de11` | executable `1d7410f962e4483e038fd5b5483380556dce01b56c89abffa6cc7dd76cb8c9d3`; bundel `d0f001e2e16bb44ed3e97d73f7d30a5aae7929d68304ace738baa96f67e4f958` |
| Ulang native | **7/0** | **7/0** |

Akun `sbx_guru_biasa`, jadwal/tanggal/pertemuan 722, snapshot SQL awal dan proksi API sama (8962 → 8964). Snapshot dipulihkan sebelum masing-masing platform. Keduanya menampilkan Semua 5/PA 3/PI 2; tab PI hanya PI, pencarian NIS PA pada PI kosong, beralih Semua menemukan PA; PI1 Izin dan PA1 Sakit disimpan walau pencarian aktif. Masing-masing **satu PUT HTTP 200**, lima ID unik dengan status yang sama, DB satu guru/lima santri unik, dan cold start/tautan pertemuan memuat kembali PI1 Izin. Pasangan ID kelas–peserta identik `[[1722,340],[1723,340],[1724,340],[1725,341],[1726,341]]`. Paritas, proksi tanpa bearer/nama, ID fixture dan tangkapan terpilih ada di `native/`; hasil `native-android.txt`/`native-ios.txt`.

Hash JSON respons mentah berbeda; audit membandingkan kontrak kelas/peserta/status dan payload yang dicatat, **bukan** mengklaim seluruh byte respons identik. Pembukaan berlangsung pada waktu berbeda, tetapi seluruh selisih field tidak dianalisis. Perbedaan tampilan kelompok yang dilaporkan pengguna tidak terulang pada lingkungan ini; penyebab pada perangkat pengguna belum ditentukan.

**Batas:** binary tersebut adalah sumber implementasi `e4a0642`, belum memuat koreksi auditor F-1 pada daftar kehadiran. Kode absensi/pencarian/tab yang dibandingkan native tidak diubah auditor; koreksi F-1 final diuji melalui browser/API, belum melalui binary native baru. Native memakai prebuild lokal dan versi yang disetel manual; APK uji mengizinkan HTTP localhost dan belum mencerminkan seluruh `blockedPermissions` app.json. Bukan prebuild EAS bersih/AAB/IPA/TestFlight, bukan pembuktian izin build distribusi. Tidak mengulang build utuh, iOS Debug, perangkat fisik, Push nyata, akun/data/build produksi pengguna, MariaDB produksi/MySQL/cPanel atau UAT. Native mencakup status dan pencarian NIS; catatan PA/PI serta pencarian nama lengkap diuji melalui browser, bukan diklaim diuji ulang native. Gerbang produksi V5/V6/V7/kelas gabungan terdahulu tidak ditutup oleh audit ini.

## Diagnostik dan oracle historis

- TypeScript sebelum Expo membuat deklarasi CSS otomatis sempat exit 2 (`mobile-tsc-awal.txt`); setelah Expo dijalankan, tsc/lint final exit 0 tanpa koreksi source/dependensi untuk diagnostik itu.
- Percobaan native Android pertama berhenti karena `Sakit` masih di luar viewport sesudah hasil cari. Langkah `scrollUntilVisible` pada tombol ditambahkan ke `tests/android/pcs_native.py`; assertion tidak dilonggarkan. Snapshot dipulihkan dan kedua platform diulang sampai **7/0**. `diagnostik-native-android.txt` mempertahankan kegagalan awal. Probe browser pertama juga memakai fixture hanya 18 baris dan label pager yang keliru; fixture 48 baris/pager sesuai komponen memperbaiki alat, hasil final **14/0** (`diagnostik-probe-browser.txt`).
- Statis Keamanan F3 **136/3**, Keamanan F4 **95/3**, V7 F3 **41/3** tetap gagal pada oracle batas berkas/larangan perubahan fase lama. F4 “tepat dua kolom cari” kini gagal karena pencarian tambahan yang memang diminta; tidak dilonggarkan. Ini konsisten dengan kategori kegagalan handoff/baseline yang tersimpan, dengan tambahan lint probe. Tahap 5 **96/0**, statis V3 F4 exit 0. Bukti asli tetap di `regresi-web/`, ringkasan `regresi-web-ringkas.txt`. Tidak menyatakan seluruh statis repo lulus.

## Handoff

Koreksi, probe dan bukti audit di-commit/push pada kedua branch fitur yang sama. Versi tetap **1.3.0**. Perubahan lokal `package.json`/`package-lock.json` dan empat direktori web pekerjaan lain tetap di luar commit. Auditor berhenti sesudah handoff; tidak ada merge, deploy, migrasi produksi, atau rilis aplikasi.
