# Risiko dan pengujian tertunda

| Temuan | Status dan tindakan |
| --- | --- |
| A4 fixture V2 meninggalkan orphan | Penyebab teardown menghapus pengajuan/users tanpa membersihkan outbox/receipt/audit fixture. Tiga fixture dikoreksi dengan ID yang benar-benar dibuat run itu, guard database uji, dan cleanup anak lebih dahulu. Dua regresi penuh + uji terarah menunjukkan residu tetap 96 outbox InApp izin dan 24 audit login; tidak bertambah dan tidak dihapus. Verifier tetap nonzero. Audit Claude Code membuktikan asal dengan reproduksi: fixture V2 versi baseline pada salinan DB uji menambah baris yatim berjenis sama (outbox `izin.*` InApp dan `login_succeeded`), versi terkoreksi menambah nol ([audit](audit-claude-code.md)). **BELUM TERPENUHI** untuk referensi yatim nol seluruh DB; auditor/operator dapat menginventaris ulang atau membuat DB uji baru dari migrasi/fixture yang sudah dikoreksi. Tidak ada akses/penghapusan produksi. |
| A5 pratinjau kedaluwarsa | Kebijakan >7 hari, dry-run, admin, batch ≤500, audit dan rollback diuji; snapshot/riwayat tetap. Operasional hosting/purge produksi **MENUNGGU PRODUKSI**. |
| A6 pesan 403 mobile V2 | Pesan netral diperbaiki; browser UI akses silang menguji penolakan. Uji OS fisik **MENUNGGU UJI FISIK**. |
| Receipt akhir push | Tool inspeksi hanya status/kode/jumlah; worker lama mendukung receipt. Status OK lokal berasal dari fake provider; bukan pengiriman nyata. Receipt produksi dan Android/iOS Fase 5 **MENUNGGU UJI FISIK / MENUNGGU PRODUKSI**. |
| Smoke Fase 3 dan pembersihan smoke | Tetap **MENUNGGU PRODUKSI**, mengikuti panduan operator; tidak diklaim selesai. |
| Data audit lama | Audit V3 baru meredaksi teks privat menjadi hash. Tidak ada migrasi pembersihan audit historis; review/retensi wajib oleh operator tanpa menghapus sejarah wajib. |
| Performa lingkungan setara produksi | 1.000 data diuji lokal; hosting/staging setara produksi **MENUNGGU PRODUKSI**. |
| Penerimaan pengguna 95%, aksesibilitas/keyboard/perangkat | Expo web adalah bukti browser, bukan OS native/pembaca layar nyata/UAT. **MENUNGGU UJI FISIK**. |
| Audit independen | Selesai 27 September 2026 ([audit](audit-claude-code.md)); K1 mobile dan K2 audit katalog dikoreksi. Tidak boleh merge sampai kriteria wajib terpenuhi. |
| Flakiness `v2_phase3_api_contract` | Sekali gagal di blok concurrency pada run penuh audit (pengajuan race tidak terbentuk); lulus pada lima run terpisah. Offset tanggal acak. Belum ditelusuri; bukan perubahan Fase 5. |

Seluruh fase belum dinyatakan selesai. Jangan menghapus data lama agar verifier lulus. Sakelar Push ON/WhatsApp OFF tidak berubah. Tidak ada uji/migrasi/deploy pada produksi yang dihitung sebagai bukti sesi ini.

Catatan lingkungan uji Expo: pada percobaan awal, cache/config perangkat tetap menunjuk API nonlokal meskipun proses dijalankan dengan URL lokal. Login fixture sintetis gagal dari browser; apakah request mencapai server tidak diverifikasi. Tidak ada hasil/mutasi bisnis produksi yang diklaim. Setelah diketahui, harness memblokir jaringan luar dan mengalihkan seluruh request API ke localhost. Hanya run akhir terisolasi yang menjadi bukti UI. Konfigurasi/credential perangkat yang ada tidak diubah atau dimasukkan ke commit.
