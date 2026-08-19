-- Fase 3: Jadwal dan Pertemuan Pengajian
-- Migrasi ini aditif: kolom jam dan seluruh baris jadwal lama tetap dipertahankan.
-- WAJIB sebelum produksi: backup + manifest jumlah baris melalui bin/preflight.php,
-- lalu uji file ini pada salinan database. Jangan jalankan rollback Fase 3 di produksi.

ALTER TABLE jadwal_ngaji
    ADD COLUMN hari ENUM('Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu') NULL AFTER waktu_sholat,
    ADD COLUMN waktu_mulai TIME NULL AFTER jam,
    ADD COLUMN waktu_selesai TIME NULL AFTER waktu_mulai,
    ADD COLUMN jam_migration_status ENUM('Belum Diproses','Berhasil','Gagal') NOT NULL DEFAULT 'Belum Diproses' AFTER waktu_selesai,
    ADD COLUMN jam_migration_note VARCHAR(255) NULL AFTER jam_migration_status,
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER tempat,
    ADD COLUMN archived_at TIMESTAMP NULL AFTER is_active,
    ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER archived_at,
    ADD COLUMN updated_by BIGINT UNSIGNED NULL AFTER created_by,
    ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER updated_by,
    ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD KEY jadwal_year_status_index (id_tahun, is_active, archived_at),
    ADD KEY jadwal_teacher_slot_index (id_tahun, id_guru, hari, waktu_mulai, waktu_selesai),
    ADD KEY jadwal_class_slot_index (id_tahun, id_kelas, hari, waktu_mulai, waktu_selesai),
    ADD KEY jadwal_place_slot_index (id_tahun, tempat, hari, waktu_mulai, waktu_selesai),
    ADD CONSTRAINT jadwal_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    ADD CONSTRAINT jadwal_updated_by_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL;

CREATE TABLE jadwal_jam_migration_report (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    jadwal_id INT NOT NULL,
    original_jam VARCHAR(50) NOT NULL,
    normalized_candidate VARCHAR(50) NULL,
    reason VARCHAR(255) NOT NULL,
    reported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY jadwal_jam_report_unique (jadwal_id),
    CONSTRAINT jadwal_jam_report_schedule_fk FOREIGN KEY (jadwal_id) REFERENCES jadwal_ngaji (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Normalisasi yang diterima:
-- HH.MM - HH.MM WIB, HH:MM-HH:MM, tanda pisah en/em dash, serta pemisah "s/d".
-- Jam sumber tidak pernah diubah. Rentang yang berakhir sebelum/sama dengan mulai dianggap gagal.
UPDATE jadwal_ngaji
SET waktu_mulai = TIME(STR_TO_DATE(
        SUBSTRING_INDEX(
            REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(TRIM(jam)), 'wib', ''), '.', ':'), ' ', ''), '–', '-'), '—', '-'), 's/d', '-'),
            '-', 1
        ),
        '%H:%i'
    )),
    waktu_selesai = TIME(STR_TO_DATE(
        SUBSTRING_INDEX(
            REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(TRIM(jam)), 'wib', ''), '.', ':'), ' ', ''), '–', '-'), '—', '-'), 's/d', '-'),
            '-', -1
        ),
        '%H:%i'
    )),
    jam_migration_status = 'Berhasil',
    jam_migration_note = 'Diparsing otomatis oleh migrasi 003; nilai jam asli dipertahankan.'
WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(TRIM(jam)), 'wib', ''), '.', ':'), ' ', ''), '–', '-'), '—', '-'), 's/d', '-')
          REGEXP '^([01]?[0-9]|2[0-3]):[0-5][0-9]-([01]?[0-9]|2[0-3]):[0-5][0-9]$'
  AND TIME(STR_TO_DATE(
        SUBSTRING_INDEX(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(TRIM(jam)), 'wib', ''), '.', ':'), ' ', ''), '–', '-'), '—', '-'), 's/d', '-'), '-', 1),
        '%H:%i'
      ))
      < TIME(STR_TO_DATE(
        SUBSTRING_INDEX(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(TRIM(jam)), 'wib', ''), '.', ':'), ' ', ''), '–', '-'), '—', '-'), 's/d', '-'), '-', -1),
        '%H:%i'
      ));

INSERT INTO jadwal_jam_migration_report (jadwal_id, original_jam, normalized_candidate, reason)
SELECT id,
       jam,
       LEFT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(TRIM(jam)), 'wib', ''), '.', ':'), ' ', ''), '–', '-'), '—', '-'), 's/d', '-'), 50),
       'Format atau rentang waktu tidak dapat diparsing dengan aman; nilai asli tetap berada pada jadwal_ngaji.jam.'
FROM jadwal_ngaji
WHERE jam_migration_status = 'Belum Diproses';

UPDATE jadwal_ngaji
SET jam_migration_status = 'Gagal',
    jam_migration_note = 'Lihat jadwal_jam_migration_report; nilai jam asli dipertahankan.'
WHERE jam_migration_status = 'Belum Diproses';

CREATE TABLE pertemuan_pengajian (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    jadwal_id INT NOT NULL,
    tanggal_pertemuan DATE NOT NULL,
    status ENUM('Draf','Dibuka','Selesai') NOT NULL DEFAULT 'Draf',
    catatan TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    opened_by BIGINT UNSIGNED NULL,
    completed_by BIGINT UNSIGNED NULL,
    opened_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY pertemuan_schedule_date_unique (jadwal_id, tanggal_pertemuan),
    KEY pertemuan_status_date_index (status, tanggal_pertemuan),
    KEY pertemuan_creator_index (created_by),
    CONSTRAINT pertemuan_schedule_fk FOREIGN KEY (jadwal_id) REFERENCES jadwal_ngaji (id),
    CONSTRAINT pertemuan_creator_fk FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT pertemuan_opener_fk FOREIGN KEY (opened_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT pertemuan_completer_fk FOREIGN KEY (completed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pertemuan_peserta (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pertemuan_id BIGINT UNSIGNED NOT NULL,
    santri_id INT NOT NULL,
    plotting_kelas_id INT NULL,
    nis_snapshot VARCHAR(20) NOT NULL,
    nama_santri_snapshot VARCHAR(100) NOT NULL,
    kelas_id_snapshot INT NOT NULL,
    tahun_ajaran_id_snapshot INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY pertemuan_participant_unique (pertemuan_id, santri_id),
    KEY pertemuan_peserta_santri_index (santri_id),
    CONSTRAINT pertemuan_peserta_meeting_fk FOREIGN KEY (pertemuan_id) REFERENCES pertemuan_pengajian (id) ON DELETE CASCADE,
    CONSTRAINT pertemuan_peserta_santri_fk FOREIGN KEY (santri_id) REFERENCES santri (id),
    CONSTRAINT pertemuan_peserta_plotting_fk FOREIGN KEY (plotting_kelas_id) REFERENCES plotting_kelas (id) ON DELETE SET NULL,
    CONSTRAINT pertemuan_peserta_kelas_fk FOREIGN KEY (kelas_id_snapshot) REFERENCES kelas (id),
    CONSTRAINT pertemuan_peserta_tahun_fk FOREIGN KEY (tahun_ajaran_id_snapshot) REFERENCES tahun_ajaran (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
