<?php

declare(strict_types=1);

/**
 * Verifier rilis PRD V5 (Fase 5). Hanya membaca; aman dijalankan pada
 * produksi sesudah migrasi, pada salinan pulihan, maupun berkala.
 *
 *   php bin/v5_verify.php [manifest-privat.json]
 *
 * 1. Menjalankan verifier per fase (`v5_phase1/2/3_verify.php`, dengan
 *    manifest yang sama bila diberikan): tabel, kunci asing, referensi yatim,
 *    versi tanpa celah, alasan koreksi/penggantian/penarikan, notifikasi
 *    generik, audit tanpa isi klinis, dan jumlah baris tabel lama.
 * 2. Pemeriksaan lintas fase Fase 5:
 *    - setiap kunjungan punya tepat satu audit `dicatat` dan setiap versi > 1
 *      tepat satu audit `dikoreksi` dengan pelaku = pembuat versi (0 pembaruan
 *      tanpa riwayat);
 *    - setiap baris rincian lahir/dibatalkan pada versi yang ada, dan versi
 *      pembatalan sesudah versi lahir;
 *    - satu kunjungan per (petugas, kunci idempotensi) dan per klaim;
 *      satu peristiwa ringkasan per klaim; 0 notifikasi V5 efektif ganda;
 *    - ringkasan hanya bersumber versi kunjungan Tercatat;
 *    - laporan (`LaporanRepository`, tanpa periode, hak seluruh) = kueri
 *      sumber yang ditulis terpisah.
 * 3. Dengan manifest `bin/v5_preflight.php`: sidik jari ID/status santri,
 *    wali, dan relasi santri–wali tidak berubah.
 *
 * Kode keluar 0 bila seluruh pemeriksaan lulus.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/_v5_rilis.php';

use App\Database\Schema;
use App\Kesehatan\LaporanRepository;

$db = app_db();
$manifestPath = $argv[1] ?? null;
$lulus = 0;
$gagal = 0;
$periksa = static function (bool $ok, string $label) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    $ok ? $lulus++ : $gagal++;
};
$n = static fn (string $sql): int => (int) $db->query($sql)->fetch_row()[0];

echo '=== 1. Verifier per fase ===' . PHP_EOL;
foreach (['v5_phase1_verify.php', 'v5_phase2_verify.php', 'v5_phase3_verify.php'] as $skrip) {
    $keluaran = [];
    $kode = 1;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $skrip) . ($manifestPath !== null ? ' ' . escapeshellarg($manifestPath) : '') . ' 2>&1', $keluaran, $kode);
    $jumlahLulus = count(array_filter($keluaran, static fn (string $l): bool => str_starts_with($l, '[lulus]')));
    foreach (array_filter($keluaran, static fn (string $l): bool => str_starts_with($l, '[gagal]')) as $l) {
        echo '    ' . $skrip . ' ' . $l . PHP_EOL;
    }
    $periksa($kode === 0, "$skrip: exit $kode, $jumlahLulus pemeriksaan lulus");
}

echo PHP_EOL . '=== 2. Lintas fase ===' . PHP_EOL;
$aksi = static fn (string $a): string => "SELECT entity_id, COUNT(*) c FROM audit_logs WHERE action = '$a' AND entity_type = 'kesehatan_kunjungan' GROUP BY entity_id";
$periksa($n('SELECT COUNT(*) FROM kesehatan_kunjungan k LEFT JOIN (' . $aksi('kesehatan.kunjungan.dicatat') . ') a ON a.entity_id = k.id WHERE COALESCE(a.c, 0) <> 1') === 0,
    'Setiap kunjungan punya tepat satu audit pencatatan');
$periksa($n('SELECT COUNT(*) FROM kesehatan_kunjungan k LEFT JOIN (' . $aksi('kesehatan.kunjungan.dikoreksi') . ') a ON a.entity_id = k.id WHERE COALESCE(a.c, 0) <> k.versi - 1') === 0,
    'Jumlah audit koreksi = versi − 1 untuk setiap kunjungan (0 pembaruan tanpa riwayat)');
$periksa($n("SELECT COUNT(*) FROM kesehatan_kunjungan_versi v WHERE v.versi > 1 AND NOT EXISTS (
        SELECT 1 FROM audit_logs a WHERE a.action = 'kesehatan.kunjungan.dikoreksi' AND a.entity_type = 'kesehatan_kunjungan' AND a.entity_id = v.kunjungan_id
           AND a.actor_user_id = v.dibuat_oleh AND CAST(JSON_UNQUOTE(JSON_EXTRACT(a.after_json, '$.versi')) AS UNSIGNED) = v.versi
           AND CAST(JSON_UNQUOTE(JSON_EXTRACT(a.before_json, '$.versi')) AS UNSIGNED) = v.versi - 1)") === 0,
    'Setiap versi koreksi punya audit dengan pelaku sama dan versi sebelum → sesudah');
$periksa($n('SELECT COUNT(*) FROM kesehatan_kunjungan_versi WHERE dibuat_oleh IS NULL OR dibuat_pada IS NULL') === 0, 'Setiap versi mencatat pelaku dan waktu');
foreach (['kesehatan_obat', 'kesehatan_rujukan', 'kesehatan_tindak_lanjut'] as $t) {
    $periksa($n("SELECT COUNT(*) FROM `$t` x JOIN kesehatan_kunjungan k ON k.id = x.kunjungan_id
        WHERE x.versi_dibuat < 1 OR x.versi_dibuat > k.versi OR (x.versi_dibatalkan IS NOT NULL AND (x.versi_dibatalkan <= x.versi_dibuat OR x.versi_dibatalkan > k.versi))") === 0,
        "$t: versi lahir/batal berada dalam rentang versi kunjungan; batal sesudah lahir");
}
$periksa($n('SELECT COUNT(*) FROM (SELECT petugas_user_id, idempotency_key FROM kesehatan_kunjungan GROUP BY petugas_user_id, idempotency_key HAVING COUNT(*) > 1) d') === 0,
    'Satu kunjungan per (petugas, kunci idempotensi)');
$periksa($n("SELECT COUNT(*) FROM (SELECT kunjungan_id FROM kesehatan_idempotensi WHERE operasi = 'kunjungan.buat' GROUP BY kunjungan_id HAVING COUNT(*) > 1) d") === 0,
    'Satu klaim pencatatan per kunjungan (0 kunjungan efektif ganda)');
$periksa($n('SELECT COUNT(*) FROM (SELECT idempotensi_id FROM kesehatan_versi_rincian WHERE idempotensi_id IS NOT NULL GROUP BY idempotensi_id HAVING COUNT(*) > 1) d') === 0,
    'Satu versi per klaim koreksi (0 versi efektif ganda)');
if (Schema::hasTable($db, 'kesehatan_ringkasan_peristiwa')) {
    $periksa($n('SELECT COUNT(*) FROM (SELECT idempotensi_id FROM kesehatan_ringkasan_peristiwa WHERE idempotensi_id IS NOT NULL GROUP BY idempotensi_id HAVING COUNT(*) > 1) d') === 0,
        'Satu peristiwa ringkasan per klaim');
    $periksa($n("SELECT COUNT(*) FROM kesehatan_ringkasan_versi rv JOIN kesehatan_kunjungan_versi kv ON kv.kunjungan_id = rv.kunjungan_id AND kv.versi = rv.kunjungan_versi
        WHERE kv.status <> 'Tercatat'") === 0, 'Ringkasan wali hanya bersumber versi kunjungan Tercatat (tidak pernah Draf)');
}
$periksa($n("SELECT COUNT(*) FROM (SELECT event_key, kanal, penerima_user_id FROM notifikasi_outbox WHERE event_type LIKE 'v5\\_kesehatan\\_%'
    GROUP BY event_key, kanal, penerima_user_id HAVING COUNT(*) > 1) d") === 0, '0 notifikasi V5 efektif ganda (peristiwa × kanal × penerima)');
$periksa($n("SELECT COUNT(*) FROM notifikasi_outbox WHERE event_type LIKE 'v5\\_kesehatan\\_%' AND kanal = 'WhatsApp'") === 0, '0 notifikasi V5 kanal WhatsApp');

// Laporan = sumber (seluruh waktu, hak seluruh santri).
$lap = new LaporanRepository($db);
$basis = $lap->basis(['dari' => null, 'sampai' => null, 'kelas' => null], null);
$k = $lap->kunjungan($basis);
$r = $lap->rincian($basis);
$sumber = [
    'total' => $n('SELECT COUNT(*) FROM kesehatan_kunjungan'),
    'draf' => $n("SELECT COUNT(*) FROM kesehatan_kunjungan WHERE status = 'Draf'"),
    'santri' => $n('SELECT COUNT(DISTINCT santri_id) FROM kesehatan_kunjungan'),
    'dengan_obat' => $n('SELECT COUNT(DISTINCT kunjungan_id) FROM kesehatan_obat WHERE versi_dibatalkan IS NULL'),
    'pemberian' => $n('SELECT COUNT(*) FROM kesehatan_obat WHERE versi_dibatalkan IS NULL'),
    'rujukan' => $n('SELECT COUNT(*) FROM kesehatan_rujukan WHERE versi_dibatalkan IS NULL'),
    'rujukan_menunggu' => $n("SELECT COUNT(*) FROM kesehatan_rujukan WHERE versi_dibatalkan IS NULL AND status = 'Menunggu hasil'"),
    'tl' => $n('SELECT COUNT(*) FROM kesehatan_tindak_lanjut WHERE versi_dibatalkan IS NULL'),
    'tl_terbuka' => $n("SELECT COUNT(*) FROM kesehatan_tindak_lanjut WHERE versi_dibatalkan IS NULL AND status = 'Terbuka'"),
    'dikoreksi' => $n('SELECT COUNT(*) FROM kesehatan_kunjungan WHERE versi > 1'),
];
$hasil = [
    'total' => $k['total'], 'draf' => $k['draf'], 'santri' => $k['santri'], 'dengan_obat' => $k['dengan_obat'], 'pemberian' => $r['obat']['pemberian'],
    'rujukan' => $r['rujukan']['total'], 'rujukan_menunggu' => $r['rujukan']['menunggu'], 'tl' => $r['tindak_lanjut']['total'],
    'tl_terbuka' => $r['tindak_lanjut']['terbuka'], 'dikoreksi' => $k['dikoreksi'],
];
$periksa($hasil === $sumber, 'Laporan seluruh waktu = kueri sumber (' . json_encode($sumber) . ')');
$perKelas = array_sum(array_column($lap->perKelas($basis), 'kunjungan'));
$perBulan = array_sum(array_column($lap->perBulan($basis), 'kunjungan'));
$periksa($perKelas === $sumber['total'] && $perBulan === $sumber['total'], "Jumlah per kelas ($perKelas) dan per bulan ($perBulan) = total kunjungan");
if ($lap->ringkasanSiap()) {
    $g = $lap->ringkasan($basis);
    $periksa($g['terbit'] === $n("SELECT COUNT(*) FROM kesehatan_ringkasan WHERE status = 'Terbit'") && $g['ditarik'] === $n("SELECT COUNT(*) FROM kesehatan_ringkasan WHERE status = 'Ditarik'")
        && $g['belum'] === $n("SELECT COUNT(*) FROM kesehatan_kunjungan k WHERE k.status = 'Tercatat' AND NOT EXISTS (SELECT 1 FROM kesehatan_ringkasan g WHERE g.kunjungan_id = k.id)"),
        'Laporan ringkasan wali = kueri sumber');
}

echo PHP_EOL . '=== 3. Identitas santri–wali ===' . PHP_EOL;
if ($manifestPath !== null) {
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest) || !isset($manifest['sidik_jari']) || !is_array($manifest['sidik_jari'])) {
        echo '[belum diperiksa] Manifest tanpa sidik_jari (bukan dari bin/v5_preflight.php).' . PHP_EOL;
    } else {
        $kini = v5_sidik_jari($db);
        foreach ($manifest['sidik_jari'] as $tabel => $sebelum) {
            $periksa(($kini[$tabel] ?? null) === $sebelum, "Sidik jari $tabel tetap ({$sebelum['baris']} baris)");
        }
    }
} else {
    echo '[belum diperiksa] Sidik jari santri/wali: berikan manifest bin/v5_preflight.php.' . PHP_EOL;
}

echo PHP_EOL . 'Ringkasan verifier V5: ' . $lulus . ' lulus, ' . $gagal . ' gagal' . PHP_EOL;
exit($gagal > 0 ? 1 : 0);
