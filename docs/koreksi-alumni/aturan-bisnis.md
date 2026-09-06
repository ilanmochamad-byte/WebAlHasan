# Aturan bisnis: Koreksi Pengelolaan Alumni

Keputusan pengguna 6 September 2026. Branch `feat/koreksi-alumni`.

---

## 1. Tujuan fitur alumni

Halaman alumni adalah **arsip santri yang sudah keluar** dari pesantren, dengan
tiga sebab:

| Status keluar | Arti |
| --- | --- |
| `Lulus` | menyelesaikan jenjang secara normal |
| `Pindah` | mutasi keluar, biasanya ikut orang tua atau pindah lembaga |
| `Berhenti` | berhenti atau dikeluarkan |

Tidak ada status baru yang ditambahkan. Ketiganya sama persis dengan ENUM kolom
`alumni.status_keluar` yang sudah ada sejak V1, dan dijaga konstanta
`AlumniService::STATUS`.

Tingkat terakhir juga mengikuti ENUM yang sudah ada: `Ibtida` dan `Tsanawi`
(`AlumniService::TINGKAT`).

### Masalah yang diperbaiki paket ini

Sebelum paket ini:

1. **Alurnya tidak dapat dipakai.** Halaman Master Data Santri sudah tidak lagi
   memiliki tombol maupun formulir pemindahan santri. `admin/admin_alumni.php`
   dan `admin/proses_mutasi_alumni.php` masih hidup, tetapi tidak ada satu pun
   tautan menuju ke sana — arsip alumni tidak dapat diisi lewat antarmuka.
2. **Pemrosesan massal tidak transaksional.** `proses_mutasi_alumni.php`
   memakai `INSERT IGNORE` di dalam perulangan tanpa transaksi. Pelanggaran
   keunikan ditelan diam-diam: santri tetap diarsipkan dan kelasnya tetap
   ditutup meskipun catatan alumninya **tidak pernah tersimpan**.
3. **Kamar tidak pernah ditutup.** Tempat tidur alumni tetap terpakai.
4. **Tidak ada jejak.** Tidak ada pelaku, waktu, kelas/kamar terakhir, catatan,
   maupun audit.
5. **Penghapusan permanen lewat GET.** `admin_alumni.php?hapus=ID` menghapus
   baris alumni **dan berkas fotonya** dari disk — tanpa CSRF, tanpa alasan,
   tanpa audit, tanpa kemungkinan pemulihan.
6. **SQL disusun dari `$_GET` langsung** dan keluaran tidak di-escape.

---

## 2. Alur individual

Dimulai dari tombol **“Luluskan / Mutasi keluar”** pada baris santri di
`admin/admin_master_santri.php`. Tombol hanya muncul untuk santri yang
`is_active = 1` dan `archived_at IS NULL`.

1. Admin membuka `admin/admin_kelulusan_santri.php?santri_id=N`.
2. Halaman menampilkan ringkasan santri: NIS, nama, L/P, unit sekolah,
   **kelas aktif**, dan **kamar aktif** pada semester berjalan.
3. Bila santri itu sudah menjadi alumni, ia muncul pada tabel
   **“Dikecualikan dari proses”** beserta alasannya dan tautan ke catatan
   alumninya. Formulir tidak ditawarkan dan tidak ada pemrosesan ulang.
4. Admin mengisi: status keluar, tanggal keluar, tahun angkatan/tahun keluar,
   tingkat terakhir, dan catatan (opsional).
5. Tombol **“Tinjau sebelum memproses”** menampilkan layar konfirmasi berisi
   seluruh nilai yang akan disimpan dan daftar santri yang terpengaruh.
   Layar ini **tidak mengubah apa pun**.
6. Tombol **“Proses N santri menjadi alumni”** menjalankan transaksi.
7. Setelah berhasil, admin diarahkan ke **detail catatan alumni** yang baru
   dibuat (`admin_alumni.php?action=detail&id=…`) sehingga hasilnya dapat
   langsung diverifikasi.

## 3. Alur massal

1. Admin membuka `admin/admin_kelulusan_santri.php` dan memilih **kelas** pada
   semester aktif. Dropdown menyebut jumlah santri aktif tiap kelas.
2. Sistem menampilkan seluruh santri aktif kelas itu: NIS, nama, L/P, unit,
   kelas aktif, kamar aktif — beserta jumlahnya.
3. Santri yang sudah menjadi alumni **dikecualikan secara terbuka** pada tabel
   terpisah, lengkap dengan alasan dan tautan ke catatan alumninya. Ia tidak
   ikut terkirim ke server, sehingga tidak ada duplikasi diam-diam.
4. Admin mengisi status, tanggal, tahun, tingkat, dan catatan — sama seperti
   alur individual.
5. Layar konfirmasi memuat peringatan tegas:
   *“Proses ini memengaruhi SELURUH N santri di bawah ini sekaligus.”*
