# PRD V6 Fase 3 — Tabungan Lengkap dan Pembayaran Silang

**Status (25 Oktober 2026): LULUS audit independen Codex setelah empat koreksi terarah.** Ringkasan menunggu >50 baris, respons replay pengembalian, kelengkapan manifest, dan tanggal rekonsiliasi pasangan internal dikoreksi; hasil dan batas di [AUDIT-CODEX.md](AUDIT-CODEX.md), bukti auditor terpisah di `audit-codex/`. Bukti implementator di bawah tetap bukti sebelum audit. Belum merge, belum deploy, belum migrasi produksi, belum rilis aplikasi. Fase 4 belum dimulai. Fase 3 **bukan** izin rilis parsial: tagihan dan tabungan dirilis bersama sesudah Fase 5 dan keputusan Human Developer.

| Butir | Nilai |
| --- | --- |
| Workstream | PRD V6 Fase 1–5 keuangan santri/BSA — implementator Claude Code, auditor Codex (`AGENTS.md`, keputusan 25 Oktober 2026) |
| Branch | `claude/prd-v6-fase-3` |
| Baseline web | Commit audit Fase 2 `ca62c3108aafb71b0a93211b6b5d3a88d086a994` (`fix(v6): audit Fase 2…`, = `origin/claude/prd-v6-fase-2`). `origin/main` `271d8fc6ec6bfdd3709b1b33adff02c61f829475` (merge PR #88) memuatnya; `git diff ca62c31 origin/main` kosong. Branch dibuat dari `ca62c31`, tidak bekerja di `main`. |
| Commit implementasi | `e61c7bd` (lihat bagian *Hash*) |
| Mobile | `~/alhasanApps` tidak diubah dan tidak dibutuhkan Fase 3. `origin/main` `f8ebba0`; salinan kerja berada di branch lain (`claude/aset-appstore-header`) dengan `package.json`/`package-lock.json` belum di-commit milik pekerjaan lain — tidak disentuh. |
| Lingkungan uji | PHP 8.4.14, MariaDB 12.3.2 lokal (`innodb_snapshot_isolation=ON`), Apache 2.4 + php-fpm lokal (regresi PSB), Chromium Playwright. Seluruh DB `test_*_test` dengan fixture sintetis; tidak ada data produksi yang dibuka/diubah. **MySQL tidak tersedia lokal — belum diuji.** |

Tidak ada keputusan Human Developer baru selama Fase 3. Penafsiran yang perlu ditinjau auditor dicatat di bawah (R-1…R-14).

## Yang dikerjakan (persyaratan Fase 3)

1. **Migrasi aditif `029_v6_fase3_tabungan.sql`** — satu tabel baru `bsa_setoran_transfer` (antrean setoran transfer yang belum diperiksa: Menunggu → Sah/Ditolak), hanya `CREATE TABLE IF NOT EXISTS`; **tidak ada ALTER/DROP/DML** terhadap tabel mana pun, termasuk tabel 027/028. Rupiah `BIGINT`, 6 kunci asing, 5 `CHECK` (Sah ⇔ satu baris ledger unik; Menunggu ⇔ belum diputus; Ditolak ⇔ alasan ≥ 5 karakter). Fitur Fase 3 lain memakai skema yang sudah disiapkan 027: jenis ledger `Belanja`/`Pembalik`/`PembayaranTagihan`, `pembalik_dari_id` unik + `alasan`, metode `Internal`, dan `bsa_pembayaran.sumber = 'Tabungan'` + `tabungan_transaksi_id` unik. Rollback berpasangan melepas satu tabel itu saja, didahului **penjaga data SQL** tiga sumber (antrean transfer, baris ledger berjenis Fase 3, pembayaran dari tabungan).
2. **Ledger tetap HANYA-TAMBAH** (R-1). Setiap baris `bsa_tabungan_transaksi` sudah sah saat disisipkan dan tidak pernah di-UPDATE/DELETE; saldo efektif = akun cerminan; urutan ID = urutan efektif, sehingga invarians Fase 1 (saldo akun = saldo sesudah transaksi sah terakhir = jumlah berjalan) tetap berlaku tanpa kolom baru. Setoran transfer yang menunggu berada di tabel 029 dan **tidak dihitung saldo**; verifikasi menyisipkan baris ledger `Setoran/Transfer/Sah` saat itu juga.
3. **`App\Bsa\TabunganService` + `TabunganRepository`** (satu pintu web dan API), hak memakai penjaga `BsaService` Fase 1 (dihitung ulang dari DB setiap panggilan). Setiap mutasi: satu transaksi READ COMMITTED, urutan kunci **penugasan (baca) → klaim idempotensi (tulis) → santri (baca) + cakupan → [tagihan (tulis) → pembayaran (tulis)] → setoran transfer (tulis) → akun tabungan (tulis)**; cakupan/status santri diperiksa ulang **sebelum replay** (pelajaran F-1 audit Fase 1); idempotensi per akun; audit wajib (gagal audit membatalkan seluruh transaksi).
   - **Setoran**: tunai, atau transfer yang dinyatakan "sudah memeriksa dana masuk" (`terverifikasi`), sah seketika lewat `BsaService::setoran` (Fase 1). Transfer tanpa pernyataan itu → antrean **Menunggu** (HTTP `202`), saldo tetap. **Verifikasi** (petugas mana pun dalam cakupan, termasuk pencatat) → ledger sah (pencatat = penerima semula, penyetuju = pemeriksa); **tolak** beralasan → Ditolak, saldo tetap; keputusan kedua → 409.
   - **Penarikan/belanja**: `jenis` Penarikan atau Belanja (baru, `BsaService::belanja`), tujuan wajib, melebihi saldo → 422, pencatat = penyetuju (PRD §5.1).
   - **Koreksi pembalik beralasan**: baris `Pembalik` (arah berlawanan, nominal dan metode sama, `pembalik_dari_id` unik, alasan) atas setoran/penarikan/belanja; asal tidak diubah. Ditolak bila sudah dikoreksi (409), bila asal sendiri pembalik (422), bila membalik setoran membuat saldo negatif (422 — dana sudah dipakai), dan bila asal debit pembayaran tagihan (422, dikoreksi lewat pembatalan pembayaran agar debit dan pembayaran selalu berpasangan, R-5).
   - **Kuitansi tabungan**: nomor `BSA-TB-<tahun>-<ID 6 digit>` turunan ID ledger (R-3); data dari baris ledger yang tidak pernah berubah (saldo sebelum = saldo sesudah ∓ nominal).
   - **Mutasi lintas santri** (saring jenis/metode/tanggal) dan daftar setoran transfer per status untuk petugas/admin.
   - **Rekonsiliasi harian** (R-8) — lihat bagian kriteria 5.
4. **Pembayaran tagihan dari tabungan** — `TagihanService::bayarDariTabungan` (tindakan eksplisit, wajib `setuju`): lewat `mutasiTagihan` (tagihan dikunci tulis) → akun tabungan dikunci tulis → periksa tagihan Terbit, nominal ≤ sisa − transfer menunggu, nominal ≤ saldo → **debit ledger `PembayaranTagihan`/`Keluar`/`Internal`** → **pembayaran `Tabungan` sah yang menunjuk debit itu** (`tabungan_transaksi_id`, kunci unik + CHECK 027) → terbayar naik bersyarat → snapshot kuitansi → audit. Satu transaksi basis data: gagal di titik mana pun → keduanya rollback. Satu referensi silang (`bsa_pembayaran.tabungan_transaksi_id`) dibaca kedua riwayat. `batalkanPembayaran` atas pembayaran `Tabungan` kini juga menyisipkan **pengembalian** `Pembalik`/`Masuk`/`Internal` beralasan atas debit itu di transaksi yang sama (R-5).
5. **Web** (kerangka bersama, POST + CSRF, `no-store`, kunci idempotensi tersembunyi): `portal/bsa_transaksi.php` (pilihan Penarikan/Belanja, centang "sudah memeriksa dana masuk"), `bsa_santri.php` (kartu setoran menunggu, tabel setoran transfer dengan verifikasi/tolak, nomor kuitansi tabungan, referensi silang ke kuitansi tagihan, cap "Sudah dikoreksi", dialog koreksi), baru: `bsa_tabungan_aksi.php` (verifikasi/tolak/koreksi; GET → 405), `bsa_tabungan_kuitansi.php` (siap cetak/PDF A4), `bsa_bayar_tabungan.php` (persetujuan wajib), `bsa_tabungan.php` (mutasi sah + setoran transfer), `bsa_rekonsiliasi.php`; `bsa_tagihan.php` (tombol "Bayar dari tabungan", cara "Dari tabungan" + nomor debit), `bsa_kuitansi.php` (cara "Dari tabungan santri" + nomor debit), `bsa_pembayaran.php` (saringan Tabungan). Navigasi bagian keuangan petugas/admin bertambah **Tabungan** dan **Rekonsiliasi** (orang tua tidak); menu Keuangan (keputusan BE) tidak berubah.
6. **API** `/api/v1/v6/bsa/*` aditif — kontrak di `docs/api-v1.md` (§ V6 Fase 3): setoran `201`/`202`, `setoran-transfer` (daftar, verifikasi, tolak), penarikan `jenis`, `tabungan` (mutasi, rincian, koreksi), `tagihan/{id}/bayar-tabungan`, `rekonsiliasi`; `GET santri/{id}` menambah `setoran_transfer[]`, `saldo_menunggu`, referensi silang per baris.
7. **Operasi**: `bin/v6_phase3_preflight.php [manifest]` (CHECK, InnoDB, binlog, 027 + 028 tercatat, jumlah baris berjenis Fase 3 di tabel 027, manifest seluruh tabel lama + sidik jari ID dan isi uang 027/028 **termasuk pembayaran**), `bin/v6_phase3_verify.php [--rollback] [manifest]` (menjalankan seluruh verifier Fase 1–2, lalu tabel 029, 6 FK, 0 yatim, dan invarians tabungan lengkap — lihat kriteria), `bin/v6_phase3_rollback_preflight.php`. Fixture: DB Fase 2 (`tests/v6_phase3_db.sh`); data Fase 3 dibuat uji lewat layanan.
8. **Perubahan kecil pada berkas Fase 1–2/bersama** (seluruhnya diuji regresi, R-12): `BsaRepository` — klaim idempotensi mengembalikan `status_code`; `catatTransaksi` menerima `pembalik_dari_id`/`alasan` dan penyetuju terpisah; riwayat/rincian ledger membawa referensi silang (hanya join tabel 027). `BsaService` — `OP_BELANJA`/`belanja()`, nomor kuitansi tabungan, `ringkasTransaksi` publik + kunci aditif (wali tanpa `alasan`). `TagihanService`/`TagihanRepository` — `bayarDariTabungan`, pengembalian pada `batalkanPembayaran`, kolom `tabungan_transaksi_id`, `syaratHak` publik, saringan sumber Tabungan, kunci `tabungan` pada pembayaran. `AuditLogger` — dua kunci ID (`setoran_transfer_id`, `pembalik_dari_id`). `Navigation` — tiga halaman aktif baru. `bootstrap` — `tabungan_service()`. `assets/ui/alhasan.css` — dua penyesuaian HP (R-13).

## Kriteria penerimaan → bukti

| Kriteria Fase 3 | Bukti |
| --- | --- |
| Setoran Rp100.000 dan belanja Rp25.000 → saldo Rp75.000; dua belanja Rp60.000 bersamaan → tepat satu sukses | `bukti/integrasi.txt` L-2 (saldo Rp75.000, jenis Belanja sah, pencatat = penyetuju, kuitansi), L-6 (proses + koneksi DB terpisah: dua belanja Rp60.000 atas Rp75.000 → 1 sukses + 1×422, saldo Rp15.000; sepuluh belanja Rp10.000 atas Rp40.000 → tepat 4 sukses berurutan); `bukti/http.txt` M-B, M-F (`[201, 422]`); `bukti/browser.txt` D-B (375/768/1280) |
| Bayar tagihan Rp50.000 dari saldo Rp75.000 → saldo Rp25.000, sisa tepat Rp50.000; satu referensi silang terbaca di kedua riwayat | L-4 (satu debit `PembayaranTagihan`/`Internal`, satu pembayaran `Tabungan` yang menunjuk debit itu, kuitansi snapshot, referensi silang di `santri()` dan `rincianTagihan()` — juga bagi wali anak itu); M-C (web), M-E (API `tabungan/{id}` ↔ `tagihan/{id}`); D-B (tampilan dan kuitansi kedua arah) |
| Pencatatan pembayaran gagal setelah debit disiapkan → saldo dan sisa semula, nol transaksi efektif yatim | L-7: trigger uji `BEFORE INSERT ON bsa_pembayaran` (sumber Tabungan) gagal **sesudah** debit ledger + pembaruan saldo → 503, saldo/sisa/baris/klaim/audit identik, nol debit yatim; juga kegagalan kuitansi, audit, dan pengembalian saat pembatalan (pembayaran tetap Sah); verifier L-12 |
| Klik ganda/ulang request kunci sama → paling banyak satu debit dan satu pembayaran | L-5 (replay hasil sama; kunci sama nominal beda → 409), L-6 (delapan proses kunci+isi sama → satu debit, satu pembayaran, tujuh replay); M-C (klik ganda web), M-E (API replay 200/409); D-B |
| Laporan penerimaan kas tidak bertambah karena pembayaran internal dari tabungan | L-9 (setoran tunai +Rp100.000 → kas +Rp100.000; bayar Rp50.000 dari tabungan → **kas tunai/transfer identik**, internal debit = pembayaran dari tabungan, selisih Rp0; bayar tunai Rp30.000 → kas +Rp30.000; transfer menunggu tidak dihitung, sesudah verifikasi +Rp70.000; saldo akhir = Σ ledger = Σ akun; cakupan petugas Tsanawi); M-E; D-E (rekonsiliasi admin) |
| Persyaratan 1–2: verifikasi, kuitansi, koreksi, saldo efektif, riwayat urut | L-3 (menunggu/verifikasi/tolak, replay), L-8 (koreksi), L-10; M-B/M-D; D-C/D-D |
| Persyaratan 5: kiriman ulang, rollback di tengah operasi, dua penarikan/pembayaran silang bersamaan, koreksi transaksi yang sudah dipakai membayar tagihan | L-5, L-6 (belanja×2, bayar tabungan ke dua tagihan, bayar tabungan vs penarikan, bayar tabungan vs tunai pada tagihan sama, dua verifikasi, dua koreksi, dua pembatalan pembayaran tabungan), L-7, L-8 (setoran yang sudah dipakai → 422; debit → lewat pembatalan; pengembalian satu kali) |
| Otorisasi wali dan lintas peran | L-1, L-10 (seluruh ID transaksi ditebak wali → hanya anaknya; wali tanpa referensi/catatan/alasan/setoran menunggu; relasi dicabut → 404), L-11 (cakupan dipersempit, santri nonaktif, penugasan dicabut pada sesi lama → 404/403 tanpa baris baru); M-A/M-E/M-G; D-E |

## Hasil pengujian Fase 3 oleh implementator (sebelum audit)

| Rangkaian | Hasil | Berkas |
| --- | --- | --- |
| Statis `tests/v6_phase3_static.php` (migrasi aditif, rollback berpenjaga, tanpa float, SQL berparameter, ledger tanpa UPDATE/DELETE, urutan kunci + cakupan sebelum replay, atomisitas bayar dari tabungan, CSRF/escape/kunci idempotensi, rute, audit, batas Fase 3 via `git diff ca62c31`, lint) | 57/0 | `bukti/statis.txt` |
| Integrasi layanan + konkurensi proses terpisah + kegagalan transaksi + verifier + preservasi `tests/v6_phase3_integration.php` | 137/0 | `bukti/integrasi.txt` |
| HTTP web + API lewat server PHP lokal `tests/v6_phase3_http.php` (log server 0 galat PHP, 0 referensi transfer) | 66/0 | `bukti/http.txt` |
| Migrasi–rollback 029 pada dua salinan berantai V6 Fase 2 `tests/v6_phase3_migration.php` | 35/0 | `bukti/migrasi-rollback.txt` |
| Browser 375/768/1280 + tanpa JavaScript + PDF kuitansi tabungan A4 satu halaman `tests/browser/uji-v6-fase3.mjs` (log server 0 galat PHP) | 89/0 | `bukti/browser.txt`, `tangkapan/*.png`, `tangkapan/kuitansi-tabungan-a4.pdf` |

Kontrol yang tercatat selama implementasi (bukan bukti akhir): kegagalan pertama uji adalah kesalahan uji (urutan saldo skenario koreksi, cast string/int, jumlah audit penugasan ikut terhitung, tautan kuitansi pada flash ikut terhitung, label "SISA" dikapitalkan CSS, centang tertutup bilah bawah tanpa JavaScript) dan diperbaiki pada uji, bukan pada kode. Satu uji konkurensi diperketat (sepuluh belanja atas saldo terbatas) agar penolakan benar-benar terjadi. Pemeriksaan visual 375px menemukan kontrol segmen tujuh butir membungkus menjadi elips dan nominal enam digit terpotong di kartu ringkasan; keduanya diperbaiki di CSS bersama (R-13) lalu seluruh uji diulang.

Cara mengulang (berurutan):

```bash
bash docs/phase-v6-3/perintah-uji.sh
KPSB_DIR=/private/tmp/<privat> bash docs/phase-v6-3/perintah-regresi.sh
```

## Regresi

Ringkasan `bukti/regresi/ringkasan.txt`, keluaran per suite di `bukti/regresi/` (dijalankan berurutan oleh `perintah-regresi.sh` pada kode final, termasuk CSS R-13).

| Rangkaian | Hasil | Catatan |
| --- | --- | --- |
| V6 Fase 1 integrasi / probe auditor Codex / browser | 120/0 · 37/0 · 59/0 | Pada DB 027–029; perilaku layanan Fase 1 tetap. |
| V6 Fase 1 HTTP | 96/8 | **Perubahan perilaku disengaja (R-2)**: suite mengharapkan `POST santri/{id}/setoran` transfer tanpa `terverifikasi` sah `201`; kini `202` menunggu, sehingga tujuh pemeriksaan berikutnya pada santri yang sama (saldo Rp100.000/Rp70.000, replay, 409, jumlah audit = jumlah ledger) gagal berantai. **Kontrol**: suite yang sama pada kode Fase 3 dengan 029 dilepas (fallback Fase 1–2) → **104/0** (`v6-fase1-http-kontrol-tanpa-029.txt`). Suite tidak diubah. |
| V6 Fase 2 integrasi / HTTP / probe auditor F-1…F-3 / probe era 027 / browser | 179/0 · 110/0 · 30/0 · 10/0 · 76/0 | Pembatalan pembayaran tunai/transfer dan seluruh alur tagihan tidak berubah. |
| V6 Fase 1 migrasi / Fase 2 migrasi | 19/10 · 22/14 | **Oracle historis**: keduanya mengasumsikan 027/028 migrasi terakhir; preflight rollback 027/028 kini memblokir dengan benar karena 029 terakhir, lalu langkah berantai gagal. Fase 1 identik dengan audit Fase 2. **Kontrol**: suite migrasi Fase 2 dari `git archive ca62c31` (+ `vendor/` ditautkan) → **36/0** (`v6-fase2-migrasi-kontrol-baseline-ca62c31.txt`). |
| Keamanan PSB HTTP (Apache + php-fpm, `.htaccess` aktif) / browser | 97/0 · 42/0 | DB baru dari sandbox `.env` + migrasi sampai 029. |
| Fondasi penugasan | 161/0 | |
| V2 Fase 4 / Fase 5 integrasi | 124/0 · 143/0 | |
| V5 Fase 5 / Fase 3 integrasi | 95/0 · 82/0 | |
| HTTP Tahap 5 / Tahap 7 | 106/0 · 122/0 | |
| HTTP Tahap 6 sesudah 029 → 028 → 027 → 026 → 025 → 024 dilepas (preflight + penjaga SQL, tabel kosong) | 33/0 · 132/0 | Rantai rollback kosong berjalan dengan penjaga 029 baru. |
| HTTP Tahap 2 / Tahap 3 | 185/6 · 166/1 | Kegagalan identik baris per baris dengan audit Fase 2 (oracle menu BB/BC/BD/BE; AG). |
| Browser kerangka Tahap 1 sembilan akun × 375/768/1280 pada DB V6 Fase 3 | 91/0 | Termasuk sesudah perubahan CSS `.ah-seg`/`.ah-stat__value`. |
| Seluruh 37 suite statis | 19 exit 0 · 16 exit 1 · 2 exit 77 | Fase 2 audit: 19 · 15 · 2 dari 36. Tambahan: `v6_phase3_static` 57/0 (exit 0) dan `v6_phase2_static` kini oracle historis (exit 1). Empat belas suite exit 1 lain **identik pesan kegagalannya** dengan `docs/phase-v6-2/audit-codex/regresi/` (diperiksa per suite). |

### Oracle historis yang gagal (tidak diubah, tidak dilonggarkan)

| Suite | Kegagalan baru dibanding audit Fase 2 | Sebab |
| --- | --- | --- |
| `v6_phase1_static` 70/5 (audit Fase 2: 71/4) | "Riwayat wali tanpa referensi transfer dan catatan internal" | Pencocokan literal `unset($baris['referensi'], $baris['catatan'])`; kini `unset(…referensi, …catatan, …alasan)` — wali tetap tanpa referensi/catatan dan juga tanpa alasan koreksi (diuji L-10, M-E). Empat kegagalan lain sama dengan audit Fase 2. |
| `v6_phase2_static` 58/4 | Rute literal `(revisi\|batal\|pembayaran)`; "Sepuluh panggilan audit Fase 2"; "Preflight Fase 1, halaman setoran/penarikan, dan BsaException tidak diubah"; "Berkas berubah hanya dalam daftar Fase 2" | Rute bertambah `bayar-tabungan`, panggilan audit `bsa.pembayaran.tabungan` bertambah di `TagihanService`, `bsa_transaksi.php` dilengkapi Fase 3, dan berkas Fase 3 baru — seluruhnya diwajibkan Fase 3. Daftar kunci audit tetap diperiksa `v6_phase3_static` U-7. |
| V6 Fase 1 HTTP 96/8, V6 Fase 1/2 migrasi | Lihat tabel regresi | R-2; 029 migrasi terakhir. Kontrol 104/0 dan 36/0. |
| `keamanan_psb_static`, `penugasan_static`, `redesain_web_tahap2/3/4/6/7/8_static`, `v2_phase4_static`, `v5_phase1/2/3/5_static` | Tidak ada | Identik dengan audit Fase 2 (oracle historis yang sudah tercatat). |
| `v2_phase5_static` 320/3 | Tidak ada (3 kegagalan sama) | Versi Expo/React Native pada salinan kerja `~/alhasanApps` milik pekerjaan lain (lihat baris Mobile); jumlah lulus berbeda dari audit (315) karena isi checkout itu, bukan kode web. |
| `v3_phase2/3_static` | exit 77 | Memerlukan DB V3 bernama tetap. |

**Tidak dijalankan ulang** (dicatat, bukan disembunyikan): V5 Fase 1–2 integrasi/HTTP/migrasi, V5 Fase 3 HTTP/migrasi, V5 Fase 5 HTTP/rilis, Tahap 8 HTTP/browser, GET-mutasi/cleanup HTTP, suite V3/V4 ber-DB tetap, browser V3/V4/V5, Android/iOS. Kode Auth, PSB, V2–V5, penugasan, master data, notifikasi, migrasi ≤ 028, preflight/verifier Fase 1–2, dan halaman PSB tidak berubah dari baseline (`v6_phase3_static` U-8).

## Penafsiran yang perlu ditinjau auditor/Human Developer

- **R-1 Antrean transfer terpisah, ledger hanya-tambah.** 027 menyiapkan status `Menunggu`/`Ditolak` pada ledger, tetapi mengubah status baris ledger saat verifikasi akan memecah invarians Fase 1 (urutan ID = urutan efektif; saldo sesudah tersimpan per baris). Fase 3 memakai tabel 029 untuk antrean; status ledger `Menunggu`/`Ditolak` tidak dipakai (verifier Fase 3 menegaskan ledger hanya berisi baris sah).
- **R-2 Verifikasi setoran transfer** mengikuti Q-7 Fase 2: centang = pernyataan petugas sudah memeriksa dana masuk; tanpa itu menunggu. "Terverifikasi" tetap bukan bukti dana bank (PRD §5.3). Verifikasi boleh oleh petugas mana pun dalam cakupan, termasuk pencatat (PRD §2). **Perubahan perilaku API Fase 1–2**: `POST santri/{id}/setoran` transfer tanpa `terverifikasi` kini `202` menunggu, bukan `201` sah — lihat oracle historis Fase 1 HTTP.
- **R-3 Nomor kuitansi tabungan** diturunkan dari ID ledger dan tahun pencatatan (`BSA-TB-TTTT-NNNNNN`), tidak disimpan di kolom baru, karena baris ledger sah tidak pernah berubah; unik menurut ID. Berlaku juga untuk baris era Fase 1.
- **R-4 Belanja** = pengeluaran dari tabungan dengan aturan sama dengan penarikan (tujuan wajib, cara tunai/transfer, pencatat = penyetuju), hanya jenis ledger berbeda. Operasi idempotensi terpisah (`tabungan.belanja`).
- **R-5 Koreksi transaksi terkait tagihan.** Debit `PembayaranTagihan` tidak dapat dibalik sendirian; koreksi dilakukan dengan **membatalkan pembayaran** (alasan wajib) yang sekaligus menyisipkan pengembalian `Pembalik`/`Internal` — debit dan pembayaran selalu berpasangan (verifier). Setoran yang dananya sudah dipakai (saldo kini < nominal) tidak dapat dibalik; petugas harus mengoreksi transaksi pemakainya lebih dahulu (pesan menjelaskan). Pembalik tidak dapat dibalik lagi; bila keliru, catat transaksi baru.
- **R-6 Metode pembalik** = metode transaksi asal (pembalik setoran tunai = kas keluar tunai di rekonsiliasi); pengembalian pembayaran tabungan = `Internal`.
- **R-7 Wali**: melihat riwayat tabungan anak termasuk debit tagihan, pembalik, nomor kuitansi, dan referensi silang ke kuitansi tagihan; tidak melihat referensi transfer, catatan internal, alasan koreksi, setoran transfer menunggu/ditolak (konsisten Q-10 Fase 2), mutasi lintas santri, atau rekonsiliasi (403).
- **R-8 Rekonsiliasi harian** — kas = uang nyata yang sudah diverifikasi: setoran tabungan dan pembayaran tagihan tunai/transfer (masuk), penarikan/belanja (keluar), koreksi (pembalik tunai/transfer, pembatalan pembayaran tunai/transfer). "Penerimaan kas baru" = setoran + pembayaran tagihan tunai/transfer. Perpindahan internal (debit tabungan ↔ pembayaran dari tabungan, pengembalian ↔ pembatalan) dilaporkan terpisah dengan selisih yang wajib Rp0. Tanggal: ledger menurut waktu sah (= waktu sisip), kas pembayaran menurut `diverifikasi_pada`, kas pembatalan menurut waktu pembatalan. **Koreksi audit F-4:** kedua sisi pembayaran internal memakai waktu debit ledger, kedua sisi pengembalian memakai waktu pembalik, termasuk bila statement satu transaksi melintasi tengah malam; timestamp asli tidak diubah. Petugas: santri aktif dalam cakupan **kini** (seperti daftar Fase 2); admin: seluruh santri. Dibaca dalam satu snapshot `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY`. Rincian ≤ 500 baris. Laporan per periode/per santri dan ekspor lengkap tetap Fase 5.
- **R-9 Pembayaran dari tabungan** tidak pernah `Menunggu`; tanggal transaksi = hari pencatatan; sebagian boleh; tidak melebihi sisa − transfer menunggu (Q-2 Fase 2). Tagihan terbit tetap tidak memotong tabungan otomatis.
- **R-10 Santri nonaktif/keluar dengan saldo.** Seperti P-5 Fase 1: petugas belum dapat menarik/membayarkan saldo santri nonaktif; admin tetap membaca. Jalur pengembalian saldo santri keluar **belum ada** dan memerlukan keputusan Human Developer (Fase 5 atau keputusan khusus) — tidak dibuat sendiri karena memperluas hak.
- **R-11 Notifikasi** transaksi selesai (PRD §3.6) belum dibuat — sama dengan Q-14 Fase 2; paritas aplikasi/notifikasi Fase 4–5.
- **R-12 Perubahan berkas Fase 1–2** (butir 8 di atas). Logika hak/mutasi Fase 1–2 tidak berubah selain: setoran/penarikan web/API kini melewati `TabunganService` (aturan verifikasi transfer, R-2) dan pembatalan pembayaran `Tabungan` mengembalikan saldo. `BsaService::setoran()` tetap primitif "setoran sah" yang dipanggil `TabunganService` sesudah aturan verifikasi diputuskan; web/API tidak memanggilnya langsung (statis U-5/U-6).
- **R-13 CSS bersama** (`assets/ui/alhasan.css`): radius `.ah-seg` = setengah tinggi sentuh (satu baris tetap pil, saat membungkus jadi persegi membulat) dan `.ah-stat__value` 1,3rem di bawah 576px. Memengaruhi halaman lain secara visual kecil; regresi browser kerangka sembilan akun dijalankan.
- **R-14 Kunci idempotensi ledger hasil verifikasi** = `transfer#<id antrean>`; `#` tidak termasuk karakter kunci klien sehingga tidak dapat bertabrakan.

## Batas yang belum diuji / terbuka

- Tidak ada uji cPanel/produksi atau **MySQL** (lokal hanya MariaDB 12.3.2). CHECK yang ditegakkan, READ COMMITTED, konsisten snapshot baca, dan binlog hosting harus lulus `bin/v6_phase3_preflight.php` sebelum migrasi.
- Konkurensi diuji pada pola dua/delapan/sepuluh proses dengan koneksi DB terpisah (L-6) dan dua permintaan HTTP serentak (M-F; server PHP satu pekerja = bukti kontrak HTTP, bukan konkurensi DB). Bukan bukti seluruh interleaving.
- Kegagalan "di antara dua pencatatan" dipaksa lewat trigger uji pada DB uji (L-7); proses mati/jaringan putus tepat saat commit tidak disimulasikan.
- Rekonsiliasi memakai jam server basis data/PHP lokal; zona waktu hosting belum diperiksa. Fixture uji hanya satu hari (kemarin kosong).
- Aplikasi mobile, Push/notifikasi, laporan per periode/per santri, ekspor, uji perangkat — **belum** (Fase 4–5).
- **Risiko urutan rilis V5/V6** (sama dengan Fase 1–2): menggabung branch ini ke cabang yang dideploy lalu `migrate up` memasang 027–029 dan mengaktifkan menu Keuangan — rilis parsial yang dilarang PRD. Belum ada sakelar rilis.

## Langkah rilis dan pemulihan (untuk keputusan Human Developer kelak — bukan izin deploy)

Tagihan dan tabungan hanya dirilis bersama sesudah Fase 5. Bila kelak diputuskan (setelah langkah Fase 1–2):

1. Backup terverifikasi DB dan berkas.
2. `php bin/v6_phase3_preflight.php /jalur/aman/manifest-029.json` — wajib tanpa `[gagal]`.
3. `php bin/migrate.php up` (menerapkan 029).
4. `php bin/v6_phase3_verify.php /jalur/aman/manifest-029.json` — wajib tanpa `[gagal]`.

**Pemulihan.** Rollback hanya bila `php bin/v6_phase3_rollback_preflight.php` lulus (tanpa antrean transfer, tanpa baris ledger Belanja/Pembalik/PembayaranTagihan/Internal, tanpa pembayaran dari tabungan, 029 migrasi terakhir); lalu `php bin/migrate.php rollback` dan `php bin/v6_phase3_verify.php --rollback /jalur/aman/manifest-029.json`. Bila sudah ada data Fase 3, **jangan rollback** (penjaga SQL juga menolaknya): pulihkan backup terverifikasi atau buat migrasi korektif. Kode Fase 3 tetap berjalan sebelum 029 dan sesudah rollback: fitur Fase 3 menjawab 503, navigasi Tabungan/Rekonsiliasi tersembunyi, setoran transfer kembali sah seketika seperti Fase 1–2, dan fitur Fase 1–2 tetap berjalan (diuji O-7).

## Hash

| Butir | Nilai |
| --- | --- |
| Baseline (commit audit Fase 2) | `ca62c3108aafb71b0a93211b6b5d3a88d086a994` |
| `origin/main` saat mulai | `271d8fc6ec6bfdd3709b1b33adff02c61f829475` (memuat baseline; diff kosong) |
| Commit implementasi Fase 3 (kode, migrasi, uji, bukti) | `e61c7bd66049c45e72b8224ecd5f13d11af86792` |
| Commit dokumen hash ini | commit berikutnya di branch yang sama (`docs(v6): hash …`) |
| SHA-256 `database/migrations/029_v6_fase3_tabungan.sql` | `1a77f44ff1da9c63147faad8aad2b4b06ee6fcdfe4ff75267fbf40eef9c82010` |
| SHA-256 `database/rollbacks/029_v6_fase3_tabungan.sql` | `234c68b42cb4a601455c0b98f36371f7b11c6037d1dd0463306ae8e6b30f485b` |
| Mobile `alhasanApps` `origin/main` (hanya dibaca) | `f8ebba0` |

## Handoff ke auditor

> *"Audit hanya Fase 3 PRD V6 buatan Claude Code pada branch `claude/prd-v6-fase-3`. Baca AGENTS.md, PRD-V6.md, audit Fase 1–2, `docs/phase-v6-3/README.md`, baseline audit Fase 2 `ca62c31`, dan semua commit fase. Uji independen setoran dan pengeluaran, status verifikasi, saldo, pembalik, kuitansi, serta pembayaran tagihan dari tabungan dengan fixture Rp100.000/Rp25.000 dan skenario pembayaran silang sesuai PRD. Paksa rollback di antara debit dan pembayaran; jalankan konkurensi serta retry untuk membuktikan nol transaksi ganda atau yatim dan nol saldo negatif. Periksa rekonsiliasi kas agar transfer internal tabungan tidak dihitung sebagai penerimaan baru; uji akses silang dan regresi Fase 1–2/PSB. Jika perlu, koreksi terarah dan ulangi tes relevan. Catat keputusan serta batas di `docs/phase-v6-3/AUDIT-CODEX.md`, commit/push branch, lalu berhenti. Jangan mulai Fase 4, merge, atau deploy."*

Implementator berhenti di sini: tidak merge, tidak deploy, tidak migrasi produksi, tidak merilis aplikasi, tidak memulai Fase 4.
