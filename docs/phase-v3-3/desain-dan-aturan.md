# Desain dan aturan Fase 3

## Batas model

- Kasus adalah induk pembinaan untuk tepat satu santri dan satu tahun ajaran. Kasus boleh mandiri atau menautkan satu/beberapa pelanggaran dari subjek yang sama. `UNIQUE tautan_unik` milik migrasi 013 (dengan kolom generated `sesi_key=COALESCE(sesi_id,0)`) mencegah tautan tingkat kasus yang sama digandakan meski `sesi_id` bernilai `NULL`.
- Sesi adalah baris tersendiri milik satu kasus. Satu kasus dapat mempunyai banyak sesi; tautan pada tingkat kasus membuat seluruh sesi terkini terlihat dari detail pelanggaran tanpa menggandakan pelanggaran.
- Tautan pelanggaran dibaca pada seluruh rantai revisinya. Koreksi pelanggaran Fase 2 membuat baris revisi baru; detail catatan terkini tetap menampilkan kasus yang menautkan revisi sebelumnya, detail kasus menandai pelanggaran yang sudah direvisi, dan revisi tidak dapat ditautkan ulang ke kasus yang sudah menautkan leluhurnya.
- Rekomendasi ambang tetap saran manual. Hanya rekomendasi berstatus `Baru`, berlaku, belum ditindaklanjuti, dan bersubjek sama yang dapat dipilih — ketika kasus dibuka **atau** melalui tautan ke kasus yang sedang berjalan. Penautan tidak mengubah ledger/agregat dan tidak membuat sesi.
- Pembatalan kasus melepas rekomendasi yang ditautkannya: tautan dikosongkan, status kembali `Baru`, dan ID yang dilepas dicatat pada audit pembatalan. Kasus yang `Selesai` tetap memegang rekomendasinya.
- Isi internal pembimbing, catatan murobi, dan DTO orang tua memakai allowlist terpisah. Implementasi Fase 3 tidak membuka endpoint orang tua.

## Akses

- Pembimbing aktif dapat membaca dan memutasi kasus santri/tahun dalam cakupan penugasannya.
- Admin dapat mengawasi semua kasus. Pembukaan detail sensitif dicatat sebagai audit akses satu kali per pembukaan halaman (detail dan timeline pada halaman yang sama berbagi satu audit); timeline API yang dibuka tersendiri tetap diaudit. Koreksi administratif wajib beralasan dan audit menyimpan provenance admin. Admin murni tidak dapat menjalankan transisi, tautan, atau sesi baru.
- Murobi aktif hanya membaca metadata yang diizinkan untuk binaannya dan menyimpan tanda mengetahui/catatan pada tabel sumber terpisah.
- Pembimbing/murobi di luar cakupan dan orang tua ditolak `403`; daftar juga difilter di query, bukan setelah data dibaca.

## Integritas dan riwayat

- Semua mutasi memakai transaksi, kunci agregat santri/tahun, idempotency key, versi optimistis, audit, serta outbox generik. Kegagalan audit maupun outbox menggulung seluruh transaksi.
- Transisi kasus dan sesi divalidasi server. Konflik versi menghasilkan `409`; transisi semantik yang tidak sah menghasilkan `422` tanpa perubahan parsial.
- Kasus berstatus `Selesai` atau `Dibatalkan` membekukan sesinya: status sesi tidak dapat diubah dan jadwal ulang ditolak `422`. Replay idempotensi atas transisi yang sudah terjadi sebelum penutupan tetap dijawab. Koreksi beralasan tetap tersedia.
- Pada transisi status sesi, field opsional yang dikirim kosong (bentuk formulir web) berarti "tidak diubah", sehingga rencana tindak lanjut dan jadwal berikut yang tersimpan tidak terhapus. Sesi `Dibatalkan` tidak mempunyai waktu realisasi.
- Koreksi sesi membuat baris revisi baru. Kunci unik `sesi_satu_revisi` memastikan satu sumber hanya mempunyai satu revisi langsung. Revisi harus tetap sah terhadap statusnya: sesi `Selesai` wajib memiliki realisasi, ringkasan internal, dan hasil; sesi `Tidak Hadir` wajib memiliki realisasi; sesi terjadwal tidak memiliki realisasi.
- Penjadwalan ulang juga membuat baris revisi, menyimpan alasan khusus, dan selalu mengosongkan realisasi meski klien mengirim nilainya.
- Pembatalan kasus/sesi dan penutupan kasus tidak menghapus baris. Alasan koreksi, penjadwalan ulang, dan pembatalan disimpan terpisah.
- Penutupan kasus wajib memiliki ringkasan hasil dan sedikitnya satu sesi selesai, serta mempertahankan sesi, revisi, tautan pelanggaran, poin, dan audit.
- Koreksi kasus (tujuan/kerahasiaan) memperbarui baris di tempat; nilai sebelum/sesudah tersimpan pada `audit_logs` sesuai PRD 5.2. Revisi berbaris hanya diwajibkan PRD 5.5 untuk sesi.

## Invariant audit Fase 2 yang dipertahankan

T1–T6 tetap diuji: pelepasan fingerprint sebelum koreksi, alasan koreksi/pembatalan terpisah, rekomendasi basi disaring dan dapat pulih, agregat dapat dipulihkan dari ledger, dokumen proyek tidak dapat diunduh dari web, dan koreksi tanpa perubahan isi tetap sah. Fase 3 menambah referensi rekomendasi ke kasus tanpa mengubah mekanisme validitas atau agregat Fase 2.

## Privasi dan notifikasi

Outbox konseling hanya berisi penanda tipe generik. Tujuan kasus, ringkasan internal, hasil, tindak lanjut internal, alasan, nama santri, serta catatan murobi tidak dimasukkan ke payload. Kunci deduplikasi perubahan status kasus/sesi memuat versi hasil, sehingga retry tidak menggandakan notifikasi tetapi perubahan status berikutnya tetap menghasilkan notifikasinya sendiri. Tampilan cetak memakai otorisasi yang sama dengan detail dan header `private, no-store`.

## Keputusan yang masih terbuka

Lihat bagian 7 [bukti audit Claude Code](audit-claude-code.md): makna tingkat kerahasiaan `Internal` vs `Rahasia`, perlakuan sesi yang masih terjadwal ketika kasus ditutup, dan kebutuhan revisi berbaris untuk koreksi kasus.
