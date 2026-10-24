# Redesain website Tahap 7 — penilaian & rapor (23 Oktober 2026)

Status: **lulus audit independen Codex setelah koreksi terarah F-1, 23 Oktober 2026.** Laporan: [`AUDIT-CODEX.md`](AUDIT-CODEX.md). Auditor memperbaiki validasi alasan yang memblokir tindakan panel; hasil akhir statis 102/0, HTTP 122/0, browser 92/0, probe server 30/0 dan probe panel 36/0. **Keputusan Human Developer 24 Oktober 2026 (AX) mencabut penundaan sampai rapor terbit.** PR #73 sudah di-merge ke `main` (`7f7a002`, 23 Oktober 2026 18.05 WIB) dan memuat kode hasil audit `a04c6e8`; catatan keputusan AX (`eab04e8`) masuk ke `main` lewat PR #74 (`5b9e002`). Deploy cPanel belum dikonfirmasi. Tahap 8 tidak dimulai.

- **Riwayat keputusan Human Developer 23 Oktober 2026 (penundaan merge/deploy dicabut oleh AX, 24 Oktober 2026):** syarat mulai "rapor Ganjil 2026/2027 sudah terbit" diganti untuk pengerjaan. Tahap 7 dikerjakan dan diaudit sekarang di branch; **merge ke `main` dan deploy ditahan sampai rapor Ganjil 2026/2027 terbit**, agar guru tidak berganti alur di tengah masa pengisian nilai. Dicatat di `AGENTS.md`, `rencana.md`, `keputusan.md`.
- **Keputusan Human Developer 24 Oktober 2026 (AX):** pengisian rapor belum dilakukan dan menurut Human Developer belum ada data isian nilai atau rapor. Merge/deploy Tahap 7 tidak perlu menunggu rapor Ganjil 2026/2027 terbit. Human Developer akan merge branch yang sudah diaudit ke `main` di GitHub, lalu deploy ke website produksi melalui cPanel sebelum pengisian dimulai. Ini keputusan pelaksanaan, bukan klaim merge/deploy atau smoke produksi sudah selesai.
- Branch `claude/redesain-web-tahap-7` dari `origin/main` `86721a2` (sudah memuat audit Tahap 6 `82f7a2b`). Tidak ada worktree.
- Sumber: `AGENTS.md`, `rencana.md`, `keputusan.md`, `cetak-biru.html` (R-1 s.d. R-8, M-5, baris Tahap 7 "Urutan tahap pengerjaan"), `tahap-6/AUDIT-CODEX.md`.
- Cakupan keputusan: **U** (Simpan draf + peringatan), **V** (tindakan massal satu per satu, penerbitan berurutan dari peramban), **W** (tanpa impor Excel), **X** (salin semester lalu sebagai isi-awal), **Y** (orang tua: versi terbaru + "Versi sebelumnya").
- **Keputusan Human Developer sesudah implementasi (23 Oktober 2026):** (1) "Salin dari semester lalu" hanya untuk admin; (2) aturan rencana disesuaikan agar efektif dan efisien — dikerjakan sebagai: mapel tanpa nilai dapat diubah/dihapus walau mapel lain sudah bernilai, target mapel bernilai terkunci, guru pengampu mapel bernilai tetap dapat diganti, penanda tangan dan tempat rencana bernilai tetap lewat jalur admin R-7, "Tandai siap" tidak dibuat; (3) orang tua hanya melihat nomor dan tanggal versi yang sudah diganti, tanpa isi; (4) setiap perubahan isian nilai/sikap yang tersimpan tetap koreksi beralasan.
- Kriteria selesai: *satu kelas 30 santri dapat diisi nilainya di satu layar; tindakan massal berjalan per santri dengan bilah kemajuan tanpa melewati batas waktu cPanel.*

## Hasil terhadap kriteria

