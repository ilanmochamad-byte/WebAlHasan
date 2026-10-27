# PRD V7 Fase 4 — Paritas Aplikasi Mobile

**Status (27 Oktober 2026): implementasi selesai pada kedua repositori, menunggu audit independen Codex.** Belum merge, belum deploy, belum migrasi produksi, belum rilis aplikasi. Fase 5 belum dimulai. Fase 4 **bukan** izin rilis parsial V7; gerbang akhir/produksi V6 tetap terbuka.

| Butir | Nilai |
| --- | --- |
| Workstream | PRD V7 Fase 1–5 pengajuan izin orang tua melalui pembimbing — implementator Claude Code, auditor Codex (`AGENTS.md`, keputusan 27 Oktober 2026) |
| Branch | `claude/prd-v7-fase-4` di **WebAlHasan** dan **alhasanApps** |
| Baseline web | Commit audit Fase 3 `2e2acc7c9fd8e1a742e69d081faab47bd95756c5` (= `origin/claude/prd-v7-fase-3`). `origin/main` `2071bc9` (merge PR #97) memuatnya; `git diff 2e2acc7 origin/main` kosong. Branch dibuat dari `2e2acc7`. |
| Baseline mobile | `origin/main` `d3a76461c852cc02bceae98963699b5f8f6c7250` (merge PR #19, memuat audit V6 Fase 5 `68a4121`; `git diff 68a4121 origin/main` kosong). Branch dibuat dari `d3a7646`. |
| Migrasi | **Tidak ada.** |
| Endpoint baru | **Tidak ada.** Satu kunci aditif `asal` pada penyajian pengajuan REST V2. |
| Dependensi/modul native baru | **Tidak ada.** `expo-screen-capture ~57.0.4` sudah terpasang sejak V5 Fase 4. Expo SDK 57; dokumentasi `expo-notifications` dan `expo-screen-capture` v57 dibaca sebelum menulis. |
| Lingkungan uji | PHP 8.4, MariaDB 12.3.2 lokal, Node 26, Chromium Playwright; DB `test_*_test` berfixture sintetis. Aplikasi: salinan tanpa `.env*`, `node_modules` dari `package-lock.json` ter-commit (Expo 57.0.15). Perubahan `package.json`/`package-lock.json` yang belum di-commit di checkout mobile milik pekerjaan lain **tidak disentuh dan tidak di-commit**. Direktori web milik pekerjaan lain (`docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/`) tidak disentuh. |

## Yang dikerjakan

### Web (WebAlHasan)

1. **`app/Api/IzinApiService.php`** — item `pengajuan` REST V2 (`/izin/pengajuan`, `/izin/antrean`, `/izin/admin/monitor`, `/izin/pengajuan/{id}`) bertambah tepat satu kunci `asal: "Wali"|"Pengurus"` dari kolom 030 (`'Pengurus'` bila kolom belum ada). Aplikasi memakainya untuk menandai pengajuan orang tua di layar keputusan murobi/admin V2 dan menautkan ke tahap pembimbing. Hak, cakupan, dan kunci lain tidak berubah; `app/Izin`, `app/Auth`, `app/Notification`, `api/v1/index.php`, `portal/` tidak berubah.
2. **`docs/api-v1.md`** — bagian "V7 Fase 4".
3. **Uji**: `tests/v7_phase4_static.php`, `tests/v7_phase4_db.sh` (rantai DB Fase 3, tanpa fixture baru), `tests/v7_phase4_http.php` (kontrak REST dengan token seperti aplikasi), `tests/browser/uji-v7-fase4-app.mjs` (aplikasi Expo web lintas peran), `docs/phase-v7-4/perintah-uji.sh`, `perintah-regresi.sh`.

### Aplikasi (alhasanApps)

| Berkas | Isi |
| --- | --- |
| `src/api/izin-wali.ts` | Klien `/v7/izin/*` (akses, anak, daftar wali, antrean/riwayat pembimbing, daftar admin, rincian, kandidat; buat, tinjau, perbaiki, batal, tetapkan pembimbing). Semua lewat `request` bersama; kelima mutasi mengirim `idempotency_key`; tinjau/perbaiki/batal/penetapan mengirim `version`. Tanpa cache disk. |
| `src/app/izin-wali/_layout.tsx` | Navigator V7. `expo-screen-capture` dipasang **sebelum** layar mana pun dimuat (Android FLAG_SECURE, iOS app switcher); modul tidak tersedia → gagal tertutup tanpa data. |
| `src/app/izin-wali/index.tsx` | Pintu per peran dari `/v7/izin/akses`: **wali** (Ajukan izin, saring per anak, daftar), **pembimbing** (Perlu ditinjau / Sudah ditinjau), **admin** (Perlu tindakan admin / Semua), **murobi** (penjelasan + ke antrean Perizinan V2). |
| `src/app/izin-wali/buat.tsx` | Wali memilih anak berelasi aktif, tanggal izin/kembali, alasan, catatan → tinjau → kirim. |
| `src/app/izin-wali/[id].tsx` | Rincian sesuai `peran_akses` server: wali pengaju (isi, catatan pembimbing, keputusan, riwayat, **Perbaiki dan kirim ulang**, **Batalkan** beralasan dengan konfirmasi); wali lain (status ringkas saja); pembimbing (Teruskan / Minta perbaikan / Tolak beralasan); admin (tetapkan pembimbing sah dari kandidat server, jejak penetapan dan notifikasi; tahap murobi → layar V2); murobi (→ "Putuskan di Perizinan" V2 pada ID sama). Tujuan ketukan notifikasi; penunjuk dilepas hanya setelah server mengizinkan. Konflik 409 → "Muat versi terbaru". |
| `src/app/izin-wali/perbaiki.tsx` | Wali pengaju memperbaiki isi hanya saat `tindakan.perbaiki`; versi yang dimuat ikut dikirim; isi sama ditolak sebelum kirim. |
| `src/components/izin-wali-shared.tsx` | `Halaman` (isi ditutup saat aplikasi tidak aktif), kartu, lencana status, pager, `useIzinWaliAkses` (503/404 = modul tidak tersedia). |
| `src/hooks/use-izin-wali-mutation.ts` | Pembungkus `useMutationGuard`: satu kunci per isian (ketukan ganda/retry jaringan → kunci sama), 401/403/404 menutup data dan draf, 409 memberi tahu layar. |
| `src/izin-wali/forms.ts` | Validasi isian/catatan/alasan setara server, label tahap/peristiwa, tautan `alhasanapps://izin-wali/{id}`. |
| `src/notifications/notification-context.tsx`, `src/rapor/forms.ts` | Payload `{tipe:"izin_wali", pengajuan_id}` dan tautan skema dikenali; penunjuk **bertahan melewati login** (foreground, background, cold start); rute `/izin-wali/[id]`. |
| `src/app/notifikasi/[id].tsx` | Tombol "Buka pengajuan izin" untuk tautan `izin_wali`. |
| `src/app/izin/[id].tsx`, `src/api/types.ts` | Layar V2: panel "Diajukan orang tua" + "Lihat tahap pembimbing" bila `asal = Wali`; tipe `asal?` opsional (server lama tidak mengirimnya). Keputusan/penetapan murobi tetap logika V2 yang sama. |
| `src/app/(app)/(izin)/perizinan.tsx`, `src/app/_layout.tsx` | Pintu "Izin orang tua melalui pembimbing" di tab Perizinan (bila server melaporkan peran V7); keterangan orang tua diperbarui; navigator didaftarkan di balik `Stack.Protected` sesi. |
| `tests/izin-wali.test.ts` | Unit validasi, label, dan penunjuk tautan. |

Jalur dan layar pengurus V2 (buat, daftar, keputusan, penetapan murobi, koreksi, laporan) tidak diubah selain panel penanda asal.

## Bukti (lokal; lihat `bukti/`)

| Uji | Hasil | Catatan |
| --- | --- | --- |
| Statis Fase 4 (`tests/v7_phase4_static.php`) | **31/0** | batas berkas web, kunci `asal`, klien/mutasi/tautan/privasi aplikasi, layar V2 tetap. |
| HTTP REST seperti aplikasi (`tests/v7_phase4_http.php`) | **54/0** | akses per peran; kunci sama → satu ID; kunci sama isi beda 409; benturan 409; no-store; wali kedua ringkas & 403 saat mutasi; wali asing/pembimbing lain/murobi sebelum penerusan 404; perbaiki → teruskan → keputusan V2 ganda → 1 keputusan; status sama 5 pembaca; ambigu → admin menetapkan (bukan sah 422, wali 403); batal; pencabutan penugasan/relasi → token lama 404; notifikasi tautan tanpa nama/alasan; `asal` pada monitor; 401; log server bersih. |
| Aplikasi Expo **web** 375 px (`uji-v7-fase4-app.mjs`) | **48/0**, galat PHP server 0, bundel host produksi 0 | wali A: validasi, ketukan ganda + jawaban hilang → satu POST, kirim ulang berkunci sama → satu ID, benturan 409; wali kedua ringkas; wali asing/pembimbing lain/murobi sebelum penerusan ditolak tanpa isi; pembimbing minta perbaikan, perangkat kedua basi → 409 → "Muat versi terbaru"; wali memperbaiki; teruskan/setujui ketukan ganda → satu POST/keputusan; notifikasi murobi → rincian → V2; paritas aplikasi/API/DB/website; admin menetapkan; batal berkonfirmasi; pencabutan saat layar terbuka; offline/pulih; token dicabut → masuk; tautan tanpa sesi → masuk; penyimpanan/konsol tanpa alasan/token; nol permintaan luar; WhatsApp 0. Chromium, bukan perangkat. |
| Mobile tsc / lint / unit | tsc 0, lint 0, unit **42/0** | lock ter-commit Expo 57.0.15. |
| Android **emulator** (dev client Debug + Metro) | 11 skenario lulus, `bukti/android-emulator.txt` | pemilih tanggal native, ketukan ganda, FLAG_SECURE/Recents, tautan latar, logout → tautan → login, wali asing, pembimbing, murobi via V2, wali kedua, pencabutan relasi, logcat/berkas data. |
| iOS **simulator** (Release arm64 ad-hoc) | 5 skenario lulus, `bukti/ios-simulator.txt` | **cold start** dari tautan saat belum masuk → login → rincian; batal beralasan + ketukan ganda; app switcher tertutup; paritas dengan pengajuan Android; kontainer/log. |
| Regresi (`perintah-regresi.sh`) | lihat di bawah | `bukti/regresi/ringkasan.txt`. |

### Regresi

- V7 Fase 3 integrasi **112/0**, HTTP **43/0**; Fase 2 integrasi **119/0**, HTTP **61/0**; Fase 1 HTTP **71/0**.
- Penugasan **161/0**; V2 integrasi **39/0 · 94/0 · 124/0 · 145/0**, konkurensi **20/0**, API Fase 5 **150/0**, web **36/0 · 46/0 · 79/0**; verifier Fase 2/3 sesudah enam DB regresi masing-masing **41/0 · 8/0**.
- HTTP redesain Tahap 4 (perizinan/cetak) **132/0**; browser kerangka sembilan akun **91/0**.
- V2 API Fase 3 **95/21** dan Fase 4 **91/1**: daftar `[gagal]` **identik baris per baris** dengan regresi Fase 3 (oracle historis yang sudah tercatat sejak audit Fase 1), sehingga kunci `asal` tidak menambah kegagalan.
- 43 suite statis: **18 exit 0 · 23 exit 1 · 2 exit 77**. Dibanding ringkasan regresi Fase 3, satu-satunya selisih adalah `v7_phase3_static` 44/0 → **43/1** — oracle "hanya berkas Fase 3 berubah dari 96dc827" kini melihat berkas Fase 4 (`IzinApiService`, dokumen, uji); suite baru `v7_phase4_static` 31/0. Oracle tidak dilonggarkan.

## Penafsiran (perlu ditinjau auditor)

- **S-1 Perlindungan tangkapan layar pada navigator izin wali.** PRD meminta tidak membocorkan data santri/alasan di log/cache perangkat. Cuplikan pengalih aplikasi adalah cache OS; mengikuti keputusan Human Developer T-1 untuk kesehatan (V5) dan keuangan (V6), navigator V7 memakai `expo-screen-capture` (tanpa dependensi baru). Konsekuensinya wali tidak dapat mengambil tangkapan layar status izin di aplikasi (web tetap). Layar perizinan V2 (termasuk keputusan murobi atas pengajuan wali) **tidak** diubah dan tetap tanpa perlindungan seperti sebelumnya. Bila Human Developer tidak menghendaki, cukup melepas efek di `_layout.tsx`.
- **S-2 Keputusan murobi/admin pengganti dan penetapan murobi tetap di layar V2.** Server V7 mengembalikan `buka_v2`; aplikasi membuka `/izin/[id]` pada ID yang sama alih-alih menduplikasi logika keputusan. Antrean murobi = antrean Perizinan V2 (pengajuan wali masuk sesudah diteruskan).
- **S-3 Kunci `asal` V2** ditambahkan agar layar V2 dapat menyembunyikan kebingungan asal; aditif dan diuji statis/HTTP. Klien lama mengabaikannya.
- **S-4 Penunjuk notifikasi V7 bertahan melewati login** (seperti rapor/kesehatan/keuangan) dan dilepas hanya setelah rincian diizinkan server; akun lain yang masuk memperoleh 404 tanpa isi.
- **S-5 Tanpa cache disk.** Respons hanya di memori layar; data dikosongkan saat blur/latar (`usePrivateResource`); draf formulir boleh bertahan di memori saat latar dan ditutup penutup `Halaman`.

## Batas dan yang belum diuji

- **Perangkat fisik: tidak diuji.** Android = emulator; iOS = simulator. Tidak ada klaim perangkat fisik.
- **Push nyata/ketukan notifikasi Push: tidak diuji.** Jalur ketukan diuji lewat tautan skema yang memanggil fungsi `buka()` yang sama serta unit `notificationTarget`; notifikasi in-app → rincian diuji di Expo web. Pengiriman provider Expo/FCM/APNs dan ketukan banner sungguhan tetap terbuka.
- **Cold start**: iOS Release dari tautan **diuji**; Android cold start dari URI tidak dapat dibuktikan dengan dev client (membuka launcher) dan build rilis Android tidak dibuat. iOS Debug/dev client tidak dibangun.
- Simulator iOS: injeksi teks tidak andal untuk `#`; kata sandi lima akun sintetis di DB uji lokal diganti sementara (tidak memengaruhi fixture repo). Tangkapan layar alat host simulator tetap menampilkan isi; pencegahan tangkapan oleh pengguna pada perangkat tidak dibuktikan; snapshot SplashBoard tidak didekode.
- MySQL/cPanel/produksi, UAT: tidak diuji. Tanpa migrasi; tidak ada langkah rilis web khusus selain deploy kode bersama Fase 5.
- **Risiko urutan rilis:** aplikasi lama tidak mengenal `izin_wali`; aplikasi baru membutuhkan endpoint V7 Fase 1–3 di server. Keputusan urutan rilis milik Human Developer.
- Temuan T-1 V7 Fase 1 (retry bersamaan jalur pengurus V2 → sebagian 500) tetap di luar cakupan.

## Handoff

Implementasi Fase 4 selesai pada kedua branch `claude/prd-v7-fase-4`. Implementator berhenti untuk **audit independen Codex** sesuai prompt audit Fase 4 di `docs/prd-v7/prompts-implementasi-audit.md`. Tidak merge, deploy, migrasi produksi, rilis aplikasi, atau memulai Fase 5.
