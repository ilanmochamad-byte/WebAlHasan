# Audit ulang Codex — koreksi Tahap 3

Tanggal: **23 Oktober 2026 (Asia/Jakarta)**. Putusan akhir: **LULUS**. **F-1 dan F-2 ditutup**, tanpa koreksi kode aplikasi oleh auditor. Tahap 4 belum dimulai dan tetap memerlukan instruksi Human Developer. Kelulusan ini bukan keputusan merge, deploy, migrasi produksi, atau aktivasi WhatsApp.

## Acuan dan pelaksanaan

- Branch `claude/redesain-web-tahap-3`, commit koreksi Claude **`549c7e6`**, sesudah audit pertama **`e0f86d2`** dan implementasi **`e626313`**. Tidak membuat worktree.
- Dibaca ulang: `AGENTS.md`, laporan audit pertama, `KOREKSI-CLAUDE.md`, status README/rencana, keputusan AL, dan ketentuan notifikasi PRD V2 §5.7/Fase 4. Seluruh diff koreksi kode dan oracle pengujian ditinjau.
- Semua angka di laporan ini merupakan eksekusi auditor. Bukti baru disimpan di [`audit-ulang-codex/`](audit-ulang-codex/); bukti audit pertama dan implementator tidak ditimpa.
- Urutan permintaan dijalankan dari root: persiapan DB → server 8951 → probe auditor asli → statis → HTTP → integrasi V2 Fase 4 → konkurensi V2 Fase 4. Setelah itu, regresi browser Tahap 3 dan probe tambahan untuk F-1/F-2 dijalankan.
- Probe/HTTP/browser memakai `test_redesain_t3_test` (HTTP juga salinan kosong). Dua suite V2 dijalankan dengan perintah yang diminta, memakai sandbox `.env` yang wajib berakhiran `_test`; keduanya membuat/membersihkan fixture serta memulihkan pengaturan sesuai skrip. Tidak ada DB produksi, dump pribadi, mobile, atau direktori pekerjaan lain yang disentuh.

## Hasil per temuan

| Temuan | Hasil akhir |
| --- | --- |
| **F-1: WhatsApp Ditangguhkan** | **Ditutup.** Worker layanan non-dry-run dan retry menolak **409** meskipun sakelar lama aktif/Lulus. Dispatcher bawaan menahan pengiriman sebelum membaca sakelar, mengambil sewa, mengklaim baris, atau menghubungi penyedia; CLI/cron memakai wiring itu. Seluruh keadaan outbox, percobaan, lock dan audit kanal tetap utuh saat penolakan. Dry-run, mematikan, serta pemeriksaan tetap tersedia. Baris WhatsApp di panel tidak menawarkan pilih/retry. |
| **F-2: alasan batal lunas** | **Ditutup.** Server menolak alasan kosong, hanya spasi dan >500 karakter **sebelum UPDATE**, dengan status/waktu lunas/audit tetap. Pengalihan membuka formulir alasan biasa. Dalam browser dengan JavaScript dinonaktifkan, tautan dapat dibuka, textarea dapat diisi dan validasi HTML menahan kiriman kosong pada ketiga lebar. Alasan sah tetap tersimpan di audit; tanpa CSRF tetap **419**. |

Probe asli `tests/redesain_web_tahap3_audit.php` dijalankan **tanpa perubahan**: **13 lulus/0 gagal**, dibandingkan **9/4** pada audit pertama. Riwayat kegagalan pertama tetap tersimpan.

Probe tambahan `tests/redesain_web_tahap3_audit_ulang.php` membuktikan perilaku facade REST (penerjemahan 409/403, bukan hanya metode layanan), preservasi seluruh baris tabel antrean terkait, dry-run, serta proses CLI sungguhan `bin/notifikasi_worker.php --kanal=whatsapp`. CLI memakai sakelar fixture saat ini; kasus sakelar **aktif/Lulus** diuji terpisah dalam transaksi dengan dispatcher bawaan dan adapter palsu. Ini bukan klaim crontab cPanel sudah diuji.

Probe positif juga memanggil retry **Push melalui facade/layanan**, menghasilkan Queued pada baris yang sama, tanpa duplikat, dengan audit kanal bertambah satu. Dengan demikian pemindahan uji mekanisme WhatsApp ke repositori tidak menjadi satu-satunya bukti retry kanal yang tetap aktif.

## Penilaian penyesuaian pengujian

