# Audit independen keamanan PSB — Codex

Tanggal: **24 Oktober 2026**. Status akhir: **LULUS setelah dua koreksi terarah auditor**. Tidak ada temuan wajib yang masih terbuka dalam cakupan audit ini. Hasil ini bukan konfirmasi deploy atau smoke produksi.

Branch `claude/perbaikan-keamanan-psb`; baseline `a116e0f`, seluruh commit implementator di atasnya: **`c6014e1`**. Remote diperiksa ulang sebelum commit audit dan masih menunjuk commit tersebut. Implementator telah menyerahkan pekerjaan; audit dilakukan bergantian, tanpa worktree atau agen lain.

## Cakupan dan keputusan

Dibaca: `AGENTS.md`, README workstream, rencana, log keputusan dan bagian relevan cetak biru redesain. Seluruh diff implementasi ditelaah, termasuk lima kelas PSB baru, halaman publik/portal/cetak, jalur berkas, aturan Apache, tautan admin, pengujian dan dokumen. Tidak ada perubahan skema, REST, `app/Auth`, mobile atau rapor dari workstream ini maupun koreksi auditor.

| Keputusan | Hasil penilaian |
| --- | --- |
| AG | Jalur berkas memeriksa hak di server dan membaca nama dari baris berhak. Tautan admin memakai jalur itu. Pratinjau panel tetap menunggu Tahap 8. |
| AJ | Pendaftar tetap memilih komponen wajib dan nominal pilihan; katalog, harga komponen serta total ditentukan server. Paritas katalog dengan baseline lulus untuk 36 kombinasi. |
| AN | Tidak menetapkan biaya MI/RA atau menambah MI ke formulir dalam workstream keamanan; kategori lama tetap. Perubahan pilihan/biaya menunggu keputusan dan Tahap 8. |
| AP | Masuk tetap NISN + No. HP wali. CSRF, pembatasan, regenerasi sesi, pemeriksaan identitas terkini dan umur sesi diuji. |
| P-7 | Halaman biaya tetap di alamat lama dengan akses pemilik/admin. Tidak dipindah ke portal dan tidak menambahkan syarat status penerimaan baru. |
| Data historis | Tidak memindah/menghapus berkas lama atau data produksi. Enam berkas pendaftar yang sudah terlacak tidak disentuh; penghapusan pohon/riwayat tetap keputusan Human Developer. |

Penafsiran README diterima: akses panitia saat ini lewat akun admin; rincian lunas memerlukan jalur pembatalan admin beralasan; validasi biodata dapat menolak nilai impor lama yang tidak sah; sesi aktif perlu masuk ulang saat perubahan cookie diterapkan; penggantian unggahan tidak menghapus berkas sebelumnya; batas per IP dapat mencakup banyak pendaftar pada jaringan yang sama. Hak bukti sementara hanya berlaku untuk pendaftaran yang dibuat peramban tersebut, bukan hak biaya/berkas.

## Koreksi terarah

| Temuan | Koreksi dan bukti penutupan |
| --- | --- |
| F-1 · P2 · Konsistensi rincian saat pelunasan bersamaan | `BiayaPsb::simpan()` menjaga seluruh rincian lunas secara atomik dan memegang transaksi sampai hasil diperiksa. Probe dua koneksi membuktikan pelestarian rincian pada tagihan yang sudah ada maupun pembuatan pertama; penolakan dan kegagalan tulis menggulung transaksi. |
| F-2 · P2 · Kategori tagihan tertinggal | Penyimpanan ulang tagihan belum lunas memperbarui kategori dari katalog server bersama rincian. Kategori/rincian lunas tetap terkunci. Asersi regresi HTTP ditambahkan untuk kategori dan penyimpanan ulang tanpa perubahan. |

Kode aplikasi auditor hanya berubah di `app/Psb/BiayaPsb.php`. Suite browser dilengkapi font lokal agar ikon dapat diperiksa secara visual. Tidak ada pelonggaran oracle keamanan implementator. Sesuai kebijakan repositori publik, parameter dan langkah reproduksi teknis temuan tidak dimuat di laporan ini; probe tambahan terperinci disimpan privat di luar checkout.

Kontrol pembanding memuat kelas layanan dari commit implementator hanya pada probe CLI privat, pada DB sintetis: **7 lulus, 5 gagal**. Probe identik dengan koreksi: **12 lulus, 0 gagal**. Checkout dan server audit tidak dikembalikan ke kode lama selama kontrol ini.

## Bukti pengujian independen

Bukti auditor di [`audit-codex/`](audit-codex/); bukti implementator tetap dipertahankan. PHP 8.4.14, Apache 2.4.67 + php-fpm, MariaDB lokal dan Chromium Playwright. Semua HTTP memakai loopback dan salinan sumber tanpa `.git`, `.env` tersendiri serta unggahan sintetis di luar checkout. Tidak memakai dump lampiran/data produksi.

