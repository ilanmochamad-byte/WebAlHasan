# Status penerimaan audit fondasi V3–V6

Audit independen Codex, 7 September 2026, branch
`codex/audit-fondasi-penugasan-v3-v6` dari `origin/main` =
`7edb6461621763ae1b4ffc3dbb54f68615fa3521` (merge implementasi
`c6ed53bc15c2f26cb8cf6a570e9e665cabf60d17`). Rentang yang diperiksa:
`1653ac4392913258aedd44259fcc0d85036a5b44..c6ed53bc15c2f26cb8cf6a570e9e665cabf60d17`.

**Keputusan: BELUM LULUS penerimaan penuh.** Koreksi dan pengujian otomatis
lokal selesai; gerbang migrasi pada salinan produksi/versi MySQL cPanel dan
smoke perangkat terpasang belum dibuktikan. Tidak ada merge/deploy oleh audit
ini dan tidak ada implementasi PRD V3. Klaim lama “681 pemeriksaan lulus”
berhasil direproduksi, tetapi tidak mencakup celah di bawah.

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
| Admin mengelola tujuh jenis penugasan dan master mapel | LULUS lokal — integrasi FI-4, HTTP FW-5 |
| Role dasar tetap empat; tidak ada role login fungsional | LULUS — statis, integrasi, verify |
| Capability dari akun/role/master/masa berlaku/tahun/cakupan server | LULUS lokal — FI-6…12/23/25/26, KA-10/12 |
| Jalur lama tidak melewati pusat; duplikasi/overlap dicegah | LULUS lokal — KA-1…7, KP-1…5, HTTP FW-8, regresi formulir lama |
| Konkurensi nyata dan tidak ada perubahan parsial | LULUS MariaDB lokal — buat identik, overlap, aktivasi, perubahan tanggal/cakupan, operasi berbeda sah, gagal audit, lock timeout/deadlock |
| Tidak ada hard delete operasional | LULUS review — hanya nonaktif/akhir/arsip; DELETE pada tes hanya untuk fixture sintetis |
| Transaksi dan audit konsisten | LULUS lokal — FI-15/24, KA-8; mulai/commit transaksi diperiksa hasilnya |
| API lama, role/mode/default_mode/menu kompatibel | LULUS — FI-19/20, FW-10, kontrak API V1/V2, tambahan audit API |
| Aplikasi lama tanpa perubahan kode | LULUS lint/typecheck, 6 tes cetak, 18 tes client asli; **smoke aplikasi terpasang BELUM DIJALANKAN** |
| Regresi otomatis V1/V2 | LULUS — rangkaian lengkap dan tambahan audit, lihat test-results |
| Migrasi 012, ulang, rollback, ulang setelah rollback, FK/CHECK/indeks/yatim | LULUS pada MariaDB 12.3.2 lokal, skema dasar tanpa data lalu fixture sintetis |
| Migrasi 012 pada salinan produksi representatif MySQL cPanel | **MEMERLUKAN UJI MYSQL CPANEL** — tidak tersedia salinan produksi terkini beserta versi/config hosting |
| Desktop/tablet/390 px, label/error/keyboard | LULUS smoke Chromium; **Safari dan pembaca layar BELUM DIJALANKAN** |
| Fitur bisnis PRD V3–V6 belum dikerjakan | LULUS review rentang dan koreksi; aplikasi mobile tidak diubah |

## Gerbang yang tetap terbuka

1. Operator menguji migrasi 012 pada salinan produksi terkini di MySQL yang
   sama dengan cPanel, mencatat jumlah/ID/nilai data, FK/indeks/CHECK/yatim,
   konflik penugasan historis, backup/restore, rollback dan konkurensi.
   Jangan memakai hasil MariaDB lokal sebagai bukti gerbang ini.
2. Smoke Safari: Safari terpasang, tetapi WebDriver menolak sesi karena
   “Allow remote automation” belum aktif. Tidak mengubah pengaturan browser.
3. Smoke aplikasi lama yang benar-benar terpasang pada Android/iOS: login,
   profil/mode, jadwal, absensi, laporan, perizinan. Kontrak/client Node tidak
   menggantikan bukti perangkat. Tidak ada kebutuhan perubahan kode mobile
   yang ditemukan.
4. Pembaca layar fisik dan konfigurasi zona waktu MySQL/PHP cPanel belum diuji.
   Label tanggal UI memakai Asia/Jakarta, resolver memakai CURDATE server DB.

Label gelombang PSB dan jenjang tetap mengikuti desain fondasi; master/alur
bisnis V3–V6 tidak ditambahkan. Audit berhenti pada fondasi ini.
