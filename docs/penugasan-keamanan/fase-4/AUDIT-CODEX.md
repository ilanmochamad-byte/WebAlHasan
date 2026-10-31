# Audit Codex — penugasan lintas guru/pengurus dan Keamanan Fase 4

**31 Oktober 2026 — LULUS audit teknis lokal setelah satu koreksi terarah P3.**
Tidak ada temuan Fase 4 yang masih memblokir pada lingkungan yang diuji.
Runtime iOS, perangkat fisik dan validasi produksi belum dinyatakan lulus.
Fase 5 tidak dimulai; tidak merge, deploy, migrasi produksi atau rilis aplikasi.

## Cakupan dan baseline

- Web: branch `claude/penugasan-keamanan-fase-4`, seluruh commit fase
  `933f8a153481dbb402baa2b82a74354cf4fc7da5`, di atas audit Fase 3 `6ebdc24`.
  Remote main terverifikasi `fcfbd939b2e9372c2964b34ecb542178e70e8a3e`;
  `git diff 6ebdc24 origin/main` kosong.
- Mobile: branch yang sama, seluruh commit fase
  `10d13c378022b1feda0a51a2722d497c35fdf1b7`, di atas remote main
  `367ae5d103c8e6a551b3d45bb09653f70b4c728f`. Kedua branch remote sama
  dengan handoff ketika audit dimulai (`git ls-remote`).
- Dibaca: `AGENTS.md` kedua repo, rancangan dan seluruh keputusan PK,
  audit Fase 3, diff kode/uji kedua repo, README dan bukti implementator.
  Ditinjau sembilan rute GET Keamanan, endpoint identitas, pemanggilan layanan
  web, proyeksi respons, penjaga/audit, seluruh layar/klien dan profil aplikasi.
- PHP 8.4.14/MariaDB 12.3.2 lokal, fixture sintetis; Node/Chromium Playwright;
  Android AVD Medium_Phone dengan dev client Debug yang sudah terpasang dan
  Metro dari salinan `git archive 10d13c3`. Package/lock ter-commit
  (Expo 57.0.15), `node_modules` direktori nyata, tanpa `.env` lokal/produksi.
- Tanpa worktree/subagen. Empat direktori web pekerjaan lain dan perubahan
  `package.json`/`package-lock.json` mobile tidak disentuh atau dimasukkan commit.

## F-1 · P3 — query kehadiran berbentuk array tidak ditolak sebelum cast

`GET /keamanan/kehadiran?date_from[]=2026-01-01` diteruskan ke
`ReportFilter` lama yang mengharapkan skalar. Pada konfigurasi lokal dengan
warning ditampilkan, hasilnya 422 disertai warning HTML sehingga bukan JSON
valid; beberapa field lain justru dikonversi menjadi saringan bernilai lain.
Tidak ditemukan perluasan hak atau kebocoran isi kasus dari masalah ini.

**Koreksi:** setelah hak Keamanan diperiksa, kesepuluh field saringan
kehadiran yang dikenal wajib skalar sebelum memanggil `ReportService`.
Nilai non-skalar menghasilkan JSON 422. Perubahan hanya pada
`KeamananApiService`; layanan laporan umum, `/reports`, schema, hak, kode
mobile dan respons valid tidak diubah.

Probe final identik `tests/browser/uji-penugasan-keamanan-f4-api-probe.mjs`
terhadap arsip commit Claude `933f8a1`: **31/10**; terhadap koreksi: **41/0**.
Kesepuluh kegagalan kontrol adalah kesepuluh field array di endpoint baru.
Kontrol dan final dijalankan berurutan pada fixture yang sama, lewat dua
server localhost; bukan perbandingan hasil statis semata.

## Pengujian independen

Angka berarti **lulus/gagal**. Bukti auditor di `audit-codex/`, terpisah dari
`bukti/` implementator.

| Rangkaian | Hasil | Bukti |
| --- | --- | --- |
| Statis Fase 4 dan lint PHP | **35/0** | `statis-final.txt` |
| HTTP REST sebelum/sesudah koreksi | **66/0 · 66/0** | `http.txt`, `http-final.txt` |
| Probe tambahan hak/metode/query/preservasi/token | **41/0**, kontrol **31/10** | `probe-api-final.txt`, `probe-api-kontrol-claude.txt` |
| Expo web 375px, lima akun | **46/0** | `expo-web.txt` |
| TypeScript/lint, paket ter-commit | **exit 0 / exit 0** | `mobile-checks.txt` |
| Unit aplikasi | **47/0** | `unit.txt` |
| Android emulator: baca, kerahasiaan, latar/revokasi, FLAG_SECURE | **8/0** | `android.txt` |
| Android emulator: ubin dan profil guru dua identitas | **2/0** | `android-identitas.txt` |
| Android screencap dan scan logcat | **lulus pada sampel** | `android-privasi.txt` |

