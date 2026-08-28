<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Pemeriksaan kesiapan CRON worker notifikasi (V2 Fase 5).
 *
 * Menutup temuan terbuka Fase 4 nomor 1: "cron worker push produksi belum
 * dipasang/diverifikasi". Skrip ini TIDAK memasang cron apa pun — memasang
 * cron pada produksi memerlukan izin pengguna. Yang dilakukannya adalah
 * MEMBUKTIKAN, dari data, apakah cron benar-benar berjalan:
 *
 *   1. apakah ada baris outbox yang menunggu lebih lama dari ambang wajar;
 *   2. kapan worker terakhir benar-benar memproses sesuatu;
 *   3. apakah sewa worker (`notifikasi_worker_lock`) pernah diperbarui;
 *   4. apakah receipt akhir push tertinggal menunggu;
 *   5. apakah konfigurasi push siap dipakai.
 *
 * Skrip ini HANYA MEMBACA dan aman dijalankan pada produksi.
 *
 * Kode keluar:
 *   0 = sehat,
 *   1 = ada indikasi cron TIDAK berjalan (butuh tindakan operator),
 *   2 = kesalahan lingkungan.
 *
 * Pemakaian:
 *   php bin/v2_phase5_cron_check.php [--ambang-menit=15]
 */

$ambangMenit = 15;
foreach (array_slice($argv, 1) as $argumen) {
    if (str_starts_with($argumen, '--ambang-menit=')) {
        $ambangMenit = max(2, (int) substr($argumen, 15));
    }
}

$db = app_db();
$scalar = static function (string $sql) use ($db): int {
    $result = $db->query($sql);

    return $result ? (int) array_values($result->fetch_assoc())[0] : -1;
};
$nilai = static function (string $sql) use ($db): ?string {
    $result = $db->query($sql);
    if ($result === false) {
        return null;
    }
    $row = $result->fetch_assoc();

    return $row === null ? null : (string) array_values($row)[0];
};

$masalah = [];
$peringatan = [];
$lulus = 0;
$catat = static function (bool $sehat, string $pesan, bool $blocking = true) use (&$masalah, &$peringatan, &$lulus): void {
    echo ($sehat ? '[sehat] ' : ($blocking ? '[MASALAH] ' : '[peringatan] ')) . $pesan . PHP_EOL;
    if ($sehat) {
        $lulus++;
    } elseif ($blocking) {
        $masalah[] = $pesan;
    } else {
        $peringatan[] = $pesan;
    }
};

echo '=== Pemeriksaan kesiapan cron worker notifikasi ===' . PHP_EOL;
echo 'Database   : ' . app_config('database.database') . PHP_EOL;
echo 'Waktu      : ' . date('Y-m-d H:i:s T') . PHP_EOL;
echo 'Ambang     : ' . $ambangMenit . ' menit' . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------------
// 1. Pengaturan kanal
// ---------------------------------------------------------------------------
$pengaturan = notification_settings_repository()->current();
$pushAktif = $pengaturan['push_enabled'] === true;

echo '--- 1. Pengaturan kanal ---' . PHP_EOL;
$catat($pengaturan['inapp_enabled'] === true, 'Notifikasi in-app aktif (sumber status utama)');
echo '        Push     : ' . ($pushAktif ? 'AKTIF' : 'nonaktif') . PHP_EOL;
echo '        WhatsApp : ' . ($pengaturan['whatsapp_enabled'] === true ? 'AKTIF' : 'nonaktif (DITANGGUHKAN)') . PHP_EOL;
$catat(
    $pengaturan['whatsapp_enabled'] !== true,
    'WhatsApp tetap OFF sesuai keputusan produk 26 Agustus 2026',
    true
);

if (!$pushAktif) {
    echo PHP_EOL . '--- Push nonaktif: pemeriksaan antrean push dilewati ---' . PHP_EOL;
    echo 'Worker tidak akan memproses apa pun dan itu memang perilaku yang benar.' . PHP_EOL;
}

