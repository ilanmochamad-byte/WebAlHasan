# Bukti audit Fase 4 — Claude Code

Auditor: Claude Code (peran `AGENTS.md` untuk PRD V3 Fase 1–5). Implementator: Codex.
Web: branch `prd-v3-fase-4`, commit diaudit `5376c9c`, baseline `03c3a05`.
Mobile: `alhasanApps` branch `prd-v3-fase-4`, commit `554553f`. Tanggal audit: 22 September 2026.

Tidak ada merge, deploy, perubahan sakelar Push/WhatsApp produksi, pekerjaan Fase 5, atau
pembersihan data smoke produksi oleh auditor.

## 1. Kesimpulan

**Fase 4 secara keseluruhan tetap BELUM TERPENUHI.** Kriteria push fisik Android+iOS belum
memiliki bukti perangkat/provider nyata, dan auditor tidak menjalankan uji fisik karena belum ada
persetujuan perangkat/provider yang sah. Sembilan kriteria lain lulus otomatis sesudah koreksi
audit di bawah.

**Catatan proses penting (A0):** saat audit dimulai, `origin/main` web sudah berisi merge
PR #41 (`353302c`, 22 September 2026 19:40) dan `origin/main` mobile sudah berisi merge PR #9
(`bd8b7f0`). Artinya kode Fase 4 sudah masuk `main` **sebelum** audit, bertentangan dengan
`AGENTS.md` ("Hanya Gabung Jika Stabil") dan handoff Fase 4 ("Jangan merge ke `main`").
Auditor tidak mengubah `main`. Koreksi audit ini hanya ada di branch `prd-v3-fase-4` dan
**belum** ada di `main`; Human Developer perlu memutuskan PR lanjutan. Jangan deploy `main`
saat ini ke cPanel karena belum memuat koreksi A1.

## 2. Temuan dan koreksi

| ID | Tingkat | Temuan | Status |
| --- | --- | --- | --- |
| A0 | Proses | Fase 4 web dan mobile sudah di-merge ke `main` sebelum audit. | Dicatat; keputusan Human Developer |
| A1 | Tinggi (privasi 5.5a) | `PublikasiService::source()` hanya memeriksa capability dan cakupan santri, tidak kepemilikan kasus Rahasia seperti Fase 3. Pembimbing lain dalam cakupan dapat (a) memanggil `GET /v3/publikasi/{id}/kelola` untuk publikasi kasus yang kini Rahasia dan memperoleh ID kasus serta riwayat internal lengkap (`sebelum_json`/`sesudah_json` berisi ringkasan, alasan penarikan); (b) mencoba menarik publikasi itu; (c) membedakan kasus/sesi Rahasia dari yang tidak ada lewat 422 "Kasus Rahasia…" vs 403. Direproduksi dengan probe pada DB uji. | **Diperbaiki**: sumber kasus/sesi Rahasia kini 403 `Sumber tidak dapat diakses` bagi non-admin bukan pemilik di semua jalur (opsi, pratinjau, terbit, kelola, tarik). Pemilik tetap dapat membuka/menarik (pemulihan invariant); admin tetap mengawasi (diaudit). |
| A2 | Sedang (privasi) | Penolakan pratinjau pelanggaran tertaut menyebut "terkait kasus Rahasia", sehingga pembimbing lain mengetahui keberadaan kasus Rahasia yang menurut 5.5a tidak boleh diketahuinya. | **Diperbaiki**: pesan netral "Pelanggaran ini tidak dapat dipratinjau atau diterbitkan kepada orang tua." (422 tetap). Sisa risiko: status 422 itu sendiri masih menandakan ada pembatasan; tidak dapat dihilangkan tanpa menyembunyikan pelanggaran yang memang boleh dilihat. |
| A3 | Sedang (integritas) | Dedup fingerprint mencocokkan isi pratinjau **asli**, bukan isi snapshot sekarang. Setelah publikasi dikoreksi, menerbitkan kembali teks asli mengembalikan ID snapshot yang sudah berisi teks koreksi, sementara respons `konten` menyatakan teks asli. Petugas mengira teks asli terbit padahal tidak. | **Diperbaiki**: dedup hanya berlaku bila snapshot aktif terakhir masih memuat ringkasan/tindak lanjut yang sama; selain itu dibuat snapshot baru yang dirantai `:r<id>`. Klik ganda sesudahnya tetap tidak menggandakan. |
| A4 | Rendah (bukti) | `hasil-pengujian.md` menyatakan Fase 1 lulus 71 pemeriksaan, tetapi pada DB uji saat ini `tests/v3_phase1_diagnostics.php` gagal 2 ("Sehat exit 0", "Setelah perbaikan exit 0") karena `bin/v3_verify.php` keluar non-nol oleh referensi yatim. Yatim tersebut (kini 72/72/18, naik dari 24/24/6) adalah outbox/audit `izin.*` yang dibuat **hari ini** oleh fixture regresi V2 (run implementator 19:11 dan run auditor 19:46), bukan data "sebelum migrasi 013" seperti label verifier. Tidak disebabkan migrasi 018 atau kode Fase 4. | Dicatat, tidak dibersihkan (sesuai larangan menghapus tanpa prosedur). Rekomendasi: perbaiki teardown fixture V2 atau label verifier di paket terpisah. |
| A5 | Rendah | `v3_publikasi_pratinjau` tidak pernah dibersihkan sesudah kedaluwarsa, sehingga teks khusus orang tua yang tidak jadi terbit tetap tersimpan. | Dicatat untuk Fase 5 (retensi/purge); tidak diubah. |

