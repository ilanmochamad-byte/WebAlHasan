# Migrasi dan rollback Fase 2

Migrasi baru adalah `database/migrations/014_v3_fase2_pelanggaran.sql`, dengan rollback berpasangan. Migrasi 001–013 tidak diubah.

## Tambahan struktur

- Indeks unik `pelanggaran_satu_revisi` pada `v3_pelanggaran.revisi_dari_id` mencegah dua koreksi bersamaan menghasilkan dua pengganti.
- `v3_poin_agregat` menyimpan total hasil rekonsiliasi dan menjadi kunci baris unik per santri/tahun ajaran.
- `v3_rekomendasi` menyimpan snapshot ambang/rekomendasi, unik per santri/tahun/ambang dan event.

Rollback menghapus dua tabel Fase 2 dan indeks unik revisi. Sebelum melepas indeks unik, rollback memastikan indeks biasa `pelanggaran_revisi_fk` tersedia agar foreign key swa-rujuk tetap valid pada MariaDB. Tabel Fase 1, tabel warisan, ledger, pelanggaran, dan audit tidak dihapus.

Rollback skema tetap destruktif terhadap isi agregat/rekomendasi Fase 2. Di produksi, utamakan rollback kode dan biarkan tabel tambahan; rollback skema hanya setelah backup dan keputusan operator. Drill lokal membuktikan runner ulang, rollback, preservasi tabel Fase 1/warisan, pemasangan ulang, dan idempotensi runner. Drill dilakukan hanya pada `webalhasan_v3_phase1_test` dan sebelum suite/browser.

`bin/v3_verify.php` tetap memeriksa jumlah constraint secara eksak. Ketika 014 tercatat, ekspektasi unik `v3_pelanggaran` bertambah tepat satu. Referensi yatim tabel `v3_*` maupun warisan tetap membuat exit nonzero; tabel warisan hanya diberi label `[warisan]` terpisah.

## Migrasi 015 (koreksi audit Claude Code)

`database/migrations/015_v3_fase2_koreksi_dan_rekomendasi.sql` aditif dan
idempoten; 001–014 tidak diubah. Menambah `v3_pelanggaran.alasan_pembatalan`,
`v3_rekomendasi.tidak_berlaku_pada`/`tidak_berlaku_alasan` beserta
`rekomendasi_berlaku_index`, memindahkan alasan pembatalan lama dari
`alasan_revisi`, melepas `fingerprint` catatan yang sudah digantikan atau
dibatalkan, mem-backfill `v3_poin_agregat` dari ledger, lalu menandai rekomendasi
yang totalnya sudah di luar rentang ambang. Tidak ada `DROP TABLE`/`DELETE FROM`.

Backfill agregat membuat pemasangan ulang 014 swa-pulih: rollback 014 membuang
isi `v3_poin_agregat` sementara ledger utuh, dan 015 mengembalikannya tanpa
mutasi baru. Untuk pemulihan di luar jalur migrasi tersedia
`php bin/v3_rekonsiliasi_agregat.php --dry-run` lalu tanpa `--dry-run`; keduanya
membaca ledger sebagai sumber kebenaran dan menutup dengan post-check selisih.

Rollback 015 hanya melepas struktur miliknya. Kolom `alasan_pembatalan` terbuang
bersama isinya; ketika 015 dipasang lagi, catatan batal yang alasannya tidak ada
lagi di baris **tidak dikarang ulang** melainkan diberi penanda bahwa nilainya
tidak tersedia, sedangkan alasan aslinya tetap tersimpan pada `audit_logs`
peristiwa pembatalan. Seperti 014, utamakan rollback kode di produksi dan lakukan
rollback skema hanya setelah backup serta keputusan operator.
