# Redesain website Tahap 1 — fondasi kerangka & komponen (22 Oktober 2026)

Status: **lulus audit independen Codex setelah koreksi terarah (22 Oktober 2026).** Belum di-merge, belum dideploy, tanpa migrasi database. Tahap 2 belum dimulai.

- Branch `claude/redesain-web-tahap-1` dibuat dari `claude/rencana-redesain-web` `7cf3a31`. Branch dokumen belum seluruhnya digabung: `origin/main` `c7b3391` baru memuat `9f92292`, belum memuat `7cf3a31` (penetapan peran dan urutan tahap). Sesuai `AGENTS.md`, Tahap 1 dibuat dari branch dokumen. Tidak ada worktree.
- Sumber: `docs/redesain-web/rencana.md`, `keputusan.md`, `cetak-biru.html` (layar K-1 s.d. K-4, bagian "Kerangka responsif dan sistem komponen", baris Tahap 1 di "Urutan tahap pengerjaan"). Belum ada audit tahap sebelumnya.
- Kriteria selesai: *halaman yang sudah memakai `Layout` tampil benar di 375px, 768px, 1280px tanpa geser horizontal halaman.*

Hasil audit: [AUDIT-CODEX.md](AUDIT-CODEX.md). Koreksi auditor mencakup Batal dengan alasan kosong beserta fokus, penolakan respons bukan JSON pada proses massal, footer panel pendek di dasar layar, serta CSRF server pada fixture tanpa JavaScript. Browser akhir 91/0, probe auditor 25/0, regresi browser 56/0, smoke HTTP 300/0; batas uji tetap dicatat dalam laporan.

## Perubahan implementator

| Berkas | Isi |
| --- | --- |
| `app/Ui/Layout.php` | Kerangka tiga bentuk. **HP (<576px)**: topbar berisi ≡, judul halaman, dan lonceng; bilah bawah 4–5 menu; laci 85% berisi nama akun, peran, dan Keluar. **576–991px**: rel ikon 46px dengan tooltip; ≡ membuka laci penuh. **≥992px**: sidebar 240px dengan grup yang dapat dilipat, plus menu profil (nama, peran, Ganti password, Keluar). **≥1400px**: isi dibatasi 1320px. Daftar "Kemampuan aktif" dipindah dari atas sidebar ke menu profil dan laci (K-1). Pesan flash ditandai untuk toast. Skrip dipindah ke `assets/ui/alhasan.js`; aset diberi penanda versi `?v=filemtime`. ID lama `#ah-nav-toggle` (tombol), `#ah-sidebar`, `#ah-konten` dan perilaku laci (inert, perangkap Tab, Esc) dipertahankan. |
| `app/Ui/Navigation.php` | `bottomBar()` memilih butir bilah bawah dari peta menu yang **sudah ada** (Beranda + prioritas Perizinan, Pengajian, Nilai, Sikap, Rapor, Pembinaan, Santri, …; satu butir per modul; sub-halaman menyorot modulnya). `find()` mencari butir menurut kunci. Peta menu dan hak akses tidak berubah. |
| `app/Ui/Status.php` (baru) | Kamus warna status: hijau selesai/disetujui/lunas/terbit, biru diajukan/baru/diserahkan, kuning menunggu/perlu perbaikan/belum lunas, merah ditolak/ditarik/gagal, abu draf/arsip/dibatalkan. Awalan "Menunggu…/Perlu…/Belum…" kuning. |
| `app/Ui/functions.php` | `ah_status()`, `ah_menu()` (menu ⋯ berbasis `<details>`; bahaya paling bawah dan merah; tindakan bermutasi berupa formulir POST + CSRF dengan atribut dialog), `ah_panel_open/close()` (panel samping), `ah_selection_bar()`, `ah_error_summary()`. |
| `assets/ui/alhasan.js` (baru) | Laci, grup terlipat (disimpan di `localStorage` peramban, bukan per akun), tooltip rel, menu ⋯ (posisi tetap agar tidak terpotong wadah tabel; Esc, klik luar), toast 5 detik, dialog konfirmasi `<dialog>` (judul, akibat, tombol merah, alasan wajib) yang **menggantikan `confirm()` untuk `form[data-confirm]`**, panel samping (Esc/scrim kembali ke alamat daftar), tombol "Menyimpan…" dan pelepasan saat kembali lewat Back, chip tab di HP, kartu tabel `.ah-table--cards`, bilah pilihan massal, `AlHasan.post()` ber-CSRF, dan `AlHasan.bulk()` (berurutan satu per satu, bilah "17 / 24", daftar gagal + sebab, ulangi yang gagal, hentikan). |
| `assets/ui/alhasan.css` | Token ukuran (sidebar 240, rel 46, panel 420, bilah bawah 60), gaya seluruh komponen di atas untuk tiga ukuran, chip tab dan kartu tabel di HP, cetak membuang rel/bilah bawah/toast/pilihan. |
| `portal/_ui.php` | `portal_status_badge()` memakai kamus bersama. Hasil untuk kelima status izin identik dengan sebelumnya (diuji S-1). |
| `portal/notifikasi.php` | Baris lencana diberi `flex-wrap`. Halaman ini sudah melebar 13px di 375px sejak baseline (lihat `banding-baseline.txt`); diperbaiki karena kriteria Tahap 1. |
| `tests/…` | `redesain_web_tahap1_static.php`, `browser/uji-redesain-web-tahap1.mjs`, router uji `redesain_web_tahap1_router.php` dan galeri komponen `fixtures/redesain_web_komponen.php` (alamat `/__uji/komponen.php` hanya ada di router uji; tetap mewajibkan admin dan CSRF; tidak menulis database). |

