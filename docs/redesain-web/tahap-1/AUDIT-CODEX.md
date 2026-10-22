# Audit independen Codex — redesain website Tahap 1

Tanggal: **22 Oktober 2026 (Asia/Jakarta)**. Putusan: **LULUS setelah koreksi terarah**. Kriteria wajib Tahap 1 terpenuhi dalam lingkungan uji yang dijelaskan di bawah. Tahap 2 belum dimulai; kelanjutannya tetap menunggu instruksi Human Developer. Audit ini bukan keputusan merge atau deploy.

## Acuan dan cakupan

- Branch `claude/redesain-web-tahap-1`, baseline dokumen `7cf3a31`, seluruh commit implementasi tahap: **`4802e35f528f9cc25780f24bc2bb572cb367347e`** (satu commit).
- Dibaca: `AGENTS.md`, `rencana.md`, `keputusan.md`, cetak biru bagian kerangka/komponen, K-1–K-4 dan urutan tahap, serta README/bukti dan batas uji implementator. Diff seluruh berkas kode/PHP/CSS/JS, fixture, pengujian, dan perubahan dokumentasi diperiksa.
- Kriteria selesai: halaman yang telah memakai `App\Ui\Layout` tampil benar pada **375px, 768px, 1280px**, tanpa geser horizontal halaman. Fondasi mencakup sidebar bergrup, rel, bilah bawah, laci, panel dengan cadangan tanpa JavaScript, dialog, toast, status, menu ⋯, dan proses massal.
- Peta menu final, peran aktif, pencarian, perubahan akses, dan Beranda baru tetap Tahap 2. Tidak mengubah database/skema, API bisnis, mobile, atau halaman tahap selanjutnya.

## Temuan dan koreksi

| Temuan | Bukti sebelum koreksi | Koreksi dan hasil |
| --- | --- | --- |
| **P2 — Batal ditahan validasi alasan wajib.** Tombol Batal pada formulir `method=dialog` ikut menjalankan validasi textarea `required`, sehingga pengguna tidak bisa membatalkan dengan alasan kosong. | Gagal pada ketiga ukuran. Uji implementator hanya membatalkan dialog yang tidak meminta alasan. | `formnovalidate` pada Batal. Fokus dikembalikan ke tombol asal atau summary menu ⋯ bila menu asal sudah tertutup. Batal tanpa POST dan fokus kembali lulus pada ketiga ukuran. |
| **P2 — Respons bukan JSON dihitung berhasil oleh helper massal.** `response.json()` yang gagal diubah menjadi `null`; HTTP 200 HTML (misalnya halaman masuk setelah sesi habis) kemudian masuk daftar berhasil. | Probe POST HTML 200 dan hasil massal salah, keduanya gagal. | `AlHasan.post()` menolak payload kosong/bukan objek JSON dengan pesan yang dapat ditampilkan. JSON sukses, penolakan JSON 422, CSRF, dan daftar gagal beserta sebab tetap lulus. |
| **P2 — Tombol panel pendek belum menempel di dasar layar.** `position:sticky; bottom:…` saja menahan footer ketika formulir panjang, tetapi tidak mendorong footer formulir pendek ke dasar panel. | Posisi footer pendek gagal pada ketiga ukuran; footer panjang sudah terlihat. | Body/form panel dengan JavaScript memakai kolom flex dan margin atas otomatis pada footer. Formulir pendek dan panjang lulus pada ketiga ukuran; tampilan tanpa JavaScript tetap biasa. Bukti visual diperiksa langsung. |
| **P2 — Bukti cadangan tanpa JavaScript belum menguji pengiriman.** Dua formulir galeri mengandalkan injeksi CSRF oleh JavaScript. Formulir panel tanpa JavaScript mendapat 419 ketika benar-benar dikirim. | Dua probe token/pengiriman tanpa JavaScript gagal. Ini cacat fixture pengujian, bukan perubahan alur produksi. | Token dirender dengan `Csrf::input()` di server pada kedua formulir. Pengiriman fixture tanpa JavaScript sekarang berhasil dan kembali ke daftar. Galeri tidak menulis database. |

Suite kondisi tepi yang sama menghasilkan **15 lulus / 10 gagal pada commit asli**, lalu **25 lulus / 0 gagal setelah koreksi**. Bukti asli diperoleh dari snapshot `git archive 4802e35`, bukan perkiraan dari pembacaan kode. Pengujian menunggu event `close` asinkron sebelum menilai fokus; mutasi halaman aplikasi asli dicegat oleh browser.

## Pengujian independen

| Pengujian | Hasil akhir | Bukti |
| --- | --- | --- |
| Statis khusus Tahap 1 | **68/0** | `audit-codex/uji-statis.txt` |
| Browser lengkap T1-A–T1-K, lima peran, tiga ukuran | **91/0** | `audit-codex/uji-browser.txt` |
| Kondisi tepi auditor: Batal, fokus, formulir lama, footer pendek/panjang, POST, massal/hentikan, pengiriman tanpa JS | **25/0** | `audit-codex/uji-kondisi-tepi.txt`; pembanding `uji-kondisi-tepi-sebelum.txt` |
| Matriks baseline `7cf3a31` vs hasil audit | **264 observasi** = 88 pasangan akun/alamat × 3 ukuran; seluruh status HTTP identik. **213 observasi Layout akhir tanpa overflow** | `audit-codex/banding-baseline.txt` |
| Regresi browser perapihan | **56/0** (1440, 768, 390px; menu/tab/keyboard/cetak) | `audit-codex/regresi-browser.txt` |
| Smoke HTTP perapihan, alumni, kredensial, penempatan, penugasan | **56/0, 61/0, 51/0, 47/0, 85/0** = **300/0** | `audit-codex/regresi-http.txt` |
| Seluruh 21 suite `tests/*_static.php` | **19 exit 0** (2.232 pemeriksaan lulus); **2 exit 77** karena gate khusus DB V3, tidak diklaim lulus | `audit-codex/regresi-statis.txt` |
| Lint | **99 PHP tanpa galat**, sintaks JS valid, `git diff --check` bersih | `audit-codex/regresi-statis.txt` |

