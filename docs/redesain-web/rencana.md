# Redesain tampilan website Al Hasan — rencana, 22 Oktober 2026

Status: **rencana disetujui per bagian.** Implementator **Claude**, auditor **Codex** sesuai `AGENTS.md`. **Tahap 1 lulus audit independen Codex 22 Oktober 2026** (`tahap-1/AUDIT-CODEX.md`). **Tahap 2 lulus audit ulang Codex setelah koreksi terarah 23 Oktober 2026** pada `claude/redesain-web-tahap-2` (`tahap-2/AUDIT-ULANG-CODEX.md`), setelah audit pertama dan koreksi Claude `e9b5b8c`. **Tahap 3 diimplementasikan Claude 23 Oktober 2026** pada `claude/redesain-web-tahap-3` dari `429f9ef` dan **lulus audit ulang independen Codex 23 Oktober 2026** atas koreksi Claude `549c7e6`: F-1/F-2 ditutup tanpa koreksi kode aplikasi auditor (`tahap-3/AUDIT-ULANG-CODEX.md`). Audit pertama dan koreksi Claude tetap tercatat sebagai riwayat. **Tahap 4 diimplementasikan Claude 23 Oktober 2026** pada `claude/redesain-web-tahap-4` dari `11ed4ba` (perizinan, pengajian & cetak; termasuk perubahan hak akses keputusan H) dan **lulus audit independen Codex setelah koreksi terarah J-1 pada 23 Oktober 2026** (`tahap-4/AUDIT-CODEX.md`): kisi mingguan kini membaca seluruh halaman, akses H diuji melalui server dan REST. Batas nonblokir dan eksperimen kontrol receipt dicatat dalam laporan. **Tahap 5 diimplementasikan Claude 23 Oktober 2026** pada `claude/redesain-web-tahap-5` dari `origin/main` `492fdd3` (pembinaan V-1 s.d. V-7, M-4; keputusan F dan Z — orang tua kini melihat nama pembimbing pengirim) dan **lulus audit independen Codex setelah dua koreksi terarah auditor 23 Oktober 2026** (pencarian pilihan V-6 dan tautan sumber V-5; bukti server keputusan Z pada `tahap-5/AUDIT-CODEX.md`). **Tahap 6 diimplementasikan Claude 23 Oktober 2026** pada `claude/redesain-web-tahap-6` dari `origin/main` `c8d934b` (data pesantren & penugasan D-1 s.d. D-10, M-1 s.d. M-3, G-1; keputusan AB, AC, AD, AE, AF; migrasi 023 asrama kamar + tanda "bukan ganda" dengan rollback; profil santri binaan berpenjaga server) dan **lulus audit independen Codex setelah dua koreksi terarah 23 Oktober 2026** (dialog dua konflik wali D-3 dan alasan hubungkan alumni D-9; server AB, migrasi/rollback AD–AE teruji). Laporan, oracle historis yang tidak sesuai tahap baru, dan batas uji di `tahap-6/AUDIT-CODEX.md`. **Tahap 7 diimplementasikan Claude 23 Oktober 2026** pada `claude/redesain-web-tahap-7` dari `origin/main` `86721a2` (penilaian & rapor R-1 s.d. R-8, M-5; keputusan U, V, W, X, Y) atas keputusan Human Developer yang sama hari itu: dikerjakan sebelum rapor Ganjil 2026/2027 terbit, dengan penundaan merge/deploy yang kemudian dicabut Human Developer 24 Oktober 2026 (AX). **Lulus audit independen Codex setelah satu koreksi terarah 23 Oktober 2026** pada validasi alasan panel nilai/sikap; aturan AU diuji melalui server dan REST. Laporan, bukti akhir, oracle historis, dan batas pengujian di `tahap-7/AUDIT-CODEX.md`. **Keputusan 24 Oktober 2026 (AX): Human Developer akan merge di GitHub dan deploy melalui cPanel tanpa menunggu rapor terbit**, karena pengisian belum dimulai dan menurut Human Developer belum ada data isian nilai atau rapor. PR #73 sudah di-merge ke `main` (`7f7a002`, 23 Oktober 2026 18.05 WIB) dan memuat kode hasil audit `a04c6e8`; catatan keputusan AX (`eab04e8`) masuk ke `main` lewat PR #74 (`5b9e002`). Deploy cPanel belum dikonfirmasi; batas audit lokal tetap berlaku. Workstream keamanan PSB (prasyarat Tahap 8; implementator Claude, auditor Codex, penetapan 24 Oktober 2026) diimplementasikan di `claude/perbaikan-keamanan-psb` dan **lulus audit independen Codex setelah dua koreksi terarah, 24 Oktober 2026** (`docs/keamanan-psb/AUDIT-CODEX.md`); belum merge/deploy, langkah manual `.htaccess` tetap wajib saat deploy. PR #76 menggabungkan workstream itu ke `main` (`1b319fa`). **Tahap 8 diimplementasikan Claude 24 Oktober 2026** pada `claude/redesain-web-tahap-8` dari `origin/main` `1b319fa` (situs publik & PSB U-1 s.d. U-10, P-1 s.d. P-8, X-1 · AG, AJ, AM, AN, AO, AP; keputusan AY bahan resmi dikosongkan, AZ MI/RA belum dibuka, BA pembiayaan tetap di `biaya_psb.php`). **Lulus audit independen Codex setelah tiga koreksi terarah tampilan (24 Oktober 2026)**; bukti, oracle historis dan batas di `tahap-8/AUDIT-CODEX.md`. Belum merge/deploy.

## Isi folder

