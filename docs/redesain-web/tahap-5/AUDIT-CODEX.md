# Audit independen Codex — redesain website Tahap 5

Tanggal: **23 Oktober 2026 (Asia/Jakarta)**. Putusan: **LULUS setelah dua koreksi terarah auditor**. Ketiga kriteria wajib terpenuhi: tanpa isian ID, kasus Rahasia tersembunyi sesuai hak, dan data baru orang tua terbatas pada nama pengirim. Tahap 6 belum dimulai dan memerlukan instruksi Human Developer. Kelulusan ini bukan izin merge/deploy/migrasi produksi.

## Acuan dan keadaan

- Branch `claude/redesain-web-tahap-5`; implementasi Claude **`5a332c1`**, baseline **`492fdd3`** (sudah memuat audit Tahap 4). Seluruh diff aplikasi, pengujian, dan perubahan oracle diperiksa. Tidak membuat worktree.
- Acuan: `AGENTS.md`, `rencana.md`, `keputusan.md` F/Z, layar V-1–V-7/M-4 serta kriteria Tahap 5 di `cetak-biru.html`, seluruh 16 penafsiran README, audit Tahap 4, dan aturan cakupan/kerahasiaan/publikasi PRD V3 §5.3–5.6 dan Fase 2–5.
- Bukti auditor di [`audit-codex/`](audit-codex/); bukti implementator tidak ditimpa. Berkas `*-final.txt` merekam hasil setelah koreksi. DB `test_redesain_t5_test` dibangun ulang sebelum HTTP dan browser pengubah data. Regresi V3 memakai sandbox yang diperintahkan, `webalhasan_v3_phase1_test`.
- Tidak ada perubahan skema, kapabilitas, data produksi, mobile, situs publik, atau direktori pekerjaan lain yang belum terlacak. Skrip penyiapan membaca DB sandbox `.env` dan membangun salinan khusus; bukan dump produksi baru. Sandbox eksekusi membatasi `/dev/fd` dan koneksi lokal, sehingga pengujian lokal dijalankan melalui eskalasi yang disetujui.

## Temuan dan koreksi

**F-1 · P3 · Pilihan laporan belum dapat dicari — DITUTUP.** V-6 mensyaratkan pilihan bernama yang dapat dicari. Empat pilihan kelas, kamar, pembimbing, dan murobi sebelumnya berupa select biasa tanpa pencarian aplikasi. Auditor menambahkan pencarian menggunakan komponen `data-ah-option-filter` yang sudah ada. Pengumuman aksesibilitas menggunakan kata “pilihan”; santri pada pemanggil lama tetap memakai kata “santri”. Tanpa JavaScript, select tetap tersedia dan kotak pencarian tambahan tersembunyi. Nilai serta cakupan tetap divalidasi server lama.

**F-2 · P3 · Sumber daftar publikasi belum tertaut — DITUTUP.** V-5 mensyaratkan sumber pelanggaran/kasus/sesi tertaut. Daftar sebelumnya hanya menampilkan teks; kelola publikasi dari sesi juga tidak menyediakan jalan ke sumber. Auditor menambahkan tautan pelanggaran/kasus dan, untuk sesi, tautan ke kasus pemilik dengan jangkar sesi. Metadata `sumber_kasus_id` hanya ditambahkan pada hasil staf yang sudah lolos pemeriksaan hak (`options`, `manage`, `managePage`); DTO orang tua tetap tepat allowlist Z. Membuka tujuan tetap melalui guard detail kasus/pelanggaran.

Probe browser yang sama sebelum koreksi menghasilkan **5 lulus/15 gagal** (12 pemeriksaan pencarian dan tiga pemeriksaan tautan pada tiga lebar); setelah koreksi **53/0**, termasuk penggunaan pencarian, pembersihan saringan, pembukaan sumber, tanpa overflow, dan tanpa JavaScript. Bukti: [`probe-ui-sebelum.txt`](audit-codex/probe-ui-sebelum.txt), [`probe-ui-final.txt`](audit-codex/probe-ui-final.txt). Probe server juga mengalihkan referensi satu publikasi sintetis ke sesi sementara, memeriksa tujuan/jangkar daftar dan kelola, lalu memulihkannya.

## Keputusan Z — verifikasi server

Uji HTTP **106/0**, probe auditor akhir **33/0**, serta integrasi publikasi V3 Fase 4 **65/0** membuktikan:

