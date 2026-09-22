# Kanal notifikasi Fase 4

- In-app selalu aktif sesuai fondasi V2; barisnya dibuat dalam transaksi publikasi.
- Push mengikuti `pengaturan_notifikasi.push_enabled`. Tidak ada perubahan sakelar produksi. Saat OFF, service tidak membentuk outbox Push; worker berhenti sebelum request provider. Saat ON pada database uji, worker lama menangani klaim, retry/backoff, tiket dan receipt. Fake provider pada uji bukan bukti pengantaran fisik.
- WhatsApp tetap OFF hingga seluruh V3 selesai. Service Fase 4 tidak membentuk outbox WhatsApp sekalipun konfigurasi uji berubah. Tidak ada request WhatsApp nyata.
- Pesan Push dan InApp: judul `Pembaruan pembinaan`, isi `Ada pembaruan pembinaan. Masuk untuk melihat informasi.`. Data hanya tipe `v3_publikasi` dan ID publikasi. Tidak memuat nama santri, pelanggaran, poin, ringkasan, alasan, nomor telepon, atau token.
- Worker memeriksa relasi wali kembali sebelum menghubungi Expo. Jika hubungan dicabut, baris ditandai gagal permanen `AKSES_BERUBAH` tanpa send. Endpoint detail dan layar mobile melakukan pemeriksaan ulang setelah login.
