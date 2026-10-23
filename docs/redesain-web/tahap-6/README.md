# Redesain website Tahap 6 — data pesantren & penugasan (23 Oktober 2026)

Status: **diimplementasikan Claude, menunggu audit independen Codex.** Belum merge/deploy/migrasi produksi. Tahap 7 belum dimulai (syaratnya juga menunggu rapor Ganjil 2026/2027 terbit).

- Branch `claude/redesain-web-tahap-6` dibuat dari `origin/main` `c8d934b` (sudah menggabungkan audit Tahap 5 `6b04d27`). Tidak ada worktree.
- Sumber: `AGENTS.md`, `rencana.md`, `keputusan.md`, `cetak-biru.html` (D-1 s.d. D-10, M-1 s.d. M-3, G-1, baris Tahap 6 "Urutan tahap pengerjaan", temuan AB/AC/AD/AE), `tahap-5/AUDIT-CODEX.md`.
- Cakupan keputusan: **AB** (hak akses profil santri binaan), **AC** (impor diperiksa tanpa menyimpan), **AD** (database: tanda "bukan ganda"), **AE** (database: asrama kamar + peringatan penempatan), **AF** (jenis penugasan modul mendatang).
- Kriteria selesai: *migrasi kolom asrama kamar dan tabel penanda "bukan ganda" teruji dengan rollback; profil binaan menolak santri di luar cakupan.*

## Hasil terhadap kriteria

| Kriteria | Bukti |
| --- | --- |
| Migrasi asrama kamar dan tabel "bukan ganda" teruji dengan rollback | Server H6-A pada salinan DB tersendiri: migrasi tercatat; SQL dapat diulang (idempoten); batasan pasangan terurut, satu tanda aktif per pasangan, kunci asing, ENUM Putra/Putri; `bin/migrate.php rollback` melepas kolom dan tabel tanpa menyentuh baris kamar/wali/relasi/penempatan/audit (hitungan identik); sesudah rollback tujuh halaman terkait tetap 200 dan menampilkan keterangan "migrasi belum dijalankan" (tombol Bukan ganda dan isian asrama tidak tampil, kamar tetap dapat disimpan); naik lagi berhasil dan kehilangan data asrama yang didokumentasikan terbukti; pemeriksaan skema berlaku per permintaan (kamar tanpa asrama kembali ditolak). Statis S6-2 memeriksa sifat aditif dan rollback berpasangan. |
| Profil binaan menolak santri di luar cakupan | Server H6-B: matriks 15 pasangan akun–santri × 4 tab (pembimbing A/B, murobi A/C, orang tua, guru, pengurus non-pembimbing); di luar cakupan dan santri tidak ada dijawab sama (403, tanpa nama); admin dialihkan ke D-2; peran Bagian Pendidikan 403; penugasan dinonaktifkan, penugasan berakhir kemarin, atau santri dinonaktifkan → sesi yang sama langsung 403; kasus Rahasia hanya untuk pembimbing pemilik; data santri admin tetap 403 bagi pembimbing/murobi. Browser T6-E. Statis S6-3 memeriksa urutan "cakupan dulu, baru data". |

## Perubahan basis data (keputusan AD, AE)

Migrasi `database/migrations/023_redesain_web_tahap6_data_pesantren.sql` + rollback berpasangan `database/rollbacks/023_…`:

- `kamar.asrama ENUM('Putra','Putri') NULL` + indeks. **Tanpa pengisian otomatis**: kamar lama tetap "Belum ditentukan" sampai admin melengkapinya (menebak dari penghuni dapat membenarkan penempatan yang keliru).
- Tabel `wali_bukan_ganda` (pasangan terurut `wali_a_id < wali_b_id`, alasan, pelaku, waktu; pembatalan mengisi `dibatalkan_*`, baris tidak dihapus; kolom terhitung `aktif_kunci` + UNIQUE menjamin satu tanda aktif).
- Kode memeriksa keberadaan kolom/tabel (`App\Database\Schema`), sehingga tetap berjalan sebelum migrasi dan sesudah rollback.
- Rollback: kehilangan label asrama dan tanda bukan ganda didokumentasikan; jejaknya tetap di `audit_logs`.
- Skrip DB uji Tahap 2–5 yang menjalankan `bin/migrate.php up` kini ikut menerapkan 023 pada salinannya.

## Perubahan hak akses (keputusan AB)

