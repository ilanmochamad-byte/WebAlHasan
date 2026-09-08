# Status penerimaan audit fondasi V3–V6

Audit independen Codex, 7–8 September 2026, branch
`codex/audit-fondasi-penugasan-v3-v6` dari `origin/main` =
`7edb6461621763ae1b4ffc3dbb54f68615fa3521` (merge implementasi
`c6ed53bc15c2f26cb8cf6a570e9e665cabf60d17`). Rentang yang diperiksa:
`1653ac4392913258aedd44259fcc0d85036a5b44..c6ed53bc15c2f26cb8cf6a570e9e665cabf60d17`.
Koreksi audit telah digabung ke `main` melalui `735dc6d`; verifikasi lanjutan
dilakukan pada hasil deploy tersebut.

**Keputusan: LULUS untuk fondasi penugasan V3–V6.** Koreksi, pengujian otomatis,
verifikasi MariaDB cPanel, dan smoke interaksi produksi/Safari telah selesai.
Audit tidak mengimplementasikan PRD V3 dan tidak mengubah aplikasi mobile.
Klaim lama “681 pemeriksaan lulus” berhasil direproduksi, lalu cakupan audit
diperluas menjadi 741 pemeriksaan paket, regresi lengkap, konkurensi nyata,
drill migrasi, dan smoke produksi.

## Temuan dan koreksi berdasarkan risiko

| ID | Risiko | Temuan | Koreksi dan bukti |
| --- | --- | --- | --- |
| A1 | Tinggi | `saveMurobi`, `setMurobiState`, `PembimbingService::create/setState` melewati benturan periode dan transaksi/audit wajib pusat. Pemulihan arsip dapat mengaktifkan penugasan bentrok. | Seluruh mutasi layanan lama menjadi adaptor `PenugasanService` pada koneksi yang sama. URL/form lama tetap tersedia; arsip/pulihkan memakai gerbang subjek, validasi, transaksi, audit, dan alasan wajib dari admin. KA-1…4 menguji jalur lama vs pusat secara bersamaan. |
| A2 | Tinggi | Hasil `get_result() = false` dapat dianggap kosong pada repository murobi, pembimbing, akun, penempatan, alumni. Akun juga belum memetakan errno kunci saat `execute`. | Hasil gagal selalu dilempar sebagai galat aman; 1205/1213 memberi instruksi muat ulang. KA-9/11 memaksa lock timeout dan deadlock nyata pada keenam repository termasuk pusat. |
| A3 | Tinggi | `akhiri` dapat memperpanjang tanggal selesai memasuki periode penugasan lain. | Pemeriksaan overlap di bawah kunci subjek; penolakan diaudit dan baris tidak berubah (KA-7). |
| A4 | Sedang | Resolver cakupan kamar menjawab benar untuk konteks jenjang mana pun, bertentangan dengan matriks. | Cakupan kamar tidak mewakili jenjang (KA-10). `get_result=false` pada resolver juga tidak lagi menyebabkan pemanggilan metode pada boolean; lima uji driver sintetis membuktikan hasil tanpa hak. |
| A5 | Sedang | Aktivasi pusat hanya memeriksa overlap, tidak menilai ulang master/cakupan yang telah nonaktif. | Aktivasi dan pemulihan menjalankan normalisasi/validasi pusat (KA-12). |
| A6 | Sedang | Penjalan paket dapat menyatakan seluruh pengujian lulus ketika concurrency dilewati (exit 77) atau regresi sengaja dilewati. | Status tidak lengkap menghasilkan exit 1; tiga tes runner tanpa DB. |
| A7 | Rendah | Label orang/tahun pada formulir ubah tidak terhubung ke kontrol; tombol Simpan mapel terpotong pada tablet. | ID kontrol ditambahkan dan lebar kolom tombol diperbaiki. Smoke Chromium 1440/768/390, termasuk formulir mapel berisi, label dan keyboard. Kolom ringkasan diberi nama “Capability efektif akun” agar tidak disalahartikan sebagai status satu baris. |

