# Cleanup fitur lama V1–V4 — tahap pertama

19 Oktober 2026, Asia/Jakarta. Implementator: Codex. **Selesai untuk audit independen; bukan persetujuan merge/rilis.** Tidak memulai PRD V5.

> **Status audit (19 Oktober 2026): LULUS audit independen Claude Code tanpa koreksi kode.** Laporan, temuan nonblokir dan batas pengujian auditor ada di [AUDIT-CLAUDE.md](AUDIT-CLAUDE.md). Merge ke `main` bukan izin deploy/migrasi produksi.

## Keputusan dan baseline

Keputusan Human Developer: pensiunkan antarmuka/jalur tulis yang terbukti duplikat, pertahankan seluruh tabel dan data historis. Web dibuat pada `codex/cleanup-legacy-v1-v4` dari `0cf77fc060e6cd277a2708c459ee0cda761c5b5c` (`origin/codex/prd-v4-fase-5`). Mobile baca-saja `ee6621a420c23605be57427e0353df4814297b80`. Keduanya diverifikasi dengan `git ls-remote`, bukan hanya referensi lokal.

HEAD awal web pada `codex/panduan-pengguna-v4` identik dengan baseline audit. Sebelum switch, dipastikan tidak ada perubahan tracked dan baseline tidak melacak tiga direktori pekerjaan lain. Branch baru tidak mengubah isi checkout; tidak membuat worktree. Isi/file pada `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, dan `output/` diperiksa melalui manifest privat dan tidak dimasukkan commit.

Konteks yang dibaca: AGENTS, PRD V1–V4; status/keputusan/audit perapihan V1–V2 dan strategi rekonsiliasi wali; keputusan arsip V3 §5.2a dan handoff/audit Fase 1; status/audit V4 Fase 5 di kedua repo. Keterbatasan produksi/iOS/Push fisik V4 tetap berlaku.

## Matriks keputusan

Inventaris terperinci, seluruhnya metadata kode tanpa isi database:

- [Halaman, endpoint PHP, komponen dan ekspor](halaman-pemanggil.csv): 114 baris; komponen/include bukan klaim endpoint mandiri.
- [Cabang router API](api-pemanggil.csv): 109 kondisi literal/regex; beberapa cabang satu endpoint, bukan hitungan endpoint unik. Metode pada cabang induk/aksi ditandai untuk pemeriksaan manual.
- [Menu](menu.csv): 49 pemanggilan menu literal dari `Navigation`; menu multiline/dinamis juga perlu membaca sumber. Grup tetap mengikuti capability server.
- [Tabel dan pemanggil](tabel-pemanggil.csv): 87 tabel skema baseline + migrasi 001–022 + registry migrasi.
- [Tautan mobile](tautan-mobile.csv): 53 referensi literal/template pada client/API/notifikasi/laporan. Template dinamis harus ditelusuri bersama fungsi pemanggilnya.

Referensi statis dicatat per berkas (kemunculan literal pertama), termasuk pemanggil CLI bila relevan. Tidak ditemukannya referensi literal tidak membuktikan ketiadaan pemanggil dinamis, bookmark atau penggunaan operasional. Tidak tersedia access log produksi yang layak untuk menetapkan penggunaan. `admin/error_log` bukan pengganti access log dan tidak dibaca. Dump snapshot bukan bukti pemakaian; lampiran pribadi **tidak dibuka atau diimpor** karena fixture sintetis mencukupi.

| Keluarga | Status dan keputusan tahap ini | Bukti/pemanggil/pengganti |
| --- | --- | --- |
| `admin/admin_izin.php` | **Alamat kompatibilitas**; antarmuka dan implementasi tulis lama dipensiunkan | Sebelum V1 `34519ec`: INSERT langsung, UPDATE/DELETE via GET. V2 `9613245` mengganti alur tetapi menyisakan flag darurat; baseline audit masih memuat flag. Sekarang tidak ada SQL/handler mutasi lama. |
| Menu izin, portal daftar/detail/form/aksi, API `/izin/*`, laporan HTML/cetak/CSV | **Aktif**; satu alur tulis V2 | `Navigation`, `portal/izin_buat.php`, `izin_aksi.php`, `IzinApiService` → `IzinWorkflowService` → repository; mobile `src/api/client.ts` dan rute `/izin/[id]`. |
| `perizinan`, hasil migrasi `izin_pengajuan` dengan `is_legacy=1` | **Arsip baca-saja** untuk catatan warisan; tabel/nilai/ID dipertahankan | Filter `source=legacy`, detail berlabel Data warisan, verifier V2. Tidak mengarang pengurus/murobi/pelaku. Tindakan V2 terhadap catatan warisan tetap ditolak layanan. |
| `admin/admin_pelanggaran.php`, menu, `pelanggaran` | **Arsip baca-saja**, wajib dipertahankan | PRD V3 §5.2a; `KatalogService::legacy`, LEFT JOIN dan escape. GET hapus/POST tetap ditolak. Tidak mem-backfill ke ledger V3. |
| Katalog/pelanggaran/poin/konseling/publikasi/laporan V3 dan API `/v3/*` | **Aktif** | Portal, service V3 dan client mobile `src/api/pembinaan.ts`; pengganti pencatatan baru, bukan izin menghapus arsip lama. |
| Rencana/nilai/sikap/verifikasi/rapor V4, PDF dan verifikasi publik | **Aktif** | Portal dan API `/v4/*`; tidak mengganti kelulusan/alumni, PSB, atau keuangan. |
| Santri, wali, `santri_wali`, `users`, role, ID, kolom ayah/ibu | **Aktif**, tidak diubah | MasterData/Account, impor/ekspor, PSB/alumni, resolver wali V2–V4; `mirrorParent()` tetap satu arah sesuai keputusan perapihan. |
| Kelas/kamar/semester, penempatan, jadwal/pertemuan/peserta/absensi dan laporan | **Aktif** | Pengajian terpadu, PenempatanService dan API guru/laporan. Tidak menyamakan jadwal dengan pertemuan. |
| `admin_santri`, `proses_mutasi_alumni`, `admin_jadwal_ngaji`, `pertemuan_pengajian`, `admin_akun_perizinan`, login lama | **Alamat kompatibilitas**, dipertahankan | Penempatan/alumni lama menolak POST 410; jadwal/pertemuan/akun meneruskan POST ke controller pusat berpengaman. Tidak mengganti kontrak forwarding yang telah diaudit. |
| Alumni, kelulusan/mutasi, rekap santri, ekspor lama | **Aktif**, belum ada pengganti setara | AlumniService dan halaman/ekspor masing-masing. Rapor V4 tidak menetapkan kelulusan otomatis. |
| PSB pendaftar/pembayaran/rekap/portal pendaftar/impor/ekspor | **Dipertahankan untuk V5/V6**, operasional lama tetap ada | Seluruh PRD V1–V4 mengecualikan penggantian PSB; tabel `psb_pendaftar`, `psb_pembayaran` tetap utuh. |
| `mapel`, `mengajar`, `pembimbing_kamar` | **Calon pensiun** hanya sebagai kandidat kajian, tetap disimpan | `mata_pelajaran`/penugasan baru bukan bukti kesetaraan seluruh isi/riwayat tabel lama. Tidak ada penghapusan/merge data. |
| Penugasan bendahara/panitia/bendahara PSB | **Dipertahankan untuk V5/V6** | Fondasi penugasan belum merupakan implementasi bisnis V5/V6. |
| Konten berita/galeri/download, jadwal publik, halaman publik dan PSB | **Aktif** | Header/footer/halaman publik; tidak ada pengganti setara pada V1–V4. |

Navigasi operasional sudah mengarah ke portal V2 sejak perapihan. Uji browser membuktikan satu menu daftar V2 dan tidak ada menu tulis izin lama; menu arsip pelanggaran dan PSB tetap ada. Karena itu tidak menambah/menghapus menu atau mengubah pusat penugasan pada tahap ini.

## Perubahan yang diterapkan

- `admin/admin_izin.php`: hapus implementasi lama dari runtime, bukan memindahkannya ke endpoint lain. Guard sesi/role admin diperiksa sebelum redirect/penolakan; `no-store`. GET/HEAD daftar → 302 portal; ID integer positif → detail dengan ID sama. Input ID array/tidak sah/overflow jatuh ke daftar. Flag `IZIN_LEGACY_ENABLED=true` dan `?legacy=1` tidak berpengaruh.
- Seluruh POST/metode lain dan GET `status`/`hapus` → **410**, tanpa Location/forwarding atau mutasi. POST dengan/tanpa CSRF memperoleh penjelasan penghentian. Guard khusus ini sengaja tidak memakai `_guard.php` yang akan menjawab 419 lebih dahulu; melewati CSRF aman karena endpoint ini sama sekali tidak memproses mutasi.
- `.env.example`: hapus opsi pengaktifan darurat, jelaskan penghentiannya. Nilai lama pada environment tidak perlu diubah agar aman.
- `tests/v2_phase2_static.php`: ganti hanya assertion yang mewajibkan flag/kode lama sesuai keputusan baru; tetap memeriksa alamat kompatibilitas, kontrak API, guard dan ketiadaan penghapusan data.
- `tests/cleanup_legacy_fixture.php`, `tests/cleanup_legacy_http.php`, `tests/browser/uji-cleanup-legacy.mjs`: fixture aditif sintetis, probe HTTP/preservasi seluruh tabel dan browser cleanup. `AGENTS.md` dan addendum `PRD-V2.md` mencatat keputusan. Dokumentasi/matriks/bukti seluruhnya di folder ini.

Tidak ada perubahan pada migrasi, schema, master data, identitas/relasi/akun wali, mobile, PSB, alumni, arsip pelanggaran, atau service bisnis V1–V4. Kode sebelum cleanup tetap tersimpan di Git.

## Bukti kesetaraan dan preservasi

V2 menggantikan kebutuhan membuat izin, membaca riwayat, mengambil keputusan dan melaporkan, dengan autentikasi, cakupan, CSRF web, validasi, transaksi, idempotensi, version, audit/outbox. Alasan keputusan dan penolakan hard delete merupakan pembatasan yang disengaja, bukan kehilangan fitur yang harus ditiru dari implementasi tidak aman. Regresi V2: 94 pemeriksaan layanan dan 36 HTTP, termasuk scope, validasi, routing tunggal/ambigu, retry, keputusan bersamaan, admin pengganti, koreksi/pembatalan, riwayat dan CSRF. Lihat [integrasi](izin-v2-integrasi.txt), [HTTP](izin-v2-http.txt) dan ringkasan regresi V2.

Pada `cleanup_legacy_test`, sentinel nonkosong dibuat **sebelum** snapshot: 4 santri, 2 wali, 2 relasi, 2 akun wali, 4 penempatan kamar dan 1 kelas, 1 alumni, 1 izin lama beserta migrasinya, 1 pelanggaran lama, 1 pendaftar dan 1 pembayaran PSB. Snapshot diambil sesudah login fixture (agar perubahan `last_login_at` yang disengaja tidak disalahartikan), lalu dibandingkan sesudah semua probe URL/menu/halaman.

**Seluruh 87 tabel** memiliki jumlah dan SHA-256 representasi setiap baris (termasuk ID dan semua kolom) identik; selisih tak direncanakan **0**. Hash baris hanya dihitung dalam memori, tidak memuat isi baris dalam laporan. Semua 14 pemeriksaan relasi/nilai warisan sebelum/sesudah tetap nol. Akun wali tetap 2. [Bukti lengkap per tabel](http-preservasi.txt) memuat jumlah before/after tanpa identitas. Ini bukti fixture sintetis lokal, **bukan pemeriksaan dump atau database produksi**.

Tidak ada skrip cleanup database baru. Fixture baru hanya INSERT sintetis; tidak ada teardown/hapus baris pada database preservasi. Suite regresi lama berjalan pada database sintetis terpisah; setup/teardown, restore atau trigger injeksi suite itu tidak merupakan pembersihan data historis/prod.

## Pengujian dan batas

| Rangkaian akhir | Hasil | Bukti |
| --- | --- | --- |
| HTTP cleanup + sidik jari 87 tabel | **156 lulus, 0 gagal** | `http-preservasi.txt` |
| Browser menu/filter/arsip/tab, admin 1440/375 dan menu guru/pengurus/wali | **26 lulus, 0 gagal** | `browser.txt`; screenshot sintetis diperiksa di direktori privat |
| V1–V3 + perapihan/kredensial/penempatan/alumni | **19 statis + 18 integrasi + HTTP V2, seluruh exit 0** | `regresi-v1-v3.txt` |
| V4 | **13 suite layanan/konkurensi/audit/proyeksi, seluruh exit 0**; browser Fase 3 **56 lulus**; PDF sintetis 1 halaman diperiksa | `regresi-v4.txt`, `proyeksi-wali-v4.txt` |
| Sintaks dan diff | PHP berubah/baru, JS baru dan `git diff --check` | `pemeriksaan-akhir.txt` |

Percobaan awal dan koreksinya dicatat, bukan disembunyikan:

1. Probe cleanup awal 84/8 karena ekspektasi Location absolut padahal `app_url()` memang relatif; assertion diperbaiki ke URL aplikasi persis. Pengeditan uji statis sempat memotong deklarasi `$apiRouter`; dipulihkan. Kode aplikasi tidak diubah untuk meluluskan ekspektasi ini.
2. Restore V1 awal ditolak hak SQL; akun uji kini hanya memiliki hak pada database `_test` di instance tanpa jaringan. Suite diulang dan exit 0.
3. Setup V4 awal memiliki enum jadwal salah dan hanya 12 peserta, sementara suite audit membaca indeks peserta ke-13. Diulang dari database sintetis baru dengan jadwal sah dan 30 peserta. Tidak mengubah assertion.
4. Probe transaksi V4 dijalankan sebelum browser Fase 3, sehingga memperoleh 409 alih-alih 503 dan meninggalkan trigger injeksi karena harness memakai `exit`. Trigger sintetis itu dilepas, urutan baseline dipenuhi, browser dan transaksi diulang, keduanya exit 0. Kunci push sintetis dan ekstraksi kode PDF yang sempat belum disiapkan juga dipenuhi sebelum melanjutkan suite terkait.
5. Browser cleanup awal menunggu form login setelah GET logout yang hanya membuka konfirmasi. Harness diganti mengisolasi cookie antaraktor; pengamanan logout aplikasi dipertahankan. Final 26/0.

**Belum dijalankan:** browser seluruh skenario V4 Fase 1/2/4/5 dan matriks mobile native baru; Safari/iOS/perangkat fisik/Push nyata; beban produksi; uji mutasi PSB/portal pendaftar/unggah/keuangan penuh; smoke cPanel, migrasi/restore/cron produksi, UAT dan access-log produksi. PSB/alumni/publik diuji baca/HTTP; bukan sertifikasi keamanan seluruh modul tersebut. Tidak mengulang semua suite lama di luar daftar bukti. Tidak mengklaim seluruh V4 siap produksi.

## Risiko dan gerbang audit

- Verifier V2 wajib dijalankan secara terotorisasi pada salinan terbaru sebelum rilis. Jika flag darurat pernah menghasilkan baris lama setelah migrasi, dapat ada data belum dipetakan/ID yang bentrok dengan pengajuan V2. Tahap ini tidak menebak pemetaan, menjalankan backfill produksi, atau mengklaim riwayat produksi sudah setara. Nol selisih pada fixture tidak membuktikan kondisi produksi. Tunda rilis jika verifier menemukan konflik; perlukan rekonsiliasi terpisah.
- Klien/operator yang sengaja menulis ke URL lama sekarang mendapat 410; arahkan prosedur operasional ke portal V2. Referensi prosedur darurat pada dokumen Fase 2 historis sudah kedaluwarsa oleh addendum PRD V2 §6d. Flag tidak dapat dipakai sebagai rollback.
- Ditemukan `admin/hapus_siswa.php` masih menghapus **PSB** melalui GET, sementara `_guard.php` memeriksa CSRF hanya untuk POST. Tidak dipanggil sebagai probe mutasi, tidak diubah karena PSB belum punya pengganti yang disetujui. Rekomendasi audit keamanan terarah PSB terpisah; jangan menganggap seluruh endpoint lama aman hanya karena cleanup izin selesai.
- `mapel`, `mengajar`, `pembimbing_kamar` hanya kandidat kajian. Penurunan frekuensi referensi atau isi snapshot tidak cukup untuk pensiun data. **Usulan tahap terpisah:** inventaris isi/pemilik/relasi, bukti ekuivalensi, retensi, backup/restore dan persetujuan Human Developer sebelum menghapus tabel/kolom/baris apa pun. Tidak ada SQL penghapusan yang disiapkan.
- Auditor independen disarankan Claude Code setelah Codex berhenti. Periksa diff dari baseline, ulangi probe flag true/legacy=1, penolakan CSRF/nonadmin, preservasi, V2 dan arsip V3. Ini pemeriksaan implementator, bukan audit independen.

## Rollback kode dan handoff

Commit cleanup tercatat pada Git/chat handoff (dokumen tidak mengacu pada hash dirinya sendiri). Push hanya branch fitur. Tidak merge/deploy/migrasi produksi atau perubahan mobile.

Rollback kode: buat commit revert terarah atas commit cleanup pada branch fitur dan tinjau ulang. Tidak ada rollback database karena cleanup tidak mengubah schema/data. **Revert keseluruhan memulihkan flag dan implementasi tidak aman**; pilihan pemulihan yang lebih aman adalah koreksi redirect/pesan dengan tetap menolak seluruh mutasi lama. Jangan mengaktifkan flag lama sebagai jalan darurat. Restore data tidak diperlukan dan tidak dijalankan pada produksi.

Berhenti setelah tahap pertama. Penghapusan tabel historis, PRD V5, rilis dan keputusan produksi tetap memerlukan instruksi terpisah Human Developer.
