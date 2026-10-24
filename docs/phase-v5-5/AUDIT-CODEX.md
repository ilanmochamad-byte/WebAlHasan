# Audit independen Codex — PRD V5 Fase 5

**24 Oktober 2026 · BELUM LULUS seluruh kriteria wajib Fase 5/V5.** Tiga temuan kode ditutup setelah koreksi dan uji lokal. Runtime iOS terbaru, perangkat fisik, Push provider/cold start non-dev, dan cPanel belum terbukti; V5 **tidak dinyatakan siap produksi**. Keputusan berikutnya diserahkan kepada Human Developer. Tidak merge, deploy, migrasi produksi, rilis aplikasi, atau memulai V6.

## Cakupan dan baseline

- Web: `claude/prd-v5-fase-5`, baseline audit Fase 4 `40ed0770148fd51aeba487380bb1d8ef07808ae0`. Seluruh commit fase diperiksa: `01694cf` implementasi dan `092cce1` bukti/handoff. Remote awal fitur `092cce1dbd0521ba0fa045978f91a66a28e5b82f`, main `5ea50ece847e19a9c26959d06217ed80db4a9686`.
- Mobile `/Users/ilanmochamad/alhasanApps`: branch sama, baseline `33522f7`, seluruh implementasi fase `b593d21e80eee637e0eb968b760e70c91c79877b`; main `9f913e27138dc0c0295c16722eb530ca2e5fffd6`. Tracked awal kedua repo bersih; isi main identik baseline audit Fase 4 masing-masing. Ini bukan bukti deploy/rilis.
- Dibaca AGENTS kedua repo, PRD V5, seluruh audit Fase 1–4, handoff Fase 5, runbook, manifest, checklist, kode/pengujian seluruh commit fase. Human Developer mengizinkan Fase 5 dengan butir Fase 4 tetap terbuka. Permintaan audit menetapkan Claude berhenti; tidak menjalankan Claude, subagen atau worktree.
- PHP 8.4.14, MariaDB 12.3.2 lokal, Node v26.7.0, Chromium/Playwright, Expo SDK 57 dev server. DB auditor `test_codex_v5_f5_test`, final `test_codex_v5_f5_final_test` dan `test_codex_v5_f5_final_http_test`; regresi memakai fixture resmi `test_*_test`. Pembentukan fixture berurutan. Semua isi/identitas sintetis; tidak mengakses DB produksi.
- Direktori pekerjaan lain `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/` tidak disentuh/dimasukkan commit.

## Temuan dan koreksi

| Temuan | Reproduksi dan dampak | Koreksi/status |
| --- | --- | --- |
| F-1 · P2 · Laporan mencampur commit | Koneksi lain menyimpan kunjungan + obat sesudah kueri total, sebelum kueri obat. Total masih lama, obat serta kelompok kelas/bulan sudah baru. SQL asli dijalankan MariaDB; subclass koneksi probe hanya menyisipkan commit di batas kueri. | Seluruh laporan memakai transaksi baca REPEATABLE READ + consistent snapshot, tidak bergantung isolation level hosting. Penutupan transaksi sukses/gagal diuji. Ditutup lokal. |
| F-2 · P2 · Manifest rilis terpotong diterima | `--rilis` dengan daftar migrasi/rollback kosong atau satu rollback hilang tetap exit 0 karena hanya memeriksa entri yang tercantum. | Daftar lengkap/unik seluruh migrasi disk dan tiga rollback V5 wajib, validasi entri/jalur, lalu SHA tiap berkas. Ditutup lokal. |
| F-3 · P2 · Verifier melewati preservasi yang hilang | Manifest privat kehilangan seluruh sidik jari atau hanya relasi santri–wali masih exit 0. Daftar tabel lama kosong juga tidak membuktikan preservasi. | Jika manifest diberikan, wajib tepat tiga sidik jari dan seluruh tabel lama; kekurangan/selisih gagal. Tanpa manifest tetap jelas hanya memeriksa integritas saat ini. Ditutup lokal. |

