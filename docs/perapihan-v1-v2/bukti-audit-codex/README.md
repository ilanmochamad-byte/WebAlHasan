# Bukti eksekusi audit Codex

Hasil lokal 30 Agustus 2026, hanya sandbox sintetis. Lihat `../hasil-audit-codex.md` untuk kondisi, hitungan, dan batas kesimpulan.

- `full-final.log` melaporkan kegagalan B-1; bukan seluruh suite hijau.
- `A-04-before.log`: tiga kegagalan CSRF berasal dari ekspektasi baru auditor 403 yang dikoreksi menjadi kontrak sebenarnya 419. Dua kegagalan isian adalah bug produk.
- `B-D-390.json`: kunci bernama `error` adalah cuplikan teks halaman untuk identifikasi, **bukan** galat JavaScript. Bukti sebelum koreksi H1 ada di sini; hasil sesudah di `A-05-after.json`.
- `mobile-tsc.log` kosong karena tsc berhasil tanpa keluaran (exit 0).
- `safari-print-dialog.png` adalah pratinjau Safari macOS nyata, bukan PDF tersimpan dan bukan Chromium.
- `api-comparison.json` membandingkan data API pada database identik; field aditif dicatat, tidak dibuang dari laporan.
- `fixture.log` berasal dari pembuatan fixture pertama. Menjalankan ulang dengan manifest yang sama hanya melewati pembuatan.
- File secret environment/SQL account, cookie, dan token tidak disertakan.