| Berkas | Isi |
| --- | --- |
| `cetak-biru.html` | Cetak biru lengkap: prinsip, masalah tampilan sekarang, kerangka responsif, komponen bersama, peta menu per peran, gambar wireframe setiap layar (desktop, tablet, HP), pemetaan menu lama → baru, tahapan, fitur lanjutan, temuan, dan keputusan. Buka di browser; tombol di bilah atas mengganti ukuran gambar. |
| `keputusan.md` | Log keputusan Human Developer A–AX beserta penanda keputusan yang menyentuh hak akses, database, atau fitur baru. |

Salinan cetak biru yang sama juga diterbitkan sebagai artifact privat milik Human Developer. Salinan privat itu memuat rincian temuan keamanan; salinan di repositori publik ini sengaja tidak memuatnya.

## Tujuan

Tampilan semua menu website (situs publik, PSB dan portal pendaftar, sistem internal untuk admin, guru, murobi, pengurus, Bagian Pendidikan, orang tua) mudah dipakai, tidak membingungkan, dan responsif di HP, tablet, dan desktop.

## Prinsip

1. Satu kerangka untuk semua halaman internal (`App\Ui\Layout`); `admin/sidebar.php` dan halaman berkerangka sendiri dihapus bertahap.
2. Menu menurut tugas, bukan versi: tanpa label V2/V3/V4 atau istilah teknis.
3. Beranda menampilkan tugas yang menunggu untuk peran aktif.
4. Satu tindakan utama per layar; tindakan lain di menu ⋯; tambah/ubah di panel samping.
5. Nama, bukan nomor ID.
6. HP setara: tabel menjadi kartu, filter di laci, tombol utama menempel di bawah, sasaran sentuh ≥ 44 px.

Menyembunyikan menu tetap bukan kontrol akses. Setiap perubahan hak akses dari keputusan (H, Z, AB, AK) harus dikerjakan di server dan diuji tersendiri.

## Urutan tahap (ditetapkan 22 Oktober 2026)

Implementator Claude, auditor Codex. Rincian cakupan, syarat mulai, dan kriteria selesai ada di bagian "Urutan tahap pengerjaan" cetak biru.

| Tahap | Cakupan | Layar & keputusan | Syarat mulai |
| --- | --- | --- | --- |
| 1 | Fondasi kerangka & komponen | K-1 s.d. K-4 | — |
| 2 | Navigasi, peran & Beranda | A-1 s.d. A-5, B-1 s.d. B-5, N-3 · A–K, L, P, AA, AK, AQ, AR | Tahap 1 lulus |
| 3 | Halaman lama ke kerangka baru | N-2, N-4, N-5, K-5 s.d. K-8 (tanpa pratinjau berkas), W-1 s.d. W-4 · AH, AI, AL | Tahap 2 lulus |
| 4 | Perizinan, pengajian & cetak | I-1 s.d. I-7, J-1 s.d. J-6, X-1 · H, M, N, O, Q, R, S, T, AS | Tahap 3 lulus |
| 5 | Pembinaan | V-1 s.d. V-7, M-4 · F, Z | Tahap 4 lulus |
| 6 | Data pesantren & penugasan | D-1 s.d. D-10, M-1 s.d. M-3, G-1 · AB, AC, AD, AE, AF | Tahap 5 lulus |
| 7 | Penilaian & rapor | R-1 s.d. R-8, M-5 · U, V, W, X, Y | Tahap 6 lulus; Tahap 7 sudah lulus audit. Keputusan AX, 24 Okt 2026: tidak perlu menunggu rapor terbit untuk merge/deploy; Human Developer akan melaksanakannya melalui GitHub/cPanel |
| 8 | Situs publik & PSB | U-1 s.d. U-10, P-1 s.d. P-8 · AG, AJ, AM, AN, AO, AP (+ AY, AZ, BA) | Tahap 7 lulus, tahap perbaikan keamanan PSB lulus; isi resmi dikosongkan (AY) dan MI/RA belum dibuka (AZ) atas keputusan 24 Okt 2026 |

Alasan urutan:

- **Fondasi dulu**: semua layar memakai kerangka dan komponen yang sama.
- **Navigasi dan Beranda kedua**: setiap peran langsung merasakan menu baru dan daftar tugasnya, dan tahap berikutnya tinggal mengisi halaman di bawah menu yang sudah benar.
- **Halaman lama ketiga**: setelah itu `admin/sidebar.php` dan kerangka ganda bisa pensiun.
- **Perizinan dan pengajian keempat**: dipakai harian oleh paling banyak orang, terutama dari HP.
- **Pembinaan sebelum data pesantren**: pembinaan hanya menambah satu data untuk orang tua, sedangkan data pesantren memuat dua migrasi database dan perubahan hak akses yang lebih besar.
- **Penilaian & rapor ketujuh**: tahap terbesar (kisi per kelas, tindakan massal). Penundaan awal bertujuan mencegah pergantian alur di tengah pengisian; dicabut Human Developer 24 Oktober 2026 karena pengisian belum dimulai, sehingga Tahap 7 dapat dipasang sebelum digunakan.
- **Situs publik & PSB terakhir**: bergantung pada perbaikan keamanan PSB dan isi resmi dari pesantren.

Setiap tahap: branch `claude/redesain-web-tahap-N`, uji, commit, push, berhenti untuk audit Codex. Bukti dan handoff di `docs/redesain-web/tahap-N/`.

## Batasan

- Gambar adalah wireframe; nama santri, guru, angka, dan nominal hanya contoh.
- Tampilan cetak biru tidak diperiksa secara visual di browser selama penyusunan; skrip halaman lolos pemeriksaan sintaks.
- Workstream redesain aplikasi mobile terpisah (`docs/redesain-tampilan/`); rencana ini hanya untuk website.
