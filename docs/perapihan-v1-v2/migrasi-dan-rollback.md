# Migrasi 010 dan prosedur rollback

Paket "Koreksi dan Modernisasi UI/UX V1–V2" — keputusan pengguna 30 Agustus 2026.

---

## 1. Ringkas

| | |
| --- | --- |
| Migrasi | `database/migrations/010_perapihan_rekonsiliasi_wali.sql` |
| Rollback | `database/rollbacks/010_perapihan_rekonsiliasi_wali.sql` |
| Sifat | **aditif dan idempoten** |
| Perubahan | `wali.merged_into_wali_id INT NULL` + indeks `wali_merged_into_index` + indeks `wali_no_hp_index` |
| Data yang disentuh | **tidak ada** |
| Status produksi | **BELUM DIJALANKAN.** Menunggu audit Codex dan instruksi pengguna. |

Tidak ada `DROP TABLE`, `DROP COLUMN`, `DELETE`, `TRUNCATE`, atau `UPDATE` data.
Seluruh pernyataan dibungkus pemeriksaan `INFORMATION_SCHEMA` sehingga aman
dijalankan ulang.

`wali_no_hp_index` adalah indeks **pencarian**, bukan kunci unik: nomor HP
sengaja boleh dipakai bersama beberapa wali (keputusan pengguna).

---

## 2. Mengapa kolom ini diperlukan

Penggabungan identitas wali tidak pernah menghapus baris. Wali sumber tetap ada
dengan ID lamanya dan diarsipkan. Tanpa penanda, hubungan "identitas ini
digabungkan ke #X" hanya hidup di `audit_logs` dan tidak dapat ditampilkan pada
halaman wali maupun dipakai untuk menyaring kandidat pencarian.

Kolom ini juga memastikan wali yang sudah digabungkan **tidak muncul lagi**
sebagai kandidat pada formulir santri (`waliSearch`, `waliActiveFind`).

---

## 3. Prosedur di lingkungan uji

```bash
# 1. Pastikan target adalah database *_test
php bin/migrate.php status | tail -5

# 2. Jalankan
php bin/migrate.php up

# 3. Verifikasi kolom dan indeks
mariadb "$DB_NAME" -e "SHOW COLUMNS FROM wali LIKE 'merged_into_wali_id';
                       SHOW INDEX FROM wali WHERE Key_name LIKE 'wali_%index';"

# 4. Idempotensi: jalankan ulang berkas migrasi langsung, harus tanpa galat
mariadb "$DB_NAME" < database/migrations/010_perapihan_rekonsiliasi_wali.sql
```

Diverifikasi pada sandbox: migrasi berjalan, kolom dan dua indeks terbentuk,
pengulangan tidak menghasilkan galat.

---

## 4. Prosedur produksi (BELUM DIJALANKAN — untuk pelaksana manusia)

> Jangan menjalankan langkah ini sebelum paket lolos audit Codex dan pengguna
> memberi instruksi terpisah.

1. **Preflight dan backup.** Jalankan prosedur backup yang sudah ada
   (`bin/v2_phase5_preflight.php`) dan simpan manifest jumlah baris seluruh tabel.
2. **Uji pada salinan.** Pulihkan backup ke database berakhiran `_test`,
   jalankan `php bin/migrate.php up`, lalu bandingkan jumlah baris tabel inti
   dengan manifest.
3. **Smoke test pada salinan.** Buka halaman Rekonsiliasi Wali, formulir santri,
   dan detail santri. Pastikan tidak ada galat dan jumlah data tidak berubah.
4. **Jalankan pada produksi** pada jendela pemeliharaan.
5. **Verifikasi.** Kolom dan indeks ada; `SELECT COUNT(*) FROM wali` dan
   `santri_wali` sama persis dengan sebelum migrasi.

### Pemeriksaan ID, jumlah, dan relasi

```sql
-- Sebelum dan sesudah harus identik
SELECT COUNT(*) AS wali, MIN(id) AS id_min, MAX(id) AS id_maks FROM wali;
SELECT COUNT(*) AS relasi FROM santri_wali;
SELECT COUNT(*) AS relasi_aktif FROM santri_wali WHERE archived_at IS NULL;
SELECT COUNT(*) AS santri FROM santri;

-- Setelah migrasi, belum boleh ada satu pun penggabungan
SELECT COUNT(*) AS sudah_digabung FROM wali WHERE merged_into_wali_id IS NOT NULL;  -- harus 0
```

---

## 5. Rollback

```bash
mariadb "$DB_NAME" < database/rollbacks/010_perapihan_rekonsiliasi_wali.sql
```

Melepas dua indeks dan satu kolom. **Tidak menyentuh** baris `wali`,
`santri_wali`, kolom lama `santri.nama_ayah/ibu`, maupun `audit_logs`.

### Kehilangan data yang disengaja

Setelah rollback, penanda "identitas ini digabungkan ke #X" hilang dari skema.
Wali sumber tetap ada dengan ID aslinya dan tetap berstatus arsip; hubungan
penggabungannya hanya dapat ditelusuri lewat `audit_logs`
(`action = master.wali.merge`), yang **tidak** dihapus.

Bila rollback dilakukan setelah beberapa penggabungan sudah dijalankan, halaman
wali tidak lagi menampilkan "Digabungkan ke #X", dan wali yang sudah digabungkan
dapat kembali muncul sebagai kandidat pencarian (karena penyaringnya memakai
kolom ini). Statusnya yang tetap arsip masih menahannya dari daftar aktif.

---

## 6. Rollback paket tanpa menyentuh basis data

Enam koreksi lain (1, 3, 4, 5, 6, 7) **tidak memerlukan perubahan skema sama
sekali**. Mengembalikan kode ke `c65390d` sudah memulihkan seluruh perilaku lama;
migrasi 010 boleh dibiarkan terpasang karena kode lama tidak membaca kolomnya.
