# V2 Fase 5 — Hasil Regresi PRD-V1 dan PRD-V2

Kriteria: *"Uji regresi seluruh alur V1: login, master data, jadwal, absensi,
laporan, cetak, API, dan aplikasi guru"* serta seluruh alur V2 Fase 1–4.

Seluruh angka berasal dari putaran yang sama dengan `test-results.md`:
**28 berkas, 2.230 pemeriksaan, 0 gagal**, diulang dua kali dengan hasil
identik.

## 1. Ringkasan

| Area | Cakupan regresi | Hasil |
| --- | --- | --- |
| Login dan otorisasi | V1 + V2, web + API | LULUS |
| Data master | santri, guru, kamar, kelas, tahun ajaran, wali, pengurus | LULUS |
| Jadwal pengajian | jadwal, pertemuan, peserta | LULUS |
| Absensi guru dan santri | pencatatan dan pembacaan | LULUS |
| Laporan dan pencetakan V1 | `/reports`, `/reports/filters`, `/reports/print`, halaman absensi | LULUS |
| REST API V1 | kontrak tidak berubah | LULUS |
| Aplikasi guru | endpoint jadwal/pertemuan/absensi | LULUS |
| Alur perizinan V2 Fase 1–2 | pengajuan, routing, keputusan, pembatalan, koreksi | LULUS |
| REST API V2 Fase 3 | kontrak multi-peran | LULUS |
| Notifikasi V2 Fase 4 | in-app, push, outbox, dedup, concurrency | LULUS |
| Isolasi data antarmurobi/pengurus/orang tua | seluruh permukaan | LULUS |
| Audit, deduplikasi, konsistensi keputusan | Fase 2 + Fase 4 | LULUS |

## 2. Mengapa perubahan Fase 5 berisiko rendah terhadap V1

| Sifat | Bukti |
| --- | --- |
| Laporan V2 memakai **kelas baru** (`App\Report\Izin*`), bukan mengubah `App\Report\ReportRepository`/`ReportService` V1 | `app/bootstrap.php` memiliki `report_service()` (V1) **dan** `izin_report_service()` (V2), keduanya utuh |
| Endpoint laporan V2 memakai prefiks `/izin/laporan*`, terpisah dari `/reports*` V1 | statis §6; KR-9h…KR-9l |
| Migrasi 009 tidak menyentuh satu pun tabel V1 | statis §1; verifikasi §2 |
| Kontrak `PushClient` Fase 4 **tidak diubah** | statis §7; klien tiruan Fase 4 tetap sah tanpa perubahan |
| Perubahan pada `portal/_ui.php` hanya menambah satu tautan navigasi | diff satu blok |

Berkas Fase 4 yang **diubah** (`ExpoPushClient`, `NotificationDispatcher`,
`OutboxRepository`, `notifikasi_worker.php`) seluruhnya bersifat menambah:
`markSent()` mendapat parameter opsional keempat, dan metode baru ditambahkan
tanpa mengubah tanda tangan yang sudah ada. Seluruh 280 pemeriksaan Fase 4
(122 + 92 + 20 + 46) tetap lulus tanpa satu pun perubahan pada berkas ujinya.

## 3. Regresi V1 — rinci

### 3.1 Statis (218 pemeriksaan)

| Berkas | Pemeriksaan | Cakupan |
| --- | --- | --- |
| `tests/phase1_static.php` | 64 | Keamanan, sesi, CSRF, hashing |
| `tests/phase2_static.php` | 46 | Data master |
| `tests/phase3_static.php` | 34 | Jadwal dan pertemuan |
| `tests/phase4_static.php` | 38 | API dan absensi |
| `tests/phase5_static.php` | 36 | Laporan absensi V1 dan indeks pelaporan |

### 3.2 Integrasi (56 pemeriksaan)

| Berkas | Pemeriksaan | Cakupan |
| --- | --- | --- |
| `tests/phase2_integration.php` | 12 | CRUD data master |
| `tests/phase3_integration.php` | 10 | Jadwal, pertemuan, peserta |
| `tests/phase4_integration.php` | 14 | Absensi guru dan santri, idempotensi |
| `tests/phase5_integration.php` | 20 | Laporan absensi, ringkasan, ekspor, cetak |

### 3.3 Kontrak API V1 lewat HTTP

Diperiksa langsung pada `tests/v2_phase5_api_contract.php` §KR-9, dengan token
bearer sungguhan:

| Endpoint | Harapan | Hasil |
| --- | --- | --- |
| `GET /` | 200 | LULUS |
| `GET /profile` | 200 | LULUS |
| `GET /me/capabilities` | 200 | LULUS |
| `GET /reports` | 200, memuat `summary`, `items`, `pagination`, `filters`, `active_filters` | LULUS |
| `GET /reports/filters` | 200 | LULUS |
| `GET /reports/print` | 200 | LULUS |
| `GET /schedules/today` (akun guru biasa) | 200 | LULUS |

Ditambah satu pemeriksaan yang sengaja mencari pencemaran kontrak: respons
`/reports` V1 **tidak boleh** memuat kunci `cakupan` milik bentuk laporan V2
(KR-9l). Lulus.

### 3.4 Halaman web V1

`tests/v2_phase5_web_smoke.php` §WL-8 membuka halaman sungguhan dengan sesi
login:

| Halaman | Hasil |
| --- | --- |
| `admin/admin_laporan_absensi.php` | Terbuka tanpa `Fatal error`/`Uncaught` |
| `admin/admin_notifikasi.php` (Fase 4) | 200 |
| `portal/index.php`, `portal/izin.php`, `portal/izin_antrean.php`, `portal/notifikasi.php` | 200 |

### 3.5 Aplikasi guru

