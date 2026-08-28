# V2 Fase 5 — Runbook Respons Insiden

Dokumen operasional. Setiap bagian mengikuti pola: **gejala → periksa →
tindakan → kapan eskalasi**.

Prinsip yang berlaku pada seluruh insiden di bawah:

1. **Transaksi perizinan adalah sumber kebenaran.** Kegagalan notifikasi,
   laporan, atau cron **tidak pernah** membatalkan pengajuan atau keputusan yang
   sudah tersimpan.
2. **Notifikasi in-app selalu tersedia** dan tidak dapat dimatikan. Bila push
   bermasalah, pengguna tetap dapat melihat status di aplikasi/web.
3. **Jangan "memperbaiki" dengan mengirim ulang.** Mengirim ulang baris outbox
   yang sudah terkirim menghasilkan notifikasi ganda di perangkat penerima.

---

## 1. Laporan tidak dapat dibuka (galat 500)

**Gejala:** halaman `portal/laporan.php` atau endpoint `/izin/laporan` menjawab
500, atau menampilkan halaman kosong.

**Periksa**

```bash
tail -100 error_log                      # log PHP di akar aplikasi
php -l app/Report/IzinReportService.php  # sintaks setelah unggah parsial
php bin/migrate.php status               # migrasi 009 sudah diterapkan?
```

**Tindakan**

| Penyebab | Tindakan |
| --- | --- |
| Unggahan berkas tidak lengkap | Unggah ulang seluruh berkas pada `cpanel-deployment.md` §2 |
| Migrasi 009 belum diterapkan | `php bin/migrate.php up` |
| `APP_DEBUG=true` di produksi | Ubah ke `false` — pesan galat internal tidak boleh tampil ke pengguna |
| Versi PHP cPanel berbeda | Jalankan `php -l` dengan versi PHP cPanel pada seluruh berkas `app/Report/` |

**Eskalasi:** bila galat berlanjut setelah unggah ulang, kembalikan kode ke
commit sebelum rilis (`migration-and-rollback.md` §4). Basis data **tidak**
perlu di-rollback — laporan tidak mengubah skema.

---

## 2. Total ringkasan berbeda dari jumlah baris detail atau CSV

**Gejala:** angka pada kartu ringkasan tidak sama dengan jumlah baris pada
tabel, halaman cetak, atau berkas CSV.

Ini **tidak boleh terjadi** dan merupakan cacat serius, bukan sekadar
ketidaknyamanan.

**Periksa**

1. Bandingkan sidik jari kriteria. Setiap permukaan mengeluarkannya:
   - web: kartu "Data warisan dalam hasil" menampilkan 16 karakter pertama;
   - CSV: header HTTP `X-Laporan-Kriteria`;
   - API: field `kriteria`.
   **Bila keempatnya sama tetapi totalnya berbeda, ada cacat pada repository.**
2. Periksa penanda terpotong:
   - CSV: header `X-Laporan-Terpotong: 1`;
   - cetak: kotak peringatan kuning di atas tabel;
   - API: field `terpotong`.
   Bila ada, hasil melebihi 20.000 baris — persempit filter. Ini **bukan** cacat.
3. Jalankan gerbang otomatis:
   ```bash
   V2_PHASE5_RUN_INTEGRATION=1 php tests/v2_phase5_integration.php
   ```
   Kelompok KL-1 membandingkan keempat permukaan pada 6 kombinasi filter.

**Tindakan:** bila KL-1 gagal, **hentikan pemakaian laporan untuk keperluan
pertanggungjawaban** dan kembalikan kode ke rilis sebelumnya. Jangan memakai
angka yang tidak konsisten untuk keputusan apa pun.

**Penyebab yang pernah diantisipasi:** menambahkan `JOIN` ke tabel plotting
akan menggandakan baris pengajuan bagi santri yang punya lebih dari satu baris
plotting. Karena itu kamar/kelas sengaja dibaca lewat subquery skalar. Jangan
mengubahnya menjadi `JOIN`.

---

## 3. Pengguna melihat data di luar cakupannya

**Gejala:** pengurus, murobi, atau orang tua melaporkan melihat nama santri atau
pengajuan yang bukan haknya.

**Ini insiden keamanan. Tangani lebih dulu, selidiki kemudian.**

**Tindakan segera**

