# Audit independen Codex — PRD V6 Fase 2

**Putusan 25 Oktober 2026: LULUS kriteria wajib Fase 2 setelah empat koreksi terarah.** F-1…F-4 ditutup dengan pengujian; tidak ada temuan Fase 2 yang masih memblokir. Ini bukan kelulusan V6 keseluruhan atau izin rilis parsial. Audit hanya Fase 2; tidak memulai Fase 3, mengubah mobile, merge, deploy, migrasi produksi, atau merilis V6.

## Konteks dan baseline

- Branch tetap `claude/prd-v6-fase-2`, tanpa worktree/perpindahan checkout. Handoff implementator menyatakan berhenti; pemeriksaan proses menemukan sesi Claude terbuka dalam keadaan tidur tanpa pekerjaan shell aktif. Auditor tidak menghentikan proses milik pengguna.
- Baseline audit Fase 1 `e895dbe2a95ee7cb75acce9a3edbadf23c239a92`. Satu commit baru yang diperiksa seluruhnya: `b50eda5ee63ebd9d1bf4ed763162475bbd5de457`. `git ls-remote` mengonfirmasi kedua ujung branch dan `origin/main` `a0f939ade79d47a966851eab855e1a6c6f2093de`; diff baseline audit terhadap `origin/main` kosong.
- Dibaca: `AGENTS.md`, seluruh `PRD-V6.md`, prompt fase, audit Fase 1, README/bukti Fase 2, diff seluruh commit, layanan/repository/halaman BSA, rute API, migrasi/rollback 028, preflight/verifier, serta sumber pengujian. Hasil implementator tidak dipakai sebagai hasil eksekusi auditor.
- Mobile hanya dibaca: `origin/main` `f8ebba0a43493efde50a7a207933e86dc4b941d4`; checkout `claude/aset-appstore-header` `c3e096c` dengan perubahan `package.json`/`package-lock.json` milik pekerjaan lain, tidak disentuh. Tiga direktori belum terlacak `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/` tidak disentuh atau disertakan.
- PHP 8.4.14, MariaDB 12.3.2 lokal, Chromium Playwright; regresi PSB memakai Apache/php-fpm dan `.htaccess`. Semua mutasi memakai DB `test_*_test` dari salinan sandbox sintetis, bukan data produksi.

## Temuan dan koreksi terarah

### F-1 · P2 · Total dan baris snapshot dapat berasal dari pembacaan berbeda — ditutup

`terbitkanMassal()` menghitung total/hash dari penyesuaian aktif, kemudian membaca ulang penyesuaian sesudah INSERT header penerbitan. READ COMMITTED serta kunci baca santri memungkinkan petugas lain mencabut/menambah penyesuaian di antara dua pembacaan. Probe dua koneksi memakai named-lock pada trigger INSERT header untuk memaksa interleaving tersebut: potongan Rp10.000 dicabut sesudah total Rp90.000 dihitung dari komponen Rp100.000. Sebelum koreksi, tagihan berhasil terbit Rp90.000 tetapi tanpa baris potongan; verifier menolaknya.

Koreksi: snapshot penyesuaian menggunakan baris yang **sama** dengan perhitungan total/hash, termasuk alasan aslinya; tidak membaca ulang sumber yang dapat berubah. Kontrak pratinjau tidak bertambah alasan internal. Komponen tarif tetap disalin dari ID tarif yang dipilih (baris komponen tarif tidak diubah saat tarif diganti).

### F-2 · P2 · Hasil penerbitan memakai unit historis untuk hak atas santri — ditutup

`penerbitan()` menyaring baris memakai `bsa_penerbitan_santri.jenjang`, tanpa status/penempatan santri saat permintaan. Pada sesi/token petugas Tsanawi yang dibuat sebelumnya, rincian tagihan sudah 404 sesudah santri pindah ke SMP atau dinonaktifkan, tetapi layanan, web, dan API hasil penerbitan masih membuka identitas, ID tagihan serta nominalnya.

Koreksi: query hasil penerbitan memakai `joinUnit()` dan `syaratHak()` yang sama dengan daftar pembayaran/tunggakan; petugas hanya menerima santri aktif dalam cakupan **kini**. Admin tetap dapat mengaudit riwayat santri pindah/nonaktif. Unit pada hasil dan ringkasan jumlah batch tetap metadata historis; bukan dasar otorisasi rincian santri. Pemeriksaan hak atas batch tetap berlaku.

Probe awal `tests/v6_phase2_audit.php`: **15/9** (`audit-codex/probe-sebelum.txt`): tiga kegagalan snapshot/verifier F-1, enam kegagalan layanan/API/web F-2. Sesudah kedua koreksi, probe awal yang sama **24/0**; probe kemudian diperluas untuk F-3 di bawah.

### F-3 · P2 · Sisa pada replay transfer menunggu/pembatalan keliru — ditutup

