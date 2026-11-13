# Redesain tampilan aplikasi gelombang 2 — Fase 4 (Kesehatan, Pembinaan, Rapor, Keamanan + uji menyeluruh)

Tanggal: 13 November 2026 · Implementator: Claude Code · Auditor berikutnya: Codex

Status: **siap audit Codex**, bukan persetujuan merge, deploy, atau rilis aplikasi. Ini fase terakhir rencana gelombang 2.

Sumber kebenaran: [`../rencana.md`](../rencana.md) (§2–§3, §4.6, §4.7, §4.8, §4.10, §5 K-1…K-10, §6), [`../fase-3/AUDIT-CODEX.md`](../fase-3/AUDIT-CODEX.md), `PRD-V3.md`, `PRD-V4.md`, `PRD-V5.md`, `docs/penugasan-keamanan/rancangan.md`, keputusan terkait di `AGENTS.md` kedua repo.

## Branch dan baseline

| Repo | Branch | Dari | Isi |
| --- | --- | --- | --- |
| alhasanApps | `claude/redesain-tampilan-v2-fase-4`, commit `d152ba1` | `origin/main` `dfba0ad` (PR #32; `git diff f2b0358 origin/main` kosong — isi sama dengan koreksi audit Fase 3; versi 1.7.0) | Kode tampilan dan uji unit; versi **1.7.0 → 1.8.0** (`npm run versi:naik -- minor`, `versi:periksa --kerja` LULUS) |
| WebAlHasan | `claude/redesain-tampilan-v2-fase-4` | `origin/main` `ef40e8d` (PR #121; `git diff c3b3dfe origin/main` kosong — isi sama dengan catatan audit Fase 3) | **Hanya** uji, alur Maestro, dan dokumen ini. Kode web, API, dan migrasi tidak berubah |

Fase 3 sudah digabung Human Developer walau audit Codex mencatat "belum lulus penuh"; selisih yang dicatat di audit Fase 1–3 tidak disentuh fase ini. Tanpa worktree; tidak menulis di `main`. `package.json`/`package-lock.json` checkout aplikasi yang berubah milik pekerjaan lain tidak di-commit; direktori web belum terlacak milik pekerjaan lain tidak disentuh. Proses Expo sisa audit Codex Fase 3 (port 8961–8970, `/private/tmp/bsa-codex.*`) tidak disentuh; uji fase ini memakai port 8986/8987/8988/8082.

## Yang berubah (aplikasi)

Hanya tampilan. Tidak ada perubahan endpoint, parameter, payload, hak, kerahasiaan, aturan bisnis, idempotensi, versi optimistik, atau perlindungan `expo-screen-capture` (Kesehatan, Keuangan, Izin wali, Keamanan). Tanpa dependensi/modul native baru.

**Bukti paritas kode:** untuk 37 berkas layar/komponen yang berubah, himpunan pemanggilan `kesehatan.*(`, `rapor.*(`, `pembinaan.*(`, `keamanan.*(`, `api.*(`, `guard.run(`, `useMutationGuard(…)`, `usePrivateResource(`, `useRaporResource(`, `r.mutate(`, ``mutate(`…` ``, `router.push/replace/back(`, `tujuanDibuka(…)`, `sidikJari(…`, `createIdempotencyKey(…)`, `downloadReport(`, `preventScreenCaptureAsync`, `enableAppSwitcherProtectionAsync`, `allowScreenCaptureAsync`, `disableAppSwitcherProtectionAsync`, dan `throw new ApiError` **identik** dengan `origin/main` (187 baris; [`bukti/aplikasi/paritas-kerja.txt`](bukti/aplikasi/paritas-kerja.txt) vs `paritas-base.txt`).

### Komponen

| Komponen | Berkas | Perubahan |
| --- | --- | --- |
| `HalamanStack`, `HalamanStackKeadaan` (baru) | `src/components/halaman-stack.tsx` | Generalisasi `Halaman` Keuangan Fase 3: kepala ← · judul · ⋯ di luar area gulir (`StackScreen`), `KeyboardAwareScrollView`, `ActionBar` opsional (pada teks > 1,35× tombol ikut digulir), penutup isi saat aplikasi tidak aktif bila `penutup` diisi (Kesehatan, Keamanan — perilaku lama dipertahankan). Juga `KartuIsi`, `JudulBagianIsi`, `KepalaRincian` (avatar inisial K-5), `PenandaPrivat` (gembok + teks), `GarisWaktu`, `BarisPilIsi`, `TombolMuatUlangKepala` |
| `ScreenHeader` | `src/components/screen-header.tsx` | Kepala lama tanpa ← (`KepalaLama`) **dihapus**; `variant` kini wajib (`beranda`/`akar`/`stack`) sehingga tidak ada kepala selain Beranda yang tanpa ← |
| `kesehatan-shared` | | `Halaman`/`HalamanKeadaan` berjudul; `StatusBadge` → `StatusPill` ikon + teks; `Penanda`; `Pesan` → `Notice`; `Baris` bergaris pemisah (urutan teks label → nilai tetap); `KontenRingkasan` berkop "Informasi kesehatan untuk orang tua"; `TombolCariKecil` |
| `keamanan-shared` | | `Halaman`/`HalamanKeadaan` berjudul; `KeamananProtection` memuat/galat ber-←; `Lencana` → `StatusPill`; `PilKerahasiaan` (gembok); `KolomCariSantri` (cari server + lembar saring tanggal); `CatatanHanyaBaca` berikon mata |
| `rapor-shared` | | `RaporPage` berjudul di atas `HalamanStack`; `Notice` ikon + teks (berhasil/galat); `HistoryList` garis waktu; `ReportBody` bergaya dokumen (kop Al Hasan, baris mapel nilai kanan, rata-rata disorot, sel kehadiran) |
| `ListRow` | `ui/list-row.tsx` | Prop aditif `accessibilityLabel` (nama tombol tetap judul lama walau ada keterangan) |
| `EntityRow` | `ui/entity-row.tsx` | Prop aditif `accessibilityHint` (kerahasiaan kasus dibacakan sesudah nama) |
| `FilterPills` | `ui/filter-pills.tsx` | Prop aditif `bungkus` (pil membungkus, tidak digeser ke samping) |
| Pembantu murni | `src/kesehatan/forms.ts` (`nadaStatusKesehatan`), `src/lib/pembinaan-tampilan.ts` (`nadaStatusPembinaan`, `labelKerahasiaan`) | Hanya memilih nada pil/teks dari status server |

### Layar (31 berkas)

| Modul | Layar | Perubahan tampilan |
| --- | --- | --- |
| Pembinaan (§4.6) | `pembinaan/index` | P-Menu: tombol cepat 2 kolom "Catat pelanggaran" / "Buka kasus" (hanya bila server memberi hak kelola), `ListRow` berikon berketerangan (Pelanggaran & poin, Kasus & sesi konseling, Rekomendasi tindak lanjut, Informasi untuk keluarga); muat ulang di kepala |
| | `pembinaan/daftar` | P-Daftar: `SearchBar` + "Cari" (server), `EntityRow` avatar inisial, keterangan (kategori/poin, tujuan), `StatusPill`, **pil kerahasiaan bergembok** (+ petunjuk aksesibilitas); nama tombol baris tetap teks lama |
| | `pembinaan/detail` | P-Rincian: `KepalaRincian` (nama, status, versi, kerahasiaan bergembok), bagian Ringkasan / Sesi (kartu per sesi + pil status) / Isian sesi dan kasus / catatan murobi; galat penjaga sebagai `Notice` |
| | `pembinaan/buat` | P-Form: kartu isian, bagian "Kerahasiaan" bergembok dengan penjelasan dan `PenandaPrivat` pilihan, tombol "Simpan" di bilah bawah |
| | `publikasi/[id]` | P-Rincian: kartu kepala pengirim (nama pembimbing dari server bila ada, atau "Pesantren Al Hasan"), pil Terbit/Ditarik dan Sudah/Belum dibaca, kartu artikel, garis waktu riwayat; "Tandai dibaca" di bilah bawah selama belum dibaca |
| Rapor (§4.7) | `rapor/index` | P-Menu: `ListRow` per kemampuan; orang tua: `EntityRow` rapor per anak/semester + pil status |
| | `rapor/[id]` | Versi terbaru di atas (kartu dokumen + "Unduh PDF rapor"), **"Versi sebelumnya"** dikelompokkan di bawahnya, riwayat sebagai garis waktu |
| | `rapor/nilai`, `rapor/sikap` | Kartu pemilih, kartu status disorot, bagian Nilai komponen / Catatan / **Alasan** (gembok); "Simpan draf" (garis) + "Serahkan" (penuh) berdampingan; "Koreksi … beralasan" tetap |
| | `rapor/verifikasi` | Kartu per bahan dengan `StatusPill` (Dikirim = jam, Diverifikasi = centang, Dikembalikan = silang), bagian Pratinjau rapor |
| Kesehatan (§4.8) | `kesehatan/index` | P-Menu tanpa kartu angka (respons akses tidak memuat angka): "Catat kunjungan", `RowGroup` Daftar kunjungan / Laporan layanan klinik / Penugasan; orang tua: `HeroCard` "Kesehatan anak" |
| | `kesehatan/daftar` | `SearchBar` + "Cari", lembar penyaring (rujukan, tindak lanjut, status, tanggal), pil cepat Semua / Rujukan menunggu / Tindak lanjut terbuka / Draf (nilai penyaring yang sama), `EntityRow` + pil |
| | `kesehatan/kunjungan/[id]` | `ProfileHeader` santri (avatar inisial, status, versi), info, **"Catatan klinik" bergembok + `PenandaPrivat` "Privat: … tidak tampil untuk orang tua"**, obat/rujukan/tindak lanjut sebagai kartu, bagian "Untuk orang tua" (ringkasan wali terpisah), Riwayat versi/akses sebagai `ListRow` |
| | `kesehatan/catat` | `PenandaPrivat` di atas formulir; chip kelas/status ≥ 44 pt |
| | `kesehatan/ringkasan` | Keterangan "Ringkasan untuk wali terpisah dari catatan klinis", pratinjau tetap "persis seperti dilihat wali" |
| | `kesehatan/laporan` | P-Laporan: pil periode, `HeroCard` jumlah kunjungan, `StatGrid` Rujukan / Dengan obat / Dikoreksi / Draf (staf), `BarChart` per kelas dan per bulan **hanya bila respons memuat ≥ 2 baris**, **tanpa angka pembanding** |
| | `kesehatan/versi`, `akses`, `penugasan`, `hasil-rujukan`, `wali/*` | Garis waktu versi; `EntityRow` akses; pil status penugasan + chip tindakan ≥ 44 pt; pil anak + `EntityRow` "Perlu perhatian" untuk wali |
| Keamanan (§4.10) | `keamanan/*` (9 layar) | Kepala "… · Hanya melihat", `KolomCariSantri`, `FilterPills` membungkus (asal, hasil, status berangka), `EntityRow` + `StatusPill`, `KepalaRincian`, garis waktu riwayat izin, lampiran bergembok; **tanpa tombol tindakan** (hanya "Kembali ke daftar" navigasi dan muat ulang) |

Semua layar di atas dan kedua navigator (`kesehatan/_layout`, `keamanan/_layout`) memakai `headerShown: false`; rute pembinaan/rapor/publikasi di `_layout.tsx` akar juga. Geser-tepi iOS dan tombol kembali Android tetap bekerja (diuji).

### Penyelarasan akhir layar lama

Inventaris statis + uji web: sebelum fase ini, **38 rute** masih memakai kepala native tanpa ← saat dibuka tanpa riwayat (seluruh `pembinaan/*` 4, `publikasi/[id]`, `rapor/*` 5, `kesehatan/*` 12, `keamanan/*` 9 — beberapa dibuka dengan parameter berbeda). Semua kini memakai kerangka ber-←. Tidak ada layar lain di luar cakupan rencana yang belum tersentuh: setelah fase ini setiap berkas di `src/app` selain Beranda, Masuk, dan Ganti password wajib memakai `StackScreen`/`HalamanStack`/`ScreenHeader variant="akar|stack"` (dijaga uji unit `redesain-v2-fase4.test.ts`). Kepala lama tanpa ← dihapus dari `ScreenHeader`.

## K-10 — daftar seluruh rute dan hasilnya

Uji `fase4-k10` (Expo web, DB Tahap 7, akun **sbx_admin** dan **sbx_guru_mapel**) membuka **75 alamat** (seluruh 70 berkas rute selain Beranda, Masuk, Ganti password wajib; beberapa dengan parameter berbeda) **langsung tanpa riwayat** — setara ketukan notifikasi / tautan `alhasanapps://` / muat ulang web — lalu memeriksa tepat satu ← ≥ 44 pt di kepala dan mengetuknya. Layar ditolak server (403/404), kosong, atau perlindungan layar tetap ber-←. Hasil **304/0** ([`bukti/web/fase4-k10.txt`](bukti/web/fase4-k10.txt), [`daftar-rute-k10.json`](bukti/web/daftar-rute-k10.json)):

| # | Alamat dibuka langsung | Pola | ← (admin) | Tujuan admin | ← (guru mapel) | Tujuan guru |
| ---: | --- | --- | --- | --- | --- | --- |
| 1 | `/schedules` | akar | Kembali ke Beranda | Beranda | Kembali ke Beranda | Beranda |
| 2 | `/perizinan` | akar | Kembali ke Beranda | Beranda | (dialihkan ke Beranda) | Beranda |
| 3 | `/profil` | akar | Kembali ke Beranda | Beranda | Kembali ke Beranda | Beranda |
| 4 | `/akun/edit-profil` | stack | Kembali | Beranda | Kembali | Beranda |
| 5 | `/akun/ganti-password` | stack | Kembali | Beranda | Kembali | Beranda |
| 6 | `/schedule/1` | stack | Kembali | Beranda | Kembali | Beranda |
| 7 | `/meeting/1` | stack | Kembali | Beranda | Kembali | Beranda |
| 8 | `/reports` | stack | Kembali | Beranda | Kembali | Beranda |
| 9 | `/report/1` | stack | Kembali | Beranda | Kembali | Beranda |
| 10 | `/izin/buat` | stack | Kembali ke halaman sebelumnya | Beranda | Kembali ke halaman sebelumnya | Beranda |
| 11 | `/izin/1` | stack | Kembali | Beranda | Kembali | Beranda |
| 12 | `/izin/laporan` | stack | Kembali | Beranda | Kembali | Beranda |
| 13 | `/izin-wali` | stack | Kembali | Beranda | Kembali | Beranda |
| 14 | `/izin-wali/buat` | stack | Kembali | Beranda | Kembali | Beranda |
| 15 | `/izin-wali/1` | stack | Kembali | Beranda | Kembali | Beranda |
| 16 | `/izin-wali/perbaiki?id=1` | stack | Kembali | Beranda | Kembali | Beranda |
| 17 | `/notifikasi` | stack | Kembali | Beranda | Kembali | Beranda |
| 18 | `/notifikasi/1` | stack | Kembali | Beranda | Kembali | Beranda |
| 19 | `/notifikasi/perangkat` | stack | Kembali | Beranda | Kembali | Beranda |
| 20 | `/pembinaan` | stack | Kembali | Beranda | Kembali | Beranda |
| 21 | `/pembinaan/daftar?jenis=pelanggaran` | stack | Kembali | Beranda | Kembali | Beranda |
| 22 | `/pembinaan/daftar?jenis=kasus` | stack | Kembali | Beranda | Kembali | Beranda |
| 23 | `/pembinaan/daftar?jenis=rekomendasi` | stack | Kembali | Beranda | Kembali | Beranda |
| 24 | `/pembinaan/daftar?jenis=publikasi` | stack | Kembali | Beranda | Kembali | Beranda |
| 25 | `/pembinaan/buat?jenis=pelanggaran` | stack | Kembali | Beranda | Kembali | Beranda |
| 26 | `/pembinaan/buat?jenis=kasus` | stack | Kembali | Beranda | Kembali | Beranda |
| 27 | `/pembinaan/detail?jenis=pelanggaran&id=1` | stack | Kembali | Beranda | Kembali | Beranda |
| 28 | `/pembinaan/detail?jenis=kasus&id=1` | stack | Kembali | Beranda | Kembali | Beranda |
| 29 | `/publikasi/1` | stack | Kembali | Beranda | Kembali | Beranda |
| 30 | `/rapor` | stack | Kembali | Beranda | Kembali | Beranda |
| 31 | `/rapor/nilai` | stack | Kembali | Beranda | Kembali | Beranda |
| 32 | `/rapor/nilai?pengganti=1` | stack | Kembali | Beranda | Kembali | Beranda |
| 33 | `/rapor/sikap` | stack | Kembali | Beranda | Kembali | Beranda |
| 34 | `/rapor/sikap?mode=murobi` | stack | Kembali | Beranda | Kembali | Beranda |
| 35 | `/rapor/verifikasi` | stack | Kembali | Beranda | Kembali | Beranda |
| 36 | `/rapor/1` | stack | Kembali | Beranda | Kembali | Beranda |
| 37 | `/kesehatan` | stack | Kembali | Beranda | Kembali | Beranda |
| 38 | `/kesehatan/daftar` | stack | Kembali | Beranda | Kembali | Beranda |
| 39 | `/kesehatan/catat` | stack | Kembali | Beranda | Kembali | Beranda |
| 40 | `/kesehatan/kunjungan/1` | stack | Kembali | Beranda | Kembali | Beranda |
| 41 | `/kesehatan/hasil-rujukan?id=1&rujukan=1` | stack | Kembali | Beranda | Kembali | Beranda |
| 42 | `/kesehatan/versi?id=1` | stack | Kembali | Beranda | Kembali | Beranda |
| 43 | `/kesehatan/akses?id=1` | stack | Kembali | Beranda | Kembali | Beranda |
| 44 | `/kesehatan/ringkasan?id=1` | stack | Kembali | Beranda | Kembali | Beranda |
| 45 | `/kesehatan/penugasan` | stack | Kembali | Beranda | Kembali | Beranda |
| 46 | `/kesehatan/laporan` | stack | Kembali | Beranda | Kembali | Beranda |
| 47 | `/kesehatan/wali` | stack | Kembali | Beranda | Kembali | Beranda |
| 48 | `/kesehatan/wali/1` | stack | Kembali | Beranda | Kembali | Beranda |
| 49 | `/keuangan` | stack | Kembali | Beranda | Kembali | Beranda |
| 50 | `/keuangan/santri` | stack | Kembali | Beranda | Kembali | Beranda |
| 51 | `/keuangan/santri/1` | stack | Kembali | Beranda | Kembali | Beranda |
| 52 | `/keuangan/transaksi?santri=1` | stack | Kembali | Beranda | Kembali | Beranda |
| 53 | `/keuangan/tagihan/1` | stack | Kembali | Beranda | Kembali | Beranda |
| 54 | `/keuangan/bayar?tagihan=1` | stack | Kembali | Beranda | Kembali | Beranda |
| 55 | `/keuangan/bayar-tabungan?tagihan=1` | stack | Kembali | Beranda | Kembali | Beranda |
| 56 | `/keuangan/ubah-tagihan?tagihan=1` | stack | Kembali | Beranda | Kembali | Beranda |
| 57 | `/keuangan/pembayaran` | stack | Kembali | Beranda | Kembali | Beranda |
| 58 | `/keuangan/pembayaran/1` | stack | Kembali | Beranda | Kembali | Beranda |
| 59 | `/keuangan/tabungan` | stack | Kembali | Beranda | Kembali | Beranda |
| 60 | `/keuangan/tabungan/1` | stack | Kembali | Beranda | Kembali | Beranda |
| 61 | `/keuangan/verifikasi` | stack | Kembali | Beranda | Kembali | Beranda |
| 62 | `/keuangan/tunggakan` | stack | Kembali | Beranda | Kembali | Beranda |
| 63 | `/keuangan/rekonsiliasi` | stack | Kembali | Beranda | Kembali | Beranda |
| 64 | `/keuangan/laporan` | stack | Kembali | Beranda | Kembali | Beranda |
| 65 | `/keuangan/penerbitan` | stack | Kembali | Beranda | Kembali | Beranda |
| 66 | `/keuangan/terbit-santri?santri=1` | stack | Kembali | Beranda | Kembali | Beranda |
| 67 | `/keamanan` | stack | Kembali | Beranda | Kembali | Beranda |
| 68 | `/keamanan/izin` | stack | Kembali | Beranda | Kembali | Beranda |
| 69 | `/keamanan/izin/1` | stack | Kembali | Beranda | Kembali | Beranda |
| 70 | `/keamanan/pelanggaran` | stack | Kembali | Beranda | Kembali | Beranda |
| 71 | `/keamanan/pelanggaran/1` | stack | Kembali | Beranda | Kembali | Beranda |
| 72 | `/keamanan/konseling` | stack | Kembali | Beranda | Kembali | Beranda |
| 73 | `/keamanan/konseling/1` | stack | Kembali | Beranda | Kembali | Beranda |
| 74 | `/keamanan/kehadiran` | stack | Kembali | Beranda | Kembali | Beranda |
| 75 | `/keamanan/kehadiran/1` | stack | Kembali | Beranda | Kembali | Beranda |

Tambahan pada uji yang sama: Beranda kedua akun menampilkan logo tanpa ←; **dengan riwayat** ← kembali ke halaman sebelumnya (menu Pembinaan → daftar → ← menu; menu Kesehatan → Laporan → ← menu). Native: Android (tautan saat berjalan menumpuk → ← ke layar sebelumnya; ← dari tautan ke Beranda) dan iOS Release (tautan saat aplikasi **mati** → rincian kasus → satu ketukan ← → Beranda).

**Kontrol baseline:** suite yang sama pada `origin/main` aplikasi gagal tepat pada seluruh 38 rute Fase 4 untuk admin (tanpa ←) dan lulus pada rute Fase 1–3 ([`bukti/kontrol/kontrol-k10-baseline-sebagian.txt`](bukti/kontrol/kontrol-k10-baseline-sebagian.txt), 112 lulus / 46 gagal; run dihentikan di tengah akun kedua karena setiap rute tanpa ← menunggu batas waktu 25 detik — hasil akun pertama sudah lengkap).

## Penafsiran (mohon ditinjau auditor / Human Developer)

| No | Penafsiran | Alasan |
| --- | --- | --- |
| P4-1 | Judul kepala = judul `Stack.Screen` lama (mis. "Detail kunjungan", "Rapor keluarga"); isi teks layar dipertahankan | Suite lama (V3, V4, V5, Keamanan) mencari teks dan nama tombol yang sama; jumlah kemunculan teks tidak bertambah dibanding kepala native lama |
| P4-2 | Nama tombol (aksesibilitas) baris daftar dan menu tetap teks lama (mis. "SBX Santri A1 · Dalam Pendampingan", "Pelanggaran & poin") walau tampilan baris kini avatar + judul + keterangan + pil | Oracle `getByRole('button', { name, exact })` suite V3/pencarian; keterangan tampil visual, kerahasiaan dibacakan sebagai petunjuk |
| P4-3 | Rincian pembinaan **tanpa** `SegmentTabs` Ringkasan / Sesi / Riwayat (rencana §4.6); bagian ditampilkan berurutan | Suite V3 mobile menekan tombol sesi dan "Tandai mengetahui" langsung pada tampilan awal; tab akan menyembunyikannya |
| P4-4 | Kerahasiaan di formulir kasus tetap `DropdownField` wajib-pilih (bukan kartu radio §4.6), diberi judul bergembok, penjelasan, dan `PenandaPrivat` pilihan | Suite V3/form pembinaan memilih lewat dropdown berlabel "Kerahasiaan (wajib dipilih)" |
| P4-5 | Alasan koreksi rapor tetap kolom di halaman (bukan lembar bawah §4.7) | Suite V4 mengisi alasan lalu menekan "Koreksi … beralasan"; lembar bawah memutus urutan itu |
| P4-6 | Riwayat versi/akses kunjungan sebagai baris terlihat (bukan ⋯ §4.8) | Suite V5 menekan "Riwayat versi (n)" dan "Riwayat akses" langsung |
| P4-7 | Menu Kesehatan petugas/admin tanpa `StatGrid` | Respons `/v5/kesehatan/akses` tidak memuat angka; menambah permintaan dilarang (pola P3-4) |
| P4-8 | Laporan klinik: `HeroCard`/`StatGrid` ditambahkan di atas, baris angka lama ("N (M santri)", "x kunjungan · y pemberian", "Draf n") dipertahankan | Oracle suite V5 F5 membandingkan teks tersebut dengan API |
| P4-9 | `FilterPills bungkus` (membungkus) di Kesehatan dan Keamanan | Suite pencarian D-5 menolak elemen apa pun di luar lebar 375 px; pil geser-samping melanggar oracle itu (1 gagal sebelum koreksi) |
| P4-10 | Penanda privat ditambahkan pada catatan klinis (rincian, formulir catat) dan kerahasiaan pembinaan; ringkasan wali diberi keterangan "terpisah dari catatan klinis" | §4.8 dan permintaan fase |
| P4-11 | Rincian publikasi menampilkan `pengirim` bila server mengirimnya (keputusan Z redesain web), dibaca tanpa mengubah tipe API | Data sudah ada di respons; tanpa permintaan baru |
| P4-12 | Rapor orang tua: versi terbaru di atas, "Versi sebelumnya" di bawah; isi versi lama tetap tidak dikirim server (keputusan Y/AV) | §4.7 |
| P4-13 | Berkas di luar `src/components`/`src/constants`/layar fase: `src/kesehatan/forms.ts`, `src/lib/pembinaan-tampilan.ts` (pembantu murni), `tests/redesain-v2-fase4.test.ts`, `app.json` (versi) | Pola P2-15/P3-16 |
| P4-14 | Tombol ← Android 43,8 × 44,2 dp | 115 px / 2,625 (420 dpi) dari 44 pt; pembulatan piksel, sama dengan fase sebelumnya |

## Bukti

Lingkungan: macOS, Node 26.7, PHP 8.4.14, MariaDB 12.3.2 lokal, fixture sintetis (`test_*_test`, `webalhasan_v3_phase1_test` dipulihkan dari dump). Aplikasi dijalankan dari **salinan tanpa `.env*`** (`git archive` + sumber kerja, `node_modules` dari `npm ci --offline` lock ter-commit), `EXPO_NO_DOTENV=1`, `EXPO_OFFLINE=1`; sumber salinan dibandingkan identik (`diff -r`) dengan checkout sebelum run akhir; bundel 0 `alhasan.co.id`. Semua skrip Playwright memblokir host selain `127.0.0.1` (nol permintaan keluar di setiap suite fase). Metro web dengan `CI=1` tidak memuat ulang berkas yang disalin, jadi Expo dinyalakan ulang sesudah setiap sinkron.

| Uji | Hasil |
| --- | --- |
| `npx tsc --noEmit` · `expo lint` (salinan bersih, kode akhir) | exit 0 · exit 0 ([`bukti/aplikasi/`](bukti/aplikasi/)) |
| Unit `node --test tests/*.test.ts` | **113/0** (baseline 104/0). Baru `redesain-v2-fase4.test.ts` (9 uji: kerangka berjudul di 31 layar tanpa keadaan memuat/galat tanpa ←, K-10 menyeluruh atas seluruh berkas `src/app`, kepala native dimatikan, perlindungan layar Kesehatan/Keamanan tetap, Keamanan tanpa tombol tindakan/mutasi, penanda privat & laporan tanpa pembanding, kerahasiaan bergembok, rapor koreksi/versi/PDF, nada status) |
| Aturan versi | LULUS 1.7.0 → 1.8.0 (minor, satu kali) |
| Paritas pemanggilan API/penjaga (37 berkas) | identik dengan `origin/main` |

### Expo web — suite fase ([`perintah-uji.sh`](perintah-uji.sh), oracle baru)

| Suite | Peran | Hasil |
| --- | --- | ---: |
| `fase4-pembinaan-rapor` (DB Tahap 7) | pembimbing, murobi (dua), guru mapel, Pendidikan, pengurus tanpa akses, admin, orang tua publikasi, orang tua rapor | **97/0** |
| `fase4-kesehatan` (DB V5 Fase 3 + satu kunjungan via API) | petugas kesehatan, admin, pembimbing, murobi, orang tua | **70/0** |
| `fase4-keamanan` (DB penugasan Keamanan F4) | Keamanan (pengurus), guru berpenugasan Keamanan, admin ditolak | **60/0** |
| `fase4-bsa` (DB V6 Fase 4) | petugas BSA | **18/0** |
| `fase4-k10` (DB Tahap 7) | admin, guru mapel — 75 alamat | **304/0** |

Setiap suite memeriksa: tepat satu ← ≥ 44 pt di kepala, tanpa luapan horizontal 375 px, seluruh kontrol terlihat ≥ 44 pt, dan untuk satu layar per peran 375 gelap serta 768/1280 terang dan gelap (latar gelap diverifikasi). Uji ini menemukan dan mengoreksi sebelum handoff: chip "Akhiri/Nonaktifkan" (36 pt), chip kelas/status tindak lanjut di formulir catat (36 pt), dan konfirmasi pratinjau ringkasan → kini ≥ 44 pt.

### Jaringan (baseline vs fase)

[`uji-redesain-v2-fase4-jaringan.mjs`](../../../tests/browser/uji-redesain-v2-fase4-jaringan.mjs) merekam alur yang sama dari dump DB yang sama pada baseline (`origin/main` `dfba0ad`) dan kode fase, membandingkan per langkah metode, path ber-ID, query, dan badan JSON kanonik (kunci idempotensi, sandi, dan `*_hash` dari server disamarkan; jam klien dikunci pada hari yang sama pukul 00.30 WIB agar nilai bawaan "sekarang" tidak bergeser).

| Bagian | Alur | Hasil |
| --- | --- | --- |
| Pembinaan + rapor | pembimbing: menu, daftar, cari, rincian, **catat pelanggaran (ketukan ganda)**, **buka kasus**, **tambah sesi**, **selesaikan sesi**, **simpan draf sikap**; murobi: **sikap diketahui**, **tandai mengetahui kasus**; guru: **simpan draf nilai**, **serahkan nilai (ketukan ganda)**; Pendidikan: **verifikasi sikap**, pratinjau; orang tua: rapor, publikasi, **tandai dibaca**; admin | **34 langkah sama / 0 berbeda, 199 permintaan** |
| Kesehatan | petugas: menu, daftar, cari, **catat (ketukan ganda)**, **koreksi**, **hasil rujukan**, pratinjau, **terbitkan ringkasan (ketukan ganda)**, **tarik**, riwayat versi, laporan; admin penugasan/akses; pembimbing; orang tua | **20 langkah sama / 0 berbeda, 104 permintaan** |
| Keamanan (pembacaan) | menu, izin + asal + cari + rincian, pelanggaran + rincian, konseling + rincian, kehadiran | **11 langkah sama / 0 berbeda, 41 permintaan** |

Total **65 langkah / 0 berbeda, 344 permintaan**; setiap langkah mutasi berkunci idempotensi tepat **1 kunci** pada baseline dan fase ([`bukti/jaringan/`](bukti/jaringan/)). Catatan terbuka: satu perekaman fase sebelumnya (run 3) berbeda **hanya** pada jumlah GET muat-ulang sesudah POST sikap murobi (8 vs 9; urutan dan isi permintaan sama) karena konteks ditutup sebelum GET terakhir selesai; perekaman ulang 34/0 ([`banding.txt`](bukti/jaringan/banding.txt) memuat kedua hasil). Perekaman awal sebelum jam klien dikunci berbeda hanya pada `waktu_kunjungan` (menit perekaman) dan `sumber_hash` (dihitung server dari stempel waktu run) — penyebab perekam, bukan aplikasi.

### Regresi Expo web modul lain (oracle tidak diubah, kode fase)

| Suite | Hasil |
| --- | ---: |
| Redesain v2 Fase 1 · Fase 2 pengajian · Fase 2 izin · Fase 3 | **159/0** (1 dilewati seperti sebelumnya) · **68/0** · **145/0** · **258/0** |
| Profil · kelas gabungan · pencarian absensi · pencarian daftar · peserta absensi | **105/0** · **57/0** · **53/0** · **24/0** · **18/0** |
| V5 F4 kesehatan · V5 F5 laporan klinik | **78/0** · **36/0** |
| V6 F4 · V6 F5 · tagihan satu santri · probe satu santri · probe F-1 · probe V-1 | **45/0** · **20/0** · **27/0** · **3/0** · **16/0** · **5/0** |
| V7 F4 izin wali · Keamanan F4 | **48/0** · **46/0** |
| V3 pembinaan aplikasi (`uji-v3-fase5-mobile.mjs`, asli) | baseline dan fase **identik**: 10 lulus lalu berhenti pada bagian portal **website** (`#case-privacy` tidak ada lagi sejak redesain web) — kegagalan historis |
| V3 pembinaan aplikasi, varian diagnostik tanpa 10 baris portal website (dibuat sementara saat uji: baris berawalan `const web=`, `await web.`, `check(await web.`, `const wr=` dibuang; oracle aplikasi tidak diubah; berkas varian tidak di-commit) | baseline **22/0**, fase **22/0** |
| Redesain gelombang 1 (`uji-redesain-fase1/2/3.mjs`, Expo 8082 + API 8940) | baseline dan fase **identik**: Fase 1 dan 2 berhenti pada penanda Beranda lama (timeout), Fase 3 17 lulus / 4 gagal pada label lama (Cetak PDF di kartu, label peran Profil) — kegagalan historis sejak gelombang 2 Fase 1 |
| V4 rapor aplikasi (`uji-v4-fase5-app.mjs`) | **tidak dapat dijalankan**: memerlukan rantai fixture V4 Fase 1–4 dan DB bernama tetap `webalhasan_v4_fase2_test` yang tidak ada lagi. Rapor diuji dengan data V4 nyata dari DB Tahap 7 (suite fase, perekam jaringan, Android, iOS) |

Keamanan F4, pencarian daftar, dan V5 F4 diulang sesudah koreksi terakhir (`accessibilityHint`): **46/0 · 24/0 · 78/0**. Galat PHP server API: 0 di semua run ([`bukti/regresi/`](bukti/regresi/)).

### Native

| Platform | Build yang benar-benar diuji | Hasil |
| --- | --- | --- |
| Android | **Emulator** `Medium_Phone`, Android 16/API 36. **Build baru** dari salinan tanpa `.env*` (`expo prebuild --clean` + `gradlew --offline`): **APK Release 1.8.0** (`assembleRelease`, bundel Hermes tertanam, API 127.0.0.1:8971, 0 host produksi) dan **dev client Debug 1.8.0** (`assembleDebug`) + Metro dari salinan yang sama. Bukan perangkat fisik, bukan build distribusi | **APK Release:** terpasang, start-up sampai layar Masuk tanpa crash; **masuk ke API lokal gagal** karena build rilis Android tidak mengizinkan HTTP cleartext (manifest debug memuatnya, manifest utama tidak) — keterbatasan lingkungan uji (API produksi HTTPS), dicatat. **Debug + Metro: 34/0** — pembimbing (Beranda tanpa ←, tautan Pembinaan, daftar kasus "Internal", ← dengan riwayat ke menu, tombol kembali Android, tautan rincian kasus → ← layar sebelumnya), orang tua rapor ("Unduh PDF rapor", "Versi sebelumnya"), **gelap + 2,0×** (rapor, menu rapor, laporan klinik ber-←), petugas kesehatan (rincian bertanda privat, tangkapan layar terlindungi 16 KB polos, ← Beranda), Keamanan (rincian izin tanpa tombol tindakan, ← Beranda), guru berpenugasan Keamanan, admin ditolak ber-← ([`bukti/android/`](bukti/android/)) |
| iOS | **Simulator** iPhone 17 Pro, iOS 26.2. **Build Release baru 1.8.0** arm64 (`expo prebuild` + `pod install` + `xcodebuild -configuration Release`, tanda tangan ad-hoc, bundel Hermes tertanam dari kode akhir: 0 host produksi, API lokal). Dibangun dua kali (kedua sesudah koreksi petunjuk aksesibilitas); semua hasil dari build kedua. Bukan iOS Debug, bukan arsip distribusi, bukan perangkat fisik | **10/10 alur Maestro lulus** ([`tests/maestro/redesain-v2-fase4/`](../../../tests/maestro/redesain-v2-fase4/), [`bukti/ios/`](bukti/ios/)): pembimbing (menu, daftar kasus, ← dengan riwayat, **tautan saat aplikasi mati → rincian kasus → ← Beranda**), orang tua rapor **terang, gelap, gelap + AX-XXXL**, petugas kesehatan rincian privat terang/gelap/gelap + AX-XXXL, Keamanan rincian izin terang/gelap/gelap + AX-XXXL (tanpa Setujui/Tolak) |

Tangkapan layar native layar Kesehatan/Keamanan polos/hitam: perlindungan `expo-screen-capture` tetap bekerja; bukti native berupa pohon aksesibilitas (uiautomator/Maestro), bukti visual dari Expo web.

## Temuan terbuka

| No | Temuan | Status |
| --- | --- | --- |
| T4-1 | APK Release Android tidak dapat masuk ke API lokal HTTP (cleartext diblokir pada build rilis) | Perilaku platform, bukan cacat; uji fungsional Android memakai Debug baru. Uji rilis Android dengan API HTTPS lokal belum dilakukan |
| T4-2 | iOS menggabungkan baris daftar menjadi satu elemen aksesibilitas, sehingga pil di dalam baris tidak dibacakan terpisah | Dikoreksi untuk kerahasiaan (petunjuk aksesibilitas); pil status lain tetap terbaca melalui nama baris yang memuat status |
| T4-3 | Suite V4 aplikasi lama tidak dapat dijalankan (fixture hilang) | Batas uji; rapor diuji dengan fixture Tahap 7 |
| T4-4 | Suite V3 mobile dan gelombang 1 berhenti pada bagian/label lama yang berubah sebelum fase ini | Historis, identik baseline; tidak dilonggarkan |
| T4-5 | Temuan terbuka Fase 1–3 (kontras `statusIzin`/`statusTerlambat`, logo lime di latar krem, luapan bilah tab web 375 px, letak vertikal FAB Expo web, ketukan notifikasi sistem) | Tidak berubah |

## Ringkasan seluruh gelombang 2

| Fase | Cakupan | Versi | Status audit |
| --- | --- | --- | --- |
| 1 | Fondasi token/komponen, `ScreenHeader` 3 varian, Masuk, Ganti password, Edit profil, Beranda (logo K-9, ringkasan K-3), Profil | 1.5.0 | Codex: belum lulus penuh (selisih §4.1–§4.2); digabung Human Developer |
| 2 | Jadwal, Perizinan (FAB K-8), pengajian, absensi, laporan kehadiran, izin V2, izin wali V7, notifikasi | 1.6.0 | Codex: belum lulus penuh (letak FAB Expo web, selisih tampilan); digabung |
| 3 | Keuangan/BSA 18 layar | 1.7.0 | Codex: belum lulus penuh (ketukan notifikasi sistem, pengalih iOS, selisih §4.9); digabung |
| 4 | Kesehatan, Pembinaan, Rapor, Keamanan, K-10 menyeluruh, penyelarasan akhir | 1.8.0 | Menunggu audit Codex |

Sesudah Fase 4: setiap rute aplikasi selain Beranda, Masuk, dan Ganti password wajib memiliki ← dengan tujuan sesuai K-10 (75 alamat, dua akun, web; contoh native Android dan iOS); paritas permintaan dibuktikan per fase (Fase 4: 65 langkah / 344 permintaan); tidak ada perubahan endpoint, hak, kerahasiaan, idempotensi, versi optimistik, atau perlindungan layar sepanjang gelombang 2.

## Mengulang uji

```bash
# Web (dari akar repo web); APP = Expo web salinan TANPA .env* yang API-nya 127.0.0.1:8971
APP=http://127.0.0.1:8987 bash docs/redesain-tampilan-v2/fase-4/perintah-uji.sh <keluaran>
# Jaringan: baseline (git archive origin/main aplikasi) dan fase, dump sama, HARI yang sama
DUMP=<dump> APP=http://127.0.0.1:8986 bash docs/redesain-tampilan-v2/fase-4/perintah-uji.sh <keluar-baseline> jaringan-pembinaan-rapor jaringan-kesehatan jaringan-keamanan
DUMP=<dump> APP=http://127.0.0.1:8987 bash docs/redesain-tampilan-v2/fase-4/perintah-uji.sh <keluar-fase> jaringan-pembinaan-rapor jaringan-kesehatan jaringan-keamanan
node tests/browser/uji-redesain-v2-fase4-jaringan.mjs banding <keluar-baseline>/jaringan-kesehatan.json <keluar-fase>/jaringan-kesehatan.json
# Regresi modul lain
APP=http://127.0.0.1:8987 bash docs/redesain-tampilan-v2/fase-2/perintah-regresi.sh <keluaran>
APP=http://127.0.0.1:8987 bash docs/redesain-tampilan-v2/fase-3/perintah-uji.sh <keluaran> v6f4 v6f5 ss ss-audit probe-f1 probe-v1 fase3
# Android emulator (adb reverse 8971; Metro dev client dari salinan tanpa .env*); DB seperti suite fase
DB_NAME=<db> METRO=http://127.0.0.1:8988 OUT_DIR=<keluaran> node tests/browser/uji-redesain-v2-fase4-android.mjs <t7|v5|pk>
# iOS simulator (build Release terpasang; DB seperti suite fase)
DB_NAME=<db> UDID=<simulator> [SEED_JSON=<ids-pk.json>] bash tests/maestro/redesain-v2-fase4/jalankan-ios.sh <keluaran> <t7|v5|pk>
```

Jalankan berurutan: semua suite memakai satu port API (8971, tertanam di bundel) dan DB perantara bernama tetap. Fixture Kesehatan belum berisi kunjungan; suite dan uji native mencatat satu kunjungan sintetis lewat API sebelum memeriksa rincian.

## Batas uji

- Perangkat fisik, build distribusi (EAS/arsip), iOS Debug, Push nyata (termasuk ketukan notifikasi Push), MariaDB 10.6/MySQL/cPanel/produksi, dan UAT **belum diuji**.
- APK Release Android hanya start-up (cleartext, T4-1); fungsi Android dari dev client Debug baru.
- "Dibuka dari notifikasi tanpa riwayat" dibuktikan dengan alamat langsung (web, 75 alamat), tautan saat berjalan (Android), dan tautan saat aplikasi mati (iOS Release); bukan ketukan Push.
- Skala teks native hanya Android 2,0× dan iOS AX-XXXL (gelap); Expo web tidak mengemulasi skala teks sistem.
- Suite V4 aplikasi lama tidak dijalankan (T4-3). Kontrol baseline K-10 hanya lengkap untuk akun pertama.
- Seluruh data sintetis lokal, API `127.0.0.1`.
