# Audit independen Codex — PRD V5 Fase 2

**24 Oktober 2026 · LULUS setelah tiga koreksi terarah pada konsistensi pembacaan dan larangan admin menulis klinis.** Lima kriteria wajib Fase 2 terpenuhi pada fixture lokal. Fase 3 belum dimulai; tidak merge, deploy, atau migrasi produksi.

## Cakupan dan baseline

- Branch `claude/prd-v5-fase-2`, baseline audit Fase 1 `1852fdba5e706c602a66c026c6d7ed92fa055797`. Seluruh commit fase diperiksa: `8648eef` (implementasi), `12332b1` (bukti/handoff), `5f95f5a` (keputusan BC). `git ls-remote` mengonfirmasi remote branch `5f95f5a2a25fe22adc3bb195cf703ba635734159` dan main `241d09e` sebelum audit.
- Dibaca AGENTS, PRD V5, audit Fase 1, README/handoff Fase 2, keputusan BC, perubahan layanan/repository, UI/API, navigasi, migrasi/rollback dan alat pemeriksa. Cakupan SQL dibandingkan dengan aturan profil binaan D-10 dan fixture penugasan aktif.
- Permintaan pengguna dan handoff menempatkan Claude pada keadaan berhenti. Tidak menjalankan Claude, subagen, worktree atau perubahan mobile. Pemeriksaan `ps` diblokir sandbox; tidak mengklaim pemeriksaan proses berhasil. Tidak terlihat perubahan bersamaan pada berkas terlacak selama audit.
- PHP 8.4.14, MariaDB lokal, Node v26.7.0, Chromium Playwright. Semua kunjungan, obat, fasilitas rujukan, tindak lanjut dan akun uji sintetis. Target utama `test_codex_v5_f2_test`, HTTP `test_codex_v5_f2_http_test`, browser `test_codex_v5_f2_browser_test`; suite migrasi memakai `test_v5_f2_migrasi_test`/`test_v5_f2_rollback_test`. Fixture resmi hanya membaca sandbox uji asal dan membangun target `test_*_test`.
- Direktori pekerjaan lain `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/` tidak disentuh atau dimasukkan commit.

## Temuan dan koreksi

| Temuan | Reproduksi dan dampak | Koreksi / hasil |
| --- | --- | --- |
| F-1 · P2 · Detail dapat mencampur dua versi | Kepala kunjungan dibaca lebih dahulu, lalu log akses, kemudian tiga kueri rincian aktif. Probe menahan pembaca pada trigger log akses dengan named lock, menyimpan koreksi di koneksi lain, lalu melepas pembaca. Nomor versi/keluhan tetap lama tetapi obat, rujukan dan tindak lanjut sudah baru. | `kunjungan()` sekarang mengambil snapshot rincian yang terikat `(kunjungan_id, versi)` melalui SELECT yang sama dengan kepala. `detail()` memakai snapshot itu. Kunjungan Fase 1 tanpa snapshot tetap memiliki rincian kosong. Probe sebelum gagal, sesudah lulus. |
| F-2 · P2 · Kepala riwayat dapat tertinggal dari isinya | Skenario sama pada `riwayatVersi()`: kepala versi lama, tetapi kueri riwayat sesudah log akses mengambil versi baru. Label terbaru menjadi tidak konsisten. | Kueri riwayat dibatasi sampai nomor versi kepala yang telah dibaca. Muat ulang mendapat versi yang lebih baru. Probe sebelum gagal, sesudah lulus. |
| F-3 · P2 · Penambahan role admin saat koreksi menunggu belum diperiksa ulang | Capability sebelum transaksi melihat petugas. Jika admin ditambahkan sebelum pemeriksaan berkunci mendapatkan akun, kueri penugasan hanya memeriksa role pengurus dan tidak menolak role admin yang baru. Probe menahan kunci akun, menambahkan admin dalam transaksi tertahan, lalu commit saat koreksi menunggu; koreksi sebelumnya berhasil. Celah ini berasal dari pemeriksaan transaksi Fase 1 dan terjangkau oleh koreksi Fase 2. | `kunciPenugasanAktif()` kini membaca ulang role admin dengan current/locking read di dalam transaksi sesudah kunci akun. Berlaku untuk buat, koreksi dan hasil rujukan. Probe koreksi sekarang 403 tanpa perubahan klinis. |