- **Dispatcher dengan daftar ditangguhkan kosong pada uji mekanisme diterima.** Default konstruktor tetap `NotificationChannel::DITANGGUHKAN`; tidak ada input HTTP/environment yang mengosongkannya. Satu-satunya wiring aplikasi ada di bootstrap dan tetap memakai default. Uji kebijakan bawaan memeriksa nol send dan antrean utuh, sedangkan dispatcher yang secara eksplisit menerima `[]` dipakai di pengujian adapter dan worker konkurensi untuk tetap menguji pengiriman/backoff/deduplikasi. Kedua jenis bukti tidak disamakan.
- **Perubahan oracle batal lunas sah diterima.** `get_mutasi_v1_http.php` kini menyertakan alasan pada permintaan sah dan menambah penolakan alasan kosong/spasi. Persyaratan metode/CSRF serta batas target tidak dilonggarkan.
- **Koreksi oracle probe tambahan auditor dicatat terbuka.** Percobaan awal probe positif salah mengasumsikan `percobaan` direset menjadi 0 setelah retry. `NotificationRepository::requeue()` pada baseline `e0f86d2` memang mempertahankan penghitung tersebut. Asersi diperbaiki menjadi mempertahankan **5**, sambil tetap memeriksa baris yang sama, Queued, penghapusan penanda permanen, dan audit. Bukti awal **12/1** disimpan di `probe-orakel-awal.txt`; hasil final **13/0** ada di `probe-tambahan.txt`. Tidak ada perubahan kode aplikasi untuk membuat probe ini lulus.

## Pengujian independen

| Pengujian | Hasil | Bukti di `audit-ulang-codex/` |
| --- | --- | --- |
| `bash tests/redesain_web_tahap3_db.sh` | exit **0** | `db.txt` |
| `DB_NAME=test_redesain_t3_test php tests/redesain_web_tahap3_audit.php` | **13/0**, exit 0 | `probe-asli.txt` |
| `php tests/redesain_web_tahap3_static.php` | **152/0**, exit 0 | `static.txt` |
| `php tests/redesain_web_tahap3_http.php` | **167/0**, exit 0 | `http.txt` |
| `V2_PHASE4_RUN_INTEGRATION=1 php tests/v2_phase4_integration.php` | **124/0**, exit 0 | `integration.txt` |
| `V2_PHASE4_RUN_CONCURRENCY=1 php tests/v2_phase4_concurrency.php` | **20/0**, exit 0 | `concurrency.txt` |
| Browser Tahap 3, 20 layar × **375/768/1280 px** + interaksi | **76/0**, exit 0 | `browser-t3.txt` |
| Probe tambahan facade REST/CLI/preservasi/retry Push | **13/0**, exit 0 | `probe-tambahan.txt` |
| Browser F-2 dengan **JavaScript dimatikan**, tiga ukuran | **18/0**, exit 0 | `browser-nojs.txt`, `tangkapan/` |
| Lint seluruh PHP koreksi Claude dan probe tambahan auditor | **11 berkas, 0 gagal** | `lint.json` |
| `node --check` JS bersama dan probe browser; `git diff --check` | exit **0** | eksekusi auditor |

Tiga tangkapan formulir tanpa JS diperiksa langsung: isian, penjelasan akibat pembatalan, tombol Batal/Batalkan, dan navigasi dapat dibaca tanpa overflow halaman. Probe browser tidak mengirim pembatalan sah agar fixture tetap utuh; penyimpanan status dan alasan sah dibuktikan terpisah oleh probe PHP dan suite HTTP. Tidak ada pengiriman nyata: seluruh uji pengiriman memakai FakeProvider/klien tiruan.

## Keputusan cakupan dan batas yang tetap berlaku

- **Butir penafsiran 2 dan 8 diterima setelah koreksi:** penangguhan kini mencakup jalur kirim/cron/retry; alasan batal lunas diwajibkan server dan tersedia tanpa JavaScript. Butir lain serta catatan nonblokir pada audit pertama tetap berlaku.
- Sakelar WhatsApp lama yang masih aktif dapat membuat `NotificationService` menambahkan antrean baru; antrean itu **tidak dikirim** oleh dispatcher dan dapat dibatalkan ketika Admin mematikan kanal. Ini batas operasional yang dinyatakan Claude, bukan celah F-1 yang masih terbuka. Audit tidak meminta migrasi pengaturan atau penghapusan antrean.
- Label chip Semua yang mengecualikan Dimigrasi, definisi jumlah K-8 berbasis penempatan lama, serta folder hapus gambar berita tetap catatan baseline nonblokir; tidak dikoreksi dalam audit ulang F-1/F-2.
- Hanya Chromium lokal. Safari/iOS, Firefox, perangkat fisik, pembaca layar, axe, Summernote hidup, kinerja produksi, impor 1.000 baris, berkas mendekati batas, mobile dan smoke cPanel tidak diuji ulang. Tidak mengulang seluruh matriks baseline/seluruh regresi V3/V4 dari audit pertama. Tidak mengklaim push fisik atau WhatsApp nyata lulus.
- **Tahap 3 lulus audit ulang Codex atas keadaan akhir `549c7e6`. Berhenti untuk Human Developer; Tahap 4 belum dimulai.** Tidak merge, deploy, atau migrasi produksi.
