<?php

declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = perizinan_account_service();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        $kind = (string) ($_POST['kind'] ?? '');
        $temporaryPassword = null;
        $message = null;

        if ($action === 'create') {
            $result = $service->create($kind, $_POST, (int) $currentUser['id']);
            $temporaryPassword = $result['temporary_password'];
            $message = 'Akun berhasil dibuat dan dihubungkan ke master data.';
        } elseif ($action === 'link') {
            $service->link(
                $kind,
                (int) ($_POST['user_id'] ?? 0),
                (int) ($_POST['master_id'] ?? 0),
                (int) $currentUser['id']
            );
            $message = 'Akun berhasil dihubungkan ke master data.';
        } elseif ($action === 'status') {
            $service->setActive((int) ($_POST['user_id'] ?? 0), ($_POST['is_active'] ?? '') === '1', (int) $currentUser['id']);
            $message = 'Status akun berhasil diperbarui.';
        } elseif ($action === 'reset_password') {
            $temporaryPassword = $service->resetPassword((int) ($_POST['user_id'] ?? 0), (int) $currentUser['id']);
            $message = 'Password sementara berhasil dibuat.';
        } else {
            throw new InvalidArgumentException('Aksi tidak dikenal.');
        }

        $_SESSION['perizinan_account_flash'] = ['type' => 'success', 'message' => $message, 'temporary_password' => $temporaryPassword];
    } catch (Throwable $exception) {
        $_SESSION['perizinan_account_flash'] = ['type' => 'danger', 'message' => $exception->getMessage(), 'temporary_password' => null];
    }

    master_redirect('admin_akun_perizinan.php');
}

$flash = $_SESSION['perizinan_account_flash'] ?? null;
unset($_SESSION['perizinan_account_flash']);

$pengurusAccounts = $service->accounts('pengurus');
$parentAccounts = $service->accounts('orang_tua');
$availablePengurus = $service->availablePengurus();
$availableWali = $service->availableWali();
$unlinked = $service->unlinkedAccounts();

$inspectWaliId = (int) ($_GET['wali_id'] ?? 0);
$relations = $inspectWaliId > 0 ? $service->waliRelations($inspectWaliId) : [];

master_header('Akun Pengurus & Orang Tua');
?>
<div class="border-bottom pb-3 mb-4">
    <h1 class="h3">Akun Pengurus &amp; Orang Tua</h1>
    <p class="text-muted mb-0">
        Setiap akun pengurus terhubung ke <strong>tepat satu</strong> baris pengurus, dan setiap akun orang tua
        terhubung ke <strong>tepat satu</strong> baris wali. Keunikan relasi dijaga oleh kunci unik basis data.
    </p>
</div>

