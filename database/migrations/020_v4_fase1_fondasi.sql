-- PRD V4 Fase 1. Hanya tabel baru; aman dijalankan ulang. Tidak ada backfill.
CREATE TABLE IF NOT EXISTS v4_penanda_tangan (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 jabatan ENUM('Pendidikan','Pimpinan') NOT NULL,
 nama VARCHAR(150) NOT NULL,
 gelar VARCHAR(100) NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NULL, updated_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY v4_penanda_status (jabatan,is_active),
 CONSTRAINT v4_penanda_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT v4_penanda_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT v4_penanda_active CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS v4_tanda_tangan_aset (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 penanda_tangan_id BIGINT UNSIGNED NOT NULL,
 private_path VARCHAR(255) NOT NULL,
 sha256 CHAR(64) NOT NULL,
 mime_type ENUM('image/png','image/jpeg','image/webp') NOT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 berlaku_mulai DATE NULL, berlaku_selesai DATE NULL,
 uploaded_by BIGINT UNSIGNED NULL, activated_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY v4_aset_penanda (penanda_tangan_id,is_active),
 CONSTRAINT v4_aset_penanda_fk FOREIGN KEY (penanda_tangan_id) REFERENCES v4_penanda_tangan(id),
 CONSTRAINT v4_aset_uploader_fk FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT v4_aset_activator_fk FOREIGN KEY (activated_by) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT v4_aset_range CHECK (berlaku_selesai IS NULL OR berlaku_mulai IS NULL OR berlaku_selesai>=berlaku_mulai)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS v4_rencana (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tahun_ajaran_id INT NOT NULL, kelas_id INT NOT NULL,
 status ENUM('Draf','Siap') NOT NULL DEFAULT 'Draf',
 pendidikan_penanda_id BIGINT UNSIGNED NULL, pimpinan_penanda_id BIGINT UNSIGNED NULL,
 pendidikan_aset_id BIGINT UNSIGNED NULL, pimpinan_aset_id BIGINT UNSIGNED NULL,
 tempat VARCHAR(120) NULL, version INT UNSIGNED NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NULL, updated_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY v4_rencana_scope (tahun_ajaran_id,kelas_id),
 KEY v4_rencana_kelas (kelas_id),
 CONSTRAINT v4_rencana_tahun_fk FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajaran(id),
 CONSTRAINT v4_rencana_kelas_fk FOREIGN KEY (kelas_id) REFERENCES kelas(id),
 CONSTRAINT v4_rencana_pendidikan_fk FOREIGN KEY (pendidikan_penanda_id) REFERENCES v4_penanda_tangan(id),
 CONSTRAINT v4_rencana_pimpinan_fk FOREIGN KEY (pimpinan_penanda_id) REFERENCES v4_penanda_tangan(id),
 CONSTRAINT v4_rencana_pendidikan_aset_fk FOREIGN KEY (pendidikan_aset_id) REFERENCES v4_tanda_tangan_aset(id),
 CONSTRAINT v4_rencana_pimpinan_aset_fk FOREIGN KEY (pimpinan_aset_id) REFERENCES v4_tanda_tangan_aset(id),
 CONSTRAINT v4_rencana_creator_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT v4_rencana_updater_fk FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS v4_rencana_mapel (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 rencana_id BIGINT UNSIGNED NOT NULL, mata_pelajaran_id BIGINT UNSIGNED NOT NULL,
 guru_mapel_assignment_id BIGINT UNSIGNED NULL,
 wajib TINYINT(1) NOT NULL DEFAULT 1,
 target_ketuntasan DECIMAL(5,2) NOT NULL DEFAULT 70.00,
 bobot_harian TINYINT UNSIGNED NOT NULL DEFAULT 40,
 bobot_tengah TINYINT UNSIGNED NOT NULL DEFAULT 30,
 bobot_akhir TINYINT UNSIGNED NOT NULL DEFAULT 30,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY v4_rencana_mapel_unik (rencana_id,mata_pelajaran_id),
 KEY v4_rencana_mapel_master (mata_pelajaran_id),
 CONSTRAINT v4_rencana_mapel_rencana_fk FOREIGN KEY (rencana_id) REFERENCES v4_rencana(id),
 CONSTRAINT v4_rencana_mapel_mapel_fk FOREIGN KEY (mata_pelajaran_id) REFERENCES mata_pelajaran(id),
 CONSTRAINT v4_rencana_mapel_guru_fk FOREIGN KEY (guru_mapel_assignment_id) REFERENCES guru_mapel_assignments(id),
 CONSTRAINT v4_rencana_target CHECK (target_ketuntasan BETWEEN 0 AND 100),
 CONSTRAINT v4_rencana_bobot CHECK (bobot_harian=40 AND bobot_tengah=30 AND bobot_akhir=30 AND bobot_harian+bobot_tengah+bobot_akhir=100),
 CONSTRAINT v4_rencana_wajib CHECK (wajib IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS v4_nilai (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 rencana_mapel_id BIGINT UNSIGNED NOT NULL, santri_id INT NOT NULL,
 komponen ENUM('Harian','Tengah','Akhir') NOT NULL,
 nomor_harian SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 nilai DECIMAL(5,2) NOT NULL, catatan TEXT NULL,
 status ENUM('Draf','Dikirim','Dikembalikan','Terverifikasi') NOT NULL DEFAULT 'Draf',
 version INT UNSIGNED NOT NULL DEFAULT 1,
 pelaku_user_id BIGINT UNSIGNED NOT NULL,
 kapasitas ENUM('guru','pendidikan_pengganti','admin') NOT NULL,
 alasan VARCHAR(1000) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY v4_nilai_unik (rencana_mapel_id,santri_id,komponen,nomor_harian),
 KEY v4_nilai_santri (santri_id),
 CONSTRAINT v4_nilai_mapel_fk FOREIGN KEY (rencana_mapel_id) REFERENCES v4_rencana_mapel(id),
 CONSTRAINT v4_nilai_santri_fk FOREIGN KEY (santri_id) REFERENCES santri(id),
 CONSTRAINT v4_nilai_pelaku_fk FOREIGN KEY (pelaku_user_id) REFERENCES users(id),
 CONSTRAINT v4_nilai_range CHECK (nilai BETWEEN 0 AND 100),
 CONSTRAINT v4_nilai_pengganti_alasan CHECK (kapasitas='guru' OR (alasan IS NOT NULL AND CHAR_LENGTH(TRIM(alasan))>0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS v4_nilai_riwayat (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 nilai_id BIGINT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL,
 snapshot_json JSON NOT NULL, pelaku_user_id BIGINT UNSIGNED NULL,
 kapasitas ENUM('guru','pendidikan_pengganti','admin') NOT NULL,
 alasan VARCHAR(1000) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY v4_nilai_riwayat_versi (nilai_id,version),
 CONSTRAINT v4_nilai_riwayat_nilai_fk FOREIGN KEY (nilai_id) REFERENCES v4_nilai(id),
 CONSTRAINT v4_nilai_riwayat_pelaku_fk FOREIGN KEY (pelaku_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS v4_sikap (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 santri_id INT NOT NULL, tahun_ajaran_id INT NOT NULL, kelas_id INT NOT NULL,
 adab ENUM('Sangat Baik','Baik','Cukup','Perlu Bimbingan') NULL,
 kedisiplinan ENUM('Sangat Baik','Baik','Cukup','Perlu Bimbingan') NULL,
 tanggung_jawab ENUM('Sangat Baik','Baik','Cukup','Perlu Bimbingan') NULL,
 catatan TEXT NULL, pembimbing_user_id BIGINT UNSIGNED NULL,
 diketahui_murobi_user_id BIGINT UNSIGNED NULL, diketahui_pada DATETIME NULL,
 status ENUM('Draf','Dikirim','Dikembalikan','Terverifikasi') NOT NULL DEFAULT 'Draf',
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY v4_sikap_semester (santri_id,tahun_ajaran_id),
 CONSTRAINT v4_sikap_santri_fk FOREIGN KEY (santri_id) REFERENCES santri(id),
 CONSTRAINT v4_sikap_tahun_fk FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajaran(id),
 CONSTRAINT v4_sikap_kelas_fk FOREIGN KEY (kelas_id) REFERENCES kelas(id),
 CONSTRAINT v4_sikap_pembimbing_fk FOREIGN KEY (pembimbing_user_id) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT v4_sikap_murobi_fk FOREIGN KEY (diketahui_murobi_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS v4_sikap_riwayat (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 sikap_id BIGINT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL,
 snapshot_json JSON NOT NULL, actor_user_id BIGINT UNSIGNED NULL,
 kapasitas ENUM('pembimbing','murobi','admin') NOT NULL,
 alasan VARCHAR(1000) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY v4_sikap_riwayat_versi (sikap_id,version),
 CONSTRAINT v4_sikap_riwayat_sikap_fk FOREIGN KEY (sikap_id) REFERENCES v4_sikap(id),
 CONSTRAINT v4_sikap_riwayat_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS v4_rapor (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 santri_id INT NOT NULL, tahun_ajaran_id INT NOT NULL,
 status ENUM('Draf','Perlu Perbaikan','Terverifikasi','Terbit','Diganti','Ditarik') NOT NULL DEFAULT 'Draf',
 current_version INT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY v4_rapor_semester (santri_id,tahun_ajaran_id),
 CONSTRAINT v4_rapor_santri_fk FOREIGN KEY (santri_id) REFERENCES santri(id),
 CONSTRAINT v4_rapor_tahun_fk FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajaran(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS v4_rapor_versi (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 rapor_id BIGINT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL,
 status ENUM('Terbit','Diganti','Ditarik') NOT NULL,
 snapshot_json JSON NOT NULL, pdf_private_path VARCHAR(255) NULL,
 pdf_sha256 CHAR(64) NULL,
 pendidikan_aset_id BIGINT UNSIGNED NULL, pimpinan_aset_id BIGINT UNSIGNED NULL,
 diterbitkan_oleh BIGINT UNSIGNED NULL, diterbitkan_pada DATETIME NULL,
 diganti_pada DATETIME NULL, ditarik_pada DATETIME NULL,
 alasan VARCHAR(1000) NULL,
 UNIQUE KEY v4_rapor_versi_unik (rapor_id,version),
 CONSTRAINT v4_rapor_versi_rapor_fk FOREIGN KEY (rapor_id) REFERENCES v4_rapor(id),
 CONSTRAINT v4_rapor_versi_pendidikan_fk FOREIGN KEY (pendidikan_aset_id) REFERENCES v4_tanda_tangan_aset(id),
 CONSTRAINT v4_rapor_versi_pimpinan_fk FOREIGN KEY (pimpinan_aset_id) REFERENCES v4_tanda_tangan_aset(id),
 CONSTRAINT v4_rapor_versi_actor_fk FOREIGN KEY (diterbitkan_oleh) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS v4_verifikasi (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 rapor_versi_id BIGINT UNSIGNED NOT NULL,
 kode_hash CHAR(64) NOT NULL,
 status ENUM('Terbit','Diganti','Ditarik') NOT NULL,
 pdf_sha256 CHAR(64) NULL,
 dibuat_pada DATETIME NOT NULL, diganti_pada DATETIME NULL, ditarik_pada DATETIME NULL,
 UNIQUE KEY v4_verifikasi_versi (rapor_versi_id),
 UNIQUE KEY v4_verifikasi_kode (kode_hash),
 CONSTRAINT v4_verifikasi_rapor_fk FOREIGN KEY (rapor_versi_id) REFERENCES v4_rapor_versi(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
