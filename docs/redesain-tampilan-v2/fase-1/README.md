# Redesain tampilan aplikasi gelombang 2 — Fase 1 (fondasi, Masuk, Beranda, Profil/Akun)

Tanggal: 12 November 2026 · Implementator: Claude Code · Auditor berikutnya: Codex

Status: **siap audit Codex**, bukan persetujuan merge, deploy, atau rilis aplikasi. Fase 2 belum dimulai.

Sumber kebenaran: [`../rencana.md`](../rencana.md) (§1–§3, §4.1, §4.2 Beranda dan Profil, §5 K-1…K-10, §6) dan keputusan gelombang 1 di `docs/redesain-tampilan/rencana.md`.

## Branch dan baseline

| Repo | Branch | Dari | Isi |
| --- | --- | --- | --- |
| alhasanApps | `claude/redesain-tampilan-v2-fase-1`, commit `bc99c12` | `origin/main` `77f6240` (PR #29, versi 1.4.0) | Kode tampilan, aset, uji unit; versi **1.4.0 → 1.5.0** |
| WebAlHasan | `claude/redesain-tampilan-v2-fase-1` | `origin/main` `5c88789` (PR #118 sudah digabung, memuat rencana `c741835`) | **Hanya** uji, seed uji, dan dokumen ini. Kode web, API, dan migrasi tidak berubah |

Tanpa worktree; tidak menulis di `main`. `package.json`/`package-lock.json` checkout aplikasi yang berubah milik pekerjaan lain tidak di-commit; direktori web belum terlacak milik pekerjaan lain tidak disentuh.

Dokumentasi Expo SDK 57 yang dibaca sebelum menulis kode: `sdk/router` (`router.canGoBack/back/navigate/replace/dismissTo`), `sdk/router/native-tabs` (`expo-router/unstable-native-tabs`, properti `NativeTabs`), `sdk/router/stack` (`headerShown`, `gestureEnabled`), dan `sdk/image` (`source`, `contentFit`, `accessibilityLabel`, `tintColor`).

## Yang berubah (aplikasi)

Hanya tampilan. Tidak ada perubahan endpoint, payload, hak, aturan bisnis, idempotensi, versi optimistik, alur sesi/password, atau perlindungan `expo-screen-capture`. Tanpa dependensi baru, tanpa modul native baru, tanpa `react-native-svg`.

### Token (K-1) — `src/constants/colors.ts` (baru, murni) dan `theme.ts`

| Token | Sebelum | Sesudah (terang) | Gelap |
| --- | --- | --- | --- |
| `background` | `#F4F7F4` | `#F6F4EE` | tetap `#0D1711` |
| `backgroundElement` | `#E9EFEA` | `#EEEBE2` | tetap |
| `border` / `divider` | `#DFE7E1` / `#EEF3EF` | `#E7E2D6` / `#F1EEE6` | `#2A3B30` → `#2C3A31` / tetap |
| `primary` / `primaryPressed` | `#176B3A` / `#10552D` | `#1B5E3F` / `#144A31` | tetap |
| `heroSurface` / `heroBorder` | `#176B3A` | `#174D36` | `#1B4E31`/`#2F6A45` → `#163F2D`/`#2A5E44` |
| `accentGold` (baru, hiasan saja) | — | `#C9962B` | `#D9B25A` |
| `textMuted` | `#8A9990` | `#66736A` | tetap (lihat penafsiran P-2) |
| `card`, `heroAccent`, status kehadiran, `success`, `warning`, `danger`, `info`, `violet` | — | **tidak berubah** | **tidak berubah** |

Tipe teks baru (§3.2): `stackTitle` 18/700, `numeralLarge` 32/800, `numeralCard` 22/800, `labelSmall` 13/600; `Layout` (tepi layar 20, antar-kartu 12, antar-bagian 24); `Elevation.floating`. Tipe lama tidak diubah agar layar fase berikutnya tidak bergeser.

### Komponen §3.3

| Komponen | Berkas | Dipakai di fase ini |
| --- | --- | --- |
| `ScreenHeader` varian `beranda` / `akar` / `stack` (+ menu ⋯) | `src/components/screen-header.tsx` | Beranda, Profil, Edit profil, Ganti password |
| `BrandLogo` (K-9) | `src/components/brand-logo.tsx` | kepala Beranda |
| `HeroCard` | `ui/hero-card.tsx` | Beranda (sesi/perizinan) |
| `StatCard`, `StatGrid` | `ui/stat-card.tsx` | Beranda (ringkasan K-3) |
| `ProfileHeader` | `ui/profile-header.tsx` | Profil |
| `InfoRow` | `ui/info-row.tsx` | Profil, Edit profil |
| `SuccessPanel` | `ui/success-panel.tsx` | Ganti password berhasil |
| `BottomSheet` | `ui/bottom-sheet.tsx` | menu ⋯ kepala stack, penyaring `SearchBar` |
| `SearchBar` + lembar penyaring, `FilterPills`, `EntityRow`, `AvatarInisial`, `StatusPill`, `SegmentTabs`, `ReceiptCard`, `BarChart`, `Fab` | `ui/search-bar.tsx`, `filter-pills.tsx`, `entity-row.tsx`, `avatar-inisial.tsx`, `status-pill.tsx`, `segment-tabs.tsx`, `receipt-card.tsx`, `bar-chart.tsx`, `fab.tsx` | belum dipakai layar (Fase 2–4); dibuktikan lewat rute pratinjau sementara |
| `AppButton` (pil, 52 pt), `Field` (52 pt, sudut 16), `MenuTile` (sudut 20, ikon lingkaran, lebar seragam), `ActionBar` (sudut atas, tepi 20), `EmptyState`/`LoadingState`/`ErrorState` (ikon dalam lingkaran) | diselaraskan | semua layar |

Ikon garis baru (PNG 24/48/72 px, pola `AppIcon` yang sama): `arrow-left`, `camera`, `mail`, `phone`, `check-circle`.

### Tombol ← (K-10)

`src/lib/kembali.ts` + `src/hooks/use-kembali.ts`: varian `akar` → `router.navigate('/')` (tab Beranda); varian `stack` → `router.back()` bila `router.canGoBack()`, selain itu `router.replace('/')`. Label aksesibilitas "Kembali ke Beranda" / "Kembali", target 44 × 44 pt. Edit profil dan Ganti password kini `headerShown: false` dan menggambar kepala `stack` sendiri; geser-tepi iOS dan tombol kembali Android tidak dimatikan.

### Logo (K-9)

`assets/images/brand/alhasan-apps.png` (80 × 28), `@2x` (159 × 56), `@3x` (239 × 84) diturunkan dengan `sips -z` dari `docs/redesain-tampilan-v2/aset/Logo_alhasanApps.png` (2161 × 761, RGBA) tanpa mengubah warna atau bentuk. Ditampilkan `expo-image` hanya di kepala Beranda, tinggi 28 pt, label "Alhasan apps", tidak ikut skala teks. Layar Masuk tetap memakai `Logo_alhasanApps_potrait*.png`.

### Layar

| Layar | Perubahan tampilan |
| --- | --- |
| Masuk | Latar krem + dua lengkung hijau pucat di dasar; logo sekarang; kolom 52 pt berikon; galat dalam kartu `dangerSoft` di atas tombol pil "Masuk"; teks lupa password tetap |
| Ganti password wajib | Lingkaran gembok besar, label "Ganti password sementara", judul "Buat password baru", "Keluar" tombol garis; tanpa ← |
| Ganti password | Kepala `stack`, lingkaran gembok, keterangan sesi di kartu `infoSoft`, hasil berupa `SuccessPanel` |
| Edit profil | Kepala `stack`; avatar besar berlencana kamera; kelompok Data diri → Kontak → Alamat rumah berikon; isian admin sebagai `InfoRow` terkunci; "Simpan perubahan" tetap di `ActionBar` |
| Beranda | Kepala logo + lonceng (di luar area gulir); kartu identitas tetap isi pertama (lonceng pindah ke kepala, tanggal tetap); `HeroCard`; **Ringkasan hari ini** `StatGrid`; ubin 3 kolom |
| Profil | ← ke Beranda + judul besar; `ProfileHeader` (foto/inisial besar berlencana kamera, nama, @username, pil status, strip angka bila ada data); Data diri sebagai `InfoRow`; kelompok Tampilan dan Tentang dipisah |

Ringkasan Beranda (K-3) hanya dari data yang sudah dimuat: akun berjadwal → "Sesi hari ini" dan "Sesi belum selesai diabsen" dari respons `/schedules/today` yang sama; akun lain → "Penugasan aktif" dari `/profile/pribadi` yang sudah dimuat kartu identitas dan "Notifikasi belum dibaca" dari hitungan milik lonceng. Tidak ada permintaan baru (lihat bukti jaringan).

Layar lain belum diubah tampilannya. Yang ikut berubah karena komponen bersama: warna token, tombol (pil 52 pt), kolom isian (52 pt), ubin, bilah aksi, dan keadaan kosong/memuat/galat. `ScreenHeader` tanpa `variant` mempertahankan kepala lama sehingga Jadwal, Perizinan, dan Laporan tidak berubah susunannya.

## Penafsiran (mohon ditinjau auditor / Human Developer)

| No | Penafsiran | Alasan |
| --- | --- | --- |
| P-1 | Judul Masuk "Masuk ke akun pesantren Anda", **tanpa** kata "Assalamu'alaikum" yang disebut §4.1 | Sepuluh suite lama memakai teks "Assalamu" sebagai tanda Beranda sudah termuat sesudah masuk (`uji-redesain-fase1/2/3`, `uji-aplikasi`, dst.). Menaruhnya di layar Masuk membuat tanda itu terpenuhi sebelum login selesai. Oracle tidak dilonggarkan; sapaan tetap di kartu identitas Beranda |
| P-2 | `textMuted` terang digelapkan `#8A9990` → `#66736A` | Di latar krem nilai lama hanya 2,71:1 (di latar lama 2,76:1). Nilai baru 4,52:1 di latar, 4,97:1 di kartu, 4,17:1 di `backgroundElement` (placeholder/ikon, batas 3:1). Bukan token yang dikunci K-1 |
| P-3 | Kelompok Profil tetap "Akun & pemberitahuan" (tidak dipecah menjadi Akun dan Pemberitahuan) dan "Data diri" tetap tampil langsung di Profil (bukan baris › ke rincian) | Suite `uji-profil-impor-f2-app` dan `uji-redesain-fase3` menunggu teks "Akun & pemberitahuan" dan memeriksa nilai data diri langsung di Profil sesudah Edit profil. Oracle tidak dilonggarkan |
| P-4 | Edit profil: lencana kamera membuka pemilih foto; tombol "Pilih/Ganti foto" dan "Hapus foto" tetap langsung, **tanpa lembar bawah** | Suite profil menekan tombol itu dan langsung menunggu pemilih berkas/dialog konfirmasi. Alur foto tidak berubah |
| P-5 | "Jadwal hari ini" di Beranda tetap memakai `ScheduleCard` (jam di kiri), belum `EntityRow` | `ScheduleCard` dipakai bersama layar Jadwal (Fase 2); mengubahnya sekarang mengubah layar di luar fase |
| P-6 | Kepala Beranda berada di luar area gulir (tetap di atas) | Supaya kartu identitas tetap anak pertama isi Beranda ≤ 16 px dari atas area gulir (oracle B-A suite profil) dan lonceng selalu terjangkau |
| P-7 | Berkas baru di luar `src/components`/`src/constants`: `src/lib/kembali.ts`, `src/lib/tampilan.ts`, `src/hooks/use-kembali.ts`, `tests/redesain-v2-*.test.ts`, `app.json` (versi) | Pembantu murni agar dapat diuji di Node; tidak memuat logika bisnis |
| P-8 | Tab Jadwal dan Izin **belum** diberi ← | Keduanya layar Fase 2 (§6); varian `akar` sudah tersedia dan dipakai Profil |
| P-9 | Subjudul Profil "Identitas, penugasan, dan pengaturan akun" | Kata "Data diri" di subjudul membuat penanda tunggu suite profil terpenuhi sebelum data dimuat (ditemukan saat regresi, 104/1 → dikoreksi tanpa mengubah oracle) |

## Bukti

Lingkungan: macOS, Node 26.7, PHP 8.4.14, MariaDB 12.3.2 lokal, fixture sintetis (`tests/profil_impor_f1_db.sh` + `profil_impor_f2_app_seed.php` + seed baru `tests/redesain_v2_fase1_seed.php` untuk akun Keamanan). Expo dijalankan dari **salinan tanpa `.env*`** (paket = HEAD, `node_modules` nyata dari lock ter-commit, Expo 57.0.15), `EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8971/api/v1`; bundel web diperiksa tanpa `alhasan.co.id`; semua skrip Playwright memblokir host selain `127.0.0.1`. Berkas di [`bukti/`](bukti/).

| Uji | Hasil |
| --- | --- |
| `npx tsc --noEmit` · `expo lint` (salinan bersih) | exit 0 · exit 0 (`bukti/aplikasi/`) |
| Unit `node --test tests/*.test.ts` | **90/0** (baseline `77f6240`: 80/0). Baru: `redesain-v2-kontras.test.ts` (5), `redesain-v2-fase1.test.ts` (5) |
| Aturan versi `versi:periksa --kerja` | LULUS 1.4.0 → 1.5.0 (minor, satu kali) |
| Expo web baru `tests/browser/uji-redesain-v2-fase1.mjs` (A–I, 375/768/1280, terang/gelap, tujuh peran, pratinjau komponen) | **175/0** (`bukti/web/expo-web-fase1.txt`, 42 tangkapan) |
| Regresi `uji-profil-impor-f2-app.mjs` (Beranda, Profil, Edit profil, foto, Ganti password, password sementara) | **105/0**; kontrol baseline `77f6240` **105/0** |
| Log jaringan `uji-redesain-v2-fase1-jaringan.mjs` (masuk, Beranda, Profil, Edit profil buka+simpan, Ganti password buka+simpan, kembali ke Beranda; tujuh akun) | baseline vs sesudah: **23 langkah sama / 0 berbeda, 143 permintaan**; kontrol baseline dua kali juga 23/0 (`bukti/jaringan/`) |
| Regresi Expo web lain (`perintah-regresi.sh`), dijalankan ulang pada kode akhir | Keamanan F4 **46/0**, kelas gabungan F2 **57/0**, pencarian absensi **53/0**, pencarian daftar **24/0**, peserta absensi **18/0**, V6 F4 **45/0**, V7 F4 **48/0**, V5 F4 **78/0**, V5 F5 **36/0**; galat PHP server API 0 (`bukti/regresi/`) |
| Android emulator (`uji-redesain-v2-fase1-android.mjs`) | lihat Native |
| iOS simulator (Maestro, `tests/maestro/redesain-v2-fase1/`) | lihat Native |

Tidak ada kegagalan pada suite yang dijalankan, sehingga tidak ada kontrol baseline tambahan selain yang tercantum. Suite gelombang 1 (`uji-redesain-fase1/2/3.mjs`) memerlukan DB bernama tetap `webalhasan_v3_phase1_test` (migrasi 019) dan port 8940/8082; **tidak dijalankan** pada fase ini (lihat Batas uji). `uji-v6-fase5-app` dan `uji-tagihan-satu-santri-app` juga tidak dijalankan.

### Kontras (K-1)

`tests/redesain-v2-kontras.test.ts` (repo aplikasi) menghitung kontras WCAG setiap pasangan teks/latar yang berubah, mode terang dan gelap, dan mengunci nilai warna status. Nilai terang yang penting:

| Pasangan | Kontras | Batas |
| --- | --- | --- |
| `text` di `background` krem | 14,76 | 4,5 |
| `textSecondary` di `background` / `backgroundElement` | 5,12 / 4,72 | 4,5 |
| `textMuted` baru di `background` / `card` / `backgroundElement` | 4,52 / 4,97 / 4,17 | 4,5 / 4,5 / 3 (placeholder, ikon) |
| `primary` di `background` / `primarySoft` / `backgroundElement` | 7,02 / 6,74 / 6,48 | 4,5 |
| `onPrimary` di `primary` | 7,72 | 4,5 |
| `heroText` / `heroTextSecondary` / `heroAccent` di `heroSurface` | 9,76 / 7,30 / 6,33 | 4,5 |
| `warning` / `danger` / `info` / `violet` di `background` | 5,36 / 5,98 / 5,38 / 6,53 | 4,5 |
| `statusHadir` / `statusSakit` / `statusAlpa` di `background` | 5,97 / 5,39 / 5,98 | 3 (ikon + teks tebal) |
| `statusIzin` / `statusTerlambat` di `background` | **3,87 / 3,39** | 3 — di bawah 4,5; warna status tidak diubah (K-1). Di latar lama 3,94 / 3,45, jadi sudah di bawah 4,5 sebelum fase ini. Dicatat sebagai temuan T-1 |
| `accentGold` di `background` | 2,42 | hiasan saja; uji memastikan tidak dipakai sebagai warna teks |

Mode gelap: seluruh pasangan yang sama ≥ batasnya (mis. `heroText` di `heroSurface` baru 10,87; `textMuted` di `backgroundElement` 4,61).

### Native

| Platform | Build yang benar-benar diuji | Hasil |
| --- | --- | --- |
| Android | **Emulator** `Medium_Phone`, Android 16. **Dev client Debug baru** (`expo prebuild` + `gradlew assembleDebug` dari salinan tanpa `.env*`, versi 1.5.0) + Metro dari salinan kerja. Bukan build rilis, bukan perangkat fisik | Putaran penuh pada kode akhir **70 lulus / 1 gagal**: yang gagal adalah langkah gulir skrip uji ("isian terkunci admin tampil" dibaca sebelum barisnya tergulir ke layar); bagian B diulang sesudah skrip diperkuat **9/0**. Cakupan: tujuh peran (35), ← Edit profil/Ganti password + tombol kembali Android + tautan saat aplikasi berjalan (9), gelap, teks 1,3×, 2,0×, gelap + 2,0× (24), Masuk/Ganti password wajib (3) (`bukti/android/`) |
| iOS | **Simulator** iPhone 17 Pro, iOS 26.2, Xcode 27.2. **Build Release baru** arm64 (`expo prebuild` + `pod install` + `xcodebuild -configuration Release`, tanda tangan lokal ad-hoc, bundel Hermes tertanam, versi 1.5.0, API lokal, tanpa alamat produksi). Bukan iOS Debug, bukan arsip distribusi, bukan perangkat fisik | **13/13 alur Maestro lulus**: tujuh peran (Beranda logo + lonceng tanpa ←, Profil ber-←, ← ke Beranda), `kembali` (Edit profil ←, geser-tepi iOS, Ganti password ←, tautan `alhasanapps://akun/ganti-password` dan `…/edit-profil` **saat aplikasi mati** → ← membuka Beranda), `wajib`, dan tampilan gelap, AX-XL, AX-XXXL, gelap + AX-XXXL (`bukti/ios/`, 41 tangkapan) |

Build iOS Release baru terbuka tanpa crash startup. Tombol kembali Android dan geser-tepi iOS tetap bekerja pada layar yang kini `headerShown: false`.

Koreksi yang ditemukan uji native dan sudah diterapkan sebelum handoff:

1. Kepala `stack` Edit profil/Ganti password ikut tergulir sehingga ← hilang saat digulir → kepala dipindah ke luar area gulir.
2. iOS AX-XL/AX-XXXL: inisial avatar meluber dari lingkaran (ukuran avatar dan huruf sama-sama diskalakan) → inisial `maxFontSizeMultiplier={1}`; ukuran avatar tetap mengikuti skala teks.
3. iOS AX-XXXL: judul Masuk ("pesantre/n") dan judul kepala `stack` ("passwo/rd") terpecah di tengah kata → pada skala > 1,35 judul Masuk/Ganti password wajib memakai tipe lebih kecil dan judul `stack` turun ke baris sendiri rata kiri.

Batas yang diterima dari gelombang 1 dan masih terlihat: pada AX-XXXL sapaan "Assalamu'alaikum," dan kata panjang nama ("Administrator") di kartu identitas terpecah; label ubin satu kata terpecah di atas AX-Medium/1,3× (keputusan gelombang 1 nomor 5).

### Logo di mode terang (K-9)

Bagian lime logo di atas latar krem terbaca tetapi kontrasnya rendah pada huruf "Al" (lihat `bukti/web/tangkapan/beranda-*-375-terang.jpg` dan `bukti/ios/tangkapan/ios-beranda-sbx_admin.jpg`). Warna logo **tidak diubah**; bila Human Developer menghendaki, pilihan yang tersedia adalah berkas logo khusus mode terang atau bidang putih di belakang logo (temuan T-2, perlu keputusan).

## Temuan terbuka

| No | Temuan | Status |
| --- | --- | --- |
| T-1 | `statusIzin` dan `statusTerlambat` sebagai teks di latar krem 3,87:1 dan 3,39:1 (< 4,5:1); sudah < 4,5:1 di latar lama | Warna status dikunci K-1; perlu keputusan bila ingin dinaikkan |
| T-2 | Keterbacaan bagian lime logo "Alhasan apps" di mode terang | Warna logo tidak diubah tanpa persetujuan (K-9) |
| T-3 | Bilah tab versi web meluap pada 375 px dengan empat tab (TF4-1, sejak baseline) | Di luar fase; dikecualikan eksplisit dalam pemeriksaan luapan |

## Mengulang uji

```bash
# Web (dari akar repo web); APP = Expo web salinan TANPA .env* yang API-nya 127.0.0.1:8971
APP=http://127.0.0.1:8973 bash docs/redesain-tampilan-v2/fase-1/perintah-regresi.sh <keluaran>
node tests/browser/uji-redesain-v2-fase1-jaringan.mjs banding docs/redesain-tampilan-v2/fase-1/bukti/jaringan/baseline-77f6240.json <keluaran>/jaringan.json
# Pratinjau komponen: salin pratinjau/pratinjau-redesain-v2.tsx ke src/app/ SALINAN uji (bagian H dilewati bila tidak ada)
# Android emulator (dev client Debug + Metro port 8974, adb reverse 8971/8974)
METRO=http://127.0.0.1:8974 OUT_DIR=<keluaran> node tests/browser/uji-redesain-v2-fase1-android.mjs
# iOS simulator (build Release terpasang)
UDID=<simulator> bash tests/maestro/redesain-v2-fase1/jalankan-ios.sh <keluaran>
```

## Batas uji

- Perangkat fisik, build distribusi (EAS), iOS Debug, Push nyata, MariaDB 10.6/MySQL/cPanel/produksi, dan UAT **belum diuji**.
- Uji tanpa riwayat (dibuka langsung) pada Android memakai tautan saat aplikasi berjalan; cold start dari tautan pada dev client membuka launcher (batas lama). Tanpa riwayat dibuktikan di Expo web (muat langsung) dan iOS Release (tautan saat aplikasi mati).
- Suite gelombang 1 `uji-redesain-fase1/2/3.mjs`, `uji-v6-fase5-app`, dan `uji-tagihan-satu-santri-app` tidak dijalankan.
- Uji statis PHP web tidak dijalankan: kode web tidak berubah (hanya berkas uji/dokumen baru; oracle "berkas tidak berubah" fase lama dapat menandai berkas baru ini).
- Cetak/bagikan, unggah foto native (pemilih sistem), dan Push tidak disentuh dan tidak diuji ulang di native.
- Rute pratinjau komponen sementara tidak di-commit ke repo aplikasi; salinannya ada di `pratinjau/pratinjau-redesain-v2.tsx`.
- Seluruh data sintetis lokal (`test_*_test`), API `127.0.0.1`.
