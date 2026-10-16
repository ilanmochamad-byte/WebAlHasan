# Audit Claude Code — PRD V4 Fase 2

**Tanggal:** 16 Oktober 2026 · **Auditor:** Claude Code · **Branch:** `codex/prd-v4-fase-2` · **Commit diaudit:** `4f276e2` (Codex) di atas baseline `d11f415`

**Putusan:** Seluruh kriteria penerimaan Fase 2 **terpenuhi** setelah dua koreksi terarah di bawah ini. Fase 3 belum dimulai; merge menunggu keputusan Human Developer.

## Lingkungan uji independen

- Instance MariaDB 12.3.2 milik auditor (datadir dan socket sementara terpisah, `--skip-networking`). Bukan instance Codex dan bukan produksi.
- DB baru `webalhasan_v4_fase2_test` pada instance itu: impor `k1807225_webalhasan.sql` → `migrate up` (001–020) → preflight/manifest → `migrate up` ulang ("Tidak ada migrasi baru") → verifier (116 lulus, 0 gagal) → fixture Fase 1.
- PHP 8.4.14 CLI, server PHP lokal `tests/v4_phase1_router.php`, Chromium Playwright 375 px dan desktop.
- Suite Codex direproduksi lebih dahulu tanpa perubahan kode (48 integrasi dan 2 konkurensi lulus). Setelah koreksi, seluruh rangkaian dijalankan ulang dari DB baru.

## Hasil per kriteria

| Kriteria | Hasil audit | Bukti |
| --- | --- | --- |
| 80/90 + 78 + 92 → 85 B pada web dan API | Lulus | `audit-claude-integrasi.txt`, `audit-claude-http-browser.txt`, `audit-claude-375.png` |
| Komponen kosong Belum lengkap; 0 tersimpan sebagai 0 | Lulus. Rumus umum **gagal sebelum koreksi 1** untuk N tepat x,50 (melanggar half-up [JANGAN DIUBAH]) | sama, ditambah fuzz di bawah |
| Guru di luar mapel/kelas/semester dan Pendidikan di luar jenjang/semester 403 | Lulus pada baca/tulis layanan, API, dan web; penugasan yang dicabut ditolak | `audit-claude-integrasi.txt`, `audit-claude-http-browser.txt` |
| Pengganti tanpa alasan 422; beralasan menyimpan pelaku dan kapasitas `pendidikan_pengganti` | Lulus. Namun **jejak pelaku salah sebelum koreksi 2**: komponen guru yang tidak diubah ikut diatasnamakan pengganti | sama |
| Request identik/bersamaan tanpa nilai ganda; versi usang 409 tanpa kehilangan revisi | Lulus. Enam proses, masing-masing dengan koneksi DB sendiri: satu 200 dan lima 409, satu baris dan satu revisi | `audit-claude-integrasi.txt` |

Tinjauan kode tambahan tidak menemukan masalah pada: urutan kunci parent rencana → rencana mapel → komponen (sama dengan Fase 1), pemeriksaan ulang cakupan di dalam transaksi, prepared statement di semua kueri, escape keluaran (`ah_e`) dan skrip yang hanya menyisipkan angka, CSRF 419 pada web, bearer 401 pada API, `Cache-Control: private, no-store`, rollback nilai+riwayat saat audit gagal, serta audit umum yang hanya berisi metadata (versi/status/kapasitas/penanda alasan).

## Koreksi audit

1. **Pembulatan half-up tidak tepat (`NilaiService::calculate`).** Perhitungan float `H×0,4 + T×0,3 + A×0,3` dapat menghasilkan 48,4999… untuk N yang tepat 48,50, sehingga nilai tampil menjadi 48, padahal seharusnya 49. Contohnya harian 14, tengah 97, akhir 46. Akibatnya predikat dapat turun di batas 69,5/79,5/89,5, dan `nilai_internal` (48.5) tidak konsisten dengan `nilai_tampil` (48). Fuzz 400.000 kombinasi acak terhadap aritmetika bilangan bulat tepat menemukan 44 nilai tampil dan 3.364 nilai internal dua desimal yang salah. Perhitungan kini memakai bilangan bulat perseratus (N = num/den tepat), lalu half-up untuk nilai tampil, nilai internal dua desimal, dan perbandingan target ketuntasan. Fuzz ulang: 0 selisih. Bobot, skala, dan predikat tidak berubah.
2. **Atribusi pelaku tertimpa (`NilaiService::save`).** Karena setiap simpan wajib menyertakan seluruh komponen tersimpan, setiap komponen yang dikirim ikut di-UPDATE. Akibatnya, ketika Bagian Pendidikan menambah satu harian sebagai pengganti, tiga komponen guru yang tidak diubah ikut berganti `pelaku_user_id`, `kapasitas=pendidikan_pengganti`, dan versi, serta mendapat revisi dan audit palsu. Hal ini bertentangan dengan tujuan "jejak pelaku yang jelas" dan metrik 100% atribusi pengganti. Kini komponen yang nilai, catatan, dan statusnya tidak berubah dilewati: tidak ada UPDATE, versi, riwayat, atau audit baru. Versinya tetap diperiksa sehingga optimistic version tetap berlaku. Simpan yang sama sekali tidak mengubah apa pun ditolak `422 Tidak ada perubahan untuk disimpan`. Sebelumnya permintaan seperti itu membuat revisi kosong yang diatasnamakan pengirim.

