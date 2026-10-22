# Redesain tampilan website Al Hasan — rencana, 22 Oktober 2026

Status: **rencana disetujui per bagian, belum ada implementasi.** Tidak ada perubahan kode aplikasi, database, atau aset di branch `claude/rencana-redesain-web`; branch ini hanya berisi dokumen. Implementator dan auditor belum ditetapkan (lihat `AGENTS.md`).

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

## Usulan tahapan

Rinciannya ada di bagian "Usulan tahapan pengerjaan" cetak biru. Ringkas:

1. Fondasi kerangka & komponen (K-1 s.d. K-4).
2. Navigasi & Beranda (A, B, N-3).
3. Halaman lama ke kerangka baru (N-4, K-5 s.d. K-8, W, N-2).
4. Kegiatan harian: Perizinan & Pengajian (I, J).
5. Penilaian & rapor (R, M-5) — tahap terbesar karena tindakan massal dan kisi per kelas.
6. Pembinaan (V, M-4).
7. Data & penugasan (D, M-1 s.d. M-3, G-1).
8. Situs publik & PSB (U, P).

Setiap tahap mengikuti alur bergantian di `AGENTS.md`: satu tahap, uji, commit, push, lalu audit oleh agen lain.

## Batasan

- Gambar adalah wireframe; nama santri, guru, angka, dan nominal hanya contoh.
- Tampilan cetak biru tidak diperiksa secara visual di browser selama penyusunan; skrip halaman lolos pemeriksaan sintaks.
- Workstream redesain aplikasi mobile terpisah (`docs/redesain-tampilan/`); rencana ini hanya untuk website.
