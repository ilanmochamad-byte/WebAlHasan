# Peta alur masuk, beranda, ganti password, dan logout

Paket "Koreksi dan Modernisasi UI/UX V1–V2", koreksi ke-7 — keputusan pengguna
30 Agustus 2026: `https://alhasan.co.id/portal/` menjadi **satu pintu masuk**
seluruh sistem internal Al Hasan.

---

## 1. Alur

```
                    ┌───────────────────────────────────────────┐
   anonim ─────────▶│ /portal/index.php                          │
                    │   • tanpa sesi  → "Masuk Sistem Al Hasan"  │
                    │   • ada sesi    → Beranda                  │
                    └───────────────┬───────────────────────────┘
                                    │ POST username + password + _csrf
                                    ▼
                    ┌───────────────────────────────────────────┐
                    │ /admin/cek_login.php   (alamat lama,       │
                    │  satu-satunya penangan POST login)         │
                    │   1. Csrf::requireValid                    │
                    │   2. LoginThrottle                         │
                    │   3. AuthService::attempt (hash + regen)   │
                    └───┬───────────┬───────────────┬───────────┘
        gagal / terkunci│           │ wajib ganti   │ berhasil
                        ▼           ▼ password      ▼
        /portal/?pesan=gagal   /admin/ubah_password.php   LandingRouter
        /portal/?pesan=terkunci        │                        │
                                       │ selesai                ▼
                                       └──────────────▶ /portal/index.php
                                                          (Beranda)
                                                              │
   akun tanpa role/relasi sah ────────────────────────────────┘
   → beranda menampilkan penjelasan, tanpa akses tambahan

   Keluar: /admin/logout.php ──POST + CSRF──▶ Session::destroy
                                            ──▶ /portal/?pesan=logout
```

### Catatan penting

- **Tidak ada sistem login kedua.** Formulir pada `/portal/` mengirim ke
  `/admin/cek_login.php`, penangan POST yang sama dengan alamat lama, memakai
  `AuthService`, `AuthRepository`, sesi, dan CSRF yang sudah ada.
- **`/portal/index.php` tidak memakai `portal/_guard.php`.** Guard itu menuntut
  kemampuan perizinan; memakainya di beranda umum adalah sebab guru non-murobi
  ditolak 403. Beranda hanya menuntut sesi yang sah; tiap modul di dalamnya tetap
  memeriksa hak dan cakupannya sendiri di server.
- **Satu pintu masuk ≠ satu berkas PHP.** Halaman internal tetap terpisah sesuai
  fungsinya.

---

## 2. Pemetaan alamat lama → alamat baru

| Alamat lama | Perilaku sekarang | Tujuan |
| --- | --- | --- |
| `/admin/admin_login.php` (GET) | 302, membawa `pesan` dan `next` yang lolos validasi | `/portal/index.php` |
| `/admin/cek_login.php` (POST) | **tidak berubah** — tetap penangan login | — |
| `/admin/logout.php` | tetap; tujuan setelah keluar berubah | `/portal/index.php?pesan=logout` |
| `/admin/ubah_password.php` | tetap; menghormati `next` | `LandingRouter` → beranda |
| sesi kedaluwarsa pada halaman internal mana pun | 302 dengan `pesan=sesi` dan `next` | `/portal/index.php` |
| `/admin/admin_jadwal_ngaji.php` | GET 302 (filter terbawa), POST diteruskan | `/admin/admin_pengajian.php?tab=jadwal` |
| `/admin/pertemuan_pengajian.php` | GET 302 (konteks terbawa), POST diteruskan | `/admin/admin_pengajian.php?tab=pertemuan` |
| `/admin/admin_akun_perizinan.php` | GET 302 (tab peran), POST diteruskan | `/admin/admin_akun.php` |
| `/portal/index.php` (ringkasan perizinan lama) | isinya pindah | `/portal/izin_ringkasan.php` |

Tautan internal dan bookmark modul lain (`/portal/izin.php`, `/portal/laporan.php`,
`/admin/admin_master_santri.php`, dan seterusnya) **tidak berubah**.

---

## 3. Pemulihan tujuan (`next`)

Ketika pengguna anonim membuka tautan detail, tujuannya disimpan sebagai `next`
dan dipulihkan setelah masuk. `App\Http\SafeRedirect` menyaring **bentuk** alamat:

| Ditolak | Alasan |
| --- | --- |
| `https://situs-lain/…`, `//situs-lain/…`, `javascript:`, `data:` | tujuan eksternal atau skema berbahaya |
| alamat di luar `/admin/` dan `/portal/` | bukan halaman internal yang diizinkan |
| alamat tanpa akhiran `.php`, atau memuat `..` | bukan halaman internal yang sah |
| `index.php`, `admin_login.php`, `cek_login.php`, `logout.php` | memulihkannya hanya menghasilkan lingkaran masuk–keluar |
| lebih dari 512 karakter, atau memuat karakter kendali | perlindungan pemisahan header |

**Penyaringan bentuk bukan otorisasi.** Setelah pengalihan, guard halaman tujuan
tetap memeriksa hak pengaksesnya — termasuk ketika pengguna masuk dengan akun
lain pada peramban yang sama. Diuji: `tests/perapihan_web_smoke.php` PM-9a/b/c.

---

## 4. Perlindungan lingkaran pengalihan

- `SafeRedirect` menolak halaman masuk/keluar sebagai tujuan `next`.
- `/portal/index.php` mengarahkan pemegang password sementara ke
  `ubah_password.php`; halaman itu tidak mengarahkan balik ke beranda selama
  password belum diganti.
- Rantai dari alamat lama ke pintu masuk berhenti dalam **satu** langkah.
  Diuji: `tests/perapihan_web_smoke.php` PM-8b (rantai ≤ 2 langkah).

---

## 5. Kompatibilitas API dan aplikasi mobile

Penyatuan pintu masuk hanya menyentuh alur **web**. Yang **tidak** diubah:

- endpoint autentikasi `/api/v1/*` dan alur login aplikasi;
- envelope JSON, status HTTP, pagination, filter, dan pencabutan token;
- `ApiTokenAuthenticator` dan seluruh guard API.

Diverifikasi oleh `tests/v2_phase3_api_contract.php`, `v2_phase4_api_contract.php`,
dan `v2_phase5_api_contract.php` yang seluruhnya tetap lulus tanpa perubahan
assertion.

---

## 6. Prosedur rollback perubahan routing

Rollback tidak memerlukan perubahan basis data.

1. Kembalikan branch ke `c65390d` (atau revert commit
   `feat(auth): satu pintu masuk /portal/ untuk seluruh peran`).
2. Berkas yang kembali ke perilaku lama: `portal/index.php`,
   `admin/admin_login.php`, `admin/cek_login.php`, `admin/logout.php`,
   `admin/ubah_password.php`, `app/Auth/LandingRouter.php`,
   `app/Auth/Authorization.php`.
3. `portal/izin_ringkasan.php` boleh tetap ada (tidak dirujuk lagi) atau dihapus.
4. Tidak ada migrasi yang perlu di-rollback untuk koreksi ke-7.
5. Bersihkan cache OPcache/hosting bila ada.

Bila hanya ingin **menonaktifkan pembatasan percobaan masuk** tanpa rollback
penuh: hapus pemanggilan `login_throttle()` pada `admin/cek_login.php`. Tidak ada
data yang perlu dipulihkan karena penghitungnya membaca `audit_logs` yang sudah ada.
