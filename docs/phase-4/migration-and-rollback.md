# Migrasi dan rollback Fase 4

Migrasi `004_phase4_api_attendance.sql` bersifat aditif. Migrasi menambah tabel absensi guru, absensi santri, dan kunci idempotensi API tanpa mengubah tabel atau kolom lama.

Sebelum produksi:

1. Buat backup penuh dan manifest jumlah baris dengan prosedur Fase 1.
2. Terapkan migrasi 001–004 pada salinan database dengan nama berakhiran `_test`.
3. Jalankan pengujian statis dan integrasi Fase 1–4.
4. Verifikasi query duplikasi pada `absensi_guru` dan `absensi_santri` menghasilkan nol baris.
5. Terapkan ke produksi pada jendela pemeliharaan. Jangan menjalankan rollback tanpa backup dan persetujuan pengguna.

Rollback `004_phase4_api_attendance.sql` hanya disediakan untuk staging atau pemulihan terencana. Rollback menghapus seluruh data absensi Fase 4 sehingga tidak aman dijalankan pada produksi yang telah menerima absensi.
