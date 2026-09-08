# PRD V3 Fase 1 — Fondasi Data, Katalog, dan Akses

Implementator: Codex. Auditor yang ditetapkan: Claude Code. Branch `prd-v3-fase-1`, baseline `14548541000e0df523343d82b28650061364bb65` dari `origin/main` setelah fetch pada 8 September 2026.

Paket menyediakan administrasi kategori, katalog pelanggaran, ambang rekomendasi, capability server, API baca, skema V3, serta diagnostik. Tidak ada pencatatan pelanggaran/konseling V3, publikasi, pengiriman notifikasi baru, perubahan akademik/pembiayaan, atau kode mobile.

## Dokumen

- [Desain dan aturan](desain-dan-aturan.md)
- [Inventarisasi data lama](inventarisasi-data-lama.md)
- [Matriks capability](matriks-capability.md)
- [Skema dan migrasi/rollback](migrasi-dan-rollback.md)
- [Kontrak API](kontrak-api.md)
- [Panduan admin dan smoke cPanel](panduan-admin.md)
- [Hasil pengujian](test-results.md)
- [Status penerimaan dan handoff](acceptance-status.md)

Keputusan tambahan pengguna: URL/menu pelanggaran lama dipertahankan, tetapi pencatatan/penghapusan lama ditutup dan halaman menjadi baca-saja berlabel **Data warisan**. Dicatat dalam PRD V3 §5.2a. Checkbox penerimaan PRD tidak diubah oleh implementator.