Koreksi aplikasi auditor hanya menyentuh `app/Kesehatan/KesehatanRepository.php` dan `KesehatanService.php`. Tidak ada perubahan skema, kebijakan hak akses, rute API, isi formulir atau modul lain; F-3 menegakkan larangan admin yang sudah diwajibkan PRD. Pengujian baru `tests/v5_phase2_audit.php` tidak mengganti/melonggarkan oracle implementator. Probe awal 16/2, sesudah F-1/F-2 18/0; setelah ditambah uji log akses dan pencabutan berkunci 25/0. Kontrol penambahan role admin 26/2, sesudah F-3 hasil final 28/0.

## Kriteria wajib

| Kriteria PRD Fase 2 | Penilaian independen |
| --- | --- |
| Satu kunjungan dengan dua obat, rujukan dapat diperbarui, tanpa baris ganda | Lulus. Integrasi J-2/J-3, HTTP K-B/K-C/K-F dan browser. Probe tambahan membuat **dua obat, dua rujukan, dua tindak lanjut**; daftar tetap satu baris dan hasil rujukan kedua tidak mengubah yang pertama. Satu tindak lanjut selesai sementara yang lain tetap terbuka. |
| Alasan wajib, versi monoton, riwayat sebelum/sesudah, koreksi bersamaan | Lulus. Tanpa alasan/pendek ditolak atomik; alasan tidak masuk log umum. Enam koreksi dari versi sama menghasilkan satu sukses dan lima 409; enam kiriman identik menghasilkan satu versi dan lima replay. Riwayat isi dan rincian lama tetap. Trigger kegagalan UPDATE rujukan, INSERT snapshot, dan INSERT audit membatalkan kepala, rincian, versi, snapshot dan klaim bersama-sama. F-1/F-2 ditutup. |
| Pembaca binaan hanya baca dan sesuai cakupan, admin tidak menulis klinis | Lulus. Matriks layanan/web/API mencakup admin, admin+petugas, dua petugas, pembimbing, murobi, gabungan keduanya, penugasan tanpa santri, guru/pengurus biasa dan wali. Tiga operasi tulis ditolak bagi pembaca/admin. F-3 menutup penambahan role admin ketika koreksi sedang menunggu kunci. Draf dan riwayat versi tetap hanya petugas/admin; ID luar cakupan/tidak ada sama-sama ditolak untuk pembaca binaan. Filter serta ringkasan tidak memperluas cakupan. |
| Sesi lama kehilangan akses setelah pencabutan/perubahan cakupan | Lulus. Objek pengguna, cookie dan token lama diuji. Penugasan nonaktif/berakhir, pindah kamar, kelas selesai, master nonaktif, akun nonaktif, role dicabut dan santri nonaktif menutup hak terkait. Probe tambahan menahan transaksi pencabutan petugas, mendeteksi koreksi menunggu pembacaan berkunci, kemudian commit: 403 tanpa versi/rincian/klaim/audit baru. |
| Tidak mengubah stok atau tabel PSB/keuangan/izin | Lulus. Tidak ada tabel stok/persediaan atau jalur mutasinya. Jumlah seluruh tabel nonkesehatan tetap dalam integrasi; checksum tabel PSB, pembayaran/penugasan PSB, izin, wali/relasi identik. Migrasi murni empat CREATE TABLE aditif; manifest/checksum saat migrasi–rollback dan referensi yatim diperiksa. Diff kode di luar kesehatan hanya tambahan navigasi dan rute V5 yang disetujui. |

## Bukti eksekusi auditor

Semua berkas di `bukti/audit-codex/` berasal dari eksekusi auditor sendiri.

