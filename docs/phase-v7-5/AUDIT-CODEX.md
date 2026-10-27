# Audit Codex — PRD V7 Fase 5 dan gerbang akhir

27 Oktober 2026. **Audit teknis lokal dilakukan dengan dua koreksi alat preservasi. Penerimaan penuh Fase 5 dan gerbang akhir V7 BELUM LULUS.** Temuan cakupan murobi F5-1 masih terbuka, demikian pula validasi Push nyata, perangkat fisik, hosting dan UAT. Keberhasilan rangkaian utama tidak berarti seluruh kriteria wajib telah terpenuhi. Tidak merge, deploy, migrasi produksi, merilis aplikasi, atau memulai V8.

## Lingkup dan sumber

- Kedua repo tetap `claude/prd-v7-fase-5`, tanpa worktree. Web: baseline audit Fase 4 `851ef0a26d1b8dd4e1db80ecf4f4ce8104ee8da7`, satu commit implementasi Fase 5 `971b3691375a65d3d17dc6334ad3c43c00061436`. Mobile: baseline `7478f7cb153e4f8af1a1b85791ce843a29733bd2`, satu commit Fase 5 `74c331dd8ede2729ba24899ee2c49b8f1bba3168`. Kedua handoff cocok dengan `git ls-remote` sebelum audit.
- Dibaca AGENTS kedua repo, PRD-V2/V7, prompt audit, audit Fase 1–4 dan gerbang V6, seluruh diff Fase 5, manifest, alat/verifier, uji, runbook, checklist dan bukti implementator. Handoff menyatakan Claude berhenti; tidak menjalankan Claude/subagen.
- PHP 8.4.14, MariaDB 12.3.2 lokal, Node 26.7.0, Chromium Playwright, Expo 57.0.15 dari lock Git. Semua mutasi pada salinan `test_*_test` berfixture sintetis. Tidak menghubungi provider Push/WhatsApp atau produksi.
- Paket mobile yang berubah di checkout milik pekerjaan lain tidak dipakai/dimasukkan commit. Uji memakai arsip HEAD mobile tanpa `.env*`, dengan direktori `node_modules` nyata dari lock ter-commit. Tiga direktori web pekerjaan lain tetap tidak disentuh. Kode bisnis, API, skema SQL, dan kode mobile tidak diubah auditor; koreksi hanya pembantu alat rilis. Uji statis Fase 5 mengizinkan satu probe browser tambahan, tanpa melonggarkan oracle perilaku.
- Bukti independen di `audit-codex/`, terpisah dari `bukti/` implementator. Runner `perintah-audit.sh` dan `perintah-regresi-audit.sh` memakai nama DB/keluaran auditor; builder fixture dijalankan berurutan karena memakai DB perantara tetap.

## Temuan dan koreksi

### A-1 · P2 · Perubahan isi audit lama lolos preservasi — ditutup

`v7_snapshot()` mengecualikan seluruh `audit_logs` dari jumlah dan hash. Mengubah `after_json` satu audit lama, dengan jumlah baris tetap, masih meluluskan `v7_verify.php MANIFEST`. Ini tidak cukup untuk klaim preservasi seluruh data V1–V6/restore, walaupun hitungan pasangan audit pengajuan wali masih benar.

Koreksi memasukkan `audit_logs` ke snapshot jumlah dan isi. Migrasi/rollback 030–031 tidak menulis audit, sehingga audit harus tetap sama tanpa lalu lintas. Hanya `schema_migrations` dikecualikan karena memang berubah saat migrasi; isinya dibandingkan tersendiri pada drill restore. Aktivitas bisnis sah diperiksa melalui `--irisan-v2`, bukan manifest seluruh tabel.

### A-2 · P2 · Pengodean hash menyamakan isi berbeda — ditutup

Penggabungan kolom dengan byte `0x1f`, serta penanda NULL berupa NUL, ambigu: `["a<0x1f>b", "c"]` sama dengan `["a", "b<0x1f>c"]`; NULL juga sama dengan string satu byte NUL. Perubahan data tersebut menghasilkan hash yang sama, tanpa perlu menyerang SHA-256.

