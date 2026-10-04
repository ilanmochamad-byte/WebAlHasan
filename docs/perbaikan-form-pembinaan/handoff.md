# Perbaikan formulir pembinaan — 4 Oktober 2026

Status: implementasi siap audit Claude Code, bukan persetujuan merge/deploy. Kedua repositori memakai branch `codex/perbaikan-form-pembinaan`, baseline website `c9aea0f`, aplikasi `02a917f`. Ruang lingkup hanya empat permintaan UX Human Developer, tidak memulai fase berikutnya.

## Hasil

- Aplikasi: kalender tanggal dan pemilih jam native memakai `@expo/ui` yang sudah terpasang; Expo web memakai input browser date/time/datetime-local. Berlaku waktu kejadian, pembukaan kasus, jadwal sesi/penjadwalan ulang; DateField lama di modul lain juga memperoleh kalender browser.
- Format kirim `YYYY-MM-DDTHH:mm` memakai nilai lokal. Simpan menunggu tanggal/jam lengkap dan sah; pilihan tanggal saja pada native tetap tersimpan ketika memilih jam.
- Formulir buat dan detail mempertahankan isian dalam memori saat inactive/background. Data server dihapus dari tampilan dan dimuat ulang saat aktif; penolakan 401/403/404 menghapus draf, pilihan dicocokkan kembali ke options terbaru. Blur/unmount/pergantian akun tetap membersihkan. Tidak ada penyimpanan isi privat di disk/sessionStorage.
- Kunci idempotensi untuk formulir dipertahankan sampai respons sukses benar-benar dikonsumsi layar. Jika respons datang saat di latar, retry payload sama memakai kunci yang sama; backend tetap menjadi penjaga deduplikasi.
- Dropdown katalog dan santri dengan pencarian, daftar gulir terbatas, dan seluruh pilihan lintas halaman. API tetap 25 item per request, dihimpun otomatis sampai selesai; tidak ada perubahan endpoint/server. Konsekuensi: jumlah request awal naik untuk himpunan pilihan besar; belum diuji performa produksi.
- Kerahasiaan aplikasi dan website harus dipilih eksplisit dari dropdown. Website sudah memiliki kalender dan dropdown katalog; tidak ada penghapusan isian saat visibilitychange pada kode website yang diperiksa.
- Keputusan UX dan perubahan aturan retensi dicatat di PRD-V3 §5.5b. Bukti lama audit yang mengharuskan hapus draf pada background telah digantikan untuk formulir ini; dokumen audit lama tetap historis.

## Validasi sesi ini

- TypeScript `npx tsc --noEmit` dan `npm run lint`: lulus.
- `TZ=Asia/Jakarta node --experimental-strip-types --test tests/date-time.test.ts tests/print-dialog.test.ts`: 8 lulus, 0 gagal. Mencakup tengah malam, tahun kabisat, tanggal tidak sah, bagian waktu kosong, serta regresi cetak.
- `node tests/browser/uji-form-pembinaan.mjs`: 15 lulus, 0 gagal, Chromium 375×900/Asia Jakarta dengan API sintetis dan jaringan luar diblokir. Menguji 60 katalog, pencarian, retensi setelah screenshot/latar, pilihan privasi wajib, payload lokal, respons simpan saat background/kunci retry sama, isian sesi, pencabutan katalog, 403/pemulihan akses, dan keluar formulir.
- PHP lint `portal/v3_konseling.php`: lulus. Static V3 Fase 2 dan 3 dijalankan dengan `DB_NAME=webalhasan_v3_phase1_test V3_RUN_TESTS=1`; keduanya exit 0. Static Fase 4 juga exit 0. Ini pemeriksaan source, bukan pengujian database.
- Harness API nyata `PERAPIHAN_AUDIT_DB=1 node tests/browser/uji-v3-fase5-mobile.mjs`: **26 lulus, 0 gagal**. Mencakup simpan pelanggaran, kasus dan dua sesi, konflik versi, murobi/wali/IDOR/401, kalender dan dropdown website, privasi wajib serta retensi formulir website. API lokal localhost:8940 dan MariaDB khusus `webalhasan_v3_phase1_test`, bukan produksi. Respons terkontrol hanya untuk keadaan UI gagal/kosong pada bagian harness yang sudah ada.
- `DB_NAME=webalhasan_v3_phase1_test bash bin/v3_phase5_run_tests.sh`: exit 0, mencakup akses, integrasi, performa, drill migrasi 019 lokal, teardown fixture, dan verifier bisnis V3. Residu warisan tetap `[96,24]` sebelum/sesudah; tidak dinyatakan bersih dan tidak dihapus.
- Preflight awal terhalang sandbox; dijalankan ulang dengan akses database lokal yang sesuai dan 5 pemeriksaan lulus. Hambatan koneksi awal bukan kegagalan database. Server lokal dijalankan dengan `DB_NAME=webalhasan_v3_phase1_test PERAPIHAN_AUDIT_DB=1 php -S 127.0.0.1:8940 tests/v3_phase2_router.php`; Expo dengan `CI=1 EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8940/api/v1 npx expo start --web --port 8082`.
- Belum diuji pada Android/iOS fisik, termasuk kalender native, keyboard, screenshot perangkat, layar terkunci lama, dan proses dibunuh OS. Draf hanya di memori; force-stop/restart proses tidak memulihkan isian. Tidak ada migrasi/deploy/merge/push-notifikasi produksi. Drill migrasi hanya database uji.

## Handoff auditor

Audit diff kedua repo dari baseline di atas. Jalankan kembali pengujian source/unit/UI, serta suite backend lokal dan harness API nyata secara independen. Uji Android dan iOS: isi semua kolom, pilih tanggal/tahun/bulan dan jam, screenshot, kunci/buka layar, kembali dari picker, lalu simpan satu kali; pastikan nilai sama dan tidak ganda. Uji batal picker, tanggal saja, logout/pergantian akun, akses dicabut, dan kirim tepat sebelum background. Uji website kalender/dropdown serta pemilihan privasi wajib, termasuk isian yang dikembalikan setelah validasi gagal. Status penerimaan Fase 5 sebelumnya tetap belum lengkap; audit ini tidak menghapus hambatan fisik/produksi/orphan yang sudah dicatat.
