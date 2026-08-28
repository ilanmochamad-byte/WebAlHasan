<?php

declare(strict_types=1);

/**
 * Pengukuran performa laporan perizinan V2 Fase 5.
 *
 * Menjalankan halaman PERTAMA laporan (ringkasan + median durasi + detail)
 * untuk beberapa kombinasi filter, lalu melaporkan waktu tempuh dan rencana
 * eksekusi (`EXPLAIN`) setiap query.
 *
 * Skrip ini adalah alat BUKTI, bukan alat klaim: keluarannya disalin apa adanya
 * ke `docs/phase-v2-5/bukti-performa.md` sebelum dan sesudah indeks ditambahkan,
 * sehingga penambahan indeks dapat dinilai dari angka, bukan dari dugaan.
 *
 * PENJAGA: hanya CLI dan hanya database berakhiran `_test`. Pengukuran tidak
 * pernah dijalankan pada produksi (PRD Fase 5: "jangan menjalankan fixture atau
 * pengujian beban pada database produksi").
 *
 * Pemakaian:
 *   V2_PHASE5_UKUR=1 php bin/v2_phase5_ukur_laporan.php [--ulang=5] [--explain]
 */

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';

use App\Report\IzinReportFilter;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (getenv('V2_PHASE5_UKUR') !== '1') {
    fwrite(STDERR, "Tolak: setel V2_PHASE5_UKUR=1 untuk menjalankan pengukuran performa.\n");
    exit(2);
}
$database = (string) app_config('database.database');
if (!str_ends_with($database, '_test')) {
    fwrite(STDERR, "Tolak: DB_NAME (`{$database}`) wajib berakhiran _test.\n");
    exit(2);
}

$argumen = static function (string $nama, ?string $default = null) use ($argv): ?string {
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--' . $nama) {
            return '1';
        }
        if (str_starts_with($arg, '--' . $nama . '=')) {
            return substr($arg, strlen($nama) + 3);
        }
    }

    return $default;
};

$ulang = max(1, (int) ($argumen('ulang', '5') ?? '5'));
$tampilkanExplain = $argumen('explain') !== null;

$db = app_db();
$repository = izin_report_repository();

$totalPengajuan = (int) $db->query('SELECT COUNT(*) FROM izin_pengajuan')->fetch_row()[0];
$totalKeputusan = (int) $db->query('SELECT COUNT(*) FROM izin_keputusan')->fetch_row()[0];

echo '=== Pengukuran laporan perizinan V2 Fase 5 ===' . PHP_EOL;
echo 'Database        : ' . $database . PHP_EOL;
echo 'izin_pengajuan  : ' . $totalPengajuan . ' baris' . PHP_EOL;
echo 'izin_keputusan  : ' . $totalKeputusan . ' baris' . PHP_EOL;
echo 'PHP             : ' . PHP_VERSION . PHP_EOL;
echo 'Server basis data: ' . $db->server_info . PHP_EOL;
echo 'Pengulangan     : ' . $ulang . ' per skenario (dilaporkan: terbaik, median, terburuk)' . PHP_EOL;
echo 'Target PRD      : halaman pertama <= 2000 ms' . PHP_EOL . PHP_EOL;

/**
 * Cakupan disuntikkan langsung agar pengukuran tidak bergantung pada akun uji
 * tertentu. Nilainya sama persis dengan yang dihasilkan `IzinService::scopeFor()`.
 */
$scope = static fn (string $mode, array $extra = []): array => array_merge([
    'mode' => $mode,
    'pengurus_id' => null,
    'guru_id' => null,
    'wali_id' => null,
    'label' => 'Pengukuran ' . $mode,
], $extra);

$pengurusId = (int) ($db->query("SELECT id FROM pengurus WHERE nomor_identitas LIKE 'P5-%' ORDER BY id LIMIT 1")->fetch_row()[0] ?? 0);
$guruId = (int) ($db->query("SELECT id FROM guru WHERE nip LIKE 'P5-%' ORDER BY id LIMIT 1")->fetch_row()[0] ?? 0);
$kamarId = (int) ($db->query("SELECT id FROM kamar WHERE nama_kamar LIKE 'P5 %' ORDER BY id LIMIT 1")->fetch_row()[0] ?? 0);

