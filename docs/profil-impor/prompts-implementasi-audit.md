# Prompt Implementasi dan Audit — Profil Pengguna & Impor Guru/Pengurus

Sumber kebenaran: `AGENTS.md` dan `PRD-PROFIL-IMPORT.md` pada branch spesifikasi `codex/prd-profil-import-spesifikasi`. Tepat dua fase: Fase 1 website/database/API dan Fase 2 aplikasi. Claude Code adalah implementator; Codex auditor. Jalankan **satu prompt per giliran**, setelah hasil giliran sebelumnya selesai. Delapan butir `(assumption)` dalam PRD belum disetujui hanya karena prompt ini tersedia; verifikasi yang memengaruhi data, keamanan, atau alur pengguna dengan Human Developer sebelum mengandalkannya.

## 1. Claude Code — Implementasi Fase 1 (website/database/API)

```text
Implementasikan hanya Fase 1 PRD-PROFIL-IMPORT.md pada repositori WebAlHasan. Anda adalah implementator; Codex auditor berikutnya.

1. Baca AGENTS.md, PRD-PROFIL-IMPORT.md, dokumen status/commit terbaru kedua repositori, lalu verifikasi git status, git log -n 5, baseline origin/main, dan branch spesifikasi codex/prd-profil-import-spesifikasi. Buat branch fitur claude/profil-impor-fase-1 dari spesifikasi yang sudah tersedia; jangan mengerjakan langsung pada main, jangan membuat worktree, dan jangan menyentuh pekerjaan lain yang belum terlacak/berubah.
2. Tinjau setiap (assumption) PRD. Bila keputusan diperlukan untuk skema, keamanan, atau alur pengguna, minta konfirmasi Human Developer secara terarah dan catat keputusannya di PRD sebelum bergantung padanya. Jangan menganggapnya sudah disetujui. Kerjakan hanya kebutuhan Fase 1 yang telah jelas.
3. Implementasikan migrasi aditif, profil pribadi untuk seluruh jenis akun tanpa menggandakan identitas guru yang merangkap pengurus, sinkronisasi data akun/master, API baca/edit profil, foto yang terlindungi, dan ganti password dengan verifikasi password saat ini. Status/penugasan/kamar/mapel/kelas dibaca dari penugasan aktif, bukan dari input klien atau kolom tugas Excel. Pertahankan bentuk API lama secara kompatibel.
4. Tambahkan impor Excel terpisah pada Data Guru dan Data Pengurus: templat, pemetaan kolom, pratinjau tanpa simpan, validasi server per baris, penanganan NIP atau NIK dan data lama, konfirmasi hasil, impor ulang tanpa duplikasi, serta audit. Kolom Tugas, Kamar/Kobong, dan Tugas Tambahan hanya ditampilkan untuk tinjauan; tidak membuat/mengubah penugasan, akun, atau username.
5. Jalankan uji sintaks/statis, integrasi MySQL/MariaDB pada salinan atau fixture sintetis, migrasi/rollback, HTTP/API termasuk kasus lintas akun, impor ulang/konflik, foto, password, dan regresi terkait. Catat kegagalan serta batas uji secara jujur. Jangan memakai data pribadi nyata dalam bukti.
6. Simpan bukti, kontrak API, panduan migrasi/rollback dan handoff di docs/profil-impor/fase-1/. Commit perubahan terarah, push branch fitur, lalu berhenti untuk audit Codex. Jangan mulai Fase 2, merge, migrasi/deploy produksi, atau rilis aplikasi.
```

## 2. Codex — Audit Fase 1 (website/database/API)

```text
Audit hanya implementasi Fase 1 PRD-PROFIL-IMPORT.md oleh Claude Code pada claude/profil-impor-fase-1. Anda adalah auditor; jangan mengambil alih Fase 2.

1. Baca AGENTS.md, PRD-PROFIL-IMPORT.md, docs/profil-impor/fase-1/, baseline, git status/log, dan seluruh commit Fase 1. Bandingkan implementasi dengan PRD serta keputusan Human Developer; tandai asumsi yang belum disetujui dan jangan nyatakan kriteria terkait lulus tanpa keputusan/bukti.
2. Uji secara independen migrasi/rollback pada salinan MySQL/MariaDB, preservasi ID/relasi, guru yang juga pengurus, identitas semua jenis akun, impor baru/lama/ulang/konflik, NIP-NIK, serta pembuktian bahwa kolom tugas Excel tidak mengubah penugasan. Uji hak akses profil/foto lintas akun, input yang mencoba menulis username/role/penugasan, upload berbahaya, perubahan password, kegagalan audit, dan regresi layanan lama.
3. Periksa UI admin pratinjau dan hasil impor di browser bila lingkungan mendukung. Bedakan hasil uji otomatis, browser, dan produksi; jangan menganggap satu menggantikan lainnya. Jika ada temuan, lakukan koreksi terarah beserta uji regresi, atau serahkan temuan terbuka bila tidak dapat diselesaikan dengan aman.
4. Catat keputusan LULUS/BELUM LULUS, bukti, batas, risiko, dan syarat Fase 2 di docs/profil-impor/fase-1/AUDIT-CODEX.md. Commit dan push koreksi/dokumentasi audit pada branch fitur yang sama, lalu berhenti. Jangan mulai Fase 2, merge, migrasi/deploy produksi, atau rilis aplikasi.
```

