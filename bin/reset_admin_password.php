<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$username = trim((string) ($argv[1] ?? 'admin'));
if ($username === '') {
    fwrite(STDERR, "Username tidak boleh kosong.\n");
    exit(1);
}

function readHiddenPassword(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $canHide = DIRECTORY_SEPARATOR !== '\\' && function_exists('shell_exec');
    if ($canHide) {
        shell_exec('stty -echo 2>/dev/null');
    }

    try {
        $value = fgets(STDIN);
    } finally {
        if ($canHide) {
            shell_exec('stty echo 2>/dev/null');
            fwrite(STDOUT, PHP_EOL);
        }
    }

    return trim((string) $value);
}

$password = readHiddenPassword("Password sementara baru untuk @{$username}: ");
$confirmation = readHiddenPassword('Ulangi password sementara: ');

if ($password !== $confirmation) {
    fwrite(STDERR, "Konfirmasi password tidak sama.\n");
    exit(1);
}
if (strlen($password) < 10) {
    fwrite(STDERR, "Password sementara minimal 10 karakter.\n");
    exit(1);
}

$db = app_db();
$db->begin_transaction();

try {
    $statement = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    if ($statement === false) {
        throw new RuntimeException('Tabel users tidak dapat dibaca.');
    }
    $statement->bind_param('s', $username);
    $statement->execute();
    $user = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!$user) {
        throw new RuntimeException("Akun @{$username} tidak ditemukan.");
    }

    $userId = (int) $user['id'];
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $statement = $db->prepare(
        'UPDATE users SET password = ?, is_active = 1, force_password_change = 1, updated_at = NOW() WHERE id = ?'
    );
    if ($statement === false) {
        throw new RuntimeException('Migrasi Fase 1 belum diterapkan: kolom force_password_change tidak tersedia.');
    }
    $statement->bind_param('si', $hash, $userId);
    if (!$statement->execute()) {
        throw new RuntimeException('Password admin gagal diperbarui.');
    }
    $statement->close();

    $role = 'admin';
    $statement = $db->prepare(
        'INSERT INTO user_roles (user_id, role_id, assigned_by)
         SELECT ?, id, NULL FROM roles WHERE slug = ?
         ON DUPLICATE KEY UPDATE assigned_at = assigned_at'
    );
    if ($statement === false) {
        throw new RuntimeException('Migrasi Fase 1 belum diterapkan: tabel role tidak tersedia.');
    }
    $statement->bind_param('is', $userId, $role);
    if (!$statement->execute()) {
        throw new RuntimeException('Role admin gagal dipastikan.');
    }
    $statement->close();

    $statement = $db->prepare(
        'SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = ? LIMIT 1'
    );
    $statement->bind_param('is', $userId, $role);
    $statement->execute();
    $hasAdminRole = (bool) $statement->get_result()->fetch_row();
    $statement->close();
    if (!$hasAdminRole) {
        throw new RuntimeException('Role admin tidak ditemukan. Pastikan migrasi Fase 1 sudah diterapkan.');
    }

    $db->commit();
    audit_logger()->log('admin_password_bootstrap_reset', 'user', $userId, null, [
        'username' => $username,
        'is_active' => true,
        'force_password_change' => true,
        'source' => 'cli',
    ]);
} catch (Throwable $exception) {
    $db->rollback();
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Password @{$username} berhasil direset. Login dengan password sementara lalu buat password baru.\n");

