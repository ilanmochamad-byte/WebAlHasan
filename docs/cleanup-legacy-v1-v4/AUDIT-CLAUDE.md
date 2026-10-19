# Audit independen cleanup V1–V4 tahap pertama — Claude Code

19 Oktober 2026, Asia/Jakarta. Auditor: Claude Code. Implementator: Codex (`23f0c895a3f5f250c682f94acc10b2dce5a60aca`).

**Putusan: LULUS.** Seluruh kriteria wajib cleanup tahap pertama terpenuhi berdasarkan pengujian yang dijalankan ulang secara independen. Tidak ditemukan cacat pada kode cleanup, sehingga **tidak ada koreksi kode aplikasi**. Audit menambah satu suite probe adversarial (`tests/cleanup_legacy_audit_claude.php`) beserta bukti baru. Merge kode bukan izin deploy dan bukan pernyataan seluruh V4 siap produksi.

## Baseline dan status Git

- `origin/main` = `b807165` (merge PR #60 dari `codex/cleanup-legacy-v1-v4`). Merge commit biasa, bukan squash/rebase: `23f0c89` adalah ancestor `origin/main`.
- Kesetaraan isi: `git diff 23f0c89 origin/main` hanya memuat `.cpanel.yml` dan `bin/deploy-cpanel.sh` (commit Human Developer `4cfbaa1`, `e1e97f6`, 18 Oktober 2026), bukan bagian cleanup. Isi cleanup di `main` identik dengan commit Codex.
- `git diff 0cf77fc origin/main -- app api portal database admin` hanya menyentuh `admin/admin_izin.php`. Tidak ada perubahan service, migrasi, schema, arsip pelanggaran, menu, PSB, alumni atau V4.
- Branch audit `claude/audit-cleanup-legacy-v1-v4` dibuat dari `origin/main` terbaru, tanpa worktree. Tiga direktori untracked milik pekerjaan lain (`docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/`) tidak disentuh, tidak di-stage, dan tidak dipakai sebagai keluaran tes.
- Mobile `/Users/ilanmochamad/alhasanApps` tetap `ee6621a420c23605be57427e0353df4814297b80`, status bersih; dibaca saja. Tidak ada referensi `admin_izin`/`IZIN_LEGACY_ENABLED`; klien izin hanya memakai API `/izin/*` V2.

## Tinjauan diff cleanup

`admin/admin_izin.php` (seluruh isi dibaca):

1. Bootstrap → `Cache-Control: no-store` → `authorization()->requireWebRole('admin')` **sebelum** pemeriksaan metode. Anonim diarahkan login, nonadmin 403; penolakan 410 tidak pernah dijangkau tanpa sesi admin.
2. Metode selain `GET`/`HEAD`, atau adanya kunci `status`/`hapus` di query, → 410 dengan `Allow: GET, HEAD`, teks biasa, tanpa `Location`, tanpa SQL, tanpa forwarding body.
3. Selain itu → 302 ke `/portal/izin.php`, atau `/portal/izin_detail.php?id=N` bila `id` lolos `FILTER_VALIDATE_INT` min 1. Nilai `id` di-cast ke int sehingga tidak ada header injection/open redirect.
4. Tidak ada `Env::bool`, `$_GET['legacy']`, `$koneksi`, `mysqli_query` atau SQL apa pun. Tidak ada jalur untuk membuka kembali implementasi lama.

Melewati `_guard.php` (yang akan menjawab 419 untuk POST tanpa CSRF) aman karena alamat ini tidak memproses mutasi sama sekali; autentikasi/otorisasi tetap dipanggil langsung. Dibuktikan oleh probe POST dengan dan tanpa CSRF: keduanya 410 dan seluruh tabel identik.

Perubahan `tests/v2_phase2_static.php` mengganti assertion lama yang *mewajibkan* flag dan kode tulis V1 dengan assertion kebalikannya (tanpa flag, tanpa SQL tulis, ada 410). Ini mengikuti keputusan 19 Oktober, bukan pelemahan: assertion keberadaan berkas, 302 kompatibilitas, dan larangan DROP/TRUNCATE tetap ada.

## Pengujian yang dijalankan auditor

Lingkungan: instans MariaDB 12.3.2 privat (`--skip-networking`, socket dan datadir di scratchpad sesi, akun SQL hanya `%_test`), PHP 8.4.14, Node + Playwright yang terpasang. Tidak memakai `.env`/DB produksi atau dump lampiran (dump tidak dibuka). Struktur V1 diambil dari `k1807225_webalhasan.sql` terlacak dengan **semua 15 INSERT dibuang** (86 → 71 statement), AUTO_INCREMENT dinormalisasi, lalu migrasi 001–022.

| Rangkaian | Hasil auditor | Bukti |
| --- | --- | --- |
| `tests/cleanup_legacy_http.php` diulang, server dengan `IZIN_LEGACY_ENABLED=true` | **156 lulus, 0 gagal** (sama dengan Codex, dijalankan sendiri) | `audit-claude-http.txt` |
| `tests/cleanup_legacy_audit_claude.php` (baru, auditor) | **127 lulus, 0 gagal** setelah koreksi harness (lihat di bawah) | `audit-claude-http.txt` |
| Browser cleanup `uji-cleanup-legacy.mjs` | **26 lulus, 0 gagal**; screenshot 375px sintetis diperiksa, tidak di-commit | `audit-claude-regresi.txt` |
| 19 suite statis V1–V3/V2/perapihan/kredensial/penempatan/alumni | seluruhnya exit 0 | `audit-claude-regresi.txt` |
| 18 suite integrasi V1–V3 + HTTP V2 `v2_phase2_web_smoke` | seluruhnya exit 0; V2 integrasi 94/0, HTTP V2 36/0 | `audit-claude-regresi.txt` |
| V4 Fase 1–3 integrasi/konkurensi pada fixture sintetis baru | seluruhnya exit 0 (29, 54, 2, 73, 4) | `audit-claude-regresi.txt` |
| Verifier V2 Fase 1 dan 2 pada salinan sintetis | lulus sebelum simulasi; **gagal (exit 2) setelah simulasi tulisan darurat** | bagian verifier di bawah |

Probe tambahan auditor yang tidak ada di suite Codex: HEAD membawa `status`/`hapus` (410), override metode lewat `X-HTTP-Method-Override`/`X-HTTP-Method`/`X-Method-Override`/`_method` (tetap 410), metode `get` huruf kecil/`PROPFIND`/`TRACE` (tidak pernah 200/302), path-info `admin_izin.php/x?hapus=` (410), CRLF/`//example.com`/fragment pada `id` (Location tetap URL aplikasi), `no-store` pada 302 dan 410, anonim GET/HEAD/POST `hapus` → login, wali GET/HEAD/POST `hapus` → 403, cakupan detail warisan wali (pemilik relasi 200, wali lain 403), AUTO_INCREMENT `perizinan`/`izin_pengajuan`/`izin_riwayat_status`/`izin_keputusan`/`audit_logs` tidak bergeser.

Pengganti V2 dibuktikan oleh probe HTTP auditor dan suite V2: `izin_aksi` tanpa CSRF 419; aksi `putuskan`/`batalkan`/`tetapkan` pada data warisan berhenti di guard warisan (409 "warisan V1 bersifat baca-saja"); status/version warisan tidak berubah; tidak ada `izin_keputusan` yang menempel ke baris warisan. Cakupan, validasi, transaksi, idempotensi, optimistic version, riwayat dan audit dibuktikan oleh `v2_phase2_integration` (94/0) dan `v2_phase2_web_smoke` (36/0) yang dijalankan ulang.

### Kegagalan awal dan koreksi harness (bukan kode aplikasi)

1. Probe metode `get` huruf kecil: server pengembangan PHP memutus koneksi sehingga curl gagal dan skrip berhenti. Diperlakukan sebagai "tidak diproses" (status 0), bukan lulus otomatis; assertion tetap menolak 200/302.
2. Token CSRF diambil dari detail warisan, padahal detail warisan memang tidak memiliki formulir aksi → empat aksi menerima 419 dan terhitung gagal. Token kini diambil dari `izin_buat.php`, ditambah assertion bahwa detail warisan tidak menawarkan formulir aksi.
3. Assertion awal menerima 403/409/422 untuk aksi pada warisan; `putuskan` ternyata 422 karena `alasan_penggantian` admin kosong, bukan karena guard warisan. Assertion **diperketat** menjadi 409 dengan pesan guard, dan probe mengisi `alasan_penggantian`.
4. Setelah diperketat, `koreksi` menghasilkan 409 dengan pesan "belum memiliki keputusan yang dapat dikoreksi", bukan pesan guard warisan. Lihat temuan O-1.
5. Daftar tabel AUTO_INCREMENT awal memakai nama `izin_riwayat`/`audit_log` yang tidak ada, sehingga hanya dua tabel benar-benar diawasi. Nama diganti ke tabel nyata (`izin_riwayat_status`, `izin_keputusan`, `audit_logs`) dan suite kini gagal bila kelima tabel tidak ditemukan. `cleanup_audit_test` lalu **dibangun ulang dari struktur kosong** dan kedua suite HTTP dijalankan ulang; angka di atas berasal dari putaran bersih itu.

## Preservasi data

Pada `cleanup_audit_test` dengan sentinel sintetis nonkosong (4 santri, 2 wali, 2 `santri_wali`, 9 akun termasuk 2 akun wali, 4 penempatan kamar + 1 kelas, 1 alumni, 1 `perizinan` + migrasinya, 1 pelanggaran, 1 pendaftar + 1 pembayaran PSB):

- Suite Codex: **87 tabel**, jumlah baris dan SHA-256 seluruh baris/kolom/ID identik sebelum/sesudah seluruh probe; 14 pemeriksaan relasi yatim = 0; akun wali tetap 2.
- Suite auditor: **87 tabel + AUTO_INCREMENT** tabel izin/audit identik setelah seluruh probe adversarial dan percobaan aksi V2; status/version baris warisan identik.
- Selisih tak direncanakan: **0**. Hash hanya dihitung dalam memori; laporan hanya memuat hitungan.

## Verifier dan risiko tulisan darurat setelah migrasi

Pada salinan sintetis terpisah `cleanup_audit_verif_test` (bukan DB preservasi):

| Skenario | `v2_phase1_verify` | `v2_phase2_verify` |
| --- | --- | --- |
| Kondisi bersih hasil backfill | LULUS | LULUS |
| INSERT `perizinan` setelah migrasi (meniru flag darurat lama) | GAGAL, 2 pemeriksaan | GAGAL, 1 pemeriksaan |
| UPDATE status `perizinan` setelah migrasi | GAGAL, 1 pemeriksaan | GAGAL, 1 pemeriksaan |

Jadi verifier yang ada mampu mendeteksi baris lama yang belum dipetakan, ID yang bentrok dengan pengajuan V2 (cek `id = legacy_perizinan_id`) dan nilai yang bercabang. Cleanup menutup sumber tulisan baru tersebut. Apakah produksi pernah memakai flag darurat setelah migrasi **tidak diketahui**: verifier harus dijalankan oleh pihak berwenang pada salinan produksi terbaru sebelum rilis. Audit ini tidak menebak pemetaan, tidak melakukan backfill, dan tidak menyentuh produksi. Penghapusan simulasi hanya terjadi pada salinan sintetis tersebut.

## Temuan

Tidak ada temuan blokir pada cleanup.

- **O-1 (observasi, nonblokir, kode V2 lama):** `IzinWorkflowService` jalur `koreksi` tidak memiliki guard `is_legacy` eksplisit. Baris warisan tetap tidak dapat dikoreksi karena tidak pernah memiliki `izin_keputusan` (backfill tidak mengarang keputusan dan `putuskan` dijaga `requirePending`), dan itu terbukti oleh probe. Bila kelak ada jalur yang membuat keputusan untuk warisan, guard eksplisit perlu ditambahkan. Tidak diubah karena di luar cakupan cleanup dan tidak ada cacat yang terbukti.
- **R-1 (risiko keamanan, sudah dicatat Codex, dinilai ulang lebih berat):** `admin/hapus_siswa.php` menghapus `psb_pendaftar` lewat GET dengan `id` yang **disisipkan langsung ke SQL tanpa escape/cast**. `_guard.php` hanya memeriksa CSRF untuk POST dan cookie sesi `SameSite=Lax`, sehingga navigasi GET lintas situs oleh admin yang masih login dapat menghapus pendaftar, dan karena injeksi, berpotensi menghapus **seluruh** pendaftar dengan satu tautan. Pola GET-mutasi tanpa CSRF juga ada pada `admin_download.php`, `admin_galeri.php`, `proses_berita.php` (hapus, `id` tanpa cast), `admin_pembayaran_psb.php` dan `proses_psb_admin.php` (ubah status, `id` di-cast int). Semuanya kode V1 yang sudah ada sebelum cleanup, tidak berubah oleh cleanup, dan termasuk PSB/konten publik yang wajib dipertahankan. Sesuai instruksi, audit ini tidak memensiunkan atau mengubahnya. Probe mutasi tidak dijalankan. **Rekomendasi:** perbaikan keamanan terarah terpisah (POST + CSRF + prepared statement, tanpa mengubah fungsi bisnis PSB/konten) dengan keputusan Human Developer.
- **R-2 (operasional):** `main` kini memuat `.cpanel.yml` yang menjalankan `bin/deploy-cpanel.sh` (rsync ke `public_html`) saat cPanel mendeploy dari repositori cPanel. Merge ke GitHub `main` tidak menjalankan deploy oleh audit ini; tetapi siapa pun yang menarik `main` ke repositori cPanel lalu menekan deploy akan merilis cleanup. Itu keputusan Human Developer.
- **R-3:** Nilai lama `IZIN_LEGACY_ENABLED` pada `.env` lokal/produksi tidak berpengaruh lagi dan tidak perlu diubah. Revert penuh commit cleanup akan memulihkan implementasi tulis tidak aman; jangan pakai sebagai rollback.

## Belum dijalankan oleh auditor

- V4 Fase 4/5 (`v4_phase4_*`, `v4_phase5_projection`), browser V4 Fase 3 dan PDF: tidak diulang karena cleanup tidak menyentuh berkas V4 mana pun dan rangkaian tersebut memerlukan prasyarat browser/PDF/push sintetis yang panjang. Hasil Codex untuk suite itu belum diverifikasi ulang oleh auditor.
- `*_concurrency.php` V1–V3 (alumni, penempatan, penugasan, perapihan, V2 Fase 4, V3) dan `*_web_smoke.php` selain V2 Fase 2.
- Matriks mobile native, Safari/iOS/perangkat fisik/Push nyata, smoke cPanel, migrasi/restore/cron produksi, UAT, access log produksi, beban produksi, uji mutasi PSB/unggah/keuangan.
- Matriks CSV (114/109/49/87/53 baris) diperiksa dengan sampel (menu izin V2, menu arsip pelanggaran, tautan mobile), bukan baris per baris.

## Keputusan merge

Tidak ada koreksi kode. Branch audit berisi laporan ini, `audit-claude-http.txt`, `audit-claude-regresi.txt`, suite `tests/cleanup_legacy_audit_claude.php`, pembaruan status README dan `AGENTS.md`. Merge ke `main` diizinkan Human Developer setelah audit lulus; tidak deploy, tidak migrasi produksi, tidak mengubah mobile, tidak memulai PRD V5. Batas fisik/iOS/Push/cPanel V4 sebelumnya tetap berlaku.

## Reproduksi probe auditor

Ikuti `perintah-uji.md` untuk DB dan server (dengan `IZIN_LEGACY_ENABLED=true` pada proses server), lalu:

```sh
CLEANUP_RUN_TESTS=1 CLEANUP_TEST_URL=http://127.0.0.1:<port> php tests/cleanup_legacy_http.php
CLEANUP_RUN_TESTS=1 CLEANUP_TEST_URL=http://127.0.0.1:<port> php tests/cleanup_legacy_audit_claude.php
```

Suite auditor juga memerlukan sentinel dari `tests/cleanup_legacy_fixture.php` dan backfill V2 pada DB `_test` yang sama. Ia tidak menghapus fixture dan hanya menghapus cookie sementara miliknya.
