# Hasil pengujian implementator — 11 September 2026

Lingkungan: PHP 8.4.14, MariaDB 12.3.2 lokal, Chromium Playwright, database khusus `webalhasan_v3_phase1_test`, dan fixture fiktif `sbx_*`. Tidak ada akses, migrasi, deploy, atau penghapusan data produksi.

| Pemeriksaan | Hasil |
| --- | --- |
| Suite Fase 3 (`bin/v3_phase3_run_tests.sh`) | LULUS — 170 pemeriksaan, 0 gagal |
| Drill migrasi 016 | LULUS — rollback, preservasi baris, pasang ulang, idempotensi, FK, dan unique |
| Integrasi kasus/sesi | LULUS — transaksi, cakupan, tautan jamak, rekomendasi, revisi, status, privasi, audit/outbox |
| Browser + HTTP API | LULUS — 33 pemeriksaan Chromium pada viewport 1440 dan 375 px |
| Regresi Fase 2 | LULUS — termasuk T1–T6 dan konkurensi, exit 0 |
| Regresi Fase 1 | LULUS — 71 pemeriksaan, exit 0 |
| Regresi V1/V2 + fondasi penugasan | LULUS — 49 suite, 4.017 pemeriksaan, 0 gagal, 0 dilewati |
| Verifier Fase 3 | LULUS — tidak ada blocker |
| Lint PHP/JS dan pemeriksaan whitespace | LULUS |

## Bukti perilaku utama

- Satu kasus dibuat dengan beberapa pelanggaran dan dua sesi berbeda; detail pelanggaran menampilkan seluruh sesi terkini tanpa menggandakan induk.
- Rekomendasi aktif dipilih manual, tidak dapat ditautkan dua kali, dan tidak pernah otomatis membuat kasus.
- Idempotency replay menghasilkan satu dampak. Versi lama/revisi kedua ditolak dan nilai historis sesi selesai tetap utuh.
- Status terminal, jadwal ulang, pembatalan, serta penutupan diuji; kegagalan audit sintetis menggulung kasus dan outbox.
- Pembimbing/murobi terkait berhasil sesuai hak. Pembimbing lain, murobi lain, dan orang tua ditolak; DTO murobi/orang tua diperiksa tidak membawa field internal. Pembukaan detail sensitif oleh admin menghasilkan audit akses.
- Browser membuktikan API tanpa token `401`, akses silang `403`, CSRF web `419`, tidak ada mutasi GET, label formulir, tanpa luapan horizontal 375 px, tampilan internal pembimbing, dan tidak adanya rahasia pada tampilan murobi.
- Suite Fase 2 sempat menemukan fixture tanggal yang rapuh dekat tengah malam. Fixture dibuat mulai sehari sebelumnya, lalu seluruh Fase 2—termasuk uji dua proses—lulus ulang.

Screenshot browser berada sementara di `/tmp/v3-phase3-browser` dan bukan bukti produksi. Belum diuji dan tidak diklaim: MariaDB/cPanel produksi, migrasi atau smoke produksi, Safari fisik, pembaca layar nyata, aplikasi Android/iOS terpasang, push fisik, dan volume produksi.

Suite regresi lama kembali meninggalkan residu fixture yang sudah dikenal dari audit Fase 2: tepat 24 outbox `izin:*` dan 6 audit `login_succeeded` untuk akun fixture yang telah dihapus. ID, tipe event, dan relasi yatim diperiksa terlebih dahulu; hanya 30 baris fixture tersebut yang dihapus dari database uji dalam satu transaksi. Sesudahnya suite Fase 1 (71), verifier Fase 2 (30), dan verifier Fase 3 (25) kembali lulus tanpa blocker. Pemeriksaan yatim tidak dilonggarkan.
