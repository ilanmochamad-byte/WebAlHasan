<?php

declare(strict_types=1);

use App\Database\BackupWriter;
use App\Notification\WhatsApp\ProviderFactory;

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Preflight V2 Fase 4.
 *
 * Menghasilkan, sebelum migrasi 008 dijalankan:
 *   - backup basis data lengkap + manifest jumlah baris,
 *   - inventaris kolom tabel notifikasi yang akan ditambah,
 *   - laporan konflik dan kesiapan kanal.
 *
 * Kode keluar:
 *   0 = aman dilanjutkan,
 *   3 = ditemukan konflik yang memblokir,
 *   2 = kesalahan lingkungan.
 *
 * Migrasi 008 bersifat aditif dan dapat dijalankan ulang, tetapi backup tetap
 * WAJIB: rollback melepas tabel percobaan pengiriman, audit kanal, dan kolom
 * operasional beserta isinya.
 *
 * Skrip ini TIDAK PERNAH mencetak nilai environment. Ia hanya melaporkan
 * apakah sebuah nama environment sudah terisi.
 */

$base = APP_ROOT . '/storage/backups/v2-phase4/' . date('Ymd_His');
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $base = rtrim(substr($argument, 9), '/');
    }
}
if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
    throw new RuntimeException('Direktori output tidak dapat dibuat: ' . $base);
}

$db = app_db();

// ---------------------------------------------------------------------------
// 1. Prasyarat: fondasi notifikasi Fase 1 dan alur Fase 2 harus sudah terpasang.
// ---------------------------------------------------------------------------
$prasyarat = [
    'izin_pengajuan', 'izin_keputusan', 'izin_riwayat_status',
    'notifikasi_outbox', 'perangkat_push', 'pengaturan_notifikasi',
    'users', 'user_roles', 'roles', 'santri_wali', 'wali', 'pengurus', 'murobi_assignments',
];
$hilang = [];
foreach ($prasyarat as $table) {
    if (($db->query('SHOW TABLES LIKE ' . "'" . $db->real_escape_string($table) . "'")?->num_rows ?? 0) !== 1) {
        $hilang[] = $table;
    }
}
if ($hilang !== []) {
    fwrite(STDERR, 'Migrasi V2 Fase 1-3 belum lengkap. Tabel hilang: ' . implode(', ', $hilang) . PHP_EOL);
    exit(2);
}

