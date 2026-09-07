# Matriks role dasar, penugasan, capability, dan cakupan

Keputusan pengguna 7 September 2026. Sumber kebenaran kode:
`app/Penugasan/PenugasanJenis.php` dan `app/Auth/Capabilities.php`.

## 1. Role dasar (tidak berubah)

| Role | Syarat relasi master | Capability perizinan V2 (`forUser()`) |
| --- | --- | --- |
| `admin` | — | `admin` |
| `guru` | `users.guru_id` → guru aktif | `murobi` **hanya bila** ada `murobi_assignments` aktif pada tahun ajaran aktif |
| `pengurus` | `users.pengurus_id` → pengurus aktif | `pengurus` |
| `orang_tua` | `users.wali_id` → wali aktif | `orang_tua` |

Tidak ada role baru. `Capabilities::ALL` tetap `[admin, pengurus, murobi, orang_tua]`.

## 2. Penugasan → feature capability

| Jenis (`PenugasanJenis`) | Role dasar wajib | Tabel | Cakupan | Tahun ajaran | Capability yang dihasilkan | PRD |
| --- | --- | --- | --- | --- | --- | --- |
| `murobi` | `guru` | `murobi_assignments` | kamar **atau** kelas | wajib Aktif | `murobi.binaan` (+ `murobi` V2 seperti sebelumnya) | V2 · V3 |
| `pembimbing` | `pengurus` | `pembimbing_assignments` | kamar **atau** kelas | wajib Aktif | `pembimbing.binaan` | V2 · V3 |
| `guru_mapel` | `guru` | `guru_mapel_assignments` | mata pelajaran + kelas | wajib Aktif | `nilai.input`, `nilai.lihat_sendiri`, `nilai.koreksi_sendiri` | V4 |
| `pendidikan` | `pengurus` | `pendidikan_assignments` | jenjang/unit (NULL = seluruh) | wajib Aktif | `rapor.verifikasi`, `rapor.finalisasi`, `rapor.buka_koreksi`, `rapor.cetak` | V4 |
| `bendahara_bulanan` | `pengurus` | `bendahara_bulanan_assignments` | jenjang/unit (NULL = seluruh) | wajib Aktif | `pembiayaan_bulanan.tagihan`, `pembiayaan_bulanan.pembayaran`, `pembiayaan_bulanan.verifikasi`, `pembiayaan_bulanan.laporan` | V5 |
| `panitia_psb` | `pengurus` | `panitia_psb_assignments` | gelombang (NULL = seluruh) | belum diarsipkan (boleh belum Aktif) | `psb.pendaftar`, `psb.verifikasi`, `psb.seleksi`, `psb.penerimaan` | V6 |
| `bendahara_psb` | `pengurus` | `bendahara_psb_assignments` | gelombang (NULL = seluruh) | belum diarsipkan (boleh belum Aktif) | `psb_keuangan.tagihan`, `psb_keuangan.pembayaran`, `psb_keuangan.verifikasi`, `psb_keuangan.laporan` | V6 |

Ketentuan penamaan: `<modul>.<tindakan>`, huruf kecil, tanpa spasi. Nama-nama
di atas adalah kontrak; modul V3–V6 wajib memakainya persis.

## 3. Siapa memperoleh apa

| Situasi akun | `forUser()` | `featureList()` |
| --- | --- | --- |
| admin aktif | `admin` | seluruh 21 capability, `sumber = admin` (pengawasan) |
| admin yang juga guru dengan penugasan mapel | `admin` (+`murobi` bila ada) | seluruh capability; `nilai.*` bersumber `keduanya` dengan cakupan penugasannya |
| guru tanpa penugasan | — | — |
| guru + murobi aktif | `murobi` | `murobi.binaan` |
| guru + guru mapel aktif | — | `nilai.*` pada kelas/mapel/tahun tertentu |
| pengurus tanpa penugasan | `pengurus` | — |
| pengurus + pembimbing | `pengurus` | `pembimbing.binaan` |
| pengurus + Bagian Pendidikan | `pengurus` | `rapor.*` |
| pengurus + bendahara bulanan | `pengurus` | `pembiayaan_bulanan.*` |
| pengurus + panitia PSB | `pengurus` | `psb.*` — **bukan** `psb_keuangan.*` |
| pengurus + bendahara PSB | `pengurus` | `psb_keuangan.*` — **bukan** `psb.*` |
| pengurus + keduanya | `pengurus` | `psb.*` dan `psb_keuangan.*` |
| orang tua | `orang_tua` | — |
| akun nonaktif / master nonaktif / role dasar dicabut | — | — (riwayat penugasan tetap tersimpan) |
| penugasan belum mulai / sudah berakhir / dinonaktifkan | (murobi hilang) | — untuk penugasan itu |