1. Catat: akun mana, halaman/endpoint mana, filter apa, dan contoh baris yang
   seharusnya tidak terlihat.
2. Jalankan gerbang isolasi:
   ```bash
   V2_PHASE5_RUN_INTEGRATION=1 php tests/v2_phase5_integration.php   # KL-2, KL-3
   V2_PHASE5_RUN_API=1         php tests/v2_phase5_api_contract.php  # KR-2, KR-3
   V2_PHASE5_RUN_WEB=1         php tests/v2_phase5_web_smoke.php     # WL-3, WL-6
   ```
3. Bila salah satu gagal: **kembalikan kode laporan ke rilis sebelumnya
   sekarang juga**, lalu selidiki.
4. Bila seluruhnya lulus, periksa **data**, bukan kode — cakupan mungkin memang
   benar menurut relasi:
   ```sql
   -- Relasi wali yang seharusnya sudah diarsipkan tetapi masih aktif
   SELECT sw.* FROM santri_wali sw JOIN wali w ON w.id = sw.wali_id
    WHERE sw.wali_id = ? AND sw.archived_at IS NULL;

   -- Penugasan murobi yang seharusnya sudah berakhir
   SELECT * FROM murobi_assignments
    WHERE guru_id = ? AND is_active = 1 AND archived_at IS NULL;

   -- Penugasan pembimbing pengurus
   SELECT * FROM pembimbing_assignments
    WHERE pengurus_id = ? AND is_active = 1 AND archived_at IS NULL;
   ```
   Penyebab tersering: relasi lama yang lupa dinonaktifkan admin.

**Jangan** menambal dengan menyembunyikan kolom atau tombol. Cakupan ditegakkan
di SQL; perbaikan harus di sana atau pada data relasi.

---

## 4. Push tidak sampai ke perangkat

**Gejala:** pengguna melaporkan tidak menerima notifikasi push, sementara
notifikasi in-app muncul normal.

**Periksa berurutan**

```bash
php bin/v2_phase5_cron_check.php          # gambaran menyeluruh
php bin/notifikasi_worker.php --status    # antrean + sebaran receipt
php bin/v2_phase4_diagnose_notifikasi.php --pengajuan=<id>
```

**Pohon keputusan**

| Temuan | Arti | Tindakan |
| --- | --- | --- |
| `Push: nonaktif` | Kanal dimatikan admin | Nyalakan lewat panel admin bila memang dikehendaki |
| Baris tertahan > 15 menit, tidak ada jejak sewa worker | Cron tidak berjalan | `cpanel-deployment.md` §4 |
| `PUSH_TOKEN_KEY belum siap` | Environment kosong | Isi lalu muat ulang |
| Penerima tidak punya perangkat aktif | Pengguna belum mendaftarkan perangkat atau menolak izin | Minta pengguna membuka layar Perangkat di aplikasi |
| `receipt_status = Gagal`, kode `DeviceNotRegistered` | Aplikasi dihapus atau token kedaluwarsa | Normal; token dicabut otomatis dan aplikasi mendaftar ulang saat dibuka |
| `receipt_status = Gagal`, kode lain | Penyedia menolak | Periksa `receipt_pesan` pada panel admin |
| `receipt_status = Menunggu` menumpuk | Cron `--receipts` belum dipasang | Tambahkan baris cron kedua |
| `receipt_status = Tidak Tersedia` | Penyedia tidak menjawab dalam 6 percobaan | **Bukan bukti gagal antar.** Pengantaran tidak dapat dipastikan; in-app tetap sumber status |
| `status = Sent` tetapi `receipt_status = Belum Diperlukan` | Terkirim sebelum migrasi 009 | Normal untuk baris lama |

**Penting:** status `Sent` hanya berarti Expo **menerima** tiket.
Yang membuktikan pengantaran adalah `receipt_status = Terkirim`.

**Jangan** mengembalikan baris `Sent` ke antrean untuk "mencoba lagi".

---

## 5. Migrasi gagal di tengah jalan

**Gejala:** `php bin/migrate.php up` berhenti dengan galat, keadaan basis data
meragukan.

**Tindakan**

