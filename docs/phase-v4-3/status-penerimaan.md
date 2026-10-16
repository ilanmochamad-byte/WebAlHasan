# Status Fase 3 PRD V4 — lulus audit Claude Code dengan satu koreksi terarah

Tanggal: 16 Oktober 2026 (Asia/Jakarta). Implementator: Codex. Seluruh hasil di bawah adalah pengujian implementator, **bukan putusan audit independen**. Hanya Fase 3 web/API; tidak ada implementasi Fase 4–5, merge, atau deploy.

Baseline remote `origin/codex/prd-v4-fase-2` diverifikasi melalui `git ls-remote`: `fe2ed92e2277f557448889769d2f9dd7aa879445`, sama dengan checkout dan remote tracking. Audit Claude Code Fase 2 menyatakan seluruh kriteria terpenuhi dengan dua koreksi. Branch `codex/prd-v4-fase-3` dibuat tepat dari baseline itu; tidak ada worktree. Checkout web dan mobile awal bersih. Mobile tetap `main` pada `dfae26d` tanpa perubahan.

| Kriteria penerimaan Fase 3 | Hasil implementator | Bukti |
| --- | --- | --- |
| 1. Pembimbing menyimpan tiga predikat/catatan; pembimbing lain 403 baca/mutasi | Lulus layanan dan API; formulir web pembimbing menyimpan/menyerahkan. Penugasan kelas dan kamar diuji, pencabutan tugas/relasi kamar ditolak. | `integrasi-mariadb.txt`, `http-browser.txt`, `browser-pembimbing-375.png` |
| 2. Murobi mengetahui tepat sekali, koreksi beralasan, riwayat lama terjaga; murobi lain 403 | Lulus layanan/API/web. Diketahui ulang dengan versi terbaru tetap 409; koreksi tanpa alasan 422. Pembimbing historis tetap, aktor murobi tercatat di revisi baru. Tidak ada murobi sah menjadi penghalang; diketahui tidak dapat melewati pengembalian Pendidikan. | `integrasi-mariadb.txt`, `http-browser.txt`, `browser-murobi-375.png` |
| 3. Satu pertemuan tanpa absensi → Belum dicatat 1, Alpa 0, finalisasi ditolak | Lulus kasus tepat satu pertemuan sebelum menambah lima status lain; penyerahan rekap tidak lengkap juga ditolak. | `integrasi-mariadb.txt` (K3) |
| 4. Lima status masing-masing 1 tanpa duplikasi | Lulus. Lima absensi dalam lima pertemuan menghasilkan `[1,1,1,1,1]`; pertemuan keenam tanpa absensi tetap terpisah. Hanya Selesai, semester snapshot dan jadwal cocok; Draf/Dibuka dan semester lain dikecualikan. | `integrasi-mariadb.txt` (K4) |
| 5. Koreksi nilai/sikap membatalkan verifikasi; finalisasi ditolak, nol rapor terbit | Lulus layanan untuk nilai dan pembimbing; layanan/API/web untuk sikap, termasuk koreksi murobi pascaverifikasi. Perubahan absensi juga menonaktifkan verifikasi efektif. Nol baris rapor, versi terbit, kode publik dan outbox pada DB uji. | `integrasi-mariadb.txt`, `http-browser.txt`, `transaksi-audit.txt` |

Hasil akhir: **70 integrasi**, **9 transaksi/audit**, **4 konkurensi**, dan **56 HTTP/browser** Fase 3 lulus. Dua percobaan browser awal gagal karena ekspektasi fixture dan kebutuhan hash matriks dari server; log awal dipertahankan. Hasil akhir berasal dari rangkaian baru setelah perbaikan, bukan menambah angka dari percobaan gagal.

Regresi Fase 1: 29 integrasi, 23 HTTP/browser lulus. Regresi Fase 2: 54 integrasi, 2 konkurensi, 25 HTTP/browser pengganti, 8 web guru lulus. Regresi V1–V3: 14 suite integrasi dan 14 dari 15 suite statis lulus; `phase5_static` tetap gagal pada path mobile lama. Lint/diff-check dan verifier skema lulus. Latihan rollback 021 kosong lalu penerapan ulang lulus; rollback berisi data ditolak baik oleh preflight maupun guard SQL.

Lihat `hasil-pengujian.md` untuk perintah, `kontrak-dan-migrasi.md` untuk satuan verifikasi/migrasi, dan `risiko-terbuka.md` untuk batas aktual. Hash commit implementasi disampaikan pada handoff chat; audit seluruh diff terhadap `fe2ed92`.

## Audit Claude Code (16 Oktober 2026)

Audit independen menyatakan kelima kriteria **terpenuhi** setelah satu koreksi. Murobi pengganti yang sah sebelumnya ditolak 409 karena tanda diketahui milik murobi yang sudah dicabut tetap mengunci. Kini tanda hanya mengunci bila pemiliknya masih sah. Pada DB baru auditor, hasilnya: 73 integrasi, 9 transaksi/audit, 4 konkurensi, 56 HTTP/browser, regresi Fase 1–2, verifier, serta penolakan dan latihan rollback lulus. Detail dan catatan nonblokir ada di `audit-claude.md`. Merge dan Fase 4 menunggu keputusan Human Developer.
