# AI Collaboration Protocol (AGENTS.md)

## 📌 Konteks Proyek
Repositori ini berisi dua proyek utama:
1. **WebAlHasan** (Aplikasi Web)
2. **alhasanApps** (Aplikasi Mobile)

Proyek ini dikembangkan menggunakan kolaborasi bergantian (alternating collaboration) antara Human Developer dan dua AI Assistant (Claude & Codex/AI lain). Karena AI tidak membagikan riwayat percakapan satu sama lain, file `AGENTS.md`, `PRD.md`, `PRD-V2.md`, `PRD-V3.md`, dokumen status fase, dan riwayat commit Git bertindak sebagai **satu-satunya sumber kebenaran (Source of Truth)**.

---

## 🌿 Strategi Branching (Sangat Penting)
*   **Branch `main`:** Adalah versi stabil/produksi. **DILARANG KERAS** melakukan implementasi langsung pada branch `main` untuk mencegah kode yang belum diuji masuk ke *environment* produksi (seperti cPanel).
*   **Branch Fitur (misal: `prd-v2`):** Semua pekerjaan pengembangan dan pengujian dilakukan di branch fitur.
*   *Catatan Sistem Git:* Branch baru tidak membuat folder baru. Semua pekerjaan tetap dilakukan di folder proyek yang sama, hanya berpindah jalur riwayat Git. Tidak perlu menggunakan Git worktrees.

---

## 🤖 Peran Agen AI per Workstream

Pembagian peran ditetapkan per workstream agar implementator dan auditor tidak tertukar:

| Workstream | Implementator | Auditor |
| --- | --- | --- |
| PRD V1–V2 dan fondasi penugasan V3–V6 | Claude | Codex |
| PRD V3 Fase 1–5 | Codex | Claude Code |
| Koreksi UX formulir pembinaan V3 (permintaan 4 Oktober 2026) | Codex | Claude Code |
| Redesain tampilan aplikasi mobile (keputusan 14 Oktober 2026; rencana `docs/redesain-tampilan/rencana.md`, branch `redesain-tampilan`; Fase 1–3 lulus audit dan digabung ke `main` 15 Oktober 2026) | Claude | Codex |
| PRD V4 Fase 1–5 (keputusan Human Developer 16 Oktober 2026) | Codex | Claude Code |
| Cleanup fitur lama V1–V4 tahap pertama (keputusan 19 Oktober 2026; lulus audit Claude Code 19 Oktober 2026, `docs/cleanup-legacy-v1-v4/AUDIT-CLAUDE.md`) | Codex | Claude Code |
| Perbaikan keamanan mutasi V1 lewat GET (keputusan 19 Oktober 2026; branch `claude/fix-get-mutasi-v1`, `docs/perbaikan-get-mutasi-v1/`) | Claude | Codex |
| Redesain tampilan website Tahap 1–8 (rencana dan penetapan peran 22 Oktober 2026; branch dokumen `claude/rencana-redesain-web`, `docs/redesain-web/`) | Claude | Codex |
| PRD V5–V6 | Belum ditetapkan; menunggu keputusan Human Developer | Belum ditetapkan; menunggu keputusan Human Developer |

Ketentuan:

*   **Implementator:** membaca PRD aktif, menulis kode hanya untuk satu fase yang diperintahkan, menjalankan pengujian dasar dan regresi yang relevan, membuat commit, mendorong branch fitur, lalu berhenti agar auditor dapat masuk.
*   **Auditor:** memeriksa commit implementator terhadap PRD aktif, menjalankan pengujian secara independen, melakukan koreksi terarah beserta pengujian regresinya jika diperlukan, memperbarui bukti/status secara jujur, lalu berhenti.
*   Keputusan terbaru Human Developer yang dinyatakan secara eksplisit dapat mengganti tabel di atas untuk workstream tertentu. Perubahan tersebut harus dicatat kembali di `AGENTS.md` agar sesi berikutnya tidak memakai pembagian lama.

---

## 📋 Alur Kerja Bergantian (Alternating Workflow SOP)

Jika Anda adalah agen AI yang baru saja diaktifkan, Anda **WAJIB** mengikuti alur kerja ini:

