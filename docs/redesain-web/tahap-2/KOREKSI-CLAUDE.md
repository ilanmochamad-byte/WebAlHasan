# Koreksi implementator atas audit Codex Tahap 2 — 23 Oktober 2026

Status: **koreksi F-1 s.d. F-4 selesai di sisi implementator, menunggu audit ulang Codex.** Ini bukan klaim lulus. Belum di-merge, belum dideploy, tanpa migrasi produksi. Tahap 3 belum dimulai.

- Branch `claude/redesain-web-tahap-2`, di atas commit audit `964e21f` (audit atas implementasi `9a5d764`). Tidak ada worktree.
- Acuan: `AUDIT-CODEX.md` (F-1–F-4 dan catatan nonblokir), cetak biru A-2, A-5, B-1–B-4, keputusan L, P, AA, AR.

## F-1 · Peran aktif tidak lagi berpindah sendiri (A-2, A-5, AR)

| Berkas | Perubahan |
| --- | --- |
| `app/Ui/Layout.php` | Blok perpindahan peran otomatis dan pesan "Peran beralih ke …" dihapus. Kerangka tidak pernah memanggil `Peran::set()`. |
| `app/Ui/Peran.php` | `Peran::gate()` (baru): untuk akun berperan ganda, bila halaman yang diminta termasuk peta menu peran LAIN milik akun tetapi tidak termasuk peta peran aktif, permintaan ditolak **403** dengan pilihan eksplisit. Berlaku untuk GET dan POST, sehingga tindakan tidak terjadi di bawah peran yang tidak dipilih. Halaman di luar peta semua peran (pemroses lama, ekspor, JSON) tidak dipersempit; guard halamannya yang memutuskan. Akun berperan tunggal tidak terpengaruh. Hanya mempersempit, tidak pernah memberi hak. |
| `app/Auth/Authorization.php` | `requireWebUser()` memanggil `Peran::gate()` setelah pemeriksaan kata sandi wajib, sebelum halaman bekerja. |
| `app/Ui/Denial.php` | `Denial::otherRole()` (baru): halaman akses ditolak di kerangka dengan "Halaman ini untuk peran …", tombol **"Buka sebagai …"** (formulir POST ber-CSRF ke `portal/peran.php`, yang memeriksa ulang hak nyata akun dan kembali ke halaman semula), opsional "Lanjutkan sebagai …", dan Kembali ke Beranda. |
| `portal/_guard.php` | `?mode=` lama untuk peran lain akun ini tidak lagi menyetel sesi lewat GET; pengguna mendapat "Buka sebagai …" atau "Lanjutkan sebagai <peran aktif>" (alamat yang sama tanpa `mode`). `?mode=` yang sama dengan peran aktif dibuka biasa; `?mode=` di luar hak akun tetap diabaikan. |

## F-2 · Beranda Guru/Bagian Pendidikan memakai cakupan penugasan sendiri

| Berkas | Perubahan |
| --- | --- |
| `app/Auth/Capabilities.php` | `featureOwnAppliesTo()` (baru, aditif): pencocokan cakupan hanya atas penugasan milik akun, tanpa hak pengawasan admin. Bukan pemeriksaan izin; `featureAppliesTo()` dan layanan bisnis tidak berubah. |
| `app/Beranda/BerandaService.php` | `kemajuanNilai()` (B-2) menyaring opsi `NilaiService` dengan cakupan guru mapel sendiri; `kemajuanKelas()` (B-4) menyaring rencana dengan cakupan Bagian Pendidikan sendiri. Hak pengawasan Admin pada layanan tetap utuh (diuji: `NilaiService::options()` tetap 2 mapel). |

## F-3 · Angka bahan rapor memakai status efektif seperti matriks

`BerandaService::ringkasBahan()` (baru) menghitung terverifikasi/perlu perbaikan dengan himpunan bahan dan aturan yang sama dengan `VerifikasiService::read()`: santri penempatan kelas semester itu, setiap mapel wajib + sikap + kehadiran; baris `v4_bahan` hanya berlaku bila sidik sumbernya (`BahanRepository::fingerprint`) sama dengan sumber terkini, selain itu Draf/perlu verifikasi ulang; tanpa baris pemeriksaan memakai status awal yang sama dengan matriks. Sumber dibaca per rencana dengan prepared statement (tipe nilai sama dengan pembacaan matriks), bukan per santri. Dipakai Beranda Bagian Pendidikan (persentase, perlu perbaikan) dan kartu admin "Bahan rapor perlu perbaikan".

Uji kesetaraan (`tests/redesain_web_tahap2_http.php` H2-H) membandingkan ringkasan dengan matriks pada lima keadaan dalam transaksi yang di-rollback: fixture awal (1 sah, 1 sikap bersidik lama, 2 dikembalikan), semua tersimpan Terverifikasi dengan sidik campuran, nilai berubah, sikap berubah, kehadiran berubah (peserta pertemuan baru).

## F-4 · Tujuan kartu berisi himpunan yang dihitung

