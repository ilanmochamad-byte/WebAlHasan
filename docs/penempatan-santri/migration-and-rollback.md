# Migrasi dan rollback: Penempatan Kelas & Kamar Santri

Keputusan pengguna 6 September 2026.

---

## 1. Kesimpulan: **TIDAK ADA MIGRASI**

Paket ini **tidak menambah, mengubah, atau menghapus satu pun objek basis data**.

- Jumlah berkas migrasi tetap **10** (`database/migrations/001…010`), berpasangan
  dengan **10** berkas rollback (`database/rollbacks/001…010`).
- Tidak ada `CREATE`, `ALTER`, `DROP`, `TRUNCATE`, indeks baru, constraint baru,
  maupun koreksi data otomatis.
- **Deployment paket ini hanya membutuhkan pembaruan kode.** Tidak ada langkah
  migrasi yang perlu dijalankan di cPanel.

Pemeriksaan statis `tests/penempatan_static.php` (PS-15) menegakkan hal ini:
jumlah berkas migrasi diperiksa dan harus tetap 10.

## 2. Mengapa tanpa migrasi

Instruksi pengguna: utamakan implementasi tanpa perubahan skema **jika** seluruh
aturan integritas, audit, dan transaksi dapat dipenuhi dengan struktur saat ini.
Ternyata dapat:

| Aturan | Cara dipenuhi tanpa skema baru |
|--------|-------------------------------|
| Satu kelas aktif per santri per tahun | sudah ada: `UNIQUE plotting_kelas_one_active_unique (id_santri, active_year_guard)` sejak migrasi 002 |
| Satu kamar per santri per tahun | baris `santri` dikunci `FOR UPDATE` sebelum baca-tulis, sehingga dua permintaan untuk santri yang sama tidak dapat berjalan bersamaan |
| Kapasitas kamar tidak terlampaui | baris `kamar` dikunci `FOR UPDATE`, penghuni dihitung ulang setelah kunci, transaksi berjalan pada READ COMMITTED |
| Atomisitas operasi massal | satu transaksi untuk seluruh santri, rollback penuh pada kegagalan mana pun |
| Audit atomik | `audit_logs` (InnoDB) ditulis dalam transaksi yang sama; kegagalan audit memicu rollback |
| Riwayat kelas | model V1 sudah menyimpan riwayat; jalur tulis terpusat dipakai apa adanya |

Bukti perilaku ini ada pada `tests/penempatan_integration.php` (53 pemeriksaan)
dan `tests/penempatan_concurrency.php` (10 pemeriksaan, proses PHP nyata).

## 3. Batas kejujuran: apa yang TIDAK dijamin tanpa migrasi

Perlindungan "satu kamar per santri per tahun" bekerja **selama seluruh penulisan
melewati `PenempatanService`**. Basis data belum menegakkannya sendiri karena
`plotting_kamar` belum punya constraint unik. Konsekuensinya:

- penulisan langsung ke tabel (skrip manual, impor, phpMyAdmin) masih dapat
  membuat baris ganda;
- data produksi yang **sudah** ganda sejak sebelum paket ini tetap ganda. Sistem
  melaporkannya dan **menolak** bekerja pada santri tersebut, bukan
  memperbaikinya diam-diam.

Ini risiko residual yang disengaja dan dicatat pada `acceptance-status.md`.

## 4. Bila kelak constraint unik hendak ditambahkan

Constraint `UNIQUE plotting_kamar_one_room_per_year (id_santri, id_tahun)` adalah
langkah lanjutan yang wajar, tetapi **hanya boleh dipasang setelah preflight
membuktikan tidak ada konflik**. Prosedurnya:

```bash
# 1. Laporan konflik (hanya membaca; tidak memperbaiki apa pun)
php bin/penempatan_preflight.php
```

Bagian yang harus **kosong** sebelum constraint dipasang:

1. Santri dengan lebih dari satu kamar pada tahun ajaran yang sama.
2. Relasi yatim pada `plotting_kamar` (menunjuk santri/kamar/tahun yang hilang).
5. Santri dengan lebih dari satu kelas berstatus `Aktif` pada tahun yang sama.

