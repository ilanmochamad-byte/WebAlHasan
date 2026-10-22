# Redesain website Tahap 2 — navigasi, peran & Beranda (22–23 Oktober 2026)

Status audit 23 Oktober 2026: **BELUM LULUS audit independen Codex; empat temuan P2 dikembalikan ke implementator.** Lihat `AUDIT-CODEX.md` dan `audit-codex/`. **Koreksi Claude atas F-1–F-4 (23 Oktober 2026) ada di `KOREKSI-CLAUDE.md` dan `koreksi/`, menunggu audit ulang Codex**; ini bukan klaim lulus. Catatan implementator berikut dipertahankan sebagai riwayat implementasi; butir yang diganti koreksi ditandai. Belum di-merge, belum dideploy, tanpa migrasi database produksi. Tahap 3 belum dimulai.

- Branch `claude/redesain-web-tahap-2` dibuat dari `d6c5da0` (ujung `claude/redesain-web-tahap-1` yang lulus audit Codex). `origin/main` `ee52d65` sudah memuat implementasi Tahap 1 (`4802e35`) tetapi belum memuat commit audit `d6c5da0` (koreksi auditor), sehingga Tahap 2 dibuat dari commit audit itu. Tidak ada worktree.
- Sumber: `AGENTS.md`, `docs/redesain-web/rencana.md`, `keputusan.md`, `cetak-biru.html` (A-1 s.d. A-5, B-1 s.d. B-5, N-3, peta menu per peran, pemetaan menu lama, baris Tahap 2 di "Urutan tahap pengerjaan"), dan `tahap-1/AUDIT-CODEX.md`.
- Cakupan keputusan: A–K, L, P, AA, AK, AQ, AR.
- Kriteria selesai: *setiap peran uji melihat menu dan tugas tertundanya; alamat lama mengalihkan; notifikasi terbuka hanya untuk pemiliknya.*

## Perubahan