Setiap angka kini dihitung dengan layanan atau filter server yang sama dengan daftar tujuannya:

| Kartu | Hitungan | Tujuan |
| --- | --- | --- |
| Izin menunggu penetapan murobi | `izin_service()->queueCount(admin)` | `izin_antrean.php` (antrean admin, sama) |
| Pendaftar PSB baru | status `Baru` | `admin_data.php?status=Baru` — saringan status baru dengan daftar tetap (juga dipakai `export_psb.php` agar "Export Sesuai Filter" sama) |
| Pembayaran PSB belum lunas | pembayaran terhubung pendaftar, `Belum Lunas` | `admin_pembayaran_psb.php?status=Belum Lunas` (saringan yang sudah ada) |
| Notifikasi gagal terkirim | `notification_admin_service()->kegagalan()` | `admin_notifikasi.php#pengiriman-gagal` (daftar yang sama, anchor baru) |
| Izin belum diputus > 1 hari | `izin_service()->list(status=Diajukan, tertunda=1)` | `izin.php?status=Diajukan&tertunda=1` — saringan `tertunda` baru di `IzinRepository::conditions()` (diajukan > 24 jam), dengan kotak centang di halaman |
| Bahan rapor perlu perbaikan | status efektif `Dikembalikan` (F-3) | `verifikasi_semester.php?status=Dikembalikan` (daftar kelas berisi perbaikan) atau `?rencana_id=…&status=Dikembalikan` bila hanya satu kelas — matriks disaring ke santri yang bahannya Perlu Perbaikan |
| Santri tanpa kelas atau kamar | `penempatan_service()->listPage(status=tanpa_tempat)` | `admin_penempatan_santri.php?status=tanpa_tempat` — status penempatan baru "Belum mempunyai kelas atau kamar" (gabungan, tidak lagi memilih salah satu) |
| Rekomendasi pembinaan belum ditindaklanjuti | `v3_konseling_service()->options()` (rekomendasi yang sama dengan formulir konseling) | `v3_konseling.php#rekomendasi` — bagian baru "Rekomendasi belum ditindaklanjuti (n)" untuk semua pembaca Konseling; pembimbing memilihnya pada formulir "Buka kasus konseling" (`rekomendasi_ids[]`). Tombol kartu admin "Lihat". |
| Pengurus: Sikap belum diisi | `SikapService::filterOptions(options(pembimbing), 'belum')` | `sikap_semester.php?status=belum` |
| Pengurus: Pelanggaran bulan ini | `LaporanService::read(jenis=pelanggaran, mulai=awal bulan, aktif=1)` | `v3_laporan.php?jenis=pelanggaran&mulai=…&aktif=1` — saringan `aktif` baru (tanpa yang dibatalkan) |
| Pengurus: Santri mencapai ambang (AA) | `LaporanService::read(jenis=ambang)` | `v3_laporan.php?jenis=ambang` — jenis laporan baru "Santri mencapai ambang": satu baris per santri dalam cakupan, total poin agregat semester aktif ≥ ambang aktif terendah, dengan label ambang tercapai |
| Murobi: Sikap binaan perlu diketahui | `SikapService::filterOptions(options(murobi), 'perlu_diketahui')` | `sikap_semester.php?mode=murobi&status=perlu_diketahui` |
| Bagian Pendidikan: Perlu perbaikan | status efektif (F-3) | `verifikasi_semester.php?status=Dikembalikan`; tabel per kelas menautkan "n perlu perbaikan" ke kelas itu |

**Catatan untuk auditor (probe `tests/redesain_web_tahap2_audit.php`, cek "B-1/AA … rekomendasi_ids[]").** Akun admin murni tidak dapat membuka kasus konseling: `KonselingService::createCase()` mewajibkan penugasan pembimbing aktif (aturan PRD V3, "sistem tidak membuat kasus otomatis"), sehingga formulir berisi `rekomendasi_ids[]` memang tidak dirender untuk admin. Tujuan kartu admin kini memuat daftar rekomendasi yang sama persis dengan hitungannya untuk pemantauan; tindak lanjut tetap oleh pembimbing di halaman yang sama (diuji: `sbx_pengurus_a` melihat `rekomendasi_ids[]` di `v3_konseling.php`). Memberi admin hak membuka kasus berarti perubahan hak akses/bisnis V3 di luar Tahap 2; mohon auditor menilai apakah daftar pemantauan ini memenuhi B-1, atau meminta keputusan Human Developer.

## Catatan nonblokir audit

- Biaya query lonceng: README butir 11 dikoreksi menjadi tiga SELECT notifikasi tambahan per halaman.
- WN-3b (`v2_phase4_web_smoke.php`): pusat notifikasi kini menulis ringkasan "n notifikasi belum dibaca." / "Semua sudah dibaca." di keterangan halaman, sehingga kontrak teks lama terpenuhi tanpa mengubah uji.

## Pengujian yang ditambah/diubah

