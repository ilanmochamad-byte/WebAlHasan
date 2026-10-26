# PRD V6 Fase 5 — Laporan, Audit, dan Kesiapan Rilis Bersama

**Status (26 Oktober 2026):** diimplementasikan Claude Code, **menunggu audit independen Codex**. Belum merge, belum deploy, belum migrasi produksi, tidak ada rilis aplikasi. Seluruh bukti berasal dari salinan DB lokal berfixture sintetis, Chromium, dan Expo **web**. Bukti tidak mencakup hosting cPanel, MySQL, perangkat fisik, build native baru, maupun Push nyata, sehingga **produksi tidak dinyatakan lulus dan V6 tidak dinyatakan siap rilis**. Keputusan rilis bersama tagihan dan tabungan tetap milik Human Developer sesudah audit.

| Butir | Nilai |
| --- | --- |
| Workstream | PRD V6 Fase 1–5 keuangan santri/BSA — implementator Claude Code, auditor Codex (`AGENTS.md`) |
| Instruksi | Human Developer 26 Oktober 2026: implementasikan hanya Fase 5, lalu berhenti untuk audit Codex |
| Branch web | `claude/prd-v6-fase-5` dari audit ulang Fase 4 `75cb144685d02d10df563f67eb1e23a67eec030b`. Diverifikasi: `origin/main` `11b0459` (merge PR #91) memuatnya dan `git diff 75cb144 origin/main` kosong. Tanpa worktree. |
| Branch mobile | `~/alhasanApps` `claude/prd-v6-fase-5` dari `16bcdb7a7dc475f785c328cfdae90ad6b3072e6b` (audit ulang Fase 4). `origin/main` `f1141e0` (merge PR #18), `git diff 16bcdb7 origin/main` kosong. |
| Commit implementasi | web `8108012` (branch `claude/prd-v6-fase-5`), mobile `18fe095` (branch `claude/prd-v6-fase-5`); keduanya sudah di-push. |
| Migrasi | **Tidak ada migrasi baru** (029 tetap terakhir); berkas rollback tidak diubah (penjaga SQL 027–029 sudah ada sejak Fase 1–3). |
| Keputusan baru | **R-10 (26 Oktober 2026): santri nonaktif bersaldo/bertunggakan hanya dilaporkan** (laporan admin + `[info]` verifier); tanpa jalur mutasi baru. Dicatat di `PRD-V6.md` §5.1 dan `AGENTS.md`. |
| Lingkungan uji | PHP 8.4.14, MariaDB 12.3.2 lokal, Apache 2.4 + php-fpm (regresi PSB), Node v26.7.0, Chromium Playwright, Expo SDK 57 web. DB `test_*_test` berfixture sintetis. **MySQL, cPanel, perangkat fisik, Push nyata — belum diuji.** |

## Yang dikerjakan

### 1. Laporan per periode dan per santri (persyaratan 1–2)

`App\Bsa\LaporanService` + `LaporanRepository` (hanya SELECT, tanpa tabel baru) dipakai oleh empat jalur, sehingga angka dan haknya identik:

- halaman `portal/bsa_laporan.php`;
- ekspor `portal/bsa_laporan_ekspor.php`;
- API `GET /api/v1/v6/bsa/laporan` dan `/laporan/ekspor` (`docs/api-v1.md`);
- layar aplikasi `src/app/keuangan/laporan.tsx`.

- **Hak** dihitung ulang setiap permintaan lewat `BsaService::wajibBaca` dan syarat `TagihanRepository::syaratHak` yang sama dengan daftar Fase 2–3:
  - petugas: santri aktif dalam cakupan unit;
  - admin dan admin+petugas: seluruh santri, hanya baca, ditambah daftar santri nonaktif bersaldo (R-10);
  - orang tua: anak dengan relasi aktif;
  - lainnya 403; santri atau unit di luar hak 404; periode tidak sah, masa depan, atau > 12 bulan 422.
- **Dua sumbu waktu**, sama dengan rekonsiliasi harian Fase 3:
  - tagihan menurut **periode tagihan**: terbit, dibatalkan, terbayar per sumber Tunai/Transfer/Tabungan, sisa, lunas, lewat jatuh tempo, transfer menunggu;
  - uang menurut **waktu transaksi sah**: saldo awal, setoran, penarikan, belanja, bayar tagihan dari tabungan, koreksi masuk/keluar, pengembalian, saldo akhir.
- **Kas vs internal:** `penerimaan_kas_baru` = setoran + pembayaran tagihan **tunai/transfer**. Pembayaran tagihan dari tabungan dan pengembaliannya tampil sebagai perpindahan internal dan tidak pernah menjadi kas (rumus sama dengan rekonsiliasi harian; diuji setara di I-3).
- **Pembatalan dan koreksi:** ringkasan dan daftar ≤ 200 terbaru dengan aktor, waktu, alasan, dan asal. Asal berupa nomor kuitansi tabungan/pembayaran, "Setoran transfer #id", atau "Tagihan #id (periode)".
- **Per bulan dan per santri.** Per santri memuat tagihan/terbayar/sisa periode, seluruh tunggakan, arus tabungan menurut **jenis**, saldo awal → akhir, dan saldo akun. Ada dua pemeriksaan: `selisih_saldo` = awal + setoran − penarikan − belanja − bayar tabungan + koreksi masuk − koreksi keluar + pengembalian − akhir, dan `selisih_akun` = akun − ledger. Keduanya wajib Rp0.
- **Pita "Rekonsiliasi periode: selisih Rp0"** memuat terbayar − pembayaran sah, saldo awal + mutasi − akhir, debit tabungan − pembayaran dari tabungan, pengembalian − pembatalan, dan jumlah santri dengan akun ≠ ledger. Pita ini hanya untuk internal.
- **Satu snapshot:** seluruh kueri satu laporan berada dalam `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY`. Kontrol I-9 tanpa snapshot pada beban mutasi yang sama menghasilkan **16–24 dari 45** pembacaan tidak konsisten (dua jalan); dengan snapshot 0 dari 45.
- **Orang tua** tidak menerima kas, perpindahan internal, daftar koreksi beralasan, transfer menunggu, penolakan transfer, atau pemeriksaan selisih internal (`null`). Baris per anak tanpa `selisih_*`.
- **Ekspor CSV** (per santri / rincian transaksi / koreksi), dengan hak yang sama:
  - UTF-8 BOM, lampiran bernama periode, `no-store`, `nosniff`;
  - sel teks berawalan formula diberi kutip;
  - rincian transaksi tanpa referensi transfer, catatan, atau alasan; kolom "Kas baru" dan "Perpindahan internal";
  - orang tua tanpa ekspor koreksi (403); lebih dari 10.000 baris ditolak 422 (tidak dipotong diam-diam);
  - setiap ekspor dicatat `audit_logs` `bsa.laporan.ekspor` (jenis, periode, sumber, kanal; tanpa nominal).
- Navigasi bagian keuangan mendapat **"Laporan"** untuk internal dan orang tua (bila 028–029 terpasang). Peta menu global tidak berubah.
- **Aplikasi:** layar baca dengan pilihan Bulan ini/Bulan lalu/3 bulan/Tahun ini, di navigator keuangan yang dilindungi tangkapan layar. Aplikasi tidak mengunduh CSV. Rincian di `docs/phase-v6-5/README.md` repo mobile.

### 2. Kesiapan rilis bersama (persyaratan 4)

| Berkas | Isi |
| --- | --- |
| `manifest-migrasi.json` | SHA-256 29 migrasi 001–029, 6 rollback 024–029, `composer.lock`, berkas mobile (hash **HEAD Git**, bukan salinan kerja yang memuat paket pekerjaan lain). Dibuat `php bin/v6_manifest.php --mobile=…`. |
| `bin/v6_preflight.php [manifest-privat] [--rilis=manifest]` | Hanya membaca. Memeriksa: CHECK/kolom terbangkit, binlog, InnoDB, **jam DB = jam aplikasi** (≤ 5 menit), prasyarat, status 024–026 (ikut terpasang bila belum), 027–029 berurutan dan **tidak sebagian**, tidak ada > 029, daftar manifest lengkap + setiap SHA. Mencetak jumlah penugasan bendahara lama (K-1) dan santri nonaktif (R-10). Menulis manifest privat 0600: jumlah baris seluruh tabel lama, sidik jari identitas, dan isi uang V6 bila tabel sudah ada. |
| `bin/v6_verify.php [manifest-privat]` | Hanya membaca. Verifier Fase 3→2→1 berantai, lalu: (a) saldo akun per santri = setoran − penarikan − belanja − bayar tagihan ± pembalik **menurut jenis**, arah pembalik sesuai asal; (b) tagihan total − pembayaran sah = sisa, tanpa lebih bayar, satu tagihan per santri–periode, kuitansi unik; (c) kas baru = setoran + pembayaran tunai/transfer, pembayaran tabungan terpisah, debit = pembayaran tabungan; (d) laporan seluruh waktu = sumber; (e) setiap koreksi/pembatalan/penolakan/revisi beraktor, beralasan, berasal, ber-audit; (f) **setiap baris uang efektif punya audit**; (g) notifikasi V6 tanpa ganda/WhatsApp, teks katalog, data penunjuk; `[info]` R-10 dan transfer menunggu; (h) dengan manifest: tabel lama dan sidik jari identitas/isi uang. |
| `bin/v6_backup_restore_drill.php --metode=writer\|dump` | Latihan: preflight → backup (BackupWriter atau `mariadb-dump --single-transaction --routines --triggers`) → restore lewat klien → verifier + manifest → hash isi 24 tabel = pulihan → angka laporan sama → rollback 029 berisi data ditolak (preflight + penjaga SQL). Hanya `test_*_test`, `V6_DRILL=1`, menolak produksi. |
| `bin/_v6_rilis.php` | Pembantu bersama (daftar migrasi, baseline, sidik jari beruntun, hitungan tabel lama). |
| `runbook-rilis-pemulihan.md` | Panduan cPanel. Memuat prasyarat (V5 ikut terbawa, K-1, menu langsung tampil, R-10, jam DB), backup/restore point, urutan rilis, worker/cron, rollback aman, dan pemantauan. |
| `checklist-smoke.md` | 22 langkah smoke/rilis bersama; **seluruh kolom produksi "Menunggu pelaksanaan"**. |
| `perintah-uji.sh`, `perintah-regresi.sh` | Pengulangan uji Fase 5 dan regresi V1–V6/PSB. |

## Kriteria penerimaan Fase 5 → bukti

| Kriteria | Bukti | Status |
| --- | --- | --- |
| Saldo akhir per santri = setoran sah − penarikan/belanja − bayar tagihan ± pembalik; selisih verifier Rp0 | `bukti/integrasi.txt` I-2: oracle tangan dua bulan (SMP 110.000 + 55.000 − 20.000 − 15.000 − 60.000 + 15.000 − 5.000 + 10.000 = 90.000 = akun), selisih Rp0 laporan A, B, A..B. Verifier §2a pada setiap DB uji dan regresi. Kontrol I-10: saldo akun +Rp1 terdeteksi laporan dan verifier. | Terbukti lokal |
| Tagihan: total − pembayaran sah = sisa; tanpa lebih bayar atau tagihan ganda | I-2 (tagihan B 210.000, terbayar 140.000 = tunai 90.000 + tabungan 50.000, transfer menunggu 30.000 tidak dihitung); verifier §2b; kontrol I-10 terbayar −Rp1 terdeteksi | Terbukti lokal |
| Rekonsiliasi kas/transfer vs perpindahan tabungan selisih Rp0; pembayaran internal bukan kas baru | I-3: kas B tunai masuk 212.000/keluar 60.000, kas baru 247.000; internal 60.000 = 60.000; bayar Rp5.000 dari tabungan → kas baru tetap. Laporan bulan = rekonsiliasi harian. CSV: Σ "Kas baru" = laporan (I-7). Verifier §2c. | Terbukti lokal |
| Seluruh pembatalan/koreksi beraktor, berwaktu, beralasan, berasal; nol mutasi efektif tanpa jejak | I-4 (8 koreksi/pembatalan lengkap); verifier §2e–2f; kontrol I-10: satu audit koreksi dihapus → verifier gagal | Terbukti lokal |
| Nol kebocoran/mutasi tidak sah lintas peran dan santri pada web/API/aplikasi; regresi PSB dan V1–V5 jujur terhadap baseline | I-1, I-6, I-7, I-8; `bukti/http.txt` H-A…H-F; `bukti/browser.txt`; `bukti/aplikasi-expo-web.txt` M-A…M-F; laporan tidak menulis apa pun (I-11). Regresi di bawah. | Terbukti lokal; aplikasi hanya Expo web |
| Backup/restore ke salinan, migrasi, verifier, rollback, smoke cPanel dengan bukti terpisah; lokal tidak berlabel lulus produksi | `bukti/rilis.txt` S-1…S-6; runbook; checklist (produksi: **Menunggu pelaksanaan**) | Lokal tersedia; **cPanel belum dijalankan** |
| Auditor menyatakan tagihan **dan** tabungan memenuhi kriteria wajib | — | **Menunggu audit Codex** |

## Hasil pengujian Fase 5 (lokal, kode final)

Seluruh baris dari satu jalan bersih `perintah-uji.sh` (DB dibangun ulang tiap suite).

| Rangkaian | Hasil | Berkas |
| --- | --- | --- |
| Statis `tests/v6_phase5_static.php` (batas berkas terhadap baseline, hanya-baca, hak, ekspor, alat rilis, aplikasi, lint 17 PHP) | 74/0 | `bukti/statis.txt` |
| Integrasi `tests/v6_phase5_integration.php`: oracle tangan dua bulan, hak, ekspor, pencabutan, konkurensi/idempotensi/snapshot, kontrol pengrusakan, verifier | 71/0; kontrol tanpa snapshot **16/45** tidak konsisten (jalan pengembangan: 24/45), dengan snapshot 0/45 | `bukti/integrasi.txt` |
| HTTP web + API `tests/v6_phase5_http.php` | 42/0, log server 0 galat PHP, 0 referensi/token | `bukti/http.txt` |
| Kesiapan rilis `tests/v6_phase5_rilis.php`: naik–turun–naik 027–029, manifest, sebagian, zona waktu, penjaga rollback 027–029 berisi data, latihan writer 11/0 + dump 12/0 | 29/0 | `bukti/rilis.txt` |
| Browser web 375/768/1280 + tanpa JS `tests/browser/uji-v6-fase5.mjs` (4 akun × 3 lebar, saringan, unduhan CSV) | 27/0, log server 0 galat PHP | `bukti/browser.txt`, `tangkapan/laporan-*.png` (diperiksa visual) |
| Aplikasi Expo **web** 375 px + API/DB nyata `tests/browser/uji-v6-fase5-app.mjs` | 20/0; bundel 0 host produksi; 0 permintaan keluar 127.0.0.1; 0 POST dari layar laporan | `bukti/aplikasi-expo-web.txt`, `tangkapan/app-laporan-*.png` |
| Mobile TypeScript / lint / unit | exit 0 / exit 0 (0 peringatan) / 37/0 | `bukti/mobile-tsc-lint-unit.txt` |

Cara mengulang (berurutan, tidak bersamaan dengan regresi):

```bash
MOBILE_COPY=/private/tmp/<salinan alhasanApps tanpa .env*> bash docs/phase-v6-5/perintah-uji.sh
KPSB_DIR=/private/tmp/<folder privat> bash docs/phase-v6-5/perintah-regresi.sh
```

Salinan mobile dibuat dengan `git ls-files -co --exclude-standard | grep -v '^\.env' | rsync -a --files-from=- ./ SALINAN/` ditambah `cp -c -R node_modules`. Berkas `.env.local` checkout asli menunjuk API produksi.

## Regresi V1–V6/PSB

Dijalankan **berurutan** lewat `perintah-regresi.sh` pada DB salinan yang dibangun ulang (ringkasan `bukti/regresi/ringkasan.txt`, berkas per suite di `bukti/regresi/`). Sesudah tiap suite V6 berbasis DB, **verifier rilis `bin/v6_verify.php` dijalankan pada DB yang sama** (invarians sesudah beban konkurensi/transaksi gagal/HTTP Fase 1–4). Seluruh baris dibandingkan dengan regresi Fase 4 (`docs/phase-v6-4/bukti/regresi/ringkasan.txt`).

| Rangkaian | Hasil | Catatan |
| --- | --- | --- |
| V6 Fase 1 integrasi / probe audit Codex / browser | 120/0 · 37/0 · 59/0 | identik dengan Fase 4 |
| V6 Fase 1 HTTP | 96/8 | Oracle historis: transfer tanpa centang kini Menunggu (sejak Fase 3); identik Fase 3–4 |
| V6 Fase 2 integrasi / HTTP / probe audit / probe era 027 / browser | 179/0 · 110/0 · 30/0 · 10/0 · 76/0 | probe audit Fase 4 tercatat 22/8, kali ini 30/0 (sama dengan bukti audit Fase 2) |
| V6 Fase 1 / Fase 2 migrasi | 19/10 · 22/14 | Oracle historis "027/028 terakhir"; identik Fase 3–4 |
| V6 Fase 3 integrasi / HTTP / probe audit / probe tengah malam / migrasi / browser | 137/0 · 66/0 · 27/0 · 9/0 · 35/0 · 89/0 | identik |
| V6 Fase 4 HTTP (paritas aplikasi, notifikasi, privasi wali) | 63/0 | identik |
| **`bin/v6_verify.php` sesudah suite Fase 1 integrasi, Fase 2 integrasi, Fase 3 integrasi, Fase 3 HTTP, Fase 4 HTTP** | **5 × 36/0** | invarians saldo/tagihan/rekonsiliasi/jejak/audit/notifikasi lulus pada DB berisi beban konkurensi dan kegagalan transaksi |
| Keamanan PSB (Apache + php-fpm, `.htaccess` aktif) HTTP / browser | 97/0 · 42/0 | identik |
| Fondasi penugasan; V2 Fase 4/5; V5 Fase 5/3 | 161/0; 124/0 · 143/0; 95/0 · 82/0 | identik |
| HTTP Tahap 2 / 3 / 5 / 7 | 185/6 · 166/1 · 106/0 · 122/0 | Tahap 2: oracle menu BB/BC/BD/BE; Tahap 3: "AG belum dipasang"; identik Fase 4 |
| Rollback 029 → 024 kosong (preflight + penjaga SQL) lalu HTTP Tahap 6 | 33/0 · 132/0 | identik |
| Browser kerangka sembilan akun | 91/0 | identik |
| Seluruh 39 suite statis | 19 exit 0 · 18 exit 1 · 2 exit 77 | Fase 4: 19/17/2 dari 38. `v6_phase5_static` baru 74/0. Satu oracle historis baru: `v6_phase4_static` 20/2 — "berkas berubah hanya dalam daftar Fase 4" dan "halaman BSA/rute API tidak berubah" terhadap baseline Fase 3 (Fase 5 menambah `portal/bsa_laporan*.php`, rute laporan, butir navigasi). Kegagalan lain identik baris demi baris dengan Fase 4 (mis. `v2_phase5_static` 3 gagal: paket mobile salinan kerja milik pekerjaan lain). Oracle tidak diubah atau dilonggarkan. |

**Tidak dijalankan ulang:** suite V3/V4 yang mengunci nama DB tetap, runner V4 Fase 5 berenv privat, browser Fase 4/5 V5, Android/iOS native, uji aplikasi Fase 4 (Expo web).

## Temuan selama Fase 5

| # | Temuan | Tindakan |
| --- | --- | --- |
| F5-1 | **Batas hari/bulan bergantung zona waktu DB = aplikasi.** Rekonsiliasi Fase 3 dan laporan membandingkan `created_at` (jam DB) dengan tanggal PHP (`APP_TIMEZONE`). Koneksi tidak menyetel zona waktu sesi, sehingga hosting dengan DB UTC menggeser transaksi dini hari ke hari/bulan lain. | Tidak mengubah sesi DB global (berisiko bagi V1–V5). Preflight kini **gagal** bila selisih jam > 5 menit; S-4 membuktikannya dengan `APP_TIMEZONE` berbeda. Zona waktu hosting wajib diperiksa saat rilis. |
| F5-2 | **Laporan multi-kueri tanpa snapshot tidak konsisten di bawah beban** (kontrol I-9: 16–24 dari 45). | Seluruh laporan dan ekspor dibaca dalam satu snapshot konsisten (0/45). |
| F5-3 | `bin/migrate.php up` memasang 024–026 (V5) bila belum ada di produksi, sebelum 027. Akibatnya rilis V6 dapat membawa V5 tanpa disengaja. | Preflight mencetak status 024–026; runbook §1.2 mewajibkan keputusan Human Developer. Kode tidak diubah. |

## Penafsiran yang perlu ditinjau auditor/Human Developer

- **U-1 Sumbu waktu.** Tagihan menurut periode tagihan; uang menurut waktu transaksi sah, dengan pembayaran pada waktu sah dan pembatalan pada waktu batal, sama dengan rekonsiliasi harian. Pembayaran yang kemudian dibatalkan tetap dihitung masuk pada waktunya, dan pembatalannya keluar pada waktunya.
- **U-2 Kas baru** = setoran + pembayaran tagihan tunai/transfer sah dalam jendela. Pembayaran yang dibatalkan dikurangkan sebagai kas keluar, bukan dihapus dari penerimaan.
- **U-3 Per santri internal** hanya menampilkan santri yang punya data BSA. Santri yang hanya memiliki setoran transfer ditolak (tanpa uang) tidak tampil. Orang tua melihat seluruh anaknya walau Rp0.
- **U-4 Ekspor.** CSV hanya dari website; aplikasi tidak menyimpan berkas keuangan. Rincian transaksi tidak memuat referensi transfer atau alasan (alasan ada di ekspor koreksi internal). Batas 10.000 baris dengan penolakan.
- **U-5 R-10** dilaporkan bagi admin (saldo dan sisa tagihan santri nonaktif/diarsipkan) dan sebagai `[info]` verifier. Petugas tidak melihat santri nonaktif (hak Fase 1 tetap).
- **U-6 Verifier "setiap baris uang punya audit"** mengandalkan `audit_logs` tidak dipangkas. Bila kebijakan retensi audit diterapkan kelak, pemeriksaan ini perlu disesuaikan.
- **U-7 Navigasi "Laporan"** ditambahkan ke segmen bagian keuangan, bukan peta menu global, sehingga tidak ada oracle menu baru.

## Batas yang belum diuji / terbuka

- **cPanel/produksi, MySQL, MariaDB hosting:** tidak ada deploy, migrasi, smoke, cron, atau pemeriksaan zona waktu/CHECK/binlog/izin routine di hosting. Seluruh baris produksi checklist **Menunggu pelaksanaan**.
- **Aplikasi:** layar laporan hanya diuji di Expo **web**; Android/iOS native (emulator, simulator, fisik) untuk layar ini belum diuji. Butir terbuka Fase 4 tetap terbuka: perangkat fisik, Push provider nyata, ketukan/cold start Push, Debug iOS, rekaman fisik.
- **R-10:** kebijakan pengembalian saldo santri keluar belum diputuskan (hanya dilaporkan).
- **Smoke data produksi:** transaksi BSA tidak dapat dihapus; Human Developer memilih smoke pada staging hasil restore atau smoke produksi berpasangan koreksi (runbook §3.5).
- **Rekonsiliasi rekening bank dan kas fisik** tetap pekerjaan BSA; sistem tidak membuktikan dana transfer benar-benar masuk.
- **UAT** petugas BSA, admin, dan orang tua belum dijalankan.
- Konkurensi diuji 6 proses (setoran kunci sama), 2 proses (belanja melampaui saldo), dan 3 mutasi + 3 pembaca laporan, bukan seluruh interleaving. Uji beban laporan pada data besar belum dijalankan.
- Suite V3/V4 yang mengunci nama DB tetap dan runner V4 Fase 5 berenv privat tidak dijalankan ulang (sama dengan fase V5/V6 sebelumnya).
