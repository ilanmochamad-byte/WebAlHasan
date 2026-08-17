-- Rollback Fase 2 hanya untuk staging atau pemulihan terencana.
-- Buat backup terlebih dahulu: rollback menghapus data baru Fase 2, tetapi tidak menyentuh
-- ID, kolom ayah/ibu, atau relasi master lama.

DROP TABLE IF EXISTS murobi_assignments;

ALTER TABLE plotting_kelas
    DROP FOREIGN KEY plotting_kelas_actor_fk,
    DROP INDEX plotting_kelas_class_year_index,
    DROP INDEX plotting_kelas_one_active_unique,
    DROP COLUMN active_year_guard,
    DROP COLUMN updated_at,
    DROP COLUMN created_at,
    DROP COLUMN created_by,
    DROP COLUMN status,
    DROP COLUMN tanggal_selesai,
    DROP COLUMN tanggal_mulai;

ALTER TABLE kelas
    DROP INDEX kelas_name_level_unique,
    DROP COLUMN updated_at,
    DROP COLUMN created_at,
    DROP COLUMN archived_at,
    DROP COLUMN is_active;

ALTER TABLE tahun_ajaran
    DROP INDEX tahun_single_active_unique,
    DROP INDEX tahun_semester_unique,
    DROP COLUMN active_guard,
    DROP COLUMN updated_at,
    DROP COLUMN created_at,
    DROP COLUMN archived_at;

DROP TABLE IF EXISTS pengurus;
DROP TABLE IF EXISTS santri_wali;
DROP TABLE IF EXISTS wali;

ALTER TABLE santri
    DROP COLUMN updated_at,
    DROP COLUMN created_at,
    DROP COLUMN archived_at,
    DROP COLUMN is_active;

ALTER TABLE guru
    DROP INDEX guru_nip_unique,
    DROP COLUMN nip_unique_key,
    DROP COLUMN updated_at,
    DROP COLUMN created_at,
    DROP COLUMN archived_at,
    DROP COLUMN is_active;
