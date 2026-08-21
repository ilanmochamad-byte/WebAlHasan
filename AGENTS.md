# AI Collaboration Protocol (AGENTS.md)

## 📌 Konteks Proyek
Repositori ini berisi dua proyek utama:
1. **WebAlHasan** (Aplikasi Web)
2. **alhasanApps** (Aplikasi Mobile)

Proyek ini dikembangkan menggunakan kolaborasi bergantian (alternating collaboration) antara Human Developer dan dua AI Assistant (Claude & Codex/AI lain). Karena AI tidak membagikan riwayat percakapan satu sama lain, file `AGENTS.md`, `PRD-V2.md`, dan riwayat commit Git bertindak sebagai **satu-satunya sumber kebenaran (Source of Truth)**.

---

## 🌿 Strategi Branching (Sangat Penting)
*   **Branch `main`:** Adalah versi stabil/produksi. **DILARANG KERAS** melakukan implementasi langsung pada branch `main` untuk mencegah kode yang belum diuji masuk ke *environment* produksi (seperti cPanel).
*   **Branch Fitur (misal: `prd-v2`):** Semua pekerjaan pengembangan dan pengujian dilakukan di branch fitur.
*   *Catatan Sistem Git:* Branch baru tidak membuat folder baru. Semua pekerjaan tetap dilakukan di folder proyek yang sama, hanya berpindah jalur riwayat Git. Tidak perlu menggunakan Git worktrees.

---

## 🤖 Peran Agen AI

Setiap AI memiliki spesialisasi tugas:
*   **Claude:** Bertanggung jawab atas **Implementasi Utama**. (Membaca PRD, menulis kode fitur baru, membuat commit tahap awal).
*   **Codex (atau AI Auditor lainnya):** Bertanggung jawab atas **Audit, Pengujian (Testing), dan Koreksi Terarah**. (Memeriksa commit Claude, menjalankan pengujian, mengoreksi bug, memastikan kriteria PRD terpenuhi).

---

## 📋 Alur Kerja Bergantian (Alternating Workflow SOP)

Jika Anda adalah agen AI yang baru saja diaktifkan, Anda **WAJIB** mengikuti alur kerja ini:

1.  **Baca Konteks:** Cek `git status`, `git log -n 5`, dan baca `PRD-V2.md`. Pastikan Anda berada di branch fitur yang benar (misal: `prd-v2`), bukan di `main`.
2.  **Kerjakan Satu Fase:** Fokus hanya pada fase yang diinstruksikan oleh Human Developer. Jangan melompat ke fase berikutnya.
3.  **Eksekusi sesuai Peran:**
    *   *Jika Anda Claude:* Implementasikan kode untuk fase tersebut, jalankan pengujian dasar, lalu buat commit. Hentikan pekerjaan agar Auditor bisa masuk.
    *   *Jika Anda Codex/Auditor:* Periksa commit terakhir, jalankan semua pengujian, bandingkan dengan `PRD-V2.md`. Lakukan commit koreksi jika ada masalah.
4.  **Handoff (Serah Terima):** Setelah satu fase lolos audit, agen AI harus memberi tahu Human Developer bahwa tugas selesai, agar pekerjaan dapat dilanjutkan ke fase berikutnya.

---

## 🤝 Prompt Handoff Standar
Human Developer akan menggunakan format instruksi berikut untuk memicu pergantian agen AI:

**Handoff ke Auditor (Codex):**
> *"Audit implementasi Fase [X] yang dibuat Claude pada branch [nama-branch]. Baca PRD-V2.md, periksa commit terbaru, jalankan seluruh pengujian, dan lakukan koreksi terarah jika diperlukan. Jangan melanjutkan ke Fase [Y] sebelum seluruh kriteria penerimaan Fase [X] terpenuhi."*

---

## ⚠️ Aturan Ketat (Strict Rules)
1.  **Tidak Ada Eksekusi Bersamaan:** Claude dan Codex **TIDAK BOLEH** dijalankan secara bersamaan pada folder dan branch yang sama. Pekerjaan harus selalu bergantian (sekuensial).
2.  **Satu Kebenaran:** Git dan PRD adalah ingatan bersama. Jangan mengandalkan riwayat percakapan AI sebelumnya.
3.  **Hanya Gabung Jika Stabil:** Branch fitur hanya boleh di-merge ke `main` setelah Codex/Auditor menyatakan seluruh fase implementasi lolos pengujian 100%.