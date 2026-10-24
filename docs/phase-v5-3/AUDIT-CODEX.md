# Audit independen Codex — PRD V5 Fase 3

**24 Oktober 2026 · LULUS setelah dua koreksi terarah worker Push.** Enam kriteria wajib Fase 3 terpenuhi pada fixture lokal. Audit hanya Fase 3, pada branch `claude/prd-v5-fase-3`. Tidak merge, deploy, migrasi produksi, atau memulai Fase 4.

## Cakupan dan baseline

- Baseline audit Fase 2 `c1cb262141b04791eb7870e4e8f466ae361fa0d3`. Seluruh commit fase diperiksa: `d733772` (implementasi), `ee488b6` (bukti/handoff), `bfc4630` (keputusan BD). `git ls-remote` mengonfirmasi branch remote `bfc4630fd3534ce00e67fe22bb1e402048048917` dan main `ca9cfa2b5f1a1db4d211cc536e775389217b225d` sebelum audit.
- Dibaca `AGENTS.md`, PRD V5, audit Fase 2, README/handoff Fase 3, keputusan BD, seluruh perubahan layanan/repository, web/API, notifikasi, navigasi, migrasi/rollback, preflight/verifier, serta suite fase dan bukti implementator. Bukti implementator bukan hasil eksekusi auditor; hasil auditor disimpan tersendiri di `bukti/audit-codex/`.
- Permintaan Human Developer menetapkan audit setelah Claude berhenti. Tidak menjalankan Claude, subagen, worktree, atau mengubah mobile. Berkas pekerjaan lain `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, dan `output/` tidak disentuh maupun dimasukkan commit.
- PHP 8.4.14, MariaDB lokal, Node/Chromium Playwright. Data seluruhnya fixture sintetis. Target utama `test_codex_v5_f3_test`, HTTP `test_codex_v5_f3_http_test`, probe `test_codex_v5_f3_probe_test`, browser `test_codex_v5_f3_browser_test`, regresi Fase 2 `test_codex_v5_f3_regresi_test`. Suite migrasi menggunakan `test_v5_f3_migrasi_test` dan `test_v5_f3_rollback_test`; regresi modul lain memakai dua salinan tersebut setelah suite migrasi selesai. Skrip fixture dijalankan berurutan karena memakai nama DB perantara tetap.

## Temuan dan koreksi terarah

| Temuan | Reproduksi dan dampak | Koreksi / hasil |
| --- | --- | --- |
| F-1 · P2 · Push masih mengirim untuk santri nonaktif/diarsipkan | Terbitkan dengan Push ON dan dua wali berperangkat. Nonaktifkan/arsipkan santri sebelum worker berjalan. Detail wali sudah 403, tetapi worker tetap mengirim kepada kedua wali karena resolver wali bersama tidak memeriksa status santri. | Kueri V5 di `NotificationDispatcher::kirimPush()` kini menggabungkan santri aktif dan belum diarsipkan, sama dengan syarat proyeksi wali. Antrean terkait gagal permanen `AKSES_BERUBAH`, 0 request provider. Resolver dan kebijakan modul lain tidak diubah. |
| F-2 · P2 · OFF di tengah batch tidak menghentikan request berikutnya | Dua baris Push sah diklaim sekaligus. Provider palsu mematikan sakelar setelah request pertama. Worker sebelumnya tetap melakukan request kedua karena sakelar hanya dibaca pada awal `run()`. | Sakelar dibaca ulang tepat sebelum `expo->send()`. Ketika OFF, klaim baris dilepas tanpa request dan tanpa percobaan. ON kembali mengirim sisa sekali; pesan pertama tidak diulang. Penjaga ini berlaku juga bagi Push modul lama yang memakai jalur yang sama. |

Koreksi kode aplikasi auditor hanya pada `app/Notification/NotificationDispatcher.php` (13 baris tambahan, satu baris diganti). Tidak mengubah skema, DTO, rute, kebijakan publikasi, menu, teks ringkasan, atau kontrak API. Probe baru `tests/v5_phase3_audit.php` dijalankan **tanpa perubahan oracle** sebelum dan sesudah koreksi: **21/5 → 26/0**. Lima asersi gagal berasal dari dua cacat di atas (dua status santri serta tiga pemeriksaan siklus OFF/ON), bukan lima cacat berbeda.

## Kriteria wajib Fase 3

| Kriteria | Penilaian independen |
| --- | --- |
| Batas internal/ringkasan dan sebelum/sesudah terbit | Lulus pada layanan serta HTTP web/API. Penyusunan tidak menyalin klinis; pratinjau memakai proyeksi yang sama dengan wali. Tidak ada kepala ringkasan sebelum terbit; ID tebakan ditolak. DTO wali hanya kolom ringkasan, tanpa obat, keluhan, rujukan, pelaku atau alasan internal. SQL wali tidak membaca tabel klinis. |
| Dua wali sah, wali lain, relasi dicabut, sesi/token lama dan tautan | Lulus. Dua wali sah membaca ringkasan anak yang sama. Wali anak lain/tanpa anak ditolak; penyaring santri tidak memperluas cakupan. Pencabutan relasi menutup daftar/detail pada objek pengguna, cookie dan token lama. Tujuan notifikasi memeriksa ulang; sesudah logout meminta masuk. Probe tambahan menutup akses saat master wali nonaktif/diarsipkan atau role orang tua dicabut, serta membuktikan wali pertama tetap berhak setelah wali kedua dicabut. |
| Sekali per peristiwa; retry, konkurensi, atomisitas | Lulus. Terbit/ganti/terbit ulang menghasilkan satu versi/peristiwa dan satu InApp per wali berakun yang berhak, maksimal satu baris Push per wali. Enam proses identik menghasilkan satu terbit + lima replay; enam kunci berbeda dari revisi sama menghasilkan satu sukses + lima 409. Retry provider memakai baris yang sama; Sent tidak dikirim ulang. Gagal menulis outbox, penerima atau audit membatalkan kepala/versi/peristiwa/klaim dan outbox bersama; kiriman ulang kunci yang sama dapat berhasil. |
| Payload Push, pusat notifikasi dan log umum | Lulus pada klien palsu, SQL dan HTTP. 0 nama santri, telepon, keluhan, obat, rujukan, isi ringkasan atau alasan privat dalam payload provider, daftar notifikasi, outbox dan audit umum. Payload hanya teks katalog generik + `{tipe, ringkasan_id}`. Worker merakit ulang teks dan membatasi data. Log akses/klaim hanya metadata/hash. |
| OFF dan pemeriksaan ulang worker | Lulus setelah F-1/F-2. OFF sebelum putaran, OFF sesudah enqueue, dan OFF sesudah request pertama menghasilkan 0 request baru selama keadaan OFF yang diuji; receipt juga berhenti saat OFF. WhatsApp tidak diantrekan, kanal tetap Ditangguhkan meskipun sakelar lama menyala. Relasi dicabut, versi diganti/ditarik, serta santri nonaktif/arsip sebelum worker menutup pengiriman terkait. |
| Versi ringkasan, alasan ganti/tarik dan koreksi internal | Lulus. Koreksi klinis tidak mengubah snapshot terbit atau versi sumbernya. Penggantian, penarikan, dan terbit ulang memerlukan alasan; revisi lama 409. Versi lama utuh di riwayat petugas/admin; wali membaca versi terbaru atau keterangan Ditarik tanpa isi/alasan. Tarik tidak membuat notifikasi baru. |

Admin/admin+petugas, pembimbing/murobi, wali dan pengurus biasa ditolak pada pratinjau/terbit/tarik. Pembaca binaan dibatasi per baris dan tidak menerima riwayat/alasan. Petugas kehilangan hak setelah penugasan dicabut. Mutasi web memerlukan CSRF; API memakai autentikasi token dan layanan yang sama. Respons privat memakai no-store. Migrasi 026 hanya empat CREATE TABLE aditif; checksum 30 tabel lama dan manifest tetap sepanjang siklus migrasi–rollback–migrasi, 14 kunci asing tanpa referensi yatim. Preflight menolak rollback bila sudah ada ringkasan/outbox V5.

## Bukti eksekusi auditor

| Rangkaian | Hasil | Bukti dalam `bukti/audit-codex/` |
| --- | --- | --- |
| Statis/lint Fase 3 | 71/0 | `statis-final.txt` |
| Integrasi Fase 3 + enam proses + worker palsu | 82/0 | `integrasi-final.txt` |
| HTTP web/API Fase 3 | 70/0 | `http-final.txt` |
| Probe auditor sebelum / sesudah | 21/5 → 26/0 | `probe-sebelum.txt`, `probe-final.txt` |
| Migrasi–rollback 026 + manifest/checksum | 26/0 | `migrasi-rollback.txt` |
| Browser Fase 3 375/768/1280 + tanpa JS | 62/0 | `browser.txt` |
| Regresi V2 Fase 4 / konkurensi / V2 Fase 5 | 124/0 · 20/0 · 143/0 | `regresi/v2-fase4-integrasi.txt`, `v2-fase4-konkurensi.txt`, `v2-fase5-integrasi.txt` |
| Regresi fondasi penugasan | 161/0 | `regresi/penugasan.txt` |
| HTTP pembinaan Tahap 5 / rapor Tahap 7 | 106/0 · 122/0 | `regresi/tahap5-http.txt`, `tahap7-http.txt` |
| Regresi V5 Fase 2 integrasi / probe auditor / browser | 134/0 · 28/0 · 58/0 | `regresi/v5-fase2-integrasi.txt`, `v5-fase2-probe.txt`, `browser-fase2.txt` |
| Browser kerangka sembilan akun × tiga lebar | 107/0 | `regresi/browser-kerangka.txt` |
| Seluruh 33 suite statis | 22 exit 0 · 9 exit 1 · 2 exit 77 | `regresi/statis-ringkasan.txt`, berkas per suite |

Tangkapan pratinjau, detail wali dan dialog penarikan 375px diperiksa visual: teks panjang membungkus, konfirmasi/alasan dan tombol dapat digunakan. Browser Fase 3 dan Fase 2 tidak menghasilkan galat PHP pada log server.

Seluruh hasil aplikasi final di atas berasal dari kode setelah F-1/F-2. Suite 33 statis pertama dieksekusi sebelum koreksi worker; suite yang melindungi worker dan lint diulang setelahnya. Tidak melonggarkan atau mengganti oracle implementator.

Cara mengulang probe (jalankan pembangunan fixture secara berurutan):

```bash
V5F3_DB=test_codex_v5_f3_probe_test bash tests/v5_phase3_db.sh
V5F3_DB=test_codex_v5_f3_probe_test V5_RUN_AUDIT=1 php tests/v5_phase3_audit.php
```

### Catatan eksekusi dan oracle historis

- Sandbox awal memblokir process substitution `/dev/fd` dalam skrip fixture. Pengulangan memakai izin eksekusi yang disediakan lingkungan; tidak mengubah skrip, `.env`, atau DB sumber. Akses GitHub awal tanpa jaringan gagal; verifikasi remote berikutnya berhasil.
- Percobaan integrasi final pertama menggunakan ulang DB yang telah dikonsumsi suite awal, sehingga wali_cabut memang sudah dicabut dan perhitungan awal tidak kosong (`integrasi-final-percobaan.txt`). Percobaan regresi Fase 2 pertama memakai DB yang sudah mengandung kunjungan Fase 3 dalam Kamar A, menghasilkan 132/2 pada asersi jumlah binaan (`regresi/v5-fase2-integrasi-fixture-bercampur.txt`). Keduanya kesalahan penyiapan uji auditor; bukan bukti kelulusan ataupun regresi aplikasi. Hasil final diambil dari pembangunan fixture baru yang sesuai masing-masing suite.
- Sembilan suite statis exit 1 memiliki baris `[gagal]` **identik dengan handoff Fase 3**: `keamanan_psb_static` (AG lama), `penugasan_static` (jumlah jenis lama), Tahap 3/4/6/7/8 (larangan perubahan historis yang diperluas fase berikutnya), `v5_phase1_static` (024 terakhir/tanpa koreksi), `v5_phase2_static` (025 terakhir/jumlah rute lama). `v3_phase2_static` dan `v3_phase3_static` exit 77. Oracle tetap utuh; bukan disimpulkan seluruh suite historis lulus.
- Suite migrasi 025/024 yang menganggapnya migrasi terakhir tidak diulang pada audit ini; kelestarian 024–025 dan kompatibilitas layanan setelah melepas 026 diuji oleh migrasi Fase 3. HTTP/browser Fase 1 dan HTTP Tahap 2/6/8 tidak dieksekusi ulang pada giliran ini. Tidak mengklaim seluruh rangkaian historis sudah diulang.

## Tinjauan penafsiran handoff

| Penafsiran | Kesimpulan auditor |
| --- | --- |
| R-1 | Mengikuti keputusan eksplisit BD: menu Kesehatan anak dipertahankan; tidak memperluas hak server. |
| R-2 | Diterima: penerima notifikasi ditetapkan saat publikasi, hak baca mengikuti relasi aktif saat permintaan. Tidak memilih satu wali utama dan tidak menolak wali kedua yang sah. |
| R-3 | Diterima: satu peristiwa versi bertindak lanjut cukup, tidak perlu dua pesan. Tarik menyembunyikan isi tanpa pesan baru; notifikasi lama tetap generik. Enqueue Push mengikuti kanal dan perangkat aktif V2. |
| R-4 | Diterima: kategori umum kesehatan tidak mengungkap identitas atau isi klinis; sesuai larangan data yang dirinci PRD. |
| R-5 | Diterima untuk fase ini: hash mengikat isi/alasan, versi sumber dan revisi ringkasan; bukan token otorisasi atau bukti petugas membaca layar. Otorisasi dan konflik tetap diperiksa pada penerbitan. UI menampilkan pratinjau + konfirmasi wajib. |
| R-6 | Diterima: hanya Tercatat dan santri aktif, satu rantai per kunjungan; snapshot terikat versi sumber. |
| R-7 | Diterima: admin hanya baca, termasuk akun ganda; pembaca binaan hanya versi berlaku; alasan dan riwayat untuk petugas/admin. Wali menerima proyeksi terpisah. |
| R-8 | Diterima: detail wali dicatat sebagai baca_ringkasan dengan metadata; staf yang membuka rincian klinis tetap dicatat lewat layanan klinis. |
| R-9 | Diterima: batas teks dan alasan minimum merupakan validasi input, tidak membuat saran medis atau tanda sudah dibaca baru. |

## Batas dan handoff

- Push hanya klien palsu dengan hitungan send/receipt. **Tidak ada bukti Push fisik, Expo nyata, ketukan aplikasi foreground/background/cold start, iOS/Android, atau produksi lulus.** Paritas mobile tetap Fase 4 dan belum dimulai.
- Tidak mengakses cPanel/DB produksi, migrasi hosting, MySQL hosting, Safari/perangkat fisik, pembaca layar atau beban besar. Browser menggunakan aset CDN lokal; bukan bukti CDN/hosting.
- Enam proses publikasi/replay, kegagalan transaksi, retry dan pergantian kanal antarrequest dalam batch diuji. Tidak mengklaim seluruh interleaving pencabutan/penarikan dengan provider. Pemeriksaan status/relasi mendahului request provider; request yang sudah berjalan sebelum perubahan tidak dapat ditarik kembali. Perubahan yang terjadi tepat sesudah pemeriksaan terakhir tetap memiliki jendela konkurensi, bukan jaminan atomik lintas jaringan. Pemeriksaan berkunci pencabutan/admin di Fase 2 tetap diuji oleh probe regresinya.
- Kelulusan Fase 3 hanya untuk ruang lingkup lokal yang dibuktikan. Setelah commit/push audit, berhenti. Fase 4, merge, deploy, dan migrasi produksi tetap memerlukan instruksi Human Developer.
