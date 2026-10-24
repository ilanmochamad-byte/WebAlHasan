<?php

declare(strict_types=1);

/**
 * Uji integrasi layanan PRD V5 Fase 3 — ringkasan wali dan notifikasi — pada
 * DB uji terpisah (MariaDB/MySQL) dengan fixture sintetis. Push memakai klien
 * palsu yang menghitung permintaan; WhatsApp memakai penyedia palsu. Tidak
 * ada permintaan jaringan nyata.
 *
 *   bash tests/v5_phase3_db.sh && V5_RUN_INTEGRATION=1 php tests/v5_phase3_integration.php
 *
 *   L-1  sebelum terbit: wali kosong/403 untuk daftar, detail, ID tebakan;
 *        draf tidak dapat diterbitkan;
 *   L-2  hak: admin, admin+petugas, pembimbing, murobi, wali, pengurus tanpa
 *        penugasan ditolak pratinjau/terbit/tarik tanpa perubahan;
 *   L-3  validasi: tanpa konfirmasi, isi berbeda dari pratinjau, versi
 *        kunjungan usang → ditolak tanpa perubahan;
 *   L-4  terbit (Push OFF, WhatsApp OFF): 1 kepala/versi/peristiwa, 1 InApp
 *        per wali berakun berhak, 0 Push/WhatsApp; replay; kunci lain → 409;
 *   L-5  wali berelasi (dua wali) melihat ringkasannya sendiri; wali lain,
 *        wali tanpa anak → kosong/403; tampilan internal per peran;
 *   L-6  konkurensi: 6 proses kunci sama → 1 terbit + 5 replay; 6 proses
 *        kunci berbeda dari revisi sama → 1 sukses + 5 konflik; notifikasi
 *        tidak ganda;
 *   L-7  Push ON + perangkat: paling banyak 1 Push per wali, payload hanya
 *        penunjuk + teks generik; retry memakai baris yang sama; receipt;
 *   L-8  Push OFF/WhatsApp OFF → 0 permintaan provider (juga sakelar WhatsApp
 *        lama menyala: kanal ditangguhkan);
 *   L-9  relasi dicabut/akun/santri nonaktif → 403 pada sesi lama, worker tidak
 *        mengirim; versi diganti sebelum worker → Push lama tidak terkirim;
 *   L-10 koreksi klinis tidak mengubah snapshot terbit; penggantian beralasan
 *        menyimpan versi lama; penarikan beralasan; terbit ulang;
 *   L-11 transaksi gagal (outbox, penerima, audit) → 0 perubahan, kunci dapat
 *        diulang; penugasan dicabut di tengah sesi → 403;
 *   L-12 privasi: 0 nama santri, keluhan, obat, rujukan, telepon, isi
 *        ringkasan pada payload/daftar notifikasi/audit/log akses; verifier
 *        026 lulus; tabel modul lain tidak berubah.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

if (getenv('V5_RUN_INTEGRATION') !== '1') {
    fwrite(STDERR, "Set V5_RUN_INTEGRATION=1 untuk menjalankan.\n");
    exit(2);
}
$dbName = getenv('V5F3_DB') ?: 'test_v5_f3_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "Ditolak: DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Kesehatan\KesehatanException;
use App\Kesehatan\RingkasanService;
use App\Notification\DeviceRepository;
use App\Notification\NotificationDispatcher;
use App\Notification\OutboxRepository;
use App\Notification\PushTokenProtector;
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
    $row = $r->fetch_row();

    return $row[0] ?? null;
};
$semua = static function (string $sql) use ($db): array {
    $r = $db->query($sql);
    if ($r === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }

    return $r->fetch_all(MYSQLI_ASSOC);
};
$jalankan = static function (string $sql) use ($db): void {
    if ($db->query($sql) === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
};
$uid = static fn (string $u): int => (int) $satu("SELECT id FROM users WHERE username='" . $db->real_escape_string($u) . "'");
$akun = static fn (string $u): array => ['id' => $uid($u), 'roles' => []];
$klinik = kesehatan_service();
$svc = kesehatan_ringkasan_service();
$tolak = static function (callable $fn): ?int {
    try {
        $fn();
    } catch (KesehatanException $e) {
        return $e->status;
    }

    return null;
};
$kunci = static fn (string $awal): string => $awal . '-' . bin2hex(random_bytes(6));
$jumlah = static fn (string $tabel, string $where = '1 = 1'): int => (int) $satu("SELECT COUNT(*) FROM `$tabel` WHERE $where");
$jejak = static fn (): array => [
    'kepala' => $jumlah('kesehatan_ringkasan'), 'versi' => $jumlah('kesehatan_ringkasan_versi'), 'peristiwa' => $jumlah('kesehatan_ringkasan_peristiwa'),
    'penerima' => $jumlah('kesehatan_ringkasan_penerima'), 'outbox' => $jumlah('notifikasi_outbox', "event_type LIKE 'v5\\_%'"),
    'klaim' => $jumlah('kesehatan_idempotensi', "operasi LIKE 'ringkasan.%'"), 'audit' => $jumlah('audit_logs', "action LIKE 'kesehatan.ringkasan.%'"),
];
$waktu = date('Y-m-d H:i', strtotime('-3 hours'));
$PENANDA = 'V5F3-' . strtoupper(bin2hex(random_bytes(3)));
$TELEPON = ['081200005301', '081200005302', '081200005303', '081200005304', '081200005305'];

$sW = (int) $satu("SELECT id FROM santri WHERE nis='V5S0201'");
$sLain = (int) $satu("SELECT id FROM santri WHERE nis='V5S0202'");
$NAMA_SANTRI = ['SBX V5 Wali Santri', 'SBX V5 Santri Lain'];
$petugas = $akun('sbx_v5_petugas_f');
$petugasG = $akun('sbx_v5_petugas_g');
$wali1 = $akun('sbx_v5_wali_1');
$wali2 = $akun('sbx_v5_wali_2');
$waliCabut = $akun('sbx_v5_wali_cabut');
$waliLain = $akun('sbx_v5_wali_lain');
$waliKosong = $akun('sbx_v5_wali_kosong');
$admin = $akun('sbx_admin');
$adminPetugas = $akun('sbx_v5_admin_petugas');
$pembimbing = $akun('sbx_v5_pembimbing');
$murobi = $akun('sbx_v5_murobi');
$pengurusBiasa = $akun('sbx_v5_petugas');
$wCabut = (int) $satu("SELECT id FROM wali WHERE nama='SBX V5 Wali Cabut'");

// Klien Push dan penyedia WhatsApp palsu: tidak ada jaringan.
$push = new class implements App\Notification\Push\PushClient, App\Notification\Push\PushReceiptClient {
    public array $messages = [];
    public int $calls = 0;
    public int $receipts = 0;
    public bool $fail = false;

    public function send(array $messages): array
    {
        $this->calls++;
        $this->messages = array_merge($this->messages, $messages);

        return $this->fail
            ? ['ok' => false, 'tickets' => [], 'kode' => 'FAKE_RETRY', 'pesan' => 'Gangguan uji sementara', 'permanen' => false]
            : ['ok' => true, 'tickets' => array_map(static fn () => ['status' => 'ok', 'id' => 'v5f3-' . bin2hex(random_bytes(6))], $messages), 'kode' => 'OK', 'pesan' => 'Palsu', 'permanen' => false];
    }

    public function getReceipts(array $ids): array
    {
        $this->receipts++;

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
$protector = new PushTokenProtector(base64_encode(str_repeat('v', 32)));
$dispatcher = new NotificationDispatcher($db, new OutboxRepository($db), $devices, $protector, $push, $wa, $settings, new WorkerLock($db));
$awalSetelan = $settings->current();
$awalCekWa = (string) $satu('SELECT whatsapp_check_status FROM pengaturan_notifikasi WHERE singleton = 1');
$jalankan('UPDATE pengaturan_notifikasi SET push_enabled = 0, whatsapp_enabled = 0 WHERE singleton = 1');
// Antrean fixture lain ditunda selama suite agar worker hanya memproses baris suite ini.
$tertunda = $semua("SELECT id, tersedia_pada FROM notifikasi_outbox WHERE kanal <> 'InApp' AND status IN ('Queued', 'Failed') AND gagal_permanen = 0");
foreach ($tertunda as $t) {
    $jalankan('UPDATE notifikasi_outbox SET tersedia_pada = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE id = ' . (int) $t['id']);
}
$perangkat = static function (array $user) use ($devices, $protector): int {
    $token = 'ExponentPushToken[v5f3-' . bin2hex(random_bytes(10)) . ']';

    return (int) $devices->register(['user_id' => $user['id'], 'token_hash' => $protector->hash($token), 'token_terlindungi' => $protector->protect($token),
        'platform' => 'android', 'device_id' => 'v5f3-' . bin2hex(random_bytes(5)), 'device_label' => 'Fixture V5 Fase 3', 'app_version' => 'uji'])['id'];
};
$buatKunjungan = static function (int $santri, string $status = 'Tercatat') use ($klinik, $petugas, $kunci, $waktu, $PENANDA): int {
    return $klinik->buat($petugas, ['idempotency_key' => $kunci('l-buat'), 'santri_id' => $santri, 'waktu_kunjungan' => $waktu, 'status' => $status,
        'keluhan' => $PENANDA . '-KELUHAN demam tinggi', 'catatan_klinis' => $PENANDA . '-CATATAN-KLINIS', 'tindakan' => $PENANDA . '-TINDAKAN-KLINIS',
        'obat' => [['nama_obat' => $PENANDA . '-OBAT Parasetamol', 'jumlah' => '1', 'satuan' => 'tablet']],
        'rujukan' => [['tujuan' => $PENANDA . '-RUJUKAN Puskesmas', 'alasan' => 'Periksa lanjutan']]], 'web')['id'];
};
$isi = static fn (string $teks, ?string $tl = null, int $kv = 1, int $rev = 0, ?string $alasan = null): array => [
    'kunjungan_versi' => $kv, 'revisi_ringkasan' => $rev, 'ringkasan' => $teks, 'tindakan_umum' => 'Istirahat di klinik', 'tindak_lanjut_wali' => $tl, 'alasan' => $alasan,
];
$terbit = static function (array $user, int $kid, array $masukan, ?string $key = null) use ($svc, $kunci): array {
    $p = $svc->pratinjau($user, $kid, $masukan);

    return $svc->terbitkan($user, $kid, $masukan + ['konfirmasi' => true, 'pratinjau_hash' => $p['pratinjau_hash'], 'idempotency_key' => $key ?? $kunci('l-terbit')], 'web');
};
$outboxEvent = static fn (int $rid, int $versi, string $kanal): int => $jumlah('notifikasi_outbox', "event_key = 'v5:kesehatan:ringkasan:$rid:v$versi' AND kanal = '$kanal'");

try {
    $assert($sW > 0 && $sLain > 0 && $petugas['id'] > 0 && $wali1['id'] > 0 && $waliCabut['id'] > 0 && $petugasG['id'] > 0, 'Fixture V5 Fase 3 tersedia');
    $tabelLain = array_column($semua("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
        AND TABLE_NAME NOT LIKE 'kesehatan\\_%' AND TABLE_NAME NOT IN ('notifikasi_outbox', 'notifikasi_percobaan', 'perangkat_push', 'pengaturan_notifikasi', 'audit_logs',
        'santri_wali', 'users', 'wali', 'santri', 'kesehatan_assignments', 'api_tokens', 'sessions', 'login_attempts', 'worker_locks', 'notifikasi_worker_lock')"), 't');
    $hitungLain = [];
    foreach ($tabelLain as $t) {
        $hitungLain[$t] = $jumlah($t);
    }

    // ================================================================= L-1
    echo PHP_EOL . '=== L-1. Sebelum terbit ===' . PHP_EOL;
    $k1 = $buatKunjungan($sW);
    $assert($svc->daftarWali($wali1, [])['total'] === 0 && $svc->daftarWali($wali2, [])['total'] === 0, 'Belum terbit: daftar kedua wali kosong');
    $tebakan = array_map(static fn ($i) => $tolak(static fn () => $svc->detailWali($wali1, $i, 'web')), [1, 2, 3, 99, 999999]);
    $assert(array_unique($tebakan) === [403], 'Belum terbit: ID tebakan (1, 2, 3, 99, 999999) → 403');
    $assert($tolak(static fn () => $svc->daftarWali($admin, [])) === 403 && $tolak(static fn () => $svc->daftarWali($petugas, [])) === 403, 'Admin dan petugas bukan wali: daftar wali 403');
    $kDraf = $buatKunjungan($sW, 'Draf');
    $assert($tolak(static fn () => $svc->pratinjau($petugas, $kDraf, $isi('Ringkasan untuk draf yang belum boleh terbit.'))) === 422, 'Kunjungan Draf tidak dapat dipratinjau/diterbitkan (422)');

    // ================================================================= L-2
    echo PHP_EOL . '=== L-2. Hak menerbitkan ===' . PHP_EOL;
    $sebelum = $jejak();
    foreach (['admin' => $admin, 'admin+petugas' => $adminPetugas, 'pembimbing' => $pembimbing, 'murobi' => $murobi, 'wali' => $wali1, 'pengurus tanpa penugasan' => $pengurusBiasa] as $label => $u) {
        $hasil = [
            $tolak(static fn () => $svc->pratinjau($u, $k1, $isi('Ringkasan percobaan tanpa hak menulis.'))),
            $tolak(static fn () => $svc->terbitkan($u, $k1, $isi('Ringkasan percobaan tanpa hak menulis.') + ['konfirmasi' => true, 'pratinjau_hash' => str_repeat('a', 64), 'idempotency_key' => $kunci('l2')], 'api')),
            $tolak(static fn () => $svc->tarik($u, $k1, ['revisi_ringkasan' => 1, 'alasan' => 'Percobaan tarik tanpa hak', 'idempotency_key' => $kunci('l2t')], 'api')),
        ];
        $assert($hasil === [403, 403, 403], "$label: pratinjau/terbit/tarik → 403");
    }
    $assert($jejak() === $sebelum, 'Seluruh percobaan tanpa hak: 0 perubahan (kepala, versi, peristiwa, outbox, klaim, audit)');

    // ================================================================= L-3
    echo PHP_EOL . '=== L-3. Validasi pratinjau dan konfirmasi ===' . PHP_EOL;
    $m1 = $isi($PENANDA . '-RINGKASAN Ananda sempat demam dan sudah membaik setelah beristirahat.');
    $p1 = $svc->pratinjau($petugas, $k1, $m1);
    $assert($p1['pratinjau']['ringkasan'] === $m1['ringkasan'] && $p1['pratinjau']['status'] === 'Terbit' && $p1['penerima'] === 3 && $jejak() === $sebelum,
        'Pratinjau: proyeksi wali persis isian, 3 akun wali berhak, tanpa menulis apa pun');
    $assert(!array_key_exists('keluhan', $p1['pratinjau']) && !str_contains(json_encode($p1), $PENANDA . '-KELUHAN') && !str_contains(json_encode($p1), $PENANDA . '-OBAT'),
        'Pratinjau tidak membawa catatan klinis kunjungan');
    $assert($tolak(static fn () => $svc->terbitkan($petugas, $k1, $m1 + ['pratinjau_hash' => $p1['pratinjau_hash'], 'idempotency_key' => $kunci('l3a')], 'web')) === 422, 'Tanpa konfirmasi eksplisit → 422');
    $ubah = ['ringkasan' => $m1['ringkasan'] . ' DIUBAH'] + $m1;
    $assert($tolak(static fn () => $svc->terbitkan($petugas, $k1, $ubah + ['konfirmasi' => true, 'pratinjau_hash' => $p1['pratinjau_hash'], 'idempotency_key' => $kunci('l3b')], 'web')) === 422,
        'Isi berbeda dari pratinjau (hash tidak cocok) → 422');
    $assert($tolak(static fn () => $svc->pratinjau($petugas, $k1, $isi('Pendek'))) === 422 && $tolak(static fn () => $svc->pratinjau($petugas, $k1, $isi('Ringkasan cukup panjang untuk wali.', null, 7))) === 409,
        'Ringkasan terlalu pendek → 422; versi kunjungan usang → 409');
    $assert($jejak() === $sebelum, 'Validasi gagal: 0 perubahan');

    // ================================================================= L-4
    echo PHP_EOL . '=== L-4. Terbit (Push OFF, WhatsApp OFF) ===' . PHP_EOL;
    $kunciTerbit = $kunci('l4');
    $t1 = $svc->terbitkan($petugas, $k1, $m1 + ['konfirmasi' => true, 'pratinjau_hash' => $p1['pratinjau_hash'], 'idempotency_key' => $kunciTerbit], 'web');
    $r1 = $t1['ringkasan_id'];
    $sesudah = $jejak();
    $assert($t1['status'] === 201 && !$t1['replayed'] && $t1['versi'] === 1 && $t1['notifikasi'] === ['in_app' => 3, 'push' => 0], 'Terbit: versi 1, 3 InApp, 0 Push');
    $assert($sesudah['kepala'] === $sebelum['kepala'] + 1 && $sesudah['versi'] === $sebelum['versi'] + 1 && $sesudah['peristiwa'] === $sebelum['peristiwa'] + 1 && $sesudah['penerima'] === $sebelum['penerima'] + 3,
        'Tepat satu kepala, satu versi, satu peristiwa, tiga penerima');
    $assert($outboxEvent($r1, 1, 'InApp') === 3 && $outboxEvent($r1, 1, 'Push') === 0 && $outboxEvent($r1, 1, 'WhatsApp') === 0, 'Outbox: 1 InApp per wali berakun (3), 0 Push, 0 WhatsApp');
    $penerima = array_map('intval', array_column($semua("SELECT o.penerima_user_id u FROM notifikasi_outbox o WHERE o.event_key = 'v5:kesehatan:ringkasan:$r1:v1' ORDER BY u"), 'u'));
    $harap = [$wali1['id'], $wali2['id'], $waliCabut['id']];
    sort($harap);
    $assert($penerima === $harap, 'Penerima tepat wali_1, wali_2, wali_cabut; wali tanpa akun dan wali lain tidak');
    $ulang = $svc->terbitkan($petugas, $k1, $m1 + ['konfirmasi' => true, 'pratinjau_hash' => $p1['pratinjau_hash'], 'idempotency_key' => $kunciTerbit], 'web');
    $assert($ulang['replayed'] && $ulang['ringkasan_id'] === $r1 && $ulang['versi'] === 1 && $jejak() === $sesudah, 'Ulang kunci + isi sama → replay, 0 baris baru (tanpa notifikasi ganda)');
    $assert($tolak(static fn () => $svc->terbitkan($petugas, $k1, $m1 + ['konfirmasi' => true, 'pratinjau_hash' => $p1['pratinjau_hash'], 'idempotency_key' => $kunci('l4b')], 'web')) === 409 && $jejak() === $sesudah,
        'Klik kedua dengan kunci lain dari pratinjau yang sama → 409 (revisi berubah), 0 baris baru');
    $mBeda = $isi('Isi lain yang dikirim dengan kunci yang sama.', null, 1, 1, 'Alasan uji kunci sama');
    $pBeda = $svc->pratinjau($petugas, $k1, $mBeda);
    $assert($tolak(static fn () => $svc->terbitkan($petugas, $k1, $mBeda + ['konfirmasi' => true, 'pratinjau_hash' => $pBeda['pratinjau_hash'], 'idempotency_key' => $kunciTerbit], 'web')) === 409 && $jejak() === $sesudah,
        'Kunci sama + isi berbeda → 409 tanpa perubahan');

    // ================================================================= L-5
    echo PHP_EOL . '=== L-5. Tampilan wali dan internal ===' . PHP_EOL;
    $d1 = $svc->daftarWali($wali1, []);
    $d2 = $svc->daftarWali($wali2, []);
    $assert($d1['total'] === 1 && $d1['rows'][0]['id'] === $r1 && $d2['total'] === 1 && $d2['rows'][0]['id'] === $r1, 'Wali kedua yang berelasi aktif juga menerima ringkasannya sendiri');
    $det = $svc->detailWali($wali2, $r1, 'api');
    $assert($det['ringkasan'] === $m1['ringkasan'] && $det['status'] === 'Terbit' && array_keys($det) === ['id', 'santri', 'tanggal_kunjungan', 'diterbitkan_pada', 'status', 'versi', 'diperbarui', 'ringkasan', 'tindakan_umum', 'tindak_lanjut_wali'],
        'Detail wali = proyeksi khusus (tanpa kolom klinis, nama petugas, alasan)');
    $assert(!str_contains(json_encode($d1 + $det), $PENANDA . '-KELUHAN') && !str_contains(json_encode($d1 + $det), $PENANDA . '-OBAT') && !str_contains(json_encode($d1 + $det), $PENANDA . '-RUJUKAN'),
        'Daftar/detail wali tidak memuat keluhan, obat, atau rujukan internal');
    $assert($jumlah('kesehatan_akses_log', "kunjungan_id = $k1 AND sumber = 'wali' AND aksi = 'baca_ringkasan'") === 1, 'Pembukaan detail wali tercatat di log akses (metadata)');
    $assert($svc->daftarWali($waliLain, [])['total'] === 0 && $tolak(static fn () => $svc->detailWali($waliLain, $r1, 'web')) === 403, 'Wali lain: daftar kosong, detail → 403');
    $assert($svc->daftarWali($waliKosong, [])['total'] === 0 && $tolak(static fn () => $svc->detailWali($waliKosong, $r1, 'web')) === 403, 'Wali tanpa anak berelasi: kosong/403');
    $assert($svc->daftarWali($wali1, ['santri_id' => $sLain])['total'] === 0, 'Penyaring santri lain tidak memperluas cakupan wali');
    $iAdmin = $svc->internal($admin, $k1);
    $iPembimbing = $svc->internal($pembimbing, $k1);
    $assert($iAdmin['berlaku']['ringkasan'] === $m1['ringkasan'] && count($iAdmin['versi']) === 1 && count($iAdmin['peristiwa']) === 1 && !$iAdmin['boleh_terbit'], 'Admin: versi berlaku + riwayat, tanpa hak terbit');
    $assert($iPembimbing['berlaku']['ringkasan'] === $m1['ringkasan'] && $iPembimbing['versi'] === [] && $iPembimbing['peristiwa'] === [] && $iPembimbing['penerima'] === null, 'Pembimbing terkait: hanya versi berlaku, tanpa riwayat/alasan');
    $assert($tolak(static fn () => $svc->internal($murobi, $k1)) === 403 && $tolak(static fn () => $svc->internal($wali1, $k1)) === 403, 'Murobi di luar cakupan dan wali: tampilan internal → 403');

    // ================================================================= L-6
    echo PHP_EOL . '=== L-6. Konkurensi (proses terpisah) ===' . PHP_EOL;
    $scratch = sys_get_temp_dir() . '/v5f3-' . bin2hex(random_bytes(4));
    mkdir($scratch);
    $serbu = static function (array $permintaan) use ($scratch, $dbName): array {
        $aba = $scratch . '/mulai-' . bin2hex(random_bytes(3));
        $proc = [];
        foreach ($permintaan as $i => [$userId, $kunjunganId, $masukan]) {
            $berkas = $scratch . '/masukan-' . $i . '-' . bin2hex(random_bytes(3)) . '.json';
            file_put_contents($berkas, json_encode($masukan, JSON_UNESCAPED_UNICODE));
            $h = proc_open(['php', dirname(__DIR__) . '/tests/v5_phase3_worker.php', (string) $userId, (string) $kunjunganId, $berkas, $aba],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['DB_NAME' => $dbName]));
            $proc[$i] = [$h, $pipes];
        }
        usleep(500000);
        touch($aba);
        $hasil = [];
        foreach ($proc as [$h, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            proc_close($h);
            $hasil[] = json_decode(trim((string) $out), true) ?? ['ok' => false, 'status' => 0];
        }

        return $hasil;
    };
    $k2 = $buatKunjungan($sW);
    $m2 = $isi('Ringkasan konkurensi kunci sama untuk wali.');
    $p2 = $svc->pratinjau($petugas, $k2, $m2);
    $kunciSama = $kunci('l6-sama');
    $hasil = $serbu(array_fill(0, 6, [$petugas['id'], $k2, $m2 + ['konfirmasi' => true, 'pratinjau_hash' => $p2['pratinjau_hash'], 'idempotency_key' => $kunciSama]]));
    $baru = count(array_filter($hasil, static fn ($h) => ($h['ok'] ?? false) && !$h['replayed']));
    $replay = count(array_filter($hasil, static fn ($h) => ($h['ok'] ?? false) && $h['replayed']));
    $r2 = (int) $satu("SELECT id FROM kesehatan_ringkasan WHERE kunjungan_id = $k2");
    $assert($baru === 1 && $replay === 5 && $jumlah('kesehatan_ringkasan_versi', "ringkasan_id = $r2") === 1 && $outboxEvent($r2, 1, 'InApp') === 3,
        '6 proses kunci+isi sama → 1 terbit + 5 replay; 1 versi; 3 InApp (tanpa ganda)');
    $k3 = $buatKunjungan($sW);
    $m3 = $isi('Ringkasan konkurensi kunci berbeda untuk wali.');
    $p3 = $svc->pratinjau($petugas, $k3, $m3);
    $permintaan = [];
    for ($i = 0; $i < 6; $i++) {
        $permintaan[] = [$petugas['id'], $k3, $m3 + ['konfirmasi' => true, 'pratinjau_hash' => $p3['pratinjau_hash'], 'idempotency_key' => $kunci('l6-beda-' . $i)]];
    }
    $hasil = $serbu($permintaan);
    $sukses = count(array_filter($hasil, static fn ($h) => $h['ok'] ?? false));
    $konflik = count(array_filter($hasil, static fn ($h) => !($h['ok'] ?? false) && ($h['status'] ?? 0) === 409));
    $r3 = (int) $satu("SELECT id FROM kesehatan_ringkasan WHERE kunjungan_id = $k3");
    $assert($sukses === 1 && $konflik === 5 && $jumlah('kesehatan_ringkasan_versi', "ringkasan_id = $r3") === 1 && $jumlah('kesehatan_ringkasan_peristiwa', "ringkasan_id = $r3") === 1 && $outboxEvent($r3, 1, 'InApp') === 3,
        '6 proses kunci berbeda dari revisi sama → 1 sukses + 5 konflik 409; 1 versi, 1 peristiwa, 3 InApp');
    $assert($jumlah('kesehatan_idempotensi', "operasi = 'ringkasan.terbit' AND kunjungan_id IS NULL") === 0, 'Klaim konflik ikut dibatalkan (tidak menggantung)');

    // ================================================================= L-7
    echo PHP_EOL . '=== L-7. Push ON + perangkat, retry, receipt ===' . PHP_EOL;
    $settings->setPushEnabled(true, $admin['id']);
    $dev1 = $perangkat($wali1);
    $k4 = $buatKunjungan($sW);
    $t4 = $terbit($petugas, $k4, $isi('Ringkasan dengan Push untuk wali yang punya perangkat.', 'Mohon pantau suhu di rumah.'));
    $r4 = $t4['ringkasan_id'];
    $assert($t4['notifikasi'] === ['in_app' => 3, 'push' => 1] && $outboxEvent($r4, 1, 'Push') === 1 && $outboxEvent($r4, 1, 'WhatsApp') === 0, 'Push ON: 3 InApp, 1 Push (hanya wali berperangkat), 0 WhatsApp');
    $assert((string) $satu("SELECT event_type FROM notifikasi_outbox WHERE event_key = 'v5:kesehatan:ringkasan:$r4:v1' LIMIT 1") === RingkasanService::EVENT_TINDAK_LANJUT,
        'Ringkasan dengan tindak lanjut untuk orang tua → peristiwa "perlu perhatian" (sekali per versi)');
    $oid = (int) $satu("SELECT id FROM notifikasi_outbox WHERE event_key = 'v5:kesehatan:ringkasan:$r4:v1' AND kanal = 'Push'");
    $push->fail = true;
    $dispatcher->run('Push');
    $row = $semua("SELECT status, percobaan FROM notifikasi_outbox WHERE id = $oid")[0];
    $assert($row['status'] === 'Failed' && (int) $row['percobaan'] === 1 && $push->calls === 1, 'Kegagalan sementara provider tercatat sebagai Failed untuk retry');
    $push->fail = false;
    $jalankan("UPDATE notifikasi_outbox SET tersedia_pada = NOW() WHERE id = $oid");
    $dispatcher->run('Push');
    $dispatcher->run('Push');
    $row = $semua("SELECT status, percobaan FROM notifikasi_outbox WHERE id = $oid")[0];
    $assert($row['status'] === 'Sent' && (int) $row['percobaan'] === 2 && $push->calls === 2 && $outboxEvent($r4, 1, 'Push') === 1, 'Retry memakai baris yang sama; baris Sent tidak dikirim ulang');
    $pesan = end($push->messages);
    $teks = RingkasanService::teksNotifikasi(RingkasanService::EVENT_TINDAK_LANJUT);
    $assert($pesan['data'] === ['tipe' => 'kesehatan_ringkasan', 'ringkasan_id' => $r4] && $pesan['title'] === $teks['judul'] && $pesan['body'] === $teks['isi'], 'Payload Push hanya penunjuk {tipe, ringkasan_id} dan kalimat generik');
    $dispatcher->reconcileReceipts(100, 0);
    $assert($push->receipts > 0, 'Receipt diproses lewat klien palsu (bukan bukti perangkat fisik)');

    // ================================================================= L-8
    echo PHP_EOL . '=== L-8. Push OFF dan WhatsApp OFF ===' . PHP_EOL;
    $settings->setPushEnabled(false, $admin['id']);
    $calls = $push->calls;
    $k5 = $buatKunjungan($sW);
    $t5 = $terbit($petugas, $k5, $isi('Ringkasan saat Push dimatikan untuk wali.'));
    $assert($t5['notifikasi']['push'] === 0 && $outboxEvent($t5['ringkasan_id'], 1, 'Push') === 0, 'Push OFF saat terbit: 0 baris Push walau wali punya perangkat');
    $jalankan("UPDATE notifikasi_outbox SET tersedia_pada = NOW() WHERE event_type LIKE 'v5\\_%' AND kanal = 'Push' AND status IN ('Queued','Failed')");
    $hPush = $dispatcher->run('Push');
    $assert($push->calls === $calls && $hPush['dijalankan'] === false, 'Push OFF: worker berhenti sebelum klaim, 0 permintaan ke provider');
    $jalankan("UPDATE pengaturan_notifikasi SET whatsapp_check_status = 'Lulus', whatsapp_enabled = 1 WHERE singleton = 1");
    $k6 = $buatKunjungan($sW);
    $t6 = $terbit($petugas, $k6, $isi('Ringkasan saat sakelar WhatsApp lama menyala.'));
    $hWa = $dispatcher->run('WhatsApp');
    $assert($outboxEvent($t6['ringkasan_id'], 1, 'WhatsApp') === 0 && $wa->calls === 0 && $hWa['dijalankan'] === false, 'WhatsApp: tidak pernah diantrekan; kanal ditangguhkan → 0 permintaan provider');
    $jalankan('UPDATE pengaturan_notifikasi SET whatsapp_enabled = 0 WHERE singleton = 1');
    $assert($jumlah('notifikasi_outbox', "event_type LIKE 'v5\\_%' AND kanal = 'WhatsApp'") === 0, 'Seluruh suite: 0 baris WhatsApp V5');

    // ================================================================= L-9
    echo PHP_EOL . '=== L-9. Relasi dicabut, akun/santri nonaktif, versi diganti ===' . PHP_EOL;
    $settings->setPushEnabled(true, $admin['id']);
    $devCabut = $perangkat($waliCabut);
    $k7 = $buatKunjungan($sW);
    $t7 = $terbit($petugas, $k7, $isi('Ringkasan sebelum relasi wali dicabut.'));
    $r7 = $t7['ringkasan_id'];
    $assert($outboxEvent($r7, 1, 'Push') === 2, 'Push diantrekan untuk wali_1 dan wali_cabut (berperangkat)');
    $assert($svc->detailWali($waliCabut, $r7, 'web')['status'] === 'Terbit', 'Sebelum dicabut: wali_cabut dapat membuka');
    $jalankan("UPDATE santri_wali SET archived_at = NOW() WHERE santri_id = $sW AND wali_id = $wCabut");
    $calls = $push->calls;
    $dispatcher->run('Push');
    $oCabut = $semua("SELECT status, error_kode, gagal_permanen FROM notifikasi_outbox WHERE event_key = 'v5:kesehatan:ringkasan:$r7:v1' AND kanal = 'Push' AND penerima_user_id = {$waliCabut['id']}")[0];
    $assert($oCabut['status'] === 'Failed' && $oCabut['error_kode'] === 'AKSES_BERUBAH' && (int) $oCabut['gagal_permanen'] === 1 && $push->calls === $calls + 1,
        'Relasi dicabut sebelum worker: Push wali_cabut gagal permanen AKSES_BERUBAH tanpa request; wali_1 tetap terkirim');
    $assert($svc->daftarWali($waliCabut, [])['total'] === 0 && $tolak(static fn () => $svc->detailWali($waliCabut, $r7, 'web')) === 403 && $tolak(static fn () => $svc->detailWali($waliCabut, $r1, 'api')) === 403,
        'Sesi/token lama wali_cabut sesudah relasi dicabut: daftar kosong, detail web/API → 403');
    $center = notification_center_service()->index($waliCabut, ['jenis' => 'kesehatan']);
    $assert($center['pagination']['total'] >= 1 && array_unique(array_map(static fn ($i) => $i['tautan']['tipe'], $center['items'])) === ['kesehatan_ringkasan'], 'Notifikasi InApp lama tetap generik di pusat notifikasi (tujuan tetap diperiksa ulang)');
    $jalankan("UPDATE users SET is_active = 0 WHERE id = {$wali2['id']}");
    $assert($tolak(static fn () => $svc->detailWali($wali2, $r1, 'web')) === 403 && $tolak(static fn () => $svc->daftarWali($wali2, [])) === 403, 'Akun wali nonaktif → 403');
    $jalankan("UPDATE users SET is_active = 1 WHERE id = {$wali2['id']}");
    $jalankan("UPDATE santri SET is_active = 0 WHERE id = $sW");
    $assert($tolak(static fn () => $svc->detailWali($wali1, $r1, 'web')) === 403 && $svc->daftarWali($wali1, [])['total'] === 0, 'Santri nonaktif → ringkasannya tertutup bagi wali');
    $assert($tolak(static fn () => $svc->pratinjau($petugas, $k7, $isi('Ringkasan untuk santri yang sudah nonaktif.', null, 1, 1, 'Alasan uji santri nonaktif'))) === 422, 'Santri nonaktif → pratinjau/terbit ringkasan ditolak 422');
    $jalankan("UPDATE santri SET is_active = 1 WHERE id = $sW");
    // Versi diganti sebelum worker: Push versi lama tidak terkirim.
    $k8 = $buatKunjungan($sW);
    $t8 = $terbit($petugas, $k8, $isi('Ringkasan pertama yang segera diganti.'));
    $r8 = $t8['ringkasan_id'];
    $terbit($petugas, $k8, $isi('Ringkasan pengganti sebelum Push terkirim.', null, 1, 1, 'Perbaikan kalimat untuk wali'));
    $calls = $push->calls;
    $dispatcher->run('Push');
    $lamaPush = $semua("SELECT status, error_kode FROM notifikasi_outbox WHERE event_key = 'v5:kesehatan:ringkasan:$r8:v1' AND kanal = 'Push'")[0];
    $baruPush = $semua("SELECT status FROM notifikasi_outbox WHERE event_key = 'v5:kesehatan:ringkasan:$r8:v2' AND kanal = 'Push'")[0];
    $assert($lamaPush['status'] === 'Failed' && $lamaPush['error_kode'] === 'AKSES_BERUBAH' && $baruPush['status'] === 'Sent' && $push->calls === $calls + 1, 'Versi diganti sebelum worker: Push versi lama tidak dikirim, versi baru dikirim sekali');
    $settings->setPushEnabled(false, $admin['id']);

    // ================================================================ L-10
    echo PHP_EOL . '=== L-10. Koreksi klinis, penggantian, penarikan, terbit ulang ===' . PHP_EOL;
    $snapshot = $semua("SELECT ringkasan, tindakan_umum, tindak_lanjut_wali, kunjungan_versi FROM kesehatan_ringkasan_versi WHERE ringkasan_id = $r1 AND versi = 1")[0];
    $dk = $klinik->detail($petugas, $k1, 'web');
    $klinik->koreksi($petugas, $k1, ['idempotency_key' => $kunci('l10k'), 'versi' => 1, 'alasan' => 'Koreksi klinis sesudah ringkasan terbit', 'waktu_kunjungan' => $waktu,
        'keluhan' => $PENANDA . '-KELUHAN-KOREKSI', 'catatan_klinis' => $dk['catatan_klinis'], 'tindakan' => $dk['tindakan'],
        'obat' => array_map(static fn ($o) => $o, $dk['obat']), 'rujukan' => $dk['rujukan'], 'tindak_lanjut' => []], 'web');
    $assert($semua("SELECT ringkasan, tindakan_umum, tindak_lanjut_wali, kunjungan_versi FROM kesehatan_ringkasan_versi WHERE ringkasan_id = $r1 AND versi = 1")[0] === $snapshot
        && $svc->detailWali($wali1, $r1, 'web')['ringkasan'] === $m1['ringkasan'] && (int) $satu("SELECT versi FROM kesehatan_kunjungan WHERE id = $k1") === 2,
        'Koreksi klinis (versi 2) tidak mengubah teks ringkasan terbit atau versi sumbernya');
    $assert($tolak(static fn () => $svc->pratinjau($petugas, $k1, $isi('Ringkasan pengganti dari pratinjau lama.', null, 1, 1, 'Alasan penggantian'))) === 409, 'Pratinjau dengan versi kunjungan lama sesudah koreksi → 409');
    $assert($tolak(static fn () => $svc->pratinjau($petugas, $k1, $isi('Ringkasan pengganti tanpa alasan.', null, 2, 1))) === 422, 'Penggantian tanpa alasan → 422');
    $m1b = $isi($PENANDA . '-RINGKASAN-V2 Ananda sudah sehat dan kembali mengikuti kegiatan.', 'Mohon ingatkan minum air yang cukup.', 2, 1, 'Pembaruan setelah pemeriksaan ulang');
    $t1b = $terbit($petugas, $k1, $m1b);
    $assert($t1b['versi'] === 2 && $t1b['revisi'] === 2 && $outboxEvent($r1, 2, 'InApp') === 2, 'Penggantian beralasan → versi 2; 1 InApp per wali yang MASIH berhak (2; wali_cabut tidak)');
    $v1 = $semua("SELECT status, berakhir_pada, ringkasan FROM kesehatan_ringkasan_versi WHERE ringkasan_id = $r1 AND versi = 1")[0];
    $assert($v1['status'] === 'Diganti' && $v1['berakhir_pada'] !== null && $v1['ringkasan'] === $m1['ringkasan'], 'Versi 1 tetap tersimpan utuh berstatus Diganti');
    $dw = $svc->detailWali($wali1, $r1, 'web');
    $assert($dw['ringkasan'] === $m1b['ringkasan'] && $dw['versi'] === 2 && $dw['diperbarui'] === true && !isset($dw['alasan']), 'Wali melihat versi 2 (diperbarui), tanpa alasan internal');
    $ia = $svc->internal($admin, $k1);
    $assert(count($ia['versi']) === 2 && $ia['peristiwa'][0]['jenis'] === 'Ganti' && $ia['peristiwa'][0]['alasan'] === 'Pembaruan setelah pemeriksaan ulang' && $ia['versi'][1]['ringkasan'] === $m1['ringkasan'],
        'Petugas/admin menelusuri versi lama dan alasan penggantian');
    $sebelumTarik = $jejak();
    $assert($tolak(static fn () => $svc->tarik($petugas, $k1, ['revisi_ringkasan' => 2, 'alasan' => '', 'idempotency_key' => $kunci('l10t')], 'web')) === 422
        && $tolak(static fn () => $svc->tarik($petugas, $k1, ['revisi_ringkasan' => 1, 'alasan' => 'Revisi usang', 'idempotency_key' => $kunci('l10t')], 'web')) === 409 && $jejak() === $sebelumTarik,
        'Tarik tanpa alasan → 422; dari revisi usang → 409; 0 perubahan');
    $kunciTarik = $kunci('l10t');
    $tk = $svc->tarik($petugas, $k1, ['revisi_ringkasan' => 2, 'alasan' => 'Informasi akan disusun ulang oleh klinik', 'idempotency_key' => $kunciTarik], 'web');
    $tkUlang = $svc->tarik($petugas, $k1, ['revisi_ringkasan' => 2, 'alasan' => 'Informasi akan disusun ulang oleh klinik', 'idempotency_key' => $kunciTarik], 'web');
    $dTarik = $svc->detailWali($wali1, $r1, 'web');
    $assert($tk['revisi'] === 3 && $tkUlang['replayed'] && $dTarik['status'] === 'Ditarik' && $dTarik['ringkasan'] === null && $dTarik['tindak_lanjut_wali'] === null, 'Tarik beralasan: wali melihat Ditarik tanpa isi; ulang kunci → replay');
    $assert($jumlah('notifikasi_outbox', "event_key LIKE 'v5:kesehatan:ringkasan:$r1:%'") === 5 && $jumlah('kesehatan_ringkasan_versi', "ringkasan_id = $r1 AND status = 'Ditarik'") === 1, 'Penarikan tidak membuat notifikasi; versi 2 tersimpan berstatus Ditarik');
    $assert($tolak(static fn () => $svc->tarik($petugas, $k1, ['revisi_ringkasan' => 3, 'alasan' => 'Tarik kedua kalinya', 'idempotency_key' => $kunci('l10t2')], 'web')) === 409, 'Tarik kedua → 409');
    $assert($tolak(static fn () => $svc->pratinjau($petugas, $k1, $isi('Formulir penggantian lama sebelum ditarik.', null, 2, 2, 'Alasan dari formulir lama'))) === 409, 'Formulir penggantian dari sebelum penarikan (revisi lama) → 409');
    $t1c = $terbit($petugas, $k1, $isi('Ringkasan yang disusun ulang setelah ditarik.', null, 2, 3, 'Terbit ulang setelah disusun ulang'));
    $assert($t1c['versi'] === 3 && $svc->detailWali($wali1, $r1, 'web')['versi'] === 3 && $outboxEvent($r1, 3, 'InApp') === 2, 'Terbit ulang beralasan → versi 3 + notifikasi baru sekali per wali berhak');

    // ================================================================ L-11
    echo PHP_EOL . '=== L-11. Transaksi gagal dan penugasan dicabut ===' . PHP_EOL;
    $k9 = $buatKunjungan($sW);
    $m9 = $isi('Ringkasan untuk uji kegagalan transaksi.');
    $p9 = $svc->pratinjau($petugas, $k9, $m9);
    $kunciGagal = $kunci('l11');
    $masukan9 = $m9 + ['konfirmasi' => true, 'pratinjau_hash' => $p9['pratinjau_hash'], 'idempotency_key' => $kunciGagal];
    foreach ([
        'outbox' => "CREATE TRIGGER v5f3_gagal BEFORE INSERT ON notifikasi_outbox FOR EACH ROW BEGIN IF NEW.event_type LIKE 'v5%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uji'; END IF; END",
        'penerima' => "CREATE TRIGGER v5f3_gagal BEFORE INSERT ON kesehatan_ringkasan_penerima FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uji'; END",
        'audit' => "CREATE TRIGGER v5f3_gagal BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action LIKE 'kesehatan.ringkasan.%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uji'; END IF; END",
    ] as $label => $trigger) {
        $sebelum = $jejak();
        $jalankan($trigger);
        try {
            $status = $tolak(static fn () => $svc->terbitkan($petugas, $k9, $masukan9, 'web'));
        } finally {
            $jalankan('DROP TRIGGER IF EXISTS v5f3_gagal');
        }
        $assert($status === 503 && $jejak() === $sebelum, "Kegagalan $label → 503, 0 kepala/versi/peristiwa/penerima/outbox/klaim/audit");
    }
    $ok9 = $svc->terbitkan($petugas, $k9, $masukan9, 'web');
    $assert($ok9['status'] === 201 && !$ok9['replayed'] && $outboxEvent($ok9['ringkasan_id'], 1, 'InApp') === 2, 'Kunci yang sama dapat diulang sesudah kegagalan (klaim ikut batal) → terbit sekali');
    $k10 = $buatKunjungan($sW);
    $m10 = $isi('Ringkasan oleh petugas yang penugasannya dicabut.');
    $p10 = $svc->pratinjau($petugasG, $k10, $m10);
    $jalankan("UPDATE kesehatan_assignments a JOIN users u ON u.pengurus_id = a.pengurus_id SET a.is_active = 0 WHERE u.id = {$petugasG['id']}");
    $sebelum = $jejak();
    $assert($tolak(static fn () => $svc->terbitkan($petugasG, $k10, $m10 + ['konfirmasi' => true, 'pratinjau_hash' => $p10['pratinjau_hash'], 'idempotency_key' => $kunci('l11g')], 'web')) === 403 && $jejak() === $sebelum,
        'Penugasan dicabut sesudah pratinjau (sesi lama) → terbit 403 tanpa baris baru');

    // ================================================================ L-12
    echo PHP_EOL . '=== L-12. Privasi, verifier, modul lain ===' . PHP_EOL;
    $teksRingkasan = array_column($semua('SELECT ringkasan FROM kesehatan_ringkasan_versi'), 'ringkasan');
    $terlarang = array_merge($NAMA_SANTRI, $TELEPON, [$PENANDA . '-KELUHAN', $PENANDA . '-CATATAN', $PENANDA . '-TINDAKAN', $PENANDA . '-OBAT', $PENANDA . '-RUJUKAN', $PENANDA . '-RINGKASAN', 'Parasetamol', 'Puskesmas'], $teksRingkasan);
    $bocor = static function (string $teks) use ($terlarang): array {
        return array_values(array_filter($terlarang, static fn ($t) => $t !== '' && str_contains($teks, $t)));
    };
    $outbox = json_encode($semua("SELECT event_key, event_type, judul, isi, data_json, error_terakhir FROM notifikasi_outbox WHERE event_type LIKE 'v5\\_%'"), JSON_UNESCAPED_UNICODE);
    $assert($bocor($outbox) === [], 'Baris outbox V5 (judul, isi, data, kunci, galat): 0 nama santri/telepon/keluhan/obat/rujukan/isi ringkasan');
    $assert($bocor(json_encode($push->messages, JSON_UNESCAPED_UNICODE)) === [] && count($push->messages) > 0, 'Payload Push yang dikirim ke provider palsu: 0 data terlarang');
    $daftarNotif = json_encode([notification_center_service()->index($wali1, ['jenis' => 'kesehatan', 'per_page' => 100]), notification_center_service()->index($wali2, ['per_page' => 100])], JSON_UNESCAPED_UNICODE);
    $assert($bocor($daftarNotif) === [] && str_contains($daftarNotif, '"ringkasan_id"'), 'Daftar notifikasi wali (pusat notifikasi/API): 0 data terlarang, hanya penunjuk ringkasan_id');
    $auditTeks = json_encode($semua("SELECT action, before_json, after_json FROM audit_logs WHERE action LIKE 'kesehatan.ringkasan.%'"), JSON_UNESCAPED_UNICODE);
    $assert($bocor($auditTeks) === [] && !str_contains($auditTeks, 'Pembaruan setelah pemeriksaan ulang'), 'audit_logs ringkasan: 0 isi ringkasan, alasan, nama, atau telepon');
    $assert($bocor(json_encode($semua('SELECT * FROM kesehatan_akses_log'), JSON_UNESCAPED_UNICODE)) === [] && $bocor(json_encode($semua("SELECT * FROM kesehatan_idempotensi WHERE operasi LIKE 'ringkasan.%'"), JSON_UNESCAPED_UNICODE)) === [],
        'Log akses dan klaim idempotensi ringkasan: hanya metadata/hash');
    $perubahan = [];
    foreach ($hitungLain as $t => $n) {
        if ($jumlah($t) !== $n) {
            $perubahan[] = $t;
        }
    }
    $assert($perubahan === [], 'Tabel modul lain (PSB, izin, V3, V4, master) tidak berubah jumlah barisnya' . ($perubahan === [] ? '' : ': ' . implode(', ', $perubahan)));
    exec('DB_NAME=' . escapeshellarg($dbName) . ' php ' . escapeshellarg(dirname(__DIR__) . '/bin/v5_phase3_verify.php') . ' 2>&1', $verif, $kodeVerif);
    $assert($kodeVerif === 0, 'Verifier 026 lulus atas seluruh data suite (' . count(preg_grep('/^\[lulus\]/', $verif)) . ' pemeriksaan)');
} finally {
    $jalankan('DROP TRIGGER IF EXISTS v5f3_gagal');
    $jalankan('UPDATE pengaturan_notifikasi SET whatsapp_enabled = 0 WHERE singleton = 1');
    $jalankan("UPDATE pengaturan_notifikasi SET whatsapp_check_status = '" . $db->real_escape_string($awalCekWa) . "' WHERE singleton = 1");
    $jalankan('UPDATE pengaturan_notifikasi SET push_enabled = ' . (int) $awalSetelan['push_enabled'] . ', whatsapp_enabled = ' . (int) $awalSetelan['whatsapp_enabled'] . ' WHERE singleton = 1');
    $jalankan("UPDATE perangkat_push SET push_aktif = 0, dicabut_pada = NOW() WHERE device_label = 'Fixture V5 Fase 3'");
    foreach ($tertunda as $t) {
        $stmt = $db->prepare('UPDATE notifikasi_outbox SET tersedia_pada = ? WHERE id = ?');
        $id = (int) $t['id'];
        $stmt->bind_param('si', $t['tersedia_pada'], $id);
        $stmt->execute();
        $stmt->close();
    }
}

echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
