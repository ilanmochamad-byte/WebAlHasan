<?php

declare(strict_types=1);

/**
 * Verifier PRD V7 Fase 2 (migrasi 031). Hanya membaca.
 *
 *   php bin/v7_phase2_verify.php [manifest.json]             # sesudah migrasi
 *   php bin/v7_phase2_verify.php --rollback [manifest.json]  # sesudah rollback 031
 *
 * Menggantikan `bin/v7_phase1_verify.php` sesudah 031 terpasang: invarians
 * Fase 1 dipertahankan kecuali "versi = 1 + penetapan", yang kini menjadi
 * "versi = 1 + jumlah peristiwa yang menaikkan versi". Ditambah invarians
 * Fase 2: setiap tinjauan/perbaikan/pembatalan wali punya riwayat dan audit;
 * perbaikan menjawab tepat satu permintaan perbaikan pada versi sesudahnya;
 * `Ditolak Pembimbing` selalu berasal dari tinjauan Tolak dan TIDAK punya
 * `izin_keputusan`; baris wali berstatus V2 selalu diteruskan pembimbing;
 * keputusan hanya untuk baris wali yang diteruskan; pembatalan wali oleh
 * wali pengaju dan tanpa keputusan; tidak ada dua pengajuan aktif yang
 * bertumpang tindih bila melibatkan baris wali.
 * Sesudah rollback: tabel 031 hilang, 031 tidak tercatat, skema 030 utuh.
 * Dengan manifest: jumlah baris tabel lama dan sidik jari ID/isi identik.
 */

require_once __DIR__ . '/_v7_phase2.php';

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
$tercatat = static fn (string $migrasi): int => $n("SELECT COUNT(*) FROM schema_migrations WHERE migration = '$migrasi'");

