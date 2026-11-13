# Rencana tindak lanjut audit redesain tampilan aplikasi gelombang 2 — 13 November 2026

Status: **rancangan, menunggu persetujuan Human Developer atas keputusan KT-1…KT-5.** Dokumen ini belum mengubah kode. Implementasi baru dimulai setelah Human Developer memberi perintah eksplisit.

## 1. Keputusan Human Developer (13 November 2026)

- Seluruh butir yang belum lulus pada audit Codex Fase 1–4 ditindaklanjuti dalam **satu pekerjaan**, bukan per fase.
- **Claude Code satu-satunya implementator. Pekerjaan ini tidak memakai auditor independen.** Keputusan ini **hanya berlaku untuk pekerjaan ini**. Penetapan peran workstream lain di `AGENTS.md` tidak berubah, termasuk Codex sebagai auditor gelombang 2 Fase 1–4.
- Karena tidak ada auditor, aturan ketat 3 `AGENTS.md` ("merge setelah auditor menyatakan lulus") diganti, khusus untuk pekerjaan ini, dengan: Claude Code memverifikasi sendiri memakai kendali mutu §7, lalu **Human Developer memutuskan merge, deploy, dan rilis** berdasarkan laporan itu. Laporan Claude Code bukan pernyataan audit independen. Batas yang belum terbukti harus ditulis sebagai belum terbukti.

## 2. Baseline

