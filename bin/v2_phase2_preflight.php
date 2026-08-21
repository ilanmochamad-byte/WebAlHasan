<?php

declare(strict_types=1);

use App\Database\BackupWriter;

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Preflight V2 Fase 2.
 *
 * Menghasilkan, sebelum migrasi 007 dijalankan:
 *   - backup basis data lengkap + manifest jumlah baris,
 *   - inventaris kolom tabel perizinan V2 yang akan ditambah,
 *   - laporan konflik data yang dapat mengganggu alur Fase 2.
 *
 * Kode keluar:
 *   0 = aman dilanjutkan,
 *   3 = ditemukan konflik yang memblokir (harus diselesaikan lebih dulu),
 *   2 = kesalahan lingkungan.
 *
 * Migrasi 007 bersifat aditif dan dapat dijalankan ulang, tetapi backup tetap
 * WAJIB: rollback melepas kolom jejak dan tabel koreksi beserta isinya.
 */

$base = APP_ROOT . '/storage/backups/v2-phase2/' . date('Ymd_His');
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $base = rtrim(substr($argument, 9), '/');
    }
}
if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
    throw new RuntimeException('Direktori output tidak dapat dibuat: ' . $base);
}

$db = app_db();

// ---------------------------------------------------------------------------
// 1. Prasyarat: Fase 1 harus sudah terpasang.
// ---------------------------------------------------------------------------
$prasyarat = ['perizinan', 'izin_pengajuan', 'izin_keputusan', 'izin_riwayat_status', 'izin_idempotency_keys', 'pembimbing_assignments', 'murobi_assignments'];
$hilang = [];
foreach ($prasyarat as $table) {
    if (($db->query('SHOW TABLES LIKE ' . "'" . $db->real_escape_string($table) . "'")?->num_rows ?? 0) !== 1) {
        $hilang[] = $table;
    }
}
if ($hilang !== []) {
    fwrite(STDERR, 'Migrasi V2 Fase 1 belum lengkap. Tabel hilang: ' . implode(', ', $hilang) . PHP_EOL);
    exit(2);
}

