# PRD V5 Fase 3 — Ringkasan Orang Tua dan Notifikasi

**Status (24 Oktober 2026):** diimplementasikan Claude Code, **menunggu audit independen Codex**. Belum merge, belum deploy, belum migrasi produksi. Fase 4 belum dimulai.

| Butir | Nilai |
| --- | --- |
| Workstream | PRD V5 Fase 1–5 kesehatan santri — implementator Claude Code, auditor Codex (`AGENTS.md`) |
| Branch | `claude/prd-v5-fase-3` |
| Baseline | `c1cb262141b04791eb7870e4e8f466ae361fa0d3` — commit audit Codex yang meluluskan Fase 2 (`audit(v5): luluskan Fase 2 dengan koreksi konsistensi versi dan guard admin`). Diverifikasi sebelum membuat branch: `git ls-remote` → `origin/claude/prd-v5-fase-2` = `c1cb262`; `origin/main` `ca9cfa2` (merge PR #81) memuat `c1cb262` (`merge-base --is-ancestor`) dan `git diff c1cb262 origin/main` kosong. Branch dibuat dari `c1cb262`, bukan dari `main`, tanpa worktree. |
| Mobile | `~/alhasanApps` tidak dibaca ulang dan tidak diubah (aplikasi = Fase 4). API V5 hanya bertambah rute baru; `tautan` notifikasi bertambah satu kunci aditif. |
| Lingkungan uji | PHP 8.4.14, MariaDB 12.3.2 lokal, Node v26.7.0, Chromium Playwright; seluruh DB `test_*_test` dengan fixture sintetis (`sbx_v5_*`, santri `V5S02xx`, telepon sintetis `0812000053xx`). Push memakai klien palsu yang menghitung permintaan; tidak ada permintaan jaringan ke Expo/WhatsApp. Tidak ada data produksi yang dibuka/diubah. |

## Yang dikerjakan (persyaratan Fase 3)

1. **Ringkasan wali terpisah dari catatan klinis** — migrasi aditif `026_v5_fase3_ringkasan_wali.sql` (hanya `CREATE TABLE IF NOT EXISTS`; tabel 024–025 dan tabel notifikasi tidak diubah) + rollback berpasangan:
   - `kesehatan_ringkasan` — kepala, **satu per kunjungan**: status `Terbit|Ditarik`, versi berlaku, versi terakhir, dan `revisi` (token optimistic yang naik pada setiap Terbit/Ganti/Tarik).
   - `kesehatan_ringkasan_versi` — **snapshot tetap** tiap versi publikasi: teks yang disusun petugas (ringkasan, tindakan umum, tindak lanjut untuk orang tua), tanggal kunjungan, dan versi kunjungan sumber (kunci asing gabungan ke `kesehatan_kunjungan_versi`). Teks tidak pernah di-UPDATE; hanya status (`Terbit → Diganti/Ditarik`) dan waktu berakhir.
   - `kesehatan_ringkasan_peristiwa` — jejak `Terbit/Ganti/Tarik`: pelaku, waktu, alasan (wajib untuk Ganti/Tarik, juga CHECK), jumlah penerima, kanal, klaim idempotensi.
   - `kesehatan_ringkasan_penerima` — wali berakun yang berhak saat peristiwa terbit (dasar notifikasi).
   Tidak ada kolom klinis di tabel ini; teks tidak disalin otomatis dari keluhan, catatan klinis, tindakan, obat, rujukan, atau tindak lanjut internal.
2. **Pratinjau tepat sebelum publikasi** — `RingkasanService::pratinjau()` menghasilkan proyeksi yang **sama persis** dengan tampilan wali (satu fungsi `proyeksi()` dipakai pratinjau, tampilan internal versi berlaku, dan detail wali; satu partial web `portal/partials/kesehatan_ringkasan_konten.php`) beserta `pratinjau_hash`. `terbitkan()` mewajibkan konfirmasi eksplisit dan hash yang cocok, sehingga yang terbit adalah yang dipratinjau.
3. **Hanya ringkasan terbit bagi wali berelasi aktif** — proyeksi `RingkasanRepository::wali*` hanya membaca tabel ringkasan + relasi wali (akun aktif, peran orang tua, master wali aktif, `santri_wali` aktif, santri aktif) per baris pada setiap daftar/detail; tidak pernah menyentuh tabel klinis. Ringkasan ditarik tidak membawa isi dari SQL. Tidak ada, milik anak lain, atau relasi dicabut sama-sama 403.
4. **Versi publikasi, penggantian/penarikan beralasan, publikasi ulang** — penggantian membuat versi baru beralasan (lama → `Diganti`), penarikan beralasan (wali melihat keterangan "telah ditarik" tanpa isi), terbit ulang sesudah penarikan beralasan. Koreksi klinis tidak menyentuh tabel ringkasan; perubahan bagi wali memerlukan versi publikasi baru. Petugas dan admin menelusuri seluruh versi dan alasan.
5. **Notifikasi InApp/Push generik** — dalam transaksi yang sama dengan publikasi: tepat satu baris InApp per wali berakun yang berhak dan paling banyak satu Push (sakelar Push menyala + perangkat aktif), lewat `NotificationRepository::enqueue()` V2 (kunci unik peristiwa × kanal × penerima). Kegagalan antrean membatalkan publikasi. WhatsApp tidak pernah diantrekan. Judul/isi dari katalog tetap tanpa nama santri, keluhan, obat, rujukan, telepon, atau isi ringkasan; data hanya `{tipe: kesehatan_ringkasan, ringkasan_id}`. Worker Push (`NotificationDispatcher::kirimPush`) memeriksa ulang bahwa ringkasan masih Terbit pada versi peristiwa dan penerima masih wali aktif, lalu merakit ulang teks dari katalog; bila tidak, gagal permanen `AKSES_BERUBAH` tanpa permintaan provider. Retry/receipt memakai jalur V2 yang ada.
6. **Tampilan internal sesuai hak** — petugas: susun, pratinjau, terbit, ganti, tarik, riwayat; admin: versi berlaku + riwayat (tanpa menulis); pembimbing/murobi terkait: versi berlaku saja; wali: proyeksi khusus.

### Antarmuka

- Web petugas/admin/pembaca: `portal/kesehatan_ringkasan.php?id={kunjungan}` (Susun → Pratinjau → konfirmasi Terbitkan; formulir penggantian diisi dari versi berlaku, bukan catatan klinik; dialog Tarik berjalan tanpa JavaScript lewat `<details>`), bagian "Ringkasan untuk wali" pada `kesehatan_detail.php`.
- Web wali: `portal/kesehatan_wali.php` (daftar dengan pilihan anak, `?id=` detail), menu **Kesehatan anak** (keputusan BD). Pusat notifikasi: kategori **Kesehatan**, ketukan → `kesehatan_wali.php?id=` yang memeriksa ulang; tanpa sesi → masuk dulu dengan `next`.
- API: lihat `docs/api-v1.md` bagian "Ringkasan kesehatan untuk wali V5 Fase 3".

  | Metode & jalur | Hak | Keterangan |
  | --- | --- | --- |
  | `GET /api/v1/v5/kesehatan/wali/akses` | semua akun | `{wali, anak}` |
  | `GET /api/v1/v5/kesehatan/wali/ringkasan` | wali | daftar ringkasan anak berelasi aktif |
  | `GET /api/v1/v5/kesehatan/wali/ringkasan/{id}` | wali | proyeksi; tercatat di log akses (`sumber = wali`) |
  | `GET /api/v1/v5/kesehatan/kunjungan/{id}/ringkasan` | petugas, admin, pembimbing/murobi terkait | internal sesuai hak |
  | `POST …/kunjungan/{id}/ringkasan/pratinjau` | petugas | tanpa menulis; `pratinjau_hash` |
  | `POST …/kunjungan/{id}/ringkasan` | petugas | terbit/ganti/terbit ulang; `201`/`200 replayed`/`409`/`422` |
  | `POST …/kunjungan/{id}/ringkasan/tarik` | petugas | alasan wajib; tanpa notifikasi |

- Operasi: `bin/v5_phase3_preflight.php [manifest]`, `bin/v5_phase3_verify.php [--rollback] [manifest]`, `bin/v5_phase3_rollback_preflight.php`; fixture `tests/v5_phase3_seed.php`, DB uji `tests/v5_phase3_db.sh`, pekerja konkurensi `tests/v5_phase3_worker.php`.

## Kriteria penerimaan → bukti

| Kriteria Fase 3 | Bukti |
| --- | --- |
| Sebelum terbit wali kosong/ditolak untuk daftar, detail, API, ID tebakan; sesudah terbit wali berelasi aktif melihat hanya ringkasan anaknya | `bukti/integrasi.txt` L-1, L-5; `bukti/http.txt` M-B, M-D, M-F; `bukti/browser.txt` E-B |
| Wali kedua menerima ringkasannya sendiri; wali lain/relasi dicabut tidak dapat membuka lewat sesi/token lama atau tautan notifikasi | `bukti/integrasi.txt` L-5, L-9; `bukti/http.txt` M-D, M-E (sesi web, token API, tautan notifikasi lama); `bukti/browser.txt` E-B |
| Satu publikasi → tepat satu peristiwa + satu InApp per wali berakun berhak + paling banyak satu Push sesuai sakelar; retry tidak menggandakan | `bukti/integrasi.txt` L-4 (replay, kunci lain 409), L-6 (6 proses kunci sama → 1 + 5 replay; 6 proses kunci beda → 1 + 5×409), L-7 (Push hanya wali berperangkat, retry baris sama), L-11 (ulang sesudah gagal); `bukti/http.txt` M-C (klik ganda), M-F (header replay); `bukti/browser.txt` E-A (klik ganda) |
| Payload Push, log umum, dan daftar notifikasi memuat 0 nama santri, keluhan, obat, rujukan, telepon, rincian klinis | `bukti/integrasi.txt` L-7, L-12 (outbox, payload Push palsu, pusat notifikasi, audit_logs, log akses, klaim); `bukti/http.txt` M-D, M-F, M-H (log server, outbox); verifier 026 |
| Push OFF dan WhatsApp OFF → 0 request provider; klik notifikasi sesudah logout meminta login dan tetap memeriksa relasi | `bukti/integrasi.txt` L-8 (termasuk sakelar WhatsApp lama menyala → kanal ditangguhkan), L-9; `bukti/http.txt` M-E |
| Koreksi klinis tidak mengubah teks terbit; bila diganti, versi lama dan alasan tetap dapat ditelusuri petugas berwenang | `bukti/integrasi.txt` L-10; `bukti/http.txt` M-G; `bukti/browser.txt` E-C; verifier 026 (revisi = jumlah peristiwa, Ganti/Tarik beralasan) |

## Hasil pengujian (lokal)

Seluruh hasil berikut dijalankan pada kode final branch (ringkasan `bukti/ringkasan-akhir.txt`).

| Rangkaian | Hasil |
| --- | --- |
| Statis V5 Fase 3 `tests/v5_phase3_static.php` (`bukti/statis.txt`) | 71/0 |
| Integrasi layanan + konkurensi + worker Push palsu `tests/v5_phase3_integration.php` (`bukti/integrasi.txt`) | 82/0 |
| HTTP web + API `tests/v5_phase3_http.php` (`bukti/http.txt`) | 70/0 |
| Migrasi/rollback 026 pada salinan `tests/v5_phase3_migration.php` (`bukti/migrasi-rollback.txt`) | 26/0 |
| Browser 375/768/1280 + tanpa JS `tests/browser/uji-v5-fase3.mjs` (`bukti/browser.txt`, `tangkapan/*.png` diperiksa visual) | 62/0, log server 0 galat PHP |

Cara mengulang (jalankan skrip DB **berurutan**; rantai fixture memakai DB perantara bernama tetap):

```bash
php tests/v5_phase3_static.php
bash tests/v5_phase3_db.sh && V5_RUN_INTEGRATION=1 php tests/v5_phase3_integration.php
V5F3_DB=test_v5_f3_http_test bash tests/v5_phase3_db.sh && V5F3_DB=test_v5_f3_http_test php tests/v5_phase3_http.php
V5_RUN_MIGRATION=1 php tests/v5_phase3_migration.php
V5F3_DB=test_v5_f3_browser_test bash tests/v5_phase3_db.sh
DB_NAME=test_v5_f3_browser_test php -S 127.0.0.1:8996 tests/redesain_web_tahap1_router.php &
(cd tests/browser && BASE_URL=http://127.0.0.1:8996 OUT_DIR=../../docs/phase-v5-3/tangkapan node uji-v5-fase3.mjs)
```

### Temuan selama pengujian implementator (sudah diperbaiki sebelum commit)

- Browser 375px: bilah tombol lengket (`ah-sticky-sm`) menutupi kotak konfirmasi di pratinjau → bilah lengket dilepas dari formulir konfirmasi yang pendek.
- Browser: formulir penggantian tidak terisi versi berlaku karena closure `$nilai` menangkap `$isian` sebelum diisi; asersi HTTP M-G semula terlalu longgar (teks juga tampil di kartu "Yang dilihat wali") sehingga diperketat ke isi `<textarea>`.

## Regresi

Dijalankan **berurutan** pada DB salinan baru (`bukti/regresi/db-ringkasan.txt`, berkas per suite di `bukti/regresi/`).

| Rangkaian | Hasil | Catatan |
| --- | --- | --- |
| Seluruh 33 suite statis (`bukti/regresi/statis-ringkasan.txt`) | 22 exit 0; 9 exit 1; 2 exit 77 | Kegagalan tujuh suite historis (`keamanan_psb`, `penugasan`, `redesain_web_tahap3/4/6/7/8`) dan `v5_phase1_static` 44/4 **identik baris demi baris** dengan bukti Fase 2. Baru: `v5_phase2_static` 61/2 (lihat oracle di bawah). `v2_phase5_static` 303/0 (dua baris lint tambahan untuk halaman baru). `v5_phase3_static` 71/0. |
| Integrasi fondasi penugasan | 161/0 | |
| Integrasi V2 Fase 4 (notifikasi, worker, backoff) | 124/0 | Worker Push yang diubah Fase 3. |
| Integrasi V2 Fase 5 (receipt, laporan) | 138/5 → kontrol 143/0 | Lima kegagalan hanya KL-9a/b/d/e/f (receipt Push pertama), signature yang sama dengan catatan audit Tahap 4: antrean Push fixture lama (22 baris Queued/Failed) mendahului baris uji dalam batch klaim. Kontrol pada salinan baru dengan antrean lama ditunda, **kode dan suite tidak diubah**: 143/0 (`v2-fase5-integrasi-kontrol.txt`). Tidak ada baris V5 di antrean itu. |
| V5 Fase 2 integrasi / probe audit Codex / HTTP / browser | 134/0 · 28/0 · 87/0 · 58/0 | Browser Fase 2 dijalankan ulang karena halaman detail mendapat bagian "Ringkasan untuk wali". |
| V5 Fase 1 integrasi / HTTP | 71/4 · 79/3 | Identik dengan bukti Fase 2 (oracle pembaca binaan lama). |
| V5 Fase 2 migrasi/rollback 025 | 23/6 | Oracle "025 migrasi terakhir" (lihat di bawah). |
| HTTP pembinaan Tahap 5 / penilaian-rapor Tahap 7 | 106/0 · 122/0 | |
| HTTP data/penugasan Tahap 6, sesudah 026 → 025 → 024 di-rollback (tabel kosong, preflight lulus) | 132/0 | `redesain-tahap6-rollback-026-025-024.txt` membuktikan rantai rollback dengan preflight 026 dan 025. |
| HTTP Beranda/navigasi Tahap 2 | 187/4 | Tiga oracle menu BB/BC yang sama dengan Fase 2 + satu baru: menu orang tua (R-1). |
| Browser kerangka (Tahap 1) sembilan akun × 375/768/1280 | 107/0 | |
| Browser kerangka akun V5 (petugas F, admin+petugas, pembimbing, murobi, ganda, wali 1, wali tanpa anak) | 99/0 | |

## Oracle historis yang kini gagal (tidak diubah, tidak dilonggarkan)

Kegagalan berikut muncul karena Fase 3 **memang** menambah migrasi 026, satu rute tulis API, dan menu orang tua sesuai PRD V5 Fase 3. Perilaku yang dilindungi oracle diuji ulang di rangkaian Fase 3.

| Suite | Pemeriksaan | Sebab dan pengganti |
| --- | --- | --- |
| `v5_phase2_static` 63/0 → 61/2 | T-1 "Migrasi 025 berurutan setelah 024 (migrasi terakhir yang ada)"; T-5 hitungan tepat tiga `$body['idempotency_key'] ??=` dalam 4.200 karakter blok `/v5/kesehatan` | Migrasi 026 dan rute tulis ringkasan adalah isi Fase 3. Bentuk respons tulis Fase 2 `{id, versi, replayed}` tetap (bagian lain asersi T-5 lulus); rute baru diuji `v5_phase3_static` S-6 dan HTTP M-F. |
| `v5_phase2_migration` 29/0 → 23/6 | N-1/N-6: `bin/migrate.php rollback` diharapkan melepas 025; N-2: `migrate up` diharapkan hanya 025; N-6: layanan 503 sesudah rollback 025 | Rollback kini melepas 026 lebih dahulu dan `migrate up` menerapkan 025+026. Rantai 026 → 025 → 024 dengan preflight masing-masing dijalankan pada regresi Tahap 6; jalur 026 diuji `v5_phase3_migration` P-1…P-7. |
| `redesain_web_tahap2_http` 188/3 → 187/4 | Peta menu `sbx_ortu_a` | Menu "Kesehatan anak" (R-1) — dipertahankan oleh keputusan Human Developer BD. Tiga kegagalan lain (admin 18 menu BB; pembimbing/murobi BC) sama dengan Fase 2. |

`v5_phase1_static` 44/4, `v5_phase1_integration` 71/4, `v5_phase1_http` 79/3, dan tujuh suite statis historis lain identik dengan bukti Fase 2; `v3_phase2/3_static` tetap dilewati (exit 77). `v5_phase1_migration` tidak dijalankan ulang (sudah gagal 17/9 sejak Fase 2 karena oracle "024 terakhir"; auditor Fase 2 juga tidak menjalankannya). Oracle tidak diubah atau dilonggarkan.

## Penafsiran yang perlu ditinjau auditor/Human Developer

- **R-1 Menu "Kesehatan anak" bagi orang tua — DIPUTUSKAN Human Developer 24 Oktober 2026 (keputusan BD, `docs/redesain-web/keputusan.md`): menu dipertahankan.** Uraian saat handoff: PRD mewajibkan wali membaca ringkasan di website; menu baru di peta orang tua (sesudah "Informasi pembinaan", ikon `fa-kit-medical`) tampil untuk setiap akun orang tua aktif dengan master wali aktif bila migrasi 026 terpasang, termasuk sebelum ada ringkasan (daftar kosong menjelaskan). Bilah bawah HP orang tua tidak diubah. Hak server tidak bergantung pada menu. Pola sama dengan keputusan BB/BC. Oracle Tahap 2 untuk menu `sbx_ortu_a` tetap tidak diubah dan dicatat sebagai kegagalan yang diharapkan.
- **R-2 Penerima dan hak baca.** Penerima notifikasi = seluruh wali berakun dengan relasi aktif saat terbit (tanpa memilih sebagian seperti V3). Hak baca = relasi aktif **saat dibaca**: wali yang dihubungkan kemudian dapat membaca ringkasan anaknya yang sudah terbit (seperti rapor V4); wali yang relasinya dicabut kehilangan seluruhnya. Wali master tanpa akun tidak menerima apa pun.
- **R-3 Peristiwa notifikasi.** Notifikasi hanya pada Terbit/Ganti/terbit ulang (sekali per versi); Tarik tidak mengirim notifikasi baru (notifikasi lama tetap generik, tujuannya menampilkan "ditarik"). Versi yang memuat "Yang perlu diperhatikan orang tua" memakai peristiwa `v5_kesehatan_tindak_lanjut` ("memerlukan perhatian Anda"), tetap satu peristiwa per versi. Push hanya diantrekan bila sakelar menyala **dan** wali punya perangkat aktif saat terbit (aturan V2; V3/V4 mengantre tanpa memeriksa perangkat).
- **R-4 Kata "kesehatan" pada Push.** Judul/isi menyebut kategori ("Informasi kesehatan …") tanpa nama/isi apa pun, setara "Rapor semester tersedia" (V4) dan "Pembaruan pembinaan" (V3). Bila Human Developer menginginkan kalimat yang tidak menyebut kategori di layar kunci, ubah satu fungsi `RingkasanService::teksNotifikasi()`.
- **R-5 Pratinjau tanpa token tersimpan.** Berbeda dengan V3 (tabel pratinjau 30 menit), pratinjau V5 tidak menulis apa pun; `pratinjau_hash` mengikat versi kunjungan, revisi ringkasan, isi, dan alasan. Tidak ada kedaluwarsa waktu; perubahan kunjungan (koreksi klinis) atau ringkasan (petugas lain) sesudah pratinjau → 409 dan petugas menyusun pratinjau ulang.
- **R-6 Syarat sumber.** Hanya kunjungan Tercatat (bukan Draf) dengan santri aktif yang dapat diterbitkan; versi kunjungan sumber dicatat pada snapshot. Satu rantai ringkasan per kunjungan (bukan rangkuman per santri).
- **R-7 Batas baca internal.** Admin membaca versi berlaku, seluruh versi, peristiwa, dan alasan, tanpa menulis (admin+petugas ditolak seperti mutasi klinis). Pembimbing/murobi terkait membaca versi berlaku saja (tanpa versi lama/alasan), konsisten dengan Q-4 Fase 2. Wali tidak melihat alasan, nama petugas, atau versi lama; pengirim tampil "Klinik Pesantren Al Hasan".
- **R-8 Log akses.** Pembukaan detail oleh wali dicatat di `kesehatan_akses_log` (`sumber = wali`, `aksi = baca_ringkasan`, metadata saja) dan tampil di riwayat akses admin. Pembacaan ringkasan oleh staf tidak dicatat terpisah karena bukan rincian klinis (rincian klinis tetap tercatat oleh halaman detail).
- **R-9 Bentuk isi.** Ringkasan 10–2.000 karakter (wajib), tindakan umum dan tindak lanjut untuk orang tua masing-masing ≤ 1.000 (opsional), alasan ≥ 5 karakter. Tidak ada tanda "sudah dibaca" oleh wali (tidak diminta PRD Fase 3; V3 memilikinya).

## Batas yang belum diuji / terbuka

- Tidak ada uji cPanel, produksi, atau MySQL non-MariaDB. Kunci asing gabungan, CHECK lintas kolom, dan `FOR UPDATE` didukung MySQL 8.0.16+/MariaDB 10.4+ tetapi belum dijalankan di hosting.
- Push hanya diuji dengan klien palsu (tiket dan receipt palsu); tidak ada perangkat iOS/Android, Expo nyata, atau ketukan notifikasi di aplikasi (Fase 4). WhatsApp tetap ditangguhkan; tidak diuji dengan penyedia nyata.
- Konkurensi diuji untuk penerbitan dari revisi sama (6 proses) dan kiriman identik (6 proses); pencabutan relasi/penugasan diuji berurutan terhadap worker dan sesi lama, bukan seluruh interleaving dengan kunci yang ditahan. Deadlock 1205/1213 dipetakan 409 tetapi tidak direproduksi.
- Pembacaan yang sudah mendapat izin sebelum relasi dicabut dapat menyelesaikan permintaan yang sedang berjalan; permintaan berikutnya diperiksa ulang. Baris InApp yang sudah dibuat tetap ada di pusat notifikasi wali yang relasinya dicabut (generik, tujuannya 403).
- Safari fisik dan pembaca layar belum diuji. Browser memakai intersepsi aset CDN lokal seperti runner resmi.
- Paritas aplikasi (Fase 4) dan laporan/rilis (Fase 5) **belum** dikerjakan.

## Langkah rilis (untuk keputusan Human Developer kelak — bukan izin deploy)

1. Backup terverifikasi DB dan berkas; 024 dan 025 harus sudah terpasang (`bin/v5_phase2_verify.php` lulus sebelum 026).
2. `php bin/v5_phase3_preflight.php /jalur/aman/manifest-026.json`
3. `php bin/migrate.php up` (menerapkan 026).
4. `php bin/v5_phase3_verify.php /jalur/aman/manifest-026.json` — wajib tanpa `[gagal]`.
5. Worker Push yang sudah ada (cron V2) memproses notifikasi V5 tanpa konfigurasi baru.

Rollback hanya bila `php bin/v5_phase3_rollback_preflight.php` lulus (empat tabel 026 kosong dan tidak ada notifikasi V5 di outbox); lalu `php bin/migrate.php rollback` (melepas 026 saja) dan `php bin/v5_phase3_verify.php --rollback manifest`. Bila sudah ada ringkasan terbit, pulihkan backup atau buat migrasi korektif — jangan rollback. Sesudah rollback 026 (024–025 tetap), layanan ringkasan menjawab 503, menu "Kesehatan anak" disembunyikan, bagian ringkasan di detail kunjungan disembunyikan, dan layanan klinis Fase 1–2 tetap berjalan.

## Handoff ke auditor

> *"Audit implementasi Fase 3 PRD V5 yang dibuat Claude Code pada branch `claude/prd-v5-fase-3`. Baca AGENTS.md, PRD-V5.md, dan docs/phase-v5-3/README.md, periksa seluruh commit fase di atas `c1cb262`, jalankan pengujian secara independen, dan lakukan koreksi terarah jika diperlukan. Commit dan push hasil audit, lalu berhenti. Jangan melanjutkan ke Fase 4 sebelum seluruh kriteria penerimaan Fase 3 terpenuhi."*

Implementator berhenti di sini: tidak merge, tidak deploy, tidak migrasi produksi, tidak memulai Fase 4.
