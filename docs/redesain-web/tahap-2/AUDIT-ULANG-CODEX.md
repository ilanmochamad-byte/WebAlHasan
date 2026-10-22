# Audit ulang Codex — koreksi Tahap 2

Tanggal: **23 Oktober 2026 (Asia/Jakarta)**. Putusan akhir: **LULUS setelah koreksi terarah auditor**. F-1–F-4 ditutup berdasarkan hasil uji di bawah. **Tahap 3 belum dimulai dan tetap memerlukan instruksi Human Developer.** Ini bukan keputusan merge/deploy atau migrasi produksi.

## Acuan

- Branch `claude/redesain-web-tahap-2`, commit koreksi Claude **`e9b5b8c`** di atas commit audit **`964e21f`**; implementasi awal `9a5d764`, baseline Tahap 1 `d6c5da0`.
- Dibaca ulang: `AGENTS.md`, `rencana.md`, `keputusan.md`, cetak biru A-1–A-5/B-1–B-5/N-3, kriteria Tahap 2, laporan audit pertama, `KOREKSI-CLAUDE.md`, seluruh diff koreksi kode dan pengujian. Untuk rekomendasi, dicocokkan pula dengan kewenangan PRD V3 dan layanan konseling yang sudah ada.
- Semua hasil pada laporan ini merupakan eksekusi auditor. Bukti implementator di `koreksi/` tetap terpisah. Bukti audit ulang disimpan di `audit-ulang-codex/`; bukti audit pertama tidak ditimpa.

## Hasil per temuan

| Temuan | Penilaian akhir |
| --- | --- |
| **F-1: peran aktif** | Ditutup setelah tambahan koreksi auditor pada POST pemroses izin. `Layout` tidak lagi mengganti sesi. Halaman peran lain dan `?mode=` lama menawarkan pilihan eksplisit; memilihnya memakai POST/CSRF dan pemeriksaan hak nyata. POST yang ditolak tidak diputar ulang. Tautan izin dari notifikasi pada peran Pendidikan meminta perpindahan eksplisit. |
| **F-2: cakupan sendiri** | Ditutup. Beranda Guru menyaring penugasan mapel sendiri; satu penugasan menghasilkan satu tugas sementara `NilaiService::options()` untuk pengawasan Admin tetap memuat kedua mapel. Akun Admin+Pendidikan diuji dengan dua jenjang: Beranda hanya kelas penugasannya; setelah memilih Admin, kelas di luar penugasan kembali tersedia. Tujuan perbaikan Pendidikan juga diperbaiki agar mengikuti cakupan itu. |
| **F-3: status efektif** | Ditutup. `ringkasBahan()` memakai himpunan santri/bahan, urutan/proyeksi sumber, dan aturan fingerprint yang setara matriks V4. Suite implementator 191/0 dijalankan sendiri; probe tambahan membuat sidik awal yang sah, kemudian mengubah nilai, sikap, dan kehadiran satu per satu. Ringkasan tetap sama dengan matriks dan hitungannya benar-benar turun. |
| **F-4: tujuan kartu** | Ditutup setelah tambahan koreksi pada cakupan tujuan Pendidikan dan artikel bahan yang ditampilkan. PSB Baru, pembayaran, gagal kirim, antrean/izin >24 jam, penempatan gabungan, rekomendasi, sikap pengurus/murobi, pelanggaran bulan berjalan, laporan ambang, serta bahan perlu perbaikan memakai filter layanan/server yang sesuai. Daftar berhalaman tetap menghitung seluruh hasil, bukan hanya halaman pertama. |

Ketentuan penafsiran yang diterima pada audit pertama tetap berlaku: Guru mencakup tugas Murobi sesuai peta menu final; H/J-6 tetap Tahap 4; data pembimbing bagi orang tua Z tetap Tahap 5; profil binaan AB dan pengalihan penugasan G-1 tetap Tahap 6; kerangka halaman lama tetap Tahap 3. Kontrak penolakan notifikasi pemilik lain tetap HTTP halaman 200 tanpa isi/data milik pihak lain. AQ, AK, A-1/A-3/A-4, menu A–K, empat tempat prioritas L/P/AA, dan tampilan tiga ukuran tetap lulus regresi terkait.