Panel samping tanpa JavaScript tampil sebagai bagian halaman biasa di alamatnya sendiri (`?action=create/edit`); dengan JavaScript menjadi panel kanan 420px (≥992px; daftar didorong ke kiri pada ≥1200px), 70% lebar (576–991px), atau layar penuh (<576px), dengan tombol simpan menempel di bawah.

## Batas cakupan dan keputusan implementator

Tahap 1 hanya menyediakan kerangka dan komponen. Hal berikut **sengaja tidak** dikerjakan dan menunggu tahapnya:

1. Peta menu 17 menu/7 grup, label tanpa versi, pengalihan alamat lama, pemilih peran (AR), pencarian menu (AQ), lonceng untuk semua akun (AK), dialog Keluar, dan Beranda per peran: **Tahap 2**. Sementara itu lonceng hanya tampil bagi akun yang sudah punya menu Notifikasi, Keluar tetap tautan `admin/logout.php`, dan peran ditampilkan sebagai teks hanya-baca.
2. Bilah bawah memakai peta prioritas sementara dari menu lama. Rel ikon admin masih menampilkan sekitar 40 ikon sampai Tahap 2 meringkas menjadi 17.
3. Halaman asli belum memakai panel samping, menu ⋯, kartu tabel, bilah pilihan, maupun proses massal. Komponen diuji di galeri uji; halaman dipindahkan bertahap mulai Tahap 3. `onsubmit="return confirm(…)"` dan `confirm()` di halaman lama tetap ada sampai halamannya dipindahkan. Hanya `form[data-confirm]` yang otomatis memakai dialog baru.
4. Halaman tanpa `Layout` (`admin_dashboard.php`, kanal notifikasi, PSB, rekap, berita, galeri, unduhan, `ubah_password.php`) di luar kriteria Tahap 1; dipindah di Tahap 2–3.
5. Tidak ada perubahan database, hak akses, API, atau aplikasi mobile.

## Bukti

DB sandbox lokal `.env` (`*_test`, akun sintetis `sbx_*`), `php -S 127.0.0.1:8931 tests/redesain_web_tahap1_router.php`, Chromium Playwright dengan Bootstrap/Font Awesome dari `tests/browser/node_modules` (tanpa jaringan CDN).

