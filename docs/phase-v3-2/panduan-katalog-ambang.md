# Panduan pengisian Katalog & Ambang V3

Untuk admin pesantren yang mengisi menu **Katalog & Ambang V3**
(`/admin/admin_v3_katalog.php`). Dokumen ini menjelaskan arti setiap isian,
aturan yang ditegakkan server, dan contoh siap pakai.

Isi katalog menentukan apa yang bisa dipilih pembimbing saat mencatat
pelanggaran, dan ambang menentukan kapan pembimbing diingatkan untuk
menindaklanjuti. Salah isi di sini berakibat langsung pada pekerjaan harian
pembimbing, jadi baca bagian "Aturan yang menolak simpan" sebelum mulai.

## 1. Cara ketiganya bekerja sama

```
Kategori  →  Jenis Pelanggaran  →  (dipakai mencatat)  →  poin di-snapshot
   (payung)      (poin default)                                  ↓
                                              akumulasi poin per santri per tahun ajaran
                                                                 ↓
                                         Ambang Poin  →  rekomendasi tindak lanjut (manual)
```

Tiga prinsip yang menentukan cara mengisi:

1. **Poin di-snapshot saat pencatatan.** Mengubah poin default sebuah jenis di
   kemudian hari tidak mengubah catatan yang sudah ada. Koreksi katalog aman
   dilakukan; sejarah tidak ikut berubah.
2. **Akumulasi dihitung per santri per tahun ajaran.** Ganti tahun ajaran,
   hitungan mulai dari nol, sementara riwayat lama tetap terbaca.
3. **Ambang hanya memberi rekomendasi, bukan hukuman.** Sistem tidak pernah
   menjatuhkan sanksi, membuat kasus konseling, atau mengubah nilai secara
   otomatis. Keputusan pembinaan tetap di tangan petugas yang berwenang.

## 2. Kategori — payung besar jenis pelanggaran

Mengelompokkan pelanggaran agar laporan terbaca per bidang pembinaan, dan agar
satu kelompok dapat diakhiri sekaligus.

| Isian | Wajib | Aturan | Contoh |
| --- | --- | --- | --- |
| Kode unik | ya | maks 40 karakter, otomatis menjadi HURUF BESAR, hanya huruf/angka/`.`/`_`/`-`, diawali huruf atau angka | `KDS` |
| Nama | ya | maks 150 karakter | `Kedisiplinan` |
| Uraian | tidak | maks 5.000 karakter | `Pelanggaran terkait waktu, kehadiran, dan ketertiban jadwal harian.` |
| Mulai berlaku | ya | tanggal | `2026-07-01` |
| Selesai berlaku | tidak | kosongkan bila berlaku terus; tidak boleh lebih awal dari tanggal mulai | *(kosong)* |
| Status | ya | Aktif / Nonaktif | `Aktif` |
| Alasan perubahan | saat mengubah | wajib ketika mengedit data lama; boleh kosong saat membuat baru | `Penyesuaian tata tertib TA 2026/2027` |

### Contoh set kategori

| Kode | Nama | Uraian |
| --- | --- | --- |
| `KDS` | Kedisiplinan | Keterlambatan, bolos, keluar tanpa izin |
| `IBD` | Ibadah | Jamaah, pengajian, tilawah |
| `AKH` | Akhlak & Kesopanan | Perkataan dan sikap kepada ustaz serta teman |
| `KBR` | Kebersihan & Ketertiban | Piket, kerapian kamar dan barang |

## 3. Jenis Pelanggaran — item yang dipilih pembimbing

Inilah yang muncul di dropdown ketika pembimbing mencatat kejadian.

| Isian | Wajib | Aturan | Contoh |
| --- | --- | --- | --- |
| Kode unik | ya | sama seperti kategori | `KDS.TLM` |
| Nama | ya | maks 150 karakter | `Terlambat masuk kelas` |
| Poin default | ya | bilangan bulat, minimal 0 | `2` |
| Mulai berlaku | ya | tanggal | `2026-07-01` |
| Selesai berlaku | tidak | kosongkan bila berlaku terus | *(kosong)* |
| Status | ya | Aktif / Nonaktif | `Aktif` |
| Kategori | ya | pilih dari kategori yang sudah dibuat | `Kedisiplinan` |
| Tingkat | ya | hanya `Ringan`, `Sedang`, atau `Berat` | `Ringan` |
| Uraian | tidak | maks 5.000 karakter | `Terlambat lebih dari 10 menit tanpa alasan yang dibenarkan.` |

