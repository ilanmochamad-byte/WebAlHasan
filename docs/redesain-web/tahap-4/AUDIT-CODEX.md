# Audit independen Codex — redesain website Tahap 4

Tanggal: **23 Oktober 2026 (Asia/Jakarta)**. Putusan akhir: **LULUS setelah satu koreksi terarah auditor**. Kriteria wajib Tahap 4 terpenuhi. Tahap 5 belum dimulai dan memerlukan instruksi Human Developer. Kelulusan ini bukan keputusan merge, deploy, atau migrasi produksi.

## Acuan dan keadaan yang diaudit

- Branch `claude/redesain-web-tahap-4`, implementasi Claude **`e6f9484`** di atas baseline audit Tahap 3 **`11ed4ba`**. Seluruh diff kode aplikasi dan perubahan oracle pengujian diperiksa. Tidak membuat worktree.
- Dibaca: `AGENTS.md`, `rencana.md`, `keputusan.md`, layar I-1–I-7/J-1–J-6/X-1 dan baris kriteria Tahap 4 pada `cetak-biru.html`, README handoff beserta 18 butir penafsiran, serta audit ulang Tahap 3. Ketentuan identitas/cakupan, routing, idempotensi dan kompatibilitas PRD V2, jadwal/pertemuan/laporan PRD V1, dan fondasi penugasan/V4 menjadi acuan regresi.
- Angka di bawah berasal dari **eksekusi auditor**. Bukti implementator tidak ditimpa; bukti baru berada di [`audit-codex/`](audit-codex/). Berkas berakhiran `-final.txt` merekam kode akhir sesudah koreksi.
- Seluruh pengujian memakai DB salinan khusus `test_redesain_t4_test`, salinan kosong, dan salinan tambahan `test_redesain_t4_regresi_test`. DB sandbox sumber `.env` hanya dibaca oleh skrip penyalinan; tidak ada migrasi atau mutasi produksi. Tidak mengubah mobile, situs publik, dokumen PSB, atau direktori pekerjaan lain yang belum terlacak.

## Temuan dan koreksi

**F-1 · P2 · Kisi mingguan terpotong pada 100 jadwal — DITUTUP.**

`admin/admin_pengajian.php` meminta `$service->list(..., 1, 500)`, sedangkan `ScheduleService::list()` membatasi `perPage` ke 100. Kisi mingguan tidak mempunyai pagination, sehingga jadwal setelah halaman pertama hilang tanpa pemberitahuan dan tidak ikut pemeriksaan bentrok. Ini bertentangan dengan J-1 dan klaim README bahwa seluruh jadwal semester dimuat.

Probe auditor menambah 101 jadwal nonaktif sintetis pada semester yang sama, membacanya lewat halaman mingguan tanpa filter dan dengan filter guru, lalu menghapus fixture tersebut. Sebelum koreksi hanya **96/101** jadwal sintetis terlihat karena empat tempat halaman pertama sudah terisi jadwal lain. Probe menghasilkan **32 lulus/2 gagal**. Auditor mengubah pembacaan kisi menjadi perulangan halaman berukuran 100 dengan filter/cakupan yang sama sampai seluruh hasil terbaca. Batas layanan/API tetap 100; tidak menambah endpoint atau mengubah aturan penyimpanan.

Sesudah koreksi, probe identik menghasilkan **34/0**; semua **101/101** jadwal sintetis tampil pada kedua pembacaan. Sidik seluruh baris jadwal, pertemuan, peserta, absensi, pengajuan dan keputusan sebelum/sesudah probe tetap identik. Bukti: [`probe-sebelum-koreksi.txt`](audit-codex/probe-sebelum-koreksi.txt), [`probe-setelah-koreksi.txt`](audit-codex/probe-setelah-koreksi.txt), [`probe-final.txt`](audit-codex/probe-final.txt). Regresi ada di `tests/redesain_web_tahap4_audit.php`.

## Keputusan H — bukti server

H diuji melalui HTTP sungguhan, bukan hanya menu atau pemanggilan layanan:

