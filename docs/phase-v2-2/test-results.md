# Hasil Pengujian — V2 Fase 2

Tanggal eksekusi: 21 Agustus 2026.
Branch: `prd-v2-fase-2` (dibuat dari `origin/main` = `7792138`).
Migrasi yang diuji: `007_v2_phase2_pengajuan_routing_keputusan.sql`.

Lingkungan uji: MariaDB 10.11 + PHP 8.4, basis data `webalhasan_test` dibangun dari
dump produksi `k1807225_webalhasan.sql` + migrasi `001`–`006` + `007`. Karena dump
produksi tidak memuat baris `perizinan`, empat baris izin warisan (ID `3`, `7`,
`11`, `12`) disisipkan lebih dulu lalu di-backfill dengan
`bin/v2_phase1_backfill.php`, agar jalur data warisan benar-benar teruji.
**Tidak ada satu pun perintah dijalankan terhadap basis data produksi.**

## 1. Ringkasan suite

| Suite | Jenis | Hasil | Jumlah pemeriksaan |
|---|---|---|---|
| `tests/v2_phase2_static.php` | statis | ✅ lulus (0 gagal) | 139 |
| `tests/v2_phase2_integration.php` | integrasi DB | ✅ lulus (0 gagal) | 94 |
| `tests/v2_phase2_web_smoke.php` | HTTP end-to-end | ✅ lulus (0 gagal) | 35 |
| `bin/v2_phase2_verify.php` | verifikasi pasca-migrasi + manifest | ✅ lulus (0 berbeda) | 35 (26 inti + 9 manifest) |
| `bin/v2_phase2_preflight.php` | preflight | ✅ keluar 0 | backup + manifest + konflik |

## 2. Regresi V1 dan V2 Fase 1

Dijalankan ulang setelah seluruh perubahan Fase 2 terpasang:

| Suite | Hasil | Jumlah |
|---|---|---|
| `tests/phase1_static.php` | ✅ lulus | 63 |
| `tests/phase2_static.php` | ✅ lulus | 46 |
| `tests/phase3_static.php` | ✅ lulus | 34 |
| `tests/phase4_static.php` | ✅ lulus | 38 |
| `tests/phase5_static.php` | ✅ lulus | 36 |
| `tests/v2_phase1_static.php` | ✅ lulus | 112 |
| `tests/phase2_integration.php` | ✅ lulus | 12 |
| `tests/phase3_integration.php` | ✅ lulus | 10 |
| `tests/phase4_integration.php` | ✅ lulus | 14 |
| `tests/phase5_integration.php` | ✅ lulus | 20 |
| `tests/v2_phase1_integration.php` | ✅ lulus | 41 |

Catatan lingkungan: `tests/phase5_static.php` membaca proyek Expo. Jalankan dengan
`MOBILE_APP_ROOT=/path/ke/alhasanApps` bila repo web dan repo mobile tidak
bersebelahan. `tests/phase5_integration.php` membuat basis data `*_test`
sementara, sehingga akun MySQL uji memerlukan hak `CREATE DATABASE`.

Kontrak API V1 tidak disentuh: `api/v1/index.php` tidak berubah pada Fase 2
(endpoint perizinan dijadwalkan pada Fase 3), dan hal ini dijaga oleh pemeriksaan
statis pada `tests/v2_phase1_static.php` maupun `tests/v2_phase2_static.php`.

## 3. `php -l` seluruh berkas PHP baru/diubah

Dieksekusi di dalam `tests/v2_phase2_static.php` (25 berkas, seluruhnya lulus):

**Baru** — `app/Izin/IzinRouter.php`, `app/Izin/IzinIdempotency.php`,
`app/Izin/IzinWriteRepository.php`, `app/Izin/IzinWorkflowService.php`,
`portal/izin_buat.php`, `portal/izin_aksi.php`, `portal/izin_antrean.php`,
`bin/v2_phase2_preflight.php`, `bin/v2_phase2_verify.php`,
`tests/v2_phase2_static.php`, `tests/v2_phase2_integration.php`,
`tests/v2_phase2_concurrency_worker.php`, `tests/v2_phase2_web_smoke.php`.

**Diubah** — `app/bootstrap.php`, `app/Audit/AuditLogger.php`,
`app/Auth/Capabilities.php`, `app/Izin/IzinException.php`,
`app/Izin/IzinRepository.php`, `app/Izin/IzinService.php`, `portal/_ui.php`,
`portal/index.php`, `portal/izin.php`, `portal/izin_detail.php`,
`admin/admin_izin.php`, `admin/sidebar.php`.

## 4. Pengujian yang diminta secara eksplisit

