# Redesain tampilan website Al Hasan — rencana, 22 Oktober 2026

Status: **rencana disetujui per bagian.** Implementator **Claude**, auditor **Codex** sesuai `AGENTS.md`. **Tahap 1 lulus audit independen Codex 22 Oktober 2026** (`tahap-1/AUDIT-CODEX.md`). **Tahap 2 lulus audit ulang Codex setelah koreksi terarah 23 Oktober 2026** pada `claude/redesain-web-tahap-2` (`tahap-2/AUDIT-ULANG-CODEX.md`), setelah audit pertama dan koreksi Claude `e9b5b8c`. **Tahap 3 diimplementasikan Claude 23 Oktober 2026** pada `claude/redesain-web-tahap-3` dari `429f9ef` dan menunggu audit Codex (`tahap-3/README.md`). Tahap 4 belum dimulai. Belum merge/deploy.

## Isi folder

| Berkas | Isi |
| --- | --- |
| `cetak-biru.html` | Cetak biru lengkap: prinsip, masalah tampilan sekarang, kerangka responsif, komponen bersama, peta menu per peran, gambar wireframe setiap layar (desktop, tablet, HP), pemetaan menu lama → baru, tahapan, fitur lanjutan, temuan, dan keputusan. Buka di browser; tombol di bilah atas mengganti ukuran gambar. |
| `keputusan.md` | Log keputusan Human Developer A–AS beserta penanda keputusan yang menyentuh hak akses, database, atau fitur baru. |

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
| 7 | Penilaian & rapor | R-1 s.d. R-8, M-5 · U, V, W, X, Y | Tahap 6 lulus dan rapor Ganjil 2026/2027 sudah terbit |
| 8 | Situs publik & PSB | U-1 s.d. U-10, P-1 s.d. P-8 · AG, AJ, AM, AN, AO, AP | Tahap 7 lulus, tahap perbaikan keamanan PSB lulus, isi dan biaya MI/RA tersedia |

Alasan urutan:

- **Fondasi dulu**: semua layar memakai kerangka dan komponen yang sama.
- **Navigasi dan Beranda kedua**: setiap peran langsung merasakan menu baru dan daftar tugasnya, dan tahap berikutnya tinggal mengisi halaman di bawah menu yang sudah benar.
- **Halaman lama ketiga**: setelah itu `admin/sidebar.php` dan kerangka ganda bisa pensiun.
- **Perizinan dan pengajian keempat**: dipakai harian oleh paling banyak orang, terutama dari HP.
- **Pembinaan sebelum data pesantren**: pembinaan hanya menambah satu data untuk orang tua, sedangkan data pesantren memuat dua migrasi database dan perubahan hak akses yang lebih besar.
- **Penilaian & rapor ketujuh**: tahap terbesar (kisi per kelas, tindakan massal), dan sengaja menunggu rapor Ganjil 2026/2027 terbit supaya guru tidak berganti alur di tengah masa pengisian nilai.
- **Situs publik & PSB terakhir**: bergantung pada perbaikan keamanan PSB dan isi resmi dari pesantren.

Setiap tahap: branch `claude/redesain-web-tahap-N`, uji, commit, push, berhenti untuk audit Codex. Bukti dan handoff di `docs/redesain-web/tahap-N/`.

## Batasan

- Gambar adalah wireframe; nama santri, guru, angka, dan nominal hanya contoh.
- Tampilan cetak biru tidak diperiksa secara visual di browser selama penyusunan; skrip halaman lolos pemeriksaan sintaks.
- Workstream redesain aplikasi mobile terpisah (`docs/redesain-tampilan/`); rencana ini hanya untuk website.
