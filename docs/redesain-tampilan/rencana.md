# Redesain tampilan aplikasi alhasanApps — 14 Oktober 2026

Status: **Fase 1 — koreksi Claude atas temuan audit 2–4 selesai, menunggu audit ulang Codex** (15 Oktober 2026). Lihat `audit-fase-1.md` dan bagian "Koreksi Claude atas audit Fase 1". Fase 2–3 belum dimulai. Dokumen ini adalah sumber kebenaran workstream redesain tampilan. Implementator: Claude. Auditor: Codex (lihat `AGENTS.md`).

Branch kedua repositori: `redesain-tampilan`, dibuat dari `codex/perbaikan-form-pembinaan` (website `059ad37`, aplikasi `8313a41`) agar perbaikan formulir pembinaan yang belum digabung ke `main` ikut terbawa.

## Tujuan

Aplikasi mudah digunakan, menu mudah dilihat, tidak membingungkan, tetapi tetap modern. Redesain ini **hanya mengubah tampilan dan navigasi**. Tidak ada perubahan endpoint, aturan akses, atau logika bisnis. Menyembunyikan menu tetap bukan kontrol akses; server tetap memeriksa cakupan.

## Keputusan Human Developer

Tiga konsep diajukan: A (menu ubin besar), B (agenda hari ini), dan C (daftar menu berpenjelasan). Keputusan:

1. Beranda memakai **Konsep A, menu ubin besar**, dengan ubin **tetap 3 kolom** berapa pun jumlah ubin yang tampil.
2. Layar lain memakai **gabungan A, B, dan C** seperti pada mockup final.
3. Bilah bawah **4 tab**: Beranda, Jadwal, Izin, Profil. Laporan keluar dari tab dan dibuka dari ubin Beranda.
4. **Tanpa tombol + mengambang** (Konsep B tidak dipakai untuk bagian ini).
5. (15 Oktober 2026, atas temuan audit 3) Label ubin **mengikuti ukuran teks sistem sepenuhnya**, tanpa batas skala atau pengecilan. Label boleh dua baris atau lebih dan ubin memanjang, tetapi kisi **tetap 3 kolom**. Bila satu kata tetap tidak muat pada ukuran ekstrem, hal itu dicatat sebagai batas yang diterima, bukan cacat. Dikonfirmasi ulang 15 Oktober 2026 setelah melihat bukti: kisi **tetap 3 kolom** di semua ukuran teks; label satu kata yang terpecah di atas AX-Medium (iOS) / 1,3× (Android) **diterima** Human Developer. Alternatif dua kolom ditolak.

## Mockup final

Salinan statis ada di `docs/redesain-tampilan/mockup/` (buka `beranda.html` di browser; tautan bilah bawah berpindah antarlayar). Ukuran bingkai 390×844 pt. Angka seperti `[24]` dan nama santri/ustadz adalah contoh, bukan data.

| Layar | Isi | Asal |
| --- | --- | --- |
| Beranda | Sapaan + tanggal, lonceng notifikasi, kartu sorot sesi dengan satu tombol besar, kisi ubin 3 kolom | A |
| Jadwal | Navigasi pekan + deretan 7 tanggal, label hari, kartu sesi dengan jam di kiri, status ikon+teks, tombol **Absen** pada sesi yang belum diabsen | B + C |
| Perizinan | Subjudul penjelas, pencarian santri, filter pil (Perlu tindakan / Selesai / Semua), kartu pengajuan dengan status ikon+teks, tombol tetap **Ajukan izin baru** | A + B + C |
| Laporan | Tombol kembali ke Beranda, filter pil (rentang tanggal, status, jadwal), ringkasan + batang rekap status + legenda, Cetak PDF / Bagikan, ringkasan per jadwal | B + C |
| Profil | Kartu identitas hijau + label peran, kelompok baris berketerangan (akun & pemberitahuan, tampilan), tombol Keluar | C |

## Aturan desain

- Warna inti tetap dari `src/constants/theme.ts` (hijau `#176B3A`, warna status kehadiran yang sudah lolos uji buta warna). Mode gelap memakai token gelap yang ada; mockup hanya menggambar mode terang.
- Huruf: Plus Jakarta Sans (500/600/700/800) dimuat dengan `expo-font` yang sudah terpasang, dengan cadangan huruf sistem. Ukuran teks 13–28 pt dan wajib ikut pengaturan ukuran teks sistem.
- Target sentuh minimal 44 pt. Status tidak boleh hanya dibedakan warna; selalu ada ikon dan teks.
- Ikon garis 24 px dengan tiga kerapatan PNG (lihat catatan di `app-tabs.tsx`) atau sumber ikon yang sudah dipakai `AppIcon`.
- Bilah tab tetap digambar sistem (Liquid Glass di iOS 26, Material 3 di Android); hanya ikon, label, warna, dan tab yang tampil yang diatur.

