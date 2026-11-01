# Perbaikan jadwal pengajian kelas gabungan — Fase 2 (aplikasi)

Status: **diimplementasikan Claude Code, menunggu audit independen Codex (31 Oktober 2026).** Bukan izin merge, deploy, migrasi produksi, atau rilis aplikasi.

## Keputusan, peran, dan baseline

- Lanjutan keputusan produk Human Developer 31 Oktober 2026 (Fase 1): satu jadwal dapat mencakup beberapa kelas yang belajar bersama; satu pertemuan per tanggal, satu absensi guru, paling banyak satu absensi per santri; pertemuan tetap dibuka manual.
- Atas perintah Human Developer sesudah audit Codex Fase 1 lulus. **Implementator Claude Code, auditor Codex.** Hanya Fase 2 (aplikasi); tanpa merge, deploy, atau rilis.
- **Baseline web:** audit Fase 1 `907552204e6f3dbb1684b7988fe8b10ff09f6f94`; `origin/main` `9aa29562fedc951cebf2dbc0bd954387b2709073` (merge PR #108) memuatnya dan `git diff 9075522 origin/main` kosong. Branch web `claude/jadwal-kelas-gabungan-fase-2` dari `9aa2956` hanya menambah uji dan dokumen ini; **kode aplikasi web, API, dan migrasi tidak berubah.**
- **Baseline mobile:** `origin/main` `b8d182529487d82e648c39e78830c64b5e375363` (merge PR #24), isi sama dengan audit penugasan Fase 5 `5364f3da88f955aecabddcf81bfd27181719c500` (`git diff` kosong). Branch mobile `claude/jadwal-kelas-gabungan-fase-2`, commit implementasi `e1fd8a4`. Tanpa worktree.
- Perubahan `package.json`/`package-lock.json` yang belum di-commit di salinan kerja mobile, serta direktori web belum terlacak milik pekerjaan lain, tidak disentuh dan tidak di-commit. Seluruh uji memakai salinan mobile **tanpa `.env*`** dengan `package.json`/lock ter-commit (Expo 57.0.15) dan `node_modules` direktori nyata.
- Dokumentasi Expo: tidak ada API Expo baru; perubahan hanya komponen React Native yang sudah dipakai. Dependensi dan modul native tidak berubah.

## Kontrak API Fase 1 yang dipakai

`GET /schedules`, `/schedules/today`, `/schedules/{id}`: `class` (kelas utama, lama) + `classes` (aditif, utama lebih dahulu). `GET /meetings/{id}`, `PUT …/attendance`: `task.class` (utama kini) + `task.classes` (kelas beku saat dibuka) + `students[].class` (kelas asal). `GET /reports/meetings/{id}` dan `/keamanan/kehadiran/pertemuan/{id}`: `task.classes`, `task.class_names`, `students[].class`. Bila `classes` tidak ada (server lama), aplikasi membaca satu kelas utama.

## Perubahan aplikasi (`alhasanApps`, `e1fd8a4`)

| Berkas | Perubahan |
| --- | --- |
| `src/lib/kelas-jadwal.ts` (baru) | Pembantu tampilan murni: `daftarKelas` (utama dulu, tanpa ganda, cadangan `class`), `labelKelas` (dipisah koma seperti website), `kelompokPeserta` (per kelas asal mengikuti urutan kelas pertemuan; urutan server dipertahankan; kelas di luar daftar dan tanpa kelas tetap tampil), `namaKelasAsal`. |
| `src/api/types.ts`, `src/api/keamanan.ts` | Field aditif opsional `classes`, `students[].class`. |
| `src/components/schedule-card.tsx`, Beranda | Judul dan label aksesibilitas kartu/sorotan memuat seluruh kelas. |
| `src/app/schedule/[id].tsx` | Baris "Kelas gabungan" (setiap kelas · jenjang) dan keterangan "Satu pertemuan untuk N kelas…"; satu kelas tetap baris "Kelas" lama. Penjaga `inFlight` menolak ketukan kedua selama POST berjalan (kunci idempotensi tetap sama untuk kirim ulang). Bila server menjawab 409 (pertemuan sudah dibuka di tempat lain), status jadwal dimuat ulang tanpa menghapus pesan. |
| `src/app/meeting/[id].tsx` | Judul memuat seluruh kelas; untuk gabungan: keterangan "Kelas gabungan · N kelas dalam satu pertemuan", judul kelompok per kelas asal + jumlah santri, label "Kelas <asal>" per santri, nomor urut mengikuti urutan tampil. Satu kelas tetap satu daftar tanpa judul/label (tampilan lama). Muatan absensi tetap dari `meeting.students` (satu entri per santri) — tidak bergantung pada pengelompokan. Penjaga `inFlight` pada kiriman. |
| `src/app/report/[id].tsx`, `src/app/keamanan/kehadiran/[id].tsx` | Rincian baca-saja ikut menampilkan seluruh kelas dan kelas asal santri (penafsiran JK2-3). |
| `tests/kelas-jadwal.test.ts` (baru) | 6 uji unit pembantu. |

Tidak ada pembukaan pertemuan otomatis, aturan bisnis di perangkat, perubahan hak akses, atau perubahan perilaku sesi (401 tetap membersihkan token dan kembali ke masuk lewat klien yang ada).

## Uji yang ditambahkan di repo web

- `tests/jadwal_kelas_gabungan_f2_seed.php` — di atas DB/seed Fase 1, lewat layanan resmi: jadwal gabungan PA+PI (guru `sbx_guru_biasa`, hari ini), jadwal satu kelas C (hari ini), jadwal C besok, penugasan Keamanan untuk `sbx_pengurus_biasa`. Tidak membuka pertemuan.
- `tests/browser/uji-jadwal-kelas-gabungan-f2-app.mjs` — Expo **web** (Chromium) + API/DB uji nyata; memblokir host selain 127.0.0.1; pengendali jaringan Playwright memutus jawaban **sesudah** server memproses.
- `tests/android/jkg_f2_proksi.py` — proksi lokal untuk native: mencatat mutasi (metode, jalur, kunci), menahan 3 detik (`tunda`), menutup sambungan tanpa jawaban (`putus-*`), atau menjawab 503 sesudah diproses (`gagal-*`).
- `tests/android/jkg_f2_android.py` — penggerak emulator Android (uiautomator + adb).
- `perintah-uji.sh` merangkum perintah Expo web/kontrol. Komponen-komponennya dijalankan satu per satu selama implementasi (bukan skrip ini sekali jalan).

## Hasil

Lingkungan: PHP 8.4.14, MariaDB 12.3.2 lokal, Node 26.7.0, DB `test_jkg_f2_app_test` (fixture sintetis, dibangun ulang sebelum setiap putaran). Tidak ada data produksi.

| Uji | Hasil | Bukti |
| --- | --- | --- |
| Unit aplikasi (`node --test tests/*.test.ts`) | **53/0** (47 lama + 6 baru) | `bukti/mobile-unit.txt` |
| TypeScript (`tsc --noEmit`) dan `expo lint` pada salinan bersih | exit 0 · exit 0 | `bukti/mobile-tsc-lint.txt` |
| Expo web alur penuh B-1…B-12 | **57/0** | `bukti/aplikasi-expo-web.txt` |
| Expo web satu kelas saja (B-8) kode kini | **5/0** | `bukti/aplikasi-expo-web-satu-kelas.txt` |
| Kontrol: uji penuh pada mobile baseline `b8d1825` | **1/2**, berhenti di B-1 (sesi gabungan hanya menampilkan kelas utama) | `bukti/kontrol-baseline/kontrol-baseline-penuh.txt` |
| Kontrol: satu kelas saja pada mobile baseline `b8d1825` | **5/0** — assertion sama dengan kode kini | `bukti/kontrol-baseline/kontrol-baseline-satu-kelas.txt` |
| Regresi Expo web Keamanan Fase 4 (layar rincian kehadiran ikut diubah) | **46/0** (sama dengan audit Fase 4) | `bukti/regresi-keamanan-f4-app.txt` |
| **Android emulator** (dev client Debug + Metro) N-1…N-8 | **24/0** | `bukti/android-emulator.txt` |
| **iOS simulator** (Release, lihat catatan build) I-1…I-12 | 11 langkah lulus + 1 catatan, diketuk manual | `bukti/ios-simulator.txt`, `bukti/ios-proksi-kiriman.jsonl` |
| Bundel/server: host produksi di bundel, galat PHP | 0 · 0 | `bukti/bundel-dan-server.txt` |

### Yang dibuktikan (Expo web 57/0)

- **Tampilan:** Beranda, daftar jadwal, label aksesibilitas, detail jadwal, absensi, rincian laporan, dan rincian Keamanan memuat seluruh kelas sama dengan `classes` API; satu kelas tetap satu nama; server lama tanpa `classes` tetap terbaca kelas utama.
- **Satu pertemuan:** membuka layar tidak membuka pertemuan; ketukan ganda + jawaban hilang → 1 POST, server sudah membuat pertemuan; kirim ulang memakai kunci sama → tepat satu pertemuan, 5 peserta unik (3 PA + 2 PI), 2 kelas beku, satu baris idempotensi. Santri berkeanggotaan Selesai tidak ikut.
- **Peserta kedua kelas** berkelompok PA (3) lalu PI (2), setiap santri berlabel kelas asal = API.
- **Absensi:** ketukan ganda + jawaban hilang → 1 PUT (5 santri unik + 1 guru); kirim ulang kunci sama → 1 absensi guru + 5 absensi santri, status sesuai isian, pertemuan Selesai.
- **Pertemuan selesai:** koreksi tanpa alasan tidak mengirim; dengan alasan → 1 PUT kunci baru, baris diperbarui tanpa bertambah.
- **Website dan laporan:** laporan admin web — setiap santri tepat satu baris; CSV — 1 baris guru + 5 santri dengan kelas asal dan status hasil aplikasi; rincian kehadiran web — setiap santri sekali; daftar pertemuan web memuat pertemuan dari aplikasi; REST laporan — 1 pertemuan, 1 guru, 5 santri, 1 ringkasan jadwal.
- **Satu kelas:** detail dan absensi lama tanpa judul/label kelas, 1 guru + 2 santri, 1 kelas beku.
- **Memuat/gagal:** keadaan memuat; 5xx (sesudah dua coba ulang GET klien) → "Data belum dapat ditampilkan" tanpa data, "Coba lagi" memuat ulang.
- **Sesi habis:** token dicabut saat koreksi → kembali ke halaman masuk, absensi tidak berubah.
- **Hak server:** guru lain (`sbx_murobi_a`) — rincian pertemuan dan jadwal ditolak tanpa nama santri/tombol buka; PUT langsung ditolak (403/404) tanpa perubahan.
- Nol permintaan keluar 127.0.0.1; konsol tanpa token; konten layar 375 px tanpa gulir horizontal.

### Native

- **Android emulator 24/0:** tampilan Beranda/detail/absensi gabungan dan satu kelas, ketukan ganda saat permintaan berjalan (proksi menahan 3 detik) → satu POST/PUT, jawaban hilang (503 sesudah diproses) → kirim ulang kunci sama → satu pertemuan / satu guru + lima santri, koreksi pertemuan selesai, REST laporan. **Temuan perilaku platform:** bila sambungan ditutup tanpa jawaban sesudah server memproses, OkHttp mengirim ulang POST sendiri; kedua kiriman berkunci sama sehingga tetap satu pertemuan. Ini memperkuat alasan kunci idempotensi dipertahankan.
- **iOS simulator:** alur yang sama diketuk manual pada iPhone 17 Pro simulator — satu POST untuk ketukan ganda, kirim ulang kunci sama, 5 peserta/2 kelas beku, absensi 1+5, koreksi beralasan, satu kelas tetap tampilan lama. **Catatan build:** build Release baru dari sumber Fase 2 berhasil dikompilasi tetapi crash saat start; binary yang sama dengan bundel lama juga crash, sedangkan binary Release audit Codex 30 Oktober (lock identik) berjalan. Yang diuji adalah binary audit Codex itu + bundel Hermes Fase 2 hasil build baru, ditandatangani ulang ad-hoc. Penyebab crash build baru tidak didiagnosis dan dicatat sebagai batas lingkungan.

## Penafsiran (mohon ditinjau auditor)

- **JK2-1 Pengelompokan hanya untuk gabungan.** Jadwal satu kelas tidak mendapat judul kelompok atau label kelas per santri agar tampil sama dengan sebelumnya; dibuktikan dengan assertion identik pada kode baseline dan kini.
- **JK2-2 Label kelas dipisah koma** (`1 IBTIDA PA, 1 IBTIDA PI`), sama dengan label web Fase 1; utama lebih dahulu.
- **JK2-3 Rincian laporan dan rincian Keamanan ikut diperbarui** walau prompt hanya menyebut jadwal/pertemuan/absensi, karena keduanya memakai data pertemuan yang sama dan sebelumnya hanya menampilkan kelas utama. Hanya baca; regresi Keamanan Fase 4 tetap 46/0.
- **JK2-4 Penjaga ketukan ganda (`inFlight`) ditambahkan** pada pembukaan pertemuan dan kiriman absensi. Sebelumnya tombol baru nonaktif setelah render; dua ketukan dalam satu bingkai dapat mengirim dua permintaan berkunci sama (aman di server, tetapi tidak perlu). Kunci, sidik muatan, dan pembaruan kunci sesudah sukses tidak berubah.
- **JK2-5 409 pada pembukaan memuat ulang status jadwal** agar tombol beralih ke "Isi absensi" ketika pertemuan sudah dibuka di website/perangkat lain. Pesan galat tetap tampil; tidak ada mutasi.
- **JK2-6 Urutan peserta di dalam kelompok** mengikuti urutan server (nama); nomor urut mengikuti urutan tampil.

## Batas

- **Perangkat fisik tidak diuji** (Android maupun iPhone). Seluruh uji native memakai emulator/simulator.
- Android memakai dev client **Debug** + Metro, bukan build rilis; iOS memakai binary Release audit Codex 30 Oktober + bundel Fase 2 (build baru crash, lihat di atas). iOS Debug/dev client, build distribusi, cold start dari tautan, dan Push tidak diuji.
- Di iOS, sesi habis dan akses guru lain tidak diulang (diuji di Expo web). Injeksi teks ke kolom sandi aman simulator harus satu karakter per perintah (batas alat).
- Hanya MariaDB 12.3.2 lokal; MariaDB 10.6.28 produksi, MySQL/cPanel, dan UAT belum diuji. Migrasi 034 belum dijalankan di produksi; aplikasi baru hanya bermakna setelah server Fase 1 terpasang (server lama tetap terbaca satu kelas).
- Statis Fase 1 kini **46/1** (`bukti/regresi-statis-fase1.txt`): satu-satunya kegagalan adalah oracle historis "perubahan hanya pada berkas Fase 1", karena branch ini menambah empat berkas uji Fase 2. Oracle tidak dilonggarkan. Suite web lain tidak dijalankan ulang karena kode web tidak berubah.
- Temuan lama TF4-1 (bilah tab Expo web akun guru meluap pada 375 px) tetap ada dan tidak diubah; uji mengukur konten layar tanpa bilah itu dan mencatat luapannya.
- Gerbang produksi V5/V6/V7 dan rilis penugasan tidak ditutup oleh fase ini.

## Handoff

Commit dan push branch mobile `claude/jadwal-kelas-gabungan-fase-2` (kode + ringkasan) dan branch web dengan nama sama (uji + dokumen ini). Tidak merge, deploy, migrasi produksi, atau rilis aplikasi. Audit independen Codex berikutnya.
