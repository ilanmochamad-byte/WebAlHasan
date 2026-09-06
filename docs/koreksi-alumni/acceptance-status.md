# Status kriteria penerimaan: Koreksi Pengelolaan Alumni

Keputusan pengguna 6 September 2026. Branch `feat/koreksi-alumni`.

---

## 1. Kriteria penerimaan

| # | Kriteria | Status | Bukti |
| --- | --- | --- | --- |
| 1 | Pemindahan individual dapat dilakukan dari Master Data Santri | **TERPENUHI** | tombol per baris santri aktif → `admin_kelulusan_santri.php?santri_id=N`; AS-18, AW-14, AW-8 |
| 2 | Pemindahan massal dapat dilakukan berdasarkan kelas | **TERPENUHI** | AL-4, AW-10 |
| 3 | Tidak ada alumni ganda | **TERPENUHI** | dua lapis: aplikasi (AL-10) + kunci unik basis data (AL-11); permintaan paralel nyata KA-1…KA-3 |
| 4 | Santri sumber tidak dihapus | **TERPENUHI** | AL-7, AW-8g; `AlumniRepository` tidak memuat satu pun `DELETE FROM santri` (AS-12) |
| 5 | Penempatan kelas dan kamar aktif ditutup konsisten | **TERPENUHI** | AL-5, AL-6, AW-10d |
| 6 | Riwayat kelas, kamar, wali, akun, dan operasional tetap tersimpan | **TERPENUHI** | AL-8, AL-9, AL-18 |
| 7 | Proses individual dan massal bersifat transaksional | **TERPENUHI** | AL-12, AL-13, AL-19 (tiga jalur kegagalan berbeda, semuanya rollback penuh); KA-3c |
| 8 | Tidak ada perubahan data melalui GET | **TERPENUHI** | AS-3, AS-9, AW-5, AW-6, AW-7 |
| 9 | Penghapusan permanen diganti arsip/pemulihan yang aman | **TERPENUHI** | AS-2, AL-14, AW-11; `?hapus=ID` dijawab 405 |
| 10 | Seluruh perubahan penting tercatat pada audit | **TERPENUHI** | 9 aksi audit (AS-11); audit gagal = rollback (AL-19) |
| 11 | Data alumni lama tetap terbaca | **TERPENUHI** | AL-17, AW-12g |
| 12 | Halaman memakai navigasi dan desain admin yang konsisten | **TERPENUHI** | AS-6, AW-3d…AW-3i |
| 13 | Pengujian yang dapat dijalankan telah lulus | **TERPENUHI** | 359 pemeriksaan paket ini + seluruh regresi; lihat `test-results.md` |
| 14 | Pengujian yang belum dapat dijalankan dilaporkan terbuka | **TERPENUHI** | `test-results.md` §6 dan §7 |
| 15 | Branch di-push tanpa merge atau deployment | **TERPENUHI** | tidak ada merge ke `main`; tidak ada migrasi produksi yang dijalankan |

## 2. Batasan yang harus diketahui sebelum menyatakan fitur selesai

Paket ini **belum boleh dinyatakan selesai untuk produksi** sampai butir berikut
diselesaikan operator manusia:

| Hal | Status | Siapa |
| --- | --- | --- |
| Migrasi 011 pada salinan `_test` dari **data produksi sungguhan** | **MEMERLUKAN UJI STAGING** | operator |
| `bin/alumni_preflight.php` pada data produksi (bagian 1 wajib kosong) | **MEMERLUKAN UJI STAGING** | operator |
| Perilaku terhadap data alumni warisan sungguhan | **BELUM PERNAH DILIHAT** — tabel `alumni` pada dump repositori kosong | operator |
| Smoke test pasca-deploy | **MEMERLUKAN UJI PRODUKSI** | operator, `cpanel-deployment.md` §5 |
| Uji peramban 1440/768/390 px | **BELUM DIJALANKAN** | — |
| Safari fisik dan pembaca layar nyata | **BELUM DIJALANKAN** | — |
| Rollback pada tabel ber-NIS ganda | **BELUM DIJALANKAN** | — |
| Pemrosesan massal 200 santri sekaligus (beban nyata) | **BELUM DIJALANKAN** | — |

## 3. Risiko dan pekerjaan yang masih terbuka

### 3.1 Pelepasan kamar tetap berupa penghapusan baris

`plotting_kamar` warisan V1 tidak punya kolom status, sehingga “keluar dari
kamar” diwakili dengan menghapus baris tahun berjalan — sama seperti yang sudah
dilakukan paket penempatan. Nilai sebelumnya disimpan pada
`alumni.kamar_terakhir` dan `audit_logs`, dan klausa `id_tahun` menjaga baris
tahun lain, tetapi ini **satu-satunya penghapusan baris** di seluruh paket.

*Perbaikan yang mungkin:* menambah kolom status pada `plotting_kamar`. Itu
keputusan lintas-modul (menyentuh penempatan, laporan, dan murobi) dan berada di
luar cakupan paket alumni.

### 3.2 `UNIQUE KEY nis` lama dilepas

Diganti `alumni_nis_aktif_unique` agar NIS tidak terkunci selamanya oleh baris
arsip. Keunikan untuk data **aktif** tidak berubah. Konsekuensinya: rollback
migrasi 011 dapat berakhir tanpa kunci unik NIS bila sudah ada baris arsip
ber-NIS sama. Ditangani dan didokumentasikan di `migrasi-dan-rollback.md` §8,
tetapi jalur itu **belum pernah dijalankan**.

### 3.3 Kunci asing `alumni_santri_fk` belum diuji pada data produksi

Migrasi memasang FK dari `alumni.santri_id` ke `santri.id`. Saat migrasi
dijalankan, seluruh `santri_id` masih NULL sehingga FK pasti terpasang. Risiko
muncul kemudian: bila ada yang mengisi `santri_id` secara manual lewat
phpMyAdmin dengan ID yang tidak ada, penulisan akan ditolak. Itu perilaku yang
diinginkan, tetapi perlu diketahui.

### 3.4 Data warisan tidak dapat dibatalkan kelulusannya

Catatan alumni tanpa `santri_id` tidak dapat memakai tindakan “Batalkan
kelulusan” — tidak ada santri yang dapat diaktifkan kembali. Pesannya jelas dan
mengarahkan admin menghubungkan catatan itu lebih dahulu. Ini disengaja, bukan
cacat.

### 3.5 Halaman kelulusan belum menyediakan pemilihan santri lintas kelas

Alur massal hanya berdasarkan **kelas aktif**, sesuai permintaan. Memproses
sekumpulan santri dari beberapa kelas sekaligus belum tersedia; lakukan per
kelas. Layanan sendiri sudah menerima daftar ID bebas
(`AlumniService::terapkan()`), sehingga penambahannya kelak tidak memerlukan
perubahan transaksi.

### 3.6 Tiga kegagalan `tests/phase5_static.php`

Memeriksa berkas repositori `alhasanApps` yang tidak ter-checkout di mesin
pengembangan. Bukan akibat paket ini; tidak ada berkas mobile yang disentuh.
Lihat `test-results.md` §4.

### 3.7 Satu assertion regresi diubah dengan sengaja

`tests/penempatan_static.php` PS-15 sebelumnya mematok jumlah berkas migrasi
seluruh repositori pada angka 10, sehingga migrasi baru yang sah dilaporkan
sebagai kegagalan paket penempatan. Assertion diubah menjadi maksud aslinya.
Alasan lengkapnya ada pada komentar di berkas itu dan pada `test-results.md` §4.
