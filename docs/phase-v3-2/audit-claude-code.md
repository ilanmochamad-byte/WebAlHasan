# Bukti audit Fase 2 — Claude Code

Auditor: Claude Code (peran ditetapkan `AGENTS.md` untuk workstream PRD V3 Fase 1–5).
Implementator: Codex. Branch `prd-v3-fase-2`, commit yang diaudit `247db8a`,
baseline `af6285f`. Tanggal audit: 10 September 2026.

Audit ini memeriksa commit implementator terhadap PRD V3 Fase 2, menjalankan
pengujian secara independen, dan menerapkan koreksi terarah beserta regresinya.
Tidak ada merge dan tidak ada deploy produksi.

## 1. Kesimpulan

Seluruh **10 kriteria penerimaan Fase 2 terpenuhi** dan kedua belas persyaratan
implementasi terimplementasi. Tidak ada kode Fase 3 yang masuk. Halaman
pelanggaran warisan tetap baca-saja dan `App\Api\ApiAuthService::menus()` tidak
disentuh, sehingga kontrak menu aplikasi tidak berubah.

Bukti pengujian implementator terbukti akurat: angka regresi 4.014 pemeriksaan
pada 49 paket direproduksi persis, termasuk residu fixture 24 outbox dan 6 audit
yatim yang memang sudah dicatat terbuka di `test-results.md`.

Enam temuan diperbaiki pada audit ini (T1–T6). Tidak satu pun menggagalkan
kriteria penerimaan, tetapi T1–T3 dan T6 menyentuh perilaku koreksi/pembatalan
yang akan dipakai Fase 3 sehingga diperbaiki sekarang, bukan diwariskan.

Sesudah koreksi T1–T5 dideploy, Human Developer menjalankan migrasi dan smoke
test pada hosting cPanel. Hasilnya menutup dua batas bukti yang sebelumnya
terbuka dan sekaligus memunculkan T6. Rinciannya di bagian 7.

## 2. Reproduksi pengujian implementator

Lingkungan: PHP 8.4.14, MariaDB 12.3.2 lokal, database `webalhasan_v3_phase1_test`,
fixture `sbx_*`. Tidak ada akses, migrasi, atau penghapusan data produksi.

| Paket | Hasil sebelum koreksi | Hasil sesudah koreksi |
| --- | --- | --- |
| `bin/v3_phase2_run_tests.sh` | 131 pemeriksaan, exit 0 | **172** pemeriksaan, exit 0 |
| `bin/v3_phase1_run_tests.sh` | 71 pemeriksaan, exit 0 | 71 pemeriksaan, exit 0 |
| `tests/v3_phase2_migration.php` | 9 pemeriksaan, exit 0 | **18** pemeriksaan, exit 0 |
| `bin/v3_verify.php` | exit 0 | exit 0 |
| `bin/v3_phase2_verify.php` | exit 0 | exit 0 |
| `bin/penugasan_run_all_tests.sh` | 49 paket / 4.014 pemeriksaan, exit 0 | 49 paket / 4.014 pemeriksaan, exit 0 |

Catatan kejujuran bukti: pada satu kali jalan, `tests/v2_phase3_api_contract.php`
gagal di dalam runner penuh tepat sesudah suite V3 Fase 2 dijalankan, lalu lulus
pada run bersih dan lulus terisolasi pada `af6285f` **maupun** `247db8a`
(116 pemeriksaan, exit 0).
Kegagalan itu bergantung urutan/fixture, bukan regresi Fase 2. Perilaku ini
dicatat di sini supaya sesi berikutnya tidak menyimpulkan sebaliknya.

**Belum diuji, tidak diklaim:** suite peramban `tests/browser/uji-v3-fase2.mjs`
(butuh server hidup dan Chromium), MariaDB hosting/cPanel, migrasi atau smoke
produksi, Safari, pembaca layar nyata, aplikasi Android/iOS terpasang, push
fisik, dan performa pada volume besar.

## 3. Temuan dan koreksi

### T1 — Fingerprint memblokir koreksi balik dan pencatatan ulang (sedang)

