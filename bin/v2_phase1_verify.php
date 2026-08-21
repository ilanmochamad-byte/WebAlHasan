<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Verifikasi pasca-migrasi V2 Fase 1.
 *
 * Pemakaian:
 *   php bin/v2_phase1_verify.php [/path/manifest.json]
 *
 * Bila manifest preflight diberikan, jumlah dan daftar ID pengajuan lama
 * dibandingkan dengan kondisi sebelum migrasi.
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

// 1. Skema baru tersedia.
foreach ([
    'pembimbing_assignments', 'izin_pengajuan', 'izin_keputusan', 'izin_riwayat_status',
    'izin_idempotency_keys', 'notifikasi_outbox', 'perangkat_push', 'pengaturan_notifikasi',
] as $table) {
    $check($db->query('SHOW TABLES LIKE ' . "'" . $db->real_escape_string($table) . "'")?->num_rows === 1, 'Tabel ' . $table . ' tersedia');
}
$check($scalar("SELECT COUNT(*) FROM roles WHERE slug IN ('pengurus','orang_tua')") === 2, 'Role pengurus dan orang_tua tersedia');
$check($db->query("SHOW COLUMNS FROM users LIKE 'pengurus_id'")?->num_rows === 1, 'Kolom users.pengurus_id tersedia');
$check($db->query("SHOW COLUMNS FROM users LIKE 'wali_id'")?->num_rows === 1, 'Kolom users.wali_id tersedia');

// 2. Tabel lama utuh.
$check($db->query("SHOW TABLES LIKE 'perizinan'")?->num_rows === 1, 'Tabel `perizinan` lama tidak dihapus');

// 3. Jumlah dan ID pengajuan lama identik.
$legacyCount = $scalar('SELECT COUNT(*) FROM perizinan');
$migratedCount = $scalar('SELECT COUNT(*) FROM izin_pengajuan WHERE is_legacy = 1');
$check($legacyCount === $migratedCount, "Jumlah pengajuan lama sama ({$legacyCount} vs {$migratedCount})");
$check(
    $scalar('SELECT COUNT(*) FROM perizinan p LEFT JOIN izin_pengajuan t ON t.id = p.id AND t.legacy_perizinan_id = p.id WHERE t.id IS NULL') === 0,
    'Setiap ID `perizinan` lama muncul dengan ID yang sama pada izin_pengajuan'
);
$check(
    $scalar("SELECT COUNT(*) FROM perizinan p JOIN izin_pengajuan t ON t.id = p.id
             WHERE p.id_santri <> t.santri_id OR p.tgl_izin <> t.tgl_izin OR p.tgl_kembali <> t.tgl_kembali
                OR p.alasan <> t.alasan
                OR t.status <> CASE p.status WHEN 'Pending' THEN 'Diajukan' ELSE p.status END") === 0,
    'Seluruh nilai bisnis lama (santri, tanggal, alasan, status) terbaca identik'
);

// 4. Data warisan tidak menunjuk pengguna fiktif.
$check(
    $scalar('SELECT COUNT(*) FROM izin_pengajuan WHERE is_legacy = 1 AND (pengurus_id IS NOT NULL OR murobi_guru_id IS NOT NULL OR diajukan_oleh_user_id IS NOT NULL)') === 0,
    'Kolom pelaku pada data warisan tetap NULL'
);
$check(
    $scalar("SELECT COUNT(*) FROM izin_pengajuan t LEFT JOIN izin_riwayat_status r ON r.pengajuan_id = t.id AND r.peristiwa = 'migrasi_warisan' WHERE t.is_legacy = 1 AND r.id IS NULL") === 0,
    'Setiap pengajuan warisan memiliki jejak riwayat migrasi'
);
$check($scalar('SELECT COUNT(*) FROM izin_keputusan') >= 0, 'Tabel keputusan dapat dibaca');

// 5. AUTO_INCREMENT tidak akan memakai ulang ID warisan.
$autoIncrement = (int) ($db->query(
    "SELECT AUTO_INCREMENT AS nilai FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'"
)?->fetch_assoc()['nilai'] ?? 0);
$maxId = $scalar('SELECT IFNULL(MAX(id), 0) FROM izin_pengajuan');
$check($autoIncrement > $maxId, "AUTO_INCREMENT izin_pengajuan ({$autoIncrement}) melewati ID tertinggi ({$maxId})");

// 6. WhatsApp mati secara bawaan.
$check($scalar('SELECT COUNT(*) FROM pengaturan_notifikasi WHERE singleton = 1') === 1, 'Baris pengaturan kanal tunggal tersedia');
$check($scalar('SELECT whatsapp_enabled FROM pengaturan_notifikasi WHERE singleton = 1') === 0, 'WhatsApp mati secara bawaan');
$check($scalar('SELECT inapp_enabled FROM pengaturan_notifikasi WHERE singleton = 1') === 1, 'Notifikasi in-app aktif secara bawaan');

// 7. Perbandingan dengan manifest preflight (opsional).
$manifestPath = $argv[1] ?? '';
if ($manifestPath !== '') {
    if (!is_file($manifestPath)) {
        fwrite(STDERR, "Manifest tidak ditemukan: {$manifestPath}\n");
        exit(2);
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    $expected = $manifest['legacy_perizinan'] ?? null;
    if (is_array($expected)) {
        $check((int) $expected['total'] === $legacyCount, 'Jumlah `perizinan` sama dengan manifest pra-migrasi');
        $actualIds = [];
        $result = $db->query('SELECT id FROM izin_pengajuan WHERE is_legacy = 1 ORDER BY id');
        while ($result && $row = $result->fetch_assoc()) {
            $actualIds[] = (int) $row['id'];
        }
        $check($actualIds === array_map('intval', $expected['ids'] ?? []), 'Daftar ID pengajuan lama identik dengan manifest pra-migrasi');
    }
    foreach (($manifest['row_counts'] ?? []) as $table => $count) {
        if (in_array($table, ['perizinan', 'santri', 'users', 'wali', 'santri_wali', 'pengurus', 'guru'], true)) {
            $identifier = '`' . str_replace('`', '``', (string) $table) . '`';
            $check($scalar('SELECT COUNT(*) FROM ' . $identifier) === (int) $count, 'Jumlah baris `' . $table . '` tidak berubah setelah migrasi');
        }
    }
}

echo PHP_EOL . ($failures === [] ? "Verifikasi V2 Fase 1: LULUS\n" : 'Verifikasi V2 Fase 1: GAGAL (' . count($failures) . " pemeriksaan)\n");
exit($failures === [] ? 0 : 2);