| Kriteria | Bukti |
| --- | --- |
| Satu kelas 30 santri diisi nilainya di satu layar | Fixture kelas "SBX T7 Kelas 30". Server H7-B: satu halaman memuat 30 baris dan ≥ 120 isian komponen; "Simpan draf" menyimpan hanya santri yang berubah (satu transaksi `NilaiService::save()` per santri). Browser T7-B (1280px): 30 santri, nilai akhir dan predikat dihitung langsung sama dengan rumus server (40/30/30, pembulatan setengah ke atas), peringatan perubahan belum disimpan; T7-D (375px): satu santri per kartu dengan Sebelumnya/Berikutnya; T7-J: tanpa JavaScript kisi tetap tersimpan. |
| Tindakan massal per santri dengan bilah kemajuan, tanpa melewati batas waktu cPanel | Satu pilihan per permintaan untuk serahkan nilai (R-2), serahkan sikap & tandai diketahui (R-4), verifikasi bahan (R-5), terbit rapor (R-6); `AlHasan.bulk` menampilkan "n / N", daftar gagal beserta sebab, dan "Ulangi". Browser T7-C/E/F/G: semua berhasil dengan bilah kemajuan. Server H7-E: durasi terlama satu permintaan terbit (PDF + kode verifikasi + outbox) dicatat di `uji-server.txt`; kunci idempotensi yang sama tidak membuat versi ganda; tanpa JavaScript paling banyak 10 rapor per kiriman dan sisanya dilaporkan. |

## Perubahan layanan hanya pada aturan rencana; REST, hak akses, dan basis data tetap

- **Satu-satunya perubahan layanan V4: `RencanaService::save()`** (keputusan 2). Sebelumnya rencana yang salah satu mapelnya sudah bernilai tidak dapat disimpan ulang sama sekali dan mapel tidak pernah dapat dihapus. Sekarang: mapel tanpa nilai/bahan dapat diubah atau dihapus (DELETE baris rencana tanpa nilai, di bawah kunci baris induk yang sama dengan pengisian nilai; tercatat `mapel_dihapus` di audit); mapel bernilai tidak dapat dihapus (409) dan targetnya tidak dapat diubah (409), guru pengampunya boleh diganti; pada rencana bernilai penanda tangan dan tempat hanya diganti lewat `changeSigners()` admin beralasan (409 bila lewat simpan biasa); pasangan penanda yang tidak diubah tidak diperiksa ulang, sehingga rencana tetap dapat disimpan walau penanda lamanya kini nonaktif (penerbitan tetap mensyaratkan penanda sah). Endpoint REST `POST /v4/rencana` memakai layanan yang sama, jadi ikut aturan ini; kode `api/` tidak berubah.
- `app/V4/` lainnya (`NilaiService`, `SikapService`, `VerifikasiService`, `RaporService`, `BahanRepository`, `SemesterScope`, `V4Access`, `RaporDocument`, `RaporStorage`, `VerifikasiPublik`), `app/Auth/Capabilities.php`, `PenugasanJenis`, `api/`, `database/`, `bin/` **tidak berubah** (statis S7-2 membandingkan dengan `86721a2`). Aturan bisnis V4 (cakupan penugasan, versi optimistis, koreksi beralasan, sidik sumber, transaksi SERIALIZABLE penerbitan, outbox) tetap satu-satunya penentu.
- Kebutuhan tampilan baru dibaca `App\V4\RaporTampilan` (hanya SELECT): nama pelaku, kisi per kelas, status rapor per santri, aset penanda tangan, anak wali. Halaman memanggilnya **sesudah** layanan V4 menegakkan cakupan dan hanya dengan ID yang dikembalikan layanan (statis S7-3).
- `PenugasanRepository/Service` hanya bertambah dua pembacaan (daftar M-5 bersaring, ringkasan status/kategori), prepared statement.
- Satu jalur baru: `portal/rapor_ttd.php` menyajikan gambar tanda tangan untuk akun ber-`rapor.rencana` (Bagian Pendidikan: hanya aset yang berlaku, yang juga tampil di pratinjau penerbitan mereka; aset nonaktif hanya admin), dengan pencocokan sha256, `no-store`, `nosniff`, CSP `sandbox`. Berkas tetap privat. Diuji server H7-A.

## Perubahan per layar