| Pengujian diminta | Di mana diuji | Hasil |
|---|---|---|
| Akses santri di luar cakupan / kelas nonaktif | `KP-1a`–`KP-1i` | ✅ 403, daftar pilihan bersih, kelas nonaktif tidak menjadi cakupan/routing/capability, tidak ada baris tersimpan |
| Validasi tanggal | `KP-2a`–`KP-2d`, `WEB-11` | ✅ 422 untuk tanggal terbalik, tanggal tidak ada di kalender, alasan kosong; tanpa baris tersimpan |
| Idempotensi pengajuan | `KP-3a`–`KP-3d`, `KP-13e`, `WEB-9`, `WEB-10` | ✅ satu pengajuan untuk kunci sama (berurutan, bersamaan, dan refresh POST); isi berbeda dengan kunci sama → 409 |
| Pengajuan tumpang tindih | `KP-4a`–`KP-4d`, `KP-11g`, `WEB-12` | ✅ 409 untuk rentang identik maupun bersinggungan sebagian; rentang lepas diterima; pembatalan melepas slot |
| Routing satu murobi | `KP-5a`–`KP-5d` | ✅ status `Diajukan`, murobi tepat, muncul di antrean murobi itu saja |
| Routing nol murobi | `KP-6a`, `KP-6b`, `KP-6e`, `KP-6f` | ✅ `Perlu Penetapan Admin`, `routing_kandidat = 0`, hanya di antrean admin |
| Routing >1 murobi | `KP-6c`–`KP-6f` | ✅ `Perlu Penetapan Admin`, `routing_kandidat = 2`, tidak terlihat oleh murobi mana pun |
| Akses silang antarmurobi | `KP-7a`–`KP-7e`, `WEB-13` | ✅ 403 untuk keputusan maupun detail; guru tanpa penugasan murobi juga 403 |
| Dua keputusan bersamaan | `KP-13a`–`KP-13d`, `WEB-17` | ✅ dua proses PHP terpisah: tepat satu berhasil, satu 409, satu baris keputusan, satu status akhir |
| Admin Pengganti tanpa alasan | `KP-9a`, `WEB-23` | ✅ 422; keputusan sah menyimpan kapasitas `Admin Pengganti` + alasan penggantian (`KP-9b`, `KP-9c`, `WEB-24`) |
| Akses silang orang tua | `KP-10a`–`KP-10e`, `WEB-25`–`WEB-28` | ✅ 403 lintas wali; tanpa tombol mutasi; tidak dapat membuat/memutus/membatalkan |
| Pembatalan tanpa kehilangan riwayat | `KP-11a`–`KP-11g`, `KP-12c` | ✅ riwayat bertambah, riwayat lama utuh, pembatalan kedua 409, pembatalan setelah keputusan 409 |
| Koreksi tanpa kehilangan riwayat | `KP-14a`–`KP-14h` | ✅ nilai sebelum/sesudah tersimpan, riwayat bertambah, baris keputusan tidak dihapus/diduplikasi |
| Kelengkapan audit | `KP-15a`–`KP-15d` | ✅ enam aksi audit tercatat, seluruh riwayat punya pelaku/alasan/waktu, tanpa credential |
| Regresi Fase 1 dan V1 | §2 di atas | ✅ 11 suite lulus |
| `php -l` seluruh berkas baru/diubah | §3 di atas | ✅ 25 berkas |

## 5. Bukti konkurensi

`tests/v2_phase2_integration.php` menjalankan `tests/v2_phase2_concurrency_worker.php`
sebagai **dua proses PHP terpisah** yang menunggu penanda waktu yang sama sebelum
memulai transaksi.

- **Dua keputusan bersamaan** atas satu pengajuan (kunci idempotensi berbeda,
  versi sama): satu proses mengembalikan keputusan, proses lain mengembalikan
  `409`. Basis data menyimpan tepat satu baris `izin_keputusan` dan satu status
  akhir. Proses kedua tertahan pada penguncian baris `FOR UPDATE` sampai proses
  pertama commit, lalu melihat status yang sudah berubah.
- **Dua pembuatan bersamaan** dengan kunci idempotensi yang sama: hanya satu baris
  `izin_pengajuan` bertambah; proses kedua menerima respons tersimpan yang sama
  (`idempotent_replay = true`).

Tiga lapis pengaman yang membuat hasil ini deterministik:

1. penguncian baris (`SELECT … FOR UPDATE` pada pengajuan dan santri),
2. `UPDATE … WHERE version = ? AND status IN (…)` (optimistic version),
3. kunci unik basis data (`izin_keputusan_pengajuan_unique`,
   `izin_idempotency_unique`).

## 6. Idempotensi migrasi dan rollback

Diuji langsung terhadap `webalhasan_test`:

| Langkah | Hasil |
|---|---|
| `migrate.php up` (007) | diterapkan |
| `migrate.php up` lagi | "Tidak ada migrasi baru" |
| jalankan ulang berkas migrasi 007 mentah | sukses, tanpa error duplikat |
| jalankan berkas rollback 007 | sukses |
| jalankan berkas rollback 007 lagi | sukses (idempoten) |
| jalankan ulang berkas migrasi 007 | sukses, skema kembali lengkap (27 kolom `izin_pengajuan`) |

