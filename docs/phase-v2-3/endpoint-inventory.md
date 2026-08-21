# V2 Fase 3 — Inventaris Endpoint REST API

Versi: V2 Fase 3, 21 Agustus 2026. Seluruh endpoint berada di bawah `/api/v1`
dan bersifat **aditif**: tidak ada endpoint V1 yang dihapus, diubah path-nya,
diubah bentuk responsnya, atau diubah aturan otorisasinya.

## 1. Konvensi yang diwarisi dari V1

| Aspek | Aturan |
| --- | --- |
| Envelope sukses | `{"success":true,"data":…,"error":null}` |
| Envelope gagal | `{"success":false,"data":null,"error":{"code":"…","message":"…","details":{}}}` |
| Autentikasi | `Authorization: Bearer <token>` untuk semua endpoint kecuali `POST /auth/login` dan `GET /` |
| Pencabutan token | `POST /auth/logout` mencabut token yang dipakai; token tersebut selanjutnya selalu `401` |
| Pagination | `page` (mulai 1) dan `per_page` (1–100, default 20). Metadata: `pagination.current_page`, `per_page`, `total`, `total_pages` |
| Tanggal | `YYYY-MM-DD`, rentang inklusif |
| Body | `Content-Type: application/json` |

## 2. Kode status yang dipakai endpoint perizinan

| Status | Kode error | Arti dan tindak lanjut klien |
| --- | --- | --- |
| `200` | — | Berhasil; pada mutasi berarti operasi selesai **atau** merupakan pemutaran ulang kunci idempotensi yang sama |
| `201` | — | Sumber daya baru dibuat (pengajuan atau keputusan pertama) |
| `401` | `UNAUTHENTICATED` | Token tidak ada, tidak valid, kedaluwarsa, atau sudah dicabut → minta login ulang |
| `403` | `FORBIDDEN` | Di luar cakupan/kemampuan, atau pengajuan bukan milik pengguna → muat ulang cakupan, jangan ulangi |
| `404` | `NOT_FOUND` | Rute tidak dikenal |
| `409` | `CONFLICT` | Tumpang tindih tanggal, keputusan kedua, versi kedaluwarsa, atau kunci idempotensi dipakai untuk isi berbeda → **muat ulang lalu ulangi dengan versi terbaru** |
| `422` | `VALIDATION_FAILED` | Isian tidak valid, alasan wajib kosong, atau `idempotency_key` tidak dikirim → perbaiki isian |
| `500` | `SERVER_ERROR` | Galat server; aman untuk dicoba ulang dengan kunci idempotensi yang sama |

**Catatan penting tentang cakupan.** Semua endpoint di bawah menerima parameter
opsional `mode` (`admin`, `pengurus`, `murobi`, `orang_tua`). `mode` hanya dapat
**mempersempit** ke kemampuan yang benar-benar dimiliki pengguna. Nilai di luar
kemampuan pengguna diabaikan dan server memakai kemampuan default — sehingga
manipulasi parameter tidak pernah menaikkan hak akses.

## 3. Akun dan capability

### `GET /api/v1/profile`

Bentuk V1 tidak berubah; ditambahkan objek `capabilities`.

```json
{"success":true,"data":{
  "id":3,"name":"Pengurus A","username":"pengurus_a",
  "guru":null,"roles":["pengurus"],
  "capabilities":{
    "list":["pengurus"],
    "default_mode":"pengurus",
    "konteks":{"guru_id":null,"pengurus_id":1,"wali_id":null},
    "menus":[{"key":"izin_pengurus","label":"Perizinan — Pengurus","capability":"pengurus"}],
    "aksi":{"dapat_membuat_pengajuan":true,"dapat_memutuskan":false,
            "dapat_menetapkan_murobi":false,"dapat_mengoreksi_keputusan":false,
            "dapat_membatalkan":true,"hanya_baca":false}
  }},"error":null}
```

`POST /api/v1/auth/login` mengembalikan `profile` dengan bentuk yang sama.

**Kelayakan login (aditif).** V1: `admin`, atau `guru` dengan baris guru aktif.
V2 Fase 3 menambahkan: `pengurus` dengan baris `pengurus` aktif, dan `orang_tua`
dengan baris `wali` aktif. Akun tanpa relasi aktif tetap ditolak `401`.

### `GET /api/v1/me/capabilities`

Mengembalikan `list`, `default_mode`, `konteks`, dan `label` per mode. Dipakai
aplikasi untuk membangun navigasi tanpa menebak dari nama role.

## 4. Perizinan

### `GET /api/v1/izin/santri`

Santri yang boleh diajukan pengguna. Pengurus hanya menerima santri dalam
penugasan pembimbing aktifnya; admin menerima seluruh santri aktif.

Parameter: `mode`, `q`, `page`, `per_page`.

