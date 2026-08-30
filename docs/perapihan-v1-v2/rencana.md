# Paket "Koreksi dan Modernisasi UI/UX V1–V2" — Rencana dan Keputusan

**Tanggal keputusan pengguna:** 30 Agustus 2026
**Peran agen:** Claude = implementer utama. Codex = audit, pengujian ulang, koreksi terarah.
**Status:** implementasi selesai, **menunggu audit Codex**. Belum di-merge, belum di-push, belum dirilis.

> **Catatan kejujuran penulisan.** Dokumen ini merekam keputusan dan rancangan yang
> memandu implementasi. Ia dirapikan menjadi bentuk akhirnya bersamaan dengan
> penyelesaian kode, bukan ditandatangani lebih dulu lalu dibekukan. Bagian
> "keputusan" dan "batas pekerjaan" berasal dari instruksi pengguna; bagian
> "rancangan" adalah pilihan implementasi yang wajib diaudit Codex.

---

## 1. Ruang lingkup

Paket ini adalah **perbaikan V1–V2**, bukan implementasi fitur PRD V3.

Tujuh koreksi:

| # | Koreksi | Berkas utama |
| --- | --- | --- |
| 1 | Pengelolaan akun terpusat (Akun & Hak Akses) | `admin/admin_akun.php`, `app/Account/*` |
| 2 | Data santri dan wali (pencocokan + rekonsiliasi) | `admin/admin_master_santri.php`, `admin/admin_wali*.php`, `app/MasterData/*` |
| 3 | Data guru dan penugasan | `admin/admin_guru.php`, `admin/admin_murobi.php` |
| 4 | Satukan navigasi jadwal dan pertemuan | `admin/admin_pengajian.php` + dua potongan tampilan |
| 5 | Pisahkan penyajian laporan kehadiran | `app/Report/*`, `admin/admin_laporan_absensi.php` |
| 6 | Desain ulang UI/UX dan navigasi | `assets/ui/alhasan.css`, `app/Ui/*` |
| 7 | Satu pintu masuk `/portal/` | `portal/index.php`, `app/Auth/*`, `app/Http/SafeRedirect.php` |

### Di luar cakupan (tidak dikerjakan)

- Fitur baru PRD V3: konseling, pembiayaan, rapor.
- WhatsApp: tetap **OFF** dan **DITANGGUHKAN** sesuai keputusan Fase 4/5.
- Desain ulang website publik dan aplikasi React Native.
- Penggantian PHP native/MySQL/Bootstrap dengan framework lain.
- Upgrade besar dependency.
- Alur PSB (penerimaan santri baru) dan biaya PSB.
- Penghapusan data, tabel, kolom, akun, atau riwayat lama.

### Repositori mobile

`alhasanApps` **tidak diubah**. Ia hanya dipakai untuk pemeriksaan kompatibilitas
(`MOBILE_APP_ROOT` pada rangkaian uji). Lihat `risiko-dan-uji-tertunda.md` untuk
temuan baseline mobile yang **sudah ada sebelum paket ini**.

---

## 2. Baseline yang diverifikasi

| Repositori | Branch | HEAD | Worktree |
| --- | --- | --- | --- |
| WebAlHasan | `main` | `c65390dd03c4da1ddaacf9d3da9adf4293848c40` | bersih |
| alhasanApps | `main` | `ab3f84224308aabaa56c8300455f6673d5549bde` | bersih |

Sesuai dengan baseline yang disebut pengguna. Branch kerja: **`codex/perapihan-v1-v2-ui`**,
dicabangkan dari `c65390d`. Tidak ada `reset`, `rebase`, atau pembuangan perubahan pengguna.

---

## 3. Inventaris terdampak

### Halaman
Lihat `inventaris-halaman.md` (daftar lengkap halaman yang didesain ulang,
halaman kompatibilitas, dan halaman di luar cakupan).

### Layanan dan kelas

| Baru | Diubah |
| --- | --- |
| `app/Ui/Layout.php`, `Navigation.php`, `Denial.php`, `functions.php` | `app/Auth/Authorization.php`, `LandingRouter.php`, `PortalGuard.php` |
| `app/Http/SafeRedirect.php` | `app/Account/AccountRepository.php`, `AccountService.php` |
| `app/Auth/LoginThrottle.php` | `app/MasterData/MasterDataRepository.php`, `MasterDataService.php` |
| `assets/ui/alhasan.css` | `app/Report/ReportFilter.php`, `ReportRepository.php`, `ReportService.php` |
| | `app/bootstrap.php` (helper baru; layanan lama tidak diganti) |

### Tabel

| Tabel | Perubahan |
| --- | --- |
| `wali` | **+1 kolom** `merged_into_wali_id` dan 2 indeks (migrasi 010, aditif) |
| Seluruh tabel lain | **tidak ada perubahan skema** |

Tidak ada `DROP`, `DELETE`, `TRUNCATE`, atau `UPDATE` data pada migrasi.

### Endpoint

| Endpoint | Perubahan |
| --- | --- |
| `/api/v1/*` | **tidak ada perubahan kontrak.** Filter `subject_scope` bersifat aditif dan default API tetap `gabungan`. |
| `admin/get_wali_json.php` | **baru**, hanya untuk formulir santri, dijaga guard admin, hanya melayani GET. |

---

## 4. Aturan data dan hak akses yang dipegang

1. **Menyembunyikan menu bukan kontrol akses.** Setiap halaman memeriksa hak dan
   cakupannya sendiri di server. Peta navigasi (`App\Ui\Navigation`) sengaja tidak
   memuat guard apa pun.
