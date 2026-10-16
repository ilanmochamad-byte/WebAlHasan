# Migrasi dan rollback Fase 1 V4

## Sebelum penerapan

1. Pastikan branch rilis yang disetujui auditor, `019` tercatat dan tidak ada migrasi lain yang menunggu. Catat versi MariaDB/MySQL, waktu, dan penanggung jawab.
2. Buat backup konsisten database **dan** `storage/private/`; simpan di luar webroot. Uji bahwa backup dapat dipulihkan pada salinan terpisah. Jangan mencetak kredensial ke log.
3. Jalankan `php bin/v4_phase1_preflight.php /lokasi/privat/manifest-sebelum.json`. Skrip hanya membaca skema/jumlah baris. Simpan manifest serta hash SHA-256 dump backup. Manifest membandingkan tabel bisnis V1–V3; `audit_logs` dan `schema_migrations` dikecualikan karena migrasi/operasi sah menambah baris di sana.
4. Jalankan `php bin/migrate.php up` hanya setelah jadwal rilis dan backup disetujui operator. Perintah ini **belum** dijalankan pada produksi dalam handoff ini.
5. Jalankan `php bin/v4_phase1_verify.php /lokasi/privat/manifest-sebelum.json`. Keluaran wajib menunjukkan 11 tabel V4, migrasi 020 sekali, nol referensi yatim V4, dan semua jumlah baris bisnis V1–V3 sama. Uji URL web/API dan akses 403 dengan akun nonproduksi yang sah.

Jika verifier gagal, hentikan pemakaian Fase 1, simpan hasil dan periksa penyebab sebelum tindakan data. Jangan membersihkan residu V3 lama agar angka terlihat lulus.

## Rollback

1. Pastikan belum ada data di **semua** tabel V4 lewat `php bin/v4_phase1_rollback_preflight.php`. SQL rollback juga memiliki pengaman yang menolak bila satu baris V4 pun ada.
2. Pastikan 020 adalah migrasi terakhir, lalu jalankan `php bin/migrate.php rollback`. Periksa bahwa tabel V4 hilang dan tabel V1–V3 tetap. File privat V4 yang mungkin tertinggal harus diinventarisasi manual terhadap backup, jangan dihapus massal.
3. Bila sudah ada data V4, **jangan** jalankan rollback destruktif. Pertahankan data dan lakukan perbaikan dengan migrasi maju; bila perlu pemulihan bencana, gunakan backup database dan berkas privat yang sudah diuji secara terkoordinasi.

## Drill yang benar-benar dijalankan (MariaDB 12.3.2 lokal)

Salinan `webalhasan_v4_schema_test` dibuat dari dump repositori, diterapkan 001–019, preflight dan manifest sebelum 020, lalu 020. `bin/migrate.php up` kedua: `Tidak ada migrasi baru`; eksekusi SQL 020 langsung ulang berhasil, jumlah tabel V4 tetap **11** dan pasangan nama tabel/kolom ganda **0**. Verifier menghasilkan **0 referensi yatim V4** dan seluruh jumlah baris bisnis lama tetap (lihat `verifier-schema-uji.txt`). Pada DB kosong V4, rollback preflight, rollback 020, migrasi ulang, dan verifier semua lulus. Setelah satu baris V4 sintetis ditambahkan, rollback preflight dan rollback SQL keduanya menolak; baris dan catatan migrasi tetap ada. Tidak ada migrasi atau penghapusan di produksi.

Hash SHA-256 berkas migrasi, rollback, kode, dan screenshot tersimpan di `SHA256SUMS`; verifikasi dengan `shasum -a 256 -c docs/phase-v4-1/SHA256SUMS` dari akar repositori.
