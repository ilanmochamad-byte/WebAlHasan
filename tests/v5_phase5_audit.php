<?php

declare(strict_types=1);

// Probe auditor: gunakan fixture Fase 5 baru; hanya DB sintetis test_*_test.
if (getenv('V5_RUN_AUDIT') !== '1' || !preg_match('/^test_.*_test$/D', getenv('DB_NAME') ?: '')) {
    exit(2);
}
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Kesehatan\KesehatanRepository;
use App\Kesehatan\LaporanRepository;
use App\Kesehatan\LaporanService;

// Menyisipkan commit melalui koneksi lain tepat di antara kueri angka
// kunjungan dan obat. Seluruh SQL laporan tetap dijalankan MariaDB nyata.
class V5AuditConnection extends mysqli
{
    public ?Closure $interleave = null;

    public function prepare(string $query): mysqli_stmt|false
    {
        if ($this->interleave !== null && str_contains($query, 'SELECT COUNT(*) AS pemberian')) {
            $run = $this->interleave;
            $this->interleave = null;
            $run();
        }
        return parent::prepare($query);
    }
}

$db = app_db();
$cfg = app_config('database');
$reader = new V5AuditConnection($cfg['host'], $cfg['username'], $cfg['password'], $cfg['database'], (int) $cfg['port']);
$reader->set_charset($cfg['charset']);
$lap = new LaporanService(kesehatan_service(), new KesehatanRepository($reader), new LaporanRepository($reader));
$n = static fn (string $sql): int => (int) $db->query($sql)->fetch_row()[0];
$petugas = ['id' => $n("SELECT id FROM users WHERE username = 'sbx_v5_petugas_f'"), 'roles' => []];
$periode = ['dari' => date('Y-m-01'), 'sampai' => date('Y-m-d')];
$lulus = $gagal = 0;
$cek = static function (bool $ok, string $label) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    $ok ? $lulus++ : $gagal++;
};
$awal = $lap->laporan($petugas, $periode);
$reader->interleave = static function () use ($petugas, $n): void {
    kesehatan_service()->buat($petugas, [
        'santri_id' => $n("SELECT id FROM santri WHERE nis = 'V5S0501'"),
        'idempotency_key' => 'audit-f5-snapshot-' . bin2hex(random_bytes(8)),
        'waktu_kunjungan' => date('Y-m-d H:i', strtotime('-10 minutes')),
        'keluhan' => 'V5AUDIT klinis sintetis',
        'obat' => [['nama_obat' => 'V5AUDIT obat sintetis', 'jumlah' => '1']],
    ], 'web');
};
$selama = $lap->laporan($petugas, $periode);
$cek($selama['kunjungan'] === $awal['kunjungan'] && $selama['obat'] === $awal['obat'],
    'Commit kunjungan di tengah laporan: angka kunjungan dan obat memakai snapshot yang sama');
$cek(array_sum(array_column($selama['per_bulan'], 'kunjungan')) === $selama['kunjungan']['total']
    && array_sum(array_column($selama['per_kelas'], 'kunjungan')) === $selama['kunjungan']['total'],
    'Commit di tengah laporan: jumlah per bulan/per kelas tetap sama dengan total');
$akhir = $lap->laporan($petugas, $periode);
$cek($akhir['kunjungan']['total'] === $awal['kunjungan']['total'] + 1 && $akhir['obat']['pemberian'] === $awal['obat']['pemberian'] + 1,
    'Permintaan laporan berikutnya melihat commit baru');

