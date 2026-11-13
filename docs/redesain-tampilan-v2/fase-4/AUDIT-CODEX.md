# Audit Codex — Fase 4 dan gerbang akhir redesain aplikasi gelombang 2

13 November 2026 · Kedua repo pada `claude/redesain-tampilan-v2-fase-4`

**Gelombang 2 belum lulus audit teknis lokal.** Fase 4 lulus matriks Expo web, paritas jaringan, dan pemeriksaan akses yang dirinci di bawah. Gerbang akhir masih terbuka karena skenario native pada teks besar, ketukan notifikasi sistem, bukti pengalih aplikasi, dan sebagian regresi belum lulus atau belum terbukti. Selisih terhadap desain yang disetujui juga masih ada. Keputusan merge, deploy, dan rilis tetap pada Human Developer.

## Baseline dan cakupan

| Repo | Baseline `main` | Handoff Claude Code |
| --- | --- | --- |
| WebAlHasan | `ef40e8de3e316edd00bd200a4bbff0aa7e7520af` | `2531cab5817ead2c9487bf5417ea333a65a3ec32` |
| alhasanApps | `dfba0ade78844ffee5163605c1a5ab43a4aff9d3` | `d152ba1a5128f1adcf8bb8b8c9a4b4b93f0983eb` |

Keempat referensi cocok dengan `git ls-remote`. Baseline mempunyai pohon yang sama dengan akhir audit Fase 3 (`c3b3dfe` web, `f2b0358` aplikasi). Audit merujuk AGENTS.md kedua repo, PRD V3/V4/V5, rancangan Keamanan, [rencana gelombang 2](../rencana.md), audit Fase [1](../fase-1/AUDIT-CODEX.md), [2](../fase-2/AUDIT-CODEX.md), [3](../fase-3/AUDIT-CODEX.md), dan satu commit handoff di setiap repo. Diff web berisi pengujian dan dokumentasi. Kode API, otorisasi, skema, klien API mobile, dan penyedia notifikasi sama dengan baseline. Versi pengguna naik sekali per fase dari **1.4.0 → 1.5.0 → 1.6.0 → 1.7.0 → 1.8.0**. `npm run versi:periksa` lulus. Manifest dan lockfile ter-commit identik sepanjang gelombang. Edit paket yang sudah ada di checkout dan direktori web pekerjaan lain berada di luar commit audit.

## Temuan dan koreksi

| Temuan | Hasil audit |
| --- | --- |
| Bilah tab dan FAB Expo web | Handoff pada 375 px melebar menjadi 408 px dan tinggi tab hanya 26 px. Bilah dipindah ke bawah dalam alur tata letak, label merek duplikat dihapus, dan lebar tab dibagi rata. Lebar dokumen kembali 375 px, tab sekitar 80 × 44 px, FAB 56 px dengan jarak 16 px dari tepi kanan dan dari wadah bilah. Pemeriksaan 768/1280 px lulus. Dengan pembesaran teks CSS 200%, tab menjadi 48 px dan tetap muat. FAB melebar menjadi 207 px pada 375 px karena pembesaran CSS tidak mengubah `fontScale`; alternatif tombol atas pada skala sistem native menggunakan aturan lama. |
| Grafik dan legenda kehadiran | Batang yang belum dipilih semula berkontras 1,12:1 pada terang dan 1,99:1 pada gelap. Batang memakai token `textSecondary`, target minimal 44 × 44, kolom bisa membungkus, dan nilai bisa memakai beberapa baris. Legenda kehadiran memakai ikon status yang sudah ada. Token status tetap sama. |
| Keputusan Fase 4 | Dropdown wajib untuk kerahasiaan dipertahankan sesuai keputusan PRD V3 §5.5b. Menu Kesehatan staf tidak membuat permintaan baru untuk angka. Pembungkus pil pada daftar sesuai lebar sempit. Rincian Pembinaan belum memakai tab Ringkasan/Sesi/Riwayat, alasan koreksi rapor masih di halaman, dan riwayat klinik berupa baris langsung. Perbedaan §4.6–§4.8 tetap dicatat. |
| K-1, K-5, K-6, K-9 | Tema mengikuti sistem, avatar santri memakai inisial, dan tidak ada onboarding pengguna. Logo teks gelombang 2 hanya di Beranda. Bagian lime logo tetap rendah kontras di krem dan aset memakai warna sumber yang disetujui. Ikon Izin/Terlambat pada kartu terang berkontras 4,26:1 dan 3,72:1, dengan label memakai warna teks. Inisial dekoratif tetap menggunakan pengali maksimum 1, sedangkan nama serta isi layar mengikuti skala teks. |
| K-2, K-3, K-4, K-7, K-8 | Empat tujuan tab bagi peran yang berhak, tanpa tombol + di tengah. Hak tab tidak berubah. Ringkasan Beranda tetap memakai data yang sudah dimuat; diff Fase 4 tidak menyentuh permintaan Beranda. Grafik tetap berbasis `View` dan memakai angka server. Header stack berjudul tengah dan menu sekunder hanya jika tersedia. FAB tetap terbatas pada izin. Koreksi web mempertahankan perilaku native. |

