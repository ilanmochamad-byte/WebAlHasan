<?php

declare(strict_types=1);

/**
 * Verifier PRD V7 Fase 1 (migrasi 030). Hanya membaca.
 *
 *   php bin/v7_phase1_verify.php [manifest.json]             # sesudah migrasi
 *   php bin/v7_phase1_verify.php --rollback [manifest.json]  # sesudah rollback
 *
 * Sesudah migrasi: skema (ENUM V2 tetap di depan, kolom/indeks/kunci asing/
 * CHECK V7, tabel penetapan InnoDB) dan invarians data (baris wali selalu ber-
 * wali dan tanpa pengurus pengaju; tahap V7 hanya untuk baris wali; tahap
 * pembimbing tanpa murobi dan tanpa keputusan; Menunggu Pembimbing selalu ber-
 * pembimbing; riwayat + audit pembuatan untuk setiap baris wali; versi =
 * 1 + jumlah penetapan; tidak ada dua pengajuan aktif bertumpang tindih yang
 * melibatkan baris wali; klaim idempotensi V7 selesai; audit tanpa isi izin).
 * Sesudah rollback: seluruh objek V7 hilang dan ENUM kembali lima nilai V2.
 * Dengan manifest: jumlah baris tabel lama dan sidik jari ID/isi identik.
 */

require_once __DIR__ . '/_v7_phase1.php';

$args = array_slice($argv, 1);
$rollback = in_array('--rollback', $args, true);
$manifestPath = null;
foreach ($args as $arg) {
    if ($arg !== '--rollback') {
        $manifestPath = $arg;
    }
}
$db = app_db();
$gagal = 0;
$periksa = static function (bool $ok, string $label) use (&$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    if (!$ok) {
        $gagal++;
    }
};
$n = static fn (string $sql): int => (int) v7f1_nilai($db, $sql);
$tipe = (string) v7f1_nilai($db, "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'status'");
$kolomV7 = ['asal_pengajuan', 'wali_id', 'catatan_wali', 'pembimbing_pengurus_id', 'pembimbing_routing_kandidat', 'pembimbing_routing_catatan',
    'pembimbing_routing_pada', 'pembimbing_ditetapkan_oleh_user_id', 'pembimbing_ditetapkan_pada'];