<?php if (is_array($flash)): ?>
    <div class="alert alert-<?= master_e($flash['type']) ?>">
        <?= master_e($flash['message']) ?>
        <?php if (!empty($flash['temporary_password'])): ?>
            <hr>
            <p class="mb-1"><strong>Password sementara (ditampilkan sekali):</strong></p>
            <code class="user-select-all fs-5"><?= master_e($flash['temporary_password']) ?></code>
            <p class="small mb-0 mt-2">Sampaikan secara aman. Pengguna wajib menggantinya saat login pertama.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-pengurus" type="button" role="tab">Akun Pengurus</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-orangtua" type="button" role="tab">Akun Orang Tua</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-relasi" type="button" role="tab">Periksa Relasi Wali–Santri</button></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="tab-pengurus" role="tabpanel">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Buat akun pengurus baru</strong></div>
            <div class="card-body">
                <?php if ($availablePengurus === []): ?>
                    <p class="text-muted mb-0">Semua pengurus aktif sudah memiliki akun, atau belum ada pengurus aktif.</p>
                <?php else: ?>
                    <form method="post" class="row g-3">
                        <?= master_csrf() ?>
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="kind" value="pengurus">
                        <div class="col-md-4">
                            <label class="form-label" for="pengurus_master">Data pengurus</label>
                            <select class="form-select" id="pengurus_master" name="pengurus_id" required>
                                <option value="">Pilih pengurus</option>
                                <?php foreach ($availablePengurus as $option): ?>
                                    <option value="<?= (int) $option['id'] ?>"><?= master_e($option['nama'] . ' — ' . $option['jabatan']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3"><label class="form-label" for="pengurus_name">Nama akun</label><input class="form-control" id="pengurus_name" name="name" maxlength="100" required></div>
                        <div class="col-md-3"><label class="form-label" for="pengurus_username">Username</label><input class="form-control" id="pengurus_username" name="username" minlength="4" maxlength="50" pattern="[a-z0-9._-]+" required></div>
                        <div class="col-md-2"><label class="form-label" for="pengurus_phone">Nomor HP</label><input class="form-control" id="pengurus_phone" name="phone" maxlength="20"></div>
                        <div class="col-md-4"><label class="form-label" for="pengurus_email">Email (opsional)</label><input class="form-control" type="email" id="pengurus_email" name="email" maxlength="191"></div>
                        <div class="col-md-3 d-flex align-items-end"><button class="btn btn-success">Buat akun pengurus</button></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($unlinked !== [] && $availablePengurus !== []): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><strong>Hubungkan akun yang sudah ada ke pengurus</strong></div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <?= master_csrf() ?>
                        <input type="hidden" name="action" value="link">
                        <input type="hidden" name="kind" value="pengurus">
                        <div class="col-md-4">
                            <label class="form-label" for="link_pengurus_user">Akun tanpa relasi</label>
                            <select class="form-select" id="link_pengurus_user" name="user_id" required>
                                <option value="">Pilih akun</option>
                                <?php foreach ($unlinked as $account): ?>
                                    <option value="<?= (int) $account['id'] ?>"><?= master_e($account['name'] . ' (@' . $account['username'] . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="link_pengurus_master">Data pengurus</label>
                            <select class="form-select" id="link_pengurus_master" name="master_id" required>
                                <option value="">Pilih pengurus</option>
                                <?php foreach ($availablePengurus as $option): ?>
                                    <option value="<?= (int) $option['id'] ?>"><?= master_e($option['nama'] . ' — ' . $option['jabatan']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end"><button class="btn btn-outline-success">Hubungkan</button></div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php master_account_table($pengurusAccounts, 'pengurus', (int) $currentUser['id']); ?>
    </div>

    <div class="tab-pane fade" id="tab-orangtua" role="tabpanel">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Buat akun orang tua baru</strong></div>
            <div class="card-body">
                <?php if ($availableWali === []): ?>
                    <p class="text-muted mb-0">Tidak ada wali aktif dengan relasi santri aktif yang belum memiliki akun.</p>
                <?php else: ?>
                    <form method="post" class="row g-3">
                        <?= master_csrf() ?>
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="kind" value="orang_tua">
                        <div class="col-md-4">
                            <label class="form-label" for="wali_master">Data wali</label>
                            <select class="form-select" id="wali_master" name="wali_id" required>
                                <option value="">Pilih wali</option>
                                <?php foreach ($availableWali as $option): ?>
                                    <option value="<?= (int) $option['id'] ?>"><?= master_e($option['nama'] . ' — ' . $option['jumlah_santri'] . ' santri') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3"><label class="form-label" for="wali_name">Nama akun</label><input class="form-control" id="wali_name" name="name" maxlength="100" required></div>
                        <div class="col-md-3"><label class="form-label" for="wali_username">Username</label><input class="form-control" id="wali_username" name="username" minlength="4" maxlength="50" pattern="[a-z0-9._-]+" required></div>
                        <div class="col-md-2"><label class="form-label" for="wali_phone">Nomor HP</label><input class="form-control" id="wali_phone" name="phone" maxlength="20"></div>
                        <div class="col-md-4"><label class="form-label" for="wali_email">Email (opsional)</label><input class="form-control" type="email" id="wali_email" name="email" maxlength="191"></div>
                        <div class="col-md-3 d-flex align-items-end"><button class="btn btn-success">Buat akun orang tua</button></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($unlinked !== [] && $availableWali !== []): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><strong>Hubungkan akun yang sudah ada ke wali</strong></div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <?= master_csrf() ?>
                        <input type="hidden" name="action" value="link">
                        <input type="hidden" name="kind" value="orang_tua">
                        <div class="col-md-4">
                            <label class="form-label" for="link_wali_user">Akun tanpa relasi</label>
                            <select class="form-select" id="link_wali_user" name="user_id" required>
                                <option value="">Pilih akun</option>
                                <?php foreach ($unlinked as $account): ?>
                                    <option value="<?= (int) $account['id'] ?>"><?= master_e($account['name'] . ' (@' . $account['username'] . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="link_wali_master">Data wali</label>
                            <select class="form-select" id="link_wali_master" name="master_id" required>
                                <option value="">Pilih wali</option>
                                <?php foreach ($availableWali as $option): ?>
                                    <option value="<?= (int) $option['id'] ?>"><?= master_e($option['nama'] . ' — ' . $option['jumlah_santri'] . ' santri') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end"><button class="btn btn-outline-success">Hubungkan</button></div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php master_account_table($parentAccounts, 'orang_tua', (int) $currentUser['id']); ?>
    </div>

    <div class="tab-pane fade" id="tab-relasi" role="tabpanel">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Periksa relasi wali–santri</strong></div>
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label" for="wali_id">Akun orang tua</label>
                        <select class="form-select" id="wali_id" name="wali_id" required>
                            <option value="">Pilih akun orang tua</option>
                            <?php foreach ($parentAccounts as $account): ?>
                                <option value="<?= (int) $account['wali_id'] ?>" <?= $inspectWaliId === (int) $account['wali_id'] ? 'selected' : '' ?>>
                                    <?= master_e($account['name'] . ' → ' . ($account['wali_nama'] ?? 'tanpa wali')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end"><button class="btn btn-outline-primary">Periksa relasi</button></div>
                </form>
            </div>
        </div>

        <?php if ($inspectWaliId > 0): ?>
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead><tr><th>Santri</th><th>NIS</th><th>Hubungan</th><th>Utama</th><th>Status relasi</th></tr></thead>
                        <tbody>
                        <?php foreach ($relations as $relation): ?>
                            <tr class="<?= $relation['archived_at'] ? 'text-muted' : '' ?>">
                                <td><?= master_e($relation['nama_santri']) ?></td>
                                <td><?= master_e($relation['nis']) ?></td>
                                <td><?= master_e($relation['hubungan']) ?></td>
                                <td><?= (int) $relation['is_primary'] === 1 ? 'Ya' : 'Tidak' ?></td>
                                <td><?= $relation['archived_at']
                                        ? '<span class="badge text-bg-secondary">Diarsipkan — tidak terlihat oleh orang tua</span>'
                                        : '<span class="badge text-bg-success">Aktif</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($relations === []): ?>
                            <tr><td colspan="5" class="text-muted text-center py-4">Wali ini belum memiliki relasi santri.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
master_footer();

/**
 * @param array<int, array<string, mixed>> $accounts
 */
function master_account_table(array $accounts, string $kind, int $actorId): void
{
    ?>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Akun</th><th>Master data</th><th>Status</th><th>Login terakhir</th><th class="text-end">Tindakan</th></tr></thead>
                <tbody>
                <?php foreach ($accounts as $account): ?>
                    <tr>
                        <td>
                            <strong><?= master_e($account['name']) ?></strong><br>
                            <span class="text-muted small">@<?= master_e($account['username']) ?></span>
                            <?php if ((int) $account['force_password_change'] === 1): ?>
                                <br><span class="badge text-bg-warning">Wajib ganti password</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($kind === 'pengurus'): ?>
                                <?= master_e($account['pengurus_nama'] ?? '— belum terhubung') ?>
                                <?php if ($account['pengurus_nama'] !== null && (int) $account['pengurus_aktif'] !== 1): ?>
                                    <br><span class="badge text-bg-danger">Pengurus nonaktif</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <?= master_e($account['wali_nama'] ?? '— belum terhubung') ?>
                                <br><span class="text-muted small"><?= (int) $account['jumlah_santri'] ?> santri dengan relasi aktif</span>
                                <?php if ($account['wali_nama'] !== null && (int) $account['wali_aktif'] !== 1): ?>
                                    <br><span class="badge text-bg-danger">Wali nonaktif</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $account['is_active'] === 1 ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-danger">Nonaktif</span>' ?></td>
                        <td class="small"><?= master_e($account['last_login_at'] ?? 'Belum pernah') ?></td>
                        <td class="text-end">
                            <div class="d-flex flex-wrap gap-1 justify-content-end">
                                <form method="post">
                                    <?= master_csrf() ?>
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                                    <input type="hidden" name="is_active" value="<?= (int) $account['is_active'] === 1 ? '0' : '1' ?>">
                                    <button class="btn btn-sm btn-outline-<?= (int) $account['is_active'] === 1 ? 'danger' : 'success' ?>" <?= (int) $account['id'] === $actorId ? 'disabled' : '' ?>>
                                        <?= (int) $account['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?>
                                    </button>
                                </form>
                                <form method="post" onsubmit="return confirm('Buat password sementara baru untuk akun ini?')">
                                    <?= master_csrf() ?>
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                                    <button class="btn btn-sm btn-outline-warning" <?= (int) $account['id'] === $actorId ? 'disabled' : '' ?>>Reset password</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($accounts === []): ?>
                    <tr><td colspan="5" class="text-muted text-center py-4">Belum ada akun pada kategori ini.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
