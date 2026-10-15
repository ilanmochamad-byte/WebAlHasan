# Flow Maestro — uji simulator V3 Fase 5

Flow UI untuk iOS Simulator dan emulator Android terhadap **server lokal dan DB uji** (`webalhasan_v3_phase1_test`). Jangan diarahkan ke produksi. Hasil penutupan Fase 5 ada di [docs/phase-v3-5/uji-simulator.md](../../docs/phase-v3-5/uji-simulator.md).

## Prasyarat

1. Server API lokal dari root WebAlHasan:
   `DB_NAME=webalhasan_v3_phase1_test PERAPIHAN_AUDIT_DB=1 php -S 127.0.0.1:8940 tests/v3_phase2_router.php`
2. Aplikasi:
   - **iOS:** build Release, misalnya `LANG=en_US.UTF-8 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8940/api/v1 npx expo run:ios --configuration Release`.
   - **Android:** build debug ditambah Metro di port 8082 dengan `EXPO_PUBLIC_API_BASE_URL=http://10.0.2.2:8940/api/v1`. Build Release Android menolak HTTP cleartext.
   - **Penting:** build debug menyuntikkan `.env.local` saat aplikasi berjalan. Pindahkan `.env.local` sementara selama Metro uji berjalan, atau aplikasi akan memakai API produksi.
3. Data uji dibuat dengan seeder sintetis: pelanggaran, kasus Internal, kasus Rahasia, dan publikasi untuk `sbx_ortu_a`.
4. Jangan menyentuh simulator/emulator selama flow berjalan.

## Flow

| Berkas | Skenario |
| --- | --- |
| `login.yaml` | Subflow login akun `sbx_*` (env `USER`) |
| `01-murobi-a.yaml` | Murobi membaca dan menandai kasus Internal; kasus Rahasia ditolak |
| `02-tolak-silang.yaml` | Akun lain (`OTHER`) ditolak pada kasus Internal dan Rahasia |
| `03-wali.yaml` | Wali A membaca publikasinya; wali B ditolak |
| `04-pembimbing-catat.yaml` | Catat pelanggaran memakai pemilih dan kalender; draf bertahan di latar; ketuk ganda Simpan |
| `05-konflik-versi.yaml` | 409 karena versi berubah dari perangkat lain, lalu muat ulang |
| `06a`–`06d` | Daftar → server mati → pulih → token dicabut (401); server dimatikan/dinyalakan dari skrip luar |
| `08-aksesibilitas.yaml` | Teks sangat besar; ukuran teks diatur dari luar flow |
| `cek/*.yaml` | Pemeriksaan setelah deep link dibuka dari luar |

`openLink` Maestro tidak andal untuk URL dengan `&` (Android memotongnya; iOS kadang kehilangan parameter setelah login). Untuk penilaian, deep link dibuka dengan `xcrun simctl openurl <udid> '<url>'` atau `adb shell "am start -a android.intent.action.VIEW -d '<url>' com.ilanmochamad.alhasanApps"`, lalu flow `cek/*.yaml` dijalankan. Penolakan hanya dihitung lulus bila pesan 403 dari server muncul.
