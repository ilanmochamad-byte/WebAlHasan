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
| PRD V4–V6 | Belum ditetapkan; menunggu keputusan Human Developer | Belum ditetapkan; menunggu keputusan Human Developer |

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

**Handoff workstream lama/fondasi ke Auditor Codex:**
> *"Audit implementasi [fase/workstream] yang dibuat Claude pada branch [nama-branch]. Baca AGENTS.md dan PRD yang berlaku, periksa commit terbaru, jalankan seluruh pengujian, dan lakukan koreksi terarah jika diperlukan. Jangan melanjutkan ke fase berikutnya sebelum seluruh kriteria penerimaan terpenuhi."*

---

## ⚠️ Aturan Ketat (Strict Rules)
1.  **Tidak Ada Eksekusi Bersamaan:** Claude dan Codex **TIDAK BOLEH** dijalankan secara bersamaan pada folder dan branch yang sama. Pekerjaan harus selalu bergantian (sekuensial).
2.  **Satu Kebenaran:** Git dan PRD adalah ingatan bersama. Jangan mengandalkan riwayat percakapan AI sebelumnya.
3.  **Hanya Gabung Jika Stabil:** Branch fitur hanya boleh di-merge ke `main` setelah auditor yang ditetapkan untuk workstream tersebut menyatakan fase memenuhi kriteria wajib berdasarkan bukti pengujian. Keterbatasan atau pengujian nonblokir yang belum dijalankan harus tetap dicatat secara terbuka.
