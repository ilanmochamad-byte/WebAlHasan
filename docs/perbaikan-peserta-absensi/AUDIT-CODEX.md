# Audit Codex — perbaikan peserta absensi

6 November 2026. **LULUS audit teknis lokal setelah koreksi F-1 (P2).**

## Dasar dan cakupan

| Repositori | Branch | Baseline | Implementasi Claude |
| --- | --- | --- | --- |
| WebAlHasan | `claude/perbaikan-peserta-absensi` | `71dbc00` | `6dead3e` |
| alhasanApps | `claude/perbaikan-peserta-absensi` | `e21d4ab` (1.3.0) | `724c170` (1.4.0) |

Auditor membaca AGENTS.md kedua repo, commit fase, keputusan 6 November dan handoff `README.md`, serta aturan jadwal/absensi PRD V1. Fetch kedua remote berhasil; `origin/main` masih baseline di atas saat pemeriksaan. Keputusan terbaru mengizinkan perubahan snapshot peserta melalui tindakan khusus selama Dibuka, dengan riwayat absensi tetap dipertahankan. Ini satu workstream, tanpa fase lanjutan, worktree atau subagen.

Seluruh data uji sintetis pada database lokal `test_*_test`. PHP 8.4.14, MariaDB 12.3.2, Node 26.7.0, Chromium/Playwright dan Expo web. Sumber aplikasi diuji dari `git archive 724c170` di `/private/tmp/ppa-codex-mobile`, tanpa `.env*`; `npm ci --ignore-scripts` memakai paket dan lock ter-commit. Sebanyak 312 berkas src/assets/paket/konfigurasi cocok dengan commit (`audit-codex/mobile-manifest.json`). Perubahan paket lokal milik pekerjaan lain tidak digunakan atau diubah.

## F-1 (P2) — perubahan peserta tetap tersimpan saat audit gagal, ditutup

Pada `ScheduleService::refreshParticipants()` dan `TeacherService::refreshParticipants()`, hasil boolean `AuditLogger::log()` diabaikan. Logger menangkap kegagalan INSERT dan mengembalikan false; karena itu transaksi tetap commit dan UI/API melaporkan sukses walaupun perubahan snapshot tidak mempunyai audit yang disyaratkan keputusan workstream.

Probe auditor memakai pertemuan terpisah untuk web dan API, memasang trigger penolak INSERT untuk tiga jenis tindakan pada fixture lokal dan menghapusnya dalam `finally`. Pada kode asli Claude, web menghapus peserta tanpa audit dan API tetap sukses; retry setelah audit pulih tidak lagi mencatat penghapusan yang sudah telanjur terjadi. Kontrol penonaktifan santri sudah membatalkan status dan penempatan dengan benar.

Koreksi terarah memeriksa hasil logger pada kedua jalur refresh dan melempar exception sebelum commit bila gagal. Web menampilkan galat melalui penanganan ScheduleException yang ada; API mengembalikan 500 `AUDIT_FAILED` dengan instruksi mencoba lagi. Catch transaksi yang ada membatalkan perubahan peserta. Bentuk respons sukses, hak, endpoint, skema dan kode aplikasi mobile tetap.

Probe final **yang sama**, dijalankan pada arsip kode asli `6dead3e` dan fixture bersih terpisah: **6/5 → 11/0**. Bukti `audit-codex/probe-awal.txt` dan `probe-akhir.txt`; probe permanen `tests/perbaikan_peserta_absensi_audit.php`. Tidak ada temuan pemblokir fungsi lokal yang tersisa setelah koreksi ini.

## Bukti independen

| Pemeriksaan | Hasil | Bukti dalam `audit-codex/` |
| --- | --- | --- |
| Integrasi implementator, diulang sebelum/sesudah koreksi | **36/0 · 36/0** | `integrasi.txt`, `integrasi-akhir.txt` |
| Probe kegagalan audit, retry, status 403/404/409 | **6/5 → 11/0** | `probe-awal.txt`, `probe-akhir.txt` |
| Dua urutan kunci pada proses PHP independen | **8/0** | `konkurensi.txt` |
| Expo web + website, fixture/API nyata, 375px | **18/0** | `browser.txt`, `tangkapan/` |
| Probe HTTP/browser tambahan, 375/768/1280px | **21/0** | `browser-probe.txt` |
| Unit aplikasi | **80/0** | `mobile-unit.txt` |
| TypeScript / lint resmi Expo | **exit 0 / exit 0** | `mobile-tsc.txt`, `mobile-lint.txt` |
| Pemeriksa versi terhadap main yang di-fetch | **LULUS 1.3.0 → 1.4.0 (minor)** | `versi.txt` |
| Lint PHP aplikasi dan probe terkait | **15/15** | `lint-php.txt` |

Integrasi membuktikan penyaringan santri nonaktif/arsip pada pertemuan baru (web/API), penutupan penempatan beserta ID audit dan tanggal tanpa penghapusan baris, pengaktifan kembali tanpa pembukaan penempatan otomatis, serta hitungan Data Kelas. Refresh menambahkan peserta baru dari kelas beku, menolak kelas yang baru ditambahkan ke jadwal, mengeluarkan peserta tidak layak tanpa absensi dan mempertahankan peserta yang sudah berabsensi. Draf/Selesai/guru lain ditolak dan daftar lama gagal disimpan.