Probe `tests/v5_phase5_audit.php`: kontrol awal **3 lulus/6 gagal**; sembilan asersi yang sama sesudah koreksi **9/0**. Setelah diperluas dengan daftar tabel lama, bawaan READ COMMITTED dan penutupan transaksi: **13/0**. Bukti awal tidak ditimpa. Koreksi aplikasi hanya `LaporanService`, `LaporanRepository`, `v5_preflight.php`, `v5_verify.php`; tanpa migrasi baru/perubahan hak/DTO klinis/worker.

Permintaan tambahan Human Developer: ikon Beranda **Kesehatan/Kesehatan anak** mobile diganti dari `info` menjadi tas medis bertanda tambah, PNG transparan 24/48/72 px dengan sumber SVG mengikuti ikon garis/tint yang ada. Tanpa dependensi native baru. Tsc/lint dan tampilan petugas/wali diperiksa. Ini koreksi tampilan yang diminta dalam sesi audit.

## Kriteria terbukti, gagal, dan belum diuji

| Kriteria Fase 5 | Putusan |
| --- | --- |
| Laporan = sumber, tanpa hitungan ganda | **Terbukti lokal setelah F-1.** Fixture September 6 kunjungan/8 pemberian obat; kunjungan tiga obat tetap satu. Angka/filter/tertunda/kelompok diuji per hak; commit di tengah laporan tidak mencampur angka. |
| Nol kebocoran lintas peran/anak web/API/aplikasi | **Terbukti pada permukaan yang diuji, belum lintas platform lengkap.** Draf, binaan, pengawasan, proyeksi wali, sesi/token lama dan pencabutan diuji. Android laporan empat peran + wali/pengurus ditolak; iOS terbaru/fisik belum diuji. |
| Koreksi/publikasi ulang berjejak | **Terbukti fixture lokal.** Versi sebelum/sesudah, pelaku/waktu/alasan, snapshot, Ganti/Tarik, audit metadata, kegagalan snapshot/outbox/audit serta verifier sesudah konkurensi. Bukan pemeriksaan data produksi. |
| Idempotensi/konkurensi tanpa efek ganda | **Terbukti skenario lokal yang dijalankan:** enam proses buat/koreksi/terbit, replay, 409, transaksi gagal; dua worker, klaim/lease/retry provider palsu. Bukan seluruh interleaving/exactly-once provider saat jawaban jaringan hilang. |
| Regresi relevan V1–V4 | **Lulus rangkaian relevan di bawah; kegagalan/dilewati historis dipisahkan.** Tidak menyatakan seluruh suite lama diulang. |
| Manifest/pemulihan/verifier/rollback/worker/checklist | **Terbukti lokal untuk tabel aplikasi, belum cPanel.** Naik–turun–naik 024–026 kosong menjaga tabel lama/identitas. Rollback berisi ditolak. BackupWriter dan mariadb-dump diuji terpisah. Probe routine **gagal izin lingkungan**. |
| Auditor menyatakan seluruh kriteria wajib terpenuhi | **Belum terpenuhi.** Kekurangan bukti wajib Fase 4/5 tidak ditutup oleh hasil lokal. |

## Eksekusi auditor

Semua bukti di `bukti/audit-codex/` merupakan eksekusi auditor, bukan salinan keluaran implementator.

| Rangkaian | Hasil | Bukti |
| --- | --- | --- |
| Fase 5 statis/lint final | **58/0** | `statis-final.txt` |
| Fase 5 integrasi/6 proses/transaksi gagal final | **95/0** | `integrasi-final.txt` |
| Fase 5 HTTP web/API final | **57/0** | `http-final.txt` |
| Probe laporan/manifest/verifier | **13/0** | `probe-final.txt`; kontrol `probe-sebelum.txt` |
| Migrasi/rollback/manifest/BackupWriter final | **22/0**, drill **12/0** | `rilis-final.txt` |
| Perintah runbook mariadb-dump/restore, trigger, hash seluruh 101 tabel V1–V5, verifier/rollback | **7/0** | `backup-runbook.txt`; `tests/v5_phase5_operator_drill.php` |
| Probe stored routine tambahan | **6/2, gagal izin ALTER ROUTINE** | `backup-routine-gagal-izin.txt` |
| Web laporan 375/768/1280 + tanpa JS | **61/0** | `browser.txt`, `tangkapan/` |
| Expo web lima peran + dua peran ditolak | **36/0** | `aplikasi-expo-web.txt` |
| Android emulator laporan/akses dicabut/FLAG_SECURE | **15/0** | `android.txt`; `tests/android/v5_fase5_audit.py` |
| Mobile tsc/lint/unit | exit **0/0**, **29/0** | `mobile-tsc-final.txt`, `mobile-lint.txt`, `mobile-unit.txt` |
| Browser kerangka sembilan akun, tiga lebar | **107/0** | `browser-kerangka.txt` |
| Dua worker, klaim/lease | **20/0** | `worker-konkurensi.txt` |
| Worker status/uji-coba/Push OFF, cron check | exit 0; **5 pemeriksaan sehat**, 1 peringatan key Push belum siap | `worker-cron.txt`; provider tidak dipanggil |

