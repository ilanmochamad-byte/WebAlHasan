# Hasil pengujian: Koreksi Pengelolaan Alumni

Keputusan pengguna 6 September 2026. Branch `feat/koreksi-alumni`.

Dokumen ini melaporkan **apa yang benar-benar dijalankan**, bukan apa yang
seharusnya lulus. Tiga label dipakai secara ketat:

| Label | Arti |
| --- | --- |
| **LULUS** | benar-benar dijalankan di lingkungan pengembangan, dan hasilnya hijau |
| **BELUM DIJALANKAN** | tidak dijalankan sama sekali; tidak ada bukti apa pun |
| **MEMERLUKAN UJI PRODUKSI/STAGING** | hanya dapat dibuktikan pada hosting cPanel sungguhan |

---

## 1. Lingkungan pengujian

| Hal | Nilai |
| --- | --- |
| PHP | 8.4.14 (CLI, Homebrew, macOS) |
| Basis data | MariaDB lokal, `binlog_format = MIXED` |
| Database uji | `webalhasan_alumni_test` — dibuat dari `k1807225_webalhasan.sql` lalu migrasi 001–011 |
| Isi | 360 santri, 4 tahun ajaran (satu aktif), kelas dan kamar produksi; tabel `alumni` kosong pada dump |
| Aplikasi mobile | **tidak tersedia** di mesin ini (`alhasanApps` tidak ter-checkout) |

Seluruh fixture pengujian memakai data fiktif berakhiran acak dan dihapus
kembali pada blok `finally`. Tidak ada data produksi yang disentuh dan tidak ada
permintaan jaringan keluar.

---

## 2. Ringkasan

| Rangkaian | Status | Pemeriksaan |
| --- | --- | --- |
| `tests/alumni_static.php` | **LULUS** | 180 |
| `tests/alumni_integration.php` | **LULUS** | 80 |
| `tests/alumni_concurrency.php` | **LULUS** | 13 |
| `tests/alumni_web_smoke.php` | **LULUS** | 61 |
| `bin/alumni_preflight.php` | **LULUS** (exit 0) | 7 bagian |
| `bin/alumni_verify.php` | **LULUS** (exit 0) | 25 |
| `bin/alumni_backfill.php` (mode laporan) | **LULUS** (exit 0) | — |
| Migrasi 011 → rollback → migrasi ulang | **LULUS** | lihat §5 |

**Total pemeriksaan paket ini yang lulus: 359** (180 statis + 80 integrasi + 13 bersamaan + 61 smoke web + 25 verifikasi skema).

---

## 3. Pemetaan ke daftar pengujian wajib

| # | Pengujian wajib | Status | Bukti |
| --- | --- | --- | --- |
| 1 | Kelulusan individual | **LULUS** | AL-1 (4 pemeriksaan), AW-8 |
| 2 | Status pindah individual | **LULUS** | AL-2 |
| 3 | Status berhenti individual | **LULUS** | AL-3 |
| 4 | Pemrosesan massal satu kelas | **LULUS** | AL-4 (6 pemeriksaan), AW-10 |
| 5 | Penutupan kelas aktif | **LULUS** | AL-5, AL-4, AW-10d |
| 6 | Penutupan kamar aktif | **LULUS** | AL-6, AL-2 |
| 7 | Santri sumber tetap tersedia sebagai arsip | **LULUS** | AL-7 (4 pemeriksaan), AW-8g |
| 8 | Riwayat kelas dan kamar tidak terhapus | **LULUS** | AL-5, AL-8 (3 pemeriksaan) |
| 9 | Relasi wali dan akun wali tidak terhapus | **LULUS** | AL-9 (3 pemeriksaan), AL-18 |
| 10 | Pemrosesan santri yang sama dua kali | **LULUS** | AL-10, KA-1, KA-2 |
| 11 | Klik atau request ganda | **LULUS** | AW-9 (token sekali pakai), AL-11 (kunci unik DB), KA-1/KA-2 (proses paralel nyata) |
| 12 | Rollback jika penyimpanan alumni gagal | **LULUS** | AL-12 (trigger `BEFORE INSERT` yang disuntikkan) |
| 13 | Rollback jika penutupan kelas/kamar gagal | **LULUS** | AL-13 (trigger `BEFORE UPDATE` pada `plotting_kelas`) |
| 14 | Arsip dan pemulihan alumni | **LULUS** | AL-14 (9 pemeriksaan), AW-11 |
| 15 | Pembatalan kelulusan/mutasi | **LULUS** | AL-15 (7 pemeriksaan) |
| 16 | Penolakan akses pengguna non-admin | **LULUS** | AW-1, AW-2 (akun guru sungguhan, dijawab 403) |
| 17 | Validasi CSRF | **LULUS** | AW-4 (tanpa token → 419; token palsu → 419; data tidak berubah) |
| 18 | Pencegahan SQL injection dan XSS | **LULUS** | AL-16, AW-12 (7 pemeriksaan) |
| 19 | Tampilan alumni lama | **LULUS** | AL-17 (8 pemeriksaan), AW-12g |
| 20 | Regresi halaman tetangga | **LULUS** | AL-18 + seluruh rangkaian regresi §4 |