Indeks unik `pelanggaran_fingerprint (santri_id, tahun_ajaran_id, fingerprint)`
mencakup baris yang sudah digantikan revisi **dan** yang dibatalkan. Terbukti
pada DB uji sebelum koreksi:

- koreksi tempat `Aula` → `Masjid`, lalu koreksi balik ke `Aula` → `409`
- catat → batalkan → catat ulang kejadian yang sama → `409`

Keduanya muncul sebagai `Permintaan menduplikasi catatan yang sudah ada`.
Persyaratan 7 Fase 2 menuntut koreksi dan pembatalan beralasan selalu tersedia.

**Koreksi.** Catatan yang tidak lagi berlaku melepas fingerprint-nya
(`fingerprint=NULL`) saat digantikan revisi atau dibatalkan, sehingga slot unik
hanya ditahan catatan yang masih berlaku. Indeks tidak diubah dan penolakan
duplikat untuk catatan hidup tetap berlaku. Nilai fingerprint adalah turunan
murni dari kolom bisnis yang tetap tersimpan (`katalog_id`, `waktu_kejadian`,
`tempat`, `uraian`, `saksi`, dan ketiga snapshot), jadi selalu dapat dihitung
ulang dan tidak ada riwayat yang hilang.

### T2 — Pembatalan menimpa alasan koreksi (sedang)