`hasilPembayaranLama()` mengambil `sisa_sesudah` dari snapshot kuitansi. Transfer menunggu belum memiliki kuitansi sehingga replay mengembalikan sisa Rp0 atas tagihan Rp100.000. Setelah transfer Rp40.000 diverifikasi lalu dibatalkan, pembatalan pertama benar mengembalikan Rp100.000 tetapi replay mengembalikan Rp60.000 dari kuitansi sebelum pembatalan. Pembukuan DB tidak mengganda atau berubah; yang keliru adalah angka respons layanan/API.

Koreksi: replay mengembalikan status pembayaran serta sisa tagihan **saat permintaan ulang**, dari total dikurangi pembayaran sah. ID/nomor kuitansi tetap, snapshot historis kuitansi tetap utuh. Semantik ini dicatat di `docs/api-v1.md`. Probe perluasan sebelum koreksi **26/4** (`probe-replay-sebelum.txt`); empat kegagalan adalah dua operasi × layanan/API.

### F-4 · P2 · Koreksi tagihan era 027 menyisakan celah versi — ditutup

Tagihan yang terbit sebelum 028 sah tanpa baris riwayat versi 1 (migrasi 028 memang aditif, tanpa backfill). Revisi/pembatalan pertama sebelumnya langsung menulis versi 2, sehingga verifier menolak riwayat 1..versi yang tidak lengkap.

Koreksi hanya dalam transaksi koreksi tagihan: ketika versi terkunci masih 1 dan riwayat awal belum ada, repository menyalin total, jatuh tempo, penerbit, penugasan serta `created_at` asli ke riwayat Terbit **sebelum** UPDATE. Ini tidak mengarang waktu/aktor baru, tidak mengubah migrasi lama, dan ikut rollback bila operasi/audit gagal.

Probe baru `tests/v6_phase2_legacy_audit.php` membuat fixture keadaan era 027 dengan menghapus hanya riwayat awal sintetis sebelum koreksi: sebelum **2/5**, sesudah **7/0** (`probe-era027-sebelum.txt`, `probe-era027-koreksi.txt`), kemudian diperluas dengan tiga pemeriksaan rollback audit gagal menjadi **10/0** (`probe-era027-akhir.txt`). Revisi dilakukan petugas berbeda untuk memastikan versi awal tetap mengacu penerbit asli. Verifier diperiksa pada hasilnya.

## Bukti yang dijalankan auditor

Keluaran auditor berada di `audit-codex/`, terpisah dari `bukti/` implementator. Skrip ulang regresi: `KPSB_DIR=/private/tmp/<folder-privat> bash docs/phase-v6-2/audit-codex/ulang-regresi.sh`. Jalankan berurutan karena fixture berantai memakai DB perantara bernama tetap. Probe auditor memakai DB baru hasil `V6F2_DB=test_v6_f2_audit_test bash tests/v6_phase2_db.sh`, kemudian `V6F2_DB=test_v6_f2_audit_test V6_RUN_AUDIT=1 php tests/v6_phase2_audit.php` dan `DB_NAME=test_v6_f2_audit_test V6_RUN_AUDIT=1 php tests/v6_phase2_legacy_audit.php`.

| Pengujian auditor | Hasil akhir | Bukti di `audit-codex/` |
| --- | --- | --- |
| Statis Fase 2 + lint 33 PHP | **62/0** | `statis-akhir.txt` |
| Integrasi layanan, transaksi, konkurensi proses, snapshot dan preservasi | **179/0** | `integrasi-akhir.txt` (sebelum koreksi juga 179/0 di `integrasi.txt`) |
| HTTP web/API Fase 2 | **110/0** | `http-akhir.txt` (sebelum koreksi juga 110/0 di `http.txt`) |
| Probe baru F-1/F-2/F-3, layanan/web/API dan barrier dua koneksi | **30/0** | `probe-akhir.txt`; kontrol 15/9 dan 26/4 di atas |
| Probe koreksi tagihan era 027 + rollback ketika audit gagal | **10/0** | `probe-era027-akhir.txt`; kontrol awal 2/5 di atas |
| Migrasi/rollback/naik ulang 028, manifest, CHECK/FK dan preservasi | **36/0** | `regresi/migrasi-rollback-akhir.txt` (sebelum koreksi juga 36/0 di `migrasi-rollback.txt`) |
| Browser Fase 2 375/768/1280px, tanpa JS, PDF kuitansi | **76/0** | `regresi/browser-v6-fase2.txt`, `tangkapan/`, `kuitansi-pdfinfo.txt` |
| Regresi V6 Fase 1 integrasi / HTTP / probe audit / browser | **120/0 · 104/0 · 37/0 · 59/0** | `regresi/v6-fase1-*.txt` |
| Migrasi V6 Fase 1 pada kode Fase 2 / kontrol baseline audit `e895dbe` | **19/10 (oracle historis) · 29/0** | `regresi/v6-fase1-migrasi*.txt` |
| Keamanan PSB Apache/`.htaccess` HTTP / browser | **97/0 · 42/0** | `regresi/keamanan-psb-*.txt` |
| Fondasi penugasan | **161/0** | `regresi/penugasan-integrasi.txt` |
| V2 Fase 4 / Fase 5 integrasi | **124/0 · 143/0** | `regresi/v2-fase*-integrasi.txt` |
| V5 Fase 3 / Fase 5 integrasi | **82/0 · 95/0** | `regresi/v5-fase*-integrasi.txt` |
| HTTP redesain Tahap 5 / Tahap 7 | **106/0 · 122/0** | `regresi/redesain-tahap*-http.txt` |
| Rollback kosong berantai 028…024 / HTTP Tahap 6 setelah rollback | **30/0 · 132/0** | `regresi/redesain-tahap6-*.txt` |
| HTTP Tahap 2 / Tahap 3 | **185/6 · 166/1 (oracle historis identik)** | `regresi/redesain-tahap*-http.txt`, `regresi/perbandingan-http.txt` |
| Browser kerangka sembilan akun × tiga lebar | **91/0** | `regresi/browser-kerangka-sembilan-akun.txt` |
| Seluruh 36 suite statis | **19 exit 0 · 15 exit 1 · 2 exit 77** | `regresi/ringkasan.txt`, keluaran per suite, perbandingan di bawah |

