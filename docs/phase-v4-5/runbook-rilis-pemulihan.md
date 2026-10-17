# Runbook rilis dan pemulihan V4 Fase 5

**Belum dieksekusi pada produksi.** Langkah produksi hanya setelah audit Claude Code, keputusan Human Developer dan instruksi terpisah. Sesi ini tidak merge/deploy/migrasi produksi, memasang cron produksi, mengirim rilis aplikasi atau mengaktifkan kanal.

## Manifest dan prasyarat

Gunakan `manifest-migrasi.json`: hash masing-masing repo, baseline, seluruh SQL 001–022, composer.lock dan package-lock mobile. Fase 5 tidak menambah migrasi; minimum V4 adalah 020/021/022 sesudah fondasi 001–019. Inventaris `php bin/migrate.php status` pada lingkungan tujuan; jangan menganggap produksi identik dengan baseline Git. Catat SHA web yang benar-benar terpasang, build/version mobile, PHP CLI/FPM, DB dan konfigurasi worker sebagai restore point sebelum tindakan.

Lokal teruji PHP 8.4.14/MariaDB 12.3.2; hosting 10.6.24/MySQL lain belum. InnoDB, enforcement CHECK, mysqli/mysqlnd, GD, mbstring, DOM, iconv, JSON dan OpenSSL diperlukan. Composer mengunci Dompdf 3.1.6/PHP-QRCode 5.0.5. Pasang `composer install --no-dev --no-scripts --prefer-dist`, lalu `composer check-platform-reqs --no-dev`; audit lock saat rilis. Vendor tidak masuk Git dan harus ditolak HTTP. Jangan menjalankan composer update pada rilis.

Mobile: `npm ci`, Node sesuai SDK 57 (minimum dokumentasi 22.13.x), Expo 57/React Native 0.86.2 sesuai lock; modul native FileSystem, Sharing, SecureStore, Notifications dan Linking harus ada dalam development/release client yang dibangun dari lock ini. Client lama emulator gagal ExpoPushTokenManager dan teratasi setelah debug build ulang. Bundel JS sukses bukan bukti IPA/APK distribusi sukses. Gunakan API HTTPS yang telah diaudit; verifikasi origin bundle sebelum memasukkan akun. Jangan memakai env/cache produksi untuk pengujian lokal.

## Backup dan restore point

1. Jadwalkan maintenance; hentikan seluruh penulis nilai/sikap/absensi/rencana/rapor dan worker sebelum mengambil pasangan DB/storage. Catat jumlah versi/PDF/outbox dan SHA rilis aktual.
2. Backup konsisten InnoDB dengan `mariadb-dump --single-transaction --routines --triggers DATABASE` (nama/credential dari konfigurasi operator privat). Sertakan `storage/private/v4` seluruh PDF/aset, konfigurasi privat, API token secret dan PUSH_TOKEN_KEY pada penyimpanan terenkripsi terpisah. Permission hanya operator; jangan memasukkan dump/key/PDF privat ke Git atau document root publik.
3. Hitung hash dump/arsip dan simpan manifest privat. Restore ke DB serta direktori storage berbeda; pastikan verifiers membaca direktori restore, bukan sumber. Jalankan verifier Fase 1/3/4, cek referensi/hash PDF dan jumlah/baris bisnis. Uji satu PDF rusak pada salinan: harus 503; pulihkan byte backup yang hash-nya cocok, bukan render ulang.
4. Bukti latihan lokal: `restore-verifier.txt`, `restore-bisnis-dan-korupsi.txt`, `rollback-kosong.txt`, `rollback-berisi-data.txt`. Backup lokal disimpan privat di /private/tmp, tidak menjadi backup produksi atau jaminan retensi.

## Urutan rilis yang harus disetujui

