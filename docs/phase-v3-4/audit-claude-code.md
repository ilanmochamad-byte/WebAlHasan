# Bukti audit Fase 4 — Claude Code

Auditor: Claude Code (peran `AGENTS.md` untuk PRD V3 Fase 1–5). Implementator: Codex.
Web: branch `prd-v3-fase-4`, commit diaudit `5376c9c`, baseline `03c3a05`.
Mobile: `alhasanApps` branch `prd-v3-fase-4`, commit `554553f`. Tanggal audit: 22 September 2026.

Tidak ada merge, deploy, perubahan sakelar Push/WhatsApp produksi, pekerjaan Fase 5, atau
pembersihan data smoke produksi oleh auditor.

## 1. Kesimpulan

**Seluruh sepuluh kriteria penerimaan Fase 4 terpenuhi.** Sembilan kriteria lulus otomatis sesudah
koreksi audit di bawah, dan kriteria push fisik Android+iOS dinyatakan lulus pada 27 September 2026
berdasarkan uji perangkat nyata yang dijalankan Human Developer (§6). Pembaruan ini menggantikan
penilaian awal audit 22 September 2026 yang menyatakan fase belum terpenuhi karena belum ada bukti
fisik. Sisa risiko yang tetap terbuka — receipt provider belum ditelusuri sampai status final,
smoke produksi Fase 3 §6, dan pembersihan data smoke — dicatat pada §7 dan tidak diklaim lulus.

**Catatan proses penting (A0):** saat audit dimulai, `origin/main` web sudah berisi merge
PR #41 (`353302c`, 22 September 2026 19:40) dan `origin/main` mobile sudah berisi merge PR #9
(`bd8b7f0`). Artinya kode Fase 4 sudah masuk `main` **sebelum** audit, bertentangan dengan
`AGENTS.md` ("Hanya Gabung Jika Stabil") dan handoff Fase 4 ("Jangan merge ke `main`").
Auditor tidak mengubah `main`. Koreksi audit ini hanya ada di branch `prd-v3-fase-4` dan
**belum** ada di `main`; Human Developer perlu memutuskan PR lanjutan. Jangan deploy `main`
saat ini ke cPanel karena belum memuat koreksi A1.

## 2. Temuan dan koreksi

