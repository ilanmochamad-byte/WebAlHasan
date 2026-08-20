-- Fase 5: indeks laporan berdasarkan EXPLAIN pada fixture sintetis 1.050 absensi.
-- Sebelum indeks, query rentang memindai pertemuan_pengajian dan filter status tidak
-- memiliki possible_key pada absensi_guru/absensi_santri. Migrasi sepenuhnya aditif.
-- WAJIB: uji pada database *_test, simpan EXPLAIN sebelum/sesudah, dan ambil backup
-- terverifikasi sebelum mempertimbangkan penerapan produksi.

ALTER TABLE pertemuan_pengajian
    ADD KEY pertemuan_date_schedule_report_index (tanggal_pertemuan, jadwal_id);

ALTER TABLE absensi_guru
    ADD KEY absensi_guru_status_meeting_report_index (status, pertemuan_id);

ALTER TABLE absensi_santri
    ADD KEY absensi_santri_status_meeting_report_index (status, pertemuan_id);
