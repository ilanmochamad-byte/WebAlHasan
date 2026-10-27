# Audit Codex — PRD V7 Fase 3

**27 Oktober 2026 · LULUS audit teknis lokal Fase 3 setelah tiga koreksi terarah auditor.** Kelima kriteria Fase 3 terbukti pada lingkungan yang diuji. Branch `claude/prd-v7-fase-3`; bukan izin rilis parsial, merge, deploy atau migrasi produksi. Tidak memulai Fase 4 atau mengubah mobile; gerbang produksi V6 tetap terbuka.

## Baseline dan ruang lingkup

- Dibaca `AGENTS.md`, seluruh `PRD-V7.md`, prompt Fase 3 di `docs/prd-v7/prompts-implementasi-audit.md`, audit Fase 1–2, README, bukti, runner dan seluruh perubahan dua commit fase: `c7569ed1329d43f928f7756fe63e01d100b08d2a` dan handoff `f696c163708efb21bbd23326eeb567f96f8cf64c`.
- Baseline audit Fase 2 `96dc8275857d78ebcdc0a432f51e3c2f9c4cae4d`. `origin/main` lokal `1012e36c77bcb3642a3a7b1630eae8a3e0b52e6b` mempunyai isi sama dengan baseline. `git fetch origin claude/prd-v7-fase-3` memverifikasi HEAD remote sama dengan handoff sebelum audit. Penyerahan implementator sudah tercatat; tidak menjalankan Claude atau agen paralel.
- Checkout mobile dibaca saja: `claude/prd-v6-fase-5`, HEAD `68a4121`; perubahan `package.json`/`package-lock.json` milik pekerjaan lain dipertahankan. Tiga direktori web milik pekerjaan lain tetap di luar commit: `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/`.
- Seluruh eksekusi DB memakai fixture sintetis lokal `test_*_test`, PHP 8.4, MariaDB 12.3.2, Chromium Playwright. Push/WhatsApp memakai klien palsu penghitung, tanpa request provider nyata. Tidak ada perubahan SQL migrasi, capability, autentikasi, atau transaksi bisnis izin pada koreksi auditor.

## Temuan dan koreksi

### F-1 — P2: kartu dan daftar dapat membaca keadaan berbeda — ditutup

`IzinLaporanService::laporan()` semula menjalankan COUNT, daftar, agregat status dan rerata sebagai pembacaan autocommit terpisah. Satu WHERE saja tidak menjamin angka konsisten saat penulis lain mengubah status. Probe memakai dua koneksi DB: penulis benar-benar commit setelah COUNT dan sebelum SELECT daftar. Pada sumber handoff, laporan menghasilkan **total 1, daftar 0, kartu 0**.

`IzinLaporanRepository::snapshot()` kini membungkus seluruh pembacaan satu respons dalam transaksi REPEATABLE READ dengan consistent snapshot hanya-baca. Probe identik tetap membuktikan penulis lain sudah commit, sedangkan respons menjadi **1/1/1**. Tidak mengambil kunci tulis atau mengubah transisi izin. Ini konsistensi satu respons; ekspor/halaman berikutnya yang diminta kemudian tetap membaca keadaan saat permintaan itu berlangsung.

### F-2 — P2: ringkasan/filter API wali membuka metadata internal — ditutup

Proyeksi baris dan halaman web sudah menyembunyikan lama tahap serta nama petugas bagi orang tua, tetapi API masih mengirim `ringkasan.rerata_lama_jam`, termasuk jumlah sampel tiap tahap. Wali kedua dapat mempersempit laporan ke satu pengajuan. Filter petugas dan durasi yang tidak ditampilkan UI juga masih diterima laporan/ekspor; ini dapat menjadi oracle atas metadata yang sengaja disembunyikan proyeksi ringkas.

Layanan kini membuang agregat durasi dari respons orang tua dan menolak filter `pembimbing_id`, `murobi_id`, `durasi_min_jam`, `durasi_maks_jam` dengan 422 untuk orang tua, pada laporan maupun ekspor. Saringan status/asal/tanggal/santri dan cakupan anak tetap tersedia. Petugas tetap dapat memakai filter internal. Kontrak API diperbarui. Tidak memberi hak baru kepada wali atau petugas.

