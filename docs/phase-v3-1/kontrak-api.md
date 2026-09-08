# Kontrak API baca V3 Fase 1

Endpoint tambahan di bawah `/api/v1`. Bearer token, autentikator, envelope `success/data/error`, dan seluruh endpoint lama dipertahankan.

| GET | Akses | Parameter |
| --- | --- | --- |
| `/v3/capabilities` | Setiap akun yang lolos autentikasi API | Tidak ada |
| `/v3/katalog` | Admin, pembimbing aktif, murobi aktif | `page` (default 1), `kategori_id`, `tingkat` |
| `/v3/ambang` | Admin atau penugasan aktif pada tahun diminta | `tahun_ajaran_id` wajib, `page` |

Capability menghasilkan `data.capabilities`, peta nama ke `{sumber, cakupan}`, serta `data.operasional_tersedia=false`. Pengguna tanpa penugasan/relasi sah memperoleh peta kosong jika tidak mempunyai hak lain. Profil/login dan `/me/capabilities` lama tidak ditambah field V3 atau diubah maknanya.

Daftar menghasilkan `data={rows,total,page,per_page}`. Ukuran halaman tetap 25. Nomor halaman positif; pembacaan dibatasi. Filter array, enum tidak dikenal, dan ID tidak sah ditolak 422. Katalog hanya yang aktif dalam tanggal berjalan dengan kategori aktif pada tanggal berjalan. Ambang hanya untuk tahun aktif yang relevan.

Field katalog: `id,kode,nama,kategori_id,kategori_nama,tingkat,poin_default,uraian,tanggal_mulai,tanggal_selesai`. Field ambang: `id,tahun_ajaran_id,nilai_minimum,nilai_maksimum,label,rekomendasi,tanggal_mulai,tanggal_selesai`. Nilai numerik database mengikuti representasi hasil driver MySQL lama; klien tidak boleh mengasumsikan seluruh integer berbentuk JSON number. Tidak ada actor/audit, catatan santri, isi konseling, atau lampiran dalam serializer ini.

Penolakan otorisasi: 403 `FORBIDDEN`. Validasi: 422 `VALIDATION_FAILED`. Konflik: 409 `CONFLICT`. Infrastruktur: 503 `SERVICE_UNAVAILABLE` dengan pesan aman. Autentikasi gagal mengikuti kode lama. Tidak ada POST/PUT/PATCH pencatatan pelanggaran/konseling; URL yang belum ada memberi 404 `NOT_FOUND`.

Orang tua tidak mendapat katalog atau ambang internal (403), meskipun mempunyai capability kesiapan membaca publikasi.
