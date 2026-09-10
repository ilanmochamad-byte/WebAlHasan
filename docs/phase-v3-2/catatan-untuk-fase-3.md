# Catatan auditor Fase 2 untuk implementator Fase 3

Ditulis Claude Code selaku auditor Fase 2, ditujukan kepada implementator Fase 3.
Isinya hal-hal yang berubah atau sudah tersedia selama Fase 2 dan akan langsung
menyentuh pekerjaan Fase 3 — bukan ringkasan PRD. PRD-V3.md tetap sumber
kebenaran ruang lingkup; dokumen ini hanya mencegah pekerjaan terbuang.

Seluruh pernyataan di bawah diverifikasi terhadap skema dan kode pada `main`
per commit `927dcd8`, bukan dari ingatan sesi.

## 1. Titik awal

- Migrasi terakhir adalah **`015_v3_fase2_koreksi_dan_rekomendasi.sql`**. Migrasi
  Fase 3 dimulai dari **016**, bukan 014 atau 015.
- Branch dicabang dari `main`. Fase 2 sudah sepenuhnya masuk `main`, termasuk
  enam koreksi audit dan bukti produksinya.
- Capability V3 yang sudah ada dan tidak perlu dibuat lagi:
  `v3.katalog.kelola`, `v3.ambang.kelola`, `v3.pelanggaran.kelola`,
  **`v3.konseling.kelola`**, `v3.binaan.baca`, `v3.murobi.mengetahui`,
  `v3.publikasi.baca`, `v3.pengawasan`, `v3.koreksi`.

## 2. Skema konseling sudah ada sejak migrasi 013 — jangan dibuat ulang

`v3_konseling_kasus`, `v3_konseling_sesi`, dan `v3_konseling_tautan` sudah
terpasang sejak Fase 1 dan sudah ada di produksi. Fase 3 sebagian besar
pekerjaan service, UI, dan API.

**`v3_konseling_kasus`** — `santri_id`, `tahun_ajaran_id`, `pembimbing_id`,
`pembimbing_assignment_id`, `tujuan`, `kerahasiaan` ENUM(`Internal`,`Rahasia`),
`status` ENUM(`Dibuka`,`Dalam Pendampingan`,`Selesai`,`Dibatalkan`),
`dibuka_pada`, `ditutup_pada`, `ringkasan_penutupan`, `cakupan_snapshot`,
`idempotency_key`, `version`, `archived_at`, plus penanda data warisan.
Unik: `kasus_retry (created_by, idempotency_key)`.

**`v3_konseling_sesi`** — `kasus_id`, `pembimbing_id`, `jadwal`, `realisasi`,
`status` ENUM(`Dijadwalkan`,`Selesai`,`Tidak Hadir`,`Dijadwalkan Ulang`,`Dibatalkan`),
`ringkasan_internal`, `hasil`, `tindak_lanjut`, `jadwal_berikut`,
`revisi_dari_id`, `alasan_revisi`, `idempotency_key`, `version`.
Unik: `sesi_retry (created_by, idempotency_key)`.

**`v3_konseling_tautan`** — `pelanggaran_id`, `kasus_id`, `sesi_id` NULL, dan
kolom turunan `sesi_key GENERATED ALWAYS AS (COALESCE(sesi_id,0)) STORED`.
Unik: `tautan_unik (pelanggaran_id, kasus_id, sesi_key)`.

Kolom `sesi_key` itu penting dipahami sebelum menulis kode tautan: ia ada supaya
tautan tingkat kasus (`sesi_id` NULL) tetap unik, karena NULL tidak pernah
bertabrakan pada indeks unik MySQL. Satu pelanggaran boleh ditautkan ke satu
kasus sekali pada tingkat kasus, dan sekali lagi per sesi.

## 3. Tabel bersama sudah menyediakan kolom kasus dan sesi

Tidak perlu migrasi tambahan untuk ketiganya:

- `v3_murobi_catatan` sudah punya `pelanggaran_id`, **`kasus_id`**, **`sesi_id`**
- `v3_lampiran` sudah punya `pelanggaran_id`, **`kasus_id`**, **`sesi_id`**
- `v3_publikasi` sudah punya ketiganya (untuk Fase 4)

`v3_idempotency` bersifat generik (`user_id`, `operation`, `idempotency_key`),
jadi operasi konseling cukup memakai nama `operation` sendiri.

## 4. Rekomendasi kini punya masa berlaku — memengaruhi persyaratan 7

Koreksi audit T3 menambahkan `v3_rekomendasi.tidak_berlaku_pada` dan
`tidak_berlaku_alasan`. Nilainya diselaraskan otomatis dengan total poin pada
**setiap** mutasi pelanggaran melalui
`PelanggaranRepository::refreshRecommendationValidity()`, yang berjalan di dalam
transaksi mutasi sesudah rekonsiliasi: ditandai tidak berlaku ketika total
keluar dari rentang ambang, dan penandanya dilepas ketika total masuk lagi.

Persyaratan 7 Fase 3 meminta rekomendasi ambang yang belum ditindaklanjuti
ditampilkan dan dapat ditautkan manual ke kasus. **Antrean itu wajib menyaring
`tidak_berlaku_pada IS NULL`.** Tanpa penyaringan, antrean akan memuat
rekomendasi yang totalnya sudah ditarik pembalik poin, dan pembimbing diminta
menindaklanjuti sesuatu yang sudah tidak berlaku.

