<?php

declare(strict_types=1);

/**
 * Worker outbox notifikasi V2 Fase 4.
 *
 * Dijalankan cron cPanel dan aman dijalankan manual untuk pengujian.
 *
 * Pemakaian:
 *   php bin/notifikasi_worker.php                      # push + whatsapp
 *   php bin/notifikasi_worker.php --kanal=push
 *   php bin/notifikasi_worker.php --kanal=whatsapp
 *   php bin/notifikasi_worker.php --uji-coba           # tidak mengirim apa pun
 *   php bin/notifikasi_worker.php --batas=50
 *   php bin/notifikasi_worker.php --status             # ringkasan antrean saja
 *
 * Cron cPanel (contoh, setiap 5 menit — lihat docs/phase-v2-4/cpanel-deployment.md
 * untuk baris cron yang dapat disalin apa adanya):
 *   setiap 5 menit  ->  /usr/local/bin/php /home/AKUN/public_html/bin/notifikasi_worker.php
 *
 * Sifat yang dijamin:
 *   - AMAN DIULANG. Sewa proses dan klaim per baris membuat dua cron yang
 *     tumpang tindih tidak pernah mengirim baris yang sama dua kali.
 *   - TIDAK MENCETAK RAHASIA. Tidak ada token, nomor tujuan, atau credential
 *     yang keluar; seluruh galat sudah melewati `SafeError`.
 *   - TIDAK MENYENTUH PERIZINAN. Skrip ini hanya menulis tabel notifikasi;
 *     kegagalan di sini tidak dapat membatalkan pengajuan atau keputusan.
 *   - BERHENTI DIAM-DIAM saat kanal mati atau konfigurasi belum siap, tanpa
 *     satu pun permintaan ke penyedia.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Notification\NotificationChannel;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opsi = [
    'kanal' => 'semua',
    'batas' => (int) app_config('notifikasi.worker_batch'),
    'uji_coba' => false,
    'status' => false,
    // V2 Fase 5: rekonsiliasi receipt akhir push.
    'receipts' => false,
    'receipt_umur' => null,
];

foreach (array_slice($argv, 1) as $argumen) {
    if ($argumen === '--uji-coba' || $argumen === '--dry-run') {
        $opsi['uji_coba'] = true;
        continue;
    }
    if ($argumen === '--status') {
        $opsi['status'] = true;
        continue;
    }
    if ($argumen === '--receipts' || $argumen === '--receipt') {
        $opsi['receipts'] = true;
        continue;
    }
    if (str_starts_with($argumen, '--receipt-umur=')) {
        $opsi['receipt_umur'] = max(0, (int) substr($argumen, 15));
        continue;
    }
    if (str_starts_with($argumen, '--kanal=')) {
        $opsi['kanal'] = strtolower(substr($argumen, 8));
        continue;
    }
    if (str_starts_with($argumen, '--batas=') || str_starts_with($argumen, '--limit=')) {
        $opsi['batas'] = (int) substr($argumen, (int) strpos($argumen, '=') + 1);
        continue;
    }
    fwrite(STDERR, "Argumen tidak dikenal: {$argumen}\n");
    fwrite(STDERR, "Pemakaian: php bin/notifikasi_worker.php [--kanal=push|whatsapp|semua] [--batas=N] [--uji-coba] [--status] [--receipts] [--receipt-umur=DETIK]\n");
    exit(2);
}

$opsi['batas'] = max(1, min(100, $opsi['batas'] === 0 ? 25 : $opsi['batas']));

$petaKanal = [
    'push' => NotificationChannel::PUSH,
    'whatsapp' => NotificationChannel::WHATSAPP,
];
$kanalDijalankan = $opsi['kanal'] === 'semua'
    ? array_values($petaKanal)
    : (isset($petaKanal[$opsi['kanal']]) ? [$petaKanal[$opsi['kanal']]] : null);

if ($kanalDijalankan === null) {
    fwrite(STDERR, "Kanal tidak dikenal: {$opsi['kanal']}. Pilih push, whatsapp, atau semua.\n");
    exit(2);
}

$waktu = date('Y-m-d H:i:s');

// --- Mode status: hanya melaporkan, tidak mengubah apa pun. -----------------
if ($opsi['status']) {
    $pengaturan = notification_settings_repository()->current();
    $ringkasan = notification_repository()->summaryByChannel();
    echo "[{$waktu}] Ringkasan antrean notifikasi\n";
    echo '  In-app  : selalu aktif | total ' . ($ringkasan[NotificationChannel::IN_APP]['total'] ?? 0)
        . ', belum dibaca ' . ($ringkasan[NotificationChannel::IN_APP]['belum_dibaca'] ?? 0) . "\n";
    foreach ($kanalDijalankan as $kanal) {
        $aktif = $kanal === NotificationChannel::PUSH
            ? $pengaturan['push_enabled']
            : $pengaturan['whatsapp_enabled'];
        printf(
            "  %-8s: %s | menunggu %d, terkirim %d, gagal %d (permanen %d)\n",
            $kanal,
            $aktif ? 'aktif' : 'nonaktif',
            notification_outbox_repository()->pendingCount($kanal),
            $ringkasan[$kanal]['Sent'] ?? 0,
            $ringkasan[$kanal]['Failed'] ?? 0,
            $ringkasan[$kanal]['gagal_permanen'] ?? 0
        );
    }
    // V2 Fase 5: sebaran receipt AKHIR push. `Sent` hanya berarti tiket awal
    // diterima Expo; kolom di bawah menunjukkan berapa yang benar-benar
    // dikonfirmasi FCM/APNs.
    $receipt = notification_outbox_repository()->receiptSummary();
    printf(
        "  receipt : menunggu %d, terkirim %d, gagal %d, tidak tersedia %d, belum diperlukan %d\n",
        $receipt['Menunggu'],
        $receipt['Terkirim'],
        $receipt['Gagal'],
        $receipt['Tidak Tersedia'],
        $receipt['Belum Diperlukan']
    );
    exit(0);
}

// --- Mode receipt: hanya menanyakan hasil akhir, tidak mengirim apa pun. -----
if ($opsi['receipts']) {
    $hasil = notification_dispatcher()->reconcileReceipts($opsi['batas'], $opsi['receipt_umur']);
    if (!$hasil['dijalankan']) {
        echo "[{$waktu}] receipt: dilewati — " . (string) $hasil['alasan'] . "\n";
        exit(0);
    }
    printf(
        "[%s] receipt: diperiksa %d, terkirim %d, gagal %d, belum tersedia %d, token dicabut %d\n",
        $waktu,
        $hasil['diperiksa'],
        $hasil['terkirim'],
        $hasil['gagal'],
        $hasil['belum_tersedia'],
        $hasil['token_dicabut']
    );
    if ($hasil['alasan'] !== null) {
        echo '    - ' . (string) $hasil['alasan'] . "\n";
    }
    foreach ($hasil['catatan'] as $catatan) {
        echo '    - ' . $catatan . "\n";
    }
    exit(0);
}

$adaKegagalan = false;
foreach ($kanalDijalankan as $kanal) {
    $hasil = notification_dispatcher()->run($kanal, $opsi['batas'], $opsi['uji_coba']);

    if (!$hasil['dijalankan']) {
        // Bukan galat: kanal mati, konfigurasi belum siap, atau worker lain
        // sedang berjalan. Cron tetap keluar dengan status 0.
        echo "[{$waktu}] {$kanal}: dilewati — " . (string) $hasil['alasan'] . "\n";
        continue;
    }

    printf(
        "[%s] %s: diproses %d, terkirim %d, gagal %d, dilepas %d%s\n",
        $waktu,
        $kanal,
        $hasil['diproses'],
        $hasil['terkirim'],
        $hasil['gagal'],
        $hasil['dilepas'],
        $opsi['uji_coba'] ? ' (mode uji coba, tidak ada pengiriman)' : ''
    );
    foreach ($hasil['catatan'] as $catatan) {
        echo '    - ' . $catatan . "\n";
    }
    if ($hasil['gagal'] > 0) {
        $adaKegagalan = true;
    }
}

// Kegagalan pengiriman BUKAN kegagalan worker: baris sudah dicatat dan akan
// dicoba ulang sesuai backoff. Keluar 0 agar cron tidak membanjiri email
// operator; admin memantau lewat halaman kegagalan.
if ($adaKegagalan) {
    echo "[{$waktu}] Terdapat pengiriman gagal. Lihat halaman admin notifikasi untuk detail aman.\n";
}

exit(0);
