# Panduan smoke test produksi — PRD V3 Fase 3

Untuk Human Developer yang menjalankan smoke test konseling pada hosting cPanel
sesudah branch `prd-v3-fase-3` (commit `6d9d59e`) dideploy dan migrasi 016–017
diterapkan pada **11 September 2026 pukul 19:19:59**.

Tujuannya membuktikan alur Fase 3 berjalan atas data nyata hosting, bukan
mengulang suite otomatis. Setiap langkah mencantumkan hasil yang diharapkan dan
bukti yang perlu dicatat. Kirim bukti tersebut ke auditor agar dicatat pada
`audit-claude-code.md`, seperti bagian 7 audit Fase 2.

## 0. Aturan keselamatan — baca dulu

- **Hanya pakai set `SMOKE AUDIT`.** Jangan membuka, membuat, atau mengoreksi
  kasus santri sungguhan. Admin membuka kasus smoke saja.
- **Tidak ada hard delete.** Kasus dan sesi smoke akan tetap tersimpan sebagai
  riwayat (ditutup atau dibatalkan). Karena itu tulis `SMOKE AUDIT F3` di awal
  setiap tujuan, alasan, dan ringkasan agar selalu dapat dikenali, termasuk pada
  laporan Fase 5.
- **Jangan menjalankan `bin/*_run_tests.sh` atau `tests/*` di produksi.**
  Skrip itu menolak database selain `_test`, tetapi jangan dicoba.
- **Jangan menyalin kredensial** ke dokumen, tangkapan layar, atau pesan.
  Tangkapan layar hanya boleh memuat data `SMOKE AUDIT`.
- **Poin uji dikembalikan sesudah dipakai** (keputusan audit Fase 2 bagian 6a):
  pelanggaran smoke yang dibuat untuk memicu rekomendasi dibatalkan dengan alasan
  pada langkah pembersihan.

## 1. Cadangan dan konfirmasi deploy

Migrasi sudah dijalankan, sehingga preflight yang semestinya mendahului migrasi
kini hanya berfungsi sebagai catatan. Bila restore point belum dibuat sebelum
`migrate up`, **buat backup database dari cPanel sekarang**, sebelum data smoke
ditulis.

Pastikan berkas yang terpasang memang hasil audit terakhir. Dari folder
`public_html`:

```bash
grep -c "function privacySql" app/V3/KonselingRepository.php
grep -c "closeScheduledSessions" app/V3/KonselingService.php
grep -c "Riwayat revisi kasus" portal/v3_konseling_detail.php
ls database/rollbacks/017_v3_fase3_kerahasiaan_dan_revisi.sql
```

Hasil yang diharapkan: tiga perintah `grep` masing-masing mencetak angka **1 atau
lebih**, dan berkas rollback 017 ada. Bila hosting berupa klon Git,
`git log -1 --oneline` seharusnya menampilkan `6d9d59e`.

## 2. Post-check migrasi dari CLI

```bash
php bin/v3_phase3_preflight.php; echo "exit=$?"
php bin/v3_verify.php; echo "exit=$?"
php bin/v3_phase2_verify.php; echo "exit=$?"
php bin/v3_phase3_verify.php; echo "exit=$?"
```

| Perintah | Hasil yang diharapkan |
| --- | --- |
| `v3_phase3_preflight.php` | seluruh baris `[lulus]`, `exit=0`; baris manifest memuat `sesi terjadwal pada kasus tertutup yang akan ditutup 017 0`. Kalimat "siap dijalankan" hanya berarti skema kompatibel — migrasi memang sudah diterapkan. |
| `v3_verify.php` | `LULUS: tidak ada blocker.`, `exit=0` |
| `v3_phase2_verify.php` | 30 pemeriksaan lulus, `exit=0` |
| `v3_phase3_verify.php` | **34** pemeriksaan lulus, `LULUS: pemeriksaan Fase 3 tanpa blocker.`, `exit=0` |

**Bukti:** salin keluaran keempat perintah (tanpa kredensial).

Bila salah satu `exit` bukan 0, **hentikan smoke test** dan kirim keluarannya ke
auditor sebelum melanjutkan.

## 3. Akun dan prasyarat data

| Peran | Akun | Kegunaan |
| --- | --- | --- |
| Pembimbing | akun `PENGURUS SMOKE AUDIT` (tercatat sebagai `penggurus_smoke.audit` pada catatan handoff; cocokkan dengan username sebenarnya) | pemilik semua kasus smoke |
| Murobi | `guru_smoke.audit` | penugasan murobi Kamar yang mencakup santri smoke |
| Orang tua | `ortu_smoke.audit` | uji penolakan |
| Admin | akun admin Human Developer | uji pengawasan dan audit akses |
| Santri | `SANTRI SMOKE AUDIT` | subjek seluruh kasus |

