# Kontrak REST API `/api/v1`

Versi: V2 Fase 3, 21 Agustus 2026 (sebelumnya Fase 5 V1, 20 Agustus 2026). Semua waktu server menggunakan zona `APP_TIMEZONE` dan semua trafik produksi wajib HTTPS.

## Konvensi

- Base URL dikonfigurasi di klien melalui `EXPO_PUBLIC_API_BASE_URL`; alamat produksi tidak ditulis di source code.
- Endpoint selain login memerlukan `Authorization: Bearer <token>`.
- Token acak berlaku 30 hari (dapat diatur dengan `API_TOKEN_TTL_DAYS`), hanya hash HMAC-SHA-256 yang disimpan server, dan dapat dicabut saat logout.
- Request ber-body memakai `Content-Type: application/json`.
- Sukses: `{"success":true,"data":...,"error":null}`.
- Gagal: `{"success":false,"data":null,"error":{"code":"KODE","message":"Pesan","details":{}}}`.
- `401` berarti kredensial/token tidak valid; `403` berarti role atau kepemilikan ditolak; `404` sumber daya tidak ada; `409` konflik atau kunci idempotensi dipakai untuk payload berbeda; `422` validasi gagal.
- Pagination memakai `page` (mulai 1) dan `per_page` (1–100). Metadata: `pagination.current_page`, `per_page`, `total`, dan `total_pages`.
- Rentang tanggal inklusif dan berformat `YYYY-MM-DD`. Batas 92 hari hanya berlaku pada daftar kejadian jadwal; laporan tidak dipotong diam-diam.
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

## Laporan Fase 5

Seluruh endpoint laporan memerlukan bearer token. Untuk role `guru`, server mengambil
`guru_id` dari token dan selalu menambahkannya sebagai batas query. Mengirim
`teacher_id` guru lain menghasilkan `403`; memilih `schedule_id` di luar kepemilikan
tidak pernah mengembalikan baris, dan detail pertemuan guru lain menghasilkan `403`.

Filter laporan: `date_from`, `date_to`, `academic_year_id`, `teacher_id`, `class_id`,
`schedule_id`, `status`, `page`, dan `per_page`. `date_from`/`date_to` default ke awal
bulan berjalan dan hari ini. Status adalah `Hadir`, `Terlambat`, `Izin`, `Sakit`, atau
`Alpa`.

### `GET /api/v1/reports`

Mengembalikan `summary`, rekap `schedules`, baris `items`, `pagination`, filter
ternormalisasi, dan label `active_filters`. Baris detail menyatukan kehadiran guru dan
santri. `summary.detail_count` dan jumlah lima status dihitung dari sumber query yang
sama. UI memakai pagination maksimal 100 baris per halaman.

### `GET /api/v1/reports/filters`

Mengembalikan pilihan tahun ajaran, guru, kelas, jadwal, dan status yang pernah
memiliki pertemuan. Untuk guru, pilihan guru/jadwal juga dibatasi ke kepemilikannya.

### `GET /api/v1/reports/meetings/{meeting_id}`

Mengembalikan identitas pertemuan, kehadiran guru, seluruh peserta snapshot, status,
catatan, pencatat, waktu pencatatan, dan waktu perubahan. Endpoint bersifat read-only.

### `GET /api/v1/reports/print`

Menerima seluruh filter laporan kecuali pagination dan mengembalikan `{ "html": "..." }`.
HTML memuat seluruh hasil filter, identitas Pesantren Al Hasan, jenis laporan, filter
aktif, waktu pembuatan, pembuat, dan nomor halaman. Aplikasi mengubah HTML ini menjadi
PDF lokal melalui `expo-print`, kemudian membuka dialog cetak atau berbagi. HTML tetap
berada di dalam envelope JSON standar API.

## Perizinan V2 (Fase 3)

Seluruh endpoint perizinan bersifat **aditif** di bawah `/api/v1` dan tidak
mengubah endpoint V1 mana pun. Inventaris lengkap beserta contoh request dan
response ada pada `docs/phase-v2-3/endpoint-inventory.md`; matriks kewenangan
per peran ada pada `docs/phase-v2-3/capability-matrix.md`.

Ringkasan:

