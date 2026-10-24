# PRD V5 Fase 1 — Penugasan Kesehatan dan Kunjungan Dasar

**Status (24 Oktober 2026):** **lulus audit independen Codex tanpa koreksi kode aplikasi** atas tiga commit fase hingga `abce969`. Bukti auditor dan tinjauan P-1…P-10: [AUDIT-CODEX.md](AUDIT-CODEX.md). Belum merge, belum deploy, belum migrasi produksi. Fase 2 belum dimulai.

| Butir | Nilai |
| --- | --- |
| Workstream | PRD V5 Fase 1–5 kesehatan santri — implementator Claude Code, auditor Codex (`AGENTS.md`) |
| Branch | `claude/prd-v5-fase-1` |
| Baseline | `origin/main` `cb7c181` (merge PR #79, memuat `85ea49d` dan `be513bf`). Diperiksa ulang sebelum push: tidak ada commit `origin/main` yang lebih baru. |
| Mobile | `~/alhasanApps` `ee6621a` hanya dibaca; tidak diubah (Fase 1 tidak memerlukan aplikasi). |
| Lingkungan uji | PHP 8.4.14, MariaDB 12.3.2 lokal, Chromium Playwright; seluruh DB `test_*_test` dengan fixture sintetis. Tidak ada data produksi yang dibuka/diubah. |

## Yang dikerjakan (persyaratan Fase 1)

1. **Konteks** — dibaca `AGENTS.md`, `PRD-V5.md`, migrasi terakhir (023), fondasi penugasan (`PenugasanJenis`, `PenugasanService`, `Capabilities`), akun/role, santri/wali, `AuditLogger`, router API, notifikasi (tidak dipakai di Fase 1).
2. **Migrasi aditif `024_v5_fase1_kesehatan.sql`** — lima tabel baru, tanpa `ALTER`/`DROP`/DML pada tabel lama:
   - `kesehatan_assignments` — penugasan pengurus sebagai petugas kesehatan (bentuk sama dengan tabel penugasan fondasi, tanpa kolom cakupan);
   - `kesehatan_kunjungan` — satu baris per kunjungan (santri, waktu, status `Draf|Tercatat`, versi, keluhan, catatan klinis, tindakan, petugas, penugasan yang dipakai, kunci idempotensi);
   - `kesehatan_kunjungan_versi` — snapshot isi tiap versi (versi 1 saat pencatatan);
   - `kesehatan_idempotensi` — klaim kunci per akun/operasi (tanpa salinan respons);
   - `kesehatan_akses_log` — siapa membuka rincian kunjungan mana, sumber hak, kanal, waktu (tanpa isi klinis).
   Rollback berpasangan `database/rollbacks/024_*.sql` melepas lima tabel itu saja.
3. **Capability** — jenis penugasan baru `kesehatan` di katalog fondasi (`PenugasanJenis::KESEHATAN`, capability `kesehatan.catat`, role dasar `pengurus`, cakupan `seluruh`). Tidak ada role login baru; bendahara bulanan lama tidak diubah. `KesehatanService::akses()` memisahkan **baca/audit admin** (sumber `admin`) dari **tulis klinis petugas** (sumber `penugasan`) di server.
4. **UI dan API**
   - Admin: menugaskan/mengakhiri/menonaktifkan lewat Pusat Penugasan yang sudah ada (`admin/admin_penugasan.php?jenis=kesehatan`); membaca daftar, detail, dan riwayat akses di menu baru **Kesehatan** (grup Kegiatan santri).
   - Petugas: menu **Klinik › Kesehatan** — daftar (`portal/kesehatan.php`), catat (`portal/kesehatan_buat.php`), detail (`portal/kesehatan_detail.php`).
   - API (token, `Cache-Control: private, no-store`):

     | Metode & jalur | Hak | Keterangan |
     | --- | --- | --- |
     | `GET /api/v1/v5/kesehatan/akses` | semua akun | `{baca, catat, sumber, riwayat_akses}` dihitung server |
     | `GET /api/v1/v5/kesehatan/santri?q=` | petugas | santri aktif untuk pemilih (≤100) |
     | `GET /api/v1/v5/kesehatan/kunjungan?q=&santri_id=&status=&page=` | petugas, admin | daftar TANPA isi klinis |
     | `POST /api/v1/v5/kesehatan/kunjungan` | petugas | badan `santri_id, waktu_kunjungan, keluhan, catatan_klinis?, tindakan?`; kunci di header `Idempotency-Key` atau `idempotency_key`; `201 {id, replayed:false}` / `200 {id, replayed:true}` / `409` |
     | `GET /api/v1/v5/kesehatan/kunjungan/{id}` | petugas, admin | rincian + isi klinis; tercatat di log akses (kanal `api`) |
     | `GET /api/v1/v5/kesehatan/kunjungan/{id}/akses` | admin | riwayat akses |
5. **Atomik, validasi, idempotensi, audit** — satu transaksi: kunci baca penugasan aktif → klaim kunci → banding isi (409) → replay → santri aktif (kunci baca) → simpan kunjungan + versi 1 → selesaikan klaim → audit `kesehatan.kunjungan.dicatat` (metadata saja). Audit gagal membatalkan transaksi. `AuditLogger` juga membuang kolom `keluhan/catatan_klinis/tindakan/alasan/catatan` untuk aksi `kesehatan.*`.
6. **Operasi** — `bin/v5_phase1_preflight.php [manifest]`, `bin/v5_phase1_verify.php [--rollback] [manifest]`, `bin/v5_phase1_rollback_preflight.php` (memblokir rollback bila tabel kesehatan berisi data), fixture `tests/v5_phase1_seed.php`, DB uji `tests/v5_phase1_db.sh`.

## Kriteria penerimaan → bukti

| Kriteria Fase 1 | Bukti |
| --- | --- |
| Admin menugaskan satu pengurus kesehatan aktif; pengurus menyimpan dan membaca tepat satu kunjungan santri aktif di website/API; admin membaca detail dan audit akses | `bukti/http.txt` H-B, H-C, H-D, H-E; `bukti/integrasi.txt` I-2, I-3, I-6 |
| Pengurus tanpa penugasan, guru non-murobi, wali, akun nonaktif ditolak mutasi; admin ditolak mutasi klinis walau dapat membaca | `bukti/integrasi.txt` I-1, I-5; `bukti/http.txt` H-A, H-C, H-D, H-G (juga pembimbing, murobi, admin+petugas) |
| Kunci idempotensi sama + klik ganda → tepat satu kunjungan; kunci sama isi beda → ditolak tanpa perubahan | `bukti/integrasi.txt` I-4, I-9 (8 proses bersamaan → 1; 6 proses isi beda → 1 + 5×409); `bukti/http.txt` H-C, H-D; `bukti/browser.txt` B-C (dobel klik) |
| Penugasan diakhiri saat sesi petugas aktif → tulis berikutnya ditolak tanpa baris | `bukti/integrasi.txt` I-8 (nonaktifkan; akhiri tanggal kemarin); `bukti/http.txt` H-F (formulir terbuka + token lama) |
| Migrasi & rollback pada salinan menjaga baris/ID/relasi santri–wali dan V1–V4; verifier 0 referensi yatim | `bukti/migrasi-rollback.txt` M-1…M-8 (manifest seluruh tabel lama, `CHECKSUM TABLE` 17 tabel inti, 18 kunci asing, 0 yatim, idempoten, siklus naik–turun–naik) |
| 375 px tanpa gulir horizontal; satu kunjungan dikirim dan dibaca ulang; log umum/audit memuat 0 isi klinis | `bukti/browser.txt` B-A…B-E (375/768/1280, tanpa JavaScript), `tangkapan/*.png`; `bukti/http.txt` H-H; `bukti/integrasi.txt` I-10 |

## Hasil pengujian (lokal)

| Rangkaian | Hasil |
| --- | --- |
| Statis V5 Fase 1 `tests/v5_phase1_static.php` | 48/0 |
| Integrasi layanan + konkurensi `tests/v5_phase1_integration.php` | 75/0 |
| HTTP web + API `tests/v5_phase1_http.php` | 82/0 |
| Migrasi/rollback salinan `tests/v5_phase1_migration.php` | 26/0 |
| Browser 375/768/1280 + tanpa JS `tests/browser/uji-v5-fase1.mjs` | 35/0, log server tanpa galat PHP |
| Regresi fondasi penugasan `tests/penugasan_integration.php` | 161/0 |
| Regresi HTTP redesain Tahap 5 / Tahap 7 | 106/0 / 122/0 |
| Regresi HTTP redesain Tahap 6 pada salinan yang 024-nya di-rollback | 132/0 |
| Regresi browser kerangka sembilan akun × 375/768/1280 | 107/0; akun petugas + admin+petugas 79/0 |
| Seluruh 31 suite statis, banding baseline `cb7c181` di checkout yang sama | lihat `bukti/regresi/statis-banding.txt` dan oracle historis di bawah |

Cara mengulang:

```bash
php tests/v5_phase1_static.php
bash tests/v5_phase1_db.sh && V5_RUN_INTEGRATION=1 php tests/v5_phase1_integration.php
V5F1_DB=test_v5_f1_http_test bash tests/v5_phase1_db.sh && V5F1_DB=test_v5_f1_http_test php tests/v5_phase1_http.php
V5_RUN_MIGRATION=1 php tests/v5_phase1_migration.php
V5F1_DB=test_v5_f1_browser_test bash tests/v5_phase1_db.sh
DB_NAME=test_v5_f1_browser_test php -S 127.0.0.1:8996 tests/redesain_web_tahap1_router.php &
BASE_URL=http://127.0.0.1:8996 node tests/browser/uji-v5-fase1.mjs
```

## Oracle historis yang kini gagal (tidak diubah, tidak dilonggarkan)

Kegagalan berikut muncul karena Fase 1 **memang** menambah jenis penugasan, migrasi, rute API, dan menu sesuai PRD V5; perilaku yang dilindungi oracle tetap diuji ulang di atas.

| Suite | Pemeriksaan | Sebab |
| --- | --- | --- |
| `penugasan_static` (283/0 → 281/2) | FS-2, FS-4 "tujuh jenis penugasan" | Jenis kedelapan `kesehatan` (PRD V5 §5.2 mewajibkan aditif). Integrasi fondasi tetap 161/0. |
| `redesain_web_tahap6_static` (141/0 → 140/1) | "Navigation dan app/Auth tidak diubah Tahap 6" | Menu Kesehatan di `Navigation`. `app/Auth` tidak diubah. |
| `redesain_web_tahap7_static` (102/0 → 100/2) | `PenugasanJenis` tidak berubah; `api/`, `database/`, `bin/` tidak berubah | Katalog, migrasi 024, rute API, skrip `bin/v5_*`. |
| `redesain_web_tahap8_static` (80/0 → 78/2 pada commit final) | `database` dan `api` tidak berubah dari baseline | Migrasi/rollback 024 dan rute `/v5/kesehatan`. Bukti implementator 79/1 dipertahankan; auditor mengoreksi catatan hasil commit final, tanpa mengubah oracle. |
| `redesain_web_tahap2_http` (191/0 → 190/1) | H2-A "admin 17 menu" | Menu admin **Kesehatan**, dipertahankan oleh keputusan Human Developer BB (P-1). |
| `redesain_web_tahap6_http` (132/0 → 125/7) | H6-A rollback mengasumsikan 023 migrasi terakhir | Rollback kini melepas 024 dahulu. Lulus 132/0 bila 024 di-rollback lebih dahulu (`bukti/regresi/redesain-tahap6-http-tanpa-024.txt`). |

Kegagalan statis lain sama persis dengan baseline: `keamanan_psb_static` 82/1, `redesain_web_tahap3_static` 151/1, `redesain_web_tahap4_static` 95/1 (oracle historis yang sudah dicatat audit sebelumnya); `v3_phase2_static` dan `v3_phase3_static` keluar 77 (dilewati) di keduanya.

## Penafsiran yang perlu ditinjau auditor/Human Developer

P-1 sudah diputuskan Human Developer; P-2…P-10 telah ditinjau dan diterima auditor untuk batas Fase 1 (rincian di [AUDIT-CODEX.md](AUDIT-CODEX.md)). Ini tidak mengubah persyaratan fase berikutnya.


- **P-1 Menu admin Kesehatan — DIPUTUSKAN Human Developer 24 Oktober 2026 (keputusan BB).** Menu baru "Kesehatan" di grup Kegiatan santri **dipertahankan**; admin kini punya 18 menu (A–E semula 17). Oracle Tahap 2 "admin 17 menu" tetap tidak diubah dan dicatat sebagai kegagalan yang diharapkan.
- **P-2 Masa berlaku penugasan.** Kolom `tahun_ajaran_id` dipertahankan agar sama dengan fondasi, tetapi `wajib_tahun_aktif = false`: penugasan kesehatan tidak gugur saat semester berganti; berlaku sampai tanggal selesai atau dinonaktifkan.
- **P-3 Admin yang juga petugas.** Akun ber-role admin yang memegang penugasan kesehatan (`keduanya`) tetap **ditolak** mutasi klinis (tafsir ketat §5.1 "admin tidak mengubah isi klinis"); baca tercatat sebagai `admin_petugas`. Petugas klinik memakai akun pengurus tanpa role admin.
- **P-4 Akhiri vs nonaktifkan.** Mengikuti semantik fondasi, "Akhiri" dengan tanggal selesai hari ini tetap memberi hak sampai akhir hari itu. Pencabutan seketika = "Nonaktifkan" atau tanggal selesai kemarin (keduanya diuji).
- **P-5 Status.** Fase 1 hanya mencatat status `Tercatat`; nilai `Draf` sudah ada di skema, alurnya (beserta penyuntingan) bersama koreksi berversi Fase 2.
- **P-6 Paparan isi klinis.** Daftar (web/API) tidak memuat isi klinis; isi hanya di detail, dan **setiap** pembukaan detail (petugas maupun admin) tercatat di log akses. Bila log akses gagal ditulis, detail tidak dibuka. Respons `POST` API hanya `{id, replayed}`.
- **P-7 Cakupan baca petugas.** Petugas berpenugasan aktif membaca seluruh kunjungan semua santri (termasuk santri yang kemudian nonaktif), sesuai "seluruh santri aktif yang dapat datang ke klinik".
- **P-8 Idempotensi.** Klaim tidak menyimpan salinan respons; replay membaca ulang dengan otorisasi saat itu. Replay setelah penugasan berakhir dijawab 403. Kunci berlaku per akun.
- **P-9 Batas isian.** Keluhan wajib ≤1.000 karakter, catatan klinis ≤4.000, tindakan ≤2.000; waktu kunjungan tidak di masa depan (toleransi 5 menit) dan tidak sebelum tahun 2000. Formulir web yang gagal validasi dirender ulang langsung dari POST (isi klinis tidak disimpan ke sesi).
- **P-10 Nama kolom log akses** `aksi` (bukan `tindakan`) agar tidak rancu dengan kolom klinis.

## Batas yang belum diuji / terbuka

- Tidak ada uji cPanel, produksi, atau MySQL non-MariaDB (lokal MariaDB 12.3.2). `LOCK IN SHARE MODE` dan `CHECK` didukung MySQL 8.0.16+/MariaDB, tetapi belum dijalankan di hosting.
- Auditor telah menguji penyimpanan yang menunggu transaksi pencabutan penugasan: setelah pencabutan commit, pekerja ditolak 403 dan tidak ada baris baru. Deadlock dari urutan kunci berbeda belum direproduksi; kode memetakan galat 1205/1213 menjadi 409. Uji tersebut bukan bukti seluruh kemungkinan interleaving telah diuji.
- Safari fisik, pembaca layar, perangkat iOS/Android, dan aplikasi mobile belum diuji (aplikasi = Fase 4).
- Performa daftar pada volume besar belum diukur (indeks `santri_id, waktu_kunjungan` dan `status, waktu_kunjungan` tersedia).
- Obat, rujukan, tindak lanjut, koreksi berversi, pembaca pembimbing/murobi (Fase 2), ringkasan wali dan notifikasi (Fase 3) **belum** dikerjakan.

## Langkah rilis (untuk keputusan Human Developer kelak — bukan izin deploy)

1. Backup terverifikasi DB dan berkas.
2. `php bin/v5_phase1_preflight.php /jalur/aman/manifest-024.json`
3. `php bin/migrate.php up` (menerapkan 024).
4. `php bin/v5_phase1_verify.php /jalur/aman/manifest-024.json` — wajib tanpa `[gagal]`.
5. Admin menugaskan petugas di Pengaturan › Penugasan › Petugas Kesehatan.

Rollback hanya bila `php bin/v5_phase1_rollback_preflight.php` lulus (tabel kesehatan kosong); lalu `php bin/migrate.php rollback` dan `php bin/v5_phase1_verify.php --rollback manifest`. Bila sudah ada data kesehatan, pulihkan backup atau buat migrasi korektif — jangan rollback. Kode Fase 1 tetap berjalan sebelum migrasi dan sesudah rollback (halaman/API kesehatan menjawab "belum dipasang", pusat penugasan tetap terbuka, menu Kesehatan disembunyikan).

## Handoff ke auditor

> *"Audit implementasi Fase 1 PRD V5 yang dibuat Claude Code pada branch `claude/prd-v5-fase-1`. Baca AGENTS.md, PRD-V5.md, dan docs/phase-v5-1/README.md, periksa seluruh commit fase di atas `cb7c181`, jalankan pengujian secara independen, dan lakukan koreksi terarah jika diperlukan. Commit dan push hasil audit, lalu berhenti. Jangan melanjutkan ke Fase 2 sebelum seluruh kriteria penerimaan Fase 1 terpenuhi."*

Implementator berhenti di sini: tidak merge, tidak deploy, tidak migrasi produksi, tidak memulai Fase 2.
