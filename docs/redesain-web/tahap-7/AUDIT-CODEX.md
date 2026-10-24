# Audit independen Codex — redesain website Tahap 7

Tanggal: **23 Oktober 2026 (Asia/Jakarta)**. Branch `claude/redesain-web-tahap-7`; implementasi Claude **`733590f` dan `9db070f`**, baseline **`86721a2`**. Tanpa worktree. Audit mencakup R-1–R-8, M-5, keputusan U/V/W/X/Y dan AT–AW, kriteria selesai, jalur `portal/rapor_ttd.php`, seluruh penafsiran README, serta preservasi aturan PRD V4.

**Putusan: LULUS setelah satu koreksi terarah auditor (F-1 ditutup).** Tidak ada temuan penghalang tersisa dalam cakupan ini. Pada saat audit 23 Oktober, merge/deploy ditahan sampai rapor terbit. **Keputusan Human Developer 24 Oktober 2026 (AX) mencabut penundaan tersebut**: pengisian belum dimulai dan menurut Human Developer belum ada data isian nilai atau rapor; Human Developer akan merge di GitHub dan deploy melalui cPanel. Pelaksanaannya belum dikonfirmasi. Putusan audit dan batas pengujiannya tetap. Tahap 8 tidak dimulai.

## Temuan dan koreksi

**F-1 · P2 · Alasan koreksi menghalangi tindakan panel lain — dikoreksi auditor.** `textarea[name=alasan][form=panel-nilai|panel-sikap]` semula selalu `required`, padahal satu formulir juga melayani Simpan draf/Serahkan nilai dan Tandai diketahui murobi. Browser menjalankan validasi kolom alasan tersebut sebelum POST meskipun pengguna tidak memilih Koreksi. Probe awal membuktikan formulir tidak valid dan klik Serahkan tidak mengirim permintaan pada 375/768/1280px. Probe terpisah juga membuktikan formulir murobi tidak valid karena alasan kosong. Suite utama hanya memakai tindakan massal sehingga tidak mendeteksinya.

Auditor menghapus `required` tanpa syarat pada dua kolom panel dan menambahkan pemeriksaan khusus submitter `koreksi`, pada fase capture sebelum pengunci klik ganda bersama. Alasan kosong tetap ditolak layanan V4 tanpa JavaScript; aturan layanan tidak dilonggarkan. Teks bantuan kisi yang masih menyatakan seluruh nilai diserahkan terkunci turut diselaraskan dengan AW. Pengujian tambahan menjalankan tombol panel, koreksi kosong/beralasan, dan pengetahuan murobi, dengan serta tanpa JavaScript.

## Aturan server, REST, dan preservasi

- **AU/H7-F:** HTTP utama memeriksa tambah/hapus mapel kosong, target mapel bernilai, pergantian guru, serta penanda/tempat dan jalur admin R-7. Probe auditor menambah pengujian **REST nyata** `/api/v1/v4/rencana`: ubah target mapel kosong, hapus dua mapel kosong, target/hapus mapel bernilai → 409 tanpa perubahan parsial, mengosongkan pasangan penanda/tempat → 409, dan audit `mapel_dihapus`. Trigger kegagalan audit sintetis membuktikan penghapusan serta versi digulung. Snapshot dan hash PDF terbit tetap identik.
- Pasangan penanda lama yang kini nonaktif tidak menghalangi simpan mapel bila tidak diubah; gerbang penerbitan tetap memeriksa penanda sah. Kunci induk `v4_rencana` di `save()` sama dengan kunci pertama pengisian nilai; pemeriksaan nilai/bahan dan DELETE terjadi di dalam transaksi. Race penghapusan melawan nilai pertama tidak dijalankan sebagai eksperimen paralel baru.
- **TTD:** matriks akun/admin/Pendidikan/guru/wali, sesi anonim, aset tidak ada, hash rusak, `no-store`/`nosniff`/CSP diuji HTTP utama. Probe menambah penanda nonaktif, aset nonaktif/belum berlaku/kedaluwarsa dan pencabutan penugasan pada sesi Pendidikan lama. Aset nonaktif hanya admin. Jalur berkas melalui validasi nama `RaporStorage`; MIME dibatasi gambar, bukan masukan jalur URL.
- **AV/Y:** versi lama orang tua tanpa isi/berkas; PDF versi lama ditolak 403, PDF terbaru 200. Relasi wali yang dicabut langsung menutup detail/PDF pada sesi lama dan menghapus anak dari chip/daftar. Proyeksi staf dan wali tetap dipisahkan oleh `RaporService`.
- **Kriteria kelas 30 santri:** selain fixture utama, probe membuat kelas sintetis terpisah tanpa nilai/rapor. Satu POST kisi menyimpan seluruh **120 nilai dan tepat 120 revisi pertama untuk 30 santri**. Koreksi santri terakhir kemudian ditimpa request versi usang: ditolak tanpa kehilangan nilai terbaru, dengan pesan per santri.
- **V:** pengiriman massal dari browser tetap satu pilihan per permintaan melalui `AlHasan.bulk`; layanan memeriksa ulang cakupan, versi/sidik dan idempotensi. HTTP menguji replay kunci penerbitan, sidik usang, alasan, batas 10 rapor tanpa JS, serta akun yang tidak berwenang. Durasi hanya di laptop lokal, bukan pembuktian batas waktu cPanel produksi.

