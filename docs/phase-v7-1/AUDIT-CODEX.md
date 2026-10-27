# Audit Codex — PRD V7 Fase 1

27 Oktober 2026. **LULUS audit teknis lokal Fase 1 setelah empat koreksi terarah auditor.** Lima kriteria penerimaan Fase 1 terbukti pada lingkungan yang diuji. Ini bukan kelulusan seluruh V7 atau izin rilis parsial. Fase 2 belum dimulai; tidak merge, deploy, migrasi produksi, atau mengubah aplikasi mobile. Gerbang akhir/produksi V6 tetap terbuka.

## Lingkup dan baseline

- Branch tetap `claude/prd-v7-fase-1`, tanpa worktree. Baseline web `3e785645bc412b7bc95b1e05152f3ed0508a6a58`; isi spesifikasi `ee8a3ff4f7e6ac97d64607d268d2e48c9c13859e` identik. Seluruh commit fase diperiksa: implementasi `b622154cf32ebb9aa17f9f311514849b2f1078e0` dan handoff `974e1c5534b9ec473f29c534ec583cab2bc7fa23`. Ketiga ref remote cocok saat audit dimulai.
- Dibaca AGENTS, PRD V2/V7, prompt Fase 1, audit V6 Fase 5, README dan bukti implementator, seluruh diff aplikasi/migrasi/alat/uji fase, serta fondasi otorisasi, penugasan, idempotensi dan alur izin V2. Handoff menyatakan Claude berhenti; tidak menjalankan Claude atau agen paralel. Proses aplikasi Claude yang masih terbuka tidak diperlakukan sebagai bukti implementasi masih berjalan.
- Mobile baca-saja: `claude/prd-v6-fase-5` pada `68a412108fc3b88b23b9b4c181269a4f10ffe9b7`, sama dengan remote. Perubahan `package.json`/`package-lock.json` milik pekerjaan lain dipertahankan. Tiga direktori web belum terlacak milik pekerjaan lain tidak disentuh atau dimasukkan commit.
- PHP 8.4.14, MariaDB 12.3.2 lokal dengan `innodb_snapshot_isolation=ON`, Node 26.7.0, Chromium Playwright. Database hanya salinan sandbox berfixture sintetis `test_*_test`; bukti auditor terpisah di `audit-codex/`. Tidak mengakses data atau layanan produksi.

## Temuan dan koreksi

### F-1 · P2 · Admin dicabut saat menunggu kunci tetap menetapkan — ditutup

`tetapkanPembimbing()` memeriksa admin sebelum transaksi, kemudian menunggu kunci akun. Koneksi lain menonaktifkan akun dan commit sebelum kunci diperoleh; permintaan lama tetap menghasilkan HTTP 200 dan penetapan. Koreksi memeriksa ulang hak admin setelah mengunci akun, sebelum klaim idempotensi maupun replay. Probe yang sama sekarang menghasilkan 403 dengan versi tetap 1 dan tanpa penetapan.

### F-2 · P2 · Penugasan berakhir saat menunggu kunci tetap dipilih — ditutup

Daftar kandidat dibaca sebelum kunci penugasan. Pembacaan terkunci berikutnya hanya memeriksa aktif/arsip; perubahan tanggal akhir menjadi kemarin tetap diterima, walaupun pembimbing sudah tidak sah. Koreksi menghitung ulang kelayakan setelah kunci diperoleh, dengan READ COMMITTED, dan menuntut penugasan yang dikunci tetap kandidat sah. Probe berubah dari HTTP 200 menjadi 422 tanpa penetapan. Kunci baris penugasan tetap ditahan hingga transaksi selesai.

### F-3 · P2 · Manifest terpotong dan perubahan isi riwayat lolos verifier — ditutup

Manifest dengan kedua daftar kosong, satu jumlah tabel hilang, atau satu hash hilang tetap lulus karena verifier hanya membandingkan entri yang masih ada. Mengubah isi satu riwayat V2 tanpa mengubah ID/jumlah juga tidak terdeteksi. Koreksi menuntut daftar tabel/hash lengkap, bentuk jumlah/hash sah, serta menambah sidik jari isi seluruh kolom riwayat status. Keempat kontrol negatif sekarang ditolak. Manifest sebelum koreksi perlu dibuat ulang karena belum memuat hash riwayat yang diwajibkan.

### F-4 · P2 · Preflight menerima CHECK MariaDB OFF — ditutup

Versi server yang mendukung CHECK bukan bukti CHECK sedang ditegakkan. Koneksi subprocess dengan `check_constraint_checks=OFF` masih lulus preflight asli. Preflight naik dan rollback kini memeriksa CHECK sesi serta default global MariaDB. Probe hanya mengubah sesi subprocess sendiri; pengaturan global dan koneksi induk tidak diubah. MySQL tidak diberi kueri variabel khusus MariaDB.

