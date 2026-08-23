<?php

declare(strict_types=1);

/**
 * Menyiapkan satu skenario perizinan nyata agar halaman notifikasi berisi data
 * ketika uji browser dijalankan.
 *
 * HANYA untuk basis data sandbox `*_test` berisi data sintetis. Skrip menolak
 * berjalan pada basis data lain.
 *
 * Menghasilkan dua pengajuan:
 *  - satu yang jatuh ke murobi (alasan sengaja khas, dipakai untuk membuktikan
 *    alasan izin TIDAK pernah bocor ke notifikasi);
 *  - satu yang berstatus "Perlu Penetapan Admin".
 *
 * Keluaran: JSON berisi id kedua pengajuan.
 */

// Kunci uji lokal; bukan credential dan tidak berlaku di lingkungan mana pun
// selain sandbox pengujian ini.
putenv('PUSH_TOKEN_KEY=' . base64_encode(str_repeat("\x2b", 32)));

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, 'Skrip ini hanya boleh dijalankan pada basis data *_test.' . PHP_EOL);
    exit(2);
}

$db = app_db();
$cari = static function (string $sql) use ($db): int {
    $row = $db->query($sql)?->fetch_assoc();
    if ($row === null) {
        fwrite(STDERR, 'Fixture sandbox belum tersedia: ' . $sql . PHP_EOL);
        exit(3);
    }

    return (int) $row['id'];
};

$adminId = $cari("SELECT id FROM users WHERE username = 'sbx_admin'");
$pengurusId = $cari("SELECT id FROM users WHERE username = 'sbx_pengurus_a'");
$santriMurobi = $cari("SELECT id FROM santri WHERE nis = 'SBX-S-001'");
$santriAdmin = $cari("SELECT id FROM santri WHERE nis = 'SBX-S-004'");

$_SESSION = ['user_id' => $adminId];
notification_settings_repository()->setPushEnabled(false, $adminId);
notification_settings_repository()->setWhatsappEnabled(false, $adminId);

$workflow = izin_workflow_service();
$pengurus = auth_repository()->findActiveById($pengurusId);
$meta = ['ip' => '127.0.0.1', 'user_agent' => 'uji-browser'];

// Tanggal sengaja jauh ke depan agar tidak bertabrakan dengan rentang yang
// dipakai rangkaian uji otomatis lain pada santri yang sama.
$a = $workflow->create(
    $pengurus,
    [
        'santri_id' => $santriMurobi,
        'tgl_izin' => date('Y-m-d', strtotime('+120 days')),
        'tgl_kembali' => date('Y-m-d', strtotime('+121 days')),
        'alasan' => 'Menghadiri pernikahan kakak kandung di luar kota',
        'catatan_pengurus' => 'Dijemput orang tua',
    ],
    'browser-a-' . bin2hex(random_bytes(4)),
    $meta,
);

$b = $workflow->create(
    $pengurus,
    [
        'santri_id' => $santriAdmin,
        'tgl_izin' => date('Y-m-d', strtotime('+124 days')),
        'tgl_kembali' => date('Y-m-d', strtotime('+125 days')),
        'alasan' => 'Kontrol kesehatan rutin ke rumah sakit',
        'catatan_pengurus' => '',
    ],
    'browser-b-' . bin2hex(random_bytes(4)),
    $meta,
);

echo json_encode([
    'pengajuan_murobi' => $a['id'],
    'status_a' => $a['status'],
    'pengajuan_admin' => $b['id'],
    'status_b' => $b['status'],
]), PHP_EOL;
