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

## Keuangan santri/BSA V6 Fase 1 (25 Oktober 2026)

Aditif di bawah `/api/v1/v6/bsa`; tidak mengubah kontrak di atas. Hak dihitung
ulang server pada setiap permintaan oleh layanan yang sama dengan website
(`BsaService`): petugas BSA (pengurus dengan penugasan `bendahara_bulanan`
aktif, label "Petugas BSA") membaca dan memutasi santri aktif dalam cakupan
unit penugasannya; admin hanya membaca seluruh santri; orang tua hanya membaca
anak dengan relasi wali aktif; akun lain `403`. Santri/tagihan di luar hak
dijawab `404`. Seluruh respons `Cache-Control: private, no-store`. Nominal
rupiah bulat (bilangan bulat JSON atau teks angka dengan titik ribuan,
1–1.000.000.000); pecahan/float ditolak `422`. `feature_capabilities` pada
login/profil bertambah satu kunci aditif `bsa.tabungan`.

- `GET /v6/bsa/akses` → `{baca, kelola, sumber, jenjang}` (`sumber`:
  `petugas`, `admin`, `admin_petugas`, `wali`, atau `null`; `jenjang` null =
  seluruh unit).
- `GET /v6/bsa/santri?q=&page=` → santri sesuai hak beserta `saldo`,
  `tagihan_terbuka`, `sisa_tagihan`.
- `GET /v6/bsa/santri/{id}?page=` → `{santri, saldo, tagihan[], tabungan{rows,
  total, page, per_page}, akses}`; bagi orang tua baris tabungan tanpa
  `referensi` dan `catatan`.
- `GET /v6/bsa/santri/{id}/saldo` → `{santri_id, saldo}`.
- `POST /v6/bsa/santri/{id}/setoran` (petugas) dengan header `Idempotency-Key`
  (atau `idempotency_key` di badan) dan badan `{nominal, metode: "Tunai"|"Transfer",
  referensi? (wajib untuk Transfer), catatan?}` → `201 {id, replayed:false,
  saldo_sesudah}`; kunci + isi sama → `200 {id, replayed:true, saldo_sesudah}`;
  kunci sama dengan nominal/operasi/santri berbeda → `409 CONFLICT` tanpa mutasi.
- `POST /v6/bsa/santri/{id}/penarikan` (petugas) — badan sama ditambah
  `tujuan` (wajib, 3–255 karakter); melebihi saldo → `422` tanpa mutasi.
- `POST /v6/bsa/tagihan` (petugas) dengan `Idempotency-Key` dan badan
  `{santri_id, periode: "TTTT-BB", jatuh_tempo: "Y-m-d", komponen: [{nama,
  nominal}] (1–10), catatan?}` → `201 {id, replayed}`; tagihan kedua untuk
  santri+periode yang sama → `409`.
- `GET /v6/bsa/tagihan/{id}` → rincian tagihan dengan snapshot komponen,
  `total`, `terbayar`, `sisa`, `status_bayar`.

Admin dan orang tua yang memanggil endpoint POST → `403` tanpa mutasi.
Rincian, penafsiran, dan batas uji: `docs/phase-v6-1/README.md`.

### Tagihan bulanan lengkap V6 Fase 2 (25 Oktober 2026)

Aditif (migrasi 028); kontrak Fase 1 di atas tetap. Seluruh POST memakai
header `Idempotency-Key` (atau `idempotency_key` di badan): kunci + isi sama →
`200 {…, replayed: true}`; kunci sama isi beda → `409`. Mutasi hanya petugas BSA
atas santri aktif dalam cakupan unitnya; admin/orang tua → `403`, data di luar
hak → `404`. Nominal rupiah bulat (bilangan bulat JSON atau teks angka); tanpa
pecahan. Sebelum migrasi 028 terpasang endpoint di bawah ini menjawab `503`.

- `GET /v6/bsa/tarif` (petugas, admin) → `{unit[], tarif[{id, unit,
  berlaku_mulai, total, status: Berlaku|Diganti, alasan, komponen[]}]}`.
- `POST /v6/bsa/tarif` `{unit, berlaku_mulai: "TTTT-BB", komponen: [{nama,
  nominal}] (1–10), alasan}` → `201 {id, menggantikan_id}`. Alasan wajib bila
  unit sudah pernah bertarif; tarif periode mulai yang sama ditandai `Diganti`.
