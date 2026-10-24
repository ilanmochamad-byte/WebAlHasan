# Audit independen Tahap 8 — Codex

Tanggal: **24 Oktober 2026**. Status: **LULUS setelah tiga koreksi terarah tampilan**. Tidak ada temuan wajib terbuka dalam cakupan Tahap 8. Ini hasil audit lokal, bukan konfirmasi deploy atau pemeriksaan produksi.

Branch `claude/redesain-web-tahap-8`, baseline `1b319fa`; seluruh commit implementator di atas baseline: **`1809363`**. Remote diperiksa dan masih menunjuk `1809363` sebelum commit audit. Pekerjaan bergantian, tanpa Claude atau subagen berjalan bersamaan dan tanpa worktree. Direktori pekerjaan lain serta enam berkas PSB lama yang terlacak tidak disentuh.

## Cakupan dan penilaian

Dibaca: `AGENTS.md`, rencana, keputusan termasuk AY–BA, cetak biru U-1–U-10/P-1–P-8/X-1, README Tahap 8, serta README dan audit workstream keamanan PSB. Seluruh diff commit implementasi ditelaah: kerangka/isi situs, PHP publik/portal/cetak, kelas jenjang dan perubahan validasi, CSS/JS, panel admin, aturan Apache, pengujian dan dokumen.

| Kriteria | Hasil audit |
| --- | --- |
| U-1, AM, AY | Empat pintasan, menu Masuk, empat unit, ajakan PSB dan tombol HP tersedia. Isian resmi yang belum tersedia tetap kosong; tanpa statistik, foto stok atau nomor contoh. Identitas/kontak/slogan yang dibawa dari situs lama diterima sesuai penafsiran README, dengan kewajiban pemeriksaan Human Developer sebelum rilis. |
| U-2–U-3 | Enam bagian Profil punya alamat masing-masing; keadaan kosong jujur. Empat unit tampil; situs unit membuka tab baru. Struktur dengan delapan bidang sintetis tambahan diperiksa pada tiga lebar tanpa overflow; konfigurasi hanya diubah sementara di salinan uji dan dipulihkan. |
| U-4–U-6, AH, AI | Kabar 12 per halaman, pencarian, cuplikan, meta bagikan dan jalur kembali; galeri tanpa album dengan keterangan, muat bertahap dan penampil foto. Fokus penampil diperbaiki auditor. |
| U-7–U-9 | Jadwal tahun aktif/per waktu, pencarian dan kelas; tabel menjadi kartu HP. Unduhan menampilkan jenis dan ukuran berkas. Isi kebijakan privasi dipertahankan, warna dan daftar isi mengikuti kerangka. |
| U-10 | Verifikasi berada dalam kerangka situs. Logika pemeriksaan V4 tidak berubah; CSRF, hasil tidak ditemukan, CSP tanpa CDN dan tidak adanya identitas santri diperiksa. CSP menambah sumber lokal stylesheet/gambar untuk kerangka. |
| P-1–P-2, AN, AO, AZ | Empat langkah dan ringkasan, input angka berupa teks, pengulangan HP, persetujuan privasi diperiksa server tanpa penyimpanan kolom baru. Galat mempertahankan isian dalam sesi sendiri. Pendaftaran lengkap dengan dan tanpa JS diuji pada tiga lebar. MI/RA ditolak server untuk pendaftaran/perpindahan; RA lama tetap dapat menyimpan data. |
| P-3–P-6, AP | Login tetap NISN + HP dengan tombol tampilkan; pesan tetap tidak membedakan NISN terdaftar. Status nyata, ubah per bagian, konfirmasi HP dan unggah tiap dokumen diuji. Hak sesi/akses tetap diperiksa server; berkas melalui jalur berpenjaga. |
| P-7, AJ, BA | Pembiayaan tetap di `biaya_psb.php`, dibuka dari portal; pemilik/admin sesuai audit keamanan PSB, tanpa syarat baru Diterima. Komponen wajib tetap dipilih pendaftar; harga/total ditentukan server. Kwitansi dibuka di tab sama. Rekening tanpa JS diperbaiki auditor. |
| P-8, X-1 | Kop/huruf `PrintLayout`, foto 3×4 pada bukti, penanda tangan PSB/Bendahara tetap, bilah tindakan tidak tercetak. Dua PDF fisik A4 diperiksa visual, masing-masing satu halaman dengan nomor halaman sesuai. |
| AG dan preservasi keamanan | Gambar pratinjau admin benar-benar termuat melalui endpoint berhak. HTTP membuktikan admin 200, guru/pendaftar lain 403 dan anonim 401. Penolakan folder langsung benar-benar diuji Apache, termasuk berkas lama sintetis. |