Auditor memeriksa visual kuitansi 375px, rincian tagihan wali 375px, dialog revisi 375px, dan render PDF kuitansi hasil eksekusi browser auditor. PDF satu halaman A4 (594,96 × 841,92 pt), angka Rp100.000/Rp40.000/Rp60.000, cap dan tanda tangan utuh. Tidak ada overflow horizontal pada tiga lebar yang diuji; konten di bawah bilah bawah dapat dijangkau dengan menggulir. Log tiga server browser tidak mengandung galat PHP (`server-ringkasan.txt`); HTTP Fase 2 juga memeriksa log tanpa galat/referensi transfer.

## Penilaian persyaratan dan penafsiran

- **Tarif/penyesuaian per santri:** J-2/J-3/J-4, HTTP K-B/K-E; tarif unit sama menghasilkan komponen sama, potongan hanya pada santri pemilik; cakupan tarif dan alasan perubahan diperiksa server. Snapshot nominal terbit tetap meskipun tarif diganti. F-1 melengkapi interleaving pencabutan penyesuaian saat penerbitan.
- **Penerbitan idempoten dan satu tagihan santri/periode:** J-4/J-8; kunci sama mengembalikan ID batch sama, kunci lain tidak membuat tagihan kedua, hash basi ditolak 409. Kunci unik santri/periode juga menolak jalur manual Fase 1. Tagihan dibatalkan tetap memblokir penerbitan kedua; koreksi melalui revisi.
- **Rp40.000 + Rp60.000 atas Rp100.000:** J-5, HTTP K-C/K-E dan browser; dua pembayaran sah dengan kuitansi unik, sisa akhir Rp0. Rp100.001, Rp60.001 atau Rp1 sesudah lunas ditolak tanpa mutasi. Transfer menunggu belum mengurangi sisa, ikut membatasi pembayaran baru, wajib referensi dan verifikasi BSA. Probe F-3 menguji angka respons kiriman ulang.
- **Kuitansi/perubahan tarif:** J-6/J-7 dan HTTP K-C; nominal/komponen/versi tagihan lama serta snapshot kuitansi tetap sebelum/sesudah tarif berubah dan revisi. Kuitansi pembatalan mempertahankan nominal/nomor lama dengan status Dibatalkan; alasan/aktor tercatat pada entri penyeimbang. Tagihan tidak mendebit tabungan.
- **Koreksi, kegagalan dan konkurensi:** J-7/J-8/J-9; versi basi ditolak, revisi tidak menurunkan kewajiban di bawah pembayaran sah+menunggu, pembatalan beralasan, dua/delapan proses dengan koneksi terpisah tidak menghasilkan pembayaran berlebih/ganda. Trigger kegagalan audit membatalkan tagihan, tarif, pembayaran, kuitansi dan klaim bersama. F-4 melengkapi koreksi tagihan era 027.
- **Hak web/API:** J-1/J-10/J-11, HTTP K-D/K-E/K-G, serta probe F-2; admin termasuk admin+petugas hanya membaca, wali hanya anak dengan relasi aktif, kuitansi/ID ditebak ditolak, referensi/catatan/alasan internal disaring, sesi/token lama ditolak setelah relasi/penugasan dicabut. Hasil batch kini memakai hak santri saat permintaan.
- **Migrasi/preservasi:** migrasi 028 hanya menambah sembilan tabel, 28 FK dan 22 CHECK; tidak ALTER/DML tabel lama. Suite migrasi menyiapkan data 027 dahulu, membandingkan manifest ID/jumlah dan checksum 29 tabel identitas, penugasan, PSB, V2–V5 serta uang 027, menguji naik–turun–naik, nol yatim, SQL idempoten, penjaga rollback berdata, dan perilaku sebelum 028/sesudah rollback. Tagihan era 027 tidak diberi riwayat sampai tindakan koreksi pertama.
- Q-1…Q-16 README dinilai sesuai batas Fase 2: rupiah bulat, tarif efektif per unit, penyesuaian beralasan untuk penerbitan berikut, snapshot/revisi tercatat, jatuh tempo eksplisit, admin hanya baca, wali anak sendiri dan santri nonaktif hanya admin. Batas lima penyesuaian aktif serta satu transaksi massal adalah batas implementasi; fixture bukan bukti ukuran produksi. Tidak ada keputusan produk baru yang mengubah PRD. Tabungan lengkap/pembayaran silang, rekonsiliasi, notifikasi dan paritas mobile tetap Fase 3–5; bukan klaim sudah tersedia.

