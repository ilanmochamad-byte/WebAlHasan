# Handoff untuk Claude Code — hanya audit Fase 4 PRD V4

Branch web: **`codex/prd-v4-fase-4`**. Baseline audit Fase 3: **`8b5410662f65681da236603c488e89c29205b098`**. Hash commit implementasi akhir disampaikan di chat setelah commit/push; gunakan `git rev-parse HEAD` pada branch remote yang telah diverifikasi. Audit seluruh diff terhadap baseline, termasuk berkas baru dan bukti. Mobile tetap bersih di `main` / `dfae26d69fe776492cfda274d10fddf79a1590f2`; tidak ada commit mobile untuk fase ini.

Perubahan utama: preview/penerbitan snapshot + satu PDF kanonis privat per versi, tanda tangan + tempat/tanggal + QR; verifikasi status/hash publik tanpa identitas; daftar/detail/PDF wali web/API dengan otorisasi ulang; penggantian/penarikan/pembukaan beralasan; InApp/outbox Push generik dan retry; guard koreksi rapor Terbit; jalur khusus admin penanda tangan sesuai keputusan Human Developer. Migrasi aditif **022** menambah operasi/riwayat penanda/throttle. Tidak mengubah bobot, gate kelengkapan, unit semester, keputusan produk lain, layar mobile atau alur Fase 5.

Bukti per tujuh kriteria dan regresi ada di `status-penerimaan.md`; perintah/hasil aktual dan kegagalan awal di `hasil-pengujian.md`; kontrak transaksi/idempotensi/endpoint/pemulihan di `kontrak-dan-pemulihan.md`; batas yang belum diuji di `risiko-terbuka.md`. Seluruh kelulusan adalah hasil implementator, belum audit independen.

Fokus audit independen:

1. Ulangi DB baru dengan baseline 001–021 lalu manifest/022; periksa gate benar-benar dibaca ulang di transaksi SERIALIZABLE, termasuk kehadiran hidup, phantom peserta/absensi, pertemuan/jadwal, penugasan/relasi dan cakupan. Uji perubahan sumber lain bersamaan; uji klik ganda/key sama dan beda serta konflik versi.
2. Tinjau retry 1020/1205/1213 pada mysqli reporting OFF dan strict. Pastikan tidak ada retry konflik produk yang menimpa revisi. Periksa replay setelah hak dicabut dan setelah status/versi berubah; metadata replay adalah keadaan saat ini, bukan penerbitan ulang.
3. Suntikkan kegagalan render/write/DB/kode/outbox/audit. Periksa semua dampak digulung, berkas ter-commit tidak dihapus pada hasil commit ambigu, orphan tetap privat. Batas COMMIT network ambiguity/disk-power belum diuji. Rekonsiliasi sengaja read-only.
4. Uji ID langsung, flag petugas palsu, relasi dicabut pada setiap GET/detail/PDF dan penugasan dicabut untuk preview/mutasi/cetak. Periksa snapshot/gambar tidak terungkap lewat public verifikasi, folder/vendor atau cache. Cek catatan umum API hanya body POST; kode QR fragment + POST, throttle dan ukuran unggahan.
5. Periksa snapshot/PDF versi lama byte-identik sesudah ganti/tarik/buka/perubahan penanda; kode lama benar; snapshot lama disembunyikan dari wali saat tidak sah tetapi status historis tetap ada. Pastikan Buka membatalkan versi sah dan koreksi memerlukan verifikasi ulang. Audit alasan/pelaku/kapasitas/waktu/versi.
6. Periksa event outbox per wali aktif berakun, dedup dan rollback outbox wajib, payload provider whitelist dan pesan persis generik, recheck hak/status saat dispatch, OFF nol panggilan termasuk receipt. Ulang regresi dispatcher V2/V3. Tidak ada provider fisik yang dinyatakan lulus.
7. Tinjau layout PDF 1/6 halaman dan 375 px/24 px/desktop, kontrol navigasi admin/kelas, dependensi Composer/chroot/temp privat, verifikasi hash dan pemindaian QR dari PDF. Jangan menyamakan router HTTP lokal dengan bukti `.htaccess` hosting.

Regresi aktual V1–V3: 14 integrasi dan 14/15 statis lulus, `phase5_static` gagal warisan path mobile lama. Tidak dilonggarkan. Jangan menghapus residu DB lama agar verifier tampak bersih. Gunakan instance dan path fixture sementara yang terpisah agar tidak mengganggu uji lain.

**Berhenti setelah audit Fase 4. Jangan mengerjakan Fase 5, menjalankan Claude Code bersamaan dengan implementator pada checkout ini, merge ke main, atau melakukan rilis/migrasi produksi.**
