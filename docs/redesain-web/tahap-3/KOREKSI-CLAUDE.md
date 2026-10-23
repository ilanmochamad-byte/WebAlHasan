# Koreksi Claude atas audit Codex Tahap 3 — 23 Oktober 2026

Status: **F-1 dan F-2 dikoreksi; menunggu audit ulang Codex. Tahap 3 belum lulus.** Tahap 4 belum dimulai dan tetap memerlukan instruksi Human Developer setelah audit lulus. Belum merge, deploy, atau migrasi produksi.

- Branch `claude/redesain-web-tahap-3`, di atas commit audit Codex **`e0f86d2`** (implementasi `e626313`). Tanpa worktree.
- Cakupan sesuai handoff: **hanya F-1 dan F-2**. Catatan nonblokir audit (label chip Semua, definisi hitungan K-8, folder gambar berita) tidak disentuh.
- Probe auditor `tests/redesain_web_tahap3_audit.php` **tidak diubah sama sekali**. Sebelum koreksi **9/4** (sama dengan bukti audit), sesudah koreksi **13/0**.

## F-1 · kanal Ditangguhkan masih dapat diproses melalui worker/retry

| Berkas | Koreksi |
| --- | --- |
| `app/Notification/NotificationDispatcher.php` | Parameter konstruktor baru `array $ditangguhkan = NotificationChannel::DITANGGUHKAN` (bawaan produksi, tidak diubah `bootstrap.php`). Putaran non-dry-run untuk kanal ditangguhkan berhenti **sebelum** membaca sakelar, klaim, sewa, maupun koneksi penyedia, sehingga cron dan panel tidak mengirim WhatsApp walaupun sakelar lama `whatsapp_enabled=1` dengan pemeriksaan Lulus. Uji coba (dry-run) tetap: tidak mengklaim, tidak mengirim. |
| `app/Notification/NotificationAdminService.php` | `jalankanWorker()` menolak 409 untuk kanal ditangguhkan bila bukan uji coba; `cobaUlang()` menolak 409 sebelum `requeue`, baris tetap Failed dengan percobaan yang sama. Berlaku untuk panel dan API (facade REST memanggil layanan ini). Mematikan kanal dan pemeriksaan konfigurasi tidak berubah. |
| `app/Notification/NotificationChannel.php` | Komentar kebijakan memuat jalur pengiriman yang kini ikut ditolak. |
| `admin/admin_notifikasi.php` | Baris kanal ditangguhkan di Pengiriman gagal tidak lagi menawarkan kotak pilih maupun "Coba ulang" (lencana Ditangguhkan); formulir tersembunyi per barisnya tidak dirender. |

Tidak ada migrasi dan antrean lama tidak dihapus. Batas yang tetap ada: `NotificationService` masih mengantrekan baris WhatsApp baru bila sakelar lama menyala (baris itu tidak pernah dikirim dan dibatalkan saat admin mematikan kanal). Perubahan pengantrean tidak diminta F-1 dan sengaja tidak dilakukan.

## F-2 · alasan batal lunas hanya diwajibkan oleh JavaScript

| Berkas | Koreksi |
| --- | --- |
| `admin/admin_pembayaran_psb.php` | Alasan dipangkas lalu divalidasi **sebelum** UPDATE: kosong, hanya spasi, atau lebih dari 500 karakter ditolak dengan pesan "Status lunas tidak berubah"; status, waktu lunas, dan audit tidak tersentuh. Pengalihan membuka panel `?batal=1` yang berisi formulir alasan biasa (textarea wajib, tanpa JavaScript). Tautan `<noscript>` "Batal lunas" langsung ke formulir itu; dialog ⋯ ber-JavaScript tetap. Alasan sah selalu tersimpan di `audit_logs` (`psb.pembayaran.batal_lunas`). |

## Pengujian yang disesuaikan (terbuka)

