# Audit Codex — Jadwal pengajian kelas gabungan, Fase 1

Tanggal: 31 Oktober 2026 (Asia/Jakarta).

## Keputusan

**LULUS audit teknis lokal Fase 1 setelah dua koreksi dokumentasi rilis/pemulihan.** Tidak ada koreksi kode aplikasi atau SQL oleh auditor. Keputusan ini terbatas pada PHP 8.4.14 / MariaDB 12.3.2 lokal dan fixture sintetis; bukan persetujuan deploy, migrasi produksi, atau Fase 2.

## Sumber dan batas pekerjaan

- Branch yang diminta: `claude/jadwal-kelas-gabungan-fase-1`; seluruh fase terdiri dari commit `1069e215ee28a93752614bbdfaf63eb1dd43da77` di atas baseline `22a364541d54c86fb0cba4a5eee746fc24d0dca3`. Ujung remote branch dan `main` diverifikasi dengan `git ls-remote`; isi baseline sama dengan audit penugasan Fase 5 `c65ec86`.
- Membaca protokol/peran di `AGENTS.md`, keputusan produk 31 Oktober, README dan bukti implementator, skrip uji serta seluruh diff kode/migrasi fase. Handoff implementator sudah ter-commit dan ter-push; tidak menjalankan atau mengendalikan Claude. Aplikasi Claude/proses residen masih terbuka di komputer; hal itu bukan bukti adanya implementasi bersamaan. Tidak teramati perubahan sumber aplikasi dari pihak lain selama audit.
- Tidak membuat worktree atau subagen; klon detached di `/private/tmp/jkg-codex-baseline-20261031` hanya untuk kontrol baseline. Mobile dan empat direktori belum terlacak milik pekerjaan lain tidak diubah/dimasukkan commit.
- Database sumber fixture `.env` berakhiran `_test`, disalin oleh rantai fixture. Semua mutasi pengujian hanya pada salinan `test_*_test`; tidak menggunakan dump atau data produksi. Bukti auditor terpisah di [`audit-codex/`](audit-codex/).

## Temuan dan koreksi

### F-1 — P2 — Panduan menyebut pemulihan ke kode lama aman setelah jadwal gabungan ada (ditutup)

README asli menyebut kembali ke kode lama dengan 034 terpasang “aman”, sekaligus mengakui kode itu hanya membuka peserta kelas utama. Ini dapat membuat kelas tambahan tidak tercatat saat operator mengikuti panduan pemulihan.

Kontrol yang benar-benar dijalankan: buat jadwal PA+PI menggunakan kode baru pada DB 034, lalu buka tanggal pertama melalui layanan web baseline `22a3645`. Hasil: **3 peserta PA, nol PI, nol baris `pertemuan_kelas`**, meskipun `jadwal_kelas` tetap dua baris. Pembukaan tanggal berikutnya dengan kode kini pada jadwal yang sama memperoleh **5 peserta dan dua kelas beku**. Bukti [`kontrol-pemulihan.txt`](audit-codex/kontrol-pemulihan.txt), **3/0**.

Panduan kini melarang kembali ke kode lama selama ada jadwal/pertemuan gabungan. Pilihan pemulihan: pertahankan kode yang memahami 034 selama perbaikan, atau pulihkan pasangan kode dan backup database sebelum rilis dengan penulis dihentikan. Tidak ada data lama yang dibersihkan untuk melewati penjaga rollback.

### F-2 — P3 — Perintah uji migrasi pada panduan tidak sesuai prasyarat suite (ditutup)

Panduan asli menyuruh `migrate up` lalu menjalankan suite migrasi memakai `DB_NAME`. Suite tersebut memilih DB dari **`JKG_DB`**, menuntut keadaan **033 tanpa 034**, dan membutuhkan akun/fixture sintetis `sbx_*`. Perintah asli dapat memilih DB default lain serta gagal membuktikan salinan yang dimaksud.

Panduan dikoreksi: salinan produksi diuji dengan perbandingan seluruh isi/ID tabel lama sebelum/sesudah migrasi dan pemeriksaan relasi; suite fixture memakai DB terpisah, `JKG_TANPA_034=1` saat penyiapan dan `JKG_DB` saat eksekusi. Suite pada prasyarat benar dijalankan auditor dan lulus **31/0**. Suite fixture tidak dijalankan pada data produksi.

