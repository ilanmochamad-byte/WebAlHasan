<?php

declare(strict_types=1);

/**
 * Uji integrasi PRD V7 Fase 3 — notifikasi tahap, worker Push, laporan lintas
 * asal, dan audit — di atas DB uji `tests/v7_phase3_db.sh` (fixture sintetis).
 * Klien Push dan penyedia WhatsApp PALSU menghitung permintaan; tidak ada
 * jaringan, perangkat fisik, atau penyedia nyata.
 *
 *   bash tests/v7_phase3_db.sh && php tests/v7_phase3_integration.php
 *
 *   N-1  pengajuan masuk → pembimbing sah / admin; penetapan → pembimbing baru
 *   N-2  minta perbaikan, perbaikan, tolak, teruskan → penerima per peristiwa
 *   N-3  jalur V2 atas baris wali: penetapan murobi, keputusan, koreksi
 *   N-4  pembatalan → pemegang antrean saat itu
 *   N-5  replay/retry: nol baris tambahan; satu baris per peristiwa/kanal/penerima
 *   N-6  Push ON: satu Push per penerima berperangkat; worker palsu; retry provider
 *   N-7  pemeriksaan ulang worker: penugasan/relasi dicabut, keadaan berubah, sakelar
 *   N-8  Push OFF / WhatsApp OFF (dan WhatsApp ON) → nol request provider V7
 *   N-9  antrean gagal tidak membatalkan transaksi izin (web V7 dan V2)
 *   N-10 pusat notifikasi dan tautan: pencabutan pada sesi lama
 *   N-11 laporan: kartu = daftar = ekspor = rincian yang dapat dibuka, per peran
 *   N-12 audit/riwayat lengkap dan privasi outbox/audit/laporan
 *   N-13 konkurensi: klik ganda dan balapan batal vs teruskan → notifikasi tunggal
 *   N-14 verifier Fase 2 dan Fase 3
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$dbName = getenv('V7F3_DB') ?: 'test_v7_f3_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once $root . '/app/bootstrap.php';

use App\Izin\IzinException;
use App\Izin\IzinWaliNotifikasi as N;
use App\Notification\DeviceRepository;
use App\Notification\NotificationDispatcher;
use App\Notification\OutboxRepository;
use App\Notification\PushTokenProtector;
use App\Notification\RecipientResolver;
use App\Notification\SettingsRepository;
use App\Notification\WhatsApp\ProviderResult;
use App\Notification\WhatsApp\WhatsAppMessage;
use App\Notification\WhatsApp\WhatsAppProvider;
use App\Notification\WorkerLock;

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$db = app_db();
$satu = static function (string $sql) use ($db): mixed {
    $r = $db->query($sql);
    if ($r === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }

    return $r->fetch_row()[0] ?? null;
};
$semua = static fn (string $sql): array => $db->query($sql)->fetch_all(MYSQLI_ASSOC);
$jalankan = static function (string $sql) use ($db): void {
    if ($db->query($sql) === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
    \App\Database\Schema::forget();
};
$akun = static function (string $username) use ($satu, $db): array {
    $id = (int) $satu("SELECT id FROM users WHERE username = '$username'");
    $guru = $satu("SELECT guru_id FROM users WHERE id = $id");
    $roles = array_column($db->query("SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = $id")->fetch_all(MYSQLI_ASSOC), 'slug');

    return ['id' => $id, 'roles' => $roles, 'guru_id' => $guru === null ? null : (int) $guru];
};
$sid = static fn (string $nis): int => (int) $satu("SELECT id FROM santri WHERE nis = '$nis'");
$galat = static function (callable $f): ?int {
    try {
        $f();

        return null;
    } catch (IzinException $e) {
        return $e->status();
    }
};
$PENANDA = 'RAHASIA-V7F3';
$meta = ['ip' => '127.0.0.1', 'user_agent' => 'uji-v7-f3'];
$svc = static fn () => izin_wali_service();
$v2 = static fn () => izin_workflow_service();
$versi = static fn (int $id): int => (int) $satu("SELECT version FROM izin_pengajuan WHERE id = $id");
$hitung = static fn (string $tabel, string $where): int => (int) $satu("SELECT COUNT(*) FROM $tabel WHERE $where");
$isi = static fn (int $santri, string $dari, string $sampai, string $alasan = 'Acara keluarga', ?string $catatan = null): array =>
    ['santri_id' => $santri, 'tgl_izin' => $dari, 'tgl_kembali' => $sampai, 'alasan' => $PENANDA . '-ALASAN ' . $alasan, 'catatan' => $catatan];
$kunciNo = 0;
$kunci = static function (string $awal) use (&$kunciNo): string {
    $kunciNo++;

    return 'v7f3-' . $awal . '-' . str_pad((string) $kunciNo, 4, '0', STR_PAD_LEFT);
};
/** Penerima (urut) baris kanal tertentu untuk satu peristiwa pengajuan. */
$penerima = static function (int $id, string $event, string $kanal = 'InApp') use ($semua): array {
    $ids = array_map('intval', array_column($semua("SELECT penerima_user_id FROM notifikasi_outbox
        WHERE event_key LIKE 'v7:izin:$id:%' AND event_type = '$event' AND kanal = '$kanal' ORDER BY penerima_user_id"), 'penerima_user_id'));

    return $ids;
};
$v7Baris = static fn (int $id): int => (int) $satu("SELECT COUNT(*) FROM notifikasi_outbox WHERE event_key LIKE 'v7:izin:$id:%'");
$urut = static function (array $a): array {
    $a = array_values(array_unique(array_map('intval', $a)));
    sort($a);

    return $a;
};

$wA = $akun('sbx_v7_wali_a');
$wB = $akun('sbx_v7_wali_b');
$wAsing = $akun('sbx_ortu_b');
$pb1 = $akun('sbx_v7_pembimbing_satu');
$pb2 = $akun('sbx_v7_pembimbing_dua');
$admin = $akun('sbx_admin');
$murobiA = $akun('sbx_murobi_a');
$murobiB = $akun('sbx_murobi_b');
$pengurusV2 = $akun('sbx_pengurus_a');
$sSatu = $sid('V7S0001');
$sNol = $sid('V7S0002');
$sDua = $sid('V7S0003');
$sCadangan = $sid('V7S0008');
$sTanpaMurobi = $sid('V7S0006');
$resolver = new RecipientResolver($db);
$pb1Pengurus = (int) $satu("SELECT pengurus_id FROM users WHERE id = {$pb1['id']}");
$pb2Pengurus = (int) $satu("SELECT pengurus_id FROM users WHERE id = {$pb2['id']}");
$akunPb1 = $urut($resolver->pengurus($pb1Pengurus));
$admins = $urut($resolver->admin());
$akunMurobiA = $urut($resolver->murobi((int) $murobiA['guru_id']));
$akunMurobiB = $urut($resolver->murobi((int) $murobiB['guru_id']));
$assert(min($wA['id'], $wB['id'], $pb1['id'], $admin['id'], $sSatu, $sNol, $sDua, $sCadangan, $sTanpaMurobi) > 0
    && in_array($pb1['id'], $akunPb1, true) && count($admins) >= 1 && $akunMurobiA !== [] && $akunMurobiB !== []
    && $hitung('notifikasi_outbox', "event_key LIKE 'v7:%'") === 0,
    'Fixture V7 tersedia: ' . count($admins) . ' admin aktif, akun pembimbing satu ' . json_encode($akunPb1) . ', belum ada notifikasi V7');

// Klien Push dan penyedia WhatsApp palsu: tidak ada jaringan.
$push = new class implements App\Notification\Push\PushClient, App\Notification\Push\PushReceiptClient {
    public array $messages = [];
    public int $calls = 0;
    public bool $fail = false;

    public function send(array $messages): array
    {
        $this->calls++;
        $this->messages = array_merge($this->messages, $messages);

        return $this->fail
            ? ['ok' => false, 'tickets' => [], 'kode' => 'FAKE_RETRY', 'pesan' => 'Gangguan uji sementara', 'permanen' => false]
            : ['ok' => true, 'tickets' => array_map(static fn () => ['status' => 'ok', 'id' => 'v7f3-' . bin2hex(random_bytes(6))], $messages), 'kode' => 'OK', 'pesan' => 'Palsu', 'permanen' => false];
    }

    public function getReceipts(array $ids): array
    {
        return ['ok' => true, 'receipts' => array_fill_keys($ids, ['status' => 'ok']), 'kode' => 'OK', 'pesan' => 'Palsu', 'permanen' => false];
    }
};
$wa = new class implements WhatsAppProvider {
    public int $calls = 0;

    public function name(): string
    {
        return 'uji';
    }

    public function mengirimNyata(): bool
    {
        return false;
    }

    public function readiness(): array
    {
        return ['siap' => true, 'pesan' => 'Palsu', 'detail' => []];
    }

    public function verify(): ProviderResult
    {
        $this->calls++;

        return ProviderResult::ok();
    }

    public function send(WhatsAppMessage $m): ProviderResult
    {
        $this->calls++;

        return ProviderResult::ok();
    }
};
$settings = new SettingsRepository($db);
$devices = new DeviceRepository($db);
$protector = new PushTokenProtector(base64_encode(str_repeat('w', 32)));
$dispatcher = new NotificationDispatcher($db, new OutboxRepository($db), $devices, $protector, $push, $wa, $settings, new WorkerLock($db));
$awalSetelan = $semua('SELECT push_enabled, whatsapp_enabled, whatsapp_check_status FROM pengaturan_notifikasi WHERE singleton = 1')[0];
$jalankan('UPDATE pengaturan_notifikasi SET push_enabled = 0, whatsapp_enabled = 0 WHERE singleton = 1');
// Antrean fixture lain ditunda agar worker hanya memproses baris suite ini.
$jalankan("UPDATE notifikasi_outbox SET tersedia_pada = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE kanal <> 'InApp' AND status IN ('Queued', 'Failed') AND gagal_permanen = 0");
$token = [];
$perangkat = static function (array $user) use ($devices, $protector, &$token): void {
    $t = 'ExponentPushToken[v7f3-' . bin2hex(random_bytes(10)) . ']';
    $token[] = $t;
    $devices->register(['user_id' => $user['id'], 'token_hash' => $protector->hash($t), 'token_terlindungi' => $protector->protect($t),
        'platform' => 'android', 'device_id' => 'v7f3-' . bin2hex(random_bytes(5)), 'device_label' => 'Fixture V7 Fase 3', 'app_version' => 'uji']);
};
$paSatu = (int) $satu("SELECT id FROM pembimbing_assignments WHERE pengurus_id = $pb1Pengurus AND kamar_id = (SELECT id FROM kamar WHERE nama_kamar = 'SBX V7 Kamar Satu') AND is_active = 1 LIMIT 1");
$relasiA = static fn (int $santri): int => (int) $satu("SELECT sw.id FROM santri_wali sw JOIN users u ON u.wali_id = sw.wali_id WHERE u.id = {$wA['id']} AND sw.santri_id = $santri AND sw.archived_at IS NULL");
$tgl = static fn (int $n): string => date('Y-m-d', strtotime('2027-09-01 +' . (2 * $n) . ' days'));
$buat = static fn (int $santri, int $n, ?string $k = null): array => $svc()->buat($wA, $isi($santri, $tgl($n), $tgl($n)), $k ?? $kunci('buat'), $meta);

try {
    // ================================================================= N-1
    echo PHP_EOL . '=== N-1. Pengajuan masuk dan penetapan pembimbing ===' . PHP_EOL;
    $k1 = $kunci('buat');
    $h1 = $buat($sSatu, 1, $k1);
    $id1 = (int) $h1['id'];
    $assert($penerima($id1, N::MASUK_PEMBIMBING) === $akunPb1 && $v7Baris($id1) === count($akunPb1),
        'Tepat satu pembimbing sah: satu InApp per akun pembimbing (' . count($akunPb1) . '); nol untuk wali pengaju, wali lain, admin, murobi');
    $o1 = $semua("SELECT event_key, pengajuan_id, data_json, judul, isi, status FROM notifikasi_outbox WHERE event_key LIKE 'v7:izin:$id1:%'")[0];
    $assert($o1['event_key'] === "v7:izin:$id1:masuk_pembimbing:v1" && $o1['pengajuan_id'] === null && $o1['status'] === 'Sent'
        && json_decode($o1['data_json'], true) === ['tipe' => 'izin_wali', 'pengajuan_id' => $id1],
        'Kunci berversi, pengajuan_id NULL, data hanya {tipe, pengajuan_id}, InApp langsung tersedia');
    $assert($hitung('notifikasi_outbox', "event_key LIKE 'v7:izin:$id1:%' AND kanal <> 'InApp'") === 0, 'Push OFF: nol baris Push; WhatsApp tidak pernah diantrekan');
    $svc()->buat($wA, $isi($sSatu, $tgl(1), $tgl(1)), $k1, $meta);
    $assert($v7Baris($id1) === count($akunPb1), 'Kirim ulang kunci sama (replay) tidak menambah notifikasi');
    $assert($hitung('audit_logs', "action = 'izin_wali_notifikasi_diantrekan' AND entity_id = $id1") === 1, 'Audit antrean notifikasi tercatat satu kali (metadata)');

    $idNol = (int) $buat($sNol, 2)['id'];
    $assert($penerima($idNol, N::PERLU_PENETAPAN_PEMBIMBING) === $admins && $penerima($idNol, N::MASUK_PEMBIMBING) === [],
        'Nol kandidat pembimbing: satu InApp per admin aktif, nol untuk pembimbing/murobi');
    $idDua = (int) $buat($sDua, 3)['id'];
    $assert($penerima($idDua, N::PERLU_PENETAPAN_PEMBIMBING) === $admins, 'Dua kandidat pembimbing: antrean admin diberi tahu');
    $svc()->tetapkanPembimbing($admin, $idDua, $pb2Pengurus, 'Penetapan uji Fase 3', 1, $kunci('tetap'), $meta);
    $assert($penerima($idDua, N::MASUK_PEMBIMBING) === $urut($resolver->pengurus($pb2Pengurus))
        && $hitung('notifikasi_outbox', "event_key = 'v7:izin:$idDua:masuk_pembimbing:v2'") === count($resolver->pengurus($pb2Pengurus)),
        'Penetapan admin → pembimbing yang ditetapkan diberi tahu (kunci versi 2)');

    // ================================================================= N-2
    echo PHP_EOL . '=== N-2. Tinjauan pembimbing dan perbaikan wali ===' . PHP_EOL;
    $svc()->tinjau($pb1, $id1, 'minta_perbaikan', $PENANDA . '-CATATAN lengkapi', 1, $kunci('mp'), $meta);
    $assert($penerima($id1, N::PERLU_PERBAIKAN) === [$wA['id']], 'Minta perbaikan → hanya wali pengaju (wali kedua berelasi tidak diberi tahu)');
    $svc()->perbaiki($wA, $id1, ['tgl_izin' => $tgl(1), 'tgl_kembali' => $tgl(1), 'alasan' => $PENANDA . '-ALASAN perbaikan', 'catatan' => null], 2, $kunci('pb'), $meta);
    $assert($hitung('notifikasi_outbox', "event_key = 'v7:izin:$id1:masuk_pembimbing:v3' AND kanal = 'InApp'") === count($akunPb1),
        'Perbaikan dikirim → pembimbing diberi tahu lagi (peristiwa baru, versi 3)');
    $svc()->tinjau($pb1, $id1, 'teruskan', null, 3, $kunci('tr'), $meta);
    $assert($penerima($id1, N::DITERUSKAN) === [$wA['id']] && $penerima($id1, N::MASUK_MUROBI) === $akunMurobiA,
        'Teruskan (murobi tunggal) → wali pengaju dan murobi tujuan; tanpa admin');

    $idTolak = (int) $buat($sSatu, 4)['id'];
    $svc()->tinjau($pb1, $idTolak, 'tolak', $PENANDA . '-TOLAK alasan', 1, $kunci('tl'), $meta);
    $assert($penerima($idTolak, N::DITOLAK_PEMBIMBING) === [$wA['id']], 'Tolak pembimbing → hanya wali pengaju');

    $idTm = (int) $buat($sTanpaMurobi, 5)['id'];
    $svc()->tinjau($pb1, $idTm, 'teruskan', null, 1, $kunci('tr'), $meta);
    $assert($penerima($idTm, N::DITERUSKAN) === [$wA['id']] && $penerima($idTm, N::PERLU_PENETAPAN_MUROBI) === $admins
        && $penerima($idTm, N::MASUK_MUROBI) === [], 'Teruskan (murobi nol) → wali pengaju dan admin routing; nol murobi');

    // ================================================================= N-3
    echo PHP_EOL . '=== N-3. Jalur V2 atas baris wali ===' . PHP_EOL;
    require_once __DIR__ . '/v7_phase5_fixture.php';
    $f51Assignment = v7f5_fixture_murobi($db, (int) $murobiB['guru_id'], (int) $sTanpaMurobi);
    $v2()->assignMurobi($admin, $idTm, (int) $murobiB['guru_id'], 'Penetapan murobi uji', 2, $kunci('am'), $meta);
    $assert($penerima($idTm, N::MASUK_MUROBI) === $akunMurobiB, 'Penetapan murobi V2 → murobi yang ditetapkan diberi tahu (V7)');
    $v2()->decide($murobiB, $idTm, 'Disetujui', $PENANDA . '-KEPUTUSAN alasan', null, 3, $kunci('pt'), $meta, 'murobi');
    $assert($penerima($idTm, N::DIPUTUS) === [$wA['id']], 'Keputusan murobi → hanya wali pengaju');
    $v2()->correctDecision($admin, $idTm, 'Ditolak', $PENANDA . '-KOREKSI baru', 'Koreksi uji', 4, $kunci('kr'), $meta);
    $assert($penerima($idTm, N::DIKOREKSI) === [$wA['id']], 'Koreksi keputusan → hanya wali pengaju');
    $v2()->decide($murobiA, $id1, 'Ditolak', $PENANDA . '-KEPUTUSAN dua', null, 4, $kunci('pt'), $meta, 'murobi');
    $assert($penerima($id1, N::DIPUTUS) === [$wA['id']], 'Santri dengan dua wali berelasi: keputusan hanya ke wali pengaju, wali kedua tidak');
    $assert($hitung('notifikasi_outbox o JOIN izin_pengajuan p ON p.id = o.pengajuan_id', "p.asal_pengajuan = 'Wali' AND o.event_type LIKE 'izin.%' AND o.event_type NOT LIKE 'izin.wali%'") === 0,
        'Tidak ada notifikasi V2 (ke seluruh wali, tautan V2) untuk baris asal wali');

    // ================================================================= N-4
    echo PHP_EOL . '=== N-4. Pembatalan wali → pemegang antrean ===' . PHP_EOL;
    $idB1 = (int) $buat($sSatu, 6)['id'];
    $svc()->batalkan($wA, $idB1, 'Batal uji satu', 1, $kunci('bt'), $meta);
    $assert($penerima($idB1, N::DIBATALKAN) === $akunPb1, 'Batal saat menunggu pembimbing → pembimbing');
    $idB2 = (int) $buat($sCadangan, 7)['id'];
    $svc()->tinjau($pb1, $idB2, 'teruskan', null, 1, $kunci('tr'), $meta);
    $svc()->batalkan($wA, $idB2, 'Batal uji dua', 2, $kunci('bt'), $meta);
    $assert($penerima($idB2, N::DIBATALKAN) === $akunMurobiA, 'Batal sesudah diteruskan → murobi tujuan');
    $svc()->batalkan($wA, $idNol, 'Batal uji tiga', 1, $kunci('bt'), $meta);
    $assert($penerima($idNol, N::DIBATALKAN) === $admins, 'Batal saat perlu penetapan pembimbing → admin');

    // ================================================================= N-5
    echo PHP_EOL . '=== N-5. Replay dan deduplikasi ===' . PHP_EOL;
    $sebelum = $hitung('notifikasi_outbox', "event_key LIKE 'v7:%'");
    $kr = $kunci('tr');
    $idR = (int) $buat($sCadangan, 8)['id'];
    $svc()->tinjau($pb1, $idR, 'teruskan', null, 1, $kr, $meta);
    $setelahSekali = $hitung('notifikasi_outbox', "event_key LIKE 'v7:%'");
    $svc()->tinjau($pb1, $idR, 'teruskan', null, 1, $kr, $meta);
    $assert($galat(fn () => $svc()->tinjau($pb1, $idR, 'teruskan', null, 1, $kunci('tr'), $meta)) === 409
        && $hitung('notifikasi_outbox', "event_key LIKE 'v7:%'") === $setelahSekali, 'Replay dan kunci baru versi lama (409) tidak menambah notifikasi');
    (new N($db))->antrekan(N::DITERUSKAN, $idR, $pb1['id']);
    $assert($hitung('notifikasi_outbox', "event_key LIKE 'v7:%'") === $setelahSekali, 'Mengantre ulang peristiwa yang sama: kunci unik menolak baris kedua');
    $assert($setelahSekali > $sebelum && (int) $satu("SELECT COUNT(*) FROM (SELECT 1 FROM notifikasi_outbox WHERE event_key LIKE 'v7:%' GROUP BY event_key, kanal, penerima_user_id HAVING COUNT(*) > 1) d") === 0,
        'Satu baris per peristiwa, kanal, dan penerima');

    // ================================================================= N-6
    echo PHP_EOL . '=== N-6. Push ON dan worker palsu ===' . PHP_EOL;
    $jalankan('UPDATE pengaturan_notifikasi SET push_enabled = 1 WHERE singleton = 1');
    foreach ([$pb1, $wA, $murobiA] as $u) {
        $perangkat($u);
    }
    $idP = (int) $buat($sCadangan, 9)['id'];
    $assert($penerima($idP, N::MASUK_PEMBIMBING, 'Push') === [$pb1['id']], 'Push ON: satu Push untuk pembimbing berperangkat saja');
    $push->messages = [];
    $push->calls = 0;
    $r0 = $dispatcher->run('Push', 50);
    $svc()->tinjau($pb1, $idP, 'teruskan', null, 1, $kunci('tr'), $meta);
    $assert($penerima($idP, N::DITERUSKAN, 'Push') === [$wA['id']] && $penerima($idP, N::MASUK_MUROBI, 'Push') === [$murobiA['id']],
        'Push untuk wali pengaju dan murobi berperangkat; paling banyak satu per penerima');
    $r1 = $dispatcher->run('Push', 50);
    $r1['terkirim'] += $r0['terkirim'];
    $kirimV7 = array_values(array_filter($push->messages, static fn ($m) => ($m['data']['tipe'] ?? '') === 'izin_wali'));
    $assert($r1['terkirim'] === 3 && count($kirimV7) === 3, 'Worker mengirim tiga Push V7 lewat klien palsu (bukan perangkat fisik) ' . json_encode($r1));
    $assert(array_filter($kirimV7, static fn ($m) => array_keys($m['data']) !== ['tipe', 'pengajuan_id'] || $m['data']['pengajuan_id'] !== $idP) === [],
        'Payload Push hanya {tipe: izin_wali, pengajuan_id}');
    $r2 = $dispatcher->run('Push', 50);
    $assert($r2['terkirim'] === 0 && count($push->messages) === 3, 'Putaran worker kedua tidak mengirim ulang (nol duplikat)');
    // Retry provider: gagal sementara, lalu berhasil tepat sekali.
    $idQ = (int) $buat($sCadangan, 10)['id'];
    $push->fail = true;
    $dispatcher->run('Push', 50);
    $baris = $semua("SELECT id, status, percobaan FROM notifikasi_outbox WHERE event_key LIKE 'v7:izin:$idQ:%' AND kanal = 'Push'")[0];
    $push->fail = false;
    $jalankan('UPDATE notifikasi_outbox SET tersedia_pada = NOW() WHERE id = ' . (int) $baris['id']);
    $dispatcher->run('Push', 50);
    $dispatcher->run('Push', 50);
    $baris2 = $semua('SELECT status, percobaan FROM notifikasi_outbox WHERE id = ' . (int) $baris['id'])[0];
    $kirimQ = array_filter($push->messages, static fn ($m) => ($m['data']['pengajuan_id'] ?? 0) === $idQ);
    $assert($baris['status'] === 'Failed' && $baris2['status'] === 'Sent' && count($kirimQ) === 2,
        'Gagal provider sementara → dicoba ulang lalu terkirim; satu baris outbox, dua upaya, satu sukses');

    // ================================================================= N-7
    echo PHP_EOL . '=== N-7. Pemeriksaan ulang tepat sebelum provider ===' . PHP_EOL;
    $sebelumKirim = count($push->messages);
    $idC = (int) $buat($sCadangan, 11)['id'];
    $jalankan("UPDATE pembimbing_assignments SET is_active = 0 WHERE id = $paSatu");
    $dispatcher->run('Push', 50);
    $kode = $satu("SELECT error_kode FROM notifikasi_outbox WHERE event_key = 'v7:izin:$idC:masuk_pembimbing:v1' AND kanal = 'Push'");
    $assert($kode === 'AKSES_BERUBAH' && count($push->messages) === $sebelumKirim, 'Penugasan pembimbing dicabut sesudah antre → AKSES_BERUBAH, nol request provider');
    $jalankan("UPDATE pembimbing_assignments SET is_active = 1 WHERE id = $paSatu");
    $idD = (int) $buat($sCadangan, 12)['id'];
    $svc()->tinjau($pb1, $idD, 'minta_perbaikan', 'Lengkapi uji', 1, $kunci('mp'), $meta);
    $rel = $relasiA($sCadangan);
    $jalankan("UPDATE santri_wali SET archived_at = NOW() WHERE id = $rel");
    $sebelumKirim = count($push->messages);
    $dispatcher->run('Push', 50);
    $kodeWali = $satu("SELECT error_kode FROM notifikasi_outbox WHERE event_key = 'v7:izin:$idD:perlu_perbaikan:v2' AND kanal = 'Push'");
    $assert($kodeWali === 'AKSES_BERUBAH' && count(array_filter(array_slice($push->messages, $sebelumKirim), static fn ($m) => ($m['data']['pengajuan_id'] ?? 0) === $idD)) === 0,
        'Relasi wali dicabut sesudah antre → Push wali tidak dikirim');
    $jalankan("UPDATE santri_wali SET archived_at = NULL WHERE id = $rel");
    $idE = (int) $buat($sCadangan, 13)['id'];
    $svc()->tinjau($pb1, $idE, 'minta_perbaikan', 'Keadaan berubah uji', 1, $kunci('mp'), $meta);
    $sebelumKirim = count($push->messages);
    $dispatcher->run('Push', 50);
    $kodeBasi = $satu("SELECT error_kode FROM notifikasi_outbox WHERE event_key = 'v7:izin:$idE:masuk_pembimbing:v1' AND kanal = 'Push'");
    $kirimE = array_values(array_filter(array_slice($push->messages, $sebelumKirim), static fn ($m) => ($m['data']['pengajuan_id'] ?? 0) === $idE));
    $assert($kodeBasi === 'AKSES_BERUBAH' && count($kirimE) === 1 && $kirimE[0]['title'] === N::teks(N::PERLU_PERBAIKAN, $idE)['judul'],
        'Pembimbing sudah bertindak sebelum worker: Push "masuk" basi tidak dikirim; Push perbaikan terkini tetap dikirim');
    $idF = (int) $buat($sCadangan, 14)['id'];
    $jalankan('UPDATE pengaturan_notifikasi SET push_enabled = 0 WHERE singleton = 1');
    $panggil = $push->calls;
    $rOff = $dispatcher->run('Push', 50);
    $assert($push->calls === $panggil && $rOff['dijalankan'] === false
        && $satu("SELECT status FROM notifikasi_outbox WHERE event_key = 'v7:izin:$idF:masuk_pembimbing:v1' AND kanal = 'Push'") === 'Queued',
        'Push dimatikan sesudah antre: worker berhenti sebelum klaim, nol request provider');

    // ================================================================= N-8
    echo PHP_EOL . '=== N-8. Push OFF / WhatsApp OFF dan ON ===' . PHP_EOL;
    $panggil = $push->calls;
    $idG = (int) $buat($sCadangan, 15)['id'];
    $svc()->tinjau($pb1, $idG, 'teruskan', null, 1, $kunci('tr'), $meta);
    $v2()->decide($murobiA, $idG, 'Disetujui', 'Keputusan uji', null, 2, $kunci('pt'), $meta, 'murobi');
    $dispatcher->run('Push', 50);
    $dispatcher->run('WhatsApp', 50);
    $assert($hitung('notifikasi_outbox', "event_key LIKE 'v7:izin:$idG:%' AND kanal <> 'InApp'") === 0 && $push->calls === $panggil && $wa->calls === 0
        && $satu("SELECT status FROM izin_pengajuan WHERE id = $idG") === 'Disetujui',
        'Push OFF dan WhatsApp OFF: nol baris eksternal, nol request provider; pengajuan dan keputusan tetap berhasil');
    $jalankan("UPDATE pengaturan_notifikasi SET whatsapp_enabled = 1, whatsapp_check_status = 'Lulus' WHERE singleton = 1");
    $idH = (int) $buat($sCadangan, 16)['id'];
    $dispatcher->run('WhatsApp', 50);
    $assert($hitung('notifikasi_outbox', "event_key LIKE 'v7:izin:$idH:%' AND kanal = 'WhatsApp'") === 0 && $wa->calls === 0,
        'WhatsApp ON pun tidak mengantre notifikasi V7; worker WhatsApp ditangguhkan, nol request');
    $jalankan('UPDATE pengaturan_notifikasi SET whatsapp_enabled = 0 WHERE singleton = 1');
    $assert($hitung('notifikasi_outbox', "event_key LIKE 'v7:%' AND kanal = 'WhatsApp'") === 0, 'Seluruh suite: nol baris WhatsApp V7');

    // ================================================================= N-9
    echo PHP_EOL . '=== N-9. Antrean gagal tidak membatalkan transaksi izin ===' . PHP_EOL;
    $jalankan('RENAME TABLE notifikasi_outbox TO notifikasi_outbox_v7f3_tunda');
    try {
        $hI = $buat($sCadangan, 17);
        $idI = (int) $hI['id'];
        $tr = $svc()->tinjau($pb1, $idI, 'teruskan', null, 1, $kunci('tr'), $meta);
        $pt = $v2()->decide($murobiA, $idI, 'Disetujui', 'Keputusan saat antrean rusak', null, 2, $kunci('pt'), $meta, 'murobi');
    } finally {
        $jalankan('RENAME TABLE notifikasi_outbox_v7f3_tunda TO notifikasi_outbox');
    }
    $assert(($hI['status_http'] ?? 0) === 201 && ($tr['status'] ?? '') === 'Diajukan' && ($pt['status'] ?? '') === 'Disetujui'
        && $satu("SELECT status FROM izin_pengajuan WHERE id = $idI") === 'Disetujui' && $versi($idI) === 3,
        'Tabel outbox tidak tersedia: buat, teruskan (V7), dan keputusan (V2) tetap commit');
    $assert($hitung('izin_riwayat_status', "pengajuan_id = $idI") >= 4
        && $hitung('audit_logs', "entity_id = $idI AND action IN ('izin_wali_pengajuan_dibuat', 'izin_wali_diteruskan', 'izin_decision_recorded')") === 3
        && $hitung('audit_logs', "entity_id = $idI AND action = 'izin_wali_notifikasi_gagal'") === 4 && $v7Baris($idI) === 0,
        'Riwayat dan audit mutasi tetap ada; empat kegagalan antrean tercatat di audit tanpa isi; nol baris notifikasi');

    // ================================================================= N-10
    echo PHP_EOL . '=== N-10. Pusat notifikasi dan tautan pada sesi lama ===' . PHP_EOL;
    $idJ = (int) $buat($sCadangan, 18)['id'];
    $pusat = notification_center_service()->index($pb1, ['jenis' => 'perizinan', 'per_page' => 100]);
    $item = array_values(array_filter($pusat['items'], static fn ($i) => $i['tautan']['tipe'] === 'izin_wali' && $i['tautan']['pengajuan_id'] === $idJ));
    $assert(count($item) === 1 && $item[0]['santri_nama'] === null && $item[0]['pengajuan_id'] === null
        && $item[0]['event_label'] === 'Pengajuan wali menunggu tinjauan' && !str_contains(json_encode($item[0]), $PENANDA),
        'Pusat notifikasi (kategori Perizinan): tautan izin_wali, tanpa nama santri/alasan');
    $assert($svc()->detail($pb1, $idJ)['peran_akses'] === 'pembimbing', 'Tautan dibuka: pembimbing sah membaca rincian');
    $jalankan("UPDATE pembimbing_assignments SET is_active = 0 WHERE id = $paSatu");
    $assert($galat(fn () => $svc()->detail($pb1, $idJ)) === 404, 'Penugasan dicabut: tautan yang sama → 404 pada sesi lama');
    $jalankan("UPDATE pembimbing_assignments SET is_active = 1 WHERE id = $paSatu");
    $relS = $relasiA($sSatu);
    $jalankan("UPDATE santri_wali SET archived_at = NOW() WHERE id = $relS");
    $assert($galat(fn () => $svc()->detail($wA, $id1)) === 404, 'Relasi wali dicabut: tautan keputusan → 404');
    $jalankan("UPDATE santri_wali SET archived_at = NULL WHERE id = $relS");
    $assert($svc()->detail($wA, $id1)['peran_akses'] === 'wali', 'Relasi dipulihkan: rincian terbuka lagi');
    $assert($galat(fn () => $svc()->detail($wAsing, $idJ)) === 404 && $galat(fn () => $svc()->detail($murobiA, $idJ)) === 404,
        'Wali asing dan murobi (sebelum diteruskan) tidak dapat membuka tautan');

    // ================================================================= N-11
    echo PHP_EOL . '=== N-11. Laporan: kartu, daftar, ekspor, rincian ===' . PHP_EOL;
    $lap = static fn () => izin_laporan_service();
    $semuaHalaman = static function (array $user, array $q) use ($lap): array {
        $ids = [];
        $pertama = $lap()->laporan($user, $q + ['page' => 1]);
        $hal = (int) ceil(max(1, $pertama['total']) / $pertama['per_page']);
        for ($p = 1; $p <= $hal; $p++) {
            foreach ($lap()->laporan($user, $q + ['page' => $p])['items'] as $i) {
                $ids[] = $i['id'];
            }
        }

        return [$pertama, $ids];
    };
    $idsCsv = static function (string $csv): array {
        $baris = array_slice(array_filter(explode("\n", trim(substr($csv, 3)))), 1);

        return array_map(static fn ($b) => (int) str_getcsv($b, ',', '"', '')[0], $baris);
    };
    $bolehDetail = static function (array $user, int $id, string $mode) use ($satu, $svc): bool {
        try {
            if ($satu("SELECT asal_pengajuan FROM izin_pengajuan WHERE id = $id") === 'Wali') {
                $svc()->detail($user, $id);
            } else {
                capabilities()->forget($user['id']);
                izin_service()->detail($user, $id, $mode);
            }

            return true;
        } catch (IzinException) {
            return false;
        }
    };
    $wDicabut = $akun('sbx_v7_wali_dicabut');
    $jalankan("INSERT INTO users (username, password, name, is_active) VALUES ('sbx_v7f3_tanpa_peran', 'x', 'SBX Tanpa Peran', 1)");
    $tanpaPeran = $akun('sbx_v7f3_tanpa_peran');
    $assert($galat(fn () => $lap()->laporan($tanpaPeran, [])) === 403, 'Pengguna tanpa peran perizinan → 403 laporan');
    $harapan = [
        'admin' => [$admin, 'admin', '1 = 1'],
        'pembimbing satu' => [$pb1, 'pengurus', null],
        'pembimbing dua' => [$pb2, 'pengurus', null],
        'pengurus V2' => [$pengurusV2, 'pengurus', null],
        'murobi A' => [$murobiA, 'murobi', "p.murobi_guru_id = {$murobiA['guru_id']} AND p.status IN ('Diajukan','Perlu Penetapan Admin','Disetujui','Ditolak','Dibatalkan')"],
        'murobi B' => [$murobiB, 'murobi', "p.murobi_guru_id = {$murobiB['guru_id']} AND p.status IN ('Diajukan','Perlu Penetapan Admin','Disetujui','Ditolak','Dibatalkan')"],
        'wali pengaju A' => [$wA, 'orang_tua', null],
        'wali kedua B' => [$wB, 'orang_tua', null],
        'wali asing' => [$wAsing, 'orang_tua', null],
        'wali relasi dicabut' => [$wDicabut, 'orang_tua', null],
    ];
    $kandidat = array_map('intval', array_column($semua("SELECT id FROM izin_pengajuan WHERE asal_pengajuan = 'Wali'
        UNION SELECT id FROM (SELECT id FROM izin_pengajuan WHERE asal_pengajuan = 'Pengurus' ORDER BY id DESC LIMIT 60) a
        UNION SELECT id FROM izin_pengajuan WHERE murobi_guru_id IN ({$murobiA['guru_id']}, {$murobiB['guru_id']})
        UNION SELECT id FROM izin_pengajuan WHERE pengurus_id = (SELECT pengurus_id FROM users WHERE id = {$pengurusV2['id']})
        UNION SELECT p.id FROM izin_pengajuan p JOIN santri_wali sw ON sw.santri_id = p.santri_id JOIN users u ON u.wali_id = sw.wali_id
              WHERE u.id IN ({$wA['id']}, {$wB['id']}, {$wAsing['id']})"), 'id'));
    foreach ($harapan as $label => [$user, $mode, $sqlHarapan]) {
        [$pertama, $idsDaftar] = $semuaHalaman($user, []);
        $ekspor = $lap()->ekspor($user, []);
        $idsEkspor = $idsCsv($ekspor['csv']);
        $k = $pertama['ringkasan'];
        $sumTahap = array_sum($k['per_tahap']);
        $sumHasil = array_sum($k['per_hasil']);
        $sumAsal = array_sum($k['per_asal']);
        sort($idsDaftar);
        sort($idsEkspor);
        $assert($pertama['peran'] === $mode && $k['total'] === count($idsDaftar) && $idsDaftar === $idsEkspor && count(array_unique($idsDaftar)) === count($idsDaftar)
            && $sumTahap === $k['total'] && $sumHasil === $k['total'] && $sumAsal === $k['total'] && $ekspor['jumlah'] === $k['total'],
            "Laporan $label: kartu {$k['total']} = daftar " . count($idsDaftar) . ' = ekspor ' . count($idsEkspor) . ' (tanpa ganda; tahap/hasil/asal berjumlah sama)');
        if ($sqlHarapan !== null) {
            $sqlIds = array_map('intval', array_column($semua("SELECT p.id FROM izin_pengajuan p WHERE $sqlHarapan ORDER BY p.id"), 'id'));
            $assert($sqlIds === $idsDaftar, "Laporan $label sama dengan hitungan SQL independen (" . count($sqlIds) . ')');
        }
        $diLaporan = array_flip($idsDaftar);
        $cek = $mode === 'admin' ? array_slice($kandidat, 0, 80) : $kandidat;
        $salah = [];
        foreach ($cek as $id) {
            if ($bolehDetail($user, $id, $mode) !== isset($diLaporan[$id])) {
                $salah[] = $id;
            }
        }
        $assert($salah === [], "Laporan $label: baris yang tampil = rincian yang dapat dibuka (" . count($cek) . ' ID diuji)' . ($salah === [] ? '' : ' beda: ' . implode(',', array_slice($salah, 0, 10))));
        $teks = json_encode($pertama, JSON_UNESCAPED_UNICODE) . $ekspor['csv'];
        $assert(!str_contains($teks, $PENANDA) && !str_contains($teks, '"alasan"'), "Laporan $label: tanpa alasan izin/catatan/alasan keputusan");
    }
    $nWali = $hitung('izin_pengajuan', "asal_pengajuan = 'Wali'");
    $nPengurus = $hitung('izin_pengajuan', "asal_pengajuan = 'Pengurus'");
    $kAdmin = $lap()->laporan($admin, [])['ringkasan'];
    $assert($kAdmin['per_asal'] === ['Wali' => $nWali, 'Pengurus' => $nPengurus] && $kAdmin['total'] === $nWali + $nPengurus,
        "Admin: pengajuan wali ($nWali) dan pengurus ($nPengurus) masing-masing terhitung tepat sekali");
    $saringan = [
        [['asal' => 'wali'], "asal_pengajuan = 'Wali'"],
        [['asal' => 'pengurus'], "asal_pengajuan = 'Pengurus'"],
        [['tahap' => 'pembimbing'], "status = 'Menunggu Pembimbing'"],
        [['tahap' => 'perbaikan_wali'], "status = 'Perlu Perbaikan Wali'"],
        [['hasil' => 'ditolak_pembimbing'], "status = 'Ditolak Pembimbing'"],
        [['hasil' => 'dibatalkan', 'asal' => 'wali'], "status = 'Dibatalkan' AND asal_pengajuan = 'Wali'"],
        [['dari' => '2027-09-01', 'sampai' => '2027-09-20'], "tgl_kembali >= '2027-09-01' AND tgl_izin <= '2027-09-20'"],
        [['santri_id' => (string) $sCadangan, 'asal' => 'wali'], "santri_id = $sCadangan AND asal_pengajuan = 'Wali'"],
        [['murobi_id' => (string) $murobiA['guru_id']], "murobi_guru_id = {$murobiA['guru_id']}"],
        [['pembimbing_id' => (string) $pb1Pengurus], "pembimbing_pengurus_id = $pb1Pengurus"],
    ];
    foreach ($saringan as [$q, $where]) {
        $assert($lap()->laporan($admin, $q)['total'] === $hitung('izin_pengajuan', $where), 'Saringan ' . json_encode($q) . ' = SQL independen');
    }
    $lamaNol = $lap()->laporan($admin, ['asal' => 'wali', 'durasi_maks_jam' => '1'])['total'];
    $assert($lamaNol === $hitung('izin_pengajuan', "asal_pengajuan = 'Wali' AND status IN ('Disetujui','Ditolak','Ditolak Pembimbing','Dibatalkan')"),
        'Saringan durasi: pengajuan wali yang selesai dalam satu jam (fixture) terhitung tepat');
    $assert($galat(fn () => $lap()->laporan($admin, ['tahap' => 'ngawur'])) === 422 && $galat(fn () => $lap()->laporan($admin, ['dari' => '2027-01-01'])) === 422
        && $galat(fn () => $lap()->laporan($admin, ['santri_id' => '1 OR 1=1'])) === 422, 'Saringan tidak valid → 422');
    $lB = $lap()->laporan($wB, []);
    $milikA = array_values(array_filter($lB['items'], static fn ($i) => $i['asal'] === 'Wali'));
    $assert($milikA !== [] && array_filter($milikA, static fn ($i) => $i['pengaju'] !== 'Wali lain') === []
        && !isset($milikA[0]['pembimbing']) && !isset($milikA[0]['lama_jam']), 'Wali kedua: pengajuan wali A tampil ringkas "Wali lain" tanpa nama petugas/durasi');
    $assert($lap()->laporan($wA, ['peran' => 'admin'])['peran'] === 'orang_tua', 'Parameter peran tidak memperluas cakupan (wali meminta admin → tetap orang tua)');
    $jalankan("UPDATE pembimbing_assignments SET is_active = 0 WHERE id = $paSatu");
    $tanpaPenugasan = $lap()->laporan($pb1, ['asal' => 'wali', 'santri_id' => (string) $sCadangan])['total'];
    $jalankan("UPDATE pembimbing_assignments SET is_active = 1 WHERE id = $paSatu");
    $assert($tanpaPenugasan === 0 && $lap()->laporan($pb1, ['asal' => 'wali', 'santri_id' => (string) $sCadangan])['total'] > 0,
        'Penugasan dicabut: laporan pembimbing pada sesi lama kehilangan baris santri itu; pulih sesudah dikembalikan');
    $assert($hitung('audit_logs', "action = 'izin_v7_laporan_ekspor'") === count($harapan)
        && !str_contains((string) $satu("SELECT GROUP_CONCAT(after_json) FROM audit_logs WHERE action = 'izin_v7_laporan_ekspor'"), 'SBX V7 Santri'),
        'Setiap ekspor diaudit (peran, saringan, jumlah) tanpa isi');

    // ================================================================= N-12
    echo PHP_EOL . '=== N-12. Audit, riwayat, dan privasi ===' . PHP_EOL;
    $tanpaPelaku = $hitung('izin_riwayat_status r JOIN izin_pengajuan p ON p.id = r.pengajuan_id', "p.asal_pengajuan = 'Wali' AND (r.pelaku_user_id IS NULL OR r.created_at IS NULL)");
    $assert($tanpaPelaku === 0, 'Setiap riwayat pengajuan wali memiliki pelaku dan waktu');
    $auditMutasi = ['izin_wali_pengajuan_dibuat', 'izin_wali_pembimbing_ditetapkan', 'izin_wali_diminta_perbaikan', 'izin_wali_ditolak_pembimbing',
        'izin_wali_diteruskan', 'izin_wali_perbaikan_dikirim', 'izin_wali_dibatalkan', 'izin_murobi_assigned', 'izin_decision_recorded', 'izin_decision_corrected'];
    $tanpaAudit = (int) $satu("SELECT COUNT(*) FROM izin_pengajuan p WHERE p.asal_pengajuan = 'Wali'
        AND (SELECT COUNT(*) FROM audit_logs a WHERE a.entity_type = 'izin_pengajuan' AND a.entity_id = p.id AND a.actor_user_id IS NOT NULL
             AND a.action IN ('" . implode("','", $auditMutasi) . "')) <> p.version");
    $assert($tanpaAudit === 0, 'Setiap kenaikan versi pengajuan wali punya tepat satu audit mutasi berpelaku (audit = versi)');
    $assert($hitung('izin_pembimbing_tinjauan', 'versi_sesudah <> versi_sebelum + 1 OR ditinjau_oleh_user_id IS NULL') === 0
        && $hitung('izin_wali_perbaikan', 'versi_sesudah <> versi_sebelum + 1') === 0 && $hitung('izin_pembimbing_penetapan', "alasan IS NULL OR alasan = ''") === 0,
        'Tinjauan/perbaikan/penetapan mencatat versi sebelum/sesudah, pelaku, dan alasan wajib');
    $outbox = (string) $satu("SELECT GROUP_CONCAT(CONCAT_WS('|', judul, isi, data_json) SEPARATOR '\n') FROM notifikasi_outbox WHERE event_key LIKE 'v7:%'");
    $nama = array_column($semua("SELECT DISTINCT s.nama_santri FROM izin_pengajuan p JOIN santri s ON s.id = p.santri_id WHERE p.asal_pengajuan = 'Wali'"), 'nama_santri');
    $hp = array_filter(array_column($semua('SELECT no_hp FROM wali UNION SELECT no_hp FROM pengurus'), 'no_hp'));
    $bocor = static function (string $teks) use ($PENANDA, $nama, $hp, &$token): array {
        $temuan = [];
        foreach (array_merge([$PENANDA, 'ExponentPushToken', 'Bearer '], $nama, $hp, $token) as $p) {
            if ($p !== '' && str_contains($teks, (string) $p)) {
                $temuan[] = $p;
            }
        }

        return $temuan;
    };
    $assert($bocor($outbox) === [] && $outbox !== '', 'Outbox V7 (judul/isi/data): nol alasan, nama santri, nomor telepon, token');
    // Medan `to` adalah token tujuan yang memang wajib bagi provider; yang dipindai judul, isi, dan data.
    $tanpaTujuan = array_map(static fn ($m) => array_diff_key($m, ['to' => true]), $push->messages);
    $assert($bocor(json_encode($tanpaTujuan, JSON_UNESCAPED_UNICODE)) === [] && $push->messages !== [], 'Payload Push yang dikirim ke provider palsu (judul/isi/data): nol data terlarang');
    $audit = (string) $satu("SELECT GROUP_CONCAT(CONCAT_WS('|', before_json, after_json) SEPARATOR '\n') FROM audit_logs WHERE action LIKE 'izin_wali%' OR action LIKE 'izin_v7%'");
    $assert($bocor($audit) === [], 'Audit V7 (mutasi, notifikasi, ekspor): nol alasan, nama santri, nomor telepon, token');
    $percobaan = (string) $satu("SELECT GROUP_CONCAT(CONCAT_WS('|', c.error_kode, c.error_pesan) SEPARATOR '\n') FROM notifikasi_percobaan c JOIN notifikasi_outbox o ON o.id = c.outbox_id WHERE o.event_key LIKE 'v7:%'");
    $assert($bocor($percobaan) === [], 'Catatan percobaan pengiriman V7: tanpa data terlarang');

    // ================================================================= N-13
    echo PHP_EOL . '=== N-13. Konkurensi proses terpisah ===' . PHP_EOL;
    $paralel = static function (array $tugas, array $tunda = []) use ($root, $dbName): array {
        $mulai = (int) floor(microtime(true) * 1000) + 1500;
        $proc = [];
        $pipes = [];
        foreach ($tugas as $i => $t) {
            $cmd = array_merge([PHP_BINARY, $root . '/tests/v7_phase2_worker.php'], array_map('strval', array_slice($t, 0, 5)), [(string) ($mulai + (int) ($tunda[$t[0]] ?? 0))], array_map('strval', array_slice($t, 5)));
            $proc[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], $root, array_merge(getenv(), ['DB_NAME' => $dbName]));
        }
        $hasil = [];
        foreach ($proc as $i => $p) {
            $out = stream_get_contents($pipes[$i][1]);
            $err = stream_get_contents($pipes[$i][2]);
            proc_close($p);
            $hasil[$i] = json_decode(trim((string) $out), true) ?? ['ok' => false, 'http' => 0, 'aksi' => '?', 'pesan' => trim((string) $out . $err)];
        }

        return $hasil;
    };
    $ringkas = static fn (array $hasil): string => json_encode(array_count_values(array_map(static fn ($h) => $h['aksi'] . ':' . ($h['ok'] ? 'ok' : (string) $h['http']), $hasil)));
    $idK = (int) $buat($sCadangan, 19)['id'];
    $tugas = [];
    for ($i = 0; $i < 8; $i++) {
        $tugas[] = ['teruskan', 'sbx_v7_pembimbing_satu', $idK, 1, 'v7f3-klik-ganda'];
    }
    $hasil = $paralel($tugas);
    $assert(count(array_filter($hasil, static fn ($h) => $h['ok'])) === 8 && $penerima($idK, N::DITERUSKAN) === [$wA['id']]
        && $penerima($idK, N::MASUK_MUROBI) === $akunMurobiA && $hitung('notifikasi_outbox', "event_key = 'v7:izin:$idK:diteruskan:v2'") === 1,
        'Klik ganda teruskan 8 proses kunci sama → 8 sukses, satu set notifikasi ' . $ringkas($hasil));
    $cabang = [];
    // Penundaan bergiliran agar kedua cabang (batal menang / teruskan menang) teramati.
    $tundaPutaran = [[], ['teruskan' => 250], ['batal' => 250], ['teruskan' => 250]];
    for ($r = 0; $r < 4; $r++) {
        $idR = (int) $buat($sCadangan, 20 + $r)['id'];
        $tugas = [];
        for ($i = 0; $i < 4; $i++) {
            $tugas[] = ['batal', 'sbx_v7_wali_a', $idR, 1, "v7f3-bt-$r-$i"];
            $tugas[] = ['teruskan', 'sbx_v7_pembimbing_satu', $idR, 1, "v7f3-tr-$r-$i"];
        }
        $hasil = $paralel($tugas, $tundaPutaran[$r]);
        $sukses = array_values(array_filter($hasil, static fn ($h) => $h['ok']));
        $tanpa500 = array_filter($hasil, static fn ($h) => !$h['ok'] && !in_array($h['http'], [403, 404, 409], true)) === [];
        $nBatal = count($penerima($idR, N::DIBATALKAN));
        $nTerus = count($penerima($idR, N::DITERUSKAN));
        $cabang[] = $sukses[0]['aksi'] ?? '-';
        $assert(count($sukses) === 1 && $tanpa500 && (($nBatal > 0) xor ($nTerus > 0))
            && ($sukses[0]['aksi'] === 'batal' ? $nBatal === count($akunPb1) && $nTerus === 0 : $nTerus === 1 && $nBatal === 0),
            'Putaran ' . ($r + 1) . ' batal vs teruskan: satu pemenang, notifikasi hanya untuk hasil efektif, tanpa 500 ' . $ringkas($hasil));
    }
    $assert(in_array('batal', $cabang, true) && in_array('teruskan', $cabang, true), 'Kedua cabang balapan teramati: ' . implode(', ', $cabang));

    // ================================================================= N-14
    echo PHP_EOL . '=== N-14. Verifier ===' . PHP_EOL;
    foreach (['v7_phase2_verify.php' => 'Fase 2', 'v7_phase3_verify.php' => 'Fase 3'] as $berkas => $fase) {
        $out = [];
        exec('DB_NAME=' . escapeshellarg($dbName) . ' php ' . escapeshellarg($root . '/bin/' . $berkas) . ' 2>&1', $out, $kode);
        $assert($kode === 0, "bin/$berkas lulus sesudah seluruh uji (" . trim((string) end($out)) . ')');
        if ($kode !== 0) {
            echo implode(PHP_EOL, array_filter($out, static fn ($l) => str_starts_with($l, '[gagal]'))) . PHP_EOL;
        }
    }
} finally {
    $jalankan('UPDATE pengaturan_notifikasi SET push_enabled = ' . (int) $awalSetelan['push_enabled'] . ', whatsapp_enabled = ' . (int) $awalSetelan['whatsapp_enabled']
        . ", whatsapp_check_status = '" . $db->real_escape_string((string) $awalSetelan['whatsapp_check_status']) . "' WHERE singleton = 1");
}

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
