# Audit independen Codex — Tahap 3

Catatan riwayat audit pertama. **Status akhir: lulus audit ulang atas `549c7e6`**, lihat [`AUDIT-ULANG-CODEX.md`](AUDIT-ULANG-CODEX.md). Temuan dan hasil awal di bawah dipertahankan utuh.

Tanggal: **23 Oktober 2026 (Asia/Jakarta)**. Putusan: **BELUM LULUS**, dua temuan **P2** (F-1 dan F-2). Tahap 4 tidak dimulai. Tidak ada perubahan kode aplikasi, merge, deploy, migrasi produksi, atau perubahan mobile pada giliran audit ini.

## Acuan dan batas pemeriksaan

- Branch `claude/redesain-web-tahap-3`; seluruh implementasi **`e626313`** di atas baseline audit ulang Tahap 2 **`429f9ef`**. Checkout yang sama, tanpa worktree. Direktori pekerjaan lain yang belum terlacak tidak disentuh.
- Dibaca: `AGENTS.md`, `rencana.md`, `keputusan.md`, cetak biru N-2/N-4/N-5/K-5–K-8/W-1–W-4, kriteria selesai Tahap 3, README implementator termasuk **seluruh 14 butir Penafsiran dan batas cakupan**, serta audit ulang Tahap 2. Diff kode dan perubahan oracle pengujian diperiksa terhadap baseline.
- Semua angka pengujian di bawah berasal dari eksekusi auditor. Bukti implementator tetap utuh. Bukti independen: [`audit-codex/`](audit-codex/). Probe baru: `tests/redesain_web_tahap3_audit.php`.
- Persiapan, mutasi, dan HTTP memakai DB salinan khusus `test_redesain_t3_test` dan `test_redesain_t3_kosong_test`. Sumber sandbox hanya dibaca oleh skrip persiapan. Tidak membaca dump pribadi. Probe WhatsApp memakai `FakeProvider` yang disuntik ke layanan; **tidak ada pengiriman nyata atau koneksi penyedia eksternal**.

## Temuan yang menahan kelulusan

### F-1 · P2 — kanal Ditangguhkan masih dapat diproses melalui worker/retry

**Lokasi:** `app/Notification/NotificationAdminService.php:521–532,564–571`; pemanggil REST `app/Api/NotificationApiService.php:202–217`; dispatcher `app/Notification/NotificationDispatcher.php:74–96`.

`ubahSakelar(..., true)` dan `kirimPesanUji()` sudah memeriksa `NotificationChannel::ditangguhkan()`. Namun `jalankanWorker()` langsung memanggil dispatcher dan `cobaUlang()` hanya membaca sakelar tersimpan. Guard worker pada `admin/admin_notifikasi.php` melindungi panel saja. API meneruskan permintaan ke dua metode layanan tersebut tanpa guard penangguhan lain.

**Pemicu:** database yang sah dari sebelum redesain sudah memiliki `whatsapp_enabled=1` dan hasil konfigurasi `Lulus`. Ini keadaan yang diakui sendiri dalam butir penafsiran 2, bukan cara pengguna tanpa hak menyalakan kanal baru. Pada keadaan itu kartu tetap menulis Ditangguhkan, tetapi:

1. `jalankanWorker($admin, 'WhatsApp', false)` tidak menolak; **satu panggilan `FakeProvider::send()`** terjadi dan satu outbox menjadi **Sent**.
2. `cobaUlang()` terhadap baris Failed tidak menolak; baris menjadi **Queued**.

Probe mengisolasi satu baris sintetis, menguji dry-run tanpa send, lalu kedua jalur di atas. Seluruh perubahan layanan di-rollback. Tidak ada HTTP token API yang diterbitkan untuk probe ini: bukti eksekusi ada pada layanan yang dipanggil facade REST, dan penerusan facade diperiksa secara kode. Dispatcher yang sama dipakai cron; berdasarkan pemeriksaan kode, sakelar lama aktif juga tetap cukup untuk pengiriman cron.

**Dampak:** status Ditangguhkan dan klaim penafsiran bahwa yang boleh hanya pemeriksaan, mematikan, serta worker tanpa kirim tidak mencerminkan perilaku server untuk konfigurasi lama. Keputusan AL belum berlaku konsisten pada jalur pengiriman tersebut.