**Tingkat dan Poin adalah dua hal berbeda.** *Tingkat* label kualitatif untuk
laporan dan pembacaan cepat. *Poin* angka yang benar-benar dijumlahkan dan
memicu ambang. Skalanya bebas, tetapi jaga konsistensi — misalnya Ringan 1–5,
Sedang 6–15, Berat 20 ke atas.

### Contoh set jenis pelanggaran

| Kode | Nama | Kategori | Tingkat | Poin |
| --- | --- | --- | --- | --- |
| `KDS.TLM` | Terlambat masuk kelas | Kedisiplinan | Ringan | 2 |
| `KDS.BLS` | Bolos pelajaran | Kedisiplinan | Sedang | 10 |
| `KDS.KLR` | Keluar pondok tanpa izin | Kedisiplinan | Berat | 25 |
| `IBD.JMH` | Tidak ikut jamaah tanpa uzur | Ibadah | Ringan | 3 |
| `IBD.NGJ` | Tidak mengikuti pengajian | Ibadah | Sedang | 8 |
| `AKH.KSR` | Berkata kasar kepada teman | Akhlak & Kesopanan | Ringan | 3 |
| `AKH.LWN` | Tidak sopan atau melawan ustaz | Akhlak & Kesopanan | Berat | 30 |
| `KBR.PKT` | Tidak melaksanakan piket | Kebersihan & Ketertiban | Ringan | 2 |

## 4. Ambang Poin — kapan pembimbing diingatkan

| Isian | Wajib | Aturan | Contoh |
| --- | --- | --- | --- |
| Label ambang | ya | maks 150 karakter | `Pembinaan terjadwal` |
| Nilai minimum | ya | minimal 0 | `25` |
| Nilai maksimum | tidak | kosong berarti tanpa batas atas; bila diisi tidak boleh lebih kecil dari minimum | `49` |
| Mulai berlaku | ya | tanggal | `2026-07-01` |
| Selesai berlaku | tidak | kosongkan bila berlaku terus | *(kosong)* |
| Status | ya | Aktif / Nonaktif | `Aktif` |
| Tahun ajaran | ya | otomatis terisi tahun ajaran aktif | `2026/2027 / Ganjil` |
| Rekomendasi | ya | maks 5.000 karakter; tulis sebagai kalimat tindakan untuk pembimbing | `Panggil santri, lakukan konseling awal, dan beri tahu murobi.` |

### Contoh susunan ambang bertingkat

Rentang tidak boleh tumpang tindih, jadi susun berurutan dan rapat:

| Rentang | Label | Rekomendasi |
| --- | --- | --- |
| 10 – 24 | Perhatian awal | Teguran lisan oleh pembimbing dan catat hasilnya. Beri tahu murobi. |
| 25 – 49 | Pembinaan terjadwal | Jadwalkan sesi konseling. Koordinasikan dengan murobi. |
| 50 – 99 | Pembinaan intensif | Konseling lanjutan dan siapkan ringkasan untuk orang tua. |
| 100 – *(kosong)* | Evaluasi pimpinan | Bawa ke rapat pembinaan. Keputusan tetap di tangan pimpinan. |

Rentang 0–9 sengaja dibiarkan kosong supaya satu atau dua pelanggaran ringan
tidak langsung memunculkan rekomendasi.

**Jangan membuat ambang dengan nilai minimum `0`** kecuali memang menginginkan
rekomendasi muncul pada pelanggaran pertama setiap santri — total `2` pun sudah
masuk rentang `0–24`.

## 5. Aturan yang menolak simpan

Server menegakkan aturan berikut. Pesan galat muncul di atas formulir dan tidak
ada baris yang tersimpan.

