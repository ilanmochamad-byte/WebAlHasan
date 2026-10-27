# Audit Codex — PRD V7 Fase 2

27 Oktober 2026. **LULUS audit teknis lokal Fase 2 setelah tiga koreksi terarah auditor.** Kelima kriteria penerimaan Fase 2 terbukti pada lingkungan yang diuji. Audit hanya pada `claude/prd-v7-fase-2`; bukan izin merge, deploy, migrasi produksi, rilis aplikasi, atau memulai Fase 3. Gerbang akhir/produksi V6 tetap terbuka.

## Lingkup dan baseline

- Baseline web audit Fase 1 `192711e0b6bc2cadd46ffc79ee5dfa921f3a76d1`; implementasi Fase 2 `8b0d958fc836c5844f588099018dcbd156e03527`, handoff `f25ef186d0f09ba6c1f4b05b9449eb7053e38c1c`. Ketiga hash cocok dengan remote saat audit dimulai. `origin/main` saat itu `ac4c4b944e4c19ee7745e22372a04d045287de63`, dengan isi identik baseline audit Fase 1. Seluruh commit Fase 2 diperiksa, termasuk perubahan layanan, repositori, web/API, migrasi 031, alat operasi, uji dan dokumentasi.
- Dibaca `AGENTS.md`, `PRD-V7.md`, prompt audit Fase 2, audit Fase 1, README/bukti implementator dan fondasi V2 yang dipakai kembali. Handoff menyatakan implementator berhenti; tidak menjalankan Claude maupun agen paralel. Tanpa worktree dan tidak menulis pada `main`.
- Mobile hanya diverifikasi baca-saja: `claude/prd-v6-fase-5` pada `68a412108fc3b88b23b9b4c181269a4f10ffe9b7`. Perubahan `package.json`/`package-lock.json` milik pekerjaan lain dipertahankan. Direktori web belum terlacak `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/` tidak disentuh/dimasukkan commit.
- PHP 8.4.14, MariaDB 12.3.2 lokal dengan `innodb_snapshot_isolation=ON`, Node 26.7.0, Chromium Playwright. Seluruh mutasi hanya pada salinan `test_*_test` berfixture sintetis. Bukti independen disimpan di `audit-codex/`, terpisah dari bukti implementator.

## Temuan dan koreksi terarah

Ketiga temuan berikut ada pada jalur keputusan/penetapan/koreksi V2 yang dipakai pengajuan wali sesudah penerusan. Koreksi dibatasi pada baris **asal Wali**; jalur pengurus mempertahankan protokol V2. Tidak ada perubahan SQL migrasi, skema, notifikasi, identitas pengajuan atau kode mobile.

### F-1 · P2 · Hak pemroses dicabut ketika menunggu tetap menghasilkan mutasi — ditutup

Pemeriksaan hak murobi/admin dilakukan sebelum transaksi. Bila permintaan sudah tertahan pada kunci, pencabutan dapat commit lebih dahulu, tetapi keputusan, penetapan murobi, atau koreksi masih tersimpan dengan HTTP 200. Probe menahan baris pengajuan atau akun, menunggu SQL worker benar-benar tertahan, memastikan UPDATE pencabutan berhasil, kemudian commit. Sesi/identitas awal worker tetap sama.

Koreksi mengunci akun pemroses lebih dahulu, membaca kembali role, guru tertaut dan kemampuan dari DB, lalu mengulang pemeriksaan setelah kunci pengajuan diperoleh dan sebelum replay. Kapasitas admin/murobi yang diminta harus masih sah; murobi juga harus tetap tujuan pengajuan. Transaksi pengajuan wali memakai READ COMMITTED agar pencabutan saat menunggu terbaca di MariaDB. Empat kontrol (keputusan murobi, keputusan admin pengganti, penetapan, koreksi) kini 403 tanpa perubahan isi/versi/keputusan/riwayat.