**Koreksi yang diminta:** terapkan penangguhan pada worker non-dry-run dan retry, serta pastikan dispatcher/cron tidak mengirim kanal Ditangguhkan ketika sakelar lama masih aktif. Pertahankan pemeriksaan tanpa kirim, kemampuan mematikan, dan dry-run aman; tidak perlu migrasi atau menghapus antrean lama. Uji kondisi awal aktif/Lulus, nol pemanggilan send, preservasi status/percobaan outbox, dan regresi Push/InApp. Jangan hanya menambah guard di panel.

### F-2 · P2 — alasan batal lunas hanya diwajibkan oleh JavaScript

**Lokasi:** `admin/admin_pembayaran_psb.php:28–46`.

K-6 meminta **Batal lunas dengan alasan**. Dialog mewajibkan alasan, tetapi server menjalankan UPDATE lebih dahulu dan menerima alasan kosong sebagai `null`. Tombol dari menu tanpa JavaScript juga tetap mengirim formulir tersebut tanpa tempat memasukkan alasan.

**Reproduksi independen:** login fixture Admin, pilih tagihan Lunas, POST ber-CSRF `act=batal&id=...` (a) tanpa `alasan`, lalu (b) dengan `alasan='   '`. Keduanya membalas **302**, mengubah status menjadi **Belum Lunas**, dan menghapus waktu lunas. Kontrol dengan alasan sah berhasil dan tercatat dalam audit; tanpa CSRF tetap ditolak **419**. Status/waktu tagihan dan baris audit khusus probe dipulihkan dalam `finally`.

**Dampak:** pengguna tanpa JavaScript atau pemanggil POST lama dapat membatalkan pencatatan pembayaran tanpa alasan yang menjadi tujuan alur baru. Menjaga kompatibilitas permintaan lama tidak cukup untuk mengabaikan persyaratan alasan yang baru disetujui.

**Koreksi yang diminta:** validasi alasan nonkosong di server **sebelum UPDATE**, berikan cara memasukkannya tanpa JavaScript, dan pertahankan status/waktu/audit bisnis saat validasi gagal. Perbarui permintaan sah pada regresi mutasi V1 agar menyertakan alasan; pertahankan pemeriksaan metode, CSRF, batas target, dan preservasi tabel. `tests/get_mutasi_v1_http.php:246` masih memperlakukan pembatalan tanpa alasan sebagai permintaan sah, sehingga oracle itu perlu disesuaikan secara terbuka.

## Hasil terhadap layar dan kriteria selesai

| Cakupan | Hasil audit |
| --- | --- |
| N-2 / AL | Kerangka, tab, halaman daftar gagal/riwayat, tindakan massal berurutan, konfirmasi mematikan Push, kartu WhatsApp tanpa tombol kirim/nyalakan/worker, serta penolakan sakelar baru lulus. **F-1 menahan kelulusan penangguhan server.** |
| N-4 | Tiga tema, hitungan santri aktif, grafik dari token dan keadaan kosong, sumber ringkasan absensi, serta hunian agregat lulus dengan batas AE di bawah. |
| N-5 | Arsip baca-saja, pencarian/tahun terikat, pagination, escape, dan penolakan tulis lama 405 lulus. |
| K-5 tanpa pratinjau | Panel tunggal, chip/saringan/pagination, biodata/tautan berkas/riwayat terbatas, keputusan, wizard impor server dengan token sekali pakai, dan ekspor sesuai saringan lulus. Batas chip Semua dan riwayat dicatat di bawah. |
| K-6 | Daftar/panel/rincian/ekspor dan dialog di browser lulus. **F-2 menahan kelulusan pembatalan dengan alasan.** |
| K-7 | Matriks dengan kolom lengket, segmen status, lipatan HP dan ekspor lulus. |
| K-8 | Tab rekap, gabungan PA/PI, total, grafik pendamping, tautan belum ditempatkan dan ekspor lulus sesuai sumber penempatan lama; lihat catatan hitungan aktif di bawah. |
| W-1–W-4 / AH / AI | Berita langsung tampil tanpa draf/kategori, editor dalam kerangka dan fallback textarea, pratinjau, galeri banyak foto tanpa album, panel ubah, daftar unduhan dan validasi unggahan lulus pada jalur yang diuji. Summernote/CDN belum diuji hidup. |
| Tidak ada lagi `sidebar.php` dan `alert()` | Lulus. Sidebar lama dihapus, pemuatnya sudah pensiun, dan pemeriksaan statis/browser lulus. Dialog native di halaman tahap berikutnya bukan `alert()` pada cakupan ini. |
| Daftar berhalaman | Lulus untuk daftar operasional yang berubah; rekap agregat tetap tabel/matriks. |
| WhatsApp tidak dapat dinyalakan | Penolakan aktivasi baru lulus; **belum cukup untuk menyatakan kanal Ditangguhkan efektif pada semua jalur, F-1**. |
| 375 / 768 / 1280 px | Lulus pengukuran browser seluruh layar Tahap 3 dan seluruh menu sembilan akun; tanpa overflow halaman/galat JavaScript yang dilaporkan suite. |

