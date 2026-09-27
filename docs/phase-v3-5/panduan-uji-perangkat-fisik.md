# Android dan iOS — MENUNGGU UJI FISIK

Expo web/Chromium dan adapter fake bukan bukti perangkat fisik. Bukti Fase 4 tetap valid untuk rilis Fase 4, tetapi tidak menggantikan pengujian UI Fase 5. Human Developer menjalankan build dari SHA mobile handoff, backend uji khusus dari SHA web handoff, migrasi 019 dan fixture sintetis; jangan mengirim data bisnis produksi ke lingkungan/provider uji. Gunakan konfigurasi SDK 57/provisioning yang sah di luar repository.

Catat perangkat, OS, versi build, SHA, waktu, akun peran sintetis/alias, ID bisnis uji, hasil dan screenshot tersanitasi. Jangan mencatat token, push credential, nomor telepon, nama nyata atau isi konseling nyata.

1. Pembimbing aktif: menu berbasis capability, pilih santri cakupan, catat pelanggaran yang mencapai ambang fixture. Buka rekomendasi → kasus Internal, buat dua sesi berbeda, selesaikan kedua sesi, tutup kasus. Verifikasi ID yang sama di website; retry/ketuk ganda tidak menambah kasus/sesi/rekomendasi. Ulangi Rahasia: hanya pemilik/admin berhak, murobi/pembimbing lain ditolak.
2. Murobi terkait: baca data Internal yang sama, beri tanda mengetahui/catatan dari website dan aplikasi. Murobi lain menebak ID mendapat 403 netral/hasil kosong. Cabut penugasan; muat kembali/deep-link harus ditolak.
3. Wali: hanya publikasi snapshot yang dipilih untuk penerimanya, tanpa poin/petugas/isi internal. Wali lain ditolak; pencabutan relasi langsung menutup akses; penarikan menutup teks lama.
4. Loading, kosong, jaringan putus, retry, timeout, 401, 403 dan 409. Untuk timeout tahan respons >20 detik per percobaan GET; maksimal tiga percobaan otomatis. Versi diubah dari website sementara layar terbuka; mutasi mobile ditolak 409, muat ulang sebelum mencoba. Matikan jaringan setelah submit lalu retry payload sama dan buktikan satu dampak.
5. Login/deep-link cold start/logout/pergantian akun. Sebelum login tidak ada isi privat; setelah login server memeriksa ulang. Background/blur menghapus form/detail; kembali fokus mengambil versi server. Keyboard multiline, safe area, ukuran teks besar, screen reader, orientasi dan tombol bawah diperiksa pada kedua OS.
6. Push nyata satu peristiwa murobi dan satu publikasi wali pada Android serta iOS. Payload generik saja (tipe + ID publikasi/penunjuk), tidak ada nama/isi/token/credential. Deep-link selalu login/otorisasi server. Periksa ticket/receipt sampai status final bila provider mendukung. Simpan hanya status/kode/waktu, jangan token/ticket mentah dalam Git. Fake receipt lokal tidak membuktikan pengiriman ini.

Lampiran privat tetap menggunakan kontrak Fase 2–3; tidak ada upload mobile baru. Uji pengambilan lampiran lintas akun tetap ditolak. Catat setiap kegagalan sebagai BELUM TERPENUHI; tidak merilis berdasarkan simulator saja.
