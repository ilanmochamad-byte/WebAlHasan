<?php

declare(strict_types=1);

/**
 * Uji integrasi layanan PRD V5 Fase 1 — penugasan kesehatan dan kunjungan
 * dasar — langsung pada `KesehatanService`/`PenugasanService` dan MariaDB uji.
 *
 *   bash tests/v5_phase1_db.sh
 *   V5_RUN_INTEGRATION=1 php tests/v5_phase1_integration.php
 *
 * Skenario (kode = kriteria penerimaan Fase 1):
 *   I-1  matriks hak: petugas, admin, admin+petugas, pengurus tanpa penugasan,
 *        pembimbing, guru non-murobi, murobi, wali;
 *   I-2  admin menugaskan pengurus lewat fondasi penugasan → hak catat;
 *        penugasan oleh non-admin ditolak;
 *   I-3  petugas menyimpan TEPAT satu kunjungan santri aktif, versi 1,
 *        audit metadata tanpa isi klinis, detail tercatat di log akses;
 *   I-4  idempotensi: kunci+isi sama → kunjungan sama; kunci sama isi beda →
 *        409 tanpa perubahan data;
 *   I-5  mutasi ditolak: admin, admin+petugas, pengurus tanpa penugasan,
 *        pembimbing, guru, murobi, wali, akun nonaktif — 0 baris baru;
 *   I-6  admin membaca detail + riwayat akses; petugas tidak membaca riwayat;
 *   I-7  validasi santri/tanggal/teks/kunci — 0 baris dan 0 klaim tersisa;
 *   I-8  penugasan dinonaktifkan/diakhiri kemarin saat "sesi" masih dipakai →
 *        tulis berikutnya ditolak tanpa baris baru, baca juga ditutup;
 *   I-9  konkurensi: 8 proses, kunci+isi sama → 1 kunjungan; 6 proses, kunci
 *        sama isi berbeda → 1 kunjungan, sisanya 409;
 *   I-10 daftar tanpa kolom klinis; 0 isi klinis di audit_logs/outbox;
 *   I-11 tabel lain (santri, wali, santri_wali, izin, PSB, V3, V4) tidak
 *        berubah jumlah barisnya.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

if (getenv('V5_RUN_INTEGRATION') !== '1') {
    fwrite(STDERR, "Set V5_RUN_INTEGRATION=1 untuk menjalankan.\n");
    exit(2);
}
$dbName = getenv('V5F1_DB') ?: 'test_v5_f1_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "Ditolak: DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Kesehatan\KesehatanException;
use App\Penugasan\PenugasanJenis;

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$db = app_db();
$satu = static function (string $sql) use ($db): mixed {
    $r = $db->query($sql);
    if ($r === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
    $row = $r->fetch_row();

    return $row[0] ?? null;
};
$jalankan = static function (string $sql) use ($db): void {
    if ($db->query($sql) === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
};
$uid = static fn (string $u): int => (int) $satu("SELECT id FROM users WHERE username='" . $db->real_escape_string($u) . "'");
$akun = static fn (string $u): array => ['id' => $uid($u), 'roles' => []];
$svc = kesehatan_service();
$tolak = static function (callable $fn) : ?int {
    try {
        $fn();
    } catch (KesehatanException $e) {
        return $e->status;
    }

    return null;
};
$jumlahKunjungan = static fn (): int => (int) $satu('SELECT COUNT(*) FROM kesehatan_kunjungan');
$waktu = date('Y-m-d H:i', strtotime('-15 minutes'));
$santri = (int) $satu("SELECT id FROM santri WHERE nis='V5S0001'");
$santriNonaktif = (int) $satu("SELECT id FROM santri WHERE nis='V5S0002'");
$tahun = (int) $satu("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1");
$assert($santri > 0 && $santriNonaktif > 0 && $uid('sbx_v5_petugas') > 0, 'Fixture V5 Fase 1 tersedia');

// Jumlah baris tabel non-kesehatan sebelum skenario (I-11).
$hitungLain = static function () use ($db): array {
    $hasil = [];
    $r = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
        AND TABLE_NAME NOT LIKE 'kesehatan\\_%' AND TABLE_NAME NOT IN ('audit_logs', 'api_tokens', 'login_attempts', 'schema_migrations') ORDER BY TABLE_NAME");
    foreach ($r->fetch_all(MYSQLI_ASSOC) as $row) {
        $hasil[$row['TABLE_NAME']] = (int) $db->query('SELECT COUNT(*) FROM `' . $row['TABLE_NAME'] . '`')->fetch_row()[0];
    }

    return $hasil;
};
$sebelumLain = $hitungLain();

// ===================================================================== I-1
echo PHP_EOL . '=== I-1. Matriks hak (dihitung server) ===' . PHP_EOL;
$harap = [
    'sbx_v5_petugas' => [false, false, null],
    'sbx_v5_petugas_b' => [true, true, 'petugas'],
    'sbx_admin' => [true, false, 'admin'],
    'sbx_v5_admin_petugas' => [true, false, 'admin_petugas'],
    'sbx_pengurus_biasa' => [false, false, null],
    'sbx_pengurus_a' => [false, false, null],
    'sbx_guru_biasa' => [false, false, null],
    'sbx_murobi_c' => [false, false, null],
    'sbx_ortu_a' => [false, false, null],
];
foreach ($harap as $u => [$baca, $catat, $sumber]) {
    $a = $svc->akses($akun($u));
    $assert($a['baca'] === $baca && $a['catat'] === $catat && $a['sumber'] === $sumber, "$u: baca=" . var_export($baca, true) . ' catat=' . var_export($catat, true) . ' sumber=' . var_export($sumber, true));
}
$assert(PenugasanJenis::definisi(PenugasanJenis::KESEHATAN)['role_dasar'] === 'pengurus' && !in_array('kesehatan', ['admin', 'guru', 'pengurus', 'orang_tua'], true)
    && (int) $satu("SELECT COUNT(*) FROM roles WHERE slug LIKE '%kesehatan%'") === 0, 'Tidak ada role login baru; jenis kesehatan berdasar role pengurus');

// ===================================================================== I-2
echo PHP_EOL . '=== I-2. Admin menugaskan petugas kesehatan ===' . PHP_EOL;
$pengurusPetugas = (int) $satu("SELECT pengurus_id FROM users WHERE username='sbx_v5_petugas'");
$input = ['pengurus_id' => $pengurusPetugas, 'tahun_ajaran_id' => $tahun, 'tanggal_mulai' => date('Y-m-d'), 'catatan' => 'Uji I-2'];
try {
    penugasan_service()->buat(PenugasanJenis::KESEHATAN, $input, $uid('sbx_pengurus_a'));
    $assert(false, 'Non-admin ditolak membuat penugasan kesehatan');
} catch (\App\Penugasan\PenugasanException $e) {
    $assert($e->status() === 403, 'Non-admin ditolak membuat penugasan kesehatan (403)');
}
$tugasId = penugasan_service()->buat(PenugasanJenis::KESEHATAN, $input, $uid('sbx_admin'));
$assert($tugasId > 0 && (int) $satu("SELECT COUNT(*) FROM kesehatan_assignments WHERE pengurus_id = $pengurusPetugas AND is_active = 1") === 1, 'Admin membuat satu penugasan kesehatan aktif');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'penugasan.buat' AND entity_type = 'kesehatan_assignment' AND entity_id = $tugasId") === 1, 'Penugasan tercatat di audit (penugasan.buat, kesehatan_assignment)');
$a = $svc->akses($akun('sbx_v5_petugas'));
$assert($a['catat'] && $a['sumber'] === 'petugas', 'Petugas kini memperoleh hak catat dari penugasan aktif');
try {
    penugasan_service()->buat(PenugasanJenis::KESEHATAN, $input + ['tanggal_mulai' => date('Y-m-d')], $uid('sbx_admin'));
    $assert(false, 'Penugasan ganda bertumpang tindih ditolak');
} catch (\App\Penugasan\PenugasanException $e) {
    $assert($e->status() === 409, 'Penugasan ganda bertumpang tindih ditolak (409)');
}
$efektif = penugasan_service()->capabilityEfektif(PenugasanJenis::KESEHATAN, $pengurusPetugas);
$assert($efektif['capabilities'] === [PenugasanJenis::CAP_KESEHATAN_CATAT], 'Pusat penugasan menampilkan capability efektif kesehatan.catat');

// ===================================================================== I-3
echo PHP_EOL . '=== I-3. Petugas mencatat tepat satu kunjungan ===' . PHP_EOL;
$penanda = 'V5UJI-' . bin2hex(random_bytes(4));
$isi = [
    'idempotency_key' => 'uji-i3-' . bin2hex(random_bytes(6)),
    'santri_id' => (string) $santri,
    'waktu_kunjungan' => $waktu,
    'keluhan' => "$penanda-KELUHAN demam sejak pagi",
    'catatan_klinis' => "$penanda-CATATAN suhu 38,2",
    'tindakan' => "$penanda-TINDAKAN kompres dan istirahat",
];
$petugas = $akun('sbx_v5_petugas');
$awal = $jumlahKunjungan();
$h = $svc->buat($petugas, $isi, 'web');
$assert($h['status'] === 201 && !$h['replayed'] && $jumlahKunjungan() === $awal + 1, 'Kunjungan tersimpan (201), tepat satu baris baru');
$row = $db->query('SELECT * FROM kesehatan_kunjungan WHERE id = ' . (int) $h['id'])->fetch_assoc();
$assert((int) $row['santri_id'] === $santri && $row['status'] === 'Tercatat' && (int) $row['versi'] === 1 && (int) $row['petugas_user_id'] === $petugas['id']
    && (int) $row['penugasan_id'] === $tugasId && $row['waktu_kunjungan'] === $waktu . ':00', 'Baris kunjungan: santri, status Tercatat, versi 1, petugas, penugasan, waktu');
$assert((int) $satu('SELECT COUNT(*) FROM kesehatan_kunjungan_versi WHERE kunjungan_id = ' . (int) $h['id'] . " AND versi = 1 AND alasan IS NULL AND keluhan = '" . $db->real_escape_string($isi['keluhan']) . "'") === 1, 'Snapshot versi 1 tersimpan');
$audit = (string) $satu("SELECT after_json FROM audit_logs WHERE action = 'kesehatan.kunjungan.dicatat' AND entity_id = " . (int) $h['id']);
$assert($audit !== '' && !str_contains($audit, $penanda) && !preg_match('/keluhan|catatan|tindakan/', $audit) && str_contains($audit, '"versi":1'), 'Audit metadata tanpa isi klinis');
$detail = $svc->detail($petugas, (int) $h['id'], 'web');
$assert($detail['keluhan'] === $isi['keluhan'] && $detail['catatan_klinis'] === $isi['catatan_klinis'] && $detail['tindakan'] === $isi['tindakan'], 'Petugas membaca ulang isi kunjungan yang sama');
$assert((int) $satu('SELECT COUNT(*) FROM kesehatan_akses_log WHERE kunjungan_id = ' . (int) $h['id'] . " AND user_id = {$petugas['id']} AND sumber = 'petugas' AND kanal = 'web' AND aksi = 'baca_detail'") === 1, 'Pembacaan detail petugas tercatat (metadata)');
$kunjunganId = (int) $h['id'];

// ===================================================================== I-4
echo PHP_EOL . '=== I-4. Idempotensi ===' . PHP_EOL;
$h2 = $svc->buat($petugas, $isi, 'api');
$assert($h2['id'] === $kunjunganId && $h2['replayed'] && $h2['status'] === 200 && $jumlahKunjungan() === $awal + 1, 'Kunci + isi sama → kunjungan yang sama, tanpa baris baru (kanal berbeda tetap sama)');
$beda = $isi;
$beda['keluhan'] = "$penanda-KELUHAN berbeda";
$s = $tolak(static fn () => $svc->buat($petugas, $beda, 'web'));
$assert($s === 409 && $jumlahKunjungan() === $awal + 1, 'Kunci sama + isi berbeda → 409, tanpa baris baru');
$assert((string) $satu("SELECT keluhan FROM kesehatan_kunjungan WHERE id = $kunjunganId") === $isi['keluhan'], 'Kunjungan asli tidak berubah oleh permintaan 409');
$h3 = $svc->buat($akun('sbx_v5_petugas_b'), $isi, 'web');
$assert(!$h3['replayed'] && $h3['id'] !== $kunjunganId, 'Kunci idempotensi berlaku per akun (petugas lain = kunjungan lain)');

// ===================================================================== I-5
echo PHP_EOL . '=== I-5. Mutasi klinis ditolak ===' . PHP_EOL;
$sebelum = $jumlahKunjungan();
foreach (['sbx_admin' => 'admin', 'sbx_v5_admin_petugas' => 'admin yang juga petugas', 'sbx_pengurus_biasa' => 'pengurus tanpa penugasan',
    'sbx_pengurus_a' => 'pembimbing', 'sbx_guru_biasa' => 'guru non-murobi', 'sbx_murobi_c' => 'murobi', 'sbx_ortu_a' => 'wali'] as $u => $label) {
    $x = $isi;
    $x['idempotency_key'] = 'uji-i5-' . bin2hex(random_bytes(6));
    $s = $tolak(static fn () => $svc->buat($akun($u), $x, 'api'));
    $assert($s === 403, "$label ditolak mencatat (403)");
}
$jalankan("UPDATE users SET is_active = 0 WHERE username = 'sbx_v5_petugas_c'");
$x = $isi;
$x['idempotency_key'] = 'uji-i5-nonaktif-' . bin2hex(random_bytes(4));
$assert($tolak(static fn () => $svc->buat($akun('sbx_v5_petugas_c'), $x, 'web')) === 403, 'Akun petugas nonaktif ditolak mencatat (403)');
$assert($tolak(static fn () => $svc->daftar($akun('sbx_v5_petugas_c'), [])) === 403, 'Akun petugas nonaktif ditolak membaca (403)');
$jalankan("UPDATE users SET is_active = 1 WHERE username = 'sbx_v5_petugas_c'");
$assert($jumlahKunjungan() === $sebelum && (int) $satu("SELECT COUNT(*) FROM kesehatan_idempotensi WHERE kunci LIKE 'uji-i5-%'") === 0, 'Seluruh penolakan: 0 kunjungan dan 0 klaim idempotensi baru');
foreach (['sbx_pengurus_biasa', 'sbx_pengurus_a', 'sbx_guru_biasa', 'sbx_murobi_c', 'sbx_ortu_a'] as $u) {
    $assert($tolak(static fn () => $svc->daftar($akun($u), [])) === 403 && $tolak(static fn () => $svc->detail($akun($u), $kunjunganId, 'api')) === 403, "$u ditolak membaca daftar dan detail (Fase 1)");
}

// ===================================================================== I-6
echo PHP_EOL . '=== I-6. Admin membaca detail dan audit akses ===' . PHP_EOL;
$adm = $akun('sbx_admin');
$d = $svc->detail($adm, $kunjunganId, 'web');
$assert($d['keluhan'] === $isi['keluhan'] && !$d['akses']['catat'] && $d['akses']['riwayat_akses'], 'Admin membaca rincian (hanya baca)');
$assert((int) $satu("SELECT COUNT(*) FROM kesehatan_akses_log WHERE kunjungan_id = $kunjunganId AND user_id = {$adm['id']} AND sumber = 'admin'") === 1, 'Akses admin tercatat sumber admin');
$rw = $svc->riwayatAkses($adm, $kunjunganId);
$assert($rw['total'] === 2 && $rw['rows'][0]['pengguna']['id'] === $adm['id'] && $rw['rows'][0]['sumber'] === 'admin', 'Admin membaca riwayat akses (2 pembukaan: petugas, admin)');
$assert($tolak(static fn () => $svc->riwayatAkses($petugas, $kunjunganId)) === 403, 'Petugas tidak membaca riwayat akses (403)');
$assert($tolak(static fn () => $svc->detail($adm, 99999999, 'web')) === 404, 'Kunjungan tidak ada → 404');
$ap = $svc->detail($akun('sbx_v5_admin_petugas'), $kunjunganId, 'api');
$assert($ap['akses']['sumber'] === 'admin_petugas' && (int) $satu("SELECT COUNT(*) FROM kesehatan_akses_log WHERE kunjungan_id = $kunjunganId AND sumber = 'admin_petugas' AND kanal = 'api'") === 1, 'Admin yang juga petugas: baca tercatat sebagai admin_petugas');

// ===================================================================== I-7
echo PHP_EOL . '=== I-7. Validasi ===' . PHP_EOL;
$sebelum = $jumlahKunjungan();
$kasus = [
    'santri nonaktif' => ['santri_id' => (string) $santriNonaktif],
    'santri tidak ada' => ['santri_id' => '99999999'],
    'santri kosong' => ['santri_id' => ''],
    'santri bukan angka' => ['santri_id' => '1 OR 1=1'],
    'waktu masa depan' => ['waktu_kunjungan' => date('Y-m-d H:i', strtotime('+2 hours'))],
    'waktu tidak valid' => ['waktu_kunjungan' => '2026-02-30 10:00'],
    'waktu kosong' => ['waktu_kunjungan' => ''],
    'keluhan kosong' => ['keluhan' => '   '],
    'keluhan terlalu panjang' => ['keluhan' => str_repeat('a', 1001)],
    'catatan terlalu panjang' => ['catatan_klinis' => str_repeat('b', 4001)],
    'keluhan bukan teks' => ['keluhan' => ['x']],
    'kunci terlalu pendek' => ['idempotency_key' => 'abc'],
    'kunci karakter tak aman' => ['idempotency_key' => "kunci'; DROP--"],
];
foreach ($kasus as $label => $ubah) {
    $x = array_merge($isi, ['idempotency_key' => 'uji-i7-' . bin2hex(random_bytes(6))], $ubah);
    $assert($tolak(static fn () => $svc->buat($petugas, $x, 'web')) === 422, "Validasi: $label → 422");
}
$assert($jumlahKunjungan() === $sebelum && (int) $satu("SELECT COUNT(*) FROM kesehatan_idempotensi WHERE kunci LIKE 'uji-i7-%'") === 0, 'Validasi gagal: 0 kunjungan dan 0 klaim idempotensi tersisa');

// ===================================================================== I-8
echo PHP_EOL . '=== I-8. Penugasan diakhiri saat sesi masih aktif ===' . PHP_EOL;
$petugasB = $akun('sbx_v5_petugas_b');
$tugasB = (int) $satu("SELECT a.id FROM kesehatan_assignments a JOIN users u ON u.pengurus_id = a.pengurus_id WHERE u.username = 'sbx_v5_petugas_b'");
$x = array_merge($isi, ['idempotency_key' => 'uji-i8-a-' . bin2hex(random_bytes(4)), 'keluhan' => "$penanda-B sebelum pencabutan"]);
$assert($svc->buat($petugasB, $x, 'web')['status'] === 201, 'Petugas B mencatat sebelum pencabutan');
penugasan_service()->nonaktifkan(PenugasanJenis::KESEHATAN, $tugasB, 'Uji pencabutan saat sesi aktif', $uid('sbx_admin'));
$sebelum = $jumlahKunjungan();
$x['idempotency_key'] = 'uji-i8-b-' . bin2hex(random_bytes(4));
$assert($tolak(static fn () => $svc->buat($petugasB, $x, 'web')) === 403 && $jumlahKunjungan() === $sebelum, 'Penugasan dinonaktifkan → tulis berikutnya 403 tanpa baris baru');
$assert($tolak(static fn () => $svc->detail($petugasB, $kunjunganId, 'web')) === 403 && $tolak(static fn () => $svc->daftar($petugasB, [])) === 403, 'Penugasan dinonaktifkan → baca daftar/detail juga ditutup');
// Diakhiri dengan tanggal selesai kemarin (masa berlaku inklusif).
$tugasC = (int) $satu("SELECT a.id FROM kesehatan_assignments a JOIN users u ON u.pengurus_id = a.pengurus_id WHERE u.username = 'sbx_v5_petugas_c'");
penugasan_service()->akhiri(PenugasanJenis::KESEHATAN, $tugasC, date('Y-m-d', strtotime('-1 day')), 'Uji akhiri kemarin', $uid('sbx_admin'));
$x['idempotency_key'] = 'uji-i8-c-' . bin2hex(random_bytes(4));
$assert($tolak(static fn () => $svc->buat($akun('sbx_v5_petugas_c'), $x, 'web')) === 403 && $jumlahKunjungan() === $sebelum, 'Penugasan diakhiri (selesai kemarin) → tulis 403 tanpa baris baru');
$assert((int) $satu("SELECT COUNT(*) FROM kesehatan_idempotensi WHERE kunci LIKE 'uji-i8-b-%' OR kunci LIKE 'uji-i8-c-%'") === 0, 'Penolakan pencabutan tidak meninggalkan klaim idempotensi');

// ===================================================================== I-9
echo PHP_EOL . '=== I-9. Konkurensi (proses terpisah) ===' . PHP_EOL;
$scratch = sys_get_temp_dir() . '/v5f1-' . bin2hex(random_bytes(4));
mkdir($scratch);
$serbu = static function (array $permintaan) use ($scratch, $dbName): array {
    $aba = $scratch . '/mulai-' . bin2hex(random_bytes(3));
    $proc = [];
    foreach ($permintaan as $i => $p) {
        $proc[$i] = proc_open(array_merge(['php', dirname(__DIR__) . '/tests/v5_phase1_worker.php'], array_map('strval', $p), [$aba]),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['DB_NAME' => $dbName]));
        $proc[$i] = [$proc[$i], $pipes];
    }
    usleep(400000);
    touch($aba);
    $hasil = [];
    foreach ($proc as [$h, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($h);
        $hasil[] = json_decode(trim((string) $out), true) ?? ['ok' => false, 'status' => -1];
    }

    return $hasil;
};
$sebelum = $jumlahKunjungan();
$kunci = 'uji-i9-sama-' . bin2hex(random_bytes(4));
$hasil = $serbu(array_fill(0, 8, [$petugas['id'], $santri, $kunci, "$penanda-SERBU", $waktu]));
$ids = array_unique(array_column(array_filter($hasil, static fn ($r) => $r['ok']), 'id'));
$baru = count(array_filter($hasil, static fn ($r) => $r['ok'] && !$r['replayed']));
$assert(count($ids) === 1 && $baru === 1 && count(array_filter($hasil, static fn ($r) => $r['ok'])) === 8 && $jumlahKunjungan() === $sebelum + 1,
    '8 permintaan bersamaan kunci+isi sama → 1 kunjungan, 7 replay (' . json_encode($hasil) . ')');
$sebelum = $jumlahKunjungan();
$kunci = 'uji-i9-beda-' . bin2hex(random_bytes(4));
$permintaan = [];
for ($i = 0; $i < 6; $i++) {
    $permintaan[] = [$petugas['id'], $santri, $kunci, "$penanda-BEDA-$i", $waktu];
}
$hasil = $serbu($permintaan);
$sukses = array_filter($hasil, static fn ($r) => $r['ok']);
$konflik = array_filter($hasil, static fn ($r) => !$r['ok'] && $r['status'] === 409);
$assert(count($sukses) === 1 && count($konflik) === 5 && $jumlahKunjungan() === $sebelum + 1, '6 permintaan bersamaan kunci sama isi berbeda → 1 kunjungan, 5 konflik 409');
array_map('unlink', glob($scratch . '/*') ?: []);
rmdir($scratch);
$assert((int) $satu('SELECT COUNT(*) FROM (SELECT created_by, idempotency_key FROM kesehatan_kunjungan GROUP BY created_by, idempotency_key HAVING COUNT(*) > 1) g') === 0, 'Tidak ada kunjungan ganda per (akun, kunci)');

// ===================================================================== I-10
echo PHP_EOL . '=== I-10. Privasi daftar dan log umum ===' . PHP_EOL;
$daftar = $svc->daftar($petugas, ['q' => 'SBX V5 Santri Klinik']);
$json = json_encode($daftar['rows']);
$assert($daftar['total'] >= 3 && !str_contains((string) $json, $penanda) && !str_contains((string) $json, 'keluhan') && !str_contains((string) $json, 'catatan'), 'Daftar: satu baris per kunjungan, tanpa kolom/isi klinis');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE CONCAT(COALESCE(before_json,''), COALESCE(after_json,'')) LIKE '%" . $penanda . "%'") === 0, 'audit_logs memuat 0 isi klinis penanda uji');
$assert((int) $satu("SELECT COUNT(*) FROM notifikasi_outbox WHERE CONCAT(COALESCE(judul,''), COALESCE(isi,''), COALESCE(data_json,'')) LIKE '%" . $penanda . "%'") === 0, 'notifikasi_outbox memuat 0 isi klinis (Fase 1 tanpa notifikasi)');
$assert((int) $satu("SELECT COUNT(*) FROM kesehatan_akses_log l JOIN kesehatan_kunjungan k ON k.id = l.kunjungan_id WHERE l.santri_id <> k.santri_id") === 0, 'Log akses konsisten dengan santri kunjungannya');

// ===================================================================== I-11
echo PHP_EOL . '=== I-11. Tabel lain tidak berubah ===' . PHP_EOL;
$sesudahLain = $hitungLain();
$berubah = [];
foreach ($sebelumLain as $t => $n) {
    if (($sesudahLain[$t] ?? -1) !== $n) {
        $berubah[$t] = [$n, $sesudahLain[$t] ?? null];
    }
}
$assert($berubah === [], 'Jumlah baris seluruh tabel non-kesehatan tetap (' . count($sebelumLain) . ' tabel)' . ($berubah === [] ? '' : ': ' . json_encode($berubah)));

echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
