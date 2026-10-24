# Checklist smoke cPanel dan aplikasi V5 — menunggu pelaksanaan

Semua baris **produksi/hosting** berstatus **MENUNGGU PELAKSANAAN**, bukan lulus. Operator mengisi tanggal, lingkungan, SHA web, build aplikasi, pelaku, dan bukti aman sesudah audit Codex dan instruksi Human Developer. Data smoke wajib **sintetis**: santri/akun berawalan jelas (mis. `SMOKE V5 …`), tanpa keluhan/obat/nama nyata, dengan rencana penanganan sesudah smoke yang mempertahankan jejak audit (data kesehatan tidak dihapus; santri smoke dinonaktifkan/arsip, penugasan diakhiri).

Kolom "Lokal" hanya menunjuk bukti salinan lokal; itu bukan bukti hosting.

| # | Langkah | Lokal | Produksi/hosting |
| --- | --- | --- | --- |
| 1 | Inventaris: SHA web terpasang, `migrate.php status`, versi PHP CLI/FPM, engine/versi DB, CHECK ditegakkan (`CREATE TEMPORARY TABLE t (x INT CHECK (x=0)); INSERT INTO t VALUES (1)` harus gagal) | MariaDB 12.3.2 menegakkan CHECK (`bukti/rilis.txt` S-3) | Menunggu pelaksanaan |
| 2 | Backup DB + konfigurasi privat, SHA-256, restore ke salinan, `bin/v5_verify.php` pada salinan | `bukti/rilis.txt` S-4 | Menunggu pelaksanaan |
| 3 | `bin/v5_preflight.php manifest-privat --rilis=docs/phase-v5-5/manifest-migrasi.json` exit 0 (SHA migrasi/rollback = manifest audit) | S-1, S-2 | Menunggu pelaksanaan |
| 4 | `migrate.php up` (024–026 yang belum ada) + `up` ulang tanpa perubahan; `bin/v5_verify.php manifest-privat` exit 0 | S-1 | Menunggu pelaksanaan |
| 5 | HTTP 403/404 untuk `.env`, `database/*.sql`, `bin/*.php` (via web), `docs/`, `tests/`, `storage/`, `composer.lock`; respons kesehatan `Cache-Control: private, no-store` | no-store diuji HTTP lokal | Menunggu pelaksanaan |
| 6 | Admin menugaskan petugas smoke; petugas mencatat kunjungan sintetis (2 obat, 1 rujukan, 1 tindak lanjut) di web; klik ganda → satu kunjungan | Fase 1–2, `bukti/integrasi.txt` O-8 | Menunggu pelaksanaan |
| 7 | Koreksi beralasan; koreksi tanpa alasan ditolak; riwayat versi menampilkan pelaku/waktu/alasan | Fase 2, O-7 | Menunggu pelaksanaan |
| 8 | Ringkasan wali: pratinjau → terbit → wali smoke (relasi sintetis) melihat di web & aplikasi; ganti/tarik beralasan | Fase 3–4 | Menunggu pelaksanaan |
| 9 | Akses silang: pengurus biasa, guru non-murobi, wali lain, pembimbing di luar cakupan → 403/kosong untuk daftar, detail, laporan, API | O-3, O-4, `bukti/http.txt` Q-A/Q-B | Menunggu pelaksanaan |
| 10 | Laporan (`portal/kesehatan_laporan.php`, `GET /api/v1/v5/kesehatan/laporan`): angka smoke = daftar kunjungan dengan periode sama; pembimbing hanya binaan; pengawasan hanya admin; tanpa isi klinis | O-1…O-5, Q-A…Q-C, browser G-A…G-D, aplikasi H-A…H-D | Menunggu pelaksanaan |
| 11 | Pencabutan penugasan petugas/pembimbing dan relasi wali → sesi web & token lama ditolak pada permintaan berikutnya | O-4, Q-B | Menunggu pelaksanaan |
| 12 | Push/WhatsApp OFF: `notifikasi_worker.php --uji-coba` dan `--kanal=push` 0 request provider; WhatsApp tetap OFF | `bukti/worker-cron.txt` | Menunggu pelaksanaan |
| 13 | Cron dipasang (user, working dir, env/`PUSH_TOKEN_KEY` = web), `v2_phase5_cron_check.php` sehat, log dirotasi dan tanpa isi notifikasi | Cron tidak dipasang lokal | Menunggu pelaksanaan |
| 14 | Push nyata (jika disetujui) iOS + Android: payload generik, ticket/receipt akhir, ketukan foreground/background/cold start → login → server memeriksa relasi | **Tidak diuji** (terbuka sejak Fase 4) | Menunggu pelaksanaan |
| 15 | Aplikasi build native baru (modul `expo-screen-capture`) pada perangkat fisik iOS/Android: layar kesehatan & laporan, cuplikan pengalih aplikasi kosong, log/cache tanpa isi | Android emulator (Fase 4); iOS terbaru **belum**; laporan hanya Expo web | Menunggu pelaksanaan |
| 16 | Log proxy/WAF/APM/OS tidak merekam body API, header Authorization, isi klinis | Log server PHP lokal tanpa penanda (`bukti/http.txt` Q-D) | Menunggu pelaksanaan |
| 17 | Rollback kode direhearsal pada staging; rollback DB hanya bila tabel kosong; `migrate.php rollback` dengan data ditolak penjaga SQL | S-1, S-3 | Menunggu pelaksanaan |
| 18 | UAT ≥ 95% petugas kesehatan dan wali menyelesaikan tugas utama tanpa bantuan (PRD §7) | Tidak diuji | Menunggu pelaksanaan |

Tidak ada merge, deployment, migrasi produksi, perubahan kanal, atau rilis aplikasi pada sesi ini.
