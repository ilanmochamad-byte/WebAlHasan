# V2 Fase 5 — Backup, Manifest, dan Latihan Restore

Kriteria penerimaan yang dilayani dokumen ini:

- *"Backup dipulihkan pada database `_test` dan seluruh jumlah baris inti cocok
  dengan manifest."*
- *"Jumlah dan ID perizinan lama sama sebelum/sesudah migrasi."*

## 1. Manifest berisi apa

`bin/v2_phase5_preflight.php` menghasilkan tiga berkas dalam satu folder
bertanda waktu di bawah `storage/backups/v2-phase5/`:

| Berkas | Isi |
| --- | --- |
| `database.sql` | Backup lengkap seluruh tabel (struktur + data) |
| `manifest.json` | Jumlah baris per tabel, daftar **seluruh** ID `perizinan`, sidik jari nilai bisnis, dan ringkasan `izin_pengajuan`/`izin_keputusan` |
| `inventory.json` | Inventaris kolom `notifikasi_outbox`, `izin_pengajuan`, `izin_keputusan` **sebelum** migrasi |
| `conflicts.json` | Daftar konflik yang memblokir dan peringatan |

### Sidik jari nilai bisnis

Manifest tidak hanya menyimpan jumlah baris. Ia menyimpan hash SHA-256 dari
gabungan seluruh baris `perizinan`, kolom demi kolom:

```
sha256( "id|id_santri|tgl_izin|tgl_kembali|alasan|status" per baris, diurutkan id )
```

Ini penting: jumlah baris yang sama **tidak** membuktikan nilainya tidak
berubah. Dengan sidik jari, satu huruf yang berubah pada satu kolom `alasan`
pun akan terdeteksi verifikasi pasca-migrasi.

## 2. Prosedur produksi (urutan wajib)

```bash
# 1. Preflight — backup + manifest + laporan konflik. TIDAK mengubah apa pun.
php bin/v2_phase5_preflight.php
#    keluar 0 = aman; 3 = ada konflik yang memblokir; 2 = kesalahan lingkungan

# 2. Uji pemulihan pada salinan _test SEBELUM menyentuh produksi
mysql -u USER -p nama_db_test < storage/backups/v2-phase5/<stamp>/database.sql
php bin/verify_restore.php storage/backups/v2-phase5/<stamp>/manifest.json

# 3. Migrasi
php bin/migrate.php up      # menerapkan 009
php bin/migrate.php status

# 4. Verifikasi — membandingkan keadaan sekarang dengan manifest
php bin/v2_phase5_verify.php storage/backups/v2-phase5/<stamp>/manifest.json
#    keluar 0 = lulus; 3 = HENTIKAN RILIS dan gunakan rollback
```

Langkah 2 **tidak boleh** dilewati. Backup yang belum pernah dipulihkan bukan
backup; ia baru menjadi backup setelah terbukti dapat dipulihkan.

## 3. Yang diperiksa preflight (memblokir)

| Pemeriksaan | Mengapa memblokir |
| --- | --- |
| Seluruh 24 tabel prasyarat Fase 1–4 ada | Migrasi 009 mengandaikan fondasi lengkap |
| Setiap baris `perizinan` sudah termigrasi ke `izin_pengajuan` | Prasyarat klaim "ID tidak berubah" |
| ID pengajuan warisan **tidak bergeser** dari ID `perizinan` asal | Inti PRD 5.5 |
| Nilai bisnis warisan identik dengan sumbernya | Inti PRD 5.5 |
| Data warisan tidak menunjuk pelaku (semua `NULL`) | PRD 5.5: jangan mengarang pelaku |
| WhatsApp **tidak** menyala | Keputusan produk 26 Agustus 2026 |
| Tidak ada pengajuan tanpa santri | Laporan akan menghilangkan baris itu diam-diam |
| Tidak ada pengajuan dengan lebih dari satu keputusan | Melanggar jaminan keputusan tunggal |
| `API_TOKEN_HASH_SECRET` terisi | Autentikasi tidak akan berjalan tanpanya |

Peringatan yang **tidak** memblokir: kolom receipt sudah ada (migrasi pernah
dijalankan — aman karena idempoten), `PUSH_TOKEN_KEY`/`EXPO_ACCESS_TOKEN` belum
terisi, dan durasi keputusan negatif (menandakan jam server pernah mundur).

Preflight **tidak pernah** mencetak nilai environment. Ia hanya melaporkan
apakah sebuah nama environment sudah terisi.

## 4. Yang diperiksa verifikasi pasca-migrasi

