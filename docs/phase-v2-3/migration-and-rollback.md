# V2 Fase 3 — Migrasi dan Rollback

## 1. Ringkasan: Fase 3 tidak menambah migrasi skema

Fase 3 adalah fase **antarmuka** (REST API, aplikasi, portal). Seluruh tabel,
kolom, indeks, dan constraint yang dibutuhkan sudah dibuat pada:

- `006_v2_phase1_perizinan_foundation.sql` — akun perizinan, penugasan
  pembimbing, `izin_pengajuan`, `izin_keputusan`, `izin_riwayat_status`,
  `izin_idempotency_keys`;
- `007_v2_phase2_pengajuan_routing_keputusan.sql` — jejak routing, penetapan
  murobi, pembatalan, tabel koreksi, dan indeks antrean/overlap.

Karena itu:

- **tidak ada berkas migrasi baru** pada `database/migrations/` (tetap 7 berkas);
- **tidak ada rollback skema** yang perlu dijalankan untuk Fase 3;
- rollback Fase 3 = mengembalikan **kode** ke commit sebelumnya.

Pemeriksaan statis `tests/v2_phase3_static.php` menegaskan hal ini: ia gagal bila
ada migrasi ke-8 muncul tanpa pembaruan dokumen ini.

## 2. Prosedur preflight sebelum menerapkan Fase 3

Meski tanpa DDL baru, jalankan preflight untuk memastikan skema target memang
sudah pada tingkat Fase 2:

```bash
php bin/migrate.php status   # 001 … 007 harus [diterapkan]
php bin/v2_phase2_verify.php # verifikasi struktur & constraint Fase 2
```

Bila ada migrasi yang belum diterapkan, jalankan prosedur Fase 2
(`docs/phase-v2-2/migration-and-rollback.md`) lebih dulu — termasuk backup dan
manifest jumlah baris — sebelum melanjutkan ke Fase 3.

## 3. Backup

Backup Fase 2 dan manifest-nya **tetap menjadi titik pemulihan resmi**. Fase 3
tidak menggantinya dan tidak boleh menghapusnya. Sebelum deploy kode Fase 3:

```bash
php bin/v2_phase2_preflight.php   # backup + manifest + laporan konflik
php bin/verify_restore.php        # restore ke database *_test, cocokkan manifest
```

Simpan hash commit rilis Fase 2 (`f2f674d`, tergabung pada `main` melalui merge
`c30add9`) bersama berkas backup.

## 4. Rollback Fase 3

Karena tidak ada perubahan skema, rollback cukup pada tingkat kode:

1. Kembalikan direktori aplikasi web ke commit rilis Fase 2 (`c30add9`).
2. Kembalikan aplikasi mobile ke build sebelumnya (lihat
   `mobile-build-and-smoke-test.md` — build lama tetap kompatibel karena kontrak
   V1 tidak berubah).
3. Tidak perlu menjalankan `php bin/migrate.php rollback`.

Data yang terlanjur dibuat lewat API Fase 3 (`izin_pengajuan`,
`izin_keputusan`, `izin_riwayat_status`, `izin_idempotency_keys`,
`izin_keputusan_koreksi`) **tetap valid** setelah rollback: struktur dan
semantiknya identik dengan yang dihasilkan portal web Fase 2. Tidak ada data
yang perlu dihapus, dan tidak boleh dihapus.

## 5. Kompatibilitas klien selama transisi

| Klien | Perilaku setelah deploy Fase 3 | Perilaku setelah rollback |
| --- | --- | --- |
| Aplikasi guru versi lama | Tidak terpengaruh; seluruh endpoint dan bentuk respons V1 identik. Field `capabilities` yang baru diabaikan klien lama. | Tidak terpengaruh |
| Aplikasi V2 baru | Berfungsi penuh | Endpoint `/izin/*` menghilang → aplikasi menampilkan galat `404`/`403` yang dapat ditindaklanjuti; alur jadwal/laporan tetap jalan |
| Portal web | Berfungsi penuh untuk seluruh peran | Kembali ke perilaku Fase 2 (fungsional sama; hanya penonaktifan tombol saat submit yang hilang) |

## 6. Migrasi destruktif

Tidak ada. Fase 3 tidak menjalankan `DROP`, `DELETE`, `TRUNCATE`, atau `ALTER`
apa pun terhadap data. Modul lama `admin/admin_izin.php` tetap diarsipkan lewat
redirect kompatibel seperti pada Fase 2 dan tidak dihapus.
