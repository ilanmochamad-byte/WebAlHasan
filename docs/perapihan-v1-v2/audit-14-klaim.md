# Verifikasi lanjutan 14 klaim — Codex

Instruksi pengguna: verifikasi seluruh 14 klaim yang masih menunggu, sesudah
`3413895`, pada branch `codex/perapihan-v1-v2-ui`. Tidak ada izin push, merge,
deploy, perubahan mobile, atau perubahan produksi. Dokumen ini dilengkapi
bersama bukti eksekusi; status tidak diubah hanya karena kode telah dibaca.

## A-12 — P1: audit akun tidak atomik dengan perubahan hak/status

K1-05: sebelum koreksi, AccountService dan PerizinanAccountService menyimpan
mutasi lebih dahulu, kemudian mengabaikan hasil boolean AuditLogger::log.
Kegagalan audit dapat meninggalkan akun/role/status/password yang sudah berubah.

Koreksi: audit wajib berhasil di dalam transaksi yang sama. Jalur create guru,
create/link pengurus dan orang tua memanggil audit sebelum commit repository;
grant/revoke/status/reset memakai transaksi layanan. Pencabutan perangkat pada
penonaktifan ikut transaksi. Lock perlindungan admin terakhir dipertahankan,
dan pembacaan role sebelum perubahan dipindah setelah lock.

Tes baru `perapihan_audit_account_log.php` menggunakan penulis audit yang sengaja
tidak tersedia (koneksi terpisah ditutup) serta penulis normal. Memeriksa rollback
akun/role/relasi/perangkat, pelaku, entitas, before/after, dan ketiadaan credential
pada semua jalur. Tidak memodifikasi tabel audit, data lama, atau skema untuk
simulasi kegagalan. Fixture sendiri dibersihkan setelah tes.

## A-13 — P1: pengaman klik ganda membuang tindakan tombol

K6-09/K6-12: handler submit bersama menonaktifkan tombol sebelum browser
membentuk body POST. Tombol bernama `action` tidak ikut terkirim; konfirmasi
yang dibatalkan juga meninggalkan tombol disabled. Reproduksi browser sebelum
koreksi: 2 lulus, 3 gagal (draft/open hilang, cancel mengunci tombol).

Koreksi: salin nama/nilai submitter yang dipilih ke input tersembunyi sebelum
disable; bila event sudah dibatalkan, jangan mengunci form. Guard, CSRF,
konfirmasi, dan validasi server tetap berjalan. Tes browser menangkap request
sebelum mencapai server (tidak memutasi data); sesudah koreksi 5 lulus, 0 gagal.

## A-16 — P1: daftar dampak rekonsiliasi dapat terpotong

K2-08: nama santri pada calon penggabungan dirangkai dengan GROUP_CONCAT.
Pada sesi sandbox `group_concat_max_len=1024`, hanya 11 dari 45 nama fixture
terbaca lengkap; 34 gagal. Konfirmasi dapat diberikan dengan daftar yang tidak
lengkap. Batas diubah hanya dalam sesi tes dan dipulihkan sesudahnya.

Koreksi: ambil relasi sebagai baris biasa, urutkan stabil, lalu bentuk teks
lengkap di PHP. ID anggota kelompok juga dibaca berdasarkan kunci kelompok,
bukan mengandalkan string agregasi yang dapat terpotong. Kontrak tampilan lama
dipertahankan. Tidak mengubah batas agregasi permanen, collation B-2, data lama,
atau keputusan identitas B-3/B-4. `perapihan_audit_wali_long_list.php`: 45 lulus,
0 gagal sesudah koreksi. Fixture terdiri dari 45 relasi sendiri dan dibersihkan
melalui manifest, bukan penghapusan data lama.

## A-14 — P2: kontras dan fokus kerangka bersama belum memadai

Pemeriksaan awal menemukan teks muted/topbar dan tombol kuning Bootstrap
berkontras rendah (contoh 4,39:1; 3,6:1; 1,5:1), tabel bergulir tidak dapat
menerima fokus, serta halaman B tanpa breadcrumb. Laci ponsel juga membiarkan
fokus masuk ke navigasi tersembunyi/latar halaman.