## Koreksi terarah auditor

### POST pemroses perizinan bersama

Pada `e9b5b8c`, `Peran::gate()` menahan POST halaman milik peran lain, tetapi `/portal/izin_aksi.php` adalah alamat bersama. Dari peran Guru akun admin+guru, POST `aksi=buat&mode=admin` dan `aksi=tetapkan` tanpa mode masih mencapai validasi workflow (**422** dengan ID kosong), bukan ditahan dengan pilihan peran. Tidak ada data yang ditulis oleh probe sebelum koreksi karena sengaja memakai ID kosong.

`portal/izin_aksi.php` kini mengambil mode dari resolver sesi dan memeriksa pasangan aksi–kapasitas sebelum workflow: buat/batalkan untuk Admin/Pengurus, putuskan untuk Admin/Murobi, tetapkan/koreksi untuk Admin. `portal/_guard.php` membaca mode POST dari body dan menawarkan perpindahan eksplisit bila berbeda. Kedua probe kini **403 + Buka sebagai Admin**; regresi tindakan perizinan normal **36/0**. Workflow/API bisnis tidak diubah.

### Cakupan dan isi daftar perbaikan

Probe HTTP menambahkan sementara hak Admin pada akun Pendidikan fixture dan satu kelas berjenjang lain dengan bahan perbaikan. Sebelum koreksi, Beranda sudah membatasi kelas sendiri, tetapi tujuan `/portal/verifikasi_semester.php?status=Dikembalikan` masih memuat kelas pengawasan Admin (**gagal 1 pemeriksaan**). Kini opsi halaman pada peran Pendidikan memakai cakupan penugasannya; GET/POST rencana di luar cakupan meminta **Buka sebagai Admin** sebelum membaca/bertindak. Setelah memilih Admin, pengawasan kelas tersebut tetap tersedia.

Penyaringan matriks semula hanya menghapus santri tanpa perbaikan, sehingga bahan lain milik santri yang sama ikut dirender. Auditor menambahkan penyaringan artikel bahan berdasarkan status efektif Dikembalikan. Probe memastikan jumlah artikel sama dengan angka kartu. Matriks sumber, fingerprint finalisasi, dan aturan layanan tetap utuh; filter hanya menentukan artikel yang ditampilkan.

## Penerimaan daftar pemantauan rekomendasi Admin

**Diterima tanpa memerlukan keputusan hak akses baru.** B-1/L/AA mewajibkan kartu membuka daftar tersaring; cetak biru tidak mensyaratkan Admin membuka kasus sendiri. PRD V3 menetapkan pembimbing aktif sebagai pengelola operasional dan Admin sebagai pengawas; `KonselingService::createCase()` tetap memeriksa penugasan pembimbing.

Probe audit pertama mewajibkan `rekomendasi_ids[]` pada akun Admin murni. Itu terlalu spesifik terhadap formulir operasional dan melampaui kriteria daftar B-1. Pada commit koreksi Claude, probe asli dijalankan tanpa perubahan: **9 lulus/1 gagal**, hanya assertion ini. Auditor memperbaiki oracle pengujian secara terbuka:

- Daftar Admin dibandingkan berdasarkan **ID rekomendasi** tertunda yang sah dan jumlah kartu, bukan keberadaan checkbox.
- Halaman Admin menjelaskan bahwa daftar untuk pemantauan dan tindak lanjut oleh pembimbing.
- Pembimbing tetap mendapat `rekomendasi_ids[]` pada halaman yang sama.
- Pemanggilan server `createCase()` oleh Admin murni tetap **ditolak 403** tanpa penugasan pembimbing.

Tidak ada pemberian hak Admin untuk membuka kasus dan tidak ada perubahan kewenangan V3. Tombol kartu “Lihat” sesuai fungsi pemantauan tersebut.

