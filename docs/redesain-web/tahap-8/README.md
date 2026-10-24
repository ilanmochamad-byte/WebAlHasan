# Redesain website Tahap 8 — Situs publik & PSB (24 Oktober 2026)

Status: **lulus audit independen Codex setelah tiga koreksi terarah tampilan (24 Oktober 2026).** Laporan, hasil pengujian independen dan batasnya: [AUDIT-CODEX.md](AUDIT-CODEX.md). Implementasi Claude tetap tercatat di bawah. Branch `claude/redesain-web-tahap-8` dari `origin/main` `1b319fa` (sudah memuat Tahap 7 dan perbaikan keamanan PSB). Tahap terakhir redesain. Belum merge, belum deploy, tanpa migrasi database.

Cakupan: U-1 s.d. U-10, P-1 s.d. P-8, templat cetak X-1 untuk dokumen PSB · keputusan AG, AJ, AM, AN, AO, AP.

## Keputusan Human Developer untuk Tahap 8 (24 Oktober 2026)

Prompt instruksi memuat tiga butir pilihan. Karena arti pilihan (a)/(b) tidak tercatat di repositori, Claude menanyakan ulang sebelum mulai; jawaban Human Developer dicatat di `../keputusan.md` (AY–BA):

| # | Butir | Keputusan |
|---|---|---|
| AY | Bahan resmi situs (isi Profil, tahun berdiri, nomor WA/telepon, logo, foto, statistik) | **Dikosongkan, diisi manual nanti.** Semua isi resmi dibaca dari satu berkas `app/Situs/isi-situs.php`; isian kosong tidak ditampilkan. |
| AZ | Biaya MI/RA | **MI/RA belum dibuka**: tampil di pengelompokan jenjang tetapi belum dapat dipilih sampai biaya ditetapkan. |
| BA | P-7 pembiayaan | **Halaman biaya tetap di alamatnya sekarang** (`biaya_psb.php`, sudah dilindungi workstream keamanan PSB), tampilannya didesain ulang, dan dibuka dari menu "Pembiayaan" Portal Pendaftar. |

## Perubahan

