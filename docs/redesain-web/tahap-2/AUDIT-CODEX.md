# Audit independen Codex — redesain website Tahap 2

> Status terkini: **LULUS setelah koreksi terarah pada audit ulang 23 Oktober 2026**. Lihat [AUDIT-ULANG-CODEX.md](AUDIT-ULANG-CODEX.md). Laporan di bawah adalah riwayat audit pertama atas `9a5d764`.

Tanggal: **23 Oktober 2026 (Asia/Jakarta)**. Putusan: **BELUM LULUS**. Empat temuan P2 di bawah masih terbuka. Tahap 3 belum boleh dimulai. Tidak ada merge, deploy, migrasi produksi, atau perubahan mobile.

## Acuan dan metode

- Branch `claude/redesain-web-tahap-2`; satu commit implementasi **`9a5d764`**, baseline audit Tahap 1 **`d6c5da0`**.
- Memeriksa diff kode, perubahan pengujian, `AGENTS.md`, rencana, keputusan, cetak biru (A-1–A-5, B-1–B-5, N-3, peta menu, pemetaan alamat, urutan tahap), README/handoff Tahap 2, serta laporan audit Tahap 1.
- Menjalankan sendiri seluruh urutan uji yang diminta Human Developer: membuat DB fixture, statis, HTTP, membuat ulang DB, server 8941, dan browser Tahap 2. Ditambah probe independen, regresi browser Tahap 1 untuk sembilan akun, tujuh suite statis terkait, regresi notifikasi V2, dan lint.
- DB hanya salinan `test_redesain_t2_test` dan `test_redesain_t2_kosong_test`. Simulasi layanan pada probe independen menggunakan transaksi dengan `finally { rollback(); }`. Tidak membaca dump pribadi. Direktori pekerjaan lain yang belum terlacak tidak disentuh.
- Kode aplikasi implementator tidak diubah dalam audit ini. Perubahan auditor berupa reproduksi yang dapat dijalankan, bukti, dan status audit. Temuan memerlukan koreksi Tahap 2 serta audit ulang; hasil suite bawaan yang hijau tidak menggantikan kriteria cetak biru.

## Temuan yang menghalangi kelulusan

### F-1 · P2 — Peran yang dipilih berubah otomatis saat membuka halaman lain

Lokasi: `app/Ui/Layout.php:72`, `portal/_guard.php:42`, dan penafsiran implementator butir 2–3.

Reproduksi HTTP: masuk sebagai `sbx_ganda`, pilih Guru melalui POST ber-CSRF, lalu buka `/admin/admin_master_santri.php`. Respons **200**, halaman menyatakan **“Peran beralih ke Admin untuk membuka halaman ini.”**, dan Beranda berikutnya sudah menjadi Admin tanpa pilihan peran baru dari pengguna.

A-2 menyatakan peran menentukan menu dan cakupan di semua halaman, hanya mempersempit hak; A-5 secara eksplisit menyediakan **“Buka sebagai …”** bila akun memiliki peran lain yang cocok. Mengubah sesi dari dalam renderer setelah guard akun lolos mengganti interaksi tersebut dengan perpindahan otomatis. Keputusan AR sendiri mengatur penyimpanan sesi dan tidak mengesahkan perubahan ini. Uji bawaan H2-C justru mengharapkan perilaku otomatis, sehingga kelulusannya tidak membuktikan kecocokan dengan cetak biru.

Koreksi yang diperlukan: pertahankan pilihan sesi sampai pengguna mengganti peran; tawarkan perpindahan eksplisit pada halaman peran lain, dengan pemeriksaan hak nyata dan CSRF di server. Alamat lama tetap harus dapat dipulihkan dengan konteks yang jelas. Uji akun ganda pada halaman dan tautan notifikasi, tanpa mengubah hak akun tunggal atau akses pengawasan Admin.

### F-2 · P2 — Beranda Guru akun ganda menghitung tugas mapel di luar penugasannya

