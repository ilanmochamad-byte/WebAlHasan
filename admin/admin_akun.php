<?php

declare(strict_types=1);

use App\Account\AccountRepository;
use App\Account\AccountService;

/**
 * Pusat "Akun & Hak Akses" (koreksi ke-1, keputusan pengguna 30 Agustus 2026).
 *
 * Menggabungkan pengelolaan akun guru/admin (V1) dengan akun pengurus/orang tua
 * (V2) dalam satu halaman. Masalah yang diperbaiki:
 *
 *   - halaman lama menampilkan SEMUA akun tetapi hanya menawarkan Guru/Admin
 *     pada dropdown role, sehingga akun orang tua tampak seolah "Guru";
 *   - jalur simpan lama menghapus SELURUH role sebelum menetapkan satu role,
 *     sehingga akun multi-peran kehilangan role lain diam-diam.
 *
 * Sekarang setiap role ditambahkan dan dicabut secara eksplisit satu per satu,
 * dengan validasi relasi master di server, konfirmasi khusus untuk hak admin,
 * dan perlindungan admin terakhir yang tahan permintaan bersamaan.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';

$service = account_service();
$perizinan = perizinan_account_service();
$aktorId = (int) $currentUser['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kembali = 'admin_akun.php' . (($q = ah_query([], $_GET)) === '' ? '' : '?' . $q);
    try {
        $action = (string) ($_POST['action'] ?? '');
        $userId = (int) ($_POST['user_id'] ?? 0);
        $temporaryPassword = null;
        $message = null;

        switch ($action) {
            case 'create_guru':
                $result = $service->createTeacher($_POST, $aktorId);
                $temporaryPassword = $result['temporary_password'];
                $message = 'Akun guru berhasil dibuat dan diberi role Guru.';
                break;

            case 'create':
                // Nama aksi dipertahankan agar formulir lama pada
                // admin_akun_perizinan.php tetap diproses penuh (bukan dialihkan).
                $result = $perizinan->create((string) ($_POST['kind'] ?? ''), $_POST, $aktorId);
                $temporaryPassword = $result['temporary_password'];
                $message = 'Akun berhasil dibuat dan dihubungkan ke master data.';
                break;

            case 'link':
                $perizinan->link(
                    (string) ($_POST['kind'] ?? ''),
                    $userId,
                    (int) ($_POST['master_id'] ?? 0),
                    $aktorId
                );
                $message = 'Akun berhasil dihubungkan ke master data dan memperoleh role terkait.';
                break;

            case 'grant_role':
                $service->grantRole($userId, (string) ($_POST['role'] ?? ''), $aktorId, $_POST);
                $message = 'Hak akses ditambahkan. Role lain pada akun ini tidak berubah.';
                break;

            case 'revoke_role':
                $service->revokeRole($userId, (string) ($_POST['role'] ?? ''), $aktorId);
                $message = 'Hak akses dicabut. Role lain pada akun ini tidak berubah, dan pencabutan berlaku pada pemeriksaan server berikutnya.';
                break;

            case 'status':
                $service->setActive($userId, ($_POST['is_active'] ?? '') === '1', $aktorId);
                $message = 'Status akun berhasil diperbarui.';
                break;

            case 'reset_password':
                $temporaryPassword = $service->resetPassword($userId, $aktorId);
                $message = 'Password sementara berhasil dibuat.';
                break;

            default:
                throw new InvalidArgumentException('Aksi tidak dikenal.');
        }

        master_flash(
            'success',
            $message,
            $temporaryPassword === null ? null : '<hr><p class="mb-1"><strong>Password sementara (ditampilkan sekali):</strong></p>'
                . '<code class="user-select-all fs-5">' . master_e($temporaryPassword) . '</code>'
                . '<p class="small mb-0 mt-2">Sampaikan secara aman. Pengguna wajib menggantinya saat login pertama.</p>'
        );
    } catch (Throwable $exception) {
        $formKind = $action === 'create_guru' ? 'guru' : (($action === 'create' && in_array($_POST['kind'] ?? '', ['pengurus','orang_tua'], true)) ? $_POST['kind'] : ($action === 'link' ? 'link' : null));
        if ($formKind !== null) { ah_validation_keep($_POST, ['guru_id','pengurus_id','wali_id','name','username','phone','email','user_id','kind','master_id'], $exception, '_account_' . $formKind); }
        master_flash('danger', $exception->getMessage());
    }

    header('Location: ' . app_url('/admin/' . $kembali));
    exit;
}

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'role' => (string) ($_GET['role'] ?? ''),
    'status' => (string) ($_GET['status'] ?? ''),
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$daftar = $service->list($filters, $page);

$guruTersedia = $service->availableTeachers();
$pengurusTersedia = $perizinan->availablePengurus();
$waliTersedia = $perizinan->availableWali();
$akunBelumTerhubung = $perizinan->unlinkedAccounts();

$tabs = [];
foreach ([
    '' => 'Semua akun',
    'admin' => 'Admin',
    'guru' => 'Guru',
    'pengurus' => 'Pengurus',
    'orang_tua' => 'Orang Tua',
    'tanpa_role' => 'Tanpa role',
] as $nilai => $label) {
    $tabs[] = [
        'label' => $label,
        'url' => 'admin_akun.php?' . ah_query(['role' => $nilai, 'page' => null]),
        'active' => $filters['role'] === $nilai,
    ];
}

master_header('Akun & Hak Akses', [
    'description' => 'Satu pusat untuk seluruh akun sistem: guru, admin, pengurus, dan orang tua. '
        . 'Setiap hak akses ditambahkan dan dicabut satu per satu, tanpa menghapus role lain.',
    'active' => 'sistem.akun',
    'tabs' => $tabs,
    'breadcrumbs' => [
        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
        ['label' => 'Akun & Sistem'],
        ['label' => 'Akun & Hak Akses'],
    ],
    'actions' => '<a class="btn btn-primary" href="#ah-buat-akun">Buat akun baru</a>',
]);
?>

<?php ah_note(
    'info',
    'Satu akun dapat memegang lebih dari satu peran sekaligus.',
    '<ul class="small mb-0 mt-2">'
        . '<li>Role <strong>Guru</strong>, <strong>Pengurus</strong>, dan <strong>Orang Tua</strong> menuntut hubungan ke data master yang valid dan aktif. Tanpa itu, penetapan ditolak server.</li>'
        . '<li><strong>Murobi bukan role.</strong> Ia adalah kemampuan yang muncul dari penugasan murobi aktif pada akun ber-role Guru.</li>'
        . '<li>Pencabutan hak berlaku pada pemeriksaan server berikutnya; sesi lama tidak mempertahankan hak yang sudah dicabut.</li>'
        . '</ul>'
); ?>

<form method="get" class="ah-card ah-no-print">
    <input type="hidden" name="role" value="<?= master_e($filters['role']) ?>">
    <div class="ah-card__body">
        <fieldset class="ah-fieldset mb-0">
            <legend>Cari akun</legend>
            <div class="row g-2 align-items-end">
                <div class="col-md-6"><label class="form-label" for="q">Pencarian</label>
                    <input class="form-control" id="q" name="q" value="<?= master_e($filters['q']) ?>"
                           placeholder="Nama akun, username, email, atau nama guru/pengurus/wali"></div>
                <div class="col-md-3"><label class="form-label" for="status">Status akun</label>
                    <select class="form-select" id="status" name="status">
                        <?php foreach (['' => 'Semua status', 'aktif' => 'Aktif', 'nonaktif' => 'Nonaktif', 'wajib_ganti_password' => 'Wajib ganti password'] as $nilai => $label): ?>
                            <option value="<?= master_e($nilai) ?>" <?= $filters['status'] === $nilai ? 'selected' : '' ?>><?= master_e($label) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-md-3 d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">Terapkan</button>
                    <a class="btn btn-outline-secondary" href="admin_akun.php">Bersihkan filter</a></div>
            </div>
        </fieldset>
    </div>
</form>

<section class="ah-card" aria-labelledby="ah-daftar-akun">
    <div class="ah-card__head"><span id="ah-daftar-akun">Daftar akun</span>
        <span class="text-muted small"><?= count($daftar['rows']) ?> dari <?= (int) $daftar['total'] ?> akun</span></div>
    <?php if ($daftar['rows'] === []): ?>
        <div class="ah-card__body"><?= ah_empty('Tidak ada akun yang sesuai filter', 'Ubah kata kunci, tab peran, atau status akun di atas.') ?></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table">
            <caption class="ah-visually-hidden">Daftar akun beserta role aktual dan identitas master yang terhubung</caption>
            <thead><tr>
                <th scope="col">Akun</th><th scope="col">Identitas master</th><th scope="col">Hak akses aktual</th>
                <th scope="col">Status</th><th scope="col">Login terakhir</th><th scope="col">Tindakan</th>
            </tr></thead>
            <tbody>
            <?php foreach ($daftar['rows'] as $akun):
                $id = (int) $akun['id'];
                $roles = $service->roles($akun);
                $milikSendiri = $id === $aktorId;
                ?>
                <tr>
                    <td>
                        <strong><?= master_e($akun['name']) ?></strong>
                        <span class="ah-cell-sub">@<?= master_e($akun['username']) ?></span>
                        <?php if ($akun['force_password_change']): ?><?= ah_badge('Wajib ganti password', 'warn') ?><?php endif; ?>
                        <?php if ($milikSendiri): ?><?= ah_badge('Akun Anda', 'info') ?><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($akun['guru_id']): ?>
                            <div>Guru: <?= master_e($akun['nama_guru'] ?? '—') ?></div>
                        <?php endif; ?>
                        <?php if ($akun['pengurus_id']): ?>
                            <div>Pengurus: <?= master_e($akun['pengurus_nama'] ?? '—') ?><span class="ah-cell-sub"><?= master_e($akun['pengurus_jabatan'] ?? '') ?></span></div>
                        <?php endif; ?>
                        <?php if ($akun['wali_id']): ?>
                            <div>Wali: <?= master_e($akun['wali_nama'] ?? '—') ?><span class="ah-cell-sub"><?= (int) $akun['jumlah_santri'] ?> santri terhubung</span></div>
                        <?php endif; ?>
                        <?php if (!$akun['guru_id'] && !$akun['pengurus_id'] && !$akun['wali_id']): ?>
                            <span class="text-muted">Belum terhubung ke data master</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex flex-wrap gap-1 mb-2">
                            <?php if ($roles === []): ?>
                                <?= ah_badge('Tanpa role', 'muted') ?>
                            <?php else: ?>
                                <?php foreach ($roles as $role): ?>
                                    <?= ah_badge($service->label($role), $role === 'admin' ? 'danger' : 'ok') ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ((int) $akun['murobi_aktif'] > 0 && in_array('guru', $roles, true)): ?>
                                <?= ah_badge('Murobi (kemampuan, ' . (int) $akun['murobi_aktif'] . ' penugasan)', 'info') ?>
                            <?php endif; ?>
                        </div>
                        <div class="ah-actions">
                            <?php foreach (AccountRepository::ROLES as $role):
                                $punya = in_array($role, $roles, true);
                                $penghalang = $punya ? null : $service->blockerFor($role, $akun);
                                ?>
                                <?php if ($punya): ?>
                                    <form method="post" onsubmit="return confirm('Cabut hak <?= master_e($service->label($role)) ?> dari akun <?= master_e($akun['username']) ?>? Role lain pada akun ini tetap. Pencabutan berlaku pada pemeriksaan server berikutnya.')">
                                        <?= master_csrf() ?>
                                        <input type="hidden" name="action" value="revoke_role">
                                        <input type="hidden" name="user_id" value="<?= $id ?>">
                                        <input type="hidden" name="role" value="<?= master_e($role) ?>">
                                        <button class="btn btn-sm btn-outline-danger"
                                            <?= $role === 'admin' && $milikSendiri ? 'disabled aria-disabled="true"' : '' ?>>
                                            Cabut <?= master_e($service->label($role)) ?>
                                        </button>
                                    </form>
                                <?php elseif ($role === 'admin'): ?>
                                    <button class="btn btn-sm btn-outline-danger" type="button"
                                            data-bs-toggle="modal" data-bs-target="#adminModal"
                                            data-user-id="<?= $id ?>" data-user-name="<?= master_e($akun['name'] . ' (@' . $akun['username'] . ')') ?>">
                                        Beri Admin…
                                    </button>
                                <?php elseif ($penghalang === null): ?>
                                    <form method="post">
                                        <?= master_csrf() ?>
                                        <input type="hidden" name="action" value="grant_role">
                                        <input type="hidden" name="user_id" value="<?= $id ?>">
                                        <input type="hidden" name="role" value="<?= master_e($role) ?>">
                                        <button class="btn btn-sm btn-outline-primary">Beri <?= master_e($service->label($role)) ?></button>
                                    </form>
                                <?php else: ?>
                                    <span class="ah-badge ah-badge--muted" title="<?= master_e($penghalang) ?>">
                                        <?= master_e($service->label($role)) ?>: belum memenuhi syarat
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td><?= (int) $akun['is_active'] === 1 ? ah_badge('Aktif', 'ok') : ah_badge('Nonaktif', 'danger') ?></td>
                    <td><?= master_e($akun['last_login_at'] ?? 'Belum pernah') ?></td>
                    <td><div class="ah-actions">
                        <form method="post" onsubmit="return confirm('<?= (int) $akun['is_active'] === 1 ? 'Nonaktifkan akun ini? Pengguna tidak dapat masuk, dan seluruh perangkat push miliknya dicabut.' : 'Aktifkan kembali akun ini?' ?>')">
                            <?= master_csrf() ?>
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="user_id" value="<?= $id ?>">
                            <input type="hidden" name="is_active" value="<?= (int) $akun['is_active'] === 1 ? '0' : '1' ?>">
                            <button class="btn btn-sm btn-outline-secondary" <?= $milikSendiri ? 'disabled aria-disabled="true" title="Tidak dapat mengubah status akun sendiri"' : '' ?>>
                                <?= (int) $akun['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?>
                            </button>
                        </form>
                        <form method="post" onsubmit="return confirm('Buat password sementara baru untuk akun ini? Password lama langsung tidak berlaku dan pengguna wajib mengganti password saat login berikutnya.')">
                            <?= master_csrf() ?>
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="user_id" value="<?= $id ?>">
                            <button class="btn btn-sm btn-outline-secondary" <?= $milikSendiri ? 'disabled aria-disabled="true" title="Gunakan halaman Ganti Password"' : '' ?>>Reset password</button>
                        </form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php master_pagination((int) $daftar['total'], $page, 20); ?>

<h2 class="h5 mt-4" id="ah-buat-akun">Buat akun baru</h2>
<p class="text-muted">Sistem membuat password sementara acak dan mewajibkan penggantian saat login pertama.</p>

<section class="ah-card" aria-labelledby="ah-buat-guru">
    <div class="ah-card__head"><span id="ah-buat-guru">Akun guru</span></div>
    <div class="ah-card__body">
        <?php if ($guruTersedia === []): ?>
            <p class="text-muted mb-0">Semua guru aktif sudah memiliki akun, atau belum ada data guru yang dapat dihubungkan.</p>
        <?php else: ?>
            <form method="post" class="row g-3">
                <?= master_csrf() ?><input type="hidden" name="action" value="create_guru">
                <div class="col-md-4"><label class="form-label" for="guru_id">Data guru</label>
                    <select class="form-select" id="guru_id" name="guru_id" required>
                        <option value="">Pilih guru</option>
                        <?php foreach ($guruTersedia as $guru): ?>
                            <option value="<?= (int) $guru['id'] ?>" <?= ah_old('guru_id',null,'_account_guru') === (string)$guru['id'] ? 'selected' : '' ?>><?= master_e($guru['nama_guru'] . ($guru['nip'] ? ' — ' . $guru['nip'] : '')) ?></option>
                        <?php endforeach; ?>
                    </select><?= ah_field_error('guru_id','_account_guru') ?></div>
                <div class="col-md-3"><label class="form-label" for="guru_name">Nama akun</label>
                    <input class="form-control" id="guru_name" name="name" value="<?= ah_e(ah_old('name',null,'_account_guru')) ?>" maxlength="100" required><?= ah_field_error('name','_account_guru') ?></div>
                <div class="col-md-3"><label class="form-label" for="guru_username">Username</label>
                    <input class="form-control" id="guru_username" name="username" value="<?= ah_e(ah_old('username',null,'_account_guru')) ?>" minlength="4" maxlength="50" pattern="[a-z0-9._-]+" required
                           aria-describedby="bantuan_username_guru"><?= ah_field_error('username','_account_guru') ?>
                    <div class="form-text" id="bantuan_username_guru">Huruf kecil, angka, titik, garis bawah, atau tanda hubung.</div></div>
                <div class="col-md-2"><label class="form-label" for="guru_phone">Nomor HP</label>
                    <input class="form-control" id="guru_phone" name="phone" value="<?= ah_e(ah_old('phone',null,'_account_guru')) ?>" maxlength="20"><?= ah_field_error('phone','_account_guru') ?></div>
                <div class="col-md-4"><label class="form-label" for="guru_email">Email <span class="text-muted fw-normal">(opsional)</span></label>
                    <input class="form-control" type="email" id="guru_email" name="email" value="<?= ah_e(ah_old('email',null,'_account_guru')) ?>" maxlength="191"><?= ah_field_error('email','_account_guru') ?></div>
                <div class="col-12"><button class="btn btn-primary">Buat akun guru</button></div>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="ah-card" aria-labelledby="ah-buat-pengurus">
    <div class="ah-card__head"><span id="ah-buat-pengurus">Akun pengurus</span></div>
    <div class="ah-card__body">
        <?php if ($pengurusTersedia === []): ?>
            <p class="text-muted mb-0">Semua pengurus aktif sudah memiliki akun, atau belum ada pengurus aktif.</p>
        <?php else: ?>
            <form method="post" class="row g-3">
                <?= master_csrf() ?><input type="hidden" name="action" value="create"><input type="hidden" name="kind" value="pengurus">
                <div class="col-md-4"><label class="form-label" for="pengurus_master">Data pengurus</label>
                    <select class="form-select" id="pengurus_master" name="pengurus_id" required>
                        <option value="">Pilih pengurus</option>
                        <?php foreach ($pengurusTersedia as $opsi): ?>
                            <option value="<?= (int) $opsi['id'] ?>" <?= ah_old('pengurus_id',null,'_account_pengurus') === (string)$opsi['id'] ? 'selected' : '' ?>><?= master_e($opsi['nama'] . ' — ' . $opsi['jabatan']) ?></option>
                        <?php endforeach; ?>
                    </select><?= ah_field_error('pengurus_id','_account_pengurus') ?></div>
                <div class="col-md-3"><label class="form-label" for="pengurus_name">Nama akun</label>
                    <input class="form-control" id="pengurus_name" name="name" value="<?= ah_e(ah_old('name',null,'_account_pengurus')) ?>" maxlength="100" required><?= ah_field_error('name','_account_pengurus') ?></div>
                <div class="col-md-3"><label class="form-label" for="pengurus_username">Username</label>
                    <input class="form-control" id="pengurus_username" name="username" value="<?= ah_e(ah_old('username',null,'_account_pengurus')) ?>" minlength="4" maxlength="50" pattern="[a-z0-9._-]+" required><?= ah_field_error('username','_account_pengurus') ?></div>
                <div class="col-md-2"><label class="form-label" for="pengurus_phone">Nomor HP</label>
                    <input class="form-control" id="pengurus_phone" name="phone" value="<?= ah_e(ah_old('phone',null,'_account_pengurus')) ?>" maxlength="20"><?= ah_field_error('phone','_account_pengurus') ?></div>
                <div class="col-md-4"><label class="form-label" for="pengurus_email">Email <span class="text-muted fw-normal">(opsional)</span></label>
                    <input class="form-control" type="email" id="pengurus_email" name="email" value="<?= ah_e(ah_old('email',null,'_account_pengurus')) ?>" maxlength="191"><?= ah_field_error('email','_account_pengurus') ?></div>
                <div class="col-12"><button class="btn btn-primary">Buat akun pengurus</button></div>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="ah-card" aria-labelledby="ah-buat-ortu">
    <div class="ah-card__head"><span id="ah-buat-ortu">Akun orang tua</span></div>
    <div class="ah-card__body">
        <?php if ($waliTersedia === []): ?>
            <p class="text-muted mb-0">Belum ada wali aktif dengan relasi santri yang belum memiliki akun.</p>
        <?php else: ?>
            <form method="post" class="row g-3">
                <?= master_csrf() ?><input type="hidden" name="action" value="create"><input type="hidden" name="kind" value="orang_tua">
                <div class="col-md-4"><label class="form-label" for="wali_master">Data wali</label>
                    <select class="form-select" id="wali_master" name="wali_id" required>
                        <option value="">Pilih wali</option>
                        <?php foreach ($waliTersedia as $opsi): ?>
                            <option value="<?= (int) $opsi['id'] ?>" <?= ah_old('wali_id',null,'_account_orang_tua') === (string)$opsi['id'] ? 'selected' : '' ?>><?= master_e($opsi['nama'] . ' — ' . (int) $opsi['jumlah_santri'] . ' santri') ?></option>
                        <?php endforeach; ?>
                    </select><?= ah_field_error('wali_id','_account_orang_tua') ?></div>
                <div class="col-md-3"><label class="form-label" for="ortu_name">Nama akun</label>
                    <input class="form-control" id="ortu_name" name="name" value="<?= ah_e(ah_old('name',null,'_account_orang_tua')) ?>" maxlength="100" required><?= ah_field_error('name','_account_orang_tua') ?></div>
                <div class="col-md-3"><label class="form-label" for="ortu_username">Username</label>
                    <input class="form-control" id="ortu_username" name="username" value="<?= ah_e(ah_old('username',null,'_account_orang_tua')) ?>" minlength="4" maxlength="50" pattern="[a-z0-9._-]+" required><?= ah_field_error('username','_account_orang_tua') ?></div>
                <div class="col-md-2"><label class="form-label" for="ortu_phone">Nomor HP</label>
                    <input class="form-control" id="ortu_phone" name="phone" value="<?= ah_e(ah_old('phone',null,'_account_orang_tua')) ?>" maxlength="20"><?= ah_field_error('phone','_account_orang_tua') ?></div>
                <div class="col-md-4"><label class="form-label" for="ortu_email">Email <span class="text-muted fw-normal">(opsional)</span></label>
                    <input class="form-control" type="email" id="ortu_email" name="email" value="<?= ah_e(ah_old('email',null,'_account_orang_tua')) ?>" maxlength="191"><?= ah_field_error('email','_account_orang_tua') ?></div>
                <div class="col-12"><button class="btn btn-primary">Buat akun orang tua</button></div>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php if ($akunBelumTerhubung !== [] && ($pengurusTersedia !== [] || $waliTersedia !== [])): ?>
<section class="ah-card" aria-labelledby="ah-hubungkan">
    <div class="ah-card__head"><span id="ah-hubungkan">Hubungkan akun yang sudah ada ke data master</span></div>
    <div class="ah-card__body">
        <p class="text-muted">Menghubungkan akun juga memberikan role terkait. Satu akun hanya boleh terhubung ke satu master pengurus atau satu master wali.</p>
        <form method="post" class="row g-3">
            <?= master_csrf() ?><input type="hidden" name="action" value="link">
            <div class="col-md-4"><label class="form-label" for="link_user">Akun</label>
                <select class="form-select" id="link_user" name="user_id" required>
                    <option value="">Pilih akun</option>
                    <?php foreach ($akunBelumTerhubung as $akun): ?>
                        <option value="<?= (int) $akun['id'] ?>" <?= ah_old('user_id',null,'_account_link') === (string)$akun['id'] ? 'selected' : '' ?>><?= master_e($akun['name'] . ' (@' . $akun['username'] . ')') ?></option>
                    <?php endforeach; ?>
                </select><?= ah_field_error('user_id','_account_link') ?></div>
            <div class="col-md-3"><label class="form-label" for="link_kind">Hubungkan sebagai</label>
                <select class="form-select" id="link_kind" name="kind" required>
                    <option value="pengurus" <?= ah_old('kind',null,'_account_link') === 'pengurus' ? 'selected' : '' ?>>Pengurus</option>
                    <option value="orang_tua" <?= ah_old('kind',null,'_account_link') === 'orang_tua' ? 'selected' : '' ?>>Orang tua / wali</option>
                </select><?= ah_field_error('kind','_account_link') ?></div>
            <div class="col-md-4"><label class="form-label" for="link_master">Data master</label>
                <select class="form-select" id="link_master" name="master_id" required>
                    <optgroup label="Pengurus">
                        <?php foreach ($pengurusTersedia as $opsi): ?>
                            <option value="<?= (int) $opsi['id'] ?>" <?= ah_old('kind',null,'_account_link') === 'pengurus' && ah_old('master_id',null,'_account_link') === (string)$opsi['id'] ? 'selected' : '' ?>><?= master_e($opsi['nama'] . ' — ' . $opsi['jabatan']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Wali">
                        <?php foreach ($waliTersedia as $opsi): ?>
                            <option value="<?= (int) $opsi['id'] ?>" <?= ah_old('kind',null,'_account_link') === 'orang_tua' && ah_old('master_id',null,'_account_link') === (string)$opsi['id'] ? 'selected' : '' ?>><?= master_e($opsi['nama'] . ' — ' . (int) $opsi['jumlah_santri'] . ' santri') ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                </select><?= ah_field_error('master_id','_account_link') ?>
                <div class="form-text">Pilih data master yang sesuai dengan jenis hubungan di sebelah kiri.</div></div>
            <div class="col-12"><button class="btn btn-primary">Hubungkan</button></div>
        </form>
    </div>
</section>
<?php endif; ?>

<div class="modal fade" id="adminModal" tabindex="-1" aria-labelledby="adminModalLabel" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="adminModalLabel">Beri hak Admin</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <?= master_csrf() ?>
                <input type="hidden" name="action" value="grant_role">
                <input type="hidden" name="role" value="admin">
                <input type="hidden" name="user_id" id="adminModalUserId" value="">
                <div class="ah-danger-zone mb-3">
                    <p class="mb-1"><strong>Dampak tindakan ini</strong></p>
                    <ul class="small mb-0">
                        <li>Akun <strong id="adminModalUserName">—</strong> memperoleh akses penuh ke seluruh data pesantren.</li>
                        <li>Ia dapat mengelola akun lain, termasuk mencabut hak akses admin lain.</li>
                        <li>Role lain pada akun tersebut tetap dipertahankan.</li>
                        <li>Tindakan ini tercatat pada audit beserta pelakunya.</li>
                    </ul>
                </div>
                <label class="form-label" for="konfirmasi_admin">Ketik <code><?= master_e(AccountService::KONFIRMASI_ADMIN) ?></code> untuk melanjutkan</label>
                <input class="form-control" id="konfirmasi_admin" name="konfirmasi_admin" autocomplete="off" required><?= ah_field_error('konfirmasi_admin','_account_guru') ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button class="btn btn-danger">Beri hak Admin</button>
            </div>
        </form>
    </div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('adminModal');
    if (!modal) { return; }
    modal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger) { return; }
        document.getElementById('adminModalUserId').value = trigger.getAttribute('data-user-id') || '';
        document.getElementById('adminModalUserName').textContent = trigger.getAttribute('data-user-name') || '—';
        document.getElementById('konfirmasi_admin').value = '';
    });
});
</script>
<?php foreach (['guru','pengurus','orang_tua','link'] as $kind) { ah_old_clear('_account_' . $kind); } master_footer(); ?>
