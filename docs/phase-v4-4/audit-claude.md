# Audit Claude Code — PRD V4 Fase 4

**Tanggal:** 16 Oktober 2026 · **Auditor:** Claude Code · **Branch:** `codex/prd-v4-fase-4` · **Commit diaudit:** `72fc397` dan `3c16424` (Codex) di atas baseline `8b54106`

**Putusan:** Seluruh tujuh kriteria penerimaan Fase 4 **terpenuhi** setelah satu koreksi terarah (lihat di bawah). Fase 5 belum dimulai. Merge ke `main`, migrasi produksi, dan rilis menunggu keputusan Human Developer.

## Lingkungan uji independen

- Auditor memakai instance MariaDB 12.3.2 sendiri (datadir sementara, socket sendiri, `--skip-networking`) dan server PHP 8.4.14 lokal pada port tersendiri. Instance Codex tidak dipakai atau diubah.
- Kode dijalankan dari **salinan checkout** di direktori sementara. Dengan begitu `storage/private/v4` dan seluruh path fixture `/private/tmp/v4-fase*` milik Codex tidak tersentuh. Path fixture pada salinan uji diarahkan ke direktori auditor; kode aplikasi dalam salinan identik dengan commit diaudit (ditambah koreksi audit). Checkout asli hanya berubah oleh koreksi dan dokumen audit ini.
- Urutan DB baru: impor `k1807225_webalhasan.sql` → migrasi 001–021 dari salinan direktori → preflight/manifest → `migrate up` (022) → `up` ulang ("Tidak ada migrasi baru") → verifier 020 + manifest (124 lulus, 0 gagal) → fixture dan rangkaian Fase 1–4 dengan urutan yang sama seperti `jalankan-final.sh`.
- Rangkaian dijalankan dua kali. Putaran pertama memakai kode Codex tanpa perubahan, dan seluruh suite Codex lulus. Putaran kedua dimulai dari DB, storage, dan fixture yang benar-benar baru setelah koreksi. Bukti `audit-claude-*` berasal dari putaran kedua.
- Mobile tetap `main` / `dfae26d` dan bersih; repositori itu hanya dibaca oleh suite statis.

## Hasil per kriteria

| Kriteria | Hasil audit | Bukti |
| --- | --- | --- |
| 1. Bahan tidak lengkap → 0 PDF terbit/0 notifikasi | Lulus. Kasus yang diuji: komponen wajib hilang, bahan belum diverifikasi, sikap belum lengkap, dan absensi hilang. | `audit-claude-integrasi.txt` |
| 2. Tepat satu versi/PDF/QR dan satu InApp per wali; retry aman | Lulus. Enam koneksi dengan key sama → 6×200 dan satu versi; key berbeda → 1×200 + 5×409. Probe auditor: satu InApp per wali berakun aktif; wali tanpa akun dan wali lain mendapat nol; tidak ada WhatsApp. | `audit-claude-konkurensi-siklus.txt`, `audit-claude-probe.txt` (G) |
| 3. Hash unduhan stabil; satu byte berubah → tidak cocok | Lulus. QR pada PDF hasil render dapat dipindai. | `audit-claude-pdf.txt`, `audit-claude-http-browser.txt` |
| 4. Publik hanya status/versi/tanggal; penebakan dibatasi | Lulus. Kode acak → 404 tanpa data; 20/IP/menit → 429 + `Retry-After`; non-PDF dan >10 MB → 422. Log server tidak memuat kode 48-hex, warning, atau fatal. | `audit-claude-http-browser.txt`, `audit-claude-ringkasan.txt` |
| 5. Relasi wali aktif setiap request | Lulus pada web/API dan layanan. Yang ditolak: ID langsung/acak, flag petugas palsu, relasi dicabut, akun wali nonaktif, dan guru dengan flag petugas. Pendidikan yang penugasannya dicabut ditolak pada pratinjau, replay, riwayat, dan PDF. | `audit-claude-probe.txt` (E, F) |
| 6. Push generik; OFF → 0 request provider | Lulus dengan mock. Recheck hak/versi saat dispatch, receipt OFF, dan regresi dispatcher V2 (`v2_phase5_integration`) juga lulus. Tidak ada provider fisik yang dinyatakan lulus. | `audit-claude-push.txt`, `audit-claude-regresi-v1-v3.txt` |
| 7. Ganti/tarik beralasan; PDF lama utuh | Lulus. Probe auditor memeriksa ulang seluruh snapshot dan PDF versi lama sesudah ganti, tarik, buka, commit ambigu, dan injeksi kegagalan; semuanya byte-identik. Status kode verifikasi selalu sama dengan status versi. | `audit-claude-probe.txt` (G), `audit-claude-migrasi-rollback.txt` |

## Koreksi audit

**Rata-rata nilai tampil desimal pada rapor (`RaporService::preview`, `RaporDocument::body`).** PDF dan tampilan wali menulis "Rata-rata nilai: 84.6". PRD §5.1 **[JANGAN DIUBAH]** menetapkan perhitungan dua desimal secara internal dan **tampilan bilangan bulat pada rapor**. Nilai mapel sudah bulat (85, 84), tetapi rata-ratanya belum.

