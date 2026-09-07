<?php
/** Destructive schema drill, only on an explicitly selected isolated *_test database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('PENUGASAN_RUN_MIGRATION') !== '1') { exit(77); }
require dirname(__DIR__) . '/app/bootstrap.php';
if (!str_ends_with((string) app_config('database.database'), '_test')) { exit(2); }
$db = app_db();
$fail = 0;
$assert = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    if (!$ok) { $fail++; }
};
$rows = static function (string $sql) use ($db): array {
    $result = $db->query($sql);
    if (!$result) { throw new RuntimeException('Migration drill query failed: ' . $db->error); }
    return $result->fetch_all(MYSQLI_ASSOC);
};
$old = static function () use ($rows): array {
    $out = [];
    foreach (['murobi_assignments', 'pembimbing_assignments'] as $table) {
        $out[$table] = array_map(static fn (array $r): array => array_diff_key($r, array_flip(['catatan','updated_by','diakhiri_pada','diakhiri_oleh','alasan_pengakhiran'])), $rows("SELECT * FROM $table ORDER BY id"));
    }
    return $out;
};
$newTables = ['mata_pelajaran','guru_mapel_assignments','pendidikan_assignments','bendahara_bulanan_assignments','panitia_psb_assignments','bendahara_psb_assignments'];
foreach ($newTables as $t) {
    if ($rows("SELECT COUNT(*) n FROM $t")[0]['n'] != 0) { throw new RuntimeException('Drill requires empty foundation tables: ' . $t); }
}
$m = new App\Database\Migrator($db, APP_ROOT . '/database/migrations', APP_ROOT . '/database/rollbacks');
$before = $old();
$assert($m->up() === [], 'Migrasi tercatat dijalankan ulang: tidak ada penerapan baru');
$run = new ReflectionMethod($m, 'runSqlFile');
$run->invoke($m, APP_ROOT . '/database/migrations/012_fondasi_penugasan_v3_v6.sql');
$assert($before === $old(), 'SQL 012 langsung diulang: seluruh nilai/ID penugasan lama tetap');
$assert($m->rollbackLast() === '012_fondasi_penugasan_v3_v6.sql', 'Rollback yang dijalankan tepat 012');
$assert($before === $old(), 'Rollback menjaga nilai/ID/jumlah penugasan lama');
foreach ($newTables as $t) {
    $assert($rows("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'")[0]['n'] == 0, "Rollback melepas $t");
}
$assert($m->up() === ['012_fondasi_penugasan_v3_v6.sql'], 'Migrasi kembali setelah rollback tepat 012');
$assert($before === $old(), 'Migrasi ulang menjaga seluruh nilai penugasan lama');
$assert(count($rows('SELECT id FROM roles')) === 4, 'Role dasar tetap empat setelah drill');
foreach ($newTables as $t) {
    $assert($rows("SELECT COUNT(*) n FROM $t")[0]['n'] == 0, "$t tetap kosong, tanpa backfill tebakan");
}
$refs = $rows("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_NAME IN ('" . implode("','", $newTables) . "')");
foreach ($refs as $r) {
    $sql = "SELECT COUNT(*) n FROM `{$r['TABLE_NAME']}` a LEFT JOIN `{$r['REFERENCED_TABLE_NAME']}` b ON a.`{$r['COLUMN_NAME']}` = b.`{$r['REFERENCED_COLUMN_NAME']}` WHERE a.`{$r['COLUMN_NAME']}` IS NOT NULL AND b.`{$r['REFERENCED_COLUMN_NAME']}` IS NULL";
    $assert($rows($sql)[0]['n'] == 0, "Tidak ada yatim {$r['TABLE_NAME']}.{$r['COLUMN_NAME']}");
}
$guru = (int) $rows('SELECT id FROM guru LIMIT 1')[0]['id'];
$pengurus = (int) $rows('SELECT id FROM pengurus LIMIT 1')[0]['id'];
$kelas = (int) $rows('SELECT id FROM kelas LIMIT 1')[0]['id'];
$tahun = (int) $rows('SELECT id FROM tahun_ajaran LIMIT 1')[0]['id'];
$db->begin_transaction();
try {
    $db->query("INSERT INTO mata_pelajaran (nama) VALUES ('Audit constraint sintetis')");
    $mapel = $db->insert_id;
    foreach (array_slice($newTables, 1) as $t) {
        $columns = $t === 'guru_mapel_assignments' ? 'guru_id,mata_pelajaran_id,kelas_id' : 'pengurus_id';
        $values = $t === 'guru_mapel_assignments' ? "$guru,$mapel,$kelas" : "$pengurus";
        $bad = $t === 'guru_mapel_assignments' ? "2147483647,$mapel,$kelas" : '18446744073709551614';
        $ok = $db->query("INSERT INTO $t ($columns,tahun_ajaran_id,tanggal_mulai,tanggal_selesai) VALUES ($values,$tahun,'2026-09-07','2026-09-06')");
        $assert(!$ok && in_array($db->errno,[3819,4025],true), "CHECK $t benar-benar menolak tanggal terbalik");
        $ok = $db->query("INSERT INTO $t ($columns,tahun_ajaran_id,tanggal_mulai) VALUES ($bad,$tahun,'2026-09-07')");
        $assert(!$ok && $db->errno === 1452, "FK $t benar-benar menolak subjek yatim");
    }
} finally { $db->rollback(); }
echo "Total gagal: $fail\n";
exit($fail ? 1 : 0);