## Ubin Beranda menurut kemampuan

Urutan tetap; ubin yang tidak berhak tidak ditampilkan, sisanya mengisi kisi 3 kolom dari kiri.

| Ubin | Syarat tampil | Tujuan |
| --- | --- | --- |
| Jadwal | `aksesJadwal` (role guru/admin) | tab Jadwal |
| Absensi | `aksesJadwal` | sesi hari ini yang pertama belum selesai; jika tidak ada, tab Jadwal |
| Perizinan | `capabilities.list.length > 0` | tab Izin; lencana jumlah antrean bila datanya sudah tersedia tanpa permintaan tambahan |
| Laporan | `aksesJadwal` | layar Laporan (stack, dengan tombol kembali) |
| Cetak Izin | `capabilities.list.length > 0` | `/izin/laporan` dengan `mode` default seperti sekarang |
| Pembinaan | kemampuan pembinaan tidak kosong (logika `PembinaanEntry`) | `/pembinaan` |

Akun tanpa ubin sama sekali tetap melihat sapaan dan keterangan bahwa akun tidak memiliki menu.

## Fase implementasi

Implementator mengerjakan satu fase sesuai perintah Human Developer, lalu berhenti untuk audit Codex.

### Fase 1 — Fondasi, navigasi, dan Beranda

- Memuat Plus Jakarta Sans dan menambah skala tipografi di `theme.ts` / `themed-text.tsx`.
- Komponen baru: ubin menu, baris menu berketerangan, deretan tanggal pekan, pil filter (boleh memperluas `chip.tsx`).
- `app-tabs.tsx` dan `app-tabs.web.tsx`: 4 tab dengan aturan `hidden` yang sama seperti sekarang. Laporan dipindah dari grup tab menjadi layar stack; rute `/reports` tetap bisa dibuka.
- Beranda sesuai mockup; tombol `PembinaanEntry` digantikan ubin Pembinaan.

Kriteria penerimaan: `npx tsc --noEmit`, `npm run lint`, dan unit test yang ada lulus; Beranda untuk akun guru, pengurus (hanya perizinan), dan orang tua menampilkan ubin yang benar; Laporan dapat dibuka dan kembali ke Beranda; mode gelap dan ukuran teks besar tidak memotong label; diperiksa di simulator iOS dan emulator Android.

### Fase 2 — Jadwal dan Perizinan

- Jadwal: deretan tanggal pekan menggantikan chip 7/30 hari sebagai tampilan utama; rentang kustom tetap tersedia.
- Perizinan: pencarian, filter pil, kartu sesuai mockup, tombol tetap Ajukan izin baru.
- Tombol Setujui/Tolak langsung di kartu **hanya** bila alur server untuk pengajuan itu tidak memerlukan isian tambahan dan pengguna berhak; selain itu kartu menampilkan "Tinjau" dan membuka detail. Implementator wajib memeriksa alur di `izin/[id].tsx` sebelum memasang tombol ini.

Kriteria penerimaan: seperti Fase 1, ditambah uji paginasi, mode cakupan ganda, dan alur setuju/tolak pada API lokal.

### Fase 3 — Laporan, Profil, dan uji menyeluruh

- Laporan dan Profil sesuai mockup, tanpa mengubah fungsi cetak/bagikan dan logout.
- Uji menyeluruh semua peran, mode gelap, ukuran teks besar, web, iOS, dan Android; termasuk regresi harness `tests/browser/uji-form-pembinaan.mjs` dan `uji-v3-fase5-mobile.mjs`.

## Status Fase 1 — implementasi Claude, 14 Oktober 2026

Status: **siap audit Codex**, bukan persetujuan merge. Commit aplikasi `0ba6e9e` di branch `redesain-tampilan` (baseline `8313a41`). Website hanya berubah dokumen ini.

Perubahan:

- Paket baru `@expo-google-fonts/plus-jakarta-sans@0.4.2` (izin unduh dari Human Developer). Empat ketebalan dimuat dengan `useFonts` di `src/app/_layout.tsx`; splash menunggu huruf. `ThemedText` dan `KeyboardAwareTextInput` memetakan `fontWeight` ke keluarga huruf (`brandFontFamily` di `theme.ts`) dan memakai huruf sistem bila berkas gagal dimuat. Tidak perlu build native ulang.
- `app-tabs.tsx` / `app-tabs.web.tsx`: 4 tab dengan aturan `hidden` yang sama; label tab 12 pt. `(app)/(reports)` dihapus; layar dipindah ke `src/app/reports.tsx` (rute tetap `/reports`) dengan tombol kembali (`ScreenHeader onBack`).
- Beranda: komponen baru `ui/menu-tile.tsx` (kisi 3 kolom tetap, pengganjal untuk baris tidak penuh). `PembinaanEntry` menjadi hook `usePembinaanAkses`; tombol muat ulang tetap muncul bila pemeriksaan gagal. `BrandHeader`/`Greeting` dihapus karena tidak dipakai lagi. Token warna baru `info`, `violet` (+ versi lunak) untuk terang dan gelap.
- Penyesuaian di luar mockup: tanggal di kanan judul "Jadwal hari ini" dihapus (sudah ada di sapaan; dengan teks besar judul terpecah per suku kata). Label ubin satu baris, `maxFontSizeMultiplier` 1,4 dan menyusut sampai 0,7; sapaan dibatasi 1,4×.

Validasi:

- `npx tsc --noEmit`, `npm run lint`: lulus. Unit `tests/date-time.test.ts` + `tests/print-dialog.test.ts`: 8 lulus, 0 gagal.
- Simulator iOS (iPhone 17 Pro, iOS 26), dev client + API lokal `DB_NAME=webalhasan_v3_phase1_test` di 127.0.0.1:8940: login `sbx_guru_biasa` → Beranda 3 ubin (Jadwal, Absensi, Laporan), 3 tab; ubin Laporan membuka `/reports` tanpa bilah tab, tombol kembali ke Beranda. Ukuran teks `accessibility-large`: label ubin, sapaan, dan judul bagian utuh setelah koreksi.
- Expo web 390 pt + Playwright, API lokal nyata lewat proxy CORS: guru (Jadwal, Absensi, Laporan), pengurus dan orang tua (Perizinan, Cetak Izin, Pembinaan + kartu perizinan + keterangan tanpa jadwal), murobi dan admin (6 ubin). Laporan buka/kembali lulus untuk peran berjadwal. Admin diuji dalam mode gelap. Tidak ada `pageerror`.

Keterbatasan dan catatan untuk auditor:

- Android: dev client lama di emulator gagal sejak awal (`Cannot find native module 'ExpoPushTokenManager'`, tidak terkait Fase 1). Atas izin Human Developer dibangun ulang dengan `ANDROID_HOME=~/Library/Android/sdk npx expo run:android --no-bundler --device Medium_Phone` (tanpa perubahan berkas proyek; tanpa `ANDROID_HOME` Gradle gagal "SDK location not found"). Hasil di emulator `Medium_Phone` (Android 16, `adb reverse` 8082/8940): huruf merek tampil tanpa penebalan tiruan; guru → 3 ubin, 3 tab; ubin Laporan → `/reports`, tombol kembali di layar dan tombol kembali Android sama-sama kembali ke Beranda; admin dalam mode gelap + `font_scale` 1,3 → 6 ubin utuh, 4 tab; ubin Pembinaan membuka menu pembinaan; tab Izin membuka Perizinan. Tidak ada error di log Metro. Pengaturan emulator dikembalikan.
- Pada mode pengembangan, Expo memuat `.env.local` (alamat produksi) di runtime meskipun `EXPO_NO_DOTENV=1`. Untuk uji lokal dipakai `.env.development.local` sementara (diabaikan git, sudah dihapus). Satu percobaan login dengan akun sandbox sempat terkirim ke API produksi sebelum hal ini diketahui, dan ditolak server. `CI=1` juga mematikan pemantauan berkas Metro, jadi Metro harus dimulai ulang setelah setiap perubahan.
- Kartu sorot (tidak diubah) tetap sempit pada teks aksesibilitas terbesar bila judul jadwal panjang. Bilah tab versi web adalah lapisan absolut di atas konten (sudah ada sebelumnya) dan menutupi sapaan pada lebar ponsel.
- Lencana jumlah antrean pada ubin Perizinan tidak dibuat karena Beranda belum memuat data perizinan (sesuai rencana: hanya bila datanya tersedia tanpa permintaan tambahan).

## Koreksi Claude atas audit Fase 1 — 15 Oktober 2026

Commit aplikasi `d49da77` (di atas koreksi Codex `e257a1f`). Bukti visual ada di `docs/redesain-tampilan/bukti-fase1/`, dan uji web dapat diulang dengan `tests/browser/uji-redesain-fase1.mjs`.

