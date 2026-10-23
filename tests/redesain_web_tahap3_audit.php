<?php

declare(strict_types=1);

/**
 * Probe independen audit Tahap 3. Hanya DB fixture Tahap 3 dan HTTP lokal.
 * Sesudah persiapan DB + server 8951:
 * DB_NAME=test_redesain_t3_test php tests/redesain_web_tahap3_audit.php
 * Exit 1 = kriteria belum terpenuhi. Tidak menghubungi penyedia eksternal:
 * layanan worker disuntik FakeProvider; seluruh fixture layanan di-rollback.
 * Mutasi HTTP pembayaran dipulihkan di finally, termasuk audit probe saja.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Notification\NotificationAdminService;
use App\Notification\NotificationChannel;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationException;
use App\Notification\Push\PushClient;
use App\Notification\WhatsApp\FakeProvider;
use App\Notification\WorkerLock;

if (app_config('database.database') !== 'test_redesain_t3_test') {
    throw new RuntimeException('Hanya DB fixture test_redesain_t3_test.');
}
$base = getenv('BASE_URL') ?: 'http://127.0.0.1:8951';
if (!preg_match('#^http://127\.0\.0\.1:\d+$#D', $base)) {
    throw new RuntimeException('Hanya HTTP localhost.');
}
$passed = $failed = 0;
$check = static function (bool $ok, string $label, array $observed = []) use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed++;
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label
        . ($observed === [] ? '' : ' | ' . json_encode($observed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . PHP_EOL;
};
$rejected = static function (callable $run): bool {
    try {
        $run();
        return false;
    } catch (NotificationException $e) {
        return $e->status() === 409;
    }
};
$db = app_db();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = auth_repository()->findByUsername('sbx_admin') ?? throw new RuntimeException('Fixture admin tidak ada.');
$adminId = (int) $admin['id'];
$settings = notification_settings_repository();
$meta = ['ip' => null, 'user_agent' => 'audit-t3'];

// F-1: simulasi sakelar yang sudah aktif sebelum keputusan AL, dengan
// adapter palsu. Tidak ada vendor/nomor asli dan tidak ada pengiriman nyata.
$db->begin_transaction();
try {
    $fake = new FakeProvider('ok', false);
    $push = new class implements PushClient {
        public function send(array $messages): array { throw new RuntimeException('Push tidak boleh dipanggil.'); }
    };
    $dispatcher = new NotificationDispatcher($db, notification_outbox_repository(), push_device_repository(),
        push_token_protector(), $push, $fake, $settings, new WorkerLock($db));
    $service = new NotificationAdminService(capabilities(), $settings, notification_repository(),
        notification_outbox_repository(), push_device_repository(), push_token_protector(), $fake,
        notification_service(), $dispatcher, audit_logger());
    $settings->recordWhatsappCheck('Lulus', 'Fixture audit tanpa jaringan.', 'fake', $adminId);
    $check($settings->setWhatsappEnabled(true, $adminId, 'fake'), 'Fixture merepresentasikan sakelar WhatsApp lama yang aktif');
    $db->query("UPDATE users SET phone='081300000000' WHERE id=$adminId");
    $db->query("UPDATE notifikasi_outbox SET gagal_permanen=1 WHERE kanal='WhatsApp'");
    $id = notification_repository()->enqueue([
        'event_key' => 'audit-t3-' . bin2hex(random_bytes(6)), 'event_type' => 'sistem.pesan_uji',
        'kanal' => 'WhatsApp', 'penerima_user_id' => $adminId, 'judul' => 'Fixture audit',
        'isi' => 'Sintetis, hanya adapter palsu.', 'data_json' => '{}',
    ]) ?? throw new RuntimeException('Fixture outbox gagal.');
    $wa = array_values(array_filter($service->status($admin)['kanal'], static fn ($k) => $k['kanal'] === 'WhatsApp'))[0];
    $check($wa['ditangguhkan'] === true && $wa['aktif'] === true, 'Kartu tetap menyatakan Ditangguhkan pada sakelar lama aktif');
    $check($rejected(static fn () => $service->ubahSakelar($admin, 'WhatsApp', true, $meta)), 'AL sakelar baru ditolak 409');
    $check($rejected(static fn () => $service->kirimPesanUji($admin, 'WhatsApp', $meta)), 'AL pesan uji ditolak 409');
    $dry = $service->jalankanWorker($admin, 'WhatsApp', true);
    $check(count($fake->terkirim()) === 0 && $dry['terkirim'] === 0, 'Dry-run kompatibel tanpa pemanggilan send');
    $blocked = $rejected(static fn () => $service->jalankanWorker($admin, 'WhatsApp', false));
    $status = $db->query("SELECT status FROM notifikasi_outbox WHERE id=$id")->fetch_assoc()['status'];
    $check($blocked && count($fake->terkirim()) === 0 && $status === 'Queued',
        'AL worker layanan/API non-dry-run menolak kanal Ditangguhkan tanpa send/mutasi',
        ['ditolak_409' => $blocked, 'panggilan_send_fake' => count($fake->terkirim()), 'status' => $status]);
    $db->query("UPDATE notifikasi_outbox SET status='Failed', gagal_permanen=1 WHERE id=$id");
    $blocked = $rejected(static fn () => $service->cobaUlang($admin, $id, $meta));
    $status = $db->query("SELECT status FROM notifikasi_outbox WHERE id=$id")->fetch_assoc()['status'];
    $check($blocked && $status === 'Failed', 'AL percobaan ulang menolak WhatsApp Ditangguhkan meski sakelar lama aktif',
        ['ditolak_409' => $blocked, 'status' => $status]);
    $check($service->ubahSakelar($admin, 'WhatsApp', false, $meta)['aktif'] === false,
        'AL sakelar lama tetap dapat dimatikan');
} finally {
    $db->rollback();
}

final class AuditTahap3Client
{
    private string $jar;
    public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'audit-t3-'); }
    public function __destruct() { @unlink($this->jar); }
    public function req(string $path, ?array $post = null): array
    {
        $h = curl_init($this->base . $path);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 20]);
        if ($post !== null) {
            curl_setopt($h, CURLOPT_POST, true);
            curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw = curl_exec($h);
        if ($raw === false) { throw new RuntimeException(curl_error($h)); }
        $size = curl_getinfo($h, CURLINFO_HEADER_SIZE);
        $result = ['status' => curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'body' => substr($raw, $size)];
        curl_close($h);
        return $result;
    }
    public function token(string $path): string
    {
        preg_match('/name="_csrf" value="([^"]+)"/', $this->req($path)['body'], $m);
        return $m[1] ?? throw new RuntimeException('CSRF tidak ditemukan.');
    }
}
$client = new AuditTahap3Client($base);
$login = $client->req('/admin/cek_login.php', ['_csrf' => $client->token('/portal/index.php'), 'username' => 'sbx_admin', 'password' => 'Sandbox#123']);
$check($login['status'] === 302, 'HTTP login fixture admin');
$path = '/admin/admin_pembayaran_psb.php';
$token = $client->token($path);
$payment = $db->query("SELECT pb.* FROM psb_pembayaran pb JOIN psb_pendaftar p ON p.no_pendaftaran=pb.no_pendaftaran WHERE p.no_pendaftaran LIKE 'SBX-T3-%' ORDER BY pb.id LIMIT 1")->fetch_assoc()
    ?: throw new RuntimeException('Fixture pembayaran tidak ada.');
$paymentId = (int) $payment['id'];
$auditStart = (int) $db->query('SELECT COALESCE(MAX(id),0) AS n FROM audit_logs')->fetch_assoc()['n'];
$read = static fn () => $db->query("SELECT status_pembayaran,waktu_lunas FROM psb_pembayaran WHERE id=$paymentId")->fetch_assoc();
try {
    foreach (['tanpa alasan' => null, 'alasan spasi' => '   '] as $label => $reason) {
        $db->query("UPDATE psb_pembayaran SET status_pembayaran='Lunas',waktu_lunas='2026-10-23 10:00:00' WHERE id=$paymentId");
        $before = $read();
        $post = ['_csrf' => $token, 'act' => 'batal', 'id' => $paymentId];
        if ($reason !== null) { $post['alasan'] = $reason; }
        $response = $client->req($path, $post);
        $after = $read();
        $check($after === $before, 'K-6 ' . $label . ': pembatalan ditolak tanpa mengubah status/waktu lunas',
            ['http' => $response['status'], 'status_akhir' => $after['status_pembayaran']]);
    }
    $db->query("UPDATE psb_pembayaran SET status_pembayaran='Lunas',waktu_lunas='2026-10-23 10:00:00' WHERE id=$paymentId");
    $response = $client->req($path, ['_csrf' => $token, 'act' => 'batal', 'id' => $paymentId, 'alasan' => 'Fixture koreksi kuitansi audit T3']);
    $after = $read();
    $audit = $db->query("SELECT after_json FROM audit_logs WHERE action='psb.pembayaran.batal_lunas' AND entity_id=$paymentId AND id>$auditStart ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $check($response['status'] === 302 && $after['status_pembayaran'] === 'Belum Lunas' && $after['waktu_lunas'] === null
        && (json_decode($audit['after_json'] ?? '{}', true)['alasan'] ?? '') === 'Fixture koreksi kuitansi audit T3',
        'K-6 pembatalan dengan alasan sah menyimpan status dan audit');
    $response = $client->req($path, ['act' => 'batal', 'id' => $paymentId, 'alasan' => 'Tanpa CSRF']);
    $check($response['status'] === 419, 'K-6 tanpa CSRF tetap ditolak 419');
} finally {
    $stmt = $db->prepare('UPDATE psb_pembayaran SET status_pembayaran=?,waktu_lunas=? WHERE id=?');
    $stmt->bind_param('ssi', $payment['status_pembayaran'], $payment['waktu_lunas'], $paymentId);
    $stmt->execute();
    $stmt->close();
    $db->query("DELETE FROM audit_logs WHERE action='psb.pembayaran.batal_lunas' AND entity_type='psb_pembayaran' AND entity_id=$paymentId AND id>$auditStart");
}
echo "\nRingkasan: $passed lulus, $failed gagal\n";
exit($failed === 0 ? 0 : 1);
