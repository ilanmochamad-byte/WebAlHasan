# Penilaian seluruh klaim penerimaan oleh Codex

Tanggal: 30 Agustus 2026. Diperbarui sesudah keputusan lanjutan B-1/A-06/A-07/API. Penilaian atas hasil koreksi audit, bukan pengesahan klaim implementer awal.

**TERVERIFIKASI** berarti bukti memadai pada cakupan sandbox yang disebut; bukan jaminan semua perangkat/data produksi. **TIDAK TERVERIFIKASI** berarti ada bukti yang menyangkal klaim/alasan bukti. **MENUNGGU VERIFIKASI** berarti bukti baru sebagian atau perlu keputusan/perangkat.

ID Kx-yy mengikuti nomor koreksi dan urutan baris pada `status-penerimaan.md`. Semua 77 baris dipertahankan. Sumber log dan temuan A-01..A-08: [hasil-audit-codex.md](hasil-audit-codex.md). Nama KA/KW/KG/KP/KL merujuk `perapihan_integration`; PM merujuk `perapihan_web_smoke`; NAV merujuk `v2_phase2_navigasi_murobi`; B merujuk uji browser. Semua suite tersebut dijalankan sendiri oleh auditor.

Rekap terkini: **60 TERVERIFIKASI**, **3 TIDAK TERVERIFIKASI**, **14 MENUNGGU VERIFIKASI**. Belum lulus paket. Audit awal memiliki 57/4/16; hasil dan perubahan putusan dijelaskan di [hasil-audit-lanjutan-codex.md](hasil-audit-lanjutan-codex.md).

## Koreksi 1

| ID | Klaim implementer | Putusan audit | Alasan / bukti / batas |
| --- | --- | --- | --- |
| K1-01 | Akun orang tua tidak lagi menampilkan Guru sebagai pilihan semu | **TERVERIFIKASI** | Paket static dan KA-2 dijalankan ulang; relasi master wajib. Ini bukan penghapusan hak guru sah pada akun multi-peran. |
| K1-02 | Penambahan satu role mempertahankan role lainnya | **TERVERIFIKASI** | KA-5/KA-6: tambah/cabut satu role tidak menghapus role lain. |
| K1-03 | Penetapan role tanpa hubungan data yang valid ditolak server | **TERVERIFIKASI** | KA-2/KA-3: penetapan tanpa relasi valid ditolak server. |
| K1-04 | Admin terakhir tetap terlindungi, termasuk pada permintaan bersamaan | **TERVERIFIKASI** | KC dijalankan tiga kali; audit admin 12 putaran lima proses/campuran/diri sendiri, minimum teramati 1. Batas pembuktian concurrency ada di laporan. |
| K1-05 | Seluruh perubahan hak akses tercatat | **MENUNGGU VERIFIKASI** | KA-10 membuktikan audit grant/revoke; belum menguji isi dan kegagalan audit untuk setiap mutasi status/reset. Perlu uji seluruh tipe perubahan, bukan mengartikan satu assertion sebagai semuanya. |
| K1-06 | Perubahan hak berlaku pada pemeriksaan server; sesi lama tidak mempertahankan hak yang dicabut | **TERVERIFIKASI** | KA-11a/b membaca ulang role setelah pencabutan; pemeriksaan kode Authorization tidak memakai role sesi sebagai otoritas. PM-9 mencakup pergantian akun. |
| K1-07 | Halaman akun lama punya jalur transisi aman tanpa melewati validasi mutasi/CSRF | **TERVERIFIKASI** | PM-10 dan audit HTTP: POST alamat lama diteruskan, CSRF tidak sah menghasilkan 419 tanpa Location. Guard tujuan tetap admin. |

## Koreksi 2