- Halaman baru `portal/santri_binaan.php` (D-10). Penjaga: akun masuk; **peran aktif** menentukan jenis binaan (Pengurus → pembimbing, Guru → murobi); admin dialihkan ke D-2; peran lain 403.
- `App\MasterData\ProfilSantriService::cakupanBinaan()` memeriksa di server pada **setiap** permintaan: akun aktif, role dasar, data master aktif, penugasan aktif pada tanggal berjalan, tahun ajaran aktif, dan penempatan santri pada kamar/kelas binaan (aturan yang sama dengan cakupan V3). Santri nonaktif/arsip tidak termasuk.
- Hanya-baca, tab Ringkasan · Izin · Sikap · Pembinaan. Tab Pembinaan memakai layanan V3 dengan akun sendiri, jadi kerahasiaan kasus tetap. Tanpa tab Nilai & rapor dan tanpa tombol ubah; "Ajukan izin" hanya untuk akun berperan perizinan pengurus.
- Tidak ada perubahan pada `admin/_guard.php`, `app/Auth`, `Navigation`, REST, atau mobile.

## Perubahan per layar

| Layar | Berkas | Isi |
| --- | --- | --- |
| D-1 Daftar santri | `admin/admin_master_santri.php` | Chip Aktif · Nonaktif · Arsip · Semua berangka (saringan sama), cari nama/NIS, kelas, kamar (bernama), L/P. Tabel NIS · Nama · L/P · Kelas · Kamar · Status (kartu di HP, baris arsip abu-abu, tanpa kolom ID). ⋯ per baris: Ubah, Atur penempatan, Luluskan/mutasi, Nonaktifkan/Aktifkan, Arsipkan/Pulihkan (dialog). Kepala: Tambah santri + ⋯ (Impor, Ekspor CSV, Unduh templat). |
| D-2 Profil 360° | sama, `?action=detail` | Kepala kartu (avatar, nama, NIS · kelas · kamar, status, Ubah, ⋯), tab Biodata · Orang tua/wali · Kelas & kamar · Izin · Pembinaan · Nilai & rapor; tiap tab ringkas + tautan ke modul aslinya. |
| D-3 Formulir | sama, `?action=create/edit`; `admin/_santri_wali_field.php` | Bagian Identitas · Alamat · Sekolah · Orang tua/wali dengan navigasi lengket di desktop. Penggantian data lama ayah/ibu kini **dialog** yang muncul saat server mendeteksi perbedaan ("Ganti dan simpan"), bukan kotak centang di formulir; tanpa "ID" di teks wali. |
| D-4 Impor | sama, `?action=impor`; `admin/proses_import_santri.php` | Wizard Unggah berkas → Periksa data → Konfirmasi. `mode=periksa` memeriksa di server **tanpa menyimpan** (aturan formulir santri, NIS terdaftar, NIS ganda di berkas); `mode=impor` memeriksa ulang tiap baris dan menyimpan yang siap. Daftar perbaikan dapat diunduh (CSV); templat Excel dari server. |
| D-5 Penempatan | `admin/admin_penempatan_santri.php` | Ringkasan angka sebagai saringan; centang → bilah bawah (Keluarkan, Tempatkan ke kamar…, Tempatkan ke kelas…) → pilih tujuan → tinjau sebelum–sesudah → terapkan. Satu santri lewat ⋯ › Ubah penempatan (panel). Peringatan asrama per santri, "Hapus dari pilihan", konfirmasi + alasan. |
| D-6 Orang tua/wali | `admin/admin_wali.php` | Chip status berangka, panel detail (data, akun/Buat akun, santri terhubung, Arsipkan relasi, + Hubungkan santri), panel tambah/ubah; tombol "Rekonsiliasi wali" menyebut jumlah hal yang perlu ditinjau. |
| D-7 Rekonsiliasi | `admin/admin_wali_rekonsiliasi.php` | Chip Kemungkinan ganda · Relasi belum lengkap · Konflik data lama · Wali tanpa santri · Ditandai bukan ganda. Satu pasangan per layar berdampingan (nilai yang sama disorot); Bukan ganda (dialog beralasan), Lewati, Gabungkan (dialog: pilih identitas yang dipertahankan + konfirmasi); pasangan yang keduanya berakun diblokir. Daftar lain dengan "Perbaiki". |
| D-8 Kelulusan | `admin/admin_kelulusan_santri.php` | Langkah Pilih santri (centang, pengecualian dijelaskan) → Keterangan keluar → Tinjau & proses. |
| D-9 Alumni | `admin/admin_alumni.php` | 6 kolom (NIS, Nama, Status keluar, Tanggal keluar, ~Tingkat, ~Tahun); panel detail (penempatan terakhir, orang tua saat keluar, jejak proses) dan tindakan beralasan dalam dialog; "Hubungkan ke santri" kini memilih nama santri, bukan mengetik ID. |
| D-10 Profil binaan | `portal/santri_binaan.php` | Lihat "Perubahan hak akses". Dibuka dari Beranda pengurus (daftar santri binaan, "Lihat semua"), Beranda murobi, detail izin, dan detail pelanggaran. |
| M-1 Asatidz & pengurus | `admin/admin_guru.php`, `admin/admin_pengurus.php` | Tab Asatidz/Pengurus; chip status, panel tambah/ubah/detail (akun terhubung atau "Buat akun" menuju Akun dengan orang terpilih, tautan Penugasan, catatan tugas lama), ⋯ status dengan dialog seragam. Pengurus menampilkan kamar/kelas binaan; ekspor CSV pengurus baru. |
| M-2 Kelas & kobong | `admin/admin_kelas.php`, `admin/admin_kamar.php` | Kelas: chip status, anggota semester aktif, "Atur anggota" ke Penempatan; kartu "Tempatkan santri" ganda dihapus. Kamar: ringkasan (kamar, kapasitas, terisi/% hunian, penuh/hampir penuh), chip Semua · Putra · Putri · Belum ditentukan, kartu dengan bilah keterisian dan label asrama (tampilan tabel tersedia), panel penghuni (tanda tidak sesuai asrama, Tempatkan santri), formulir dengan Asrama wajib. |
| M-3 Tahun ajaran | `admin/admin_tahun.php` | Kartu Semester aktif; ⋯ Aktifkan dengan dialog yang menyebut semester yang otomatis Non-Aktif dan dampaknya; Arsipkan/Pulihkan; panel tambah/ubah. |
| G-1 Penugasan | `admin/admin_penugasan.php`, `admin/admin_murobi.php`, `admin/admin_pembimbing.php`, `app/Penugasan/PenugasanJenis.php` | Menu jenis berangka di kiri (pilihan di atas pada HP), kelompok "Disiapkan untuk modul mendatang" berketerangan (AF); penjelasan jenis; kolom "Hak yang didapat" dalam kata biasa (mis. "Memutus izin") dari kelayakan akun nyata; panel tambah/ubah/kelola; Akhiri, Nonaktifkan/Aktifkan, Arsipkan/Pulihkan dalam dialog beralasan. GET halaman Murobi/Pembimbing lama dialihkan 301; POST lamanya tetap diproses. Tab Mata pelajaran tetap tersedia di alamat yang sama (desain M-5 di Tahap 7). |

