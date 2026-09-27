# Audit independen Claude Code — PRD V3 Fase 5

27 September 2026. Koreksi mobile: alhasanApps `02a917f`. Auditor: Claude Code, satu sesi, tidak bersamaan dengan Codex. Objek audit: web `33d975a` (baseline `e79919a`) dan mobile `0901088` (baseline `bd8b7f0`), keduanya branch `prd-v3-fase-5`. Database: `webalhasan_v3_phase1_test` (MariaDB 12.3.2 lokal) dan salinan terisolasi `test_f5_audit_test` untuk uji asal residu. Tidak ada akses produksi, merge, deploy, migrasi/purge produksi, atau perubahan sakelar Push ON/WhatsApp OFF. Tidak memulai V4–V6.

## Kesimpulan

Implementasi Fase 5 **lulus audit kode dan regresi otomatis/browser lokal setelah dua koreksi terarah** (satu web, satu mobile). Fase 5 **belum selesai seluruhnya**: kriteria perangkat fisik, lingkungan setara produksi, cron/receipt produksi, smoke cPanel, dan rekonsiliasi orphan nol untuk seluruh DB masih terbuka. **Jangan merge** ke `main`.

## Temuan dan koreksi

| # | Temuan | Dampak | Tindakan |
| --- | --- | --- | --- |
| K1 (mobile, tinggi) | `usePrivateResource` memasang `useFocusEffect` dengan dependensi `load`→`fetcher`→`page`. Mengganti halaman pilihan di `pembinaan/buat` menjalankan cleanup fokus yang memanggil `clear()`, sehingga santri, katalog, waktu dan uraian terhapus. Santri dan katalog berbagi parameter `page`, sehingga santri halaman 1 tidak dapat dipadukan dengan katalog halaman ≥2 (DB uji: 3 santri, 932 katalog aktif). | Pembimbing tidak dapat mencatat pelanggaran di aplikasi untuk katalog di luar 25 pertama, atau untuk santri ke-26 dst. bila katalog ≤25. | Direproduksi di Expo web + backend nyata (2 gagal). Hook diperbaiki: pergantian fetcher hanya memuat ulang data; pembersihan isian privat tetap pada blur, latar belakang, dan unmount. Tiga pemeriksaan regresi ditambahkan ke `tests/browser/uji-v3-fase5-mobile.mjs`, termasuk bukti bahwa aplikasi ke latar tetap menghapus isian. |
| K2 (web, sedang) | Redaksi hash audit V3 (`AuditLogger::v3Metadata`) juga mengenai entitas master `v3_katalog`/`v3_kategori` sehingga `uraian` hanya tersimpan sebagai `uraian_sha256`. Tabel itu tidak memiliki tabel revisi, sehingga nilai sebelum perubahan hilang. | Melanggar metrik PRD §7: setiap perubahan katalog memiliki audit nilai sebelum/sesudah. Data master bukan isi santri/konseling. | Dikecualikan `v3_katalog`, `v3_kategori`, `v3_ambang` dari redaksi; entitas pelanggaran/konseling/publikasi tetap di-hash. Pemeriksaan regresi ditambahkan ke `tests/v3_phase5_integration.php` (terbukti gagal sebelum koreksi: baris audit `v3.katalog.buat` berisi `uraian_sha256`). Audit katalog lama yang sudah ter-hash tidak diubah. |

Tidak ada verifier yang dilemahkan dan tidak ada residu historis yang dihapus.

## Asal residu 96 outbox + 24 audit (terbukti)

Inventaris: 96 baris `notifikasi_outbox` `izin.*` kanal InApp status Sent dengan `pengajuan_id` dan `penerima_user_id` yang sudah hilang (72 baris 22 Sep, 24 baris 27 Sep), serta 24 `audit_logs` `login_succeeded` dengan aktor hilang (18 + 6). Pola 24 outbox + 6 audit per tanggal sama dengan satu run fixture V2.

Reproduksi pada salinan `test_f5_audit_test` (dibuat dengan `mysqldump` dari DB uji, hak `test\_%`):

| Langkah | Outbox yatim | Audit yatim |
| --- | ---: | ---: |
| Awal salinan | 96 | 24 |
| `v2_phase2_integration.php` versi baseline `e79919a` | 117 | 24 |
| `v2_phase2_web_smoke.php` versi baseline | 124 | 30 |
| `v2_phase3_api_contract.php` versi baseline | 124 | 30 |
| Ketiga fixture versi terkoreksi `33d975a` (94 + 36 + 116 lulus) | 124 | 30 |

Jenis baris baru dari run baseline sama dengan residu: `izin.pengajuan_dibuat`, `izin.koreksi`, `izin.murobi_ditetapkan(_ulang)`, `izin.keputusan_*`, `izin.pembatalan` dan tepat 6 `login_succeeded`. Kesimpulan: residu berasal dari teardown fixture V2 lama pada database uji, bukan dari migrasi atau kode produksi V3; teardown terkoreksi tidak menambah residu. Pada DB uji utama residu tetap `[96,24]` setelah seluruh regresi audit. Kriteria "referensi yatim 0" untuk seluruh DB uji tetap **BELUM TERPENUHI**; penyelesaiannya (DB uji baru dari migrasi atau pembersihan terdokumentasi atas izin Human Developer) adalah keputusan Human Developer. Suite V3 terkunci pada nama DB `webalhasan_v3_phase1_test`, sehingga DB baru tidak dapat menjalankan suite V3 tanpa mengganti DB tersebut.

## Pemeriksaan yang dikonfirmasi

