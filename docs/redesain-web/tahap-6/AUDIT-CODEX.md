# Audit independen Codex — redesain website Tahap 6

Tanggal: **23 Oktober 2026 (Asia/Jakarta)**. Putusan: **LULUS setelah dua koreksi terarah auditor**. Migrasi/rollback AD–AE dan penolakan profil di luar cakupan AB memenuhi kriteria wajib. Tahap 7 belum dimulai: tetap menunggu rapor Ganjil 2026/2027 terbit dan instruksi Human Developer. Kelulusan audit bukan izin merge/deploy/migrasi produksi.

## Acuan dan keadaan

- Branch `claude/redesain-web-tahap-6`, implementasi Claude **`3ad7b54`**, baseline **`c8d934b`**; tanpa worktree. Acuan: `AGENTS.md`, rencana, keputusan AB/AC/AD/AE/AF, cetak biru D-1–D-10/M-1–M-3/G-1 dan kriteria Tahap 6, seluruh 15 penafsiran README, audit Tahap 5, serta aturan cakupan/kerahasiaan PRD V3.
- Diff aplikasi, SQL naik/turun, skrip fixture, dan perubahan oracle lama diperiksa. Bukti auditor di [`audit-codex/`](audit-codex/); bukti implementator tidak ditimpa. Log `*-final.txt` adalah hasil setelah koreksi.
- PHP 8.4.14, Node 26.7.0, klien MariaDB 12.3.2, Chromium Playwright lokal. DB Tahap 6 dibangun ulang sebelum pengulangan HTTP/browser. Migrasi HTTP memakai salinan `test_redesain_t6_migrasi_test` yang dihapus suite. Regresi master data memakai `test_regresi_t6_audit_test`; suite V3 memakai sandbox wajib `webalhasan_v3_phase1_test`.
- Sandbox menolak `/dev/fd` pada persiapan awal; pengujian koneksi/DB/browser kemudian memakai eskalasi yang disetujui. Tidak menyentuh DB produksi, mobile, API bisnis, situs publik, atau direktori pekerjaan lain yang belum terlacak.

## Temuan dan koreksi

**F-1 · P2 · Dialog konflik ayah dan ibu berputar — DITUTUP.** Bila kedua identitas orang tua baru berbeda dari data lama, layanan menggulung transaksi pada konflik pertama. Setelah admin mengonfirmasi Ayah, transaksi berikutnya menemukan Ibu; konfirmasi Ayah tidak ikut formulir berikutnya, sehingga pengiriman Ibu kembali meminta Ayah. Alur UI tidak pernah menyimpan kedua perubahan. Penafsiran README butir 6 semula menganggap dialog berurutan dapat selesai.

Auditor mengumpulkan seluruh konflik yang diajukan untuk ditampilkan dalam satu dialog, lengkap nilai lama → calon nama/HP tiap orang tua. Satu tombol menyetujui keduanya pada POST tersebut; persetujuan tidak disimpan otomatis di sesi. Guard CSRF, token sekali pakai, validasi dan transaksi layanan tetap berlaku. Konflik tunggal tetap memakai nama tombol sebelumnya. Probe asli **22/2 → 25/0**, termasuk tepat dua audit mirror; probe browser menguji calon wali baru, probe HTTP menguji wali terdaftar.

**F-2 · P3 · Hubungkan alumni belum beralasan — DITUTUP.** D-9 meminta setiap tindakan melalui dialog beralasan. Formulir Hubungkan belum menyediakan alasan, server menerima POST tanpa alasan, dan audit tidak menyimpannya. Auditor menambahkan isian alasan, pemeriksaan layanan sebelum transaksi, serta alasan di `alumni.hubungkan`. Pemanggil aplikasi hanya halaman ini; dua pemanggil suite integrasi diperbarui untuk mengirim alasan, tanpa menghapus asersi lama. Probe sebelum koreksi **26/3**, setelah koreksi **29/0**: tanpa alasan → 422 tanpa mutasi; dengan alasan → tersimpan dan tercatat. Regresi alumni **80/0**.

Tidak ada temuan aplikasi penghalang yang masih terbuka dalam cakupan ini.

## Keputusan server dan database