Koreksi A1–A3 berada di `app/V3/PublikasiService.php` dan `app/V3/PublikasiRepository.php`
(kolom `pembimbing_id` ditambahkan ke proyeksi sumber). Sepuluh pemeriksaan regresi baru
ditambahkan pada akhir `tests/v3_phase4_integration.php` (simulasi bukan pemilik dengan
memindahkan kepemilikan sementara, seperti uji Fase 3). Probe yang sama pada kode asli
menghasilkan kebocoran A1 dan A3 yang dijelaskan di atas.

## 3. Hal yang diperiksa dan dinilai benar

- Jalur Rahasia satu pintu: pratinjau/terbit/koreksi menolak kasus dan sesi Rahasia termasuk
  teks manual; pelanggaran yang keluarga revisinya tertaut ke kasus Rahasia ditolak;
  `Internal → Rahasia` dan penautan pelanggaran terbit ke kasus Rahasia (buat kasus, tambah
  tautan, sesi) ditolak 409 selama publikasi aktif.
- Penguncian: terbit, tarik, koreksi kasus, dan penautan memakai `lockSubject` santri/tahun yang
  sama di dalam transaksi; versi sumber diperiksa sesudah kunci. Uji konkurensi terbit/terbit
  dan terbit/kerahasiaan lulus.
- Akses wali pada query: daftar/detail/baca menggabungkan `users.wali_id`, `wali` aktif,
  `santri` aktif, `santri_wali` tidak diarsip, dan role `orang_tua` di SQL; detail juga memeriksa
  `RecipientResolver`. IDOR wali B dan relasi dicabut menghasilkan 403.
- Outbox: judul/isi generik, `data_json` hanya `{tipe, publikasi_id}`, `pengajuan_id` NULL,
  setiap baris `v3_publikasi_*` punya relasi `v3_publikasi_outbox` yang konsisten (verifier),
  WhatsApp tidak pernah dibentuk, Push hanya bila sakelar ON; dispatcher memeriksa ulang relasi
  wali sebelum provider dan menandai `AKSES_BERUBAH` permanen.
- Koreksi/penarikan: optimistic `version`, alasan ≥5 karakter, riwayat unik per versi, audit
  hanya metadata versi, reset status baca saat koreksi, detail orang tua menyembunyikan teks lama
  saat ditarik.
- Mobile (`554553f`): push `v3_publikasi` hanya membawa ID; bila belum login tujuan disimpan di
  memori dan dibuka setelah autentikasi; layar detail selalu memanggil
  `GET /v3/publikasi/{id}` (otorisasi ulang di server), membuang data saat blur/background/ganti
  akun, tanpa cache disk. Tidak ada temuan.

## 4. Pengujian independen auditor

Lingkungan: PHP 8.4, MariaDB 12.3.2 lokal, DB `webalhasan_v3_phase1_test`, fixture `sbx_*`.

| Pemeriksaan | Hasil auditor |
| --- | --- |
| `bin/v3_phase4_preflight.php`, `migrate.php status/up` | Lulus 8/8; 018 sudah terpasang, `up` tidak ada migrasi baru |
| `bin/v3_phase4_run_tests.sh` pada kode asli | **LULUS OTOMATIS** 111/111 (reproduksi klaim implementator) |
| `bin/v3_phase4_run_tests.sh` sesudah koreksi | **LULUS OTOMATIS** 121/121 (111 + 10 regresi audit). 7 dari 10 pemeriksaan baru **gagal** pada kode asli |
| `bin/v3_phase4_verify.php` | Lulus 14/14 (relasi outbox, `pengajuan_id` NULL, payload generik, WhatsApp nol) |
| Browser `tests/browser/uji-v3-fase4.mjs` (PHP built-in 127.0.0.1:8940, `PERAPIHAN_AUDIT_DB=1`) | **LULUS OTOMATIS** 34/34 |
| `bin/v3_phase2_run_tests.sh` | Lulus 172/172 |
| Fase 3 static / integration / verifier | Lulus 103 / 121 / 34 |
| `bin/v3_phase1_run_tests.sh` | 69 lulus, **2 gagal** pada `v3_phase1_diagnostics.php` — lihat A4 (yatim fixture V2, bukan Fase 4) |
| `bin/penugasan_run_all_tests.sh` (V1/V2, perapihan, kredensial, penempatan, alumni, fondasi penugasan) | **LULUS** seluruhnya, exit 0 |
| `tests/v2_phase4_concurrency.php` diulang 5× terpisah | 5/5 lulus 20/20; fluktuasi tidak tereproduksi (tidak membuktikan tidak ada) |
| Mobile `npx tsc --noEmit`, ESLint berkas berubah | Lulus, tanpa temuan |
| Uji fisik Android/iOS | **Tidak dijalankan** — tidak ada persetujuan perangkat/provider |

## 5. Yang tidak diklaim

- Push dan deep-link Android+iOS fisik: **BELUM TERPENUHI — MENUNGGU UJI FISIK**.
- Migrasi/smoke cPanel, smoke produksi Fase 3 §6, dan pembersihan data smoke produksi:
  **BELUM DIJALANKAN**; tidak ada klaim lulus.
- Pemeriksaan log produksi: belum dijalankan.