1.  **Baca Konteks:** Cek `git status`, `git log -n 5`, `AGENTS.md`, PRD aktif (`PRD.md`, `PRD-V2.md`, atau `PRD-V3.md` sesuai workstream), dokumen status fase, dan commit handoff. Pastikan Anda berada di branch fitur yang benar, bukan di `main`.
2.  **Kerjakan Satu Fase:** Fokus hanya pada fase yang diinstruksikan oleh Human Developer. Jangan melompat ke fase berikutnya.
3.  **Eksekusi sesuai Peran:** Tentukan lebih dahulu workstream pada tabel pembagian peran. Implementator mengerjakan fase dan menyerahkannya; auditor memeriksa secara independen terhadap PRD aktif. Jangan mengambil peran berdasarkan nama AI saja.
4.  **Handoff (Serah Terima):** Setelah satu fase lolos audit, agen AI harus memberi tahu Human Developer bahwa tugas selesai, agar pekerjaan dapat dilanjutkan ke fase berikutnya.

---

## 🤝 Prompt Handoff Standar

Human Developer akan menggunakan format berikut untuk memicu pergantian agen AI.

**Implementasi PRD V3 oleh Codex:**
> *"Implementasikan hanya Fase [X] PRD V3 pada branch [nama-branch]. Baca AGENTS.md, PRD-V3.md, dokumen status fase sebelumnya, dan commit terbaru. Jalankan pengujian yang relevan, buat commit, push branch, lalu berhenti untuk audit Claude Code. Jangan melanjutkan ke Fase [Y]."*

**Audit PRD V3 oleh Claude Code:**
> *"Audit implementasi Fase [X] PRD V3 yang dibuat Codex pada branch [nama-branch]. Baca AGENTS.md dan PRD-V3.md, periksa seluruh commit fase, jalankan pengujian secara independen, dan lakukan koreksi terarah jika diperlukan. Commit dan push hasil audit, lalu berhenti. Jangan melanjutkan ke Fase [Y] sebelum seluruh kriteria penerimaan Fase [X] terpenuhi."*

**Implementasi PRD V4 oleh Codex:**
> *"Implementasikan hanya Fase [X] PRD V4 pada branch [nama-branch]. Baca AGENTS.md, PRD-V4.md, dokumen status fase sebelumnya, dan commit terbaru di kedua repositori yang relevan. Jalankan pengujian yang relevan, buat commit, push branch, lalu berhenti untuk audit Claude Code. Jangan melanjutkan ke Fase [Y]."*

**Audit PRD V4 oleh Claude Code:**
> *"Audit implementasi Fase [X] PRD V4 yang dibuat Codex pada branch [nama-branch]. Baca AGENTS.md dan PRD-V4.md, periksa seluruh commit fase, jalankan pengujian secara independen, dan lakukan koreksi terarah jika diperlukan. Commit dan push hasil audit, lalu berhenti. Jangan melanjutkan ke Fase [Y] sebelum seluruh kriteria penerimaan Fase [X] terpenuhi."*

**Implementasi Redesain Website oleh Claude:**
> *"Implementasikan hanya Tahap [N] redesain tampilan website pada branch claude/redesain-web-tahap-[N]. Baca AGENTS.md, docs/redesain-web/rencana.md, keputusan.md, cetak-biru.html, dan hasil audit tahap sebelumnya. Jalankan pengujian yang relevan, buat commit, push branch, lalu berhenti untuk audit Codex. Jangan melanjutkan ke Tahap [N+1]."*

**Audit Redesain Website oleh Codex:**
> *"Audit implementasi Tahap [N] redesain tampilan website yang dibuat Claude pada branch claude/redesain-web-tahap-[N]. Baca AGENTS.md dan docs/redesain-web/, periksa seluruh commit tahap, jalankan pengujian secara independen termasuk tampilan 375px, 768px, dan 1280px, dan lakukan koreksi terarah jika diperlukan. Commit dan push hasil audit, lalu berhenti. Jangan melanjutkan ke Tahap [N+1] sebelum seluruh kriteria selesai Tahap [N] terpenuhi."*

**Handoff workstream lama/fondasi ke Auditor Codex:**
> *"Audit implementasi [fase/workstream] yang dibuat Claude pada branch [nama-branch]. Baca AGENTS.md dan PRD yang berlaku, periksa commit terbaru, jalankan seluruh pengujian, dan lakukan koreksi terarah jika diperlukan. Jangan melanjutkan ke fase berikutnya sebelum seluruh kriteria penerimaan terpenuhi."*

---

## ⚠️ Aturan Ketat (Strict Rules)
1.  **Tidak Ada Eksekusi Bersamaan:** Claude dan Codex **TIDAK BOLEH** dijalankan secara bersamaan pada folder dan branch yang sama. Pekerjaan harus selalu bergantian (sekuensial).
2.  **Satu Kebenaran:** Git dan PRD adalah ingatan bersama. Jangan mengandalkan riwayat percakapan AI sebelumnya.
3.  **Hanya Gabung Jika Stabil:** Branch fitur hanya boleh di-merge ke `main` setelah auditor yang ditetapkan untuk workstream tersebut menyatakan fase memenuhi kriteria wajib berdasarkan bukti pengujian. Keterbatasan atau pengujian nonblokir yang belum dijalankan harus tetap dicatat secara terbuka.