Baris rekomendasi tidak pernah dihapus dan tetap unik per santri/tahun/ambang.
Jangan menambah baris kedua untuk ambang yang sama; itu akan melanggar
`rekomendasi_ambang_subjek_unik` dan pemeriksaan verifier.

## 5. Pola yang sebaiknya diikuti agar konsisten dengan Fase 2

**Alasan pembatalan berdiri sendiri.** Koreksi audit T2 memisahkan
`v3_pelanggaran.alasan_pembatalan` dari `alasan_revisi`, karena pembatalan yang
menyusul koreksi sempat menimpa alasan koreksinya. Persyaratan 9 Fase 3 meminta
koreksi, penjadwalan ulang, pembatalan, dan penutupan hidup berdampingan pada
satu entitas — `v3_konseling_sesi` saat ini baru punya `alasan_revisi`, jadi
pertimbangkan kolom alasan terpisah untuk pembatalan sesi dan penutupan kasus
sejak migrasi 016, bukan menumpuk semuanya pada satu kolom.

**Urutan pelepasan fingerprint.** Koreksi audit T6: bila memakai fingerprint
untuk mencegah duplikasi kasus atau sesi, catatan sumber harus melepas
fingerprint **sebelum** baris revisi ditulis. Terbalik urutannya, koreksi yang
tidak mengubah isi — misalnya hanya menambah alasan atau melampirkan bukti
susulan — akan ditolak sebagai duplikat. Penjaga statisnya ada di
`tests/v3_phase2_static.php` dengan label "Catatan sumber melepas fingerprint
sebelum revisi ditulis"; tiru polanya bila menambah fingerprint baru.

**Kunci mutasi per subjek.** Mutasi pelanggaran mengunci satu baris
`v3_poin_agregat` per santri/tahun ajaran dengan `SELECT ... FOR UPDATE`, bukan
gerbang global. Mutasi konseling yang menyentuh poin atau rekomendasi sebaiknya
memakai kunci yang sama agar tidak muncul jalur penguncian kedua.

**Audit wajib menggagalkan transaksi.** `auditRequired()` melempar `503` bila
audit gagal disimpan, dan seluruh transaksi bisnis ikut digulung. Kriteria
penerimaan Fase 3 memuat hal yang sama; ikuti pola yang sudah ada.

## 6. Dua sisa Fase 2 yang belum diuji di produksi

Keduanya lulus pada database uji dan tercatat terbuka di
`acceptance-status.md`. Fase 3 memperluas keduanya, jadi bila ada masalah, akan
muncul di sana lebih dahulu:

- **Penolakan akses lintas cakupan.** Daftar milik murobi di produksi memang
  hanya memuat santri binaannya, tetapi belum ada pelanggaran milik santri di
  luar cakupannya, sehingga penyaringan belum benar-benar teruji di sana.
  Persyaratan 12 Fase 3 meminta pengujian privasi serializer; perlakukan itu
  sebagai kesempatan menutup celah ini sekalian.
- **Lampiran privat.** Belum pernah diunggah dan diunduh di produksi.

## 7. Set akun smoke test produksi

Dipertahankan aktif atas keputusan Human Developer — jangan dinonaktifkan.
Rinciannya di `audit-claude-code.md` bagian 6a.

| Peran | Akun | Catatan |
| --- | --- | --- |
| Pembimbing | `penggurus_smoke.audit` | penugasan pembimbing aktif |
| Murobi | `guru_smoke.audit` | penugasan murobi bertarget Kamar |
| Orang tua | `ortu_smoke.audit` | role `orang_tua`, tertaut ke santri smoke |
| Santri | `SANTRI SMOKE AUDIT` | penempatan kelas dan kamar aktif |

Rantai ini sudah memenuhi prasyarat data untuk menguji kasus konseling, catatan
murobi, dan — pada Fase 4 nanti — publikasi orang tua. Poin uji dikembalikan
lewat pembatalan beralasan, bukan dihapus.

## 8. Menjalankan pengujian

Paket Fase 2 dan verifikatornya berjalan hanya pada database uji khusus:

```
DB_NAME=webalhasan_v3_phase1_test bash bin/v3_phase2_run_tests.sh
DB_NAME=webalhasan_v3_phase1_test bash bin/v3_phase1_run_tests.sh
DB_NAME=webalhasan_v3_phase1_test bash bin/penugasan_run_all_tests.sh
```

Angka acuan pada `main` saat serah terima: Fase 2 **172** pemeriksaan, Fase 1
**71**, drill migrasi **18**, regresi V1/V2 dan fondasi **4.014** pada 49 paket,
`bin/v3_verify.php` dan `bin/v3_phase2_verify.php` tanpa blocker. Seluruhnya
exit 0. Bila salah satu turun sesudah pekerjaan Fase 3, itu regresi.

Regresi warisan meninggalkan tepat 24 outbox dan 6 audit yatim setiap kali
dijalankan; itu perilaku fixture yang sudah diketahui, bukan temuan. Bersihkan
hanya residu yang asalnya terbukti, lalu ulangi verifikator — jangan
melonggarkan pemeriksaan yatim.