HTTP memakai bearer token sungguhan dari login fixture. Menguji sumber
penugasan sendiri, admin tanpa tugas, kedua asal izin, Rahasia/nonaktif/tidak
ada, kesamaan daftar/angka dengan layanan web dan oracle SQL, empat rincian
dengan audit wajib, trigger gagal audit → 503 tanpa isi, pencabutan pada token
lama, identitas guru/pengurus, serta bentuk `/profile` dan login lama.

Probe tambahan menguji pencabutan role Pengurus pada akun guru yang masih
memiliki role Guru, master Pengurus nonaktif, Guru diarsipkan, identitas
pengurus orang lain, tanggal penugasan belum mulai, semester `Non-Aktif`,
admin pengawasan vs admin dengan tugas sendiri, saringan asing, HEAD/OPTIONS
dan empat metode mutasi pada delapan rute data, checksum seluruh tabel bisnis
serta jumlah audit Keamanan, dan token setelah logout. Penyajian kehadiran
`gabungan` mengikuti fasilitas web; santri nonaktif tetap disaring. Endpoint
`/reports` lama untuk guru tetap hanya jadwalnya sendiri (HTTP R-7).

Expo web memeriksa ubin/menu, akses langsung tanpa hak, daftar/rincian semua
bagian, audit rincian, penutupan data/ubin setelah pencabutan dari latar,
profil dua identitas, nol request mutasi Keamanan dan nol request di luar
localhost, serta penyimpanan/konsol tanpa penanda isi. PNG rincian izin dan
profil guru ditinjau visual. TF4-1 (bilah tab guru meluap 33px di web sejak
baseline) tetap catatan nonblokir; kontrol baseline adalah bukti implementator,
tidak diulang auditor. Layar Keamanan yang diuji tidak meluap.

Android memakai API fixture localhost melalui `adb reverse`, bukan server
produksi. Rincian izin menampilkan alasan lengkap, daftar konseling hanya
Internal, dan pencabutan dari latar menutup isi. FLAG_SECURE dipasang selama
navigator Keamanan dan dilepas setelah kembali ke Beranda. Dekoder PNG
memastikan screencap rincian **2.592.000/2.592.000 piksel hitam**, dengan kontrol
Beranda bergambar normal; sampel 73.850 baris logcat tanpa `PKF3`, `PRIVATE-T5`
atau bearer 64-heksa mentah. Ini tidak membuktikan privasi semua log/OS atau
perangkat fisik. Gambar/logcat lengkap tetap lokal, tidak di-commit.
Pergantian akun K → G juga membuktikan ubin dan bagian profil dua identitas
beserta tugas Keamanan milik G pada native.

## Regresi

Rangkaian implementator `perintah-regresi.sh` dijalankan ulang auditor sampai
**SELESAI (exit 0)** dengan `PFX=pkf4audit` dan keluaran `audit-codex/regresi/`.
Seluruh **49 suite non-statis dan 49 statis mempunyai exit/hitungan identik
dengan log handoff**; tidak mengubah atau melonggarkan oracle historis.
Pembanding ini adalah log implementator, bukan klaim auditor mengeksekusi
ulang baseline Fase 3 untuk seluruh regresi. Kontrol baru auditor sendiri
terhadap arsip Claude Fase 4 hanya untuk probe F-1 di atas.

| Kelompok | Hasil auditor |
| --- | --- |
| Penugasan Fase 1 integrasi/probe/token/HTTP | **70/0 · 20/0 · 10/0 · 34/0** |
| Penugasan Fase 2 integrasi/HTTP | **53/0 · 28/0** |
| Fase 3 integrasi/HTTP/migrasi/probe audit | **44/0 · 44/0 · 19/0 · 26/0** |
| REST aplikasi V5/V6/V7 Fase 4 | **43/0 · 63/0 · 54/0** |
| Fondasi penugasan; V2 integrasi Fase 1/2/4/5; konkurensi | **161/0; 39/0 · 94/0 · 124/0 · 145/0; 20/0** |
| V7 Fase 3 integrasi/notifikasi/HTTP; Fase 2 integrasi/HTTP/probe | **112/0 · 49/0 · 43/0; 119/0 · 61/0 · 34/0** |
| V3 integrasi Fase 2/3/4/5 dan akses | **67/0 · 121/0 · 65/0 · 43/0 · 5/0** |
| HTTP redesain Tahap 4/5/7 | **132/0 · 106/0 · 122/0** |