## Keputusan Human Developer — V4 Fase 4 (16 Oktober 2026)

- Satuan verifikasi Fase 3 per isian mapel–santri–kelas–semester (seluruh komponen bersama) dikonfirmasi untuk gate penerbitan Fase 4.
- Admin boleh mengganti penanda tangan/aset/tempat setelah penilaian melalui tindakan khusus beralasan dan optimistic version dengan riwayat; perubahan hanya berlaku untuk penerbitan/penggantian versi berikutnya, tidak mengubah snapshot/PDF terbit.
- Fase 4 dikerjakan Codex pada `codex/prd-v4-fase-4` dari baseline audit `8b54106`; bukti/status/handoff ada di `docs/phase-v4-4/`. Claude Code menjadi auditor berikutnya. Fase 5 tetap belum dikerjakan.


## Keputusan Human Developer — V4 Fase 5 (17 Oktober 2026)

- Codex mengimplementasikan hanya Fase 5 pada `codex/prd-v4-fase-5` di web dan mobile; Claude Code mengaudit secara independen sesudah handoff.
- Baseline remote web terverifikasi `831017ac37a09d4465e9f64131f212f040410420` (audit Fase 4), mobile `dfae26d69fe776492cfda274d10fddf79a1590f2` (`origin/main`). Tidak membuat worktree.
- Bukti, manifest, panduan rilis/pemulihan, batas uji, dan handoff Fase 5 ada di `docs/phase-v4-5/` pada kedua repositori.
- Tidak merge, deploy/migrasi produksi, mengirim rilis aplikasi, menjalankan Claude bersamaan, atau melanjutkan PRD berikutnya. Uji lokal/emulator bukan persetujuan produksi; smoke cPanel dan Push fisik tetap memerlukan pelaksanaan serta keputusan Human Developer.

## Keputusan Human Developer — Cleanup V1–V4 tahap pertama (19 Oktober 2026)