Sumber probe: `tests/v7_phase1_audit.php`. Kontrol pada arsip sumber handoff asli `974e1c5`: **6/7** (`probe-kontrol-valid.txt`); sumber terkoreksi: **13/0** (`probe-akhir.txt`), tanpa mengubah harapan probe. Kedua balapan memakai proses/koneksi terpisah dan menunggu sampai SQL benar-benar tertahan di kunci, lalu melakukan pencabutan dan commit. Pemeriksaan mutasi fixture memastikan tanggal baru memang tersimpan.

Diagnostik awal disimpan di `audit-codex/diagnostik/`: tanggal akhir percobaan pertama melanggar CHECK rentang sehingga belum membuktikan pencabutan. Bukti F-2 memakai kontrol valid bertanggal kemarin yang diterima CHECK, bukan percobaan awal itu.

## Bukti independen

`perintah-audit.sh` menjalankan rangkaian utama berurutan, setiap suite mutatif mendapat salinan fixture sendiri. Uji tambahan warisan memakai salinan 029 dengan tiga status V1, ID kanonis yang sama, pelaku NULL, dan riwayat migrasi yang benar-benar berisi. Tidak ada oracle lama yang dilonggarkan.

| Pemeriksaan | Hasil akhir | Bukti dalam `audit-codex/` |
| --- | --- | --- |
| Statis Fase 1 + lint sumber PHP baru/berubah | 49/0 | `statis-akhir.txt` |
| Integrasi layanan, CHECK, retry, konkurensi, otorisasi dan invarians | 75/0 | `integrasi-akhir.txt` |
| Probe empat temuan, termasuk dua balapan terkendali | 13/0 | `probe-akhir.txt` |
| HTTP website/API dan pencabutan pada sesi/token lama | 71/0 | `http-akhir.txt` |
| Migrasi 030 naik–turun–naik, preservasi, idempoten, rollback berisi ditolak | 25/0 | `migrasi-akhir.txt` |
| Siklus yang sama dengan perizinan warisan benar-benar berisi | 26/0 | `migrasi-warisan.txt` |
| Browser 375/768/1280px, klik ganda, tanpa JS, penetapan admin | 39/0 | `browser.txt`, `tangkapan/` |
| Regresi penugasan | 161/0 | `regresi/penugasan.txt` |
| V2 integrasi Fase 1 / 2 / 4 / 5 | 39/0 · 94/0 · 124/0 · 143/0 | `regresi/v2-fase*-integrasi.txt` |
| V2 konkurensi Fase 4 | 20/0 | `regresi/v2-fase4-konkurensi.txt` |
| V2 web Fase 2 / 4 / 5 | 36/0 · 46/0 · 79/0 | `regresi/v2-fase*-web.txt` |
| V2 API Fase 5 | 150/0 | `regresi/v2-fase5-api.txt` |
| HTTP redesain Tahap 4: perizinan, laporan, pengajian dan cetak | 132/0 | `regresi/redesain-tahap4-http.txt` |
| Verifier V7 sesudah enam kelompok regresi V2 | masing-masing 34/0 | `regresi/verifier-reg*.txt` |

Pembuktian kriteria Fase 1:

1. **Satu identitas dan routing:** wali sah membuat/membaca di web/API; tepat satu pembimbing sah melihat antrean. `murobi_guru_id` tetap NULL, tidak ada keputusan, tidak ada baris tahap pembimbing di daftar/detail/antrean/laporan V2. Pembimbing lain tidak membaca detail.
2. **Retry dan benturan:** delapan proses wali berkunci/isi sama semuanya berhasil dengan satu ID/baris; isi berbeda berkunci sama ditolak 409. Empat wali + empat pengurus dengan rentang bersinggungan menghasilkan satu sukses dan tujuh 409. Dua akun wali yang berlomba juga menghasilkan satu pengajuan aktif. Batas tanggal inklusif, kedua arah lintas jalur, dan pelepasan rentang V2 sesudah batal diuji.
3. **Isolasi wali:** buat/daftar/detail/ID tebakan untuk wali asing/dicabut ditolak atau kosong. Wali kedua berelasi menerima DTO ringkas tanpa alasan, catatan, versi, riwayat, atau tindakan. Browser hasil auditor juga diperiksa visual, termasuk rincian wali kedua 375px dan admin 1280px.
4. **Nol/dua kandidat:** tetap antrean admin dan nol di murobi; pengurus tanpa akun aktif tidak dihitung sah. Admin hanya memilih pembimbing berpenugasan aktif yang cocok; versi lama, kunci konflik dan non-admin ditolak. Hak dicabut langsung menutup sesi/token lama. F-1/F-2 melengkapi pemeriksaan saat penetapan sudah menunggu kunci.
5. **Preservasi:** salinan awal memuat 1.052 pengajuan V2 dan 551 keputusan; tambahan drill warisan memuat 1.055 pengajuan, tiga `perizinan`, tiga pengajuan `is_legacy` dan riwayatnya. Hash isi 24 tabel inti serta checksum 18 tabel tanpa generated column sama sesudah migrasi/rollback; struktur kolom/constraint kembali sama. ID dan isi relasi santri–wali tetap, tanpa referensi yatim. Sesudah rollback V2 masih membuat pengajuan dan V7 menjawab 503. Rollback sesudah data wali ditolak sebelum perubahan skema dan 030 tetap tercatat.

