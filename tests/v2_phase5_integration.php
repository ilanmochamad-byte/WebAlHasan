<?php

declare(strict_types=1);

/**
 * Pengujian integrasi V2 Fase 5 — laporan, cetak, ekspor, dan receipt push.
 *
 * Menjalankan kriteria penerimaan Fase 5 yang dapat diotomatiskan pada basis
 * data sungguhan, TANPA satu pun permintaan jaringan keluar:
 *
 *   KL-1  ringkasan, detail, cetak, dan CSV konsisten untuk filter yang sama;
 *   KL-2  isolasi cakupan admin/pengurus/murobi/orang tua ditegakkan server;
 *   KL-3  parameter yang berusaha memperluas cakupan ditolak 403;
 *   KL-4  setiap filter PRD benar-benar mempersempit hasil;
 *   KL-5  median durasi keputusan dihitung benar;
 *   KL-6  CSV memuat SELURUH hasil filter dan menetralkan formula injection;
 *   KL-7  cetak memuat identitas, filter, pembuat, waktu, keputusan, halaman;
 *   KL-8  input tidak valid ditolak 422 tanpa membocorkan data;
 *   KL-9  receipt push akhir direkonsiliasi tanpa mengirim ulang pesan;
 *   KL-10 saat push mati, tidak ada satu pun permintaan ke penyedia.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   V2_PHASE5_RUN_INTEGRATION=1 php tests/v2_phase5_integration.php
 */

