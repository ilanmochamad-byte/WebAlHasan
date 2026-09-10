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

- **Makna `kerahasiaan`.** PRD 5.5 menyebut tingkat kerahasiaan tanpa
  mendefinisikan `Internal` vs `Rahasia`. Saat ini keduanya identik: murobi hanya
  memperoleh metadata. Perlu keputusan sebelum Fase 4/5.
- **Menutup kasus dengan sesi yang masih terjadwal** diizinkan (suite
  implementator melakukannya). Sesudah K1 sesi itu dibekukan apa adanya.
  Pertimbangkan apakah penutupan `Selesai` harus mensyaratkan sesi terjadwal
  diselesaikan atau dibatalkan dahulu.
- **Koreksi kasus** memperbarui `tujuan`/`kerahasiaan` di tempat;
  nilai lama tersimpan di `audit_logs` (memenuhi PRD 5.2) dan
  `alasan_revisi_terakhir` hanya menyimpan alasan terakhir. PRD 5.5 hanya
  mewajibkan revisi berbaris untuk sesi.
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
