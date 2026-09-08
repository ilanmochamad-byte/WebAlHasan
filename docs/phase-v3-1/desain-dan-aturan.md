# Desain dan aturan Fase 1

## Lapisan

`admin/admin_v3_katalog.php` memakai guard admin, CSRF, dan layout portal yang sudah ada. Controller meneruskan nilai ke `App\V3\KatalogService`; seluruh SQL berada pada `KatalogRepository`. API `/api/v1/v3/*` memakai autentikasi token dan envelope lama, kemudian layanan yang sama. Satu resolver `App\Auth\Capabilities` diperluas dengan metode V3; tidak ada sistem akun, penugasan, atau role kedua.

## Kategori dan katalog

Kode wajib unik, dinormalisasi huruf besar, maksimum 40 karakter, hanya huruf/angka/titik/garis bawah/tanda hubung. Nama maksimum 150 karakter; uraian maksimum 5.000. Tingkat tepat Ringan/Sedang/Berat; poin bilangan bulat 0–2.147.483.647. Katalog aktif harus berada dalam masa berlaku kategori aktif saat disimpan. Kategori nonaktif/berakhir menyebabkan katalog anak tidak muncul dalam API aktif. Pengubahan kategori tidak mengubah snapshot pelanggaran.

Tanggal memakai Y-m-d yang benar-benar valid, tahun minimal 1000. Tanggal akhir nullable dan inklusif; akhir sebelum awal ditolak. Status efektif ditampilkan sebagai Aktif, Nonaktif, Akan datang, atau Berakhir. `is_active` hanya 0/1. Data tidak dihapus permanen; nonaktif atau isi tanggal akhir melalui formulir POST. Setiap perubahan baris lama memerlukan alasan maksimum 1.000 karakter dan versi optimistis yang masih sama.

## Ambang

Ambang terikat satu baris tahun ajaran/semester yang belum diarsipkan. Minimum nonnegatif, maksimum nullable atau >= minimum. Dua rentang nilai inklusif tidak boleh beririsan jika tahun sama, keduanya aktif/tidak diarsipkan, dan periode tanggal beririsan. Rentang identik pada periode tanggal terpisah diperbolehkan. API ambang hanya menerima tahun aktif yang berada dalam cakupan penugasan pembaca, atau admin.

Ambang hanyalah teks rekomendasi yang dikelola admin. Tidak ada hukuman, kasus konseling, notifikasi, perubahan nilai, ataupun tagihan otomatis.

## Transaksi, konkurensi, audit

Semua penyimpanan mengunci baris migrasi 013 dalam `schema_migrations` melalui `FOR UPDATE`. Ini menyerialkan seluruh penulisan konfigurasi V3 (volume administrasi rendah), termasuk perpindahan tahun dan perubahan kategori; tidak membutuhkan tabel kunci kedua. Setelah kunci diperoleh, admin dihitung ulang, baris target dikunci, versi diperiksa, validasi dijalankan, data ditulis, lalu satu audit wajib dibuat sebelum commit.

Tradeoff: dua admin yang mengubah konfigurasi berbeda tetap menunggu satu sama lain. Pola ini sengaja hanya untuk konfigurasi Fase 1; tidak menjadi gerbang semua kejadian operasional pada fase selanjutnya. Unique kode adalah pertahanan database tambahan. CHECK menegakkan rentang/tanggal/nilai per baris; overlap antarbaris ditegakkan oleh layanan dalam transaksi karena MySQL/MariaDB tidak memiliki exclusion constraint rentang.

Audit memakai `audit_logs`/`AuditLogger` lama, aksi `v3.kategori.buat/ubah`, `v3.katalog.buat/ubah`, `v3.ambang.buat/ubah`; menyimpan actor, sebelum/sesudah, alasan, IP/user agent, dan waktu. Tidak ada credential dalam payload layanan. Kegagalan audit menyebabkan rollback. Konflik 1062/1205/1213 dipetakan ke pesan aman 409; kegagalan driver lain 503. `execute()` dan `get_result()` yang false tidak dianggap data kosong.

## Batas fase

Tabel operasional hanya struktur persiapan, tidak memiliki service mutasi, endpoint, atau menu. Fase 2–5 kelak wajib memvalidasi transisi status, kesamaan santri pada tautan/ledger, versi publikasi, idempotensi lengkap, akses sensitif dan audit/outbox atomik. Menyediakan tabel bukan bukti alur tersebut sudah berjalan. Notifikasi tetap memakai fondasi outbox yang ada pada fase terkait; WhatsApp tetap OFF/ditangguhkan.
