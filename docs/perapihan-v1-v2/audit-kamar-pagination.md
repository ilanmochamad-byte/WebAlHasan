# Penyelesaian kamar dan pagination sebelum push

Keputusan pengguna 30 Agustus 2026 setelah commit `33ceb43`: selesaikan halaman
kamar dan pagination sebelum paket dikirim ke GitHub. Tidak ada izin untuk
push, merge, deploy, mengubah mobile, atau menjalankan migrasi produksi.

## A-11 — P1, pra-ada: mutasi kamar tidak aman

Pembacaan halaman kamar sebelum modernisasi menemukan `?hapus=<id>` menjalankan
DELETE melalui GET, tanpa CSRF; ID edit diinterpolasi langsung ke SQL. Ini kode
warisan yang sudah ada sebelum paket, bukan regresi Claude. Ditemukan ketika
mengerjakan kamar yang sekarang diminta pengguna. Tidak menguji penghapusan
terhadap data lama. Sesuai larangan penghapusan data, jalur GET hapus ditutup
dengan 405; tidak menambahkan jalur penghapusan pengganti.

Tambah/ubah kini melalui prepared statement, validasi ID/nama/kapasitas, guard
admin dan CSRF, transaksi dan audit wajib. Jika audit gagal, perubahan
dibatalkan. ID/relasi/riwayat kamar tetap dipertahankan; tidak ada migrasi baru.

`tests/perapihan_audit_kamar.php`: 15 pemeriksaan awal lulus pada sandbox
`webalhasan_ui_codex_20260830_test`. Mencakup empat login, tiga penolakan peran,
CSRF, input invalid, tambah/edit sah, GET hapus 405, audit, dan jumlah data.
Percobaan pertama menghasilkan satu kegagalan tes baru karena membandingkan
string numerik hasil mysqli dengan integer secara strict; diperbaiki dengan
cast saat membaca hasil, tanpa mengubah ekspektasi 422/tidak ada mutasi.