## Kegagalan historis dan temuan terbuka

- **V2 API Fase 3 95/21 dan Fase 4 91/1.** Diuji ulang pada arsip sumber baseline `3e78564` dengan salinan fixture identik yang dilepas 030: **95/21 dan 91/1, daftar kegagalan identik** (`regresi/baseline-v2-fase*-api.txt`). Fixture gabungan menghasilkan routing/kandidat yang berbeda dari harapan suite lama. Ini tidak dinyatakan lulus dan bukan regresi baru V7.
- **T-1 pengurus V2, sudah ada:** delapan proses berkunci sama menghasilkan dua sukses/replay dan enam 500, tetapi tepat satu ID/baris (`regresi/v2-retry-kunci-sama.txt`). `IzinWorkflowService` dan `IzinIdempotency` identik dengan baseline; koreksi V7 tidak mengubahnya. Ini tetap risiko reliabilitas jalur lama dan perlu tindak lanjut tersendiri. Retry V7 lulus; temuan lama ini tidak meniadakan bukti identitas tunggal atau menjadi alasan mengklaim semua regresi V2 bersih.
- **Seluruh 40 statis:** 18 exit 0, 20 exit 1, dua exit 77. Kegagalan historis/pembatasan fase tetap disimpan di `statis/`, bukan diubah agar hijau. Dibanding bukti implementator, hitungan sama kecuali V6 Fase 3 kini 54/3 (sebelumnya 55/2): oracle melarang perubahan migrasi selain 029, sedangkan migrasi 030 sekarang sudah terlacak Git. Peta kegagalan lain dan alasan oracle historis tetap mengikuti README implementator. Dua suite V3 memerlukan DB khusus dan keluar 77.
- Suite V2 Fase 5 memunculkan deprecation PHP 8.4 dari `str_getcsv()` pada kode pembantu tes lama (bukan galat layanan). Stdout mentah dipertahankan. Log server HTTP/browser V7 tidak memuat galat PHP; pemindaian bukti teks auditor tidak menemukan bearer atau token JSON mentah sesuai pola yang diuji.
- Penafsiran P-1…P-11 implementator diterima untuk Fase 1: perluasan ENUM di ujung, kandidat unik per pengurus dengan akun aktif, status V2 sebagai batas kompatibilitas sementara, tanggal lampau mengikuti V2, rincian wali kedua ringkas, dan belum ada tindakan/notifikasi Fase 2–3. P-8 tetap dinilai sebagai antrean admin yang dihitung saat membaca, bukan perubahan status/audit routing otomatis.

## Batas dan handoff

- Lokal hanya MariaDB 12.3.2; MySQL 8, cPanel/hosting, binlog dan perilaku ALTER pada server sasaran belum diuji. Preflight CHECK tidak menggantikan uji hosting. Tidak ada perubahan skema SQL selama koreksi auditor.
- Konkurensi nyata dibuktikan lewat proses/koneksi terpisah; server HTTP PHP bawaan melayani berurutan. Bukti bukan pembuktian seluruh kemungkinan interleaving. Perubahan dependensi lain sesudah pemeriksaan terakhir tetap perlu ditangani tahap lanjutan; dua celah menunggu kunci yang direproduksi di atas sudah ditutup.
- Manifest harus diambil/dibandingkan pada jendela tanpa penulis, dalam database salinan yang sama. Snapshot hitungan/hash alat lintas tabel/proses bukan satu snapshot global. Hash riwayat yang baru diwajibkan melindungi drill migrasi, bukan untuk membandingkan manifest lama sesudah aktivitas bisnis normal.
- PSB Apache/keamanan hosting, suite DB V3/V4/V5/V6, browser kerangka sembilan akun dan aplikasi native tidak dijalankan ulang oleh auditor Fase 1 ini. Tidak mengklaim bukti implementator sebagai eksekusi independen. Kode modul tersebut tidak berubah terhadap baseline fase.
- Notifikasi, tinjauan/perbaikan/pembatalan wali, penerusan dan keputusan akhir, laporan V7, serta paritas mobile belum termasuk Fase 1. Tab/formulir setelah 030 terpasang akan menerima pengajuan yang belum dapat diteruskan sampai Fase 2; tetap **tidak boleh dianggap siap rilis parsial**. Ketidakcocokan alat rilis V6 yang masih mengasumsikan 029 terakhir tetap terbuka seperti README, tanpa mengubah gerbang produksi V6.

Commit/push koreksi, pengujian dan laporan pada branch yang sama, lalu berhenti. Fase 2 hanya dimulai oleh implementator yang ditetapkan sesudah instruksi baru Human Developer. Keputusan merge/deploy/rilis tetap di tangan Human Developer.
