# Status penerimaan dan handoff Fase 2

Branch `prd-v3-fase-2`, baseline `af6285ffe31f9ca22407446b32c9785530beabaa` dari `main`.

**Implementasi dan pengujian lokal selesai. Belum dinyatakan lulus audit akhir atau siap produksi.** Claude Code adalah auditor yang ditetapkan. Codex berhenti setelah commit/push dan tidak melanjutkan Fase 3.

| Kriteria PRD Fase 2 | Status implementator |
| --- | --- |
| Pembimbing mencatat hanya santri dalam cakupan | LULUS lokal — query pilihan, create, daftar/detail, dan akses silang |
| Retry idempotency menghasilkan satu dampak | LULUS — service, unique DB, dan dua proses nyata |
| Snapshot lama tidak berubah saat katalog berubah | LULUS |
| Akumulasi sama dengan seluruh ledger sah | LULUS; verifier selisih nol |
| Ambang memberi satu rekomendasi, nol otomatisasi | LULUS |
| Koreksi/pembatalan beralasan tanpa menghapus riwayat | LULUS — revisi dan pembalik ledger |
| Dua koreksi versi lama | LULUS — satu sukses, satu `409` |
| Murobi terkait mengetahui; akses silang `403` | LULUS web/API dan integrasi |
| Outbox/push tanpa payload sensitif | LULUS inspeksi seluruh outbox V3 fixture |
| Nol kebocoran lintas pembimbing/murobi/orang tua | LULUS pada fixture dan serializer yang diuji |
| Dua belas persyaratan implementasi | TERIMPLEMENTASI dan lulus bukti lokal; menunggu audit independen |
| Regresi Fase 1 dan V1/V2/fondasi | LULUS — 71 serta 50 suite/4.014 pemeriksaan |
| Audit Claude Code | **BELUM** |
| Kesiapan/deploy produksi | **BELUM dan di luar izin tugas** |

## Fokus audit Claude Code

1. Audit rentang perubahan dari baseline `af6285f`, terutama provenance T-2 dan pembedaan koreksi admin/pembimbing.
2. Reproduksi drill 014 sebelum suite/browser. Pastikan rollback menjaga foreign key revisi dan migrasi 001–013 identik.
3. Uji independen transaksi snapshot-ledger-audit/outbox, pembalik, rekonsiliasi, idempotensi/fingerprint/version, dan dua proses konkurensi.
4. Periksa query cakupan serta akses silang pembimbing, murobi, orang tua, admin, lampiran, dan santri tanpa penempatan.
5. Periksa payload outbox/push serta allowlist seluruh serializer baru.
6. Pastikan menu aplikasi tetap dari `ApiAuthService::menus()`, halaman warisan tetap baca-saja, dan tidak ada implementasi Fase 3.

Gunakan hanya database uji berakhiran `_test` dan fixture `sbx_*`. Regresi warisan dapat meninggalkan audit/outbox yatim; jangan melonggarkan verifier. Bersihkan hanya fixture yang asalnya dapat dibuktikan, lalu ulangi verifikasi. Commit/push koreksi audit bila perlu, lalu berhenti; jangan merge atau deploy.
