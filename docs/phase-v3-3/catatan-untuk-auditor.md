# Catatan untuk auditor Claude Code

## Titik berisiko tinggi

1. Periksa `KonselingService` dan `KonselingRepository` sebagai satu batas transaksi. Audit dan outbox harus berada dalam transaksi yang sama dengan mutasi bisnis.
2. Uji ulang pembimbing/murobi dengan cakupan aktif, cakupan berakhir, subjek lain, admin murni, akun peran ganda, orang tua, serta ID yang ditebak.
3. Pastikan `show()` memakai serializer internal atau murobi secara eksplisit; jangan mengembalikan baris repository mentah. `parentSerializer()` hanya DTO allowlist untuk pengujian dan kesiapan Fase 4, bukan endpoint publik.
4. Periksa koreksi sesi selesai dan jadwal ulang: sumber tidak berubah, revisi langsung tunggal, alasan terpisah, dan sesi terjadwal tidak mempunyai `realisasi`.
5. Periksa rekomendasi: hanya baris berlaku dan belum ditindaklanjuti, subjek harus sama, dan penautan manual tidak mengubah ledger/agregat.
6. Jalankan verifier fondasi setelah 016. `bin/v3_verify.php` kini menghitung unique tambahan sesi hanya bila migrasi 016 tercatat; hitungan tetap eksak.

## Urutan reproduksi yang disarankan

```sh
DB_NAME=webalhasan_v3_phase1_test V3_RUN_TESTS=1 bash bin/v3_phase3_run_tests.sh
DB_NAME=webalhasan_v3_phase1_test V3_RUN_TESTS=1 bash bin/v3_phase2_run_tests.sh
DB_NAME=webalhasan_v3_phase1_test V3_RUN_TESTS=1 bash bin/v3_phase1_run_tests.sh
DB_NAME=webalhasan_v3_phase1_test MOBILE_APP_ROOT=/Users/ilanmochamad/alhasanApps bash bin/penugasan_run_all_tests.sh
```

Browser menggunakan server lokal `127.0.0.1:8940`, opt-in `PERAPIHAN_AUDIT_DB=1`, dan `tests/browser/uji-v3-fase3.mjs`. Gunakan hanya database bernama `webalhasan_v3_phase1_test`.

Fase 4 dilarang pada audit ini: jangan menambahkan publikasi atau notifikasi orang tua. Jika koreksi diperlukan, commit/push terarah pada branch ini lalu berhenti.
