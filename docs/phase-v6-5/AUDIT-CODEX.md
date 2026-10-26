# Audit Codex — PRD V6 Fase 5 dan gerbang akhir

27 Oktober 2026. **LULUS audit teknis lokal Fase 5 setelah tiga koreksi terarah. Tagihan dan tabungan sama-sama lulus pada lingkungan yang diuji; seluruh gerbang akhir/produksi V6 BELUM LULUS.** Smoke cPanel/hosting, perangkat fisik, Push nyata, UAT dan rekonsiliasi dana nyata belum dibuktikan. Keputusan merge/deploy/rilis bersama diserahkan kepada Human Developer. Tidak ada izin rilis parsial atau awal V7.

## Lingkup dan baseline

| Repositori | Baseline audit Fase 4 | Seluruh commit Fase 5 yang diaudit |
| --- | --- | --- |
| WebAlHasan | `75cb144685d02d10df563f67eb1e23a67eec030b` | `8108012` (implementasi), `cd3bdf5e0eec4b0df3c64551e32df9db3f49873b` (handoff) |
| alhasanApps | `16bcdb7a7dc475f785c328cfdae90ad6b3072e6b` | `18fe095` (implementasi), `ecfbe1099ac14cacefa7e1ad0f6546f1a667c6f5` (handoff) |

Kedua checkout berada di `claude/prd-v6-fase-5`. Hash handoff remote diverifikasi melalui `git ls-remote`. Tidak membuat worktree atau menjalankan agen paralel. AGENTS kedua repo, PRD V6, prompt Fase 5, audit Fase 1–4 termasuk audit ulang Fase 4, seluruh diff sumber Fase 5, README/bukti implementator, manifest, verifier, alat backup/restore, rollback, runbook, checklist dan sumber suite diperiksa. Bukti implementator dipertahankan; hasil eksekusi auditor disimpan terpisah di `audit-codex/`.

PHP 8.4.14, MariaDB 12.3.2 lokal, Node 26.7.0, Chromium Playwright. Database yang ditulis hanya salinan `test_*_test` dan pulihannya, dengan fixture sintetis. Tidak menghubungi API, rekening, provider Push atau DB produksi. Perubahan paket mobile milik pekerjaan lain dan direktori web `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/` tetap di luar commit audit.

## Temuan dan koreksi terarah

### F-1 · P2 · Manifest pasca-V6 kehilangan hash uang tetap lulus — ditutup

`bin/v6_verify.php` membandingkan hanya hash uang yang masih tercantum. Menghapus seluruh hash tabel V6 membuat verifier menyebut manifest itu diambil sebelum V6, walaupun `v6_terpasang` menyatakan 027–029 sudah terpasang. Menghapus satu hash juga melewati pemeriksaan isi tabel itu. Manifest kehilangan status migrasi pun diterima. Invarians saldo yang lulus tidak menggantikan bukti preservasi isi uang saat restore.

Koreksi menuntut status sumber lengkap (seluruhnya sebelum V6 atau seluruhnya sesudah V6) serta **daftar sidik jari tepat sesuai status**: empat tabel identitas sebelum V6; identitas dan seluruh tabel uang yang di-hash preflight sesudah V6. Status hilang/parsial atau daftar terpotong/tidak sesuai ditolak. Manifest asli pra-V6 tetap sah untuk pemeriksaan segera setelah migrasi. Idempotensi tetap dikecualikan dari sidik jari sesuai kontrak preflight yang sudah ada; drill membandingkannya juga lewat hash isi.

### F-2 · P2 · Preflight menerima CHECK MariaDB nonaktif — ditutup

Preflight sebelumnya menyimpulkan CHECK ditegakkan dari versi server saja. MariaDB lokal yang sama dengan `SET SESSION check_constraint_checks=OFF` tetap lulus, padahal CHECK menjaga saldo serta menolak rollback berisi data.

Koreksi membaca status CHECK sesi **dan default global** pada MariaDB. Salah satunya nonaktif membuat preflight gagal. Probe hanya mematikan CHECK pada koneksi subprocess sendiri; tidak mengubah default global atau pengaturan produksi. MySQL tidak diberi kueri variabel MariaDB. Runbook diperbarui; uji MySQL/hosting tetap terbuka.

Kontrol F-1/F-2 pada sumber asli: `probe-kontrol.txt` **3/5**; setelah koreksi keduanya **8/0**. Probe memakai manifest sementara, tanpa menghapus data uang.