## Kriteria penerimaan

| Kriteria | Status dan bukti |
| --- | --- |
| Admin mengelola tujuh jenis penugasan dan master mapel | LULUS — integrasi FI-4, HTTP FW-5, dan smoke produksi seluruh tab |
| Role dasar tetap empat; tidak ada role login fungsional | LULUS — statis, integrasi, verify cPanel, halaman akun, dan sesi Guru/Pengurus/Orang Tua produksi |
| Capability dari akun/role/master/masa berlaku/tahun/cakupan server | LULUS — FI-6…12/23/25/26, KA-10/12, serta 20 audit perubahan capability produksi |
| Jalur lama tidak melewati pusat; duplikasi/overlap dicegah | LULUS — KA-1…7, KP-1…5, HTTP FW-8, dan smoke produksi jalur pusat/lama murobi serta pembimbing |
| Konkurensi nyata dan tidak ada perubahan parsial | LULUS MariaDB lokal — buat identik, overlap, aktivasi, perubahan tanggal/cakupan, operasi berbeda sah, gagal audit, lock timeout/deadlock |
| Tidak ada hard delete operasional | LULUS review — hanya nonaktif/akhir/arsip; DELETE pada tes hanya untuk fixture sintetis |
| Transaksi dan audit konsisten | LULUS — FI-15/24, KA-8; produksi mencatat 34 mutasi entitas uji, 20 perubahan capability, dan 3 penolakan benturan |
| API lama, role/mode/default_mode/menu kompatibel | LULUS — FI-19/20, FW-10, kontrak API V1/V2, tambahan audit API |
| Aplikasi lama tanpa perubahan kode | LULUS lint/typecheck, 6 tes cetak, 18 tes client asli, kontrak API, dan smoke sesi role produksi; kode mobile tidak diubah |
| Regresi otomatis V1/V2 | LULUS — rangkaian lengkap dan tambahan audit, lihat test-results |
| Migrasi 012, ulang, rollback, ulang setelah rollback, FK/CHECK/indeks/yatim | LULUS pada MariaDB 12.3.2 lokal, skema dasar tanpa data lalu fixture sintetis |
| Migrasi 012 pada MySQL/MariaDB cPanel representatif | LULUS pada produksi MariaDB `10.6.27-MariaDB-cll-lve`: migrasi tercatat, preflight tanpa penghalang, verify 154 pemeriksaan exit 0 dengan jumlah murobi 1 dan pembimbing 9 |
| Desktop/tablet/390 px, label/error/keyboard | LULUS Chromium dan Safari produksi; Responsive Design Mode 768/390, menu/tab/form, label, keyboard, pesan benturan, dan tanpa scroll horizontal formulir |
| Fitur bisnis PRD V3–V6 belum dikerjakan | LULUS review rentang dan koreksi; aplikasi mobile tidak diubah |

## Batas bukti dan tindak lanjut nonblokir

1. Rollback/migrasi ulang tidak dijalankan pada basis data produksi yang hidup.
   Siklus tersebut lulus pada MariaDB lokal terpisah; menjalankannya di produksi
   akan menurunkan layanan dan tidak diperlukan untuk menerima hasil deploy.
2. Smoke aplikasi Android/iOS yang terpasang dan pembaca layar VoiceOver fisik
   belum dijalankan. Pengujian kontrak/client, lint/typecheck, sesi role produksi,
   label Safari, dan keyboard dasar lulus; tidak ditemukan kebutuhan perubahan
   kode mobile.
3. MariaDB produksi memakai `system_time_zone=WIB`, sesi `SYSTEM`, dan
   `CURDATE()` sesuai 8 September 2026. Nilai zona waktu PHP CLI belum direkam;
   tanggal yang ditampilkan aplikasi produksi konsisten dengan tanggal DB.

Label gelombang PSB dan jenjang tetap mengikuti desain fondasi; master/alur
bisnis V3–V6 tidak ditambahkan. Audit berhenti pada fondasi ini.
