# Prompt implementasi dan audit — optimasi Android Google Play

21 November 2026 · Satu tahap · Gunakan secara bergantian, bukan bersamaan.

## 1. Claude Code — implementator

```text
Implementasikan satu tahap lengkap pekerjaan optimasi Android untuk peringatan Google Play: GP-1 pengoptimalan DEX/R8 dan GP-2 API tampilan yang ditinggalkan. Human Developer menyetujui rencana pada 21 November 2026 dan menetapkan Claude Code sebagai implementator, Codex sebagai auditor independen.

Repositori:
- Web/dokumen utama: /Users/ilanmochamad/Documents/GitHub/webalhasan/WebAlHasan
- Mobile: /Users/ilanmochamad/alhasanApps

Baca AGENTS.md di kedua repo, docs/optimasi-android-play/rencana.md dan prompt ini di repo web, status/handoff terakhir serta git status dan git log -n 5. Ikuti rencana lengkap termasuk kriteria K-1…K-7; prompt ini bukan pengganti rencana. Jangan menjalankan Claude dan Codex bersamaan pada folder/branch sama.

Gunakan branch claude/optimasi-android-play di kedua repo. Fetch ulang; bila branch dokumen codex/rencana-optimasi-android-play belum digabung, cabangkan dari ujung branch dokumen tersebut, bila sudah digabung gunakan origin/main yang memuatnya. Baseline main yang diperiksa 21 November: web 37e67ef652ab598644fb2c7314b079d4c34abd48, mobile 84ddebbbdedc022dae42f323cd37ae9fcc471cc2 (1.9.0). Verifikasi baseline aktual dan perubahan yang lebih baru; jangan mengimplementasikan di main dan jangan membuat worktree.

Lindungi perubahan lokal package.json/package-lock.json milik pekerjaan lain serta seluruh direktori untracked web yang dicatat dalam rencana. Jangan reset, stash, menimpa, atau menyertakannya secara massal. Perubahan dependensi yang dibutuhkan paket ini harus direkonstruksi dari baseline ter-commit pada salinan sementara bersih, dibuktikan dan dipisahkan dari edit pengguna. Jangan memakai patch lokal yang belum di-commit sebagai bukti build sumber handoff.

Kerjakan seluruh cakupan berikut dalam satu paket, tanpa meminta persetujuan ulang untuk langkah rutin yang sudah diizinkan:
1. Cocokkan AAB rilis 19 (1.9.0) dengan commit/build EAS/profile/dependensi/toolchain; analisis DEX, mapping/r8.json dan aturan keep. Bila AAB asli tidak tersedia, lanjutkan build rekonstruksi baseline dengan label jujur; keterlacakan produksi tetap terbuka.
2. Baca dokumentasi Expo SDK 57 dan Google yang ditautkan rencana. Aktifkan minify R8 dan resource shrinking lewat config plugin Expo yang kompatibel; buktikan Gradle hasil generate dan build Release benar-benar menjalankan optimasi. Evaluasi consumer rules/metadata, gunakan aturan keep terarah; jangan blanket keep/dontwarn atau menonaktifkan obfuscation/optimasi.
3. Petakan setiap simbol GP-2 ke library versi terpaket. Terapkan patch kompatibel atau koreksi reproducible yang dibutuhkan; jangan menganggap perubahan StatusBar JS saja menghapus pemanggilan native. Jangan turunkan target SDK, matikan edge-to-edge/proteksi layar, atau upgrade mayor SDK tanpa keputusan tersendiri.
4. Naikkan patch tepat satu kali dari main terbaru dengan skrip versi; bila baseline tetap 1.9.0, target 1.9.1. Nomor build tetap diurus EAS. Audit/koreksi tidak menaikkan versi lagi untuk paket yang sama.
5. Bangun AAB Release dan APK/split untuk uji dengan R8 aktif dari sumber/lockfile yang akan di-handoff; simpan hash, konfigurasi, mapping dan lokasi aman artefak. EAS build boleh bila akses tersedia, tanpa auto-submit. Jangan masukkan binary, secrets atau mapping privat ke Git.
6. Ukur seluruh metrik DEX yang berlaku pada AAB final, bandingkan baseline dan hasil; periksa simbol GP-2. Jalankan tsc, lint, unit/regresi relevan serta matriks native §5: cold start tanpa Metro, sesi/login, seluruh modul, privasi, native modules, Android 15/16 dan OS lama, gestur/3 tombol, cutout, terang/gelap, teks 2×, keyboard dan bottom sheet. Tambahkan smoke iOS/web sesuai dampak dependensi/kode bersama.
7. Gunakan fixture sintetis dan API nonproduksi yang aman. Jangan melonggarkan transport binary distribusi untuk melewati HTTP lokal. Bedakan emulator, fisik, Push nyata, internal Play dan produksi; jangan mengklaim yang tidak diuji. Siapkan verifikasi internal Play atas AAB yang sama; saat hasilnya belum ada, tulis konfirmasi Play belum tersedia.

Tulis laporan lengkap docs/optimasi-android-play/IMPLEMENTASI.md dan manifest-bukti.md di web, ringkasan/pointer di mobile, serta status AGENTS.md kedua repo. Sertakan commit baseline/final, versi/dependensi/toolchain, build ID/hash, perintah/exit code, angka DEX, daftar simbol GP-2, temuan/risiko, cara build ulang dan pemulihan. Pisahkan status implementasi, kesiapan audit lokal K-1…K-6, dan konfirmasi Play K-7. Jangan mengklaim audit independen sudah lulus.

Selesaikan bagian independen walau ada keterbatasan akses, tetapi jangan mengarang metrik atau menganggap blocker sebagai nonblokir. Commit hanya berkas pekerjaan ini, push kedua branch fitur, laporkan SHA serta bukti dan batas, lalu BERHENTI untuk audit Codex. Jangan merge, deploy, publikasi produksi/OTA, mengubah API/skema/hak bisnis, atau memulai PRD/fase lain.
```

