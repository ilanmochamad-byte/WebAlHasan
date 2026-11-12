# Redesain tampilan aplikasi gelombang 2 — Fase 2 (pengajian, perizinan, izin melalui pembimbing, notifikasi)

Tanggal: 12 November 2026 · Implementator: Claude Code · Auditor berikutnya: Codex

Status: **siap audit Codex**, bukan persetujuan merge, deploy, atau rilis aplikasi. Fase 3 belum dimulai.

Sumber kebenaran: [`../rencana.md`](../rencana.md) (§2–§3, §4.2 Jadwal dan Perizinan, §4.3, §4.4, §4.5, §4.11, §5 K-7, K-8, K-10) dan [`../fase-1/AUDIT-CODEX.md`](../fase-1/AUDIT-CODEX.md).

## Branch dan baseline

| Repo | Branch | Dari | Isi |
| --- | --- | --- | --- |
| alhasanApps | `claude/redesain-tampilan-v2-fase-2`, commit `d9aec8f` | `origin/main` `707b7b7` (PR #30; `git diff 8c8e025 origin/main` kosong — isi sama dengan koreksi audit Fase 1; versi 1.5.0) | Kode tampilan dan uji unit; versi **1.5.0 → 1.6.0** |
| WebAlHasan | `claude/redesain-tampilan-v2-fase-2` | `origin/main` `660931c` (PR #119; `git diff fbf9a2e origin/main` kosong — isi sama dengan catatan audit Fase 1) | **Hanya** uji, alur Maestro, dan dokumen ini. Kode web, API, dan migrasi tidak berubah |

Fase 1 sudah digabung ke `main` kedua repo oleh Human Developer walau audit Codex mencatat "belum lulus penuh" (perbedaan §4.1–§4.2 tetap terbuka dan tidak disentuh fase ini). Tanpa worktree; tidak menulis di `main`. `package.json`/`package-lock.json` checkout aplikasi yang berubah milik pekerjaan lain tidak di-commit; lima direktori web belum terlacak milik pekerjaan lain tidak disentuh.

Dokumentasi Expo SDK 57 yang dibaca sebelum menulis kode: tipe dan komentar `expo-router/build/native-tabs/types.d.ts` pada paket terpasang (57.0.15) serta halaman Native tabs Expo Router (URL berversi v57 menjawab 404; halaman tak berversi memuat blok SDK 55–57). Isinya yang dipakai: di **Android** isi layar tab dibungkus `SafeAreaView` dengan inset **bawah**; di **iOS** area gulir pertama mendapat inset otomatis dan isi bergulir di balik bilah tab; **tinggi bilah tab tidak dapat diukur** ("Cannot measure the tab bar height"); untuk inset manual dipakai `SafeAreaView` dari `react-native-screens/experimental`.

## Yang berubah (aplikasi)

Hanya tampilan. Tidak ada perubahan endpoint, parameter, payload, hak, aturan bisnis, idempotensi, versi optimistik, atau perlindungan `expo-screen-capture`. Tanpa dependensi/modul native baru (`react-native-screens` sudah dependensi langsung). Seluruh pemanggilan `api.*`/`izinWali.*`, `guard.run`, `createIdempotencyKey`, `usePrivateResource`, dan `IzinWaliProtection` tidak berubah; tiga baris yang muncul di diff hanya berpindah tempat dengan isi identik.

### Komponen

| Komponen | Berkas | Perubahan |
| --- | --- | --- |
| `StackScreen` (baru) | `src/components/screen-shell.tsx` | Kerangka layar stack: kepala ← · judul · ⋯ **di luar area gulir**, juga saat memuat/galat/ditolak server |
| `Notice` (baru) | `src/components/ui/notice.tsx` | Kotak hasil/galat/keterangan: ikon + teks |
| `Fab` + `FabArea` + `useKeyboardVisible` | `ui/fab.tsx` | Lapisan FAB yang mengikuti bilah tab sistem (lihat K-8) dan hilang saat papan ketik terbuka |
| `EntityRow` | `ui/entity-row.tsx` | `detail`, `highlight`, peran `radio` + `selected`; avatar/ikon disembunyikan pada teks sistem > 1,35× |
| `SearchBar` `trailing`; `FilterPills` `role="tab"`; `InfoRow` `children`/`selectable`; `AppButton` varian `line` | `ui/search-bar.tsx`, `ui/filter-pills.tsx`, `ui/info-row.tsx`, `app-button.tsx` | Perluasan aditif |
| `ScreenHeader` | `screen-header.tsx` | `backLabel` (varian `stack`); judul varian `akar` satu baris yang menyusut bila perlu |
| `ScheduleCard`, `StatusSelector`, `PesertaSaring`, `IzinCard`, komponen izin wali, `WeekStrip` | `schedule-card.tsx`, `status-selector.tsx`, `peserta-saring.tsx`, `izin-card.tsx`, `izin-wali-shared.tsx`, `ui/week-strip.tsx` | Disusun ulang dengan komponen Fase 1; teks, peran, dan nama aksesibilitas dipertahankan |
| Pembantu murni | `src/lib/tampilan.ts`, `src/izin-wali/forms.ts` | `waktuRelatif`, `fabMelewatiSetengahLayar`, `jarakAtasLayarTab`, `langkahTahap` |

### Layar

| Layar (rute) | Pola | Perubahan tampilan |
| --- | --- | --- |
| Jadwal (`(schedules)/schedules`) | P-Akar | ← ke Beranda di atas judul besar + subjudul; pil Per pekan / Rentang kustom; `WeekStrip`; kartu sesi: blok jam, kitab + **seluruh kelas gabungan**, `StatusPill`, penanda Absen |
| Perizinan (`(izin)/perizinan`) | P-Akar + P-Daftar | ← ke Beranda; `SearchBar`; `FilterPills` Perlu tindakan · N / Semua / Disetujui / Ditolak; baris entitas; **FAB "Ajukan izin"** (K-8) |
| Rincian tugas (`schedule/[id]`) | P-Rincian | Kartu kepala hijau pucat (kitab, jam, pil status); `InfoRow` Guru, Kelas (chip per kelas gabungan), Kitab, Tempat, Tahun ajaran; "Buka pertemuan" di bilah bawah |
| Absensi (`meeting/[id]`) | P-Daftar + P-Form | Kepala ringkas; ringkasan mini yang ikut draf; `SearchBar` + tab kelas asal; baris santri: avatar inisial, NIS · kelas, pemilih status lima tombol ikon + huruf; "Semua hadir (N)" tombol garis; **"Perbarui daftar" di ⋯**; hasil kirim sebagai panel "Absensi tersimpan" |
| Laporan kehadiran (`reports`) | P-Laporan | Pil rentang; kartu sorot "Persentase hadir"; grafik batang per status; ringkasan per jadwal dan detail sebagai baris entitas; Cetak PDF / Bagikan di ⋯; penyaring di lembar bawah |
| Rincian laporan (`report/[id]`) | P-Rincian | Kepala pertemuan; angka per status dari ringkasan server; cari + tab kelas asal; baris santri ber-`StatusPill` |
| Buat izin (`izin/buat`) | P-Form langkah | `SearchBar` + baris santri (radio); kartu santri terpilih; kolom berikon dan penghitung karakter; Tinjau; hasil kirim sebagai panel "Pengajuan terkirim" di rincian |
| Rincian izin (`izin/[id]`) | P-Rincian | Kartu kepala (avatar, nama, pil status besar); `InfoRow`; garis waktu riwayat; satu tindakan utama di bilah bawah sesuai `aksi` server |
| Laporan izin (`izin/laporan`) | P-Laporan | `SearchBar` + penyaring tanggal/status di lembar bawah; `StatGrid` total per status; baris entitas; Cetak / Bagikan PDF / Bagikan CSV di bilah bawah |
| Izin melalui pembimbing (`izin-wali/index`, `buat`, `[id]`, `perbaiki`) | P-Daftar / P-Form / P-Rincian | Pil anak dan pil Antrean/Riwayat; baris entitas ber-pil tahap; **FAB "Ajukan izin" untuk wali**; kartu anak terpilih; **garis waktu tahap Wali → Pembimbing → Murobi**; tindakan di bilah bawah; catatan pembimbing di kartu peringatan |
| Notifikasi (`notifikasi/index`, `[id]`, `perangkat`) | P-Daftar / P-Rincian | Pil Semua / Belum dibaca / Sudah dibaca; baris berikon modul, waktu relatif, penanda belum dibaca; "Tandai semua dibaca" dan "Perangkat & push" di ⋯; kartu pesan + tombol "Buka …"; kartu perangkat ber-pil status |

Kepala native dimatikan (`headerShown: false`) untuk seluruh rute di atas; geser-tepi iOS dan tombol kembali Android tetap bekerja.

### K-10 — tombol ←

Setiap rute fase menggambar tepat satu ←: Jadwal dan Perizinan berlabel "Kembali ke Beranda" (→ tab Beranda); layar stack berlabel "Kembali" (→ halaman sebelumnya, atau Beranda bila dibuka tanpa riwayat). Pada alur berlangkah ← kembali ke langkah sebelumnya dulu (Buat izin: langkah 2/3; Ajukan izin anak: dari Tinjau ke isian) tanpa membuang isian. Layar yang ditolak server (403/404) tetap ber-←.

### K-8 — pilihan akhir dan buktinya

**FAB dipakai pada skala teks sampai sekitar 1,3×; alternatif (tombol pil lebar penuh "Ajukan izin baru" di bawah judul, sebelum kotak cari) dipakai hanya bila FAB dapat melewati setengah lebar layar** (`fabMelewatiSetengahLayar`: 70 pt + 84 pt × skala teks > ½ lebar). Pada ponsel 402–412 pt itu berarti skala ≥ ±1,6×.

| Syarat K-8 | Penerapan | Bukti |
| --- | --- | --- |
| Pil hijau 56 pt, ikon + teks "Ajukan izin", label "Ajukan izin baru" | `Fab` | web 56 pt; Android 56 dp; iOS 56 pt |
| 16 pt dari tepi kanan | `right: 16 + insets.right` | web 16; Android 16 dp; iOS 16 pt |
| 16 pt di atas bilah tab, **tinggi bilah dari sistem** | `FabArea`: iOS `SafeAreaView` bawah milik `react-native-screens` (membaca area aman termasuk bilah Liquid Glass); Android dasar wadah = tepi atas bilah (isi tab sudah di-inset native tabs); web bilah di atas isi. Tanpa angka tetap untuk posisi FAB | Android: jarak 16 dp ke bilah Material 3; iOS: bawah FAB 775 pt, butir bilah tab mulai 795 pt (jarak 20 pt ke butir; tepi kaca bilah sedikit di atasnya); web: 16 pt dari dasar jendela, tidak menyentuh bilah atas |
| Ruang bawah daftar setinggi FAB + 16 | `paddingBottom = FAB_RUANG_BAWAH + 16 + 24` (+ tinggi bilah di iOS karena isi bergulir di balik bilah) | Ujung daftar: paginasi "Berikutnya" di atas FAB dan dapat diketuk (web, Android, iOS) |
| Hilang saat papan ketik terbuka | `useKeyboardVisible` (native) / fokus kotak cari (web) | Android `mInputShown=true` → FAB tidak ada; iOS `assertNotVisible`; web fokus/blur |
| Syarat tampil sama | `capabilities.aksi.dapat_membuat_pengajuan && !orangTua` | Pengurus, admin, pembimbing tampil; murobi dan orang tua tidak (web = capability API; Android; iOS) |
| Teks besar | FAB melebar pada 1,3× (172 dp ≤ 206); ≥ ±1,6× → alternatif | Android 2,0×; iOS AX-XL/AX-XXXL: tombol atas, tanpa FAB |

FAB tidak bertabrakan dengan bilah tab dan tidak menutupi isi di ketiga platform, sehingga alternatif tidak dipakai pada skala normal.

## Penafsiran (mohon ditinjau auditor / Human Developer)

| No | Penafsiran | Alasan |
| --- | --- | --- |
| P2-1 | "Perbarui daftar" pindah ke ⋯ sesuai perintah. Suite `uji-perbaikan-peserta-absensi.mjs` disesuaikan **hanya pada pencari tombol** (membuka ⋯ lebih dulu; satu pernyataan diperketat: ⋯ juga harus hilang saat Selesai) | Suite asli dari `origin/main` dijalankan apa adanya: 1 lulus / 2 gagal, tepat pada tombol itu. Suite yang disesuaikan lulus 18/0 di kode fase **dan** di baseline |
| P2-2 | Tombol kirim absensi tetap berlabel "Konfirmasi dan kirim" (rencana menulis "Kirim absensi"); hasil kirim tampil sebagai panel di layar yang sama (P-Sukses ringkas), bukan layar pengganti | Tiga suite regresi menekan tombol bernama itu, lalu melanjutkan koreksi di layar yang sama. Oracle tidak dilonggarkan |
| P2-3 | Kolom alasan keputusan izin tetap di badan layar; Setujui/Tolak di bilah bawah **tidak** membuka lembar beralasan | Suite V7 Fase 4 mengisi kolom alasan lalu menekan Setujui. Keputusan tetap memerlukan alasan: tanpa alasan server menolak dan status tidak berubah (diuji) |
| P2-4 | P-Sukses Buat izin dan Ajukan izin anak = panel "Pengajuan terkirim" di rincian yang dibuka otomatis, bukan layar terpisah bertombol "Lihat pengajuan" | Urutan permintaan sesudah kirim (POST lalu GET rincian) tidak berubah |
| P2-5 | FAB wali berlabel aksesibilitas "Ajukan izin" (bukan "Ajukan izin baru"), dan tidak digambar bila `boleh_mengajukan` salah (dulu tombol nonaktif); keterangannya tampil sebagai kotak | Nama tombol yang ada dipertahankan (suite V7); FAB nonaktif tidak bermakna |
| P2-6 | ← di Buat izin berlabel "Kembali ke halaman sebelumnya" / "Kembali ke langkah sebelumnya" | Layar itu punya kolom tanggal berlabel "Kembali"; dua kontrol bernama sama membingungkan pembaca layar dan pencari label suite lama |
| P2-7 | Pil rentang Laporan: "Bulan ini" (bawaan) · 7 hari · 30 hari · Kustom | Rencana menyebut 7/30/Kustom; "Bulan ini" dipertahankan sebagai bawaan agar permintaan awal tidak berubah. Pil hanya mengganti nilai tanggal pada endpoint dan parameter yang sama |
| P2-8 | Ringkasan mini absensi memuat lima status (termasuk Terlambat), dan rekap batang di bawah daftar dipertahankan | Rencana menyebut empat; menyembunyikan Terlambat menghilangkan informasi |
| P2-9 | Pemilih status santri: ikon + huruf awal (H/T/I/S/A), nama lengkap sebagai label aksesibilitas; pada teks > 1,35× nama lengkap. Kehadiran guru: ikon + nama lengkap | "5 tombol ikon+huruf" agar muat satu baris |
| P2-10 | Rincian izin wali: tinjauan pembimbing dan perbaiki/batal wali di bilah bawah; "Tetapkan pembimbing" (admin) dan "Muat ulang" tetap di badan | Daftar kandidat pembimbing dimuat oleh panel penetapan sendiri; memindah tombolnya berarti memindah pemuatan itu |
| P2-11 | Rincian izin: satu tindakan utama di bilah bawah — keputusan; tanpa keputusan → Tetapkan murobi; tanpa keduanya → Batalkan. Tindakan lain tetap di panelnya | Bilah bawah tidak memuat lebih dari dua tombol |
| P2-12 | Notifikasi: penanda belum dibaca berupa pil ikon + teks (bukan titik saja); ikon modul dari `tautan.tipe`; waktu relatif dihitung dari waktu server (WIB), di luar 7 hari atau format lain → teks server apa adanya | Status tidak boleh dibawa warna/titik saja; tidak ada waktu karangan |
| P2-13 | Kepala `akar` (Jadwal, Perizinan) ikut tergulir seperti Profil Fase 1; kepala `stack` tetap di atas | Konsisten dengan Fase 1 |
| P2-14 | "Persentase hadir" = Hadir ÷ baris kehadiran dari ringkasan server, dibulatkan; tanpa data → "—" | Turunan langsung dua angka server, tanpa pembanding |
| P2-15 | Berkas di luar `src/components`/`src/constants`/layar fase: `src/lib/tampilan.ts`, `src/izin-wali/forms.ts`, `tests/redesain-v2-*.test.ts`, `app.json` (versi) | Pembantu murni agar dapat diuji di Node |
| P2-16 | Jarak atas ganda di iOS pada layar tab (inset otomatis native tabs + `insets.top`, sudah ada sejak baseline) diperbaiki untuk Jadwal dan Perizinan; Profil (Fase 1) tidak disentuh | Ditemukan saat uji iOS; Profil di luar fase |
| P2-17 | Komponen bersama yang ikut mengubah layar di luar fase: `ScheduleCard` (Beranda), `PesertaSaring` (rincian kehadiran Keamanan), `EntityRow`, judul `akar` (Profil), `AppButton`, `WeekStrip` | Regresi Keamanan F4, profil, dan Fase 1 lulus |

## Bukti

Lingkungan: macOS, Node 26.7, PHP 8.4.14, MariaDB 12.3.2 lokal, fixture sintetis. Expo dijalankan dari **salinan tanpa `.env*`** (paket = HEAD, `node_modules` nyata dari lock ter-commit, Expo 57.0.15), `EXPO_OFFLINE=1`, `EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8971/api/v1`; bundel web diperiksa tanpa `alhasan.co.id`; semua skrip Playwright memblokir host selain `127.0.0.1` (nol permintaan keluar tercatat). Berkas di [`bukti/`](bukti/).

| Uji | Hasil |
| --- | --- |
| `npx tsc --noEmit` · `expo lint` (salinan bersih) | exit 0 · exit 0 (`bukti/aplikasi/`) |
| Unit `node --test tests/*.test.ts` | **97/0** (baseline `707b7b7`: 90/0). Baru: `redesain-v2-fase2.test.ts` (7); `redesain-v2-kontras.test.ts` ditambah sembilan pasangan Fase 2 |
| Aturan versi `versi:periksa --kerja` | LULUS 1.5.0 → 1.6.0 (minor, satu kali) |
| Expo web `tests/browser/uji-redesain-v2-fase2.mjs pengajian` (guru; 375/768/1280, terang/gelap) | **67/0** |
| Expo web `uji-redesain-v2-fase2.mjs izin` (pengurus, admin, murobi, pembimbing, orang tua V2, wali V7) | **144/0** |
| Log jaringan `uji-redesain-v2-fase2-jaringan.mjs` | lihat Jaringan |
| Regresi Expo web (`perintah-regresi.sh`, kode akhir) | Fase 1 **159/0** (1 dilewati: rute pratinjau komponen tidak dipasang), profil **105/0**, kelas gabungan **57/0**, pencarian absensi **53/0**, pencarian daftar **24/0**, peserta absensi **18/0**, V7 F4 **48/0**, Keamanan F4 **46/0**, V6 F4 **45/0**, V5 F4 **78/0**, V5 F5 **36/0** — total **669/0**; galat PHP server API 0 (`bukti/regresi/`) |
| Suite peserta absensi versi `origin/main` (tanpa penyesuaian pencari) | 1 lulus / 2 gagal pada kode fase (diharapkan, P2-1) |
| Android emulator | lihat Native |
| iOS simulator | lihat Native |

Oracle seluruh suite lama tidak dilonggarkan. Satu-satunya suite yang diubah adalah `uji-perbaikan-peserta-absensi.mjs` (P2-1).

### Jaringan

`uji-redesain-v2-fase2-jaringan.mjs` menjalankan alur yang sama pada baseline (`git archive origin/main`, port 8961) dan kode fase (port 8962), dari DB yang dipulihkan dari dump yang sama sebelum tiap perekaman. Yang dibandingkan per langkah: metode, path (ID → `{id}`), **nilai** query, dan badan JSON kanonik; password disamarkan dan kunci idempotensi (acak per percobaan) diganti penanda; token tidak ditulis. Satu-satunya cabang skrip: "Perbarui daftar" dan "Tandai semua dibaca" dibuka dari ⋯ bila tidak tampil langsung.

| Bagian | Alur | Baseline vs fase | Kontrol baseline dua kali |
| --- | --- | --- | --- |
| pengajian (guru) | masuk, Jadwal, rincian tugas, **buka pertemuan**, **perbarui daftar**, **kirim absensi** (saat tersaring), koreksi, laporan, rincian laporan | **10 langkah sama / 0 berbeda, 32 permintaan** | 10 sama / 0 berbeda |
| izin (pengurus, admin, murobi, wali, pembimbing) | **buat** izin ×3, tetapkan murobi ×3, **setujui** dan **tolak**, laporan izin; wali **mengajukan**, pembimbing minta perbaikan, wali **memperbaiki**, pembimbing **meneruskan**; **buka notifikasi (tandai dibaca)**, tandai semua, perangkat | **44 langkah sama / 0 berbeda, 198 permintaan** | 44 sama / 0 berbeda |

Total **54 langkah sama / 0 berbeda, 230 permintaan**. Berkas: `bukti/jaringan/` (rekaman baseline `707b7b7`, kontrol, fase, dan hasil banding).

### Native

| Platform | Build yang benar-benar diuji | Hasil |
| --- | --- | --- |
| Android | **Emulator** `Medium_Phone`, Android 16/API 36, 1080 × 2400 @ 420 dpi. **Dev client Debug baru 1.6.0** (`expo prebuild` + `gradlew --offline assembleDebug` dari salinan tanpa `.env*`; `versionName=1.6.0`) + Metro dari salinan yang sama. Bukan build rilis, bukan perangkat fisik | izin **54/0**, pengajian **13/0** (`bukti/android/`) |
| iOS | **Simulator** iPhone 17 Pro, iOS 26.2 (bilah tab Liquid Glass). **Build Release baru 1.6.0** arm64 (`expo prebuild` + `pod install` + `xcodebuild -configuration Release`, tanda tangan lokal ad-hoc, bundel Hermes tertanam dari kode akhir, API lokal, tanpa alamat produksi). Bukan iOS Debug, bukan arsip distribusi, bukan perangkat fisik | **17 alur Maestro lulus**; ukur FAB dari hierarki aksesibilitas **4/0** (`bukti/ios/`) |

Cakupan native: peran guru, murobi, pengurus, pembimbing, admin, orang tua (V2), dan wali (V7); mode gelap; teks 1,3× dan 2,0× (Android), AX-XL dan AX-XXXL (iOS); papan ketik; ← , tombol kembali Android, geser-tepi iOS; tautan `alhasanapps://notifikasi`, `…/izin/laporan`, dan `…/reports` **saat aplikasi mati** (iOS Release) → ← membuka Beranda; di Android tautan saat aplikasi berjalan.

Koreksi yang ditemukan uji native dan sudah diterapkan sebelum handoff:

1. iOS: jarak kosong ganda di atas kepala layar tab (P2-16).
2. Android 2,0× / iOS AX: label "Terlambat" terpotong di ringkasan mini → kisi 3 + 2 tanpa pemotongan.
3. iOS AX-XL: judul baris entitas terpecah di tengah kata ("pembimbin/g") → avatar/ikon disembunyikan pada teks > 1,35×.
4. iOS AX-XXXL: judul "Perizinan" dan nama bulan `WeekStrip` terpecah di tengah kata → satu baris yang menyusut bila perlu.

Diagnostik alat uji (bukan cacat aplikasi), dicatat terbuka: emulator sempat `offline` di tengah satu putaran dan dinyalakan ulang; tombol mengambang "Tools" dev client Debug menutupi ⋯ sehingga dimatikan lewat menu pengembang sebelum ⋯ diuji (tidak ada di build rilis); beberapa langkah Maestro/adb yang salah sasaran (teks "Jadwal" milik ubin Beranda, ketukan di bawah bilah status, tombol "Buka pertemuan" vs judul bagian bernama sama) diperbaiki pada skrip uji.

## Temuan terbuka

| No | Temuan | Status |
| --- | --- | --- |
| T2-1 | Layar `izin-wali/*` dan rincian izin asal wali dilindungi `expo-screen-capture`, sehingga tangkapan layar native-nya hitam | Perlindungan memang tidak diubah. Bukti native layar itu berupa pernyataan Maestro/uiautomator; geometri FAB wali diukur di Android (56 dp, 16 dp dari kanan, 40 dp dari dasar layar = 16 + area aman) dan web. Di iOS hanya tampil dan dapat diketuknya yang dibuktikan: hierarki aksesibilitas layar itu kosong saat diambil karena isinya ditutup ketika aplikasi tidak aktif. Bukti visual dari Expo web |
| T2-2 | Pada AX-XXXL (iOS) kepala stack dua baris dan bilah tombol bawah menyisakan area isi yang sempit; isi tetap dapat digulir | Konsekuensi teks tanpa batas (aturan gelombang 1); sama dengan layar akun Fase 1 |
| T2-3 | Profil (Fase 1) masih punya jarak atas ganda di iOS (P2-16) | Di luar fase; satu baris perbaikan bila disetujui |
| T2-4 | Bilah tab versi web meluap pada 375 px (T-3 Fase 1) | Sejak baseline; dikecualikan eksplisit dalam pemeriksaan luapan |
| T2-5 | T-1 (kontras teks `statusIzin`/`statusTerlambat`) dan T-2 (logo lime) Fase 1 | Tidak berubah; ikon status di kartu putih lolos ≥ 3:1, labelnya memakai warna teks biasa |
| T2-6 | Enam proses `expo start` milik sesi audit Fase 1 (`/private/tmp/codex-rtv2.*`, port 8972–8975, 8981, 8983) masih berjalan di mesin ini | Tidak disentuh; fase ini memakai port 8961/8962/8964 |

## Mengulang uji

```bash
# Web (dari akar repo web); APP = Expo web salinan TANPA .env* yang API-nya 127.0.0.1:8971
APP=http://127.0.0.1:8962 bash docs/redesain-tampilan-v2/fase-2/perintah-regresi.sh <keluaran>
# Jaringan: baseline (git archive origin/main, port lain) dan fase dari dump yang sama
DUMP=<dump> APP=http://127.0.0.1:8961 bash docs/redesain-tampilan-v2/fase-2/perintah-regresi.sh <keluar-baseline> jaringan-pengajian jaringan-izin
DUMP=<dump> APP=http://127.0.0.1:8962 bash docs/redesain-tampilan-v2/fase-2/perintah-regresi.sh <keluar-fase> jaringan-pengajian jaringan-izin
node tests/browser/uji-redesain-v2-fase2-jaringan.mjs banding <keluar-baseline>/jaringan-izin.json <keluar-fase>/jaringan-izin.json
# Android emulator (dev client Debug + Metro port 8964, adb reverse 8971/8964); DB seperti suite web
METRO=http://127.0.0.1:8964 OUT_DIR=<keluaran> node tests/browser/uji-redesain-v2-fase2-android.mjs izin
METRO=http://127.0.0.1:8964 SEED_JSON=<ids.json> OUT_DIR=<keluaran> node tests/browser/uji-redesain-v2-fase2-android.mjs pengajian
# iOS simulator (build Release terpasang)
UDID=<simulator> bash tests/maestro/redesain-v2-fase2/jalankan-ios.sh <keluaran> izin
UDID=<simulator> MAPEL='Nahwu <sfx>' bash tests/maestro/redesain-v2-fase2/jalankan-ios.sh <keluaran> pengajian
```

## Batas uji

- Perangkat fisik, build distribusi (EAS), iOS Debug, APK rilis, Push nyata (termasuk ketukan notifikasi Push), MariaDB 10.6/MySQL/cPanel/produksi, dan UAT **belum diuji**.
- "Dibuka dari notifikasi tanpa riwayat" dibuktikan dengan memuat rute langsung (Expo web) dan tautan `alhasanapps://` saat aplikasi mati (iOS Release); bukan ketukan Push. Di Android dev client, tautan saat aplikasi mati membuka launcher (batas lama), jadi hanya tautan saat aplikasi berjalan yang diuji.
- Cetak/bagikan PDF dan CSV tidak dijalankan sampai dialog sistem; yang diuji hanya letak tombol dan bahwa permintaannya tidak berubah.
- Suite gelombang 1 (`uji-redesain-fase1/2/3.mjs`), `uji-aplikasi.mjs`, `uji-v6-fase5-app`, dan `uji-tagihan-satu-santri-app` tidak dijalankan. Uji statis PHP web tidak dijalankan: kode web tidak berubah (oracle "berkas tidak berubah" fase lama dapat menandai berkas uji/dokumen baru ini).
- Regresi layar Keamanan/Keuangan/Kesehatan/Pembinaan/Rapor hanya lewat suite yang tercantum; layar-layar itu belum dipindahkan ke pola gelombang 2 (Fase 3–4).
- Seluruh data sintetis lokal (`test_*_test`), API `127.0.0.1`.
