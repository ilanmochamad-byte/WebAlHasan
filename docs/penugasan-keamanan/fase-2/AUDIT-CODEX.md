# Audit Codex — penugasan lintas guru/pengurus, Fase 2

**30 Oktober 2026 — LULUS audit teknis lokal setelah dua koreksi terarah.** Bukan persetujuan merge, deploy, migrasi produksi, atau rilis aplikasi. Fase 3 belum dikerjakan.

## Cakupan dan baseline

- Branch tetap `claude/penugasan-keamanan-fase-2`, tanpa worktree, subagen, atau menjalankan Claude bersamaan.
- Seluruh commit implementasi fase: `610b6b50155cfc0e786d76267dfa963e97f75fda`, di atas audit Fase 1 `464d9a18871b1bbf4cd7faa1b2df5222ea6eafa6`.
- `git ls-remote` saat audit: branch Fase 2 `610b6b5`, Fase 1 `464d9a1`, main `2e4f5b5ef4d2a949de814c5e78d8247ee615e4c2`. `git diff 464d9a1 origin/main` kosong. Kegagalan DNS pertama dalam sandbox diatasi lewat akses jaringan alat yang disetujui; baseline remote benar-benar diverifikasi.
- Dibaca: `AGENTS.md`, rancangan dan keputusan PK-1…PK-7/PK-T1…PK-T7, audit Fase 1, seluruh diff kode/portal/uji Fase 2, README dan bukti handoff. Kontrak izin, sikap/verifikasi/rapor serta jalur V3 dibandingkan dengan PRD V2/V4/V7 dan implementasi baseline.
- Lingkungan: PHP **8.4.14**, MariaDB **12.3.2**, Chromium Playwright. Data selalu fixture sintetis lokal. Tidak membaca dump pribadi atau mengakses DB produksi.
- Empat direktori milik pekerjaan lain tetap tidak diubah/di-commit: `docs/panduan-pengguna-v4/`, `docs/presentasi-al-hasan-v4/`, `output/`, `tests/browser/tangkapan-penugasan-keamanan-f1/`. Mobile tidak diubah.

## Keputusan kebijakan saat audit

Human Developer menjawab **"Ya, pertahankan larangan V3"** atas PF2-6. Tanda mengetahui pelanggaran/kasus/sesi V3 milik sendiri tetap dilarang, meskipun daftar awal Fase 2 hanya menyebut izin dan rapor. Keputusan dicatat sebagai **PK-T8** pada rancangan dan `AGENTS.md`. Ini menyelesaikan pertanyaan cakupan; bukan persetujuan fase berikutnya.

## Temuan dan koreksi

### F-1 · P2 — pengisi terdahulu hilang dari pemeriksaan revisi V3

`App\V3\PemisahanTugas::sesiMilik()` hanya membaca baris sesi terbaru dan pemilik kasus, tanpa menelusuri `revisi_dari_id`. Pada kasus milik Y: sesi dibuat Y, dikoreksi X, lalu dikoreksi Y lagi; X dapat mengetahui versi terbaru meskipun pernah mengisinya sebagai pembimbing. Kepemilikan kasus tidak menangkap contoh ini karena X tidak membuat atau memperbarui kasusnya.

Pemeriksaan pelanggaran menelusuri revisi tetapi berhenti setelah 100 baris dan mengembalikan `false`. Sesudah lebih dari 100 revisi lanjutan oleh Y, catatan yang sebelumnya dikoreksi X kembali dapat diketahui X. Kedua skenario direproduksi melalui layanan resmi, termasuk 101 koreksi lanjutan, bukan sekadar pemanggilan helper dengan ID buatan.

**Koreksi:** sesi dan pelanggaran memakai penelusuran seluruh rantai revisi yang sama. Tidak ada batas yang berubah menjadi izin. Siklus atau sumber revisi hilang menghasilkan 503, sehingga rantai rusak tidak membuka akses. Tetap hanya SELECT; tidak ada perubahan skema, isi historis, atau alur pencatatan/koreksi.

### F-2 · P2 — replay melewati pemisahan tugas

Keputusan dan koreksi izin mengembalikan respons idempotensi sebelum memeriksa apakah akun pernah mengajukan/meninjau. Penetapan murobi juga memutar ulang penetapan lama kepada pengaju sendiri. Ketiga tanda mengetahui V3 mempunyai urutan serupa. Respons sukses yang tersimpan sebelum aturan baru dapat dikembalikan lagi walau permintaan baru dengan kunci lain sudah ditolak.