- Pendidikan dengan role Pengurus dan penugasan aktif milik sendiri membaca seluruh jadwal pada daftar, detail pertemuan, CSV, dan cetak. Angka laporan sama dengan Admin; Guru tetap terbatas pada jadwal sendiri. Penugasan yang dibatasi jenjang tetap mendapat seluruh jadwal sesuai bunyi keputusan H; cakupan modul rapor V4 tidak diubah.
- POST daftar/CSV/cetak/detail ditolak **405**. Modul jadwal/pertemuan menolak Pendidikan **403**, termasuk upaya menyelesaikan pertemuan. Tabel bisnis tetap identik sesudah semua permintaan yang ditolak.
- Penugasan nonaktif, sudah berakhir, belum dimulai, master pengurus nonaktif/diarsipkan, serta tahun penugasan nonaktif/diarsipkan mencabut akses pada **permintaan berikutnya**. Daftar, CSV, cetak dan detail semuanya **403**; sesi lama tidak mempertahankan hak.
- Akun Pendidikan yang diberi role Guru dan relasi guru sintetis membaca **0 pertemuan sendiri** saat peran aktif Guru, menolak detail guru lain **403**, lalu membuka detail tersebut **200** hanya setelah beralih eksplisit ke Pendidikan. Mencabut penugasannya kembali menutup akses meskipun sesi sebelumnya memilih Pendidikan.
- Login REST menghasilkan token sungguhan, lalu `/reports`, `/reports/filters`, `/reports/print`, dan `/reports/meetings/{id}` menolak Pendidikan **403**, termasuk dengan parameter penanda baca-semua palsu. HTTP implementator juga membuktikan parameter itu tidak memberi akses web kepada pengurus biasa. Penanda hanya dipasang guard web; kontrak aplikasi guru tidak melebar.

Suite HTTP **132/0** dan probe auditor akhir **34/0** mencakup bukti ini serta F-1. Mutasi role/relasi, penugasan, master, tahun dan guru sementara dipulihkan dalam `finally`; token probe dibersihkan. Login tetap menghasilkan jejak autentikasi pada DB salinan.

## Penilaian layar dan keputusan lain

| Cakupan | Hasil audit |
| --- | --- |
| I-1/I-2 | Ringkasan, tautan status, chip berangka, filter lanjutan, label arsip, tabel/kartu dan penanda tertunda tersedia; angka diverifikasi terhadap DB. |
| I-3 · N | Keputusan dari HP tetap membuka pengajuan yang sama dengan pesan hasil dan tombol Berikutnya. Tidak berpindah otomatis; alasan bertag HTML di-escape. |
| I-4 · M/Q | Pengurus memilih santri dalam binaan dan mengirim dari 375px tanpa overflow. Alasan cepat hanya mengganti awalan; panel routing/jumlah dibaca dari server dan menolak santri luar cakupan. |
| I-5 | Tindakan mengikuti hak/status, formulir ada dalam dialog, Batal tanpa POST, alasan wajib, linimasa dan info teknis khusus Admin. ID tidak sah tidak membuka pengajuan pertama. |
| I-6 | Periode/filter sama dipakai grafik, ringkasan, daftar, cetak dan CSV; grafik per pekan/kelas menggantikan grafik alasan sesuai M. |
| I-7 · O | Kartu izin anak, pilihan anak, Aktif/Riwayat dan detail hanya-baca; anak lain tidak terbuka. |
| J-1/J-3 · T | Kisi Senin–Ahad, pemilih hari HP, daftar dan panel tersedia. Pemotongan 100 jadwal ditutup melalui F-1. Pencarian daftar pertemuan tetap dalam cakupan guru. |
| J-2/J-4/J-5/J-6 · R/S/H | Hasil kehadiran hanya-baca, label izin tidak mengubah status, siklus pertemuan tetap tersedia bagi pelaku yang berhak; laporan Pendidikan tanpa tab pengelolaan. Batas riwayat J-5 dijelaskan di bawah. |
| X-1 · AS | Kop bersama internal, satu tanda tangan Bagian Pendidikan dengan garis nama kosong pada lembar terakhir, kode dan nomor halaman server. PDF Chromium 15 kasus × 3 orientasi cocok dengan jumlah lembar; tanda tangan bersama baris data. PSB tetap Tahap 8. |

Tangkapan auditor diperiksa langsung untuk formulir I-4 pada 375px, antrean I-3 pada 1280px, J-1 pada 1280px, J-2 pada 375px, laporan Pendidikan pada 768px, dan HTML cetak dalam media print. Pemeriksaan otomatis mencakup seluruh layar suite pada **375/768/1280px**. Tangkapan media print bukan bukti Safari atau PDF perangkat fisik.

## Penafsiran dan batas cakupan README

