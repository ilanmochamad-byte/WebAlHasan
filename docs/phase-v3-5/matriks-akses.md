# Matriks akses Fase 5

| Capability/konteks | Menu/operasi | Cakupan | Laporan |
| --- | --- | --- | --- |
| v3.pelanggaran.kelola + penugasan pembimbing aktif | Santri, pelanggaran, rekomendasi, kasus/sesi dan status | Penugasan kelas/kamar/tahun aktif | Metadata pelanggaran/kasus; Rahasia hanya pemilik |
| v3.binaan.baca + v3.murobi.mengetahui | Baca Internal, mengetahui dan catatan | Penugasan murobi aktif | Metadata pelanggaran/kasus Internal |
| v3.pengawasan | Pengawasan sesuai capability; bukan mode login baru | Sesuai resolver admin | Metadata semua kasus/pelanggaran, tanpa isi konseling dalam laporan |
| v3.publikasi.baca | Publikasi keluarga | Akun wali, wali/santri aktif, santri_wali aktif, penerima snapshot tepat | Snapshot keluarga, status ditarik tanpa teks lama |
| Tidak punya capability/relasi | Tidak ada menu operasional | Tidak ada | 403 atau hasil kosong aman |

Semua endpoint autentikasi ulang dari token/session dan resolver server. ID yang ditebak tidak memberi hak. Filter ID pembimbing/murobi/santri tidak dapat memperluas SQL cakupan. Admin mengikuti capability; siswa/kelas/kamar historis tidak menghidupkan penugasan berakhir. Koreksi baseline `/v3/pelanggaran/options` menolak wali; endpoint baru `/v3/mobile/options` juga menolak wali. Laporan orang tua menolak filter internal, bukan menerima dan memperlihatkan sinyal data internal. Mutasi web lama tetap CSRF; laporan GET baca saja. Tidak menambah endpoint upload atau mengurangi pengaman upload Fase 2–3.
