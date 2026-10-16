# Handoff audit Claude Code — hanya Fase 3 V4

Audit branch `codex/prd-v4-fase-3` terhadap baseline `fe2ed92e2277f557448889769d2f9dd7aa879445`. Hash commit implementasi ada pada handoff chat dan `git log`. Implementator Codex sudah berhenti setelah commit/push; jangan melanjutkan Fase 4 atau merge sebelum audit dan keputusan Human Developer.

Perubahan utama: sikap berversi pembimbing/murobi, capability aditif, rekap Selesai+snapshot+absensi semester, matriks Pendidikan, workflow/verifikasi per isian mapel dan dua bahan lain, gate kelengkapan tanpa penerbitan, migrasi 021 dan guard rollback. Form pengisi memperlihatkan alasan pengembalian; verifikasi Pendidikan tidak menulis komponen nilai guru. Mobile tidak diubah.

Reproduksi dan hasil tiap kriteria: `status-penerimaan.md`, `hasil-pengujian.md`; perintah dan seluruh log berada di folder ini. Hasil implementator: Fase 3 70 integrasi + 9 transaksi/audit + 4 konkurensi + 56 HTTP/browser lulus; regresi Fase 1–2 lulus; V1–V3 14 integrasi dan 14/15 statis lulus. Kegagalan statis warisan mobile masih terbuka.

Audit independen perlu memeriksa khusus:

1. Scope saat baca/mutasi untuk kelas, kamar, semester, akun/master/tugas aktif, pencabutan relasi dan tugas; tidak ada admin yang mengarang pengetahuan murobi. Perubahan penugasan tidak memindahkan identitas pengisi historis.
2. Satuan verifikasi mapel/santri dan atribusi Fase 2; pengembalian tidak mengubah komponen, koreksi Fase 2 tetap mengikuti keputusan audit sebelumnya tentang revisi perubahan status.
3. Tanda diketahui sekali per revisi, koreksi murobi beralasan dan riwayat pembimbing terjaga, penghalang tanpa murobi sah, serta larangan melewati pengembalian dengan diketahui saja.
4. Sumber kehadiran hanya Selesai+peserta snapshot+jadwal semester cocok; null bukan Alpa, deduplikasi, perubahan sumber menonaktifkan verifikasi efektif; nol pertemuan tidak dibuat-buat.
5. Gate memakai versi/hash terkini, menolak kekurangan/pasca koreksi/kelas ambigu, tanpa menulis rapor/PDF/kode/notifikasi. Fase 4 harus mengulang pemeriksaan dalam transaksi penerbitan; gate ini bukan izin yang dapat dipakai nanti.
6. Transaksi parent/optimistic lock, retry dan enam koneksi penulis/pemeriksa, rollback audit awal/akhir termasuk audit sukses pertama, alasan privat dan metadata audit umum, CSRF/bearer/no-store/XSS.
7. Migrasi/verifier/rollback CHECK pada MySQL versi tujuan, batas beban/riwayat, serta risiko warisan lengkap pada `risiko-terbuka.md`. Jalankan DB uji independen, jangan menghapus residu historis agar verifier tampak lulus.
