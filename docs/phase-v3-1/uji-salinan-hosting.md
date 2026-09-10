# Uji salinan hosting — menutup status `MEMERLUKAN UJI MYSQL`

Dokumen ini menutup satu-satunya risiko teknis Fase 1 yang belum terbukti: apakah migrasi 013 berjalan benar pada versi database hosting, bukan hanya pada MariaDB lokal auditor.

**Gerbang deploy.** `main` sudah memuat Fase 1 (PR #22, `e685b57`), tetapi migrasi belum pernah dijalankan pada versi database hosting. Jangan deploy sampai Jalur A di bawah hijau.

## 0. Yang perlu dipastikan lebih dahulu

Dump struktur yang ada di repositori (`k1807225_webalhasan.sql`) berasal dari **16 Agustus 2026** dan menyebut server hosting **MariaDB 10.6.24-cll-lve**. Dump itu hanya memuat **20 tabel V1** — belum ada `roles`, `user_roles`, `pengurus`, `wali`, `santri_wali`, `audit_logs`, `notifikasi_outbox`, `pembimbing_assignments`, maupun `murobi_assignments`.

Artinya, **bila keadaan itu masih berlaku**, produksi belum pernah menjalankan migrasi 001–012, sehingga deploy V3 berarti menjalankan seluruh rantai 001→013, bukan 013 saja. Konsekuensinya jauh lebih besar daripada satu migrasi tambahan.

Dump itu berumur ~3 minggu dan bisa saja sudah usang. **Konfirmasi keadaan sekarang lebih dahulu**, jangan berasumsi. Di phpMyAdmin cPanel, pada database produksi, jalankan kueri **baca-saja** ini:

```sql
SELECT VERSION() AS versi_server;
SELECT COUNT(*) AS jumlah_tabel FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();
SELECT migration, applied_at FROM schema_migrations ORDER BY id;
```

Bila `schema_migrations` tidak ada, produksi memang masih di baseline V1.

## Jalur A — uji di server cPanel (menentukan, wajib)

Hanya jalur ini yang benar-benar membuktikan kompatibilitas versi. Seluruh langkah berada **di dalam** akun hosting; tidak ada data produksi yang diunduh ke komputer lokal atau masuk ke Git.

1. **Backup dulu.** Ekspor penuh database produksi lewat cPanel Backup, dan uji restore-nya ke database lain sebelum melangkah.
2. **Buat database bekas uji** lewat cPanel → MySQL Databases, misalnya `<akun>_v3uji`. Ini database terpisah, bukan produksi.
3. **Salin produksi ke database uji, di sisi server.** Di phpMyAdmin: pilih database produksi → tab **Operations** → *Copy database to* → isi nama database uji → pilih **Structure and data** → centang *Add DROP TABLE*. Cara ini menyalin di dalam server, sehingga salinan tetap representatif tanpa data keluar dari hosting.
   - Bila kebijakan Anda melarang menyalin data sekalipun di dalam server, pilih **Structure only**. Uji tetap sah untuk kompatibilitas skema, tetapi tidak membuktikan preservasi baris.
4. **Catat jumlah baris sebelum migrasi.** Pada database uji, jalankan dan **simpan hasilnya**:

   ```sql
   SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME;
   ```

5. **Jalankan migrasi pada database uji.** Dua pilihan:
   - **Terminal cPanel** (bila tersedia): arahkan `DB_NAME` ke database uji, lalu `php bin/migrate.php up`. Ini pilihan terbaik karena memakai runner yang sama dengan produksi.
   - **phpMyAdmin**: impor berkas migrasi berurutan dari `database/migrations/`, mulai dari yang belum tercatat di `schema_migrations` sampai `013_v3_fase1.sql`. Bila menempuh cara ini, catat sendiri setiap migrasi yang dijalankan ke tabel `schema_migrations` agar runner tidak mengulanginya kelak.
6. **Jalankan post-check struktur.** Impor `database/checks/013_v3_fase1_postcheck.sql` di phpMyAdmin dengan database uji terpilih. Skrip ini **hanya membaca** dan tidak memerlukan PHP atau `.env`.
7. **Bandingkan jumlah baris sesudah migrasi** dengan hasil langkah 4.

### Kriteria lulus Jalur A

- [ ] Seluruh migrasi berjalan tanpa error.
- [ ] Post-check: **nol** baris `GAGAL` pada pemeriksaan 1–7.
- [ ] Pemeriksaan 8 mencatat versi server hosting yang sebenarnya — simpan sebagai bukti.
- [ ] Jumlah baris tabel lama identik sebelum dan sesudah migrasi.
- [ ] Bila muncul selisih pada pemeriksaan 2 (jumlah constraint), **jangan disamakan diam-diam**. MariaDB versi berbeda dapat melaporkan CHECK secara berbeda; laporkan selisihnya apa adanya.

Setelah itu lanjutkan checklist fungsional 11 butir pada [panduan-admin.md](panduan-admin.md).

## Jalur B — gladi lokal (opsional, untuk latihan berulang)

Membangun database uji lokal dari dump **struktur** hosting, memasang 001–013, lalu menjalankan post-check yang sama:

```bash
bash bin/v3_salinan_hosting.sh <dump-struktur.sql> test_salinan_hosting
```

Penjaga skrip: nama database wajib berakhiran `_test` atau berawalan `test_`, sasaran tidak boleh sama dengan `DB_NAME` pada `.env`, dan seluruh pernyataan `INSERT` dibuang dari dump sebelum impor sehingga **tidak ada satu baris data pun** yang tersalin.

Catatan: pada salinan struktur kosong, pre-check memang gagal di tahap kesiapan data (belum ada admin, penugasan, atau relasi wali). Itu wajar; yang harus lulus di tahap itu hanyalah keberadaan tabel prasyarat.

**Batas Jalur B:** berjalan pada MariaDB lokal, sehingga **tidak** menggantikan Jalur A.

## Bukti yang sudah ada (auditor, 9–10 September 2026)

Dijalankan pada MariaDB 12.3.2 lokal:

- Migrasi 013 terpasang bersih di bawah `sql_mode` ketat ala MySQL 8 (`STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,ONLY_FULL_GROUP_BY,NO_ENGINE_SUBSTITUTION`), tanpa error maupun warning.
- **Rantai penuh 001→013 berjalan bersih di atas struktur produksi nyata** dari `k1807225_webalhasan.sql` (20 tabel V1), dan post-check struktur menghasilkan **0 GAGAL**.
- Post-check SQL pada dokumen ini divalidasi terhadap skema yang sudah diverifikasi: seluruh pemeriksaan 1–7 LULUS.

Bukti ini mempersempit risiko sintaksis, `sql_mode`, dan urutan migrasi. Ia **tidak** membuktikan perilaku MariaDB 10.6.24 hosting, karena tidak ada mesin 10.6 yang tersedia di lingkungan audit (Docker tidak terpasang).
