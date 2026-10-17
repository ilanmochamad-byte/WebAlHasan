# Hasil pengujian implementator — 17 Oktober 2026

Bukti lokal implementator, bukan putusan auditor/izin produksi. Web diuji dari salinan checkout `/private/tmp/v4p5-web`; MariaDB terpisah tanpa jaringan (`v4p5.sock`). Storage fixture dan path /private/tmp/v4-fase1–4 dialihkan hanya pada salinan ke v4p5-fase1–4, agar bukti Fase 4 asli tidak tersentuh. Aplikasi diuji dari salinan `/private/tmp/v4p5-mobile` tanpa dotenv produksi, memakai kode fitur identik dan node_modules baseline. HTTP/browser: Chromium Playwright; backend PHP 8.4.14/MariaDB 12.3.2; Apache 2.4.67 untuk probe .htaccess sintetis.

| Kelompok | Hasil aktual | Bukti |
| --- | --- | --- |
| V4 Fase 1 | 29 integrasi + 23 browser lulus | regresi-fase1*.txt |
| V4 Fase 2 | 54 integrasi + 2 konkurensi + 25 browser pengganti + 8 guru lulus | regresi-fase2*.txt |
| V4 Fase 3 | 73 integrasi + 4 konkurensi + 56 browser + 9 transaksi lulus | regresi-fase3*.txt |
| V4 Fase 4 | 45 integrasi, browser 92, konkurensi/pemulihan 22, siklus 16, mock Push 10, probe audit 83 lulus | regresi-fase4*.txt, regresi-probe-audit-fase4.txt |
| PDF/QR sintetis | Ekstraksi, render, dua gambar tanda tangan, kode terbaca, perubahan byte berbeda hash; browser memeriksa QR publik + unggahan asli/berubah | regresi-fase4-pdf.txt, regresi-fase4-http.txt; PDF/QR mentah tetap di temp privat |
| Regresi V1–V3 | 14 integrasi exit 0. Final 15/15 statis exit 0 setelah koreksi path dan validator | regresi-ringkasan.txt (awal); statis-final.txt dan regresi-*.txt (final) |
| Aplikasi Expo web + API nyata | Guru/pembimbing/murobi/Pendidikan; 409 tidak menimpa versi baru; nilai/sikap identik web; pengganti beralasan/kapasitas, verifikasi, gate pratinjau; akses silang; InApp generik; PDF app/web hash sama | app-browser-final.txt, app-*.png |
| Keadaan gagal | Daftar tanpa relasi menunjukkan tanpa akses; gangguan jaringan/retry dan 401 memakai respons/transport mock, token dibersihkan; validasi kosong/nol/rentang/versi/ID diuji unit | app-browser-final.txt, mobile-unit.txt |
| Privasi/proyeksi | Allowlist wali; nol gambar/data URI/ID petugas/sumber internal dalam JSON wali; snapshot/hash tidak diubah; audit/outbox/log tanpa isi sensitif/credential | proyeksi-wali.txt, privasi-log-payload.txt |
| Worker | Status dan dry-run OFF tanpa request; mock OFF menghitung nol panggilan Push/WhatsApp | worker-*.txt, regresi-fase4-push.txt |
| Restore/rollback | Restore DB + storage terpisah; baris V4 identik pada restore point latihan, PDF rusak 503 lalu byte backup identik; rollback berisi ditolak, skema kosong down/up/up lulus | restore-*.txt, rollback-*.txt, backup-hashes.txt |
| Mobile checks | TypeScript exit 0, lint penuh exit 0, 21 unit test lulus; bundel Hermes iOS + Android sukses dari kode akhir | mobile-lint*.txt, mobile-unit.txt, native-bundles.txt |
| Android runtime | Debug client dibangun ulang; emulator smoke guru/wali, deep-link meminta login, akses ditolak, detail/PDF hash kanonis, cache nol setelah dialog batal | android-emulator.txt, android-*.png |
| iOS/Push fisik/produksi | Runtime iOS tidak terverifikasi; perangkat fisik, Push/receipt nyata dan cPanel/cron produksi belum dilakukan | ios-batas.txt, risiko-terbuka.md, checklist-smoke.md |

Screenshot browser diambil 375 px dan 1440 px; suite web utama juga menguji teks 24 px. Pemeriksaan visual sampel guru/wali Android dan guru 375 px tidak menemukan clipping/overflow. Android emulator bukan representasi iOS/fontScale native besar. Mutasi penuh empat peran baru dibuktikan pada Expo web, belum pada semua native runtime.

Kegagalan awal dicatat pada risiko: file .env.example terlewat dalam sandbox, flag V3 menghasilkan 77, pointer mobile awal berbeda dari baseline (diselaraskan ke tipe=rapor), animasi pemilih di harness, client native lama dan cache API lama. Perbaikan terarah telah diuji ulang. Tidak menganggap warning/skipped sebagai lulus. Error audit yang sengaja diinjeksi hanya menulis pesan generik “Audit log gagal disimpan.”; body/nilai tidak dilog. Timeout request 20 detik tersedia di kode; jaringan native, putus nyata saat COMMIT dan seluruh variasi timeout fisik masih harus diuji.

`composer check-platform-reqs --no-dev` lulus dan audit lock tidak menemukan advisory pada waktu uji (composer-audit.txt). Tidak menambah paket atau migrasi. Detail perintah ada pada perintah-uji.md dan dua skrip regresi; secret/fixture token disimpan privat, bukan Git.
