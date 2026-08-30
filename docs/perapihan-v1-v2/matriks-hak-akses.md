# Matriks hak akses dan beranda per peran

Paket "Koreksi dan Modernisasi UI/UX V1–V2" — keputusan pengguna 30 Agustus 2026.

> **Aturan induk.** Menyembunyikan menu **bukan** kontrol akses. Tabel di bawah
> menjelaskan apa yang *ditampilkan* dan apa yang *ditegakkan server*. Kolom
> "Ditegakkan di" menyebut tempat penegakannya.

---

## 1. Role dan kemampuan

| Role | Syarat relasi master | Kemampuan yang timbul |
| --- | --- | --- |
| `admin` | — | `admin` |
| `guru` | `users.guru_id` → `guru` aktif, tidak diarsipkan | akses modul Pengajian; **`murobi` hanya bila** ada `murobi_assignments` aktif pada tahun ajaran aktif |
| `pengurus` | `users.pengurus_id` → `pengurus` aktif | `pengurus` |
| `orang_tua` | `users.wali_id` → `wali` aktif | `orang_tua` |

**Murobi bukan role.** Ia kemampuan yang dihitung `App\Auth\Capabilities` dari
penugasan aktif. Penugasan murobi tidak membuat akun; tanpa akun ber-role `guru`,
kemampuan keputusan tidak muncul.

Penetapan role tanpa relasi master yang valid **ditolak server**
(`AccountService::requireMasterRelation`), bukan hanya disembunyikan dari
formulir.

---

## 2. Beranda dan menu per peran

Seluruh peran mendarat pada **satu beranda** `/portal/index.php`. Yang berbeda
adalah panel dan pintasan yang disusun dari kemampuan nyata.

| Peran | Pintasan pada beranda | Kelompok menu yang tampil |
| --- | --- | --- |
| Admin | Ringkasan administrasi · Jadwal & pertemuan pengajian | Utama, Pengajian, Perizinan, Master Data, Penugasan, Akun & Sistem, PSB & Keuangan, Lain-lain |
| Guru (tanpa murobi) | Jadwal & pertemuan pengajian | Utama, Pengajian, Akun & Sistem |
| Guru + penugasan murobi | Jadwal & pertemuan · **Antrean keputusan perizinan** | Utama, Pengajian, Perizinan, Akun & Sistem |
| Pengurus | Pengajuan izin santri | Utama, Perizinan, Akun & Sistem |
| Orang tua | Status izin anak | Utama, Perizinan, Akun & Sistem |
| Akun tanpa role/relasi sah | — (penjelasan, tanpa akses tambahan) | Utama, Akun & Sistem |

Akun multi-peran memperoleh **gabungan** dari baris-baris di atas, dalam satu
sesi, tanpa login ulang. Tidak ada pemilihan role pada formulir masuk.

---

## 3. Matriks akses halaman

| Halaman | Admin | Guru | Murobi | Pengurus | Orang tua | Ditegakkan di |
| --- | --- | --- | --- | --- | --- | --- |
| `/portal/index.php` (beranda) | ✅ | ✅ | ✅ | ✅ | ✅ | `Authorization::requireWebUser()` — hanya menuntut sesi |
| `/portal/izin_ringkasan.php` | ✅ | ❌ 403 | ✅ | ✅ | ✅ | `PortalGuard::requireAnyPerizinan()` |
| `/portal/izin.php`, `izin_detail.php` | ✅ | ❌ 403 | ✅ | ✅ | ✅ | `PortalGuard` + cakupan `IzinService` |
| `/portal/izin_antrean.php` | ✅ | ❌ 403 | ✅ | ✅ | ✅ | `PortalGuard` + cakupan mode |
| `/portal/izin_buat.php` | ✅ | ❌ 403 | ❌ 403 | ✅ | ❌ 403 | `PortalGuard` + `IzinService` |
| `/portal/izin_aksi.php` (keputusan) | ✅ | ❌ 403 | ✅ | ❌ | ❌ | `IzinWorkflowService` |
| `/portal/laporan*.php` | ✅ | ❌ 403 | ✅ | ✅ | ✅ | `IzinReportService` (cakupan dihitung ulang) |
| `/admin/admin_pengajian.php` (tab Pertemuan) | ✅ | ✅ | ✅ | ❌ 403 | ❌ 403 | guard role admin/guru pada modul |
| `/admin/admin_pengajian.php` (aksi tab Jadwal) | ✅ | ❌ 403 | ❌ 403 | ❌ | ❌ | penolakan POST eksplisit + `Denial::render` |
| `/admin/admin_pengajian.php` (lihat jadwal) | seluruh jadwal | **hanya miliknya** | hanya miliknya | ❌ | ❌ | filter `guru_id` dipaksa di server |
| `/admin/admin_laporan_absensi.php` | seluruh guru | hanya jadwal miliknya | idem | ❌ 403 | ❌ 403 | `ReportFilter::forUser()` |
| `/admin/admin_akun.php` dan seluruh master data | ✅ | ❌ 403 | ❌ 403 | ❌ 403 | ❌ 403 | `admin/_guard.php` (`requireWebRole('admin')`) |
| `/admin/ubah_password.php`, `logout.php` | ✅ | ✅ | ✅ | ✅ | ✅ | `requireWebUser()` |
| `/portal/notifikasi.php` | ✅ | ❌ 403 | ✅ | ✅ | ✅ | `PortalGuard` |