- `GET /v6/bsa/santri/{id}/penyesuaian` (petugas, admin);
  `POST /v6/bsa/santri/{id}/penyesuaian` `{jenis: Potongan|Tambahan, nama,
  nominal, berlaku_mulai, berlaku_sampai?, alasan}` → `201`;
  `POST /v6/bsa/penyesuaian/{id}/cabut` `{alasan}` → `200`.
- `POST /v6/bsa/penerbitan/pratinjau` `{periode, jatuh_tempo, unit?}` →
  `{rows[{santri_id, unit, hasil: Terbit|SudahAda|Dilewati, keterangan,
  tarif_id, total, penyesuaian[]}], jumlah, total_terbit, hash}` tanpa menulis.
- `POST /v6/bsa/penerbitan` `{periode, jatuh_tempo, unit?, pratinjau_hash}` →
  `201 {id, jumlah_terbit}`; data berubah sejak pratinjau → `409`; tidak ada
  tagihan baru → `422`. `GET /v6/bsa/penerbitan?page=`, `GET
  /v6/bsa/penerbitan/{id}` (petugas sesuai cakupan, admin).
- `GET /v6/bsa/tagihan/{id}` kini juga memuat `penyesuaian[]`, `pembayaran[]`,
  `menunggu`, dan `riwayat[]` (riwayat dan alasan tidak dikirim ke orang tua).
- `POST /v6/bsa/tagihan/{id}/revisi` `{versi, jenis?, nama?, nominal?,
  jatuh_tempo?, alasan}` → `200 {versi}`; `POST /v6/bsa/tagihan/{id}/batal`
  `{versi, alasan}` (hanya tanpa pembayaran). Versi basi → `409`.
- `POST /v6/bsa/tagihan/{id}/pembayaran` `{nominal, sumber: Tunai|Transfer,
  tanggal_transaksi, referensi (wajib transfer), catatan?, terverifikasi?}` →
  `201 {id, nomor_kuitansi, status_pembayaran: Sah|Menunggu, sisa}`; melebihi
  sisa (dikurangi transfer menunggu) → `422`.
- `POST /v6/bsa/pembayaran/{id}/verifikasi` dan `POST
  /v6/bsa/pembayaran/{id}/batal` `{alasan}` (sah → dibatalkan; menunggu →
  ditolak) → `200 {nomor_kuitansi, status_pembayaran, sisa}`.
  Pada replay pembayaran/verifikasi/pembatalan, ID dan nomor kuitansi tetap;
  `status_pembayaran` dan `sisa` menunjukkan keadaan saat permintaan ulang.
  Angka historis kuitansi tetap berada di `kuitansi.sisa_sesudah`.
- `GET /v6/bsa/pembayaran?periode=&status=&sumber=&q=&page=` dan `GET
  /v6/bsa/pembayaran/{id}` (data kuitansi berupa snapshot saat sah). Orang
  tua: hanya anaknya, tanpa transfer menunggu, referensi, catatan, alasan.
- `GET /v6/bsa/tunggakan?periode=&unit=&lewat=1&q=&page=` → tagihan terbit
  bersisa + `ringkasan{jumlah, sisa, santri}` sesuai hak.

Rincian, penafsiran, dan batas uji: `docs/phase-v6-2/README.md`.

### Tabungan lengkap dan pembayaran silang V6 Fase 3 (25 Oktober 2026)

Aditif; aktif bila migrasi 029 terpasang (sebelum itu/sesudah rollback,
fitur di bawah menjawab `503` dan setoran/penarikan berperilaku Fase 1–2).
Hak sama dengan Fase 1–2: petugas memutasi santri aktif dalam cakupannya,
admin dan orang tua hanya membaca, di luar hak `404`. Setiap mutasi memakai
`Idempotency-Key` per akun (kunci + isi sama → hasil sama `200` dengan
`replayed: true`; isi beda → `409`).

- `POST /v6/bsa/santri/{id}/setoran` `{nominal, metode: Tunai|Transfer,
  referensi?, catatan?, terverifikasi?}`: tunai, atau transfer dengan
  `terverifikasi: true` (petugas menyatakan sudah memeriksa dana masuk) →
  `201 {status_setoran: "Sah", transaksi_id, nomor_kuitansi, saldo_sesudah}`.
  Transfer tanpa pernyataan itu → **`202`** `{status_setoran: "Menunggu",
  setoran_transfer_id, transaksi_id: null, saldo_sesudah}` (saldo efektif
  tidak berubah). **Perubahan perilaku dari Fase 1–2**: transfer tanpa
  `terverifikasi` dulu langsung sah.