## 3. Claude Code — Implementasi Fase 2 (aplikasi)

Gunakan prompt ini **setelah** audit Fase 1 menyatakan kriteria wajib lulus dan Human Developer meminta Fase 2.

```text
Implementasikan hanya Fase 2 PRD-PROFIL-IMPORT.md pada alhasanApps. Anda adalah implementator; Codex auditor berikutnya.

1. Baca AGENTS.md, PRD-PROFIL-IMPORT.md, audit final Fase 1, kontrak API final, serta status/commit kedua repositori. Pastikan API Fase 1 yang diaudit menjadi baseline, periksa pekerjaan lokal yang belum di-commit, dan buat branch mobile claude/profil-impor-fase-2 dari baseline stabil yang tepat. Jangan membuat worktree atau menimpa perubahan milik pekerjaan lain. Website hanya dibaca untuk kontrak; perbaikan server yang diperlukan dikembalikan ke audit Fase 1, bukan diselipkan tanpa jejak pada Fase 2.
2. Buat kartu identitas sebagai isi pertama Beranda setelah area aman status bar: foto/inisial, nama lengkap, seluruh status aktif, nama kamar/kobong atau kelas untuk Murobi/Pembimbing, serta pintasan ke Profil. Ambil seluruh status dan target dari API, termasuk guru yang merangkap pengurus; jangan simpulkan hak dari teks tampilan.
3. Perluas tab Profil dan buat Edit Profil untuk semua jenis akun: seluruh isian pribadi yang disetujui, foto melalui pemilih bawaan sistem, daftar tugas/mapel/kelas hanya-baca, pemutakhiran kartu tanpa login ulang, serta Ganti Password dengan verifikasi password saat ini. Tangani keadaan tanpa foto/penugasan, nama panjang, banyak status, teks besar, gagal jaringan, dan sesi kedaluwarsa. Jangan menambah izin akses menyeluruh galeri hanya untuk memilih foto.
4. Jalankan TypeScript/lint, uji otomatis yang relevan, Expo web, Android, iOS, dan regresi navigasi/fitur lama sesuai alat yang tersedia. Uji build Release iOS utuh bila mengklaim siap rilis; catat jika blocker startup iOS historis masih muncul. Jangan menyamakan emulator/simulator dengan perangkat fisik.
5. Simpan bukti dan handoff di docs/profil-impor/fase-2/ pada repositori aplikasi. Commit dan push branch fitur, lalu berhenti untuk audit Codex. Jangan merge, deploy, atau merilis aplikasi.
```

## 4. Codex — Audit Fase 2 (aplikasi)

```text
Audit hanya Fase 2 PRD-PROFIL-IMPORT.md buatan Claude Code pada alhasanApps branch claude/profil-impor-fase-2. Website Fase 1 adalah kontrak yang sudah diaudit.

1. Baca AGENTS.md, PRD-PROFIL-IMPORT.md, docs/profil-impor/fase-1/AUDIT-CODEX.md, docs/profil-impor/fase-2/, status/log kedua repositori, baseline, dan seluruh commit Fase 2. Periksa semua kriteria penerimaan Fase 2, termasuk asumsi yang kemudian disetujui Human Developer.
2. Uji independen kartu sebagai isi pertama Beranda, semua kelompok akun, banyak tugas, nama panjang/teks besar, foto/inisial, target kamar versus kelas, pasangan mapel–kelas, pencabutan penugasan pada sesi lama, edit setiap field, pembatasan username/penugasan, ganti password, password sementara, gagal jaringan, dan batas akses foto/profil lintas akun.
3. Jalankan TypeScript/lint, uji otomatis, Expo web, Android/iOS sesuai alat yang tersedia, serta regresi fitur lama. Pisahkan bukti emulator/simulator, perangkat fisik, build Debug/Release, dan hasil server uji. Verifikasi apakah blocker startup iOS historis masih ada sebelum klaim kesiapan rilis.
4. Koreksi temuan terarah bila perlu dan ulangi regresi terkait. Catat LULUS/BELUM LULUS, hasil nyata, batas, dan gerbang rilis di docs/profil-impor/fase-2/AUDIT-CODEX.md; commit dan push hasil audit pada branch yang sama, lalu berhenti. Jangan merge, deploy, atau mengirim rilis aplikasi.
```