| Uji | Perubahan | Alasan |
| --- | --- | --- |
| `tests/get_mutasi_v1_http.php` | Permintaan batal lunas sah kini menyertakan `alasan`; ditambah dua asersi bahwa batal tanpa alasan dan beralasan spasi **ditolak tanpa mengubah** status/waktu lunas. Metode, CSRF, batas target, injeksi, dan preservasi tabel tidak diubah. | Diminta audit F-2 (`:246` memperlakukan batal tanpa alasan sebagai sah). |
| `tests/v2_phase4_integration.php` | Ditambah **KN-7al**: dispatcher bawaan dengan sakelar WhatsApp menyala dan antrean berisi → tidak dijalankan, nol panggilan penyedia, antrean utuh. **KN-7al2**: `cobaUlang()` WhatsApp → 409, baris tetap Failed. Uji mekanisme adapter (kirim, gagal, backoff, dedup) memakai dispatcher yang **secara eksplisit** memberi daftar ditangguhkan kosong; "baris yang sama kembali ke antrean" (KN-7p–r) diuji pada `NotificationRepository::requeue()` yang dipakai layanan. | Kebijakan AL kini menolak jalur yang dulu diuji sebagai sukses; mekanismenya tetap diuji, kebijakannya diuji tersendiri. |
| `tests/v2_phase4_concurrency_worker.php` | Worker uji klaim atomik membuat dispatcher yang sama dengan bootstrap, tetapi dengan daftar ditangguhkan kosong secara eksplisit. Dengan worker lama suite gagal **3** (0 terkirim) — bukti bahwa dispatcher bawaan kini menahan WhatsApp (`koreksi/v2-fase4-konkurensi-worker-lama.txt`). | Uji ini memeriksa klaim ganda, bukan kebijakan kanal. |
| `tests/redesain_web_tahap3_http.php` | Pesan JSON coba ulang WhatsApp kini "ditangguhkan"; ditambah asersi F-1 (dispatcher/cron, uji coba, worker 409, retry 409 dengan status/percobaan tetap, regresi worker Push, baris panel tanpa Coba ulang, pemulihan fixture) dan F-2 (tanpa alasan, spasi, > 500 karakter; formulir tanpa JavaScript). | Bukti implementator. |
| `tests/redesain_web_tahap3_static.php` | Ditambah pemeriksaan letak guard dispatcher sebelum klaim, guard worker/retry di layanan, dispatcher produksi tidak mengosongkan daftar, panel tanpa Coba ulang, validasi alasan sebelum UPDATE, dan formulir tanpa JavaScript. | Bukti implementator. |

## Bukti (eksekusi implementator, `koreksi/`)

| Pengujian | Hasil | Berkas |
| --- | --- | --- |
| Probe auditor, tidak diubah (`DB_NAME=test_redesain_t3_test`, server 8951) | sebelum **9/4**, sesudah **13/0** | `probe-auditor-sebelum.txt`, `probe-auditor-sesudah.txt` |
| `php tests/redesain_web_tahap3_static.php` | **152/0** | `uji-statis.txt` |
| `php tests/redesain_web_tahap3_http.php` | **167/0** | `uji-server.txt` |
| Browser Tahap 3, 375/768/1280 | **76/0** | `uji-browser.txt` |
| V2 Fase 4: integrasi, konkurensi, kontrak API, web | **124/0**, **20/0**, **92/0**, **46/0** | `v2-fase4-*.txt` |
| V2 Fase 5: integrasi, web | **143/0**, **79/0** (peringatan *Deprecated* `str_getcsv` berasal dari berkas uji itu sendiri di PHP 8.4, bukan kode yang berubah) | `v2-fase5-*.txt` |
| V3 Fase 4 kanal (`webalhasan_v3_phase1_test`) | **9/0** | `v3-f4-kanal.txt` |
| Perbaikan mutasi V1 dari salinan sumber di luar Git (DB salinan Tahap 3 + fixture cleanup + backfill V2): HTTP, probe audit Codex, browser; cleanup HTTP/preservasi | **91/0**, **84/0**, **35/0**; **156/0** | `gm-*.txt`, `cl.txt` |
| Tahap 2 HTTP; browser Tahap 1 seluruh menu sembilan akun × tiga lebar | **191/0**; **91/0**, tanpa halaman "belum memakai Layout" | `t2h.txt`, `t1.txt` |
| 23 suite statis | 21 exit 0, 2 exit 77 (gate DB V3 Fase 2/3, seperti baseline) | `statis-semua.txt` |
| `php -l` 458 berkas terlacak, `node --check alhasan.js`, `git diff --check` | 0 gagal, lulus, bersih | — |

## Batas

- Probe WhatsApp memakai `FakeProvider`/penyedia tiruan; tidak ada pengiriman nyata atau koneksi penyedia eksternal. Cron dibuktikan lewat dispatcher yang sama dengan bawaan produksi, bukan dengan menjalankan crontab cPanel.
- Hanya Chromium; belum Safari/iOS, Firefox, perangkat fisik, pembaca layar, atau axe. Tidak ada smoke cPanel/produksi.

## Handoff ke auditor (Codex)

Mohon audit ulang F-1 dan F-2 pada branch ini. Jalankan dari root:

```bash
bash tests/redesain_web_tahap3_db.sh
DB_NAME=test_redesain_t3_test php -S 127.0.0.1:8951 tests/redesain_web_tahap1_router.php &
DB_NAME=test_redesain_t3_test php tests/redesain_web_tahap3_audit.php
php tests/redesain_web_tahap3_static.php
php tests/redesain_web_tahap3_http.php
V2_PHASE4_RUN_INTEGRATION=1 php tests/v2_phase4_integration.php
V2_PHASE4_RUN_CONCURRENCY=1 php tests/v2_phase4_concurrency.php
```

**Tahap 3 belum lulus sampai audit ulang Codex menyatakannya. Tahap 4 belum dimulai.**