- `GET /v6/bsa/setoran-transfer?status=Menunggu|Sah|Ditolak|semua&q=&page=`
  (petugas, admin); `POST /v6/bsa/setoran-transfer/{id}/verifikasi` → `200`
  (baris ledger sah disisipkan saat itu, saldo naik sekali) dan `POST
  /v6/bsa/setoran-transfer/{id}/tolak` `{alasan}` → `200`; keputusan kedua
  → `409`.
- `POST /v6/bsa/santri/{id}/penarikan` kini menerima `jenis:
  Penarikan|Belanja` (bawaan Penarikan); respons menambah `jenis`,
  `transaksi_id`, `nomor_kuitansi`.
- `GET /v6/bsa/santri/{id}` kini menambah `setoran_transfer[]` dan
  `saldo_menunggu` (orang tua: daftar kosong/0); baris `tabungan.rows[]`
  menambah `nomor_kuitansi`, `penyetuju`, `pembalik_dari`,
  `dikoreksi_oleh_id`, `pembayaran {id, nomor_kuitansi, tagihan_id, periode,
  status}` (referensi silang), dan `alasan` (bukan untuk orang tua).
  `saldo_menunggu` menjumlah seluruh setoran menunggu santri, termasuk yang
  berada di luar batas 50 baris `setoran_transfer[]`.
- `GET /v6/bsa/tabungan?jenis=&metode=&tanggal=&q=&page=` (petugas, admin):
  mutasi tabungan sah lintas santri. `GET /v6/bsa/tabungan/{id}` (petugas,
  admin, orang tua anaknya): satu transaksi + `saldo_sebelum` untuk
  kuitansi. `POST /v6/bsa/tabungan/{id}/koreksi` `{alasan}` → `201` baris
  `Pembalik` beralasan; asal sudah dikoreksi → `409`; asal pembalik, debit
  pembayaran tagihan (koreksi lewat pembatalan pembayaran), atau saldo tidak
  cukup → `422`.
- `POST /v6/bsa/tagihan/{id}/bayar-tabungan` `{nominal, setuju: true,
  catatan?}` → `201 {id, nomor_kuitansi, status_pembayaran, sisa,
  transaksi_id, saldo}`: debit ledger `PembayaranTagihan` dan pembayaran
  `Tabungan` dalam satu transaksi, saling merujuk. Tanpa `setuju`, melebihi
  saldo, atau melebihi sisa dikurangi transfer menunggu → `422`.
  Pembayaran `GET /v6/bsa/tagihan/{id}` dan `/v6/bsa/pembayaran/{id}` menambah
  `tabungan {transaksi_id, nomor_kuitansi, pengembalian_id}`. `POST
  /v6/bsa/pembayaran/{id}/batal` atas pembayaran `Tabungan` mengembalikan
  saldo (respons menambah `transaksi_id`, `saldo`).
  Replay pembatalan juga membawa ID pembalik yang sama serta saldo kini;
  transaksi sesudah pembatalan tidak mengubah referensi pembalik tersebut.
- `GET /v6/bsa/rekonsiliasi?tanggal=TTTT-BB-HH` (petugas cakupannya, admin;
  orang tua `403`): `kas{Tunai,Transfer}`, `penerimaan_kas_baru` (tanpa
  perpindahan internal), `tabungan`, `tagihan`, `internal{…, selisih,
  selisih_pengembalian}`, `saldo{awal, mutasi_bersih, akhir, selisih, akun,
  selisih_akun}`, `menunggu`, `rows[]` (≤500).
  Kas mengikuti waktu verifikasi/pembatalan. Dua sisi perpindahan internal
  memakai waktu debit ledger (pembayaran) atau pembalik (pengembalian),
  sehingga satu operasi yang melintasi tengah malam tetap satu hari laporan.

Rincian, penafsiran, dan batas uji: `docs/phase-v6-3/README.md`.

### Paritas aplikasi dan pemberitahuan V6 Fase 4 (25–26 Oktober 2026)

