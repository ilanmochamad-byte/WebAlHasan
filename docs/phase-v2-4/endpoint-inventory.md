# V2 Fase 4 — Inventaris Endpoint Notifikasi

Seluruh endpoint bersifat **aditif** di bawah `/api/v1`. Tidak ada kontrak V1
maupun Fase 3 yang berubah.

Konvensi (sama seperti V1 dan Fase 3):

- Autentikasi bearer: `Authorization: Bearer <token>`.
- Envelope: `{ "success": bool, "data": …, "error": { code, message, details } }`.
- Status: `200` sukses, `201` sumber daya baru, `401` tanpa sesi, `403` di luar
  cakupan, `409` konflik keadaan, `422` validasi, `503` konfigurasi server
  belum siap.
- **Tidak ada** parameter pemilik pada endpoint notifikasi. Penerima selalu
  diambil dari token; mengganti ID hanya menghasilkan `403`.

## 1. Pusat notifikasi pengguna

### `GET /api/v1/notifikasi`

Query: `status` (`semua` | `belum_dibaca` | `sudah_dibaca`), `page`,
`per_page` (maks. 100, default 20).

```json
{
  "items": [
    {
      "id": 41,
      "event_type": "izin.keputusan_disetujui",
      "event_label": "Izin disetujui",
      "judul": "Izin disetujui",
      "isi": "Izin Ahmad Fauzi (#128) telah disetujui untuk 2026-08-25 s.d. 2026-08-26. Buka detail untuk melihat alasan keputusan.",
      "pengajuan_id": 128,
      "pengajuan_status": "Disetujui",
      "santri_nama": "Ahmad Fauzi",
      "dibaca": false,
      "dibaca_pada": null,
      "dibuat_pada": "2026-08-23 09:12:44",
      "tautan": { "tipe": "izin", "pengajuan_id": 128 }
    }
  ],
  "jumlah_belum_dibaca": 3,
  "filters": { "status": "semua" },
  "pagination": { "current_page": 1, "per_page": 20, "total": 12, "total_pages": 1 }
}
```

### `GET /api/v1/notifikasi/belum-dibaca`

`{ "jumlah": 3 }` — dipakai lencana tab aplikasi dan navigasi web.

### `GET /api/v1/notifikasi/{id}`

`{ "notifikasi": { … } }`. `403` bila bukan milik pengguna **atau** tidak ada;
keduanya dijawab sama agar keberadaan baris tidak bocor.

### `POST /api/v1/notifikasi/{id}/dibaca`

`{ "notifikasi": { … }, "jumlah_belum_dibaca": 2 }`. Idempoten: memanggil dua
kali tidak mengubah jumlah lagi.

### `POST /api/v1/notifikasi/dibaca-semua`

`{ "ditandai": 5, "jumlah_belum_dibaca": 0 }`. Hanya menyentuh notifikasi
pengguna yang sedang masuk.

## 2. Perangkat push

### `POST /api/v1/notifikasi/perangkat` → `201`

```json
{ "token": "ExponentPushToken[...]", "platform": "android",
  "device_id": "…", "device_label": "Pixel 8", "app_version": "1.0.0" }
```

Respons: `{ "perangkat_id": 7, "baru": true, "platform": "android", "push_aktif": true, "pesan": "…" }`

- `422` bila token bukan Expo push token atau platform tidak dikenal.
- `503` bila `PUSH_TOKEN_KEY` belum diisi di server.
- Token **tidak pernah** dikembalikan.
- Idempoten pada dua sumbu: token sama → baris yang sama dihidupkan; perangkat
  sama (`device_id`) dengan token baru → baris lama diperbarui.

### `GET /api/v1/notifikasi/perangkat`

`{ "items": [ { id, platform, device_label, app_version, push_aktif, dicabut, alasan_pencabutan, terakhir_aktif_pada, terdaftar_pada } ] }`

Tidak memuat token maupun hash-nya.

### `POST /api/v1/notifikasi/perangkat/pencabutan`

Body salah satu: `{ "perangkat_id": 7 }`, `{ "token": "Exponent…" }`, atau
`{ "semua": true }`; opsional `alasan`.
`403` bila perangkat milik pengguna lain.

