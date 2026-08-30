# Status kriteria penerimaan

Paket "Koreksi dan Modernisasi UI/UX V1–V2" — keputusan pengguna 30 Agustus 2026.

> **Catatan audit Codex, 30 Agustus 2026:** tabel di bawah dipertahankan sebagai
> klaim implementer, bukan putusan audit. Penilaian satu per satu atas seluruh
> 77 klaim tersedia di [penilaian-penerimaan-codex.md](penilaian-penerimaan-codex.md),
> dengan bukti awal di [hasil-audit-codex.md](hasil-audit-codex.md) dan
> hasil keputusan lanjutan di [hasil-audit-lanjutan-codex.md](hasil-audit-lanjutan-codex.md),
> serta penyelesaian kamar/pagination di [audit-kamar-pagination.md](audit-kamar-pagination.md)
> dan verifikasi 14 klaim di [audit-14-klaim.md](audit-14-klaim.md).
> Beberapa klaim tidak terverifikasi atau masih menunggu keputusan/verifikasi.
> **Paket belum dinyatakan lulus atau siap produksi.**

Legenda:

- **TERPENUHI** — ada bukti otomatis yang dapat diulang auditor.
- **MENUNGGU VERIFIKASI** — belum dapat dibuktikan pada lingkungan ini; langkah
  lanjutan dan hasil yang diharapkan dicatat di `risiko-dan-uji-tertunda.md`.

> Tidak ada butir yang dinyatakan TERPENUHI berdasarkan pembacaan kode saja bila
> kriterianya menuntut perilaku. Seluruh butir TERPENUHI merujuk berkas uji dan
> penanda pemeriksaan yang dapat dijalankan ulang.
>
> **Paket ini belum lolos audit Codex dan belum dinyatakan siap produksi.**

---

## Koreksi 1 — Pengelolaan akun terpusat

| Kriteria | Status | Bukti |
| --- | --- | --- |
| Akun orang tua tidak lagi menampilkan Guru sebagai pilihan semu | TERPENUHI | `perapihan_static` "Tidak ada lagi dropdown role tunggal…"; `perapihan_integration` KA-2 (role Orang Tua/Guru ditolak tanpa relasi) |
| Penambahan satu role mempertahankan role lainnya | TERPENUHI | `perapihan_integration` KA-5, KA-6 |
| Penetapan role tanpa hubungan data yang valid ditolak server | TERPENUHI | `perapihan_integration` KA-2, KA-3 |
| Admin terakhir tetap terlindungi, termasuk pada permintaan bersamaan | TERPENUHI | `perapihan_integration` KA-7, KA-8; **`perapihan_akun_concurrency` KC-1a/b/c dan KC-2b/c dengan tiga proses PHP nyata** |
| Seluruh perubahan hak akses tercatat | TERPENUHI | `perapihan_integration` KA-10 |
| Perubahan hak berlaku pada pemeriksaan server; sesi lama tidak mempertahankan hak yang dicabut | TERPENUHI | `perapihan_integration` KA-11a, KA-11b |
| Halaman akun lama punya jalur transisi aman tanpa melewati validasi mutasi/CSRF | TERPENUHI | `perapihan_static` "Halaman akun lama meneruskan POST ke pusat akun"; `perapihan_web_smoke` PM-10 |

## Koreksi 2 — Data santri dan wali

