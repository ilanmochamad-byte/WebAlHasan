# PRD V7 Fase 5 — Regresi, Keamanan, dan Kesiapan Rilis

**Status (27 Oktober 2026):** diimplementasikan Claude Code, **menunggu audit independen Codex** atas Fase 5 dan gerbang akhir V7. Belum merge, belum deploy, belum migrasi produksi, tidak ada rilis aplikasi, V8 tidak dimulai. Seluruh bukti berasal dari salinan DB lokal berfixture sintetis, Chromium, dan Expo **web**. MySQL/cPanel, perangkat fisik, Push nyata, dan UAT **belum diuji**, sehingga **produksi tidak dinyatakan lulus dan V7 tidak dinyatakan siap rilis**.

| Butir | Nilai |
| --- | --- |
| Workstream | PRD V7 Fase 1–5 — implementator Claude Code, auditor Codex (`AGENTS.md`) |
| Instruksi | Human Developer 27 Oktober 2026: implementasikan hanya Fase 5, lalu berhenti untuk audit Codex |
| Branch web | `claude/prd-v7-fase-5` dari audit Fase 4 `851ef0a26d1b8dd4e1db80ecf4f4ce8104ee8da7`. Diverifikasi: `origin/main` `857aff9` (merge PR #98) memuatnya dan `git diff 851ef0a origin/main` kosong. Tanpa worktree. |
| Branch mobile | `~/alhasanApps` `claude/prd-v7-fase-5` dari audit Fase 4 `7478f7cb153e4f8af1a1b85791ce843a29733bd2`. `origin/main` `c747ebd` (merge PR #20), `git diff 7478f7c origin/main` kosong. Hanya ringkasan dokumen. |
| Perubahan kode | **Tidak ada** pada `app/`, `api/`, `portal/`, `admin/`, `assets/`, `database/`, maupun kode aplikasi mobile (dibuktikan uji statis Y-1). Fase 5 menambah alat rilis CLI, uji, dan dokumen. |
| Migrasi | **Tidak ada migrasi baru** (031 tetap terakhir); rollback 030–031 tidak diubah (penjaga SQL sudah ada sejak Fase 1–2). |
| Lingkungan uji | PHP 8.4.14, MariaDB 12.3.2 lokal, Node v26.7.0, Chromium Playwright, Expo SDK 57 web (lock ter-commit 57.0.15). DB `test_*_test` berfixture sintetis. |

## Yang dikerjakan

### 1. Alat rilis

| Berkas | Isi |
| --- | --- |
| `manifest-migrasi.json` | SHA-256 31 migrasi 001–031, rollback 030–031, berkas mobile dari **HEAD Git** (bukan salinan kerja yang memuat paket pekerjaan lain). Dibuat `php bin/v7_manifest.php --mobile=~/alhasanApps`. |
| `bin/v7_preflight.php [manifest-privat] [--rilis=manifest]` | Hanya membaca. Server (CHECK sesi + default global, InnoDB, binlog bukan STATEMENT, **jam DB = jam aplikasi** ≤ 5 menit), tabel prasyarat, 029 tercatat, role dasar, 030–031 berurutan dan **tidak sebagian**, tidak ada > 031, ENUM V2 utuh, tipe induk kunci asing, pembimbing tanpa akun, orang tua yang dapat mengajukan, sakelar notifikasi; `--rilis` mewajibkan daftar lengkap + setiap SHA. Menulis manifest privat 0600: jumlah baris dan **hash isi seluruh tabel lama**, irisan V2, isi V7 bila terpasang. |
| `bin/v7_verify.php [--irisan-v2] [manifest-privat]` | Hanya membaca. (1) Verifier Fase 2 dan 3 sebagai proses terpisah. (2) Invarians rilis yang ditulis ulang terpisah dari kode aplikasi: transisi sah per peristiwa, **rantai riwayat** setiap pengajuan wali dari pembuatan sampai status kini, pelaku/alasan wajib, **jumlah riwayat = jumlah audit** per pengajuan dan jenis, audit V7 berpelaku dan menunjuk pengajuan wali, keputusan tunggal (+ indeks unik) dan tidak ada keputusan untuk pengajuan belum diputus/dibatalkan/ditolak pembimbing, antrean murobi, murobi tidak dituju sebelum penerusan (data dan urutan riwayat), tanpa benturan tanggal lintas jalur, **nol referensi yatim** pada 15 relasi, laporan V7 = hitungan sumber. (3) Dengan manifest: jumlah dan isi seluruh tabel lama, irisan V2, isi V7. |
| `bin/v7_backup_restore_drill.php --metode=writer\|dump` | preflight → backup (BackupWriter atau `mariadb-dump --single-transaction --routines --triggers`) → restore lewat klien → verifier + manifest → hash isi 17 tabel = pulihan → ringkasan laporan sama → rollback 031 berisi data ditolak (preflight + penjaga SQL). Hanya `test_*_test`, `V7_DRILL=1`, menolak produksi, credential lewat berkas opsi 0600. |
| `bin/_v7_rilis.php` | Pembantu bersama: daftar migrasi, baseline, hash multiset isi (tidak bergantung urutan baris), snapshot preservasi, peta peristiwa → audit, transisi sah. |
| `runbook-rilis-pemulihan.md` | Panduan cPanel: prasyarat (V7 mewajibkan V6, penugasan/akun, tanpa sakelar, F5-1, T-1, jam DB), backup/restore point, urutan rilis, worker/cron, rollback aman, pemantauan. |
| `checklist-smoke.md` | 22 langkah smoke/rilis; **seluruh kolom produksi "Menunggu pelaksanaan"**. |
| `perintah-uji.sh`, `perintah-regresi.sh` | Pengulangan uji Fase 5 dan regresi V1–V7. |

### 2. Uji end-to-end dan keamanan

- `tests/v7_phase5_e2e.php` (layanan + proses konkuren): E-1 satu pembimbing/satu murobi dengan perbaikan, keputusan, koreksi; E-2 nol pembimbing (penetapan bukan-sah 422, penugasan sah dibuat lalu ditetapkan); E-3 dua pembimbing; E-4 pembimbing tanpa akun; E-5 nol murobi → admin pengganti beralasan; E-6 dua murobi → penetapan murobi B; E-7 dua wali, wali asing, ID tebakan; E-8 pencabutan relasi wali, penugasan pembimbing (lalu penetapan ulang), dan seluruh penugasan murobi pada sesi lama; E-9 **irisan V2 tetap** sesudah seluruh pemakaian V7; E-10 benturan tanggal lintas jalur dan pelepasan rentang; E-11 retry berkunci sama untuk buat/tinjau/perbaiki/teruskan/putus/batal; E-12 konkurensi proses: enam kiriman ganda, tiga kiriman lintas jalur, **batal vs putus empat ronde**, batal vs teruskan, klik ganda murobi + admin pengganti; E-13 jalur pengurus V2; E-14 notifikasi (kesepuluh jenis peristiwa terjadi); E-15 verifier rilis.
- `tests/v7_phase5_http.php` (server lokal, sesi + CSRF dan bearer): H-A jalur wali penuh di website termasuk keputusan di halaman V2 dan tautan notifikasi; H-B jalur wali penuh lewat API; H-C jalur pengurus V2 web/API; H-D **matriks 9 akun × 2 pengajuan** pada rincian web/API, laporan, ekspor, mutasi, tautan notifikasi, dan pencabutan relasi pada sesi/token lama; H-E laporan/cetak/CSV V2 dan laporan/ekspor V7; H-F log server.
- `tests/v7_phase5_rilis.php`: R-1 titik awal V6 + perizinan warisan V1, manifest rilis (diubah/terpotong ditolak); R-2 zona waktu; R-3 `migrate up` 030–031 + verifier/manifest; R-4 rilis sebagian ditolak; R-5 turun tanpa data = snapshot pra-migrasi persis, naik lagi; R-6 penjaga rollback dengan data; R-7 latihan writer + dump; R-8 **sembilan kontrol pengrusakan** (verifier harus gagal lalu lulus lagi sesudah dipulihkan).
- `tests/browser/uji-v7-fase5.mjs`: 375/768/1280 jalur wali (klik ganda → satu pengajuan, diperiksa DB) dan pengurus V2 lewat UI, ID tebakan, delapan halaman laporan/cetak lama dan V7 tidak melebar, tanpa JavaScript (batal beralasan, laporan).
- `tests/v7_phase5_static.php`: batas berkas, alat rilis hanya-baca, penjaga latihan, verifier, manifest = disk, dokumen, lint.

## Kriteria penerimaan Fase 5 → bukti

| Kriteria | Bukti | Status |
| --- | --- | --- |
| Jalur wali → pembimbing → murobi/admin pengganti selesai di web dan aplikasi; jalur pengurus V2 lulus regresi | E-1…E-6, H-A/H-B, browser E-A, Expo web `aplikasi-app` 48/0 dan `aplikasi-audit` 5/0; jalur pengurus E-13, H-C, browser E-B, `aplikasi-regresi` 7/0, regresi V2 | Terbukti lokal; aplikasi hanya Expo web pada Fase 5 |
| Klik ganda, retry, konkurensi → nol pengajuan/penerusan/keputusan/pembatalan/notifikasi efektif ganda | E-11, E-12 (batal vs putus 4 ronde, nol 500 pada jalur wali), H-A/H-C klik ganda, browser klik ganda (DB: 1 per lebar), verifier "satu baris per peristiwa/kanal/penerima" | Terbukti lokal; T-1 jalur pengurus V2 tetap terbuka |
| Matriks wali pengaju/lain/asing/pembimbing/murobi/admin → nol akses lintas santri/peran | H-D 9 akun × 2 pengajuan × (rincian web/API, laporan, ekspor) + mutasi enam akun + tautan notifikasi; E-7/E-8; browser E-C | Terbukti lokal |
| Setiap perubahan fixture berpelaku, berwaktu, beralasan bila wajib, berversi | Verifier: transisi + rantai + pelaku/alasan + riwayat = audit, versi = 1 + peristiwa (Fase 2), pada setiap DB uji/regresi; kontrol pengrusakan R-8 | Terbukti lokal |
| Jumlah/ID pengajuan V2/warisan, keputusan, riwayat tetap pada migrasi/rollback salinan; nol yatim baru | R-3 (isi seluruh tabel lama + irisan V2 + perizinan warisan), R-5 (snapshot turun = pra-migrasi), R-7, E-9; yatim 15 relasi | Terbukti lokal (MariaDB) |
| Backup/restore, migrasi, verifier, rollback, smoke cPanel, uji perangkat sesuai pelaksanaan nyata | R-1…R-7; runbook; checklist **Menunggu pelaksanaan** | Lokal tersedia; **cPanel, MySQL, fisik, Push nyata, UAT belum diuji** |
| Auditor menyatakan seluruh kriteria wajib V7 terpenuhi | — | **Menunggu audit Codex** |

## Hasil pengujian Fase 5 (lokal, kode final)

Satu jalan bersih `perintah-uji.sh` (DB dibangun ulang per suite):

| Rangkaian | Hasil | Berkas |
| --- | --- | --- |
| Statis `tests/v7_phase5_static.php` (batas berkas, alat hanya-baca, penjaga latihan, verifier, manifest = disk, dokumen, lint 9 PHP) | **56/0** (jalan pertama di `perintah-uji.sh` 55/1 karena README belum ditulis; diulang sesudah dokumen lengkap) | `bukti/statis.txt` |
| E2E layanan + konkurensi `tests/v7_phase5_e2e.php` | **81/0** | `bukti/e2e.txt` |
| HTTP web + API `tests/v7_phase5_http.php` | **40/0**, log server 0 galat PHP, 0 alasan/token | `bukti/http.txt` |
| Kesiapan rilis `tests/v7_phase5_rilis.php` | **36/0** (latihan writer 11/0, dump 12/0) | `bukti/rilis.txt` |
| Browser 375/768/1280 + tanpa JS `tests/browser/uji-v7-fase5.mjs` | **95/0** + klik ganda 3/0 (DB), 0 galat PHP | `bukti/browser.txt`, `tangkapan/` |
| `bin/v7_verify.php` sesudah E2E, HTTP, browser, dan tiga suite aplikasi | **6 × 21/0** | `bukti/verifier-*.txt` |
| Aplikasi Expo **web** 375 px, suite Fase 4 pada server Fase 5: lintas peran / regresi pengurus V2 / probe audit F-1–F-2 | **48/0 · 7/0 · 5/0**; bundel 0 host produksi; 0 galat PHP | `bukti/aplikasi-*.txt` |
| Mobile TypeScript / lint / unit (salinan `git archive 7478f7c`) | exit 0 / exit 0 / **42/0** | `bukti/mobile-tsc-lint-unit.txt` |

```bash
MOBILE_COPY=/private/tmp/<salinan git archive alhasanApps tanpa .env*> bash docs/phase-v7-5/perintah-uji.sh
bash docs/phase-v7-5/perintah-regresi.sh
```

## Regresi V1–V7

Dijalankan **berurutan** lewat `perintah-regresi.sh` pada DB salinan yang dibangun ulang (ringkasan `bukti/regresi/ringkasan.txt`, berkas per suite di `bukti/regresi/`). Sesudah setiap suite yang membebani DB perizinan, **verifier rilis `bin/v7_verify.php` dijalankan pada DB yang sama**. Hitungan dibandingkan dengan regresi audit Fase 4 (`docs/phase-v7-4/audit-codex/regresi/ringkasan.txt`).

| Rangkaian | Hasil | Catatan |
| --- | --- | --- |
| V7 Fase 4 HTTP | 54/0 | identik audit Fase 4 |
| V7 Fase 3 integrasi / probe audit / probe notifikasi / probe privasi / HTTP / probe HTTP / browser | 112/0 · 18/0 · 49/0 · 3/0 · 43/0 · 33/0 · 53/0 | identik audit Fase 3–4 |
| V7 Fase 2 integrasi / HTTP / probe audit / migrasi / browser | 119/0 · 61/0 · 34/0 · 27/0 · 33/0 | identik |
| V7 Fase 1 integrasi | 73/2 | Oracle historis; identik kontrol sumber baseline `96dc827` pada audit Fase 3 |
| V7 Fase 1 probe audit / HTTP / browser | 13/0 · 71/0 · 39/0 | identik |
| **`bin/v7_verify.php` sesudah Fase 4 HTTP, Fase 3 (integrasi+probe, HTTP, browser), Fase 2 (integrasi, probe), dan enam DB regresi V2** | **12 × 21/0** | rantai riwayat, audit, keputusan tunggal, yatim, laporan lulus pada DB berbeban konkurensi/HTTP lama |
| Fondasi penugasan | 161/0 | identik |
| V2 integrasi Fase 1/2/4/5, konkurensi | 39/0 · 94/0 · 124/0 · 145/0 · 20/0 | identik |
| V2 API Fase 3 / 4 / 5 | 95/21 · 91/1 · 150/0 | Fase 3/4: kegagalan historis identik sejak audit Fase 1 |
| V2 web Fase 2/4/5 | 36/0 · 46/0 · 79/0 | identik |
| Worker V5 Fase 3; V6 Fase 4 HTTP; V6 Fase 5 integrasi / HTTP; `bin/v6_verify.php` | 82/0; 63/0; 71/0 · 42/0; 36/0 | identik |
| HTTP redesain Tahap 2 / 3 / 4 / 5 / 7 | 185/6 · 166/1 · 132/0 · 106/0 · 122/0 | Tahap 2: oracle menu BB–BE; Tahap 3: "AG belum dipasang"; identik V6 F5/V7 F3 |
| Rollback 031 → 024 kosong (preflight + penjaga SQL) lalu HTTP Tahap 6 | 44/0 · 132/0 | identik audit Fase 2 |
| Browser kerangka sembilan akun | 91/0 | identik |
| Seluruh **44 suite statis** | 18 exit 0 · 24 exit 1 · 2 exit 77 | Fase 4: 18/23/2 dari 43. `v7_phase5_static` baru 56/0. Satu oracle historis baru: `v7_phase4_static` 30/1 — "berkas web berubah hanya dalam daftar Fase 4" (Fase 5 menambah alat rilis, uji, dan bukti). Kegagalan lain identik baris demi baris dengan Fase 4. Oracle tidak diubah atau dilonggarkan. |

**Tidak dijalankan ulang:** keamanan PSB (Apache + php-fpm), suite V3/V4 yang mengunci nama DB tetap, runner V4 Fase 5 berenv privat, browser V5/V6, Android/iOS native.

## Temuan selama Fase 5

| # | Temuan | Tindakan |
| --- | --- | --- |
| F5-1 | **Murobi tujuan tetap dapat memutus sesudah penugasan atas kamar santri dicabut**, selama ia masih memiliki penugasan murobi aktif lain. Aturan V2 `IzinRouter::isEligibleMurobi` hanya memeriksa penugasan murobi aktif mana pun; routing menetapkan murobi tujuan saat diteruskan. Reproduksi: E-8 `[temuan]`. Pencabutan **seluruh** penugasan murobi ditolak dengan benar. | Tidak diubah (perilaku V2, juga berlaku untuk jalur pengurus). Runbook: admin menetapkan ulang murobi bila penugasan dipindah. Perlu keputusan Human Developer apakah murobi wajib masih mencakup santri saat memutus. |
| F5-2 | Model transisi verifier awal tidak memuat keputusan admin pengganti dari "Perlu Penetapan Admin". | Ditemukan oleh verifier sendiri pada E2E; `V7_TRANSISI` dilengkapi. Bukan cacat aplikasi. |
| F5-3 | `V7F1_TANPA_030=1 tests/v7_phase1_db.sh` hanya melepas satu migrasi (031) sejak 031 ada, sehingga "salinan tanpa 030" sebenarnya masih memuat 030. | Uji Fase 1 lama tidak diubah. `tests/v7_phase5_db.sh V7F5_TANPA_V7=1` melepas 031 lalu 030 dan dipakai uji rilis. |
| T-1 | (terbuka sejak Fase 1) kiriman bersamaan berkunci sama pada pembuatan pengajuan **pengurus** V2 → sebagian 500, tanpa baris ganda. | Tidak diubah; menunggu keputusan. Jalur wali V7 nol 500 (E-12). |

## Penafsiran yang perlu ditinjau auditor/Human Developer

- **P5-1 Preservasi.** "Data V1–V6 tetap" dibuktikan sebagai jumlah baris **dan hash isi seluruh kolom** setiap tabel lama (identitas, PSB, V1–V6, perizinan V2) pada migrasi, rollback, dan restore tanpa lalu lintas. Saat V7 dipakai, yang dibuktikan tetap adalah **irisan V2** (kolom V2 baris Pengurus, keputusan/riwayat/idempotensi miliknya, perizinan warisan, identitas santri–wali); tabel bersama seperti `izin_riwayat_status` dan `notifikasi_outbox` memang bertambah oleh V7.
- **P5-2 Data Pengurus diputus tanpa keputusan.** Fixture V2 Fase 5 memuat 36 baris Pengurus Disetujui/Ditolak tanpa `izin_keputusan` (data sintetis lama). Verifier mewajibkan tepat satu keputusan hanya untuk baris Wali dan mencetak jumlah baris Pengurus sebagai `[info]`, karena data sebelum V7 di produksi dapat serupa dan V7 tidak mengubahnya.
- **P5-3 Aplikasi.** Fase 5 tidak mengubah kode mobile. Bukti aplikasi Fase 5 adalah Expo web atas salinan HEAD yang sama dengan yang diaudit; Android emulator dan iOS simulator native **tidak** dijalankan ulang (bukti native tetap dari audit Fase 4).
- **P5-4 Verifier berkala.** Verifier tanpa manifest aman dijalankan berkala; perbandingan isi tabel lama hanya bermakna tanpa lalu lintas. `--irisan-v2` untuk membuktikan V7 tidak mengubah V2 (berubah sah bila jalur pengurus dipakai).
- **P5-5 Laporan/cetak lama.** Diuji sebagai halaman/CSV/API V2 dan laporan V7 yang terbuka tanpa galat, tidak melebar, dan total V7 = sumber; isi angka laporan V2 dicakup suite regresi V2 Fase 5 dan Tahap 4.

## Batas yang belum diuji / terbuka

- **MySQL, cPanel, MariaDB hosting, produksi:** tidak ada deploy, migrasi, smoke, cron, pemeriksaan zona waktu/CHECK/binlog/hak routine di hosting. Seluruh baris produksi checklist **Menunggu pelaksanaan**.
- **Aplikasi native:** perangkat fisik, Push nyata (Expo/FCM/APNs), ketukan/cold start Push, Android cold start build rilis, iOS Debug **belum diuji**; emulator/simulator tidak diulang pada Fase 5.
- **UAT** orang tua, pembimbing, murobi, admin belum dijalankan.
- **Ketergantungan V6:** V7 mewajibkan 029; gerbang akhir/produksi V6 tetap terbuka.
- Konkurensi diuji 6 proses (buat berkunci sama), 3 proses lintas jalur, 2 proses × 4 ronde (batal vs putus), 2 (batal vs teruskan), 3 (keputusan ganda), bukan seluruh interleaving. Uji beban data besar belum dijalankan.
- Enqueue notifikasi yang gagal tetap tanpa pemulihan otomatis (batas Fase 3).
- Suite V3/V4 yang mengunci nama DB tetap, runner V4 Fase 5 berenv privat, dan keamanan PSB Apache tidak dijalankan ulang (Fase 5 tidak mengubah kode).

## Handoff

Commit web dan mobile pada branch `claude/prd-v7-fase-5` sudah di-push; hash dibaca dari Git. Berhenti untuk audit independen Codex atas Fase 5 dan gerbang akhir V7. Tidak merge, deploy, migrasi produksi, rilis aplikasi, atau memulai V8.
