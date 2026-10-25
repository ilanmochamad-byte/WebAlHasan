# PRD V6 Fase 2 — Tagihan Bulanan Lengkap

**Status (25 Oktober 2026): LULUS audit independen Codex setelah empat koreksi terarah** (snapshot penyesuaian saat konkurensi, hak santri kini pada hasil penerbitan, sisa pada replay pembayaran, riwayat koreksi tagihan era 027). Lihat `AUDIT-CODEX.md`; bukti auditor terpisah di `audit-codex/`. Belum merge, belum deploy, belum migrasi produksi, belum rilis aplikasi. Fase 3 belum dimulai. Fase 2 **bukan** izin rilis parsial: tagihan dan tabungan dirilis bersama sesudah Fase 5 dan keputusan Human Developer.

| Butir | Nilai |
| --- | --- |
| Workstream | PRD V6 Fase 1–5 keuangan santri/BSA — implementator Claude Code, auditor Codex (`AGENTS.md`, keputusan 25 Oktober 2026) |
| Branch | `claude/prd-v6-fase-2` |
| Baseline web | Commit audit Fase 1 `e895dbe2a95ee7cb75acce9a3edbadf23c239a92` (`fix(v6): audit Fase 1…`, = `origin/claude/prd-v6-fase-1`). `origin/main` `a0f939ade79d47a966851eab855e1a6c6f2093de` (merge PR #87) memuatnya; `git diff e895dbe origin/main` kosong. Branch dibuat dari `e895dbe`, tidak bekerja di `main`. |
| Mobile | `~/alhasanApps` tidak diubah dan tidak dibutuhkan Fase 2. `origin/main` `f8ebba0`; salinan kerja berada di branch lain (`claude/aset-appstore-header`) dengan `package.json`/`package-lock.json` belum di-commit milik pekerjaan lain — tidak disentuh. |
| Lingkungan uji | PHP 8.4.14, MariaDB 12.3.2 lokal (`innodb_snapshot_isolation=ON`), Apache 2.4 + php-fpm lokal (regresi PSB), Chromium Playwright. Seluruh DB `test_*_test` dengan fixture sintetis; tidak ada data produksi yang dibuka/diubah. |

Tidak ada keputusan Human Developer baru selama Fase 2. Penafsiran yang perlu ditinjau auditor dicatat di bawah (Q-1…Q-16).

## Yang dikerjakan (persyaratan Fase 2)

1. **Migrasi aditif `028_v6_fase2_tagihan_bulanan.sql`** — sembilan tabel baru, hanya `CREATE TABLE IF NOT EXISTS`; **tidak ada ALTER/DROP/DML** terhadap tabel mana pun, termasuk enam tabel 027. Rupiah `BIGINT`, 28 kunci asing, 22 `CHECK`:
   - `bsa_tarif` + `bsa_tarif_komponen` — tarif per unit/jenjang mulai satu periode; kolom terbangkit `kunci_berlaku` berindeks unik menjamin **satu tarif Berlaku per unit + periode mulai**; mengganti tarif periode mulai yang sama menandai yang lama `Diganti` (nominal lama tidak diubah, menunjuk penggantinya).
   - `bsa_penyesuaian` — penyesuaian per santri (Potongan/Tambahan) untuk rentang periode, **alasan wajib** (CHECK ≥ 5 karakter); pencabutan beralasan (status + aktor + waktu + alasan), tanpa DELETE.
   - `bsa_penerbitan` + `bsa_penerbitan_santri` — satu penerbitan massal (periode, unit, jatuh tempo, hash pratinjau, jumlah) dan hasil per santri sasaran (`Terbit`/`SudahAda`/`Dilewati` + alasan).
   - `bsa_tagihan_penyesuaian` — baris penyesuaian pada tagihan (dari penyesuaian santri saat terbit atau dari revisi), hanya INSERT. Total tagihan = Σ komponen + Σ tambahan − Σ potongan.
   - `bsa_tagihan_riwayat` — satu baris per versi tagihan (`Terbit`, `Revisi`, `Dibatalkan`) dengan aktor, waktu, penugasan, total, jatuh tempo, alasan.
   - `bsa_pembayaran_kuitansi` — snapshot kuitansi yang dibuat sekali saat pembayaran sah (versi + total tagihan, terbayar sebelum, sisa sesudah).
   - `bsa_pembayaran_pembatalan` — entri penyeimbang beralasan untuk pembayaran sah yang dibatalkan atau transfer menunggu yang ditolak.
   Rollback berpasangan melepas sembilan tabel itu saja, didahului **penjaga data SQL**: bila ada satu baris di tabel 028 atau `bsa_pembayaran` (027), rollback berhenti sebelum DROP.
2. **Layanan `App\Bsa\TagihanService` + `TagihanRepository`** (satu pintu web dan API). Hak memakai penjaga `BsaService` Fase 1 yang sama (dihitung ulang dari DB setiap panggilan): petugas memutasi santri **aktif** dalam cakupan unitnya; admin dan admin+petugas hanya membaca; wali hanya membaca anaknya; di luar hak → 404. Setiap mutasi: satu transaksi READ COMMITTED, urutan kunci **penugasan (baca) → klaim idempotensi (tulis) → santri (baca) + cakupan → [tarif berlaku / penyesuaian] → tagihan (tulis) → pembayaran (tulis)**; **cakupan dan status santri diperiksa ulang sebelum replay** (pelajaran temuan F-1 audit Fase 1); idempotensi per akun (kunci + isi sama → hasil sama, isi beda → 409); audit wajib (gagal audit membatalkan seluruh transaksi).
   - **Tarif**: unit kanonik dari `kelas.jenjang` aktif; petugas unit hanya untuk unitnya; alasan wajib bila unit sudah pernah bertarif.
   - **Pratinjau** (tanpa menulis) dan **penerbitan massal**: santri aktif sasaran (cakupan petugas, opsional satu unit), tarif efektif per unit (berlaku_mulai terbesar ≤ periode), penyesuaian aktif yang mencakup periode; hasil per santri + hash. Penerbitan wajib membawa hash pratinjau; server mengunci santri sasaran (urut ID), menghitung ulang, dan menolak 409 bila berubah. Satu transaksi untuk seluruh tagihan; komponen tarif disalin ke snapshot `bsa_tagihan_komponen` (027) dan penyesuaian santri ke `bsa_tagihan_penyesuaian`. Santri yang sudah punya tagihan periode itu (`SudahAda`, termasuk tagihan dibatalkan) tidak mendapat tagihan kedua; tanpa tagihan baru → 422. Jatuh tempo: antara awal periode dan tiga bulan sesudahnya.
   - **Pembayaran tunai/transfer**: tunai sah seketika (pencatat = verifikator); transfer sah seketika hanya bila petugas menyatakan sudah memeriksa dana masuk, selain itu `Menunggu` dan **belum** mengurangi sisa. Pembayaran sebagian boleh; nominal di atas sisa dikurangi transfer menunggu → 422. Pembaruan `terbayar` bersyarat nilai yang dikunci; CHECK `terbayar ≤ total` sebagai pertahanan terakhir. Nomor kuitansi `BSA-KW-TTTT-NNNNNN` dari ID, unik.
   - **Verifikasi** transfer menunggu (petugas mana pun dalam cakupan) → sah + kuitansi; **pembatalan/penolakan** beralasan → status `Dibatalkan`, nominal dan nomor kuitansi tidak diubah, entri penyeimbang + aktor + alasan; sisa naik kembali untuk pembatalan pembayaran sah.
   - **Revisi** tagihan beralasan (optimistic `versi`): satu baris potongan/tambahan dan/atau jatuh tempo baru, versi naik, riwayat tercatat; total baru wajib > 0 dan ≥ terbayar + menunggu. **Pembatalan** tagihan beralasan hanya bila belum ada pembayaran sah/menunggu.
   - **Tunggakan** (tagihan Terbit bersisa; saringan periode, unit, lewat jatuh tempo) dan **riwayat pembayaran** (saringan periode, status, cara) menurut hak; wali hanya anaknya.
   - Tagihan **tidak memotong tabungan** (ledger tidak disentuh Fase 2).
3. **Web** (kerangka bersama, POST + CSRF, `no-store`, kunci idempotensi tersembunyi di setiap formulir): `portal/bsa_tarif.php`, `bsa_penerbitan.php` (pratinjau GET → Terbitkan POST, riwayat, `?id=` hasil), `bsa_penyesuaian.php`, `bsa_bayar.php`, `bsa_tagihan_aksi.php` (revisi, batal, verifikasi, batal/tolak pembayaran; GET → 405), `bsa_kuitansi.php` (siap cetak/PDF A4, templat cetak bersama X-1, cap DITERIMA/DIBATALKAN), `bsa_tunggakan.php`, `bsa_pembayaran.php`; `bsa_tagihan.php` diperluas (rincian biaya + baris penyesuaian, pembayaran, koreksi dalam dialog, riwayat versi). Navigasi bagian keuangan: petugas/admin Santri · Tunggakan · Pembayaran · Penerbitan · Tarif; orang tua Anak · Belum lunas · Pembayaran. Menu Keuangan (keputusan BE) tidak berubah; hanya halaman aktifnya ditambah.
4. **API** `/api/v1/v6/bsa/*` aditif (layanan yang sama, `no-store`): tarif, penyesuaian, pratinjau/penerbitan, pembayaran/verifikasi/batal, revisi/batal tagihan, tunggakan, kuitansi. `GET /v6/bsa/tagihan/{id}` = kontrak Fase 1 + `penyesuaian`, `pembayaran`, `menunggu`, `riwayat`. Kontrak di `docs/api-v1.md`.
5. **Operasi**: `bin/v6_phase2_preflight.php [manifest]` (CHECK + kolom terbangkit, InnoDB, binlog, 027 tercatat, manifest jumlah baris seluruh tabel lama termasuk enam tabel 027 + sidik jari ID identitas **dan isi uang 027**), `bin/v6_phase2_verify.php [--rollback] [manifest]` (menjalankan seluruh invarians Fase 1, lalu 9 tabel, 28 FK, 0 yatim, invarians tarif/riwayat versi/pembayaran/kuitansi/pembatalan/penerbitan, audit tanpa data uang), `bin/v6_phase2_rollback_preflight.php` (blokir bila ada data Fase 2 atau pembayaran, atau 028 bukan migrasi terakhir). Fixture: DB Fase 1 (`tests/v6_phase2_db.sh`); tarif/tagihan/pembayaran dibuat uji lewat layanan.
6. **Perubahan kecil pada berkas Fase 1/bersama** (seluruhnya diuji regresi): helper penjaga/normalisasi `BsaService` dijadikan publik untuk `TagihanService` (logika tidak berubah); `BsaRepository::simpanTagihan` menulis riwayat versi 1 bila 028 ada, `joinUnit()`/`waliSyarat()` publik (satu definisi syarat unit/relasi wali); `bin/v6_phase1_verify.php` menghitung FK hanya enam tabel 027 dan total tagihan memperhitungkan baris penyesuaian bila 028 ada; `AuditLogger` menambah kunci metadata ID/jumlah (tanpa nominal/alasan); `Status` menambah label Berlaku/Dicabut/Sudah ada/Dilewati; `assets/ui/alhasan.css` — kartu ringkasan `.ah-stat` boleh membungkus nilai panjang (nominal besar sempat melebarkan halaman 5–14 px pada 375 px).

## Kriteria penerimaan → bukti

| Kriteria Fase 2 | Bukti |
| --- | --- |
| Tarif unit yang sama menghasilkan tagihan sesuai santri sasaran; satu penyesuaian per santri beralasan mengubah hanya tagihan santri tersebut | `bukti/integrasi.txt` J-4 (setiap santri SMP lain Rp500.000 dari tarif SMP, Tsanawi Rp250.000 dari tarif penggantinya; santri berpenyesuaian Rp350.000; baris penyesuaian hanya pada tagihannya; tagihan SMP lain seragam), J-3; `bukti/http.txt` K-B (Rp400.000 lewat formulir); `bukti/browser.txt` C-B |
| Menjalankan penerbitan periode yang sama dua kali tidak membuat tagihan kedua atau mengubah snapshot tagihan pertama | J-4 (replay kunci sama → penerbitan sama; kunci lain → 422; pratinjau basi → 409; sidik jari komponen/total/versi seluruh tagihan periode tetap; tagihan manual Fase 1 untuk santri+periode itu → 409), J-8 (dua penerbitan massal bersamaan → satu penerbitan, tanpa tagihan ganda); K-B (klik ganda, terbit ulang); C-B |
| Pembayaran Rp40.000 lalu Rp60.000 atas tagihan Rp100.000 → sisa Rp0 dan dua transaksi terverifikasi; membayar lebih dari sisa ditolak | J-5 (dua pembayaran sah berkuitansi, sisa sesudah Rp60.000 lalu Rp0; Rp100.001, Rp60.001, dan Rp1 sesudah lunas → 422; transfer menunggu ikut membatasi), J-8 (dua pembayaran Rp60.000 bersamaan → 1 sukses + 422; delapan Rp20.000 → lima sukses; delapan kunci sama → satu); K-C, K-E, K-F; C-C (375/768/1280) |
| Perubahan tarif setelah terbit tidak mengubah nominal atau kuitansi tagihan lama | J-6 (tarif Oktober diganti + tarif November baru: nominal/versi/snapshot seluruh tagihan Oktober dan seluruh baris kuitansi identik; November memakai tarif baru), J-7 (revisi tidak mengubah kuitansi lama); K-C (tampilan kuitansi identik sebelum/sesudah perubahan tarif) |
| Wali lain tidak dapat membaca tagihan, kuitansi, atau API anak yang bukan relasinya, termasuk ID ditebak | J-10 (seluruh ID pembayaran 1..maks ditebak wali → hanya anaknya, lainnya 404; daftar/tunggakan hanya anaknya; tanpa referensi/catatan/alasan/transfer menunggu), J-11; K-D (setiap kuitansi web ditebak → 404), K-E (API 404), K-G (relasi dicabut pada sesi/token lama → 404) |
| Persyaratan 5: kegagalan transaksi, kiriman ganda, dua pembayaran bersamaan, perubahan tarif setelah terbit, pencabutan penugasan pada sesi lama | J-9 (audit dipaksa gagal lewat trigger uji: pembayaran, verifikasi, pembatalan, revisi, penerbitan massal, tarif → 503 dan nol baris/klaim/kuitansi), J-8, J-6, J-11 (penugasan dinonaktifkan → 403; cakupan dipersempit/santri dinonaktifkan → replay 404); K-G |

## Hasil pengujian Fase 2 oleh implementator (sebelum audit)

| Rangkaian | Hasil | Berkas |
| --- | --- | --- |
| Statis `tests/v6_phase2_static.php` (migrasi aditif, rollback berpenjaga, tanpa float/interpolasi SQL, urutan kunci + cakupan sebelum replay, CSRF/escape/kunci idempotensi, rute, audit, batas Fase 2 via `git diff e895dbe`, lint) | 62/0 | `bukti/statis.txt` |
| Integrasi layanan + konkurensi proses terpisah + kegagalan transaksi + verifier + preservasi `tests/v6_phase2_integration.php` | 179/0 | `bukti/integrasi.txt` |
| HTTP web + API lewat server PHP lokal `tests/v6_phase2_http.php` (log server 0 galat PHP, 0 referensi transfer) | 110/0 | `bukti/http.txt` |
| Migrasi–rollback 028 pada dua salinan berantai V6 Fase 1 `tests/v6_phase2_migration.php` | 36/0 | `bukti/migrasi-rollback.txt` |
| Browser 375/768/1280 + tanpa JavaScript + PDF kuitansi A4 satu halaman `tests/browser/uji-v6-fase2.mjs` (log server 0 galat PHP) | 76/0 | `bukti/browser.txt`, `tangkapan/*.png`, `tangkapan/kuitansi-a4.pdf` (diperiksa visual) |

Kontrol yang tercatat selama implementasi (bukan bukti akhir): pemeriksaan tampilan pertama menemukan halaman tunggakan melebar 5–14 px pada 375 px (nilai ringkasan rupiah besar) dan cap kuitansi menimpa teks info pada 375 px; keduanya diperbaiki (CSS `.ah-stat`, cap sebaris di layar sempit) lalu uji diulang. Dua kegagalan uji pertama adalah kesalahan uji (jatuh tempo pratinjau ≠ penerbitan → 409 yang benar; label "SISA" dikapitalkan CSS) dan diperbaiki pada uji, bukan pada kode.

Cara mengulang (berurutan):

```bash
bash docs/phase-v6-2/perintah-uji.sh
KPSB_DIR=/private/tmp/<privat> bash docs/phase-v6-2/perintah-regresi.sh
```

## Regresi

Ringkasan `bukti/regresi/ringkasan.txt`, keluaran per suite di `bukti/regresi/`.

| Rangkaian | Hasil | Catatan |
| --- | --- | --- |
| V6 Fase 1 integrasi / HTTP / probe auditor Codex | 120/0 · 104/0 · 37/0 | Pada DB 027 + 028; perilaku Fase 1 tetap (termasuk koreksi F-1 replay). |
| V6 Fase 1 browser | 59/0 | |
| V6 Fase 1 migrasi | 19/10 | **Oracle historis**: mengasumsikan 027 migrasi terakhir (preflight/rollback 027 kini diblokir dengan benar karena 028 terakhir; CHECK `bsa_%` 36 bukan 14). **Kontrol**: suite yang sama dari `git archive e895dbe` (+ `vendor/` ditautkan) → **29/0** (`v6-fase1-migrasi-kontrol-baseline-e895dbe.txt`). |
| Keamanan PSB HTTP (Apache + php-fpm, `.htaccess` aktif) / browser | 97/0 · 42/0 | DB baru dari sandbox `.env` + migrasi sampai 028. |
| Fondasi penugasan | 161/0 | |
| V2 Fase 4 / Fase 5 integrasi | 124/0 · 143/0 | |
| V5 Fase 5 / Fase 3 integrasi | 95/0 · 82/0 | |
| HTTP Tahap 5 / Tahap 7 | 106/0 · 122/0 | |
| HTTP Tahap 6 sesudah 028 → 027 → 026 → 025 → 024 dilepas (preflight + penjaga SQL, tabel kosong) | 30/0 · 132/0 | Rantai rollback kosong berjalan dengan penjaga 028 baru. |
| HTTP Tahap 2 / Tahap 3 | 185/6 · 166/1 | Sama persis dengan Fase 1 (oracle menu BB/BC/BD/BE; AG). |
| Browser kerangka Tahap 1 sembilan akun × 375/768/1280 pada DB V6 Fase 2 | 91/0 | |
| Seluruh 36 suite statis | 19 exit 0 · 15 exit 1 · 2 exit 77 | Fase 1: 19 · 14 · 2 dari 35 suite. Tambahan: `v6_phase2_static` 62/0 (exit 0) dan `v6_phase1_static` kini oracle historis (exit 1). Lihat tabel oracle. |

## Oracle historis yang gagal (tidak diubah, tidak dilonggarkan)

Seluruh kegagalan statis di bawah identik jumlahnya dengan bukti Fase 1, kecuali `v6_phase1_static` yang baru menjadi oracle historis karena Fase 2:

| Suite | Kegagalan | Sebab |
| --- | --- | --- |
| `v6_phase1_static` 72/3 pada bukti awal; **71/4 pada commit implementator dan audit** | "027 berurutan setelah 026 (migrasi terakhir)"; "AuditLogger hanya meloloskan …" (mengunci literal daftar kunci Fase 1); "Fase 2+ belum dimulai: tanpa tarif, pembayaran tagihan, kuitansi…" | Migrasi 028, kunci metadata audit Fase 2 (aditif, tetap tanpa nominal/alasan — diperiksa `v6_phase2_static` T-7 dan verifier), dan fitur Fase 2 itu sendiri. Audit menambahkan kontrol commit `b50eda5`: oracle migrasi lama juga ikut membaca pasangan 028 karena hanya mengecualikan 027; tidak ada perubahan migrasi ≤027. Lihat `AUDIT-CODEX.md`. |
| V6 Fase 1 migrasi 19/10 | Lihat regresi | 027 bukan lagi migrasi terakhir; kontrol baseline 29/0. |
| `keamanan_psb_static` 82/1, `penugasan_static` 281/2, `redesain_web_tahap2/3/4/6/7/8_static` 120/1 · 151/1 · 95/1 · 139/2 · 100/2 · 78/2, `v2_phase4_static` 287/1, `v5_phase1/2/3/5_static` 44/4 · 61/2 · 68/3 · 57/1 | Sama dengan Fase 1 | Oracle historis yang sudah tercatat di `docs/phase-v6-1/README.md` dan audit Codex Fase 1. |
| `v2_phase5_static` 315/3 | Versi Expo/React Native/expo-notifications | Lingkungan: salinan kerja `~/alhasanApps` milik pekerjaan lain (lihat baris Mobile). Fase 1 auditor: ekspor bersih `origin/main` → 310/0. |
| `v3_phase2/3_static` | exit 77 | Memerlukan DB V3 bernama tetap. |

**Tidak dijalankan ulang** (dicatat, bukan disembunyikan): V5 Fase 1–2 integrasi/HTTP/migrasi, V5 Fase 3 HTTP/migrasi, V5 Fase 5 HTTP/rilis, Tahap 8 HTTP/browser, GET-mutasi/cleanup HTTP, suite V3/V4 ber-DB tetap, browser V3/V4/V5, Android/iOS. Kode Auth, PSB, V2–V5, penugasan, master data, notifikasi, migrasi ≤ 027, dan halaman PSB tidak berubah dari baseline (`v6_phase2_static` T-8).

## Penafsiran yang perlu ditinjau auditor/Human Developer

- **Q-1 Model penyesuaian per santri.** Penyesuaian = baris Potongan/Tambahan beralasan untuk rentang periode (maks. 5 aktif per santri), disalin sebagai baris tagihan saat tagihan terbit dari tarif. Komponen tarif tidak diganti per santri. Penyesuaian tidak mengubah tagihan yang sudah terbit dan tidak berlaku untuk tagihan manual Fase 1. Total ≤ Rp0 sesudah potongan → santri dilewati ("Dibebaskan penuh oleh penyesuaian").
- **Q-2 Revisi = baris penyeimbang + versi baru.** PRD §5.3 "penyesuaian berikutnya tercatat sebagai revisi". Komponen snapshot tidak pernah diubah/dihapus; `bsa_tagihan.total/versi/jatuh_tempo` (027) diperbarui bersyarat versi sebagai cerminan, setiap versi tersimpan di `bsa_tagihan_riwayat`. Total tidak boleh di bawah terbayar + transfer menunggu (pengembalian kelebihan bayar di luar Fase 2).
- **Q-3 Pembatalan tagihan** hanya tanpa pembayaran sah/menunggu; baris tetap ada dan tetap memblokir tagihan kedua santri+periode itu (kunci unik 027). Untuk menerbitkan ulang nominal yang benar, gunakan revisi, bukan pembatalan.
- **Q-4 Tarif berlaku-mulai.** Tarif efektif periode P = tarif Berlaku unit dengan `berlaku_mulai` terbesar ≤ P. Alasan wajib bila unit sudah pernah bertarif (perubahan); tarif pertama unit boleh tanpa alasan. Belum ada tindakan "hentikan tarif unit".
- **Q-5 Unit** = `kelas.jenjang` kelas aktif (kanonik, tanpa membedakan huruf). Santri tanpa penempatan kelas aktif dilewati penerbitan massal; tagihannya tetap dapat diterbitkan manual (Fase 1) oleh petugas seluruh unit.
- **Q-6 Jatuh tempo penerbitan massal**: awal periode ≤ jatuh tempo ≤ awal periode + 3 bulan (pembatas salah ketik, dapat diubah). Tagihan manual Fase 1 mempertahankan aturan Fase 1.
- **Q-7 Verifikasi transfer.** Tunai selalu sah oleh pencatat. Transfer sah seketika hanya dengan pernyataan centang "sudah memeriksa dana masuk"; tanpa itu `Menunggu`. Verifikasi boleh oleh petugas BSA mana pun dalam cakupan, termasuk pencatat (PRD §2 membolehkan pencatat menyetujui). "Terverifikasi" tetap bukan bukti dana bank (PRD §5.3).
- **Q-8 Pembatalan pembayaran.** Status `Sah`/`Menunggu` → `Dibatalkan` (nominal tidak diubah) + satu baris `bsa_pembayaran_pembatalan` (jenis Pembatalan/Penolakan, aktor, waktu, alasan). Kolom 027 `bsa_pembayaran.pembatalan_dari_id` dan `alasan` tidak dipakai untuk pembalik (dibiarkan NULL; kolom `alasan` dipakai sebagai catatan internal pembayaran) — entri penyeimbang ada di tabel 028 agar `terbayar = Σ pembayaran sah` (invarians Fase 1) tetap berlaku. Koreksi nominal = batalkan lalu catat ulang.
- **Q-9 Kuitansi** hanya untuk pembayaran yang pernah sah; snapshot sekali saat sah. Nomor `BSA-KW-<tahun pencatatan>-<ID 6 digit>` sudah diberikan saat dicatat (termasuk transfer menunggu, yang belum berkuitansi). Pembayaran yang dibatalkan tetap dapat dibuka dengan cap DIBATALKAN.
- **Q-10 Proyeksi wali.** Wali melihat tagihan anaknya, komponen dan baris penyesuaian (nama/nominal, tanpa alasan), pembayaran sah/dibatalkan, kuitansi tanpa referensi transfer/catatan/alasan pembatalan, dan "Belum lunas". Wali tidak melihat transfer menunggu, riwayat versi, tarif, daftar penyesuaian, atau penerbitan (403).
- **Q-11 Santri pindah unit / nonaktif.** Tagihan menyimpan unit saat terbit; hak petugas mengikuti unit sekarang (aturan Fase 1 P-3). Santri nonaktif bukan sasaran penerbitan; tagihan/tunggakannya hanya dibaca admin; petugas belum dapat mencatat pembayaran/pembatalan untuk santri nonaktif (sama dengan P-5 Fase 1; jalur santri keluar ditinjau di Fase 3/5).
- **Q-12 Penerbitan petugas unit** tanpa saringan = seluruh unit dalam cakupannya. Riwayat penerbitan petugas unit: penerbitan unitnya + penerbitan tanpa saringan buatannya sendiri; baris santri di luar cakupan disaring. Hash pratinjau memuat cakupan: perubahan cakupan di antara pratinjau dan penerbitan → 409.
- **Q-13 Satu transaksi per penerbitan massal** (fixture: 45 tagihan dari 255 santri aktif). Ukuran pesantren nyata dan batas waktu PHP/hosting belum diuji.
- **Q-14 Notifikasi tagihan terbit/pembayaran** (PRD §3.6) belum dibuat: bukan persyaratan Fase 2; dicatat untuk fase berikutnya (paritas aplikasi/rilis).
- **Q-15 Perubahan berkas bersama** (lihat butir 6 di atas): visibilitas helper `BsaService`, verifier Fase 1, `AuditLogger`, `Status`, dan CSS `.ah-stat`. Logika hak/mutasi Fase 1 tidak berubah (regresi Fase 1 + probe auditor 37/0).
- **Q-16 Satu tarif per unit**, bukan per kelas/angkatan; komponen sama untuk seluruh santri unit kecuali penyesuaian.

## Batas yang belum diuji / terbuka

- Tidak ada uji cPanel/produksi atau MySQL (lokal hanya MariaDB 12.3.2). Kolom terbangkit STORED berindeks unik, CHECK, READ COMMITTED, dan binlog hosting harus lulus `bin/v6_phase2_preflight.php` sebelum migrasi.
- Konkurensi diuji pada pola dua/delapan proses dengan koneksi DB terpisah (J-8) dan dua permintaan HTTP serentak (K-F, server PHP satu pekerja = bukti kontrak HTTP). Bukan bukti seluruh interleaving.
- Kegagalan transaksi dipaksa lewat trigger `audit_logs` pada DB uji (J-9); kegagalan jaringan/proses di tengah commit tidak disimulasikan.
- Aplikasi mobile, Push/notifikasi, tabungan lengkap, pembayaran dari tabungan, rekonsiliasi, laporan keuangan — **belum** dikerjakan (Fase 3–5).
- **Risiko urutan rilis V5/V6** (sama dengan Fase 1): menggabung branch ini ke cabang yang dideploy lalu `migrate up` memasang 027 + 028 dan mengaktifkan menu Keuangan — rilis parsial yang dilarang PRD. Belum ada sakelar rilis.

## Langkah rilis dan pemulihan (untuk keputusan Human Developer kelak — bukan izin deploy)

Tagihan dan tabungan hanya dirilis bersama sesudah Fase 5. Bila kelak diputuskan (setelah langkah Fase 1):

1. Backup terverifikasi DB dan berkas.
2. `php bin/v6_phase2_preflight.php /jalur/aman/manifest-028.json` — wajib tanpa `[gagal]`.
3. `php bin/migrate.php up` (menerapkan 028).
4. `php bin/v6_phase2_verify.php /jalur/aman/manifest-028.json` — wajib tanpa `[gagal]`.

**Pemulihan.** Rollback hanya bila `php bin/v6_phase2_rollback_preflight.php` lulus (sembilan tabel 028 dan `bsa_pembayaran` kosong, 028 migrasi terakhir); lalu `php bin/migrate.php rollback` dan `php bin/v6_phase2_verify.php --rollback /jalur/aman/manifest-028.json`. Bila sudah ada tarif/tagihan pasca-028/pembayaran, **jangan rollback** (penjaga SQL juga menolaknya): pulihkan backup terverifikasi atau buat migrasi korektif. Kode Fase 2 tetap berjalan sebelum 028 dan sesudah rollback: fitur Fase 2 menjawab 503, navigasi bagian keuangan tersembunyi, rincian tagihan kembali ke kontrak Fase 1, dan setoran/penarikan/tagihan manual Fase 1 tetap berjalan (diuji N-7).

## Handoff ke auditor

> *"Audit hanya Fase 2 PRD V6 pada branch `claude/prd-v6-fase-2` sesudah Claude Code berhenti. Baca AGENTS.md, PRD-V6.md, dokumen audit Fase 1, `docs/phase-v6-2/README.md`, baseline audit `e895dbe`, dan semua commit baru. Uji secara independen cakupan tarif, penyesuaian per santri, snapshot, penerbitan ulang periode, pembayaran tunai/transfer dan sebagian, pembayaran berlebih, kuitansi, perubahan tarif setelah terbit, koreksi, serta akses wali dan pencabutan penugasan pada web/API. Jalankan regresi Fase 1, PSB, dan migrasi/rollback pada salinan database; pisahkan kegagalan historis dari regresi baru. Koreksi hanya temuan terarah beserta pengujiannya. Tulis `docs/phase-v6-2/AUDIT-CODEX.md` dengan keputusan lulus/belum lulus, commit/push branch, lalu berhenti. Jangan mulai Fase 3, merge, atau deploy."*

Implementator berhenti di sini: tidak merge, tidak deploy, tidak migrasi produksi, tidak merilis aplikasi, tidak memulai Fase 3.