- Ulang seluruh regresi pada staging yang meniru cPanel/DB target dan build aplikasi dari SHA audit. Ambil preflight `php bin/v4_phase1_preflight.php PATH_MANIFEST_PRIVAT` sebelum migrasi yang belum diterapkan. Terapkan migrasi aditif berurutan dan ulang up untuk memeriksa idempotensi; jangan backfill dengan tebakan.
- Verifikasi `php bin/v4_phase1_verify.php PATH_MANIFEST_PRIVAT`, `php bin/v4_phase3_verify.php`, `php bin/v4_phase4_verify.php`, `php bin/v4_phase4_reconcile.php`. Rekonsiliasi hanya baca; jangan menghapus orphan atau residu V1–V3 agar verifier hijau. Investigasi asal di salinan.
- Web/API kompatibel dipasang lebih dulu, baru aplikasi. Uji capability endpoint dan otorisasi sebelum membuka V4 bagi pengguna. Matikan remote/PHP/JS Dompdf, jaga chroot/temp/font cache privat. Jangan log body API, header Authorization, unggahan/verifikasi QR, atau PDF pada proxy/WAF/analytics.
- Jalankan semua langkah `checklist-smoke.md`; setiap hasil mencatat tanggal, lingkungan, SHA/build, pelaku dan bukti aman. Jika blocker, hentikan rollout dan jalankan rollback yang ditinjau operator.

## Worker/cron

Pertahankan konfigurasi kanal yang telah disetujui: WhatsApp OFF; jangan mengubah Push produksi tanpa instruksi. Producer InApp transaksional; worker memeriksa lagi relasi/versi. Rekomendasi CLI yang sudah ada (sesuaikan path PHP dan akun cPanel, log di luar document root):

```cron
*/5 * * * * /usr/local/bin/php /home/AKUN/public_html/bin/notifikasi_worker.php --kanal=push >> /home/AKUN/logs/notifikasi_push.log 2>&1
*/15 * * * * /usr/local/bin/php /home/AKUN/public_html/bin/notifikasi_worker.php --receipts >> /home/AKUN/logs/notifikasi_receipt.log 2>&1
0 * * * * /usr/local/bin/php /home/AKUN/public_html/bin/v2_phase5_cron_check.php
```

Ini contoh konfigurasi, belum dipasang. Periksa user/working directory, env/key identik dengan PHP web, timestamp cron, lock/lease, retry/backoff, dedup, umur pending, ticket/receipt final dan rotasi log. `--status`/`--uji-coba` tidak mengirim provider. Bukti lokal worker OFF ada di `worker-status.txt`, `worker-dry-run.txt`; Push/WhatsApp OFF nol request dibuktikan mock di `regresi-fase4-push.txt`. Tidak memasang cron purge atau menghapus outbox. Exactly-once provider saat jawaban jaringan hilang tidak dijamin.

## Rollback aman

Fase 5 tidak memiliki DDL baru: rollback utama adalah kode web/mobile ke restore point aktual yang kompatibel dengan skema aditif, dengan DB/snapshot/PDF tetap dipertahankan. Aplikasi baru pada API Fase 4 tanpa capability endpoint akan menampilkan gagal memuat menu V4; jangan mengklaim rollback itu mempertahankan seluruh fitur. Siapkan build mobile sebelumnya; hentikan rollout, jangan menghapus data aplikasi pengguna secara otomatis.

Rollback 022 **hanya** jika tabel bisnis Fase 4 kosong dan seluruh penulis berhenti: `v4_phase4_rollback_preflight.php` lalu `bin/migrate.php rollback` ketika migrasi terakhir benar-benar 022. Guard SQL juga menolak data. Jangan DROP/TRUNCATE paksa pada rapor, riwayat, snapshot, kode atau outbox. Guard SQL gagal dapat meninggalkan tabel guard kosong; periksa sebelum penanganan manual. Jika sudah ada data/versi, lakukan forward fix atau restore konsisten dari backup yang diverifikasi, setelah menilai perubahan setelah restore point. Restore tidak boleh diam-diam mengganti PDF versi terbit yang sudah diterima wali.

Jika hasil COMMIT/koneksi tidak pasti, pertahankan file sampai referensinya dapat dibuktikan pada DB benar. Missing/corrupt PDF harus 503. Pulihkan byte kanonis tepercaya atau lakukan Tarik/Ganti formal melalui server; jangan mengubah hash/snapshot versi lama. Durability disk/power dan putus jaringan nyata saat COMMIT belum dibuktikan oleh latihan lokal.