Skrip regresi resmi disalin ke lokasi sementara dengan perubahan hanya jalur keluaran/cwd agar bukti implementator tidak tertimpa; oracle tetap. Regresi berurutan (`regresi/ringkasan.txt`): Fase 2 integrasi/HTTP/probe **134/0 · 87/0 · 28/0**, Fase 3 **82/0 · 70/0 · 26/0**, migrasi 026 **26/0**, HTTP Fase 4 **43/0**; penugasan **161/0**, V2 Fase 4 **124/0**, V2 Fase 5 **143/0** (kontrol terpisah **143/0**); HTTP Tahap 5 pembinaan **106/0**, Tahap 7 rapor **122/0**, Tahap 6 **132/0** sesudah rollback kosong 026/025/024 **14/0**. Tahap 5 auditor tidak mereproduksi 100/6 implementator pada waktu/fixture ini; hasil implementator dan kontrolnya tetap disimpan.

Regresi dimulai sebelum koreksi auditor. Sesudah koreksi, Fase 5 integrasi, HTTP, rilis, statis, probe dan browser/aplikasi dijalankan pada kode akhir. Modul bisnis Fase 1–4 tidak diubah auditor; suite tersebut tidak diulang lagi tanpa alasan baru.

### Oracle historis dan cakupan yang tidak diulang

- **34 statis: 22 exit 0, 10 exit 1, 2 exit 77.** Baris gagal identik handoff: tujuh jenis penugasan lama; AG/pratinjau lama; larangan diff database/API/bin sejak tahap lama; Fase 1 hanya 024/tanpa koreksi/draf; Fase 2 025 terakhir; Fase 3 larangan perubahan layanan sebelum pemilih kelas; literal deep-link V2 yang sudah diganti helper sejak Fase 4. Oracle tidak diubah/dilonggarkan.
- Fase 1 integrasi **71/4**, HTTP **79/3**: pembaca binaan/draf/koreksi sah ditambahkan sesudah Fase 1. Migrasi 025 **23/6**: asumsi 025 terakhir, kini 026 ada. HTTP Tahap 2 **187/4**: keputusan menu BB/BC/BD; Tahap 3 **166/1**: asumsi AG belum dipasang. Semua hasil gagal dipertahankan.
- Tidak mengulang suite V3/V4 dengan nama DB/env privat tetap, seluruh HTTP PSB/GET-mutasi/cleanup/alumni/penempatan/perapihan, atau browser Fase 1–3. V1 tercakup statis/halaman lama/kerangka serta kelestarian seluruh tabel saat pemulihan; V3/V4 melalui HTTP Tahap 5/7 dan rangkaian relevan di atas. Bukan audit ulang seluruh rilis V1–V4.

## Lingkungan dan batas nyata

