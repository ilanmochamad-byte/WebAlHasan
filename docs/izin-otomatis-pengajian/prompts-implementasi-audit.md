# Prompt — Status "Izin" otomatis pada absensi pengajian

Satu tahap. **Claude Code implementator, Codex auditor**, bergantian (tidak bersamaan pada folder/branch yang sama). Keberadaan prompt ini bukan perintah memulai; Human Developer yang menyalin dan menjalankannya.

---

## Prompt implementasi (Claude Code)

> Implementasikan status "Izin" otomatis pada absensi pengajian dalam **satu tahap** pada branch `claude/izin-otomatis-absensi` di repositori web `WebAlHasan` dan mobile `alhasanApps`. Kamu implementator; Codex auditor sesudah handoff.
>
> **Baca dulu:** `AGENTS.md` (bagian keputusan 23 November 2026), `docs/izin-otomatis-pengajian/rencana.md` (keputusan IP-1…IP-6, rancangan §3, kriteria K-1…K-9, batas §6), `docs/redesain-web/keputusan.md` (R, S, dan revisi S), serta dokumen perbaikan peserta absensi `docs/perbaikan-peserta-absensi/` dan kelas gabungan `docs/jadwal-kelas-gabungan/` karena menyentuh `PesertaPertemuan` yang sama.
>
> **Baseline:** `git fetch`, lalu buat branch dari `origin/main` kedua repositori (web harus sudah memuat dokumen rencana ini; bila belum digabung, buat dari `origin/claude/rencana-izin-otomatis-pengajian`). Catat SHA baseline dan versi `expo.version`. Tanpa worktree, jangan menulis di `main`. Perubahan `package.json`/`package-lock.json` yang belum di-commit di checkout mobile dan direktori untracked web milik pekerjaan lain **tidak disentuh dan tidak di-commit**; kerjakan mobile dari klon bersih bila checkout utama kotor.
>
> **Kerjakan sesuai rencana §3:**
> 1. Migrasi aditif `036_izin_otomatis_pengajian.sql` + rollback berpasangan (`pertemuan_peserta.izin_pengajuan_id`, `izin_keterangan`); kode memeriksa skema dan berperilaku seperti baseline sebelum migrasi/sesudah rollback.
> 2. Satu pembantu pengisian usulan dipanggil di dalam transaksi `PesertaPertemuan::bekukan()` dan `perbarui()` sesuai §3.3 (izin Disetujui dengan baris `izin_keputusan` Disetujui, rentang tanggal inklusif, keterangan persis format IP-3 per kapasitas, ID terbesar bila tumpang tindih; perbarui hanya menyelaraskan peserta belum berabsensi pada pertemuan Dibuka).
> 3. Kunci aditif `izin` pada payload pertemuan REST (§3.4); validasi `saveAttendance`, idempotensi, dan hak tidak berubah; server tidak memaksa status.
> 4. Tampilan baca web (§3.6): keterangan dari snapshot; label "Sedang izin" lama tetap untuk pertemuan tanpa usulan. Tanpa formulir absensi web.
> 5. Aplikasi (§3.5): draf awal Izin + keterangan, penanda "Izin s.d. …", aturan catatan saat status diubah, "Semua hadir" melewati santri izin, sinkron sesudah perbarui, keterangan di rincian laporan dan Keamanan. Naikkan `expo.version` tepat satu minor (`npm run versi:naik minor`). Tanpa dependensi/modul native baru.
>
> **Privasi:** alasan, catatan, keputusan, dan nama pengaju izin tidak boleh masuk payload, audit, log, atau tampilan absensi.
>
> **Uji (catat hitungan lulus/gagal nyata, MariaDB lokal, fixture sintetis):** statis + lint; integrasi layanan (K-1…K-3, termasuk perbarui bersamaan dengan penyimpanan absensi, dan kontrol tanpa migrasi K-7); HTTP web/REST (K-4); migrasi naik–rollback–naik dengan preservasi isi tabel lama; rekap rapor V4 dan laporan (K-6); unit, tsc, lint aplikasi; Expo web untuk absensi berizin (375/768/1280); Android emulator dan iOS simulator (K-9). Jalankan kontrol yang sama pada baseline untuk membuktikan uji baru gagal sebelum perubahan. Regresi K-8: kelas gabungan, perbaikan peserta absensi, pencarian santri, Keamanan Fase 3/4, izin V2/V7, rapor V4, HTTP redesain Tahap 4. Oracle historis yang gagal karena migrasi 036 dicatat, **tidak dilonggarkan**.
>
> **Handoff:** tulis `docs/izin-otomatis-pengajian/implementasi/README.md` (web) dan ringkasan di mobile pada lokasi yang sama: SHA baseline/commit, perubahan, bukti dan hitungan, penafsiran bernomor, temuan, batas (MariaDB 10.6/cPanel, perangkat fisik, distribusi EAS, Push, UAT belum diuji), langkah rilis/pemulihan. Perbarui catatan implementasi di `AGENTS.md`. Commit dan push kedua branch fitur, lalu **berhenti** untuk audit Codex. Jangan merge, deploy, migrasi produksi, atau merilis aplikasi.

