<?php

declare(strict_types=1);

/**
 * Probe tambahan audit ulang F-1: facade REST, seluruh keadaan outbox/lock,
 * dan entry point CLI bawaan. Hanya DB fixture Tahap 3, tanpa jaringan vendor.
 * DB_NAME=test_redesain_t3_test php tests/redesain_web_tahap3_audit_ulang.php
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Api\ApiException;
use App\Api\NotificationApiService;
use App\Notification\NotificationAdminService;
use App\Notification\NotificationDispatcher;
use App\Notification\Push\PushClient;
use App\Notification\WhatsApp\FakeProvider;
use App\Notification\WorkerLock;

if (PHP_SAPI !== 'cli' || app_config('database.database') !== 'test_redesain_t3_test') {
    throw new RuntimeException('Hanya CLI dan DB fixture Tahap 3.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = app_db();
$passed = $failed = 0;
$check = static function (bool $ok, string $message) use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
};
$snapshot = static function () use ($db): array {
    $state = [];
    foreach (['notifikasi_outbox', 'notifikasi_percobaan', 'notifikasi_worker_lock', 'notifikasi_pengaturan_audit'] as $table) {
        $rows = array_map(static fn ($row) => json_encode($row, JSON_THROW_ON_ERROR), $db->query("SELECT * FROM `$table`")->fetch_all(MYSQLI_ASSOC));
        sort($rows, SORT_STRING);
        $state[$table] = $rows;
    }
    return $state;
};
$status = static function (callable $run): int {
    try { $run(); return 200; } catch (ApiException $e) { return $e->status(); }
};
$admin = auth_repository()->findByUsername('sbx_admin') ?? throw new RuntimeException('Admin tidak ada.');
$other = auth_repository()->findByUsername('sbx_pengurus_a') ?? throw new RuntimeException('Pengurus tidak ada.');
$settings = notification_settings_repository();
$beforeTransaction = $snapshot();
$db->begin_transaction();
try {
    $settings->recordWhatsappCheck('Lulus', 'Fixture auditor tanpa jaringan.', 'fake', (int) $admin['id']);
    if (!$settings->setWhatsappEnabled(true, (int) $admin['id'], 'fake')) throw new RuntimeException('Fixture sakelar aktif gagal.');
    $fake = new FakeProvider('ok', false);
    $push = new class implements PushClient {
        public function send(array $messages): array { throw new RuntimeException('Push tidak boleh dikirim probe ini.'); }
    };
    $dispatcher = new NotificationDispatcher($db, notification_outbox_repository(), push_device_repository(), push_token_protector(),
        $push, $fake, $settings, new WorkerLock($db));
    $service = new NotificationAdminService(capabilities(), $settings, notification_repository(), notification_outbox_repository(),
        push_device_repository(), push_token_protector(), $fake, notification_service(), $dispatcher, audit_logger());
    $api = new NotificationApiService(notification_center_service(), push_device_service(), $service);
    $state = $snapshot();
    $result = $dispatcher->run('WhatsApp');
    $check(!$result['dijalankan'] && str_contains((string) $result['alasan'], 'ditangguhkan') && $fake->terkirim() === [],
        'F-1 dispatcher bawaan menahan sakelar lama aktif/Lulus tanpa send');
    $check($snapshot() === $state, 'F-1 seluruh outbox, percobaan, lock, dan audit kanal utuh setelah dispatcher');
    $check($status(static fn () => $api->adminRunWorker($admin, ['kanal' => 'WhatsApp', 'uji_coba' => false])) === 409,
        'F-1 facade REST menerjemahkan penolakan worker menjadi 409');
    $id = (int) $db->query("SELECT id FROM notifikasi_outbox WHERE kanal='WhatsApp' AND status='Failed' LIMIT 1")->fetch_assoc()['id'];
    if ($id < 1) throw new RuntimeException('Fixture baris gagal tidak ada.');
    $check($status(static fn () => $api->adminRetry($admin, $id, ['ip' => null, 'user_agent' => null])) === 409,
        'F-1 facade REST menerjemahkan penolakan retry menjadi 409');
    $check($snapshot() === $state, 'F-1 penolakan facade REST mempertahankan seluruh keadaan antrean/lock/audit kanal');
    $dry = $api->adminRunWorker($admin, ['kanal' => 'WhatsApp', 'uji_coba' => true]);
    $check($dry['dijalankan'] && $dry['terkirim'] === 0 && $fake->terkirim() === [], 'F-1 facade REST tetap menyediakan dry-run tanpa send');
    $check($snapshot()['notifikasi_outbox'] === $state['notifikasi_outbox'], 'F-1 dry-run tidak mengklaim atau mengubah outbox');
    $check($status(static fn () => $api->adminRunWorker($other, ['kanal' => 'WhatsApp'])) === 403,
        'F-1 non-admin tetap ditolak 403 sebelum kebijakan kanal');
    // Uji positif lewat layanan/facade, bukan hanya requeue repositori: guard
    // penangguhan WhatsApp tidak boleh menutup percobaan ulang Push yang sah.
    $settings->setPushEnabled(true, (int) $admin['id']);
    $pushId = notification_repository()->enqueue([
        'event_key' => 'audit-ulang-t3-' . bin2hex(random_bytes(6)), 'event_type' => 'sistem.pesan_uji',
        'kanal' => 'Push', 'penerima_user_id' => (int) $admin['id'], 'judul' => 'Fixture auditor',
        'isi' => 'Tidak dikirim; hanya uji retry.', 'data_json' => '{}',
    ]) ?? throw new RuntimeException('Fixture retry Push gagal.');
    $db->query("UPDATE notifikasi_outbox SET status='Failed',gagal_permanen=1,percobaan=5 WHERE id=$pushId");
    $count = (int) $db->query('SELECT COUNT(*) AS n FROM notifikasi_outbox')->fetch_assoc()['n'];
    $audit = (int) $db->query('SELECT COUNT(*) AS n FROM notifikasi_pengaturan_audit')->fetch_assoc()['n'];
    $retry = $api->adminRetry($admin, $pushId, ['ip' => null, 'user_agent' => null]);
    $row = $db->query("SELECT status,gagal_permanen,percobaan FROM notifikasi_outbox WHERE id=$pushId")->fetch_assoc();
    $check($retry['diantrekan_ulang'] && $row['status'] === 'Queued' && (int) $row['gagal_permanen'] === 0
        && (int) $row['percobaan'] === 5 && (int) $db->query('SELECT COUNT(*) AS n FROM notifikasi_outbox')->fetch_assoc()['n'] === $count,
        'F-1 regresi positif: facade REST mengantrekan ulang baris Push yang sama tanpa duplikat');
    $check((int) $db->query('SELECT COUNT(*) AS n FROM notifikasi_pengaturan_audit')->fetch_assoc()['n'] === $audit + 1,
        'F-1 regresi positif: retry Push tetap mencatat audit kanal');
} finally {
    $db->rollback();
}
$check($snapshot() === $beforeTransaction, 'F-1 seluruh fixture layanan dipulihkan lewat rollback');

// CLI memakai wiring bootstrap produksi. Kanal saat ini tidak perlu diaktifkan:
// guard harus menjawab Ditangguhkan sebelum membaca sakelar/config penyedia.
$state = $snapshot();
$process = proc_open([PHP_BINARY, APP_ROOT . '/bin/notifikasi_worker.php', '--kanal=whatsapp'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, APP_ROOT,
    array_merge(getenv(), ['DB_NAME' => 'test_redesain_t3_test', 'WHATSAPP_PROVIDER' => '']));
if (!is_resource($process)) throw new RuntimeException('CLI worker tidak dapat dijalankan.');
fclose($pipes[0]);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$exit = proc_close($process);
$check($exit === 0 && $err === '' && str_contains($out, 'ditangguhkan'), 'F-1 entry point CLI/cron berhenti dengan Ditangguhkan dan exit 0');
$check($snapshot() === $state, 'F-1 CLI/cron tidak mengubah outbox, percobaan, lock, atau audit kanal');
echo "\nRingkasan: $passed lulus, $failed gagal\n";
exit($failed === 0 ? 0 : 1);
