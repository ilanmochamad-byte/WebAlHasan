# Ringkasan desain: Fondasi Penugasan V3–V6

Keputusan pengguna 7 September 2026. Branch `feat/fondasi-penugasan-v3-v6`.

## 1. Masalah yang diselesaikan

PRD V3–V6 memerlukan pihak-pihak baru yang berwenang: guru pengampu mata
pelajaran, Bagian Pendidikan, bendahara pembiayaan bulanan, panitia PSB, dan
bendahara PSB. Bila masing-masing dibuat sebagai role login, sistem akun akan
kehilangan prinsip yang sudah berlaku sejak V2 (murobi bukan role, pembimbing
bukan role), dan hak akses akan bergantung pada label, bukan pada penugasan
yang berbatas waktu dan cakupan.

Fondasi ini menjadikan **penugasan** — bukan role — sebagai sumber hak
operasional, dengan satu resolver server yang sama untuk seluruh modul
mendatang.

## 2. Hubungan akun – master – penugasan – capability

```
users ──(guru_id / pengurus_id, unik)──► guru | pengurus ──► *_assignments ──► capability
  │                                            │                  │
  │ role dasar (user_roles)                    │ aktif, tidak     │ aktif, masa berlaku,
  │ dibaca ulang tiap request                  │ diarsipkan       │ tahun ajaran, cakupan
  ▼                                            ▼                  ▼
akun aktif  ──AND──  role cocok  ──AND──  master aktif  ──AND──  penugasan sah  ⇒  capability EFEKTIF
```

Urutan pemeriksaan resolver (`App\Auth\Capabilities::featureCapabilities()`):

1. akun ada dan `is_active = 1` (bila tidak: tidak ada capability apa pun);
2. role dasar dibaca ulang dari `user_roles` — nilai `roles` yang dikirim
   pemanggil, sesi, atau klien **diabaikan**;
3. untuk setiap jenis penugasan yang role dasarnya dimiliki (murobi/guru mapel →
   `guru`; selebihnya → `pengurus`): relasi master (`users.guru_id` /
   `users.pengurus_id`) menunjuk baris aktif dan belum diarsipkan;
4. penugasan `is_active = 1` (dan `archived_at IS NULL` pada tabel lama);
5. `tanggal_mulai <= CURDATE()` dan (`tanggal_selesai IS NULL` atau
   `>= CURDATE()`);
6. tahun ajaran belum diarsipkan; untuk murobi, pembimbing, guru mapel,
   Bagian Pendidikan, dan bendahara bulanan **wajib berstatus Aktif**; untuk
   panitia/bendahara PSB tidak wajib (penerimaan berjalan sebelum tahun aktif);
7. cakupan masih sah: kelas aktif, mata pelajaran aktif (kamar cukup ada);
8. admin aktif memperoleh seluruh capability dengan `sumber = admin`
   (pengawasan); admin yang juga punya penugasan bertanda `keduanya`.

Hasilnya adalah peta `capability → {sumber, cakupan[]}` yang dipakai metode
terpusat `hasFeature`, `featureAppliesTo`, `featureAppliesToKelas`,
`featureAppliesToKamar`, `featureAppliesToMataPelajaran`,
`featureAppliesToTahunAjaran`, `featureAppliesToSemester`, dan
`featureAppliesToGelombangPsb`.

Daftar capability perizinan V2 (`forUser()`: `admin`, `pengurus`, `murobi`,
`orang_tua`) **tidak berubah** bentuk maupun maknanya; guard perizinan, aplikasi
perangkat, dan `LandingRouter` tetap memakainya.

## 3. Model data

Tabel per domain dengan kunci asing nyata (lihat `migrasi-dan-rollback.md`).
Satu tabel generik `target_type`/`target_id` sengaja **tidak** dipakai karena
tidak dapat memasang kunci asing ke `guru`, `pengurus`, `kelas`,
`mata_pelajaran`, dan `tahun_ajaran` sekaligus.

| Jenis | Tabel | Subjek | Cakupan |
| --- | --- | --- | --- |
| murobi | `murobi_assignments` (lama, 002) | guru | kamar **atau** kelas |
| pembimbing | `pembimbing_assignments` (lama, 006) | pengurus | kamar **atau** kelas |
| guru mata pelajaran | `guru_mapel_assignments` | guru | mata pelajaran + kelas |
| Bagian Pendidikan | `pendidikan_assignments` | pengurus | jenjang/unit (NULL = seluruh) |
| bendahara bulanan | `bendahara_bulanan_assignments` | pengurus | jenjang/unit (NULL = seluruh) |
| panitia PSB | `panitia_psb_assignments` | pengurus | gelombang (NULL = seluruh) |
| bendahara PSB | `bendahara_psb_assignments` | pengurus | gelombang (NULL = seluruh) |

