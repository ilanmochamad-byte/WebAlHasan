# Rencana — Status "Izin" otomatis pada absensi pengajian

Disusun Claude Code 23 November 2026 atas permintaan Human Developer. Dokumen ini hanya rencana; belum ada perubahan kode, migrasi, merge, deploy, atau rilis aplikasi.

## 1. Latar dan keputusan

Keputusan S (redesain web, 22 Oktober 2026) menetapkan: *santri yang sedang izin diberi label saja; status tidak diisi otomatis; label di aplikasi HP di luar cakupan.* Label "Sedang izin" kini tampil hanya di halaman pertemuan web, rincian laporan absensi admin, dan rincian kehadiran Keamanan (`IzinRepository::approvedCovering`). Absensi santri sendiri hanya dicatat dari aplikasi (keputusan R) dan draf aplikasi selalu dimulai dengan "Hadir".

**Keputusan Human Developer 23 November 2026 — revisi keputusan S (IP-1…IP-6):**

| # | Keputusan |
|---|---|
| IP-1 | Status santri yang izinnya berlaku pada tanggal pertemuan **terisi "Izin" otomatis**. Guru tetap boleh mengubahnya, misalnya menjadi "Hadir" bila santri pulang lebih cepat lalu ikut pengajian. |
| IP-2 | Berlaku **per hari penuh**: setiap pertemuan pada tanggal `tgl_izin` sampai `tgl_kembali` (inklusif). Tanpa jam; ketidaktepatan pada hari berangkat/kembali dikoreksi guru. |
| IP-3 | Keterangan dibuat umum, tanpa alasan izin: **"Izin No. 123 — disetujui murobi s.d. 25 Nov"**. |
| IP-4 | Yang dihitung hanya izin **berstatus Disetujui oleh murobi atau admin pengganti murobi** (jalur Pengurus V2 maupun Wali V7). |
| IP-5 | Status diisi **saat pertemuan dibuka** dan **saat "Perbarui daftar peserta"**. |
| IP-6 | Rekap kehadiran rapor ikut menghitung Izin (angkanya boleh berubah). **Aplikasi HP wajib menampilkan** status dan keterangan ini. |

## 2. Temuan dari kode (dasar rancangan)

- `absensi_santri` (migrasi 004) baru berisi baris ketika guru menyimpan absensi; penyimpanan aplikasi (`TeacherService::saveAttendance`) mengirim **seluruh** peserta sekaligus lalu menandai pertemuan Selesai. Kolom `dicatat_oleh` wajib akun pencatat.
- Pembukaan pertemuan web (`ScheduleService::open`) dan REST (`TeacherService::openMeeting`) sama-sama membekukan peserta lewat `App\Schedule\PesertaPertemuan::bekukan()`; "Perbarui daftar peserta" lewat `PesertaPertemuan::perbarui()`, yang mempertahankan peserta berabsensi.
- Rekap kehadiran rapor V4 (`VerifikasiService::attendance`) dan laporan menghitung dari `absensi_santri` pada pertemuan Selesai. Status yang disimpan guru sebagai "Izin" otomatis ikut terhitung; rekap tidak perlu diubah.
- `izin_keputusan.kapasitas` (`Murobi`/`Admin Pengganti`) menunjukkan siapa yang menyetujui; `tgl_izin`/`tgl_kembali` bertipe DATE.
- Aplikasi `src/app/meeting/[id].tsx`: draf `student.attendance?.status ?? 'Hadir'`, dan tombol "Semua hadir (N)" mengubah seluruh peserta menjadi Hadir.

## 3. Rancangan

### 3.1 Prinsip
"Izin" otomatis adalah **status awal (usulan) yang dibekukan pada peserta pertemuan**, bukan baris absensi yang ditulis sistem. Guru tetap satu-satunya pencatat absensi: status Izin tersimpan di `absensi_santri` ketika guru menyimpan, sehingga `dicatat_oleh`, audit, idempotensi, koreksi beralasan, dan aturan "peserta berabsensi tidak dikeluarkan" tetap seperti sekarang. Alternatif menulis langsung ke `absensi_santri` saat pembukaan **ditolak** karena memalsukan pencatat, membuat pertemuan tampak "sebagian sudah diabsen", dan mengunci peserta yang seharusnya dapat dikeluarkan saat perbarui.

### 3.2 Basis data (migrasi aditif 036)
`036_izin_otomatis_pengajian.sql` + rollback berpasangan:
- `pertemuan_peserta.izin_pengajuan_id BIGINT UNSIGNED NULL` (FK ke `izin_pengajuan.id`, indeks);
- `pertemuan_peserta.izin_keterangan VARCHAR(160) NULL` — teks IP-3 yang dibekukan.