## Pengujian independen

Urutan yang diminta dijalankan dari root: persiapan DB → statis → HTTP → buat ulang DB → server 8941 → probe asli → browser Tahap 2. Setelah koreksi auditor, HTTP, probe yang diperluas, browser Tahap 2, dan regresi relevan dijalankan lagi.

| Pengujian akhir | Hasil |
| --- | --- |
| `bash tests/redesain_web_tahap2_db.sh` | dua kali exit 0 sebelum suite yang diminta; hanya DB salinan Tahap 2 |
| `php tests/redesain_web_tahap2_static.php` | **122/0**, exit 0 |
| `php tests/redesain_web_tahap2_http.php` | **191/0**, sebelum dan sesudah koreksi auditor |
| `DB_NAME=test_redesain_t2_test php tests/redesain_web_tahap2_audit.php` | **29/0**, exit 0; sebelumnya probe asli **9/1** dan probe POST tambahan membuktikan dua celah F-1 |
| Browser Tahap 2, **375/768/1280px** | **89/0**, sebelum dan sesudah koreksi auditor |
| Browser Tahap 1, seluruh menu **sembilan akun × tiga ukuran** | **107/0**, tanpa galat JS |
| Notifikasi V2 Fase 4, DB Tahap 2 | **46/0**; WN-3b kini lulus dengan ringkasan teks yang dipulihkan Claude |
| Penempatan web, DB Tahap 2 (port 8946) | **47/0** |
| Perizinan V2 Fase 2 web, DB Tahap 2 | **36/0** |
| Laporan V3 Fase 5 integrasi dan akses, `webalhasan_v3_phase1_test` | **43/0**, **5/0** |
| Regresi statis Tahap 1, perapihan, penugasan, penempatan, V2 Fase 1/2/4, V3 Fase 4 | **69/132/282/138/127/175/289/18 lulus**, seluruh exit 0 |
| Lint PHP yang berubah sejak `d6c5da0` | **61 berkas, 0 gagal**; JS syntax exit 0; `git diff --check` bersih |

Probe tambahan F-3 dimulai dengan **3 Terverifikasi/9 Dikembalikan** yang sidiknya sah. Setelah nilai berubah: **3/8**; sikap berubah: **2/8**; kehadiran berubah: **2/7**. Pada setiap langkah, hasil sama dengan `VerifikasiService::read()`. Semua perubahan layanan di-rollback.

Fixture HTTP Admin+Pendidikan dibuat terlihat server lalu dibersihkan di `finally` (kelas/rencana/bahan/penempatan tambahan dihapus, penugasan dan hak fixture dipulihkan). Login hanya memperbarui metadata login di DB uji. Tidak menyentuh dump pribadi, DB produksi, aplikasi mobile, atau direktori pekerjaan lain yang belum terlacak.

## Batas dan handoff

- Chromium lokal. Belum Safari/iOS, Firefox, perangkat fisik, pembaca layar, atau axe. Tangkapan Admin 375/768/1280, Pendidikan HP, dan pilihan peran desktop diperiksa langsung; pengukuran otomatis mencakup seluruh menu sembilan akun.
- Tidak mengulang matriks dua server baseline, seluruh suite V4/PDF/concurrency, push fisik, mobile, atau smoke cPanel/produksi. Tidak ada klaim kelulusan pengujian yang tidak dijalankan.
- Biaya query lonceng sudah dikoreksi di README menjadi tiga SELECT tambahan. Kinerja data produksi, termasuk pembangunan peta peran dan ringkasan per kelas, belum diukur; ini tetap batas nonblokir yang tercatat.
- Empat temuan P2 audit pertama sudah ditutup dalam keadaan kode akhir. Bukti kegagalan awal/probe sebelum koreksi tetap disertakan untuk ketertelusuran.
- **Tahap 2 lulus; berhenti untuk Human Developer. Tahap 3 belum dimulai.** Tidak merge/deploy/migrasi produksi.