Kolom umum setiap penugasan: subjek (FK), `tahun_ajaran_id` (FK),
cakupan, `tanggal_mulai`, `tanggal_selesai` (NULL), `is_active`, `catatan`,
`alasan_perubahan` (tabel baru), `diakhiri_pada`, `diakhiri_oleh` (FK),
`alasan_pengakhiran`, `created_by` (FK), `updated_by` (FK), `created_at`,
`updated_at`, CHECK `tanggal_selesai >= tanggal_mulai`, dan kunci unik
(subjek, tahun ajaran, kunci cakupan, tanggal mulai).

**Semester** tidak disimpan terpisah: baris `tahun_ajaran` sudah memuat
pasangan unik (tahun, semester). Resolver menyediakan
`featureAppliesToSemester(user, cap, tahun, semester)` yang memetakannya ke
`tahun_ajaran_id`.

**Master mata pelajaran.** Tabel warisan `mapel` (latin1, tanpa status/arsip,
kosong, tidak dipakai kode) tidak diubah dan tidak dihapus. Tabel
`mata_pelajaran` baru berisi kode (unik bila terisi), nama (unik), kategori,
status aktif/arsip, dan jejak pelaku — hanya yang diperlukan penugasan guru.

**Jenjang/unit** mengikuti nilai bebas `kelas.jenjang` yang sudah ada (validasi:
harus dipakai sedikitnya satu kelas yang belum diarsipkan). **Gelombang PSB**
belum punya master; disimpan sebagai label opsional yang divalidasi
(huruf/angka/tanda wajar, ≤ 50). Saat PRD V6 membuat master gelombang, migrasi
lanjutan dapat menambah `gelombang_id` dan memasangkannya dari label.

## 4. Status turunan

Status **tidak** disimpan sebagai kolom; ia diturunkan dari data agar tidak
pernah bertentangan:

| Status | Syarat |
| --- | --- |
| Dinonaktifkan | `is_active = 0` atau (tabel lama) `archived_at` terisi |
| Akan Datang | aktif dan `tanggal_mulai > hari ini` |
| Berakhir | aktif dan `tanggal_selesai < hari ini` |
| Aktif | selebihnya |

## 5. Aturan masa berlaku

- `tanggal_mulai` wajib; `tanggal_selesai` opsional (NULL = sampai diakhiri).
- `tanggal_selesai` tidak boleh mendahului `tanggal_mulai` (validasi server +
  CHECK basis data pada tabel baru).
- Capability berlaku **inklusif** sampai akhir `tanggal_selesai`.
- **Akhiri** mengisi `tanggal_selesai`, `diakhiri_pada`, `diakhiri_oleh`, dan
  `alasan_pengakhiran`; baris tetap ada. Tanggal selesai pengakhiran tidak boleh
  mendahului tanggal mulai; pengakhiran ganda pada tanggal yang sama ditolak.
- **Nonaktifkan/aktifkan** mengubah `is_active` dengan alasan wajib;
  pengaktifan kembali melewati pemeriksaan tumpang tindih yang sama dengan
  pembuatan baru.
- Penugasan yang **diarsipkan** dari halaman lama tidak dapat diubah dari pusat
  (hanya dipulihkan dari halaman lamanya) — mencegah dua jalur yang saling
  menimpa.

## 6. Aturan duplikasi dan tumpang tindih

Diperiksa di dalam transaksi setelah `SELECT … FOR UPDATE` seluruh baris milik
subjek yang sama pada tahun ajaran yang sama, sehingga dua permintaan
bersamaan diserialkan.

Dua penugasan dianggap **bentrok** bila seluruhnya terpenuhi:

1. subjek sama dan tahun ajaran sama;
2. keduanya aktif (dan tidak diarsipkan);
3. kunci cakupan sama, **atau** salah satunya adalah cakupan "seluruh" (`*`,
   yaitu jenjang/gelombang kosong) yang sudah mencakup yang lain;
4. periode beririsan: `mulai_B <= selesai_A` (selesai NULL = tak berhingga)
   dan `selesai_B >= mulai_A`.

