# V2 Fase 5 — Bukti Performa dan `EXPLAIN`

Kriteria penerimaan PRD: *"Halaman pertama laporan selesai maksimal 2 detik pada
fixture minimal 1.000 pengajuan."*
Persyaratan §6: *"Ukur query dengan fixture minimal 1.000 pengajuan; tambahkan
indeks hanya setelah `EXPLAIN`."*

## 1. Kesimpulan lebih dulu

| Pertanyaan | Jawaban |
| --- | --- |
| Apakah target 2 detik tercapai pada ≥1.000 pengajuan? | **Ya.** Terburuk **24,0 ms** dari ambang 2.000 ms — margin ±83×. |
| Apakah indeks laporan baru ditambahkan? | **Tidak.** |
| Mengapa tidak? | Tiga indeks kandidat **diuji** dan **dibuang** karena selisihnya masih berada di dalam derau pengukuran dan tidak dapat diulang. Menambahkan indeks yang tidak terbukti hanya memperlambat penulisan tanpa mempercepat pembacaan. |
| Apakah ada indeks yang ditambahkan sama sekali? | Ya, satu — `notifikasi_receipt_index`, tetapi itu untuk **worker receipt push**, bukan laporan, dan pola aksesnya diketahui pasti (lihat §6). |

## 2. Cara mengulang pengukuran

```bash
# Fixture deterministik (benih tetap, bukan acak) — dapat diulang persis
V2_PHASE5_FIXTURE=1 php bin/v2_phase5_fixture.php --jumlah=1000

# Pengukuran + rencana eksekusi
V2_PHASE5_UKUR=1 php bin/v2_phase5_ukur_laporan.php --ulang=9 --explain

# Gerbang otomatis (bagian dari rangkaian uji)
V2_PHASE5_RUN_PERF=1 php tests/v2_phase5_performance.php
```

Yang diukur adalah pekerjaan halaman pertama yang **sesungguhnya**, bukan satu
query pilihan: `summary()` + `decisionDuration()` + `page()`. Yang dilaporkan
sebagai gerbang adalah waktu **terburuk** dari pengulangan, bukan yang terbaik.

## 3. Hasil pada 1.000+ pengajuan (gerbang PRD)

Lingkungan: PHP 8.4.21, MariaDB 10.11.14. Fixture: 1.028 pengajuan,
550 keputusan. 5 pengulangan per skenario.

| Skenario | Median | Terburuk | Ambang | Hasil |
| --- | ---: | ---: | ---: | --- |
| admin — rentang penuh | 20,0 ms | 20,6 ms | 2.000 ms | LULUS |
| admin — status Disetujui | 7,5 ms | 8,0 ms | 2.000 ms | LULUS |
| admin — basis keputusan | 16,0 ms | 18,2 ms | 2.000 ms | LULUS |
| admin — durasi ≥ 24 jam | 16,1 ms | 16,3 ms | 2.000 ms | LULUS |
| admin — filter kamar | 9,0 ms | 9,3 ms | 2.000 ms | LULUS |
| admin — pencarian teks | 22,1 ms | 24,0 ms | 2.000 ms | LULUS |
| admin — halaman ke-10 | 20,2 ms | 21,8 ms | 2.000 ms | LULUS |
| pengurus — cakupan sendiri | 1,3 ms | 1,8 ms | 2.000 ms | LULUS |
| murobi — cakupan sendiri | 1,2 ms | 1,4 ms | 2.000 ms | LULUS |
| orang tua — cakupan sendiri | 2,2 ms | 2,7 ms | 2.000 ms | LULUS |

**Waktu terburuk seluruh skenario: 24,0 ms.**
Ekspor penuh 1.000 baris (di luar kriteria "halaman pertama"): 13,6 ms.

Cakupan non-admin jauh lebih cepat karena predikat cakupan langsung memakai
`izin_pengajuan_pengurus_index` / `izin_pengajuan_murobi_index`.

## 4. Uji tekanan pada 20.000 pengajuan

Untuk mengetahui perilaku pertumbuhan, pengukuran diulang pada 20.004 pengajuan
(16,6× persyaratan PRD):

| Skenario | Median @1k | Median @20k |
| --- | ---: | ---: |
| admin — rentang penuh | 20,0 ms | ±290 ms |
| admin — status Disetujui | 7,5 ms | ±115 ms |
| admin — pencarian teks | 22,1 ms | ±295 ms |
| pengurus — cakupan sendiri | 1,3 ms | ±70 ms |

