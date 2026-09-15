# Handoff Fase 3 → Fase 4

Disusun Claude Code (auditor Fase 3) atas perintah Human Developer 15 September
2026: sisa smoke test produksi dilewati, Fase 3 diserahkan, pekerjaan lanjut ke
Fase 4 (Publikasi Orang Tua dan Notifikasi, PRD V3 baris 268–298).

## 1. Keputusan yang mendasari handoff ini

| Keputusan | Isi |
| --- | --- |
| Sisa smoke produksi | **Dilewati.** Skenario pada [bukti audit](audit-claude-code.md) bagian 10.9 tidak dijalankan dan tidak diklaim lulus. |
| Status Fase 3 | Diserahkan dengan seluruh kriteria penerimaan wajib terpenuhi pada pengujian otomatis, ditambah tiga putaran smoke produksi sebagian. |
| Fase 4 | Boleh dimulai. Implementator berikutnya mengikuti protokol kolaborasi bergantian pada `AGENTS.md`. |

## 2. Keadaan yang diwariskan

- Branch `prd-v3-fase-3`, terakhir `5e9d364`, dicabang dari `main` pada
  `927dcd89dfa2ba53d853f7bd900805bc16b9c6de`. PR #34 masih terbuka dan **belum**
  di-merge.
- Migrasi terakhir: **017** (`017_v3_fase3_kerahasiaan_dan_revisi.sql`), sudah
  diterapkan di produksi 11 September 2026 pukul 19.19.59. Fase 4 mulai dari
  **018**.
- Angka pengujian terakhir pada database uji: Fase 3 **297**, Fase 2 172, Fase 1
  71, regresi V1/V2 + fondasi penugasan 49 suite/4.017, browser **53**,
  `v3_verify`/`v3_phase2_verify`/`v3_phase3_verify` **286/30/34**, semuanya
  `exit=0`.
- Produksi menyimpan data smoke yang sengaja ditinggalkan: pelanggaran #6
  (Dicatat), #7 dan #8 (Dibatalkan berikut pembalik poin), kasus #1 (Rahasia,
  Dalam Pendampingan, 1 sesi) dan kasus Internal 14 September (Selesai, 1 sesi),
  serta catatan murobi dan notifikasi terkait. **Belum diputuskan** apakah ini
  dibersihkan mengikuti bagian pembersihan [panduan smoke](panduan-smoke-test-produksi.md)
  atau dipertahankan sebagai data contoh.

## 3. Invarian yang tidak boleh dilanggar Fase 4

1. **Kerahasiaan (PRD 5.5a).** `Internal` hanya diketahui pembimbing dan murobi
   terkait; `Rahasia` hanya pembimbing pemilik kasus, dengan admin sebagai
   pengawas yang setiap pembukaannya menghasilkan audit akses. Publikasi orang
   tua tidak boleh menjadi celah yang mengalahkan aturan ini — lihat pertanyaan
   terbuka 7.1.
2. **Enam koreksi audit Fase 2 (T1–T6)** termasuk `.htaccess` T5 dan alasan
   pembatalan yang tersimpan terpisah dari uraian.
3. **Sebelas koreksi audit Fase 3 (K1–K11)**, terutama: sesi pada kasus tertutup
   dibekukan (K1), rekomendasi kembali ke antrean saat kasus dibatalkan (K2),
   tindak lanjut tetap tampil pada revisi pelanggaran terkini (K3), rollback
   migrasi tidak boleh membuang keputusan bisnis (K4), isian kosong tidak
   menghapus nilai tersimpan (K5), sesi selesai wajib tetap punya realisasi,
   ringkasan internal, dan hasil (K6).
4. **Pola transaksi.** `auditRequired()` menggulung transaksi bila audit gagal;
   outbox generik ditulis di dalam transaksi yang sama; idempotency melalui
   `v3_idempotency`; versi optimistis menolak dengan `409`; subjek dikunci dengan
   `SELECT … FOR UPDATE` pada `v3_poin_agregat`.
5. **Tidak ada mutasi lewat GET**, CSRF web `419`, API tanpa token `401`, akses
   silang `403`, dan halaman `403` yang tidak membocorkan keberadaan baris.
6. **Migrasi aditif berpasangan.** Setiap migrasi punya rollback; rollback tidak
   boleh menghapus data bisnis (pelajaran K4/K8). Verifier tidak boleh
   dilonggarkan agar pengujian lulus.

## 4. Titik sambung teknis yang sudah siap

| Aset | Keadaan | Catatan untuk Fase 4 |
| --- | --- | --- |
| `KonselingService::parentSerializer()` | Ada, diuji, **belum dirutekan** | Allowlist tepat tujuh field: `id`, `santri_id`, `ringkasan`, `tindak_lanjut`, `diterbitkan_pada`, `ditarik_pada`, `dibaca_pada`. Uji statis dan integrasi sudah menjaga agar tidak ada field internal masuk; pertahankan keduanya saat serializer dipakai sungguhan. |
| `RecipientResolver::waliSantri()` | Ada dan dipakai V2 | Sudah menolak relasi `santri_wali` yang diarsipkan serta wali/user nonaktif — pakai ini, jangan menulis query wali baru. |
| `notifikasi_outbox` | Ada sejak migrasi 006 | Unique `(event_key, kanal, penerima_user_id)` memberi dedup. Kolom relasi yang ada hanya `pengajuan_id` (FK ke `izin_pengajuan`), jadi publikasi perlu kolom atau tabel penghubung sendiri di migrasi 018 — jangan menumpang `pengajuan_id`. |
| `KonselingRepository::enqueueGeneric()` / `PelanggaranRepository::enqueueGeneric()` | Ada | Payload konseling sengaja generik ("Ada pembaruan pendampingan…"); verifier Fase 3 menjaga agar payload tidak memuat rincian sensitif. Publikasi orang tua harus mengikuti pola generik yang sama. |
| `NotificationAdminService` + `app/Notification/Push`, `app/Notification/WhatsApp` | Ada, dengan sakelar kanal on/off | Persyaratan 9 Fase 4: WhatsApp tetap mati dan adapter uji bukan bukti pengiriman nyata. |
| `Capabilities::v3Capabilities()`/`v3AppliesToSantri()` | Ada | Peran dasar tetap admin/guru/pengurus/orang_tua; jangan membuat mode login baru. |
| Verifier & preflight | `bin/v3_verify.php`, `bin/v3_phase2_verify.php`, `bin/v3_phase3_verify.php`, `bin/v3_phase3_preflight.php` | Fase 4 sebaiknya menambah `v3_phase4_preflight.php` dan `v3_phase4_verify.php` dengan pola yang sama, termasuk pemeriksaan yatim untuk tabel baru. |

