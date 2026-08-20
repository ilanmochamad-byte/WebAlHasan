-- Rollback Fase 5 hanya menghapus indeks laporan; tidak menghapus data absensi.
-- Jalankan hanya setelah EXPLAIN membuktikan penurunan performa, backup tersedia,
-- target database telah diverifikasi, dan pemilik sistem menyetujui rollback produksi.

ALTER TABLE absensi_santri
    DROP INDEX absensi_santri_status_meeting_report_index;

ALTER TABLE absensi_guru
    DROP INDEX absensi_guru_status_meeting_report_index;

ALTER TABLE pertemuan_pengajian
    DROP INDEX pertemuan_date_schedule_report_index;