Periksa kondisi awal lewat phpMyAdmin (hanya `SELECT`). **Ganti setiap
`ID_RAHASIA`, `ID_INTERNAL`, dan `ID_REKOMENDASI` pada panduan ini dengan
angka sebenarnya sebelum menjalankan query**; bila tidak, MySQL menolak dengan
`#1054 Unknown column`:

```sql
-- Pelanggaran santri smoke yang masih berlaku
SELECT p.id, p.status, p.poin_snapshot, p.waktu_kejadian
  FROM v3_pelanggaran p JOIN santri s ON s.id = p.santri_id
 WHERE s.nama_santri = 'SANTRI SMOKE AUDIT' AND p.status <> 'Dibatalkan'
   AND NOT EXISTS (SELECT 1 FROM v3_pelanggaran nx WHERE nx.revisi_dari_id = p.id);

-- Rekomendasi santri smoke
SELECT r.id, r.label_snapshot, r.total_poin_snapshot, r.status,
       r.tidak_berlaku_pada, r.ditindaklanjuti_kasus_id
  FROM v3_rekomendasi r JOIN santri s ON s.id = r.santri_id
 WHERE s.nama_santri = 'SANTRI SMOKE AUDIT';

-- Konseling masih kosong sesudah migrasi
SELECT (SELECT COUNT(*) FROM v3_konseling_kasus) AS kasus,
       (SELECT COUNT(*) FROM v3_konseling_sesi) AS sesi,
       (SELECT COUNT(*) FROM v3_konseling_kasus_revisi) AS revisi;
```

**Rekomendasi untuk skenario A–D.** Skenario tersebut memerlukan satu rekomendasi
berstatus `Baru`, `tidak_berlaku_pada` kosong, dan `ditindaklanjuti_kasus_id`
kosong. Pada smoke Fase 2, rekomendasi `Perhatian Awal` sudah menjadi *Tidak
berlaku* karena poin dikembalikan. Bila belum ada yang berlaku:

1. Login sebagai pembimbing smoke, buka **Pelanggaran & poin**, lalu catat pelanggaran
   smoke (uraian diawali `SMOKE AUDIT F3`) sampai total poin masuk rentang ambang
   aktif. Rentangnya terlihat di **Katalog & Ambang V3**.
2. Rekomendasi lama untuk ambang yang sama akan **dipulihkan menjadi berlaku**,
   bukan dibuat baris baru. Itu perilaku T3 yang benar.
3. Catat ID pelanggaran tersebut untuk dibatalkan pada langkah pembersihan.

Bila tidak ingin mengubah poin, lewati bagian rekomendasi pada skenario A, B, dan
D, lalu catat bahwa bagian itu tidak diuji di produksi.

## 4. Skenario

Catat setiap ID dari URL (`?id=`) atau judul halaman cetak. Halaman utama:
**Pembinaan V3 → Konseling & tindak lanjut** (`/portal/v3_konseling.php`).

### A. Kasus Rahasia — pembimbing pemilik

Login **pembimbing smoke**.

1. Pada **Buka kasus konseling**: pilih `SANTRI SMOKE AUDIT`, kerahasiaan
   **Rahasia**, tujuan `SMOKE AUDIT F3 — kasus rahasia`, centang satu
   pelanggaran santri smoke, dan centang rekomendasi yang berlaku bila ada.
   Klik **Buka kasus**.
2. Pada detail, isi **Jadwalkan sesi baru**: jadwal besok, rencana tindak lanjut
   `SMOKE AUDIT F3 — rencana sesi rahasia`. Klik **Simpan sesi**.

Diharapkan:

- Muncul pesan *Kasus konseling dibuka dengan audit dan tautan yang dipilih.*
- Kerahasiaan tertulis *Hanya pembimbing pemilik kasus; admin dapat membuka untuk
  pengawasan dan tercatat audit.*
- Pelanggaran terhubung dan rekomendasi tertaut tampil.
- Sesudah sesi dibuat, status kasus menjadi **Dalam Pendampingan**.

**Catat:** `ID_RAHASIA`.

### B. Kasus Rahasia tidak terbuka bagi murobi; admin mengawasi

Login **murobi smoke**.

1. Buka **Konseling & tindak lanjut** → kasus Rahasia **tidak tercantum**.
2. Buka `/portal/v3_konseling_detail.php?id=ID_RAHASIA` → halaman penolakan
   (*Kasus tidak ditemukan atau tidak dapat diakses.*, HTTP 403).
