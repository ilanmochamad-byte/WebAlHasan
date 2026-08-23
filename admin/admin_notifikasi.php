<?php

declare(strict_types=1);

use App\Notification\NotificationChannel;
use App\Notification\NotificationException;

require_once __DIR__ . '/_guard.php';

/**
 * Panel kanal notifikasi (V2 Fase 4).
 *
 * Menyediakan: status in-app/push/WhatsApp, pemeriksaan konfigurasi, tombol
 * pesan uji, sakelar kanal, daftar pengiriman gagal, dan percobaan ulang aman.
 *
 * Halaman ini tidak pernah menampilkan credential, token perangkat, atau nomor
 * tujuan. Yang ditampilkan hanyalah NAMA environment yang dibutuhkan, status
 * pemeriksaan, dan pesan galat yang sudah dibersihkan `SafeError`.
 *
 * Seluruh otorisasi dikerjakan `NotificationAdminService` di server;
 * `_guard.php` hanya lapisan pertama.
 */

$service = notification_admin_service();
$meta = [
    'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null,
    'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null,
];

function notif_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string) ($_POST['aksi'] ?? '');
    $kanal = (string) ($_POST['kanal'] ?? '');
    try {
        $hasil = match ($aksi) {
            'periksa' => $service->periksaKonfigurasi($currentUser, $kanal, $meta),
            'sakelar' => $service->ubahSakelar($currentUser, $kanal, ((string) ($_POST['aktif'] ?? '0')) === '1', $meta),
            'pesan_uji' => $service->kirimPesanUji($currentUser, $kanal, $meta),
            'coba_ulang' => $service->cobaUlang($currentUser, (int) ($_POST['outbox_id'] ?? 0), $meta),
            'worker' => $service->jalankanWorker($currentUser, $kanal, ((string) ($_POST['uji_coba'] ?? '0')) === '1'),
            default => throw NotificationException::invalid('Aksi tidak dikenal.'),
        };
        $_SESSION['notif_flash'] = [
            'jenis' => ($hasil['status'] ?? 'Lulus') === 'Gagal' ? 'warning' : 'success',
            'pesan' => (string) ($hasil['pesan'] ?? 'Perintah dijalankan.'),
            'detail' => array_values(array_filter([
                ...(array) ($hasil['detail'] ?? []),
                ...(array) ($hasil['catatan'] ?? []),
                isset($hasil['worker']) && is_array($hasil['worker'])
                    ? 'Worker: diproses ' . (int) $hasil['worker']['diproses']
                        . ', terkirim ' . (int) $hasil['worker']['terkirim']
                        . ', gagal ' . (int) $hasil['worker']['gagal']
                        . ((string) ($hasil['worker']['alasan'] ?? '') === '' ? '' : ' — ' . (string) $hasil['worker']['alasan'])
                    : null,
                isset($hasil['diproses'])
                    ? 'Worker: diproses ' . (int) $hasil['diproses']
                        . ', terkirim ' . (int) $hasil['terkirim']
                        . ', gagal ' . (int) $hasil['gagal']
                    : null,
            ])),
        ];
    } catch (NotificationException $exception) {
        $_SESSION['notif_flash'] = ['jenis' => 'danger', 'pesan' => $exception->getMessage(), 'detail' => []];
    }

    header('Location: ' . app_url('/admin/admin_notifikasi.php'));
    exit;
}

$flash = $_SESSION['notif_flash'] ?? null;
unset($_SESSION['notif_flash']);

