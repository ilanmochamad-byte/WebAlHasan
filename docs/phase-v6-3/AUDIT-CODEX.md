# Audit independen Codex — PRD V6 Fase 3

**Putusan 25 Oktober 2026: LULUS kriteria wajib Fase 3 setelah empat koreksi terarah.** F-1…F-4 ditutup; tidak ada temuan Fase 3 yang masih memblokir. Probe auditor 21/6 → 27/0 dan batas tengah malam 5/4 → 9/0. Ini bukan kelulusan V6 keseluruhan atau izin rilis parsial. Tidak memulai Fase 4, merge, deploy, migrasi produksi, atau mengubah mobile.

## Konteks dan baseline

- Branch tetap `claude/prd-v6-fase-3`, tanpa worktree atau perpindahan ke `main`. Handoff implementator menyatakan berhenti; proses Claude yang diperiksa berstatus tidur. Auditor tidak menjalankan atau menghentikan Claude.
- Baseline audit Fase 2 `ca62c3108aafb71b0a93211b6b5d3a88d086a994`. Seluruh commit fase: `e61c7bd66049c45e72b8224ecd5f13d11af86792` (kode, migrasi, alat, tes dan bukti) dan `20520d49af5c4040051e14ab7b8745d17b486230` (hash dan baseline). Hash ujung kedua branch fase dan `main` diverifikasi langsung dengan `git ls-remote`; `main` `271d8fc6ec6bfdd3709b1b33adff02c61f829475` memuat baseline dan diff isinya kosong.
- Dibaca: `AGENTS.md`, `PRD-V6.md`, prompt terinci, audit Fase 1–2, README/bukti implementator, seluruh perubahan aplikasi/SQL/alat/tes fase. Hasil implementator tidak disalin sebagai hasil eksekusi auditor. Bukti auditor terpisah di `audit-codex/`.
- Mobile hanya dibaca: remote `main` `f8ebba0a43493efde50a7a207933e86dc4b941d4`; checkout `claude/aset-appstore-header` `c3e096c` dengan perubahan `package.json`/`package-lock.json` milik pekerjaan lain, tidak disentuh. Direktori belum terlacak `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/` tidak disentuh/disertakan.
- PHP 8.4.14, MariaDB 12.3.2 (`innodb_snapshot_isolation=ON`), Chromium Playwright. PSB diuji melalui Apache/php-fpm dan `.htaccess`. Mutasi hanya pada DB `test_*_test` berfixture sintetis dari salinan sandbox lokal; bukan data produksi.
- Pasangan SQL 029 tetap identik dengan implementator: migrasi SHA-256 `1a77f44ff1da9c63147faad8aad2b4b06ee6fcdfe4ff75267fbf40eef9c82010`, rollback `234c68b42cb4a601455c0b98f36371f7b11c6037d1dd0463306ae8e6b30f485b`. Tidak ada migrasi baru atau perubahan SQL ≤029 oleh auditor.

## Temuan dan koreksi

### F-1 · P2 · Ringkasan setoran menunggu terpotong batas rincian — ditutup

`TabunganService::santri()` menjumlah `saldo_menunggu` dari hasil `setoranTransferSantri()` yang dibatasi 50 baris. Probe membuat **51 transfer**, nominal Rp1.000 sampai Rp1.050: jumlah DB/rekonsiliasi **Rp52.275**, tetapi rincian santri web/API memberi **Rp51.275**. Saldo efektif tetap benar (Rp0); angka dana menunggu yang keliru dapat menyesatkan pemeriksaan petugas.

Koreksi: agregat `SUM(nominal)` atas **seluruh** transfer Menunggu santri setelah otorisasi layanan, terpisah dari batas 50 baris tampilan. Wali tetap menerima daftar kosong/0. Daftar, hak, dan ledger tidak diperluas. Probe memeriksa layanan serta API dan membandingkannya dengan jumlah DB/rekonsiliasi.

### F-2 · P2 · Replay pembatalan kehilangan referensi pengembalian — ditutup

Pembatalan pertama pembayaran Tabungan membawa `transaksi_id` pembalik dan `saldo`, tetapi cabang replay hanya mengembalikan hasil Fase 2 (kuitansi/status/sisa). Kiriman ulang yang sah kehilangan dua kunci tambahan Fase 3, walaupun tidak terjadi pembukuan ganda. Kontrak retry pada `docs/api-v1.md` tidak terpenuhi.

