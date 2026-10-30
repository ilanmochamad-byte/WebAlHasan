# Audit Codex — penugasan lintas guru/pengurus, Fase 1

**30 Oktober 2026 — LULUS audit teknis lokal Fase 1 setelah dua koreksi terarah.** Ini bukan persetujuan rilis parsial, merge, deploy, atau migrasi produksi. Fase 2 belum dikerjakan.

## Ruang lingkup dan baseline

- Branch tetap `claude/penugasan-keamanan-fase-1`, tanpa worktree atau subagen.
- Seluruh commit fase yang diperiksa: `3e4aaceef433b2b7d5d6a957d7333e8f93bd0c4d`, di atas `89172955f86ad5ab38e9d66cccf34b318ca1c324` (rancangan dan jawaban PK-T1…PK-T4).
- `git ls-remote` memverifikasi ujung branch fase = `3e4aace`, branch rancangan = `8917295`, dan `main` = `b88078c` sebelum audit. Selisih baseline dengan `origin/main` hanya `AGENTS.md` dan `rancangan.md` (jawaban PK-T), bukan kode.
- Sumber kebenaran: `AGENTS.md`, `../rancangan.md`, README implementator, diff seluruh fase, migrasi/rollback 032, kode akun/master/penugasan/hak, dan pengujian terkait. Bukti implementator dipisahkan dari hasil independen pada `audit-codex/`.
- Lingkungan auditor: PHP **8.4.14**, MariaDB **12.3.2**, Chromium/Playwright. Semua perubahan data dilakukan pada database fixture sintetis `test_*_test`; tidak memakai data pribadi produksi.
- Direktori pekerjaan lain yang sudah belum terlacak (`docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/`, dan tangkapan browser implementator) tidak diubah atau dimasukkan commit. Tidak ada perubahan mobile oleh auditor.

## Temuan dan koreksi

### F-1 · P2 — sinkronisasi identitas tetap commit ketika audit gagal

Pada `MasterDataService::sinkronIdentitasGuru`, nilai balik `AuditLogger::log()` diabaikan. Saat penyimpanan audit `master.identitas_guru.sinkron` gagal, perubahan nama/HP, penonaktifan, atau pengarsipan guru beserta identitas pengurus tetap tersimpan. Transaksi yang membungkus perubahan tidak cukup, karena kegagalan audit hanya mengembalikan `false`.

Probe memasang trigger kegagalan **hanya pada database fixture**, lalu menolak action tersebut. Ketiga jalur tetap menyelesaikan perubahan tanpa exception dan tanpa audit sinkronisasi: enam assertion gagal. Pengujian juga membuktikan audit pembentukan identitas, penghubungan akun, dan penugasan sudah membatalkan seluruh transaksi dengan benar.

**Koreksi:** kegagalan audit sinkronisasi kini melempar exception. Transaksi yang sudah ada mengembalikan guru, pengurus, dan audit lain ke keadaan semula. Tidak mengubah format audit atau aturan bisnis modul lain. Semua enam assertion kemudian lulus.

### F-2 · P3 — perubahan kapitalisasi nama tidak mengikuti Data Guru

Kedua implementasi `sinkronIdentitasGuru` memakai `p.nama <> g.nama_guru`. Pada collation fixture `utf8mb4_unicode_ci`, nama yang hanya berbeda kapitalisasi dianggap sama. Mengubah nama guru menjadi huruf besar atau memakai ulang identitas dengan kapitalisasi yang tertinggal tidak menyamakan nama pengurus; dua assertion gagal.

**Koreksi:** perbandingan nama menggunakan `BINARY` pada repository MasterData dan Penugasan. Nilai nama yang disalin tetap berasal dari guru. Perubahan kapitalisasi dan pemakaian ulang identitas kini lulus, tanpa perubahan skema/collation database.

**Kontrol identik final:** `tests/penugasan_keamanan_f1_audit_probe.php` pada arsip sumber asli `3e4aace`: **12/8**; sumber terkoreksi pada fixture bersih: **20/0**. Versi/oracle probe sama, termasuk dua kasus kapitalisasi. Berkas: `probe-asli-final.txt` dan `probe-sesudah.txt`. `probe-sebelum.txt` adalah versi awal 18 assertion (12/6); `probe-antara.txt` memuat 20 assertion setelah koreksi F-1 saja (18/2).

## Bukti independen

Angka ditulis **lulus/gagal**, bukan jumlah suite.