Komponen bersama baru: CSS `.ah-profile-head`, `.ah-formnav`, `.ah-compare`, `.ah-room`, `.ah-jenis`, `.ah-actbar--sticky`, `.ah-row-muted`, `.ah-col-opt`, bilah pilihan tetap tampil tanpa JavaScript, `.ah-table-wrap { position: relative }` (teks tersembunyi di kepala tabel lebar tidak lagi melebarkan halaman); JS `select[data-ah-autosubmit]`.

## Penafsiran dan batas cakupan — mohon dinilai auditor

1. **Cakupan D-10 mengikuti peran aktif (AR).** Akun pengurus+guru melihat santri binaan sesuai peran aktifnya; perpindahan peran lewat pemilih peran. Murobi memakai cakupan **penugasan** kamar/kelas (seperti V3), bukan "pernah menerima pengajuan izin".
2. **D-10 tab Izin** menampilkan rentang tanggal dan status saja, tanpa alasan dan tanpa tautan detail (detail izin menegakkan cakupannya sendiri yang bisa lebih sempit). Jumlah izin semester tidak menghitung yang Dibatalkan.
3. **D-10 tab Sikap** menampilkan status dan waktu; isian sikap tetap dibaca di halaman Sikap semester sesuai hak masing-masing.
4. **Bukan ganda per pasangan.** Kandidat lama berbentuk kelompok (nama/HP sama) dipecah menjadi pasangan wali; tanda hanya untuk pasangan yang masih kandidat. Kontrak `reconciliationReport()`/`reconciliationPage('duplikat')` lama tidak diubah. Penggabungan pasangan bertanda tetap mungkin lewat POST (keputusan admin eksplisit).
5. **Jumlah pada tombol Rekonsiliasi wali** = kemungkinan ganda (pasangan) + relasi belum lengkap + konflik data lama + wali tanpa santri.
6. **Dialog penimpaan data lama (D-3)** muncul setelah server mendeteksi perbedaan; bila ayah dan ibu sama-sama berbeda, dialog muncul berurutan (satu per hubungan) karena layanan berhenti pada perbedaan pertama.
7. **Asrama (AE):** kamar baru wajib asrama bila kolomnya ada; ubah kamar tanpa isian `asrama` (pemanggil lama) tidak mengosongkan nilainya. Kamar "Belum ditentukan" tidak memicu peringatan. "Penuh/hampir penuh" = terisi ≥ 90% kapasitas. Peringatan juga berlaku untuk jalur cepat lama `tahap=langsung` dan pindah kamar.
8. **Impor (AC):** pembacaan Excel tetap di peramban (SheetJS) seperti sebelumnya; tanpa JavaScript impor tidak tersedia (dinyatakan di halaman). Batas 1.000 baris per berkas. Jalur POST lama tanpa `mode` tetap ada dan kini sama dengan `mode=impor` (memeriksa lalu menyimpan yang siap) dengan jawaban teks lama.
9. **Asatidz "Buat akun"** menautkan ke halaman Akun & hak akses dengan orang terpilih (pra-pilih `?guru_id=`/`?pengurus_id=`/`?wali_id=`); wizard akun N-1 tidak termasuk tahap ini.
10. **G-1 "Hak yang didapat"** memakai label kata biasa per jenis, ditampilkan hanya bila penugasan Aktif dan akun memenuhi syarat (selain itu "Belum punya akun"/"Belum berlaku"); capability tetap dihitung `Capabilities`. Label kode lama `LABEL_CAPABILITY` tidak diubah.
11. **Halaman Murobi/Pembimbing lama:** GET dialihkan 301 ke jenisnya; arsip/pulihkan kini tersedia di G-1 (`statusLama()`), POST lama tetap diterima.
12. **Kelas: aksi POST lama `assign`** tetap diterima untuk kompatibilitas walau kartunya tidak lagi tampil.
13. **Ekspor CSV santri** bertambah kolom terakhir "Kamar Aktif" dan menerima saringan kamar (aditif); ekspor pengurus baru.
14. **Tautan nama santri ke D-10** di detail pelanggaran dan detail izin (staf); untuk admin halaman D-10 mengalihkan ke D-2.
15. **D-9 tanpa ringkasan angka** (cetak biru tidak menggambarnya); data warisan tanpa referensi santri tetap diperingatkan.

