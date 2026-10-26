# Audit ulang Codex — PRD V6 Fase 4

26 Oktober 2026. **LULUS kriteria penerimaan Fase 4 pada lingkungan uji yang tersedia.** Koreksi V-1 Claude diterima tanpa koreksi kode tambahan auditor; F-1 tetap tertutup. Hambatan runtime iOS pada audit pertama kini ditutup dengan build Release simulator yang dibangun dan diuji auditor sendiri. Cold start URI pada Release juga terbukti. Ini **bukan** kelulusan perangkat fisik, Push nyata, kesiapan produksi, atau izin rilis parsial V6.

## Lingkup dan baseline

| Repositori | Baseline tindak lanjut | Commit yang diaudit |
| --- | --- | --- |
| WebAlHasan | `f1a948d755038b9d1a9976b6b8376a2d92269e17` (PR #90; pohon sama dengan audit `08aa5fc`) | `88bc69abfb3b856298b2a0da7ebfc9f88dcd6c51` — dokumen/bukti Claude, tanpa perubahan aplikasi web/API |
| alhasanApps | `6842f133988cd7618990d6abbfdfa7cd60284c4f` (PR #17; pohon sama dengan koreksi `87d1c10`) | `0097f91a50dfd18bd57959f2618d7c644175f219` — satu hook aplikasi dan catatan AGENTS |

Kedua checkout tetap `claude/prd-v6-fase-4-validasi`, bukan `main`; hash remote diverifikasi. AGENTS, PRD V6 Fase 4, audit pertama, diff seluruh commit tindak lanjut, `validasi-claude/VALIDASI-CLAUDE.md`, skrip dan buktinya diperiksa. Catatan implementator tidak digunakan sebagai pengganti eksekusi auditor. Tidak menjalankan Claude atau agen paralel pada checkout ini, tidak membuat worktree.

Merge PR #90/#17 sudah ada sebelum audit ulang dimulai; auditor tidak melakukan merge. Dua berkas paket mobile milik pekerjaan lain dan tiga direktori web belum terlacak tetap tidak disentuh/di-commit. Tidak ada perubahan kode aplikasi oleh auditor pada putaran ini.

## V-1 (P3) ditutup; F-1 tetap tertutup

Pada kode audit pertama, penolakan POST sudah menutup data/draf melalui `usePrivateResource.reject()`, tetapi `useMutationGuard.error` masih menyimpan pesan penolakan. Setelah hak dipulihkan dan pengguna menekan Coba lagi, data baru termuat dengan pesan lama yang menyesatkan.

Koreksi `useKeuanganMutation` menandai penolakan 401/403/404 per pemanggilan, menunggu `guard.run()` selesai, lalu mereset penjaga. Pesan penolakan tetap tampil di resource induk selama akses belum pulih. Kunci/reset dan pesan 409/422, retry jaringan/5xx, keputusan nominal, saldo, dan otorisasi server tidak berubah. Panggilan kedua yang diabaikan saat sibuk tidak mereset operasi pertama.

Probe Claude **tanpa perubahan oracle**, dijalankan auditor pada hook lama `87d1c10` → **4/1**, dan hook `0097f91` → **5/0**. Probe F-1 delapan alur yang asli → **16/0**; seluruh suite Expo web asli → **45/0**, termasuk respons yang hilang sesudah commit, retry kunci sama, konflik versi dan paritas kuitansi website. Android dan iOS juga membuktikan pemulihan akses tanpa pesan/draf basi.

## Hasil independen auditor

Bukti baru terpisah di `audit-ulang-codex/`; `audit-codex/` dan `validasi-claude/` dipertahankan sebagai riwayat.

| Pemeriksaan | Hasil |
| --- | --- |
| Statis Fase 4, termasuk lint 14 PHP | 22/0 |
| HTTP/API Fase 4, empat pekerja PHP, fixture baru | 63/0 |
| Kontrol V-1 lama / koreksi Claude | 4/1 → 5/0 |
| Regresi F-1 delapan alur / Expo web asli 375px | 16/0 · 45/0 |
| TypeScript / Expo lint / unit mobile | exit 0 · exit 0 tanpa peringatan · 36/0 |
| iOS Release: transaksi, F-1/V-1, cold start berhak dan hak dicabut | 6/0 |
| iOS Release: wali/admin, pencabutan relasi, token kedaluwarsa, wali asing | 8/0 |
| iOS: referensi benar dimuat, berkas/log, cold start setelah Metro dihentikan | 4/0 |
| Android emulator dengan bundel `0097f91`: F-1/V-1/tanpa mutasi | 3/0 |
| Cache snapshot OS iOS | Snapshot keuangan terbaru hitam, kontrol dekoder menampilkan logo; diperiksa visual |

PHP 8.4.14, MariaDB 12.3.2 lokal, Node 26.7.0; seluruh DB yang ditulis bernama `test_*_test`, hasil builder fixture asli Fase 4 yang dijalankan ulang. Dump bersih dipisahkan untuk HTTP, browser dan iOS; browser direset sebelum setiap suite. Tidak membaca database produksi. Bukti regresi V1–V5/PSB dan V6 Fase 1–3 pada audit sebelumnya tetap berlaku pada baseline; putaran terarah ini **tidak menjalankan ulang semuanya**. Tidak ada perubahan backend, migrasi, atau dependensi ter-commit yang membutuhkan pengulangan seluruh suite tersebut.

## Runtime dan cold start iOS

Auditor membuat salinan sumber `0097f91` tanpa `.env*` kerja. Node_modules dipakai ulang dari instalasi lock ter-commit (Expo 57.0.15); SHA-256 lock `a874dd8856a59c2724939eb92d6d15b069c95ec56bb5f613c33fc5d9b7361f5a`. Bukan paket checkout 57.0.31 milik pekerjaan lain. Proyek native/Pods lokal yang tersedia disalin sebagai input build; semua berkas sumber ter-commit ditimpa dengan HEAD terverifikasi. Build memakai cache kompilasi lokal, bukan instalasi dependensi baru.

Build **Release arm64 simulator**, Xcode 27.1, iPhone 17 Pro iOS 26.2, tanda tangan ad-hoc lokal (`CODE_SIGN_IDENTITY=-`, `CODE_SIGNING_ALLOWED/REQUIRED=YES`, `ONLY_ACTIVE_ARCH=YES`). Tidak membuat arsip distribusi/IPA, mengunggah, atau merilis aplikasi. `ios-build.txt` mencatat BUILD SUCCEEDED; `ios-build.json` memuat hash executable/bundle/lock dan API `127.0.0.1:8990`, dengan nol host produksi di bundle. Runtime Debug/dev client yang gagal linker pada laporan Claude tidak diuji ulang; Release membuktikan runtime yang tersedia tanpa mengklaim Debug sudah diperbaiki.

Bukti perilaku:

- Login dan SecureStore berhasil. Dua ketukan setoran Rp100.000 menghasilkan satu ledger, satu klaim tambahan, saldo Rp100.000 serta dua InApp wali aktif. Tagihan sintetis tetap terpisah dari saldo.
- Penugasan dicabut saat formulir terisi Rp5.000 → POST ditolak, nama/saldo/isian tidak tampil dan uang tidak berubah. Hak dipulihkan + Coba lagi → formulir bersih tanpa pesan lama.
- Proses dihentikan melalui `simctl terminate`, lalu URI tagihan dibuka: token tersimpan membuka tujuan yang berhak; setelah penugasan dicabut, token lama ditolak tanpa nama/nominal. Pemeriksaan cold start tambahan tetap lulus sesudah Metro audit benar-benar dihentikan. Tidak ada dev launcher pada jalur Release ini.
- Wali dua anak melihat dua kartu terpisah; memilih Tsanawi tidak membawa data SMP/tombol petugas. Mencabut satu relasi pada layar lama menutup anak itu. Mencabut relasi anak kedua saat aplikasi di latar juga menutup data ketika aplikasi aktif kembali.
- Wali satu anak hanya melihat SMP. Token wali dicabut lalu proses dihentikan dan URI tagihan Tsanawi dibuka → login ulang. Setelah masuk sebagai wali SMP, tujuan tetap ditolak tanpa nama/nominal Tsanawi. Admin membaca data server tanpa tombol mutasi.

Pergantian akun pada matriks native dilakukan melalui pencabutan token **DB fixture terpisah** + cold start; tidak mengklaim pengujian tombol logout profil dari pembantu ini. URI diuji langsung, **bukan** ketukan Push provider nyata.

## Privasi dan T-1

Referensi transfer sintetis `REAUDIT-IOS-TRF-PRIVATE` benar-benar dimuat pada layar admin sebelum pemindaian. **68 berkas** kontainer aplikasi dapat dibaca, nol penanda referensi/nama santri/kuitansi/kata sandi yang diuji atau pola bearer mentah, nol berkas tidak terbaca. **103.527 baris log proses `alhasanApps` dalam jendela 60 menit** juga tanpa penanda tersebut. Daftar berkas dan angka disimpan di `ios-privasi-data.json`; log mentah tetap di folder privat sementara, tidak dipublikasikan. Ini bukan forensik RAM atau pemeriksaan seluruh penyimpanan OS. Token yang diperlukan untuk autentikasi native tetap di SecureStore; tidak menuntut Keychain autentikasi kosong.

T-1 tetap keputusan Human Developer: perlindungan seluruh navigator Keuangan dipertahankan. Layar berhasil dimuat sesudah panggilan native perlindungan selesai; sumber menunjukkan kedua panggilan dilalui sebelum Stack dirender. Frame foreground keuangan dengan nama/NIS dan Rp100.000 diperiksa. Gestur/dua Home Maestro tidak membuka UI pengalih, tetapi membawa aplikasi ke latar. Auditor kemudian membaca **cache snapshot SplashBoard milik aplikasi itu sendiri**, mengonversi KTX terbaru melalui `sips`/ImageIO, dan memeriksa PNG: **hitam seluruhnya, tanpa data keuangan**. Dua snapshot penuh terbaru mempunyai hash yang sama. Kontrol snapshot lain dari kontainer yang sama didekode menjadi logo Alhasan, membuktikan dekoder tidak selalu menghasilkan hitam. Bukti: `ios-snapshot.txt`, metadata dan PNG. Tidak mengklaim UI pengalih, tangkapan/rekaman pada perangkat fisik sudah diuji.

Android memakai **emulator Medium_Phone / Android 16**, APK Debug lokal 24 Oktober 2026 yang sudah memuat modul native perlindungan + JavaScript `0097f91` dari Metro salinan paket ter-commit, API fixture browser terpisah dari iOS. Bukan APK Release baru. Pencabutan/pemulihan penugasan pada formulir lama diuji langsung: data/draf ditutup, pesan basi hilang setelah Coba lagi, tidak ada baris uang baru. Bukti lengkap transaksi/akses/proteksi Android audit pertama tetap terpisah dari tiga pemeriksaan baru ini.

## Kontrol penyiapan dan batas yang dibawa ke gerbang rilis

Kontrol awal yang gagal dipertahankan: symlink skill pada salinan build, keyboard/fokus/AutoFill iOS, duplikasi label aksesibilitas, format perintah Maestro, locator logout profil, serta overlay dev menu Android. Penyiapan dan pembantu diperbaiki; **tidak ada koreksi aplikasi auditor** untuk hasil tersebut. Percobaan gestur pengalih bukan bukti UI pengalih terbuka; penilaian snapshot menggunakan berkas OS yang benar-benar dibaca dan kontrol dekoder. GUI macOS tidak diperlukan untuk bukti akhir.

Tetap belum dibuktikan: perangkat fisik Android/iOS, Push provider nyata dan ketukannya/cold start dari Push, rekaman/tangkapan fisik, TalkBack/VoiceOver, build Debug iOS, MySQL/cPanel/produksi, UAT serta rilis bersama. Pengujian snapshot terbatas pada cache simulator yang disebutkan. Batas ini bukan temuan kode V-1 yang masih terbuka dan tidak diubah menjadi klaim lulus produksi. **Bawa seluruhnya ke gerbang Fase 5/keputusan rilis Human Developer.** Jalur saldo santri keluar/nonaktif (R-10 Fase 3) tetap memerlukan keputusan sebelum rilis akhir.

## Handoff

Ringkasan audit mobile telah di-commit dan di-push sebagai `16bcdb7` pada branch validasi yang sama.

Tidak ada temuan kode baru yang menghalangi penutupan Fase 4; V-1 diterima dan F-1 tidak mengalami regresi. Commit/push dokumen audit pada kedua branch `claude/prd-v6-fase-4-validasi`, lalu berhenti. Fase 5 tetap hanya dimulai atas instruksi Human Developer tersendiri. Tidak merge, deploy, migrasi produksi, atau merilis aplikasi; tagihan dan tabungan tetap hanya boleh dirilis bersama setelah gerbang akhir V6.
