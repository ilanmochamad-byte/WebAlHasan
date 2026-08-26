<?php

declare(strict_types=1);

use App\Database\BackupWriter;

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Latihan (drill) backup → restore → verifikasi V2 Fase 5.
 *
 * Membuktikan tiga kriteria penerimaan PRD Fase 5 sekaligus, END TO END:
 *
 *   1. "Backup dipulihkan pada database `_test` dan seluruh jumlah baris inti
 *      cocok dengan manifest backup";
 *   2. "Jumlah dan ID perizinan lama sama sebelum/sesudah migrasi";
 *   3. migrasi 009 tidak mengubah satu pun nilai bisnis data lama.
 *
 * Alur:
 *   a. menyuntik data warisan sintetis ke `perizinan` (awalan alasan `DRILL5`)
 *      supaya kriteria ID/nilai benar-benar diuji terhadap data, bukan terhadap
 *      tabel kosong — tabel kosong akan lolos secara palsu;
 *   b. menjalankan backfill Fase 1 agar baris warisan masuk ke `izin_pengajuan`;
 *   c. mencatat sidik jari nilai bisnis SEBELUM migrasi;
 *   d. menjalankan backup + manifest (sama seperti preflight);
 *   e. memulihkan backup ke database `_test` KEDUA yang terpisah;
 *   f. mencocokkan jumlah baris hasil restore dengan manifest;
 *   g. menjalankan migrasi 009 pada database pulihan, lalu membuktikan ID,
 *      jumlah, dan nilai bisnis data lama TIDAK berubah.
 *
 * PENJAGA KERAS:
 *   - hanya CLI;
 *   - database sumber DAN tujuan wajib berakhiran `_test`;
 *   - menolak `APP_ENV=production`;
 *   - tidak pernah dijalankan pada produksi (PRD Fase 5: "Jangan menjalankan
 *     migrasi atau restore pada produksi dalam pekerjaan ini").
 *
 * Pemakaian:
 *   V2_PHASE5_DRILL=1 php bin/v2_phase5_backup_restore_drill.php [--target=nama_db_test]
 */

if (getenv('V2_PHASE5_DRILL') !== '1') {
    fwrite(STDERR, "Tolak: setel V2_PHASE5_DRILL=1 untuk menjalankan latihan backup/restore.\n");
    exit(2);
}
$sumber = (string) app_config('database.database');
if (!str_ends_with($sumber, '_test')) {
    fwrite(STDERR, "Tolak: DB_NAME (`{$sumber}`) wajib berakhiran _test.\n");
    exit(2);
}
if (strtolower((string) app_config('env')) === 'production') {
    fwrite(STDERR, "Tolak: APP_ENV=production. Latihan restore tidak pernah dijalankan pada produksi.\n");
    exit(2);
}

$target = $sumber . '_restore';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--target=')) {
        $target = substr($argument, 9);
    }
}
if (!str_ends_with($target, '_test') && !str_ends_with($target, '_test_restore')) {
    fwrite(STDERR, "Tolak: database tujuan (`{$target}`) wajib berakhiran _test atau _test_restore.\n");
    exit(2);
}
if (preg_match('/^[A-Za-z0-9_]+$/', $target) !== 1) {
    fwrite(STDERR, "Tolak: nama database tujuan tidak valid.\n");
    exit(2);
}

