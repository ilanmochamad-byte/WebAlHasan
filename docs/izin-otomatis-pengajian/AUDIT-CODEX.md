# Audit independen Codex — status “Izin” otomatis pada absensi pengajian

Tanggal audit: 23 November 2026. Satu tahap, revisi keputusan S/IP-1–IP-6. Sumber persyaratan: `rencana.md` §3, K-1–K-9, §6; handoff implementator kedua repo. Audit ini tidak menutup gerbang produksi workstream lain.

## Putusan

**BELUM LULUS seluruh kriteria secara literal: K-8 belum terpenuhi pada oracle historis. K-1–K-7 dan K-9 lulus teknis lokal.** Tidak ditemukan regresi fungsional atau cacat runtime yang memerlukan koreksi. Kegagalan baru di K-8 berasal dari bentuk respons aditif yang diwajibkan rencana dan batas cakupan/migrasi pada oracle fase lama; auditor tidak mengubahnya atau menyatakannya hijau.

| Kriteria | Putusan | Bukti utama |
|---|---|---|
| K-1 | Lulus lokal | Web/REST, kedua asal × kedua kapasitas, rentang satu hari dan kedua batas inklusif, ID terbesar pada tumpang tindih; format IP-3 dan 12 bulan. |
| K-2 | Lulus lokal | Setiap status negatif diperiksa sendiri; status Disetujui tanpa keputusan/keputusan Ditolak/di luar rentang tidak mengusulkan Izin. Enum kapasitas DB hanya Murobi/Admin Pengganti. |
| K-3 | Lulus lokal | Perbarui peserta belum/sudah berabsensi; pembatalan, koreksi resmi dua arah, penggantian ke izin sah berikutnya; Selesai tetap; dua urutan kunci pertemuan; kegagalan audit menggulung balik usulan. |
| K-4 | Lulus lokal | HTTP web/REST/laporan/Keamanan, penanda privat pada alasan/catatan/keputusan; pembacaan helper hanya ID, tanggal, kapasitas; audit perbarui hanya ID/jumlah. |
| K-5 | Lulus lokal | UI asli 34/0, probe UI auditor 14/0, unit 141/0, TypeScript/lint exit 0; draf, catatan, penanda, “Semua hadir”, sinkronisasi, ketukan ganda serta retry setelah respons hilang. |
| K-6 | Lulus lokal | Status tersimpan Izin/Hadir dihitung oleh `VerifikasiService::attendance` dan laporan; usulan tidak dihitung sebagai absensi. Rumus V4 tidak berubah. |
| K-7 | Lulus lokal | Migrasi 20/0; 123 tabel lama dipreservasi. Kontrol sesudah rollback: buka/perbarui/simpan/laporan/audit cocok dengan kode baseline, hanya kunci aditif null disisihkan. Klien tanpa `izin` tetap berfungsi. |
| K-8 | **Belum lulus literal** | Perbandingan suite asli final dengan kontrol baseline dijalankan auditor; rincian di bawah. |
| K-9 | Lulus lokal | Android Release R8 dan iOS Release arm64 baru: draf Izin, ubah Hadir, simpan, laporan; masing-masing satu penyimpanan. |

## Baseline, cakupan, dan preservasi

- Web: baseline **`044b0e7787a3695ea965e38d8734851d42b849dd`**, sama dengan `origin/main` ketika audit/fetch. Seluruh commit tahap: **`3e6592a`** (implementasi/tes) dan **`2c58a50`** (handoff/bukti). Keduanya diperiksa; perubahan aplikasi hanya commit pertama.
- Mobile: baseline **`d80320b7f6f6760f5a98d9f591ecfd94da1f6e91`**, sama dengan remote main; seluruh commit **`02268ad`** dan **`7b85efc`**. `expo.version` tepat satu minor **1.10.0 → 1.11.0**, diverifikasi dari Git dan skrip versi setelah ref main disegarkan. `package.json`/lock serta dependensi/native module sama dengan baseline.
- Audit mobile memakai klon bersih `/private/tmp/iop-codex-audit/mobile`, tanpa worktree/subagen. Checkout `~/alhasanApps` tetap pada pekerjaan sebelumnya. Dua edit paket di sana dan lima direktori untracked web tidak diubah/dimasukkan commit.
- Preservasi akhir: **100/100 berkas kontrol identik**, termasuk dua paket mobile dan isi lima direktori web pekerjaan lain; `bukti-audit/preservasi.json`.
- Tidak ditemukan cacat aplikasi yang memerlukan koreksi runtime. Auditor menambah probe independen, bukti, laporan, dan catatan audit; oracle historis tidak diedit. Temuan alat/fixture dijelaskan terpisah di bawah.

