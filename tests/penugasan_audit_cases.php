<?php
/** Included only by the isolated concurrency fixture; no standalone mutation. */
if (!isset($jalankanBersamaan, $adminId)) { exit(77); }
$okCount = static fn (array $rows): int => count(array_filter($rows, static fn (array $r): bool => $r['berhasil'] === true));
$rejectAudit = static function (callable $work, string $label) use ($assert): void {
    try { $work(); $assert(false, $label); }
    catch (App\Penugasan\PenugasanException | App\Izin\IzinException | App\MasterData\MasterDataException $e) {
        $assert(!preg_match('/SQLSTATE|Deadlock|Lock wait timeout|mysqli/', $e->getMessage()), $label . ': pesan aman');
    }
};
// Cross-entry races prove that the old paths cannot bypass the central lock.
foreach (['murobi', 'pembimbing'] as $jenisLama) {
    $subjectColumn = $jenisLama === 'murobi' ? 'guru_id' : 'pengurus_id';
    $subject = $jenisLama === 'murobi' ? $guru : $pengurus;
    $base = [$subjectColumn => $subject, 'tahun_ajaran_id' => $tahunAktif, 'target_type' => 'Kelas', 'kelas_id' => $kelasA, 'tanggal_mulai' => $hari(0)];
    $results = $jalankanBersamaan([
        ['aksi' => 'lama_buat', 'jenis' => $jenisLama, 'isian' => $base],
        ['aksi' => 'buat', 'jenis' => $jenisLama, 'isian' => array_replace($base, ['tanggal_mulai' => $hari(1)])],
    ], $adminId);
    $assert($okCount($results) === 1, "KA-1 $jenisLama lama vs pusat overlap: tepat satu berhasil");
    $table = $jenisLama . '_assignments';
    $assert($angka("SELECT COUNT(*) n FROM $table WHERE $subjectColumn = $subject") === 1, "KA-1 $jenisLama tanpa data parsial/ganda");
    $id = (int) $satu("SELECT id FROM $table WHERE $subjectColumn = $subject")['id'];
    $legacyCreate = static fn (array $input): int => $jenisLama === 'murobi' ? master_data_service()->saveMurobi($input, $adminId) : pembimbing_service()->create($input, $adminId);
    $legacyState = static function (int $id, string $action, string $alasan = 'Uji status melalui halaman lama') use ($jenisLama, $adminId): void {
        if ($jenisLama === 'murobi') { master_data_service()->setMurobiState($id, $action, $adminId, $alasan); }
        else { pembimbing_service()->setState($id, $action, $adminId, $alasan); }
    };
    $rejectAudit(fn () => $legacyCreate(array_replace($base, ['tanggal_selesai' => $hari(-1)])), "KA-2 $jenisLama tanggal terbalik ditolak");
    $rejectAudit(fn () => $legacyState($id, 'deactivate', ''), "KA-2 $jenisLama alasan status wajib");
    $rejectAudit(fn () => $legacyState($id, 'archive', ''), "KA-2 $jenisLama alasan arsip wajib");
    $assert((int) $satu("SELECT is_active FROM $table WHERE id = $id")['is_active'] === 1, "KA-2 $jenisLama tanpa alasan tidak mengubah status");
    $legacyState($id, 'archive');
    $replacement = $service->buat($jenisLama, array_replace($base, ['tanggal_mulai' => $hari(2)]), $adminId);
    $rejectAudit(fn () => $legacyState($id, 'restore'), "KA-3 $jenisLama pulihkan arsip bentrok ditolak");
    $assert(!empty($satu("SELECT archived_at FROM $table WHERE id = $id")['archived_at']), "KA-3 $jenisLama arsip tetap utuh");
    $service->nonaktifkan($jenisLama, $replacement, 'Persiapan uji dua pengaktifan', $adminId);
    $legacyState($id, 'restore');
    $legacyState($id, 'deactivate');
    $results = $jalankanBersamaan([
        ['aksi' => 'lama_status', 'jenis' => $jenisLama, 'isian' => ['id' => $id, 'action' => 'activate']],
        ['aksi' => 'aktifkan', 'jenis' => $jenisLama, 'isian' => ['id' => $replacement]],
    ], $adminId);
    $assert($okCount($results) === 1 && $angka("SELECT COUNT(*) n FROM $table WHERE $subjectColumn = $subject AND is_active = 1 AND archived_at IS NULL") === 1, "KA-4 $jenisLama pengaktifan lama vs pusat tepat satu");
}
// Concurrent changes to scope: only one row can move into the destination.
$base = ['pengurus_id' => $pengurus, 'tahun_ajaran_id' => $tahunAktif, 'tanggal_mulai' => $hari(0)];
$x = $service->buat('panitia_psb', $base + ['gelombang' => 'audit-a'], $adminId);
$y = $service->buat('panitia_psb', $base + ['gelombang' => 'audit-b'], $adminId);
$results = $jalankanBersamaan([
    ['aksi' => 'ubah', 'jenis' => 'panitia_psb', 'isian' => $base + ['id' => $x, 'gelombang' => 'audit-c']],
    ['aksi' => 'ubah', 'jenis' => 'panitia_psb', 'isian' => $base + ['id' => $y, 'gelombang' => 'audit-c']],
], $adminId);
$assert($okCount($results) === 1 && $angka("SELECT COUNT(*) n FROM panitia_psb_assignments WHERE pengurus_id = $pengurus AND gelombang = 'audit-c'") === 1, 'KA-5 perubahan cakupan bersamaan tepat satu');
$base['gelombang'] = 'audit-dates';
$x = $service->buat('panitia_psb', array_replace($base, ['tanggal_mulai' => $hari(10), 'tanggal_selesai' => $hari(12)]), $adminId);
$y = $service->buat('panitia_psb', array_replace($base, ['tanggal_mulai' => $hari(20), 'tanggal_selesai' => $hari(22)]), $adminId);
$results = $jalankanBersamaan([
    ['aksi' => 'ubah', 'jenis' => 'panitia_psb', 'isian' => array_replace($base, ['id' => $x, 'tanggal_mulai' => $hari(10), 'tanggal_selesai' => $hari(17)])],
    ['aksi' => 'ubah', 'jenis' => 'panitia_psb', 'isian' => array_replace($base, ['id' => $y, 'tanggal_mulai' => $hari(15), 'tanggal_selesai' => $hari(22)])],
], $adminId);
$assert($okCount($results) === 1, 'KA-6 perubahan tanggal bersamaan tepat satu');
$before = $satu("SELECT * FROM panitia_psb_assignments WHERE id = $x");
$rejectAudit(fn () => $service->akhiri('panitia_psb', $x, $hari(25), 'Perpanjangan yang bertabrakan', $adminId), 'KA-7 akhiri tidak boleh memperpanjang ke periode lain');
$assert($before === $satu("SELECT * FROM panitia_psb_assignments WHERE id = $x"), 'KA-7 penolakan akhiri tidak mengubah baris');
// Failure AFTER an audit insert is attempted: both business and audit roll back.
$exec("CREATE TRIGGER codex_penugasan_audit_fail BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.actor_user_id = $adminId AND NEW.action = 'penugasan.buat' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit test failure'; END IF; END");
try {
    $beforeCount = $angka("SELECT COUNT(*) n FROM panitia_psb_assignments WHERE pengurus_id = $pengurus");
    $results = $jalankanBersamaan([
        ['aksi' => 'buat', 'jenis' => 'panitia_psb', 'isian' => array_replace($base, ['gelombang' => 'audit-fail-a'])],
        ['aksi' => 'buat', 'jenis' => 'panitia_psb', 'isian' => array_replace($base, ['gelombang' => 'audit-fail-b'])],
    ], $adminId);
    $assert($okCount($results) === 0 && $beforeCount === $angka("SELECT COUNT(*) n FROM panitia_psb_assignments WHERE pengurus_id = $pengurus"), 'KA-8 audit gagal pada dua proses: nol perubahan parsial');
} finally { $exec('DROP TRIGGER codex_penugasan_audit_fail'); }
// Hold the master lock from this connection, force actual 1205 in each reader.
$db->begin_transaction();
$db->query("SELECT id FROM guru WHERE id = $guru FOR UPDATE");
try {
    $results = $jalankanBersamaan(array_map(static fn (string $j): array => ['aksi' => 'lock_read', 'jenis' => $j, 'isian' => ['guru_id' => $guru]], ['murobi', 'pembimbing', 'pusat', 'akun', 'penempatan', 'alumni']), $adminId);
    $assert($okCount($results) === 0, 'KA-9 get_result lock timeout keenam repository tidak dianggap data kosong');
    $assert(count(array_filter($results, static fn (array $r): bool => str_contains($r['pesan'] ?? '', 'Muat ulang'))) === 6, 'KA-9 seluruh timeout menghasilkan konflik aman');
} finally { $db->rollback(); }
// The documented matrix explicitly denies jenjang access from a room scope.
$resolver = new App\Auth\Capabilities($db);
$method = new ReflectionMethod($resolver, 'scopeMatches');
$scope = ['tahun_ajaran_id' => $tahunAktif, 'kelas_id' => null, 'kamar_id' => 1, 'mata_pelajaran_id' => null, 'jenjang' => null, 'gelombang' => null];
$assert($method->invoke($resolver, $scope, ['jenjang' => 'Tsanawi']) === false, 'KA-10 cakupan kamar tidak memberi akses seluruh jenjang');

