# Redesain tampilan aplikasi gelombang 2 — Fase 3 (Keuangan santri/BSA)

Tanggal: 12–13 November 2026 · Implementator: Claude Code · Auditor berikutnya: Codex

Status: **siap audit Codex**, bukan persetujuan merge, deploy, atau rilis aplikasi. Fase 4 belum dimulai.

Sumber kebenaran: [`../rencana.md`](../rencana.md) (§2–§3, §4.9, §5 K-3, K-4, K-5, K-7, K-10), [`../fase-2/AUDIT-CODEX.md`](../fase-2/AUDIT-CODEX.md), `PRD-V6.md`, keputusan V6 dan perbaikan tagihan satu santri di `AGENTS.md` kedua repo.

## Branch dan baseline

| Repo | Branch | Dari | Isi |
| --- | --- | --- | --- |
| alhasanApps | `claude/redesain-tampilan-v2-fase-3`, commit `7541b89` | `origin/main` `50d2842` (PR #31; `git diff 749384a origin/main` kosong — isi sama dengan koreksi audit Fase 2; versi 1.6.0) | Kode tampilan dan uji unit; versi **1.6.0 → 1.7.0** |
| WebAlHasan | `claude/redesain-tampilan-v2-fase-3` | `origin/main` `1c73af1` (PR #120; `git diff 40be350 origin/main` kosong — isi sama dengan catatan audit Fase 2) | **Hanya** uji, alur Maestro, dan dokumen ini. Kode web, API, dan migrasi tidak berubah |

Fase 2 sudah digabung Human Developer walau audit Codex mencatat "belum lulus penuh" (letak vertikal FAB di Expo web dan selisih tampilan yang dicatat di sana tetap terbuka dan tidak disentuh fase ini). Tanpa worktree; tidak menulis di `main`. `package.json`/`package-lock.json` checkout aplikasi yang berubah milik pekerjaan lain tidak di-commit; lima direktori web belum terlacak milik pekerjaan lain tidak disentuh.

## Yang berubah (aplikasi)

Hanya tampilan. Tidak ada perubahan endpoint, parameter, payload, hak, nominal, hash pratinjau, idempotensi, versi optimistik, penjaga `pratinjauSatuSantriSah`, penjaga respons basi/latar (`masihBerlaku`, `generation`, `versiPratinjau`), penanganan 401/403/404 (`useKeuanganMutation` → `reject`), atau perlindungan `expo-screen-capture` navigator Keuangan (keputusan T-1 V6). Tanpa dependensi/modul native baru.

Bukti paritas kode: untuk 23 berkas (`src/app/keuangan/**`, `keuangan-shared`, `keuangan-setoran`, `use-keuangan-mutation`, `api/keuangan`) himpunan pemanggilan `keuangan.*(`, `guard.run(`, `sidikJari(…)`, `pratinjauSatuSantriSah(`, `masihBerlaku()`, `reject(`, `usePrivateResource(…)`, `useKeuanganMutation('…'`, `router.push/replace/back`, `tujuanDibuka('…'`, `preventScreenCaptureAsync`, `enableAppSwitcherProtectionAsync`, dan `throw new ApiError` **identik** dengan `origin/main` (`bukti/aplikasi/paritas-panggilan.txt`). `src/hooks/use-keuangan-mutation.ts` dan `src/api/keuangan.ts` tidak berubah sama sekali.

### Komponen

| Komponen | Berkas | Perubahan |
| --- | --- | --- |
| `Halaman` (ditulis ulang), `HalamanKeadaan` (baru) | `src/components/keuangan-shared.tsx` | Kerangka layar Keuangan di atas `StackScreen` Fase 2: kepala ← · judul · ⋯ di luar area gulir, `KeyboardAwareScrollView`, `ActionBar` opsional (`bawah`), penutup isi saat aplikasi tidak aktif tetap ada. `HalamanKeadaan` membungkus memuat/galat/kosong/ditolak sehingga ← selalu ada |
| `StatusBadge` | sama | Kini `StatusPill` ikon + teks: Menunggu = **jam**, Sah/Lunas/Terbit = centang, Sebagian = dompet, Belum dibayar = info, Dibatalkan/Ditolak = silang |
| `KartuSantri`, `Kartu`, `JudulBagian`, `BarisPil`, `TombolMuatUlang`, `TombolCari`, `Pesan` (Notice) | sama | Baru/diganti; `Angka` (lama) dihapus |
| `SetoranTransferKartu` | `keuangan-setoran.tsx` | Kartu ber-avatar, nominal bertanda hanya bila Sah, pil status, tombol verifikasi/tolak bersebelahan; prop `santri` kini `{ nama, nis }` |
| `ProfileHeader` | `ui/profile-header.tsx` | Prop aditif `statsNominal`: angka nominal lebih kecil dan, bila kolom terlalu sempit (± < 120 pt per angka), strip menjadi baris label–nilai agar nominal tidak saling menimpa |
| `StatCard` | `ui/stat-card.tsx` | Prop aditif `labelDulu` (label dibaca sebelum angka) |
| `EntityRow` | `ui/entity-row.tsx` | Prop aditif `valueCaption` (keterangan kecil di bawah nilai kanan) dan `iconRotate`; nilai kanan dibungkus kolom rata kanan (maks. 45% lebar) |
| `AppIcon` | `app-icon.tsx` | Prop aditif `rotate` (panah masuk/keluar dari ikon panah yang ada, tanpa aset baru) |
| `Field` | `ui/app-field.tsx` | Prop aditif `awalan` ("Rp" di depan nominal; tidak dibacakan dan tidak ikut terkirim) |
| `HeroCard` | `ui/hero-card.tsx` | `action.accessibilityLabel` opsional |
| Pembantu murni | `src/keuangan/forms.ts` | `nadaStatusKeuangan`, `nominalBertanda`, `putaranArah`, `sisaTagihanTerbuka`, `labelPeriodePendek`, `seriPerBulan`, `BATAS_TAGIHAN_SANTRI` |

### Layar (`src/app/keuangan/*`, 18 berkas)

| Layar | Pola | Perubahan tampilan |
| --- | --- | --- |
| Menu (`index`) | P-Menu | **Petugas/admin:** judul besar, kartu akses (peran · unit), kisi `MenuTile` 3 kolom (petugas 8 ubin, admin 7 tanpa penerbitan). **Orang tua:** per anak `HeroCard` "Saldo tabungan" + `StatCard` "Sisa tagihan (n terbuka)", lalu ubin Riwayat pembayaran dan Laporan anak. Muat ulang = ikon di kepala |
| Daftar santri (`santri/index`) | P-Daftar | `SearchBar` + tombol "Cari" di dalam kolom; `EntityRow` avatar inisial, NIS · kelas · unit, saldo kanan, pil "Tunggakan Rp… (n)" / "Tanpa sisa tagihan" |
| Rincian santri (`santri/[id]`) | P-Rincian | `ProfileHeader` avatar, nama, NIS · kelas · unit, pil Aktif/Tidak aktif, tombol cepat Setoran · Penarikan/belanja · Terbitkan tagihan (hanya `kelola`), strip **Saldo · Sisa tagihan · Transfer menunggu** (orang tua tanpa transfer menunggu); `SegmentTabs` Ringkasan / Tagihan / Tabungan; baris tagihan, setoran transfer, mutasi bertanda |
| Rincian tagihan (`tagihan/[id]`) | P-Rincian | Kartu invoice: kop Al Hasan · BSA, "Tagihan {periode}", pil status/versi, "Untuk", jatuh tempo, tabel Rincian biaya (Keterangan · Nominal), Total / Dibayar / **Sisa disorot**; pembayaran sebagai baris; riwayat versi (internal). Bilah bawah: "Catat pembayaran tunai/transfer" + "Bayar dari tabungan"; ⋯ = Revisi/batalkan, Keuangan santri ini |
| Catat pembayaran (`bayar`), Bayar dari tabungan (`bayar-tabungan`) | P-Form | `KartuSantri` dengan angka acuan (Sisa tagihan; Saldo tabungan / Sisa tagihan dua kolom), pil Tunai/Transfer, nominal berawalan "Rp", penghitung catatan, centang; tombol di bilah bawah → kuitansi |
| Setoran/penarikan/belanja (`transaksi`, `keuangan-setoran`) | P-Form → P-Sukses | `KartuSantri` + "Saldo saat ini"; pil jenis dan cara; hasil = `SuccessPanel` (transfer menunggu: **jam jingga** "Menunggu verifikasi") + `ReceiptCard` bukti, di atas formulir yang tetap tersedia |
| Verifikasi (`verifikasi`) | P-Daftar | `FilterPills` (tab) Setoran · n / Pembayaran · n; kartu setoran berikut tombol verifikasi/tolak; pembayaran sebagai baris ber-pil Menunggu → kuitansi |
| Pembayaran: daftar & kuitansi (`pembayaran/*`) | P-Daftar / P-Sukses | Daftar baris bergaya kuitansi; kuitansi: `SuccessPanel` "Pembayaran sah" (centang) atau "Menunggu verifikasi" (jam), `ReceiptCard`; tolak/batal beralasan di **lembar bawah**; tanpa tombol Bagikan/Cetak (keputusan T-1 V6) |
| Mutasi tabungan (`tabungan/*`) | P-Daftar / P-Rincian | Baris berikon panah (masuk ↓ hijau, keluar ↑ jingga), nominal **+/−**, saldo sesudah; saring tanggal di lembar bawah; rincian = nominal besar + `ReceiptCard`, koreksi beralasan di **lembar bawah** |
| Tunggakan (`tunggakan`) | P-Daftar | `HeroCard` "Total sisa" (jumlah tagihan · santri dari ringkasan server), pil Semua/Lewat jatuh tempo + unit, baris sisa merah dengan pil |
| Penerbitan (`penerbitan`), Tagihan satu santri (`terbit-santri`) | P-Form + pratinjau | Massal: `StatGrid` Akan terbit / Sudah ada / Dilewati / Total terbit + baris per santri, centang, tombol di bilah bawah, `SuccessPanel` hasil. Satu santri: `KartuSantri`, rincian tarif sebagai `ReceiptCard`, "Terbitkan tagihan Rp…" di bilah bawah; sudah ada/dilewati sebagai kotak keterangan |
| Ubah tagihan (`ubah-tagihan`) | P-Form | `KartuSantri` (Total/Dibayar, "versi n"), pil Revisi/Batalkan tagihan, pil baris koreksi, penghitung alasan, tombol di bilah bawah |
| Rekonsiliasi (`rekonsiliasi`) | P-Laporan | Kartu sorot Awal hari → Akhir hari; `StatGrid` Penerimaan kas baru · Keluar (tunai + transfer) · Mutasi bersih; kas per cara; **perpindahan internal (bukan kas)** terpisah; pil selisih (centang/silang); rincian berikon arah, pil "Perpindahan internal" |
| Laporan (`laporan`) | P-Laporan | Pil periode; `HeroCard` "Penerimaan kas baru" (internal) atau "Saldo tabungan akhir" (orang tua); `StatGrid` Tagihan (Terbit, Terbayar, Sisa, Pembayaran tunai/transfer) dan Tabungan (Saldo awal/akhir, Setoran, Penarikan dan belanja); `BarChart` per bulan (Tagihan/Setoran/Keluar) **hanya bila respons memuat seri ≥ 2 bulan**; kas & perpindahan internal; pembatalan & koreksi; per santri/anak sebagai baris |

Kepala native dimatikan (`headerShown: false`) untuk seluruh navigator Keuangan; geser-tepi iOS dan tombol kembali Android tetap bekerja.

### K-10 — tombol ←

Setiap layar Keuangan menggambar tepat satu ← "Kembali" (`useKembali('stack')` Fase 2): kembali ke halaman sebelumnya, atau ke Beranda bila dibuka tanpa riwayat (ketukan notifikasi `bsa_tagihan`/`bsa_pembayaran`/`bsa_tabungan`, tautan `alhasanapps://keuangan/…`, muat ulang web). Keadaan memuat, galat, kosong, ditolak server (403/404), dan perlindungan layar yang sedang disiapkan/gagal juga ber-←.

## Penafsiran (mohon ditinjau auditor / Human Developer)

| No | Penafsiran | Alasan |
| --- | --- | --- |
| P3-1 | Tab segmen rincian santri: **Ringkasan** (bawaan) menampilkan seluruh bagian (tagihan, setoran transfer, riwayat tabungan); Tagihan dan Tabungan menyaring | Suite V6 Fase 4/5 menunggu teks "Riwayat tabungan" dan referensi setoran transfer pada tampilan awal rincian. Oracle tidak dilonggarkan |
| P3-2 | Rincian tagihan: "Bayar dari tabungan" tetap tombol terlihat di bilah bawah bersama "Catat pembayaran tunai/transfer"; ⋯ berisi Revisi/batalkan dan Keuangan santri ini (rencana: Bayar dari tabungan di ⋯) | Suite V6 Fase 4 (K-C) menekan "Bayar dari tabungan" langsung di rincian tagihan |
| P3-3 | P-Sukses setoran/penarikan/belanja = `SuccessPanel` + `ReceiptCard` di atas formulir yang tetap tersedia (bukan layar pengganti) | Suite V6 Fase 4 (K-B) mencatat transaksi berikutnya langsung sesudah sukses; urutan permintaan tidak berubah |
| P3-4 | Menu petugas/admin **tanpa** StatGrid | Respons `/v6/bsa/akses` tidak memuat angka apa pun; menambah permintaan untuk angka dilarang ("StatGrid hanya dari angka yang sudah ada di respons"). Orang tua mendapat angka dari daftar anak yang memang sudah dimuat |
| P3-5 | Strip "Sisa tagihan" = jumlah `sisa` tagihan berstatus Terbit pada respons rincian santri; bila daftar mencapai batas server 60 tagihan, tampil "—" disertai keterangan | Respons rincian tidak memuat total sisa; server membatasi daftar 60 (`BsaRepository::tagihanSantri`), sehingga jumlah di atas batas bisa keliru. Tidak ada permintaan tambahan |
| P3-6 | Strip "Transfer menunggu" = `saldo_menunggu` (setoran transfer). Pembayaran tagihan transfer yang menunggu tetap tampil di invoice tagihannya | Angka itulah yang ada di respons rincian santri |
| P3-7 | Rupiah tetap `Rp1.250.000` (titik, tanpa spasi) seperti `rupiah()` sejak V6 | Rencana §2 mencontohkan "Rp 300.000"; seluruh oracle V6 (`rp()`) dan website memakai tanpa spasi |
| P3-8 | `BarChart` laporan hanya bila `per_bulan` memuat ≥ 2 bulan; pilihan ukuran Tagihan/Setoran/Keluar; tanpa angka pembanding | Satu bulan bukan seri. "Bulan ini"/"Bulan lalu" karena itu tanpa grafik |
| P3-9 | Kartu statistik laporan/rekonsiliasi yang menjumlahkan komponen server (Setoran tunai+transfer, Penarikan dan belanja, Pembayaran tunai/transfer, Keluar tunai+transfer) | Penjumlahan langsung angka server yang sudah dilakukan layar lama (mis. "Setoran", "Penarikan dan belanja"); tidak ada angka turunan lain |
| P3-10 | Verifikasi: setoran diputus di kartunya (tidak punya rute rincian); pembayaran membuka kuitansi | Sama dengan perilaku lama; suite V6 Fase 4 dan probe V-1 menekan tombol verifikasi pada kartu |
| P3-11 | Tolak/batal pembayaran dan koreksi tabungan beralasan di lembar bawah (`BottomSheet keyboardAware`); tolak setoran tetap di kartu | Rencana menyebut lembar bawah untuk koreksi; pembatalan pembayaran diseragamkan. Probe F-1 (403 menutup data dan draf) lulus dengan lembar bawah |
| P3-12 | Kuitansi menampilkan `SuccessPanel` setiap kali dibuka bila status Sah (centang) atau Menunggu (jam), bukan hanya sesudah transaksi | Rencana: "SuccessPanel bila Disahkan"; status dari server |
| P3-13 | Menu orang tua tanpa ubin "Mutasi tabungan" | Daftar mutasi lintas santri menjawab 403 bagi orang tua; mutasi per anak ada di rincian anak (dibuka dari kartu anak) |
| P3-14 | Label tombol yang dipertahankan: "Catat pembayaran tunai/transfer", "Kembali ke santri", "Sudah diperiksa — verifikasi", "Dana sudah diperiksa — verifikasi", "Koreksi transaksi…", "Batalkan pembayaran…", "Tampilkan tagihan", "Terbitkan tagihan Rp…", "Muat ulang" (kini ikon kepala berlabel sama) | Dipakai suite V6 Fase 4/5, tagihan satu santri, dan probe F-1/V-1 |
| P3-15 | `ProfileHeader` mendapat `statsNominal` (strip baris label–nilai pada layar sempit) | Uji visual menemukan tiga nominal saling menimpa pada 375 px; dikoreksi sebelum handoff dan dijaga pemeriksaan "teks meluber" |
| P3-16 | Berkas di luar `src/components`/`src/constants`/layar fase: `src/keuangan/forms.ts` (pembantu murni), `tests/redesain-v2-fase3.test.ts`, `tests/redesain-v2-kontras.test.ts`, `app.json` (versi) | Pembantu murni agar dapat diuji di Node; sama dengan P2-15 |
| P3-17 | `EntityRow`: nilai kanan kini kolom rata kanan selebar maks. 45% (untuk `valueCaption`) — memengaruhi semua daftar yang memakai `EntityRow` | Regresi Expo web Fase 1–2 dan modul lain dijalankan ulang (lihat Bukti) |
| P3-18 | Tanggal ditulis format Indonesia (`tanggalPanjang`) pada rekonsiliasi, rincian tarif satu santri, dan pil saring mutasi | Rencana §2 |

## Bukti

Lingkungan: macOS, Node 26.7, PHP 8.4.14, MariaDB 12.3.2 lokal, fixture sintetis (`test_*_test`). Aplikasi dijalankan dari **salinan tanpa `.env*`** (`git archive` + sumber kerja, `node_modules` dari `npm ci --offline` lock ter-commit, Expo 57.0.15), `EXPO_NO_DOTENV=1`, `EXPO_OFFLINE=1`, API `http://127.0.0.1:8971/api/v1`; sumber salinan dibandingkan identik (`diff -r`) dengan checkout sebelum setiap run akhir; bundel web 0 `alhasan.co.id`. Semua skrip Playwright memblokir host selain `127.0.0.1` (nol permintaan keluar). Berkas di [`bukti/`](bukti/).

| Uji | Hasil |
| --- | --- |
| `npx tsc --noEmit` · `expo lint` (salinan bersih, kode akhir) | exit 0 · exit 0 (`bukti/aplikasi/`) |
| Unit `node --test tests/*.test.ts` | **104/0** (baseline `50d2842`: 97/0). Baru: `redesain-v2-fase3.test.ts` (7 uji: kerangka ber-← di 18 layar dan `headerShown: false`, perlindungan layar tetap, tanpa Bagikan/Cetak, tanpa "pendapatan"/"laba", nada status, nominal bertanda, sisa tagihan/batas 60, seri grafik); `redesain-v2-kontras.test.ts` + empat pasangan Fase 3 |
| Aturan versi `versi:periksa --kerja` | LULUS 1.6.0 → 1.7.0 (minor, satu kali) |
| Paritas pemanggilan API/penjaga (23 berkas) | identik dengan `origin/main` (`bukti/aplikasi/paritas-panggilan.txt`) |

### Expo web — suite keuangan (oracle tidak diubah)

`perintah-uji.sh` (kode akhir, port 8962) dan kontrol baseline `git archive origin/main` aplikasi (port 8961), DB disiapkan ulang per suite:

| Suite | Fase | Baseline |
| --- | ---: | ---: |
| V6 Fase 4 `uji-v6-fase4-app.mjs` (setoran ketukan ganda, jawaban hilang → kunci sama, belanja, transfer menunggu, verifikasi, bayar dari tabungan, tunai, konflik versi, penerbitan massal, admin, wali dua anak, notifikasi, pencabutan relasi/penugasan, offline, token dicabut) | **45/0** | 45/0 |
| V6 Fase 5 `uji-v6-fase5-app.mjs` (laporan = API, R-10, petugas unit, wali, pencabutan) | **20/0** | 20/0 |
| Tagihan satu santri `uji-tagihan-satu-santri-app.mjs` | **27/0** | 27/0 |
| Probe audit satu santri `uji-tagihan-satu-santri-app-audit.mjs` (respons pratinjau basi/latar) | **3/0** | 3/0 |
| Probe F-1 `docs/phase-v6-4/audit-codex/uji-mutasi-akses.mjs` (8 alur POST ditolak menutup data dan draf) | **16/0** | 16/0 |
| Probe V-1 `docs/phase-v6-4/validasi-claude/uji-pesan-basi.mjs` | **5/0** | 5/0 |
| Suite fase `uji-redesain-v2-fase3.mjs` (lihat di bawah) | **258/0** | — |

Galat PHP server API: 0. Tidak ada suite lama yang diubah.

`uji-redesain-v2-fase3.mjs` (petugas, admin, orang tua): 19 varian rute dari 18 layar dibuka **langsung** — judul kepala, tepat satu ← ≥ 44 pt → Beranda, tanpa Bagikan/Cetak, tanpa "pendapatan"/"laba", rupiah bertitik, tanpa luapan dan tanpa teks meluber/menimpa; ← dengan riwayat; menu per peran (8/7 ubin, orang tua per anak = API); strip Saldo · Sisa tagihan · Transfer menunggu = API (orang tua tanpa transfer menunggu); tab segmen; mutasi bertanda = arah API; kuitansi Menunggu = jam, Sah = centang; grafik hanya bila seri ≥ 2 bulan, jumlah batang = seri; seluruh kontrol terlihat ≥ 44 pt; 375 gelap, 768 dan 1280 terang/gelap. **Kontrol pemeriksaan "teks meluber":** dijalankan pada bundel sebelum koreksi strip (P3-15) → 3 gagal tepat pada tiga nominal yang menimpa (`bukti/web/kontrol-luber/`).

### Jaringan (baseline vs fase)

`uji-redesain-v2-fase3-jaringan.mjs` merekam alur yang sama dari dump DB yang sama pada baseline (`origin/main` `50d2842`) dan kode fase: petugas — menu, daftar + cari, rincian santri, **setoran tunai (ketukan ganda)**, **setoran transfer**, **verifikasi setoran (ketukan ganda)**, **terbit satu santri (ketukan ganda)**, **bayar tunai (ketukan ganda)**, **bayar transfer** + **verifikasi pembayaran**, **bayar dari tabungan (ketukan ganda)**, **batal pembayaran**, **revisi tagihan**, **koreksi tabungan**, **penerbitan massal (ketukan ganda)**, **batal tagihan**, tunggakan, pembayaran, mutasi, rekonsiliasi, **laporan** (tiga periode); admin; orang tua. Dibandingkan per langkah: metode, path dengan ID literal, nilai query, badan JSON kanonik (kunci idempotensi dan password disamarkan).

| Hasil | |
| --- | --- |
| Baseline vs fase (direkam hari yang sama) | **32 langkah sama / 0 berbeda, 158 permintaan** |
| Ketukan ganda | 13 langkah mutasi: tepat **1 kunci idempotensi dan 1 POST** per langkah, pada baseline dan fase |
| Catatan | Rekaman baseline 12 November dibanding fase 13 November berbeda hanya pada tanggal bawaan "hari ini" (`tanggal_transaksi`, `tanggal` rekonsiliasi) di 3 langkah; baseline direkam ulang 13 November → 0 berbeda (`bukti/jaringan/`) |

### Regresi Expo web modul lain (komponen bersama berubah)

`docs/redesain-tampilan-v2/fase-2/perintah-regresi.sh` dengan kode fase: Fase 2 pengajian **68/0**, Fase 2 izin **145/0**, Fase 1 **159/0** (1 dilewati seperti Fase 2), profil **105/0**, kelas gabungan **57/0**, pencarian absensi **53/0**, pencarian daftar **24/0**, peserta absensi **18/0**, V7 F4 **48/0**, Keamanan F4 **46/0**, V5 F4 **78/0**, V5 F5 **36/0** — total **837/0**, galat PHP 0 (`bukti/regresi/`). Run ini mendahului dua koreksi terakhir yang hanya menyentuh `keuangan-shared.tsx` (dipakai layar Keuangan saja).

### Native

| Platform | Build yang benar-benar diuji | Hasil |
| --- | --- | --- |
| Android | **Emulator** `Medium_Phone`, Android 16/API 36, 1080 × 2400 @ 420 dpi. **Dev client Debug baru 1.7.0** (`expo prebuild` + `gradlew --offline assembleDebug`, arm64, dari salinan tanpa `.env*`; `versionName=1.7.0`) + Metro dari salinan yang sama (API 127.0.0.1:8981, DB uji tersendiri). Bukan build rilis, bukan perangkat fisik | **25/0**: petugas (menu, strip, tab, ← ≈ 44 dp, setoran **ketukan ganda → tepat satu transaksi**, tombol kembali Android, ← dengan riwayat), tautan saat berjalan → ← Beranda, penugasan dicabut saat formulir terbuka (403, tanpa mutasi, data/kolom ditutup, ← tetap), admin hanya baca, orang tua dua anak tanpa transfer menunggu, relasi dicabut → ditutup, gelap + 2,0× (strip, ←, tombol terjangkau dengan menggulir, laporan); tangkapan Keuangan terlindungi (`bukti/android/`) |
| iOS | **Simulator** iPhone 17 Pro, iOS 26.2. **Build Release baru 1.7.0** arm64 (`expo prebuild` + `pod install` + `xcodebuild -configuration Release`, tanda tangan ad-hoc `-` oleh Xcode, bundel Hermes tertanam dari kode akhir: 0 host produksi, API lokal). Bukan iOS Debug, bukan arsip distribusi, bukan perangkat fisik | Petugas (menu, rincian santri: strip + tab, setoran **ketukan ganda dengan papan ketik terbuka → tepat satu transaksi** (4 → 5), ← dengan riwayat, tautan saat berjalan) **lulus**; admin hanya baca + orang tua per anak tanpa "Transfer menunggu" **lulus**; tampilan gelap, AX-XL, AX-XXXL, gelap + AX-XXXL (strip, invoice, kuitansi menunggu, laporan; ← ada) **4/4 lulus**; tautan saat aplikasi **mati** → kuitansi terbuka, tetapi ← pertama membuka duplikat (T3-1) (`bukti/ios/`) |

Tangkapan layar native layar Keuangan **hitam** di kedua platform: perlindungan `expo-screen-capture` (keputusan T-1 V6) tetap bekerja — Android: PNG layar Keuangan 15,6 KB polos vs Beranda 247 KB; iOS: tangkapan Maestro `ios-f3-*` hitam, dan alat simulator mengembalikan bingkai lama. Karena itu bukti native berupa pernyataan hierarki aksesibilitas (Maestro/uiautomator) dan hitungan DB; bukti visual dari Expo web.

Koreksi yang ditemukan uji visual/native dan sudah diterapkan sebelum handoff:

1. Web 375 px: tiga nominal strip profil santri saling menimpa → `ProfileHeader statsNominal` (P3-15).
2. Web: judul invoice terhimpit pil status; label bilah bawah pecah tiga baris → judul lebar penuh, dua tombol bertumpuk.
3. iOS: sesudah Dynamic Type diubah saat layar aktif, kepala profil menyisakan ruang kosong besar (pengukuran lama) → area gulir `Halaman` dipasang ulang saat skala teks berubah (pola Fase 2 `izin/[id]`).
4. iOS AX-XXXL: bilah tombol tetap (dua tombol bertumpuk) menutupi hampir seluruh layar (area gulir ±14 pt) → pada teks > 1,35× tombol berada di akhir isi bergulir.

Diagnostik alat uji (bukan cacat aplikasi), dicatat terbuka: Metro web dengan `CI=1` tidak memuat ulang berkas yang disalin, sehingga satu run awal memakai bundel lama (diulang sesudah Metro dinyalakan ulang; semua angka di atas dari kode akhir); entri cache transform Gradle yang rusak ("immutable workspace modified") dihapus satu per satu lalu build diulang; build iOS tanpa tanda tangan tidak membawa entitlement Keychain (login gagal) → dibangun ulang dengan tanda tangan ad-hoc Xcode; dialog emulator "System UI isn't responding" ditutup; pemilih Maestro `"Nominal (rupiah)"` semula mengenai label (bukan kolom) dan sempat disangka masalah papan ketik — percobaan memindah tombol ke dalam area gulir dibatalkan karena tombol justru tertutup papan ketik (`bukti/ios/kontrol-tombol-dalam-gulir-tertutup-papan-ketik.txt`), sedangkan bilah tetap terbukti menerima ketukan dengan papan ketik terbuka (`bilah-tetap-papan-ketik-terbuka.txt`); penyiapan DB V6 tidak boleh berjalan bersamaan (DB perantara bernama tetap) — satu percobaan Android gagal karena itu dan diulang berurutan.

## Temuan terbuka

| No | Temuan | Status |
| --- | --- | --- |
| T3-1 | iOS: tautan `alhasanapps://keuangan/pembayaran/{id}` saat aplikasi **mati** membuka kuitansi dua kali bertumpuk; ← pertama membuka duplikatnya, ← kedua Beranda. Penyebab: `src/notifications/notification-context.tsx` menyimpan tautan selagi sesi belum siap (`userId === null`) lalu mendorong layar yang sama lagi sesudah masuk, padahal expo-router sudah menavigasi URI itu | **Di luar cakupan** (berkas notifikasi, bukan layar Keuangan; ada sejak sebelum fase ini). Tidak diubah. Perlu keputusan/perbaikan tersendiri; berlaku juga untuk tujuan tautan lain yang ditangani pendengar itu (rapor, ringkasan kesehatan, izin wali) |
| T3-2 | Tangkapan layar native Keuangan hitam (perlindungan tetap) | Bukan cacat; bukti visual dari Expo web |
| T3-3 | Pada teks sangat besar (> 1,35×) tombol tindakan tidak lagi di bilah tetap tetapi di akhir isi | Koreksi #4; menyimpang dari "tombol tetap di bawah" demi keterjangkauan isi |
| T3-4 | Temuan terbuka Fase 1–2 (kontras `statusIzin`/`statusTerlambat`, logo lime, luapan bilah tab web 375 px, letak vertikal FAB di Expo web) | Tidak berubah |

## Mengulang uji

```bash
# Web (dari akar repo web); APP = Expo web salinan TANPA .env* yang API-nya 127.0.0.1:8971
APP=http://127.0.0.1:8962 bash docs/redesain-tampilan-v2/fase-3/perintah-uji.sh <keluaran> v6f4 v6f5 ss ss-audit probe-f1 probe-v1 fase3
# Jaringan: baseline (git archive origin/main aplikasi) dan fase dari dump yang sama, pada HARI yang sama
DUMP=<dump> APP=http://127.0.0.1:8961 bash docs/redesain-tampilan-v2/fase-3/perintah-uji.sh <keluar-baseline> jaringan
DUMP=<dump> APP=http://127.0.0.1:8962 bash docs/redesain-tampilan-v2/fase-3/perintah-uji.sh <keluar-fase> jaringan
node tests/browser/uji-redesain-v2-fase3-jaringan.mjs banding <keluar-baseline>/jaringan.json <keluar-fase>/jaringan.json
# Android emulator (dev client Debug + Metro 8964, adb reverse API/Metro); DB seperti suite fase3
DB_NAME=<db> METRO=http://127.0.0.1:8964 OUT_DIR=<keluaran> node tests/browser/uji-redesain-v2-fase3-android.mjs
# iOS simulator (build Release ad-hoc terpasang; DB seperti suite fase3 + satu pembayaran transfer menunggu)
DB_NAME=<db> UDID=<simulator> bash tests/maestro/redesain-v2-fase3/jalankan-ios.sh <keluaran>
```

Jalankan berurutan: penyiapan DB V6 memakai DB perantara bernama tetap.

## Batas uji

- Perangkat fisik, build distribusi (EAS), iOS Debug, APK rilis, Push nyata (termasuk ketukan notifikasi Push), MariaDB 10.6/MySQL/cPanel/produksi, dan UAT **belum diuji**.
- "Dibuka dari notifikasi tanpa riwayat" dibuktikan dengan rute langsung di Expo web (19 rute → Beranda), tautan saat aplikasi berjalan (Android, iOS), dan tautan saat aplikasi mati di iOS (terbuka, lihat T3-1); bukan ketukan Push. Di Android dev client tautan saat aplikasi mati membuka launcher (batas lama).
- Skala teks native hanya Android 2,0× dan iOS AX-XL/AX-XXXL; Expo web tidak mengemulasi skala teks sistem.
- Regresi Expo web modul lain tidak diulang sesudah dua koreksi terakhir `keuangan-shared.tsx` (dipakai layar Keuangan saja); suite keuangan, fase, dan jaringan diulang pada kode akhir.
- Suite gelombang 1 (`uji-redesain-fase1/2/3.mjs`) dan `uji-aplikasi.mjs` tidak dijalankan. Uji statis PHP web tidak dijalankan: kode web tidak berubah (oracle "berkas tidak berubah" fase lama dapat menandai berkas uji/dokumen baru ini).
- Seluruh data sintetis lokal, API `127.0.0.1`.