## Uji independen dan bukti

Lingkungan: PHP 8.4, MariaDB **12.3 lokal**, fixture sintetis `test_*_test`, API hanya loopback. Tidak memakai/memigrasikan DB produksi. Bukti ada di `bukti-audit/`.

| Uji | Hasil final | Kontrol auditor sendiri |
|---|---|---|
| Statis/lint fitur asli | 32/0 | **19/13**, pohon kode/skema berkas baseline asli dengan berkas uji baru yang sama |
| Integrasi asli, termasuk konkurensi proses terpisah | **44/0** | **24/20**, kode `044b0e7` pada salinan DB berskema 036 |
| HTTP asli (sesi/CSRF, REST, Keamanan) | **18/0** | **10/8**, kode `044b0e7` pada salinan DB berskema 036 |
| Migrasi naik–rollback–naik | **20/0** | Preservasi isi kolom lama pada **123 tabel**, FK/indeks, tidak mengisi ulang baris lama, absensi tersimpan tetap |
| Probe auditor `tests/izin_otomatis_pengajian_audit.php` | **46/0** | Matriks status negatif terpisah, kedua asal/kapasitas, koreksi melalui layanan resmi, hak, replay/konflik idempotensi, alasan koreksi, audit gagal/rollback, 12 bulan |
| Kontrol rollback `…_rollback_audit.php` | **identik** | Dua DB salinan identik, kode baru tanpa 036 vs baseline; 411 baris JSON normalisasi, SHA-256 sama **`2af4763172981f38d6c32ab510e6c7d265694e3948dfbfed273063836c873fed`** |
| Expo web asli, 1280/768/375 | **34/0** | Suite asli tanpa perubahan, Metro, API fixture nyata; blokir semua host non-loopback |
| Probe UI auditor `tests/browser/uji-izin-otomatis-audit.mjs` | **14/0** | Pembatalan saat draf terbuka, isian manual, usulan baru, “Semua hadir”, respons 503 sesudah server commit; dua PUT dengan kunci sama menghasilkan **satu** audit simpan |
| Mobile unit / TypeScript / Expo lint | **141/0 / exit 0 / exit 0** | Lock baseline; versi tetap 1.11.0 |

Kontrol rollback menyisihkan **hanya** `izin`/kolom aditif yang harus null; bila berisi, probe gagal. Kunci lama, nilai/status, respons perbarui, dan audit lama dibandingkan utuh. Waktu DB dikunci sama untuk kedua proses. Bukan hanya pemeriksaan bentuk payload.

Privasi diperiksa pada konteks absensi/buka/perbarui/laporan/Keamanan. Audit bisnis perizinan sendiri memang menyimpan alasan keputusan sesuai modul V2/V7 dan bukan ruang lingkup pelarangan baru ini. Helper usulan tidak membaca kolom alasan/catatan/nama pengaju. Isi catatan absensi yang dibuat guru tetap bagian dari kontrak lama.

Hak dan validasi simpan tidak berubah. Probe membuktikan guru lain 403, status Hadir berusulan tersimpan Hadir, Izin tanpa usulan boleh disimpan, replay mengembalikan respons yang sama, kunci sama isi berbeda 409, serta koreksi Selesai tanpa alasan 422. Diff mobile tidak mengubah klien HTTP, penyimpanan token, penjaga layar privat, atau mekanisme kunci/penguncian kiriman; Keamanan tetap memakai navigator privat lama.

## Regresi K-8 dan oracle historis

**Temuan penerimaan A-1 — K-8 belum hijau secara literal (tanpa pelonggaran oracle):**