Koreksi mengodekan jumlah kolom, penanda NULL/string dan panjang byte setiap string sebelum hashing. Hash multiset tetap tidak bergantung urutan dan tetap menghitung duplikasi. Probe identik `tests/v7_phase5_audit.php`: **4 lulus/4 gagal → 8/0** (`probe-alat-asli.txt`, `probe-alat-akhir.txt`), termasuk kontrol positif dan pemulihan audit. **Manifest privat sebelum koreksi harus dibuat ulang.** Manifest SHA migrasi/paket tidak berubah: SQL dan berkas paket Git identik.

### F5-1 · P2 · Pencabutan cakupan murobi sebagian — tetap terbuka

Probe independen membuat pengajuan wali yang otomatis diarahkan kepada Murobi A. Setelah hanya penugasan kamar yang mencakup santri dinonaktifkan, kandidat untuk santri tidak lagi memuat A, tetapi A masih punya penugasan murobi lain. Dengan identitas sesi lama: detail V7 dan V2 tetap **200/200**, laporan masih memuat **1** pengajuan, keputusan tetap **200** dan menghasilkan **1 keputusan**. Kontrol membuktikan hak/detail awal sah dan pencabutan benar-benar tersimpan. `tests/v7_phase5_scope.php`: **3 lulus/3 gagal** (`probe-cakupan.txt`); harapan penutupan hak sengaja tidak dilonggarkan.

Probe tambahan `tests/browser/uji-v7-fase5-audit.mjs` memakai server/API nyata dan aplikasi Expo web: **1 lulus/4 gagal** (`probe-cakupan-aplikasi.txt`). Detail API V7/V2 tetap 200/200, reload aplikasi mempertahankan isi, laporan total 1 dan keputusan dari tombol aplikasi diterima 201. Penugasan fixture dipulihkan dalam `finally`; oracle yang merah tetap merah.

Ini perlu keputusan aturan yang konsisten sebelum kelulusan penuh: PRD §5.2 meminta pemeriksaan ulang penugasan dan pencabutan pada sesi lama; implementasi V2 saat ini memakai murobi tujuan sebagai cakupan dan mengizinkan admin memilih murobi aktif di luar kamar/kelas santri. PRD V2 §5.2 juga mensyaratkan penugasan aktif yang cocok dengan santri; perilaku lama itu bukan persetujuan penyimpangan. Memakai `candidates()` begitu saja untuk semua tindakan akan sekaligus mengubah perilaku penetapan admin tersebut dan akses riwayat. Auditor tidak mengasumsikan pengecualian bisnis baru atau mengubah jalur V2 diam-diam. Pilih aturan tujuan pengajuan yang bertahan sampai penetapan ulang, atau cakupan santri terkini dengan penanganan penetapan/rerouting beralasan; lalu uji ulang detail, mutasi, laporan/ekspor dan notifikasi. **Penetapan ulang manual dalam runbook bukan bukti kriteria pencabutan telah lulus.** Tidak ada persetujuan perubahan aturan yang diasumsikan dari belum adanya jawaban.

### T-1 · Reliabilitas pengurus V2 — tetap terbuka

Diulang independen: delapan proses pengurus dengan kunci/isi sama menghasilkan **2 sukses/replay dan 6 respons 500**, satu ID dan satu baris (`retry-v2.txt`). Verifier sesudahnya **21/0**. Tidak ada pengajuan efektif ganda, tetapi retry jalur lama tidak bersih. Perilaku ini sudah direproduksi audit Fase 1; kode workflow/idempotensi pengurus identik baseline Fase 4. Tidak ditutup hanya karena jalur wali lulus.

## Bukti lokal utama

