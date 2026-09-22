# Matriks akses Fase 4

| Aktor | Pratinjau/terbit | Koreksi/tarik | Baca snapshot wali | Kasus Rahasia |
| --- | --- | --- | --- | --- |
| Pembimbing dengan cakupan aktif | Ya, sumber dalam cakupan | Ya, sumber dalam cakupan | Tidak melalui portal wali | Pratinjau/terbit/koreksi publikasi ditolak 422 |
| Pembimbing di luar cakupan | 403 | 403 | 403 | 403 |
| Admin | Ya | Ya, alasan wajib | Melalui halaman kelola, akses admin diaudit | Pratinjau/terbit/koreksi publikasi ditolak 422 |
| Murobi | 403 | 403 | 403 | 403 |
| Orang tua dengan relasi `santri_wali` aktif dan wali penerima cocok | 403 | 403 | Ya, snapshot miliknya saja | Tidak ada akses sumber internal |
| Wali lain, relasi dicabut, akun nonaktif, belum login | 403/401 | 403/401 | 403/401 | 403/401 |

Pemeriksaan relasi berjalan ulang pada setiap request. Bila dua wali terhubung ke santri yang sama, ID publikasi tetap milik satu wali penerima. IDOR terhadap wali lain ditolak. Admin tidak mendapatkan isi kasus dari API orang tua; akses internalnya tetap melalui jalur pengawasan Fase 3.
