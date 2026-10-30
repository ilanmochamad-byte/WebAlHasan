# Audit Codex — tombol Terbitkan tagihan satu santri pada aplikasi

Tanggal: 30 Oktober 2026. Auditor: Codex; implementator: Claude Code.

**Keputusan: LULUS audit teknis lokal setelah satu koreksi P2 (F-1).** Keputusan ini hanya untuk penambahan tombol/layar satu santri beserta regresinya. Gerbang akhir/produksi V6 untuk tagihan dan tabungan tetap mengikuti `docs/phase-v6-5/AUDIT-CODEX.md`; audit ini tidak menutup gerbang yang belum diuji. Merge, deploy, dan rilis aplikasi diserahkan kepada Human Developer.

## Lingkup dan sumber

- Web: baseline `b55730b` (PR #100), seluruh commit baru `f8ded4e3299128539cda70c09a3b9109b35f58aa`, branch `claude/terbit-tagihan-satu-santri-app`. Hanya uji/dokumen; `app/`, `portal/`, `api/`, `database/` identik dengan baseline.
- Mobile: baseline `92caea4`, seluruh commit baru `a815d45e23a413611557939281e2f345aeb05a71`, branch `claude/terbit-tagihan-satu-santri`. Tinjauan mencakup tombol detail santri, registrasi rute, layar penerbitan, DTO API, penjaga respons server lama, hooks sumber privat/mutasi, dan unit test.
- Membaca `AGENTS.md` kedua repo, `PRD-V6.md`, dokumen fase/audit keuangan terdahulu dan perbaikan satu santri PR #100, serta README/bukti handoff workstream ini. Dokumentasi Expo SDK57 diperiksa sebelum koreksi mobile: https://docs.expo.dev/versions/v57.0.0/.
- Tidak mengubah backend, migrasi, hak, hook bersama, dependensi, layar penerbitan massal, atau perlindungan navigator Keuangan. Paket mobile yang sudah kotor milik pekerjaan lain tidak dimasukkan commit. Direktori dokumen/output web milik pekerjaan lain tidak disentuh.

## F-1 — P2, respons pratinjau lama dapat mengaktifkan penerbitan periode lama — ditutup

Pada handoff, `lihat()` langsung memasang respons tanpa memeriksa versi input atau siklus layar. Mengganti periode/jatuh tempo saat respons tertahan memang menghapus pratinjau, tetapi respons lama kemudian menampilkan kembali total dan tombol terbit. Hal serupa terjadi setelah aplikasi masuk latar lalu aktif kembali. Tombol memakai periode dari respons lama, meski isian sudah berubah. Hash server sah untuk periode lama itu, sehingga pemeriksaan server saja tidak menyelesaikan kekeliruan maksud pengguna.

Reproduksi independen menahan respons API nyata, mengubah input atau melakukan siklus latar/aktif, lalu melepas respons. Probe identik: **kontrol handoff 1 lulus/2 gagal → koreksi 3 lulus/0 gagal**. Tidak melonggarkan oracle. Bukti: `audit-codex/probe-kontrol.txt`, `probe-akhir.txt`; runner `tests/browser/uji-tagihan-satu-santri-app-audit.mjs`.

Koreksi hanya `src/app/keuangan/terbit-santri.tsx`: versi pratinjau naik saat input/siklus layar berubah; hasil, galat, status memuat dan navigasi terlambat hanya berlaku untuk versi/generasi layar yang masih aktif. Kembali dari latar memeriksa ulang hak dan membuang pratinjau, dengan periode/jatuh tempo tetap sebagai draf. Input terkunci selama penerbitan. Pembayaran, tabungan dan nominal server tetap memakai aturan yang ada.

## Bukti independen

| Pemeriksaan | Hasil | Bukti |
| --- | --- | --- |
| Expo web alur tombol handoff sebelum koreksi | 27/0 | `audit-codex/expo-awal.txt` |
| Expo web alur tombol setelah koreksi | 27/0 | `audit-codex/expo-akhir.txt` |
| Probe F-1 identik sebelum/sesudah | 1/2 → 3/0 | `audit-codex/probe-kontrol.txt`, `probe-akhir.txt` |
| Regresi aplikasi V6 Fase4 | 45/0 | `audit-codex/regresi-v6-fase4-app.txt` |
| Regresi aplikasi V6 Fase5 | 20/0 | `audit-codex/regresi-v6-fase5-app.txt` |
| TypeScript / `npm run lint` (Expo) | exit0 / exit0 | `audit-codex/typescript.txt`, `lint.txt` |
| Seluruh unit test paket ter-commit | 43/0 | `audit-codex/unit.txt` |
| iOS Release simulator sumber koreksi | 7/0 | `audit-codex/ios-akhir.txt`, `maestro/`, `uji-ios.py` |

Alur utama 27 pemeriksaan membuktikan tombol BSA dalam cakupan, rincian tarif150000+200000-potongan50000=300000 setara API, pratinjau tidak menulis, ketukan ganda menghasilkan satu tagihan/satu sasaran, santri lain tidak ikut, serta navigasi rincian. Periode yang sudah terbit hanya menawarkan Buka tagihan; tanpa kelas diarahkan ke website. Respons server sebelum PR #100 ditolak tanpa POST penerbitan. Admin/wali tidak mendapat tombol dan alamat langsung ditolak; petugas unit lain ditolak; pencabutan penugasan sesudah pratinjau menutup data tanpa tagihan. Tampilan375px diperiksa visual dan tanpa overflow; penyimpanan/konsol tanpa token dan tidak ada permintaan browser keluar loopback.

Uji iOS7/0 membuktikan tombol/pratinjau dan angka300000 tanpa menulis, pembuangan pratinjau sesudah latar, tepat satu tagihan/satu sasaran dan navigasi rincian, Buka tagihan yang sama, pencabutan penugasan pada sesi lama, serta admin dan wali tanpa tombol dan ditolak melalui deep link. Tidak ada warning/fatal PHP pada log kedua API lokal.

Regresi Fase4 meliputi ketukan ganda, jawaban hilang/idempotensi setoran, belanja/transfer/verifikasi, pembayaran tunai/tabungan dan kuitansi, konflik versi409, penerbitan massal, rekonsiliasi, admin/wali dua anak, revokasi, offline dan sesi berakhir. Fase5 memeriksa laporan periode setara API, kas baru dan selisih nol, penyaringan peran/unit/anak, pencabutan hak, dan pembacaan tanpa POST. Ini regresi aplikasi terhadap layanan nyata; rangkaian backend penuh PR #100 tidak diulang karena backend identik. Bukti konkurensi backend sebelumnya tetap pada audit PR #100, bukan diklaim sebagai uji baru di sini.

## Lingkungan dan batas

- Salinan sumber tanpa root `.env*`, paket dari lock ter-commit Expo57.0.15, bukan paket kerja yang sedang kotor. Hash sumber/lock dan bundel di `audit-codex/manifest.json`. MariaDB12.3.2/PHP8.4.14, DB sintetis terpisah `test_v6_ss_app_codex_test` dan `test_v6_ss_app_ios_test`; API8986/8990, Metro8099, seluruhnya loopback.
- iOS memakai build baru Release arm64 simulator iPhone17Pro/iOS26.2 dengan tanda tangan ad-hoc; `BUILD SUCCEEDED`, bundel mengandung API loopback8990, nol host produksi. Perlindungan layar tidak dimatikan. Ini tidak membuktikan distribusi App Store, iOS Debug atau perangkat fisik.
- Android native tidak dijalankan; tidak ada perangkat adb terhubung. Perangkat fisik, Push nyata, cPanel/MySQL/produksi, UAT dan rekonsiliasi dana nyata tidak dijalankan/ditutup oleh audit ini. Tidak ada bukti target fisik/produksi yang tersedia dan berwenang untuk sesi ini.
- Persyaratan server PR #100 tetap wajib: aplikasi menolak respons pratinjau server lama, sehingga fitur baru baru berfungsi setelah backend mendukung `santri_id`.
- Q-1 sampai Q-4 diterima: komponen manual/tarif di website, jatuh tempo bawaan tanggal10, membaca ulang rincian setelah terbit dengan fallback ke detail santri, dan penerbitan bersasaran satu. Tidak menambah asumsi produk baru.
- Pemindaian tambahan `npx eslint .` exit1: sembilan `no-undef` pada skrip tooling lama `.agents/skills/...` dan `store/_source/`, serta satu peringatan pada tipe `.expo` yang dihasilkan. Berkas tersebut tidak berubah oleh workstream ini. Perintah resmi proyek `npm run lint` (`expo lint`) exit0; hasil luas tidak diklaim lulus dan disimpan pada `audit-codex/eslint-seluruh-folder.txt`.
- Diagnostik alat: percobaan awal probe mengalami timeout karena helper menunggu tombol memuat sebelum melepas respons (`probe-kontrol-pembantu.txt`); urutan helper diperbaiki sebelum kontrol1/2 dan hasil3/0. Dua penyiapan fixture sempat bertabrakan pada DB perantara bernama tetap; uji akhir disiapkan berurutan dan native mendapat salinan DB tersendiri. Percobaan native awal mendahului kesiapan impor; ulangan menunggu fixture. Semua itu kesalahan setup auditor, bukan kegagalan aplikasi yang disamarkan sebagai lulus.

## Handoff

Commit koreksi mobile `6e50bd9` (sumber koreksi); commit/push bukti/dokumen web pada branch fitur masing-masing. Tidak merge, deploy, migrasi produksi, mengirim rilis atau memulai fase/PRD lain. Human Developer memegang keputusan berikutnya dengan mempertimbangkan gerbang terbuka di atas dan audit akhir V6 yang sudah ada.