`RencanaService::save()` **berubah** pada Tahap 7. Tidak berubahnya kode `api/` tidak berarti perilaku REST identik; endpoint yang memanggil layanan ini mengikuti keputusan AU. Klaim batas pengujian implementator yang menyebut seluruh layanan identik tidak dipakai sebagai dasar audit. Mobile tidak diubah atau diuji ulang.

## Penilaian layar dan keputusan

| Layar | Hasil pemeriksaan |
| --- | --- |
| R-1 | Konteks sesi, mapel/target/guru, tambah/hapus mapel kosong, penguncian target bernilai, pilihan nama/gambar, salin sebagai isi awal. AT/AU mengesampingkan rancangan awal Tandai siap dan salin Pendidikan. |
| R-2/R-3 | Kisi per mapel-kelas, kalkulasi 40/30/30 dan half-up, riwayat bernama, panel catatan, alasan sesi pengganti, koreksi beralasan, peringatan perubahan, kartu HP dan pager. F-1 memulihkan tindakan panel. Tanpa impor Excel (W). |
| R-4 | Tiga aspek, pencarian/status, isian pembimbing dan mode murobi terpisah, serahkan/diketahui massal. F-1 memulihkan Tandai diketahui dari panel. |
| R-5 | Matriks simbol + warna + teks/legenda, angka dari bahan yang sama, panel nilai/sikap/kehadiran, kembalikan beralasan, serahkan kehadiran, verifikasi massal. |
| R-6 | Chip, centang santri siap, pratinjau sebelumnya/berikutnya, catatan sesi, penerbitan berurutan/idempotensi, buka/tarik/ganti beralasan dan riwayat versi. |
| R-7 | Khusus admin; gambar privat berpenjaga, aktif/nonaktif, tambah penanda, ganti pasangan/tempat beralasan dengan optimistic version/riwayat. Rapor terbit tetap snapshot. |
| R-8 | Chip anak termasuk yang belum memiliki rapor, kartu semester/versi terbaru, nomor/tanggal versi lama tanpa isi, PDF melalui penjaga. |
| M-5 | Halaman admin sendiri, chip/status/kategori/pencarian, guru pengampu bertaut ke penugasan, ubah/status lewat layanan lama. GET alamat lama 301; POST kompatibel. |

## Penafsiran dan batas cakupan README

| Butir | Penilaian auditor |
| --- | --- |
| 1 | Diterima sesuai AW: setiap perubahan isian tersimpan adalah koreksi beralasan, termasuk draf. F-1 membatasi validasi alasan panel pada tindakan yang tepat. |
| 2 | Diterima: sidik yang dilihat diperiksa sebelum penyerahan; muatan dibaca ulang dan versi tetap diperiksa layanan. |
| 3 | Diterima: Simpan draf satu kiriman, transaksi terpisah per santri; hasil parsial dinyatakan, bukan klaim atomik seluruh kelas. Probe membuktikan 30 santri tersimpan. |
| 4 | Diterima: tambah kolom memuat ulang, dengan peringatan perubahan belum disimpan. |
| 5 | Diterima: alasan sesi per mapel untuk kapasitas pengganti/admin; koreksi tetap alasan khusus sesuai AW. |
| 6 | Diterima sesuai AU: tidak membuat Tandai siap; hapus sebagai pilihan dalam formulir dan diperiksa server. |
| 7 | Diterima sesuai AT: admin menyalin lewat pembacaan yang ada; Pendidikan semester aktif tidak mendapat isi semester sebelumnya. |
| 8 | Diterima: pasangan penanda/aset dipecah sebelum layanan; bentuk POST lama tetap diterima, URL gambar berpenjaga. |
| 9 | Diterima: judul lintas cakupan; nama murobi ada di panel, isi draf dahulu sebelum penyerahan. F-1 memperbaiki tindakan panel. |
| 10 | Diterima: Belum ada mencakup draf/kosong dengan petunjuk; kelayakan admin di R-6, POST lama tetap melewati layanan. |
| 11 | Diterima: catatan sesi ikut hash/replay, versi pengganti beralasan, terbit tanpa JS dibatasi 10. Bukan jaminan waktu cPanel yang sudah diuji. |
| 12 | Diterima sesuai AV: versi lama hanya metadata, tanpa snapshot/PDF. |
| 13 | Diterima: jumlah guru berbeda dari penugasan aktif hari ini pada semester aktif, dengan tautan saringan. |
| 14 | Diterima: tab pengawasan admin mengikuti keputusan peta menu Tahap 2. |
| 15 | Diterima: HTML dari pembangun dokumen yang sama, judul diturunkan untuk satu h1; PDF kanonis tidak diubah. |

