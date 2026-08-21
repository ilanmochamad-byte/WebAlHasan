# Status Penerimaan — V2 Fase 2

Tanggal verifikasi: 21 Agustus 2026.
Branch: `prd-v2-fase-2`. Migrasi: `007_v2_phase2_pengajuan_routing_keputusan.sql`.
Seluruh bukti berasal dari eksekusi nyata pada salinan `webalhasan_test`
(lihat `docs/phase-v2-2/test-results.md`). Basis data produksi tidak disentuh.

Penanda bukti:
`KP-*` = `tests/v2_phase2_integration.php`, `WEB-*` = `tests/v2_phase2_web_smoke.php`,
`ST` = `tests/v2_phase2_static.php`, `VF` = `bin/v2_phase2_verify.php`.

## 1. Kriteria penerimaan PRD Fase 2

| # | Kriteria | Status | Bukti |
|---|---|---|---|
| 1 | Pengurus hanya dapat memilih santri dalam cakupan pembimbingnya | ✅ | `KP-1a`/`KP-1b` daftar pilihan hanya memuat santri binaan; `KP-1c` kelas nonaktif tidak masuk pilihan; `KP-1d` pencarian tidak memunculkan santri luar; `KP-1e`/`KP-1f` pengiriman paksa ke luar cakupan/kelas nonaktif → **403**; `KP-1g`/`KP-1h` kelas nonaktif tidak menjadi kandidat routing/kemampuan murobi; `KP-1i` tidak ada baris tersimpan |
| 2 | Pengajuan dengan tanggal kembali sebelum tanggal izin ditolak `422` dan tidak menyimpan baris | ✅ | `KP-2a` **422** pada layanan, `WEB-11` **422** lewat HTTP, `KP-2d` jumlah baris tidak berubah; `KP-2b` tanggal tak ada di kalender juga **422** |
| 3 | Dua request identik dengan idempotency key sama menghasilkan satu pengajuan | ✅ | `KP-3a`–`KP-3c` dua panggilan berurutan → satu ID, penanda `idempotent_replay`; `KP-13e` dua **proses bersamaan** → satu baris; `WEB-9`/`WEB-10` refresh POST tidak menambah baris; `KP-3d` kunci sama + isi beda → **409** |
| 4 | Pengajuan tumpang tindih untuk santri dan rentang aktif yang sama ditolak `409` | ✅ | `KP-4a` rentang identik **409**; `KP-4b` bersinggungan sebagian **409**; `WEB-12` **409** lewat HTTP; `KP-4c` tanpa baris tersimpan; `KP-4d` rentang lepas tetap diterima |
| 5 | Pengajuan dengan satu murobi valid muncul pada antrean murobi tersebut | ✅ | `KP-5a` status `Diajukan`; `KP-5b` `murobi_guru_id` = guru dengan penugasan kamar yang cocok; `KP-5c` muncul di antrean murobi itu; `KP-5d` tidak terlihat murobi lain; `WEB-15` terlihat pada antrean lewat HTTP |
| 6 | Pengajuan tanpa routing tunggal masuk antrean admin dan tidak terlihat murobi yang tidak ditetapkan | ✅ | `KP-6a`/`KP-6b` nol kandidat → `Perlu Penetapan Admin`, `routing_kandidat = 0`; `KP-6c`/`KP-6d` dua kandidat → `Perlu Penetapan Admin`, `routing_kandidat = 2`; `KP-6e` keduanya di antrean admin; `KP-6f` tidak terlihat oleh kedua murobi |
| 7 | Murobi A menerima `403` ketika mencoba memutus pengajuan milik Murobi B | ✅ | `KP-7a` keputusan lintas murobi **403**; `KP-7b` detail lintas murobi **403**; `WEB-13` **403** lewat HTTP; `KP-7c` guru tanpa penugasan murobi juga **403** |
| 8 | Dua keputusan bersamaan menghasilkan tepat satu keputusan dan satu status akhir | ✅ | `KP-13a`–`KP-13d` dua proses PHP terpisah: satu berhasil, satu **409**, `COUNT(izin_keputusan) = 1`, satu status akhir; `KP-12b` keputusan kedua berurutan **409**; `WEB-17` **409** lewat HTTP; `KP-12d` versi kedaluwarsa **409** |
| 9 | Admin pengganti tidak dapat memutus tanpa alasan; keputusan valid menyimpan kapasitas `Admin Pengganti` | ✅ | `KP-9a` alasan penggantian kosong → **422**; `WEB-23` **422** lewat HTTP; `KP-9b`/`KP-9c` kapasitas `Admin Pengganti` + alasan penggantian tersimpan di basis data; `WEB-24` alur HTTP berhasil (**303**) |
| 10 | Orang tua A menerima `403` untuk pengajuan santri yang tidak terhubung | ✅ | `KP-10b` **403** lintas wali; `WEB-28` **403** lewat HTTP; `KP-10a`/`WEB-25` santri terhubung tetap terbaca; `KP-10c`–`KP-10e`, `WEB-26`, `WEB-27` orang tua tidak dapat memutus/membatalkan/mengajukan |
| 11 | Pembatalan/koreksi tidak menghapus keputusan atau riwayat sebelumnya | ✅ | `KP-11d`/`KP-11e` riwayat bertambah, peristiwa `pengajuan_dibuat` tetap ada; `KP-14d`–`KP-14h` koreksi menyimpan nilai sebelum/sesudah, riwayat `keputusan` tetap ada, baris `izin_keputusan` tidak dihapus/diduplikasi; `VF` invarian yang sama diperiksa ulang pasca-migrasi |
| 12 | Seluruh perubahan memiliki pelaku, waktu, dan alasan yang sesuai pada riwayat/audit | ✅ | `KP-15a` riwayat memuat `pengajuan_dibuat`, `routing_otomatis`, `keputusan`, `keputusan_dikoreksi`; `KP-15b` seluruh baris riwayat V2 punya pelaku + alasan + waktu; `KP-15c` audit mencatat 6 aksi; `KP-15d` tanpa credential/secret; `ST` memastikan riwayat menyimpan IP/user agent tanpa credential |