$status = $service->status($currentUser);
$kegagalan = $service->kegagalan($currentUser, [
    'kanal' => (string) ($_GET['kanal'] ?? ''),
    'page' => (int) ($_GET['page'] ?? 1),
    'per_page' => 20,
]);
$auditKanal = $service->auditKanal($currentUser, 25);
$csrf = \App\Http\Csrf::input();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kanal Notifikasi - Admin Al Hasan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-light">
<div class="container-fluid"><div class="row">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center border-bottom pb-3 mb-4">
            <div>
                <h1 class="h3 mb-1">Kanal Notifikasi</h1>
                <p class="text-muted mb-0">
                    Status kanal, pemeriksaan konfigurasi, sakelar, dan pengiriman yang gagal.
                    Terakhir diperbarui: <?= notif_e($status['diperbarui_pada'] ?? '-') ?>.
                </p>
            </div>
            <a class="btn btn-outline-secondary" href="<?= notif_e(app_url('/portal/notifikasi.php')) ?>">Pusat notifikasi saya</a>
        </div>

        <?php if (is_array($flash)): ?>
            <div class="alert alert-<?= notif_e($flash['jenis']) ?>" role="alert">
                <div><?= notif_e($flash['pesan']) ?></div>
                <?php if ($flash['detail'] !== []): ?>
                    <ul class="mb-0 mt-2 small">
                        <?php foreach ($flash['detail'] as $baris): ?>
                            <li><?= notif_e($baris) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="alert alert-secondary small" role="note">
            <strong>Catatan keamanan.</strong> Halaman ini tidak pernah menampilkan credential, token perangkat,
            atau nomor tujuan. Secret penyedia berada di environment server dan tidak disimpan pada basis data,
            audit, maupun log.
        </div>

        <div class="row g-3 mb-4">
            <?php foreach ($status['kanal'] as $kanal): ?>
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h2 class="h5 mb-0"><?= notif_e($kanal['label']) ?></h2>
                                <span class="badge text-bg-<?= $kanal['aktif'] ? 'success' : 'secondary' ?>">
                                    <?= $kanal['aktif'] ? 'Aktif' : 'Nonaktif' ?>
                                </span>
                            </div>
                            <p class="text-muted small"><?= notif_e($kanal['keterangan']) ?></p>

                            <div class="mb-2">
                                <span class="badge text-bg-<?= ($kanal['kesiapan']['siap'] ?? false) ? 'success' : 'warning' ?>">
                                    <?= ($kanal['kesiapan']['siap'] ?? false) ? 'Konfigurasi siap' : 'Konfigurasi belum siap' ?>
                                </span>
                                <div class="small text-muted mt-1"><?= notif_e($kanal['kesiapan']['pesan'] ?? '') ?></div>
                                <?php if (($kanal['kesiapan']['detail'] ?? []) !== []): ?>
                                    <ul class="small text-muted mt-1 mb-0">
                                        <?php foreach ($kanal['kesiapan']['detail'] as $baris): ?>
                                            <li><?= notif_e($baris) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>

                            <?php if (isset($kanal['pemeriksaan'])): ?>
                                <div class="small mb-2">
                                    Pemeriksaan terakhir:
                                    <span class="badge text-bg-<?= match ((string) $kanal['pemeriksaan']['status']) {
                                        'Lulus' => 'success',
                                        'Gagal' => 'danger',
                                        default => 'secondary',
                                    } ?>"><?= notif_e($kanal['pemeriksaan']['status']) ?></span>
                                    <?php if (($kanal['pemeriksaan']['pada'] ?? null) !== null): ?>
                                        <span class="text-muted"><?= notif_e($kanal['pemeriksaan']['pada']) ?></span>
                                    <?php endif; ?>
                                    <?php if (($kanal['pemeriksaan']['pesan'] ?? null) !== null): ?>
                                        <div class="text-muted"><?= notif_e($kanal['pemeriksaan']['pesan']) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (isset($kanal['penyedia'])): ?>
                                <div class="small mb-2">
                                    Penyedia: <code><?= notif_e($kanal['penyedia']['nama']) ?></code>
                                    <?php if (!$kanal['penyedia']['mengirim_nyata']): ?>
                                        <span class="badge text-bg-warning">Adapter uji — bukan pengiriman nyata</span>
                                    <?php endif; ?>
                                    <details class="mt-1">
                                        <summary class="text-muted">Environment yang dibutuhkan (nama saja)</summary>
                                        <ul class="mb-0">
                                            <?php foreach ($kanal['penyedia']['environment_dibutuhkan'] as $env): ?>
                                                <li><code><?= notif_e($env) ?></code></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                </div>
                            <?php endif; ?>

                            <?php if (isset($kanal['perangkat'])): ?>
                                <div class="small text-muted mb-2">
                                    Perangkat terdaftar: <?= (int) $kanal['perangkat']['total'] ?>
                                    (aktif <?= (int) $kanal['perangkat']['aktif'] ?>,
                                    dicabut <?= (int) $kanal['perangkat']['dicabut'] ?>)
                                </div>
                            <?php endif; ?>

                            <div class="small text-muted mb-3">
                                Antrean: menunggu <?= (int) ($kanal['antrean']['Queued'] ?? 0) ?>,
                                terkirim <?= (int) ($kanal['antrean']['Sent'] ?? 0) ?>,
                                gagal <?= (int) ($kanal['antrean']['Failed'] ?? 0) ?>
                                (permanen <?= (int) ($kanal['antrean']['gagal_permanen'] ?? 0) ?>)
                            </div>

                            <div class="mt-auto d-flex flex-wrap gap-2">
                                <form method="post" class="mb-0">
                                    <?= $csrf ?>
                                    <input type="hidden" name="aksi" value="periksa">
                                    <input type="hidden" name="kanal" value="<?= notif_e($kanal['kanal']) ?>">
                                    <button class="btn btn-sm btn-outline-primary">Periksa konfigurasi</button>
                                </form>

                                <?php if ($kanal['dapat_dimatikan']): ?>
                                    <form method="post" class="mb-0">
                                        <?= $csrf ?>
                                        <input type="hidden" name="aksi" value="sakelar">
                                        <input type="hidden" name="kanal" value="<?= notif_e($kanal['kanal']) ?>">
                                        <input type="hidden" name="aktif" value="<?= $kanal['aktif'] ? '0' : '1' ?>">
                                        <button class="btn btn-sm btn-<?= $kanal['aktif'] ? 'outline-danger' : 'success' ?>">
                                            <?= $kanal['aktif'] ? 'Matikan kanal' : 'Nyalakan kanal' ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-secondary" disabled
                                            title="Notifikasi dalam aplikasi adalah sumber status utama dan tidak dapat dimatikan.">
                                        Selalu aktif
                                    </button>
                                <?php endif; ?>

                                <form method="post" class="mb-0">
                                    <?= $csrf ?>
                                    <input type="hidden" name="aksi" value="pesan_uji">
                                    <input type="hidden" name="kanal" value="<?= notif_e($kanal['kanal']) ?>">
                                    <button class="btn btn-sm btn-outline-success" <?= $kanal['aktif'] ? '' : 'disabled' ?>>
                                        Kirim pesan uji
                                    </button>
                                </form>

                                <?php if (in_array($kanal['kanal'], NotificationChannel::EKSTERNAL, true)): ?>
                                    <form method="post" class="mb-0">
                                        <?= $csrf ?>
                                        <input type="hidden" name="aksi" value="worker">
                                        <input type="hidden" name="kanal" value="<?= notif_e($kanal['kanal']) ?>">
                                        <button class="btn btn-sm btn-outline-secondary">Jalankan worker sekali</button>
                                    </form>
                                    <form method="post" class="mb-0">
                                        <?= $csrf ?>
                                        <input type="hidden" name="aksi" value="worker">
                                        <input type="hidden" name="kanal" value="<?= notif_e($kanal['kanal']) ?>">
                                        <input type="hidden" name="uji_coba" value="1">
                                        <button class="btn btn-sm btn-outline-secondary">Uji coba (tanpa kirim)</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h2 class="h5 mb-0">Pengiriman gagal</h2>
                <form method="get" class="d-flex gap-2 mb-0">
                    <select class="form-select form-select-sm" name="kanal" onchange="this.form.submit()">
                        <option value="">Semua kanal</option>
                        <?php foreach (NotificationChannel::EKSTERNAL as $pilihan): ?>
                            <option value="<?= notif_e($pilihan) ?>" <?= ((string) ($_GET['kanal'] ?? '')) === $pilihan ? 'selected' : '' ?>>
                                <?= notif_e(NotificationChannel::label($pilihan)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button class="btn btn-sm btn-outline-secondary">Filter</button></noscript>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr>
                        <th>#</th><th>Peristiwa</th><th>Kanal</th><th>Penerima</th>
                        <th>Percobaan</th><th>Galat aman</th><th>Berikutnya</th><th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($kegagalan['items'] as $baris): ?>
                        <tr>
                            <td><?= (int) $baris['id'] ?></td>
                            <td class="small">
                                <?= notif_e($baris['event_label']) ?>
                                <?php if ($baris['pengajuan_id'] !== null): ?>
                                    <div class="text-muted">Pengajuan #<?= (int) $baris['pengajuan_id'] ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge text-bg-light border"><?= notif_e($baris['kanal']) ?></span></td>
                            <td class="small"><?= notif_e($baris['penerima_nama'] ?? ('User #' . (int) $baris['penerima_user_id'])) ?></td>
                            <td class="small">
                                <?= (int) $baris['percobaan'] ?>/<?= (int) $baris['maksimum_percobaan'] ?>
                                <?php if ($baris['gagal_permanen']): ?>
                                    <span class="badge text-bg-danger">Permanen</span>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <code><?= notif_e($baris['error_kode'] ?? '-') ?></code>
                                <div class="text-muted"><?= notif_e($baris['error_aman'] ?? '-') ?></div>
                            </td>
                            <td class="small"><?= notif_e($baris['percobaan_berikutnya_pada'] ?? '-') ?></td>
                            <td class="text-end">
                                <form method="post" class="mb-0">
                                    <?= $csrf ?>
                                    <input type="hidden" name="aksi" value="coba_ulang">
                                    <input type="hidden" name="outbox_id" value="<?= (int) $baris['id'] ?>">
                                    <button class="btn btn-sm btn-outline-primary">Coba ulang</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($kegagalan['items'] === []): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">
                            Tidak ada pengiriman gagal. Notifikasi dalam aplikasi tetap tersedia apa pun keadaan kanal lain.
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ((int) $kegagalan['pagination']['total_pages'] > 1): ?>
                <div class="card-footer bg-white small text-muted">
                    Halaman <?= (int) $kegagalan['pagination']['current_page'] ?>
                    dari <?= (int) $kegagalan['pagination']['total_pages'] ?>
                    (<?= (int) $kegagalan['pagination']['total'] ?> baris gagal).
                </div>
            <?php endif; ?>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h2 class="h5 mb-0">Audit perubahan kanal</h2></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Waktu</th><th>Aksi</th><th>Kanal</th><th>Perubahan</th><th>Hasil</th><th>Oleh</th></tr></thead>
                    <tbody>
                    <?php foreach ($auditKanal['items'] as $baris): ?>
                        <tr>
                            <td class="small"><?= notif_e($baris['created_at']) ?></td>
                            <td class="small"><?= notif_e($baris['aksi']) ?></td>
                            <td class="small"><?= notif_e($baris['kanal'] ?? '-') ?></td>
                            <td class="small">
                                <?= notif_e($baris['nilai_sebelum'] ?? '-') ?> &rarr; <?= notif_e($baris['nilai_sesudah'] ?? '-') ?>
                            </td>
                            <td class="small">
                                <?= notif_e($baris['hasil'] ?? '-') ?>
                                <?php if (($baris['pesan'] ?? null) !== null): ?>
                                    <div class="text-muted"><?= notif_e($baris['pesan']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= notif_e($baris['aktor_nama'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($auditKanal['items'] === []): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Belum ada perubahan kanal yang tercatat.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
