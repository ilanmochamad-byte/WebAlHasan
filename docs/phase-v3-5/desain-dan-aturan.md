# PRD V3 Fase 5 — desain dan aturan

Implementator: Codex. Auditor independen: Claude Code, **MENUNGGU AUDIT**. Hanya Fase 5; tidak ada pekerjaan V4–V6, merge, deploy, migrasi produksi, pembersihan produksi, atau perubahan sakelar kanal.

Baseline setelah fetch: web `e79919ac0f47df370e5283ffa409aaaf80129d52`, mobile `bd8b7f0368799239341b69e5b476548da9bae65f`. Kedua folder semula bersih, branch dibuat langsung dari origin/main dengan nama `prd-v3-fase-5`; main lokal tidak dipakai. Keputusan produk: Push produksi ON, WhatsApp OFF. Data uji hanya sintetis di `webalhasan_v3_phase1_test`.

## Aplikasi

Menu pembinaan berasal dari `/v3/capabilities`, tetap satu login. Server memeriksa ulang identitas, capability, cakupan aktif dan versi pada setiap operasi. Daftar/options/rekomendasi baru dibatasi 25 baris; pilihan santri/katalog dapat berpindah halaman. Kasus Internal dan Rahasia mengikuti aturan Fase 3–4; murobi tidak membaca Rahasia. Pembimbing membuat pelanggaran/kasus, menautkan rekomendasi, menjadwalkan sedikitnya dua sesi, mengisi ringkasan/hasil, mengubah status sesuai transisi server, dan menutup kasus. Murobi memberi tanda mengetahui/catatan. Wali hanya membaca snapshot publikasi miliknya.

Isi layar/form hanya berada dalam memori. Blur/background menghapus isi dan membatalkan penggunaan respons lama; kembali fokus memuat API lagi. Pergantian akun mereset komponen. Tidak ada cache isi konseling, analytics, clipboard otomatis, notifikasi lokal, atau payload push tambahan. Kolom privat menonaktifkan autofill/autocorrect. Kunci idempotensi sama dipakai untuk retry payload sama, klik ganda diabaikan selama request berlangsung. Loading/kosong/gagal/retry/timeout/401/403/409 memakai pesan yang dapat ditindaklanjuti. Deep-link berada di Stack autentikasi dan selalu membaca ulang server.

Koreksi baseline yang diizinkan pengguna: options pelanggaran kini menolak wali sebelum membaca katalog internal. Teks 403 mobile netral dan tidak menyebut keberadaan kasus/santri. Fokus input web tidak memanggil API fokus native yang tidak tersedia pada React Native Web; uji UI login/form menjadi regresinya.

## Laporan dan audit

Satu service query/proyeksi dipakai HTML, cetak/PDF, CSV dan daftar API laporan. Laporan internal berisi metadata; tidak mengekspor tujuan, isi konseling, catatan murobi, saksi, atau lampiran. Laporan keluarga berisi hanya snapshot yang telah dipilih untuk wali itu. Snapshot ditarik menampilkan status penarikan tanpa teks lama. Filter tambahan tidak pernah memperluas cakupan.

Audit tindakan V3 baru menyimpan hash SHA-256 untuk kolom teks privat; identitas pelaku/subjek, waktu, versi, status dan jejak perubahan tetap ada. Nilai bisnis sebelum/sesudah tetap berada di riwayat privat. Audit lama tidak ditulis ulang atau dihapus. Kegagalan audit wajib tetap menggulung transaksi. AuditLogger tidak mencetak exception SQL mentah.

## Rekonsiliasi dan retensi

Verifier Fase 1–5 tetap mendeteksi selisih ledger/agregat, referensi yatim, status, relasi rekomendasi/kasus/sesi, publikasi duplikat/riwayat/outbox dan larangan Rahasia. Verifier Fase 5 menambah indeks dan indikator operasional tertunda >30 menit, publikasi tanpa wali aktif, serta pratinjau melewati retensi. Indikator operasional bukan alasan menghapus publikasi: relasi wali yang dicabut sah dan akses langsung tertutup.

Pratinjau kedaluwarsa lebih dari tujuh hari dapat dipurge oleh operator admin aktif. Default dry-run, maksimum 500 per batch, urut expires_at/id, lock/transaksi dan audit wajib. Snapshot terbit, riwayat serta audit tidak dihapus. Baca [migrasi](migrasi-verifier-rollback.md) dan [risiko](risiko-tertunda.md).
