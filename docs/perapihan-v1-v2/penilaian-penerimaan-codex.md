# Penilaian seluruh klaim penerimaan oleh Codex

Tanggal: 30 Agustus 2026. Diperbarui sesudah keputusan lanjutan B-1/A-06/A-07/API penyelesaian kamar/pagination A-08/A-10, serta verifikasi lanjutan 14 klaim (A-12–A-17). Penilaian atas hasil koreksi audit, bukan pengesahan klaim implementer awal.

**TERVERIFIKASI** berarti bukti memadai pada cakupan sandbox yang disebut; bukan jaminan semua perangkat/data produksi. **TIDAK TERVERIFIKASI** berarti ada bukti yang menyangkal klaim/alasan bukti. **MENUNGGU VERIFIKASI** berarti bukti baru sebagian atau perlu keputusan/perangkat.

ID Kx-yy mengikuti nomor koreksi dan urutan baris pada `status-penerimaan.md`. Semua 77 baris dipertahankan. Sumber log dan temuan A-01..A-08: [hasil-audit-codex.md](hasil-audit-codex.md). Nama KA/KW/KG/KP/KL merujuk `perapihan_integration`; PM merujuk `perapihan_web_smoke`; NAV merujuk `v2_phase2_navigasi_murobi`; B merujuk uji browser. Semua suite tersebut dijalankan sendiri oleh auditor.

Rekap terkini: **75 TERVERIFIKASI**, **0 TIDAK TERVERIFIKASI**, **2 MENUNGGU VERIFIKASI**. Belum lulus paket. Riwayat: audit awal 57/4/16; sesudah B-1/A-06/A-07/API menjadi 60/3/14; sesudah kamar/pagination menjadi 63/0/14. Lanjutan verifikasi 14 klaim menutup 12 klaim pada cakupan sandbox. Bukti terakhir: [audit-14-klaim.md](audit-14-klaim.md). A-17 adalah cacat cetak pra-ada yang masih terbuka; tidak disembunyikan oleh rekap klaim.

## Koreksi 1

| ID | Klaim implementer | Putusan audit | Alasan / bukti / batas |
| --- | --- | --- | --- |
| K1-01 | Akun orang tua tidak lagi menampilkan Guru sebagai pilihan semu | **TERVERIFIKASI** | Paket static dan KA-2 dijalankan ulang; relasi master wajib. Ini bukan penghapusan hak guru sah pada akun multi-peran. |
| K1-02 | Penambahan satu role mempertahankan role lainnya | **TERVERIFIKASI** | KA-5/KA-6: tambah/cabut satu role tidak menghapus role lain. |
| K1-03 | Penetapan role tanpa hubungan data yang valid ditolak server | **TERVERIFIKASI** | KA-2/KA-3: penetapan tanpa relasi valid ditolak server. |
| K1-04 | Admin terakhir tetap terlindungi, termasuk pada permintaan bersamaan | **TERVERIFIKASI** | KC dijalankan tiga kali; audit admin 12 putaran lima proses/campuran/diri sendiri, minimum teramati 1. Batas pembuktian concurrency ada di laporan. |
| K1-05 | Seluruh perubahan hak akses tercatat | **TERVERIFIKASI** | A-12: 36 tes mutasi normal dan kegagalan penulis audit membuktikan rollback akun/role/status/password/relasi/perangkat, pelaku, before/after dan tidak ada credential pada audit. Seluruh jalur layanan akun diuji; audit harus berhasil sebelum commit. |
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
| K2-08 | Identitas wali bersama menampilkan santri terdampak sebelum konfirmasi | **TERVERIFIKASI** | A-16: 45 nama lengkap pada batas agregasi sesi 1 KiB, plus ID kelompok lengkap pada 4 byte (46 tes). UI edit/merge 1440/768/390 menampilkan seluruh 45 nama sebelum konfirmasi; tidak terpotong/tersembunyi dan persetujuan tidak tercentang otomatis. |
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
| K5-07 | Filter yang sama berlaku pada ringkasan, detail, CSV, dan cetak/PDF | **TERVERIFIKASI** | 432 kombinasi admin/guru × scope × status × tanggal × filter kelas/tahun/jadwal/guru dibandingkan dengan oracle independen: summary, baris detail layar, CSV, dan baris cetak sama. Sembilan PDF nyata memuat data yang sama; cacat pemenggalan kata potret A-17 dicatat terpisah. Detail pertemuan tetap snapshot lengkap, bukan tabel baris detail laporan. |
| K5-08 | Absensi guru tidak dihapus | **TERVERIFIKASI** | KL-11 dan fixture: absensi guru tetap tersimpan; scope hanya menyaring penyajian. |
| K5-09 | Default dan kontrak API lama tidak berubah diam-diam | **TERVERIFIKASI** | A-09: seluruh JSON API /reports tanpa parameter identik dengan snapshot main c65390d pada fixture sama, gabungan 31. API mengabaikan subject_scope seperti baseline; filter/options/cetak tidak membawa metadata web. 13 uji API dan client mobile asli lulus. |
| K5-10 | Batas ekspor, formula injection, isolasi akses, pengaman cetak tetap berlaku | **TERVERIFIKASI** | A-06 sesuai keputusan lanjutan: 20.001 ditolak 422 EXPORT_TOO_LARGE, tepat 20.000 lengkap, per_page tidak melewati batas, scope diterapkan sebelum batas. Empat uji nyata lulus; regresi formula/cetak dan 38 uji isolasi laporan juga lulus. Batas perangkat tetap di K6-16. |

