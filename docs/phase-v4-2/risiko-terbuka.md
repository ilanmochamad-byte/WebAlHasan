# Risiko dan batas pengujian Fase 2

- Audit Claude Code selesai (lihat `audit-claude.md`); tidak ada klaim siap produksi. Merge/deploy menunggu keputusan Human Developer.
- Setelah audit, simpan yang tidak mengubah komponen apa pun ditolak 422, dan komponen yang tidak berubah tidak lagi mendapat versi/riwayat/audit baru.
- Uji berjalan pada MariaDB 12.3.2, PHP 8.4.14, Chromium lokal. MySQL/hosting cPanel, Safari, perangkat fisik, dan aplikasi mobile tidak diuji. Mobile memang di luar fase ini.
- `tests/phase5_static.php` masih gagal pada path laporan mobile lama setelah redesain. Kegagalan yang sama ada pada baseline audit Fase 1. Seluruh integrasi V1 yang dijalankan lulus; file uji lama tidak diubah agar terlihat lulus.
- Regresi V2 Fase 2 awal gagal karena proses anak PHP tidak mewarisi opsi socket CLI. Ulang dengan `PHP_INI_SCAN_DIR` khusus uji lulus. Regresi V3 Fase 2 awal gagal karena fixture resmi hanya membuat semester aktif; tambah semester nonaktif sintetis, lalu ulang lulus. Log awal dan ulang tetap disimpan.
- Pengujian pengisian Fase 2 memakai salinan dump repositori, fixture akun `v4_*`, serta alias santri sintetis untuk screenshot. Regresi V1–V3 memakai database lain dengan skema kosong dan fixture resmi sintetis. Tidak ada akses atau perubahan data produksi.
- Regresi lama dapat menyisakan audit/outbox yatim ketika fixture dibersihkan oleh suite lama. Residu tersebut tidak dihapus atau verifier dilonggarkan. Verifier V4 dijalankan sebelum fixture bisnis ditambahkan dan tidak mengklaim kebersihan semua data historis.
- Penguncian parent rencana menserialisasi penulis dalam satu kelas/semester, termasuk santri/mapel berbeda. Enam proses diuji; performa beban produksi dan konkurensi perubahan penugasan/master secara bersamaan belum diuji.
- Retry identik yang sudah tersimpan mengembalikan 409, bukan replay 200; klien wajib memuat ulang versi. Isian yang gagal ditampilkan ulang sebagai data tersimpan terbaru dengan pesan galat, bukan menyimpan otomatis input lokal.
- Harian/praktik dibatasi 100 komponen per santri/mapel; catatan per komponen maksimal 2000 karakter, alasan 1000. Penghapusan komponen yang telah disimpan belum didukung; koreksi mempertahankan jejak lama.
- Riwayat seluruh revisi satu santri/mapel dimuat sekaligus; belum ada uji volume riwayat besar. Guru/Pendidikan hanya mendapatnya dalam cakupan server yang aktif.
- Penanda tangan/tempat tetap terkunci oleh perilaku Fase 1 setelah nilai ada. Optimistic version rencana, perubahan penanda tangan pascapenilaian, ambiguitas pindah kelas saat finalisasi, sikap/kehadiran, PDF/QR, publikasi/notifikasi, serta layar mobile tidak diperluas dalam Fase 2.