| Pemeriksaan | Hasil independen |
| --- | --- |
| Statis Fase 5 dan lint | 56/0; hasil akhir disimpan terpisah |
| E2E layanan + proses konkuren | 81/0 pada sumber handoff dan 81/0 lagi setelah koreksi alat |
| HTTP web/API | 40/0: dua jalur, sesi/CSRF/bearer, matriks 9 akun × 2 pengajuan, dua wali, ID/relasi, laporan/ekspor/cetak |
| Migrasi/rollback/restore | 36/0 pada sumber handoff, 36/0 setelah koreksi alat; **37/0** pada fixture akhir yang menambah tiga ID kanonis warisan/riwayat/pelaku NULL. Writer 11/0, dump 12/0 (`rilis-warisan-akhir.txt`, `restore-*-akhir.txt`) |
| Browser 375/768/1280 dan tanpa JS | 95/0; tiga pemeriksaan DB klik ganda menghasilkan satu baris per lebar; nol galat PHP |
| Expo web Fase 4 pada backend Fase 5 | utama 48/0, regresi pengurus/admin pengganti 7/0, probe F-1/F-2 5/0; nol host produksi dalam bundle dan nol galat PHP |
| Mobile tsc/lint/unit | exit 0 / exit 0 / 42/0 dari arsip Git dan lock ter-commit |
| Verifier rilis setelah E2E, HTTP, browser dan tiga kelompok Expo | 6 × 21/0; ulang setelah retry V2 21/0 |
| Probe preservasi tambahan | 4/4 → 8/0; audit dikembalikan dan kontrol positif lulus |
| Probe cakupan parsial | 3/3; temuan terbuka, tidak dihitung sebagai kelulusan |

E2E menguji satu/nol/dua pembimbing dan murobi, akun pembimbing tidak aktif, penetapan sah/tidak sah, perbaikan, penolakan pembimbing, pembatalan dari tahap berbeda, admin pengganti beralasan, benturan inklusif dua jalur, replay dan kunci konflik. Konkurensi: enam buat berkunci sama, tiga buat lintas jalur, empat ronde batal–putus, batal–teruskan dan klik ganda murobi–admin; satu hasil efektif, tanpa 500 pada jalur wali. Putaran E2E awal mengamati dua kemenangan pembatalan dan dua kemenangan keputusan; ulangan akhir empat kemenangan pembatalan. Probe regresi Fase 2 juga mengamati kedua terminal dengan retry (Disetujui satu keputusan atau Dibatalkan nol keputusan, hanya 200/409). Ini bukan seluruh interleaving atau uji beban produksi.

Rilis akhir dimulai dari salinan 029 dengan tiga perizinan V1 berisi **dan tiga pengajuan V2 is_legacy ber-ID sama serta riwayat impor/pelaku NULL**. Satu assertion preservasi kanonis ditambahkan pada suite rilis; oracle lama tidak dilonggarkan. Naik 030–031, pemasangan sebagian ditolak, turun kosong lalu naik lagi, serta rollback berisi ditolak oleh preflight dan CHECK SQL. Jumlah/ID/isi tabel lama, keputusan/riwayat dan relasi dipertahankan; sembilan kontrol pengrusakan membuat verifier gagal lalu lulus kembali. Manifest SHA mencocokkan 31 migrasi + dua rollback; pemeriksaan langsung disk/Git **37/37** juga mencakup composer.lock dan tiga berkas HEAD mobile (`manifest-audit.json`). Restore kedua metode ke DB berbeda memverifikasi seluruh snapshot, termasuk audit setelah koreksi, 17 hash tabel tambahan dan laporan; nol referensi yatim pada 15 relasi. DB restore dihapus oleh drill; dump sintetis privat tetap di luar Git.

Tangkapan auditor rincian wali 375/768 dan laporan admin 1280 diperiksa visual: teks/kartu/tabel terbaca, tanpa overflow halaman. Cetak di sini adalah halaman cetak browser/kontrak laporan yang diuji; bukan uji printer fisik. Cakupan/matriks utama yang lulus **tidak** menutup skenario pencabutan parsial F5-1.

## Regresi