## Pengujian yang disesuaikan

| Uji | Perubahan (maksud uji dipertahankan) |
| --- | --- |
| `alumni_static` AS-14, AS-18 | Ringkasan angka → saringan status catatan + peringatan data warisan; "Diproses oleh" → panel "Jejak proses"; alur massal dibuka dari "Proses kelulusan" di Kelulusan & alumni. |
| `penempatan_static` PS-4, PS-12, PS-13, PS-14 | Kunci menu `santri` (peta menu Tahap 2); komponen `ah_menu(`/`ah_stats(`; bilah pilihan bersama menggantikan `data-jumlah-terpilih`; "Tinjau perubahan penempatan"; nama aksesibel kotak centang per santri; tanpa blok skrip sendiri; panel penghuni "Tempatkan santri"/"Kelola penghuni". |
| `penempatan_web_smoke` PW-2c/2e/7a/7b/10c | Judul "Data santri", remah "Data santri", "Tinjau perubahan penempatan", jumlah santri di kepala tinjauan, tautan kamar pada tampilan tabel. |
| `perapihan_static` | "Tidak ada penggabungan massal" kini juga di deskripsi D-7; tab "Orang tua/wali"; judul "Asatidz & pengurus". |
| `perapihan_audit_kamar` | Kamar baru mengirim `asrama` (wajib sesudah migrasi 023). |
| `phase2_static` | Pencarian ditulis "Cari …" (`type="search"`), detail lewat panel; impor kini `?action=impor`. |
| `penugasan_web_smoke` FW-1b…FW-8a | Judul "Penugasan"/"Mata pelajaran", menu "Penugasan", penjelasan jenis baru, hak dalam kata biasa, masa "Mulai …", pesan kosong baru, galat tanggal dibaca di panel tujuan pengalihan, judul panel ubah, halaman lama dialihkan 301 (komentar uji lama sudah menyebut pengalihan di Tahap 6 G-1). |

