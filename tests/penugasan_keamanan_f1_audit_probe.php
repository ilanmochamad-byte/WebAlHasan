<?php

declare(strict_types=1);

// Probe auditor: audit wajib, rollback atomik, dan pencabutan identitas.
// Siapkan fixture bersih: PKF1_DB=test_pk_f1_audit_test bash tests/penugasan_keamanan_f1_db.sh
$dbName = getenv('PKF1_DB') ?: 'test_pk_f1_audit_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    throw new RuntimeException('Hanya database test_*_test.');
}
putenv('DB_NAME=' . $dbName);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Penugasan\PenugasanJenis;

$db = app_db();
$one = static fn (string $sql): mixed => $db->query($sql)->fetch_row()[0] ?? null;
$row = static fn (string $sql): array => $db->query($sql)->fetch_assoc() ?? [];
$admin = (int) $one("SELECT id FROM users WHERE username='sbx_admin'");
$_SESSION['user_id'] = $admin;
$user = (int) $one("SELECT id FROM users WHERE username='sbx_guru_biasa'");
$guru = (int) $one("SELECT guru_id FROM users WHERE id=$user");
$tahun = (int) $one("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1");
$input = static fn (int $gid): array => ['pengurus_id' => 'guru:' . $gid, 'tahun_ajaran_id' => $tahun, 'tanggal_mulai' => date('Y-m-d')];
$ok = 0;
$bad = 0;
$assert = static function (bool $pass, string $message) use (&$ok, &$bad): void {
    $pass ? $ok++ : $bad++;
    echo ($pass ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
};
$error = static function (callable $work): ?Throwable {
    try { $work(); return null; } catch (Throwable $e) { return $e; }
};
$snapshot = static fn (): array => [
    $row("SELECT * FROM guru WHERE id=$guru"),
    $row("SELECT * FROM pengurus WHERE guru_id=$guru"),
    $row("SELECT * FROM users WHERE id=$user"),
    $one('SELECT COUNT(*) FROM audit_logs'),
];
$caps = static function () use ($user): array {
    capabilities()->forget($user);
    return capabilities()->featureList(['id' => $user, 'roles' => ['guru', 'pengurus']]);
};

$db->query('DROP TRIGGER IF EXISTS pkf1_auditor_fail');
$db->query("CREATE TRIGGER pkf1_auditor_fail BEFORE INSERT ON audit_logs FOR EACH ROW
    BEGIN IF NEW.action = @pkf1_fail_action THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='auditor injected audit failure'; END IF; END");
try {
    // Semua sisi pembuatan harus ikut rollback bila salah satu audit gagal.
    foreach (['penugasan.identitas_guru.buat', 'account_identitas_guru_linked', 'penugasan.buat'] as $action) {
        $db->query("SET @pkf1_fail_action='$action'");
        $before = $snapshot();
        $e = $error(static fn () => penugasan_service()->buat(PenugasanJenis::KESEHATAN, $input($guru), $admin));
        $assert($e !== null, "$action gagal: layanan menolak");
        $assert($before === $snapshot()
            && (int) $one("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=$user AND r.slug='pengurus'") === 0,
            "$action gagal: guru, identitas, akun, role dan audit kembali utuh");
    }
    $db->query('SET @pkf1_fail_action=NULL');
    penugasan_service()->buat(PenugasanJenis::KESEHATAN, $input($guru), $admin);
    $pid = (int) $one("SELECT id FROM pengurus WHERE guru_id=$guru");
    $assert($pid > 0 && in_array(PenugasanJenis::CAP_KESEHATAN_CATAT, $caps(), true), 'kontrol: hak kesehatan efektif');

    $original = master_data_service()->guru($guru);
    $db->query("SET @pkf1_fail_action='master.identitas_guru.sinkron'");
    foreach (['nama' => static fn () => master_data_service()->saveGuru([
        'nip' => $original['nip'], 'nama_guru' => 'Auditor Nama Sinkron', 'no_hp' => '081234560009',
    ], $guru), 'nonaktif' => static fn () => master_data_service()->setGuruState($guru, 'deactivate'),
        'arsip' => static fn () => master_data_service()->setGuruState($guru, 'archive')] as $label => $work) {
        $before = $snapshot();
        $e = $error($work);
        $assert($e !== null, "sinkron $label tanpa audit ditolak");
        $assert($snapshot() === $before, "sinkron $label tanpa audit: guru, identitas dan audit rollback utuh");
        // Pulihkan kontrol agar setiap kasus independen, termasuk pada kode sebelum koreksi.
        $db->query('SET @pkf1_fail_action=NULL');
        master_data_service()->setGuruState($guru, 'restore');
        master_data_service()->setGuruState($guru, 'activate');
        master_data_service()->saveGuru(['nip' => $original['nip'], 'nama_guru' => $original['nama_guru'], 'no_hp' => $original['no_hp'] ?? ''], $guru);
        $db->query("SET @pkf1_fail_action='master.identitas_guru.sinkron'");
    }
    $db->query('SET @pkf1_fail_action=NULL');
    $beforeAudit = (int) $one("SELECT COUNT(*) FROM audit_logs WHERE action='master.identitas_guru.sinkron'");
    master_data_service()->setGuruState($guru, 'deactivate');
    $assert((int) $one("SELECT is_active FROM pengurus WHERE id=$pid") === 0 && $caps() === [], 'kontrol sukses: nonaktif menutup hak dan menyinkronkan identitas');
    $assert((int) $one("SELECT COUNT(*) FROM audit_logs WHERE action='master.identitas_guru.sinkron'") === $beforeAudit + 1, 'kontrol sukses: sinkron tercatat tepat sekali');
    master_data_service()->setGuruState($guru, 'activate');
    // Syarat kepemilikan tetap menutup hak walau status pengurus tertinggal aktif.
    $db->query("UPDATE guru SET archived_at=NOW() WHERE id=$guru");
    $assert($caps() === [] && capabilities()->linkedPengurusId($user) === null, 'arsip guru tanpa sinkron tetap mencabut hak pada resolver baru');
    $db->query("UPDATE guru SET archived_at=NULL WHERE id=$guru");
    $db->query("UPDATE users SET guru_id=NULL WHERE id=$user");
    $assert($caps() === [] && capabilities()->linkedPengurusId($user) === null, 'akun tanpa guru tidak memperoleh hak identitas milik guru');
    $db->query("UPDATE users SET guru_id=$guru WHERE id=$user");
    $assert(in_array(PenugasanJenis::CAP_KESEHATAN_CATAT, $caps(), true), 'kontrol: hak pulih setelah relasi asli dipulihkan');
    $namaBesar = mb_strtoupper((string) $original['nama_guru']);
    master_data_service()->saveGuru(['nip' => $original['nip'], 'nama_guru' => $namaBesar, 'no_hp' => $original['no_hp'] ?? ''], $guru);
    $assert($one("SELECT nama FROM pengurus WHERE id=$pid") === $namaBesar, 'perubahan kapitalisasi nama guru tersinkron persis ke pengurus');
    master_data_service()->saveGuru(['nip' => $original['nip'], 'nama_guru' => $original['nama_guru'], 'no_hp' => $original['no_hp'] ?? ''], $guru);
    $db->query("UPDATE pengurus SET nama=LOWER(nama) WHERE id=$pid");
    penugasan_service()->buat(PenugasanJenis::BENDAHARA_BULANAN, $input($guru), $admin);
    $assert($one("SELECT nama FROM pengurus WHERE id=$pid") === $original['nama_guru'], 'pemakaian ulang identitas menyamakan kapitalisasi dari guru');
} finally {
    $db->query('SET @pkf1_fail_action=NULL');
    $db->query('DROP TRIGGER IF EXISTS pkf1_auditor_fail');
}
echo "\nHasil: $ok lulus, $bad gagal.\n";
exit($bad === 0 ? 0 : 1);
