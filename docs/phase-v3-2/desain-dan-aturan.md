# Desain dan aturan operasional Fase 2

## Otorisasi dan T-2

`Capabilities::v3Capabilities()` mempertahankan provenance dari resolver fondasi: `penugasan`, `admin`, atau `keduanya`. Admin murni hanya memperoleh pengawasan/koreksi. Pencatatan operasional tetap memerlukan penugasan pembimbing nyata. Akun admin yang juga menjadi pembimbing dikenali sebagai `keduanya`; koreksinya masuk jalur admin beralasan dan auditnya berbeda dari koreksi pembimbing.

Cakupan baca dan tulis dihitung ulang dari akun, role dasar, master aktif, penugasan aktif, tahun ajaran aktif, serta penempatan kelas/kamar aktual. Query pilihan santri dan query daftar/detail memiliki penjaga cakupan sendiri. Santri tanpa sedikitnya satu penempatan aktual tetap tidak masuk pilihan dan ditolak `403`; perilaku fondasi ini tidak dilonggarkan.

## Transaksi, kunci, dan kebenaran poin

Setiap mutasi mengunci satu baris `v3_poin_agregat` berdasarkan pasangan santri/tahun ajaran dengan `SELECT ... FOR UPDATE`. Subjek berbeda tidak berbagi kunci global. `schema_migrations` tidak dipakai oleh mutasi operasional.

Pencatatan menyimpan snapshot kategori, tingkat, dan poin bersama pelanggaran, ledger, agregat, rekomendasi, outbox, idempotensi, lampiran metadata, dan audit dalam transaksi yang sama. Total yang ditampilkan dihitung ulang dari `v3_poin_ledger`; `v3_poin_agregat` adalah cache terkunci yang diverifikasi terhadap ledger, bukan sumber kebenaran.

Koreksi membuat baris revisi baru, satu pembalik ledger untuk revisi lama, dan satu delta baru. Pembatalan mengubah status dengan optimistic version dan menambah pembalik yang menunjuk `pembalik_dari_id`. Tidak ada hard delete atau penimpaan uraian/poin historis. Perubahan poin default katalog tidak mengubah snapshot lama.

## Idempotensi, duplikasi, dan rekomendasi

Setiap create, koreksi, pembatalan, dan tanda mengetahui memerlukan idempotency key. `v3_idempotency` menyimpan hash request serta respons untuk replay. Fingerprint bisnis mencegah pencatatan identik dengan key berbeda. Kolom `version` menolak tab/request lama dengan `409`, dan indeks unik memastikan satu pengganti langsung untuk satu revisi.

Ambang aktif pada tahun ajaran aktif menghasilkan paling banyak satu `v3_rekomendasi` per santri/tahun/ambang. Rekomendasi hanya antrean tindak lanjut manual; tidak menulis hukuman, kasus konseling, sesi, atau penempatan akademik. Ambang tahun nonaktif tetap boleh disimpan, tetapi tidak pernah menjadi default dan ditampilkan sebagai peringatan operator karena belum dapat memicu rekomendasi.

## Privasi dan lampiran

Outbox in-app/push hanya berisi jenis event serta teks generik. Nama santri, kategori, uraian, saksi, lokasi, poin, nomor telepon, dan catatan murobi tidak masuk judul, isi, atau JSON payload.

Lampiran dibatasi JPG/PNG/PDF, maksimum 5 MiB, dideteksi dengan `finfo`, diberi nama acak, hash SHA-256, mode privat, dan dilayani hanya setelah otorisasi ulang. File staging maupun file final dibersihkan jika validasi, audit, atau transaksi gagal. Direktori privat menolak akses web langsung.

## Kontrak lama

Menu web V3 berasal dari `App\Ui\Navigation`. Menu aplikasi tetap berasal dari `ApiAuthService::menus()`. Struktur `capabilities`, `default_mode`, endpoint login/profil, jadwal, absensi, laporan, dan perizinan V1/V2 tidak diubah. V3 tetap aditif di `feature_capabilities` dan endpoint `/v3/*`.