Bagian 3 (kamar melebihi kapasitas) dan bagian 4 (tahun ajaran aktif) adalah
informasi operasional: kamar yang sudah kelebihan penghuni sejak sebelumnya tidak
menghalangi constraint keunikan, tetapi harus diputuskan admin karena penempatan
baru ke kamar itu akan selalu ditolak.

Bila seluruh bagian yang disyaratkan kosong, migrasi berikutnya adalah **011**,
mengikuti konvensi yang sudah ada: aditif, idempoten (dibungkus pemeriksaan
`INFORMATION_SCHEMA`), tanpa `DROP`/`TRUNCATE`/koreksi data, dan **berpasangan**
dengan `database/rollbacks/011_*.sql`. Dokumentasikan pula dampaknya terhadap
routing murobi, pembimbing, laporan, dan perizinan V2 sebelum dijalankan.

**Paket ini sengaja tidak membuat migrasi 011.** Menambah constraint unik pada
tabel produksi yang belum diperiksa adalah perubahan yang dapat menggagalkan
deployment di tengah jalan; keputusannya diserahkan kepada pengguna setelah
melihat laporan preflight dari data produksi.

## 5. Dampak terhadap modul lain (bila migrasi kelak dilakukan)

| Modul | Dampak yang harus diperiksa |
|-------|-----------------------------|
| Routing murobi (`App\Izin\IzinRouter`) | join `plotting_kamar` tidak memfilter status. Bila kelak kolom status ditambahkan ke `plotting_kamar`, router **wajib** ikut diperbarui, jika tidak penghuni lama ikut menjadi kandidat dan memicu `Perlu Penetapan Admin` palsu. Regresinya `tests/v2_phase2_*`. |
| Pembimbing (pengurus) | cakupan santri berasal dari `plotting_kamar`/`plotting_kelas`; constraint unik tidak mengubah bacanya |
| Laporan perizinan V2 | membaca penempatan aktif; tidak berubah oleh constraint |
| Absensi / pertemuan | memakai snapshot; tidak terpengaruh |

## 6. Rollback

**Rollback kode saja.** Karena tidak ada perubahan skema, memulihkan versi
sebelumnya cukup dengan mengembalikan kode ke commit sebelum paket ini
(`3b53c1c`). Basis data tidak perlu disentuh.

Setelah rollback kode:

- alamat lama `admin/admin_santri.php` kembali menjadi halaman penempatan lama
  dan berfungsi seperti sebelumnya;
- `admin/admin_penempatan_santri.php` menjadi 404 — bookmark ke halaman baru
  perlu diberi tahu;
- **data penempatan yang sudah dibuat lewat halaman baru tetap ada** dan tetap
  terbaca halaman lama: strukturnya sama persis;
- catatan `audit_logs` dengan `action` berawalan `penempatan.` tetap tersimpan
  dan tetap terbaca — audit tidak pernah dihapus oleh rollback kode.

**Kehilangan data yang disengaja: tidak ada.** Rollback paket ini tidak
menghapus baris apa pun.

## 7. Verifikasi sebelum dan sesudah deployment

Jalankan sebelum dan sesudah rilis; hasilnya harus **identik** (kecuali bila admin
memang melakukan penempatan di antaranya):

```sql
SELECT COUNT(*) AS penempatan_kamar, MIN(id) AS id_min, MAX(id) AS id_maks FROM plotting_kamar;
SELECT COUNT(*) AS penempatan_kelas FROM plotting_kelas;
SELECT COUNT(*) AS kelas_aktif FROM plotting_kelas WHERE status = 'Aktif';
SELECT COUNT(*) AS santri FROM santri;
SELECT COUNT(*) AS kamar FROM kamar;
SELECT COUNT(*) AS peserta_pertemuan FROM pertemuan_peserta;
```

Dan laporan konflik:

```bash
php bin/penempatan_preflight.php   # kode keluar 0 = tidak ada konflik
```