Lokasi: `app/Beranda/BerandaService.php:210` (`kemajuanNilai` memanggil `NilaiService::options($user)`), terkait A-2 dan B-2.

Probe menugaskan **satu** mapel fixture kepada guru milik akun admin+guru `sbx_ganda` dalam transaksi. `featureScopes(CAP_NILAI_INPUT)` mengembalikan **1** cakupan miliknya, tetapi `kemajuanNilai()` mengembalikan **2** tugas: **Fiqih dan Nahwu**. `NilaiService::options()` memakai hak pengawasan Admin dari akun yang sama, sehingga mapel kedua ikut muncul pada Beranda Guru. Menu Nilai memang muncul setelah akun mempunyai penugasan mapel sendiri, sehingga kondisi ini terjangkau dari UI.

B-2 meminta kemajuan per mapel–kelas **dari penugasan guru mapel**. Ini kesalahan cakupan tugas pada peran aktif, bukan bukti akses ke akun yang sama sekali tidak berhak. Koreksi proyeksi Beranda dengan cakupan penugasan sendiri; hak pengawasan pada peran Admin harus tetap tersedia. Jangan menghapus hak Admin dari layanan bisnis global demi memperbaiki tampilan Guru.

### F-3 · P2 — Angka “Bahan terverifikasi” menghitung verifikasi yang sudah tidak berlaku

Lokasi: `app/Beranda/BerandaService.php:372`, terkait B-4.

Probe membuat dua bahan fixture berstatus tersimpan `Terverifikasi` dengan hash sumber lama/tidak cocok, kemudian membandingkan ringkasan dengan matriks `VerifikasiService::read()`. Hasil **Beranda: 2**, sedangkan **matriks: 0**. `BahanRepository::state()` sudah menurunkan status efektif ke Draf/perlu verifikasi ulang saat sumber berubah; query Beranda hanya menjumlah status tersimpan.

Perubahan sumber setelah verifikasi adalah keadaan yang memang didukung V4. Pendidikan dapat mendapat persentase kemajuan yang terlalu tinggi setelah guru mengoreksi nilai. Gunakan status efektif/fingerprint dan himpunan bahan yang sama dengan matriks untuk menghitung terverifikasi/perbaikan, kemudian uji perubahan nilai, sikap, dan kehadiran. Perbaikan ringkasan ini termasuk Tahap 2; tidak memerlukan redesain alur penilaian Tahap 7.

### F-4 · P2 — Tujuan sejumlah kartu tugas tidak menyaring pekerjaan yang dihitung

Lokasi: `app/Beranda/BerandaService.php:69`, `:87`, `:94`, `:105`, `:114`, `:326`; terkait B-1, B-3, L, P, AA.

- Kartu **Pendaftar PSB baru** menghitung 2 akun Baru tetapi membuka `/admin/admin_data.php` tanpa filter status. Reproduksi membaca tabel tujuan: **DITERIMA, Baru, Baru**. B-1 mewajibkan setiap kartu membuka daftar yang sudah tersaring.
- Kartu **Rekomendasi pembinaan belum ditindaklanjuti** menuju `/portal/v3_pelanggaran.php`; halaman tersebut berisi pencatatan/daftar pelanggaran dan tidak menyediakan pilihan rekomendasi untuk ditindaklanjuti. Pilihan rekomendasi yang sudah ada berada di `/portal/v3_konseling.php`. Probe tidak menemukan `rekomendasi_ids[]` di tujuan kartu.
- Pemeriksaan kode juga menemukan kartu **izin >1 hari** hanya mengirim `status=Diajukan` (umur >24 jam tidak diterapkan pada tujuan), **bahan perlu perbaikan** membuka matriks umum tanpa filter, dan **santri tanpa kelas/kamar** memilih salah satu filter sehingga sebagian himpunan hitungan hilang bila kedua jenis kekurangan ada bersamaan.
- Kartu pengurus **Santri mencapai ambang** menuju `/portal/v3_laporan.php` tanpa filter. Laporan tersebut secara bawaan menampilkan baris pelanggaran, bukan himpunan santri berdasarkan agregat poin yang mencapai ambang aktif. `poin_min` yang tersedia menyaring poin per kejadian dan tidak setara dengan total poin santri.