| Berkas | Isi |
| --- | --- |
| `app/Ui/Navigation.php`, `NavigationContext.php` (baru) | Peta menu per **peran aktif**. Admin 17 menu dalam 7 grup (A–E): Beranda · Kegiatan santri (Perizinan, Pengajian, Pembinaan) · Akademik (Penilaian & rapor) · Data pesantren (Data santri, Asatidz & pengurus, Kelas & kobong) · PSB (Pendaftar, Pembayaran, Rekap keuangan) · Website (Konten website) · Pengaturan (Tahun ajaran, Penugasan, Akun & hak akses, Kanal notifikasi, Data sistem lama). Guru: Mengajar (Pengajian bila punya jadwal atau bukan guru mapel; Nilai semester) + grup Murobi berisi Antrean izin, Sikap binaan, Pembinaan binaan (G). Pengurus: Perizinan; Sikap semester dan Pembinaan hanya bila pembimbing (J). Bagian Pendidikan: Penilaian & rapor, Nilai pengganti. Orang tua: Beranda, Izin anak, Rapor, Informasi pembinaan (F), Notifikasi (K). Setiap menu menyimpan daftar halaman miliknya dan **tab modul**; halaman lama yang bukan lagi butir menu ditempatkan di bawah menu barunya. Pencocokan memakai nama skrip + parameter wajib (mis. `sikap_semester.php?mode=murobi`, `admin_penugasan.php?jenis=mata_pelajaran`). Bilah bawah per peran sesuai cetak biru. Tanpa label V2/V3/V4. Tetap bukan kontrol akses. |
| `app/Ui/Peran.php` (baru) | Peran aktif di sesi (AR): daftar peran dihitung ulang dari hak nyata akun setiap request; pilihan diikat ke id akun; nilai di luar hak diabaikan; tanpa perubahan database. Bagian Pendidikan ditawarkan hanya bila akun memegang penugasan Pendidikan miliknya sendiri. |
| `portal/peran.php` (baru) | Pemilih peran: hanya POST + CSRF dari akun masuk (GET 405, tanpa token 419). Peran tidak sah ditolak dengan pesan. Kembali ke halaman asal hanya bila halaman itu ada di menu peran baru, selain itu ke Beranda. |
| `app/Ui/Layout.php` | Topbar: pencarian menu (desktop; tombol ⌕ di tablet membuka laci dengan fokus di kotak cari; HP lewat laci) — AQ; pil "Peran: … ▾" (desktop) dan pemilih peran di atas laci (HP/tablet) — A-2; lonceng untuk **semua akun** dengan pratinjau 5 notifikasi terbaru dan "Lihat semua" — K, N-3; menu profil dengan Ganti kata sandi dan Keluar lewat **dialog + POST ber-CSRF** — A-4. Tab modul dari peta menu. Bilah bawah dengan butir Menu yang membuka laci. Mode `bare` (tanpa menu/cari/peran/lonceng) untuk penggantian kata sandi wajib. Halaman milik peran lain akun yang sama mengalihkan peran aktif dengan pesan "Peran beralih ke …". |
| `portal/index.php` | A-1: halaman masuk dua panel (desktop) / satu kartu (HP), tombol Tampilkan kata sandi, lima pesan ditulis ulang. B-1 s.d. B-5: Beranda per peran (lihat bawah). |
| `app/Beranda/BerandaService.php` (baru) | Data Beranda, **hanya membaca**, prepared statement. Kartu prioritas admin delapan urutan L + AA dengan batas tertunda 24 jam (P); statistik; jadwal hari ini; kemajuan nilai guru mapel; sikap binaan murobi; angka pengurus/pembimbing; kemajuan per kelas Bagian Pendidikan; ringkasan anak orang tua. Setiap angka memakai cakupan akun (admin = pengawasan; selainnya lewat `izin_service()`, cakupan penugasan, atau relasi wali). Kartu yang gagal dihitung (mis. tabel modul belum dimigrasi) disembunyikan dan dicatat ke log. |
| `portal/notifikasi.php`, `app/Notification/*` | AK: penjaga perizinan `portal/_guard.php` diganti `requireWebUser()`; pemeriksaan pemilik tetap di layanan/repositori (penerima selalu dari sesi). Chip Semua · Belum dibaca · Sudah dibaca · Perizinan · Rapor · Pembinaan (kategori dari `event_type` yang sudah tercatat, daftar tetap di repositori, bukan masukan SQL). Kelompok Hari ini/Kemarin/Pekan ini/Lebih lama. Ketuk notifikasi = tandai dibaca lalu buka tujuannya (`?buka=`). |
| `admin/ubah_password.php` | A-3: di dalam kerangka; saat wajib, kerangka polos dengan pesan "Ganti kata sandi sebelum melanjutkan"; syarat sebagai daftar centang langsung; server tetap memeriksa syarat yang sama. |
| `admin/logout.php` | A-4: keluar tetap hanya lewat POST ber-CSRF; GET menampilkan konfirmasi di dalam kerangka (alamat lama dan tanpa JavaScript). |
| `app/Ui/Denial.php`, `Authorization.php`, `PortalGuard.php`, 6 halaman V4 | A-5: penolakan tampil di kerangka (menu peran aktif tetap), menyebut halaman, siapa yang berhak, dan bahwa admin pesantren yang menambah peran/penugasan; status 403 tetap. Teks polos `403 — Akses ditolak.` di `sikap_semester`, `verifikasi_semester`, `rapor_penanda`, `rapor_semester`, `nilai_semester`, `rencana_nilai` diganti. |
| `portal/_guard.php`, `_ui.php`, 8 halaman perizinan | Cakupan perizinan bawaan mengikuti peran sesi (`portal_requested_mode()`); `?mode=` lama tetap berlaku dan menyelaraskan peran aktif; deretan tombol mode per halaman diganti satu baris keterangan cakupan (A-2). Layanan perizinan tetap mengabaikan cakupan di luar hak akun. |
| `admin/cek_login.php` | Setelah masuk, peran aktif kembali ke bawaan (AR). |
| `app/Ui/Status.php` | Kamus: `Lengkap` hijau, `Belum dibaca` biru, `Dibaca`/`Belum terbit`/`Belum waktunya` abu. |
| `admin/sidebar.php` | Halaman lama berkerangka sendiri ikut memakai peta menu baru (grup tanpa label tidak menampilkan judul kosong). |
| `assets/ui/alhasan.css`, `alhasan.js` | Gaya dan perilaku: combobox pencarian (panah, Enter, Esc, tanpa permintaan jaringan), pemilih peran, lonceng, laci (perangkap Tab mencakup tombol/isian), kartu tugas, statistik, jadwal, kemajuan, kartu orang tua, pusat notifikasi, halaman masuk, tampilkan kata sandi, daftar centang kata sandi, tab modul menjadi chip di HP. |

