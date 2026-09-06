<?php

declare(strict_types=1);

/**
 * Pengujian integrasi fitur "Pesan Kredensial Akun Siap Salin"
 * (keputusan pengguna 6 September 2026) pada basis data sungguhan.
 *
 *   KR-1  pembuatan akun guru menghasilkan pesan dari data yang tersimpan;
 *   KR-2  hal yang sama untuk akun pengurus;
 *   KR-3  hal yang sama untuk akun orang tua;
 *   KR-4  pesan hanya dapat dibaca satu kali dari sesi;
 *   KR-5  basis data TIDAK memuat password sementara dalam bentuk asli;
 *   KR-6  audit memuat identitas akun, tetapi tidak memuat password;
 *   KR-7  log aplikasi tidak memuat password;
 *   KR-8  kegagalan transaksi tidak membuat akun maupun pesan kredensial;
 *   KR-9  retry formulir tidak membuat akun atau pesan kedua;
 *   KR-10 login pertama memakai password sementara mewajibkan ganti password;
 *   KR-11 setelah password diganti, password sementara ditolak;
 *   KR-12 alur reset password lama tetap berperilaku sama;
 *   KR-13 nama dan username berkarakter khusus tidak merusak pesan;
 *   KR-14 regresi pengelolaan akun: role, relasi master, multi-peran, status.
 *
 * Seluruh fixture memakai data FIKTIF berakhiran acak dan dihapus kembali pada
 * blok `finally`. Tidak ada permintaan jaringan keluar.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   KREDENSIAL_RUN_INTEGRATION=1 php tests/kredensial_integration.php
 */

