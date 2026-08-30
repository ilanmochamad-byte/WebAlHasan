# Panduan mengirim hasil audit ke GitHub dan menyiapkan rilis cPanel

> **Pembaruan A-17:** koreksi kolom PDF telah disetujui pengguna dan selesai; sembilan PDF kini 45 lulus, 0 gagal. Rekap penerimaan tetap 75 terverifikasi dan 2 menunggu pembaca layar/cetak Safari nyata. Lihat [audit-koreksi-pdf-a17.md](audit-koreksi-pdf-a17.md). Ini bukan izin rilis; angka pada isi laporan historis di bawah tetap merupakan hasil saat itu.

Tanggal: 30 Agustus 2026. Panduan ini disiapkan setelah koreksi B-1, A-06,
A-07, A-09, serta penyelesaian kamar/pagination A-08/A-10. **Auditor tidak melakukan push, merge, deploy, atau migrasi
produksi.** Seluruh perubahan masih commit lokal pada
`codex/perapihan-v1-v2-ui`; main dan sumber mobile tetap utuh.

## 1. Yang dapat dilakukan sekarang: push branch untuk review

Sebelum push, pastikan tidak ada pengaturan hosting di luar repositori yang
otomatis menerbitkan branch ini. Repo saat audit tidak mempunyai `.cpanel.yml`
atau workflow `.github`; ini tidak membuktikan bahwa webhook eksternal tidak
ada. Branch produksi harus tetap terpisah dari branch audit.

Di terminal komputer pengembang:

```bash
cd /Users/ilanmochamad/Documents/GitHub/webalhasan/WebAlHasan
git status --short --branch
git log -5 --oneline
git push -u origin codex/perapihan-v1-v2-ui
```

Pastikan baris pertama menunjukkan branch audit dan tidak ada berkas yang belum
dikomit. Jika ada perubahan yang tidak dikenal, berhenti dan periksa; jangan
`reset --hard`, jangan force push, dan jangan mengganti branch ke main untuk
mempercepat rilis. Push di atas mengirim branch audit ke GitHub, tidak
menggabungkannya ke main.