Penafsiran README diterima: data lama RA boleh dipertahankan, admin tetap memakai daftar unit yang ada; pembiayaan tidak ditambah gate Diterima; pilihan wajib tetap AJ; jadwal nonaktif/arsip tidak dipublikasikan; tanggal memakai kontrol bawaan; HTML berita dari editor admin mempertahankan perilaku lama. Pemeriksaan HP ulang di server hanya berlaku bila field dikirim, sementara formulir normal mewajibkannya; ini pencegah salah ketik, bukan kredensial baru. Tombol verifikasi tetap berlabel Periksa. Penyimpanan biodata per bagian tetap menggunakan layanan lama dengan keseluruhan data, sesuai handoff.

## Koreksi auditor dan bukti penutupan

| Temuan | Koreksi |
| --- | --- |
| F-1 · P2 · Menu HP menutupi tujuan unit | Memilih unit saat sudah berada di Pendidikan hanya mengganti fragmen, sehingga menu tetap terbuka dan gulir terkunci. Klik tautan menu sekarang menutup menu serta memperbarui `aria-expanded`. Probe langsung pada 375/768 px membuktikan gulir terbuka kembali. |
| F-2 · P2 · Fokus keluar dari penampil galeri | Shift+Tab dari tombol Tutup sebelumnya memindahkan fokus ke tautan situs di belakang penampil. Tab/Shift+Tab kini berputar di tiga tombol penampil. Panah, Escape, pengembalian fokus dan pemulihan gulir tetap diuji pada ketiga lebar. |
| F-3 · P2 · Rekening transfer tersembunyi tanpa JS | Instruksi transfer sebelumnya memakai `hidden` dari pilihan tersimpan, sehingga memilih Transfer tanpa JS tidak menampilkan rekening. Instruksi dasar kini tersedia dari HTML; JavaScript tetap menyaring instruksi menurut pilihan ketika aktif. Probe tanpa JS membuktikan rekening terbaca pada ketiga lebar. |

Kode aplikasi auditor hanya berubah di **`assets/ui/situs.js`** dan **`biaya_psb.php`**. Tidak mengubah kelas keamanan PSB, aturan biaya, skema, `app/Auth`, `app/V4`, REST atau mobile. Regresi tambahan yang dapat dijalankan ulang: `tests/browser/uji-redesain-web-tahap8-audit.mjs`. Probe identik pada commit implementator menghasilkan **12/8**; sesudah koreksi **20/0**. Tidak mengubah oracle implementator maupun oracle keamanan lama.

## Bukti pengujian independen

Bukti di [`audit-codex/`](audit-codex/), terpisah dari bukti implementator. PHP 8.4.14, Apache 2.4.67 + php-fpm, MariaDB lokal dan Chromium Playwright. HTTP keamanan menggunakan salinan sumber di luar checkout tanpa `.git`, `.env` tersendiri dan seluruh unggahan sintetis. Tidak memakai dump lampiran atau data produksi.

DB baru: `test_t8_codex_audit_test` dari sandbox sintetis keamanan PSB, `test_t8_codex_regresi_test` dari sandbox preservasi sebelumnya, serta `test_t8_codex_t3_test`/`test_t8_codex_t3_kosong_test` dari sandbox Tahap 3. Fixture Tahap 8 dibuat ulang secara independen. Tidak menjalankan migrasi, baik pada produksi maupun selama audit ini; seluruh skema sudah ada di salinan sintetis.

| Pengujian | Hasil akhir | Bukti |
| --- | --- | --- |
| Statis Tahap 8 | **80/0** | `statis-akhir.txt` |
| HTTP Tahap 8 | **84/0** | `http-akhir.txt` |
| Browser Tahap 8, 375/768/1280 px, termasuk pendaftaran tanpa JS | **121/0** | `browser-akhir.txt`, `tangkapan/` |
| Probe UI auditor, dengan/tanpa JS | **20/0** (sebelum 12/8) | `probe-ui.txt`, `probe-ui-sebelum.txt` |
| Keamanan PSB HTTP | **97/0** | `keamanan-http-akhir.txt` |
| Keamanan PSB browser, tiga lebar | **42/0** | `keamanan-browser-akhir.txt` |
| Probe auditor keamanan PSB terdahulu: akses/sesi, transaksi biaya | **32/0, 12/0** | `keamanan-akses.txt`, `keamanan-transaksi.txt` |
| GET-mutasi HTTP dan probe audit lama | **91/0, 84/0** | `get-mutasi.txt`, `get-mutasi-probe.txt` |
| Preservasi cleanup sebelum/sesudah, audit cleanup | **157/0, 157/0, 128/0** | `cleanup-sebelum.txt`, `cleanup-sesudah.txt`, `cleanup-probe.txt` |
| HTTP admin Tahap 3 | **166/1**, hanya oracle AG historis | `tahap3-http.txt` |
| Browser kerangka Tahap 2 / admin Tahap 3, tiga lebar | **89/0, 76/0** | `kerangka-browser.txt`, `admin-browser.txt` |
| Seluruh 29 suite statis | **24 lulus, 5 gagal historis** | `statis/` |
| PDF fisik bukti/kwitansi | **masing-masing 1 halaman A4**, bilah tindakan tidak tercetak | `pdf-bukti.txt`, `pdf-kwitansi.txt`, `tangkapan/cetak-*.png` |
| Lint 31 PHP berubah, sintaks JS dan diff whitespace | **lulus** | `lint.txt`, suite statis |

