# PRD V7 Fase 2 — Tinjauan Pembimbing, Perbaikan, dan Keputusan Akhir

**Status (27 Oktober 2026): LULUS audit teknis lokal Codex setelah tiga koreksi terarah.** Keputusan dan bukti independen: [AUDIT-CODEX.md](AUDIT-CODEX.md). Belum merge, belum deploy, belum migrasi produksi, belum rilis aplikasi. Fase 3 belum dimulai. Fase 2 **bukan** izin rilis parsial V7; gerbang akhir/produksi V6 tetap terbuka.

| Butir | Nilai |
| --- | --- |
| Workstream | PRD V7 Fase 1–5 pengajuan izin orang tua melalui pembimbing — implementator Claude Code, auditor Codex (`AGENTS.md`, keputusan 27 Oktober 2026) |
| Branch | `claude/prd-v7-fase-2` |
| Baseline web | Commit audit Fase 1 `192711e0b6bc2cadd46ffc79ee5dfa921f3a76d1` (= `origin/claude/prd-v7-fase-1`). `origin/main` `ac4c4b944e4c19ee7745e22372a04d045287de63` (merge PR #95) memuatnya; `git diff 192711e origin/main` kosong. Branch dibuat dari `192711e`; tidak bekerja di `main`, tanpa worktree. |
| Mobile | Tidak diubah (paritas aplikasi = Fase 4). Endpoint V2 orang tua yang dipakai aplikasi tidak lagi memuat pengajuan berasal wali (lihat P-6), sehingga layar lama tidak menampilkan isi wali kepada wali lain. |
| Lingkungan uji | PHP 8.4.14, MariaDB 12.3.2 lokal (`innodb_snapshot_isolation=ON`), Node 26.7.0, Chromium Playwright; seluruh DB `test_*_test` dengan fixture sintetis. Tidak ada data/layanan produksi yang dibuka. Direktori belum terlacak milik pekerjaan lain (`docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/`) tidak disentuh. |

## Yang dikerjakan (persyaratan Fase 2)

1. **Konteks** — dibaca `AGENTS.md`, `PRD-V7.md`, prompt Fase 2 di `docs/prd-v7/prompts-implementasi-audit.md`, `docs/phase-v7-1/AUDIT-CODEX.md` dan README Fase 1, commit `b622154`/`974e1c5`/`192711e`, serta layanan keputusan V2 (`IzinWorkflowService`, `IzinWriteRepository`, `IzinRouter`, `IzinIdempotency`, `IzinService`, `IzinRepository`, `Capabilities`, `RecipientResolver`/`NotificationService`, `IzinReportRepository`, `IzinApiService`, `portal/izin_detail.php`, `portal/izin_aksi.php`).
2. **Migrasi aditif `031_v7_fase2_tinjauan_pembimbing.sql`** (`CREATE TABLE IF NOT EXISTS`; tanpa ALTER/DROP/DELETE/UPDATE/INSERT pada tabel lama — `izin_pengajuan` dan kolom 030 tidak diubah):
   - `izin_pembimbing_tinjauan` — setiap tindakan pembimbing: tindakan (`Minta Perbaikan`/`Tolak`/`Teruskan`), pengurus dan penugasan yang dipakai, pelaku, catatan/alasan, status dan versi sebelum/sesudah (UNIQUE per pengajuan+versi), **sidik isi** yang ditinjau (SHA-256 tanggal+alasan+catatan; isi tidak disalin), dan hasil routing murobi untuk penerusan. CHECK: status sebelum selalu `Menunggu Pembimbing`; `Tolak` ↔ `Ditolak Pembimbing`, `Minta Perbaikan` ↔ `Perlu Perbaikan Wali`, `Teruskan` ↔ `Diajukan` + murobi tunggal **atau** `Perlu Penetapan Admin` tanpa murobi; alasan wajib (≥ 3) untuk minta perbaikan/tolak; versi naik tepat satu. Lima kunci asing (murobi `INT` mengikuti `guru.id`).
   - `izin_wali_perbaikan` — setiap pengiriman ulang wali pengaju: isi sebelum **dan** sesudah, sidik keduanya, versi sebelum/sesudah, wali dan akun pengirim; satu perbaikan menjawab tepat satu permintaan (UNIQUE `tinjauan_id`). CHECK: isi harus berubah, rentang sah, alasan ≥ 3, versi naik satu.
   - Rollback berpasangan didahului **penjaga data SQL** (CHECK pada tabel sementara): ada tinjauan/perbaikan, klaim `izin.wali.tinjauan|perbaikan|batal`, atau pengajuan wali di luar tahap Fase 1 → rollback berhenti sebelum DROP dan 031 tetap tercatat. Hanya dua tabel 031 yang dilepas.
3. **Layanan `IzinWaliService` (+ `IzinWaliRepository`)** — satu pintu web dan API, transaksi READ COMMITTED seperti Fase 1:
   - **Tinjau (pembimbing):** kunci baris akun → **role/penugasan pembimbing dibaca ulang dari DB sesudah kunci** (pola koreksi F-1 Fase 1) → klaim idempotensi → putar ulang hanya bila akun masih pembimbing sah atas pengajuan itu → kunci baris pengajuan → harus asal Wali dan dialamatkan kepadanya → **kelayakan penugasan dihitung ulang sesudah kunci dengan penugasan dikunci baca** (pola koreksi F-2) → status `Menunggu Pembimbing` → versi sama → pembaruan bersyarat asal+versi+status → baris tinjauan → riwayat → selesaikan klaim → audit metadata. Pembimbing lain/dicabut → `404`; bukan pengurus (termasuk admin dan wali) → `403`. Admin tidak menggantikan tinjauan pembimbing.
   - **Minta perbaikan** → `Perlu Perbaikan Wali` (rentang tetap ditahan). **Tolak** → `Ditolak Pembimbing`: terminal, melepas rentang (`STATUS_MENAHAN` Fase 1), **tanpa baris `izin_keputusan`**, status dan label berbeda dari `Ditolak` murobi. **Teruskan** → `IzinRouter::resolve()` V2 pada santri yang sama: tepat satu murobi → `Diajukan` dengan `murobi_guru_id`; nol/lebih → `Perlu Penetapan Admin`. Kolom routing V2 (`routing_kandidat/catatan/pada`) diisi seperti `applyRouting()`; riwayat `tinjauan_diteruskan` + `routing_otomatis|routing_perlu_admin` (nama peristiwa V2). **Tidak ada pengajuan kedua.**
   - **Perbaiki (wali pengaju):** kunci akun → klaim → **akun wali dan relasi aktif dibaca ulang berkunci baca, identitas pengaju (akun + wali) diperiksa sebelum putar ulang** → kunci baris santri lalu baris pengajuan (urutan sama dengan pembuatan kedua jalur) → hanya saat `Perlu Perbaikan Wali` → versi → santri aktif → permintaan perbaikan terbuka → isi harus berubah (sidik) → **benturan rentang baru lintas jalur** (`findOverlap` mengecualikan dirinya) → isi baru + `Menunggu Pembimbing` → baris perbaikan (isi lama/baru) → riwayat tanpa isi → audit. Wali lain berelasi → `403`; tanpa relasi/ID tebakan → `404`.
   - **Batalkan (wali pengaju):** pemeriksaan hak yang sama → kunci pengajuan → status ∈ {`Menunggu Pembimbing`, `Perlu Penetapan Pembimbing`, `Perlu Perbaikan Wali`, `Diajukan`, `Perlu Penetapan Admin`} → versi → pembaruan bersyarat versi+status (kolom pembatalan V2) → riwayat `pembatalan_wali` beralasan → audit. Sesudah keputusan/penolakan pembimbing → `409`.
   - **Keputusan akhir tetap V2:** sesudah diteruskan, penetapan murobi (`assignMurobi`), keputusan murobi/admin pengganti beralasan (`decide`), dan koreksi (`correctDecision`) berjalan tanpa perubahan pada `IzinWorkflowService` untuk baris wali. Murobi di luar penugasan → `403` (aturan V2).
   - **Baca per peran:** admin (lengkap + penetapan + tinjauan + versi isi + keputusan; tindakan tautan V2), pembimbing tujuan yang masih sah (tindakan tinjauan saat menunggu), **murobi tujuan sesudah diteruskan** (lengkap, tautan ke halaman keputusan V2), wali pengaju (isi, catatan perbaikan dan alasan penolakan pembimbing, versi isi, keputusan beserta alasannya, pembatalan; tindakan perbaiki/batal), wali lain (status ringkas tanpa catatan pembimbing, alasan, keputusan, atau tindakan). `GET` riwayat pembimbing memuat pengajuan yang sudah keluar dari antrean beserta status terkini. Antrean admin V7 kini juga memuat pengajuan wali yang diteruskan dengan murobi tidak tunggal.
   - Tanpa skema 031 tindakan Fase 2 menjawab `503`, pembuatan/penetapan Fase 1 tetap berjalan.
4. **Perubahan V2 terarah (jalur pengurus tidak berubah):**
   - `IzinWorkflowService::cancel()` menolak pengajuan berasal wali (`403`, PRD §5.1 "hanya wali pengaju yang membatalkan"); `actionsFor()` tidak menawarkan `batalkan` untuk baris wali (web dan API V2).
   - `IzinWorkflowService::notify()` melewati pengajuan berasal wali: notifikasi V7 (penerima wali pengaju, tautan V7) adalah Fase 3, dan notifikasi V2 bawaan akan dikirim ke seluruh wali santri dengan tautan V2 yang tidak lagi dapat dibuka orang tua (P-7).
   - `IzinRepository::conditions()` dan `IzinReportRepository::scopeConditions()` cakupan **orang tua** menambah `asal_pengajuan = 'Pengurus'` (berpenjaga `Schema::hasColumn`, tetap berjalan sebelum 030): pengajuan wali yang sudah berstatus V2 hanya dibaca lewat V7, sehingga alasan tidak terbuka kepada wali lain melalui daftar/rincian/laporan V2. Admin, pengurus, dan murobi tidak berubah.
   - `IzinRepository::selectClause()` menambah kolom `asal_pengajuan` (atau literal `'Pengurus'` sebelum 030) untuk `actionsFor()`; `IzinApiService` memetakan kolom secara eksplisit sehingga bentuk kontrak API V2 tidak berubah.
5. **Web** (`portal/izin_wali_detail.php`, kerangka bersama, POST + CSRF lewat `_guard.php`, `no-store`): dialog tindakan (`<details data-ah-dialog>`, berfungsi tanpa JavaScript) untuk **Teruskan ke murobi**, **Minta perbaikan**, **Tolak** (pembimbing, peran Pengurus), **Perbaiki dan kirim ulang**, **Batalkan pengajuan** (wali pengaju, peran Orang tua). Gerbang peran POST per aksi; server tetap memeriksa. Sesudah galat 409/422 dialog terbuka lagi dengan isian dan **kunci idempotensi yang sama**. Kartu keputusan, versi isi sebelum perbaikan, jejak tinjauan. `portal/izin_wali.php` menambah chip pembimbing **Menunggu tinjauan / Sudah ditinjau** dan label "Perlu penetapan murobi" di antrean admin. `portal/izin_detail.php` (V2) menampilkan penanda "Diajukan wali dan diteruskan pembimbing …" beserta catatan penerusan dan tautan tinjauan **sesudah** hak V2 diperiksa. Label peristiwa baru di `portal/_ui.php`.
6. **API** (`/api/v1/v7/izin/*`): `POST pengajuan/{id}/tinjauan`, `POST pengajuan/{id}/perbaikan`, `POST pengajuan/{id}/pembatalan`, `GET pembimbing/riwayat`; keputusan memakai `/izin/pengajuan/{id}/keputusan` V2. Kontrak di `docs/api-v1.md`.
7. **Operasi** — `bin/v7_phase2_preflight.php [manifest]` (CHECK sesi+global MariaDB, InnoDB, binlog, 030 tercatat dan lengkap, tipe kolom induk kunci asing 031, manifest jumlah baris + sidik jari Fase 1 tanpa tabel 031), `bin/v7_phase2_verify.php [--rollback] [manifest]` (skema 031 + invarians Fase 1 yang dipertahankan + invarians Fase 2: versi = 1 + peristiwa yang menaikkan versi; tinjauan/perbaikan/pembatalan masing-masing punya riwayat dan audit; perbaikan menjawab permintaan pada versi sesudahnya oleh pengaju; `Ditolak Pembimbing` selalu dari tinjauan Tolak dan tanpa `izin_keputusan`; baris wali berstatus V2 diteruskan tepat sekali; keputusan hanya untuk baris wali yang diteruskan dan tepat satu bila diputus; pembatalan wali oleh pengaju tanpa keputusan; tidak ada pembatalan V2 atas baris wali; tidak ada dua pengajuan aktif bertumpang tindih yang melibatkan baris wali; tanpa referensi yatim), `bin/v7_phase2_rollback_preflight.php`. Fixture `tests/v7_phase2_seed.php` (murobi nol/dua/tunggal untuk santri berpembimbing tunggal), DB uji `tests/v7_phase2_db.sh`, pekerja konkurensi `tests/v7_phase2_worker.php`.

## Kriteria penerimaan → bukti

| Kriteria Fase 2 | Bukti |
| --- | --- |
| Permintaan perbaikan menampilkan catatan kepada wali pengaju; hanya akun itu dapat memperbaiki dan mengirim ulang, dengan versi sebelum/sesudah tercatat | `bukti/integrasi.txt` J-1, J-5, J-7; `bukti/http.txt` W-B, W-E; `bukti/browser.txt` C-A, C-B, C-C, C-G |
| Penolakan pembimbing menutup pengajuan tanpa baris `izin_keputusan` murobi; orang tua melihat hasil tahap pembimbing; pengajuan baru tidak diblokir oleh rentang yang ditolak | `bukti/integrasi.txt` J-2, J-11; `bukti/http.txt` W-D; `bukti/browser.txt` C-E; verifier |
| Penerusan membuat tepat satu antrean murobi atau antrean admin bila routing ambigu; murobi di luar penugasan `403` dan tidak dapat memutus | `bukti/integrasi.txt` J-1, J-3, J-4; `bukti/http.txt` W-C, W-D, W-E; `bukti/browser.txt` C-D |
| Wali pengaju membatalkan sebelum keputusan dengan alasan; pembatalan dan keputusan bersamaan → tepat satu status terminal dan paling banyak satu keputusan efektif | `bukti/integrasi.txt` J-6, J-9 (proses terpisah; kedua cabang teramati); `bukti/http.txt` W-D, W-E; `bukti/browser.txt` C-F, C-G |
| Jalur pengurus V2 tetap dapat membuat, membatalkan, dan memutus sesuai cakupan semula tanpa tahap pembimbing | `bukti/integrasi.txt` J-10; `bukti/regresi/` V2 Fase 1–5 |

## Hasil pengujian Fase 2 (lokal, kode final)

| Rangkaian | Hasil | Berkas |
| --- | --- | --- |
| Statis `tests/v7_phase2_static.php` (migrasi/rollback 031, urutan kunci dan pemeriksaan hak sebelum putar ulang, versi, tanpa `izin_keputusan` di V7, routing murobi V2, audit tanpa isi, tanpa notifikasi, perubahan V2 terarah, gerbang peran POST, CSRF/kunci/versi, escape keluaran dengan uji mutasi, rute, batas berkas terhadap `192711e`, lint 21 PHP) | 54/0 | `bukti/statis.txt` |
| Integrasi layanan + konkurensi proses terpisah + CHECK 031 + jalur V2 + verifier `tests/v7_phase2_integration.php` (J-1…J-12) | 119/0 | `bukti/integrasi.txt` |
| HTTP web + API lewat server PHP lokal `tests/v7_phase2_http.php` (W-A…W-G; log server tanpa galat PHP dan tanpa alasan/catatan) | 61/0 | `bukti/http.txt` |
| Migrasi 031 naik–turun–naik pada salinan 030 berisi pengajuan wali Fase 1 dan ±1.000 izin V2; hash isi 26 tabel inti, struktur/constraint `izin_pengajuan` tetap; rollback ditolak sesudah data Fase 2 `tests/v7_phase2_migration.php` | 27/0 | `bukti/migrasi-rollback.txt` |
| Browser 375/768/1280 + tanpa JavaScript `tests/browser/uji-v7-fase2.mjs` (dialog tindakan, klik ganda, rincian V2 murobi, keputusan; log server 0 galat PHP) | 33/0 | `bukti/browser.txt`, `tangkapan/*.png` (diperiksa visual) |
| Verifier Fase 2 pada DB browser sesudah uji | lulus | `bukti/verifier-sesudah-browser.txt` |

Ringkasan balapan J-9 (proses + koneksi DB terpisah, 4+4 proses per putaran):

| Balapan | Hasil |
| --- | --- |
| Batal vs teruskan, 5 putaran | Tepat satu sukses per putaran, sisanya `409`, tanpa 500; versi naik tepat satu; tiga putaran `Diajukan` (satu tinjauan), dua `Dibatalkan` (nol tinjauan) — kedua cabang teramati |
| Batal vs keputusan murobi, 5 putaran | Tepat satu status terminal; `Disetujui`/`Ditolak` dengan tepat satu keputusan atau `Dibatalkan` dengan nol keputusan — kedua cabang teramati |
| Perbaikan vs batal (akun sama) | Tepat satu menang; jumlah versi isi sesuai hasil |
| Keputusan murobi vs admin pengganti | Tepat satu keputusan efektif |
| Klik ganda 8 proses kunci sama (tinjauan, perbaikan, batal) | 8 jawaban sukses (putar ulang), satu baris efek, versi naik satu |

Kontrol yang tercatat selama implementasi (bukan bukti akhir):

- **Tipe kunci asing murobi.** Versi pertama 031 mendeklarasikan `murobi_guru_id BIGINT UNSIGNED`; MariaDB menolak (`errno 150`) karena `guru.id` bertipe `INT`. Kolom disamakan dengan `izin_pengajuan.murobi_guru_id` (INT); preflight kini memeriksa tipe induk.
- **Cabang balapan.** Putaran serentak pertama batal vs teruskan/keputusan selalu dimenangkan sisi yang sama (keduanya benar, tetapi cabang "batal menang" tidak teramati). Uji J-9 kini memberi penundaan bergiliran 250 ms pada salah satu sisi di sebagian putaran, dan mewajibkan **kedua** cabang teramati. Putaran tanpa penundaan tetap ada.
- **Uji browser.** Dua kekeliruan skrip uji (bukan aplikasi) diperbaiki: `waitForURL` langsung terpenuhi karena alamat rincian sama sebelum/sesudah POST (kini menunggu peristiwa `load` dokumen baru — 409 yang sempat muncul justru penolakan versi basi yang benar), dan gulir halus CSS membuat percobaan ulang klik Playwright tidak pernah stabil (konteks uji kini `reducedMotion: 'reduce'`, aturan CSS yang sudah ada mematikan gulir halus).

Cara mengulang (berurutan; DB perantara rantai fixture bernama tetap):

```bash
bash docs/phase-v7-2/perintah-uji.sh
```

```bash
bash docs/phase-v7-2/perintah-regresi.sh
```

## Regresi

Dijalankan berurutan lewat `perintah-regresi.sh` (ringkasan `bukti/regresi/ringkasan.txt`, keluaran per suite di `bukti/regresi/`). DB regresi dibangun dengan `tests/v7_phase1_db.sh` dari pohon kerja ini (001–031 + fixture V1–V7 Fase 1, belum ada pengajuan wali); verifier Fase 2 dijalankan sesudah setiap DB dipakai suite V2 (enam DB, masing-masing 41/0).

| Rangkaian | Hasil | Catatan |
| --- | --- | --- |
| V7 Fase 1 HTTP / browser 375/768/1280 | 71/0 · 39/0 | Kode Fase 2 dengan 031 terpasang. |
| V7 Fase 1 probe audit Codex (`tests/v7_phase1_audit.php`, F-1…F-4) | 13/0 | Koreksi auditor Fase 1 tetap berlaku. |
| V7 Fase 1 integrasi | 74/1 | Oracle historis: I-3 "Wali pengaju … belum ada tindakan (Fase 2)" — wali pengaju kini memperoleh `{perbaiki, batalkan}`, persis yang diwajibkan Fase 2. Rincian wali kedua tetap tanpa tindakan (lulus). |
| V7 Fase 1 migrasi / drill warisan | exit 1 (21 baris `[gagal]` termasuk keluaran verifier) · 18/7 | Oracle historis: suite mengasumsikan 030 migrasi terakhir — `migrate up` kini memasang 030 **dan** 031, lalu `rollback` hanya melepas 031, sehingga pemeriksaan "030 dilepas" gagal. **Kontrol:** suite yang sama dari `git archive 192711e` (tanpa 031): migrasi **25/0**, drill warisan **26/0** (`*-kontrol-baseline-192711e.txt`). Preservasi 030→031 dibuktikan `bukti/migrasi-rollback.txt`. |
| Fondasi penugasan | 161/0 | |
| V2 Fase 1 / 2 / 4 / 5 integrasi | 39/0 · 94/0 · 124/0 · 145/0 | Jalur pengurus V2 tetap lulus dengan pembatalan/notifikasi/tampilan orang tua yang disaring asal. |
| V2 Fase 4 konkurensi | 20/0 | |
| V2 web smoke Fase 2 / 4 / 5 | 36/0 · 46/0 · 79/0 | |
| V2 kontrak API Fase 5 | 150/0 | |
| V2 kontrak API Fase 3 / Fase 4 | 95/21 · 91/1 | Identik dengan regresi dan kontrol baseline Fase 1 (`docs/phase-v7-1/bukti/regresi/*-kontrol-baseline-3e78564.txt`, dikonfirmasi audit Codex): fixture rantai memberi santri A1 lebih dari satu kandidat; bukan dampak V7. |
| V6 Fase 5 integrasi / HTTP | 71/0 · 42/0 | |
| V6 Fase 5 rilis | 14/21 | Sama dengan Fase 1: alat rilis V6 mengasumsikan 029 migrasi terakhir (kontrol baseline Fase 1 29/0). |
| HTTP Tahap 4 (perizinan, pengajian, cetak) / 5 / 7 | 132/0 · 106/0 · 122/0 | |
| Rollback kosong 031 → 030 → … → 024 (preflight + penjaga SQL) lalu HTTP Tahap 6 | 44/0 · 132/0 | Rantai rollback kosong berjalan dengan penjaga 031 dan 030. |
| HTTP Tahap 2 / Tahap 3 | 185/6 · 166/1 | Identik dengan Fase 1 (oracle menu BB–BE dan "AG belum dipasang"). |
| Browser kerangka sembilan akun × 375/768/1280 | 91/0 | |
| Seluruh 41 suite statis | 18 exit 0 · 21 exit 1 · 2 exit 77 | `v7_phase2_static` 54/0. Rincian di bawah. |

### Oracle historis statis (tidak diubah, tidak dilonggarkan)

Dibanding regresi Fase 1 (`docs/phase-v7-1/bukti/regresi/ringkasan.txt`):

| Suite | V7 F1 → V7 F2 | Sebab |
| --- | --- | --- |
| `v7_phase1_static` | 49/0 → 46/3 | "030 adalah migrasi terakhir" (031 ada); "Hanya berkas Fase 1 yang berubah dari baseline 3e78564" (berkas Fase 2); "Wali lain: proyeksi ringkas tanpa riwayat" mencocokkan bentuk kode Fase 1 — perilaku tetap (wali lain ringkas tanpa riwayat/tindakan diuji J-1, W-B, W-E, C-C, dan integrasi Fase 1 I-3 baris wali kedua lulus). |
| `v6_phase3_static` | 55/2 → 54/3 | Sama dengan hitungan audit Codex Fase 1 (migrasi baru terlacak Git). |
| `redesain_web_tahap6_static` | 139/2 → 140/1 | Pemeriksaan "Navigation dan app/Auth tidak diubah" membaca perubahan belum di-commit; Fase 2 tidak mengubah Navigation. |

Dari 40 suite yang sama dengan Fase 1, hanya `v7_phase1_static` berpindah dari exit 0 ke exit 1; `v7_phase2_static` baru dan lulus. Suite statis lain yang gagal (`keamanan_psb` 82/1, `penugasan` 281/2, Tahap 2/3/4/7/8, `v2_phase2/4/5`, `v5_phase1/2/3/5`, `v6_phase1/2/4/5`) memiliki hitungan **identik** dengan regresi Fase 1; `v3_phase2/3_static` exit 77 (memerlukan DB V3 bernama tetap).

**Tidak dijalankan ulang** (dicatat, bukan disembunyikan): keamanan PSB HTTP/browser (Apache), suite V3/V4/V5 berbasis DB, V6 Fase 1–4, browser V3–V6, uji Android/iOS. Kode PSB, V3–V6, `app/Auth`, `app/Penugasan`, `app/Notification`, `app/Ui` (Navigation), `portal/izin_aksi.php`, dan halaman admin tidak berubah dari baseline (statis T-6).

## Penafsiran yang perlu ditinjau auditor/Human Developer

- **P-1 Status sesudah diteruskan memakai status V2** (`Diajukan`/`Perlu Penetapan Admin`) pada baris yang sama, sehingga antrean, penetapan, keputusan, dan koreksi murobi tetap kode V2 tanpa cabang baru. Asal wali tetap terbaca dari `asal_pengajuan` dan jejak tinjauan `Teruskan`.
- **P-2 Penolakan pembimbing** adalah tindakan tinjauan (`izin_pembimbing_tinjauan`, status `Ditolak Pembimbing`), bukan keputusan; label "Ditolak pembimbing" vs "Ditolak murobi" dibedakan di web/API untuk semua pembaca, termasuk wali lain.
- **P-3 Isi tidak berubah ditolak (`422`).** Perbaikan wajib mengubah tanggal, alasan, atau catatan; CHECK basis data menegakkan hal yang sama. Bila Human Developer ingin wali dapat "mengirim ulang tanpa perubahan" (mis. sekadar konfirmasi), aturan ini perlu dilonggarkan dengan keputusan.
- **P-4 Pembimbing tetap sama saat dikirim ulang.** Perbaikan mengembalikan pengajuan ke pembimbing tujuan semula. Bila penugasannya sudah tidak sah, pengajuan tampil di antrean admin (perilaku P-8 Fase 1) untuk ditetapkan ulang; tidak ada routing ulang otomatis.
- **P-5 Catatan penerusan** (opsional) terlihat oleh petugas (pembimbing, murobi, admin) dan di rincian V2, tetapi tidak oleh wali pengaju; catatan perbaikan dan alasan penolakan terlihat oleh wali pengaju. Keputusan murobi beserta alasannya terlihat oleh wali pengaju; wali lain hanya status.
- **P-6 Tampilan V2 orang tua tanpa baris wali.** Daftar/rincian/laporan V2 cakupan orang tua (web, API, CSV) tidak lagi memuat pengajuan berasal wali walau sudah berstatus V2; rincian V2 untuk ID itu → `403`. Orang tua membacanya di tab **Pengajuan wali**. Konsekuensi: tab Aktif/Riwayat "Izin anak" V2 tidak menghitung pengajuan wali (laporan lintas asal = Fase 3).
- **P-7 Notifikasi V2 dilewati untuk baris wali** (penetapan murobi, keputusan, koreksi). Sampai Fase 3 murobi tidak diberi tahu saat pengajuan diteruskan dan wali tidak diberi tahu saat diputus; mereka melihatnya di antrean/rincian. Jalur pengurus tetap memancarkan notifikasi V2 (diuji J-10).
- **P-8 Admin tidak dapat membatalkan pengajuan wali** lewat jalur V2 (PRD §5.1 "hanya wali pengaju"). Admin tetap dapat memutus sebagai pengganti beralasan dan menetapkan murobi/pembimbing.
- **P-9 Murobi membaca rincian V7** hanya sesudah diteruskan dan hanya bila ia murobi tujuan (guru akun dari DB, capability murobi aktif); sebelum penerusan `404`, murobi lain `404`. Keputusan tetap di halaman/API V2.
- **P-10 Putar ulang diperiksa hak.** Kiriman ulang kunci sama dari pembimbing yang penugasannya sudah dicabut, atau wali yang relasinya dicabut, ditolak (`404`) alih-alih mengembalikan hasil tersimpan — mengikuti pola audit V6 Fase 1 dan Fase 1 V7.

## Temuan dan catatan untuk Human Developer

- **T-1 (dari Fase 1, jalur pengurus V2, tidak diubah)** — kiriman bersamaan berkunci sama pada `IzinWorkflowService::create()` masih dapat menghasilkan 500 akibat deadlock klaim idempotensi. Fase 2 tidak menyentuh method itu. Tindakan V7 baru memakai kunci akun lebih dulu sehingga klik ganda 8 proses → 8 sukses (J-9).
- **Notifikasi V7 belum ada** (P-7) — sesuai urutan PRD, Fase 3.

## Batas yang belum diuji / terbuka

- Tidak ada uji MySQL 8, cPanel/hosting, atau produksi (lokal hanya MariaDB 12.3.2). Preflight memeriksa CHECK, InnoDB, binlog, dan tipe kolom, tetapi tidak menggantikan uji hosting.
- Konkurensi dibuktikan lewat proses + koneksi DB terpisah pada pola 4+4/8 proses (batal–teruskan, batal–keputusan, perbaikan–batal, keputusan murobi–admin pengganti, klik ganda tinjauan/perbaikan/batal); bukan bukti seluruh kemungkinan interleaving. Server PHP bawaan melayani HTTP berurutan.
- Aplikasi mobile, notifikasi in-app/Push V7, laporan lintas asal, dan audit pengiriman notifikasi belum dikerjakan (Fase 3–5).
- **Risiko urutan rilis.** Bila branch ini digabung dan dideploy sebelum Fase 3–5, `bin/migrate.php up` memasang 030 dan 031; alur wali → pembimbing → murobi berjalan **tanpa notifikasi** dan tanpa layar aplikasi. Alat rilis V6 masih mengasumsikan 029 migrasi terakhir (lihat regresi). Keputusan urutan rilis milik Human Developer.

## Langkah rilis dan pemulihan (untuk keputusan Human Developer kelak — bukan izin deploy)

1. Backup terverifikasi DB dan berkas; hentikan penulis selama pengambilan dan perbandingan manifest.
2. `php bin/v7_phase2_preflight.php /jalur/aman/manifest-031.json` — wajib tanpa `[gagal]`.
3. `php bin/migrate.php up` (menerapkan 031; 030 harus sudah ada).
4. `php bin/v7_phase2_verify.php /jalur/aman/manifest-031.json` — wajib "Verifikasi V7 Fase 2 lulus". Sesudah 031, gunakan verifier Fase 2 (verifier Fase 1 mengasumsikan versi = 1 + penetapan).
5. Pemulihan sebelum ada data Fase 2: `php bin/v7_phase2_rollback_preflight.php` lalu `php bin/migrate.php rollback`, kemudian `php bin/v7_phase2_verify.php --rollback /jalur/aman/manifest-031.json`. Sesudah ada tinjauan/perbaikan/pembatalan wali, rollback ditolak penjaga SQL; pulihkan dari backup atau buat migrasi korektif.

## Handoff

Implementasi Fase 2 selesai pada branch `claude/prd-v7-fase-2` di atas commit audit Fase 1 `192711e0b6bc2cadd46ffc79ee5dfa921f3a76d1`; commit implementasi `8b0d958fc836c5844f588099018dcbd156e03527`; commit sesudahnya hanya mencatat hash ini. Implementator berhenti untuk **audit independen Codex** sesuai prompt audit Fase 2 di `docs/prd-v7/prompts-implementasi-audit.md`. Tidak merge, deploy, migrasi produksi, rilis aplikasi, atau memulai Fase 3.

Audit independen Codex selesai sesudah handoff tersebut: tiga koreksi pada pemrosesan **baris wali melalui V2** (hak sesudah menunggu kunci, versi wajib/ikut sidik request, dan serialisasi retry keputusan). Statis 54/0, integrasi 119/0, HTTP 61/0, migrasi 27/0, browser 33/0; probe yang sama pada sumber asli 18/16 → koreksi 34/0. Regresi, oracle historis, batas dan penyesuaian daftar berkas uji statis Fase 2 dijelaskan di `AUDIT-CODEX.md`; bukti pada `audit-codex/`. Auditor berhenti setelah commit/push branch ini; Fase 3 memerlukan instruksi baru Human Developer.
