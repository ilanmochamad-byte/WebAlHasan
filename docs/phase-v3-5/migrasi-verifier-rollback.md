# Migrasi 019, verifier dan rollback

Urutan wajib: fondasi 001–012 → V3 013–018 → **019_v3_fase5_laporan_retensi.sql**. Tidak ada migrasi mobile. 019 menambah tiga indeks saja: pratinjau(expires_at,id), pelanggaran(archived_at,waktu_kejadian,id), kasus(archived_at,dibuka_pada,id). Pemeriksaan information_schema + PREPARE membuat DDL idempotent pada MySQL/MariaDB. Tidak menghapus/mengubah baris bisnis.

Drill database uji: migrate up ulang, rollback terakhir 019, hash seluruh baris setiap tabel selain schema_migrations tetap sama, up 019 kembali, hash sama, up kedua kosong. Drill 017/018 lama sekarang memakai jendela migrasinya pada DB uji dan otomatis memulihkan 018/019 saat selesai. Guard menolak database produksi dan nomor migrasi lain yang belum dikenal. Ini bukan izin menjalankan drill di hosting.

## Langkah operator — belum dijalankan produksi

1. Setelah audit independen dan otorisasi Human Developer, ambil SHA web/mobile dari handoff, verifikasi manifest/checksum, backup database serta berkas privat di luar webroot, dan uji restore pada salinan. Simpan bukti backup tanpa credential/isi bisnis di Git.
2. Pastikan baseline hosting 018 dan MySQL/MariaDB sesuai. Jalankan preflight/read-only verifier; selidiki selisih sebelum migrasi. Batasi akses mutasi saat pergantian rilis.
3. Perintah di bawah memakai konfigurasi database hosting yang sah di luar repository; **jangan** menaruh password pada command line atau dokumen.

```sh
php bin/v3_phase5_preflight.php
php bin/migrate.php status
php bin/migrate.php up
php bin/v3_verify.php
php bin/v3_phase2_verify.php
php bin/v3_phase3_verify.php
php bin/v3_phase4_verify.php
php bin/v3_phase5_verify.php
php bin/v3_push_receipts.php
```

4. Jalankan smoke cPanel dan simpan bukti; pertahankan Push ON/WhatsApp OFF. Tidak ada migrasi produksi yang dijalankan dalam sesi implementasi.
5. Jika perlu rollback, pastikan `migrate status` menunjukkan 019 sebagai migrasi terakhir. `php bin/migrate.php rollback` menghapus tiga indeks 019 saja; restore kode baseline yang sudah disetujui. Snapshot, ledger, kasus, sesi, audit/outbox dan publikasi tetap ada. Jangan rollback 013–018 atau restore backup di atas transaksi baru tanpa rencana operator. Jalankan verifier 1–4 setelah rollback; verifier 5 tentu melaporkan indeks yang tidak ada. Migrasi ulang `up` memasang indeks kembali.

## Retensi manual

Tidak menambahkan cron purge secara otomatis. Operator memilih ID pengguna admin aktif miliknya; nilai contoh di bawah merupakan placeholder.

```sh
php bin/v3_pratinjau_purge.php --actor=ID_ADMIN_AKTIF --batch=100
php bin/v3_pratinjau_purge.php --actor=ID_ADMIN_AKTIF --batch=100 --apply
```

Periksa dry-run sebelum apply. Default tidak menghapus. Batch 1–500; pilih 100 untuk pertama. Hanya pratinjau yang expires_at lebih lama tujuh hari, transaksi/lock/audit wajib; audit gagal menggulung semua penghapusan. Publikasi terbit dan riwayat tidak disentuh. Tidak menghapus audit wajib dan tidak membersihkan data smoke produksi. Jangan mengulang apply tanpa memeriksa jumlah/audit tiap batch.

Manifest/checksum ada di `manifest-sha256.txt`. Manifest berisi kode/migrasi/pengujian yang berubah; dokumen bukti tidak diikutkan untuk menghindari checksum sirkular. SHA rilis final adalah commit fitur yang tercantum di laporan handoff, bukan baseline di atas.