Rangkaian lengkap selesai: **98/98** memiliki exit dan hitungan lulus/gagal identik dengan bukti implementator Fase 5 (`perbandingan-regresi.json`). Delapan suite statis memiliki teks daftar berkas historis berbeda karena berkas audit tambahan; jenis dan jumlah gagal tetap sama. Tidak mengubah oracle historis agar hijau.

| Regresi independen | Hasil |
| --- | --- |
| V7 Fase 4 HTTP | 54/0 |
| V7 Fase 3 integrasi / probe laporan / notifikasi / privasi / HTTP / probe HTTP / browser | 112/0 · 18/0 · 49/0 · 3/0 · 43/0 · 33/0 · 53/0 |
| V7 Fase 2 integrasi / HTTP / probe audit / migrasi / browser | 119/0 · 61/0 · 34/0 · 27/0 · 33/0 |
| V7 Fase 1 integrasi / probe audit / HTTP / browser | 73/2 · 13/0 · 71/0 · 39/0 |
| Penugasan | 161/0 |
| V2 integrasi Fase 1 / 2 / 4 / 5 dan konkurensi | 39/0 · 94/0 · 124/0 · 145/0; 20/0 |
| V2 API Fase 3 / 4 / 5; web Fase 2 / 4 / 5 | 95/21 · 91/1 · 150/0; 36/0 · 46/0 · 79/0 |
| Verifier rilis setelah kelompok V7 dan enam DB V2 | 12 × 21/0 |
| Worker V5 Fase 3; HTTP V6 Fase 4; V6 Fase 5 integrasi / HTTP / verifier | 82/0; 63/0; 71/0 · 42/0 · 36/0 |
| HTTP redesain Tahap 2 / 3 / 4 / 5 / 7 | 185/6 · 166/1 · 132/0 · 106/0 · 122/0 |
| Rollback 031…024 kosong / HTTP Tahap 6 sesudah rollback | 44/0 · 132/0 |
| Browser kerangka sembilan akun, tiga lebar | 91/0 |
| Seluruh 44 statis | 18 exit 0 · 24 exit 1 · dua exit 77 |

**Regresi tidak seluruhnya hijau.** Fase 1 V7 masih mengharapkan belum ada tindakan Fase 2 dan versi opsional; API V2 Fase 3–4 mengasumsikan fixture/routing lama; HTTP redesain Tahap 2–3 mengunci menu/penugasan historis. Statis lama membatasi migrasi terakhir, daftar berkas dan versi paket checkout mobile milik pekerjaan lain; dua V3 exit 77 memerlukan DB khusus. Pesan gagal dibandingkan terhadap bukti baseline, bukan dihapus atau diklaim lulus. Kontrol sumber baseline terdahulu tetap bukti historis audit Fase 1–3, tidak diklaim dijalankan ulang di sesi ini. Deprecation str_getcsv PHP 8.4 pada pembantu V2 lama dipertahankan dalam keluaran.

Ini regresi relevan, bukan audit ulang lengkap seluruh modul V1–V6. PSB Apache/php-fpm, suite V3/V4 ber-DB tetap/runner privat, seluruh browser V5/V6 dan uji beban data besar tidak diulang. Preservasi seluruh tabel lama dibuktikan terpisah melalui migrasi/rollback/restore dan manifest, bukan dengan mengklaim seluruh fitur lama sudah diuji manual.

## Native, privasi dan batas

