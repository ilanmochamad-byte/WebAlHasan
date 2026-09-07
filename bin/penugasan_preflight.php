<?php

declare(strict_types=1);

/**
 * Preflight migrasi 012 — Fondasi Penugasan V3–V6 (keputusan pengguna
 * 7 September 2026).
 *
 * HANYA MEMBACA. Tidak menulis, tidak memperbaiki, tidak menormalkan data.
 * Dijalankan pada salinan `_test` dari backup produksi SEBELUM
 * `php bin/migrate.php up`, untuk memastikan migrasi akan berhasil:
 *
 *   0. status migrasi 011 (prasyarat) dan 012;
 *   1. versi MySQL/MariaDB dan dukungan CHECK/generated column (dibuktikan
 *      oleh kolom generated migrasi 002 yang sudah terpasang);
 *   2. tabel rujukan kunci asing tersedia dengan tipe kolom yang cocok
 *      (guru.id INT, pengurus.id BIGINT UNSIGNED, kelas.id INT,
 *      tahun_ajaran.id INT, users.id BIGINT UNSIGNED, kamar.id INT);
 *   3. nama tabel/kolom/constraint baru belum dipakai untuk hal lain;
 *   4. jumlah baris tabel penugasan lama (manifest sebelum migrasi);
 *   5. penugasan murobi/pembimbing aktif yang tanggal selesainya mendahului
 *      tanggal mulai (tidak menghalangi, hanya laporan — migrasi 012 tidak
 *      menambah CHECK pada tabel lama);
 *   6. binlog_format (transaksi READ COMMITTED pada InnoDB).
 *
 * Pemakaian:
 *   php bin/penugasan_preflight.php
 *
 * Kode keluar: 0 tidak ada penghalang; 1 ada temuan; 2 tidak dapat dijalankan.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$db = app_db();
$temuan = 0;
$peringatan = 0;

echo "Preflight migrasi 012 (fondasi penugasan V3–V6)\n";
echo 'Basis data : ' . (string) app_config('database.database') . "\n";
echo 'Waktu      : ' . date('Y-m-d H:i:s') . "\n";
echo str_repeat('-', 72) . "\n";

$skalar = static function (string $sql, array $params = []) use ($db): ?string {
    $statement = $db->prepare($sql);
    if ($statement === false) {
        return null;
    }
    if ($params !== []) {
        $types = str_repeat('s', count($params));
        $statement->bind_param($types, ...$params);
    }
    if (!$statement->execute()) {
        $statement->close();
        return null;
    }
    $row = $statement->get_result()?->fetch_row();
    $statement->close();

    return $row === null || $row === false ? null : (string) $row[0];
};
$ok = static function (bool $lulus, string $pesan, bool $penghalang = true) use (&$temuan, &$peringatan): void {
    echo ($lulus ? '[ok]      ' : ($penghalang ? '[HALANG]  ' : '[perhati] ')) . $pesan . PHP_EOL;
    if (!$lulus) {
        $penghalang ? $temuan++ : $peringatan++;
    }
};

// ------------------------------------------------------------------ 0
echo "\n## 0. Status migrasi\n";
$ada011 = $skalar("SELECT COUNT(*) FROM schema_migrations WHERE migration = '011_koreksi_alumni.sql'");
$ada012 = $skalar("SELECT COUNT(*) FROM schema_migrations WHERE migration = '012_fondasi_penugasan_v3_v6.sql'");
$ok($ada011 === '1', 'Migrasi 011 sudah diterapkan (prasyarat urutan migrasi)');
if ($ada012 === '1') {
    echo "[info]    Migrasi 012 SUDAH tercatat diterapkan. Migrasi ini idempoten, tetapi jangan dijalankan ulang tanpa alasan.\n";
}

// ------------------------------------------------------------------ 1
echo "\n## 1. Versi server\n";
$versi = (string) $db->server_info;
echo '  Versi: ' . $versi . "\n";
$generated = $skalar(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'murobi_assignments' AND COLUMN_NAME = 'target_key'"
);
$ok($generated === '1', 'Kolom generated STORED migrasi 002 terpasang (server mendukung pola yang dipakai 012)');
$check = $skalar(
    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pembimbing_assignments' AND CONSTRAINT_TYPE = 'CHECK'"
);
$ok($check !== null && (int) $check >= 1, 'CHECK constraint migrasi 006 dikenali server (bila 0: CHECK diabaikan; validasi server tetap berlaku)', false);

// ------------------------------------------------------------------ 2
echo "\n## 2. Tabel rujukan kunci asing\n";
foreach ([
    ['guru', 'id', 'int'],
    ['pengurus', 'id', 'bigint unsigned'],
    ['kelas', 'id', 'int'],
    ['kamar', 'id', 'int'],
    ['tahun_ajaran', 'id', 'int'],
    ['users', 'id', 'bigint unsigned'],
] as [$tabel, $kolom, $tipe]) {
    $aktual = $skalar(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$tabel, $kolom]
    );
    $normal = $aktual === null ? null : strtolower(preg_replace('/\(\d+\)/', '', $aktual) ?? $aktual);
    $ok($normal === $tipe, sprintf('%s.%s bertipe %s (aktual: %s)', $tabel, $kolom, $tipe, $aktual ?? 'TIDAK ADA'));
}
foreach (['murobi_assignments', 'pembimbing_assignments', 'user_roles', 'roles', 'audit_logs'] as $tabel) {
    $ok($skalar('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$tabel]) === '1', 'Tabel ' . $tabel . ' tersedia');
}

// ------------------------------------------------------------------ 3
echo "\n## 3. Nama baru belum dipakai untuk hal lain\n";
foreach (['mata_pelajaran', 'guru_mapel_assignments', 'pendidikan_assignments', 'bendahara_bulanan_assignments', 'panitia_psb_assignments', 'bendahara_psb_assignments'] as $tabel) {
    $ada = $skalar('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$tabel]);
    if ($ada === '1') {
        $kolomFondasi = $skalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tabel, $tabel === 'mata_pelajaran' ? 'kode_unique_key' : 'alasan_pengakhiran']
        );
        $ok($kolomFondasi === '1', 'Tabel ' . $tabel . ' sudah ada dan berbentuk sesuai migrasi 012 (bukan tabel lain bernama sama)');
    } else {
        $ok(true, 'Tabel ' . $tabel . ' belum ada (akan dibuat)');
    }
}
foreach (['murobi_assignments', 'pembimbing_assignments'] as $tabel) {
    foreach (['catatan', 'updated_by', 'diakhiri_pada', 'diakhiri_oleh', 'alasan_pengakhiran'] as $kolom) {
        $tipe = $skalar('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$tabel, $kolom]);
        if ($tipe === null) {
            continue;
        }
        $harap = match ($kolom) {
            'catatan', 'alasan_pengakhiran' => 'varchar(500)',
            'updated_by', 'diakhiri_oleh' => 'bigint(20) unsigned',
            default => 'datetime',
        };
        $ok(str_starts_with(strtolower($tipe), rtrim($harap, ')')) || strtolower($tipe) === $harap, $tabel . '.' . $kolom . ' sudah ada dengan tipe yang cocok (' . $tipe . ')');
    }
}

// ------------------------------------------------------------------ 4
echo "\n## 4. Manifest jumlah baris sebelum migrasi (simpan nilai ini)\n";
foreach (['murobi_assignments', 'pembimbing_assignments', 'guru', 'pengurus', 'kelas', 'tahun_ajaran', 'users', 'user_roles', 'roles', 'mapel'] as $tabel) {
    $n = $skalar('SELECT COUNT(*) FROM `' . $tabel . '`');
    echo '  ' . str_pad($tabel, 26) . ($n ?? 'tidak ada') . "\n";
}
$roleBaru = $skalar("SELECT COUNT(*) FROM roles WHERE slug NOT IN ('admin','guru','pengurus','orang_tua')");
$ok($roleBaru === '0', 'Tabel roles hanya berisi empat role dasar (migrasi 012 tidak menambah role)', false);

// ------------------------------------------------------------------ 5
echo "\n## 5. Data penugasan lama yang perlu diperhatikan (tidak menghalangi)\n";
foreach (['murobi_assignments', 'pembimbing_assignments'] as $tabel) {
    $n = $skalar('SELECT COUNT(*) FROM ' . $tabel . ' WHERE tanggal_selesai IS NOT NULL AND tanggal_selesai < tanggal_mulai');
    $ok($n === '0', $tabel . ': tidak ada baris dengan tanggal selesai mendahului tanggal mulai (ditemukan ' . ($n ?? '?') . ')', false);
}

// ------------------------------------------------------------------ 6
echo "\n## 6. Konfigurasi server\n";
$binlog = $skalar('SELECT @@binlog_format');
$ok($binlog === null || in_array(strtoupper($binlog), ['ROW', 'MIXED'], true), 'binlog_format = ' . ($binlog ?? 'tidak diketahui') . ' (ROW/MIXED diperlukan bila replikasi aktif)', false);

echo "\n" . str_repeat('-', 72) . "\n";
if ($temuan === 0) {
    echo 'TIDAK ADA PENGHALANG. ' . ($peringatan > 0 ? $peringatan . ' hal perlu diperhatikan (lihat [perhati]).' : '') . "\n";
    exit(0);
}
echo "TERDAPAT {$temuan} PENGHALANG. Jangan jalankan migrasi 012 sebelum diselesaikan.\n";
exit(1);
