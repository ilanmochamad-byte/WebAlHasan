<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Verifikasi pasca-migrasi V2 Fase 4.
 *
 * Pemakaian:
 *   php bin/v2_phase4_verify.php [/path/manifest.json]
 *
 * Memeriksa skema Fase 4, keutuhan data Fase 1-3, dan invarian notifikasi:
 * tidak ada duplikat (event, kanal, penerima), in-app tidak dapat dimatikan,
 * WhatsApp tidak menyala tanpa pemeriksaan lulus, dan tidak ada token
 * perangkat yang tersimpan dalam bentuk terbaca.
 *
 * Skrip ini tidak pernah mencetak token, nomor tujuan, atau nilai environment.
 */

$db = app_db();
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? '[sesuai]  ' : '[berbeda] ') . $message . PHP_EOL;
    if (!$ok) {
        $failures[] = $message;
    }
};
$scalar = static function (string $sql) use ($db): int {
    $result = $db->query($sql);
    return $result ? (int) array_values($result->fetch_assoc())[0] : -1;
};
$hasColumn = static fn (string $table, string $column): bool =>
    ($db->query('SHOW COLUMNS FROM `' . $table . "` LIKE '" . $db->real_escape_string($column) . "'")?->num_rows ?? 0) === 1;
$hasTable = static fn (string $table): bool =>
    ($db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'")?->num_rows ?? 0) === 1;
$hasIndex = static fn (string $table, string $index): bool => ((int) ($db->query(
    "SELECT COUNT(*) AS n FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $db->real_escape_string($table) . "'
        AND INDEX_NAME = '" . $db->real_escape_string($index) . "'"
)?->fetch_assoc()['n'] ?? 0)) > 0;

echo '=== 1. Skema Fase 4 ===' . PHP_EOL;
foreach (['data_json', 'tersedia_pada', 'gagal_permanen', 'error_kode', 'locked_by', 'locked_until'] as $column) {
    $check($hasColumn('notifikasi_outbox', $column), 'Kolom notifikasi_outbox.' . $column . ' tersedia');
}
foreach (['device_id', 'app_version', 'push_aktif', 'alasan_pencabutan', 'gagal_berturut'] as $column) {
    $check($hasColumn('perangkat_push', $column), 'Kolom perangkat_push.' . $column . ' tersedia');
}
foreach (['whatsapp_check_oleh_user_id', 'push_check_status', 'push_check_pesan', 'push_check_pada'] as $column) {
    $check($hasColumn('pengaturan_notifikasi', $column), 'Kolom pengaturan_notifikasi.' . $column . ' tersedia');
}
foreach (['notifikasi_percobaan', 'notifikasi_pengaturan_audit', 'notifikasi_worker_lock'] as $table) {
    $check($hasTable($table), 'Tabel ' . $table . ' tersedia');
}
foreach ([
    ['notifikasi_outbox', 'notifikasi_event_channel_recipient_unique'],
    ['notifikasi_outbox', 'notifikasi_worker_index'],
    ['notifikasi_outbox', 'notifikasi_inapp_index'],
    ['perangkat_push', 'perangkat_push_user_device_unique'],
    ['perangkat_push', 'perangkat_push_kirim_index'],
] as [$table, $index]) {
    $check($hasIndex($table, $index), 'Indeks ' . $table . '.' . $index . ' tersedia');
}

echo PHP_EOL . '=== 2. Invarian notifikasi ===' . PHP_EOL;
$check(
    $scalar(
        'SELECT COUNT(*) FROM (
            SELECT event_key, kanal, penerima_user_id FROM notifikasi_outbox
             GROUP BY event_key, kanal, penerima_user_id HAVING COUNT(*) > 1
         ) x'
    ) === 0,
    'Tidak ada duplikat kombinasi (event_key, kanal, penerima)'
);
$check(
    $scalar('SELECT COUNT(*) FROM pengaturan_notifikasi WHERE inapp_enabled <> 1') === 0,
    'Notifikasi in-app tetap aktif pada seluruh baris pengaturan'
);
$check(
    $scalar("SELECT COUNT(*) FROM pengaturan_notifikasi WHERE whatsapp_enabled = 1 AND whatsapp_check_status <> 'Lulus'") === 0,
    'WhatsApp tidak menyala tanpa pemeriksaan konfigurasi berstatus Lulus'
);
$check(
    $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE kanal = 'InApp' AND status <> 'Sent'") === 0,
    'Seluruh notifikasi in-app berstatus Sent (tidak menunggu penyedia eksternal)'
);
$check(
    $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE kanal = 'InApp' AND gagal_permanen = 1") === 0,
    'Tidak ada notifikasi in-app yang ditandai gagal permanen'
);
$check(
    $scalar('SELECT COUNT(*) FROM notifikasi_percobaan p LEFT JOIN notifikasi_outbox o ON o.id = p.outbox_id WHERE o.id IS NULL') === 0,
    'Tidak ada riwayat percobaan yatim'
);