Tanpa perubahan `absensi_santri`, `izin_*`, atau data lama; baris lama tetap NULL (pertemuan lama tidak diisi ulang). Kode wajib memeriksa skema dan berperilaku seperti sekarang sebelum migrasi/sesudah rollback. Rollback boleh melepas kolom (usulan dapat dihitung ulang; absensi tersimpan tidak tersentuh) dan melaporkan jumlah baris berusulan pada pertemuan Dibuka sebelum melepas.

### 3.3 Aturan pengisian (server, satu tempat)
Satu pembantu dipanggil di dalam transaksi `bekukan()` dan `perbarui()` (web dan REST otomatis sama):
1. Izin yang memenuhi: `izin_pengajuan.status = 'Disetujui'`, **memiliki** baris `izin_keputusan` dengan `hasil = 'Disetujui'` (kapasitas Murobi atau Admin Pengganti), `tgl_izin <= tanggal_pertemuan <= tgl_kembali`. Asal Pengurus dan Wali sama. Izin warisan tanpa baris keputusan tidak dihitung (IP-4). Bila beberapa izin tumpang tindih, pakai ID terbesar (sama dengan `approvedCovering`).
2. Keterangan: `Izin No. {id} — disetujui murobi s.d. {d M}` untuk kapasitas Murobi; `Izin No. {id} — disetujui admin pengganti s.d. {d M}` untuk Admin Pengganti (bulan singkat Indonesia: Jan, Feb, Mar, Apr, Mei, Jun, Jul, Agu, Sep, Okt, Nov, Des). Tidak memuat alasan, catatan, nama pengaju, atau keputusan.
3. Saat **dibuka**: setiap peserta yang memenuhi diberi `izin_pengajuan_id` + `izin_keterangan`.
4. Saat **perbarui** (hanya pertemuan Dibuka): peserta baru diisi seperti (3). Untuk peserta lama **yang belum berabsensi**, usulan diselaraskan ulang dengan izin yang berlaku saat ini (izin baru disetujui → diisi; izin dibatalkan/dikoreksi/ditolak → dikosongkan). Peserta yang **sudah berabsensi** tidak diubah. Pertemuan Selesai tidak pernah diubah (sejalan dengan perbaikan peserta absensi 6 November 2026).
5. Hasil perbarui (respons web/REST dan audit) menambahkan jumlah usulan izin yang ditambah/dihapus, tanpa isi izin.

### 3.4 REST API (aditif)
Setiap santri pada payload pertemuan (`meetingPayload`, termasuk respons buka, perbarui, simpan, dan rincian) memperoleh kunci aditif:
```json
"izin": { "pengajuan_id": 123, "keterangan": "Izin No. 123 — disetujui murobi s.d. 25 Nov", "sampai": "2026-11-25" }
```
atau `null`. Kunci lain, validasi `saveAttendance`, idempotensi, dan hak tidak berubah. Server **tidak** memaksa status Izin: guru boleh mengirim status apa pun (IP-1). Rincian kehadiran Keamanan dan laporan boleh menampilkan keterangan yang sama (hanya teks IP-3, aman bagi semua pembaca absensi).

### 3.5 Aplikasi (`alhasanApps`)
- Draf awal santri yang **belum berabsensi**: bila `izin` ada → status `Izin`, catatan = `izin.keterangan`; selain itu tetap `Hadir`. Santri yang sudah berabsensi selalu memakai status tersimpan.
- Baris santri menampilkan penanda "Izin s.d. 25 Nov" (ikon + teks, bukan warna saja) selama `izin` ada, juga bila guru mengubah status.
- Guru mengubah status menjadi Hadir/lainnya: catatan otomatis dikosongkan **hanya bila** masih sama persis dengan keterangan izin; catatan yang sudah diedit guru dipertahankan.
- **"Semua hadir"** tidak mengubah santri ber-`izin`; label menjadi "Semua hadir (N)" dengan N = peserta tanpa izin, dan teks penjelas menyebut jumlah santri izin yang dilewati. (Penafsiran implementator atas IP-1; Human Developer dapat mengubahnya.)
- Sesudah "Perbarui daftar", draf santri belum berabsensi mengikuti `izin` terbaru kecuali guru sudah mengubahnya secara manual pada sesi itu.
- Ringkasan mini (Hadir/Izin/…) menghitung draf seperti sekarang.
- Rincian laporan dan rincian kehadiran Keamanan menampilkan keterangan izin bila ada.
- Versi `expo.version` naik **satu minor** dari baseline (1.10.0 → 1.11.0 bila baseline tetap 1.10.0). Tanpa dependensi/modul native baru.