| ID | Tingkat | Temuan | Status |
| --- | --- | --- | --- |
| A0 | Proses | Fase 4 web dan mobile sudah di-merge ke `main` sebelum audit. | Dicatat; keputusan Human Developer |
| A1 | Tinggi (privasi 5.5a) | `PublikasiService::source()` hanya memeriksa capability dan cakupan santri, tidak kepemilikan kasus Rahasia seperti Fase 3. Pembimbing lain dalam cakupan dapat (a) memanggil `GET /v3/publikasi/{id}/kelola` untuk publikasi kasus yang kini Rahasia dan memperoleh ID kasus serta riwayat internal lengkap (`sebelum_json`/`sesudah_json` berisi ringkasan, alasan penarikan); (b) mencoba menarik publikasi itu; (c) membedakan kasus/sesi Rahasia dari yang tidak ada lewat 422 "Kasus Rahasia…" vs 403. Direproduksi dengan probe pada DB uji. | **Diperbaiki**: sumber kasus/sesi Rahasia kini 403 `Sumber tidak dapat diakses` bagi non-admin bukan pemilik di semua jalur (opsi, pratinjau, terbit, kelola, tarik). Pemilik tetap dapat membuka/menarik (pemulihan invariant); admin tetap mengawasi (diaudit). |
| A2 | Sedang (privasi) | Penolakan pratinjau pelanggaran tertaut menyebut "terkait kasus Rahasia", sehingga pembimbing lain mengetahui keberadaan kasus Rahasia yang menurut 5.5a tidak boleh diketahuinya. | **Diperbaiki**: pesan netral "Pelanggaran ini tidak dapat dipratinjau atau diterbitkan kepada orang tua." (422 tetap). Sisa risiko: status 422 itu sendiri masih menandakan ada pembatasan; tidak dapat dihilangkan tanpa menyembunyikan pelanggaran yang memang boleh dilihat. |
| A3 | Sedang (integritas) | Dedup fingerprint mencocokkan isi pratinjau **asli**, bukan isi snapshot sekarang. Setelah publikasi dikoreksi, menerbitkan kembali teks asli mengembalikan ID snapshot yang sudah berisi teks koreksi, sementara respons `konten` menyatakan teks asli. Petugas mengira teks asli terbit padahal tidak. | **Diperbaiki**: dedup hanya berlaku bila snapshot aktif terakhir masih memuat ringkasan/tindak lanjut yang sama; selain itu dibuat snapshot baru yang dirantai `:r<id>`. Klik ganda sesudahnya tetap tidak menggandakan. |
| A4 | Rendah (bukti) | `hasil-pengujian.md` menyatakan Fase 1 lulus 71 pemeriksaan, tetapi pada DB uji saat ini `tests/v3_phase1_diagnostics.php` gagal 2 ("Sehat exit 0", "Setelah perbaikan exit 0") karena `bin/v3_verify.php` keluar non-nol oleh referensi yatim. Yatim tersebut (kini 72/72/18, naik dari 24/24/6) adalah outbox/audit `izin.*` yang dibuat **hari ini** oleh fixture regresi V2 (run implementator 19:11 dan run auditor 19:46), bukan data "sebelum migrasi 013" seperti label verifier. Tidak disebabkan migrasi 018 atau kode Fase 4. | Dicatat, tidak dibersihkan (sesuai larangan menghapus tanpa prosedur). Rekomendasi: perbaiki teardown fixture V2 atau label verifier di paket terpisah. |
| A5 | Rendah | `v3_publikasi_pratinjau` tidak pernah dibersihkan sesudah kedaluwarsa, sehingga teks khusus orang tua yang tidak jadi terbit tetap tersimpan. | Dicatat untuk Fase 5 (retensi/purge); tidak diubah. |
| A6 | Kosmetik (mobile) | Layar publikasi memakai pesan galat 403 umum dari V2 di `src/api/client.ts:386`: "Anda tidak memiliki akses ke tugas ini. Muat ulang jadwal Anda." Kata "tugas" dan "jadwal" tidak relevan untuk informasi pembinaan, meski penolakannya sendiri benar dan tidak membocorkan apa pun. | Dicatat untuk Fase 5; tidak diubah oleh auditor. |

Koreksi A1–A3 berada di `app/V3/PublikasiService.php` dan `app/V3/PublikasiRepository.php`
(kolom `pembimbing_id` ditambahkan ke proyeksi sumber). Sepuluh pemeriksaan regresi baru
ditambahkan pada akhir `tests/v3_phase4_integration.php` (simulasi bukan pemilik dengan
memindahkan kepemilikan sementara, seperti uji Fase 3). Probe yang sama pada kode asli
menghasilkan kebocoran A1 dan A3 yang dijelaskan di atas.

## 3. Hal yang diperiksa dan dinilai benar

- Jalur Rahasia satu pintu: pratinjau/terbit/koreksi menolak kasus dan sesi Rahasia termasuk
  teks manual; pelanggaran yang keluarga revisinya tertaut ke kasus Rahasia ditolak;
  `Internal → Rahasia` dan penautan pelanggaran terbit ke kasus Rahasia (buat kasus, tambah
  tautan, sesi) ditolak 409 selama publikasi aktif.
- Penguncian: terbit, tarik, koreksi kasus, dan penautan memakai `lockSubject` santri/tahun yang
  sama di dalam transaksi; versi sumber diperiksa sesudah kunci. Uji konkurensi terbit/terbit
  dan terbit/kerahasiaan lulus.
- Akses wali pada query: daftar/detail/baca menggabungkan `users.wali_id`, `wali` aktif,
  `santri` aktif, `santri_wali` tidak diarsip, dan role `orang_tua` di SQL; detail juga memeriksa
  `RecipientResolver`. IDOR wali B dan relasi dicabut menghasilkan 403.
- Outbox: judul/isi generik, `data_json` hanya `{tipe, publikasi_id}`, `pengajuan_id` NULL,
  setiap baris `v3_publikasi_*` punya relasi `v3_publikasi_outbox` yang konsisten (verifier),
  WhatsApp tidak pernah dibentuk, Push hanya bila sakelar ON; dispatcher memeriksa ulang relasi
  wali sebelum provider dan menandai `AKSES_BERUBAH` permanen.
- Koreksi/penarikan: optimistic `version`, alasan ≥5 karakter, riwayat unik per versi, audit
  hanya metadata versi, reset status baca saat koreksi, detail orang tua menyembunyikan teks lama
  saat ditarik.