1. `perbaikan_peserta_absensi_integration`: **36/0 baseline → 35/1 final**. Assertion “API participant_changes tepat” membandingkan array persis tiga kunci. Respons baru mempertahankan tiga kunci itu dan menambahkan `izin_suggested`/`izin_cleared` sesuai §3.3/§3.4. Menghapus kunci baru untuk meluluskan oracle akan melanggar rencana. Probe baru memverifikasi kunci lama/nilai lama tetap.
2. `profil_impor_f1_static`: pada klon bersih **71/0 → 69/2**. Assertion “035 migrasi terakhir” ditolak oleh 036 yang diwajibkan; assertion cakupan berkas khusus workstream Profil menolak berkas fitur absensi. Seluruh kegagalan itu tetap ada pada oracle asli. Ini berbeda dari hitungan handoff karena kontrol audit memakai klon bersih/ref benar dan tidak membawa artefak untracked. Matriks `statis-perbandingan.json` mencatat angka aktual.

Tidak ada perubahan runtime yang bisa sekaligus menghapus perbedaan persis tersebut dan mempertahankan kontrak aditif/migrasi yang diwajibkan. Audit berhenti dengan status K-8 di atas, tanpa mengubah oracle atau menganggap pengecualian telah diterima Human Developer.

| Suite | Final lulus/gagal | Baseline lulus/gagal |
|---|---|---|
| jkg-int | 71/0 | 71/0 |
| jkg-http | 32/0 | 32/0 |
| jkg-audit | 0/1 | 0/1 |
| jkg-mig | 0/3 | 0/3 |
| ppa-int | 35/1 | 36/0 |
| ppa-conc | 8/0 | 8/0 |
| ppa-audit | 11/0 | 11/0 |
| pcs-http | 26/0 | 26/0 |
| pcs-audit | 15/0 | 15/0 |
| pk3-int | 44/0 | 44/0 |
| pk3-http | 44/0 | 44/0 |
| pk3-audit | 26/0 | 26/0 |
| pk4-http | 66/0 | 66/0 |
| v7f5-e2e | 82/1 | 82/1 |
| v7f5-http | 40/0 | 40/0 |
| v7f4-http | 54/0 | 54/0 |
| v7f3-int | 112/0 | 112/0 |
| v7f2-int | 119/0 | 119/0 |
| v7f1-int | 73/2 | 73/2 |
| v2f1-int | 39/0 | 39/0 |
| v2f2-int | 94/0 | 94/0 |
| v2f4-int | 124/0 | 124/0 |
| v2f5-int | 140/5 | 140/5 |
| v1f3-int | 10/0 | 10/0 |
| v1f4-int | 14/0 | 14/0 |
| v1f5-int | 20/0 | 20/0 |
| HTTP redesain Tahap 4 | 132/0 | 132/0 |
| HTTP redesain Tahap 7 (rapor V4) | 122/0 | 122/0 |
| V2 Fase 5, kontrol rantai fixture asli | 143/0 | 143/0 |

Kegagalan yang **identik** baseline: probe/migrasi kelas gabungan mensyaratkan salinan sebelum 034 (kedua sumber sudah 035/036), V7 Fase 5 satu assertion dan V7 Fase 1 dua assertion. V2 Fase 5 pada snapshot gabungan **140/5** di kedua kode: lima assertion receipt KL-9 karena antrean sintetis lama menghabiskan putaran dispatcher 10 baris. Kontrol tambahan dengan builder asli `v7_phase1_db.sh`, menjalankan Fase 4 sebelum Fase 5 sebagaimana runner awal, menghasilkan **143/0 pada kedua kode**, tanpa mengubah suite. Ini uji provider Push tiruan, bukan Push nyata.

Statis: **52 suite baseline dan 53 suite final**. Selain suite fitur baru 32/0 dan dua kegagalan Profil di atas, jumlah gagal sama; empat suite penugasan Keamanan menambah enam lint lulus karena berkas PHP baru. Keluaran lengkap disimpan, termasuk kegagalan historis lama.


