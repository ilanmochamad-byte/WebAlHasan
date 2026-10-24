<?php

declare(strict_types=1);

/** Probe independen Codex Fase 3. Hanya fixture sintetis test_*_test.
 * V5F3_DB=test_codex_v5_f3_probe_test V5_RUN_AUDIT=1 php tests/v5_phase3_audit.php
 * Provider selalu palsu; tidak mengirim jaringan atau membuka produksi.
 */
if (PHP_SAPI !== 'cli' || getenv('V5_RUN_AUDIT') !== '1'
    || !preg_match('/^test_.*_test$/D', getenv('V5F3_DB') ?: '')) exit(2);
putenv('DB_NAME=' . getenv('V5F3_DB'));
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Kesehatan\KesehatanException;
use App\Kesehatan\RingkasanService;
use App\Notification\{DeviceRepository, NotificationDispatcher, OutboxRepository, PushTokenProtector, SettingsRepository, WorkerLock};
use App\Notification\WhatsApp\{ProviderResult, WhatsAppMessage, WhatsAppProvider};

$db = app_db();
$sql = static function (string $q) use ($db): mixed {
    $r = $db->query($q);
    if ($r === false) throw new RuntimeException($db->error);
    return $r;
};
$val = static fn (string $q): mixed => $sql($q)->fetch_row()[0];
$user = static fn (string $name): array => ['id' => (int) $val("SELECT id FROM users WHERE username='$name'"), 'roles' => []];
$petugas = $user('sbx_v5_petugas_f');
$wali1 = $user('sbx_v5_wali_1');
$wali2 = $user('sbx_v5_wali_2');
$lain = $user('sbx_v5_wali_lain');
$s1 = (int) $val("SELECT id FROM santri WHERE nis='V5S0201'");
$s2 = (int) $val("SELECT id FROM santri WHERE nis='V5S0202'");
if (!$petugas['id'] || !$s1) throw new RuntimeException('Bangun fixture Fase 3 terlebih dahulu');
$svc = kesehatan_ringkasan_service();
$klinik = kesehatan_service();
$ok = $fail = 0;
$check = static function (bool $pass, string $label) use (&$ok, &$fail): void {
    $pass ? $ok++ : $fail++;
    echo ($pass ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
};
$attempt = static function (callable $fn): int {
    try { $fn(); return 200; } catch (KesehatanException $e) { return $e->status; }
};
$key = static fn (): string => 'codex-f3-' . bin2hex(random_bytes(8));
$body = ['waktu_kunjungan' => date('Y-m-d H:i:s', strtotime('-2 hours')), 'keluhan' => 'AUDIT-PRIVAT-KELUHAN',
    'catatan_klinis' => 'AUDIT-PRIVAT-CATATAN', 'obat' => [['nama_obat' => 'AUDIT-PRIVAT-OBAT', 'jumlah' => '2']],
    'rujukan' => [['tujuan' => 'AUDIT-PRIVAT-RUJUKAN']]];
$buat = static fn (int $sid): int => $klinik->buat($petugas, $body + ['santri_id' => $sid, 'idempotency_key' => $key()], 'api')['id'];
$isi = static fn (int $revisi = 0): array => ['kunjungan_versi' => 1, 'revisi_ringkasan' => $revisi,
    'ringkasan' => 'AUDIT-RINGKASAN-WALI: Ananda telah diperiksa di klinik.',
    'tindakan_umum' => 'Beristirahat di klinik', 'alasan' => $revisi ? 'AUDIT-ALASAN-PRIVAT pembaruan' : null];
$publish = static function (int $kid, array $input) use ($svc, $petugas, $key): array {
    $p = $svc->pratinjau($petugas, $kid, $input);
    return $svc->terbitkan($petugas, $kid, $input + ['konfirmasi' => true, 'pratinjau_hash' => $p['pratinjau_hash'], 'idempotency_key' => $key()], 'api');
};
$counts = static fn (): array => array_map(static fn (string $t): int => (int) $val("SELECT COUNT(*) FROM $t"),
    ['kesehatan_ringkasan', 'kesehatan_ringkasan_versi', 'kesehatan_ringkasan_peristiwa', 'kesehatan_ringkasan_penerima', 'notifikasi_outbox', 'kesehatan_idempotensi', 'audit_logs']);
$settings = new SettingsRepository($db);
$initial = $settings->current();
$push = new class implements App\Notification\Push\PushClient, App\Notification\Push\PushReceiptClient {
    public int $calls = 0;
    public int $receipts = 0;
    public array $messages = [];
    public ?Closure $afterSend = null;
    public function send(array $messages): array {
        $this->calls++;
        $this->messages = array_merge($this->messages, $messages);
        if ($this->afterSend) ($this->afterSend)();
        return ['ok' => true, 'tickets' => array_map(static fn (): array => ['status' => 'ok', 'id' => 'fake-' . bin2hex(random_bytes(6))], $messages),
            'kode' => 'OK', 'pesan' => 'Palsu', 'permanen' => false];
    }
    public function getReceipts(array $ids): array {
        $this->receipts++;
        return ['ok' => true, 'receipts' => array_fill_keys($ids, ['status' => 'ok']), 'kode' => 'OK', 'pesan' => 'Palsu', 'permanen' => false];
    }
};
$wa = new class implements WhatsAppProvider {
    public int $calls = 0;
    public function name(): string { return 'audit-fake'; }
    public function mengirimNyata(): bool { return false; }
    public function readiness(): array { $this->calls++; return ['siap' => true, 'pesan' => 'Palsu', 'detail' => []]; }
    public function verify(): ProviderResult { $this->calls++; return ProviderResult::ok(); }
    public function send(WhatsAppMessage $m): ProviderResult { $this->calls++; return ProviderResult::ok(); }
};
$devices = new DeviceRepository($db);
$protector = new PushTokenProtector(base64_encode(str_repeat('a', 32)));
$dispatcher = new NotificationDispatcher($db, new OutboxRepository($db), $devices, $protector, $push, $wa, $settings, new WorkerLock($db));
$queue = static function (int $sid) use ($publish, $buat, $isi): array { return $publish($buat($sid), $isi()); };
$out = static fn (int $rid): string => "event_key LIKE 'v5:kesehatan:ringkasan:$rid:%' AND kanal='Push'";
$pending = $sql("SELECT id, tersedia_pada FROM notifikasi_outbox WHERE kanal='Push' AND status IN ('Queued','Failed')")->fetch_all(MYSQLI_ASSOC);
$sql("UPDATE notifikasi_outbox SET tersedia_pada=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE kanal='Push' AND status IN ('Queued','Failed')");
try {
    $settings->setPushEnabled(false, $user('sbx_admin')['id']);
    $kid = $buat($s1);
    $check($svc->daftarWali($wali1, [])['total'] === 0 && $attempt(static fn () => $svc->detailWali($wali1, 99999999, 'api')) === 403, 'Sebelum terbit: daftar kosong dan ID tebakan ditolak');
    $before = $counts();
    $preview = $svc->pratinjau($petugas, $kid, $isi());
    $check($counts() === $before && !str_contains(json_encode($preview), 'AUDIT-PRIVAT'), 'Pratinjau tidak menulis dan tidak menyalin catatan klinis');
    $published = $publish($kid, $isi());
    $rid = $published['ringkasan_id'];
    $check($svc->detailWali($wali1, $rid, 'web') === $svc->detailWali($wali2, $rid, 'api'), 'Dua wali sah memperoleh proyeksi ringkasan yang sama');
    $check($attempt(static fn () => $svc->detailWali($lain, $rid, 'api')) === 403 && $svc->daftarWali($lain, ['santri_id' => $s1])['total'] === 0, 'Wali anak lain: detail/filter tidak membuka ringkasan');
    foreach (["UPDATE wali SET is_active=0 WHERE id=(SELECT wali_id FROM users WHERE id={$wali2['id']})" => "UPDATE wali SET is_active=1 WHERE id=(SELECT wali_id FROM users WHERE id={$wali2['id']})",
        "UPDATE wali SET archived_at=NOW() WHERE id=(SELECT wali_id FROM users WHERE id={$wali2['id']})" => "UPDATE wali SET archived_at=NULL WHERE id=(SELECT wali_id FROM users WHERE id={$wali2['id']})",
        "DELETE FROM user_roles WHERE user_id={$wali2['id']} AND role_id=(SELECT id FROM roles WHERE slug='orang_tua')" => "INSERT INTO user_roles (user_id,role_id) SELECT {$wali2['id']},id FROM roles WHERE slug='orang_tua'"] as $change => $restore) {
        $sql($change);
        try { $check($attempt(static fn () => $svc->detailWali($wali2, $rid, 'api')) === 403, 'Objek sesi lama: master/arsip/role wali dicabut menutup detail'); }
        finally { $sql($restore); }
    }
    $sql("UPDATE santri_wali SET archived_at=NOW() WHERE santri_id=$s1 AND wali_id=(SELECT wali_id FROM users WHERE id={$wali2['id']})");
    $check($attempt(static fn () => $svc->detailWali($wali2, $rid, 'web')) === 403 && $svc->daftarWali($wali2, [])['total'] === 0, 'Relasi dicabut: objek lama kehilangan daftar dan detail, wali pertama tetap berhak');
    $check($svc->detailWali($wali1, $rid, 'api')['status'] === 'Terbit', 'Pencabutan wali kedua tidak menutup wali pertama');
    $sql("UPDATE santri_wali SET archived_at=NULL WHERE santri_id=$s1 AND wali_id=(SELECT wali_id FROM users WHERE id={$wali2['id']})");
    $input = $isi(1);
    $input['kunjungan_versi'] = 2;
    $klinik->koreksi($petugas, $kid, $body + ['versi' => 1, 'alasan' => 'Koreksi internal setelah terbit', 'idempotency_key' => $key()], 'api');
    $check($svc->detailWali($wali1, $rid, 'api')['ringkasan'] === $isi()['ringkasan'], 'Koreksi internal tidak mengubah snapshot ringkasan terbit');
    $input['alasan'] = '';
    $check($attempt(static fn () => $publish($kid, $input)) === 422, 'Penggantian tanpa alasan ditolak');
    $input['alasan'] = 'AUDIT-ALASAN-PRIVAT perbaikan';
    $input['ringkasan'] = 'AUDIT-RINGKASAN-BARU: Informasi untuk wali diperbarui.';
    $new = $publish($kid, $input);
    $check($new['versi'] === 2 && $svc->detailWali($wali1, $rid, 'api')['ringkasan'] === $input['ringkasan'], 'Penggantian membuat versi 2 yang dibaca wali');
    $check((string) $val("SELECT ringkasan FROM kesehatan_ringkasan_versi WHERE ringkasan_id=$rid AND versi=1") === $isi()['ringkasan'], 'Teks snapshot versi pertama tetap utuh');
    $check($attempt(static fn () => $svc->tarik($petugas, $kid, ['revisi_ringkasan'=>2, 'alasan'=>'', 'idempotency_key'=>$key()], 'api')) === 422, 'Penarikan tanpa alasan ditolak');
    $svc->tarik($petugas, $kid, ['revisi_ringkasan'=>2, 'alasan'=>'AUDIT-ALASAN-PRIVAT tarik', 'idempotency_key'=>$key()], 'api');
    $withdrawn = $svc->detailWali($wali1, $rid, 'api');
    $check($withdrawn['status'] === 'Ditarik' && $withdrawn['ringkasan'] === null && $withdrawn['tindakan_umum'] === null && $withdrawn['tindak_lanjut_wali'] === null, 'Ringkasan ditarik tidak mengandung isi atau alasan');
    $check((int) $val("SELECT COUNT(*) FROM notifikasi_outbox WHERE event_key LIKE 'v5:kesehatan:ringkasan:$rid:%'") === 6, 'Terbit dan ganti masing-masing sekali per tiga wali; tarik tidak menambah pesan');

    foreach ([$wali1, $wali2, $lain] as $u) {
        $token = 'ExponentPushToken[audit-' . bin2hex(random_bytes(8)) . ']';
        $devices->register(['user_id'=>$u['id'], 'token_hash'=>$protector->hash($token), 'token_terlindungi'=>$protector->protect($token),
            'platform'=>'android', 'device_id'=>$key(), 'device_label'=>'Codex V5 F3', 'app_version'=>'uji']);
    }
    $settings->setPushEnabled(true, $user('sbx_admin')['id']);
    foreach (["is_active=0" => "is_active=1", "archived_at=NOW()" => "archived_at=NULL"] as $change => $restore) {
        $q = $queue($s1);
        $calls = $push->calls;
        $sql("UPDATE santri SET $change WHERE id=$s1");
        try {
            $check($attempt(static fn () => $svc->detailWali($wali1, $q['ringkasan_id'], 'api')) === 403, 'Santri nonaktif/arsip: tujuan ringkasan ditolak');
            $dispatcher->run('Push', 100);
            $check($push->calls === $calls && (int) $val('SELECT COUNT(*) FROM notifikasi_outbox WHERE ' . $out($q['ringkasan_id']) . " AND error_kode='AKSES_BERUBAH' AND gagal_permanen=1") === 2, 'Santri nonaktif/arsip sebelum worker: nol request provider, antrean gagal permanen');
        } finally { $sql("UPDATE santri SET $restore WHERE id=$s1"); }
    }
    $q = $queue($s1);
    $calls = $push->calls;
    $push->afterSend = static function () use ($sql): void { $sql('UPDATE pengaturan_notifikasi SET push_enabled=0 WHERE singleton=1'); };
    $dispatcher->run('Push', 100);
    $push->afterSend = null;
    $check($push->calls === $calls + 1, 'OFF sesudah kiriman pertama: tidak ada request provider berikutnya dalam batch');
    $queued = (int) $val('SELECT COUNT(*) FROM notifikasi_outbox WHERE ' . $out($q['ringkasan_id']) . " AND status='Queued' AND percobaan=0 AND locked_by IS NULL");
    $check($queued === 1, 'Sisa batch saat OFF dilepas tanpa percobaan agar dapat dilanjutkan');
    $calls = $push->calls;
    $receipts = $push->receipts;
    $dispatcher->run('Push', 100);
    $dispatcher->reconcileReceipts(100, 0);
    $dispatcher->run('WhatsApp', 100);
    $check($push->calls === $calls && $push->receipts === $receipts && $wa->calls === 0, 'OFF dengan antrean/tiket nyata sintetis: nol send, receipt dan request WhatsApp');
    $settings->setPushEnabled(true, $user('sbx_admin')['id']);
    $dispatcher->run('Push', 100);
    $dispatcher->run('Push', 100);
    $check($push->calls === $calls + 1 && (int) $val('SELECT COUNT(*) FROM notifikasi_outbox WHERE ' . $out($q['ringkasan_id']) . " AND status='Sent' AND percobaan=1") === 2, 'ON kembali: hanya sisa batch dikirim sekali, pesan pertama tidak diulang');
    $private = ['AUDIT-PRIVAT', 'AUDIT-RINGKASAN', 'AUDIT-ALASAN', 'SBX V5 Wali Santri', '081200005301'];
    $texts = json_encode($push->messages) . json_encode(notification_center_service()->index($wali1, ['jenis'=>'kesehatan']))
        . json_encode($sql("SELECT action,before_json,after_json FROM audit_logs WHERE action LIKE 'kesehatan.ringkasan.%'")->fetch_all(MYSQLI_ASSOC))
        . json_encode($sql("SELECT event_key,judul,isi,data_json,error_terakhir FROM notifikasi_outbox WHERE event_type LIKE 'v5%'")->fetch_all(MYSQLI_ASSOC));
    $check(!array_filter($private, static fn (string $p): bool => str_contains($texts, $p)), 'Payload provider, pusat notifikasi, outbox dan log umum: nol isi/nama/telepon/alasan');
    $check(count($push->messages) > 0 && !array_filter($push->messages, static fn (array $m): bool => array_keys($m['data']) !== ['tipe','ringkasan_id']), 'Seluruh payload Push hanya dua kunci penunjuk aman');
} finally {
    $sql('UPDATE pengaturan_notifikasi SET push_enabled=' . (int) $initial['push_enabled'] . ' WHERE singleton=1');
    $sql("UPDATE perangkat_push SET push_aktif=0, dicabut_pada=NOW() WHERE device_label='Codex V5 F3'");
    foreach ($pending as $p) {
        $s = $db->prepare('UPDATE notifikasi_outbox SET tersedia_pada=? WHERE id=?');
        $s->bind_param('si', $p['tersedia_pada'], $p['id']); $s->execute(); $s->close();
    }
}
echo "Lulus: $ok · Gagal: $fail\n";
exit($fail ? 1 : 0);