Bukti pengukuran tab [handoff](audit-codex/tab-web-handoff.jsonl) dan [koreksi](audit-codex/tab-web-koreksi.jsonl). Oracle Fase 1 kini memeriksa tab tanpa pengecualian luapan dan mengukur target. Oracle Fase 2 mengukur FAB terhadap tepi bilah dan menggulir wadah daftar yang sebenarnya. Paginasi admin dan pengurus terlihat di atas FAB dan tetap dapat diketuk. Selisih lama pada §4.1–§4.5, §4.9 dan §4.11 tetap merujuk audit terdahulu.

## K-10 dan Expo web

Inventaris independen diturunkan dari pohon `src/app`. Grup Expo Router, `index`, dan berkas layout dinormalisasi. Hasilnya **71 pola, 3 pengecualian** (Beranda, Masuk, Ganti password wajib), sehingga 68 pola wajib memiliki ←. Ketiga tab selain Beranda memakai tujuan Beranda. Daftar uji 75 alamat meliputi seluruh 68 pola dan beberapa variasi parameter, termasuk ganti password biasa. Matriks admin dan guru mapel **304/0** pada handoff dan bundel koreksi. Pada akses yang ditolak, layar galat tetap menyediakan ←. Tab izin tersembunyi untuk guru tanpa hak dialihkan ke Beranda. Dengan riwayat, daftar Pembinaan dan laporan Kesehatan kembali ke menu sebelumnya. Langkah pembuatan izin juga kembali ke pemilihan santri tanpa kehilangan pilihan. [Inventaris](audit-codex/rute-dari-pohon-git.txt) · [hasil matriks](audit-codex/k10-hasil.json).

Arsip Git terpisah tanpa berkas `.env*`, Expo 57.0.15 dari `npm ci --offline`, Node 26.7, PHP 8.4.14 dan MariaDB 12.3.2. Host browser di luar `127.0.0.1` diblokir pada suite fase dan jaringan. Perantara IPv4 ke Metro IPv6 hanya di loopback. Putaran pembuka sebelum perantara siap gagal tersambung pada dua suite dan diulang. Salinan beberapa oracle mengalihkan transport ke port fixture masing-masing, dengan asersi produk tetap. TypeScript lulus setelah Expo membuat deklarasi CSS; lint lulus. Unit baseline **104/0**, handoff dan koreksi akhir **113/0**.

| Suite atau probe | Hasil independen |
| --- | --- |
| Expo web Fase 4 Pembinaan/Rapor | 97/0 |
| Expo web Fase 4 Kesehatan, Keamanan, BSA, K-10 | 70/0, 60/0, 18/0, 304/0 |
| Rahasia Pembinaan pada tautan langsung | 5/0. Pemilik dan admin 200, murobi terkait/pembimbing lain/wali 403. Isi privat hanya muncul pada dua akun berhak. |
| V5 Fase 4 | 78/0. Wali melihat ringkasan tanpa keluhan/catatan klinis/obat; wali lain dan relasi dicabut 403. Penyimpanan browser dan 113 baris konsol lulus pemeriksaan penanda. |
| Regresi Expo web lama | Suite Fase 1 asli 159/0 dengan satu dilewati, pengajian Fase 2 68/0, profil 105/0, kelas gabungan 57/0, pencarian absensi 53/0, pencarian daftar 24/0, Keamanan Fase 4 46/0, dan V6 Fase 4 45/0. Regresi izin dengan oracle yang diperketat membuktikan posisi FAB, dan paginasi. Dua putaran regresi izin berhenti pada locator sebelum rangkaian selesai. Kembali antar langkah dan pilihan santri terbukti pada salah satu putaran. |
| Kontrol gelombang 1 | Tiga oracle historis dicoba pada fixture yang sama. Ketiganya berhenti pada penanda Masuk/Beranda dalam kontrol ini; hasil Fase 3 dari handoff (17/4) tidak berhasil direproduksi dengan fixture audit ini. |
| Kontrol V3 mobile lama | Baseline dan koreksi berhenti pada fixture katalog yang kurang dari 26 pilihan dan timeout sesudah simpan. Kegagalan portal `#case-privacy` yang dilaporkan Claude belum direproduksi secara independen. |
| V4 Fase 5 lama | Rantai ID/tokens dan DB fixture lama tidak tersedia. Jalur rapor diuji dengan fixture Tahap 7 di suite fase, perekam jaringan, dan native. |

