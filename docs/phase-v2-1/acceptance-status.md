# Status Penerimaan — V2 Fase 1

Tanggal verifikasi: 21 Agustus 2026.
Branch: `prd-v2`. Migrasi: `006_v2_phase1_perizinan_foundation.sql`.

Seluruh angka di bawah ini berasal dari eksekusi nyata pada salinan MySQL/MariaDB
berakhiran `_test` yang dibangun dari dump produksi `k1807225_webalhasan.sql`
ditambah migrasi V1 `001`–`005`. Karena dump produksi tidak memuat baris
`perizinan`, empat baris izin warisan (ID `3`, `7`, `11`, `12`; status `Pending`,
`Disetujui`, `Ditolak`, `Pending`) disisipkan lebih dulu agar preservasi ID dan
jumlah benar-benar teruji, bukan hanya lolos secara vakum.

## 1. Kriteria penerimaan PRD

| # | Kriteria | Status | Bukti |
|---|---|---|---|
| 1 | Jumlah dan ID pengajuan lama sebelum/sesudah migrasi sama; nilai bisnis lama masih terbaca | ✅ | `bin/v2_phase1_verify.php` membandingkan daftar ID dengan `manifest.json` pra-migrasi: 4 vs 4, ID identik, seluruh kolom `id_santri`/`tgl_izin`/`tgl_kembali`/`alasan`/`status` cocok |
| 2 | Data lama tanpa pelaku tampil sebagai `Data warisan`, bukan pengguna fiktif | ✅ | Verify: kolom pelaku `NULL` untuk semua baris `is_legacy = 1`; integrasi: `sumber_label`/`pengurus_label`/`murobi_label` = `Data warisan` |
| 3 | Admin dapat menghubungkan satu akun pengurus dan satu akun orang tua ke master masing-masing | ✅ | Integrasi: pembuatan akun pengurus + penghubungan akun orang tua; percobaan ganda ditolak oleh `users_pengurus_unique` / `users_wali_unique` |
| 4 | Satu akun orang tua hanya melihat santri dengan relasi wali aktif | ✅ | Integrasi: `santriInScope()` mengembalikan tepat satu santri; relasi `santri_wali` yang diarsipkan tidak muncul |
| 5 | Guru tanpa penugasan murobi aktif tidak mendapat kemampuan keputusan izin | ✅ | Integrasi: kemampuan `[]` untuk guru biasa; kemampuan `murobi` hilang saat `tanggal_selesai` dilewati dan kembali saat penugasan aktif lagi |
| 6 | Penugasan pembimbing hanya memakai pengurus aktif dan target kamar/kelas valid | ✅ | Integrasi: penolakan pengurus nonaktif, kamar tidak ada, dan `tanggal_selesai < tanggal_mulai` |
| 7 | Setiap portal menolak role tidak berwenang dengan `403` atau redirect aman | ✅ | Smoke HTTP (lihat tabel §2) |
| 8 | Login dan endpoint V1 guru tetap lulus regresi | ✅ | `tests/phase1_static.php` … `tests/phase5_integration.php` seluruhnya lulus dengan overlay V2 terpasang; `api/v1/index.php` tidak diubah |
| 9 | Backup dapat dipulihkan pada database `_test` dan jumlah baris cocok dengan manifest | ✅ | `bin/v2_phase1_preflight.php` → `database.sql` + `manifest.json`; `bin/verify_restore.php` dan `bin/v2_phase1_verify.php` mencocokkan jumlah baris tabel inti |
| 10 | Seluruh file PHP baru/diubah lolos `php -l` dan seluruh tes V1 tetap lulus | ✅ | 24 file dilint di dalam `tests/v2_phase1_static.php`; 9 suite V1 lulus |

## 2. Smoke test HTTP per peran

Dijalankan terhadap server PHP lokal pada salinan `_test` dengan akun uji nyata.

