<?php

declare(strict_types=1);

use App\Database\BackupWriter;

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Preflight V2 Fase 5 — dijalankan SEBELUM migrasi 009.
 *
 * Menghasilkan:
 *   - backup basis data lengkap + manifest jumlah baris seluruh tabel,
 *   - daftar ID perizinan lama (untuk membuktikan ID tidak berubah),
 *   - sidik jari nilai bisnis perizinan lama (untuk membuktikan nilai tidak berubah),
 *   - inventaris kolom `notifikasi_outbox` sebelum kolom receipt ditambahkan,
 *   - laporan konflik yang memblokir dan peringatan.
 *
 * Kode keluar:
 *   0 = aman dilanjutkan,
 *   3 = ditemukan konflik yang memblokir,
 *   2 = kesalahan lingkungan.
 *
 * Skrip ini HANYA MEMBACA basis data (selain menulis berkas backup ke disk).
 * Ia TIDAK PERNAH mencetak nilai environment, credential, atau isi secret; ia
 * hanya melaporkan apakah sebuah nama environment sudah terisi.
 *
 * Pemakaian:
 *   php bin/v2_phase5_preflight.php [--output=/path/folder]
 */

$base = APP_ROOT . '/storage/backups/v2-phase5/' . date('Ymd_His');
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $base = rtrim(substr($argument, 9), '/');
    }
}
if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
    throw new RuntimeException('Direktori output tidak dapat dibuat: ' . $base);
}

$db = app_db();

$scalar = static function (string $sql) use ($db): int {
    $result = $db->query($sql);

    return $result ? (int) array_values($result->fetch_assoc())[0] : -1;
};
$adaTabel = static function (string $table) use ($db): bool {
    return ($db->query('SHOW TABLES LIKE ' . "'" . $db->real_escape_string($table) . "'")?->num_rows ?? 0) === 1;
};

echo '=== Preflight V2 Fase 5 ===' . PHP_EOL;
echo 'Database : ' . app_config('database.database') . PHP_EOL;
echo 'Output   : ' . $base . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------------
// 1. Prasyarat: seluruh fondasi Fase 1-4 wajib sudah terpasang.
// ---------------------------------------------------------------------------
$prasyarat = [
    'perizinan', 'izin_pengajuan', 'izin_keputusan', 'izin_riwayat_status',
    'izin_keputusan_koreksi', 'izin_idempotency_keys',
    'notifikasi_outbox', 'perangkat_push', 'pengaturan_notifikasi',
    'users', 'user_roles', 'roles', 'santri', 'santri_wali', 'wali', 'pengurus',
    'guru', 'murobi_assignments', 'pembimbing_assignments',
    'plotting_kamar', 'plotting_kelas', 'kamar', 'kelas', 'tahun_ajaran',
];
$hilang = array_values(array_filter($prasyarat, static fn (string $t): bool => !$adaTabel($t)));
if ($hilang !== []) {
    fwrite(STDERR, 'Migrasi V2 Fase 1-4 belum lengkap. Tabel hilang: ' . implode(', ', $hilang) . PHP_EOL);
    exit(2);
}
echo '[ok] Seluruh ' . count($prasyarat) . ' tabel prasyarat Fase 1-4 tersedia.' . PHP_EOL;

