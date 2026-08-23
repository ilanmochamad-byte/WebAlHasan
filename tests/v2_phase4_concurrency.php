<?php

declare(strict_types=1);

/**
 * Pengujian concurrency worker notifikasi V2 Fase 4.
 *
 * Membuktikan kriteria "concurrency worker tidak mengirim event yang sama dua
 * kali" pada dua lapis pengaman yang berbeda:
 *
 *   KC-1  DUA PROSES PHP NYATA menjalankan worker pada detik yang sama.
 *         Jumlah pesan pada jurnal adapter uji harus PERSIS sama dengan jumlah
 *         baris antrean — tidak boleh ada satu pun pengiriman ganda.
 *   KC-2  Klaim per baris: dua pemilik berbeda yang mengklaim antrean yang sama
 *         memperoleh himpunan baris yang saling lepas (disjoint).
 *   KC-3  Sewa proses: pemilik kedua tidak dapat mengambil sewa yang masih
 *         berlaku, dan dapat mengambilnya setelah dilepas.
 *
 * Tidak ada permintaan jaringan keluar: seluruh pengiriman memakai adapter uji.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   V2_PHASE4_RUN_CONCURRENCY=1 php tests/v2_phase4_concurrency.php
 */

$root = dirname(__DIR__);
if (getenv('V2_PHASE4_RUN_CONCURRENCY') !== '1') {
    fwrite(STDOUT, "[lewati] Set V2_PHASE4_RUN_CONCURRENCY=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

putenv('PUSH_TOKEN_KEY=' . base64_encode(str_repeat("\x2b", 32)));
putenv('WHATSAPP_PROVIDER=');

require_once $root . '/app/bootstrap.php';

use App\Notification\NotificationChannel;
use App\Notification\WorkerLock;

if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Pengujian concurrency ditolak: DB_NAME wajib berakhiran _test.\n");
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

$suffix = strtoupper(bin2hex(random_bytes(3)));
$lower = strtolower($suffix);
$jurnal = sys_get_temp_dir() . '/v2_phase4_fake_wa_' . $lower . '.jsonl';
@unlink($jurnal);

$settings = notification_settings_repository();
$outbox = notification_outbox_repository();
$notifications = notification_repository();
$pengaturanAwal = $settings->current();

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

$created = ['users' => [], 'wali' => []];
$jumlahBaris = 12;
$prefiksEvent = 'uji:concurrency:' . $lower . ':';

$exec = static function (string $sql, array $params = []) use ($db): int {
    $statement = $db->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Fixture gagal: ' . $db->error);
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
    $statement->execute();
    $id = (int) $statement->insert_id;
    $statement->close();

    return $id;
};

$scalar = static function (string $sql) use ($db): int {
    $result = $db->query($sql);

    return $result ? (int) array_values($result->fetch_assoc())[0] : -1;
};

try {
    // -----------------------------------------------------------------
    // Fixture: satu penerima bernomor dan antrean WhatsApp berisi N baris.
    // -----------------------------------------------------------------
    $wali = $exec('INSERT INTO wali (nama, no_hp, is_active) VALUES (?, ?, 1)', ['Wali Concurrency ' . $suffix, '081377700001']);
    $created['wali'][] = $wali;
    $userId = $exec(
        'INSERT INTO users (name, username, password, phone, wali_id, is_active, force_password_change, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 1, 0, NOW(), NOW())',
        ['Akun Concurrency ' . $suffix, 'f4.cc.' . $lower, password_hash('UjiPassword123!Aa', PASSWORD_DEFAULT), '081377700002', $wali]
    );
    $exec('INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = ?', [$userId, $adminId, 'orang_tua']);
    $created['users'][] = $userId;

    // WhatsApp harus AKTIF agar worker berjalan; adapter uji yang dipakai anak
    // proses tidak menghubungi penyedia mana pun.
    $settings->recordWhatsappCheck('Lulus', 'Adapter uji untuk pengujian concurrency.', 'fake', $adminId);
    $settings->setWhatsappEnabled(true, $adminId, 'fake');

    for ($i = 1; $i <= $jumlahBaris; $i++) {
        $notifications->enqueue([
            'event_key' => $prefiksEvent . $i,
            'event_type' => 'sistem.pesan_uji',
            'kanal' => NotificationChannel::WHATSAPP,
            'penerima_user_id' => $userId,
            'pengajuan_id' => null,
            'judul' => 'Uji concurrency',
            'isi' => 'Baris antrean nomor ' . $i . ' untuk pengujian concurrency.',
            'data_json' => null,
        ]);
    }
    $antrean = $scalar("SELECT COUNT(*) FROM notifikasi_outbox WHERE event_key LIKE '" . $db->real_escape_string($prefiksEvent) . "%'");
    $assert($antrean === $jumlahBaris, 'KC-0 Antrean uji berisi ' . $jumlahBaris . ' baris WhatsApp');

    // -----------------------------------------------------------------
    // KC-1. Dua proses worker nyata pada detik yang sama
    // -----------------------------------------------------------------
    echo PHP_EOL . '=== KC-1. Dua worker bersamaan ===' . PHP_EOL;

    $mulai = microtime(true) + 1.5;
    $perintah = static function () use ($root, $mulai, $jurnal): string {
        return implode(' ', [
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/tests/v2_phase4_concurrency_worker.php'),
            escapeshellarg('--at=' . $mulai),
            escapeshellarg('--journal=' . $jurnal),
            escapeshellarg('--batas=25'),
        ]);
    };

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipesA = $pipesB = [];
    $prosesA = proc_open($perintah(), $descriptors, $pipesA, $root);
    $prosesB = proc_open($perintah(), $descriptors, $pipesB, $root);
    if (!is_resource($prosesA) || !is_resource($prosesB)) {
        throw new RuntimeException('Proses worker uji tidak dapat dijalankan.');
    }
    $outA = (string) stream_get_contents($pipesA[1]);
    $errA = (string) stream_get_contents($pipesA[2]);
    $outB = (string) stream_get_contents($pipesB[1]);
    $errB = (string) stream_get_contents($pipesB[2]);
    foreach ([$pipesA, $pipesB] as $pipes) {
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
    }
    proc_close($prosesA);
    proc_close($prosesB);

    $bacaJson = static function (string $out, string $err): array {
        $baris = trim($out);
        $decoded = $baris === '' ? null : json_decode($baris, true);

        return is_array($decoded) ? $decoded : ['ok' => false, 'pesan' => trim($err . ' ' . $out)];
    };
    $hasilA = $bacaJson($outA, $errA);
    $hasilB = $bacaJson($outB, $errB);

    $assert(
        ($hasilA['ok'] ?? false) === true && ($hasilB['ok'] ?? false) === true,
        'KC-1a Kedua proses worker selesai tanpa galat'
    );

    $terkirimTotal = (int) ($hasilA['terkirim'] ?? 0) + (int) ($hasilB['terkirim'] ?? 0);
    $assert(
        $terkirimTotal === $jumlahBaris,
        'KC-1b Total terkirim kedua worker = ' . $jumlahBaris . ' (tercatat ' . $terkirimTotal . ')'
    );

    // Bukti dari sisi penyedia: jurnal adapter uji.
    $barisJurnal = is_file($jurnal)
        ? array_values(array_filter(explode("\n", (string) file_get_contents($jurnal))))
        : [];
    $assert(
        count($barisJurnal) === $jumlahBaris,
        'KC-1c Adapter uji menerima tepat ' . $jumlahBaris . ' pesan (tercatat ' . count($barisJurnal) . ')'
    );
    $kunciTerkirim = [];
    foreach ($barisJurnal as $baris) {
        $data = json_decode($baris, true);
        if (is_array($data) && isset($data['event_key'])) {
            $kunciTerkirim[] = (string) $data['event_key'];
        }
    }
    $assert(
        count($kunciTerkirim) === count(array_unique($kunciTerkirim)),
        'KC-1d Tidak ada satu pun peristiwa yang terkirim dua kali'
    );
    $assert(
        !preg_match('/08137770000\d/', implode(' ', $barisJurnal)),
        'KC-1e Jurnal adapter uji menyamarkan nomor tujuan'
    );

    $sisaAntrean = $scalar(
        "SELECT COUNT(*) FROM notifikasi_outbox
          WHERE event_key LIKE '" . $db->real_escape_string($prefiksEvent) . "%' AND status <> 'Sent'"
    );
    $assert($sisaAntrean === 0, 'KC-1f Seluruh baris antrean berstatus Sent setelah kedua worker selesai');

    $percobaanGanda = $scalar(
        "SELECT COUNT(*) FROM notifikasi_outbox
          WHERE event_key LIKE '" . $db->real_escape_string($prefiksEvent) . "%' AND percobaan > 1"
    );
    $assert($percobaanGanda === 0, 'KC-1g Tidak ada baris yang dicoba lebih dari satu kali');

    $sewaTerbuka = $scalar(
        "SELECT COUNT(*) FROM notifikasi_outbox
          WHERE event_key LIKE '" . $db->real_escape_string($prefiksEvent) . "%' AND locked_by IS NOT NULL"
    );
    $assert($sewaTerbuka === 0, 'KC-1h Tidak ada baris yang tertinggal dalam keadaan terkunci');

    // -----------------------------------------------------------------
    // KC-2. Klaim per baris bersifat eksklusif
    // -----------------------------------------------------------------
    echo PHP_EOL . '=== KC-2. Klaim baris eksklusif ===' . PHP_EOL;

    $prefiksKlaim = 'uji:klaim:' . $lower . ':';
    for ($i = 1; $i <= 6; $i++) {
        $notifications->enqueue([
            'event_key' => $prefiksKlaim . $i,
            'event_type' => 'sistem.pesan_uji',
            'kanal' => NotificationChannel::WHATSAPP,
            'penerima_user_id' => $userId,
            'pengajuan_id' => null,
            'judul' => 'Uji klaim',
            'isi' => 'Baris klaim nomor ' . $i . '.',
            'data_json' => null,
        ]);
    }

    $klaimA = $outbox->claim(NotificationChannel::WHATSAPP, 'pemilik-a-' . $lower, 3);
    $klaimB = $outbox->claim(NotificationChannel::WHATSAPP, 'pemilik-b-' . $lower, 10);
    $idA = array_map(static fn (array $row): int => (int) $row['id'], $klaimA);
    $idB = array_map(static fn (array $row): int => (int) $row['id'], $klaimB);

    $assert($idA !== [], 'KC-2a Pemilik pertama memperoleh baris');
    $assert(array_intersect($idA, $idB) === [], 'KC-2b Dua pemilik tidak pernah memperoleh baris yang sama');
    $assert(
        count(array_unique(array_merge($idA, $idB))) === count($idA) + count($idB),
        'KC-2c Gabungan klaim tidak memuat duplikat'
    );

    // Baris yang sudah diklaim tidak dapat diselesaikan pemilik lain.
    if ($idA !== []) {
        $assert(
            $outbox->markSent($idA[0], 'pemilik-b-' . $lower) === false,
            'KC-2d Pemilik lain tidak dapat menandai baris yang bukan klaimnya'
        );
        $assert(
            $outbox->markSent($idA[0], 'pemilik-a-' . $lower) === true,
            'KC-2e Pemilik yang benar dapat menyelesaikan barisnya'
        );
    }

    // -----------------------------------------------------------------
    // KC-3. Sewa proses worker
    // -----------------------------------------------------------------
    echo PHP_EOL . '=== KC-3. Sewa proses ===' . PHP_EOL;

    $lockSatu = new WorkerLock($db);
    $lockDua = new WorkerLock($db);
    $namaSewa = 'notifikasi:uji-' . $lower;

    $assert($lockSatu->acquire($namaSewa, 60) === true, 'KC-3a Proses pertama memperoleh sewa');
    $assert($lockDua->acquire($namaSewa, 60) === false, 'KC-3b Proses kedua ditolak selama sewa masih berlaku');
    $assert($lockSatu->renew(60) === true, 'KC-3c Pemilik dapat memperpanjang sewa proses');
    $assert($lockDua->renew(60) === false, 'KC-3d Proses lain tidak dapat memperpanjang sewa milik worker pertama');
    $lockSatu->release();
    $assert($lockDua->acquire($namaSewa, 60) === true, 'KC-3e Sewa dapat diambil kembali setelah dilepas');
    $lockDua->release();
    $assert(
        (int) ($db->query("SELECT COUNT(*) AS n FROM notifikasi_worker_lock WHERE nama = '" . $db->real_escape_string($namaSewa) . "' AND pemilik = ''")?->fetch_assoc()['n'] ?? 0) === 1,
        'KC-3f Sewa kembali kosong setelah dilepas'
    );
    $db->query("DELETE FROM notifikasi_worker_lock WHERE nama = '" . $db->real_escape_string($namaSewa) . "'");
} catch (Throwable $exception) {
    $failures[] = 'Kesalahan tak terduga: ' . $exception->getMessage();
    echo '[gagal] ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine() . PHP_EOL;
} finally {
    try {
        $settings->setWhatsappEnabled(false, $adminId);
        $settings->recordWhatsappCheck(
            $pengaturanAwal['whatsapp_check_status'],
            (string) ($pengaturanAwal['whatsapp_check_pesan'] ?? 'Dipulihkan setelah pengujian.'),
            $pengaturanAwal['whatsapp_provider'],
            $adminId
        );
        if ($pengaturanAwal['whatsapp_enabled']) {
            $settings->setWhatsappEnabled(true, $adminId, (string) $pengaturanAwal['whatsapp_provider']);
        }
        $settings->setPushEnabled($pengaturanAwal['push_enabled'], $adminId);
    } catch (Throwable $exception) {
        echo '[perhatian] Pengaturan kanal tidak dapat dipulihkan: ' . $exception->getMessage() . PHP_EOL;
    }

    @unlink($jurnal);
    $db->query('SET FOREIGN_KEY_CHECKS=0');
    $idsUser = array_values(array_filter(array_map('intval', $created['users'])));
    if ($idsUser !== []) {
        $daftar = implode(',', $idsUser);
        $db->query('DELETE FROM notifikasi_percobaan WHERE outbox_id IN (SELECT id FROM notifikasi_outbox WHERE penerima_user_id IN (' . $daftar . '))');
        $db->query('DELETE FROM notifikasi_outbox WHERE penerima_user_id IN (' . $daftar . ')');
        $db->query('DELETE FROM user_roles WHERE user_id IN (' . $daftar . ')');
        $db->query('DELETE FROM users WHERE id IN (' . $daftar . ')');
    }
    $idsWali = array_values(array_filter(array_map('intval', $created['wali'])));
    if ($idsWali !== []) {
        $db->query('DELETE FROM wali WHERE id IN (' . implode(',', $idsWali) . ')');
    }
    $db->query("DELETE FROM notifikasi_pengaturan_audit WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
    $db->query('SET FOREIGN_KEY_CHECKS=1');
    echo '[bersih] Fixture uji concurrency Fase 4 dihapus.' . PHP_EOL;
}

exit($failures === [] ? 0 : 1);