## 5. Cara menjalankan pengujian

```bash
DB_NAME=webalhasan_v3_phase1_test V3_RUN_TESTS=1 bash bin/v3_phase3_run_tests.sh
DB_NAME=webalhasan_v3_phase1_test V3_RUN_TESTS=1 bash bin/v3_phase2_run_tests.sh
DB_NAME=webalhasan_v3_phase1_test V3_RUN_TESTS=1 bash bin/v3_phase1_run_tests.sh
DB_NAME=webalhasan_v3_phase1_test MOBILE_APP_ROOT=/Users/ilanmochamad/alhasanApps bash bin/penugasan_run_all_tests.sh
```

Hanya database uji `webalhasan_v3_phase1_test`. Regresi lama masih meninggalkan
tepat 24 outbox `izin:*` dan 6 audit `login_succeeded` milik akun fixture yang
sudah dihapus per putaran penuh; buktikan asal tiap baris sebelum membersihkan
hanya residu itu (bagian 8 [bukti audit](audit-claude-code.md)).

## 6. Risiko yang diwariskan karena sisa smoke dilewati

Jalur berikut **lulus pada pengujian otomatis** tetapi belum pernah dijalankan
pada MariaDB/cPanel produksi. Fase 4 dan Fase 5 sebaiknya tidak menganggapnya
sudah terbukti di produksi:

- Pembatalan kasus dan penutupan otomatis sesi yang masih terjadwal.
- Koreksi kasus menjadi revisi (`v3_konseling_kasus_revisi` diperkirakan masih
  kosong di produksi, sehingga invariant terkait lulus secara hampa).
- Koreksi sesi, penolakan koreksi pengosong, dan penjadwalan ulang.
- Penyimpanan catatan murobi tingkat sesi.
- Rekomendasi: penautan manual dan pelepasan saat kasus dibatalkan.
- Penolakan orang tua pada halaman dan endpoint internal — relevan langsung bagi
  Fase 4, karena portal orang tua baru akan dibangun di atasnya.
- Post-check `v3_verify` dan `v3_phase2_verify` sesudah data smoke terbentuk.

Persyaratan 11 Fase 5 memang menuntut smoke cPanel menyeluruh; item di atas
paling murah ditutup di sana, atau pada smoke Fase 4 yang memang harus menyentuh
portal orang tua.

## 7. Pertanyaan terbuka untuk Human Developer

1. **Publikasi kasus Rahasia.** Keputusan 5.5a menyatakan `Rahasia` hanya
   diketahui pembimbing pemilik dan santri. Apakah kasus Rahasia (a) tidak boleh
   diterbitkan sama sekali kepada orang tua, (b) boleh diterbitkan hanya sesudah
   kerahasiaannya dikoreksi menjadi Internal lewat revisi, atau (c) boleh
   diterbitkan langsung karena isi publikasi ditulis manual dan terpisah dari
   catatan internal? Saran saya: **(b)** — jejaknya terekam sebagai revisi kasus
   dan aturan kerahasiaan tetap satu pintu.
2. **Data smoke produksi** — dibersihkan mengikuti panduan, atau dipertahankan?
3. **Merge PR #34 ke `main`** sebelum Fase 4 dicabang, atau Fase 4 dicabang dari
   `prd-v3-fase-3`? Saran saya: merge dulu agar baseline Fase 4 bersih.
4. **Kanal**: push fisik dan WhatsApp tetap OFF selama Fase 4, sesuai persyaratan
   9? Saran saya: ya; bukti pengiriman fisik ditunda ke Fase 5.

## 8. Langkah pertama yang disarankan untuk implementator Fase 4

1. Baca `AGENTS.md`, PRD V3 bagian Fase 4 dan 5.5a, [desain dan aturan](desain-dan-aturan.md),
   [kontrak API](kontrak-api.md), dan [bukti audit](audit-claude-code.md) bagian
   9 (keputusan kerahasiaan) sebelum menulis kode.
2. Tunggu jawaban pertanyaan 7.1 — jawabannya menentukan bentuk tabel publikasi
   dan penjaga aksesnya.
3. Rancang migrasi 018 beserta rollback non-destruktifnya: tabel publikasi
   (sumber, versi sumber, penerima wali, ringkasan, tindak lanjut, status terbit/
   tarik, alasan penarikan, waktu baca) dan penghubung outbox untuk publikasi.
4. Bangun pratinjau publikasi lebih dulu (persyaratan 1) — pratinjau memakai
   `parentSerializer()` yang sudah ada, sehingga perbedaan antara yang dilihat
   pembimbing dan yang diterima orang tua menjadi mustahil secara konstruksi.
5. Tulis uji akses silang wali A vs wali B, uji nol baris untuk data belum
   terbit, dan uji nol request ke provider ketika kanal OFF sejak awal, bukan di
   akhir.
