# Status Penerimaan Fase 4

Tanggal pemeriksaan lokal: 19 Agustus 2026.

## Sudah lulus

- Kontrak `/api/v1` diperiksa oleh `tests/phase4_static.php`: 37 pemeriksaan lulus.
- Seluruh file PHP lolos pemeriksaan sintaks.
- Pengujian statis Fase 1, Fase 2, dan Fase 3 tetap lulus.
- `npm run lint` lulus pada proyek Expo.
- `npx tsc --noEmit` lulus dengan TypeScript strict.
- `npx expo install --check` menyatakan dependency sesuai peta lokal Expo 57.
- Ekspor bundle Android, iOS, dan web berhasil.
- Alur UI login, beranda, detail tugas, pembukaan pertemuan, pengisian, penyimpanan,
  pembacaan ulang, validasi koreksi, dan logout telah diperiksa memakai server mock lokal.

## Belum boleh ditandai lulus

Pengujian integrasi `tests/phase4_integration.php` belum dapat dijalankan karena mesin
lokal tidak menyediakan MySQL. Karena itu, sepuluh kriteria penerimaan yang melibatkan
akun, kepemilikan data, transaksi, constraint unik, idempotensi, pembacaan ulang, dan
pencabutan token tetap tidak dicentang di `PRD.md`.

Pengujian integrasi harus dijalankan pada database khusus yang namanya berakhiran
`_test`. Skrip sengaja menolak database lain agar fixture dan pembersihan data uji tidak
pernah diarahkan ke database produksi.

## Gerbang sebelum produksi

1. Jalankan backup dan manifest pra-migrasi.
2. Terapkan migrasi aditif `004_phase4_api_attendance.sql`.
3. Jalankan pengujian integrasi pada salinan database khusus `_test`.
4. Jalankan smoke test HTTP `/api/v1` dengan akun guru uji aktif dan nonaktif.
5. Hubungkan aplikasi ke URL HTTPS produksi melalui `EXPO_PUBLIC_API_BASE_URL`.
6. Ulangi alur manual pada perangkat Android atau iOS nyata.

