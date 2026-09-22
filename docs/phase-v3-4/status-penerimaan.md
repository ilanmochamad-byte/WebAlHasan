# Status kriteria penerimaan PRD V3 Fase 4

Penanda: **LULUS OTOMATIS** = diuji lokal; **LULUS UJI FISIK** = diuji pada perangkat/provider nyata (belum ada); **BELUM DIJALANKAN** = jalur uji belum dieksekusi; **BELUM TERPENUHI** = kriteria wajib belum memiliki bukti cukup. Status fase secara keseluruhan **BELUM TERPENUHI** sampai uji push Android dan iOS fisik lulus dan auditor menilai hasilnya.

| Kriteria PRD | Status | Bukti atau kekurangan |
| --- | --- | --- |
| Hanya relasi `santri_wali` aktif | **LULUS OTOMATIS** | Query daftar/detail/baca membatasi wali dan relasi aktif; uji cabut/pindah relasi serta IDOR. |
| Respons tanpa isi internal dan ID petugas | **LULUS OTOMATIS** | Allowlist serializer, uji API/browser dan inspeksi audit/payload. |
| Catatan belum terbit nol baris dan notifikasi | **LULUS OTOMATIS** | Uji kasus dan sesi belum terbit, termasuk Rahasia. |
| Satu publikasi per penerima; retry tanpa duplikasi | **LULUS OTOMATIS** | Uji dua wali, klik ganda, request paralel, fingerprint/idempotensi. |
| Push fisik Android+iOS generik dan detail benar setelah login/otorisasi | **BELUM TERPENUHI — MENUNGGU UJI FISIK** | Deep-link web diuji di browser dan kode mobile lulus tipe/lint; belum ada pengiriman/ketukan fisik dan receipt provider nyata. |
| Wali A tidak membaca ID wali B | **LULUS OTOMATIS** | API dan browser mengembalikan 403 aman. |
| Penarikan wajib alasan, audit, tanpa isi internal | **LULUS OTOMATIS** | Uji versi, riwayat, audit, redaksi detail orang tua. |
| Push OFF = nol request provider | **LULUS OTOMATIS** | Fake provider menghitung nol request; sakelar produksi tidak diubah. |
| WhatsApp OFF = nol request provider | **LULUS OTOMATIS** | Fake provider menghitung nol request; WhatsApp tetap OFF sepanjang V3. |
| Log/audit/payload tanpa rahasia dan credential | **LULUS OTOMATIS** | Uji sentinel rahasia dan credential pada keluaran lokal serta payload generik. Pemeriksaan log produksi **BELUM DIJALANKAN**. |

Persyaratan tambahan dari keputusan produk juga diuji otomatis: Rahasia ditolak pada pratinjau/terbit/koreksi meski teks manual, termasuk pelanggaran yang tertaut melalui keluarga revisinya; revisi beralasan ke Internal memungkinkan terbit; perubahan balik ke Rahasia dan penautan pelanggaran terbit ke Rahasia ditolak selama ada publikasi aktif, termasuk request bersamaan untuk kasus/sesi; audit/outbox gagal menggulung transaksi; koreksi mempertahankan versi dan sejarah; migrasi 018 aman terhadap data bisnis lama. Tidak ada jalur Fase 5 yang dibuat.

Risiko terbuka: uji fisik kedua platform dan receipt provider, konfigurasi/staging cPanel, smoke produksi Fase 3 §6 yang tertunda, pembersihan data smoke produksi oleh prosedur operator tersendiri, dan temuan referensi yatim fixture warisan dalam verifier umum database uji. Daftar langkah dan batas otorisasi berada di [panduan uji fisik dan cPanel](panduan-uji-fisik-dan-cpanel.md); rincian verifier ada di [hasil pengujian](hasil-pengujian.md).
