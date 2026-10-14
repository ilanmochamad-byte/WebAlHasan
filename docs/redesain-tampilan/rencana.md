# Redesain tampilan aplikasi alhasanApps — 14 Oktober 2026

Status: **Fase 1 diimplementasikan, menunggu audit Codex** (lihat "Status Fase 1"). Fase 2–3 belum dimulai. Dokumen ini adalah sumber kebenaran workstream redesain tampilan. Implementator: Claude. Auditor: Codex (lihat `AGENTS.md`).

Branch kedua repositori: `redesain-tampilan`, dibuat dari `codex/perbaikan-form-pembinaan` (website `059ad37`, aplikasi `8313a41`) agar perbaikan formulir pembinaan yang belum digabung ke `main` ikut terbawa.

## Tujuan

Aplikasi mudah digunakan, menu mudah dilihat, tidak membingungkan, tetapi tetap modern. Redesain ini **hanya mengubah tampilan dan navigasi**. Tidak ada perubahan endpoint, aturan akses, atau logika bisnis. Menyembunyikan menu tetap bukan kontrol akses; server tetap memeriksa cakupan.

## Keputusan Human Developer

Tiga konsep diajukan: A (menu ubin besar), B (agenda hari ini), dan C (daftar menu berpenjelasan). Keputusan:

1. Beranda memakai **Konsep A, menu ubin besar**, dengan ubin **tetap 3 kolom** berapa pun jumlah ubin yang tampil.
2. Layar lain memakai **gabungan A, B, dan C** seperti pada mockup final.
3. Bilah bawah **4 tab**: Beranda, Jadwal, Izin, Profil. Laporan keluar dari tab dan dibuka dari ubin Beranda.
4. **Tanpa tombol + mengambang** (Konsep B tidak dipakai untuk bagian ini).

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

- **Android belum diuji.** Dev client di emulator `Medium_Phone` versi lama dan gagal sejak awal (`Cannot find native module 'ExpoPushTokenManager'`), tidak terkait Fase 1. Perlu `npx expo run:android` (unduh dependensi Gradle) sebelum uji Android.
- Pada mode pengembangan, Expo memuat `.env.local` (alamat produksi) di runtime meskipun `EXPO_NO_DOTENV=1`. Untuk uji lokal dipakai `.env.development.local` sementara (diabaikan git, sudah dihapus). Satu percobaan login dengan akun sandbox sempat terkirim ke API produksi sebelum hal ini diketahui, dan ditolak server. `CI=1` juga mematikan pemantauan berkas Metro, jadi Metro harus dimulai ulang setelah setiap perubahan.
- Kartu sorot (tidak diubah) tetap sempit pada teks aksesibilitas terbesar bila judul jadwal panjang. Bilah tab versi web adalah lapisan absolut di atas konten (sudah ada sebelumnya) dan menutupi sapaan pada lebar ponsel.
- Lencana jumlah antrean pada ubin Perizinan tidak dibuat karena Beranda belum memuat data perizinan (sesuai rencana: hanya bila datanya tersedia tanpa permintaan tambahan).

## Di luar cakupan

Perubahan API/server, layar login, detail sesi/absensi, formulir pembinaan, dan halaman detail izin kecuali penyesuaian gaya kecil agar konsisten.
