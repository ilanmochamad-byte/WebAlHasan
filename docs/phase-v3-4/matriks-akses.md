# Matriks akses Fase 4

| Aktor | Pratinjau/terbit | Koreksi/tarik | Baca snapshot wali | Kasus Rahasia |
| --- | --- | --- | --- | --- |
| Pembimbing pemilik kasus, cakupan aktif | Ya, sumber dalam cakupan | Ya, sumber dalam cakupan | Tidak melalui portal wali | Pratinjau/terbit/koreksi publikasi ditolak 422; kelola/tarik publikasi lama tetap boleh untuk pemulihan |
| Pembimbing lain dalam cakupan, bukan pemilik kasus | Ya untuk sumber Internal | Ya untuk sumber Internal | Tidak melalui portal wali | **403 pada seluruh jalur** (opsi, pratinjau, terbit, kelola, tarik) sehingga keberadaan kasus Rahasia tidak terungkap — koreksi audit A1 |
| Pembimbing di luar cakupan | 403 | 403 | 403 | 403 |
| Admin | Ya | Ya, alasan wajib | Melalui halaman kelola, akses admin diaudit | Pratinjau/terbit/koreksi publikasi ditolak 422 |
| Murobi | 403 | 403 | 403 | 403 |
| Orang tua dengan relasi `santri_wali` aktif dan wali penerima cocok | 403 | 403 | Ya, snapshot miliknya saja | Tidak ada akses sumber internal |
| Wali lain, relasi dicabut, akun nonaktif, belum login | 403/401 | 403/401 | 403/401 | 403/401 |

Pemeriksaan relasi berjalan ulang pada setiap request. Bila dua wali terhubung ke santri yang sama, ID publikasi tetap milik satu wali penerima. IDOR terhadap wali lain ditolak. Admin tidak mendapatkan isi kasus dari API orang tua; akses internalnya tetap melalui jalur pengawasan Fase 3. Sesudah koreksi audit A1, kerahasiaan `Rahasia` mengikuti aturan kepemilikan PRD V3 5.5a pada seluruh jalur publikasi: hanya pembimbing pemilik dan admin; pembimbing lain menerima 403 yang tidak dapat dibedakan dari sumber yang tidak ada. Penolakan untuk pelanggaran yang tertaut kasus Rahasia memakai pesan netral (A2).
