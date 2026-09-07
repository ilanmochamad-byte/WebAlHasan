# Panduan admin: Pusat Penugasan

Keputusan pengguna 7 September 2026. Alamat: **Penugasan → Pusat Penugasan**
(`/admin/admin_penugasan.php`), hanya untuk admin.

## 1. Dua hal yang berbeda: role dasar dan penugasan

| | Role dasar | Penugasan |
| --- | --- | --- |
| Dikelola di | Akun & Hak Akses | Pusat Penugasan |
| Nilai | Admin, Guru, Pengurus, Orang Tua | murobi, pembimbing, guru mapel, Bagian Pendidikan, bendahara bulanan, panitia PSB, bendahara PSB |
| Menentukan | siapa boleh masuk dan modul dasar apa yang terbuka | tindakan operasional apa yang boleh dilakukan, pada cakupan dan masa berlaku tertentu |
| Bila dicabut | akun kehilangan modul dasarnya; riwayat penugasan **tetap** tersimpan | capability berhenti; akun dan role dasar **tidak** berubah |

Sebuah penugasan **tidak membuat akun**. Capability baru efektif bila:

1. orang itu punya akun **aktif**;
2. akun memegang role dasar yang cocok (`guru` untuk murobi/guru mapel;
   `pengurus` untuk selebihnya);
3. data guru/pengurus-nya aktif dan belum diarsipkan;
4. penugasan aktif pada tanggal berjalan (bukan Akan Datang, Berakhir, atau
   Dinonaktifkan);
5. tahun ajaran penugasan berstatus **Aktif** (kecuali panitia/bendahara PSB:
   cukup belum diarsipkan);
6. cakupannya masih sah (kelas aktif, mata pelajaran aktif).

Pusat Penugasan menampilkan kolom **Capability efektif** per baris dan
menjelaskan mengapa sebuah penugasan belum efektif (belum punya akun, akun
nonaktif, role dasar belum diberikan, tahun ajaran belum aktif).

## 2. Tab

| Tab | Untuk | Cakupan yang diminta |
| --- | --- | --- |
| Murobi | guru | kamar atau kelas |
| Pembimbing | pengurus | kamar atau kelas |
| Guru Mata Pelajaran | guru | mata pelajaran + kelas (semester mengikuti tahun ajaran) |
| Bagian Pendidikan | pengurus | unit/jenjang, boleh kosong = seluruh unit |
| Bendahara Pembiayaan Bulanan | pengurus | unit/jenjang, boleh kosong |
| Panitia PSB | pengurus | tahun ajaran **penerimaan** + gelombang, boleh kosong |
| Bendahara PSB | pengurus | tahun ajaran penerimaan + gelombang, boleh kosong |
| Mata Pelajaran | master minimum | kode (opsional), nama, kategori |

Panitia PSB **tidak** otomatis menjadi bendahara PSB, dan sebaliknya. Bila
seseorang harus memegang keduanya, buat dua penugasan.

## 3. Mencari dan menyaring

Setiap tab menyediakan pencarian nama/username/cakupan, filter tahun ajaran,
filter status (**Aktif**, **Akan Datang**, **Berakhir**, **Dinonaktifkan**), dan
filter cakupan sesuai jenis (kamar, kelas, mata pelajaran, jenjang, gelombang).
Status diturunkan dari data, bukan dipilih manual.

## 4. Membuat penugasan

1. Pilih tab jenis penugasan.
2. Isi formulir **Tambah penugasan**: orang, tahun ajaran, cakupan, tanggal
   mulai, tanggal selesai (opsional), catatan (opsional).
3. Simpan dan setujui konfirmasi.

Yang ditolak server: orang nonaktif/diarsipkan; tahun ajaran diarsipkan; kelas
nonaktif; mata pelajaran nonaktif; jenjang yang tidak dipakai kelas mana pun;
tanggal tidak valid; tanggal selesai mendahului tanggal mulai; **duplikat atau
tumpang tindih** dengan penugasan aktif orang yang sama pada tahun ajaran dan
cakupan yang sama (atau cakupan "seluruh" yang sudah mencakupnya).

Bila ditolak, isian dipertahankan dan pesan ditampilkan pada kolom terkait.

## 5. Mengubah

Klik **Ubah** pada baris → formulir berisi nilai saat ini. Yang dapat diubah:
cakupan, tanggal mulai/selesai, catatan. **Alasan perubahan wajib**. Orang dan
tahun ajaran **tidak** dapat diganti: itu berarti penugasan baru — akhiri yang
lama, lalu buat yang baru.

## 6. Mengakhiri, menonaktifkan, mengaktifkan

- **Akhiri…** — isi tanggal selesai (≥ tanggal mulai) dan alasan. Penugasan
  tetap tersimpan sebagai riwayat; capability berhenti setelah tanggal selesai
  (inklusif). Gunakan ini untuk pergantian tugas yang wajar.
- **Nonaktifkan…** — isi alasan. Capability dicabut pada pemeriksaan server
  berikutnya. Gunakan ini bila penugasan keliru atau dihentikan seketika.
- **Aktifkan…** — isi alasan. Pemeriksaan tumpang tindih dijalankan ulang.

**Tidak ada tombol hapus.** Penugasan yang pernah ada tetap ada.

Penugasan murobi/pembimbing yang **diarsipkan** dari halaman lama hanya dapat
dipulihkan dari halaman lamanya (`admin_murobi.php`, `admin_pembimbing.php`).
Kedua halaman lama tetap berfungsi seperti sebelumnya dan menaut ke pusat.

## 7. Halaman Akun & Hak Akses

Pada setiap baris akun ada ringkasan **Penugasan efektif** (jenis penugasan
yang saat ini benar-benar menghasilkan capability) dan tautan **Kelola di Pusat
Penugasan**. Akun admin ditandai "Pengawasan admin". Dropdown/tombol role dasar
**tidak** memuat penugasan.

## 8. Audit

Seluruh tindakan di atas tercatat pada `audit_logs` (aksi `penugasan.*`)
beserta pelaku, waktu, nilai sebelum/sesudah, alasan, IP, dan user agent.
Perubahan yang menambah/mengurangi capability akun dicatat terpisah sebagai
`penugasan.capability_berubah`. Percobaan duplikat/tumpang tindih dan percobaan
oleh bukan admin juga dicatat.