| Metode & path | Kegunaan |
| --- | --- |
| `GET /me/capabilities` | Kemampuan aktual pengguna untuk membangun navigasi |
| `GET /izin/santri` | Santri dalam cakupan pengurus (admin: seluruh santri aktif) |
| `GET /izin/anak` | Daftar anak terhubung untuk akun orang tua |
| `POST /izin/pengajuan` | Membuat pengajuan (idempoten) |
| `GET /izin/pengajuan` | Daftar pengajuan dalam cakupan, berhalaman dan berfilter |
| `GET /izin/antrean` | Antrean tindakan sesuai peran |
| `GET /izin/admin/monitor` | Pemantauan seluruh pengajuan (admin) |
| `GET /izin/pengajuan/{id}` | Detail pengajuan, keputusan, riwayat, koreksi, dan aksi |
| `GET /izin/pengajuan/{id}/riwayat` | Riwayat perubahan dan koreksi |
| `GET /izin/pengajuan/{id}/routing` | Kandidat routing dan murobi yang berhak (admin) |
| `POST /izin/pengajuan/{id}/penetapan-murobi` | Menetapkan/mengganti murobi (admin, alasan wajib) |
| `POST /izin/pengajuan/{id}/keputusan` | Keputusan murobi atau Admin Pengganti |
| `POST /izin/pengajuan/{id}/pembatalan` | Pembatalan sebelum keputusan (alasan wajib) |
| `POST /izin/pengajuan/{id}/koreksi` | Koreksi keputusan (admin, alasan wajib) |
| `GET /izin/filters` | Pilihan filter yang aman ditampilkan |

Aturan yang berlaku pada seluruh endpoint di atas:

- Parameter `mode` (`admin`, `pengurus`, `murobi`, `orang_tua`) hanya dapat
  **mempersempit** ke kemampuan yang benar-benar dimiliki pengguna. Nilai lain
  diabaikan, sehingga manipulasi parameter tidak pernah menaikkan hak akses.
- Setiap mutasi wajib menyertakan `idempotency_key` (8–100 karakter,
  `[A-Za-z0-9._:-]`). Retry dengan kunci dan isi yang sama mengembalikan respons
  tersimpan dengan status `200`; kunci sama dengan isi berbeda menghasilkan `409`.
- Mutasi menerima `version` (optimistic version). Versi kedaluwarsa atau
  keputusan kedua menghasilkan `409` tanpa menimpa keputusan pertama.
- `GET /profile` dan respons login kini memuat objek `capabilities`. Field ini
  aditif; klien V1 yang mengabaikannya tetap berfungsi tanpa perubahan.
- Akun `pengurus` dan `orang_tua` kini dapat login ke API bila relasi masternya
  aktif. Endpoint jadwal dan laporan V1 tetap terbatas pada role `admin`/`guru`
  dan tetap menjawab `403` untuk akun lain.

## Fondasi penugasan V3–V6 (7 September 2026)

Aditif; tidak mengubah satu pun kontrak di atas. `GET /profile` dan `profile`
pada respons login memuat satu field baru, `feature_capabilities`
(`{list, sumber, cakupan}`), berisi capability dari penugasan fungsional
(guru mata pelajaran, Bagian Pendidikan, bendahara pembiayaan bulanan, panitia
PSB, bendahara PSB, cakupan binaan murobi/pembimbing). Field `capabilities`
(`list/default_mode/konteks/menus/aksi`), `default_mode`, `menus`, dan
`GET /me/capabilities` **tidak berubah**; tidak ada mode atau menu baru. Klien
yang tidak mengenal field ini mengabaikannya. Rincian:
`docs/fondasi-penugasan-v3-v6/kontrak-api.md`.

## Kesehatan santri V5 Fase 1 (24 Oktober 2026)

Aditif di bawah `/api/v1/v5/kesehatan`; tidak mengubah kontrak di atas. Hak
dihitung ulang server pada setiap permintaan oleh layanan yang sama dengan
website: petugas (pengurus dengan penugasan kesehatan aktif) membaca dan
mencatat; admin hanya membaca dan meninjau riwayat akses; akun lain `403`.
Seluruh respons `Cache-Control: private, no-store`. `feature_capabilities`
pada login/profil bertambah satu kunci aditif `kesehatan.catat`.

- `GET /v5/kesehatan/akses` → `{baca, catat, sumber, riwayat_akses}`.
- `GET /v5/kesehatan/santri?q=` (petugas) → santri aktif, paling banyak 100.
- `GET /v5/kesehatan/kunjungan?q=&santri_id=&status=&page=` → daftar tanpa
  isi klinis (`id, santri, waktu_kunjungan, dicatat_pada, status, versi, petugas`).