$db = app_db();
$gagal = [];
$lulus = 0;
$assert = static function (bool $kondisi, string $pesan) use (&$gagal, &$lulus): void {
    echo ($kondisi ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    if ($kondisi) {
        $lulus++;
    } else {
        $gagal[] = $pesan;
    }
};
$scalar = static function (string $sql) use ($db): int {
    $result = $db->query($sql);

    return $result ? (int) array_values($result->fetch_assoc())[0] : -1;
};

echo '=== Latihan backup → restore → verifikasi (V2 Fase 5) ===' . PHP_EOL;
echo 'Sumber : ' . $sumber . PHP_EOL;
echo 'Tujuan : ' . $target . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------------
// a. Data warisan sintetis
// ---------------------------------------------------------------------------
echo '--- a. Menyiapkan data warisan sintetis ---' . PHP_EOL;

$santriId = $scalar('SELECT id FROM santri ORDER BY id LIMIT 1');
if ($santriId < 1) {
    fwrite(STDERR, "Tolak: tidak ada santri pada database uji. Jalankan bin/v2_phase3_sandbox_seed.php lebih dulu.\n");
    exit(2);
}

// Bersihkan sisa drill sebelumnya supaya latihan selalu berangkat dari
// keadaan yang sama dan dapat diulang auditor.
$db->query("DELETE r FROM izin_riwayat_status r JOIN izin_pengajuan p ON p.id = r.pengajuan_id
             WHERE p.alasan LIKE 'DRILL5%'");
$db->query("DELETE FROM izin_pengajuan WHERE alasan LIKE 'DRILL5%'");
$db->query("DELETE FROM perizinan WHERE alasan LIKE 'DRILL5%'");

$statusWarisan = ['Pending', 'Disetujui', 'Ditolak'];
for ($i = 1; $i <= 30; $i++) {
    $statement = $db->prepare(
        'INSERT INTO perizinan (id_santri, tgl_izin, tgl_kembali, alasan, status) VALUES (?, ?, ?, ?, ?)'
    );
    $tglIzin = date('Y-m-d', strtotime('2024-01-01 +' . ($i * 3) . ' days'));
    $tglKembali = date('Y-m-d', strtotime($tglIzin . ' +2 days'));
    $alasan = sprintf('DRILL5 alasan warisan nomor %02d', $i);
    $status = $statusWarisan[$i % 3];
    $statement->bind_param('issss', $santriId, $tglIzin, $tglKembali, $alasan, $status);
    $statement->execute();
    $statement->close();
}
$jumlahWarisan = $scalar("SELECT COUNT(*) FROM perizinan WHERE alasan LIKE 'DRILL5%'");
echo '[ok] ' . $jumlahWarisan . ' baris warisan sintetis dibuat pada `perizinan`.' . PHP_EOL;

// ---------------------------------------------------------------------------
// b. Backfill Fase 1
// ---------------------------------------------------------------------------
echo PHP_EOL . '--- b. Backfill warisan ke izin_pengajuan ---' . PHP_EOL;
$backfill = APP_ROOT . '/bin/v2_phase1_backfill.php';
if (!is_file($backfill)) {
    fwrite(STDERR, "Tolak: bin/v2_phase1_backfill.php tidak ditemukan.\n");
    exit(2);
}
$keluaranBackfill = [];
$kodeBackfill = 1;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($backfill) . ' 2>&1', $keluaranBackfill, $kodeBackfill);
echo '    ' . implode(PHP_EOL . '    ', array_slice($keluaranBackfill, -5)) . PHP_EOL;
$assert(
    $kodeBackfill === 0,
    'Backfill Fase 1 berjalan tanpa galat'
        . ($kodeBackfill === 0 ? '' : ' (kode ' . $kodeBackfill . ')')
);
$assert(
    $scalar("SELECT COUNT(*) FROM izin_pengajuan WHERE alasan LIKE 'DRILL5%' AND is_legacy = 1") === $jumlahWarisan,
    'Seluruh ' . $jumlahWarisan . ' baris warisan sintetis termigrasi ke `izin_pengajuan`'
);

// ---------------------------------------------------------------------------
// c. Sidik jari SEBELUM migrasi
// ---------------------------------------------------------------------------
$sidikJari = static function (mysqli $koneksi): array {
    $ids = [];
    $baris = [];
    $result = $koneksi->query('SELECT id, id_santri, tgl_izin, tgl_kembali, alasan, status FROM perizinan ORDER BY id');
    while ($result && $row = $result->fetch_assoc()) {
        $ids[] = (int) $row['id'];
        $baris[] = implode('|', [
            (string) $row['id'], (string) $row['id_santri'], (string) $row['tgl_izin'],
            (string) $row['tgl_kembali'], (string) $row['alasan'], (string) $row['status'],
        ]);
    }

    return ['ids' => $ids, 'hash' => hash('sha256', implode("\n", $baris))];
};

$sebelum = $sidikJari($db);
echo PHP_EOL . '--- c. Sidik jari sebelum migrasi ---' . PHP_EOL;
echo '[ok] ' . count($sebelum['ids']) . ' baris, sidik jari ' . substr($sebelum['hash'], 0, 16) . PHP_EOL;

// ---------------------------------------------------------------------------
// d. Backup + manifest
// ---------------------------------------------------------------------------
echo PHP_EOL . '--- d. Backup + manifest ---' . PHP_EOL;
$base = APP_ROOT . '/storage/backups/v2-phase5-drill/' . date('Ymd_His');
if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
    throw new RuntimeException('Direktori output tidak dapat dibuat: ' . $base);
}
$counts = (new BackupWriter($db))->write($base . '/database.sql');
$manifest = [
    'generated_at' => date(DATE_ATOM),
    'database' => $sumber,
    'phase' => 'v2-phase5',
    'row_counts' => $counts,
    'legacy_perizinan' => [
        'total' => count($sebelum['ids']),
        'ids' => $sebelum['ids'],
        'fingerprint_sha256' => $sebelum['hash'],
    ],
    'izin_pengajuan' => [
        'total' => $scalar('SELECT COUNT(*) FROM izin_pengajuan'),
        'warisan' => $scalar('SELECT COUNT(*) FROM izin_pengajuan WHERE is_legacy = 1'),
        'maks_id' => $scalar('SELECT IFNULL(MAX(id), 0) FROM izin_pengajuan'),
    ],
    'izin_keputusan_total' => $scalar('SELECT COUNT(*) FROM izin_keputusan'),
];
file_put_contents($base . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo '[ok] Backup + manifest ditulis ke ' . $base . PHP_EOL;
echo '     ' . count($counts) . ' tabel, ' . array_sum($counts) . ' baris total.' . PHP_EOL;

// ---------------------------------------------------------------------------
// e. Restore ke database _test kedua
// ---------------------------------------------------------------------------
echo PHP_EOL . '--- e. Restore ke `' . $target . '` ---' . PHP_EOL;

$config = app_config('database');
$restoreDb = new mysqli(
    (string) $config['host'],
    (string) $config['username'],
    (string) $config['password'],
    '',
    (int) $config['port']
);
if ($restoreDb->connect_errno !== 0) {
    fwrite(STDERR, 'Koneksi restore gagal: ' . $restoreDb->connect_error . PHP_EOL);
    exit(2);
}
$restoreDb->set_charset((string) $config['charset']);
$restoreDb->query('DROP DATABASE IF EXISTS `' . $target . '`');
if ($restoreDb->query('CREATE DATABASE `' . $target . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci') === false) {
    fwrite(STDERR, 'Database tujuan tidak dapat dibuat: ' . $restoreDb->error . PHP_EOL);
    fwrite(STDERR, "Pastikan pengguna basis data memiliki hak CREATE/DROP pada database `_test`.\n");
    exit(2);
}
$restoreDb->select_db($target);

/**
 * Restore memakai KLIEN BARIS PERINTAH, bukan `multi_query()`.
 *
 * Dua alasan:
 *   1. inilah prosedur yang benar-benar dipakai operator pada cPanel
 *      (`mysql nama_db < backup.sql`), sehingga latihan ini menguji jalur yang
 *      sama dengan pemulihan sungguhan — bukan jalur khusus pengujian;
 *   2. `multi_query()` mengirim seluruh berkas sebagai satu string dan dapat
 *      melampaui `max_allowed_packet` pada backup besar.
 *
 * Credential dikirim lewat berkas `--defaults-extra-file` sementara ber-mode
 * 0600, TIDAK pernah lewat argumen baris perintah (yang terlihat pada `ps`).
 */
$klien = null;
foreach (['mariadb', 'mysql'] as $kandidat) {
    $cek = [];
    $kodeCek = 1;
    exec('command -v ' . escapeshellarg($kandidat) . ' 2>/dev/null', $cek, $kodeCek);
    if ($kodeCek === 0 && ($cek[0] ?? '') !== '') {
        $klien = $cek[0];
        break;
    }
}
if ($klien === null) {
    fwrite(STDERR, "Tolak: klien `mariadb`/`mysql` tidak ditemukan. Latihan restore memerlukan klien baris perintah.\n");
    exit(2);
}

$defaults = tempnam(sys_get_temp_dir(), 'p5drill');
if ($defaults === false) {
    fwrite(STDERR, "Tolak: berkas sementara credential tidak dapat dibuat.\n");
    exit(2);
}
chmod($defaults, 0600);
/**
 * Nilai pada berkas opsi MySQL/MariaDB WAJIB dikutip.
 *
 * Tanpa kutip, karakter `#` pada password memulai KOMENTAR sehingga password
 * terpotong diam-diam dan restore ditolak dengan `Access denied` yang
 * membingungkan. Backslash juga bermakna khusus dan harus di-escape.
 */
$opsiKutip = static fn (string $nilai): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $nilai) . '"';

// `protocol=TCP` disetel eksplisit: tanpa itu klien MySQL/MariaDB
// menerjemahkan host `localhost` menjadi koneksi soket Unix, sehingga hak
// akses yang diberikan untuk `pengguna@127.0.0.1` tidak selalu terpakai.
file_put_contents($defaults, implode("\n", [
    '[client]',
    'protocol=TCP',
    'host=' . $opsiKutip((string) $config['host']),
    'port=' . (int) $config['port'],
    'user=' . $opsiKutip((string) $config['username']),
    'password=' . $opsiKutip((string) $config['password']),
    '',
]));

$jalankanSql = static function (string $berkasSql) use ($klien, $defaults, $target): array {
    $perintah = escapeshellarg($klien)
        . ' --defaults-extra-file=' . escapeshellarg($defaults)
        . ' ' . escapeshellarg($target)
        . ' < ' . escapeshellarg($berkasSql)
        . ' 2>&1';
    $keluaran = [];
    $kode = 1;
    exec($perintah, $keluaran, $kode);

    return ['kode' => $kode, 'keluaran' => $keluaran];
};

$hasilRestore = $jalankanSql($base . '/database.sql');
$assert(
    $hasilRestore['kode'] === 0,
    'Restore selesai tanpa galat SQL'
        . ($hasilRestore['kode'] === 0 ? '' : ': ' . implode(' | ', array_slice($hasilRestore['keluaran'], 0, 3)))
);
// Sambungan mysqli dipakai HANYA untuk memeriksa hasil, tidak untuk memuat.
$restoreDb->select_db($target);

// ---------------------------------------------------------------------------
// f. Cocokkan jumlah baris hasil restore dengan manifest
// ---------------------------------------------------------------------------
echo PHP_EOL . '--- f. Pencocokan jumlah baris dengan manifest ---' . PHP_EOL;

$tidakCocok = [];
foreach ($counts as $table => $harapan) {
    $identifier = '`' . str_replace('`', '``', (string) $table) . '`';
    $result = $restoreDb->query('SELECT COUNT(*) AS n FROM ' . $identifier);
    $aktual = $result ? (int) $result->fetch_assoc()['n'] : -1;
    if ($aktual !== (int) $harapan) {
        $tidakCocok[] = $table . ' (manifest ' . $harapan . ', restore ' . $aktual . ')';
    }
}
$assert(
    $tidakCocok === [],
    'Seluruh ' . count($counts) . ' tabel cocok dengan manifest'
        . ($tidakCocok === [] ? '' : '; tidak cocok: ' . implode(', ', $tidakCocok))
);

$restoreSidik = $sidikJari($restoreDb);
$assert(
    $restoreSidik['ids'] === $sebelum['ids'],
    'ID `perizinan` hasil restore identik dengan sumber (' . count($sebelum['ids']) . ' ID)'
);
$assert(
    $restoreSidik['hash'] === $sebelum['hash'],
    'Nilai bisnis `perizinan` hasil restore identik dengan sumber'
);

// ---------------------------------------------------------------------------
// g. Jalankan migrasi 009 pada database pulihan lalu buktikan data lama tetap
// ---------------------------------------------------------------------------
echo PHP_EOL . '--- g. Migrasi 009 pada database pulihan ---' . PHP_EOL;

$hasilMigrasi = $jalankanSql(APP_ROOT . '/database/migrations/009_v2_phase5_laporan_dan_push_receipt.sql');
$assert(
    $hasilMigrasi['kode'] === 0,
    'Migrasi 009 berjalan pada database pulihan'
        . ($hasilMigrasi['kode'] === 0 ? '' : ': ' . implode(' | ', array_slice($hasilMigrasi['keluaran'], 0, 3)))
);

$sesudah = $sidikJari($restoreDb);
$assert($sesudah['ids'] === $sebelum['ids'], 'ID `perizinan` TIDAK berubah akibat migrasi 009');
$assert($sesudah['hash'] === $sebelum['hash'], 'Nilai bisnis `perizinan` TIDAK berubah akibat migrasi 009');
$assert(count($sesudah['ids']) === count($sebelum['ids']), 'Jumlah `perizinan` TIDAK berubah akibat migrasi 009');

$berkurang = [];
foreach ($counts as $table => $harapan) {
    $identifier = '`' . str_replace('`', '``', (string) $table) . '`';
    $result = $restoreDb->query('SELECT COUNT(*) AS n FROM ' . $identifier);
    $aktual = $result ? (int) $result->fetch_assoc()['n'] : -1;
    if ($aktual < (int) $harapan) {
        $berkurang[] = $table;
    }
}
$assert($berkurang === [], 'Tidak ada tabel yang barisnya berkurang akibat migrasi 009');

$kolomReceipt = $restoreDb->query(
    "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = '" . $restoreDb->real_escape_string($target) . "'
        AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'receipt_status'"
);
$assert(
    (int) ($kolomReceipt ? $kolomReceipt->fetch_assoc()['n'] : 0) === 1,
    'Kolom receipt terpasang pada database pulihan'
);

// Rollback 009 pada database pulihan: membuktikan prosedur rollback benar-benar
// berjalan dan tidak menyentuh data bisnis.
echo PHP_EOL . '--- h. Uji rollback 009 pada database pulihan ---' . PHP_EOL;
$hasilRollback = $jalankanSql(APP_ROOT . '/database/rollbacks/009_v2_phase5_laporan_dan_push_receipt.sql');
$assert(
    $hasilRollback['kode'] === 0,
    'Rollback 009 berjalan tanpa galat'
        . ($hasilRollback['kode'] === 0 ? '' : ': ' . implode(' | ', array_slice($hasilRollback['keluaran'], 0, 3)))
);

$setelahRollback = $sidikJari($restoreDb);
$assert($setelahRollback['ids'] === $sebelum['ids'], 'ID `perizinan` TIDAK berubah akibat rollback 009');
$assert($setelahRollback['hash'] === $sebelum['hash'], 'Nilai bisnis `perizinan` TIDAK berubah akibat rollback 009');

$kolomSetelahRollback = $restoreDb->query(
    "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = '" . $restoreDb->real_escape_string($target) . "'
        AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'receipt_status'"
);
$assert(
    (int) ($kolomSetelahRollback ? $kolomSetelahRollback->fetch_assoc()['n'] : -1) === 0,
    'Kolom receipt terlepas kembali setelah rollback'
);

$outboxSetelahRollback = $restoreDb->query('SELECT COUNT(*) AS n FROM notifikasi_outbox');
$assert(
    (int) ($outboxSetelahRollback ? $outboxSetelahRollback->fetch_assoc()['n'] : -1) === (int) ($counts['notifikasi_outbox'] ?? 0),
    'Baris notifikasi_outbox tetap utuh setelah rollback (rollback hanya melepas kolom jejak)'
);

// ---------------------------------------------------------------------------
// Pembersihan dan hasil
// ---------------------------------------------------------------------------
$restoreDb->query('DROP DATABASE IF EXISTS `' . $target . '`');
$restoreDb->close();
// Berkas credential sementara dihapus sesegera mungkin.
@unlink($defaults);

$db->query("DELETE r FROM izin_riwayat_status r JOIN izin_pengajuan p ON p.id = r.pengajuan_id
             WHERE p.alasan LIKE 'DRILL5%'");
$db->query("DELETE FROM izin_pengajuan WHERE alasan LIKE 'DRILL5%'");
$db->query("DELETE FROM perizinan WHERE alasan LIKE 'DRILL5%'");

echo PHP_EOL;
echo 'Artefak latihan disimpan di: ' . $base . PHP_EOL;
if ($gagal !== []) {
    echo PHP_EOL . 'LATIHAN GAGAL (' . count($gagal) . ' pemeriksaan):' . PHP_EOL;
    foreach ($gagal as $pesan) {
        echo ' - ' . $pesan . PHP_EOL;
    }
    exit(3);
}
echo "SELURUH {$lulus} PEMERIKSAAN LATIHAN BACKUP/RESTORE LULUS." . PHP_EOL;
exit(0);
