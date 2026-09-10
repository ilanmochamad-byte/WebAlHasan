# Kontrak API Fase 3

Semua rute berada di bawah `/api/v1`, memakai autentikasi yang sama dengan API yang ada, envelope JSON standar, dan otorisasi berbasis capability/cakupan. Mutasi menerima `Idempotency-Key`; body `idempotency_key` tetap didukung untuk klien web internal.

| Metode | Rute | Fungsi |
| --- | --- | --- |
| `GET` | `/v3/konseling/options` | Santri dalam cakupan serta pelanggaran/rekomendasi berlaku yang dapat dipilih |
| `GET` | `/v3/konseling/kasus` | Daftar berhalaman dan terfilter cakupan |
| `POST` | `/v3/konseling/kasus` | Membuka kasus, tautan pelanggaran, dan rekomendasi pilihan secara atomik |
| `GET` | `/v3/konseling/kasus/{id}` | Detail internal pembimbing/admin atau DTO terbatas murobi |
| `PATCH` | `/v3/konseling/kasus/{id}` | Koreksi kasus beralasan dengan optimistic version |
| `POST` | `/v3/konseling/kasus/{id}/status` | Transisi status, termasuk penutupan/pembatalan beralasan |
| `POST` | `/v3/konseling/kasus/{id}/tautan` | Menambah tautan satu/beberapa pelanggaran bersubjek sama |
| `POST` | `/v3/konseling/kasus/{id}/sesi` | Membuat sesi terjadwal dan tautan sesi opsional |
| `GET` | `/v3/konseling/kasus/{id}/timeline` | Timeline kasus, sesi, dan revisi sesuai hak akses |
| `POST` | `/v3/konseling/kasus/{id}/diketahui` | Tanda mengetahui/catatan murobi pada kasus |
| `PATCH` | `/v3/konseling/sesi/{id}` | Koreksi sesi selesai sebagai revisi baru |
| `POST` | `/v3/konseling/sesi/{id}/status` | Selesai, tidak hadir, jadwal ulang, atau batal |
| `POST` | `/v3/konseling/sesi/{id}/diketahui` | Tanda mengetahui/catatan murobi pada sesi |

Kode utama: `200` sukses/replay, `201` pembuatan baru, `401` tanpa autentikasi, `403` di luar capability/cakupan, `404` sumber tidak ditemukan atau metode/rute tidak tersedia, `409` konflik versi/idempotensi/keunikan, `419` CSRF khusus web, `422` input atau transisi tidak sah, dan `503` bila audit transaksi gagal.

Tidak ada mutasi melalui `GET`. Endpoint Fase 3 bersifat internal; publikasi serta DTO orang tua baru boleh dihubungkan ke rute eksternal pada Fase 4.