| Contoh | Hasil |
| --- | --- |
| guru X: Fiqih–Kelas 1 (mulai 1 Sep) lalu Fiqih–Kelas 1 (mulai 5 Sep) | **ditolak** (409) |
| guru X: Fiqih–Kelas 1 dan Fiqih–Kelas 2 | diterima (cakupan berbeda) |
| guru X: Nahwu–Kelas 2 berakhir 6 Sep, lalu Nahwu–Kelas 2 mulai 7 Sep | diterima (berurutan) |
| pengurus Y: bendahara bulanan seluruh unit, lalu bendahara bulanan Tsanawi | **ditolak** (`*` mencakup Tsanawi) |
| pengurus Y: panitia PSB seluruh gelombang, lalu panitia PSB gelombang 2 | **ditolak** |
| pengurus Y: panitia PSB **dan** bendahara PSB | diterima (jenis berbeda) |
| guru X: murobi Kamar A dan murobi Kelas 1 | diterima (cakupan berbeda) |

Penugasan **identik** (subjek, tahun, cakupan, tanggal mulai sama) juga
ditangkap oleh kunci unik basis data sebagai lapisan kedua (galat 1062 → 409).

Setiap penolakan 409 dicatat pada `audit_logs` sebagai
`penugasan.tolak_tumpang_tindih`; penolakan hak (403) sebagai
`penugasan.tolak_hak`.

## 7. Audit

Seluruh mutasi ditulis di dalam transaksi yang sama dengan datanya; kegagalan
menulis audit **membatalkan** mutasi (`auditRequired`).

| Aksi audit | Entitas | Kapan |
| --- | --- | --- |
| `penugasan.buat` | `<jenis>_assignment` | pembuatan |
| `penugasan.ubah` | idem | perubahan cakupan / masa berlaku / catatan (alasan wajib) |
| `penugasan.akhiri` | idem | pengakhiran historis |
| `penugasan.nonaktifkan` / `penugasan.aktifkan` | idem | perubahan `is_active` (alasan wajib) |
| `penugasan.capability_berubah` | `user` | bila capability akun terkait bertambah/berkurang akibat mutasi di atas (memuat `bertambah`, `berkurang`, `pemicu`) |
| `penugasan.tolak_tumpang_tindih` | `<jenis>_assignment` | percobaan duplikat/tumpang tindih |
| `penugasan.tolak_hak` | idem | percobaan oleh bukan admin |
| `mata_pelajaran.buat` / `.ubah` / `.status` | `mata_pelajaran` | master minimum |

Isi audit: pelaku (`actor_user_id`), waktu, jenis, subjek, nilai sebelum/sesudah
(kolom bisnis saja), alasan, IP, dan user agent — tanpa password, token, atau
credential. Aksi audit halaman lama (`master.relation.create`,
`pembimbing_assignment_created`, `…_state_changed`) tetap seperti sebelumnya.

## 8. Keamanan

- Guard admin (`admin/_guard.php`) pada halaman + `requireAdmin()` di layanan
  (role admin dibaca dari basis data, bukan sesi).
- Seluruh mutasi POST + CSRF (`Csrf::requireValid`); GET dengan parameter aksi
  hanya merender halaman.
- Prepared statement di seluruh repository; nama tabel/kolom hanya dari katalog.
- Escape seluruh keluaran (`master_e`/`ah_e`).
- Validasi ID dan pilihan di server: subjek aktif, tahun tersedia, kelas aktif,
  kamar ada, mata pelajaran aktif, jenjang dipakai kelas, gelombang wajar,
  tanggal valid.
- Anti-IDOR: `ubah` mengunci subjek dan tahun ajaran pada nilai lama; ID dari
  jenis lain tidak ditemukan pada tabel jenis ini (404).
- Tidak ada hard delete; tidak ada menu tersembunyi sebagai keamanan; tidak ada
  credential di repositori.

## 9. Keputusan yang diambil (dan alternatif yang ditolak)

| Keputusan | Alternatif ditolak | Alasan |
| --- | --- | --- |
| Tabel per domain | satu tabel `penugasan` generik | kunci asing nyata dan kunci unik per bentuk cakupan |
| Perluas `Capabilities` dengan metode `feature*` | kelas resolver kedua | satu sistem otorisasi; `forUser()` tetap dipakai guard V2 |
| Halaman murobi/pembimbing lama tetap hidup dengan layanan lamanya | mengalihkan ke pusat | fungsi lama tetap dipakai pengujian dan admin; tidak ada perubahan mendadak |
| Kolom jejak ditambahkan ke tabel lama | tabel jejak terpisah | ubah/akhiri dari pusat memerlukan pelaku dan alasan pada baris yang sama |
| Role admin dibaca ulang di resolver | mempercayai `$user['roles']` | menutup celah manipulasi sesi/respons klien |
| Gelombang sebagai label opsional | tabel master gelombang | master belum ada; V6 yang menentukan bentuknya |
