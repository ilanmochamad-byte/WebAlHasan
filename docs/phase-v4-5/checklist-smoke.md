# Checklist smoke cPanel dan aplikasi — menunggu pelaksanaan

Semua baris **produksi** di bawah berstatus **MENUNGGU PELAKSANAAN**, bukan lulus. Operator mengisi lingkungan/tanggal/SHA/build/bukti sesudah audit independen dan instruksi Human Developer. Data smoke wajib sintetis dengan penanggung jawab serta rencana pembersihan yang mempertahankan jejak audit.

| Langkah produksi | Status |
| --- | --- |
| Catat SHA web/build aplikasi aktual, migrasi/status DB/engine/CHECK, kapasitas storage dan izin maintenance | Menunggu pelaksanaan |
| Backup DB + PDF/aset + konfigurasi privat, hash, restore ke salinan dan verifier | Menunggu pelaksanaan |
| Composer install dari lock, ekstensi PHP CLI/FPM, vendor/private storage tidak bisa diunduh | Menunggu pelaksanaan |
| HTTP 403/404 untuk .env, dump .sql, composer.json/lock, PRD/AGENTS, storage/vendor/docs/bin/app/database/tests; coba URL berkas privat yang diketahui | Menunggu pelaksanaan; Apache lokal lulus |
| .htaccess/FilesMatch benar-benar berlaku pada vhost cPanel; PHP tidak terlayani sebagai source; Authorization diteruskan FastCGI | Menunggu pelaksanaan |
| Migrasi yang belum ada + up ulang, verifier 020/021/022 dan rekonsiliasi tanpa menghapus residu | Menunggu pelaksanaan |
| Guru/pembimbing/murobi/Pendidikan/admin/wali: cakupan aktif, lintas kelas/mapel/semester/relasi, pencabutan, alasan wajib dan riwayat | Menunggu pelaksanaan |
| Nilai/sikap dari aplikasi sama di web; ketukan ganda; 409 setelah konflik/ambiguous retry memuat ulang; tidak menimpa nilai baru | Menunggu pelaksanaan |
| Seluruh gate sebelum terbit, ganti/tarik/buka beralasan; PDF/hash lama utuh dan kode versi mengikuti status | Menunggu pelaksanaan |
| Wali login dari notifikasi/deep-link, relasi diperiksa ulang, ID wali lain ditolak, PDF web/app hash identik | Menunggu pelaksanaan |
| iOS + Android fisik: foreground/background/cold-start, keyboard/safe area, teks besar, offline/timeout/401/403/409, unduh dan cache cleanup/crash | Menunggu pelaksanaan |
| Push nyata dua platform + ticket/receipt akhir; payload generik, relasi/versi dicabut sebelum dispatch | Menunggu pelaksanaan; hanya mock/uri emulator lokal |
| Push/WhatsApp OFF nol request pada staging/lingkungan yang disetujui; WhatsApp produksi tetap OFF | Menunggu pelaksanaan |
| Cron otomatis, env/key, lock/lease, retry/backoff, polling receipt, rotasi log; jangan hanya menjalankan worker manual | Menunggu pelaksanaan |
| Audit/log proxy/WAF/APM/OS/provider tidak merekam credential/nilai/nama santri/gambar/kode; clipboard dan cache akademik tidak otomatis | Menunggu pelaksanaan; inspeksi log aplikasi lokal lulus |
| Beban kelas besar/render PDF/timeout DB dan verifier pada DB target; UAT 95% tugas tanpa bantuan | Menunggu pelaksanaan |
| Rollback kode/build dan restore point aktual direhearsal pada staging; operator menyetujui hasil | Menunggu pelaksanaan |

Tidak ada merge, deployment, migrasi produksi, perubahan kanal atau rilis aplikasi pada sesi implementasi ini. Satu percobaan login sintetis awal browser terkena alamat API lama dari cache konfigurasi; pengujian kemudian diisolasi dan request non-localhost diblokir. Itu bukan smoke produksi atau bukti produksi lulus.