| Kriteria | Status | Bukti |
| --- | --- | --- |
| Dua saudara dapat memakai satu identitas wali | TERPENUHI | `perapihan_integration` KW-1, KW-2 |
| Dua orang bernama sama tetap dapat disimpan sebagai orang berbeda | TERPENUHI | `perapihan_integration` KW-4 (nama **dan** nomor HP sama, tetap dua identitas) |
| Koreksi data tidak memperluas akses akun orang tua tanpa hubungan yang dikonfirmasi | TERPENUHI | `perapihan_integration` KW-10 (penggabungan diblokir bila menyangkut akun login) |
| Data lama, impor/ekspor terkait, dan riwayat tetap dapat digunakan | TERPENUHI | `perapihan_integration` KW-17, KW-18; `phase2_integration` dan `phase5_integration` tetap lulus tanpa perubahan |
| Penyimpanan santri, wali baru, dan relasi bersifat atomik | TERPENUHI | `perapihan_integration` KW-7 (penolakan me-rollback seluruh transaksi) |
| Pengiriman ulang tidak membuat data ganda | TERPENUHI | `perapihan_static` (token sekali pakai pada formulir santri dan wali) |
| Pembuatan/pemilihan wali tidak membuat akun login | TERPENUHI | `perapihan_integration` KW-5; `perapihan_static` (jalur master data tidak pernah `INSERT INTO users`) |
| Identitas wali bersama menampilkan santri terdampak sebelum konfirmasi | TERPENUHI | `perapihan_integration` KW-14, KW-15 |
| Laporan kandidat duplikasi, konflik, dan relasi belum lengkap | TERPENUHI | `perapihan_integration` KW-16 |
| Tidak ada penggabungan massal | TERPENUHI | `perapihan_static` (halaman rekonsiliasi menegaskannya; tidak ada aksi massal) |
| Penggabungan yang bertentangan diblokir dan meminta penyelesaian eksplisit | TERPENUHI | `perapihan_integration` KW-9, KW-10 |
| ID lama dan jejak perubahan dipertahankan | TERPENUHI | `perapihan_integration` KW-11, KW-13; `perapihan_static` (tidak ada `DELETE FROM wali`/`santri_wali`) |
| Kolom ayah/ibu lama tidak dihapus; tidak ada dua sumber pengeditan | TERPENUHI | `rekonsiliasi-wali.md` §4 (inventaris pembaca); `perapihan_static` (`array_key_exists('nama_ayah', …)`) |
| Nilai lama yang bertentangan tidak ditimpa tanpa audit dan keputusan admin | TERPENUHI | `perapihan_integration` KW-6, KW-8 |

## Koreksi 3 — Data guru dan penugasan

| Kriteria | Status | Bukti |
| --- | --- | --- |
| Tidak ada pilihan tugas lama pada formulir guru | TERPENUHI | `perapihan_static` (tidak ada `Guru/Pembimbing/Keduanya`, tidak ada dropdown pengganti) |
| Label menjadi "Data Guru" | TERPENUHI | `perapihan_static` |
| Hak murobi berasal dari akun guru dan penugasan aktif yang valid | TERPENUHI | `matriks-hak-akses.md` §1; `v2_phase2_navigasi_murobi` NAV-0a/0b tetap lulus |
| Akun, jadwal, absensi, dan riwayat lama tidak rusak | TERPENUHI | `perapihan_integration` KG-1 (nilai `status` lama tetap `Keduanya` setelah simpan); seluruh regresi V1 tetap lulus |
| Guru tanpa jadwal tetap dapat ditugaskan sebagai murobi | TERPENUHI | `perapihan_integration` KG-2; `perapihan_static` (keterangan halaman murobi) |
| Nilai tugas lama tidak diubah otomatis menjadi Guru | TERPENUHI | `perapihan_integration` KG-1 |
| Guru berlabel Pembimbing tidak dipindahkan menjadi pengurus | TERPENUHI | tidak ada kode yang memindahkannya; `perapihan_static` memeriksa `guruUpdate` tidak menyentuh kolom status |

## Koreksi 4 — Menu Pengajian terpadu

| Kriteria | Status | Bukti |
| --- | --- | --- |
| Perpindahan jadwal–pertemuan mudah dan tidak kehilangan konteks | TERPENUHI | `uji-perapihan.mjs` B-4a/B-4b pada tiga lebar layar; `perapihan_static` (konteks terbawa) |
| Satu jadwal tetap mempunyai banyak pertemuan | TERPENUHI | `phase3_integration` tetap lulus tanpa perubahan (keunikan jadwal–tanggal tidak disentuh) |
| Tidak ada pertemuan atau absensi ganda | TERPENUHI | `phase3_integration`, `phase4_integration`, `phase5_integration` tetap lulus |
| Guru hanya mengakses jadwal dan pertemuannya sendiri | TERPENUHI | `perapihan_integration` KP-2; `perapihan_static` (filter `guru_id` dipaksa di server) |
| Guru tidak memperoleh hak pengelolaan jadwal admin | TERPENUHI | `perapihan_static` (penolakan POST eksplisit); diverifikasi manual: POST jadwal oleh guru → 403 tanpa baris tercipta |
| Alamat halaman lama tetap dapat diakses | TERPENUHI | `perapihan_web_smoke` PM-10; `v2_phase2_navigasi_murobi` NAV-7a |
| Penyimpanan jadwal dan pertemuan tetap terpisah | TERPENUHI | tidak ada perubahan tabel; `perapihan_static` (potongan jadwal tidak menyentuh tabel pertemuan) |
| Snapshot peserta dan audit dipertahankan | TERPENUHI | `perapihan_integration` KP-1 |
| Riwayat peserta tidak berubah saat santri pindah kelas | TERPENUHI | mekanisme snapshot Fase 3 tidak diubah; `phase3_integration` tetap lulus |