Probe `tests/v7_phase3_audit.php` **identik** pada sumber asli dan koreksi: **8/10 → 18/0**. Probe HTTP tambahan **33/0** membuktikan perilaku pada wali pengaju, wali kedua, admin, dan akses anonim; respons memakai `no-store`. Bukti `audit-codex/probe-asli.txt`, `probe-akhir.txt`, `probe-http.txt`.

Diagnostik awal probe salah menyebut `notifikasi_outbox.error_pesan` (kolom sebenarnya `error_terakhir`), sehingga pemindaian akhir berhenti. Diagnostik disimpan terpisah di `diagnostik-probe-awal.txt`; probe final diperbaiki lalu dijalankan pada sumber asli sebelum koreksi aplikasi. Tidak menganggap penghentian pembantu uji itu sebagai kegagalan aplikasi.

### F-3 — P2: action audit V2 atas pengajuan wali menyimpan alasan mentah — ditutup

Pemindaian suite awal hanya memilih action `izin_wali%`/`izin_v7%`. Penetapan murobi dan koreksi keputusan atas pengajuan wali memakai action `izin_murobi_assigned` dan `izin_decision_corrected`, sehingga terlewat. Pemeriksaan sumber dan probe seluruh action baru pada matriks sepuluh peristiwa membuktikan **tiga bagian JSON audit** menyimpan alasan mentah: alasan penetapan, alasan keputusan sebelum/sesudah koreksi, serta alasan koreksi.

`IzinWorkflowService::auditOrFail()` kini menerima penanda jalur wali yang sudah dihitung dari DB oleh mutator. Khusus jalur itu, `alasan` dan `alasan_koreksi` diganti hash SHA-256 sebelum masuk log umum. Alasan lengkap tetap di keputusan, penetapan/riwayat bisnis, dan tabel koreksi dengan pelaku/waktu; tidak mengubah keputusan atau isi riwayat. Jalur pengurus V2 mempertahankan bentuk audit lamanya. Tidak membersihkan baris audit historis yang sudah ada.

Probe notifikasi yang sama, dengan pemindaian **seluruh action baru** dan tanpa mencetak isi rahasia, berubah **48/1 → 49/0**, tiga bagian bocor → nol. Bukti `probe-push-audit-sebelum-f3.txt` dan `probe-push-semua-peristiwa.txt`. Sumber kontrol ini sudah memuat F-1/F-2 tetapi belum F-3; bukan diklaim sebagai kontrol ulang seluruh handoff asli.

## Pemetaan penerimaan Fase 3

1. **Peristiwa dan penerima.** Integrasi menguji seluruh sepuluh jenis peristiwa; pembimbing/admin menerima pengajuan/routing, hanya wali pengaju menerima perbaikan/penolakan/penerusan/keputusan/koreksi, murobi tujuan menerima penerusan/penetapan, dan pemegang antrean menerima pembatalan. Wali lain dan pengguna asing tidak menerima pesan itu. Probe tambahan `tests/v7_phase3_audit_notifications.php` memakai mutasi nyata dengan **Push ON untuk kesepuluh jenis peristiwa**, satu perangkat sintetis per akun, termasuk akun yang tidak boleh menerima. InApp, outbox Push, request klien, replay dan privasi audit diperiksa: **49/0**. Kunci berversi dan indeks unik V2 menahan pengantrean ganda; replay mutasi dan worker tidak menambah pengiriman efektif. Retry provider gagal-sementara lalu sukses diuji integrasi: satu outbox, dua upaya, satu sukses.
2. **Sakelar mati dan kegagalan notifikasi.** Push OFF/WhatsApp OFF menghasilkan nol request provider sambil pengajuan/keputusan tetap berhasil. WhatsApp ON lama pun tetap ditangguhkan dan tidak diantrekan V7. Push juga dihentikan bila sakelar dimatikan setelah antrean dibuat. Hilangnya tabel outbox tidak membatalkan buat/teruskan/keputusan: empat kegagalan antrean dicatat tanpa isi. Ini bukan bukti provider nyata sudah diuji.
3. **Rollback transaksi izin.** Probe memaksa COMMIT gagal **sesudah** outbox ditulis pada koneksi transaksi yang sama. Pengajuan, riwayat, idempotensi, audit dan outbox kembali ke jumlah awal. Retry dengan kunci yang sama dapat membuat pengajuan, lalu replay sukses mempertahankan satu ID dan satu set outbox. Verifier Fase 2/3 lulus sesudah probe dan matriks Push.
4. **Pencabutan hak.** Penugasan pembimbing atau relasi wali dicabut sesudah antre: worker membuang Push tanpa request provider. Tautan yang sama pada sesi web/token API lama ditolak 404 dan tidak mengandung isi santri. Murobi sebelum penerusan dan wali asing ditolak. Pusat notifikasi tetap generik tanpa nama santri karena `pengajuan_id` outbox NULL; detail selalu mengotorisasi ulang.
5. **Laporan dan proyeksi.** Total kartu = seluruh halaman daftar = ID ekspor, tanpa ganda; jumlah per asal/tahap/hasil sama. Sepuluh akun diuji pada fixture yang sama. Himpunan ID laporan dibandingkan dengan hak membuka detail V7/V2: 106 kandidat per peran selain admin; admin diuji 80 kandidat dan jumlah seluruh ID dibanding SQL independen. F-1 menambahkan pengujian penulis bersamaan. F-2 menutup jalur metadata wali melalui API/filter.
6. **Riwayat dan privasi.** Setiap kenaikan versi mempunyai satu audit mutasi berpelaku; seluruh riwayat wali berpelaku/waktu; tinjauan/perbaikan menyimpan versi sebelum/sesudah, penetapan beralasan. Payload judul/isi/data generik, penunjuk dibangun ulang worker. Pemindaian per baris auditor melengkapi GROUP_CONCAT suite supaya hasil tidak terpotong: 80 outbox, 118 audit, 10 catatan percobaan pada titik probe final, nol penanda sensitif yang diuji. Token tujuan berada hanya pada medan `to` di memori request provider palsu; medan itu memang wajib dikirim ke provider dan tidak ditulis ke bukti/log. Payload selain `to`, audit, log HTTP, outbox dan catatan percobaan diperiksa terpisah.

