# Checklist smoke cPanel, aplikasi, dan rilis — penugasan lintas guru/pengurus dan Keamanan

Semua baris **produksi/hosting** berstatus **Menunggu pelaksanaan**, bukan lulus. Operator mengisi tanggal, lingkungan (staging hasil restore atau produksi), SHA web, build aplikasi, pelaku, dan bukti aman. Pengisian hanya sesudah audit Codex Fase 5 dan instruksi Human Developer.

Data smoke wajib **sintetis**: akun dan data berawalan jelas (mis. `SMOKE PK …`). Penugasan, identitas guru, dan audit tidak dihapus. Setiap penugasan smoke di produksi diakhiri dengan alasan, dan baris yang tertinggal menahan rollback DB (runbook §3.5, §5).

Kolom "Lokal" hanya menunjuk bukti salinan lokal; itu bukan bukti hosting.

| # | Langkah | Lokal | Produksi/hosting |
| --- | --- | --- | --- |
| S-1 | Inventaris: SHA web terpasang, `migrate.php status` (031 tercatat; 032–033 belum), PHP CLI/FPM, engine/versi DB (catatan lama: MariaDB 10.6.28), CHECK sesi/global, zona waktu DB | MariaDB 12.3.2; preflight R-1/R-2 | Menunggu pelaksanaan |
| S-2 | Keputusan Human Developer: rilis utuh Fase 1–4 + 032–033; gerbang V5/V6/V7 diputuskan tersendiri | Preflight/verifier menolak sebagian (R-4) | Menunggu pelaksanaan |
| S-3 | Tinjauan data ganda guru/pengurus dan akun ganda warisan dari keluaran preflight; tentukan dari kelompok mana orang itu akan ditugaskan | Preflight mencetak keduanya (R-1) | Menunggu pelaksanaan |
| S-4 | Backup DB + konfigurasi privat, SHA-256, restore ke salinan, preflight/verifier pada salinan | `bukti/rilis.txt` R-7 (writer 11/0, dump 12/0) | Menunggu pelaksanaan |
| S-5 | Preflight dari kode terpasang, `--rilis` memakai jalur absolut manifest di checkout sumber (runbook §3.2); exit 0 dan manifest privat 0600 tersimpan (35 SHA, daftar lengkap, jam DB = aplikasi, tidak sebagian) | R-1, R-2; probe auditor kegagalan tulis | Menunggu pelaksanaan |
| S-6 | `migrate.php up` → tepat 032 dan 033; `up` ulang kosong; `bin/penugasan_keamanan_verify.php manifest-privat` exit 0 sebelum penugasan pertama | R-3, R-5 | Menunggu pelaksanaan |
| S-7 | HTTP 403/404 untuk `.env`, `database/*.sql`, `bin/*.php` (via web), `docs/`, `tests/`, `storage/`; respons `/api/v1/keamanan/*` `Cache-Control: private, no-store` | Fase 3–4 HTTP | Menunggu pelaksanaan |
| S-8 | Admin menugaskan guru smoke sebagai Pembimbing/Kesehatan dari kelompok Guru → identitas dibentuk, akun guru memperoleh pemilih peran; Murobi/Guru Mapel tetap hanya guru | R-6, Fase 1 HTTP | Menunggu pelaksanaan |
| S-9 | Pemisahan tugas: guru smoke sebagai pembimbing mengajukan izin santri yang ia murobi-i → masuk antrean admin; keputusan oleh akun pengaju/peninjau ditolak (termasuk admin pengganti) | Fase 2 HTTP/integrasi; verifier P-1/P-2 | Menunggu pelaksanaan |
| S-10 | Penugasan Keamanan untuk pengurus smoke dan guru smoke → menu Keamanan pada peran Pengurus; daftar/rincian/cetak izin, pelanggaran, konseling Internal, kehadiran terbuka | E-3 (15 halaman, 10 endpoint per akun) | Menunggu pelaksanaan |
| S-11 | Keamanan hanya baca: POST/PUT/PATCH/DELETE web dan API → 405; kasus Rahasia 404; CSV kehadiran ditolak | E-3 (43 kiriman → 405) | Menunggu pelaksanaan |
| S-12 | Jejak `keamanan.*` hanya metadata; verifier lulus sesudah smoke | E-3, verifier §4 | Menunggu pelaksanaan |
| S-13 | Pencabutan: penugasan Keamanan dinonaktifkan/diakhiri, guru dinonaktifkan di Data Guru → sesi web dan token aplikasi lama ditolak pada permintaan berikutnya; diaktifkan → pulih | E-4, E-5 | Menunggu pelaksanaan |
| S-14 | Akses silang: pengurus tanpa Keamanan, admin (pengawasan), orang tua → tidak membuka halaman/endpoint Keamanan | Fase 3–4 HTTP | Menunggu pelaksanaan |
| S-15 | Aplikasi build rilis pada perangkat fisik iOS/Android: ubin Keamanan, empat bagian baca, "Identitas & penugasan" di Profil, perlindungan tangkapan layar | Expo web dan Android emulator Debug (Fase 4); **iOS runtime, fisik, build distribusi belum** | Menunggu pelaksanaan |
| S-16 | Log proxy/WAF/APM/OS tanpa body API, header Authorization, alasan izin | Log server PHP lokal E-6 | Menunggu pelaksanaan |
| S-17 | Rollback kode direhearsal pada staging; rollback DB hanya bila data kosong; rollback berisi data ditolak penjaga SQL | Rollback DB R-4…R-6; latihan rollback kode terpasang belum diuji | Menunggu pelaksanaan |
| S-18 | Verifier V5/V6/V7 berkala (bila dijadwalkan) tetap lulus sesudah 032–033 | `bukti/verifier-lama-*.txt` | Menunggu pelaksanaan |
| S-19 | UAT admin, pengurus/guru Keamanan, pembimbing yang juga murobi | Tidak diuji | Menunggu pelaksanaan |
| S-20 | Keputusan rilis oleh Human Developer sesudah audit Codex | — | Menunggu pelaksanaan |

Tidak ada merge, deployment, migrasi produksi, perubahan kanal, atau rilis aplikasi pada sesi ini.