## 4. Cakupan dan pemeriksaan

Setiap cakupan berbentuk
`{jenis, id, tahun_ajaran_id, kelas_id, kamar_id, mata_pelajaran_id, jenjang, gelombang, tanggal_mulai, tanggal_selesai}`.

`featureAppliesTo(user, cap, konteks)` benar bila **satu** cakupan memenuhi
seluruh kunci konteks yang diberikan:

| Kunci konteks | Cakupan berkelas | Cakupan berkamar | Cakupan berjenjang | Cakupan bergelombang / tanpa batas |
| --- | --- | --- | --- | --- |
| `kelas_id` | harus sama | **tidak** berlaku | jenjang kelas harus sama | berlaku |
| `kamar_id` | **tidak** berlaku | harus sama | **tidak** berlaku | berlaku |
| `mata_pelajaran_id` | harus sama bila cakupan punya mapel | — | — | berlaku |
| `tahun_ajaran_id` | harus sama | harus sama | harus sama | harus sama |
| `jenjang` | jenjang kelas harus sama | **tidak** berlaku | harus sama | berlaku |
| `gelombang` | — | — | — | harus sama bila cakupan punya gelombang; NULL = semua |

Admin (`sumber = admin` atau `keduanya`) selalu lolos pemeriksaan cakupan;
modul memakai `featureSource()` untuk membedakan tindakan pengawasan/pengganti
dari tindakan operasional dan mencatatnya berbeda pada audit — seperti
`Admin Pengganti` pada perizinan V2.

Metode terpusat:

| Metode | Pertanyaan |
| --- | --- |
| `featureList(user)` / `hasFeature(user, cap)` | apakah punya capability |
| `featureSource(user, cap)` | `penugasan`, `admin`, `keduanya`, atau null |
| `featureScopes(user, cap)` | daftar cakupan yang mendasari |
| `featureAppliesToKelas(user, cap, kelasId, tahunId?)` | berlaku pada kelas |
| `featureAppliesToKamar(user, cap, kamarId, tahunId?)` | berlaku pada kamar |
| `featureAppliesToMataPelajaran(user, cap, mapelId, kelasId?, tahunId?)` | berlaku pada mata pelajaran |
| `featureAppliesToTahunAjaran(user, cap, tahunId)` | berlaku pada tahun ajaran |
| `featureAppliesToSemester(user, cap, tahun, semester)` | berlaku pada tahun/semester |
| `featureAppliesToGelombangPsb(user, cap, tahunId, gelombang?)` | berlaku pada gelombang PSB |

## 5. Pusat Penugasan (web) — akses halaman

| Halaman | Admin | Guru | Murobi | Pengurus | Orang tua | Ditegakkan di |
| --- | --- | --- | --- | --- | --- | --- |
| `/admin/admin_penugasan.php` (semua tab) | ✅ | ❌ 403 | ❌ 403 | ❌ 403 | ❌ 403 | `admin/_guard.php` + `PenugasanService::requireAdmin()` |
| `/admin/admin_murobi.php`, `/admin/admin_pembimbing.php` (lama) | ✅ | ❌ 403 | ❌ 403 | ❌ 403 | ❌ 403 | `admin/_guard.php` (tidak berubah) |

Menyembunyikan menu bukan kontrol akses: menu Pusat Penugasan hanya tampil
untuk admin, dan halamannya tetap menolak siapa pun yang bukan admin.