`PelanggaranRepository::cancelViolation()` menulis alasan pembatalan ke
`alasan_revisi`, sehingga alasan koreksi sebelumnya hilang dari baris dan dari
daftar "Riwayat revisi". Terbukti: `'Salah tempat'` berubah menjadi
`'Dibatalkan karena salah santri'`. Melanggar PRD 5.4 ("tidak diedit dengan
menimpa nilai historis") dan persyaratan 7.

**Koreksi.** Kolom `alasan_pembatalan` terpisah. Pembatalan tidak pernah lagi
menulis ke `alasan_revisi`. Halaman detail menampilkan keduanya secara eksplisit,
dan serializer detail mengembalikan keduanya.

### T3 — Rekomendasi menjadi basi setelah pembatalan (sedang)

Membatalkan pelanggaran tidak menyentuh rekomendasi yang dipicunya:
`dipicu_oleh_pelanggaran_id` tetap menunjuk baris `Dibatalkan` dan
`total_poin_snapshot` sudah tidak berlaku. Karena `rekomendasi_ambang_subjek_unik`,
ambang itu juga tidak pernah memicu lagi ketika poin kembali naik.

**Koreksi.** `refreshRecommendationValidity()` dijalankan di dalam transaksi
yang sama sesudah setiap rekonsiliasi pada `create`, `correct`, dan `cancel`.
Ketika total keluar dari rentang ambang, barisnya ditandai `tidak_berlaku_pada`
beserta alasannya; ketika total kembali masuk rentang, penanda itu dilepas.
Rekomendasi tidak pernah dihapus dan tetap tepat satu per santri/tahun/ambang,
sehingga kriteria "ambang tercapai menghasilkan satu rekomendasi" tetap utuh.
Perubahan masuk payload dan audit sebagai `rekomendasi_disesuaikan`.

### T4 — Agregat tidak dipulihkan sesudah rollback dan pasang ulang (rendah)

Rollback Fase 2 membuang `v3_poin_agregat` sementara `v3_poin_ledger` tetap utuh,
dan pemasangan ulang 014 membuat tabelnya kosong tanpa membangun kembali total.
Direproduksi dengan menjalankan drill setelah data ada: `bin/v3_phase2_verify.php`
langsung melaporkan blocker "Agregat dapat direkonsiliasi dari seluruh ledger".
Total yang ditampilkan tetap benar karena `show()` menjumlahkan ledger, tetapi
verifier merah sampai tiap subjek tersentuh mutasi baru.

Sifat destruktif rollback sendiri sudah didokumentasikan jujur oleh implementator
di `migrasi-dan-rollback.md`; yang kurang adalah pemulihannya.

**Koreksi.** Migrasi 015 mem-backfill agregat dari ledger sehingga pemasangan
ulang swa-pulih, dan `bin/v3_rekonsiliasi_agregat.php` menyediakan pemulihan
manual dengan `--dry-run` serta post-check. Drill migrasi kini membuktikan
rangkaian rollback 015 → rollback 014 → pasang ulang keduanya berakhir dengan
selisih agregat nol tanpa satu pun mutasi baru.

### T5 — Dokumen internal dapat diunduh dari web (rendah)

`PRD-V3.md` tidak masuk daftar tolak `.htaccess` yang sudah memuat `PRD.md` dan
`PRD-V2.md`. Warisan Fase 1. `design.md` sekelas dan ikut ditutup.

**Koreksi.** Kedua berkas ditambahkan ke `FilesMatch` di `.htaccess` root.

### T6 — Koreksi yang tidak mengubah isi ditolak sebagai duplikat (sedang)

Ditemukan dari smoke test produksi, bukan dari suite. Koreksi yang tidak mengubah
satu pun field pembentuk fingerprint — misalnya hanya mengisi alasan, atau
melampirkan bukti susulan — ditolak `409 Permintaan menduplikasi catatan yang
sudah ada`.

Sebabnya urutan di `PelanggaranService::correct()`: baris revisi baru di-`insert`
sebelum baris sumber melepas fingerprint-nya. Ketika isinya tidak berubah,
fingerprint keduanya sama dan revisi bertabrakan dengan catatan yang justru
sedang dikoreksi.

Koreksi T1 menutup dua kasus lain dari famili yang sama (koreksi balik ke nilai
catatan yang sudah digantikan, dan pencatatan ulang sesudah pembatalan) tetapi
tidak kasus ini, karena uji regresi T1 selalu mengubah `tempat`. Ini kelalaian
dalam merancang uji, bukan pada perbaikannya.

Skenario terdampak yang paling nyata: pembimbing ingin melampirkan bukti foto
belakangan pada catatan yang isinya sudah benar. Lampiran bukan bagian
fingerprint, sehingga koreksi itu selalu ditolak.

**Koreksi.** `updateViolationVersion()` — yang melepas fingerprint sumber —
dipindahkan ke sebelum `insertViolation()`. Keduanya tetap dalam satu transaksi,
dan pemeriksaan versi menjadi gagal-cepat sebelum ada baris revisi yang tertulis.
Uji regresi ditambahkan untuk koreksi tanpa perubahan isi maupun untuk pelampiran
bukti susulan.

## 4. Migrasi 015

`database/migrations/015_v3_fase2_koreksi_dan_rekomendasi.sql` bersifat aditif
dan idempoten. Migrasi 001–014 tidak diubah. Isinya: kolom `alasan_pembatalan`,
kolom `tidak_berlaku_pada`/`tidak_berlaku_alasan` beserta indeks antreannya,
pemindahan alasan pembatalan lama, pelepasan fingerprint catatan yang tidak
berlaku, backfill agregat dari ledger, dan penandaan rekomendasi yang totalnya
sudah di luar rentang.

Tidak ada `DROP TABLE` maupun `DELETE FROM`; tidak ada catatan bisnis yang
dihapus. Rollback `015` hanya melepas struktur miliknya sendiri.

Batas yang harus diketahui operator: rollback 015 membuang kolom
`alasan_pembatalan` beserta isinya. Ketika 015 dipasang lagi, catatan batal yang
alasannya sudah tidak ada di baris **tidak dikarang ulang** — diberi penanda
jujur bahwa nilainya tidak tersedia, sedangkan alasan aslinya tetap tersimpan di
`audit_logs` peristiwa pembatalan. Utamakan rollback kode, bukan rollback skema.

## 5. Invariant baru yang kini dijaga otomatis

`bin/v3_phase2_verify.php` bertambah enam pemeriksaan:

- migrasi 015 tercatat
- fingerprint hanya menahan catatan yang masih berlaku
- setiap pembatalan menyimpan alasannya sendiri
- pembatalan tidak menulis ke alasan revisi
- rekomendasi basi ditandai tidak berlaku
- penanda dan alasan tidak berlaku selalu berpasangan

`tests/v3_phase2_integration.php` bertambah 19 pemeriksaan regresi untuk T1–T3
dan T6 (34 → 53 assertion), `tests/v3_phase2_static.php` menjaga bentuk migrasi
015, `.htaccess`, serta urutan pelepasan fingerprint sebelum penulisan revisi,
dan `tests/v3_phase2_migration.php` menjadi drill dua migrasi dengan bukti
agregat swa-pulih (9 → 18 pemeriksaan).

Rincian pemeriksaan Fase 2 sesudah koreksi: statis 69, integrasi 67, konkurensi 6,
verifier 30 — total 172.

## 6. Catatan kecil yang tidak diperbaiki

Dicatat supaya keputusannya sadar, bukan terlewat:

- `cakupan_snapshot.sumber_capability` menyimpan provenance capability saat
  `create` tetapi kapasitas (`admin`/`pembimbing`) saat `correct` — dua arti
  pada satu field. Kosmetik, tidak memengaruhi otorisasi.
- PRD 5.4 mengizinkan pembimbing menyesuaikan poin "jika diberi izin khusus";
  implementasi membatasinya hanya ke pemegang `v3.koreksi`. Pembacaan ini masuk
  akal dan lebih ketat, tetapi belum tercatat di `desain-dan-aturan.md`.
- Formulir koreksi/pembatalan pada halaman detail tampil berdasarkan capability
  saja, bukan cakupan per catatan; server tetap menolak `403`. UX, bukan akses.
- Berkas staging `.v3-stage-*` yang tertinggal karena proses mati mendadak belum
  masuk pemeriksaan "tidak ada lampiran pending" yang kini hanya melihat akhiran
  `.pending`.
- ~~Halaman notifikasi masih berjudul "Pemberitahuan perizinan untuk akun Anda"
  dengan breadcrumb `Beranda / Perizinan / Notifikasi`, padahal sekarang memuat
  peristiwa V3.~~ **Sudah diperbaiki** atas permintaan Human Developer sesudah
  terlihat pada bukti produksi: deskripsi menjadi "Pemberitahuan untuk akun
  Anda" dan breadcrumb menjadi `Beranda / Notifikasi`. Breadcrumb default
  `portal_header()` sengaja tidak diubah karena seluruh halaman lain yang
  memakainya memang milik modul perizinan. Gating menu "Notifikasi Saya"
  diperiksa dan tidak diubah: setiap penerima notifikasi V3 pasti memiliki
  salah satu capability perizinan, sehingga tidak ada penerima yang kehilangan
  menunya.

## 6a. Keputusan operasional: akun smoke test produksi

**Keputusan Human Developer, 10 September 2026: akun dan santri smoke test
dipertahankan aktif di produksi, tidak dinonaktifkan sesudah Fase 2.**

Alasannya: Fase 3–5 akan memerlukan smoke test produksi juga, dan membangun
ulang rantai akun → penugasan pembimbing → penempatan kelas/kamar setiap fase
jauh lebih mahal daripada memelihara satu set yang sudah terbukti berjalan.
Penonaktifan dilakukan hanya ketika set ini benar-benar sudah tidak dibutuhkan.

Keputusan ini menggantikan saran auditor sebelumnya yang menganjurkan
penonaktifan sesudah Fase 2. Sesi berikutnya jangan memakai saran lama itu.

Entitas terkait: akun `PENGURUS SMOKE AUDIT` (pengurus dengan penugasan
pembimbing aktif) dan santri `SANTRI SMOKE AUDIT`.

Syarat yang menyertai keputusan ini:

- **Poin uji dikembalikan sesudah dipakai.** Catatan uji dibatalkan dengan
  alasan sehingga pembalik poin mengembalikan total tanpa menghapus riwayat.
  Penting menjelang Fase 5 yang membangun laporan teragregasi.
- **Kredensial akun smoke tidak dibagikan.** Akun ini memegang penugasan
  pembimbing aktif, jadi kewenangannya nyata di produksi.
- **Penamaan `SMOKE AUDIT` dipertahankan** agar barisnya selalu dapat dikenali
  pada laporan dan pemeriksaan.

Catatan untuk Fase 4. Cakupan pembimbing berasal dari penempatan kelas/kamar,
bukan dari relasi wali; santri dapat masuk cakupan tanpa wali sama sekali.
Pemeriksaan pada 10 September 2026 pukul 21.44 memang menemukan `SANTRI SMOKE
AUDIT` belum mempunyai relasi `santri_wali`, sehingga publikasi orang tua Fase 4
belum akan punya penerima.

Human Developer kemudian menambahkan wali fiktif `ORANG TUA SMOKE AUDIT`
(hubungan Ayah, status aktif) dan menautkannya ke santri tersebut. Pemeriksaan
ulang pukul 21.55 menunjukkan `total_relasi_wali` bernilai 1, dengan satu
penempatan kelas aktif dan satu kamar. Set smoke kini memenuhi prasyarat data
untuk menguji publikasi orang tua.

Akun login walinya juga sudah ada dan diverifikasi pada 10 September 2026:
`ortu_smoke.audit`, role `orang_tua`, status aktif, dengan satu santri terhubung.
Ini penting karena relasi wali saja belum cukup — publikasi Fase 4 hanya sampai
kepada wali yang mempunyai akun (`users.wali_id`).

Dengan demikian rantai prasyarat data untuk menguji publikasi orang tua sudah
lengkap: santri berpenempatan aktif → relasi `santri_wali` aktif → wali →
akun login dengan role `orang_tua`. Tidak ada lagi prasyarat data yang tertunda
untuk smoke test Fase 4.

Seluruh wali smoke wajib fiktif. Menautkan santri uji ke wali sungguhan akan
membuat publikasi uji coba terkirim kepada orang tua betulan begitu Fase 4
aktif; itu satu-satunya jalur nyata data uji ini dapat bocor keluar sistem.

## 7. Bukti produksi (hosting cPanel, 10 September 2026)

Dijalankan Human Developer pada host produksi sesudah koreksi T1–T5 dideploy.
Keluaran perintah dan tangkapan layar ditinjau langsung selama sesi audit; yang
dicatat di bawah hanya hal yang benar-benar terlihat pada bukti tersebut.

**Migrasi.** `php bin/migrate.php up` menerapkan `014` dan `015` pada MariaDB
hosting tanpa galat. `bin/v3_verify.php` lulus penuh: seluruh tabel, kolom,
constraint, indeks, dan **nol referensi yatim** pada semua tabel termasuk
`v3_poin_agregat` dan `v3_rekomendasi`. Ini menutup batas bukti "MariaDB
hosting/cPanel" dan "migrasi produksi" yang sebelumnya terbuka.

**Smoke test operasional.** Satu santri, dua pencatatan, dua koreksi berurutan,
satu pembatalan, dan satu pencatatan ulang. Ledger berakhir tujuh entri
berpasangan dan `Agregat 2 · ledger 2 · selisih 0`. Karena itu pemeriksaan
`Agregat dapat direkonsiliasi dari seluruh ledger` kini lulus atas data nyata,
bukan atas tabel kosong seperti pada run pertama sesudah migrasi.

**T2 terbukti di produksi.** Riwayat revisi menampilkan
`Catatan #5 · versi 2 · Dibatalkan — koreksi: koreksi lagi — pembatalan:
batalkan ini uji coba`. Kedua alasan hidup berdampingan pada satu baris; sebelum
koreksi, alasan koreksi akan tertimpa alasan pembatalan.

**T3 terbukti dua arah di produksi.** Rekomendasi `Perhatian Awal` terbit ketika
total mencapai 12, lalu sesudah pembatalan berubah menjadi **Tidak berlaku**
dengan keterangan `snapshot total 12 · Total poin 2 berada di luar rentang
ambang`. Snapshot lama tetap utuh, baris tidak dihapus, dan tidak terbentuk
baris kedua.

**Sebagian T1 terbukti.** Pencatatan ulang kejadian sesudah pembatalan berhasil.

**Kriteria penerimaan #9 terbukti di produksi.** Notifikasi in-app
`v3_rekomendasi_baru` berbunyi "Ada rekomendasi pembinaan baru. Masuk untuk
melihat sesuai kewenangan." — tanpa nama santri, kategori, uraian, poin, atau
nomor telepon.

**T6 muncul dari sesi ini.** Koreksi yang hanya mengisi alasan ditolak `409`.
Diagnosis dibaca dari kode lalu direproduksi pada database uji sebelum
diperbaiki; lihat bagian 3.

**T6 terbukti tertutup di produksi (deploy kedua).** Sesudah perbaikan dideploy,
koreksi yang hanya mengisi `Alasan koreksi` tanpa menyentuh waktu, tempat,
uraian, maupun saksi berhasil: halaman menampilkan "Perubahan tersimpan dengan
audit dan riwayat", catatan sumber naik ke versi 2, dan revisi baru terbentuk
dengan alasan "ujicoba koreksi hanya mengisi alasan koreksi tanpa mengubah
uraian". Riwayat revisi menampilkan keduanya sebagai rantai, dan poin tetap
`Agregat 2 · ledger 2 · selisih 0` — koreksi tanpa perubahan isi tidak merusak
rekonsiliasi.

`php bin/v3_phase2_verify.php` pada hosting: 30 pemeriksaan lulus, tanpa blocker.
Angka itu diambil sebelum perbaikan T6 dideploy; penjaga statis urutan
fingerprint hidup di suite `tests/`, bukan di verifier, sehingga jumlah
pemeriksaan verifier tidak berubah karenanya.

Seluruh alur produksi di atas dijalankan pada Safari macOS. Itu membuktikan
halaman Fase 2 dapat dipakai di Safari untuk alur tersebut, tetapi bukan
pengganti suite peramban otomatis yang tetap belum dijalankan.

**Tanda mengetahui murobi terbukti di produksi (10 September 2026).** Prasyarat
diperiksa lebih dahulu dengan query yang meniru `relatedMurobiUsers()`: akun
`guru_smoke.audit` memegang penugasan murobi bertarget Kamar yang mencakup santri
smoke. Login sebagai akun itu memunculkan badge kemampuan aktif **Murobi**,
daftar pelanggaran terbaca, dan tanda mengetahui tersimpan dengan catatan —
`2026-09-10 23:19:47 — uji catatan singkat murobi di pelanggaran`. Verifier
sesudahnya tetap lulus 30 pemeriksaan tanpa blocker, sehingga baris pertama
`v3_murobi_catatan` di produksi tidak melanggar satu pun invariant.

Pemisahan kewenangan ikut terbukti pada layar yang sama: halaman detail bagi
murobi **tidak memunculkan formulir koreksi maupun pembatalan**, hanya bagian
tanda mengetahui. Ini sesuai keputusan PRD bahwa murobi mengetahui dan memantau,
bukan mengelola.

Batas yang perlu dijaga kejujurannya: daftar milik murobi memang hanya memuat
santri binaannya, tetapi pada produksi belum ada pelanggaran milik santri di
luar cakupannya, sehingga **penyaringan cakupan belum benar-benar teruji di
sana** — yang terlihat baru bahwa data dalam cakupan muncul. Penolakan lintas
cakupan tetap hanya terbukti pada database uji.

**Belum diuji di produksi:** penolakan akses lintas cakupan, lampiran privat,
aplikasi perangkat, suite peramban otomatis, pembaca layar nyata, push fisik,
dan performa volume besar.

## 8. Kebersihan database uji

Audit ini hanya memakai `webalhasan_v3_phase1_test` dan fixture `sbx_*`.
Regresi warisan kembali meninggalkan tepat 24 outbox dan 6 audit yatim seperti
yang sudah dicatat implementator. Asal-usulnya dibuktikan lebih dulu — seluruhnya
bertipe `izin.*` dan `login_succeeded` milik akun fixture yang dihapus suite-nya
sendiri, nol baris `v3_*` — baru kemudian dibersihkan. Verifier tidak dilonggarkan
dan diulang sampai exit 0.
