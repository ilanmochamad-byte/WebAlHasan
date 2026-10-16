-- Status pemeriksaan terpisah: tidak mengatasnamakan komponen guru kepada pemeriksa.
CREATE TABLE IF NOT EXISTS v4_bahan (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 rencana_id BIGINT UNSIGNED NOT NULL, santri_id INT NOT NULL,
 jenis ENUM('nilai','sikap','kehadiran') NOT NULL,
 mapel_key BIGINT UNSIGNED NOT NULL DEFAULT 0,
 status ENUM('Draf','Dikirim','Dikembalikan','Terverifikasi') NOT NULL,
 sumber_hash CHAR(64) NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1,
 actor_user_id BIGINT UNSIGNED NOT NULL, kapasitas VARCHAR(40) NOT NULL,
 alasan VARCHAR(1000) NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY v4_bahan_unik (rencana_id,santri_id,jenis,mapel_key),
 CONSTRAINT v4_bahan_rencana FOREIGN KEY (rencana_id) REFERENCES v4_rencana(id),
 CONSTRAINT v4_bahan_santri FOREIGN KEY (santri_id) REFERENCES santri(id),
 CONSTRAINT v4_bahan_actor FOREIGN KEY (actor_user_id) REFERENCES users(id),
 CONSTRAINT v4_bahan_key CHECK ((jenis='nilai' AND mapel_key>0) OR (jenis<>'nilai' AND mapel_key=0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS v4_bahan_riwayat (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 bahan_id BIGINT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL,
 snapshot_json JSON NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY v4_bahan_revisi (bahan_id,version),
 CONSTRAINT v4_bahan_riwayat_fk FOREIGN KEY (bahan_id) REFERENCES v4_bahan(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
