# Migrasi dan rollback Fase 3

## Artefak

- Migrasi: `database/migrations/016_v3_fase3_konseling.sql`
- Rollback: `database/rollbacks/016_v3_fase3_konseling.sql`
- Preflight: `bin/v3_phase3_preflight.php`
- Verifier: `bin/v3_phase3_verify.php`
- Runner: `bin/v3_phase3_run_tests.sh`

Migrasi 016 menambah metadata alasan koreksi/pembatalan kasus, metadata alasan jadwal ulang/pembatalan sesi, penjaga unik satu revisi langsung per sesi, generated guard untuk keunikan tautan yang memiliki `sesi_id=NULL`, serta tautan rekomendasi ke kasus berikut waktunya. Tidak ada tabel atau catatan bisnis lama yang dihapus.

Baris lama berstatus `Dijadwalkan Ulang`/`Dibatalkan` yang belum memiliki kolom alasan diberi penanda konservatif bahwa rincian historis harus dirujuk dari `audit_logs`; migrasi tidak mengarang alasan bisnis.

Rollback melepas hanya kolom, foreign key, dan indeks milik 016. Sebelum indeks unik revisi dilepas, rollback memulihkan indeks biasa bernama `revisi_dari_id` untuk tetap menopang foreign key pada MariaDB. Pemasangan ulang membuang nama indeks sementara dari versi awal rollback agar tidak menyisakan indeks redundan. Kasus, sesi, tautan, rekomendasi, pelanggaran, dan ledger tidak dihapus.

## Urutan operator

1. Buat backup/restore point dan jalankan hanya pada salinan atau lingkungan yang telah disetujui.
2. Jalankan `php bin/v3_phase3_preflight.php`; selesaikan semua blocker dan simpan manifest jumlah baris.
3. Pastikan migrasi 013, 014, dan 015 telah tercatat.
4. Jalankan migrator yang ada, lalu `php bin/v3_phase3_verify.php` dan bandingkan jumlah baris.
5. Bila rollback diperlukan, jalankan berkas rollback 016, verifikasi jumlah baris tidak berubah, lalu pulihkan aplikasi ke versi sebelum Fase 3.

Drill otomatis pada database uji membuktikan pemasangan ulang idempoten, rollback bersih, preservasi baris, serta pemulihan foreign key dan penjaga unik. Migrasi/rollback produksi dan cPanel belum dijalankan oleh implementator.