| Keputusan | Bukti independen |
| --- | --- |
| AB | HTTP 132/0 mencakup matriks 15 akun–santri × empat tab, santri luar/tidak ada sama-sama 403 tanpa nama, admin ke D-2, Pendidikan/orang tua/guru biasa/pengurus biasa ditolak, daftar hanya binaan, akses admin tetap tertutup. Penonaktifan/berakhirnya penugasan serta santri nonaktif langsung berlaku pada sesi lama. Probe auditor menambah master nonaktif/arsip, penugasan arsip/belum mulai, semester nonaktif, dan santri arsip pada pembimbing **dan** murobi: empat tab 403 serta hilang dari daftar; setelah pemulihan 200. Kasus Rahasia tetap hanya pemilik; layanan V3 tidak diubah. |
| AC | Mode periksa memakai validasi santri, menolak NIS terdaftar/ganda/invalid, tidak menyimpan; saat impor diperiksa ulang. Probe membandingkan jumlah santri, wali, relasi dan audit sebelum/sesudah periksa. HTTP membuktikan hanya baris siap disimpan, jalur lama, templat XLSX, GET 405 dan CSRF. Browser menguji wizard dengan pembaca CSV pengganti SheetJS. |
| AD | HTTP membuktikan pasangan terurut, FK, satu tanda aktif, tanda ulang ditolak, alasan wajib, pembatalan tidak menghapus riwayat dan mengembalikan kandidat, dua wali berakun tidak dapat digabung, CSRF, dan audit. Browser menguji Bukan ganda/Lewati. |
| AE | HTTP membuktikan ENUM, kamar baru wajib asrama, pemanggil ubah lama tidak mengosongkan, penempatan cepat/massal menolak tanpa konfirmasi+alasan, audit per santri, dan status tidak sesuai. Probe mengubah asrama tujuan **sesudah** preview: apply membaca ulang dan menolak tanpa menulis; konfirmasi beralasan kemudian berhasil. Browser menjalankan penempatan dari HP. |
| AD/AE migrasi | H6-A menjalankan ulang SQL idempoten, rollback melalui migrator, memeriksa kolom/tabel hilang dan hitungan kamar/kapasitas/wali/relasi/penempatan/audit terjaga, tujuh halaman tetap 200 tanpa migrasi, kamar dapat disimpan tanpa kolom, naik lagi berhasil dan aturan wajib kembali berlaku. Kehilangan label/tanda sesudah rollback+naik dibuktikan dan didokumentasikan. |
| AF | G-1 memisahkan tiga jenis mendatang dan menjelaskan belum memberi akses modul baru; data tetap dapat dikelola. Tidak mengubah kapabilitas. |

SQL naik tidak mengisi asrama lama secara otomatis. Rollback memang menghilangkan data dua fitur baru, sementara audit tetap ada; itu batas pemulihan yang dinyatakan, bukan pemulihan nilai fitur secara utuh. Uji dilakukan pada MariaDB lokal, belum MySQL/cPanel produksi.

## Penilaian layar

| Layar | Hasil |
| --- | --- |
| D-1 | Chip status berangka, saringan nama/NIS/kelas/kamar/L-P, enam kolom, kartu HP, menu per baris, ekspor/templat/impor tersedia. |
| D-2 | Kepala profil dan enam tab, ringkasan/tautan modul, riwayat kelas/kamar, izin/pembinaan/rapor melalui layanan baca. Admin tetap penjaga. |
| D-3 | Pembagian identitas/alamat/sekolah/wali dan navigasi desktop tersedia. F-1 melengkapi konflik dua orang tua secara atomik. |
| D-4 | Wizard unggah/periksa/impor, daftar kesalahan dan unduhan perbaikan, pemeriksaan ulang server; batas 1.000 baris dinyatakan. |
| D-5 | Pilihan massal → tujuan → tinjau → terapkan, panel individu, peringatan per santri dan hapus dari pilihan, kapasitas/audit/CSRF terjaga. |
| D-6 | Chip, panel identitas/akun/relasi, arsip relasi, hubungkan santri, dan tombol rekonsiliasi berangka tersedia. |
| D-7 | Satu pasangan berdampingan, kesamaan disorot, Bukan ganda beralasan/pembatalan/Lewati/gabung berkonfirmasi, daftar perbaikan tersedia. |
| D-8 | Tiga langkah, pengecualian dijelaskan, tinjauan dan token sekali pakai; regresi alumni mempertahankan snapshot dan riwayat. |
| D-9 | Enam kolom, panel jejak/penempatan/orang tua, pilih santri bernama; F-2 melengkapi alasan hubungkan sampai audit. |
| D-10 | Empat tab hanya-baca; akses per permintaan, tidak ada nilai/rapor/ubah; Ajukan izin mengikuti hak. |
| M-1 | Tab guru/pengurus, chip, panel identitas/akun/penugasan, Buat akun prapilih, status/dialog, CSV pengurus tersedia. |
| M-2 | Kelas beranggota semester aktif; kartu/tabel kamar, hunian, chip asrama, panel penghuni, formulir wajib; tanpa hapus kamar. |
| M-3 | Semester aktif ditonjolkan; aktivasi menerangkan semester lama menjadi Non-Aktif dan dampaknya; arsip/pulih/panel tersedia. |
| G-1 | Menu jenis desktop/pilihan HP, hak dalam kata biasa mengikuti kelayakan akun, panel dan tindakan beralasan; GET halaman lama 301, POST tetap. |