---

## Prompt audit (Codex)

> Audit implementasi status "Izin" otomatis pada absensi pengajian buatan Claude Code pada branch `claude/izin-otomatis-absensi` di repositori web `WebAlHasan` dan mobile `alhasanApps`. Satu tahap; kamu auditor independen.
>
> **Baca:** `AGENTS.md` (keputusan 23 November 2026 dan catatan implementasi), `docs/izin-otomatis-pengajian/rencana.md` (IP-1…IP-6, §3, K-1…K-9, §6), handoff `docs/izin-otomatis-pengajian/implementasi/README.md` kedua repo, dan seluruh commit tahap dari baseline yang dicatat. Verifikasi bahwa baseline benar dan versi aplikasi naik tepat satu minor.
>
> **Periksa secara independen**, bukan hanya mengulang uji implementator:
> - Aturan izin yang dihitung (hanya Disetujui oleh murobi/admin pengganti, kedua asal, rentang inklusif, tumpang tindih) dan format keterangan IP-3 per kapasitas.
> - Perilaku perbarui: peserta belum/sudah berabsensi, izin dibatalkan/dikoreksi sesudah dibuka, pertemuan Selesai tidak berubah, konkurensi dengan penyimpanan absensi pada kunci pertemuan yang sama.
> - Kontrak REST aditif dan kompatibilitas aplikasi lama; server tidak memaksa status; idempotensi dan hak tetap.
> - Privasi: tidak ada alasan/catatan/keputusan/nama pengaju di payload, audit, log, tampilan absensi, atau rincian Keamanan.
> - Aplikasi: draf awal, penanda, ubah ke Hadir tersimpan Hadir, aturan catatan, "Semua hadir" melewati santri izin, sinkron sesudah perbarui, ketukan ganda satu penyimpanan, perlindungan layar dan idempotensi tidak berubah.
> - Rekap rapor V4 dan laporan menghitung status tersimpan; migrasi 036 naik–rollback–naik dengan preservasi isi tabel lama dan perilaku baseline sesudah rollback.
> - Regresi K-8 dan oracle historis; bandingkan dengan kontrol baseline yang kamu jalankan sendiri.
> - Uji native Android emulator dan iOS simulator (K-9), dengan jenis build yang dipakai dicatat jujur.
>
> Lakukan **koreksi terarah** bila perlu beserta probe yang gagal sebelum dan lulus sesudah koreksi; jangan melonggarkan oracle. Tulis `docs/izin-otomatis-pengajian/AUDIT-CODEX.md` (web) dan ringkasan mobile pada lokasi sama: putusan (lulus/belum lulus per kriteria), temuan, bukti dan hitungan, diagnostik kesalahan alat, batas yang belum diuji (MariaDB 10.6/cPanel, fisik, distribusi, Push, UAT). Perbarui catatan audit di `AGENTS.md`. Commit dan push hanya branch fitur yang sama, lalu **berhenti**. Jangan merge, deploy, migrasi produksi, merilis aplikasi, atau memulai pekerjaan lain; perubahan paket mobile dan direktori web milik pekerjaan lain tidak disentuh.