**Temuan 2 — fondasi komponen.**
- `src/components/ui/week-strip.tsx`: deretan tanggal pekan Senin–Minggu dengan judul bulan, tombol pekan sebelumnya/berikutnya, dan titik penanda hari ini. Label aksesibilitas berisi tanggal lengkap ditambah "hari ini". Bila skala teks > 1,35, sel melebar sesuai isi dan deretan dapat digeser ke samping. Logika ada di `src/lib/week.ts`.
- `ListRow` mendapat `subtitle`: keterangan di bawah judul dengan ikon dalam kotak lunak. Baris tanpa `subtitle` (Profil) tidak berubah.
- Pil filter memakai `Chip` yang sudah ada, sesuai catatan auditor.
- Komponen ini belum dipakai layar mana pun; pemakaiannya masuk Fase 2–3. Bukti visual diambil dari rute pratinjau sementara yang tidak di-commit.

**Temuan 3 — ukuran teks.** Keputusan Human Developer nomor 5:
- Ubin: `numberOfLines`, `maxFontSizeMultiplier`, dan `minimumFontScale` dihapus.
- Sapaan: batas 1,4× dihapus. Lonceng dipindah ke baris tanggal supaya sapaan mendapat lebar penuh.
- Penyesuaian tambahan yang ditemukan saat uji:
  - Kartu sorot menumpuk jam di atas judul bila skala teks > 1,35.
  - Teks tombol kartu sorot boleh membungkus, karena sebelumnya terpotong pada AX-XL.

**Temuan 4 — akun tanpa menu.** `usePembinaanAkses` kini mengembalikan `status` (memuat/siap/gagal), dan `keadaanMenuBeranda` (`src/lib/home-menu.ts`) menentukan tampilan:
- Selagi akses pembinaan dimuat: "Memeriksa menu akun…".
- Bila pemeriksaan gagal: hanya tombol muat ulang.
- Setelah selesai dan tidak ada menu: "Akun ini belum memiliki menu" beserta saran menghubungi admin.
- Keterangan "tidak memiliki jadwal mengajar" hanya muncul bila akun masih punya menu lain.

Validasi (Claude):

- `npx tsc --noEmit`, `npm run lint`, dan `git diff --check`: lulus. `node --experimental-strip-types --test tests/*.test.ts` (TZ Asia/Jakarta): **14 lulus, 0 gagal**. Uji baru `tests/redesain-fase1.test.ts` juga lulus pada TZ America/Los_Angeles.
- Web (Playwright 390 pt, API lokal `webalhasan_v3_phase1_test` lewat proxy CORS, respons kemampuan dikendalikan hanya untuk skenario tanpa menu/memuat/gagal):
  - Tanpa menu, saat memuat, dan saat gagal: tampilan benar, tanpa `pageerror`.
  - Regresi orang tua (`sbx_ortu_a`): lulus.
  - Deretan tanggal (pilih tanggal, pekan maju/mundur, label hari ini) dan label aksesibilitas baris berketerangan: lulus pada rute pratinjau sementara.
  - Setelah rute pratinjau dihapus, skrip dijalankan ulang terhadap kode final: 6 lulus, bagian fondasi dilewati.
- iOS (iPhone 17 Pro, `sbx_guru_biasa`):
  - Ukuran normal: sesuai mockup.
  - AX-Medium: sapaan dan label ubin utuh.
  - AX-XL: kartu sorot bertumpuk dan teks tombol membungkus dengan benar; label ubin terpecah di tengah kata ("Jadw/al").
  - AX-XXXL (maksimum): sapaan terpecah di apostrof; deretan tanggal dapat digeser dan utuh; label ubin terpecah.
- Android (emulator `Medium_Phone`, `sbx_admin`, enam ubin):
  - `font_scale` 1,0 dan 1,3: semua label utuh.
  - 2,0 (maksimum): "Cetak Izin" membungkus di batas kata; "Perizinan" dan "Pembinaan" terpecah di tengah kata.
  - Catatan: emulator memulai dari snapshot lama, sehingga APK hasil build sebelumnya dipasang ulang dengan `adb install -r` (tanpa build ulang).

Batas yang diterima dan sisa catatan:

- Label ubin satu kata utuh sampai AX-Medium (iOS) dan 1,3× (Android). Di atasnya, kata tunggal yang lebih lebar dari sepertiga layar terpecah di tengah kata. Ini konsekuensi tiga kolom tetap (keputusan nomor 5). Human Developer menerima batas ini dan menolak alternatif dua kolom (keputusan nomor 5).
- `EmptyState` (komponen lama, di luar Fase 1) juga memecah kata panjang pada AX-XXXL.
- Bilah tab versi web yang menutupi sapaan pada lebar ponsel belum diubah (sudah ada sebelum Fase 1).
- Data uji memuat judul jadwal panjang tanpa spasi (`ed78749f`), sehingga kartu sorot tampak lebih padat daripada data nyata.

## Di luar cakupan

Perubahan API/server, layar login, detail sesi/absensi, formulir pembinaan, dan halaman detail izin kecuali penyesuaian gaya kecil agar konsisten.
