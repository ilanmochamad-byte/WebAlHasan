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

## Reproduksi dan koreksi auditor — Claude Code, 11 September 2026

Catatan implementator di atas sengaja dibiarkan apa adanya sebagai rekaman jujur atas bukti pada saat itu.

Lingkungan: PHP 8.4.14, MariaDB 12.3.2, Node 26.7.0, Playwright 1.62.1 Chromium, database `webalhasan_v3_phase1_test`. Baseline dijalankan pada salinan `git archive e13787f` agar tidak tercampur koreksi audit.

| Pemeriksaan | Sebelum koreksi (e13787f) | Sesudah koreksi K1–K11 |
| --- | --- | --- |
| Suite Fase 3 | 170, exit 0 | **255**, exit 0 |
| — preflight / statis / drill 016 / integrasi / verifier | 14 / 76 / 13 / 42 / 25 | 14 / 94 / 19 / 99 / 29 |
| Suite Fase 2 (T1–T6, konkurensi) | 172, exit 0 | 172, exit 0 |
| Suite Fase 1 | 71, exit 0 | 71, exit 0 |
| Regresi V1/V2 + fondasi penugasan | 49 suite / 4.017, 0 gagal, 0 dilewati | 49 suite / 4.017, 0 gagal, 0 dilewati |
| Browser + HTTP API (1440 & 375 px) | 33, exit 0 | **46**, exit 0 |
| `v3_verify` / `v3_phase2_verify` / `v3_phase3_verify` akhir | — | 284 / 30 / 29, exit 0 |
| Probe konkurensi dua proses nyata | — | satu dampak pada create idempoten, koreksi sesi, dan penutupan kasus |
| Lint PHP/JS dan `git diff --check` | — | LULUS |

Tambahan bukti sesudah koreksi: sesi pada kasus tertutup dibekukan (`422`), rekomendasi kembali ke antrean saat kasus dibatalkan dan dapat ditautkan ke kasus berjalan, tindak lanjut tetap tampil pada revisi pelanggaran terkini, formulir web tidak menghapus rencana tindak lanjut, koreksi tidak dapat mengosongkan isi wajib sesi selesai, dua perubahan status menghasilkan dua notifikasi generik, kegagalan outbox menggulung transaksi, drill 016 mempertahankan nilai alasan dan tautan rekomendasi, database menolak tautan tingkat kasus ganda dengan `sesi_id=NULL`, audit akses admin satu kali per pembukaan halaman, serta label dan luapan halaman detail lulus pada 375 px.

Pada putaran pertama sesudah koreksi, verifier Fase 2 dan diagnostik Fase 1 sempat gagal karena artefak lingkungan audit (lampiran baseline tertulis ke storage salinan, dan residu regresi warisan dari run baseline). Keduanya dibuktikan, dipulihkan, lalu kedua paket lulus ulang tanpa perubahan kode. Dua kali regresi penuh meninggalkan tepat 24 outbox + 6 audit per run (48 + 12); asal setiap baris dibuktikan per jendela waktu run sebelum hanya ID tersebut dihapus. Rincian di [bukti audit](audit-claude-code.md) bagian 2 dan 8.

Belum diuji dan tidak diklaim: migrasi 016 dan smoke pada hosting cPanel, Safari, pembaca layar nyata, aplikasi Android/iOS terpasang, push fisik, dan volume produksi.

## Penerapan keputusan Human Developer — 11 September 2026

Kerahasiaan `Internal`/`Rahasia`, penutupan otomatis sesi terjadwal, dan revisi kasus diterapkan dengan migrasi 017 (lihat [bukti audit](audit-claude-code.md) bagian 9). Migrasi 017 diterapkan pada database uji dengan `php bin/migrate.php up` dan menutup 13 sesi terjadwal lama pada kasus yang sudah ditutup.

| Pemeriksaan | Sesudah K1–K11 | Sesudah keputusan HD |
| --- | --- | --- |
| Suite Fase 3 | 255, exit 0 | **297**, exit 0 |
| — preflight / statis / drill 016+017 / integrasi / verifier | 14 / 94 / 19 / 99 / 29 | 15 / 103 / 24 / 121 / 34 |
| Suite Fase 2 | 172, exit 0 | 172, exit 0 |
| Suite Fase 1 | 71, exit 0 | 71, exit 0 |
| Regresi V1/V2 + fondasi penugasan | 49 suite / 4.017 | 49 suite / 4.017, 0 gagal, 0 dilewati |
| Browser + HTTP API (1440 & 375 px) | 46, exit 0 | **53**, exit 0 |
| `v3_verify` / `v3_phase2_verify` / `v3_phase3_verify` akhir | 284 / 30 / 29, exit 0 | 286 / 30 / 34, exit 0 |

Bukti perilaku baru: murobi terkait membaca isi kasus Internal pada API, website, dan cetak; kasus Rahasia tertolak `403` bagi murobi dan pembimbing bukan pemilik, tidak muncul pada daftar maupun detail pelanggaran, tidak dapat diberi tanda mengetahui, dan tidak menghasilkan notifikasi murobi; admin tetap dapat membukanya dengan audit akses; penutupan `Selesai` dan `Dibatalkan` menutup sesi terjadwal sebagai `Dibatalkan` beralasan sistem dan beraudit; koreksi kasus membentuk tepat satu revisi per versi, ditampilkan pada detail/API/cetak, versi lama `409`, dan database menolak revisi ganda. Putaran pertama suite gagal 2 pemeriksaan karena uji kegagalan outbox memakai kasus Rahasia yang kini sengaja tidak menulis outbox murobi; uji diperbaiki memakai kasus Internal.