Buka [perbandingan di GitHub](https://github.com/ilanmochamad-byte/WebAlHasan/compare/main...codex/perapihan-v1-v2-ui),
lalu buat pull request dengan **base: main** dan **compare:
codex/perapihan-v1-v2-ui**. Cantumkan laporan audit awal, laporan lanjutan,
matriks 77 klaim, laporan koreksi PDF A-17 (3.318 pemeriksaan ulang lulus,
0 gagal; rincian dan batas cakupan di laporan), serta
butir yang belum terverifikasi. Jangan menyatakan semua kriteria sudah lulus.

## 2. Syarat sebelum merge dan produksi

Sesuai AGENTS.md, main tidak boleh menerima paket yang belum memenuhi
penerimaan. Kamar dan pagination telah dituntaskan sebelum push sesuai
instruksi pengguna. Sesudah verifikasi lanjutan dan koreksi PDF A-17:
**75 klaim terverifikasi, 0 tidak terverifikasi, dan 2 menunggu verifikasi**.
Jumlah tes yang lulus tidak menghapus sisa bukti perangkat itu. Lihat
[matriks penerimaan](penilaian-penerimaan-codex.md) dan
[langkah lanjut audit](hasil-audit-lanjutan-codex.md).

- Kamar dan pagination sudah selesai dalam kode, tanpa mengurangi ruang lingkup
  untuk meluluskan klaim. Lihat [buktinya](audit-kamar-pagination.md).
- Lengkapi K6-14 (VoiceOver/TalkBack) dan K6-16 (PDF Safari nyata) dengan
  langkah pada [audit koreksi PDF](audit-koreksi-pdf-a17.md). Form, audit mutasi,
  filter, dan halaman legacy telah diperiksa dalam cakupan sandbox pada audit
  sebelumnya; jangan mengganti bukti fisik dengan emulasi Chromium.
- Lakukan rehearsal versi yang sama pada lingkungan uji setara cPanel dengan
  database berakhiran `_test`; semua notifikasi keluar dinonaktifkan dan data
  sensitif dilindungi/disamarkan. Uji migrasi 010 dan restore backup di sana.
- Tinjau PR, verifikasi commit yang akan dirilis, lalu minta persetujuan rilis
  produksi tersendiri. Setelah gerbang tersebut terpenuhi, barulah merge PR ke
  main melalui GitHub. Jika main berubah selama review, lakukan integrasi dan
  uji ulang sebelum merge.

Keputusan menjaga API baseline mengurangi kebutuhan merilis aplikasi mobile
bersamaan. Aplikasi yang sudah terpasang masih memakai jalur dan skema lama;
uji perangkat terhadap server uji tetap diperlukan. Tidak perlu mengubah
alamat API aplikasi hanya untuk koreksi ini.

## 3. Persiapan operator cPanel, sebelum menyentuh situs aktif

Ini langkah untuk rilis mendatang yang **sudah disetujui**, bukan perintah yang
sudah dijalankan auditor. Jangan masukkan credential produksi ke chat/GitHub.

1. Pastikan domain, document root, lokasi repository cPanel, branch aktif, dan
   commit yang sedang live. Bedakan repo terpisah dari repo yang langsung
   berada di document root. Jangan menebak konfigurasi hosting dari repo lokal.
2. Catat versi PHP CLI **dan** PHP web beserta MySQL/MariaDB. Dokumen Fase 5
   merekam `/opt/alt/php83/usr/bin/php` dan `/DATA/k1807225/public_html` pada
   penutupan sebelumnya; verifikasi ulang, jangan menganggapnya bukti keadaan
   hosting sekarang. Audit lanjutan memakai PHP 8.4/MariaDB 12.3, bukan server
   produksi. Gunakan [panduan cPanel Fase 5](../phase-v2-5/cpanel-deployment.md)
   sebagai referensi riwayat, bukan pengesahan paket ini.
3. Siapkan backup database, berkas aplikasi, unggahan/media, dan konfigurasi
   privat di luar web root. Catat commit sebelumnya; lakukan uji restore ke
   `_test`. Backup yang belum diuji pemulihannya belum cukup untuk rilis.
4. Pertahankan `.env` produksi, `API_TOKEN_HASH_SECRET`, `PUSH_TOKEN_KEY`,
   unggahan, dan konfigurasi cron yang telah disetujui. Jangan menyalin `.env`
   sandbox, fixture, dump uji, atau folder dependency browser ke produksi.
   WhatsApp tetap OFF/DITANGGUHKAN; paket ini tidak meminta perubahan cron.
5. Periksa status migrasi. Jika produksi benar-benar berada pada baseline
   001–009, perubahan skema paket adalah migrasi **010** (satu kolom penanda
   merge wali dan dua indeks). Jika ada migrasi lama yang belum diterapkan,
   berhenti dan susun rencana terpisah; jangan menjalankan semua yang tertunda
   tanpa meninjaunya. Tidak ada penggabungan data wali otomatis oleh migrasi.
6. Tetapkan jendela pemeliharaan dan cara menahan lalu lintas saat perubahan
   skema/kode. Kode baru membaca kolom 010: jangan membiarkannya melayani
   pengguna sebelum kolom tersedia. Setelah rehearsal dan persetujuan rilis,
   operator menerapkan migrasi yang ditinjau dengan prosedur migrator proyek
   yang sudah diuji, lalu menerbitkan commit main yang disetujui. Jangan
   menjalankan fixture atau suite destruktif pada produksi.

## 4. Memakai Git Version Control cPanel

Pada repository yang sesuai, cPanel menyediakan **Manage → Pull or Deploy →
Update from Remote** untuk menarik perubahan secara fast-forward. Tombol
**Deploy HEAD Commit** memerlukan `.cpanel.yml`, branch, dan checkout bersih.
Lihat [dokumentasi resmi Git Version Control cPanel](https://docs.cpanel.net/cpanel/files/git-version-control/).

Repo ini belum menyertakan `.cpanel.yml`; karena itu jangan menganggap tombol
Deploy sudah mempunyai aturan penerbitan yang benar. Pastikan salah satu
kondisi berikut berdasarkan konfigurasi hosting yang nyata:

| Penempatan repo | Implikasi tindakan |
| --- | --- |
| Repo langsung berada di document root | Update dari remote dapat langsung mengganti kode yang dilayani situs. Lakukan hanya dalam jendela pemeliharaan, dengan urutan skema/kode yang sudah diuji. |
| Repo berada di lokasi terpisah | Update repo belum berarti situs terbit. Gunakan prosedur penyalinan/deploy yang sudah teruji dan menjaga `.env`, unggahan, serta data privat. |
| Belum ada prosedur deployment yang jelas | Berhenti. Tentukan dahulu lokasi sumber/tujuan dan perlindungan data. Jangan membuat perintah salin massal atau `rsync --delete` berdasarkan tebakan. |

Aturan deployment cPanel ditulis dalam `.cpanel.yml`; penambahannya memerlukan
pengetahuan jalur hosting dan uji tersendiri. Panduan ini tidak menambah aturan
tersebut. Rujukan: [panduan resmi Git deployment cPanel](https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/).

## 5. Pemeriksaan setelah rilis yang disetujui

Operator harus mencocokkan commit situs dengan commit main yang disetujui,
memeriksa log PHP, lalu melakukan smoke test yang aman. Jangan membuat akun
fixture produksi atau menjalankan suite audit pada data produksi. Audit rinci
mutasi tetap dilakukan di `_test`; pemeriksaan produksi hanya oleh operator
berwenang sesuai prosedur yang telah disepakati.

- Login, logout, dan tujuan halaman untuk peran yang relevan; guru hanya melihat
  laporan miliknya. Buka alamat langsung untuk memastikan guard tidak sekadar
  bergantung pada menu.
- Layar, CSV, dan cetak memakai filter sama; kode batas 422 sudah dibuktikan di
  `_test` tanpa perlu menambahkan 20.001 catatan ke produksi.
- Aplikasi mobile yang ada dapat login, membuka laporan/notifikasi in-app,
  berpindah akun dengan aman, serta membuka cetak sesuai perangkatnya.
- Kolom ayah/ibu lama, ID wali, relasi, dan riwayat tetap tersedia; tidak ada
  rekonsiliasi massal atau keputusan identitas berdasarkan kemiripan nama.
- Aset dan halaman tidak 500; print tidak memuat sidebar. File `.env`, SQL,
  backup, `bin`, `tests`, dan direktori internal tidak dapat diunduh publik.
- WhatsApp tetap OFF; konfigurasi push/receipt/cron lama tidak berubah karena
  paket UI. Jangan mengirim pesan percobaan tanpa izin tersendiri.

Jika ada kegagalan kritis, hentikan akses tulis sesuai prosedur insiden dan
kembalikan **kode** ke commit sebelumnya melalui mekanisme deploy yang sudah
diuji. Pertahankan kolom migrasi 010 yang aditif; jangan menghapus penanda merge,
akun, relasi, atau riwayat. Jangan menimpa database dengan backup lama setelah
ada transaksi baru tanpa rekonsiliasi dan keputusan operator. Lihat
[migrasi dan rollback paket](migrasi-dan-rollback.md).

Push branch untuk review dapat menjadi langkah berikutnya. Merge dan produksi
tetap menunggu gerbang penerimaan serta persetujuan rilis yang terpisah.
