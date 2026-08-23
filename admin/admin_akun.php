<?php

declare(strict_types=1);

use App\Account\AccountRepository;
use App\Account\AccountService;
use App\Http\Csrf;

require_once __DIR__ . '/_guard.php';

$repository = new AccountRepository($koneksi);
$service = new AccountService($repository, audit_logger(), push_device_repository());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid($_POST['_csrf'] ?? null);
    try {
        $action = (string) ($_POST['action'] ?? '');
        $message = null;
        $temporaryPassword = null;

        if ($action === 'create') {
            $result = $service->createTeacher($_POST, $currentUser['id']);
            $message = 'Akun guru berhasil dibuat.';
            $temporaryPassword = $result['temporary_password'];
        } elseif ($action === 'status') {
            $service->setActive((int) ($_POST['user_id'] ?? 0), ($_POST['is_active'] ?? '') === '1', $currentUser['id']);
            $message = 'Status akun berhasil diperbarui.';
        } elseif ($action === 'role') {
            $service->setRole((int) ($_POST['user_id'] ?? 0), (string) ($_POST['role'] ?? ''), $currentUser['id']);
            $message = 'Role akun berhasil diperbarui.';
        } elseif ($action === 'reset_password') {
            $temporaryPassword = $service->resetPassword((int) ($_POST['user_id'] ?? 0), $currentUser['id']);
            $message = 'Password sementara berhasil dibuat.';
        } else {
            throw new InvalidArgumentException('Aksi tidak dikenal.');
        }

        $_SESSION['account_flash'] = ['type' => 'success', 'message' => $message, 'temporary_password' => $temporaryPassword];
    } catch (Throwable $exception) {
        $_SESSION['account_flash'] = ['type' => 'danger', 'message' => $exception->getMessage(), 'temporary_password' => null];
    }

    header('Location: admin_akun.php');
    exit;
}

$flash = $_SESSION['account_flash'] ?? null;
unset($_SESSION['account_flash']);
$accounts = $repository->all();
$teachers = $repository->availableTeachers();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kelola Akun - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
<div class="container-fluid"><div class="row">
    <?php include 'sidebar.php'; ?>
    <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
            <div><h1 class="h2 mb-1">Akun & Hak Akses</h1><p class="text-muted mb-0">Kelola akun aktif, role, dan password sementara.</p></div>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createAccount" <?= $teachers ? '' : 'disabled' ?>>Tambah akun guru</button>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>" role="alert">
                <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
                <?php if ($flash['temporary_password']): ?>
                    <hr><p class="mb-1"><strong>Password sementara (ditampilkan sekali):</strong></p>
                    <code class="user-select-all fs-5"><?= htmlspecialchars($flash['temporary_password'], ENT_QUOTES, 'UTF-8') ?></code>
                    <p class="small mb-0 mt-2">Sampaikan secara aman. Pengguna wajib menggantinya saat login pertama.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm"><div class="card-body table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Akun</th><th>Guru</th><th>Role</th><th>Status</th><th>Login terakhir</th><th class="text-end">Tindakan</th></tr></thead>
                <tbody>
                <?php foreach ($accounts as $account): $roles = $account['roles'] ? explode(',', $account['roles']) : []; ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($account['name'], ENT_QUOTES, 'UTF-8') ?></strong><br><span class="text-muted small">@<?= htmlspecialchars($account['username'], ENT_QUOTES, 'UTF-8') ?></span><?php if ($account['force_password_change']): ?><br><span class="badge text-bg-warning">Wajib ganti password</span><?php endif; ?></td>
                        <td><?= htmlspecialchars($account['nama_guru'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge text-bg-secondary"><?= htmlspecialchars(implode(', ', $roles) ?: 'Tanpa role', ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= $account['is_active'] ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-danger">Nonaktif</span>' ?></td>
                        <td><?= htmlspecialchars($account['last_login_at'] ?? 'Belum pernah', ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-end">
                            <div class="d-flex flex-wrap gap-1 justify-content-end">
                                <form method="post">
                                    <?= Csrf::input() ?><input type="hidden" name="action" value="status"><input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>"><input type="hidden" name="is_active" value="<?= $account['is_active'] ? '0' : '1' ?>">
                                    <button class="btn btn-sm btn-outline-<?= $account['is_active'] ? 'danger' : 'success' ?>" <?= (int) $account['id'] === $currentUser['id'] ? 'disabled title="Tidak dapat mengubah akun sendiri"' : '' ?>><?= $account['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                                </form>
                                <form method="post" class="d-flex">
                                    <?= Csrf::input() ?><input type="hidden" name="action" value="role"><input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                                    <select class="form-select form-select-sm" name="role" aria-label="Role" <?= (int) $account['id'] === $currentUser['id'] ? 'disabled' : '' ?>><option value="guru" <?= in_array('guru', $roles, true) ? 'selected' : '' ?>>Guru</option><option value="admin" <?= in_array('admin', $roles, true) ? 'selected' : '' ?>>Admin</option></select>
                                    <button class="btn btn-sm btn-outline-primary" <?= (int) $account['id'] === $currentUser['id'] ? 'disabled' : '' ?>>Simpan</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Buat password sementara baru untuk akun ini?')">
                                    <?= Csrf::input() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                                    <button class="btn btn-sm btn-outline-warning" <?= (int) $account['id'] === $currentUser['id'] ? 'disabled title="Gunakan halaman Ganti Password"' : '' ?>>Reset password</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    </main>
</div></div>

<div class="modal fade" id="createAccount" tabindex="-1" aria-labelledby="createAccountLabel" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <div class="modal-header"><h2 class="modal-title fs-5" id="createAccountLabel">Tambah akun guru</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
            <div class="modal-body">
                <?= Csrf::input() ?><input type="hidden" name="action" value="create">
                <div class="mb-3"><label class="form-label" for="guru_id">Data guru</label><select class="form-select" id="guru_id" name="guru_id" required><option value="">Pilih guru</option><?php foreach ($teachers as $teacher): ?><option value="<?= (int) $teacher['id'] ?>"><?= htmlspecialchars($teacher['nama_guru'] . ($teacher['nip'] ? ' — ' . $teacher['nip'] : ''), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <div class="mb-3"><label class="form-label" for="name">Nama akun</label><input class="form-control" id="name" name="name" maxlength="100" required></div>
                <div class="mb-3"><label class="form-label" for="username_create">Username</label><input class="form-control" id="username_create" name="username" minlength="4" maxlength="50" pattern="[a-z0-9._-]+" required><div class="form-text">Huruf kecil, angka, titik, garis bawah, atau tanda hubung.</div></div>
                <div class="mb-3"><label class="form-label" for="email">Email (opsional)</label><input class="form-control" type="email" id="email" name="email" maxlength="191"></div>
                <div class="mb-3"><label class="form-label" for="phone">Nomor HP (opsional)</label><input class="form-control" id="phone" name="phone" maxlength="20"></div>
                <p class="alert alert-info small mb-0">Sistem membuat password sementara acak dan mewajibkan penggantian saat login pertama.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-success">Buat akun</button></div>
        </form>
    </div></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
