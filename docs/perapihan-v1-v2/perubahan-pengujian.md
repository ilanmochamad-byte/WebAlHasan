# Perubahan pada pengujian lama, dan alasannya

Paket "Koreksi dan Modernisasi UI/UX V1–V2" — keputusan pengguna 30 Agustus 2026.

> **Aturan yang dipegang:** pengujian lama **tidak boleh dilemahkan** hanya agar
> perubahan terlihat lulus. Bila perilaku memang berubah karena keputusan
> pengguna, alasannya didokumentasikan dan ditambahkan pengujian pengganti yang
> setara.
>
> Dokumen ini adalah daftar lengkapnya, supaya auditor dapat memeriksa satu per
> satu apakah setiap perubahan memang dituntut keputusan — bukan disesuaikan agar
> hijau.

---

## 1. Ringkasan

| Berkas uji | Jenis perubahan |
| --- | --- |
| `tests/phase1_static.php` | daftar pengecualian guard + pemeriksaan pengganti **lebih ketat** |
| `tests/phase3_static.php` | pemeriksaan berpindah lokasi + satu pemeriksaan baru |
| `tests/v2_phase1_static.php` | dua pemeriksaan menyesuaikan perilaku baru |
| `tests/v2_phase2_static.php` | blok "hotfix navigasi murobi" ditulis ulang sebagai penjaga yang setara |
| `tests/v2_phase3_static.php` | jumlah migrasi 9 → 10 |
| `tests/v2_phase4_static.php` | lokasi lencana notifikasi |
| `tests/v2_phase5_static.php` | lokasi tautan laporan pada navigasi |
| `tests/v2_phase2_navigasi_murobi.php` | tujuan pasca-login dan alamat modul |
| `tests/v2_phase2_web_smoke.php`, `v2_phase4_web_smoke.php`, `v2_phase5_web_smoke.php` | alamat halaman masuk |
| `tests/browser/cetak-pdf.mjs` | dukungan `CHROMIUM_PATH` (tanpa mengubah perilaku render) |

**Tidak ada** pemeriksaan yang dihapus tanpa pengganti. **Tidak ada** ambang yang
dilonggarkan. **Tidak ada** assertion yang diubah dari "harus ditolak" menjadi
"boleh lewat", kecuali satu baris yang memang merupakan cacat yang diperbaiki
(lihat §4).

---

## 2. Perubahan karena berkas berpindah lokasi

Perilakunya identik; hanya tempat kodenya yang berubah.

| Pemeriksaan | Dulu membaca | Sekarang membaca |
| --- | --- | --- |
| UI jadwal: Pencarian/Detail/Tambah/Ubah/Nonaktifkan/Arsipkan | `admin/admin_jadwal_ngaji.php` | `admin/_pengajian_jadwal.php` |
| UI pertemuan: Simpan draf / Buka & bekukan / Selesaikan | `admin/pertemuan_pengajian.php` | `admin/_pengajian_pertemuan.php` |
| Guard pengguna + batas role admin/guru | `admin/pertemuan_pengajian.php` | `admin/admin_pengajian.php` |
| Lencana notifikasi belum dibaca | `portal/_ui.php` | `ui_context()` + `App\Ui\Navigation` + `App\Ui\Layout` |
| Tautan laporan pada navigasi portal | `portal/_ui.php` | `app/Ui/Navigation.php` |
| Kode status 403 guard portal | `app/Auth/PortalGuard.php` | `App\Ui\Denial` (dipanggil PortalGuard) |

### Pemeriksaan yang DITAMBAHKAN pada kesempatan yang sama

- alamat lama jadwal/pertemuan **meneruskan POST** ke modul ber-guard, bukan
  mengalihkannya melewati validasi mutasi/CSRF;
- potongan tampilan (`_pengajian_*.php`, `_santri_wali_field.php`) **menolak
  permintaan langsung** lewat penjaga `AH_PARTIAL` — ini pengaman baru yang
  sebelumnya tidak ada;
- modul pengajian melindungi seluruh POST dengan `Csrf::requireValid`;
- tab Jadwal dan Pertemuan saling tertaut dengan konteks terbawa.

---

## 3. Perubahan karena keputusan pengguna mengubah perilaku

### 3.1 Tujuan pasca-login (koreksi ke-7)

**Perilaku lama:** `LandingRouter` memilih SATU halaman menurut urutan role —
admin ke dashboard, murobi ke antrean, guru ke jadwal, pengurus/orang tua ke portal.

**Mengapa berubah:** memilih satu halaman berdasarkan urutan role membuat akun
multi-peran kehilangan jalur peran lainnya saat mendarat, dan tiap peran mendapat
kerangka tampilan yang berbeda. Pengguna memutuskan satu pintu masuk dan satu
beranda yang menyusun panel dari kemampuan nyata.

**Perilaku baru:** seluruh akun sah mendarat pada `/portal/index.php`.

**Pengujian pengganti yang setara** (inti hotfix murobi tetap dijaga):