- `POST /v5/kesehatan/kunjungan` (petugas) dengan header `Idempotency-Key`
  (atau `idempotency_key` di badan, 8–100 karakter `A-Za-z0-9._:-`) dan badan
  `{santri_id, waktu_kunjungan: "Y-m-d H:i", keluhan, catatan_klinis?, tindakan?}`
  → `201 {id, replayed:false}`; kunci + isi sama → `200 {id, replayed:true}`;
  kunci sama isi berbeda → `409 CONFLICT` tanpa perubahan. Admin → `403`.
- `GET /v5/kesehatan/kunjungan/{id}` → rincian beserta `keluhan`,
  `catatan_klinis`, `tindakan`; setiap pembukaan tercatat (kanal `api`).
- `GET /v5/kesehatan/kunjungan/{id}/akses` (admin) → riwayat akses.

Rincian, penafsiran, dan batas uji: `docs/phase-v5-1/README.md`.

## Ringkasan kesehatan untuk wali V5 Fase 3 (24 Oktober 2026)

Aditif di bawah `/api/v1/v5/kesehatan`; kontrak Fase 1–2 tidak berubah.
Seluruh respons `Cache-Control: private, no-store`. Tampilan wali berasal dari
proyeksi khusus yang tidak membaca catatan klinis; relasi wali aktif (akun,
master wali, `santri_wali`, santri) diperiksa pada setiap permintaan, sehingga
token lama sesudah relasi dicabut mendapat `403`.

Wali (akun orang tua aktif):

- `GET /v5/kesehatan/wali/akses` → `{wali, anak:[{id, nama}]}`.
- `GET /v5/kesehatan/wali/ringkasan?santri_id=&page=` → `rows:[{id, santri, tanggal_kunjungan,
  diterbitkan_pada, status: Terbit|Ditarik, versi, diperbarui, ada_tindak_lanjut}]`.
- `GET /v5/kesehatan/wali/ringkasan/{id}` → `{id, santri, tanggal_kunjungan, diterbitkan_pada,
  status, versi, diperbarui, ringkasan, tindakan_umum, tindak_lanjut_wali}`; isi `null`
  bila Ditarik. Tidak ada/anak lain/relasi dicabut → `403`.

Petugas (penugasan kesehatan aktif; admin, pembimbing, murobi, wali → `403`):

- `POST /v5/kesehatan/kunjungan/{id}/ringkasan/pratinjau` badan
  `{kunjungan_versi, revisi_ringkasan (0 bila belum pernah), ringkasan, tindakan_umum?,
  tindak_lanjut_wali?, alasan? (wajib bila pernah terbit)}` → `{pratinjau, pratinjau_hash, ...}`
  tanpa menulis apa pun.
- `POST /v5/kesehatan/kunjungan/{id}/ringkasan` badan yang sama + `konfirmasi: true` +
  `pratinjau_hash`, header `Idempotency-Key` → `201 {ringkasan_id, versi, revisi, replayed:false,
  notifikasi:{in_app, push}}`; kunci + isi sama → `200 replayed:true`; revisi/versi kunjungan
  usang → `409`; isi berbeda dari pratinjau → `422`.
- `POST /v5/kesehatan/kunjungan/{id}/ringkasan/tarik` `{revisi_ringkasan, alasan}` →
  `{ringkasan_id, versi, revisi, replayed}`; tanpa notifikasi baru.

Pembaca internal: `GET /v5/kesehatan/kunjungan/{id}/ringkasan` → petugas/admin
mendapat versi berlaku + seluruh versi dan peristiwa (alasan, pelaku, waktu);
pembimbing/murobi terkait hanya versi berlaku.

Notifikasi: `event_type` `v5_kesehatan_ringkasan_terbit|_diganti` atau
`v5_kesehatan_tindak_lanjut`, judul/isi generik, `tautan` pada `/notifikasi`
bertambah kunci aditif `ringkasan_id` (`tipe: kesehatan_ringkasan`); payload
Push hanya `{tipe, ringkasan_id}`. Klien wajib meminta
`/v5/kesehatan/wali/ringkasan/{id}` (yang memeriksa ulang hak) setelah diketuk.

Rincian, penafsiran, dan batas uji: `docs/phase-v5-3/README.md`.

## Kesehatan santri V5 Fase 4 — tambahan untuk aplikasi (24 Oktober 2026)

