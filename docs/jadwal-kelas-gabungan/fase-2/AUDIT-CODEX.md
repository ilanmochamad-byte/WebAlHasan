# Audit Codex — Jadwal pengajian kelas gabungan, Fase 2

Tanggal: 1 November 2026 (Asia/Jakarta).

## Keputusan

**BELUM LULUS penuh Fase 2: alur fungsional lulus pada lingkungan uji, tetapi build Release iOS baru masih crash saat startup (F-1).** Binary audit lama dengan bundel JavaScript/Hermes Fase 2 hanya menjadi bukti fungsional terbatas, bukan pengganti validasi build dari sumber terkini. Tidak ditemukan kebutuhan koreksi kode bisnis/tampilan Fase 2 dari pengujian di bawah.

**Fase 1 dan Fase 2 harus dinilai sebagai satu kesatuan rilis. Keduanya tidak dinyatakan siap produksi atau boleh dirilis terpisah.** Keputusan merge, deploy, migrasi dan rilis aplikasi tetap Human Developer. Tidak ada tindakan tersebut pada audit ini.

## Sumber, baseline, dan batas perubahan

- Membaca `AGENTS.md` kedua repositori, laporan audit Fase 1 `9075522`, kontrak `/api/v1` (`docs/api-v1.md`) beserta tambahan `classes`/kelas asal pada README Fase 1/2, handoff dan bukti implementator, seluruh diff commit Fase 2, serta skrip uji.
- Mobile: branch `claude/jadwal-kelas-gabungan-fase-2`, baseline `b8d182529487d82e648c39e78830c64b5e375363`, implementasi `e1fd8a480afb38fcabdbb865ca5e1db232fc8e71`, dokumentasi `586964cd0f1f61cf241515f9b91f2daeda9e6d78`.
- Web: branch bernama sama, baseline `9aa29562fedc951cebf2dbc0bd954387b2709073` (isi identik audit Fase 1 `907552204e6f3dbb1684b7988fe8b10ff09f6f94`), commit Fase 2 `bfb5bc7fd50c2d815588574f09cdbb96c9b8f669` hanya uji/dokumen. Backend yang diuji tetap kode hasil audit Fase 1; auditor tidak mengubah backend, skema, migrasi atau hak akses.
- Ujung remote kedua branch cocok handoff ketika diperiksa. Remote `main` sudah bergerak ke web `5c06722` dan mobile `a1b1428`; audit tetap menggunakan baseline dan branch yang diminta, tidak mengasumsikan isi `main` baru sebagai sumber pengujian.
- Handoff implementator sudah ter-commit/ter-push; tidak menjalankan Claude atau subagen, tidak membuat worktree. Proses residen Claude di komputer bukan bukti sedang mengimplementasikan; tidak teramati perubahan sumber aplikasi selama audit.
- Perubahan paket mobile milik pekerjaan lain tetap dipertahankan dan tidak diuji sebagai paket fase ini. Arsip HEAD mobile di `/private/tmp/jkg2-codex-mobile` tanpa `.env*` selain `.env.example`, memakai direktori nyata `node_modules` dari lock identik ter-commit (Expo 57.0.15). Kontrol baseline memakai arsip terpisah. [Dokumentasi Expo v57](https://docs.expo.dev/versions/v57.0.0/) diperiksa; tidak ada dependensi/modul native baru.
- Empat direktori web milik pekerjaan lain tidak disentuh/dimasukkan commit. Semua data uji sintetis dalam DB `test_jkg_f2_codex_test`, `test_jkg_f2_reg_codex_test`, dan `test_jkg_f2_ios_codex_test`; tidak memakai data atau dump produksi. Snapshot fixture hanya disimpan privat di `/private/tmp`, tidak di Git.

## F-1 — P2 — Build Release iOS baru crash saat startup (terbuka)

Auditor menginstal dan menjalankan artefak Release arm64 simulator buatan implementator dari `jkg2-ios/dd/Build/Products/Release-iphonesimulator/alhasanApps.app`. `src/` dan lock dibandingkan dengan arsip HEAD mobile dan cocok. Executable SHA-256 `fc02342935b37d7737daa691bb8b585290b6817279ae448154a05c3ff2850b0b`; bundel mengarah ke API lokal, tanpa host produksi.

Pada iPhone 17 Pro simulator **iOS 26.2**, aplikasi berhenti dengan `RCTFatalException`, `RCTEventEmitter.receiveEvent()`, dan `Module has not been registered as callable`. Bukti: [`ios-original-crash.txt`](audit-codex/ios-original-crash.txt). Ini mengonfirmasi batas yang sudah diungkap implementator. Auditor tidak membangun ulang native iOS baru dan tidak menetapkan akar penyebab dari log ini.

Kontrol fungsional memakai executable audit lama yang checksum-nya cocok handoff (`3a39fbea…e13d82e9` sebelum penandatanganan ulang), seluruh resource aplikasi lengkap, ditambah bundel **Hermes bytecode v98** dari arsip HEAD yang dibangun ulang auditor. Ekspor final menggunakan `--reset-cache`, port khusus fixture iOS 8964, kemudian ditandatangani ulang ad-hoc. Metadata/checksum final di [`metadata.json`](audit-codex/metadata.json). **Ini binary hibrida untuk simulator; bukan build Release baru dari seluruh sumber Fase 2 atau build App Store.**

Syarat penutupan: build iOS utuh dari sumber dan lock yang akan dirilis harus dapat cold start dan menyelesaikan jadwal gabungan/satu kelas, pembukaan, retry, absensi dan pembukaan ulang pada simulator/perangkat yang ditargetkan tanpa menukar executable lama. Jangan menutup temuan hanya dengan keberhasilan `xcodebuild` atau penggantian bundel pada binary lama. Diagnosis/perbaikan toolchain harus dibuktikan, bukan diasumsikan sebagai kesalahan lingkungan.

## Pengujian independen

Lingkungan: PHP 8.4.14, MariaDB 12.3.2 lokal, Node 26.7.0; seluruh akses aplikasi diarahkan ke loopback. Bukti mentah auditor ada di [`audit-codex/`](audit-codex/), terpisah dari bukti implementator.

| Uji | Hasil | Bukti |
| --- | --- | --- |
| Unit seluruh aplikasi, termasuk pengajian/absensi dan helper kelas | **53/0** | `unit.txt` |
| TypeScript, lint resmi Expo pada sumber/paket ter-commit | **exit 0, exit 0** | `tsc-final.txt`, `lint-final.txt` |
| Alur penuh Expo web + API/DB nyata, suite implementator dieksekusi auditor | **57/0** | `app-web.txt` |
| Probe tambahan auditor: 409, riwayat kelas, seluruh status/catatan, akses, expiry | **15/0** | `probe.txt`, `tests/browser/uji-jadwal-kelas-gabungan-f2-audit.mjs` |
| Kontrol satu kelas pada kode kini | **5/0** | `current-single.txt` |
| Kontrol baseline mobile: gabungan / satu kelas dengan assertion sama | **1/2**, berhenti di kelas tambahan / **5/0** | `control-base-full.txt`, `control-base-single.txt` |
| Regresi aplikasi Keamanan (rincian kehadiran ikut berubah) | **46/0** | `reg-app-keamanan.txt` |
| Android emulator, dev client Debug yang sudah terpasang + Metro HEAD | **24/0** | `android.txt`, `android-proxy.jsonl` |
| iOS 26.2 simulator, binary Release audit lama + Hermes HEAD buatan auditor | **10/0**, bukti fungsional terbatas | `ios.txt`, `ios-proxy.jsonl`, `ios-flows/` |
| Hasil kiriman iOS → DB, REST laporan, HTML admin dan CSV | **4/0** | `ios-report.txt`, `verifikasi-native-laporan.py` |
| V1 jadwal/pertemuan, API absensi, laporan/ekspor | **10/0 · 14/0 · 20/0** | `reg-v1-phase3.txt`, `reg-v1-phase4.txt`, `reg-v1-phase5.txt` |
| Backend gabungan Fase 1: integrasi/konkurensi dan HTTP | **71/0 · 32/0** | `reg-f1-integration.txt`, `reg-f1-http.txt` |
| Statis Fase 1 asli | **46/1**, oracle batas fase historis | `reg-f1-static.txt` |

Satu kegagalan statis adalah larangan perubahan di luar daftar berkas Fase 1; Fase 2 menambah pengujian. Oracle asli tidak dilonggarkan. Tidak menjalankan ulang seluruh suite statis/V2–V7; tidak mengklaim semua regresi repositori hijau.

### Invarians dan hasil lintas aplikasi–web–API–laporan

- Beranda, daftar, label aksesibilitas, detail jadwal dan pertemuan menampilkan **PA + PI**; layar tidak membuka pertemuan otomatis. Tiga santri PA dan dua santri PI tampil dengan kelas asal serta urutan kelompok; santri berkeanggotaan Selesai tidak ikut.
- Klik ganda saat permintaan berlangsung hanya mengirim satu POST/PUT. Jawaban sengaja hilang **setelah server commit**; kirim ulang menggunakan kunci yang sama. Hasil tetap satu pertemuan jadwal–tanggal, dua kelas beku, lima peserta unik, **satu absensi guru + lima absensi santri**, tanpa baris tambahan.
- Android adalah **emulator Android 16 / API 36, `sdk_gphone16k_arm64` (AVD Medium_Phone)**, binary `DEBUGGABLE`, versi 1.0.0, dev client yang sudah ada dengan Metro terbaru; auditor tidak membuat APK baru. Pada penutupan koneksi tanpa jawaban, OkHttp mengirim ulang POST; dua kiriman tetap berkunci sama dan hanya membuat satu pertemuan. Pengujian 503 setelah commit juga membuktikan retry manual.
- Pada **iPhone 17 Pro simulator iOS 26.2**, binary kontrol dengan bundel HEAD membuktikan kedua kelas, lima peserta/kelas asal, ketukan ganda pembukaan, retry POST/PUT sesudah 503 setelah commit, satu guru + lima santri, koreksi wajib alasan, satu kelas dan token kedaluwarsa. Verifikasi akhir HTTP terpisah mencocokkan DB, REST laporan, HTML admin dan CSV **4/0**. Guru di luar cakupan diuji pada Expo web/REST, tidak diulang pada simulator. Ini tidak menutup F-1.
- Suite penuh membandingkan hasil aplikasi dengan laporan admin web, rincian kehadiran web, daftar pertemuan, **CSV satu guru + lima santri**, REST laporan dan rincian aplikasi/Keamanan. Status hasil koreksi dan kelas asal cocok; satu jadwal hanya mempunyai satu ringkasan laporan.
- Probe auditor mengisi lima status berbeda (`Hadir`, `Terlambat`, `Izin`, `Sakit`, `Alpa`) dan catatan ID berbeda untuk kelima santri, serta guru Terlambat. API pertemuan dan rincian laporan mengembalikan status/catatan pada ID yang tepat setelah pengelompokan.
- Perangkat lain membuka pertemuan ketika layar masih menampilkan belum dibuka: POST aplikasi mendapat **409**, status dimuat ulang menjadi **Isi absensi**, lalu membuka ID yang sama tanpa POST tambahan. Setelah selesai, tombol menjadi **Buka absensi tersimpan**, tetap tanpa pembukaan baru; koreksi tanpa alasan tidak mengirim, koreksi beralasan berkunci baru memperbarui baris.
- Setelah pertemuan PA+PI dibuka, layanan resmi mengganti seluruh kelas jadwal ke C. Detail jadwal mengikuti C; judul/peserta pertemuan dan laporan aplikasi tetap PA+PI melalui `task.classes`. Field kompatibilitas `task.class` kini C tidak menimpa kelas beku.
- Jadwal satu kelas mempertahankan tampilan lama dan satu guru + dua santri. Bila server tidak mengirim `classes`, detail tetap membaca kelas utama; tidak ada pembukaan otomatis atau aturan peserta di perangkat.
- Guru lain ditolak pada jadwal/pertemuan, POST pembukaan dan PUT absensi tanpa perubahan data. Token dicabut (suite penuh) maupun **`expires_at` benar-benar dibuat lampau** (probe tambahan) mengarahkan kembali ke login tanpa perubahan absensi.
- Keadaan memuat, GET 5xx setelah retry bawaan dan Coba lagi diuji. Konten Expo web pada 375 px tidak meluap; TF4-1 bilah tab web lama tetap dicatat dan dikecualikan dari ukuran konten. Bukan klaim seluruh layar pada seluruh ukuran/perbesaran diuji.

Penafsiran JK2-1…JK2-6 diterima dalam lingkup ini: satu kelas tetap tampilan lama, label koma/utama dulu, rincian baca laporan/Keamanan ikut benar, penjaga ketukan ganda, refresh 409, dan urutan peserta sesuai kelas lalu urutan server. Semuanya hanya cermin kontrak backend; hak, peserta dan konsistensi tetap ditegakkan server.

## Diagnostik pengujian

- Percobaan browser pertama dimulai sebelum fixture selesai sehingga berkas ID belum ada; dijalankan ulang penuh setelah penyiapan selesai (**57/0**).
- TypeScript pertama sebelum Expo menghasilkan tipe CSS/env gagal menemukan dua deklarasi CSS. Sesudah dev server membuat tipe Expo, TypeScript dan lint pada arsip yang sama exit 0; tidak ada perubahan sumber untuk membuatnya lulus.
- Seed Fase 2 bukan seed berulang: menjalankannya kedua kali pada DB yang sudah dipakai bertabrakan dengan penugasan Keamanan. Semua putaran berikut dimulai dari snapshot fixture Fase 1 bersih, bukan mengabaikan konflik bisnis.
- Probe perubahan kelas awal memakai jam SQL `HH:MM:SS`; layanan benar menolak karena kontrak `HH:MM`. Penyiapan probe diperbaiki, fixture diulang, lalu **15/0**. Bukti awal `probe-setup-time-format.txt` dipertahankan.
- Regresi HTTP Fase 1 pertama memakai DB yang sudah dimutasi suite integrasi; gagal **4/18** lalu berhenti pada respons null. Sesudah restore fixture bersih sebagaimana prasyarat suite, suite asli lulus **32/0**. Log awal `reg-f1-http-fixture-terpakai.txt` tidak dihapus atau dihitung sebagai regresi produk baru.
- Salinan awal binary kontrol iOS dari direktori build lama ternyata kehilangan resource storyboard; salinan uji itu crash berbeda dari F-1. Diganti dengan salinan lengkap yang executable-nya cocok checksum handoff. Ekspor iOS pertama pada port berbeda masih memakai cache transform port lama; terdeteksi melalui inspeksi bundel dan log backend, kemudian ekspor diulang `--reset-cache`. Hasil sebelum koreksi alat tidak dipakai sebagai bukti fungsional iOS final.
- Pemilih teks Maestro awal tidak mencakup jenjang di belakang nama kelas; assertion diperbaiki mengikuti label utuh yang terlihat pada hierarchy/tangkapan. Lokasi screenshot juga disesuaikan ke aturan folder keluaran Maestro. Ini koreksi penggerak audit, bukan perubahan aplikasi atau pengabaian assertion bisnis.
- Ketukan Maestro awal memilih judul panel Buka pertemuan yang berteks sama dengan tombol. Hierarchy menunjukkan dua posisi berbeda; penggerak diarahkan ke tombol bilah aksi yang terlihat (50%,92% pada simulator ini), lalu pembukaan/retry benar-benar mencapai proksi. Hasil iOS final menggabungkan login yang sudah lulus dengan kelanjutan `IOS_SKIP_LOGIN=1` pada fixture/sesi sama; ini bukan klaim satu eksekusi skrip dari awal tanpa penyesuaian alat.
- Bukti teks dinormalisasi hanya pada spasi akhir baris untuk pemeriksaan whitespace Git; hasil assertion tidak diubah. Log diagnostik awal penting dipertahankan terpisah dari hasil final.

## Gerbang rilis dan handoff

- **F-1 tetap terbuka.** Android Release/distribusi, iOS Debug dan build distribusi bertanda tangan/provisioning produksi belum diuji. Semua native di audit ini adalah emulator/simulator; **tidak ada perangkat fisik**.
- MariaDB 10.6.28 produksi, MySQL/cPanel/hosting, salinan produksi, migrasi 034 di produksi, UAT dan smoke pada kode yang benar-benar terpasang belum dibuktikan. Preflight rilis historis yang masih menganggap 033 migrasi terakhir harus dinilai/disesuaikan untuk paket rilis 034; tidak ditutup oleh pengujian ini.
- Rencana rilis harus memasangkan backend sadar 034 dan aplikasi Fase 2, memverifikasi preservasi/backup dan pemulihan. Larangan kembali ke backend lama setelah jadwal gabungan ada pada audit Fase 1 tetap berlaku; jangan melewatkan peserta kelas tambahan.
- Push nyata/ketukan/cold start Push, cold start Android rilis, aksesibilitas/perbesaran menyeluruh, serta gerbang produksi V5/V6/V7/penugasan terdahulu tetap terbuka.
- Auditor hanya menambah probe, bukti dan keputusan/status audit di branch fitur kedua repositori. Tidak mengoreksi kode aplikasi, dependensi atau skema. Commit dan push hasil audit, lalu berhenti; tindak lanjut F-1 dan keputusan merge/deploy/rilis berada pada Human Developer.