- Login REST nyata, daftar dan detail web/REST orang tua hanya menambah `pengirim`. Allowlist tepat: `id`, `santri_id`, `ringkasan`, `tindak_lanjut`, `diterbitkan_pada`, `ditarik_pada`, `dibaca_pada`, `version`, `status`, `pengirim`. Tidak ada ID pembimbing, tujuan kasus, ringkasan internal, isi sesi, atau lampiran.
- Pengirim pelanggaran berasal dari pembimbing pencatat; pengirim kasus dan sesi berasal dari pembimbing kasus. Nama kosong/tanpa pemilik memakai “Pesantren Al Hasan”. Mengubah nama master mengubah nama yang dibaca, dengan escaping HTML pada web. Perubahan isi sesi internal tidak mengubah snapshot orang tua.
- Detail wali lain melalui ID web maupun REST ditolak 403. Saringan anak yang tidak berhak tidak membuka data. Nama anak diperoleh lewat relasi akun wali sendiri.
- Pada sesi web dan token REST yang sudah login, pencabutan relasi wali, penonaktifan master wali, atau penonaktifan santri langsung menutup detail 403. Daftar REST kosong atau 403; laporan HTML/CSV/cetak tidak mengandung isi publikasi. Setelah pemulihan, snapshot dan pengirim kembali identik.
- Publikasi ditarik tetap meredaksi ringkasan/tindak lanjut; nama pengirim tidak menjadi akses ke isi sumber. Sumber Rahasia menolak pratinjau/terbit; perubahan Internal → Rahasia ditolak selama publikasi aktif, sesuai invariant V3.
- Probe memulihkan nama, status master/relasi, referensi sumber, dan ringkasan internal dalam `finally`; token probe dihapus. Login dan pembacaan admin tetap dapat meninggalkan jejak autentikasi/audit pada DB uji.

Oracle probe Z pertama keliru menuntut total=0 pada daftar ketika master wali nonaktif; aplikasi mengembalikan **403** yang aman karena kapabilitas dicabut. Oracle diperbaiki menjadi 200 dengan total=0 atau 403, selalu tanpa nama/snapshot. Bukti awal **30/1** dipertahankan di `probe-z-oracle-awal.txt`; ini bukan temuan aplikasi. Versi 31/0 sebelum tambahan uji tautan dan versi akhir 33/0 juga disimpan.

## Penilaian layar dan keputusan F

| Cakupan | Hasil |
| --- | --- |
| V-1 | Chip berangka, cari/periode, jenis/tingkat/poin, panel catat, pratinjau total, POST+CSRF dan cakupan berfungsi. Murobi tidak dapat mencatat. |
| V-2 | Tab, total/catatan berlaku, ambang katalog, riwayat, lampiran privat, koreksi/pembatalan beralasan dan mengetahui tersedia. Publikasi bertaut Rahasia dinonaktifkan dengan sebab layanan. |
| V-3 | Papan/daftar dan wizard tersedia. Pembimbing lain dalam kamar yang sama serta murobi tidak melihat kartu/baris/angka kasus Rahasia; pemilik/admin melihatnya. Tautan rekomendasi mencentang rekomendasi santri terpilih. |
| V-4 | Tab/kartu sesi, dialog hasil bersyarat, jadwal ulang dan tindakan kasus berfungsi. Detail/cetak Rahasia tetap menolak pembaca lain. Isi internal mengikuti serializer lama. |
| V-5 | Daftar per wali, status/baca, tautan sumber setelah F-2, wizard pratinjau/terbit, batas 30 menit, ubah isi/pratinjau ulang, koreksi/penarikan beralasan tersedia. |
| V-6 | Isian ID diganti pencarian/pilihan nama; F-1 melengkapi pencarian empat pilihan. Angka memakai builder cakupan/privasi yang sama. CSV/REST laporan mempertahankan bentuk; HTML/cetak memakai nama. |
| V-7 · F/Z | Menu orang tua “Informasi pembinaan”, staf “Publikasi ke orang tua”; tab Belum dibaca/Semua. Judul bertanggal, nama anak/pengirim, ringkasan/tindak lanjut dan tanda baca tersedia, tanpa jenis sumber. |
| M-4 | Admin saja; kategori/jenis/ambang, panel beralasan, label tingkat/poin/status dan riwayat kalimat dengan pelaku bernama. Server menolak penyimpanan nonadmin. |

Tangkapan auditor diperiksa langsung: V-7 detail pada 375px, V-3 papan pada 1280px, V-6 pada 768px, serta formulir catat/kasus dan katalog. Suite browser memeriksa 28 pasangan layar–akun pada **375/768/1280px** dan interaksi utama; regresi kerangka memeriksa sembilan akun pada tiga lebar.

## Penafsiran dan batas cakupan README

