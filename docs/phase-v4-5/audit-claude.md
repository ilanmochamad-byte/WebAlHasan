# Audit Claude Code — PRD V4 Fase 5

**Tanggal:** 17 Oktober 2026 · **Auditor:** Claude Code · **Branch:** `codex/prd-v4-fase-5` (web dan mobile)

**Commit diaudit:**

| Repo | Baseline | Commit Codex |
| --- | --- | --- |
| WebAlHasan | `831017a` | `2deb8f9` (implementasi), `f107554` dan `9f78918`…`ab8f2f9` (bukti) |
| alhasanApps | `dfae26d` | `c969374` (implementasi), `dc943ed` (bukti) |

**Putusan:** Kriteria Fase 5 yang dapat dibuktikan di lingkungan lokal **terpenuhi** setelah dua koreksi terarah (lihat di bawah). Kriteria yang memerlukan iOS, perangkat fisik, Push nyata, dan smoke cPanel/produksi **belum dapat dinyatakan lulus**. Itu bukan kegagalan implementasi, melainkan pelaksanaan yang masih menunggu, seperti yang sudah dicatat jujur oleh Codex. Branch **belum siap di-merge sebagai rilis produksi** sampai Human Developer memutuskan pelaksanaan bukti fisik/staging (kriteria 4 dan 6). PRD berikutnya belum dimulai.

## Lingkungan uji independen

- Auditor memakai instance MariaDB 12.3.2 sendiri (`/private/tmp/v4a5-db`, socket sendiri, `--skip-networking`), server PHP 8.4.14 pada `127.0.0.1:8975`, dan Expo web/Metro pada `127.0.0.1:8095`. Instance, storage, dan path `/private/tmp/v4p5-*` milik Codex tidak dipakai atau diubah.
- Web dijalankan dari salinan `git archive HEAD` (`/private/tmp/v4a5-web`). Path fixture pada salinan diarahkan ke `/private/tmp/v4a5-fase*`, dan output bukti ke direktori auditor. Kode aplikasi pada salinan identik dengan commit diaudit, ditambah koreksi audit.
- Urutan DB baru: impor `k1807225_webalhasan.sql` → migrasi 001–019 → preflight/manifest → `migrate up` (020–022) → `up` ulang ("Tidak ada migrasi baru") → verifier 020 dengan manifest (0 gagal) → rangkaian `perintah-regresi-v4.sh` → rantai Fase 5.
- Rangkaian dijalankan dua kali. Putaran 1 memakai kode Codex tanpa perubahan, dan seluruh suite Codex lulus. Putaran 2 dimulai dari DB, storage, dan fixture baru dengan kode terkoreksi. Bukti `audit-claude-*` berasal dari putaran 2, kecuali integrasi V1–V3 (lihat catatan di tabel).

## Hasil per kriteria