echo PHP_EOL . '=== 3. Perlindungan token perangkat ===' . PHP_EOL;
$check(
    $scalar("SELECT COUNT(*) FROM perangkat_push WHERE token_hash NOT REGEXP '^[0-9a-f]{64}$'") === 0,
    'Seluruh token perangkat tersimpan sebagai hash heksadesimal 64 karakter'
);
$check(
    $scalar("SELECT COUNT(*) FROM perangkat_push WHERE token_terlindungi LIKE '%ExponentPushToken%' OR token_terlindungi LIKE '%ExpoPushToken%'") === 0,
    'Tidak ada token perangkat tersimpan dalam bentuk terbaca'
);
$check(
    $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE isi LIKE '%ExponentPushToken%' OR judul LIKE '%ExponentPushToken%' OR COALESCE(data_json,'') LIKE '%ExponentPushToken%'") === 0,
    'Tidak ada token perangkat yang bocor ke isi notifikasi'
);
$check(
    $scalar("SELECT COUNT(*) FROM notifikasi_pengaturan_audit WHERE COALESCE(pesan,'') REGEXP '[0-9]{8,}'") === 0,
    'Audit kanal tidak memuat rangkaian angka panjang (kandidat nomor/credential)'
);

echo PHP_EOL . '=== 4. Keutuhan data Fase 1-3 ===' . PHP_EOL;
$manifestPath = $argv[1] ?? null;
if ($manifestPath !== null && is_file($manifestPath)) {
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (is_array($manifest) && isset($manifest['legacy_perizinan']['total'])) {
        $check(
            $scalar('SELECT COUNT(*) FROM perizinan') === (int) $manifest['legacy_perizinan']['total'],
            'Jumlah baris perizinan lama sama dengan manifest preflight'
        );
        $check(
            $scalar('SELECT COUNT(*) FROM izin_pengajuan WHERE is_legacy = 1') === (int) $manifest['legacy_perizinan']['total'],
            'Jumlah pengajuan warisan tetap sama dengan manifest preflight'
        );
    } else {
        echo '[lewati]  Manifest tidak memuat data perizinan lama.' . PHP_EOL;
    }
} else {
    echo '[lewati]  Manifest tidak diberikan; perbandingan jumlah baris dilewati.' . PHP_EOL;
}
$check(
    $scalar('SELECT COUNT(*) FROM izin_pengajuan p LEFT JOIN santri s ON s.id = p.santri_id WHERE s.id IS NULL') === 0,
    'Tidak ada pengajuan tanpa santri (relasi Fase 1 utuh)'
);
$check(
    $scalar('SELECT COUNT(*) FROM (SELECT pengajuan_id FROM izin_keputusan GROUP BY pengajuan_id HAVING COUNT(*) > 1) x') === 0,
    'Tetap satu keputusan per pengajuan'
);

echo PHP_EOL;
if ($failures === []) {
    echo 'Verifikasi V2 Fase 4 LULUS.' . PHP_EOL;
    exit(0);
}
echo 'Verifikasi V2 Fase 4 menemukan ' . count($failures) . ' ketidaksesuaian:' . PHP_EOL;
foreach ($failures as $failure) {
    echo '- ' . $failure . PHP_EOL;
}
exit(3);