Matriks laporan saat N-11 integrasi, sebelum probe auditor menambah fixture:

| Pembaca | Kartu = daftar = ekspor |
| --- | ---: |
| Admin | 1.070 = 18 wali + 1.052 pengurus |
| Pembimbing satu / dua | 16 / 1 |
| Pengurus pengaju V2 | 52 |
| Murobi A / B | 31 / 1 |
| Wali pengaju / wali kedua | 18 / 15 |
| Wali asing / relasi dicabut | 0 / 0 |

Pengguna tanpa peran perizinan ditolak 403. Perbedaan jumlah wali pengaju dan wali kedua berasal dari relasi anak masing-masing; wali kedua hanya menerima proyeksi status pengajuan milik wali lain, tanpa alasan, catatan, riwayat pribadi atau tindakan.

## Bukti independen

Runner `perintah-audit.sh` dan `perintah-regresi-audit.sh` dijalankan **berurutan** karena rantai fixture memakai DB perantara bernama tetap. Keluaran implementator di `bukti/` tidak ditimpa. Hasil auditor ada di `audit-codex/`.

| Pemeriksaan | Hasil | Bukti |
| --- | --- | --- |
| Statis Fase 3 dan lint berkas baru/berubah | 44/0 | `statis.txt` |
| Integrasi Fase 3, worker, kegagalan antrean, konkurensi, laporan dan audit | 112/0 | `integrasi.txt` |
| HTTP web/API, akses sesi/token lama, laporan/CSV | 43/0 | `http.txt` |
| Browser 375/768/1280px dan tanpa JavaScript | 53/0 | `browser.txt`, `tangkapan/` |
| Probe snapshot, proyeksi/filter wali, rollback dan pemindaian per baris | 8/10 asli → 18/0 koreksi | `probe-asli.txt`, `probe-akhir.txt` |
| Probe koreksi melalui HTTP API | 33/0 | `probe-http.txt` |
| Sepuluh jenis peristiwa dengan Push ON, replay, kanal OFF dan audit semua action | 48/1 sebelum F-3 → 49/0 | `probe-push-audit-sebelum-f3.txt`, `probe-push-semua-peristiwa.txt` |
| Pemindaian penuh seluruh action audit pengajuan wali, outbox dan percobaan | 3/0; 148 · 127 · 33 baris, nol temuan | `privasi-semua-action.txt` |
| Verifier Fase 2 / 3 sesudah browser, probe, Push | 41/0 · 8/0 tiap pasangan | `verifier-*.txt` |