Runner awal mengulang rantai setup fixture untuk tiap suite. Setelah fixture bersih `test_car_ppaa_test` siap, runner dijeda/dihentikan dan snapshot diambil **sebelum** probe peserta dijalankan (0 usulan). Sisa verifikasi dipasangkan pada salinan snapshot bersih per suite: final berskema 036, baseline berskema 035 (rollback 036 pada salinan, tidak mengubah tabel lama). Setiap suite memakai DB sendiri; tidak ada perubahan assertion. Runner `bukti-audit/regresi-pasangan.py`, hasil `regresi-pasangan/hasil.json`. Snapshot SQL sintetis disimpan lokal di `/private/tmp/iop-codex-audit`, tidak dimasukkan Git.

Statis historis yang bergantung `git diff` dibandingkan pada **klon Git bersih** commit `3e6592a` dan `044b0e7` dengan `origin/main` yang benar. Keluaran awal dari working tree yang sedang menghasilkan bukti tidak dipakai sebagai pembanding final karena daftar untracked bukti ikut terbaca oracle cakupan berkas.

## Native K-9 dan privasi log

- **Android:** emulator `Uji_API36`, Android 16/API 36 arm64. APK **Release R8** dibangun baru oleh auditor, bundel Hermes tertanam, tanpa Metro. Versi 1.11.0. Hanya build uji lokal mengizinkan HTTP `127.0.0.1` lewat konfigurasi jaringan khusus; tidak ada perubahan transport pada sumber ter-commit. SHA-256 APK **`c9e63e41a03ec89567e2973f6bbc5e6db38a0743c1cb866d978fd3015d56c0de`**; metadata di `native/android-artifact.json`.
- Alur Maestro asli **lulus**: draf Izin + keterangan, penanda, ubah ke Hadir/catatan kosong, simpan, dan rincian laporan. Pertemuan fixture **724**: dua Hadir tersimpan, satu `api.attendance.save`. Tangkapan diperiksa auditor.
- **iOS:** simulator iPhone 17 Pro **iOS 27.2**, build **Release arm64 baru** dengan bundel Hermes tertanam, signing ad-hoc lokal. Build awal memiliki dictionary entitlement simulator kosong; penambahan entitlement Keychain buatan dan percobaan sertifikat lokal justru ditolak saat launch. Kontrol dengan signing ad-hoc/dictionary kosong dapat diluncurkan dan **login berhasil menyimpan sesi**. Alur fitur K-9 selanjutnya **lulus**, pertemuan **725**: dua Hadir, satu `api.attendance.save`, laporan memuat keterangan. Assertion fitur asli dipertahankan; setup login alat uji ditangani terpisah sebagaimana diagnostik di bawah. Metadata di `native/ios-artifact.json`; bukan arsip/distribusi iOS.
- Pemindaian log Android: **10.082 baris**, **0** penanda privat yang diuji, **0** bearer mentah, **0** sandi fixture. Log proses iOS pada 8 menit alur yang berhasil (termasuk instrumentasi XCTest): **18.585 baris, 0 penanda privat, 0 bearer, 0 sandi fixture**. Log debug alat uji tetap dapat berisi input fixture; tidak disamakan dengan log aplikasi produksi.

## Penilaian penafsiran implementator

P-1–P-9 diterima dalam batas rancangan. `izin.sampai` dibaca dari tanggal kembali sumber sedangkan keterangan dibekukan; jalur resmi yang ada tidak mengubah tanggal izin sesudah keputusan. Perubahan tanggal lewat SQL langsung/fitur masa depan bisa membuat keduanya berbeda dan memerlukan peninjauan tersendiri. Koreksi keputusan yang diuji adalah jalur resmi dua arah; status efektif dan baris keputusan diperbarui bersama. P-3 tetap aditif (`izin:null` tanpa 036). P-4/P-5 tindakan “Semua hadir” melewati usulan; santri yang hanya mengikuti nilai awal tetap mengikuti izin terbaru saat perbarui. P-6–P-8 mempertahankan keterangan/penanda serta status tersimpan. P-9 jumlah usulan Dibuka harus dilaporkan melalui kueri pra-rollback karena CLI Migrator tidak mencetak hasil SELECT.