Perubahan oracle penugasan serta tujuan kartu Tahap 2 diperiksa terhadap diff: pengalihan M-5 dan pembacaan markup baru, dengan maksud uji akses/preservasi tetap. Tidak melonggarkan asersi agar temuan F-1 tampak lulus.

## Bukti dan batas pengujian

Bukti independen di [`audit-codex/`](audit-codex/); bukti implementator tidak ditimpa. PHP 8.4.14, Node 26.7.0, MariaDB lokal, Chromium Playwright. DB Tahap 7 **`test_redesain_t7_test` dibangun ulang sebelum tiap pengulangan HTTP/browser/probe**. Regresi Tahap 2/penugasan memakai DB terpisah `test_redesain_t2_test`.

| Pengujian | Hasil |
| --- | --- |
| Tahap 7 statis awal | 102/0 |
| Tahap 7 HTTP awal | 122/0 |
| Tahap 7 browser utama awal (375/768/1280) | 92/0 |
| Tahap 7 statis / HTTP / browser utama akhir setelah koreksi | 102/0 / 122/0 / 92/0 |
| Probe server auditor akhir (AU REST/rollback, TTD, AV, kelas 30) | 30/0 |
| Probe panel browser akhir (375/768/1280, JS aktif/nonaktif) | 36/0 |
| Regresi HTTP Tahap 2 | 191/0 |
| Regresi integrasi penugasan | 161/0 |
| Regresi browser kerangka sembilan akun × tiga lebar | 107/0 |
| Regresi statis Tahap 1/2/3/5 dan penugasan | exit 0 |
| Statis Tahap 4 / Tahap 6 | 95/1 / 140/1 — oracle cakupan historis |
| Statis V3 Fase 2 / Fase 3 | 68/1 / 102/1 — dua baseline navigasi lama |

Run akhir seluruh suite Tahap 7 exit 0. Penerbitan terlama satu permintaan pada run HTTP akhir **0,13 detik** di laptop lokal. Lint probe PHP/sintaks JS dan `git diff --check` lulus. Koreksi alasan tidak memerlukan perubahan layanan V4, hak akses, API, skema atau mobile oleh auditor.

Statis Tahap 4 melarang diff `database/` sejak Tahap 3, padahal migrasi 023 diwajibkan Tahap 6. Statis Tahap 6 melarang perubahan Navigation sejak baseline, padahal M-5 diwajibkan Tahap 7. Dua kegagalan V3 tetap label navigasi lama yang tercatat sejak audit sebelumnya. Oracle tersebut tidak disunting. Percobaan V3 awal exit 77 sebelum flag uji wajib dipasang; hasil tabel berasal dari run berflag.

Probe auditor awal diperbaiki pada pembacaan `pengurus_id`/`wali_id` dari tabel `users` (DTO Auth tidak memuat keduanya), sinkronisasi navigasi browser, dan pemeriksaan alasan koreksi server; ini galat harness, bukan koreksi kode aplikasi tambahan. Bukti F-1 awal memuat reproduksi tindakan yang tidak mengirim POST; run awal probe browser juga berhenti pada sinkronisasi tanpa JS, sehingga tidak diklaim sebagai suite awal lengkap.

- Hanya Chromium lokal. Safari/iOS/Firefox, perangkat fisik, pembaca layar dan axe belum diuji. R-2 HP, R-5 desktop dan R-6 panel diperiksa visual; 14 tangkapan akhir terpilih disimpan, bukan klaim seluruh audit aksesibilitas manual.
- Tidak mengulang seluruh HTTP/browser Tahap 3–6, suite browser V4 lama yang terikat markup sebelum redesain, seluruh integrasi V1–V4, atau pengujian aplikasi mobile. Layanan V4 selain pengecualian AU diperiksa preservasi sumbernya dan alur penting diuji HTTP/probe.
- Tidak ada smoke cPanel/MySQL produksi, pengujian Push nyata, merge, deploy atau migrasi produksi. Batas 10 rapor tanpa JS merupakan batas konservatif lokal. Tidak ada klaim produksi lulus.
- Direktori pekerjaan lain `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, dan `output/` tidak disentuh.
- Kedua server localhost milik audit dihentikan setelah verifikasi; DB tetap salinan sintetis.

**Pembaruan keputusan, 24 Oktober 2026 (AX):** Human Developer akan melakukan merge di GitHub dan deploy produksi melalui cPanel tanpa menunggu rapor Ganjil 2026/2027 terbit, karena pengisian belum dilakukan. Auditor hanya mencatat keputusan ini. PR #73 sudah di-merge ke `main` (`7f7a002`, 23 Oktober 2026 18.05 WIB) dan memuat kode hasil audit `a04c6e8`; catatan keputusan AX (`eab04e8`) dibawa ke `main` lewat PR lanjutan. Deploy cPanel belum dikonfirmasi; pemeriksaan produksi belum dijalankan. **Tahap 8 tetap belum dimulai.**