Tanpa endpoint atau migrasi baru; aplikasi Expo memakai endpoint Fase 1–3 di
atas. Perubahan aditif/penyempitan untuk orang tua:

- Pemberitahuan umum kepada wali aktif santri (InApp; Push bila sakelar
  Push menyala dan wali berperangkat), diantre di dalam transaksi mutasi yang
  sama: `v6_bsa_tagihan_terbit` (tagihan tunggal/massal), `v6_bsa_pembayaran_sah`
  (pembayaran tunai/transfer sah, verifikasi, bayar dari tabungan),
  `v6_bsa_pembayaran_batal` (pembatalan pembayaran sah), `v6_bsa_tabungan`
  (setoran/penarikan/belanja sah, verifikasi setoran, koreksi). Transfer
  menunggu/ditolak tidak diberitahukan; replay tidak mengantre ulang. Judul/isi
  generik tanpa nominal, nama, kuitansi, atau referensi. Data Push hanya
  `{tipe: bsa_tagihan|bsa_pembayaran|bsa_tabungan, tagihan_id|pembayaran_id|transaksi_id}`;
  worker memeriksa ulang keadaan sumber, santri aktif, dan relasi wali sebelum
  provider (`AKSES_BERUBAH` bila tidak lagi berhak).
- `GET /notifikasi`: `tautan` bertambah `tagihan_id`, `pembayaran_id`,
  `transaksi_id` (aditif); kategori `jenis=keuangan`. Klien wajib meminta
  `/v6/bsa/tagihan/{id}`, `/v6/bsa/pembayaran/{id}`, atau `/v6/bsa/tabungan/{id}`
  sesudah diketuk; anak lain → `404`. Tautan skema aplikasi
  `alhasanapps://keuangan/{tagihan|pembayaran|tabungan}/{id}` hanya penunjuk.
- Orang tua: `GET /v6/bsa/pembayaran` kini menyaring transfer menunggu di SQL
  (`total` dan halaman tidak lagi menghitungnya); `GET /v6/bsa/tunggakan`
  mengembalikan `menunggu_verifikasi: 0` bagi orang tua.

Rincian, penafsiran, dan batas uji: `docs/phase-v6-4/README.md`.

### Laporan dan ekspor V6 Fase 5 (26 Oktober 2026)

Aditif; tanpa migrasi. Hak sama dengan halaman `portal/bsa_laporan.php`
(dihitung ulang setiap permintaan): petugas = santri aktif dalam cakupan unit,
admin = seluruh santri (hanya baca), orang tua = anak dengan relasi aktif;
lainnya `403`; santri/unit di luar hak `404`. Membutuhkan 027–029 (`503`
bila belum). Seluruh angka satu laporan dibaca dalam satu snapshot.

- `GET /v6/bsa/laporan?dari=TTTT-BB&sampai=TTTT-BB&santri=&unit=&q=&page=`
  (bawaan bulan ini; paling panjang 12 bulan; tidak di masa depan → `422`):
  `rentang`, `saring`, `santri` (bila disaring), `tagihan{terbit, dibatalkan,
  terbayar, sisa, lunas, belum_lunas, lewat_jatuh_tempo, pembayaran_sah{Tunai,
  Transfer, Tabungan}, pembayaran_dibatalkan, menunggu_verifikasi}` menurut
  periode tagihan; `tabungan{saldo_awal, setoran, penarikan, belanja,
  pembayaran_tagihan, koreksi_masuk, koreksi_keluar, pengembalian,
  mutasi_bersih, saldo_akhir, setoran_menunggu}` menurut waktu transaksi sah;
  `kas{Tunai,Transfer}` dan `penerimaan_kas_baru` (setoran + pembayaran tagihan
  tunai/transfer; pembayaran dari tabungan TIDAK termasuk); `internal`;
  `pembatalan`; `koreksi{rows[≤200]{jenis, aktor, alasan, rujukan, …}}`;
  `per_bulan[]`; `per_santri{rows, total, page, per_page, jumlah}`;
  `nonaktif` (admin saja: santri nonaktif bersaldo/bertunggakan, R-10);
  `selisih{tagihan_terbayar, tagihan_baris, saldo, internal, pengembalian,
  santri_saldo, santri_akun, nol}`. Orang tua: `kas`, `penerimaan_kas_baru`,
  `internal`, `koreksi`, `selisih`, `nonaktif`, angka menunggu, dan penolakan
  transfer bernilai `null`; baris per anak tanpa `selisih_*`.
