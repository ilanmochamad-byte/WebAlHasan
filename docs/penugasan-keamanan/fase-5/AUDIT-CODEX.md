# Audit Codex — penugasan lintas guru/pengurus dan Keamanan Fase 5

**31 Oktober 2026 — LULUS audit teknis lokal setelah tiga koreksi terarah.**
Tidak ada temuan Fase 5 yang masih memblokir pada lingkungan uji lokal.
Kesiapan produksi belum dinyatakan lulus. Tidak merge, deploy,
migrasi produksi, atau rilis aplikasi. Gerbang produksi V5/V6/V7 tetap terbuka.

## Cakupan dan baseline

- Web: branch `claude/penugasan-keamanan-fase-5`, seluruh commit fase
  `9e62464d6fa2cc0118137a58e347eba31e32a53e`, di atas audit Fase 4
  `39cc6445a996c7d1b3fe3fadd351d9aa8d05d306`.
  `git ls-remote` mengonfirmasi kedua ujung branch tersebut dan remote main
  `2a786757108021546fe03a8160e53f92693f267a`; diff baseline–main kosong.
- Mobile: branch sama, seluruh commit fase
  `1b4335ec425f316458ff39d85892ab3e9e0ddfcf`, di atas main
  `d4d792a3644accc5af795b8d45223c608e72ae9c` (isi sama audit Fase 4 `c514e4f`).
  Kedua remote terverifikasi. Diff commit hanya menambah README rilis.
- Dibaca: `AGENTS.md` kedua repo, rancangan/keputusan PK, audit Fase 4,
  seluruh diff fase, alat CLI, uji, manifest, runbook dan checklist.
  Ditinjau snapshot preservasi, SQL pembanding capability, batas restore,
  penjaga rollback dan alur deploy cPanel.
- PHP 8.4.14/MariaDB 12.3.2 lokal, DB sintetis `test_pkf5audit_*_test`.
  Tidak memakai data produksi. Mobile dari `git archive HEAD`, tanpa `.env`
  atau `.env.local` (hanya `.env.example` ter-commit); dependensi dari audit
  Fase 4 dengan package/lock identik, Expo 57.0.15. Typecheck memakai deklarasi
  generated router/Expo Fase 4; tidak menjalankan Metro atau aplikasi native.
- Tanpa worktree/subagen. Empat direktori pekerjaan lain di web dan perubahan
  `package.json`/`package-lock.json` mobile tidak disentuh atau di-commit.

## Temuan dan koreksi terarah

### F-1 · P2 — status manifest yang hilang melewati preservasi isi 033

Verifier awal hanya memeriksa `isi_pk` bila `pk_terpasang` persis menyatakan
kedua migrasi sudah terpasang. Menghapus field itu, memakai status sebagian,
atau mengganti boolean dengan string melewati seluruh pemeriksaan hash isi
032–033. Kontrol mengubah `keamanan_assignments.catatan` tanpa mengubah
timestamp. Manifest utuh menolak perubahan, tetapi tiga bentuk status rusak
tadi tetap exit 0. Manifest berstatus pra-rilis dengan isi pasca-rilis juga
diterima. Ini dapat meloloskan pulihan yang berbeda dari restore point.

**Koreksi:** manifest harus mempunyai status pra-rilis atau pasca-rilis utuh
berboolean, serta `isi_pk` yang sesuai: kosong sebelum rilis, kedua kelompok
hash lengkap sesudah rilis. Status hilang/sebagian/bertipe salah dan hash
terpotong ditolak, termasuk ketika memakai `--irisan-baca`. Pemeriksaan penuh
dengan manifest pra-rilis hanya sah segera sesudah migrasi, sebelum identitas
atau penugasan pertama, sehingga mengosongkan hash sambil mengaku pra-rilis
tidak meloloskan pulihan yang sudah berisi penugasan.

### F-2 · P2 — nama constraint benar dengan definisi salah tetap lulus

Verifier awal menerima `keamanan_range_check CHECK (1)`, dan menerima
`keamanan_pengurus_fk` yang menunjuk `pengurus.id` dari kolom lokal `id`
(seharusnya `pengurus_id`). Kedua kontrol memakai skema sungguhan pada DB
sintetis; bukan manipulasi hasil query. Nama constraint dan tabel induk saja
tidak membuktikan hasil restore mempunyai aturan yang benar.

**Koreksi:** seluruh enam FK dibandingkan sebagai kolom lokal–tabel target–
kolom target; CHECK dibandingkan ekspresinya setelah normalisasi format
spasi/backtick/kurung. CHECK sesi/default global MariaDB juga wajib aktif.
Tidak mengubah berkas migrasi atau rollback.

### F-3 · P3 — manifest gagal ditulis tetapi preflight mengaku sukses