| Dulu | Sekarang |
| --- | --- |
| NAV-1 murobi → `/portal/izin_antrean.php?mode=murobi` | NAV-1a murobi mendarat di beranda **dan** NAV-1b beranda menawarkan pintasan antrean |
| NAV-2 guru → `/admin/pertemuan_pengajian.php` | NAV-2a guru mendarat di beranda, NAV-2b beranda **tidak** menawarkan antrean, NAV-2c beranda menawarkan modul pengajian |
| NAV-3 admin → dashboard | NAV-3a admin mendarat di beranda, NAV-3b beranda menawarkan ringkasan administrasi |
| NAV-25 setelah ganti password → antrean | NAV-25a lanjut ke beranda, NAV-25b beranda menawarkan antrean |
| WEB-18 login admin → dashboard | WEB-18a mendarat di beranda, WEB-18b panel administrasi tetap terbuka dan tetap dijaga |
| Sumber kebenaran `LandingRouter` dipakai bersama | **tetap diuji** |
| Cabang murobi memakai `Capabilities`, bukan role mentah | **tetap diuji** (`shortcuts()` memakai `capabilities->forUser`) |

### 3.2 Alamat halaman masuk (koreksi ke-7)

Halaman masuk berpindah dari `/admin/admin_login.php` ke `/portal/index.php`;
alamat lama tetap berfungsi sebagai pengalihan, dan **penangan POST tidak
berubah** (`/admin/cek_login.php`).

Yang disesuaikan: tempat berkas uji mengambil token CSRF, dan tujuan pengalihan
yang diharapkan untuk pengguna anonim (`admin_login.php` → `/portal/index.php`).

Yang **ditambahkan**: `tests/perapihan_web_smoke.php` PM-6a/PM-6b memastikan
alamat lama benar-benar masih berfungsi, dan PM-8b memastikan rantai
pengalihannya tidak berputar.

### 3.3 Jumlah migrasi

9 → 10 karena migrasi aditif `010_perapihan_rekonsiliasi_wali.sql`. Komentar pada
berkas uji menyebutkan asal-usul tiap nomor.

---

## 4. Satu-satunya assertion yang berubah dari "ditolak" menjadi "diterima"

```
NAV-16  Guru tanpa penugasan murobi menerima 403 pada /portal/index.php
```

menjadi

```
NAV-16a Guru tanpa penugasan murobi DAPAT membuka beranda umum (200)
NAV-16b Guru tanpa penugasan murobi TETAP menerima 403 pada:
        /portal/izin_ringkasan.php, /portal/izin.php, /portal/izin_antrean.php,
        /portal/izin_antrean.php?mode=murobi, /portal/izin_buat.php,
        /portal/laporan.php
```

**Ini bukan pelonggaran keamanan — ini cacat yang diperbaiki.** Instruksi
pengguna menyebutnya secara eksplisit:

> "Saat ini portal menggunakan pemeriksaan kemampuan perizinan. Jangan menerapkan
> pemeriksaan tersebut pada beranda umum sehingga guru non-murobi ditolak.
> Sebaliknya, jangan melonggarkan pengamanan halaman perizinan hanya untuk
> membuat beranda dapat diakses."

Daftar halaman perizinan pada NAV-16b **diperluas** dibanding versi lama (menambah
`izin_ringkasan.php` dan `laporan.php`), sehingga cakupan penolakan justru
bertambah, bukan berkurang. Diperkuat lagi oleh `perapihan_web_smoke.php` PM-3b
yang juga menguji `/portal/izin_aksi.php`.

---

## 5. Perubahan infrastruktur uji (bukan perubahan assertion)

`tests/browser/cetak-pdf.mjs` kini menghormati `CHROMIUM_PATH`. Alasannya: bila
revisi Chromium bawaan Playwright tidak dapat diunduh (lingkungan tanpa jaringan
keluar), pengujian PDF Fase 5 sebelumnya berhenti dengan galat peluncuran
peramban. Dengan variabel ini, Chromium yang sudah tersedia dapat dipakai dan
pemeriksaan PDF sungguhan tetap berjalan. Perilaku render, orientasi, margin, dan
pemeriksaan penomoran halaman **tidak diubah sama sekali**.

---

## 6. Temuan baseline yang TIDAK disebabkan paket ini

`tests/v2_phase4_static.php` **sudah gagal pada baseline `c65390d`** dengan 7
pemeriksaan yang sama:

```
[gagal] Berkas mobile Fase 4 tersedia: src/app/(app)/(notifikasi)/notifikasi.tsx
[gagal] Layar notifikasi menyediakan LoadingState / EmptyState / ErrorState
[gagal] Layar notifikasi menyediakan Tandai semua dibaca / Halaman
[gagal] Tab notifikasi menampilkan lencana jumlah belum dibaca
```