- `GET /v6/bsa/laporan/ekspor?jenis=santri|transaksi|koreksi&dari=&sampai=&santri=&unit=`
  → `text/csv` (UTF-8 BOM, lampiran `keuangan-{jenis}-{periode}.csv`,
  `nosniff`). Rincian transaksi tanpa referensi, catatan, atau alasan; orang
  tua tidak mendapat `koreksi` (`403`). Lebih dari 10.000 baris → `422`
  (persempit periode). Setiap ekspor dicatat `audit_logs`
  `bsa.laporan.ekspor` (jenis, periode, sumber hak, kanal; tanpa nominal).

Rincian, penafsiran, dan batas uji: `docs/phase-v6-5/README.md`.

## Pengajuan izin wali V7 Fase 1 (27 Oktober 2026)

Aditif di bawah `/api/v1/v7/izin`; kontrak perizinan V2 di atas tidak berubah
bentuknya. Hak dihitung ulang server pada setiap permintaan oleh layanan yang
sama dengan website (`IzinWaliService`). Seluruh respons `Cache-Control:
private, no-store`. Pengajuan wali tinggal di ruang ID `izin_pengajuan` yang
sama dengan jalur pengurus (satu identitas kanonis). Selama berada di tahap
pembimbing (`Menunggu Pembimbing`, `Perlu Penetapan Pembimbing`; `Perlu
Perbaikan Wali` dan `Ditolak Pembimbing` disiapkan untuk Fase 2) pengajuan
**tidak** muncul pada endpoint `/izin/*` V2 (daftar, antrean, ringkasan,
detail, laporan) dan murobi tidak menerimanya. Di luar hak → `404`; peran
tanpa akses → `403`; skema 030 belum terpasang → `503 SERVICE_UNAVAILABLE`.

- `GET /v7/izin/akses` → `{peran[], wali, pembimbing, admin, boleh_mengajukan,
  antrean_pembimbing, antrean_admin}`.
- `GET /v7/izin/wali/anak` (orang tua) → `{items:[{id, nis, nama, hubungan}]}`
  — anak berelasi `santri_wali` aktif dan santri aktif.
- `POST /v7/izin/wali/pengajuan` (orang tua) dengan header `Idempotency-Key`
  (atau `idempotency_key` di badan) dan badan `{santri_id, tgl_izin, tgl_kembali,
  alasan (3–2000), catatan? (≤1000)}` → `201 {id, status, tahap, version,
  pembimbing_ditetapkan, idempotent_replay:false}`; kunci + isi sama → `200`
  dengan `idempotent_replay:true` dan ID sama; kunci sama isi berbeda → `409`;
  rentang bertumpang tindih dengan pengajuan aktif jalur mana pun → `409`;
  anak tidak berelasi aktif → `404`; bukan akun orang tua → `403`.
- `GET /v7/izin/wali/pengajuan?santri_id=&page=` (orang tua) → pengajuan wali
  untuk anak berelasi aktif. Wali pengaju: `alasan`, `catatan`, `version`,
  `pembimbing`; wali lain: `ringkas:true` tanpa alasan/catatan/riwayat.
- `GET /v7/izin/pembimbing/antrean?page=` (pengurus) → pengajuan yang
  dialamatkan kepadanya, `Menunggu Pembimbing`, santri aktif, dan ia masih
  pembimbing sah; isi lengkap untuk ditinjau (tindakan tinjauan: Fase 2).
- `GET /v7/izin/admin/pengajuan?antrean=1&status=&page=` (admin) → seluruh
  pengajuan wali; `antrean=1` = pengecualian (tanpa tepat satu pembimbing sah,
  atau pembimbing tujuan tidak lagi sah). Menyertakan `ringkasan` per status.
- `GET /v7/izin/pengajuan/{id}` → rincian sesuai hak (`peran_akses`: admin,
  pembimbing, wali); wali lain ringkas tanpa riwayat.
- `GET /v7/izin/pengajuan/{id}/kandidat-pembimbing` (admin) → pembimbing sah
  `{pengurus_id, nama, assignment_id, targets[]}`.