Suite browser mencakup 29 pasangan layar–akun × 375/768/1280px dan interaksi utama. Tangkapan terpilih akhir serta enam dialog probe disimpan. Auditor meninjau langsung dialog dua konflik 375px, kamar 1280px, dan penempatan HP. Uji otomatis tidak menggantikan seluruh pemeriksaan aksesibilitas manual.

## Penafsiran dan batas cakupan README

| Butir | Penilaian |
| --- | --- |
| 1 | Diterima: cakupan mengikuti peran aktif dan penugasan kamar/kelas, bukan riwayat permohonan izin; guard memeriksa server. |
| 2 | Diterima: tab Izin hanya tanggal/status, tanpa alasan atau detail yang mungkin lebih sempit; angka semester mengecualikan Dibatalkan. |
| 3 | Diterima: tab Sikap hanya status/waktu, tautan modul tetap melalui guard aslinya. |
| 4 | Diterima: tanda per pasangan, kontrak kelompok lama tetap; POST gabung bertanda adalah keputusan admin eksplisit dan tetap memakai validasi penggabungan. |
| 5 | Diterima: angka tombol menjumlahkan butir tinjauan, bukan jumlah orang unik; sumber yang sama dengan chip. |
| 6 | **Dikoreksi F-1**: dialog berurutan semula tidak dapat selesai pada dua konflik. Sekarang satu dialog menjelaskan dan mengonfirmasi seluruh konflik. |
| 7 | Diterima: asrama NULL tidak ditebak; ambang hampir penuh ≥90%; jalur cepat/pindah tetap diperiksa ulang server. |
| 8 | Diterima dengan batas terbuka: pembacaan Excel memerlukan JS, validasi tetap server; 1.000 baris dan kompatibilitas POST lama. XLSX asli di browser belum diuji. |
| 9 | Diterima: Buat akun prapilih orang; wizard akun N-1 bukan lingkup tahap ini. |
| 10 | Diterima: label untuk penugasan aktif dan kelayakan akun; aturan hak tetap Capabilities, tanpa migrasi role. |
| 11 | Diterima: GET 301, POST kompatibel, arsip/pulih melalui layanan lama; regresi penugasan 161/0. |
| 12 | Diterima: POST kelas assign lama dipertahankan, formulir ganda dihapus. |
| 13 | Diterima: kolom CSV kamar aditif dan saringan kamar memakai builder yang sama; CSV pengurus baru. |
| 14 | Diterima: nama dari izin/pelanggaran menuju D-10, admin dialihkan D-2; tujuan selalu memeriksa akses lagi. |
| 15 | Diterima: D-9 tanpa ringkasan angka sesuai cetak biru; data warisan tetap diperingatkan. Tindakan hubungkan kini beralasan setelah F-2. |

Perubahan oracle alumni/penempatan/perapihan/phase2/penugasan yang dibuat implementator diterima sebagai perubahan judul, navigasi, panel, atau saringan baru. Asersi preservasi/akses tidak diganti dengan penerimaan status yang lebih lemah. Tambahan alasan pada dua panggilan integrasi alumni diperlukan untuk persyaratan D-9 baru; seluruh 80 asersi lama tetap dijalankan.