`bin/v2_phase5_verify.php` menjalankan 22 pemeriksaan dalam empat kelompok:

1. **Data perizinan lama** — jumlah baris sama, seluruh ID identik dan berurutan
   sama, sidik jari nilai bisnis tidak berubah, setiap baris masih terbaca pada
   `izin_pengajuan`, ID tidak bergeser, nilai bisnis identik, pelaku warisan
   tetap `NULL`.
2. **Sifat aditif** — tidak ada tabel yang hilang, tidak ada tabel yang jumlah
   barisnya **berkurang**, `izin_pengajuan` dan `izin_keputusan` tidak menyusut.
3. **Kolom receipt** — enam kolom dan satu indeks terpasang, tidak ada baris
   dengan `receipt_status` `NULL`.
4. **Pengaman kanal** — in-app tetap tidak dapat dimatikan, WhatsApp tetap OFF.

## 5. Latihan end-to-end yang sudah dijalankan

`bin/v2_phase5_backup_restore_drill.php` menjalankan seluruh rantai pada
sandbox dan **lulus 17/17 pemeriksaan** pada 26 Agustus 2026:

| Tahap | Isi | Hasil |
| --- | --- | --- |
| a | Menyuntik **30 baris warisan sintetis** ke `perizinan` | 30 baris dibuat |
| b | Backfill Fase 1 memindahkannya ke `izin_pengajuan` | 30/30 termigrasi |
| c | Sidik jari nilai bisnis **sebelum** migrasi | tercatat |
| d | Backup + manifest | 47 tabel, 3.944 baris |
| e | Restore ke database `_test` **kedua** yang terpisah | tanpa galat |
| f | Pencocokan jumlah baris dengan manifest | **47/47 tabel cocok** |
| f | ID dan nilai bisnis `perizinan` hasil restore vs sumber | identik |
| g | Migrasi 009 pada database pulihan | tanpa galat |
| g | ID, jumlah, dan nilai bisnis `perizinan` **setelah migrasi** | **tidak berubah** |
| g | Tidak ada tabel yang barisnya berkurang | terpenuhi |
| h | Rollback 009 pada database pulihan | tanpa galat |
| h | ID dan nilai bisnis setelah **rollback** | **tidak berubah** |
| h | Kolom receipt terlepas, baris `notifikasi_outbox` tetap utuh | terpenuhi |

Mengapa data warisan sintetis disuntikkan lebih dulu: tanpa itu tabel
`perizinan` pada sandbox kosong, dan kriteria "ID tidak berubah" akan **lolos
secara palsu** terhadap tabel kosong. Latihan ini sengaja menguji terhadap data.

### Menjalankan ulang latihan

```bash
V2_PHASE5_DRILL=1 php bin/v2_phase5_backup_restore_drill.php
V2_PHASE5_DRILL=1 php bin/v2_phase5_backup_restore_drill.php --target=nama_db_test
```

Penjaga: hanya CLI, sumber **dan** tujuan wajib berakhiran `_test`, menolak
`APP_ENV=production`. Database tujuan dibuat ulang dan dihapus kembali oleh
skrip; fixture `DRILL5` dibersihkan pada akhir.

## 6. Catatan teknis dari latihan

Dua pelajaran yang sudah tertanam pada skrip dan patut diketahui operator:

1. **Restore memakai klien baris perintah, bukan `multi_query()`.** Ini jalur
   yang sama dengan pemulihan sungguhan di cPanel (`mysql nama_db < backup.sql`),
   sekaligus menghindari batas `max_allowed_packet` pada backup besar.
2. **Nilai pada berkas opsi MySQL wajib dikutip.** Tanpa kutip, karakter `#`
   pada password memulai **komentar**, sehingga password terpotong diam-diam dan
   restore ditolak dengan `Access denied` yang membingungkan. Credential
   dikirim lewat `--defaults-extra-file` bermode 0600 dan **tidak pernah** lewat
   argumen baris perintah yang terlihat pada `ps`.

## 7. Retensi dan kerahasiaan backup

- Backup memuat **seluruh** data santri, wali, dan pengguna. Perlakukan sebagai
  data pribadi.
- `storage/backups/` sudah masuk `.gitignore`; jangan pernah meng-commit-nya.
- Simpan di luar `public_html` atau lindungi dengan `.htaccess` bila jalurnya
  dapat diakses web.
- Password basis data **tidak** ikut ke dalam berkas backup, tetapi berkas
  opsi sementara yang dipakai restore memuatnya — skrip menghapusnya segera
  setelah selesai.
- Simpan sedikitnya backup dari **tiga** rilis terakhir sebelum menghapus yang
  lama.