| Rangkaian | Hasil | Bukti |
| --- | --- | --- |
| Statis Fase 2, lint 21 berkas fase | 63/0 | `statis.txt`, `regresi/v5_phase2_static.txt` |
| Integrasi layanan + konkurensi final | 134/0 | `integrasi-final.txt` |
| HTTP web/API final | 87/0 | `http-final.txt` |
| Migrasi–rollback 025 final | 29/0 | `migrasi-rollback-final.txt` |
| Probe auditor final | 28/0 | `probe-final.txt`; kontrol `probe-sebelum.txt`, `probe-sesudah.txt`, `probe-peran-sebelum.txt` |
| Browser Fase 2, 375/768/1280 + tanpa JS | 58/0 | `browser.txt`, `tangkapan/*.png` |
| Integrasi fondasi penugasan | 161/0 | `regresi/penugasan.txt` |
| HTTP pembinaan Tahap 5 / penilaian dan rapor Tahap 7 | 106/0 · 122/0 | `regresi/tahap5-http.txt`, `regresi/tahap7-http.txt` |
| HTTP data dan penugasan Tahap 6, 025/024 kosong dilepas dahulu | 132/0 | `regresi/tahap6-http.txt`, `regresi/rollback-025-024.txt` |
| HTTP navigasi/Beranda Tahap 2, fixture baru | 188/3, hanya oracle menu BB/BC | `regresi/tahap2-http.txt` |
| Browser kerangka sembilan akun, tiga lebar | 107/0 | `regresi/browser-kerangka.txt` |
| Integrasi Fase 1 / probe auditor Fase 1 | 71/4 · 27/0 | `regresi/v5-fase1-integrasi.txt`, `regresi/v5-fase1-probe.txt` |
| Seluruh 32 suite statis | 22 exit 0, 8 exit 1, 2 exit 77 | `regresi/ringkasan.txt`, berkas masing-masing suite |

Tangkapan detail, koreksi dan riwayat pada 375px diperiksa visual: teks panjang membungkus dan isi/tombol tetap terbaca. Browser menggunakan aset CDN lokal seperti runner resmi, bukan bukti CDN/hosting. Status HTTP, otorisasi, alasan wajib, no-store, log akses dan kegagalan transaksi diuji server secara terpisah.

Cara mengulang probe:

```bash
V5F2_DB=test_codex_v5_f2_test bash tests/v5_phase2_db.sh
V5F2_DB=test_codex_v5_f2_test V5_RUN_INTEGRATION=1 php tests/v5_phase2_integration.php
V5F2_DB=test_codex_v5_f2_test V5_RUN_AUDIT=1 php tests/v5_phase2_audit.php
```

Probe memakai trigger sementara dan koneksi/proses terpisah; trigger dilepas, role admin tambahan dihapus serta penugasan dipulihkan. Kunjungan sintetis sukses tetap hanya di DB uji. Probe terakhir memakai target baru `test_codex_v5_f2_probe_test` karena regresi Fase 1 sengaja mengakhiri penugasan petugas pada target awal. Pembangunan fixture final dijalankan berurutan karena DB perantara resmi memakai nama tetap. Pada putaran awal ada tumpang tindih pembangunan fixture ketika migrasi masih berjalan; meskipun suite tersebut lulus, hasil kelulusan final di atas berasal dari pengulangan berurutan. Percobaan browser kerangka pertama salah menyebut satu akun (`sbx_guru_ganda`, seharusnya `sbx_ganda`); kegagalan login disimpan dan diperbaiki dengan mengulang runner memakai akun fixture yang benar. Sesudah F-3, statis/lint, integrasi/konkurensi, HTTP dan browser kesehatan dijalankan ulang; suite migrasi dan regresi modul lain dijalankan sebelum F-3 karena koreksi terakhir hanya menambah pemeriksaan di transaksi kesehatan, tanpa perubahan migrasi atau modul tersebut.

## Oracle historis dan tinjauan penafsiran

Delapan suite statis exit 1 sesuai handoff: `v5_phase1_static` 44/4 membatasi migrasi terakhir ke 024, melarang koreksi/tombol ubah dan mengharuskan hanya Tercatat; semua itu memang diperluas Fase 2. Tujuh suite lain sama dengan audit Fase 1: `penugasan_static` masih menghitung tujuh jenis, `keamanan_psb_static`/Tahap 3 melarang pratinjau AG lama, Tahap 4 melarang migrasi sesudah Tahap 3, Tahap 6 melarang perubahan navigasi, Tahap 7 melarang perubahan katalog/API/database/bin, Tahap 8 melarang perubahan database/API. `v3_phase2_static` dan `v3_phase3_static` exit 77. Oracle tidak diubah.

