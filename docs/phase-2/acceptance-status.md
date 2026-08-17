# Status penerimaan Fase 2

## Verifikasi fondasi Fase 1

Fondasi yang diperlukan Fase 2 tersedia tanpa dibangun ulang: bootstrap dan konfigurasi environment, koneksi database, migrator berversi, session aman, guard role admin, CSRF, audit log, serta akun berbasis `users`. Pengujian regresi `tests/phase1_static.php` tetap digunakan untuk menjaganya.

## Hasil per kriteria

| Kriteria penerimaan | Implementasi/pengujian | Status workspace |
|---|---|---|
| CRUD, detail, pencarian, filter, nonaktif, dan arsip guru/santri dari UI | UI server-side + skenario `phase2_integration.php` | Menunggu MySQL test |
| NIS duplikat ditolak dan hanya satu baris | Unique constraint + skenario integrasi | Menunggu MySQL test |
| NIP non-kosong duplikat ditolak dan hanya satu baris | Unique constraint + skenario integrasi | Menunggu MySQL test |
| Nonaktif tidak menghapus riwayat/relasi | State update tanpa `DELETE` + skenario integrasi | Menunggu MySQL test |
| Satu wali terhubung ke dua santri dan terbaca kembali | `santri_wali` + skenario integrasi | Menunggu MySQL test |
| Tepat satu semester aktif | Transaksi service + generated unique guard + query integrasi | Menunggu MySQL test |
| Penempatan kelas aktif menyimpan riwayat | Penutupan status lama + insert keanggotaan baru + skenario integrasi | Menunggu MySQL test |
| Penugasan murobi tidak memberi approval izin | Tabel/UI penugasan tanpa akun atau perubahan modul izin + skenario integrasi | Menunggu MySQL test |
| Jumlah baris CSV sama dengan filter UI | Repository filter bersama + skenario integrasi | Menunggu MySQL test |
| Audit menyimpan pelaku/waktu tanpa rahasia | `AuditLogger` pada seluruh mutasi + skenario integrasi | Menunggu MySQL test |
| Semua PHP baru/diubah lolos `php -l` | Lint seluruh `app`, `admin`, `bin`, dan `tests` | Terverifikasi |

`tests/phase2_static.php` memverifikasi struktur migrasi, tidak adanya penghapusan master permanen di UI, prepared statement, audit, transaksi, layar, ekspor/impor, serta normalisasi. `tests/phase2_integration.php` menguji seluruh kriteria dinamis dan menolak berjalan kecuali `PHASE2_RUN_INTEGRATION=1` serta nama database berakhiran `_test`, agar tidak mungkin mengubah produksi secara tidak sengaja.

Kriteria dinamis di `PRD.md` belum dicentang karena workspace ini tidak memiliki `.env` atau service MySQL yang dapat dipakai. Jalankan migrasi pada salinan database dan pengujian integrasi sebelum mencentangnya.