| Pesan | Sebab | Cara memperbaiki |
| --- | --- | --- |
| `Kode hanya boleh memuat huruf, angka, titik, garis bawah, atau tanda hubung.` | Kode memakai spasi atau simbol lain | Pakai pola seperti `KDS.TLM` |
| `Periksa isian kode.` / `Periksa isian nama.` | Kosong atau melebihi batas panjang | Isi dan pendekkan |
| `Masa berlaku katalog harus berada dalam kategori aktif.` | Jenis pelanggaran mulai lebih awal daripada kategorinya, melewati tanggal selesai kategori, atau kategorinya nonaktif | Samakan atau persempit masa berlaku jenis; aktifkan kategorinya |
| `Tingkat tidak valid.` | Nilai selain Ringan/Sedang/Berat | Pilih dari dropdown |
| `Maksimum tidak boleh lebih kecil dari minimum.` | Ambang terbalik | Perbaiki angkanya |
| `Rentang ambang bertumpang tindih pada masa berlaku yang sama.` | Ada ambang aktif lain di tahun ajaran itu yang rentangnya beririsan | Persempit rentang, atau nonaktifkan ambang lama lebih dulu |
| `Tanggal selesai tidak boleh sebelum tanggal mulai.` | Urutan tanggal terbalik | Perbaiki tanggalnya |
| `Data telah berubah. Muat ulang sebelum menyimpan.` | Orang lain menyimpan lebih dulu di tab lain | Muat ulang halaman, ulangi perubahan |
| `Periksa isian alasan.` | Mengubah data lama tanpa alasan | Isi kolom Alasan perubahan |

## 6. Hal yang sering keliru

**Katalog dinilai berdasarkan tanggal kejadian, bukan tanggal input.** Bila
sebuah jenis pelanggaran mulai berlaku hari ini lalu pembimbing mencatat
kejadian minggu lalu, pencatatan ditolak dengan pesan *"Katalog tidak aktif pada
tanggal kejadian."* Isi Mulai berlaku dengan tanggal yang cukup awal.

**Ambang di tahun ajaran non-aktif tidak memicu apa pun.** Boleh disimpan untuk
persiapan tahun depan, tetapi halaman menampilkan peringatan operator dan
rekomendasi tidak akan terbentuk sampai tahun ajaran itu aktif.

**Tidak ada hapus permanen.** Untuk menghentikan sebuah item, pilih status
`Nonaktif` atau isi Selesai berlaku. Ketiadaan tombol hapus disengaja: catatan
bisnis tidak pernah dihapus.

**Setiap pengubahan data lama wajib mengisi Alasan perubahan.** Alasan itu masuk
audit bersama pelaku, waktu, dan nilai sebelum/sesudah. Saat membuat data baru,
kolom tersebut boleh kosong.

**Mengubah poin default tidak mengubah catatan lama.** Ini disengaja. Bila poin
sebuah catatan lama memang perlu diubah, gunakan koreksi admin beralasan pada
catatan tersebut, bukan mengubah katalog.

## 7. Set minimal untuk verifikasi alur

Untuk membuktikan rantai snapshot → ledger → ambang → rekomendasi bekerja, tiga
baris ini cukup, dan poinnya sengaja langsung menyentuh ambang.

1. **Kategori** — Kode `KDS`, Nama `Kedisiplinan`, Mulai `2026-07-01`, Aktif
2. **Jenis** — Kode `KDS.BLS`, Nama `Bolos pelajaran`, Kategori `Kedisiplinan`,
   Tingkat `Sedang`, Poin `10`, Mulai `2026-07-01`, Aktif
3. **Ambang** — Label `Perhatian awal`, Minimum `10`, Maksimum `24`, tahun
   ajaran aktif, Rekomendasi `Teguran lisan oleh pembimbing dan catat hasilnya.`,
   Mulai `2026-07-01`, Aktif

Setelah itu satu pencatatan pelanggaran menghasilkan total 10 poin dan
memunculkan tepat satu rekomendasi pada halaman detail pelanggaran.