Baseline yang tidak berubah: `v3_phase2_static` 68/1 dan `v3_phase3_static` 102/1 (label navigasi lama sejak Tahap 2), identik dengan Tahap 5.

## Bukti

Lingkungan: PHP 8.4, Node 26, MariaDB 12 lokal, Chromium Playwright. DB: `tests/redesain_web_tahap6_db.sh` membangun salinan Tahap 5 bernama `test_redesain_t6_test`, menjalankan migrasi 023, lalu fixture sintetis `tests/redesain_web_tahap6_seed.php` (asrama kamar SBX, santri putri tanpa kamar, santri di luar cakupan, satu alumni via layanan, tiga pasangan wali kemungkinan ganda termasuk pasangan berakun).

| Bukti | Hasil | Berkas |
| --- | --- | --- |
| `php tests/redesain_web_tahap6_static.php` | **141/0** | `uji-statis.txt` |
| `php tests/redesain_web_tahap6_http.php` (server sendiri; salinan DB migrasi sendiri) | **132/0** | `uji-server.txt`, `persiapan-db.txt` |
| `node tests/browser/uji-redesain-web-tahap6.mjs` (29 layar-akun × 375/768/1280 + alur) | **115/0** | `uji-browser.txt`, `tangkapan/` |
| Regresi: statis 21 suite, V3 13 suite, integrasi/smoke master data, penugasan, perapihan, kredensial, V2 Fase 5; Tahap 2–5 HTTP + probe audit Codex; browser Tahap 1–5 dan perapihan; lint 475 berkas | lulus (kecuali dua baseline V3 di atas) | `regresi.txt` |

## Belum dijalankan / batas

- Hanya Chromium. Safari/iOS, Firefox, perangkat fisik, pembaca layar, dan axe belum dijalankan.
- Impor di browser memakai pengganti kecil pustaka SheetJS (CDN tidak tersedia luring) yang membaca CSV; pemeriksaan dan penyimpanan tetap server sungguhan. Berkas `.xlsx` nyata tidak diuji di peramban.
- `tests/browser/uji-penempatan.mjs` lama berhenti di BP-1a karena menu Penempatan bukan butir sidebar sejak peta menu Tahap 2; tidak dihitung. `uji-penugasan.mjs` lama tidak dijalankan. Empat audit perapihan yang memerlukan manifest fixture lama tidak dijalankan (juga tidak di regresi Tahap 5).
- Mobile/REST tidak diubah dan tidak diuji. Tidak ada smoke cPanel, tidak ada migrasi produksi. Kinerja pasangan kemungkinan ganda hanya diukur pada data uji (self-join pada wali aktif).

## Handoff ke auditor (Codex)

Audit Tahap 6 terhadap D-1 s.d. D-10, M-1 s.d. M-3, G-1, keputusan **AB** (hak akses — uji server wajib), **AC**, **AD** dan **AE** (database — migrasi dan rollback), AF, kriteria selesai di atas, dan butir "Penafsiran dan batas cakupan". Jalankan dari root repo:

```bash
php tests/redesain_web_tahap6_static.php
bash tests/redesain_web_tahap6_db.sh
php tests/redesain_web_tahap6_http.php
bash tests/redesain_web_tahap6_db.sh
DB_NAME=test_redesain_t6_test php -S 127.0.0.1:8981 tests/redesain_web_tahap1_router.php &
BASE_URL=http://127.0.0.1:8981 node tests/browser/uji-redesain-web-tahap6.mjs
```

Uji HTTP dan browser mengubah data uji; bangun ulang DB sebelum mengulang. Uji HTTP membuat dan menghapus salinan `test_redesain_t6_migrasi_test` untuk H6-A. Tahap 7 tidak dimulai sebelum Tahap 6 lulus audit, rapor Ganjil 2026/2027 terbit, dan ada instruksi Human Developer.
