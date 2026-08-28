# V2 Fase 5 — Matriks Hak Akses Laporan

Seluruh pembatasan pada dokumen ini ditegakkan **di server**, di dalam klausa
`WHERE` query laporan. Menyembunyikan tombol, kolom, atau menu tidak pernah
dianggap kontrol akses (PRD 5.2).

## 1. Dari mana cakupan berasal

Cakupan **tidak pernah** berasal dari parameter request. Ia dihitung ulang dari
akun yang sedang masuk pada **setiap** pemanggilan:

```
akun  →  Capabilities::forUser()      (dihitung dari basis data)
      →  IzinService::scopeFor()      (memilih mode dari kemampuan yang DIMILIKI)
      →  IzinReportFilter::forScope() (menolak parameter yang memperluas → 403)
      →  IzinReportRepository::scopeConditions()  (predikat SQL, tidak dapat dilewati)
```

Parameter `mode` hanya memilih di antara kemampuan yang **memang dimiliki**
akun. Mengirim `mode=admin` dari akun orang tua tidak memberi hak apa pun:
server mengabaikannya dan tetap memakai cakupan orang tua (diuji KL-3e, KR-3d).

Lapisan `forScope()` adalah lapisan **kedua**, bukan satu-satunya. Bahkan bila
pemeriksaannya dilewati, `scopeConditions()` tetap menambahkan predikat cakupan
pada setiap query, dan cakupan yang tidak dikenal menghasilkan `1 = 0` —
tidak pernah "semua baris".

## 2. Matriks cakupan

| Kemampuan | Baris yang terlihat | Predikat SQL |
| --- | --- | --- |
| `admin` | Seluruh pengajuan | `1 = 1` |
| `pengurus` | Hanya pengajuan yang ia ajukan | `p.pengurus_id = <pengurus akun>` |
| `murobi` | Hanya pengajuan yang diarahkan kepadanya | `p.murobi_guru_id = <guru akun>` |
| `orang_tua` | Hanya pengajuan santri dengan relasi wali **aktif** | `p.santri_id IN (SELECT sw.santri_id FROM santri_wali sw JOIN wali w … WHERE sw.wali_id = <wali akun> AND sw.archived_at IS NULL AND w.is_active = 1 AND w.archived_at IS NULL)` |
| tanpa kemampuan perizinan | **Tidak ada** | `403` sebelum query dijalankan |

Catatan penting untuk orang tua: relasi yang **sudah diarsipkan**
(`santri_wali.archived_at IS NOT NULL`) atau wali yang dinonaktifkan tidak
memberi akses. Ini diwarisi dari Fase 1 dan tetap berlaku pada laporan.

## 3. Matriks per permukaan

Cakupan yang sama berlaku pada **setiap** permukaan. Tidak ada permukaan yang
lebih longgar dari yang lain.

| Permukaan | Admin | Pengurus | Murobi | Orang tua | Tanpa kemampuan |
| --- | --- | --- | --- | --- | --- |
| `portal/laporan.php` | seluruh | miliknya | tujuannya | anaknya | 403 |
| `portal/laporan_cetak.php` | seluruh | miliknya | tujuannya | anaknya | 403 |
| `portal/laporan_csv.php` | seluruh | miliknya | tujuannya | anaknya | 403 |
| `GET /izin/laporan` | seluruh | miliknya | tujuannya | anaknya | 403 |
| `GET /izin/laporan/filters` | seluruh | miliknya | tujuannya | anaknya | 403 |
| `GET /izin/laporan/cetak` | seluruh | miliknya | tujuannya | anaknya | 403 |
| `GET /izin/laporan/csv` | seluruh | miliknya | tujuannya | anaknya | 403 |
| `GET /izin/laporan/explain` | seluruh | **403** | **403** | **403** | 403 |
| Layar laporan aplikasi | seluruh | miliknya | tujuannya | anaknya | tidak dapat dibuka |