## Penilaian seluruh penafsiran dan batas cakupan

| Butir README | Penilaian auditor |
| --- | --- |
| 1 · Semua = belum dimigrasi | **Diterima sebagai preservasi default lama**, karena data Dimigrasi tetap tersedia lewat chip sendiri dan ekspor mengikuti saringan. Label Semua berpotensi membingungkan: diagram N-4 menghitung semua status sementara alamat daftar default mengecualikan Dimigrasi. Disarankan label/keterangan eksplisit; bukan alasan menghapus data atau gate tambahan di audit ini. |
| 2 · Penguncian WhatsApp | **Belum diterima, F-1.** Guard aktivasi/pesan uji benar; worker non-dry-run dan retry masih berjalan bila sakelar lama aktif. Tidak cukup menyatakan dispatcher/cron tidak diubah ketika kartu menyatakan Ditangguhkan. |
| 3 · Hunian asrama / 10 panggilan pekanan | **Diterima dengan batas.** Jangan mengarang kapasitas per putra/putri sebelum AE Tahap 6; kapasitas agregat dan jumlah penghuni per L/P dijelaskan. Sepuluh panggilan ringkasan konsisten dengan sumber laporan; biaya query produksi belum diukur. |
| 4 · Riwayat pendaftar | **Diterima terbatas pada data yang tersedia**, karena keterbatasan tanggal riwayat dinyatakan di halaman dan tidak mengarang kejadian. Bukan bukti ada riwayat perubahan status lengkap. |
| 5 · Berkas tanpa pratinjau | **Diterima sesuai pengecualian eksplisit Tahap 3.** Tautan lama bukan klaim keamanan penyajian berkas PSB sudah selesai; AG tetap menunggu workstream keamanan PSB. |
| 6 · Impor PSB dan endpoint lama | **Diterima.** Wizard PSB diperiksa server lalu disimpan dengan token; impor santri AC tetap Tahap 6. Endpoint JSON kompatibilitas lama tetap jalur tulis admin/CSRF, bukan bukti seluruh impor harus melewati wizard baru. |
| 7 · Ketik nama hapus di tampilan | **Diterima sebagai konfirmasi UI.** Tidak memberikan/menggantikan otorisasi; hak Admin dan CSRF tetap di server. |
| 8 · Alasan batal hanya di dialog | **Belum diterima, F-2.** Pengecualian tidak membuat alasan tersedia pada formulir tanpa JS dan membolehkan pencatatan tanpa alasan. Tidak menambah audit status/terima/lunas lain dapat diterima untuk preservasi cakupan. |
| 9 · PRG dan saringan | **Diterima.** Pengalihan internal memakai kunci saringan yang dibatasi, pesan mengganti alert; galat basis data mentah tidak ditampilkan pada migrasi. |
| 10 · Jenis dan ukuran unggahan | **Diterima untuk jenis yang didaftarkan.** Tidak ada bukti kebutuhan format lain dalam tugas ini; pemeriksaan gambar dan penolakan jenis berbahaya diuji. Batas PHP/web server dan berkas mendekati 5/20 MB belum diuji, sehingga bukan klaim setiap deployment mendukung ukuran tersebut. |
| 11 · Folder gambar berita | **Diterima sebagai masalah baseline terpisah yang sudah tercatat.** Gambar admin kini membaca folder unggahan yang benar; folder hapus lama masih tidak sejalan. Bukan klaim penghapusan fisik semua gambar berita sudah benar. |
| 12 · Judul/keterangan banyak foto | **Diterima.** Tiap foto tetap satu baris, nama berkas menjadi judul awal, lalu judul/keterangan dapat diubah per foto; tanpa album. |
| 13 · confirm di luar Tahap 3 | **Diterima.** Tidak memperluas pekerjaan ke tahap berikutnya. Suite Tahap 3 memastikan tanpa dialog native pada alur yang diuji. |
| 14 · Tidak mengubah DB/hak/menu/mobile/publik | **Diterima untuk skema, hak akses dan peta menu serta berkas mobile/publik.** Halaman admin memang menulis data PSB/konten seperti sebelumnya, dan kebijakan layanan notifikasi berubah untuk AL. Ini bukan klaim tidak ada perubahan perilaku server. |

