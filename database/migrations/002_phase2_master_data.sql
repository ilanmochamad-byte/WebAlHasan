-- Fase 2: Master Data Terpusat
-- Pra-syarat produksi:
-- 1. Jalankan bin/preflight.php dan backup basis data.
-- 2. Pastikan NIP guru non-kosong dan pasangan tahun/semester tidak duplikat.
-- 3. File ini tidak menghapus tabel, kolom, ID, atau relasi lama.

ALTER TABLE guru
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER status,
    ADD COLUMN archived_at TIMESTAMP NULL AFTER is_active,
    ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER archived_at,
    ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD COLUMN nip_unique_key VARCHAR(30) GENERATED ALWAYS AS (NULLIF(TRIM(nip), '')) STORED AFTER updated_at,
    ADD UNIQUE KEY guru_nip_unique (nip_unique_key);

-- nip_unique_key menjaga NIP non-kosong tetap unik tanpa mengubah nilai kosong lama.

ALTER TABLE santri
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER foto,
    ADD COLUMN archived_at TIMESTAMP NULL AFTER is_active,
    ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER archived_at,
    ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

CREATE TABLE wali (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nama VARCHAR(100) NOT NULL,
    no_hp VARCHAR(20) NULL,
    alamat TEXT NULL,
    legacy_santri_id INT NULL,
    legacy_hubungan VARCHAR(20) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    archived_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY wali_legacy_source_unique (legacy_santri_id, legacy_hubungan),
    KEY wali_nama_index (nama),
    KEY wali_status_index (is_active, archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE santri_wali (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    santri_id INT NOT NULL,
    wali_id BIGINT UNSIGNED NOT NULL,
    hubungan VARCHAR(30) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    archived_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    active_guard TINYINT GENERATED ALWAYS AS (CASE WHEN archived_at IS NULL THEN 1 ELSE NULL END) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY santri_wali_relation_unique (santri_id, wali_id, hubungan, active_guard),
    KEY santri_wali_wali_index (wali_id),
    CONSTRAINT santri_wali_santri_fk FOREIGN KEY (santri_id) REFERENCES santri (id),
    CONSTRAINT santri_wali_wali_fk FOREIGN KEY (wali_id) REFERENCES wali (id),
    CONSTRAINT santri_wali_actor_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Salin setiap ayah/ibu lama sebagai baris tersendiri. Kolom sumber tetap dipertahankan.
INSERT INTO wali (nama, no_hp, alamat, legacy_santri_id, legacy_hubungan, is_active, created_at, updated_at)
SELECT TRIM(nama_ayah), NULLIF(TRIM(no_hp_ayah), ''), NULLIF(TRIM(alamat), ''), id, 'Ayah', 1, NOW(), NOW()
FROM santri
WHERE TRIM(nama_ayah) <> '';

INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary, archived_at, created_by, created_at)
SELECT s.id, w.id, 'Ayah', 1, NULL, NULL, NOW()
FROM santri s
JOIN wali w ON w.legacy_santri_id = s.id AND w.legacy_hubungan = 'Ayah'
WHERE TRIM(s.nama_ayah) <> ''
;

INSERT INTO wali (nama, no_hp, alamat, legacy_santri_id, legacy_hubungan, is_active, created_at, updated_at)
SELECT TRIM(nama_ibu), NULLIF(TRIM(no_hp_ibu), ''), NULLIF(TRIM(alamat), ''), id, 'Ibu', 1, NOW(), NOW()
FROM santri
WHERE TRIM(nama_ibu) <> '';

INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary, archived_at, created_by, created_at)
SELECT s.id, w.id, 'Ibu', CASE WHEN TRIM(s.nama_ayah) = '' THEN 1 ELSE 0 END, NULL, NULL, NOW()
FROM santri s
JOIN wali w ON w.legacy_santri_id = s.id AND w.legacy_hubungan = 'Ibu'
WHERE TRIM(s.nama_ibu) <> ''
;

CREATE TABLE pengurus (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nama VARCHAR(100) NOT NULL,
    nomor_identitas VARCHAR(50) NULL,
    no_hp VARCHAR(20) NULL,
    jabatan VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    archived_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY pengurus_identitas_unique (nomor_identitas),
    KEY pengurus_status_index (is_active, archived_at),
    KEY pengurus_nama_index (nama)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tahun_ajaran
    ADD COLUMN archived_at TIMESTAMP NULL AFTER status,
    ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER archived_at,
    ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD COLUMN active_guard TINYINT GENERATED ALWAYS AS (CASE WHEN status = 'Aktif' AND archived_at IS NULL THEN 1 ELSE NULL END) STORED,
    ADD UNIQUE KEY tahun_semester_unique (tahun, semester),
    ADD UNIQUE KEY tahun_single_active_unique (active_guard);

ALTER TABLE kelas
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER jenjang,
    ADD COLUMN archived_at TIMESTAMP NULL AFTER is_active,
    ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER archived_at,
    ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD UNIQUE KEY kelas_name_level_unique (nama_kelas, jenjang);

ALTER TABLE plotting_kelas
    ADD COLUMN tanggal_mulai DATE NULL AFTER id_tahun,
    ADD COLUMN tanggal_selesai DATE NULL AFTER tanggal_mulai,
    ADD COLUMN status ENUM('Aktif','Selesai') NOT NULL DEFAULT 'Aktif' AFTER tanggal_selesai,
    ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER status,
    ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by,
    ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD COLUMN active_year_guard INT GENERATED ALWAYS AS (CASE WHEN status = 'Aktif' THEN id_tahun ELSE NULL END) STORED,
    ADD UNIQUE KEY plotting_kelas_one_active_unique (id_santri, active_year_guard),
    ADD KEY plotting_kelas_class_year_index (id_kelas, id_tahun, status),
    ADD CONSTRAINT plotting_kelas_actor_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL;

-- Keanggotaan dari semester lama menjadi riwayat; baris dan ID plotting tetap dipertahankan.
UPDATE plotting_kelas pk
JOIN tahun_ajaran ta ON ta.id = pk.id_tahun
SET pk.status = 'Selesai'
WHERE ta.status <> 'Aktif';

CREATE TABLE murobi_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    guru_id INT NOT NULL,
    tahun_ajaran_id INT NOT NULL,
    target_type ENUM('Kamar','Kelas') NOT NULL,
    kamar_id INT NULL,
    kelas_id INT NULL,
    tanggal_mulai DATE NOT NULL,
    tanggal_selesai DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    archived_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    target_key VARCHAR(50) GENERATED ALWAYS AS (CONCAT(target_type, ':', COALESCE(kamar_id, kelas_id))) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY murobi_assignment_unique (guru_id, tahun_ajaran_id, target_key, tanggal_mulai),
    KEY murobi_year_status_index (tahun_ajaran_id, is_active, archived_at),
    KEY murobi_kamar_index (kamar_id),
    KEY murobi_kelas_index (kelas_id),
    CONSTRAINT murobi_guru_fk FOREIGN KEY (guru_id) REFERENCES guru (id),
    CONSTRAINT murobi_tahun_fk FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajaran (id),
    CONSTRAINT murobi_kamar_fk FOREIGN KEY (kamar_id) REFERENCES kamar (id),
    CONSTRAINT murobi_kelas_fk FOREIGN KEY (kelas_id) REFERENCES kelas (id),
    CONSTRAINT murobi_actor_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT murobi_target_check CHECK (
        (target_type = 'Kamar' AND kamar_id IS NOT NULL AND kelas_id IS NULL)
        OR (target_type = 'Kelas' AND kelas_id IS NOT NULL AND kamar_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
