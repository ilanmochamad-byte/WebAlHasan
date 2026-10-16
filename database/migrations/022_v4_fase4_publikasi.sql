-- Aditif: metadata operasi; PDF/snapshot lama tidak diubah.
CREATE TABLE IF NOT EXISTS v4_rapor_operasi (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 rapor_id BIGINT UNSIGNED NOT NULL,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 kapasitas ENUM('pendidikan','admin') NOT NULL,
 tindakan ENUM('Terbit','Ganti','Tarik','Buka') NOT NULL,
 version INT UNSIGNED NOT NULL,
 alasan VARCHAR(1000) NOT NULL DEFAULT '',
 request_key CHAR(64) NOT NULL,
 request_hash CHAR(64) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY v4_operasi_key (actor_user_id,request_key),
 KEY v4_operasi_rapor (rapor_id,id),
 CONSTRAINT v4_operasi_rapor_fk FOREIGN KEY (rapor_id) REFERENCES v4_rapor(id),
 CONSTRAINT v4_operasi_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS v4_rencana_penanda_riwayat (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 rencana_id BIGINT UNSIGNED NOT NULL,
 version INT UNSIGNED NOT NULL,
 snapshot_json JSON NOT NULL,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 alasan VARCHAR(1000) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY v4_rencana_penanda_version (rencana_id,version),
 CONSTRAINT v4_penanda_riwayat_rencana_fk FOREIGN KEY (rencana_id) REFERENCES v4_rencana(id),
 CONSTRAINT v4_penanda_riwayat_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS v4_verifikasi_laju (
 bucket CHAR(64) PRIMARY KEY,
 jendela BIGINT UNSIGNED NOT NULL,
 jumlah INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