Uji yang disesuaikan/ditambahkan:
- `tests/v4_phase2_integration.php`: +6 pemeriksaan, terdiri atas tiga kasus half-up/presisi, atribusi komponen guru tetap saat pengganti menambah harian, hanya komponen baru yang tercatat pengganti, dan simpan tanpa perubahan 422. Uji kegagalan audit kini benar-benar mengubah satu catatan, karena simpan tanpa perubahan tidak lagi menulis. Total 54 lulus.
- `tests/browser/uji-v4-fase2.mjs`: koreksi pengganti via API kini mengubah satu catatan dan memastikan hanya komponen itu yang berkapasitas `pendidikan_pengganti`. Total 25 lulus.

## Hasil akhir (DB baru, urutan sama dengan Codex)

| Suite | Hasil |
| --- | --- |
| `v4_phase1_integration.php` | exit 0, 29 lulus (pemeriksaan aset nonaktif opsional tidak berjalan pada fixture ini, sama seperti catatan Codex) |
| `uji-v4-fase1.mjs` | exit 0, 23 lulus |
| `v4_phase2_integration.php` | exit 0, 54 lulus |
| `v4_phase2_concurrency.php` | exit 0, 2 lulus |
| `uji-v4-fase2.mjs` | exit 0, 25 lulus (375 px, teks 24 px, desktop tanpa overflow; CSRF 419; XSS tidak dieksekusi) |
| `uji-v4-fase2-guru.mjs` | exit 0, 8 lulus |
| `penugasan_static.php`, `v2_phase5_static.php`, `php -l`, `git diff --check` | lulus |
| Log server PHP selama uji browser | 0 warning/fatal |

Regresi integrasi V1–V3 tidak dijalankan ulang oleh auditor. Koreksi hanya menyentuh `app/V4/NilaiService.php` dan uji V4; tidak ada perubahan skema, endpoint lama, atau kode bersama. Hasil regresi Codex tetap menjadi rujukan, termasuk kegagalan warisan `phase5_static` (path mobile lama).

`SHA256SUMS` milik Codex mencatat keadaan `4f276e2`; hash berkas setelah audit ada di `SHA256SUMS-audit`. Tangkapan layar asli Codex dipertahankan; tangkapan auditor ada di `audit-claude-375.png` dan `audit-claude-guru-375.png`.

## Catatan nonblokir untuk fase berikutnya

- Retry identik setelah sukses tetap mendapat 409 (bukan replay 200). Klien Fase 5 harus memuat ulang versi, sebagaimana tercatat di `risiko-terbuka.md`.
- `koreksi` mengembalikan *seluruh* komponen `Dikirim` ke `Draf`, termasuk yang tidak diubah. Status berubah sehingga revisi tercatat atas nama pelaku koreksi. Ini konsisten dengan "perubahan memerlukan pemeriksaan ulang", tetapi Fase 3 perlu menentukan apakah verifikasi bersifat per komponen atau per isian mapel/santri.
- Capability `nilai.koreksi_sendiri`/`nilai.lihat_sendiri` belum dipakai terpisah; koreksi dan baca guru memakai `nilai.input`. Saat ini setara karena profil `guru_mapel` memberi ketiganya.
- Penguncian parent rencana menserialisasi semua penulis dalam satu kelas/semester. Ini aman, tetapi performa pada beban nyata belum diuji.
- MySQL/hosting cPanel, Safari, perangkat fisik, dan aplikasi mobile belum diuji; tidak ada deploy.
