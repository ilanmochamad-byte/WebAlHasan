# Profil pengguna dan impor Guru/Pengurus — Fase 1 (database, API, website admin)

Implementator **Claude Code**, auditor berikutnya **Codex** (`AGENTS.md`, workstream "Profil pengguna aplikasi dan impor guru/pengurus"). Sumber kebutuhan: `PRD-PROFIL-IMPORT.md` §6 Fase 1.

- Branch `claude/profil-impor-fase-1` dari `c1a5b25` (ujung `codex/prd-profil-import-spesifikasi`; isinya sama dengan `origin/main` `016f52f`, PR #111 — `git diff origin/main` kosong sebelum pekerjaan). Tanpa worktree; tidak menulis di `main`.
- Aplikasi mobile **tidak diubah** (Fase 2). Tidak ada merge, deploy, migrasi produksi, atau rilis aplikasi.
- Direktori belum terlacak milik pekerjaan lain (`docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/`, `tests/browser/tangkapan-penugasan-keamanan-f1/`) tidak disentuh.

## Keputusan Human Developer sebelum implementasi (3 November 2026)

Delapan butir `(assumption)` PRD ditanyakan lebih dulu dan **seluruhnya disetujui**; tiga pertanyaan tambahan muncul dari pemeriksaan kode. Semuanya dicatat sebagai PI-1…PI-10 di `PRD-PROFIL-IMPORT.md`:

| Kode | Keputusan |
| --- | --- |
| PI-1 | Password sementara: login aplikasi memberi **token terbatas** (hanya ganti password dan logout). |
| PI-2 | Ganti password berhasil (aplikasi **dan** website) → sesi website lain, token aplikasi lain, dan perangkat push lain dicabut; sesi yang dipakai tetap. |
| PI-3 | Admin boleh memilih satu pasangan data lama secara eksplisit; sistem hanya menyarankan calon. |
| PI-4 | NIK tepat 16 digit (teks); NIP tidak dipaksa angka. |
| PI-5 | Foto opsional JPEG/PNG/WebP ≤ 2 MiB, folder privat, endpoint berotorisasi (pemilik dan admin); tanpa foto → inisial. |
| PI-6 | Impor `.xlsx`/`.xls` dibaca sebagai nilai (seperti impor santri); foto tidak diimpor. |
| PI-7 | Data pribadi menempel pada identitas guru/pengurus/wali; guru merangkap pengurus memakai profil guru; akun tanpa identitas → pada akun. |
| PI-8 | Pengurus **baru** dari impor berjabatan "Pengurus"; jabatan lama tidak diubah. |
| PI-9 | `pengurus.nomor_identitas` = **NIP / nomor identitas**; NIK kolom baru. |
| PI-10 | Baris impor Data Pengurus yang cocok dengan identitas pengurus milik guru → konflik, ubah lewat Data Guru. |

## Ringkasan perubahan

**Basis data — migrasi aditif `035_profil_impor_fase1.sql`** (+ rollback berpasangan):

- Tabel `profil_pribadi`: satu baris per jangkar identitas (`jenis_pemilik` guru/pengurus/wali/akun, tepat satu kolom pemilik — CHECK), `nik` (16 digit, unik per jenis), tempat/tanggal lahir, alamat rumah, kelurahan, kecamatan, kabupaten, provinsi, email, `foto` (nama acak, CHECK pola), `foto_versi`, `versi`, `diubah_oleh`. FK ke `guru`, `pengurus`, `wali`, `users`.
- `users.sesi_versi INT UNSIGNED DEFAULT 0` (PI-2) dan `api_tokens.cakupan ENUM('penuh','ganti_password') DEFAULT 'penuh'` (PI-1).
- Tanpa UPDATE/DELETE/INSERT atas baris lama. Nama dan HP tetap tinggal di `guru`, `pengurus`, `wali`, `users` seperti sebelumnya.

**Layanan (`app/Profil/`)**: `ProfilService` (baca/ubah diri, foto, ganti password, penerapan identitas admin/impor), `ImporPersonelService`, `ProfilRepository`, `ProfilData` (satu aturan validasi untuk edit diri, formulir admin, impor), `FotoProfilStorage`, `ProfilException`.

**Sumber dan konsistensi identitas** (PRD §5 "Otoritas data"):

- Jangkar profil akun = guru terhubung → pengurus biasa → wali → akun. Identitas pengurus milik guru (PK-1) memakai jangkar guru; tidak pernah diberi profil sendiri.
- Setiap perubahan berhasil menyamakan nama/HP pada **seluruh** identitas terhubung (guru → identitas pengurus milik guru lewat sinkronisasi PK-1 yang sama, pengurus biasa, wali) dan `users.name/phone/email`, dalam **satu transaksi bersama audit**. Audit gagal → seluruh perubahan dibatalkan (diuji dengan trigger).
- Data Guru, Data Pengurus, dan Data Wali admin kini juga menyamakan nama/HP (dan email untuk guru/pengurus) akun terhubung.
- Status, penugasan, target kamar/kelas Murobi/Pembimbing, dan pasangan mapel–kelas dibaca dari `Capabilities::featureCapabilities` (penugasan aktif, tahun ajaran, status master) dan role akun — bukan dari masukan klien, teks Excel, atau `guru.status`.

**Autentikasi**: `Authorization::currentUser()` menghentikan sesi web yang `sesi_versi`-nya lebih lama; `ApiTokenAuthenticator` membaca `cakupan` token dan `force_password_change`; router API membatasi token terbatas/akun berpassword sementara hanya ke `POST /profile/password` dan `POST /auth/logout`. `admin/ubah_password.php` memakai `ProfilService::gantiPassword()` (aturan sama, PI-2), jejak audit lama `password_changed` tetap ditulis. Seluruh kode sadar skema: sebelum 035 / sesudah rollback perilaku lama berjalan.

**Website admin**: isian identitas pribadi + NIK di formulir/detail Data Guru dan Data Pengurus (label "NIP / nomor identitas", PI-9), foto (bila ada) lewat `admin/foto_profil.php`; tombol **Impor Excel** dan wizard `?action=impor` (unggah → pemetaan kolom → pratinjau per baris → simpan) dengan templat `proses_import_personel.php?jenis=guru|pengurus&templat=1`.

## Kontrak API (aditif; bentuk `/profile`, `/profile/identitas`, dan login lama tidak berubah)

Semua memakai `Authorization: Bearer <token>`, respons `{success, data, error}`, `Cache-Control: private, no-store`.

| Metode & jalur | Isi | Hasil |
| --- | --- | --- |
| `GET /profile/pribadi` | — | Profil pemilik token (lihat bentuk di bawah). |
| `POST` atau `PATCH /profile/pribadi` | JSON berisi sebagian isian `nama`, `tempat_lahir`, `tanggal_lahir` (YYYY-MM-DD), `alamat_rumah`, `kelurahan`, `kecamatan`, `kabupaten`, `provinsi`, `no_hp`, `email`; opsional `versi` (kontrol konflik). Kunci yang dikirim kosong mengosongkan isian itu. | 200 profil sesudah. 422 `VALIDATION_FAILED` (termasuk kunci terlarang: `username`, `roles`, `penugasan`, `kamar`, `kelas`, `mapel`, `nip`, `nik`, `*_id`, `foto`, `password`, …; `error.details` per kunci). 409 `CONFLICT` versi basi/email milik akun lain. |
| `POST /profile/foto` | `multipart/form-data`, berkas `foto` | 200 profil sesudah. 422 bukan JPEG/PNG/WebP utuh, > 2 MiB, atau tanpa berkas. |
| `POST /profile/foto/hapus` | — | 200 profil sesudah (kembali ke inisial). |
| `GET /profile/foto` | — | Berkas foto pemilik (`image/*`, `nosniff`, CSP sandbox). 404 bila tidak ada. |
| `GET /profile/foto/{userId}` | — | Pemilik sendiri atau admin aktif; selain itu **404**. |
| `POST /profile/password` | `password_saat_ini`, `password_baru`, `konfirmasi_password`, opsional `device_id` (perangkat push yang dipertahankan) | 200 `{sesi_versi, token_lain_dicabut, perangkat_dicabut, wajib_ganti_password:false}`. 422 per isian (salah/lemah/beda; password baru maksimal 72 byte dan tanpa NUL). Token yang dipakai tetap dan menjadi `penuh`. |
| `POST /auth/login` | tambahan opsional `izinkan_password_sementara: true` | Akun berpassword sementara: tanpa penanda → 403 `PASSWORD_CHANGE_REQUIRED` (perilaku lama); dengan penanda → 200 `{token, token_type, expires_at, password_change_required:true, token_scope:"ganti_password", profile:{id,name,username}}` (tanpa capability, berlaku 1 hari). |
| Endpoint lain dengan token terbatas, atau akun yang wajib ganti password | — | 403 `PASSWORD_CHANGE_REQUIRED`. |

Bentuk `GET /profile/pribadi`:

```json
{
  "akun": {"id": 12, "username": "…", "peran": ["guru", "pengurus"]},
  "sumber": "guru",
  "pribadi": {"nama": "…", "nip": "…|null", "nik": "…|null", "no_hp": "…|null", "tempat_lahir": null, "tanggal_lahir": null,
              "alamat_rumah": null, "kelurahan": null, "kecamatan": null, "kabupaten": null, "provinsi": null, "email": null},
  "foto": {"ada": false, "versi": 0, "url": null, "inisial": "AB"},
  "versi": 0,
  "status": [{"kode": "guru", "label": "Guru", "jenis": "peran"}, {"kode": "murobi", "label": "Murobi", "jenis": "penugasan"}],
  "penugasan": [{"jenis": "murobi", "label": "Murobi", "identitas": "guru", "rincian": [{"id": 5, "tahun_ajaran": {"id": 1, "nama": "2026/2027 Ganjil"},
                 "target": {"tipe": "Kamar", "id": 2, "nama": "…"}, "mata_pelajaran": null, "kelas": null, "jenjang": null, "gelombang": null,
                 "tanggal_mulai": "…", "tanggal_selesai": null}]}],
  "binaan": [{"peran": "Murobi", "jenis": "murobi", "tipe": "Kamar", "id": 2, "nama": "…"}],
  "mengajar": [{"mata_pelajaran_id": 56, "mata_pelajaran": "Fiqih", "kelas_id": 1, "kelas": "…"}],
  "dapat_diubah": ["nama", "tempat_lahir", "…"],
  "wajib_ganti_password": false
}
```

- `status` peran: Admin (role), Guru (role + guru aktif), Pengurus (role + pengurus biasa sah; identitas pengurus milik guru tidak ditampilkan sebagai "Pengurus" kedua), Orang Tua (role + wali sah). Lalu satu entri per jenis penugasan aktif (Murobi, Pembimbing, Guru Mata Pelajaran, Bagian Pendidikan, Petugas Kesehatan, Petugas BSA, Keamanan, Panitia PSB, Bendahara PSB).
- `binaan.tipe` = `Kamar` atau `Kelas` (label untuk kartu Fase 2). Akun tanpa target → `[]`, tidak ada nama kamar rekaan.
- Nama berkas foto tidak pernah dikirim; `foto.versi` dipakai klien untuk memuat ulang.

## Impor Excel (Data Guru / Data Pengurus)

Berkas dibaca di peramban (SheetJS `xlsx@0.18.5`, nilai mentah, `cellFormula: false`), dikirim sebagai baris berkunci kanonis. Server (`ImporPersonelService`) memakai **analisis yang sama** untuk pratinjau dan simpan:

- Kolom: ID Sistem opsional, Nama Lengkap, NIP, NIK, (atau satu kolom "NIP atau NIK" yang dikenali otomatis: 16 digit → NIK), Tempat/Tanggal Lahir, Alamat Rumah, Kelurahan/Desa, Kecamatan, Kabupaten/Kota, Provinsi, No. HP, Email, Tugas, Kamar/Kobong, Tugas Tambahan. Pemetaan judul ditebak dan dapat diubah admin.
- Tindakan per baris: **Tambah baru**, **Perbarui** (mengisi kosong), **Tidak berubah**, **Perlu konfirmasi** (menimpa nilai lama terisi → centang "Timpa nilai lama"; NIP/NIK lama berbeda → centang "Ganti NIP/NIK"), **Pilih pasangan** (nama sama tanpa NIP/NIK cocok → radio calon atau "Tambah baru"), **Konflik**.
- Konflik: tanpa NIP dan NIK, NIK bukan 16 digit, NIP/NIK/HP ganda di dalam berkas, NIP dan NIK menunjuk dua orang berbeda, dua baris menunjuk orang yang sama, NIP/NIK tercatat ganda di data lama, identitas pengurus milik guru pada impor pengurus (PI-10), nama guru di luar latin1, sel angka yang kehilangan presisi atau notasi ilmiah.
- Sel kosong tidak pernah mengosongkan nilai lama. HP numerik tanpa nol awal dipulihkan; nomor seri tanggal Excel dan HH/BB/TTTT dibaca.
- Simpan: seluruh baris dianalisis ulang di bawah kunci baris; sidik pratinjau yang berbeda (data berubah, pilihan berubah, atau baris belum diperiksa) → konflik, tidak menimpa. Seluruh simpan **satu transaksi** + audit ringkasan `impor_personel.simpan` (jumlah, sidik dan ukuran berkas) dan per baris `profil.impor_baris` (ID, tindakan, nama isian yang berubah). Kesalahan basis data/audit → tidak ada yang tersimpan.
- Tugas/Kamar/Tugas Tambahan hanya tampil di pratinjau. Tidak ada akun, username, role, token, atau penugasan yang dibuat/diubah. Batas 500 baris dan 5 MB.

## Hasil uji implementator (`bukti/uji/`, `perintah-uji.sh`)

Hasil independen dan koreksi sesudah handoff dicatat di [audit Codex](AUDIT-CODEX.md).

Sidik berkas yang dibuat/diubah: `bukti/sha256.txt`. Lingkungan: PHP 8.4.14, MariaDB 12.3.2 lokal, Chromium (Playwright), DB `test_*_test` berfixture sintetis. Tidak ada data pribadi nyata.

| Suite | Hasil | Cakupan |
| --- | --- | --- |
| Statis `tests/profil_impor_f1_static.php` | **71/0** | lint 27 berkas, migrasi aditif, rollback berpenjaga + pencabutan token terbatas, tanpa tulis penugasan/role/akun/token, saringan audit, penjaga API, foto privat, UI `textContent`, PI-1…PI-10 tercatat, batas berkas |
| Integrasi `tests/profil_impor_f1_integration.php` | **99/0** | I-1…I-10 (lihat kepala berkas): baca empat kelompok akun + guru merangkap, tugas diakhiri hilang, ubah diri setiap isian & konsistensi guru/identitas pengurus/akun/wali, 18 kunci terlarang, validasi, versi, email, audit gagal tanpa sebagian, akses foto, password (salah/lemah/beda/berhasil/audit gagal), impor pratinjau/simpan/ulang/konflik/pasangan/timpa/pengenal/PI-10/sidik basi/audit gagal, penugasan & role identik, formulir admin |
| Migrasi `tests/profil_impor_f1_migration.php` | **26/0** | sebelum 035 (503, login & Data Guru jalan), naik dengan sidik isi seluruh kolom lama 16 tabel identik, jalankan ulang, CHECK/FK/UNIQUE/ENUM, rollback ditolak bila ada profil, rollback saat kosong mencabut token terbatas, naik lagi |
| HTTP `tests/profil_impor_f1_http.php` | **76/0** | REST profil empat akun, `/profile` lama tetap, kunci terlarang, PATCH tanpa login ulang, foto unggah/ganti/hapus + PHP-sebagai-PNG/SVG/GIF/>2 MB ditolak + akses lintas akun 404 + folder privat 403, password API → token lain 401 + sesi web lain keluar, password sementara (klien lama 403; token terbatas hanya ganti password/logout; token penuh lama ikut dibatasi; token yang menjadi penuh memperoleh masa berlaku biasa), web ganti kata sandi, impor lewat HTTP + CSRF + non-admin, halaman/formulir admin, log server bersih |
| Browser `tests/browser/uji-profil-impor-f1.mjs` | **44/0** | 375/768/1280 px: wizard impor guru (pemetaan, pratinjau, simpan, ulang = tidak berubah), markup berkas tampil sebagai teks, formulir identitas, pilih pasangan + Simpan tertahan sampai periksa ulang, impor pengurus, tanpa JavaScript, tanpa galat JS/dialog bawaan |

**Kontrol kode lama** (klon `origin/main` `016f52f`, berkas uji baru disalin): statis **21/48**, integrasi berhenti di awal (`Call to undefined function profil_service()`), `bukti/kontrol-baseline/uji-*.txt`.

## Regresi (`bukti/regresi/` vs `bukti/kontrol-baseline/regresi/`, `perintah-regresi.sh`)

`perintah-regresi.sh` dijalankan berurutan pada kode kini lalu pada klon `origin/main` `016f52f` (suite, urutan, dan rantai fixture sama; DB berawalan berbeda). **Seluruh 15 suite non-statis identik exit dan hitungannya dengan baseline:**

| Suite | Kini = baseline |
| --- | --- |
| Pesan kredensial / akun (reset password lama): integrasi · web | 59/0 · 51/0 |
| Identitas pengurus milik guru (penugasan Fase 1): integrasi · HTTP | 70/0 · 34/0 |
| REST Keamanan + `/profile/identitas` (penugasan Fase 4) | 66/0 |
| V1 master data (Fase 2) · API absensi (Fase 4) · perapihan wali/akun | 12/0 · 14/0 · 52/0 |
| Kelas gabungan Fase 1: integrasi · HTTP | 71/0 · 32/0 |
| HTTP redesain Tahap 2 (navigasi, ganti kata sandi) · 3 (halaman lama) · 6 (data pesantren) | 185/6 · 166/1 · 125/7 |

Tahap 2 185/6 (peta menu BB–BE), Tahap 3 166/1 ("AG belum dipasang") dan Tahap 6 125/7 (rollback menganggap 023 terakhir; di sini rollback 035 kosong berhasil lebih dulu) adalah oracle historis yang sudah tercatat dan identik dengan baseline.

Catatan urutan: regresi dijalankan sebelum dua koreksi kecil sesudahnya — masa berlaku token yang menjadi penuh (`ProfilRepository::jadikanTokenPenuh`) dan `require_once _guard.php` pada dua fragmen admin baru (ditemukan oleh `phase1_static`). Keduanya tercakup uji final di atas; `phase1_static` diulang sesudah koreksi: **81/0**.

**Statis (52 suite kini, termasuk suite baru):** 17 exit 0 (setelah `phase1_static` diulang), 33 exit 1, 2 exit 77. Klon baseline bukan repo Git dan tanpa repo mobile tetangga, sehingga hitungan statis tidak sebanding langsung; setiap judul kegagalan kode kini diperiksa:

1. **Migrasi 035 sengaja ditambahkan** — "033/034/031/029 migrasi terakhir", "database/ tidak berubah", manifest rilis berjumlah tetap (penugasan Fase 5, kelas gabungan Fase 1, Tahap 4/8, V5–V7, tagihan satu santri).
2. **Batas berkas fase lama** — "hanya berkas fase X yang berubah", "app/Auth, app/Audit, api/, bootstrap tidak berubah" kini memuat berkas Fase 1 ini (autentikasi sesi/token, audit profil, rute profil, pabrik layanan). Termasuk satu yang baru terlihat: V6 Fase 5 "bootstrap.php hanya menambah pabrik laporan_bsa_service()".
3. **Oracle historis yang sudah gagal pada handoff kelas gabungan Fase 1** (judul sama persis di `docs/jadwal-kelas-gabungan/fase-1/bukti/regresi/`): V2 Fase 2 tumpang tindih, V5 Fase 1 validasi/tombol, V6 Fase 1–2, V7 Fase 1/2/4, Tahap 4 penanda baca-semua, Tahap 6 jenis modul mendatang, Tahap 7 penugasan, `phase3_static` lokasi SQL peserta, AG keamanan PSB/Tahap 3.
4. **V2 Fase 5 versi Expo/React Native** — membaca repo mobile tetangga yang berisi perubahan paket belum di-commit milik pekerjaan lain.

Tidak ada oracle yang dilonggarkan.

## Penafsiran implementator (menunggu tinjauan auditor)

- **PF-1** `force_password_change` membatasi **semua** token akun (termasuk token penuh lama sesudah admin mereset password), selaras dengan website yang memaksa ke halaman ganti kata sandi. Sebelumnya token lama tetap penuh.
- **PF-2** Data Guru/Data Pengurus/Data Wali admin kini juga menyamakan `users.name/phone` (dan email guru/pengurus) akun terhubung — perilaku baru yang dituntut PRD §5. Nilai akun lama yang sudah berbeda tidak diubah sampai ada perubahan identitas yang berhasil.
- **PF-3** Akun dengan dua identitas master (mis. guru yang juga wali): nama/HP disamakan ke semua; isian tambahan disimpan pada jangkar guru saja, sehingga panel Data Wali tidak menampilkannya.
- **PF-4** Bila orang tua mengubah alamat, kolom lama `wali.alamat` diisi alamat lengkap tersusun (rumah, kelurahan, kecamatan, kabupaten, provinsi).
- **PF-5** HP ganda **di dalam berkas** = konflik; HP yang sama dengan data lama tidak diperiksa (bukan pengenal).
- **PF-6** Perbedaan ejaan nama (termasuk huruf besar/kecil) pada pasangan terpilih dihitung menimpa nilai lama dan meminta konfirmasi "timpa".
- **PF-7** Impor: sel kosong tidak mengosongkan. Formulir admin: isian kosong mengosongkan (formulir lengkap). Edit diri: hanya kunci yang dikirim.
- **PF-8** NIK unik per jenis pemilik; NIK sama antara guru dan pengurus biasa (orang yang sama tercatat ganda, keputusan 30 Oktober "peringatan saja") hanya diperingatkan.
- **PF-9** Satu simpan impor = satu transaksi; baris konflik dilewati dan dilaporkan, kesalahan basis data/audit membatalkan seluruh simpan.
- **PF-10** Token terbatas berlaku 1 hari; klien harus mengirim `izinkan_password_sementara: true` (aplikasi lama tidak berubah perilaku).
- **PF-11** Ganti password lewat aplikasi mempertahankan perangkat push dengan `device_id` yang dikirim; tanpa `device_id` (dan lewat website) seluruh perangkat push akun dicabut dan aplikasi perlu mendaftar ulang.
- **PF-12** Admin tidak mengunggah foto (PI-5: pemilik akun); foto lama dihapus dari disk saat diganti/dihapus.
- **PF-13** Nama guru wajib terwakili latin1 (tabel `guru` V1 latin1) agar tidak rusak diam-diam; ditolak dengan pesan pada edit diri, formulir, dan impor.
- **PF-14** Audit `profil.*`/`impor_personel.*` hanya memuat ID, nama isian, versi, dan jumlah. Audit lama `master.update` Data Guru/Pengurus tetap menyimpan baris sebelum/sesudah (perilaku yang sudah ada).
- **PF-15** Token terbatas yang dipakai mengganti password menjadi token penuh dengan masa berlaku token biasa (`api.token_ttl_days`) sejak saat itu.

## Batas pengujian

- **MariaDB 10.6.28 produksi, MySQL, cPanel, dan UAT belum diuji.** Migrasi 035 belum dijalankan di produksi.
- Pembacaan `.xlsx`/`.xls` nyata oleh SheetJS di peramban **tidak** diuji otomatis: uji browser memakai pembaca CSV berantarmuka sama (pola uji Tahap 6); konversi nilai Excel (nomor seri tanggal, HP numerik, presisi) diuji di server.
- Server uji `php -S` + router (folder `storage/` 403) meniru `.htaccess`; aturan Apache hosting belum diperiksa.
- Aplikasi mobile tidak diubah; kartu Beranda, Profil, Edit Profil, dan Ganti Password aplikasi adalah Fase 2. Uji API memakai klien HTTP.
- Kasus produksi "satu orang tercatat sebagai guru dan pengurus berakun" tidak digabung; impor hanya memperingatkan.

## Panduan rilis dan pemulihan

1. Backup penuh terverifikasi (dump + uji pulih pada salinan).
2. Uji 035 pada salinan `_test`: `DB_NAME=<salinan> php bin/migrate.php up`, bandingkan jumlah/ID/hash tabel lama. Suite `tests/profil_impor_f1_migration.php` hanya untuk DB fixture **sebelum 035** (`PIF1_TANPA_035=1 PIF1_DB=test_pif1_mig_test bash tests/profil_impor_f1_db.sh`), bukan salinan produksi.
3. Pasang kode **bersama** migrasi 035. Kode sadar skema: tanpa 035 fitur profil melapor "belum dipasang" dan perilaku lama berjalan.
4. Pastikan folder `storage/private/profil/` dapat ditulis PHP dan tetap di bawah `storage/private/.htaccess` (`Require all denied`).
5. Sesudah rilis: sesi website lama tetap berlaku (versi 0) sampai pemiliknya mengganti password.

Pemulihan: rollback 035 hanya berhasil selama `profil_pribadi` kosong dan semua `users.sesi_versi` bernilai 0 (penjaga CHECK); token `ganti_password` yang masih berlaku dicabut lebih dulu. Bila profil sudah berisi atau versi sesi sudah dinaikkan, pulihkan dari backup atau buat migrasi korektif — jangan kembali ke kode lama sambil mempertahankan token terbatas aktif. Berkas foto tidak pernah dihapus rollback.

## Handoff audit Codex

Periksa: keputusan PI-1…PI-10 dan penafsiran PF-1…PF-15; uji independen migrasi/rollback pada salinan, impor baru/lama/ulang/konflik, konsistensi guru–pengurus–akun, hak akses profil/foto lintas akun, kunci terlarang, unggahan berbahaya, password dan token terbatas, audit gagal, serta regresi. Jangan mulai Fase 2, merge, atau deploy.