Koreksi hanya cabang replay pembatalan Tabungan: baca ID pembalik yang sudah tersimpan dan saldo **kini**, tetap setelah pemeriksaan cakupan santri. Pembatalan tunai/transfer tetap memakai kontrak semula. Probe memeriksa layanan serta API, termasuk sesudah setoran/pembayaran berikutnya: ID pembalik tetap sama, saldo mengikuti keadaan kini, tanpa ledger/klaim/audit tambahan. Snapshot kuitansi historis tidak diubah.

### F-3 · P2 · Manifest preservasi boleh menghilangkan tabel lama — ditutup

Verifier Fase 3 menerima `legacy_counts: []` atau manifest tanpa `psb_pendaftar` dan melewati perbandingan tabel tersebut. Sepuluh sidik jari uang/identitas masih diperiksa, tetapi tidak mencakup seluruh tabel lama/PSB. Karena itu kelulusan verifier dengan manifest terpotong tidak membuktikan preservasi yang diklaim.

Koreksi: bila manifest diberikan, nama database harus cocok; daftar hitungan wajib mencakup **semua** tabel lama (selain tabel baru 029, bookkeeping migrasi dan audit), dengan hitungan integer nonnegatif. Kedua daftar diurutkan dengan aturan yang sama agar kolasi SQL tidak membuat manifest sah ditolak. Sidik jari yang sudah ada tetap wajib. Probe kontrol positif manifest lengkap lulus; manifest tanpa PSB/kosong ditolak. Migrasi dan rollback yang membawa manifest sah diuji ulang.

### Kontrol probe

`tests/v6_phase3_audit.php` adalah probe baru auditor. Kontrol lengkap **21 lulus / 6 gagal** (`audit-codex/probe-sebelum-lengkap.txt`): F-1 dua pemeriksaan layanan/API, F-2 dua pemeriksaan layanan/API, F-3 dua manifest. Probe **yang sama** setelah koreksi **27/0** (`probe-akhir.txt`).

Keluaran eksplorasi awal tetap disimpan: `probe-sebelum.txt` berhenti ketika kontrol negatif mencoba membuat pembayaran tanpa debit — CHECK DB sudah menolaknya. Kontrol negatif diperbaiki menjadi ketidaksesuaian nominal debit di fixture saja, lalu dipulihkan; `probe-kontrol.txt` 21/4 mendahului penambahan dua pemeriksaan API. Percobaan awal pengetatan manifest juga menolak manifest sah akibat urutan kolasi `users`/`user_roles`; kedua daftar kini diurutkan sama. Hasil percobaan itu bukan hasil akhir.

### F-4 · P2 · Dua sisi internal bisa masuk hari laporan berbeda — ditutup

Waktu `created_at` debit dan `diverifikasi_pada` pembayaran berasal dari statement berbeda dalam satu transaksi. Begitu operasi melintasi tengah malam, rekonsiliasi lama memakai tanggal berbeda untuk kedua sisi. Hal yang sama berlaku pada header pembatalan yang ditulis sebelum pembalik. Tidak ada uang hilang/ganda, tetapi selisih harian menjadi palsu walaupun verifier uang keseluruhan lulus.

Probe baru `tests/v6_phase3_boundary_audit.php` memakai trigger **AFTER INSERT** yang hanya menggeser `timestamp` sesi DB uji antara statement: debit pukul 23:59:59, pembayaran hari berikutnya 00:00:01; lalu pembatalan 23:59:59, pembalik hari berikutnya 00:00:01. Semua uang/referensi tetap dicatat layanan asli. Pada `TabunganRepository` asli dari `20520d4`, hasil **5/4** (`batas-tengah-malam-kontrol.txt`); pemeriksaan yang gagal adalah kesamaan agregat dan rincian harian. Eksplorasi pertama hanya pasangan bayar juga tersimpan di `batas-tengah-malam-sebelum.txt` (+Rp50.000/−Rp50.000).

Koreksi hanya kueri rekonsiliasi: dua sisi pembayaran **internal** memakai waktu debit ledger, dan dua sisi pengembalian memakai waktu pembalik. Kas tunai/transfer tetap memakai waktu verifikasi/pembatalannya. Agregat dan rincian memakai aturan sama; timestamp asli pembayaran/pembatalan, kuitansi serta ledger tidak diubah. Ini menetapkan satu waktu pelaporan untuk satu perpindahan internal agar cocok dengan saldo ledger, tanpa mengubah jumlah uang atau hak.