| Layar | Berkas | Isi |
| --- | --- | --- |
| R-1 Rencana | `portal/rencana_nilai.php` | Tab Penilaian & rapor + bilah konteks semester/kelas "Tersimpan untuk sesi ini". Status rencana (Belum dibuat · Tersimpan · "n mapel sudah bernilai"). Tabel mapel (mapel, target, guru pengampu tersaring per mapel); mapel bernilai bertanda "Sudah ada nilai" dengan target terkunci; mapel tersimpan tanpa nilai punya "Hapus dari rencana saat disimpan"; "+ Tambah mapel" (tanpa JavaScript: baris kosong tambahan). Penanda tangan dipilih dengan **nama + pratinjau gambar** (bukan "aset #id"). "Salin dari semester lalu" (X) mengisi formulir dari `RencanaService::read()` semester sebelumnya; guru semester lalu yang nonaktif/tidak ditugaskan ditandai kuning. Kelola penanda tangan pindah ke R-7. |
| R-2 Nilai semester | `portal/nilai_semester.php` | Konteks "Mapel · kelas" (dikelompokkan per semester). Kisi santri × Harian n · Tengah · Akhir · Nilai akhir · Predikat · Status, kolom nama tetap terlihat saat digeser, Enter pindah ke santri berikutnya, "+ Kolom harian". Bilah bawah "Simpan draf" / "Serahkan n nilai" dan tombol "Serahkan yang lengkap (n)". Panel santri: catatan capaian per komponen, koreksi beralasan dalam dialog, riwayat revisi dan pemeriksaan dengan **nama pelaku**. HP: satu kartu per santri. |
| R-3 Nilai pengganti | sama, `?pengganti=1` | Kisi yang sama; alasan diisi **sekali** di awal sesi (per mapel, tersimpan di sesi) dan tercatat di setiap perubahan; catatan "Anda mengisi atas nama … · Alasan …" + "Ganti alasan". Admin pada nilai juga diminta alasan sesi (aturan `V4Access`). |
| R-4 Sikap semester / Sikap binaan | `portal/sikap_semester.php` | Saringan semester, cari santri, status (Belum diserahkan / Menunggu diketahui murobi — himpunan sama dengan angka Beranda). Kisi santri × 3 aspek (pilihan sesuai basis data). Murobi: isian terkunci, centang + "pilih semua" + "Tandai diketahui". Catatan, koreksi, nama murobi aktif, dan riwayat di panel santri. |
| R-5 Kelengkapan | `portal/verifikasi_semester.php` | Ringkasan Bahan · Terverifikasi · Diserahkan · Perlu perbaikan · Belum ada; legenda; matriks santri × (mapel, Sikap, Hadir) + kolom Siap dengan simbol + warna + teks tersembunyi. Klik sel → panel bahan (status, pengisi, komponen/aspek/kehadiran, Verifikasi; Kembalikan dalam dialog beralasan; "Serahkan rekap kehadiran"; riwayat pemeriksaan bernama). "Verifikasi semua yang diserahkan (n)" dalam dialog (alasan wajib bila admin). HP: daftar "7 dari 8 bahan terverifikasi". Saringan Beranda `status=Dikembalikan` tetap. |
| R-6 Penerbitan | `portal/rapor_semester.php` | Chip Semua · Siap terbit · Terbit · Belum lengkap; tabel Santri · Kelengkapan · Rapor · Versi · Terbit dengan centang untuk yang siap; bilah pilihan (Pratinjau, Terbitkan yang dipilih); tombol "Terbitkan yang siap (n)". ⋯ per baris: Pratinjau, Lihat PDF, Terbitkan versi pengganti, Buka untuk koreksi, Tarik rapor (merah, beralasan). Panel pratinjau: Sebelumnya/Berikutnya, catatan umum, terbit/pengganti/buka/tarik dalam dialog, "Periksa kelayakan (admin)", isi rapor, daftar versi + "Unduh PDF", riwayat bernama. |
| R-7 Penanda tangan | `portal/rapor_penanda.php` | Kartu penanda tangan dengan pratinjau gambar, jabatan, status, tanggal unggah, Nonaktifkan/Aktifkan dalam dialog; "Tambah penanda tangan" di panel. "Ganti penanda tangan pada rencana yang sudah dinilai" (jalur admin beralasan yang ada) dengan konteks semester/kelas; riwayat sebagai kalimat ("Bagian Pendidikan: A → B · Tempat: X → Y · Alasan …") dengan nama admin. |
| R-8 Rapor anak | `portal/rapor_wali.php` | Chip per anak (termasuk anak tanpa rapor), kartu per semester berlabel status, versi terbaru saja dengan catatan "Versi 2 terbit …, menggantikan versi 1 (terbit …)", "Unduh PDF", tautan "Versi sebelumnya (1) ›" bertanda "Sudah diganti". |
| M-5 Mata pelajaran | `admin/admin_mata_pelajaran.php` (baru), `admin/admin_penugasan.php`, `app/Ui/Navigation.php` | Tab Penilaian & rapor › Mata pelajaran (admin). Chip Aktif · Nonaktif · Arsip, saringan cari dan kategori, kolom Kode · Nama · Kategori · Guru pengampu (tautan ke Penugasan guru mapel tersaring) · Status, ⋯ Ubah/Nonaktifkan/Arsipkan dalam dialog, panel tambah/ubah. Alamat lama `admin_penugasan.php?jenis=mata_pelajaran` dialihkan 301; POST lamanya tetap diproses. |

