# PRD V6 Fase 1 — Fondasi BSA dan Transaksi Minimum

**Status (25 Oktober 2026):** implementasi selesai dan diuji lokal oleh implementator; **menunggu audit independen Codex**. Belum merge, belum deploy, belum migrasi produksi, belum rilis aplikasi. Fase 2 belum dimulai. Fase 1 **bukan** izin rilis parsial: tagihan dan tabungan dirilis bersama sesudah Fase 5 dan keputusan Human Developer.

| Butir | Nilai |
| --- | --- |
| Workstream | PRD V6 Fase 1–5 keuangan santri/BSA — implementator Claude Code, auditor Codex (`AGENTS.md`, keputusan 25 Oktober 2026) |
| Branch | `claude/prd-v6-fase-1` |
| Baseline web | `origin/main` `761ae07fd41fd5833a2f43653567e0db80a643ff` (merge PR #86) memuat spesifikasi `477ad3cc4fac888934b96744f18990af1d9db9a3` (`codex/prd-v6-spesifikasi`); `git diff 477ad3c origin/main` kosong. Branch dibuat dari `origin/main`, tidak bekerja di `main`. |
| Mobile | `~/alhasanApps` hanya dibaca: `origin/main` `f8ebba0` (PR #16); salinan kerja lokal berada di branch lain (`claude/aset-appstore-header` `c3e096c`) dengan perubahan `package.json`/`package-lock.json` yang belum di-commit milik pekerjaan lain — tidak disentuh. Fase 1 tidak memerlukan aplikasi. |
| Lingkungan uji | PHP 8.4.14, MariaDB 12.3.2 lokal (`innodb_snapshot_isolation=ON`), Chromium Playwright; seluruh DB `test_*_test` dengan fixture sintetis. Tidak ada data produksi yang dibuka/diubah. |

## Keputusan Human Developer selama Fase 1

**K-1 (25 Oktober 2026) — penugasan BSA tidak gugur saat semester berganti.** Pertanyaan diajukan sebelum migrasi karena fondasi `bendahara_bulanan` semula `wajib_tahun_aktif = true` (gugur setiap semester). Pilihan Human Developer: pakai tabel fondasi `bendahara_bulanan_assignments`, label menjadi "Petugas BSA", capability ditambah aditif, berlaku menurut tanggal mulai/selesai dan status aktif (seperti petugas kesehatan V5). Konsekuensi yang disampaikan saat bertanya: baris bendahara bulanan lama yang masih aktif dan dalam masa berlaku ikut menjadi Petugas BSA — `bin/v6_phase1_preflight.php` melaporkan jumlahnya agar ditinjau sebelum rilis. Dicatat di `PRD-V6.md` §5.1 dan `AGENTS.md`.

## Yang dikerjakan (persyaratan Fase 1)

1. **Konteks dan audit kode fondasi** — dibaca `AGENTS.md`, `PRD-V6.md`, `docs/prd-v6/prompts-implementasi-audit.md`, status V5 (audit Fase 5 belum lulus seluruh kriteria; V5 tidak disentuh), migrasi terakhir (026), `PenugasanJenis`/`PenugasanService`/`Capabilities`, tabel `bendahara_bulanan_assignments` (012, cakupan `jenjang` opsional), santri/wali/`santri_wali`, penempatan kelas (`plotting_kelas` → `kelas.jenjang` = unit santri), `AuditLogger`, router API. Hasil audit kode: perluasan cukup di katalog (`PenugasanJenis`); resolver `Capabilities` membaca `wajib_tahun_aktif` dari katalog sehingga tidak perlu cabang khusus, dan skema penugasan tidak perlu diubah.
2. **Satu penugasan BSA** — jenis fondasi `bendahara_bulanan` dipertahankan (tabel, entitas audit, subjek pengurus, cakupan unit/jenjang), diperluas aditif: label "Petugas BSA", `prd = V6`, `wajib_tahun_aktif = false` (K-1), capability lama `pembiayaan_bulanan.{tagihan,pembayaran,verifikasi,laporan}` tetap berurutan + `bsa.tabungan` baru; tidak lagi "modul mendatang" (panitia/bendahara PSB tetap mendatang). Admin menugaskan/mengakhiri/menonaktifkan lewat Pusat Penugasan yang sudah ada (`admin/admin_penugasan.php?jenis=bendahara_bulanan`); formulir menjelaskan masa berlaku. Tidak ada role login baru.
3. **Migrasi aditif `027_v6_fase1_bsa.sql`** — enam tabel baru, tanpa `ALTER`/`DROP`/DML pada tabel lama, seluruh nominal `BIGINT` rupiah bulat, 22 kunci asing, 14 `CHECK`:
   - `bsa_tagihan` — satu tagihan per santri per periode (`UNIQUE santri_id, periode`), periode `TTTT-BB`, jatuh tempo, `total`, `terbayar` (cerminan pembayaran sah, `0 ≤ terbayar ≤ total`), unit saat terbit, status `Terbit|Dibatalkan`, versi, penugasan/pengurus/penerbit, kunci idempotensi;
   - `bsa_tagihan_komponen` — snapshot komponen (≥ 1, nominal > 0, total = Σ komponen);
   - `bsa_tabungan_transaksi` — ledger: jenis `Setoran|Penarikan|Belanja|PembayaranTagihan|Pembalik`, arah konsisten dengan jenis (CHECK), nominal > 0, `saldo_sesudah ≥ 0`, status `Menunggu|Sah|Ditolak` (status `Sah` wajib punya penyetuju + waktu), metode, tujuan, referensi, catatan, hubungan pembalik (unik, wajib untuk jenis Pembalik), penugasan, pencatat/penyetuju, kunci idempotensi;
   - `bsa_tabungan_akun` — satu akun per santri, **cerminan ledger** (`saldo ≥ 0` CHECK, jumlah transaksi, transaksi terakhir);
   - `bsa_pembayaran` — skema minimum pembayaran tagihan (tunai/transfer/tabungan, nomor kuitansi unik, satu debit tabungan per pembayaran, hubungan pembatalan); **alurnya Fase 2–3**, Fase 1 belum menulis ke tabel ini;
   - `bsa_idempotensi` — satu kunci per akun untuk satu operasi + isi (`UNIQUE user_id, kunci`), tanpa salinan respons.
   Rollback berpasangan `database/rollbacks/027_*.sql` melepas enam tabel itu saja, didahului **penjaga data SQL** (CHECK pada tabel sementara): bila ada satu baris BSA pun, rollback berhenti sebelum `DROP`.
4. **Layanan `App\Bsa\BsaService` + `BsaRepository`** (satu pintu untuk web dan API):
   - Hak dihitung ulang dari basis data setiap panggilan (`Capabilities::forget()`): **petugas** (sumber penugasan) baca + mutasi santri **aktif** dalam cakupan unit penugasannya; **admin** dan **admin yang juga petugas** hanya baca seluruh santri; **orang tua** hanya baca anak dengan relasi `santri_wali` aktif (akun aktif + peran orang tua + master wali aktif + relasi tidak diarsipkan + santri aktif); lainnya 403. Santri/tagihan di luar hak → **404** (ID ditebak tidak membocorkan keberadaan).
   - Unit santri = `kelas.jenjang` dari penempatan kelas aktif pada tahun ajaran aktif. Petugas unit tertentu tidak menjangkau santri yang belum ditempatkan (unit tidak terbukti); petugas seluruh unit menjangkau semua santri aktif.
   - Mutasi (`setoran`, `penarikan`, `terbitkanTagihan`) dalam satu transaksi dengan **satu urutan kunci**: penugasan BSA (kunci baca) → klaim idempotensi (kunci tulis) → santri (kunci baca) + cakupan → akun tabungan (kunci tulis) → ledger + pembaruan akun bersyarat `WHERE saldo = saldo_lama` → selesaikan klaim → audit wajib. Penugasan yang dicabut saat sesi masih terbuka menolak mutasi berikutnya (403) tanpa baris baru. Akun tabungan bersaldo nol dibuat di luar transaksi (`INSERT IGNORE`) hanya setelah pra-periksa hak, agar dua transaksi tidak berebut menyisipkan baris yang sama.
   - **READ COMMITTED + kunci eksplisit.** Uji konkurensi pertama menemukan bahwa MariaDB 12 (`innodb_snapshot_isolation=ON`) menggagalkan `FOR UPDATE` di REPEATABLE READ dengan galat 1020 bila baris saldo diubah transaksi lain sesudah snapshot transaksi terbentuk (dua penarikan → satu sukses, satu 503). Transaksi BSA kini memakai `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` (hanya transaksi itu) dan kebenaran saldo bertumpu pada kunci baris + kunci unik; 1020/1205/1213 dipetakan ke 409 dapat diulang. Preflight memeriksa binlog (RC memerlukan ROW/MIXED bila binlog aktif).
   - Nominal: bilangan bulat JSON atau teks angka (titik ribuan `100.000` boleh), 1 … 1.000.000.000; pecahan, tanda, notasi ilmiah, float, bool ditolak 422 — tidak ada float di jalur uang. Saldo tidak negatif dijaga tiga lapis: pemeriksaan layanan sesudah kunci tulis, pembaruan bersyarat, dan CHECK basis data.
   - Idempotensi: kunci + isi sama → hasil sama (`replayed`); kunci sama dengan nominal/operasi/santri berbeda → 409 tanpa mutasi. Hash memuat operasi, santri, nominal, metode, tujuan, referensi, catatan.
   - Penarikan: tujuan wajib (3–255), transfer wajib referensi; petugas pencatat sekaligus penyetuju (`disetujui_oleh`, `disetujui_pada`), status `Sah`, saldo efektif berubah satu kali. Tagihan: 1–10 komponen, jatuh tempo ≥ awal periode, total ≤ Rp1 miliar, unit saat terbit disalin; **tidak memotong tabungan**.
   - Audit: `audit_logs` `bsa.tabungan.setoran|penarikan`, `bsa.tagihan.terbit` dengan ID, jenis, metode, status, periode, penugasan, kanal saja; `AuditLogger` menyaring kunci lain untuk awalan `bsa.` (nominal, saldo, referensi, tujuan, catatan tidak masuk log umum — PRD §5.4). Aktor, waktu, nominal, dan tujuan tersimpan di baris ledger/tagihan itu sendiri. Gagal audit membatalkan transaksi.
5. **Web** (kerangka bersama, `Cache-Control: private, no-store`, POST wajib CSRF):
   - `portal/bsa.php` daftar santri sesuai hak (saldo, sisa tagihan); `portal/bsa_santri.php` rincian: kartu saldo + sisa tagihan, tagihan dan riwayat tabungan **terpisah**; `portal/bsa_transaksi.php?jenis=setoran|penarikan` dan `portal/bsa_tagihan_buat.php` (hanya petugas; kunci idempotensi tersembunyi, validasi gagal dirender ulang dengan kunci sama); `portal/bsa_tagihan.php` rincian tagihan + snapshot komponen.
   - Menu: admin grup **Keuangan › Keuangan santri** (hanya baca), petugas grup **Keuangan › BSA**, orang tua **Keuangan anak** — dipertahankan oleh keputusan Human Developer BE. Hak tetap ditegakkan halaman/layanan, bukan menu.
   - Orang tua tidak melihat referensi transfer dan catatan internal petugas.
6. **API** (`/api/v1/v6/bsa/*`, layanan yang sama, no-store): `GET akses`, `GET santri`, `GET santri/{id}`, `GET santri/{id}/saldo`, `POST santri/{id}/setoran`, `POST santri/{id}/penarikan`, `POST tagihan`, `GET tagihan/{id}`. Kunci idempotensi dari header `Idempotency-Key` atau badan. Kontrak lengkap di `docs/api-v1.md`. `feature_capabilities` login/profil bertambah satu kunci `bsa.tabungan` (aditif).
7. **Operasi** — `bin/v6_phase1_preflight.php [manifest]` (versi server menegakkan CHECK, InnoDB, binlog, tabel induk, 026 tercatat, jumlah penugasan bendahara lama yang akan menjadi BSA, manifest jumlah baris seluruh tabel lama + **sidik jari ID** santri/wali/santri_wali/users + relasi santri–wali), `bin/v6_phase1_verify.php [--rollback] [manifest]` (6 tabel InnoDB, 22 kunci asing, 0 yatim, saldo akun = Σ ledger sah = saldo sesudah transaksi terakhir, jumlah berjalan per transaksi, tidak ada saldo negatif/tagihan ganda/klaim menggantung, total = Σ komponen, terbayar = Σ pembayaran sah, audit tanpa data keuangan; dengan manifest: jumlah baris dan sidik jari ID tetap), `bin/v6_phase1_rollback_preflight.php` (blokir bila ada data BSA atau 027 bukan migrasi terakhir). Fixture `tests/v6_phase1_seed.php`, DB uji `tests/v6_phase1_db.sh`.

## Kriteria penerimaan → bukti

| Kriteria Fase 1 | Bukti |
| --- | --- |
| Admin menugaskan satu pengurus BSA; pengurus menerbitkan tepat satu tagihan uji, mencatat setoran Rp100.000 dan penarikan Rp30.000; saldo Rp70.000 di web/API | `bukti/integrasi.txt` I-2, I-3; `bukti/http.txt` H-A, H-C (web: saldo Rp70.000 tampil), H-D (API: saldo Rp70.000, juga dibaca admin dan wali); `bukti/browser.txt` B-B (375/768/1280) |
| Dua setoran kunci sama → tepat satu kredit; kunci sama nominal berbeda → ditolak tanpa mutasi | `bukti/integrasi.txt` I-4, I-5 (8 proses bersamaan kunci+isi sama → 1 kredit; 6 proses kunci sama nominal beda → 1 + 5×409); `bukti/http.txt` H-C (klik ganda web), H-D (API 200 replay, 409 CONFLICT); `bukti/browser.txt` B-C (dobel klik) |
| Dua penarikan Rp80.000 bersamaan atas saldo Rp100.000 → paling banyak satu sukses, tidak ada saldo negatif | `bukti/integrasi.txt` I-5 (proses terpisah: tepat 1 sukses + 1×422; 8 penarikan Rp30.000 → 3 sukses, saldo berbeda tiap sukses, sisa Rp10.000); `bukti/http.txt` H-E (dua permintaan API bersamaan → `[201, 422]`); I-10 CHECK basis data |
| Admin/wali tidak dapat mutasi; petugas tanpa penugasan, pengurus di luar cakupan, wali anak lain, sesi dengan penugasan dicabut → 403/404 atau daftar kosong tanpa mutasi/data santri lain | `bukti/integrasi.txt` I-1, I-6, I-7, I-8; `bukti/http.txt` H-B, H-C, H-D, H-F (formulir terbuka + token lama sesudah pencabutan), H-G (akun dinonaktifkan, relasi wali dicabut) |
| Migrasi dan rollback pada salinan mempertahankan jumlah serta ID santri/wali dan baris PSB/V1–V5; verifier nol referensi yatim baru | `bukti/migrasi-rollback.txt` M-1…M-8 (manifest seluruh tabel lama + sidik jari ID, `CHECKSUM TABLE` 23 tabel inti termasuk PSB dan V2–V5, struktur kolom, 22 kunci asing, 0 yatim, idempoten, penjaga SQL menolak rollback berdata, siklus naik–turun–naik); `bukti/integrasi.txt` I-11 |
| Preflight, verifier, rollback aman, fixture sintetis; uji MySQL/MariaDB salinan | `bin/v6_phase1_*.php`, `tests/v6_phase1_seed.php`; `bukti/migrasi-rollback.txt`; verifier juga dijalankan sesudah uji integrasi/HTTP (I-10, H-H) |

## Hasil pengujian Fase 1 (lokal, kode final)

| Rangkaian | Hasil | Berkas |
| --- | --- | --- |
| Statis `tests/v6_phase1_static.php` (migrasi, katalog, urutan kunci, tanpa float, CSRF/escape, rute, privasi audit, batas Fase 1 via `git diff` baseline, lint 25 berkas) | 75/0 | `bukti/statis.txt` |
| Integrasi layanan + konkurensi proses terpisah + validasi + penjaga CHECK + verifier + preservasi `tests/v6_phase1_integration.php` | 120/0 | `bukti/integrasi.txt` |
| HTTP web + API lewat server PHP lokal `tests/v6_phase1_http.php` (log server 0 galat PHP, 0 referensi transfer di log) | 104/0 | `bukti/http.txt` |
| Migrasi–rollback pada dua salinan berantai V5 Fase 5 `tests/v6_phase1_migration.php` | 29/0 | `bukti/migrasi-rollback.txt` |
| Browser 375/768/1280 + tanpa JavaScript `tests/browser/uji-v6-fase1.mjs` (log server 0 galat PHP) | 59/0 | `bukti/browser.txt`, `tangkapan/*.png` (diperiksa visual) |

Kontrol yang tercatat selama implementasi (kode sebelum perbaikan, bukan bukti akhir): uji konkurensi pertama pada REPEATABLE READ menghasilkan 117/2 — dua penarikan bersamaan: satu 201 + satu **503** (galat 1020), delapan penarikan: 2 sukses alih-alih 3. Sesudah READ COMMITTED: tepat satu sukses + 422 saldo kurang; uji kini juga menegaskan setiap penolakan adalah 422 bisnis, bukan galat basis data.

Cara mengulang (berurutan; DB perantara rantai fixture bernama tetap):

```bash
bash docs/phase-v6-1/perintah-uji.sh
bash docs/phase-v6-1/perintah-regresi.sh
```

## Regresi

Dijalankan berurutan lewat `perintah-regresi.sh` (ringkasan `bukti/regresi/ringkasan.txt`, keluaran per suite di `bukti/regresi/`).

| Rangkaian | Hasil | Catatan |
| --- | --- | --- |
| Seluruh 35 suite statis | 19 exit 0 · 14 exit 1 · 2 exit 77 | Seluruh kegagalan oracle historis atau lingkungan (tabel di bawah). `v6_phase1_static` 75/0, `navigasi_ikon_static` 36/0. |
| V5 Fase 5 integrasi / HTTP | 95/0 · 57/0 | |
| V5 Fase 3 integrasi / HTTP · Fase 4 HTTP | 82/0 · 70/0 · 43/0 | |
| Fondasi penugasan `penugasan_integration` | 161/0 | Pada DB V6 (jenis `bendahara_bulanan` diperluas). |
| V2 Fase 4 / Fase 5 integrasi | 124/0 · 143/0 | |
| HTTP Tahap 5 / Tahap 7 | 106/0 · 122/0 | |
| HTTP Tahap 6 sesudah 027 → 026 → 025 → 024 dilepas (preflight + penjaga SQL, tabel kosong) | 20/0 · 132/0 | Rantai rollback kosong berjalan dengan penjaga baru 027. |
| HTTP Tahap 2 | 185/6 | Empat oracle menu BB/BC/BD lama + dua oracle peta menu Keuangan (P-1, keputusan BE). |
| HTTP Tahap 3 | 166/1 | Oracle historis "AG belum dipasang" sejak Tahap 8 (sama dengan V5). |
| V5 Fase 5 rilis / V5 Fase 3 migrasi | 12/10 · 20/6 | Mengasumsikan 026 migrasi terakhir. **Kontrol baseline:** suite yang sama dari `git archive 761ae07` → **22/0 · 26/0** (`bukti/regresi/*-kontrol-baseline-761ae07.txt`); selisih hanya karena 027 ada. |
| Browser kerangka Tahap 1 (`uji-redesain-web-tahap1.mjs`) sembilan akun × 375/768/1280 pada DB V6 | 91/0 | Admin kini membuka 24 alamat menu (termasuk Keuangan santri). |

**Tidak dijalankan ulang** (dicatat, bukan disembunyikan): V5 Fase 1–2 integrasi/HTTP/migrasi (oracle historis yang sudah tercatat di V5), keamanan PSB HTTP/browser (memerlukan Apache + salinan sumber), suite V3/V4 yang mengunci nama DB tetap, browser V3/V4/V5, uji Android/iOS. Kode PSB, V1–V5, `app/Auth`, master data, dan notifikasi tidak berubah dari baseline (statis S-7).

## Oracle historis yang kini gagal (tidak diubah, tidak dilonggarkan)

| Suite | Kegagalan | Sebab |
| --- | --- | --- |
| `redesain_web_tahap2_static` 120/1 | "Admin: 7 grup" | Grup admin baru **Keuangan** (P-1, keputusan BE). |
| HTTP Tahap 2 185/6 | Peta menu admin/murobi/pengurus/orang tua (4 lama BB/BC/BD) + grup admin + "menu/tab menyorot tempatnya" untuk Keuangan santri | P-1, keputusan BE. Penanda menu aktif benar (`menu=Keuangan santri`); oracle mengharapkan grup dari daftar grup lama. |
| `redesain_web_tahap6_static` 139/2 | AF "modul mendatang" literal tiga jenis; "Navigation tidak diubah Tahap 6" | BSA bukan lagi modul mendatang (K-1); menu Keuangan di `Navigation`. |
| `penugasan_static` 281/2 | "tujuh jenis" | Sama dengan V5 (jenis kesehatan). |
| `v5_phase1_static` 44/4, `v5_phase2_static` 61/2, `v5_phase3_static` 68/3, `v5_phase5_static` 57/1 | Migrasi 024/025/026 "terakhir"; berkas tidak berubah sejak baseline fase V5 | Migrasi 027, katalog `PenugasanJenis`, `AuditLogger`. Dua dari kegagalan V5 Fase 1 dan satu Fase 3 sudah gagal di bukti V5 Fase 5. |
| `redesain_web_tahap7_static` 100/2, `tahap8` 78/2, `tahap4` 95/1, `tahap3` 151/1, `keamanan_psb_static` 82/1 | Berkas/direktori tidak berubah sejak baseline tahap; AG belum dipasang | Sama dengan bukti V5 Fase 5. |
| `v2_phase4_static` 287/1 | Literal deep link mobile | Sama dengan V5 Fase 5 (mobile sejak `38e1850`). |
| `v2_phase5_static` 307/3 | Versi Expo/React Native/expo-notifications | **Lingkungan, bukan V6:** salinan kerja `~/alhasanApps` memuat perubahan `package.json` yang belum di-commit milik pekerjaan lain. Dengan ekspor bersih mobile `origin/main` `f8ebba0` (`MOBILE_APP_ROOT`) → **310/0** (`bukti/regresi/statis-v2_phase5_static-mobile-origin-main.txt`). |
| `v3_phase2/3_static` | exit 77 | Memerlukan DB V3 bernama tetap (sama dengan V5). |
| V5 Fase 5 rilis 12/10, V5 Fase 3 migrasi 20/6 | 026 migrasi terakhir | Kontrol baseline 22/0 · 26/0 (lihat Regresi). |

## Penafsiran yang perlu ditinjau auditor/Human Developer

- **P-1 Menu Keuangan — DIPUTUSKAN Human Developer 25 Oktober 2026 (keputusan BE, `docs/redesain-web/keputusan.md`): dipertahankan.** Admin grup "Keuangan" › "Keuangan santri" (admin kini 19 menu dalam 8 grup), pengurus grup "Keuangan" › "BSA", orang tua "Keuangan anak" (tampil untuk setiap akun orang tua aktif; daftar kosong bila belum ada anak berelasi). Oracle Tahap 2 tidak diubah; kegagalannya dicatat sebagai kegagalan yang diharapkan.
- **P-2 Penugasan lama menjadi BSA.** Sesuai K-1, baris `bendahara_bulanan_assignments` yang aktif dan dalam masa berlaku — termasuk yang dibuat sebelum V6 dan yang tahun ajarannya sudah tidak aktif — langsung menjadi Petugas BSA sesudah kode V6 dirilis. Preflight melaporkan jumlahnya.
- **P-3 Unit santri.** Cakupan unit dibandingkan dengan `kelas.jenjang` penempatan aktif tahun ajaran aktif (tanpa membedakan huruf besar/kecil). Santri tanpa penempatan hanya terjangkau petugas seluruh unit; tagihan menyimpan unit saat terbit.
- **P-4 Admin yang juga petugas.** Akun ber-role admin yang memegang penugasan BSA tetap hanya baca (tafsir ketat PRD §2 "admin membaca dan mengaudit tanpa mengubahnya"); BSA memakai akun pengurus tanpa role admin.
- **P-5 Santri nonaktif.** Petugas hanya membaca/memutasi santri aktif; admin membaca santri aktif serta santri nonaktif yang sudah punya data BSA. Penarikan saldo santri yang keluar (nonaktif) belum ada jalurnya — dicatat untuk Fase 3.
- **P-6 Setoran langsung sah.** Fase 1 mencatat setoran/penarikan berstatus `Sah` oleh petugas pencatat (= penyetuju). Status `Menunggu`/`Ditolak` dan verifikasi transfer terpisah sudah ada di skema untuk Fase 3; referensi transfer wajib diisi, tetapi "tercatat" bukan bukti dana tiba (PRD §5.3).
- **P-7 Tagihan Fase 1.** Penerbitan satu santri dengan komponen yang diketik petugas (tanpa tabel tarif); tarif unit, penyesuaian beralasan, penerbitan massal, pembayaran, dan kuitansi adalah Fase 2. Komponen tidak diisi contoh (mengikuti keputusan AY: isi resmi diisi Human Developer).
- **P-8 Batas nominal** Rp1.000.000.000 per transaksi/tagihan untuk mencegah salah ketik berlipat; bukan aturan bisnis dan dapat diubah.
- **P-9 Idempotensi per akun lintas operasi.** Satu kunci hanya untuk satu operasi + isi; dipakai untuk operasi/santri/nominal lain → 409. Replay sesudah penugasan dicabut dijawab 403 (keputusan sah diambil ulang).
- **P-10 Wali** melihat jenis, nominal, tujuan, saldo sesudah, waktu, dan nama petugas; tidak melihat referensi transfer dan catatan internal.

## Batas yang belum diuji / terbuka

- Tidak ada uji cPanel/produksi atau MySQL (lokal hanya MariaDB 12.3.2). `READ COMMITTED`, `LOCK IN SHARE MODE`, `CHECK` (MariaDB 10.2+/MySQL 8.0.16+), dan binlog hosting belum diperiksa di server nyata — preflight memeriksanya sebelum migrasi.
- Interleaving konkurensi diuji pada pola dua/delapan proses dan dua permintaan HTTP serentak; bukan bukti seluruh kemungkinan interleaving. Server PHP bawaan (`php -S`) memproses permintaan berurutan, sehingga H-E membuktikan perilaku HTTP, sedangkan bukti konkurensi sesungguhnya adalah I-5 (proses + koneksi DB terpisah).
- Uji keamanan PSB HTTP (Apache + `.htaccess`) dan browser kerangka V3/V4 lain tidak dijalankan ulang; kode PSB/V1–V5 tidak berubah (dibuktikan `git diff` di statis S-7).
- Aplikasi mobile, Push/notifikasi, laporan, kuitansi, pembayaran tagihan, pembalik/koreksi, verifikasi transfer, rekonsiliasi — **belum** dikerjakan (Fase 2–5).
- **Risiko urutan rilis V5/V6.** Bila branch ini digabung ke `main` lalu `main` dideploy (misalnya untuk merilis V5), `bin/migrate.php up` ikut memasang 027 dan menu Keuangan langsung aktif untuk admin, petugas BSA (termasuk baris bendahara bulanan lama, P-2), dan orang tua — itu rilis parsial yang dilarang PRD. Fase 1 tidak menambah sakelar rilis; Human Developer perlu memutuskan apakah V6 tidak digabung ke cabang deploy sebelum Fase 5, atau ditambah sakelar penonaktif.
- Tombol kirim formulir pendek tanpa JavaScript pada 375 px dapat berada di belakang bilah bawah sampai halaman digulir ke dasar (padding kerangka memberi ruang; uji browser menggulir ke dasar). Perilaku kerangka bersama, bukan kode V6; dicatat untuk ditinjau.

## Langkah rilis dan pemulihan (untuk keputusan Human Developer kelak — bukan izin deploy)

Tagihan dan tabungan hanya dirilis bersama sesudah Fase 5. Bila kelak diputuskan:

1. Backup terverifikasi DB dan berkas.
2. `php bin/v6_phase1_preflight.php /jalur/aman/manifest-027.json` — wajib tanpa `[gagal]`; tinjau jumlah penugasan bendahara lama yang akan menjadi BSA (P-2).
3. `php bin/migrate.php up` (menerapkan 027).
4. `php bin/v6_phase1_verify.php /jalur/aman/manifest-027.json` — wajib tanpa `[gagal]`.
5. Admin menugaskan petugas di Pengaturan › Penugasan › Petugas BSA.

**Pemulihan.** Rollback hanya bila `php bin/v6_phase1_rollback_preflight.php` lulus (keenam tabel BSA kosong **dan** 027 migrasi terakhir); lalu `php bin/migrate.php rollback` dan `php bin/v6_phase1_verify.php --rollback /jalur/aman/manifest-027.json`. Bila sudah ada transaksi/tagihan, **jangan rollback** (penjaga SQL juga menolaknya): pulihkan backup terverifikasi atau buat migrasi korektif. Kode Fase 1 tetap berjalan sebelum migrasi dan sesudah rollback: halaman/API BSA menjawab 503 "belum dipasang", menu Keuangan disembunyikan, pusat penugasan dan capability tetap berjalan (diuji M-7). Mengembalikan kode ke baseline juga mengembalikan penugasan bendahara ke perilaku lama (gugur per semester, tanpa akses halaman).

## Handoff ke auditor

> *"Audit hanya Fase 1 PRD V6 buatan Claude Code pada branch `claude/prd-v6-fase-1`. Baca AGENTS.md, PRD-V6.md, docs/prd-v6/prompts-implementasi-audit.md, docs/phase-v6-1/README.md, baseline `761ae07`, dan seluruh commit fase. Uji independen, koreksi terarah jika diperlukan, catat temuan dan bukti secara jujur di docs/phase-v6-1/AUDIT-CODEX.md, commit serta push hasil audit, lalu berhenti. Jangan memulai Fase 2, merge, atau deploy."*

Implementator berhenti di sini: tidak merge, tidak deploy, tidak migrasi produksi, tidak merilis aplikasi, tidak memulai Fase 2.