Kemampuan guru yang tidak memiliki penugasan murobi diuji khusus (KR-8f):
akun tersebut **ditolak 403** pada seluruh endpoint laporan perizinan, tetapi
**tetap dapat** memakai `/schedules/today` miliknya. Ini penting — penolakan
laporan perizinan tidak boleh mematikan kemampuan guru yang sah.

## 4. Regresi V2 Fase 1–4 — rinci

| Berkas | Pemeriksaan | Cakupan |
| --- | --- | --- |
| `tests/v2_phase1_static.php` | 126 | Fondasi akun, role, migrasi 006 |
| `tests/v2_phase1_integration.php` | 39 | Migrasi warisan, relasi akun, capability |
| `tests/v2_phase2_static.php` | 169 | Alur pengajuan, routing, keputusan |
| `tests/v2_phase2_integration.php` | 94 | Pengajuan, routing, keputusan, pembatalan, koreksi, audit |
| `tests/v2_phase2_navigasi_murobi.php` | 32 | Navigasi murobi lintas peran |
| `tests/v2_phase2_web_smoke.php` | 35 | Halaman portal Fase 2 |
| `tests/v2_phase3_static.php` | 147 | Kontrak API dan capability |
| `tests/v2_phase3_api_contract.php` | 116 | Otorisasi lintas peran, idempotensi, concurrency |
| `tests/v2_phase4_static.php` | 286 | Notifikasi, push, WhatsApp, keamanan secret |
| `tests/v2_phase4_integration.php` | 122 | 9 peristiwa, penerima, dedup, retry, backoff |
| `tests/v2_phase4_api_contract.php` | 92 | Endpoint notifikasi dan perangkat |
| `tests/v2_phase4_concurrency.php` | 20 | Dua worker, 12 baris → 12 pesan, 0 ganda |
| `tests/v2_phase4_web_smoke.php` | 46 | Pusat notifikasi dan panel kanal |

### Perubahan yang disengaja pada berkas uji fase sebelumnya

Hanya satu berkas uji fase lama yang diubah, dan perubahannya **memperketat**,
bukan melonggarkan:

`tests/v2_phase3_static.php` sebelumnya menegaskan *"Tidak ada pekerjaan Fase 5
yang dimulai"* dan *"Jumlah migrasi menjadi 8 berkas"*. Kedua pagar itu memang
dipasang Fase 4 sebagai batas ruang lingkup, dan kini tidak berlaku lagi karena
Fase 5 sah dimulai. Keduanya **diganti**, bukan dihapus, dengan pagar yang lebih
kuat:

| Sebelum | Sesudah |
| --- | --- |
| `!is_file('.../009_v2_phase5.sql') && glob('tests/v2_phase5_*.php') === []` | Migrasi 009 **wajib** memiliki pasangan rollback |
| `count(migrations) === 8` | `count(migrations) === 9` |
| *(tidak ada)* | Endpoint Fase 3 tetap utuh **dan** endpoint laporan Fase 5 bersifat aditif |

Berkas uji Fase 1, 2, 4, dan seluruh berkas uji V1 **tidak disentuh sama
sekali**.

## 5. Isolasi data antarperan

Diverifikasi pada tiga lapisan sekaligus — bukan hanya satu.

| Lapisan | Berkas | Bukti |
| --- | --- | --- |
| Layanan | `v2_phase5_integration.php` | KL-2a…KL-2l, KL-3a…KL-3g |
| HTTP/API | `v2_phase5_api_contract.php` | KR-2a…KR-2i, KR-3a…KR-3e, KR-8d…KR-8f |
| HTML terender | `v2_phase5_web_smoke.php` | WL-1a…WL-1b, WL-3a…WL-3e, WL-6a…WL-6f |

Yang diperiksa bukan hanya jumlah baris, tetapi juga **isi**: nama santri milik
cakupan lain tidak boleh muncul pada HTML daftar, HTML cetak, maupun berkas CSV.

## 6. Audit, deduplikasi, dan konsistensi keputusan

| Jaminan | Berkas | Hasil |
| --- | --- | --- |
| Setiap transisi status punya pelaku, waktu, dan alasan | `v2_phase2_integration.php` | LULUS |
| Dua keputusan bersamaan → tepat satu keputusan | `v2_phase2_integration.php`, `v2_phase3_api_contract.php` | LULUS |
| Retry create/decision tidak menambah baris | `v2_phase3_api_contract.php` | LULUS |
| Koreksi tidak menghapus riwayat | `v2_phase2_integration.php` | LULUS |
| Dedup peristiwa/kanal/penerima | `v2_phase4_integration.php` | LULUS |
| Dua worker tidak mengirim ganda | `v2_phase4_concurrency.php` | LULUS |
| **Rekonsiliasi receipt tidak mengirim ulang** (baru Fase 5) | `v2_phase5_integration.php` KL-9g, KL-9k | LULUS |
| **Rekonsiliasi receipt idempoten** (baru Fase 5) | `v2_phase5_integration.php` KL-9p | LULUS |

## 7. Yang tidak tercakup regresi otomatis

| Area | Status | Rujukan |
| --- | --- | --- |
| Aplikasi guru pada perangkat fisik | MENUNGGU VERIFIKASI | `uji-manual-tertunda.md` §3 |
| Halaman publik (berita, galeri, PSB) | Tidak disentuh Fase 5; tidak ada pengujian otomatis sejak V1 | Smoke test manual `cpanel-deployment.md` §5 |
| Impor/ekspor XLSX V1 | Tidak disentuh Fase 5 | Smoke test manual |
| Perilaku pada PHP versi cPanel | Sandbox memakai PHP 8.4 | Ulangi `php -l` pada staging |
| `npx expo export -p web` | Gagal karena lingkungan, **terbukti pre-existing** pada baseline | `test-results.md` §8 |