| Rangkaian | Hasil | Bukti di `audit-codex/` |
| --- | --- | --- |
| Statis Fase 1 + lint seluruh PHP baru/berubah | **57/0** (awal 55/0; bertambah lint dua probe) | `statis-akhir.txt` |
| Integrasi + konkurensi Fase 1 | **70/0** | `integrasi.txt` |
| HTTP web Fase 1 | **34/0** | `http.txt` |
| Migrasi/idempotensi/unique/FK/rollback/preservasi | **22/0** | `migrasi.txt`; ulangan akhir `migrasi-akhir.txt` |
| Browser 375/768/1280px + tanpa JavaScript | **41/0** | `browser.txt` |
| Probe atomisitas audit, kepemilikan dan sinkronisasi | **20/0**, kontrol asli **12/8** | `probe-sesudah.txt`, `probe-asli-final.txt` |
| Token API lama, guru nonaktif/arsip, identitas masih aktif | **10/0** | `token.txt` |
| Fondasi penugasan integrasi / konkurensi / web | **161/0 · 52/0 · 86/0** | `regresi-penugasan.txt`, `regresi-konkurensi.txt`, `regresi-web.txt` |
| Kredensial integrasi / statis / web | **59/0 · 105/0 · 51/0** | `regresi-kredensial.txt`, `regresi-statis-kredensial.txt`, `regresi-kredensial-web.txt` |
| Statis penugasan historis | **281/2**, identik baseline auditor | `regresi-statis-penugasan.txt`, `baseline-penugasan-statis.txt` |
| HTTP Tahap 6 (Data Guru/Pengurus dan penugasan) | **125/7**, identik bukti handoff | `regresi-t6.txt` |

Integrasi dan HTTP Fase 1 diulang setelah koreksi. Browser memakai sumber dengan kedua koreksi, fixture khusus browser, dan aset lokal pengujian. Tujuh tangkapan diperiksa visual (formulir tiga lebar, daftar tersimpan, tanpa JS, Data Guru dan binaan); tangkapan auditor di `/tmp/pkf1-audit-browser/`, tidak di-commit. Pemeriksaan tidak menemukan overflow horizontal, input tanpa label, atau galat JavaScript pada skenario suite. Bukti browser merupakan kondisi lokal, bukan hosting produksi.

Probe token mengakses endpoint BSA yang sudah ada dengan akun guru yang memiliki identitas pengurus dan penugasan BSA. Setelah `guru.is_active=0` atau `guru.archived_at` terisi langsung pada fixture (identitas pengurus sengaja tetap aktif), **token yang sama** menerima 403; `/me/capabilities` menghilangkan capability dan konteks pengurus. Setelah guru dipulihkan, token yang sama kembali mendapat 200. Bearer tidak dicetak atau disimpan dalam bukti.

### Oracle historis dan batas regresi

- Dua kegagalan `penugasan_static` FS-2/FS-4 menyatakan katalog harus berisi tujuh jenis, sementara baseline sudah delapan. Auditor mengeksekusi sumber baseline `8917295` melalui `git archive`; output **281/2 identik byte demi byte** dengan hasil akhir. Oracle tidak diubah.
- Tujuh kegagalan HTTP Tahap 6 berasal dari asumsi bahwa `rollbackLast()` melepas 023; sekarang migrasi terakhir adalah 032. Eksperimen itu memang melepas 032 dan bukan asrama/penanda bukan-ganda 023. Output auditor **125/7 identik byte demi byte** dengan `bukti/regresi/tahap6_http_kini.txt` implementator. Kontrol baseline Tahap 6 milik implementator tetap dipertahankan; auditor tidak mengeksekusi ulang kontrol baseline HTTP itu. Uji khusus migrasi 032 tetap **22/0**. Oracle historis tidak dilonggarkan.
- HTTP Tahap 2 dan semua suite V2–V7 tidak diulang seluruhnya pada audit ini. Bukti implementator bukan diklaim sebagai eksekusi auditor. Pengujian independen dipusatkan pada jalur yang berubah: akun, master guru/pengurus, penugasan, resolver hak, sesi web dan token API lama.

## Penilaian rancangan dan penafsiran

