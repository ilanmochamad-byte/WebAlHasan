# Kontrak aplikasi Fase 5

Tanggal 17 Oktober 2026 (Asia/Jakarta). Tidak menambah role/mode login, rumus, status bisnis, migrasi, atau keputusan [JANGAN DIUBAH]. Pengaturan rencana/penanda tangan tetap menggunakan web Fase 1–4. Aplikasi menambahkan paritas tindakan yang diminta dan pratinjau/penerbitan ber-gate server.

## Endpoint dan navigasi

- GET `/api/v1/v4/capabilities` → `{list: string[]}`; resolver server membaca ulang penugasan dan relasi. Ini petunjuk menu, bukan otorisasi. Daftar V4 terpisah dari mode perizinan lama agar V1–V3 kompatibel.
- Guru: GET `nilai/options`, GET `nilai?rencana_mapel_id&santri_id`, POST `nilai`. Seluruh komponen tersimpan disertakan bersama `version`; komponen baru versi 0. Draf boleh parsial, kosong tidak menjadi nol. Perubahan tersimpan melalui koreksi beralasan, lalu serahkan kembali.
- Pendidikan pengganti: endpoint yang sama memakai `pengganti=1` saat baca dan POST `nilai/pengganti`; alasan wajib. Server mencatat kapasitas aktual, komponen yang tidak berubah tetap milik pengisi semula.
- Pembimbing/murobi: GET `sikap/options?mode=...`, GET `sikap?rencana_id&santri_id&mode=...`, POST `sikap` atau `sikap/murobi`. Mengetahui tidak mengubah isi; koreksi memerlukan alasan/versi dan mempertahankan riwayat.
- Pendidikan: GET `verifikasi/options`, GET `verifikasi?rencana_id&santri_id`, POST `verifikasi` membawa jenis, mapel_key, version dan sumber_hash. Kehadiran diserahkan sebelum diverifikasi; pengembalian beralasan. Kekurangan dan seluruh lima hitungan terlihat.
- Pratinjau memakai POST `rapor/pratinjau` agar catatan tidak di URL. Terbit/Ganti/Tarik/Buka membawa current_version, revision, hash pratinjau, alasan dan idempotency_key stabil untuk retry payload sama. Gate server Fase 4 tetap wajib.
- Wali: GET `rapor`, `rapor/{id}`, `rapor/{id}/pdf/{version}`. Pemeriksaan relasi aktif pada setiap daftar, detail, fokus kembali, dan unduhan. Versi tidak sah hanya metadata, tidak ditawarkan PDF baru.

## Notifikasi, sesi dan berkas

Kontrak Fase 4 yang dipakai adalah `{"tipe":"rapor","rapor_id":ID}` dengan judul/isi “Rapor semester tersedia”. ID hanya penunjuk. Listener foreground/background/cold-start menyimpan tujuan sementara dalam memori sampai login dan detail berhasil. Skema `alhasanapps://rapor/ID` juga mempertahankan tujuan melewati login. Target selain ID aman/jenis yang diizinkan diabaikan; URL/isi push tidak memberi izin. Akun yang keliru tetap mendapat 403 dari server. Token 401 dibersihkan melalui handler sesi yang ada.

Data server/isian dibuang saat blur/background; request lama tidak dapat mengisi layar baru berkat generation guard. Mutasi tidak dicoba ulang otomatis; ketukan ganda dijaga ref lock. Setelah 409 (termasuk retry manual sesudah jawaban hilang), seluruh keadaan server dimuat ulang dan formulir lama dibongkar. Pengguna meninjau versi baru sebelum koreksi berikutnya. GET memakai timeout 20 detik dan maksimal dua retry transport sesuai client lama; POST/PDF tanpa retry otomatis.

PDF diunduh sebagai byte kanonis dengan bearer di header, bukan token URL atau render ulang. Native memakai File/Paths.cache dan dialog simpan/bagikan eksplisit; finally menghapus file setelah dialog selesai/gagal. Startup/logout membersihkan residu berawalan v4-rapor akibat crash. Web memakai Blob sementara dan mencabut object URL. Salinan yang sengaja disimpan pengguna berada di bawah kendali pengguna. Tidak ada cache akademik/analytics/clipboard otomatis. File sharing Android telah diuji; iOS runtime belum.

## Proyeksi API wali

`RaporService::parentView` memanggil penjaga `show` lama terlebih dahulu, lalu membuat allowlist tampilan. ID petugas pembimbing/murobi, sumber absensi, ID rencana/kelas/santri internal, detail hitung internal mapel, ID/hash/aset/data URI tanda tangan dibuang dari respons API wali. Identitas penanda, nilai tampil, sikap, catatan, hitungan hadir dan rata-rata tampilan tetap tersedia. Rata-rata internal lama dipertahankan untuk fallback snapshot yang belum memiliki `rata_rata_tampil`. Snapshot tersimpan, PDF kanonis, pembacaan web/petugas dan hash versi terdahulu tidak diubah. PDF lengkap tetap hanya melalui endpoint terotorisasi.

## Dokumentasi SDK yang dibaca sebelum perubahan

[Expo SDK v57](https://docs.expo.dev/versions/v57.0.0/), [FileSystem](https://docs.expo.dev/versions/v57.0.0/sdk/filesystem/), [Sharing](https://docs.expo.dev/versions/v57.0.0/sdk/sharing/), [Notifications](https://docs.expo.dev/versions/v57.0.0/sdk/notifications/), [Linking](https://docs.expo.dev/versions/v57.0.0/sdk/linking/). Dependensi SDK tetap dari lock baseline; tidak menambah paket.