6. Seluruh santri diproses dalam **satu transaksi**. Bila satu gagal
   divalidasi, seluruh operasi dibatalkan dan penyebabnya ditampilkan.
7. Batas satu operasi massal: **200 santri** (`AlumniService::BATAS_MASSAL`),
   agar transaksi tidak memegang kunci terlalu lama pada hosting bersama.

---

## 4. Perubahan status santri

Ketika santri dipindahkan menjadi alumni:

| Yang terjadi | Yang TIDAK terjadi |
| --- | --- |
| `santri.is_active = 0` dan `santri.archived_at` terisi | baris `santri` **tidak** dihapus |
| Santri hilang dari daftar operasional santri aktif | data historisnya tetap dapat ditemukan lewat filter “Arsip” dan halaman alumni |
| Penempatan kelas aktif ditutup (`status = 'Selesai'`, `tanggal_selesai` = tanggal keluar) | baris `plotting_kelas` **tidak** dihapus |
| Penempatan kamar semester berjalan dilepas | baris kamar **tahun ajaran lain tidak disentuh** |
| — | relasi wali (`santri_wali`) tidak dihapus dan tidak diarsipkan |
| — | identitas wali (`wali`) tidak diubah |
| — | akun wali (`users.wali_id`) **tidak** dinonaktifkan otomatis |
| — | absensi, perizinan, konseling, penilaian, dan pembiayaan tidak disentuh |

Pola statusnya sama persis dengan yang sudah dipakai
`MasterDataService::setSantriState()` untuk seluruh master data lain.

## 5. Penanganan kelas dan kamar

**Kelas.** `plotting_kelas` punya kolom `status`, sehingga penutupan cukup
mengubah barisnya menjadi `Selesai` dengan `tanggal_selesai` = tanggal keluar.
Barisnya tetap ada sebagai riwayat dan tetap tampil pada “Riwayat keanggotaan
kelas” di detail santri.

**Kamar.** `plotting_kamar` warisan V1 **tidak punya kolom status**, sehingga
“keluar dari kamar” hanya dapat diwakili dengan menghapus baris tahun berjalan
— persis seperti yang sudah dilakukan
`PenempatanRepository::releaseRoomAssignment()` sejak paket penempatan. Nilai
sebelum penghapusan **tidak hilang**: nama kamar disimpan pada
`alumni.kamar_terakhir` dan pada `audit_logs`. Klausa `id_tahun` memastikan
baris tahun ajaran lain tidak pernah tersentuh.

> **Catatan jujur.** Ini satu-satunya tempat di seluruh paket ini yang menghapus
> baris. Alternatifnya adalah menambah kolom status pada `plotting_kamar`, dan
> itu keputusan lintas-modul yang berada di luar cakupan paket alumni.

## 6. Pencegahan duplikasi

Duplikasi dicegah pada **dua lapis**, dengan identitas berupa **ID santri** —
bukan kesamaan nama santri, nama ayah, atau nama ibu.

**Lapis 1 — aplikasi, di dalam transaksi terkunci.**
`AlumniService::terapkan()` mengunci baris santri (`FOR UPDATE`, ID menaik),
lalu mengunci catatan alumni aktif milik santri itu dan catatan alumni aktif
ber-NIS sama. Baru setelah itu ia memutuskan.

**Lapis 2 — basis data (migrasi 011).**

```sql
UNIQUE KEY alumni_santri_aktif_unique (santri_aktif_guard)
UNIQUE KEY alumni_nis_aktif_unique    (nis_aktif_guard)
```

`santri_aktif_guard` bernilai `santri_id` selama `archived_at IS NULL`, dan
NULL setelah diarsipkan. `nis_aktif_guard` bekerja sama untuk NIS. Karena NULL
boleh berulang pada kunci unik MySQL:

- baris warisan tanpa `santri_id` tidak saling bertabrakan;
- baris yang sudah diarsipkan tidak mengunci NIS-nya selamanya, sehingga santri
  yang kelulusannya dibatalkan **dapat diproses ulang**.

**Klik ganda, refresh POST, dan retry** ditangani token sekali pakai
(`ah_form_token` / `ah_form_token_consume`) pada layar konfirmasi, ditambah pola
POST-redirect-GET. Permintaan yang benar-benar bersamaan ditangani lapis 1 dan
2; `tests/alumni_concurrency.php` membuktikannya dengan proses PHP paralel yang
nyata.

## 7. Audit

Memakai `audit_logs` yang sudah ada — **tidak ada sistem audit kedua**. Seluruh
penulisan melewati satu jalur `AlumniService::wajibTercatat()` yang memeriksa
nilai baliknya: **audit gagal = seluruh transaksi di-rollback**.