Probe membentuk respons sukses **menggunakan layanan baseline Fase 1 `464d9a1`** pada fixture lokal: keputusan sendiri oleh murobi/admin, koreksi admin pengaju, penetapan kepada guru pengaju, keputusan oleh peninjau V7, serta tanda mengetahui sendiri pada pelanggaran/kasus/sesi. Dengan sumber Claude Fase 2, replay masih sukses. Tidak ada klaim bahwa replay menambah baris keputusan baru; celahnya adalah respons sukses melewati aturan hak yang baru.

**Koreksi:** replay izin mengunci pengajuan dan menjalankan pemeriksaan pemisahan sebelum mengembalikan respons tersimpan; penetapan guru terlibat tetap 422, keputusan/koreksi akun terlibat 403. Pada V3, pemeriksaan sumber/pemilik dilakukan sebelum replay. Riwayat dan keputusan lama tetap utuh. Kontrol replay admin/murobi lain tetap sukses dan tidak menggandakan baris.

**Probe final identik** `tests/penugasan_keamanan_f2_audit_probe.php`: arsip kode Claude `610b6b5` **12/12**, sumber terkoreksi **24/0**. Oracle tidak diubah antara kedua eksekusi final itu. Berkas `probe-kontrol-claude.txt` dan `probe-final.txt` di `audit-codex/`.

## Pengujian independen

Angka berarti **lulus/gagal**, bukan jumlah suite. Bukti auditor dipisahkan dari `bukti/` implementator.

| Rangkaian | Hasil | Bukti di `audit-codex/` |
| --- | --- | --- |
| Statis Fase 2, batas berkas, lima kunci aksi, lint | **58/0**; awal 56/0, bertambah dua lint probe | `statis-akhir.txt` |
| Integrasi Fase 2 J-1…J-9 pada sumber final | **53/0** | `integrasi-akhir.txt` |
| Web/REST Fase 2 W-1…W-7 | **28/0** | `http.txt` |
| Browser 375/768/1280px dan tanpa JS 375px | **41/0** | `browser.txt`, `browser-log.txt` |
| Riwayat revisi dan replay izin/V3 | **24/0**, kontrol Claude **12/12** | `probe-final.txt`, `probe-kontrol-claude.txt` |
| Riwayat pengisi V4, penantian kunci, PK-T5 sampai PDF | **9/0** | `probe-v4.txt` |

HTTP/browser memakai sumber final koreksi; empat PNG di `/private/tmp/pkf2-audit-browser/` diperiksa visual, tidak di-commit. Penjelasan terlihat dan aksi terlarang tidak ditawarkan; pengujian tiga lebar tidak menemukan overflow horizontal. Log server tidak memuat warning/notice/fatal/deprecated PHP. Native mobile tidak termasuk bukti ini.

Probe V4 membuktikan:

- Pengisi nilai X tetap tidak boleh verifikasi/kembalikan setelah seluruh komponen terakhir diisi pihak lain, karena riwayatnya tetap dihitung.
- Sikap dengan pembimbing pertama Y, koreksi X, lalu koreksi/penyerahan Y tetap menolak mengetahui oleh X.
- Proses verifikasi benar-benar terlihat menunggu kunci `v4_rencana` melalui `information_schema.PROCESSLIST`. Saat menunggu, koneksi pengendali mengubah aktor sumber menjadi X pada fixture dan commit; setelah memperoleh kunci, layanan verifikasi membaca ulang lalu menolak 403. Penulis pada probe ini adalah injeksi fixture di bawah kunci yang sama dengan penyimpanan nilai, **bukan klaim dua layanan penulis dijalankan bersamaan**.
- Setelah nilai diverifikasi admin, X tetap dapat memverifikasi sikap/kehadiran, finalisasi, dan menerbitkan satu PDF nyata pada direktori sementara. Nilai sendiri tidak memblokir seluruh rapor sesudah verifikasi pihak lain (PK-T5). PDF/aset sementara dibersihkan; DB uji penerbitan itu tidak dipakai ulang sebagai fixture rilis.

### Regresi dan oracle historis

Rangkaian `perintah-regresi.sh` dieksekusi independen dengan `OUT=.../audit-codex/regresi` dan `PFX=pkf2aud`. Penyiapan DB yang memakai nama perantara tetap berjalan berurutan. Suite V3 memakai DB sintetis tetap yang di-dump lalu dipulihkan sesudah setiap suite. Untuk HTTP/browser/probe tambahan, auditor menyalin snapshot DB sintetis V7 Fase 4 yang sudah selesai diuji; tidak menjalankan builder perantara bersamaan.

**Hasil per suite dan perbandingan akhir dicatat di `audit-codex/perbandingan-regresi.txt`.** Seluruh kegagalan historis dipertahankan; tidak ada pelonggaran suite lama. Kontrol baseline regresi menyeluruh di `bukti/kontrol-baseline/regresi/` adalah eksekusi implementator, bukan diklaim sebagai eksekusi baseline baru oleh auditor. Auditor mengeksekusi sendiri baseline Fase 1 untuk pembuatan cache lama dan arsip Claude untuk kontrol probe.