| Peran (akun) | Tujuan setelah login | `/portal/index.php` | `/portal/izin.php` | `/admin/admin_dashboard.php` | `/admin/admin_pembimbing.php` | `/admin/admin_akun_perizinan.php` | `/admin/pertemuan_pengajian.php` |
|---|---|---|---|---|---|---|---|
| Admin | `admin_dashboard.php` | 200 | 200 | 200 | 200 | 200 | 200 |
| Pengurus | `portal/index.php` | 200 | 200 | **403** | **403** | **403** | **403** |
| Orang tua | `portal/index.php` | 200 | 200 | **403** | **403** | **403** | **403** |
| Guru tanpa penugasan murobi | `pertemuan_pengajian.php` | **403** | **403** | **403** | **403** | **403** | 200 |
| Guru dengan penugasan murobi | `pertemuan_pengajian.php` | 200 | 200 | **403** | **403** | **403** | 200 |
| Anonim | — | 302 → `admin_login.php?pesan=sesi` | — | 302 → `admin_login.php?pesan=sesi` | — | — | — |

Isolasi detail lintas peran (`/portal/izin_detail.php?id=…`):

| Pemanggil | Pengajuan dalam cakupan | Pengajuan milik peran lain |
|---|---|---|
| Pengurus A | 200 | **403** |
| Orang tua A | 200 | **403** |
| Murobi A | 200 | **403** |

Alur V1 tidak berubah: guru tetap mendarat di `pertemuan_pengajian.php`, admin tetap
di `admin_dashboard.php`.

## 3. Hasil suite pengujian

| Suite | Hasil |
|---|---|
| `tests/phase1_static.php` … `tests/phase5_static.php` | lulus (0 gagal) |
| `tests/phase2_integration.php` … `tests/phase5_integration.php` | lulus (0 gagal) |
| `tests/v2_phase1_static.php` | lulus (0 gagal) |
| `tests/v2_phase1_integration.php` | lulus (0 gagal), fixture dibersihkan otomatis |

Perintah:

```bash
php tests/v2_phase1_static.php
V2_PHASE1_RUN_INTEGRATION=1 php tests/v2_phase1_integration.php
```

## 4. Hasil migrasi pada salinan uji

```
=== Preflight (pra-migrasi) ===
Pengajuan izin lama: 4
Relasi yatim memblokir: 0

=== Migrasi 006 ===
Diterapkan: 006_v2_phase1_perizinan_foundation.sql

=== Backfill ulang (uji idempotensi) ===
Baris `perizinan`         : 4
Pengajuan warisan sebelum : 4
Pengajuan warisan sesudah : 4
Baris baru ditambahkan    : 0

=== Verifikasi ===
Verifikasi V2 Fase 1: LULUS (31 pemeriksaan)
```

Rollback `006` diuji: seluruh tabel V2 terlepas, kolom `users.pengurus_id`/`wali_id`
beserta FK-nya hilang, role `pengurus`/`orang_tua` terhapus, dan tabel `perizinan`
lama tetap berisi 4 baris. Migrasi naik kembali menghasilkan hasil identik.

## 5. Belum termasuk Fase 1 (sesuai PRD)

- Pengajuan, routing, keputusan, pembatalan, dan koreksi — **Fase 2**.
- Endpoint `/api/v1` perizinan dan layar aplikasi Expo — **Fase 3**.
- Pengiriman notifikasi in-app/push/WhatsApp — **Fase 4** (skema dan sakelar sudah
  disiapkan pada Fase 1; WhatsApp mati bawaan dan tidak dapat dinyalakan sebelum
  pemeriksaan konfigurasi lulus, dijaga oleh CHECK constraint).
- Laporan, ekspor, dan migrasi produksi — **Fase 5**.
- Modul lama `admin/admin_izin.php` **belum** dialihkan; sesuai PRD Fase 2 butir 14
  pengalihan baru dilakukan setelah alur baru lolos regresi dan disetujui pengguna.

## 6. Langkah yang masih harus dijalankan pada lingkungan tujuan

Verifikasi di atas dilakukan pada salinan `_test`. Sebelum menerapkan ke produksi:

1. [ ] `php bin/v2_phase1_preflight.php` pada database produksi; pastikan kode keluar `0`.
2. [ ] Pastikan laporan relasi yatim `perizinan_tanpa_santri` bernilai **0**.
3. [ ] Terapkan `php bin/migrate.php up` pada salinan produksi terlebih dahulu.
4. [ ] `php bin/v2_phase1_verify.php <manifest.json>` dan pastikan LULUS.
5. [ ] Pulihkan `database.sql` ke database `_test` lalu jalankan `php bin/verify_restore.php`.
6. [ ] Smoke test manual seluruh peran pada lingkungan tujuan.
