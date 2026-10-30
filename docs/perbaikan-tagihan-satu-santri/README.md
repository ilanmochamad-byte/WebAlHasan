# Perbaikan: "Terbitkan tagihan" satu santri memakai tarif

**Status (30 Oktober 2026):** diimplementasikan Claude Code atas permintaan Human Developer, **menunggu audit independen Codex**. Belum merge, belum deploy, tanpa migrasi, mobile tidak diubah. Bukti berasal dari DB salinan lokal berfixture sintetis (MariaDB 12.3.2, PHP 8.4.14, Chromium). Hosting/cPanel dan MySQL belum diuji.

| Butir | Nilai |
| --- | --- |
| Asal | Pertanyaan Human Developer 30 Oktober 2026: petugas BSA diminta mengisi komponen biaya dan nominal saat menerbitkan tagihan, padahal tarif sudah diisi. Human Developer memilih "Ya, pakai tarif". |
| Peran | Implementator Claude Code, auditor Codex (sama dengan workstream PRD V6). |
| Branch | `claude/perbaikan-tagihan-satu-santri` dari `origin/main` `0b2fcd1` (merge PR #99). Tanpa worktree. |
| Migrasi | Tidak ada. |

## Masalah

Ada dua jalan menerbitkan tagihan:

- **Penerbitan** (menu Penerbitan dan aplikasi): nominal otomatis dari tarif unit ± penyesuaian santri, lewat pratinjau.
- **Terbitkan tagihan** di rincian satu santri: formulir Fase 1 (sebelum tarif ada di Fase 2) dengan komponen dan nominal diisi manual. Formulir ini tidak pernah disambungkan ke tarif, sehingga petugas mengetik ulang nominal dan tagihan dapat berbeda dari tarif resmi.

## Perubahan

- `App\Bsa\TagihanRepository::sasaranPenerbitan()` — saringan opsional satu santri (`AND s.id = ?`, parameter terikat). Pemanggil lama tidak berubah.
- `App\Bsa\TagihanService`:
  - `pratinjau()` dan `terbitkanMassal()` menerima `santri_id` opsional. Sasaran hanya santri itu; nominal tetap dihitung oleh rencana yang sama (tarif unit ± penyesuaian, satu tagihan per santri–periode, snapshot).
  - Pratinjau satu santri menambah `santri_id` dan `komponen` tarif pada barisnya. Santri nonaktif/di luar cakupan/tidak ada → 404.
  - Hash pratinjau dan hash idempotensi menambah ID santri **hanya bila ada**, sehingga hash dan perilaku penerbitan massal tidak berubah. Kunci sama untuk santri lain → 409.
  - Sebelum replay, santri diperiksa ulang masih aktif dalam cakupan (pelajaran F-1 audit V6 Fase 1).
  - `alasanTagihanManual()` (baru, hanya membaca): isian manual boleh hanya bila tarif tidak dapat dipakai untuk santri itu pada periode itu, yaitu belum ditempatkan di kelas, atau unitnya belum punya tarif berlaku. Tidak bergantung pada tagihan yang sudah ada, agar kiriman manual ulang tetap menjadi replay/409 seperti Fase 1.
- `portal/bsa_tagihan_buat.php`:
  1. pilih periode dan jatuh tempo (GET);
  2. rincian dari tarif: komponen tarif, potongan/tambahan santri, total, dan tombol **Terbitkan tagihan Rp…** (POST berhash pratinjau). Sesudah terbit, halaman membuka rincian tagihan;
  3. isian komponen manual hanya tampil bila tarif tidak dapat dipakai. Kiriman manual diperiksa ulang di server, dan untuk santri bertarif ditolak 422 tanpa tagihan.

  Bila tagihan bulan itu sudah ada, halaman menautkan tagihannya. Sebelum migrasi 028 hanya isian manual Fase 1 yang tersedia.
- `docs/api-v1.md` — `santri_id` opsional pada pratinjau/penerbitan.
- Tidak berubah: `BsaService` (termasuk `POST /v6/bsa/tagihan` manual Fase 1), hak/penugasan, audit, notifikasi, rute API, halaman Penerbitan, laporan, mobile.

## Hasil uji (lokal)

| Rangkaian | Hasil | Berkas |
| --- | --- | --- |
| Statis perbaikan `tests/v6_tagihan_satu_santri_static.php` | 24/0 | `bukti/statis-perbaikan.txt` |
| Integrasi `tests/v6_tagihan_satu_santri_integration.php` | 27/0 | `bukti/integrasi.txt` |
| HTTP web + API `tests/v6_tagihan_satu_santri_http.php` | 25/0, log 0 galat PHP | `bukti/http.txt` |
| Browser 375/768/1280 + tanpa JS `tests/browser/uji-tagihan-satu-santri.mjs` | 11/0, log 0 galat PHP | `bukti/browser.txt`, `tangkapan/` |
| **Kontrol kode lama** (uji yang sama; layanan dan halaman dikembalikan ke `0b2fcd1`) | integrasi 8/11 lalu berhenti (kode lama menerbitkan tagihan untuk seluruh santri SMP), HTTP 8/15 | `bukti/kontrol-kode-lama-*.txt` |

Integrasi mencakup:
- satu baris dengan komponen tarif + potongan;
- tepat satu tagihan, santri SMP lain tidak ikut;
- catatan penerbitan sasaran 1, audit dan pemberitahuan wali seperti massal;
- replay; tagihan kedua ditolak; kunci sama untuk santri lain 409;
- data berubah sesudah pratinjau 409;
- hak (petugas unit lain 404, nonaktif 404, admin/admin+petugas/wali/pengurus 403) dan santri dinonaktifkan sebelum replay 404;
- aturan isian manual;
- penerbitan massal sesudahnya tanpa ganda;
- verifier Fase 1–3.

HTTP mencakup:
- tampilan rincian tarif dan tanpa isian manual;
- terbit, lalu klik ganda tetap satu;
- kiriman manual lama/baru untuk santri bertarif 422;
- santri tanpa kelas/unit tanpa tarif mendapat isian manual, replay manual, dan isian tetap ada sesudah bertagihan;
- pratinjau basi;
- hak halaman;
- API dengan `santri_id`.

## Regresi

Satu jalan bersih `perintah-uji.sh`, ringkasan `bukti/ringkasan.txt`.

| Rangkaian | Hasil | Catatan |
| --- | --- | --- |
| V6 Fase 1 HTTP / probe audit / browser | 96/8 · 37/0 · 59/0 | 96/8 identik baris demi baris dengan regresi V6 Fase 5 (oracle historis "transfer tanpa centang sah") |
| V6 Fase 2 integrasi / HTTP / probe audit / browser | 179/0 · 110/0 · 30/0 · 76/0 | |
| V6 Fase 3 integrasi / HTTP / browser | 137/0 · 66/0 · 89/0 | |
| V6 Fase 4 HTTP | 63/0 | |
| V6 Fase 5 integrasi / HTTP / verifier rilis | 71/0 · 42/0 · 36/0 | |
| Seluruh suite statis | lihat di bawah | |

**Jalan pertama sebelum koreksi** (dipertahankan sebagai riwayat): aturan isian manual awalnya bergantung pada tagihan yang sudah ada. Akibatnya HTTP Fase 1 94/10 (kiriman manual ulang ditolak alih-alih replay), browser Fase 1 berhenti 17/0, dan HTTP Fase 2 108/2 (isian manual hilang untuk santri tanpa kelas yang sudah bertagihan). Aturan diubah menjadi hanya berdasarkan tarif untuk santri itu, asersi replay/"sudah bertagihan" ditambahkan, lalu seluruh regresi di atas diulang bersih.

**Suite statis:** dibandingkan dengan kontrol suite yang sama pada `main` `0b2fcd1` bersih (`bukti/kontrol-main-statis/`). Hitungan identik kecuali tiga suite. Selisihnya hanya oracle historis "berkas tidak berubah sejak baseline fase X", yang kini menyebut `TagihanService`/`TagihanRepository`/`bsa_tagihan_buat.php`:
- `v6_phase5_static` 69/5 → 67/7;
- `v7_phase3_static` 42/2 → 41/3;
- `v7_phase5_static` 56/0 → 53/3.

Oracle tidak diubah atau dilonggarkan. Kode keluar pada ringkasan kontrol `main` tidak sah (kesalahan urutan ekspansi di loop pembantu), jadi yang dibandingkan hanya hitungan dan isi kegagalan.

## Penafsiran untuk ditinjau auditor/Human Developer

- **P-1** Penerbitan satu santri tercatat sebagai satu penerbitan (`bsa_penerbitan`, sasaran 1) dan tampil di Riwayat penerbitan dengan unit "Seluruh cakupan". Tidak ada kolom baru untuk menandai "satu santri".
- **P-2** `POST /v6/bsa/tagihan` (komponen manual, Fase 1) tetap menerima santri bertarif lewat API. Pembatasan "manual hanya tanpa tarif" ditegakkan pada halaman web. Mengubah kontrak API lama memerlukan keputusan tersendiri.
- **P-3** Santri tanpa tarif yang memakai isian manual tetap dapat menerbitkan untuk bulan lain. Bila unit kemudian mendapat tarif, kiriman manual ulang dengan kunci lama ditolak 422 (bukan replay); tagihan yang sudah terbit tidak berubah.
- **P-4** Aplikasi HP tidak punya tombol terbit satu santri; penerbitan di aplikasi sudah memakai tarif (massal).

## Batas

MySQL/cPanel/produksi belum diuji. Suite V1–V5/PSB di luar V6 tidak dijalankan ulang karena kode di luar `app/Bsa` dan halaman ini tidak berubah. Konkurensi penerbitan satu santri memakai jalur transaksi massal yang sudah diuji di V6 Fase 2 dan tidak diuji ulang dengan banyak proses.

Ulangi: `bash docs/perbaikan-tagihan-satu-santri/perintah-uji.sh` (berurutan, tidak bersamaan dengan suite lain yang memakai rantai fixture V6).