$root = dirname(__DIR__);
if (getenv('KREDENSIAL_RUN_INTEGRATION') !== '1') {
    fwrite(STDOUT, "[lewati] Set KREDENSIAL_RUN_INTEGRATION=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

// Log aplikasi diarahkan ke berkas sementara supaya isinya dapat diperiksa.
$logUji = sys_get_temp_dir() . '/kredensial-uji-' . getmypid() . '.log';
@unlink($logUji);
ini_set('error_log', $logUji);
ini_set('log_errors', '1');

require_once $root . '/app/bootstrap.php';

use App\Account\CredentialFlash;
use App\Account\CredentialMessage;
use App\Auth\AuthService;
use App\Ui\CredentialPanel;

if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Ditolak: pengujian ini hanya boleh berjalan pada database berakhiran _test.\n");
    exit(2);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};
$tolak = static function (callable $aksi, string $pesan) use ($assert): void {
    try {
        $aksi();
        $assert(false, $pesan . ' (ternyata TIDAK ditolak)');
    } catch (Throwable $exception) {
        $assert(true, $pesan . ' [' . $exception->getMessage() . ']');
    }
};

$db = app_db();
$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$kecil = strtolower($suffix);
$akun = account_service();
$perizinan = perizinan_account_service();
$master = master_data_service();

$adminRow = $db->query(
    "SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
      WHERE r.slug = 'admin' AND u.is_active = 1 ORDER BY u.id LIMIT 1"
)?->fetch_assoc();
if (!$adminRow) {
    fwrite(STDERR, "Fixture admin tidak tersedia pada database uji.\n");
    exit(2);
}
$adminId = (int) $adminRow['id'];
$_SESSION['user_id'] = $adminId;

$dibuat = ['users' => [], 'guru' => [], 'pengurus' => [], 'wali' => [], 'santri' => []];
/** Seluruh password sementara yang pernah dibuat pengujian ini. */
$sandiDipakai = [];

$satu = static function (string $sql) use ($db): array {
    $rs = $db->query($sql);

    return ($rs && $row = $rs->fetch_assoc()) ? $row : [];
};

try {
    // =====================================================================
    echo PHP_EOL . '=== KR-1. Akun guru ===' . PHP_EOL;
    // =====================================================================

    $guruId = (int) $master->saveGuru(['nip' => 'KR' . $suffix, 'nama_guru' => 'Guru Kredensial ' . $suffix, 'no_hp' => '']);
    $dibuat['guru'][] = $guruId;

    // Formulir sengaja mengirim nilai MENTAH: username huruf besar berspasi dan
    // email huruf besar. Server menormalkan lalu membacanya kembali.
    $hasilGuru = $akun->createTeacher([
        'guru_id' => $guruId,
        'name' => '  Ustadz Kredensial ' . $suffix . '  ',
        'username' => '  KR.GURU.' . $suffix . '  ',
        'email' => '  KR.GURU.' . $suffix . '@Contoh.Test  ',
        'phone' => '+6281200000001',
    ], $adminId);
    $userGuru = (int) $hasilGuru['id'];
    $dibuat['users'][] = $userGuru;
    $sandiGuru = (string) $hasilGuru['temporary_password'];
    $sandiDipakai[] = $sandiGuru;

    $assert(isset($hasilGuru['account']) && is_array($hasilGuru['account']),
        'KR-1a createTeacher mengembalikan baris akun hasil pembacaan ulang server');

    $barisDb = $satu('SELECT name, username, email, password, force_password_change FROM users WHERE id = ' . $userGuru);
    $akunTerbaca = $hasilGuru['account'];
    $assert((string) $akunTerbaca['username'] === (string) $barisDb['username']
        && (string) $akunTerbaca['name'] === (string) $barisDb['name']
        && (string) $akunTerbaca['email'] === (string) $barisDb['email'],
        'KR-1b nilai pada hasil sama persis dengan yang tersimpan di basis data');
    $assert((string) $akunTerbaca['username'] === 'kr.guru.' . $kecil,
        'KR-1c username yang dipakai pesan adalah hasil normalisasi server, bukan nilai mentah formulir');
    $assert((string) $akunTerbaca['email'] === 'kr.guru.' . $kecil . '@contoh.test',
        'KR-1d email yang dipakai pesan adalah hasil normalisasi server');

    $muatanGuru = CredentialMessage::forSavedAccount($akunTerbaca, $sandiGuru, 'guru');
    $pesanGuru = CredentialMessage::text($muatanGuru);
    $assert(str_contains($pesanGuru, 'Username: kr.guru.' . $kecil)
        && str_contains($pesanGuru, 'Password sementara: ' . $sandiGuru)
        && str_contains($pesanGuru, 'https://alhasan.co.id/portal/')
        && str_contains($pesanGuru, 'Yth. Ustadz Kredensial ' . $suffix . ','),
        'KR-1e pesan memuat nama, username, password sementara, dan alamat masuk');
    $assert((int) $barisDb['force_password_change'] === 1,
        'KR-1f akun baru wajib mengganti password pada login pertama');
    $assert(str_starts_with((string) $barisDb['password'], '$2y$')
        && (string) $barisDb['password'] !== $sandiGuru
        && password_verify($sandiGuru, (string) $barisDb['password']),
        'KR-1g basis data hanya menyimpan hash, bukan password asli');

    $panelGuru = CredentialPanel::render($muatanGuru);
    $assert(str_contains($panelGuru, 'Salin pesan') && str_contains($panelGuru, $sandiGuru),
        'KR-1h panel sukses menampilkan pesan lengkap beserta tombol salin');

    // =====================================================================
    echo PHP_EOL . '=== KR-2. Akun pengurus ===' . PHP_EOL;
    // =====================================================================

    $pengurusId = (int) $master->savePengurus([
        'nama' => 'Pengurus Kredensial ' . $suffix,
        'nomor_identitas' => 'PK' . $suffix,
        'no_hp' => '081200000002',
        'jabatan' => 'Keamanan',
    ]);
    $dibuat['pengurus'][] = $pengurusId;

    $hasilPengurus = $perizinan->create('pengurus', [
        'pengurus_id' => $pengurusId,
        'name' => 'Akun Pengurus ' . $suffix,
        'username' => 'KR.PENGURUS.' . $suffix,
        'email' => 'KR.PENGURUS.' . $suffix . '@Contoh.Test',
        'phone' => '081200000002',
    ], $adminId);
    $userPengurus = (int) $hasilPengurus['id'];
    $dibuat['users'][] = $userPengurus;
    $sandiPengurus = (string) $hasilPengurus['temporary_password'];
    $sandiDipakai[] = $sandiPengurus;

    $assert(isset($hasilPengurus['account']['username'])
        && (string) $hasilPengurus['account']['username'] === 'kr.pengurus.' . $kecil,
        'KR-2a create() pengurus mengembalikan akun hasil pembacaan ulang yang ternormalisasi');
    $muatanPengurus = CredentialMessage::forSavedAccount($hasilPengurus['account'], $sandiPengurus, 'pengurus');
    $pesanPengurus = CredentialMessage::text($muatanPengurus);
    $assert(str_contains($pesanPengurus, 'Username: kr.pengurus.' . $kecil)
        && str_contains($pesanPengurus, 'Password sementara: ' . $sandiPengurus),
        'KR-2b pesan pengurus memakai format baku yang sama');
    $barisPengurus = $satu('SELECT password, force_password_change FROM users WHERE id = ' . $userPengurus);
    $assert(password_verify($sandiPengurus, (string) $barisPengurus['password'])
        && (string) $barisPengurus['password'] !== $sandiPengurus
        && (int) $barisPengurus['force_password_change'] === 1,
        'KR-2c akun pengurus: hanya hash tersimpan dan wajib ganti password');

    // =====================================================================
    echo PHP_EOL . '=== KR-3. Akun orang tua ===' . PHP_EOL;
    // =====================================================================

    $santriId = (int) $master->saveSantri([
        'nis' => 'KS' . $suffix,
        'nama_santri' => 'Santri Kredensial ' . $suffix,
        'jenis_kelamin' => 'L',
        'tgl_lahir' => '2011-05-05',
        'wali' => ['Ayah' => ['mode' => 'baru', 'nama' => 'Wali Kredensial ' . $suffix, 'no_hp' => '081200000003', 'alamat' => 'Jl Uji']],
    ]);
    $dibuat['santri'][] = $santriId;
    $waliId = (int) ($satu('SELECT wali_id FROM santri_wali WHERE santri_id = ' . $santriId . ' AND archived_at IS NULL LIMIT 1')['wali_id'] ?? 0);
    $dibuat['wali'][] = $waliId;
    $assert($waliId > 0, 'KR-3a fixture wali dengan relasi santri aktif tersedia');

    // Email SENGAJA dikosongkan: email tetap opsional, tidak dijadikan wajib.
    $hasilOrtu = $perizinan->create('orang_tua', [
        'wali_id' => $waliId,
        'name' => 'Akun Wali ' . $suffix,
        'username' => 'kr.wali.' . $kecil,
        'email' => '',
        'phone' => '081200000003',
    ], $adminId);
    $userOrtu = (int) $hasilOrtu['id'];
    $dibuat['users'][] = $userOrtu;
    $sandiOrtu = (string) $hasilOrtu['temporary_password'];
    $sandiDipakai[] = $sandiOrtu;

    $muatanOrtu = CredentialMessage::forSavedAccount($hasilOrtu['account'], $sandiOrtu, 'orang_tua');
    $assert($muatanOrtu['email'] === '', 'KR-3b akun tanpa email tetap sah: email tidak dijadikan wajib');
    $panelOrtu = CredentialPanel::render($muatanOrtu);
    $assert(str_contains($panelOrtu, 'tidak memiliki email'),
        'KR-3c panel menjelaskan akun tanpa email, bukan menampilkan kolom kosong');
    $assert(str_contains(CredentialMessage::text($muatanOrtu), 'Password sementara: ' . $sandiOrtu),
        'KR-3d pesan orang tua memakai format baku yang sama');
    $barisOrtu = $satu('SELECT password, force_password_change FROM users WHERE id = ' . $userOrtu);
    $assert(password_verify($sandiOrtu, (string) $barisOrtu['password'])
        && (int) $barisOrtu['force_password_change'] === 1,
        'KR-3e akun orang tua: hanya hash tersimpan dan wajib ganti password');

    // =====================================================================
    echo PHP_EOL . '=== KR-4. Pesan hanya satu kali ===' . PHP_EOL;
    // =====================================================================

    CredentialFlash::set($muatanGuru);
    $assert(CredentialFlash::has(), 'KR-4a pesan tersedia tepat setelah pembuatan akun berhasil');
    $pertama = CredentialFlash::take();
    $assert(is_array($pertama) && $pertama['username'] === $muatanGuru['username'],
        'KR-4b tampilan pertama memuat pesan lengkap');
    $assert(CredentialFlash::take() === null,
        'KR-4c muat ulang halaman atau tombol kembali tidak memunculkan pesan kedua kali');
    $assert(!isset($_SESSION['_ah_kredensial_akun']),
        'KR-4d muatan benar-benar hilang dari sesi setelah dibaca');

    // =====================================================================
    echo PHP_EOL . '=== KR-5. Basis data tidak memuat password asli ===' . PHP_EOL;
    // =====================================================================

    $namaDb = (string) app_config('database.database');
    $kolom = $db->query(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = '" . $db->real_escape_string($namaDb) . "'
            AND DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext','json')"
    );
    $temuan = [];
    $kolomDiperiksa = 0;
    while ($kolom && $baris = $kolom->fetch_assoc()) {
        $kolomDiperiksa++;
        $tabel = '`' . str_replace('`', '', (string) $baris['TABLE_NAME']) . '`';
        $nama = '`' . str_replace('`', '', (string) $baris['COLUMN_NAME']) . '`';
        foreach ($sandiDipakai as $sandi) {
            $rs = $db->query('SELECT COUNT(*) AS c FROM ' . $tabel . ' WHERE ' . $nama
                . " LIKE '%" . $db->real_escape_string($sandi) . "%'");
            $jumlah = (int) (($rs && $r = $rs->fetch_assoc()) ? $r['c'] : 0);
            if ($jumlah > 0) {
                $temuan[] = $baris['TABLE_NAME'] . '.' . $baris['COLUMN_NAME'] . ' (' . $jumlah . ' baris)';
            }
        }
    }
    $assert($kolomDiperiksa > 50, 'KR-5a pemindaian mencakup seluruh kolom teks basis data (' . $kolomDiperiksa . ' kolom)');
    $assert($temuan === [],
        'KR-5b tidak ada satu pun kolom yang memuat password sementara dalam bentuk asli'
            . ($temuan === [] ? '' : ' — ditemukan pada: ' . implode(', ', $temuan)));

    // =====================================================================
    echo PHP_EOL . '=== KR-6. Audit ===' . PHP_EOL;
    // =====================================================================

    $idAkun = implode(',', [$userGuru, $userPengurus, $userOrtu]);
    $auditRs = $db->query(
        "SELECT action, entity_id, before_json, after_json FROM audit_logs
          WHERE entity_type = 'user' AND entity_id IN (" . $idAkun . ')'
    );
    $auditTeks = '';
    $aksiTercatat = [];
    while ($auditRs && $baris = $auditRs->fetch_assoc()) {
        $auditTeks .= (string) $baris['before_json'] . (string) $baris['after_json'];
        $aksiTercatat[(string) $baris['action']] = true;
    }
    $adaSandiDiAudit = false;
    foreach ($sandiDipakai as $sandi) {
        if (str_contains($auditTeks, $sandi)) {
            $adaSandiDiAudit = true;
        }
    }
    $assert(!$adaSandiDiAudit, 'KR-6a audit pembuatan akun tidak memuat password sementara');
    $assert(isset($aksiTercatat['account_created']) && isset($aksiTercatat['perizinan_account_created']),
        'KR-6b audit tetap mencatat pembuatan akun untuk ketiga jalur');
    $assert(str_contains($auditTeks, 'kr.guru.' . $kecil) && str_contains($auditTeks, '"roles":["guru"]'),
        'KR-6c audit tetap menyimpan username dan role akun');

    // =====================================================================
    echo PHP_EOL . '=== KR-7. Log aplikasi ===' . PHP_EOL;
    // =====================================================================

    $isiLog = is_file($logUji) ? (string) file_get_contents($logUji) : '';
    $adaSandiDiLog = false;
    foreach ($sandiDipakai as $sandi) {
        if ($isiLog !== '' && str_contains($isiLog, $sandi)) {
            $adaSandiDiLog = true;
        }
    }
    $assert(!$adaSandiDiLog, 'KR-7a log aplikasi tidak memuat password sementara');

    // =====================================================================
    echo PHP_EOL . '=== KR-8. Kegagalan transaksi ===' . PHP_EOL;
    // =====================================================================

    $sebelumGagal = (int) ($satu('SELECT COUNT(*) AS c FROM users')['c'] ?? 0);
    CredentialFlash::forget();
    $gagalTertangkap = false;
    try {
        // Username sudah dipakai: penyimpanan HARUS gagal seluruhnya.
        $akun->createTeacher([
            'guru_id' => $guruId,
            'name' => 'Akun Bentrok ' . $suffix,
            'username' => 'kr.guru.' . $kecil,
            'email' => '',
            'phone' => '',
        ], $adminId);
    } catch (Throwable $exception) {
        $gagalTertangkap = true;
        // Meniru jalur catch halaman akun.
        CredentialFlash::forget();
    }
    $assert($gagalTertangkap, 'KR-8a pembuatan akun dengan username bentrok ditolak');
    $assert((int) ($satu('SELECT COUNT(*) AS c FROM users')['c'] ?? 0) === $sebelumGagal,
        'KR-8b kegagalan tidak menyisakan baris akun setengah jadi');
    $assert(CredentialFlash::take() === null,
        'KR-8c kegagalan tidak meninggalkan pesan kredensial palsu');

    // =====================================================================
    echo PHP_EOL . '=== KR-9. Retry formulir ===' . PHP_EOL;
    // =====================================================================

    $formulirSama = [
        'pengurus_id' => $pengurusId,
        'name' => 'Akun Pengurus ' . $suffix,
        'username' => 'kr.pengurus.' . $kecil,
        'email' => 'kr.pengurus.' . $kecil . '@contoh.test',
        'phone' => '081200000002',
    ];
    $tolak(static fn () => $perizinan->create('pengurus', $formulirSama, $adminId),
        'KR-9a pengiriman ulang formulir yang sama ditolak (master sudah punya akun)');
    $jumlahAkunPengurus = (int) ($satu(
        "SELECT COUNT(*) AS c FROM users WHERE pengurus_id = " . $pengurusId
    )['c'] ?? 0);
    $assert($jumlahAkunPengurus === 1, 'KR-9b hanya ada satu akun untuk satu data pengurus');
    $assert(CredentialFlash::take() === null, 'KR-9c retry tidak menghasilkan pesan kredensial kedua');

    // =====================================================================
    echo PHP_EOL . '=== KR-10 dan KR-11. Login pertama dan ganti password ===' . PHP_EOL;
    // =====================================================================

    $login = new AuthService(auth_repository(), audit_logger());
    $_SESSION = [];
    $assert($login->attempt('kr.guru.' . $kecil, $sandiGuru),
        'KR-10a login pertama dengan password sementara berhasil');
    $assert(!empty($_SESSION['force_password_change']),
        'KR-10b sesi menandai pengguna wajib mengganti password');
    $assert(!$login->attempt('kr.guru.' . $kecil, $sandiGuru . 'salah'),
        'KR-10c password yang keliru tetap ditolak');

    $sandiBaru = 'PasswordBaruUji' . $suffix . '9';
    $assert(auth_repository()->updatePassword($userGuru, password_hash($sandiBaru, PASSWORD_DEFAULT)),
        'KR-11a pengguna dapat menetapkan password baru');
    $_SESSION = [];
    $assert(!$login->attempt('kr.guru.' . $kecil, $sandiGuru),
        'KR-11b password sementara DITOLAK setelah password baru dibuat');
    $_SESSION = [];
    $assert($login->attempt('kr.guru.' . $kecil, $sandiBaru),
        'KR-11c password baru diterima');
    $assert(empty($_SESSION['force_password_change']),
        'KR-11d kewajiban ganti password hilang setelah password diganti');
    $_SESSION['user_id'] = $adminId;

    // =====================================================================
    echo PHP_EOL . '=== KR-12. Alur reset password tidak berubah ===' . PHP_EOL;
    // =====================================================================

    $sandiReset = $akun->resetPassword($userGuru, $adminId);
    $sandiDipakai[] = $sandiReset;
    $assert(is_string($sandiReset) && $sandiReset !== '',
        'KR-12a resetPassword tetap mengembalikan password sementara sebagai string, bukan muatan pesan');
    $barisReset = $satu('SELECT password, force_password_change FROM users WHERE id = ' . $userGuru);
    $assert(password_verify($sandiReset, (string) $barisReset['password'])
        && (int) $barisReset['force_password_change'] === 1,
        'KR-12b reset password menetapkan hash baru dan mewajibkan penggantian');
    $_SESSION = [];
    $assert(!$login->attempt('kr.guru.' . $kecil, $sandiBaru),
        'KR-12c password lama tidak berlaku setelah reset');
    $_SESSION = [];
    $assert($login->attempt('kr.guru.' . $kecil, $sandiReset),
        'KR-12d password hasil reset dapat dipakai masuk');
    $_SESSION = [];
    $_SESSION['user_id'] = $adminId;
    $assert(CredentialFlash::take() === null,
        'KR-12e reset password TIDAK membuat pesan kredensial siap salin');

    // =====================================================================
    echo PHP_EOL . '=== KR-13. Nama dan username berkarakter khusus ===' . PHP_EOL;
    // =====================================================================

    $guruKhusus = (int) $master->saveGuru(['nip' => 'KX' . $suffix, 'nama_guru' => 'Guru Khusus ' . $suffix, 'no_hp' => '']);
    $dibuat['guru'][] = $guruKhusus;
    $namaKhusus = 'Ust. <b>"Fulan"</b> & \'Rekan\' ' . $suffix;
    $hasilKhusus = $akun->createTeacher([
        'guru_id' => $guruKhusus,
        'name' => $namaKhusus,
        'username' => 'kr-x.' . $kecil,
        'email' => '',
        'phone' => '',
    ], $adminId);
    $dibuat['users'][] = (int) $hasilKhusus['id'];
    $sandiDipakai[] = (string) $hasilKhusus['temporary_password'];

    $muatanKhusus = CredentialMessage::forSavedAccount(
        $hasilKhusus['account'],
        (string) $hasilKhusus['temporary_password'],
        'guru'
    );
    $teksKhusus = CredentialMessage::text($muatanKhusus);
    $assert(str_contains($teksKhusus, 'Yth. ' . $namaKhusus . ','),
        'KR-13a nama berkarakter khusus tampil apa adanya pada teks yang disalin');
    $panelKhusus = CredentialPanel::render($muatanKhusus);
    $assert(!str_contains($panelKhusus, '<b>"Fulan"</b>') && str_contains($panelKhusus, '&lt;b&gt;'),
        'KR-13b panel meng-escape nama, tidak membangun HTML mentah darinya');
    $assert(preg_match('#<pre class="ah-kredensial__teks[^"]*" id="ah-kredensial-teks"[^>]*>(.*?)</pre>#s', $panelKhusus, $cocokKhusus) === 1
        && html_entity_decode($cocokKhusus[1], ENT_QUOTES, 'UTF-8') === $teksKhusus,
        'KR-13c isi kotak pesan tetap utuh dan sama dengan teks yang akan disalin');

    // =====================================================================
    echo PHP_EOL . '=== KR-14. Regresi pengelolaan akun ===' . PHP_EOL;
    // =====================================================================

    $rolesDari = static function (int $userId) use ($db): array {
        $hasil = [];
        $rs = $db->query('SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = '
            . $userId . ' ORDER BY r.slug');
        while ($rs && $row = $rs->fetch_assoc()) {
            $hasil[] = $row['slug'];
        }

        return $hasil;
    };

    $assert($rolesDari($userGuru) === ['guru'], 'KR-14a akun guru baru tetap memperoleh tepat satu role guru');
    $assert($rolesDari($userPengurus) === ['pengurus'], 'KR-14b akun pengurus memperoleh role pengurus');
    $assert($rolesDari($userOrtu) === ['orang_tua'], 'KR-14c akun orang tua memperoleh role orang tua');

    $tolak(static fn () => $akun->grantRole($userGuru, 'orang_tua', $adminId),
        'KR-14d role yang menuntut relasi master tetap ditolak tanpa relasi');
    $tolak(static fn () => $akun->grantRole($userGuru, 'admin', $adminId, []),
        'KR-14e pemberian hak admin tetap menuntut konfirmasi yang diketik ulang');

    $akun->grantRole($userGuru, 'admin', $adminId, ['konfirmasi_admin' => \App\Account\AccountService::KONFIRMASI_ADMIN]);
    $assert($rolesDari($userGuru) === ['admin', 'guru'], 'KR-14f akun multi-peran mempertahankan role lain');
    $akun->revokeRole($userGuru, 'admin', $adminId);
    $assert($rolesDari($userGuru) === ['guru'], 'KR-14g pencabutan role tidak menyentuh role lain');

    $tolak(static fn () => $akun->setActive($adminId, false, $adminId),
        'KR-14h admin tetap tidak dapat menonaktifkan akunnya sendiri');
    $akun->setActive($userPengurus, false, $adminId);
    $assert((int) ($satu('SELECT is_active AS a FROM users WHERE id = ' . $userPengurus)['a'] ?? 1) === 0,
        'KR-14i penonaktifan akun tetap berfungsi');
    $_SESSION = [];
    $assert(!$login->attempt('kr.pengurus.' . $kecil, $sandiPengurus),
        'KR-14j akun nonaktif tidak dapat masuk meski password sementara benar');
    $_SESSION['user_id'] = $adminId;
    $akun->setActive($userPengurus, true, $adminId);
    $assert((int) ($satu('SELECT is_active AS a FROM users WHERE id = ' . $userPengurus)['a'] ?? 0) === 1,
        'KR-14k pengaktifan kembali tetap berfungsi');

    // Pemindaian ulang basis data setelah SELURUH langkah di atas.
    $temuanAkhir = [];
    $kolom2 = $db->query(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = '" . $db->real_escape_string($namaDb) . "'
            AND DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext','json')"
    );
    while ($kolom2 && $baris = $kolom2->fetch_assoc()) {
        $tabel = '`' . str_replace('`', '', (string) $baris['TABLE_NAME']) . '`';
        $nama = '`' . str_replace('`', '', (string) $baris['COLUMN_NAME']) . '`';
        foreach ($sandiDipakai as $sandi) {
            $rs = $db->query('SELECT COUNT(*) AS c FROM ' . $tabel . ' WHERE ' . $nama
                . " LIKE '%" . $db->real_escape_string($sandi) . "%'");
            if ((int) (($rs && $r = $rs->fetch_assoc()) ? $r['c'] : 0) > 0) {
                $temuanAkhir[] = $baris['TABLE_NAME'] . '.' . $baris['COLUMN_NAME'];
            }
        }
    }
    $assert($temuanAkhir === [],
        'KR-14l setelah seluruh alur, basis data tetap tidak memuat password sementara mana pun');
} finally {
    // -------------------------------------------------------------------
    // Pembersihan fixture. Dijalankan meski pengujian gagal di tengah.
    // -------------------------------------------------------------------
    $hapus = static function (string $sql) use ($db): void {
        @$db->query($sql);
    };
    if ($dibuat['users'] !== []) {
        $daftar = implode(',', array_map('intval', $dibuat['users']));
        $hapus('DELETE FROM audit_logs WHERE entity_type = \'user\' AND entity_id IN (' . $daftar . ')');
        $hapus('DELETE FROM audit_logs WHERE actor_id IN (' . $daftar . ')');
        $hapus('DELETE FROM user_roles WHERE user_id IN (' . $daftar . ')');
        $hapus('DELETE FROM users WHERE id IN (' . $daftar . ')');
    }
    if ($dibuat['santri'] !== []) {
        $daftar = implode(',', array_map('intval', $dibuat['santri']));
        $hapus('DELETE FROM santri_wali WHERE santri_id IN (' . $daftar . ')');
        $hapus('DELETE FROM santri WHERE id IN (' . $daftar . ')');
    }
    if ($dibuat['wali'] !== []) {
        $hapus('DELETE FROM wali WHERE id IN (' . implode(',', array_map('intval', $dibuat['wali'])) . ')');
    }
    if ($dibuat['pengurus'] !== []) {
        $hapus('DELETE FROM pengurus WHERE id IN (' . implode(',', array_map('intval', $dibuat['pengurus'])) . ')');
    }
    if ($dibuat['guru'] !== []) {
        $hapus('DELETE FROM guru WHERE id IN (' . implode(',', array_map('intval', $dibuat['guru'])) . ')');
    }
    @unlink($logUji);
}

echo PHP_EOL;
if ($failures === []) {
    echo 'SELURUH PENGUJIAN INTEGRASI PESAN KREDENSIAL LULUS.' . PHP_EOL;
    exit(0);
}
echo 'GAGAL: ' . count($failures) . ' pemeriksaan.' . PHP_EOL;
foreach ($failures as $failure) {
    echo ' - ' . $failure . PHP_EOL;
}
exit(1);