### F-2 · P2 · Versi tidak wajib dan tidak ikut sidik request pada mutasi V2 pengajuan wali — ditutup

Menghilangkan versi dari keputusan, penetapan murobi atau koreksi tetap diterima karena V2 memperbolehkan versi NULL. Sesudah mutasi sukses, kunci yang sama dengan versi diubah menjadi 999 masih menghasilkan 200/replay karena versi tidak masuk payload idempotensi. Keduanya melanggar PRD V7 §5.3.

Untuk pengajuan wali, ketiga tindakan kini mewajibkan versi positif (tanpa versi → 422) dan memasukkan versi ke sidik request (kunci sama, versi berbeda → 409). Retry dengan kunci, isi dan versi semula tetap mengembalikan hasil tersimpan. Tidak mengubah kontrak historis pengurus V2.

### F-3 · P2 · Retry keputusan serentak menghasilkan sebagian 500 — ditutup untuk pengajuan wali

Delapan proses keputusan berkunci sama pada pengajuan wali menghasilkan dua sukses dan enam 500 pada kontrol asli, dengan satu keputusan efektif. Pembatalan yang dibalapkan dengan retry keputusan juga menghasilkan 500 pada sebagian worker. Ini perluasan masalah klaim idempotensi V2 yang sebelumnya hanya dicatat sebagai T-1 pada pembuatan pengurus; sekarang menyentuh kriteria retry/konkurensi Fase 2 V7.

Kunci akun sebelum klaim idempotensi menserialkan retry pemroses V7. Tiga putaran masing-masing delapan retry kini semuanya 200, dengan satu keputusan dan satu kenaikan versi. Tiga putaran batal versus keputusan dengan empat retry per kubu, ditambah retry kedua hasil, hanya menghasilkan 200/409: satu terminal `Disetujui` dengan satu keputusan atau `Dibatalkan` dengan nol keputusan. Kedua cabang teramati; tidak ada 500.

Sumber: `tests/v7_phase2_audit.php`. Probe final **identik** dijalankan berurutan pada arsip sumber handoff `f25ef18` (**18 lulus/16 gagal**) dan sumber terkoreksi (**34/0**), menggunakan database fixture yang sama dengan tanggal/kunci unik per putaran. Bukti `probe-kontrol-final-asli.txt`, `probe-akhir.txt`; verifier sesudah seluruh kontrol/probe di `verifier-sesudah-probe.txt`.

Accessor `IzinWriteRepository::db()` hanya mengembalikan koneksi transaksi yang sama. Daftar berkas yang diizinkan pada **uji statis Fase 2** ditambah tepat repositori ini untuk koreksi tersebut; SQL V2 tidak berubah. Tidak melonggarkan oracle perilaku atau mengubah uji/alat Fase 1.

### Kontrol diagnostik yang tidak dipakai sebagai kelulusan

Disimpan di `audit-codex/diagnostik/`. Probe awal memakai fragmen pengamatan SQL yang tidak cocok; probe lain mencoba mencabut akun sesudah worker terkoreksi sudah memegang kunci akun, atau relasi tanpa lebih dahulu mengunci induk FK wali, sehingga UPDATE kontrol menjadi korban deadlock. Kasus ketika pencabutan gagal **tidak** membuktikan bypass maupun keberhasilan pengaman. Probe final memegang kunci induk lebih dahulu, menerima lokasi tunggu akun/FK idempotensi/pengajuan yang sesuai sumber, serta mewajibkan UPDATE pencabutan berhasil sebelum commit. Ekspektasi 403/404 dan tanpa mutasi tidak diubah.

## Pemetaan kriteria penerimaan