Probe keamanan terdahulu disalin ke folder privat baru dan hanya diganti nama DB, URL dan lokasi salinan. Probe 32/0 menguji umur sesi 12 jam/hak bukti 6 jam dengan timestamp sintetis, pencabutan identitas/peran/akun, kewajiban ganti sandi, CSRF lintas sesi, cookie, HEAD dan penolakan metode tulis berkas. Probe 12/0 menguji transaksi dua koneksi saat pelunasan, pembuatan pertama, kategori dan rollback. Log Apache/php-fpm tidak menghasilkan log galat PHP; pemeriksaan HTTP akhir juga lulus tanpa peringatan PHP.

Tangkapan diperiksa visual untuk HP, tablet, desktop, dialog, pratinjau admin dan kedua cetakan. PDF dibuat dari baris sintetis yang mengandung karakter HTML untuk sekaligus memeriksa escape. Tidak menerbitkan rincian teknis temuan keamanan lama; probe terperinci tetap privat di luar checkout.

## Oracle historis dan penyiapan fixture

- Statis keamanan PSB **82/1**, statis Tahap 3 **151/1**, dan HTTP Tahap 3 **166/1** masih menuntut AG *belum* dipasang. Tahap 8 justru diwajibkan memasang pratinjau berpenjaga. Ini oracle periode sebelumnya; akses aktual, sumber URL dan gambar termuat diuji penggantinya oleh H8-J dan browser. Oracle lama tidak diubah.
- Statis Tahap 4 **95/1** melarang perubahan database sejak Tahap 3, sedangkan migrasi 023 diwajibkan Tahap 6 dan sudah ada di baseline. Dua kegagalan statis V3 lama tetap sama: navigasi sistem warisan dan capability konseling. Tidak ada perubahan aplikasi atau pelonggaran asersi untuk membuatnya hijau.
- HTTP Tahap 3 pertama **161/6** karena salinan sandbox sudah memuat hasil uji lama: baris baru menggeser nama uji escape, status contoh sudah Cadangan, dan baris impor uji sudah ada. Fixture PSB Tahap 3 dipulihkan hanya pada DB auditor yang baru, memakai blok seed PSB aslinya, lalu suite identik menjadi **166/1**; kegagalan tersisa hanya AG. Run awal tetap disimpan di `tahap3-http-awal.txt`.
- Run browser kerangka pertama berhenti saat akun guru mapel tidak tersedia di sandbox PSB. Run penuh dipindahkan ke salinan sandbox Tahap 3 yang memuat fixture akun lengkap, tanpa perubahan suite, dan lulus **89/0**. Bukan perbaikan kode aplikasi.

## Batas dan handoff

- Bukan cPanel. Handler PHP khusus hosting pada `admin/.htaccess` diganti konfigurasi php-fpm lokal; aturan pelindung folder akar/berkas diuji Apache. **Langkah manual `.htaccess` pada README keamanan PSB tetap wajib saat deploy**, ditambah aturan alamat Profil/Pendidikan dari README Tahap 8. Belum ada bukti aturan tersebut terpasang di produksi.
- Chromium saja; Bootstrap/font lokal dan API wilayah fixture atau diblokir. Belum Safari/Firefox, perangkat fisik, pembaca layar atau printer fisik. Pendaftaran utuh serta rekening tanpa JS diuji; bukan klaim seluruh alur aplikasi telah diuji tanpa JS.
- Materi resmi AY belum tersedia. Kondisi kosong dan struktur organisasi sintetis diuji, tetapi logo/foto/materi lengkap yang akan diisi Human Developer belum dapat dinyatakan telah lulus tinjauan visual.
- Verifikasi publik V4 diuji HTTP/CSP/CSRF dan halaman browser, tetapi alur lengkap kode rapor terbit + pencocokan PDF kanonis tidak diulang. Layanan V4 tidak berubah; demikian pula API, mobile dan integrasi bisnis V1–V4 di luar regresi yang disebut di atas.
- Batas ukuran hosting `post_max_size`, hosting nyata, temuan keamanan publik non-PSB, folder gambar berita lama, tagihan yatim historis dan berkas pribadi lama di Git tetap di luar koreksi ini sesuai workstream sebelumnya.
- Seluruh server yang dibuat auditor dihentikan setelah pengujian. DB dan salinan audit tetap sintetis di lokal. Tidak merge, deploy atau migrasi produksi.

Tahap 8 memenuhi kriteria wajib audit dalam cakupan ini setelah koreksi. Hasil diserahkan kembali kepada Human Developer untuk keputusan berikutnya; audit berhenti setelah commit dan push branch yang sama.