$root = dirname(__DIR__);
if (getenv('V2_PHASE5_RUN_INTEGRATION') !== '1') {
    fwrite(STDOUT, "[lewati] Set V2_PHASE5_RUN_INTEGRATION=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

// Kunci sandbox; hanya berlaku di dalam proses ini dan tidak pernah ditulis.
putenv('PUSH_TOKEN_KEY=' . base64_encode(str_repeat("\x37", 32)));
putenv('WHATSAPP_PROVIDER=');

require_once $root . '/app/bootstrap.php';

use App\Api\ApiException;
use App\Izin\IzinException;
use App\Notification\NotificationDispatcher;
use App\Notification\OutboxRepository;
use App\Notification\Push\PushClient;
use App\Notification\Push\PushReceiptClient;
use App\Notification\WorkerLock;
use App\Report\IzinCsvExport;
use App\Report\IzinReportFilter;

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

/** Menegaskan sebuah panggilan ditolak dengan status HTTP tertentu. */
$expectStatus = static function (int $status, callable $callback, string $message) use ($assert): void {
    try {
        $callback();
        $assert(false, $message . ' (tidak ada penolakan)');
    } catch (ApiException | IzinException $exception) {
        $aktual = $exception->status();
        $assert($aktual === $status, $message . ' [' . $aktual . ($aktual === $status ? '' : ' ≠ ' . $status) . ']');
    }
};

$exec = static function (string $sql, array $params = []) use ($db): int {
    $statement = $db->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Query gagal disiapkan: ' . $db->error . ' | ' . substr($sql, 0, 120));
    }
    if ($params !== []) {
        $types = '';
        $refs = [];
        foreach ($params as $i => &$v) {
            $types .= is_int($v) ? 'i' : 's';
            $refs[$i] = &$v;
        }
        unset($v);
        $statement->bind_param($types, ...$refs);
    }
    if (!$statement->execute()) {
        $error = $statement->error;
        $statement->close();
        throw new RuntimeException('Query gagal: ' . $error . ' | ' . substr($sql, 0, 120));
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

    return is_array($row) ? (int) $row[0] : -1;
};

/**
 * Klien push tiruan yang MENCATAT panggilan, bukan melakukannya.
 * Mendukung tiket awal sekaligus receipt akhir (Fase 5).
 */
final class KlienPushTiruan implements PushClient, PushReceiptClient
{
    public int $panggilanSend = 0;
    public int $panggilanReceipt = 0;
    /** @var array<int, array<int, string>> */
    public array $idDiminta = [];
    /** @var array<string, array<string, mixed>> */
    public array $receipts = [];
    private int $urutan = 0;

    public function send(array $messages): array
    {
        $this->panggilanSend++;
        $tickets = [];
        foreach ($messages as $_) {
            $this->urutan++;
            $tickets[] = ['status' => 'ok', 'id' => 'TIKET-UJI-' . $this->urutan];
        }

        return ['ok' => true, 'tickets' => $tickets, 'kode' => 'OK', 'pesan' => 'ok', 'permanen' => false];
    }

    public function getReceipts(array $ticketIds): array
    {
        $this->panggilanReceipt++;
        $this->idDiminta[] = array_values($ticketIds);
        $hasil = [];
        foreach ($ticketIds as $id) {
            if (array_key_exists($id, $this->receipts)) {
                $hasil[$id] = $this->receipts[$id];
            }
        }

        return ['ok' => true, 'receipts' => $hasil, 'kode' => 'OK', 'pesan' => 'ok', 'permanen' => false];
    }
}

$adminRow = $db->query(
    "SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
      WHERE r.slug = 'admin' AND u.is_active = 1 ORDER BY u.id LIMIT 1"
)?->fetch_assoc();
if (!$adminRow) {
    fwrite(STDERR, "Akun admin fixture tidak tersedia. Jalankan bin/v2_phase3_sandbox_seed.php lebih dulu.\n");
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

$suffix = strtoupper(bin2hex(random_bytes(4)));
$lower = strtolower($suffix);
$created = [
    'izin' => [], 'users' => [], 'santri' => [], 'kamar' => [], 'kelas' => [],
    'guru' => [], 'pengurus' => [], 'wali' => [], 'santri_wali' => [],
    'murobi' => [], 'pembimbing' => [], 'plotting_kamar' => [], 'plotting_kelas' => [],
];

$laporan = izin_report_service();
$loadUser = static fn (int $id): array => auth_repository()->findActiveById($id)
    ?? throw new RuntimeException('Akun uji tidak ditemukan: ' . $id);

try {
    // =====================================================================
    // Fixture
    // =====================================================================
    echo '=== Menyiapkan fixture Fase 5 (' . $suffix . ') ===' . PHP_EOL;

    $kamarA = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 30)', ['F5 Kamar A ' . $suffix]);
    $kamarB = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 30)', ['F5 Kamar B ' . $suffix]);
    $created['kamar'] = [$kamarA, $kamarB];

    $kelasA = $exec("INSERT INTO kelas (nama_kelas, jenjang, is_active) VALUES (?, 'Uji', 1)", ['F5 Kelas A ' . $suffix]);
    $created['kelas'] = [$kelasA];

    $guruA = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['F5G1' . $suffix, 'F5 Murobi A ' . $suffix]);
    $guruB = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['F5G2' . $suffix, 'F5 Murobi B ' . $suffix]);
    $created['guru'] = [$guruA, $guruB];

    $murobiSql = "INSERT INTO murobi_assignments (guru_id, tahun_ajaran_id, target_type, kamar_id, tanggal_mulai, is_active)
                  VALUES (?, ?, 'Kamar', ?, DATE_SUB(CURDATE(), INTERVAL 60 DAY), 1)";
    $created['murobi'][] = $exec($murobiSql, [$guruA, $yearId, $kamarA]);
    $created['murobi'][] = $exec($murobiSql, [$guruB, $yearId, $kamarB]);

    $pengurusA = $exec("INSERT INTO pengurus (nama, nomor_identitas, jabatan, is_active) VALUES (?, ?, 'Keamanan', 1)", ['F5 Pengurus A ' . $suffix, 'F5PA' . $suffix]);
    $pengurusB = $exec("INSERT INTO pengurus (nama, nomor_identitas, jabatan, is_active) VALUES (?, ?, 'Keamanan', 1)", ['F5 Pengurus B ' . $suffix, 'F5PB' . $suffix]);
    $created['pengurus'] = [$pengurusA, $pengurusB];

    $santriSql = 'INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa,
                        kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, asal_sekolah, sekolah_saat_ini, is_active)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)';
    $buatSantri = static fn (string $nis, string $nama): int => $exec($santriSql, [
        $nis, $nama, 'L', 'Kota Uji', '2010-05-05', 'Alamat uji', 'Desa', 'Kecamatan',
        'Kabupaten', 'Provinsi', 'Ayah', 'Ibu', 'SD Uji', 'MTs Uji',
    ]);

    $santriA1 = $buatSantri('F5-' . $suffix . '-1', 'F5 Santri A1 ' . $suffix);
    $santriA2 = $buatSantri('F5-' . $suffix . '-2', 'F5 Santri A2 ' . $suffix);
    $santriB1 = $buatSantri('F5-' . $suffix . '-3', 'F5 Santri B1 ' . $suffix);
    // Nama BERBAHAYA untuk uji formula injection dengan data sungguhan.
    $santriJahat = $buatSantri('=CMD-' . $suffix, '=HYPERLINK("http://jahat.example","klik")');
    $created['santri'] = [$santriA1, $santriA2, $santriB1, $santriJahat];

    foreach ([$santriA1, $santriA2, $santriJahat] as $s) {
        $created['plotting_kamar'][] = $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$s, $kamarA, $yearId]);
    }
    $created['plotting_kamar'][] = $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$santriB1, $kamarB, $yearId]);
    $created['plotting_kelas'][] = $exec(
        "INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun, status) VALUES (?, ?, ?, 'Aktif')",
        [$santriA1, $kelasA, $yearId]
    );

    $waliA = $exec('INSERT INTO wali (nama, is_active) VALUES (?, 1)', ['F5 Wali A ' . $suffix]);
    $waliB = $exec('INSERT INTO wali (nama, is_active) VALUES (?, 1)', ['F5 Wali B ' . $suffix]);
    $created['wali'] = [$waliA, $waliB];
    $created['santri_wali'][] = $exec("INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary) VALUES (?, ?, 'Ayah', 1)", [$santriA1, $waliA]);
    $created['santri_wali'][] = $exec("INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary) VALUES (?, ?, 'Ayah', 1)", [$santriB1, $waliB]);

    $makeUser = static function (string $username, string $name, ?int $guruId, ?int $pengurusId, ?int $waliId, ?string $role) use ($exec, $adminId): int {
        $id = $exec(
            'INSERT INTO users (name, username, password, guru_id, pengurus_id, wali_id, is_active, force_password_change, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW())',
            [$name, $username, password_hash('UjiPassword123!Aa', PASSWORD_DEFAULT), $guruId, $pengurusId, $waliId]
        );
        if ($role !== null) {
            $exec('INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = ?', [$id, $adminId, $role]);
        }

        return $id;
    };

    $userPengurusA = $makeUser('f5.pa.' . $lower, 'Akun Pengurus A F5', null, $pengurusA, null, 'pengurus');
    $userPengurusB = $makeUser('f5.pb.' . $lower, 'Akun Pengurus B F5', null, $pengurusB, null, 'pengurus');
    $userMurobiA = $makeUser('f5.ma.' . $lower, 'Akun Murobi A F5', $guruA, null, null, 'guru');
    $userMurobiB = $makeUser('f5.mb.' . $lower, 'Akun Murobi B F5', $guruB, null, null, 'guru');
    $userWaliA = $makeUser('f5.oa.' . $lower, 'Akun Wali A F5', null, null, $waliA, 'orang_tua');
    $userWaliB = $makeUser('f5.ob.' . $lower, 'Akun Wali B F5', null, null, $waliB, 'orang_tua');
    $created['users'] = [$userPengurusA, $userPengurusB, $userMurobiA, $userMurobiB, $userWaliA, $userWaliB];

    $pembimbing = pembimbing_service();
    $created['pembimbing'][] = $pembimbing->create([
        'pengurus_id' => $pengurusA, 'tahun_ajaran_id' => $yearId, 'target_type' => 'Kamar',
        'kamar_id' => $kamarA, 'tanggal_mulai' => date('Y-m-d', strtotime('-30 days')), 'tanggal_selesai' => '',
    ], $adminId);
    $created['pembimbing'][] = $pembimbing->create([
        'pengurus_id' => $pengurusB, 'tahun_ajaran_id' => $yearId, 'target_type' => 'Kamar',
        'kamar_id' => $kamarB, 'tanggal_mulai' => date('Y-m-d', strtotime('-30 days')), 'tanggal_selesai' => '',
    ], $adminId);

    /**
     * Menyisipkan pengajuan langsung dengan waktu terkendali.
     *
     * Alur kerja normal (workflow service) diuji Fase 2-4. Di sini yang diuji
     * adalah LAPORAN, sehingga waktu pengajuan/keputusan perlu ditentukan
     * persis agar median durasi dapat diperiksa terhadap nilai yang diketahui.
     */
    $buatPengajuan = static function (
        int $santriId,
        int $pengurusId,
        ?int $murobiGuruId,
        string $status,
        string $tglIzin,
        string $tglKembali,
        string $alasan,
        ?string $diajukanPada = null,
        ?int $durasiMenit = null
    ) use ($exec, $yearId, &$created): int {
        $diajukan = $diajukanPada ?? date('Y-m-d H:i:s', strtotime($tglIzin . ' -1 day 08:00:00'));
        $id = $exec(
            'INSERT INTO izin_pengajuan
                (is_legacy, santri_id, pengurus_id, murobi_guru_id, routing_kandidat, tahun_ajaran_id,
                 tgl_izin, tgl_kembali, alasan, status, version, idempotency_key, diajukan_pada)
             VALUES (0, ?, ?, ?, 1, ?, ?, ?, ?, ?, 1, ?, ?)',
            [$santriId, $pengurusId, $murobiGuruId, $yearId, $tglIzin, $tglKembali, $alasan, $status,
             'F5-' . bin2hex(random_bytes(6)), $diajukan]
        );
        $created['izin'][] = $id;

        if ($durasiMenit !== null && ($status === 'Disetujui' || $status === 'Ditolak')) {
            $exec(
                'INSERT INTO izin_keputusan
                    (pengajuan_id, hasil, alasan, diputus_oleh_user_id, kapasitas, diputus_pada, pengajuan_version, idempotency_key)
                 VALUES (?, ?, ?, NULL, ?, ?, 1, ?)',
                [$id, $status, 'Alasan keputusan uji F5', 'Murobi',
                 date('Y-m-d H:i:s', strtotime($diajukan . ' +' . $durasiMenit . ' minutes')),
                 'F5-KP-' . bin2hex(random_bytes(6))]
            );
        }

        return $id;
    };

    // Rentang uji terisolasi jauh dari fixture lain.
    $basis = '2029-03-01';
    $tgl = static fn (int $offset): string => date('Y-m-d', strtotime($basis . ' +' . $offset . ' days'));

    /**
     * Penanda unik fixture ini.
     *
     * Rentang tanggal saja TIDAK cukup untuk isolasi: berkas uji lain (dan
     * fixture sandbox) juga membuat pengajuan bertanggal jauh ke depan, dan
     * cakupan ADMIN melihat SEMUANYA. Tanpa penanda ini, jumlah yang diharapkan
     * berubah tergantung berkas uji mana yang kebetulan berjalan lebih dulu —
     * kegagalan palsu yang menyesatkan auditor.
     *
     * Penanda disisipkan ke setiap `alasan` dan ikut pada NIS santri berbahaya,
     * lalu dipakai sebagai filter `q` pada seluruh perhitungan di bawah.
     */
    $tanda = 'F5' . $suffix;
    $al = static fn (string $teks): string => $teks . ' ' . $tanda;

    // Pengurus A / Murobi A / Kamar A — durasi 60, 120, 180 menit → median 120.
    $p1 = $buatPengajuan($santriA1, $pengurusA, $guruA, 'Disetujui', $tgl(0), $tgl(1), $al('Alasan uji satu'), null, 60);
    $p2 = $buatPengajuan($santriA2, $pengurusA, $guruA, 'Disetujui', $tgl(2), $tgl(3), $al('Alasan uji dua'), null, 120);
    $p3 = $buatPengajuan($santriA1, $pengurusA, $guruA, 'Ditolak', $tgl(4), $tgl(5), $al('Alasan uji tiga'), null, 180);
    // Tanpa keputusan → tidak boleh ikut perhitungan median.
    $p4 = $buatPengajuan($santriA2, $pengurusA, $guruA, 'Diajukan', $tgl(6), $tgl(7), $al('Alasan uji empat'));
    // Santri dengan nama berbahaya.
    $p5 = $buatPengajuan($santriJahat, $pengurusA, $guruA, 'Dibatalkan', $tgl(8), $tgl(9), $al('=SUM(1+1) alasan berbahaya'));
    // Pengurus B / Murobi B / Kamar B — cakupan berbeda.
    $p6 = $buatPengajuan($santriB1, $pengurusB, $guruB, 'Disetujui', $tgl(0), $tgl(1), $al('Alasan uji enam'), null, 600);

    // `q` adalah bagian dari rentang baku: seluruh perhitungan di bawah hanya
    // melihat fixture ini, apa pun isi basis data uji selebihnya.
    $rentang = ['date_from' => $tgl(0), 'date_to' => $tgl(10), 'q' => $tanda];
    $adminUser = $loadUser($adminId);

    echo '[ok] Fixture siap: 6 pengajuan pada rentang ' . $tgl(0) . ' s.d. ' . $tgl(10) . PHP_EOL;

    // =====================================================================
    // KL-1. Konsistensi ringkasan / detail / cetak / CSV
    // =====================================================================
    echo PHP_EOL . '=== KL-1. Konsistensi total antar permukaan ===' . PHP_EOL;

    $skenarioFilter = [
        'seluruh rentang' => $rentang,
        'status Disetujui' => $rentang + ['status' => 'Disetujui'],
        'kamar A' => $rentang + ['kamar_id' => (string) $kamarA],
        'murobi A' => $rentang + ['murobi_guru_id' => (string) $guruA],
        'basis keputusan' => $rentang + ['basis_tanggal' => 'keputusan'],
        'durasi >= 2 jam' => $rentang + ['durasi_min_jam' => '2'],
    ];

    foreach ($skenarioFilter as $nama => $input) {
        // Halaman kecil DENGAN SENGAJA: membuktikan CSV/cetak tidak mengikuti pagination.
        $rep = $laporan->report($adminUser, $input + ['per_page' => 2]);
        $doc = $laporan->document($adminUser, $input);
        $csvHasil = $laporan->csv($adminUser, $input);
        $cetak = $laporan->printHtml($adminUser, $input);

        $total = (int) $rep['ringkasan']['total'];
        $assert(
            $total === (int) $doc['jumlah_baris'],
            'KL-1 [' . $nama . '] total ringkasan (' . $total . ') = jumlah baris dokumen (' . $doc['jumlah_baris'] . ')'
        );
        $assert(
            $total === (int) $csvHasil['jumlah_baris'],
            'KL-1 [' . $nama . '] total ringkasan = jumlah baris CSV (' . $csvHasil['jumlah_baris'] . ')'
        );
        $assert(
            $total === (int) $cetak['jumlah_baris'],
            'KL-1 [' . $nama . '] total ringkasan = jumlah baris cetak'
        );
        $assert(
            $total === array_sum($rep['ringkasan']['per_status']),
            'KL-1 [' . $nama . '] jumlah per status = total ringkasan'
        );
        $assert(
            count(array_unique([$rep['kriteria'], $doc['kriteria'], $csvHasil['kriteria'], $cetak['kriteria']])) === 1,
            'KL-1 [' . $nama . '] keempat permukaan memakai sidik jari kriteria yang sama'
        );
        // Baris CSV sesungguhnya (tanpa header, tanpa baris kosong akhir).
        $barisCsv = array_values(array_filter(explode("\n", trim($csvHasil['konten'])), static fn (string $b): bool => trim($b) !== ''));
        $assert(
            count($barisCsv) === $total + 1,
            'KL-1 [' . $nama . '] berkas CSV memuat ' . $total . ' baris data + 1 header'
        );
        if ($total > 2) {
            $assert(
                count($rep['items']) === 2 && $doc['jumlah_baris'] > count($rep['items']),
                'KL-1 [' . $nama . '] halaman dibatasi 2 baris tetapi ekspor tetap seluruh hasil'
            );
        }
    }

    // =====================================================================
    // KL-2. Isolasi cakupan
    // =====================================================================
    echo PHP_EOL . '=== KL-2. Isolasi cakupan ===' . PHP_EOL;

    $totalUntuk = static function (int $userId, array $input = []) use ($laporan, $loadUser, $rentang): int {
        return (int) $laporan->report($loadUser($userId), $input + $rentang)['ringkasan']['total'];
    };

    $totalAdmin = (int) $laporan->report($adminUser, $rentang)['ringkasan']['total'];
    $assert($totalAdmin === 6, 'KL-2a Admin melihat seluruh 6 pengajuan pada rentang uji');
    $assert($totalUntuk($userPengurusA) === 5, 'KL-2b Pengurus A hanya melihat 5 pengajuan miliknya');
    $assert($totalUntuk($userPengurusB) === 1, 'KL-2c Pengurus B hanya melihat 1 pengajuan miliknya');
    $assert($totalUntuk($userMurobiA) === 5, 'KL-2d Murobi A hanya melihat pengajuan yang diarahkan kepadanya');
    $assert($totalUntuk($userMurobiB) === 1, 'KL-2e Murobi B hanya melihat pengajuan yang diarahkan kepadanya');
    // Wali A hanya terhubung ke santri A1, yang memiliki dua pengajuan (p1, p3).
    $itemWaliA = $laporan->report($loadUser($userWaliA), $rentang + ['per_page' => 100])['items'];
    $santriWaliA = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['santri_id'], $itemWaliA)));
    $assert($totalUntuk($userWaliA) === 2, 'KL-2f Orang tua A hanya melihat 2 pengajuan santri terhubung (A1)');
    $assert($santriWaliA === [$santriA1], 'KL-2f2 Seluruh baris orang tua A hanya milik santri yang terhubung');
    $assert($totalUntuk($userWaliB) === 1, 'KL-2g Orang tua B hanya melihat pengajuan santri terhubung (B1)');

    // Isi baris, bukan hanya jumlah: ID di luar cakupan tidak boleh muncul.
    $idPengurusB = array_map(
        static fn (array $r): int => (int) $r['id'],
        $laporan->report($loadUser($userPengurusB), $rentang + ['per_page' => 100])['items']
    );
    $assert($idPengurusB === [$p6], 'KL-2h Detail Pengurus B hanya memuat pengajuannya sendiri');
    $idWaliB = array_map(
        static fn (array $r): int => (int) $r['id'],
        $laporan->report($loadUser($userWaliB), $rentang + ['per_page' => 100])['items']
    );
    $assert($idWaliB === [$p6], 'KL-2i Detail orang tua B hanya memuat santri terhubung');

    // CSV dan cetak juga terbatas cakupan — bukan hanya tampilan daftar.
    $csvPengurusB = $laporan->csv($loadUser($userPengurusB), $rentang);
    $assert((int) $csvPengurusB['jumlah_baris'] === 1, 'KL-2j CSV pengurus B hanya memuat barisnya sendiri');
    $assert(
        !str_contains($csvPengurusB['konten'], 'Alasan uji satu'),
        'KL-2k CSV pengurus B tidak memuat data pengurus lain'
    );
    $cetakWaliB = $laporan->printHtml($loadUser($userWaliB), $rentang);
    $assert(
        !str_contains($cetakWaliB['html'], 'F5 Santri A1'),
        'KL-2l Cetak orang tua B tidak memuat santri yang tidak terhubung'
    );

    // =====================================================================
    // KL-3. Parameter tidak dapat memperluas cakupan
    // =====================================================================
    echo PHP_EOL . '=== KL-3. Parameter tidak memperluas cakupan ===' . PHP_EOL;

    $expectStatus(403, static fn () => $laporan->report($loadUser($userPengurusA), $rentang + ['pengurus_id' => (string) $pengurusB]),
        'KL-3a Pengurus A ditolak saat meminta laporan pengurus B');
    $expectStatus(403, static fn () => $laporan->report($loadUser($userMurobiA), $rentang + ['murobi_guru_id' => (string) $guruB]),
        'KL-3b Murobi A ditolak saat meminta laporan murobi B');
    $expectStatus(403, static fn () => $laporan->csv($loadUser($userPengurusA), $rentang + ['pengurus_id' => (string) $pengurusB]),
        'KL-3c Penolakan berlaku juga pada ekspor CSV');
    $expectStatus(403, static fn () => $laporan->printHtml($loadUser($userMurobiA), $rentang + ['murobi_guru_id' => (string) $guruB]),
        'KL-3d Penolakan berlaku juga pada halaman cetak');

    // `mode` bukan hak akses: meminta mode admin dari akun orang tua tetap
    // menghasilkan cakupan orang tua, bukan 500 dan bukan data admin.
    $paksaMode = $laporan->report($loadUser($userWaliB), $rentang, 'admin');
    $assert(
        $paksaMode['cakupan']['mode'] === 'orang_tua' && (int) $paksaMode['ringkasan']['total'] === 1,
        'KL-3e Parameter mode=admin dari akun orang tua tidak memberi hak admin'
    );
    $paksaModePengurus = $laporan->report($loadUser($userPengurusB), $rentang, 'murobi');
    $assert(
        $paksaModePengurus['cakupan']['mode'] === 'pengurus',
        'KL-3f Mode yang tidak dimiliki akun diabaikan dan kembali ke cakupan sah'
    );

    // Orang tua meminta santri milik orang lain: hasilnya kosong, bukan bocor.
    $ortuSantriLain = $laporan->report($loadUser($userWaliB), $rentang + ['santri_id' => (string) $santriA1]);
    $assert(
        (int) $ortuSantriLain['ringkasan']['total'] === 0 && $ortuSantriLain['items'] === [],
        'KL-3g Orang tua yang memfilter santri orang lain menerima hasil kosong'
    );

    // =====================================================================
    // KL-4. Setiap filter benar-benar mempersempit
    // =====================================================================
    echo PHP_EOL . '=== KL-4. Filter PRD ===' . PHP_EOL;

    $totalFilter = static fn (array $input): int => (int) $laporan->report($adminUser, $input + $rentang)['ringkasan']['total'];

    $assert($totalFilter(['status' => 'Disetujui']) === 3, 'KL-4a Filter status Disetujui menghasilkan 3');
    $assert($totalFilter(['status' => 'Dibatalkan']) === 1, 'KL-4b Filter status Dibatalkan menghasilkan 1');
    $assert($totalFilter(['santri_id' => (string) $santriA1]) === 2, 'KL-4c Filter santri menghasilkan 2');
    $assert($totalFilter(['pengurus_id' => (string) $pengurusB]) === 1, 'KL-4d Filter pengurus menghasilkan 1');
    $assert($totalFilter(['murobi_guru_id' => (string) $guruB]) === 1, 'KL-4e Filter murobi menghasilkan 1');
    $assert($totalFilter(['kamar_id' => (string) $kamarA]) === 5, 'KL-4f Filter kamar A menghasilkan 5');
    $assert($totalFilter(['kamar_id' => (string) $kamarB]) === 1, 'KL-4g Filter kamar B menghasilkan 1');
    $assert($totalFilter(['kelas_id' => (string) $kelasA]) === 2, 'KL-4h Filter kelas menghasilkan 2 (santri A1)');
    $assert($totalFilter(['tahun_ajaran_id' => (string) $yearId]) === 6, 'KL-4i Filter tahun ajaran aktif menghasilkan 6');
    $assert($totalFilter(['sumber' => 'v2']) === 6, 'KL-4j Filter sumber V2 menghasilkan 6');
    $assert($totalFilter(['sumber' => 'legacy']) === 0, 'KL-4k Filter data warisan menghasilkan 0 pada fixture V2');
    $assert($totalFilter(['durasi_min_jam' => '2']) === 3, 'KL-4l Durasi minimal 2 jam menghasilkan 3 (120, 180, 600 menit)');
    $assert($totalFilter(['durasi_maks_jam' => '2']) === 2, 'KL-4m Durasi maksimal 2 jam menghasilkan 2 (60, 120 menit)');
    $assert($totalFilter(['durasi_min_jam' => '2', 'durasi_maks_jam' => '3']) === 2, 'KL-4n Rentang durasi 2-3 jam menghasilkan 2');
    $assert($totalFilter(['q' => 'uji enam ' . $tanda]) === 1, 'KL-4o Pencarian teks pada alasan mempersempit hasil');
    // Basis keputusan: yang diuji adalah SIFATNYA, bukan angka hafalan —
    // setiap baris WAJIB punya keputusan, dan tanggal keputusannya WAJIB
    // berada di dalam rentang filter (bukan tanggal izinnya).
    $itemKeputusan = $laporan->report($adminUser, $rentang + ['basis_tanggal' => 'keputusan', 'per_page' => 100])['items'];
    $semuaAdaKeputusan = array_reduce(
        $itemKeputusan,
        static fn (bool $acc, array $r): bool => $acc && $r['diputus_pada'] !== null,
        true
    );
    $semuaDalamRentang = array_reduce(
        $itemKeputusan,
        static fn (bool $acc, array $r): bool => $acc
            && substr((string) $r['diputus_pada'], 0, 10) >= $tgl(0)
            && substr((string) $r['diputus_pada'], 0, 10) <= $tgl(10),
        true
    );
    $assert($itemKeputusan !== [], 'KL-4p Basis tanggal keputusan menghasilkan baris');
    $assert($semuaAdaKeputusan, 'KL-4p2 Basis keputusan hanya memuat pengajuan yang SUDAH diputus');
    $assert($semuaDalamRentang, 'KL-4p3 Basis keputusan menyaring berdasarkan tanggal keputusan, bukan tanggal izin');
    // Rentang yang diperlebar ke belakang wajib menangkap lebih banyak keputusan,
    // membuktikan filter benar-benar memakai kolom keputusan.
    $lebar = (int) $laporan->report($adminUser, [
        'date_from' => date('Y-m-d', strtotime($tgl(0) . ' -10 days')),
        'date_to' => $tgl(10),
        'basis_tanggal' => 'keputusan',
        'q' => $tanda,
    ])['ringkasan']['total'];
    $assert($lebar === 4, 'KL-4p4 Rentang diperlebar menangkap seluruh 4 keputusan fixture');
    $assert($totalFilter(['basis_tanggal' => 'pengajuan']) >= 1, 'KL-4q Basis tanggal pengajuan menghasilkan baris');
    // Kanal notifikasi: fixture ini menyisipkan pengajuan langsung tanpa outbox,
    // sehingga filter kanal harus menghasilkan NOL — bukan seluruh baris.
    $assert($totalFilter(['kanal' => 'Push']) === 0, 'KL-4r Filter kanal Push menghasilkan 0 pada pengajuan tanpa notifikasi');
    $exec(
        "INSERT INTO notifikasi_outbox (event_key, event_type, kanal, penerima_user_id, pengajuan_id, judul, isi, status)
         VALUES (?, 'uji_f5', 'Push', ?, ?, 'Judul uji', 'Isi uji', 'Queued')",
        ['f5-kanal-' . bin2hex(random_bytes(6)), $userMurobiA, $p1]
    );
    $assert($totalFilter(['kanal' => 'Push']) === 1, 'KL-4s Filter kanal Push menemukan pengajuan yang punya baris Push');
    $assert($totalFilter(['kanal' => 'WhatsApp']) === 0, 'KL-4t Filter kanal WhatsApp tetap 0 (tidak ada baris WhatsApp)');

    // Rentang tanggal benar-benar membatasi.
    $assert(
        (int) $laporan->report($adminUser, ['date_from' => $tgl(0), 'date_to' => $tgl(1), 'q' => $tanda])['ringkasan']['total'] === 2,
        'KL-4u Rentang tanggal sempit membatasi hasil'
    );

    // =====================================================================
    // KL-5. Median durasi keputusan
    // =====================================================================
    echo PHP_EOL . '=== KL-5. Median durasi keputusan ===' . PHP_EOL;

    // Cakupan murobi A: keputusan 60, 120, 180 menit → median 120 menit.
    $durasiA = $laporan->report($loadUser($userMurobiA), $rentang)['durasi'];
    $assert((int) $durasiA['jumlah'] === 3, 'KL-5a Hanya keputusan dengan kedua waktu yang dihitung (3 dari 5)');
    $assert((int) $durasiA['median_detik'] === 120 * 60, 'KL-5b Median ganjil = nilai tengah (120 menit)');
    $assert((int) $durasiA['min_detik'] === 60 * 60, 'KL-5c Durasi tercepat 60 menit');
    $assert((int) $durasiA['maks_detik'] === 180 * 60, 'KL-5d Durasi terlama 180 menit');
    $assert($durasiA['median_label'] === '2 jam', 'KL-5e Label median manusiawi: ' . $durasiA['median_label']);

    // Cakupan admin: 60, 120, 180, 600 → genap → median (120+180)/2 = 150 menit.
    $durasiAdmin = $laporan->report($adminUser, $rentang)['durasi'];
    $assert((int) $durasiAdmin['jumlah'] === 4, 'KL-5f Admin menghitung 4 keputusan');
    $assert((int) $durasiAdmin['median_detik'] === 150 * 60, 'KL-5g Median genap = rata-rata dua nilai tengah (150 menit)');

    // Tanpa keputusan sama sekali → tidak tersedia, BUKAN nol.
    $durasiKosong = $laporan->report($adminUser, $rentang + ['status' => 'Diajukan'])['durasi'];
    $assert(
        $durasiKosong['median_detik'] === null && $durasiKosong['median_label'] === 'Tidak tersedia',
        'KL-5h Tanpa keputusan, median dilaporkan Tidak tersedia dan bukan 0'
    );

    // =====================================================================
    // KL-6. CSV
    // =====================================================================
    echo PHP_EOL . '=== KL-6. Ekspor CSV ===' . PHP_EOL;

    $csvSemua = $laporan->csv($adminUser, $rentang);
    $konten = $csvSemua['konten'];

    $assert(str_starts_with($konten, "\xEF\xBB\xBF"), 'KL-6a CSV diawali BOM UTF-8');
    $barisSemua = array_values(array_filter(explode("\n", trim($konten)), static fn (string $b): bool => trim($b) !== ''));
    $assert(count($barisSemua) === 7, 'KL-6b CSV memuat 6 baris data + 1 header');

    $headerBaris = str_getcsv(ltrim($barisSemua[0], "\xEF\xBB\xBF"));
    $assert($headerBaris === IzinCsvExport::HEADERS, 'KL-6c Baris header CSV sama persis dengan konstanta terdokumentasi');

    // Formula injection dengan DATA SUNGGUHAN dari basis data.
    $assert(
        str_contains($konten, '"\'=HYPERLINK(""http://jahat.example"",""klik"")"')
            || str_contains($konten, "'=HYPERLINK"),
        'KL-6d Nama santri berawalan = dinetralkan dengan kutip tunggal'
    );
    $assert(
        str_contains($konten, "'=SUM(1+1) alasan berbahaya"),
        'KL-6e Alasan izin berawalan = dinetralkan'
    );
    $assert(
        str_contains($konten, "'=CMD-"),
        'KL-6f NIS berawalan = dinetralkan'
    );
    // Tidak boleh ada sel yang MASIH diawali karakter formula.
    $selBerbahaya = 0;
    foreach (array_slice($barisSemua, 1) as $baris) {
        foreach (str_getcsv($baris) as $sel) {
            if ($sel !== '' && in_array(substr($sel, 0, 1), IzinCsvExport::PEMBUKA_BERBAHAYA, true)) {
                $selBerbahaya++;
            }
        }
    }
    $assert($selBerbahaya === 0, 'KL-6g Tidak ada satu pun sel CSV yang masih diawali karakter formula');

    // CSV memuat seluruh hasil filter walau pagination diminta kecil.
    $csvHalamanKecil = $laporan->csv($adminUser, $rentang + ['page' => '1', 'per_page' => '1']);
    $assert(
        (int) $csvHalamanKecil['jumlah_baris'] === 6,
        'KL-6h CSV mengabaikan pagination dan tetap memuat seluruh 6 hasil filter'
    );
    $assert(
        str_ends_with($csvSemua['nama_berkas'], '.csv') && str_contains($csvSemua['nama_berkas'], 'laporan-perizinan'),
        'KL-6i Nama berkas CSV deskriptif: ' . $csvSemua['nama_berkas']
    );
    $assert($csvSemua['terpotong'] === false, 'KL-6j Hasil tidak ditandai terpotong pada jumlah wajar');

    // =====================================================================
    // KL-7. Halaman cetak
    // =====================================================================
    echo PHP_EOL . '=== KL-7. Halaman cetak / PDF ===' . PHP_EOL;

    $cetakAdmin = $laporan->printHtml($adminUser, $rentang);
    $html = $cetakAdmin['html'];
    $dokAdmin = $laporan->document($adminUser, $rentang);

    $assert(str_contains($html, 'Pesantren Al Hasan'), 'KL-7a Cetak memuat identitas pesantren');
    $assert(str_contains($html, 'Laporan Perizinan Santri'), 'KL-7b Cetak memuat judul laporan');
    $assert(str_contains($html, htmlspecialchars($dokAdmin['dibuat_oleh'], ENT_QUOTES)), 'KL-7c Cetak memuat nama pembuat laporan');
    $assert(str_contains($html, htmlspecialchars($dokAdmin['dibuat_pada'], ENT_QUOTES)), 'KL-7d Cetak memuat waktu pembuatan');
    $lembarCetak = substr_count($html, '<section class="lembar">');
    preg_match_all('/Halaman (\d+) dari (\d+)/', $html, $nomorCetak);
    $assert(
        $lembarCetak >= 1
            && !str_contains($html, 'Halaman 0')
            && $nomorCetak[1] === array_map('strval', range(1, $lembarCetak))
            && $nomorCetak[2] === array_fill(0, $lembarCetak, (string) $lembarCetak),
        'KL-7e Cetak memuat nomor halaman 1..' . $lembarCetak . ' tanpa "Halaman 0"'
    );
    $assert(str_contains($html, $tgl(0) . ' s.d. ' . $tgl(10)), 'KL-7f Cetak memuat rentang filter aktif');
    $assert(str_contains($html, 'Median durasi keputusan'), 'KL-7g Cetak memuat median durasi keputusan');
    $assert(
        substr_count($html, '<tr>') >= 7,
        'KL-7h Cetak memuat seluruh baris hasil filter (6 data + header)'
    );
    // Nama berbahaya harus ter-escape sebagai HTML, bukan tereksekusi.
    $assert(
        str_contains($html, '&quot;') && !str_contains($html, '<script'),
        'KL-7i Nilai pada halaman cetak di-escape sebagai HTML'
    );
    $assert(
        str_contains($html, 'Cakupan:') || str_contains($html, 'Cakupan'),
        'KL-7j Cetak menyebutkan cakupan laporan'
    );

    // =====================================================================
    // KL-8. Validasi input
    // =====================================================================
    echo PHP_EOL . '=== KL-8. Validasi input ===' . PHP_EOL;

    $expectStatus(422, static fn () => $laporan->report($adminUser, ['date_from' => '2029-03-10', 'date_to' => '2029-03-01']),
        'KL-8a Tanggal akhir sebelum tanggal awal ditolak 422');
    $expectStatus(422, static fn () => $laporan->report($adminUser, ['date_from' => '01-03-2029']),
        'KL-8b Format tanggal salah ditolak 422');
    $expectStatus(422, static fn () => $laporan->report($adminUser, $rentang + ['status' => 'TidakAda']),
        'KL-8c Status tidak dikenal ditolak 422, bukan diabaikan diam-diam');
    $expectStatus(422, static fn () => $laporan->report($adminUser, $rentang + ['basis_tanggal' => 'entah']),
        'KL-8d Basis tanggal tidak dikenal ditolak 422');
    $expectStatus(422, static fn () => $laporan->report($adminUser, $rentang + ['kanal' => 'Telegram']),
        'KL-8e Kanal tidak dikenal ditolak 422');
    $expectStatus(422, static fn () => $laporan->report($adminUser, $rentang + ['santri_id' => "1 OR 1=1"]),
        'KL-8f ID non-numerik ditolak 422 (tidak pernah masuk SQL)');
    $expectStatus(422, static fn () => $laporan->report($adminUser, $rentang + ['durasi_min_jam' => '5', 'durasi_maks_jam' => '2']),
        'KL-8g Durasi maksimum lebih kecil dari minimum ditolak 422');
    $expectStatus(422, static fn () => $laporan->report($adminUser, $rentang + ['kamar_id' => (string) $kamarA, 'kelas_id' => (string) $kelasA]),
        'KL-8h Filter kamar dan kelas bersamaan ditolak 422');

    // Batas pagination dipaksa server, bukan dipercaya dari klien.
    $besar = $laporan->report($adminUser, $rentang + ['per_page' => '100000']);
    $assert(
        (int) $besar['pagination']['per_page'] === IzinReportFilter::MAX_PER_PAGE,
        'KL-8i per_page dibatasi server pada ' . IzinReportFilter::MAX_PER_PAGE
    );

    // =====================================================================
    // KL-9. Receipt push akhir
    // =====================================================================
    echo PHP_EOL . '=== KL-9. Receipt push akhir ===' . PHP_EOL;

    $klien = new KlienPushTiruan();
    $dispatcher = new NotificationDispatcher(
        $db,
        notification_outbox_repository(),
        push_device_repository(),
        push_token_protector(),
        $klien,
        whatsapp_provider(),
        $settings,
        new WorkerLock($db)
    );

    // Perangkat push milik murobi A.
    $tokenA = 'ExponentPushToken[F5UJI' . $suffix . ']';
    push_device_service()->register($loadUser($userMurobiA), [
        'token' => $tokenA,
        'platform' => 'android',
        'device_id' => 'f5-dev-' . $lower,
        'device_label' => 'Perangkat Uji F5',
    ]);

    $settings->setPushEnabled(true, $adminId);

    // Antrekan satu push, kirim, lalu ambil receipt-nya.
    $eventPush = 'f5-receipt-' . bin2hex(random_bytes(6));
    $outboxId = $exec(
        "INSERT INTO notifikasi_outbox (event_key, event_type, kanal, penerima_user_id, pengajuan_id, judul, isi, status)
         VALUES (?, 'uji_f5_receipt', 'Push', ?, ?, 'Judul uji', 'Isi uji tanpa data sensitif', 'Queued')",
        [$eventPush, $userMurobiA, $p1]
    );

    $hasilKirim = $dispatcher->run('Push', 10, false);
    $assert($hasilKirim['terkirim'] >= 1, 'KL-9a Push terkirim dan menghasilkan tiket awal');
    // Dicatat SETELAH pengiriman: putaran ini juga memproses baris Push lain yang
    // masih antre (mis. baris uji filter kanal). Yang diuji berikutnya adalah
    // bahwa REKONSILIASI tidak menambah pengiriman, bukan jumlah absolutnya.
    $sendSetelahKirim = $klien->panggilanSend;

    $tiket = $db->query('SELECT tiket_id, receipt_status FROM notifikasi_outbox WHERE id = ' . $outboxId)?->fetch_assoc();
    $assert(
        !empty($tiket['tiket_id']) && $tiket['receipt_status'] === 'Menunggu',
        'KL-9b Id tiket disimpan dan baris menunggu receipt akhir'
    );

    // Receipt sukses.
    $klien->receipts = [(string) $tiket['tiket_id'] => ['status' => 'ok']];
    $hasilReceipt = $dispatcher->reconcileReceipts(50, 0);
    $assert($hasilReceipt['dijalankan'] === true, 'KL-9c Rekonsiliasi receipt berjalan');
    $assert($hasilReceipt['terkirim'] === 1, 'KL-9d Receipt sukses menandai pengantaran terkonfirmasi');
    $statusAkhir = $db->query('SELECT status, receipt_status, percobaan FROM notifikasi_outbox WHERE id = ' . $outboxId)?->fetch_assoc();
    $assert($statusAkhir['receipt_status'] === 'Terkirim', 'KL-9e receipt_status menjadi Terkirim');
    $assert($statusAkhir['status'] === 'Sent', 'KL-9f Status pengiriman utama tidak berubah');
    $assert(
        $klien->panggilanSend === $sendSetelahKirim,
        'KL-9g Rekonsiliasi TIDAK mengirim ulang pesan (jumlah panggilan send tidak bertambah)'
    );

    // Receipt gagal pada baris kedua: dicatat, tetapi TIDAK dikirim ulang.
    $outboxId2 = $exec(
        "INSERT INTO notifikasi_outbox (event_key, event_type, kanal, penerima_user_id, pengajuan_id, judul, isi, status)
         VALUES (?, 'uji_f5_receipt', 'Push', ?, ?, 'Judul uji 2', 'Isi uji 2', 'Queued')",
        ['f5-receipt2-' . bin2hex(random_bytes(6)), $userMurobiA, $p1]
    );
    $dispatcher->run('Push', 10, false);
    $tiket2 = $db->query('SELECT tiket_id FROM notifikasi_outbox WHERE id = ' . $outboxId2)?->fetch_assoc();
    $sendSebelum = $klien->panggilanSend;
    $klien->receipts = [(string) $tiket2['tiket_id'] => ['status' => 'error', 'message' => 'gagal antar', 'details' => ['error' => 'MessageTooBig']]];
    $hasilGagal = $dispatcher->reconcileReceipts(50, 0);
    $assert($hasilGagal['gagal'] === 1, 'KL-9h Receipt gagal tercatat');
    $barisGagal = $db->query('SELECT status, receipt_status, receipt_kode FROM notifikasi_outbox WHERE id = ' . $outboxId2)?->fetch_assoc();
    $assert($barisGagal['receipt_status'] === 'Gagal', 'KL-9i receipt_status menjadi Gagal');
    $assert($barisGagal['status'] === 'Sent', 'KL-9j Baris TIDAK dikembalikan ke antrean kirim');
    $assert($klien->panggilanSend === $sendSebelum, 'KL-9k Tidak ada pengiriman ulang setelah receipt gagal');
    $assert(
        (string) $barisGagal['receipt_kode'] !== '' && !str_contains((string) $barisGagal['receipt_kode'], 'ExponentPushToken'),
        'KL-9l Kode receipt tersimpan aman tanpa token'
    );

    // Tiket yang belum dijawab penyedia: tetap Menunggu, bukan Gagal.
    $outboxId3 = $exec(
        "INSERT INTO notifikasi_outbox (event_key, event_type, kanal, penerima_user_id, pengajuan_id, judul, isi, status)
         VALUES (?, 'uji_f5_receipt', 'Push', ?, ?, 'Judul uji 3', 'Isi uji 3', 'Queued')",
        ['f5-receipt3-' . bin2hex(random_bytes(6)), $userMurobiA, $p1]
    );
    $dispatcher->run('Push', 10, false);
    $klien->receipts = [];
    $hasilBelum = $dispatcher->reconcileReceipts(50, 0);
    $assert($hasilBelum['belum_tersedia'] >= 1, 'KL-9m Tiket tanpa jawaban dihitung sebagai belum tersedia');
    $baris3 = $db->query('SELECT receipt_status, receipt_percobaan FROM notifikasi_outbox WHERE id = ' . $outboxId3)?->fetch_assoc();
    $assert(
        $baris3['receipt_status'] === 'Menunggu' && (int) $baris3['receipt_percobaan'] === 1,
        'KL-9n Tiket tanpa jawaban tetap Menunggu dan percobaannya bertambah'
    );

    // Setelah batas percobaan, ditandai Tidak Tersedia — bukan Gagal.
    for ($i = 0; $i < OutboxRepository::RECEIPT_MAKS_PERCOBAAN + 1; $i++) {
        $dispatcher->reconcileReceipts(50, 0);
    }
    $baris3Akhir = $db->query('SELECT receipt_status FROM notifikasi_outbox WHERE id = ' . $outboxId3)?->fetch_assoc();
    $assert(
        $baris3Akhir['receipt_status'] === 'Tidak Tersedia',
        'KL-9o Tiket tanpa jawaban berulang ditandai Tidak Tersedia, bukan Gagal'
    );

    // Rekonsiliasi aman diulang: baris yang sudah final tidak berubah lagi.
    $sebelumUlang = $db->query('SELECT receipt_status, receipt_percobaan FROM notifikasi_outbox WHERE id = ' . $outboxId)?->fetch_assoc();
    $dispatcher->reconcileReceipts(50, 0);
    $sesudahUlang = $db->query('SELECT receipt_status, receipt_percobaan FROM notifikasi_outbox WHERE id = ' . $outboxId)?->fetch_assoc();
    $assert($sebelumUlang == $sesudahUlang, 'KL-9p Rekonsiliasi ulang tidak mengubah baris yang sudah final (idempoten)');

    // =====================================================================
    // KL-10. Push mati = nol permintaan penyedia
    // =====================================================================
    echo PHP_EOL . '=== KL-10. Push mati ===' . PHP_EOL;

    $settings->setPushEnabled(false, $adminId);
    $sendSebelumMati = $klien->panggilanSend;
    $receiptSebelumMati = $klien->panggilanReceipt;

    $exec(
        "INSERT INTO notifikasi_outbox (event_key, event_type, kanal, penerima_user_id, pengajuan_id, judul, isi, status)
         VALUES (?, 'uji_f5_mati', 'Push', ?, ?, 'Judul mati', 'Isi mati', 'Queued')",
        ['f5-mati-' . bin2hex(random_bytes(6)), $userMurobiA, $p1]
    );

    $hasilMati = $dispatcher->run('Push', 10, false);
    $hasilReceiptMati = $dispatcher->reconcileReceipts(50, 0);

    $assert($hasilMati['dijalankan'] === false, 'KL-10a Worker push berhenti saat kanal mati');
    $assert($hasilReceiptMati['dijalankan'] === false, 'KL-10b Rekonsiliasi receipt berhenti saat kanal mati');
    $assert($klien->panggilanSend === $sendSebelumMati, 'KL-10c NOL permintaan pengiriman ke penyedia saat push mati');
    $assert($klien->panggilanReceipt === $receiptSebelumMati, 'KL-10d NOL permintaan receipt ke penyedia saat push mati');

    // Laporan tetap dapat dibuka meski kanal notifikasi mati.
    $laporanSaatMati = $laporan->report($adminUser, $rentang);
    $assert(
        (int) $laporanSaatMati['ringkasan']['total'] === 6,
        'KL-10e Laporan tetap berfungsi penuh saat kanal notifikasi mati'
    );
} finally {
    // =====================================================================
    // Pembersihan fixture dan pemulihan pengaturan
    // =====================================================================
    echo PHP_EOL . '=== Pembersihan fixture ===' . PHP_EOL;

    $bersih = static function (string $sql, array $params = []) use ($db): void {
        try {
            $statement = $db->prepare($sql);
            if ($statement === false) {
                return;
            }
            if ($params !== []) {
                $types = str_repeat('i', count($params));
                $statement->bind_param($types, ...$params);
            }
            $statement->execute();
            $statement->close();
        } catch (Throwable) {
            // Pembersihan tidak boleh menutupi kegagalan pengujian.
        }
    };

    foreach (array_reverse($created['izin']) as $id) {
        $bersih('DELETE FROM notifikasi_outbox WHERE pengajuan_id = ?', [$id]);
        $bersih('DELETE FROM izin_riwayat_status WHERE pengajuan_id = ?', [$id]);
        $bersih('DELETE FROM izin_keputusan WHERE pengajuan_id = ?', [$id]);
        $bersih('DELETE FROM izin_idempotency_keys WHERE pengajuan_id = ?', [$id]);
        $bersih('DELETE FROM izin_pengajuan WHERE id = ?', [$id]);
    }
    foreach (array_reverse($created['users']) as $id) {
        $bersih('DELETE FROM notifikasi_outbox WHERE penerima_user_id = ?', [$id]);
        $bersih('DELETE FROM perangkat_push WHERE user_id = ?', [$id]);
        $bersih('DELETE FROM user_roles WHERE user_id = ?', [$id]);
        $bersih('DELETE FROM users WHERE id = ?', [$id]);
    }
    foreach (array_reverse($created['pembimbing']) as $id) {
        $bersih('DELETE FROM pembimbing_assignments WHERE id = ?', [$id]);
    }
    foreach (array_reverse($created['murobi']) as $id) {
        $bersih('DELETE FROM murobi_assignments WHERE id = ?', [$id]);
    }
    foreach (array_reverse($created['santri_wali']) as $id) {
        $bersih('DELETE FROM santri_wali WHERE id = ?', [$id]);
    }
    foreach (array_reverse($created['plotting_kelas']) as $id) {
        $bersih('DELETE FROM plotting_kelas WHERE id = ?', [$id]);
    }
    foreach (array_reverse($created['plotting_kamar']) as $id) {
        $bersih('DELETE FROM plotting_kamar WHERE id = ?', [$id]);
    }
    foreach (array_reverse($created['santri']) as $id) {
        $bersih('DELETE FROM santri WHERE id = ?', [$id]);
    }
    foreach (array_reverse($created['wali']) as $id) {
        $bersih('DELETE FROM wali WHERE id = ?', [$id]);
    }
    foreach (array_reverse($created['pengurus']) as $id) {
        $bersih('DELETE FROM pengurus WHERE id = ?', [$id]);
    }
    foreach (array_reverse($created['guru']) as $id) {
        $bersih('DELETE FROM guru WHERE id = ?', [$id]);
    }
    foreach (array_reverse($created['kelas']) as $id) {
        $bersih('DELETE FROM kelas WHERE id = ?', [$id]);
    }
    foreach (array_reverse($created['kamar']) as $id) {
        $bersih('DELETE FROM kamar WHERE id = ?', [$id]);
    }

    // Pengaturan kanal dikembalikan ke keadaan semula.
    try {
        $settings->setPushEnabled((bool) $pengaturanAwal['push_enabled'], $adminId);
        $settings->setWhatsappEnabled((bool) $pengaturanAwal['whatsapp_enabled'], $adminId);
    } catch (Throwable) {
        // Diabaikan: pemulihan pengaturan tidak boleh menutupi hasil pengujian.
    }
    echo '[ok] Fixture Fase 5 dibersihkan dan pengaturan kanal dipulihkan.' . PHP_EOL;
}

echo PHP_EOL;
if ($failures !== []) {
    echo 'GAGAL (' . count($failures) . '):' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}
echo 'SELURUH PENGUJIAN INTEGRASI FASE 5 LULUS.' . PHP_EOL;
exit(0);
