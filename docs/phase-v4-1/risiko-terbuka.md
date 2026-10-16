# Risiko dan batas bukti Fase 1

- Audit independen Claude Code belum dilakukan. Branch tidak boleh digabung ke `main` sebelum audit dan keputusan Human Developer.
- Pengujian memakai MariaDB 12.3.2 lokal, dump repositori, PHP 8.4, dan Chromium. Hosting cPanel/versi MySQL produksi, Safari, serta perangkat fisik belum diuji. Tidak ada deploy produksi.
- Residu yatim DB uji V3 lama (96 outbox, 24 audit) yang tercatat pada `docs/phase-v3-5/risiko-tertunda.md` tidak dibersihkan. Verifier V4 menghitung **referensi baru V4**, bukan mengklaim semua yatim historis bernilai nol.
- Rencana mengizinkan guru pengampu belum dipilih saat draf. Penugasan guru yang dipilih harus cocok dan aktif; pengisian nilai/finalisasi belum dibuka. Admin/Pendidikan perlu menuntaskan penugasan sebelum Fase 2.
- Gambar tanda tangan disimpan privat, tetapi persetujuan pemilik tanda tangan merupakan proses operator di luar aplikasi sesuai PRD. Rencana yang menunjuk aset yang kemudian dinonaktifkan akan meminta pemilihan ulang sebelum penyimpanan berikutnya.
- `tests/phase5_static.php` lama gagal karena mencari path mobile `src/app/(app)/(reports)/reports.tsx` yang sudah berubah setelah redesain; tidak terkait kode V4 dan tidak diubah. `tests/v3_phase2_static.php` berhenti dengan kode 77 karena guard mengharuskan DB fixture bernama `webalhasan_v3_phase1_test`; bukan hasil lulus. Regresi fondasi penugasan dan V2 statis lulus.
- Nilai, sikap, penerbitan rapor, PDF, QR publik, notifikasi, dan klien mobile merupakan fase lanjut; skema Fase 1 hanya fondasinya. Tidak ada perubahan repositori mobile.
