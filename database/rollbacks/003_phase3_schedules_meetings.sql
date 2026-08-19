-- Rollback Fase 3 HANYA untuk staging atau pemulihan terencana dari backup penuh.
-- Jangan jalankan di produksi: pertemuan, snapshot peserta, dan metadata jadwal Fase 3 akan hilang.
-- Kolom jam asli dan baris jadwal lama tidak dihapus oleh rollback ini.

DROP TABLE IF EXISTS pertemuan_peserta;
DROP TABLE IF EXISTS pertemuan_pengajian;
DROP TABLE IF EXISTS jadwal_jam_migration_report;

ALTER TABLE jadwal_ngaji
    DROP FOREIGN KEY jadwal_updated_by_fk,
    DROP FOREIGN KEY jadwal_created_by_fk,
    DROP INDEX jadwal_place_slot_index,
    DROP INDEX jadwal_class_slot_index,
    DROP INDEX jadwal_teacher_slot_index,
    DROP INDEX jadwal_year_status_index,
    DROP COLUMN updated_at,
    DROP COLUMN created_at,
    DROP COLUMN updated_by,
    DROP COLUMN created_by,
    DROP COLUMN archived_at,
    DROP COLUMN is_active,
    DROP COLUMN jam_migration_note,
    DROP COLUMN jam_migration_status,
    DROP COLUMN waktu_selesai,
    DROP COLUMN waktu_mulai,
    DROP COLUMN hari;