Aditif; kontrak Fase 1–3 tidak berubah (baris pemilih santri hanya bertambah
satu kunci). Seluruh respons `Cache-Control: private, no-store`.

Pemilih santri petugas dikelompokkan per kelas penempatan aktif tahun ajaran
aktif (permintaan Human Developer 24 Oktober 2026):

- `GET /v5/kesehatan/santri?q=&kelas_id=` (petugas) → baris `{id, nis, nama, kelas: {id, nama} | null}`,
  urut kelas lalu nama, santri belum ditempatkan paling akhir; `kelas_id` angka = satu kelas,
  `tanpa` = belum ditempatkan, kosong = semua; paling banyak 100 baris + `total`.
- `GET /v5/kesehatan/santri/kelas` (petugas) → `{kelas: [{id, nama, jumlah}], tanpa_kelas}`.

Penugasan petugas kesehatan (admin saja; akun lain `403`). Lewat
`PenugasanService` yang sama dengan Pusat Penugasan web (transaksi,
tumpang tindih `409`, audit `penugasan.*`); tidak ada penghapusan.

- `GET /v5/kesehatan/penugasan?status=aktif|akan_datang|berakhir|nonaktif&page=` →
  `{rows: [{id, pengurus, tahun, tanggal_mulai, tanggal_selesai, aktif, status, catatan,
  alasan_pengakhiran, akun: {username, aktif} | null, diperbarui_pada}], total, page, per_page,
  opsi: {pengurus, tahun}}`.
- `POST /v5/kesehatan/penugasan` `{pengurus_id, tahun_ajaran_id, tanggal_mulai, tanggal_selesai?, catatan?}`
  → `201 {id, penugasan, hak}` (`hak` = penjelasan bila belum efektif, `null` bila efektif);
  validasi `422` dengan `details` per kolom.
- `POST /v5/kesehatan/penugasan/{id}/akhiri` `{tanggal_selesai, alasan}`,
  `/nonaktifkan` `{alasan}`, `/aktifkan` `{alasan}` → `{penugasan}`; alasan wajib (`422`),
  keadaan sudah sama `409`.

Notifikasi kesehatan di aplikasi: data Push `{tipe: kesehatan_ringkasan, ringkasan_id}` dan
tautan skema `alhasanapps://kesehatan/wali/{id}` hanya penunjuk; aplikasi selalu meminta
`/v5/kesehatan/wali/ringkasan/{id}` setelah autentikasi.

Rincian, penafsiran, dan batas uji: `docs/phase-v5-4/README.md`.

## Laporan layanan klinik V5 Fase 5 (24 Oktober 2026)

Aditif, hanya baca, `Cache-Control: private, no-store`. Layanan yang sama dengan
`portal/kesehatan_laporan.php`; tidak ada isi klinis (keluhan, obat, tujuan/hasil
rujukan, uraian tindak lanjut, teks ringkasan, alasan) pada respons.

- `GET /v5/kesehatan/laporan?dari=YYYY-MM-DD&sampai=YYYY-MM-DD&kelas_id=` →
  petugas dan admin: seluruh santri termasuk Draf; pembimbing/murobi terkait: hanya
  kunjungan Tercatat santri dalam cakupan penugasan aktif (penyaring baris sama dengan
  daftar); akun lain termasuk wali `403`. Periode bawaan awal bulan berjalan s.d. hari ini;
  terbalik, > 366 hari, atau tanggal tidak valid `422`. `kelas_id` angka = kelas
  penempatan aktif saat ini, `tanpa` = belum ditempatkan, lainnya = semua.
- Respons `{periode: {dari, sampai, hari}, filter, akses,
  kunjungan: {total, tercatat, draf|null, santri, dikoreksi, dengan_obat, dengan_rujukan, dengan_tindak_lanjut},
  obat: {pemberian}, rujukan: {total, menunggu, diketahui}, tindak_lanjut: {total, terbuka, terlambat, selesai},
  ringkasan_wali: {terbit, ditarik, belum}|null, per_kelas: [{kelas|null, kunjungan, santri}],
  per_bulan: [{bulan, kunjungan, santri}], kelas_pilihan: [{id, nama}],
  tertunda: {rujukan: {total, rows}, tindak_lanjut: {total, rows}, batas},
  pengawasan: {...}|null}`. `draf` null bagi pembaca binaan. Baris `tertunda`
  `{kunjungan_id, santri: {id, nis, nama}, kelas, waktu_kunjungan, status, jumlah, tanggal, lewat}`
  tidak dibatasi periode (semua rujukan menunggu / tindak lanjut terbuka), paling banyak 50.
