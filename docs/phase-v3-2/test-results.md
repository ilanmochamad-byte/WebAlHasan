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

## Reproduksi dan koreksi auditor — Claude Code, 10 September 2026

Bagian ini menyalip daftar "Belum diuji" di atas untuk dua hal: MariaDB
hosting/cPanel dan migrasi/smoke produksi kini sudah dijalankan. Catatan
implementator sengaja dibiarkan apa adanya sebagai rekaman jujur atas batas
buktinya sendiri pada saat itu.

Pengujian dijalankan ulang secara independen pada `webalhasan_v3_phase1_test`.
Angka implementator terverifikasi: regresi 4.014 pemeriksaan pada 49 paket
direproduksi persis, begitu pula residu fixture 24 outbox dan 6 audit yatim.

| Pemeriksaan | Sebelum koreksi | Sesudah koreksi T1–T6 |
| --- | --- | --- |
| Paket Fase 2 (`bin/v3_phase2_run_tests.sh`) | 131, exit 0 | **172**, exit 0 |
| Paket Fase 1 | 71, exit 0 | 71, exit 0 |
| Drill migrasi | 9, exit 0 | **18**, exit 0 |
| Regresi V1/V2 + fondasi | 4.014 / 49 paket, exit 0 | 4.014 / 49 paket, exit 0 |
| `bin/v3_verify.php` | exit 0 | exit 0 |
| `bin/v3_phase2_verify.php` | exit 0 | exit 0 |

Satu kali jalan, `tests/v2_phase3_api_contract.php` gagal di dalam runner penuh
tepat sesudah suite V3 Fase 2 dijalankan, lalu lulus pada run bersih dan lulus
terisolasi pada `af6285f` maupun `247db8a` (116 pemeriksaan). Bergantung
urutan/fixture, bukan regresi Fase 2.

### Bukti produksi hosting cPanel

Dijalankan Human Developer sesudah koreksi T1–T5 dideploy. Migrasi 014 dan 015
terpasang tanpa galat; `bin/v3_verify.php` lulus penuh tanpa referensi yatim;
smoke test operasional menghasilkan ledger tujuh entri berpasangan dengan
`Agregat 2 · ledger 2 · selisih 0`, sehingga pemeriksaan rekonsiliasi lulus atas
data nyata. T2, T3 dua arah, dan payload notifikasi generik terbukti di sana.
T6 ditemukan pada sesi yang sama, direproduksi pada database uji, lalu diperbaiki.

Perbaikan T6 dideploy sesudahnya dan diverifikasi pada alur web produksi:
koreksi yang hanya mengisi alasan berhasil membentuk revisi, catatan sumber naik
versi, dan poin tetap `Agregat 2 · ledger 2 · selisih 0`.

Belum diuji di produksi: tanda mengetahui murobi, lampiran privat, akses lintas
cakupan, aplikasi perangkat, dan suite peramban otomatis.
