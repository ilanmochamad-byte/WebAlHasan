# Perbaikan peserta absensi pengajian

Tanggal: 6 November 2026 · Implementator: Claude Code · Auditor berikutnya: Codex

## Laporan dan penyebab

Human Developer melaporkan dua hal di aplikasi: santri yang di website sudah keluar/pindah masih muncul di absensi pengajian, dan satu kelas tercatat 8 santri di website tetapi 6 di aplikasi.

Penelusuran kode (belum diperiksa pada data produksi):

| Penyebab | Lokasi sebelum perbaikan | Akibat |
| --- | --- | --- |
| Peserta pertemuan dibekukan saat dibuka dan tidak pernah diperbarui | `App\Schedule\PesertaPertemuan::bekukan` | Perubahan penempatan sesudah pertemuan dibuka tidak ikut, di aplikasi maupun halaman pertemuan website |
| Pembekuan tidak memeriksa status santri | `PesertaPertemuan::bekukan` (hanya `plotting_kelas.status = 'Aktif'`) | Santri nonaktif/arsip yang penempatannya masih Aktif ikut setiap pertemuan baru |
| "Nonaktifkan/Arsipkan" di Data Santri hanya mengubah `santri.is_active/archived_at` | `MasterDataRepository::santriSetState` | Penempatan kelas tetap Aktif; halaman Penempatan menyembunyikan santri itu, absensi tetap memuatnya |
| Jumlah anggota Data Kelas ikut menghitung santri nonaktif | `MasterDataRepository::classesPage` | Angka website sendiri berbeda antarhalaman |

Menu Luluskan/Mutasi (`AlumniService`) sudah menutup penempatan dengan benar dan tidak diubah.

## Keputusan (persetujuan Human Developer 6 November 2026)

1. Pertemuan baru hanya memuat santri aktif dan tidak diarsipkan.
2. Nonaktifkan/Arsipkan di Data Santri menutup seluruh penempatan kelas Aktif santri itu (status `Selesai`, tanggal selesai hari ini) dalam transaksi yang sama, dengan audit memuat ID penempatan yang ditutup. Baris tetap ada sebagai riwayat. Aktifkan/Pulihkan tidak membuka penempatan lagi; santri ditempatkan ulang lewat menu Penempatan (sama dengan pembatalan kelulusan/mutasi). Penempatan kamar tidak diubah.
3. "Perbarui daftar peserta" untuk pertemuan berstatus **Dibuka** (website dan aplikasi):
   - kelas pertemuan tetap kelas yang dibekukan saat dibuka; kelas yang ditambahkan ke jadwal sesudahnya tidak ikut;
   - santri aktif yang kini ditempatkan di kelas itu ditambahkan;
   - peserta yang tidak lagi memenuhi syarat dikeluarkan bila absensinya belum tercatat; yang sudah berabsensi tetap tercantum dan absensinya tidak dihapus;
   - pertemuan Draf (belum punya peserta) dan Selesai (riwayat) ditolak;
   - dijalankan di dalam transaksi yang mengunci baris pertemuan, sama dengan penyimpanan absensi, sehingga keduanya berurutan; diaudit `meeting.refresh_participants` (web) / `api.meeting.refresh_participants` (API).
4. Jumlah anggota Data Kelas hanya menghitung santri aktif.

## Perubahan

Web (`claude/perbaikan-peserta-absensi` dari `origin/main` `71dbc00`), tanpa migrasi:

- `app/Schedule/PesertaPertemuan.php` — saringan santri aktif pada pembekuan; `perbarui()`.
- `app/Schedule/ScheduleService.php`, `ScheduleRepository.php`, `admin/admin_pengajian.php`, `admin/_pengajian_pertemuan.php` — aksi web `refresh_participants` (POST + CSRF, konfirmasi) dan teks bantuan.
- `app/Api/TeacherService.php`, `TeacherRepository.php`, `api/v1/index.php` — endpoint aditif `POST /api/v1/meetings/{id}/participants/refresh`. Respons = isi `GET /meetings/{id}` ditambah `participant_changes: {added, removed, kept_with_attendance}` (ID santri). Tanpa kunci idempotensi karena hasilnya ditentukan keadaan data (pengiriman ulang aman). Galat: 404 tidak ditemukan, 403 bukan miliknya, 409 `MEETING_NOT_OPEN`.
- `app/MasterData/MasterDataService.php`, `MasterDataRepository.php`, `admin/admin_master_santri.php` — butir 2 dan 4, teks konfirmasi.
- `bin/tutup_penempatan_santri_nonaktif.php` — merapikan data lama (lihat Rilis).

Mobile (`claude/perbaikan-peserta-absensi` dari `origin/main` `e21d4ab`), versi **1.3.0 → 1.4.0**:

