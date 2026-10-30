# Aplikasi: "Terbitkan tagihan" satu santri dari tarif

**Status (30 Oktober 2026):** diimplementasikan Claude Code atas permintaan Human Developer, **menunggu audit independen Codex**. Belum merge, belum rilis aplikasi, tanpa migrasi, **tanpa perubahan kode web**. Bukti dari Expo web (Chromium 375 px) dengan API dan DB salinan lokal berfixture sintetis (MariaDB 12.3.2, PHP 8.4.14). Perangkat native, perangkat fisik, Push, MySQL/cPanel belum diuji.

| Butir | Nilai |
| --- | --- |
| Asal | Tindak lanjut perbaikan web PR #100 (`docs/perbaikan-tagihan-satu-santri/`). Human Developer bertanya apakah aplikasi juga perlu diperbaiki; aplikasi tidak salah (penerbitannya sudah memakai tarif, massal), lalu Human Developer memilih **"Ya, tambahkan"** tombol terbit satu santri di aplikasi. |
| Peran | Implementator Claude Code, auditor Codex. |
| Branch | Mobile `claude/terbit-tagihan-satu-santri` dari `origin/main` `92caea4` (commit `a815d45`). Web `claude/terbit-tagihan-satu-santri-app` dari `origin/main` `b55730b` (merge PR #100), hanya uji dan dokumen. Tanpa worktree. |
| Ketergantungan server | Penerbitan satu santri memerlukan server yang sudah memuat PR #100 (`santri_id` pada pratinjau/penerbitan). |

## Perubahan (repo alhasanApps)

- `src/app/keuangan/santri/[id].tsx` — tombol **Terbitkan tagihan** di rincian santri, hanya bila `akses.kelola` (Petugas BSA berpenugasan aktif untuk santri aktif dalam cakupan; sama dengan tombol Setoran).
- `src/app/keuangan/terbit-santri.tsx` (baru) — periode + jatuh tempo → **Tampilkan tagihan** (`POST /v6/bsa/penerbitan/pratinjau` dengan `santri_id`, tidak menulis) → rincian tarif (komponen tarif, potongan/tambahan santri, total) → **Terbitkan tagihan Rp…** (`POST /v6/bsa/penerbitan` dengan hash pratinjau, `santri_id`, kunci idempotensi `useKeuanganMutation`) → membuka rincian tagihan baru. Bila tagihan bulan itu sudah ada: tautan **Buka tagihan**. Bila tarif tidak dapat dipakai (tanpa kelas / unit tanpa tarif): penjelasan dan arahan ke website, tanpa isian manual di aplikasi.
- **Penjaga server lama:** server sebelum PR #100 mengabaikan `santri_id` dan memratinjau seluruh unit. `pratinjauSatuSantriSah()` (`src/keuangan/forms.ts`) hanya menerima respons yang membawa `santri_id` sama dan tepat satu baris santri itu; selain itu layar menolak tanpa tombol terbit, sehingga aplikasi tidak pernah mengirim penerbitan seluruh unit dari layar ini.
- `src/api/keuangan.ts` — `santri_id?` pada pratinjau/terbitkan, `komponen?` pada baris pratinjau (aditif). `src/app/keuangan/_layout.tsx` — judul rute. `tests/keuangan.test.ts` — uji unit penjaga.
- Tidak berubah: kode web, migrasi, hak/penugasan, layar Penerbitan massal, perlindungan layar navigator Keuangan (T-1), paket. Perubahan `package.json`/`package-lock.json` milik pekerjaan lain tidak di-commit.

## Hasil uji

| Rangkaian | Hasil | Berkas |
| --- | --- | --- |
| Expo web `tests/browser/uji-tagihan-satu-santri-app.mjs` | **27/0**, API 0 galat PHP | `bukti/expo-web.txt`, `tangkapan/` |
| Kontrol: penjaga server lama dimatikan (`pratinjauSatuSantriSah` → `true`, bundel diperiksa memuat perubahan) | 15/1, berhenti di A-5 (layar menawarkan terbit dari respons seluruh unit) | `bukti/kontrol-penjaga-server-lama-dimatikan.txt` |
| tsc / lint / unit (repo mobile, paket ter-commit Expo 57.0.15) | exit 0 / exit 0 / 43/0 | `docs/terbit-tagihan-satu-santri/bukti/` di alhasanApps |
| Regresi Expo web V6 Fase 4 (`uji-v6-fase4-app.mjs`) | 45/0, 0 galat PHP | `bukti/regresi-v6-fase4-app.txt` |
| Regresi Expo web V6 Fase 5 (`uji-v6-fase5-app.mjs`) | 20/0, 0 galat PHP | `bukti/regresi-v6-fase5-app.txt` |
| Statis perbaikan web PR #100 (`tests/v6_tagihan_satu_santri_static.php`) | 23/1 | `bukti/statis-perbaikan-web-pr100.txt` |

Uji Expo web mencakup:
- **A-1** petugas: rincian santri → tombol → rincian tarif sama dengan API (Syahriah Rp150.000, Makan Rp200.000, potongan −Rp50.000, total Rp300.000); pratinjau tanpa POST penerbitan dan tanpa tagihan; tanpa isian komponen manual;
- **A-2** ketukan ganda → satu kiriman, tepat satu tagihan Rp300.000, santri SMP lain tidak ikut, catatan penerbitan sasaran 1, layar membuka tagihan baru;
- **A-3** buka ulang: "sudah ada", tanpa tombol terbit, **Buka tagihan** ke tagihan yang sama;
- **A-4** santri tanpa kelas: penjelasan dan arahan ke website, tanpa tagihan;
- **A-5** respons server lama (tanpa `santri_id`, dua baris) → ditolak, nol POST penerbitan;
- **A-6** penugasan dicabut sesudah pratinjau → server menolak, rincian ditutup, tanpa tagihan;
- **A-7** admin dan wali: tanpa tombol, alamat langsung ditolak tanpa formulir/POST; petugas Tsanawi: tombol untuk santri unitnya, santri SMP → 404;
- **A-8** 375 px tanpa gulir samping; penyimpanan/konsol tanpa token; nol permintaan keluar 127.0.0.1.

Bundel Expo web diperiksa: 0 `alhasan.co.id`; Metro dijalankan ulang dengan `--clear` dari salinan tanpa `.env*` dengan `node_modules` nyata dari `npm ci` lock ter-commit, dan sumber salinan dibandingkan identik dengan checkout sebelum jalan akhir.

**Oracle historis:** `v6_tagihan_satu_santri_static.php` Z-1 ("berkas berubah dari `0b2fcd1` hanya daftar perbaikan PR #100") kini gagal karena berkas uji dan dokumen baru ini. Kode web (`app/`, `portal/`, `api/`, `database/`) tidak berubah dari `b55730b`; oracle tidak diubah atau dilonggarkan.

## Penafsiran untuk ditinjau auditor/Human Developer

- **Q-1** Aplikasi tidak menyediakan isian komponen manual; untuk santri tanpa kelas atau unit tanpa tarif layar mengarahkan ke website (sama dengan pembagian tarif yang hanya diatur di website).
- **Q-2** Jatuh tempo bawaan tanggal 10 bulan periode, sama dengan layar Penerbitan.
- **Q-3** Sesudah terbit, layar membaca ulang rincian santri untuk menemukan tagihan periode itu dan membukanya; bila gagal dibaca, layar kembali ke rincian santri (tagihan tetap terbit).
- **Q-4** Penerbitan satu santri dari aplikasi tercatat sebagai satu penerbitan sasaran 1 (P-1 perbaikan web).

## Batas

Expo web bukan perangkat native: Android/iOS native, perangkat fisik, Push, MySQL/cPanel/produksi dan UAT belum diuji. Konkurensi lintas perangkat bergantung pada jalur server yang sudah diaudit di PR #100. Aplikasi yang memakai server tanpa PR #100 menolak penerbitan satu santri (A-5) dan petugas tetap memakai menu Penerbitan atau website.

Ulangi (berurutan, satu suite pada satu waktu):

```bash
V6F5_DB=test_v6_ss_app_test bash tests/v6_phase5_db.sh
DB_NAME=test_v6_ss_app_test php tests/v6_tagihan_satu_santri_seed.php
DB_NAME=test_v6_ss_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8986 tests/redesain_web_tahap1_router.php &
# salinan alhasanApps tanpa .env*: EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8986/api/v1 npx expo start --web --port 8099 --clear
cd tests/browser && DB_NAME=test_v6_ss_app_test SMP_LAIN=<id santri SMP aktif selain V6S0001> V6_WEB=http://127.0.0.1:8986 V6_APP=http://127.0.0.1:8099 node uji-tagihan-satu-santri-app.mjs
```