## Koreksi 6

| ID | Klaim implementer | Putusan audit | Alasan / bukti / batas |
| --- | --- | --- | --- |
| K6-01 | Standar bersama untuk warna, spasi, judul, tombol, formulir, tabel, badge, dialog, pesan | **TERVERIFIKASI** | A-14/A-15: 120 observasi A/B (40 rute/mode × tiga lebar), dialog admin terbuka, form invalid dan daftar panjang. Kartu/tabel/form/tombol/pesan memakai token bersama; adaptor Bootstrap memperbaiki kontras dan ukuran. Standalone login/password/logout/denial/cetak tetap pengecualian yang disengaja. |
| K6-02 | Bootstrap dipertahankan, komponen bersama, tanpa aset eksternal baru | **TERVERIFIKASI** | Diff mempertahankan Bootstrap/PHP native dan komponen bersama, tidak menambah host aset baru. Uji browser memakai aset lokal; bukan bukti ketersediaan CDN produksi. |
| K6-03 | Sidebar/topbar konsisten; menu mengikuti role dan kemampuan aktual | **TERVERIFIKASI** | A-07 menyelaraskan menu dengan guard; A-08 menuntaskan kamar. Inventaris A/B memakai kerangka bersama; browser kamar menunjukkan menu aktif, topbar, dan laci ponsel berfungsi. Kelompok D sengaja tetap di luar desain ulang. |
| K6-04 | Komponen navigasi terpisah dari guard khusus admin | **TERVERIFIKASI** | Pencarian Navigation hanya pada Layout/sidebar, tanpa guard dan tanpa dipakai layanan otorisasi. Guard tetap di tujuan. |
| K6-05 | Menu ponsel dapat dibuka/ditutup, tombol mudah disentuh | **TERVERIFIKASI** | B-3a/b/c dan B-1c dijalankan pada 768/390 untuk kerangka baru. Menu halaman legacy tidak termasuk kesimpulan ini. |
| K6-06 | Menu aktif, breadcrumb, judul, tindakan utama jelas | **TERVERIFIKASI** | Inventaris UI memeriksa H1 tunggal, satu menu aktif dan breadcrumb pada seluruh rute/mode A/B berkerangka; tindakan formulir dan tab diuji browser. Breadcrumb bawaan melengkapi halaman B; hanya crumb terakhir aria-current. Halaman berdiri sendiri tidak dipaksa memiliki sidebar. |
| K6-07 | Formulir dikelompokkan, label jelas, validasi dekat kolom | **TERVERIFIKASI** | A-15: label for/id diperbaiki; 108 tes HTTP mencakup setiap jenis formulir utama A/B, termasuk pesan dekat kolom dan konteks form. Tes browser memeriksa aria-invalid/describedby; 120 observasi A/B tidak menemukan kontrol tanpa nama. Konflik lintas data tetap dijelaskan lewat alert umum. |
| K6-08 | Isian dipertahankan saat validasi gagal | **TERVERIFIKASI** | 108 tes formulir mencakup master, akun/link, jadwal/pertemuan, penugasan, filter laporan serta pengajuan/empat aksi detail izin. Pilihan santri halaman tiga dan nilai aman tetap ada. Password, token dan konfirmasi tidak dipulihkan. Jadwal yang sebelumnya tidak membaca old-input telah diperbaiki. |
| K6-09 | Keadaan kosong / berhasil / gagal / akses ditolak yang mudah dipahami | **TERVERIFIKASI** | A-13/A-15: validasi gagal terbaca dan dapat diperbaiki; jenis tindakan tombol tetap terkirim, cancel tidak mengunci form. Regresi web/HTTP/kamar/pagination dijalankan ulang untuk sukses, penolakan hak dan pencarian kosong; matriks laporan juga mencakup tanggal tanpa data. Batasnya alur A/B dan fixture sandbox yang dicatat. |
| K6-10 | Pencarian/pagination untuk daftar besar | **TERVERIFIKASI** | A-10: 45 pengujian pagination mencakup daftar baru, 20/20/5 baris, urutan stabil, pencarian/konteks, page ekstrem, dan akses melewati batas lama 100 pada rekonsiliasi/pertemuan. Daftar lama tetap pada regresi. Pemetaan seluruh daftar utama A/B ada di inventaris §F; dropdown/cetak bukan tabel daftar terpaginasikan. |
| K6-11 | Tabel nyaman pada layar kecil tanpa melebarkan halaman | **TERVERIFIKASI** | Tidak ada overflow halaman pada sampel browser resmi 390 dan pemeriksaan tambahan B/D. Tabel memakai area gulir. Hasil dibatasi halaman/data yang diuji, bukan seluruh perangkat. |
| K6-12 | Tindakan berisiko menjelaskan dampak sebelum konfirmasi | **TERVERIFIKASI** | A-15 melengkapi penjelasan dampak status, penugasan, role/link akun dan aksi detail izin. Delapan pembatalan konfirmasi diuji browser tanpa POST; dialog admin dan daftar edit/merge wali panjang diperiksa. Persetujuan server admin/merge/timpa tetap diuji oleh regresi; bukan hanya dialog sisi klien. |
| K6-13 | Makna tidak bergantung warna/ikon saja | **TERVERIFIKASI** | 120 observasi A/B memeriksa kontrol terlihat tanpa nama dan ikon tanpa label (nol); badge, status dan pesan tetap memiliki teks. Dialog admin dan error form diuji terbuka; kontras ditambah pada teks muted/tombol. Ini bukan kelulusan aksesibilitas seluruh halaman legacy D. |
| K6-14 | Navigasi keyboard, fokus terlihat, label pembaca layar, kontras memadai | **MENUNGGU VERIFIKASI** | A-14: axe WCAG 2 A/AA + 2.1 AA, keyboard, fokus, tabel gulir, modal dan laci lulus pada cakupan browser. VoiceOver/TalkBack belum dapat diuji. Mac terkunci; pemeriksaan luas Safari berikutnya ditolak auto-review karena risiko membaca tab pribadi/produksi. Perlu jendela audit Safari khusus yang dibuka pengguna dan izin pemeriksaan terarah. |
| K6-15 | Preferensi pengurangan animasi dihormati | **TERVERIFIKASI** | Browser menjalankan preferensi no-preference dan reduce: transisi laci aktif pada keadaan normal, nyaris nol ketika reduce, sementara buka/tutup tetap berfungsi. Ini pengujian perilaku media preference, bukan hanya pencarian string CSS atau klaim perangkat fisik. |
| K6-16 | Halaman cetak/PDF tetap tanpa sidebar, margin dan pagination tidak berubah | **MENUNGGU VERIFIKASI** | 175 regresi PDF lama lulus; sembilan PDF matriks memiliki data, margin dan nomor halaman benar tanpa sidebar. Namun A-17 menemukan kata Terlambat terpecah pada ketiga PDF potret (pra-ada pada renderer identik main), menunggu keputusan koreksi; PDF Safari tersimpan/perangkat fisik juga belum tersedia. Tidak dinyatakan lulus cetak menyeluruh. |
| K6-17 | Inventaris halaman agar tidak ada yang tertinggal | **TERVERIFIKASI** | A-08: kamar benar-benar dipindah ke Layout dan telah diuji, bukan hanya dikoreksi di dokumen. Inventaris diperbarui sesuai sumber kerangka termasuk detail laporan A-07 dan pagination A-10. Pengecualian D/E tetap eksplisit. |
| K6-18 | Dampak CSS bersama pada halaman lama diperiksa | **TERVERIFIKASI** | Semua 13 rute D pada 1440/768/390 diuji dengan CSS bersama aktif/nonaktif dan aset JS tersedia: tidak ada overflow baru akibat CSS atau pageerror. Pelanggaran pada 390 tetap 526 px dengan/tanpa CSS; sumber halaman identik baseline. Pelanggaran axe legacy dicatat, tidak diperbaiki diam-diam dan tidak disebut lulus. admin_izin ternyata alias portal, dicatat sesuai tujuan aktual. |

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
| Kontras dan pembaca layar | **MENUNGGU VERIFIKASI** | Axe dan keyboard sudah dijalankan; VoiceOver/TalkBack fisik masih belum dapat diuji. |
| Migrasi 010 produksi | **MENUNGGU VERIFIKASI** | Sandbox sudah diterapkan/idempoten; produksi sengaja tidak disentuh. |
| Audit Codex seluruh paket | **MENUNGGU VERIFIKASI** | 75 dari 77 klaim terverifikasi pada cakupan bukti; dua klaim perangkat/cetak masih menunggu, dengan A-17 pra-ada memerlukan keputusan. Paket belum ditutup dan tidak mendapat izin push/merge/deploy. |