Legenda: ✅ terbuka · ❌ 403 ditolak server dengan halaman penjelasan.

> **Perubahan penting dibanding sebelum paket ini:** baris pertama. Sebelumnya
> `/portal/index.php` memakai guard kemampuan perizinan, sehingga **guru tanpa
> penugasan murobi selalu ditolak 403 di beranda umum**. Itulah cacat yang
> diperbaiki koreksi ke-7. Seluruh baris lain tidak dilonggarkan.

---

## 4. Aturan pengelolaan hak akses

| Tindakan | Aturan |
| --- | --- |
| Menambah role | Satu baris relasi; role lain dipertahankan. Akun harus aktif. |
| Menambah role `guru`/`pengurus`/`orang_tua` | Wajib punya relasi master yang valid **dan aktif**. |
| Menambah role `admin` | Dialog dampak + kalimat konfirmasi `BERI AKSES ADMIN` diketik ulang. |
| Mencabut role | Satu baris relasi; role lain dipertahankan. |
| Mencabut `admin` dari diri sendiri | **Ditolak.** |
| Menonaktifkan akun sendiri | **Ditolak.** |
| Mencabut/menonaktifkan admin aktif terakhir | **Ditolak**, termasuk pada permintaan bersamaan (transaksi + `SELECT … FOR UPDATE`). |
| Menonaktifkan akun | Seluruh perangkat push milik akun itu dicabut. |
| Reset password | Password sementara sekali tampil; pengguna wajib menggantinya. |
| Seluruh perubahan hak akses | Dicatat pada `audit_logs` (`account_role_granted`, `account_role_revoked`, `account_status_changed`, `account_password_reset`). |

## 5. Pencabutan hak dan sesi lama

Role dibaca ulang dari basis data pada **setiap** request oleh
`Authorization::currentUser()`, dan kemampuan dihitung ulang oleh `Capabilities`.
`$_SESSION['roles']` hanya ditulis untuk kompatibilitas modul lama dan **tidak
pernah dibaca** sebagai dasar otorisasi. Akibatnya, hak yang dicabut langsung
tidak berlaku pada pemeriksaan server berikutnya tanpa perlu mencabut sesi.

Diuji: `tests/perapihan_integration.php` KA-11a/KA-11b.

## 6. Pengaman yang dipertahankan tanpa perubahan

- CSRF pada seluruh mutasi (`Csrf::requireValid`).
- `password_hash`/`password_verify`, regenerasi ID sesi saat login.
- Kewajiban mengganti password sementara sebelum fungsi operasional.
- Idempotency key dan optimistic version pada alur perizinan V2.
- Batas ekspor CSV 20.000 baris dan netralisasi formula injection.
- Isolasi cakupan laporan perizinan per peran.
- WhatsApp tetap `OFF` dan tidak menghubungi penyedia mana pun.

## 7. Tambahan pengaman

`app/Auth/LoginThrottle.php` membatasi percobaan masuk (8 per username dan 20 per
IP dalam 15 menit) dengan menghitung `login_failed` pada `audit_logs` yang sudah
dicatat sejak V1 — tanpa tabel atau migrasi baru. Bila penghitungan gagal, ia
**membuka jalan** (fail-open) agar kesalahan infrastruktur tidak mengunci semua
orang; pengaman utama tetap hash password, CSRF, dan regenerasi sesi.
