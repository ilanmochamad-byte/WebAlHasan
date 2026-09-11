# Desain dan aturan Fase 3

## Batas model

- Kasus adalah induk pembinaan untuk tepat satu santri dan satu tahun ajaran. Kasus boleh mandiri atau menautkan satu/beberapa pelanggaran dari subjek yang sama. `UNIQUE tautan_unik` milik migrasi 013 (dengan kolom generated `sesi_key=COALESCE(sesi_id,0)`) mencegah tautan tingkat kasus yang sama digandakan meski `sesi_id` bernilai `NULL`.
- Sesi adalah baris tersendiri milik satu kasus. Satu kasus dapat mempunyai banyak sesi; tautan pada tingkat kasus membuat seluruh sesi terkini terlihat dari detail pelanggaran tanpa menggandakan pelanggaran.
- Tautan pelanggaran dibaca pada seluruh rantai revisinya. Koreksi pelanggaran Fase 2 membuat baris revisi baru; detail catatan terkini tetap menampilkan kasus yang menautkan revisi sebelumnya, detail kasus menandai pelanggaran yang sudah direvisi, dan revisi tidak dapat ditautkan ulang ke kasus yang sudah menautkan leluhurnya.
- Rekomendasi ambang tetap saran manual. Hanya rekomendasi berstatus `Baru`, berlaku, belum ditindaklanjuti, dan bersubjek sama yang dapat dipilih — ketika kasus dibuka **atau** melalui tautan ke kasus yang sedang berjalan. Penautan tidak mengubah ledger/agregat dan tidak membuat sesi.
- Pembatalan kasus melepas rekomendasi yang ditautkannya: tautan dikosongkan, status kembali `Baru`, dan ID yang dilepas dicatat pada audit pembatalan. Kasus yang `Selesai` tetap memegang rekomendasinya.
- Koreksi kasus disimpan pada `v3_konseling_kasus_revisi` (migrasi 017): satu baris per versi sumber berisi tujuan dan kerahasiaan sebelum/sesudah, alasan, kapasitas (`pembimbing`/`admin`), pelaku, dan waktu. Baris kasus memegang nilai terkini; riwayatnya tidak pernah ditimpa.
- Isi internal pembimbing, catatan murobi, dan DTO orang tua memakai allowlist terpisah. Implementasi Fase 3 tidak membuka endpoint orang tua.

## Kerahasiaan dan akses

Keputusan Human Developer 11 September 2026 (PRD V3 5.5a):

| Pembaca | Kasus `Internal` | Kasus `Rahasia` |
| --- | --- | --- |
| Pembimbing pemilik kasus (dalam cakupan aktif) | Baca isi dan kelola | Baca isi dan kelola |
| Pembimbing lain dalam cakupan aktif | Baca isi dan kelola | Tidak melihat; mutasi `403` |
| Murobi terkait | Baca isi, catatan/tanda mengetahui, notifikasi generik | Tidak melihat kasus, isi, tindak lanjut di detail pelanggaran, maupun notifikasi; tanda mengetahui `403` |
| Admin | Pengawasan dan koreksi beralasan; pembukaan diaudit | Pengawasan dan koreksi beralasan; pembukaan diaudit |
| Orang tua dan akun di luar cakupan | `403` | `403` |

- Pemilik kasus adalah pengurus pada `v3_konseling_kasus.pembimbing_id`, yaitu pembimbing yang membuka kasus. Kepemilikan tidak menggantikan cakupan: pemilik yang penugasannya berakhir tidak lagi membuka kasus; admin tetap dapat mengawasi.
- Penyaringan kerahasiaan dilakukan pada query daftar, detail, dan tindak lanjut pelanggaran — bukan disembunyikan setelah dibaca.
- Admin mengawasi semua kasus. Pembukaan detail dicatat sebagai audit akses satu kali per pembukaan halaman (detail dan timeline pada halaman yang sama berbagi satu audit); timeline API yang dibuka tersendiri tetap diaudit. Admin murni tidak dapat menjalankan transisi, tautan, atau sesi baru.
- Mengoreksi kerahasiaan berlaku seketika: `Rahasia → Internal` membuka kasus bagi murobi terkait dan pembimbing cakupan; `Internal → Rahasia` menutupnya bagi mereka. Notifikasi yang sudah terkirim tetap generik.