## Koreksi 5 — Pemisahan laporan kehadiran

Fixture satu guru dan 30 santri:

| Kriteria | Status | Bukti |
| --- | --- | --- |
| Mode Santri: 30 catatan | TERPENUHI | `perapihan_integration` KL-1/KL-2/KL-3 (`santri` = 30) |
| Mode Guru: satu catatan | TERPENUHI | idem (`guru` = 1) |
| Mode Gabungan: 31 catatan | TERPENUHI | idem (`gabungan` = 31) |
| Ringkasan, detail, dan ekspor menghasilkan jumlah sesuai filter yang sama | TERPENUHI | `perapihan_integration` KL-1..KL-4; diverifikasi lewat HTTP: layar 30/1/31 = CSV 30/1/31 |
| Mode gabungan menampilkan penanda jenis dan jumlah masing-masing | TERPENUHI | `perapihan_integration` KL-5, KL-6 |
| Guru tetap tampil sebagai pengampu pada laporan santri, tidak dihitung sebagai santri | TERPENUHI | `perapihan_integration` KL-7 |
| Filter yang sama berlaku pada ringkasan, detail, CSV, dan cetak/PDF | TERPENUHI | `perapihan_static` (satu `attendanceRowsSql`); `perapihan_web_smoke` PM-11c |
| Absensi guru tidak dihapus | TERPENUHI | `perapihan_integration` KL-11 |
| Default dan kontrak API lama tidak berubah diam-diam | TERPENUHI | `perapihan_integration` KL-8, KL-9; seluruh berkas kontrak API tetap lulus tanpa perubahan assertion |
| Batas ekspor, formula injection, isolasi akses, pengaman cetak tetap berlaku | TERPENUHI | `v2_phase5_integration`, `v2_phase5_api_contract`, `v2_phase5_cetak_pdf` tetap lulus |

## Koreksi 6 — Desain ulang UI/UX dan navigasi

| Kriteria | Status | Bukti |
| --- | --- | --- |
| Standar bersama untuk warna, spasi, judul, tombol, formulir, tabel, badge, dialog, pesan | TERPENUHI | `perapihan_static` bagian 6; `standar-desain.md` |
| Bootstrap dipertahankan, komponen bersama, tanpa aset eksternal baru | TERPENUHI | `perapihan_static` (tidak ada host baru pada CSS) |
| Sidebar/topbar konsisten; menu mengikuti role dan kemampuan aktual | TERPENUHI | `App\Ui\Navigation`; `uji-perapihan.mjs` B-2, B-3 |
| Komponen navigasi terpisah dari guard khusus admin | TERPENUHI | `perapihan_static` (Navigation bebas guard; `sidebar.php` tidak memuat `_guard.php`) |
| Menu ponsel dapat dibuka/ditutup, tombol mudah disentuh | TERPENUHI | `uji-perapihan.mjs` B-3a/b/c dan B-1c (≥ 44 px) pada 768 dan 390 px |
| Menu aktif, breadcrumb, judul, tindakan utama jelas | TERPENUHI | `perapihan_static`; `uji-perapihan.mjs` B-4a |
| Formulir dikelompokkan, label jelas, validasi dekat kolom | TERPENUHI | `perapihan_static`; tinjau `_pengajian_jadwal.php`, `admin_master_santri.php` |
| Isian dipertahankan saat validasi gagal | TERPENUHI | `perapihan_static` (`ah_old_keep`) |
| Keadaan kosong / berhasil / gagal / akses ditolak yang mudah dipahami | TERPENUHI | `perapihan_static`; `uji-perapihan.mjs` B-10b |
| Pencarian/pagination untuk daftar besar | TERPENUHI | `ah_pagination` + filter pada seluruh halaman daftar |
| Tabel nyaman pada layar kecil tanpa melebarkan halaman | TERPENUHI | `uji-perapihan.mjs` B-6a/B-6b dan seluruh `*-tidak melebar` pada 390 px |
| Tindakan berisiko menjelaskan dampak sebelum konfirmasi | TERPENUHI | `standar-desain.md` §6; tinjau teks `onsubmit` dan dialog admin |
| Makna tidak bergantung warna/ikon saja | TERPENUHI | `perapihan_static` (lencana selalu memuat teks) |
| Navigasi keyboard, fokus terlihat, label pembaca layar, kontras memadai | TERPENUHI (sebagian) | `uji-perapihan.mjs` B-7a/B-7b; `perapihan_static` (`:focus-visible`). **Audit kontras otomatis belum dijalankan** — lihat `risiko-dan-uji-tertunda.md` |
| Preferensi pengurangan animasi dihormati | TERPENUHI | `perapihan_static` (`prefers-reduced-motion`) |
| Halaman cetak/PDF tetap tanpa sidebar, margin dan pagination tidak berubah | TERPENUHI | `uji-perapihan.mjs` B-9a; `perapihan_web_smoke` PM-11b; `v2_phase5_cetak_pdf` 175 pemeriksaan tetap lulus |
| Inventaris halaman agar tidak ada yang tertinggal | TERPENUHI | `inventaris-halaman.md` |
| Dampak CSS bersama pada halaman lama diperiksa | TERPENUHI | `inventaris-halaman.md` §D |