- PK-1/PK-3: keenam jenis berbasis pengurus menerima guru melalui satu identitas miliknya; penugasan tetap memakai `pengurus_id`. Guru tanpa akun tidak dibuatkan akun diam-diam; pembuatan akun guru sesudahnya menghubungkan identitas itu. Pembentukan/penghubungan beraudit dan satu transaksi, termasuk penolakan serta konkurensi.
- PK-2: Murobi dan Guru Mapel tetap guru. Tidak menambah jenis Keamanan, jalur baca baru, role login atau perubahan modul yang seharusnya masuk fase berikutnya.
- Hak pengurus biasa/akun ganda warisan dipertahankan. Identitas milik guru lain, guru nonaktif dan arsip tidak memberi hak. Master Pengurus menolak edit/status untuk identitas guru; master Guru menjadi sumber nama/HP/status.
- PF-1 (jabatan Guru, nomor identitas NULL) dan PF-2 (identitas dipakai ulang, tidak dihapus saat tugas berakhir) diterima: sesuai pendekatan A dan preservasi riwayat.
- PF-3 (akun ganda warisan ditolak pada pilihan Guru, tetap dapat memakai pengurus biasa yang sudah ada) diterima sebagai kompatibilitas, tanpa penggabungan akun otomatis.
- PF-4/PF-5 diterima sesuai keputusan **peringatan saja**: pencocokan nama hanya huruf kecil/spasi dan peringatan di formulir tambah. Tidak mengenali nama dengan penulisan/gelar berbeda dan tidak menjamin dua nama sama adalah satu orang. Pembentukan identitas tetap diperbolehkan; bukan fitur deduplikasi.

## Reproduksi dan catatan pelaksanaan

```bash
php tests/penugasan_keamanan_f1_static.php
bash tests/penugasan_keamanan_f1_db.sh
php tests/penugasan_keamanan_f1_integration.php
PKF1_DB=test_pk_f1_audit_test bash tests/penugasan_keamanan_f1_db.sh
php tests/penugasan_keamanan_f1_audit_probe.php
php tests/penugasan_keamanan_f1_token_probe.php
PKF1_DB=test_pk_f1_audit_http_test bash tests/penugasan_keamanan_f1_db.sh
PKF1_DB=test_pk_f1_audit_http_test php tests/penugasan_keamanan_f1_http.php
PKF1_TANPA_032=1 PKF1_DB=test_pk_f1_audit_mig_final_test bash tests/penugasan_keamanan_f1_db.sh
PKF1_DB=test_pk_f1_audit_mig_final_test php tests/penugasan_keamanan_f1_migration.php
```

- Skrip fixture harus dijalankan berurutan karena memakai salinan kosong perantara bernama tetap. Auditor sempat memulai dua penyiapan dengan tujuan berbeda bersamaan; setelah menyadarinya, tidak memulai penyiapan tambahan sampai keduanya selesai. Integrasi/HTTP/probe final dibangun ulang berurutan. Tidak memakai salinan kosong bersama itu sebagai bukti pengujian.
- Percobaan kontrol sumber asli semula memakai fixture migrasi yang telah berisi penugasan kesehatan dan berhenti karena tumpang tindih. Itu kesalahan penyiapan auditor, bukan temuan aplikasi; log tetap di `diagnostik-fixture-tidak-bersih.txt`. Kontrol yang dilaporkan **12/8** memakai DB baru `test_pk_f1_audit_control_test`.
- Sandbox pertama memblokir `/dev/fd` pada skrip fixture dan DNS GitHub. Eksekusi lokal/network dilanjutkan dengan izin sandbox yang sesuai; tidak ada bypass ke database produksi.
- Trigger kegagalan probe dilepas dalam `finally`. Salinan `.env` lokal untuk arsip kontrol tidak dimasukkan Git/bukti; tidak mencetak kredensial atau isi pribadi.

## Batas dan handoff

**MariaDB 10.6.28 produksi, MySQL, cPanel/hosting, UAT, mobile/native, perangkat fisik dan Push nyata belum diuji.** Migrasi dan rollback 032 diuji di MariaDB 12.3.2; bukan bukti sudah dijalankan pada versi produksi. Rollback mengandalkan CHECK yang ditegakkan seperti prasyarat SQL-nya, dan ditolak setelah ada identitas guru. Tidak melakukan rollback yang memaksa menghapus identitas/data.

**Fase 1 tidak boleh dianggap siap rilis terpisah:** satu akun kini dapat menjadi pembimbing dan murobi atas santri yang sama, sedangkan pemisahan pengaju/peninjau/pemutus serta verifikasi nilai sendiri baru Fase 2. Kelulusan ini hanya memenuhi lingkup teknis Fase 1, bukan menutup gerbang pemisahan tugas atau gerbang produksi V5/V6/V7.

Hasil audit dan dua koreksi diserahkan melalui commit/push pada branch fase yang sama. Berhenti sesudah itu. Tidak memulai Fase 2, merge, deploy, migrasi produksi, atau rilis aplikasi; keputusan selanjutnya tetap Human Developer.