3. Buka `/portal/v3_konseling_cetak.php?id=ID_RAHASIA` → halaman penolakan.
4. Buka detail pelanggaran yang ditautkan pada skenario A → bagian **Tindak lanjut
   konseling** **tidak** menyebut kasus Rahasia.
5. Buka **Notifikasi Saya** → tidak ada notifikasi baru *Pembaruan pendampingan*
   untuk langkah A.

Login **admin**, lalu buka `/portal/v3_konseling_detail.php?id=ID_RAHASIA` **satu kali** →
tujuan dan sesi tampil.

```sql
SELECT id, action, entity_id, created_at FROM audit_logs
 WHERE action = 'v3.konseling.kasus.dilihat.admin' AND entity_id = ID_RAHASIA
 ORDER BY id DESC;

SELECT COUNT(*) AS notifikasi_murobi_kasus_rahasia FROM notifikasi_outbox
 WHERE event_type IN ('v3_konseling_dibuka','v3_konseling_status')
   AND event_key LIKE 'v3:konseling:ID_RAHASIA:%';
```

Diharapkan: **tepat satu** baris audit akses untuk satu kali pembukaan, dan
hitungan notifikasi murobi **0**.

### C. Pembatalan kasus Rahasia menutup sesi terjadwal dan melepas rekomendasi

Login **pembimbing smoke**, lalu buka detail `ID_RAHASIA`.

1. Pada **Status kasus**, pilih **Dibatalkan**, isi alasan
   `SMOKE AUDIT F3 — uji pembatalan`, lalu klik **Ubah status**.

Diharapkan:

- Status kasus **Dibatalkan** dan formulir kelola hilang.
- Sesi terjadwal dari langkah A kini **Dibatalkan**.
- Rekomendasi yang tadi ditautkan kembali muncul pada **Buka kasus konseling →
  Rekomendasi berlaku yang belum ditindaklanjuti**.

```sql
SELECT id, status, realisasi, alasan_pembatalan FROM v3_konseling_sesi
 WHERE kasus_id = ID_RAHASIA;
SELECT id, status, ditindaklanjuti_kasus_id FROM v3_rekomendasi
 WHERE id = ID_REKOMENDASI;
```

Diharapkan pada sesi: `status='Dibatalkan'`, `realisasi` NULL, dan alasan
`Ditutup otomatis: kasus dibatalkan sebelum sesi dilaksanakan.` Pada rekomendasi:
`status='Baru'` dan `ditindaklanjuti_kasus_id` NULL.

### D. Kasus Internal, dua sesi, dan penautan rekomendasi ke kasus berjalan

Login **pembimbing smoke**.

1. Buka kasus baru dengan kerahasiaan **Internal**, tujuan
   `SMOKE AUDIT F3 — kasus internal`, dan centang pelanggaran yang sama. **Jangan**
   centang rekomendasi.
2. Buat **sesi 1** dengan jadwal satu jam lalu dan rencana tindak lanjut
   `SMOKE AUDIT F3 — rencana sesi 1`, lalu centang tautan pelanggaran.
3. Buat **sesi 2** dengan jadwal besok.
4. Pada **Tautkan pelanggaran atau rekomendasi**, centang rekomendasi yang dilepas
   pada skenario C, lalu klik **Simpan tautan**.
5. Buka detail pelanggaran tersebut.

Diharapkan:

- Rekomendasi pindah ke **Rekomendasi yang ditindaklanjuti** dan tidak lagi muncul
  pada formulir buka kasus.
- Detail pelanggaran pada **Tindak lanjut konseling** menampilkan kasus Internal
  beserta sesi 1 dan sesi 2 tanpa pelanggaran ganda. Karena Anda pemiliknya,
  kasus Rahasia yang sudah dibatalkan juga tetap tampil di sana. Itu benar;
  murobi tidak melihatnya (skenario B dan G).
- Poin santri tidak berubah karena penautan.

**Catat:** `ID_INTERNAL`, `ID_SESI_1`, dan `ID_SESI_2`.

### E. Menyelesaikan sesi, koreksi beralasan, dan penolakan koreksi pengosong

Pada detail `ID_INTERNAL`:

1. Pada formulir sesi 1, pilih **Selesai**. Isi ringkasan internal
   `SMOKE AUDIT F3 — ringkasan sesi 1` dan hasil `SMOKE AUDIT F3 — hasil sesi 1`,
   lalu **biarkan Tindak lanjut kosong**. Klik **Simpan status sesi**.
