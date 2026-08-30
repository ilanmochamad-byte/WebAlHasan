# Strategi rekonsiliasi identitas wali

Paket "Koreksi dan Modernisasi UI/UX V1–V2", koreksi ke-2 — keputusan pengguna
30 Agustus 2026.

---

## 1. Masalah yang diperbaiki

| Cacat sebelum paket ini | Akibat |
| --- | --- |
| `MasterDataService::saveSantri()` **selalu** membuat wali baru dari kolom `nama_ayah`/`nama_ibu` | Dua saudara kandung selalu berakhir dengan dua identitas ayah yang berbeda |
| Pengeditan kolom ayah/ibu santri lama tidak menyinkronkan data wali | Kolom lama dan relasi wali menyimpang tanpa ada yang tahu |
| Impor Excel memanggil jalur yang sama | Setiap baris impor melahirkan identitas wali baru |

---

## 2. Prinsip

1. **Sistem tidak pernah menebak identitas.** Nama dan nomor HP hanya petunjuk
   pencarian. Tidak ada penggabungan otomatis berdasarkan nama, nomor HP, atau
   pasangan nama ayah/ibu.
2. **Dua orang bernama sama tetap dua orang.** Bahkan dengan nomor HP yang sama.
3. **Nomor HP bukan identitas unik wajib.** Ia boleh dipakai bersama; migrasi 010
   menambahkan indeks pencarian, **bukan** kunci unik.
4. **Admin yang memutuskan.** Setiap penyatuan identitas adalah tindakan sadar
   dengan konfirmasi, satu pasang per tindakan.
5. **Tidak ada yang dihapus.** ID lama dipertahankan; jejaknya tersimpan pada
   `audit_logs` dan kolom `merged_into_wali_id`.

---

## 3. Alur baru pada formulir santri

Untuk masing-masing peran **Ayah**, **Ibu**, dan **Wali lain**, admin memilih:

| Mode | Arti |
| --- | --- |
| `abaikan` (bawaan) | tidak menyentuh relasi yang ada |
| `pilih` | menghubungkan ke wali yang **sudah terdaftar** (lewat pencarian atau daftar) |
| `baru` | membuat identitas wali baru langsung dari formulir yang sama |
| `lepas` | mengarsipkan relasi yang ada |

- Santri, wali baru, dan relasinya disimpan dalam **satu transaksi**.
- **Saudara kandung:** admin memilih ulang ID wali yang sama pada santri kedua.
  Kotak pencarian menampilkan berapa santri yang sudah terhubung ke tiap kandidat,
  supaya admin dapat memastikan keluarganya benar.
- **Pengiriman ulang** (klik ganda, refresh POST, retry jaringan) memakai token
  sekali pakai `ah_form_token('santri')` dan tidak menghasilkan santri, wali,
  atau relasi ganda.
- **Membuat atau memilih wali tidak membuat akun login.** Akun orang tua dibuat
  terpisah pada halaman Akun & Hak Akses.

---

## 4. Strategi kompatibilitas kolom lama

Kolom `santri.nama_ayah`, `no_hp_ayah`, `nama_ibu`, `no_hp_ibu` **tidak dihapus**.

### Satu sumber pengeditan

| Sebelum | Sesudah |
| --- | --- |
| Kolom lama diketik langsung pada formulir santri **dan** identitas wali dikelola terpisah → dua sumber pengeditan yang saling menyimpang | Kolom lama menjadi **cermin satu arah** dari relasi wali yang dikonfirmasi. Ia tidak lagi dapat diketik pada formulir santri. |

Penulisan cermin hanya terjadi lewat `MasterDataService::mirrorParent()`, dipicu
ketika admin menetapkan wali untuk peran Ayah/Ibu.

### Nilai lama yang bertentangan

Bila kolom lama berisi nama yang **berbeda** dari identitas wali yang dipilih:

1. penyimpanan **ditolak** dengan penjelasan yang menyebut kedua nilai;
2. seluruh transaksi di-rollback — kolom lama dan relasi tidak berubah;
3. admin dapat melanjutkan dengan mencentang konfirmasi penggantian;
4. nilai **sebelum dan sesudah** dicatat pada `audit_logs`
   (`action = master.legacy.mirror`) sebagai jejak pemulihan.

Diuji: `tests/perapihan_integration.php` KW-6, KW-7, KW-8.

### Pembaca kolom lama (inventaris)

Kolom lama masih dibaca oleh berkas berikut. Seluruhnya tetap berfungsi karena
kolomnya tidak dihapus dan tidak dikosongkan:

| Berkas | Peran |
| --- | --- |
| `admin/export_santri.php`, `admin/export_master.php` | ekspor CSV santri |
| `admin/export_psb.php`, `admin/proses_import_psb.php`, `admin/proses_psb_admin.php` | alur PSB (di luar cakupan paket ini) |
| `admin/proses_import_santri.php` | impor Excel format lama |
| `admin/proses_mutasi_alumni.php` | mutasi alumni |
| `admin/admin_alumni.php`, `admin/admin_data.php`, `admin/get_santri_json.php` | tampilan dan pencarian |
| `psb.php`, `proses_psb.php`, `portal_pendaftar.php` | website publik PSB |
| `bin/v2_phase3_sandbox_seed.php`, `bin/v2_phase5_fixture.php` | fixture uji |