| Butir | Penilaian auditor |
| --- | --- |
| 1 | Diterima; definisi Pendidikan dan seluruh jadwal dibuktikan server, termasuk akun Guru/Pendidikan dan batas jenjang. |
| 2 | Diterima; memilih Pendidikan tetap menahan halaman perizinan milik Pengurus dengan tawaran beralih peran. Tidak memberi hak baru. |
| 3 | Diterima karena label implementasi adalah **Total**, bukan Total bulan ini. Angka seluruh cakupan tidak boleh dipresentasikan sebagai angka bulanan. |
| 4 | Diterima; chip menyatakan jumlah seluruh cakupan per status, sedangkan jumlah daftar mengikuti filter. |
| 5 | Diterima; alasan wajib pada server tetap dipertahankan. Pesan tidak mengklaim pengiriman WhatsApp berhasil; tautan detail bagi tindakan admin tetap tersedia. |
| 6 | Diterima; Q berupa jumlah semester aktif, mengecualikan pembatalan, dengan pemeriksaan cakupan sebelum agregasi. Riwayat rinci tetap memakai cakupan daftar akun. |
| 7 | Diterima sebagai batas peningkatan bertahap: pencarian server tetap menjangkau santri di luar 100 pilihan awal; tanpa JS panel tidak mengikuti pilihan secara langsung. |
| 8 | Diterima; Cetak detail menggunakan peramban, sedangkan AS berlaku pada laporan. ID tidak sah ditolak, tidak jatuh ke izin pertama. |
| 9 | Diterima; tanggal semester diturunkan dari teks tahun/semester; alamat lama bertanggal tetap memakai rentangnya. |
| 10 | Diterima; grafik adalah pembacaan web terpisah dan presenter API tidak memasukkan kolom baru secara bebas. Regresi kontrak API V2 Fase 5 lulus. |
| 11 | Diterima; izin menunggu keputusan tetap Aktif walau tanggal sudah lewat; Ditolak/Dibatalkan menjadi Riwayat. Pilihan anak tetap dibatasi relasi wali. |
| 12 | **Diterima dengan batas nonblokir:** belum ada validasi bentrok langsung ketika mengetik. Penanda bentrok kisi/panel mingguan membaca jadwal tersimpan; penyimpanan tetap memakai penolakan/peringatan server lama. F-1 diperbaiki agar kisi tidak kehilangan jadwal setelah baris 100. |
| 13 | Diterima; catatan tetap di alur draf/buka, tanpa mutasi baru. Label izin bagi non-admin tidak menaut ke detail di luar haknya. |
| 14 | Diterima sebagai penyederhanaan nonblokir; pencarian gabungan menggantikan filter Tanggal/Guru/Status terpisah pada gambar. |
| 15 | **Diterima dengan batas nonblokir:** J-5 menyediakan pencatat dan waktu perubahan terakhir, bukan dialog riwayat perubahan lengkap seperti wireframe. Tidak mengklaim riwayat berurutan telah dibangun. |
| 16 | Diterima; kop dokumen PSB tetap cakupan Tahap 8 dan tidak dibuka pada audit ini. |
| 17 | Diterima sebagai batas uji; renderer bersama mengubah isi HTML cetak dari API, bentuk respons tetap. Tidak mengklaim PDF mobile telah diuji pada perangkat. |
| 18 | Terverifikasi pada diff: tidak ada skema/migrasi, kode mobile, situs publik atau endpoint bisnis baru. Tampilan Ahad tidak mengubah nilai hari DB. |

Penyesuaian oracle pengujian diterima: label/tab dan lokasi formulir mengikuti UI baru; idempotency key tetap dibuat per formulir; uji PDF lama menganggarkan tanda tangan dengan satu baris data pada lembar terakhir. Penolakan akses, CSRF, kepemilikan, penyajian API dan kelengkapan ekspor tidak dilonggarkan.

## Hasil pengujian independen

| Uji | Hasil | Bukti `audit-codex/` |
| --- | --- | --- |
| Persiapan DB sesuai urutan permintaan; dibangun ulang sebelum browser awal dan akhir | exit 0 | `db*.txt` |
| Statis Tahap 4, kode akhir | **96/0** | `static-final.txt` |
| HTTP Tahap 4, kode akhir | **132/0** | `http-final.txt` |
| Cetak PDF Tahap 4, kode akhir | **60/0** | `cetak-final.txt` |
| Browser Tahap 4, tiga lebar, kode akhir | **121/0** | `browser-t4-final.txt`, `tangkapan/` |
| Browser Tahap 1, lima akun bawaan × tiga lebar, kode akhir | **91/0** | `browser-t1-final.txt`, `tangkapan-t1/` |
| Probe H + preservasi + regresi >100 jadwal | **34/0** | `probe-final.txt` |
| Statis perapihan, V1 Fase 3/5, V2 Fase 2/5, Tahap 2/3 | **131/0, 35/0, 44/0, 174/0, 290/0, 121/0, 152/0** | `static-*.txt` |
| Web V2 Fase 2/5; kontrak API V2 Fase 5 | **36/0, 79/0, 150/0** | `web-*.txt`, `api-v2-phase5.txt` |
| Integrasi V1 Fase 3/5; V2 Fase 2 | **10/0, 20/0, 94/0** | `integration-phase*.txt`, `integration-v2-phase2.txt` |
| Integrasi V2 Fase 5, eksperimen kontrol antrean | **143/0** | `integration-v2-phase5-kontrol.txt` |
| Regresi cetak lama, Bagian A | **76/0**; Bagian B menunggu `pdftotext` | `cetak-regresi.txt` |
| Lint PHP seluruh perubahan implementasi dan probe auditor | **43 berkas, 0 gagal** | `lint.json` |
| Sintaks JS bersama/browser dan `git diff --check` | exit 0 | suite statis/eksekusi auditor |

