# Audit Codex — Profil pengguna aplikasi Fase 2

**LULUS teknis Fase 2 setelah koreksi.** Audit 4 November 2026 pada kedua branch `claude/profil-impor-fase-2`. Mobile `b887d7b` di atas `b9a4e10`; web `c5d2fc8` di atas `18539cd`, yang memuat koreksi kontrak Fase 1 `c4647be`. Satu commit implementasi per repo. Perubahan paket mobile yang sudah ada di salinan kerja dikecualikan. Pengujian memakai salinan commit tanpa `.env`, lock ter-commit, localhost, dan data sintetis MariaDB 12.3.2. Branch web hanya memuat uji/dokumen.

## Koreksi auditor

| Temuan | Koreksi dan verifikasi |
| --- | --- |
| Pemilih foto dibuka di luar penanganan galat. Kegagalan pemilih dapat menghasilkan penolakan promise tanpa pesan dan pembatalan melewati pembersihan cache. | Kunci aksi selama pemilihan hingga unggah, tampilkan galat pada panel, bersihkan salinan pemilih lewat `finally`. Android Debug membuktikan unggah dan cache kosong; iOS Release membuktikan batal lalu buka kembali. |
| Setelah unduhan foto gagal, memuat ulang profil dengan versi foto sama tidak mencoba mengunduh lagi. | Penyegaran profil yang berhasil mencoba lagi jika foto belum tersedia. Foto yang sudah dimuat digunakan kembali selama versi/pemiliknya sesuai. Chromium memutus GET foto, memeriksa inisial, memulihkan jaringan, dan membuka Profil tanpa memulai sesi baru. |
| Uji kartu menunggu label yang bisa muncul di luar kartu sebelum profil pribadi tersedia. | Tunggu nama dan label di dalam kartu, serta tunggu respons 404 dan hilangnya tindakan password untuk server lama. Putaran antara mencatat dua kegagalan pembacaan kartu wali sebelum data tiba dan satu pembacaan tindakan saat 404 masih diproses. Putaran akhir lulus 105/0. Probe tambahan juga memastikan parameter ID milik akun lain tidak mengubah pemilik respons. |

PF2-1…PF2-8 diterima sebagai penafsiran antarmuka. Status dan target mengikuti API, dan pasangan mapel–kelas hanya tampil di Profil. Server lama memakai fallback peran. Perubahan profil memakai versi optimistis dan menyisakan data pribadi yang belum diubah. Alur reset sementara mengikuti PI-1 dan pencabutan sesi mengikuti PI-2. Pemotong foto dan hapus foto merupakan tindakan untuk foto akun sendiri. T-1…T-3 diverifikasi melalui unggah native, pesan hasil pada panel foto, dan pemeriksaan cache Android.

## Bukti independen

| Uji | Hasil dan cakupan |
| --- | --- |
| TypeScript, Expo lint, unit mobile | Exit 0, exit 0, 64/0. |
| Expo web Chromium 375 px | 105/0. Admin, Guru/Murobi, Pengurus/Pembimbing, Orang Tua, akun rangkap, Guru Mapel. Kartu pertama, nama/inisial, seluruh status, target Kamar/Kelas, tujuh pasangan mapel–kelas, pencabutan tugas, semua isian pribadi, konflik versi/email, foto, privasi, password biasa/sementara, offline, server lama, dan sesi kedaluwarsa. |
| Kontrak Fase 1 via HTTP | 77/0, termasuk akses foto lintas akun, mutasi profil diri, password sementara, pencabutan token aplikasi dan sesi web. |
| Statis Fase 1 | 70/1, oracle historis batas berkas Fase 1 melihat tambahan Fase 2; layanan penugasan dan Capabilities lulus. |
| Android emulator API 36, APK Debug dev client handoff + Metro koreksi audit | 25/0, teks 1×/2× dengan nama panjang/empat status dan Kamar/Kelas, edit, pemilih sistem, unggah, cache kosong, izin paket, password biasa dan sementara. |
| iPhone 17 Pro simulator iOS 26.2, Release arm64 utuh | Artefak handoff diverifikasi dan dijalankan ulang. Build baru auditor berhasil, dua cold start, login, Beranda/Profil wali, Dynamic Type besar, PHPicker tanpa dialog akses seluruh galeri, batal/buka lagi, unggah/cache kosong, dan edit tempat lahir. F-1 historis `RCTEventEmitter.receiveEvent` tidak muncul pada kedua artefak Release profil ini. |
| Regresi aplikasi via Expo web | Pengajian kelas gabungan 57/0, Keamanan 46/0, Kesehatan 36/0, Keuangan 20/0, Perizinan V7 48/0, probe Pembinaan 5/0 (pintu, daftar/API, pemilih santri). |

Log HTTP dan regresi tersedia di [bukti-audit](bukti-audit/). Log Android, iOS, TypeScript/lint/unit dan detail build berada di direktori sepadan repo alhasanApps.

## Batas

Regresi dijalankan dengan fixture yang sesuai. Percobaan awal memakai fixture bersama sehingga seed Pengajian bentrok dan Perizinan tidak berawal dari DB bersih. Keduanya lulus setelah dipisahkan. Suite historis Pembinaan yang menguji mutasi penuh memerlukan katalog lebih dari 25 entri dan tanggal September 2026, dan percobaan pada fixture kini berhenti pada prasyarat katalog dan penyimpanan bertanggal lama. Bukti regresi Pembinaan pada audit ini terbatas pada probe navigasi, respons daftar, dan formulir bagi pembimbing serta wali.

Pengujian startup menutup reproduksi F-1 untuk dua build Release simulator yang diperiksa pada branch profil ini. iOS Debug/linker historis, Android Release, perangkat fisik (iPhone terdaftar offline, adb hanya emulator), distribusi AAB/IPA/TestFlight, Push nyata, dan matriks semua peran di setiap perangkat native belum diuji. MariaDB 10.6/MySQL/cPanel, pemasangan 035 produksi, smoke produksi, pemulihan produksi dan UAT masih merupakan gerbang rilis. Bilah tab Expo web masih memiliki overflow baseline pada beberapa akun, sementara isi kartu diuji terpisah.