## Bukti inti yang dijalankan auditor

| Pengujian | Hasil | Bukti |
| --- | --- | --- |
| Statis Fase 1 asli, lalu final dengan lint probe auditor | 46/0 → **47/0** | `audit-codex/uji/statis.txt`, `statis-final.txt` |
| Integrasi layanan, API, laporan, rapor dan konkurensi implementator, dieksekusi ulang auditor | **71/0** | `audit-codex/uji/integrasi.txt` |
| Migrasi–rollback 034, rerun, FK/UNIQUE/CHECK dan penolakan rollback data gabungan | **31/0** | `audit-codex/uji/migrasi.txt` |
| HTTP web/REST, POST/CSRF, ekspor CSV, jadwal publik | **32/0** | `audit-codex/uji/http.txt` |
| Probe tambahan yang ditulis auditor | **166/0** | `audit-codex/probe.txt` |
| Dua urutan perubahan kelas–pembukaan dengan kunci ditahan | **7/0** | `audit-codex/kunci-terkendali.txt` |
| Kontrol kode pemulihan baseline vs kode kini | **3/0** | `audit-codex/kontrol-pemulihan.txt` |

Perubahan suite statis hanya menambahkan berkas probe auditor ke daftar lint/berkas fase yang diizinkan; tidak melonggarkan assertion bisnis atau oracle historis. Skrip [`reproduksi.sh`](audit-codex/reproduksi.sh) merangkum perintah untuk mengulang; setiap komponen di atas dieksekusi secara terpisah pada audit ini.

### Invarians jadwal, peserta, dan absensi

- Contoh **1 IBTIDA PA (3 santri aktif) + 1 IBTIDA PI (2)** menghasilkan satu pertemuan per tanggal, lima peserta unik dengan `kelas_id_snapshot` sesuai kelas asal, satu absensi guru dan lima absensi santri. Santri dengan keanggotaan Selesai dan kelas C tidak ikut.
- Pilihan kelas berulang dinormalisasi; replay pembukaan dan absensi API tidak menambah pertemuan/peserta/absensi. Absensi kurang peserta dan ID santri berulang ditolak. Menyimpan jadwal tidak membuka pertemuan otomatis.
- Dua putaran masing-masing tiga pembukaan nyata bersamaan (web+API+web) menghasilkan tepat satu sukses, dua penolakan layanan, satu pertemuan, lima peserta, dua kelas beku. Empat balapan ubah kelas–buka menghasilkan keadaan utuh; pada eksekusi auditor ini semuanya mempertahankan PA+PI, sehingga tidak diklaim telah mengamati kedua urutan dari balapan acak tersebut.
- Probe terkendali melengkapi bukti urutan: transaksi induk memegang kunci jadwal dan menjalankan bagian repositori simpan/pembukaan yang sama; proses anak menjalankan **layanan API pembukaan** atau **layanan simpan** yang sebenarnya. `PROCESSLIST` akun sendiri memperlihatkan `SELECT ... FOR UPDATE` anak ketika kunci masih ditahan. Simpan-dulu → PA+C utuh; buka-dulu → pertemuan tetap PA+C, jadwal berikutnya PA+PI. Ini bukan klaim seluruh layanan induk dieksekusi lewat HTTP pada probe kunci.
- Pindah santri PA ke PI, lalu mengganti seluruh kelas jadwal menjadi C tidak mengubah satu byte isi snapshot/absensi historis yang di-hash. Pertemuan pertama tetap PA+PI; draf tanggal berikutnya dibuka pada ID draf yang sama dan memakai dua peserta C.

### Benturan, akses, dan kontrak API

