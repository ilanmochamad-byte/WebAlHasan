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
| Redesain tampilan website (rencana 22 Oktober 2026; branch dokumen `claude/rencana-redesain-web`, `docs/redesain-web/`) | Belum ditetapkan; menunggu keputusan Human Developer | Belum ditetapkan; menunggu keputusan Human Developer |
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
- Implementator, auditor, branch implementasi, dan urutan tahap belum ditetapkan. Jangan memulai implementasi sebelum Human Developer menetapkannya dan mencatatnya di tabel peran di atas.
- Temuan keamanan selama penyusunan disampaikan langsung kepada Human Developer dan sengaja tidak dirinci di repositori publik. Perbaikannya memerlukan tahap tersendiri yang ditetapkan Human Developer.