Perbaikannya:

- Snapshot baru tetap menyimpan `rata_rata` dua desimal dan menambah `rata_rata_tampil`, yaitu bilangan bulat dengan pembulatan setengah ke atas seperti `nilai_tampil` mapel. PDF dan web memakai nilai bulat itu.
- Snapshot lama tidak mempunyai kunci tersebut. Tampilannya tetap seperti PDF terbitnya, sehingga tampilan web versi lama tidak berbeda dari PDF-nya, dan snapshot/PDF lama tidak diubah.
- Hash pratinjau berubah hanya untuk pratinjau baru.

Uji `tests/v4_phase4_audit_claude.php` bagian H memeriksa tiga hal: 84.6 → 85, snapshot lama tetap 84.6, dan versi pengganti menyimpan kunci baru. Render PDF putaran kedua (`audit-claude-pdf-v1.png`) menampilkan "Rata-rata nilai: 85". `kontrak-dan-pemulihan.md` sudah diperbarui.

## Pemeriksaan fokus handoff

1. **Gate di transaksi SERIALIZABLE.** Probe A menahan penerbit (`Ganti`) sesudah gate dibaca dan PDF ditulis, sebelum commit. Pada saat itu, 14 penulisan dari koneksi lain mendapat timeout 1205 dan tidak lolos:
   - phantom insert peserta baru dan phantom insert absensi;
   - perubahan status pertemuan dan perubahan semester jadwal;
   - absensi yang sudah ada, nilai, sikap, dan pemeriksaan bahan;
   - penempatan kelas, penugasan Pendidikan pelaku, dan aset tanda tangan;
   - rencana mapel/target.

   Catatan teknis: jadwal dibaca melalui indeks sekunder penutup `id_tahun`. Akibatnya `SELECT … FOR UPDATE` per-PK tidak bentrok (negatif palsu pada probe pertama auditor), sedangkan perubahan nyata `id_tahun` diblokir. Jadi perlindungannya benar. Uji Codex untuk key sama/berbeda dan konflik versi juga diulang dan lulus.
2. **Retry.** Probe C menyuntikkan konflik 1213 secara deterministik melalui trigger dan penghitung non-transaksional, pada mode mysqli strict maupun OFF. Dua konflik dicoba ulang dan menghasilkan tepat satu versi, operasi, dan PDF. Konflik terus-menerus berakhir 409 tanpa dampak, dan berkas setiap percobaan dihapus. Revisi usang tetap 409 dan tidak pernah ditimpa. Replay sesudah hak dicabut ditolak 403, karena otorisasi dibaca ulang sebelum key diklaim. Replay sesudah Tarik mengembalikan metadata saat ini (`replayed=true`, status Ditarik) tanpa operasi atau versi baru. Key sama dengan isi berbeda → 409.
3. **Kegagalan dan berkas.** Probe B menyuntikkan kegagalan pada lima titik: insert kode verifikasi, outbox wali kedua sesudah wali pertama sukses, operasi/idempotensi, audit umum, dan pembatalan versi lama. Ditambah tulis PDF parsial. Setiap kasus berakhir 503; versi, kode, operasi, outbox, audit, status, dan jumlah berkas PDF tidak berubah.

   Probe D memakai subkelas mysqli untuk tiga kasus commit:
   - **commit sukses, jawaban `false`** dan **commit sukses lalu koneksi hilang**: berkas yang dirujuk tetap ada dan terbaca dengan hash cocok; ulang key sama menghasilkan replay;
   - **commit gagal sungguhan**: tidak ada versi dan berkas yatim dihapus.

   Kegagalan render terjadi sebelum berkas dibuat, sehingga dinilai lewat tinjauan urutan kode, bukan injeksi. Rekonsiliasi read-only (hanya menghitung `*.pdf` >24 jam). Putus jaringan nyata saat COMMIT, disk/power, dan fsync direktori tetap belum diuji.
4. **Akses.** Lihat kriteria 5. Pada router lokal, `storage/private/v4/*.pdf`, `vendor/`, `bin/`, dan `docs/` mengembalikan 403. Router hanya meniru aturan direktori `.htaccess`, bukan `FilesMatch`, sehingga `composer.json/lock` dan `PRD-V4.md` terlayani 200 secara lokal. `.htaccess` menolaknya lewat `FilesMatch`, tetapi hal itu belum dibuktikan di Apache/hosting. Respons PDF API memuat `private, no-store`, `Pragma`, `nosniff`, dan `attachment`. 401 juga `no-store`. Pratinjau GET mengabaikan `catatan_umum` di query (catatan hanya lewat body POST).
5. **Versi.** Snapshot/PDF lama byte-identik (G). Kode lama mengikuti status Diganti/Ditarik. Wali melihat status semua versi tetapi snapshot hanya untuk versi sah, dan riwayat wali tanpa alasan atau pelaku. Buka menandai versi Ditarik dan parent Perlu Perbaikan. Nilai/sikap/pemeriksaan pada rapor Terbit ditolak sampai dibuka; koreksi sesudahnya mengubah `sumber_hash` sehingga memerlukan verifikasi ulang. Perubahan penanda oleh admin menaikkan versi rencana, tetapi tidak menyentuh `sumber_hash` bahan (verifikasi tidak hilang) dan hanya berlaku pada pratinjau baru.
6. **Outbox.** Lihat kriteria 2 dan 6. Event key per versi. Outbox wajib ikut rollback (B). Isi outbox persis "Rapor semester tersedia" dengan `data_json` hanya `tipe`, `rapor_id`.
7. **PDF/tampilan.** PDF satu halaman dan fixture 30 mapel (enam halaman) dirender ulang. Tampilan 375 px, teks 24 px, dan desktop lulus uji browser tanpa overflow. Screenshot wali/Pendidikan 375 px ditinjau visual. Dompdf memakai remote/PHP/JS OFF dan chroot/temp/font cache privat; gambar tanda tangan berupa data URI dari snapshot. Dependensi dikunci: Dompdf 3.1.6 dan PHP-QRCode 5.0.5. `vendor/` tidak dikomit dan diblokir `.htaccess`.

