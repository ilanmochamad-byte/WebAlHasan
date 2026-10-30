# Audit Codex — penugasan lintas guru/pengurus dan Keamanan, Fase 3

**31 Oktober 2026 — LULUS audit teknis lokal setelah tiga koreksi terarah.**
Tidak ada temuan Fase 3 yang masih memblokir pada lingkungan uji ini. Ini bukan
persetujuan merge, deploy, migrasi produksi, atau rilis aplikasi. Fase 4 tidak dimulai.

## Cakupan dan baseline

- Branch tetap `claude/penugasan-keamanan-fase-3`, tanpa worktree atau subagen.
- Seluruh implementasi fase: `9eb2ad1d0b0e9398ec5d5c2aacd6c2b389fd2b8e`, di atas
  audit Fase 2 `6c9d54436e276a9ef7b2e73dae339ba02244995b`.
- `git ls-remote` memverifikasi branch Fase 3 pada `9eb2ad1` dan main pada
  `d9f576abf915a69eb6190c910475d6ed7e602dee`; `git diff 6c9d544 origin/main`
  kosong. Auditor tidak berpindah ke main.
- Dibaca: `AGENTS.md`, seluruh rancangan/keputusan PK, audit Fase 2, seluruh diff
  kode dan uji Fase 3, README serta bukti handoff. Audit meliputi katalog dan
  migrasi 033, hak milik akun, tiga jalur baca, navigasi, kerahasiaan, cetak/CSV,
  penolakan mutasi, audit akses, serta pencabutan hak.
- PHP **8.4.14**, MariaDB **12.3.2**, Chromium Playwright; fixture sintetis dan DB
  lokal khusus uji. Tidak mengakses database produksi atau dump pribadi.
- Mobile dan REST tidak diubah. Empat direktori pekerjaan lain tetap di luar
  perubahan/commit: `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`,
  `output/`, dan `tests/browser/tangkapan-penugasan-keamanan-f1/`.

## Temuan dan koreksi

### F-1 · P2 — kehadiran masih membuka peserta santri nonaktif/arsip

Izin dan pembinaan membatasi Keamanan ke santri aktif, tetapi jalur kehadiran
memakai seluruh snapshot peserta tanpa memeriksa master santri kini. Pada
fixture peserta berpenanda unik, menonaktifkan atau mengarsipkan santri masih
menampilkan nama/kehadirannya pada daftar, rincian pertemuan, dan cetak.
Ini tidak sesuai PK-5, meskipun snapshot historis memang harus tetap disimpan.

**Koreksi:** guard web Keamanan memasang pembatas internal santri aktif.
`ReportFilter` membawa pembatas itu sampai pagination; repositori menerapkannya
pada satu pembangun baris laporan (daftar, angka, ringkasan jadwal, cetak), dan
pada peserta rincian pertemuan. Pembatas tidak dibaca dari parameter request.
Jalur admin, Pendidikan, guru sesuai peran, serta kontrak API lama tetap memakai
perilaku sebelumnya. Tidak ada perubahan atau penghapusan snapshot/data.

### F-2 · P2 — rincian dan cetak kehadiran Keamanan belum diaudit

Berbeda dengan rincian izin/pembinaan, pembukaan rincian pertemuan dan cetak
kehadiran tidak mencatat akses Keamanan. Trigger sintetis yang menolak INSERT
audit membuktikan keduanya tetap mengirim isi dengan status 200.

**Koreksi:** rincian menulis `keamanan.kehadiran.dilihat` dengan ID pertemuan;
cetak menulis `keamanan.kehadiran.dicetak` dengan jumlah baris. Keduanya wajib
berhasil sebelum respons isi dikirim; kegagalan menghasilkan 503. Metadata
`pertemuan_id` ditambahkan ke allowlist `AuditLogger`, tanpa nama, catatan, atau
isi kehadiran. Jalur peran lama tidak diberi kewajiban audit baru ini.

### F-3 · P3 — kegagalan audit unduhan dilaporkan sebagai 404

`keamanan_lampiran.php` menangkap semua `V3Exception` sebagai 404, termasuk
kegagalan audit 503. Isi lampiran sudah tertutup, tetapi status mengaburkan
gangguan penyimpanan audit dan tidak sesuai keterangan handoff.

**Koreksi:** 503 dipertahankan; data tidak ada/di luar cakupan tetap 404.