$tmp = sys_get_temp_dir() . '/v5-audit-f5-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
$run = static function (string $script, array $args): array {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/bin/' . $script)
        . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $exit);
    return [$exit, implode("\n", $out)];
};
try {
    file_put_contents($tmp . '/rilis-kosong.json', '{"migrations":[],"rollbacks":[]}');
    [$exit] = $run('v5_preflight.php', ['--rilis=' . $tmp . '/rilis-kosong.json', $tmp . '/x.json']);
    $cek($exit !== 0, 'Preflight menolak manifest rilis tanpa daftar migrasi/rollback');
    $rilis = json_decode((string) file_get_contents(APP_ROOT . '/docs/phase-v5-5/manifest-migrasi.json'), true);
    array_pop($rilis['rollbacks']);
    file_put_contents($tmp . '/rilis-kurang.json', json_encode($rilis));
    [$exit] = $run('v5_preflight.php', ['--rilis=' . $tmp . '/rilis-kurang.json', $tmp . '/x.json']);
    $cek($exit !== 0, 'Preflight menolak manifest dengan satu rollback hilang');
    [$exit] = $run('v5_preflight.php', [$tmp . '/privat.json']);
    $cek($exit === 0, 'Preflight menghasilkan manifest privat lengkap');
    $privat = json_decode((string) file_get_contents($tmp . '/privat.json'), true);
    unset($privat['sidik_jari']);
    file_put_contents($tmp . '/tanpa-sidik.json', json_encode($privat));
    [$exit] = $run('v5_verify.php', [$tmp . '/tanpa-sidik.json']);
    $cek($exit !== 0, 'Verifier menolak manifest privat yang kehilangan seluruh sidik jari');
    $privat = json_decode((string) file_get_contents($tmp . '/privat.json'), true);
    unset($privat['sidik_jari']['santri_wali']);
    file_put_contents($tmp . '/kurang-sidik.json', json_encode($privat));
    [$exit] = $run('v5_verify.php', [$tmp . '/kurang-sidik.json']);
    $cek($exit !== 0, 'Verifier menolak manifest privat yang kehilangan sidik jari relasi santri–wali');
    [$exit] = $run('v5_verify.php', [$tmp . '/privat.json']);
    $cek($exit === 0, 'Verifier menerima manifest privat lengkap pada data utuh');
    $privat['legacy_counts'] = [];
    file_put_contents($tmp . '/tanpa-tabel.json', json_encode($privat));
    [$exit] = $run('v5_verify.php', [$tmp . '/tanpa-tabel.json']);
    $cek($exit !== 0, 'Verifier menolak manifest yang kehilangan daftar tabel lama');
    // Isolation level bawaan READ COMMITTED tidak boleh memecah satu laporan.
    $reader->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $awal = $lap->laporan($petugas, $periode);
    $reader->interleave = static function () use ($petugas, $n): void {
        kesehatan_service()->buat($petugas, [
            'santri_id' => $n("SELECT id FROM santri WHERE nis = 'V5S0501'"),
            'idempotency_key' => 'audit-f5-rc-' . bin2hex(random_bytes(8)),
            'waktu_kunjungan' => date('Y-m-d H:i', strtotime('-10 minutes')),
            'keluhan' => 'V5AUDIT klinis sintetis',
            'obat' => [['nama_obat' => 'V5AUDIT obat sintetis', 'jumlah' => '1']],
        ], 'web');
    };
    $selama = $lap->laporan($petugas, $periode);
    $cek($selama['kunjungan'] === $awal['kunjungan'] && $selama['obat'] === $awal['obat'],
        'Snapshot konsisten juga ketika isolation level bawaan READ COMMITTED');
    $cek((int) $reader->query('SELECT @@in_transaction')->fetch_row()[0] === 0,
        'Transaksi baca laporan ditutup setelah sukses');
    try {
        $lap->laporan($petugas, ['dari' => '2026-02-30']);
    } catch (\App\Kesehatan\KesehatanException $e) {
        $cek($e->status === 422 && (int) $reader->query('SELECT @@in_transaction')->fetch_row()[0] === 0,
            'Transaksi baca laporan ditutup juga ketika validasi gagal');
    }
} finally {
    foreach (glob($tmp . '/*') ?: [] as $f) unlink($f);
    rmdir($tmp);
}
echo "Ringkasan: $lulus lulus, $gagal gagal\n";
exit($gagal ? 1 : 0);
