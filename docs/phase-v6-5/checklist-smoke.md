# Checklist smoke cPanel, aplikasi, dan rilis bersama V6 — menunggu pelaksanaan

Semua baris **produksi/hosting** berstatus **Menunggu pelaksanaan**, bukan lulus. Operator mengisi tanggal, lingkungan (staging hasil restore atau produksi), SHA web, build aplikasi, pelaku, dan bukti aman. Pengisian hanya sesudah audit Codex Fase 5 dan instruksi Human Developer. Data smoke wajib **sintetis**: santri dan akun berawalan jelas (mis. `SMOKE V6 …`), nominal kecil, tanpa referensi rekening nyata. Transaksi uang tidak pernah dihapus, sehingga setiap transaksi smoke di produksi dipasangkan dengan koreksi/pembatalan beralasan sampai saldo dan sisa Rp0 (lihat runbook §3.5).

Kolom "Lokal" hanya menunjuk bukti salinan lokal; itu bukan bukti hosting.

| # | Langkah | Lokal | Produksi/hosting |
| --- | --- | --- | --- |
| 1 | Inventaris: SHA web terpasang, `migrate.php status` (status 024–026 dan 027–029), PHP CLI/FPM, engine/versi DB, CHECK ditegakkan, zona waktu DB | MariaDB 12.3.2; preflight S-1/S-4 | Menunggu pelaksanaan |
| 2 | Keputusan Human Developer: V5 (024–026) ikut rilis atau tidak; daftar penugasan bendahara lama (K-1) ditinjau; kebijakan R-10 | Preflight mencetak keduanya | Menunggu pelaksanaan |
| 3 | Backup DB + konfigurasi privat, SHA-256, restore ke salinan, preflight/verifier pada salinan | `bukti/rilis.txt` S-6 (writer 11/0, dump 12/0) | Menunggu pelaksanaan |
| 4 | `bin/v6_preflight.php manifest-privat --rilis=docs/phase-v6-5/manifest-migrasi.json` exit 0 (35 SHA, daftar lengkap, jam DB = aplikasi, tidak sebagian) | S-1…S-4 | Menunggu pelaksanaan |
| 5 | `migrate.php up` → tepat 027, 028, 029 (dan 024–026 bila diputuskan); `up` ulang kosong; `bin/v6_verify.php manifest-privat` exit 0 sebelum transaksi pertama | S-1 | Menunggu pelaksanaan |
| 6 | HTTP 403/404 untuk `.env`, `database/*.sql`, `bin/*.php` (via web), `docs/`, `tests/`, `storage/`, `berkas_psb/`; respons BSA `Cache-Control: private, no-store`; ekspor `nosniff` | HTTP lokal H-D/H-E | Menunggu pelaksanaan |
| 7 | Admin menugaskan petugas smoke (cakupan satu unit); menu Keuangan tampil untuk admin, petugas, dan wali smoke; pengurus lain tidak | Fase 1–2, H-A | Menunggu pelaksanaan |
| 8 | Tarif + penerbitan tagihan smoke (pratinjau → terbit, ulang tidak ganda); bayar tunai sebagian + transfer menunggu → verifikasi; kuitansi | Fase 2 | Menunggu pelaksanaan |
| 9 | Setoran tunai, belanja, setoran transfer menunggu → verifikasi/tolak; bayar tagihan dari tabungan (centang wajib) → saldo dan sisa turun dengan satu referensi silang | Fase 3, I-2 | Menunggu pelaksanaan |
| 10 | Koreksi/pembatalan beralasan untuk setiap transaksi smoke → saldo dan sisa Rp0; jejak koreksi menampilkan aktor, waktu, alasan, asal | I-4, H-B | Menunggu pelaksanaan |
| 11 | Laporan bulan berjalan (web dan `GET /api/v1/v6/bsa/laporan`): angka = transaksi smoke; kas baru tidak bertambah oleh bayar dari tabungan; pita selisih Rp0; = rekonsiliasi harian | I-2/I-3, H-B, H-E, browser B-A…B-E | Menunggu pelaksanaan |
| 12 | Ekspor CSV per santri/rincian/koreksi: hanya dalam hak; wali tanpa koreksi; tanpa referensi transfer; tercatat audit tanpa nominal | I-7, H-D, B-C | Menunggu pelaksanaan |
| 13 | Akses silang: petugas unit lain, wali lain, pengurus/guru biasa → 403/404/kosong pada daftar, detail, laporan, ekspor, API | I-1, I-6, H-A/H-C | Menunggu pelaksanaan |
| 14 | Pencabutan penugasan dan relasi wali → sesi web, token API, dan layar aplikasi lama tertutup pada permintaan berikutnya | I-8, H-F, aplikasi M-E | Menunggu pelaksanaan |
| 15 | Notifikasi: InApp generik tanpa nominal/nama; Push hanya bila disetujui; WhatsApp OFF; `notifikasi_worker.php --uji-coba` 0 request provider | Fase 4 (`docs/phase-v6-4/`) | Menunggu pelaksanaan |
| 16 | Push nyata iOS + Android (jika disetujui): ketukan foreground/background/cold start → masuk → server memeriksa hak | **Belum diuji** (terbuka sejak Fase 4) | Menunggu pelaksanaan |
| 17 | Aplikasi build rilis pada perangkat fisik iOS/Android: Keuangan dan Laporan, perlindungan tangkapan layar, cuplikan pengalih kosong, log/cache tanpa data keuangan | Expo web M-A…M-F; Android emulator dan iOS Release simulator (Fase 4); **fisik belum** | Menunggu pelaksanaan |
| 18 | Log proxy/WAF/APM/OS tanpa body API, header Authorization, referensi transfer | Log server PHP lokal H-G | Menunggu pelaksanaan |
| 19 | Rollback kode direhearsal pada staging; rollback DB hanya bila tabel V6 kosong; rollback berisi data ditolak penjaga SQL | S-1, S-5, S-6 | Menunggu pelaksanaan |
| 20 | Rekonsiliasi BSA dengan kas fisik dan mutasi rekening pada hari pertama | Tidak dapat diuji lokal | Menunggu pelaksanaan |
| 21 | UAT petugas BSA, admin, dan orang tua | Tidak diuji | Menunggu pelaksanaan |
| 22 | Keputusan rilis bersama tagihan **dan** tabungan oleh Human Developer sesudah audit Codex | — | Menunggu pelaksanaan |

Tidak ada merge, deployment, migrasi produksi, perubahan kanal, atau rilis aplikasi pada sesi ini.