Waktu terburuk pada 20.004 pengajuan: **397,7 ms** — masih 5× di bawah ambang.
Pertumbuhannya mendekati linier, sehingga ambang 2 detik diperkirakan baru
tersentuh di sekitar **±100.000 pengajuan** pada perangkat keras setara.

> **Ambang peninjauan ulang.** Bila `izin_pengajuan` melewati **50.000 baris**,
> jalankan ulang `bin/v2_phase5_ukur_laporan.php --explain` dan pertimbangkan
> kembali indeks kandidat pada §5. Sebelum itu, penambahan indeks tidak
> didukung bukti.

## 5. Indeks kandidat yang DIUJI dan DIBUANG

Tiga indeks berikut dipasang pada database uji, diukur, lalu **dilepas kembali**:

```sql
ALTER TABLE izin_pengajuan ADD KEY (tgl_izin, id);
ALTER TABLE plotting_kamar ADD KEY (id_santri, id_tahun);
ALTER TABLE plotting_kelas ADD KEY (id_santri, id_tahun, status);
```

Perbandingan median pada 20.004 pengajuan, 9 pengulangan:

| Skenario | Tanpa indeks | Dengan 3 indeks | Selisih |
| --- | ---: | ---: | --- |
| admin — rentang penuh | 263,2 ms | 287,7 ms | **lebih lambat** |
| admin — status Disetujui | 114,2 ms | 98,0 ms | lebih cepat |
| admin — basis keputusan | 266,5 ms | 247,3 ms | lebih cepat |
| admin — durasi ≥ 24 jam | 232,5 ms | 234,6 ms | setara |
| admin — filter kamar | 208,6 ms | 192,2 ms | lebih cepat |
| admin — pencarian teks | 296,8 ms | 283,2 ms | lebih cepat |
| pengurus — cakupan sendiri | 75,0 ms | 75,0 ms | setara |
| murobi — cakupan sendiri | 61,1 ms | 71,3 ms | **lebih lambat** |

Arahnya **tidak konsisten**: dua skenario justru melambat, dua setara, dan
sisanya membaik dalam rentang ±10–15% yang setara dengan sebaran antar
pengulangan. Pada putaran dengan pengulangan lebih sedikit, urutannya bahkan
berbalik.

**Keputusan: tidak menambahkan ketiganya.** Bukti pengukuran tidak mendukung,
dan PRD §6 secara eksplisit mensyaratkan indeks hanya ditambahkan bila didukung
hasil pengukuran. Kandidat dan ambang peninjauannya dicatat di sini agar
keputusan ini dapat ditinjau ulang dengan data, bukan diulang dari nol.

## 6. Satu indeks yang DITAMBAHKAN — dan alasannya

Migrasi 009 menambahkan **satu** indeks, dan bukan untuk laporan:

```sql
ALTER TABLE notifikasi_outbox ADD KEY notifikasi_receipt_index (receipt_status, dikirim_pada, id);
```

Alasannya berbeda secara mendasar dari kandidat pada §5:

- pola aksesnya **diketahui pasti** karena hanya ada satu pemanggil,
  `OutboxRepository::pendingReceipts()`;
- query itu dijalankan **cron setiap 15 menit** dan mencari baris berstatus
  `Menunggu` yang paling lama menunggu;
- tanpa indeks ini, query tersebut memindai **seluruh** tabel outbox pada setiap
  putaran cron, dan outbox tumbuh terus seumur sistem;
- kolom `receipt_status` bersifat sangat selektif (mayoritas baris berstatus
  `Belum Diperlukan`).

Ini indeks untuk pekerjaan latar berkala pada tabel yang tumbuh tanpa batas,
bukan spekulasi terhadap query laporan.

## 7. Rencana eksekusi (`EXPLAIN`) pada 20.004 pengajuan

Query ringkasan dan detail, tanpa indeks baru:

```
EXPLAIN ringkasan  table=s   type=index   key=nis                                rows=204  Extra=Using index; Using temporary; Using filesort
EXPLAIN ringkasan  table=p   type=ref     key=izin_pengajuan_santri_range_index  rows=48   Extra=Using index condition
EXPLAIN ringkasan  table=k   type=eq_ref  key=izin_keputusan_pengajuan_unique    rows=1

EXPLAIN detail     table=s   type=ALL     key=NULL                               rows=204  Extra=Using temporary; Using filesort
EXPLAIN detail     table=p   type=ref     key=izin_pengajuan_santri_range_index  rows=48   Extra=Using index condition
EXPLAIN detail     table=pg  type=eq_ref  key=PRIMARY                            rows=1
EXPLAIN detail     table=g   type=eq_ref  key=PRIMARY                            rows=1
EXPLAIN detail     table=ta  type=eq_ref  key=PRIMARY                            rows=1
EXPLAIN detail     table=k   type=eq_ref  key=izin_keputusan_pengajuan_unique    rows=1
EXPLAIN detail     table=kp  type=eq_ref  key=PRIMARY                            rows=1
EXPLAIN detail     table=o   type=ref     key=notifikasi_pengajuan_index         rows=1
```

Untuk filter status:

```
EXPLAIN ringkasan  table=p   type=ref     key=izin_pengajuan_status_index        rows=420
EXPLAIN detail     table=p   type=ref     key=izin_pengajuan_status_index        rows=420
```

Yang perlu dibaca dari rencana ini:

1. **Query laporan sudah memakai indeks yang dibuat migrasi 006/007.** Tidak ada
   indeks baru yang perlu dibuat untuk itu:
   `izin_pengajuan_santri_range_index`, `izin_pengajuan_status_index`,
   `izin_pengajuan_pengurus_index`, `izin_pengajuan_murobi_index`,
   `izin_keputusan_pengajuan_unique`, dan `notifikasi_pengajuan_index`.
2. **Seluruh JOIN bersifat `eq_ref` atau `ref`** — tidak ada perkalian baris.
   Inilah yang membuat `COUNT` ringkasan selalu sama dengan jumlah baris
   detail/CSV. Kamar dan kelas sengaja dibaca lewat subquery skalar, **bukan**
   `JOIN`, karena satu santri dapat memiliki lebih dari satu baris plotting dan
   `JOIN` akan menggandakan baris pengajuan.
3. `Using temporary; Using filesort` berasal dari `GROUP BY p.status` dan
   `ORDER BY p.tgl_izin DESC, p.id DESC`. Inilah yang coba dihilangkan indeks
   kandidat `(tgl_izin, id)` pada §5 — dan yang ternyata tidak memberi perbaikan
   yang dapat diulang pada volume ini.

## 8. Fixture pengukuran

`bin/v2_phase5_fixture.php` menghasilkan data **deterministik** (benih tetap,
bukan acak) agar dua putaran dapat dibandingkan:

| Aspek | Nilai |
| --- | --- |
| Sebaran status | ±35% Disetujui, ±20% Ditolak, ±25% Diajukan, ±12% Perlu Penetapan Admin, ±8% Dibatalkan |
| Durasi keputusan | 30 menit s.d. ±14 hari (16 nilai berulang) sehingga median bermakna |
| Kapasitas keputusan | 1 dari 12 diambil `Admin Pengganti` dengan alasan penggantian terisi |
| Sebaran master | 200 santri, 10 kamar, 8 pengurus, 8 murobi |
| Rentang tanggal | ±2 tahun sehingga filter tanggal bermakna |
| Penanda | seluruh baris berawalan `P5` dan dapat dibersihkan dengan `--bersihkan` |

Penjaga: hanya CLI, hanya database berakhiran `_test`, memerlukan
`V2_PHASE5_FIXTURE=1`, dan **menolak** `APP_ENV=production`. Fixture tidak
pernah dijalankan pada basis data produksi.

## 9. Batas yang jujur

- Angka di atas berasal dari MariaDB 10.11 pada mesin pengujian. **cPanel
  produksi dapat memakai MySQL 5.7 dengan perangkat keras berbeda**; ulangi
  pengukuran pada staging cPanel sebelum menyatakan target terpenuhi di sana.
- Fixture bersifat sintetis. Sebaran data nyata (mis. satu murobi menangani
  sebagian besar pengajuan) dapat menghasilkan rencana eksekusi berbeda.
- Pengukuran dilakukan pada basis data yang cache-nya hangat. Query pertama
  setelah restart akan lebih lambat; margin 83× membuat hal itu tidak
  mengkhawatirkan pada volume saat ini.
