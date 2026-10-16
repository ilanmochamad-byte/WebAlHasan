# Kontrak bahan dan migrasi Fase 3

## Satuan pemeriksaan dan atribusi

- Nilai: satu bahan untuk **rencana mapel + santri**; rencana mapel sudah mengikat kelas dan semester. Seluruh harian/praktik, tengah, dan akhir harus lengkap serta diserahkan. Tidak ada verifikasi parsial per komponen. `v4_bahan` menyimpan pemeriksa, kapasitas, alasan, status, versi dan fingerprint; `v4_bahan_riwayat` menyimpan revisinya. Verifikasi/pengembalian tidak menulis `v4_nilai` atau `v4_nilai_riwayat`.
- Simpan/koreksi/penyerahan Fase 2 menulis status bahan dalam transaksi yang sama. Perilaku komponen Fase 2 tetap: koreksi mengembalikan seluruh komponen yang berstatus Dikirim menjadi Draf; perubahan **status** itu memang menghasilkan revisi komponen atas aktor koreksi (catatan auditor Fase 2). Komponen yang nilai, catatan, dan statusnya sama tetap dilewati dan atribusinya dipertahankan. Tidak ada perubahan bobot, rumus, pembulatan, skala atau predikat.
- Sikap: satu bahan per santri–semester pada kelas asal. `pembimbing_user_id` pertama dipertahankan; seluruh versi menyimpan aktor sebenarnya di riwayat. Tiga aspek wajib diisi; catatan opsional maksimal 2000 karakter. Pengisi aktif saat ini harus tetap mencakup santri; ID pengisi historis tidak memberi hak akses. Murobi mengetahui satu kali sampai revisi isi/penyerahan pembimbing berikutnya; koreksi murobi beralasan membuat versi baru dan menjadi tanda diketahui oleh murobi itu. Pendidikan tidak dapat mengisi atau mengarang persetujuan murobi.
- Kehadiran: satu bahan rekap santri–semester, lintas kelas snapshot jika santri pindah kelas. Sumber hanya pertemuan `Selesai`, peserta snapshot semester yang diminta, dan jadwal semester yang sama. Absensi di-LEFT JOIN menurut pertemuan + santri; pertemuan dideduplikasi. Status kosong tidak menjadi Alpa. Snapshot sumber dan waktu hitung disimpan privat di riwayat pemeriksaan kehadiran. Nol pertemuan berarti nol pertemuan yang memenuhi sumber; tidak dibuat pertemuan/Alpa sintetis.

## Status dan perubahan sumber

Label UI `Diserahkan`/`Perlu Perbaikan` memakai status kode `Dikirim`/`Dikembalikan`; Draf dan Terverifikasi sama. Nilai diserahkan guru/pengganti, sikap diserahkan pembimbing, rekap kehadiran diserahkan Pendidikan. Pendidikan memverifikasi atau mengembalikan bahan Diserahkan; pengembalian wajib alasan dan harus diperbaiki/diserahkan lagi. Alasan terakhir tersedia pada formulir pengisi dan matriks; riwayat pemeriksaan tetap tersedia di API. Murobi tidak dapat melewati pengembalian dengan hanya menandai diketahui.

Perubahan nilai/sikap menulis Draf atau Dikirim baru ke bahan, membatalkan verifikasi lama tanpa menghapus revisinya. Perubahan absensi/status pertemuan/snapshot semester terdeteksi lewat fingerprint sumber pada **setiap baca dan mutasi**: status efektif menjadi Draf dan `perlu_verifikasi_ulang=true`. Baris verifikasi terdahulu tetap sebagai bukti pemeriksaan sumber lama; penyerahan ulang beralasan menulis versi baru. Tidak ada klaim bahwa tabel absensi V1 menyimpan semua revisinya; snapshot saat pemeriksaan disimpan pada riwayat bahan.

