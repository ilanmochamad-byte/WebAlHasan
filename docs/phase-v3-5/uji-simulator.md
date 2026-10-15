# Uji simulator Android dan iOS — penutupan Fase 5

15 Oktober 2026, oleh Claude Code (auditor V3 Fase 1–5) atas permintaan Human Developer: **uji di simulator iOS dan emulator Android dianggap cukup sebagai uji pengembang**. Masukan pengguna nyata menjadi tahap berikutnya. Keputusan ini menggantikan syarat "uji perangkat fisik" untuk penutupan Fase 5 (lihat [status penerimaan](status-penerimaan.md)).

## Lingkungan

| Komponen | Versi / konfigurasi |
| --- | --- |
| Web/API | `main` `d919c9e`, server PHP lokal `127.0.0.1:8940`, DB `webalhasan_v3_phase1_test` (MariaDB 12.3.2), akun sintetis `sbx_*` |
| Mobile | `main` `dfae26d` (memuat redesain, pemilih santri/katalog/kalender, draf bertahan, dan K1) |
| iOS | iPhone 17 Pro Simulator, iOS 26.5, build **Release** dengan `EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8940/api/v1` |
| Android | Emulator `Medium_Phone`, build **debug** (dev client) dengan Metro `EXPO_PUBLIC_API_BASE_URL=http://10.0.2.2:8940/api/v1` |
| Penggerak UI | Maestro 2.11.0, flow di [`tests/maestro/`](../../tests/maestro/) |
| Bukti server | Setiap permintaan API dicatat (metode, URL, status) oleh router pencatat lokal |

Data uji dibuat per platform (santri `SBX Santri A1`): satu pelanggaran, satu kasus Internal, satu kasus Rahasia, dan satu publikasi untuk wali A. Hasil akhir memakai iOS kasus 305/306 dan publikasi 344, Android kasus 307/308 dan publikasi 345.

## Hasil

Penolakan dinilai lulus hanya bila server benar-benar menjawab 403 dan aplikasi menampilkan pesan 403 dari server. Kekeliruan validasi lokal yang terlihat serupa tidak dihitung (lihat temuan S1).

| # | Skenario | iOS | Android | Bukti server |
| --- | --- | --- | --- | --- |
| 1 | Menu pembinaan berbasis hak akses | LULUS | LULUS | Uji fisik 27 Sep + login tiap peran |
| 2 | Catat pelanggaran: pemilih santri, katalog (dropdown, 932 entri), kalender tanggal dan jam | LULUS | LULUS | `POST /v3/pelanggaran` 201 |
| 3 | Rekomendasi → kasus Internal → dua sesi → selesai, data sama di web | LULUS (fisik 27 Sep) | LULUS (fisik 27 Sep) | Kasus Selesai versi 3, dua sesi, rekomendasi tertaut di web |
| 5 | Ketuk ganda Simpan | LULUS | LULUS | Tepat 1 baris per penanda (`SIMCATAT…`) |
| 6 | Kasus Rahasia: murobi A, pembimbing B, murobi B ditolak | LULUS | LULUS | `GET kasus/{rahasia}` 403 |
| 7 | Murobi A membaca kasus Internal dan menandai mengetahui | LULUS | LULUS | `GET` 200, `POST …/diketahui` 201; ulangan 409 "menduplikasi" |
| 8 | Pembimbing B dan murobi B menebak ID kasus Internal | LULUS | LULUS | 403 |
| 10 | Wali A membuka publikasinya: hanya snapshot, tanpa isi internal | LULUS | LULUS | `GET publikasi/{id}` 200 |
| 10b | Wali A membuka kasus internal | LULUS (ditolak) | LULUS (ditolak) | 403 |
| 11 | Wali B membuka publikasi wali A | LULUS (ditolak) | LULUS (ditolak) | 403 |
| 13 | Kasus diubah dari perangkat lain → 409 → "Muat ulang versi terbaru" | LULUS | LULUS | `POST …/status` 200 (API) lalu 409 (aplikasi) |
| 14 | Draf formulir tetap ada setelah aplikasi ke latar | LULUS | LULUS | Uraian tetap terlihat setelah home → buka lagi |
| 15a | Server tidak terjangkau → pesan jaringan → "Coba lagi" memuat ulang | LULUS | LULUS | Server dimatikan lalu dinyalakan |
| 15b | Token dicabut di server → 401 → kembali ke login | LULUS | LULUS | `GET /v3/pelanggaran` 401 |
| 16 | Teks sangat besar (iOS AX5, Android font 2.0): semua kontrol sampai Simpan terjangkau | LULUS | LULUS | Screenshot |
| 17 | Penugasan murobi A dinonaktifkan → kasus Internal ditolak → penugasan dipulihkan | LULUS | LULUS | 403 |
| 9/12 | Push notifikasi sungguhan (murobi dan publikasi) | TIDAK DAPAT DIUJI | TIDAK DAPAT DIUJI | Aplikasi sengaja menolak registrasi push di simulator (`Device.isDevice`) |