### F-3 · P2 · Pencarian nama menyembunyikan selisih dari rekonsiliasi agregat — ditutup

Laporan memakai jumlah santri berselisih **setelah pencarian nama** untuk pita rekonsiliasi, tetapi angka tagihan/tabungan agregat tetap seluruh cakupan. Saldo akun SMP +Rp1 terdeteksi tanpa pencarian; mencari Tsanawi menghasilkan pita selisih Rp0, walaupun saldo SMP masih tidak cocok dengan ledger.

Koreksi memisahkan hitungan rekonsiliasi seluruh cakupan dari total tabel yang mengikuti pencarian, dalam snapshot yang sama dan syarat hak/unit/santri yang sama. Pencarian tetap menyaring tabel dan totalnya. Agregat tidak lagi terlihat sehat karena baris bermasalah tersembunyi. Tidak mengubah nominal, transaksi, kapabilitas, migrasi atau kontrak mobile.

`probe-kontrol-pencarian.txt`: **9/1** sesudah F-1/F-2 tetapi sebelum F-3. Probe mengubah saldo fixture Rp1 lalu memulihkannya dalam `finally`. Regresi tambahan memastikan petugas Tsanawi tidak membaca selisih SMP di luar haknya. Sumber probe: `tests/v6_phase5_audit.php`.

### Catatan operasional

Runbook sebelumnya menyebut verifier tidak mencetak nominal, padahal ia mencetak agregat saldo/kas. Diperjelas: log khusus operator di luar document root, direktori 0700/berkas 0600 dan `umask 077`; jangan kirim ke log umum. Verifier berantai lintas proses tidak mempunyai satu snapshot global. Untuk bukti restore/migrasi, hentikan penulis seperti runbook; alarm pemeriksaan berkala pada transaksi aktif perlu dikonfirmasi pada jendela tenang.

## Bukti independen

`audit-codex/` berisi hasil eksekusi auditor sendiri, terpisah dari `bukti/` implementator. Integrasi dan HTTP Fase 5 **diulang dari fixture baru sesudah seluruh koreksi kode**. Tidak ada oracle historis yang dilonggarkan. Pemeriksaan whitespace sumber/dokumen lulus; beberapa baris stdout mentah (mis. penanda `UPDATE ` pada tes statis) mempertahankan whitespace aslinya.

| Pemeriksaan | Hasil akhir | Bukti |
| --- | --- | --- |
| Statis Fase 5 + lint 18 PHP | 74/0 | `statis-akhir.txt`, `regresi/statis-v6_phase5_static.txt` |
| Integrasi keuangan, hak, ekspor, koreksi, konkurensi dan snapshot | 71/0 | `integrasi-akhir.txt` (awal juga 71/0) |
| HTTP web/API Fase 5 | 42/0 | `http-akhir.txt` (awal juga 42/0) |
| Probe auditor F-1/F-2/F-3, termasuk cakupan hitungan rekonsiliasi | 11/0 | `probe-akhir.txt`; kontrol 3/5 dan 9/1 terpisah |
| Migrasi 027–029 naik–turun–naik, manifest, zona waktu, pemasangan sebagian, penjaga rollback berisi data | 29/0 | `rilis.txt` |
| Backup/restore penuh salinan, metode aplikasi / dump runbook | 11/0 · 12/0 | bagian S-6 `rilis.txt`: jumlah seluruh tabel, hash isi 24 tabel, angka laporan, verifier + manifest, rollback berisi data ditolak |
| Browser web 375/768/1280, ekspor, tanpa JavaScript | 27/0 | `browser-ulang.txt`; timeout awal `browser.txt` tetap disimpan |
| Aplikasi Expo web + API/DB fixture nyata, 375px | 20/0 | `aplikasi-expo-web.txt` |
| TypeScript / lint / unit mobile pada lock ter-commit | exit 0 / exit 0 / 37/0 | `mobile-tsc.txt`, `mobile-lint.txt`, `mobile-unit.txt` |
| iOS Release simulator dibangun ulang / matriks laporan dan pencabutan | build berhasil / 7/0 | `ios-build-ringkasan.txt`, `ios-ringkasan.txt`, `maestro/` |
| Hash migrasi, rollback, dependensi web, berkas HEAD mobile | 39/39 cocok | `manifest-dan-build.json` |