```json
{"scope":{"mode":"pengurus","label":"…","pengurus_id":1,"guru_id":null,"wali_id":null,"hanya_baca":false},
 "items":[{"id":1,"nis":"S-001","nama":"Santri A1","jenis_kelamin":"L",
           "cakupan":"Kamar: Kamar A","pembimbing_assignment_id":1,"hubungan":null}],
 "pagination":{"current_page":1,"per_page":20,"total":3,"total_pages":1},
 "filters":{"q":""}}
```

Orang tua dan murobi menerima `403`.

### `GET /api/v1/izin/anak`

Khusus orang tua. Daftar santri dengan relasi wali aktif.

```json
{"scope":{…},"items":[{"santri":{"id":1,"nis":"S-001","nama":"Santri A1"},
 "hubungan":"Ayah","wali_utama":true}],"total":1}
```

Peran lain menerima `403`.

### `POST /api/v1/izin/pengajuan`

Membuat pengajuan dan langsung menjalankan routing murobi.

```json
{"santri_id":1,"tgl_izin":"2026-09-01","tgl_kembali":"2026-09-03",
 "alasan":"Menghadiri acara keluarga","catatan_pengurus":"Dijemput orang tua",
 "idempotency_key":"uuid-atau-kunci-unik"}
```

Sukses pertama `201`:

```json
{"id":1,"status":"Diajukan","murobi_guru_id":27,"routing_kandidat":1,
 "routing_catatan":"Routing otomatis: satu murobi aktif cocok (Kamar: Kamar A).",
 "idempotent_replay":false}
```

- Retry dengan kunci **dan isi** sama → `200`, `idempotent_replay: true`, tanpa baris baru.
- Kunci sama dengan isi berbeda → `409`.
- Santri di luar cakupan → `403`. Tanggal kembali < tanggal izin → `422`.
- Rentang tumpang tindih dengan pengajuan `Diajukan`/`Perlu Penetapan Admin`/`Disetujui` → `409`.
- Tanpa `idempotency_key` → `422`.
- Routing dengan 0 atau >1 kandidat → status `Perlu Penetapan Admin`.

### `GET /api/v1/izin/pengajuan`

Daftar pengajuan dalam cakupan pengguna.

Parameter: `mode`, `q`, `status`, `source` (`legacy`/`v2`), `date_from`,
`date_to`, `santri_id`, `page`, `per_page`.

```json
{"scope":{…},
 "items":[{"id":1,"is_legacy":false,"sumber_label":"V2","status":"Diajukan","version":1,
   "santri":{"id":1,"nis":"S-001","nama":"Santri A1"},
   "pengurus":{"id":1,"nama":"Pengurus A"},"pengurus_label":"Pengurus A",
   "murobi":{"guru_id":27,"nama":"Murobi A"},"murobi_label":"Murobi A",
   "tahun_ajaran":{"id":1,"tahun":"2026/2027","semester":"Ganjil"},
   "tgl_izin":"2026-09-01","tgl_kembali":"2026-09-03",
   "alasan":"…","catatan_pengurus":null,
   "routing":{"kandidat":1,"catatan":"…","pada":"2026-08-21 16:32:06"},
   "keputusan_ringkas":null,"keputusan_label":"Belum ada keputusan",
   "pembatalan":null,"diajukan_pada":"2026-08-21 16:32:06",
   "aksi":{"putuskan_murobi":false,"putuskan_admin":false,"tetapkan_murobi":false,
           "batalkan":true,"koreksi":false}}],
 "pagination":{…},"filters":{…},
 "summary":{"total":3,"legacy":0,"per_status":{"Diajukan":1,"Perlu Penetapan Admin":2,
   "Disetujui":0,"Ditolak":0,"Dibatalkan":0}}}
```

### `GET /api/v1/izin/antrean`

Sama seperti daftar, dipersempit ke pengajuan yang menunggu tindakan pengguna:

| Mode | Isi antrean |
| --- | --- |
| `murobi` | `Diajukan` yang **diarahkan kepadanya** |
| `admin` | `Perlu Penetapan Admin` |
| `pengurus` | Pengajuan miliknya yang belum diputus |
| `orang_tua` | Kosong (tidak ada tindakan) |

### `GET /api/v1/izin/admin/monitor`

Khusus admin. Sama seperti daftar mode admin, ditambah `antrean_admin`
(jumlah pengajuan yang menunggu penetapan). Non-admin menerima `403`.

### `GET /api/v1/izin/pengajuan/{id}`

Detail lengkap: `pengajuan`, `keputusan`, `riwayat`, `koreksi`, dan `aksi`
(kemampuan tindakan yang dihitung server untuk cakupan pemanggil).

Pengajuan di luar cakupan menghasilkan `403` — bukan `404` — supaya keberadaan
pengajuan milik orang lain tidak bocor lewat pengubahan ID.

