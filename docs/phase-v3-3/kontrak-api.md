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
| `POST` | `/v3/konseling/kasus/{id}/tautan` | Menambah tautan pelanggaran dan/atau rekomendasi bersubjek sama pada kasus yang masih terbuka |
| `POST` | `/v3/konseling/kasus/{id}/sesi` | Membuat sesi terjadwal dan tautan sesi opsional |
| `GET` | `/v3/konseling/kasus/{id}/timeline` | Timeline kasus, sesi, dan revisi sesuai hak akses |
| `POST` | `/v3/konseling/kasus/{id}/diketahui` | Tanda mengetahui/catatan murobi pada kasus |
| `PATCH` | `/v3/konseling/sesi/{id}` | Koreksi sesi sebagai revisi baru |
| `POST` | `/v3/konseling/sesi/{id}/status` | Selesai, tidak hadir, jadwal ulang, atau batal |
| `POST` | `/v3/konseling/sesi/{id}/diketahui` | Tanda mengetahui/catatan murobi pada sesi |

Kode utama: `200` sukses/replay, `201` pembuatan baru, `401` tanpa autentikasi, `403` di luar capability/cakupan, `404` sumber tidak ditemukan atau metode/rute tidak tersedia, `409` konflik versi/idempotensi/keunikan, `419` CSRF khusus web, `422` input atau transisi tidak sah, dan `503` bila audit atau outbox transaksi gagal.

Tidak ada mutasi melalui `GET`. Endpoint Fase 3 bersifat internal; publikasi serta DTO orang tua baru boleh dihubungkan ke rute eksternal pada Fase 4.

## Tambahan koreksi audit Claude Code

Aditif; klien yang mengabaikan field baru tetap berjalan.

- `POST /v3/konseling/kasus/{id}/tautan` menerima `rekomendasi_ids` di samping `pelanggaran_ids`; sedikitnya salah satu wajib berisi (`422` bila keduanya kosong). Respons menyertakan `rekomendasi_ids` yang berhasil ditautkan. Rekomendasi yang tidak berlaku, bukan berstatus `Baru`, atau sudah ditindaklanjuti ditolak `409`. Revisi pelanggaran yang leluhurnya sudah tertaut pada tingkat kasus yang sama ditolak `409`.
- `POST /v3/konseling/kasus/{id}/status` menyertakan `rekomendasi_dilepas` (daftar ID) pada respons dan audit; berisi rekomendasi yang kembali ke antrean ketika kasus `Dibatalkan`, dan kosong untuk transisi lain.
- `POST /v3/konseling/sesi/{id}/status` menolak `422` ketika kasus induk sudah `Selesai` atau `Dibatalkan`. Field opsional bernilai string kosong tidak menghapus nilai tersimpan; status `Dibatalkan` selalu menyimpan `realisasi=null`.
- `PATCH /v3/konseling/sesi/{id}` menolak `422` bila revisi akan membuat sesi `Selesai` tanpa ringkasan internal/hasil/realisasi atau sesi `Tidak Hadir` tanpa realisasi; untuk sesi terjadwal, `realisasi` selalu `null`.
- Setiap item `tautan` pada detail kasus memperoleh `pelanggaran_digantikan_oleh_id` (ID revisi langsung atau `null`).
- `GET /v3/pelanggaran/{id}` → field `konseling` kini membaca tautan pada seluruh rantai revisi pelanggaran.
