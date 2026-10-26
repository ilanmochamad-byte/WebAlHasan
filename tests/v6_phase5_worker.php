<?php

declare(strict_types=1);

/**
 * Pekerja konkurensi uji V6 Fase 5. Satu proses = satu koneksi DB. Menunggu
 * berkas aba-aba agar seluruh pekerja mulai bersamaan, lalu mencetak satu
 * baris JSON hasil.
 *
 *   DB_NAME=… php tests/v6_phase5_worker.php <aba> <user_id> setoran <santri_id> <kunci> <nominal>
 *   … <aba> <user_id> belanja <santri_id> <kunci> <nominal>
 *   … <aba> <user_id> bayartab <tagihan_id> <kunci> <nominal>
 *   … <aba> <user_id> mutasi <santri_id> <tagihan_id> <ulang>      (setoran → belanja → bayar dari tabungan → batal, berulang)
 *   … <aba> <user_id> laporan <ulang> [tanpa-snapshot]             (membaca laporan berulang dan memeriksa konsistensi internalnya)
 *   … <aba> <user_id> fixture <santri_smp> <santri_tsanawi> <santri_keluar>
 *        (data kecil seluruh jenis transaksi untuk uji rilis: setoran, belanja + koreksi, tagihan, bayar dari
 *        tabungan, bayar tunai dibatalkan, transfer menunggu, setoran transfer ditolak, santri keluar bersaldo)
 */

if (PHP_SAPI !== 'cli' || $argc < 5) {
    exit(2);
}
require_once dirname(__DIR__) . '/app/bootstrap.php';
if (!preg_match('/^test_.*_test$/', (string) app_config('database.database'))) {
    exit(2);
}
$aba = $argv[1];
$user = ['id' => (int) $argv[2], 'roles' => []];
$op = $argv[3];
$a = array_slice($argv, 4);
$batas = microtime(true) + 20;
while (!is_file($aba) && microtime(true) < $batas) {
    usleep(2000);
}
$kunci = static fn (string $awal): string => $awal . '-' . getmypid() . '-' . bin2hex(random_bytes(5));
try {
    $tab = tabungan_service();
    $tag = tagihan_service();
    $hasil = match ($op) {
        'setoran' => $tab->setoran($user, (int) $a[0], ['idempotency_key' => $a[1], 'nominal' => $a[2], 'metode' => 'Tunai'], 'api'),
        'belanja' => $tab->penarikan($user, (int) $a[0], ['idempotency_key' => $a[1], 'jenis' => 'Belanja', 'nominal' => $a[2], 'metode' => 'Tunai', 'tujuan' => 'Uji konkurensi'], 'api'),
        'bayartab' => $tag->bayarDariTabungan($user, (int) $a[0], ['idempotency_key' => $a[1], 'nominal' => $a[2], 'setuju' => '1'], 'api'),
        'mutasi' => (static function () use ($tab, $tag, $user, $a, $kunci): array {
            $ok = 0;
            $tolak = 0;
            for ($i = 0; $i < (int) $a[2]; $i++) {
                foreach ([
                    static fn () => $tab->setoran($user, (int) $a[0], ['idempotency_key' => $kunci('ms'), 'nominal' => 3000, 'metode' => 'Tunai'], 'api'),
                    static fn () => $tab->penarikan($user, (int) $a[0], ['idempotency_key' => $kunci('mb'), 'jenis' => 'Belanja', 'nominal' => 1000, 'metode' => 'Tunai', 'tujuan' => 'Uji'], 'api'),
                    static function () use ($tag, $user, $a, $kunci): void {
                        $b = $tag->bayarDariTabungan($user, (int) $a[1], ['idempotency_key' => $kunci('mt'), 'nominal' => 500, 'setuju' => '1'], 'api');
                        $tag->batalkanPembayaran($user, (int) $b['id'], ['idempotency_key' => $kunci('mx'), 'alasan' => 'Uji konkurensi laporan'], 'api');
                    },
                ] as $langkah) {
                    try {
                        $langkah();
                        $ok++;
                    } catch (\App\Bsa\BsaException $e) {
                        $tolak++;
                    }
                }
            }

            return ['berhasil' => $ok, 'ditolak' => $tolak];
        })(),
        'laporan' => (static function () use ($user, $a): array {
            $lap = laporan_bsa_service();
            $baca = 0;
            $tidakKonsisten = [];
            for ($i = 0; $i < (int) $a[0]; $i++) {
                $r = ($a[1] ?? '') === 'tanpa-snapshot' ? laporanTanpaSnapshot() : $lap->laporan($user, []);
                $baca++;
                $cek = [
                    'selisih_nol' => $r['selisih']['nol'],
                    'saldo_per_santri' => $r['per_santri']['jumlah']['saldo_akhir'] === $r['tabungan']['saldo_akhir'],
                    'terbayar_sumber' => $r['tagihan']['terbayar'] === array_sum($r['tagihan']['pembayaran_sah']),
                    'kas_per_bulan' => array_sum(array_column($r['per_bulan'], 'kas_baru')) === $r['penerimaan_kas_baru'],
                    'internal' => $r['internal']['selisih'] === 0 && $r['internal']['selisih_pengembalian'] === 0,
                ];
                foreach ($cek as $k => $ok) {
                    if (!$ok) {
                        $tidakKonsisten[$k] = ($tidakKonsisten[$k] ?? 0) + 1;
                    }
                }
            }

            return ['baca' => $baca, 'tidak_konsisten' => $tidakKonsisten];
        })(),
        'fixture' => (static function () use ($tab, $tag, $user, $a, $kunci): array {
            [$smp, $tsanawi, $keluar] = array_map('intval', $a);
            $tab->setoran($user, $smp, ['idempotency_key' => $kunci('fs'), 'nominal' => 100000, 'metode' => 'Tunai'], 'api');
            $bl = $tab->penarikan($user, $smp, ['idempotency_key' => $kunci('fb'), 'jenis' => 'Belanja', 'nominal' => 25000, 'metode' => 'Tunai', 'tujuan' => 'Uji rilis'], 'api');
            $tab->koreksi($user, (int) $bl['id'], ['idempotency_key' => $kunci('fk'), 'alasan' => 'Uji rilis koreksi'], 'api');
            $periode = date('Y-m');
            $t = bsa_service()->terbitkanTagihan($user, ['idempotency_key' => $kunci('ft'), 'santri_id' => $smp, 'periode' => $periode,
                'jatuh_tempo' => (new DateTimeImmutable($periode . '-01'))->modify('+1 month -1 day')->format('Y-m-d'), 'komponen' => [['nama' => 'Syahriah rilis', 'nominal' => 100000]]], 'api')['id'];
            $tag->bayarDariTabungan($user, $t, ['idempotency_key' => $kunci('fbt'), 'nominal' => 50000, 'setuju' => '1'], 'api');
            $p = $tag->catatPembayaran($user, $t, ['idempotency_key' => $kunci('fp'), 'nominal' => 20000, 'sumber' => 'Tunai', 'tanggal_transaksi' => date('Y-m-d')], 'api');
            $tag->batalkanPembayaran($user, (int) $p['id'], ['idempotency_key' => $kunci('fx'), 'alasan' => 'Uji rilis pembatalan'], 'api');
            $tag->catatPembayaran($user, $t, ['idempotency_key' => $kunci('fm'), 'nominal' => 10000, 'sumber' => 'Transfer', 'referensi' => 'TRF-RILIS', 'tanggal_transaksi' => date('Y-m-d')], 'api');
            $st = $tab->setoran($user, $tsanawi, ['idempotency_key' => $kunci('fst'), 'nominal' => 25000, 'metode' => 'Transfer', 'referensi' => 'TRF-RILIS-TOLAK'], 'api');
            $tab->tolakSetoran($user, (int) $st['setoran_transfer_id'], ['idempotency_key' => $kunci('ftl'), 'alasan' => 'Uji rilis penolakan'], 'api');
            app_db()->query('UPDATE santri SET is_active = 1 WHERE id = ' . $keluar);
            $tab->setoran($user, $keluar, ['idempotency_key' => $kunci('fsk'), 'nominal' => 12000, 'metode' => 'Tunai'], 'api');
            app_db()->query('UPDATE santri SET is_active = 0 WHERE id = ' . $keluar);

            return ['tagihan_id' => $t];
        })(),
        default => throw new RuntimeException('operasi'),
    };
    echo json_encode(['ok' => true] + $hasil) . PHP_EOL;
} catch (\App\Bsa\BsaException $e) {
    echo json_encode(['ok' => false, 'status' => $e->status, 'errno' => $e->errno]) . PHP_EOL;
}

