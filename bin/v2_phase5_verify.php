<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Verifikasi V2 Fase 5 — dijalankan SESUDAH migrasi 009.
 *
 * Membandingkan keadaan sekarang dengan manifest preflight dan membuktikan
 * kriteria penerimaan PRD Fase 5 yang menyangkut data:
 *
 *   - "Jumlah dan ID perizinan lama sama sebelum/sesudah migrasi";
 *   - nilai bisnis perizinan lama tidak berubah (sidik jari SHA-256);
 *   - migrasi 009 benar-benar aditif: tidak ada tabel atau baris yang hilang;
 *   - kolom receipt terpasang dan siap dipakai worker;
 *   - WhatsApp tetap OFF.
 *
 * Skrip ini HANYA MEMBACA. Ia tidak memperbaiki apa pun; bila ada selisih,
 * operator wajib berhenti dan memakai prosedur rollback.
 *
 * Kode keluar: 0 = lulus, 2 = kesalahan lingkungan, 3 = verifikasi gagal.
 *
 * Pemakaian:
 *   php bin/v2_phase5_verify.php /path/ke/manifest.json
 */

$manifestPath = $argv[1] ?? '';
if ($manifestPath === '' || !is_file($manifestPath)) {
    fwrite(STDERR, "Pemakaian: php bin/v2_phase5_verify.php /path/ke/manifest.json\n");
    fwrite(STDERR, "Manifest dihasilkan bin/v2_phase5_preflight.php.\n");
    exit(2);
}

$manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
if (($manifest['phase'] ?? '') !== 'v2-phase5') {
    fwrite(STDERR, "Manifest bukan milik Fase 5 (phase=" . (string) ($manifest['phase'] ?? '-') . ").\n");
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

echo '=== Verifikasi V2 Fase 5 ===' . PHP_EOL;
echo 'Database : ' . app_config('database.database') . PHP_EOL;
echo 'Manifest : ' . $manifestPath . ' (dibuat ' . (string) ($manifest['generated_at'] ?? '-') . ')' . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------------
// 1. Perizinan lama: jumlah, ID, dan nilai bisnis tidak berubah.
// ---------------------------------------------------------------------------
echo '--- 1. Data perizinan lama (PRD 5.5) ---' . PHP_EOL;

$idSekarang = [];
$barisSekarang = [];
$result = $db->query('SELECT id, id_santri, tgl_izin, tgl_kembali, alasan, status FROM perizinan ORDER BY id');
while ($result && $row = $result->fetch_assoc()) {
    $idSekarang[] = (int) $row['id'];
    $barisSekarang[] = implode('|', [
        (string) $row['id'],
        (string) $row['id_santri'],
        (string) $row['tgl_izin'],
        (string) $row['tgl_kembali'],
        (string) $row['alasan'],
        (string) $row['status'],
    ]);
}
$sidikSekarang = hash('sha256', implode("\n", $barisSekarang));

$idHarapan = array_map('intval', $manifest['legacy_perizinan']['ids'] ?? []);
$sidikHarapan = (string) ($manifest['legacy_perizinan']['fingerprint_sha256'] ?? '');

$assert(
    count($idSekarang) === count($idHarapan),
    'Jumlah baris `perizinan` sama: harapan ' . count($idHarapan) . ', aktual ' . count($idSekarang)
);
$assert(
    $idSekarang === $idHarapan,
    'Seluruh ID `perizinan` identik dan berurutan sama seperti sebelum migrasi'
);
$assert(
    $sidikSekarang === $sidikHarapan,
    'Sidik jari nilai bisnis `perizinan` tidak berubah (' . substr($sidikHarapan, 0, 16) . ')'
);

$hilangDiV2 = $scalar(
    'SELECT COUNT(*) FROM perizinan p
      WHERE NOT EXISTS (SELECT 1 FROM izin_pengajuan t WHERE t.legacy_perizinan_id = p.id)'
);
$assert($hilangDiV2 === 0, 'Setiap baris `perizinan` masih terbaca pada `izin_pengajuan`');

$idBergeser = $scalar(
    'SELECT COUNT(*) FROM izin_pengajuan t JOIN perizinan p ON p.id = t.legacy_perizinan_id WHERE t.id <> p.id'
);
$assert($idBergeser === 0, 'ID pengajuan warisan tetap sama dengan ID `perizinan` asalnya');

$nilaiBerbeda = $scalar(
    'SELECT COUNT(*) FROM izin_pengajuan t JOIN perizinan p ON p.id = t.legacy_perizinan_id
      WHERE t.santri_id <> p.id_santri OR t.tgl_izin <> p.tgl_izin
         OR t.tgl_kembali <> p.tgl_kembali OR t.alasan <> p.alasan'
);
$assert($nilaiBerbeda === 0, 'Nilai bisnis warisan pada `izin_pengajuan` identik dengan `perizinan`');

$warisanBerpelaku = $scalar(
    'SELECT COUNT(*) FROM izin_pengajuan
      WHERE is_legacy = 1 AND (pengurus_id IS NOT NULL OR diajukan_oleh_user_id IS NOT NULL OR murobi_guru_id IS NOT NULL)'
);
$assert($warisanBerpelaku === 0, 'Data warisan tetap tanpa pelaku (tidak ada akun fiktif)');

// ---------------------------------------------------------------------------
// 2. Migrasi bersifat aditif: tidak ada tabel/baris yang hilang.
// ---------------------------------------------------------------------------
echo PHP_EOL . '--- 2. Sifat aditif migrasi 009 ---' . PHP_EOL;

$berkurang = [];
$tabelHilang = [];
foreach (($manifest['row_counts'] ?? []) as $table => $harapan) {
    $identifier = '`' . str_replace('`', '``', (string) $table) . '`';
    if (($db->query('SHOW TABLES LIKE ' . "'" . $db->real_escape_string((string) $table) . "'")?->num_rows ?? 0) !== 1) {
        $tabelHilang[] = (string) $table;
        continue;
    }
    $aktual = $scalar('SELECT COUNT(*) FROM ' . $identifier);
    if ($aktual < (int) $harapan) {
        $berkurang[] = $table . ' (harapan >= ' . $harapan . ', aktual ' . $aktual . ')';
    }
}
$assert($tabelHilang === [], 'Tidak ada tabel yang hilang setelah migrasi: ' . (implode(', ', $tabelHilang) ?: 'tidak ada'));
$assert($berkurang === [], 'Tidak ada tabel yang jumlah barisnya BERKURANG: ' . (implode(', ', $berkurang) ?: 'tidak ada'));

$assert(
    $scalar('SELECT COUNT(*) FROM izin_pengajuan') >= (int) ($manifest['izin_pengajuan']['total'] ?? 0),
    'Jumlah `izin_pengajuan` tidak berkurang'
);
$assert(
    $scalar('SELECT COUNT(*) FROM izin_keputusan') >= (int) ($manifest['izin_keputusan_total'] ?? 0),
    'Jumlah `izin_keputusan` tidak berkurang'
);

// ---------------------------------------------------------------------------
// 3. Kolom receipt terpasang (temuan terbuka Fase 4).
// ---------------------------------------------------------------------------
echo PHP_EOL . '--- 3. Receipt akhir push (temuan terbuka Fase 4) ---' . PHP_EOL;

foreach (['tiket_id', 'receipt_status', 'receipt_kode', 'receipt_pesan', 'receipt_diperiksa_pada', 'receipt_percobaan'] as $kolom) {
    $ada = $scalar(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox'
            AND COLUMN_NAME = '" . $db->real_escape_string($kolom) . "'"
    ) === 1;
    $assert($ada, 'Kolom notifikasi_outbox.' . $kolom . ' terpasang');
}
$assert(
    $scalar(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox'
            AND INDEX_NAME = 'notifikasi_receipt_index'"
    ) > 0,
    'Indeks notifikasi_receipt_index terpasang'
);
$assert(
    $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE receipt_status IS NULL") === 0,
    'Tidak ada baris outbox dengan receipt_status NULL (default terpasang untuk baris lama)'
);

// ---------------------------------------------------------------------------
// 4. Pengaman kanal notifikasi.
// ---------------------------------------------------------------------------
echo PHP_EOL . '--- 4. Pengaman kanal ---' . PHP_EOL;

$assert(
    $scalar('SELECT COUNT(*) FROM pengaturan_notifikasi WHERE inapp_enabled <> 1') === 0,
    'Notifikasi in-app tetap tidak dapat dimatikan'
);
$assert(
    $scalar('SELECT COUNT(*) FROM pengaturan_notifikasi WHERE whatsapp_enabled = 1') === 0,
    'WhatsApp tetap OFF (keputusan produk 26 Agustus 2026: DITANGGUHKAN)'
);
$assert(
    $scalar("SELECT COUNT(*) FROM pengaturan_notifikasi WHERE whatsapp_enabled = 1 AND whatsapp_check_status <> 'Lulus'") === 0,
    'Tidak ada WhatsApp menyala tanpa pemeriksaan konfigurasi lulus'
);

// ---------------------------------------------------------------------------
// Hasil
// ---------------------------------------------------------------------------
echo PHP_EOL;
if ($gagal !== []) {
    echo 'VERIFIKASI GAGAL (' . count($gagal) . ' dari ' . ($lulus + count($gagal)) . " pemeriksaan):\n";
    foreach ($gagal as $pesan) {
        echo ' - ' . $pesan . PHP_EOL;
    }
    echo PHP_EOL . 'HENTIKAN RILIS. Gunakan prosedur rollback pada docs/phase-v2-5/migration-and-rollback.md.' . PHP_EOL;
    exit(3);
}

echo "SELURUH {$lulus} PEMERIKSAAN VERIFIKASI FASE 5 LULUS." . PHP_EOL;
exit(0);
