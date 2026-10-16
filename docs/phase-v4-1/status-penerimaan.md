# Status implementasi Fase 1 PRD V4 — untuk audit Claude Code

**Status:** implementasi dan uji mandiri selesai pada 16 Oktober 2026. **Audit Claude Code selesai 16 Oktober 2026: seluruh kriteria Fase 1 terpenuhi setelah dua koreksi terarah** (403 sebelum validasi; halaman setelah simpan gagal). Lihat `audit-claude.md`. Penggabungan ke `main` menunggu keputusan Human Developer. Branch `codex/prd-v4-fase-1` dibuat dari `be11e2e` dan hanya memuat Fase 1. Mobile `main` (`dfae26d`) tidak diubah.

| Kriteria PRD | Hasil uji mandiri | Bukti |
| --- | --- | --- |
| Admin/Pendidikan menyimpan dua mapel wajib, baca ulang web/API, ubah target sebelum nilai | Lulus pada MariaDB uji dan Chromium 375 px | `integrasi-mariadb.txt`, `http-browser-375.txt`, `browser-375.png` |
| Mapel ganda ditolak tanpa baris tambahan | Lulus 409; jumlah baris tetap | `integrasi-mariadb.txt`, `http-browser-375.txt` |
| Pengurus tanpa Pendidikan, guru tanpa penugasan mapel sesuai, wali ditolak 403 pada pengaturan | Lulus pada layanan dan HTTP API nyata; guru mapel berbeda 403 pada capability input | `integrasi-mariadb.txt`, `http-browser-375.txt` |
| Migrasi ulang tanpa objek ganda; yatim baru 0; jumlah baris V1–V3 tetap | Lulus pada salinan DB terpisah; SQL 020 ulang = 11 tabel, 0 kolom ganda; verifier 0 yatim V4 dan jumlah bisnis lama sama | `verifier-schema-uji.txt`, `manifest-schema-uji.json`, `migrasi-rollback.md` |
| Halaman 375 px dapat dipakai; audit aman | Lulus Chromium: pilih, baca, ubah target, pilih guru, unggah dan nonaktifkan penanda tangan; tidak ada overflow horizontal termasuk saat teks diperbesar. Audit hanya metadata ID/hash, tanpa token/gambar mentah | `http-browser-375.txt`, `penanda-browser.txt`, `integrasi-mariadb.txt`, `audit-uji.txt`, `browser-375.png` |

Pengaman tambahan yang diuji: target >100 dan bobot selain 40/30/30 ditolak 422; aset tanda tangan nonaktif ditolak 422; pengganti Pendidikan dan tindakan nilai admin tanpa alasan ditolak 422. Rollback menolak DB V4 berisi data. Skema menjaga target 0–100, bobot tetap 100%, dan unik rencana/mapel di database.

Perintah uji, batas hasil, dan langkah rilis ada di `hasil-pengujian.md`, `risiko-terbuka.md`, dan `migrasi-rollback.md`. Fase 1 ditutup oleh auditor; tidak ada klaim pengujian produksi.