1. **Perbaikan hanya wali pengaju:** catatan minta perbaikan terlihat di web/API; perbaikan hanya saat diminta; isi sebelum/sesudah, versi dan pengirim tercatat. Wali kedua yang berelasi hanya menerima proyeksi ringkas tanpa alasan/catatan/tinjauan/keputusan/tindakan. Wali lain/asing, pembimbing dan admin tidak dapat memperbaiki atau membatalkan milik pengaju. Daftar, detail, laporan/CSV V2 orang tua menyaring baris wali agar isi tidak bocor lewat jalur lama.
2. **Penolakan pembimbing:** `Ditolak Pembimbing` terminal dan dibedakan dari `Ditolak` murobi, tanpa `izin_keputusan`; rentang dilepas dan pengajuan baru kedua jalur dapat memakai tanggal itu. Pembimbing tidak dapat meneruskan/menolak ulang sesudah terminal.
3. **Penerusan satu identitas:** tepat satu baris dan ID yang sama masuk antrean murobi; nol/dua kandidat masuk satu antrean admin tanpa ID baru. Penetapan murobi dan admin pengganti beralasan tetap memakai V2; guru tanpa penugasan atau murobi bukan tujuan ditolak 403 tanpa keputusan. Admin tidak mengambil alih tinjauan pembimbing.
4. **Pembatalan, versi dan retry:** wali pengaju membatalkan beralasan dari seluruh tahap sebelum keputusan, termasuk sesudah penerusan; setelah keputusan/penolakan pembimbing ditolak. Versi basi dan konflik kunci tidak mengubah keadaan. Balapan proses/koneksi terpisah menguji batal–teruskan, batal–keputusan, perbaikan–batal, keputusan murobi–admin serta retry. Probe tambahan melanjutkan hasil balapan penerusan sampai terminal, lalu mengulang kiriman lama: tetap `Dibatalkan`, nol keputusan, tanpa penerusan tambahan. Replay sukses lama dapat mengembalikan respons transisi lamanya sesuai kontrak idempotensi; status DB/detail tetap terkini.
5. **Pencabutan dan regresi:** sesi web/token lama kehilangan akses setelah penugasan, akun atau relasi dicabut. Probe terkontrol juga mencabut pembimbing ketika menunggu kunci penugasan dan relasi ketika menunggu kunci wali: 404 tanpa mutasi. Jalur pengurus V2 membuat langsung ke murobi, membatalkan sendiri, memutus, dikoreksi admin dan tetap memancarkan notifikasi V2.

## Bukti independen

`perintah-audit.sh` dan `perintah-regresi-audit.sh` menjalankan suite secara berurutan; rantai penyiapan memakai DB perantara bernama tetap, sehingga jangan menjalankan keduanya bersamaan. Setiap suite mutatif mendapat salinan fixture tersendiri. Server HTTP PHP bawaan melayani berurutan; pembuktian konkurensi memakai proses/koneksi DB terpisah.

| Pemeriksaan | Hasil | Bukti di `audit-codex/` |
| --- | --- | --- |
| Statis Fase 2 dan lint 23 PHP baru/berubah | 54/0 | `statis.txt` |
| Integrasi Fase 2, CHECK, konkurensi dan invarians | 119/0 | `integrasi.txt` |
| HTTP web/API, CSRF, akses, versi dan pencabutan sesi/token lama | 61/0 | `http.txt` |
| Migrasi 031 naik–turun–naik dan preservasi | 27/0 | `migrasi-rollback.txt` |
| Browser 375/768/1280px, klik ganda, tanpa JS | 33/0 | `browser.txt`, `tangkapan/` |
| Probe auditor pada sumber asli → koreksi | 18/16 → 34/0 | `probe-kontrol-final-asli.txt`, `probe-akhir.txt` |
| Verifier Fase 2 sesudah probe dan browser | masing-masing 41/0 | `verifier-sesudah-*.txt` |

Tangkapan browser auditor diperiksa visual: rincian pembimbing/perbaikan 375px, penolakan pembimbing 768px, keputusan wali dan rincian V2 murobi 1280px. Tidak ada koreksi tampilan tambahan.

## Regresi dan oracle historis