$rentangPenuh = ['date_from' => '2024-01-01', 'date_to' => '2028-12-31'];

$skenario = [
    'admin — rentang penuh' => [$scope('admin'), $rentangPenuh],
    'admin — status Disetujui' => [$scope('admin'), $rentangPenuh + ['status' => 'Disetujui']],
    'admin — basis keputusan' => [$scope('admin'), $rentangPenuh + ['basis_tanggal' => 'keputusan']],
    'admin — durasi >= 24 jam' => [$scope('admin'), $rentangPenuh + ['durasi_min_jam' => '24']],
    'admin — filter kamar' => [$scope('admin'), $rentangPenuh + ['kamar_id' => (string) $kamarId]],
    'admin — pencarian teks' => [$scope('admin'), $rentangPenuh + ['q' => 'fixture']],
    'pengurus — cakupan sendiri' => [$scope('pengurus', ['pengurus_id' => $pengurusId]), $rentangPenuh],
    'murobi — cakupan sendiri' => [$scope('murobi', ['guru_id' => $guruId]), $rentangPenuh],
];

$hasil = [];
$gagalTarget = 0;

foreach ($skenario as $nama => [$cakupan, $input]) {
    $filter = IzinReportFilter::fromInput($input, (string) app_config('timezone'))->forScope($cakupan);

    $waktu = [];
    for ($i = 0; $i < $ulang; $i++) {
        $mulai = microtime(true);
        // Persis pekerjaan halaman pertama laporan: ringkasan + durasi + detail.
        $repository->summary($filter);
        $repository->decisionDuration($filter);
        $repository->page($filter);
        $waktu[] = (microtime(true) - $mulai) * 1000;
    }
    sort($waktu);
    $median = $waktu[intdiv(count($waktu), 2)];
    $terbaik = $waktu[0];
    $terburuk = $waktu[count($waktu) - 1];

    $lulus = $terburuk <= 2000.0;
    if (!$lulus) {
        $gagalTarget++;
    }

    $hasil[$nama] = ['terbaik' => $terbaik, 'median' => $median, 'terburuk' => $terburuk, 'lulus' => $lulus];

    printf(
        "%-30s terbaik %7.1f ms | median %7.1f ms | terburuk %7.1f ms | %s%s",
        $nama,
        $terbaik,
        $median,
        $terburuk,
        $lulus ? 'LULUS' : 'MELEBIHI TARGET',
        PHP_EOL
    );

    if ($tampilkanExplain) {
        foreach ($repository->explain($filter) as $bagian => $baris) {
            foreach ($baris as $r) {
                printf(
                    "    EXPLAIN %-10s table=%-4s type=%-8s key=%-34s rows=%-7s Extra=%s%s",
                    $bagian,
                    (string) ($r['table'] ?? '-'),
                    (string) ($r['type'] ?? '-'),
                    (string) ($r['key'] ?? 'NULL'),
                    (string) ($r['rows'] ?? '-'),
                    (string) ($r['Extra'] ?? ''),
                    PHP_EOL
                );
            }
        }
        echo PHP_EOL;
    }
}

echo PHP_EOL;
$terburukGlobal = max(array_column($hasil, 'terburuk'));
printf('Waktu terburuk seluruh skenario: %.1f ms%s', $terburukGlobal, PHP_EOL);

if ($totalPengajuan < 1000) {
    echo 'PERINGATAN: fixture kurang dari 1.000 pengajuan. Jalankan bin/v2_phase5_fixture.php lebih dulu.' . PHP_EOL;
    exit(3);
}
if ($gagalTarget > 0) {
    echo "TIDAK MEMENUHI TARGET: {$gagalTarget} skenario melebihi 2000 ms." . PHP_EOL;
    exit(1);
}
echo 'SELURUH SKENARIO MEMENUHI TARGET 2 DETIK pada fixture >= 1.000 pengajuan.' . PHP_EOL;
exit(0);
