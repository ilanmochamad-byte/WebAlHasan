-- PRD V3 Fase 3: keputusan Human Developer 11 September 2026.
-- 1. Koreksi kasus disimpan sebagai revisi berbaris, bukan hanya audit.
-- 2. Menutup kasus (Selesai atau Dibatalkan) ikut menutup sesi yang masih
--    terjadwal sebagai Dibatalkan dengan alasan sistem.
-- Aturan kerahasiaan Internal/Rahasia ditegakkan kode tanpa perubahan skema.
-- Migrasi 001-016 tidak diubah. Tidak ada DROP maupun DELETE.

CREATE TABLE IF NOT EXISTS v3_konseling_kasus_revisi (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kasus_id BIGINT UNSIGNED NOT NULL,
    versi_sebelum INT UNSIGNED NOT NULL,
    tujuan_sebelum TEXT NOT NULL,
    tujuan_sesudah TEXT NOT NULL,
    kerahasiaan_sebelum ENUM('Internal','Rahasia') NOT NULL,
    kerahasiaan_sesudah ENUM('Internal','Rahasia') NOT NULL,
    alasan TEXT NOT NULL,
    kapasitas ENUM('pembimbing','admin') NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY kasus_revisi_satu_per_versi (kasus_id, versi_sebelum),
    CONSTRAINT kasus_revisi_kasus_fk FOREIGN KEY (kasus_id) REFERENCES v3_konseling_kasus(id),
    CONSTRAINT kasus_revisi_pelaku_fk FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT kasus_revisi_versi_check CHECK (versi_sebelum > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sesi terjadwal terkini pada kasus yang sudah ditutup mengikuti aturan baru.
-- Baris tidak dihapus: status menjadi Dibatalkan, realisasi tetap kosong,
-- alasan sistem menyebut migrasi ini, dan versi dinaikkan. Revisi yang sudah
-- digantikan dibiarkan sebagai riwayat. Idempoten.
UPDATE v3_konseling_sesi s
  JOIN v3_konseling_kasus k ON k.id=s.kasus_id
  LEFT JOIN v3_konseling_sesi nx ON nx.revisi_dari_id=s.id
   SET s.status='Dibatalkan',
       s.realisasi=NULL,
       s.alasan_pembatalan=CONCAT('Ditutup otomatis oleh migrasi 017: kasus sudah ', k.status, ' sebelum sesi dilaksanakan.'),
       s.version=s.version+1
 WHERE nx.id IS NULL
   AND s.archived_at IS NULL
   AND s.status IN ('Dijadwalkan','Dijadwalkan Ulang')
   AND k.status IN ('Selesai','Dibatalkan');
