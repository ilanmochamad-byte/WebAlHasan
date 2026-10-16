# Audit Claude Code — PRD V4 Fase 1

**Tanggal:** 16 Oktober 2026 · **Auditor:** Claude Code · **Branch:** `codex/prd-v4-fase-1` · **Commit diaudit:** `197e818` (Codex)

**Putusan:** Seluruh kriteria penerimaan Fase 1 **terpenuhi** setelah dua koreksi terarah di bawah ini. Fase 2 belum dimulai.

## Lingkungan uji independen

- Instance MariaDB 12.3.2 terpisah (datadir dan socket sementara milik auditor, tanpa jaringan), bukan DB/instance yang dipakai Codex dan bukan produksi.
- DB baru `webalhasan_v4audit_test`: impor `k1807225_webalhasan.sql` → migrasi 001–019 → preflight + manifest → 020.
- PHP 8.4.14 CLI, server PHP lokal dengan `tests/v4_phase1_router.php`, Chromium Playwright 375 px.

## Hasil per kriteria

| Kriteria | Hasil audit | Bukti |
| --- | --- | --- |
| Simpan rencana dua mapel wajib, baca ulang web + API, ubah target sebelum ada nilai | Lulus | `audit-claude-integrasi.txt`, `audit-claude-http-browser.txt`, `audit-claude-375.png` |
| Mapel ganda ditolak tanpa baris tambahan | Lulus (409; jumlah baris tetap; unik juga dijaga di DB) | sama |
| Pengurus tanpa Pendidikan, guru tanpa mapel sesuai, wali ditolak 403 | **Gagal sebagian sebelum koreksi**, lulus setelah koreksi 1 | sama |
| Migrasi ulang tanpa objek ganda; 0 yatim baru; baris V1–V3 tetap | Lulus: `migrate up` kedua "Tidak ada migrasi baru"; SQL 020 diulang langsung sukses; 11 tabel V4, 0 kolom ganda; verifier exit 0, 0 yatim, semua jumlah baris lama sama | dijalankan ulang oleh auditor (log di bawah) |
| Halaman 375 px dapat dipakai; audit tanpa token/gambar mentah | Lulus setelah koreksi 2. `audit_logs` V4 hanya memuat ID, target, versi, status, dan SHA-256 aset | `audit-claude-http-browser.txt`, `audit-claude-375.png` |

Latihan rollback diulang: rollback preflight lulus pada V4 kosong → rollback menghapus 11 tabel → `up` lagi. Dengan satu baris `v4_penanda_tangan`, preflight memblokir dan `migrate.php rollback` gagal (exit 1) melalui guard SQL; baris dan catatan migrasi 020 tetap ada.

Verifier juga diperiksa sensitivitasnya: setelah fixture uji menambah baris ke `users`, `guru`, `mata_pelajaran`, dll., verifier melaporkan 8 tabel lama berubah jumlah barisnya (exit 1). Perilaku ini diharapkan, karena fixture memang menambah baris setelah manifest dibuat. Artinya verifier wajib dijalankan tepat setelah migrasi, sebelum ada data baru.

## Koreksi audit

1. **403 harus mendahului validasi (`app/V4/RencanaService.php`).** Wali, guru, atau pengurus tanpa capability `rapor.rencana` mendapat `422` bila `tahun_ajaran_id`/`kelas_id` kosong atau tidak dikenal (`GET /v4/rencana`, `POST /v4/rencana` dengan `{}`). Akibatnya kriteria "ditolak 403 pada endpoint pengaturan" tidak berlaku untuk semua permintaan, dan respons 422 vs 403 membocorkan ID semester/kelas yang valid. Perbaikannya: `requireFeature()` dijalankan lebih dulu di `options()`, `read()`, dan `save()`. Pemeriksaan cakupan per kelas/semester (`allowed`) tetap berjalan sesudahnya.
2. **Halaman setelah simpan gagal (`portal/rencana_nilai.php`).** Saat POST ditolak (mis. target 150), `$tahun`, `$kelas`, dan `$signers` belum terdefinisi. Peringatan PHP `Undefined variable` tampil di HTML (APP_DEBUG) dan formulir hilang. Perbaikannya: konteks diambil dari POST/GET sebelum `try`, rencana tersimpan dan daftar penanda tangan dimuat ulang setelah galat, dan formulir tetap tampil bersama pesan galat.

Uji regresi yang ditambahkan:
- `tests/v4_phase1_integration.php`: 12 pemeriksaan 403 untuk pengurus/guru/wali dengan parameter kosong, ID tidak dikenal, payload kosong, dan opsi. Total sekarang 30 pemeriksaan lulus, termasuk penolakan aset nonaktif.
- `tests/browser/uji-v4-fase1.mjs`: simpan gagal 422 tanpa peringatan PHP dan formulir tetap tampil, serta API tanpa parameter tetap 403 untuk tiga peran. Total 23 pemeriksaan lulus; `uji-v4-penanda.mjs` 5 pemeriksaan lulus.

`SHA256SUMS` milik Codex mencatat keadaan commit `197e818`, sehingga empat berkas yang dikoreksi tidak lagi cocok dengannya. Hash setelah audit ada di `SHA256SUMS-audit`.

## Regresi lain

- `php tests/penugasan_static.php` exit 0; `php tests/v2_phase5_static.php` exit 0; `php -l` pada berkas yang diubah dan `git diff --check` lulus.
- `php tests/phase5_static.php` exit 1, penyebabnya sama dengan yang dicatat Codex: path mobile lama sudah dipindah oleh redesain dan tidak terkait V4.

## Catatan nonblokir untuk fase berikutnya

- Setelah satu mapel memiliki nilai, setiap simpan rencana ditolak 409, termasuk perubahan penanda tangan/tempat. Sebelum Fase 4 perlu diputuskan apakah penanda tangan boleh diganti setelah penilaian dimulai.
- Simpan rencana belum memakai *optimistic version* (`version` hanya dinaikkan). Penguncian `FOR UPDATE` mencegah kerusakan data, tetapi perubahan bersamaan bisa saling menimpa tanpa peringatan. PRD mewajibkan optimistic version untuk koreksi/finalisasi; pertimbangkan juga untuk rencana pada Fase 2.
- Mengirim ulang mapel yang sudah ada diperlakukan sebagai pembaruan target (tidak menambah baris), bukan ditolak; duplikasi dalam satu permintaan ditolak 409.
- Uji browser penanda tangan menulis gambar uji ke `storage/private/v4/` (diabaikan git). Berkas dari uji Codex masih ada di checkout lokal; berkas uji auditor sudah dihapus.
- Hosting cPanel/MySQL produksi, Safari, dan perangkat fisik belum diuji; tidak ada deploy.
