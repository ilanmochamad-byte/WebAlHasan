# Paket "Koreksi dan Modernisasi UI/UX V1–V2"

Keputusan pengguna **30 Agustus 2026**. Branch: `codex/perapihan-v1-v2-ui`,
dari baseline `main` `c65390d`.

**Status: implementasi selesai, MENUNGGU AUDIT CODEX.**
Belum di-merge, belum di-push, belum dirilis, tidak dinyatakan siap produksi.

## Peta dokumen

| Berkas | Isi |
| --- | --- |
| [`rencana.md`](rencana.md) | Keputusan, ruang lingkup, batas pekerjaan, inventaris terdampak, risiko dan rollback |
| [`inventaris-halaman.md`](inventaris-halaman.md) | Halaman yang didesain ulang, halaman kompatibilitas, dampak CSS pada halaman lama |
| [`standar-desain.md`](standar-desain.md) | Token, kerangka halaman, formulir, pesan, aksesibilitas, cetak |
| [`matriks-hak-akses.md`](matriks-hak-akses.md) | Role, kemampuan, beranda per peran, matriks akses halaman |
| [`peta-alur-masuk.md`](peta-alur-masuk.md) | Alur masuk/beranda/ganti password/logout, pemetaan alamat lama, rollback routing |
| [`rekonsiliasi-wali.md`](rekonsiliasi-wali.md) | Strategi identitas wali, kompatibilitas kolom lama, aturan penggabungan |
| [`migrasi-dan-rollback.md`](migrasi-dan-rollback.md) | Migrasi 010 dan prosedur rollback |
| [`status-penerimaan.md`](status-penerimaan.md) | Status tiap kriteria penerimaan beserta buktinya |
| [`hasil-pengujian.md`](hasil-pengujian.md) | Angka pengujian, bukti kunci, dan tangkapan layar sebelum/sesudah |
| [`perubahan-pengujian.md`](perubahan-pengujian.md) | Pengujian lama yang disesuaikan dan alasannya |
| [`risiko-dan-uji-tertunda.md`](risiko-dan-uji-tertunda.md) | Yang belum terbukti, konflik yang butuh keputusan manusia |
| [`panduan-audit-codex.md`](panduan-audit-codex.md) | Cara menyiapkan lingkungan dan apa yang paling perlu diaudit |

## Pekerjaan lanjutan pada folder yang sama

| Berkas | Isi |
| --- | --- |
| [`pesan-kredensial-akun.md`](pesan-kredensial-akun.md) | Fitur "Pesan Kredensial Akun Siap Salin" (6 September 2026): keputusan produk, teks baku, perilaku satu kali tampil, model ancaman password, hasil pengujian, uji tertunda, dan rollback. Branch `feat/pesan-kredensial-akun`, baseline `main` `1382d6a`, menunggu audit Codex |
| [`bukti-pesan-kredensial/`](bukti-pesan-kredensial/) | Log mentah dan tangkapan layar pengujian fitur tersebut |

## Tujuh koreksi

1. Pusat Akun & Hak Akses dengan role eksplisit
2. Data santri–wali: pencocokan dengan konfirmasi admin + rekonsiliasi data lama
3. Data guru: tanpa pilihan tugas lama, penugasan dari data operasional
4. Modul Pengajian terpadu (jadwal + pertemuan dalam satu menu bertab)
5. Pemisahan penyajian laporan kehadiran: Santri / Guru / Gabungan
6. Lapisan desain dan navigasi bersama lintas peran
7. Satu pintu masuk `/portal/`

## Angka pengujian

| Rangkaian | Lulus |
| --- | --- |
| Regresi V1 dan V2 (29 berkas) | 2.174 |
| Paket perapihan (4 berkas) | 248 |
| Uji browser 1440/768/390 px | 56 |
| **Total** | **2.478** |

Satu berkas gagal: `tests/v2_phase4_static.php` — **sudah gagal pada baseline**
`c65390d` karena redesign UI mobile memindahkan layar notifikasi. Bukan akibat
paket ini; butuh keputusan pengguna (lihat `risiko-dan-uji-tertunda.md` B-1).
