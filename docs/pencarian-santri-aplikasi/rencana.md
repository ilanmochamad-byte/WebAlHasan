# Rencana satu fase — pencarian santri, pemisahan IBTIDA PA/PI, dan versi aplikasi

**Keputusan Human Developer, 5 November 2026:** rencana disetujui. Pekerjaan dilakukan dalam **satu fase** pada branch fitur; Claude Code mengimplementasikan, lalu Codex mengaudit secara independen. Dokumen ini adalah dasar kerja bersama dengan `AGENTS.md`. Implementasi, audit, merge, dan rilis belum dilakukan oleh penyusunan rencana ini.

## Kondisi yang ditemukan

- Pada `alhasanApps/src/app/meeting/[id].tsx`, pencarian peserta belum ada. Pengelompokan kelas hanya muncul ketika `daftarKelas(meeting.task).length > 1`. Komponen dan `kelas-jadwal.ts` tidak memiliki cabang Android/iOS untuk pengelompokan.
- REST pertemuan sudah mengirim `task.classes` dan kelas asal setiap peserta (`students[].class`) dari snapshot pertemuan. Satu kiriman absensi memuat seluruh `meeting.students` dan satu entri per `student_id`, terlepas dari urutan tampilan.
- Audit kelas gabungan sebelumnya membuktikan PA+PI terkelompok pada emulator Android dan simulator iOS, tetapi belum membuktikan kesamaan build yang dipakai pengguna pada perangkat fisik. Perbedaan yang dilaporkan Human Developer harus direproduksi pada akun, pertemuan, API, dan versi build yang sama sebelum akar penyebab dinyatakan.
- Pada 5 November 2026, `app.json` GitHub `main` aplikasi berisi `expo.version = 1.2.0`; branch lokal `claude/profil-impor-fase-2` masih `1.0.1`. `package.json`/`package-lock.json` lokal mempunyai perubahan dependensi belum di-commit milik pekerjaan lain. `eas.json` memakai `appVersionSource: remote` dan `autoIncrement: true`, yang mengurus nomor build Android/iOS, bukan `expo.version` yang terlihat pengguna.
- Pencarian santri sudah tersedia pada pengajuan izin pengurus, pembuatan pembinaan, beberapa pemilih rapor, catatan kesehatan, dan daftar keuangan. Celah yang terlihat antara lain absensi pertemuan, rincian kehadiran Keamanan, daftar pembinaan, serta daftar izin Keamanan. Inventaris akhir dilakukan implementator sebelum perubahan.

## Perilaku yang diminta

1. **Cari santri saat absensi.** Tambahkan pencarian nama atau NIS di atas daftar peserta. Saring data yang sudah dimuat dari pertemuan, tanpa permintaan API baru per ketikan. Cocokkan huruf tanpa membedakan kapital; tombol hapus/kata kunci kosong mengembalikan daftar lengkap. Hasil pencarian tetap menunjukkan kelas asal dan status/catatan draf yang benar.
2. **Pemisahan IBTIDA PA/PI.** Pada pertemuan IBTIDA dengan peserta kedua kelas, tampilkan pilihan `Semua`, `PA`, dan `PI` beserta hitungan; tampilan `Semua` memiliki judul pemisah yang jelas. Tentukan kelompok dari ID kelas asal snapshot dan data kelas pertemuan yang diverifikasi, bukan dari nama santri. Peserta yang kelas asalnya hilang/di luar daftar tetap terlihat dalam kelompok `Kelas belum tercatat`, tanpa ditebak sebagai PA atau PI. Pertemuan satu kelas dan gabungan non-IBTIDA tetap memakai label kelas yang benar.
3. **Pencarian lintas fitur yang relevan.** Inventaris seluruh layar aplikasi yang memilih santri atau menampilkan daftar banyak santri. Pertahankan pencarian yang sudah bekerja. Tambahkan pencarian pada celah yang benar-benar berguna, termasuk daftar panjang yang ditemukan di atas. Pada daftar berhalaman, pencarian harus memakai penyaring server bercakupan agar menjangkau semua halaman; jangan menyaring hanya halaman yang kebetulan terbuka. Jika endpoint belum memiliki parameter pencarian, perubahan API minimal di repositori web tetap termasuk fase tunggal ini dan diaudit bersama.
4. **Konsistensi absensi.** Pencarian/tab hanya mengubah tampilan. Draf status/catatan disimpan menurut `student_id`, tetap ada saat tab/kata kunci berganti, dan payload simpan tetap mencakup seluruh peserta tepat sekali. Ringkasan dan progres tetap menghitung seluruh pertemuan. Aksi `Semua hadir` diberi label/keterangan eksplisit bahwa ia berlaku untuk semua peserta, termasuk yang sedang tidak terlihat.
5. **Aturan versi aplikasi.** `app.json` menjadi sumber versi yang terlihat pengguna dengan pola `major.minor.patch`: major untuk perubahan besar/tidak kompatibel, minor untuk fitur sedang, patch untuk koreksi kecil. Setiap paket pekerjaan aplikasi yang mengubah perilaku wajib menaikkan versi sekali pada paket itu, termasuk ketika implementatornya AI. Sediakan perintah pembaruan versi yang menghitung angka berikutnya dan pemeriksaan yang gagal bila perubahan aplikasi tidak menaikkan versi terhadap baseline branch. Jangan menaikkan versi per commit koreksi dalam paket yang sama atau menurunkan versi dari `main` terbaru. Untuk paket ini gunakan kenaikan minor: dari `1.2.0` menjadi `1.3.0` **hanya jika** `1.2.0` masih baseline terbaru saat implementasi. Nomor build EAS tetap mengikuti konfigurasi yang ada.

