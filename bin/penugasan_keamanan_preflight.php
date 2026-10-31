<?php

declare(strict_types=1);

/**
 * Preflight rilis penugasan lintas guru/pengurus dan Keamanan (Fase 5):
 * migrasi 032–033 sekaligus, atau sisa yang belum terpasang. Hanya membaca.
 *
 *   php bin/penugasan_keamanan_preflight.php [manifest-privat.json] [--rilis=docs/penugasan-keamanan/fase-5/manifest-migrasi.json]
 *
 * Memeriksa:
 *   - server: CHECK ditegakkan (MariaDB 10.2.1+/MySQL 8.0.16+; penjaga data
 *     rollback 032–033 dan CHECK rentang tanggal 033) pada sesi dan default
 *     global MariaDB, InnoDB, jam basis data = jam aplikasi (`APP_TIMEZONE`,
 *     dipakai tanggal penugasan aktif);
 *   - prasyarat tabel, migrasi 031 (V7) tercatat, status 032–033 berurutan dan
 *     tidak sebagian, tidak ada migrasi > 033;
 *   - sebelum 032: tipe kolom induk kunci asing (`guru.id` INT bertanda,
 *     `pengurus.id`/`users.id` BIGINT UNSIGNED, `tahun_ajaran.id` INT) dan
 *     mesin InnoDB pada tabel induk;
 *   - data terdampak: nama guru = pengurus biasa aktif (data ganda, keputusan
 *     "peringatan saja"), akun ganda warisan (akun guru yang memegang pengurus
 *     biasa), penugasan aktif berbasis pengurus per jenis;
 *   - dengan `--rilis`: daftar migrasi/rollback lengkap dan SHA-256 berkas di
 *     disk = manifest rilis yang diaudit.
 * Lalu menulis manifest privat (0600): jumlah baris dan hash isi seluruh tabel
 * lama, irisan data yang hanya dibaca, serta isi 032/033 bila sudah terpasang —
 * untuk `bin/penugasan_keamanan_verify.php`. Hanya hitungan dan hash; simpan di
 * luar document root.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/_penugasan_keamanan_rilis.php';

use App\Penugasan\PenugasanJenis;

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
$periksa($maria ? $angka >= [10, 2, 1] : $angka >= [8, 0, 16], 'Server ' . $versi . ' menegakkan CHECK (rentang tanggal 033 dan penjaga rollback 032–033)');
$periksa(v7f1_check_aktif($db), 'CHECK aktif pada sesi dan default global (MariaDB)');
$periksa(in_array((string) v7f1_nilai($db, "SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'"), ['YES', 'DEFAULT'], true),
    'Mesin InnoDB tersedia (kunci asing, transaksi pembentukan identitas)');
$jamDb = (string) v7f1_nilai($db, "SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')");
$tz = $db->query('SELECT @@session.time_zone, @@system_time_zone')->fetch_row();
$selisih = abs(strtotime($jamDb) - time());
$periksa($selisih <= 300, 'Jam basis data ' . $jamDb . ' (zona ' . $tz[0] . '/' . $tz[1] . ') = jam aplikasi ' . date('Y-m-d H:i:s') . ' (' . date_default_timezone_get()
    . '), selisih ' . $selisih . ' detik — tanggal mulai/selesai penugasan dibandingkan dengan CURDATE()');

echo PHP_EOL . '=== Prasyarat dan status migrasi ===' . PHP_EOL;
$prasyarat = ['pengurus', 'guru', 'users', 'user_roles', 'roles', 'tahun_ajaran', 'santri', 'izin_pengajuan', 'izin_keputusan', 'izin_riwayat_status',
    'izin_pembimbing_tinjauan', 'v3_pelanggaran', 'v3_konseling_kasus', 'v3_lampiran', 'jadwal_ngaji', 'pertemuan_pengajian', 'pertemuan_peserta',
    'audit_logs', 'schema_migrations'];
foreach (PenugasanJenis::semua() as $definisi) {
    if ($definisi['tabel'] !== PK_TABEL) {
        $prasyarat[] = $definisi['tabel'];
    }
}
foreach (array_unique($prasyarat) as $t) {
    $periksa(v7f1_ada_tabel($db, $t), 'Tabel prasyarat ' . $t);
}
$periksa($n("SELECT COUNT(*) FROM schema_migrations WHERE migration = '" . PK_PRASYARAT . "'") === 1, 'Migrasi 031 (V7 Fase 2) tercatat — Keamanan membaca izin asal Pengurus dan Wali');
$periksa($n("SELECT COUNT(*) FROM roles WHERE slug IN ('admin', 'pengurus', 'orang_tua', 'guru')") === 4 && $n('SELECT COUNT(*) FROM roles') === 4,
    'Tepat empat role dasar admin, pengurus, orang_tua, guru (Keamanan bukan role login)');
$terpasang = pk_terpasang($db);
foreach ($terpasang as $m => $ada) {
    echo '[info] ' . $m . ': ' . ($ada ? 'sudah tercatat' : 'belum') . PHP_EOL;
}
$periksa(!($terpasang[PK_MIGRASI[1]] && !$terpasang[PK_MIGRASI[0]]), 'Migrasi tercatat berurutan (tidak ada 033 tanpa 032)');
$periksa(!in_array(true, $terpasang, true) || !in_array(false, $terpasang, true),
    'Tidak ada pemasangan sebagian 032–033 (Fase 1–5 dirilis bersama; pemisahan tugas Fase 2 wajib menyertai 032)'
    . (in_array(true, $terpasang, true) && in_array(false, $terpasang, true) ? ': lanjutkan ke 033 atau pulihkan backup' : ''));
$periksa($n("SELECT COUNT(*) FROM schema_migrations WHERE migration > '" . PK_MIGRASI[1] . "'") === 0, 'Tidak ada migrasi sesudah 033 yang tercatat');

$tipe = static fn (string $tabel, string $kolom): string => strtolower((string) v7f1_nilai($db,
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tabel' AND COLUMN_NAME = '$kolom'"));
$mesin = static fn (string $tabel): string => strtolower((string) v7f1_nilai($db,
    "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tabel'"));
$periksa(preg_match('/^int(\(\d+\))?$/', $tipe('guru', 'id')) === 1, 'Kolom guru.id INT bertanda (induk kunci asing pengurus.guru_id 032)');
foreach (['pengurus', 'users'] as $tabel) {
    $periksa(str_starts_with($tipe($tabel, 'id'), 'bigint') && str_contains($tipe($tabel, 'id'), 'unsigned'), 'Kolom ' . $tabel . '.id BIGINT UNSIGNED (induk kunci asing 033)');
}
$periksa(preg_match('/^int(\(\d+\))?$/', $tipe('tahun_ajaran', 'id')) === 1, 'Kolom tahun_ajaran.id INT (induk kunci asing 033)');
foreach (['pengurus', 'guru', 'users', 'tahun_ajaran'] as $tabel) {
    $periksa($mesin($tabel) === 'innodb', 'Tabel ' . $tabel . ' bermesin InnoDB (kunci asing 032–033)');
}
if (!$terpasang[PK_MIGRASI[0]]) {
    $periksa(!v7f1_ada_kolom($db, 'pengurus', PK_KOLOM_PENGURUS), 'Kolom pengurus.guru_id belum ada (032 dapat dipasang bersih)');
}
if (!$terpasang[PK_MIGRASI[1]]) {
    $periksa(!v7f1_ada_tabel($db, PK_TABEL) || $n('SELECT COUNT(*) FROM ' . PK_TABEL) === 0, 'Tabel keamanan_assignments belum ada atau kosong');
}

echo PHP_EOL . '=== Data yang terdampak ===' . PHP_EOL;
$biasa = v7f1_ada_kolom($db, 'pengurus', PK_KOLOM_PENGURUS) ? ' AND p.guru_id IS NULL' : '';
echo '[info] Guru aktif yang namanya sama dengan pengurus biasa aktif (data ganda; keputusan Human Developer 30 Oktober 2026: peringatan saja): '
    . $n("SELECT COUNT(DISTINCT g.id) FROM guru g JOIN pengurus p ON LOWER(TRIM(p.nama)) = LOWER(TRIM(g.nama_guru))
          WHERE g.is_active = 1 AND g.archived_at IS NULL AND p.is_active = 1 AND p.archived_at IS NULL$biasa")
    . ' — pilih orang itu dari kelompok Pengurus saat menugaskan; penggabungan memerlukan keputusan tersendiri.' . PHP_EOL;
echo '[info] Akun guru yang memegang pengurus biasa (akun ganda warisan): '
    . $n("SELECT COUNT(*) FROM users u JOIN pengurus p ON p.id = u.pengurus_id WHERE u.guru_id IS NOT NULL$biasa")
    . ' — menugaskan guru itu lewat kelompok Guru ditolak (konflik); pemisahan tugas Fase 2 tetap mengenalinya lewat users.guru_id.' . PHP_EOL;
echo '[info] Akun guru aktif: ' . $n("SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id AND r.slug = 'guru' WHERE u.is_active = 1")
    . '; guru aktif tanpa akun: ' . $n('SELECT COUNT(*) FROM guru g WHERE g.is_active = 1 AND g.archived_at IS NULL AND NOT EXISTS (SELECT 1 FROM users u WHERE u.guru_id = g.id)') . PHP_EOL;
$perJenis = [];
foreach (PenugasanJenis::semua() as $jenis => $definisi) {
    if ($definisi['subjek'] !== 'pengurus' || !v7f1_ada_tabel($db, $definisi['tabel'])) {
        continue;
    }
    $arsip = v7f1_ada_kolom($db, $definisi['tabel'], 'archived_at') ? ' AND archived_at IS NULL' : '';
    $perJenis[$jenis] = $n('SELECT COUNT(*) FROM `' . $definisi['tabel'] . "` WHERE is_active = 1$arsip AND tanggal_mulai <= CURDATE() AND (tanggal_selesai IS NULL OR tanggal_selesai >= CURDATE())");
}
echo '[info] Penugasan aktif berbasis pengurus per jenis: ' . json_encode($perJenis) . PHP_EOL;
if ($terpasang[PK_MIGRASI[0]]) {
    echo '[info] Identitas pengurus milik guru: ' . $n('SELECT COUNT(*) FROM pengurus WHERE guru_id IS NOT NULL') . PHP_EOL;
}

if ($rilis !== null) {
    echo PHP_EOL . '=== Manifest rilis ===' . PHP_EOL;
    $m = json_decode((string) @file_get_contents($rilis), true);
    $valid = is_array($m) && is_array($m['migrations'] ?? null) && is_array($m['rollbacks'] ?? null);
    $periksa($valid, 'Manifest rilis terbaca: ' . $rilis);
    // Hash yang cocok tidak membuktikan kelengkapan: daftar kosong/terpotong ditolak.
    $wajib = [
        'migrations' => array_map(static fn (string $f): string => 'database/migrations/' . basename($f), glob(APP_ROOT . '/database/migrations/*.sql') ?: []),
        'rollbacks' => array_map(static fn (string $f): string => 'database/rollbacks/' . $f, PK_MIGRASI),
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
    'fase' => 'penugasan-keamanan-rilis',
    'captured_at' => date(DATE_ATOM),
    'pk_terpasang' => $terpasang,
] + pk_snapshot($db), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($tujuan !== null) {
    file_put_contents($tujuan, $manifest . PHP_EOL);
    @chmod($tujuan, 0600);
    echo PHP_EOL . 'Manifest privat ditulis: ' . $tujuan . PHP_EOL;
} else {
    echo $manifest . PHP_EOL;
}
echo "Backup DB terverifikasi dan manifest privat ini wajib disimpan sebelum migrasi.\n";
echo $gagal === 0 ? "Preflight penugasan Keamanan: lulus.\n" : "Preflight penugasan Keamanan: {$gagal} pemeriksaan gagal — jangan migrasi sebelum diselesaikan.\n";
exit($gagal > 0 ? 1 : 0);