| Lingkungan | Bukti dan batas |
| --- | --- |
| Chromium | Web tiga lebar/tanpa JS; Expo web 375 px + API/DB nyata. CDN web dari aset lokal. Expo salinan `git archive` tanpa `.env.local`, bundel baru, request browser dibatasi localhost. Tangkapan laporan/ikon diperiksa visual. Bukan Safari/fisik/hosting. |
| Android | Emulator Medium_Phone, sdk_gphone16k_arm64, Android 16. Dev client Debug 1.0.0/code 1 dibangun saat audit Fase 4 (lastUpdateTime 24 Oktober 19:49:39), sudah berisi expo-screen-capture; JS Fase 5 dari Metro lokal 8103. **Tidak membangun binary baru/distribusi.** Probe 15/0 hanya laporan, bukan seluruh alur klinis Fase 4. Ikon baru diverifikasi visual lewat Expo web. |
| iOS terbaru/fisik | **Tidak diuji pada audit ini.** Kegagalan toolchain/runtime iOS Fase 4 tetap terbuka; emulator tidak menggantikan fisik. |
| Push nyata/receipt/ketukan/cold start | **Tidak diuji.** Worker beradapter palsu; URI/InApp bukan ketukan provider. Cold start non-dev tetap terbuka. |
| cPanel/produksi | **Tidak diakses/diuji.** DB/CHECK, CLI/FPM, izin restore/routine, HTTP direktori privat, cron/key Push, smoke dan deploy/migrasi menunggu operator atas keputusan Human Developer. |

**Pemulihan:** BackupWriter tidak mengekspor trigger/routine. Jalur runbook `mariadb-dump --single-transaction --routines --triggers` diuji terpisah: tabel aplikasi + trigger sintetis pulih, hash seluruh tabel identik, verifier lulus, rollback berisi ditolak (**7/0**). Dengan procedure sintetis (`V5_DRILL_ROUTINE=1`), restore berhenti pada DROP PROCEDURE IF EXISTS: akun `wah_test` tidak memiliki **ALTER ROUTINE** pada DB target. **6/2 tetap gagal lingkungan**, tidak diubah menjadi lulus lewat pemberian hak/penghapusan asersi. V5 tidak membuat routine; pemeriksaan hak/routine hosting tetap wajib sebelum menyebut backup sebagai restore point. Tidak mengubah hak DB global.

Belum diuji: beban besar, seluruh interleaving/deadlock 1205/1213, MySQL/versi DB hosting, UAT ≥95%, pembaca layar, cache/log OS iOS terbaru. Snapshot laporan mempertahankan keadaan/hak pada awal permintaan; pencabutan berlaku pada permintaan berikutnya. Verifier lintas proses sebaiknya dipakai ketika penulis dihentikan untuk bukti preservasi stabil.

## Penyiapan dan handoff

- Fixture awal diblokir sandbox `/dev/fd`; diulang dengan izin eksekusi lingkungan. `.env` tidak diubah.
- Tsc salinan sebelum Expo menghasilkan expo-env.d.ts gagal dua deklarasi CSS; sesudah berkas keluaran Expo tersedia, tsc/lint lulus. Tidak menambah deklarasi palsu.
- Runner Expo awal **34/2** menunggu total yang kebetulan sama dengan periode lama. Penantian diperbaiki ke tanggal periode; asersi angka/akses tetap, hasil **36/0**. Browser kerangka awal salah menyebut sbx_bendahara yang tidak ada; sembilan akun benar menghasilkan **107/0**. Bukti awal disimpan.
- Harness pemulihan awal mencampur stderr ke SQL; diperbaiki. Satu latihan tanpa routine bertumpang tindih dengan worker sintetis dan gagal hash sumber; diulang setelah worker berhenti sesuai runbook (**7/0**). Bukti penyiapan disimpan. Kegagalan routine sesudah harness benar tetap batas izin nyata.
- S-1…S-6 handoff diterima: pembaca internal sesuai hak; pengawasan admin; periode kunjungan/kelas saat ini; tertunda seluruh waktu; tanpa ekspor; angka bukan pembukaan rincian; rollback hanya engine CHECK ditegakkan dan tanpa penulis aktif. Tidak mengubah keputusan produk.

**Handoff:** tiga temuan ditutup lokal, ikon diminta diperbaiki, bukti wajib yang belum ada tetap terbuka. Kriteria Fase 4/5 tidak dicentang penuh. Human Developer menentukan tindak lanjut; audit ini tidak memberi persetujuan produksi/merge/deploy/migrasi/rilis/V6.

Commit handoff mobile hasil audit/ikon: `44ae015` pada `claude/prd-v5-fase-5`.
