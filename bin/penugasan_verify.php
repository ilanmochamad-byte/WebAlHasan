<?php

declare(strict_types=1);

/**
 * Verifikasi pasca-migrasi 012 — Fondasi Penugasan V3–V6 (keputusan pengguna
 * 7 September 2026).
 *
 * HANYA MEMBACA. Dijalankan SETELAH `php bin/migrate.php up` untuk memastikan
 * migrasi 012 terpasang persis seperti yang dijanjikan dan tidak ada data lama
 * yang hilang:
 *
 *   1. enam tabel baru ada dengan kolom, kunci unik, CHECK, dan kunci asing;
 *   2. lima kolom jejak baru pada murobi_assignments dan pembimbing_assignments
 *      beserta kunci asingnya;
 *   3. jumlah baris murobi/pembimbing TIDAK berkurang (opsional, --murobi=N
 *      --pembimbing=N dari manifest preflight);
 *   4. tabel baru KOSONG bila ini migrasi pertama (tidak ada pengisian tebakan),
 *      atau dilaporkan jumlahnya bila sudah dipakai;
 *   5. tabel roles tetap hanya empat role dasar;
 *   6. resolver capability dapat dijalankan pada skema ini.
 *
 * Pemakaian:
 *   php bin/penugasan_verify.php
 *   php bin/penugasan_verify.php --murobi=12 --pembimbing=9
 *
 * Kode keluar: 0 lulus; 1 ada yang gagal; 2 tidak dapat dijalankan.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Penugasan\PenugasanJenis;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$db = app_db();
$sebelum = ['murobi' => null, 'pembimbing' => null];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--murobi=')) {
        $sebelum['murobi'] = (int) substr($arg, 9);
    }
    if (str_starts_with($arg, '--pembimbing=')) {
        $sebelum['pembimbing'] = (int) substr($arg, 13);
    }
}

echo "Verifikasi migrasi 012 (fondasi penugasan V3–V6)\n";
echo 'Basis data : ' . (string) app_config('database.database') . "\n";
echo 'Waktu      : ' . date('Y-m-d H:i:s') . "\n";
echo str_repeat('-', 72) . "\n";

$gagal = 0;
$periksa = static function (bool $lulus, string $pesan) use (&$gagal): void {
    echo ($lulus ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    if (!$lulus) {
        $gagal++;
    }
};
$skalar = static function (string $sql, array $params = []) use ($db): int {
    $statement = $db->prepare($sql);
    if ($statement === false) {
        return -1;
    }
    if ($params !== []) {
        $statement->bind_param(str_repeat('s', count($params)), ...$params);
    }
    if (!$statement->execute()) {
        $statement->close();
        return -1;
    }
    $row = $statement->get_result()?->fetch_row();
    $statement->close();

    return $row ? (int) $row[0] : -1;
};
$kolomAda = static fn (string $tabel, string $kolom): bool => $skalar(
    'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
    [$tabel, $kolom]
) === 1;
$indeksAda = static fn (string $tabel, string $indeks): bool => $skalar(
    'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
    [$tabel, $indeks]
) > 0;
$constraintAda = static fn (string $tabel, string $nama): bool => $skalar(
    'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
    [$tabel, $nama]
) === 1;

// ------------------------------------------------------------------- 1
$periksa($skalar("SELECT COUNT(*) FROM schema_migrations WHERE migration = '012_fondasi_penugasan_v3_v6.sql'") === 1, 'Migrasi 012 tercatat pada schema_migrations');

$umum = ['tanggal_mulai', 'tanggal_selesai', 'is_active', 'catatan', 'alasan_perubahan', 'diakhiri_pada', 'diakhiri_oleh', 'alasan_pengakhiran', 'created_by', 'updated_by', 'created_at', 'updated_at'];
$spesifik = [
    'guru_mapel_assignments' => ['guru_id', 'mata_pelajaran_id', 'kelas_id', 'tahun_ajaran_id'],
    'pendidikan_assignments' => ['pengurus_id', 'tahun_ajaran_id', 'jenjang', 'jenjang_key'],
    'bendahara_bulanan_assignments' => ['pengurus_id', 'tahun_ajaran_id', 'jenjang', 'jenjang_key'],
    'panitia_psb_assignments' => ['pengurus_id', 'tahun_ajaran_id', 'gelombang', 'gelombang_key'],
    'bendahara_psb_assignments' => ['pengurus_id', 'tahun_ajaran_id', 'gelombang', 'gelombang_key'],
];
$prefix = [
    'guru_mapel_assignments' => 'guru_mapel',
    'pendidikan_assignments' => 'pendidikan',
    'bendahara_bulanan_assignments' => 'bendahara_bulanan',
    'panitia_psb_assignments' => 'panitia_psb',
    'bendahara_psb_assignments' => 'bendahara_psb',
];

foreach (['kode', 'nama', 'kategori', 'is_active', 'archived_at', 'created_by', 'updated_by', 'kode_unique_key'] as $kolom) {
    $periksa($kolomAda('mata_pelajaran', $kolom), 'Kolom mata_pelajaran.' . $kolom . ' tersedia');
}
$periksa($indeksAda('mata_pelajaran', 'mata_pelajaran_nama_unique'), 'Kunci unik nama mata pelajaran terpasang');
$periksa($indeksAda('mata_pelajaran', 'mata_pelajaran_kode_unique'), 'Kunci unik kode mata pelajaran terpasang');

foreach ($spesifik as $tabel => $kolomKhusus) {
    foreach (array_merge($kolomKhusus, $umum) as $kolom) {
        $periksa($kolomAda($tabel, $kolom), 'Kolom ' . $tabel . '.' . $kolom . ' tersedia');
    }
    $p = $prefix[$tabel];
    $periksa($indeksAda($tabel, $p . '_assignment_unique'), 'Kunci unik ' . $p . '_assignment_unique terpasang');
    $periksa($constraintAda($tabel, $p . '_range_check'), 'CHECK rentang tanggal ' . $p . '_range_check terpasang');
    $periksa($constraintAda($tabel, $p . '_tahun_fk'), 'Kunci asing ' . $p . '_tahun_fk terpasang');
    $periksa($constraintAda($tabel, $p . '_creator_fk') && $constraintAda($tabel, $p . '_updater_fk') && $constraintAda($tabel, $p . '_ender_fk'), 'Kunci asing pelaku ' . $p . ' terpasang');
    $periksa($constraintAda($tabel, $p . '_' . ($p === 'guru_mapel' ? 'guru' : 'pengurus') . '_fk'), 'Kunci asing subjek ' . $p . ' terpasang');
}
$periksa($constraintAda('guru_mapel_assignments', 'guru_mapel_mapel_fk') && $constraintAda('guru_mapel_assignments', 'guru_mapel_kelas_fk'), 'Kunci asing mata pelajaran dan kelas pada guru_mapel terpasang');

// ------------------------------------------------------------------- 2
foreach (['murobi_assignments' => 'murobi', 'pembimbing_assignments' => 'pembimbing'] as $tabel => $p) {
    foreach (['catatan', 'updated_by', 'diakhiri_pada', 'diakhiri_oleh', 'alasan_pengakhiran'] as $kolom) {
        $periksa($kolomAda($tabel, $kolom), 'Kolom ' . $tabel . '.' . $kolom . ' tersedia');
    }
    $periksa($constraintAda($tabel, $p . '_updater_fk') && $constraintAda($tabel, $p . '_ender_fk'), 'Kunci asing jejak ' . $p . ' terpasang');
    // Kolom lama tetap utuh.
    foreach (['target_type', 'kamar_id', 'kelas_id', 'tanggal_mulai', 'tanggal_selesai', 'is_active', 'archived_at', 'created_by', 'target_key'] as $kolom) {
        $periksa($kolomAda($tabel, $kolom), 'Kolom lama ' . $tabel . '.' . $kolom . ' tetap ada');
    }
    $periksa($indeksAda($tabel, $p . '_assignment_unique'), 'Kunci unik lama ' . $p . '_assignment_unique tetap ada');
}

// ------------------------------------------------------------------- 3
$murobi = $skalar('SELECT COUNT(*) FROM murobi_assignments');
$pembimbing = $skalar('SELECT COUNT(*) FROM pembimbing_assignments');
echo 'Jumlah baris murobi_assignments     : ' . $murobi . PHP_EOL;
echo 'Jumlah baris pembimbing_assignments : ' . $pembimbing . PHP_EOL;
if ($sebelum['murobi'] !== null) {
    $periksa($murobi >= $sebelum['murobi'], 'Baris murobi tidak berkurang (sebelum ' . $sebelum['murobi'] . ', sesudah ' . $murobi . ')');
}
if ($sebelum['pembimbing'] !== null) {
    $periksa($pembimbing >= $sebelum['pembimbing'], 'Baris pembimbing tidak berkurang (sebelum ' . $sebelum['pembimbing'] . ', sesudah ' . $pembimbing . ')');
}
if ($sebelum['murobi'] === null && $sebelum['pembimbing'] === null) {
    echo "[info]  Jumlah sebelum migrasi tidak diberikan; jalankan ulang dengan --murobi=N --pembimbing=N untuk membandingkannya.\n";
}

// ------------------------------------------------------------------- 4
foreach (array_merge(array_keys($spesifik), ['mata_pelajaran']) as $tabel) {
    $n = $skalar('SELECT COUNT(*) FROM ' . $tabel);
    echo '  ' . str_pad($tabel, 32) . $n . ' baris' . ($n === 0 ? ' (kosong: migrasi tidak mengisi apa pun)' : '') . PHP_EOL;
}
$periksa($skalar("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'penugasan.%'") >= 0, 'Audit penugasan dapat dibaca');

// ------------------------------------------------------------------- 5
$periksa($skalar("SELECT COUNT(*) FROM roles WHERE slug NOT IN ('admin','guru','pengurus','orang_tua')") === 0, 'Tabel roles tetap hanya berisi empat role dasar');

// ------------------------------------------------------------------- 6
try {
    $adminId = $skalar("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'admin' AND u.is_active = 1 ORDER BY u.id LIMIT 1");
    if ($adminId > 0) {
        $user = auth_repository()->findActiveById($adminId);
        $daftar = $user === null ? [] : capabilities()->featureList($user);
        $periksa($daftar === PenugasanJenis::semuaCapability(), 'Resolver capability berjalan: admin memperoleh seluruh ' . count(PenugasanJenis::semuaCapability()) . ' capability pengawasan');
    } else {
        echo "[info]  Tidak ada admin aktif untuk menguji resolver.\n";
    }
} catch (Throwable $exception) {
    $periksa(false, 'Resolver capability gagal: ' . $exception->getMessage());
}

echo str_repeat('-', 72) . "\n";
if ($gagal === 0) {
    echo "SELURUH PEMERIKSAAN LULUS.\n";
    exit(0);
}
echo "TERDAPAT {$gagal} PEMERIKSAAN YANG GAGAL.\n";
exit(1);