## Regresi historis dan ketidaksesuaian bukti awal

- V6 Fase 1 statis pada **commit implementator** ternyata **71/4**, bukan 72/3 dalam bukti implementator. Kontrol ekspor bersih `b50eda5` dengan konteks Git baseline yang sama juga **71/4** (`regresi/statis-v6_phase1-kontrol-b50eda5.txt`). Selain tiga oracle yang sudah dicatat (027 terakhir, daftar literal kunci audit, Fase 2 belum boleh ada), oracle “migrasi ≤026 tidak diubah” mengecualikan 027 saja dan ikut membaca pasangan **028**. Diff memastikan seluruh migrasi/rollback ≤027 tidak berubah. Tes historis tidak diubah/dilonggarkan; ini bukan regresi koreksi auditor.
- V6 Fase 1 migrasi mengasumsikan 027 migrasi terakhir dan CHECK seluruh `bsa_%` hanya 14. Sesudah 028, preflight/rollback 027 memang harus memblokir. Hasil pada kode Fase 2 dan kontrol baseline audit Fase 1 dicatat terpisah di tabel hasil.
- Empat belas suite statis PSB, fondasi, redesain, V2/V5 memiliki jumlah dan pesan kegagalan yang identik dengan bukti audit Fase 1 (`regresi/perbandingan-statis.json`); V6 Fase 1 statis dicatat terpisah di atas. Oracle lama membatasi jenis/menu, pratinjau AG, API/database fase lama, atau migrasi terakhir; bukan alasan untuk menyebut seluruh regresi hijau. V2 Fase 5 statis masih memeriksa versi paket pada checkout mobile milik pekerjaan lain; mobile tidak diubah. V3 statis Fase 2/3 exit 77 karena syarat DB bernama tetap tidak tersedia.
- HTTP Tahap 2/Tahap 3 masih mempunyai oracle menu/AG historis yang sama seperti bukti Fase 1/Fase 2. Rincian kegagalan, bukan hanya exit, tetap disimpan per suite. Bukan audit ulang lengkap seluruh V1–V5.

## Batas operasional dan handoff

1. Hanya MariaDB 12.3.2 lokal; **MySQL dan cPanel belum diuji**. CHECK, kolom STORED berindeks unik, READ COMMITTED, InnoDB, konfigurasi binlog serta izin hosting wajib diperiksa preflight sebelum rilis kelak.
2. Uji proses terpisah membuktikan interleaving yang dicoba, termasuk barrier deterministik F-1; bukan seluruh kemungkinan race. Dua request HTTP pada server PHP satu pekerja adalah bukti kontrak HTTP, bukan bukti konkurensi DB. Jaringan putus/proses mati tepat saat commit dan beban skala produksi belum disimulasikan.
3. Tidak ada pemeriksaan dana bank nyata, migrasi/backup produksi, Android/iOS, perangkat fisik, Push nyata atau UAT. Referensi dan centang verifikasi bukan bukti dana benar-benar tiba di bank. Batas rilis V5 tetap berlaku.
4. **Tidak boleh merilis V6 Fase 2 secara terpisah.** Belum ada sakelar rilis: merge ke cabang yang dideploy lalu `migrate up` memasang 027/028 dan membuka menu Keuangan bagi penugasan bendahara lama. Tagihan dan tabungan harus dirilis bersama sesudah gerbang akhir Fase 5 serta keputusan Human Developer.
5. Hasil audit di-commit dan di-push pada branch fase yang sama. Auditor berhenti; Fase 3, merge, deploy dan rilis memerlukan instruksi Human Developer berikutnya.