- `src/app/meeting/[id].tsx` — tombol "Perbarui daftar" pada pertemuan Dibuka; isian guru untuk santri yang tetap tercantum dipertahankan, santri baru "Hadir"; pesan ringkas hasil. Tombol kirim dinonaktifkan selama pembaruan berjalan.
- `src/lib/perbarui-peserta.ts`, `src/api/client.ts`, `src/api/types.ts`, `tests/perbarui-peserta.test.ts`.
- Tanpa dependensi atau modul native baru.

## Bukti

PHP 8.4 dan MariaDB 12.3 lokal, fixture sintetis (`tests/jadwal_kelas_gabungan_f1_db.sh` + seed kelas gabungan). Berkas di `bukti/`.

| Uji | Hasil |
| --- | --- |
| Integrasi baru `tests/perbaikan_peserta_absensi_integration.php` (P-1…P-7: web, API, Data Santri, Data Kelas, alat rapikan) | **36/0** (`bukti/integrasi.txt`) |
| Kontrol: uji yang sama pada kode `origin/main` `71dbc00` (`git archive`) | 2 lulus, 9 gagal, lalu berhenti karena `refreshParticipants()` belum ada (`bukti/kontrol-baseline-main.txt`) |
| Browser `tests/browser/uji-perbaikan-peserta-absensi.mjs`: Expo web aplikasi 1.4.0 (salinan tanpa `.env*`, lock ter-commit Expo 57.0.15, bundel tanpa host produksi) + website, 375 px | **18/0** (`bukti/browser-expo-web-dan-website.txt`, `bukti/tangkapan/`) |
| Mobile unit (`node --test tests/*.test.ts`) | **80/0** |
| Mobile `tsc --noEmit` dan `expo lint` pada salinan bersih | exit 0 / exit 0 |
| `npm run versi:periksa -- --kerja` | LULUS 1.3.0 → 1.4.0 (minor) |

Regresi web (`docs/profil-impor/fase-1/perintah-regresi.sh`, ringkasan `bukti/regresi/ringkasan.txt`) dibandingkan dengan bukti regresi terakhir (`docs/profil-impor/fase-1/bukti/regresi/ringkasan.txt`):

- Sama persis: kredensial 59/0 · 51/0, penugasan Fase 1 70/0 · 34/0, Fase 4 HTTP 66/0, V1 master data 12/0, API absensi V1 14/0, perapihan 52/0, kelas gabungan integrasi 71/0 dan HTTP 32/0, redesain HTTP Tahap 2/3/6 185/6 · 166/1 · 125/7 (oracle historis), statis alumni 180/0, penempatan 138/0, phase2 46/0, phase3 34/1 dan kelas gabungan 44/3 (oracle historis lokasi SQL peserta dan batas berkas).
- Berbeda: `profil_impor_f1_static` 71/0 → 70/1 — oracle batas berkas fase profil kini mencantumkan berkas perbaikan ini; tidak dilonggarkan. `penugasan_keamanan_f4_static` +1 gagal "satu-satunya isian kolom cari" sudah ada sejak pencarian santri (PR #116, dicatat di AGENTS.md), bukan dari perubahan ini. `redesain_web_tahap6_static` 139/2 → 140/1 (perubahan di `main` sebelumnya). Jumlah lulus beberapa statis penugasan Keamanan naik karena lint mencakup berkas PHP baru.

## Rilis dan pemulihan

1. Deploy kode web seperti biasa (tanpa migrasi).
2. Lihat dulu data lama yang terdampak (hanya membaca):
   `php bin/tutup_penempatan_santri_nonaktif.php`
3. Bila daftar sesuai, tutup penempatannya: `php bin/tutup_penempatan_santri_nonaktif.php --terapkan`. Setiap santri satu transaksi + satu audit `master.santri_penempatan_ditutup`; status santri, kamar, absensi, dan pertemuan lama tidak diubah.
4. Pertemuan Dibuka yang daftar pesertanya keliru dapat diperbarui lewat tombol. Pertemuan Selesai tetap seperti tercatat.
5. Aplikasi 1.4.0 memerlukan build baru. Aplikasi lama tetap berfungsi (endpoint bersifat aditif), hanya tanpa tombol.

Pemulihan: kembalikan kode web ke commit sebelumnya. Penempatan yang sudah ditutup dapat dibuka kembali dengan menempatkan ulang santri lewat menu Penempatan; ID penempatan yang ditutup tercatat di audit.

## Batas

- Penyebab di produksi belum dipastikan dengan data; kueri pemeriksaan read-only telah diberikan kepada Human Developer.
- Diuji pada PHP lokal dan MariaDB lokal dengan fixture sintetis; MariaDB 10.6 produksi, cPanel, perangkat fisik, build Android/iOS baru, dan UAT belum diuji.
- Aplikasi diuji lewat Expo web (Chromium 375 px), bukan perangkat native.
- Modul lain yang memakai penempatan kelas (izin, pembinaan, rapor, kesehatan, BSA) tidak diubah. Penutupan penempatan pada butir 2 dan alat rapikan membuat santri nonaktif tidak lagi terhitung di modul itu melalui penempatan Aktif; pemeriksaan menyeluruh per modul belum dilakukan.