if ($rollback) {
    $periksa(!v7f1_ada_tabel($db, 'izin_pembimbing_penetapan'), 'Tabel izin_pembimbing_penetapan sudah dilepas');
    foreach ($kolomV7 as $kolom) {
        $periksa(!v7f1_ada_kolom($db, 'izin_pengajuan', $kolom), 'Kolom izin_pengajuan.' . $kolom . ' sudah dilepas');
    }
    $periksa($tipe === "enum('" . implode("','", V7F1_STATUS_V2) . "')", 'ENUM status kembali lima nilai V2 (' . $tipe . ')');
    $periksa($n("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND CONSTRAINT_NAME LIKE 'izin_pengajuan\\_v7\\_%'") === 0,
        'Tidak ada CHECK/kunci asing V7 tersisa');
    $periksa($n("SELECT COUNT(*) FROM schema_migrations WHERE migration = '030_v7_fase1_pengajuan_wali.sql'") === 0, 'Migrasi 030 tidak lagi tercatat');
} else {
    $periksa($n("SELECT COUNT(*) FROM schema_migrations WHERE migration = '030_v7_fase1_pengajuan_wali.sql'") === 1, 'Migrasi 030 tercatat');
    $periksa($tipe === "enum('" . implode("','", array_merge(V7F1_STATUS_V2, V7F1_STATUS_V7)) . "')", 'ENUM status: lima nilai V2 tetap di depan, empat tahap V7 di ujung');
    foreach ($kolomV7 as $kolom) {
        $periksa(v7f1_ada_kolom($db, 'izin_pengajuan', $kolom), 'Kolom izin_pengajuan.' . $kolom);
    }
    $periksa((string) v7f1_nilai($db, "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pembimbing_penetapan'") === 'InnoDB', 'Tabel izin_pembimbing_penetapan InnoDB');
    $periksa($n("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND CONSTRAINT_NAME IN ('izin_pengajuan_v7_wali_fk', 'izin_pengajuan_v7_pembimbing_fk', 'izin_pengajuan_v7_penetap_fk', 'izin_pengajuan_v7_asal_check')") === 4,
        'Tiga kunci asing V7 dan CHECK asal terpasang pada izin_pengajuan');
    $periksa($n("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pembimbing_penetapan'") === 5,
        'Lima kunci asing tabel penetapan');
    foreach (['izin_pengajuan_v7_pembimbing_index', 'izin_pengajuan_v7_asal_index', 'izin_pengajuan_v7_wali_index'] as $indeks) {
        $periksa($n("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND INDEX_NAME = '$indeks'") > 0, 'Indeks ' . $indeks);
    }

    $v2 = "'" . implode("','", V7F1_STATUS_V2) . "'";
    $v7 = "'" . implode("','", V7F1_STATUS_V7) . "'";
    $wali = $n("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan = 'Wali'");
    echo '[info] Pengajuan berasal wali: ' . $wali . '; per status: ' . json_encode($db->query("SELECT status, COUNT(*) AS n FROM izin_pengajuan WHERE asal_pengajuan = 'Wali' GROUP BY status")->fetch_all(MYSQLI_ASSOC)) . PHP_EOL;
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan = 'Wali' AND (wali_id IS NULL OR pengurus_id IS NOT NULL OR is_legacy <> 0)") === 0, 'Baris wali selalu berwali, tanpa pengurus pengaju, bukan warisan');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan = 'Pengurus' AND (wali_id IS NOT NULL OR status NOT IN ($v2))") === 0, 'Baris pengurus/warisan tanpa wali_id dan tetap berstatus V2');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan WHERE status IN ($v7) AND (murobi_guru_id IS NOT NULL OR asal_pengajuan <> 'Wali')") === 0, 'Tahap pembimbing V7 tidak pernah memiliki murobi tujuan (murobi belum menerima)');
    $periksa($n("SELECT COUNT(*) FROM izin_keputusan k JOIN izin_pengajuan p ON p.id = k.pengajuan_id WHERE p.status IN ($v7)") === 0, 'Tidak ada keputusan murobi untuk pengajuan di tahap pembimbing');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan WHERE status = 'Menunggu Pembimbing' AND (pembimbing_pengurus_id IS NULL OR pembimbing_assignment_id IS NULL)") === 0, 'Setiap Menunggu Pembimbing memiliki pembimbing dan penugasan tujuan');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan WHERE status = 'Perlu Penetapan Pembimbing' AND pembimbing_pengurus_id IS NOT NULL") === 0, 'Perlu Penetapan Pembimbing belum memiliki pembimbing tujuan');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan p WHERE p.asal_pengajuan = 'Wali' AND NOT EXISTS (SELECT 1 FROM izin_riwayat_status r WHERE r.pengajuan_id = p.id AND r.peristiwa = 'pengajuan_wali_dibuat')") === 0, 'Setiap pengajuan wali memiliki riwayat pembuatan');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan p WHERE p.asal_pengajuan = 'Wali' AND NOT EXISTS (SELECT 1 FROM audit_logs a WHERE a.entity_type = 'izin_pengajuan' AND a.entity_id = p.id AND a.action = 'izin_wali_pengajuan_dibuat')") === 0, 'Setiap pengajuan wali memiliki audit pembuatan');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan p WHERE p.asal_pengajuan = 'Wali' AND p.status IN ('Menunggu Pembimbing', 'Perlu Penetapan Pembimbing')
        AND p.version <> 1 + (SELECT COUNT(*) FROM izin_pembimbing_penetapan t WHERE t.pengajuan_id = p.id)") === 0, 'Versi pengajuan tahap pembimbing = 1 + jumlah penetapan (Fase 1)');
    $periksa($n("SELECT COUNT(*) FROM izin_pembimbing_penetapan t WHERE NOT EXISTS (SELECT 1 FROM izin_riwayat_status r WHERE r.pengajuan_id = t.pengajuan_id AND r.peristiwa IN ('pembimbing_ditetapkan', 'pembimbing_ditetapkan_ulang'))") === 0, 'Setiap penetapan pembimbing memiliki riwayat');
    $periksa($n("SELECT COUNT(*) FROM izin_pembimbing_penetapan t JOIN izin_pengajuan p ON p.id = t.pengajuan_id WHERE p.asal_pengajuan <> 'Wali'") === 0, 'Penetapan pembimbing hanya untuk pengajuan wali');
    $menahan = "'Diajukan','Perlu Penetapan Admin','Disetujui','Menunggu Pembimbing','Perlu Penetapan Pembimbing','Perlu Perbaikan Wali'";
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan a JOIN izin_pengajuan b ON b.santri_id = a.santri_id AND b.id > a.id
        WHERE a.status IN ($menahan) AND b.status IN ($menahan) AND a.tgl_izin <= b.tgl_kembali AND a.tgl_kembali >= b.tgl_izin
          AND (a.asal_pengajuan = 'Wali' OR b.asal_pengajuan = 'Wali')") === 0, 'Tidak ada dua pengajuan aktif bertumpang tindih yang melibatkan pengajuan wali (lintas jalur)');
    $periksa($n("SELECT COUNT(*) FROM izin_idempotency_keys WHERE operation LIKE 'izin.wali.%' AND (completed_at IS NULL OR pengajuan_id IS NULL)") === 0, 'Seluruh klaim idempotensi V7 selesai dan menunjuk pengajuan');
    $periksa($n("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'izin\\_wali\\_%'
        AND CONCAT(COALESCE(before_json, ''), COALESCE(after_json, '')) REGEXP '\"(alasan|catatan|tgl_izin|tgl_kembali)\"'") === 0,
        'Audit V7 tanpa alasan, catatan, atau tanggal rinci');
}

