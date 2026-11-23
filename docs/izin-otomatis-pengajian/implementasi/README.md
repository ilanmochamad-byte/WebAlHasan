# Status "Izin" otomatis pada absensi pengajian — implementasi

Implementator **Claude Code**, 23 November 2026. **Belum diaudit**: auditor Codex berikutnya. Satu tahap web + REST + aplikasi atas keputusan Human Developer 23 November 2026 (revisi keputusan S, IP-1…IP-6), rencana `../rencana.md` (§3, K-1…K-9, batas §6).

## Baseline dan commit

| Repo | Branch | Baseline | Commit implementasi |
| --- | --- | --- | --- |
| Web `WebAlHasan` | `claude/izin-otomatis-absensi` | `origin/main` `044b0e7` (memuat rencana, PR #131) | `3e6592a` (kode + uji), lalu commit dokumen/bukti ini |
| Mobile `alhasanApps` | `claude/izin-otomatis-absensi` | `origin/main` `d80320b`, `expo.version` **1.10.0** | `02268ad` (1.11.0), lalu commit ringkasan |

Tanpa worktree. Mobile dikerjakan dari klon bersih karena checkout `~/alhasanApps` memuat perubahan `package.json`/`package-lock.json` pekerjaan lain (tidak disentuh). Lima direktori untracked web pekerjaan lain tidak disentuh atau di-commit.

## Perubahan

Web/REST:

- `database/migrations/036_izin_otomatis_pengajian.sql` + rollback: `pertemuan_peserta.izin_pengajuan_id` (BIGINT UNSIGNED NULL, indeks, FK ke `izin_pengajuan`) dan `izin_keterangan` (VARCHAR(160) NULL). Aditif, dijaga `information_schema`, dapat diulang, tanpa UPDATE/DELETE; baris lama tetap NULL.
- `app/Schedule/IzinPeserta.php` (baru) — satu tempat aturan §3.3: izin `status='Disetujui'` **dan** baris `izin_keputusan` berhasil `Disetujui` (Murobi/Admin Pengganti), `tgl_izin ≤ tanggal ≤ tgl_kembali`, ID terbesar; keterangan IP-3 `Izin No. {id} — disetujui murobi|admin pengganti s.d. {d Mmm}`; hanya peserta **belum berabsensi**. Tidak membaca alasan/catatan/keputusan/pengaju dan tidak menulis `absensi_santri`.
- `app/Schedule/PesertaPertemuan.php` — `selaraskan()` dipanggil di dalam transaksi `bekukan()` (kedua jalur) dan `perbarui()`; hasil perbarui menambah `izin_diisi`/`izin_dikosongkan` **hanya bila 036 terpasang** (audit/hasil sebelum migrasi identik baseline).
- `app/Api/TeacherService.php`/`TeacherRepository.php` — kunci aditif `izin: {pengajuan_id, keterangan, sampai}|null` di setiap santri payload pertemuan (buka, rincian, simpan, perbarui); `participant_changes.izin_suggested/izin_cleared`. Validasi `saveAttendance`, idempotensi, dan hak tidak berubah; server tidak memaksa status.
- `app/Report/ReportRepository.php`/`ReportService.php` — rincian laporan pertemuan memuat `izin` yang sama; `app/Keamanan/KeamananApiService.php` — `izin_id` mengutamakan snapshot.
- Web hanya baca: `admin/_pengajian_pertemuan.php`, `admin/laporan_absensi_detail.php` (juga rincian kehadiran Keamanan) menampilkan keterangan dari snapshot (`data-izin-usulan`); label "Sedang izin #" yang dihitung tetap untuk pertemuan tanpa usulan; `admin/admin_pengajian.php` menambah jumlah usulan pada pesan "Perbarui daftar". Tanpa formulir absensi web (keputusan R).

Aplikasi (ringkasan di repo mobile, lokasi sama): draf awal Izin + keterangan, penanda "Izin s.d. …", aturan catatan, "Semua hadir" melewati santri izin, sinkron sesudah perbarui, keterangan di rincian laporan dan Keamanan; 1.10.0 → **1.11.0**; tanpa dependensi/modul native baru.

## Bukti (PHP 8.4, MariaDB 12.3 lokal, fixture sintetis)

Berkas di `bukti/`. Perintah: `perintah-uji.sh` (uji baru), `perintah-regresi.sh` (K-8).

| Uji | Kode kini | Kontrol baseline `044b0e7` |
| --- | --- | --- |
| Statis + lint `tests/izin_otomatis_pengajian_static.php` | **32/0** | 19/13 |
| Integrasi K-1…K-6 + dua urutan kunci perbarui ↔ simpan (proses terpisah) `…_integration.php` | **44/0** | 24/20 (kode lama pada DB **dengan** SQL 036 diterapkan, sehingga yang diuji adalah kode) |
| Migrasi naik–rollback–naik, preservasi isi 123 tabel (SHA-256 kolom lama), K-7 sesudah rollback `…_migration.php` | **20/0** | — |
| HTTP web (sesi + CSRF) dan REST termasuk Keamanan (K-4) `…_http.php` | **18/0** | 10/8 (DB dengan SQL 036) |
| Expo web 1280/768/375 `tests/browser/uji-izin-otomatis-absensi-app.mjs` (bundel tanpa host produksi, blokir host non-127.0.0.1) | **34/0** | — |
| Mobile unit / `tsc` / `expo lint` / `versi:periksa --kerja` | **141/0** / 0 / 0 / LULUS minor | — |
| Android emulator API 36, **APK uji Release R8** dari sumber kerja (bundel tertanam tanpa Metro; cleartext hanya 127.0.0.1; SHA-256 `80dfac0e…6a3b`), alur Maestro `tests/maestro/izin-otomatis/absensi-izin.yaml` | **lulus** (draf Izin + penanda, ubah ke Hadir → catatan kosong, simpan, rincian laporan menampilkan keterangan); DB: Hadir tersimpan, satu `api.attendance.save` | — |
| iOS simulator iPhone 17 Pro iOS 27.2, **build Release arm64 baru** (ad-hoc lokal, entitlement Keychain uji), alur yang sama | **lulus**; DB: Hadir, satu penyimpanan | — |
| Privasi log native (`bukti/native/privasi-log.txt`) | logcat 10.628 baris: 0 penanda alasan, 0 bearer, 0 sandi; `maestro.log` hanya memuat sandi fixture yang diketik alat uji | — |

Bundel native: 0 kemunculan host produksi, 1 URL API lokal. Percobaan pertama build iOS gagal karena pemindaian modul Expo timeout saat build Android berjalan bersamaan (pesan meminta build ulang); build ulang berhasil (`bukti` tidak memuat log build besar). Langkah Maestro "Hadir: 2" pertama kali gagal karena ringkasan sudah tergulir ke atas (tangkapan menunjukkan perubahan benar); alur diperbaiki untuk menggulir dulu, assertion tidak dilonggarkan.

### Regresi K-8 (`bukti/regresi/ringkasan.txt` vs `bukti/regresi-baseline/ringkasan.txt`)

Identik dengan baseline: kelas gabungan integrasi **71/0**, HTTP **32/0**; perbaikan peserta konkurensi **8/0**, probe audit **11/0**; pencarian santri HTTP **26/0**, probe audit **15/0**; Keamanan Fase 3 integrasi **44/0**, HTTP **44/0**, probe **26/0**, Fase 4 HTTP **66/0**; V7 Fase 5 E2E **82/1**, HTTP **40/0**, Fase 4 HTTP **54/0**, Fase 3 **112/0**, Fase 2 **119/0**, Fase 1 **73/2**; V2 Fase 1/2/4/5 **39/0 · 94/0 · 124/0 · 145/0**; V1 Fase 3/4/5 **10/0 · 14/0 · 20/0**; HTTP redesain Tahap 4 **132/0**, Tahap 7 (rapor V4) **122/0**. Kegagalan V7 82/1 dan 73/2 serta probe/migrasi kelas gabungan (prasyarat "salinan sebelum 034", gagal juga di baseline sejak 035) adalah oracle historis yang sudah tercatat.

Berbeda:

- `perbaikan_peserta_absensi_integration` **36/0 → 35/1**: assertion "API participant_changes tepat" membandingkan larik persis, kini memuat kunci aditif `izin_suggested`/`izin_cleared` yang diwajibkan rencana §3.3 butir 5. Oracle historis, tidak dilonggarkan.
- Statis (dijalankan pada dua **klon Git** ber-commit agar suite berbasis `git diff` sebanding; `bukti/statis-klon-*.txt`): `profil_impor_f1_static` **69/2 → 68/3** ("035 migrasi terakhir", kini 036); jumlah lulus `penugasan_keamanan_f1…f4_static` naik +6 karena lint mencakup PHP baru, jumlah gagal sama. Seluruh suite lain identik; total 16 exit 0 (+1 suite baru), 35 exit 1, 2 exit 77.
- Probe pencarian santri di skrip awal salah memberi variabel DB (exit 2 di kedua kode); diulang dengan seed Keamanan dan `DB_NAME` (15/0 di kedua kode) dan skrip sudah diperbaiki.

## Penafsiran (menunggu tinjauan auditor/Human Developer)

1. **P-1** `izin.sampai` dibaca langsung dari `izin_pengajuan.tgl_kembali` (dua kolom sesuai §3.2, tanpa kolom ketiga), sedangkan teks keterangan dibekukan. Tanggal izin tidak berubah sesudah keputusan pada alur yang ada; bila kelak berubah, penanda dan teks bisa berbeda.
2. **P-2** Izin dihitung bila `status='Disetujui'` **dan** baris `izin_keputusan.hasil='Disetujui'`; keputusan dikoreksi menjadi Ditolak mengubah status, sehingga usulan hilang pada perbarui berikutnya.
3. **P-3** Kunci `izin` selalu ada (null) pada payload REST/laporan, juga sebelum 036 (aditif); kunci `izin_*` hasil perbarui dan audit hanya sesudah 036 agar audit/hasil identik baseline (K-7).
4. **P-4** "Semua hadir" melewati santri ber-usulan izin (rencana §3.5), tombol "Semua hadir (N)" dengan N = peserta tanpa izin.
5. **P-5** "Semua hadir" **tidak** dihitung sebagai ubahan per santri: sesudah "Perbarui daftar", santri yang hanya terkena tindakan massal tetap mengikuti izin terbaru (IP-5). Hanya ubahan status/catatan per santri yang dipertahankan.
6. **P-6** Kembali ke "Izin" dengan catatan kosong mengisi lagi keterangan izin; mengubah dari Izin mengosongkan catatan hanya bila masih sama persis dengan keterangan.
7. **P-7** Penanda tetap tampil walau guru mengubah status, juga pada pertemuan Selesai (usulan beku tidak dihapus saat absensi disimpan).
8. **P-8** Rincian kehadiran Keamanan di aplikasi memakai label "Izin s.d. …" + keterangan bila ada; "Sedang izin #" tetap untuk pertemuan tanpa usulan / server lama.
9. **P-9** Rollback 036 tidak ditolak walau usulan berisi (rencana §3.2); jumlah usulan pada pertemuan Dibuka dilaporkan lewat kueri pemeriksaan di bawah, karena Migrator tidak menampilkan hasil SELECT.

## Temuan

- **T-1** Fixture: klien `mysql` CLI tanpa `--default-character-set=utf8mb4` menampilkan em dash keterangan sebagai `?`; pembantu uji browser kini memakai utf8mb4. Bukan cacat aplikasi (data tersimpan benar).
- **T-2** Build Android dan iOS bersamaan dapat membuat pemindaian modul Expo iOS timeout (5 s); build iOS diulang.

## Batas

MariaDB 10.6.28 produksi, MySQL/cPanel, perangkat fisik, build distribusi EAS, iOS Debug, Push nyata, UAT, dan PDF cetak fisik belum diuji. Native diuji dengan alur K-9 tunggal per platform (bukan seluruh suite native lama). Aplikasi ≤ 1.10.0 mengabaikan kunci `izin` (tetap draf Hadir); izin yang disetujui sesudah pertemuan dibuka baru terisi lewat "Perbarui daftar"; per hari penuh (IP-2) dapat keliru pada hari berangkat/kembali dan dikoreksi guru. Gerbang produksi workstream lain tidak berubah.

## Rilis dan pemulihan (dilaksanakan Human Developer)

1. Backup DB terverifikasi. Deploy kode web, lalu `php bin/migrate.php up` (036 sesudah 035).
2. Verifikasi: `SHOW COLUMNS FROM pertemuan_peserta LIKE 'izin_%'` → dua kolom; buka satu pertemuan uji pada tanggal berizin dan periksa keterangan di halaman pertemuan web.
3. Rilis aplikasi **1.11.0** (build native baru). Sampaikan kepada guru bahwa manfaat penuh memerlukan pembaruan aplikasi.
4. Pemulihan: sebelum rollback laporkan usulan pada pertemuan Dibuka —
   `SELECT COUNT(*) FROM pertemuan_peserta pp JOIN pertemuan_pengajian p ON p.id = pp.pertemuan_id WHERE p.status = 'Dibuka' AND pp.izin_pengajuan_id IS NOT NULL;`
   lalu `php bin/migrate.php rollback` (hanya melepas dua kolom; absensi tersimpan tidak tersentuh) dan kembalikan kode. Kode baru juga aman tanpa 036 (perilaku baseline).

Tidak merge, deploy, migrasi produksi, atau rilis aplikasi. Berhenti untuk audit Codex.
