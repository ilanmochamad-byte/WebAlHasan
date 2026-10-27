# Checklist smoke cPanel, aplikasi, dan rilis V7 — menunggu pelaksanaan

Semua baris **produksi/hosting** berstatus **Menunggu pelaksanaan**, bukan lulus. Operator mengisi tanggal, lingkungan (staging hasil restore atau produksi), SHA web, build aplikasi, pelaku, dan bukti aman. Pengisian hanya sesudah audit Codex Fase 5 dan instruksi Human Developer. Data smoke wajib **sintetis**: santri, wali, dan akun berawalan jelas (mis. `SMOKE V7 …`), alasan tanpa data pribadi nyata. Pengajuan, riwayat, dan audit tidak pernah dihapus, sehingga setiap pengajuan smoke di produksi diakhiri dengan pembatalan beralasan atau keputusan (runbook §3.5).

Kolom "Lokal" hanya menunjuk bukti salinan lokal; itu bukan bukti hosting.

| # | Langkah | Lokal | Produksi/hosting |
| --- | --- | --- | --- |
| S-1 | Inventaris: SHA web terpasang, `migrate.php status` (024–029 dan 030–031), PHP CLI/FPM, engine/versi DB, CHECK sesi/global, zona waktu DB | MariaDB 12.3.2; preflight R-1/R-2 | Menunggu pelaksanaan |
| S-2 | Keputusan Human Developer: V5/V6 sudah dirilis sesuai gerbangnya (V7 mewajibkan 029); F5-1 aturan murobi V2 dan T-1 diterima atau ditindaklanjuti | Preflight menolak tanpa 029 | Menunggu pelaksanaan |
| S-3 | Tinjauan penugasan pembimbing/murobi dan akun pengurus/guru; daftar pembimbing tanpa akun dari preflight | Preflight mencetak jumlahnya | Menunggu pelaksanaan |
| S-4 | Backup DB + konfigurasi privat, SHA-256, restore ke salinan, preflight/verifier pada salinan | `bukti/rilis.txt` R-7 (writer 11/0, dump 12/0) | Menunggu pelaksanaan |
| S-5 | `bin/v7_preflight.php manifest-privat --rilis=docs/phase-v7-5/manifest-migrasi.json` exit 0 (33 SHA, daftar lengkap, jam DB = aplikasi, tidak sebagian) | R-1, R-2, R-4 | Menunggu pelaksanaan |
| S-6 | `migrate.php up` → tepat 030 dan 031; `up` ulang kosong; `bin/v7_verify.php manifest-privat` exit 0 sebelum pengajuan pertama | R-3, R-5 | Menunggu pelaksanaan |
| S-7 | HTTP 403/404 untuk `.env`, `database/*.sql`, `bin/*.php` (via web), `docs/`, `tests/`, `storage/`, `berkas_psb/`; respons `/api/v1/v7/izin/*` `Cache-Control: private, no-store` | Fase 1–4 HTTP | Menunggu pelaksanaan |
| S-8 | Wali smoke mengajukan di website (klik ganda → satu pengajuan); pembimbing tunggal menerima; murobi belum melihat | H-A, browser E-A | Menunggu pelaksanaan |
| S-9 | Pembimbing meminta perbaikan → wali memperbaiki → pembimbing meneruskan → murobi memutus di halaman V2; wali kedua hanya status | H-A, E-1, browser E-A | Menunggu pelaksanaan |
| S-10 | Santri tanpa/dua pembimbing → antrean admin; admin menetapkan pembimbing sah (bukan sah ditolak); routing murobi nol/dua → admin menetapkan murobi atau memutus sebagai pengganti beralasan | E-2…E-6, H-B | Menunggu pelaksanaan |
| S-11 | Pembatalan wali beralasan sebelum keputusan; pembatalan sesudah keputusan ditolak; penolakan pembimbing tanpa keputusan murobi | E-1, E-3, E-4, browser E-E | Menunggu pelaksanaan |
| S-12 | Jalur pengurus V2: buat, putus, batal, koreksi tanpa tahap pembimbing; benturan tanggal dengan pengajuan wali → 409 | E-10, E-13, H-C, browser E-B | Menunggu pelaksanaan |
| S-13 | Akses silang: wali asing, pembimbing lain, murobi lain, pengurus biasa → 404/kosong pada daftar, rincian, mutasi, laporan, ekspor, tautan notifikasi | E-7, H-D, browser E-C | Menunggu pelaksanaan |
| S-14 | Pencabutan relasi wali dan penugasan pembimbing/murobi → sesi web, token API, dan layar aplikasi lama tertutup pada permintaan berikutnya | E-8, H-D | Menunggu pelaksanaan |
| S-15 | Laporan/cetak/CSV V2 dan laporan/ekspor V7 terbuka tanpa galat; total laporan = pengajuan per asal | H-E, browser E-D | Menunggu pelaksanaan |
| S-16 | Notifikasi InApp generik (tanpa nama/alasan/tanggal); WhatsApp nol baris; `notifikasi_worker.php --uji-coba` 0 request provider | E-14 | Menunggu pelaksanaan |
| S-17 | Push nyata iOS + Android (jika disetujui): ketukan foreground/background/cold start → masuk → server memeriksa hak; sesudah logout/pencabutan tanpa isi | **Belum diuji** (terbuka sejak Fase 4) | Menunggu pelaksanaan |
| S-18 | Aplikasi build rilis pada perangkat fisik iOS/Android: alur wali/pembimbing/murobi/admin, perlindungan tangkapan layar, log/cache tanpa alasan izin | Expo web (Fase 5); Android emulator Debug dan iOS Release simulator (audit Fase 4); **fisik belum** | Menunggu pelaksanaan |
| S-19 | Log proxy/WAF/APM/OS tanpa body API, header Authorization, alasan izin | Log server PHP lokal H-F | Menunggu pelaksanaan |
| S-20 | Rollback kode direhearsal pada staging; rollback DB hanya bila data V7 kosong; rollback berisi data ditolak penjaga SQL | R-4…R-6 | Menunggu pelaksanaan |
| S-21 | UAT orang tua, pembimbing, murobi, dan admin | Tidak diuji | Menunggu pelaksanaan |
| S-22 | Keputusan rilis V7 oleh Human Developer sesudah audit Codex | — | Menunggu pelaksanaan |

Tidak ada merge, deployment, migrasi produksi, perubahan kanal, atau rilis aplikasi pada sesi ini.
