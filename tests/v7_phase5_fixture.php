<?php

declare(strict_types=1);

/** Fixture F5-1: admin hanya dapat menetapkan sesudah ada penugasan yang cocok. */
function v7f5_fixture_murobi(mysqli $db, int $guruId, int $santriId): int
{
    $name = (string) $db->query('SELECT DATABASE()')->fetch_row()[0];
    if (preg_match('/^test_[A-Za-z0-9_]*_test$/D', $name) !== 1) {
        throw new RuntimeException('Fixture murobi hanya boleh pada test_*_test.');
    }
    $s = $db->prepare("INSERT INTO murobi_assignments (guru_id, tahun_ajaran_id, target_type, kamar_id, tanggal_mulai, is_active)
        SELECT ?, pk.id_tahun, 'Kamar', pk.id_kamar, CURDATE() - INTERVAL 1 DAY, 1
          FROM plotting_kamar pk JOIN tahun_ajaran ta ON ta.id = pk.id_tahun AND ta.status = 'Aktif' AND ta.archived_at IS NULL
         WHERE pk.id_santri = ? ORDER BY pk.id LIMIT 1
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(murobi_assignments.id), is_active = 1");
    $s->bind_param('ii', $guruId, $santriId);
    if (!$s->execute() || (int) $db->insert_id < 1) throw new RuntimeException('Fixture penugasan gagal: ' . $s->error);
    $id = (int) $db->insert_id; $s->close();
    register_shutdown_function(static function () use ($db, $id): void {
        $db->query('UPDATE murobi_assignments SET is_active = 0 WHERE id = ' . $id);
    });
    return $id;
}
