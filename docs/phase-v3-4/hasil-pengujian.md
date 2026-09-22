# Hasil pengujian Fase 4

Semua pengujian database di bawah memakai **MariaDB lokal `webalhasan_v3_phase1_test`** dan akun sandbox. Tidak ada migrasi, pengiriman provider, atau pembersihan data produksi. Status `LULUS OTOMATIS` hanya berarti pemeriksaan yang benar-benar dijalankan di lingkungan uji tersebut.

| Pemeriksaan | Hasil | Bukti dan batas |
| --- | --- | --- |
| `bin/v3_phase4_run_tests.sh` | **LULUS OTOMATIS** | 111 pemeriksaan statis, integrasi, konkurensi, kanal, migrasi, dan verifier. Termasuk penolakan Rahasia pada kasus/sesi/pelanggaran tertaut, transaksi audit/outbox, satu dampak per wali, IDOR, cabut relasi, payload generik, dan credential sentinel yang tidak muncul di outbox/audit publikasi. |
| Browser/API Playwright `tests/browser/uji-v3-fase4.mjs` | **LULUS OTOMATIS** | 34 pemeriksaan pada Chromium lokal: token, CSRF, Rahasia, revisi, pratinjau, konfirmasi, retry, XSS, akses orang tua, login deep-link web, 1440/375 px, pembacaan, penarikan, dan GET tanpa mutasi. |
| `bin/v3_phase1_run_tests.sh` | **LULUS OTOMATIS** | 71 pemeriksaan. |
| `bin/v3_phase2_run_tests.sh` | **LULUS OTOMATIS** | 172 pemeriksaan; aturan payload aman V3 ditambahkan tanpa melonggarkan bentuk lama. |
| Fase 3: static, integration, verifier | **LULUS OTOMATIS** | Masing-masing 103, 121, dan 34 pemeriksaan. Drill migrasi Fase 3 yang menganggap 017 selalu migrasi terakhir tidak digunakan sesudah 018; drill 018 memverifikasi data lama. |
| `bin/penugasan_run_all_tests.sh` | **LULUS OTOMATIS** | Satu run lengkap V1/V2, perapihan, kredensial, penempatan, alumni, dan fondasi penugasan berakhir exit 0. Ada satu run sebelumnya yang gagal pada uji konkurensi V2; uji itu lulus saat diulang sendiri dan pada run lengkap berikutnya. |
| Mobile `npx tsc --noEmit` dan ESLint untuk berkas yang berubah | **LULUS OTOMATIS** | Memeriksa tipe dan aturan lint; tidak membuktikan push/deep-link perangkat nyata. |
| Push OFF dan WhatsApp OFF | **LULUS OTOMATIS** | Fake provider mencatat **nol request** untuk masing-masing kanal ketika OFF; retry dan receipt dicoba dengan fake provider setelah Push dinyalakan hanya pada fixture lokal. Adapter ini bukan bukti pengiriman fisik. |
| Rollback dan migrasi ulang 018 | **LULUS OTOMATIS** | Drill membandingkan hash seluruh baris tabel bisnis sebelum/sesudah rollback non-destruktif, lalu migrasi ulang dua kali. |
| Push dan deep-link Android+iOS fisik | **BELUM TERPENUHI — MENUNGGU UJI FISIK** | Belum ada persetujuan menyalakan Push fisik/produksi, perangkat uji, atau receipt provider nyata. Panduan terpisah siap dijalankan di staging sesudah persetujuan. |
| Migrasi/smoke cPanel dan smoke produksi Fase 3 §6 | **BELUM DIJALANKAN** | Perlu jadwal rilis/persetujuan tersendiri. Browser lokal menutup sebagian risiko penolakan orang tua terhadap halaman/API internal, bukan bukti produksi. |

Pengujian utama sengaja memakai database uji yang sudah ada. Rangkaian regresi lama membuat fixture sementara; verifier 018 memeriksa tabel Fase 4 secara terpisah. Tidak ada klaim bahwa catatan smoke produksi Fase 3 atau pembersihan datanya telah dikerjakan.

Verifier umum `bin/v3_verify.php` melaporkan tiga jenis referensi yatim pada tabel warisan database uji: 24 `notifikasi_outbox.penerima_user_id`, 24 `notifikasi_outbox.pengajuan_id`, dan 6 `audit_logs.actor_user_id`. Ini berasal dari fixture regresi lama dan tidak dibuat migrasi 018. Struktur V3 pada keluaran itu lulus; temuan warisan **belum dibersihkan** agar tidak menghapus catatan tanpa prosedur terverifikasi. Verifier khusus 018 lulus.
