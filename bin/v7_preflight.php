<?php

declare(strict_types=1);

/**
 * Preflight rilis PRD V7 (Fase 5): migrasi 030–031 sekaligus, atau sisa yang
 * belum terpasang. Hanya membaca.
 *
 *   php bin/v7_preflight.php [manifest-privat.json] [--rilis=docs/phase-v7-5/manifest-migrasi.json]
 *
 * Memeriksa:
 *   - server: CHECK ditegakkan (MariaDB 10.2.1+/MySQL 8.0.16+) pada sesi dan
 *     default global MariaDB, InnoDB, binlog tidak STATEMENT (transaksi READ
 *     COMMITTED pengajuan wali), jam basis data = jam aplikasi (`APP_TIMEZONE`,
 *     dipakai tanggal penugasan aktif dan laporan);
 *   - prasyarat tabel, migrasi 029 (V6) tercatat, status 030–031 berurutan dan
 *     tidak sebagian, tidak ada migrasi > 031;
 *   - sebelum 030: ENUM status V2 masih lima nilai lama berurutan dan seluruh
 *     baris berstatus V2 (CHECK 030 dapat dipasang); tipe kolom induk kunci
 *     asing 031;
 *   - data terdampak: pembimbing aktif tanpa akun pengurus aktif, santri aktif
 *     tanpa pembimbing sah (masuk antrean admin), sakelar Push/WhatsApp;
 *   - dengan `--rilis`: daftar migrasi/rollback lengkap dan SHA-256 berkas di
 *     disk = manifest rilis yang diaudit.
 * Lalu menulis manifest privat (0600): jumlah baris dan hash isi seluruh tabel
 * lama, irisan V2 yang tidak boleh berubah oleh pemakaian V7, serta isi V7
 * bila sudah terpasang — untuk `bin/v7_verify.php`. Hanya hitungan dan hash;
 * simpan di luar document root.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/_v7_rilis.php';

$db = app_db();
$rilis = null;
$tujuan = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--rilis=')) {
        $rilis = substr($a, 8);
    } elseif (!str_starts_with($a, '--')) {
        $tujuan = $a;
    }
}
$gagal = 0;
$periksa = static function (bool $ok, string $label) use (&$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    if (!$ok) {
        $gagal++;
    }
};
$n = static fn (string $sql): int => (int) v7f1_nilai($db, $sql);
$nama = (string) v7f1_nilai($db, 'SELECT DATABASE()');
echo '[info] Basis data terpilih: ' . $nama . ' (preflight hanya membaca; migrasi produksi memerlukan otorisasi operator).' . PHP_EOL;

echo PHP_EOL . '=== Server ===' . PHP_EOL;
$versi = (string) v7f1_nilai($db, 'SELECT VERSION()');
$maria = stripos($versi, 'mariadb') !== false;
preg_match('/^(\d+)\.(\d+)\.(\d+)/', $versi, $v);
$angka = isset($v[1]) ? [(int) $v[1], (int) $v[2], (int) $v[3]] : [0, 0, 0];
$periksa($maria ? $angka >= [10, 2, 1] : $angka >= [8, 0, 16], 'Server ' . $versi . ' menegakkan CHECK (konsistensi asal/tindakan dan penjaga rollback 030–031)');
$periksa(v7f1_check_aktif($db), 'CHECK aktif pada sesi dan default global (MariaDB)');
$binlog = $db->query('SELECT @@log_bin, @@binlog_format')->fetch_row();
$periksa(!((int) $binlog[0] === 1 && strtoupper((string) $binlog[1]) === 'STATEMENT'),
    'Binary log ' . ((int) $binlog[0] === 1 ? 'aktif, format ' . $binlog[1] : 'tidak aktif') . ' — kompatibel dengan transaksi READ COMMITTED');
$periksa(in_array((string) v7f1_nilai($db, "SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'"), ['YES', 'DEFAULT'], true),
    'Mesin InnoDB tersedia (transaksi, kunci baris, snapshot konsisten laporan)');
$jamDb = (string) v7f1_nilai($db, "SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')");
$tz = $db->query('SELECT @@session.time_zone, @@system_time_zone')->fetch_row();
$selisih = abs(strtotime($jamDb) - time());
$periksa($selisih <= 300, 'Jam basis data ' . $jamDb . ' (zona ' . $tz[0] . '/' . $tz[1] . ') = jam aplikasi ' . date('Y-m-d H:i:s') . ' (' . date_default_timezone_get()
    . '), selisih ' . $selisih . ' detik — tanggal penugasan aktif, lama tahap, dan laporan');

echo PHP_EOL . '=== Prasyarat dan status migrasi ===' . PHP_EOL;
foreach (['izin_pengajuan', 'izin_riwayat_status', 'izin_idempotency_keys', 'izin_keputusan', 'perizinan', 'users', 'user_roles', 'roles', 'pengurus', 'guru',
    'wali', 'santri', 'santri_wali', 'pembimbing_assignments', 'murobi_assignments', 'tahun_ajaran', 'plotting_kamar', 'plotting_kelas',
    'notifikasi_outbox', 'perangkat_push', 'pengaturan_notifikasi', 'audit_logs', 'schema_migrations'] as $t) {
    $periksa(v7f1_ada_tabel($db, $t), 'Tabel prasyarat ' . $t);
}
$periksa($n("SELECT COUNT(*) FROM schema_migrations WHERE migration = '029_v6_fase3_tabungan.sql'") === 1, 'Migrasi 029 (V6) tercatat');
$periksa($n("SELECT COUNT(*) FROM roles WHERE slug IN ('admin', 'pengurus', 'orang_tua', 'guru')") === 4, 'Role dasar admin, pengurus, orang_tua, guru tersedia (V7 tidak menambah role)');
$terpasang = v7_terpasang($db);
foreach ($terpasang as $m => $ada) {
    echo '[info] ' . $m . ': ' . ($ada ? 'sudah tercatat' : 'belum') . PHP_EOL;
}
$periksa(!($terpasang[V7_MIGRASI[1]] && !$terpasang[V7_MIGRASI[0]]), 'Migrasi V7 tercatat berurutan (tidak ada 031 tanpa 030)');
$periksa(!in_array(true, $terpasang, true) || !in_array(false, $terpasang, true),
    'Tidak ada pemasangan sebagian 030–031 (Fase 1–5 dirilis bersama)' . (in_array(true, $terpasang, true) && in_array(false, $terpasang, true) ? ': lanjutkan ke 031 atau pulihkan backup' : ''));
$periksa($n("SELECT COUNT(*) FROM schema_migrations WHERE migration > '" . V7_MIGRASI[1] . "'") === 0, 'Tidak ada migrasi sesudah 031 yang tercatat');

$tipeStatus = (string) v7f1_nilai($db, "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'status'");
$periksa(str_starts_with($tipeStatus, "enum('" . implode("','", V7F1_STATUS_V2) . "'"), 'ENUM status diawali lima nilai V2 berurutan (ID dan arti status V2 tetap)');
if (!$terpasang[V7_MIGRASI[0]]) {
    $luar = $n("SELECT COUNT(*) FROM izin_pengajuan WHERE status NOT IN (" . v7f2_in(V7F1_STATUS_V2) . ')');
    $periksa($luar === 0, 'Seluruh baris izin_pengajuan berstatus V2 (' . $luar . ' di luar) — CHECK asal 030 dapat dipasang');
}
$tipe = static fn (string $tabel, string $kolom): string => strtolower((string) v7f1_nilai($db,
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tabel' AND COLUMN_NAME = '$kolom'"));
foreach (['izin_pengajuan', 'pengurus', 'pembimbing_assignments', 'users', 'wali'] as $tabel) {
    $periksa(str_starts_with($tipe($tabel, 'id'), 'bigint') && str_contains($tipe($tabel, 'id'), 'unsigned'), 'Kolom ' . $tabel . '.id BIGINT UNSIGNED (induk kunci asing 030–031)');
}
$periksa(preg_match('/^int(\(\d+\))?$/', $tipe('guru', 'id')) === 1, 'Kolom guru.id INT bertanda (induk kunci asing murobi 031)');

echo PHP_EOL . '=== Data yang terdampak ===' . PHP_EOL;
$aktifPa = "pa.is_active = 1 AND pa.archived_at IS NULL AND pa.tanggal_mulai <= CURDATE() AND (pa.tanggal_selesai IS NULL OR pa.tanggal_selesai >= CURDATE())";
echo '[info] Pengurus berpenugasan pembimbing aktif tanpa akun pengurus aktif: ' . $n("SELECT COUNT(DISTINCT pa.pengurus_id) FROM pembimbing_assignments pa
    JOIN tahun_ajaran ta ON ta.id = pa.tahun_ajaran_id AND ta.status = 'Aktif' AND ta.archived_at IS NULL
    WHERE $aktifPa AND NOT EXISTS (SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id AND r.slug = 'pengurus'
                                   WHERE u.pengurus_id = pa.pengurus_id AND u.is_active = 1)")
    . ' — santri binaannya tidak memiliki pembimbing SAH; pengajuan wali masuk antrean admin sampai akun dibuat.' . PHP_EOL;
echo '[info] Akun orang tua aktif berelasi santri aktif (dapat mengajukan sesudah rilis): ' . $n("SELECT COUNT(DISTINCT u.id) FROM users u
    JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id AND r.slug = 'orang_tua'
    JOIN santri_wali sw ON sw.wali_id = u.wali_id AND sw.archived_at IS NULL JOIN santri s ON s.id = sw.santri_id AND s.is_active = 1 AND s.archived_at IS NULL
    WHERE u.is_active = 1") . PHP_EOL;
$sakelar = $db->query('SELECT inapp_enabled, push_enabled, whatsapp_enabled FROM pengaturan_notifikasi WHERE singleton = 1')->fetch_assoc() ?? [];
echo '[info] Sakelar notifikasi InApp/Push/WhatsApp: ' . (int) ($sakelar['inapp_enabled'] ?? 0) . '/' . (int) ($sakelar['push_enabled'] ?? 0) . '/' . (int) ($sakelar['whatsapp_enabled'] ?? 0)
    . ' — V7 tidak pernah mengantrekan WhatsApp walau sakelar lama aktif; Push nyata belum diuji.' . PHP_EOL;
if ($terpasang[V7_MIGRASI[0]]) {
    echo '[info] Pengajuan per asal/status: ' . json_encode($db->query('SELECT asal_pengajuan, status, COUNT(*) AS n FROM izin_pengajuan GROUP BY 1, 2')->fetch_all(MYSQLI_ASSOC)) . PHP_EOL;
}

if ($rilis !== null) {
    echo PHP_EOL . '=== Manifest rilis ===' . PHP_EOL;
    $m = json_decode((string) @file_get_contents($rilis), true);
    $valid = is_array($m) && is_array($m['migrations'] ?? null) && is_array($m['rollbacks'] ?? null);
    $periksa($valid, 'Manifest rilis terbaca: ' . $rilis);
    // Hash yang cocok tidak membuktikan kelengkapan: daftar kosong/terpotong ditolak.
    $wajib = [
        'migrations' => array_map(static fn (string $f): string => 'database/migrations/' . basename($f), glob(APP_ROOT . '/database/migrations/*.sql') ?: []),
        'rollbacks' => array_map(static fn (string $f): string => 'database/rollbacks/' . $f, V7_MIGRASI),
    ];
    foreach ($wajib as $jenis => $files) {
        $paths = array_map(static fn (mixed $e): mixed => is_array($e) ? ($e['file'] ?? null) : null, $valid ? $m[$jenis] : []);
        sort($files);
        sort($paths);
        $periksa($paths === $files, 'Daftar lengkap dan unik manifest rilis: ' . $jenis . ' (' . count($files) . ' berkas)');
    }
    foreach ($valid ? array_merge($m['migrations'], $m['rollbacks']) : [] as $e) {
        if (!is_array($e) || !is_string($e['file'] ?? null) || !is_string($e['sha256'] ?? null) || !in_array($e['file'], array_merge(...array_values($wajib)), true)) {
            $periksa(false, 'Entri manifest rilis tidak valid');
            continue;
        }
        $jalur = APP_ROOT . '/' . $e['file'];
        $periksa(is_file($jalur) && hash_equals($e['sha256'], (string) hash_file('sha256', $jalur)), 'SHA-256 sesuai manifest rilis: ' . $e['file']);
    }
}

$manifest = json_encode([
    'database' => $nama,
    'fase' => 'v7-rilis',
    'captured_at' => date(DATE_ATOM),
    'v7_terpasang' => $terpasang,
] + v7_snapshot($db), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($tujuan !== null) {
    file_put_contents($tujuan, $manifest . PHP_EOL);
    @chmod($tujuan, 0600);
    echo PHP_EOL . 'Manifest privat ditulis: ' . $tujuan . PHP_EOL;
} else {
    echo $manifest . PHP_EOL;
}
echo "Backup DB terverifikasi dan manifest privat ini wajib disimpan sebelum migrasi.\n";
echo $gagal === 0 ? "Preflight V7: lulus.\n" : "Preflight V7: {$gagal} pemeriksaan gagal — jangan migrasi sebelum diselesaikan.\n";
exit($gagal > 0 ? 1 : 0);