// ---------------------------------------------------------------------------
// 2. Inventaris kolom sebelum migrasi.
// ---------------------------------------------------------------------------
$inventory = [];
foreach (['izin_pengajuan', 'izin_keputusan'] as $table) {
    $columns = $db->query('SHOW COLUMNS FROM `' . $table . '`');
    $rows = [];
    while ($columns && $column = $columns->fetch_assoc()) {
        $rows[] = ['field' => $column['Field'], 'type' => $column['Type'], 'null' => $column['Null']];
    }
    $inventory[$table] = $rows;
}
file_put_contents($base . '/inventory.json', json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// ---------------------------------------------------------------------------
// 3. Backup + manifest jumlah baris.
// ---------------------------------------------------------------------------
$counts = (new BackupWriter($db))->write($base . '/database.sql');

$legacyIds = [];
$result = $db->query('SELECT id FROM perizinan ORDER BY id');
while ($result && $row = $result->fetch_assoc()) {
    $legacyIds[] = (int) $row['id'];
}
$manifest = [
    'generated_at' => date(DATE_ATOM),
    'database' => app_config('database.database'),
    'phase' => 'v2-phase2',
    'row_counts' => $counts,
    'legacy_perizinan' => ['total' => count($legacyIds), 'ids' => $legacyIds],
];
file_put_contents($base . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// ---------------------------------------------------------------------------
// 4. Laporan konflik.
// ---------------------------------------------------------------------------
$scalar = static function (string $sql) use ($db): int {
    $result = $db->query($sql);
    return $result ? (int) array_values($result->fetch_assoc())[0] : -1;
};

$blocking = [];
$warnings = [];

// (a) Keputusan Admin Pengganti tanpa alasan penggantian — melanggar aturan PRD.
$adminTanpaAlasan = $scalar(
    "SELECT COUNT(*) FROM izin_keputusan
      WHERE kapasitas = 'Admin Pengganti'
        AND (alasan_penggantian IS NULL OR CHAR_LENGTH(TRIM(alasan_penggantian)) = 0)"
);
if ($adminTanpaAlasan > 0) {
    $blocking[] = "Keputusan 'Admin Pengganti' tanpa alasan penggantian: {$adminTanpaAlasan} baris.";
}

// (b) Lebih dari satu keputusan untuk satu pengajuan — seharusnya mustahil.
$keputusanGanda = $scalar(
    'SELECT COUNT(*) FROM (SELECT pengajuan_id FROM izin_keputusan GROUP BY pengajuan_id HAVING COUNT(*) > 1) x'
);
if ($keputusanGanda > 0) {
    $blocking[] = "Pengajuan dengan lebih dari satu keputusan: {$keputusanGanda}.";
}

// (c) Pengajuan V2 aktif yang rentangnya tumpang tindih untuk santri yang sama.
//     Tidak memblokir migrasi (skema tidak memasang batasan unik untuk rentang),
//     tetapi wajib diketahui admin karena alur baru menolak kasus seperti ini.
$tumpangTindih = $scalar(
    "SELECT COUNT(*) FROM izin_pengajuan a
       JOIN izin_pengajuan b
         ON b.santri_id = a.santri_id AND b.id > a.id
        AND a.tgl_izin <= b.tgl_kembali AND a.tgl_kembali >= b.tgl_izin
      WHERE a.status IN ('Diajukan','Perlu Penetapan Admin','Disetujui')
        AND b.status IN ('Diajukan','Perlu Penetapan Admin','Disetujui')"
);
if ($tumpangTindih > 0) {
    $warnings[] = "Pasangan pengajuan aktif dengan rentang tumpang tindih (data lama): {$tumpangTindih}.";
}

// (d) Pengajuan V2 non-warisan tanpa murobi tetapi tidak berada di antrean admin.
$routingTidakKonsisten = $scalar(
    "SELECT COUNT(*) FROM izin_pengajuan
      WHERE is_legacy = 0 AND murobi_guru_id IS NULL AND status = 'Diajukan'"
);
if ($routingTidakKonsisten > 0) {
    $warnings[] = "Pengajuan berstatus 'Diajukan' tanpa murobi tujuan: {$routingTidakKonsisten} (akan tampak pada antrean admin setelah ditinjau).";
}

// (e) Tahun ajaran aktif wajib ada agar routing dapat berjalan.
$tahunAktif = $scalar("SELECT COUNT(*) FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL");
if ($tahunAktif !== 1) {
    $warnings[] = "Tahun ajaran aktif berjumlah {$tahunAktif} (seharusnya 1). Routing tidak akan menemukan kandidat murobi.";
}

// (f) Kesiapan routing: guru dengan penugasan murobi aktif.
$murobiAktif = $scalar(
    "SELECT COUNT(DISTINCT ma.guru_id) FROM murobi_assignments ma
       JOIN guru g ON g.id = ma.guru_id AND g.is_active = 1 AND g.archived_at IS NULL
       JOIN tahun_ajaran ta ON ta.id = ma.tahun_ajaran_id AND ta.status = 'Aktif' AND ta.archived_at IS NULL
      WHERE ma.is_active = 1 AND ma.archived_at IS NULL
        AND ma.tanggal_mulai <= CURDATE()
        AND (ma.tanggal_selesai IS NULL OR ma.tanggal_selesai >= CURDATE())"
);
if ($murobiAktif === 0) {
    $warnings[] = 'Tidak ada guru dengan penugasan murobi aktif: seluruh pengajuan baru akan masuk antrean penetapan admin.';
}

// (g) Kesiapan cakupan pengurus.
$pembimbingAktif = $scalar(
    "SELECT COUNT(*) FROM pembimbing_assignments pa
       JOIN tahun_ajaran ta ON ta.id = pa.tahun_ajaran_id AND ta.status = 'Aktif' AND ta.archived_at IS NULL
      WHERE pa.is_active = 1 AND pa.archived_at IS NULL
        AND pa.tanggal_mulai <= CURDATE()
        AND (pa.tanggal_selesai IS NULL OR pa.tanggal_selesai >= CURDATE())"
);
if ($pembimbingAktif === 0) {
    $warnings[] = 'Tidak ada penugasan pembimbing aktif: pengurus belum dapat mengajukan izin untuk santri mana pun.';
}

$report = [
    'generated_at' => date(DATE_ATOM),
    'blocking' => $blocking,
    'warnings' => $warnings,
    'readiness' => [
        'tahun_ajaran_aktif' => $tahunAktif,
        'guru_murobi_aktif' => $murobiAktif,
        'penugasan_pembimbing_aktif' => $pembimbingAktif,
        'pengajuan_v2' => $scalar('SELECT COUNT(*) FROM izin_pengajuan WHERE is_legacy = 0'),
        'pengajuan_warisan' => $scalar('SELECT COUNT(*) FROM izin_pengajuan WHERE is_legacy = 1'),
    ],
];
file_put_contents($base . '/conflicts.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo 'Preflight V2 Fase 2 selesai. Output: ' . $base . PHP_EOL;
echo '- database.sql, manifest.json, inventory.json, conflicts.json' . PHP_EOL;
foreach ($report['readiness'] as $label => $value) {
    echo '  · ' . str_pad((string) $label, 28) . ': ' . $value . PHP_EOL;
}
foreach ($warnings as $warning) {
    echo '[perhatian] ' . $warning . PHP_EOL;
}
foreach ($blocking as $problem) {
    echo '[blokir]    ' . $problem . PHP_EOL;
}

if ($blocking !== []) {
    fwrite(STDERR, 'Preflight menemukan konflik yang memblokir. Migrasi 007 TIDAK boleh dijalankan.' . PHP_EOL);
    exit(3);
}

echo 'Aman dilanjutkan: php bin/migrate.php up' . PHP_EOL;
exit(0);