- **Akses laporan:** `kinds` dari capability; wali hanya `publikasi` lewat `parentFrom` (tanpa join kasus/sesi/pelanggaran); filter internal wali 422; tebakan jenis 403. Probe Rahasia pada salinan: kasus Rahasia terlihat admin dan pembimbing pemilik, tidak terlihat murobi maupun pembimbing lain; filter `tindak_lanjut=ada` tidak memasukkan pelanggaran yang hanya ditindaklanjuti kasus Rahasia bagi murobi (muncul di `belum`), sehingga tidak menjadi sinyal. Sentinel tujuan Rahasia tidak muncul di keluaran mana pun.
- **Filter:** status Draf diterima sesuai daftar status; angka `^\d{1,10}$` dan ≤ INT maks; tanggal ketat; teks ≤150 dan terikat parameter; `murobi_id` mengganti tepat satu placeholder `ux.id=?` → `mx.id=?`; urutan parameter sesuai urutan klausa.
- **Ekspor:** COUNT dan SELECT dalam satu transaksi REPEATABLE READ; >10.000 ditolak 422 sebelum output; CSV BOM + `csvCell` menetralkan `= + - @` setelah spasi/kontrol; tidak ada berkas ekspor disimpan di server; `Cache-Control: private, no-store`.
- **Options:** `readMode` menolak wali 403 sebelum katalog; halaman kosong tetap `dapat_mencatat` sesuai capability; halaman divalidasi.
- **Migrasi 019:** hanya indeks aditif dengan guard `information_schema`; rollback hanya indeks; drill idempoten dan hash seluruh tabel bisnis tetap.
- **Purge pratinjau:** hanya >7 hari lewat kedaluwarsa, admin aktif, batch 1–500, dry-run, `FOR UPDATE`, audit dalam transaksi yang sama (kegagalan audit menggulung batch).
- **Mobile privasi:** tidak ada `console`, clipboard, analytics, penyimpanan persisten, atau notifikasi lokal pada kode pembinaan; field privat menonaktifkan autocorrect/autofill; data dan isian dibersihkan saat blur/latar/unmount; respons usang diabaikan melalui `generation`.
- **Manifest:** `shasum -c` manifest implementator cocok untuk seluruh berkas kecuali tiga yang diubah auditor; manifest diperbarui.

## Regresi independen (setelah koreksi)

| Rangkaian | Lulus | Gagal | Catatan |
| --- | ---: | ---: | --- |
| PHP lint berkas diubah | semua | 0 | |
| V3 Fase 5 runner | 153 | 0 | +1 pemeriksaan audit master |
| V3 Fase 4 runner | 122 | 0 | |
| V3 Fase 3 runner | 297 | 0 | |
| V3 Fase 2 runner | 172 | 0 | |
| V3 Fase 1 runner | 69 | 2 | Diagnostik sehat/setelah perbaikan nonzero karena residu 96/24 — sesuai laporan implementator |
| `bin/v3_verify.php` | struktur V3 lulus | 3 warisan | Residu di atas; exit nonzero dipertahankan |
| V1/V2/fondasi `penugasan_run_all_tests.sh` (run kedua, setelah koreksi) | 4.020 | 0 | 2.378 + 248 + 215 + 257 + 334 + 588; run pertama 1 berkas gagal intermiten (lihat bawah) |
| Browser Fase 1 / 2 / 3 / 4 / 5 | 74 / 40 / 53 / 34 / 55 | 0 | Chromium, server PHP lokal |
| Expo web Fase 5 (375 px) | 23 | 0 | 20 implementator + 3 regresi K1 |
| Mobile `tsc --noEmit`, `npm run lint`, `test:print-dialog` | lulus, lulus, 6 | 0 | |

Intermiten: pada run penuh pertama `tests/v2_phase3_api_contract.php` gagal di blok concurrency (pengajuan race tidak terbentuk, keputusan 403/403). Berkas yang sama lulus 116/0 pada lima run terpisah dan pada run di salinan. Tanggal uji memakai offset acak 400–3000 hari; perubahan Fase 5 pada berkas ini hanya teardown saat shutdown. Penelusuran lanjutan: 46 pengajuan sisa run lama (30 `Disetujui`) untuk `SBX-S-001` pada jendela offset acak memicu tolakan tumpang tindih (409) pada 245/2.601 offset (9,4%); offset 543 yang dipatok mereproduksi keempat kegagalan. Uji dikoreksi agar memilih ulang offset yang bebas status menahan; 10/10 run lulus 116/0, residu tetap [96,24]. Bukan regresi Fase 5.

## Tetap tertunda (bukan lulus)

- **MENUNGGU UJI FISIK:** APK/IPA dari SHA audit pada Android dan iOS: alur pembimbing (termasuk pemilihan santri/katalog lintas halaman setelah K1), murobi, wali, keyboard/safe area/aksesibilitas, jaringan/timeout/401/409/cold start/deep link, push murobi dan publikasi dengan payload generik, receipt akhir. Smoke Fase 3 fisik.
- **MENUNGGU PRODUKSI:** cPanel/MySQL setara produksi untuk seluruh regresi dan performa <2 detik; backup/restore; migrasi 019; cron/worker; receipt produksi; smoke produksi dan pembersihannya; purge pratinjau operasional.
- **BELUM TERPENUHI:** referensi yatim 0 untuk seluruh DB uji (asal terbukti, keputusan Human Developer).
- **UAT:** target 95% dan pembaca layar nyata.
- Catatan nonblokir: ekspor CSV/cetak laporan tidak dicatat di audit (PRD tidak mewajibkan); membersihkan isian saat aplikasi ke latar adalah pilihan privasi yang perlu dinilai pada UAT.
