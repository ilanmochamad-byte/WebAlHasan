# Deployment cPanel tanpa rsync

19 Oktober 2026 (Asia/Jakarta). Perbaikan terbatas pada alat deployment; tidak mengubah kode aplikasi, `.cpanel.yml`, konfigurasi database atau mobile. Baseline `origin/main` saat pekerjaan: `b317884`. Branch implementasi `codex/fix-cpanel-without-rsync`. Belum merge atau deploy produksi; perlu review/audit sebelum masuk `main` sesuai AGENTS.md.

## Masalah dan perubahan

Terminal akun hosting melaporkan `rsync: command not found`; `command -v rsync` kosong dan `/usr/bin/rsync --version` menghasilkan `No such file or directory`. Ketersediaan rsync di lingkungan akun tidak dapat diasumsikan.

`bin/deploy-cpanel.sh` sekarang memakai Bash, Git, `cmp`, `cp`, `mkdir`, `mktemp`, dan `rm`. Tidak memerlukan instalasi rsync, PHP CLI atau Python pada hosting. Python hanya dipakai untuk uji lokal. Skrip memeriksa seluruh utilitas yang diperlukan sebelum menulis.

- Sumber tetap `/DATA/k1807225/repositories/WebAlHasan`; tujuan `/DATA/k1807225/public_html`. Sumber harus akar repositori pada branch `main` yang bersih; tujuan harus ada dan memiliki `.env`.
- Hanya file terlacak Git yang dipilih: isi `admin`, `api`, `app`, `assets`, `bin`, `database`, `portal`; file PHP akar; `composer.json` dan `composer.lock`. File lokal yang diabaikan Git tidak ikut disalin.
- Tetap mengecualikan komponen `.git`, `.env*`, `error_log`, `*.log`, direktori `file_download` dan `uploads`. Direktori lain seperti `storage`, `berkas_psb`, `gambar`, `gambar_galeri`, `vendor`, folder subdomain, serta `.htaccess` akar/`.user.ini`/`php.ini` tidak termasuk pilihan salin. File tambahan tujuan tidak dihapus.
- File dengan byte identik dilewati. Seluruh file terpilih dan path sumber/tujuan diperiksa sebelum penyalinan pertama. Symlink pada path terpilih, konflik file/direktori dan kegagalan perbandingan menghentikan proses. Path diperiksa lagi sebelum setiap salinan.
- `--dry-run` hanya mencetak `UPDATE` untuk file berbeda; tidak membuat direktori atau menulis file pada tujuan. Manifest sementara di direktori temp privat dipisahkan dengan NUL agar nama berisi spasi/newline tidak pecah.
- Tanpa argumen berarti penyalinan nyata. Argumen lain, termasuk salah ketik `--dryrun`, ditolak agar tidak berubah menjadi deployment nyata.

## Langkah operator setelah review dan merge

1. Backup kode dan pasangan database/storage sesuai runbook rilis. Pastikan perubahan konfigurasi `.htaccess` serta dependensi/migrasi yang diperlukan telah ditinjau terpisah. Deployment ini tidak menjalankan Composer, SQL, migrasi, cron atau pengiriman notifikasi.
2. Pada Git Version Control cPanel, gunakan branch `main` dan klik **Update from Remote**. Pastikan SHA yang ditarik memuat perbaikan ini. Jangan menambal checkout cPanel secara langsung karena guard repositori bersih akan menolak perubahan lokal.
3. Jalankan simulasi di Terminal cPanel:

   ```bash
   cd /DATA/k1807225/repositories/WebAlHasan
   /bin/bash bin/deploy-cpanel.sh --dry-run
   ```

4. Tinjau daftar file, kesiapan rilis aplikasi yang terkandung dalam `main`, dan backup. Saat siap, aktifkan maintenance/jeda penulis dan worker yang relevan, lalu gunakan **Deploy HEAD Commit**. Isi `.cpanel.yml` tetap:

   ```yaml
   ---
   deployment:
     tasks:
       - /bin/bash /DATA/k1807225/repositories/WebAlHasan/bin/deploy-cpanel.sh
   ```

5. Lakukan smoke sesuai versi aplikasi, periksa hasil deployment dan kembali buka layanan hanya setelah hasilnya sesuai.

## Pengujian dan batas

Perintah lokal:

```bash
/bin/bash -n bin/deploy-cpanel.sh
python3 tests/deploy_cpanel_test.py
git diff --check
```

Hasil implementator: **13 tes lulus** pada Bash bawaan macOS, menggunakan repositori Git dan folder hosting sintetis dalam direktori sementara. PATH uji hanya berisi utilitas dasar di atas, tanpa rsync. Bukti meliputi simulasi tanpa perubahan tujuan, penyalinan nyata dan direktori baru, pengulangan nol perubahan, preservasi byte/permission file data dan konfigurasi, situs lain dan file tambahan, nama berisi spasi/newline, file diabaikan Git tidak tersalin, serta penolakan branch lain, sumber kotor/untracked, `.env` hilang, argumen salah, utilitas hilang, `cmp` exit 2, symlink sumber/tujuan dan konflik tipe sebelum penyalinan.

Tidak menjalankan regresi aplikasi/DB karena tidak ada kode aplikasi yang berubah. Tidak menjalankan skrip terhadap path produksi. Hosting/jailed shell sebenarnya dan tombol deployment cPanel belum diuji; ketersediaan utilitas dasar tetap harus dibuktikan lewat simulasi operator. Belum ada audit independen atas perbaikan ini.

Penyalinan berlangsung per file dan **tidak atomik untuk seluruh rilis**. Kegagalan izin/disk, interupsi atau perubahan file bersamaan dapat meninggalkan rilis parsial; gunakan maintenance, satu operator, dan backup. Skrip tidak mengunci seluruh direktori atau memulihkan otomatis. File tujuan yang sudah ada mempertahankan permission melalui `cp` biasa; file/direktori baru memakai umask 022. Tidak menghapus kode lama yang dihapus dari Git: penarikan file harus ditinjau secara khusus, bukan menambahkan penghapusan massal. Pemulihan memakai backup kode yang kompatibel dengan database/storage; tidak mengembalikan implementasi cleanup lama atau menimpa data baru tanpa keputusan terpisah.

Untuk handoff auditor: periksa `bin/deploy-cpanel.sh`, jalankan kembali suite sintetis di atas, dan verifikasi batas penyalinan/data sebelum menyatakan layak merge. Perbaikan alat ini tidak menyatakan seluruh aplikasi siap produksi.
