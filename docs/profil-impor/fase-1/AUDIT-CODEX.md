# Audit Codex — Profil dan impor guru/pengurus Fase 1

**Keputusan 3 November 2026 — LULUS teknis Fase 1 setelah koreksi.** Keputusan berlaku untuk branch `claude/profil-impor-fase-1` dengan koreksi audit ini.

Baseline spesifikasi `c1a5b25` identik secara isi dengan baseline main `016f52f`. Audit mencakup implementasi `f8546be` dan handoff `14b6a1b`, PRD §6, PI-1–PI-10, prompt auditor, dan bukti implementator. Pada awal audit GitHub sudah menunjukkan implementasi `14b6a1b` sebagai leluhur main `ab49f47` (PR #112). Dengan demikian main yang diperiksa masih berisi versi sebelum koreksi audit ini.

## Koreksi dan bukti

| Temuan | Koreksi dan regresi |
| --- | --- |
| F-1 — NIK numerik 16 digit bisa lolos batas integer aman JavaScript meski Excel hanya menjaga 15 digit signifikan. Jalur JSON juga membulatkan pengenal pecahan. | Tolak sel angka pengenal 16 digit dan pengenal numerik pecahan. Uji integrasi serta XLSX sungguhan membuktikan konflik dan preservasi nol awal pada NIK teks/NIP alfanumerik. |
| F-2 — Pencocokan pasti memakai NIP/NIK saja dan belum memenuhi jalur ID Sistem dalam PRD §3.5. | Tambah kolom ID Sistem opsional pada templat dan pemetaan. Baris tetap memerlukan NIP atau NIK. ID hilang/bertentangan menjadi konflik. Konfirmasi timpa atau ganti pengenal tetap wajib bila diperlukan. |
| F-3 — PHP 8.4 menerima dua kata sandi bcrypt yang hanya berbeda setelah byte ke-72 sebagai kata sandi yang sama. NUL pada kata sandi baru bisa memicu galat hashing. | Batas 72 byte dan penolakan NUL sebelum hashing. API dan website menggunakan layanan yang sama dengan pesan validasi spesifik. Integrasi memeriksa hash dan token tetap saat ditolak. |
| F-4 — Rollback bisa menghapus versi sesi setelah penggantian password pada akun tanpa profil pribadi. Sesi web yang masih tersimpan dapat berlaku lagi. | Rollback berhenti sebelum perubahan skema apabila ada versi sesi nonnol. Uji migrasi membuktikan penolakan, preservasi kolom dan versi, serta jalur rollback kosong yang mencabut token terbatas. Jalankan rollback dalam masa pemeliharaan dengan penulisan aplikasi dihentikan. |

## Hasil independen

PHP 8.4.14, MariaDB 12.3.2, server PHP loopback, dan Chromium Playwright. Database audit lokal berakhiran `_test` memakai fixture sintetis. Bukti teks ada di [bukti audit](bukti/audit-codex/).

| Pengujian | Lulus/gagal | Cakupan |
| --- | --- | --- |
| Statis | 71/0 | Lint, skema, otorisasi, jalur audit, dan batas perubahan |
| Migrasi | 27/0 | 034 → 035 → rollback → 035, isi kolom lama pada 16 tabel, penjaga profil/sesi, pencabutan token terbatas |
| Integrasi | 103/0 | Guru dan pengurus baru/lama/ulang, pencocokan NIP/NIK/ID, konflik dan pilihan manual, sidik basi, rollback saat audit gagal, sidik penugasan dan role identik, sinkronisasi guru–pengurus–akun, empat kelompok akun, otorisasi foto dan ganti password |
| HTTP | 77/0 | Profil sendiri dan kontrak lama, penolakan atribut terlindungi dan foto lintas akun, unggah/ganti/hapus serta berkas berbahaya, CSRF dan admin impor, password sementara, pencabutan token/sesi lain, validasi web |
| Chromium | 46/0 | XLSX untuk guru, XLS BIFF8 untuk pengurus, pemetaan/pratinjau/simpan/ulang, pasangan manual, markup sebagai teks, angka NIK bermasalah dan nol awal, 375/768/1280 px |
| Regresi kredensial | 59/0 dan 51/0 | Integrasi dan smoke web, termasuk alur reset admin lama |

Perintah utama mengikuti `tests/profil_impor_f1_db.sh` dan suite `tests/profil_impor_f1_{static,migration,integration,http}.php`. Untuk browser, jalankan `tests/browser/uji-profil-impor-f1.mjs` dengan `BASE_URL` loopback, `OUT_DIR` sementara dan `SHEETJS_PATH` menunjuk salinan lokal SheetJS 0.18.5 yang juga dipakai halaman. Putaran pertama uji Excel nyata berhenti karena tipe buffer penggerak Playwright. Konversi eksplisit ke Node Buffer memperbaiki penggerak dan putaran akhir selesai.

## Batas dan handoff

- MariaDB produksi 10.6.28, MySQL, cPanel/Apache dan UAT belum diuji. Privasi folder foto melalui HTTP diuji pada router lokal. Tidak ada data produksi atau uji salinan produksi. Kegagalan fixture historis di suite di luar putaran ini tetap dicatat dalam bukti implementator.
- Pengujian browser memakai salinan pustaka yang sama dengan CDN. Uji ini mencakup pembacaan Excel dan wizard, sementara ketersediaan CDN dari hosting dan perangkat pengguna belum diperiksa. Pengujian web sebelum 035 mencakup kompatibilitas dasar, dan batas 72 byte berlaku pada layanan profil setelah 035.
- Fase 2 memerlukan instruksi Human Developer dan baseline yang mencakup koreksi audit ini. Status deploy produksi belum diverifikasi. Audit hanya mengubah branch fitur.