Skenario 4 (dua sesi dan penutupan) dan skenario push memakai bukti uji fisik Human Developer 27 September 2026 serta receipt produksi (7 Terkirim/OK). Payload push generik sudah terbukti otomatis dan oleh verifier Fase 4 di produksi ("Payload generik" lulus). Rotasi layar tidak diuji karena `app.json` mengunci orientasi `portrait`. Pembaca layar (VoiceOver/TalkBack) tidak diuji; label aksesibilitas dipakai sebagai selektor Maestro dan tersedia pada kontrol formulir.

## Temuan

| ID | Temuan | Tingkat | Tindakan |
| --- | --- | --- | --- |
| S1 | Deep link yang dibuka tepat saat aplikasi masih berpindah layar setelah login kadang kehilangan parameter `id`/`jenis`. Layar lalu menampilkan "Informasi tidak dapat diakses." tanpa meminta ke server, dan "Coba lagi" tidak memulihkannya karena parameter tetap kosong. | Rendah | Navigasi dari notifikasi memakai `router.push` dengan parameter, bukan URL, sehingga alur pengguna normal tidak terdampak. Flow uji menunggu beranda stabil dan membuka deep link dari luar (`simctl`/`adb`). Perbaikan klien diserahkan ke Codex bila dianggap perlu. |
| S2 | Build **debug** Expo menyuntikkan `.env.local` saat aplikasi berjalan dan mengalahkan `EXPO_PUBLIC_API_BASE_URL` dari shell, sehingga aplikasi debug diam-diam memakai API produksi. | Proses | Selama pengujian, beberapa percobaan login gagal dengan username `sbx_*` (tidak ada di produksi) terkirim ke produksi. Ada juga login manual Human Developer dengan akunnya sendiri, disertai dua `GET` kasus yang ditolak. Tidak ada perubahan data produksi. Untuk uji lokal, `.env.local` dipindahkan sementara lalu dikembalikan. Build Release iOS tidak terdampak. |
| S3 | `openLink` Maestro di Android memotong URL pada `&`; di iOS tidak andal setelah login. | Alat uji | Deep link dibuka lewat `adb shell am start -d '…'` dan `xcrun simctl openurl`. |
| S4 | Formulir catat memuat seluruh katalog dengan 39 permintaan `options?page=` setiap kali dibuka (DB uji: 932 katalog). | Rendah (kinerja) | Katalog produksi kecil (±8). Pantau bila katalog membesar. |
| S5 | Pada ukuran teks terbesar iOS, petunjuk kolom tanggal terpotong ("Pilih tan…"). | Kosmetik | — |
| S6 | Build Release Android menolak HTTP cleartext ke server lokal. | Bukan bug | Perilaku benar untuk produksi; uji Android memakai build debug. |

## Menjalankan ulang

Lihat [`tests/maestro/README.md`](../../tests/maestro/README.md). Screenshot setiap langkah tersimpan lokal di `~/.maestro/tests/2026-10-15_*` (data sintetis) dan tidak dimasukkan ke Git.