### Kegagalan dan penyesuaian lingkungan yang tetap dicatat

- Persiapan awal sandbox menolak process substitution `/dev/fd` sebelum DB dibuat. Server PHP/Chromium juga memerlukan izin eksekusi jaringan lokal. Uji selanjutnya dijalankan dengan izin yang diperlukan, tanpa mengubah skrip persiapan atau aplikasi.
- Dua harness lama V2 Fase 5 mencetak deprecation PHP 8.4 karena `str_getcsv()` tidak mengirim argumen `escape` eksplisit. Asersi web/API tetap lulus; ini peringatan pada pembaca CSV di berkas uji, bukan galat pada respons halaman. Log mentah dipertahankan.
- Probe H awal auditor menghasilkan **30/1** karena memakai nilai tahun ajaran salah `Nonaktif`; skema memakai **`Non-Aktif`**. Probe diperbaiki, kesalahan SQL dibuat fatal, dan cleanup token diperbaiki dari kolom `device_name` menjadi `name`. Guru sintetis yang sempat tersisa dibersihkan. Ini kesalahan harness auditor, bukan temuan aplikasi; hasil awal disimpan di `probe-h-orakel-awal.txt`. H final lulus.
- Integrasi V2 Fase 5 menghasilkan **138/5** pada DB Tahap 4 yang dipakai suite lain, dan **138/5** lagi pada salinan Tahap 4 baru. Kelima kegagalan hanya KL-9a/b/d/e/f (receipt Push pertama). Fixture Tahap 3 menambah pengiriman **Failed** yang siap dicoba ulang; `OutboxRepository::claim()` mengambil Queued/Failed menurut ID dengan batch **10**, sehingga baris receipt baru tidak mendapat giliran pada putaran pertama. Koreksi F-1 hanya menyentuh pembacaan jadwal web; worker Push tidak diubah. Seluruh asersi laporan pada kedua percobaan ini lulus.
- Kontrol: bangun ulang `test_redesain_t4_regresi_test`, simpan `id/tersedia_pada` semua **26** baris eksternal bawaan di memori, majukan `tersedia_pada` satu hari hanya selama subprocess `V2_PHASE5_RUN_INTEGRATION=1 php tests/v2_phase5_integration.php`, lalu pulihkan nilainya dalam `finally`. Suite **tanpa perubahan oracle/kode** lulus **143/0**. Tidak memakai penyedia nyata. `regresi.json` dan kedua log kegagalan asli tetap disimpan; jangan membaca exit 1 di sana sebagai hasil kontrol akhir.

## Batas dan handoff

- Chromium lokal saja; Safari/iOS, Firefox, perangkat fisik, pembaca layar, axe, cPanel dan PDF mobile tidak diuji. Verifikasi ekstraksi teks PDF lama dengan Poppler belum dijalankan; jumlah halaman fisik dicakup suite PDF Tahap 4.
- Tidak mengulang seluruh suite V3/V4, konkurensi semua workstream, uji mutasi V1, cleanup historis, atau matriks baseline seluruh alamat. Regresi dipilih berdasarkan perubahan jadwal/perizinan/laporan/kerangka/cetak. Tidak mengklaim pengiriman Push fisik/WhatsApp lulus.
- Pemeriksaan bentrok saat mengetik, filter pertemuan terpisah, dan dialog riwayat J-5 tetap batas nonblokir yang dinyatakan di atas. Kinerja kisi pada ribuan jadwal belum diukur; koreksi F-1 menjaga batas setiap pembacaan layanan namun kisi tetap memuat semua jadwal semester seperti rancangan.
- **Tahap 4 lulus pada keadaan akhir setelah F-1 ditutup. Berhenti untuk Human Developer. Tahap 5 belum dimulai.**