// ---------------------------------------------------------------------------
// 2. Inventaris kolom sebelum migrasi 009.
// ---------------------------------------------------------------------------
$inventory = [];
foreach (['notifikasi_outbox', 'izin_pengajuan', 'izin_keputusan'] as $table) {
    $columns = $db->query('SHOW COLUMNS FROM `' . $table . '`');
    $rows = [];
    while ($columns && $column = $columns->fetch_assoc()) {
        $rows[] = ['field' => $column['Field'], 'type' => $column['Type'], 'null' => $column['Null']];
    }
    $inventory[$table] = $rows;
}
file_put_contents($base . '/inventory.json', json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo '[ok] Inventaris kolom disimpan: inventory.json' . PHP_EOL;

// ---------------------------------------------------------------------------
// 3. Backup + manifest.
// ---------------------------------------------------------------------------
$counts = (new BackupWriter($db))->write($base . '/database.sql');
echo '[ok] Backup ditulis: database.sql (' . count($counts) . ' tabel)' . PHP_EOL;

/**
 * Sidik jari nilai bisnis perizinan lama.
 *
 * Bukan sekadar jumlah baris: hash ini ikut memuat setiap nilai bisnis yang
 * PRD 5.5 wajibkan tidak berubah (`id`, `id_santri`, `tgl_izin`, `tgl_kembali`,
 * `alasan`, `status`). Dengan begitu, perubahan diam-diam pada salah satu nilai
 * akan terdeteksi verifikasi pasca-migrasi, bukan hanya penghapusan baris.
 */
$legacyIds = [];
$legacyRows = [];
$result = $db->query('SELECT id, id_santri, tgl_izin, tgl_kembali, alasan, status FROM perizinan ORDER BY id');
while ($result && $row = $result->fetch_assoc()) {
    $legacyIds[] = (int) $row['id'];
    $legacyRows[] = implode('|', [
        (string) $row['id'],
        (string) $row['id_santri'],
        (string) $row['tgl_izin'],
        (string) $row['tgl_kembali'],
        (string) $row['alasan'],
        (string) $row['status'],
    ]);
}
$legacyFingerprint = hash('sha256', implode("\n", $legacyRows));

$manifest = [
    'generated_at' => date(DATE_ATOM),
    'database' => app_config('database.database'),
    'phase' => 'v2-phase5',
    'row_counts' => $counts,
    'legacy_perizinan' => [
        'total' => count($legacyIds),
        'ids' => $legacyIds,
        'fingerprint_sha256' => $legacyFingerprint,
    ],
    'izin_pengajuan' => [
        'total' => $scalar('SELECT COUNT(*) FROM izin_pengajuan'),
        'warisan' => $scalar('SELECT COUNT(*) FROM izin_pengajuan WHERE is_legacy = 1'),
        'maks_id' => $scalar('SELECT IFNULL(MAX(id), 0) FROM izin_pengajuan'),
    ],
    'izin_keputusan_total' => $scalar('SELECT COUNT(*) FROM izin_keputusan'),
];
file_put_contents($base . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo '[ok] Manifest ditulis: manifest.json' . PHP_EOL;
echo '     perizinan lama : ' . count($legacyIds) . ' baris, sidik jari ' . substr($legacyFingerprint, 0, 16) . PHP_EOL;

// ---------------------------------------------------------------------------
// 4. Laporan konflik.
// ---------------------------------------------------------------------------
$blocking = [];
$warnings = [];

// (a) Setiap baris `perizinan` WAJIB sudah termigrasi ke `izin_pengajuan`
//     dengan ID yang sama. Ini prasyarat klaim "ID tidak berubah".
$belumTermigrasi = $scalar(
    'SELECT COUNT(*) FROM perizinan p
      WHERE NOT EXISTS (SELECT 1 FROM izin_pengajuan t WHERE t.legacy_perizinan_id = p.id)'
);
if ($belumTermigrasi > 0) {
    $blocking[] = "Terdapat {$belumTermigrasi} baris `perizinan` yang belum termigrasi. Jalankan bin/v2_phase1_backfill.php lebih dulu.";
}

$idBergeser = $scalar(
    'SELECT COUNT(*) FROM izin_pengajuan t
      JOIN perizinan p ON p.id = t.legacy_perizinan_id
     WHERE t.id <> p.id'
);
if ($idBergeser > 0) {
    $blocking[] = "Terdapat {$idBergeser} pengajuan warisan yang ID-nya BERGESER dari ID `perizinan` asalnya.";
}

// (b) Nilai bisnis warisan harus identik dengan sumbernya.
$nilaiBerbeda = $scalar(
    'SELECT COUNT(*) FROM izin_pengajuan t
      JOIN perizinan p ON p.id = t.legacy_perizinan_id
     WHERE t.santri_id <> p.id_santri
        OR t.tgl_izin <> p.tgl_izin
        OR t.tgl_kembali <> p.tgl_kembali
        OR t.alasan <> p.alasan'
);
if ($nilaiBerbeda > 0) {
    $blocking[] = "Terdapat {$nilaiBerbeda} pengajuan warisan yang nilai bisnisnya berbeda dari `perizinan`.";
}

// (c) Data warisan tidak boleh menunjuk pelaku fiktif (PRD 5.5 poin 3).
$warisanBerpelaku = $scalar(
    'SELECT COUNT(*) FROM izin_pengajuan
      WHERE is_legacy = 1
        AND (pengurus_id IS NOT NULL OR diajukan_oleh_user_id IS NOT NULL OR murobi_guru_id IS NOT NULL)'
);
if ($warisanBerpelaku > 0) {
    $blocking[] = "Terdapat {$warisanBerpelaku} baris warisan yang memiliki pelaku. Data warisan wajib berpelaku NULL.";
}

// (d) WhatsApp tidak boleh menyala tanpa pemeriksaan lulus (keputusan produk:
//     WhatsApp DITANGGUHKAN dan tetap default OFF).
$waMenyala = $scalar('SELECT COUNT(*) FROM pengaturan_notifikasi WHERE whatsapp_enabled = 1');
if ($waMenyala > 0) {
    $blocking[] = 'WhatsApp dalam keadaan MENYALA. Keputusan produk 26 Agustus 2026: WhatsApp ditangguhkan dan wajib OFF sebelum rilis.';
}

// (e) Kolom receipt: bila sudah ada, migrasi 009 pernah dijalankan. Bukan galat.
$adaKolomReceipt = $scalar(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'receipt_status'"
) > 0;
if ($adaKolomReceipt) {
    $warnings[] = 'Kolom receipt sudah ada: migrasi 009 tampaknya sudah pernah dijalankan. Migrasi bersifat idempoten sehingga aman diulang.';
}

// (f) Pengajuan yatim (santri sudah hilang) — memblokir laporan yang benar.
$pengajuanYatim = $scalar(
    'SELECT COUNT(*) FROM izin_pengajuan t LEFT JOIN santri s ON s.id = t.santri_id WHERE s.id IS NULL'
);
if ($pengajuanYatim > 0) {
    $blocking[] = "Terdapat {$pengajuanYatim} pengajuan tanpa santri. Laporan akan menghilangkan baris ini secara diam-diam.";
}

// (g) Keputusan ganda untuk satu pengajuan — tidak boleh ada.
$keputusanGanda = $scalar(
    'SELECT COUNT(*) FROM (SELECT pengajuan_id FROM izin_keputusan GROUP BY pengajuan_id HAVING COUNT(*) > 1) x'
);
if ($keputusanGanda > 0) {
    $blocking[] = "Terdapat {$keputusanGanda} pengajuan dengan lebih dari satu keputusan.";
}

// (h) Peringatan operasional: durasi keputusan negatif menandakan jam server
//     pernah mundur; median laporan akan menyesatkan.
$durasiNegatif = $scalar(
    'SELECT COUNT(*) FROM izin_pengajuan p JOIN izin_keputusan k ON k.pengajuan_id = p.id
      WHERE p.diajukan_pada IS NOT NULL AND k.diputus_pada < p.diajukan_pada'
);
if ($durasiNegatif > 0) {
    $warnings[] = "Terdapat {$durasiNegatif} keputusan yang waktunya mendahului pengajuan. Periksa zona waktu/jam server.";
}

// (i) Kesiapan environment — DILAPORKAN SEBAGAI TERISI/KOSONG, bukan nilainya.
foreach ([
    'API_TOKEN_HASH_SECRET' => true,
    'PUSH_TOKEN_KEY' => false,
    'EXPO_ACCESS_TOKEN' => false,
] as $nama => $wajib) {
    $terisi = trim((string) getenv($nama)) !== '' || trim((string) (\App\Support\Env::get($nama, '') ?? '')) !== '';
    if (!$terisi && $wajib) {
        $blocking[] = "Environment {$nama} belum terisi.";
    } elseif (!$terisi) {
        $warnings[] = "Environment {$nama} belum terisi (push tidak akan berjalan sampai diisi).";
    }
}

$laporan = [
    'generated_at' => date(DATE_ATOM),
    'blocking' => $blocking,
    'warnings' => $warnings,
];
file_put_contents($base . '/conflicts.json', json_encode($laporan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo PHP_EOL . '--- Laporan konflik ---' . PHP_EOL;
foreach ($warnings as $warning) {
    echo '[peringatan] ' . $warning . PHP_EOL;
}
foreach ($blocking as $block) {
    echo '[MEMBLOKIR] ' . $block . PHP_EOL;
}
if ($blocking === [] && $warnings === []) {
    echo 'Tidak ada konflik maupun peringatan.' . PHP_EOL;
}

echo PHP_EOL;
if ($blocking !== []) {
    echo 'PREFLIGHT GAGAL: perbaiki konflik yang memblokir sebelum menjalankan migrasi 009.' . PHP_EOL;
    exit(3);
}
echo 'PREFLIGHT LULUS. Backup dan manifest tersimpan di: ' . $base . PHP_EOL;
echo 'Langkah berikutnya: uji restore pada database _test, lalu php bin/migrate.php up.' . PHP_EOL;
exit(0);