Pada beban akhir, tiga proses penulis menyelesaikan 36 langkah bersamaan dengan 45 pembacaan: **0 laporan tidak konsisten dengan snapshot**. Kontrol kueri terpisah tanpa snapshot **23/45 tidak konsisten**; kontrol bergantung penjadwalan dan tidak menjadi assertion deterministik.

## Regresi yang dijalankan ulang

Seluruh runner berjalan sekuensial karena builder memakai DB perantara tetap. Keluaran lengkap di `audit-codex/regresi/`, ringkasan `ringkasan.txt`; setiap verifier dijalankan pada DB yang sama segera setelah suite terkait.

| Regresi | Hasil |
| --- | --- |
| V6 Fase 1 integrasi / probe audit / browser | 120/0 · 37/0 · 59/0 |
| V6 Fase 1 HTTP / migrasi historis | 96/8 · 19/10 (oracle lama) |
| V6 Fase 2 integrasi / HTTP / probe / era 027 / browser | 179/0 · 110/0 · 30/0 · 10/0 · 76/0 |
| V6 Fase 2 migrasi historis | 22/14 (oracle lama) |
| V6 Fase 3 integrasi / HTTP / probe / batas hari / migrasi / browser | 137/0 · 66/0 · 27/0 · 9/0 · 35/0 · 89/0 |
| V6 Fase 4 HTTP, paritas API/notifikasi/privasi wali | 63/0 |
| Verifier rilis sesudah Fase 1/2/3 integrasi, Fase 3/4 HTTP | 5 × 36/0 |
| Keamanan PSB melalui Apache/php-fpm dengan .htaccess / browser | 97/0 · 42/0 |
| Fondasi penugasan / V2 Fase 4 / V2 Fase 5 | 161/0 · 124/0 · 143/0 |
| V5 Fase 5 / Fase 3 | 95/0 · 82/0 |
| HTTP redesain Tahap 2 / 3 | 185/6 · 166/1 (oracle lama) |
| HTTP pembinaan Tahap 5 / penilaian-rapor Tahap 7 | 106/0 · 122/0 |
| Rollback kosong 029…024 / HTTP data pesantren Tahap 6 sesudah rollback | 33/0 · 132/0 |
| Browser kerangka sembilan akun, tiga lebar | 91/0 |
| Seluruh 39 statis (V1–V6, PSB, fondasi, redesain) | 19 exit 0 · 18 exit 1 · 2 exit 77 |

**Tidak seluruh regresi hijau.** `perbandingan-kegagalan.json` mencocokkan seluruh pesan gagal dengan bukti implementator Fase 5. Dari 23 suite yang memuat pesan gagal, **20 identik literal**. Tiga lainnya (`v6_phase2_static`, `v6_phase3_static`, `v6_phase4_static`) hanya berbeda dalam daftar berkas di luar cakupan fase lama, yang kini mencakup laporan Fase 5 dan bukti auditor; jumlah/jenis gagal tetap 6/2/2. Tidak ditemukan jenis kegagalan baru.

HTTP Fase 1 masih mengasumsikan setoran transfer langsung sah, padahal sejak Fase 3 harus Menunggu. Migrasi Fase 1/2 masih mengasumsikan 027/028 terakhir. HTTP Tahap 2 mengunci menu sebelum BB–BE; Tahap 3 menganggap pratinjau AG belum terpasang. Statis lama membatasi migrasi/API/notifikasi pada fase historis. Tiga kegagalan versi paket V2 Fase 5 membaca checkout mobile milik pekerjaan lain; tidak mengubah paket itu agar tes hijau. Dua statis V3 exit 77 karena syarat DB tetap. Seluruhnya tetap dicatat, bukan dihapus/dilonggarkan.

Ini regresi relevan V1–V5/PSB, **bukan audit ulang lengkap seluruh fitur versi lama**. V3/V4 juga dicakup oleh HTTP pembinaan dan rapor di atas; suite integrasi ber-DB tetap/runner privat serta seluruh browser V1–V5 tidak diulang.

## Aplikasi native dan privasi

Auditor membangun ulang **iOS Release arm64 simulator ad-hoc lokal**, Xcode 27.1/iPhone 17 Pro iOS 26.2, dari HEAD mobile `ecfbe10` dengan paket lock ter-commit Expo **57.0.15**. Salinan build tidak memiliki `.env*` produksi. Build berhasil; hash binary/bundle dan API `127.0.0.1:8990` ada di `manifest-dan-build.json`, dengan **0 host produksi di bundle**. Ini bukan arsip distribusi atau build perangkat fisik; tidak membutuhkan Metro saat runtime.