### `POST /api/v1/notifikasi/perangkat/{id}/push`

`{ "push_aktif": false }` → menghentikan push untuk perangkat itu **tanpa**
mencabut registrasi dan **tanpa** mempengaruhi notifikasi in-app.

### `POST /api/v1/auth/logout` (diperluas, kompatibel)

Body opsional `{ "push_token": "Exponent…" }`.
Respons kini menambahkan `perangkat_push_dicabut` di samping `message` yang
sudah ada. Tanpa `push_token`, seluruh perangkat akun dicabut.

## 3. Panel kanal (khusus admin)

Seluruh endpoint di bawah menolak non-admin dengan `403`; capability admin
dihitung ulang di server.

| Metode & path | Fungsi |
| --- | --- |
| `GET /notifikasi/admin/status` | status ketiga kanal, kesiapan, antrean, penyedia, jumlah perangkat |
| `POST /notifikasi/admin/pemeriksaan` | `{ "kanal": "Push"\|"WhatsApp"\|"InApp" }` — pemeriksaan konfigurasi |
| `POST /notifikasi/admin/sakelar` | `{ "kanal": …, "aktif": bool }` |
| `POST /notifikasi/admin/pesan-uji` | `{ "kanal": … }` — pesan uji kepada admin sendiri |
| `GET /notifikasi/admin/kegagalan` | `kanal`, `page`, `per_page`, `permanen=1` |
| `POST /notifikasi/admin/kegagalan/{id}/coba-ulang` | mengantrekan ulang baris yang SAMA |
| `POST /notifikasi/admin/worker` | `{ "kanal": …, "uji_coba": bool }` — satu putaran manual |
| `GET /notifikasi/admin/audit` | `limit` — audit perubahan kanal |

Catatan status HTTP yang penting:

- `POST /notifikasi/admin/sakelar` dengan `kanal=InApp, aktif=false` → **422**
  ("in-app tidak dapat dimatikan").
- `POST /notifikasi/admin/sakelar` dengan `kanal=WhatsApp, aktif=true` saat
  pemeriksaan belum `Lulus` → **409**.
- `POST /notifikasi/admin/pesan-uji` untuk kanal yang mati → **409**.

Contoh potongan `GET /notifikasi/admin/status`:

```json
{
  "kanal": [
    { "kanal": "InApp", "aktif": true, "dapat_dimatikan": false, … },
    { "kanal": "Push", "aktif": false,
      "kesiapan": { "siap": true, "pesan": "Kunci perlindungan token push tersedia…", "detail": [] },
      "pemeriksaan": { "status": "Lulus", "pesan": "…", "pada": "2026-08-23 09:20:11" },
      "perangkat": { "total": 4, "aktif": 3, "dicabut": 1 },
      "antrean": { "Queued": 0, "Sent": 12, "Failed": 1, "gagal_permanen": 0, "belum_dibaca": 0, "total": 13 } },
    { "kanal": "WhatsApp", "aktif": false,
      "penyedia": { "nama": "belum-dipilih", "mengirim_nyata": false,
                    "dikenal": ["belum-dipilih","fake","http"],
                    "environment_dibutuhkan": ["WHATSAPP_PROVIDER","WHATSAPP_API_URL","WHATSAPP_API_TOKEN", …] }, … }
  ],
  "diperbarui_pada": "2026-08-23 09:20:11"
}
```

`environment_dibutuhkan` hanya memuat **NAMA** environment. Nilainya tidak
pernah dikirim.

## 4. Endpoint yang TIDAK berubah

Seluruh endpoint V1 (`/auth/login`, `/profile`, `/schedules*`, `/meetings*`,
`/reports*`) dan Fase 3 (`/me/capabilities`, `/izin/*`) tetap sama bentuk,
makna, dan status HTTP-nya. Satu-satunya perluasan adalah field tambahan
`perangkat_push_dicabut` pada respons logout, yang bersifat aditif dan
diabaikan klien lama.

Regresi ini diperiksa `tests/v2_phase3_api_contract.php` (116 pemeriksaan) dan
bagian KA-10 pada `tests/v2_phase4_api_contract.php`.