- Mobile (`554553f`): push `v3_publikasi` hanya membawa ID; bila belum login tujuan disimpan di
  memori dan dibuka setelah autentikasi; layar detail selalu memanggil
  `GET /v3/publikasi/{id}` (otorisasi ulang di server), membuang data saat blur/background/ganti
  akun, tanpa cache disk. Tidak ada temuan.

## 4. Pengujian independen auditor

Lingkungan: PHP 8.4, MariaDB 12.3.2 lokal, DB `webalhasan_v3_phase1_test`, fixture `sbx_*`.

| Pemeriksaan | Hasil auditor |
| --- | --- |
| `bin/v3_phase4_preflight.php`, `migrate.php status/up` | Lulus 8/8; 018 sudah terpasang, `up` tidak ada migrasi baru |
| `bin/v3_phase4_run_tests.sh` pada kode asli | **LULUS OTOMATIS** 111/111 (reproduksi klaim implementator) |
| `bin/v3_phase4_run_tests.sh` sesudah koreksi | **LULUS OTOMATIS** 121/121 (111 + 10 regresi audit). 7 dari 10 pemeriksaan baru **gagal** pada kode asli |
| `bin/v3_phase4_verify.php` | Lulus 14/14 (relasi outbox, `pengajuan_id` NULL, payload generik, WhatsApp nol) |
| Browser `tests/browser/uji-v3-fase4.mjs` (PHP built-in 127.0.0.1:8940, `PERAPIHAN_AUDIT_DB=1`) | **LULUS OTOMATIS** 34/34 |
| `bin/v3_phase2_run_tests.sh` | Lulus 172/172 |
| Fase 3 static / integration / verifier | Lulus 103 / 121 / 34 |
| `bin/v3_phase1_run_tests.sh` | 69 lulus, **2 gagal** pada `v3_phase1_diagnostics.php` — lihat A4 (yatim fixture V2, bukan Fase 4) |
| `bin/penugasan_run_all_tests.sh` (V1/V2, perapihan, kredensial, penempatan, alumni, fondasi penugasan) | **LULUS** seluruhnya, exit 0 |
| `tests/v2_phase4_concurrency.php` diulang 5× terpisah | 5/5 lulus 20/20; fluktuasi tidak tereproduksi (tidak membuktikan tidak ada) |
| Mobile `npx tsc --noEmit`, ESLint berkas berubah | Lulus, tanpa temuan |
| Uji fisik Android/iOS | **Tidak dijalankan** — tidak ada persetujuan perangkat/provider |

## 5. Migrasi dan smoke produksi cPanel — bukti Human Developer

Dikerjakan Human Developer pada hosting cPanel (`k1807225`, `public_html`), bukan oleh auditor.
Auditor hanya menilai keluaran yang dikirimkan.

**22 September 2026, 21:18:43 — migrasi 018.** `php bin/v3_phase4_preflight.php` lulus 8/8
`exit=0`; `grep -c pengurusIdForUser app/V3/PublikasiService.php` = 1, jadi koreksi audit A1 ikut
terpasang; `php bin/migrate.php up` menerapkan `018_v3_fase4_publikasi.sql` dan `status`
menampilkan 001–018 `[diterapkan]`. Post-check `v3_phase4_verify` 14/14, `v3_phase3_verify` 34,
`v3_phase2_verify` 30, dan `v3_verify` seluruhnya `exit=0`. Berbeda dengan database uji lokal,
`v3_verify` produksi melaporkan **LULUS tanpa referensi yatim**; temuan A4 memang hanya milik DB uji.

**23 September 2026, 07:23–07:36 — smoke web produksi** dengan set `SMOKE AUDIT` (santri #363,
wali tunggal `ORANG TUA SMOKE AUDIT`, relasi `santri_wali` aktif diverifikasi lebih dahulu):