- **38 suite non-statis**, seluruh exit/hitungan sama dengan kontrol baseline handoff. Ringkasan kode Claude cocok 37/38: baris probe token masih merekam percobaan salah urutan `exit=1 0/0`, sedangkan berkas individual `bukti/regresi/pk-fase1-probe-token.txt` sudah **10/0**, sama dengan auditor dan kontrol baseline. README implementator menyebut 37 suite; jumlah baris eksekusi sebenarnya 38. Bukti lama tidak ditimpa.
- **47 suite statis: 18 exit 0, 27 exit 1, dua exit 77.** Seluruhnya sama dengan hitungan handoff kode Claude kecuali dua suite penugasan yang bertambah dua assertion lint probe auditor: Fase 1 **78/2 → 80/2**, Fase 2 **56/0 → 58/0**. Perbedaan baseline Fase 1 lainnya cocok penjelasan handoff (batas modul/pola pemanggilan serta lingkungan klon tanpa repo mobile/vendor biasa).

| Regresi | Hasil independen |
| --- | --- |
| Penugasan Fase 1 integrasi/probe audit/probe token/HTTP | 70/0 · 20/0 · 10/0 · 34/0 |
| Fondasi penugasan; V2 Fase 1/2/4/5 integrasi; konkurensi | 161/0; 39/0 · 94/0 · 124/0 · 145/0; 20/0 |
| V2 API Fase 3/4/5; web Fase 2/4/5 | 95/21 · 91/1 · 150/0; 36/0 · 46/0 · 79/0 |
| V3 integrasi Fase 2/3/4/5 dan akses | 67/0 · 121/0 · 65/0 · 43/0; 5/0 |
| V7 Fase 1 integrasi/HTTP; Fase 2 integrasi/HTTP/probe | 73/2 · 71/0; 119/0 · 61/0 · 34/0 |
| V7 Fase 3 integrasi/notifikasi/HTTP; Fase 4 HTTP | 112/0 · 49/0 · 43/0; 54/0 |
| V7 Fase 5 final E2E/HTTP/cakupan/pencabutan | 82/1 · 40/0 · 6/0 · 24/0 |
| HTTP redesain Tahap 2/4/5/7 | 185/6 · 132/0 · 106/0 · 122/0 |

Fase 1 dan sebagian V7 Fase 5 pada rangkaian besar selesai sebelum koreksi auditor. Fase 1 tidak disentuh koreksi; V7 Fase 5 E2E/HTTP/cakupan/pencabutan diulang pada sumber final dengan empat salinan fixture yang dibangun baru dan belum berisi pengajuan wali (`regresi-final-v7/`). V2/V3 serta regresi HTTP redesain pada rangkaian besar berjalan sesudah koreksi.

Kegagalan yang telah diketahui dan harus tetap dibaca sebagai batas:

- V7 Fase 5 E2E **82/1**: preflight historis menolak migrasi 032 sesudah 031. Pembaruan alat rilis workstream ini termasuk Fase 5, bukan audit Fase 2.
- V7 Fase 1 integrasi **73/2**, V2 API Fase 3 **95/21** dan Fase 4 **91/1**, serta HTTP redesain Tahap 2 **185/6** mempunyai hitungan/exit yang sama dengan kontrol handoff. Bukan kegagalan pemisahan tugas baru.
- Statis historis yang membatasi perubahan modul, daftar berkas, pola pemanggilan, atau mengasumsikan versi paket mobile lama tetap merah. Dua suite V3 berstatus 77 (dilewati). Rincian perbedaan hitungan terdapat pada perbandingan, bukan dihapus dari log.

## Penilaian rancangan dan penafsiran