// Real 1213: each connection owns one row and requests the other after a barrier.
$guruDeadlock = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['KPD' . $suffix, 'Guru deadlock ' . $suffix]);
$dibuat['guru'][] = $guruDeadlock;
foreach (['murobi', 'pembimbing', 'pusat', 'akun', 'penempatan', 'alumni'] as $j) {
    $sync = sys_get_temp_dir() . '/penugasan-deadlock-' . bin2hex(random_bytes(6));
    mkdir($sync, 0700);
    try {
        $results = $jalankanBersamaan([
            ['aksi' => 'deadlock_read', 'jenis' => $j, 'isian' => ['lock_id' => $guru, 'guru_id' => $guruDeadlock, 'sync' => $sync]],
            ['aksi' => 'deadlock_read', 'jenis' => $j, 'isian' => ['lock_id' => $guruDeadlock, 'guru_id' => $guru, 'sync' => $sync]],
        ], $adminId);
        $assert($okCount($results) === 1, "KA-11 $j deadlock nyata: satu pembacaan berhasil, satu konflik");
        $assert(count(array_filter($results, static fn (array $r): bool => str_contains($r['pesan'] ?? '', 'Muat ulang'))) === 1, "KA-11 $j deadlock tidak menjadi data kosong/pesan MySQL");
    } finally {
        foreach (glob($sync . '/*') as $file) { unlink($file); }
        rmdir($sync);
    }
}

$inactive = $service->buat('panitia_psb', ['pengurus_id' => $pengurus, 'tahun_ajaran_id' => $tahunAktif, 'gelombang' => 'inactive-master', 'tanggal_mulai' => $hari(0)], $adminId);
$service->nonaktifkan('panitia_psb', $inactive, 'Persiapan master nonaktif', $adminId);
$exec('UPDATE pengurus SET is_active = 0 WHERE id = ?', [$pengurus]);
try {
    $rejectAudit(fn () => $service->aktifkan('panitia_psb', $inactive, 'Aktifkan dengan master nonaktif', $adminId), 'KA-12 aktifkan menilai ulang master aktif');
    $assert((int) $satu("SELECT is_active FROM panitia_psb_assignments WHERE id = $inactive")['is_active'] === 0, 'KA-12 aktivasi ditolak tanpa perubahan parsial');
} finally { $exec('UPDATE pengurus SET is_active = 1 WHERE id = ?', [$pengurus]); }