- iOS **Release arm64 simulator**, iPhone 17 Pro iOS 26.2: binary audit Fase 4 dipakai ulang, bukan build baru/distribusi. SHA bundle `22fd94791f7ae6baab4d4989a36cd46de09dd74bd25e68acc1090fd383c80ac0` identik bukti audit Fase 4; sumber mobile/lock/app.json identik, API fixture 8977, nol host produksi (`provenansi-native.json`). **7/0**: cold URI/login, isi/ID pengaju, wali kedua ringkas, relasi dicabut saat latar, penerusan ID/versi sama, murobi membuka keputusan V2, pencabutan seluruh penugasan menutup isi tanpa keputusan. Pengulangan awal 0/1 berasal dari SpringBoard simulator crash yang dilaporkan Maestro saat cleanup driver; sumber/log diagnostik dipertahankan. Runner kemudian membuka kembali aplikasi setiap flow dan matriks selesai, tanpa koreksi produk.
- Android **emulator** Android 16, APK Debug dev client audit Fase 4 dipakai ulang + Metro sumber/lock Fase 5 tanpa env produksi: **8/0**. Pengaju membaca ID/isi, FLAG_SECURE pada V7 dan keputusan V2, logout/URI meminta login, wali kedua ringkas, pencabutan relasi di latar menutup isi, dua ketukan menjadi satu penerusan, serta pencabutan seluruh penugasan murobi menutup data dengan nol keputusan. Tidak membangun APK baru atau menguji cold start rilis. **Paritas native–web–API 2/0**: Android #2709 dan iOS #2708 sama-sama Diajukan pada ID yang sama (`paritas-native-web-api.txt`). Ini bukti emulator/simulator, bukan perangkat fisik.
- Pemindaian native Fase 5 (`privasi-native.txt`): Android **629 baris log/80 berkas data**, iOS **28.915 baris log/250 berkas data**; nol penanda alasan/nama/kata sandi sintetis yang diuji. Pola bearer mentah Android log/data dan iOS log nol. Berkas biner dipindai sebagai byte; snapshot/gambar OS tidak didekode ulang, sehingga ini bukan pembuktian visual seluruh cache. Raw log sementara di luar Git; kata sandi fixture disamarkan dari log langkah UI yang dikomit. Perlindungan Recents/snapshot visual Fase 4 tetap bukti historis, tidak diklaim diulang seluruhnya.

## Keputusan gerbang akhir

| Gerbang | Keputusan |
| --- | --- |
| Alat preservasi/restore lokal | Lulus setelah A-1/A-2; manifest privat wajib dibuat ulang |
| Alur utama, satu ID, versi/audit, benturan dan duplikasi pada fixture | Terbukti pada skenario lokal yang dijalankan; T-1 tetap risiko reliabilitas pengurus |
| Pencabutan cakupan murobi sebagian | **Terbuka (F5-1)**; penerimaan penuh hak V7 tidak dinyatakan lulus |
| Ketukan Push nyata setelah logout/pencabutan, foreground/background/cold start | **Belum diuji**; terbuka sejak Fase 4. Mock provider, in-app dan URI bukan Push nyata |
| Perangkat fisik Android/iOS, Android cold start rilis, iOS Debug | **Belum dibuktikan**; emulator/simulator dan binary lokal tidak menggantikannya |
| MySQL, MariaDB hosting/cPanel, smoke, cron, CHECK/binlog/jam DB dan backup hosting | **Belum diuji**; seluruh S-1…S-22 produksi tetap Menunggu pelaksanaan |
| UAT wali/pembimbing/murobi/admin dan keputusan operasi | **Belum dilaksanakan** |
| Prasyarat produksi V5/V6 dan inventaris deploy nyata | Tetap mengikuti gerbang masing-masing; V7 tidak menutup gerbang produksi V6 atau mengonfirmasi deploy |

P5-1…P5-5 implementator diterima sebagai batas bukti, dengan koreksi cakupan snapshot audit dan keputusan terbuka di atas. Baris pengurus/warisan berstatus diputus tanpa keputusan hanya dilaporkan verifier; bukan diubah atau dinyatakan konsisten oleh V7. Verifier lintas proses dan manifest lintas tabel harus dipakai tanpa penulis untuk preservasi; bukan satu snapshot global pada lalu lintas aktif. Enqueue yang gagal belum memiliki pemulihan otomatis dan pengiriman provider tepat-sekali saat respons hilang belum terbukti.

Commit/push hasil audit hanya pada kedua branch Fase 5. Ringkasan mobile di repo alhasanApps pada lokasi yang sama. **Human Developer menerima keputusan audit, menyelesaikan temuan/gerbang terbuka dan memutuskan merge/deploy/rilis. Auditor tidak menyatakan V7 siap produksi dan berhenti setelah handoff.**
