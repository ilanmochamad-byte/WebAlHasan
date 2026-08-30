<?php

declare(strict_types=1);

use App\Izin\IzinException;

require_once __DIR__ . '/_ui.php';

/**
 * Controller mutasi perizinan (Fase 2).
 *
 * Hanya menerima POST. CSRF sudah diperiksa `_guard.php` sebelum file ini berjalan.
 * Seluruh otorisasi, cakupan, validasi, transaksi, dan idempotensi dikerjakan
 * IzinWorkflowService di server — halaman ini tidak pernah memutuskan hak akses.
 *
 * Sukses  -> 303 See Other ke halaman detail (POST/Redirect/GET).
 * Gagal   -> merender halaman galat dengan status HTTP asli (403/409/422) agar
 *            hasilnya dapat diperiksa manusia maupun pengujian.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Metode tidak diizinkan.');
}

$aksi = (string) ($_POST['aksi'] ?? '');
$mode = isset($_POST['mode']) && $_POST['mode'] !== '' ? (string) $_POST['mode'] : null;
$pengajuanId = (int) ($_POST['pengajuan_id'] ?? 0);
$key = isset($_POST['idempotency_key']) ? (string) $_POST['idempotency_key'] : null;
$version = isset($_POST['version']) && $_POST['version'] !== '' ? (int) $_POST['version'] : null;
$meta = portal_request_meta();
$workflow = izin_workflow_service();

$kembali = static function (int $id) use ($mode): never {
    $target = app_url('/portal/izin_detail.php') . '?id=' . $id
        . ($mode === null ? '' : '&mode=' . rawurlencode($mode));
    header('Location: ' . $target, true, 303);
    exit;
};

try {
    switch ($aksi) {
        case 'buat':
            $hasil = $workflow->create(
                $currentUser,
                [
                    'santri_id' => (int) ($_POST['santri_id'] ?? 0),
                    'tgl_izin' => (string) ($_POST['tgl_izin'] ?? ''),
                    'tgl_kembali' => (string) ($_POST['tgl_kembali'] ?? ''),
                    'alasan' => (string) ($_POST['alasan'] ?? ''),
                    'catatan_pengurus' => (string) ($_POST['catatan_pengurus'] ?? ''),
                ],
                $key,
                $meta,
                $mode
            );
            portal_flash_set(
                'sukses',
                ($hasil['idempotent_replay'] ?? false)
                    ? 'Pengajuan #' . (int) $hasil['id'] . ' sudah tersimpan sebelumnya. Permintaan ulang tidak membuat pengajuan baru.'
                    : 'Pengajuan #' . (int) $hasil['id'] . ' tersimpan. ' . (string) $hasil['routing_catatan']
            );
            $kembali((int) $hasil['id']);
            // no break — $kembali() selalu exit.

        case 'putuskan':
            $hasil = $workflow->decide(
                $currentUser,
                $pengajuanId,
                (string) ($_POST['hasil'] ?? ''),
                (string) ($_POST['alasan'] ?? ''),
                isset($_POST['alasan_penggantian']) ? (string) $_POST['alasan_penggantian'] : null,
                $version,
                $key,
                $meta,
                $mode
            );
            portal_flash_set(
                'sukses',
                ($hasil['idempotent_replay'] ?? false)
                    ? 'Keputusan sudah tercatat sebelumnya; permintaan ulang tidak membuat keputusan kedua.'
                    : 'Keputusan ' . (string) $hasil['status'] . ' tersimpan sebagai ' . (string) $hasil['kapasitas'] . '.'
            );
            $kembali($pengajuanId);

        case 'tetapkan':
            $workflow->assignMurobi(
                $currentUser,
                $pengajuanId,
                (int) ($_POST['murobi_guru_id'] ?? 0),
                (string) ($_POST['alasan'] ?? ''),
                $version,
                $key,
                $meta
            );
            portal_flash_set('sukses', 'Murobi tujuan ditetapkan. Pengajuan masuk ke antrean murobi tersebut.');
            $kembali($pengajuanId);

        case 'batalkan':
            $workflow->cancel(
                $currentUser,
                $pengajuanId,
                (string) ($_POST['alasan'] ?? ''),
                $version,
                $key,
                $meta,
                $mode
            );
            portal_flash_set('sukses', 'Pengajuan dibatalkan. Riwayat sebelumnya tetap tersimpan.');
            $kembali($pengajuanId);

        case 'koreksi':
            $workflow->correctDecision(
                $currentUser,
                $pengajuanId,
                (string) ($_POST['hasil'] ?? ''),
                (string) ($_POST['alasan'] ?? ''),
                (string) ($_POST['alasan_koreksi'] ?? ''),
                $version,
                $key,
                $meta
            );
            portal_flash_set('sukses', 'Koreksi keputusan tersimpan. Keputusan dan riwayat sebelumnya tidak dihapus.');
            $kembali($pengajuanId);

        default:
            throw IzinException::invalid('Aksi perizinan tidak dikenal.');
    }
} catch (IzinException $exception) {
    if (in_array($exception->status(), [409, 422], true) && in_array($aksi, ['buat','putuskan','tetapkan','batalkan','koreksi'], true)) {
        ah_validation_keep($_POST, ['santri_id','tgl_izin','tgl_kembali','alasan','catatan_pengurus','hasil','alasan_penggantian','murobi_guru_id','alasan_koreksi'], $exception, '_portal_' . $pengajuanId . '_' . $aksi);
    }
    http_response_code($exception->status());
    portal_header('Aksi ditolak', $userCapabilities, $mode ?? ($userCapabilities[0] ?? ''), $currentUser);
    echo '<div class="alert alert-danger"><strong>' . (int) $exception->status() . '</strong> — '
        . portal_e($exception->getMessage()) . '</div>';
    echo '<div class="d-flex gap-2">';
    if ($aksi === 'buat' && in_array($exception->status(), [409,422], true)) { echo '<a class="btn btn-primary" href="' . portal_e(app_url('/portal/izin_buat.php') . '?mode=' . rawurlencode($mode ?? 'admin') . '&santri_id=' . (int)($_POST['santri_id']??0) . '&page=' . max(1,(int)($_POST['return_page']??1)) . '&q=' . rawurlencode(is_string($_POST['return_q']??null) ? mb_substr($_POST['return_q'],0,200) : '')) . '">Perbaiki isian pengajuan</a>'; }
    if ($pengajuanId > 0) {
        echo '<a class="btn btn-outline-primary" href="' . portal_e(app_url('/portal/izin_detail.php') . '?id=' . $pengajuanId) . '">Buka detail pengajuan</a>';
    }
    echo '<a class="btn btn-outline-secondary" href="' . portal_e(app_url('/portal/izin.php')) . '">Kembali ke daftar</a>';
    echo '</div>';
    portal_footer();
    exit;
}
