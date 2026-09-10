# PRD V3 Fase 2 — Pelanggaran, Poin, dan Rekomendasi

Implementator: Codex. Auditor yang ditetapkan: Claude Code. Branch `prd-v3-fase-2`, dicabang dari `main` pada `af6285ffe31f9ca22407446b32c9785530beabaa` tanggal 10 September 2026.

Paket ini mengimplementasikan tepat Fase 2: pencatatan pelanggaran sesuai cakupan pembimbing, snapshot katalog, ledger dan agregat poin, rekomendasi manual, revisi/pembatalan, tanda mengetahui murobi, lampiran privat, API, audit, dan outbox generik. Fase 3 tidak dikerjakan; tidak ada pembuatan kasus atau sesi konseling.

## Dokumen

- [Panduan pengisian Katalog & Ambang](panduan-katalog-ambang.md) — untuk admin pesantren
- [Desain dan aturan](desain-dan-aturan.md)
- [Kontrak API](kontrak-api.md)
- [Migrasi dan rollback](migrasi-dan-rollback.md)
- [Hasil pengujian](test-results.md)
- [Status penerimaan dan handoff](acceptance-status.md)
- [Bukti audit Claude Code](audit-claude-code.md)
- [Manifest berkas](manifest-berkas.md)

Halaman `admin/admin_pelanggaran.php` tetap baca-saja dengan label **Data warisan**. Tidak ada backfill tabel warisan ke ledger V3, tidak ada merge ke `main`, dan tidak ada deploy produksi.
