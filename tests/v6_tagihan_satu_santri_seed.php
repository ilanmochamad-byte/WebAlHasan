<?php

declare(strict_types=1);

/**
 * Fixture sintetis uji browser "Terbitkan tagihan" satu santri dari tarif, di
 * atas DB `tests/v6_phase5_db.sh`: tarif SMP bulan ini (Syahriah Rp150.000 +
 * Makan Rp200.000) dan potongan Rp50.000 untuk santri V6S0001. Hanya DB
 * `test_*_test`; dibuat lewat layanan sehingga audit ikut tercatat.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (!preg_match('/^test_.*_test$/', (string) app_config('database.database'))) {
    fwrite(STDERR, "Seed hanya untuk DB test_*_test.\n");
    exit(2);
}
$db = app_db();
$petugas = ['id' => (int) $db->query("SELECT id FROM users WHERE username = 'sbx_v6_bsa_b'")->fetch_row()[0], 'roles' => []];
$santri = (int) $db->query("SELECT id FROM santri WHERE nis = 'V6S0001'")->fetch_row()[0];
$periode = date('Y-m');
$tag = tagihan_service();
$tag->buatTarif($petugas, ['idempotency_key' => 'seed-tarif-' . bin2hex(random_bytes(4)), 'unit' => 'SMP', 'berlaku_mulai' => $periode,
    'komponen' => [['nama' => 'Syahriah', 'nominal' => '150000'], ['nama' => 'Makan', 'nominal' => '200000']]], 'web');
$tag->buatPenyesuaian($petugas, $santri, ['idempotency_key' => 'seed-pj-' . bin2hex(random_bytes(4)), 'jenis' => 'Potongan', 'nama' => 'Potongan anak guru',
    'nominal' => '50000', 'berlaku_mulai' => $periode, 'alasan' => 'Anak guru pesantren'], 'web');
echo "Seed tarif SMP $periode dan potongan santri V6S0001 siap\n";