| Berkas | Perubahan |
|---|---|
| `app/Situs/Kerangka.php` (baru) | Kerangka situs publik: navbar tanpa "#" dengan menu Profil (6 bagian), Pendidikan (4 unit), Kabar, Galeri, Jadwal Ngaji, Unduhan, **Masuk** (Masuk Sistem + Portal Pendaftar, AM), tombol Daftar PSB; menu layar penuh di HP (tanpa JavaScript tampil sebagai daftar); kaki 4 kolom dengan kontak resmi; kerangka polos (P-3); kerangka Portal Pendaftar (bilah atas nama/nomor + Keluar ber-CSRF dengan dialog, menu samping di tablet/desktop, bilah bawah di HP). Murni presentasi. |
| `app/Situs/IsiSitus.php`, `app/Situs/isi-situs.php` (baru) | Isi resmi situs dalam satu berkas yang diisi manual (AY). Gambar hanya diterima bila berkas lokal yang ada. Folder `app/` tetap ditolak web. |
| `assets/ui/situs.css`, `situs.js`, `situs-awal.js` (baru) | Token desain yang sama dengan `alhasan.css` (menggantikan `style.css` yang dirujuk tetapi tidak ada di repositori). Perilaku peramban bersifat tambahan; tidak ada `alert()`/`confirm()` bawaan. Pustaka AOS, Poppins, lightbox, masonry, jQuery, flatpickr tidak dipakai lagi. |
| `header.php`, `footer.php` | Tetap dipanggil halaman lama (`include 'header.php'` sesudah `koneksi.php`), kini membuka/menutup kerangka baru. |
| `index.php` (U-1) | Hero, bilah 4 pintasan (Daftar PSB, Portal Pendaftar, Cek keaslian rapor, Masuk Sistem), Tentang (hanya bila diisi), 4 unit, 3 kabar terbaru, ajakan PSB; tombol Daftar PSB menempel di bawah HP. |
| `profil.php` (baru, U-2), `pendidikan.php` (baru, U-3) | Profil 6 bagian beralamat sendiri (`/profil?bagian=…`), struktur bertingkat dan kisi pengajar; bagian kosong menampilkan "Belum diterbitkan". Unit pendidikan dengan tautan daftar berjenjang terpilih dan situs unit di tab baru. Alamat bersih `/profil`, `/pendidikan` di `.htaccess`. |
| `berita.php`, `detail.php` (U-4/U-5) | 12 berita per halaman, cari, tanpa status/kategori (AH); detail dengan kolom baca, jalur ke Kabar, meta bagikan, WhatsApp + Salin tautan, kabar lainnya. |
| `galeri.php` (U-6) | Tanpa album (AI), keterangan selalu terlihat, muat 24 per langkah (tautan biasa), penampil layar penuh (geser/Escape). |
| `jadwal_ngaji.php` (U-7) | Segmen 4 waktu sholat, saringan kelas + cari kitab/guru, tabel jadi kartu di HP. |
| `download.php` (U-8), `privacy.php` (U-9) | Jenis/ukuran berkas sebenarnya, seluruh baris dapat diklik; kebijakan privasi memakai token dan daftar isi menempel di desktop (isi tidak diubah). |
| `verifikasi_rapor.php` (U-10) | Masuk kerangka situs. Pemeriksaan, CSRF, pembatasan, dan header keamanan tetap; CSP hanya ditambah `'self'` untuk stylesheet/gambar kerangka (tanpa CDN, tanpa skrip sebaris). |
| `psb.php`, `proses_psb.php` (P-1/P-2) | Formulir 4 langkah (tanpa JavaScript tampil utuh); jenjang "Unit Al Hasan"/"Sekolah Mitra" (AN), RA/MI tampil tetapi nonaktif (AZ); NISN/NIK/No. HP teks `inputmode=numeric`; No. HP wali diketik dua kali; ringkasan dengan "Ubah"; kotak persetujuan Kebijakan Privasi wajib, diperiksa server, **tidak disimpan** (AO); wilayah gagal dimuat → "Coba lagi"/"Ketik manual"; bila gagal, galat per isian dan isian kembali (hanya sesi peramban itu, sekali tampil). Halaman berhasil tersendiri. |
| `app/Psb/Jenjang.php` (baru), `app/Psb/PendaftarData.php` | Pengelompokan jenjang; server menolak pendaftaran baru atau perpindahan ke RA/MI; pendaftar lama yang sudah tercatat RA tetap dapat menyimpan biodatanya. Tambahan `periksaPendaftaran()` (validasi tanpa menyimpan). `PsbAdmin::UNIT` dan katalog biaya tidak berubah. |
| `login_pendaftar.php`, `logout_pendaftar.php` (P-3) | Tetap NISN + No. HP wali (AP), No. HP disamarkan dengan tombol Tampilkan; pesan gagal tidak membedakan NISN terdaftar. |
| `portal_pendaftar.php`, `proses_upload_berkas.php` (P-4–P-6) | Satu halaman tiga bagian (#status, #data, #berkas; tab dengan JavaScript). Status nyata + "Langkah berikutnya" satu tombol; data diri dibaca dulu, diubah per bagian (setiap formulir tetap mengirim seluruh biodata ke `PendaftarData::ubah`), ganti No. HP meminta konfirmasi; unggah per dokumen dengan pesan di baris berkas itu, tautan Lihat lewat `lihat_berkas_psb.php`. Pesan hasil berupa catatan + toast, bukan `alert()`. |
| `biaya_psb.php` (P-7, BA) | Alamat tetap. Pendaftar melihatnya di kerangka portal (menu Pembiayaan aktif); admin dengan kotak cari; anonim hanya ajakan masuk. Komponen wajib dicentang (AJ), nominal pilihan, cara bayar dengan rekening + Salin, total langsung (menempel di HP). Simpan → kwitansi di tab yang sama (303). |
| `cetak_bukti.php`, `cetak_biaya_psb.php` (P-8) | Kop dan huruf templat cetak X-1 (`PrintLayout::kop`, `cssDasar`), A4 potret, bilah atas tidak tercetak, penanda tangan tetap (Panitia PSB; Orang tua/wali + Bendahara). Nomor telepon contoh pada kop lama dihapus. |
| `admin/admin_data.php` (AG) | Panel Pendaftar › Berkas: gambar mini dan tautan lewat `lihat_berkas_psb.php` (hak admin diperiksa setiap permintaan). |
| `tests/redesain_web_tahap8_*`, `tests/browser/uji-redesain-web-tahap8.mjs` (baru) | Uji statis, fixture, HTTP, browser. |

Tidak berubah (diperiksa uji statis terhadap `1b319fa`): `PsbAkses`, `BerkasPsb`, `PendaftarSesi`, `BiayaPsb`, `lihat_berkas_psb.php`, `auth_pendaftar.php`, `app/Auth`, `app/V4`, `api/`, `database/`, `portal/rapor_ttd.php`, `berkas_psb/` (enam berkas lama tidak dihapus). Tidak ada perubahan mobile.

## Penafsiran untuk auditor

1. **Bahan resmi kosong (AY).** Tidak ada teks contoh. Isian yang sudah tampil di situs lama/kebijakan privasi dibawa ke `isi-situs.php` dan diberi tanda "dibawa dari situs lama" agar diperiksa ulang: nama, slogan, semboyan kaki, alamat, surel, jam layanan, nama pengasuh, alamat situs SMP/SMK, tahun ajaran PSB. Nomor telepon/WA contoh, `[Tahun Berdiri]`, angka statistik, foto stok, logo, dan keterangan unit dikosongkan. Bagian "Tentang" Beranda baru tampil bila `tentang` diisi; Profil menampilkan "Belum diterbitkan".
2. **RA/MI belum dibuka (AZ) ditegakkan server**, bukan hanya opsi nonaktif. RA sebelumnya dapat dipilih; sekarang pendaftaran baru ke RA ditolak. Admin tetap dapat menambah pendaftar RA secara manual (`PsbAdmin::UNIT` tidak berubah). MI tidak ditambahkan ke daftar unit admin maupun katalog biaya.
3. **Pembiayaan (BA)**: akses tetap seperti workstream keamanan PSB (pemilik yang masuk atau admin; tidak dibatasi status Diterima). Kartu "Langkah berikutnya" baru mengarahkan ke pembiayaan saat status Diterima.
4. **Persetujuan privasi (AO)** diperiksa server di `proses_psb.php` agar tidak dapat dilewati; tidak ada kolom baru. No. HP ketik ulang hanya diperiksa bila isiannya dikirim.
5. **Isian kembali saat gagal** disimpan di sesi peramban pendaftar itu saja, dibersihkan setelah satu kali tampil.
6. **Jadwal ngaji publik** kini hanya menampilkan jadwal aktif (`is_active = 1`, tidak diarsipkan) tahun ajaran aktif; sebelumnya jadwal nonaktif ikut tampil.
7. **Cek keaslian rapor**: label tombol tetap "Periksa" (cetak biru: "Periksa rapor") agar uji V4 Fase 4 yang mencari tombol itu tidak berubah.
8. **Tanggal lahir** memakai `input type=date` bawaan (flatpickr dihapus). Halaman publik tidak memuat Font Awesome; kerangka tidak bergantung pada Bootstrap (halaman ber-CSP ketat dan portal tanpa Bootstrap).
9. **Halaman publik yang ditulis ulang** memakai query terikat dan keluaran di-escape; isi berita tetap HTML dari editor admin seperti sebelumnya.

## Pengujian

DB uji `test_redesain_t8_test` (salinan `test_keamanan_psb_test`: sandbox + migrasi 001–023 + fixture sintetis) dilayani **Apache 2.4 + php-fpm 8.4** dari salinan sumber di luar checkout (`tests/keamanan_psb_server.sh`, port 8981), sehingga `.htaccess` ikut diuji. Data sintetis: berita/galeri/unduhan/jadwal "[T8]", pendaftar NISN `97…`/`96…`/`95…`.

| Bukti | Hasil | Berkas |
|---|---|---|
| `tests/redesain_web_tahap8_static.php` | 80/0 | `uji-statis.txt` |
| `tests/redesain_web_tahap8_http.php` (H8-A s.d. H8-K) | 84/0 | `uji-http.txt` |
| `tests/browser/uji-redesain-web-tahap8.mjs`, Chromium 375/768/1280 px: semua layar U-1–U-10, P-1–P-8, AG tanpa geser horizontal dan tanpa galat konsol; interaksi; formulir PSB tanpa JavaScript | 121/0 | `uji-browser.txt`, `tangkapan/` |
| Keamanan PSB HTTP (`keamanan_psb_http.php`) | 97/0 (96 lama + I2a baru) | `regresi.txt` |
| Keamanan PSB browser (`uji-keamanan-psb.mjs`) | 42/0 | `regresi.txt` |
| Keamanan PSB statis | 82/1 — S4 historis (lihat bawah) | `regresi.txt` |
| HTTP Tahap 3 (PSB admin, konten website) | 166/1 — AG historis | `regresi.txt` |
| Browser Tahap 3 | 76/0 | `regresi.txt` |
| GET-mutasi V1 HTTP + audit Codex; cleanup preservasi + audit Claude | 91/0, 84/0, 157/0, 128/0 | `regresi.txt` |
| Seluruh `tests/*_static.php` | lulus kecuali oracle historis di bawah | `regresi.txt` |
| Log galat PHP server uji | kosong | — |

### Uji lama yang disesuaikan (tanpa melonggarkan oracle)

- `tests/keamanan_psb_http.php`: kiriman pendaftaran kelompok I ditambah `setuju_privasi=1` (AO kini wajib di server) dan ditambah satu pemeriksaan **I2a** (tanpa persetujuan ditolak dan tidak tersimpan). Semua pemeriksaan lama tetap sama.
- `tests/browser/uji-keamanan-psb.mjs`: hanya pencari elemen yang diganti untuk tampilan baru (langkah formulir, radio jenis kelamin, ketik ulang HP + centang privasi, tombol "Masuk", bagian Berkas, tombol unggah per dokumen, pesan di halaman — tanpa dialog `alert()` — kwitansi di tab yang sama, dialog keluar). Jumlah (42) dan isi pemeriksaan tetap.

### Oracle historis yang kini gagal karena keputusan AG (tidak diubah)

- `keamanan_psb_static.php` S4 "pratinjau (AG) tetap belum dipasang", `redesain_web_tahap3_static.php` S3-9 dan `redesain_web_tahap3_http.php` "AG panel berkas tanpa pratinjau gambar": ketiganya menegaskan pratinjau belum dipasang sebelum Tahap 8. Tahap 8 memasangnya sesuai AG lewat jalur berpenjaga; keamanannya diuji H8-J dan uji browser (admin 200, guru 403, anonim 401, pendaftar lain 403).
- Tetap dari sebelumnya: `redesain_web_tahap4_static.php` (migrasi 023 sejak Tahap 6), `v3_phase2_static.php` dan `v3_phase3_static.php` (baseline V3 lama).

## Batas pengujian (jujur)

- Bukan hosting cPanel; smoke produksi belum dijalankan. Langkah manual `.htaccess` workstream keamanan PSB tetap wajib saat deploy, ditambah dua aturan `/profil` dan `/pendidikan` di `.htaccess` akar (lihat bawah).
- API wilayah (emsifa) diganti fixture/diblokir di uji browser; Bootstrap dari `node_modules`; hanya Chromium.
- Isi resmi kosong diuji sebagai keadaan kosong; tampilan dengan isi lengkap (logo, foto, struktur bertingkat, statistik) hanya diperiksa lewat kode, belum dengan bahan nyata.
- Uji browser V4 Fase 4 (`uji-v4-fase4.mjs`, cek keaslian rapor dengan rapor terbit) tidak dijalankan ulang; kode pemeriksaan V4 tidak berubah dan HTTP H8-F menguji CSP/CSRF/404.
- Regresi browser kerangka sembilan akun (Tahap 1–2) tidak dijalankan ulang: kerangka internal (`Layout`) tidak diubah; satu-satunya halaman internal yang berubah (panel Pendaftar) diuji di Tahap 3 browser, keamanan PSB browser, dan Tahap 8 browser.
- Tidak diuji: pembaca layar nyata, peramban selain Chromium, cetak fisik ke printer.

## Langkah rilis manual

1. Ikuti langkah manual workstream keamanan PSB (`docs/keamanan-psb/README.md`) bila belum.
2. `.htaccess` akar produksi: tambahkan dua aturan `RewriteRule ^profil/?$ profil.php [QSA,L]` dan `RewriteRule ^pendidikan/?$ pendidikan.php [QSA,L]` (lihat `.htaccess` repositori). Tanpa ini, `/profil.php` dan `/pendidikan.php` tetap berfungsi; navbar memakai alamat `.php`.
3. Isi `app/Situs/isi-situs.php` dengan bahan resmi; simpan logo/foto di folder situs (mis. `assets/situs/`) dan tulis jalurnya. Periksa isian bertanda "dibawa dari situs lama".
4. Uji: Beranda, Profil, formulir PSB dari HP, masuk portal, unggah satu berkas, pembiayaan → kwitansi, panel Pendaftar admin › Berkas.

## Pemulihan

Revert commit Tahap 8; tidak ada migrasi. Pendaftar yang mendaftar selama Tahap 8 tersimpan dengan kolom yang sama. Aturan `.htaccess` `/profil` dan `/pendidikan` boleh dibiarkan (berkasnya hilang setelah revert → 404).

## Reproduksi

```sh
mysql -e 'CREATE DATABASE test_redesain_t8_test' && mysqldump test_keamanan_psb_test | mysql test_redesain_t8_test
KPSB_DIR=/private/tmp/<privat> KPSB_PORT=8981 KPSB_DB=test_redesain_t8_test bash tests/keamanan_psb_server.sh start
REDESAIN_T8_SEED=1 KPSB_DB=test_redesain_t8_test KPSB_WEB_ROOT=/private/tmp/<privat>/web php tests/redesain_web_tahap8_seed.php
php tests/redesain_web_tahap8_static.php
REDESAIN_T8_RUN=1 KPSB_URL=http://127.0.0.1:8981 KPSB_DB=test_redesain_t8_test KPSB_WEB_ROOT=/private/tmp/<privat>/web php tests/redesain_web_tahap8_http.php
BASE_URL=http://127.0.0.1:8981 T8_BROWSER=1 node tests/browser/uji-redesain-web-tahap8.mjs
KEAMANAN_PSB_RUN_TESTS=1 KPSB_URL=http://127.0.0.1:8981 KPSB_DB=test_redesain_t8_test KPSB_WEB_ROOT=/private/tmp/<privat>/web php tests/keamanan_psb_http.php
BASE_URL=http://127.0.0.1:8981 KPSB_BROWSER=1 node tests/browser/uji-keamanan-psb.mjs
# Probe regresi auditor, setelah fixture keamanan_psb_http.php tersedia:
BASE_URL=http://127.0.0.1:8981 T8_BROWSER=1 node tests/browser/uji-redesain-web-tahap8-audit.mjs
```

`tests/keamanan_psb_server.sh sync` menghapus berkas fixture di salinan; jalankan seed lagi sesudah sinkron. Suite menolak berjalan bila URL bukan `127.0.0.1`, salinan berisi `.git`, atau DB bukan `test_*_test`.

## Handoff ke auditor Codex

> Audit implementasi Tahap 8 redesain tampilan website (situs publik & PSB) yang dibuat Claude pada branch `claude/redesain-web-tahap-8`. Baca AGENTS.md, `docs/redesain-web/` (rencana, keputusan termasuk AY–BA, cetak biru U-1–U-10, P-1–P-8, X-1), `docs/redesain-web/tahap-8/README.md`, serta `docs/keamanan-psb/README.md` dan `AUDIT-CODEX.md`. Periksa seluruh commit di atas `1b319fa`, jalankan pengujian secara independen termasuk tampilan 375px, 768px, dan 1280px dan seluruh uji keamanan PSB, dan lakukan koreksi terarah jika diperlukan. Commit dan push hasil audit, lalu berhenti. Jangan merge, deploy, atau menjalankan migrasi produksi.