| ID | Klaim implementer | Putusan audit | Alasan / bukti / batas |
| --- | --- | --- | --- |
| K2-01 | Dua saudara dapat memakai satu identitas wali | **TERVERIFIKASI** | KW-1/2 membuat dua saudara dengan satu wali yang sama pada sandbox. |
| K2-02 | Dua orang bernama sama tetap dapat disimpan sebagai orang berbeda | **TERVERIFIKASI** | KW-4 mempertahankan dua ID berbeda meskipun nama dan HP sama. |
| K2-03 | Koreksi data tidak memperluas akses akun orang tua tanpa hubungan yang dikonfirmasi | **TERVERIFIKASI** | KW-10 menolak merge yang terkait akun sumber; konfirmasi dampak tetap wajib. B-4 tidak diubah. |
| K2-04 | Data lama, impor/ekspor terkait, dan riwayat tetap dapat digunakan | **TERVERIFIKASI** | KW-17/18 dan regresi V1 membuktikan data impor sintetis/kolom lama tetap terbaca. Bukan pembuktian seluruh data produksi atau collation B-2. |
| K2-05 | Penyimpanan santri, wali baru, dan relasi bersifat atomik | **TERVERIFIKASI** | KW-7 dan audit wali membuktikan penolakan konflik membatalkan perubahan relasi/kolom dalam transaksi. |
| K2-06 | Pengiriman ulang tidak membuat data ganda | **TERVERIFIKASI** | Audit HTTP mengirim dua POST identik untuk santri dan wali: masing-masing tepat satu identitas. Pengganti bukti statis token saja. |
| K2-07 | Pembuatan/pemilihan wali tidak membuat akun login | **TERVERIFIKASI** | KW-5 memeriksa tidak ada akun login untuk wali baru/pilihan; jalur master tidak menambah users. |
| K2-08 | Identitas wali bersama menampilkan santri terdampak sebelum konfirmasi | **MENUNGGU VERIFIKASI** | KW-14/15 membuktikan konfirmasi server; keberadaan markup daftar terdampak dibaca. Perlu verifikasi UI semua nama terdampak benar-benar terlihat sebelum submit, termasuk daftar panjang. |
| K2-09 | Laporan kandidat duplikasi, konflik, dan relasi belum lengkap | **TERVERIFIKASI** | KW-16 menghasilkan empat bagian laporan; fixture audit menguji benturan dan kandidat hasil merge. Kebenaran identitas data lama B-3 tetap keputusan admin. |
| K2-10 | Tidak ada penggabungan massal | **TERVERIFIKASI** | Audit rute/form/layanan dan static: merge hanya menerima satu pasangan ID, tanpa aksi massal. |
| K2-11 | Penggabungan yang bertentangan diblokir dan meminta penyelesaian eksplisit | **TERVERIFIKASI** | KW-9/10 dan koreksi A-03: tanpa konfirmasi/terkait akun ditolak; merge berlawanan bersamaan tidak lagi mengarsipkan keduanya. |
| K2-12 | ID lama dan jejak perubahan dipertahankan | **TERVERIFIKASI** | KW-11/13 serta fixture benturan mempertahankan ID wali/relasi dan audit. Relasi ganda diarsipkan, tidak dihapus. |
| K2-13 | Kolom ayah/ibu lama tidak dihapus; tidak ada dua sumber pengeditan | **TERVERIFIKASI** | Kolom lama ada setelah migrasi; A-02 menutup POST palsu kolom lama. Impor legacy tetap jalur kompatibilitas, bukan field edit web kedua. |
| K2-14 | Nilai lama yang bertentangan tidak ditimpa tanpa audit dan keputusan admin | **TERVERIFIKASI** | Baru terverifikasi setelah A-02: 16 pemeriksaan termasuk HP kosong/berbeda dan nama berspasi ganda. Nilai asli diaudit dan konfirmasi wajib. |

## Koreksi 3