Koreksi yang diperlukan: cocokkan hitungan dan tujuan tiap kartu dengan filter yang benar-benar ditegakkan server. Tambahkan pengujian isi daftar tujuan, termasuk pekerjaan yang tidak memenuhi syarat dan pekerjaan di halaman berikutnya. Perubahan endpoint/filter yang diperlukan tetap bagian penerimaan Tahap 2, dengan regresi modul terkait; jangan menunda fungsi klik kartu yang diwajibkan ke tahap tampilan modul berikutnya.

## Penilaian cakupan dan penafsiran implementator

| Butir | Hasil audit |
| --- | --- |
| A–E: 17 menu Admin/7 grup, penamaan/pengelompokan | Sesuai pada fixture. Fungsi lama tetap dijangkau melalui tab/tombol. |
| F–K: menu peran lain | Sesuai pada fixture, dengan H tetap dijadwalkan Tahap 4. K/AK diuji di server. |
| L/P/AA, B-1–B-3 | Urutan empat tempat, isi nol, kelebihan, warna >24 jam, dan angka fixture dasar lulus; cakupan akun ganda dan tujuan kartu belum memenuhi F-2/F-4. |
| A-1, A-3, A-4 | Masuk, pesan, tampilkan sandi, kerangka wajib ganti sandi, validasi server, dialog Batal/Keluar dan POST/CSRF lulus. |
| A-2/A-5/AR | Pemilih POST/CSRF, penolakan peran di luar hak, dan reset saat masuk lulus; perpindahan otomatis belum sesuai (F-1). |
| B-4 | Per kelas dan tautan kelengkapan lulus fixture dasar; status verifikasi setelah sumber berubah gagal (F-3). |
| B-5 | Pilihan anak terhubung, penolakan `?anak=` lain, izin/rapor/pembinaan lulus. Nama pembimbing tetap keputusan Z/Tahap 5. |
| N-3/AK | Akun guru mapel/Pendidikan dapat membuka pusat notifikasi. Daftar/detail/buka/tandai milik akun lain tidak membocorkan isi atau mengubah data; tandai semua hanya milik sendiri; kategori dan kelompok hari lulus. |
| AQ | Pencarian menu di peramban, tanpa request fetch, sesuai menu peran; keyboard dan laci lulus. |
| Penafsiran 1 | Diterima: peta menu final G menyatukan tugas Murobi dalam Guru; tidak perlu membuat peran database baru. |
| Penafsiran 2 | Tidak diterima sebagai pengganti A-2/A-5 (F-1/F-2). Tidak ada keputusan Human Developer yang mengganti interaksi cetak biru tersebut. |
| Penafsiran 3 | Alamat kompatibilitas yang sudah mengalihkan lulus; halaman lama tetap terbuka di menu/tab baru dapat diterima untuk transisi. Pengalihan penugasan murobi/pembimbing mengikuti G-1/Tahap 6. Penggantian sesi otomatis lewat tautan mode tetap perlu diselesaikan bersama F-1. |
| Penafsiran 4–6 | Diterima: H/J-6 Tahap 4; data pembimbing bagi orang tua Z Tahap 5; tab fungsi lama dipertahankan sampai tahap terkait. Profil binaan D-10/AB juga tetap Tahap 6. |
| Penafsiran 7 | Kata “belum lunas” mengikuti keputusan L. Pemisahan Diajukan >24 jam dan Perlu Penetapan Admin masuk akal; tujuan tersaringnya tetap F-4. |
| Penafsiran 8 | Diterima: HTTP halaman penolakan notifikasi tetap 200 sesuai WN-5a; layanan menolak pemilik lain, isi/data tidak berubah. |
| Penafsiran 9–10 | Diterima: pemindahan kerangka lama Tahap 3 dan label bilah bawah “Perizinan” tidak menghilangkan fungsi. |
| Penafsiran 11 | Klaim “satu query” tidak tepat. `notificationPreview()` memanggil `index()`: COUNT daftar + SELECT daftar + unreadCount, yaitu tiga SELECT notifikasi tambahan pada jalur sukses, di luar ui_context. Catatan biaya query perlu diperbaiki; nonblokir. |