| Pemeriksaan | Hasil independen | Bukti di `audit-codex/regresi/` |
| --- | --- | --- |
| Penugasan | 161/0 | `penugasan-integrasi.txt` |
| V2 Fase 1 / 2 / 4 / 5 integrasi | 39/0 · 94/0 · 124/0 · 145/0 | `v2-fase*-integrasi.txt` |
| V2 Fase 4 konkurensi | 20/0 | `v2-fase4-konkurensi.txt` |
| V2 web Fase 2 / 4 / 5 | 36/0 · 46/0 · 79/0 | `v2-fase*-web.txt` |
| V2 API Fase 5 | 150/0 | `v2-fase5-api.txt` |
| Enam verifier Fase 2 sesudah kelompok regresi | masing-masing 41/0 | `v7-fase2-verify-sesudah-reg*.txt` |
| V7 Fase 1 HTTP / browser / probe audit Fase 1 | 71/0 · 39/0 · 13/0 | `v7-fase1-http.txt`, `v7-fase1-browser.txt`, `v7-fase1-probe-audit-codex.txt` |
| V6 Fase 5 integrasi / HTTP | 71/0 · 42/0 | `v6-fase5-*.txt` |
| Redesain HTTP Tahap 4 (izin/pengajian/cetak) / 5 / 7 | 132/0 · 106/0 · 122/0 | `redesain-tahap*-http.txt` |
| Rollback kosong 031…024 dan HTTP Tahap 6 | 44/0 · 132/0 | `redesain-tahap6-*.txt` |
| Browser kerangka sembilan akun, tiga lebar | 91/0 | `browser-kerangka-sembilan-akun.txt` |
| Seluruh 41 suite statis | 18 exit 0 · 21 exit 1 · 2 exit 77 | `statis-*.txt`, `ringkasan.txt` |

Kegagalan berikut **tidak** dinyatakan lulus atau dihapus:

- **V2 API Fase 3 95/21 dan Fase 4 91/1**, dengan daftar kegagalan sama seperti handoff dan kontrol sumber baseline yang sudah diuji pada audit Fase 1 (`docs/phase-v7-1/AUDIT-CODEX.md`). Fixture gabungan mengubah jumlah kandidat/routing dibanding harapan suite lama. Kontrol baseline terdahulu tidak dijalankan ulang pada audit Fase 2 ini; rangkaian pada sumber final dieksekusi independen dan hasilnya tetap sama.
- **V7 Fase 1 integrasi 74/1** menuntut wali belum mempunyai tindakan Fase 2. Perilaku baru `{perbaiki, batalkan}` diwajibkan PRD; wali kedua tetap tanpa tindakan. Uji Fase 1 tidak diubah.
- **Migrasi Fase 1**: ringkasan suite utama 18/7, dan probe warisan tetap mempertahankan tiga ID/nilai bisnis/pelaku NULL. Penghitung seluruh keluaran termasuk verifier/subprocess adalah 86/21 dan 87/21. Suite mengasumsikan `migrate up` hanya sampai 030 dan rollback pertama melepas 030; sekarang 031 ikut dipasang. Daftar gagal identik dengan bukti implementator; kontrol baseline 192711e 25/0 dan warisan 26/0 tersedia dari implementator, bukan diklaim sebagai eksekusi baru auditor. Preservasi 030→031 serta rollback berpenjaga diuji independen oleh suite Fase 2.
- **V6 alat rilis 14/21**, **HTTP redesain Tahap 2 185/6 dan Tahap 3 166/1** tetap sama seperti bukti Fase 1/Fase 2: asumsi migrasi terakhir 029, menu lama dan penugasan AG. Alat rilis V6 belum boleh dipakai tanpa penyesuaian gerbang V7; tidak mengubah status produksi V6.
- Suite V2 Fase 5 mengeluarkan deprecation PHP 8.4 dari `str_getcsv()` di pembantu tes lama. Stdout mentah tetap disimpan. Hasil HTTP/browser V7 dan pemindaian token dicatat terpisah.
- Hitungan dan exit seluruh 41 statis **identik** dengan handoff Fase 2. Antara lain `v7_phase1_static` 46/3 (asumsi 030 terakhir/daftar berkas/proyeksi teks Fase 1), V6 Fase 3 54/3 (migrasi tambahan), serta dua suite V3 exit 77 (memerlukan DB khusus). Oracle historis tetap; statis Fase 2 54/0 termasuk 23 PHP lulus lint. `git diff --check` dan `bash -n` kedua runner final lulus. Pemindaian 92 berkas bukti teks tidak menemukan pola bearer, access/refresh token atau password mentah yang diuji; bukan klaim seluruh kemungkinan data sensitif telah dipindai. Log HTTP/browser V7 tanpa galat PHP atau isi izin menurut pemeriksaan suite.

