# Audit Claude Code — PRD V4 Fase 3

**Tanggal:** 16 Oktober 2026 · **Auditor:** Claude Code · **Branch:** `codex/prd-v4-fase-3` · **Commit diaudit:** `b62a0cd` (Codex) di atas baseline `fe2ed92`

**Putusan:** Seluruh kriteria penerimaan Fase 3 **terpenuhi** setelah satu koreksi terarah (lihat di bawah). Fase 4 belum dimulai. Merge menunggu keputusan Human Developer.

## Lingkungan uji independen

- Instance MariaDB 12.3.2 milik auditor dengan datadir sementara, socket sendiri, dan `--skip-networking`. Instance ini bukan milik Codex dan bukan produksi. Server PHP lokal berjalan pada port tersendiri.
- DB baru `webalhasan_v4_fase2_test`. Nama ini wajib dipakai karena guard suite memerlukannya. Urutan persiapan: impor `k1807225_webalhasan.sql` → `migrate up` (001–021) → preflight/manifest → `migrate up` ulang ("Tidak ada migrasi baru") → verifier 020 + manifest (120 lulus) → verifier 021 (7 lulus) → fixture Fase 1–3 → rangkaian yang sama dengan `hasil-pengujian.md`.
- Percobaan pertama memakai port yang ternyata masih ditempati server sisa audit Fase 2. Server itu terhubung ke DB lama, sehingga hasil HTTP percobaan tersebut dibuang. Seluruh rangkaian kemudian diulang dari DB baru pada port lain. Tidak ada hasil yang digabung dari percobaan itu.
- Suite Codex direproduksi lebih dahulu tanpa perubahan kode: 70 integrasi, 9 transaksi/audit, 4 konkurensi, dan 56 HTTP/browser lulus (`audit-claude-reproduksi-awal.txt`). Setelah koreksi, seluruh rangkaian Fase 1–3 dijalankan ulang dari DB baru.

## Hasil per kriteria

| Kriteria | Hasil audit | Bukti |
| --- | --- | --- |
| 1. Pembimbing menyimpan tiga predikat + catatan; pembimbing lain 403 baca/mutasi | Lulus pada layanan, API, dan web. Penugasan atau relasi kamar yang dicabut ditolak pada request baru. Admin tanpa penugasan pembimbing juga ditolak 403 (probe auditor). | `audit-claude-integrasi.txt`, `audit-claude-http-browser.txt` |
| 2. Murobi mengetahui sekali, mengoreksi beralasan, murobi lain 403, versi lama dapat ditelusuri | Lulus. Namun **sebelum koreksi**, alur macet setelah murobi lama dicabut: tanda lama yang sudah tidak sah mengunci murobi pengganti. Admin tidak dapat mengarang tanda diketahui (403). | sama |
| 3. Satu pertemuan tanpa absensi → Belum dicatat 1, Alpa 0, finalisasi ditolak | Lulus | sama |
| 4. Lima status masing-masing 1 tanpa duplikasi | Lulus. Sumber hanya Selesai + snapshot + jadwal semester yang sama. UNIQUE `(pertemuan_id, santri_id)` pada `pertemuan_peserta` dan `absensi_santri` menjamin satu baris per pertemuan, sehingga urutan fingerprint stabil. | sama |
| 5. Koreksi nilai/sikap pascaverifikasi → perlu verifikasi; finalisasi ditolak tanpa rapor | Lulus untuk nilai, sikap pembimbing, koreksi murobi, dan perubahan absensi. Nol `v4_rapor`, `v4_rapor_versi`, `v4_verifikasi`, dan outbox. | `audit-claude-integrasi.txt`, `audit-claude-transaksi-audit.txt` |

## Koreksi audit

**Murobi pengganti yang sah tidak dapat menandai diketahui (`SikapService::save`).** Pemeriksaan "sekali per revisi" hanya melihat apakah `diketahui_murobi_user_id` sudah terisi. Probe auditor memakai langkah berikut: pembimbing menyerahkan sikap, murobi A menandai diketahui, penugasan A dicabut, lalu admin menugaskan murobi B pada kelas yang sama. Hasilnya:

- Matriks Pendidikan benar menyatakan `diketahui_sah=false`, "Menunggu diketahui atau koreksi murobi".
- Murobi B yang sah mendapat `409 Sikap versi ini sudah diketahui murobi`.
- Koreksi tanpa perubahan isi ditolak 422.

Akibatnya, setelah admin memperbaiki penugasan sesuai §5.4, murobi B hanya bisa maju dengan mengubah isi sikap tanpa alasan nyata, atau dengan meminta pembimbing menyerahkan ulang. Hal ini bertentangan dengan alur "ditandai diketahui **atau** dikoreksi" dan dengan penghalang yang semestinya selesai setelah penugasan diperbaiki.

Perbaikannya: tanda lama hanya mengunci bila pemiliknya masih termasuk murobi sah saat ini (`SemesterScope::murobis`). Murobi pengganti kini membuat versi baru tanpa mengubah isi. Tanda lama tetap ada di `v4_sikap_riwayat`, dan murobi yang sama tetap hanya dapat menandai sekali per revisi. Formulir `portal/sikap_semester.php` kini menampilkan tombol "Tandai diketahui" bila tanda tersimpan tidak lagi sah.

Uji tambahan `tests/v4_phase3_integration.php` (+3, total 73) memeriksa tiga hal: tanda dari murobi yang dicabut tidak dihitung sah, murobi pengganti dapat mengetahui sementara tanda lama tetap di riwayat dan isi tidak berubah, serta murobi pengganti tetap 409 pada percobaan kedua. Penugasan uji dipulihkan per ID di `finally`.

## Pemeriksaan fokus handoff

1. **Cakupan.** `SemesterScope::plan` mewajibkan kelas aktif dan semester `Aktif`. `operator()` membaca capability dari DB, dengan role diambil ulang, dan mencocokkan penempatan kelas `Aktif` atau relasi kamar semester yang sama. Admin `SUMBER_ADMIN` tidak memiliki cakupan sehingga ditolak sebagai pembimbing/murobi. `pembimbing_user_id` tidak berubah saat pengisi berganti. Setiap aktor tercatat di riwayat. Seluruh pemeriksaan diulang di dalam transaksi setelah kunci parent.
2. **Satuan verifikasi.** Satuannya adalah isian mapel–santri (rencana mapel mengikat kelas dan semester). `v4_bahan` terpisah dari `v4_nilai`. Verifikasi dan pengembalian tidak menulis komponen, dan uji memastikan versi, atribusi, serta riwayat guru tetap. Perilaku koreksi Fase 2 tidak berubah. Keputusan satuan ini ditambahkan Codex ke PRD §Fase 3 butir 4 dan konsisten dengan catatan audit Fase 2. **Human Developer sebaiknya mengonfirmasinya**, karena ini keputusan produk yang dicatat oleh implementator.
3. **Diketahui/koreksi.** Tanda diketahui ditolak pada bahan `Dikembalikan`. Koreksi murobi wajib beralasan dan mengubah isi. Penghalang muncul bila tidak ada murobi sah. Lihat juga koreksi di atas.
4. **Kehadiran.** Status null tidak dihitung Alpa, data dideduplikasi, dan fingerprint sumber membuat status efektif kembali Draf. Nol pertemuan tidak menghasilkan pertemuan sintetis. Lihat catatan nonblokir.
5. **Gate.** Gate memakai `matriks_hash` server di dalam transaksi dengan kunci parent, menolak kekurangan, verifikasi usang, murobi yang tidak sah, dan kelas ambigu, serta tidak menulis apa pun. Fase 4 tetap wajib memeriksa ulang di transaksi penerbitan.
6. **Transaksi.** Urutan kunci rencana → (rencana mapel) → baris sama dengan Fase 1–2. Kegagalan audit awal maupun akhir menggulung bisnis, riwayat, dan audit sukses pertama. Uji enam koneksi menghasilkan 1×200 + 5×409. Audit umum hanya berisi metadata (`alasan_tercatat` boolean), sedangkan alasan dan catatan hanya ada di riwayat privat. Uji juga mencakup CSRF 419, bearer 401, `no-store`, keluaran `ah_e`, dan XSS yang tidak dieksekusi.
7. **Migrasi/rollback.** 021 bersifat aditif, `up` ulang idempoten, dan verifier lulus. Rollback berisi data ditolak oleh preflight (exit 1) dan oleh guard CHECK SQL ("CONSTRAINT `v4_fase3_rollback_guard.jumlah` failed"). Setelah penolakan, data tetap ada (11 bahan, 56 riwayat) dan verifier tetap lulus. Latihan kosong → rollback → up → up lulus pada DB skema terpisah. Header dump produksi menyebut **MariaDB 10.6.24** (`cll-lve`), dan MariaDB menegakkan CHECK sejak 10.2.1. Meski begitu, guard tetap belum diuji pada hosting itu sendiri. Residu historis tidak dihapus.

