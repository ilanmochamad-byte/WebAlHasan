# Audit Claude Code — deployment cPanel tanpa rsync

19 Oktober 2026 (Asia/Jakarta). Objek audit: commit `9a8ec51` pada `codex/fix-cpanel-without-rsync` (baseline `origin/main` `b317884`). Ruang lingkup hanya `bin/deploy-cpanel.sh`, `docs/deployment-cpanel/tanpa-rsync.md` dan `tests/deploy_cpanel_test.py`. Tidak ada kode aplikasi, `.cpanel.yml`, database atau mobile yang berubah.

**Hasil: lulus tanpa koreksi kode; layak merge sebagai perbaikan alat deployment.** Ini bukan izin deploy dan tidak menyatakan seluruh aplikasi siap produksi.

## Pengujian independen

| Pemeriksaan | Hasil |
| --- | --- |
| `/bin/bash -n bin/deploy-cpanel.sh` (GNU bash 3.2.57 macOS) | lulus |
| `git diff --check origin/main...HEAD` | bersih |
| `python3 tests/deploy_cpanel_test.py` | 13 tes lulus |
| Paritas terhadap skrip rsync lama (`origin/main:bin/deploy-cpanel.sh`) pada salinan nyata pohon repositori di `9a8ec51` | pohon tujuan identik (`diff -r` kosong), 357 file tersalin |
| Simulasi `--dry-run` pada pohon nyata, PATH tanpa rsync | 357 baris `UPDATE`, tujuan tidak berubah |
| Pengulangan sesudah deploy | `0 file berbeda` |
| File hosting sintetis: `.env` (0600), `admin/error_log`, `storage/private/*`, `portal/uploads/*`, situs lain `alhasan/`, `.htaccess` akar | isi dan mode tetap |
| Sisa manifest/rencana sementara | tidak ada |

Semua uji memakai direktori sementara dan data sintetis; tidak ada path produksi yang dijalankan.

## Batas penyalinan/data yang diverifikasi

- Pilihan salin setara dengan aturan rsync lama: isi `admin`, `api`, `app`, `assets`, `bin`, `database`, `portal`, file PHP akar, `composer.json`/`composer.lock`; komponen `.git`, `.env*`, `error_log`, `*.log`, `file_download`, `uploads` dikecualikan di kedalaman mana pun.
- Satu-satunya file terlacak di area terpilih yang terkena pengecualian adalah `admin/error_log`; tetap tidak disalin sehingga log hosting tidak tertimpa (sama dengan sebelumnya).
- Isi salinan nyata: PHP, SQL migrasi/rollback, skrip `.sh`, CSS/JS, `admin/.htaccess` dan `api/v1/.htaccess`. Tidak ada gambar, PDF, unggahan, dump atau berkas data runtime yang terlacak di area terpilih.
- Perbedaan dari rsync lama justru lebih ketat: hanya file terlacak Git (`git ls-files`) yang dipertimbangkan. File diabaikan Git di area terpilih saat ini hanya `.DS_Store`; tidak ada gitlink atau symlink terlacak.
- Tidak ada penghapusan; file tambahan tujuan dan direktori data di luar pilihan tidak disentuh.

## Temuan nonblokir

1. **Penulisan per file tidak lagi atomik.** rsync lama menulis ke file sementara lalu `rename`; `cp` menimpa isi di tempat, sehingga permintaan PHP yang berjalan dapat membaca file setengah tertulis. Dokumen sudah mewajibkan maintenance; pertahankan syarat itu.
2. **Hard link di tujuan diikuti.** `cp` menulis melalui hard link (diverifikasi dengan probe), sedangkan rsync memutusnya. Hanya relevan jika pihak yang sudah punya akses tulis ke `public_html` membuat hard link; bukan jalur eskalasi baru.
3. **Bash hosting belum diuji.** Uji lokal memakai bash 3.2; konstruksi yang dipakai (`[[ ]]`, `read -d ''`, `local`, `printf %q`) kompatibel dengan bash 4/5, tetapi simulasi `--dry-run` operator di Terminal cPanel tetap menjadi bukti ketersediaan utilitas yang sebenarnya.
4. **Index Git dengan bit `skip-worktree`/`assume-unchanged`** dapat membuat `git status` tampak bersih walau isi berbeda; tidak relevan untuk checkout cPanel standar.

## Belum dilakukan

Tidak menjalankan skrip di hosting, tombol **Deploy HEAD Commit**, migrasi, Composer, atau smoke aplikasi. Tidak ada merge atau deploy yang dilakukan dalam audit ini.
