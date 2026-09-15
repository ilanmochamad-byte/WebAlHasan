# Bukti audit Fase 3 — Claude Code

Auditor: Claude Code (peran ditetapkan `AGENTS.md` untuk workstream PRD V3 Fase 1–5).
Implementator: Codex. Branch `prd-v3-fase-3`, commit yang diaudit `e13787f`,
baseline `927dcd8`. Tanggal audit: 11 September 2026.

Audit ini memeriksa commit implementator terhadap PRD V3 Fase 3, menjalankan
pengujian secara independen, dan menerapkan koreksi terarah beserta regresinya.
Tidak ada merge, tidak ada deploy, dan tidak ada pekerjaan Fase 4.

## 1. Kesimpulan

Sesudah koreksi audit, **seluruh 10 kriteria penerimaan Fase 3 terpenuhi** dan
kedua belas persyaratan implementasi terimplementasi. Enam koreksi audit Fase 2
(T1–T6) tetap utuh. Tidak ada kode Fase 4: tidak ada rute atau halaman
publikasi orang tua, `parentSerializer()` tidak dipanggil dari rute mana pun,
`ApiAuthService::menus()` dan modul notifikasi tidak disentuh.

Bukti pengujian implementator akurat dan tereproduksi persis pada commit asli.
Namun suite implementator hanya menguji jalur yang ia rancang. Probe independen
menemukan **sebelas temuan (K1–K11)**; tujuh di antaranya mengubah perilaku
bisnis. Sebelum koreksi, kriteria 2 ("pelanggaran ditampilkan bersama seluruh
sesinya") gagal begitu pelanggaran dikoreksi (K3), kriteria 6 ("transisi tidak
sah ditolak") tidak berlaku bagi sesi pada kasus yang sudah ditutup (K1),
kriteria 8 ("koreksi tanpa mengganti nilai secara diam-diam") dapat dilanggar
lewat formulir web dan koreksi pengosong (K5, K6), dan persyaratan 7
("menghubungkan rekomendasi secara manual ke kasus") hanya tersedia saat kasus
dibuka serta dapat macet permanen (K2). Migrasi 016 juga membuang keputusan
bisnis saat rollback sehingga verifier merah sesudah pasang ulang (K4).

Seluruh temuan sudah diperbaiki dengan uji regresi yang gagal pada kode lama
dan lulus pada kode baru.

## 2. Reproduksi pengujian implementator

Lingkungan: PHP 8.4.14, MariaDB 12.3.2 lokal, Node 26.7.0, Playwright 1.62.1
(Chromium), database `webalhasan_v3_phase1_test`, fixture `sbx_*`. Tidak ada
akses, migrasi, atau penghapusan data produksi.

Baseline dijalankan pada salinan hasil `git archive e13787f` di scratchpad agar
koreksi audit yang sedang ditulis tidak tercampur ke hasil "sebelum".

| Paket | Klaim implementator | Sebelum koreksi (e13787f) | Sesudah koreksi |
| --- | --- | --- | --- |
| `bin/v3_phase3_run_tests.sh` | 170, exit 0 | 170, exit 0 | **255**, exit 0 |
| — preflight / statis / drill / integrasi / verifier | — | 14 / 76 / 13 / 42 / 25 | 14 / **94** / **19** / **99** / **29** |
| `bin/v3_phase2_run_tests.sh` | exit 0 | 172, exit 0 | 172, exit 0 |
| `bin/v3_phase1_run_tests.sh` | 71, exit 0 | 71, exit 0 | 71, exit 0 |
| `bin/penugasan_run_all_tests.sh` | 49 suite / 4.017 | 49 suite / 4.017, 0 gagal, 0 dilewati | 49 suite / 4.017, 0 gagal, 0 dilewati |
| `tests/browser/uji-v3-fase3.mjs` (Chromium, 1440 & 375 px) | 33 | 33, exit 0 | **46**, exit 0 |
| `bin/v3_verify.php` / `v3_phase2_verify.php` / `v3_phase3_verify.php` akhir | exit 0 | — | 284 / 30 / 29, exit 0 |

Uji browser baseline memakai skrip asli dari `e13787f` terhadap server
`php -S 127.0.0.1:8941` yang melayani salinan `git archive`; uji sesudah koreksi
memakai `127.0.0.1:8940` pada worktree audit. Keduanya dengan
`tests/v3_phase2_router.php` dan opt-in `PERAPIHAN_AUDIT_DB=1`.

Catatan kejujuran bukti: pada putaran pertama sesudah koreksi, verifier Fase 2
gagal `Lampiran privat tersedia dan cocok dengan hash` dan diagnostik Fase 1
gagal `Sehat exit 0`. Keduanya dibuktikan sebagai artefak lingkungan audit,
bukan regresi: lampiran #33/#34 dibuat suite Fase 2 baseline di storage salinan
`git archive`, dan `bin/v3_verify.php` merah karena residu regresi warisan dari
run baseline. Sesudah berkas dipulihkan dan residu dibersihkan (bagian 8), kedua
paket lulus ulang tanpa perubahan kode (172 dan 71).

## 3. Temuan dan koreksi

Setiap temuan direproduksi dengan probe pada database uji sebelum diperbaiki.
Probe menandai fixture dengan `SBX-AUD3-*` dan idempotency key berawalan `aud-`.

### K1 — Sesi pada kasus tertutup masih dapat diubah (sedang)

`transitionSession()` tidak memeriksa status kasus induk, padahal
`createSession()` dan `addLinks()` sudah menolak kasus tertutup. Probe:

- menutup kasus → jadwal ulang sesinya → `200`, terbentuk baris sesi baru
  berstatus `Dijadwalkan Ulang` di dalam kasus `Selesai`
- sesi hasil jadwal ulang itu lalu diselesaikan → `200`

Suite implementator sendiri meninggalkan 10 kasus `Selesai` dengan sesi
`Dijadwalkan Ulang` yang masih aktif, karena menutup kasus sebelum sesi kedua
selesai — keadaan itu sah, tetapi tanpa penjaga sesi tersebut tetap dapat
dimutasi sesudah penutupan.

**Koreksi.** Di dalam transaksi, sesudah kunci subjek dan pemeriksaan versi,
kasus induk dibaca `FOR UPDATE`; bila `Selesai`/`Dibatalkan`, transisi ditolak
`422`. Pemeriksaan ditempatkan sesudah replay idempotensi, sehingga retry atas
transisi yang terjadi sebelum penutupan tetap dijawab. Formulir status sesi
disembunyikan pada kasus tertutup. Koreksi beralasan tetap tersedia.

### K2 — Rekomendasi tertahan oleh kasus batal dan tidak dapat ditautkan ke kasus berjalan (sedang)

Dua celah pada persyaratan 7:

1. Membatalkan kasus tidak menyentuh rekomendasi yang ditautkannya. Probe:
   rekomendasi #58 tetap `ditindaklanjuti_kasus_id=34` milik kasus batal, tidak
   kembali ke antrean, dan ditolak `409` ketika ditautkan ke kasus pengganti.
   Tidak ada jalur apa pun untuk memulihkannya.
2. Rekomendasi hanya dapat ditautkan saat kasus dibuka. `addLinks()`
   mengabaikan `rekomendasi_ids`, dan halaman detail tidak memiliki formulir
   `tambah_tautan` walaupun controller-nya menangani aksi tersebut. Pembimbing
   yang sudah punya kasus berjalan terpaksa membuka kasus kedua.

**Koreksi.** Pembatalan kasus melepas rekomendasinya di transaksi yang sama
(tautan dikosongkan, status kembali `Baru`, ID lepasan masuk respons dan audit
sebagai `rekomendasi_dilepas`). `addLinks()` menerima `rekomendasi_ids`, dan
halaman detail menyediakan formulir tautan pelanggaran/rekomendasi untuk kasus
terbuka. Antrean dan penautan kini mensyaratkan `status='Baru'`, sehingga
rekomendasi berstatus `Ditinjau` tidak pernah ditindaklanjuti dua kali. Status
rekomendasi tidak pernah ditulis kode Fase 2, jadi syarat ini tidak mengubah
perilaku lama. Penautan tetap tidak menyentuh ledger dan tidak membuat sesi.

### K3 — Koreksi pelanggaran memutus tampilan tindak lanjut (sedang)

Koreksi pelanggaran Fase 2 membuat baris revisi dengan ID baru, sedangkan
tautan konseling tetap menunjuk ID lama. Probe: detail catatan lama menampilkan
3 baris tindak lanjut, detail catatan **terkini** menampilkan 0. Kriteria 2
gagal tepat pada catatan yang dibuka pengguna. Revisi itu juga dapat ditautkan
ulang ke kasus yang sama sehingga kasus menampilkan satu kejadian dua kali.

**Koreksi.** `PelanggaranService::show()` membaca tindak lanjut pada seluruh
rantai revisi (ID dari riwayat revisi yang sudah dihitung). `addLinks()` menolak
`409` revisi yang leluhurnya sudah tertaut pada tingkat kasus yang sama. Detail
kasus memberi `pelanggaran_digantikan_oleh_id` dan halaman menandai "direvisi
menjadi #…".

### K4 — Rollback 016 membuang keputusan bisnis dan verifier merah sesudah pasang ulang (sedang)

Rollback 016 men-`DROP COLUMN` alasan pembatalan kasus/sesi, alasan jadwal
ulang, alasan koreksi kasus, serta `ditindaklanjuti_kasus_id`/`_pada`. Drill
sesudah probe K2 membuktikan:

- `bin/v3_phase3_verify.php` merah: `Kasus batal memiliki waktu dan alasan terpisah`
  (pasang ulang memberi penanda hanya untuk sesi, tidak untuk kasus)
- tautan rekomendasi→kasus hilang; rekomendasi `Ditinjau` tanpa kasus naik dari
  9 (akibat drill implementator sebelumnya) menjadi 11, dan pada kode lama
  seluruhnya kembali masuk antrean sehingga dapat ditindaklanjuti dua kali
- alasan asli sesi (mis. `Penyesuaian jadwal pembimbing`) diganti penanda
  `tidak tersedia pada baris warisan`

Uji drill implementator lulus karena hanya menghitung baris dan kolom, bukan
nilai, dan tidak ada kasus batal pada fixture-nya.

**Koreksi.** Rollback 016 kini hanya melepas indeks/penjaga struktural
(`sesi_satu_revisi`, `rekomendasi_tindak_lanjut_index`, guard draf) dan
mempertahankan kolom keputusan beserta foreign key-nya; kolomnya nullable dan
aman bagi kode Fase 2. Migrasi 016 memberi penanda jujur pada kasus batal tanpa
alasan. Drill kini membandingkan nilai alasan dan tautan rekomendasi sebelum dan
sesudah rollback serta sesudah pasang ulang.

### K5 — Formulir status sesi web menghapus rencana tindak lanjut (sedang)

Formulir status sesi selalu mengirim `tindak_lanjut`, `ringkasan_internal`, dan
`hasil` kosong serta `realisasi` terisi. `normaliseSession()` membaca string
kosong sebagai "hapus". Probe:

- sesi dengan rencana `AUD rencana S1` diselesaikan lewat bentuk formulir web →
  `tindak_lanjut` menjadi `NULL` tanpa peringatan
- sesi dibatalkan lewat formulir → `realisasi` terisi waktu sekarang, padahal
  sesi tidak pernah terlaksana

**Koreksi.** Pada transisi status, field opsional berupa string kosong berarti
"tidak diubah". Status `Dibatalkan` selalu menyimpan `realisasi=null`. Label
formulir menjelaskan bahwa kolom kosong mempertahankan rencana tersimpan.

### K6 — Koreksi sesi dapat melanggar arti statusnya sendiri (sedang)

`correctSession()` tidak memvalidasi hasil revisi terhadap status. Probe:

- koreksi sesi `Selesai` dengan `ringkasan_internal=''` dan `hasil=''` → `200`,
  terbentuk revisi `Selesai` tanpa ringkasan maupun hasil (#53)
- koreksi sesi `Dijadwalkan` dengan `realisasi` → revisi terjadwal yang
  memiliki waktu realisasi (#54)

Nilai sumber tetap tersimpan, tetapi catatan yang berlaku kehilangan isi wajib
yang sebelumnya dipaksa `transitionSession()`.

**Koreksi.** `sessionStateRules()` dijalankan sebelum versi sumber dinaikkan:
sesi `Selesai` wajib memiliki realisasi, ringkasan, dan hasil; `Tidak Hadir`
wajib memiliki realisasi (`422` bila tidak); sesi terjadwal selalu `realisasi=null`
seperti jalur jadwal ulang. Verifier memperoleh dua invariant yang sepadan.

### K7 — Deduplikasi outbox menelan notifikasi status berikutnya (sedang-rendah)

Kunci `v3:konseling:{id}:{event}` sama untuk setiap perubahan status pada
sumber yang sama, dan `ON DUPLICATE KEY UPDATE id=id` membuang yang kedua. Probe:
kasus `Dibuka → Dalam Pendampingan → Selesai` menghasilkan 2 audit tetapi hanya 1
outbox bagi murobi. Hal sama terjadi pada sesi hasil jadwal ulang yang lalu
diselesaikan (kedua peristiwa memakai ID sesi yang sama). Payload tetap generik.

**Koreksi.** Kunci peristiwa status memuat versi hasil (`…:v{version}`), sehingga
retry tetap tidak menggandakan notifikasi tetapi status berikutnya menghasilkan
notifikasinya sendiri.

### K8 — Guard tautan 016 menduplikasi unique 013 dan dokumentasinya keliru (rendah)

`kontrak`/`desain`/`migrasi` menyatakan "UNIQUE lama mengizinkan lebih dari satu
NULL". `SHOW CREATE TABLE v3_konseling_tautan` membuktikan sebaliknya: 013 sudah
memasang `UNIQUE tautan_unik (pelanggaran_id,kasus_id,sesi_key)` dengan
`sesi_key=COALESCE(sesi_id,0)`, dan 016 menambah `sesi_unik_guard` +
`tautan_unik_efektif` yang identik — indeks unik ganda pada setiap penulisan.

**Koreksi.** 016 tidak lagi memasang guard kedua; rollback tetap melepasnya bila
ada. Verifier Fase 3 memeriksa `tautan_unik` beserta ekspresi generated-nya,
`bin/v3_verify.php` kembali menghitung unique tautan secara eksak tanpa tambahan
016, dan drill membuktikan database sendiri menolak tautan tingkat kasus ganda
dengan `sesi_id=NULL` (`1062` → `409`), bukan hanya service.

### K9 — Audit akses admin tercatat dua kali per pembukaan halaman (rendah)

`timeline()` memanggil `show()`, dan halaman detail serta cetak memanggil
keduanya, sehingga satu pembukaan detail oleh admin menulis dua audit
`v3.konseling.kasus.dilihat.admin` (dibaca dari kode). Hitungan peristiwa akses
akan berlipat pada laporan Fase 5.

**Koreksi.** `timeline()` menerima detail yang sudah dimuat pada permintaan yang
sama; halaman detail dan cetak meneruskannya. Timeline API yang dibuka tersendiri
tetap diaudit. Uji regresi membuktikan keduanya.

### K10 — Penjaga statis "tidak ada mutasi GET" tidak pernah dapat gagal (rendah)

Pola `"/\$method === 'GET'.*…/"` berada di string bertanda kutip ganda, sehingga
regex menerima `$method` dengan `$` sebagai jangkar akhir. Dibuktikan dengan
`php -r`: pola tidak cocok pada API asli **maupun** pada API yang sengaja diberi
rute `GET …/sesi/{id}/status`.

**Koreksi.** Penjaga membaca setiap baris rute `GET`, memastikan rute konseling
benar-benar terbaca, dan menguji dirinya sendiri terhadap rute mutasi sintetis.

### K11 — Label formulir halaman detail tidak terhubung ke kontrolnya (rendah)

Seluruh formulir pada `v3_konseling_detail.php` memakai `<label>` tanpa `for`,
sehingga pembaca layar tidak mengumumkan nama kontrol. Pemeriksaan label
implementator hanya berjalan pada halaman daftar.

**Koreksi.** Setiap kontrol diberi `id` unik (bersufiks ID sesi bila berulang) dan
label `for`. Uji statis menolak label tanpa `for`; uji browser memeriksa label
dan luapan horizontal halaman detail pada 1440 dan 375 px.

### Celah bukti yang ikut ditutup

- **Kriteria 9** menyebut audit/outbox, tetapi hanya kegagalan audit yang diuji.
  Kini trigger sintetis pada `notifikasi_outbox` membuktikan pembukaan kasus
  digulung tanpa kasus maupun audit parsial.
- **Kriteria 3** menuntut "seluruh mutasinya ditolak 403", tetapi hanya
  pembuatan kasus dan detail yang diuji. Kini sembilan jalur (koreksi/status
  kasus, tautan, sesi baru, status/koreksi sesi, dua tanda mengetahui, timeline)
  diuji untuk pembimbing lain, orang tua, dan murobi lain, ditambah admin murni
  pada jalur operasional, disertai bukti tidak ada jejak tulis.
- **Kriteria 5** kini juga diuji lewat HTTP untuk status/koreksi/tanda mengetahui
  sesi, tautan, dan timeline dengan token orang tua.

## 4. Migrasi 016 sesudah koreksi

Tetap aditif dan idempoten; migrasi 001–015 tidak diubah dan 016 belum pernah
dijalankan di produksi. Perubahan: guard tautan duplikat tidak dipasang, kasus
batal tanpa alasan diberi penanda jujur, dan rollback tidak lagi membuang kolom
keputusan bisnis. Tidak ada `DROP TABLE`, `DROP COLUMN` data, maupun `DELETE FROM`.

Database uji yang sempat memasang draf 016 dipulihkan oleh drill (rollback lalu
pasang ulang). Rekomendasi yang tautannya sudah hilang akibat rollback versi
awal tidak dipulihkan otomatis; statusnya tetap `Ditinjau` sehingga tidak dapat
ditindaklanjuti dua kali, dan kasus asalnya tercatat di `audit_logs`.

## 5. Invariant baru yang dijaga otomatis

`bin/v3_phase3_verify.php` (25 → 29 pemeriksaan):

- unique tautan 013 menjaga duplikasi termasuk `sesi_id=NULL`, dan tidak ada guard duplikat
- sesi selesai terkini memiliki realisasi, ringkasan internal, dan hasil
- waktu realisasi sesi terkini sesuai statusnya
- rekomendasi tertaut tidak berada pada kasus batal, dan antrean `Baru` tidak membawa tautan

## 6. Pemeriksaan fokus tanpa temuan tambahan

1. **Cakupan akses.** Pembimbing/murobi terkait berhasil; pembimbing lain,
   murobi lain, orang tua, dan admin murni pada jalur operasional ditolak `403`
   di service maupun HTTP. Daftar difilter di query (`scopeSql`).
2. **Pemisahan data.** Serializer internal, murobi, dan `parentSerializer()`
   memakai allowlist terpisah; respons murobi dan halaman/cetak murobi tidak
   memuat tujuan, ringkasan, hasil, tindak lanjut, maupun rekomendasi. DTO orang
   tua hanya tujuh field dan belum terhubung ke rute.
3. **Kasus mandiri dan jamak.** Kasus tanpa tautan, kasus dengan beberapa
   pelanggaran, dan satu pelanggaran pada tingkat kasus dan beberapa sesi diuji.
4. **Rekomendasi manual.** Tidak ada kasus/sesi otomatis; penautan tidak
   menyentuh ledger (diuji K2).
5. **Status, versi, idempotensi.** Transisi tidak sah `422`, versi lama `409`,
   replay setelah status terminal dijawab dari idempotensi. Probe dua proses
   PHP nyata yang dilepas pada detik yang sama (tidak dikomit sebagai suite):
   pembukaan kasus dengan idempotency key sama → `201` + replay `200` dengan ID
   sama dan tepat satu kasus; dua koreksi sesi selesai dengan versi sama → `200`
   + `409`, tepat satu revisi langsung, isi sumber tidak berubah; dua penutupan
   kasus dengan versi sama → `200` + `409` (`Data sedang diperbarui`, yaitu lock
   wait/deadlock InnoDB pada kunci subjek yang dipetakan ke konflik aman), tepat
   satu audit status.
6. **Riwayat.** Revisi sesi, jadwal ulang, pembatalan, dan penutupan tidak
   menghapus baris; sumber revisi tidak berubah; satu revisi langsung per sesi.
7. **Integritas.** Foreign key dan unique terpasang; audit dan outbox di
   transaksi yang sama.
8. **Audit akses admin.** Tercatat satu kali per pembukaan (K9).
9. **Web/API.** CSRF `419` pada halaman daftar dan detail, `401` tanpa token,
   `GET` pada rute mutasi `404`, cetak `private, no-store`.
10. **T1–T6.** Suite Fase 2 lulus penuh termasuk regresi T1–T3, T6, drill T4,
    dan `.htaccess` T5 masih menolak `PRD-V3.md` serta `design.md`.
11. **Fase 4.** Tidak ada rute, halaman, tabel baru, maupun notifikasi orang tua.

## 7. Catatan dan keputusan terbuka untuk Human Developer

Tidak diperbaiki karena memerlukan keputusan produk atau berada di luar Fase 3:

- ~~**Makna `kerahasiaan`**, **menutup kasus dengan sesi yang masih
  terjadwal**, dan **koreksi kasus tanpa revisi berbaris**.~~ **Sudah diputuskan
  Human Developer pada 11 September 2026 dan diterapkan** — lihat bagian 9.
- **Pembeda 404/403 pada mutasi.** ID yang tidak ada menghasilkan `404`, ID
  lintas cakupan `403`, sehingga keberadaan ID dapat ditebak — tanpa isi. Pola
  yang sama diwarisi dari Fase 2.
- **Akun peran ganda.** `readMode()` memilih satu mode (pola Fase 2); akun yang
  sekaligus pembimbing dan murobi untuk santri berbeda tidak melihat kasus
  binaan murobinya pada daftar. Tidak ada fixture peran ganda.
- **Formulir pembukaan kasus** memuat pelanggaran seluruh santri cakupan
  (satu query per santri); server tetap menolak pasangan yang tidak cocok.
- **Timeline** hanya memuat pembukaan, sesi, dan penutupan; koreksi, tautan,
  dan catatan murobi belum menjadi peristiwa timeline.

**Belum diuji dan tidak diklaim:** migrasi/rollback 016 di MariaDB hosting
cPanel, smoke produksi, Safari, pembaca layar nyata, aplikasi Android/iOS
terpasang, push fisik, dan performa volume besar.

## 8. Kebersihan database uji

Audit hanya memakai `webalhasan_v3_phase1_test`.

- **Fixture probe** ditandai `SBX-AUD3-*`/kunci `aud-*` dan dibiarkan sebagai data uji.
- **Sesi #53** (revisi `Selesai` tanpa ringkasan/hasil, bukti K6) tidak diubah
  atau dihapus. Sesudah koreksi, isinya dipulihkan melalui jalur koreksi
  aplikasi dengan alasan tercatat, membentuk revisi #59 yang memuat ulang nilai
  sumber #50.
- **Lampiran #33 dan #34.** Baseline suite Fase 2 berjalan di salinan
  `git archive`, sehingga berkasnya tertulis ke storage salinan. Kedua berkas
  disalin ke storage utama setelah hash SHA-256 terbukti sama dengan
  `v3_lampiran.sha256`.
- **Residu regresi warisan.** Dua kali regresi penuh (baseline dan sesudah
  koreksi) meninggalkan tepat 24 outbox + 6 audit per run — total 48 + 12.
  Asal setiap baris dibuktikan per jendela waktu run: seluruh outbox bertipe
  `izin.*`, kanal InApp, dengan pengajuan izin yang sudah dihapus; seluruh audit
  `login_succeeded` milik akun fixture yang sudah dihapus (aktor 1111–1116 dan
  1196–1201); nol baris `v3_*`. Hanya ID tersebut yang dihapus dalam satu
  transaksi berpenjaga hitungan. Verifier tidak dilonggarkan.

## 9. Penerapan keputusan Human Developer — 11 September 2026

Sesudah commit audit `f166bdd`, Human Developer menjawab tiga pertanyaan terbuka
pada bagian 7. Empat detail yang mengubah implementasi dikonfirmasi langsung
sebelum ditulis, bukan ditebak:

1. **Kerahasiaan.** `Internal` diketahui pembimbing dan murobi; `Rahasia` hanya
   pembimbing dan santrinya. Dikonfirmasi: admin **tetap** boleh membuka kasus
   Rahasia untuk pengawasan dengan audit akses (PRD 5.3), dan "pembimbing" berarti
   **pembimbing pemilik kasus**, bukan setiap pembimbing dalam cakupan.
2. **Penutupan.** Kasus boleh ditutup `Selesai` walaupun ada sesi terjadwal, dan
   sesi itu ikut ditutup otomatis. Dikonfirmasi: statusnya **`Dibatalkan` dengan
   alasan sistem** (tidak mengarang realisasi, ringkasan, atau hasil), dan aturan
   berlaku juga ketika kasus **`Dibatalkan`**.
3. **Koreksi kasus** disimpan sebagai revisi.

Keputusan dicatat pada PRD V3 bagian 5.5a dan diterapkan auditor sebagai koreksi
terarah pada branch yang sama. Tidak ada pekerjaan Fase 4.

### 9.1 Kerahasiaan

| Pembaca | `Internal` | `Rahasia` |
| --- | --- | --- |
| Pembimbing pemilik (cakupan aktif) | baca isi, kelola | baca isi, kelola |
| Pembimbing lain dalam cakupan | baca isi, kelola | tidak terlihat; mutasi `403` |
| Murobi terkait | baca isi, tanda mengetahui, notifikasi generik | tidak terlihat di daftar/detail/detail pelanggaran; tanda mengetahui `403`; tanpa notifikasi |
| Admin | pengawasan, koreksi beralasan, akses diaudit | pengawasan, koreksi beralasan, akses diaudit |

Penyaring kerahasiaan berjalan di query (`privacySql()` untuk daftar dan detail
kasus, serta `counselingForViolations()` untuk detail pelanggaran), dan setiap
mutasi melewati `assertCaseAccess()` yang menggabungkan cakupan aktif dengan
kepemilikan untuk kasus Rahasia. Tanda mengetahui memeriksa ulang kerahasiaan di
dalam transaksi terkunci. `notifyMurobi()` memperlakukan kerahasiaan yang tidak
diketahui sebagai Rahasia.

Konsekuensi yang disengaja: DTO terbatas murobi tidak lagi dipakai, karena murobi
kini hanya dapat membuka kasus Internal yang isinya memang boleh diketahuinya.
Pemisahan data yang diwajibkan persyaratan 5 tetap berlaku pada model (catatan
murobi di tabel sendiri) dan pada DTO orang tua.

### 9.2 Penutupan otomatis sesi terjadwal

Di dalam transaksi penutupan (`Selesai` atau `Dibatalkan`), setiap sesi terjadwal
terkini diubah menjadi `Dibatalkan` dengan alasan `Ditutup otomatis: kasus
diselesaikan/dibatalkan sebelum sesi dilaksanakan.`, realisasi kosong, versi
naik, dan audit `v3.konseling.sesi.ditutup_otomatis` per sesi. ID-nya dikembalikan
sebagai `sesi_ditutup_otomatis`. Syarat sedikitnya satu sesi selesai untuk
penutupan `Selesai` tetap berlaku. Penjaga K1 tetap ada sebagai lapisan kedua.

### 9.3 Revisi kasus

Tabel `v3_konseling_kasus_revisi` menyimpan satu baris per versi sumber berisi
tujuan dan kerahasiaan sebelum/sesudah, alasan, kapasitas, pelaku, dan waktu.
Kasus tidak dipecah menjadi baris baru seperti sesi, karena sesi, tautan,
rekomendasi, dan catatan murobi semuanya menunjuk ID kasus; baris kasus memegang
nilai terkini, sedangkan riwayat tidak pernah ditimpa. Revisi tampil pada detail,
API, dan cetak.

### 9.4 Migrasi 017

`017_v3_fase3_kerahasiaan_dan_revisi.sql` aditif dan idempoten: membuat tabel
revisi dan menerapkan aturan penutupan pada data lama. Pada database uji, 13 sesi
terjadwal pada kasus yang sudah ditutup — 10 ditinggalkan suite implementator,
sisanya dari probe dan regresi audit — ditutup dengan alasan yang menyebut
migrasi 017. Perubahan data migrasi tidak menulis `audit_logs`; jejaknya adalah
alasan pada baris dan `schema_migrations`. Rollback 017 hanya melepas tabel revisi
bila masih kosong dan tidak membuka kembali sesi yang sudah ditutup.

### 9.5 Pengujian sesudah keputusan

| Paket | Sesudah K1–K11 | Sesudah keputusan HD |
| --- | --- | --- |
| Suite Fase 3 | 255 | **297**, exit 0 |
| — preflight / statis / drill / integrasi / verifier | 14 / 94 / 19 / 99 / 29 | 15 / 103 / 24 / 121 / 34 |
| Suite Fase 2 | 172 | 172, exit 0 |
| Suite Fase 1 | 71 | 71, exit 0 |
| Regresi penuh | 49 suite / 4.017 | 49 suite / 4.017, 0 gagal, 0 dilewati |
| Browser (1440 & 375 px) | 46 | **53**, exit 0 |
| `v3_verify` / `v3_phase2_verify` / `v3_phase3_verify` akhir | 284 / 30 / 29 | 286 / 30 / 34, exit 0 |

Regresi penuh kali ini kembali meninggalkan tepat 24 outbox `izin.*` (InApp,
pengajuan izin terhapus) dan 6 audit `login_succeeded` milik akun fixture yang
sudah dihapus (aktor 1281–1286), nol baris `v3_*`. Asalnya dibuktikan per run
sebelum hanya 30 ID tersebut dihapus dalam satu transaksi berpenjaga hitungan.

Putaran pertama suite gagal 2 pemeriksaan: uji "kegagalan outbox menggulung
transaksi" memakai kasus Rahasia, sehingga sesuai keputusan baru tidak ada
notifikasi murobi, outbox tidak ditulis, dan trigger kegagalan tidak pernah
terpicu. Perilaku aplikasi benar; uji diubah memakai kasus Internal. Putaran
gagal itu meninggalkan satu kasus fixture Rahasia `SBX outbox gagal` yang sah.

### 9.6 Batasan yang perlu diketahui

- **Tidak ada alih kepemilikan kasus.** Bila penugasan pembimbing pemilik
  berakhir, kasus Rahasia hanya dapat diawasi admin dan tidak dapat dilanjutkan
  pembimbing lain. Bila alih tangan dibutuhkan, perlu keputusan dan fitur baru.
- **Uji pembimbing bukan pemilik** memindahkan `pembimbing_id` kasus uji secara
  sementara, karena fixture tidak memiliki dua pembimbing dengan cakupan yang sama;
  nilainya dipulihkan dalam blok `finally`.
- **Penutupan otomatis** tidak mengirim notifikasi per sesi; notifikasi status
  kasus (hanya untuk kasus Internal) sudah mewakilinya.
- **Catatan murobi dan notifikasi lama** pada kasus yang kemudian dikoreksi menjadi
  Rahasia tetap tersimpan sebagai riwayat, tetapi murobi tidak lagi dapat membuka
  kasusnya.

## 10. Bukti smoke test produksi — 12, 14, dan 15 September 2026

Dijalankan Human Developer pada hosting cPanel sesudah `6d9d59e` dideploy.
Migrasi 016 dan 017 diterapkan 11 September 2026 pukul 19.19.59 melalui
`php bin/migrate.php up`. Keluaran CLI dan tangkapan layar ditinjau langsung;
yang dicatat di bawah hanya hal yang benar-benar terlihat pada bukti tersebut.

### 10.1 Migrasi dan post-check CLI — LULUS

- `php bin/migrate.php status` menampilkan 001–017 diterapkan, dengan 016 dan 017
  bertanda waktu sama.
- Konfirmasi berkas terpasang: `privacySql` 1, `closeScheduledSessions` 1,
  `Riwayat revisi kasus` 1, dan rollback 017 ada.
- `bin/v3_phase3_preflight.php`: 15 pemeriksaan lulus, `exit=0`. Manifest
  produksi `kasus 0, sesi 0, tautan 0, rekomendasi 1, sesi terjadwal pada kasus
  tertutup yang akan ditutup 017 0` — tidak ada data konseling lama yang perlu
  dirapikan 017 di produksi.
- `bin/v3_verify.php`: `exit=0`, termasuk pemeriksaan yatim baru
  `v3_konseling_kasus_revisi.kasus_id` dan `.created_by`, sehingga tabel revisi
  017 terbukti terpasang pada MariaDB hosting.
- `bin/v3_phase2_verify.php`: 30 pemeriksaan lulus, `exit=0`; T1–T6 dan
  rekonsiliasi agregat–ledger tetap utuh sesudah 016/017.
- `bin/v3_phase3_verify.php`: **34** pemeriksaan lulus, `exit=0`, termasuk
  `Migrasi 017 tercatat`, `Tabel revisi kasus memiliki unique per versi dan
  foreign key`, `Kasus tertutup tidak menyisakan sesi terjadwal`, serta kedua
  invariant revisi kasus.

Keempat perintah dijalankan sebelum skenario web, ketika manifest masih nol
kasus; post-check sesudah data smoke terbentuk belum dijalankan.

### 10.2 Skenario yang terbukti di produksi — putaran 12 September

| Langkah | Bukti |
| --- | --- |
| Pencatatan pelanggaran smoke | Catatan #7, `SMOKE AUDIT F3 ini adalah uji coba`, 2 poin; "Pelanggaran dicatat dan poin direkonsiliasi"; `Agregat 4 · ledger 4 · selisih 0` |
| Kasus **Rahasia** dibuka pembimbing pemilik | Kasus #1, tujuan `SMOKE AUDIT F3 — kasus rahasia`, tautan pelanggaran #7; keterangan kerahasiaan tampil pada formulir dan detail sesuai keputusan Human Developer |
| Sesi dan status | Sesi #1 dijadwalkan dengan rencana `SMOKE AUDIT F3 — rencana sesi rahasia`; status kasus otomatis **Dalam Pendampingan**; timeline memuat dua peristiwa; label `Tindak lanjut (kosongkan untuk mempertahankan rencana tersimpan)` tampil |
| Murobi terkait ditolak dari kasus Rahasia | Daftar konseling "Belum ada kasus"; `v3_konseling_detail.php?id=1` → 403; `?id=7` (ID tebakan) → 403; `v3_konseling_cetak.php?id=7` → 403; detail pelanggaran #7 menampilkan "Belum ditautkan ke kasus konseling"; **Notifikasi Saya** hanya memuat `v3_pelanggaran_dicatat`, tanpa satu pun peristiwa konseling |
| Admin mengawasi | Admin membuka kasus Rahasia #1: tujuan, sesi, dan formulir koreksi kasus terlihat beserta keterangan bahwa koreksi disimpan sebagai revisi |
| Pemilik tetap melihat kasusnya | Daftar pembimbing memuat kasus #1 (Dalam Pendampingan, 1 sesi) |
| Pengembalian poin uji | Pelanggaran #7 dibatalkan dengan alasan `SMOKE AUDIT F3 — uji pembatalan`: status Dibatalkan versi 2, ledger `-2 · Pembalik karena pembatalan (pembalik #10)`, `Agregat 2 · ledger 2 · selisih 0`, dan riwayat revisi menampilkan alasan pembatalan terpisah (T2 utuh) |
| Tautan tetap terbaca sesudah pembatalan | Detail pelanggaran #7 yang sudah dibatalkan tetap menampilkan `Kasus #1 · Dalam Pendampingan · sesi #1` bagi pemiliknya (K3) |

Identitas sesi pada langkah `?id=1` disimpulkan dari kesinambungan jendela privat
yang sama (login murobi smoke 20.39–20.49); halaman 403 memang tidak menampilkan
header akun.

### 10.3 Belum diuji sesudah putaran 12 September

Daftar ini adalah keadaan pada 12 September. Sebagian sudah tertutup pada
putaran 14 September (bagian 10.5); sisa terkini ada di bagian 10.6.

- Kasus **Internal** dan pembacaan isinya oleh murobi, termasuk tampilan cetak.
- Penyelesaian sesi lewat formulir web (K5), koreksi sesi beserta penolakan
  koreksi pengosong (K6), dan penjadwalan ulang.
- Koreksi kasus menjadi revisi berbaris; `v3_konseling_kasus_revisi` masih kosong
  di produksi.
- Penutupan `Selesai` maupun pembatalan kasus beserta penutupan otomatis sesi
  yang masih terjadwal.
- **Rekomendasi.** Satu-satunya rekomendasi produksi (`Perhatian Awal`, snapshot
  total 12) berstatus *Tidak berlaku* karena total poin santri smoke hanya 2–4,
  di luar rentang ambang. Penautan manual dan pelepasan saat kasus dibatalkan
  belum terbukti di produksi.
- Penolakan orang tua, penolakan pembimbing bukan pemilik, tampilan 375 px, dan
  post-check verifier sesudah data smoke terbentuk.
- Dua query bukti pada panduan gagal karena placeholder belum diganti
  (`#1054 Unknown column 'ID_RAHASIA'`), sehingga jumlah audit akses admin dan
  status sesi/rekomendasi belum terverifikasi lewat SQL.

### 10.4 Catatan kecil dari bukti 12 September

- Detail kasus menampilkan pelanggaran yang sama pada dua baris ketika ditautkan
  di tingkat kasus dan di tingkat sesi (`Pelanggaran #7` dan
  `Pelanggaran #7 · sesi #1`). Catatan pelanggarannya tetap satu; ini soal
  tampilan, bukan duplikasi data.
- Kartu rekomendasi menampilkan alasan historis `Total poin 2 berada di luar
  rentang ambang` walaupun total saat itu 4. Alasan memang direkam ketika penanda
  dipasang dan tidak ditulis ulang selama status berlakunya tidak berubah
  (perilaku T3 Fase 2).

### 10.5 Skenario kasus Internal — 14 September 2026, LULUS

Putaran kedua dijalankan Human Developer pukul 22.21–22.31 pada hosting yang
sama, dengan pembimbing `PENGURUS SMOKE AUDIT` dan murobi `GURU SMOKE AUDIT`
(jendela privat terpisah). Tangkapan layar ditinjau satu per satu; hanya yang
terlihat pada bukti yang dicatat. Kasus kedua ini dibuka untuk
`SANTRI SMOKE AUDIT` 2026/2027 Ganjil, kerahasiaan **Internal**, tujuan
`Percobaan kerahasiaan internal, apakah masuk atau tidak dibaca oleh murobi?`,
dengan tautan pelanggaran #6.

| Langkah | Bukti |
| --- | --- |
| Kasus Internal dibuka pembimbing | "Kasus konseling dibuka dengan audit dan tautan yang dipilih"; status Dibuka, versi 1, keterangan `Diketahui pembimbing dan murobi terkait.`, tautan `Pelanggaran #6` |
| Murobi terkait membaca kasus Internal | Daftar konseling murobi memuat kasus ini (Dibuka, 0 sesi); detail terbuka penuh dengan tujuan, kerahasiaan, versi, tautan, dan timeline — sekaligus tetap **tanpa** kasus Rahasia #1 pada daftar yang sama |
| Notifikasi murobi untuk kasus Internal | `v3_konseling_dibuka` diterima 22.23.34 dan `v3_konseling_sesi` 22.27.03, keduanya dari nol menjadi terbaca; kontras dengan kasus Rahasia yang tidak menghasilkan satu pun notifikasi konseling |
| Tanda mengetahui murobi (tingkat kasus) | Catatan `baik, murobi mengetahui kasusnya` tersimpan; Riwayat catatan murobi menampilkan `2026-09-14 22:25:17 · kasus — baik, murobi mengetahui kasusnya`, terpisah dari catatan internal |
| Transisi tak sah ditolak tanpa tulis parsial | Percobaan `Dibuka → Selesai` dengan ringkasan penutupan terisi menghasilkan "Gagal · Transisi status kasus tidak sah"; kasus tetap Dibuka dan versi tetap 1 (aturan `Dibuka` hanya boleh ke `Dalam Pendampingan`/`Dibatalkan`) |
| Sesi dijadwalkan dan status berpindah otomatis | Sesi #2 (jadwal 15 September 22.26) dengan rencana `menjadwalkan sesi baru` dan tautan #6; kasus menjadi **Dalam Pendampingan** versi 2 tanpa langkah status terpisah |
| Sesi diselesaikan lewat formulir web | Sesi #2 berstatus Selesai dengan realisasi 14 September 22.29, `Ringkasan internal: sesi ini selesai`, `Hasil: hasilnya selesai`, dan rencana tindak lanjut `menjadwalkan sesi baru` **tetap utuh** — perilaku K5 (isian kosong tidak menghapus nilai tersimpan) terlihat pada hasilnya |
| Murobi membaca isi sesi kasus Internal | Tampilan murobi atas sesi Selesai memuat ringkasan internal dan hasil, sesuai keputusan Human Developer bahwa Internal diketahui pembimbing dan murobi (`KonselingService.php:220`) |
| Penutupan kasus | `Selesai` dengan ringkasan `kasus ini selesai` berhasil: versi 3, timeline tiga peristiwa (22.21.00 kasus dibuka, 22.29.00 sesi selesai, 22.30.33 kasus selesai) |
| Kasus tertutup tetap terbaca murobi | Sesudah penutupan, daftar murobi menampilkan kasus Selesai dengan 1 sesi dan detailnya memuat ringkasan penutupan serta timeline lengkap |
| Daftar pembimbing memuat kedua kasus | Kasus Internal (Selesai, 1 sesi) dan kasus Rahasia 12 September (Dalam Pendampingan, 1 sesi) berdampingan pada daftar pemiliknya |

Dengan putaran ini, pemisahan kerahasiaan terbukti dua arah pada data produksi:
murobi terkait membaca kasus Internal beserta isi sesinya dan menerima
notifikasinya, sementara kasus Rahasia pada santri yang sama tetap tidak terlihat,
tidak dapat dibuka, dan tidak memicu notifikasi apa pun untuk murobi yang sama.

### 10.6 Sisa yang belum diuji di produksi — per 14 September 2026

- **Penutupan otomatis sesi terjadwal.** Kasus ditutup ketika sesi satu-satunya
  sudah Selesai, sehingga jalur "sesi terjadwal ikut menjadi Dibatalkan dengan
  alasan sistem" belum pernah berjalan pada data produksi. Pembatalan kasus juga
  belum dicoba.
- **Revisi kasus.** `Riwayat revisi kasus` masih "Belum ada koreksi";
  `v3_konseling_kasus_revisi` diperkirakan tetap kosong di produksi.
- **Koreksi sesi dan penjadwalan ulang.** Panel `Koreksi sesi dengan revisi`
  terlihat pada bukti tetapi tidak dibuka; penolakan koreksi pengosong (K6) dan
  `Dijadwalkan Ulang` belum diuji.
- **Tanda mengetahui murobi tingkat sesi.** Catatan `sesi ini telah diketahui
  oleh murobi` terlihat diketik pada 22.28, tetapi tidak ada tangkapan layar yang
  menampilkannya kembali pada Riwayat catatan murobi, sehingga penyimpanannya
  tidak saya klaim.
- **Rekomendasi.** Masih satu-satunya rekomendasi produksi berstatus *Tidak
  berlaku*; penautan manual dan pelepasan saat kasus dibatalkan belum terbukti.
- **Penolakan orang tua** dan penolakan pembimbing bukan pemilik.
- **Tampilan 375 px** dan **post-check verifier** sesudah data smoke terbentuk —
  keempat perintah CLI pada 10.1 dijalankan ketika manifest masih nol kasus.

Sebagian daftar ini tertutup pada putaran 15 September (bagian 10.8); sisa
terkini ada di bagian 10.9.

### 10.7 Catatan kecil dari bukti 14 September

- Tampilan dua baris untuk satu pelanggaran terulang di sini (`Pelanggaran #6` dan
  `Pelanggaran #6 · sesi #2`) karena pelanggaran ditautkan sekaligus di tingkat
  kasus dan tingkat sesi. Sama seperti 12 September: soal tampilan, bukan
  penggandaan data.
- Timeline memperbarui peristiwa sesi di tempat, bukan menambah baris: entri yang
  semula `Sesi · Dijadwalkan` (jadwal 15 September) berubah menjadi
  `Sesi · Selesai` (realisasi 14 September) sesudah sesi diselesaikan.

### 10.8 Putaran ketiga — 15 September 2026, LULUS

Dijalankan Human Developer pukul 07.45–07.51, ditutup dengan post-check verifier
pada shell hosting. Putaran ini berfokus pada siklus pelanggaran–poin, penolakan
lintas cakupan, dan tampilan sempit.

| Langkah | Bukti |
| --- | --- |
| Pelanggaran baru dicatat pembimbing | Catatan #8 untuk `SANTRI SMOKE AUDIT`, jenis `KDS.TLM — Terlambat masuk kelas · Ringan · 2 poin`, waktu 15 September 07.45, uraian `ini adalah uji coba. untuk pembatalan kasus`; "Pelanggaran dicatat dan poin direkonsiliasi", status Dicatat versi 1, `Agregat 4 · ledger 4 · selisih 0` |
| Murobi di luar cakupan tidak melihat apa pun | Akun murobi lain (bukan murobi terkait santri smoke) mendapat "Belum ada catatan — Belum ada pelanggaran yang dapat dibaca dalam cakupan aktif Anda" pada daftar pelanggaran |
| ID tebakan ditolak | `portal/v3_pelanggaran_detail.php?id=8` pada akun yang sama → `403 — Akses ditolak`, "Catatan tidak ditemukan atau tidak dapat diakses. Pelanggaran berada di luar cakupan pengguna." |
| Tampilan jendela sempit | Detail pelanggaran pada lebar ponsel: menu menjadi hamburger, kartu menumpuk satu kolom, angka poin dan rekonsiliasi tetap terbaca, tanpa luapan horizontal |
| Pembatalan dengan pembalik poin | Alasan `uji coba pembatalan kasus` → "Perubahan tersimpan dengan audit dan riwayat": status Dibatalkan versi 2, alasan pembatalan tersimpan pada barisnya sendiri (T2 utuh), `Agregat 2 · ledger 2 · selisih 0`, dan daftar pelanggaran ikut berubah menjadi Dibatalkan |
| Riwayat tidak tertimpa | Panel `Riwayat revisi` tetap menampilkan `Catatan #8 · versi 1 · Dicatat` sesudah pembatalan; formulir koreksi (`Buat revisi`) dan pembatalan tetap terpisah |
| Post-check verifier sesudah ada data smoke | `php bin/v3_phase3_verify.php` di `public_html` hosting: **34** pemeriksaan lulus, `exit=0`, dijalankan dua kali dengan hasil identik |

Post-check ini penting karena kali ini produksi sudah memuat data konseling
nyata: `Kasus tertutup tidak menyisakan sesi terjadwal`, `Sesi selesai terkini
memiliki realisasi, ringkasan internal, dan hasil`, `Kasus selesai memiliki waktu
dan ringkasan penutupan`, serta kedua invariant revisi kasus lulus terhadap kasus
Rahasia #1 dan kasus Internal 14 September — bukan lagi terhadap tabel kosong.

### 10.9 Sisa yang belum diuji di produksi — per 15 September 2026

- **Pembatalan kasus dan penutupan otomatis sesi terjadwal.** Uraian pelanggaran
  #8 menyebut "untuk pembatalan kasus", tetapi bukti yang dikirim berhenti pada
  pembatalan pelanggaran; tidak ada tangkapan layar kasus yang dibatalkan. Jalur
  "sesi terjadwal ikut menjadi Dibatalkan dengan alasan sistem" karena itu masih
  belum pernah berjalan pada data produksi.
- **Revisi kasus.** Belum ada koreksi kasus; `v3_konseling_kasus_revisi`
  diperkirakan tetap kosong (verifier lulus secara hampa untuk baris revisi).
- **Koreksi sesi, penolakan koreksi pengosong (K6), dan penjadwalan ulang.**
- **Tanda mengetahui murobi tingkat sesi** — masih belum terlihat tersimpan.
- **Rekomendasi.** Tetap berstatus *Tidak berlaku*; penautan manual dan pelepasan
  saat kasus dibatalkan belum terbukti.
- **Penolakan orang tua** pada halaman dan endpoint internal.
- **Post-check `v3_verify` dan `v3_phase2_verify`** sesudah data smoke — pada 15
  September hanya `v3_phase3_verify` yang dijalankan ulang.

### 10.10 Catatan kecil dari bukti 15 September

- Kartu rekomendasi masih menampilkan alasan historis `Total poin 2 berada di
  luar rentang ambang` ketika total sedang 4, sama seperti catatan 12 September:
  alasan direkam saat penanda dipasang dan tidak ditulis ulang selama status
  berlakunya tidak berubah (T3 Fase 2).
- Penolakan pada ID tebakan memakai halaman `403` yang sama tanpa membocorkan
  keberadaan catatan; pesannya berbunyi "tidak ditemukan atau tidak dapat
  diakses", bukan menegaskan bahwa catatan #8 ada.

## 11. Keputusan Human Developer — 15 September 2026

Dua keputusan diambil sesudah putaran smoke ketiga.

1. **Sisa smoke test produksi dilewati.** Jalur pada bagian 10.9 tidak dijalankan
   dan tidak diklaim lulus; risikonya diwariskan ke Fase 4 lewat
   [handoff](handoff-ke-fase-4.md) bagian 6.
2. **Kasus `Rahasia` tidak boleh diterbitkan kepada orang tua.** Publikasi baru
   mungkin sesudah kerahasiaan dikoreksi menjadi `Internal` melalui revisi kasus,
   sehingga perubahannya terekam dan aturan kerahasiaan tetap satu pintu. Dicatat
   pada PRD V3 bagian 5.5a; konsekuensi rancangannya di
   [handoff](handoff-ke-fase-4.md) bagian 9.

Keputusan 2 tidak mengubah kode Fase 3: kasus Rahasia memang sudah tidak memiliki
jalur publikasi, dan `parentSerializer()` belum dirutekan ke mana pun. Yang
berubah adalah batasan yang mengikat Fase 4.
