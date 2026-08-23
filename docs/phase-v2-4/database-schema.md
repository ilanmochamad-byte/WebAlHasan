# V2 Fase 4 — Skema Basis Data Notifikasi

Fondasi tabel notifikasi dibuat pada **migrasi 006** (Fase 1). **Migrasi 008**
(Fase 4) hanya menambahkan kolom operasional, tabel percobaan pengiriman, tabel
audit kanal, tabel sewa worker, dan indeks. Seluruhnya aditif.

## 1. `notifikasi_outbox`

Satu tabel menyimpan notifikasi in-app **dan** antrean kanal eksternal. Baris
`InApp` tidak pernah menunggu penyedia: ia langsung berstatus `Sent`.

| Kolom | Tipe | Fase | Keterangan |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED PK | 006 | |
| `event_key` | VARCHAR(120) | 006 | kunci peristiwa deterministik |
| `event_type` | VARCHAR(60) | 006 | mis. `izin.keputusan_disetujui` |
| `kanal` | ENUM('InApp','Push','WhatsApp') | 006 | |
| `penerima_user_id` | BIGINT UNSIGNED FK users | 006 | ON DELETE CASCADE |
| `pengajuan_id` | BIGINT UNSIGNED FK izin_pengajuan | 006 | NULL untuk pesan sistem |
| `judul` | VARCHAR(150) | 006 | isi aman |
| `isi` | VARCHAR(500) | 006 | isi aman |
| `data_json` | VARCHAR(1000) | **008** | payload deep link (penunjuk saja) |
| `status` | ENUM('Queued','Sent','Failed') | 006 | |
| `percobaan` | INT UNSIGNED | 006 | |
| `error_terakhir` | VARCHAR(255) | 006 | galat AMAN |
| `error_kode` | VARCHAR(60) | **008** | kode galat AMAN |
| `dibaca_pada` | DATETIME | 006 | status baca in-app |
| `dikirim_pada` | DATETIME | 006 | |
| `percobaan_terakhir_pada` | DATETIME | 006 | |
| `tersedia_pada` | DATETIME | **008** | backoff: waktu paling awal percobaan berikutnya |
| `gagal_permanen` | TINYINT(1) | **008** | 1 = retry dihentikan |
| `locked_by` | VARCHAR(64) | **008** | pemilik klaim worker |
| `locked_until` | DATETIME | **008** | masa berlaku klaim |
| `created_at`, `updated_at` | TIMESTAMP | 006 | |

Indeks:

| Nama | Kolom | Fase | Untuk |
| --- | --- | --- | --- |
| `notifikasi_event_channel_recipient_unique` | (event_key, kanal, penerima_user_id) | 006 | **deduplikasi** |
| `notifikasi_penerima_index` | (penerima_user_id, dibaca_pada, id) | 006 | jumlah belum dibaca |
| `notifikasi_status_index` | (status, kanal, percobaan) | 006 | ringkasan admin |
| `notifikasi_pengajuan_index` | (pengajuan_id) | 006 | jejak per pengajuan |
| `notifikasi_worker_index` | (kanal, status, gagal_permanen, tersedia_pada, id) | **008** | klaim worker |
| `notifikasi_inapp_index` | (penerima_user_id, kanal, id) | **008** | daftar pusat notifikasi |

Kolom yang **tidak ada dan tidak boleh ditambahkan**: nomor telepon penerima,
token perangkat, dan credential penyedia. Nomor diresolusi ulang saat
pengiriman; token diambil dari `perangkat_push`.

## 2. `notifikasi_percobaan` (baru pada 008)

Riwayat setiap percobaan pengiriman.

| Kolom | Tipe | Keterangan |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED PK | |
| `outbox_id` | BIGINT UNSIGNED FK notifikasi_outbox | ON DELETE CASCADE |
| `kanal` | ENUM('InApp','Push','WhatsApp') | |
| `percobaan_ke` | INT UNSIGNED | |
| `hasil` | ENUM('Sent','Failed') | |
| `error_kode` | VARCHAR(60) | AMAN |
| `error_pesan` | VARCHAR(255) | AMAN |
| `durasi_ms` | INT UNSIGNED | lama panggilan penyedia |
| `dicoba_pada` | DATETIME | |

Unik: `(outbox_id, percobaan_ke)` — mencatat percobaan yang sama dua kali tidak
menghasilkan baris ganda.

## 3. `perangkat_push`

| Kolom | Tipe | Fase | Keterangan |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED PK | 006 | |
| `user_id` | BIGINT UNSIGNED FK users | 006 | ON DELETE CASCADE |
| `token_hash` | CHAR(64) | 006 | HMAC-SHA256, **unik** |
| `token_terlindungi` | VARBINARY(512) | 006 | AES-256-GCM, disimpan base64 |
| `platform` | ENUM('android','ios','web') | 006 | |
| `device_id` | VARCHAR(100) | **008** | identitas instalasi dari aplikasi |
| `device_label` | VARCHAR(100) | 006 | mis. "iPhone 15" |
| `app_version` | VARCHAR(30) | **008** | |
| `push_aktif` | TINYINT(1) | **008** | sakelar per perangkat |
| `terakhir_aktif_pada` | DATETIME | 006 | |
| `dicabut_pada` | DATETIME | 006 | |
| `alasan_pencabutan` | VARCHAR(40) | **008** | logout / dinonaktifkan_pengguna / akun_dinonaktifkan / token_invalid / perangkat_dihapus |
| `gagal_berturut` | SMALLINT UNSIGNED | **008** | penghitung kegagalan berurutan |
| `created_at`, `updated_at` | TIMESTAMP | 006 | |