| Aksi | Kapan |
| --- | --- |
| `alumni.proses` | satu santri diproses menjadi alumni (individual maupun tiap baris massal) |
| `alumni.massal` | ringkasan satu operasi massal: jumlah santri, daftar ID, alasan |
| `alumni.koreksi` | isi catatan alumni dikoreksi |
| `alumni.arsip` | catatan alumni diarsipkan, beserta alasannya |
| `alumni.pulihkan` | catatan alumni dipulihkan, beserta alasannya |
| `alumni.batalkan` | kelulusan/mutasi dibatalkan |
| `alumni.batalkan.santri` | santri diaktifkan kembali akibat pembatalan |
| `alumni.hubungkan` | catatan warisan dipasangkan ke santri sumber |
| `alumni.backfill` | pemasangan otomatis oleh `bin/alumni_backfill.php` |

Setiap catatan memuat pelaku (`actor_user_id`), waktu, nilai **sebelum**, nilai
**sesudah**, dan alasan bila ada.

## 8. Koreksi, arsip, pemulihan, dan pembatalan

**Tidak ada penghapusan permanen.** Alamat lama `?hapus=ID` dijawab **405** dan
tidak menghapus apa pun; pesannya menunjuk ke tindakan pengganti.

| Tindakan | Efek pada catatan alumni | Efek pada santri | Alasan |
| --- | --- | --- | --- |
| **Koreksi** | mengubah status/tanggal/tahun/tingkat/unit/kelas/kamar/catatan | tidak ada | catatan (opsional) |
| **Arsipkan** | `archived_at` terisi, `jenis_arsip = 'arsip'` | **tidak ada** | wajib, min. 5 karakter |
| **Pulihkan** | `archived_at` dikosongkan | **tidak ada** | wajib, min. 5 karakter |
| **Batalkan** | `archived_at` terisi, `jenis_arsip = 'pembatalan'` | **diaktifkan kembali** | wajib, min. 5 karakter |

Aturan yang dipegang:

- Memulihkan catatan arsip **tidak** otomatis mengaktifkan kembali santri,
  kelas, atau kamar. Itu keputusan terpisah.
- Pembatalan **tidak** membuat penempatan kelas atau kamar baru. Admin
  menentukannya sendiri lewat halaman Penempatan Kelas & Kamar.
- Pembatalan memeriksa konflik lebih dahulu: bila santrinya sudah aktif, ia
  ditolak dan diarahkan memakai “Arsipkan”.
- Pemulihan memeriksa konflik: bila santri atau NIS itu sudah punya catatan
  alumni aktif lain, pemulihan ditolak dengan pesan yang menyebut ID catatan
  yang bentrok.
- Identitas, NIS, alamat, snapshot orang tua, dan **foto** tidak pernah diubah
  oleh koreksi — semuanya rekaman keadaan saat santri keluar.
- **Berkas foto fisik tidak pernah dihapus** oleh tindakan apa pun.

## 9. Penanganan data alumni lama

Baris `alumni` warisan tidak menyimpan referensi santri. Paket ini:

- **menampilkannya apa adanya**, lengkap dengan foto dan snapshot identitasnya;
- menandainya “Tanpa referensi santri” pada daftar dan detail;
- menyediakan filter **“Belum terhubung (data warisan)”**;
- melaporkan jumlahnya pada kartu ringkasan dan pada catatan peringatan;
- **tidak menebak pasangannya**. `bin/alumni_backfill.php` hanya memasangkan
  bila NIS cocok **persis satu** santri **dan** dipakai **persis satu** catatan
  alumni **dan** santri itu belum punya catatan alumni aktif. Sisanya
  dilaporkan sebagai AMBIGU dan dibiarkan;
- menyediakan tindakan manual **“Hubungkan ke santri sumber”** pada halaman
  detail, atas konfirmasi admin, dan tercatat pada audit.

Filter lama (tahun angkatan, tingkat, status keluar, pencarian nama/NIS) tetap
berfungsi persis seperti sebelumnya.

## 10. Keamanan

- Seluruh halaman melewati `admin/_guard.php` → `requireWebRole('admin')`.
  Menyembunyikan menu **bukan** kontrol akses; akun guru yang membuka alamatnya
  langsung dijawab **403** (dibuktikan `tests/alumni_web_smoke.php` AW-2).
- Seluruh perubahan data lewat **POST**. Tidak ada satu pun tautan GET yang
  mengubah data.
- CSRF diperiksa `_guard.php` untuk setiap POST; token palsu dijawab **419**.
- Seluruh SQL memakai prepared statement. Nama kolom pencarian berasal dari
  konstanta `AlumniRepository::KOLOM_CARI`, bukan dari URL.
- Seluruh keluaran HTML di-escape dengan `master_e()`.
- ID, tanggal, status, tahun, dan tingkat divalidasi ulang di server. Nilai
  dropdown dan hidden field **tidak dipercaya**.
- Galat basis data tidak pernah dikirim mentah ke layar: pesan MySQL dapat
  memuat nilai kolom. Detailnya hanya masuk `error_log` server.