| Kriteria PRD Fase 5 | Hasil audit | Bukti |
| --- | --- | --- |
| 1. Nilai/sikap app identik dengan web; versi usang → 409 tanpa menimpa | **Lulus (Expo web + API/DB nyata).** E2E Codex 37/37 diulang. Probe auditor: jawaban hilang sesudah commit (respons dibuang) → retry manual 409 dan tetap 3 baris nilai; ketukan ganda → 1 POST; form dimuat ulang dari server sesudah 409. | `audit-claude-aplikasi.txt` |
| 2. Akses silang 403/kosong pada web dan aplikasi | **Lulus pada web/API/Expo web.** Capability per peran sesuai penugasan; admin tidak mendapat `rapor.wali`. Detail wali tanpa `petugas=1` ditolak untuk Pendidikan, admin, guru, dan wali lain; `petugas=1` ditolak untuk wali/guru. Pencabutan relasi berlaku seketika (detail, PDF, capability). Regresi akses Fase 1–4 lulus ulang. | `audit-claude-api-proyeksi.txt`, `audit-claude-regresi-v4.txt` |
| 3. Notifikasi generik → login → rapor sendiri → PDF hash sama; wali lain ditolak | **Lulus pada Expo web dan Android emulator (URI).** PDF wali = `pdf_sha256` DB = unduhan petugas, dan unduhan ulang identik; `application/pdf` + `no-store`. Versi Diganti/Ditarik → 403 bagi wali. URI sebelum login, akun salah → 403, lalu akun benar → terbuka sekali (sesudah koreksi 1). Ketukan Push nyata dan cold start belum diuji. | `audit-claude-api-proyeksi.txt`, `audit-claude-android.txt` |
| 4. Regresi V1–V4, browser 4 peran desktop/375, iOS/Android dicatat | **Lulus untuk yang tersedia.** V4 Fase 1–4: semua suite exit 0, probe audit Fase 4 83/0. V1–V3: 15 statis + 14 integrasi exit 0, nol `[gagal]`. Mobile: tsc, lint, 21 unit lulus. **iOS runtime tidak diuji** dan Android hanya emulator, sehingga bagian lintas platform belum lengkap. | `audit-claude-ringkasan.txt`, `audit-claude-regresi-v1-v3.txt`, `audit-claude-mobile.txt` |
| 5. 0 credential/nilai/nama/gambar di push dan log umum | **Lulus lokal.** Outbox 34 baris berisi teks generik dan hanya `tipe`, `rapor_id`. Audit V4 tanpa isi sensitif. Log HTTP/Metro auditor tanpa token (termasuk token admin auditor), nama santri, atau bearer. Storage web tanpa isi rapor. Kode rapor mobile tanpa console/AsyncStorage/Clipboard/analytics. Log OS/provider/hosting belum diuji. | `audit-claude-privasi.txt`, `audit-claude-aplikasi.txt` |
| 6. Manifest/backup/verifier/rollback/worker/smoke | **Lokal lulus; produksi menunggu.** 22 hash migrasi + `composer.lock` + `package-lock.json` pada manifest cocok dengan berkas, dan tidak ada perubahan dependensi. Backup → restore ke DB/root terpisah: 19/0 (baris V4 identik, PDF rusak 503, byte backup pulih); verifier Fase 4 21/0; preflight rollback pada DB berisi data menolak (exit 1). Checklist smoke cPanel seluruhnya "Menunggu pelaksanaan", tanpa klaim lulus. Auditor tidak mengulang probe Apache karena `.htaccess` tidak berubah sejak baseline. | `audit-claude-pemulihan.txt`, `manifest-migrasi.json`, `checklist-smoke.md` |
| 7. Auditor menyatakan kriteria wajib terpenuhi; branch bersih | **Sebagian.** Bagian yang dapat diuji lokal terpenuhi sesudah koreksi, dan kedua branch bersih serta di-push. Kriteria 4 (iOS/fisik) dan 6 (smoke produksi) masih memerlukan pelaksanaan dan keputusan Human Developer sebelum merge/rilis. | dokumen ini |

Catatan: integrasi V1–V3 dijalankan pada putaran 1 (kode web Codex). Koreksi web hanya menyentuh `RaporService::parentView`, yang tidak dipanggil suite V1–V3. Ke-15 suite statis diulang sesudah koreksi dan exit 0.

## Koreksi audit

### 1. Deep-link `alhasanapps://rapor/ID` saat sesi aktif membuka dua layar (mobile)

Di Android emulator, dengan wali sudah login, URI rapor menumpuk dua layar "Rapor keluarga": Back pertama tetap pada rapor, baru Back kedua kembali ke Beranda. Tiga pembanding menunjukkan sumbernya:

- rute lain tanpa listener (`alhasanapps://notifikasi`) hanya membuka satu layar;
- navigasi dari menu hanya membuka satu layar;
- listener URI yang dimatikan sementara (Metro `--clear`) hanya membuka satu layar.

Jadi expo-router sudah menavigasi URI skema aplikasi, dan listener Fase 5 menambah `router.push` kedua.

Perbaikan di `src/notifications/notification-context.tsx`:

- Saat sesi aktif, listener URI hanya menyimpan penunjuk tertunda dan tidak melakukan push sendiri. Penunjuk itu tetap bertahan untuk 401/login ulang dan dibersihkan oleh `tujuanDibuka` setelah detail berhasil dimuat.
- Saat belum login, perilakunya tetap seperti sebelumnya: tujuan ditunda sampai autentikasi.
- Efek tujuan tertunda tidak melakukan push bila lokasi saat ini sudah `/rapor/ID`. Penjaga ini untuk cold start dengan sesi tersimpan dan hanya diverifikasi lewat tinjauan kode.

Percobaan `dangerouslySingular` pada `Stack.Screen` tidak mencegah tumpukan dan dibatalkan. Diuji ulang dengan Metro `--clear` dan bundel yang diperiksa: (A) sesi aktif, (B) sebelum login, dan (C) akun salah lalu akun benar. Ketiganya membuka satu layar dan Back pertama kembali ke Beranda. tsc, lint, 21 unit, E2E 37/37, serta statis V2/V3 yang memeriksa `notification-context.tsx` juga lulus.

### 2. Proyeksi wali tetap mengirim rata-rata desimal (web)

`RaporService::parentView` sengaja membuang `nilai_akhir` desimal per mapel, tetapi tetap mengirim `rata_rata` (contoh 84.6) di samping `rata_rata_tampil` (85). Hal ini tidak konsisten dengan allowlist proyeksi itu sendiri dan dengan PRD §5.1 (tampilan rapor bilangan bulat; wali tidak melihat nilai internal). PDF sudah menampilkan 85.