Probe yang sama sesudah koreksi **9/0**: kedua jenis selisih Rp0 pada tiga hari; dua sisi muncul bersama dalam rincian; kas baru hanya Rp100.000 saat setoran, saldo harian Rp50.000/Rp50.000/Rp100.000. Setelah F-4, statis, kedua probe, integrasi/konkurensi, HTTP dan browser Fase 3 diulang. Regresi lain serta migrasi dijalankan setelah F-1…F-3, sebelum F-4; F-4 hanya mengubah kueri laporan baru Fase 3, tanpa perubahan layanan/SQL migrasi/antarmuka Fase 1–2/PSB.

## Bukti yang dijalankan auditor

Perintah ulang: `bash docs/phase-v6-3/audit-codex/ulang-uji.sh`. Builder fixture asli dijalankan sekali, kemudian dump sintetis kosong BSA diimpor ke DB terpisah sebelum setiap suite. Ini setara dengan builder Fase 1–3: wrapper Fase 2/3 tidak menambah data di luar fixture Fase 1. Suite historis tidak diedit. SQL/dump privat dan log server berada di `/private/tmp`, tidak di-commit.

| Pengujian | Hasil akhir | Bukti relatif terhadap `audit-codex/` |
| --- | --- | --- |
| Statis Fase 3 + lint 31 PHP | 57/0 | `statis-akhir.txt` |
| Integrasi layanan, konkurensi, rollback, verifier, preservasi | 137/0 | `integrasi-akhir.txt`; awal juga 137/0 |
| HTTP web/API Fase 3 | 66/0 | `http-akhir.txt`; awal juga 66/0 |
| Probe auditor F-1/F-2/F-3, tiga hari, retry setelah gagal, kontrol negatif | 27/0 | `probe-akhir.txt` |
| Probe batas tengah malam F-4, pembayaran dan pengembalian | 9/0 | `batas-tengah-malam-akhir.txt`; kontrol asli 5/4 |
| Browser Fase 3 375/768/1280px, tanpa JS, kuitansi | 89/0 | `browser.txt`, `tangkapan/uji-v6-fase3/` |
| Migrasi/rollback 029, manifest, CHECK/FK, preservasi 38 tabel inti | 35/0 | `migrasi-rollback-akhir.txt`; awal juga 35/0 |
| Regresi Fase 1 integrasi / HTTP / probe / browser | 120/0 · 96/8 (oracle historis) · 37/0 · 59/0 | `regresi/v6-fase1-*.txt` |
| Kontrol HTTP Fase 1 dengan 029 dilepas, kode Fase 3 | 104/0 | `regresi/v6-fase1-http-tanpa029.txt` |
| Regresi Fase 2 integrasi / HTTP / probe / era 027 / browser | 179/0 · 110/0 · 30/0 · 10/0 · 76/0 | `regresi/v6-fase2-*.txt` |
| Keamanan PSB Apache HTTP / browser | 97/0 · 42/0 | `regresi/psb-*.txt` |
| Penugasan / V2 Fase 4 / V2 Fase 5 | 161/0 · 124/0 · 143/0 | `regresi/penugasan.txt`, `regresi/v2-fase4.txt`, `regresi/v2-fase5.txt` |
| Kerangka sembilan akun × 375/768/1280px | 91/0 | `regresi/browser-kerangka.txt` |
| Seluruh 37 suite statis | 19 exit 0 · 16 exit 1 · 2 exit 77 | `regresi/ringkasan.txt`, keluaran per suite, `perbandingan-statis.json` |

Auditor memeriksa visual hasil eksekusinya sendiri: kuitansi debit tabungan 375px (Rp75.000 sebelum, −Rp50.000, Rp25.000 sesudah, nomor tabungan/tagihan), formulir bayar dari tabungan dan rekonsiliasi 375px. Tidak ada overflow horizontal pada tiga lebar yang diuji; kontrol di bawah bilah bawah dapat dijangkau dengan menggulir. Pemeriksaan PDF satu halaman A4 dilakukan otomatis oleh suite browser, bukan klaim audit produksi/perangkat cetak.