## 2. Persyaratan Fase 2 (§6 PRD)

| # | Persyaratan | Status | Bukti |
|---|---|---|---|
| 1 | Pengurus melihat santri dalam cakupan penugasan pembimbing aktif | ✅ | `IzinRepository::santriForPengurusPaged()`; `KP-1a`–`KP-1c` |
| 2 | Pengurus membuat pengajuan dengan santri, tanggal, alasan, catatan | ✅ | `portal/izin_buat.php` + `IzinWorkflowService::create()`; `WEB-7` |
| 3 | Validasi server menolak santri di luar cakupan, tanggal terbalik, data tidak aktif, tumpang tindih | ✅ | `KP-1e`/`KP-1f`, `KP-2a`–`KP-2c`, `KP-4a`/`KP-4b`; santri nonaktif dan kelas nonaktif ditolak di dalam transaksi setelah baris santri dikunci |
| 4 | Create memakai transaksi dan idempotency key | ✅ | `ST` (transactional + 5 mutasi ber-idempotensi); `KP-3a`–`KP-3c`, `KP-13e` |
| 5 | Routing memilih murobi dari penugasan aktif yang cocok kamar/kelas dan tahun ajaran | ✅ | `IzinRouter::resolve()`; `KP-1g`, `KP-5b`; `ST` memastikan query memakai `murobi_assignments` + tahun ajaran aktif + plotting dan menolak master kelas nonaktif/arsip |
| 6 | Kasus tanpa kandidat atau lebih dari satu masuk antrean penetapan admin | ✅ | `KP-6a`–`KP-6f` |
| 7 | Murobi hanya melihat dan memutus pengajuan yang diarahkan kepadanya | ✅ | `KP-5c`/`KP-5d`, `KP-7a`/`KP-7b`, `WEB-13`/`WEB-14` |
| 8 | Admin menetapkan/menetapkan ulang murobi dan memutus sebagai pengganti dengan alasan wajib | ✅ | `KP-8a`–`KP-8g` (alasan wajib, guru tak layak ditolak, non-admin 403, penetapan ulang tercatat), `KP-9a`–`KP-9c` |
| 9 | Keputusan memakai transaksi, optimistic version, dan idempotency key | ✅ | `KP-12d` versi kedaluwarsa **409**; `KP-13a`–`KP-13d` konkurensi; `ST` |
| 10 | Pengurus membatalkan pengajuan sebelum keputusan dengan alasan | ✅ | `KP-11b` tanpa alasan **422**; `KP-11c` berhasil; `KP-12c` setelah keputusan **409**; `KP-11a` lintas pengurus **403** |
| 11 | Orang tua melihat status dan riwayat santri yang terhubung | ✅ | `KP-10a`, `WEB-25`, `WEB-26` |
| 12 | Seluruh transisi status, routing, keputusan, pembatalan, koreksi tercatat pada riwayat dan audit | ✅ | `KP-8g`, `KP-11d`/`KP-11e`, `KP-14f`/`KP-14g`, `KP-15a`–`KP-15c`; `ST` memastikan kegagalan audit membatalkan transaksi perizinan |
| 13 | Daftar, detail, pencarian, filter, pagination, empty/error state untuk tiap peran | ✅ | `KP-15e`–`KP-15g`; halaman `portal/izin.php`, `portal/izin_antrean.php`, `portal/izin_buat.php`, `portal/izin_detail.php`; `WEB-4`, `WEB-19` |
| 14 | Modul `admin/admin_izin.php` diarsipkan lewat redirect kompatibel setelah regresi lulus; file/data tidak dihapus | ✅ | Redirect dipasang **setelah** seluruh suite Fase 2 + regresi lulus. `WEB-20` **302** ke portal, `WEB-21` `?id=<n>` → detail pengajuan yang sama; `ST` memastikan kode lama tetap utuh, tabel `perizinan` tidak disentuh, dan flag `IZIN_LEGACY_ENABLED` mati secara bawaan |