Perbaikan: `rata_rata` hanya diteruskan untuk snapshot lama yang belum memiliki `rata_rata_tampil` (fallback tetap, sama seperti PDF lama). Tipe `Snapshot.rata_rata` di mobile kini opsional; tampilan sudah memakai fallback itu.

Regresi di `tests/v4_phase5_projection.php`:

- wali hanya menerima nilai tampil;
- snapshot lama tanpa `rata_rata_tampil` tetap mendapat `rata_rata` (disimulasikan dalam transaksi lalu di-rollback; assertion snapshot/hash sesudahnya tetap lulus).

Uji ini gagal pada kode sebelum koreksi dan lulus sesudahnya. Snapshot dan PDF tersimpan tidak diubah. Rantai V4 Fase 1–4 dari DB baru dan probe API 70/0 lulus dengan kode terkoreksi.

## Pemeriksaan fokus handoff

- **Capability hanya petunjuk.** `/v4/capabilities` menerapkan `no-store` (header `/v4/` global di `api/v1/index.php`) dan 401 tanpa token. Setiap endpoint tetap memeriksa cakupan. Admin mendapat petunjuk menu V4 sebagai pengawas, tetapi tidak menjadi wali (403 di layar/API/web) dan tetap wajib memberi alasan pada mutasi (regresi Fase 1–2).
- **Proyeksi wali.** Tidak ada kunci internal (gambar, aset, hash, ID kelas/rencana/tahun, sumber, kekurangan, pelaku, alasan, kapasitas, komponen) maupun `santri.id`. Snapshot dan hash tersimpan tidak berubah. Respons petugas tetap lengkap.
- **Pointer** `tipe=rapor, rapor_id` pada outbox dan InApp; validator `notificationTarget` menolak ID tak sah (unit).
- **PDF native** memakai header bearer, bukan URL bertoken, tanpa retry otomatis, dan memeriksa `%PDF-`. Cache dihapus di `finally`, saat startup, dan saat logout (Android: cache nol setelah dialog dibatalkan menurut bukti Codex; auditor tidak mengulang dialog berbagi). Risiko: `shareAsync` Android dapat selesai sebelum aplikasi tujuan selesai membaca berkas yang langsung dihapus. Ini perlu diuji pada perangkat fisik dengan aplikasi simpan nyata.
- **`phase5_static`.** Kegagalan awal berasal dari rute mobile lama `src/app/(app)/(reports)/reports.tsx`, yang dihapus redesain `0ba6e9e`. Auditor mengonfirmasi uji baseline gagal pada path itu, dan uji baru memakai rute aktual dengan assertion yang sama. Perubahan statis V2/V3 tidak melemahkan pemeriksaan (validasi kini di `src/rapor/forms.ts` dan diuji unit).
- **Riwayat input** hanya di memori. `HistoryList` menampilkan `snapshot_json` mentah kepada petugas berwenang; ini catatan UX, bukan kebocoran.

## Batas dan risiko yang tetap terbuka

- iOS runtime, perangkat fisik iOS/Android, fontScale besar native, keyboard, jaringan nyata/timeout, ketukan Push nyata dan receipt, serta cold start dengan URI pada build non-dev belum diuji.
- Smoke cPanel/FastCGI/`FilesMatch` nyata, cron/worker, log WAF/provider, beban kelas besar, UAT, dan restore produksi menunggu pelaksanaan.
- `risiko-terbuka.md` mencatat bahwa pada awal pengujian Codex, satu login akun sintetis sempat mengenai "endpoint konfigurasi lama" dari cache Metro. Auditor tidak dapat memastikan host tujuannya. **Human Developer sebaiknya memeriksa log login server produksi/staging pada 17 Oktober 2026 untuk upaya login nama akun `v4_*`.**
- Urutan rilis: web harus terpasang sebelum aplikasi. Aplikasi baru pada API lama menampilkan tombol "Muat ulang akses rapor" di Beranda untuk semua pengguna (sudah dicatat di runbook).

## Reproduksi

Perintah setup/regresi mengikuti `perintah-uji.md` dengan socket/port auditor. Probe auditor tersimpan sebagai `tests/browser/uji-v4-fase5-audit-claude-api.mjs`, `tests/browser/uji-v4-fase5-audit-claude-app.mjs`, dan `tests/v4_phase5_audit_mint_admin.php`. Ketiganya hanya untuk DB `*_test`, localhost, dan path `/private/tmp`, dan dijalankan ulang dari berkas commit (keduanya "SEMUA LULUS").

Tidak ada merge, deploy, migrasi produksi, rilis aplikasi, perubahan kanal, atau pekerjaan PRD berikutnya.
