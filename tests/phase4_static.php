<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) { $failures[] = $message; }
};
$source = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$contract = $source('docs/api-v1.md');
foreach (['POST /api/v1/auth/login', 'GET /api/v1/profile', 'POST /api/v1/auth/logout', 'GET /api/v1/schedules/today', 'GET /api/v1/schedules', 'POST /api/v1/schedules/{schedule_id}/meetings', 'GET /api/v1/meetings/{meeting_id}', 'PUT /api/v1/meetings/{meeting_id}/attendance', 'pagination', '401', '403', '409', '422'] as $required) {
    $assert(str_contains($contract, $required), 'Kontrak API mendokumentasikan ' . $required);
}

$migration = $source('database/migrations/004_phase4_api_attendance.sql');
$rollback = $source('database/rollbacks/004_phase4_api_attendance.sql');
foreach (['CREATE TABLE absensi_guru', 'CREATE TABLE absensi_santri', 'CREATE TABLE api_idempotency_keys', 'absensi_guru_meeting_teacher_unique', 'absensi_santri_meeting_student_unique', 'api_idempotency_user_key_unique'] as $required) {
    $assert(str_contains($migration, $required), 'Migrasi Fase 4 memuat ' . $required);
}
$assert(!preg_match('/\b(?:DELETE\s+FROM|DROP\s+(?:TABLE|COLUMN))\b/i', $migration), 'Migrasi naik Fase 4 tidak menghapus tabel, kolom, atau baris lama');
$assert(str_contains($rollback, 'HANYA untuk staging') && str_contains($rollback, 'Jangan jalankan di produksi'), 'Rollback Fase 4 memperingatkan risiko produksi');

$auth = $source('app/Api/ApiAuthService.php');
$hasher = $source('app/Auth/TokenHasher.php');
$authenticator = $source('app/Auth/ApiTokenAuthenticator.php');
$assert(str_contains($auth, 'random_bytes(32)') && str_contains($hasher, "hash_hmac('sha256'"), 'Bearer token acak dan hanya dicocokkan melalui hash HMAC');
$assert(!str_contains($authenticator, 'SELECT t.token_hash') && !str_contains($contract, 'token_hash":"'), 'Profil dan response API tidak mengembalikan hash token');
$assert(str_contains($auth, 'invalid_credentials_or_inactive') && str_contains($auth, 'INVALID_CREDENTIALS'), 'Password salah dan akun nonaktif memakai kegagalan generik');
$assert(str_contains($auth, 'revokeToken') && str_contains($authenticator, 't.revoked_at IS NULL'), 'Logout mencabut token dan autentikasi menolak token tercabut');

$service = $source('app/Api/TeacherService.php');
$repository = $source('app/Api/TeacherRepository.php');
$assert(str_contains($service, 'authorizeOwnership') && str_contains($service, "in_array('admin'"), 'Service menerapkan kepemilikan jadwal dengan pengecualian admin');
$assert(substr_count($service, 'begin_transaction()') >= 2 && substr_count($service, 'rollback()') >= 2, 'Pembukaan pertemuan dan penyimpanan absensi memakai transaksi');
$assert(str_contains($service, 'beginIdempotency') && str_contains($repository, 'api_idempotency_keys'), 'Pembukaan dan absensi memakai idempotency key persisten');
$assert(str_contains($repository, 'ON DUPLICATE KEY UPDATE') && str_contains($service, 'CORRECTION_REASON_REQUIRED'), 'Koreksi memperbarui baris yang sama dan mewajibkan alasan');
$assert(str_contains($service, '$snapshotIds !== $submittedIds'), 'Daftar absensi wajib sama persis dengan snapshot peserta');

$router = $source('api/v1/index.php');
foreach (['/auth/login', '/auth/logout', '/profile', '/schedules/today', '/meetings', '/attendance'] as $route) {
    $assert(str_contains($router, $route), 'Router API memuat route ' . $route);
}
$assert(str_contains($source('.env.example'), 'API_TOKEN_HASH_SECRET=') && str_contains($source('.env.example'), 'API_TOKEN_TTL_DAYS=30'), 'Secret token dan TTL dikonfigurasi melalui environment');

$backupWriter = $source('app/Database/BackupWriter.php');
$assert(
    str_contains($backupWriter, 'SHOW FULL COLUMNS FROM')
    && str_contains($backupWriter, "str_contains(\$extra, 'GENERATED')")
    && str_contains($backupWriter, "'INSERT INTO ' . \$escapedTable . ' (' . \$columnList . ') VALUES ('"),
    'Backup SQL mengecualikan generated column dan menulis daftar kolom eksplisit'
);

exit($failures === [] ? 0 : 1);
