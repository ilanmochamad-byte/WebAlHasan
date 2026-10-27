<?php

declare(strict_types=1);

/**
 * Pembantu bersama alat rilis PRD V7 Fase 1 (preflight, verifier, rollback
 * preflight). Hanya membaca basis data.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';

/** Status V2 dan urutannya sebelum migrasi 030 (harus tetap di depan ENUM). */
const V7F1_STATUS_V2 = ['Diajukan', 'Perlu Penetapan Admin', 'Disetujui', 'Ditolak', 'Dibatalkan'];
const V7F1_STATUS_V7 = ['Menunggu Pembimbing', 'Perlu Penetapan Pembimbing', 'Perlu Perbaikan Wali', 'Ditolak Pembimbing'];

function v7f1_nilai(mysqli $db, string $sql): mixed
{
    $hasil = $db->query($sql);
    if ($hasil === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
    $row = $hasil->fetch_row();

    return $row[0] ?? null;
}

function v7f1_ada_kolom(mysqli $db, string $tabel, string $kolom): bool
{
    $s = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->bind_param('ss', $tabel, $kolom);
    $s->execute();
    $ada = (int) $s->get_result()->fetch_row()[0] > 0;
    $s->close();

    return $ada;
}

function v7f1_ada_tabel(mysqli $db, string $tabel): bool
{
    $s = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->bind_param('s', $tabel);
    $s->execute();
    $ada = (int) $s->get_result()->fetch_row()[0] > 0;
    $s->close();

    return $ada;
}

/**
 * Jumlah baris seluruh tabel lama (selain tabel V7, schema_migrations,
 * audit_logs) dan sidik jari ID identitas serta data perizinan V2/warisan.
 *
 * @return array{legacy_counts:array<string,int>, id_fingerprints:array<string,string>}
 */
function v7f1_snapshot(mysqli $db): array
{
    $db->query('SET SESSION group_concat_max_len = 4294967295');
    $lama = [];
    $tabel = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
        AND TABLE_NAME NOT IN ('izin_pembimbing_penetapan', 'schema_migrations', 'audit_logs') ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC);
    foreach ($tabel as $row) {
        $t = (string) $row['TABLE_NAME'];
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $t)) {
            throw new RuntimeException('Nama tabel tidak dikenal.');
        }
        $lama[$t] = (int) v7f1_nilai($db, 'SELECT COUNT(*) FROM `' . $t . '`');
    }
    // Baris berasal wali tidak dihitung pada izin_pengajuan agar manifest
    // pra-migrasi tetap dapat dibandingkan sesudah data V7 ada.
    if (v7f1_ada_kolom($db, 'izin_pengajuan', 'asal_pengajuan')) {
        $lama['izin_pengajuan'] = (int) v7f1_nilai($db, "SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan = 'Pengurus'");
    }
    $saringV2 = v7f1_ada_kolom($db, 'izin_pengajuan', 'asal_pengajuan') ? " WHERE asal_pengajuan = 'Pengurus'" : '';
    $sidik = [];
    foreach (['santri', 'wali', 'santri_wali', 'users', 'perizinan', 'izin_keputusan', 'pembimbing_assignments', 'murobi_assignments'] as $t) {
        $sidik[$t] = (string) v7f1_nilai($db, "SELECT SHA2(COALESCE(GROUP_CONCAT(id ORDER BY id SEPARATOR ','), ''), 256) FROM `$t`");
    }
    $sidik['santri_wali_relasi'] = (string) v7f1_nilai($db, "SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, santri_id, wali_id, COALESCE(archived_at, '-')) ORDER BY id SEPARATOR ','), ''), 256) FROM santri_wali");
    $sidik['izin_pengajuan_v2'] = (string) v7f1_nilai($db, "SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, COALESCE(legacy_perizinan_id, '-'), is_legacy, santri_id,
        COALESCE(pengurus_id, '-'), COALESCE(murobi_guru_id, '-'), tgl_izin, tgl_kembali, status, version, SHA2(alasan, 256)) ORDER BY id SEPARATOR ','), ''), 256)
        FROM izin_pengajuan" . $saringV2);
    $sidik['izin_keputusan_isi'] = (string) v7f1_nilai($db, "SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, pengajuan_id, hasil, kapasitas, SHA2(alasan, 256)) ORDER BY id SEPARATOR ','), ''), 256) FROM izin_keputusan");
    $sidik['perizinan_isi'] = (string) v7f1_nilai($db, "SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, id_santri, tgl_izin, tgl_kembali, status) ORDER BY id SEPARATOR ','), ''), 256) FROM perizinan");

    return ['legacy_counts' => $lama, 'id_fingerprints' => $sidik];
}