### 3.6 Website (hanya baca, keputusan R tetap)
Halaman pertemuan web, rincian laporan absensi admin, dan rincian kehadiran Keamanan: untuk pertemuan yang sudah berusulan tampilkan keterangan IP-3 dari snapshot peserta; label "Sedang izin" yang dihitung langsung tetap untuk pertemuan lama/belum berusulan. Tidak ada formulir absensi di website.

### 3.7 Rapor dan laporan
Tidak ada perubahan rumus. Angka Izin naik karena guru menyimpan status Izin yang sebelumnya sering tercatat Hadir/Alpa. Uji wajib membuktikan rekap V4 dan laporan absensi menghitung Izin hasil usulan yang disimpan, dan menghitung Hadir bila guru mengubahnya.

## 4. Satu tahap

Seluruh pekerjaan dikerjakan **dalam satu tahap** (web + REST + aplikasi), seperti perbaikan peserta absensi 6 November 2026, karena IP-6 mewajibkan aplikasi: tanpa aplikasi baru, server menyimpan usulan tetapi guru tidak melihatnya. Branch implementasi: `claude/izin-otomatis-absensi` di kedua repositori. Implementator Claude Code, auditor Codex.

## 5. Kriteria penerimaan

| # | Kriteria |
|---|---|
| K-1 | Membuka pertemuan (web dan REST) pada tanggal dalam rentang izin Disetujui (murobi maupun admin pengganti; asal Pengurus dan Wali) membekukan usulan + keterangan persis format IP-3; hari `tgl_izin` dan `tgl_kembali` termasuk. |
| K-2 | Izin Diajukan, Perlu Penetapan Admin, tahap pembimbing V7, Ditolak, Ditolak Pembimbing, Dibatalkan, di luar rentang, santri lain, dan izin warisan tanpa keputusan tidak menghasilkan usulan. |
| K-3 | "Perbarui daftar peserta" mengisi/mengosongkan usulan peserta belum berabsensi sesuai izin saat ini; peserta berabsensi dan pertemuan Selesai tidak berubah; satu transaksi, kunci baris pertemuan sama seperti sekarang. |
| K-4 | Payload REST memuat `izin` aditif; tidak ada alasan, catatan, keputusan, atau nama pengaju di payload, audit, maupun log. |
| K-5 | Aplikasi: draf awal Izin + keterangan, penanda terlihat, guru dapat mengubah ke Hadir dan tersimpan sebagai Hadir; "Semua hadir" melewati santri izin; ketukan ganda tetap satu penyimpanan; perlindungan dan kunci idempotensi tidak berubah. |
| K-6 | Rekap kehadiran rapor V4 dan laporan absensi menghitung status tersimpan (Izin/Hadir) dengan benar. |
| K-7 | Sebelum migrasi 036/sesudah rollback, perilaku identik dengan baseline (kontrol), dan aplikasi lama tanpa kunci `izin` tetap berfungsi seperti sekarang. |
| K-8 | Regresi kelas gabungan, perbaikan peserta absensi, pencarian santri, Keamanan, izin V2/V7, rapor V4 lulus atau identik baseline; oracle historis tidak dilonggarkan. |
| K-9 | Uji native: Android emulator dan iOS simulator membuka pertemuan berizin, mengubah satu santri ke Hadir, menyimpan, dan hasilnya terbaca di laporan. |

## 6. Batas dan risiko

- **Aplikasi lama** (≤ versi baseline) mengabaikan kunci `izin` dan tetap memulai dengan Hadir. Manfaat penuh baru terasa setelah guru memperbarui aplikasi; perlu disampaikan saat rilis.
- Izin yang disetujui sesudah pertemuan dibuka baru terisi bila guru menekan "Perbarui daftar".
- Per hari penuh (IP-2) berarti hari berangkat/kembali bisa keliru dan dikoreksi guru.
- Produksi: urutan migrasi (036 sesudah 035) dan gerbang produksi workstream lain tetap mengikuti keputusan masing-masing; MariaDB 10.6/cPanel, perangkat fisik, distribusi EAS, dan UAT belum diuji oleh rencana ini.
- Tidak merge, deploy, migrasi produksi, atau rilis aplikasi tanpa keputusan Human Developer.
