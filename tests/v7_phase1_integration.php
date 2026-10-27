<?php

declare(strict_types=1);

/**
 * Uji integrasi PRD V7 Fase 1 — layanan pengajuan wali di atas DB uji
 * `tests/v7_phase1_db.sh` (fixture sintetis), termasuk konkurensi proses
 * terpisah lewat `tests/v7_phase1_worker.php`.
 *
 *   bash tests/v7_phase1_db.sh && php tests/v7_phase1_integration.php
 *
 *   I-1  satu pengajuan wali → tepat satu pembimbing sah; murobi nol; riwayat + audit
 *   I-2  idempotensi: kunci+isi sama → ID sama; kunci sama isi beda → 409 tanpa baris
 *   I-3  hak baca: wali pengaju lengkap, wali kedua ringkas, wali asing/dicabut/ID
 *        tebakan 404/kosong; pembimbing tujuan vs lain; murobi; permukaan V2
 *   I-4  nol/dua/tanpa-akun pembimbing → antrean admin; penetapan admin hanya sah,
 *        versi, idempotensi, non-admin ditolak
 *   I-5  benturan tanggal lintas jalur wali ↔ pengurus V2; pembatalan melepas rentang
 *   I-6  konkurensi proses terpisah: klik ganda, wali vs pengurus bersamaan
 *   I-7  pencabutan relasi/penugasan/akun pada sesi lama
 *   I-8  validasi masukan dan santri nonaktif
 *   I-9  CHECK basis data dan jalur V2 menolak baris tahap V7
 *   I-10 verifier sesudah seluruh uji
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$dbName = getenv('V7F1_DB') ?: 'test_v7_f1_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once $root . '/app/bootstrap.php';

use App\Izin\IzinException;

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

    return $r->fetch_row()[0] ?? null;
};
$jalankan = static function (string $sql) use ($db): void {
    if ($db->query($sql) === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
    \App\Database\Schema::forget();
};
$akun = static function (string $username) use ($satu): array {
    $id = (int) $satu("SELECT id FROM users WHERE username = '$username'");
    $guru = $satu("SELECT guru_id FROM users WHERE id = $id");

    return ['id' => $id, 'roles' => [], 'guru_id' => $guru === null ? null : (int) $guru];
};
$sid = static fn (string $nis): int => (int) $satu("SELECT id FROM santri WHERE nis = '$nis'");
$galat = static function (callable $f): ?int {
    try {
        $f();

        return null;
    } catch (IzinException $e) {
        return $e->status();
    }
};
$meta = ['ip' => '127.0.0.1', 'user_agent' => 'uji-v7'];
$svc = static fn () => izin_wali_service();
$jumlahPengajuan = static fn (): int => (int) $satu('SELECT COUNT(*) FROM izin_pengajuan');
$isi = static fn (int $santri, string $dari, string $sampai, string $alasan = 'Acara keluarga besar', ?string $catatan = null): array =>
    ['santri_id' => $santri, 'tgl_izin' => $dari, 'tgl_kembali' => $sampai, 'alasan' => $alasan, 'catatan' => $catatan];

$wA = $akun('sbx_v7_wali_a');
$wB = $akun('sbx_v7_wali_b');
$wDicabut = $akun('sbx_v7_wali_dicabut');
$wAsing = $akun('sbx_ortu_b');
$pb1 = $akun('sbx_v7_pembimbing_satu');
$pb2 = $akun('sbx_v7_pembimbing_dua');
$admin = $akun('sbx_admin');
$murobiA = $akun('sbx_murobi_a');
$sSatu = $sid('V7S0001');
$sNol = $sid('V7S0002');
$sDua = $sid('V7S0003');
$sTanpaAkun = $sid('V7S0004');
$sNonaktif = $sid('V7S0005');
$pengurusSatu = (int) $satu("SELECT pengurus_id FROM users WHERE username = 'sbx_v7_pembimbing_satu'");
$pengurusDua = (int) $satu("SELECT pengurus_id FROM users WHERE username = 'sbx_v7_pembimbing_dua'");
$pengurusTanpaAkun = (int) $satu("SELECT id FROM pengurus WHERE nomor_identitas = 'SBX-V7-P-003'");
$assert(min($wA['id'], $wB['id'], $pb1['id'], $pb2['id'], $sSatu, $sNol, $sDua, $sTanpaAkun, $pengurusTanpaAkun) > 0
    && (int) $satu("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan = 'Wali'") === 0, 'Fixture V7 tersedia; belum ada pengajuan wali');
$v2Sebelum = (string) $satu("SELECT SHA2(GROUP_CONCAT(CONCAT_WS(':', id, status, version) ORDER BY id), 256) FROM izin_pengajuan");

// ===================================================================== I-1
echo PHP_EOL . '=== I-1. Satu pengajuan wali, tepat satu pembimbing sah ===' . PHP_EOL;
$antreanMurobiSebelum = izin_service()->queueCount(['roles' => ['guru']] + $murobiA, 'murobi');
$h1 = $svc()->buat($wA, $isi($sSatu, '2026-11-02', '2026-11-03', 'Menghadiri pernikahan kakak', 'Dijemput ayah'), 'v7-int-satu-0001', $meta);
$id1 = (int) $h1['id'];
$baris = $db->query("SELECT * FROM izin_pengajuan WHERE id = $id1")->fetch_assoc();
$assert($h1['status_http'] === 201 && $h1['status'] === 'Menunggu Pembimbing' && $h1['idempotent_replay'] === false, 'Pengajuan wali tersimpan (201) berstatus Menunggu Pembimbing');
$assert($baris['asal_pengajuan'] === 'Wali' && (int) $baris['wali_id'] === (int) $satu("SELECT wali_id FROM users WHERE id = {$wA['id']}")
    && (int) $baris['diajukan_oleh_user_id'] === $wA['id'] && $baris['pengurus_id'] === null, 'Asal Wali, wali pengaju dan akun pengaju tercatat; tanpa pengurus pengaju');
$assert((int) $baris['pembimbing_pengurus_id'] === $pengurusSatu && (int) $baris['pembimbing_routing_kandidat'] === 1
    && (int) $baris['pembimbing_assignment_id'] === (int) $satu("SELECT id FROM pembimbing_assignments WHERE pengurus_id = $pengurusSatu AND kamar_id = (SELECT id FROM kamar WHERE nama_kamar = 'SBX V7 Kamar Satu')"),
    'Routing: satu kandidat sah → Pembimbing Satu dan penugasannya');
$assert($baris['murobi_guru_id'] === null && (int) $baris['version'] === 1 && $baris['catatan_wali'] === 'Dijemput ayah' && (int) $baris['tahun_ajaran_id'] > 0,
    'Murobi belum ditetapkan; versi 1; catatan wali dan tahun ajaran tersimpan');
$riwayat = $db->query("SELECT peristiwa, alasan FROM izin_riwayat_status WHERE pengajuan_id = $id1 ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$assert(array_column($riwayat, 'peristiwa') === ['pengajuan_wali_dibuat', 'routing_pembimbing_otomatis'] && $riwayat[0]['alasan'] === null,
    'Riwayat: dibuat + routing pembimbing; alasan izin tidak disalin ke riwayat');
$audit = (string) $satu("SELECT after_json FROM audit_logs WHERE action = 'izin_wali_pengajuan_dibuat' AND entity_id = $id1");
$assert($audit !== '' && !str_contains($audit, 'pernikahan') && !str_contains($audit, 'Dijemput') && !str_contains($audit, '2026-11-02'),
    'Audit pembuatan ada dan tanpa alasan/catatan/tanggal');
$antrean1 = $svc()->antreanPembimbing($pb1);
$assert($antrean1['total'] === 1 && $antrean1['items'][0]['id'] === $id1 && $antrean1['items'][0]['alasan'] === 'Menghadiri pernikahan kakak', 'Pembimbing Satu melihat tepat satu item dengan isi lengkap');
$assert($svc()->antreanPembimbing($pb2)['total'] === 0, 'Pembimbing Dua (bukan tujuan) melihat nol item');
$assert(izin_service()->queueCount(['roles' => ['guru']] + $murobiA, 'murobi') === $antreanMurobiSebelum
    && (int) $satu("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan = 'Wali' AND murobi_guru_id IS NOT NULL") === 0,
    'Murobi (kamar santri) tidak menerima item baru sebelum penerusan');
$assert($galat(fn () => $svc()->antreanPembimbing($murobiA)) === 403 && $galat(fn () => $svc()->daftarWali($murobiA)) === 403
    && $galat(fn () => $svc()->detail($murobiA, $id1)) === 404, 'Murobi ditolak pada antrean/daftar/detail V7 (403/404)');

// ===================================================================== I-2
echo PHP_EOL . '=== I-2. Idempotensi ===' . PHP_EOL;
$n = $jumlahPengajuan();
$h1b = $svc()->buat($wA, $isi($sSatu, '2026-11-02', '2026-11-03', 'Menghadiri pernikahan kakak', 'Dijemput ayah'), 'v7-int-satu-0001', $meta);
$assert($h1b['id'] === $id1 && $h1b['idempotent_replay'] === true && $h1b['status_http'] === 200 && $jumlahPengajuan() === $n, 'Kiriman identik kunci sama → ID sama, 200 replay, tanpa baris baru');
$assert($galat(fn () => $svc()->buat($wA, $isi($sSatu, '2026-11-02', '2026-11-04', 'Menghadiri pernikahan kakak'), 'v7-int-satu-0001', $meta)) === 409
    && $jumlahPengajuan() === $n, 'Kunci sama isi berbeda → 409, tanpa baris baru');
$assert($galat(fn () => $svc()->buat($wA, $isi($sSatu, '2026-11-02', '2026-11-03', 'Menghadiri pernikahan kakak'), 'v7-int-satu-0002', $meta)) === 409
    && $jumlahPengajuan() === $n, 'Kunci baru rentang sama → 409 benturan, tanpa baris baru');
$assert($galat(fn () => $svc()->buat($wA, $isi($sSatu, '2026-11-20', '2026-11-20'), null, $meta)) === 422, 'Tanpa kunci idempotensi → 422');

// ===================================================================== I-3
echo PHP_EOL . '=== I-3. Hak baca per wali/peran ===' . PHP_EOL;
$dA = $svc()->detail($wA, $id1);
$assert($dA['peran_akses'] === 'wali' && $dA['pengajuan']['pengaju_sendiri'] === true && $dA['pengajuan']['alasan'] === 'Menghadiri pernikahan kakak'
    && $dA['riwayat'] !== [] && $dA['tindakan'] === [], 'Wali pengaju: isi lengkap + riwayat; belum ada tindakan (Fase 2)');
$dB = $svc()->detail($wB, $id1);
$jsonB = json_encode($dB);
$assert($dB['pengajuan']['ringkas'] === true && !array_key_exists('alasan', $dB['pengajuan']) && !array_key_exists('catatan', $dB['pengajuan'])
    && $dB['riwayat'] === [] && $dB['tindakan'] === [] && !str_contains((string) $jsonB, 'pernikahan') && !str_contains((string) $jsonB, 'Dijemput'),
    'Wali kedua: status ringkas tanpa alasan/catatan/riwayat/tindakan');
$daftarB = $svc()->daftarWali($wB);
$assert($daftarB['total'] === 1 && $daftarB['items'][0]['ringkas'] === true && !str_contains((string) json_encode($daftarB), 'pernikahan'), 'Daftar wali kedua: satu item ringkas');
$assert($svc()->daftarWali($wAsing)['total'] === 0 && $galat(fn () => $svc()->detail($wAsing, $id1)) === 404, 'Wali asing: daftar kosong, detail 404');
$assert($svc()->daftarWali($wDicabut)['total'] === 0 && $galat(fn () => $svc()->detail($wDicabut, $id1)) === 404, 'Wali dengan relasi diarsipkan: daftar kosong, detail 404');
$maks = (int) $satu('SELECT MAX(id) FROM izin_pengajuan');
$v2Id = (int) $satu("SELECT id FROM izin_pengajuan WHERE asal_pengajuan = 'Pengurus' ORDER BY id LIMIT 1");
$assert($galat(fn () => $svc()->detail($wA, $maks + 50)) === 404 && $galat(fn () => $svc()->detail($admin, $v2Id)) === 404, 'ID tebakan dan ID pengajuan pengurus → 404 di detail V7');
$assert($galat(fn () => $svc()->detail($pb2, $id1)) === 404 && $svc()->detail($pb1, $id1)['peran_akses'] === 'pembimbing', 'Detail: pembimbing lain 404, pembimbing tujuan lengkap');
$assert($galat(fn () => $svc()->buat($wAsing, $isi($sSatu, '2026-12-01', '2026-12-01'), 'v7-int-asing-01', $meta)) === 404
    && $galat(fn () => $svc()->buat($wDicabut, $isi($sSatu, '2026-12-01', '2026-12-01'), 'v7-int-dicabut-01', $meta)) === 404
    && $galat(fn () => $svc()->buat($pb1, $isi($sSatu, '2026-12-01', '2026-12-01'), 'v7-int-pb1-01', $meta)) === 403
    && $galat(fn () => $svc()->buat($admin, $isi($sSatu, '2026-12-01', '2026-12-01'), 'v7-int-adm-01', $meta)) === 403,
    'Buat: wali asing/dicabut 404, pengurus dan admin 403 (jalur wali)');
// Permukaan V2 tidak memuat tahap V7.
$ortuV2 = izin_service()->list(['roles' => ['orang_tua']] + $wB, ['santri_id' => $sSatu], 1, 50, 'orang_tua');
$adminV2 = izin_service()->list(['roles' => ['admin']] + $admin, ['status' => ''], 1, 100, 'admin');
$assert(!in_array($id1, array_map('intval', array_column($ortuV2['rows'], 'id')), true)
    && !in_array($id1, array_map('intval', array_column($adminV2['rows'], 'id')), true)
    && $galat(fn () => izin_service()->detail(['roles' => ['admin']] + $admin, $id1, 'admin')) === 403
    && $galat(fn () => izin_service()->detail(['roles' => ['orang_tua']] + $wB, $id1, 'orang_tua')) === 403,
    'Daftar/detail V2 (orang tua dan admin) tidak memuat pengajuan tahap pembimbing');
$cari = izin_service()->list(['roles' => ['orang_tua']] + $wB, ['q' => 'pernikahan'], 1, 50, 'orang_tua');
$assert($cari['total'] === 0, 'Pencarian alasan lewat daftar V2 orang tua tidak menemukan alasan wali lain');

// ===================================================================== I-4
echo PHP_EOL . '=== I-4. Nol/dua/tanpa-akun pembimbing → admin; penetapan ===' . PHP_EOL;
$hNol = $svc()->buat($wA, $isi($sNol, '2026-11-05', '2026-11-05'), 'v7-int-nol-0001', $meta);
$hDua = $svc()->buat($wA, $isi($sDua, '2026-11-05', '2026-11-06'), 'v7-int-dua-0001', $meta);
$hTanpa = $svc()->buat($wA, $isi($sTanpaAkun, '2026-11-05', '2026-11-05'), 'v7-int-tanpa-0001', $meta);
$idNol = (int) $hNol['id'];
$idDua = (int) $hDua['id'];
$idTanpa = (int) $hTanpa['id'];
$assert($hNol['status'] === 'Perlu Penetapan Pembimbing' && $hDua['status'] === 'Perlu Penetapan Pembimbing' && $hTanpa['status'] === 'Perlu Penetapan Pembimbing',
    'Nol kandidat, dua kandidat, dan pembimbing tanpa akun → Perlu Penetapan Pembimbing');
$assert((int) $satu("SELECT pembimbing_routing_kandidat FROM izin_pengajuan WHERE id = $idDua") === 2 && (int) $satu("SELECT pembimbing_routing_kandidat FROM izin_pengajuan WHERE id = $idNol") === 0
    && (int) $satu("SELECT pembimbing_routing_kandidat FROM izin_pengajuan WHERE id = $idTanpa") === 0, 'Jumlah kandidat sah tercatat (0, 2, 0)');
$adminAntrean = $svc()->daftarAdmin($admin, ['antrean' => '1']);
$idsAdmin = array_column($adminAntrean['items'], 'id');
$assert($adminAntrean['total'] === 3 && !array_diff([$idNol, $idDua, $idTanpa], $idsAdmin) && !in_array($id1, $idsAdmin, true), 'Antrean admin tepat tiga item pengecualian (tanpa pengajuan bertujuan tunggal)');
$assert($svc()->antreanPembimbing($pb1)['total'] === 1 && $svc()->antreanPembimbing($pb2)['total'] === 0, 'Kandidat ganda belum masuk antrean pembimbing mana pun');
$assert((int) $satu("SELECT COUNT(*) FROM izin_pengajuan WHERE id IN ($idNol, $idDua, $idTanpa) AND murobi_guru_id IS NULL") === 3, 'Murobi tidak menerima pengajuan ambigu');
$assert($svc()->akses($admin)['antrean_admin'] === 3 && $svc()->akses($pb1)['antrean_pembimbing'] === 1, 'Hitungan antrean akses admin/pembimbing konsisten dengan daftar');
$kandidat = $svc()->kandidatPembimbing($admin, $idDua);
$assert(array_column($kandidat, 'pengurus_id') === [$pengurusDua, $pengurusSatu] || array_column($kandidat, 'pengurus_id') === [$pengurusSatu, $pengurusDua], 'Kandidat santri Dua: Pembimbing Satu dan Dua');
$assert($svc()->kandidatPembimbing($admin, $idTanpa) === [] && $svc()->kandidatPembimbing($admin, $idNol) === [], 'Kandidat santri Nol/TanpaAkun kosong');
$assert($galat(fn () => $svc()->kandidatPembimbing($pb1, $idDua)) === 403, 'Kandidat pembimbing hanya untuk admin');
$assert($galat(fn () => $svc()->tetapkanPembimbing($admin, $idTanpa, $pengurusTanpaAkun, 'Coba pengurus tanpa akun', 1, 'v7-int-tetap-0001', $meta)) === 422
    && $galat(fn () => $svc()->tetapkanPembimbing($admin, $idNol, $pengurusSatu, 'Coba pembimbing kamar lain', 1, 'v7-int-tetap-0002', $meta)) === 422,
    'Admin tidak dapat menetapkan pengurus tanpa akun atau pembimbing di luar kamar/kelas santri (422)');
$assert($galat(fn () => $svc()->tetapkanPembimbing($pb1, $idDua, $pengurusSatu, 'Pembimbing menetapkan diri', 1, 'v7-int-tetap-0003', $meta)) === 403
    && $galat(fn () => $svc()->tetapkanPembimbing($wA, $idDua, $pengurusSatu, 'Wali menetapkan', 1, 'v7-int-tetap-0004', $meta)) === 403,
    'Pembimbing dan wali tidak dapat menetapkan pembimbing (403)');
$assert($galat(fn () => $svc()->tetapkanPembimbing($admin, $idDua, $pengurusDua, 'Versi lama', 7, 'v7-int-tetap-0005', $meta)) === 409
    && $galat(fn () => $svc()->tetapkanPembimbing($admin, $idDua, $pengurusDua, 'Tanpa versi', null, 'v7-int-tetap-0006', $meta)) === 422
    && $galat(fn () => $svc()->tetapkanPembimbing($admin, $idDua, $pengurusDua, '', 1, 'v7-int-tetap-0007', $meta)) === 422,
    'Penetapan: versi lama 409, tanpa versi 422, tanpa alasan 422');
$t = $svc()->tetapkanPembimbing($admin, $idDua, $pengurusDua, 'Pembimbing kelas lebih dekat dengan santri', 1, 'v7-int-tetap-0008', $meta);
$assert($t['status'] === 'Menunggu Pembimbing' && $t['version'] === 2 && $t['idempotent_replay'] === false, 'Admin menetapkan Pembimbing Dua → Menunggu Pembimbing, versi 2');
$t2 = $svc()->tetapkanPembimbing($admin, $idDua, $pengurusDua, 'Pembimbing kelas lebih dekat dengan santri', 1, 'v7-int-tetap-0008', $meta);
$assert($t2['idempotent_replay'] === true && $t2['version'] === 2 && (int) $satu("SELECT COUNT(*) FROM izin_pembimbing_penetapan WHERE pengajuan_id = $idDua") === 1, 'Replay penetapan → hasil sama, satu baris penetapan');
$assert($galat(fn () => $svc()->tetapkanPembimbing($admin, $idDua, $pengurusDua, 'Alasan lain', 1, 'v7-int-tetap-0008', $meta)) === 409, 'Kunci penetapan sama isi berbeda → 409');
$pen = $db->query("SELECT * FROM izin_pembimbing_penetapan WHERE pengajuan_id = $idDua")->fetch_assoc();
$assert($pen['pembimbing_sebelum_pengurus_id'] === null && (int) $pen['pembimbing_pengurus_id'] === $pengurusDua && (int) $pen['jumlah_kandidat'] === 2
    && (int) $pen['versi_sebelum'] === 1 && (int) $pen['versi_sesudah'] === 2 && $pen['status_sebelum'] === 'Perlu Penetapan Pembimbing' && (int) $pen['ditetapkan_oleh_user_id'] === $admin['id'],
    'Riwayat penetapan: sebelum/sesudah, kandidat, versi, pelaku');
$assert($svc()->antreanPembimbing($pb2)['total'] === 1 && $svc()->antreanPembimbing($pb1)['total'] === 1 && $svc()->daftarAdmin($admin, ['antrean' => '1'])['total'] === 2,
    'Sesudah penetapan: Pembimbing Dua melihat satu item; antrean admin berkurang');
$assert($galat(fn () => $svc()->tetapkanPembimbing($admin, $idDua, $pengurusDua, 'Ulang ke pembimbing sama', 2, 'v7-int-tetap-0009', $meta)) === 409, 'Menetapkan pembimbing yang sama lagi → 409');
// Admin membuat penugasan sah untuk kamar Nol lalu menetapkan.
$jalankan("INSERT INTO pembimbing_assignments (pengurus_id, tahun_ajaran_id, target_type, kamar_id, tanggal_mulai, is_active)
    SELECT $pengurusDua, pa.tahun_ajaran_id, 'Kamar', (SELECT id FROM kamar WHERE nama_kamar = 'SBX V7 Kamar Nol'), CURDATE(), 1 FROM pembimbing_assignments pa WHERE pa.pengurus_id = $pengurusDua LIMIT 1");
$assert(array_column($svc()->kandidatPembimbing($admin, $idNol), 'pengurus_id') === [$pengurusDua], 'Sesudah penugasan baru: kandidat santri Nol = Pembimbing Dua');
$t3 = $svc()->tetapkanPembimbing($admin, $idNol, $pengurusDua, 'Penugasan kamar Nol sudah dibuat', 1, 'v7-int-tetap-0010', $meta);
$assert($t3['version'] === 2 && $svc()->antreanPembimbing($pb2)['total'] === 2, 'Penetapan santri Nol berhasil; antrean Pembimbing Dua dua item');
$assert((int) $satu("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan = 'Wali' AND murobi_guru_id IS NOT NULL") === 0
    && (int) $satu("SELECT COUNT(*) FROM izin_keputusan k JOIN izin_pengajuan p ON p.id = k.pengajuan_id WHERE p.asal_pengajuan = 'Wali'") === 0,
    'Tetap nol murobi tujuan dan nol keputusan untuk pengajuan wali');

// ===================================================================== I-5
echo PHP_EOL . '=== I-5. Benturan tanggal lintas jalur ===' . PHP_EOL;
$pengurusV2 = ['roles' => ['pengurus']] + $pb1;
$assert($galat(fn () => izin_workflow_service()->create($pengurusV2, ['santri_id' => $sSatu, 'tgl_izin' => '2026-11-03', 'tgl_kembali' => '2026-11-04', 'alasan' => 'Pulang'], 'v7-int-v2-0001', $meta, 'pengurus')) === 409,
    'Pengurus V2 bertumpang tindih dengan pengajuan wali Menunggu Pembimbing → 409');
$assert($galat(fn () => izin_workflow_service()->create($pengurusV2, ['santri_id' => $sDua, 'tgl_izin' => '2026-11-06', 'tgl_kembali' => '2026-11-06', 'alasan' => 'Pulang'], 'v7-int-v2-0002', $meta, 'pengurus')) === 409,
    'Pengurus V2 bertumpang tindih dengan pengajuan wali yang ditetapkan admin → 409');
$v2 = izin_workflow_service()->create($pengurusV2, ['santri_id' => $sSatu, 'tgl_izin' => '2026-11-10', 'tgl_kembali' => '2026-11-11', 'alasan' => 'Pulang'], 'v7-int-v2-0003', $meta, 'pengurus');
$assert($v2['status'] === 'Diajukan' && (int) $v2['murobi_guru_id'] === (int) $murobiA['guru_id'] && (string) $satu("SELECT asal_pengajuan FROM izin_pengajuan WHERE id = {$v2['id']}") === 'Pengurus',
    'Jalur pengurus V2 tetap langsung ke murobi (asal Pengurus) tanpa tahap pembimbing');
$assert($galat(fn () => $svc()->buat($wA, $isi($sSatu, '2026-11-11', '2026-11-12'), 'v7-int-satu-0010', $meta)) === 409, 'Wali bertumpang tindih dengan pengajuan pengurus V2 → 409');
izin_workflow_service()->cancel($pengurusV2, (int) $v2['id'], 'Batal pulang', null, 'v7-int-v2-0004', $meta, 'pengurus');
$h5 = $svc()->buat($wA, $isi($sSatu, '2026-11-11', '2026-11-12'), 'v7-int-satu-0011', $meta);
$assert($h5['status_http'] === 201, 'Sesudah pengajuan pengurus dibatalkan, rentang terlepas dan wali dapat mengajukan');

// ===================================================================== I-6
echo PHP_EOL . '=== I-6. Konkurensi proses terpisah ===' . PHP_EOL;
$paralel = static function (array $tugas) use ($root, $dbName): array {
    $mulai = (int) floor(microtime(true) * 1000) + 1500;
    $proc = [];
    foreach ($tugas as $i => $t) {
        // Argumen ke-7 adalah waktu mulai; alasan opsional di belakangnya.
        $cmd = array_merge(['php', $root . '/tests/v7_phase1_worker.php'], array_map('strval', array_slice($t, 0, 6)), [(string) $mulai], array_map('strval', array_slice($t, 6)));
        $proc[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], $root, array_merge(getenv(), ['DB_NAME' => $dbName]));
    }
    $hasil = [];
    foreach ($proc as $i => $p) {
        $out = stream_get_contents($pipes[$i][1]);
        $err = stream_get_contents($pipes[$i][2]);
        proc_close($p);
        $hasil[$i] = json_decode(trim((string) $out), true) ?? ['ok' => false, 'http' => 0, 'pesan' => trim((string) $out . $err)];
    }

    return $hasil;
};
$n = $jumlahPengajuan();
$hasil = $paralel(array_fill(0, 8, ['wali', 'sbx_v7_wali_a', $sSatu, '2026-11-15', '2026-11-16', 'v7-int-klik-0001', 'Klik ganda bersamaan']));
$ids = array_unique(array_column(array_filter($hasil, static fn ($h) => $h['ok']), 'id'));
$assert(count($ids) === 1 && $jumlahPengajuan() === $n + 1 && count(array_filter($hasil, static fn ($h) => $h['ok'])) === 8,
    'Delapan proses kunci+isi sama bersamaan → delapan jawaban sukses, satu ID, satu baris (' . json_encode(array_count_values(array_map(static fn ($h) => $h['ok'] ? 'ok' : (string) $h['http'], $hasil))) . ')');
$n = $jumlahPengajuan();
$tugas = [];
for ($i = 0; $i < 4; $i++) {
    $tugas[] = ['wali', 'sbx_v7_wali_a', $sSatu, '2026-11-20', '2026-11-21', 'v7-int-balap-w' . $i];
    $tugas[] = ['pengurus', 'sbx_v7_pembimbing_satu', $sSatu, '2026-11-21', '2026-11-22', 'v7-int-balap-p' . $i];
}
$hasil = $paralel($tugas);
$sukses = array_filter($hasil, static fn ($h) => $h['ok']);
$lain = array_filter($hasil, static fn ($h) => !$h['ok']);
$assert(count($sukses) === 1 && $jumlahPengajuan() === $n + 1 && array_unique(array_column($lain, 'http')) === [409],
    'Empat wali + empat pengurus bersamaan pada rentang bertumpang tindih → tepat satu sukses, tujuh 409 (' . json_encode(array_values(array_map(static fn ($h) => $h['ok'] ? 'ok:' . $h['status'] : (string) $h['http'], $hasil))) . ')');
$n = $jumlahPengajuan();
$hasil = $paralel(array_map(static fn ($i) => ['wali', $i % 2 === 0 ? 'sbx_v7_wali_a' : 'sbx_v7_wali_b', $sSatu, '2026-11-25', '2026-11-25', 'v7-int-dua-wali-' . $i], range(0, 5)));
$assert(count(array_filter($hasil, static fn ($h) => $h['ok'])) === 1 && $jumlahPengajuan() === $n + 1, 'Dua wali berbeda mengajukan bersamaan untuk anak yang sama → satu pengajuan aktif');

// ===================================================================== I-7
echo PHP_EOL . '=== I-7. Pencabutan hak pada sesi lama ===' . PHP_EOL;
$relasiB = (int) $satu("SELECT id FROM santri_wali WHERE wali_id = (SELECT wali_id FROM users WHERE id = {$wB['id']}) AND santri_id = $sSatu");
$jalankan("UPDATE santri_wali SET archived_at = NOW() WHERE id = $relasiB");
$assert($svc()->daftarWali($wB)['total'] === 0 && $galat(fn () => $svc()->detail($wB, $id1)) === 404
    && $galat(fn () => $svc()->buat($wB, $isi($sSatu, '2026-12-10', '2026-12-10'), 'v7-int-b-0001', $meta)) === 404, 'Relasi wali kedua dicabut → daftar kosong, detail 404, buat 404');
$jalankan("UPDATE santri_wali SET archived_at = NULL WHERE id = $relasiB");
$paSatu = (int) $satu("SELECT id FROM pembimbing_assignments WHERE pengurus_id = $pengurusSatu AND kamar_id = (SELECT id FROM kamar WHERE nama_kamar = 'SBX V7 Kamar Satu')");
$jalankan("UPDATE pembimbing_assignments SET is_active = 0 WHERE id = $paSatu");
$adm = $svc()->daftarAdmin($admin, ['antrean' => '1']);
$itemSatu = array_values(array_filter($adm['items'], static fn ($r) => $r['id'] === $id1))[0] ?? null;
$assert($svc()->antreanPembimbing($pb1)['total'] === 0 && $galat(fn () => $svc()->detail($pb1, $id1)) === 404
    && $itemSatu !== null && $itemSatu['perlu_admin'] === true && $itemSatu['pembimbing']['sah'] === false,
    'Penugasan Pembimbing Satu dinonaktifkan → antrean/detail tertutup; pengajuan pindah ke antrean admin (pembimbing tidak sah)');
$assert($galat(fn () => $svc()->tetapkanPembimbing($admin, $id1, $pengurusSatu, 'Pembimbing lama', 1, 'v7-int-tetap-0011', $meta)) === 422, 'Admin tidak dapat menetapkan kembali pembimbing yang penugasannya nonaktif');
$jalankan("UPDATE pembimbing_assignments SET is_active = 1 WHERE id = $paSatu");
$assert($svc()->antreanPembimbing($pb1)['total'] >= 1, 'Penugasan dipulihkan → antrean kembali');
$jalankan("UPDATE users SET is_active = 0 WHERE id = {$wA['id']}");
$assert($galat(fn () => $svc()->buat($wA, $isi($sNol, '2026-12-12', '2026-12-12'), 'v7-int-a-nonaktif', $meta)) === 403 && $galat(fn () => $svc()->detail($wA, $id1)) === 404,
    'Akun wali dinonaktifkan → buat 403, detail 404');
$jalankan("UPDATE users SET is_active = 1 WHERE id = {$wA['id']}");
$jalankan("UPDATE users SET is_active = 0 WHERE id = {$pb2['id']}");
$assert($galat(fn () => $svc()->antreanPembimbing($pb2)) === 403 && $svc()->daftarAdmin($admin, ['antrean' => '1'])['total'] >= 2,
    'Akun Pembimbing Dua dinonaktifkan → antrean 403; pengajuannya kembali tampil sebagai pengecualian admin');
$jalankan("UPDATE users SET is_active = 1 WHERE id = {$pb2['id']}");

// ===================================================================== I-8
echo PHP_EOL . '=== I-8. Validasi dan santri nonaktif ===' . PHP_EOL;
$n = $jumlahPengajuan();
$assert($galat(fn () => $svc()->buat($wA, $isi($sNonaktif, '2026-12-01', '2026-12-01'), 'v7-int-nonaktif-01', $meta)) === 422
    && !in_array($sNonaktif, array_column($svc()->anak($wA), 'id'), true), 'Santri nonaktif tidak dapat diajukan (422) dan tidak ada di pilihan anak');
foreach ([
    'tanggal tidak valid' => $isi($sNol, '2026-02-30', '2026-03-01'),
    'kembali sebelum izin' => $isi($sNol, '2026-12-05', '2026-12-04'),
    'alasan terlalu pendek' => $isi($sNol, '2026-12-05', '2026-12-05', 'ok'),
    'santri bukan angka' => ['santri_id' => 'abc', 'tgl_izin' => '2026-12-05', 'tgl_kembali' => '2026-12-05', 'alasan' => 'Acara keluarga'],
    'alasan bukan teks' => ['santri_id' => $sNol, 'tgl_izin' => '2026-12-05', 'tgl_kembali' => '2026-12-05', 'alasan' => ['x']],
    'catatan terlalu panjang' => $isi($sNol, '2026-12-05', '2026-12-05', 'Acara keluarga', str_repeat('a', 1001)),
] as $label => $masukan) {
    $assert($galat(fn () => $svc()->buat($wA, $masukan, 'v7-int-val-' . md5($label), $meta)) === 422, 'Validasi: ' . $label . ' → 422');
}
$assert($jumlahPengajuan() === $n, 'Masukan tidak valid tidak membuat baris');

// ===================================================================== I-9
echo PHP_EOL . '=== I-9. CHECK basis data dan jalur V2 ===' . PHP_EOL;
$santriBebas = $sTanpaAkun;
$coba = static function (string $sql) use ($db): bool {
    try {
        return $db->query($sql) !== false;
    } catch (mysqli_sql_exception) {
        return false;
    }
};
$assert(!$coba("INSERT INTO izin_pengajuan (asal_pengajuan, santri_id, tgl_izin, tgl_kembali, alasan, status) VALUES ('Pengurus', $santriBebas, '2027-01-01', '2027-01-01', 'Uji CHECK', 'Menunggu Pembimbing')"),
    'CHECK: baris pengurus tidak dapat berstatus tahap V7');
$assert(!$coba("INSERT INTO izin_pengajuan (asal_pengajuan, santri_id, tgl_izin, tgl_kembali, alasan, status) VALUES ('Wali', $santriBebas, '2027-01-01', '2027-01-01', 'Uji CHECK', 'Menunggu Pembimbing')"),
    'CHECK: baris wali wajib wali_id');
$assert(!$coba("UPDATE izin_pengajuan SET pengurus_id = $pengurusSatu WHERE id = $id1"), 'CHECK: baris wali tidak dapat memiliki pengurus pengaju');
$adminV2 = ['roles' => ['admin']] + $admin;
$murobiV2 = ['roles' => ['guru']] + $murobiA;
$versi1 = (int) $satu("SELECT version FROM izin_pengajuan WHERE id = $id1");
$assert($galat(fn () => izin_workflow_service()->decide($adminV2, $id1, 'Disetujui', 'Admin memutus dini', 'Murobi berhalangan', null, 'v7-int-v2-dec-01', $meta, 'admin')) === 409
    && $galat(fn () => izin_workflow_service()->decide($murobiV2, $id1, 'Disetujui', 'Murobi memutus dini', null, null, 'v7-int-v2-dec-02', $meta, 'murobi')) === 409
    && $galat(fn () => izin_workflow_service()->assignMurobi($adminV2, $id1, (int) $murobiA['guru_id'], 'Tetapkan dini', null, 'v7-int-v2-asg-01', $meta)) === 409
    && $galat(fn () => izin_workflow_service()->cancel($adminV2, $id1, 'Batal dini', null, 'v7-int-v2-can-01', $meta, 'admin')) === 409,
    'Jalur V2 (keputusan admin/murobi, penetapan murobi, pembatalan) menolak pengajuan di tahap pembimbing (409)');
$assert((int) $satu("SELECT version FROM izin_pengajuan WHERE id = $id1") === $versi1 && (int) $satu("SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id = $id1") === 0,
    'Penolakan V2 tanpa perubahan versi dan tanpa keputusan');

// ===================================================================== I-10
echo PHP_EOL . '=== I-10. Verifier dan preservasi V2 ===' . PHP_EOL;
$v2Sesudah = (string) $satu("SELECT SHA2(GROUP_CONCAT(CONCAT_WS(':', id, status, version) ORDER BY id), 256) FROM izin_pengajuan WHERE id <= (SELECT MAX(id) FROM izin_pengajuan WHERE id < $id1)");
$assert($v2Sesudah === $v2Sebelum, 'Baris pengajuan V2/warisan yang ada sebelum uji tidak berubah (ID, status, versi)');
exec('DB_NAME=' . escapeshellarg($dbName) . ' php ' . escapeshellarg($root . '/bin/v7_phase1_verify.php') . ' 2>&1', $out, $kode);
$assert($kode === 0, 'bin/v7_phase1_verify.php lulus sesudah seluruh uji (' . trim((string) end($out)) . ')');
if ($kode !== 0) {
    echo implode(PHP_EOL, array_filter($out, static fn ($l) => str_starts_with($l, '[gagal]'))) . PHP_EOL;
}

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