| Bukti | Hasil | Berkas |
| --- | --- | --- |
| `php tests/redesain_web_tahap1_static.php` | 68/0 | `uji-statis.txt` |
| `node tests/browser/uji-redesain-web-tahap1.mjs` | 91/0 | `uji-browser.txt` |
| Banding baseline `7cf3a31` vs branch ini: status HTTP & geser horizontal setiap menu, lima peran, tiga lebar | Status identik di 88 baris; satu-satunya geser (notifikasi 375px, 13px) sudah ada di baseline dan kini 0 | `banding-baseline.txt` |
| Seluruh `tests/*_static.php` | exit 0, kecuali `v3_phase2/3` exit 77 (gate DB, sama dengan baseline) | `regresi.txt` |
| Smoke web perapihan 56/0, alumni 61/0, kredensial 51/0, penempatan 47/0, penugasan 85/0; `browser/uji-perapihan.mjs` 56/0; `php -l` 99 berkas | lulus | `regresi.txt` |
| Tangkapan layar terpilih | — | `tangkapan/` |

Yang dibuktikan uji browser (T1-A s.d. T1-K):

- **T1-A:** setiap menu sidebar admin, guru, murobi, pengurus, dan orang tua (plus formulir `?action=create` admin) di 375/768/1280: tanpa geser horizontal, satu `h1`, tanpa galat JS, dan bentuk navigasi tepat per ukuran (bilah bawah / rel / sidebar).
- **T1-B, T1-C:** laci (≤85%, fokus, perangkap Tab, Esc, Keluar), sasaran sentuh ≥44px, rel 46px bertooltip, sidebar 240px, grup terlipat yang diingat. Grup berisi halaman aktif tetap terbuka.
- **T1-D s.d. T1-F:** menu ⋯ (urutan, merah, tidak terpotong, Esc, klik luar), dialog (nama objek, alasan wajib tanpa POST, batal tanpa POST dan tombol tidak terkunci, Esc), toast 5 detik sementara pesan tetap di halaman.
- **T1-G, T1-H:** panel di tiga ukuran, galat isian, dan "Menyimpan…". Pilihan massal, proses massal 5/5 dengan 1 gagal beserta sebabnya, dan ulangi yang gagal.
- **T1-I s.d. T1-K:** kamus status, kartu tabel dan chip tab di HP, tanpa JavaScript, dan media cetak.

Status bukan 200 pada beberapa halaman (V4 503, konseling V3 500, publikasi murobi 403, rapor orang tua 503) berasal dari data sandbox dan **identik dengan baseline**. Halaman 503 yang dirender di dalam kerangka tetap diuji tata letaknya.

## Belum dijalankan / batas

- Hanya Chromium. Safari/iOS, Firefox, perangkat fisik, pembaca layar, dan pemeriksaan axe belum dijalankan.
- `tests/browser/uji-audit-interaksi.mjs` (audit lama, butuh manifest fixture audit) tidak dijalankan. Bagian "batal konfirmasi"-nya menunggu dialog bawaan peramban (`page.on('dialog')`) untuk `form[data-confirm]`, padahal Tahap 1 sengaja menggantinya dengan dialog bersama (K-3). Harapan itu perlu disesuaikan, bukan dianggap regresi.
- Uji memakai DB sandbox bersama dari `.env`. Smoke test menulis data sintetisnya sendiri ke DB itu.
- Tidak ada smoke cPanel/produksi.

## Handoff implementator ke auditor (riwayat)

Mohon audit terhadap cetak biru K-1 s.d. K-4 dan kriteria Tahap 1. Jalankan:

```bash
php tests/redesain_web_tahap1_static.php
php -S 127.0.0.1:8931 tests/redesain_web_tahap1_router.php &
BASE_URL=http://127.0.0.1:8931 node tests/browser/uji-redesain-web-tahap1.mjs
```

Tahap 2 tidak dimulai sebelum Tahap 1 lulus audit dan ada instruksi Human Developer.