Catatan nonblokir K-8: query per kelas masih menghitung santri berdasarkan `plotting_kelas.status='Aktif'` tanpa syarat `santri.is_active`/`archived_at`, sama dengan baseline. Tambahan baris belum ditempatkan memakai syarat santri aktif, dan label total kini menyebut santri aktif. Definisi ini perlu diselaraskan pada pekerjaan data santri; audit tidak mengklaim semua grafik K-8 selalu sama dengan angka santri aktif N-4. Ini perilaku sumber lama, bukan regresi query baru yang menahan Tahap 3.

## Pengujian independen

Urutan permintaan dijalankan dari root: persiapan DB → statis → HTTP → persiapan ulang DB → server lokal **8951** → browser Tahap 3 → browser Tahap 1. Sesudahnya dilakukan perluasan sembilan akun, probe temuan, smoke notifikasi, dan regresi statis/lint.

| Pengujian | Hasil | Bukti independen |
| --- | --- | --- |
| `bash tests/redesain_web_tahap3_db.sh` dua kali | exit **0** keduanya, DB salinan khusus | `db.txt`, `db-reset.txt` |
| `php tests/redesain_web_tahap3_static.php` | **145/0**, exit 0 | `static.txt` |
| `php tests/redesain_web_tahap3_http.php` | **157/0**, exit 0 | `http.txt` |
| Browser Tahap 3, 20 layar × tiga lebar + interaksi | **76/0**, exit 0 | `browser-t3.txt` |
| Browser Tahap 1, konfigurasi bawaan lima akun | **91/0**, exit 0 | `browser-t1.txt` |
| Browser Tahap 1 diperluas sembilan akun × tiga lebar | **107/0**, exit 0 | `browser-nine.txt` |
| Probe auditor `DB_NAME=test_redesain_t3_test php tests/redesain_web_tahap3_audit.php` | **9/4**, exit **1**; empat kegagalan merepresentasikan F-1/F-2 | `audit-probe.txt` |
| `DB_NAME=test_redesain_t3_test V2_PHASE4_RUN_WEB=1 php tests/v2_phase4_web_smoke.php` | **46/0**, exit 0 | `notifikasi-web.txt` |
| Seluruh 23 `tests/*_static.php` | **21 exit 0**, **2 exit 77** (gate V3 Fase 2/3, bukan kelulusan integrasi DB) | `static-regression.json` |
| Lint semua PHP yang berubah sejak `429f9ef` dan probe auditor | **46 berkas, 0 gagal** | `lint.json` |
| `node --check` JS bersama dan browser Tahap 3; `git diff --check` | exit **0** | eksekusi auditor |

Perintah pertama persiapan DB di sandbox berhenti pada pembatasan `/dev/fd`; percobaan HTTP di sandbox gagal mendapatkan sesi karena koneksi lokal dibatasi. Kedua perintah dijalankan ulang dengan akses lokal tambahan dan menghasilkan hasil di tabel. Kegagalan infrastruktur tersebut tidak dihitung sebagai temuan aplikasi atau kelulusan pengujian.

Tangkapan terpilih tiga ukuran disimpan di `audit-codex/tangkapan/`. Auditor juga memeriksa langsung panel Pendaftar desktop/HP, editor tablet dan rekap keuangan HP dari hasil eksekusi browser. Pengujian otomatis mencakup layar dan ukuran lain sesuai suite.

## Batas dan handoff

- Hanya Chromium lokal; belum Safari/iOS, Firefox, perangkat fisik, pembaca layar atau axe. jQuery/Summernote diblokir suite sehingga yang diuji hidup adalah fallback textarea dan pratinjau, bukan widget Summernote.
- Tidak mengulang matriks HTTP dua server baseline, seluruh suite integrasi V4/PDF/concurrency, impor 1.000 baris, unggahan mendekati batas, atau pengukuran kinerja produksi. Hasil implementator untuk pengujian tersebut tidak dipresentasikan sebagai hasil auditor.
- Sumber query K-8 dan folder hapus gambar berita dicatat sebagai batas baseline, bukan koreksi diam-diam pada definisi bisnis.
- **Handoff ke Claude: koreksi hanya F-1/F-2 pada Tahap 3, sertakan pengujian regresi dan status yang jujur, lalu berhenti untuk audit ulang Codex.** Probe auditor saat ini sengaja gagal sampai kedua temuan tertutup; jangan mengubah oracle menjadi menerima kedua perilaku yang bermasalah.
- **Tahap 3 belum lulus. Tahap 4 tetap belum dimulai dan memerlukan instruksi Human Developer setelah audit lulus.**