## Integritas dan riwayat

- Semua mutasi memakai transaksi, kunci agregat santri/tahun, idempotency key, versi optimistis, audit, serta outbox generik. Kegagalan audit maupun outbox menggulung seluruh transaksi.
- Transisi kasus dan sesi divalidasi server. Konflik versi menghasilkan `409`; transisi semantik yang tidak sah menghasilkan `422` tanpa perubahan parsial.
- Kasus boleh ditutup `Selesai` walaupun masih ada sesi terjadwal. Menutup kasus `Selesai` maupun `Dibatalkan` ikut menutup setiap sesi terjadwal terkini menjadi `Dibatalkan` dengan alasan sistem `Ditutup otomatis: kasus diselesaikan/dibatalkan sebelum sesi dilaksanakan.`, realisasi kosong, versi naik, dan audit `v3.konseling.sesi.ditutup_otomatis` per sesi — dalam transaksi yang sama dengan penutupan.
- Sesi pada kasus tertutup dibekukan: transisi status ditolak `422`. Replay idempotensi atas transisi yang sudah terjadi sebelum penutupan tetap dijawab. Koreksi beralasan tetap tersedia.
- Pada transisi status sesi, field opsional yang dikirim kosong (bentuk formulir web) berarti "tidak diubah", sehingga rencana tindak lanjut dan jadwal berikut yang tersimpan tidak terhapus. Sesi `Dibatalkan` tidak mempunyai waktu realisasi.
- Koreksi sesi membuat baris revisi baru. Kunci unik `sesi_satu_revisi` memastikan satu sumber hanya mempunyai satu revisi langsung. Revisi harus tetap sah terhadap statusnya: sesi `Selesai` wajib memiliki realisasi, ringkasan internal, dan hasil; sesi `Tidak Hadir` wajib memiliki realisasi; sesi terjadwal tidak memiliki realisasi.
- Koreksi kasus memerlukan versi terkini dan alasan; kunci unik `kasus_revisi_satu_per_versi` memastikan satu versi sumber hanya menghasilkan satu revisi.
- Penjadwalan ulang juga membuat baris revisi, menyimpan alasan khusus, dan selalu mengosongkan realisasi meski klien mengirim nilainya.
- Pembatalan kasus/sesi dan penutupan kasus tidak menghapus baris. Alasan koreksi, penjadwalan ulang, dan pembatalan disimpan terpisah.
- Penutupan kasus `Selesai` wajib memiliki ringkasan hasil dan sedikitnya satu sesi selesai, serta mempertahankan sesi, revisi, tautan pelanggaran, poin, dan audit.

## Invariant audit Fase 2 yang dipertahankan

T1–T6 tetap diuji: pelepasan fingerprint sebelum koreksi, alasan koreksi/pembatalan terpisah, rekomendasi basi disaring dan dapat pulih, agregat dapat dipulihkan dari ledger, dokumen proyek tidak dapat diunduh dari web, dan koreksi tanpa perubahan isi tetap sah. Fase 3 menambah referensi rekomendasi ke kasus tanpa mengubah mekanisme validitas atau agregat Fase 2.

## Privasi dan notifikasi

Outbox konseling hanya berisi penanda tipe generik. Tujuan kasus, ringkasan internal, hasil, tindak lanjut internal, alasan, nama santri, serta catatan murobi tidak dimasukkan ke payload. Murobi hanya menerima notifikasi untuk kasus `Internal`; kerahasiaan yang tidak diketahui diperlakukan sebagai `Rahasia`. Kunci deduplikasi perubahan status kasus/sesi memuat versi hasil, sehingga retry tidak menggandakan notifikasi tetapi perubahan status berikutnya tetap menghasilkan notifikasinya sendiri. Tampilan cetak memakai otorisasi yang sama dengan detail dan header `private, no-store`.