## Bukti pengujian auditor

Bukti teks di `audit-codex/`. Tangkapan terpilih hasil eksekusi auditor tersedia untuk 375, 768, dan 1280 piksel. Visual yang diperiksa langsung: Beranda Admin ketiga ukuran, Pendidikan HP, dan Guru Mapel desktop. Pengukuran otomatis regresi mencakup semua menu sembilan akun × tiga lebar.

| Pengujian | Hasil |
| --- | --- |
| Persiapan DB sesuai skrip, dua kali sebelum suite yang diminta | exit 0; sandbox asal tidak diubah |
| Statis Tahap 2 | **96/0** |
| HTTP Tahap 2 | **162/0** |
| Browser Tahap 2, 375/768/1280 | **87/0** |
| Probe independen `tests/redesain_web_tahap2_audit.php` | **4 lulus / 6 gagal**, exit 1, mewakili empat temuan di atas |
| Browser regresi Tahap 1, sembilan akun, 375/768/1280 | **107/0**, tanpa galat JS |
| Tujuh regresi statis: Tahap 1, perapihan, penugasan, V2 Fase 1/2/4, V3 Fase 4 | **69/132/282/127/175/289/18 lulus**, seluruh exit 0 |
| Notifikasi V2 `V2_PHASE4_RUN_WEB=1`, DB salinan Tahap 2 | **45/1**, exit 1. WN-3b mencari teks peka kapital “belum dibaca”/“Semua sudah dibaca”; UI baru memakai chip “Belum dibaca”. Kontrak pemilik/CSRF tetap lulus. Ketidakcocokan assertion teks ini dicatat, bukan disembunyikan sebagai suite lulus. |
| Lint semua PHP yang berubah dari baseline + probe auditor | **48 berkas, 0 gagal**; `node --check assets/ui/alhasan.js` exit 0 |
| `git diff --check` | bersih |

Reproduksi temuan:

```bash
bash tests/redesain_web_tahap2_db.sh
DB_NAME=test_redesain_t2_test php -S 127.0.0.1:8941 tests/redesain_web_tahap1_router.php
# Terminal kedua:
DB_NAME=test_redesain_t2_test php tests/redesain_web_tahap2_audit.php
```

Jangan menjalankan probe bersamaan dengan reset DB atau agen lain. Exit 1 pada probe saat ini adalah hasil penerimaan yang gagal. Fixture layanan dikembalikan lewat rollback, tetapi login tetap memperbarui metadata login pada DB uji sebagaimana aplikasi biasa.

## Batas uji dan handoff

- Chromium lokal; belum Safari/iOS, Firefox, perangkat fisik, pembaca layar, atau axe. Tidak ada klaim uji produksi/cPanel atau pengukuran beban produksi.
- Bukti regresi implementator dalam `regresi.txt` tidak diklaim sebagai eksekusi auditor. Audit hanya mengklaim uji pada tabel di atas; tidak menjalankan ulang semua suite V4, API, concurrency, PDF, atau mobile.
- Status HTTP alamat kompatibilitas dan menu diuji oleh HTTP Tahap 2 serta regresi browser. Audit ini tidak membuat ulang matriks perbandingan dua server baseline implementator.
- **Kembalikan ke implementator Claude untuk koreksi empat temuan dalam Tahap 2, lalu audit ulang.** Pertahankan regresi pemilik notifikasi, CSRF, URL lama, dan tampilan tiga ukuran. Pembetulan assertion WN-3b serta biaya query harus dicatat jujur.
- **Tahap 2 belum lulus; Tahap 3 belum dimulai.** Kelulusan mendatang tetap memerlukan bukti koreksi dan audit, dan tahap berikutnya memerlukan instruksi Human Developer.
