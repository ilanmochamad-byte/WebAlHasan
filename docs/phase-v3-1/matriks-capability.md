# Matriks capability V3

Sumber: `Capabilities::v3Capabilities(user)`. Nilai role dari sesi/klien diabaikan; akun aktif dan role dibaca ulang dari database. Daftar capability V1/V2, `default_mode`, menu mobile, dan `feature_capabilities` fondasi tetap memakai kontrak lama.

| Capability | Sumber | Cakupan | Tersedia pada Fase 1 |
| --- | --- | --- | --- |
| `v3.katalog.kelola` | Admin aktif | Administrasi kategori/katalog | Ya, web |
| `v3.ambang.kelola` | Admin aktif | Administrasi ambang | Ya, web |
| `v3.pengawasan` | Admin aktif | Pengawasan khusus | Kesiapan saja |
| `v3.koreksi` | Admin aktif | Koreksi khusus beralasan | Kesiapan saja |
| `v3.pelanggaran.kelola` | `pembimbing.binaan` dari penugasan nyata | Santri binaan pembimbing | Kesiapan saja; API katalog baca tersedia |
| `v3.konseling.kelola` | `pembimbing.binaan` dari penugasan nyata | Santri binaan pembimbing | Kesiapan saja |
| `v3.binaan.baca` | `murobi.binaan` dari penugasan nyata | Santri binaan murobi | Kesiapan saja; API katalog baca tersedia |
| `v3.murobi.mengetahui` | `murobi.binaan` dari penugasan nyata | Santri binaan murobi | Kesiapan saja |
| `v3.publikasi.baca` | Orang tua + master wali aktif + relasi santri aktif | Hanya publikasi santri terkait | Kesiapan saja |

Pembimbing tetap pengurus, murobi tetap guru. Sumber/cakupan berasal dari metode feature fondasi dan tujuh jenis `PenugasanJenis`, termasuk masa berlaku, tahun aktif, master aktif dan kelas/kamar. Admin tanpa penugasan nyata tidak menyamar menjadi pembimbing/murobi: memperoleh capability pengawasan terpisah.

`v3AppliesToSantri(user, capability, santriId, tahunAjaranId)` memeriksa penempatan kelas/kamar nyata dan memakai pencocokan cakupan fondasi. Orang tua diperiksa dengan join `users → wali → santri_wali → santri`; mengarsipkan relasi menghilangkan hak pada pemeriksaan berikutnya. Relasi wali tidak memberi akses katalog internal. Pengawasan/koreksi adalah capability admin; endpoint detail kelak tetap harus mengecek keberadaan objek dan mencatat akses sensitif.

Capability kesiapan tidak boleh menjadi satu-satunya dasar menampilkan menu operasional: respons API menyatakan `operasional_tersedia=false`. Tidak ada endpoint mutasi kosong.