Tinjauan visual langsung mencakup Beranda admin, panel, dan dialog pada tangkapan browser yang dibuat auditor. Tangkapan terpilih ada di `audit-codex/tangkapan/`. Bentuk HP/rel tablet/sidebar desktop sesuai cetak biru. Satu-satunya overflow Layout baseline, Notifikasi pengurus 375px sebesar 13px, menjadi 0 di implementasi; koreksi implementator ini terkonfirmasi.

## Lingkungan, reproduksi, dan batas bukti

- PHP 8.4.14, Node 26.7.0, Chromium Playwright lokal; Bootstrap/Font Awesome dipenuhi dari dependensi lokal saat pengujian agar tidak bergantung CDN. Tidak menambah dependensi.
- DB lokal `.env` diverifikasi berakhiran `_test`; akun browser sintetis `sbx_admin`, `sbx_guru_biasa`, `sbx_murobi_a`, `sbx_pengurus_a`, `sbx_ortu_a`. Smoke HTTP membuat/membersihkan fixture sintetisnya sendiri. Tidak memakai dump pribadi untuk bukti audit.
- Checkout tetap pada branch fitur yang diminta. Baseline dan implementasi asli disajikan dari snapshot sementara `git archive` di `/private/tmp`, dengan konfigurasi/dependensi lokal melalui symlink; bukan Git worktree. Port audit akhir 8931, baseline 8933, implementasi asli 8934.
- Matriks mencatat **33 respons 503, 6 respons 500, 3 respons 403**, semuanya identik dengan baseline: halaman V4, konseling V3, dan publikasi murobi sebagaimana tercantum per alamat di bukti. Ini batas fixture/lingkungan atau perilaku sebelumnya, bukan bukti alur bisnisnya sehat. Halaman galat yang memakai Layout tetap diperiksa. Audit ini tidak mengklaim pengujian alur V4 sehat secara menyeluruh.
- Halaman lama yang belum memakai Layout tetap di luar kriteria Tahap 1 dan dicatat di log browser. Detail dengan ID tertentu tidak seluruhnya tercakup matriks menu. Galeri menyediakan pengujian komponen sebelum diadopsi tahap berikutnya.
- Satu probe tindakan `data-confirm` pada Tahun Ajaran dilewati karena fixture tidak menyediakan semester nonaktif/arsip; empat halaman lama lain diuji. Dua suite statis V3 ber-gate tetap dilewati, sama seperti catatan implementator. Tidak mengubah gate untuk meluluskan pengujian.
- Safari/iOS, Firefox, perangkat fisik, pembaca layar, axe, cPanel/produksi belum diuji. Klaim kelulusan terbatas pada fondasi Tahap 1 dan bukti di atas, bukan sertifikasi seluruh aksesibilitas atau fungsi bisnis.
- Direktori pekerjaan lain `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/` tidak disentuh dan tidak dimasukkan commit.

Perintah inti dari root repo setelah konfigurasi sandbox tersedia:

```sh
php tests/redesain_web_tahap1_static.php
php -S 127.0.0.1:8931 tests/redesain_web_tahap1_router.php
# Terminal lain, server tetap berjalan:
BASE_URL=http://127.0.0.1:8931 OUT_DIR=/private/tmp/audit-tahap1 node tests/browser/uji-redesain-web-tahap1.mjs
PERAPIHAN_AUDIT_DB=1 BASE_URL=http://127.0.0.1:8931 OUT_DIR=/private/tmp/audit-tahap1 node tests/browser/uji-redesain-web-tahap1-audit.mjs
PERAPIHAN_AUDIT_DB=1 BASE_URL=http://127.0.0.1:8931 MATRIX_OUT=/private/tmp/matriks-final.json node tests/browser/uji-redesain-web-tahap1-matriks.mjs
BASE_URL=http://127.0.0.1:8931 OUT_DIR=/private/tmp/regresi-tahap1 node tests/browser/uji-perapihan.mjs
PERAPIHAN_RUN_WEB=1 PERAPIHAN_WEB_PORT=8932 php tests/perapihan_web_smoke.php
ALUMNI_RUN_WEB=1 php tests/alumni_web_smoke.php
KREDENSIAL_RUN_WEB=1 php tests/kredensial_web_smoke.php
PENEMPATAN_RUN_WEB=1 php tests/penempatan_web_smoke.php
PENUGASAN_RUN_WEB=1 php tests/penugasan_web_smoke.php
```

Untuk pembanding, jalankan skrip auditor yang sama terhadap server snapshot baseline/implementasi asli pada port berbeda. Snapshot hanya memuat kode terlacak pada hash yang disebut di atas; konfigurasi lokal dan token tidak masuk commit. Berhenti setelah commit dan push hasil audit. Tidak merge, deploy, migrasi produksi, atau memulai Tahap 2.
