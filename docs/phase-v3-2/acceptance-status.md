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
| Audit Claude Code | **SELESAI** — lihat [bukti audit](audit-claude-code.md); 6 temuan (T1–T6) diperbaiki beserta regresinya |
| Migrasi dan smoke test hosting cPanel | **LULUS** — 014 dan 015 terpasang, `v3_verify` nol yatim, rekonsiliasi terbukti atas data nyata |
| Koreksi T1–T6 terbukti di hosting | **LULUS** — T2, T3 dua arah, dan T6 diverifikasi langsung pada alur web produksi |
| Kesiapan/deploy produksi | **Terpasang dan berjalan** — seluruh koreksi audit sudah dideploy; cakupan uji produksi masih terbatas pada alur pencatatan/koreksi/pembatalan |

## Hasil audit Claude Code

Seluruh 10 kriteria penerimaan Fase 2 terpenuhi dan kedua belas persyaratan
implementasi terimplementasi. Bukti implementator terverifikasi: regresi 4.014
pemeriksaan pada 49 paket direproduksi persis, begitu pula residu fixture 24
outbox dan 6 audit yatim yang sudah dicatat terbuka.

Enam temuan diperbaiki pada audit ini, tidak satu pun menggagalkan kriteria
penerimaan: fingerprint yang memblokir koreksi balik dan pencatatan ulang (T1),
pembatalan yang menimpa alasan koreksi (T2), rekomendasi yang menjadi basi
setelah pembatalan (T3), agregat yang tidak pulih sesudah rollback dan pasang
ulang (T4), `PRD-V3.md` yang dapat diunduh dari web (T5), dan koreksi tanpa
perubahan isi yang ditolak sebagai duplikat (T6). Migrasi 015 menyertai koreksi
tersebut. Rinciannya di [bukti audit](audit-claude-code.md).

T6 ditemukan dari smoke test produksi, bukan dari suite, karena uji regresi T1
selalu mengubah salah satu field fingerprint. Uji untuk koreksi tanpa perubahan
isi dan untuk pelampiran bukti susulan kini ditambahkan.

Migrasi dan smoke test pada hosting cPanel sudah dijalankan Human Developer dan
menutup batas bukti yang sebelumnya terbuka; T2, T3 dua arah, rekonsiliasi ledger,
dan kriteria payload notifikasi terbukti atas data nyata di sana.

Perbaikan T6 kemudian dideploy dan diverifikasi langsung di hosting: koreksi
yang hanya mengisi alasan berhasil membentuk revisi, dan rekonsiliasi poin tetap
berselisih nol.

Belum dijalankan dan tidak diklaim: tanda mengetahui murobi, lampiran privat,
dan akses lintas cakupan pada produksi; suite peramban otomatis; pembaca layar
nyata; aplikasi terpasang; push fisik; dan performa volume besar.

## Fokus audit yang diminta implementator

1. Audit rentang perubahan dari baseline `af6285f`, terutama provenance T-2 dan pembedaan koreksi admin/pembimbing.
2. Reproduksi drill 014 sebelum suite/browser. Pastikan rollback menjaga foreign key revisi dan migrasi 001–013 identik.
3. Uji independen transaksi snapshot-ledger-audit/outbox, pembalik, rekonsiliasi, idempotensi/fingerprint/version, dan dua proses konkurensi.
4. Periksa query cakupan serta akses silang pembimbing, murobi, orang tua, admin, lampiran, dan santri tanpa penempatan.
5. Periksa payload outbox/push serta allowlist seluruh serializer baru.
6. Pastikan menu aplikasi tetap dari `ApiAuthService::menus()`, halaman warisan tetap baca-saja, dan tidak ada implementasi Fase 3.

Gunakan hanya database uji berakhiran `_test` dan fixture `sbx_*`. Regresi warisan dapat meninggalkan audit/outbox yatim; jangan melonggarkan verifier. Bersihkan hanya fixture yang asalnya dapat dibuktikan, lalu ulangi verifikasi. Commit/push koreksi audit bila perlu, lalu berhenti; jangan merge atau deploy.
