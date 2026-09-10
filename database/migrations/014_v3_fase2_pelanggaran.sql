-- PRD V3 Fase 2: ledger, agregat terrekonsiliasi, dan rekomendasi.
--
-- Migrasi 001-013 tidak diubah. Tabel agregat di bawah sekaligus menjadi
-- kunci baris per santri/tahun ajaran untuk mutasi operasional; alur ini tidak
-- memakai baris tunggal schema_migrations yang hanya cocok untuk konfigurasi.

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_pelanggaran'
        AND INDEX_NAME='pelanggaran_satu_revisi') = 0,
    'ALTER TABLE v3_pelanggaran ADD UNIQUE KEY pelanggaran_satu_revisi (revisi_dari_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS v3_poin_agregat (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    santri_id INT NOT NULL,
    tahun_ajaran_id INT NOT NULL,
    total_poin BIGINT NOT NULL DEFAULT 0,
    direkonsiliasi_pada DATETIME NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    archived_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY poin_agregat_subjek_unik (santri_id, tahun_ajaran_id),
    KEY poin_agregat_tahun_index (tahun_ajaran_id, total_poin),
    CONSTRAINT poin_agregat_santri_fk FOREIGN KEY (santri_id) REFERENCES santri (id),
    CONSTRAINT poin_agregat_tahun_fk FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajaran (id),
    CONSTRAINT poin_agregat_creator_fk FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT poin_agregat_updater_fk FOREIGN KEY (updated_by) REFERENCES users (id),
    CONSTRAINT poin_agregat_version_check CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS v3_rekomendasi (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    santri_id INT NOT NULL,
    tahun_ajaran_id INT NOT NULL,
    ambang_id BIGINT UNSIGNED NOT NULL,
    dipicu_oleh_pelanggaran_id BIGINT UNSIGNED NOT NULL,
    total_poin_snapshot BIGINT NOT NULL,
    label_snapshot VARCHAR(150) NOT NULL,
    rekomendasi_snapshot TEXT NOT NULL,
    status ENUM('Baru','Ditinjau','Selesai') NOT NULL DEFAULT 'Baru',
    event_key VARCHAR(150) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    archived_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY rekomendasi_ambang_subjek_unik (santri_id, tahun_ajaran_id, ambang_id),
    UNIQUE KEY rekomendasi_event_unik (event_key),
    KEY rekomendasi_antrean_index (tahun_ajaran_id, status, id),
    CONSTRAINT rekomendasi_santri_fk FOREIGN KEY (santri_id) REFERENCES santri (id),
    CONSTRAINT rekomendasi_tahun_fk FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajaran (id),
    CONSTRAINT rekomendasi_ambang_fk FOREIGN KEY (ambang_id) REFERENCES v3_ambang (id),
    CONSTRAINT rekomendasi_pelanggaran_fk FOREIGN KEY (dipicu_oleh_pelanggaran_id) REFERENCES v3_pelanggaran (id),
    CONSTRAINT rekomendasi_creator_fk FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT rekomendasi_updater_fk FOREIGN KEY (updated_by) REFERENCES users (id),
    CONSTRAINT rekomendasi_total_check CHECK (total_poin_snapshot >= 0),
    CONSTRAINT rekomendasi_version_check CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