## Hasil akhir (DB baru setelah koreksi)

| Suite | Hasil |
| --- | --- |
| `v4_phase3_integration.php` | exit 0, **73** lulus |
| `v4_phase3_transaction.php` | exit 0, 9 lulus |
| `v4_phase3_concurrency.php` | exit 0, 4 lulus |
| `uji-v4-fase3.mjs` | exit 0, 56 lulus (375 px, teks 24 px, desktop) |
| Fase 1 integrasi / HTTP | exit 0, 29 / 23 lulus |
| Fase 2 integrasi / konkurensi / HTTP / guru | exit 0, 54 / 2 / 25 / 8 lulus |
| Verifier 020 + manifest; verifier 021 | 120 lulus; 7 lulus |
| Rollback berisi data (preflight dan SQL) | exit 1 sesuai harapan; data dan verifier utuh |
| Latihan rollback kosong | lulus |
| `penugasan_static`, `v2_phase5_static` | exit 0 |
| `phase5_static` | exit 1, kegagalan warisan path mobile lama (sama dengan baseline) |
| `php -l` seluruh PHP yang diubah, `git diff --check`, log server PHP | lulus; 0 warning/fatal |

Regresi integrasi V1–V3 tidak dijalankan ulang oleh auditor. Koreksi hanya menyentuh `SikapService`, formulir sikap, dan uji Fase 3, jadi hasil 14 suite milik Codex tetap menjadi rujukan. Kondisi tombol UI yang diubah diperiksa melalui lint dan tinjauan kode. Logika layanannya diuji integrasi, tetapi alur tombol untuk murobi pengganti belum diklik di browser.

Bukti auditor tersimpan dalam berkas berawalan `audit-claude-*`, sedangkan bukti dan screenshot Codex tidak diubah. `SHA256SUMS` mencatat keadaan `b62a0cd`, dan `SHA256SUMS-audit` mencatat keadaan setelah audit.

## Catatan nonblokir

- **Nol pertemuan dianggap lengkap.** Santri tanpa pertemuan Selesai pada semester itu mendapat hitungan nol dan `lengkap=true`. Kehadirannya tetap harus diserahkan dan diverifikasi Pendidikan secara eksplisit. Hal ini sesuai dengan aturan tidak mengarang data, tetapi matriks belum memberi peringatan khusus. Human Developer/Fase 4 dapat mempertimbangkan peringatan atau penghalang bila kelas memiliki pertemuan sedangkan santri tidak.
- Penyuntingan sikap Draf yang sudah tersimpan memerlukan `koreksi` beralasan, sama seperti nilai Fase 2. Perilaku ini konsisten, tetapi lebih ketat daripada PRD.
- Koreksi murobi pada sikap `Dikembalikan` langsung kembali menjadi Diserahkan karena isinya berubah dan beralasan. Ini bukan jalan pintas "diketahui saja", tetapi pengembaliannya dapat diselesaikan oleh murobi, bukan oleh pembimbing.
- Admin yang juga memiliki penugasan Pendidikan (`keduanya`) selalu tercatat berkapasitas `admin` dan wajib memberi alasan. Pencatatan ini konservatif.
- Pemakai API yang mengirim koreksi murobi tanpa `catatan` akan mengosongkan catatan aktif. Catatan lama tetap ada di riwayat. Klien Fase 5 perlu mengirim catatan yang dimuat.
- Risiko pada `risiko-terbuka.md` tetap berlaku: performa matriks/opsi untuk kelas besar, retry 409, Safari/perangkat fisik, serta hosting dan restore produksi yang belum diuji.
