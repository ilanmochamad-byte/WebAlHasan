# Desain Fase 4 — publikasi orang tua

Baseline `origin/main` pada awal fase: `03c3a05` (Fase 3 telah digabung). Migrasi terakhir sebelum fase ini: 017. Fase ini tidak mengubah role, mode, `default_mode`, akun, penugasan, atau data warisan.

## Batas kepercayaan

- `v3_publikasi_pratinjau` menyimpan hanya teks yang ditulis **khusus orang tua**, tipe/ID/versi sumber, wali pilihan, alasan pengecualian penerima, dan token acak berbentuk hash. Masa berlaku 30 menit. Belum ada baris bisnis maupun outbox ketika hanya pratinjau.
- Konten pratinjau dihasilkan oleh proyeksi `KonselingService::parentSerializer()` yang sama dengan respons orang tua. Formulir pratinjau dan halaman detail memakai partial HTML yang sama. Metadata waktu terbit/status baca ditambahkan setelah konfirmasi.
- `v3_publikasi` dari migrasi 013 adalah snapshot bisnis per wali. `v3_publikasi_riwayat` menyimpan nilai sebelum/sesudah tiap versi. Penarikan tidak menghapus baris; halaman orang tua menampilkan status ditarik, tetapi menyembunyikan teks lama dan alasan penarikan.
- Daftar/detail orang tua hanya mengambil `v3_publikasi`, `users`, `wali`, `santri`, `santri_wali`, dan role. Tidak mengambil kasus, sesi, pelanggaran, audit, atau riwayat berisi teks. Riwayat yang diberikan kepada orang tua hanya versi, tindakan, dan waktu. Nama/ID petugas dan alasan tetap internal.
- Penerima ditentukan oleh `RecipientResolver::waliSantri()`, kemudian pilihan wali yang diberikan petugas divalidasi ulang ketika pratinjau dan konfirmasi. Bila dipilih sebagian dari wali aktif berakun, alasan minimal lima karakter wajib. Satu wali memperoleh satu snapshot per tindakan.
- Pembimbing harus memiliki capability operasional dan cakupan santri saat ini; admin memakai `v3.koreksi`. Koreksi dan penarikan juga memeriksa cakupan. Orang tua memerlukan `v3.publikasi.baca` dan relasi wali aktif pada query untuk setiap daftar/detail. `POST dibaca` memeriksa versi dan relasi ulang.

## Kerahasiaan dan konkurensi

- `Rahasia` ditolak service pada pratinjau dan konfirmasi untuk sumber kasus maupun sesi, termasuk teks yang ditulis manual. Revisi `Rahasia → Internal` memakai alur koreksi kasus Fase 3 beserta alasan, pelaku, waktu, nilai sebelum/sesudah. Koreksi publikasi tetap ditolak jika sumber kembali Rahasia.
- `Internal → Rahasia` ditolak `409` bila ada publikasi aktif sumber kasus, sesi, atau pelanggaran yang ditautkan. Penautan pelanggaran terbit ke kasus Rahasia juga ditolak sampai ditarik beralasan. Pelanggaran mandiri boleh diterbitkan, tetapi jika salah satu revisi dalam keluarga pelanggaran tertaut ke kasus Rahasia, pratinjau dan terbit pelanggaran ditolak.
- Penerbitan, koreksi kasus, dan penarikan memakai kunci `v3_poin_agregat` untuk santri/tahun ajaran dalam transaksi yang sama. Konfirmasi memeriksa versi sumber setelah kunci diperoleh. Dua proses tidak dapat melewati invariant aktif/Rahasia bersamaan.
- Idempotency key dan fingerprint `event_key` mencegah klik ganda. Fingerprint aktif sama mengembalikan ID yang ada tanpa outbox baru. Setelah penarikan, penerbitan ulang konten identik mendapat ID baru berdasarkan ID snapshot terakhir yang ditarik. Koreksi memerlukan optimistic `version`; riwayat unik per `(publikasi_id, version)`.
- Audit, snapshot, riwayat, idempotensi, dan outbox dibuat dalam satu transaksi. Audit hanya menyimpan metadata versi, bukan ringkasan. Kegagalan salah satu tulis me-roll back seluruh dampak.

## Alur baca dan notifikasi

Outbox memakai event generik `v3_publikasi_terbit`, `v3_publikasi_koreksi`, dan `v3_publikasi_tarik`, unik per peristiwa/kanal/user. `v3_publikasi_outbox` menghubungkan ke publikasi dan versi; `pengajuan_id` tetap kosong. Worker V2 menangani klaim, deduplikasi, retry, tiket, dan receipt; khusus publikasi, worker mengecek ulang relasi wali sebelum memanggil push provider. Payload hanya `{tipe:"v3_publikasi",publikasi_id:<id>}`. Judul/isi selalu generik. Push ditambahkan hanya jika sakelar Push ON. WhatsApp tidak pernah dibuat.

Web dan mobile memaksa autentikasi sebelum menampilkan detail. URL/id push hanya penunjuk: detail memanggil API publikasi dan memeriksa relasi wali lagi. Layar mobile membuang isi lama saat focus hilang, akun berubah, atau aplikasi ditinggalkan; tidak menulis ringkasan ke penyimpanan/log/clipboard.