- `POST /v7/izin/pengajuan/{id}/penetapan-pembimbing` (admin) dengan
  `Idempotency-Key` dan badan `{pengurus_id, alasan (3–1000), version}` →
  `200 {id, status:"Menunggu Pembimbing", tahap, pembimbing_pengurus_id,
  version}`; bukan pembimbing sah → `422`; versi lama → `409`; status di luar
  tahap pembimbing → `409`; non-admin → `403`.

Rincian, penafsiran, dan batas uji: `docs/phase-v7-1/README.md`.

### V7 Fase 2 — tinjauan pembimbing, perbaikan, pembatalan (27 Oktober 2026)

Aditif; endpoint Fase 1 di atas tetap. Setiap mutasi wajib `Idempotency-Key`
(atau `idempotency_key` di badan) dan `version` yang sedang berlaku; kunci +
isi sama → `200` dengan `idempotent_replay:true`; kunci sama isi berbeda atau
versi lama → `409` tanpa perubahan. Skema 031 belum terpasang → `503`.

- `POST /v7/izin/pengajuan/{id}/tinjauan` (pembimbing tujuan yang masih sah)
  dengan `{tindakan: minta_perbaikan|tolak|teruskan, catatan, version}` —
  catatan wajib 3–2000 untuk `minta_perbaikan`/`tolak`, opsional ≤1000 untuk
  `teruskan` → `200 {id, tindakan, status, tahap, version, tinjauan_id}`;
  `teruskan` menambah `murobi_guru_id` dan `perlu_penetapan_murobi`. Penolakan
  pembimbing berstatus `Ditolak Pembimbing` dan **tidak** membuat keputusan
  `/izin` V2. Penerusan menjalankan routing murobi V2 pada ID yang sama:
  satu murobi → `Diajukan`; nol/lebih → `Perlu Penetapan Admin`. Pembimbing
  lain/penugasan dicabut → `404`; bukan pengurus (termasuk admin dan wali) →
  `403`; status bukan `Menunggu Pembimbing` → `409`.
- `POST /v7/izin/pengajuan/{id}/perbaikan` (wali pengaju) dengan `{tgl_izin,
  tgl_kembali, alasan, catatan?, version}` → `200 {id, status:"Menunggu
  Pembimbing", tahap, version, perbaikan_id}`; hanya saat `Perlu Perbaikan
  Wali` (lainnya `409`); isi tidak berubah → `422`; rentang baru berbenturan →
  `409`; wali lain berelasi → `403`; tanpa relasi → `404`.
- `POST /v7/izin/pengajuan/{id}/pembatalan` (wali pengaju) dengan `{alasan
  (3–1000), version}` → `200 {id, status:"Dibatalkan", tahap, version}` pada
  tahap mana pun sebelum keputusan murobi/admin pengganti (termasuk
  `Diajukan`/`Perlu Penetapan Admin`); sesudah keputusan atau penolakan
  pembimbing → `409`. Pembatalan V2 `/izin/pengajuan/{id}/pembatalan` atas
  pengajuan wali → `403`.
- `GET /v7/izin/pembimbing/riwayat?page=` (pengurus) → pengajuan yang sudah
  keluar dari antrean pembimbing beserta status terkini.
- `GET /v7/izin/pengajuan/{id}` kini juga untuk murobi tujuan sesudah
  diteruskan (`peran_akses: murobi`) dan memuat `tinjauan`, `perbaikan`
  (versi isi sebelum/sesudah), `keputusan` (murobi/admin pengganti), serta
  `tindakan` per peran: pembimbing `{minta_perbaikan, tolak, teruskan}`, wali
  pengaju `{perbaiki, batalkan}`, murobi `{putuskan, buka_v2}`, admin
  `{tetapkan_pembimbing, tetapkan_murobi, putuskan_pengganti, buka_v2}`. Wali
  lain tetap ringkas tanpa tinjauan/keputusan/tindakan.
- Penetapan murobi, keputusan murobi/admin pengganti, dan koreksi atas
  pengajuan wali yang sudah diteruskan memakai endpoint V2
  `/izin/pengajuan/{id}/penetapan-murobi|keputusan|koreksi`. Khusus baris
  asal wali, ketiganya wajib `version` positif (`422` bila tidak ada), versi
  ikut sidik idempotensi (kunci sama dengan versi berbeda → `409`), dan hak
  pemroses dibaca ulang setelah menunggu kunci serta sebelum replay. Kontrak
  jalur pengurus V2 tetap. Endpoint V2 orang tua (`/izin/pengajuan`, laporan) tidak memuat
  pengajuan berasal wali; orang tua membacanya lewat `/v7/izin`.