Probe konkurensi mengatur kedua urutan secara sengaja: (1) transaksi simpan absensi memegang kunci, proses refresh menunggu lalu menerima 409 setelah pertemuan Selesai; dua absensi tetap utuh; (2) transaksi helper refresh memegang kunci yang diwajibkan layanan, proses simpan daftar lama menunggu lalu ditolak 422, tanpa absensi parsial. Urutan kedua menguji helper di bawah kunci secara langsung agar urutannya terkendali, bukan mengklaim dua permintaan HTTP bersamaan.

Browser utama membuktikan penambahan dua peserta/pengeluaran satu peserta, draf status Sakit tetap, peserta baru Hadir, kirim seluruh enam peserta, dan tombol hilang setelah Selesai. Probe tambahan membuktikan 401 tanpa token, 403 pengurus/guru lain, 404 ID tak ada dan GET tanpa mutasi; galat 503 mempertahankan status serta catatan santri/guru; retry sukses mempertahankan draf dan menyimpannya ke DB. Ketukan ganda menghasilkan satu POST tambahan dan tombol kirim dinonaktifkan selama pembaruan. Web menolak POST tanpa CSRF dengan 419, GET tidak memutasi, dan formulir bekerja tanpa JavaScript. Tombol aplikasi tersedia tanpa overflow pada tiga lebar; tombol web tanpa JavaScript tersedia pada ketiganya. Aset eksternal diblokir selama browser sehingga ini bukan validasi visual lengkap stylesheet/CDN produksi. Dua tangkapan 375px ditinjau auditor.

## Regresi

Seluruh **13 entri non-statis dan 52 suite statis** pada `docs/profil-impor/fase-1/perintah-regresi.sh` selesai. Setelah pengulangan benturan port, **status exit dan jumlah gagal seluruh entri sama dengan bukti handoff Claude**. Perbandingan ini terhadap bukti tersimpan, bukan klaim mengulang semua suite pada main. Ringkasan mentah `regresi/ringkasan.txt`, seluruh output `regresi/`, dan normalisasi/pembanding `perbandingan-regresi.txt` dipertahankan.

| Regresi | Hasil independen |
| --- | --- |
| Kredensial integrasi / web | **59/0 · 51/0** |
| Penugasan Keamanan F1 integrasi / HTTP | **70/0 · 34/0** |
| Penugasan Keamanan F4 HTTP | **66/0** |
| V1 master data / API absensi / perapihan | **12/0 · 14/0 · 52/0** |
| Kelas gabungan integrasi+konkurensi / HTTP | **71/0 · 32/0** |
| Redesain HTTP Tahap 2 / 3 / 6 | **185/6 · 166/1 · 125/7** (identik handoff) |
| Seluruh statis | **16 exit 0, 34 exit 1, 2 exit 77** (identik handoff) |

Kegagalan HTTP historis berkaitan oracle menu/tampilan dan pesan migrasi lama, bukan perubahan peserta. Statis tetap mempunyai oracle batas berkas fase terdahulu, migrasi terakhir dan lokasi SQL lama; `profil_impor_f1_static` masih 70/1, `phase3_static` 34/1, kelas gabungan 44/3, alumni 180/0, penempatan 138/0. Tambahan dua probe PHP menaikkan jumlah lulus beberapa suite karena cakupan lint menyisir seluruh tests. Dua suite yang melewati eksekusi adalah `v3_phase2_static`/`v3_phase3_static` (exit 77). Oracle tidak dilonggarkan; **tidak menyatakan seluruh statis repo lulus**.

Diagnostik: regresi HTTP Keamanan F1 pertama **28/6** memakai port default 8972 yang sedang dipakai server browser audit, sehingga proses HTTP mengakses DB browser sedangkan oracle membaca DB suite. Server browser ini hanya milik audit lokal; uji browser sudah selesai. Hasil awal disimpan pada `diagnostik/pk-fase1-http-port-bentrok.txt` dan ringkasan mentah. Suite yang sama, tanpa perubahan assertion, diulang memakai `PKF1_PORT=8992`, DB `test_ppa_codex_pk1h_test` yang diimpor dari fixture bersih sebelum seed probe: **34/0** (`pk-fase1-http-ulang.txt`). Ini memperbaiki isolasi alat uji, bukan kode aplikasi.

## Batas dan handoff

Penyebab pada data/build produksi pengguna belum diverifikasi. Aplikasi hanya diuji lewat Expo web pada audit ini: **tidak menjalankan atau membangun APK/IPA 1.4.0, emulator Android, simulator iOS atau perangkat fisik**. Hasil native 1.3.0 workstream sebelumnya bukan bukti native perubahan ini. Build distribusi/EAS, Push nyata, MariaDB 10.6/MySQL/cPanel/produksi dan UAT belum diuji. Audit lokal ini tidak menutup gerbang produksi V5/V6/V7/kelas gabungan terdahulu.

Alat rapikan diuji hanya pada fixture: laporan tidak menulis, penerapan menutup penempatan dengan audit, status santri tetap, pengulangan kosong. Tidak menjalankan `--terapkan` pada produksi. Penutupan penempatan di Data Santri memang berpengaruh pada cakupan kelas modul lain sesuai keputusan; regresi relevan dijalankan, tetapi tidak mengklaim UAT menyeluruh setiap kombinasi modul/akun.

Tidak merge, deploy, migrasi produksi atau rilis aplikasi. Direktori web belum terlacak milik pekerjaan lain dan perubahan `package.json`/`package-lock.json` mobile dipertahankan di luar commit. Hasil audit/koreksi diserahkan pada branch fitur yang sama; keputusan operasional berikutnya tetap Human Developer.