// ---------------------------------------------------------------------------
// 2. Inventaris kolom sebelum migrasi.
// ---------------------------------------------------------------------------
$inventory = [];
foreach (['notifikasi_outbox', 'perangkat_push', 'pengaturan_notifikasi'] as $table) {
    $columns = $db->query('SHOW COLUMNS FROM `' . $table . '`');
    $rows = [];
    while ($columns && $column = $columns->fetch_assoc()) {
        $rows[] = ['field' => $column['Field'], 'type' => $column['Type'], 'null' => $column['Null']];
    }
    $inventory[$table] = $rows;
}
file_put_contents($base . '/inventory.json', json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// ---------------------------------------------------------------------------
// 3. Backup + manifest jumlah baris.
// ---------------------------------------------------------------------------
$counts = (new BackupWriter($db))->write($base . '/database.sql');

$legacyIds = [];
$result = $db->query('SELECT id FROM perizinan ORDER BY id');
while ($result && $row = $result->fetch_assoc()) {
    $legacyIds[] = (int) $row['id'];
}
$manifest = [
    'generated_at' => date(DATE_ATOM),
    'database' => app_config('database.database'),
    'phase' => 'v2-phase4',
    'row_counts' => $counts,
    'legacy_perizinan' => ['total' => count($legacyIds), 'ids' => $legacyIds],
];
file_put_contents($base . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// ---------------------------------------------------------------------------
// 4. Laporan konflik dan kesiapan.
// ---------------------------------------------------------------------------
$scalar = static function (string $sql) use ($db): int {
    $result = $db->query($sql);
    return $result ? (int) array_values($result->fetch_assoc())[0] : -1;
};

$blocking = [];
$warnings = [];

// (a) Kunci unik dedup WAJIB ada sebelum Fase 4: tanpa itu retry dapat
//     menghasilkan notifikasi ganda.
$adaUnik = ($db->query(
    "SELECT COUNT(*) AS n FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox'
        AND INDEX_NAME = 'notifikasi_event_channel_recipient_unique'"
)?->fetch_assoc()['n'] ?? 0) > 0;
if (!$adaUnik) {
    $blocking[] = 'Kunci unik notifikasi_event_channel_recipient_unique tidak ada. Jalankan ulang migrasi 006.';
}

// (b) Baris outbox duplikat (kalau kunci unik pernah dilepas manual).
$duplikat = $scalar(
    'SELECT COUNT(*) FROM (
        SELECT event_key, kanal, penerima_user_id
          FROM notifikasi_outbox
         GROUP BY event_key, kanal, penerima_user_id
        HAVING COUNT(*) > 1
     ) x'
);
if ($duplikat > 0) {
    $blocking[] = "Terdapat {$duplikat} kombinasi (event, kanal, penerima) duplikat pada notifikasi_outbox.";
}

// (c) In-app WAJIB dapat dinyalakan; baris pengaturan harus ada.
$barisPengaturan = $scalar('SELECT COUNT(*) FROM pengaturan_notifikasi WHERE singleton = 1');
if ($barisPengaturan !== 1) {
    $blocking[] = "Baris pengaturan_notifikasi singleton berjumlah {$barisPengaturan} (seharusnya 1).";
}
$inappMati = $scalar('SELECT COUNT(*) FROM pengaturan_notifikasi WHERE inapp_enabled <> 1');
if ($inappMati > 0) {
    $blocking[] = 'Kolom inapp_enabled bernilai 0. Migrasi 008 memasang CHECK yang mewajibkan nilai 1; perbaiki lebih dahulu.';
}

// (d) WhatsApp menyala tanpa pemeriksaan lulus — tidak boleh terjadi.
$waTanpaLulus = $scalar(
    "SELECT COUNT(*) FROM pengaturan_notifikasi WHERE whatsapp_enabled = 1 AND whatsapp_check_status <> 'Lulus'"
);
if ($waTanpaLulus > 0) {
    $blocking[] = 'WhatsApp menyala tanpa pemeriksaan konfigurasi berstatus Lulus.';
}

// (e) Token perangkat yatim (pengguna sudah dihapus) — hanya peringatan.
$perangkatYatim = $scalar(
    'SELECT COUNT(*) FROM perangkat_push p LEFT JOIN users u ON u.id = p.user_id WHERE u.id IS NULL'
);
if ($perangkatYatim > 0) {
    $warnings[] = "Perangkat push tanpa pengguna: {$perangkatYatim} baris.";
}

// (f) Kesiapan environment. HANYA nama yang dilaporkan, tidak pernah nilainya.
$envTerisi = static fn (string $name): bool => trim((string) getenv($name)) !== '';
$kesiapanEnv = [
    'PUSH_TOKEN_KEY' => $envTerisi('PUSH_TOKEN_KEY'),
    'EXPO_ACCESS_TOKEN' => $envTerisi('EXPO_ACCESS_TOKEN'),
    'WHATSAPP_PROVIDER' => $envTerisi('WHATSAPP_PROVIDER'),
];
foreach (ProviderFactory::ENV_KEYS['http'] as $key) {
    $kesiapanEnv[$key] = $envTerisi($key);
}
if (!$kesiapanEnv['PUSH_TOKEN_KEY']) {
    $warnings[] = 'PUSH_TOKEN_KEY belum diisi: registrasi perangkat push akan ditolak sampai environment diisi.';
}
if (!$kesiapanEnv['WHATSAPP_PROVIDER']) {
    $warnings[] = 'WHATSAPP_PROVIDER belum diisi: WhatsApp tetap mati dan tidak ada permintaan ke penyedia (kondisi default yang dikehendaki).';
}
if (!extension_loaded('openssl')) {
    $blocking[] = 'Ekstensi PHP openssl tidak aktif; token push tidak dapat dilindungi.';
}

// (g) Kesiapan penerima: akun aktif per peran.
$penerima = [
    'admin' => $scalar(
        "SELECT COUNT(DISTINCT u.id) FROM users u
           JOIN user_roles ur ON ur.user_id = u.id
           JOIN roles r ON r.id = ur.role_id AND r.slug = 'admin'
          WHERE u.is_active = 1"
    ),
    'pengurus_terhubung' => $scalar(
        'SELECT COUNT(*) FROM users u JOIN pengurus p ON p.id = u.pengurus_id
          WHERE u.is_active = 1 AND p.is_active = 1 AND p.archived_at IS NULL'
    ),
    'orang_tua_terhubung' => $scalar(
        'SELECT COUNT(*) FROM users u JOIN wali w ON w.id = u.wali_id
          WHERE u.is_active = 1 AND w.is_active = 1 AND w.archived_at IS NULL'
    ),
    'guru_murobi_aktif' => $scalar(
        "SELECT COUNT(DISTINCT u.id) FROM users u
           JOIN guru g ON g.id = u.guru_id
           JOIN murobi_assignments ma ON ma.guru_id = g.id
           JOIN tahun_ajaran ta ON ta.id = ma.tahun_ajaran_id
          WHERE u.is_active = 1 AND g.is_active = 1 AND g.archived_at IS NULL
            AND ma.is_active = 1 AND ma.archived_at IS NULL
            AND ma.tanggal_mulai <= CURDATE()
            AND (ma.tanggal_selesai IS NULL OR ma.tanggal_selesai >= CURDATE())
            AND ta.status = 'Aktif' AND ta.archived_at IS NULL"
    ),
];
if ($penerima['admin'] === 0) {
    $warnings[] = 'Tidak ada akun admin aktif: pengajuan yang perlu penetapan tidak akan memiliki penerima notifikasi.';
}

$report = [
    'generated_at' => date(DATE_ATOM),
    'blocking' => $blocking,
    'warnings' => $warnings,
    // Nilai boolean: terisi atau tidak. Isinya TIDAK pernah dicatat.
    'environment_terisi' => $kesiapanEnv,
    'readiness' => [
        'notifikasi_outbox' => $scalar('SELECT COUNT(*) FROM notifikasi_outbox'),
        'perangkat_push_aktif' => $scalar('SELECT COUNT(*) FROM perangkat_push WHERE dicabut_pada IS NULL'),
        'penerima' => $penerima,
    ],
];
file_put_contents($base . '/conflicts.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo 'Preflight V2 Fase 4 selesai. Output: ' . $base . PHP_EOL;
echo '- database.sql, manifest.json, inventory.json, conflicts.json' . PHP_EOL;
foreach ($penerima as $label => $value) {
    echo '  · penerima ' . str_pad((string) $label, 22) . ': ' . $value . PHP_EOL;
}
foreach ($kesiapanEnv as $name => $terisi) {
    echo '  · env ' . str_pad((string) $name, 27) . ': ' . ($terisi ? 'terisi' : 'kosong') . PHP_EOL;
}
foreach ($warnings as $warning) {
    echo '[perhatian] ' . $warning . PHP_EOL;
}
foreach ($blocking as $problem) {
    echo '[blokir]    ' . $problem . PHP_EOL;
}

exit($blocking === [] ? 0 : 3);
