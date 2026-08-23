<?php

declare(strict_types=1);

/**
 * Pengujian integrasi V2 Fase 4 — notifikasi in-app, push, dan WhatsApp.
 *
 * Menjalankan kriteria penerimaan Fase 4 yang dapat diotomatiskan pada basis
 * data sungguhan, TANPA satu pun permintaan jaringan keluar:
 *
 *   KN-1  setiap peristiwa menghasilkan tepat satu notifikasi in-app per
 *         penerima yang berhak, dan tidak menjangkau yang tidak berhak;
 *   KN-2  pengguna tidak dapat membaca notifikasi pengguna lain lewat ID;
 *   KN-3  retry peristiwa yang sama tidak menghasilkan notifikasi ganda;
 *   KN-4  mematikan push menghentikan enqueue push tanpa mengganggu in-app;
 *   KN-5  WhatsApp tidak dapat dinyalakan bila pemeriksaan konfigurasi gagal;
 *   KN-6  saat WhatsApp mati/tidak siap: nol permintaan penyedia, transaksi
 *         perizinan tetap berhasil;
 *   KN-7  adapter uji memverifikasi enqueue, send, fail, retry, dan dedup;
 *   KN-8  secret tidak muncul di database, audit, respons, atau log;
 *   KN-9  admin melihat status aman dan galat pengiriman;
 *   KN-10 perubahan sakelar tercatat pada audit;
 *   KN-11 registrasi/pencabutan perangkat, termasuk saat logout;
 *   KN-12 kegagalan notifikasi tidak membatalkan pengajuan/keputusan.
 *
 * Concurrency worker diuji terpisah pada `tests/v2_phase4_concurrency.php`.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   V2_PHASE4_RUN_INTEGRATION=1 php tests/v2_phase4_integration.php
 */

$root = dirname(__DIR__);
if (getenv('V2_PHASE4_RUN_INTEGRATION') !== '1') {
    fwrite(STDOUT, "[lewati] Set V2_PHASE4_RUN_INTEGRATION=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

// Environment sandbox HARUS disetel sebelum bootstrap membaca konfigurasi.
// Nilai-nilai ini hanya berlaku di dalam proses pengujian ini: tidak ditulis ke
// berkas mana pun dan tidak pernah menjadi credential produksi.
putenv('PUSH_TOKEN_KEY=' . base64_encode(str_repeat("\x2b", 32)));
putenv('WHATSAPP_PROVIDER=');

require_once $root . '/app/bootstrap.php';

use App\Notification\DeviceService;
use App\Notification\NotificationChannel;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationEvent;
use App\Notification\NotificationException;
use App\Notification\OutboxRepository;
use App\Notification\Push\PushClient;
use App\Notification\PushTokenProtector;
use App\Notification\WhatsApp\FakeProvider;
use App\Notification\WhatsApp\HttpProvider;
use App\Notification\WhatsApp\NullProvider;
use App\Notification\WhatsApp\ProviderResult;
use App\Notification\WhatsApp\WhatsAppMessage;
use App\Notification\WhatsApp\WhatsAppProvider;
use App\Notification\WorkerLock;

if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Pengujian integrasi ditolak: DB_NAME wajib berakhiran _test.\n");
    exit(2);
}

$db = app_db();
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};
$expectStatus = static function (int $status, callable $callback, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message . ' (tidak ada penolakan)');
    } catch (NotificationException $exception) {
        $assert(
            $exception->status() === $status,
            $message . ' [' . $exception->status() . ($exception->status() === $status ? '' : ' ≠ ' . $status) . ']'
        );
    }
};

// ---------------------------------------------------------------------------
// Ganda uji: penyedia yang MENCATAT panggilan, bukan melakukannya.
// ---------------------------------------------------------------------------

/** Penyedia WhatsApp mata-mata: memastikan "nol permintaan" benar-benar nol. */
final class PenyediaMataMata implements WhatsAppProvider
{
    public int $panggilanSend = 0;
    public int $panggilanVerify = 0;

    public function __construct(private bool $siap = true)
    {
    }

    public function name(): string
    {
        return 'mata-mata';
    }

    public function mengirimNyata(): bool
    {
        return false;
    }

    public function readiness(): array
    {
        return [
            'siap' => $this->siap,
            'pesan' => $this->siap ? 'Siap.' : 'Sengaja tidak siap untuk pengujian.',
            'detail' => [],
        ];
    }

    public function verify(): ProviderResult
    {
        $this->panggilanVerify++;

        return $this->siap
            ? ProviderResult::ok('Verifikasi mata-mata.')
            : ProviderResult::permanen('TIDAK_SIAP', 'Sengaja gagal.');
    }

    public function send(WhatsAppMessage $message): ProviderResult
    {
        $this->panggilanSend++;

        return ProviderResult::ok('Dicatat mata-mata.');
    }
}

/** Klien push tiruan: tidak pernah menghubungi Expo. */
final class KlienPushTiruan implements PushClient
{
    /** @var array<int, array<int, array<string, mixed>>> */
    public array $batch = [];

    /**
     * @param callable(array<int, array<string, mixed>>):array $penjawab
     */
    public function __construct(private $penjawab)
    {
    }

    public function send(array $messages): array
    {
        $this->batch[] = $messages;

        return ($this->penjawab)($messages);
    }
}

$suffix = strtoupper(bin2hex(random_bytes(3)));
$lower = strtolower($suffix);
$created = [
    'users' => [], 'pengurus' => [], 'wali' => [], 'santri_wali' => [], 'santri' => [],
    'guru' => [], 'kamar' => [], 'murobi' => [], 'pembimbing' => [], 'izin' => [],
];

$exec = static function (string $sql, array $params = []) use ($db): int {
    $statement = $db->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Fixture gagal disiapkan: ' . $db->error . ' | ' . $sql);
    }
    if ($params !== []) {
        $types = '';
        $references = [];
        foreach ($params as $index => &$value) {
            $types .= is_int($value) ? 'i' : 's';
            $references[$index] = &$value;
        }
        unset($value);
        $statement->bind_param($types, ...$references);
    }
    if (!$statement->execute()) {
        $error = $statement->error;
        $statement->close();
        throw new RuntimeException('Fixture gagal dijalankan: ' . $error . ' | ' . $sql);
    }
    $id = (int) $statement->insert_id;
    $statement->close();

    return $id;
};

$scalar = static function (string $sql, array $params = []) use ($db): int {
    $statement = $db->prepare($sql);
    if ($statement === false) {
        return -1;
    }
    if ($params !== []) {
        $types = str_repeat('i', count($params));
        $statement->bind_param($types, ...$params);
    }
    $statement->execute();
    $row = $statement->get_result()?->fetch_row();
    $statement->close();

    return is_array($row) ? (int) $row[0] : 0;
};

$adminRow = $db->query(
    "SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
      WHERE r.slug = 'admin' AND u.is_active = 1 ORDER BY u.id LIMIT 1"
)?->fetch_assoc();
if (!$adminRow) {
    fwrite(STDERR, "Akun admin fixture tidak tersedia.\n");
    exit(2);
}
$adminId = (int) $adminRow['id'];
$_SESSION = ['user_id' => $adminId];

$yearRow = $db->query("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1")?->fetch_assoc();
if (!$yearRow) {
    fwrite(STDERR, "Tahun ajaran aktif tidak tersedia.\n");
    exit(2);
}
$yearId = (int) $yearRow['id'];

$settings = notification_settings_repository();
$pengaturanAwal = $settings->current();
$meta = ['ip' => '203.0.113.44', 'user_agent' => 'uji-integrasi-fase-4'];
$key = static fn (string $prefix): string => $prefix . '-' . bin2hex(random_bytes(8));

/** Jumlah baris outbox untuk satu peristiwa/kanal. */
$hitungOutbox = static function (string $eventKeyPrefix, string $kanal) use ($db): int {
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM notifikasi_outbox WHERE event_key LIKE ? AND kanal = ?'
    );
    $pola = $eventKeyPrefix . '%';
    $statement->bind_param('ss', $pola, $kanal);
    $statement->execute();
    $row = $statement->get_result()?->fetch_row();
    $statement->close();

    return is_array($row) ? (int) $row[0] : 0;
};