### Beranda per peran

- **B-1 Admin:** tab Tugas saya · Ringkasan administrasi (tab kedua membuka `admin_dashboard.php` lama; dipindah ke kerangka di Tahap 3, N-4). "Perlu tindakan" 4 tempat dari 8 kartu urutan L + AA: izin menunggu penetapan murobi, pendaftar PSB baru, pembayaran PSB belum lunas, notifikasi gagal, izin belum diputus > 1 hari, bahan rapor perlu perbaikan, santri tanpa kelas/kamar, rekomendasi pembinaan belum ditindaklanjuti. Kartu 0 disembunyikan, kelebihan "+n lainnya · Lihat semua" (`?semua=1`), semua 0 → "Tidak ada yang perlu ditindaklanjuti". Kartu kuning bila ada yang tertunda > 1 hari. Setiap kartu membuka daftar tersaring. Statistik santri aktif, izin aktif hari ini, pertemuan hari ini, hunian asrama; pengajian hari ini; pengajuan izin terbaru.
- **B-2 Guru & murobi:** jadwal hari ini dengan satu tombol (Buka pertemuan mulai 30 menit sebelum waktunya / Lanjutkan / Selesai ✓ / Belum waktunya); kemajuan nilai per mapel–kelas; bagian "Sebagai murobi" hanya bila ada penugasan murobi.
- **B-3 Pengurus/pembimbing:** "Ajukan izin" di atas (penuh lebar di HP); izin aktif, menunggu keputusan, sikap belum diisi, pelanggaran bulan ini, santri mencapai ambang (AA) — tiga terakhir hanya untuk pembimbing (J); pengajuan izin saya; santri binaan.
- **B-4 Bagian Pendidikan:** "Kemajuan semester …", tombol Buka kelengkapan; kelas, bahan terverifikasi, perlu perbaikan, rapor terbit; tabel per kelas (nilai masuk, sikap, kehadiran, terverifikasi, rapor) yang membuka kelengkapan kelas itu.
- **B-5 Orang tua:** pilih anak (hanya anak yang terhubung; `?anak=` lain diabaikan), kartu anak (NIS, kelas, kamar, hubungan), izin terakhir, rapor terbit, informasi pembinaan belum dibaca. Semua baca-saja.

## Penafsiran dan batas cakupan implementator

Mohon auditor menilai butir berikut secara khusus:

1. **Lima peran tampilan** mengikuti peta menu cetak biru: Admin, Bagian Pendidikan, Guru (termasuk tugas murobi), Pengurus, Orang tua. Gambar A-2 memperlihatkan Guru dan Murobi sebagai dua pilihan; peta menu (keputusan G) menaruh grup Murobi di dalam peran Guru, dan itu yang diikuti.
2. *(Diganti koreksi F-1 — lihat `KOREKSI-CLAUDE.md`: peran kini mempersempit halaman lewat gerbang server dan tidak pernah berganti sendiri.)* ~~**Peran tidak mempersempit hak server.** Peran menentukan menu, Beranda, dan cakupan bawaan perizinan. Guard halaman tetap berdasarkan hak akun (sama seperti sebelumnya). Bila akun membuka halaman milik perannya yang lain (tautan lama, notifikasi), peran aktif ikut beralih dengan pesan. Karena itu tombol "Buka sebagai …" di A-5 tidak muncul: penolakan berarti akun tidak berhak di peran mana pun.~~
3. **Alamat lama.** Pengalihan HTTP yang sudah ada (`admin_login.php`, `admin_santri.php`, `admin_izin.php`, `proses_mutasi_alumni.php`) tetap dan diuji. Halaman yang tidak lagi menjadi butir menu tetap terbuka dan menyorot menu/tab barunya (rekonsiliasi wali, penugasan murobi/pembimbing, buat pengajuan, kelulusan). Pengalihan murobi/pembimbing ke Penugasan sengaja menunggu Tahap 6 (G-1). *(Koreksi F-1: `?mode=` lama untuk peran lain kini menawarkan "Buka sebagai …" / "Lanjutkan sebagai …", tidak lagi menyetel peran lewat GET.)*
4. **Keputusan H ditunda ke Tahap 4.** Menu "Laporan absensi pengajian" untuk Bagian Pendidikan menambah hak akses (`admin/_laporan_guard.php`); cetak biru menempatkan H dan J-6 di Tahap 4. Tahap 2 tidak menampilkan menu itu dan tidak mengubah penjaganya.
5. **B-5 tanpa nama pembimbing.** Nama pembimbing adalah data baru bagi orang tua (keputusan Z, Tahap 5); kartu anak menampilkan NIS, kelas, kamar, hubungan.
6. **Fungsi lama tetap terjangkau lewat tab:** murobi (Menunggu keputusan · Riwayat · Laporan), Izin anak (Daftar izin · Ringkasan · Laporan), Informasi pembinaan (Informasi · Laporan pembinaan), Penilaian & rapor admin (Rencana · Kelengkapan · Penerbitan · Penanda tangan · Nilai semester · Nilai pengganti · Sikap semester · Mata pelajaran). Halaman yang punya tab sendiri (Pengajian, Penugasan, Akun) kini memiliki dua baris tab sampai halamannya dipindah di tahap berikutnya.
7. Kartu ke-3 memakai kata keputusan L "Pembayaran PSB belum lunas" (gambar B-1 menulis "menunggu"). Kartu ke-5 menghitung status `Diajukan` lebih dari 24 jam; `Perlu Penetapan Admin` sudah dihitung kartu ke-1.
8. Detail/buka notifikasi milik orang lain mempertahankan kontrak V2 Fase 4 (WN-5a): halaman 200 dengan pesan "Notifikasi tidak dapat dibuka", layanan menolak 403, isi tidak pernah tampil, basis data tidak berubah.
9. Halaman tanpa `Layout` (Ringkasan administrasi, PSB, rekap, konten website, kanal notifikasi, rekap santri) memakai peta menu baru lewat `admin/sidebar.php`; pemindahan kerangkanya Tahap 3.
10. Label bilah bawah pengurus "Perizinan" (cetak biru menulis "Izin").
11. *(Dikoreksi atas catatan audit.)* Pratinjau lonceng memanggil `NotificationCenterService::index()`: tiga SELECT notifikasi tambahan per halaman (COUNT daftar, SELECT 5 baris, jumlah belum dibaca), di luar `ui_context`.

Tidak ada perubahan skema/migrasi, API bisnis, atau aplikasi mobile. Tidak ada perubahan hak akses selain AK (notifikasi milik sendiri terbuka untuk semua akun yang masuk).

## Pengujian yang disesuaikan

Harapan lama yang menyebut label/kunci menu V1–V4 atau teks yang memang diganti keputusan A–E, AK, A-1, A-4, A-5 diperbarui tanpa melemahkan maksudnya (alamat lama tetap menandai menu yang sama, CSRF tetap diperiksa, penolakan tetap 403/tanpa isi):

| Uji | Perubahan |
| --- | --- |
| `alumni_static` AS-7, `penempatan_static` PS-5, `penugasan_static` FS-12, `perapihan_static` | Memeriksa tab/halaman di peta menu baru (Data santri › Kelulusan & alumni, › Penempatan; Pengaturan › Penugasan memuat halaman murobi/pembimbing lama; Asatidz). |
| `redesain_web_tahap1_static` S-2 | Bilah bawah per peran (urutan cetak biru, Menu, orang tua tanpa Menu). |
| `v2_phase4_static` | `ah_pagination` dan `Csrf::requireValid`/`Csrf::input` menggantikan adaptor perizinan. |
| `alumni_web_smoke` AW-3i, `penugasan_web_smoke` FW-1e, `perapihan_web_smoke` PM-7b | Tab Kelulusan & alumni aktif; halaman murobi/pembimbing lama terbuka di bawah Penugasan; pesan gagal masuk baru. |
| `browser/uji-redesain-web-tahap1.mjs` | Keluar berupa tombol; perangkap Tab laci mencakup tombol/isian; selektor tab halaman dibedakan dari tab modul; grup terlipat dipilih yang punya tombol. |
| `browser/uji-perapihan.mjs` | Sapaan Beranda "Assalamu'alaikum"; tab halaman dibedakan dari tab modul; penolakan A-5. |

`v2_phase1_static`/`v2_phase2_static` tidak diubah: `PortalGuard`/`Authorization` tetap memanggil `Denial::render`, dan komentar `portal/index.php` tetap menyebut bahwa Beranda tidak memuat `portal/_ui.php`. Kartu murobi di Beranda menaut `izin_antrean.php?mode=murobi` sehingga `v2_phase2_navigasi_murobi` tetap lulus tanpa diubah.