/**
 * KONTROL: angka yang sama dibaca kueri demi kueri TANPA snapshot (autocommit),
 * untuk menunjukkan uji konsistensi dapat menangkap ketidakcocokan bila
 * laporan tidak dibaca dalam satu snapshot.
 *
 * @return array<string, mixed>
 */
function laporanTanpaSnapshot(): array
{
    $db = app_db();
    $b = new \App\Bsa\BsaRepository($db);
    $repo = new \App\Bsa\LaporanRepository($db, $b, new \App\Bsa\TagihanRepository($db, $b));
    $hak = ['mode' => 'admin', 'user_id' => 0, 'jenjang' => null];
    $f = ['santri_id' => null, 'unit' => null];
    $bulan = date('Y-m');
    $mulai = $bulan . '-01 00:00:00';
    $akhir = (new DateTimeImmutable($bulan . '-01'))->modify('+1 month')->format('Y-m-d 00:00:00');
    $awal = $repo->saldoSebelum($hak, $f, $mulai);
    usleep(3000);
    $ledger = $repo->ledgerBulanan($hak, $f, $mulai, $akhir);
    usleep(3000);
    $akhirSaldo = $repo->saldoSebelum($hak, $f, $akhir);
    usleep(3000);
    $santri = $repo->perSantri($hak, $f, $bulan, $bulan, $mulai, $akhir, '', 1);
    $mutasi = 0;
    foreach ($ledger as $x) {
        $mutasi += $x['arah'] === 'Masuk' ? $x['nominal'] : -$x['nominal'];
    }

    return [
        'selisih' => ['nol' => $awal + $mutasi === $akhirSaldo],
        'per_santri' => ['jumlah' => $santri['jumlah']],
        'tabungan' => ['saldo_akhir' => $akhirSaldo],
        'tagihan' => ['terbayar' => 0, 'pembayaran_sah' => [0]],
        'per_bulan' => [], 'penerimaan_kas_baru' => 0,
        'internal' => ['selisih' => 0, 'selisih_pengembalian' => 0],
    ];
}