Koreksi pada Layout dan CSS: token kontras lebih kuat, adaptor kartu/tabel/
tombol/modal Bootstrap, tombol kecil 44 px, breadcrumb bawaan, hanya crumb
terakhir bertanda halaman aktif, wilayah tabel dapat digulir dengan keyboard,
fokus masuk/berputar/keluar laci melalui Tab/Shift+Tab/Escape, serta latar inert
selama laci terbuka. Adapter pesan kolom dan konfirmasi digunakan formulir A-15.
Semua tambahan tampilan Bootstrap dibatasi pada body.ah; halaman D tidak
didesain ulang. Makna badge/pesan tetap berupa teks, bukan warna saja.

Bukti akhir gabungan dengan A-15: inventaris 159 observasi A/B/D pada tiga lebar;
120 observasi A/B bebas pelanggaran axe pada aturan WCAG 2 A/AA dan 2.1 AA
yang dapat diautomasi. Ini tidak membuktikan seluruh WCAG atau pembaca layar.
Tes interaksi 60 lulus mencakup keyboard, modal terbuka, daftar wali panjang,
konfirmasi batal, dan prefers-reduced-motion. Pemeriksaan modal menunggu
animasi selesai sebelum membaca kontras/fokus agar hasil bukan warna transisi.

## A-15 — P2: validasi menghilangkan isian dan sebagian label tidak terhubung

Form lama kelas/pengurus/tahun belum menghubungkan label ke kolom. Pengurus,
kelas, tahun, penugasan dan akun menghilangkan isian setelah penolakan. Jadwal
menyimpan old-input tetapi tidak membacanya kembali. Pesan banyak formulir
hanya berupa alert di atas halaman, tidak di dekat kolom yang salah.

Koreksi presentasi: whitelist isian aman per formulir, label for/id, pesan
kolom dari kesalahan server, serta asosiasi aria-invalid/aria-describedby.
Password, token dan persetujuan berbahaya tidak dipulihkan. Filter laporan
dengan rentang terbalik mempertahankan tanggal/mode dan tidak menawarkan
CSV/cetak sampai valid. Pesan konflik lintas data tetap berada pada alert umum.

Portal hanya mendapat pemulihan isian dan tautan koreksi dengan konteks
pencarian/halaman; status HTTP gagal 403/409/422, CSRF, scope, validasi workflow,
idempotensi dan redirect sukses tidak diubah. Pengujian empat jenis aksi
perizinan menggunakan input sengaja invalid dan memastikan 422 sebelum mutasi.
Tidak mengubah layanan/alur perizinan V2 yang di luar paket.

Konfirmasi tindakan status/penugasan pada form A/B kini menjelaskan dampak
pada cakupan, kelayakan, dan pelestarian riwayat. Dialog admin, merge dan timpa
masih memerlukan persetujuan eksplisit server. Cancel tidak mengunci tombol
atau mengirim POST (A-13); tidak ada redirect yang melewati CSRF.

`perapihan_audit_form_feedback.php`: 108 lulus, 0 gagal. Cakupan: santri, wali,
relasi wali, guru, pengurus, kelas/keanggotaan, tahun, kamar, murobi, pembimbing,
jadwal, pertemuan, tiga jenis pembuatan akun, link akun, tiga kegagalan password,
filter laporan, pengajuan izin dan empat aksi detail izin. Kasus positif,
keadaan kosong, dan penolakan hak juga diperiksa ulang melalui regresi paket,
audit HTTP/kamar/pagination/laporan dan browser; tidak mengartikan lint sebagai
bukti keberhasilan transaksi.

### Pengulangan concurrency A-12

Run lanjutan sempat menghasilkan dua kegagalan alasan penolakan pencabutan diri
sendiri: bila orang lain lebih dahulu mencabut role, layanan menjawab "tidak
memiliki role" alih-alih larangan diri sendiri. Minimum admin tetap 1 pada
semua putaran; tidak terjadi nol admin. Pemeriksaan larangan diri dipindahkan
sebelum transaksi, seperti larangan menonaktifkan diri, tanpa melemahkan
assertion. Tiga pengulangan berikutnya masing-masing 13/13 lulus: total 36
putaran, lima proses mutasi (ditambah permintaan diri pada variasi terakhir),
minimum teramati 1. Tes audit atomik 36/36 diulang sesudahnya.
