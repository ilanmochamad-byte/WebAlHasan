# Aturan bisnis: Penempatan Kelas & Kamar Santri

Keputusan pengguna 6 September 2026. Berkas ini adalah rujukan tunggal untuk
perilaku yang boleh diandalkan; kode dan pengujian mengikutinya.

---

## 1. Tahun ajaran

- Penempatan **selalu** berlaku untuk tahun ajaran yang berstatus `Aktif` dan
  tidak diarsipkan. Tepat satu tahun boleh aktif (dijaga
  `tahun_ajaran.active_guard` + `UNIQUE tahun_single_active_unique` sejak
  migrasi 001/002).
- Bila tidak ada tahun ajaran aktif, **seluruh** penempatan ditolak dengan pesan
  yang menyuruh admin mengaktifkan semester lebih dahulu. Tidak ada penempatan
  "tanpa tahun".
- Tahun ajaran dibaca **ulang di dalam transaksi**. Semester yang berganti di
  tengah operasi tidak akan menghasilkan penempatan pada tahun yang salah.
- Penempatan tahun ajaran **sebelumnya tidak pernah** dibaca-ubah-hapus oleh
  halaman ini. Setiap pernyataan tulis kamar memuat klausa `id_tahun` tahun
  aktif (sisip, perpindahan, maupun pengeluaran), dan penulisan kelas memakai
  jalur terpusat V1 yang juga memfilter `id_tahun`.

## 2. Penempatan kelas (`plotting_kelas`)

Model riwayat V1 dipertahankan seluruhnya.

- Satu santri hanya boleh mempunyai **satu** penempatan kelas berstatus `Aktif`
  untuk satu tahun ajaran. Aturan ini ditegakkan basis data lewat kolom
  `active_year_guard` + `UNIQUE plotting_kelas_one_active_unique (id_santri, active_year_guard)`.
- **Perpindahan kelas** menyelesaikan penempatan aktif sebelumnya
  (`status='Selesai'`, `tanggal_selesai` diisi) lalu membuat baris baru
  `status='Aktif'` dengan `tanggal_mulai`. Baris lama **tidak dihapus**.
- **Mengeluarkan dari kelas** menyelesaikan penempatan aktif tanpa membuat baris
  baru. Barisnya tetap ada sebagai riwayat.
- Penulisan dilakukan lewat jalur terpusat yang sudah ada:
  `MasterDataRepository::membershipAssign()` dan `::membershipEnd()`. Paket ini
  tidak menulis SQL kelas sendiri.
- Kelas tujuan wajib **ada, `is_active = 1`, dan `archived_at IS NULL`**.
- Santri wajib aktif dan tidak diarsipkan untuk **ditempatkan**. Untuk
  **dikeluarkan**, syarat itu tidak berlaku — lihat §3 butir terakhir.
- Menempatkan ke kelas yang sedang ditempati bersifat **idempoten**: tidak ada
  baris baru, tidak ada catatan audit palsu.
- **Snapshot peserta pertemuan pengajian tidak pernah berubah.** `pertemuan_peserta`
  menyimpan `nis_snapshot`, `nama_santri_snapshot`, `kelas_id_snapshot`,
  `tahun_ajaran_id_snapshot` saat pertemuan dibuka. Paket ini tidak menyentuh
  tabel itu, dan pengujian PN-13 membuktikan isinya identik sebelum/sesudah
  santri pindah kelas.

## 3. Penempatan kamar (`plotting_kamar`)

- Satu santri hanya boleh mempunyai **satu** kamar untuk satu tahun ajaran.
  `plotting_kamar` warisan V1 belum memiliki constraint unik, sehingga aturan ini
  ditegakkan aplikasi dengan **mengunci baris santri** (`SELECT … FOR UPDATE`)
  sebelum membaca dan menulis. Dua permintaan untuk santri yang sama tidak dapat
  berjalan bersamaan.
- Menempatkan ke kamar yang sedang ditempati bersifat **idempoten**: tidak ada
  baris baru dan tidak ada audit.
- **Perpindahan kamar memperbarui baris yang ada** (`UPDATE plotting_kamar SET id_kamar = ? WHERE id = ? AND id_tahun = ?`).
  ID penempatan dipertahankan; pola hapus-lalu-sisip tidak dipakai lagi.
