# PRD V7 Fase 3 — Notifikasi, Laporan, dan Audit

**Status (27 Oktober 2026): implementasi selesai, menunggu audit independen Codex.** Belum merge, belum deploy, belum migrasi produksi, belum rilis aplikasi. Fase 4 belum dimulai. Fase 3 **bukan** izin rilis parsial V7; gerbang akhir/produksi V6 tetap terbuka.

| Butir | Nilai |
| --- | --- |
| Workstream | PRD V7 Fase 1–5 pengajuan izin orang tua melalui pembimbing — implementator Claude Code, auditor Codex (`AGENTS.md`, keputusan 27 Oktober 2026) |
| Branch | `claude/prd-v7-fase-3` |
| Baseline web | Commit audit Fase 2 `96dc8275857d78ebcdc0a432f51e3c2f9c4cae4d` (= `origin/claude/prd-v7-fase-2`). `origin/main` `1012e36c77bcb3642a3a7b1630eae8a3e0b52e6b` (merge PR #96) memuatnya; `git diff 96dc827 origin/main` kosong. Branch dibuat dari `96dc827`; tidak bekerja di `main`, tanpa worktree. |
| Migrasi | **Tidak ada.** Notifikasi memakai `notifikasi_outbox`/`notifikasi_percobaan` V2; laporan membaca tabel 006/030/031 yang sudah ada. |
| Mobile | Tidak diubah (paritas aplikasi = Fase 4). Aplikasi lama belum mengenal tautan `tipe: izin_wali` (lihat Batas). |
| Lingkungan uji | PHP 8.4.14, MariaDB 12.3.2 lokal (`innodb_snapshot_isolation=ON`), Node 26.7.0, Chromium Playwright; seluruh DB `test_*_test` dengan fixture sintetis. Klien Push dan penyedia WhatsApp **palsu** (menghitung permintaan; tanpa jaringan). Direktori belum terlacak milik pekerjaan lain (`docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/`) tidak disentuh. |

## Yang dikerjakan (persyaratan Fase 3)

1. **Konteks** — dibaca `AGENTS.md`, `PRD-V7.md`, prompt Fase 3, README dan `AUDIT-CODEX.md` Fase 1–2, serta infrastruktur V2: `NotificationService`, `NotificationEvent`, `NotificationRepository` (dedup `(event_key, kanal, penerima)`), `RecipientResolver`, `NotificationDispatcher` (worker Push, pemeriksaan ulang V3/V4/V5/V6), `NotificationCenterService`, `portal/notifikasi.php`, pola V6 `BsaNotifikasi`, laporan V2 `IzinReportRepository`.
2. **`App\Izin\IzinWaliNotifikasi`** (baru) — katalog sepuluh peristiwa dan penerimanya:

   | Peristiwa | Kapan | Penerima |
   | --- | --- | --- |
   | `izin.wali_masuk_pembimbing` | dibuat (tepat satu pembimbing sah), ditetapkan admin, dikirim ulang wali | akun pembimbing tujuan yang masih sah |
   | `izin.wali_perlu_penetapan_pembimbing` | dibuat/dikirim ulang tanpa tepat satu pembimbing sah | admin aktif |
   | `izin.wali_perlu_perbaikan` | pembimbing meminta perbaikan | **wali pengaju saja** |
   | `izin.wali_ditolak_pembimbing` | pembimbing menolak | wali pengaju |
   | `izin.wali_diteruskan` | pembimbing meneruskan | wali pengaju |
   | `izin.wali_masuk_murobi` | diteruskan ke murobi tunggal; penetapan murobi V2 | akun murobi berpenugasan aktif |
   | `izin.wali_perlu_penetapan_murobi` | diteruskan, murobi tidak tunggal | admin aktif |
   | `izin.wali_diputus` | keputusan murobi/admin pengganti (V2) | wali pengaju |
   | `izin.wali_keputusan_dikoreksi` | koreksi keputusan (V2) | wali pengaju |
   | `izin.wali_dibatalkan` | wali pengaju membatalkan | pemegang antrean saat itu: pembimbing (atau admin bila pembimbing tak sah), admin, atau murobi tujuan |

   - **Penerima dihitung ulang dari DB** (`penerima()`), dari keadaan baris **sesudah** peralihan; aktor tidak diberi tahu tentang tindakannya sendiri. Wali pengaju = akun aktif ber-role orang tua yang masih tertaut data wali pengaju yang aktif dan berelasi `santri_wali` aktif; **wali lain berelasi tidak diberi tahu**. Keadaan yang tidak sesuai peristiwa → nol penerima.
   - **Satu baris per penerima per kanal per peristiwa**: kunci `v7:izin:{id}:{peristiwa}:v{versi}` (versi baris sesudah peralihan) + kunci unik outbox V2. Replay idempotensi tidak menjalankan mutasi ulang, dan mengantre ulang peristiwa yang sama ditolak kunci unik.
   - **Kanal**: InApp selalu; Push hanya bila sakelar Push menyala **dan** penerima punya perangkat aktif; **WhatsApp tidak pernah diantrekan** (selain itu worker WhatsApp V2 tetap ditangguhkan).
   - **Isi aman untuk semua kanal**: judul/isi generik dengan nomor pengajuan saja — tanpa nama santri, alasan, catatan, tanggal, nomor telepon, atau token. `pengajuan_id` baris outbox sengaja **NULL** agar pusat notifikasi tidak menggabungkan nama santri ke daftar; penunjuk tunggal `data_json = {"tipe":"izin_wali","pengajuan_id":N}`.
   - **Di dalam transaksi mutasi, tetapi tidak membatalkannya**: antrean ditulis setelah mutasi dan audit, dibungkus `SAVEPOINT`. Galat apa pun → `ROLLBACK TO SAVEPOINT`, audit `izin_wali_notifikasi_gagal` (metadata) dan `error_log` (nama peristiwa + nomor saja); transaksi izin tetap commit. Pengecualian: deadlock (InnoDB sudah membatalkan transaksi) → 409. Setiap antrean berhasil dicatat audit `izin_wali_notifikasi_diantrekan` `{event, version, penerima, in_app, push}`.
   - **`pushMasihBerhak()`** dipakai worker tepat sebelum provider: kunci cocok dengan jenis, **versi kunci = versi baris** (peristiwa basi tidak dikirim), dan penerima masih ada di `penerima()` saat itu.
   - **`jejak()`** — metadata antrean/pengiriman (peristiwa, kanal, status, kode galat, penerima, waktu) untuk rincian admin.
3. **Kait peralihan tahap**
   - `IzinWaliService::buat/tetapkanPembimbing/tinjau/perbaiki/batalkan` mengantre peristiwa sesudah mutasi di transaksi yang sama.
   - `IzinWorkflowService::notify()` (V2) untuk baris **asal Wali**: penetapan murobi → `masuk_murobi`, keputusan → `diputus`, koreksi → `dikoreksi`. Notifikasi V2 lama (ke seluruh wali santri, tautan V2) tetap tidak dipancarkan untuk baris wali. Jalur pengurus V2 tidak berubah.
4. **Worker Push** (`NotificationDispatcher::kirimPush`) — cabang V7 seperti V5/V6: pemeriksaan ulang → teks dari katalog (isi baris tidak dipercaya) → data hanya penunjuk → sakelar dibaca ulang tepat sebelum request. Gagal hak/keadaan → `AKSES_BERUBAH` permanen, nol request.
5. **Pusat notifikasi** — `NotificationEvent::label()` mendelegasikan label V7; `portal/notifikasi.php` membuka `tipe: izin_wali` ke `portal/izin_wali_detail.php?id=` ("Buka pengajuan izin"), yang memeriksa ulang peran, penugasan, dan relasi (`404` bila sudah dicabut). Kategori "Perizinan" (`izin.%`) otomatis memuat peristiwa V7.
6. **Laporan lintas asal** — `App\Izin\IzinLaporanService` + `IzinLaporanRepository` (baru), web `portal/izin_wali_laporan.php` + `portal/izin_wali_laporan_ekspor.php`, API `GET /v7/izin/laporan` dan `/v7/izin/laporan/ekspor`:
   - **Satu pembangun WHERE** (`conditions()`) dan satu `FROM` untuk kartu, daftar berhalaman, dan ekspor; kamar/kelas/waktu tinjauan lewat subquery skalar; satu baris per pengajuan.
   - **Cakupan dari akun** (role aktif dibaca ulang dari DB; `peran` hanya memilih peran yang dimiliki): admin = seluruh; pengurus = pengajuan pengurus miliknya **+** pengajuan wali yang dialamatkan kepadanya sebagai pembimbing **sah** (`PembimbingRouter::sahSql`, santri aktif — aturan sama dengan rincian V7); murobi = `murobi_guru_id` miliknya dan status V2 (aturan rincian V7/V2); orang tua = anak berelasi aktif, baris pengurus berstatus V2 + seluruh baris wali, dengan baris wali yang bukan kirimannya diproyeksikan `pengaju: "Wali lain"` tanpa nama petugas/durasi. Cakupan tidak dikenal → `1 = 0`. Tanpa peran perizinan → `403`.
   - **Saringan**: asal (wali/pengurus), tahap, hasil (berjalan, disetujui, ditolak murobi/admin, ditolak pembimbing, dibatalkan), tanggal (rentang izin bersinggungan atau tanggal diajukan), santri, pembimbing, murobi, kamar, kelas, lama total (jam). Nilai tidak valid → `422` (web: peringatan + laporan tanpa saringan).
   - **Durasi**: tahap pembimbing (diajukan → teruskan/tolak, termasuk siklus perbaikan), tahap murobi (diteruskan atau diajukan → diputus), total (diajukan → diputus/ditolak pembimbing/dibatalkan); kartu rerata per tahap.
   - **Tanpa isi izin**: SQL tidak membaca alasan, catatan, alasan keputusan/pembatalan/penggantian. Ekspor CSV ≤ 5.000 baris, aman spreadsheet, diaudit `izin_v7_laporan_ekspor` `{peran, filter, jumlah}`.
   - Tautan: tombol "Laporan" di `portal/izin_wali.php` dan "Laporan asal & tahap" di laporan V2 (`portal/laporan.php`). Tanpa perubahan Navigation/peta menu.
7. **Audit** — mutasi Fase 1–2 sudah beraudit; Fase 3 menambah audit antrean/gagal notifikasi dan ekspor laporan (metadata). Rincian admin menampilkan **Jejak notifikasi**. Pengiriman Push tercatat V2 (`status`, `percobaan`, `error_kode`, `notifikasi_percobaan`).
8. **Konkurensi (perubahan terarah)** — baris outbox berkunci asing ke `users`; menyisipkannya mengambil kunci S pada akun penerima. Akun wali pengaju dikunci X oleh pembatalan wali (`kunciAkun`, desain F-3 Fase 1–2), sehingga balapan batal vs teruskan/keputusan kini dapat berakhir **deadlock**. Kontrol pertama: integrasi Fase 2 J-9 115/4 dengan `batal:500` (pesan `RuntimeException: Data perizinan tidak dapat dikunci…`), karena `errno` dibaca **sesudah** rollback (selalu 0) dan statement V2 tidak membawa kode galat. Perbaikan: `IzinWriteRepository` membawa kode galat MySQL pada exception (tanpa perubahan SQL), dan `transactional()` V7 serta V2 **khusus baris wali** membaca kode sebelum rollback dan memetakan 1020/1205/1213 → 409. Hasil: J-9 kembali 119/0; satu pemenang, sisanya 409, tanpa 500.
9. **Verifier** `bin/v7_phase3_verify.php` (hanya membaca; jalankan bersama `bin/v7_phase2_verify.php`): notifikasi V7 hanya InApp/Push; kunci cocok jenis/format; menunjuk baris asal Wali dengan versi ≤ versi baris; `pengajuan_id` NULL; data hanya penunjuk; judul/isi = katalog; nol notifikasi V2 lama untuk baris wali; satu baris per peristiwa/kanal/penerima; audit notifikasi hanya metadata.
10. **Kontrak API** — `docs/api-v1.md` bagian "V7 Fase 3".

## Kriteria penerimaan → bukti

| Kriteria Fase 3 | Bukti |
| --- | --- |
| Setiap peristiwa fixture → satu InApp per penerima berhak dan paling banyak satu Push sesuai sakelar; retry tidak menggandakan | `bukti/integrasi.txt` N-1…N-6, N-13 (klik ganda 8 proses → satu set); `bukti/http.txt` H-A, H-B (replay); `bukti/browser.txt` D-A, D-B; verifier Fase 3 |
| Push OFF/WhatsApp OFF → nol request provider; pengajuan dan keputusan tetap berhasil | `bukti/integrasi.txt` N-1, N-7 (sakelar dimatikan sesudah antre → worker berhenti sebelum klaim), N-8 (OFF; WhatsApp ON pun nol antrean V7), N-9 (antrean rusak → mutasi tetap commit) |
| Setelah relasi wali/penugasan dicabut, sesi/token lama dan tautan notifikasi tidak membuka pengajuan di luar haknya | `bukti/integrasi.txt` N-7 (Push tidak dikirim), N-10, N-11 (laporan); `bukti/http.txt` H-A (web sesi lama + API token lama → 404), H-B |
| Laporan menghitung pengajuan wali dan pengurus tepat sekali; kartu, daftar, ekspor, dan rincian yang terlihat per peran konsisten | `bukti/integrasi.txt` N-11: sepuluh peran (admin, dua pembimbing, pengurus V2, dua murobi, wali pengaju, wali kedua, wali asing, wali relasi dicabut) — kartu = semua halaman daftar = ekspor, tanpa ganda, tahap/hasil/asal berjumlah sama, **himpunan ID laporan = himpunan ID yang rinciannya dapat dibuka** (V7 untuk baris wali, V2 untuk baris pengurus), murobi dan admin sama dengan SQL independen; sepuluh saringan = SQL independen; `bukti/http.txt` H-C/H-D (API total = CSV = web `data-total` = ekspor web); `bukti/browser.txt` D-C |
| Perubahan fixture punya pelaku, waktu, alasan bila wajib, versi; payload push/log umum nol alasan, nama santri, nomor telepon, token mentah | `bukti/integrasi.txt` N-12 (audit mutasi = versi untuk setiap pengajuan wali; tinjauan/perbaikan/penetapan berversi dan beralasan; pemindaian outbox, payload Push, audit V7, catatan percobaan); `bukti/http.txt` H-E (log server); verifier Fase 2 dan 3 |

## Hasil pengujian Fase 3 (lokal, kode final)

| Rangkaian | Hasil | Berkas |
| --- | --- | --- |
| Statis `tests/v7_phase3_static.php` (katalog, SAVEPOINT/409, kait, worker, laporan satu WHERE, batas berkas terhadap `96dc827`, lint) | 44/0 | `bukti/statis.txt` |
| Integrasi layanan + worker palsu + konkurensi proses terpisah + laporan per peran + audit/privasi `tests/v7_phase3_integration.php` (N-1…N-14) | 112/0 | `bukti/integrasi.txt` |
| HTTP web + API lewat server PHP lokal `tests/v7_phase3_http.php` (H-A…H-E) | 43/0 | `bukti/http.txt` |
| Browser 375/768/1280 + tanpa JavaScript `tests/browser/uji-v7-fase3.mjs` (D-A…D-F; log server 0 galat PHP) | 53/0 | `bukti/browser.txt`, `tangkapan/*.png` (diperiksa visual) |
| Verifier Fase 2 dan Fase 3 pada DB browser sesudah uji | lulus · lulus | `bukti/verifier-fase*-sesudah-browser.txt` |

Uji mutasi statis (dijalankan manual, dipulihkan): `pengajuan_id` outbox diisi → 1 gagal; kait notifikasi pembatalan dihapus → 1 gagal; cakupan laporan tak dikenal `1 = 1` → 1 gagal.

Kontrol yang tercatat selama implementasi (bukan bukti akhir):

- **Deadlock → 500** (lihat butir 8) ditemukan oleh regresi integrasi Fase 2 J-9 pada kode Fase 3 awal, direproduksi dengan skrip sementara (3 putaran × 8 proses: `batal:500` 24 kali), lalu diperbaiki; reproduksi sesudah perbaikan: `batal:409` 24, tanpa 500.
- **Harapan uji N-6 awal keliru** — mengharapkan tiga Push terkirim padahal pembimbing sudah meneruskan sebelum worker berjalan; Push `masuk_pembimbing` memang **dibuang** sebagai basi (perilaku yang diinginkan). Uji kini menjalankan worker sebelum dan sesudah penerusan. Pemindaian payload awal ikut membaca medan `to` (token tujuan yang wajib bagi provider); kini judul/isi/data yang dipindai, token tetap dipindai di outbox, audit, dan log.
- **Cabang balapan** — putaran serentak tanpa penundaan selalu dimenangkan "teruskan"; N-13 kini memberi penundaan bergiliran 250 ms dan mewajibkan kedua cabang teramati.

Cara mengulang (berurutan; rantai fixture memakai DB perantara bernama tetap):

```bash
bash docs/phase-v7-3/perintah-uji.sh
```

```bash
bash docs/phase-v7-3/perintah-regresi.sh
```

## Regresi

Dijalankan berurutan lewat `perintah-regresi.sh` (ringkasan `bukti/regresi/ringkasan.txt`, keluaran per suite di `bukti/regresi/`). Kode Fase 3 final; DB dibangun dengan rantai `tests/v7_phase1_db.sh`/`v7_phase2_db.sh` dari pohon kerja ini (001–031, tanpa migrasi baru). Verifier Fase 2 dan Fase 3 dijalankan sesudah setiap DB dipakai suite V2 (enam DB: 41/0 dan 8/0 masing-masing).

| Rangkaian | Hasil | Catatan |
| --- | --- | --- |
| V7 Fase 2 integrasi / HTTP / browser 375/768/1280 | 119/0 · 61/0 · 33/0 | Termasuk balapan J-9 batal vs teruskan/keputusan (sesudah perbaikan deadlock → 409, butir 8). |
| V7 Fase 2 probe audit Codex (`tests/v7_phase2_audit.php`, F-1…F-3) / migrasi 031 | 34/0 · 27/0 | Koreksi auditor Fase 2 tetap berlaku. |
| V7 Fase 1 probe audit Codex / HTTP / browser | 13/0 · 71/0 · 39/0 | |
| V7 Fase 1 integrasi | 73/2 | Oracle historis, **identik dengan kontrol sumber baseline `96dc827`** (`bukti/regresi/v7-fase1-integrasi-kontrol-baseline-96dc827.txt`, 73/2): I-3 menuntut wali belum punya tindakan Fase 2, dan pemeriksaan jalur V2 tanpa `version` kini dijawab 422 (versi wajib, koreksi audit Fase 2 F-2) sebelum 409 status. Bukti audit Fase 2 mencatat 74/1; kontrol ulang pada sumber yang sama menunjukkan 73/2, jadi bukan dampak Fase 3. |
| Fondasi penugasan | 161/0 | |
| V2 Fase 1 / 2 / 4 / 5 integrasi | 39/0 · 94/0 · 124/0 · 145/0 | Jalur pengurus V2 dan notifikasi V2 tetap. |
| V2 Fase 4 konkurensi | 20/0 | |
| V2 web smoke Fase 2 / 4 / 5 | 36/0 · 46/0 · 79/0 | Termasuk pusat notifikasi web. |
| V2 kontrak API Fase 5 | 150/0 | |
| V2 kontrak API Fase 3 / 4 | 95/21 · 91/1 | Identik dengan regresi Fase 1–2 dan kontrol baseline `3e78564` (fixture rantai memberi santri A1 lebih dari satu kandidat). |
| Worker Push V5 Fase 3 integrasi / V6 Fase 4 HTTP | 82/0 · 63/0 | `NotificationDispatcher` berubah; cabang V5/V6 tetap. |
| V6 Fase 5 integrasi / HTTP | 71/0 · 42/0 | |
| HTTP redesain Tahap 4 (perizinan, cetak) / 5 / 7 | 132/0 · 106/0 · 122/0 | |
| HTTP Tahap 2 / Tahap 3 | 185/6 · 166/1 | Identik dengan Fase 1–2 (oracle menu BB–BE dan "AG belum dipasang"). |
| Browser kerangka sembilan akun × 375/768/1280 | 91/0 | |
| Seluruh 42 suite statis | 18 exit 0 · 22 exit 1 · 2 exit 77 | `v7_phase3_static` 44/0. Rincian di bawah. |

### Oracle historis statis (tidak diubah, tidak dilonggarkan)

Dibanding regresi Fase 2 (`docs/phase-v7-2/bukti/regresi/ringkasan.txt`), hanya tiga suite berubah hitungan:

| Suite | V7 F2 → V7 F3 | Sebab |
| --- | --- | --- |
| `v7_phase1_static` | 46/3 → 44/5 | Tambahan: "Fase 1 tidak mengirim notifikasi (Fase 3)" — kini Fase 3 memang mengirim; "notifikasi tidak berubah" — `app/Notification` diubah Fase 3 (cabang worker dan label). |
| `v7_phase2_static` | 54/0 → 52/2 | "Hanya berkas Fase 2 yang berubah dari baseline `192711e`" dan "notifikasi tidak berubah" — berkas Fase 3. Seluruh pemeriksaan perilaku Fase 2 tetap lulus. |
| `v2_phase5_static` | 326/3 → 328/3 | Tiga kegagalan sama (versi paket checkout mobile milik pekerjaan lain); dua pemeriksaan tambahan kini lulus. |

Suite statis lain memiliki hitungan **identik** dengan Fase 2; `v3_phase2/3_static` exit 77 (memerlukan DB V3 bernama tetap).

**Tidak dijalankan ulang** (dicatat, bukan disembunyikan): migrasi Fase 1 dan drill warisan (Fase 3 tanpa migrasi; tetap oracle "030 terakhir" seperti Fase 2), rollback 031…024 + HTTP Tahap 6, alat rilis V6, keamanan PSB HTTP/browser (Apache), suite DB V3/V4/V5 lain, V6 Fase 1–3, browser V3–V6, uji Android/iOS. Kode PSB, V3–V6, `app/Auth`, `app/Penugasan`, `app/Ui`, admin, dan basis data tidak berubah dari baseline (statis T-6).

## Penafsiran yang perlu ditinjau auditor/Human Developer

- **R-1 Pembatalan memberi tahu pemegang antrean.** PRD §5.3 meminta pembimbing/murobi terdampak "diberi status terkini"; Fase 3 mengirim `izin.wali_dibatalkan` kepada pembimbing (atau admin bila pembimbing tak sah) saat menunggu pembimbing/perbaikan, admin saat perlu penetapan, dan murobi tujuan sesudah diteruskan. Wali pengaju (aktor) tidak diberi tahu.
- **R-2 Penetapan pembimbing dan perbaikan wali dihitung "pengajuan masuk".** Pembimbing yang baru ditetapkan admin dan pembimbing yang menerima kiriman ulang diberi tahu (`masuk_pembimbing`, versi baru); bila pembimbing tak lagi sah saat kiriman ulang, admin yang diberi tahu (P-4 Fase 2).
- **R-3 Penetapan murobi oleh admin** memberi tahu murobi yang ditetapkan (`masuk_murobi`), setara "murobi saat diteruskan". Koreksi keputusan memberi tahu wali pengaju (`dikoreksi`) karena hasil yang diterimanya berubah.
- **R-4 InApp juga generik.** Berbeda dengan V2 (InApp memuat nama santri dan tanggal), seluruh kanal V7 hanya memuat nomor pengajuan; nama santri tampil setelah rincian dibuka dan hak diperiksa ulang. `pengajuan_id` outbox NULL sehingga daftar notifikasi lama tetap tanpa nama santri sesudah hak dicabut.
- **R-5 Push basi dibuang.** Push ditahan hanya bila versi baris masih sama dengan versi peristiwa; bila tahap sudah berpindah sebelum worker berjalan, Push lama ditandai `AKSES_BERUBAH` (InApp tetap ada di pusat notifikasi) dan peristiwa terbaru dikirim sendiri.
- **R-6 Kegagalan antrean tidak membatalkan transaksi izin** (PRD Fase 3 persyaratan 5), berbeda dengan pola V5/V6 yang membatalkan mutasi (503). Konsekuensinya peristiwa dapat tanpa notifikasi bila outbox rusak; kejadian itu tercatat di audit `izin_wali_notifikasi_gagal` dan error log tanpa isi, dan status tetap terbaca di antrean/rincian. Tidak ada antrean ulang otomatis untuk peristiwa yang gagal diantrekan.
- **R-7 Deadlock baris wali → 409** (butir 8). Kiriman yang kalah balapan menerima 409 "sedang diproses" dan kiriman ulang memperoleh status terkini; hasil efektif tetap satu.
- **R-8 Cakupan laporan pengurus** menggabungkan pengajuan pengurus miliknya (cakupan V2) dan pengajuan wali yang dialamatkan kepadanya sebagai pembimbing sah (cakupan rincian V7); pengajuan wali yang pernah ditinjaunya tetapi penugasannya sudah tidak sah tidak lagi tampil, konsisten dengan rincian (`404`). Admin melihat keduanya tanpa batas.
- **R-9 Laporan tanpa alasan izin** untuk semua peran, termasuk admin (isi dibaca di rincian sesuai hak). Laporan V2 lama (dengan alasan) tidak diubah dan tetap hanya status V2.
- **R-10 Lama total** dihitung sampai keputusan, penolakan pembimbing, atau pembatalan; baris V2/warisan tanpa `izin_keputusan.diputus_pada` tidak memiliki lama murobi/total.

## Temuan dan catatan untuk Human Developer

- **T-1 (dari Fase 1, jalur pengurus V2, tidak diubah)** — retry bersamaan berkunci sama pada `IzinWorkflowService::create()` pengurus masih dapat 500. Pemetaan deadlock → 409 Fase 3 hanya untuk baris asal wali.
- **T-2 (nonblokir, tampilan Fase 2)** — di rincian V7, nama murobi dan catatan routing murobi tampil bersambung tanpa pemisah ("SBX Murobi ARouting otomatis…") karena `ah-cell-sub` di dalam `<dd>` tidak tampil sebagai blok. Tidak diubah (di luar cakupan Fase 3).
- Server PHP yatim pada port 8998 (dimulai 10.39, dari sesi lain) tidak disentuh; suite Fase 3 memakai port lain.

## Batas yang belum diuji / terbuka

- Tidak ada uji MySQL 8, cPanel/hosting, atau produksi (lokal hanya MariaDB 12.3.2).
- **Push nyata, perangkat fisik, ketukan notifikasi, dan cold start tidak diuji.** Worker diuji dengan klien Push palsu; "terkirim" berarti diterima klien palsu, bukan sampai perangkat. Aplikasi mobile yang ada belum menangani `tipe: izin_wali` (Fase 4); sampai itu ketukan Push V7 di aplikasi lama tidak membuka layar khusus.
- Konkurensi dibuktikan pada interleaving yang benar-benar dieksekusi (proses + koneksi DB terpisah, 8 proses klik ganda, 4 putaran 4+4 batal vs teruskan dengan penundaan bergiliran); bukan bukti semua kemungkinan interleaving. Server PHP bawaan melayani HTTP berurutan.
- Pemindaian privasi memeriksa penanda alasan, nama santri, nomor telepon fixture, dan token yang diuji pada outbox, payload Push palsu, audit V7, catatan percobaan, laporan, dan log server; bukan klaim seluruh kemungkinan data sensitif telah dipindai.
- Laporan dibatasi 5.000 baris per ekspor; tidak diuji pada volume produksi.
- **Risiko urutan rilis.** Bila branch ini digabung dan dideploy sebelum Fase 4–5, notifikasi V7 aktif di web sementara aplikasi belum menangani tautannya. Keputusan urutan rilis milik Human Developer.

## Langkah rilis dan pemulihan (untuk keputusan Human Developer kelak — bukan izin deploy)

Fase 3 tidak menambah migrasi; prasyarat 030 dan 031 tetap (langkah Fase 1–2). Sesudah deploy kode: `php bin/v7_phase2_verify.php` lalu `php bin/v7_phase3_verify.php` — keduanya wajib lulus. Pemulihan kode cukup mengembalikan berkas Fase 3; baris outbox V7 yang sudah dibuat tetap valid (InApp terbaca di pusat notifikasi, Push yang tertunda diperiksa ulang worker sebelum kirim).

## Handoff

Implementasi Fase 3 selesai pada branch `claude/prd-v7-fase-3` di atas commit audit Fase 2 `96dc8275857d78ebcdc0a432f51e3c2f9c4cae4d`; commit implementasi `c7569ed1329d43f928f7756fe63e01d100b08d2a`; commit sesudahnya hanya mencatat hash ini. Implementator berhenti untuk **audit independen Codex** sesuai prompt audit Fase 3 di `docs/prd-v7/prompts-implementasi-audit.md`. Tidak merge, deploy, migrasi produksi, rilis aplikasi, atau memulai Fase 4.