`saveSantri()` kini hanya menulis kolom lama bila pemanggil **memang
mengirimkannya** (`array_key_exists`). Formulir santri tidak lagi mengirimnya,
sehingga menyimpan santri tidak pernah mengosongkan nilai lama secara tidak
sengaja.

### Perubahan perilaku impor dan PSB

Pemanggil yang tidak mengirim spesifikasi `wali` (impor Excel, alur PSB) **tidak
lagi membuat identitas wali otomatis**. Ini disengaja: pembuatan otomatis per
baris justru melahirkan identitas ganda. Santri hasil impor/PSB muncul pada
halaman **Rekonsiliasi Wali** bagian "relasi belum lengkap" untuk dihubungkan ke
identitas yang benar atas konfirmasi admin.

---

## 5. Halaman Rekonsiliasi Wali

`admin/admin_wali_rekonsiliasi.php` **melaporkan**, tidak memperbaiki sendiri.

| Bagian | Isi | Sumber |
| --- | --- | --- |
| Kandidat duplikasi | kelompok wali aktif dengan nama sama (dinormalisasi) atau nomor HP sama | `waliDuplicateCandidates()` |
| Relasi belum lengkap | santri yang punya nama ayah/ibu pada kolom lama tanpa relasi wali, atau tanpa relasi sama sekali | `santriWithIncompleteWali()` |
| Konflik kolom lama | santri yang kolom lamanya berbeda dari identitas wali terverifikasi | `santriLegacyConflicts()` |
| Wali tanpa relasi | identitas wali aktif tanpa satu pun santri | `waliWithoutRelations()` |

Tidak ada tombol "gabungkan semua". Tidak ada penggabungan massal.

---

## 6. Penggabungan identitas

`MasterDataService::mergeWali($sumber, $tujuan, $aktor, $dikonfirmasi)`.

### Aturan blokir

| Kondisi | Perilaku |
| --- | --- |
| Konfirmasi belum dicentang | **ditolak** |
| Sumber dan tujuan sama, atau salah satunya tidak ada | **ditolak** |
| Tujuan sudah diarsipkan atau sudah digabungkan | **ditolak** |
| Sumber sudah pernah digabungkan | **ditolak** |
| **Kedua sisi memiliki akun login** | **ditolak**, admin diminta menyelesaikan akun lebih dulu |
| **Sisi sumber memiliki akun login** | **ditolak** — penggabungan akan mengubah santri yang dapat dilihat akun orang tua tersebut |

Aturan terakhir menjaga janji "koreksi data tidak memperluas akses akun orang tua
tanpa hubungan yang dikonfirmasi": akses akun tidak pernah berpindah diam-diam
akibat pembersihan data.

### Langkah (satu transaksi)

1. Kunci baris `wali` sumber dan tujuan (`FOR UPDATE`).
2. Untuk tiap relasi aktif sumber:
   - bila tujuan sudah punya relasi aktif ke santri + hubungan yang sama →
     relasi sumber **diarsipkan** (bukan dihapus);
   - selain itu → relasi **dialihkan** ke tujuan, dengan **ID relasi tetap**.
3. Wali sumber: `is_active = 0`, `archived_at` diisi, `merged_into_wali_id` =
   tujuan. **Barisnya tidak dihapus.**
4. Audit `master.wali.merge` menyimpan identitas sebelum, tujuan, jumlah relasi
   yang dipindahkan/diarsipkan, dan daftar ID santri terdampak.

Diuji: `tests/perapihan_integration.php` KW-9 s.d. KW-13.

---

## 7. Perubahan identitas wali bersama

Mengubah nama atau nomor HP wali yang dipakai **lebih dari satu** santri:

1. halaman menampilkan daftar santri terdampak lebih dulu;
2. penyimpanan ditolak sampai kotak konfirmasi dicentang;
3. audit `master.update` menyimpan nilai sebelum/sesudah **dan** daftar ID santri
   terdampak.

Diuji: `tests/perapihan_integration.php` KW-14, KW-15.

---

## 8. Catatan teknis: perbedaan collation

Tabel `santri` warisan V1 memakai `utf8mb4_general_ci`, sedangkan `wali` dibuat
migrasi 002 dengan `utf8mb4_unicode_ci`. Membandingkan nama antar keduanya tanpa
`COLLATE` eksplisit menghasilkan galat "Illegal mix of collations" pada
MySQL/MariaDB. Query deteksi konflik memakai `COLLATE utf8mb4_unicode_ci` pada
kedua sisi perbandingan. **Tidak ada kolom yang diubah collation-nya** — mengubah
collation tabel produksi berada di luar cakupan dan berisiko.

> **Untuk auditor:** ini kondisi nyata pada skema produksi, bukan artefak
> sandbox. Bila kelak ada rencana menyeragamkan collation, itu harus menjadi
> keputusan dan migrasi tersendiri.
