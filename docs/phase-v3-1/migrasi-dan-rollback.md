# Skema, migrasi, dan rollback

Migrasi baru: `database/migrations/013_v3_fase1.sql`; rollback berpasangan `database/rollbacks/013_v3_fase1.sql`. Tidak mengubah migrasi produksi 001–012. Diuji pada PHP 8.4.14 dan MariaDB 12.3.2 lokal.

## Struktur

| Tabel | Isi |
| --- | --- |
| `v3_kategori` | Kode/nama/uraian, masa berlaku/status |
| `v3_katalog` | Kategori FK, tingkat, poin default, kode unik, masa berlaku |
| `v3_ambang` | Tahun ajaran FK, rentang poin, rekomendasi, masa berlaku |
| `v3_pelanggaran` | Santri/tahun/pembimbing/penugasan/katalog FK, kejadian, snapshot kategori/tingkat/poin/cakupan, status, fingerprint, idempotency, revisi, provenance warisan |
| `v3_poin_ledger` | Delta poin, sumber pelanggaran, santri/tahun, pembalik unik, event key |
| `v3_konseling_kasus` | Pemilik pembimbing, santri/tahun, tujuan, kerahasiaan, status, buka/tutup, provenance |
| `v3_konseling_sesi` | Kasus, pembimbing, jadwal/realisasi, ringkasan internal, hasil/tindak lanjut, revisi |
| `v3_konseling_tautan` | Pelanggaran–kasus–sesi; satu pelanggaran dapat ditautkan ke beberapa sesi |
| `v3_murobi_catatan` | Sumber catatan, guru/penugasan, dilihat/diketahui, catatan murobi terpisah |
| `v3_publikasi` | Sumber/versi, santri/wali, ringkasan publik terpisah, terbit/tarik/dibaca |
| `v3_lampiran` | Sumber/versi, nama aman, MIME, ukuran, hash, lokasi privat |
| `v3_idempotency` | User/operation/key unik, request hash, response/status |

Semua tabel menyediakan `created_by`, `updated_by` (FK users), timestamp, `version>0`, dan `archived_at`. Tidak ada cascade DELETE data bisnis. FK mengikuti tipe master aktual: santri/guru/tahun INT; users/pengurus/wali/penugasan BIGINT UNSIGNED.

CHECK menegakkan nonnegatif, urutan tanggal/rentang, status boolean, versi positif, serta tepat satu jenis sumber pada lampiran/catatan/publikasi. Unique key mencakup kode, event, retry, tautan dan pembalik. Indeks mencakup pencarian katalog, ambang per tahun, riwayat santri/tahun, jadwal sesi, dan publikasi per penerima.

Struktur ini tidak mengimplementasikan validasi bisnis fase berikutnya. Kesamaan santri pada tautan, revisi historis, pembalik ledger yang benar, publikasi dan outbox tetap wajib ditegakkan oleh service pada fase terkait. Fase 1 tidak membuka pintu mutasinya.

## Pre-check dan urutan

1. Operator menyiapkan backup dan menguji restore pada database terpisah. Jangan menyalin credential atau data santri ke Git.
2. Pastikan baseline/fondasi 012 tersedia, tidak ada deployment/agen lain yang bekerja bersamaan.
3. Jalankan `php bin/v3_verify.php --pre`; harus exit 0. Pemeriksaan akun/penugasan mengharuskan admin, pembimbing, murobi, dan relasi wali aktif tersedia.
4. Simpan manifest jumlah/hash tabel lama melalui mekanisme backup terkontrol. Untuk fixture khusus lokal, `tests/v3_phase1_migration.php` membandingkan hash isi seluruh tabel non-V3 tanpa mencetak isinya.
5. `php bin/migrate.php status`, lalu `php bin/migrate.php up`.
6. `php bin/v3_verify.php`: memeriksa tabel, semua nama kolom, jumlah FK/CHECK/unique, indeks bernama, seluruh FK yatim, role, relasi, capability, serta overlap ambang; exit nonzero pada blocker.

CREATE IF NOT EXISTS dan pencatat migrasi membuat runner aman diulang tanpa menggandakan struktur. DDL MySQL tidak atomik; bila gagal sebagian, periksa penyebab, ulangi runner, lalu post-check lengkap. Jangan memakai rollback sebagai cara mencoba lagi di produksi yang sudah berisi data. Tidak ada dry-run DDL umum; pre-check hanya membaca, dan drill dilakukan pada DB uji.

## Rollback

Utamakan rollback kode saja; tabel tambahan tidak mengubah data V1/V2. Jika rollback skema benar-benar diperlukan: backup seluruh data V3 dan audit, kembalikan kode ke baseline, pastikan migrasi terakhir tepat 013, baru jalankan `php bin/migrate.php rollback`.

Rollback skema **menghapus seluruh tabel dan isi V3**, bukan tabel lama. Audit lama tetap dipertahankan; setelah pemasangan ulang ID V3 dapat dipakai kembali, sehingga backup audit dan hubungan entitas harus dipertahankan oleh operator. Jangan menjalankan drill pada produksi. Rollback 013 bukan alat pembatalan bisnis.

## Reproduksi lokal

Database pengujian V3 sengaja dibatasi nama tepat `webalhasan_v3_phase1_test`. Siapkan struktur dasar kosong (CREATE/ALTER saja, tanpa INSERT dari dump). Pasang migrasi 001–013, kemudian fixture resmi:

```sh
V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php
V2_PHASE5_FIXTURE=1 php bin/v2_phase5_fixture.php --jumlah=1000
V3_RUN_TESTS=1 php tests/v3_phase1_migration.php
bash bin/v3_phase1_run_tests.sh
bash bin/penugasan_run_all_tests.sh
```

Drill menghapus fixture V3; jalankan sebelum tes paket/browser. Regresi lama dapat meninggalkan audit/outbox yatim setelah menghapus akun fixture miliknya. Diagnostik menyatakan keadaan itu gagal dengan benar. Gunakan DB uji bersih untuk pemeriksaan penerimaan, atau bersihkan hanya sisa fixture yang asalnya dapat dibuktikan; jangan menonaktifkan pemeriksaan FK untuk meluluskan diagnostik.

Belum dijalankan pada salinan produksi representatif MariaDB cPanel 10.6.27 atau MySQL 8. Bukti lokal bukan pengganti pemeriksaan kompatibilitas versi hosting tersebut.