## Pemeriksaan platform dan keamanan

- Bandingkan Android dan iOS pada akun, pertemuan gabungan IBTIDA PA+PI, respons API, dan versi aplikasi yang sama; catat hash/jenis build, perangkat/emulator, jumlah PA/PI, tampilan tab, pencarian, hasil simpan dan muat ulang. Jika tidak ada perangkat fisik, tulis batas itu secara jelas dan jangan menyimpulkan perilaku build produksi.
- Verifikasi kelas `task.classes` dan `students[].class` pada respons pertemuan. Bila data salah atau tidak lengkap, koreksi sumber data/kontrak yang relevan secara minimal; UI tidak boleh mengarang PA/PI.
- Pencarian daftar yang sudah bercakupan tetap memakai hak akses server. Jangan memasukkan nama/NIS ke log diagnostik publik atau bukti yang menggunakan data nyata.
- Pertahankan perubahan lokal `package.json`/`package-lock.json` dan direktori belum terlacak milik pekerjaan lain. Tidak ada perubahan data santri, migrasi produksi, merge, atau rilis dalam fase implementasi/audit ini.

## Kriteria penerimaan satu fase

- [ ] Pada Android dan iOS, guru menemukan peserta dengan nama atau NIS tanpa menggulir; hapus pencarian menampilkan kembali semua peserta.
- [ ] Pertemuan gabungan IBTIDA menampilkan `Semua`, `PA`, `PI` dengan hitungan yang sesuai peserta unik; masing-masing tab hanya menampilkan kelompoknya, dan peserta tanpa kelas tidak salah dimasukkan.
- [ ] Status/catatan yang diubah pada satu tab tetap sama setelah pindah tab/pencarian; simpan dan buka ulang menghasilkan satu absensi guru serta paling banyak satu absensi per santri dengan status/catatan pada ID yang benar.
- [ ] Pertemuan satu kelas, gabungan non-IBTIDA, dan server lama tanpa `classes` tetap dapat dibuka dan disimpan sesuai kontrak lama.
- [ ] Daftar/pemilih santri yang besar dan sebelumnya tidak memiliki pencarian kini dapat mencari seluruh cakupan yang sah; daftar berhalaman tidak terbatas pada halaman saat ini.
- [ ] Versi `app.json` pada commit fitur lebih besar dari baseline `main` terbaru sesuai aturan minor; pemeriksaan versi menolak perubahan aplikasi tanpa kenaikan versi dan menolak penurunan versi. Nomor build EAS tidak disalahartikan sebagai versi pengguna.
- [ ] Bukti uji paritas Android/iOS menyebut versi aplikasi, jenis/hash build, API/fixture, hasil tampilan dan absensi; keterbatasan perangkat atau build dicatat. TypeScript, lint, unit/integrasi serta regresi relevan lulus atau temuan terbuka dinyatakan jelas.

## Handoff

Prompt implementasi dan audit siap salin ada di `docs/pencarian-santri-aplikasi/prompts.md`. Claude Code menyelesaikan satu fase pada branch fitur, menguji, commit/push, lalu berhenti. Codex mengaudit commit yang sama, membuat koreksi terarah bila perlu, mencatat keputusan dan batas uji, commit/push, lalu berhenti. Keputusan merge/rilis tetap pada Human Developer sesudah audit.