- Guru sama dan waktu beririsan **ditolak**. Irisan kelas utama/tambahan antardua jadwal serta tempat sama **diperingatkan**, mengikuti aturan V1. Kelas di dalam satu jadwal gabungan tidak dianggap benturan. Uji waktu, jadwal berbeda, dan pengecualian jadwal sendiri ada pada integrasi/HTTP.
- Guru lain ditolak pada rincian/buka pertemuan web, jadwal/rincian/buka/absensi API, dan rincian laporan; daftar laporan/ekspornya tidak mengembalikan data jadwal tersebut. Probe layanan memakai DTO guru sintetis; otentikasi/sesi/bearer aktual dibuktikan suite HTTP yang terpisah.
- `class`/`class_name` lama tetap ada dan mewakili kelas utama jadwal saat ini; `classes`/`class_names` aditif menampilkan kelas lengkap, dan `task.classes` memakai kelas beku pertemuan. Setelah utama diganti C, `task.class` tetap mengikuti perilaku kompatibilitas lama, sedangkan `task.classes` tetap PA+PI. Kelas asal setiap santri diteruskan secara terpisah.
- Penafsiran JK-1…JK-9 diterima untuk Fase 1: utama eksplisit, batas 10 kelas, keanggotaan aktif semester jadwal, pelepasan kelas arsip saat simpan ulang dengan pemberitahuan, field lama mengikuti jadwal kini, saringan santri berdasarkan kelas snapshot, bentuk CSV tetap, ringkasan satu baris per jadwal, tanda tangan repositori lama tetap didukung. Tidak ada keputusan produk baru yang ditambahkan auditor.

### Laporan, ekspor, rapor, dan preservasi

- Dua pertemuan PA+PI lalu PA+C: laporan jadwal gabungan berisi **2 guru + 10 absensi santri**, satu ringkasan jadwal. Santri per kelas PA/PI/C = **6/2/2**; jumlahnya sama dengan gabungan. Guru PI = satu pertemuan, PA = dua; tidak digandakan dalam laporan gabungan. Angka guru dari dua laporan kelas yang berbeda tidak dijumlahkan sebagai total gabungan.
- Probe auditor tambahan mencakup **12 kombinasi** kelas semua/PA/PI/C × mode guru/santri/gabungan setelah kelas utama diubah: daftar, ringkasan, ekspor, keunikan subjek dan jumlah jadwal cocok oracle fixture. CSV HTTP benar-benar diminta; kelas santri adalah kelas asal.
- Rekap rapor menghitung satu pertemuan per santri, seluruh status Hadir/Terlambat/Izin/Sakit/Alpa diuji, termasuk santri yang sudah pindah kelas. Pertemuan masih Dibuka tidak ikut hitungan rapor Selesai.
- Hash SHA-256 **seluruh kolom/baris 120 tabel lama, 9.252 baris** dibuat sebelum 034; setiap tabel cocok setelah naik, setelah rollback tunggal, dan setelah naik lagi. ID ikut isi hash; `schema_migrations` dikecualikan karena memang bertambah/berkurang. Manifest hanya berisi jumlah/hash, bukan isi baris: [`hash-tabel-lama.json`](audit-codex/hash-tabel-lama.json).
- Dua jadwal lama yang kini mempunyai kelas sama tetap dua ID dan dua relasi tunggal, tanpa penggabungan otomatis. Pertemuan lama PA tetap beku PA walaupun kelas jadwal kini PI. Rollback ditolak setelah kelas gabungan pernah dibekukan, walau jadwal sudah kembali satu kelas; tabel/isinya tetap utuh.

## Regresi dan kontrol baseline

Seluruh **14 entri non-statis dan 51 suite statis** pada `perintah-regresi.sh` selesai dijalankan auditor. Semua hitungan/exit non-statis identik dengan handoff implementator; auditor tidak mengulang seluruh regresi non-statis pada baseline, hanya dua suite HTTP yang tidak hijau.

| Regresi | Hasil auditor |
| --- | --- |
| V1 jadwal/pertemuan, API absensi, laporan/ekspor | **10/0 · 14/0 · 20/0** |
| Perapihan | **52/0** |
| Keamanan Fase 3 integrasi / HTTP / probe audit; Fase 4 HTTP | **44/0 · 44/0 · 26/0 · 66/0** |
| HTTP redesain Tahap 2 / 4 / 6 / 7 | **185/6 · 132/0 · 125/7 · 122/0** |
| Integrasi / HTTP kelas gabungan (diulang pada DB regresi baru) | **71/0 · 32/0** |