Komponen bersama baru: `portal/_rapor_ui.php` (label status, simbol matriks, konteks sesi, alasan sesi, pratinjau rapor satu-h1), `assets/ui/rapor.js` (kisi, peringatan, alasan koreksi, pager HP, proses massal `[data-rapor-massal]`, tambah mapel, pratinjau tanda tangan), CSS `.ah-ctx`, `.ah-kisi*`, `.ah-koreksi`, `.ah-legend`, `.ah-sel*`, `.ah-matrix*`, `.ah-rapor-*`, `.ah-signer*`, `.ah-actbar--kisi`. `App\Ui\Status` mengenal "Diganti", "Sudah diganti", "Siap terbit".

## Penafsiran dan batas cakupan — mohon dinilai auditor

1. **Koreksi beralasan untuk setiap perubahan isian tersimpan (disetujui 23 Okt 2026).** `NilaiService` dan `SikapService` menolak perubahan nilai/catatan/aspek yang sudah tersimpan kecuali tindakan "koreksi" beralasan — tidak hanya untuk yang sudah diserahkan. Kisi mengikuti aturan itu: isian baru tersimpan sebagai draf; mengubah isian tersimpan (atau menambah komponen pada santri yang sudah diserahkan) memunculkan isian "Alasan koreksi" yang wajib, dan baris itu disimpan sebagai koreksi (layanan mengembalikan statusnya ke draf). Kisi hanya terkunci untuk santri yang rapornya terbit.
2. **"Serahkan yang lengkap"** mengirim `santri:sidik` — sidik komponen yang dilihat saat halaman dibuka — dan server menolak (409) bila nilai berubah sejak itu. Muatan diserahkan dibangun ulang dari basis data, bukan dari isian peramban. Santri yang sudah diserahkan tidak masuk daftar.
3. **"Simpan draf" kisi** diproses server dalam satu kiriman (satu transaksi layanan per santri yang berubah; ringkasan berhasil/gagal per santri). Bilah kemajuan satu-per-satu dipakai untuk serahkan, tandai diketahui, verifikasi, dan terbit (keputusan V).
4. **"+ Kolom harian"** berupa tautan (`?kolom=`) yang memuat ulang kisi dengan kolom tambahan untuk seluruh kelas; perubahan belum disimpan memicu peringatan sebelum pindah.
5. **Alasan sesi (R-3)** disimpan di sesi PHP per mapel sampai keluar/diganti. Admin yang mengisi nilai (bukan pengganti) juga memakainya karena `V4Access` mewajibkan alasan untuk kapasitas admin. Koreksi tetap meminta alasannya sendiri.
6. **R-1 aturan rencana (diputuskan 23 Okt 2026, butir 2):** "Tandai siap" tidak dibuat — kolom `v4_rencana.status` tidak dipakai aturan apa pun dan gerbang tambahan hanya memperlambat guru; status menampilkan Tersimpan dan jumlah mapel bernilai. Hapus mapel memakai kotak "Hapus dari rencana saat disimpan" (bukan menu ⋯) agar bekerja tanpa JavaScript dan ikut tombol Simpan yang sama; hanya untuk mapel tanpa nilai.
7. **R-1 "Salin dari semester lalu" (X) hanya untuk admin (diputuskan 23 Okt 2026, butir 1).** Memakai `RencanaService::read()` apa adanya; penugasan Bagian Pendidikan hanya berlaku pada semester aktif, sehingga tombol tidak tampil bagi mereka dan permintaan langsung dijawab "di luar penugasan Anda" tanpa isi.
8. **Pemilih penanda tangan R-1/R-7** menggabungkan identitas dan aset aktif dalam satu pilihan (`penanda:aset`) yang dipecah halaman ke isian lama; pratinjau gambar lewat `rapor_ttd.php` (lihat di atas). Bentuk isian lama (`*_penanda_id`, `*_aset_id`) tetap diterima.
9. **R-4** kepala halaman tidak menyebut nama kobong/murobi (cakupan pembimbing dapat mencakup beberapa kelas/kamar); nama murobi aktif tampil di panel santri. "Serahkan yang lengkap" untuk baris sikap Draf yang tersimpan; mengisi baru lalu menyerahkan memakai Simpan draf dulu. Murobi menandai diketahui per santri; koreksi murobi lewat panel.
10. **R-5** "Belum ada" mencakup Draf dan kosong (sesuai angka cetak biru); kolom kehadiran bernama "Hadir". "Periksa kelayakan" pindah ke panel R-6 dan hanya ditampilkan untuk akun bersumber admin ("Periksa kelayakan (admin)"); aksi POST `periksa_finalisasi` lama di R-5 tetap diterima layanan.
11. **R-6 catatan umum** disimpan sebagai draf sesi per santri dan ikut dalam sidik pratinjau; draf tidak dihapus sesudah terbit agar pengulangan dengan kunci idempotensi yang sama tetap permintaan identik (replay, bukan 409) dan versi pengganti berikutnya terisi catatan yang sama. "Terbitkan yang siap" mencakup santri tanpa rapor (Terbit) dan santri berversi yang tidak sedang terbit (Ganti, alasan wajib); rapor yang sedang terbit diganti lewat panel/⋯. Tanpa JavaScript paling banyak 10 per kiriman.
12. **R-8 versi sebelumnya** hanya menampilkan nomor, tanggal terbit, dan "Sudah diganti" (disetujui 23 Okt 2026, butir 3). `RaporService::show()` tidak memberi isi/berkas versi yang sudah diganti kepada orang tua; hak akses tidak berubah.
13. **M-5 "Guru pengampu"** = jumlah guru berbeda dengan penugasan guru mapel yang berlaku hari ini pada semester aktif; tautannya membuka Penugasan › Guru mapel tersaring mapel itu.
14. **Tab admin "Nilai semester", "Nilai pengganti", "Sikap semester"** dipertahankan (keputusan peta menu Tahap 2: pengawasan admin tanpa kehilangan jalan masuk), meskipun gambar R-1 hanya menggambar lima tab.
15. **Pratinjau rapor di halaman** memakai isi `RaporDocument::body()` yang sama dengan PDF, dengan tingkat judul diturunkan satu agar halaman tetap satu `h1`; PDF tidak berubah.