## 2. Codex — auditor independen

Jalankan sesudah Claude Code selesai dan berhenti. Handoff implementator harus menunjuk SHA kedua repo, laporan serta manifest bukti.

```text
Audit independen satu tahap lengkap optimasi Android Google Play buatan Claude Code pada branch claude/optimasi-android-play di kedua repo. Cakupan GP-1 DEX/R8 dan GP-2 API tampilan; tidak memulai tahap atau PRD lain. Human Developer menetapkan Claude Code implementator dan Codex auditor pada 21 November 2026.

Repositori:
- Web/dokumen utama: /Users/ilanmochamad/Documents/GitHub/webalhasan/WebAlHasan
- Mobile: /Users/ilanmochamad/alhasanApps

Baca AGENTS.md kedua repo, docs/optimasi-android-play/rencana.md, prompts-implementasi-audit.md, IMPLEMENTASI.md, manifest-bukti.md, seluruh commit paket dan handoff SHA aktual. Verifikasi git status/log, fetch, baseline, cabang dan preservasi edit lokal pekerjaan lain. Jangan audit sambil Claude masih bekerja pada checkout sama. Jangan main/worktree, reset/stash/menimpa edit pengguna atau mengambil perubahan paket lokal tak terkait.

Audit terhadap kriteria K-1…K-7, bukan hanya klaim implementator:
1. Verifikasi asal AAB rilis 19 atau label rekonstruksi, sumber/lockfile ter-commit, toolchain dan environment. Pastikan hasil tidak bergantung pada edit lokal, Metro atau binary lama. Cocokkan hash AAB/APK, versi/build ID dan pemetaan mapping ke build.
2. Bangun ulang Release secara independen dari sumber handoff dengan dependency bersih. Buktikan R8/shrinking aktif melalui Gradle/log/artifact; periksa config plugin EAS dan consumer keep rules. Audit blanket keep/dontwarn, metadata yang hilang atau patch node_modules yang tidak reproducible. Baca dokumentasi resmi versi yang tepat.
3. Jalankan analisis AAB final secara independen: ukuran DEX tak terkompresi, optimasi/obfuscation/shrinking yang berlaku, mapping/r8.json dan perbandingan baseline. Bila ukuran berada di bawah batas penerapan, buktikan angka serta bedakan dari skor ≥25%. Jangan menyamakan skor Configuration Analyzer dengan skor final vitals atau menutup kriteria hanya karena build sukses.
4. Telusuri semua simbol GP-2 dan dependency versi terpaket; uji alasan kompatibilitas Android lama bila ada. Buktikan koreksi dan layout/insets di Android 15/16 serta OS lama, gestur/3 tombol, cutout, keyboard, dialog, terang/gelap dan teks 2×. UI benar saja tidak menutup residual pemindaian Play.
5. Uji sendiri tsc/lint/unit/regresi serta matriks §5 yang relevan pada binary Release R8 aktif tanpa Metro. Periksa sesi SecureStore, cold start/upgrade bila signing memungkinkan, navigasi/animasi, seluruh modul, foto, cetak/bagikan, URI, privasi tangkapan/Recents dan log. Bedakan URI sintetis dengan Push nyata dan emulator dengan fisik. Tambahkan smoke web/iOS sesuai dampak kode/dependensi bersama.
6. Pastikan patch versi tepat sekali, EAS remote versioning terjaga, tidak ada perubahan API/skema/hak, target SDK diturunkan, proteksi dinonaktifkan, transport dilonggarkan, atau secret/data pribadi masuk bukti/commit.
7. Nilai bukti internal Play/pre-launch report jika tersedia atas hash/build final yang sama. Jika belum tersedia, tulis konfirmasi Play belum tersedia; jangan menyatakan peringatan produksi hilang atau semua gerbang rilis terdahulu tertutup.

Koreksi temuan terarah bila diperlukan dan dalam cakupan, tambah regresi yang membuktikan perbaikan, lalu bangun/analisis/uji ulang artefak terdampak. Bukti milik hash lama tidak otomatis berlaku untuk hash setelah koreksi. Jangan melemahkan oracle agar lulus; pembaruan oracle yang sah harus beralasan dan dikontrol terhadap baseline. Jangan mengadopsi upgrade mayor di luar cakupan.

Tulis docs/optimasi-android-play/AUDIT-CODEX.md lengkap di web dan ringkasan/pointer mobile; perbarui AGENTS.md kedua repo. Catat temuan dengan tingkat/berkas/line, keputusan setiap K-1…K-7, uji independen dan angka, hash/asal binary, koreksi, keterbatasan serta langkah yang memerlukan akses eksternal. Pisahkan: implementasi selesai/belum, audit teknis lokal lulus/belum (K-1…K-6), dan konfirmasi Play/kesiapan produksi terkonfirmasi/belum (K-7 serta batas fisik/Push/upgrade). Jangan otomatis memberi label nonblokir untuk metrik tidak terukur atau residual GP-2.

Commit hanya hasil audit pekerjaan ini, push kedua branch fitur, laporkan SHA dan status yang jujur, lalu BERHENTI. Jangan merge, deploy, publikasi produksi/OTA atau memulai fase/PRD lain. Keputusan produksi tetap Human Developer.
```
