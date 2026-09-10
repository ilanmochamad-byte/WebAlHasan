# Hasil pengujian implementator — 10 September 2026

Lingkungan: PHP 8.4.14, MariaDB 12.3.2 lokal, Chromium Playwright, database khusus `webalhasan_v3_phase1_test`, dan fixture fiktif `sbx_*`. Tidak ada akses, migrasi, atau penghapusan data produksi. Ini bukti implementator dan masih menunggu audit independen Claude Code.

| Pemeriksaan | Hasil |
| --- | --- |
| Fixture `V2_PHASE3_SEED=1` | LULUS; fixture telah tersedia di DB uji |
| Drill migrasi 014 | LULUS — 9 pemeriksaan; rollback/pasang ulang/idempoten/preservasi |
| Suite Fase 2 statis, integrasi, konkurensi, verifier | LULUS seluruhnya, exit 0 |
| Konkurensi nyata | LULUS — dua proses create idempoten, dua koreksi versi lama, dan kunci subjek A tidak memblokir subjek B |
| Browser dan HTTP API | LULUS — 40 pemeriksaan pada Chromium, viewport 1440 dan 375 px |
| Paket internal Fase 1 | LULUS — 71 pemeriksaan, 0 gagal |
| Regresi V1/V2 + fondasi | LULUS — 50 suite, 4.014 pemeriksaan, 0 gagal, 0 dilewati |
| `bin/v3_verify.php` setelah cleanup fixture | LULUS, tidak ada blocker/yatim |
| `bin/v3_phase2_verify.php` | LULUS, tidak ada blocker |
| Lint PHP/JS dan `git diff --check` | LULUS |

## Bukti perilaku utama

- Snapshot kategori/tingkat/poin, ledger, agregat, rekomendasi, idempotensi, outbox, dan audit diuji sebagai satu transaksi. Trigger kegagalan audit membuktikan tidak ada catatan parsial.
- Perubahan poin default tidak mengubah catatan lama. Koreksi membentuk revisi dan pasangan pembalik/delta; pembatalan membentuk pembalik tanpa delete. Semua total berakhir dengan selisih rekonsiliasi nol.
- Request idempoten dua proses memberi satu create dan satu replay. Dua koreksi versi lama memberi satu sukses dan satu `409`. Kunci santri/tahun A tidak menjadi gerbang global bagi B.
- Satu ambang menghasilkan satu rekomendasi dan nol kasus konseling/perubahan akademik. Ambang tahun nonaktif menghasilkan peringatan operator.
- Pembimbing/murobi terkait berhasil; pembimbing lain, murobi lain, dan orang tua diuji pada web serta API dan ditolak `403`/hasil daftar kosong sesuai operasi. Santri tanpa penempatan tetap ditolak.
- Outbox diperiksa tanpa nama, kategori, uraian, lokasi, saksi, poin, nomor telepon, atau catatan rahasia. Lampiran privat diuji sukses, lintas-cakupan, hash, staging gagal, dan tidak ada file pending yatim.
- Browser membuktikan CSRF `419`, XSS menjadi teks, label formulir, tidak ada overflow formulir pada 375 px, Bearer token, idempotency header, unduhan privat, serta tidak ada mutasi GET.
- Profil lama masih memuat `default_mode`/`menus`; menu aplikasi tidak mengambil menu web V3.

## Catatan fixture dan batas bukti

Regresi warisan sengaja menghapus akun fixture miliknya dan meninggalkan 24 outbox serta 6 audit yatim. `bin/v3_verify.php` mendeteksinya, menandai `[warisan]`, dan exit nonzero. Hanya residu fixture yang asalnya terbukti tersebut dibersihkan pada DB uji, lalu verifikator diulang hingga exit 0. Pemeriksaan yatim tidak dilonggarkan.

Screenshot browser disimpan sementara di `/tmp/v3-phase2-browser` dan ditinjau selama sesi; tidak menjadi bukti produksi. Belum diuji: MariaDB hosting/cPanel, migrasi atau smoke produksi, Safari, pembaca layar nyata, aplikasi Android/iOS terpasang, push fisik, dan performa 1.000 pelanggaran. Tidak ada klaim untuk area tersebut.

Patch cleanup lampiran validasi ditambahkan setelah regresi besar; suite Fase 2 lengkap diulang sesudah patch. Regresi V1/V2 tidak diulang lagi karena patch hanya menyentuh service dan tes V3 baru; hasil regresi 4.014 tetap dicatat dengan batas ini secara eksplisit.