## Pengujian yang disesuaikan

| Uji | Perubahan (maksud uji dipertahankan) |
| --- | --- |
| `penugasan_static` FS-7 | Hitungan token CSRF/escape kini mencakup `admin_mata_pelajaran.php`; ubah status mapel lewat menu ⋯ (`ah_menu` menyisipkan CSRF). |
| `penugasan_web_smoke` FW-1b, FW-3, FW-4, FW-12 | Tab mapel lama → 301 ke M-5; token dan daftar mapel dibaca dari M-5; GET lama dialihkan tanpa memutasi. POST ke alamat lama tetap diuji. |
| `redesain_web_tahap2_http` F-4 (3 asersi) | Tujuan kartu "bahan perlu perbaikan", "sikap belum diisi", dan "sikap binaan perlu diketahui" kini dihitung dari baris matriks/kisi baru, bukan kartu/daftar tautan lama. |

Oracle historis yang membandingkan dengan `origin/main` dan kini memang berubah: `redesain_web_tahap6_static` "Navigation dan app/Auth tidak diubah Tahap 6" (Tahap 7 mengubah tujuan tab Mata pelajaran; `app/Auth` tetap) dan `redesain_web_tahap4_static` "database/ tidak berubah sejak Tahap 3" (sudah tercatat sejak audit Tahap 6). Keduanya tidak disunting.

## Bukti