// ---------------------------------------------------------------------------
// 2. Kesiapan konfigurasi push
// ---------------------------------------------------------------------------
echo PHP_EOL . '--- 2. Kesiapan konfigurasi push ---' . PHP_EOL;
$protector = push_token_protector();
$catat(
    $protector->ready(),
    $protector->ready()
        ? 'Kunci perlindungan token push (PUSH_TOKEN_KEY) terpasang'
        : 'PUSH_TOKEN_KEY belum siap: ' . (string) $protector->reason(),
    $pushAktif
);
$perangkatAktif = $scalar('SELECT COUNT(*) FROM perangkat_push WHERE dicabut_pada IS NULL AND push_aktif = 1');
echo '        Perangkat push aktif: ' . $perangkatAktif . PHP_EOL;
if ($pushAktif && $perangkatAktif === 0) {
    $peringatan[] = 'Push aktif tetapi tidak ada perangkat terdaftar; tidak ada yang akan menerima push.';
    echo '[peringatan] Push aktif tetapi belum ada perangkat terdaftar.' . PHP_EOL;
}

// ---------------------------------------------------------------------------
// 3. Antrean yang tertahan — bukti paling langsung cron tidak berjalan
// ---------------------------------------------------------------------------
echo PHP_EOL . '--- 3. Antrean tertahan ---' . PHP_EOL;

$tertahan = $scalar(
    "SELECT COUNT(*) FROM notifikasi_outbox
      WHERE kanal = 'Push' AND status = 'Queued' AND gagal_permanen = 0
        AND created_at <= DATE_SUB(NOW(), INTERVAL {$ambangMenit} MINUTE)
        AND (tersedia_pada IS NULL OR tersedia_pada <= NOW())"
);
echo '        Baris push menunggu lebih dari ' . $ambangMenit . ' menit: ' . $tertahan . PHP_EOL;
$catat(
    !$pushAktif || $tertahan === 0,
    $tertahan === 0
        ? 'Tidak ada baris push yang tertahan melewati ambang'
        : "Terdapat {$tertahan} baris push tertahan lebih dari {$ambangMenit} menit — cron kemungkinan TIDAK berjalan",
    $pushAktif
);

$tertua = $nilai(
    "SELECT MIN(created_at) FROM notifikasi_outbox
      WHERE kanal = 'Push' AND status = 'Queued' AND gagal_permanen = 0"
);
if ($tertua !== null) {
    echo '        Baris push tertua yang masih antre: ' . $tertua . PHP_EOL;
}

// ---------------------------------------------------------------------------
// 4. Jejak aktivitas worker
// ---------------------------------------------------------------------------
echo PHP_EOL . '--- 4. Jejak aktivitas worker ---' . PHP_EOL;

$percobaanTerakhir = $nilai('SELECT MAX(percobaan_terakhir_pada) FROM notifikasi_outbox');
$kirimTerakhir = $nilai('SELECT MAX(dikirim_pada) FROM notifikasi_outbox');
echo '        Percobaan pengiriman terakhir : ' . ($percobaanTerakhir ?? 'belum pernah') . PHP_EOL;
echo '        Pengiriman berhasil terakhir  : ' . ($kirimTerakhir ?? 'belum pernah') . PHP_EOL;

$adaTabelLock = ($db->query("SHOW TABLES LIKE 'notifikasi_worker_lock'")?->num_rows ?? 0) === 1;
if ($adaTabelLock) {
    $lockTerakhir = $nilai('SELECT MAX(heartbeat_pada) FROM notifikasi_worker_lock');
    if ($lockTerakhir === null) {
        $lockTerakhir = $nilai('SELECT MAX(updated_at) FROM notifikasi_worker_lock');
    }
    echo '        Sewa worker terakhir          : ' . ($lockTerakhir ?? 'belum pernah') . PHP_EOL;
    $catat(
        !$pushAktif || $lockTerakhir !== null,
        $lockTerakhir !== null
            ? 'Worker pernah mengambil sewa (bukti worker benar-benar dijalankan)'
            : 'Tidak ada jejak sewa worker sama sekali — cron belum pernah berjalan',
        $pushAktif
    );
} else {
    $peringatan[] = 'Tabel notifikasi_worker_lock tidak ada; jalankan migrasi 008.';
    echo '[peringatan] Tabel notifikasi_worker_lock tidak ditemukan.' . PHP_EOL;
}