Tujuan dengan induk tidak ada atau berupa direktori menghasilkan exit 0 dan
pesan `Manifest privat ditulis`, walau tidak ada file manifest. Kode lama
juga mengubah izin direktori tujuan menjadi 0600 sesudah write gagal.

**Koreksi:** tulis melalui file sementara privat 0600, periksa jumlah byte,
lalu rename ke tujuan. Kegagalan menaikkan status gagal/nonzero dan tidak
melaporkan manifest tersimpan. Tujuan direktori tidak di-chmod.

### Catatan runbook · P3 — lokasi manifest sesudah deploy

`deploy-cpanel.sh` menyalin `bin/` dan `database/`, tetapi tidak `docs/`.
Perintah relatif `--rilis=docs/...` dari `public_html` tidak menemukan manifest.
Runbook kini menyatakan CLI dijalankan dari kode terpasang dengan `.env`
yang sesuai dan manifest memakai jalur absolut checkout sumber dengan SHA
yang sama. Ini diverifikasi lewat inspeksi skrip; tidak mengeksekusi deploy.
Checklist juga membedakan latihan rollback DB dari rollback kode hosting
yang belum diuji.

## Bukti independen

Angka berarti **lulus/gagal**. Keluaran di `audit-codex/` terpisah dari
`bukti/` implementator. Probe auditor yang sama dijalankan berurutan terhadap
arsip handoff Claude dan kode terkoreksi pada DB rilis sintetis yang sama.
Setiap kontrol memulihkan data/skema, lalu memeriksa preservasi kembali.

| Rangkaian | Hasil | Bukti |
| --- | --- | --- |
| Probe F-1/F-2/F-3 terhadap arsip Claude → koreksi | **6/9 → 15/0** | `probe-kontrol-claude.txt`, `probe-final.txt` |
| Statis Fase 5 awal/akhir | **36/0 · 36/0** | `statis-awal.txt`, `statis-final.txt` |
| Rilis awal/akhir | **49/0 · 49/0** | `rilis-awal.txt`, `rilis-final.txt` |
| E2E web/API final | **19/0** | `e2e.txt` |
| Verifier sesudah E2E | **28/0** | `verifier-e2e.txt` |
| Verifier lama V5/V6/V7 sesudah E2E | **exit 0 ketiganya** | `verifier-lama-*.txt` |
| TypeScript / lint resmi Expo / unit mobile | **exit 0 / exit 0 / 47/0** | `mobile-checks.txt` |

Rilis menguji salinan 031 berisi V1–V7 → preflight/manifest → tepat dua
migrasi 032–033 → verifier dengan preservasi isi seluruh tabel lama →
turun tanpa data → naik lagi; pemasangan sebagian dan migrasi asing ditolak.
Rollback berisi identitas atau penugasan, termasuk yang sudah berakhir,
ditolak baik oleh preflight maupun SQL langsung. Latihan writer **11/0**
dan dump **12/0** mencakup restore ke DB kedua, verifier dengan manifest,
hash isi 25 tabel/hak sumber sama dengan pulihan, dan penolakan rollback.
Sembilan kontrol pengrusakan implementator juga dieksekusi ulang auditor.

E2E membaca 15 halaman web dan 10 endpoint per akun untuk pengurus biasa dan
guru Keamanan; 43 mutasi ditolak 405, Rahasia 404, irisan baca tetap,
32 jejak akses hanya metadata, pencabutan guru/penugasan menutup sesi dan
token lama, pengaktifan guru memulihkan akses. Log PHP diperiksa oleh suite.
Tidak mengklaim uji visual/native baru dari uji HTTP ini.

Manifest rilis ter-commit diperiksa independen: **33/33 migrasi, 2/2 rollback,
1/1 composer.lock, 3/3 berkas HEAD mobile** cocok SHA-256. Manifest migrasi
tidak perlu diubah karena SQL/dependensi tidak dikoreksi.

Perintah utama (jalankan **berurutan**, rantai fixture memakai DB perantara
bernama tetap):

```bash
PK_RUN_RILIS=1 PKF5_RILIS_DB=test_pkf5audit_rilis_test php tests/penugasan_keamanan_f5_rilis.php
PKF5_PROBE_DB=test_pkf5audit_rilis_test php tests/penugasan_keamanan_f5_probe.php
PKF5_DB=test_pkf5audit_e2e_test bash tests/penugasan_keamanan_f5_db.sh
PKF5_DB=test_pkf5audit_e2e_test PKF5_PORT=8969 php tests/penugasan_keamanan_f5_e2e.php
OUT=docs/penugasan-keamanan/fase-5/audit-codex/regresi PFX=pkf5audit bash docs/penugasan-keamanan/fase-5/perintah-regresi.sh
```

