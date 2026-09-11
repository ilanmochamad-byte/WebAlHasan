# Migrasi dan rollback Fase 3

## Artefak

- Migrasi: `database/migrations/016_v3_fase3_konseling.sql`
- Rollback: `database/rollbacks/016_v3_fase3_konseling.sql`
- Preflight: `bin/v3_phase3_preflight.php`
- Verifier: `bin/v3_phase3_verify.php`
- Runner: `bin/v3_phase3_run_tests.sh`

Migrasi 016 menambah metadata alasan koreksi/pembatalan kasus, metadata alasan jadwal ulang/pembatalan sesi, penjaga unik satu revisi langsung per sesi, serta tautan rekomendasi ke kasus berikut waktunya. Tidak ada tabel atau catatan bisnis lama yang dihapus.

Keunikan tautan yang memiliki `sesi_id=NULL` sudah dijaga sejak 013 oleh `UNIQUE tautan_unik (pelanggaran_id,kasus_id,sesi_key)` dengan kolom generated `sesi_key=COALESCE(sesi_id,0)`. Draf awal 016 memasang guard kedua yang identik (`sesi_unik_guard` dan `tautan_unik_efektif`); sesudah audit, guard itu tidak lagi dipasang (temuan K8).

Baris lama berstatus `Dijadwalkan Ulang`/`Dibatalkan` — sesi maupun kasus — yang belum memiliki kolom alasan diberi penanda konservatif bahwa rincian historis harus dirujuk dari `audit_logs`; migrasi tidak mengarang alasan bisnis.

Rollback hanya melepas indeks dan penjaga struktural milik 016: `sesi_satu_revisi`, `rekomendasi_tindak_lanjut_index`, dan guard tautan draf bila masih ada. Sebelum indeks unik revisi dilepas, rollback memulihkan indeks biasa bernama `revisi_dari_id` untuk tetap menopang foreign key pada MariaDB. Pemasangan ulang membuang nama indeks sementara dari versi awal rollback agar tidak menyisakan indeks redundan.

**Kolom keputusan bisnis sengaja dipertahankan saat rollback** (temuan K4): `v3_konseling_kasus.alasan_revisi_terakhir`, `v3_konseling_kasus.alasan_pembatalan`, `v3_konseling_sesi.alasan_penjadwalan_ulang`, `v3_konseling_sesi.alasan_pembatalan`, serta `v3_rekomendasi.ditindaklanjuti_kasus_id`/`ditindaklanjuti_pada` berikut foreign key `rekomendasi_kasus_fk`. Kolomnya nullable sehingga aman bagi kode Fase 2. Versi awal rollback membuangnya; drill audit membuktikan hal itu menghapus alasan asli sesi, memutus tautan rekomendasi ke kasus sehingga rekomendasi tampak belum ditindaklanjuti, dan membuat verifier merah sesudah pemasangan ulang. Kasus, sesi, tautan, rekomendasi, pelanggaran, dan ledger tidak dihapus.

## Urutan operator

1. Buat backup/restore point dan jalankan hanya pada salinan atau lingkungan yang telah disetujui.
2. Jalankan `php bin/v3_phase3_preflight.php`; selesaikan semua blocker dan simpan manifest jumlah baris.
3. Pastikan migrasi 013, 014, dan 015 telah tercatat.
4. Jalankan migrator yang ada, lalu `php bin/v3_phase3_verify.php` dan bandingkan jumlah baris.
5. Bila rollback diperlukan, jalankan berkas rollback 016, verifikasi jumlah baris tidak berubah, lalu pulihkan aplikasi ke versi sebelum Fase 3.

Drill otomatis pada database uji membuktikan pemasangan ulang idempoten, rollback bersih, preservasi baris, serta pemulihan foreign key dan penjaga unik. Sesudah audit, drill juga membuktikan nilai alasan kasus/sesi dan tautan rekomendasi identik sebelum dan sesudah rollback, pemasangan ulang tidak menimpa keputusan yang tersimpan, kasus batal tanpa alasan diberi penanda jujur, dan database sendiri menolak tautan tingkat kasus ganda dengan `sesi_id=NULL`. Migrasi/rollback produksi dan cPanel belum dijalankan.

## Migrasi 017 — keputusan Human Developer 11 September 2026

- Migrasi: `database/migrations/017_v3_fase3_kerahasiaan_dan_revisi.sql`
- Rollback: `database/rollbacks/017_v3_fase3_kerahasiaan_dan_revisi.sql`

Migrasi 017 aditif dan idempoten; 001–016 tidak diubah dan tidak ada `DROP`/`DELETE`. Isinya:

1. Tabel `v3_konseling_kasus_revisi` untuk koreksi kasus berbaris, dengan `UNIQUE kasus_revisi_satu_per_versi (kasus_id, versi_sebelum)`, foreign key ke `v3_konseling_kasus` dan `users`, serta `CHECK versi_sebelum > 0`.
2. Penerapan aturan penutupan pada data yang sudah ada: setiap sesi terjadwal terkini (`Dijadwalkan`/`Dijadwalkan Ulang`, belum digantikan revisi) milik kasus `Selesai`/`Dibatalkan` diubah menjadi `Dibatalkan` dengan alasan `Ditutup otomatis oleh migrasi 017: kasus sudah <status> sebelum sesi dilaksanakan.`, realisasi kosong, dan versi naik. Revisi yang sudah digantikan dibiarkan sebagai riwayat. Perubahan data migrasi ini tidak menulis `audit_logs`; jejaknya adalah alasan pada baris dan catatan `schema_migrations`. Preflight melaporkan jumlah sesi yang akan ditutup.

Aturan kerahasiaan `Internal`/`Rahasia` ditegakkan kode dan tidak memerlukan perubahan skema.

Rollback 017 hanya melepas `v3_konseling_kasus_revisi` bila tabelnya masih kosong; tabel berisi dipertahankan karena riwayat revisi adalah catatan bisnis dan aman bagi kode lama. Sesi yang sudah ditutup otomatis tidak dibuka kembali. Pada database uji, penerapan 017 menutup 13 sesi menggantung yang ditinggalkan suite sebelumnya.

Urutan operator sama seperti 016: preflight (`php bin/v3_phase3_preflight.php`), backup, migrator (menerapkan 016 lalu 017), lalu `php bin/v3_phase3_verify.php`. Drill `tests/v3_phase3_migration.php` kini melakukan rollback 017 → rollback 016 → pasang ulang keduanya dan membuktikan riwayat revisi, alasan, tautan rekomendasi, serta sesi yang sudah ditutup tetap utuh.

## Catatan untuk database yang sempat memasang draf awal 016

Hanya database uji/pengembangan yang terdampak; produksi belum pernah menjalankan 016. Pada database seperti itu `bin/v3_verify.php` dan `bin/v3_phase3_verify.php` merah karena guard tautan duplikat masih ada. Jalankan rollback 016 lalu migrator; drill `tests/v3_phase3_migration.php` melakukan hal yang sama. Rekomendasi yang tautannya sudah hilang akibat rollback versi awal **tidak dipulihkan otomatis**: statusnya tetap `Ditinjau` sehingga tidak masuk antrean dan tidak dapat ditindaklanjuti dua kali, sedangkan kasus asalnya tercatat pada `audit_logs` peristiwa `v3.konseling.kasus.dibuka`.