Sebabnya: redesign UI aplikasi mobile (PR #8, sudah masuk `alhasanApps` `main`)
memindahkan layar notifikasi dari `src/app/(app)/(notifikasi)/notifikasi.tsx` ke
`src/app/notifikasi/index.tsx`, sementara berkas uji masih menuntut path lama.

**Paket ini sengaja TIDAK menyentuhnya.** Memperbaikinya berarti mengubah berkas
uji fase lain atas keputusan yang bukan bagian paket ini. Diperlukan keputusan
pengguna: apakah assertion path diperbarui mengikuti keputusan redesign mobile,
atau ada hal lain yang memang perlu dikembalikan pada aplikasi.


## 7. Keputusan lanjutan pengguna dan pengujian pengganti (Codex)

Bagian 4 dan 6 di atas menggambarkan paket sebelum keputusan lanjutan pengguna
30 Agustus 2026. Setelah audit awal, pengguna memberi izin eksplisit untuk
B-1 dan akses laporan guru A-07. Perubahan berikut bukan pengubahan bukti lulus
historis Fase 1–5 menjadi hasil paket ini.

- **B-1, `3253917`:** fungsi notifikasi diaudit dahulu melalui kode layar,
  provider, header, dan client mobile asli yang berkomunikasi dengan sandbox.
  Dua referensi path diperbarui; pemeriksaan loading/empty/error/mark-all/
  pagination dipertahankan. Badge kini diperiksa lewat header → bell → angka
  unread → renderer badge → route. Tiga assertion baru memeriksa route,
  pemisahan pemilik sesi, dan operasi API. Total statis 286 → 289; tujuh
  kegagalan lama diperbaiki tanpa menghilangkan kewajiban fungsinya.
- **A-07, `afed1bb`:** empat halaman laporan bukan lagi admin-only berdasarkan
  keputusan pengguna. Assertion Fase 1 mengecualikan tepat empat halaman itu
  dan helper guard khususnya dari aturan umum admin; menggantinya dengan lima
  pemeriksaan guard laporan (sesi, admin/guru berelasi, GET/HEAD saja, dan
  pembatasan di layanan). Total bersih 71 → 72. Pengganti perilaku:
  `perapihan_audit_laporan_web.php`, 38 pemeriksaan HTTP untuk lima peran,
  tiga scope, detail asing, teacher_id asing, dan larangan POST/master data.
- **A-06:** uji batas kini memeriksa 20.000 diterima dan 20.001 ditolak 422,
  termasuk bypass pagination dan filter scope. Bukan menerima hasil baseline
  yang melampaui batas.
- **A-09:** tes kontrak API lama tidak dilonggarkan. Ditambah tes 13 pemeriksaan
  terhadap skema baseline, termasuk seluruh respons /reports tanpa parameter,
  serta tiga operasi laporan pada client mobile asli. Total client dari
  15 pengujian notifikasi menjadi 18.

Percobaan persiapan yang gagal dan alasan koreksi pada uji baru dicatat dalam
[keputusan lanjutan](keputusan-lanjutan-audit.md) dan
[laporan lanjutan](hasil-audit-lanjutan-codex.md). Tidak ada perubahan sumber
mobile, pengabaian berkas uji yang gagal, atau pengujian lama yang dihapus
untuk menyamakan angka dengan acuan implementer.

## 8. Penyelesaian kamar dan pagination sebelum push

Sesuai instruksi pengguna berikutnya, A-08/A-10 dituntaskan dalam implementasi,
bukan dengan mengurangi klaim penerimaan. Tidak ada assertion lama yang diubah
pada tahap ini. Ditambahkan `perapihan_audit_kamar.php` (19 pemeriksaan) dan
`perapihan_audit_pagination.php` (45 pemeriksaan). Yang diuji meliputi guard,
CSRF, mutasi kamar aman, escaping, isian kembali, 20/20/5 baris, data setelah
batas lama 100, konsistensi halaman/pencarian, dan isolasi guru.

Angka resmi tetap 2.768. Uji tambahan audit menjadi 235 karena 171 sebelumnya
dijalankan ulang dan ditambah 64 pemeriksaan tersebut. Bukti serta 14 butir
yang masih menunggu verifikasi ada di
[audit-kamar-pagination.md](audit-kamar-pagination.md).

## 9. Lanjutan verifikasi 14 klaim: A-12

`v2_phase1_static.php` tetap memiliki satu assertion pembuatan/penghubungan akun
tercatat pada audit. Bentuk pencarian diubah dari pemanggilan logger langsung
menjadi kedua pemanggilan `auditRequired`, pemeriksaan hasil logger, dan exception
ketika audit gagal. Rangkaian awal lanjutan menemukan assertion lama gagal
karena pemanggilan telah dibungkus oleh pengaman transaksi A-12; bukan karena
jejak audit boleh dihilangkan. Pengganti dinamis: 36 pemeriksaan baru
`perapihan_audit_account_log.php` menjalankan semua mutasi normal dan simulasi
kegagalan audit, memeriksa rollback data/perangkat dan isi before/after/pelaku.
Jumlah assertion lama tidak dikurangi. Tidak ada perubahan assertion B-1 di
lanjutan ini; keputusan B-1 sebelumnya tetap berlaku.