Empat kegagalan integrasi Fase 1 hanya mengharapkan `sbx_pengurus_a` dan `sbx_murobi_c` tidak memiliki hak baca; Fase 2 memang memberi pembimbing/murobi daftar binaan dan tetap menolak detail di luar cakupan. Tiga kegagalan HTTP Tahap 2 hanya peta menu admin (BB) serta pembimbing/murobi (BC). Hak per baris dan larangan tulis diuji ulang oleh matriks Fase 2, bukan disimpulkan dari menu. Suite migrasi Fase 1 dan HTTP/browser Fase 1 tidak dieksekusi ulang oleh auditor pada giliran ini; kompatibilitas kunjungan Fase 1 tanpa snapshot dan koreksi pertamanya diuji di suite migrasi Fase 2 (N-5).

| Penafsiran README | Kesimpulan auditor |
| --- | --- |
| Q-1 | Mengikuti keputusan eksplisit BC: menu pembimbing dan murobi dipertahankan. BB tetap 18 menu admin. |
| Q-2 | Diterima: hak mengikuti cakupan saat ini, sama dengan D-10; perpindahan memindahkan hak baca riwayat Tercatat, bukan kepemilikan catatan. |
| Q-3 | Diterima: gabungan penugasan aktif diperiksa server, konsisten untuk web/API. Peran aktif sesi tidak memberi hak tambahan. Sumber log ditentukan per baris. |
| Q-4 | Diterima untuk fase ini: pembaca binaan melihat rincian terkini saja, tanpa draf, riwayat alasan/versi atau log pengawasan. Riwayat versi tetap tersedia bagi petugas/admin. |
| Q-5 | Diterima: Draf belum menjadi catatan Tercatat; perubahan draf tetap berversi, alasan wajib setelah Tercatat. Tidak boleh kembali ke Draf. |
| Q-6 | Diterima: hasil rujukan dan tindak lanjut memakai transaksi koreksi yang sama; tidak ada mutasi rincian tanpa versi. |
| Q-7 | Diterima: minimum lima karakter alasan merupakan validasi operasional; bukan alasan yang dihasilkan sistem. |
| Q-8 | Diterima untuk fase ini: batas rincian dan teks/desimal merupakan validasi input, tanpa stok/saran medis. Tindak lanjut melekat ke kunjungan dan dapat lebih dari satu. |
| Q-9 | Diterima sebagai batas fase: identitas santri kunjungan tidak dipindah melalui koreksi. Alur salah santri/pembatalan masih memerlukan keputusan produk terpisah; audit tidak membuat fitur itu. |
| Q-10 | Diterima: laporan operasional fase ini berupa daftar/filter/ringkasan dari cakupan yang sama; cetak/agregat lanjut tetap Fase 5. |
| Q-11 | Diterima dan diuji: isi serta alasan hanya di tabel kesehatan privat; log umum hanya metadata. |

## Batas dan handoff

- Tidak mengakses atau memeriksa cPanel/produksi, MySQL hosting, Safari/perangkat fisik, pembaca layar, aplikasi iOS/Android, atau beban besar. Mobile memang Fase 4; ringkasan wali/notifikasi Fase 3 belum dikerjakan.
- Konkurensi koreksi, kiriman identik, pembacaan bersilangan koreksi, pencabutan serta penambahan role admin yang memegang kunci lebih dahulu telah direproduksi. Tidak mengklaim seluruh interleaving perubahan role/penempatan teruji; deadlock 1205/1213 tidak direproduksi.
- Pembacaan yang telah mendapat izin sebelum pencabutan selesai dapat menyelesaikan request yang sedang berjalan; request sesudah perubahan cakupan/pencabutan diperiksa ulang. Uji sesi lama membuktikan request berikutnya tertutup, bukan penarikan isi yang sudah tampil di layar.
- Rollback 025 hanya setelah preflight kosong dan backup terverifikasi, tanpa penulis aktif. Migrator tidak otomatis menjalankan preflight; jangan rollback ketika ada rincian/snapshot kesehatan. Uji restore/hosting menyeluruh tidak diklaim oleh fase ini.
- Kelulusan ini hanya Fase 2 pada lingkungan uji. Berhenti setelah commit/push audit pada branch yang sama. Fase 3, merge dan deploy tetap memerlukan instruksi Human Developer.
