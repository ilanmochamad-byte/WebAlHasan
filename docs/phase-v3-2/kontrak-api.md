# Kontrak API Fase 2

Semua endpoint berada di bawah `/api/v1`, memakai Bearer token dan envelope lama `{success,data,error}`. Serializer menggunakan allowlist; baris database, fingerprint, idempotency key, actor internal, dan snapshot cakupan tidak dikirim mentah.

| Method dan path | Fungsi | Akses |
| --- | --- | --- |
| `GET /v3/capabilities` | Capability V3 dan `operasional_tersedia` | Semua akun terautentikasi |
| `GET /v3/pelanggaran/options` | Santri dalam cakupan dan katalog aktif | Pembimbing; akun lain mendapat daftar santri kosong |
| `GET /v3/pelanggaran` | Daftar 25/baris per halaman | Admin, pembimbing, murobi terkait |
| `POST /v3/pelanggaran` | Pencatatan | Pembimbing dalam cakupan |
| `GET /v3/pelanggaran/{id}` | Detail, ledger, rekomendasi, revisi | Admin, pembimbing, murobi terkait |
| `PATCH /v3/pelanggaran/{id}` | Koreksi beralasan | Pembimbing dalam cakupan atau admin |
| `POST /v3/pelanggaran/{id}/pembatalan` | Pembatalan + pembalik poin | Pembimbing dalam cakupan atau admin |
| `POST /v3/pelanggaran/{id}/diketahui` | Tanda mengetahui/catatan | Murobi terkait |
| `GET /v3/pelanggaran/{id}/riwayat` | Riwayat revisi | Akses baca yang sama dengan detail |
| `GET /v3/lampiran/{id}` | Unduhan privat | Akses baca pada sumber lampiran |

Mutasi menerima `Idempotency-Key` atau `idempotency_key` pada JSON. Create memerlukan `santri_id`, `tahun_ajaran_id`, `katalog_id`, `waktu_kejadian`, dan `uraian`. Koreksi/pembatalan juga memerlukan `version` dan alasan; hanya admin dapat mengirim penyesuaian `poin`. Lampiran API opsional berbentuk `{nama,data_base64}` dan divalidasi dari isi, bukan klaim MIME klien.

Respons penolakan: autentikasi `401`, cakupan `403`, tidak ditemukan `404`, konflik idempotensi/versi/duplikasi `409`, validasi `422`, dan kegagalan transaksi/audit `503`. URL mutasi dengan GET tidak cocok dengan rute dan menghasilkan `404`; tidak ada perubahan status melalui GET.

Menu aplikasi tidak ditambahkan pada Fase 2. Klien lama tetap membaca `profile.capabilities.default_mode` dan `profile.capabilities.menus` dari `ApiAuthService`; implementasi layar mobile adalah Fase 5.