## 3. Matriks akses per peran (HTTP nyata)

| Peran | `/portal/index.php` | `/portal/izin.php` | `/portal/izin_antrean.php` | `/portal/izin_buat.php` | `/admin/admin_dashboard.php` | `/admin/admin_pembimbing.php` |
|---|---|---|---|---|---|---|
| Anonim | 302 → login | 302 → login | 302 → login | 302 → login | 302 → login | 302 → login |
| Pengurus | 200 | 200 | 200 | 200 | **403** | **403** |
| Murobi (guru + penugasan) | 200 | 200 | 200 | **403** | **403** | **403** |
| Guru tanpa penugasan murobi | **403** | **403** | **403** | **403** | **403** | **403** |
| Orang tua | 200 | 200 | 200 | **403** | **403** | **403** |
| Admin | 200 | 200 | 200 | 200 | 200 | 200 |

Isolasi detail lintas peran (`/portal/izin_detail.php?id=…`):

| Pemanggil | Pengajuan dalam cakupan | Pengajuan peran lain |
|---|---|---|
| Murobi A | 200 (dengan panel keputusan) | **403** |
| Orang tua A | 200 (tanpa tombol mutasi) | **403** |

Kode status mutasi (`/portal/izin_aksi.php`): sukses **303**, tanpa CSRF **419**,
di luar cakupan **403**, validasi **422**, konflik status/versi/tumpang tindih **409**.

Tujuan setelah login (hotfix navigasi murobi, `App\Auth\LandingRouter`):

| Akun | Tujuan | Bukti |
|---|---|---|
| Admin | `/admin/admin_dashboard.php` | `NAV-3` |
| Guru + penugasan murobi aktif | `/portal/izin_antrean.php?mode=murobi` | `NAV-1`, `NAV-6` |
| Guru tanpa penugasan murobi | `/admin/pertemuan_pengajian.php` | `NAV-2` |
| Pengurus / orang tua | `/portal/index.php` | `NAV-4`, `NAV-5` |

Tautan navigasi dua arah dirender bersyarat dan **bukan** kontrol akses: guru
non-murobi yang mengetik URL portal tetap menerima **403** (`NAV-16`).

## 4. Yang MASIH harus diuji manusia sebelum rilis

Fase 2 **belum boleh dinyatakan selesai untuk produksi** sebelum langkah berikut
dikerjakan manusia (lihat `docs/phase-v2-2/cpanel-deployment.md`):

- [ ] Uji manual di browser sungguhan (Chrome/Safari desktop + mobile) untuk empat
      peran: pengurus, murobi, admin, orang tua.
- [ ] Verifikasi tampilan responsif halaman baru pada layar ponsel.
- [ ] Preflight + migrasi + verifikasi pada salinan produksi terbaru (bukan hanya
      dump `k1807225_webalhasan.sql` yang dipakai di sini).
- [ ] Persetujuan pengguna atas pengarsipan `admin/admin_izin.php`.
- [ ] Uji asap pasca-deploy di cPanel sesuai runbook.
- [ ] **Hotfix navigasi murobi:** login akun murobi produksi dan pastikan mendarat
      di antrean keputusan, tautan dua arah jadwal ⇄ antrean berfungsi, serta guru
      non-murobi tetap mendarat di jadwal tanpa tautan perizinan.

Seluruh kriteria penerimaan otomatis Fase 2 sudah memiliki bukti pengujian.
Butir manual di atas berada di luar jangkauan agen dan sengaja dibiarkan terbuka.
