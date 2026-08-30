# Keputusan lanjutan audit — 30 Agustus 2026

Pengguna menginstruksikan setelah laporan awal `01d136e`:

1. B-1: audit fungsi notifikasi dahulu, kemudian sesuaikan assertion dengan layar mobile baru.
2. A-06: CSV absensi maksimal 20.000 baris.
3. A-07: menu sesuai hak nyata, dan laporan web guru hanya untuk datanya sendiri.
4. API: pengguna meminta pilihan paling aman bagi aplikasi perangkat. Auditor memilih mempertahankan skema/perilaku baseline pada API dan mengisolasi `subject_scope` ke jalur web; client mobile yang ada tidak mengirim atau membaca field tersebut. Ini memulihkan batas kompatibilitas, tidak menambah fitur mobile.
5. Setelah selesai, sediakan panduan push GitHub dan rilis cPanel. Ini bukan instruksi untuk melakukan push/merge/deploy oleh auditor, dan bukan penghapusan gerbang penerimaan yang belum terpenuhi.

## B-1: audit sebelum penyesuaian assertion

Pembacaan kode menelusuri root route, layar daftar/detail/perangkat, provider,
client API, header lonceng, dan renderer badge. Fungsi daftar/filter/pagination,
loading/empty/error, tandai satu/semua, deep link, serta pemisahan sesi tetap ada.
Lonceng header menggantikan tab; tidak ditemukan fungsi yang hilang akibat
perpindahan path pada cakupan pemeriksaan ini.

`tests/perapihan_audit_notifikasi.php` membuat hanya notifikasi InApp sintetis,
menjalankan **client TypeScript mobile asli** melalui Node dengan transport fetch
lokal dan penyimpanan token in-memory, lalu menghapus fixture miliknya dan
memulihkan status baca yang sudah ada. Tidak mengubah sumber mobile dan tidak
menghubungi penyedia push/WhatsApp. Ini pengujian operasi client/server, bukan
rendering UI React Native atau pengiriman push fisik.

15 pemeriksaan client mencakup login dua akun, pagination, unread, detail,
idempotensi read, mark-all/empty, filter, larangan ID akun lain, pergantian akun,
serta penanganan 401. Ekspektasi baru auditor untuk filter status tak dikenal
awalnya keliru mengharapkan 422; pembacaan kontrak menunjukkan fallback `semua`.
Ekspektasi uji baru disesuaikan dengan kontrak itu, tanpa mengubah backend.

Assertion lama untuk loading/empty/error/mark-all/pagination tetap ada, hanya
path diganti. Assertion badge kini memeriksa rangkaian header → lonceng → angka
unread → renderer badge → rute. Ditambah pemeriksaan route, pemisahan pemilik
sesi, dan operasi API layar. Tidak meniadakan tujuh pemeriksaan yang sebelumnya
gagal. Pengujian UI/perangkat fisik tetap memerlukan verifikasi tersendiri.

## A-06: batas CSV absensi

`ReportService::exportCsvRows` memberlakukan maksimal 20.000 sesudah filter dan
cakupan hak. Repository membaca paling banyak batas+1; ada pemeriksaan ulang
jumlah aktual untuk mencegah lolos ketika data bertambah setelah query summary.
Controller CSV mengembalikan JSON error 422 `EXPORT_TOO_LARGE`, bukan CSV parsial.
Jalur cetak/API lama tidak dikenai batas baru secara diam-diam.

Uji batas nyata: 20.000 diterima lengkap; 20.001 ditolak; `per_page=1` tidak dapat
melewati batas; scope santri kosong tetap diterima meski scope guru berisi 20.001.
Uji awal setelah perubahan sempat gagal karena router khusus API mengembalikan
404 untuk `/portal/` sehingga login uji tidak mendapat CSRF. Router lokal audit
diperbaiki tanpa mengubah expected result; empat pengujian batas kemudian lulus.
