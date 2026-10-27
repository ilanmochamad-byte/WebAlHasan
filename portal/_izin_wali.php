<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Izin\IzinException;
use App\Izin\IzinWaliRepository;
use App\Izin\IzinWaliService;

require_once __DIR__ . '/_ui.php';

/**
 * Bagian bersama halaman pengajuan izin wali (PRD V7 Fase 1).
 *
 * Peran V7 halaman ditentukan dari peran aktif sesi (orang tua → wali,
 * pengurus → pembimbing, admin → admin). Itu hanya pilihan tampilan: setiap
 * data dan tindakan diperiksa ulang `IzinWaliService` di server, dan peran
 * yang tidak dimiliki akun tidak pernah memberi hak.
 */

header('Cache-Control: private, no-store');

/** Peran V7 yang dipetakan dari cakupan perizinan aktif (null = tidak ada). */
function izin_wali_peran(?string $mode): ?string
{
    return match ($mode) {
        Capabilities::ORANG_TUA => IzinWaliService::PERAN_WALI,
        Capabilities::PENGURUS => IzinWaliService::PERAN_PEMBIMBING,
        Capabilities::ADMIN => IzinWaliService::PERAN_ADMIN,
        default => null,
    };
}

function izin_wali_status(string $status): string
{
    $tone = match ($status) {
        IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING => 'info',
        IzinWaliRepository::STATUS_PERLU_PENETAPAN_PEMBIMBING, IzinWaliRepository::STATUS_PERLU_PERBAIKAN, 'Perlu Penetapan Admin' => 'warn',
        IzinWaliRepository::STATUS_DITOLAK_PEMBIMBING, 'Ditolak' => 'danger',
        'Disetujui' => 'ok',
        'Diajukan' => 'info',
        default => 'muted',
    };

    return ah_badge(IzinWaliService::statusLabel($status), $tone);
}

function izin_wali_detail_url(int $id): string
{
    return app_url('/portal/izin_wali_detail.php') . '?id=' . $id;
}

/**
 * Halaman galat dengan status HTTP asli; tidak membocorkan isi pengajuan.
 *
 * @param array<string, mixed> $user
 * @param array<int, string> $capabilities
 */
function izin_wali_tolak(IzinException $exception, array $user, array $capabilities, string $mode): never
{
    http_response_code($exception->status());
    portal_header('Pengajuan wali', $capabilities, $mode, $user, [
        'active' => $mode === Capabilities::ORANG_TUA ? 'izin_anak' : 'izin',
        'breadcrumbs' => [['label' => 'Beranda', 'url' => app_url('/portal/index.php')], ['label' => 'Pengajuan wali']],
    ]);
    ah_note($exception->status() === 503 ? 'warning' : 'danger', (int) $exception->status() . ' — ' . $exception->getMessage(),
        '<p class="mb-0 mt-2"><a href="' . portal_e(app_url('/portal/izin_wali.php')) . '">Kembali ke daftar pengajuan wali</a></p>');
    portal_footer();
    exit;
}