`explain` dibatasi admin karena rencana eksekusi memuat nama tabel dan indeks
yang tidak perlu diketahui peran lain.

## 4. Filter yang dibatasi cakupan

| Filter | Admin | Pengurus | Murobi | Orang tua |
| --- | --- | --- | --- | --- |
| `pengurus_id` | bebas | **hanya miliknya**; nilai lain → `403` | bebas (hanya mempersempit) | bebas (hanya mempersempit) |
| `murobi_guru_id` | bebas | bebas (hanya mempersempit) | **hanya miliknya**; nilai lain → `403` | bebas (hanya mempersempit) |
| `santri_id` | bebas | dibatasi cakupan; santri lain menghasilkan **hasil kosong** | sama | sama |
| filter lainnya | bebas | bebas — seluruhnya hanya dapat MEMPERSEMPIT | sama | sama |

Perbedaan `403` dan "hasil kosong" disengaja:

- **`403`** dipakai ketika parameter secara eksplisit menyebut pemilik cakupan
  lain (`pengurus_id`/`murobi_guru_id` milik orang lain). Ini adalah percobaan
  memperluas cakupan dan pantas ditolak terang-terangan.
- **Hasil kosong** dipakai untuk `santri_id` di luar cakupan, agar keberadaan
  atau ketiadaan santri tertentu tidak dapat disimpulkan dari perbedaan kode
  status.

## 5. Daftar pilihan filter juga dibatasi

Dropdown filter (`/izin/laporan/filters`) dibangun dari query yang **sudah**
memuat predikat cakupan. Orang tua tidak menerima daftar seluruh santri
pesantren dan murobi tidak menerima daftar seluruh pengurus — membocorkan nama
lewat dropdown tetap kebocoran data.

Daftar sengaja **tidak** dipersempit oleh filter lain yang sedang aktif, agar
pengguna tetap dapat memperlebar pilihannya kembali. Cakupan sendiri tidak
pernah dilonggarkan.

## 6. Aturan otorisasi tidak diduplikasi di aplikasi

PRD Fase 5 §4 melarang aplikasi menjadikan aturan otorisasi sisi klien sebagai
satu-satunya pengaman. Yang dilakukan aplikasi:

- memanggil endpoint `/izin/laporan*` yang **sama** dengan website;
- menampilkan ringkasan, total, dan median **apa adanya dari server**, tanpa
  menghitung ulang dari `items` (yang hanya satu halaman);
- membangun pemilih cakupan dari `capabilities` yang dikirim server;
- **tidak** menyaring baris berdasarkan cakupan di sisi klien;
- **tidak** memakai nama role untuk memutuskan hak.

`tests/v2_phase5_static.php` §11 menolak pola yang melanggar ketiga larangan
terakhir.

## 7. Bukti pengujian

| Klaim | Bukti |
| --- | --- |
| Setiap peran hanya melihat cakupannya (jumlah **dan** isi baris) | KL-2a…KL-2l |
| Cakupan berlaku pada CSV dan cetak, bukan hanya daftar | KL-2j…KL-2l, WL-3d, WL-3e |
| Parameter memperluas cakupan → 403 pada seluruh permukaan | KL-3a…KL-3d, KR-3b…KR-3c, WL-6a…WL-6f |
| `mode` palsu tidak memberi hak | KL-3e, KL-3f, KR-3d, KR-3e |
| Santri di luar cakupan → hasil kosong | KL-3g |
| Akun tanpa kemampuan perizinan → 403 (bukan 500) | KR-8d, KR-8e |
| Penolakan laporan tidak mematikan kemampuan V1 yang sah | KR-8f |
| Anonim tidak dapat membuka halaman laporan mana pun | WL-1a, WL-1b |
| `explain` hanya admin | KR-8a…KR-8c |
| Isolasi terlihat pada HTML yang benar-benar dirender | WL-3b, WL-3c |
