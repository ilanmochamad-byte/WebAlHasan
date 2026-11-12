<?php

declare(strict_types=1);

/**
 * Seed tambahan uji redesain tampilan aplikasi gelombang 2 Fase 1, di atas DB
 * `tests/profil_impor_f1_db.sh` + `tests/profil_impor_f2_app_seed.php`.
 * Hanya menambah satu penugasan Keamanan (sbx_pengurus_b) agar matriks peran
 * memuat akun Keamanan. Seluruh data sintetis.
 *
 *   RTV2_DB=test_rtv2_app_test php tests/redesain_v2_fase1_seed.php
 */

use App\Penugasan\PenugasanJenis;

$db = getenv('RTV2_DB') ?: '';
if (!preg_match('/^test_.*_test$/', $db)) {
    fwrite(STDERR, "Ditolak: DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $db);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$conn = app_db();
$satu = static function (string $sql) use ($conn): mixed {
    $r = $conn->query($sql);
    if ($r === false) {
        throw new RuntimeException($conn->error);
    }
    return $r->fetch_row()[0] ?? null;
};
if ((int) $satu('SELECT COUNT(*) FROM keamanan_assignments') > 0) {
    fwrite(STDERR, "Seed sudah dijalankan pada DB ini.\n");
    exit(0);
}
$admin = (int) $satu("SELECT id FROM users WHERE username = 'sbx_admin'");
$pengurus = (int) $satu("SELECT pengurus_id FROM users WHERE username = 'sbx_pengurus_b'");
$tahun = (int) $satu("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
penugasan_service()->buat(
    PenugasanJenis::KEAMANAN,
    ['pengurus_id' => (string) $pengurus, 'tahun_ajaran_id' => (string) $tahun, 'tanggal_mulai' => date('Y-m-d')],
    $admin,
);
echo "Keamanan: sbx_pengurus_b\n";