- Routing V2/V7 membuang guru terlibat sebelum menghitung kandidat; nol atau lebih dari satu ke admin, satu langsung diarahkan. Penetapan admin tidak menawarkan/menerima guru terlibat. Keputusan/koreksi langsung maupun replay diperiksa server.
- Semua peninjau V7, termasuk yang meminta perbaikan, dihitung. Admin pengaju boleh menetapkan murobi lain atau membatalkan tetapi tidak memutus/mengoreksi pengajuannya sendiri (PK-T6).
- Sikap hanya dapat diketahui murobi sah bukan pengisi; seluruh murobi pengisi berarti tertahan tanpa jalur pengganti (PK-T7). Tanda lama milik pengisi tidak dihitung sah pada matriks, tanpa menghapus barisnya.
- PK-T1/PK-T5 berlaku per bahan nilai dan kapasitas Pendidikan; admin tetap jalur pengganti. Penanda `diisi_sendiri` berada di luar sidik jari matriks. Pengisi pertama serta riwayat tetap dihitung, bukan hanya aktor terbaru.
- PF2-1…PF2-5 dan PF2-7…PF2-9 diterima dengan batas yang tercatat: dua akun orang yang sama tidak dideduplikasi, seluruh riwayat pengisi dihitung, verifikasi lama tidak dibatalkan secara retroaktif, catatan routing dibatasi 255 karakter. PF2-6 disetujui Human Developer melalui PK-T8 dan diuji setelah F-1/F-2 dikoreksi.
- Tidak menambah jenis/capability/menu Keamanan atau endpoint baca baru; itu tetap Fase 3/4. Tidak ada migrasi, perubahan `app/Auth`, katalog penugasan, akun/master, kesehatan, BSA, notifikasi, rute API, atau kode mobile dalam koreksi audit.

## Reproduksi dan diagnostik

- Jalur dasar: `PKF2_DB=test_nama_test bash tests/penugasan_keamanan_f2_db.sh`, lalu integrasi pada DB tersebut. Semua builder harus berurutan.
- Jalankan probe riwayat/replay pada DB sesudah integrasi: `PKF2_DB=test_nama_test PKF2_BASELINE=/jalur/arsip-464d9a1 php tests/penugasan_keamanan_f2_audit_probe.php`. Arsip baseline memerlukan `.env`/`vendor` lokal; tidak di-commit. `PKF2_KODE=/jalur/arsip-610b6b5` memilih kontrol kode Claude dengan probe yang sama.
- Probe V4: siapkan DB baru, jalankan integrasi, lalu `PKF2_DB=test_nama_test php tests/penugasan_keamanan_f2_audit_v4.php`. Jangan menggunakan ulang DB yang telah menerbitkan rapor.
- Penyiapan pertama dalam sandbox berhenti pada `/dev/fd`; diulang melalui eksekusi lokal yang disetujui. Tidak mengubah skrip fixture untuk melewati pembatasan.
- Probe awal 12 dan 20 assertion disimpan sebagai `probe-awal*.txt`/`probe-akhir.txt`; hasil final sebanding adalah 24 assertion pada dua sumber. Satu pengulangan menabrak event unik karena memakai kembali sumber V3 yang sudah diketahui; fixture diperbaiki menjadi sumber baru setiap putaran, tanpa mengubah assertion.
- Dua percobaan awal probe V4 berhenti karena penyiapan uji: pemeriksa juga pengisi nilai (server benar menolak), lalu satu mapel wajib belum dilengkapi (server benar menahan finalisasi). Keduanya dipertahankan dalam `diagnostik-v4-*.txt`; perbaikan hanya pada fixture/probe.
- Percobaan pertama regresi V7 final memakai snapshot yang sudah diuji HTTP: E2E **80/3**, HTTP **39/1**, cakupan **6/0**, pencabutan **24/0**. Tambahan kegagalan berasal dari prasyarat "belum ada pengajuan wali" dan hitungan absolut satu pengajuan. Log dipertahankan di `diagnostik-regresi-v7-fixture-terpakai/`; diulang pada fixture baru dengan suite/oracle sama, bukan menghapus assertion.

## Batas dan handoff

- Bukti hanya lokal MariaDB 12.3.2 dan fixture sintetis. **MariaDB 10.6.28 produksi, MySQL/cPanel, UAT, native mobile/perangkat fisik dan Push nyata belum diuji.** Tidak ada migrasi Fase 2; migrasi 032 hanya dijalankan oleh builder fixture lokal.
- Tidak ada uji balapan baru untuk perubahan `users.guru_id` bersamaan dengan routing; batas implementator dipertahankan. Larangan keputusan akun pengaju/peninjau tetap berdasarkan ID akun. Dua akun terpisah orang yang sama tidak dikenali; penggabungannya memerlukan keputusan tersendiri.
- Data historis tidak ditulis ulang: keputusan/tanda mengetahui lama tetap tercatat; penolakan replay tidak membatalkan sejarah. Status verifikasi nilai yang sudah tersimpan sebelum Fase 2 tidak dievaluasi ulang otomatis (PF2-8).
- Gerbang produksi V5/V6/V7 dan risiko retry V2 lama tetap mengikuti audit masing-masing. Lulus lokal Fase 2 tidak menutup gerbang tersebut atau mengizinkan rilis parsial.
- Commit/push hasil audit hanya pada branch Fase 2 yang sama, lalu berhenti. Fase 3, merge, deploy, migrasi produksi, dan rilis tetap memerlukan instruksi Human Developer berikutnya.