1. **Berhenti.** Jangan menjalankan ulang migrasi untuk "memperbaiki".
2. Nonaktifkan baris cron worker agar tidak menulis lagi.
3. Migrasi 009 idempoten dan aditif, sehingga menjalankan ulang **biasanya**
   aman. Namun bila galatnya menyangkut kunci, ruang disk, atau koneksi
   terputus, pulihkan dari backup:
   ```bash
   mysql -u USER -p nama_db < storage/backups/v2-phase5/<stamp>/database.sql
   php bin/verify_restore.php storage/backups/v2-phase5/<stamp>/manifest.json
   ```
4. Kembalikan kode ke commit sebelum rilis.
5. Aktifkan kembali cron.
6. Catat kejadian pada §8.

**Eskalasi:** bila backup preflight tidak ada, **jangan melanjutkan apa pun** —
hubungi pemilik produk sebelum menyentuh basis data lebih lanjut.

---

## 6. Data perizinan lama tampak berubah

**Gejala:** jumlah atau isi pengajuan warisan berbeda dari sebelum rilis.

**Periksa**

```bash
php bin/v2_phase5_verify.php storage/backups/v2-phase5/<stamp>/manifest.json
```

Verifikasi membandingkan jumlah baris, seluruh ID, **dan** sidik jari SHA-256
nilai bisnis. Bila sidik jari berbeda, cari baris yang berubah:

```sql
SELECT t.id, t.santri_id, p.id_santri, t.tgl_izin, p.tgl_izin,
       t.tgl_kembali, p.tgl_kembali, t.status
  FROM izin_pengajuan t
  JOIN perizinan p ON p.id = t.legacy_perizinan_id
 WHERE t.santri_id <> p.id_santri
    OR t.tgl_izin  <> p.tgl_izin
    OR t.tgl_kembali <> p.tgl_kembali
    OR t.alasan <> p.alasan;
```

**Tindakan:** ini pelanggaran PRD 5.5. Hentikan rilis, pulihkan dari backup, dan
laporkan kepada pemilik produk sebelum mencoba lagi.

Catatan: migrasi 009 **tidak menyentuh** `perizinan` maupun `izin_pengajuan`
sama sekali, sehingga penyebabnya hampir pasti berasal dari luar migrasi ini.

---

## 7. Ekspor CSV berperilaku aneh di Excel

| Gejala | Sebab | Tindakan |
| --- | --- | --- |
| Huruf beraksen rusak | BOM terbuang oleh perantara | Buka lewat Data → From Text/CSV, pilih UTF-8 |
| Sel diawali kutip tunggal | **Disengaja** — netralisasi formula injection | Bukan cacat; jangan dihapus |
| Berkas terpotong | Hasil > 20.000 baris | Header `X-Laporan-Terpotong: 1` muncul; persempit filter |
| Jumlah baris ≠ total ringkasan | Lihat §2 | — |
| Berkas terbuka sebagai halaman web | `Content-Disposition` hilang | Periksa apakah ada perantara yang membuang header |

---

## 8. Format catatan insiden

Simpan pada `docs/phase-v2-5/insiden/` dengan nama `YYYY-MM-DD-<ringkas>.md`:

```
Tanggal & waktu   :
Dilaporkan oleh   :
Gejala            :
Dampak            : (siapa terpengaruh, berapa lama, data apa)
Bagian runbook    :
Yang diperiksa    : (perintah + keluaran, apa adanya)
Tindakan          :
Waktu pulih       :
Akar penyebab     :
Pencegahan        : (pengujian apa yang akan menangkapnya lain kali)
```

Setiap insiden yang tidak tertangkap pengujian otomatis **wajib** menghasilkan
pengujian baru. Itulah cara daftar ini berhenti bertambah panjang.

---

## 9. Kontak dan batas kewenangan

| Tindakan | Boleh dilakukan operator | Perlu izin pemilik produk |
| --- | --- | --- |
| Menjalankan skrip diagnosa dan pemeriksaan (hanya baca) | ya | — |
| Menjalankan worker manual | ya | — |
| Menyalakan/mematikan push | ya | — |
| Memasang cron | — | **ya** |
| Menjalankan migrasi produksi | — | **ya** |
| Restore basis data produksi | — | **ya** |
| Rollback migrasi | — | **ya** |
| **Mengaktifkan WhatsApp** | **TIDAK PERNAH** | **ya**, dan hanya setelah seluruh syarat `../phase-v2-4/whatsapp-provider-checklist.md` terpenuhi |
