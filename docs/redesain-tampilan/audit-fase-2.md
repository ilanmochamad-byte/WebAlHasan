# Audit Codex — Fase 2 redesain tampilan

Tanggal: 15 Oktober 2026 (Asia/Jakarta). **BELUM LULUS penuh**: koreksi kode sudah diuji, tetapi bukti penerimaan cakupan ganda dan tampilan native belum lengkap. Jangan merge atau melanjutkan Fase 3 berdasarkan audit ini.

## Cakupan

Implementasi aplikasi `d49da77..0f2e294`, enam berkas; handoff website `11e8df5`. Kedua checkout bersih pada awal audit dan berada di `redesain-tampilan`. Acuan: AGENTS.md kedua repositori, rencana dan audit Fase 1, seluruh perubahan Fase 2, serta alur keputusan pada `izin/[id].tsx`. Dokumentasi Expo SDK 57 dibaca sebelum koreksi. API/server tidak diubah.

Koreksi aplikasi disimpan pada commit `abca313`.

## Temuan dan koreksi

1. **P2 — Respons pekan lama dapat mengosongkan daftar (dikoreksi).** Pada Jadwal, tekan pekan berikutnya dua kali ketika permintaan pertama lambat. Respons pertama menimpa data terbaru; pemeriksaan `dataSesuai` menolak hasil lama sementara loading sudah false, sehingga daftar menjadi kosong tanpa pesan. Uji browser dengan respons pertama ditunda 2 detik mereproduksi kegagalan pada `0f2e294`. Koreksi memakai nomor permintaan dan invalidasi saat fokus/rentang berubah, termasuk penjagaan error/loading. Skenario yang sama lulus setelah koreksi.
2. **P2 — Status masa depan/lampau tanpa ikon (dikoreksi).** `Belum dimulai` dan `Belum dibuka` mengembalikan ikon null, bertentangan dengan aturan ikon + teks. Keduanya sekarang memakai ikon jam; uji unit diperbarui.
3. **Bukti penerimaan belum lengkap (terbuka).** Akun `sbx_admin` yang dipakai harness hanya mempunyai satu mode. Tidak ada uji perpindahan cakupan ganda Fase 2 yang dapat diklaim dari harness tersebut. Perlu bukti akun dengan dua kemampuan nyata: berpindah mode, pencarian/filter/paginasi, dan detail memakai mode yang dipilih.
4. **Bukti native kartu jadwal teks besar belum lengkap (terbuka).** Gambar Jadwal teks besar yang ditinjau hanya menampilkan 0 sesi. Belum membuktikan jam, judul, status, dan penanda Absen pada kartu tidak terpotong; kartu masih memakai susunan mendatar. Perlu uji layar berisi sesi pada iOS dan Android, normal/teks besar/mode gelap. Perizinan iOS juga belum diuji menurut handoff. Ini kekurangan bukti, bukan klaim bahwa semua keadaan tersebut sudah gagal.

## Verifikasi independen

- TypeScript, lint, seluruh **17/17 unit test**, dan diff whitespace lulus setelah koreksi.
- Harness Fase 2 **14/14 lulus** sebelum dan sesudah koreksi: pemilihan tanggal tanpa request ulang, pekan berikutnya, rentang kustom, penanda Absen dengan satu sesi respons sintetis, filter status izin, tombol pengajuan, dan akses orang tua.
- Harness tambahan `tests/browser/uji-redesain-fase2-race.mjs`: respons pekan terbalik gagal sebelum koreksi dan lulus sesudahnya; halaman kedua izin menampilkan record berbeda; memilih Disetujui kembali ke halaman pertama.
- Regresi harness Fase 1: **6 lulus**, bagian fondasi dilewati karena rute pratinjau sementara tidak ada.
- Kontrak `V2_PHASE3_RUN_API=1 php tests/v2_phase3_api_contract.php`: seluruh pemeriksaan lulus pada database `webalhasan_v3_phase1_test`. Meliputi keputusan murobi/admin, alasan penggantian, otorisasi, idempotensi, konflik keputusan, pembatalan, dan regresi API jadwal. Ini bukti API, bukan interaksi tombol keputusan native.
- Keputusan tetap di detail sesuai rencana: alasan/version wajib, dan admin pengganti membutuhkan alasan tambahan. Tidak menambahkan tombol keputusan langsung di kartu.
- Bukti gambar Claude Android teks besar Jadwal dan Perizinan ditinjau; bukan hasil uji perangkat independen Codex.

## Batas dan handoff

Pengujian browser memakai localhost:8082 dan API localhost:8940; harness memblokir origin lain. Berkas `.env.development.local` sementara diarahkan ke API lokal dan dihapus sesudah audit. Bukti sementara/log berada di `/tmp/audit-redesain-fase2`, `/tmp/audit-fase2-api.log`, dan `/tmp/audit-fase2-unit.log` (tidak menjadi artefak Git).

Catatan bilah web dari Fase 1 tetap berlaku. Uji native tidak dijalankan ulang auditor. Filter Disetujui/Ditolak sebagai pengganti Selesai mengikuti kemampuan endpoint yang sudah ada; status lain masih dapat dilihat melalui Semua.

Handoff: Claude melengkapi bukti temuan 3–4 pada Fase 2, lalu audit ulang. Tidak ada implementasi Fase 3 atau merge ke main.