`perintah-validasi-akhir.sh` menyiapkan arsip kode baseline (tanpa worktree), mengulang kontrol Fase 1, menjalankan seluruh `perintah-audit.sh` pada sumber akhir, lalu membangun fixture Fase 2 baru untuk integrasi dan probe audit: **119/0 dan 34/0**, verifier **41/0 dan 8/0** (`regresi/*sesudah-f3.txt`). Kontrol baseline **73/2**, dengan dua label gagal yang sama persis seperti sumber akhir, kini dikonfirmasi independen (`regresi/v7-fase1-kontrol-baseline-96dc827.txt`). Keluaran rangkaian akhir `validasi-akhir.txt` mencatat exit tiap langkah. Kegagalan pertama penyiapan arsip karena direktori fixture tanda tangan/dependensi PDF belum disertakan disimpan di `diagnostik-arsip-baseline.txt`/`diagnostik-dependensi-arsip.txt`; sumber baseline kemudian dijalankan dengan direktori fixture dan vendor lokal yang sama. Ini kesalahan penyiapan kontrol, bukan temuan aplikasi.

Tangkapan laporan wali kedua 1280px diperiksa visual: kartu/status ringkas dan daftar konsisten, tanpa durasi/nama petugas. Notifikasi pembimbing 375/768px dan laporan admin 1280px juga diperiksa visual: teks generik, kerangka dan daftar terbaca, tanpa overflow halaman. Tangkapan murobi/pembimbing disimpan; seluruh peran/lebar diperiksa oleh assertion browser.

Pemindaian akhir **112 berkas bukti teks** tidak menemukan pola bearer, token Push, access/refresh token atau password mentah yang diuji (`pemindaian-bukti.txt`). Ini pemindaian pola terukur, bukan klaim seluruh kemungkinan data sensitif telah dicari. `git diff --check`, sintaks ketiga runner dan lint 24 PHP baru/berubah lulus.

## Regresi

Rangkaian luas di `audit-codex/regresi/ringkasan.txt` selesai: **85 rangkaian memiliki exit dan hitungan identik dengan handoff Fase 3**, dibandingkan otomatis di `perbandingan-regresi.txt`. Tidak melonggarkan oracle lama.

| Pemeriksaan | Hasil independen |
| --- | --- |
| V7 Fase 2 integrasi / HTTP / browser / probe audit / migrasi 031 | 119/0 · 61/0 · 33/0 · 34/0 · 27/0 |
| V7 Fase 1 integrasi / HTTP / browser / probe audit | 73/2 · 71/0 · 39/0 · 13/0 |
| Penugasan | 161/0 |
| V2 Fase 1 / 2 / 4 / 5 integrasi | 39/0 · 94/0 · 124/0 · 145/0 |
| V2 Fase 4 konkurensi | 20/0 |
| V2 Fase 2 / 4 / 5 web | 36/0 · 46/0 · 79/0 |
| V2 API Fase 3 / 4 / 5 | 95/21 · 91/1 · 150/0 |
| Verifier V7 Fase 2 / 3 pada enam DB regresi V2 | masing-masing 41/0 · 8/0 |
| Worker V5 Fase 3 / HTTP V6 Fase 4 | 82/0 · 63/0 |
| V6 Fase 5 integrasi / HTTP | 71/0 · 42/0 |
| Redesain HTTP Tahap 2 / 3 / 4 / 5 / 7 | 185/6 · 166/1 · 132/0 · 106/0 · 122/0 |
| Browser kerangka sembilan akun, tiga lebar | 91/0 |
| Seluruh 42 statis | 18 exit 0 · 22 exit 1 · 2 exit 77 |

Kegagalan historis yang tetap terbuka:

- V7 Fase 1 73/2: oracle mengharapkan wali belum memiliki tindakan Fase 2; probe V2 tanpa versi mengharapkan 409, sedangkan versi kini wajib dan ditolak 422 sesuai koreksi audit Fase 2. Bukan diubah agar menjadi hijau. Kontrol sumber baseline 96dc827 juga 73/2 dengan kedua label gagal identik.
- V2 API Fase 3 95/21 dan Fase 4 91/1 identik handoff dan audit Fase 1–2. Fixture gabungan mengubah jumlah kandidat/routing dibanding ekspektasi suite lama; bukti kontrol baseline 3e78564 terdahulu tidak diklaim sebagai eksekusi baru pada audit ini.
- HTTP Tahap 2 185/6 dan Tahap 3 166/1 masih memakai oracle menu lama dan penugasan AG. Semua statis memiliki hitungan/exit identik handoff, termasuk asumsi migrasi terakhir, daftar berkas fase lama, versi paket checkout mobile milik pekerjaan lain, serta dua suite V3 exit 77 yang memerlukan DB khusus. Statis Fase 3 sendiri 44/0.
- Deprecation PHP 8.4 `str_getcsv()` berasal dari pembantu V2 lama; keluaran mentah tetap disimpan. Ini tidak dianggap bukti log HTTP V7 memuat credential.

