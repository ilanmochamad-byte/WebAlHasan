-- Fase 4: REST API dan Absensi Guru/Santri
-- Migrasi ini sepenuhnya aditif dan tidak mengubah atau menghapus data lama.
-- WAJIB sebelum produksi: backup, manifest jumlah baris, dan uji pada database *_test.

CREATE TABLE absensi_guru (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pertemuan_id BIGINT UNSIGNED NOT NULL,
    guru_id INT NOT NULL,
    status ENUM('Hadir','Terlambat','Izin','Sakit','Alpa') NOT NULL,
    dicatat_pada DATETIME NOT NULL,
    dicatat_oleh BIGINT UNSIGNED NOT NULL,
    catatan TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY absensi_guru_meeting_teacher_unique (pertemuan_id, guru_id),
    KEY absensi_guru_teacher_index (guru_id),
    CONSTRAINT absensi_guru_meeting_fk FOREIGN KEY (pertemuan_id) REFERENCES pertemuan_pengajian (id),
    CONSTRAINT absensi_guru_teacher_fk FOREIGN KEY (guru_id) REFERENCES guru (id),
    CONSTRAINT absensi_guru_actor_fk FOREIGN KEY (dicatat_oleh) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE absensi_santri (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pertemuan_id BIGINT UNSIGNED NOT NULL,
    santri_id INT NOT NULL,
    status ENUM('Hadir','Terlambat','Izin','Sakit','Alpa') NOT NULL,
    dicatat_pada DATETIME NOT NULL,
    dicatat_oleh BIGINT UNSIGNED NOT NULL,
    catatan TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY absensi_santri_meeting_student_unique (pertemuan_id, santri_id),
    KEY absensi_santri_student_index (santri_id),
    CONSTRAINT absensi_santri_meeting_fk FOREIGN KEY (pertemuan_id) REFERENCES pertemuan_pengajian (id),
    CONSTRAINT absensi_santri_student_fk FOREIGN KEY (santri_id) REFERENCES santri (id),
    CONSTRAINT absensi_santri_actor_fk FOREIGN KEY (dicatat_oleh) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_idempotency_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    operation VARCHAR(80) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    response_status SMALLINT UNSIGNED NULL,
    response_json JSON NULL,
    resource_type VARCHAR(80) NULL,
    resource_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY api_idempotency_user_key_unique (user_id, idempotency_key),
    KEY api_idempotency_created_index (created_at),
    CONSTRAINT api_idempotency_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