if ($manifestPath !== null) {
    $manifest = json_decode((string) @file_get_contents($manifestPath), true);
    if (!is_array($manifest) || !is_array($manifest['legacy_counts'] ?? null) || !is_array($manifest['id_fingerprints'] ?? null)) {
        $periksa(false, 'Manifest tidak terbaca: ' . $manifestPath);
    } else {
        $kini = v7f1_snapshot($db);
        $periksa((string) ($manifest['database'] ?? '') === (string) v7f1_nilai($db, 'SELECT DATABASE()'), 'Manifest milik basis data yang sama');
        // Jangan mempercayai daftar pemeriksaan dari manifest yang terpotong:
        // daftar kosong sebelumnya lolos tanpa membandingkan satu pun hash.
        $lengkap = true;
        foreach (['legacy_counts', 'id_fingerprints'] as $jenis) {
            $lengkap = $lengkap && array_diff_key($kini[$jenis], $manifest[$jenis]) === []
                && array_diff_key($manifest[$jenis], $kini[$jenis]) === [];
        }
        foreach ($manifest['legacy_counts'] as $jumlah) {
            $lengkap = $lengkap && is_int($jumlah) && $jumlah >= 0;
        }
        foreach ($manifest['id_fingerprints'] as $hash) {
            $lengkap = $lengkap && is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1;
        }
        $periksa($lengkap, 'Manifest memuat daftar jumlah dan sidik jari lengkap, termasuk isi riwayat V2');
        $beda = [];
        foreach ($manifest['legacy_counts'] as $tabel => $jumlah) {
            if (($kini['legacy_counts'][$tabel] ?? null) !== $jumlah) {
                $beda[] = $tabel . ' ' . $jumlah . '→' . ($kini['legacy_counts'][$tabel] ?? 'hilang');
            }
        }
        $periksa($beda === [], 'Jumlah baris ' . count($manifest['legacy_counts']) . ' tabel lama sama dengan manifest' . ($beda === [] ? '' : ': ' . implode(', ', $beda)));
        foreach ($manifest['id_fingerprints'] as $nama => $hash) {
            $periksa(($kini['id_fingerprints'][$nama] ?? null) === $hash, 'Sidik jari ' . $nama . ' sama dengan manifest');
        }
    }
}
$periksa($n("SELECT COUNT(*) FROM santri_wali sw LEFT JOIN santri s ON s.id = sw.santri_id LEFT JOIN wali w ON w.id = sw.wali_id WHERE s.id IS NULL OR w.id IS NULL") === 0, 'Relasi santri–wali tanpa referensi yatim');
$periksa($n('SELECT COUNT(*) FROM izin_pengajuan p LEFT JOIN santri s ON s.id = p.santri_id WHERE s.id IS NULL') === 0, 'Pengajuan izin tanpa santri yatim');
$periksa($n('SELECT COUNT(*) FROM izin_riwayat_status r LEFT JOIN izin_pengajuan p ON p.id = r.pengajuan_id WHERE p.id IS NULL') === 0, 'Riwayat izin tanpa pengajuan yatim');
echo $gagal === 0 ? "Verifikasi V7 Fase 1 lulus.\n" : "Verifikasi V7 Fase 1 GAGAL: {$gagal} pemeriksaan.\n";
exit($gagal > 0 ? 1 : 0);
