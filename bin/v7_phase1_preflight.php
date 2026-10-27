<?php

declare(strict_types=1);

/**
 * Preflight PRD V7 Fase 1 (migrasi 030, pengajuan wali). Hanya membaca.
 *
 *   php bin/v7_phase1_preflight.php [manifest.json]
 *
 * Memeriksa versi server (CHECK ditegakkan), InnoDB, binlog yang cocok untuk
 * transaksi READ COMMITTED, tabel induk kunci asing 030, bahwa migrasi 029
 * sudah tercatat, ENUM status V2 masih lima nilai lama berurutan, tidak ada
 * baris yang akan melanggar CHECK 030, lalu menulis manifest jumlah baris dan
 * sidik jari ID/isi (santri, wali, relasi, users, perizinan warisan,
 * pengajuan/keputusan V2, penugasan) untuk `bin/v7_phase1_verify.php`.
 */

require_once __DIR__ . '/_v7_phase1.php';

$db = app_db();
$gagal = 0;
$periksa = static function (bool $ok, string $label) use (&$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    if (!$ok) {
        $gagal++;
    }
};
$nama = (string) v7f1_nilai($db, 'SELECT DATABASE()');
echo '[info] Basis data terpilih: ' . $nama . ' (preflight hanya membaca; migrasi produksi memerlukan otorisasi operator).' . PHP_EOL;

$versi = (string) v7f1_nilai($db, 'SELECT VERSION()');
$maria = stripos($versi, 'mariadb') !== false;
preg_match('/^(\d+)\.(\d+)\.(\d+)/', $versi, $v);
$angka = isset($v[1]) ? [(int) $v[1], (int) $v[2], (int) $v[3]] : [0, 0, 0];
$periksa($maria ? $angka >= [10, 2, 1] : $angka >= [8, 0, 16], 'Server ' . $versi . ' menegakkan CHECK (konsistensi asal dan penjaga rollback bergantung padanya)');
$binlog = $db->query('SELECT @@log_bin AS aktif, @@binlog_format AS format')->fetch_assoc();
$periksa(!((int) $binlog['aktif'] === 1 && strtoupper((string) $binlog['format']) === 'STATEMENT'),
    'Binary log ' . ((int) $binlog['aktif'] === 1 ? 'aktif, format ' . $binlog['format'] : 'tidak aktif') . ' — kompatibel dengan transaksi READ COMMITTED pengajuan wali');
$periksa(in_array((string) v7f1_nilai($db, "SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'"), ['YES', 'DEFAULT'], true), 'Mesin InnoDB tersedia');

foreach (['izin_pengajuan', 'izin_riwayat_status', 'izin_idempotency_keys', 'izin_keputusan', 'perizinan', 'users', 'user_roles', 'roles',
    'pengurus', 'wali', 'santri', 'santri_wali', 'pembimbing_assignments', 'murobi_assignments', 'tahun_ajaran', 'audit_logs', 'schema_migrations'] as $tabel) {
    $periksa(v7f1_ada_tabel($db, $tabel), 'Tabel ' . $tabel);
}
$periksa((int) v7f1_nilai($db, "SELECT COUNT(*) FROM schema_migrations WHERE migration = '029_v6_fase3_tabungan.sql'") === 1, 'Migrasi 029 tercatat');
$periksa((int) v7f1_nilai($db, "SELECT COUNT(*) FROM roles WHERE slug IN ('admin', 'pengurus', 'orang_tua')") === 3, 'Role dasar admin, pengurus, dan orang tua tersedia (tanpa role baru)');

$tipe = (string) v7f1_nilai($db, "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'status'");
$v2 = "enum('" . implode("','", V7F1_STATUS_V2) . "'";
$terpasang = v7f1_ada_kolom($db, 'izin_pengajuan', 'asal_pengajuan');
$periksa(str_starts_with($tipe, $v2), 'ENUM status diawali lima nilai V2 berurutan (' . $tipe . ')');
echo '[info] Migrasi 030 ' . ($terpasang ? 'sudah terpasang (idempoten; pemeriksaan INFORMATION_SCHEMA)' : 'belum dijalankan') . PHP_EOL;
if (!$terpasang) {
    // CHECK 030 untuk baris lama: status harus salah satu nilai V2.
    $luar = (int) v7f1_nilai($db, "SELECT COUNT(*) FROM izin_pengajuan WHERE status NOT IN ('" . implode("','", V7F1_STATUS_V2) . "')");
    $periksa($luar === 0, 'Seluruh baris izin_pengajuan berstatus V2 (' . $luar . ' baris di luar) — CHECK 030 dapat dipasang');
}
$tanpaAkun = (int) v7f1_nilai($db, "SELECT COUNT(DISTINCT pa.pengurus_id) FROM pembimbing_assignments pa
    JOIN tahun_ajaran ta ON ta.id = pa.tahun_ajaran_id AND ta.status = 'Aktif' AND ta.archived_at IS NULL
    WHERE pa.is_active = 1 AND pa.archived_at IS NULL AND (pa.tanggal_selesai IS NULL OR pa.tanggal_selesai >= CURDATE())
      AND NOT EXISTS (SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id AND r.slug = 'pengurus'
                       WHERE u.pengurus_id = pa.pengurus_id AND u.is_active = 1)");
echo '[info] Pengurus berpenugasan pembimbing aktif tanpa akun pengurus aktif: ' . $tanpaAkun
    . ($tanpaAkun === 0 ? '' : ' — santri binaannya tidak memiliki pembimbing SAH; pengajuan wali mereka masuk antrean admin sampai akun dibuat.') . PHP_EOL;

$manifest = json_encode(['database' => $nama, 'captured_at' => date(DATE_ATOM)] + v7f1_snapshot($db), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (isset($argv[1])) {
    file_put_contents($argv[1], $manifest . PHP_EOL);
    echo 'Manifest ditulis: ' . $argv[1] . PHP_EOL;
} else {
    echo $manifest . PHP_EOL;
}
echo "Backup DB terverifikasi dan manifest ini wajib disimpan sebelum migrasi.\n";
exit($gagal > 0 ? 1 : 0);
