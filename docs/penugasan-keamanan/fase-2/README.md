# Penugasan lintas guru/pengurus — Fase 2: pemisahan tugas per akun

**Status (30 Oktober 2026): diimplementasikan Claude Code, menunggu audit independen Codex.** Belum merge, belum deploy, tanpa migrasi. Fase 3 belum dimulai.

| Butir | Nilai |
| --- | --- |
| Workstream | Penugasan lintas guru/pengurus dan Keamanan Fase 1–5 — implementator Claude Code, auditor Codex (`AGENTS.md`, keputusan 30 Oktober 2026) |
| Rancangan | [`../rancangan.md`](../rancangan.md) §3.2 (pemisahan tugas), PK-T1, PK-T5…PK-T7 |
| Branch | `claude/penugasan-keamanan-fase-2` |
| Baseline | `464d9a1` (audit Codex Fase 1 = `origin/claude/penugasan-keamanan-fase-1`). `origin/main` `2e4f5b5` (PR #103) memuatnya; `git diff 464d9a1 origin/main` kosong. Tidak bekerja di `main`, tanpa worktree. |
| Mobile | Tidak diubah (paritas aplikasi = Fase 4). Kunci `aksi` REST izin tetap lima, sehingga tipe `IzinActions` aplikasi tidak berubah. |
| Migrasi | Tidak ada. Seluruh aturan memakai kolom yang sudah ada (`users.guru_id`, `diajukan_oleh_user_id`, `izin_pembimbing_tinjauan`, `v4_sikap(_riwayat)`, `v4_nilai(_riwayat)`, `created_by` V3). |
| Lingkungan uji | PHP 8.4, MariaDB 12.3 lokal, Chromium Playwright; DB `test_*_test` dari rantai fixture Fase 1 (`tests/penugasan_keamanan_f2_db.sh`). Produksi MariaDB 10.6.28 belum diuji. Direktori belum terlacak milik pekerjaan lain (`docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/`, tangkapan browser Fase 1) tidak disentuh. |

## Keputusan Human Developer saat implementasi (30 Oktober 2026)

Tiga hal tidak ditetapkan rancangan dan ditanyakan sebelum kode ditulis:

| Kode | Keputusan |
| --- | --- |
| **PK-T5** | PK-T1 berlaku **per bahan nilai**: Bagian Pendidikan tidak memverifikasi/mengembalikan nilai mapel yang ia isi sendiri. Mapel lain, sikap, kehadiran, finalisasi, dan penerbitan tetap boleh setelah nilainya diverifikasi pihak lain. |
| **PK-T6** | "Pengaju bukan pemutus" **berlaku juga untuk admin pengganti**. Pengajuan buatan admin diputus murobi atau admin lain. |
| **PK-T7** | Bila satu-satunya murobi sah juga pengisi sikap: **tertahan, tanpa jalur baru**. Murobi lain yang sah yang mengetahui; bila tidak ada, rapor menampilkan penghalang sampai admin mengubah penugasan. |

## Yang dikerjakan

Prinsipnya satu: **akun yang mengisi, mengajukan, atau meninjau tidak memutus, mengetahui, atau memverifikasi pekerjaannya sendiri.** Pemeriksaan dilakukan di server, sesudah baris yang relevan dikunci, sehingga berlaku untuk web, REST, sesi lama, dan kiriman langsung. Tampilan hanya mencerminkannya.

### 1. Izin V2 dan V7 (`App\Izin\PemisahanTugas`, baru)

- **Akun terlibat** = akun pengaju (`diajukan_oleh_user_id`) ditambah setiap akun pembimbing yang pernah meninjau pengajuan wali (`izin_pembimbing_tinjauan.ditinjau_oleh_user_id`).
- **Guru terlibat** = `users.guru_id` milik akun-akun itu. Satu guru paling banyak satu akun (`users_guru_unique`), sehingga identitas pengurus milik guru (Fase 1) maupun akun ganda warisan ikut tertangkap.
- **Routing murobi** (`IzinRouter::resolve(..., $kecualiGuru)`): guru terlibat dikeluarkan dari kandidat *sebelum* dihitung.
  - Satu kandidat tersisa → langsung diarahkan.
  - Nol atau lebih dari satu → `Perlu Penetapan Admin`.
  - Catatan routing dan audit menyebut jumlah yang dikecualikan.
  - Berlaku pada pembuatan pengajuan V2 (`create`) dan penerusan pembimbing V7 (`tinjau` → teruskan). Pratinjau tujuan di formulir pengajuan memakai pengecualian yang sama.
- **Penetapan admin** (`assignMurobi`): guru terlibat ditolak 422 sesudah baris dikunci. Daftar murobi berhak dan kandidat yang ditampilkan admin tidak memuatnya.
- **Keputusan** (`decide`, kapasitas Murobi maupun Admin Pengganti) dan **koreksi** (`correctDecision`) oleh akun terlibat → 403, sesudah baris dikunci dan sebelum baris keputusan/koreksi ditulis. Ini berlaku walau pengajuan dipaksa diarahkan ke orang itu, juga lewat REST.
- **Tampilan**: `actionsFor(..., $userId)` menyembunyikan Putuskan/Koreksi bagi akun terlibat. Kuncinya tetap lima, tanpa kunci baru. Rincian izin menampilkan penjelasan singkat. Rincian V7 menyembunyikan `putuskan`/`putuskan_pengganti` bagi akun terlibat.

### 2. Sikap V4 (PK-T7)

- **Pengisi** = `v4_sikap.pembimbing_user_id` ditambah setiap pelaku riwayat berkapasitas `pembimbing`.
- Dalam mode murobi, pengisi ditolak 403 untuk **Tandai diketahui** dan **Koreksi**, karena koreksi murobi otomatis berarti mengetahui. Pemeriksaan dilakukan sesudah baris sikap dikunci.
- Matriks Pendidikan (`VerifikasiService`) hanya mengakui tanda "diketahui" dari **murobi sah yang bukan pengisi** (`SikapService::murobiSah`). Bila seluruh murobi aktif adalah pengisi, bahan sikap tertahan dengan penghalang khusus. Tidak ada jalur pengganti.
- **Tampilan**:
  - Panel sikap murobi menjadi hanya-baca dengan penjelasan.
  - Baris kisi tanpa centang, berlabel "Anda pengisi sikap ini".
  - Saringan dan angka Beranda "perlu diketahui" tidak menghitung sikap isian sendiri.

### 3. PK-T1 / PK-T5 — verifikasi nilai

- **Pengisi nilai** = pelaku baris `v4_nilai` bahan itu ditambah pelaku `v4_nilai_riwayat`-nya, baik sebagai Guru Mapel maupun Pendidikan pengganti.
- Dalam kapasitas Pendidikan, `verifikasi` dan `kembalikan` atas bahan **nilai** yang memuat isian akun itu ditolak 403, sesudah rencana dikunci (penyimpanan nilai mengunci rencana yang sama). Kapasitas dihitung ulang di dalam transaksi.
- Admin tetap jalur pengganti. Bahan lain dan `periksa_finalisasi` tidak terdampak.
- Penanda `diisi_sendiri` dikembalikan **di luar** matriks, sehingga sidik jari matriks/pratinjau rapor tidak bergantung pada akun pembaca.
- **Tampilan**: panel bahan menjelaskan, tanpa tombol Verifikasi/Kembalikan; "Verifikasi semua yang diserahkan" tidak memuat bahan itu.

### 4. Pembinaan V3 — tanda "mengetahui" murobi (`App\V3\PemisahanTugas`, baru)

- Murobi tidak menandai mengetahui **pelanggaran, kasus, atau sesi** yang ia buat/miliki sendiri sebagai pembimbing: 403, sesudah baris sumber dikunci.
- **Milik** = pembuat/pembaru baris, seluruh rantai revisi pelanggaran, revisi kasus, atau akun yang memegang identitas `pembimbing_id` sumber. Sesi juga milik pemilik kasusnya.
- **Tampilan**: tombol "Tandai mengetahui" diganti penjelasan.

### Berkas

| Lapisan | Berkas |
| --- | --- |
| Layanan izin | `app/Izin/PemisahanTugas.php` (baru), `IzinRouter.php`, `IzinWorkflowService.php`, `IzinWaliService.php`, `app/Api/IzinApiService.php` |
| Rapor V4 | `app/V4/SikapService.php`, `app/V4/VerifikasiService.php`, `app/Beranda/BerandaService.php` (angka murobi) |
| Pembinaan V3 | `app/V3/PemisahanTugas.php` (baru), `PelanggaranService.php`, `KonselingService.php`, `PelanggaranRepository.php` / `KonselingRepository.php` (pengakses) |
| Halaman | `portal/izin_detail.php`, `izin_antrean.php`, `sikap_semester.php`, `verifikasi_semester.php`, `v3_pelanggaran_detail.php`, `v3_konseling_detail.php` |
| Tidak diubah | `app/Auth`, `app/Penugasan`, akun/master, kesehatan, BSA, notifikasi, rute `api/v1/index.php`, migrasi, mobile |

## Penafsiran (menunggu tinjauan auditor)

- **PF2-1** Level akun memakai `users.guru_id`. Dua akun **terpisah** milik satu orang (data ganda guru/pengurus yang tidak dihubungkan; Fase 1 memutuskan "peringatan saja") tidak dapat dikenali. Penggabungan data tetap tahap tersendiri.
- **PF2-2** Kandidat terlibat dibuang **sebelum** dihitung. Dua murobi (X dan Y) dengan X terlibat → langsung ke Y, tanpa melalui admin. Ini sesuai kata "mengecualikan" pada rancangan; satu-satunya murobi terlibat → admin.
- **PF2-3** Peninjau V7 = **setiap** pembimbing yang mencatat tinjauan, termasuk "minta perbaikan", bukan hanya yang meneruskan. Wali tidak pernah memegang `guru_id` (akun guru tidak dapat dihubungkan ke wali), tetapi akunnya tetap dihitung.
- **PF2-4** Koreksi keputusan oleh admin termasuk "pemutus" (PK-T6). Admin yang mengajukan tetap boleh **menetapkan murobi** dan membatalkan pengajuannya sendiri. Keduanya bukan keputusan.
- **PF2-5** Penolakan POST web memakai halaman "Aksi ditolak" 403 yang sudah ada untuk seluruh penolakan hak perizinan, bukan pengalihan.
- **PF2-6** Tanda mengetahui V3 tidak tercantum eksplisit di rancangan §3.2. Ditambahkan karena prinsipnya sama dengan sikap (PK-T7) dan tidak menambah jalur baru. Tanda mengetahui V3 bersifat informatif (tidak menghalangi rapor), sehingga catatan yang hanya diketahui pemiliknya tetap tanpa tanda. **Mohon ditinjau auditor/Human Developer**; bila tidak dikehendaki, cukup mencabut pemeriksaan di dua layanan V3.
- **PF2-7** Pengisi sikap mencakup pengisi pertama **dan** setiap revisi pembimbing. Akun yang pernah mengisi tidak mengetahui walau isi terakhir direvisi pembimbing lain (aturan sederhana dan dapat diaudit). Tanda "diketahui" lama oleh pengisi tidak dihitung sah pada matriks (dihitung langsung). Baris tersimpan tidak diubah.
- **PF2-8** Status verifikasi nilai yang **sudah tersimpan** sebelum Fase 2 tidak dievaluasi ulang. Sejak Fase 1 belum dirilis, kasus ini hanya mungkin pada akun ganda warisan.
- **PF2-9** Catatan routing (`VARCHAR(255)`) dipotong pada daftar nama kandidat agar akhiran pemisahan tugas selalu tercatat. Teks lama yang lebih dari 255 karakter sebelumnya akan gagal disimpan pada mode ketat.

## Hasil pengujian (kode final, lokal)

| Rangkaian | Hasil | Berkas |
| --- | --- | --- |
| Statis `tests/penugasan_keamanan_f2_static.php`: urutan kunci → periksa → tulis, lima kunci aksi, pengisi/pemilik, batas berkas terhadap baseline, lint | 56/0 | `bukti/statis.txt` |
| Integrasi `tests/penugasan_keamanan_f2_integration.php` J-1…J-9 (routing V2/V7, penetapan, keputusan dipaksa, admin pengganti/koreksi, sikap, PK-T1, V3, kontrol akun tak terlibat) | 53/0 | `bukti/integrasi.txt` |
| HTTP web + REST `tests/penugasan_keamanan_f2_http.php` W-1…W-7 (skenario `penugasan_keamanan_f2_seed.php`) | 28/0 | `bukti/http.txt` |
| Browser 375/768/1280 + tanpa JavaScript `tests/browser/uji-penugasan-keamanan-f2.mjs`; log server 0 galat PHP; empat tangkapan diperiksa visual, tidak di-commit | 41/0 | `bukti/browser.txt` |

Perintah:

```bash
php tests/penugasan_keamanan_f2_static.php
bash tests/penugasan_keamanan_f2_db.sh && php tests/penugasan_keamanan_f2_integration.php
PKF2_DB=test_pk_f2_http_test bash tests/penugasan_keamanan_f2_db.sh && PKF2_DB=test_pk_f2_http_test php tests/penugasan_keamanan_f2_http.php
PKF2_DB=test_pk_f2_browser_test bash tests/penugasan_keamanan_f2_db.sh
PKF2_DB=test_pk_f2_browser_test php tests/penugasan_keamanan_f2_seed.php > /tmp/pkf2-ids.json
DB_NAME=test_pk_f2_browser_test php -S 127.0.0.1:8979 tests/redesain_web_tahap1_router.php &
(cd tests/browser && BASE_URL=http://127.0.0.1:8979 SEED_JSON=/tmp/pkf2-ids.json node uji-penugasan-keamanan-f2.mjs)
bash docs/penugasan-keamanan/fase-2/perintah-regresi.sh
```

### Kontrol pada kode baseline `464d9a1`

Uji baru dijalankan pada `git archive 464d9a1` (dengan `.env` dan `vendor/` lokal) untuk membuktikan bahwa uji mendeteksi ketiadaan fitur:

- Integrasi **3/5**, lalu berhenti tak tertangkap pada J-1f. Pengajuan X diarahkan ke X sendiri; tidak ada pengecualian.
- HTTP **11/17**.
- Statis **6/27**. T-6 juga gagal karena arsip bukan repositori Git.

Berkas: `bukti/kontrol-baseline/f2_*`.

## Regresi

Skrip `perintah-regresi.sh` dijalankan dua kali dengan suite, urutan, dan fixture yang sama:

- **kode kini**: `bukti/regresi/`;
- **klon Git baseline `464d9a1`**: `bukti/kontrol-baseline/regresi/`. Klon memakai `git clone` + `checkout`, bukan worktree, dengan `.env` dan symlink `vendor/` lokal.

Pembinaan V3 memakai DB tetap `webalhasan_v3_phase1_test` (migrasi 019). DB itu di-dump lalu dipulihkan sesudah setiap suite, sehingga kedua putaran mulai dari isi yang sama.

**Seluruh 37 suite non-statis menghasilkan exit dan hitungan identik dengan baseline.**

| Kelompok | Kini = baseline |
| --- | --- |
| Penugasan Fase 1: integrasi / probe audit / probe token / HTTP | 70/0 · 20/0 · 10/0 · 34/0 |
| V7 Fase 5: E2E / HTTP / probe cakupan / probe pencabutan | 82/1 · 40/0 · 6/0 · 24/0 |
| V7 Fase 4 HTTP; Fase 3 integrasi / notifikasi / HTTP | 54/0; 112/0 · 49/0 · 43/0 |
| V7 Fase 2 integrasi / HTTP / probe audit; Fase 1 integrasi / HTTP | 119/0 · 61/0 · 34/0; 73/2 · 71/0 |
| Fondasi penugasan; V2 integrasi Fase 1/2/4/5; konkurensi | 161/0; 39/0 · 94/0 · 124/0 · 145/0; 20/0 |
| V2 API Fase 3/4/5; web Fase 2/4/5 | 95/21 · 91/1 · 150/0; 36/0 · 46/0 · 79/0 |
| V3 integrasi Fase 2/3/4/5; akses Fase 5 | 67/0 · 121/0 · 65/0 · 43/0; 5/0 |
| HTTP redesain Tahap 2 / 4 (perizinan, cetak) / 5 (pembinaan) / 7 (sikap, verifikasi, rapor) | 185/6 · 132/0 · 106/0 · 122/0 |

### Kegagalan yang identik dengan baseline (oracle historis)

Tidak dilonggarkan:

- **V7 Fase 5 E2E 82/1**: `bin/v7_preflight.php` menolak migrasi sesudah 031, yaitu 032 dari Fase 1.
- **V7 Fase 1 73/2**: dua perilaku V7 Fase 2 yang sah, sama dengan catatan audit V7 Fase 3.
- **V2 API Fase 3/4 95/21 · 91/1**: sama dengan kontrol audit V7 sejak `3e78564`.
- **Tahap 2 HTTP 185/6**: oracle peta menu lama BB–BE.

### Statis (47 suite)

Perbedaan hitungan terhadap baseline seluruhnya dijelaskan berikut. Oracle tidak diubah.

1. **Berubah karena Fase 2 sengaja mengubah berkas yang dikunci oracle lama:**
   - Tahap 7 98/4 → **96/6** ("Tidak berubah: `SikapService`, `VerifikasiService`", keputusan AU Tahap 7);
   - Tahap 8 77/3 → **76/4** ("T8-F `app/V4` tidak berubah");
   - V7 Fase 2 51/3 → **50/4** (pola teks pemanggilan `murobiRouter()->resolve($santriId, $onDate)`, kini dengan argumen pengecualian);
   - Penugasan Fase 1 → **78/2** (batas berkas Fase 1 dan "modul izin/V3/V4/portal tidak diubah").
2. **Berbeda karena lingkungan klon, bukan kode:**
   - Klon di folder sementara tidak memiliki repo mobile tetangga, sehingga `phase5_static` dan V2 Fase 3/4/5 berbeda.
   - Di pohon kerja, V2 Fase 5 328/3 dan V2 Fase 4 287/1 gagal pada versi paket mobile dan deep link. Penyebabnya perubahan `package.json` milik pekerjaan lain yang belum di-commit di repo mobile; Fase 2 tidak menyentuh mobile.
   - Tahap 6 baseline 139/2 vs kini 140/1 dan statis Fase 1 baseline 56/1 berbeda karena symlink `vendor/` di klon.
3. **Selebihnya identik.** Suite baru `penugasan_keamanan_f2_static` 56/0. Dua suite V3 keluar 77 (dilewati), sama dengan baseline.

### Kesalahan penyiapan yang diulang

Putaran pertama menjalankan probe token Fase 1 pada DB baru. Probe itu mensyaratkan keadaan sesudah probe audit pada DB yang sama, sehingga berhenti dengan "Jalankan audit_probe lebih dahulu" (`bukti/regresi/diagnostik-probe-token-urutan-salah.txt`). Skrip diperbaiki, lalu probe diulang sesudah probe audit pada DB baru: **10/0**. Putaran baseline memakai skrip yang sudah diperbaiki: 10/0.

## Batas

- **MariaDB 10.6.28 (produksi), MySQL/cPanel, dan UAT belum diuji.** Fase 2 tanpa migrasi.
- **Mobile tidak diubah.** Aplikasi memakai `aksi` dari server untuk izin, sehingga tombol keputusan ikut tersembunyi. Layar aplikasi lain yang memanggil sikap/verifikasi/V3 akan menerima 403 dengan pesan pemisahan tugas tanpa penjelasan khusus. Penyesuaian tampilan aplikasi = Fase 4.
- **Tanpa uji konkurensi baru.** Pemeriksaan hanya membaca sesudah kunci baris yang sudah ada: pengajuan `FOR UPDATE`, rencana + sikap `FOR UPDATE`, sumber V3 `FOR UPDATE`. Perubahan `users.guru_id` yang terjadi bersamaan dengan routing tidak dikunci. Akibat terburuknya pengajuan diarahkan ke guru yang baru dihubungkan, tetapi penolakan keputusan berbasis akun tetap berlaku dan admin dapat menetapkan ulang.
- **Data ganda dua akun** tidak dikenali (PF2-1).
- **Keamanan** (Fase 3) dan aplikasi (Fase 4) belum dikerjakan.

## Risiko rilis

Fase 2 menutup risiko yang dicatat Fase 1 (satu akun mengajukan lalu memutus sendiri). Fase 1 + 2 tetap **bukan izin rilis**: keputusan rilis, merge, deploy, dan migrasi produksi 032 milik Human Developer, dan gerbang produksi V5/V6/V7 yang terbuka tidak berubah.

## Handoff

Implementasi selesai; commit dan push pada `claude/penugasan-keamanan-fase-2`. **Berhenti untuk audit independen Codex.** Tidak merge, deploy, migrasi produksi, atau memulai Fase 3.
