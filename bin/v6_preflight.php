<?php

declare(strict_types=1);

/**
 * Preflight rilis bersama PRD V6 (Fase 5): migrasi 027–029 sekaligus, atau
 * sisa yang belum terpasang. Hanya membaca.
 *
 *   php bin/v6_preflight.php [manifest-privat.json] [--rilis=docs/phase-v6-5/manifest-migrasi.json]
 *
 * Memeriksa:
 *   - server: CHECK ditegakkan dan kolom terbangkit (MariaDB 10.2.1+/MySQL
 *     8.0.16+), InnoDB, binlog tidak STATEMENT (transaksi READ COMMITTED),
 *     dan jam basis data = jam aplikasi (`APP_TIMEZONE`): batas hari/periode
 *     laporan dan rekonsiliasi membandingkan `created_at` basis data dengan
 *     tanggal PHP, sehingga selisih zona waktu menggeser transaksi dini hari
 *     ke hari/bulan lain;
 *   - prasyarat tabel dan migrasi 023, status 024–026 (V5 ikut terpasang bila
 *     belum) dan 027–029 berurutan, tidak ada migrasi > 029;
 *   - jumlah penugasan bendahara bulanan lama yang aktif (menjadi Petugas BSA,
 *     keputusan K-1) dan santri nonaktif (R-10, hanya dilaporkan);
 *   - dengan `--rilis`: daftar migrasi/rollback lengkap dan SHA-256 berkas di
 *     disk = manifest rilis yang diaudit.
 * Lalu menulis manifest privat: jumlah baris seluruh tabel lama dan sidik jari
 * identitas (serta isi uang V6 bila tabelnya sudah ada), untuk dibandingkan
 * `bin/v6_verify.php` sesudah migrasi, restore, atau sebagai pemeriksaan
 * berkala. Manifest hanya berisi hitungan dan hash; simpan di luar document
 * root.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/_v6_rilis.php';

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
$satu = static fn (string $sql): int => (int) $db->query($sql)->fetch_row()[0];
$nama = (string) $db->query('SELECT DATABASE()')->fetch_row()[0];
echo '[info] Basis data terpilih: ' . $nama . ' (preflight hanya membaca; migrasi produksi memerlukan otorisasi operator).' . PHP_EOL;

echo PHP_EOL . '=== Server ===' . PHP_EOL;
$versi = (string) $db->query('SELECT VERSION()')->fetch_row()[0];
$maria = stripos($versi, 'mariadb') !== false;
preg_match('/^(\d+)\.(\d+)\.(\d+)/', $versi, $v);
$angka = isset($v[1]) ? [(int) $v[1], (int) $v[2], (int) $v[3]] : [0, 0, 0];
$periksa($maria ? $angka >= [10, 2, 1] : $angka >= [8, 0, 16], 'Server ' . $versi . ' menegakkan CHECK (saldo tidak negatif, penjaga rollback) dan kolom terbangkit 028');
if ($maria) {
    // MariaDB dapat menonaktifkan CHECK walaupun versinya mendukungnya.
    // Default global juga menentukan penjaga rollback pada koneksi berikutnya.
    $checks = $db->query('SELECT @@session.check_constraint_checks, @@global.check_constraint_checks')->fetch_row();
    $periksa((int) $checks[0] === 1 && (int) $checks[1] === 1,
        'CHECK aktif pada sesi dan default global (penjaga saldo dan rollback)');
}
$binlog = $db->query('SELECT @@log_bin, @@binlog_format')->fetch_row();
$periksa(!((int) $binlog[0] === 1 && strtoupper((string) $binlog[1]) === 'STATEMENT'),
    'Binary log ' . ((int) $binlog[0] === 1 ? 'aktif, format ' . $binlog[1] : 'tidak aktif') . ' — kompatibel dengan transaksi READ COMMITTED');
$innodb = (string) ($db->query("SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'")->fetch_row()[0] ?? '');
$periksa(in_array($innodb, ['YES', 'DEFAULT'], true), 'Mesin InnoDB tersedia (transaksi, kunci baris, snapshot konsisten laporan)');
$jamDb = (string) $db->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')")->fetch_row()[0];
$tz = $db->query('SELECT @@session.time_zone, @@system_time_zone')->fetch_row();
$selisihDetik = abs(strtotime($jamDb) - time());
$periksa($selisihDetik <= 300, 'Jam basis data ' . $jamDb . ' (zona ' . $tz[0] . '/' . $tz[1] . ') = jam aplikasi ' . date('Y-m-d H:i:s') . ' (' . date_default_timezone_get()
    . '), selisih ' . $selisihDetik . ' detik — batas hari/periode laporan dan rekonsiliasi');

echo PHP_EOL . '=== Prasyarat dan status migrasi ===' . PHP_EOL;
foreach (['users', 'user_roles', 'roles', 'pengurus', 'tahun_ajaran', 'santri', 'wali', 'santri_wali', 'kelas', 'plotting_kelas', 'bendahara_bulanan_assignments',
    'notifikasi_outbox', 'perangkat_push', 'pengaturan_notifikasi', 'audit_logs', 'schema_migrations'] as $t) {
    $periksa(v6_tabel_ada($db, $t), 'Tabel prasyarat ' . $t);
}
$periksa($satu("SELECT COUNT(*) FROM schema_migrations WHERE migration = '023_redesain_web_tahap6_data_pesantren.sql'") === 1, 'Migrasi 023 tercatat');
$periksa($satu("SELECT COUNT(*) FROM roles WHERE slug IN ('admin', 'pengurus', 'orang_tua')") === 3, 'Role dasar admin, pengurus, orang_tua tersedia (V6 tidak menambah role)');
foreach (['024_v5_fase1_kesehatan.sql', '025_v5_fase2_rincian_kesehatan.sql', '026_v5_fase3_ringkasan_wali.sql'] as $m) {
    $ada = $satu("SELECT COUNT(*) FROM schema_migrations WHERE migration = '" . $db->real_escape_string($m) . "'") === 1;
    echo '[info] ' . $m . ': ' . ($ada ? 'sudah tercatat' : 'BELUM — `bin/migrate.php up` akan memasangnya sebelum 027 (rilis V5 ikut terbawa; putuskan dahulu)') . PHP_EOL;
}
$terpasang = v6_terpasang($db);
foreach ($terpasang as $m => $ada) {
    echo '[info] ' . $m . ': ' . ($ada ? 'sudah tercatat' : 'belum') . PHP_EOL;
}
$berurutan = true;
$celah = false;
foreach ($terpasang as $ada) {
    $berurutan = $berurutan && !($ada && $celah);
    $celah = $celah || !$ada;
}
$periksa($berurutan, 'Migrasi V6 tercatat berurutan (tidak ada 028/029 tanpa pendahulunya)');
$periksa(!in_array(true, $terpasang, true) || !in_array(false, $terpasang, true),
    'Tidak ada pemasangan sebagian 027–029 (tagihan dan tabungan dirilis bersama)' . (in_array(true, $terpasang, true) && in_array(false, $terpasang, true) ? ': lanjutkan ke 029 atau pulihkan backup' : ''));
$periksa($satu("SELECT COUNT(*) FROM schema_migrations WHERE migration > '029_v6_fase3_tabungan.sql'") === 0, 'Tidak ada migrasi sesudah 029 yang tercatat');

echo PHP_EOL . '=== Data yang terdampak ===' . PHP_EOL;
$bendahara = $satu("SELECT COUNT(*) FROM bendahara_bulanan_assignments WHERE is_active = 1 AND tanggal_mulai <= CURDATE() AND (tanggal_selesai IS NULL OR tanggal_selesai >= CURDATE())");
echo '[info] Penugasan bendahara bulanan lama yang aktif dan dalam masa berlaku: ' . $bendahara . ' — setelah rilis menjadi Petugas BSA (keputusan K-1); tinjau sebelum deploy.' . PHP_EOL;
echo '[info] Santri nonaktif/diarsipkan: ' . $satu('SELECT COUNT(*) FROM santri WHERE is_active = 0 OR archived_at IS NOT NULL')
    . ' — saldo/tunggakan mereka hanya dibaca admin; jalur pengembalian belum ada (R-10, keputusan Human Developer).' . PHP_EOL;
if ($terpasang[V6_MIGRASI[0]] && v6_tabel_ada($db, 'bsa_tabungan_transaksi')) {
    echo '[info] Data V6 yang sudah ada: ' . $satu('SELECT COUNT(*) FROM bsa_tagihan') . ' tagihan, ' . $satu('SELECT COUNT(*) FROM bsa_tabungan_transaksi') . ' baris ledger, '
        . $satu('SELECT COUNT(*) FROM bsa_pembayaran') . ' pembayaran.' . PHP_EOL;
}

if ($rilis !== null) {
    echo PHP_EOL . '=== Manifest rilis ===' . PHP_EOL;
    $m = json_decode((string) @file_get_contents($rilis), true);
    $valid = is_array($m) && is_array($m['migrations'] ?? null) && is_array($m['rollbacks'] ?? null);
    $periksa($valid, 'Manifest rilis terbaca: ' . $rilis);
    // Hash yang cocok tidak membuktikan kelengkapan: daftar kosong/terpotong ditolak.
    $wajib = [
        'migrations' => array_map(static fn (string $f): string => 'database/migrations/' . basename($f), glob(APP_ROOT . '/database/migrations/*.sql') ?: []),
        'rollbacks' => array_map(static fn (string $f): string => 'database/rollbacks/' . $f,
            ['024_v5_fase1_kesehatan.sql', '025_v5_fase2_rincian_kesehatan.sql', '026_v5_fase3_ringkasan_wali.sql', ...V6_MIGRASI]),
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
    'fase' => 'v6-rilis',
    'captured_at' => date(DATE_ATOM),
    'v6_terpasang' => $terpasang,
    'legacy_counts' => v6_hitung_tabel_lama($db),
    'sidik_jari' => v6_sidik_jari($db),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($tujuan !== null) {
    file_put_contents($tujuan, $manifest . PHP_EOL);
    @chmod($tujuan, 0600);
    echo PHP_EOL . 'Manifest privat ditulis: ' . $tujuan . PHP_EOL;
} else {
    echo $manifest . PHP_EOL;
}
echo "Backup DB terverifikasi dan manifest privat ini wajib disimpan sebelum migrasi.\n";
echo $gagal === 0 ? "Preflight V6: lulus.\n" : "Preflight V6: {$gagal} pemeriksaan gagal — jangan migrasi sebelum diselesaikan.\n";
exit($gagal > 0 ? 1 : 0);