**Probe final yang identik** `tests/penugasan_keamanan_f3_audit_probe.php` pada
arsip Claude `9eb2ad1`: **14/12**; pada kode terkoreksi: **26/0**.
Probe memakai trigger gagal audit nyata, sesi HTTP yang sama untuk perubahan
status/pencabutan, dan oracle ID santri aktif dari master. Empat assertion
metode mencakup **56 request** (14 rute × POST/PUT/PATCH/DELETE), seluruhnya 405.
Semester nonaktif tidak menggugurkan Keamanan; tanggal penugasan berakhir
menutup seluruh rute pada sesi lama.

## Pengujian independen

Angka berarti **lulus/gagal**. Bukti auditor berada di `audit-codex/`; bukti
implementator tidak ditimpa.

| Rangkaian | Hasil | Bukti |
| --- | --- | --- |
| Statis Fase 3 dan lint | **80/0** | `regresi/statis-penugasan_keamanan_f3_static.txt` |
| Integrasi Fase 3 pada sumber akhir | **44/0** | `regresi/f3-integrasi.txt` |
| HTTP Fase 3 | **44/0** | `http.txt` |
| Migrasi–rollback 033/preservasi | **19/0** | `migrasi.txt` |
| Browser 375/768/1280px, tanpa JS 375px | **56/0** | `browser.txt`, `browser-log.txt` |
| Probe auditor: cakupan, audit gagal, metode, semester/pencabutan | **26/0**, kontrol Claude **14/12** | `probe-final.txt`, `probe-kontrol-claude.txt` |
| Matriks laporan admin/guru: layanan + layar + CSV + cetak | **432/0** | `matriks-laporan-final.txt` |

Browser memeriksa menu, saringan, rincian, tidak adanya formulir mutasi serta
overflow horizontal. Lima PNG lokal di `/private/tmp/pkf3-audit-browser/`
ditinjau visual; tidak di-commit. Log browser/probe tidak memuat galat PHP
warning/notice/fatal/deprecated atau Uncaught. Penolakan audit sengaja menulis
pesan umum `Audit log gagal disimpan.`, tanpa isi.

Suite Fase 3 menguji izin kedua asal dan seluruh tahap, rincian lengkap PK-T4,
ketiadaan kasus Rahasia pada daftar/angka/pencarian/rincian/tautan, hak milik
pengurus maupun identitas guru, guru nonaktif, penanda tanpa hak, CSV, dan
penolakan jalur tulis. Migrasi menguji kode sebelum 033/sesudah rollback,
pemasangan ulang, FK/CHECK dan penolakan rollback setelah ada riwayat penugasan.

### Regresi

`audit-codex/perintah-regresi.sh` merekam perintah, fixture, urutan, dan exit
setiap suite. Pengujian berurutan; suite V3 dengan nama DB tetap dibackup dan
dipulihkan setelah setiap suite.

- Penugasan Fase 1: integrasi **70/0**, probe **20/0**, token **10/0**, HTTP **34/0**.
- Penugasan Fase 2: integrasi **53/0**, probe riwayat/replay **24/0**, probe V4
  **9/0**, HTTP **28/0**. Probe V4 didahului integrasi pada salinan tersendiri.
- Laporan/notifikasi izin V7 Fase 3: integrasi **112/0**, probe **49/0**, HTTP **43/0**.
- Fondasi penugasan **161/0**, integrasi V2 Fase 4 **124/0**, Fase 5 **145/0**.
- Integrasi V3 Fase 2/3/4/5 dan akses: **67/0 · 121/0 · 65/0 · 43/0 · 5/0**.
- HTTP redesain Tahap 4 **132/0**, Tahap 5 **106/0**.

Tidak seluruh 43 suite non-statis handoff diulang. Auditor memilih jalur yang
disentuh dan regresi Fase 1/2 di atas; bukti handoff lebih luas tetap bukti
implementator, bukan diklaim sebagai eksekusi auditor.

### Oracle historis dan penyesuaian uji

1. Matriks lama `perapihan_audit_report_matrix.php` masih mencari caption
   `Baris kehadiran sesuai filter`, susunan kolom, dan tanggal mentah sebelum
   redesain. Hasil tanpa perubahan oracle: **188/244** pada kode terkoreksi
   **dan kode asli Claude**. Salinan baru
   `penugasan_keamanan_f3_audit_report_matrix.php` menyesuaikan parser ke caption
   `saringan`, kolom Peserta gabungan, dan tanggal tampilan Indonesia. Fixture,
   432 kombinasi, jumlah/status, oracle data, serta pemeriksaan layanan/CSV/cetak
   dipertahankan; hasil **432/0**. Suite historis aslinya tidak diubah.