Pendidikan mengirim `version` dan `sumber_hash` dari `pemeriksaan` untuk setiap tindakan. Keduanya wajib cocok; retry setelah sukses mendapat 409 tanpa revisi ganda. API baca matriks satu santri menyediakan `matriks_hash` untuk `periksa_finalisasi`; klien menggunakan hash server, bukan menyusun ulang JSON. Gate menolak kekurangan, status belum Terverifikasi, persetujuan murobi yang tidak sah saat ini, atau kelas semester ambigu (nilai kelas asal + penempatan aktif berbeda). Gate sah hanya mengembalikan `siap_finalisasi=true, rapor_diterbitkan=false`. **Tidak ada finalisasi permanen/penerbitan/PDF/notifikasi pada Fase 3.** Fase 4 wajib memanggil pemeriksaan ulang di transaksi penerbitannya sendiri; hasil gate bukan token izin penerbitan.

## Otorisasi dan endpoint

Capability aditif `sikap.input`/`sikap.murobi` berasal dari penugasan pembimbing/murobi yang sama. Mode login lama tidak berubah. Resolver membaca akun/role/master/masa berlaku/cakupan aktif; layanan memeriksa penempatan aktif semester untuk kelas atau relasi kamar semester yang sama. Master kamar warisan tidak mempunyai `is_active`/`archived_at`; pemeriksaan memakai keberadaan kamar, penugasan aktif, dan relasi plotting. Pendidikan/admin memakai capability `rapor.verifikasi`/`rapor.finalisasi` sesuai jenjang dan semester. Admin membaca sebagai pengawas Pendidikan; mutasi pemeriksaan admin wajib alasan, tanpa menyamar menjadi pembimbing/murobi.

Endpoint aditif di `/api/v1/v4/`:

| Metode | Path | Operasi |
| --- | --- | --- |
| GET | `sikap/options?mode=pembimbing|murobi|pendidikan` | Santri/rencana dalam cakupan |
| GET | `sikap?rencana_id=...&santri_id=...&mode=...` | Isi, revisi, status bahan, riwayat pemeriksaan |
| POST | `sikap` | Pembimbing: draf/serahkan/koreksi |
| POST | `sikap/murobi` | Murobi: diketahui/koreksi |
| GET | `verifikasi/options` | Rencana dalam cakupan Pendidikan |
| GET | `verifikasi?rencana_id=...&santri_id=...` | Matriks; santri opsional untuk seluruh kelas |
| POST | `verifikasi` | serahkan (kehadiran), kembalikan, verifikasi, periksa_finalisasi |

Web: `portal/sikap_semester.php`, `portal/verifikasi_semester.php`; status pengembalian juga muncul di `portal/nilai_semester.php`. Semua respons privat `no-store`; web memakai CSRF, API bearer. Setiap mutasi mengunci parent rencana, memeriksa ulang cakupan dan optimistic version, lalu menyimpan bisnis+riwayat+audit dalam satu transaksi. Audit umum hanya metadata; alasan/catatan/sumber tersimpan di riwayat privat.

## Migrasi 021 dan rollback

Migrasi 021 hanya menambah `v4_bahan` dan `v4_bahan_riwayat`; tidak ada backfill/UPDATE data V1–V3. Bahan nilai lama yang lengkap dan seluruh komponennya Dikirim dibaca sebagai Diserahkan sampai pemeriksaan pertama; tidak diberi status Terverifikasi otomatis. Verifier Fase 3 memeriksa referensi rencana/santri/aktor/mapel dan adanya riwayat untuk versi saat ini; verifier Fase 1 tetap memeriksa seluruh FK V4 dan manifest baris lama.

Sebelum produksi (belum dilakukan): hentikan penulis, backup DB serta berkas privat, verifikasi restore point, simpan manifest `bin/v4_phase1_preflight.php`, lalu jalankan `bin/migrate.php up`, ulang, `bin/v4_phase1_verify.php <manifest>`, dan `bin/v4_phase3_verify.php`. Jangan deploy kode Fase 3 sebelum 021 diterapkan. Cabut/hentikan penulis kode Fase 3 sebelum rollback.

Rollback hanya untuk kedua tabel kosong: `php bin/v4_phase3_rollback_preflight.php` lalu `php bin/migrate.php rollback`. Guard CHECK pada SQL juga menolak tabel berisi data jika preflight dilewati. DDL MySQL tidak dianggap transaksional; bila sudah ada status/riwayat, gunakan forward fix atau pulihkan backup yang terverifikasi, jangan hapus bukti bisnis. Uji kosong → rollback → up → up lulus pada DB skema terpisah; penolakan berisi data dan verifikasi sesudahnya ada pada `rollback-guard-sql.txt`.