| ID | Klaim implementer | Putusan audit | Alasan / bukti / batas |
| --- | --- | --- | --- |
| K3-01 | Tidak ada pilihan tugas lama pada formulir guru | **TERVERIFIKASI** | Static dijalankan ulang, form guru dibaca: pilihan Guru/Pembimbing/Keduanya tidak tersedia. |
| K3-02 | Label menjadi "Data Guru" | **TERVERIFIKASI** | Static dan halaman memakai label Data Guru. |
| K3-03 | Hak murobi berasal dari akun guru dan penugasan aktif yang valid | **TERVERIFIKASI** | NAV-0a/b serta cakupan murobi pada regresi: akun guru dan penugasan aktif pada tahun aktif tetap diperlukan. |
| K3-04 | Akun, jadwal, absensi, dan riwayat lama tidak rusak | **TERVERIFIKASI** | KG-1 dan suite regresi V1 berjalan ulang pada sandbox; riwayat/relasi sintetis tetap utuh. Tidak mencakup seluruh data produksi. |
| K3-05 | Guru tanpa jadwal tetap dapat ditugaskan sebagai murobi | **TERVERIFIKASI** | KG-2 menguji guru tanpa jadwal dapat ditugaskan sebagai murobi. |
| K3-06 | Nilai tugas lama tidak diubah otomatis menjadi Guru | **TERVERIFIKASI** | KG-1 membuktikan status Keduanya dipertahankan sesudah save. |
| K3-07 | Guru berlabel Pembimbing tidak dipindahkan menjadi pengurus | **TERVERIFIKASI** | Tidak ada migrasi/pemindahan guru ke pengurus pada diff; pembaruan guru tidak menulis status. Ini klaim ketiadaan transformasi, diperiksa pada kode dan regresi. |

## Koreksi 4

| ID | Klaim implementer | Putusan audit | Alasan / bukti / batas |
| --- | --- | --- | --- |
| K4-01 | Perpindahan jadwal–pertemuan mudah dan tidak kehilangan konteks | **TERVERIFIKASI** | Browser B-4 pada 1440/768/390 dan perpindahan tab Safari macOS berjalan; konteks pada tautan diperiksa. |
| K4-02 | Satu jadwal tetap mempunyai banyak pertemuan | **TERVERIFIKASI** | Regresi phase3_integration dijalankan ulang, bukan hanya merujuk bukti historis. |
| K4-03 | Tidak ada pertemuan atau absensi ganda | **TERVERIFIKASI** | Keunikan dan transaksi pertemuan/absensi diuji ulang oleh regresi V1 Fase 3/4/5. |
| K4-04 | Guru hanya mengakses jadwal dan pertemuannya sendiri | **TERVERIFIKASI** | KP-2 serta audit HTTP URL modul dan alias lama: detail jadwal guru lain 403. Cakupan pertemuan tetap diuji regresi. |
| K4-05 | Guru tidak memperoleh hak pengelolaan jadwal admin | **TERVERIFIKASI** | Audit HTTP POST palsu dengan CSRF sah pada modul dan alias jadwal menghasilkan 403; jumlah jadwal tidak bertambah. |
| K4-06 | Alamat halaman lama tetap dapat diakses | **TERVERIFIKASI** | PM-10/NAV-7a serta audit POST alias lama 419 tanpa redirect untuk CSRF tidak sah. |
| K4-07 | Penyimpanan jadwal dan pertemuan tetap terpisah | **TERVERIFIKASI** | Layanan/tabel penyimpanan terpisah dan regresi masing-masing dijalankan; modul hanya menyatukan UI. |
| K4-08 | Snapshot peserta dan audit dipertahankan | **TERVERIFIKASI** | KP-1 dan regresi pertemuan memeriksa snapshot/audit tetap ada. |
| K4-09 | Riwayat peserta tidak berubah saat santri pindah kelas | **TERVERIFIKASI** | Regresi phase3_integration dijalankan ulang, mempertahankan peserta snapshot meski keanggotaan kelas berubah. |

## Koreksi 5

