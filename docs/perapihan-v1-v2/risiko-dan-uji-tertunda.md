# Risiko dan pengujian yang masih menunggu verifikasi

Paket "Koreksi dan Modernisasi UI/UX V1–V2" — keputusan pengguna 30 Agustus 2026.

> Dokumen ini sengaja memuat hal-hal yang **belum** dapat dibuktikan. Tidak ada
> satu pun butir di sini yang boleh dihitung sebagai lulus.

---

## A. MENUNGGU VERIFIKASI

### A-1. Safari fisik (macOS dan iOS)

**Status:** belum diuji.
**Mengapa:** lingkungan implementasi hanya memiliki Chromium. Chromium **bukan**
bukti Safari; emulasi Chromium juga bukan.

**Langkah lanjutan:**

1. Buka `https://<host-uji>/portal/` pada Safari macOS dan Safari iOS.
2. Masuk sebagai admin, lalu murobi, lalu guru non-murobi.
3. Buka: beranda, modul Pengajian (kedua tab), Laporan Kehadiran (ketiga tab),
   Akun & Hak Akses, Data Santri (formulir wali), Rekonsiliasi Wali.
4. Pada iOS, uji laci navigasi: buka lewat tombol menu, tutup lewat ketukan latar.
5. Cetak `laporan_absensi_cetak.php` ke PDF dari Safari.

**Hasil yang diharapkan:**

- halaman tidak melebar (tidak ada gulir horizontal pada seluruh halaman);
- laci navigasi membuka dan menutup;
- `position: sticky` topbar tidak menutupi konten saat menggulir;
- PDF tetap memuat identitas pesantren, filter (termasuk baris **Penyajian**),
  pembuat, waktu, dan nomor halaman, dengan margin yang sama seperti Fase 5;
- tidak ada sidebar pada hasil cetak.

**Risiko bila tidak diuji:** `100dvh`, `position: sticky`, dan `-webkit-overflow-scrolling`
punya perilaku berbeda pada Safari lama. Dampak terburuk yang masuk akal: laci
navigasi terpotong atau topbar menutupi judul — mengganggu, tidak merusak data.

### A-2. Perangkat Android/iOS fisik untuk halaman web

**Status:** belum diuji. Uji 390 px dilakukan lewat emulasi viewport Chromium,
bukan perangkat nyata.

**Langkah lanjutan:** ulangi A-1 langkah 2–4 pada satu perangkat Android dan satu
iPhone. **Hasil yang diharapkan** sama, ditambah: target sentuh terasa nyaman
(tombol menu, item menu, tombol tabel).

### A-3. Audit aksesibilitas otomatis

**Status:** belum dijalankan.
**Yang sudah ada:** fokus terlihat, tautan lompat ke konten, `aria-current`,
`aria-expanded`, `<caption>` tabel, lencana selalu bertext, `prefers-reduced-motion`
— seluruhnya diuji.
**Yang belum:** pemindaian kontras dan peran ARIA menyeluruh.

**Langkah lanjutan:** jalankan axe-core atau Lighthouse pada beranda, Akun & Hak
Akses, Data Santri (formulir), dan Laporan Kehadiran.
**Hasil yang diharapkan:** tidak ada pelanggaran serius; kontras teks utama dan
lencana memenuhi WCAG AA.

### A-4. Migrasi 010 pada produksi

**Status:** **belum dijalankan.** Hanya dijalankan pada `webalhasan_test`.
**Langkah lanjutan:** prosedur lengkap pada `migrasi-dan-rollback.md` §4.
**Hasil yang diharapkan:** jumlah baris `wali`, `santri_wali`, `santri` identik
sebelum dan sesudah; `SELECT COUNT(*) FROM wali WHERE merged_into_wali_id IS NOT NULL`
= 0 tepat setelah migrasi.

### A-5. Audit Codex

**Status:** **belum dilakukan.** Paket ini berhenti di sini untuk audit.

---

## B. KONFLIK DATA YANG MEMBUTUHKAN KEPUTUSAN MANUSIA

### B-1. Kegagalan baseline `tests/v2_phase4_static.php`

**Bukan disebabkan paket ini.** Sudah gagal pada baseline `c65390d`.