DB baru dibuat untuk audit: `test_kpsb_codex_audit_test`, `test_kpsb_codex_regresi_test`, `test_kpsb_codex_t3_test`, `test_kpsb_codex_t3_kosong_test`. Sumbernya sandbox sintetis lokal; skema yang belum ada hanya diterapkan pada DB baru. Sumber sandbox tidak diubah. DB regresi cleanup memakai sentinel historis sintetis dan backfill lokal.

| Pengujian | Hasil akhir |
| --- | --- |
| Keamanan PSB statis | **82/0** |
| Keamanan PSB HTTP awal / akhir | **94/0 → 96/0** (dua asersi regresi baru) |
| Browser alur PSB, 375/768/1280 px | **42/0** |
| Probe tambahan akses dan sesi | **32/0** |
| Probe tambahan transaksi biaya | **12/0** |
| GET-mutasi HTTP / probe audit lama | **91/0 / 84/0** |
| Preservasi cleanup sebelum / sesudah GET-mutasi | **157/0 / 157/0** |
| Probe audit cleanup lama | **128/0** |
| HTTP redesain Tahap 3, termasuk PSB admin/impor/ekspor/pembayaran/rekap | **167/0** |
| Browser kerangka sembilan akun × tiga lebar | **107/0** |
| Seluruh 28 suite statis | **25 lulus; 3 kegagalan baseline** |
| Lint PHP berubah, sintaks JS dan `git diff --check` | **lulus** |

Probe akses menambahkan pembuktian umur sesi 12 jam dan hak bukti 6 jam melalui timestamp sintetis, pencabutan identitas, pencabutan peran/penonaktifan admin, kewajiban ganti sandi pada sesi yang masih ada, CSRF lintas sesi, cookie, HEAD, penolakan metode tulis dan pembatasan lokasi berkas. Tidak menunggu waktu 6/12 jam secara nyata. Probe transaksi membuktikan pertemuan dua koneksi pada kunci tulis, lalu memeriksa isi dan penutupan transaksi. Log galat PHP kedua server Apache tidak memuat galat/peringatan PHP.

Tiga kegagalan statis tidak diubah: oracle Tahap 4 melarang migrasi sejak Tahap 3 meski migrasi 023 diwajibkan Tahap 6; dua oracle navigasi V3 lama sudah tercatat sebelum workstream ini. Suite V3 statis memakai flag dan nama konfigurasi historis yang diwajibkannya, hanya membaca sumber, tanpa koneksi DB tersebut. Percobaan awal tanpa flag exit 77, bukan dianggap lulus.

Regresi Tahap 3 pertama 166/1 karena pendaftar sintetis baru dari suite browser menggeser baris uji escape dari halaman pertama. Run dengan fixture PSB terpisah lulus 167/0 tanpa mengubah kode atau oracle. Probe HEAD awal mengharapkan `Content-Length`; Apache/FastCGI lokal menghilangkannya. Oracle auditor diperbaiki untuk memeriksa status, MIME dan isi kosong; aplikasi tidak diubah untuk kasus ini.

Tangkapan 375/768/1280 px diperiksa visual: tindakan portal, formulir, dan panel admin dapat digunakan; pemeriksaan lebar dokumen lulus. **Ini bukan klaim seluruh tampilan publik sudah sempurna:** logo publik tidak tersedia di checkout dan teks merek lama terpotong pada 375 px. `header.php`, `style.css` dan keadaan aset tersebut tidak berubah dari baseline; perapihan tampilan tetap Tahap 8. Ikon pada tangkapan auditor menggunakan font lokal.

## Batas dan handoff

- Hanya Chromium lokal; Safari/Firefox, perangkat fisik, aksesibilitas menyeluruh dan alur penuh tanpa JavaScript belum diuji. Bootstrap/font memakai aset lokal, flatpickr/AOS berupa stub dan API wilayah fixture sintetis.
- Bukan cPanel: handler `admin/.htaccess` produksi diganti handler php-fpm pada konfigurasi uji. Aturan penolakan folder akar dan folder berkas benar-benar diuji Apache. Ukuran melewati `post_max_size` hosting belum diuji di produksi.
- **Langkah `.htaccess` manual pada README tetap wajib saat deploy oleh pihak yang berwenang.** Skrip deploy tidak otomatis menyalin dua aturan pelindung berkas lama. Jangan mengartikan hasil audit lokal sebagai bukti aturan hosting sudah terpasang.
- Temuan non-PSB, berkas lama yang sudah ada di Git, tagihan yatim saat penghapusan dan folder gambar berita lama tetap di luar koreksi ini sesuai keputusan sebelumnya.
- Tidak mengulang seluruh integrasi V1–V4 atau pengujian mobile/rapor yang tidak berubah. Direktori pekerjaan lain tidak disentuh.
- Server yang dibuat auditor dihentikan setelah pengujian; DB tetap sintetis. Tidak merge, deploy atau migrasi produksi, dan tidak memulai Tahap 8.

Workstream keamanan PSB memenuhi kriteria audit dalam cakupan ini. Keputusan merge/deploy tetap Human Developer; Tahap 8 tetap memerlukan bahan resmi, biaya MI/RA dan instruksi pengerjaan tersendiri.
