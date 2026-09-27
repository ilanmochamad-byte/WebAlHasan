# Kontrak API Fase 5

Envelope, bearer, kode status, idempotensi dan versi tetap mengikuti kontrak Fase 1–4. Semua jalur `/api/v1/v3/*` mengirim `Cache-Control: private, no-store`. Daftar baru dibatasi 25 baris, ID urut stabil.

| Endpoint GET | Respons/aturan |
| --- | --- |
| /v3/capabilities | Capability server yang sudah ada; menu tidak menggunakan nama role |
| /v3/mobile/options?page=1 | santri, katalog, dapat_mencatat; maksimum 25 pilihan tiap koleksi; halaman kosong menandai akhir; wali 403 |
| /v3/mobile/rekomendasi?page=1 | rows, page, per_page; hanya kelola konseling/pengawasan dan cakupannya |
| /v3/laporan?jenis=pelanggaran | jenis, columns, rows, total, page, per_page, export_limit; proyeksi sesuai capability |

Filter laporan: jenis=pelanggaran/kasus/publikasi, mulai/sampai=YYYY-MM-DD, santri_id, kelas_id, kamar_id, kategori, tingkat, status, poin_min/poin_max, pembimbing_id, murobi_id, tindak_lanjut=ada/belum, page. Wali hanya jenis publikasi dan filter periode/santri/status/tindak_lanjut. Parameter array ditolak 422; angka/tanggal/status divalidasi dan nilai teks diikat prepared statement. Permintaan jenis tak berwenang 403.

Aplikasi menggunakan endpoint bisnis Fase 2–4 yang sama: POST pelanggaran, POST konseling/kasus (rekomendasi_ids opsional), POST kasus/{id}/sesi, POST sesi/{id}/status, POST kasus/{id}/status, POST pelanggaran/kasus/sesi/{id}/diketahui, GET publikasi dan detailnya. Mutasi menyertakan idempotency_key serta version jika kontrak memerlukannya. Server memutuskan transisi. Tidak ada rahasia/nama santri/token pada push payload. Lihat kontrak [Fase 2](../phase-v3-2/kontrak-api.md), [Fase 3](../phase-v3-3/kontrak-api.md), [Fase 4](../phase-v3-4/kontrak-api.md).

401 menghapus sesi lokal dan meminta login; 403 netral; 409 meminta muat ulang versi; 422 perbaiki isian; jaringan/timeout dapat dicoba lagi. Retry mutasi payload sama memakai kunci sama dan tidak otomatis menimpa konflik.
