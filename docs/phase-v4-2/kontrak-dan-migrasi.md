# Kontrak pengisian nilai dan operasi Fase 2

## Penyimpanan dan otorisasi

Tidak ada migrasi baru atau backfill. Fase 2 memakai skema **020** yang sudah diaudit: `v4_rencana`, `v4_rencana_mapel`, `v4_nilai`, `v4_nilai_riwayat`, dan `audit_logs`. Unique key `(rencana_mapel_id,santri_id,komponen,nomor_harian)` menentukan satu komponen berlaku; rencana mapel sudah unik per kelas/mapel/semester. Harian bernomor 1–100, Tengah/Akhir wajib nomor 0; nilai 0–100 maksimal dua desimal. Nomor harian tetap merupakan identitas, bukan posisi array.

Daftar santri memakai penempatan kelas/semester, termasuk penempatan selesai, atau nilai yang sudah tersimpan pada rencana itu. Tidak membaca kelas aktif santri untuk memindahkan nilai. Rencana lama tetap mengikat kelas/semester asal. Perpindahan ambigu tidak diselesaikan otomatis; finalisasi merupakan fase selanjutnya.

Otorisasi memakai capability Fase 1 dan konteks kelas/mapel/semester pada setiap request. Baca Pendidikan memakai pilihan pengganti; mutasi Pendidikan memakai endpoint/tindakan pengganti tersendiri. Admin tetap perlu alasan dan kapasitasnya `admin`. Wali tidak dapat membaca nilai internal. Mutasi memerlukan master mapel/kelas dan semester aktif. Capability aktif diturunkan dari DB, bukan role/cakupan payload.

Transaksi mengunci parent rencana lalu rencana mapel, sama urutannya dengan penyimpanan rencana Fase 1. Setelah itu seluruh komponen santri dikunci dan versi diperiksa. Ini menyelaraskan input awal dengan perubahan target rencana dan menserialisasi penulis bersamaan. Perubahan nilai, snapshot revisi, dan audit berada dalam transaksi yang sama.

Setiap komponen baru memakai `version: 0`; versi tersimpan dimulai 1. Semua komponen tersimpan harus disertakan dalam request berikutnya dengan versi terkini. Komponen baru yang kosong di web diabaikan, komponen tersimpan tidak dapat dikosongkan/dihapus. Respons konflik tidak melakukan replay: retry identik setelah berhasil mendapat **409** dan klien memuat ulang. Unique key tetap menjadi pertahanan database.

Tindakan `draf` menyimpan komponen baru; perubahan nilai/catatan tersimpan memakai `koreksi` dengan alasan wajib. `serahkan` memerlukan sedikitnya satu harian, tengah, dan akhir, lalu seluruh komponen menjadi `Dikirim`. `koreksi` mengembalikan seluruh isian ke `Draf` dan membuat revisi baru. Status fase lanjut belum dibuka. Riwayat menyimpan nilai, catatan, versi, pelaku, kapasitas, alasan, dan waktu. Audit umum menyimpan metadata versi/status/kapasitas dan penanda alasan tercatat; isi sensitif tetap di riwayat terotorisasi.

Harian dirata-ratakan sebelum bobot 40/30/30; perhitungan antara tidak dibulatkan lebih awal. Hasil internal API dua desimal, nilai tampil dibulatkan half-up dari hasil sebelum pembulatan tampilan, predikat dari nilai tampil. `rata_harian` tersedia. `tuntas` membandingkan hasil sebelum pembulatan tampilan dengan target. Rata-rata seluruh rapor tidak dikerjakan pada fase ini.

## API aditif

Semua rute memerlukan bearer token, respons privat `no-store`, dan otorisasi server. API tidak memakai autentikasi cookie; CSRF formulir web diperiksa melalui `Csrf::requireValid`.

- `GET /api/v1/v4/nilai/options` — rencana mapel sesuai penugasan guru; tambah `?pengganti=1` untuk Pendidikan/admin.
- `GET /api/v1/v4/nilai?rencana_mapel_id=ID&santri_id=ID` — konteks, daftar santri, komponen, hasil, riwayat. Tanpa santri: daftar saja. Tambah `pengganti=1` untuk jalur Pendidikan.
- `POST /api/v1/v4/nilai` — tindakan guru/admin.
- `POST /api/v1/v4/nilai/pengganti` — tindakan pengganti Pendidikan/admin, alasan wajib.

Contoh payload (ID harus berasal dari respons opsi/daftar terotorisasi):

```json
{
  "rencana_mapel_id": 1,
  "santri_id": 1,
  "action": "draf",
  "alasan": "Guru berhalangan",
  "komponen": [
    {"komponen":"Harian","nomor_harian":1,"nilai":80,"version":0,"catatan":"Capaian praktik"},
    {"komponen":"Harian","nomor_harian":2,"nilai":90,"version":0},
    {"komponen":"Tengah","nomor_harian":0,"nilai":78,"version":0},
    {"komponen":"Akhir","nomor_harian":0,"nilai":92,"version":0}
  ]
}
```

Respons hasil memuat `kelengkapan`, `rata_harian`, `nilai_internal`, `nilai_tampil`, `predikat`, `target_ketuntasan`, `tuntas`. Tidak lengkap menghasilkan hasil/predikat `null`, bukan nol. Status galat: 401 tanpa token; 403 cakupan; 409 versi/duplikasi/status; 422 validasi; 503 gagal transaksi. Alur web ada di `/portal/nilai_semester.php`, pengganti pada `?pengganti=1`.

## Penerapan dan pemulihan

Tidak ada penerapan produksi pada fase ini. Prasyarat operasional tetap skema 020 dan kode baseline audit Fase 1. Sebelum rilis yang kelak disetujui, simpan backup konsisten DB/berkas privat dan hash commit; gunakan panduan `../phase-v4-1/migrasi-rollback.md`. Fase 2 tidak perlu `ALTER`/`DROP` atau migrasi data tambahan.

Pada salinan uji baru, migrasi 001–020 diterapkan, manifest dibaca sesudah baseline terpasang, `up` ulang menghasilkan tidak ada migrasi baru, verifier menunjukkan nol yatim V4 dan jumlah bisnis lama tetap. Ini memverifikasi prasyarat/re-run, **bukan** latihan migrasi baru atau backup produksi. Lihat `migrasi-uji.txt`, `preflight.txt`, `verifier.txt`.

Rollback kode dapat kembali ke baseline Fase 1 setelah endpoint/formulir Fase 2 dinonaktifkan; pertahankan seluruh tabel nilai dan riwayat. Jangan rollback 020 setelah ada nilai. Tidak ada latihan pemulihan backup produksi dalam sesi ini. Perubahan penanda tangan/rencana yang telah memiliki nilai tetap mengikuti pembatas Fase 1 dan menunggu keputusan Fase 4.