if ($rollback) {
    foreach (V7F2_TABEL as $tabel) {
        $periksa(!v7f1_ada_tabel($db, $tabel), 'Tabel ' . $tabel . ' sudah dilepas');
    }
    $periksa($tercatat(V7F2_MIGRASI) === 0, 'Migrasi 031 tidak lagi tercatat');
    $periksa($tercatat('030_v7_fase1_pengajuan_wali.sql') === 1 && v7f1_ada_kolom($db, 'izin_pengajuan', 'asal_pengajuan')
        && v7f1_ada_tabel($db, 'izin_pembimbing_penetapan'), 'Skema dan catatan migrasi 030 tetap utuh');
} else {
    $periksa($tercatat('030_v7_fase1_pengajuan_wali.sql') === 1 && $tercatat(V7F2_MIGRASI) === 1, 'Migrasi 030 dan 031 tercatat');
    foreach (V7F2_TABEL as $tabel) {
        $periksa((string) v7f1_nilai($db, "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tabel'") === 'InnoDB', 'Tabel ' . $tabel . ' InnoDB');
    }
    $periksa($n("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pembimbing_tinjauan'") === 5, 'Lima kunci asing tabel tinjauan');
    $periksa($n("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_wali_perbaikan'") === 4, 'Empat kunci asing tabel perbaikan');
    $periksa($n("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'
        AND CONSTRAINT_NAME IN ('izin_pembimbing_tinjauan_versi_check', 'izin_pembimbing_tinjauan_hash_check', 'izin_pembimbing_tinjauan_alasan_check',
            'izin_pembimbing_tinjauan_status_check', 'izin_wali_perbaikan_versi_check', 'izin_wali_perbaikan_rentang_check',
            'izin_wali_perbaikan_alasan_check', 'izin_wali_perbaikan_berubah_check')") === 8, 'Delapan CHECK tabel 031 terpasang');
    $periksa($n("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
        AND CONSTRAINT_NAME IN ('izin_pengajuan_v7_wali_fk', 'izin_pengajuan_v7_pembimbing_fk', 'izin_pengajuan_v7_penetap_fk', 'izin_pengajuan_v7_asal_check')") === 4,
        'Kunci asing dan CHECK asal 030 tetap terpasang');

    $v2 = v7f2_in(V7F1_STATUS_V2);
    $v7 = v7f2_in(V7F1_STATUS_V7);
    echo '[info] Pengajuan wali per status: ' . json_encode($db->query("SELECT status, COUNT(*) AS n FROM izin_pengajuan WHERE asal_pengajuan = 'Wali' GROUP BY status")->fetch_all(MYSQLI_ASSOC))
        . '; tinjauan ' . $n('SELECT COUNT(*) FROM izin_pembimbing_tinjauan') . '; perbaikan ' . $n('SELECT COUNT(*) FROM izin_wali_perbaikan') . PHP_EOL;

    // --- Invarians Fase 1 (dipertahankan) ---------------------------------
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan = 'Wali' AND (wali_id IS NULL OR pengurus_id IS NOT NULL OR is_legacy <> 0)") === 0, 'Baris wali selalu berwali, tanpa pengurus pengaju, bukan warisan');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan = 'Pengurus' AND (wali_id IS NOT NULL OR status NOT IN ($v2))") === 0, 'Baris pengurus/warisan tanpa wali_id dan tetap berstatus V2');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan WHERE status IN ($v7) AND (murobi_guru_id IS NOT NULL OR asal_pengajuan <> 'Wali')") === 0, 'Tahap pembimbing V7 tidak pernah memiliki murobi tujuan');
    $periksa($n("SELECT COUNT(*) FROM izin_keputusan k JOIN izin_pengajuan p ON p.id = k.pengajuan_id WHERE p.status IN ($v7)") === 0, 'Tidak ada izin_keputusan untuk pengajuan di tahap pembimbing, termasuk Ditolak Pembimbing');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan WHERE status = 'Menunggu Pembimbing' AND (pembimbing_pengurus_id IS NULL OR pembimbing_assignment_id IS NULL)") === 0, 'Setiap Menunggu Pembimbing memiliki pembimbing dan penugasan tujuan');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan WHERE status = 'Perlu Penetapan Pembimbing' AND pembimbing_pengurus_id IS NOT NULL") === 0, 'Perlu Penetapan Pembimbing belum memiliki pembimbing tujuan');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan p WHERE p.asal_pengajuan = 'Wali' AND NOT EXISTS (SELECT 1 FROM izin_riwayat_status r WHERE r.pengajuan_id = p.id AND r.peristiwa = 'pengajuan_wali_dibuat')") === 0, 'Setiap pengajuan wali memiliki riwayat pembuatan');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan p WHERE p.asal_pengajuan = 'Wali' AND NOT EXISTS (SELECT 1 FROM audit_logs a WHERE a.entity_type = 'izin_pengajuan' AND a.entity_id = p.id AND a.action = 'izin_wali_pengajuan_dibuat')") === 0, 'Setiap pengajuan wali memiliki audit pembuatan');
    $periksa($n("SELECT COUNT(*) FROM izin_pembimbing_penetapan t JOIN izin_pengajuan p ON p.id = t.pengajuan_id WHERE p.asal_pengajuan <> 'Wali'") === 0, 'Penetapan pembimbing hanya untuk pengajuan wali');
    $menahan = "'Diajukan','Perlu Penetapan Admin','Disetujui','Menunggu Pembimbing','Perlu Penetapan Pembimbing','Perlu Perbaikan Wali'";
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan a JOIN izin_pengajuan b ON b.santri_id = a.santri_id AND b.id > a.id
        WHERE a.status IN ($menahan) AND b.status IN ($menahan) AND a.tgl_izin <= b.tgl_kembali AND a.tgl_kembali >= b.tgl_izin
          AND (a.asal_pengajuan = 'Wali' OR b.asal_pengajuan = 'Wali')") === 0, 'Tidak ada dua pengajuan aktif bertumpang tindih yang melibatkan pengajuan wali (lintas jalur)');
    $periksa($n("SELECT COUNT(*) FROM izin_idempotency_keys WHERE operation LIKE 'izin.wali.%' AND (completed_at IS NULL OR pengajuan_id IS NULL)") === 0, 'Seluruh klaim idempotensi V7 selesai dan menunjuk pengajuan');
    $periksa($n("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'izin\\_wali\\_%'
        AND CONCAT(COALESCE(before_json, ''), COALESCE(after_json, '')) REGEXP '\"(alasan|catatan|tgl_izin|tgl_kembali)\"'") === 0,
        'Audit V7 tanpa alasan, catatan, atau tanggal rinci');

    // --- Versi dan jejak (Fase 2) -----------------------------------------
    $peristiwaVersi = v7f2_in(V7F2_PERISTIWA_VERSI);
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan p WHERE p.asal_pengajuan = 'Wali'
        AND p.version <> 1 + (SELECT COUNT(*) FROM izin_riwayat_status r WHERE r.pengajuan_id = p.id AND r.peristiwa IN ($peristiwaVersi))") === 0,
        'Versi setiap pengajuan wali = 1 + jumlah peristiwa yang menaikkan versi (tidak ada perubahan tanpa riwayat)');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan p WHERE p.asal_pengajuan = 'Wali'
        AND (SELECT COUNT(*) FROM izin_pembimbing_penetapan t WHERE t.pengajuan_id = p.id)
            <> (SELECT COUNT(*) FROM izin_riwayat_status r WHERE r.pengajuan_id = p.id AND r.peristiwa IN ('pembimbing_ditetapkan', 'pembimbing_ditetapkan_ulang'))") === 0,
        'Jumlah penetapan pembimbing = jumlah riwayat penetapan per pengajuan');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan p WHERE p.asal_pengajuan = 'Wali'
        AND (SELECT COUNT(*) FROM izin_pembimbing_tinjauan t WHERE t.pengajuan_id = p.id)
            <> (SELECT COUNT(*) FROM izin_riwayat_status r WHERE r.pengajuan_id = p.id AND r.peristiwa IN ('tinjauan_minta_perbaikan', 'tinjauan_ditolak_pembimbing', 'tinjauan_diteruskan'))") === 0,
        'Jumlah tinjauan = jumlah riwayat tinjauan per pengajuan');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan p WHERE p.asal_pengajuan = 'Wali'
        AND (SELECT COUNT(*) FROM izin_pembimbing_tinjauan t WHERE t.pengajuan_id = p.id)
            <> (SELECT COUNT(*) FROM audit_logs a WHERE a.entity_type = 'izin_pengajuan' AND a.entity_id = p.id
                 AND a.action IN ('izin_wali_diminta_perbaikan', 'izin_wali_ditolak_pembimbing', 'izin_wali_diteruskan'))") === 0,
        'Setiap tinjauan memiliki audit');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan p WHERE p.asal_pengajuan = 'Wali'
        AND (SELECT COUNT(*) FROM izin_wali_perbaikan w WHERE w.pengajuan_id = p.id)
            <> (SELECT COUNT(*) FROM izin_riwayat_status r WHERE r.pengajuan_id = p.id AND r.peristiwa = 'perbaikan_wali_dikirim')
         OR (SELECT COUNT(*) FROM izin_wali_perbaikan w WHERE w.pengajuan_id = p.id)
            <> (SELECT COUNT(*) FROM audit_logs a WHERE a.entity_type = 'izin_pengajuan' AND a.entity_id = p.id AND a.action = 'izin_wali_perbaikan_dikirim')") === 0,
        'Setiap perbaikan wali memiliki riwayat dan audit');
    $periksa($n("SELECT COUNT(*) FROM izin_wali_perbaikan w LEFT JOIN izin_pembimbing_tinjauan t ON t.id = w.tinjauan_id
        LEFT JOIN izin_pengajuan p ON p.id = w.pengajuan_id
        WHERE t.id IS NULL OR t.pengajuan_id <> w.pengajuan_id OR t.tindakan <> 'Minta Perbaikan' OR w.versi_sebelum <> t.versi_sesudah
           OR p.wali_id <> w.wali_id OR p.diajukan_oleh_user_id <> w.dikirim_oleh_user_id") === 0,
        'Perbaikan menjawab permintaan perbaikan pengajuan yang sama pada versi sesudahnya, oleh wali dan akun pengaju');
    $periksa($n("SELECT COUNT(*) FROM izin_riwayat_status WHERE peristiwa = 'perbaikan_wali_dikirim' AND alasan IS NOT NULL") === 0, 'Riwayat perbaikan tidak menyalin isi izin');
    $periksa($n("SELECT COUNT(*) FROM izin_pembimbing_tinjauan t JOIN pembimbing_assignments pa ON pa.id = t.pembimbing_assignment_id
        JOIN izin_pengajuan p ON p.id = t.pengajuan_id WHERE pa.pengurus_id <> t.pembimbing_pengurus_id OR p.asal_pengajuan <> 'Wali'") === 0,
        'Tinjauan hanya pada pengajuan wali, oleh pengurus pemilik penugasan yang dipakai');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan p WHERE p.status = 'Perlu Perbaikan Wali'
        AND NOT EXISTS (SELECT 1 FROM izin_pembimbing_tinjauan t WHERE t.pengajuan_id = p.id AND t.tindakan = 'Minta Perbaikan'
                         AND t.versi_sesudah = p.version AND NOT EXISTS (SELECT 1 FROM izin_wali_perbaikan w WHERE w.tinjauan_id = t.id))") === 0,
        'Setiap Perlu Perbaikan Wali memiliki permintaan perbaikan terbuka pada versi saat ini');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan p WHERE p.status = 'Ditolak Pembimbing'
        AND NOT EXISTS (SELECT 1 FROM izin_pembimbing_tinjauan t WHERE t.pengajuan_id = p.id AND t.tindakan = 'Tolak' AND t.versi_sesudah = p.version)") === 0,
        'Setiap Ditolak Pembimbing berasal dari tinjauan Tolak pada versi saat ini');
    $periksa($n("SELECT COUNT(*) FROM izin_pembimbing_tinjauan WHERE tindakan = 'Tolak'") === $n("SELECT COUNT(*) FROM izin_pengajuan WHERE status = 'Ditolak Pembimbing'"),
        'Tolak pembimbing selalu terminal (jumlah tinjauan Tolak = jumlah Ditolak Pembimbing)');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan p WHERE p.asal_pengajuan = 'Wali' AND p.status IN ('Diajukan', 'Perlu Penetapan Admin', 'Disetujui', 'Ditolak')
        AND (SELECT COUNT(*) FROM izin_pembimbing_tinjauan t WHERE t.pengajuan_id = p.id AND t.tindakan = 'Teruskan') <> 1") === 0,
        'Baris wali berstatus V2 selalu diteruskan pembimbing tepat satu kali (tanpa pengajuan kedua)');
    $periksa($n("SELECT COUNT(*) FROM izin_pembimbing_tinjauan t JOIN izin_pengajuan p ON p.id = t.pengajuan_id
        WHERE t.tindakan = 'Teruskan' AND p.routing_pada IS NULL") === 0, 'Setiap penerusan menjalankan routing murobi V2 pada baris yang sama');
    $periksa($n("SELECT COUNT(*) FROM izin_keputusan k JOIN izin_pengajuan p ON p.id = k.pengajuan_id
        WHERE p.asal_pengajuan = 'Wali' AND NOT EXISTS (SELECT 1 FROM izin_pembimbing_tinjauan t WHERE t.pengajuan_id = p.id AND t.tindakan = 'Teruskan')") === 0,
        'Keputusan murobi/admin pengganti hanya untuk pengajuan wali yang diteruskan');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan p WHERE p.asal_pengajuan = 'Wali' AND p.status IN ('Disetujui', 'Ditolak')
        AND (SELECT COUNT(*) FROM izin_keputusan k WHERE k.pengajuan_id = p.id) <> 1") === 0, 'Pengajuan wali yang diputus memiliki tepat satu keputusan efektif');
    $periksa($n("SELECT COUNT(*) FROM izin_pengajuan p WHERE p.asal_pengajuan = 'Wali' AND p.status = 'Dibatalkan'
        AND (p.dibatalkan_oleh_user_id IS NULL OR p.dibatalkan_oleh_user_id <> p.diajukan_oleh_user_id OR p.alasan_pembatalan IS NULL
             OR EXISTS (SELECT 1 FROM izin_keputusan k WHERE k.pengajuan_id = p.id)
             OR (SELECT COUNT(*) FROM izin_riwayat_status r WHERE r.pengajuan_id = p.id AND r.peristiwa = 'pembatalan_wali') <> 1
             OR (SELECT COUNT(*) FROM audit_logs a WHERE a.entity_type = 'izin_pengajuan' AND a.entity_id = p.id AND a.action = 'izin_wali_dibatalkan') <> 1)") === 0,
        'Pembatalan wali oleh akun pengaju, beralasan, tepat satu riwayat dan audit, tanpa keputusan');
    $periksa($n("SELECT COUNT(*) FROM izin_riwayat_status r JOIN izin_pengajuan p ON p.id = r.pengajuan_id
        WHERE r.peristiwa = 'pembatalan' AND p.asal_pengajuan = 'Wali'") === 0, 'Pembatalan V2 (pengurus/admin) tidak pernah terjadi pada pengajuan wali');
    $periksa($n("SELECT COUNT(*) FROM izin_idempotency_keys k JOIN izin_pengajuan p ON p.id = k.pengajuan_id
        WHERE k.operation IN (" . v7f2_in(V7F2_OPERASI) . ") AND p.asal_pengajuan <> 'Wali'") === 0, 'Klaim idempotensi Fase 2 hanya menunjuk pengajuan wali');
}

if ($manifestPath !== null) {
    $manifest = json_decode((string) @file_get_contents($manifestPath), true);
    if (!is_array($manifest) || !is_array($manifest['legacy_counts'] ?? null) || !is_array($manifest['id_fingerprints'] ?? null)) {
        $periksa(false, 'Manifest tidak terbaca: ' . $manifestPath);
    } else {
        $kini = v7f2_snapshot($db);
        $periksa((string) ($manifest['database'] ?? '') === (string) v7f1_nilai($db, 'SELECT DATABASE()'), 'Manifest milik basis data yang sama');
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
$periksa($n('SELECT COUNT(*) FROM santri_wali sw LEFT JOIN santri s ON s.id = sw.santri_id LEFT JOIN wali w ON w.id = sw.wali_id WHERE s.id IS NULL OR w.id IS NULL') === 0, 'Relasi santri–wali tanpa referensi yatim');
$periksa($n('SELECT COUNT(*) FROM izin_pengajuan p LEFT JOIN santri s ON s.id = p.santri_id WHERE s.id IS NULL') === 0, 'Pengajuan izin tanpa santri yatim');
$periksa($n('SELECT COUNT(*) FROM izin_riwayat_status r LEFT JOIN izin_pengajuan p ON p.id = r.pengajuan_id WHERE p.id IS NULL') === 0, 'Riwayat izin tanpa pengajuan yatim');
$periksa($n('SELECT COUNT(*) FROM izin_keputusan k LEFT JOIN izin_pengajuan p ON p.id = k.pengajuan_id WHERE p.id IS NULL') === 0, 'Keputusan izin tanpa pengajuan yatim');
echo $gagal === 0 ? "Verifikasi V7 Fase 2 lulus.\n" : "Verifikasi V7 Fase 2 GAGAL: {$gagal} pemeriksaan.\n";
exit($gagal > 0 ? 1 : 0);
