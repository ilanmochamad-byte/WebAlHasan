# Audit independen Codex — PRD V6 Fase 1

**Putusan 25 Oktober 2026: LULUS kriteria wajib Fase 1 setelah satu koreksi aplikasi dan satu koreksi tes statis.** Tidak ada temuan Fase 1 yang masih memblokir. Putusan ini bukan kelulusan V6 secara keseluruhan atau izin rilis parsial. Fase 2 belum dimulai; tidak merge, deploy, migrasi produksi, atau perubahan mobile.

## Konteks dan baseline

- Branch audit tetap `claude/prd-v6-fase-1`, tanpa worktree atau perpindahan checkout. Empat proses Claude masih terbuka pada direktori ini; **Human Developer mengonfirmasi bahwa seluruh pekerjaan Claude sudah berhenti sebelum auditor melakukan perubahan**. Auditor tidak menghentikan proses milik pengguna.
- Baseline web `761ae07fd41fd5833a2f43653567e0db80a643ff` (`origin/main`), memuat spesifikasi `477ad3cc4fac888934b96744f18990af1d9db9a3`; diff kedua commit kosong. Hash `main` dan branch fase diverifikasi langsung dengan `git ls-remote`.
- Seluruh commit fase yang diperiksa: `e4e36c193976886626949edd5f26bfde2d404e25` (aplikasi, migrasi, alat dan tes), `ea655dfcfac20fae61176615bd2fe340bd41b179` (bukti/handoff, K-1), `a5bb9ae9ae7639932028ab165a8d172f134dce57` (keputusan menu BE, titik awal audit).
- Dibaca: `AGENTS.md`, seluruh `PRD-V6.md`, prompt audit/implementasi, README dan bukti implementator, skema/fondasi penugasan, resolver capability, layanan/repository BSA, router API, seluruh halaman BSA, navigasi, AuditLogger, migrasi/rollback/preflight/verifier dan sumber tes fase.
- Mobile hanya dibaca: remote `main` `f8ebba0a43493efde50a7a207933e86dc4b941d4`; checkout `claude/aset-appstore-header` `c3e096c` dengan perubahan `package.json`/`package-lock.json` milik pekerjaan lain. Tidak diubah. Status audit V5 yang masih menyisakan gerbang rilis tidak diubah oleh audit V6 ini.
- Direktori belum terlacak `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, dan `output/` tidak disentuh atau dimasukkan commit.

## Temuan dan koreksi

### F-1 · P2 · Replay melewati pemeriksaan ulang cakupan/status santri — ditutup

`BsaService::mutasiTabungan()` dan `terbitkanTagihan()` memeriksa penugasan aktif dan klaim idempotensi, tetapi mengembalikan hasil yang sudah ada **sebelum** `santriDalamCakupan()`. Petugas yang penugasannya dipersempit dari seluruh unit menjadi Tsanawi masih dapat mengulang setoran, penarikan, dan tagihan SMP dengan kunci lama. Hal yang sama terjadi setelah santri dinonaktifkan. GET sudah menolak 404, tetapi replay layanan/API mengembalikan 200 beserta hasil lama (termasuk saldo sesudah transaksi), dan POST web mengembalikan 302. Tidak terjadi pembukuan uang kedua; masalahnya otorisasi hasil pada permintaan ulang (PRD §3.1, §5.2, Fase 1 persyaratan 4).

Probe auditor baru `tests/v6_phase1_audit.php` menggunakan sesi web dan token API yang dibuat sebelum perubahan hak. Kontrol pada kode implementator: **19 lulus / 18 gagal** (`audit-codex/probe-sebelum.txt`). Delapan belas kegagalan = dua keadaan × tiga operasi × layanan/API/web.

Koreksi terbatas pada kedua jalur layanan: pemeriksaan santri aktif dan cakupan terkunci dipindahkan sesudah klaim, sebelum cabang pengembalian replay. Urutan kunci penugasan → idempotensi → santri → akun tetap sama. Tidak mengubah kontrak hasil replay yang masih sah atau memperluas hak.

Probe yang sama sesudah koreksi: **37/0** (`audit-codex/probe-akhir.txt`). Seluruh replay di luar cakupan/nonaktif sekarang 404 tanpa data hasil; jumlah ledger/tagihan/komponen/klaim/audit serta saldo tetap. Hak dipulihkan → replay sah kembali; penugasan dicabut → 403 pada sesi/token lama. Integrasi/konkurensi 120/0 dan HTTP 104/0 dijalankan ulang sesudah koreksi.

### F-2 · Tes statis · Pola “migrasi lama ≤026” ikut memilih 027 — ditutup

Pada checkout implementator yang sudah di-commit, `tests/v6_phase1_static.php` menghasilkan **74/1**, berbeda dari bukti implementator 75/0. Glob `0[0-2]*.sql` mencakup 027 juga; pola ini bukan batas sampai 026. Verifikasi diff independen menunjukkan hanya pasangan 027 yang ditambahkan, tanpa perubahan migrasi/rollback lama.

Tes kini memakai pathspec Git yang dikutip dan mengecualikan **hanya** pasangan 027 fase ini. Seluruh migrasi/rollback lain tetap diperiksa terhadap baseline. Hasil akhir **75/0**. Oracle regresi historis lainnya tidak diubah atau dilonggarkan.

## Bukti audit yang dijalankan sendiri

Semua keluaran berikut dihasilkan auditor pada sesi ini dan disimpan di `audit-codex/`, terpisah dari `bukti/` implementator. Sumber suite implementator diperiksa, kemudian dijalankan ulang; hasil lamanya tidak disalin sebagai hasil audit. Lingkungan: PHP 8.4.14, MariaDB 12.3.2 lokal, Chromium Playwright. Data uji berasal dari salinan sandbox dengan fixture sintetis V1–V5 dan fixture V6; bukan salinan produksi atau pemeriksaan saldo nyata.

| Pengujian | Hasil akhir | Bukti |
| --- | --- | --- |
| Statis V6 + lint 25 berkas | **75/0** | `statis-akhir.txt`; kontrol 74/1 di `statis.txt` |
| Integrasi BSA, penugasan, nominal, idempotensi, konkurensi proses, CHECK, isolasi wali, preservasi | **120/0** | `integrasi-akhir.txt`; sebelum koreksi juga 120/0 di `integrasi.txt` |
| HTTP web/API: penugasan oleh admin, transaksi, saldo, hak, CSRF, sesi/token dicabut, privasi | **104/0** | `http-akhir.txt`; sebelum koreksi juga 104/0 di `http.txt` |
| Probe auditor: replay sesudah hak berubah, rollback atomik saat audit gagal, kontrol negatif verifier | **37/0** | `probe-akhir.txt`; kontrol 19/18 di `probe-sebelum.txt` |
| Migrasi/rollback/naik ulang, penjaga data, manifest dan preservasi | **29/0** | `migrasi-rollback.txt` |
| Browser BSA 375/768/1280px, klik ganda, tanpa JavaScript | **59/0** | `browser.txt`, `tangkapan/*.png` |
| Regresi kerangka/menu sembilan akun dan tiga lebar | **91/0** | `regresi/browser-kerangka.txt` |
| Fondasi penugasan | **161/0** | `regresi/penugasan-integrasi.txt` |
| V2 Fase 4 / Fase 5 integrasi (provider uji) | **124/0 · 143/0** | `regresi/v2-fase4-integrasi.txt`, `v2-fase5-integrasi.txt` |
| V5 Fase 3 / Fase 5 integrasi, termasuk konkurensi dan kegagalan transaksi | **82/0 · 95/0** | `regresi/v5-fase3-integrasi.txt`, `v5-fase5-integrasi.txt` |
| Seluruh 35 suite statis | **19 exit 0, 14 exit 1, 2 exit 77** | `regresi/statis-status.txt`, keluaran per suite; penjelasan di bawah |
| Kontrol V2 Fase 5 statis pada ekspor bersih mobile `origin/main` | **310/0** | `regresi/v2_phase5_static-mobile-main.txt` |

Auditor memeriksa visual tangkapan 375px rincian saldo, formulir setoran, formulir tagihan, dan rincian wali. Komponen/tabel menjadi kartu dan tidak meluber horizontal. Bilah bawah tetap melekat; isian/tombol yang berada di bawahnya terjangkau dengan menggulir. Pengujian tiga lebar dan tanpa JavaScript benar-benar dijalankan, bukan hanya meninjau tangkapan implementator.

## Penilaian kriteria wajib

| Kriteria PRD Fase 1 | Bukti dan putusan |
| --- | --- |
| Admin menugaskan BSA; satu tagihan, setoran Rp100.000, penarikan Rp30.000, saldo Rp70.000 di web/API | **Terpenuhi.** Integrasi I-2/I-3 dan HTTP H-A/H-C/H-D memeriksa penyimpanan melalui Pusat Penugasan, snapshot komponen, tepat satu tagihan, saldo DB/layanan/web/API yang sama. Tagihan tidak mendebit tabungan. |
| Dua setoran kunci sama → satu kredit; nominal berbeda ditolak | **Terpenuhi.** I-4/I-5, H-C/H-D dan probe auditor. Delapan proses dengan kunci/isi sama → satu kredit dan tujuh replay; enam proses kunci sama/nominal berbeda → satu sukses dan lima 409. |
| Dua penarikan Rp80.000 bersamaan atas Rp100.000 | **Terpenuhi.** I-5 memakai proses dan koneksi DB terpisah dengan aba-aba bersama: tepat satu sukses, satu 422, saldo Rp20.000. Delapan penarikan Rp30.000 atas Rp100.000 → tiga sukses, sisa Rp10.000. Tidak ada saldo negatif atau penolakan galat DB. H-E menguji kontrak HTTP; server PHP satu pekerja **bukan** bukti konkurensi DB. |
| Admin/wali tidak memutasi; tanpa penugasan/di luar unit/wali lain/sesi dicabut tertutup | **Terpenuhi setelah F-1.** I-1/I-6/I-7/I-8, H-B/H-D/H-F/H-G dan probe auditor. Termasuk admin yang juga ditugaskan BSA tetap hanya baca, guru/petugas kesehatan bukan BSA, santri tanpa kelas di luar petugas unit, ID tagihan ditebak, dan relasi wali dicabut pada sesi/token lama. |
| Migrasi/rollback mempertahankan data identitas, PSB, V1–V5; nol yatim baru | **Terpenuhi pada salinan sintetis.** Manifest membandingkan jumlah seluruh tabel lama (kecuali audit/migration bookkeeping), sidik jari ID santri/wali/santri_wali/users dan relasi; checksum 23 tabel inti serta struktur kolom tetap sesudah naik–turun–naik. Enam tabel baru, 22 FK, 14 CHECK, nol referensi yatim. Rollback kosong berhasil, rollback berdata ditolak preflight **dan** penjaga SQL sebelum tabel/data dilepas. |

Probe auditor juga memaksa INSERT audit gagal lewat trigger sintetis pada DB probe: setoran dan tagihan membatalkan ledger/saldo/snapshot/klaim secara atomik. Kontrol negatif menghilangkan sementara akun cermin pada DB probe; verifier menolaknya, lalu akun dipulihkan. Ini bukan koreksi kode verifier: implementasi yang ada sudah mendeteksi keadaan tersebut.

## Batas penafsiran dan regresi historis

- K-1 (penugasan BSA lintas semester) dan BE (menu Keuangan) adalah keputusan Human Developer yang sudah tercatat; auditor tidak membatalkannya. Tabel/capability bendahara lama dipertahankan dan perluasan satu penugasan diterima. Baris lama yang masih berlaku ikut menjadi BSA; wajib ditinjau operator sebelum rilis.
- P-3 unit melalui penempatan kelas aktif pada tahun ajaran aktif, P-4 admin+petugas tetap hanya baca, P-5 santri nonaktif hanya dibaca admin, P-6 setoran langsung sah oleh pencatat pada minimum Fase 1, P-7 komponen tagihan manual, P-8 batas Rp1 miliar, P-9 idempotensi per akun, P-10 DTO wali diterima untuk **Fase 1**. Tarif, pembayaran tagihan, verifikasi transfer terpisah, koreksi/pembalik dan penanganan saldo santri keluar tetap pekerjaan fase berikutnya. Penerimaan fondasi bukan klaim fitur itu sudah ada.
- Empat belas suite statis exit 1 tidak disembunyikan. Sumber/diff diperiksa: oracle “tujuh jenis” penugasan sudah tidak cocok sejak V5; larangan pratinjau AG sudah tidak cocok sejak Tahap 8; oracle “tidak mengubah database/API/bin sejak tahap lama” dan “024/025/026 terakhir” tidak cocok dengan migrasi/katalog/API BSA yang memang diwajibkan. Tahap 2 masih mengharapkan tujuh grup admin, Tahap 6 mengharapkan BSA sebagai modul mendatang, dan V5 Fase 1 masih melarang koreksi yang sudah hadir di Fase 2. Ini tidak diperlakukan sebagai seluruh regresi hijau.
- V2 statis Fase 4 masih mengunci literal deep-link mobile lama. V2 statis Fase 5 gagal tiga versi pada checkout mobile milik pekerjaan lain; ekspor bersih `origin/main` tanpa mengubah checkout menghasilkan 310/0. V3 statis Fase 2/3 exit 77 karena syarat lingkungan/DB bernama tetap tidak dipenuhi. Tidak ada pengubahan oracle tersebut.
- Bukan audit ulang lengkap V1–V5/PSB. HTTP keamanan PSB dengan Apache/`.htaccess`, seluruh browser V3/V4/V5, suite DB V3/V4 bernama tetap, HTTP redesain Tahap 2–8, serta drill rilis/restore V5 tidak dijalankan ulang dalam audit ini. Kode bisnis Auth/PSB/Kesehatan/V3/V4/Izin/MasterData/Notification tidak berubah terhadap baseline, dan regresi yang relevan dengan katalog/AuditLogger/navigasi dijalankan di atas.

## Batas operasional dan handoff

1. Hanya MariaDB lokal 12.3.2 diuji; **MySQL dan cPanel belum diuji**. Versi CHECK yang ditegakkan, InnoDB, READ COMMITTED, kunci baca, konfigurasi binlog dan izin operator hosting harus diperiksa melalui preflight sebelum rilis kelak. Pengujian konkurensi membuktikan interleaving yang dicoba, bukan seluruh kemungkinan.
2. Tidak membaca atau memigrasi data produksi, tidak menggunakan rekening/bukti dana nyata. Preservasi dibuktikan atas data sintetis salinan sandbox. Referensi transfer yang diterima aplikasi bukan bukti dana bank benar-benar masuk.
3. Tidak ada perubahan atau uji runtime Android/iOS, perangkat fisik, Push nyata, UAT atau rilis V6. Batas V5 yang sudah terbuka tetap terbuka.
4. **Jangan deploy branch ini sebagai rilis V6 Fase 1.** Belum ada sakelar rilis; merge ke cabang yang dideploy dan `migrate up` akan memasang 027 serta menampilkan menu Keuangan, termasuk bagi penugasan bendahara lama. Tagihan dan tabungan hanya boleh dirilis bersama setelah gerbang Fase 5 serta keputusan Human Developer.
5. Bukti audit dan langkah ulang ada di direktori ini. Hasil audit di-commit dan di-push ke branch fase yang sama; auditor berhenti. Fase 2 hanya melalui instruksi baru Human Developer, tanpa mengubah status V5 atau membuat keputusan merge/deploy.