Redesign UI aplikasi mobile (`alhasanApps` PR #8, sudah masuk `main`) memindahkan
layar notifikasi dari `src/app/(app)/(notifikasi)/notifikasi.tsx` ke
`src/app/notifikasi/index.tsx` dan mengeluarkan notifikasi dari bilah tab menjadi
lonceng berlencana di header. Berkas uji Fase 4 masih menuntut path dan struktur
tab yang lama, sehingga 7 pemeriksaan gagal.

**Keputusan yang dibutuhkan:** apakah

1. assertion path pada `tests/v2_phase4_static.php` diperbarui mengikuti keputusan
   redesign mobile (paling mungkin benar, karena redesign sudah disetujui dan
   di-merge), **atau**
2. ada fungsi notifikasi yang memang hilang pada redesign dan perlu dikembalikan.

Paket ini **tidak** menyentuhnya karena itu keputusan di luar cakupan tujuh
koreksi, dan mengubah berkas uji fase lain tanpa keputusan pengguna justru
menyembunyikan pertanyaannya.

### B-2. Perbedaan collation `santri` dan `wali`

`santri` (warisan V1) memakai `utf8mb4_general_ci`; `wali` (migrasi 002) memakai
`utf8mb4_unicode_ci`. Query pembanding nama memakai `COLLATE` eksplisit sebagai
penyelesaian aman.

**Keputusan yang mungkin dibutuhkan kelak:** menyeragamkan collation tabel.
Itu perubahan skema yang menyentuh data dan berada **di luar cakupan** paket ini.

### B-3. Data lama yang akan muncul pada Rekonsiliasi Wali

Setelah paket ini dirilis, halaman Rekonsiliasi Wali kemungkinan besar akan
menampilkan sejumlah besar entri "relasi belum lengkap" — santri lama dan hasil
impor/PSB yang hanya punya nama orang tua pada kolom lama.

**Ini bukan cacat.** Itu memang keadaan data yang selama ini tidak terlihat.
**Keputusan yang dibutuhkan:** prioritas dan urutan penyelesaiannya oleh admin
pesantren. Sistem sengaja **tidak** menyelesaikannya sendiri.

### B-4. Wali yang punya akun login dan menjadi kandidat penggabungan

Penggabungan diblokir bila sisi sumber (atau kedua sisi) memiliki akun login.
Bila di lapangan ditemukan duplikat yang keduanya sudah bertautan akun, admin
harus memutuskan lebih dulu akun mana yang dipertahankan, pada halaman Akun &
Hak Akses. Sistem sengaja tidak memilih sendiri karena pilihan itu mengubah
santri yang dapat dilihat orang tua.

---

## C. RISIKO YANG DITERIMA (dengan mitigasi)

| Risiko | Mitigasi | Sisa risiko |
| --- | --- | --- |
| Halaman kelompok B (`inventaris-halaman.md`) memperoleh kerangka baru tanpa ditulis ulang | Diuji terbuka lewat HTTP (status 200) dan lewat regresi | Cacat tampilan kecil pada markup lama di dalam kerangka baru. Prioritas pemeriksaan visual auditor. |
| Halaman kelompok D memuat token warna bersama | Hanya `:root` dan empat variabel Bootstrap yang global | Perbedaan warna latar/tautan pada halaman PSB dan konten website. Disengaja. |
| Impor dan PSB tidak lagi membuat wali otomatis | Muncul pada laporan rekonsiliasi | Admin harus menyelesaikan relasi secara sadar. Ini memang tujuannya. |
| Pembatasan percobaan masuk memakai `audit_logs` | Fail-open bila penghitungan gagal | Pada basis data dengan `audit_logs` sangat besar, query penghitung bisa lambat. Dibatasi jendela 15 menit; pantau bila `audit_logs` melampaui jutaan baris. |
| Formulir santri memuat sampai 200 kandidat wali pada `<select>` cadangan | Pencarian AJAX sebagai jalur utama; daftar dibatasi dan diberi keterangan | Pada data sangat besar, jalur tanpa JavaScript menjadi kurang nyaman. |

---

## D. YANG SECARA TEGAS TIDAK DIKLAIM

- Paket ini **belum lolos audit Codex**.
- Paket ini **belum siap produksi**.
- Belum ada merge, push, deploy, atau perubahan produksi apa pun.
- Tidak ada pekerjaan PRD V3 yang dimulai.
- WhatsApp tetap **OFF** dan **DITANGGUHKAN** — tidak disentuh paket ini.
