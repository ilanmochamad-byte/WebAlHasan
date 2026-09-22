# Kontrak API publikasi V3 Fase 4

Semua rute di bawah `/api/v1`, memakai token API lama dan envelope `JsonResponse` yang sama. Web memakai halaman `/portal/v3_publikasi_kelola.php` dan `/portal/v3_publikasi.php` dengan CSRF pada setiap POST. API mutasi hanya POST.

| Metode dan rute | Isi dan hasil |
| --- | --- |
| `GET /v3/publikasi/sumber/{kasus\|sesi\|pelanggaran}/{id}` | Metadata versi sumber, wali aktif berakun, daftar publikasi sumber (petugas/admin saja) |
| `POST /v3/publikasi/pratinjau` | Body `sumber_type`, `sumber_id`, `sumber_version`, `ringkasan`, `tindak_lanjut`, `wali_ids[]` opsional, `alasan_penerima` bila subset; untuk koreksi tambahkan `publikasi_id`, `version`, `alasan`. Hasil token 30 menit dan proyeksi konten orang tua. Menolak Rahasia 422. |
| `POST /v3/publikasi/terbit` | Body `pratinjau_token`, `konfirmasi:true`, `idempotency_key`/header `Idempotency-Key`. Hasil `publikasi_ids` dan konten snapshot. Retry 200, baru 201. Koreksi memakai pratinjau yang menunjuk satu publikasi. |
| `GET /v3/publikasi/{id}/kelola` | Snapshot dan riwayat penuh bagi petugas/admin dalam cakupan; admin diaudit. |
| `POST /v3/publikasi/{id}/tarik` | Body `version`, `alasan`, `idempotency_key`; menulis status tarik, versi, riwayat, audit, outbox atomik. |
| `GET /v3/publikasi?page=N` | Daftar orang tua, 25 per halaman; hanya snapshot milik wali aktif. |
| `GET /v3/publikasi/{id}` | `publikasi` dari allowlist `{id,santri_id,ringkasan,tindak_lanjut,diterbitkan_pada,ditarik_pada,dibaca_pada,version,status}` dan `riwayat` aman `{version,tindakan,created_at}`. |
| `POST /v3/publikasi/{id}/dibaca` | Body `version`; status baca idempoten dengan audit, konflik versi 409. |

`401` tanpa token, `403` tanpa hak/IDOR, `404` rute salah, `409` versi atau perubahan relasi/sumber, `422` input/kerahasiaan, `503` kegagalan audit/database. API detail tidak menerima `wali_id` dari klien. Endpoint internal kasus/sesi/pelanggaran V3 tetap tertutup untuk orang tua.