- `tests/redesain_web_tahap2_http.php`: H2-C diganti perilaku F-1 (403 + Buka sebagai, sesi tetap, POST ditolak sebelum halaman bekerja, Buka sebagai kembali ke halaman, `?mode=` lama, tautan detail izin di peran Bagian Pendidikan, akun tunggal tidak terpengaruh); H2-H baru untuk F-2, F-3, F-4 (isi setiap daftar tujuan dibandingkan dengan angka kartu dan basis data, termasuk pekerjaan yang tidak memenuhi syarat).
- `tests/redesain_web_tahap2_seed.php`: bahan dikembalikan bersidik benar, satu bahan sah, satu bahan sikap bersidik lama, sikap diserahkan dua dari tiga santri, satu santri berkelas tanpa kamar di kelas terpisah tanpa rencana V4.
- `tests/redesain_web_tahap2_static.php`: pemeriksaan F-1–F-4.
- `tests/browser/uji-redesain-web-tahap2.mjs`: T2-C membuka halaman Admin dari peran Guru (403, peran tetap, tombol Buka sebagai Admin, lalu kembali ke halaman semula sebagai Admin).
- Probe auditor `tests/redesain_web_tahap2_audit.php` tidak diubah.

## Bukti koreksi

Lingkungan sama dengan README (PHP 8.4, Node 26, Chromium lokal). DB uji dibuat ulang dengan `tests/redesain_web_tahap2_db.sh` sebelum setiap suite yang mengubah data; sandbox `.env` tidak diubah fixture Tahap 2.

| Bukti | Hasil | Berkas `koreksi/` |
| --- | --- | --- |
| Statis Tahap 2 | **122/0** | `statis.txt` |
| HTTP Tahap 2 (termasuk H2-C baru dan H2-H F-2/F-3/F-4) | **191/0** | `http.txt` |
| Browser Tahap 2, 375/768/1280 (termasuk Buka sebagai) | **89/0** | `browser.txt` |
| Probe auditor `tests/redesain_web_tahap2_audit.php` (tidak diubah) | **9 lulus / 1 gagal** (sebelumnya 4/6). Sisa: cek `rekomendasi_ids[]` untuk akun admin — lihat catatan F-4 di atas | `probe-auditor.txt` |
| Browser Tahap 1 di DB Tahap 2, sembilan akun × semua menu × 3 lebar | **107/0** | `regresi.txt` |
| Regresi DB sandbox asli: 22 suite statis (20 exit 0, 2 gate DB V3 exit 77 seperti baseline); `php -l` 379 berkas; smoke HTTP perapihan/alumni/kredensial/penempatan/penugasan 56/61/51/47/85; web V2 Fase 2/4/5 36/46/79; API Fase 4 92/0; navigasi murobi 40/0; integrasi V2 Fase 2/4, perapihan, penugasan 94/122/53/161; browser perapihan 56/0; browser Tahap 1 91/0; banding baseline `d6c5da0` 91 alamat (hanya notifikasi guru 403→200 sesuai AK), tanpa geser horizontal | lulus | `regresi.txt`, `banding-baseline.tsv` |
| V3 Fase 5 integrasi/akses (`webalhasan_v3_phase1_test`, laporan V3 yang diubah) | **43/0**, **5/0** | `regresi.txt` |
| Notifikasi V2 Fase 4 di DB Tahap 2 (WN-3b auditor) | **46/0** | `regresi.txt` |
| Tangkapan terpilih | — | `tangkapan/` |

## Batas

- Hanya Chromium; Safari/iOS, Firefox, perangkat fisik, pembaca layar, axe belum dijalankan.
- Gerbang peran menambah pembangunan peta menu untuk setiap peran akun berperan ganda pada setiap permintaan halaman (dibaca dari cache per request; akun berperan tunggal tidak terkena). Kinerja Beranda (`ringkasBahan` lima query per kelas, `SikapService::options`, `LaporanService::read`) belum diukur pada data produksi.
- Suite V4 lama (`webalhasan_v4_fase2_test`) tidak tersedia di mesin ini sehingga `SikapService::filterOptions()` (aditif, baca-saja) hanya diuji lewat suite Tahap 2.
- Tidak ada smoke cPanel/produksi.

## Handoff ke auditor (Codex)

Mohon audit ulang Tahap 2 khusus F-1 s.d. F-4 dan regresinya. Urutan reproduksi dari root repo:

```bash
bash tests/redesain_web_tahap2_db.sh
php tests/redesain_web_tahap2_static.php
php tests/redesain_web_tahap2_http.php
bash tests/redesain_web_tahap2_db.sh
DB_NAME=test_redesain_t2_test php -S 127.0.0.1:8941 tests/redesain_web_tahap1_router.php &
DB_NAME=test_redesain_t2_test php tests/redesain_web_tahap2_audit.php
BASE_URL=http://127.0.0.1:8941 node tests/browser/uji-redesain-web-tahap2.mjs
```

Tahap 3 tidak dimulai sebelum Tahap 2 lulus audit dan ada instruksi Human Developer.