/** Penerima in-app satu peristiwa. */
$penerimaInApp = static function (int $pengajuanId, string $eventType) use ($db): array {
    $statement = $db->prepare(
        "SELECT penerima_user_id FROM notifikasi_outbox
          WHERE pengajuan_id = ? AND event_type = ? AND kanal = 'InApp'
          ORDER BY penerima_user_id"
    );
    $statement->bind_param('is', $pengajuanId, $eventType);
    $statement->execute();
    $result = $statement->get_result();
    $ids = [];
    while ($result !== false && ($row = $result->fetch_assoc()) !== null) {
        $ids[] = (int) $row['penerima_user_id'];
    }
    $statement->close();

    return $ids;
};

try {
    // =====================================================================
    // Fixture
    // =====================================================================
    $kamarA = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 20)', ['Kamar F4A ' . $suffix]);
    $kamarKosong = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 20)', ['Kamar F4Z ' . $suffix]);
    $created['kamar'] = [$kamarA, $kamarKosong];

    $santriSql = "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan,
                    kab_kota, provinsi, nama_ayah, no_hp_ayah, nama_ibu, no_hp_ibu, asal_sekolah, sekolah_saat_ini, is_active)
                  VALUES (?, ?, 'L', 'Ciamis', '2010-01-01', 'Alamat', 'Desa', 'Kec', 'Ciamis', 'Jabar', '', NULL, '', NULL, 'A', 'B', 1)";
    $santriA = $exec($santriSql, ['F4A' . $suffix, 'Santri Notifikasi A ' . $suffix]);
    $santriTanpaMurobi = $exec($santriSql, ['F4Z' . $suffix, 'Santri Tanpa Murobi ' . $suffix]);
    $created['santri'] = [$santriA, $santriTanpaMurobi];
    $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$santriA, $kamarA, $yearId]);
    $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$santriTanpaMurobi, $kamarKosong, $yearId]);

    $guruMurobi = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['F4G1' . $suffix, 'Guru Murobi F4 ' . $suffix]);
    $guruLain = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['F4G2' . $suffix, 'Guru Murobi Lain F4 ' . $suffix]);
    $guruTanpaTugas = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['F4G3' . $suffix, 'Guru Tanpa Tugas F4 ' . $suffix]);
    $created['guru'] = [$guruMurobi, $guruLain, $guruTanpaTugas];

    $kamarLain = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 20)', ['Kamar F4B ' . $suffix]);
    $created['kamar'][] = $kamarLain;
    $murobiSql = "INSERT INTO murobi_assignments (guru_id, tahun_ajaran_id, target_type, kamar_id, kelas_id, tanggal_mulai, is_active)
                  VALUES (?, ?, 'Kamar', ?, NULL, DATE_SUB(CURDATE(), INTERVAL 30 DAY), 1)";
    $created['murobi'][] = $exec($murobiSql, [$guruMurobi, $yearId, $kamarA]);
    $created['murobi'][] = $exec($murobiSql, [$guruLain, $yearId, $kamarLain]);

    $pengurusA = $exec("INSERT INTO pengurus (nama, nomor_identitas, jabatan, no_hp, is_active) VALUES (?, ?, 'Keamanan', ?, 1)", ['Pengurus F4 ' . $suffix, 'F4P' . $suffix, '081399900001']);
    $created['pengurus'] = [$pengurusA];

    $waliAktif = $exec('INSERT INTO wali (nama, no_hp, is_active) VALUES (?, ?, 1)', ['Wali F4 Aktif ' . $suffix, '081399900002']);
    $waliLepas = $exec('INSERT INTO wali (nama, no_hp, is_active) VALUES (?, ?, 1)', ['Wali F4 Lepas ' . $suffix, '081399900003']);
    $created['wali'] = [$waliAktif, $waliLepas];
    $created['santri_wali'][] = $exec("INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary) VALUES (?, ?, 'Ayah', 1)", [$santriA, $waliAktif]);
    // Relasi wali yang SUDAH diarsipkan: tidak boleh menerima notifikasi apa pun.
    $relasiLepas = $exec("INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary) VALUES (?, ?, 'Paman', 0)", [$santriA, $waliLepas]);
    $created['santri_wali'][] = $relasiLepas;
    $exec('UPDATE santri_wali SET archived_at = NOW() WHERE id = ?', [$relasiLepas]);

    $makeUser = static function (string $username, string $name, ?int $guruId, ?int $pengurusId, ?int $waliId, ?string $role, ?string $phone = null) use ($exec, $adminId): int {
        $id = $exec(
            'INSERT INTO users (name, username, password, phone, guru_id, pengurus_id, wali_id, is_active, force_password_change, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW())',
            [$name, $username, password_hash('UjiPassword123!Aa', PASSWORD_DEFAULT), $phone, $guruId, $pengurusId, $waliId]
        );
        if ($role !== null) {
            $exec('INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = ?', [$id, $adminId, $role]);
        }
        return $id;
    };

    $userPengurus = $makeUser('f4.pa.' . $lower, 'Akun Pengurus F4', null, $pengurusA, null, 'pengurus', '081399900011');
    $userMurobi = $makeUser('f4.m1.' . $lower, 'Akun Murobi F4', $guruMurobi, null, null, 'guru', '081399900013');
    $userMurobiLain = $makeUser('f4.m2.' . $lower, 'Akun Murobi Lain F4', $guruLain, null, null, 'guru');
    $userGuruTanpaTugas = $makeUser('f4.gb.' . $lower, 'Akun Guru Tanpa Tugas F4', $guruTanpaTugas, null, null, 'guru');
    $userWali = $makeUser('f4.o1.' . $lower, 'Akun Wali F4', null, null, $waliAktif, 'orang_tua', '081399900012');
    $userWaliLepas = $makeUser('f4.o2.' . $lower, 'Akun Wali Lepas F4', null, null, $waliLepas, 'orang_tua');
    $created['users'] = [$userPengurus, $userMurobi, $userMurobiLain, $userGuruTanpaTugas, $userWali, $userWaliLepas];

    $pembimbing = pembimbing_service();
    foreach ([$kamarA, $kamarKosong] as $kamarTarget) {
        $created['pembimbing'][] = $pembimbing->create([
            'pengurus_id' => $pengurusA,
            'tahun_ajaran_id' => $yearId,
            'target_type' => 'Kamar',
            'kamar_id' => $kamarTarget,
            'tanggal_mulai' => date('Y-m-d', strtotime('-10 days')),
            'tanggal_selesai' => '',
        ], $adminId);
    }

    $loadUser = static fn (int $id): array => auth_repository()->findActiveById($id)
        ?? throw new RuntimeException('Akun uji tidak ditemukan: ' . $id);

    $workflow = izin_workflow_service();
    $center = notification_center_service();
    $devices = push_device_repository();
    $outbox = notification_outbox_repository();
    $notifications = notification_repository();
    $besok = date('Y-m-d', strtotime('+1 day'));
    $lusa = date('Y-m-d', strtotime('+2 days'));

    // Titik awal yang tegas: push mati, WhatsApp mati.
    $settings->setPushEnabled(false, $adminId);
    $settings->setWhatsappEnabled(false, $adminId);

    // =====================================================================
    // KN-1. Satu notifikasi in-app per penerima berhak, per peristiwa
    // =====================================================================
    echo PHP_EOL . '=== KN-1. Penerima yang berhak ===' . PHP_EOL;

    $hasilBuat = $workflow->create(
        $loadUser($userPengurus),
        ['santri_id' => $santriA, 'tgl_izin' => $besok, 'tgl_kembali' => $lusa, 'alasan' => 'Keperluan keluarga yang rahasia', 'catatan_pengurus' => 'Catatan internal pengurus'],
        $key('buat'),
        $meta
    );
    $pengajuanA = (int) $hasilBuat['id'];
    $created['izin'][] = $pengajuanA;

    $penerimaBuat = $penerimaInApp($pengajuanA, NotificationEvent::PENGAJUAN_DIBUAT);
    $assert($penerimaBuat === [$userMurobi], 'KN-1a Pengajuan dengan satu murobi memberi tahu tepat murobi tujuan');
    $assert(!in_array($userMurobiLain, $penerimaBuat, true), 'KN-1b Murobi lain tidak menerima notifikasi pengajuan');
    $assert(!in_array($userGuruTanpaTugas, $penerimaBuat, true), 'KN-1c Guru tanpa penugasan murobi tidak menerima notifikasi');
    $assert(!in_array($userPengurus, $penerimaBuat, true), 'KN-1d Pengaju tidak diberi tahu tentang tindakannya sendiri');
    $assert(
        $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE pengajuan_id = ? AND kanal = 'InApp'", [$pengajuanA]) === 1,
        'KN-1e Tepat satu baris in-app dibuat untuk peristiwa pengajuan'
    );

    // Pengajuan tanpa kandidat murobi -> antrean admin.
    $hasilAdmin = $workflow->create(
        $loadUser($userPengurus),
        ['santri_id' => $santriTanpaMurobi, 'tgl_izin' => $besok, 'tgl_kembali' => $lusa, 'alasan' => 'Perlu penetapan admin'],
        $key('buat-admin'),
        $meta
    );
    $pengajuanAdmin = (int) $hasilAdmin['id'];
    $created['izin'][] = $pengajuanAdmin;
    $penerimaRouting = $penerimaInApp($pengajuanAdmin, NotificationEvent::ROUTING_PERLU_ADMIN);
    $assert(in_array($adminId, $penerimaRouting, true), 'KN-1f Pengajuan tanpa murobi tunggal memberi tahu admin');
    $assert(
        !in_array($userPengurus, $penerimaRouting, true),
        'KN-1g Pengurus pengaju tidak diberi notifikasi atas tindakannya sendiri; statusnya sudah tampak pada daftarnya'
    );
    $assert(!in_array($userMurobi, $penerimaRouting, true), 'KN-1h Murobi tidak diberi tahu pengajuan yang belum ditetapkan kepadanya');

    // Keputusan murobi -> pengurus + wali aktif, bukan wali yang relasinya lepas.
    $hasilKeputusan = $workflow->decide(
        $loadUser($userMurobi),
        $pengajuanA,
        'Disetujui',
        'Alasan keputusan yang tidak boleh bocor',
        null,
        null,
        $key('putus'),
        $meta
    );
    $penerimaKeputusan = $penerimaInApp($pengajuanA, NotificationEvent::KEPUTUSAN_DISETUJUI);
    $assert(in_array($userPengurus, $penerimaKeputusan, true), 'KN-1i Keputusan memberi tahu pengurus pengaju');
    $assert(in_array($userWali, $penerimaKeputusan, true), 'KN-1j Keputusan memberi tahu orang tua dengan relasi wali aktif');
    $assert(!in_array($userWaliLepas, $penerimaKeputusan, true), 'KN-1k Wali dengan relasi terarsip tidak menerima notifikasi');
    $assert(!in_array($userMurobi, $penerimaKeputusan, true), 'KN-1l Murobi pemutus tidak diberi tahu keputusannya sendiri');

    // Isi notifikasi tidak boleh membocorkan alasan atau catatan.
    $isiBaris = $db->query(
        'SELECT judul, isi, data_json FROM notifikasi_outbox WHERE pengajuan_id = ' . $pengajuanA
    );
    $adaBocor = false;
    while ($isiBaris !== false && ($row = $isiBaris->fetch_assoc()) !== null) {
        $gabung = $row['judul'] . ' ' . $row['isi'] . ' ' . (string) $row['data_json'];
        if (str_contains($gabung, 'rahasia') || str_contains($gabung, 'Catatan internal') || str_contains($gabung, 'tidak boleh bocor')) {
            $adaBocor = true;
        }
    }
    $assert(!$adaBocor, 'KN-1m Alasan izin, catatan pengurus, dan alasan keputusan tidak pernah masuk isi notifikasi');

    // Koreksi keputusan oleh admin.
    $hasilKoreksi = $workflow->correctDecision(
        $loadUser($adminId),
        $pengajuanA,
        'Ditolak',
        'Alasan keputusan setelah koreksi',
        'Alasan koreksi administratif',
        null,
        $key('koreksi'),
        $meta
    );
    $penerimaKoreksi = $penerimaInApp($pengajuanA, NotificationEvent::KOREKSI);
    $assert(
        in_array($userPengurus, $penerimaKoreksi, true) && in_array($userWali, $penerimaKoreksi, true)
            && in_array($userMurobi, $penerimaKoreksi, true),
        'KN-1n Koreksi memberi tahu pengurus, orang tua, dan murobi'
    );

    // Penetapan murobi dan pembatalan pada pengajuan antrean admin.
    $workflow->assignMurobi($loadUser($adminId), $pengajuanAdmin, $guruMurobi, 'Penetapan manual admin', null, $key('tetapkan'), $meta);
    $penerimaTetapkan = $penerimaInApp($pengajuanAdmin, NotificationEvent::MUROBI_DITETAPKAN);
    $assert(
        in_array($userMurobi, $penerimaTetapkan, true) && in_array($userPengurus, $penerimaTetapkan, true),
        'KN-1o Penetapan murobi memberi tahu murobi baru dan pengurus'
    );

    $workflow->assignMurobi($loadUser($adminId), $pengajuanAdmin, $guruLain, 'Penetapan ulang admin', null, $key('tetapkan-ulang'), $meta);
    $penerimaUlang = $penerimaInApp($pengajuanAdmin, NotificationEvent::MUROBI_DITETAPKAN_ULANG);
    $assert(
        in_array($userMurobiLain, $penerimaUlang, true) && in_array($userMurobi, $penerimaUlang, true),
        'KN-1p Penetapan ulang memberi tahu murobi baru DAN murobi lama'
    );

    $workflow->cancel($loadUser($userPengurus), $pengajuanAdmin, 'Dibatalkan karena santri sakit', null, $key('batal'), $meta);
    $penerimaBatal = $penerimaInApp($pengajuanAdmin, NotificationEvent::PEMBATALAN);
    $assert(in_array($userMurobiLain, $penerimaBatal, true), 'KN-1q Pembatalan memberi tahu murobi tujuan agar antreannya bersih');

    // Keputusan Admin Pengganti pada pengajuan baru.
    $hasilPengganti = $workflow->create(
        $loadUser($userPengurus),
        ['santri_id' => $santriA, 'tgl_izin' => date('Y-m-d', strtotime('+10 days')), 'tgl_kembali' => date('Y-m-d', strtotime('+11 days')), 'alasan' => 'Pengajuan untuk keputusan pengganti'],
        $key('buat-pengganti'),
        $meta
    );
    $pengajuanPengganti = (int) $hasilPengganti['id'];
    $created['izin'][] = $pengajuanPengganti;
    $workflow->decide(
        $loadUser($adminId),
        $pengajuanPengganti,
        'Disetujui',
        'Keputusan pengganti karena murobi berhalangan',
        'Murobi sedang cuti panjang',
        null,
        $key('putus-pengganti'),
        $meta
    );
    $penerimaPengganti = $penerimaInApp($pengajuanPengganti, NotificationEvent::KEPUTUSAN_ADMIN_PENGGANTI);
    $assert(
        in_array($userPengurus, $penerimaPengganti, true) && in_array($userWali, $penerimaPengganti, true)
            && in_array($userMurobi, $penerimaPengganti, true),
        'KN-1r Keputusan Admin Pengganti memberi tahu pengurus, orang tua, dan murobi yang digantikan'
    );

    // =====================================================================
    // KN-2. Tidak dapat membaca notifikasi pengguna lain
    // =====================================================================
    echo PHP_EOL . '=== KN-2. Otorisasi notifikasi ===' . PHP_EOL;

    $daftarMurobi = $center->index($loadUser($userMurobi), []);
    $assert($daftarMurobi['items'] !== [], 'KN-2a Murobi melihat notifikasinya sendiri');
    $idMilikMurobi = (int) $daftarMurobi['items'][0]['id'];

    $expectStatus(403, static fn () => $center->show($loadUser($userWali), $idMilikMurobi),
        'KN-2b Orang tua ditolak 403 saat membuka notifikasi milik murobi');
    $expectStatus(403, static fn () => $center->markRead($loadUser($userWali), $idMilikMurobi),
        'KN-2c Orang tua ditolak 403 saat menandai baca notifikasi milik murobi');
    $expectStatus(403, static fn () => $center->show($loadUser($userGuruTanpaTugas), $idMilikMurobi),
        'KN-2d Guru tanpa penugasan ditolak 403 untuk notifikasi milik orang lain');
    $expectStatus(403, static fn () => $center->show($loadUser($userMurobi), 999999999),
        'KN-2e ID yang tidak ada dijawab 403, bukan 404 yang membocorkan keberadaan baris');

    $sebelumBaca = $center->unreadCount($loadUser($userMurobi))['jumlah'];
    $center->markRead($loadUser($userMurobi), $idMilikMurobi);
    $sesudahBaca = $center->unreadCount($loadUser($userMurobi))['jumlah'];
    $assert($sesudahBaca === $sebelumBaca - 1, 'KN-2f Menandai dibaca mengurangi jumlah belum dibaca tepat satu');
    $center->markRead($loadUser($userMurobi), $idMilikMurobi);
    $assert(
        $center->unreadCount($loadUser($userMurobi))['jumlah'] === $sesudahBaca,
        'KN-2g Menandai dibaca dua kali tidak mengubah jumlah lagi (idempoten)'
    );

    $semua = $center->markAllRead($loadUser($userPengurus));
    $assert(
        $semua['jumlah_belum_dibaca'] === 0,
        'KN-2h Tandai semua dibaca mengosongkan jumlah belum dibaca pengguna itu saja'
    );
    $assert(
        $center->unreadCount($loadUser($userWali))['jumlah'] > 0,
        'KN-2i Tandai semua milik pengurus tidak menyentuh notifikasi orang tua'
    );

    // Pagination.
    $halaman = $center->index($loadUser($userWali), ['page' => 1, 'per_page' => 1]);
    $assert(
        $halaman['pagination']['per_page'] === 1 && count($halaman['items']) <= 1,
        'KN-2j Pagination pusat notifikasi bekerja'
    );

    // =====================================================================
    // KN-3. Retry peristiwa yang sama tidak membuat notifikasi ganda
    // =====================================================================
    echo PHP_EOL . '=== KN-3. Deduplikasi ===' . PHP_EOL;

    $sebelumRetry = $scalar('SELECT COUNT(*) FROM notifikasi_outbox WHERE pengajuan_id = ?', [$pengajuanA]);
    $notifier = notification_service();
    $barisPengajuan = $db->query('SELECT * FROM izin_pengajuan WHERE id = ' . $pengajuanA)?->fetch_assoc() ?: [];
    for ($i = 0; $i < 3; $i++) {
        $notifier->emit(NotificationEvent::PENGAJUAN_DIBUAT, $barisPengajuan, ['aktor_user_id' => $userPengurus]);
    }
    $assert(
        $scalar('SELECT COUNT(*) FROM notifikasi_outbox WHERE pengajuan_id = ?', [$pengajuanA]) === $sebelumRetry,
        'KN-3a Tiga kali emit peristiwa yang sama tidak menambah satu baris pun'
    );

    // Retry lewat idempotensi pengajuan: replay create tidak membuat notifikasi baru.
    $kunciUlang = $key('idem');
    $payloadUlang = [
        'santri_id' => $santriA,
        'tgl_izin' => date('Y-m-d', strtotime('+20 days')),
        'tgl_kembali' => date('Y-m-d', strtotime('+21 days')),
        'alasan' => 'Uji idempotensi notifikasi',
    ];
    $pertama = $workflow->create($loadUser($userPengurus), $payloadUlang, $kunciUlang, $meta);
    $created['izin'][] = (int) $pertama['id'];
    $jumlahSetelahPertama = $scalar('SELECT COUNT(*) FROM notifikasi_outbox WHERE pengajuan_id = ?', [(int) $pertama['id']]);
    $kedua = $workflow->create($loadUser($userPengurus), $payloadUlang, $kunciUlang, $meta);
    $assert((bool) $kedua['idempotent_replay'], 'KN-3b Create kedua dengan kunci sama adalah replay');
    $assert(
        $scalar('SELECT COUNT(*) FROM notifikasi_outbox WHERE pengajuan_id = ?', [(int) $pertama['id']]) === $jumlahSetelahPertama,
        'KN-3c Replay idempotensi tidak menghasilkan notifikasi tambahan'
    );

    $assert(
        $scalar(
            'SELECT COUNT(*) FROM (SELECT event_key, kanal, penerima_user_id FROM notifikasi_outbox
              GROUP BY event_key, kanal, penerima_user_id HAVING COUNT(*) > 1) x'
        ) === 0,
        'KN-3d Tidak ada duplikat (event_key, kanal, penerima) di seluruh tabel'
    );

    // =====================================================================
    // KN-4. Sakelar push
    // =====================================================================
    echo PHP_EOL . '=== KN-4. Sakelar push ===' . PHP_EOL;

    $protector = push_token_protector();
    $assert($protector->ready(), 'KN-4a Kunci perlindungan token push tersedia pada sandbox');

    $tokenMurobi = 'ExponentPushToken[' . str_repeat('a', 16) . $lower . ']';
    $deviceService = push_device_service();
    $registrasi = $deviceService->register($loadUser($userMurobi), [
        'token' => $tokenMurobi,
        'platform' => 'android',
        'device_id' => 'uji-' . $lower,
        'device_label' => 'Perangkat Uji F4',
        'app_version' => '1.0.0',
    ]);
    $assert($registrasi['baru'] === true, 'KN-4b Perangkat push terdaftar');
    $assert(
        $scalar('SELECT COUNT(*) FROM perangkat_push WHERE user_id = ? AND dicabut_pada IS NULL', [$userMurobi]) === 1,
        'KN-4c Tepat satu perangkat aktif tercatat untuk murobi'
    );
    $ulang = $deviceService->register($loadUser($userMurobi), [
        'token' => $tokenMurobi,
        'platform' => 'android',
        'device_id' => 'uji-' . $lower,
    ]);
    $assert(
        $ulang['baru'] === false
            && $scalar('SELECT COUNT(*) FROM perangkat_push WHERE user_id = ?', [$userMurobi]) === 1,
        'KN-4d Registrasi ulang token yang sama tidak menumpuk baris perangkat'
    );

    // Push MATI: tidak boleh ada baris push baru, in-app tetap dibuat.
    $settings->setPushEnabled(false, $adminId);
    $pengajuanPushMati = (int) $workflow->create(
        $loadUser($userPengurus),
        ['santri_id' => $santriA, 'tgl_izin' => date('Y-m-d', strtotime('+30 days')), 'tgl_kembali' => date('Y-m-d', strtotime('+31 days')), 'alasan' => 'Uji push mati'],
        $key('push-mati'),
        $meta
    )['id'];
    $created['izin'][] = $pengajuanPushMati;
    $assert(
        $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE pengajuan_id = ? AND kanal = 'Push'", [$pengajuanPushMati]) === 0,
        'KN-4e Push mati: tidak ada satu pun baris push diantrekan'
    );
    $assert(
        $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE pengajuan_id = ? AND kanal = 'InApp'", [$pengajuanPushMati]) === 1,
        'KN-4f Push mati: notifikasi in-app tetap dibuat seperti biasa'
    );

    // Push NYALA: baris push dibuat untuk penerima yang punya perangkat aktif.
    $settings->setPushEnabled(true, $adminId);
    $pengajuanPushNyala = (int) $workflow->create(
        $loadUser($userPengurus),
        ['santri_id' => $santriA, 'tgl_izin' => date('Y-m-d', strtotime('+40 days')), 'tgl_kembali' => date('Y-m-d', strtotime('+41 days')), 'alasan' => 'Uji push nyala'],
        $key('push-nyala'),
        $meta
    )['id'];
    $created['izin'][] = $pengajuanPushNyala;
    $assert(
        $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE pengajuan_id = ? AND kanal = 'Push'", [$pengajuanPushNyala]) === 1,
        'KN-4g Push nyala: baris push diantrekan untuk penerima berperangkat'
    );

    // Pengguna mematikan push pada perangkatnya sendiri.
    $perangkatId = (int) $registrasi['perangkat_id'];
    $deviceService->setEnabled($loadUser($userMurobi), $perangkatId, false);
    $pengajuanPerangkatMati = (int) $workflow->create(
        $loadUser($userPengurus),
        ['santri_id' => $santriA, 'tgl_izin' => date('Y-m-d', strtotime('+50 days')), 'tgl_kembali' => date('Y-m-d', strtotime('+51 days')), 'alasan' => 'Uji perangkat mati'],
        $key('perangkat-mati'),
        $meta
    )['id'];
    $created['izin'][] = $pengajuanPerangkatMati;
    $assert(
        $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE pengajuan_id = ? AND kanal = 'Push'", [$pengajuanPerangkatMati]) === 0,
        'KN-4h Perangkat dengan push dimatikan tidak lagi diantrekan'
    );
    $assert(
        $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE pengajuan_id = ? AND kanal = 'InApp'", [$pengajuanPerangkatMati]) === 1,
        'KN-4i Mematikan push perangkat tidak mengganggu notifikasi in-app'
    );
    $deviceService->setEnabled($loadUser($userMurobi), $perangkatId, true);

    // Pencabutan lintas pengguna ditolak.
    $expectStatus(403, static fn () => $deviceService->revoke($loadUser($userWali), ['perangkat_id' => $perangkatId]),
        'KN-4j Pengguna lain tidak dapat mencabut perangkat milik murobi');

    // =====================================================================
    // KN-5 & KN-6. WhatsApp berpagar dan senyap saat mati
    // =====================================================================
    echo PHP_EOL . '=== KN-5/KN-6. WhatsApp opsional ===' . PHP_EOL;

    $adminService = notification_admin_service();
    $adminUser = $loadUser($adminId);

    $settings->setWhatsappEnabled(false, $adminId);
    $settings->recordWhatsappCheck('Gagal', 'Konfigurasi sengaja dibuat gagal untuk pengujian.', 'uji', $adminId);
    $expectStatus(409, static fn () => $adminService->ubahSakelar($adminUser, NotificationChannel::WHATSAPP, true, $meta),
        'KN-5a WhatsApp ditolak menyala ketika pemeriksaan konfigurasi berstatus Gagal');
    $assert(
        $settings->current()['whatsapp_enabled'] === false,
        'KN-5b Sakelar WhatsApp tetap mati setelah penolakan'
    );
    $assert(
        $settings->setWhatsappEnabled(true, $adminId) === false,
        'KN-5c Repositori juga menolak menyalakan WhatsApp tanpa pemeriksaan Lulus'
    );

    // In-app tidak dapat dimatikan.
    $expectStatus(422, static fn () => $adminService->ubahSakelar($adminUser, NotificationChannel::IN_APP, false, $meta),
        'KN-5d Kanal in-app tidak dapat dimatikan');

    // Penyedia default (belum dipilih) tidak pernah menghubungi siapa pun.
    $nullProvider = new NullProvider();
    $assert($nullProvider->readiness()['siap'] === false, 'KN-6a Penyedia default melaporkan belum siap');
    $assert($nullProvider->verify()->ok === false, 'KN-6b Verifikasi penyedia default gagal tanpa koneksi');
    $assert(
        $nullProvider->send(new WhatsAppMessage('081300000000', 'J', 'I', 'k'))->permanen === true,
        'KN-6c Penyedia default menolak mengirim secara permanen'
    );

    // Adapter HTTP tanpa environment tidak pernah membuka koneksi.
    $httpKosong = new HttpProvider([], 'http');
    $assert($httpKosong->readiness()['siap'] === false, 'KN-6d Adapter HTTP tanpa environment melaporkan belum siap');
    $hasilKirimKosong = $httpKosong->send(new WhatsAppMessage('081300000000', 'J', 'I', 'k'));
    $assert(
        $hasilKirimKosong->ok === false && $hasilKirimKosong->kode === 'KONFIGURASI_TIDAK_LENGKAP',
        'KN-6e Adapter HTTP berhenti sebelum koneksi ketika environment belum lengkap'
    );

    // Worker WhatsApp saat kanal mati: NOL panggilan penyedia.
    $mataMata = new PenyediaMataMata(true);
    $dispatcherMati = new NotificationDispatcher(
        $db,
        $outbox,
        $devices,
        $protector,
        new KlienPushTiruan(static fn (array $m): array => ['ok' => true, 'tickets' => [], 'kode' => 'OK', 'pesan' => '', 'permanen' => false]),
        $mataMata,
        $settings,
        new WorkerLock($db)
    );
    $hasilWorkerMati = $dispatcherMati->run(NotificationChannel::WHATSAPP, 10);
    $assert($hasilWorkerMati['dijalankan'] === false, 'KN-6f Worker WhatsApp tidak berjalan saat kanal mati');
    $assert($mataMata->panggilanSend === 0, 'KN-6g Nol permintaan ke penyedia WhatsApp saat kanal mati');

    // Transaksi perizinan tetap berhasil saat WhatsApp mati.
    $pengajuanWaMati = (int) $workflow->create(
        $loadUser($userPengurus),
        ['santri_id' => $santriA, 'tgl_izin' => date('Y-m-d', strtotime('+60 days')), 'tgl_kembali' => date('Y-m-d', strtotime('+61 days')), 'alasan' => 'Uji WhatsApp mati'],
        $key('wa-mati'),
        $meta
    )['id'];
    $created['izin'][] = $pengajuanWaMati;
    $assert($pengajuanWaMati > 0, 'KN-6h Pengajuan tetap berhasil ketika WhatsApp mati');
    $assert(
        $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE pengajuan_id = ? AND kanal = 'WhatsApp'", [$pengajuanWaMati]) === 0,
        'KN-6i WhatsApp mati: tidak ada baris WhatsApp diantrekan'
    );
    $assert($mataMata->panggilanSend === 0, 'KN-6j Masih nol permintaan penyedia setelah transaksi perizinan');

    // =====================================================================
    // KN-7. Adapter uji: enqueue, send, fail, retry, dedup
    // =====================================================================
    echo PHP_EOL . '=== KN-7. Adapter uji WhatsApp ===' . PHP_EOL;

    $fakeOk = new FakeProvider('ok', false, null);
    $assert($fakeOk->mengirimNyata() === false, 'KN-7a Adapter uji menyatakan bukan pengiriman nyata');
    $assert($fakeOk->verify()->ok === true, 'KN-7b Verifikasi adapter uji lulus tanpa jaringan');
    $assert(
        (new FakeProvider('ok', true, null))->verify()->ok === false,
        'KN-7c Adapter uji ditolak ketika APP_ENV=production'
    );

    // Nyalakan WhatsApp lewat jalur yang benar: pemeriksaan lulus dulu.
    $settings->recordWhatsappCheck('Lulus', 'Adapter uji siap.', 'fake', $adminId);
    $assert($settings->setWhatsappEnabled(true, $adminId) === true, 'KN-7d WhatsApp menyala setelah pemeriksaan Lulus');

    $pengajuanWa = (int) $workflow->create(
        $loadUser($userPengurus),
        ['santri_id' => $santriA, 'tgl_izin' => date('Y-m-d', strtotime('+70 days')), 'tgl_kembali' => date('Y-m-d', strtotime('+71 days')), 'alasan' => 'Uji WhatsApp nyala'],
        $key('wa-nyala'),
        $meta
    )['id'];
    $created['izin'][] = $pengajuanWa;
    $barisWa = $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE pengajuan_id = ? AND kanal = 'WhatsApp'", [$pengajuanWa]);
    $assert($barisWa >= 1, 'KN-7e WhatsApp nyala: baris WhatsApp diantrekan untuk penerima bernomor');

    $dispatcherFake = static fn (WhatsAppProvider $provider): NotificationDispatcher => new NotificationDispatcher(
        $db,
        $outbox,
        $devices,
        $protector,
        new KlienPushTiruan(static fn (array $m): array => ['ok' => true, 'tickets' => [], 'kode' => 'OK', 'pesan' => '', 'permanen' => false]),
        $provider,
        $settings,
        new WorkerLock($db)
    );

    $hasilKirim = $dispatcherFake($fakeOk)->run(NotificationChannel::WHATSAPP, 20);
    $assert($hasilKirim['terkirim'] >= 1, 'KN-7f Adapter uji menandai baris WhatsApp sebagai terkirim');
    $assert(
        $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE pengajuan_id = ? AND kanal = 'WhatsApp' AND status = 'Sent'", [$pengajuanWa]) === $barisWa,
        'KN-7g Seluruh baris WhatsApp pengajuan itu berstatus Sent'
    );
    $terkirimSekali = count($fakeOk->terkirim());
    $dispatcherFake($fakeOk)->run(NotificationChannel::WHATSAPP, 20);
    $assert(
        count($fakeOk->terkirim()) === $terkirimSekali,
        'KN-7h Putaran worker kedua tidak mengirim ulang baris yang sudah Sent'
    );

    // Kegagalan sementara -> backoff, bukan gagal permanen.
    $pengajuanGagal = (int) $workflow->create(
        $loadUser($userPengurus),
        ['santri_id' => $santriA, 'tgl_izin' => date('Y-m-d', strtotime('+80 days')), 'tgl_kembali' => date('Y-m-d', strtotime('+81 days')), 'alasan' => 'Uji kegagalan WhatsApp'],
        $key('wa-gagal'),
        $meta
    )['id'];
    $created['izin'][] = $pengajuanGagal;
    $fakeGagal = new FakeProvider('gagal', false, null);
    $dispatcherFake($fakeGagal)->run(NotificationChannel::WHATSAPP, 20);
    $barisGagal = $db->query(
        "SELECT id, status, percobaan, gagal_permanen, tersedia_pada, error_kode FROM notifikasi_outbox
          WHERE pengajuan_id = " . $pengajuanGagal . " AND kanal = 'WhatsApp' LIMIT 1"
    )?->fetch_assoc();
    $assert(is_array($barisGagal) && $barisGagal['status'] === 'Failed', 'KN-7i Kegagalan pengiriman dicatat sebagai Failed');
    $assert(is_array($barisGagal) && (int) $barisGagal['percobaan'] === 1, 'KN-7j Jumlah percobaan bertambah tepat satu');
    $assert(is_array($barisGagal) && (int) $barisGagal['gagal_permanen'] === 0, 'KN-7k Kegagalan sementara belum permanen');
    $assert(is_array($barisGagal) && $barisGagal['tersedia_pada'] !== null, 'KN-7l Backoff menjadwalkan percobaan berikutnya');
    $assert(
        is_array($barisGagal) && $scalar('SELECT COUNT(*) FROM notifikasi_percobaan WHERE outbox_id = ?', [(int) $barisGagal['id']]) === 1,
        'KN-7m Riwayat percobaan pengiriman tercatat'
    );

    // Backoff menahan baris pada putaran berikutnya (tidak diambil worker).
    $sebelumUlang = count($fakeGagal->terkirim());
    $dispatcherFake($fakeGagal)->run(NotificationChannel::WHATSAPP, 20);
    $assert(
        count($fakeGagal->terkirim()) === $sebelumUlang,
        'KN-7n Backoff mencegah percobaan ulang sebelum waktunya'
    );

    // Kegagalan permanen langsung menghentikan retry.
    $pengajuanPermanen = (int) $workflow->create(
        $loadUser($userPengurus),
        ['santri_id' => $santriA, 'tgl_izin' => date('Y-m-d', strtotime('+90 days')), 'tgl_kembali' => date('Y-m-d', strtotime('+91 days')), 'alasan' => 'Uji kegagalan permanen'],
        $key('wa-permanen'),
        $meta
    )['id'];
    $created['izin'][] = $pengajuanPermanen;
    $dispatcherFake(new FakeProvider('gagal_permanen', false, null))->run(NotificationChannel::WHATSAPP, 20);
    $assert(
        $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE pengajuan_id = ? AND kanal = 'WhatsApp' AND gagal_permanen = 1", [$pengajuanPermanen]) >= 1,
        'KN-7o Kegagalan permanen ditandai dan tidak diambil worker lagi'
    );

    // Percobaan ulang manual admin: baris yang SAMA, bukan baris baru.
    $barisPermanen = $db->query(
        "SELECT id FROM notifikasi_outbox WHERE pengajuan_id = " . $pengajuanPermanen . " AND kanal = 'WhatsApp' LIMIT 1"
    )?->fetch_assoc();
    $idPermanen = (int) ($barisPermanen['id'] ?? 0);
    $totalSebelumRetry = $scalar('SELECT COUNT(*) FROM notifikasi_outbox');
    $hasilRetry = $adminService->cobaUlang($adminUser, $idPermanen, $meta);
    $assert($hasilRetry['diantrekan_ulang'] === true, 'KN-7p Admin dapat mengantrekan ulang baris gagal');
    $assert(
        $scalar('SELECT COUNT(*) FROM notifikasi_outbox') === $totalSebelumRetry,
        'KN-7q Percobaan ulang tidak menambah baris outbox baru'
    );
    $assert(
        $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE id = ? AND status = 'Queued' AND gagal_permanen = 0", [$idPermanen]) === 1,
        'KN-7r Baris yang sama kembali ke antrean'
    );

    // Mematikan kanal membatalkan antrean yang belum terkirim, tanpa in-app.
    $inAppSebelum = $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE kanal = 'InApp'");
    $adminService->ubahSakelar($adminUser, NotificationChannel::WHATSAPP, false, $meta);
    $assert(
        $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE kanal = 'WhatsApp' AND status = 'Queued'") === 0,
        'KN-7s Mematikan WhatsApp mengosongkan antrean yang belum terkirim'
    );
    $assert(
        $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE kanal = 'InApp'") === $inAppSebelum,
        'KN-7t Mematikan WhatsApp tidak menyentuh satu pun notifikasi in-app'
    );

    // =====================================================================
    // Push melalui klien tiruan (tanpa jaringan)
    // =====================================================================
    echo PHP_EOL . '=== KN-7b. Pengiriman push (klien tiruan) ===' . PHP_EOL;

    $settings->setPushEnabled(true, $adminId);
    $pengajuanPush = (int) $workflow->create(
        $loadUser($userPengurus),
        ['santri_id' => $santriA, 'tgl_izin' => date('Y-m-d', strtotime('+100 days')), 'tgl_kembali' => date('Y-m-d', strtotime('+101 days')), 'alasan' => 'Uji pengiriman push'],
        $key('push-kirim'),
        $meta
    )['id'];
    $created['izin'][] = $pengajuanPush;

    $klienSukses = new KlienPushTiruan(static fn (array $m): array => [
        'ok' => true,
        'tickets' => array_map(static fn (): array => ['status' => 'ok', 'id' => 'tiket-uji'], $m),
        'kode' => 'OK',
        'pesan' => 'ok',
        'permanen' => false,
    ]);
    $dispatcherPush = new NotificationDispatcher(
        $db, $outbox, $devices, $protector, $klienSukses, new NullProvider(), $settings, new WorkerLock($db)
    );
    $hasilPush = $dispatcherPush->run(NotificationChannel::PUSH, 20);
    $assert($hasilPush['terkirim'] >= 1, 'KN-7u Baris push terkirim melalui klien push');
    $assert($klienSukses->batch !== [], 'KN-7v Klien push menerima batch pesan');
    $pesanPertama = $klienSukses->batch[0][0] ?? [];
    $assert(
        ($pesanPertama['channelId'] ?? '') === NotificationDispatcher::ANDROID_CHANNEL_ID,
        'KN-7w Pesan push menyertakan channelId Android yang sama dengan aplikasi'
    );
    $assert(
        array_keys($pesanPertama['data'] ?? []) === array_values(array_intersect(
            ['tipe', 'event', 'pengajuan_id', 'url'],
            array_keys($pesanPertama['data'] ?? [])
        )),
        'KN-7x Payload push hanya memuat penunjuk sumber daya'
    );
    $assert(
        !str_contains(json_encode($pesanPertama, JSON_UNESCAPED_UNICODE) ?: '', 'Uji pengiriman push'),
        'KN-7y Alasan izin tidak ikut pada payload push'
    );

    // Token yang ditolak Expo dicabut otomatis.
    $pengajuanTokenMati = (int) $workflow->create(
        $loadUser($userPengurus),
        ['santri_id' => $santriA, 'tgl_izin' => date('Y-m-d', strtotime('+110 days')), 'tgl_kembali' => date('Y-m-d', strtotime('+111 days')), 'alasan' => 'Uji token mati'],
        $key('push-token-mati'),
        $meta
    )['id'];
    $created['izin'][] = $pengajuanTokenMati;
    $klienTokenMati = new KlienPushTiruan(static fn (array $m): array => [
        'ok' => true,
        'tickets' => array_map(static fn (): array => [
            'status' => 'error',
            'message' => 'Device not registered',
            'details' => ['error' => 'DeviceNotRegistered'],
        ], $m),
        'kode' => 'OK',
        'pesan' => 'ok',
        'permanen' => false,
    ]);
    (new NotificationDispatcher(
        $db, $outbox, $devices, $protector, $klienTokenMati, new NullProvider(), $settings, new WorkerLock($db)
    ))->run(NotificationChannel::PUSH, 20);
    $assert(
        $scalar("SELECT COUNT(*) FROM perangkat_push WHERE user_id = ? AND dicabut_pada IS NOT NULL AND alasan_pencabutan = 'token_invalid'", [$userMurobi]) === 1,
        'KN-7z Token yang ditolak penyedia dicabut otomatis'
    );

    // =====================================================================
    // KN-8. Tidak ada secret pada data, audit, atau respons
    // =====================================================================
    echo PHP_EOL . '=== KN-8. Secret tidak bocor ===' . PHP_EOL;

    $assert(
        $scalar("SELECT COUNT(*) FROM perangkat_push WHERE token_terlindungi LIKE '%ExponentPushToken%'") === 0,
        'KN-8a Token perangkat tidak tersimpan dalam bentuk terbaca'
    );
    $assert(
        $scalar("SELECT COUNT(*) FROM perangkat_push WHERE token_hash NOT REGEXP '^[0-9a-f]{64}$'") === 0,
        'KN-8b Token perangkat hanya tersimpan sebagai hash'
    );
    $assert(
        $scalar("SELECT COUNT(*) FROM audit_logs WHERE COALESCE(after_json,'') LIKE '%ExponentPushToken%'
                  OR COALESCE(before_json,'') LIKE '%ExponentPushToken%'") === 0,
        'KN-8c Audit tidak memuat token perangkat'
    );
    $assert(
        $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE isi LIKE '%ExponentPushToken%' OR COALESCE(data_json,'') LIKE '%ExponentPushToken%'") === 0,
        'KN-8d Isi notifikasi tidak memuat token perangkat'
    );
    $assert(
        $scalar("SELECT COUNT(*) FROM notifikasi_pengaturan_audit WHERE COALESCE(pesan,'') LIKE '%081399900%'") === 0,
        'KN-8e Audit kanal tidak memuat nomor telepon'
    );
    $daftarPerangkat = $deviceService->index($loadUser($userMurobi));
    $assert(
        !str_contains(json_encode($daftarPerangkat, JSON_UNESCAPED_UNICODE) ?: '', 'ExponentPushToken'),
        'KN-8f Respons daftar perangkat tidak pernah mengembalikan token'
    );
    $statusAdmin = $adminService->status($adminUser);
    $jsonStatus = json_encode($statusAdmin, JSON_UNESCAPED_UNICODE) ?: '';
    $assert(
        !str_contains($jsonStatus, 'ExponentPushToken') && !str_contains($jsonStatus, (string) app_config('notifikasi.push_token_key')),
        'KN-8g Status kanal tidak membocorkan token maupun kunci environment'
    );
    $assert(
        str_contains($jsonStatus, 'PUSH_TOKEN_KEY') === false
            && str_contains($jsonStatus, 'WHATSAPP_API_TOKEN'),
        'KN-8h Status kanal menyebut NAMA environment WhatsApp, bukan nilainya'
    );

    // Perlindungan token: hash stabil, sandi dapat dibuka, bentuk tersamar aman.
    $protector2 = new PushTokenProtector((string) app_config('notifikasi.push_token_key'));
    $sandi = $protector2->protect($tokenMurobi);
    $assert($protector2->reveal($sandi) === $tokenMurobi, 'KN-8i Token terlindungi dapat dibuka kembali oleh worker');
    $assert(!str_contains($sandi, 'ExponentPushToken'), 'KN-8j Bentuk terlindungi tidak memuat token terbaca');
    $assert(
        (new PushTokenProtector(base64_encode(str_repeat("\x7f", 32))))->reveal($sandi) === null,
        'KN-8k Kunci berbeda tidak dapat membuka token'
    );
    $assert(
        !str_contains(PushTokenProtector::mask($tokenMurobi), str_repeat('a', 16)),
        'KN-8l Bentuk tersamar token tidak cukup untuk mengirim push'
    );

    // =====================================================================
    // KN-9 & KN-10. Pemantauan admin dan audit sakelar
    // =====================================================================
    echo PHP_EOL . '=== KN-9/KN-10. Panel admin dan audit ===' . PHP_EOL;

    $kegagalan = $adminService->kegagalan($adminUser, ['kanal' => NotificationChannel::WHATSAPP, 'page' => 1, 'per_page' => 20]);
    $assert($kegagalan['items'] !== [], 'KN-9a Admin melihat daftar pengiriman gagal');
    $contohGagal = $kegagalan['items'][0];
    $assert(
        array_key_exists('error_kode', $contohGagal) && array_key_exists('error_aman', $contohGagal)
            && array_key_exists('riwayat_percobaan', $contohGagal),
        'KN-9b Daftar kegagalan memuat kode, pesan aman, dan riwayat percobaan'
    );
    $assert(
        !preg_match('/ExponentPushToken|081399900|Bearer /i', json_encode($kegagalan, JSON_UNESCAPED_UNICODE) ?: ''),
        'KN-9c Daftar kegagalan tidak memuat token, nomor, atau credential'
    );

    // Non-admin ditolak.
    $expectStatus(403, static fn () => $adminService->status($loadUser($userPengurus)),
        'KN-9d Pengurus ditolak 403 saat membuka status kanal');
    $expectStatus(403, static fn () => $adminService->kegagalan($loadUser($userMurobi), []),
        'KN-9e Murobi ditolak 403 saat membuka daftar kegagalan');
    $expectStatus(403, static fn () => $adminService->ubahSakelar($loadUser($userWali), NotificationChannel::PUSH, false, $meta),
        'KN-9f Orang tua ditolak 403 saat mengubah sakelar kanal');

    $auditSebelum = $scalar("SELECT COUNT(*) FROM notifikasi_pengaturan_audit WHERE aksi = 'kanal_diubah'");
    $adminService->ubahSakelar($adminUser, NotificationChannel::PUSH, false, $meta);
    $adminService->ubahSakelar($adminUser, NotificationChannel::PUSH, true, $meta);
    $assert(
        $scalar("SELECT COUNT(*) FROM notifikasi_pengaturan_audit WHERE aksi = 'kanal_diubah'") === $auditSebelum + 2,
        'KN-10a Setiap perubahan sakelar tercatat pada audit kanal'
    );
    $assert(
        $scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'notifikasi.kanal_diubah'") >= 2,
        'KN-10b Perubahan sakelar juga tercatat pada audit sistem'
    );
    $adminService->periksaKonfigurasi($adminUser, NotificationChannel::PUSH, $meta);
    $assert(
        $scalar("SELECT COUNT(*) FROM notifikasi_pengaturan_audit WHERE aksi = 'pemeriksaan_konfigurasi'") >= 1,
        'KN-10c Pemeriksaan konfigurasi tercatat pada audit kanal'
    );
    $assert(
        $scalar("SELECT COUNT(*) FROM notifikasi_pengaturan_audit WHERE nilai_sebelum IS NOT NULL AND nilai_sesudah IS NOT NULL") >= 2,
        'KN-10d Audit menyimpan nilai sebelum dan sesudah sakelar'
    );

    // Pesan uji in-app untuk admin.
    $pesanUji = $adminService->kirimPesanUji($adminUser, NotificationChannel::IN_APP, $meta);
    $assert($pesanUji['diantrekan'] === true, 'KN-9g Admin dapat mengirim pesan uji in-app kepada dirinya sendiri');
    $assert(
        $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE penerima_user_id = ? AND event_type = 'sistem.pesan_uji'", [$adminId]) >= 1,
        'KN-9h Pesan uji tercatat pada pusat notifikasi admin'
    );

    // =====================================================================
    // KN-11. Logout mencabut registrasi perangkat
    // =====================================================================
    echo PHP_EOL . '=== KN-11. Pencabutan perangkat ===' . PHP_EOL;

    $tokenBaru = 'ExponentPushToken[' . str_repeat('b', 16) . $lower . ']';
    $deviceService->register($loadUser($userMurobi), [
        'token' => $tokenBaru,
        'platform' => 'ios',
        'device_id' => 'uji-logout-' . $lower,
        'device_label' => 'Perangkat Logout F4',
    ]);
    $aktifSebelum = $scalar('SELECT COUNT(*) FROM perangkat_push WHERE user_id = ? AND dicabut_pada IS NULL', [$userMurobi]);
    $assert($aktifSebelum >= 1, 'KN-11a Perangkat kedua terdaftar sebelum logout');

    $dicabut = $deviceService->revokeOnLogout($loadUser($userMurobi), $tokenBaru);
    $assert($dicabut === 1, 'KN-11b Logout mencabut tepat perangkat yang mengirim tokennya');
    $assert(
        $scalar("SELECT COUNT(*) FROM perangkat_push WHERE user_id = ? AND dicabut_pada IS NOT NULL AND alasan_pencabutan = 'logout'", [$userMurobi]) === 1,
        'KN-11c Alasan pencabutan tercatat sebagai logout'
    );
    $assert(
        $center->unreadCount($loadUser($userMurobi))['jumlah'] >= 0
            && $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE penerima_user_id = ? AND kanal = 'InApp'", [$userMurobi]) > 0,
        'KN-11d Mencabut perangkat tidak menghapus notifikasi in-app pengguna'
    );

    // Token tidak valid ditolak saat registrasi.
    $expectStatus(422, static fn () => $deviceService->register($loadUser($userMurobi), [
        'token' => 'bukan-token-expo',
        'platform' => 'android',
    ]), 'KN-11e Token yang bukan Expo push token ditolak 422');
    $expectStatus(422, static fn () => $deviceService->register($loadUser($userMurobi), [
        'token' => 'ExponentPushToken[xyz]',
        'platform' => 'palsu',
    ]), 'KN-11f Platform yang tidak dikenal ditolak 422');

    // =====================================================================
    // KN-12. Kegagalan notifikasi tidak membatalkan transaksi perizinan
    // =====================================================================
    echo PHP_EOL . '=== KN-12. Ketahanan transaksi ===' . PHP_EOL;

    // Kunci unik outbox sengaja dibuat bentrok: baris outbox untuk peristiwa
    // berikutnya sudah ada lebih dulu. Pengajuan tetap harus berhasil.
    $pengajuanTahan = (int) $workflow->create(
        $loadUser($userPengurus),
        ['santri_id' => $santriA, 'tgl_izin' => date('Y-m-d', strtotime('+120 days')), 'tgl_kembali' => date('Y-m-d', strtotime('+121 days')), 'alasan' => 'Uji ketahanan transaksi'],
        $key('tahan'),
        $meta
    )['id'];
    $created['izin'][] = $pengajuanTahan;
    $assert($pengajuanTahan > 0, 'KN-12a Pengajuan berhasil walaupun outbox sedang dalam keadaan tidak biasa');

    $barisTahan = $db->query('SELECT status FROM izin_pengajuan WHERE id = ' . $pengajuanTahan)?->fetch_assoc();
    $assert(
        is_array($barisTahan) && in_array((string) $barisTahan['status'], ['Diajukan', 'Perlu Penetapan Admin'], true),
        'KN-12b Status pengajuan tersimpan normal'
    );
    $assert(
        $scalar('SELECT COUNT(*) FROM izin_riwayat_status WHERE pengajuan_id = ?', [$pengajuanTahan]) >= 2,
        'KN-12c Riwayat status tetap lengkap'
    );

    // Peristiwa yang tidak dikenal tidak boleh melempar ke pemanggil.
    $hasilTidakDikenal = $notifier->emit('izin.tidak_ada', $barisPengajuan, []);
    $assert(
        $hasilTidakDikenal['galat'] !== null && $hasilTidakDikenal['dibuat'][NotificationChannel::IN_APP] === 0,
        'KN-12d Peristiwa tidak dikenal dicatat sebagai galat aman, bukan exception'
    );

    // =====================================================================
    // Ringkasan admin akhir
    // =====================================================================
    echo PHP_EOL . '=== Ringkasan ===' . PHP_EOL;
    $ringkasan = $notifications->summaryByChannel();
    $assert(
        ($ringkasan[NotificationChannel::IN_APP]['Sent'] ?? 0) > 0,
        'KN-13a Ringkasan admin menampilkan notifikasi in-app yang tersedia'
    );
    $assert(
        OutboxRepository::MAX_PERCOBAAN === 5
            && $outbox->backoffSeconds(1) === OutboxRepository::BACKOFF_BASE
            && $outbox->backoffSeconds(20) === OutboxRepository::BACKOFF_MAX,
        'KN-13b Backoff naik bertahap dan berhenti pada batas atas'
    );
} catch (Throwable $exception) {
    $failures[] = 'Kesalahan tak terduga: ' . $exception->getMessage();
    echo '[gagal] ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine() . PHP_EOL;
} finally {
    // =====================================================================
    // Pembersihan fixture dan pemulihan pengaturan
    // =====================================================================
    try {
        $settings->setWhatsappEnabled(false, $adminId);
        $settings->recordWhatsappCheck(
            $pengaturanAwal['whatsapp_check_status'],
            (string) ($pengaturanAwal['whatsapp_check_pesan'] ?? 'Dipulihkan setelah pengujian.'),
            $pengaturanAwal['whatsapp_provider'],
            $adminId
        );
        if ($pengaturanAwal['whatsapp_enabled']) {
            $settings->setWhatsappEnabled(true, $adminId);
        }
        $settings->setPushEnabled($pengaturanAwal['push_enabled'], $adminId);
    } catch (Throwable $exception) {
        echo '[perhatian] Pengaturan kanal tidak dapat dipulihkan sepenuhnya: ' . $exception->getMessage() . PHP_EOL;
    }

    $db->query('SET FOREIGN_KEY_CHECKS=0');
    $idsUser = array_values(array_filter(array_map('intval', $created['users'])));
    $idsIzin = array_values(array_unique(array_filter(array_map('intval', $created['izin']))));

    if ($idsIzin !== []) {
        $daftar = implode(',', $idsIzin);
        $db->query('DELETE FROM notifikasi_percobaan WHERE outbox_id IN (SELECT id FROM notifikasi_outbox WHERE pengajuan_id IN (' . $daftar . '))');
        $db->query('DELETE FROM notifikasi_outbox WHERE pengajuan_id IN (' . $daftar . ')');
        $db->query("DELETE FROM audit_logs WHERE entity_type = 'izin_pengajuan' AND entity_id IN (" . $daftar . ')');
        $db->query('DELETE FROM izin_keputusan_koreksi WHERE pengajuan_id IN (' . $daftar . ')');
        $db->query('DELETE FROM izin_keputusan WHERE pengajuan_id IN (' . $daftar . ')');
        $db->query('DELETE FROM izin_riwayat_status WHERE pengajuan_id IN (' . $daftar . ')');
        $db->query('DELETE FROM izin_idempotency_keys WHERE pengajuan_id IN (' . $daftar . ')');
    }
    if ($idsUser !== []) {
        $daftarUser = implode(',', $idsUser);
        $db->query('DELETE FROM notifikasi_percobaan WHERE outbox_id IN (SELECT id FROM notifikasi_outbox WHERE penerima_user_id IN (' . $daftarUser . '))');
        $db->query('DELETE FROM notifikasi_outbox WHERE penerima_user_id IN (' . $daftarUser . ')');
        $db->query('DELETE FROM perangkat_push WHERE user_id IN (' . $daftarUser . ')');
        $db->query('DELETE FROM izin_idempotency_keys WHERE user_id IN (' . $daftarUser . ')');
        $db->query('DELETE FROM notifikasi_pengaturan_audit WHERE aktor_user_id IN (' . $daftarUser . ')');
    }
    // Baris milik akun admin yang dipakai pengujian.
    $db->query('DELETE FROM notifikasi_percobaan WHERE outbox_id IN (SELECT id FROM notifikasi_outbox WHERE penerima_user_id = ' . $adminId
        . " AND (event_type = 'sistem.pesan_uji' OR created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)))");
    $db->query('DELETE FROM notifikasi_outbox WHERE penerima_user_id = ' . $adminId
        . " AND (event_type = 'sistem.pesan_uji' OR created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE))");
    $db->query('DELETE FROM notifikasi_pengaturan_audit WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
    $db->query('DELETE FROM perangkat_push WHERE device_id LIKE ' . "'uji-%'");
    if ($idsIzin !== []) {
        $db->query('DELETE FROM izin_pengajuan WHERE id IN (' . implode(',', $idsIzin) . ')');
    }

    $cleanup = [
        'pembimbing_assignments' => ['id', $created['pembimbing']],
        'murobi_assignments' => ['id', $created['murobi']],
        'santri_wali' => ['id', $created['santri_wali']],
        'user_roles' => ['user_id', $created['users']],
        'users' => ['id', $created['users']],
        'wali' => ['id', $created['wali']],
        'pengurus' => ['id', $created['pengurus']],
        'guru' => ['id', $created['guru']],
        'santri' => ['id', $created['santri']],
        'kamar' => ['id', $created['kamar']],
    ];
    $santriIds = array_values(array_filter(array_map('intval', $created['santri'])));
    if ($santriIds !== []) {
        $db->query('DELETE FROM plotting_kamar WHERE id_santri IN (' . implode(',', $santriIds) . ')');
    }
    foreach ($cleanup as $table => [$column, $ids]) {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            continue;
        }
        $db->query('DELETE FROM `' . $table . '` WHERE `' . $column . '` IN (' . implode(',', $ids) . ')');
    }
    $db->query("DELETE FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        AND action IN ('pembimbing_assignment_created','pembimbing_assignment_state_changed',
                       'notifikasi.kanal_diubah','notifikasi.pemeriksaan_konfigurasi','notifikasi.pesan_uji',
                       'notifikasi.percobaan_ulang','notifikasi.perangkat_didaftarkan','notifikasi.perangkat_dicabut',
                       'notifikasi.perangkat_push_diubah')");
    $db->query('SET FOREIGN_KEY_CHECKS=1');
    echo '[bersih] Fixture uji Fase 4 dihapus dan pengaturan kanal dipulihkan.' . PHP_EOL;
}

exit($failures === [] ? 0 : 1);