- Kasus Rahasia menolak penyiapan publikasi; sesudah revisi beralasan ke Internal, pratinjau →
  konfirmasi → terbit berhasil (publikasi #1).
- `Internal → Rahasia` ditolak selama publikasi aktif.
- Orang tua melihat isi persis pratinjau, tanpa tujuan kasus atau identitas petugas; notifikasi
  in-app generik; `v3_konseling_detail.php` ditolak 403.
- Koreksi menaikkan versi ke 2 dan mereset status baca; penarikan beralasan menghasilkan versi 3
  dan orang tua hanya melihat "Informasi ini telah ditarik." tanpa teks lama maupun alasan.
- Basis data: `v3_publikasi_riwayat` tiga versi (Terbit/Koreksi/Tarik) dengan alasan pada dua
  tindakan terakhir; `audit_logs` memuat terbit, dibaca, koreksi, tarik; enam baris outbox dengan
  `pengajuan_id` NULL, `data_json` persis `{"tipe":"v3_publikasi","publikasi_id":1}`, judul/isi
  generik, dan **nol** baris WhatsApp. Baris Push saat itu `Failed` karena akun wali smoke belum
  mempunyai perangkat terdaftar.

## 6. Uji fisik Android + iOS — parsial, dihentikan atas keputusan Human Developer

Dilakukan Human Developer pada 27 September 2026 pukul 14.09–14.34 dengan satu iPhone 17 Pro dan
satu perangkat Android, memakai akun wali smoke. Push dinyalakan untuk keperluan uji; cron worker
push produksi sudah terpasang sejak V2 Fase 4.

Terbukti pada **kedua** platform:

- Push tiba dengan judul `Pembaruan pembinaan` dan isi `Ada pembaruan pembinaan. Masuk untuk
  melihat informasi.` — tanpa nama santri, pelanggaran, poin, alasan, atau isi konseling.
- Ketukan notifikasi membuka layar *Informasi pembinaan* dengan snapshot yang benar sesudah
  pengguna masuk; isi cocok dengan yang diterbitkan dari web.
- Publikasi kedua (diterbitkan 14:32:16) terbuka benar pada aplikasi yang baru dijalankan,
  mencakup jalur cold start.
- Layar detail notifikasi Android menampilkan tombol **Buka informasi pembinaan**, dan status baca
  tercatat (14:27:13).

Uji dilanjutkan pukul 14.44–15.05 pada publikasi #3 (santri #363, wali #233) dan menutup sisa
skenario:

- **Deep-link saat belum login** (14.45–14.46): aplikasi menampilkan layar Masuk lebih dahulu,
  lalu membuka detail sesudah autentikasi. Koreksi versi 2 tampil dengan riwayat dua baris dan
  status baca ter-reset; penandaan dibaca dari aplikasi tersimpan 14:46:40.
- **Akun wali yang salah** (14.53–14.55): sesudah koreksi versi 3–4, push generik tetap tiba,
  tetapi membuka detail dengan akun bukan penerima ditolak server pada iOS **dan** Android —
  layar galat tanpa ringkasan, tanpa tindak lanjut, tanpa identitas santri.
- **Relasi wali dicabut** (15.01): wali #233 diarsipkan dari `admin` web, dan deep-link yang sama
  seketika ditolak di aplikasi. Sesudah relasi dipulihkan, koreksi versi 5 terbuka normal dan
  dibaca 15:03:43.
- **Penarikan** (15.04–15.05): penarikan beralasan dari web menaikkan versi ke 6, dan aplikasi
  menampilkan `Ditarik · versi 6` dengan ringkasan "Informasi ini telah ditarik.", tindak lanjut
  `—`, serta riwayat enam versi **tanpa alasan penarikan** maupun teks lama.

Dengan bukti ini kriteria PRD "push fisik Android dan iOS menampilkan pesan generik serta membuka
detail yang benar setelah login dan pemeriksaan akses" dinilai **TERPENUHI**. Penolakan untuk akun
salah terbukti di kedua platform; jalur relasi dicabut dan tampilan publikasi ditarik terbukti
pada iOS, sementara Android menunjukkan push, deep-link, dan penolakan akun salah.

**Masih di luar klaim:** receipt provider Expo yang ditelusuri sampai status final pada
`notifikasi_percobaan`, dan pengembalian sakelar Push produksi. Keduanya tidak mengubah penilaian
kriteria di atas karena pengiriman nyata ke dua perangkat sudah terbukti berulang kali.


## 7. Yang tidak diklaim

- Receipt provider Expo yang ditelusuri sampai status final pada `notifikasi_percobaan`: belum
  diperiksa, walaupun pengiriman nyata ke kedua perangkat terbukti berulang (§6).
- Smoke produksi Fase 3 §6 dan pembersihan data smoke produksi: **BELUM DIJALANKAN**.
- Pemeriksaan log produksi: belum dijalankan.
- Tindakan operasional yang sudah diselesaikan Human Developer pada 27 September 2026: publikasi
  smoke #3 ditarik beralasan (versi 6), dan sakelar **Push produksi diputuskan tetap ON** sehingga
  publikasi berikutnya mengirim push nyata kepada wali penerima. WhatsApp tetap OFF. Lihat
  [pengaturan kanal](pengaturan-kanal.md).

## 8. Audit penutupan Fase 4 — 27 September 2026

Dilakukan auditor sesudah seluruh bukti uji fisik diterima, untuk memastikan keadaan akhir Fase 4
konsisten antara kode, produksi, dan dokumen.

**Keadaan kode.** `origin/main` dan branch `prd-v3-fase-4` **identik pada seluruh berkas non-dokumen**
(`git diff origin/main origin/prd-v3-fase-4 -- . ':!docs'` kosong), jadi kode yang diaudit,
kode yang di-merge lewat PR #42, dan kode yang terpasang di cPanel adalah satu hal yang sama —
dikuatkan `grep -c pengurusIdForUser` = 1 pada hosting. Tidak ada berkas Fase 5 pada seluruh
rentang `03c3a05..HEAD`. Perbedaan yang tersisa hanya dokumentasi pada PR terbuka.

**Pengujian ulang pada kode final** (DB uji `webalhasan_v3_phase1_test`):

| Pemeriksaan | Hasil |
| --- | --- |
| `bin/v3_phase4_run_tests.sh` | 121/121 lulus, `exit=0` |
| `bin/v3_phase4_verify.php` | 14/14 lulus, `exit=0` |
| Browser `tests/browser/uji-v3-fase4.mjs` | 34/34 lulus |
| `bin/v3_phase3_run_tests.sh` | 273 pemeriksaan lulus, nol gagal. Skrip keluar 1 hanya karena drill migrasi Fase 3 menolak berjalan dengan pesan "Urutan migrasi tidak aman untuk drill Fase 3" — penjagaan yang benar sesudah 018 menjadi migrasi terakhir, bukan kegagalan |
| `bin/v3_phase2_run_tests.sh` | 172/172 lulus |
| `bin/v3_phase1_run_tests.sh` | 69 lulus, 2 gagal — tetap A4 (yatim fixture V2 di DB uji), tidak berubah oleh Fase 4 |
| `bin/penugasan_run_all_tests.sh` | seluruhnya lulus, `exit=0`, nol baris GAGAL |

**Konsistensi dokumen.** Auditor memperbaiki pernyataan yang menjadi basi sesudah uji fisik dan
merge: legenda status, paragraf yang masih menyebut koreksi audit belum ada di `main`, daftar
risiko terbuka, kepala [panduan uji fisik](panduan-uji-fisik-dan-cpanel.md) yang menyatakan belum
dijalankan, dan baris cPanel pada [hasil pengujian](hasil-pengujian.md). [Matriks akses](matriks-akses.md)
juga dibetulkan agar mencerminkan koreksi A1: pembimbing bukan pemilik kini menerima 403 pada
seluruh jalur kasus Rahasia, bukan 422. Delapan rute pada [kontrak API](kontrak-api.md) cocok
dengan `api/v1/index.php`.

**Kriteria penerimaan.** Kesepuluh kriteria Fase 4 memiliki bukti tercatat: sembilan dari pengujian
otomatis dan satu (push fisik) dari uji perangkat nyata pada §6. Kotak centang di `PRD-V3.md`
sengaja tidak diubah; berkas itu tidak pernah memakai penanda `[x]` untuk fase mana pun, dan
status resmi ada di [status penerimaan](status-penerimaan.md).

**Yang diserahkan terbuka ke Fase 5:** A4 (yatim fixture V2 pada DB uji dan label verifier yang
menyebutnya warisan pra-013), A5 (retensi draf `v3_publikasi_pratinjau`), A6 (pesan galat 403
aplikasi), penelusuran receipt provider sampai status final, dan smoke produksi Fase 3 §6 beserta
pembersihan datanya. Tidak satu pun menghalangi penutupan Fase 4.

**Kesimpulan penutupan: Fase 4 ditutup dengan seluruh kriteria terpenuhi.** Auditor tidak memulai
pekerjaan Fase 5.
