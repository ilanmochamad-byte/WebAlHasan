# PRD V6 Fase 4 — Paritas Aplikasi Mobile dan Orang Tua

**Putusan terbaru (26 Oktober 2026): LULUS audit ulang Fase 4 pada lingkungan uji yang tersedia — lihat [AUDIT-ULANG-CODEX.md](AUDIT-ULANG-CODEX.md).** Koreksi V-1 dan runtime iOS Release/cold start URI telah diuji independen. Perangkat fisik, Push nyata/cold start Push, Debug iOS dan validasi produksi tetap terbuka. Implementasi/audit awal sudah masuk `main` lewat PR #90 web dan #17 mobile; tindak lanjut ini tetap pada `claude/prd-v6-fase-4-validasi`, belum di-merge. Tidak deploy/migrasi produksi/rilis aplikasi atau memulai Fase 5. Tagihan dan tabungan tetap hanya dirilis bersama sesudah gerbang akhir V6 dan keputusan Human Developer. Catatan implementator awal di bawah dipertahankan sebagai riwayat.

| Butir | Nilai |
| --- | --- |
| Workstream | PRD V6 Fase 1–5 keuangan santri/BSA — implementator Claude Code, auditor Codex (`AGENTS.md`, keputusan 25 Oktober 2026) |
| Branch | `claude/prd-v6-fase-4` di **kedua** repositori |
| Baseline web | Commit audit Fase 3 `1f2dbca574805954566305789e589540e27348f0` (= `origin/claude/prd-v6-fase-3`). `origin/main` `4502233` (merge PR #89) memuatnya; `git diff 1f2dbca origin/main` kosong. Branch dibuat dari `1f2dbca`, tidak bekerja di `main`. |
| Baseline mobile | `origin/main` `f8ebba0a43493efde50a7a207933e86dc4b941d4` (merge PR #16; memuat V5 Fase 5 `7311ae3` dan audit V5 Fase 4 `33522f7`), diverifikasi dengan `git fetch`. Checkout semula di `claude/aset-appstore-header` `c3e096c` (pohon identik dengan `f8ebba0`). Branch dibuat dari `origin/main`. |
| Perubahan belum di-commit milik pekerjaan lain | `~/alhasanApps/package.json` + `package-lock.json` (Expo 57.0.15 → 57.0.31, RN 0.86.2 → 0.86.3, dst.) **tidak disentuh dan tidak di-commit**; terbawa sebagai perubahan kerja saat berpindah branch. Tiga direktori web belum terlacak (`docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/`) tidak disentuh. |
| SDK/dokumentasi | Expo SDK 57; dokumentasi versi `docs.expo.dev/versions/v57.0.0` (expo-router: Stack, `useLocalSearchParams`, `useFocusEffect`, `router.push`; expo-notifications: respons ketukan, `useLastNotificationResponse`, `setNotificationHandler`) dibaca sebelum mengubah kode mobile. Tanpa dependensi/modul native baru. |
| Lingkungan uji | PHP 8.4.14, MariaDB 12.3.2 lokal, Apache 2.4 + php-fpm (regresi PSB), Chromium Playwright, Android emulator, Xcode 27.1. Seluruh DB `test_*_test` berfixture sintetis; tidak ada data produksi. **MySQL, cPanel, perangkat fisik, Push nyata — belum diuji.** |

## Yang dikerjakan

### Aplikasi (`alhasanApps`)

Modul baru `src/app/keuangan/*` (17 layar) di atas endpoint V6 Fase 1–3 yang sudah ada; seluruh keputusan hak, saldo, sisa, status, dan nomor kuitansi tetap dari server.

| Peran | Layar dan tindakan |
| --- | --- |
| Petugas BSA | Pintu; santri & saldo (cari, halaman); rincian santri (saldo, saldo transfer menunggu, tagihan, setoran transfer + verifikasi/tolak, riwayat tabungan dengan rujukan silang); setoran tunai/transfer (centang "sudah memeriksa dana"); penarikan/belanja; rincian tagihan; catat pembayaran tunai/transfer; **bayar dari tabungan** (wajib centang persetujuan); revisi/batal tagihan berversi; kuitansi pembayaran (verifikasi, tolak, batal beralasan); kuitansi tabungan (koreksi beralasan); daftar pembayaran; mutasi; antrean verifikasi transfer; tunggakan; rekonsiliasi harian; penerbitan tagihan massal (pratinjau → centang → terbit). |
| Admin / admin+petugas | Seluruh layar baca yang sama, tanpa tombol mutasi (server tetap menolak mutasi `403`). |
| Orang tua | "Keuangan anak": satu kartu per anak berelasi aktif (saldo, sisa tagihan); rincian anak, tagihan, kuitansi pembayaran dan tabungan, riwayat pembayaran — tanpa referensi transfer, catatan internal, alasan, antrean menunggu, riwayat versi. |

Penanganan keadaan: memuat/kosong/gagal (`screen-state`), offline/timeout (pesan khusus dan "Coba lagi"), ketukan ganda (`useMutationGuard`: satu permintaan berjalan, tombol nonaktif), retry jaringan (kunci idempotensi sama untuk isi sama, kunci baru bila isi berubah atau sesudah 403/409/422), konflik versi `409` (pesan + tombol "Muat versi terbaru (isian ini dibuang)", tanpa kirim ulang otomatis), sesi kedaluwarsa `401` (token dihapus, kembali ke layar masuk), akses dicabut `403/404` (data dibuang dari memori, isian dibersihkan). Data server dimuat ulang setiap fokus dan setiap aplikasi kembali aktif, dan dibuang saat aplikasi ke latar (`usePrivateResource`). Tidak ada penyimpanan disk atau log untuk data keuangan. Navigator keuangan memasang perlindungan native `expo-screen-capture` sebelum layar dimuat (lihat T-1).

Ubin Beranda "Keuangan"/"Keuangan anak" (ikon dompet PNG ber-tint), rute akar, tombol notifikasi keuangan, penunjuk notifikasi/tautan skema `alhasanapps://keuangan/{tagihan|pembayaran|tabungan}/{id}` yang bertahan melewati login. Rincian berkas di `alhasanApps/docs/phase-v6-4/README.md`.

### Web/API (`WebAlHasan`) — tanpa migrasi, tanpa endpoint baru

1. **Pemberitahuan umum V6 (PRD §3.6, Fase 4 persyaratan 5)** — `App\Bsa\BsaNotifikasi`: satu peristiwa → satu baris InApp per wali berakun aktif dengan relasi `santri_wali` aktif (`RecipientResolver::waliSantri`), dan Push hanya bila sakelar Push menyala serta wali berperangkat aktif; WhatsApp tidak pernah. Diantre di **dalam transaksi mutasi yang sama** sesudah audit (pola V5 Fase 3), sehingga replay idempotensi tidak mengantre ulang dan gagal antre membatalkan mutasi (`503`). Sembilan titik: tagihan terbit (tunggal, massal), setoran/penarikan/belanja sah, verifikasi setoran transfer, koreksi pembalik, pembayaran tunai/transfer sah, verifikasi pembayaran, pembatalan pembayaran sah, bayar dari tabungan (satu pemberitahuan pembayaran mewakili debit pasangannya). Transfer menunggu/ditolak dan penolakan pembayaran menunggu tidak diberitahukan. Judul/isi generik dari satu katalog; data hanya `{tipe, tagihan_id|pembayaran_id|transaksi_id}`.
2. **Worker Push** (`NotificationDispatcher`) memeriksa ulang sebelum provider: kunci peristiwa cocok dengan penunjuk, sumber masih dalam keadaan peristiwa (tagihan `Terbit`, pembayaran `Sah`/`Dibatalkan`, ledger `Sah`), santri aktif, penerima masih wali aktif; bila tidak → `AKSES_BERUBAH` permanen tanpa kiriman. Teks dirakit ulang dari katalog; payload = penunjuk yang diperiksa.
3. **Tautan notifikasi**: `/notifikasi` (`NotificationCenterService`) menambah kunci aditif `tagihan_id`, `pembayaran_id`, `transaksi_id`; kategori `keuangan`; label peristiwa; pusat notifikasi web menaut ke `bsa_tagihan.php`, `bsa_kuitansi.php`, `bsa_tabungan_kuitansi.php` (halaman yang memeriksa ulang hak) dan chip "Keuangan".
4. **Koreksi privasi orang tua (temuan T-2, kode Fase 2)**: `GET /v6/bsa/pembayaran` orang tua menyaring transfer menunggu di SQL — sebelumnya disaring sesudah paginasi sehingga `total` menghitung transfer menunggu (bocor jumlah dan halaman tidak konsisten); `tunggakan` orang tua mengembalikan `menunggu_verifikasi: 0` — sebelumnya membuka nominal transfer menunggu, bertentangan dengan Q-10 Fase 2/R-7 Fase 3 (rincian tagihan sudah 0). Web memakai layanan yang sama.
5. `docs/api-v1.md` § V6 Fase 4; fixture `tests/v6_phase4_seed.php`/`v6_phase4_db.sh` (wali `sbx_v6_wali_dua` dengan dua anak); uji `tests/v6_phase4_static.php`, `tests/v6_phase4_http.php`, `tests/browser/uji-v6-fase4-app.mjs`.

## Kriteria penerimaan → bukti

| Kriteria Fase 4 | Bukti | Status |
| --- | --- | --- |
| Transaksi dari aplikasi terlihat sekali dengan angka identik di website; retry jaringan tidak menggandakan tagihan, setoran, penarikan, pembayaran | `bukti/http.txt` N-B (API: retry setoran 200 replay + 409 isi beda; empat belanja serentak pada server 4 pekerja → satu; tagihan + retry → satu; bayar dari tabungan tiga kali serentak → satu debit + satu pembayaran; tunai + retry → satu kuitansi; penerbitan massal + retry → replay, ulang periode 422; baris tabel web petugas dan wali memuat kuitansi tepat sekali, saldo/sisa sama). `bukti/aplikasi-expo-web.txt` K-A…K-D (ketukan ganda → satu POST; **jawaban server dibuang lalu kirim ulang → kunci sama, replay, satu kredit**; belanja, transfer → antrean, verifikasi; bayar dari tabungan, tunai; website menampilkan kuitansi aplikasi sekali dengan angka sama; penerbitan ketukan ganda → satu batch). `bukti/android-petugas.txt` (emulator: ketukan ganda setoran/belanja/verifikasi/bayar dari tabungan → satu baris, satu klaim). | Lulus (API, Expo web, Android emulator) |
| Matriks BSA/admin/wali dan dua wali santri berbeda: nol akses lintas cakupan web/API/aplikasi | `bukti/http.txt` N-A (akses sembilan akun; wali dua anak tepat dua, wali SMP/Tsanawi masing-masing satu; tujuh pasangan baca → 200/404; petugas Tsanawi atas SMP 404; admin, admin+petugas, wali mutasi 403; token rusak 401), N-D (penunjuk notifikasi dibuka wali lain → 404 pada tagihan/pembayaran/tabungan). `aplikasi-expo-web.txt` K-E (admin tanpa tombol mutasi), K-F (wali SMP membuka URL anak Tsanawi → ditolak tanpa nama/angka). | Lulus (web, API, Expo web); Android: wali dua anak dan pencabutan (bawah) |
| Wali dua anak: dua himpunan terpisah; relasi dicabut menutup akses walau sesi dan layar lama terbuka | `http.txt` N-F (token/sesi lama sesudah satu relasi diarsipkan: anak itu 404 di API dan web, anak lain tetap; transaksi baru anak itu tidak diberitahukan; Push yang sudah antre ditolak worker). `aplikasi-expo-web.txt` K-F/K-G (dua kartu = API; rincian Tsanawi tanpa data SMP; relasi dicabut saat layar terbuka → "Muat ulang" ditolak tanpa data, pintu tinggal satu anak). `bukti/android-wali-privasi.txt` B1–B4 (emulator: dua kartu, notifikasi → "Buka tagihan", relasi dicabut saat layar terbuka → ditolak, kembali dari latar memeriksa ulang). Penugasan petugas dinonaktifkan saat formulir terbuka → kirim 403 tanpa mutasi (`http.txt`, `aplikasi-expo-web.txt` K-G). | Lulus |
| Uji Android dan iOS mencatat jenis build/perangkat; emulator ≠ fisik | Android: emulator `sdk_gphone16k_arm64` Android 16 (AVD Medium_Phone), dev client **Debug APK lama** (24 Oktober 2026, sudah memuat `expo-screen-capture`) + bundel JS dari Metro salinan tanpa `.env.local` (`node_modules` Expo 57.0.31 pada saat uji Android). iOS: **belum diuji** — `.app` Debug simulator yang ada tidak lengkap (`alhasanApps.debug.dylib` hilang, crash saat dyld) dan build baru dari salinan gagal di linker Xcode 27.1 (simbol `RCTPackagerConnection`, `facebook::react::Sealable`), sama dengan batas V5 Fase 4–5; percobaan pertama juga gagal karena Pods tidak cocok dengan `@expo/ui` 57.0.26 dari `package.json` belum di-commit, lalu diulang dengan versi ter-commit (`npm ci`, 57.0.15) sampai tahap linker. **Perangkat fisik Android/iOS: belum diuji.** | Android emulator lulus; iOS dan fisik **belum diuji** |
| Log dan cache memuat nol token, nomor rekening, bukti transfer, atau riwayat anak lain | `http.txt` N-G (log server: 0 galat PHP, 0 referensi transfer, 0 token API/Push; 62 baris audit `bsa.*` tanpa nominal/saldo/referensi; antrean notifikasi tanpa referensi; 24 baris pemberitahuan generik). `aplikasi-expo-web.txt` K-F/K-G/K-H (local/sessionStorage petugas dan wali tanpa referensi/nama santri; konsol tanpa referensi/token/kata sandi; 0 permintaan ke luar 127.0.0.1). `android-wali-privasi.txt` B6 (39 berkas data aplikasi: 0 referensi, 0 nama santri, 0 nomor kuitansi, 0 kata sandi; 702 baris logcat proses aplikasi: 0 referensi/nama/`Bearer`/`Rp`/kuitansi), B5/B7 (cuplikan pengalih aplikasi — T-1). Aplikasi tidak menyimpan bukti transfer (referensi hanya teks yang diketik petugas, tidak disimpan lokal). | Lulus sesudah koreksi T-1 (Android emulator); iOS belum diuji |
| Persyaratan 5: notifikasi umum dan otorisasi ulang di tujuan | `http.txt` N-D/N-E (lihat bagian Web 1–3); aplikasi: ketukan/tautan hanya membawa penunjuk, layar tujuan meminta `/v6/bsa/...` (statis W-6, `keuangan.test.ts`), `aplikasi-expo-web.txt` K-F (notifikasi → tombol → tagihan dibaca dari server). **Push provider nyata dan ketukan Push di perangkat belum diuji** (klien Expo palsu di worker). | Lulus untuk InApp dan worker; Push nyata **belum diuji** |

## Hasil pengujian implementator

| Rangkaian | Hasil | Berkas |
| --- | --- | --- |
| Statis `tests/v6_phase4_static.php` (tanpa migrasi; katalog generik; 9 titik pemberitahuan di cabang mutasi; worker memeriksa ulang; privasi wali; tautan; batas berkas Fase 4; klien/layar aplikasi tanpa cache/log; setiap mutasi lewat penjaga; lint 14 PHP) | 22/0 | `bukti/statis.txt` |
| HTTP web + API, server 4 pekerja `tests/v6_phase4_http.php` | 63/0 | `bukti/http.txt` |
| **Kontrol**: suite HTTP yang sama di atas kode baseline `1f2dbca` (hanya kelas `BsaNotifikasi` disalin agar konstanta uji terbaca) | 48/15 — 15 kegagalan = seluruh butir pemberitahuan dan privasi wali Fase 4 | `bukti/http-kontrol-baseline-1f2dbca.txt` |
| Aplikasi Expo web (Chromium 375×812) + API/DB nyata, dari salinan checkout mobile tanpa `.env*` dengan **versi paket ter-commit** (`npm ci`, Expo 57.0.15); bundel 0 host produksi | 45/0 | `bukti/aplikasi-expo-web.txt`, `tangkapan/app-*.png` (diperiksa visual) |
| Mobile `tsc --noEmit` / `expo lint` / unit (`node --test`, 36 = 29 lama + 7 baru) — checkout (57.0.31) dan salinan ter-commit (57.0.15) | exit 0 / exit 0 (0 peringatan) / 36/0 dan 36/0 | `bukti/mobile-tsc-lint-unit.txt` |
| Android emulator (dev client Debug + Metro): petugas setoran/belanja/transfer/verifikasi/tautan skema tagihan/bayar dari tabungan; wali dua anak/notifikasi/pencabutan/latar; data aplikasi + logcat; pengalih aplikasi | seluruh langkah sesuai harapan (log berlangkah, bukan suite otomatis) | `bukti/android-petugas.txt`, `bukti/android-wali-privasi.txt`, `tangkapan/android-*.png` |
| iOS simulator | **belum diuji** (build gagal, lihat kriteria) | — |

Langkah Android memakai pembantu `adb`/uiautomator di luar repo (`/private/tmp`), langkah dan hasil tercatat di berkas bukti. Kontrol yang tercatat selama implementasi (bukan bukti akhir): kegagalan awal suite HTTP adalah asumsi uji (`sbx_ortu_a` ternyata wali santri V1 sehingga `sumber: wali`; kuitansi juga muncul di dialog koreksi tersembunyi sehingga dihitung per baris `<tr>`; notifikasi tagihan pertama milik anak lain), diperbaiki pada uji. Uji Expo web pertama gagal pada label kolom cari (`SearchField` tanpa `accessibilityLabel` — ditambahkan di layar keuangan) dan pada asumsi tombol sesudah sukses (formulir kini mempertahankan pilihan jenis/cara); simulasi offline dibuat lewat penangan rute karena `context.setOffline` tidak memutus permintaan yang diteruskan penangan.

**Kesalahan lingkungan uji yang diulang:** putaran regresi pertama mencatat `v6_phase2_audit` 22/8. Penyebabnya pekerja `PHP_CLI_SERVER_WORKERS` yatim dari uji HTTP Fase 4 masih mendengar port 8993 — port bawaan probe audit Fase 2 — sehingga permintaan probe dijawab server lain berbasis DB berbeda. Proses dimatikan, uji Fase 4 dipindah ke port 8979 dan kini mematikan pekerjanya saat selesai, lalu probe diulang: **30/0**. Keluaran yang salah disimpan sebagai `bukti/regresi/v6-fase2-probe-audit-bentrok-port-8993.txt`.

Cara mengulang (berurutan, dari akar repo web):

```bash
MOBILE_COPY=/private/tmp/<salinan alhasanApps tanpa .env*> bash docs/phase-v6-4/perintah-uji.sh
KPSB_DIR=/private/tmp/<privat> bash docs/phase-v6-4/perintah-regresi.sh
```

Salinan mobile: `cp -cR ~/alhasanApps <salinan> && rm -f <salinan>/.env.local <salinan>/.env` (berkas `.env.local` menunjuk API produksi dan tetap terbundel walau `EXPO_NO_DOTENV=1`); untuk versi ter-commit jalankan `git checkout -- package.json package-lock.json && npm ci` **di salinan saja**.

## Regresi

Ringkasan `bukti/regresi/ringkasan.txt`, keluaran per suite di `bukti/regresi/`.

| Rangkaian | Hasil | Catatan |
| --- | --- | --- |
| V6 Fase 3 integrasi / HTTP / probe audit / probe tengah malam / migrasi / browser | 137/0 · 66/0 · 27/0 · 9/0 · 35/0 · 89/0 | Pemberitahuan ikut terantre pada DB uji; hitungan uang/baris/audit tidak berubah. |
| V6 Fase 2 integrasi / HTTP / probe audit / probe era 027 / browser | 179/0 · 110/0 · 30/0 · 10/0 · 76/0 | Probe audit 30/0 sesudah bentrok port diatasi (lihat atas). |
| V6 Fase 1 integrasi / probe audit / browser | 120/0 · 37/0 · 59/0 | |
| V6 Fase 1 HTTP | 96/8 | Oracle historis R-2 Fase 3 (transfer tanpa centang kini menunggu) — identik dengan bukti Fase 3. |
| V6 Fase 1 / Fase 2 migrasi | 19/10 · 22/14 | Oracle historis (027/028 dianggap migrasi terakhir) — identik dengan Fase 3; Fase 4 tanpa migrasi. |
| Keamanan PSB HTTP (Apache + php-fpm) / browser | 97/0 · 42/0 | |
| Fondasi penugasan / V2 Fase 4 / V2 Fase 5 (notifikasi) | 161/0 · 124/0 · 143/0 | Dispatcher dan pusat notifikasi berubah aditif. |
| V5 Fase 5 / V5 Fase 3 integrasi (worker Push kesehatan) | 95/0 · 82/0 | |
| HTTP Tahap 5 / Tahap 7 | 106/0 · 122/0 | |
| HTTP Tahap 6 sesudah 029 → 024 dilepas | 33/0 · 132/0 | |
| HTTP Tahap 2 / Tahap 3 | 185/6 · 166/1 | Kegagalan identik baris per baris dengan Fase 3 (oracle menu BB/BC/BD/BE; AG). |
| Browser kerangka sembilan akun × 375/768/1280 | 91/0 | Termasuk pusat notifikasi dengan chip Keuangan baru. |
| Seluruh 38 suite statis | 19 exit 0 · 17 exit 1 · 2 exit 77 | Audit Fase 3: 19 · 16 · 2 dari 37. Tambahan `v6_phase4_static` 22/0 (exit 0); `v6_phase3_static` kini oracle historis (exit 1). Lihat tabel di bawah. |

### Oracle historis yang gagal (tidak diubah, tidak dilonggarkan)

Dibandingkan baris per baris dengan `docs/phase-v6-3/audit-codex/regresi/` (bukti auditor Fase 3):

| Suite | Kegagalan baru | Sebab |
| --- | --- | --- |
| `v6_phase1_static` | "Kode Auth, PSB, V2–V5, master data, dan **notifikasi** tidak diubah" | Fase 4 mengubah `app/Notification/*` secara aditif (tautan, kategori, label, pemeriksaan worker) — diwajibkan PRD §3.6/Fase 4 persyaratan 5. |
| `v6_phase2_static` | Sama ("…penugasan, master data, dan notifikasi tidak diubah"); daftar "berkas di luar Fase 2" bertambah berkas Fase 4 | Sama. |
| `v6_phase3_static` (57/0 → 55/2) | "…notifikasi tidak diubah"; "Berkas berubah hanya dalam daftar Fase 3" | Sama; berkas Fase 4. |
| `redesain_web_tahap6_static` (139/2 → 140/1) | Tidak ada; satu oracle **kini lulus secara kebetulan** | "Navigation dan app/Auth tidak diubah" membandingkan dengan `origin/main`, yang kini memuat Fase 3; bukan perbaikan. |
| `v2_phase5_static` | Tidak ada (kegagalan sama) | Membaca checkout mobile; checkout kini di branch Fase 4 dengan perubahan paket milik pekerjaan lain. |
| Suite statis lain exit 1 | Tidak ada | Identik dengan audit Fase 3. `v3_phase2/3_static` exit 77 (DB V3 bernama tetap). |

**Tidak dijalankan ulang** (dicatat, bukan disembunyikan): V5 Fase 1–2 dan 4 integrasi/HTTP/migrasi, V5 Fase 3 HTTP/migrasi, V5 Fase 5 HTTP/rilis, Tahap 8 HTTP/browser, GET-mutasi/cleanup HTTP, suite V3/V4 ber-DB tetap, browser V3/V4/V5, uji Expo web/Android modul kesehatan V5 (navigator kesehatan dan berkasnya tidak diubah; `keuangan/_layout.tsx` memakai pola yang sama secara terpisah).

## Penafsiran yang perlu ditinjau auditor/Human Developer

- **S-1 Pemberitahuan diaktifkan pada Fase 4.** PRD §3.6 mewajibkan pemberitahuan umum dan R-11 Fase 3 menunda ke Fase 4–5; Fase 4 persyaratan 5 memuat aturannya. Penerima hanya orang tua/wali (tidak ada pemberitahuan untuk petugas atau admin). Revisi/pembatalan tagihan **tidak** diberitahukan (bukan "tagihan terbit" atau "transaksi selesai"); orang tua melihat perubahan saat membuka aplikasi. Pemberitahuan diantre di dalam transaksi uang (pola V5): kegagalan tabel outbox membatalkan mutasi uang — dipilih agar replay dan kiriman tepat satu kali; alternatif sesudah commit membuka kemungkinan pemberitahuan hilang.
- **S-2 Bayar dari tabungan = satu pemberitahuan pembayaran**, bukan dua (debit + pembayaran); pembatalannya satu pemberitahuan "pembaruan pembayaran" (pengembalian tidak terpisah).
- **S-3 Worker Push** menolak Push "pembayaran tercatat" bila pembayaran sudah dibatalkan sebelum terkirim, dan Push "transaksi tabungan"/"tagihan" bila santri tidak aktif atau relasi dicabut. InApp yang sudah tercatat tetap ada di kotak notifikasi wali (teksnya generik; membuka tujuan memeriksa ulang hak).
- **S-4 Tarif, penyesuaian per santri, dan penugasan BSA tetap di website.** PRD Fase 4 menyebut daftar, detail, transaksi, tagihan, tabungan, riwayat, laporan; aplikasi menyediakan penerbitan massal (pratinjau berhash), revisi/batal tagihan, seluruh transaksi/verifikasi, dan rekonsiliasi. Penugasan admin tetap lewat Pusat Penugasan web (tidak ada API penugasan BSA).
- **S-5 Laporan di aplikasi** = tunggakan, mutasi, daftar pembayaran, dan rekonsiliasi harian yang sudah ada; laporan per periode/per santri dan ekspor tetap Fase 5. Orang tua tidak membuka tunggakan/mutasi lintas santri (server 403 untuk mutasi/rekonsiliasi; daftar pembayaran anak sendiri tersedia).
- **S-6 Nominal** dikirim sebagai teks angka; aplikasi hanya menghapus titik ribuan dan awalan "Rp"; pecahan/huruf diteruskan agar server menolak dengan pesan baku. Aplikasi tidak menghitung saldo/sisa.
- **S-7 Rincian santri bagi admin/petugas menampilkan referensi setoran transfer** (sama dengan website internal); orang tua tidak pernah menerimanya dari server.
- **S-8 Wali tanpa relasi santri V6 tetapi wali santri lain** (`sbx_ortu_a`) mendapat `sumber: wali` dan daftar anaknya sendiri (perilaku Fase 1).

## Temuan

- **T-1 (koreksi Fase 4) — cuplikan pengalih aplikasi Android memuat data keuangan.** Pada build awal, Recents menampilkan nama santri, NIS, dan saldo (`tangkapan/android-recents-sebelum-perlindungan.png`); penutup JS saat tidak aktif terlambat untuk cuplikan Android (sama dengan T-1 V5). Navigator `keuangan` kini memasang `expo-screen-capture` (`preventScreenCaptureAsync`, iOS `enableAppSwitcherProtectionAsync`) **sebelum** layar mana pun dimuat, gagal tertutup bila modul tidak ada, dan melepasnya saat keluar. Emulator: jendela ber-`FLAG_SECURE` di pintu keuangan, Recents hitam (`…-sesudah-perlindungan.png`), flag lepas kembali di Beranda. Akibat: tangkapan layar/rekaman layar dicegah di seluruh layar keuangan (termasuk kuitansi) — **Keputusan Human Developer 26 Oktober 2026 (T-1): perlindungan layar `expo-screen-capture` pada navigator Keuangan dipertahankan** (bukan temuan terbuka); kuitansi cetak/PDF tetap dari website. Tanpa dependensi baru (modul sudah dipasang V5); binary lama tanpa modul tidak memuat data keuangan.
- **T-2 (koreksi Fase 4, kode Fase 2) — transfer menunggu terlihat orang tua lewat jumlah.** Lihat Web butir 4; diuji `http.txt` N-D/N-G dan kontrol baseline (gagal di `1f2dbca`, lulus sesudah koreksi).
- **T-3 (batas lingkungan) — `package.json` mobile belum di-commit.** Dev client, Pods, dan bundel bergantung pada versi paket. Uji checkout memakai 57.0.31; Expo web akhir dan tsc/unit diulang di salinan ber-`npm ci` 57.0.15 (ter-commit). Android emulator diuji dengan bundel 57.0.31 di atas APK lama. Human Developer perlu memutuskan nasib perubahan paket tersebut sebelum build rilis.

## Batas yang belum diuji / terbuka

- **iOS** (simulator dan fisik), **Android fisik**, **Push provider nyata/ketukan Push di perangkat**, cold start dari Push/tautan pada build non-dev — belum diuji. Ketukan notifikasi diuji lewat kotak notifikasi InApp (Expo web, Android) dan tautan skema saat sesi aktif (Android).
- Tidak ada build native baru: Android memakai dev client Debug APK 24 Oktober 2026; iOS gagal dibangun (linker). Rilis memerlukan build baru oleh Human Developer.
- MySQL/cPanel/produksi belum diuji (lokal MariaDB 12.3.2). Konkurensi HTTP diuji dengan `PHP_CLI_SERVER_WORKERS=4` dan `curl_multi` pada pola dua/tiga/empat permintaan identik, bukan seluruh interleaving.
- Pembaca layar (TalkBack/VoiceOver) tidak diuji; label aksesibilitas diperiksa lewat pencari Playwright dan uiautomator.
- R-10 Fase 3 (saldo santri nonaktif/keluar) tetap terbuka — aplikasi hanya membaca santri nonaktif bagi admin; petugas tidak dapat memutasi.
- **Risiko urutan rilis** (sama dengan Fase 1–3): menggabung branch ini ke cabang deploy lalu `migrate up` memasang 027–029, mengaktifkan menu Keuangan dan kini juga pemberitahuan keuangan kepada orang tua. Belum ada sakelar rilis. Aplikasi menampilkan ubin Keuangan hanya bila `/v6/bsa/akses` memberi hak baca (server tanpa 027 → `503` → ubin tersembunyi).

## Langkah rilis dan pemulihan

Tidak ada migrasi baru; langkah Fase 1–3 tetap. Pemberitahuan memakai tabel `notifikasi_outbox` V2 dan sakelar kanal yang ada (Push hanya bila dinyalakan admin). Pemulihan: kembalikan kode; baris outbox V6 yang sudah ada tetap tampil generik di kotak notifikasi wali dan tautannya ditolak/diperiksa ulang oleh halaman tujuan. Aplikasi: tidak ada perubahan `app.json`/`eas.json`/SDK; build baru dibutuhkan hanya untuk binary yang belum memuat `expo-screen-capture`.

## Hash

| Butir | Nilai |
| --- | --- |
| Baseline web (commit audit Fase 3) | `1f2dbca574805954566305789e589540e27348f0` |
| `origin/main` web saat mulai | `4502233` (memuat baseline; diff kosong) |
| Baseline mobile (`origin/main`) | `f8ebba0a43493efde50a7a207933e86dc4b941d4` |
| Commit implementasi web | `9754be56d1d361a3830de5eeac06cb5b787285ac` (`feat(v6): Fase 4 paritas aplikasi, pemberitahuan wali, dan privasi wali`) |
| Commit implementasi mobile | `950ca355106600c791c34d977be94debf0174fbc` (`feat(v6): Fase 4 paritas aplikasi keuangan santri/BSA`) |
| Commit dokumen hash ini | commit berikutnya di branch web yang sama (`docs(v6): hash …`) |
| Migrasi | tidak ada; SHA-256 027–029 tidak berubah dari Fase 3 |

## Handoff ke auditor

> *"Audit hanya Fase 4 PRD V6 pada branch `claude/prd-v6-fase-4` di WebAlHasan dan alhasanApps sesudah Claude Code berhenti. Baca AGENTS.md web/mobile, PRD-V6.md, audit Fase 1–3, `docs/phase-v6-4/README.md` kedua repo, baseline web `1f2dbca` dan mobile `f8ebba0`, serta seluruh commit fase. Uji silang paritas web/API/aplikasi untuk BSA, admin, wali satu anak/dua anak, wali asing, serta pencabutan relasi atau penugasan saat sesi lama masih aktif. Uji satu transaksi dari aplikasi terlihat sekali di web, retry jaringan tidak menggandakan, konflik dan error tampil jelas, serta log/cache tidak memuat bukti transfer atau token tanpa keperluan. Tinjau pemberitahuan V6 (S-1…S-3), koreksi privasi wali T-2, dan perlindungan layar T-1 (dipertahankan atas keputusan Human Developer 26 Oktober 2026). Jalankan Android dan iOS sesuai build/perangkat yang tersedia; tandai emulator, perangkat fisik, Push dan cold start secara jujur. Koreksi temuan terarah dengan regresi, tulis `docs/phase-v6-4/AUDIT-CODEX.md` dan ringkasan mobile bila perlu, commit/push kedua branch yang berubah, lalu berhenti. Jangan mulai Fase 5, merge, deploy, atau merilis aplikasi."*

Implementator berhenti di sini: tidak merge, tidak deploy, tidak migrasi produksi, tidak merilis aplikasi, tidak memulai Fase 5.