- Branch web `codex/cleanup-legacy-v1-v4` dari audit `0cf77fc`, bukan `main`; baseline mobile baca-saja `ee6621a`. Tidak membuat worktree.
- Pensiunkan hanya antarmuka/jalur tulis yang terbukti duplikat. `admin/admin_izin.php` menjadi alamat kompatibilitas tanpa implementasi tulis atau sakelar darurat; mutasi lama ditolak, pembacaan melalui portal V2 tetap tersedia.
- `admin/admin_pelanggaran.php`, menu, tabel dan data pelanggaran tetap arsip baca-saja sesuai PRD V3 §5.2a. Semua tabel/data historis, identitas santri/wali, relasi, akun, kolom ayah/ibu, penempatan, alumni, PSB/keuangannya, situs publik dan laporan tanpa pengganti tetap dipertahankan.
- Tidak ada penghapusan tabel/baris/kolom, pembersihan data smoke, migrasi produksi, merge/deploy, perubahan mobile tanpa bukti kebutuhan, atau awal PRD V5. Dump lampiran bersifat pribadi rahasia; bukti cleanup hanya menggunakan fixture sintetis.
- Direktori belum terlacak `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, dan `output/` milik pekerjaan lain, tidak boleh disentuh atau dimasukkan commit cleanup.
- Matriks, bukti, batas pengujian dan rollback ada di `docs/cleanup-legacy-v1-v4/`. Rekomendasi auditor berikutnya Claude Code, secara bergantian; ini bukan klaim audit independen sudah dilakukan. Penghapusan database historis memerlukan keputusan tahap terpisah.
- Audit independen Claude Code (19 Oktober 2026) atas penunjukan Human Developer: **lulus tanpa koreksi kode**, branch `claude/audit-cleanup-legacy-v1-v4` dari `origin/main` `b807165`. Temuan nonblokir dan risiko PSB GET-hapus/injeksi `admin/hapus_siswa.php` dicatat di `docs/cleanup-legacy-v1-v4/AUDIT-CLAUDE.md`; perbaikannya memerlukan keputusan tahap terpisah. Merge bukan izin deploy.

## Keputusan Human Developer — Perbaikan keamanan mutasi V1 lewat GET (19 Oktober 2026)

- Tindak lanjut risiko R-1 audit cleanup. Implementator Claude, auditor Codex. Branch `claude/fix-get-mutasi-v1` dari `origin/main` `6bec08e`, tanpa worktree.
- Cakupan hanya mutasi: `hapus_siswa.php`, `proses_psb_admin.php`, `admin_pembayaran_psb.php`, `proses_berita.php` (hapus), `admin_galeri.php`, `admin_download.php` dipindah ke POST + CSRF dengan cast int/prepared statement; GET bermutasi → 405; nama berkas yang dihapus diambil dari DB dengan `basename()`. Pemanggil diganti formulir POST. Fungsi bisnis PSB/konten publik, tabel dan data tidak berubah.
- Injeksi baca-saja filter GET `admin_pembayaran_psb.php` di luar cakupan (temuan). Tidak merge/deploy/migrasi produksi, tidak mengubah mobile. Bukti, temuan, batas uji dan handoff di `docs/perbaikan-get-mutasi-v1/`.
- Audit independen Codex atas permintaan Human Developer (20 Oktober 2026), commit implementasi `113ee1f` di atas `6bec08e` pada branch yang sama: **lulus tanpa koreksi kode aplikasi**. HTTP 89/0, probe tambahan 84/0, browser 32/0, preservasi 156/0, regresi cleanup 127/0, 20 suite statis exit 0 dan lint 63 PHP admin. Laporan `docs/perbaikan-get-mutasi-v1/AUDIT-CODEX.md` mencatat ketidaksesuaian folder gambar berita dan filter SELECT `admin_data.php` yang sudah ada sejak baseline, di luar cakupan. Belum merge/deploy; keputusan berikutnya tetap Human Developer.

## Keputusan Human Developer — Redesain tampilan website (22 Oktober 2026)

- Rencana redesain seluruh menu website (situs publik, PSB/portal pendaftar, sistem internal semua peran) disusun Claude dan ditinjau per bagian oleh Human Developer. Sumber kebenaran: `docs/redesain-web/rencana.md`, cetak biru `docs/redesain-web/cetak-biru.html`, dan log keputusan A–AS `docs/redesain-web/keputusan.md`.
- Branch `claude/rencana-redesain-web` dari `origin/main` `e445c50` hanya berisi dokumen; tidak ada perubahan kode, database, atau aset. Tidak membuat worktree.
- Peta menu admin final 17 menu dalam 7 grup (keputusan A–E); peta menu peran lain (F–K); keputusan tinjauan layar L–AS. Keputusan yang menyentuh hak akses (H, Z, AB, AK), database (AD, AE), atau fitur baru (V, AC) wajib dikerjakan dan diuji di server, bukan hanya di tampilan.
- Penetapan peran (22 Oktober 2026): **implementator Claude, auditor Codex** untuk Tahap 1–8, bergantian dan tidak bersamaan. Satu tahap per giliran: Claude mengerjakan satu tahap, menguji, commit, push, lalu berhenti; Codex mengaudit tahap itu; tahap berikutnya baru dimulai setelah lulus audit dan atas instruksi Human Developer.
- Urutan tahap (rincian syarat dan kriteria selesai di bagian "Urutan tahap pengerjaan" cetak biru):
  1. Fondasi kerangka & komponen.
  2. Navigasi, peran & Beranda.
  3. Halaman lama ke kerangka baru.
  4. Perizinan, pengajian & cetak.
  5. Pembinaan.
  6. Data pesantren & penugasan (memuat migrasi database keputusan AD, AE).
  7. Penilaian & rapor — lulus audit Codex 23 Oktober 2026. **Keputusan Human Developer 24 Oktober 2026 mencabut syarat menunggu rapor Ganjil 2026/2027 terbit untuk merge/deploy**, karena pengisian belum dimulai dan menurut Human Developer belum ada data isian nilai atau rapor. Human Developer akan melakukan merge di GitHub dan deploy ke website produksi melalui cPanel; pelaksanaannya belum dikonfirmasi.
  8. Situs publik & PSB — baru dimulai setelah tahap perbaikan keamanan PSB (workstream terpisah, peran belum ditetapkan) lulus, dan isi Profil, nomor resmi, aset, serta biaya MI/RA tersedia.
- Branch implementasi per tahap: `claude/redesain-web-tahap-N`. Tahap 1 dibuat dari `origin/main` setelah branch dokumen digabung, atau dari `claude/rencana-redesain-web` bila belum digabung. Bukti, batas uji, dan handoff tiap tahap di `docs/redesain-web/tahap-N/`.
- Tahap 1 (22 Oktober 2026): Claude mengimplementasikan fondasi kerangka & komponen (K-1 s.d. K-4) di `claude/redesain-web-tahap-1` dari `claude/rencana-redesain-web` `7cf3a31`, karena `7cf3a31` belum ada di `origin/main`. Tanpa perubahan database, hak akses, peta menu, atau mobile. Bukti dan handoff ada di `docs/redesain-web/tahap-1/`. **Lulus audit independen Codex setelah koreksi terarah (22 Oktober 2026)**: pembatalan dialog/fokus, respons bukan JSON pada proses massal, footer panel pendek, dan CSRF server pada fixture tanpa JavaScript. Laporan serta batas uji di `docs/redesain-web/tahap-1/AUDIT-CODEX.md`; browser 91/0, probe auditor 25/0, regresi browser 56/0, smoke HTTP 300/0. Matriks 264 observasi mempertahankan seluruh status HTTP baseline dan 213 observasi Layout akhir tanpa overflow.
- Tahap 2 (22–23 Oktober 2026): Claude mengimplementasikan navigasi, peran & Beranda (A-1 s.d. A-5, B-1 s.d. B-5, N-3 · A–K, L, P, AA, AK, AQ, AR) di `claude/redesain-web-tahap-2` dari `d6c5da0`. Audit pertama Codex `964e21f` membuka empat temuan P2; koreksi Claude `e9b5b8c` didokumentasikan di `docs/redesain-web/tahap-2/KOREKSI-CLAUDE.md`. **Lulus audit ulang independen Codex setelah koreksi terarah auditor (23 Oktober 2026)**: kapasitas POST pemroses izin mengikuti peran aktif, tujuan perbaikan Pendidikan mengikuti cakupan sendiri, dan artikel perbaikan sesuai bahan yang dihitung. Daftar rekomendasi Admin diterima sebagai pemantauan sesuai PRD V3; hak membuka kasus tetap memerlukan penugasan pembimbing. Statis 122/0, HTTP 191/0, probe auditor 29/0, browser Tahap 2 89/0, regresi browser sembilan akun 107/0, notifikasi 46/0, penempatan 47/0, perizinan 36/0, laporan V3 43/0 dan akses 5/0. Bukti dan batas uji di `docs/redesain-web/tahap-2/AUDIT-ULANG-CODEX.md`. Uji hanya DB salinan/sandbox khusus; tanpa migrasi produksi, API bisnis, atau mobile. H tetap Tahap 4. Tahap 3 belum dimulai dan tetap memerlukan instruksi Human Developer.
- Tahap 3 (23 Oktober 2026): Claude mengimplementasikan halaman lama ke kerangka baru (N-2, N-4, N-5, K-5 s.d. K-8 tanpa pratinjau berkas, W-1 s.d. W-4 · AH, AI, AL) di `claude/redesain-web-tahap-3` dari `429f9ef`, karena `origin/main` baru memuat Tahap 2 sampai `964e21f`. `admin/sidebar.php` dihapus, tidak ada `alert()`, daftar berhalaman, dan WhatsApp dikunci "Ditangguhkan" di layanan notifikasi (panel dan API). Tanpa perubahan database, hak akses, peta menu, situs publik, atau mobile. Bukti, penafsiran, uji yang disesuaikan, dan handoff di `docs/redesain-web/tahap-3/`. **Audit pertama Codex 23 Oktober 2026: BELUM LULUS**, dua temuan P2 pada penangguhan worker/retry WhatsApp saat sakelar lama aktif dan alasan batal lunas yang belum divalidasi server. Bukti serta handoff koreksi di `docs/redesain-web/tahap-3/AUDIT-CODEX.md`; statis 145/0, HTTP 157/0, browser Tahap 3 76/0, regresi browser sembilan akun 107/0, probe auditor 9/4. Kode aplikasi belum dikoreksi auditor. **Koreksi Claude F-1/F-2 (23 Oktober 2026)** di atas `e0f86d2`: dispatcher/cron, worker yang mengirim, dan percobaan ulang kini menolak kanal Ditangguhkan walau sakelar lama aktif; alasan batal lunas divalidasi server sebelum UPDATE dan dapat diisi tanpa JavaScript. Probe auditor tanpa diubah 9/4 → 13/0; rincian, uji yang disesuaikan, dan bukti di `docs/redesain-web/tahap-3/KOREKSI-CLAUDE.md`. **Lulus audit ulang independen Codex (23 Oktober 2026)** atas `549c7e6`, tanpa koreksi kode aplikasi auditor: F-1/F-2 ditutup; probe asli 13/0, statis 152/0, HTTP 167/0, integrasi V2 Fase 4 124/0, konkurensi 20/0, browser Tahap 3 76/0, probe tambahan facade/CLI/Push 13/0, dan browser tanpa JavaScript 18/0 pada 375/768/1280px. Laporan serta batas uji di `docs/redesain-web/tahap-3/AUDIT-ULANG-CODEX.md`.
- Tahap 4 (23 Oktober 2026): Claude mengimplementasikan perizinan, pengajian & cetak (I-1 s.d. I-7, J-1 s.d. J-6, X-1 · H, M, N, O, Q, R, S, T, AS) di `claude/redesain-web-tahap-4` dari `11ed4ba`, karena `origin/main` `24f10f9` baru memuat Tahap 3 sampai `e626313`. **Perubahan hak akses H**: `admin/_laporan_guard.php` menerima Bagian Pendidikan (pengurus + penugasan Pendidikan aktif milik sendiri) untuk membaca laporan kehadiran seluruh jadwal, hanya-baca, lewat penanda yang hanya dipasang guard web (REST API tidak melebar); menu "Laporan absensi pengajian" di grup Laporan. Tanda tangan "Bagian Pendidikan" pada cetak perizinan dan kehadiran (AS). Tanpa perubahan database, mobile, situs publik, atau dokumen PSB (kop PSB menunggu Tahap 8). Bukti: statis 96/0, HTTP 132/0, browser 121/0, PDF fisik 60/0, regresi Tahap 1–3 dan V1–V4 terkait lulus; penafsiran, uji yang disesuaikan, batas, dan handoff di `docs/redesain-web/tahap-4/README.md`. **Lulus audit independen Codex setelah koreksi terarah auditor (23 Oktober 2026)** atas implementasi `e6f9484`: kisi mingguan J-1 tidak lagi terpotong pada 100 jadwal; batas layanan/API tetap. Uji akhir statis 96/0, HTTP 132/0, PDF 60/0, browser Tahap 4 121/0 dan Tahap 1 91/0 (lima akun bawaan, tiga lebar), probe H/preservasi/101 jadwal 34/0. Akses H mencakup pencabutan penugasan, master/tahun nonaktif, akun Guru/Pendidikan, penolakan POST dan empat endpoint REST. Regresi V2 Fase 5 awal 138/5 pada receipt Push akibat antrean sintetis Tahap 3; eksperimen kontrol tanpa perubahan suite lulus 143/0. Bukti, batas nonblokir dan penilaian 18 penafsiran di `docs/redesain-web/tahap-4/AUDIT-CODEX.md`. Tahap 5 belum dimulai dan tetap memerlukan instruksi Human Developer.
- Tahap 5 (23 Oktober 2026): Claude mengimplementasikan pembinaan (V-1 s.d. V-7, M-4 · F, Z) di `claude/redesain-web-tahap-5` dari `origin/main` `492fdd3` (sudah memuat audit Tahap 4 `2520c48`). **Perubahan data orang tua Z**: DTO orang tua `PublikasiService` (web dan REST) bertambah tepat satu kunci `pengirim` = nama pembimbing pemilik sumber atau "Pesantren Al Hasan", dibaca lewat `PublikasiRepository::senderNames()` di luar penyaring SQL orang tua; nama anak dari relasi wali akun sendiri. Laporan tanpa isian ID (saringan bernama + ringkasan dari kueri yang sama); papan/daftar/laporan konseling memakai penyaring kerahasiaan yang sama untuk kartu dan angka. Tanpa perubahan skema, kapabilitas, situs publik, atau mobile. Bukti: statis 96/0, HTTP 106/0, browser 115/0; regresi V3, Tahap 1–4, statis dan smoke lulus kecuali dua kegagalan baseline V3 statis yang sudah ada. Penafsiran, oracle yang disesuaikan (`v3_phase4_integration`, F-4 Tahap 2), batas, dan handoff di `docs/redesain-web/tahap-5/README.md`. **Lulus audit independen Codex setelah dua koreksi terarah auditor (23 Oktober 2026)** atas `5a332c1`: pencarian pilihan bernama V-6 dan tautan sumber V-5 (termasuk sesi) dilengkapi. Server Z membuktikan DTO hanya bertambah nama pengirim, pencabutan relasi/master segera menutup akses sesi/token lama, dan isi internal tetap tersembunyi. Statis 96/0, HTTP 106/0, browser Tahap 5 115/0, probe Z/sumber sesi 33/0, probe UI 53/0, regresi browser sembilan akun 107/0, dan integrasi V3 Fase 4 65/0. Dua kegagalan statis V3 lama tetap dicatat sebagai baseline; laporan dan batas uji di `docs/redesain-web/tahap-5/AUDIT-CODEX.md`. Tahap 6 belum dimulai dan tetap memerlukan instruksi Human Developer.
- Tahap 6 (23 Oktober 2026): Claude mengimplementasikan data pesantren & penugasan (D-1 s.d. D-10, M-1 s.d. M-3, G-1 · AB, AC, AD, AE, AF) di `claude/redesain-web-tahap-6` dari `origin/main` `c8d934b` (sudah memuat audit Tahap 5 `6b04d27`). **Perubahan basis data AD/AE**: migrasi aditif `023_redesain_web_tahap6_data_pesantren.sql` (kolom `kamar.asrama` Putra/Putri tanpa pengisian otomatis; tabel `wali_bukan_ganda`) dengan rollback berpasangan; kode memeriksa skema dan tetap berjalan sebelum migrasi/sesudah rollback. Penempatan tidak sesuai asrama hanya tersimpan dengan konfirmasi + alasan yang diperiksa ulang layanan dan dicatat di audit. **Perubahan hak akses AB**: halaman baru `portal/santri_binaan.php` (D-10) hanya-baca untuk pembimbing (peran Pengurus) dan murobi (peran Guru) atas santri dalam cakupan penugasan aktif, diperiksa server setiap permintaan (`ProfilSantriService::cakupanBinaan`); di luar cakupan/tidak ada → 403; data santri admin tetap khusus admin. Impor santri diperiksa di server tanpa menyimpan (AC); G-1 memisahkan jenis "Disiapkan untuk modul mendatang" (AF); halaman Murobi/Pembimbing lama dialihkan (POST lama tetap). Tanpa perubahan `app/Auth`, peta menu, REST, atau mobile. Bukti: statis 141/0, HTTP 132/0 (termasuk migrasi+rollback pada salinan DB tersendiri), browser 115/0; regresi Tahap 1–5, master data, penugasan, perapihan, V2, V3 lulus kecuali dua baseline V3 statis lama. Penafsiran, uji lama yang disesuaikan, batas, dan handoff di `docs/redesain-web/tahap-6/README.md`. **Lulus audit independen Codex setelah dua koreksi terarah auditor (23 Oktober 2026)** atas `3ad7b54`: dialog konflik ayah dan ibu D-3 kini selesai dalam satu konfirmasi, dan hubungkan alumni D-9 wajib beralasan sampai audit. Statis 141/0, HTTP 132/0, browser Tahap 6 115/0, probe server 29/0, probe dialog 36/0, regresi browser sembilan akun 107/0; integrasi penempatan 62/0, konkurensi 10/0, alumni 80/0, perapihan 52/0, penugasan 161/0, master data 12/0 dan V3 terkait lulus. AB diuji pencabutan master/penugasan/semester pada sesi lama di empat tab serta daftar, AD/AE diuji migrasi/rollback pada salinan DB. Statis Tahap 4 95/1 karena oracle melarang diff database sejak baseline Tahap 3 (sekarang ada migrasi 023 yang diwajibkan); dua kegagalan statis V3 lama tetap dicatat. Laporan dan batas termasuk pemilih wali tanpa JS, parser XLSX browser, serta cPanel di `docs/redesain-web/tahap-6/AUDIT-CODEX.md`. Tahap 7 belum dimulai dan tetap menunggu rapor Ganjil 2026/2027 terbit serta instruksi Human Developer.
- Tahap 7 (23 Oktober 2026): **Keputusan Human Developer** — syarat "rapor Ganjil 2026/2027 sudah terbit" diganti untuk pengerjaan: Tahap 7 dikerjakan dan diaudit sekarang di branch, sedangkan merge ke `main` dan deploy **ditahan sampai rapor Ganjil 2026/2027 terbit**. Claude mengimplementasikan penilaian & rapor (R-1 s.d. R-8, M-5 · U, V, W, X, Y) di `claude/redesain-web-tahap-7` dari `origin/main` `86721a2` (sudah memuat audit Tahap 6 `82f7a2b`). Kisi nilai/sikap per kelas dengan Simpan draf dan peringatan (U); tindakan massal serahkan/tandai diketahui/verifikasi/terbit satu per satu dengan bilah kemajuan (V; tanpa JavaScript paling banyak 10 rapor per kiriman); tanpa impor Excel (W); "Salin dari semester lalu" memakai `RencanaService::read()` yang ada (X); orang tua melihat versi terbaru dengan "Versi sebelumnya" (Y). Layanan V4 hanya berubah pada `RencanaService::save()` (keputusan AU); REST (kode), `app/Auth`, basis data, dan mobile **tidak berubah**; pembacaan tampilan baru `App\V4\RaporTampilan` hanya SELECT dan dipanggil sesudah layanan menegakkan cakupan. Jalur gambar tanda tangan berpenjaga baru `portal/rapor_ttd.php`; M-5 pindah ke `admin/admin_mata_pelajaran.php` (alamat lama dialihkan 301, POST lama tetap). Keputusan Human Developer sesudah implementasi (23 Oktober 2026, `keputusan.md` AT–AW): salin semester lalu hanya admin; mapel tanpa nilai dapat diubah/dihapus, target mapel bernilai terkunci, penanda/tempat rencana bernilai tetap jalur admin, "Tandai siap" tidak dibuat; versi rapor lama bagi orang tua tanpa isi; koreksi beralasan untuk setiap perubahan isian tersimpan. Bukti, penafsiran, uji yang disesuaikan, dan handoff di `docs/redesain-web/tahap-7/README.md`. **Lulus audit independen Codex setelah satu koreksi terarah auditor (23 Oktober 2026)** atas `733590f` dan `9db070f`: alasan koreksi tidak lagi memblokir Serahkan nilai/Tandai diketahui dari panel; kewajiban alasan untuk Koreksi tetap diperiksa server. Statis akhir 102/0, HTTP 122/0, browser utama 92/0, probe AU melalui REST/rollback/TTD/AV/kelas 30 santri 30/0, probe panel dengan/tanpa JavaScript 36/0, regresi HTTP Tahap 2 191/0, penugasan 161/0, dan browser sembilan akun 107/0. Oracle historis Tahap 4/Tahap 6 serta dua baseline statis V3 tetap dicatat, bukan diubah. Laporan dan batas termasuk cPanel, mobile serta race penghapusan–nilai pertama yang tidak dieksekusi ulang di `docs/redesain-web/tahap-7/AUDIT-CODEX.md`. **Penundaan merge/deploy pada catatan 23 Oktober ini dicabut keputusan Human Developer 24 Oktober 2026** (lihat keputusan terbaru di bawah). Human Developer akan melakukan merge di GitHub dan deploy melalui cPanel; belum dinyatakan terlaksana. Tahap 8 belum dimulai.
- Tidak merge, deploy, atau migrasi produksi tanpa keputusan Human Developer. Tahap 6 dan keputusan berhak akses (H, Z, AB, AK) wajib punya uji server, bukan hanya uji tampilan.
- Temuan keamanan selama penyusunan disampaikan langsung kepada Human Developer dan sengaja tidak dirinci di repositori publik. Perbaikannya memerlukan tahap tersendiri yang ditetapkan Human Developer.


## Keputusan Human Developer — Merge dan deploy Tahap 7 (24 Oktober 2026)

- Human Developer menjelaskan bahwa pengisian rapor belum dilakukan dan saat ini belum ada data isian nilai atau rapor. Ini keterangan Human Developer, bukan hasil pemeriksaan database produksi oleh auditor.
- **Syarat menunggu rapor Ganjil 2026/2027 terbit sebelum merge/deploy Tahap 7 dicabut.** Keputusan ini menggantikan penundaan 23 Oktober 2026 dan catatan handoff sebelumnya yang masih memakai syarat tersebut.
- Human Developer menerima Tahap 7 yang telah lulus audit Codex pada `a04c6e8`, dan akan melakukan merge branch `claude/redesain-web-tahap-7` ke `main` melalui GitHub, kemudian deploy ke website produksi melalui cPanel sebelum pengisian dimulai.
- Pelaksana merge/deploy adalah Human Developer. Pada saat keputusan dicatat, merge dan deploy belum dikonfirmasi terlaksana; auditor hanya memperbarui dokumen keputusan. Batas audit lokal dan pemeriksaan cPanel yang belum dijalankan tetap dicatat jujur.
- Status 24 Oktober 2026: PR #73 sudah di-merge ke `main` (`7f7a002`, 23 Oktober 2026 18.05 WIB) dan memuat kode hasil audit `a04c6e8`; catatan keputusan AX (`eab04e8`) masuk ke `main` lewat PR #74 (`5b9e002`). Deploy cPanel belum dikonfirmasi; pemeriksaan produksi tetap menjadi tanggung jawab Human Developer.
- Keputusan ini khusus Tahap 7. Tahap 8 belum dimulai; syarat keamanan PSB, bahan resmi, biaya MI/RA, dan instruksi pengerjaannya tetap berlaku.