| ID | Klaim implementer | Putusan audit | Alasan / bukti / batas |
| --- | --- | --- | --- |
| K5-01 | Mode Santri: 30 catatan | **TERVERIFIKASI** | KL-1/2/3 dan fixture audit nyata: ringkasan/items/export santri 30. |
| K5-02 | Mode Guru: satu catatan | **TERVERIFIKASI** | KL dan fixture audit nyata: ringkasan/items/export guru 1. |
| K5-03 | Mode Gabungan: 31 catatan | **TERVERIFIKASI** | KL dan fixture audit nyata: ringkasan/items/export gabungan 31. |
| K5-04 | Ringkasan, detail, dan ekspor menghasilkan jumlah sesuai filter yang sama | **TERVERIFIKASI** | Fixture audit memeriksa summary/items/export untuk ketiga scope pada filter identik; KL-4 juga dijalankan. |
| K5-05 | Mode gabungan menampilkan penanda jenis dan jumlah masing-masing | **TERVERIFIKASI** | KL-5/6 dan keluaran laporan gabungan memperlihatkan jenis serta hitungan guru/santri. |
| K5-06 | Guru tetap tampil sebagai pengampu pada laporan santri, tidak dihitung sebagai santri | **TERVERIFIKASI** | KL-7: guru pengampu ada pada baris santri tetapi tidak menambah jumlah santri. |
| K5-07 | Filter yang sama berlaku pada ringkasan, detail, CSV, dan cetak/PDF | **MENUNGGU VERIFIKASI** | Lanjutan A-07 membuktikan 30/1/31 pada layar/CSV/cetak dengan filter identik, detail sendiri 200 dan detail asing 403. Belum membandingkan setiap kombinasi tanggal/status/scope di semua keluaran, terutama PDF Safari tersimpan. |
| K5-08 | Absensi guru tidak dihapus | **TERVERIFIKASI** | KL-11 dan fixture: absensi guru tetap tersimpan; scope hanya menyaring penyajian. |
| K5-09 | Default dan kontrak API lama tidak berubah diam-diam | **TERVERIFIKASI** | A-09: seluruh JSON API /reports tanpa parameter identik dengan snapshot main c65390d pada fixture sama, gabungan 31. API mengabaikan subject_scope seperti baseline; filter/options/cetak tidak membawa metadata web. 13 uji API dan client mobile asli lulus. |
| K5-10 | Batas ekspor, formula injection, isolasi akses, pengaman cetak tetap berlaku | **TERVERIFIKASI** | A-06 sesuai keputusan lanjutan: 20.001 ditolak 422 EXPORT_TOO_LARGE, tepat 20.000 lengkap, per_page tidak melewati batas, scope diterapkan sebelum batas. Empat uji nyata lulus; regresi formula/cetak dan 38 uji isolasi laporan juga lulus. Batas perangkat tetap di K6-16. |

## Koreksi 6