## Bukti

Lingkungan: PHP 8.4, Node 26, Chromium Playwright lokal; Bootstrap/Font Awesome dari `tests/browser/node_modules`. DB sandbox `.env` (`*_test`) tidak diubah oleh fixture Tahap 2; uji Tahap 2 memakai salinan `test_redesain_t2_test` (+ `test_redesain_t2_kosong_test` untuk keadaan semua 0) yang dibuat `tests/redesain_web_tahap2_db.sh`: salin sandbox, terapkan migrasi 014–022 yang belum ada di sandbox (V3 Fase 2–5, V4 Fase 1–4), lalu fixture sintetis `tests/redesain_web_tahap2_seed.php` (akun `sbx_pendidikan`, `sbx_guru_mapel`, `sbx_ganda` admin+guru, `sbx_pengurus_biasa`, `sbx_sandi_wajib`; data V3/V4/PSB/jadwal/notifikasi sintetis).

| Bukti | Hasil | Berkas |
| --- | --- | --- |
| `php tests/redesain_web_tahap2_static.php` | 96/0 | `uji-statis.txt` |
| `php tests/redesain_web_tahap2_http.php` (server sendiri, DB Tahap 2 + DB kosong) | 162/0 | `uji-server.txt` |
| `node tests/browser/uji-redesain-web-tahap2.mjs` (375/768/1280) | 87/0 | `uji-browser.txt` |
| `node tests/browser/uji-redesain-web-tahap1.mjs` di DB Tahap 2, 9 akun, setiap menu × 3 lebar | 107/0 | `uji-browser-tahap1-db-tahap2.txt` |
| Regresi di DB sandbox asli: 22 suite statis (20 exit 0, 2 gate DB V3 exit 77 seperti baseline), `php -l` 371 berkas, smoke HTTP 300/0 (perapihan 56, alumni 61, kredensial 51, penempatan 47, penugasan 85), web V2 Fase 2/4/5 36/46/79, API Fase 4 92/0, navigasi murobi 40/0, integrasi 94/122/53/161, browser perapihan 56/0, browser Tahap 1 91/0, banding baseline 91 alamat tanpa beda status di luar keputusan (hanya notifikasi guru 403→200, AK) dan tanpa geser horizontal | `regresi.txt`, `banding-baseline.tsv` |
| Tangkapan layar terpilih | — | `tangkapan/` |

## Belum dijalankan / batas

- Hanya Chromium. Safari/iOS, Firefox, perangkat fisik, pembaca layar, dan axe belum dijalankan.
- Data Beranda di DB sandbox asli: tabel V3 Fase 2+ dan V4 belum ada, sehingga kartu/angka modul itu tersembunyi di sana (tercatat di log) dan halaman V4 tetap 503 seperti baseline; perilaku lengkap diuji di DB salinan bermigrasi.
- Fixture tanggal jadwal memakai hari saat seed dijalankan; jalankan ulang `tests/redesain_web_tahap2_db.sh` bila hari berganti. Uji server mengubah status baca notifikasi di DB salinan (jalankan ulang skrip DB sebelum mengulang uji browser).
- Kinerja Beranda pada data produksi belum diukur (query hitung sederhana; `kemajuanKelas` dan `kemajuanNilai` memakai opsi layanan V4 per kelas/mapel).
- Tidak ada smoke cPanel/produksi.

## Handoff ke auditor (Codex)

Mohon audit Tahap 2 terhadap A-1 s.d. A-5, B-1 s.d. B-5, N-3, keputusan A–K, L, P, AA, AK, AQ, AR, dan kriteria selesai di atas, termasuk butir "Penafsiran dan batas cakupan". Jalankan dari root repo:

```bash
bash tests/redesain_web_tahap2_db.sh
php tests/redesain_web_tahap2_static.php
php tests/redesain_web_tahap2_http.php
bash tests/redesain_web_tahap2_db.sh
DB_NAME=test_redesain_t2_test php -S 127.0.0.1:8941 tests/redesain_web_tahap1_router.php &
BASE_URL=http://127.0.0.1:8941 node tests/browser/uji-redesain-web-tahap2.mjs
```

Tahap 3 tidak dimulai sebelum Tahap 2 lulus audit dan ada instruksi Human Developer.
