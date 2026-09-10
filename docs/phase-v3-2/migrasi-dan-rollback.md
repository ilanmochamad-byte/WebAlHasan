# Migrasi dan rollback Fase 2

Migrasi baru adalah `database/migrations/014_v3_fase2_pelanggaran.sql`, dengan rollback berpasangan. Migrasi 001–013 tidak diubah.

## Tambahan struktur

- Indeks unik `pelanggaran_satu_revisi` pada `v3_pelanggaran.revisi_dari_id` mencegah dua koreksi bersamaan menghasilkan dua pengganti.
- `v3_poin_agregat` menyimpan total hasil rekonsiliasi dan menjadi kunci baris unik per santri/tahun ajaran.
- `v3_rekomendasi` menyimpan snapshot ambang/rekomendasi, unik per santri/tahun/ambang dan event.

Rollback menghapus dua tabel Fase 2 dan indeks unik revisi. Sebelum melepas indeks unik, rollback memastikan indeks biasa `pelanggaran_revisi_fk` tersedia agar foreign key swa-rujuk tetap valid pada MariaDB. Tabel Fase 1, tabel warisan, ledger, pelanggaran, dan audit tidak dihapus.

Rollback skema tetap destruktif terhadap isi agregat/rekomendasi Fase 2. Di produksi, utamakan rollback kode dan biarkan tabel tambahan; rollback skema hanya setelah backup dan keputusan operator. Drill lokal membuktikan runner ulang, rollback, preservasi tabel Fase 1/warisan, pemasangan ulang, dan idempotensi runner. Drill dilakukan hanya pada `webalhasan_v3_phase1_test` dan sebelum suite/browser.

`bin/v3_verify.php` tetap memeriksa jumlah constraint secara eksak. Ketika 014 tercatat, ekspektasi unik `v3_pelanggaran` bertambah tepat satu. Referensi yatim tabel `v3_*` maupun warisan tetap membuat exit nonzero; tabel warisan hanya diberi label `[warisan]` terpisah.