Koreksi F-3 selesai setelah kelompok awal regresi V7, sebelum kelompok V2 dieksekusi. Karena itu seluruh Fase 3 serta integrasi/probe Fase 2 diulang setelah F-3; hasil akhir dicatat terpisah. Browser/HTTP Fase 1–2 dan migrasi di tabel juga benar-benar dieksekusi pada audit ini, sebelum F-3 yang hanya mengubah metadata audit jalur wali. Tidak mengklaim seluruh 85 rangkaian dijalankan ulang dua kali.

Tidak mengulang migrasi Fase 1/drill warisan, rollback 031…024, alat rilis V6, keamanan PSB Apache/browser, seluruh DB V3/V4/V5 lain, V6 Fase 1–3, browser khusus V3–V6, atau native mobile. Fase 3 tanpa migrasi; uji preservasi 030→031 dan rollback 031 tetap dijalankan oleh suite migrasi Fase 2.

## Penafsiran dan batas

- R-1…R-5, R-7…R-10 README diterima untuk Fase 3: penerima pembatalan mengikuti pemegang antrean; penetapan/kirim ulang memicu peristiwa masuk; InApp juga generik; Push versi basi dibuang; cakupan laporan mengikuti detail; isi izin dibaca lewat detail, bukan laporan. Metadata internal orang tua diperketat melalui F-2.
- R-6 diterima dengan batas eksplisit: kegagalan enqueue dipisahkan melalui SAVEPOINT agar izin tetap commit dan tercatat audit gagal. **Tidak ada pemulihan antrean otomatis** bila tabel outbox rusak. Replay mutasi sukses tidak mengantrekan ulang peristiwa yang hilang; ini harus dipantau/ditangani melalui operasi, bukan diklaim pengiriman akhirnya terjamin.
- Deduplikasi dibuktikan pada penyimpanan, replay mutasi, klaim worker dan hasil provider palsu yang diketahui. Tidak membuktikan pengiriman tepat sekali pada perangkat ketika provider sebenarnya menerima request tetapi responsnya hilang, atau proses mati di antara penerimaan provider dan pencatatan tiket; batas infrastruktur V2 itu tetap ada.
- Laporan lanjutan menurut ID santri/pembimbing/murobi/kamar/kelas tersedia melalui parameter web/API; halaman saat ini mempertahankan parameter tersebut sebagai hidden input, belum menyediakan pemilih bernama untuk semuanya. Filter internal petugas dibatasi server bagi wali. Tidak mengubah peta navigasi atau membangun layar mobile dalam audit ini.
- Hanya MariaDB lokal/sandbox. MySQL 8, cPanel/hosting, produksi, Push nyata, perangkat fisik, Android/iOS, ketukan/cold start Push dan UAT **tidak diuji**. Fase 4 dan gerbang akhir/rilis V7 tetap memerlukan giliran tersendiri; status gerbang produksi V6 tidak berubah.
- Konkurensi hanya membuktikan interleaving yang benar-benar dieksekusi. Snapshot laporan menjamin satu respons konsisten pada keadaan awal transaksi; permintaan detail/ekspor selanjutnya memeriksa hak/keadaan terbaru.
- T-1 retry pembuatan pengurus V2 yang dapat 500 adalah temuan lama terpisah; tidak diperluas koreksinya. T-2 pemisah nama murobi/catatan routing pada detail Fase 2 tetap catatan tampilan lama nonblokir.
- Upaya awal runner tanpa eskalasi terhalang sandbox `/dev/fd`; seluruh suite di tabel kemudian benar-benar dieksekusi dengan izin lingkungan lokal yang sesuai. Tidak menyatakan eksekusi terhalang itu lulus. Tidak menjalankan migrasi atau tindakan terhadap DB produksi.