2. Statis Fase 3 sebelum koreksi **73/0**. Sesudah koreksi, oracle batas berkas
   menolak perubahan pembatas/audit di `app/Report` dan dua halaman kehadiran
   (**77/2**). Daftar berkas Fase 3 diperbarui untuk lima berkas yang memang
   diperlukan F-1/F-2; larangan perubahan Auth, akun, master, kesehatan, BSA,
   notifikasi, REST, V4 dan alur tulis izin tetap. Dua berkas uji auditor dan
   lima berkas koreksi menambah lint; hasil akhir **80/0**.
3. Seluruh **48 suite statis** dijalankan: **18 exit 0, 28 exit 1, dua exit 77**,
   sama dengan log handoff. Kegagalan historis Fase 1/2 tentang
   nomor migrasi terakhir/jenis/berkas, oracle fase lama lain, dan dua skip V3
   tetap dicatat, tidak dilonggarkan. Perbandingan dengan log handoff lengkap
   ada di `audit-codex/perbandingan-statis.txt`.

### Diagnostik penyiapan

- Percobaan sandbox awal berhenti pada process substitution `/dev/fd/62`;
  penyiapan DB uji diulang dengan akses lokal alat yang disetujui.
- Satu percobaan HTTP dimulai sebelum klon tabel selesai dan berhenti pada
  penyiapan tanpa assertion. Log dipertahankan sebagai
  `diagnostik-http-sebelum-klon-selesai.txt`; pengulangan sesudah klon selesai
  menghasilkan **44/0**. Tidak diklaim sebagai kegagalan aplikasi.
- Probe awal 22 assertion menghasilkan **13/9 → 22/0**. Setelah empat
  pemeriksaan oracle angka/pagination/admin ditambahkan, **sumber probe final
  yang sama** diulang pada arsip Claude dan kode terkoreksi: **14/12 → 26/0**.
- Ringkasan awal regresi menghitung `[lulus]`/`[OK]` tetapi melewatkan `[ok]`
  penyiapan/pembersihan V2. Ringkasan diregenerasi dari log asli dengan pola
  penghitung handoff; tidak mengulang atau mengubah hasil suite.
- Auditor mengedit penghitung pada skrip orkestrator ketika proses masih berjalan.
  Seluruh 73 eksekusi (23 suite non-statis, dua setup, 48 statis) sudah menghasilkan
  log dan exit, tetapi pembacaan footer skrip kemudian gagal sintaks (orkestrator
  exit 2). Skrip final lulus `bash -n`; log/exit individual dan ringkasan awal
  dipertahankan. Ini kesalahan orkestrasi auditor, bukan kegagalan aplikasi;
  tidak mengklaim orkestrator awal selesai exit 0.

## Tinjauan PF3-1…PF3-9 dan batas

PF3-1…PF3-9 diterima dalam lingkup Fase 3: penugasan milik sendiri, mode baca
pada layanan lama, santri aktif kini, daftar izin tanpa isi/rincian lengkap,
pelanggaran tetap terlihat tanpa tautan Rahasia, cetak lewat peramban, audit
rincian/unduhan/cetak, lampiran berpenjaga, serta rollback berpenjaga riwayat.
PF3-3 diterapkan juga pada peserta kehadiran lewat koreksi F-1. Admin atau
Pendidikan memakai hak lamanya saat jalur kehadiran dibuka dalam kapasitas itu;
penugasan Keamanan tidak menghapus hak lain yang sah.

- MariaDB **10.6.28 produksi**, MySQL, cPanel/smoke hosting, UAT dan data nyata
  **belum diuji**. Migrasi 033/rollback hanya dijalankan pada DB sintetis lokal.
- Cetak diuji sebagai respons HTML dan baris cetak, termasuk 432 kombinasi
  laporan lama; PDF fisik/dialog simpan atau pemecahan halaman panjang tidak
  diverifikasi ulang dalam audit ini. Tidak mengklaim PDF fisik sudah lulus.
- Pembacaan memeriksa hak pada permintaan berikutnya; tidak ada uji balapan
  pencabutan persis di tengah streaming lampiran atau perubahan santri di antara
  beberapa SELECT rincian. Tidak ada klaim pembatalan respons yang sudah terkirim.
- Mobile/REST Keamanan tetap Fase 4; native/perangkat fisik/Push tidak diuji.
  Gerbang produksi V5/V6/V7 yang masih terbuka tidak ditutup audit ini.

Handoff: audit Fase 3 selesai; commit/push pada branch fase yang sama, lalu
berhenti. Fase 4, merge, deploy, migrasi produksi dan rilis memerlukan instruksi
Human Developer tersendiri.