| ID | Klaim implementer | Putusan audit | Alasan / bukti / batas |
| --- | --- | --- | --- |
| K6-01 | Standar bersama untuk warna, spasi, judul, tombol, formulir, tabel, badge, dialog, pesan | **MENUNGGU VERIFIKASI** | Token dan komponen bersama tersedia, browser sampel berjalan. A-05 dikoreksi, tetapi keseragaman seluruh dialog/form/halaman belum dibuktikan; kamar masih legacy. |
| K6-02 | Bootstrap dipertahankan, komponen bersama, tanpa aset eksternal baru | **TERVERIFIKASI** | Diff mempertahankan Bootstrap/PHP native dan komponen bersama, tidak menambah host aset baru. Uji browser memakai aset lokal; bukan bukti ketersediaan CDN produksi. |
| K6-03 | Sidebar/topbar konsisten; menu mengikuti role dan kemampuan aktual | **TIDAK TERVERIFIKASI** | A-07 telah dikoreksi: menu laporan guru/murobi membuka laporan sendiri, notifikasi guru non-murobi tidak ditawarkan; HTTP 29 dan laporan 38 lulus. Bagian klaim konsistensi sidebar/topbar seluruh halaman belum terpenuhi karena kamar tetap legacy (A-08). |
| K6-04 | Komponen navigasi terpisah dari guard khusus admin | **TERVERIFIKASI** | Pencarian Navigation hanya pada Layout/sidebar, tanpa guard dan tanpa dipakai layanan otorisasi. Guard tetap di tujuan. |
| K6-05 | Menu ponsel dapat dibuka/ditutup, tombol mudah disentuh | **TERVERIFIKASI** | B-3a/b/c dan B-1c dijalankan pada 768/390 untuk kerangka baru. Menu halaman legacy tidak termasuk kesimpulan ini. |
| K6-06 | Menu aktif, breadcrumb, judul, tindakan utama jelas | **MENUNGGU VERIFIKASI** | A-05 memperbaiki H1 ganda; A-07 memperbaiki menu operasional. Browser guru 390 memverifikasi satu H1 dan jalur laporan. Menu aktif/breadcrumb sampel lulus, tetapi kamar dan seluruh alur belum diverifikasi. |
| K6-07 | Formulir dikelompokkan, label jelas, validasi dekat kolom | **MENUNGGU VERIFIKASI** | Pengelompokan dan label terlihat pada form baru; static bukan bukti validasi dekat setiap kolom. Perlu uji pesan invalid pada seluruh form A/B. |
| K6-08 | Isian dipertahankan saat validasi gagal | **MENUNGGU VERIFIKASI** | A-04 dan HTTP memverifikasi form santri/wali; form legacy lain belum diuji satu per satu. ah_old_keep saja tidak membuktikan klaim menyeluruh. |
| K6-09 | Keadaan kosong / berhasil / gagal / akses ditolak yang mudah dipahami | **MENUNGGU VERIFIKASI** | B-10b membuktikan 403 yang menjelaskan jalan keluar; contoh kosong/berhasil tersedia. Semua mode error/empty tiap halaman belum diuji. |
| K6-10 | Pencarian/pagination untuk daftar besar | **TIDAK TERVERIFIKASI** | Alasan bukti menyebut seluruh daftar, tetapi kelas/tahun masih mengambil daftar penuh tanpa pagination. Uji daftar besar seluruh modul belum tersedia; klaim menyeluruh tidak dapat dipertahankan. |
| K6-11 | Tabel nyaman pada layar kecil tanpa melebarkan halaman | **TERVERIFIKASI** | Tidak ada overflow halaman pada sampel browser resmi 390 dan pemeriksaan tambahan B/D. Tabel memakai area gulir. Hasil dibatasi halaman/data yang diuji, bukan seluruh perangkat. |
| K6-12 | Tindakan berisiko menjelaskan dampak sebelum konfirmasi | **MENUNGGU VERIFIKASI** | Konfirmasi admin/merge/timpa ada, termasuk koreksi A-04. Belum memeriksa setiap dialog legacy serta teks dampaknya secara manual. |
| K6-13 | Makna tidak bergantung warna/ikon saja | **MENUNGGU VERIFIKASI** | Badge sampel memiliki teks; static tidak membuktikan seluruh pesan/aksi bebas ketergantungan warna/ikon. Perlu pemeriksaan aksesibilitas menyeluruh. |
| K6-14 | Navigasi keyboard, fokus terlihat, label pembaca layar, kontras memadai | **MENUNGGU VERIFIKASI** | B-7 dan fokus CSS lulus, tetapi kontras, nama aksesibel semua kontrol dan pembaca layar belum diaudit. Tidak boleh menganggap sebagian bukti sebagai kelulusan seluruh klaim. |
| K6-15 | Preferensi pengurangan animasi dihormati | **MENUNGGU VERIFIKASI** | prefers-reduced-motion ada dalam CSS dan static lulus; belum menguji perilaku animasi saat preferensi perangkat benar-benar aktif. |
| K6-16 | Halaman cetak/PDF tetap tanpa sidebar, margin dan pagination tidak berubah | **MENUNGGU VERIFIKASI** | PDF Chromium 175 lulus dan sampel visual tanpa sidebar; Safari hanya pratinjau 4 halaman, Save PDF belum berhasil. Kesetaraan cetak lintas browser menunggu. |
| K6-17 | Inventaris halaman agar tidak ada yang tertinggal | **TIDAK TERVERIFIKASI** | A-08: inventaris awal keliru memasukkan kamar sebagai adaptor. Dokumen sudah dikoreksi; modernisasi semua halaman belum dibuktikan. |
| K6-18 | Dampak CSS bersama pada halaman lama diperiksa | **MENUNGGU VERIFIKASI** | Sampel D dashboard/PSB/rekap keuangan 390 tidak melebar; belum seluruh 13 halaman D dan semua breakpoint/mode operasi. |

## Koreksi 7

