<?php

declare(strict_types=1);

use App\Auth\Capabilities;
use App\Http\Csrf;
use App\Schedule\ScheduleException;

require_once dirname(__DIR__) . '/app/bootstrap.php';

$currentUser = authorization()->requireWebUser();
if (!in_array('admin', $currentUser['roles'], true) && !in_array('guru', $currentUser['roles'], true)) {
    http_response_code(403);
    exit('Akses ditolak. Akun tidak memiliki hak jadwal atau pertemuan.');
}

// Tautan ke antrean perizinan hanya untuk akun yang benar-benar memiliki
// capability murobi (guru + penugasan murobi aktif). Menyembunyikan tautan ini
// BUKAN kontrol akses: `/portal/*` tetap dijaga PortalGuard di sisi server dan
// menolak guru tanpa penugasan dengan 403 meskipun URL-nya diketik manual.
$bolehAntreanIzin = capabilities()->has($currentUser, Capabilities::MUROBI);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['_csrf'] ?? null);
}

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$flash = static function (string $type, string $message): void { $_SESSION['_meeting_flash'] = compact('type', 'message'); };
$redirect = static function (string $query = ''): never {
    header('Location: ' . app_url('/admin/pertemuan_pengajian.php') . ($query !== '' ? '?' . $query : ''));
    exit;
};
$service = schedule_service();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'draft' || $action === 'open') {
            $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
            $date = (string) ($_POST['tanggal_pertemuan'] ?? '');
            $notes = (string) ($_POST['catatan'] ?? '');
            $meetingId = $action === 'draft'
                ? $service->createDraft($scheduleId, $date, $notes, $currentUser)
                : $service->open($scheduleId, $date, $notes, $currentUser);
            $flash('success', $action === 'draft' ? 'Draf pertemuan berhasil dibuat.' : 'Pertemuan berhasil dibuka dan daftar peserta telah dibekukan sebagai snapshot.');
            $redirect('id=' . $meetingId);
        }
        if ($action === 'complete') {
            $meetingId = (int) ($_POST['meeting_id'] ?? 0);
            $service->complete($meetingId, $currentUser);
            $flash('success', 'Pertemuan diselesaikan dan waktu selesai tercatat.');
            $redirect('id=' . $meetingId);
        }
        throw new ScheduleException('Aksi pertemuan tidak valid.');
    } catch (ScheduleException $exception) {
        $flash('danger', $exception->getMessage());
        $query = !empty($_POST['meeting_id']) ? 'id=' . (int) $_POST['meeting_id'] : (!empty($_POST['schedule_id']) ? 'schedule_id=' . (int) $_POST['schedule_id'] : '');
        $redirect($query);
    }
}