Lingkungan: PHP 8.4, Node 26, MariaDB 12 lokal, Chromium Playwright. DB: `tests/redesain_web_tahap7_db.sh` membangun salinan Tahap 6 bernama `test_redesain_t7_test` lalu fixture sintetis `tests/redesain_web_tahap7_seed.php` (kelas 30 santri; semester lalu Genap 2025/2026 dengan guru Tafsir nonaktif; kelas tanpa rencana untuk salin; penanda tangan aktif + nonaktif beraset PNG privat; nilai, sikap, verifikasi, dan dua rapor terbit — satu dengan dua versi — **lewat layanan V4**; wali `sbx_t7_ortu`).

| Bukti | Hasil | Berkas |
| --- | --- | --- |
| `php tests/redesain_web_tahap7_static.php` | **102/0** | `uji-statis.txt` |
| `php tests/redesain_web_tahap7_http.php` (server sendiri) | **122/0**; terbit terlama 0,12 detik per permintaan | `uji-server.txt`, `persiapan-db.txt` |
| `node tests/browser/uji-redesain-web-tahap7.mjs` (18 layar-akun × 375/768/1280 + alur T7-B s.d. T7-K, termasuk tanpa JavaScript) | **92/0** | `uji-browser.txt`, `tangkapan/` |
| Regresi: 22 suite statis, penugasan (integrasi 161/0, smoke 86/0, konkurensi 52/0), HTTP + probe audit Tahap 2–6, browser Tahap 1–6, lint 483 berkas | lulus, kecuali dua oracle historis yang dibandingkan dengan `origin/main` (lihat atas) | `regresi.txt` |

## Belum dijalankan / batas

- Hanya Chromium. Safari/iOS, Firefox, perangkat fisik, pembaca layar, dan axe belum dijalankan.
- Durasi penerbitan diukur di laptop lokal (PHP 8.4, MariaDB 12), bukan cPanel; batas 10 rapor per kiriman tanpa JavaScript adalah perkiraan konservatif.
- Uji browser lama V4 (`tests/browser/uji-v4-*.mjs`) menguji markup halaman V4 sebelum redesain dan tidak dijalankan; alur terkait diuji ulang oleh uji server Tahap 7. Layanan V4 tidak berubah kecuali `RencanaService::save()` sesuai AU.
- Kode REST dan aplikasi mobile tidak diubah. Perilaku REST `POST /v4/rencana` mengikuti perubahan layanan AU; implementator belum mengujinya ulang, dan auditor kemudian mengujinya melalui REST nyata (lihat `AUDIT-CODEX.md`). Aplikasi mobile tidak diuji ulang.
- Tidak ada smoke cPanel, tidak ada migrasi produksi (Tahap 7 tanpa migrasi).

## Handoff ke auditor (Codex)

Audit Tahap 7 terhadap R-1 s.d. R-8, M-5, keputusan U, V, W, X, Y, keputusan Human Developer 23 Oktober 2026 (termasuk perubahan aturan `RencanaService::save()` — uji server H7-F), kriteria selesai di atas, jalur `portal/rapor_ttd.php`, dan butir "Penafsiran dan batas cakupan". Jalankan dari root repo:

```bash
php tests/redesain_web_tahap7_static.php
bash tests/redesain_web_tahap7_db.sh
php tests/redesain_web_tahap7_http.php
bash tests/redesain_web_tahap7_db.sh
DB_NAME=test_redesain_t7_test php -S 127.0.0.1:8977 tests/redesain_web_tahap1_router.php &
BASE_URL=http://127.0.0.1:8977 node tests/browser/uji-redesain-web-tahap7.mjs
```

Uji HTTP dan browser mengubah data uji; bangun ulang DB sebelum mengulang. Fixture menulis ID uji ke `storage/private/v4/redesain_t7_ids.json` (diabaikan Git). Penundaan merge/deploy Tahap 7 sampai rapor terbit telah dicabut oleh keputusan AX, 24 Oktober 2026. PR #73 sudah di-merge ke `main` (`7f7a002`, 23 Oktober 2026 18.05 WIB) dan memuat kode hasil audit `a04c6e8`; catatan keputusan AX (`eab04e8`) masuk ke `main` lewat PR #74 (`5b9e002`). Deploy cPanel belum dikonfirmasi. Tahap 8 tidak dimulai.