### Catatan atas nomor 13

AL-13 menyuntikkan trigger `BEFORE UPDATE` pada `plotting_kelas` yang menolak
santri uji, membuktikan bahwa kegagalan **penutupan kelas** membatalkan seluruh
transaksi. Kegagalan **pelepasan kamar** tidak diuji dengan trigger terpisah:
keduanya berjalan di dalam transaksi yang sama dan melewati jalur rollback yang
sama (`AlumniService::terapkan()` blok `catch`), yang sudah dibuktikan tiga kali
oleh AL-12, AL-13, dan AL-19. Menambah trigger keempat tidak menambah informasi
baru.

### Catatan atas nomor 12 dan 13

Kedua pengujian ini memerlukan hak `CREATE TRIGGER` pada database uji. Bila hak
itu tidak tersedia, rangkaian mencetak `[lewati]` secara eksplisit alih-alih
mengklaim lulus. Pada lingkungan pengembangan ini haknya tersedia dan keduanya
**benar-benar dijalankan**.

---

## 4. Regresi paket sebelumnya

Dijalankan pada database uji yang sama, setelah migrasi 011 terpasang:

| Rangkaian | Status | Lulus | Gagal |
| --- | --- | --- | --- |
| `tests/penempatan_static.php` | **LULUS** | — | 0 |
| `tests/penempatan_integration.php` | **LULUS** | 62 | 0 |
| `tests/penempatan_web_smoke.php` | **LULUS** | 47 | 0 |
| `tests/penempatan_concurrency.php` | **LULUS** | 10 | 0 |
| `tests/perapihan_static.php` | **LULUS** | — | 0 |
| `tests/perapihan_integration.php` | **LULUS** | 53 | 0 |
| `tests/perapihan_web_smoke.php` | **LULUS** | 52 | 0 |
| `tests/perapihan_akun_concurrency.php` | **LULUS** | 7 | 0 |
| `tests/kredensial_static.php` | **LULUS** | — | 0 |
| `tests/kredensial_integration.php` | **LULUS** | 59 | 0 |
| `tests/kredensial_web_smoke.php` | **LULUS** | 51 | 0 |
| `tests/phase1_static.php` … `phase4_static.php` | **LULUS** | — | 0 |
| `tests/phase2_integration.php` | **LULUS** | 12 | 0 |
| `tests/phase3_integration.php` | **LULUS** | 10 | 0 |
| `tests/phase4_integration.php` | **LULUS** | 14 | 0 |
| `tests/phase5_integration.php` | **LULUS** | 20 | 0 |
| `tests/v2_phase1_static.php` | **LULUS** | — | 0 |
| `tests/v2_phase1_integration.php` | **LULUS** | 39 | 0 |
| `tests/v2_phase2_static.php` | **LULUS** | — | 0 |
| `tests/v2_phase2_integration.php` | **LULUS** | 94 | 0 |
| `tests/phase5_static.php` | **GAGAL — sebab lingkungan** | — | 3 |

### `tests/phase5_static.php` — 3 kegagalan yang BUKAN akibat paket ini

```
[gagal] Aplikasi guru menyediakan laporan dan detail pertemuan
[gagal] Aplikasi dapat membuka dialog cetak dan berbagi PDF
[gagal] Dependency cetak dan berbagi mengikuti Expo 57
```

Ketiganya memeriksa berkas di dalam repositori **`alhasanApps`** (aplikasi
mobile), yang **tidak ter-checkout di mesin ini**. Berkas
`tests/phase5_static.php` tidak diubah paket ini, dan tidak ada satu pun berkas
mobile yang disentuh. Setel `MOBILE_APP_ROOT` lalu jalankan ulang untuk
memverifikasinya sendiri.

### Satu perubahan yang disengaja pada `tests/penempatan_static.php`

Assertion **PS-15** sebelumnya mematok **jumlah berkas migrasi seluruh
repositori** pada angka 10. Patokan itu keliru sasaran: yang hendak dijamin
PS-15 adalah *“paket penempatan tidak menambah migrasi”*, bukan *“repositori ini
tidak boleh pernah bertambah migrasi lagi”*. Paket alumni menambah
`011_koreksi_alumni.sql` secara sah, dan patokan lama melaporkannya sebagai
kegagalan **penempatan**.