- **Mengeluarkan dari kamar** menghapus satu baris tahun berjalan
  (`DELETE … WHERE id = ? AND id_tahun = ?`). `plotting_kamar` tidak memiliki
  kolom status, sehingga ini satu-satunya cara mewakili "keluar" tanpa perubahan
  skema. Nilai sebelum penghapusan **selalu** tercatat pada `audit_logs`,
  sehingga tetap dapat ditelusuri. Baris tahun ajaran lain tidak tersentuh.
- Kamar tujuan wajib **ada** dan kapasitasnya bilangan bulat ≥ 1. Tabel `kamar`
  warisan V1 **tidak memiliki** kolom `is_active`/`archived_at`; "aktif" untuk
  kamar karena itu berarti barisnya ada dan kapasitasnya sah. Ini dicatat di sini
  supaya tidak terbaca sebagai pemeriksaan yang terlewat.
- Bila ditemukan **konflik data warisan** (satu santri memiliki lebih dari satu
  baris kamar pada tahun yang sama), operasi untuk santri itu **ditolak** dengan
  pesan yang menyuruh admin menjalankan `php bin/penempatan_preflight.php`.
  Sistem **tidak** memperbaiki, menggabungkan, atau menghapus data produksi
  secara otomatis. Filter kamar tetap menemukan santri seperti ini dari sisi
  kamar mana pun, sehingga konfliknya tidak tersembunyi.
- Kamar yang kapasitasnya belum diatur (`kapasitas < 1`) **tidak dapat dipilih**
  sebagai tujuan: pilihannya dinonaktifkan pada formulir dan ditolak server.
  Kamar itu tetap tampil pada filter agar penghuninya tetap dapat ditemukan.
- **Santri nonaktif atau yang sudah diarsipkan tetap dapat DIKELUARKAN dari
  kamar**, meskipun tidak dapat ditempatkan. Mengarsipkan santri tidak
  membebaskan kamarnya secara otomatis (riwayat tidak boleh berubah diam-diam),
  sehingga tanpa aturan ini tempat tidurnya akan terkunci selamanya. Halaman
  menyediakan filter **"Nonaktif/arsip tetapi masih berkamar"** dan satu kartu
  ringkasan untuk menemukannya.

## 4. Perlindungan kapasitas kamar

Urutan yang dijalankan `PenempatanService::apply()` untuk setiap operasi kamar:

1. `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` lalu `begin_transaction()`.
2. Baca tahun ajaran aktif **di dalam** transaksi.
3. **Kunci baris santri** yang terlibat, `SELECT … WHERE id IN (…) ORDER BY id FOR UPDATE`.
4. Baca penempatan kamar santri tersebut (keadaan terkini, karena READ COMMITTED).
5. **Kunci baris kamar** yang terlibat — kamar tujuan **dan** seluruh kamar asal —
   `SELECT … WHERE id IN (…) ORDER BY id FOR UPDATE`.
6. Hitung penghuni kamar tujuan **setelah** kunci diperoleh.
7. Santri yang **sudah** berada di kamar tujuan **tidak dihitung** sebagai tambahan.
8. Bila `terisi + tambahan > kapasitas`, **seluruh** operasi ditolak — tidak ada
   satu santri pun yang dipindahkan.
9. Perubahan dan seluruh catatan audit ditulis dalam transaksi yang sama.
10. `commit()` hanya setelah seluruh penempatan berhasil.

**Mengapa READ COMMITTED wajib.** Pada REPEATABLE READ (bawaan InnoDB), seluruh
pembacaan biasa memakai snapshot yang dibuat pada pembacaan pertama transaksi.
Akibatnya, setelah menunggu kunci baris kamar, perhitungan penghuni tetap membaca
keadaan lama dan kapasitas dapat terlampaui. Cacat ini nyata: pengujian
`tests/penempatan_concurrency.php` KP-2 menunjukkan lima permintaan bersamaan
sama-sama lolos ke kamar berkapasitas dua sebelum isolasi diperbaiki. Perintah
`SET TRANSACTION …` hanya berlaku untuk satu transaksi berikutnya, sehingga modul
lain tidak terpengaruh.