## Penilaian kriteria wajib Fase 3

| Kriteria PRD | Bukti |
| --- | --- |
| Setoran Rp100.000, belanja Rp25.000 → Rp75.000; dua belanja Rp60.000 → tepat satu sukses | L-2/L-6 integrasi, HTTP M-B/M-F, browser D-B. Dua proses/koneksi DB dan aktor berbeda → satu sukses, satu 422, saldo Rp15.000. Sepuluh pengeluaran atas saldo terbatas menghasilkan tepat jumlah sukses yang dapat dibayar, tanpa saldo negatif. |
| Bayar Rp50.000 dari Rp75.000 → saldo Rp25.000 dan sisa Rp50.000, kedua riwayat terhubung | L-4, HTTP M-C/M-E, browser D-B serta probe B. Satu debit `PembayaranTagihan/Internal`, satu pembayaran `Tabungan` yang menunjuk debit, kuitansi unik; referensi terbaca oleh petugas/admin/wali anak itu. Penerbitan tagihan tidak mendebit tabungan. |
| Gagal setelah debit disiapkan → seluruhnya rollback, nol yatim | L-7 memakai trigger INSERT pembayaran yang gagal sesudah debit dan pembaruan saldo; juga kuitansi/audit/pengembalian gagal. Probe C mengulang dengan kunci **yang sama** sesudah trigger dilepas: baru sekali sukses, lalu replay. Saldo, sisa, ledger, kuitansi, klaim dan audit cocok; verifier mendeteksi kontrol negatif nominal yang sengaja dirusak dan kembali lulus setelah fixture dipulihkan. |
| Klik ganda/retry → paling banyak satu debit dan satu pembayaran | L-5/L-6: delapan proses dengan isi/kunci sama → satu debit, satu pembayaran, tujuh replay. Nominal berbeda memakai kunci lama → 409. Dua pembayaran silang ke tagihan berbeda, tabungan vs penarikan, tabungan vs tunai pada satu tagihan, verifikasi/koreksi/pembatalan ganda diuji lewat proses terpisah. F-2 melengkapi data respons pengembalian pada replay. |
| Kas tidak bertambah karena pembayaran internal | L-9 dan probe B: hari pertama setoran Rp100.000/belanja Rp25.000, hari kedua debit/pembayaran Rp50.000, hari ketiga pembatalan/pembalik Rp50.000 + verifikasi transfer Rp20.000. Kas baru per hari Rp100.000/Rp0/Rp20.000; saldo akhir Rp75.000/Rp25.000/Rp95.000; selisih internal/pengembalian/ledger Rp0. Transfer dihitung saat verifikasi, bukan saat masuk antrean. |

Persyaratan tambahan: transfer Menunggu/Ditolak tidak masuk saldo, referensi transfer wajib dan alasan penolakan/koreksi wajib; pencatat/penyetuju/waktu tersimpan. Membalik setoran yang sudah dipakai ditolak bila akan membuat saldo negatif; debit tagihan dikoreksi melalui pembatalan pembayaran yang mengembalikan saldo sekali. Ledger sah tidak di-UPDATE/DELETE oleh layanan. Saldo akun = ledger = jumlah berjalan dan terbayar = pembayaran sah diuji verifier Fase 1–3.

Akses L-1/L-10/L-11, HTTP M-A/M-E/M-G dan regresi probe Fase 1–2: admin termasuk admin+petugas serta wali tidak memutasi; petugas hanya santri aktif dalam cakupan kini; wali hanya anak dengan relasi aktif. ID santri/tagihan/transaksi/kuitansi ditebak, hak dipersempit, santri nonaktif, penugasan/relasi dicabut pada sesi/token lama ditolak tanpa baris uang baru. Referensi transfer, catatan, alasan koreksi dan antrean belum sah tidak dibuka kepada wali. CSRF, GET tanpa mutasi, no-store, escape HTML serta audit tanpa nominal/referensi diuji.

## Regresi historis dan penafsiran

