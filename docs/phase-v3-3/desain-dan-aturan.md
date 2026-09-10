# Desain dan aturan Fase 3

## Batas model

- Kasus adalah induk pembinaan untuk tepat satu santri dan satu tahun ajaran. Kasus boleh mandiri atau menautkan satu/beberapa pelanggaran dari subjek yang sama. Generated guard dan unique key mencegah tautan tingkat kasus yang sama digandakan meski `sesi_id` bernilai `NULL`.
- Sesi adalah baris tersendiri milik satu kasus. Satu kasus dapat mempunyai banyak sesi; tautan pada tingkat kasus membuat seluruh sesi terkini terlihat dari detail pelanggaran tanpa menggandakan pelanggaran.
- Rekomendasi ambang tetap saran manual. Hanya rekomendasi berlaku, belum ditindaklanjuti, dan bersubjek sama yang dapat dipilih ketika kasus dibuat.
- Isi internal pembimbing, catatan murobi, dan DTO orang tua memakai allowlist terpisah. Implementasi Fase 3 tidak membuka endpoint orang tua.

## Akses

- Pembimbing aktif dapat membaca dan memutasi kasus santri/tahun dalam cakupan penugasannya.
- Admin dapat mengawasi semua kasus. Pembukaan detail sensitif dicatat sebagai audit akses; koreksi administratif wajib beralasan dan audit menyimpan provenance admin.
- Murobi aktif hanya membaca metadata yang diizinkan untuk binaannya dan menyimpan tanda mengetahui/catatan pada tabel sumber terpisah.
- Pembimbing/murobi di luar cakupan dan orang tua ditolak `403`; daftar juga difilter di query, bukan setelah data dibaca.

## Integritas dan riwayat

- Semua mutasi memakai transaksi, kunci agregat santri/tahun, idempotency key, versi optimistis, audit, serta outbox generik.
- Transisi kasus dan sesi divalidasi server. Konflik versi menghasilkan `409`; transisi semantik yang tidak sah menghasilkan `422` tanpa perubahan parsial.
- Koreksi sesi selesai membuat baris revisi baru. Kunci unik `sesi_satu_revisi` memastikan satu sumber hanya mempunyai satu revisi langsung.
- Penjadwalan ulang juga membuat baris revisi, menyimpan alasan khusus, dan selalu mengosongkan realisasi meski klien mengirim nilainya.
- Pembatalan kasus/sesi dan penutupan kasus tidak menghapus baris. Alasan koreksi, penjadwalan ulang, dan pembatalan disimpan terpisah.
- Penutupan kasus wajib memiliki ringkasan hasil dan mempertahankan sesi, revisi, tautan pelanggaran, poin, serta audit.

## Invariant audit Fase 2 yang dipertahankan

T1–T6 tetap diuji: pelepasan fingerprint sebelum koreksi, alasan koreksi/pembatalan terpisah, rekomendasi basi disaring dan dapat pulih, agregat dapat dipulihkan dari ledger, dokumen proyek tidak dapat diunduh dari web, dan koreksi tanpa perubahan isi tetap sah. Fase 3 menambah referensi rekomendasi ke kasus tanpa mengubah mekanisme validitas atau agregat Fase 2.

## Privasi

Outbox konseling hanya berisi penanda tipe generik. Tujuan kasus, ringkasan internal, hasil, tindak lanjut internal, alasan, nama santri, serta catatan murobi tidak dimasukkan ke payload. Tampilan cetak memakai otorisasi yang sama dengan detail dan header `private, no-store`.