Rincian, penafsiran, dan batas uji: `docs/phase-v7-2/README.md`.

### V7 Fase 3 — notifikasi tahap dan laporan lintas asal (27 Oktober 2026)

Aditif; tanpa migrasi dan tanpa perubahan bentuk respons yang sudah ada.

- **Notifikasi** memakai `/notifikasi` V2 (kategori `jenis=perizinan`). Jenis
  peristiwa `izin.wali_masuk_pembimbing`, `izin.wali_perlu_penetapan_pembimbing`,
  `izin.wali_perlu_perbaikan`, `izin.wali_ditolak_pembimbing`,
  `izin.wali_diteruskan`, `izin.wali_masuk_murobi`,
  `izin.wali_perlu_penetapan_murobi`, `izin.wali_diputus`,
  `izin.wali_keputusan_dikoreksi`, `izin.wali_dibatalkan`. Judul/isi generik
  (hanya nomor pengajuan); `pengajuan_id` dan `santri_nama` item bernilai
  `null`; `tautan = {tipe: "izin_wali", pengajuan_id}`. Klien membuka
  `GET /v7/izin/pengajuan/{id}`, yang memeriksa ulang hak (`404` bila
  penugasan/relasi sudah dicabut). Payload Push hanya
  `{tipe: "izin_wali", pengajuan_id}`. WhatsApp tidak pernah diantrekan.
- `GET /v7/izin/laporan?peran=&asal=wali|pengurus&tahap=&hasil=&basis=izin|diajukan&dari=&sampai=&santri_id=&pembimbing_id=&murobi_id=&kamar_id=&kelas_id=&durasi_min_jam=&durasi_maks_jam=&page=`
  - Kartu, total dan daftar satu respons dibaca dalam satu snapshot konsisten.
  - Orang tua tidak menerima rerata durasi internal. Filter `pembimbing_id`,
    `murobi_id`, `durasi_min_jam`, dan `durasi_maks_jam` ditolak `422` untuk
    orang tua, termasuk pada ekspor, agar filter tidak membuka metadata yang
    disembunyikan proyeksi ringkas.
  → `{peran, peran_tersedia, cakupan_label, filter, ringkasan:{total,
  per_asal, per_tahap, per_hasil, rerata_lama_jam}, items, total, page,
  per_page}`. Cakupan dari akun: admin seluruh; pengurus = pengajuan miliknya
  + pengajuan wali yang ditujukan kepadanya sebagai pembimbing sah; murobi =
  pengajuan yang diarahkan kepadanya (status V2); orang tua = anak berelasi
  aktif (pengajuan wali lain hanya `pengaju: "Wali lain"`). `peran` hanya
  memilih peran yang dimiliki. Tanpa alasan/catatan. Tanpa peran perizinan
  `403`; saringan tidak valid `422`.
- `GET /v7/izin/laporan/ekspor` (saringan sama) → `text/csv` (paling banyak
  5.000 baris, diaudit tanpa isi).

Rincian, penafsiran, dan batas uji: `docs/phase-v7-3/README.md`.

### V7 Fase 4 — paritas aplikasi (27 Oktober 2026)

Aditif; tanpa migrasi dan tanpa endpoint baru. Aplikasi `alhasanApps` memakai
endpoint `/v7/izin` di atas dengan kunci idempotensi yang sama pada setiap
kirim ulang, dan endpoint V2 `/izin/pengajuan/{id}/keputusan|penetapan-murobi`
untuk tahap murobi.

- Item `pengajuan` pada `GET /izin/pengajuan`, `/izin/antrean`,
  `/izin/admin/monitor`, dan `GET /izin/pengajuan/{id}` bertambah satu kunci
  `asal: "Wali"|"Pengurus"` (sebelum migrasi 030 selalu `"Pengurus"`). Hak,
  cakupan, dan kunci lain tidak berubah.
- Tautan skema aplikasi `alhasanapps://izin-wali/{id}` dan notifikasi
  `{tipe:"izin_wali", pengajuan_id}` membuka rincian V7 yang memeriksa ulang
  hak; ID hanya penunjuk.

Rincian, penafsiran, dan batas uji: `docs/phase-v7-4/README.md`.

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