## Hasil pengujian auditor

| Uji | Hasil |
| --- | --- |
| Tahap 6 statis, awal/akhir | **141/0**, **141/0** |
| Tahap 6 HTTP, DB baru tiap run | **132/0**, **132/0** |
| Tahap 6 browser, DB baru tiap run | **115/0**, **115/0** |
| Probe server auditor akhir | **29/0** |
| Probe dialog browser 375/768/1280, JS aktif/nonaktif | **36/0** |
| Regresi browser kerangka sembilan akun × tiga lebar | **107/0** |
| Penempatan integrasi/konkurensi | **62/0**, **10/0** |
| Alumni, perapihan, penugasan, master data integrasi | **80/0**, **52/0**, **161/0**, **12/0** |
| V3 Fase 2/Fase 3/Fase 4 integrasi; akses Fase 5 | **67/0**, **121/0**, **65/0**, **5/0** |
| Statis Tahap 1/2/3/5, alumni/penempatan/penugasan/perapihan/phase2, V3 Fase 1/4 | exit **0** |
| Statis Tahap 4 | **95/1** — oracle cakupan historis, dijelaskan di bawah |
| Statis V3 Fase 2/Fase 3 | **68/1**, **102/1** — dua baseline label navigasi lama |
| Lint PHP berubah, sintaks JS probe, `git diff --check` | lulus |

**Perbedaan dari klaim regresi implementator:** `redesain_web_tahap4_static.php` menjalankan `git diff --name-only 11ed4ba -- database`, lalu menuntut kosong. Pada commit Tahap 6 hasilnya tepat dua berkas migrasi/rollback 023 yang diwajibkan AD/AE. Baseline `c8d934b` tidak memiliki diff database itu. Ini ketidakcocokan oracle larangan skema **Tahap 4** dengan cakupan baru Tahap 6, bukan pelanggaran larangan skema Tahap 4; tidak mengubah suite agar hasil tampak hijau. Bukti implementator 96/0 tidak dipakai sebagai hasil auditor. Dua oracle V3 lama tetap identik dengan Tahap 5.

Perapihan melewati skenario admin terakhir karena DB fixture punya lebih dari satu admin aktif; tidak diklaim menguji ulang skenario tersebut. Log alumni berisi galat trigger sintetis yang sengaja dipasang suite untuk membuktikan rollback, dengan exit 0. Percobaan V3 pada DB salinan keluar 77 karena suite mengunci nama DB; run final memakai DB sandbox yang ditetapkan. Probe browser awal berhenti saat mencoba pemilih wali tanpa JS; run final memperjelas batas berikut.

## Batas dan handoff

- Pemilih mode wali D-3 memakai panel `hidden` yang dibuka JS, sudah ada di baseline `c8d934b`. Pengujian **dialog** tanpa JS menyiapkan POST konflik lewat HTTP, kemudian menekan tombol konfirmasi melalui browser tanpa JS. Ini membuktikan dialog/submit lintas-form, **bukan** keseluruhan alur tambah/pilih wali tanpa JS. Tidak mengklaim 36/0 sebagai akses penuh formulir tanpa JS.
- Chromium lokal saja; Safari/iOS/Firefox, perangkat fisik, pembaca layar dan axe belum diuji. SheetJS diganti pembaca CSV di suite browser utama; parser `.xlsx` nyata/CDN belum diuji. Tidak ada smoke cPanel atau pengujian MySQL produksi.
- Tidak mengulang seluruh HTTP/browser Tahap 2–5, seluruh integrasi V1/V2/V4, performa pasangan wali pada skala produksi, migrasi V3 lama, mobile, maupun REST. Bukti implementator untuk bagian tersebut bukan eksekusi independen auditor. Regresi auditor dipilih pada master data/penugasan/penempatan/alumni, privasi V3 dan kerangka semua peran.
- Server uji dihentikan setelah audit; DB tetap salinan sintetis. Tidak merge/deploy atau menjalankan migrasi produksi. Human Developer memutuskan langkah berikutnya. **Tahap 7 tidak dimulai.**
