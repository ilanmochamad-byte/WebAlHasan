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