**Urutan penguncian.** Selalu santri (ID menaik) lalu kamar (ID menaik). Urutan
yang sama pada seluruh permintaan menghilangkan siklus tunggu, sehingga risiko
deadlock ditekan. Bila konflik kunci tetap terjadi (galat 1213/1205), seluruh
operasi di-rollback dan admin menerima pesan agar mencoba lagi — bukan galat
basis data mentah.

**Batas operasi massal:** 200 santri per operasi (`PenempatanService::BATAS_MASSAL`),
agar satu transaksi tidak menahan kunci terlalu lama.

**Prasyarat server.** READ COMMITTED menuntut `binlog_format` bernilai `ROW`
atau `MIXED`. Pada `STATEMENT`, MariaDB menolak setiap penulisan InnoDB di dalam
transaksi READ COMMITTED (galat 1665). `bin/penempatan_preflight.php` memeriksa
setelan ini sebagai bagian 0 dan menolak lolos bila nilainya `STATEMENT`; bila
tetap terjadi saat berjalan, admin menerima pesan yang menyebut setelan itu,
bukan galat mentah.

**Penerjemahan galat.** Konflik kunci dari jalur kamar (repository paket ini)
maupun jalur kelas (`MasterDataRepository`) sama-sama diterjemahkan
`PenempatanService::translateFailure()` menjadi pesan "coba lagi". Nilai balik
`SET TRANSACTION …` dan `begin_transaction()` diperiksa: bila salah satunya
gagal, operasi dibatalkan sebelum menulis apa pun, bukan berjalan diam-diam pada
isolasi yang salah atau tanpa transaksi.

## 5. Atomisitas

- Operasi massal bersifat **atomik**: seluruh santri berubah, atau tidak satu pun.
- Kegagalan pada santri terakhir **tidak** meninggalkan perubahan santri
  sebelumnya (diuji: PN-10).
- Audit ditulis di dalam transaksi yang sama. **Bila audit gagal, perubahan
  penempatan ikut di-rollback** (diuji: PN-16).

## 6. Audit

Setiap tindakan yang benar-benar mengubah data menulis satu baris `audit_logs`.

| Kolom / bidang | Isi |
|----------------|-----|
| `actor_user_id` | admin pelaku |
| `action` | `penempatan.kelas.tetapkan`, `penempatan.kelas.keluarkan`, `penempatan.kamar.tetapkan`, `penempatan.kamar.keluarkan` |
| `entity_type` | `plotting_kelas` atau `plotting_kamar` |
| `entity_id` | ID baris penempatan |
| `created_at` | waktu |
| `before_json` | santri (id, NIS, nama) + kelas/kamar **sebelumnya** |
| `after_json` | santri + kelas/kamar **baru**, `tahun_ajaran_id`, `tahun_ajaran`, `perubahan`, `mode` (`individu`/`massal`), `jumlah_santri`, `alasan`, dan `tanggal` untuk kelas |

Operasi massal yang benar-benar mengubah sesuatu menambah satu baris ringkasan
`penempatan.kelas.massal` / `penempatan.kamar.massal` berisi `aksi`, tahun ajaran,
target, `jumlah_santri`, `diterapkan`, `tidak_berubah`, daftar `santri_id`, dan
`alasan`. Bila `diterapkan` = 0, tidak ada baris ringkasan yang ditulis.

Yang **tidak** dicatat: password, token, data pribadi yang tidak diperlukan.
Perubahan yang tidak mengubah apa pun (idempoten) tidak menghasilkan baris audit.

**Alasan wajib** ketika admin mengeluarkan santri dari kelas atau kamar
(maksimal 500 karakter). Tanpa alasan, operasi ditolak sebelum menyentuh basis
data.

## 7. Keamanan

- Seluruh halaman hanya untuk admin (`admin/_guard.php` → `requireWebRole('admin')`).
- Pemeriksaan CSRF berlaku untuk setiap POST (`Csrf::requireValid` di `_guard.php`).
- **Endpoint mutasi hanya menerima POST.** `GET` dengan parameter `action`
  ditolak `405`. Alamat lama menolak POST dengan `410`.
- Tidak ada nilai GET/POST yang masuk ke SQL: seluruh query memakai prepared
  statement, dan nama tabel/kolom/urutan berasal dari konstanta repository.