Kontrol baseline detached `22a3645` dijalankan auditor pada dua DB terpisah: Tahap 2 **185/6**, keenam teks gagal sama; Tahap 6 **125/7**, ketujuh judul gagal sama. Tahap 2 masih mengharapkan peta menu lama; Tahap 6 menganggap rollback terakhir selalu 023, padahal baseline terakhir 033 dan kode kini 034. Rincian pesan rollback memang berbeda 033/034, tidak diklaim byte-identik. Bukti: `baseline-http/` dan `perbandingan-http.txt`.

Seluruh statis kode kini: **17 exit 0, 32 exit 1, dua exit 77**. Kontrol baseline 50 statis (belum mempunyai suite kelas gabungan), dengan `MOBILE_APP_ROOT` diarahkan ke checkout mobile yang sama: **18 exit 0, 30 exit 1, dua exit 77**. Tidak ada oracle historis yang dilonggarkan. Dua suite yang beralih hijau → gagal adalah:

1. `phase3_static`: mencari literal SQL keanggotaan aktif di `ScheduleRepository`, padahal implementasinya dipusatkan ke `PesertaPertemuan`. Predikat aktif tetap ada, dan perilakunya diuji pada integrasi V1/kelas gabungan.
2. `penugasan_keamanan_f5_static`: menuntut 033 terakhir, manifest rilis 33 migrasi, serta tidak ada perubahan aplikasi/database sesudah fase lama. Tambahan 034 dan perubahan pengajian memang melanggar oracle batas fase historis tersebut.

`penugasan_keamanan_f4_static` yang sudah gagal pada baseline bertambah satu kegagalan larangan perubahan Report/admin/database. Suite V6/V7/penugasan lain mencetak daftar berkas Git yang bertambah sesuai diff kelas gabungan; judul serta jumlah gagal lainnya sama. Daftar mentah perbedaan disimpan di `perbedaan-statis.json`. Dibanding handoff pada kode implementator, satu lint probe auditor menambah satu lulus pada statis Fase 1 ini dan empat statis penugasan lama; seluruh hitungan gagal tetap sama.

Catatan kontrol: klon lokal mula-mula mempunyai `origin/main` dari branch lokal lama sumber klon. Ref kontrol diperbaiki menjadi `22a3645` dan satu-satunya statis yang memakai `origin/main` (Tahap 6) dijalankan ulang sebelum hasil baseline final dicatat. Ini bukan perubahan ref remote/repo kerja. Dua log statis penempatan hanya dinormalisasi spasi akhirnya agar pemeriksaan whitespace Git lulus; hasil assertion tidak diubah.

## Batas dan handoff

- Belum menjalankan MariaDB 10.6.28 produksi, MySQL, cPanel/hosting, salinan produksi, atau UAT. Tidak menguji CHECK yang sengaja dinonaktifkan; penjaga SQL memerlukan CHECK yang ditegakkan sebagaimana dicatat rollback. Uji konkurensi berlangsung pada satu mesin lokal.
- Tidak menguji ulang browser visual tiga lebar, PDF fisik/paginasi, aplikasi native, perangkat fisik atau Push. HTTP web/API dan CSV diuji; mobile tidak diubah.
- Preflight historis penugasan/V7 yang menganggap daftar migrasinya terakhir belum disesuaikan untuk 034 oleh fase ini; status itu tetap pekerjaan gerbang rilis, bukan bukti produksi siap. Gerbang produksi V5/V6/V7 tidak ditutup.
- Percobaan pertama skrip DB dalam sandbox gagal pada `/dev/fd`; seluruh suite inti kemudian dijalankan ulang dengan akses lokal yang sesuai. Probe kunci awal tidak memiliki hak `PROCESS` untuk `INNODB_LOCK_WAITS`; diagnostiknya dipertahankan, lalu probe diperbaiki untuk mengamati sesi akun sendiri melalui `PROCESSLIST`, tanpa mengubah hak DB. Koneksi socket root juga ditolak, tidak ada hak yang dinaikkan.
- Berhenti sesudah commit/push audit pada branch fase yang sama. Tidak memulai Fase 2, merge, deploy, atau migrasi produksi.