Runner regresi pertama berhenti setelah HTTP Tahap 7 karena auditor mengubah komentar runner ketika bash masih membacanya. Berkas final lulus `bash -n`; sisa Tahap 6, browser kerangka dan statis dilanjutkan berurutan dengan fungsi/output yang sama, tanpa mengosongkan hasil yang telah selesai. Diagnostik di `diagnostik-runner-regresi.txt`; ini bukan kegagalan aplikasi.

## Penafsiran dan batas

- P-1…P-10 README diterima **untuk Fase 2**: status V2 sesudah diteruskan tetap pada ID yang sama; penolakan pembimbing merupakan tinjauan; perbaikan wajib mengubah isi; pembimbing tujuan dipertahankan saat kirim ulang dan admin menangani pengecualian; catatan penerusan untuk petugas; orang tua membaca baris wali melalui V7; notifikasi V7 serta laporan lintas asal menunggu Fase 3. Admin tidak dapat membatalkan milik wali. Pengamanan replay juga diterapkan ke pemroses V2 atas baris wali melalui koreksi audit.
- Fase ini **belum boleh dianggap rilis parsial**: alur dapat berjalan tanpa notifikasi V7 dan tanpa layar mobile. Tautan/status/tindakan fase mendatang tidak diimplementasikan auditor.
- Hanya MariaDB lokal yang diuji. MySQL 8, cPanel/hosting, binlog server sasaran, produksi, perangkat Android/iOS, Push nyata dan UAT tidak diuji pada audit ini. Preflight bukan bukti hosting lulus.
- Konkurensi membuktikan interleaving yang benar-benar dieksekusi, termasuk penundaan bergantian dan penantian kunci teramati; bukan bukti semua kemungkinan interleaving. Pencabutan harus benar-benar commit; penulis yang menunggu kunci milik transaksi sah belum dihitung sebagai pencabutan selesai.
- Manifest jumlah/hash harus diambil dan dibandingkan pada jendela tanpa penulis, di salinan DB yang sama. Alat hash lintas tabel bukan snapshot global dan tidak untuk membandingkan sebelum/sesudah aktivitas bisnis normal. Pemulihan setelah ada data Fase 2 ditolak penjaga SQL; gunakan backup atau migrasi korektif. Tidak menjalankan pemulihan/migrasi produksi.
- T-1 pembuatan/retry pengurus V2 tetap temuan lama terpisah; koreksi audit hanya memasang pengaman baru pada mutasi **baris wali** yang sekarang memakai layanan V2. Tidak mengklaim seluruh jalur lama bebas masalah.
- PSB Apache/keamanan hosting, suite DB V3/V4/V5 dan V6 Fase 1–4, serta browser khusus modul tersebut tidak dijalankan ulang. HTTP pembinaan/rapor, V6 Fase 5, kerangka bersama dan seluruh statis yang tercantum di tabel benar-benar dijalankan. Tidak mengklaim bukti implementator sebagai eksekusi independen auditor.

Commit dan push hasil audit pada branch yang sama, lalu berhenti. Fase 3 hanya atas instruksi Human Developer berikutnya; keputusan merge/deploy/rilis tetap terpisah.
