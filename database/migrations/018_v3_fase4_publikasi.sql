-- Aditif: snapshot bisnis 013 dipertahankan, draft belum dapat dibaca wali.
CREATE TABLE IF NOT EXISTS v3_publikasi_pratinjau (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 token_hash CHAR(64) NOT NULL UNIQUE,
 created_by BIGINT UNSIGNED NOT NULL,
 sumber_type ENUM('kasus','sesi','pelanggaran') NOT NULL,
 sumber_id BIGINT UNSIGNED NOT NULL,
 sumber_version INT UNSIGNED NOT NULL,
 publikasi_id BIGINT UNSIGNED NULL,
 publikasi_version INT UNSIGNED NULL,
 isi_json MEDIUMTEXT NOT NULL,
 penerima_json TEXT NOT NULL,
 alasan TEXT NULL,
 alasan_penerima TEXT NULL,
 expires_at DATETIME NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (created_by) REFERENCES users(id),
 FOREIGN KEY (publikasi_id) REFERENCES v3_publikasi(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS v3_publikasi_riwayat (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 publikasi_id BIGINT UNSIGNED NOT NULL,
 version INT UNSIGNED NOT NULL,
 tindakan ENUM('Terbit','Koreksi','Tarik') NOT NULL,
 sebelum_json MEDIUMTEXT NULL,
 sesudah_json MEDIUMTEXT NOT NULL,
 alasan TEXT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY publikasi_versi (publikasi_id,version),
 FOREIGN KEY (publikasi_id) REFERENCES v3_publikasi(id),
 FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS v3_publikasi_outbox (
 outbox_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 publikasi_id BIGINT UNSIGNED NOT NULL,
 publikasi_version INT UNSIGNED NOT NULL,
 FOREIGN KEY (outbox_id) REFERENCES notifikasi_outbox(id),
 FOREIGN KEY (publikasi_id) REFERENCES v3_publikasi(id),
 INDEX publikasi_notifikasi (publikasi_id,publikasi_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