Kegagalan historis yang tetap dicatat: V7 Fase 5 E2E **82/1** (preflight
mengunci migrasi lama), V7 Fase 1 **73/2**, V2 API Fase 3/4 **95/21 · 91/1**,
HTTP Tahap 2 **185/6** dan Tahap 6 **125/7**. Exit, hitungan dan pesan gagal
non-statis sama dengan handoff; tidak dinyatakan sebagai suite lulus.
Statis **18 exit 0, 29 exit 1, dua exit 77**. Pada 13 pesan batas berkas fase
lama, judul assertion tetap sama tetapi daftar berkas Git yang dicetak
berbeda karena commit Fase 4 serta bukti/tes audit kini ikut diperiksa.
Oracle aslinya tetap utuh, bukan diubah menjadi hijau.
Hasil lengkap di `regresi/ringkasan.txt` dan `perbandingan-regresi.txt`.

Probe Fase 2 pada orkestrator warisan berhenti pada penyiapan (5/0 tanpa
`PKF2_BASELINE`, V4 0/0 tanpa integrasi). Keduanya diulang pada dua salinan
terpisah sesudah integrasi Fase 2 dengan arsip `464d9a1`: **24/0 dan 9/0**.
Log awal dan ulangan dipertahankan; bukan kegagalan aplikasi baru.

## Penafsiran dan batas

PF4-1…PF4-8 diterima dalam lingkup lokal Fase 4: prefiks aditif Keamanan,
cetak/unduhan berjejak tetap melalui website, proteksi native sebelum data,
rute Keamanan memilih kapasitas baca sendiri (tidak memperluas `/reports`),
admin hanya dengan tugas sendiri, profil informatif tanpa role/sakelar baru,
serta `pantau:false` untuk menentukan ubin. Tidak menambahkan jalur mutasi.

- **Belum diuji:** runtime iOS, perangkat fisik, build distribusi Android/iOS,
  rekaman layar fisik, Push nyata/cold start Push, MariaDB 10.6.28 produksi,
  MySQL/cPanel/smoke hosting dan UAT. Perintah inventaris simulator iOS tidak
  selesai; dihentikan. Tidak ada klaim validasi iOS dari inspeksi kode.
- Tidak menguji balapan pencabutan tepat di antara SELECT atau sesudah respons
  terkirim. Hak diperiksa kembali pada permintaan berikutnya; data lokal
  disembunyikan/dimuat ulang saat blur/latar/fokus sesuai hook yang sudah ada.
- Audit ini tidak menutup gerbang produksi V5/V6/V7. Kompatibilitas server lama
  ditinjau pada klien (404 → modul/identitas tidak tampil); tidak ada rilis baru
  ke perangkat pengguna.

## Diagnostik auditor

Satu spasi ujung baris log statis dinormalisasi untuk `git diff --check`;
isi assertion dan hasilnya tidak diubah.

- Sandbox awal menolak process substitution `/dev/fd/62`; fixture/server lokal
  dijalankan kembali lewat izin alat. Tidak menyentuh database produksi.
- Tsc sebelum Metro menghasilkan dua error deklarasi CSS karena berkas Expo
  generated belum terbentuk. Sesudah Metro, tsc exit 0 tanpa perubahan kode.
- Probe awal: pencabutan satu-satunya role memang 401 (autentikasi gugur),
  sehingga skenario role diganti ke akun Guru/Pengurus yang masih dapat login;
  identitas palsu harus memakai pengurus tanpa akun karena indeks unik;
  enum semester yang benar adalah `Non-Aktif`. Kesalahan penyiapan ini
  diperbaiki sebelum probe final/kontrol dibekukan. Probe Fase 3 historis
  memakai string `Nonaktif`; bukti semester audit ini memakai enum yang benar.
- Checksum sempat berbeda pada percobaan yang belum diisolasi dari klien lain;
  penyebab perubahan tidak dibuktikan. Pengulangan terisolasi pada kode akhir
  menghasilkan seluruh tabel bisnis dan jumlah audit Keamanan tetap. Tidak
  mengklaim percobaan awal itu lulus. Probe final/kontrol adalah hasil yang
  dilaporkan di atas.
- Input koordinat Android awal tidak mengenai password; diganti pembacaan
  hierarki UI. Percobaan pertama hanya kembali dua tingkat sehingga masih di
  navigator Keamanan (FLAG_SECURE semestinya tetap); ulangan kembali tiga
  tingkat hingga Beranda dan lulus 8/0. Diagnostik awal dipertahankan.

Handoff: commit/push hasil audit pada kedua branch Fase 4 yang sama, lalu
berhenti. Tidak memulai Fase 5, merge, deploy, migrasi produksi atau rilis.
