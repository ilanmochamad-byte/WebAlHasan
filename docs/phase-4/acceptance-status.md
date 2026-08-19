# Status Penerimaan Fase 4

Tanggal pemeriksaan dan pemasangan produksi: 19 Agustus 2026.

## Hasil akhir

- Seluruh 12 kriteria penerimaan Fase 4 pada `PRD.md` lulus dan telah dicentang.
- Kontrak `/api/v1` diperiksa oleh `tests/phase4_static.php`; seluruh pemeriksaan lulus.
- Seluruh file PHP lolos pemeriksaan sintaks, dan pengujian statis Fase 1–3 tetap lulus.
- Pengujian integrasi dijalankan dua kali pada `k1807225_webalhasan_test`; seluruh 13
  skenario lulus, termasuk login aktif/salah/nonaktif, kepemilikan Guru A–B, transaksi,
  idempotensi, constraint unik, baca ulang/koreksi, dan pencabutan token.
- Migrasi aditif `004_phase4_api_attendance.sql` diterapkan ke produksi dan tercatat pada
  `schema_migrations` tanpa mengubah data lama.
- Smoke test HTTPS `https://alhasan.co.id/api/v1/` mengembalikan `200` dan envelope JSON.
- Backup SQL setelah perbaikan generated column berhasil dipulihkan ke database uji;
  seluruh jumlah baris pada manifest cocok, termasuk 4 migrasi, 1 pertemuan, 1 absensi
  guru, 35 peserta snapshot, dan 35 absensi santri.
- `npm run lint`, `npx tsc --noEmit`, pemeriksaan dependency Expo 57, serta ekspor bundle
  Android, iOS, dan web lulus.
- Uji manual aplikasi native Android terhadap API produksi lulus: SecureStore memulihkan
  token, profil dan jadwal tampil, pertemuan dibuka, 35 santri ditandai hadir, absensi
  disimpan dan dibaca ulang, status menjadi `Selesai`, logout mencabut sesi, dan restart
  aplikasi tetap menampilkan login.

## Catatan data uji produksi

Uji penerimaan membuat satu pertemuan produksi untuk jadwal Fiqih tanggal 24 Agustus
2026 dengan catatan `Uji penerimaan Fase 4`, satu absensi guru hadir, dan 35 absensi
santri hadir. Data ini dipertahankan sebagai jejak uji/audit dan tidak dihapus.

## Risiko tersisa

- Build store Android/iOS belum dibuat atau didistribusikan; pengujian memakai development
  client pada emulator Android API 36.
- Audit dependency melaporkan kerentanan transitive tanpa temuan critical; rekomendasi
  perbaikan otomatis menurunkan Expo/React Native sehingga tidak diterapkan karena akan
  melanggar baseline Expo 57 dan React Native 0.86.
- Database uji dan backup di hosting sengaja belum dihapus agar bukti restore tetap dapat
  diaudit; penghapusan memerlukan persetujuan eksplisit.
