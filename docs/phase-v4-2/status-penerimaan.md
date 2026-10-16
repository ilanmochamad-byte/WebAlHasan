# Status Fase 2 PRD V4 — menunggu audit Claude Code

Tanggal: 16 Oktober 2026. Implementator: Codex. Implementasi dan uji mandiri selesai; **belum dinyatakan lulus audit independen**. Hanya website/API Fase 2, tanpa Fase 3, merge, atau deploy.

Baseline remote diverifikasi dengan `git ls-remote origin refs/heads/codex/prd-v4-fase-1`: `d11f415335547e4d3074db1aab358fd38379f444`, sesuai putusan `docs/phase-v4-1/audit-claude.md`. Checkout web dan mobile awal bersih. Branch `codex/prd-v4-fase-2` dibuat tepat dari audit itu, tanpa worktree. Mobile tetap `main` di `dfae26d` dan tidak diubah.

| Kriteria | Hasil aktual implementator | Bukti |
| --- | --- | --- |
| Harian 80/90, tengah 78, akhir 92 → 85 B web/API | Lulus; layanan, API, web pengganti, dan web guru | `integrasi-mariadb.txt`, `http-browser.txt`, `http-browser-guru.txt`, `browser-guru-375.png` |
| Kosong Belum lengkap; 0 tersimpan | Lulus layanan, API, web; penyerahan tidak lengkap 422; seluruh komponen nol lengkap | `integrasi-mariadb.txt`, `http-browser.txt` |
| Guru di luar mapel/kelas/semester dan Pendidikan di luar cakupan 403 | Lulus baca/tulis layanan; HTTP guru mapel lain dan peran tanpa hak; web guru akses silang. Pencabutan penugasan ditolak pada request baru | `integrasi-mariadb.txt`, `http-browser.txt`, `http-browser-guru.txt` |
| Pengganti tanpa alasan 422; pelaku/kapasitas tersimpan | Lulus layanan dan API. Web memakai jalur pengganti tersendiri. Penugasan guru tidak berubah; riwayat memuat alasan dan pelaku; audit memuat kapasitas | `integrasi-mariadb.txt`, `http-browser.txt` |
| Identik/bersamaan tanpa nilai ganda; versi usang 409 | Lulus retry layanan/API/web. Enam proses PHP dengan koneksi DB terpisah: satu 200, lima 409, satu nilai dan satu riwayat | `konkurensi.txt`, `integrasi-mariadb.txt`, `http-browser.txt` |
| Riwayat, perpindahan kelas, kegagalan audit | Versi 1–3 tetap tersedia; nilai asal tetap kelas asal setelah penempatan dipindahkan; kegagalan audit menggulung nilai dan riwayat | `integrasi-mariadb.txt` |
| Keamanan dan tampilan | Validasi skala/tipe/payload injeksi, CSRF 419, bearer 401, XSS tidak dieksekusi; 375 px/teks 24 px/desktop tanpa overflow | `integrasi-mariadb.txt`, `http-browser.txt`, `browser-375.png` |

Hasil utama: **48** pemeriksaan integrasi Fase 2, **2** konkurensi, **25** HTTP/browser pengganti dan **8** web guru lulus. Regresi Fase 1: **29** integrasi dan **23** HTTP/browser lulus. Pemeriksaan aset nonaktif opsional dalam suite integrasi Fase 1 tidak berjalan karena fixture baru tidak memiliki aset nonaktif; angka 29 tidak diklaim sebagai 30.

Regresi V1–V3: seluruh 14 suite integrasi yang dijalankan akhirnya exit 0; 14 dari 15 suite statis exit 0. `phase5_static` tetap exit 1 pada path mobile lama, sesuai baseline audit. Dua kegagalan awal karena konfigurasi/fixture dicatat beserta hasil ulang, bukan disembunyikan; lihat `hasil-pengujian.md` dan `regresi-ringkasan.txt`.

## Handoff auditor

Audit branch `codex/prd-v4-fase-2` terhadap baseline `d11f415`. Hash commit implementasi disampaikan pada handoff chat dan dapat dilihat melalui `git log` (tidak ditanam di dalam commit itu sendiri). Periksa khusus: otorisasi tiap jalur dan pencabutan penugasan; keterikatan nilai ke rencana asal; transaksi/kunci parent dan versi komponen; nilai/riwayat/audit saat gagal; pembulatan; XSS/CSRF; serta hasil enam proses konkuren pada DB uji independen. Periksa juga batas yang tertulis di `risiko-terbuka.md`. Jangan melanjutkan Fase 3 atau merge sebelum audit dan keputusan Human Developer.
