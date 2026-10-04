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

## Audit Claude Code — 4 Oktober 2026

Status: empat permintaan UX terpenuhi pada bukti Expo web, website, dan backend lokal. **Belum layak merge/deploy** sampai uji perangkat native di bawah dijalankan; audit ini bukan persetujuan merge.

Diperiksa: diff website `c9aea0f..5bf5421` dan aplikasi `02a917f..da422e4`. Dijalankan ulang secara independen pada MariaDB uji `webalhasan_v3_phase1_test` dan localhost saja:

- `npx tsc --noEmit`, `npm run lint`: lulus. Unit tanggal/cetak 8 lulus, 0 gagal.
- PHP lint `portal/v3_konseling.php`; static V3 Fase 2, 3, 4: exit 0. `bin/v3_phase5_run_tests.sh`: exit 0, residu warisan tetap `[96,24]`. Preflight Fase 5: exit 0.
- `uji-form-pembinaan.mjs` 15/0 dan `uji-v3-fase5-mobile.mjs` 26/0 pada kode implementator; setelah koreksi audit 17/0 dan 26/0.
- Uji tambahan auditor (API nyata): POST pelanggaran dengan kunci dan isi sama dua kali menghasilkan 201 lalu 200 dengan id yang sama; kunci sama dengan isi berbeda ditolak 409; kerahasiaan kosong ditolak 422 oleh API. Website: POST kerahasiaan kosong (atribut `required` dilepas) tidak membuat kasus, isian santri/tujuan/waktu dan kunci idempotensi dikembalikan, kerahasiaan tetap kosong.

Temuan dan koreksi:

1. **Catatan ganda setelah simpan di latar (dikoreksi).** Bila respons sukses tiba saat aplikasi di latar, formulir buat tampil lagi tanpa petunjuk bahwa data sudah tersimpan. Simpan ulang tanpa perubahan aman (kunci sama), tetapi mengubah isian lalu menyimpan mengirim POST kedua dengan kunci baru; tereproduksi dengan API sintetis (2 POST, kunci berbeda). Koreksi di aplikasi `src/app/pembinaan/buat.tsx`: id hasil simpan diingat di memori dan, setelah options/hak akses dimuat ulang, layar langsung dialihkan ke detail. Penolakan 401/403/404, blur, dan pergantian akun membuang id tersebut. Regresi: pemeriksaan 7, 9, 10 di `uji-form-pembinaan.mjs`.
2. **Commit di luar ruang lingkup.** Aplikasi `da422e4` (pesan login `INVALID_CREDENTIALS`/`PASSWORD_CHANGE_REQUIRED`) tidak termasuk empat permintaan UX dan tidak disebut di handoff. Perilakunya diperiksa benar untuk password salah (pesan server tampil); kasus password sementara tidak diuji. Keputusan mempertahankan commit ini di branch ada pada Human Developer.
3. **Jalur native tanpa bukti jalan.** Seluruh bukti UI memakai Expo web, yang merender `WebDateInput`; `DateField` mode jam, `DateTimeField` native, dan `DropdownField` dalam Modal dengan keyboard belum pernah dijalankan di Android/iOS (simulator maupun fisik). Ini hambatan penerimaan utama.
4. Catatan nonblokir: (a) pada native, "Waktu dibuka (opsional)" tidak dapat dikosongkan kembali setelah tanggal dipilih, dan Simpan nonaktif tanpa penjelasan sampai jam dipilih; (b) aplikasi tidak membatasi tanggal masa depan di pemilih, penolakan baru muncul dari server; (c) formulir kasus tetap mengunduh seluruh halaman katalog walau tidak memakainya, dan database uji memiliki lebih dari 150 katalog (≥6 request berurutan sebelum formulir tampil); (d) pada detail kasus, isian sesi tetap tampil setelah mutasi yang selesai di latar, tetapi sesi baru terlihat di daftar dan kirim ulang yang sama diputar ulang server; (e) harness menulis screenshot ke `/tmp`.

Belum dijalankan: seluruh skenario Android/iOS pada handoff implementator (pemilih tanggal/jam, batal picker, screenshot, kunci layar, logout/pergantian akun), password sementara, dan performa produksi. Hambatan Fase 5 lama (fisik/produksi/orphan) tidak berubah.