Indeks:

| Nama | Kolom | Fase |
| --- | --- | --- |
| `perangkat_push_token_unique` | (token_hash) | 006 |
| `perangkat_push_user_index` | (user_id, dicabut_pada) | 006 |
| `perangkat_push_user_device_unique` | (user_id, device_id) | **008** |
| `perangkat_push_kirim_index` | (user_id, dicabut_pada, push_aktif) | **008** |

`device_id` boleh NULL dan NULL berulang diizinkan MySQL/MariaDB pada kunci
unik, sehingga klien lama yang belum mengirim `device_id` tetap dapat mendaftar.
Klien Fase 4 menyimpan identitas instalasi acak ini di `expo-secure-store` agar
rotasi token memperbarui perangkat yang sama, bukan membuat baris per sesi.

## 4. `pengaturan_notifikasi`

Baris tunggal (dijaga `UNIQUE(singleton)`).

| Kolom | Tipe | Fase | Keterangan |
| --- | --- | --- | --- |
| `inapp_enabled` | TINYINT(1) | 006 | **CHECK = 1** ditambahkan pada 008 |
| `push_enabled` | TINYINT(1) | 006 | default 0 |
| `push_check_status` | ENUM | **008** | Belum Diperiksa / Lulus / Gagal |
| `push_check_pesan` | VARCHAR(255) | **008** | AMAN |
| `push_check_pada` | DATETIME | **008** | |
| `whatsapp_enabled` | TINYINT(1) | 006 | default 0 |
| `whatsapp_provider` | VARCHAR(50) | 006 | NAMA adapter, bukan credential |
| `whatsapp_check_status` | ENUM | 006 | Belum Diperiksa / Lulus / Gagal |
| `whatsapp_check_pesan` | VARCHAR(255) | 006 | AMAN |
| `whatsapp_check_pada` | DATETIME | 006 | |
| `whatsapp_check_oleh_user_id` | BIGINT UNSIGNED FK users | **008** | ON DELETE SET NULL |
| `updated_by` | BIGINT UNSIGNED FK users | 006 | |

Constraint:

- `pengaturan_notifikasi_whatsapp_check` (006):
  `whatsapp_enabled = 0 OR whatsapp_check_status = 'Lulus'`
- `pengaturan_notifikasi_inapp_check` (**008**): `inapp_enabled = 1`

Tabel ini **tidak memiliki** kolom untuk credential apa pun. Secret berada di
environment server.

## 5. `notifikasi_pengaturan_audit` (baru pada 008)

Audit khusus kanal, terpisah dari `audit_logs` umum agar riwayat kanal dapat
ditelusuri tanpa memfilter seluruh audit sistem. Perubahan sakelar tetap ikut
tercatat pada `audit_logs`, sehingga rollback Fase 4 tidak menghapus jejak
pertanggungjawaban.

| Kolom | Tipe | Keterangan |
| --- | --- | --- |
| `aksi` | ENUM | kanal_diubah / pemeriksaan_konfigurasi / pesan_uji / percobaan_ulang |
| `kanal` | ENUM('InApp','Push','WhatsApp') | NULL untuk aksi lintas kanal |
| `nilai_sebelum`, `nilai_sesudah` | VARCHAR(255) | mis. `aktif` → `nonaktif` |
| `hasil` | VARCHAR(60) | Lulus / Gagal / Tersimpan / Diantrekan |
| `pesan` | VARCHAR(255) | AMAN |
| `aktor_user_id` | BIGINT UNSIGNED FK users | ON DELETE SET NULL |
| `ip_address`, `user_agent` | VARCHAR | tanpa credential |
| `created_at` | TIMESTAMP | |

Struktur tabel ini **sengaja tidak memiliki** kolom untuk credential, token,
atau nomor tujuan.

## 6. `notifikasi_worker_lock` (baru pada 008)

| Kolom | Tipe | Keterangan |
| --- | --- | --- |
| `nama` | VARCHAR(60) PK | `notifikasi:push`, `notifikasi:whatsapp` |
| `pemilik` | VARCHAR(64) | host:pid:acak; kosong = bebas |
| `dikunci_pada` | DATETIME | |
| `kedaluwarsa_pada` | DATETIME | sewa kedaluwarsa otomatis |
| `updated_at` | TIMESTAMP | |

Dua baris disemai migrasi 008 dengan kedaluwarsa di masa lalu (bebas).

## 7. Yang TIDAK diubah Fase 4

`perizinan`, `izin_pengajuan`, `izin_keputusan`, `izin_keputusan_koreksi`,
`izin_riwayat_status`, `izin_idempotency_keys`, `pembimbing_assignments`,
`murobi_assignments`, `users`, `roles`, `user_roles`, `audit_logs`, dan seluruh
tabel V1. Migrasi 008 tidak memuat satu pun `DROP TABLE`, `DROP COLUMN`,
`DELETE`, atau `TRUNCATE` (diverifikasi `tests/v2_phase4_static.php`).