| Repo | `origin/main` (diperiksa 13 November 2026) | Isi |
| --- | --- | --- |
| alhasanApps | `9c1bcd9` (PR #33) | Gelombang 2 Fase 1–4 beserta koreksi audit Codex Fase 4 `8d419b0`, versi **1.8.0** |
| WebAlHasan | `dc4e21d` (PR #122) | Bukti, uji, dan laporan audit Fase 1–4 |

Branch implementasi yang diusulkan: `claude/tindak-lanjut-redesain-v2` di kedua repo, dibuat dari `origin/main` terbaru saat perintah diberikan. Tanpa worktree dan tidak menulis di `main`. Perubahan `package.json`/`package-lock.json` mobile serta direktori web milik pekerjaan lain tidak disentuh dan tidak di-commit.

## 3. Batas pekerjaan

- **Hanya tampilan dan navigasi aplikasi.** Endpoint, parameter, payload, hak akses, kerahasiaan, idempotensi, versi optimistik, `expo-screen-capture`, kode server, dan migrasi tidak berubah. Bila suatu butir ternyata membutuhkan perubahan di luar batas ini, butir itu dihentikan dan diajukan kepada Human Developer.
- Tanpa dependensi atau modul native baru.
- Versi aplikasi naik minor **sekali** untuk seluruh pekerjaan: **1.8.0 → 1.9.0** (`npm run versi:naik minor`), kecuali `main` sudah memuat versi lebih tinggi saat mulai.
- Kode web hanya berubah pada uji, fixture uji, alur Maestro, dan dokumen.
- Tidak merge, deploy, migrasi produksi, atau rilis aplikasi.

## 4. Inventaris butir terbuka

Sumber: `fase-1…4/AUDIT-CODEX.md` dan penafsiran/temuan pada `fase-1…4/README.md`. Kolom **Tindakan**: *Perbaiki* = dikerjakan; *Verifikasi* = sudah dikoreksi pada audit, diuji ulang; *Pertahankan* = sudah diputuskan atau tidak dapat tanpa perubahan server (lihat KT-3); *Keputusan* = menunggu KT.

### A. Selisih terhadap rencana desain

| ID | Sumber | Butir | Tindakan |
| --- | --- | --- | --- |
| TL-01 | F1 P-1 | Masuk belum menampilkan sapaan "Assalamu'alaikum" (§4.1) | Perbaiki. Suite lama yang memakai "Assalamu" sebagai tanda Beranda diganti penanda khusus Beranda (KT-1) |
| TL-02 | F1 P-4 | Edit profil: ubah/hapus foto belum lewat lembar bawah | Perbaiki: lencana kamera membuka lembar bawah (Pilih foto / Hapus foto / Batal); alur unggah dan konfirmasi hapus tetap |
| TL-03 | F1 P-3, P-9 | Profil: "Data diri" tampil langsung, kelompok "Akun & pemberitahuan" belum dipecah | Perbaiki: baris "Data diri ›" membuka rincian; kelompok Akun dan Pemberitahuan terpisah; Tampilan dan Tentang tetap |
| TL-04 | F2 T2-3 | Profil iOS masih berjarak atas ganda | Perbaiki dengan pola P2-16 yang sudah dipakai Jadwal/Perizinan |
| TL-05 | F1 P-5 | "Jadwal hari ini" di Beranda masih `ScheduleCard` | Perbaiki: `EntityRow` jam-kiri; `ScheduleCard` di layar Jadwal tidak berubah |
| TL-06 | F2 P2-2 | Tombol absensi berlabel "Konfirmasi dan kirim" | Perbaiki: "Kirim absensi"; panel sukses ringkas di layar yang sama dipertahankan (sesuai "P-Sukses ringkas") |
| TL-07 | F2 P2-4 | Buat izin V2/V7 belum punya layar sukses "Pengajuan terkirim" + "Lihat pengajuan" | Perbaiki: layar sukses dengan tombol "Lihat pengajuan"; V7 menambah "Menunggu tinjauan pembimbing". Urutan permintaan POST lalu GET rincian tidak berubah |
| TL-08 | F2 P2-10, P2-11 | Sebagian tindakan rincian izin (Tetapkan pembimbing, Batalkan, Tetapkan murobi) masih di badan layar | Perbaiki: tindakan utama di bilah bawah (paling banyak dua), sisanya di ⋯. Pemuatan kandidat pembimbing tetap dipicu dari lembar penetapan |
| TL-09 | F2 §4.11 | Perangkat & push masih dua tombol, belum sakelar | Perbaiki: sakelar "Push di perangkat ini" yang memanggil fungsi daftar/hapus perangkat yang sama |
| TL-10 | F2 K-8, F4 | FAB Expo web belum di atas bilah tab | Verifikasi: sudah dikoreksi audit Fase 4 (`8d419b0`). Tambahan: di web, alih ke tombol atas memakai lebar terukur, sehingga pembesaran CSS 200% tidak membuat FAB 207 px |
| TL-11 | F3 P3-2 | "Bayar dari tabungan" di bilah aksi, rencana di ⋯ | Perbaiki (KT-1) |
| TL-12 | F3 P3-13 | Menu wali tanpa "Mutasi tabungan" | Perbaiki: tiap kartu anak diberi pintasan Riwayat pembayaran, Mutasi tabungan, dan Laporan anak yang membuka rincian anak pada tab terkait. Tidak memakai daftar lintas santri yang ditolak server |
| TL-13 | F3 audit | Pratinjau terbit satu santri berbentuk kartu rincian tarif | Perbaiki: StatGrid Akan terbit / Sudah ada / Dilewati + total, rincian tarif di bawahnya. Penjaga `pratinjauSatuSantriSah` tetap |
| TL-14 | F4 P4-3 | Rincian Pembinaan tanpa tab Ringkasan / Sesi / Riwayat | Perbaiki (KT-1). Tindakan "Tandai mengetahui" tetap terlihat pada tab Ringkasan |
| TL-15 | F4 P4-5 | Alasan koreksi rapor masih kolom di halaman | Perbaiki: lembar bawah beralasan (KT-1) |
| TL-16 | F4 P4-6 | Riwayat versi/akses kunjungan sebagai baris langsung | Perbaiki: pindah ke ⋯, ditampilkan sebagai garis waktu (KT-1) |
| TL-17 | F4 P4-4 | Kerahasiaan pembinaan memakai dropdown, bukan kartu radio | Pertahankan: sudah diputuskan sesuai PRD V3 §5.5b dan diterima audit Fase 4 |

### B. Aksesibilitas dan kontras

| ID | Sumber | Butir | Tindakan |
| --- | --- | --- | --- |
| TL-20 | F1 T-1 | `statusIzin`/`statusTerlambat` kurang dari 4,5:1 bila dipakai sebagai teks biasa | Perbaiki tanpa mengubah token K-1: pastikan seluruh label status memakai warna teks, dan tambahkan uji statis yang menolak token status sebagai warna teks biasa |
| TL-21 | F1 T-2 | Bagian lime logo "Alhasan apps" berkontras rendah di latar krem | Keputusan KT-2 |
| TL-22 | F2 T2-2, F3 T3-3 | Pada AX-XXXL/2,0× kepala dua baris dan bilah bawah menyisakan area isi sempit | Perbaiki: pola "tindakan masuk ke akhir isi" pada skala > 1,35× (sudah dipakai Keuangan) diberlakukan di semua layar ber-`ActionBar` |
| TL-23 | F4 T4-2 | iOS menggabungkan baris jadi satu elemen aksesibilitas, pil tidak dibacakan terpisah | Perbaiki: label aksesibilitas setiap `EntityRow` memuat teks status, diuji di seluruh daftar |
| TL-24 | F1 audit | Inisial avatar memakai pengali teks maksimum 1 | Pertahankan: inisial dekoratif, nama dan isi tetap mengikuti skala sistem |

### C. Navigasi dan bukti native

| ID | Sumber | Butir | Tindakan |
| --- | --- | --- | --- |
| TL-30 | F4 audit | Android gelap + 2,0×: tautan kedua berhenti di Beranda, sehingga rincian rapor dan laporan klinik pada skala itu belum terbukti (T7 14/2, Kesehatan 5/2) | **Dikerjakan pertama.** Diselidiki apakah penyebabnya aplikasi (misalnya activity dibuat ulang saat ukuran teks berubah lalu tautan hilang) atau alat uji. Bila aplikasi, diperbaiki di lapisan navigasi tanpa mengubah hak; bila alat uji, alat diperbaiki dan dicatat |
| TL-31 | F4 audit | iOS Kesehatan AX-XXXL: penanda privat baru ditemukan pada ulang terarah | Perbaiki: penanda privat diletakkan di kepala bagian catatan klinis agar langsung terlihat; uji ulang dengan ambang Maestro bawaan |
| TL-32 | F3, F4 audit | Ketukan notifikasi sistem (aplikasi berjalan dan mati) belum terbukti | iOS simulator: `xcrun simctl push` dengan payload berformat Expo, lalu mengetuk dari pusat notifikasi, untuk izin, izin wali, keuangan, kesehatan, rapor. Android dan Push nyata: KT-4 |
| TL-33 | F3, F4 audit | Kartu pengalih aplikasi iOS untuk modul sensitif belum terbukti | Periksa cache cuplikan sistem pada kontainer simulator (metode audit ulang V6 Fase 4) untuk Keuangan, Kesehatan, Pembinaan, Izin wali, Keamanan, dengan kontrol layar tidak sensitif |
| TL-34 | F4 T4-1 | APK Release Android belum diuji (cleartext ke API lokal ditolak) | Uji APK Release 1.9.0 pada emulator Google APIs dengan sertifikat CA uji dipasang sebagai CA sistem dan API lokal HTTPS. Bila tidak berhasil, dicatat terbuka |
| TL-35 | F1–F4 audit | Uji native akhir memakai binary lama + bundel baru (hibrida) | Bangun iOS Release dan Android Debug/Release **baru dari sumber akhir**, bukan hibrida |
| TL-36 | F3 T3-1 | Duplikasi layar saat tautan cold start iOS | Verifikasi: sudah dikoreksi audit Fase 3 (`f2b0358`); uji ulang di semua modul, bukan hanya kuitansi |

### D. Regresi dan data uji

| ID | Sumber | Butir | Tindakan |
| --- | --- | --- | --- |
| TL-40 | F4 T4-3, audit | Suite V4 Fase 5 aplikasi tidak dapat dijalankan (fixture hilang) | Pulihkan atau buat ulang fixture sintetis, jalankan pada baseline dan akhir |
| TL-41 | F4 T4-4, audit | Suite V3 mobile berhenti pada katalog < 26 pilihan dan `#case-privacy` | Lengkapi fixture; bedakan kegagalan historis dari regresi dengan kontrol baseline |
| TL-42 | F1, F4 audit | Suite gelombang 1 (`uji-redesain-fase1/2/3`) berhenti pada penanda Masuk/Beranda | Sesuaikan penanda (KT-1), jalankan sampai selesai |
| TL-43 | F4 audit | Lanjutan matriks BSA, V5 Fase 5, V7 Fase 4, regresi izin Fase 2 belum dapat dibandingkan (fixture jadwal bermutasi, path alat uji, locator) | Setiap suite memakai dump fixture sendiri yang dipulihkan sebelum jalan; path alat uji dibuat relatif |
| TL-44 | F1–F4 | Paritas jaringan baru per fase | Satu perbandingan **seluruh gelombang**: baseline sebelum gelombang 2 (`77f6240`, 1.4.0) dan baseline pekerjaan ini (`9c1bcd9`) terhadap sumber akhir, untuk seluruh alur perekam Fase 1–4 |

### E. Tidak dikerjakan dalam pekerjaan ini (KT-3)

Butir berikut membutuhkan data yang tidak dikirim server, sehingga hanya bisa dikerjakan dengan perubahan API:

- StatGrid menu petugas/admin Keuangan (F3 P3-4) dan Kesehatan (F4 P4-7): respons akses tidak memuat angka.
- Kamar/kelas pada baris Perizinan V2 (F2 audit): DTO V2 hanya nama/NIS.
- Sisa tagihan di atas 60 baris (F3 P3-5): tetap "—" dengan keterangan.

Penafsiran lain yang sudah diterima audit dan dipertahankan: P2-7 pil "Bulan ini", P2-8 lima status, P2-9 huruf status, P2-12 pil belum dibaca, P3-1 tab Ringkasan memuat semua bagian, P3-3 panel sukses di atas formulir, P3-7 format `Rp1.250.000`, P3-10 verifikasi setoran di kartu, P4-1/P4-2 judul dan nama tombol lama, P4-8 baris angka lama laporan klinik, P4-9 pil membungkus.

## 5. Keputusan yang diperlukan sebelum mulai

| ID | Pertanyaan | Rekomendasi |
| --- | --- | --- |
| **KT-1** | Sebagian besar selisih desain (TL-01, 02, 03, 06, 07, 08, 11, 14, 15, 16, 42) dibiarkan karena suite uji lama mencari tombol atau teks pada letak lama. Bolehkah suite lama disesuaikan? | **Setuju dengan syarat**: hanya pencari elemen dan langkah navigasi (misalnya membuka ⋯ atau lembar bawah lebih dulu) yang diubah; pernyataan bisnis (permintaan, hasil di DB, hak, nominal, teks data) tidak diubah atau dilonggarkan; setiap perubahan dicatat per suite; bila memungkinkan suite yang disesuaikan dijalankan juga pada baseline dan harus lulus di sana. Tanpa KT-1, butir tersebut tidak dapat diperbaiki |
| **KT-2** | Logo lime (TL-21): warna logo tidak boleh diubah tanpa persetujuan | **Pertahankan** sebagai hiasan; logo sudah berlabel aksesibilitas. Alternatif: Human Developer menyediakan varian logo untuk latar terang |
| **KT-3** | Butir §4E yang memerlukan data server | **Pertahankan**; bila dibutuhkan, dibuka sebagai pekerjaan API terpisah |
| **KT-4** | Bukti perangkat fisik, Push nyata (APNs/FCM), dan build distribusi EAS tidak dapat dikerjakan Claude Code sendiri | Human Developer menyediakan satu HP Android dan satu iPhone serta build preview EAS dengan kredensial Push. Claude Code menyiapkan daftar periksa dan mencatat hasilnya. Bila tidak tersedia, butir ini tetap tercatat terbuka |
| **KT-5** | Pekerjaan tanpa auditor independen (§1) | Setuju sesuai §1 dan kendali mutu §7 |

## 6. Urutan kerja

Satu branch per repo, satu commit per bagian agar mudah ditinjau dan dibatalkan.

0. **Persiapan.** Verifikasi `origin/main` kedua repo, buat branch, catat keputusan di `AGENTS.md` kedua repo, naikkan versi ke 1.9.0. Pulihkan fixture (TL-40…TL-43) lebih dulu, lalu jalankan **seluruh** suite pada baseline untuk mendapat angka pembanding yang lengkap.
1. **Navigasi native berisiko** (TL-30, TL-36). Dikerjakan pertama karena satu-satunya butir yang mungkin cacat fungsi.
2. **Akun dan Beranda** (TL-01…TL-05).
3. **Pengajian, perizinan, notifikasi** (TL-06…TL-10).
4. **Keuangan** (TL-11…TL-13).
5. **Pembinaan, rapor, kesehatan** (TL-14…TL-16, TL-31).
6. **Aksesibilitas lintas layar** (TL-20, TL-22, TL-23).
7. **Verifikasi menyeluruh** (§7) termasuk TL-32…TL-35 dan TL-44.
8. **Laporan dan serah terima** di `docs/redesain-tampilan-v2/tindak-lanjut/README.md` (web) dan ringkasan di repo aplikasi; commit, push, lalu berhenti untuk keputusan Human Developer.

## 7. Kendali mutu pengganti audit independen

Karena tidak ada auditor, setiap klaim harus dapat diperiksa ulang dari bukti yang di-commit:

- **Kontrol baseline untuk setiap probe baru**: probe harus gagal pada baseline dan lulus pada sumber akhir. Probe yang lulus di keduanya tidak dihitung sebagai bukti perbaikan.
- **Uji dari arsip Git bersih** (tanpa `.env*`, dependensi dari lockfile ter-commit, Expo offline, host di luar `127.0.0.1` diblokir), bukan dari checkout kerja.
- **Paritas jaringan** seluruh gelombang (TL-44): nol langkah berbeda, atau setiap perbedaan dijelaskan dan disetujui.
- **Daftar perubahan oracle** (KT-1) dalam tabel: suite, baris, isi lama, isi baru, alasan, hasil pada baseline.
- **Pemeriksaan diff otomatis**: tidak ada perubahan pada klien API, penjaga mutasi/idempotensi, `expo-screen-capture`, manifest dan lockfile ter-commit, kode server, atau migrasi.
- **Build native baru dari sumber akhir** (TL-35), dengan jenis build, perangkat, dan versi ditulis apa adanya.
- **Pemindaian privasi** log native dan web untuk penanda sintetis serta bearer mentah.
- **Peninjauan ulang dengan konteks baru**: setelah implementasi selesai, Claude Code menjalankan `/code-review` atas diff kedua repo dan mencatat temuannya. Ini bukan audit independen, tetapi mengurangi bias penulis.
- Hasil yang gagal, tidak selesai, atau tidak dapat dijalankan dicatat dengan log, bukan dihilangkan.

## 8. Kriteria selesai

- Semua butir *Perbaiki* selesai dengan probe yang gagal di baseline dan lulus di akhir; butir *Verifikasi* lulus uji ulang.
- `npx tsc --noEmit` dan `npm run lint` exit 0; unit test lulus; seluruh suite Expo web gelombang 2 dan regresi modul lulus atau kegagalannya terbukti historis dengan kontrol baseline.
- K-10 untuk seluruh rute wajib (saat ini 68 pola) lulus untuk semua peran yang relevan, terang dan gelap, 375/768/1280 px.
- Paritas jaringan seluruh gelombang tanpa perbedaan yang tidak dijelaskan.
- Android emulator (Debug baru, dan Release baru bila TL-34 berhasil) serta iOS simulator Release baru: semua peran, gelap, 2,0×/AX-XXXL, tautan saat aplikasi berjalan dan mati, tombol kembali sistem.
- Versi 1.9.0, satu kenaikan minor.

## 9. Batas yang tetap terbuka setelah pekerjaan ini

Kecuali KT-4 dilaksanakan: perangkat fisik, Push nyata dan ketukannya, build distribusi EAS, iOS Debug. Selain itu MySQL/MariaDB produksi, cPanel, UAT, dan gerbang produksi V5/V6/V7/Keamanan tetap mengikuti keputusan masing-masing.

## 10. Prompt mulai (setelah KT disetujui)

> *"Implementasikan tindak lanjut audit redesain tampilan aplikasi gelombang 2 sesuai `docs/redesain-tampilan-v2/tindak-lanjut/rencana.md` pada branch `claude/tindak-lanjut-redesain-v2` di kedua repo. Keputusan KT-1…KT-5: [isi]. Claude Code satu-satunya implementator tanpa auditor independen, khusus pekerjaan ini. Ikuti urutan §6 dan kendali mutu §7, catat bukti dan batas secara jujur, commit dan push kedua branch, lalu berhenti. Jangan merge, deploy, atau rilis aplikasi."*