| Butir | Penilaian auditor |
| --- | --- |
| 1 | Diterima: pengirim = pembimbing pemilik sumber; sesi mengikuti kasus; fallback dan nama master dinamis dibuktikan server. Snapshot isi publikasi tidak berubah. |
| 2 | Diterima: nama anak dari daftar anak wali sendiri, fallback “Anak Anda”; tidak menambah data internal. |
| 3 | Diterima untuk cakupan website: REST aditif mengikuti layanan yang sama, DTO terbatas. Kompatibilitas runtime mobile belum diuji dan tidak diklaim. |
| 4 | Diterima: tempat tetap opsional mengikuti aturan layanan lama; tidak mengubah validasi bisnis hanya karena tanda wajib pada wireframe. |
| 5 | Diterima: pratinjau total memakai angka server dan poin jenis, penyimpanan tetap dihitung layanan. Tanpa JS petunjuk ini tidak tersedia. |
| 6 | Diterima: tab tautan biasa, definisi catatan berlaku dari ledger, ambang menurut tahun catatan. |
| 7 | Diterima: tombol berbahaya dalam dialog kolom Tindakan tetap memenuhi tujuan alasan/pembatalan dan dapat diakses tanpa JS. |
| 8 | Diterima: pemilihan santri GET, tujuan/kerahasiaan POST pada langkah kedua; tautan hanya dari santri yang sama. |
| 9 | Diterima: enam kartu + tautan daftar lengkap; angka seluruh cakupan memakai penyaring kerahasiaan sama. Dibatalkan lewat daftar. |
| 10 | Diterima: JS menonaktifkan isian tak relevan; layanan tetap memvalidasi hasil dan mempertahankan nilai tersimpan sesuai aturan lama. Regresi konseling 121/0 dan interaksi browser lulus. |
| 11 | Diterima: penerima/isi pada formulir bernomor, lalu pratinjau; tidak menambahkan status draf. |
| 12 | Diterima dengan klarifikasi README: status tidak memengaruhi ringkasan; **tindak lanjut memengaruhi Catatan/Total poin**, tidak Kasus berjalan. Ambang hanya semester aktif dan saringan santri/kelas/kamar/nama. Ringkasan merupakan metrik lintas status, bukan jumlah baris status terpilih. |
| 13 | Diterima: kelas/kamar tampilan memakai penempatan tahun aktif; CSV dan REST laporan mempertahankan kolom ID. Tidak ada isian ID yang diketik. |
| 14 | Diterima: pilihan pembimbing/murobi hanya admin, cakupan staf tetap server. Alamat laporan lama ber-ID tetap menyaring. |
| 15 | Diterima: tab Laporan untuk murobi membuat halaman yang sudah berhak dibaca dapat ditemukan; tidak memperluas hak. |
| 16 | Diterima: fixture sintetis dua pembimbing sekamar menguji Rahasia dengan lebih kuat; total A1=15 sesuai ledger, bukan angka komentar seed lama 30. |

Perubahan oracle implementator diterima: allowlist V3 Fase 4 menambah tepat `pengirim` sesuai Z; F-4 Tahap 2 mengikuti wizard baru dengan rekomendasi tercentang. Pemeriksaan cakupan dan privasi tidak dihapus.

## Pengujian independen

| Uji | Hasil |
| --- | --- |
| Tahap 5 statis, sebelum/sesudah koreksi | **96/0** |
| Tahap 5 HTTP, DB baru sebelum setiap run | **106/0**, sebelum dan sesudah koreksi |
| Tahap 5 browser 375/768/1280px, DB baru sebelum setiap run | **115/0**, sebelum dan sesudah koreksi |
| Probe auditor Z + sumber sesi, akhir | **33/0** |
| Probe browser pencarian/tautan sumber, akhir | **53/0** |
| Regresi browser Tahap 1, sembilan akun × tiga lebar | **107/0** |
| Integrasi V3 Fase 4, sebelum/sesudah koreksi | **65/0** |
| Integrasi V3 Fase 2/Fase 3/Fase 5; akses Fase 5 | **67/0**, **121/0**, **43/0**, **5/0** |
| Kanal Fase 4; konkurensi Fase 4/Fase 2; performa Fase 5 | **9/0**, **10/0**, **6/0**, **6/0** |
| Statis Tahap 1–4; V3 Fase 1/Fase 4 | exit **0** seluruhnya |
| Statis V3 Fase 2/Fase 3 | **68/1**, **102/1** — kegagalan baseline label navigasi lama |
| Lint PHP berubah, sintaks JS, `git diff --check` | lulus |

Dua oracle statis lama menuntut teks `Data pelanggaran warisan` dan `'v3.konseling'` yang sudah tidak ada pada Navigation baseline `492fdd3`; bukti `baseline-oracle.txt`. Tidak mengubah oracle untuk menyembunyikan hasil. Percobaan awal tanpa env V3 keluar 77; run dengan DB/env yang benar disimpan terpisah dan angka di tabel berasal dari run yang benar.

## Batas pengujian

- Chromium lokal; Safari/iOS, Firefox, pembaca layar, axe, mobile dan perangkat fisik belum diuji. Tidak ada smoke cPanel/produksi atau pengiriman Push fisik.
- Tidak mengulang migrasi V3, integrasi V1/V2/V4 yang tidak menyentuh perubahan ini, atau seluruh suite HTTP Tahap 2–4. Regresi auditor memakai integrasi V3, statis Tahap 1–4 dan browser kerangka seluruh peran; bukti lama implementator tidak diklaim sebagai eksekusi auditor.
- Suite performa V3 memakai fixture/pagination tersendiri, bukan pengukuran halaman lengkap V-1/papan/laporan pada skala produksi. Nama pengirim tetap pembacaan dinamis sesuai penafsiran 1.
- Tidak ada temuan aplikasi yang masih terbuka dalam cakupan audit. Tahap berikutnya menunggu instruksi Human Developer.