Matriks laporan native **7/0**: admin membaca tagihan Rp100.000/saldo Rp62.000/kas Rp132.000, admin melihat santri nonaktif, BSA dibatasi ke santri aktif (saldo Rp50.000/kas Rp120.000), penugasan BSA dicabut saat di latar menutup laporan lama, wali dua anak hanya melihat anaknya tanpa kas/selisih internal, pencabutan relasi SMP memperbarui saldo Rp0 dan menyisakan Tsanawi, serta tidak ada perubahan ledger/pembayaran/saldo dari seluruh pembacaan. T-1 dipertahankan; auditor tidak mematikan perlindungan layar.

**123 berkas** data aplikasi simulator terbaca (0 gagal dibaca) dan **68.058 baris log proses 20 menit** tanpa penanda nama/NIS/referensi sintetis yang diuji atau bearer mentah. Bukti `ios-privasi.json`. Ini bukan forensik RAM, Keychain autentikasi atau seluruh cache OS; token yang diperlukan tetap di SecureStore. Expo web memeriksa penyimpanan/konsol, 0 POST ke modul V6 dari laporan, serta 0 permintaan keluar loopback. Layar laporan tidak menyimpan CSV. Jejak rinci mobile menampilkan paling banyak 20 koreksi; audit detail lengkap/CSV tersedia di website.

Kontrol pembantu disimpan: nama fixture keliru (`Keluar` vs `Nonaktif`, 1/1), asumsi tujuan sesudah login (2/1), dan kartu anak belum digulir ke layar setelah refresh kembali ke atas (5/1). Hierarki pada kontrol terakhir sudah memperlihatkan angka Rp0; kegagalan terjadi sebelum kartu anak terlihat. Pembantu diperbaiki tanpa koreksi aplikasi, lalu matriks lengkap diulang **7/0**. `uji-ios.py` dan sumber/hasil Maestro disimpan. Tangkapan kontrol saat proteksi aktif hitam; ini tidak diklaim sebagai pemeriksaan visual layar fisik.

Browser web awal berhenti pada timeout saringan tanpa JavaScript (bukan hasil 27/0). Pengulangan dengan diagnosis validitas form/screenshot, tanpa perubahan oracle atau aplikasi, lulus **27/0** termasuk klik Terapkan dan unduhan CSV tanpa JavaScript. Kedua keluaran dipertahankan (`browser.txt`, `browser-ulang.txt`). Tangkapan web wali dan aplikasi admin 375px hasil auditor diperiksa visual; tiga lebar diuji otomatis. Bukan pengganti UAT atau aksesibilitas native.

## Penilaian tagihan dan tabungan sekaligus

- Oracle dua bulan diperiksa terhadap kueri sumber terpisah: saldo SMP Rp90.000 = Rp110.000 + Rp55.000 − Rp20.000 − Rp15.000 − Rp60.000 + Rp15.000 − Rp5.000 + Rp10.000. Tagihan bulan B Rp210.000 − pembayaran sah Rp140.000 = sisa Rp70.000; transfer Rp30.000 menunggu tidak dihitung.
- Kas baru bulan B Rp247.000 berasal dari setoran dan pembayaran tunai/transfer. Debit/pembayaran tabungan Rp60.000 terpisah; tambahan pembayaran Rp5.000 dari tabungan tidak menambah kas baru. Pembatalan yang kemudian terjadi menjadi arus keluar pada waktu pembatalannya. Angka periode = rekonsiliasi harian = ekspor CSV yang bersesuaian.
- Enam proses setoran berkunci sama menghasilkan satu kredit/lima replay; dua belanja Rp30.000 atas Rp40.000 menghasilkan satu sukses dan saldo Rp10.000. Tiga penulis dan tiga pembaca/45 laporan menguji snapshot; pola kegagalan transaksi dan pembayaran silang diuji lagi pada regresi Fase 1–3. Ini bukan seluruh kemungkinan interleaving atau beban produksi.
- Seluruh delapan koreksi/pembatalan fixture mempunyai aktor, waktu, alasan dan asal. Kontrol +Rp1 saldo, −Rp1 terbayar, serta penghilangan audit koreksi membuat verifier gagal; fixture dipulihkan. Laporan tidak menulis uang/klaim/notifikasi; ekspor menulis audit tanpa nominal.
- Hak BSA/admin/admin+petugas/wali diperiksa ulang server; petugas mengikuti santri aktif/unit, admin hanya baca, wali hanya relasi aktif anaknya. Ekspor/kuitansi/detail dan token/sesi lama diuji. R-10 tetap hanya laporan admin, tanpa memperluas mutasi petugas.