2. Buka **Koreksi sesi dengan revisi** pada sesi 1. Ubah hasil menjadi
   `SMOKE AUDIT F3 — hasil terkoreksi`, isi alasan, lalu klik **Buat revisi sesi**.
3. Buka lagi koreksi pada revisi terbaru, **kosongkan** ringkasan internal dan
   hasil, isi alasan, lalu kirim.

Diharapkan:

1. Sesi 1 **Selesai**, dan tindak lanjut tetap `SMOKE AUDIT F3 — rencana sesi 1`
   karena kolom kosong tidak menghapus rencana.
2. Sesi 1 lama berlabel *Direvisi menjadi sesi #…* dengan isi lama utuh, dan
   muncul baris sesi baru berisi hasil terkoreksi.
3. Muncul pesan *Sesi selesai wajib tetap memiliki ringkasan internal dan hasil.*
   dan tidak ada revisi tambahan.

### F. Penjadwalan ulang

Pada formulir sesi 2, pilih **Dijadwalkan Ulang**, ubah **Jadwal baru** menjadi
lusa, isi alasan `SMOKE AUDIT F3 — jadwal ulang`, lalu simpan.

Diharapkan: sesi 2 lama berlabel *Direvisi menjadi sesi #…*, dan sesi baru
berstatus **Dijadwalkan Ulang** dengan jadwal lusa.

### G. Murobi pada kasus Internal

Login **murobi smoke**.

1. Daftar konseling memuat kasus Internal, tetapi **tidak** kasus Rahasia.
2. Detail `ID_INTERNAL` menampilkan tujuan, ringkasan internal, dan hasil sesi.
   Formulir koreksi, status, dan sesi baru **tidak** tampil.
3. Pada **Tanda mengetahui murobi**, isi catatan `SMOKE AUDIT F3 — murobi mengetahui`,
   lalu klik **Tandai mengetahui kasus**. Catatan muncul pada **Riwayat catatan
   murobi**.
4. **Cetak sesuai hak akses** memuat isi internal.
5. **Notifikasi Saya** memuat notifikasi *Pembaruan pendampingan* dengan teks
   generik, tanpa nama santri atau isi konseling.

Tanda mengetahui kedua oleh murobi yang sama untuk kasus yang sama memang ditolak
(*Permintaan menduplikasi catatan yang sudah ada.*). Itu bukan kegagalan.

### H. Koreksi kasus tersimpan sebagai revisi

Login **pembimbing smoke**, lalu buka detail `ID_INTERNAL`. Pada **Koreksi kasus**,
ubah tujuan menjadi `SMOKE AUDIT F3 — kasus internal (koreksi)`, biarkan
kerahasiaan **Internal**, isi alasan, lalu klik **Simpan koreksi**.

Diharapkan: **Riwayat revisi kasus** memuat satu entri *dari versi N · pembimbing*
beserta alasan dan *Tujuan sebelumnya*, dan tampilan cetak ikut memuatnya.

```sql
SELECT id, versi_sebelum, kerahasiaan_sebelum, kerahasiaan_sesudah, kapasitas, alasan, created_at
  FROM v3_konseling_kasus_revisi WHERE kasus_id = ID_INTERNAL;
```

### I. Penutupan Selesai menutup sesi yang masih terjadwal

Pada detail `ID_INTERNAL`, di **Status kasus**, pilih **Selesai**, isi ringkasan
penutupan `SMOKE AUDIT F3 — penutupan`, lalu klik **Ubah status**.

Diharapkan:

- Status **Selesai** dan halaman menyebut sesi terjadwal ditutup otomatis.
- Sesi hasil jadwal ulang dari skenario F kini **Dibatalkan**.
- Sesi selesai, revisi, tautan pelanggaran, rekomendasi, dan catatan murobi tetap
  ada.
- Memuat ulang halaman tidak mengubah apa pun.

```sql
SELECT id, status, realisasi, alasan_pembatalan, revisi_dari_id FROM v3_konseling_sesi
 WHERE kasus_id = ID_INTERNAL ORDER BY id;
SELECT event_type, judul, isi, data_json FROM notifikasi_outbox
 WHERE event_type LIKE 'v3_konseling%' ORDER BY id DESC LIMIT 20;
```