Kontrol probe memakai `PKF5_PROBE_ROOT=/private/tmp/pkf5audit-claude`, yaitu
arsip `9e62464` dengan autoloader `vendor` yang sama. Probe tidak membangun
ulang fixture; prasyaratnya DB sesudah suite rilis di atas, dan setiap kontrol
memulihkan perubahan sintetisnya.

## Regresi

Rangkaian berurutan `perintah-regresi.sh` selesai (`SELESAI`, orkestrator
exit 0). Exit orkestrator bukan pernyataan semua suite hijau: setiap hasil
dibandingkan dengan bukti handoff Fase 5. **24/24 entri non-statis** (15 suite
dan sembilan verifier) mempunyai exit/hitungan identik handoff. Semua 50
statis selesai dengan **18 exit 0, 30 exit 1, dua exit 77**.

| Kelompok | Hasil auditor |
| --- | --- |
| Fase 1 integrasi / probe audit / token / HTTP | **70/0 · 20/0 · 10/0 · 34/0** |
| Fase 2 integrasi / HTTP | **53/0 · 28/0** |
| Fase 3 integrasi / HTTP / migrasi / probe audit | **44/0 · 44/0 · 19/0 · 26/0** |
| Fase 4 HTTP REST | **66/0** |
| Verifier baru sesudah suite Fase 1–4 | **sembilan kali 28/0** |
| Fondasi penugasan | **161/0** |
| V7 Fase 5 E2E / HTTP / rilis | **82/1 · 40/0 · 22/15** |

Empat statis penugasan Fase 1–4 masing-masing bertambah satu assertion lint
lulus karena probe PHP auditor baru: **127/4 · 109/2 · 93/3 · 44/1**.
Jumlah gagal dan seluruh judul assertion gagal tetap sama dengan handoff;
detail daftar berkas Git pada pesan batas fase berubah sesuai tambahan file.
Tidak mengubah atau melonggarkan oracle historis. Perbandingan lengkap di
`audit-codex/perbandingan-regresi.txt`, log di `audit-codex/regresi/`.

V7 E2E gagal satu kali pada preflight lama yang membatasi migrasi sampai 031.
Uji rilis V7 lama menghasilkan 15 kegagalan karena fixture `TANPA_V7` hanya
melepas 030/031 bila salah satunya migrasi terakhir; setelah 033 titik awal
029 tidak tercapai. Hasilnya identik handoff. Bukti rilis workstream ini
memakai suite baru dengan titik awal 031 yang benar. Pembanding regresi ini
adalah log handoff; auditor tidak mengeksekusi ulang seluruh baseline Fase 4.
Kontrol sumber asli yang dieksekusi auditor adalah probe 6/9 di atas.

## Penafsiran dan batas

PF5-1…PF5-9 diterima dalam lingkup lokal dengan koreksi manifest/skema di atas:
rilis utuh bersama kode Fase 1–4, prasyarat 031, dan pemeriksaan tanpa lalu
lintas. Waktu 032 adalah proksi awal pemisahan tugas; runbook menghentikan
penulis sebelum kode/migrasi sehingga tidak menganggap interval deployment
yang tak tercatat sebagai bukti. Aturan sikap/nilai/tanda mengetahui tetap
diuji suite Fase 2, bukan direkonstruksi dari riwayat yang sah tetap tersimpan.

- **Belum diuji:** MariaDB 10.6.28, MySQL, cPanel/PHP-FPM/hosting, produksi,
  restore backup nyata beserta hak routine/trigger, rollback kode terpasang,
  UAT dan beban data produksi. Normalisasi CHECK baru hanya dibuktikan pada
  MariaDB lokal, bukan klaim kompatibilitas MySQL sudah teruji.
- Runtime iOS, perangkat fisik, build distribusi dan Push nyata tidak diuji.
  Kode mobile identik baseline audit Fase 4; tidak mengulang layar native.
- Verifier tanpa manifest memeriksa invarians, bukan keseluruhan preservasi.
  Manifest penuh/irisan dibaca hanya dalam jendela tanpa penulis; tidak ada
  pembuktian snapshot lintas transaksi saat modul lain sedang menulis.
- Audit aktor memakai tautan akun/identitas saat ini; perubahan tautan sah
  kemudian dapat memerlukan investigasi manual (PF5-5). Dua akun terpisah
  milik orang yang sama tetap tidak dideduplikasi. Tidak membersihkan data.
- Diagnostik sandbox, koreksi pembantu probe, orkestrasi yang dihentikan dan
  perbedaan perintah lint dicatat di `audit-codex/diagnostik.txt`.

Handoff: commit/push hasil audit pada kedua branch Fase 5 yang sama, lalu
berhenti. Tidak memulai pekerjaan berikutnya. Keputusan produksi tetap
Human Developer.