// ---------------------------------------------------------------------------
// 5. Receipt akhir push (temuan terbuka Fase 4)
// ---------------------------------------------------------------------------
echo PHP_EOL . '--- 5. Receipt akhir push ---' . PHP_EOL;

$adaKolomReceipt = $scalar(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifikasi_outbox' AND COLUMN_NAME = 'receipt_status'"
) === 1;

if (!$adaKolomReceipt) {
    $masalah[] = 'Kolom receipt belum ada. Jalankan migrasi 009.';
    echo '[MASALAH] Kolom receipt belum ada; jalankan migrasi 009.' . PHP_EOL;
} else {
    $sebaran = notification_outbox_repository()->receiptSummary();
    foreach ($sebaran as $status => $jumlah) {
        printf("        %-17s: %d%s", $status, $jumlah, PHP_EOL);
    }
    $receiptTertahan = $scalar(
        "SELECT COUNT(*) FROM notifikasi_outbox
          WHERE kanal = 'Push' AND receipt_status = 'Menunggu'
            AND dikirim_pada <= DATE_SUB(NOW(), INTERVAL 6 HOUR)"
    );
    $catat(
        !$pushAktif || $receiptTertahan === 0,
        $receiptTertahan === 0
            ? 'Tidak ada receipt yang tertahan lebih dari 6 jam'
            : "Terdapat {$receiptTertahan} receipt tertahan lebih dari 6 jam — cron `--receipts` kemungkinan belum dipasang",
        false
    );
    if ($sebaran['Gagal'] > 0) {
        $peringatan[] = 'Terdapat ' . $sebaran['Gagal'] . ' push yang gagal diantar menurut receipt akhir. Periksa panel admin.';
    }
}

// ---------------------------------------------------------------------------
// 6. Baris cron yang disarankan
// ---------------------------------------------------------------------------
echo PHP_EOL . '--- 6. Baris cron cPanel yang disarankan ---' . PHP_EOL;
echo 'Salin apa adanya ke cPanel > Cron Jobs (ganti AKUN dan jalur PHP bila berbeda).' . PHP_EOL;
echo 'JANGAN dipasang pada produksi tanpa persetujuan pengguna.' . PHP_EOL . PHP_EOL;
$jalurPhp = PHP_BINARY;
$jalurBin = APP_ROOT . '/bin';
echo "  # setiap menit — memproses antrean push\n";
echo "  * * * * * {$jalurPhp} {$jalurBin}/notifikasi_worker.php --kanal=push >> ~/logs/notifikasi_worker.log 2>&1\n\n";
echo "  # setiap 15 menit — mengambil receipt AKHIR dari Expo/FCM/APNs\n";
echo "  */15 * * * * {$jalurPhp} {$jalurBin}/notifikasi_worker.php --receipts >> ~/logs/notifikasi_receipt.log 2>&1\n\n";
echo "  # setiap jam — pemeriksaan kesehatan cron (kirim keluarannya ke admin)\n";
echo "  0 * * * * {$jalurPhp} {$jalurBin}/v2_phase5_cron_check.php\n";

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== Hasil ===' . PHP_EOL;
foreach ($peringatan as $pesan) {
    echo '[peringatan] ' . $pesan . PHP_EOL;
}
if ($masalah !== []) {
    foreach ($masalah as $pesan) {
        echo '[MASALAH] ' . $pesan . PHP_EOL;
    }
    echo PHP_EOL . 'CRON BELUM SEHAT: ' . count($masalah) . ' masalah perlu ditangani operator.' . PHP_EOL;
    exit(1);
}
echo "Seluruh {$lulus} pemeriksaan kesehatan cron lulus." . PHP_EOL;
if ($peringatan !== []) {
    echo 'Terdapat ' . count($peringatan) . ' peringatan yang tidak memblokir.' . PHP_EOL;
}
exit(0);
