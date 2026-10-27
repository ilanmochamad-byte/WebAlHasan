# PRD V7 Fase 1 — Fondasi Pengajuan Wali dan Routing Pembimbing

**Status (27 Oktober 2026): LULUS audit teknis lokal independen Codex setelah empat koreksi terarah.** Keputusan, bukti dan batas audit di [AUDIT-CODEX.md](AUDIT-CODEX.md); bukti implementator di bawah dipertahankan sebagai riwayat. Belum merge, belum deploy, belum migrasi produksi, belum rilis aplikasi. Fase 2 belum dimulai. Fase 1 **bukan** izin rilis parsial V7.

| Butir | Nilai |
| --- | --- |
| Workstream | PRD V7 Fase 1–5 pengajuan izin orang tua melalui pembimbing — implementator Claude Code, auditor Codex (`AGENTS.md`, keputusan 27 Oktober 2026) |
| Branch | `claude/prd-v7-fase-1` |
| Baseline web | `origin/main` `3e785645bc412b7bc95b1e05152f3ed0508a6a58` (merge PR #94) memuat spesifikasi `codex/prd-v7-spesifikasi` `ee8a3ff4f7e6ac97d64607d268d2e48c9c13859e`; `git diff ee8a3ff origin/main` kosong. Branch dibuat dari `origin/main`; tidak bekerja di `main`, tanpa worktree. |
| Mobile | Tidak diubah dan tidak dibutuhkan Fase 1 (paritas aplikasi = Fase 4). Endpoint V2 yang dipakai aplikasi tidak menerima baris tahap V7, sehingga layar aplikasi lama tidak melihat status baru. |
| Lingkungan uji | PHP 8.4.14, MariaDB 12.3.2 lokal (`innodb_snapshot_isolation=ON`), Chromium Playwright; seluruh DB `test_*_test` dengan fixture sintetis. Tidak ada data produksi yang dibuka/diubah. Direktori belum terlacak milik pekerjaan lain (`docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/`) tidak disentuh. |

## Yang dikerjakan (persyaratan Fase 1)

1. **Konteks** — dibaca `AGENTS.md`, `PRD-V7.md`, `PRD-V2.md`, `docs/prd-v7/prompts-implementasi-audit.md`, `docs/phase-v6-5/AUDIT-CODEX.md` (gerbang akhir V6 tetap terbuka; V7 tidak mengubahnya), migrasi 006/007 (skema izin V2), `IzinWorkflowService`/`IzinWriteRepository`/`IzinRepository`/`IzinRouter`/`IzinIdempotency`, `Capabilities`, penugasan pembimbing (012), relasi `santri_wali`, halaman portal izin, router API, laporan izin V2.
2. **Migrasi aditif `030_v7_fase1_pengajuan_wali.sql`** (idempoten; setiap pernyataan dibungkus pemeriksaan `INFORMATION_SCHEMA` seperti 007; tanpa DROP/DELETE/UPDATE/INSERT; hanya `ALTER` pada `izin_pengajuan`):
   - `status` ENUM diperluas **di ujung** dengan `Menunggu Pembimbing`, `Perlu Penetapan Pembimbing`, `Perlu Perbaikan Wali`, `Ditolak Pembimbing` — lima nilai V2 tetap di depan dengan urutan sama.
   - Kolom `asal_pengajuan` (`Pengurus`|`Wali`, bawaan `Pengurus` untuk seluruh baris lama), `wali_id` (wali pengaju), `catatan_wali`, `pembimbing_pengurus_id` (pembimbing tujuan), `pembimbing_routing_kandidat|catatan|pada`, `pembimbing_ditetapkan_oleh_user_id|pada`; tiga kunci asing dan empat indeks.
   - CHECK `izin_pengajuan_v7_asal_check`: baris Wali wajib `wali_id` dan tanpa `pengurus_id`; baris Pengurus tanpa `wali_id` dan **hanya** berstatus V2 (jalur pengurus tidak pernah masuk tahap pembimbing).
   - Tabel `izin_pembimbing_penetapan` (hanya bertambah): pembimbing sebelum/sesudah, penugasan, status sebelum, jumlah kandidat, alasan (CHECK ≥ 3), pelaku, versi sebelum/sesudah (CHECK `sesudah = sebelum + 1`, UNIQUE per pengajuan+versi), waktu; lima kunci asing.
   - Idempotensi memakai `izin_idempotency_keys` V2 (operasi `izin.wali.create`, `izin.wali.penetapan_pembimbing`), riwayat `izin_riwayat_status`, audit `audit_logs`. Satu identitas kanonis: ID baru di ruang ID `izin_pengajuan` yang sama; ID V2/warisan, `perizinan`, keputusan, riwayat tidak berubah.
   - Rollback berpasangan `database/rollbacks/030_*.sql` didahului **penjaga data SQL** (CHECK pada tabel sementara): bila ada baris Wali/tahap V7, riwayat penetapan, atau klaim `izin.wali.*`, rollback berhenti sebelum perubahan skema dan 030 tetap tercatat.
3. **Layanan `App\Izin\IzinWaliService`** (+ `IzinWaliRepository`, `PembimbingRouter`) — satu pintu web dan API:
   - **Buat (wali):** satu transaksi READ COMMITTED dengan urutan: kunci baris akun pelaku → klaim idempotensi → akun wali aktif + relasi `santri_wali` aktif dibaca ulang **dengan kunci baca** (juga pada putar ulang) → kunci baris santri (`IzinWriteRepository::lockSantri`, urutan yang **sama** dengan jalur pengurus V2) → santri aktif → tahun ajaran aktif → benturan tanggal (`IzinWriteRepository::findOverlap`, satu aturan untuk kedua jalur) → routing pembimbing → sisip → riwayat (`pengajuan_wali_dibuat`, `routing_pembimbing_otomatis|perlu_admin`) → selesaikan klaim → audit wajib. `pengurus_id` dan `murobi_guru_id` NULL: **murobi tidak menerima apa pun sebelum pembimbing meneruskan (Fase 2)**.
   - **Routing pembimbing:** pembimbing **sah** = pengurus aktif dengan `pembimbing_assignments` aktif yang berlaku pada tanggal itu, tahun ajaran aktif, santri ditempatkan di kamar penugasan atau kelas aktif penugasan, **dan** akun pengurus aktif (P-2). Tepat satu (unik per pengurus) → `Menunggu Pembimbing`; nol atau lebih dari satu → `Perlu Penetapan Pembimbing` (antrean admin). Jumlah kandidat dan catatan routing disimpan.
   - **Penetapan pembimbing (admin):** kunci akun → klaim → kunci baris pengajuan → harus asal Wali dan berstatus tahap penetapan → versi wajib dan sama → pengurus pilihan harus pembimbing sah santri itu (penugasan dikunci baca) → pembaruan bersyarat versi+status → baris `izin_pembimbing_penetapan` → riwayat `pembimbing_ditetapkan|_ulang` → audit. Pengurus tanpa akun/di luar kamar-kelas → `422`; versi lama/status berubah → `409`; non-admin → `403`.
   - **Baca per peran:** wali pengaju (akun + wali sama) melihat isi lengkap dan peristiwa; wali lain berelasi aktif melihat **status ringkas** (santri, rentang, status, waktu, "Wali lain") tanpa alasan, catatan, versi, riwayat, atau tindakan; pembimbing tujuan yang **masih sah** melihat antrean dan isi lengkap; admin memantau seluruh pengajuan wali, antrean pengecualian (nol/ganda kandidat **atau** pembimbing tujuan tidak lagi sah/santri tidak aktif), kandidat, dan riwayat penetapan. Selain itu `404` (ID tebakan tidak membocorkan keberadaan); peran tanpa akses V7 (mis. murobi) `403`.
   - Hak dihitung ulang dari basis data setiap panggilan (role akun aktif dari DB, bukan sesi/token) sehingga pencabutan relasi/penugasan/akun langsung berlaku pada sesi web dan token lama.
   - Audit `izin_wali_pengajuan_dibuat`, `izin_wali_pembimbing_ditetapkan` hanya metadata (ID santri/wali/tahun/status/kandidat/pembimbing/versi) — **tanpa** alasan izin, catatan, atau tanggal rinci; riwayat pembuatan tidak menyalin alasan izin.
   - Galat basis data 1020/1205/1213 dipetakan `409` dapat diulang. Tanpa skema 030 layanan menjawab `503` "belum dipasang".
4. **Perubahan V2 terarah (jalur pengurus dipertahankan):**
   - `IzinWriteRepository::STATUS_MENAHAN` + `Menunggu Pembimbing`, `Perlu Penetapan Pembimbing`, `Perlu Perbaikan Wali` → pemeriksaan benturan V2 dan V7 saling menahan; `Ditolak Pembimbing`/`Dibatalkan` melepaskan (PRD §5.2). `STATUS_BELUM_DIPUTUS` tidak berubah, sehingga keputusan murobi/admin, penetapan murobi, dan pembatalan V2 menolak (`409`) baris tahap pembimbing.
   - `IzinRepository::conditions()` dan `IzinReportRepository::conditions()` menambah `p.status IN (lima status V2)`: daftar, antrean, ringkasan, detail, dan laporan/CSV/cetak V2 (web dan API) tidak memuat baris tahap pembimbing (P-3). `IzinRepository::STATUSES` dan kontrak API V2 tidak berubah.
5. **Web** (kerangka bersama, `Cache-Control: private, no-store`, POST wajib CSRF lewat `portal/_guard.php`):
   - `portal/izin_wali.php` — satu alamat, tampilan per peran aktif: orang tua (kartu pengajuan wali anak berelasi, pengaju lengkap/wali lain ringkas, tombol Ajukan izin), pembimbing (antrean hanya-baca), admin (chip "Perlu tindakan admin"/"Semua", tabel dengan penanda "Perlu penetapan admin"). Murobi `403`.
   - `portal/izin_wali_buat.php` — formulir wali (anak, tanggal izin, tanggal kembali, alasan, catatan; tanpa lampiran); kunci idempotensi dibuat saat render dan **dipertahankan** saat dirender ulang sesudah `409/422`; sukses `303` ke rincian.
   - `portal/izin_wali_detail.php` — rincian sesuai hak; admin menetapkan pembimbing (pilihan hanya kandidat sah, alasan, versi, kunci); tanpa kandidat → petunjuk membuat penugasan di Pusat Penugasan.
   - Navigasi: tab **"Pengajuan wali"** di Perizinan (admin, pengurus) dan di Izin anak (orang tua); halaman Izin anak menautkan Pengajuan wali dan Ajukan izin. Jumlah menu tidak berubah (hanya tab).
6. **API** (`/api/v1/v7/izin/*`, layanan sama, no-store): `GET akses`, `GET wali/anak`, `GET|POST wali/pengajuan`, `GET pembimbing/antrean`, `GET admin/pengajuan`, `GET pengajuan/{id}`, `GET pengajuan/{id}/kandidat-pembimbing`, `POST pengajuan/{id}/penetapan-pembimbing`. `IzinException` dipetakan ke `FORBIDDEN/NOT_FOUND/CONFLICT/VALIDATION_FAILED/SERVICE_UNAVAILABLE`. Kontrak di `docs/api-v1.md`.
7. **Operasi** — `bin/v7_phase1_preflight.php [manifest]` (CHECK ditegakkan, InnoDB, binlog untuk READ COMMITTED, tabel induk, 029 tercatat, ENUM V2 berurutan, baris lama memenuhi CHECK 030, jumlah pembimbing aktif tanpa akun, manifest jumlah baris + sidik jari ID/isi santri, wali, relasi, users, `perizinan`, pengajuan/keputusan V2, penugasan), `bin/v7_phase1_verify.php [--rollback] [manifest]` (skema + invarians: baris wali berwali tanpa pengurus pengaju; tahap V7 hanya baris wali, tanpa murobi dan tanpa keputusan; Menunggu Pembimbing berpembimbing; riwayat + audit untuk setiap baris wali; versi = 1 + penetapan; **tidak ada dua pengajuan aktif bertumpang tindih yang melibatkan baris wali**; klaim V7 selesai; audit tanpa isi izin; tanpa referensi yatim), `bin/v7_phase1_rollback_preflight.php`. Fixture `tests/v7_phase1_seed.php`, DB uji `tests/v7_phase1_db.sh`.

## Kriteria penerimaan → bukti

| Kriteria Fase 1 | Bukti |
| --- | --- |
| Wali A membuat satu pengajuan untuk anak A di web/API; wali A membacanya, pembimbing A melihat satu item, murobi nol item sebelum penerusan | `bukti/integrasi.txt` I-1; `bukti/http.txt` H-A (web), H-D (API `201`), H-B/H-D (pembimbing satu item, murobi `403/404`, antrean murobi V2 tanpa item); `bukti/browser.txt` B-A, B-E |
| Dua kiriman identik kunci sama → tepat satu ID; kunci sama isi berbeda → `409` tanpa baris baru | `bukti/integrasi.txt` I-2, I-6 (8 proses bersamaan → 8 jawaban sukses, satu ID, satu baris); `bukti/http.txt` H-A (POST ulang web → `303` ke rincian sama, satu baris), H-D (API `201`/`200` replay/`409`); `bukti/browser.txt` B-C (klik ganda) |
| Wali B tanpa relasi anak A → `403`/`404`/kosong pada buat, daftar, detail, ID tebakan; wali kedua berelasi hanya status tanpa tombol mutasi | `bukti/integrasi.txt` I-3, I-7; `bukti/http.txt` H-B, H-D, H-E; `bukti/browser.txt` B-B |
| Nol/dua kandidat → satu item antrean admin dan nol antrean murobi; admin hanya menetapkan pembimbing berpenugasan aktif yang cocok | `bukti/integrasi.txt` I-4 (nol, dua, dan pembimbing tanpa akun; penetapan sah/tidak sah, versi, kunci, non-admin); `bukti/http.txt` H-C, H-D; `bukti/browser.txt` B-E |
| Dua pengajuan wali/pengurus bertumpang tindih → paling banyak satu aktif; data V2/warisan dan ID santri–wali utuh sesudah migrasi/rollback | `bukti/integrasi.txt` I-5 (lintas jalur dua arah, pembatalan melepas rentang), I-6 (4 wali + 4 pengurus bersamaan → tepat satu sukses, tujuh `409`; dua wali bersamaan → satu), I-10; `bukti/migrasi-rollback.txt` M-1…M-6 |

## Hasil pengujian Fase 1 (lokal, kode final)

| Rangkaian | Hasil | Berkas |
| --- | --- | --- |
| Statis `tests/v7_phase1_static.php` (migrasi/rollback, urutan kunci, versi, audit tanpa isi, tanpa notifikasi, perubahan V2 terarah, CSRF/escape/no-store, rute, batas berkas Fase 1 terhadap baseline, lint) | 49/0 | `bukti/statis.txt` |
| Integrasi layanan + konkurensi proses terpisah + CHECK + jalur V2 + verifier `tests/v7_phase1_integration.php` | 75/0 | `bukti/integrasi.txt` |
| HTTP web + API lewat server PHP lokal `tests/v7_phase1_http.php` (log server tanpa galat PHP dan tanpa alasan izin) | 71/0 | `bukti/http.txt` |
| Migrasi–rollback–preservasi pada salinan 029 berfixture V1–V6 (1.052 pengajuan V2, 551 keputusan) `tests/v7_phase1_migration.php` | 25/0 | `bukti/migrasi-rollback.txt` |
| Browser 375/768/1280 + tanpa JavaScript `tests/browser/uji-v7-fase1.mjs` (log server 0 galat PHP) | 39/0 | `bukti/browser.txt`, `tangkapan/*.png` (diperiksa visual) |

Kontrol yang tercatat selama implementasi (bukan bukti akhir):

- **Klik ganda bersamaan (temuan, diperbaiki untuk jalur V7).** Versi pertama layanan memakai urutan klaim idempotensi V2 langsung. Uji I-6 dengan 8 proses berkunci sama: **2 sukses + 6 × 500** (`Penjaga idempotensi tidak dapat dibaca`): transaksi yang menunggu di indeks unik sama-sama memegang kunci baca atas baris duplikat lalu saling menunggu saat menaikkannya ke `FOR UPDATE`; InnoDB memutus deadlock dengan menggagalkan sebagian. Tidak ada baris ganda, tetapi pengguna menerima galat. Perbaikan: mutasi V7 diawali kunci tulis baris akun pelaku (`IzinWaliRepository::kunciAkun`), sehingga kiriman berikutnya langsung memutar ulang hasil → **8 sukses, satu ID, satu baris**.
- **CHECKSUM TABLE `santri_wali`.** Uji migrasi pertama: `CHECKSUM TABLE santri_wali` berbeda sesudah `ALTER` pada `izin_pengajuan`, padahal sidik jari relasi (ID, santri, wali, arsip) di verifier identik. `santri_wali` memiliki kolom generated STORED (`active_guard`); nilai CHECKSUM MariaDB 12 lokal untuk tabel semacam itu tidak stabil antar pembukaan tabel. Uji kini membandingkan **hash isi seluruh kolom dasar** untuk 24 tabel inti (termasuk `santri_wali`) dan CHECKSUM untuk tabel tanpa kolom generated; keduanya identik sebelum/sesudah migrasi dan sesudah rollback.

Cara mengulang (berurutan; DB perantara rantai fixture bernama tetap):

```bash
bash docs/phase-v7-1/perintah-uji.sh
bash docs/phase-v7-1/perintah-regresi.sh
```

## Regresi

Dijalankan berurutan lewat `perintah-regresi.sh` (ringkasan `bukti/regresi/ringkasan.txt`, keluaran per suite di `bukti/regresi/`). DB jalur V2 dibangun dengan `tests/v7_phase1_db.sh` (001–030 + fixture V1–V7, belum ada pengajuan wali); verifier V7 dijalankan sesudah setiap DB regresi dipakai suite V2 (enam DB, masing-masing 34/0).

| Rangkaian | Hasil | Catatan |
| --- | --- | --- |
| Fondasi penugasan `penugasan_integration` | 161/0 | |
| V2 Fase 1 / 2 / 4 / 5 integrasi | 39/0 · 94/0 · 124/0 · 145/0 | Jalur pengurus V2 (buat, routing, keputusan, pembatalan, koreksi, notifikasi, laporan) tetap lulus dengan aturan benturan baru. |
| V2 Fase 4 konkurensi | 20/0 | |
| V2 web smoke Fase 2 / 4 / 5 | 36/0 · 46/0 · 79/0 | |
| V2 kontrak API Fase 5 | 150/0 | |
| V2 kontrak API Fase 3 / Fase 4 | 95/21 · 91/1 | **Kontrol baseline:** suite yang sama dari `git archive 3e78564` pada DB rantai V6 Fase 5 yang dibangun kode baseline → **95/21 · 91/1, daftar kegagalan identik** (`*-kontrol-baseline-3e78564.txt`). Fixture rantai kini memberi santri A1 lebih dari satu murobi/pembimbing kandidat, sehingga harapan "routing tunggal" suite Fase 3 tidak terpenuhi; bukan dampak V7. |
| V6 Fase 5 integrasi / HTTP (DB 027–030) | 71/0 · 42/0 | |
| V6 Fase 5 rilis | 14/21 | Oracle historis: alat/manifest rilis V6 mengasumsikan 029 migrasi terakhir (rollback 029, `migrate up` tepat 027–029, manifest 29 + 6). **Kontrol baseline 29/0** (`v6-fase5-rilis-kontrol-baseline-3e78564.txt`). Lihat catatan urutan rilis di Batas. |
| HTTP Tahap 4 (perizinan, pengajian, cetak) / 5 / 7 | 132/0 · 106/0 · 122/0 | |
| HTTP Tahap 6 sesudah 030 → 029 → … → 024 dilepas (preflight + penjaga SQL, tabel kosong) | 37/0 · 132/0 | Rantai rollback kosong berjalan dengan penjaga baru 030. |
| HTTP Tahap 2 | 185/6 | Sama dengan regresi V6 (empat oracle menu BB/BC/BD + dua oracle peta menu Keuangan BE). Tab "Pengajuan wali" tidak menambah menu. |
| HTTP Tahap 3 | 166/1 | Oracle historis "AG belum dipasang" sejak Tahap 8 (sama dengan V5/V6). |
| Browser kerangka Tahap 1 sembilan akun × 375/768/1280 pada DB V7 | 91/0 | |
| Seluruh 40 suite statis | 18 exit 0 · 20 exit 1 · 2 exit 77 | `v7_phase1_static` 49/0. Kegagalan pada tabel di bawah. |

### Oracle historis statis (tidak diubah, tidak dilonggarkan)

Dibandingkan dengan regresi audit V6 Fase 5 (`docs/phase-v6-5/audit-codex/regresi/ringkasan.txt`), hanya lima suite statis yang hitungannya berubah; seluruhnya oracle yang memang dilanggar oleh tambahan V7 yang diminta PRD:

| Suite | V6 F5 audit → V7 F1 | Kegagalan baru dan sebabnya |
| --- | --- | --- |
| `v2_phase2_static` | 174/0 → 173/1 | "Tumpang tindih dinilai terhadap status Diajukan, Perlu Penetapan Admin, dan Disetujui" — PRD V7 §5.2 mewajibkan tahap wali yang masih menunggu ikut menahan rentang (P-4). |
| `v6_phase5_static` | 74/0 → 69/5 | "Tidak ada berkas database/ yang ditambah", "Migrasi terakhir tetap 029", "Berkas berubah hanya dalam daftar Fase 5", "Navigation tidak berubah", "Manifest rilis = 29 + 6" — migrasi 030, tab navigasi, dan berkas V7. |
| `v6_phase4_static` | 20/2 → 19/3 | "Auth, PSB, penugasan, V2–V5, admin, rute API … tidak berubah" — `api/v1/index.php` dan berkas izin V2 kini berubah untuk V7. |
| `redesain_web_tahap6_static` | 140/1 → 139/2 | "Navigation dan app/Auth tidak diubah Tahap 6" — tab "Pengajuan wali" di `Navigation`. |
| `v2_phase5_static` | 322/3 → 326/3 | Tiga kegagalan sama (versi Expo/React Native/expo-notifications) karena salinan kerja `~/alhasanApps` memuat perubahan `package.json` milik pekerjaan lain; bukan V7. |

Suite statis lain yang gagal (`keamanan_psb` 82/1, `penugasan` 281/2, Tahap 2/3/4/7/8, `v2_phase4`, `v5_phase1/2/3/5`, `v6_phase1/2/3`) memiliki hitungan **identik** dengan regresi audit V6 Fase 5; `v3_phase2/3_static` exit 77 (memerlukan DB V3 bernama tetap), sama seperti sebelumnya.

**Tidak dijalankan ulang** (dicatat, bukan disembunyikan): keamanan PSB HTTP/browser (Apache), suite V3/V4/V5 berbasis DB, V6 Fase 1–4, browser V3–V6, uji Android/iOS. Kode PSB, V3–V6, `app/Auth`, `app/Penugasan`, notifikasi, dan halaman admin tidak berubah dari baseline (statis S-6).

## Temuan untuk keputusan Human Developer

- **T-1 (jalur pengurus V2, sudah ada sebelum V7) — kiriman bersamaan berkunci sama menghasilkan 500.** Pola deadlock yang sama dengan kontrol di atas terjadi pada `IzinWorkflowService::create()` V2: 8 proses pengurus dengan kunci dan isi sama → 1 sukses + 1 putar ulang + **6 × 500**; tidak ada pengajuan ganda (kunci unik tetap menjaga), tetapi klien menerima galat server. Kode V2 itu tidak diubah Fase 1 (di luar cakupan; perubahan jalur pengurus memerlukan keputusan). Perbaikan yang sama (kunci baris akun sebelum klaim) dapat diterapkan pada create/decide/cancel/assign/correct V2 dalam tahap terpisah.
- **T-2 — notifikasi pembimbing belum ada.** PRD §3.5 meminta pembimbing diberi tahu saat pengajuan masuk; sesuai urutan PRD itu Fase 3. Sampai Fase 3, pembimbing dan admin perlu membuka tab Pengajuan wali.

## Penafsiran yang perlu ditinjau auditor/Human Developer

- **P-1 Status tahap.** Empat status V7 ditambahkan sekali di ujung ENUM (PRD §5.3 "minimal" tahap). Fase 1 hanya memakai `Menunggu Pembimbing` dan `Perlu Penetapan Pembimbing`; `Perlu Perbaikan Wali` dan `Ditolak Pembimbing` belum punya transisi (Fase 2) tetapi sudah dihitung penjaga rollback, verifier, dan aturan benturan. Sesudah penerusan (Fase 2) baris memakai status V2 (`Diajukan`/`Perlu Penetapan Admin`) agar routing dan keputusan murobi tetap jalur V2.
- **P-2 Pembimbing sah memerlukan akun pengurus aktif.** Penugasan milik pengurus tanpa akun aktif ber-role `pengurus` tidak dihitung, sebab antreannya tidak pernah terbaca; pengajuan seperti itu masuk antrean admin. Preflight melaporkan jumlah pengurus seperti itu.
- **P-3 Tampilan V2 hanya status V2.** Tahap pembimbing dibaca lewat halaman/API V7 dengan proyeksi per peran; daftar/antrean/ringkasan/detail/laporan V2 (web dan API, termasuk orang tua dan admin) tidak memuatnya. Tiga konsekuensi yang disengaja: (a) detail V2 untuk ID tahap pembimbing `403`; (b) kartu "Izin bulan ini" Beranda admin (`RingkasanAdministrasi::izinBulanIni`, agregat admin) ikut menghitung status V7; (c) "jumlah izin semester ini" (keputusan Q) menghitung pengajuan wali yang tidak dibatalkan. Laporan lintas asal wali/pengurus adalah Fase 3.
- **P-4 Benturan tanggal.** Menahan: `Diajukan`, `Perlu Penetapan Admin`, `Disetujui`, `Menunggu Pembimbing`, `Perlu Penetapan Pembimbing`, `Perlu Perbaikan Wali`. Melepas: `Ditolak`, `Dibatalkan`, `Ditolak Pembimbing`. Kedua jalur menserialkan pembuatan lewat kunci baris santri yang sama.
- **P-5 404 untuk di luar hak.** Detail/daftar V7 menjawab `404` (bukan `403` seperti V2) agar ID tebakan tidak membocorkan keberadaan; peran tanpa akses V7 menerima `403`. PRD menerima keduanya.
- **P-6 Identitas wali pengaju** = akun pengaju **dan** wali yang tertaut pada akun itu. Bila akun dipindah ke wali lain, akun itu tidak lagi diperlakukan sebagai pengaju.
- **P-7 Riwayat bagi wali pengaju** berisi peristiwa dan waktu saja; catatan routing dan alasan penetapan admin tidak ditampilkan. Alasan izin tidak disalin ke riwayat.
- **P-8 Pembimbing tidak lagi sah.** Bila penugasan pembimbing tujuan berakhir/dinonaktifkan, akunnya nonaktif, atau santri nonaktif, item hilang dari antrean pembimbing dan tampil di antrean admin ("Perlu penetapan admin") saat dibaca; status tidak diubah otomatis dan tidak ada proses latar. Admin menetapkan ulang (beralasan, riwayat baru); pembimbing yang sama tidak dapat "ditetapkan ulang" bila masih sah (`409`).
- **P-9 Akun ganda.** Akun admin yang juga wali/pembimbing melihat rincian sebagai admin (akses terluas yang sah). Halaman daftar mengikuti peran aktif sesi.
- **P-10 Tanggal lampau** tidak ditolak, sama dengan formulir V2.
- **P-11 Tanpa notifikasi** (T-2) dan tanpa perubahan WhatsApp/outbox.

## Batas yang belum diuji / terbuka

- Tidak ada uji MySQL 8, cPanel/hosting, atau produksi (lokal hanya MariaDB 12.3.2). `READ COMMITTED`, `LOCK IN SHARE MODE`, `CHECK`, `ALTER ... MODIFY ENUM` instan, dan binlog hosting belum diperiksa di server nyata — preflight memeriksa sebagian sebelum migrasi.
- Interleaving konkurensi diuji pada pola 8 proses (kunci sama; 4 wali + 4 pengurus; dua wali berbeda); bukan bukti seluruh kemungkinan interleaving. Server PHP bawaan memproses permintaan HTTP berurutan, sehingga bukti konkurensi sesungguhnya adalah I-6 (proses + koneksi DB terpisah).
- Aplikasi mobile, notifikasi in-app/Push, tinjauan pembimbing, perbaikan/pembatalan wali, penerusan dan keputusan murobi, serta laporan V7 — **belum** dikerjakan (Fase 2–5).
- Uji keamanan PSB (Apache + `.htaccess`) dan browser V3/V4/V5/V6 lain tidak dijalankan ulang; kodenya tidak berubah (statis S-6).
- **Urutan rilis V6/V7.** Gerbang akhir V6 masih terbuka. Alat rilis V6 (`bin/v6_preflight.php --rilis`, manifest 29 migrasi + 6 rollback, rollback 029…027) mengasumsikan 029 migrasi terakhir; sesudah 030 ada, alat itu menolak (V6 Fase 5 rilis 14/21, kontrol baseline 29/0). Bila V6 dirilis sesudah kode V7 berada di cabang deploy, manifest/preflight V6 perlu diselaraskan atau 030 dilepas dahulu — keputusan Human Developer.
- **Risiko urutan rilis.** Bila branch ini digabung ke `main` lalu dideploy sebelum Fase 5, `bin/migrate.php up` memasang 030 dan tab "Pengajuan wali" langsung aktif bagi orang tua tanpa tahap tinjauan/penerusan pembimbing (Fase 2) — pengajuan akan tertahan di tahap pembimbing. Fase 1 tidak menambah sakelar rilis; keputusan urutan rilis milik Human Developer.

## Langkah rilis dan pemulihan (untuk keputusan Human Developer kelak — bukan izin deploy)

1. Backup terverifikasi DB dan berkas.
   Hentikan penulis selama pengambilan dan perbandingan manifest; manifest sebelum koreksi auditor perlu dibuat ulang karena belum memuat hash isi riwayat V2. Preflight kini juga memeriksa CHECK sesi dan default global MariaDB.
2. `php bin/v7_phase1_preflight.php /jalur/aman/manifest-030.json` — wajib tanpa `[gagal]`.
3. `php bin/migrate.php up` (menerapkan 030).
4. `php bin/v7_phase1_verify.php /jalur/aman/manifest-030.json` — wajib "Verifikasi V7 Fase 1 lulus".
5. Pemulihan sebelum ada data V7: `php bin/v7_phase1_rollback_preflight.php` lalu `php bin/migrate.php rollback`, kemudian `php bin/v7_phase1_verify.php --rollback /jalur/aman/manifest-030.json`. Sesudah ada data wali, rollback ditolak penjaga SQL; pulihkan dari backup atau buat migrasi korektif.

## Handoff

Implementasi Fase 1 selesai pada branch `claude/prd-v7-fase-1`: commit implementasi `b622154cf32ebb9aa17f9f311514849b2f1078e0` di atas baseline `3e785645bc412b7bc95b1e05152f3ed0508a6a58`; commit sesudahnya hanya mencatat hash ini. Implementator berhenti untuk **audit independen Codex** sesuai prompt audit Fase 1 di `docs/prd-v7/prompts-implementasi-audit.md`. Tidak merge, deploy, migrasi produksi, rilis aplikasi, atau memulai Fase 2.