## Hasil akhir (DB baru setelah koreksi)

| Suite | Hasil |
| --- | --- |
| Fase 4 integrasi / missing grade | exit 0, 45 / 4 lulus |
| HTTP/browser Fase 4 / navigasi / pratinjau POST | exit 0, 92 / 4 / 5 lulus |
| Konkurensi-pemulihan / siklus | exit 0, 22 / 16 lulus |
| Push mock / receipt | exit 0, 10 / 3 lulus |
| PDF, QR, 30 mapel | exit 0 |
| **Probe auditor `v4_phase4_audit_claude.php`** | exit 0, **83 lulus, 0 gagal** |
| Verifier 022 (sesudah suite, sesudah probe, sesudah rollback ditolak) | exit 0, 21 lulus |
| Verifier 020 + manifest; verifier 021 | 124 lulus; 7 lulus |
| Rollback berisi data (preflight, guard SQL) | exit 1 sesuai harapan; data 1 rapor/16 versi/19 operasi utuh |
| Latihan rollback kosong → up → up (DB skema terpisah) | exit 0 |
| Regresi Fase 1 / 2 / 3 | 29+23 / 54+2+25+8 / 73+4+56+9 lulus |
| Regresi V1–V3 | 14 integrasi lulus; 14/15 statis lulus. `phase5_static` gagal warisan (path laporan mobile lama), sama dengan baseline; tidak dilonggarkan. |
| Lint perubahan, `git diff --check`, log server | lulus; 0 warning/fatal |

Bukti auditor berawalan `audit-claude-*`; bukti Codex tidak diubah. `SHA256SUMS` mencatat keadaan Codex, sedangkan `SHA256SUMS-audit` mencatat keadaan setelah audit. Hash, path sementara, dan token uji tidak dimasukkan ke bukti.

## Catatan nonblokir

- **Hosting.** Pemblokiran `FilesMatch` (composer, PRD) dan `storage/vendor` di Apache/cPanel belum dibuktikan; router lokal bukan bukti `.htaccess`. Perlu masuk smoke Fase 5. `composer install --no-dev` dari lock juga perlu menjadi langkah rilis.
- **Semester nonaktif.** Ganti/Tarik/Buka memakai `SemesterScope::plan`, yang mewajibkan semester `Aktif`. Rapor semester yang sudah ditutup tidak dapat ditarik lewat aplikasi; Codex mencatatnya sebagai penyelesaian administratif. Human Developer sebaiknya memutuskan apakah penarikan historis diperlukan.
- **Target ketuntasan** tampil "81.00"/"75.00". Ini nilai pengaturan, bukan nilai hitung, sehingga tidak diubah. Format tampilan dapat diputuskan Human Developer; mengubahnya hanya berlaku untuk versi baru.
- **Snapshot API wali** menyertakan `pembimbing_user_id`/`diketahui_murobi_user_id` internal dan data URI tanda tangan. Respons ini hanya untuk wali berhak, tetapi ID internal petugas tidak diperlukan wali. Fase 5 dapat memangkasnya di respons tanpa mengubah snapshot.
- **Guard rollback.** Penolakan guard SQL meninggalkan tabel `v4_fase4_rollback_guard` kosong (DDL tidak transaksional), sehingga percobaan berikutnya perlu membersihkannya secara manual. Hal ini sudah dicatat Codex.
- **Kunci SERIALIZABLE** ditahan selama render PDF. Sifat ini benar tetapi konservatif. Beban kelas besar dan durasi render pada hosting belum diukur. Retry 1205 dapat menahan request hingga 4× `innodb_lock_wait_timeout`.
- Probe commit ambigu memakai subkelas mysqli, bukan putus jaringan nyata. Push pada versi yang sudah digantikan ditandai `AKSES_BERUBAH` permanen, sehingga wali hanya menerima push versi terbaru.
- Risiko lain di `risiko-terbuka.md` tetap berlaku: provider fisik, Safari/perangkat, MariaDB 10.6 hosting, dan backup/restore.
