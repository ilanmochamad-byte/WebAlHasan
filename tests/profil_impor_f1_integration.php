<?php

declare(strict_types=1);

/**
 * Uji integrasi Fase 1 profil pengguna dan impor guru/pengurus (migrasi 035)
 * lewat layanan resmi (`ProfilService`, `ImporPersonelService`,
 * `MasterDataService`, `PenugasanService`) di atas fixture sintetis:
 *
 *   PIF1_DB=test_pif1_test bash tests/profil_impor_f1_db.sh
 *   PIF1_DB=test_pif1_test php tests/profil_impor_f1_integration.php
 *
 *   I-1  baca profil Admin/Guru/Pengurus/Orang Tua: hanya data sendiri; status dan
 *        target binaan/mapel–kelas dari penugasan aktif; tugas yang diakhiri hilang
 *   I-2  ubah diri: setiap isian; guru + identitas pengurus milik guru + akun
 *        konsisten; wali + akun; akun tanpa identitas; audit tanpa nilai
 *   I-3  ubah diri ditolak: kunci terlarang, validasi, versi basi, email ganda;
 *        audit gagal → tidak ada pembaruan sebagian
 *   I-4  akses foto: pemilik dan admin saja; lainnya 404
 *   I-5  ganti password: salah/lemah/beda ditolak; berhasil → sesi/token lain
 *        dicabut, token terbatas menjadi penuh; tanpa password/token di audit
 *   I-6  impor: pratinjau tanpa mutasi; simpan guru/pengurus baru dan lama
 *   I-7  impor ulang berkas sama → 0 baris baru, ID/NIP/relasi tetap
 *   I-8  konflik: nomor ganda, NIP–NIK dua orang, nama ambigu, pasangan manual,
 *        timpa/pengenal, identitas milik guru (PI-10), sidik basi, audit gagal
 *   I-9  Tugas/Kamar/Tugas Tambahan tidak mengubah penugasan, role, atau akun
 *   I-10 formulir admin Data Guru/Pengurus/Wali menyamakan identitas terkait
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

use App\Penugasan\PenugasanJenis;
use App\Profil\ImporPersonelService;
use App\Profil\ProfilException;

$root = dirname(__DIR__);
$dbName = getenv('PIF1_DB') ?: 'test_pif1_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once $root . '/app/bootstrap.php';

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$db = app_db();
$q = static function (string $sql, array $p = []) use ($db): array {
    $s = $db->prepare($sql);
    if ($s === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
    $s->execute($p);
    $r = $s->get_result();
    $rows = $r === false ? [] : $r->fetch_all(MYSQLI_ASSOC);
    $s->close();
    return $rows;
};
$satu = static fn (string $sql, array $p = []): mixed => array_values($q($sql, $p)[0] ?? [null])[0];
$exec = static function (string $sql, array $p = []) use ($db): int {
    $s = $db->prepare($sql);
    if ($s === false || !$s->execute($p)) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
    $id = (int) $db->insert_id;
    $s->close();
    return $id;
};
$galat = static function (callable $kerja): ?Throwable {
    try {
        $kerja();
        return null;
    } catch (Throwable $e) {
        return $e;
    }
};
$status = static fn (?Throwable $e): int => $e instanceof ProfilException ? $e->status() : ($e === null ? 0 : -1);
$sidikTabel = static function (array $tabel) use ($q): string {
    $hasil = [];
    foreach ($tabel as $t) {
        $hasil[$t] = $q("SELECT * FROM {$t} ORDER BY 1");
    }
    return hash('sha256', json_encode($hasil));
};
$TABEL_PENUGASAN = ['murobi_assignments', 'pembimbing_assignments', 'guru_mapel_assignments', 'pendidikan_assignments', 'kesehatan_assignments',
    'bendahara_bulanan_assignments', 'keamanan_assignments', 'panitia_psb_assignments', 'bendahara_psb_assignments', 'user_roles'];

$profil = profil_service();
$impor = impor_personel_service();
$master = master_data_service();
$assert($profil->tersedia(), 'Migrasi 035 terpasang dan layanan profil tersedia');

// ---------------------------------------------------------------------------
// Fixture sintetis.
// ---------------------------------------------------------------------------
$_SESSION = ['user_id' => 1];
$adminId = 1;
$tahun = (int) $satu("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1");
$hash = password_hash('Sandbox#123', PASSWORD_DEFAULT);
$akunBaru = static function (string $username, string $nama, array $tautan, array $peran) use ($exec, $hash, $satu): int {
    $id = $exec('INSERT INTO users (name, username, password, guru_id, pengurus_id, wali_id, is_active, force_password_change, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW())',
        [$nama, $username, $hash, $tautan['guru'] ?? null, $tautan['pengurus'] ?? null, $tautan['wali'] ?? null]);
    foreach ($peran as $slug) {
        $exec('INSERT INTO user_roles (user_id, role_id, assigned_at) VALUES (?, ?, NOW())', [$id, (int) $satu('SELECT id FROM roles WHERE slug = ?', [$slug])]);
    }
    return $id;
};
$g1 = $exec("INSERT INTO guru (nip, nama_guru, no_hp, status) VALUES ('PIF1-G-001', 'PIF1 Guru Satu', '081200000101', 'Guru')");
$uG1 = $akunBaru('pif1_guru_satu', 'PIF1 Guru Satu', ['guru' => $g1], ['guru']);
$mulai = (new DateTimeImmutable('-10 days'))->format('Y-m-d');
$kesehatanId = penugasan_service()->buat(PenugasanJenis::KESEHATAN, ['pengurus_id' => 'guru:' . $g1, 'tahun_ajaran_id' => (string) $tahun, 'tanggal_mulai' => $mulai], $adminId);
$pG1 = (int) $satu('SELECT id FROM pengurus WHERE guru_id = ?', [$g1]);
$murobiId = penugasan_service()->buat(PenugasanJenis::MUROBI, ['guru_id' => (string) $g1, 'tahun_ajaran_id' => (string) $tahun, 'target_type' => 'Kamar', 'kamar_id' => '2', 'tanggal_mulai' => $mulai], $adminId);
foreach ([[56, 1], [57, 332]] as [$mapel, $kelas]) {
    penugasan_service()->buat(PenugasanJenis::GURU_MAPEL, ['guru_id' => (string) $g1, 'tahun_ajaran_id' => (string) $tahun, 'mata_pelajaran_id' => (string) $mapel, 'kelas_id' => (string) $kelas, 'tanggal_mulai' => $mulai], $adminId);
}
$p2 = $exec("INSERT INTO pengurus (nama, nomor_identitas, no_hp, jabatan) VALUES ('PIF1 Pengurus Dua', 'PIF1-P-002', '081200000202', 'Bendahara')");
$uP2 = $akunBaru('pif1_pengurus_dua', 'PIF1 Pengurus Dua', ['pengurus' => $p2], ['pengurus']);
penugasan_service()->buat(PenugasanJenis::PEMBIMBING, ['pengurus_id' => (string) $p2, 'tahun_ajaran_id' => (string) $tahun, 'target_type' => 'Kelas', 'kelas_id' => '333', 'tanggal_mulai' => $mulai], $adminId);
$w1 = $exec("INSERT INTO wali (nama, no_hp, alamat) VALUES ('PIF1 Wali Satu', '081200000303', 'Alamat lama wali')");
$uW1 = $akunBaru('pif1_wali_satu', 'PIF1 Wali Satu', ['wali' => $w1], ['orang_tua']);
$uAdmin2 = $akunBaru('pif1_admin_dua', 'PIF1 Admin Dua', [], ['admin']);
$uBiasa = $akunBaru('pif1_guru_lain', 'PIF1 Guru Lain', ['guru' => $exec("INSERT INTO guru (nip, nama_guru, status) VALUES ('PIF1-G-099', 'PIF1 Guru Lain', 'Guru')")], ['guru']);

echo "\n=== I-1 baca profil ===\n";
$pAdmin = $profil->baca($uAdmin2);
$assert($pAdmin['sumber'] === 'akun' && array_column($pAdmin['status'], 'kode') === ['admin'] && $pAdmin['penugasan'] === [] && $pAdmin['akun']['username'] === 'pif1_admin_dua',
    'Admin tanpa identitas: sumber akun, status Admin saja, tanpa penugasan');
$pGuru = $profil->baca($uG1);
$kode = array_column($pGuru['status'], 'kode');
$assert($pGuru['sumber'] === 'guru' && $pGuru['pribadi']['nama'] === 'PIF1 Guru Satu' && $pGuru['pribadi']['nip'] === 'PIF1-G-001' && $pGuru['akun']['id'] === $uG1,
    'Guru: identitas dari data guru miliknya sendiri');
$assert(in_array('guru', $kode, true) && in_array('murobi', $kode, true) && in_array('kesehatan', $kode, true) && in_array('guru_mapel', $kode, true) && !in_array('pengurus', $kode, true),
    'Guru merangkap: status Guru, Murobi, Petugas Kesehatan, Guru Mapel; identitas pengurus milik guru tidak tampil sebagai "Pengurus" kedua');
$assert($pGuru['binaan'] === [['peran' => 'Murobi', 'jenis' => 'murobi', 'tipe' => 'Kamar', 'id' => 2, 'nama' => (string) $satu('SELECT nama_kamar FROM kamar WHERE id = 2')]],
    'Target murobi bertarget kamar tampil sebagai Kamar dengan nama kamar');
$assert(count($pGuru['mengajar']) === 2 && array_column($pGuru['mengajar'], 'kelas_id') === [1, 332] && array_column($pGuru['mengajar'], 'mata_pelajaran') === ['Fiqih', 'Nahwu'],
    'Guru Mapel: kedua pasangan mapel–kelas aktif tampil');
$pPeng = $profil->baca($uP2);
$assert($pPeng['sumber'] === 'pengurus' && array_column($pPeng['status'], 'kode') === ['pengurus', 'pembimbing'] && ($pPeng['binaan'][0]['tipe'] ?? '') === 'Kelas' && $pPeng['pribadi']['nip'] === 'PIF1-P-002',
    'Pengurus: status Pengurus + Pembimbing; target kelas berlabel Kelas; NIP = nomor identitas (PI-9)');
$pWali = $profil->baca($uW1);
$assert($pWali['sumber'] === 'wali' && array_column($pWali['status'], 'kode') === ['orang_tua'] && $pWali['pribadi']['alamat_rumah'] === 'Alamat lama wali' && $pWali['binaan'] === [],
    'Orang Tua: status Orang Tua; alamat lama wali tampil; tanpa kamar rekaan');
$assert($pGuru['foto']['ada'] === false && $pGuru['foto']['inisial'] === 'PG', 'Tanpa foto: inisial dari nama');
penugasan_service()->akhiri(PenugasanJenis::MUROBI, $murobiId, (new DateTimeImmutable('-1 day'))->format('Y-m-d'), 'Uji profil: penugasan diakhiri', $adminId);
$pGuru = $profil->baca($uG1);
$assert(!in_array('murobi', array_column($pGuru['status'], 'kode'), true) && $pGuru['binaan'] === [], 'Penugasan murobi yang diakhiri hilang dari status dan target binaan');

echo "\n=== I-2 ubah diri ===\n";
$audit0 = (int) $satu('SELECT MAX(id) FROM audit_logs');
$isian = ['nama' => 'PIF1 Guru Satu Baru', 'tempat_lahir' => 'Ciamis', 'tanggal_lahir' => '1990-02-03', 'alamat_rumah' => 'Jl. Uji No. 7', 'kelurahan' => 'Desa Uji',
    'kecamatan' => 'Kec Uji', 'kabupaten' => 'Kab Uji', 'provinsi' => 'Jawa Barat', 'no_hp' => '+62 812-0000-0111', 'email' => 'PIF1.Guru@Example.TEST', 'versi' => 0];
$hasil = $profil->ubahDiri(['id' => $uG1], $isian);
$guru = $q('SELECT nama_guru, no_hp FROM guru WHERE id = ?', [$g1])[0];
$peng = $q('SELECT nama, no_hp FROM pengurus WHERE id = ?', [$pG1])[0];
$akun = $q('SELECT name, phone, email FROM users WHERE id = ?', [$uG1])[0];
$assert($guru === ['nama_guru' => 'PIF1 Guru Satu Baru', 'no_hp' => '081200000111'] && $peng === ['nama' => 'PIF1 Guru Satu Baru', 'no_hp' => '081200000111']
    && $akun === ['name' => 'PIF1 Guru Satu Baru', 'phone' => '081200000111', 'email' => 'pif1.guru@example.test'],
    'Guru merangkap pengurus: nama/HP sama pada guru, identitas pengurus, dan akun; email akun ikut');
$pr = $q("SELECT * FROM profil_pribadi WHERE jenis_pemilik = 'guru' AND guru_id = ?", [$g1])[0] ?? [];
$assert(($pr['tempat_lahir'] ?? '') === 'Ciamis' && ($pr['tanggal_lahir'] ?? '') === '1990-02-03' && ($pr['kabupaten'] ?? '') === 'Kab Uji' && ($pr['email'] ?? '') === 'pif1.guru@example.test'
    && (int) $satu('SELECT COUNT(*) FROM profil_pribadi WHERE pengurus_id = ?', [$pG1]) === 0,
    'Satu profil pribadi milik guru; identitas pengurus milik guru tidak punya salinan profil');
$assert($hasil['pribadi']['nama'] === 'PIF1 Guru Satu Baru' && $hasil['pribadi']['provinsi'] === 'Jawa Barat' && $hasil['versi'] === 1, 'Respons memuat nilai baru dan versi 1');
$audit = $q("SELECT * FROM audit_logs WHERE id > ? AND action = 'profil.ubah_diri'", [$audit0]);
$teksAudit = json_encode($q('SELECT before_json, after_json FROM audit_logs WHERE id > ?', [$audit0]));
$assert(count($audit) === 1 && str_contains((string) $audit[0]['after_json'], '"field_berubah"') && !str_contains($teksAudit, 'Ciamis') && !str_contains($teksAudit, '0111')
    && !str_contains($teksAudit, 'example.test') && !str_contains($teksAudit, 'Jl. Uji'), 'Satu audit profil.ubah_diri berisi nama isian saja, tanpa nilai pribadi');
$jumlahAudit = (int) $satu('SELECT COUNT(*) FROM audit_logs');
$profil->ubahDiri(['id' => $uG1], array_diff_key($isian, ['versi' => 1]));
$assert((int) $satu('SELECT COUNT(*) FROM audit_logs') === $jumlahAudit && (int) $satu('SELECT versi FROM profil_pribadi WHERE guru_id = ?', [$g1]) === 1,
    'Isian yang sama tidak menulis apa pun (tanpa audit, versi tetap)');
$profil->ubahDiri(['id' => $uG1], ['tempat_lahir' => '', 'versi' => 1]);
$assert($satu('SELECT tempat_lahir FROM profil_pribadi WHERE guru_id = ?', [$g1]) === null && $satu('SELECT kecamatan FROM profil_pribadi WHERE guru_id = ?', [$g1]) === 'Kec Uji',
    'Kunci yang dikirim kosong mengosongkan isian itu; isian lain tetap');
$profil->ubahDiri(['id' => $uW1], ['nama' => 'PIF1 Wali Satu Baru', 'no_hp' => '081200000333', 'alamat_rumah' => 'Jl. Wali 1', 'kelurahan' => 'Desa W', 'provinsi' => 'Banten']);
$wali = $q('SELECT nama, no_hp, alamat FROM wali WHERE id = ?', [$w1])[0];
$assert($wali === ['nama' => 'PIF1 Wali Satu Baru', 'no_hp' => '081200000333', 'alamat' => 'Jl. Wali 1, Desa W, Banten']
    && $q('SELECT name, phone FROM users WHERE id = ?', [$uW1])[0] === ['name' => 'PIF1 Wali Satu Baru', 'phone' => '081200000333']
    && (int) $satu("SELECT COUNT(*) FROM profil_pribadi WHERE jenis_pemilik = 'wali' AND wali_id = ?", [$w1]) === 1,
    'Orang Tua: wali, alamat lama tersusun, dan akun konsisten; profil menempel pada wali');
$profil->ubahDiri(['id' => $uAdmin2], ['nama' => 'PIF1 Admin Dua Baru', 'kabupaten' => 'Kota Uji']);
$assert($satu('SELECT name FROM users WHERE id = ?', [$uAdmin2]) === 'PIF1 Admin Dua Baru' && (int) $satu("SELECT COUNT(*) FROM profil_pribadi WHERE jenis_pemilik = 'akun' AND user_id = ?", [$uAdmin2]) === 1,
    'Akun tanpa identitas master: profil menempel pada akun (PI-7)');
$profil->ubahDiri(['id' => $uP2], ['no_hp' => '081200000222', 'tempat_lahir' => 'Tasikmalaya']);
$assert($satu('SELECT no_hp FROM pengurus WHERE id = ?', [$p2]) === '081200000222' && $satu('SELECT phone FROM users WHERE id = ?', [$uP2]) === '081200000222'
    && $satu('SELECT nomor_identitas FROM pengurus WHERE id = ?', [$p2]) === 'PIF1-P-002', 'Pengurus biasa: HP pengurus dan akun sama; NIP tidak tersentuh');

echo "\n=== I-3 ubah diri ditolak ===\n";
$potret = static fn (): string => hash('sha256', json_encode([
    $q('SELECT * FROM guru WHERE id = ?', [$g1]), $q('SELECT * FROM pengurus WHERE id = ?', [$pG1]), $q('SELECT id, name, username, phone, email, password, guru_id, pengurus_id, wali_id FROM users WHERE id = ?', [$uG1]),
    $q('SELECT * FROM profil_pribadi WHERE guru_id = ?', [$g1]), $q('SELECT * FROM user_roles WHERE user_id = ?', [$uG1]),
]));
$sebelum = $potret();
$terlarang = ['username' => 'peretas', 'roles' => ['admin'], 'role' => 'admin', 'penugasan' => [], 'kamar' => 'X', 'mapel' => 'Y', 'kelas' => 'Z', 'status' => 'Murobi',
    'nip' => 'X1', 'nik' => '3200000000000999', 'guru_id' => 1, 'pengurus_id' => 1, 'wali_id' => 1, 'user_id' => 1, 'id' => 1, 'foto' => 'a.jpg', 'password' => 'Xx123456789012', 'is_active' => 0];
$semuaDitolak = true;
foreach ($terlarang as $kunci => $nilai) {
    $e = $galat(static fn () => $profil->ubahDiri(['id' => $uG1], ['nama' => 'Peretas', $kunci => $nilai]));
    $semuaDitolak = $semuaDitolak && $status($e) === 422 && isset($e->errors()[$kunci]);
}
$assert($semuaDitolak && $potret() === $sebelum, 'Username/role/penugasan/kamar/mapel/kelas/NIP/NIK/ID akun lain/foto/password lewat edit diri ditolak 422 tanpa perubahan');
foreach ([['email' => 'bukan-email'], ['no_hp' => '12345'], ['tanggal_lahir' => '2999-01-01'], ['tanggal_lahir' => '31-02-1990'], ['nama' => ''], ['nama' => str_repeat('a', 101)], ['nama' => 'محمد']] as $salah) {
    $e = $galat(static fn () => $profil->ubahDiri(['id' => $uG1], $salah));
    $assert($status($e) === 422 && $potret() === $sebelum, 'Validasi server menolak ' . json_encode($salah, JSON_UNESCAPED_UNICODE) . ' tanpa perubahan');
}
$assert($status($galat(static fn () => $profil->ubahDiri(['id' => $uG1], ['nama' => 'Basi', 'versi' => 0]))) === 409 && $potret() === $sebelum, 'Versi basi → 409 tanpa perubahan');
$exec("UPDATE users SET email = 'dipakai@example.test' WHERE id = ?", [$uBiasa]);
$assert($status($galat(static fn () => $profil->ubahDiri(['id' => $uG1], ['email' => 'DIPAKAI@example.test']))) === 409 && $potret() === $sebelum, 'Email milik akun lain → 409 tanpa perubahan');
$db->query('DROP TRIGGER IF EXISTS pif1_audit_gagal');
$db->query("CREATE TRIGGER pif1_audit_gagal BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action = @pif1_gagal THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit uji gagal'; END IF; END");
$db->query("SET @pif1_gagal = 'profil.ubah_diri'");
$e = $galat(static fn () => $profil->ubahDiri(['id' => $uG1], ['nama' => 'PIF1 Gagal Audit', 'no_hp' => '081299999999', 'kecamatan' => 'Gagal']));
$assert($status($e) === 503 && $potret() === $sebelum, 'Audit gagal → 503; guru, identitas pengurus, akun, dan profil tidak berubah sebagian');
$db->query('SET @pif1_gagal = NULL');

echo "\n=== I-4 akses foto ===\n";
$folder = $root . '/storage/private/profil';
@mkdir($folder, 0750, true);
$namaFoto = str_repeat('a', 32) . '.png';
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
file_put_contents($folder . '/' . $namaFoto, $png);
$exec("UPDATE profil_pribadi SET foto = ?, foto_versi = foto_versi + 1 WHERE guru_id = ?", [$namaFoto, $g1]);
$foto = $profil->foto(['id' => $uG1], $uG1);
$assert($foto['mime'] === 'image/png' && is_file($foto['path']), 'Pemilik membaca foto sendiri');
$assert($profil->foto(['id' => 1], $uG1)['mime'] === 'image/png', 'Admin membaca foto akun lain');
foreach ([$uP2 => 'pengurus', $uW1 => 'orang tua', $uBiasa => 'guru lain'] as $penonton => $label) {
    $assert($status($galat(static fn () => $profil->foto(['id' => $penonton], $uG1))) === 404, "Akun $label tidak dapat membaca foto akun lain (404)");
}
$assert($status($galat(static fn () => $profil->foto(['id' => 1], 999999))) === 404 && $status($galat(static fn () => $profil->foto(['id' => $uP2], $uP2))) === 404,
    'Akun tidak ada / tanpa foto → 404');
$exec('UPDATE users SET force_password_change = 1 WHERE id = ?', [$uAdmin2]);
$assert($status($galat(static fn () => $profil->foto(['id' => $uAdmin2], $uG1))) === 404, 'Admin berpassword sementara tidak membaca foto akun lain');
$exec('UPDATE users SET force_password_change = 0 WHERE id = ?', [$uAdmin2]);
$assert(!str_contains((string) json_encode($profil->baca($uG1)), $namaFoto), 'Nama berkas foto tidak pernah dikirim ke klien');

echo "\n=== I-5 ganti password ===\n";
$token = static fn (int $user, string $cakupan = 'penuh') => $exec("INSERT INTO api_tokens (user_id, token_hash, name, cakupan, expires_at, created_at) VALUES (?, ?, 'uji', ?, DATE_ADD(NOW(), INTERVAL 1 DAY), NOW())",
    [$user, bin2hex(random_bytes(32)), $cakupan]);
$tSekarang = $token($uP2, 'ganti_password');
$tLain = [$token($uP2), $token($uP2)];
$tAkunLain = $token($uG1);
$exec("INSERT INTO perangkat_push (user_id, token_hash, platform, device_id) VALUES (?, ?, 'android', 'hp-ini'), (?, ?, 'android', 'hp-lain')", [$uP2, hash('sha256', 'a' . $uP2), $uP2, hash('sha256', 'b' . $uP2)]);
$hashLama = (string) $satu('SELECT password FROM users WHERE id = ?', [$uP2]);
$versiLama = (int) $satu('SELECT sesi_versi FROM users WHERE id = ?', [$uP2]);
$konteks = ['token_id' => $tSekarang, 'device_id' => 'hp-ini', 'kanal' => 'api'];
$baru = 'PasswordBaru#2026';
foreach ([
    'password saat ini salah' => ['password_saat_ini' => 'Salah#123', 'password_baru' => $baru, 'konfirmasi_password' => $baru],
    'password baru lemah' => ['password_saat_ini' => 'Sandbox#123', 'password_baru' => 'pendek1A', 'konfirmasi_password' => 'pendek1A'],
    'tanpa angka' => ['password_saat_ini' => 'Sandbox#123', 'password_baru' => 'TanpaAngkaSama', 'konfirmasi_password' => 'TanpaAngkaSama'],
    'bcrypt melebihi 72 byte' => ['password_saat_ini' => 'Sandbox#123', 'password_baru' => 'Aa1' . str_repeat('x', 70), 'konfirmasi_password' => 'Aa1' . str_repeat('x', 70)],
    'byte NUL' => ['password_saat_ini' => 'Sandbox#123', 'password_baru' => $baru . chr(0), 'konfirmasi_password' => $baru . chr(0)],
    'konfirmasi beda' => ['password_saat_ini' => 'Sandbox#123', 'password_baru' => $baru, 'konfirmasi_password' => $baru . 'x'],
] as $label => $isianPw) {
    $e = $galat(static fn () => $profil->gantiPassword($uP2, $isianPw, $konteks));
    $assert($status($e) === 422 && $satu('SELECT password FROM users WHERE id = ?', [$uP2]) === $hashLama && (int) $satu('SELECT COUNT(*) FROM api_tokens WHERE user_id = ? AND revoked_at IS NULL', [$uP2]) === 3,
        "Ganti password ditolak ($label); hash dan token tidak berubah");
}
$profil->catatPasswordGagal($uP2, 'api');
$exec('UPDATE users SET force_password_change = 1 WHERE id = ?', [$uP2]);
$auditPw = (int) $satu('SELECT MAX(id) FROM audit_logs');
$hasilPw = $profil->gantiPassword($uP2, ['password_saat_ini' => 'Sandbox#123', 'password_baru' => $baru, 'konfirmasi_password' => $baru], $konteks);
$akunPw = $q('SELECT password, force_password_change, sesi_versi FROM users WHERE id = ?', [$uP2])[0];
$assert(password_verify($baru, (string) $akunPw['password']) && (int) $akunPw['force_password_change'] === 0 && (int) $akunPw['sesi_versi'] === $versiLama + 1 && $hasilPw['sesi_versi'] === $versiLama + 1,
    'Password baru sah diterima; wajib ganti dilepas; versi sesi naik (sesi web lain berhenti)');
$assert($satu('SELECT revoked_at FROM api_tokens WHERE id = ?', [$tSekarang]) === null && $satu('SELECT cakupan FROM api_tokens WHERE id = ?', [$tSekarang]) === 'penuh'
    && (int) $satu('SELECT COUNT(*) FROM api_tokens WHERE id IN (?, ?) AND revoked_at IS NOT NULL', $tLain) === 2 && $hasilPw['token_lain_dicabut'] === 2
    && $satu('SELECT revoked_at FROM api_tokens WHERE id = ?', [$tAkunLain]) === null,
    'Token sekarang tetap dan menjadi penuh; dua token lain akun ini dicabut; token akun lain tidak tersentuh');
$assert($q("SELECT device_id, dicabut_pada IS NOT NULL AS dicabut FROM perangkat_push WHERE user_id = ? ORDER BY device_id", [$uP2]) === [['device_id' => 'hp-ini', 'dicabut' => 0], ['device_id' => 'hp-lain', 'dicabut' => 1]],
    'Perangkat push lain dicabut; perangkat yang dipakai tetap');
$teks = json_encode($q('SELECT action, before_json, after_json FROM audit_logs WHERE id > ? OR action LIKE ?', [$auditPw, 'profil.password%'])) . json_encode($hasilPw);
$assert(!str_contains($teks, $baru) && !str_contains($teks, 'Sandbox#123') && !str_contains($teks, (string) $akunPw['password'])
    && (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'profil.password_diganti' AND entity_id = ?", [$uP2]) === 1
    && (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'profil.password_gagal' AND entity_id = ?", [$uP2]) === 1,
    'Audit/respons tanpa password, hash, atau token; jejak berhasil dan gagal tercatat');
$db->query("SET @pif1_gagal = 'profil.password_diganti'");
$hashSekarang = (string) $satu('SELECT password FROM users WHERE id = ?', [$uP2]);
$tBaru = $token($uP2);
$e = $galat(static fn () => $profil->gantiPassword($uP2, ['password_saat_ini' => $baru, 'password_baru' => 'PasswordLagi#2026', 'konfirmasi_password' => 'PasswordLagi#2026'], ['kanal' => 'web']));
$assert($status($e) === 503 && $satu('SELECT password FROM users WHERE id = ?', [$uP2]) === $hashSekarang && $satu('SELECT revoked_at FROM api_tokens WHERE id = ?', [$tBaru]) === null,
    'Audit ganti password gagal → password dan token tidak berubah');
$db->query('SET @pif1_gagal = NULL');

echo "\n=== I-6 impor: pratinjau dan simpan ===\n";
$g3 = $exec("INSERT INTO guru (nip, nama_guru, no_hp, status) VALUES ('PIF1-G-003', 'PIF1 Guru Tiga', NULL, 'Guru')");
$p3 = $exec("INSERT INTO pengurus (nama, nomor_identitas, no_hp, jabatan) VALUES ('PIF1 Pengurus Tiga', 'PIF1-P-003', NULL, 'Keamanan Lama')");
$uP3 = $akunBaru('pif1_pengurus_tiga', 'PIF1 Pengurus Tiga', ['pengurus' => $p3], ['pengurus']);
$barisGuru = [
    ['baris' => 2, 'nama' => 'PIF1 Guru Baru', 'nip' => 'PIF1-G-NEW', 'nik' => '3200000000000101', 'tempat_lahir' => 'Garut', 'tanggal_lahir' => 32874, 'no_hp' => 81200000444,
        'email' => 'guru.baru@example.test', 'tugas' => 'Murobi', 'kamar' => 'SBX Kamar A', 'tugas_tambahan' => 'Keamanan'],
    ['baris' => 3, 'nama' => 'PIF1 Guru Tiga', 'nip' => 'pif1-g-003', 'nik' => '3200000000000103', 'tempat_lahir' => 'Bandung', 'tanggal_lahir' => '05/06/1985', 'alamat_rumah' => 'Jl. Tiga',
        'kelurahan' => 'Desa 3', 'kecamatan' => 'Kec 3', 'kabupaten' => 'Kab 3', 'provinsi' => 'Jawa Barat', 'no_hp' => '081200000555', 'tugas' => 'Guru Mapel', 'kamar' => 'SBX Kamar B'],
];
$barisPengurus = [
    ['baris' => 2, 'nama' => 'PIF1 Pengurus Baru', 'nip_nik' => '3200000000000201', 'no_hp' => '081200000666', 'tugas' => 'Pembimbing', 'kamar' => 'SBX Kamar C', 'tugas_tambahan' => 'BSA'],
    ['baris' => 3, 'nama' => 'PIF1 Pengurus Tiga', 'nip_nik' => 'PIF1-P-003', 'tempat_lahir' => 'Sumedang', 'email' => 'pengurus.tiga@example.test', 'tugas' => 'Kesehatan'],
];
$tabelInti = ['guru', 'pengurus', 'profil_pribadi', 'users'];
$sidikInti = $sidikTabel($tabelInti);
$sidikTugas = $sidikTabel($TABEL_PENUGASAN);
$jumlah = static fn (): array => [(int) $satu('SELECT COUNT(*) FROM guru'), (int) $satu('SELECT COUNT(*) FROM pengurus'), (int) $satu('SELECT COUNT(*) FROM users'), (int) $satu('SELECT COUNT(*) FROM api_tokens')];
$jumlah0 = $jumlah();
$prG = $impor->periksa('guru', $barisGuru);
$prP = $impor->periksa('pengurus', $barisPengurus);
$aksi = static fn (array $r): array => array_column($r['hasil'], 'aksi', 'baris');
$assert($aksi($prG) === [2 => 'tambah', 3 => 'perbarui'] && $aksi($prP) === [2 => 'tambah', 3 => 'perbarui'], 'Pratinjau: guru baru/lama dan pengurus baru/lama memperoleh tindakan per baris');
$assert($sidikTabel($tabelInti) === $sidikInti && $jumlah() === $jumlah0, 'Pratinjau tidak mengubah basis data');
$r2 = $prG['hasil'][0];
$assert(($r2['informasi']['tugas'] ?? '') === 'Murobi' && ($r2['informasi']['kamar'] ?? '') === 'SBX Kamar A' && $r2['peringatan'] !== [] && !array_key_exists('data', $r2),
    'Tugas/Kamar/Tugas Tambahan tampil sebagai informasi dengan peringatan bukan penugasan');
$baru = array_column($r2['perubahan'], 'baru', 'field');
$assert(($baru['tanggal_lahir'] ?? '') === '1990-01-01' && ($baru['no_hp'] ?? '') === '081200000444' && ($prG['hasil'][1]['sasaran']['id'] ?? 0) === $g3 && ($prG['hasil'][1]['sasaran']['cocok_lewat'] ?? '') === 'NIP',
    'Sel angka Excel: nomor seri tanggal dan HP tanpa nol awal dibaca benar; NIP lama cocok tanpa membedakan huruf');
$assert(($prP['hasil'][0]['nik'] ?? '') === '3200000000000201' && ($prP['hasil'][1]['nip'] ?? '') === 'PIF1-P-003', 'Kolom "NIP atau NIK" otomatis: 16 digit = NIK, lainnya = NIP');
$pilihan = static function (array $periksa, array $tambahan = []): array {
    $hasil = [];
    foreach ($periksa['hasil'] as $b) {
        $hasil[$b['baris']] = ($tambahan[$b['baris']] ?? []) + ['sidik' => $b['sidik']];
    }
    return $hasil;
};
$simG = $impor->simpan('guru', $barisGuru, $pilihan($prG), $adminId, ['ukuran' => 1234, 'sha256' => str_repeat('b', 64)]);
$simP = $impor->simpan('pengurus', $barisPengurus, $pilihan($prP), $adminId);
$gBaru = (int) $satu("SELECT id FROM guru WHERE nip = 'PIF1-G-NEW'");
$pBaru = (int) $satu("SELECT p.id FROM pengurus p JOIN profil_pribadi pr ON pr.pengurus_id = p.id WHERE pr.nik = '3200000000000201'");
$assert($simG['ditambah'] === 1 && $simG['diperbarui'] === 1 && $simP['ditambah'] === 1 && $simP['diperbarui'] === 1 && $gBaru > 0 && $pBaru > 0,
    'Simpan: satu guru baru, satu guru lama, satu pengurus baru, satu pengurus lama');
$assert($jumlah() === [$jumlah0[0] + 1, $jumlah0[1] + 1, $jumlah0[2], $jumlah0[3]], 'Tidak ada akun maupun token yang dibuat dari Excel');
$assert($satu('SELECT jabatan FROM pengurus WHERE id = ?', [$pBaru]) === 'Pengurus' && $satu('SELECT jabatan FROM pengurus WHERE id = ?', [$p3]) === 'Keamanan Lama',
    'Pengurus baru berjabatan "Pengurus"; jabatan pengurus lama tidak diubah impor (PI-8)');
$assert($q('SELECT nip, nama_guru, no_hp FROM guru WHERE id = ?', [$g3])[0] === ['nip' => 'PIF1-G-003', 'nama_guru' => 'PIF1 Guru Tiga', 'no_hp' => '081200000555']
    && $satu('SELECT tanggal_lahir FROM profil_pribadi WHERE guru_id = ?', [$g3]) === '1985-06-05' && $satu('SELECT nik FROM profil_pribadi WHERE guru_id = ?', [$g3]) === '3200000000000103',
    'Guru lama: isian kosong terisi, NIP lama tetap');
$assert($satu('SELECT email FROM users WHERE id = ?', [$uP3]) === 'pengurus.tiga@example.test' && $satu('SELECT email FROM profil_pribadi WHERE pengurus_id = ?', [$p3]) === 'pengurus.tiga@example.test',
    'Email pengurus lama ikut disamakan ke akun terhubung');
$auditImpor = $q("SELECT after_json FROM audit_logs WHERE action IN ('impor_personel.simpan', 'profil.impor_baris') ORDER BY id");
$teksImpor = json_encode($auditImpor);
$assert(count($auditImpor) === 6 && !str_contains($teksImpor, 'Garut') && !str_contains($teksImpor, '3200000000000101') && !str_contains($teksImpor, 'PIF1 Guru Baru') && str_contains($teksImpor, str_repeat('b', 64)),
    'Audit ringkasan impor + per baris tanpa data pribadi mentah (hanya ID, tindakan, nama isian, sidik berkas)');

echo "\n=== I-7 impor ulang ===\n";
$idSebelum = $q("SELECT id, nip FROM guru WHERE nama_guru LIKE 'PIF1%' ORDER BY id");
$relasi = $sidikTabel(['users', 'user_roles', 'santri_wali']);
$jumlah1 = $jumlah();
$ulangG = $impor->periksa('guru', $barisGuru);
$ulangP = $impor->periksa('pengurus', $barisPengurus);
$assert($aksi($ulangG) === [2 => 'tidak_berubah', 3 => 'tidak_berubah'] && $aksi($ulangP) === [2 => 'tidak_berubah', 3 => 'tidak_berubah'], 'Berkas yang sama: seluruh baris "tidak berubah"');
$s2 = $impor->simpan('guru', $barisGuru, $pilihan($ulangG), $adminId);
$s3 = $impor->simpan('pengurus', $barisPengurus, $pilihan($ulangP), $adminId);
$assert($s2['disimpan'] === 0 && $s3['disimpan'] === 0 && $jumlah() === $jumlah1 && $q("SELECT id, nip FROM guru WHERE nama_guru LIKE 'PIF1%' ORDER BY id") === $idSebelum && $sidikTabel(['users', 'user_roles', 'santri_wali']) === $relasi,
    'Impor kedua: 0 guru/pengurus baru, ID/NIP/relasi tetap');

echo "\n=== I-8 konflik ===\n";
$sidikInti = $sidikTabel($tabelInti);
$jumlah2 = $jumlah();
$k = $impor->periksa('guru', [
    ['baris' => 2, 'nama' => 'PIF1 Ganda A', 'nik' => '3200000000000901'],
    ['baris' => 3, 'nama' => 'PIF1 Ganda B', 'nik' => '3200000000000901'],
    ['baris' => 4, 'nama' => 'PIF1 Silang', 'nip' => 'PIF1-G-003', 'nik' => '3200000000000101'],
    ['baris' => 5, 'nama' => 'PIF1 Tanpa Pengenal', 'no_hp' => '081200000777'],
    ['baris' => 6, 'nama' => 'PIF1 NIK Pendek', 'nik' => '320000000000010'],
    ['baris' => 7, 'nama' => 'PIF1 HP A', 'nip' => 'PIF1-HP-A', 'no_hp' => '081200000888'],
    ['baris' => 8, 'nama' => 'PIF1 HP B', 'nip' => 'PIF1-HP-B', 'no_hp' => '081200000888'],
    ['baris' => 9, 'nama' => 'PIF1 Presisi', 'nik' => '#PRESISI'],
    ['baris' => 13, 'nama' => 'PIF1 NIK Numerik', 'nik' => 3200000000000902],
    ['baris' => 14, 'nama' => 'PIF1 NIP Pecahan', 'nip' => 12.5],
    ['baris' => 10, 'nama' => 'Guru ' . "\u{0645}\u{062D}\u{0645}\u{062F}", 'nip' => 'PIF1-ARAB'],
]);
$kSama = $impor->periksa('guru', [
    ['baris' => 11, 'nama' => 'PIF1 Sasaran Sama 1', 'nip' => 'PIF1-G-NEW'],
    ['baris' => 12, 'nama' => 'PIF1 Sasaran Sama 2', 'nik' => '3200000000000101'],
]);
$assert(array_values(array_unique(array_column($k['hasil'], 'aksi'))) === ['konflik'] && $k['siap'] === 0, 'Seluruh baris bermasalah menjadi konflik');
$masalah = array_column($k['hasil'], 'masalah', 'baris');
$assert(str_contains(implode(' ', $masalah[2]), 'ganda') && str_contains(implode(' ', $masalah[3]), 'ganda'), 'NIK ganda di dalam berkas → kedua baris konflik');
$assert(str_contains(implode(' ', $masalah[4]), 'dua guru berbeda'), 'NIP dan NIK yang menunjuk dua orang berbeda → konflik');
$assert(str_contains(implode(' ', $masalah[5]), 'NIP atau NIK wajib') && str_contains(implode(' ', $masalah[6]), '16 digit'), 'Tanpa NIP/NIK atau NIK bukan 16 digit → konflik');
$assert(str_contains(implode(' ', $masalah[7]), 'ganda') && str_contains(implode(' ', $masalah[8]), 'ganda'), 'HP ganda di dalam berkas → konflik');
$assert(str_contains(implode(' ', $masalah[9]), 'teks') && str_contains(implode(' ', $masalah[10]), 'Latin'), 'Sel angka kehilangan presisi dan nama di luar latin1 (tabel guru) → konflik');
$assert(str_contains(implode(' ', $masalah[13]), 'teks') && str_contains(implode(' ', $masalah[14]), 'teks'),
    'NIK numerik 16 digit dan NIP pecahan ditolak tanpa pembulatan');
$pastiId = $impor->periksa('guru', [
    ['nama' => 'PIF1 Nama Berbeda', 'id_sistem' => $g1, 'nip' => 'PIF1-ID-BARU'],
    ['nama' => 'PIF1 ID Bertentangan', 'id_sistem' => $g1, 'nip' => 'PIF1-G-003'],
    ['nama' => 'PIF1 ID Tidak Ada', 'id_sistem' => 99999999, 'nip' => 'PIF1-ID-HILANG'],
]);
$assert(array_column($pastiId['hasil'], 'aksi') === ['perlu_konfirmasi', 'konflik', 'konflik']
    && $pastiId['hasil'][0]['sasaran']['cocok_lewat'] === 'ID Sistem'
    && str_contains(implode(' ', $pastiId['hasil'][1]['masalah']), 'bertentangan'),
    'ID Sistem memilih sasaran tanpa mengandalkan nama; NIP milik orang lain atau ID hilang menjadi konflik');
$masalahSama = array_column($kSama['hasil'], 'masalah', 'baris');
$assert(array_column($kSama['hasil'], 'aksi') === ['konflik', 'konflik'] && str_contains(implode(' ', $masalahSama[11]), 'data lama yang sama') && str_contains(implode(' ', $masalahSama[12]), 'data lama yang sama'),
    'Dua baris menunjuk guru lama yang sama (lewat NIP dan lewat NIK) → keduanya konflik');
$simK = $impor->simpan('guru', array_map(static fn ($r) => $r, [['baris' => 2, 'nama' => 'PIF1 Ganda A', 'nik' => '3200000000000901'], ['baris' => 3, 'nama' => 'PIF1 Ganda B', 'nik' => '3200000000000901']]),
    $pilihan($k), $adminId);
$assert($simK['disimpan'] === 0 && $sidikTabel($tabelInti) === $sidikInti && $jumlah() === $jumlah2, 'Simpan baris konflik tidak membuat catatan apa pun');

// Nama ambigu dan pasangan manual (PI-3).
$kembar1 = $exec("INSERT INTO guru (nama_guru, status) VALUES ('PIF1 Kembar', 'Guru')");
$kembar2 = $exec("INSERT INTO guru (nama_guru, status) VALUES ('pif1  kembar', 'Guru')");
$exec("UPDATE guru SET nama_guru = 'pif1 kembar' WHERE id = ?", [$kembar2]);
$jumlah2 = $jumlah();
$barisKembar = [['baris' => 2, 'nama' => 'PIF1 Kembar', 'nik' => '3200000000000777', 'tempat_lahir' => 'Kuningan']];
$pk = $impor->periksa('guru', $barisKembar);
$assert(($pk['hasil'][0]['aksi'] ?? '') === 'pilih_pasangan' && array_column($pk['hasil'][0]['kandidat'], 'id') === [$kembar1, $kembar2], 'Nama sama tanpa NIP/NIK cocok → "pilih pasangan" dengan dua calon; tidak digabung otomatis');
$sm = $impor->simpan('guru', $barisKembar, $pilihan($pk), $adminId);
$assert($sm['disimpan'] === 0 && (int) $satu("SELECT COUNT(*) FROM profil_pribadi WHERE nik = '3200000000000777'") === 0 && $jumlah() === $jumlah2, 'Pasangan ambigu tanpa pilihan admin tidak disimpan');
$pk2 = $impor->periksa('guru', $barisKembar, [2 => ['aksi' => 'pasangan', 'pasangan_id' => $kembar2]]);
$assert(($pk2['hasil'][0]['aksi'] ?? '') === 'perlu_konfirmasi' && $pk2['hasil'][0]['menimpa'] === ['nama'], 'Pasangan pilihan admin dengan ejaan nama berbeda → konfirmasi timpa nama');
$pk2 = $impor->periksa('guru', $barisKembar, [2 => ['aksi' => 'pasangan', 'pasangan_id' => $kembar2, 'timpa' => true]]);
$assert(($pk2['hasil'][0]['aksi'] ?? '') === 'perbarui' && ($pk2['hasil'][0]['sasaran']['cocok_lewat'] ?? '') === 'pilihan admin', 'Admin memilih pasangan → perbarui data lama terpilih');
$salahPilih = $impor->periksa('guru', $barisKembar, [2 => ['aksi' => 'pasangan', 'pasangan_id' => $g1]]);
$assert(($salahPilih['hasil'][0]['aksi'] ?? '') === 'konflik', 'Pasangan di luar daftar calon ditolak');
$impor->simpan('guru', $barisKembar, $pilihan($pk2, [2 => ['aksi' => 'pasangan', 'pasangan_id' => $kembar2, 'timpa' => true]]), $adminId);
$assert($satu('SELECT nik FROM profil_pribadi WHERE guru_id = ?', [$kembar2]) === '3200000000000777' && $satu('SELECT COUNT(*) FROM profil_pribadi WHERE guru_id = ?', [$kembar1]) === 0 && $jumlah() === $jumlah2,
    'Pasangan pilihan admin tersimpan pada guru yang dipilih saja');
$barisKembarBaru = [['baris' => 2, 'nama' => 'PIF1 Kembar', 'nik' => '3200000000000778']];
$pk3 = $impor->periksa('guru', $barisKembarBaru, [2 => ['aksi' => 'tambah']]);
$impor->simpan('guru', $barisKembarBaru, $pilihan($pk3, [2 => ['aksi' => 'tambah']]), $adminId);
$assert(($pk3['hasil'][0]['aksi'] ?? '') === 'tambah' && $jumlah()[0] === $jumlah2[0] + 1, '"Tambah baru" atas pilihan eksplisit admin membuat satu guru');

// Timpa nilai lama dan ganti pengenal.
$barisTimpa = [['baris' => 2, 'nama' => 'PIF1 Guru Tiga', 'nip' => 'PIF1-G-003', 'tempat_lahir' => 'Cirebon']];
$pt = $impor->periksa('guru', $barisTimpa);
$assert(($pt['hasil'][0]['aksi'] ?? '') === 'perlu_konfirmasi' && $pt['hasil'][0]['menimpa'] === ['tempat_lahir'], 'Nilai lama terisi yang berbeda → perlu konfirmasi "timpa"');
$impor->simpan('guru', $barisTimpa, $pilihan($pt), $adminId);
$assert($satu('SELECT tempat_lahir FROM profil_pribadi WHERE guru_id = ?', [$g3]) === 'Bandung', 'Tanpa konfirmasi, nilai lama tidak ditimpa');
$pt2 = $impor->periksa('guru', $barisTimpa, [2 => ['timpa' => true]]);
$impor->simpan('guru', $barisTimpa, $pilihan($pt2, [2 => ['timpa' => true]]), $adminId);
$assert(($pt2['hasil'][0]['aksi'] ?? '') === 'perbarui' && $satu('SELECT tempat_lahir FROM profil_pribadi WHERE guru_id = ?', [$g3]) === 'Cirebon', 'Dengan konfirmasi "timpa", nilai lama diganti');
$barisNik = [['baris' => 2, 'nama' => 'PIF1 Guru Tiga', 'nip' => 'PIF1-G-003', 'nik' => '3200000000000199']];
$pn = $impor->periksa('guru', $barisNik, [2 => ['timpa' => true]]);
$assert(($pn['hasil'][0]['aksi'] ?? '') === 'perlu_konfirmasi', 'NIK lama yang berbeda membutuhkan konfirmasi "ganti NIP/NIK" terpisah');
$pn2 = $impor->periksa('guru', $barisNik, [2 => ['ubah_pengenal' => true]]);
$impor->simpan('guru', $barisNik, $pilihan($pn2, [2 => ['ubah_pengenal' => true]]), $adminId);
$assert($satu('SELECT nik FROM profil_pribadi WHERE guru_id = ?', [$g3]) === '3200000000000199' && (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'profil.impor_baris' AND entity_id = ? AND after_json LIKE '%nik%'", [$g3]) >= 1,
    'NIK dikoreksi atas konfirmasi admin dan tercatat di audit');

// PI-10: identitas pengurus milik guru pada impor Data Pengurus.
$nikG1 = '3200000000000011';
$master->saveGuru(['nama_guru' => 'PIF1 Guru Satu Baru', 'nip' => 'PIF1-G-001', 'no_hp' => '081200000111', 'nik' => $nikG1], $g1);
$pp = $impor->periksa('pengurus', [['baris' => 2, 'nama' => 'PIF1 Guru Satu Baru', 'nik' => $nikG1], ['baris' => 3, 'nama' => 'PIF1 Guru Satu Baru', 'nip' => 'PIF1-P-NEWX']]);
$assert(($pp['hasil'][0]['aksi'] ?? '') === 'konflik' && str_contains(implode(' ', $pp['hasil'][0]['masalah']), 'Data Guru'), 'Pengurus: NIK milik guru yang merangkap pengurus → konflik dengan petunjuk Data Guru (PI-10)');
$kand = $pp['hasil'][1]['kandidat'][0] ?? [];
$assert(($pp['hasil'][1]['aksi'] ?? '') === 'pilih_pasangan' && ($kand['id'] ?? 0) === $pG1 && ($kand['dapat_dipilih'] ?? true) === false, 'Nama sama dengan identitas pengurus milik guru: calon tidak dapat dipilih');
$pp2 = $impor->periksa('pengurus', [['baris' => 2, 'nama' => 'PIF1 Guru Satu Baru', 'nip' => 'PIF1-P-NEWX']], [2 => ['aksi' => 'pasangan', 'pasangan_id' => $pG1]]);
$assert(($pp2['hasil'][0]['aksi'] ?? '') === 'konflik', 'Memaksa memilih identitas pengurus milik guru → konflik');
$pg = $impor->periksa('guru', [['baris' => 2, 'nama' => 'PIF1 Pengurus Dua', 'nip' => 'PIF1-P-002']]);
$assert(($pg['hasil'][0]['aksi'] ?? '') === 'tambah' && str_contains(implode(' ', $pg['hasil'][0]['peringatan']), 'pengurus'), 'Guru: NIP sama dengan pengurus biasa → peringatan data ganda, tanpa penggabungan (keputusan 30 Oktober)');

// Sidik basi: data berubah di antara pratinjau dan simpan.
$barisBasi = [['baris' => 2, 'nama' => 'PIF1 Guru Tiga', 'nip' => 'PIF1-G-003', 'kecamatan' => 'Kec Baru', 'kabupaten' => 'Kab Baru']];
$exec('UPDATE profil_pribadi SET kecamatan = NULL, kabupaten = NULL WHERE guru_id = ?', [$g3]);
$pb = $impor->periksa('guru', $barisBasi);
$master->saveGuru(['nama_guru' => 'PIF1 Guru Tiga', 'nip' => 'PIF1-G-003', 'no_hp' => '081200000555', 'kecamatan' => 'Diubah Admin'], $g3);
$sb = $impor->simpan('guru', $barisBasi, $pilihan($pb), $adminId);
$assert(($pb['hasil'][0]['aksi'] ?? '') === 'perbarui' && $sb['disimpan'] === 0 && $satu('SELECT kecamatan FROM profil_pribadi WHERE guru_id = ?', [$g3]) === 'Diubah Admin' && $satu('SELECT kabupaten FROM profil_pribadi WHERE guru_id = ?', [$g3]) === null,
    'Data berubah sejak pratinjau → simpan menolak baris (konflik), nilai yang lebih baru tidak ditimpa');
$assert($status($galat(static fn () => $impor->simpan('guru', [['baris' => 2, 'nama' => 'PIF1 Tanpa Sidik', 'nip' => 'PIF1-NOSIDIK']], [], $adminId))) === 0
    && (int) $satu("SELECT COUNT(*) FROM guru WHERE nip = 'PIF1-NOSIDIK'") === 0, 'Baris tanpa sidik pratinjau tidak disimpan');

// Audit gagal → tidak ada yang tersimpan.
$barisAudit = [['baris' => 2, 'nama' => 'PIF1 Audit Gagal A', 'nip' => 'PIF1-AUD-A'], ['baris' => 3, 'nama' => 'PIF1 Audit Gagal B', 'nip' => 'PIF1-AUD-B']];
$pa = $impor->periksa('guru', $barisAudit);
foreach (['impor_personel.simpan', 'profil.impor_baris'] as $aksiGagal) {
    $db->query("SET @pif1_gagal = '$aksiGagal'");
    $e = $galat(static fn () => $impor->simpan('guru', $barisAudit, $pilihan($pa), $adminId));
    $assert($status($e) === 503 && (int) $satu("SELECT COUNT(*) FROM guru WHERE nip LIKE 'PIF1-AUD-%'") === 0, "Audit $aksiGagal gagal → seluruh impor dibatalkan");
}
$db->query('SET @pif1_gagal = NULL');
$assert($impor->simpan('guru', $barisAudit, $pilihan($pa), $adminId)['ditambah'] === 2, 'Sesudah audit pulih, impor yang sama tersimpan');
$assert($status($galat(static fn () => $impor->periksa('guru', array_fill(0, ImporPersonelService::BATAS_BARIS + 1, ['nama' => 'X', 'nip' => 'X'])))) === 422, 'Batas jumlah baris ditegakkan server');

echo "\n=== I-9 penugasan tidak berubah ===\n";
$assert($sidikTabel($TABEL_PENUGASAN) === $sidikTugas, 'Seluruh tabel penugasan dan role identik sebelum/sesudah impor berisi Tugas/Kamar/Tugas Tambahan');
$assert((int) $satu("SELECT COUNT(*) FROM users WHERE username LIKE 'pif1%'") === 6, 'Tidak ada akun/username baru dari impor');

echo "\n=== I-10 formulir admin ===\n";
$master->saveGuru(['nama_guru' => 'PIF1 Guru Satu Admin', 'nip' => 'PIF1-G-001', 'no_hp' => '081200000112', 'nik' => $nikG1, 'email' => 'admin.ubah@example.test', 'provinsi' => 'Banten'], $g1);
$assert($satu('SELECT nama FROM pengurus WHERE id = ?', [$pG1]) === 'PIF1 Guru Satu Admin' && $q('SELECT name, phone, email FROM users WHERE id = ?', [$uG1])[0] === ['name' => 'PIF1 Guru Satu Admin', 'phone' => '081200000112', 'email' => 'admin.ubah@example.test']
    && $satu('SELECT provinsi FROM profil_pribadi WHERE guru_id = ?', [$g1]) === 'Banten', 'Data Guru: guru, identitas pengurus milik guru, akun, dan profil konsisten');
$assert($profil->baca($uG1)['pribadi']['nama'] === 'PIF1 Guru Satu Admin' && $profil->baca($uG1)['pribadi']['nik'] === $nikG1, 'Profil aplikasi langsung membaca nilai baru');
$e = $galat(static fn () => $master->saveGuru(['nama_guru' => 'PIF1 Guru Satu Admin', 'nip' => 'PIF1-G-001', 'nik' => '3200000000000199'], $g1));
$assert($e instanceof \App\MasterData\MasterDataException && $satu('SELECT nik FROM profil_pribadi WHERE guru_id = ?', [$g1]) === $nikG1, 'Data Guru: NIK milik guru lain ditolak');
$e = $galat(static fn () => $master->savePengurus(['nama' => 'Ubah', 'jabatan' => 'X'], $pG1));
$assert($e instanceof \App\MasterData\MasterDataException && $satu('SELECT nama FROM pengurus WHERE id = ?', [$pG1]) === 'PIF1 Guru Satu Admin', 'Data Pengurus: identitas milik guru tetap tidak dapat diubah dari sini');
$master->savePengurus(['nama' => 'PIF1 Pengurus Dua Admin', 'nomor_identitas' => 'PIF1-P-002', 'no_hp' => '081200000223', 'jabatan' => 'Bendahara', 'nik' => '3200000000000202'], $p2);
$assert($q('SELECT name, phone FROM users WHERE id = ?', [$uP2])[0] === ['name' => 'PIF1 Pengurus Dua Admin', 'phone' => '081200000223'] && $satu('SELECT nik FROM profil_pribadi WHERE pengurus_id = ?', [$p2]) === '3200000000000202',
    'Data Pengurus: pengurus, akun, dan NIK profil konsisten');
$master->saveWali(['nama' => 'PIF1 Wali Admin', 'no_hp' => '081200000334', 'alamat' => 'Alamat admin'], $w1);
$assert($q('SELECT name, phone FROM users WHERE id = ?', [$uW1])[0] === ['name' => 'PIF1 Wali Admin', 'phone' => '081200000334'], 'Data Wali: nama/HP akun orang tua ikut disamakan');
$db->query("SET @pif1_gagal = 'profil.ubah_admin'");
$sebelumAdmin = $q('SELECT * FROM guru WHERE id = ?', [$g1]);
$e = $galat(static fn () => $master->saveGuru(['nama_guru' => 'PIF1 Gagal', 'nip' => 'PIF1-G-001', 'provinsi' => 'Gagal'], $g1));
$assert($e !== null && $q('SELECT * FROM guru WHERE id = ?', [$g1]) === $sebelumAdmin && $satu('SELECT name FROM users WHERE id = ?', [$uG1]) === 'PIF1 Guru Satu Admin', 'Data Guru: audit profil gagal → guru dan akun tidak berubah');
$db->query('SET @pif1_gagal = NULL');
$db->query('DROP TRIGGER IF EXISTS pif1_audit_gagal');
@unlink($folder . '/' . $namaFoto);

echo "\n" . $lulus . ' lulus, ' . count($gagal) . " gagal\n";
foreach ($gagal as $g) {
    echo ' - ' . $g . "\n";
}
exit($gagal === [] ? 0 : 1);
