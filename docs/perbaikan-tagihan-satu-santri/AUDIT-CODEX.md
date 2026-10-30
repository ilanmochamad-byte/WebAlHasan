# Audit Codex — Perbaikan tagihan satu santri dari tarif

30 Oktober 2026. **LULUS audit teknis lokal, tanpa koreksi kode aplikasi.** Penerbitan satu santri memakai tarif dan penyesuaian, mempertahankan pembatasan hak serta kompatibilitas massal pada skenario yang diuji. Tidak ditemukan temuan baru yang memblokir perbaikan ini. Kelulusan ini tidak menutup gerbang produksi V6/V7 atau menjadi izin merge/deploy/rilis.

## Baseline dan lingkup

- Branch `claude/perbaikan-tagihan-satu-santri`; baseline `0b2fcd1ffe56ca7fb093749275830655ff95a92b` (merge PR #99), commit Claude `3d6ca62b3c4ba673c0129fd05eb035db01e7f21b`. Kedua hash remote diverifikasi sebelum pengujian. Tidak membuat worktree atau menjalankan agen paralel.
- Dibaca: AGENTS terbaru beserta keputusan Human Developer 30 Oktober, PRD V6, audit V6 sebelumnya dan batas gerbang akhir, seluruh diff implementasi, kontrak API, README/handoff serta sumber/bukti pengujian Claude. Pilihan Human Developer adalah memakai tarif pada tombol satu santri; bukan perintah mengubah seluruh kontrak API manual lama.
- Perubahan aplikasi hanya `TagihanService`, `TagihanRepository` dan `portal/bsa_tagihan_buat.php`. Tidak ada migrasi, perubahan BsaService, otorisasi, notifikasi, endpoint/rute API, halaman Penerbitan massal atau sumber aplikasi mobile. Auditor menambah probe dan dokumen; tidak mengoreksi kode aplikasi.
- PHP 8.4.14, MariaDB 12.3.2 lokal, Chromium Playwright; hanya DB salinan `test_*_test` berfixture sintetis. Probe baru menggunakan salinan terpisah hasil integrasi, sehingga tidak berbagi mutasi dengan runner regresi.
- Direktori `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/` dan `output/` tidak disentuh/di-commit. Repo mobile hanya dibaca; branch dan dua berkas paket milik pekerjaan lain tidak diubah.

## Pemeriksaan perubahan

1. **Nominal dan sasaran.** `santri_id` diparsing dengan validasi ID, terikat parameter SQL dan ikut hash pratinjau serta idempotensi hanya bila ada. Rencana tarif/penyesuaian, snapshot, audit dan notifikasi memakai layanan penerbitan yang sama dengan massal. Web mengambil sasaran dari URL dan mengutamakannya terhadap kiriman body. Tarif Rp350.000 − potongan Rp50.000 menjadi tepat satu tagihan Rp300.000; santri SMP lain tidak ikut.
2. **Otorisasi dan replay.** Hanya petugas BSA aktif; admin, termasuk admin+petugas, serta wali tidak memutasi. Santri nonaktif/tidak ditemukan/di luar unit ditolak. Jalur baru memeriksa ulang santri sebelum pengembalian replay. Mempersempit cakupan petugas setelah terbit membuat replay SMP 404 tanpa mutasi. Kunci yang sama untuk santri berbeda ditolak 409.
3. **Integritas snapshot.** Penyesuaian/tarif yang berubah setelah pratinjau membuat hash basi ditolak; meninjau ulang mengambil nominal baru. Komponen/total palsu dari kiriman diabaikan pada jalur tarif. Mengganti tarif masa depan tidak mengubah tagihan lama.
4. **Kompatibilitas massal.** Respons lengkap dan hash pratinjau tanpa `santri_id` dibandingkan pada sumber baseline `0b2fcd1` dan sumber perbaikan, pada DB/input yang sama: identik, empat baris SMP, tanpa field komponen/target tambahan. Bukti `kompatibilitas-massal.json`. Probe serta integrasi menguji penerbitan massal setelah penerbitan satu santri tanpa ganda.
5. **Fallback manual.** Halaman memeriksa ulang tarif saat POST; bila unit bertarif, mode manual/formulir lama ditolak 422. Tanpa kelas atau tanpa tarif, isian manual tetap tersedia dan retry tetap memakai layanan Fase 1. CSRF dan no-store tetap melalui `_bsa.php` yang tidak berubah. Teks/nama tarif dan penyesuaian di-escape ketika ditampilkan.

## Bukti independen auditor

Keluaran baru di `audit-codex/`, tidak mengganti bukti implementator. Seluruh runner selesai; hasil aktual dibandingkan dengan bukti handoff `3d6ca62` melalui `perbandingan-kegagalan.json`.

| Pemeriksaan independen | Hasil |
| --- | --- |
| Statis perbaikan dan lint PHP berkas baru/berubah | 24/0 |
| Integrasi perbaikan | 27/0 |
| HTTP halaman web dan API penerbitan | 25/0; log 0 galat PHP |
| Browser 375/768/1280 dan penerbitan tanpa JavaScript | 11/0; log 0 galat PHP |
| Probe auditor: manipulasi nominal, rollback, proses serentak, cakupan/replay | 16/0 |
| Kontrol sumber baseline: respons/hash massal tanpa santri_id | Identik pada DB/input yang sama |
| V6 Fase 1 HTTP / probe audit / browser | 96/8 (oracle historis) · 37/0 · 59/0 |
| V6 Fase 2 integrasi / HTTP / probe audit / browser | 179/0 · 110/0 · 30/0 · 76/0 |
| V6 Fase 3 integrasi / HTTP / browser | 137/0 · 66/0 · 89/0 |
| V6 Fase 4 HTTP, termasuk proyeksi/notifikasi/hak wali | 63/0 |
| V6 Fase 5 integrasi / HTTP / verifier setelah HTTP | 71/0 · 42/0 · 36/0 |
| Seluruh 45 suite statis | 18 exit 0 · 25 exit 1 · 2 exit 77 |

**Tidak seluruh regresi hijau.** Seluruh **61 rangkaian yang mempunyai padanan di ringkasan handoff** memiliki exit/hitungan identik. Runner auditor juga memasukkan statis perbaikan ke loop (`24/0`), sehingga ada 62 baris rangkaian dengan exit, termasuk 45 statis; lihat `perbandingan-status.json`. Dari 26 suite dengan pesan gagal, **17 identik literal**; sembilan lainnya hanya berbeda pada daftar berkas di luar cakupan fase lama, kini menambahkan probe/dokumen auditor. Tidak ada jenis kegagalan baru. Rincian penuh tetap disimpan dalam JSON dan stdout per suite.

HTTP Fase 1 masih mengharapkan transfer tanpa centang langsung sah, padahal sejak Fase 3 harus Menunggu. Statis lama mengunci batas migrasi/API/berkas pada fase historis; tiga perbedaan terhadap kontrol main yang dilaporkan Claude tetap merupakan larangan berkas lama berubah (`v6_phase5_static`, `v7_phase3_static`, `v7_phase5_static`), bukan kegagalan nominal/hak. Kontrol main Claude mempunyai kesalahan pencatatan exit sebagaimana README; auditor tidak memakai angka exit kontrol itu sebagai bukti. Perbandingan audit ini memakai exit aktual runner dan pesan gagal pada handoff. Dua statis V3 exit 77 karena syarat lingkungan/DB tetap, dan statis paket mobile tetap membaca paket kerja milik pekerjaan lain. Oracle tidak diubah/dilonggarkan.

Pengulangan: `bash docs/perbaikan-tagihan-satu-santri/audit-codex/ulang-uji.sh` dari repo web, sekuensial. Untuk probe tambahan, salin DB hasil integrasi `test_v6_satu_santri_test` ke DB terpisah `test_v6_satu_santri_audit_test` yang baru, lalu jalankan `V6_RUN_AUDIT=1 DB_NAME=test_v6_satu_santri_audit_test php tests/v6_tagihan_satu_santri_audit.php`. Bangun ulang salinan sebelum mengulang probe karena ia menulis transaksi sintetis.

Probe tambahan `tests/v6_tagihan_satu_santri_audit.php` dan pekerja proses terpisah `tests/v6_tagihan_satu_santri_worker.php` menghasilkan **16/0**:

- kiriman total/komponen Rp1 tetap menghasilkan tarif − potongan Rp300.000 dan satu sasaran;
- trigger kegagalan INSERT audit wajib membatalkan tagihan, komponen, penyesuaian, riwayat, batch/hasil, idempotensi, audit dan notifikasi; retry dengan kunci yang sama baru sekali berhasil, berikutnya replay;
- enam proses/koneksi dengan isi/kunci sama menghasilkan satu batch/tagihan dan lima replay, tanpa 500;
- dua aktor/kunci berbeda untuk santri–periode yang sama menghasilkan satu sukses dan satu konflik 409/422, tanpa duplikasi;
- dua sasaran berbeda bersamaan menghasilkan tepat dua tagihan;
- satu-santri versus massal bersamaan menghasilkan satu pemenang dan satu konflik aman; setelah tinjau ulang, semua SMP mendapat tepat satu tagihan, Tsanawi tidak ikut;
- cakupan dipersempit menutup replay; penggantian tarif menolak pratinjau lama dan snapshot lama tetap; verifier Fase 1–3 lulus.

Kontrol pembantu awal berhenti setelah tiga assertion lulus karena nama tabel penghitung salah (`bsa_penerbitan_hasil` alih-alih `bsa_penerbitan_santri`). `probe-kontrol-pembantu.txt` dipertahankan. Nama tabel pembantu diperbaiki, DB audit disalin ulang, seluruh probe diulang 16/0. Ini bukan cacat aplikasi atau oracle yang dilonggarkan.

Auditor memeriksa visual tangkapan 375px hasil eksekusinya sendiri untuk halaman bertarif dan fallback manual. Nominal dan potongan terbaca, tombol terbit dapat dijangkau melalui gulir; suite juga menguji 768/1280px, alur dari rincian santri dan penerbitan tanpa JavaScript 375px. Aset CDN luar dibatasi oleh pembantu browser lokal; pemeriksaan ini bukan smoke jaringan hosting atau perangkat fisik.

## Penafsiran dan batas

P-1…P-4 README diterima dalam lingkup perbaikan ini:

- Penerbitan satu santri memakai satu `bsa_penerbitan` dengan sasaran 1; label unit riwayat dapat tetap "Seluruh cakupan" ketika saringan unit kosong. Jumlah dan rincian sasaran tetap satu; tidak menambah kolom/migrasi untuk label.
- **API manual lama `POST /v6/bsa/tagihan` tetap menerima komponen manual**, termasuk untuk santri bertarif. Pembatasan fallback pada halaman ini bukan larangan global API. Kontrak dan BsaService baseline tidak diubah; kebijakan penutupan API manual memerlukan workstream/keputusan tersendiri bila diinginkan Human Developer.
- Bila tarif ditambahkan setelah formulir manual lama dibuka, POST manual web ditolak 422; tagihan yang sudah terbit tidak berubah. Tidak menambahkan pengecualian replay baru untuk perubahan keadaan ini.
- Aplikasi tidak mendapat tombol satu-santri baru; penerbitan aplikasi tetap lewat massal bertarif yang kompatibilitasnya diuji melalui layanan/API. Tidak menjalankan Expo/native/Push karena sumber mobile tidak berubah.

Hanya interleaving proses yang disebutkan diuji, bukan seluruh kemungkinan atau beban produksi. Tidak menguji ulang downgrade skema sebelum 028, migrasi/restore produksi, MySQL/cPanel, seluruh V1–V5/PSB, perangkat fisik atau UAT. Tidak ada perubahan pada SQL maupun modul lama tersebut; regresi sesi ini fokus V6 dan seluruh suite statis. Gerbang produksi V5/V6/V7 dari audit terdahulu tetap terbuka.

## Handoff

Perbaikan memenuhi tujuan Human Developer pada lingkungan uji: rincian tarif dan penyesuaian ditampilkan tanpa mengetik ulang komponen; penerbitan hanya satu santri, tidak ganda, tidak memotong tabungan, berjejak dan mengikuti hak. Penerbitan massal serta transaksi/laporan V6 terkait tetap sesuai regresi. Tidak ada koreksi kode aplikasi auditor; hanya probe, bukti dan status audit ditambahkan.

Commit/push dilakukan hanya pada branch `claude/perbaikan-tagihan-satu-santri`; hash audit akhir dapat dibaca pada riwayat Git. Keputusan merge/deploy tetap Human Developer. Auditor berhenti; tidak mengubah mobile, menjalankan migrasi produksi, melakukan merge/deploy/rilis atau memulai fase/workstream berikutnya. Bukti lokal ini tidak menggantikan smoke cPanel/produksi dan gerbang operasional yang masih terbuka.