Sebagian regresi aplikasi yang dilaporkan implementator masih belum mendapat hasil audit yang dapat dibandingkan, termasuk kelanjutan matriks BSA, V5 Fase 5, V7 Fase 4, dan perbaikan peserta absensi. Percobaan lokal pada subset menemukan fixture jadwal yang telah bermutasi serta path alat uji yang berbeda setelah penyalinan. Hasil tersebut tidak ditetapkan sebagai regresi aplikasi dan tidak dihitung lulus.

## Paritas jaringan dan hak

| Alur | Langkah sama / berbeda | Permintaan |
| --- | ---: | ---: |
| Pembinaan dan Rapor | 34 / 0 | 199 |
| Kesehatan | 20 / 0 | 104 |
| Keamanan | 11 / 0 | 41 |
| Total | **65 / 0** | **344** |

Setiap pasangan dimulai dari dump sintetis yang sama dan jam klien tetap. Log kanonik juga identik per byte pada tiap pasangan. Per langkah dibandingkan metode, path dengan ID literal, query, dan badan JSON. Sandi, kunci idempotensi acak, serta hash yang dihitung server disamarkan. CORS preflight dan header otorisasi tidak dihitung. Alur mencakup ketukan ganda, perubahan kasus/sesi, nilai dan sikap, hasil rujukan, ringkasan terbit/tarik, serta pembacaan Keamanan. [Pembinaan/Rapor](audit-codex/banding-pembinaan-rapor.txt) · [Kesehatan](audit-codex/banding-kesehatan.txt) · [Keamanan](audit-codex/banding-keamanan.txt).

Keamanan diuji sebagai pengurus dan guru dengan penugasan, dengan admin tanpa penugasan ditolak. Kontrol UI yang muncul terbatas pada navigasi, saringan dan muat ulang. Kasus Rahasia tersembunyi di daftar/pencarian Keamanan dan rincian ditolak seperti kasus tidak ada. Jalur Kesehatan/Keamanan tetap menunggu proteksi sebelum menampilkan isi native. Kerangka status proteksi memuat tombol kembali saat belum siap atau gagal.

## Native dan batas gerbang

- **iOS simulator, iPhone 17 Pro, iOS 26.2.** Host **Release 1.8.0 arm64** dari handoff, dengan bundel Hermes hasil ekspor auditor dan tanda tangan ad-hoc. Sembilan dari sepuluh alur asli lulus. Pembinaan dari tautan saat aplikasi mati kembali ke Beranda. Rapor dan Keamanan lulus terang, gelap, serta gelap AX-XXXL. Kesehatan lulus terang dan gelap, tetapi Maestro belum menemukan penanda privat pada guliran AX-XXXL walau rincian dan tombol kembali tampil. Ulang terarah dengan ambang keterlihatan 60% dan waktu lebih panjang menemukan penanda privat pada AX-XXXL. Gestur pengalih berakhir di layar klinik aktif sehingga cuplikan kartu iOS belum terbukti.
- **Android emulator, Medium_Phone, API 36.** Dev client **Debug 1.8.0** dari handoff + Metro sumber Fase 4. Pembinaan dan rapor pada skala normal, kembali sistem dan tautan saat berjalan terbukti. Rincian klinis bertanda privat dan tangkapan layar terlindungi. Keamanan **10/0** termasuk guru berpenugasan dan admin ditolak. Suite T7 **14/2**, Kesehatan **5/2**. Sesudah perubahan gelap + 2,0×, tautan kedua berhenti di Beranda sehingga rincian rapor dan laporan klinik pada skala itu belum terbukti. APK Android Release belum diuji independen. Pada handoff, Release bisa memulai aplikasi tetapi login loopback HTTP tertahan pembatasan cleartext.
- **Privasi log dan cuplikan.** Pemindaian log native terbatas pada penanda sintetis klinis, kasus, nama/NIS contoh, sandi uji dan bearer. Tidak ada kecocokan pada log yang disaring. Kartu Recents Android setelah membuka kunjungan sintetis berwarna hitam. Pengalih iOS dan kartu modul sensitif lainnya belum terverifikasi pada audit ini. [Pemindaian log](audit-codex/log-privasi-native.txt) · [Recents Android](audit-codex/android-klinik-recents.png). Ketukan notifikasi sistem, termasuk cold start, juga belum terbukti. URI dan notifikasi dalam aplikasi membuktikan alur navigasi yang diuji, dengan cakupan berbeda dari ketukan sistem.
- **Gerbang produksi terdahulu.** Perangkat fisik, build distribusi EAS, Push APNs/FCM nyata, UAT, MySQL/MariaDB produksi, cPanel, migrasi dan smoke, backup/pemulihan, serta gerbang V5/V6/V7 dan Keamanan tetap mengikuti audit masing-masing. Audit ini hanya memberi keputusan teknis lokal untuk gelombang 2.