Catatan nonblokir tampilan: teks penjelas “sudah terisi Izin” tetap tampil setelah guru mengubah santri ke Hadir; pilihan radio, catatan, angka, dan hasil simpan benar. Ini tidak mengunci atau mengganti pilihan guru.

## Diagnostik alat dan batas bukti

1. Sandbox awal memblokir `/dev/fd`, socket DB, ADB, dan CoreSimulator. Uji diulang dengan akses lokal yang sesuai; kegagalan itu bukan hasil aplikasi.
2. Klon lokal awal mewarisi ref `origin/main` lama; pemeriksa versi sempat membandingkan 1.0.0 → 1.11.0. Fetch remote dan pembacaan langsung baseline membuktikan kenaikan minor yang benar. Statis berbasis Git diulang dengan ref benar.
3. Penyalinan dependensi pertama memilih symlink node_modules milik Playwright; TypeScript/Expo tidak ditemukan. Diperbaiki dengan direktori dependensi mobile nyata yang lock-nya sama; unit/tsc/lint diulang. Prebuild terlalu awal sempat gagal mencari `@expo/env` ketika salinan belum lengkap.
4. Fixture probe auditor awal salah nilai waktu sholat/argumen metadata, lalu tidak memenuhi constraint asal Wali dan bertabrakan jadwal saat diulang. Fixture dibetulkan, SQL dibuat fail-fast, dan assertion tetap. Ekspektasi awal koreksi mengira usulan kosong; karena ada izin lain yang masih berlaku, ekspektasi yang tepat adalah **ID sah terbesar berikutnya**. Probe final memeriksa ID itu secara tepat.
5. Seed UI awal mencoba menambah penugasan Keamanan yang sudah ada pada salinan HTTP. Helper lokal memakai penugasan aktif yang sama; suite UI asli tidak diubah. Dua percobaan memakai penyaji ekspor statis lokal tertahan timeout Keamanan/login; diagnosis API 200 dan layar Keamanan benar tanpa page error. Pengulangan menggunakan Metro sebagaimana petunjuk suite lulus **34/0**; kegagalan percobaan awal tidak dihitung lulus.
6. Login Maestro iOS pertama mengetik sandi fixture ke username karena fokus belum berpindah; tidak ada request login sah. Helper audit menutup keyboard dan menunggu animasi. Percobaan signing tambahan lolos `codesign --verify` namun launch ditolak (NSPOSIX 3/launchd 163), termasuk sertifikat Development lokal; dikembalikan ke signing simulator tanpa entitlement tambahan. Login kemudian berhasil saat keyboard ditutup, sehingga ketukan “Masuk” tambahan justru gagal karena Beranda/dialog “Save Password?” sudah tampil. Alur fitur dilanjutkan dari sesi itu setelah menutup dialog, semua assertion K-9 asli tetap. Tidak ada koreksi sumber aplikasi/perlindungan layar. Berkas helper lanjutan dan log percobaan disimpan sebagai bukti, bukan hasil lulus untuk percobaan yang gagal.
7. Build Android pertama ditolak Android Lint karena konfigurasi jaringan **khusus uji** tidak menyebut `includeSubdomains`. Atribut diperbaiki pada direktori native temp, lint tetap aktif, rebuild Release berhasil. Konfigurasi sumber aplikasi tidak diubah.

**Belum diuji:** MariaDB 10.6/cPanel/MySQL produksi, migrasi/deploy produksi, perangkat fisik, build/signing/distribusi EAS/Play/App Store, Push nyata/ketukan/cold start Push, iOS Debug, UAT dan cetak fisik. Native K-9 memakai alur tunggal per platform; bukan pengulangan seluruh suite native historis. Aplikasi ≤1.10.0 tetap draf Hadir dan membutuhkan pembaruan untuk manfaat penuh; izin baru sesudah pembukaan memerlukan “Perbarui daftar”; per hari penuh dapat dikoreksi guru. Temuan Optimasi Android serta gerbang V5/V6/V7 lama tidak ditutup oleh audit ini.

Commit/push hanya branch `claude/izin-otomatis-absensi` kedua repo. Tidak merge, deploy, migrasi produksi, rilis/OTA, atau pekerjaan berikutnya.