## 7. Yang belum diuji otomatis

- Uji manual di browser sungguhan oleh manusia (lihat
  `docs/phase-v2-2/acceptance-status.md` §4).
- Alur mobile/API perizinan — **di luar ruang lingkup Fase 2** (Fase 3).
- Notifikasi in-app/push/WhatsApp — **di luar ruang lingkup Fase 2** (Fase 4).
- Migrasi pada basis data produksi — belum dan tidak boleh dijalankan otomatis.

## 8. Koreksi hasil audit Codex

Audit independen setelah commit implementasi menemukan dan memperbaiki dua celah
yang belum dicakup suite awal:

1. target kelas yang kemudian dinonaktifkan/diarsipkan tidak lagi dianggap sebagai
   cakupan pembimbing, kandidat routing, atau sumber kemampuan murobi;
2. audit perizinan kini wajib berhasil di dalam transaksi; kegagalan menyimpan
   audit membatalkan seluruh mutasi, bukan menghasilkan perubahan tanpa jejak.

Pemeriksaan cakupan dan tahun ajaran pada pembuatan juga dipindahkan ke dalam
transaksi setelah baris santri dikunci. Seluruh suite pada §1–§2, smoke HTTP,
konkurensi dua proses, preflight/verify, migrasi mentah dua kali, dan rollback
mentah dua kali dijalankan ulang setelah koreksi dan seluruhnya lulus.

## 9. Hotfix navigasi murobi (pasca-deployment)

**Laporan lapangan:** setelah Fase 2 dirilis, akun murobi yang login selalu
mendarat di `admin/pertemuan_pengajian.php` dan tidak punya jalan menuju antrean
keputusan; antreannya sendiri sudah benar di `/portal/izin_antrean.php?mode=murobi`.

**Akar masalah (dikonfirmasi lewat reproduksi HTTP sebelum kode diubah).** Tiga
titik pengarahan memutuskan tujuan dari **role mentah**, bukan capability:

| Berkas | Baris masalah | Akibat |
|---|---|---|
| `admin/cek_login.php` | `in_array('guru', $roles)` → jadwal | murobi tidak pernah sampai ke antrean |
| `admin/admin_login.php` | pemeriksaan role yang sama pada sesi hidup | murobi terlempar balik ke jadwal |
| `admin/ubah_password.php` | `match` berbasis role | tawaran lanjut mengarah ke jadwal |

Ditambah dua celah navigasi: halaman jadwal tidak punya tautan ke portal, dan
portal tidak punya tautan balik ke jadwal.

Reproduksi (`tests/v2_phase2_navigasi_murobi.php` terhadap kode **sebelum**
perbaikan) menghasilkan tepat lima kegagalan — `NAV-1`, `NAV-6`, `NAV-8`,
`NAV-12`, `NAV-25` — sementara seluruh pemeriksaan otorisasi, isi antrean, dan
isolasi antarmurobi tetap lulus. Ini membuktikan masalahnya murni navigasi, bukan
otorisasi maupun cakupan data.

**Perbaikan.** Aturan tujuan pasca-login dipusatkan pada `App\Auth\LandingRouter`
yang menilai `Capabilities` (role `guru` **dan** `murobi_assignments` aktif),
lalu dipakai bersama oleh ketiga berkas di atas. Urutannya: admin → murobi
(capability) → guru biasa → pengurus/orang tua. Tautan dua arah ditambahkan dan
dirender bersyarat, tanpa mengubah satu pun pemeriksaan otorisasi server.

**Hasil pengujian setelah perbaikan:**

| Suite | Hasil | Jumlah |
|---|---|---|
| `tests/v2_phase2_navigasi_murobi.php` (baru) | ✅ lulus | 32 |
| `tests/v2_phase2_static.php` | ✅ lulus | 164 |
| `tests/v2_phase2_integration.php` | ✅ lulus | 94 |
| `tests/v2_phase2_web_smoke.php` | ✅ lulus | 35 |
| `bin/v2_phase2_verify.php` | ✅ lulus | 35 (26 inti + 9 manifest) |
| `tests/phase1–5_static` + `v2_phase1_static` | ✅ lulus | 329 |
| `tests/phase2–5_integration` + `v2_phase1_integration` | ✅ lulus | 97 |

Dua pemeriksaan statis Fase 1 yang tadinya mengunci **lokasi** logika pengarahan
(string `in_array('guru', …)` di dalam `cek_login.php`/`ubah_password.php`)
diperbarui agar mengunci **perilaku** pada sumber kebenaran barunya
(`LandingRouter`). Jaminannya tidak berkurang: admin, guru biasa, pengurus, dan
orang tua tetap mendarat di tempat yang sama, dan hal itu kini juga dibuktikan
lewat HTTP nyata oleh `NAV-2`–`NAV-5`.

Tidak ada perubahan pada migrasi, aturan routing, aturan keputusan, cakupan data,
maupun kontrak API. Fase 3 tidak disentuh.
