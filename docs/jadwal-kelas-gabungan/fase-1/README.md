# Perbaikan jadwal pengajian kelas gabungan — Fase 1

Status: **diimplementasikan Claude Code, menunggu audit independen Codex.** Bukan izin merge, deploy, atau migrasi produksi.

## Keputusan dan peran

- **Keputusan produk Human Developer (31 Oktober 2026):** satu jadwal dapat mencakup satu atau beberapa kelas yang benar-benar belajar bersama dengan satu guru, pelajaran, waktu, dan tempat (contoh: 1 IBTIDA PA + 1 IBTIDA PI). Satu jadwal gabungan menghasilkan tepat satu pertemuan per tanggal, satu absensi guru, dan paling banyak satu absensi per santri. Pertemuan tetap dibuka manual; tidak ada pembukaan otomatis. Jadwal lama tidak digabung otomatis.
- **Peran:** implementator Claude Code, auditor Codex. Fase 1 saja; aplikasi mobile, Fase 2, merge, dan deploy di luar cakupan.
- **Branch:** `claude/jadwal-kelas-gabungan-fase-1` dari `origin/main` `22a3645` (merge PR #107, isi sama dengan audit penugasan Fase 5 `c65ec86`; `git diff c65ec86 origin/main` kosong). Tanpa worktree.
- Direktori belum terlacak milik pekerjaan lain (`docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/`, `tests/browser/tangkapan-penugasan-keamanan-f1/`) tidak disentuh dan tidak di-commit.

## Rancangan

### Basis data — migrasi 034 (aditif)

| Tabel | Isi | Sifat |
| --- | --- | --- |
| `jadwal_kelas` | seluruh kelas terpilih sebuah jadwal, termasuk kelas utama (`is_utama = 1`) | sumber resmi peserta dan saringan untuk pertemuan **berikutnya** |
| `pertemuan_kelas` | kelas yang dibekukan saat pertemuan dibuka (`sumber` = `Pembukaan` / `Migrasi 034`) | historis; perubahan kelas jadwal sesudahnya tidak mengubahnya |

- `jadwal_ngaji.id_kelas` tetap ada sebagai **kelas utama** (kompatibilitas). Kode selalu menghitung kelas utama dari kolom ini, sehingga jadwal tanpa baris `jadwal_kelas` (mis. dibuat di luar layanan) tetap terbaca satu kelas.
- Kelas asal tiap santri disimpan di kolom lama `pertemuan_peserta.kelas_id_snapshot` (dahulu selalu sama dengan kelas jadwal). `UNIQUE (pertemuan_id, santri_id)` yang sudah ada mencegah peserta ganda.
- **Isian data lama:** tiap jadwal → satu baris kelas utama dari `id_kelas`; tiap pertemuan yang sudah dibuka → kelas snapshot pesertanya (kelas jadwal **saat pertemuan dibuka**, bukan kelas sekarang); pertemuan dibuka tanpa peserta → `id_kelas`; draf tidak diisi (kelasnya dibekukan saat dibuka). Seluruh isian dijaga `NOT EXISTS` sehingga berkas aman dijalankan ulang.
- Tidak ada `ALTER`, `UPDATE`, `DELETE`, atau `TRUNCATE` terhadap tabel lama; ID jadwal, pertemuan, peserta, santri, dan absensi tidak berubah.
- **Rollback berpenjaga** (`database/rollbacks/034_*.sql`): ditolak bila ada kelas gabungan (`jadwal_kelas.is_utama = 0`) atau pertemuan berkelas lebih dari satu. Selama masih satu kelas, kedua tabel hanya cermin data lama dan dapat dilepas.

### Kode

- `App\Schedule\KelasJadwal` — satu tempat untuk fragmen SQL "kelas jadwal", "kelas pertemuan", dan predikat saringan, serta pembacaan daftar kelas per jadwal/pertemuan. Sadar skema: sebelum 034 atau sesudah rollback, kembali ke perilaku satu kelas.
- `App\Schedule\PesertaPertemuan::bekukan()` — satu implementasi pembukaan untuk web (`ScheduleService::open`) dan REST (`TeacherService::openMeeting`): bekukan kelas jadwal ke `pertemuan_kelas`, lalu peserta dari keanggotaan **aktif** semester jadwal seluruh kelas itu, satu baris per santri dengan kelas asal.
- `ScheduleService::save()` kini satu transaksi: baris jadwal dikunci `FOR UPDATE` (baris yang sama dikunci pembukaan pertemuan), jadwal disimpan, lalu `jadwal_kelas` disamakan. Perubahan kelas dan pembukaan pertemuan berurutan; pertemuan yang terbuka memakai salah satu keadaan utuh.
- Benturan: guru tetap **ditolak**; kelas dan tempat tetap **peringatan** (aturan V1). Kelas dibandingkan sebagai himpunan (utama + gabungan) terhadap himpunan kelas jadwal lain; kelas-kelas di dalam satu jadwal gabungan bukan benturan.
- Formulir admin: "Kelas utama" (`id_kelas`, wajib) + "Kelas lain yang belajar bersama" (`kelas_gabungan[]`, kotak centang, paling banyak 10 kelas termasuk utama). Isian gagal mempertahankan pilihan. Daftar, kisi mingguan, rincian, pertemuan, Beranda, dan jadwal publik menampilkan seluruh kelas.
- REST (aditif): `classes` pada jadwal, `task.classes` pada pertemuan, `class_names` + `classes` pada daftar pertemuan, `class` (kelas asal) per santri. Field lama `class`/`class_name` tetap kelas utama.
- Laporan/ekspor: baris santri memakai kelas asal; saringan kelas pada baris santri = `kelas_id_snapshot`, pada baris guru = pertemuan yang mencakup kelas itu. Absensi guru tetap satu baris per pertemuan. Ringkasan per jadwal tidak lagi terpecah per kelas.
- Rekap kehadiran rapor (V4) sudah dihitung per santri dari `pertemuan_peserta` dan per kelas dari `kelas_id_snapshot`; tidak diubah, kini benar untuk santri kelas gabungan karena kelas asal disimpan. Diuji.

## Penafsiran (mohon ditinjau auditor)

- **JK-1 Kelas utama eksplisit.** Formulir memakai pemilih kelas utama + kotak centang kelas lain, bukan satu daftar pilih-banyak, agar `id_kelas` tidak perlu ditebak. Kelas utama yang ikut dicentang diabaikan (tidak ganda).
- **JK-2 Benturan kelas tetap peringatan.** Prompt meminta memeriksa guru, semua kelas, waktu, dan tempat; aturan V1 (guru ditolak, kelas/tempat diperingatkan) dipertahankan, diperluas ke himpunan kelas.
- **JK-3 Peserta aktif** = keanggotaan `plotting_kelas.status = 'Aktif'` pada semester jadwal, sama dengan definisi lama. Basis data sudah membatasi satu keanggotaan aktif per santri per semester; bila tetap ganda, dipilih keanggotaan ber-ID terkecil.
- **JK-4 Kelas arsip.** Formulir hanya menawarkan kelas aktif; kelas gabungan yang kemudian diarsipkan dilepas saat jadwal disimpan ulang (ada pemberitahuan). Pertemuan lama tetap menyimpannya.
- **JK-5 `class` pada pertemuan REST** tetap kelas utama jadwal **saat ini** (perilaku lama); kelas historis pertemuan ada di `task.classes`.
- **JK-6 Saringan kelas laporan untuk baris santri** kini memakai kelas asal historis (`kelas_id_snapshot`), bukan kelas jadwal saat ini. Untuk data lama hasilnya sama kecuali jadwal pernah diganti kelasnya; hasil baru lebih tepat.
- **JK-7 Ekspor CSV** tidak menambah kolom: kolom Kelas berisi kelas asal (santri) atau seluruh kelas pertemuan (guru).
- **JK-8 Ringkasan per jadwal** dikelompokkan per jadwal; kolom kelas memuat kelas pertemuan, dipisah ` | ` bila berbeda antar-pertemuan.
- **JK-9 Tanda tangan lama dipertahankan:** `snapshotParticipants($meeting, $class, $year)` (dipakai fixture uji lama) masih berlaku dan membekukan satu kelas itu.

## Bukti

Lihat [Hasil uji dan regresi](#hasil-uji-dan-regresi). Lingkungan: PHP 8.4.14, MariaDB 12.3.2 lokal, DB `test_*_test` berfixture sintetis (tanpa data produksi).

- `perintah-uji.sh` → `bukti/uji/`: statis, integrasi + konkurensi, migrasi/rollback, HTTP.
- `perintah-regresi.sh` → `bukti/regresi/` (kode kini) dan `bukti/kontrol-baseline/regresi/` (klon `22a3645`, suite dan fixture sama).
- `bukti/sha256.txt` — sidik berkas yang dibuat/diubah.

## Hasil uji dan regresi

### Uji Fase 1 (`bukti/uji/`)

| Suite | Hasil | Cakupan |
| --- | --- | --- |
| Statis `tests/jadwal_kelas_gabungan_f1_static.php` | **46/0** | lint, migrasi aditif, rollback berpenjaga, saringan, satu implementasi pembukaan, kontrak API, formulir, batas berkas |
| Integrasi `tests/jadwal_kelas_gabungan_f1_integration.php` | **71/0** | jadwal satu/dua kelas, validasi, benturan himpunan kelas, saringan, satu pertemuan untuk dua kelas, kelas asal, pembukaan ulang web/API ditolak, `classes` API, absensi tanpa duplikasi, perubahan kelas sesudah dibuka, laporan per kelas/gabungan/ekspor, rekap rapor V4, konkurensi, audit, preservasi |
| Migrasi `tests/jadwal_kelas_gabungan_f1_migration.php` | **31/0** | sebelum 034 (satu kelas, gabungan ditolak), naik + isian historis, jalankan ulang, FK/UNIQUE/CHECK, rollback satu kelas + naik lagi, rollback ditolak sesudah gabungan |
| HTTP `tests/jadwal_kelas_gabungan_f1_http.php` | **32/0** | formulir admin + CSRF, tampilan jadwal/pertemuan, isian gagal, hak guru, REST `/schedules`, `/meetings`, `POST …/meetings` 201/409, `PUT …/attendance`, laporan web per kelas, rincian, CSV, jadwal publik, log server bersih |

Konkurensi (I-12/I-13, proses PHP terpisah dengan titik mulai sama): dua putaran **tiga pembukaan bersamaan** (web + API + web) → tepat satu berhasil, dua ditolak dengan pesan layanan, satu pertemuan berpeserta 5 tanpa ganda; empat putaran **ubah kelas ∥ buka** → kedua urutan teramati (simpan-dulu dan buka-dulu), kelas beku selalu sama dengan kelas peserta dan salah satu keadaan utuh.

**Kontrol kode lama:** uji integrasi dan HTTP baru pada klon `22a3645` gagal (`Class "App\Schedule\KelasJadwal" not found`, HTTP 1/1), statis 22/24 (`bukti/kontrol-baseline/regresi/jkg-*.txt`).

### Regresi pengajian/absensi V1–V4 dan jalur yang disentuh (`bukti/regresi/` vs `bukti/kontrol-baseline/regresi/`)

`perintah-regresi.sh` dijalankan berurutan pada kode kini lalu pada klon `22a3645` (suite, urutan, fixture sama). **Seluruh suite non-statis identik exit dan hitungannya dengan baseline:**

| Suite | Kini = baseline |
| --- | --- |
| V1 jadwal & pertemuan (Fase 3) / API absensi (Fase 4) / laporan, ekspor, cetak, EXPLAIN (Fase 5) | 10/0 · 14/0 · 20/0 |
| Perapihan (integrasi) | 52/0 |
| Kehadiran Keamanan: penugasan Fase 3 integrasi / HTTP / probe audit Codex; Fase 4 REST | 44/0 · 44/0 · 26/0 · 66/0 |
| HTTP redesain Tahap 2 (Beranda/navigasi) / 4 (kehadiran, cetak) / 6 (data pesantren) / 7 (rapor V4) | 185/6 · 132/0 · 125/7 · 122/0 |

Tahap 2 185/6 dan Tahap 6 125/7 adalah oracle historis yang sudah tercatat (peta menu BB–BE; rollback 023 dianggap terakhir) dan identik dengan baseline.

### Statis (51 suite kini)

Kini **17 exit 0, 32 exit 1, 2 exit 77**. Suite statis berbeda hitungan dengan klon karena klon bukan repo Git (pemeriksaan `git diff` tidak berjalan di sana) dan tidak memiliki repo mobile tetangga. Assertion yang gagal **hanya pada kode kini** seluruhnya oracle historis berikut; tidak ada yang dilonggarkan:

1. **Migrasi 034 sengaja ditambahkan** — "033/026 adalah migrasi terakhir", "database/ tidak berubah", manifest 33 migrasi (penugasan Fase 5, Tahap 4, V6 Fase 1–5, V7 Fase 4–5, tagihan satu santri).
2. **Batas berkas fase lama** — daftar "hanya berkas fase X yang berubah sejak baseline" pada penugasan Fase 1–5, V6, V7, tagihan satu santri kini memuat berkas pengajian/laporan/API guru yang memang diubah Fase 1 ini.
3. **`phase3_static` "Pembukaan pertemuan membekukan peserta dari keanggotaan kelas aktif"** — oracle mencari teks `pk.status = 'Aktif'` di `ScheduleRepository.php`; SQL pembukaan dipindah ke `App\Schedule\PesertaPertemuan` (predikat sama, dipakai web dan API). Perilaku tetap dibuktikan V1 Fase 3 10/0 dan integrasi I-6.
4. **Tahap 7 "Penugasan: hanya penambahan baca"** — gagal juga pada `origin/main` sendiri (6 baris terhapus di `app/Penugasan` sejak `86721a2` oleh pekerjaan penugasan lintas guru); Fase 1 ini tidak menyentuh `app/Penugasan` (`git diff 22a3645 -- app/Penugasan` kosong).
5. **V2 Fase 4/5 (deep link, versi Expo/React Native)** — membaca repo mobile tetangga yang berisi perubahan paket belum di-commit milik pekerjaan lain (sama dengan catatan penugasan Fase 3–5).

Catatan rilis: `bin/penugasan_keamanan_preflight.php` dan preflight V7 memeriksa daftar migrasi lama; keduanya kini melihat 034 sebagai migrasi sesudah rilisnya (oracle 1). Penyesuaian alat rilis itu tidak dikerjakan di Fase 1.

## Batas

- **MariaDB 10.6.28 produksi, MySQL/cPanel, dan UAT belum diuji.** Migrasi 034 belum dijalankan di produksi.
- Aplikasi mobile tidak diubah: aplikasi lama tetap membaca `class` (kelas utama) dan daftar santri lengkap; penampilan `classes` adalah pekerjaan aplikasi terpisah.
- Uji konkurensi memakai proses PHP bersamaan pada satu mesin (bukan beban hosting).
- Perubahan kelas jadwal tidak menyimpan riwayat di `jadwal_kelas` (baris yang dilepas dihapus); riwayatnya ada di `audit_logs` (`schedule.update`, `kelas_ids` sebelum/sesudah) dan pada `pertemuan_kelas` setiap pertemuan.

## Panduan rilis dan pemulihan

Urutan rilis (keputusan Human Developer):

1. Backup penuh terverifikasi (dump + uji pulih pada salinan).
2. Uji `034` pada salinan berakhiran `_test` dari produksi: `DB_NAME=<salinan> php bin/migrate.php up`, lalu `DB_NAME=<salinan> php tests/jadwal_kelas_gabungan_f1_migration.php` hanya pada salinan uji.
3. Pasang kode **bersama** migrasi 034. Kode sadar skema: tanpa 034 tetap berjalan satu kelas dan menolak kelas gabungan dengan pesan jelas, sehingga urutan "kode dulu" tidak merusak halaman.
4. Periksa: jumlah `jadwal_kelas` (is_utama = 1) = jumlah jadwal berkelas sah; tiap pertemuan Dibuka/Selesai memiliki `pertemuan_kelas`; tidak ada `pertemuan_peserta` yang kelas snapshot-nya tidak tercantum di `pertemuan_kelas`.

Pemulihan:

- **Belum ada kelas gabungan:** `php bin/migrate.php rollback` (melepas 034 saja). Kode tetap berjalan satu kelas.
- **Sudah ada kelas gabungan:** rollback ditolak penjaga data (sengaja). Pilihan: (a) biarkan 034 dan perbaiki kode; (b) pulihkan backup sebelum rilis (kehilangan data sesudahnya); (c) migrasi korektif yang ditetapkan Human Developer. Jangan menghapus baris `jadwal_kelas`/`pertemuan_kelas` secara manual untuk memaksa rollback: kelas historis pertemuan gabungan akan hilang (peserta dan kelas asal tetap di `pertemuan_peserta`).
- Kembali ke kode lama dengan 034 tetap terpasang aman: kode lama mengabaikan kedua tabel, tetapi jadwal gabungan akan tampil dan membuka pertemuan hanya untuk kelas utama.

## Handoff

Audit independen Codex berikutnya untuk Fase 1. Tidak merge, deploy, migrasi produksi, atau memulai Fase 2/aplikasi.
