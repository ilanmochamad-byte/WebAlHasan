#!/bin/bash
# Fixture uji redesain Fase 2: dua sesi pada HARI INI untuk sbx_guru_biasa
# (tanpa pertemuan), agar kartu "Belum diabsen" + penanda "Absen" dapat
# dibuktikan di perangkat dengan data nyata. Baris ditandai
# jam_migration_note = 'FIXTURE-REDESAIN-F2'. HANYA untuk database *_test.
# Pemakaian: DB_NAME=webalhasan_v3_phase1_test bash tests/browser/fixture-redesain-sesi-hari-ini.sh pasang|pulihkan
set -euo pipefail
DB="${DB_NAME:?Isi DB_NAME}"
case "$DB" in *_test) ;; *) echo "Menolak: DB_NAME harus berakhiran _test" >&2; exit 1;; esac
case "${1:-}" in
  pasang)
    mysql "$DB" <<'SQL'
SET @guru := (SELECT guru_id FROM users WHERE username = 'sbx_guru_biasa');
SET @ref := (SELECT id FROM jadwal_ngaji WHERE id_guru = @guru AND is_active = 1 ORDER BY id LIMIT 1);
SET @hari := ELT(DAYOFWEEK(CURDATE()), 'Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu');
INSERT INTO jadwal_ngaji (id_tahun, waktu_sholat, hari, jam, waktu_mulai, waktu_selesai, jam_migration_status, jam_migration_note, id_kelas, fan_ilmu, nama_kitab, id_guru, tempat, is_active, created_at, updated_at)
SELECT id_tahun, 'Ba''da Shubuh', @hari, '07.30 - 09.00 WIB', '07:30:00', '09:00:00', 'Berhasil', 'FIXTURE-REDESAIN-F2', id_kelas, 'Tahfidz', 'Juz 30', id_guru, 'Ruang 3', 1, NOW(), NOW()
  FROM jadwal_ngaji WHERE id = @ref
UNION ALL
SELECT id_tahun, 'Ba''da Ashar', @hari, '10.00 - 11.30 WIB', '10:00:00', '11:30:00', 'Berhasil', 'FIXTURE-REDESAIN-F2', id_kelas, 'Fiqih', 'Safinatun Najah', id_guru, 'Ruang 2', 1, NOW(), NOW()
  FROM jadwal_ngaji WHERE id = @ref;
SQL
    ;;
  pulihkan)
    mysql "$DB" <<'SQL'
DELETE ap FROM absensi_santri ap JOIN pertemuan_pengajian p ON p.id = ap.pertemuan_id JOIN jadwal_ngaji j ON j.id = p.jadwal_id WHERE j.jam_migration_note = 'FIXTURE-REDESAIN-F2';
DELETE ag FROM absensi_guru ag JOIN pertemuan_pengajian p ON p.id = ag.pertemuan_id JOIN jadwal_ngaji j ON j.id = p.jadwal_id WHERE j.jam_migration_note = 'FIXTURE-REDESAIN-F2';
DELETE pp FROM pertemuan_peserta pp JOIN pertemuan_pengajian p ON p.id = pp.pertemuan_id JOIN jadwal_ngaji j ON j.id = p.jadwal_id WHERE j.jam_migration_note = 'FIXTURE-REDESAIN-F2';
DELETE p FROM pertemuan_pengajian p JOIN jadwal_ngaji j ON j.id = p.jadwal_id WHERE j.jam_migration_note = 'FIXTURE-REDESAIN-F2';
DELETE FROM jadwal_ngaji WHERE jam_migration_note = 'FIXTURE-REDESAIN-F2';
SQL
    ;;
  *) echo "Argumen: pasang | pulihkan" >&2; exit 1;;
esac
mysql -N "$DB" -e "SELECT id, hari, jam, fan_ilmu FROM jadwal_ngaji WHERE jam_migration_note = 'FIXTURE-REDESAIN-F2'"