## Koreksi 7 — Satu pintu masuk `/portal/`

| Kriteria | Status | Bukti |
| --- | --- | --- |
| `/portal/` tanpa sesi menampilkan login | TERPENUHI | `perapihan_web_smoke` PM-1a/b/c; `uji-perapihan.mjs` B-1a |
| Login berhasil untuk admin, guru non-murobi, murobi, pengurus, orang tua | TERPENUHI | `perapihan_web_smoke` PM-2 (lima peran) |
| Guru non-murobi masuk beranda umum tetapi ditolak dari fungsi keputusan perizinan | TERPENUHI | `perapihan_web_smoke` PM-3a/PM-3b; `v2_phase2_navigasi_murobi` NAV-16a/16b |
| Pengguna yang sudah login tidak diminta login ulang | TERPENUHI | `perapihan_web_smoke` PM-4a/PM-4b, PM-6b |
| Akun multi-peran dapat memakai seluruh menu yang diizinkan | TERPENUHI | `perapihan_web_smoke` PM-5 |
| Alamat login lama tetap berfungsi | TERPENUHI | `perapihan_web_smoke` PM-6a |
| Password sementara, password salah, akun nonaktif, sesi kedaluwarsa, logout ditangani benar | TERPENUHI | `perapihan_web_smoke` PM-7a s.d. PM-7g |
| Tidak ada redirect loop atau pengalihan eksternal | TERPENUHI | `perapihan_web_smoke` PM-8a (5 tujuan jahat), PM-8b (rantai ≤ 2 langkah) |
| Tautan detail tetap diperiksa haknya setelah login, termasuk setelah berganti akun | TERPENUHI | `perapihan_web_smoke` PM-9a/b/c |
| Tampilan login dan navigasi berfungsi pada desktop serta ponsel | TERPENUHI | `uji-perapihan.mjs` B-1, B-2, B-3 pada 1440/768/390 px |
| API dan login aplikasi mobile tetap kompatibel | TERPENUHI | `v2_phase3/4/5_api_contract` seluruhnya tetap lulus tanpa perubahan assertion |
| Menyembunyikan menu bukan pengganti otorisasi | TERPENUHI | seluruh baris matriks pada `matriks-hak-akses.md` §3 diuji dengan status HTTP |

---

## Butir yang MENUNGGU VERIFIKASI

Rincian, langkah lanjutan, dan hasil yang diharapkan: `risiko-dan-uji-tertunda.md`.

1. Safari fisik (macOS/iOS) — cetak dan tampilan.
2. Perangkat Android/iOS fisik untuk halaman web (bukan aplikasi).
3. Audit kontras dan pembaca layar otomatis (axe/Lighthouse).
4. Migrasi 010 pada produksi.
5. Audit Codex atas seluruh paket.
