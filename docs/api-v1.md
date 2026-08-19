# Kontrak REST API `/api/v1`

Versi: Fase 4, 19 Agustus 2026. Semua waktu server menggunakan zona `APP_TIMEZONE` dan semua trafik produksi wajib HTTPS.

## Konvensi

- Base URL dikonfigurasi di klien melalui `EXPO_PUBLIC_API_BASE_URL`; alamat produksi tidak ditulis di source code.
- Endpoint selain login memerlukan `Authorization: Bearer <token>`.
- Token acak berlaku 30 hari (dapat diatur dengan `API_TOKEN_TTL_DAYS`), hanya hash HMAC-SHA-256 yang disimpan server, dan dapat dicabut saat logout.
- Request ber-body memakai `Content-Type: application/json`.
- Sukses: `{"success":true,"data":...,"error":null}`.
- Gagal: `{"success":false,"data":null,"error":{"code":"KODE","message":"Pesan","details":{}}}`.
- `401` berarti kredensial/token tidak valid; `403` berarti role atau kepemilikan ditolak; `404` sumber daya tidak ada; `409` konflik atau kunci idempotensi dipakai untuk payload berbeda; `422` validasi gagal.
- Pagination memakai `page` (mulai 1) dan `per_page` (1–100). Metadata: `pagination.current_page`, `per_page`, `total`, dan `total_pages`.
- Rentang tanggal inklusif, format `YYYY-MM-DD`, maksimal 92 hari.
- Status absensi: `Hadir`, `Terlambat`, `Izin`, `Sakit`, `Alpa`.

## Autentikasi

### `POST /api/v1/auth/login`

Request:

```json
{"username":"guru1","password":"rahasia","device_name":"iPhone Guru"}
```

Sukses `200`:

```json
{"success":true,"data":{"token":"token-acak-sekali-tampil","token_type":"Bearer","expires_at":"2026-09-18T10:00:00+07:00","profile":{"id":12,"name":"Guru A","username":"guru1","guru":{"id":7,"nip":"2627001","name":"Guru A"},"roles":["guru"]}},"error":null}
```

Password salah, akun nonaktif, atau guru nonaktif mengembalikan `401 INVALID_CREDENTIALS` dengan pesan generik. Password dan hash token tidak pernah dikembalikan.

### `GET /api/v1/profile`

Mengembalikan profil token saat ini. Sukses `200`.

### `POST /api/v1/auth/logout`

Mencabut token bearer yang dipakai. Sukses `200`. Token yang sama selanjutnya menerima `401`.

Refresh token terpisah tidak dipakai pada V1. Login ulang membuat sesi 30 hari baru.

## Jadwal

### `GET /api/v1/schedules/today`

Mengembalikan kejadian jadwal hari ini dan kejadian terdekat setelah hari ini pada semester aktif. Guru hanya menerima jadwal miliknya; admin dapat melihat lintas guru.

### `GET /api/v1/schedules`

Filter: `date_from`, `date_to`, `page`, `per_page`. Default rentang adalah hari ini sampai 30 hari ke depan. Setiap item adalah satu kejadian tanggal dari pola mingguan dan memuat ringkasan pertemuan bila sudah dibuka.

### `GET /api/v1/schedules/{schedule_id}?date=YYYY-MM-DD`

Mengembalikan detail tugas dan pertemuan untuk tanggal tersebut bila tersedia. Guru yang meminta jadwal guru lain menerima `403`.

Contoh item jadwal:

```json
{"id":21,"occurrence_date":"2026-08-24","day":"Senin","start_time":"05:00","end_time":"06:00","subject":"Fiqih","book":"Riyadlul Badiah","place":"Aula","class":{"id":3,"name":"1 Tsanawi","level":"SMA"},"teacher":{"id":7,"name":"Guru A"},"academic_year":{"id":4,"year":"2026/2027","semester":"Ganjil"},"meeting":null}
```

## Pertemuan dan absensi

### `GET /api/v1/meetings?page=1&per_page=20&date_from=&date_to=`

Mengembalikan pertemuan yang boleh diakses pengguna, dengan filter tanggal opsional.

### `POST /api/v1/schedules/{schedule_id}/meetings`

Membuka pertemuan dan membekukan daftar peserta. Guru harus memiliki jadwal; tanggal harus sesuai hari pola dan semester aktif.

```json
{"date":"2026-08-24","notes":"Pertemuan pekan ini","idempotency_key":"uuid-atau-kunci-unik"}
```

Sukses pertama `201`; retry kunci dan payload sama mengembalikan hasil tersimpan (`200`) tanpa pertemuan/peserta tambahan. Jadwal–tanggal yang sudah dibuka dengan kunci lain mengembalikan `409`.

### `GET /api/v1/meetings/{meeting_id}`

Mengembalikan detail pertemuan, snapshot peserta, absensi guru, dan absensi santri yang telah tersimpan. Guru lintas kepemilikan menerima `403`.

### `GET /api/v1/meetings/{meeting_id}/attendance`

Alias terfokus untuk data snapshot dan absensi pertemuan. Sukses `200`.

### `PUT /api/v1/meetings/{meeting_id}/attendance`

Menyimpan kehadiran guru dan seluruh snapshot santri dalam satu transaksi. Daftar `students` wajib tepat sama dengan snapshot, tanpa ID ganda. Pertemuan `Dibuka` otomatis menjadi `Selesai`. Koreksi pertemuan `Selesai` wajib memiliki `correction_reason` dan memperbarui baris yang sama.

```json
{
  "idempotency_key":"uuid-yang-dipertahankan-saat-retry",
  "teacher":{"status":"Hadir","notes":""},
  "students":[
    {"student_id":101,"status":"Hadir","notes":""},
    {"student_id":102,"status":"Izin","notes":"Sakit dari rumah"}
  ],
  "correction_reason":null
}
```

Sukses pertama `200`. Retry kunci dan payload yang sama mengembalikan response yang sama tanpa baris tambahan. Kunci sama dengan payload berbeda mengembalikan `409 IDEMPOTENCY_CONFLICT`. Error validasi mengembalikan `422`; seluruh transaksi dibatalkan sehingga tidak ada absensi sebagian.

## Contoh error

```json
{"success":false,"data":null,"error":{"code":"FORBIDDEN","message":"Anda tidak berhak mengakses jadwal ini.","details":{}}}
```

```json
{"success":false,"data":null,"error":{"code":"VALIDATION_FAILED","message":"Data yang dikirim belum valid.","details":{"students":"Daftar santri harus sama dengan snapshot pertemuan."}}}
```