Diharapkan pada outbox: seluruh `judul` bernilai `Pembaruan pendampingan`, `isi`
bernilai `Ada pembaruan pendampingan. Masuk untuk melihat sesuai kewenangan.`, dan
`data_json` bernilai `{"type":"v3_konseling"}`. Untuk kasus Internal, jenis
peristiwanya adalah `v3_konseling_dibuka` (kasus dibuka), `v3_konseling_sesi`
(sesi dibuat), `v3_konseling_sesi_status` (sesi selesai atau dijadwalkan ulang),
dan `v3_konseling_status` (penutupan). Perubahan ke **Dalam Pendampingan**
terjadi otomatis saat sesi pertama dibuat, sehingga tidak menghasilkan
`v3_konseling_status` tersendiri. Tidak ada satu pun baris untuk kasus Rahasia.

### J. Orang tua dan akses menebak ID

Login **orang tua smoke**.

1. Menu **Konseling & tindak lanjut** tidak tampil.
2. `/portal/v3_konseling_detail.php?id=ID_INTERNAL` dan `…?id=ID_RAHASIA` → ditolak.
3. `/portal/v3_konseling_cetak.php?id=ID_INTERNAL` → ditolak.

Opsional bila ada pembimbing produksi lain yang **tidak** mencakup santri smoke:
login dengan akun tersebut hanya bila Anda berwenang atasnya, lalu buka
`?id=ID_INTERNAL` → ditolak. Jangan meminjam akun petugas sungguhan tanpa izin
pemiliknya. Bila tidak dilakukan, catat bahwa penolakan lintas cakupan pembimbing
hanya terbukti pada database uji.

### K. Opsional — tampilan 375 px

Buka detail `ID_INTERNAL` di ponsel sebagai pembimbing. Diharapkan tidak ada
geser horizontal dan setiap kolom formulir berlabel.

## 5. Pembersihan dan post-check akhir

1. **Kembalikan poin uji.** Batalkan setiap pelanggaran smoke yang dibuat khusus
   pada langkah 3, dengan alasan `SMOKE AUDIT F3 — pengembalian poin uji`.
   Rekomendasi yang tertaut ke kasus Internal tetap tertaut dan dapat berubah
   menjadi *Tidak berlaku*; itu perilaku yang benar.
2. **Jangan membatalkan pelanggaran smoke lama dari Fase 2** kecuali memang bagian
   rencana pengembalian poin.
3. Kasus dan sesi smoke **tidak dihapus**. Keduanya sudah berstatus akhir
   (Dibatalkan dan Selesai).
4. Jalankan ulang:

```bash
php bin/v3_verify.php; echo "exit=$?"
php bin/v3_phase2_verify.php; echo "exit=$?"
php bin/v3_phase3_verify.php; echo "exit=$?"
```

Diharapkan ketiganya `exit=0` tanpa blocker, dan `v3_phase2_verify.php` tetap
menunjukkan rekonsiliasi agregat–ledger berselisih nol.

## 6. Bila menemukan masalah

- Hentikan skenario berikutnya dan simpan pesan galat, URL tanpa kredensial, serta
  waktu kejadian.
- **Utamakan rollback kode** ke rilis sebelumnya.
- Rollback skema hanya atas keputusan operator dan sesudah backup:
  `php bin/migrate.php rollback` dijalankan dua kali, pertama untuk 017 lalu
  untuk 016. Rollback keduanya tidak menghapus kasus, sesi, alasan, tautan
  rekomendasi, maupun riwayat revisi yang berisi (lihat
  [migrasi dan rollback](migrasi-dan-rollback.md)).
- Jangan menghapus baris smoke secara manual untuk "membersihkan" kegagalan.

## 7. Lembar hasil

Salin tabel ini, isi, lalu kirim ke auditor bersama keluaran CLI dan hasil query.

| Langkah | Hasil (LULUS/GAGAL/DILEWATI) | ID / catatan |
| --- | --- | --- |
| 1. Backup dan konfirmasi berkas | | |
| 2. Post-check CLI (preflight, 3 verifier) | | |
| 3. Prasyarat data dan rekomendasi | | ID_REKOMENDASI = |
| A. Kasus Rahasia dibuka | | ID_RAHASIA = |
| B. Murobi ditolak, admin diaudit sekali, notifikasi 0 | | |
| C. Batal menutup sesi dan melepas rekomendasi | | |
| D. Kasus Internal, dua sesi, tautan rekomendasi | | ID_INTERNAL = |
| E. Sesi selesai, rencana utuh, revisi, pengosong ditolak | | |
| F. Jadwal ulang | | |
| G. Murobi membaca Internal, tanda mengetahui, notifikasi generik | | |
| H. Revisi kasus | | |
| I. Selesai menutup sesi terjadwal, payload generik | | |
| J. Orang tua ditolak | | |
| K. 375 px (opsional) | | |
| 5. Pembersihan dan verifier akhir | | |
