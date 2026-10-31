# Penugasan lintas guru/pengurus — Fase 5: kesiapan rilis

**Status (31 Oktober 2026): LULUS audit teknis lokal Codex setelah tiga koreksi alat rilis.** Laporan dan bukti independen: [`AUDIT-CODEX.md`](AUDIT-CODEX.md), `audit-codex/`. Kesiapan produksi belum dinyatakan lulus. Belum merge, belum deploy, belum migrasi produksi, belum rilis aplikasi. Tidak ada fase berikutnya dalam workstream ini; keputusan rilis tetap milik Human Developer.

| Butir | Nilai |
| --- | --- |
| Workstream | Penugasan lintas guru/pengurus dan Keamanan Fase 1–5 — implementator Claude Code, auditor Codex (`AGENTS.md`, keputusan 30 Oktober 2026) |
| Rancangan | [`../rancangan.md`](../rancangan.md) §4 Fase 5: preflight/verifier/manifest/latihan pemulihan, runbook cPanel, checklist smoke |
| Branch | `claude/penugasan-keamanan-fase-5` di **WebAlHasan** dan **alhasanApps** |
| Baseline web | Commit audit Fase 4 `39cc644` (= `origin/claude/penugasan-keamanan-fase-4`). `origin/main` `2a78675` (PR #106) memuatnya; `git diff 39cc644 origin/main` kosong. Branch dibuat dari `39cc644`, bukan `main`, tanpa worktree. |
| Baseline mobile | `origin/main` `d4d792a` (PR #23), isi sama dengan audit Fase 4 `c514e4f` (`git diff c514e4f origin/main` kosong). |
| Migrasi | **Tidak ada migrasi baru.** Rilis memasang 032 (Fase 1) dan 033 (Fase 3). |
| Kode aplikasi | **Tidak berubah** di web maupun mobile. Fase 5 hanya menambah alat CLI, uji, dan dokumen. |
| Lingkungan uji | PHP 8.4.14, MariaDB 12.3.2 lokal, fixture sintetis. Direktori web milik pekerjaan lain (`docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/`, `tests/browser/tangkapan-penugasan-keamanan-f1/`) dan perubahan `package.json`/`package-lock.json` mobile yang belum di-commit **tidak disentuh dan tidak di-commit**. |

## Yang dikerjakan

### Alat rilis (CLI saja; non-CLI → 404)

| Berkas | Isi |
| --- | --- |
| `bin/_penugasan_keamanan_rilis.php` | Konstanta rilis (032–033, prasyarat 031, baseline), snapshot preservasi (jumlah + hash isi seluruh tabel lama, `pengurus` tanpa `guru_id`; irisan baca; isi 032/033), dan hitungan SQL independen akun ber-`keamanan.pantau`. Hash multiset memakai `bin/_v7_rilis.php` yang sudah dikoreksi audit Codex V7. |
| `bin/penugasan_keamanan_preflight.php` | Server (CHECK sesi/global, InnoDB, jam DB = aplikasi), prasyarat tabel + seluruh tabel penugasan, 031 tercatat, empat role dasar, 032–033 berurutan dan tidak sebagian, tanpa migrasi > 033, tipe/mesin induk kunci asing, data terdampak (data ganda nama, akun ganda warisan, penugasan aktif per jenis), `--rilis` (daftar lengkap + 35 SHA), manifest privat 0600. Hanya membaca. |
| `bin/penugasan_keamanan_verify.php` | Lihat [Verifier](#verifier). Hanya membaca; aman berkala. `--irisan-baca` hanya membandingkan data yang dibaca Keamanan. |
| `bin/penugasan_keamanan_rollback_preflight.php` | Menilai rollback berikutnya: 033 hanya bila `keamanan_assignments` kosong (termasuk penugasan yang sudah diakhiri), 032 hanya bila belum ada identitas guru; menolak bila CHECK tidak aktif. |
| `bin/penugasan_keamanan_manifest.php` | SHA-256 33 migrasi, 2 rollback, `composer.lock`, baseline, dan berkas mobile dari HEAD Git → `manifest-migrasi.json`. |
| `bin/penugasan_keamanan_backup_restore_drill.php` | Latihan backup (`BackupWriter` atau `mariadb-dump`) → restore ke DB `_test` kedua → verifier + manifest → hash 25 tabel dan hak Keamanan sumber = pulihan → rollback berisi data ditolak. Hanya `test_*_test`, `PK_DRILL=1`, menolak `APP_ENV=production`. |

### Verifier

1. Verifier fondasi penugasan `bin/penugasan_verify.php` sebagai proses terpisah.
2. Skema 032–033: tipe `pengurus.guru_id` = `guru.id`, UNIQUE, enam kunci asing, kolom `keamanan_assignments` persis, CHECK rentang tanggal, InnoDB, tanpa tabel penjaga tersisa.
3. Identitas pengurus milik guru (PK-1), ditulis ulang terpisah dari kode aplikasi:
   - jabatan "Guru" tanpa nomor identitas; nama/HP/status/arsip = Data Guru;
   - hanya dipegang akun guru pemiliknya, tanpa relasi wali;
   - setiap pembentukan (`penugasan.identitas_guru.buat`) dan penautan akun (`account_identitas_guru_linked`) diaudit;
   - identitas milik guru nonaktif/diarsipkan tidak memberi capability penugasan apa pun (resolver `Capabilities`).
4. Penugasan Keamanan (PK-5/PK-6):
   - setiap baris diaudit `penugasan.buat`, pengakhiran beralasan;
   - akun ber-`keamanan.pantau` menurut resolver `Capabilities` = hitungan SQL independen;
   - setiap audit `keamanan.*` hanya memuat kunci metadata (daftar sama dengan `AuditLogger::metadataKeamanan`, diperiksa statis) dan berpelaku pemegang penugasan Keamanan.
5. Pemisahan tugas izin (Fase 2) sejak 032 tercatat: tidak ada keputusan atau koreksi oleh akun pengaju/peninjau pengajuan yang sama.
6. Dengan manifest: jumlah dan isi seluruh tabel lama, irisan baca, dan (bila manifest diambil sesudah rilis) isi 032/033.

Hasil `[info]` (bukan kegagalan) dicetak untuk ditinjau admin: akun ganda warisan, identitas tanpa akun/role, penugasan Keamanan tanpa akun sah, keputusan lama sebelum rilis, dan routing murobi sejak rilis ke guru milik akun yang terlibat.

### Uji

| Berkas | Isi |
| --- | --- |
| `tests/penugasan_keamanan_f5_db.sh` | Rantai DB Fase 3/4; `PKF5_TANPA_PK=1` melepas 033 → 032 sehingga DB berada di 031 berisi data V1–V7 (titik awal rilis). |
| `tests/penugasan_keamanan_f5_static.php` | S-1…S-6: alat hanya CLI dan tanpa SQL tulis, batas latihan restore, konstanta dan sinkron kunci audit, manifest = disk, batas berkas terhadap baseline, dokumen, lint. |
| `tests/penugasan_keamanan_f5_rilis.php` | R-1…R-8 (`PK_RUN_RILIS=1`): preflight + manifest, jam DB, migrasi asing, naik–turun–naik, pemasangan sebagian, penjaga rollback dengan data, latihan restore dua metode, sembilan kontrol pengrusakan verifier. |
| `tests/penugasan_keamanan_f5_e2e.php` | E-1…E-6: Fase 1–4 pada satu DB sesudah migrasi lewat server PHP lokal (sesi web dan token API), verifier di setiap langkah, irisan baca sebelum/sesudah seluruh jalur Keamanan, siklus nonaktif guru dan pencabutan penugasan. |
| `perintah-uji.sh`, `perintah-regresi.sh` | Perintah yang menghasilkan `bukti/`. |

### Dokumen

- [`runbook-rilis-pemulihan.md`](runbook-rilis-pemulihan.md) — keadaan awal, backup/restore point, urutan rilis, cron opsional, rollback aman, pemantauan. **Belum dieksekusi pada produksi.**
- [`checklist-smoke.md`](checklist-smoke.md) — S-1…S-20; seluruh kolom produksi **Menunggu pelaksanaan**.
- [`manifest-migrasi.json`](manifest-migrasi.json) — manifest rilis ter-commit.
- Ringkasan mobile: `alhasanApps/docs/penugasan-keamanan-fase-5/README.md`.

## Bukti

| Uji | Hasil | Berkas |
| --- | --- | --- |
| Statis Fase 5 S-1…S-6 | **36/0** | `bukti/statis.txt`; kontrol mutasi `bukti/kontrol-statis.txt` (SQL tulis di verifier → 35/1; berkas baru di `app/` → 34/2) |
| Rilis R-1…R-8 (latihan writer 11/0, dump 12/0 di dalamnya) | **49/0** | `bukti/rilis.txt` |
| E2E E-1…E-6 (15 halaman web + 10 endpoint per akun, 43 mutasi → 405, irisan baca tetap, 32 jejak `keamanan.*`) | **19/0**; verifier akhir 28/0 | `bukti/e2e.txt`, `bukti/verifier-e2e.txt` |
| Verifier V5/V6/V7 lama pada DB E2E sesudah 032–033 dan pemakaian Keamanan | exit 0, 0 gagal (ketiganya) | `bukti/verifier-lama-*.txt` |
| Mobile tsc/lint/unit (HEAD `d4d792a` tanpa `.env*`, lock ter-commit) | tsc 0, lint 0, unit 47/0 | `bukti/mobile-tsc-lint-unit.txt` |
| Regresi | lihat [Regresi](#regresi) | `bukti/regresi/` |

### Kontrol

- Verifier diuji dengan **sembilan kontrol pengrusakan** (R-8): setiap kerusakan menghasilkan `[gagal]` yang diharapkan, lalu lulus lagi sesudah dipulihkan:
  - nama/jabatan identitas diubah langsung;
  - identitas dipindah ke akun guru lain;
  - audit pembentukan identitas atau pembuatan penugasan Keamanan dihapus;
  - audit `keamanan.*` berisi alasan atau berpelaku admin;
  - keputusan izin oleh pengaju;
  - baris pengurus biasa dan alasan izin diubah sesudah manifest.
- Preflight menolak manifest rilis yang hashnya diubah atau daftarnya terpotong, jam DB berbeda, migrasi asing sesudah 033, dan pemasangan sebagian. Verifier juga menolak pemasangan sebagian.
- Penjaga rollback diuji dengan dua jalur: preflight rollback, dan `migrate.php rollback` langsung (ditolak penjaga SQL, data dan catatan migrasi tetap).
- Tidak ada "kontrol kode baseline": alat rilis tidak ada di baseline. Bukti efektivitasnya adalah kontrol pengrusakan di atas.

## Regresi

Fase 5 tidak mengubah kode aplikasi. Regresi membuktikan dua hal:

- suite Fase 1–4 tetap lulus seperti handoff Fase 4;
- verifier rilis **lulus** pada keadaan DB yang dibentuk setiap suite resmi (tidak memberi positif palsu).

Ringkasan di `bukti/regresi/ringkasan.txt` (`perintah-regresi.sh`).

| Rangkaian | Hasil | Verifier rilis pada DB sesudah suite |
| --- | --- | --- |
| Fase 1 integrasi / probe audit + token / HTTP | 70/0 · 20/0 + 10/0 · 34/0 | 28/0 · 28/0 · 28/0 |
| Fase 2 integrasi / HTTP | 53/0 · 28/0 | 28/0 · 28/0 |
| Fase 3 integrasi / HTTP / migrasi / probe audit | 44/0 · 44/0 · 19/0 · 26/0 | 28/0 · 28/0 · — · 28/0 |
| Fase 4 HTTP | 66/0 | 28/0 |
| Fondasi penugasan integrasi | 161/0 | — |
| V7 Fase 5 E2E / HTTP | 82/1 · 40/0 | — |
| V7 Fase 5 rilis | 22/15 | — |
| 50 suite statis | 18 exit 0, 30 exit 1, 2 exit 77 (Fase 4: 18 · 29 · 2 dari 49; statis Fase 4 kini exit 1, statis Fase 5 baru exit 0) | — |

Pembanding: handoff/audit Fase 4 (`../fase-4/bukti/regresi/ringkasan.txt`). Hasil identik exit dan hitungan, kecuali empat hal:

- **Statis penugasan Fase 1/2/3:** hitungan lulus bertambah 9 (126/4, 108/2, 92/3) karena oracle melint setiap berkas yang berubah, termasuk berkas Fase 5. Jumlah gagal sama dan judul gagal sama (batas berkas/migrasi terakhir historis).
- **Statis penugasan Fase 4: 35/0 → 43/1.** Satu-satunya kegagalan baru adalah oracle "Hanya berkas Fase 4 yang berubah terhadap 6ebdc24", yang kini juga melihat berkas Fase 5. Oracle tidak dilonggarkan.
- **Statis Fase 5** baru: 36/0.
- **V7 Fase 5 rilis 22/15** (tidak dijalankan di regresi Fase 4). Oracle historis: `tests/v7_phase5_db.sh` dengan `V7F5_TANPA_V7=1` hanya melepas migrasi `03[01]_v7_*`. Sejak 032 (Fase 1) migrasi terakhir di DB adalah 033, sehingga tidak ada yang dilepas dan titik awal "029 terakhir" tidak tercapai. Seluruh 15 kegagalan berasal dari titik awal itu. Penggantinya untuk rilis ini adalah `tests/penugasan_keamanan_f5_rilis.php`. Oracle V7 tidak diubah.

Kegagalan V7 Fase 5 E2E 82/1 (preflight historis menolak 032), statis lain, dan dua exit 77 V3 sama dengan Fase 4. Probe audit Codex Fase 2 (perlu arsip baseline `464d9a1`) dan matriks laporan Fase 3 (perlu server/sakelar audit) tidak diulang karena kode yang mereka periksa tidak berubah. V2, V3, V5, V6, dan redesain HTTP juga tidak diulang karena Fase 5 tidak menyentuh kode aplikasi (S-4 statis).

## Penafsiran (diterima dalam lingkup lokal; lihat audit Codex)

- **PF5-1 Rilis utuh.** 032 dan 033 dirilis bersama kode Fase 1–4. Preflight dan verifier menolak 032 tanpa 033. Fase 1 tanpa pemisahan tugas Fase 2 tidak boleh hidup di produksi, dan Fase 2 tidak memiliki migrasi sehingga dirilis bersama kodenya.
- **PF5-2 Batas waktu pemisahan tugas.** Invarians keputusan/koreksi izin berlaku sejak `schema_migrations.applied_at` 032, sebagai pengganti waktu deploy kode Fase 2 yang tidak tercatat di DB. Keputusan sebelumnya adalah riwayat lama dan hanya dilaporkan. Routing murobi ke guru milik akun terlibat hanya `[info]`, karena `users.guru_id` dapat berubah sesudahnya dan perubahan bersamaan tidak dikunci (batas Fase 2).
- **PF5-3 Sikap V4, PK-T1, dan tanda mengetahui V3 tidak menjadi invarians verifier.** Fase 2 menetapkan tanda "diketahui" lama oleh pengisi tidak dihitung sah tetapi barisnya tidak diubah, sehingga data sah dapat memuat baris itu. Aturan ini tetap diuji suite Fase 2.
- **PF5-4 Irisan baca.** Tabel yang berubah sah karena masuk/token (`users`, `api_tokens`, `api_idempotency_keys`), role dan identitas yang dibentuk penugasan (`user_roles`, `pengurus` identitas guru), seluruh tabel penugasan, `audit_logs`, `schema_migrations`, dan kunci worker dikecualikan. Baris pengurus biasa dibandingkan tersendiri. Tabel yang dibaca Keamanan dipastikan tidak dikecualikan (statis S-2).
- **PF5-5 Pelaku audit `keamanan.*`.** Diperiksa terhadap penugasan Keamanan apa pun (termasuk yang sudah berakhir) milik pengurus yang kini terhubung ke akun pelaku. Penautan akun yang diubah kemudian dapat menghasilkan `[gagal]` yang memerlukan investigasi, bukan pembersihan data.
- **PF5-6 Hitungan SQL independen** ditulis tanpa memanggil `Capabilities`/`KeamananAkses` (statis S-1) dan mencakup admin yang memegang penugasan Keamanan sendiri (sumber "keduanya"), sama dengan `KeamananAkses::sah`.
- **PF5-7 Pembantu bersama.** Fungsi hash/skema dipakai dari `bin/_v7_rilis.php` (sudah diaudit dan dikoreksi Codex V7 Fase 5), bukan disalin.
- **PF5-8 Prasyarat 031.** Keamanan membaca izin asal Pengurus dan Wali, sehingga preflight mewajibkan 031. Catatan sebelumnya menyebut produksi sudah di 031; inventaris ulang tetap wajib (runbook §1).
- **PF5-9** Preflight rollback menolak bila CHECK tidak aktif, karena penjaga SQL bergantung pada CHECK.

## Catatan pengembangan

- Versi pertama verifier menggabungkan `TABLE_CONSTRAINTS` dengan `KEY_COLUMN_USAGE`, dan MariaDB membuka seluruh tabel di server untuk kueri itu. Latihan restore tertahan beberapa menit di server lokal yang berisi banyak DB uji. Diperbaiki sebelum bukti diambil dengan menyaring `KEY_COLUMN_USAGE` langsung per skema/tabel; verifier kini selesai < 1 detik pada DB uji.
- Kontrol pengrusakan pertama gagal memulihkan manifest dasar karena kolom `updated_at` ber-`ON UPDATE` pada `pengurus` dan `izin_pengajuan` ikut berubah. Kesalahan ada di pembantu uji, bukan alat. Uji kini memakai `updated_at = updated_at`.

## Batas

- **Belum diuji:** MariaDB 10.6 (versi produksi menurut catatan sebelumnya), MySQL, cPanel/hosting, produksi, hak akun pemulihan hosting (routine/trigger), UAT.
- **Aplikasi:** kode tidak berubah dan layar tidak diuji ulang di Fase 5. Runtime iOS, perangkat fisik, build distribusi, dan Push nyata belum pernah diuji (sama dengan Fase 4).
- Dua akun terpisah milik satu orang (data ganda guru/pengurus) tidak dikenali pemisahan tugas. Ini keputusan "peringatan saja" yang tetap berlaku.
- Gerbang produksi V5/V6/V7 tetap terbuka dan tidak ditutup oleh rilis ini.
- Preflight rilis V5/V6/V7 menolak DB sesudah 033 karena masing-masing hanya untuk sebelum rilisnya. Verifier V5/V6/V7 tetap lulus.

## Handoff

Audit independen Codex selesai; tiga koreksi dan seluruh batas dicatat di `AUDIT-CODEX.md`. Tidak merge, deploy, migrasi produksi, atau rilis aplikasi. Berhenti setelah commit/push hasil audit Fase 5.