### `GET /api/v1/izin/pengajuan/{id}/riwayat`

`{"pengajuan_id":1,"status":"Disetujui","version":2,"riwayat":[…],"koreksi":[…]}`

Setiap baris riwayat memuat `peristiwa`, `status_sebelum`, `status_sesudah`,
`pelaku_nama`, `pelaku_kapasitas`, `alasan`, dan `waktu`.

### `GET /api/v1/izin/pengajuan/{id}/routing`

Khusus admin. Memuat `routing` (jumlah kandidat + catatan), `kandidat`
(hasil evaluasi routing untuk santri tersebut), dan `murobi_berhak` (seluruh
guru dengan penugasan murobi aktif). Non-admin menerima `403`.

### `POST /api/v1/izin/pengajuan/{id}/penetapan-murobi`

Khusus admin. Menetapkan atau menetapkan ulang murobi selama belum ada keputusan.

```json
{"murobi_guru_id":29,"alasan":"Kelas lebih relevan","version":1,
 "idempotency_key":"uuid"}
```

`200` → `{"id":2,"status":"Diajukan","murobi_guru_id":29,"version":2,"idempotent_replay":false}`

- Alasan kosong → `422`. Guru tanpa penugasan murobi aktif → `422`.
- `version` kedaluwarsa atau pengajuan sudah diputus/dibatalkan → `409`.

### `POST /api/v1/izin/pengajuan/{id}/keputusan`

Dipakai **murobi** dan **admin sebagai Admin Pengganti**. Kapasitas ditentukan
server dari cakupan pemanggil, bukan dari body.

```json
{"hasil":"Disetujui","alasan":"Alasan dan rentang tanggal wajar",
 "alasan_penggantian":"Kamar C belum memiliki murobi aktif",
 "version":1,"idempotency_key":"uuid"}
```

Sukses pertama `201`:

```json
{"id":1,"keputusan_id":1,"status":"Disetujui","kapasitas":"Murobi","version":2,
 "idempotent_replay":false}
```

- `alasan_penggantian` **wajib** bila kapasitasnya `Admin Pengganti`; kosong → `422`.
- Murobi yang bukan tujuan pengajuan → `403`.
- Keputusan kedua (kunci berbeda) → `409`, keputusan pertama tidak tertimpa.
- Retry dengan kunci dan isi sama → `200` dengan `idempotent_replay: true`.

### `POST /api/v1/izin/pengajuan/{id}/pembatalan`

Pengurus pemilik pengajuan atau admin, hanya sebelum ada keputusan.

```json
{"alasan":"Jadwal keberangkatan dibatalkan","version":2,"idempotency_key":"uuid"}
```

`200` → `{"id":2,"status":"Dibatalkan","version":3,"idempotent_replay":false}`

Alasan kosong → `422`. Setelah keputusan → `409`. Pengurus lain → `403`.

### `POST /api/v1/izin/pengajuan/{id}/koreksi`

Khusus admin, untuk pengajuan yang sudah diputus. Koreksi **tidak menghapus**
keputusan atau riwayat: nilai sebelum/sesudah disimpan pada `izin_keputusan_koreksi`.

```json
{"hasil":"Ditolak","alasan":"Ternyata bertepatan dengan ujian",
 "alasan_koreksi":"Informasi jadwal ujian baru diterima","version":2,
 "idempotency_key":"uuid"}
```

`200` → `{"id":1,"status":"Ditolak","koreksi_id":1,"version":3,"idempotent_replay":false}`

### `GET /api/v1/izin/filters`

Pilihan filter yang aman ditampilkan: `statuses`, `sources`, `modes` (capability
pengguna), `murobi_berhak` (khusus admin), dan `santri` (khusus orang tua).

## 5. Endpoint V1 yang tidak berubah

`GET /`, `POST /auth/login`, `GET /profile`, `POST /auth/logout`,
`GET /schedules/today`, `GET /schedules`, `GET /schedules/{id}`,
`POST /schedules/{id}/meetings`, `GET /meetings`, `GET /meetings/{id}`,
`GET /meetings/{id}/attendance`, `PUT /meetings/{id}/attendance`,
`GET /reports`, `GET /reports/filters`, `GET /reports/print`,
`GET /reports/meetings/{id}`.

Perubahan struktural satu-satunya pada router adalah: autentikasi dilakukan satu
kali, lalu penjaga role admin/guru diterapkan **per endpoint** melalui
`ApiTokenAuthenticator::assertScheduleAccess()`. Perilaku untuk aplikasi guru
identik — akun tanpa role guru/admin tetap menerima `403` pada endpoint jadwal
dan laporan. `GET /profile` dan `POST /auth/logout` kini juga dapat diakses akun
pengurus/orang tua; ini pelebaran akses, bukan perubahan kontrak bagi klien lama.
