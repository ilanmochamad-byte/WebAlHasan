# PRD V3 Fase 3 — Konseling dan Tindak Lanjut Terhubung

Implementator: Codex. Auditor yang ditetapkan: Claude Code. Branch `prd-v3-fase-3`, dicabang tepat dari `main` pada `927dcd89dfa2ba53d853f7bd900805bc16b9c6de` tanggal 11 September 2026.

Paket ini mengimplementasikan tepat Fase 3: kasus konseling, beberapa sesi, tautan jamak ke pelanggaran santri yang sama, rekomendasi yang dipilih manual, timeline, koreksi/revisi, penjadwalan ulang, pembatalan, penutupan, catatan murobi, API bersama web/mobile, tampilan cetak internal, audit, dan outbox generik.

Fase 4 tidak dikerjakan. Tidak ada endpoint publikasi orang tua, tidak ada informasi konseling yang otomatis diterbitkan, dan orang tua tetap ditolak dari endpoint serta halaman internal.

## Dokumen

- [Desain dan aturan](desain-dan-aturan.md)
- [Kontrak API](kontrak-api.md)
- [Migrasi dan rollback](migrasi-dan-rollback.md)
- [Hasil pengujian](test-results.md)
- [Status penerimaan dan handoff](acceptance-status.md)
- [Catatan untuk auditor](catatan-untuk-auditor.md)
- [Manifest berkas](manifest-berkas.md)

Tidak ada merge ke `main` dan tidak ada deploy atau migrasi produksi pada tahap implementator.
