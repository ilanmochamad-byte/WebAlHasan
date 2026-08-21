# V2 Fase 3 — Daftar Capability dan Matriks Role/Capability

## 1. Definisi capability

Capability **selalu dihitung ulang di server** oleh `App\Auth\Capabilities`
dari basis data. Nama role saja tidak pernah cukup.

| Capability | Syarat |
| --- | --- |
| `admin` | akun aktif dan memiliki role `admin` |
| `pengurus` | role `pengurus` **dan** `users.pengurus_id` menunjuk baris `pengurus` aktif (belum diarsipkan) |
| `murobi` | role `guru` **dan** terdapat `murobi_assignments` aktif pada tahun ajaran aktif yang berlaku pada tanggal berjalan |
| `orang_tua` | role `orang_tua` **dan** `users.wali_id` menunjuk baris `wali` aktif |

Tidak ada role `murobi`. Guru tanpa penugasan murobi aktif tidak memperoleh
kemampuan keputusan apa pun. Pembimbing adalah penugasan **pengurus**
(`pembimbing_assignments`), bukan identitas guru maupun murobi.

## 2. Matriks role → capability

| Role akun | Relasi master | Capability yang diperoleh |
| --- | --- | --- |
| `admin` | — | `admin` |
| `guru` | `guru` aktif, ada `murobi_assignments` aktif | `murobi` |
| `guru` | `guru` aktif, tanpa penugasan murobi | *(tidak ada capability perizinan)* |
| `pengurus` | `pengurus` aktif | `pengurus` |
| `pengurus` | relasi nonaktif/diarsipkan | *(tidak ada; login API ditolak)* |
| `orang_tua` | `wali` aktif | `orang_tua` |
| `orang_tua` | relasi wali nonaktif | *(tidak ada; login API ditolak)* |

Satu akun dapat memiliki lebih dari satu capability (misalnya guru-murobi yang
juga admin). Akun seperti itu memakai **satu sesi** dan berpindah menu tanpa
login ulang; pemilihan cakupan dilakukan lewat parameter `mode`.

## 3. Matriks capability → kewenangan

| Kemampuan / tindakan | `admin` | `pengurus` | `murobi` | `orang_tua` |
| --- | :---: | :---: | :---: | :---: |
| Melihat daftar santri yang dapat diajukan | seluruh santri aktif | hanya cakupan pembimbing aktif | — | — |
| Membuat pengajuan | ya (diaudit) | ya, hanya dalam cakupan | — | — |
| Melihat daftar pengajuan | seluruh pengajuan | pengajuan yang ia buat | pengajuan yang diarahkan kepadanya | santri dengan relasi wali aktif |
| Antrean tindakan | `Perlu Penetapan Admin` | miliknya yang belum diputus | `Diajukan` untuknya | — |
| Melihat detail pengajuan | ya | dalam cakupan | dalam cakupan | dalam cakupan |
| Melihat riwayat & koreksi | ya | dalam cakupan | dalam cakupan | dalam cakupan |
| Menetapkan / mengganti murobi | ya (alasan wajib) | — | — | — |
| Memberi keputusan | ya sebagai `Admin Pengganti` (alasan penggantian wajib) | — | ya sebagai `Murobi` untuk pengajuan miliknya | — |
| Membatalkan pengajuan | ya | ya, hanya miliknya, sebelum keputusan | — | — |
| Mengoreksi keputusan | ya (alasan koreksi wajib) | — | — | — |
| Endpoint mutasi apa pun | ya | terbatas | terbatas | **tidak ada** |

## 4. Matriks endpoint → capability

| Endpoint | admin | pengurus | murobi | orang_tua | tanpa capability |
| --- | :---: | :---: | :---: | :---: | :---: |
| `GET /profile`, `GET /me/capabilities` | 200 | 200 | 200 | 200 | 200 (list kosong) |
| `GET /izin/santri` | 200 | 200 | 403 | 403 | 403 |
| `GET /izin/anak` | 403 | 403 | 403 | 200 | 403 |
| `GET /izin/pengajuan`, `GET /izin/antrean` | 200 | 200 | 200 | 200 | 403 |
| `GET /izin/admin/monitor` | 200 | 403 | 403 | 403 | 403 |
| `GET /izin/pengajuan/{id}` | 200 | 200 dalam cakupan | 200 dalam cakupan | 200 dalam cakupan | 403 |
| `GET /izin/pengajuan/{id}/routing` | 200 | 403 | 403 | 403 | 403 |
| `POST /izin/pengajuan` | 201 | 201 dalam cakupan | 403 | 403 | 403 |
| `POST …/penetapan-murobi` | 200 | 403 | 403 | 403 | 403 |
| `POST …/keputusan` | 201 (`Admin Pengganti`) | 403 | 201 bila tujuan; 403 bila bukan | 403 | 403 |
| `POST …/pembatalan` | 200 | 200 bila miliknya | 403 | 403 | 403 |
| `POST …/koreksi` | 200 | 403 | 403 | 403 | 403 |
| `GET /schedules*`, `GET /reports*` (V1) | 200 | 403 | 200 (karena role `guru`) | 403 | 200 bila role guru |

Catatan: akses endpoint V1 tetap ditentukan **role** `admin`/`guru` seperti pada
V1 — tidak diubah Fase 3. Endpoint perizinan V2 ditentukan **capability**.

## 5. Navigasi aplikasi

Aplikasi membangun menu dari `profile.capabilities`:

| Kondisi | Tab yang tampil |
| --- | --- |
| role `guru` atau `admin` | Beranda, Jadwal, Laporan |
| `capabilities.list` tidak kosong | + Perizinan |
| hanya `pengurus` atau `orang_tua` | Beranda + Perizinan (Jadwal & Laporan disembunyikan) |
| `capabilities.list` kosong | Beranda saja, dengan pesan bahwa akun belum memiliki kemampuan perizinan |

Di dalam tab Perizinan, akun dengan lebih dari satu capability mendapat pemilih
mode. Menyembunyikan tab atau tombol **bukan** kontrol akses: setiap endpoint
tetap memeriksa cakupan di server, dan pengujian kontrak membuktikannya dengan
memanggil endpoint lintas peran secara langsung.