## Penafsiran dan gerbang rilis yang masih terbuka

U-1…U-7 diterima untuk batas Fase 5: periode tagihan dan waktu transaksi adalah dua sumbu; kas baru adalah penerimaan bruto, koreksi/pembatalan ditampilkan sebagai keluar; CSV tersedia di web dengan batas 10.000 dan audit; aplikasi hanya membaca JSON; R-10 hanya dilaporkan sesuai keputusan Human Developer. Retensi `audit_logs` diperlukan untuk verifier setiap baris uang berjejak. Penambahan Laporan adalah navigasi bagian Keuangan.

1. **cPanel/hosting, MySQL, produksi:** belum diuji; tidak tersedia bukti akses dan instruksi eksekusi produksi untuk sesi ini. Semua baris produksi checklist tetap **Menunggu pelaksanaan**. Versi/konfigurasi nyata, CHECK, binlog, jam DB/aplikasi, PHP CLI/FPM, .htaccess, cron, backup/restore hosting dan smoke bersama masih wajib.
2. **Perangkat fisik Android/iOS dan Push provider nyata:** belum diuji; ketukan/cold start Push serta proteksi tangkapan/rekaman fisik tetap terbuka. URI simulator bukan Push. Tidak ada build/arsip distribusi atau rilis toko. Batas Debug/dev client iOS, TalkBack/VoiceOver dan pengujian fisik Fase 4 tidak ditutup oleh audit laporan ini.
3. **Android Fase 5:** belum dijalankan ulang secara native; bukti Android Fase 4 tetap historis. Bukti simulator iOS Fase 5 dipisahkan dari Expo web dan perangkat fisik.
4. **Keputusan operasional:** tinjau penugasan bendahara lama (otomatis menjadi BSA), putuskan V5 024–026 yang ikut terbawa `migrate up`, dan kebijakan pengembalian saldo santri nonaktif R-10. Tidak ada sakelar rilis; kode dan 027–029 harus dipasang bersama dalam jendela pemeliharaan.
5. **UAT dan dana nyata:** petugas BSA/admin/wali belum UAT; kas fisik dan mutasi rekening belum direkonsiliasi. Aplikasi tidak membuktikan dana bank benar-benar masuk. Smoke staging hasil restore dianjurkan; smoke produksi memerlukan pilihan Human Developer dan koreksi berpasangan karena transaksi tidak boleh dihapus.
6. **Regresi yang tidak diulang:** suite V3/V4 ber-DB tetap/runner privat dan seluruh browser native V1–V5 tidak diklaim dijalankan; rincian tabel hasil akhir membedakan bukti lama dari eksekusi sesi ini. Status gerbang V5 tetap mengikuti audit V5, tidak otomatis lulus karena audit V6.

## Handoff

Ketiga temuan kode Fase 5 ditutup dengan probe dan regresi; tidak ada temuan kode baru yang memblokir audit teknis lokal. Kriteria saldo/tagihan/rekonsiliasi/jejak/hak serta pemulihan salinan terbukti untuk **kedua modul bersama**. Kriteria hosting/smoke produksi dan validasi operasional yang disebutkan di atas tetap **terbuka**: auditor tidak menyatakan seluruh kriteria akhir V6 lulus atau V6 siap produksi.

Ringkasan mobile ada di repo alhasanApps `docs/phase-v6-5/AUDIT-CODEX.md`; mobile hanya berubah pada dokumen audit/README/AGENTS. Web memuat koreksi, probe, bukti dan status ini. Commit/push dilakukan pada kedua branch `claude/prd-v6-fase-5`; hash akhir dicatat pada riwayat Git dan handoff kepada Human Developer. Direktori web serta paket mobile milik pekerjaan lain tidak disertakan.

Human Developer memutuskan merge/deploy/rilis **tagihan dan tabungan sekaligus** setelah menilai gerbang terbuka dan menjalankan pemeriksaan yang diwajibkan. Auditor berhenti di sini. Tidak merge, deploy, migrasi produksi, merilis aplikasi atau memulai V7.
