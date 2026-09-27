# Kanal notifikasi Fase 4

- In-app selalu aktif sesuai fondasi V2; barisnya dibuat dalam transaksi publikasi.
- Push mengikuti `pengaturan_notifikasi.push_enabled`. Tidak ada perubahan sakelar produksi. Saat OFF, service tidak membentuk outbox Push; worker berhenti sebelum request provider. Saat ON pada database uji, worker lama menangani klaim, retry/backoff, tiket dan receipt. Fake provider pada uji bukan bukti pengantaran fisik.
- WhatsApp tetap OFF hingga seluruh V3 selesai. Service Fase 4 tidak membentuk outbox WhatsApp sekalipun konfigurasi uji berubah. Tidak ada request WhatsApp nyata.
- Pesan Push dan InApp: judul `Pembaruan pembinaan`, isi `Ada pembaruan pembinaan. Masuk untuk melihat informasi.`. Data hanya tipe `v3_publikasi` dan ID publikasi. Tidak memuat nama santri, pelanggaran, poin, ringkasan, alasan, nomor telepon, atau token.
- Worker memeriksa relasi wali kembali sebelum menghubungi Expo. Jika hubungan dicabut, baris ditandai gagal permanen `AKSES_BERUBAH` tanpa send. Endpoint detail dan layar mobile melakukan pemeriksaan ulang setelah login.

## Keputusan Human Developer 27 September 2026

Sakelar **Push produksi tetap ON** sesudah uji fisik Fase 4 lulus. Konsekuensinya disadari dan
dikehendaki: setiap penerbitan, koreksi, dan penarikan publikasi akan mengirim push nyata kepada
wali penerima yang perangkatnya terdaftar, dengan judul dan isi generik yang sama seperti pada uji.
Cron `notifikasi_worker.php --kanal=push` yang sudah terpasang sejak V2 Fase 4 tetap berjalan.

**WhatsApp tetap OFF** sampai seluruh V3 selesai; keputusan ini tidak mengubahnya.