| ID | Klaim implementer | Putusan audit | Alasan / bukti / batas |
| --- | --- | --- | --- |
| K7-01 | `/portal/` tanpa sesi menampilkan login | **TERVERIFIKASI** | PM-1a/b/c dan browser B-1a: anonim mendapat form login portal. |
| K7-02 | Login berhasil untuk admin, guru non-murobi, murobi, pengurus, orang tua | **TERVERIFIKASI** | PM-2 dan audit HTTP login lima fixture peran berhasil. |
| K7-03 | Guru non-murobi masuk beranda umum tetapi ditolak dari fungsi keputusan perizinan | **TERVERIFIKASI** | PM-3/NAV-16 dijalankan ulang: beranda umum 200; fungsi perizinan/keputusan guru non-murobi 403. |
| K7-04 | Pengguna yang sudah login tidak diminta login ulang | **TERVERIFIKASI** | PM-4/PM-6b memeriksa sesi aktif tidak dipaksa login ulang. |
| K7-05 | Akun multi-peran dapat memakai seluruh menu yang diizinkan | **TERVERIFIKASI** | PM-5 kembali lulus untuk akun multi-peran. A-07 kini menyelaraskan menu laporan guru dengan guard; hak admin pada akun gabungan tetap dipertahankan. |
| K7-06 | Alamat login lama tetap berfungsi | **TERVERIFIKASI** | PM-6a dan pemeriksaan alias login dijalankan ulang. |
| K7-07 | Password sementara, password salah, akun nonaktif, sesi kedaluwarsa, logout ditangani benar | **TERVERIFIKASI** | PM-7a..g dijalankan ulang untuk password sementara/salah, akun nonaktif, sesi, logout. Tidak memakai credential produksi. |
| K7-08 | Tidak ada redirect loop atau pengalihan eksternal | **TERVERIFIKASI** | A-01 36 pemeriksaan dan PM-8: tujuan jahat ditolak; alias yang diuji tidak berputar. Tidak mengklaim pengujian tak terbatas semua URL. |
| K7-09 | Tautan detail tetap diperiksa haknya setelah login, termasuk setelah berganti akun | **TERVERIFIKASI** | PM-9a/b/c dan detail jadwal asing pada audit HTTP: tujuan tetap mengecek hak sesudah login/pergantian akun. |
| K7-10 | Tampilan login dan navigasi berfungsi pada desktop serta ponsel | **TERVERIFIKASI** | B-1/B-2/B-3 pada 1440/768/390; Safari macOS sampel login/home. Perangkat iOS/Android nyata masih menunggu, bukan bagian bukti viewport Chromium. |
| K7-11 | API dan login aplikasi mobile tetap kompatibel | **TERVERIFIKASI** | B-1: audit fungsi sebelum memperbarui assertion (289 statis lulus); client TypeScript mobile asli menjalankan 18 operasi login/notifikasi/laporan/print pada API sandbox. A-09 mempertahankan skema baseline; tsc/lint dan enam test print mobile lulus. Kesimpulan kompatibilitas pada cakupan client/server ini, bukan bukti UI/push/perangkat fisik. |
| K7-12 | Menyembunyikan menu bukan pengganti otorisasi | **TERVERIFIKASI** | Guard server tetap terpisah dari Navigation. Permintaan langsung ke JSON/master/partial/detail asing ditolak; A-07 memakai guard laporan baca-saja dan memaksa cakupan guru. Menu bukan sumber otorisasi. |

## Lima butir tertunda pada dokumen asal

| Butir asal | Putusan audit | Rincian |
| --- | --- | --- |
| Safari fisik macOS/iOS | **MENUNGGU VERIFIKASI** | macOS login/home/tab/print-preview telah diuji nyata; PDF Safari tersimpan dan iOS belum. |
| Perangkat Android/iOS untuk web | **MENUNGGU VERIFIKASI** | Tidak diuji pada perangkat fisik; viewport 390 bukan pengganti. |
| Kontras dan pembaca layar | **MENUNGGU VERIFIKASI** | Belum axe/VoiceOver/TalkBack menyeluruh. |
| Migrasi 010 produksi | **MENUNGGU VERIFIKASI** | Sandbox sudah diterapkan/idempoten; produksi sengaja tidak disentuh. |
| Audit Codex seluruh paket | **MENUNGGU VERIFIKASI** | B-1/A-06/A-07/A-09 selesai pada lingkup audit lanjutan; tiga klaim tidak terverifikasi dan 14 klaim parsial tetap mencegah penutupan penerimaan seluruh paket. |