- Pilihan dropdown **bukan** validasi: kelas dan kamar tujuan dibaca ulang dari
  basis data dan diperiksa kelayakannya di server (`assignableClass`,
  `assignableRoom`). Seluruh ID divalidasi sebagai bilangan bulat positif.
- IDOR dicegah dengan memvalidasi setiap entitas di server; santri yang tidak
  ada, tidak aktif, atau diarsipkan ditolak.
- Token formulir sekali pakai (`ah_form_token`) melindungi jalur **tinjau →
  terapkan**: satu tinjauan hanya dapat diterapkan sekali, sehingga refresh POST
  atau klik ganda tidak menerapkannya dua kali. Jalur cepat pada baris tabel
  (satu santri, penempatan saja) **tidak** memakai token; pengamannya adalah
  idempotensi tingkat data — mengirim ulang penempatan yang sama tidak membuat
  baris atau audit baru. Idempotensi tetap menjadi pengaman sebenarnya untuk
  kedua jalur.
- Respons galat konsisten dan tidak membocorkan query, nama kolom, atau jejak
  tumpukan. Galat basis data dicatat ke log server, bukan ke layar.
- Tidak ada keputusan keamanan yang bergantung pada JavaScript. Halaman bekerja
  penuh tanpa JavaScript; skrip di halaman hanya menghitung jumlah centang.
- Alamat lama `admin/admin_santri.php` memeriksa peran admin **secara langsung**
  dan sengaja **tidak** memeriksa CSRF: berkas itu tidak pernah mengubah data,
  dan justru klien AJAX lama yang menjadi sasaran pesan 410 adalah klien yang
  tidak mengirim token. Tanpa pengecualian ini, klien lama hanya menerima 419
  tanpa penjelasan ke mana harus pindah. Tamu tetap tidak dapat membukanya.

## 8. Perilaku pilihan lintas halaman

- Daftar memakai **pagination server**; browser tidak pernah memuat seluruh
  santri.
- Kotak centang hanya berlaku untuk baris yang **terlihat pada halaman itu**.
  "Pilih semua" mencentang baris halaman berjalan saja, dan labelnya menyatakan
  hal itu.
- Server hanya bertindak atas ID yang benar-benar dikirim. Santri pada halaman
  lain tidak pernah ikut berubah tanpa sepengetahuan admin.

## 8b. Jalur penulisan lain yang masih ada

`admin/admin_kelas.php` masih memiliki formulir lamanya, "Tempatkan Santri pada
Semester Aktif", yang menulis lewat `MasterDataService::assignActiveClass()`.
Formulir itu **sengaja tidak dihapus** — fitur lama tidak boleh hilang — tetapi
perlu diketahui bahwa ia:

- tidak mengunci baris santri (hanya membuka transaksi);
- tidak meminta alasan dan tidak mendukung tindakan massal;
- memakai aksi audit `master.relation.create`, bukan `penempatan.kelas.*`.

Bila dua jalur itu berjalan bersamaan pada santri yang sama, konflik kunci yang
muncul diterjemahkan menjadi pesan "coba lagi" oleh halaman penempatan. Halaman
Data Kelas juga menyediakan tautan menuju halaman penempatan sebagai jalur yang
dianjurkan.

## 9. Dampak pada modul lain

- **Routing murobi (`App\Izin\IzinRouter`).** Kandidat murobi dihitung dari
  `plotting_kamar` (tanpa filter status, karena tabelnya tidak punya status) dan
  `plotting_kelas` dengan `status='Aktif'`. Mengubah kamar atau kelas seorang
  santri **mengubah murobi tujuan pengajuan berikutnya** — memang begitu
  seharusnya. Paket ini tidak mengubah `IzinRouter` sama sekali; PN-14 menguji
  bahwa kandidat mengikuti penempatan aktif yang benar sebelum dan sesudah
  perpindahan.
- **Cakupan pembimbing (pengurus).** Ditentukan penugasan pembimbing terhadap
  kamar/kelas. Mengubah penempatan santri mengubah santri yang boleh dipilih
  pengurus. Tidak ada kode pembimbing yang diubah.
- **Laporan dan perizinan V2.** Membaca penempatan aktif seperti sebelumnya.
  Karena tidak ada perubahan skema, kontrak bacanya tidak berubah.
- **Absensi dan pertemuan.** Tidak tersentuh; snapshot melindungi riwayat.