2. **Kemampuan dihitung ulang dari basis data pada setiap request.** Role dibaca
   ulang oleh `Authorization::currentUser()`; `Capabilities` menghitung ulang
   murobi/pengurus/orang tua. Sesi lama **tidak dapat** mempertahankan hak yang
   sudah dicabut. `$_SESSION['roles']` hanya ditulis untuk kompatibilitas dan
   tidak pernah dibaca sebagai dasar otorisasi.
3. **Role ditambahkan dan dicabut satu per satu.** Tidak ada lagi jalur yang
   menghapus seluruh `user_roles` sebelum menetapkan satu role.
4. **Role guru/pengurus/orang_tua menuntut relasi master yang valid dan aktif.**
5. **Admin adalah tindakan khusus** (konfirmasi diketik ulang) dan **admin
   terakhir dilindungi**, termasuk pada permintaan bersamaan (transaksi +
   `SELECT ... FOR UPDATE`).
6. **Identitas wali tidak pernah digabungkan otomatis.** Nama dan nomor HP hanya
   petunjuk pencarian. Nomor HP boleh dipakai bersama.
7. **Data lama tidak dihapus.** Kolom `santri.nama_ayah/ibu` dipertahankan sebagai
   cermin dari relasi wali terverifikasi; wali yang digabungkan diarsipkan dengan
   ID lamanya tetap ada.
8. **Absensi guru tidak dihapus.** Pemisahan penyajian laporan hanya mengubah apa
   yang ditampilkan dan dihitung.

Matriks lengkap: `matriks-hak-akses.md`.

---

## 5. Struktur navigasi baru

Satu peta menu bersama (`App\Ui\Navigation`) dihitung dari **role + capability
nyata** akun:

```
Utama          Beranda · Ringkasan Administrasi (admin)
Pengajian      Jadwal & Pertemuan · Laporan Kehadiran        (guru/admin)
Perizinan      Ringkasan · Daftar · Antrean · Buat · Laporan (kemampuan perizinan)
Master Data    Santri · Orang Tua/Wali · Rekonsiliasi Wali · Guru · Pengurus ·
               Kelas · Kamar · Tahun Ajaran                  (admin)
Penugasan      Murobi · Pembimbing                           (admin)
Akun & Sistem  Notifikasi Saya · Akun & Hak Akses · Kanal Notifikasi · Ganti Password
PSB & Keuangan / Lain-lain                                   (admin)
```

Perubahan besar dibanding sebelumnya:

- dua menu "Jadwal Pengajian" dan "Pertemuan Pengajian" **menjadi satu** menu
  "Jadwal & Pertemuan" bertab;
- dua menu "Akun & Hak Akses" dan "Akun Pengurus & Orang Tua" **menjadi satu**;
- "Guru & Pembimbing" menjadi **"Data Guru"**;
- label "Portal Perizinan" tidak lagi menjadi identitas seluruh sistem; namanya
  **Sistem Al Hasan**, dan perizinan adalah salah satu modulnya.

Peta alur masuk, beranda, ganti password, dan logout: `peta-alur-masuk.md`.

---

## 6. Standar visual

Ringkas: identitas hijau Al Hasan yang tenang di atas latar netral, satu berkas
token (`assets/ui/alhasan.css`), Bootstrap 5.3 dipertahankan, tanpa dependency
atau aset eksternal baru. Rincian dan aturan komponen: `standar-desain.md`.

---

## 7. Kriteria penerimaan

Status per kriteria (dengan bukti): `status-penerimaan.md`.
Hasil pengujian dan tangkapan layar: `hasil-pengujian.md`.

---

## 8. Risiko dan strategi rollback

| Risiko | Mitigasi |
| --- | --- |
| Perubahan alamat halaman masuk memutus bookmark | `/admin/admin_login.php` dipertahankan sebagai pengalihan; POST tetap ke `/admin/cek_login.php` |
| Alamat lama jadwal/pertemuan/akun perizinan | GET dialihkan, **POST diteruskan penuh** ke modul tujuan sehingga CSRF dan validasi mutasi tetap berjalan |
| Kolom lama ayah/ibu menjadi dua sumber pengeditan | Kolom dijadikan cermin satu arah; tidak dapat diketik langsung pada formulir santri |
| Penggabungan wali salah orang | Satu pasang per tindakan, konfirmasi wajib, diblokir bila menyangkut akun login, baris sumber diarsipkan bukan dihapus |
| Perubahan default laporan merusak aplikasi mobile | Default REST API sengaja tetap `gabungan`; hanya halaman web yang meminta `santri` |
| CSS bersama mengubah halaman lama di luar cakupan | `assets/ui/alhasan.css` hanya aktif pada elemen ber-kelas `ah-*` dan `body.ah`; halaman lama memuatnya lewat `admin/sidebar.php` hanya untuk menu |
| Migrasi 010 | Aditif, idempoten, punya rollback berpasangan; lihat `migrasi-dan-rollback.md` |

**Rollback keseluruhan paket:** paket ini berada pada satu branch terpisah.
Mengembalikan `main` ke `c65390d` memulihkan seluruh perilaku lama. Satu-satunya
perubahan skema (migrasi 010) punya rollback berpasangan dan tidak dibutuhkan
oleh kode lama.

---

## 9. Yang TIDAK boleh diklaim

- Paket ini **belum lolos audit Codex**.
- Paket ini **belum diuji pada Safari fisik** (macOS/iOS).
- Paket ini **belum dijalankan pada produksi** dan migrasi 010 **belum
  dijalankan pada produksi**.
- Tidak ada klaim "siap rilis".

Daftar lengkap yang menunggu verifikasi: `risiko-dan-uji-tertunda.md`.