$message = $_SESSION['_meeting_flash'] ?? null;
unset($_SESSION['_meeting_flash']);
$scheduleOptions = $service->activeScheduleOptions($currentUser);
$meetings = $service->meetings($currentUser);
try {
    $selected = isset($_GET['id']) ? $service->meeting((int) $_GET['id'], $currentUser) : null;
} catch (ScheduleException $exception) {
    http_response_code(403);
    exit($escape($exception->getMessage()));
}
$selectedScheduleId = (int) ($_GET['schedule_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pertemuan Pengajian - Al Hasan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark"><div class="container"><span class="navbar-brand"><i class="fas fa-mosque me-2"></i>Pertemuan Pengajian</span><div class="d-flex gap-2"><?php if ($bolehAntreanIzin): ?><a class="btn btn-sm btn-success" href="<?= $escape(app_url('/portal/izin_antrean.php?mode=' . Capabilities::MUROBI)) ?>"><i class="fas fa-file-shield me-1"></i>Antrean Perizinan</a><?php endif; ?><?php if (in_array('admin', $currentUser['roles'], true)): ?><a class="btn btn-sm btn-outline-light" href="admin_jadwal_ngaji.php">Kelola Jadwal</a><?php endif; ?><a class="btn btn-sm btn-outline-danger" href="logout.php">Keluar</a></div></div></nav>
<main class="container py-4">
    <?php if (is_array($message)): ?><div class="alert alert-<?= $escape($message['type']) ?>" role="alert"><?= $escape($message['message']) ?></div><?php endif; ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center border-bottom pb-3 mb-4 gap-2"><div><h1 class="h3 mb-1">Jadwal dan Pertemuan</h1><p class="text-muted mb-0"><?= in_array('admin', $currentUser['roles'], true) ? 'Admin dapat membuka seluruh jadwal aktif.' : 'Hanya jadwal aktif milik Anda yang ditampilkan.' ?></p></div><span class="badge text-bg-secondary"><?= $escape($currentUser['name']) ?></span></div>

    <div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white"><strong>Buat atau Buka Pertemuan</strong></div><div class="card-body">
        <?php if ($scheduleOptions !== []): ?><form method="post" class="row g-3">
            <?= Csrf::input() ?>
            <div class="col-lg-6"><label class="form-label" for="schedule_id">Jadwal aktif semester ini</label><select class="form-select" id="schedule_id" name="schedule_id" required><option value="">Pilih jadwal</option><?php foreach ($scheduleOptions as $schedule): ?><option value="<?= (int) $schedule['id'] ?>" <?= $selectedScheduleId === (int) $schedule['id'] ? 'selected' : '' ?>><?= $escape($schedule['hari'] . ' ' . substr($schedule['waktu_mulai'], 0, 5) . '–' . substr($schedule['waktu_selesai'], 0, 5) . ' — ' . $schedule['nama_kelas'] . ' — ' . $schedule['fan_ilmu'] . ' — ' . $schedule['nama_guru']) ?></option><?php endforeach; ?></select><div class="form-text">Tanggal wajib jatuh pada hari sesuai pola jadwal.</div></div>
            <div class="col-lg-3"><label class="form-label" for="tanggal_pertemuan">Tanggal pertemuan</label><input class="form-control" id="tanggal_pertemuan" type="date" name="tanggal_pertemuan" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-lg-3"><label class="form-label" for="catatan">Catatan</label><input class="form-control" id="catatan" name="catatan" maxlength="2000" placeholder="Opsional"></div>
            <div class="col-12 d-flex gap-2"><button class="btn btn-outline-secondary" name="action" value="draft">Simpan Draf</button><button class="btn btn-success" name="action" value="open">Buka & Bekukan Peserta</button></div>
        </form><?php else: ?><div class="alert alert-warning mb-0">Tidak ada jadwal aktif dengan hari dan waktu terstruktur yang dapat dibuka untuk akun ini.</div><?php endif; ?>
    </div></div>

    <?php if ($selected): ?><div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white d-flex justify-content-between"><strong>Detail Pertemuan #<?= (int) $selected['id'] ?></strong><span class="badge <?= $selected['status'] === 'Selesai' ? 'text-bg-dark' : ($selected['status'] === 'Dibuka' ? 'text-bg-success' : 'text-bg-secondary') ?>"><?= $escape($selected['status']) ?></span></div><div class="card-body">
        <div class="row g-3 mb-4"><div class="col-md-3"><small class="text-muted d-block">Tanggal</small><?= $escape($selected['tanggal_pertemuan']) ?></div><div class="col-md-3"><small class="text-muted d-block">Jadwal</small><?= $escape($selected['hari'] . ', ' . substr($selected['waktu_mulai'], 0, 5) . '–' . substr($selected['waktu_selesai'], 0, 5)) ?></div><div class="col-md-3"><small class="text-muted d-block">Kelas</small><?= $escape($selected['nama_kelas']) ?></div><div class="col-md-3"><small class="text-muted d-block">Guru</small><?= $escape($selected['nama_guru']) ?></div><div class="col-md-3"><small class="text-muted d-block">Dibuka</small><?= $escape($selected['opened_at'] ?: '-') ?></div><div class="col-md-3"><small class="text-muted d-block">Selesai</small><?= $escape($selected['completed_at'] ?: '-') ?></div><div class="col-md-6"><small class="text-muted d-block">Catatan</small><?= $escape($selected['catatan'] ?: '-') ?></div></div>
        <?php if ($selected['status'] === 'Draf'): ?><form method="post" class="mb-4"><?= Csrf::input() ?><input type="hidden" name="schedule_id" value="<?= (int) $selected['jadwal_id'] ?>"><input type="hidden" name="tanggal_pertemuan" value="<?= $escape($selected['tanggal_pertemuan']) ?>"><input type="hidden" name="catatan" value="<?= $escape($selected['catatan']) ?>"><button class="btn btn-success" name="action" value="open">Buka & Bekukan Peserta</button></form><?php elseif ($selected['status'] === 'Dibuka'): ?><form method="post" class="mb-4" onsubmit="return confirm('Tandai pertemuan ini selesai?')"><?= Csrf::input() ?><input type="hidden" name="meeting_id" value="<?= (int) $selected['id'] ?>"><button class="btn btn-dark" name="action" value="complete">Selesaikan Pertemuan</button></form><?php endif; ?>
        <h2 class="h5">Snapshot Peserta (<?= count($selected['participants']) ?>)</h2><div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>NIS saat dibuka</th><th>Nama saat dibuka</th><th>ID santri</th><th>ID keanggotaan</th></tr></thead><tbody><?php foreach ($selected['participants'] as $participant): ?><tr><td><?= $escape($participant['nis_snapshot']) ?></td><td><?= $escape($participant['nama_santri_snapshot']) ?></td><td><?= (int) $participant['santri_id'] ?></td><td><?= $participant['plotting_kelas_id'] === null ? '-' : (int) $participant['plotting_kelas_id'] ?></td></tr><?php endforeach; ?><?php if ($selected['participants'] === []): ?><tr><td colspan="4" class="text-center text-muted py-3"><?= $selected['status'] === 'Draf' ? 'Peserta dibekukan saat pertemuan dibuka.' : 'Tidak ada keanggotaan aktif di kelas ini saat pertemuan dibuka.' ?></td></tr><?php endif; ?></tbody></table></div>
    </div></div><?php endif; ?>

    <div class="card border-0 shadow-sm"><div class="card-header bg-white"><strong>Riwayat Pertemuan</strong></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Tanggal</th><th>Jadwal</th><th>Kelas</th><th>Guru</th><th>Status</th><th>Peserta</th><th>Aksi</th></tr></thead><tbody><?php foreach ($meetings as $meeting): ?><tr><td><?= $escape($meeting['tanggal_pertemuan']) ?></td><td><?= $escape($meeting['hari'] . ' ' . substr($meeting['waktu_mulai'], 0, 5) . ' — ' . $meeting['fan_ilmu']) ?></td><td><?= $escape($meeting['nama_kelas']) ?></td><td><?= $escape($meeting['nama_guru']) ?></td><td><?= $escape($meeting['status']) ?></td><td><?= (int) $meeting['participant_count'] ?></td><td><a class="btn btn-sm btn-outline-primary" href="?id=<?= (int) $meeting['id'] ?>">Detail</a></td></tr><?php endforeach; ?><?php if ($meetings === []): ?><tr><td colspan="7" class="text-center text-muted py-5">Belum ada pertemuan.</td></tr><?php endif; ?></tbody></table></div></div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