Assertion diubah menjadi maksud aslinya: tidak ada satu pun berkas migrasi yang
namanya memuat “penempatan”. Pemeriksaan pasangan migrasi↔rollback dan seluruh
pemeriksaan dokumentasi PS-15 lainnya **tidak diubah**.

---

## 5. Uji migrasi dan rollback

Dijalankan pada `webalhasan_alumni_test`:

1. `php bin/migrate.php up` → 011 diterapkan. **LULUS**
2. Menjalankan ulang berkas migrasi 011 mentah lewat `mysql` → tidak ada galat.
   **LULUS** (idempoten)
3. `php bin/migrate.php rollback` → skema `alumni` kembali **persis** seperti
   dump asli: `PRIMARY KEY (id)` + `UNIQUE KEY nis (nis)`, tanpa kolom tambahan.
   **LULUS**
4. `php bin/migrate.php up` → 011 diterapkan kembali. **LULUS**
5. `php bin/alumni_verify.php` → 25/25 pemeriksaan lulus. **LULUS**

Rollback **belum diuji pada tabel yang berisi baris arsip dengan NIS ganda** —
kondisi yang hanya muncul setelah pemakaian nyata (pembatalan lalu pemrosesan
ulang). Perilakunya sudah ditulis dan dijaga (langkah 9 rollback melewatkan
pemasangan kunci unik dan memberi instruksi manual), tetapi jalur itu berstatus
**BELUM DIJALANKAN**. Lihat `migrasi-dan-rollback.md` §8.

---

## 6. Yang BELUM DIJALANKAN

| Hal | Status | Alasan |
| --- | --- | --- |
| Uji peramban 1440 / 768 / 390 px | **BELUM DIJALANKAN** | tidak ada rangkaian uji peramban untuk paket ini; tata letak memakai komponen `assets/ui/alhasan.css` yang sudah diuji pada paket perapihan |
| Safari fisik (macOS/iOS) | **BELUM DIJALANKAN** | perlu perangkat sungguhan |
| Pembaca layar nyata (NVDA/JAWS/VoiceOver) | **BELUM DIJALANKAN** | perlu perangkat lunak dan penilaian manusia |
| Audit aksesibilitas otomatis (axe) | **BELUM DIJALANKAN** | tidak ada harness axe pada paket ini |
| Rollback pada tabel ber-NIS ganda | **BELUM DIJALANKAN** | lihat §5 |
| Pemrosesan massal 200 santri sekaligus | **BELUM DIJALANKAN** | batasnya divalidasi di server (AL-20), tetapi beban nyata 200 baris belum diukur |
| Regresi aplikasi mobile | **BELUM DIJALANKAN** | `alhasanApps` tidak tersedia; paket ini tidak menyentuh berkas mobile |

## 7. Yang MEMERLUKAN UJI PRODUKSI/STAGING

| Hal | Alasan |
| --- | --- |
| Migrasi 011 pada MySQL/MariaDB cPanel | versi server, hak akses, dan `binlog_format` produksi berbeda dari lingkungan lokal |
| `bin/alumni_preflight.php` pada salinan data produksi | tabel `alumni` pada dump repositori **kosong**; perilaku terhadap data warisan sungguhan belum pernah dilihat |
| `bin/alumni_backfill.php` pada data produksi | jumlah pasangan pasti vs ambigu hanya diketahui dari data sungguhan |
| Kunci asing `alumni_santri_fk` pada data produksi | bila ada `santri_id` yatim akibat pemakaian manual, constraint akan menolak — belum dapat diuji tanpa data nyata |
| Smoke test pasca-deploy | lihat `cpanel-deployment.md` §5 |
| Perilaku pada `binlog_format = STATEMENT` | galat 1665 sudah ditangani dan diterjemahkan, tetapi belum pernah dipicu pada server sungguhan |

---

## 8. Cara menjalankan ulang seluruh pengujian

```bash
php bin/migrate.php up
bash bin/alumni_run_all_tests.sh
```

Menjalankan satu per satu:

```bash
php tests/alumni_static.php
ALUMNI_RUN_INTEGRATION=1 php tests/alumni_integration.php
ALUMNI_RUN_CONCURRENCY=1 php tests/alumni_concurrency.php
ALUMNI_RUN_WEB=1        php tests/alumni_web_smoke.php
```

Seluruh rangkaian ber-DB menolak berjalan bila `DB_NAME` **tidak** berakhiran
`_test`.