- HTTP Fase 1 mengasumsikan transfer tanpa `terverifikasi` langsung sah `201`. Sejak Fase 3, sesuai verifikasi yang diwajibkan PRD, respons **202 Menunggu** benar; pemeriksaan saldo/replay/audit berikutnya gagal berantai. Suite asli tetap dijalankan dan hasilnya dicatat terpisah dari kontrol tanpa 029. Tidak ada perubahan oracle historis.
- Seluruh 37 suite statis dibandingkan dengan bukti Fase 3 implementator: 36 mempunyai daftar pesan kegagalan identik. Perbedaan satu suite, `v6_phase2_static`, hanya daftar berkas di luar cakupan Fase 2 yang kini bertambah berkas audit Fase 3; empat jenis kegagalannya tetap sama (rute bertambah, audit bertambah, halaman transaksi dilengkapi, cakupan fase lama). `perbandingan-statis.json` menyimpan perbandingan literal. Bukan seluruh regresi hijau.
- Fase 1 statis 70/5 dan Fase 2 statis 58/4 tetap oracle historis yang sudah dijelaskan implementator. V2 Fase 5 statis membaca checkout mobile milik pekerjaan lain; V3 Fase 2/3 exit 77 karena syarat DB bernama tetap. Tidak mengubah checkout mobile atau melonggarkan tes.
- R-1…R-9/R-12…R-14 README diterima sebagai penafsiran dalam Fase 3: antrean terpisah menjaga ledger hanya-tambah; transfer diverifikasi manual; kuitansi turunan ID; jenis Belanja; koreksi berpasangan; rekonsiliasi membedakan kas dari internal dengan satu snapshot baca; orang tua tetap hanya baca. Persetujuan `setuju` wajib untuk pembayaran dari tabungan. R-2 adalah perubahan perilaku API yang diwajibkan verifikasi, dicatat jelas untuk implementasi mobile kelak.
- R-10 tetap batas terbuka: santri nonaktif/keluar bersaldo belum mempunyai jalur pengembalian oleh petugas (hak Fase 1 tidak diperluas auditor). Perlu keputusan Human Developer sebelum penutupan gerbang akhir/rilis V6. R-11 notifikasi, paritas mobile, laporan/ekspor lengkap serta kesiapan rilis tetap Fase 4–5; tidak dikerjakan audit ini.

## Batas operasional dan handoff

1. **MySQL dan cPanel/produksi belum diuji**. MariaDB lokal saja; CHECK, InnoDB, READ COMMITTED, konsisten snapshot, binlog dan izin hosting tetap wajib diperiksa sebelum rilis. Zona waktu DB lokal `SYSTEM`; jam/konfigurasi hosting belum diverifikasi.
2. Bukti konkurensi memakai proses/koneksi DB terpisah pada pola yang disebutkan, bukan seluruh kemungkinan interleaving atau beban produksi. Permintaan HTTP serentak pada server PHP satu pekerja membuktikan kontrak HTTP, bukan konkurensi DB. Proses mati/jaringan putus tepat saat commit belum disimulasikan; operasi yang gagal sebelum commit dan retry diuji.
3. Probe tiga hari dan F-4 mengendalikan `SET timestamp` pada koneksi fixture sintetis, bukan mengganti jam server. F-4 mensimulasikan pergeseran tanggal di antara statement, bukan menunggu tengah malam nyata atau menguji perubahan zona waktu hosting. Rekonsiliasi memakai cakupan santri kini untuk petugas, seluruh santri untuk admin, dan rincian dibatasi 500 baris (agregat tidak dibatasi).
4. Tidak memeriksa dana bank nyata, backup/restore produksi, Android/iOS, perangkat fisik, Push, UAT, atau rilis V6. Batas gerbang rilis V5 tidak diubah. Regresi migrasi Fase 1/2 historis, V3/V4 ber-DB tetap, runtime/browser V5, dan HTTP redesain lain tidak dijalankan ulang auditor pada sesi ini; bukti implementator tetap terpisah dan bukan pengganti eksekusi auditor.
5. **Tagihan dan tabungan hanya boleh dirilis bersama setelah gerbang akhir Fase 5 serta keputusan Human Developer.** Branch belum mempunyai sakelar rilis; merge ke cabang deploy diikuti migrasi 027–029 akan membuka Keuangan bagi penugasan lama yang masih berlaku. Kelulusan fase tidak menjadi izin rilis parsial.
6. Commit/push hasil audit hanya pada branch fase yang sama, lalu berhenti. Tidak memulai Fase 4, merge, deploy, migrasi produksi, atau merilis aplikasi.