- `pengawasan` hanya admin: `koreksi {total, beralasan, wajib_alasan, tanpa_alasan_wajib}`,
  `koreksi_terbaru` (pelaku, waktu, versi sebelum/sesudah, beralasan — tanpa teks alasan),
  `per_petugas`, `akses` (log akses per sumber/aksi/kanal), `peristiwa_ringkasan`, `notifikasi`
  (status antrean V5), berdasarkan waktu peristiwa dalam periode.

Rincian, penafsiran, dan batas uji: `docs/phase-v5-5/README.md`.

## Notifikasi V2 (Fase 4)

Aditif; tidak mengubah satu pun kontrak di atas. Rincian lengkap beserta contoh
respons ada pada `docs/phase-v2-4/endpoint-inventory.md`.

Notifikasi selalu milik SATU akun: tidak ada parameter pemilik pada satu pun
endpoint di bawah. Penerima ditentukan server dari bearer token, sehingga
mengganti id pada URL hanya menghasilkan `403` — bukan notifikasi orang lain.

| Metode | Path | Keterangan |
| --- | --- | --- |
| GET | `/notifikasi` | daftar in-app; `status`, `page`, `per_page` |
| GET | `/notifikasi/belum-dibaca` | `{ "jumlah": n }` |
| GET | `/notifikasi/{id}` | detail; `403` bila bukan milik pengguna |
| POST | `/notifikasi/{id}/dibaca` | tandai satu dibaca (idempoten) |
| POST | `/notifikasi/dibaca-semua` | tandai semua dibaca |
| GET | `/notifikasi/perangkat` | daftar perangkat push milik pengguna |
| POST | `/notifikasi/perangkat` | registrasi Expo push token (`201`) |
| POST | `/notifikasi/perangkat/pencabutan` | cabut satu perangkat, satu token, atau semua |
| POST | `/notifikasi/perangkat/{id}/push` | sakelar push per perangkat |
| GET | `/notifikasi/admin/status` | status kanal (admin) |
| POST | `/notifikasi/admin/pemeriksaan` | pemeriksaan konfigurasi kanal (admin) |
| POST | `/notifikasi/admin/sakelar` | nyalakan/matikan kanal (admin) |
| POST | `/notifikasi/admin/pesan-uji` | pesan uji kepada admin sendiri |
| GET | `/notifikasi/admin/kegagalan` | daftar pengiriman gagal (admin) |
| POST | `/notifikasi/admin/kegagalan/{id}/coba-ulang` | antrekan ulang baris yang SAMA (admin) |
| POST | `/notifikasi/admin/worker` | satu putaran worker manual (admin) |
| GET | `/notifikasi/admin/audit` | audit perubahan kanal (admin) |

Catatan penting:

- `POST /auth/logout` kini menerima body opsional `{ "push_token": "…" }` dan
  menambahkan field `perangkat_push_dicabut` pada responsnya. Perubahan ini
  aditif: klien lama yang mengirim body kosong tetap berfungsi, dan seluruh
  perangkat akun dicabut bila token tidak disertakan.
- Token perangkat TIDAK PERNAH dikembalikan endpoint mana pun.
- Isi notifikasi untuk kanal push dan WhatsApp tidak memuat nama santri, alasan
  izin, catatan pengurus, maupun alasan keputusan.
- Payload deep link hanya memuat penunjuk sumber daya. Klien tetap wajib
  memanggil `GET /izin/pengajuan/{id}`, yang memverifikasi cakupan di server.
- `kanal=InApp, aktif=false` pada sakelar dijawab `422`: notifikasi in-app
  adalah sumber status utama dan tidak dapat dimatikan.
- `kanal=WhatsApp, aktif=true` sebelum pemeriksaan konfigurasi berstatus
  `Lulus` dijawab `409`.

## Contoh error

```json
{"success":false,"data":null,"error":{"code":"FORBIDDEN","message":"Anda tidak berhak mengakses jadwal ini.","details":{}}}
```

```json
{"success":false,"data":null,"error":{"code":"VALIDATION_FAILED","message":"Data yang dikirim belum valid.","details":{"students":"Daftar santri harus sama dengan snapshot pertemuan."}}}
```
