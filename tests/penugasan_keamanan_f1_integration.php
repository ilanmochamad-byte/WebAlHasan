<?php

declare(strict_types=1);

/**
 * Uji integrasi Fase 1 penugasan lintas guru/pengurus — identitas pengurus
 * milik guru (PK-1…PK-4) di atas DB uji `tests/penugasan_keamanan_f1_db.sh`.
 *
 *   bash tests/penugasan_keamanan_f1_db.sh && php tests/penugasan_keamanan_f1_integration.php
 *
 *   I-1  katalog: Murobi/Guru Mapel tetap khusus guru; jenis berbasis pengurus menawarkan guru
 *   I-2  guru berakun ditugaskan sebagai pembimbing: identitas + akun + role + capability + audit
 *   I-3  penugasan kedua memakai ulang identitas (nilai guru:ID maupun ID identitas)
 *   I-4  guru tanpa akun: identitas dibentuk; akun guru yang dibuat kemudian langsung terhubung
 *   I-5  status mengikuti guru: nonaktif/arsip/aktif ulang menggugurkan/memulihkan hak
 *   I-6  pertahanan berlapis: identitas aktif tetapi guru nonaktif tetap tanpa hak
 *   I-7  Data Pengurus menolak mengubah identitas; perubahan nama guru tersinkron
 *   I-8  aturan akun: identitas tidak dapat dibuatkan akun lain/dipinjam; akun warisan tetap
 *   I-9  penolakan: guru nonaktif/tidak ada, nilai guru pada murobi, non-admin, akun ganda warisan
 *   I-10 konkurensi proses terpisah: dua penugasan bersamaan untuk guru yang sama
 *   I-11 pengubahan penugasan identitas; pengurus biasa tidak berubah
 *   I-12 peringatan nama sama: guru bernama sama dengan pengurus biasa aktif ditandai, tanpa dikunci
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$dbName = getenv('PKF1_DB') ?: 'test_pk_f1_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once $root . '/app/bootstrap.php';

use App\Auth\Capabilities;
use App\MasterData\MasterDataException;
use App\Penugasan\PenugasanException;
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

    return $r->fetch_row()[0] ?? null;
};
$jalankan = static function (string $sql) use ($db): void {
    if ($db->query($sql) === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
};
$akun = static function (int $userId) use ($satu): array {
    $roles = (string) $satu("SELECT GROUP_CONCAT(r.slug ORDER BY r.slug) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = $userId");
    $guru = $satu("SELECT guru_id FROM users WHERE id = $userId");

    return ['id' => $userId, 'roles' => $roles === '' ? [] : explode(',', $roles), 'guru_id' => $guru === null ? null : (int) $guru];
};
$caps = capabilities();
$fitur = static function (int $userId) use ($caps, $akun): array {
    $caps->forget($userId);

    return $caps->featureList($akun($userId));
};
$izinCaps = static function (int $userId) use ($caps, $akun): array {
    $caps->forget($userId);

    return $caps->forUser($akun($userId));
};
$galat = static function (callable $f): ?Throwable {
    try {
        $f();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
};
$audit = static fn (string $aksi, string $entitas, int $id): int => (int) $satu(
    "SELECT COUNT(*) FROM audit_logs WHERE action = '$aksi' AND entity_type = '$entitas' AND entity_id = $id"
);

$svc = penugasan_service();
$master = master_data_service();
$admin = (int) $satu("SELECT id FROM users WHERE username = 'sbx_admin'");
$tahun = (int) $satu("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1");
$kamar = (int) $satu('SELECT id FROM kamar ORDER BY id LIMIT 1');
$hari = date('Y-m-d');
$pembimbing = static fn (string $subjek, array $tambah = []): array => ['pengurus_id' => $subjek, 'tahun_ajaran_id' => $tahun, 'target_type' => 'Kamar', 'kamar_id' => $kamar, 'tanggal_mulai' => $hari] + $tambah;
$umum = static fn (string $subjek): array => ['pengurus_id' => $subjek, 'tahun_ajaran_id' => $tahun, 'tanggal_mulai' => $hari];

$guruBerakun = (int) $satu("SELECT guru_id FROM users WHERE username = 'sbx_guru_biasa'");
$userGuru = (int) $satu("SELECT id FROM users WHERE username = 'sbx_guru_biasa'");
$guruTanpaAkun = (int) $satu("SELECT g.id FROM guru g LEFT JOIN users u ON u.guru_id = g.id WHERE u.id IS NULL AND g.is_active = 1 AND g.archived_at IS NULL ORDER BY g.id LIMIT 1");
$guruTanpaAkun2 = (int) $satu("SELECT g.id FROM guru g LEFT JOIN users u ON u.guru_id = g.id WHERE u.id IS NULL AND g.is_active = 1 AND g.archived_at IS NULL AND g.id <> $guruTanpaAkun ORDER BY g.id LIMIT 1");
$userGanda = (int) $satu("SELECT id FROM users WHERE username = 'sbx_v5_ganda'");
$guruGanda = (int) $satu("SELECT guru_id FROM users WHERE id = $userGanda");
$pengurusGanda = (int) $satu("SELECT pengurus_id FROM users WHERE id = $userGanda");
$userPengurusA = (int) $satu("SELECT id FROM users WHERE username = 'sbx_pengurus_a'");
$pengurusAAsal = (int) $satu("SELECT pengurus_id FROM users WHERE id = $userPengurusA");
$assert($admin > 0 && $tahun > 0 && $kamar > 0 && $guruBerakun > 0 && $guruTanpaAkun > 0 && $guruTanpaAkun2 > 0 && $guruGanda > 0 && $pengurusGanda > 0,
    'fixture tersedia: admin, tahun aktif, kamar, guru berakun/tanpa akun, akun ganda warisan');
$assert((int) $satu("SELECT COUNT(*) FROM pengurus WHERE guru_id IS NOT NULL") === 0, 'awal: belum ada identitas pengurus milik guru');
$fiturPengurusAAwal = $fitur($userPengurusA);
$fiturGandaAwal = $fitur($userGanda);

// --- I-1 katalog ------------------------------------------------------------
foreach ([PenugasanJenis::MUROBI, PenugasanJenis::GURU_MAPEL] as $j) {
    $o = $svc->opsi($j);
    $assert(PenugasanJenis::definisi($j)['subjek'] === 'guru' && $o['subjek_guru'] === [], "I-1 $j tetap khusus guru (tanpa kelompok guru tambahan)");
}
foreach ([PenugasanJenis::PEMBIMBING, PenugasanJenis::PENDIDIKAN, PenugasanJenis::KESEHATAN, PenugasanJenis::BENDAHARA_BULANAN, PenugasanJenis::PANITIA_PSB, PenugasanJenis::BENDAHARA_PSB] as $j) {
    $o = $svc->opsi($j);
    $ids = array_map(static fn (array $g): int => (int) $g['id'], $o['subjek_guru']);
    $assert(in_array($guruBerakun, $ids, true) && count($o['subjek_guru']) === (int) $satu('SELECT COUNT(*) FROM guru WHERE is_active = 1 AND archived_at IS NULL'),
        "I-1 $j menawarkan seluruh guru aktif di samping pengurus");
}

// --- I-2 guru berakun → pembimbing --------------------------------------------
$assert(!in_array(PenugasanJenis::CAP_PEMBIMBING_BINAAN, $fitur($userGuru), true) && !in_array(Capabilities::PENGURUS, $izinCaps($userGuru), true),
    'I-2 sebelum: guru belum memegang hak pembimbing maupun pengurus');
$idPembimbing = $svc->buat(PenugasanJenis::PEMBIMBING, $pembimbing('guru:' . $guruBerakun), $admin);
$identitas = $db->query("SELECT * FROM pengurus WHERE guru_id = $guruBerakun")->fetch_assoc();
$namaGuru = (string) $satu("SELECT nama_guru FROM guru WHERE id = $guruBerakun");
$assert($idPembimbing > 0 && $identitas !== null && $identitas['jabatan'] === 'Guru' && $identitas['nama'] === $namaGuru && $identitas['nomor_identitas'] === null && (int) $identitas['is_active'] === 1,
    'I-2 identitas pengurus terbentuk dari data guru (nama sama, jabatan Guru, tanpa nomor identitas)');
$identitasId = (int) $identitas['id'];
$assert((int) $satu("SELECT pengurus_id FROM pembimbing_assignments WHERE id = $idPembimbing") === $identitasId, 'I-2 penugasan merujuk identitas pengurus guru');
$assert((int) $satu("SELECT pengurus_id FROM users WHERE id = $userGuru") === $identitasId && in_array('pengurus', $akun($userGuru)['roles'], true) && in_array('guru', $akun($userGuru)['roles'], true),
    'I-2 akun guru yang sama terhubung ke identitas dan memegang role guru + pengurus');
$assert(in_array(PenugasanJenis::CAP_PEMBIMBING_BINAAN, $fitur($userGuru), true) && in_array(Capabilities::PENGURUS, $izinCaps($userGuru), true),
    'I-2 sesudah: hak pembimbing dan capability pengurus efektif');
$assert($audit('penugasan.identitas_guru.buat', 'pengurus', $identitasId) === 1 && $audit('account_identitas_guru_linked', 'user', $userGuru) === 1 && $audit('penugasan.buat', 'pembimbing_assignment', $idPembimbing) === 1,
    'I-2 audit: identitas dibuat, akun dihubungkan, penugasan dibuat');
$ef = $svc->capabilityEfektif(PenugasanJenis::PEMBIMBING, $identitasId);
$assert(($ef['akun']['id'] ?? 0) === $userGuru && in_array(PenugasanJenis::CAP_PEMBIMBING_BINAAN, $ef['capabilities'], true), 'I-2 pusat penugasan menampilkan akun guru dan hak efektif');
$daftar = $svc->daftar(PenugasanJenis::PEMBIMBING, ['q' => $namaGuru, 'tahun_ajaran_id' => 0, 'status' => '', 'kelas_id' => 0, 'kamar_id' => 0, 'mata_pelajaran_id' => 0, 'jenjang' => '', 'gelombang' => ''], 1);
$assert(in_array($idPembimbing, array_map(static fn (array $r): int => (int) $r['id'], $daftar['rows']), true), 'I-2 penugasan muncul di daftar pembimbing dengan nama guru');
$opsiSesudah = array_map(static fn (array $r): int => (int) $r['id'], $svc->opsi(PenugasanJenis::PEMBIMBING)['subjek']);
$assert(!in_array($identitasId, $opsiSesudah, true), 'I-2 identitas tidak muncul ganda di kelompok Pengurus');

// --- I-3 pemakaian ulang identitas ---------------------------------------------
$jumlahPengurus = (int) $satu('SELECT COUNT(*) FROM pengurus');
$idKesehatan = $svc->buat(PenugasanJenis::KESEHATAN, $umum('guru:' . $guruBerakun), $admin);
$idBsa = $svc->buat(PenugasanJenis::BENDAHARA_BULANAN, $umum((string) $identitasId), $admin);
$assert((int) $satu('SELECT COUNT(*) FROM pengurus') === $jumlahPengurus
    && (int) $satu("SELECT pengurus_id FROM kesehatan_assignments WHERE id = $idKesehatan") === $identitasId
    && (int) $satu("SELECT pengurus_id FROM bendahara_bulanan_assignments WHERE id = $idBsa") === $identitasId,
    'I-3 penugasan kedua/ketiga memakai identitas yang sama (nilai guru:ID dan ID identitas)');
$assert($audit('account_identitas_guru_linked', 'user', $userGuru) === 1 && $audit('penugasan.identitas_guru.buat', 'pengurus', $identitasId) === 1,
    'I-3 tidak ada pembentukan/penghubungan ulang yang diaudit dua kali');
$f = $fitur($userGuru);
$assert(in_array(PenugasanJenis::CAP_KESEHATAN_CATAT, $f, true) && in_array(PenugasanJenis::CAP_BSA_TABUNGAN, $f, true), 'I-3 hak kesehatan dan BSA efektif pada akun guru');

// --- I-4 guru tanpa akun -------------------------------------------------------
$idPendidikan = $svc->buat(PenugasanJenis::PENDIDIKAN, $umum('guru:' . $guruTanpaAkun), $admin);
$identitas2 = (int) $satu("SELECT id FROM pengurus WHERE guru_id = $guruTanpaAkun");
$assert($idPendidikan > 0 && $identitas2 > 0 && (int) $satu("SELECT COUNT(*) FROM users WHERE pengurus_id = $identitas2") === 0,
    'I-4 guru tanpa akun: identitas terbentuk, tidak ada akun yang dibuat');
$ef = $svc->capabilityEfektif(PenugasanJenis::PENDIDIKAN, $identitas2);
$assert($ef['akun'] === null && str_contains((string) $ef['pesan'], 'belum mempunyai akun'), 'I-4 pusat penugasan menjelaskan hak belum efektif karena belum ada akun');
$baru = account_service()->createTeacher(['guru_id' => $guruTanpaAkun, 'name' => 'Guru Identitas PK', 'username' => 'pkf1.guru' . $guruTanpaAkun], $admin);
$userBaru = (int) $baru['id'];
$assert((int) $satu("SELECT pengurus_id FROM users WHERE id = $userBaru") === $identitas2 && $akun($userBaru)['roles'] === ['guru', 'pengurus'],
    'I-4 akun guru yang dibuat kemudian langsung terhubung ke identitas dengan role guru + pengurus');
$assert(in_array(PenugasanJenis::CAP_RAPOR_VERIFIKASI, $fitur($userBaru), true) && $audit('account_identitas_guru_linked', 'user', $userBaru) === 1,
    'I-4 hak Bagian Pendidikan efektif dan penghubungan diaudit');

// --- I-5 status mengikuti guru -------------------------------------------------
$master->setGuruState($guruBerakun, 'deactivate');
$assert((int) $satu("SELECT is_active FROM pengurus WHERE id = $identitasId") === 0, 'I-5 menonaktifkan guru menonaktifkan identitasnya di transaksi yang sama');
$f = $fitur($userGuru);
$assert(!in_array(PenugasanJenis::CAP_PEMBIMBING_BINAAN, $f, true) && !in_array(PenugasanJenis::CAP_KESEHATAN_CATAT, $f, true) && !in_array(Capabilities::PENGURUS, $izinCaps($userGuru), true),
    'I-5 guru nonaktif: seluruh hak berbasis pengurus gugur');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'master.identitas_guru.sinkron' AND entity_id = $identitasId") >= 1, 'I-5 sinkronisasi identitas diaudit');
$master->setGuruState($guruBerakun, 'activate');
$assert((int) $satu("SELECT is_active FROM pengurus WHERE id = $identitasId") === 1 && in_array(PenugasanJenis::CAP_PEMBIMBING_BINAAN, $fitur($userGuru), true),
    'I-5 mengaktifkan guru memulihkan identitas dan hak');
$master->setGuruState($guruBerakun, 'archive');
$assert($satu("SELECT archived_at FROM pengurus WHERE id = $identitasId") !== null && !in_array(PenugasanJenis::CAP_BSA_TABUNGAN, $fitur($userGuru), true),
    'I-5 mengarsipkan guru mengarsipkan identitas dan menggugurkan hak');
$master->setGuruState($guruBerakun, 'restore');
$assert($satu("SELECT archived_at FROM pengurus WHERE id = $identitasId") === null && in_array(PenugasanJenis::CAP_BSA_TABUNGAN, $fitur($userGuru), true),
    'I-5 memulihkan guru memulihkan identitas dan hak');

// --- I-6 pertahanan berlapis ---------------------------------------------------
$jalankan("UPDATE guru SET is_active = 0 WHERE id = $guruBerakun");
$assert((int) $satu("SELECT is_active FROM pengurus WHERE id = $identitasId") === 1, 'I-6 penyiapan: identitas masih aktif (sinkronisasi terlewat)');
$assert(!in_array(PenugasanJenis::CAP_PEMBIMBING_BINAAN, $fitur($userGuru), true) && !in_array(Capabilities::PENGURUS, $izinCaps($userGuru), true),
    'I-6 Capabilities tetap menolak hak saat data guru nonaktif');
$jalankan("UPDATE guru SET is_active = 1 WHERE id = $guruBerakun");
$jalankan("UPDATE users SET pengurus_id = NULL WHERE id = $userGuru");
$jalankan("UPDATE users SET pengurus_id = $identitasId WHERE id = $userPengurusA");
$assert(!in_array(Capabilities::PENGURUS, $izinCaps($userPengurusA), true), 'I-6 identitas guru yang dipasang ke akun lain tidak memberi hak pengurus');
$jalankan("UPDATE users SET pengurus_id = $pengurusAAsal WHERE id = $userPengurusA");
$jalankan("UPDATE users SET pengurus_id = $identitasId WHERE id = $userGuru");
$assert($fitur($userPengurusA) === $fiturPengurusAAwal && in_array(PenugasanJenis::CAP_PEMBIMBING_BINAAN, $fitur($userGuru), true), 'I-6 keadaan dipulihkan');

// --- I-7 Data Pengurus dan sinkron nama ------------------------------------------
$e = $galat(static fn () => $master->savePengurus(['nama' => 'Diubah', 'jabatan' => 'Keamanan'], $identitasId));
$assert($e instanceof MasterDataException && str_contains($e->getMessage(), 'Data Guru') && (int) $satu("SELECT COUNT(*) FROM pengurus WHERE id = $identitasId AND nama = 'Diubah'") === 0,
    'I-7 Data Pengurus menolak mengubah identitas milik guru');
$e = $galat(static fn () => $master->setPengurusState($identitasId, 'deactivate'));
$assert($e instanceof MasterDataException && (int) $satu("SELECT is_active FROM pengurus WHERE id = $identitasId") === 1, 'I-7 Data Pengurus menolak menonaktifkan identitas milik guru');
$guruRow = $master->guru($guruBerakun);
$master->saveGuru(['nip' => (string) $guruRow['nip'], 'nama_guru' => 'Guru Sinkron PK', 'no_hp' => '081234560001'], $guruBerakun);
$assert($satu("SELECT nama FROM pengurus WHERE id = $identitasId") === 'Guru Sinkron PK' && $satu("SELECT no_hp FROM pengurus WHERE id = $identitasId") === '081234560001',
    'I-7 nama dan nomor HP guru tersinkron ke identitas');
$master->saveGuru(['nip' => (string) $guruRow['nip'], 'nama_guru' => (string) $guruRow['nama_guru'], 'no_hp' => (string) ($guruRow['no_hp'] ?? '')], $guruBerakun);
$assert((int) ($master->identitasGuru($guruBerakun)['id'] ?? 0) === $identitasId, 'I-7 panel guru menemukan identitas pengurusnya');
$pengurusBiasa = (int) $satu("SELECT id FROM pengurus WHERE guru_id IS NULL AND is_active = 1 AND archived_at IS NULL ORDER BY id LIMIT 1");
$namaBiasa = (string) $satu("SELECT nama FROM pengurus WHERE id = $pengurusBiasa");
$jabatanBiasa = (string) $satu("SELECT jabatan FROM pengurus WHERE id = $pengurusBiasa");
$master->savePengurus(['nama' => $namaBiasa, 'jabatan' => $jabatanBiasa, 'nomor_identitas' => (string) $satu("SELECT nomor_identitas FROM pengurus WHERE id = $pengurusBiasa"), 'no_hp' => (string) $satu("SELECT no_hp FROM pengurus WHERE id = $pengurusBiasa")], $pengurusBiasa);
$assert(true, 'I-7 pengurus biasa tetap dapat disimpan dari Data Pengurus');

// --- I-8 aturan akun ------------------------------------------------------------
$tersedia = array_map(static fn (array $r): int => (int) $r['id'], perizinan_account_service()->availablePengurus());
$assert(!in_array($identitasId, $tersedia, true) && !in_array($identitas2, $tersedia, true), 'I-8 identitas guru tidak ditawarkan untuk akun pengurus terpisah');
$e = $galat(static fn () => perizinan_account_service()->create('pengurus', ['name' => 'Pinjam', 'username' => 'pkf1.pinjam', 'pengurus_id' => $identitasId], $admin));
$assert($e instanceof InvalidArgumentException && (int) $satu("SELECT COUNT(*) FROM users WHERE username = 'pkf1.pinjam'") === 0, 'I-8 membuat akun pengurus untuk identitas guru ditolak server');
$e = $galat(static fn () => perizinan_account_service()->link('pengurus', $userGuru, $pengurusBiasa, $admin));
$assert($e instanceof InvalidArgumentException && (int) $satu("SELECT pengurus_id FROM users WHERE id = $userGuru") === $identitasId, 'I-8 akun guru tetap tidak dapat dihubungkan ke pengurus biasa');
$jalankan("DELETE ur FROM user_roles ur JOIN roles r ON r.id = ur.role_id AND r.slug = 'pengurus' WHERE ur.user_id = $userPengurusA");
$jalankan("UPDATE users SET pengurus_id = NULL WHERE id = $userBaru");
$jalankan("UPDATE users SET pengurus_id = $identitas2 WHERE id = $userPengurusA");
$e = $galat(static fn () => account_service()->grantRole($userPengurusA, 'pengurus', $admin));
$assert($e instanceof InvalidArgumentException && !in_array('pengurus', $akun($userPengurusA)['roles'], true), 'I-8 role Pengurus ditolak untuk akun yang memegang identitas guru lain');
$jalankan("UPDATE users SET pengurus_id = $pengurusAAsal WHERE id = $userPengurusA");
$jalankan("UPDATE users SET pengurus_id = $identitas2 WHERE id = $userBaru");
account_service()->grantRole($userPengurusA, 'pengurus', $admin);
$assert($fitur($userPengurusA) === $fiturPengurusAAwal, 'I-8 akun pengurus biasa dipulihkan dengan hak semula');
$assert(account_service()->blockerFor('pengurus', account_service()->find($userGanda)) === null && $fitur($userGanda) === $fiturGandaAwal,
    'I-8 akun ganda warisan (guru + pengurus biasa) tidak berubah aturan maupun haknya');
account_service()->revokeRole($userGuru, 'pengurus', $admin);
$assert(!in_array(PenugasanJenis::CAP_PEMBIMBING_BINAAN, $fitur($userGuru), true), 'I-8 mencabut role Pengurus dari akun guru menggugurkan hak berbasis pengurus');
account_service()->grantRole($userGuru, 'pengurus', $admin);
$assert(in_array(PenugasanJenis::CAP_PEMBIMBING_BINAAN, $fitur($userGuru), true), 'I-8 role Pengurus dapat diberikan lagi pada akun guru pemilik identitas');

// --- I-9 penolakan ---------------------------------------------------------------
$sebelum = (int) $satu('SELECT COUNT(*) FROM pengurus') . '/' . (int) $satu('SELECT COUNT(*) FROM pembimbing_assignments') . '/' . (int) $satu('SELECT COUNT(*) FROM murobi_assignments');
$master->setGuruState($guruTanpaAkun2, 'deactivate');
$e = $galat(static fn () => $svc->buat(PenugasanJenis::PEMBIMBING, $pembimbing('guru:' . $guruTanpaAkun2), $admin));
$assert($e instanceof PenugasanException && isset($e->errors()['pengurus_id']), 'I-9 guru nonaktif ditolak');
$master->setGuruState($guruTanpaAkun2, 'activate');
$e = $galat(static fn () => $svc->buat(PenugasanJenis::PEMBIMBING, $pembimbing('guru:99999999'), $admin));
$assert($e instanceof PenugasanException, 'I-9 guru yang tidak ada ditolak');
$e = $galat(static fn () => $svc->buat(PenugasanJenis::PEMBIMBING, $pembimbing('guru:abc'), $admin));
$assert($e instanceof PenugasanException, 'I-9 nilai subjek tidak sah ditolak');
$e = $galat(static fn () => $svc->buat(PenugasanJenis::MUROBI, ['guru_id' => 'guru:' . $guruBerakun, 'tahun_ajaran_id' => $tahun, 'target_type' => 'Kamar', 'kamar_id' => $kamar, 'tanggal_mulai' => $hari], $admin));
$assert($e instanceof PenugasanException, 'I-9 Murobi tidak menerima nilai berbentuk guru:ID (jalur identitas khusus jenis pengurus)');
$e = $galat(static fn () => $svc->buat(PenugasanJenis::PEMBIMBING, $pembimbing('guru:' . $guruTanpaAkun2), $userGuru));
$assert($e instanceof PenugasanException && $e->status() === 403 && (int) $satu("SELECT COUNT(*) FROM pengurus WHERE guru_id = $guruTanpaAkun2") === 0, 'I-9 non-admin ditolak tanpa membentuk identitas');
$e = $galat(static fn () => $svc->buat(PenugasanJenis::PEMBIMBING, $pembimbing('guru:' . $guruGanda), $admin));
$assert($e instanceof PenugasanException && str_contains($e->getMessage(), 'sudah terhubung') && (int) $satu("SELECT COUNT(*) FROM pengurus WHERE guru_id = $guruGanda") === 0
    && (int) $satu("SELECT pengurus_id FROM users WHERE id = $userGanda") === $pengurusGanda,
    'I-9 akun ganda warisan: memilih gurunya ditolak, identitas dibatalkan bersama transaksi, tautan lama utuh');
$sesudah = (int) $satu('SELECT COUNT(*) FROM pengurus') . '/' . (int) $satu('SELECT COUNT(*) FROM pembimbing_assignments') . '/' . (int) $satu('SELECT COUNT(*) FROM murobi_assignments');
$assert($sebelum === $sesudah, 'I-9 seluruh penolakan tanpa baris pengurus/penugasan baru');

// --- I-10 konkurensi -------------------------------------------------------------
$guruBalap = $guruTanpaAkun2;
$mulai = (int) (microtime(true) * 1e6) + 1500000;
$proses = [];
$pipaSemua = [];
foreach ([PenugasanJenis::PEMBIMBING, PenugasanJenis::KESEHATAN] as $j) {
    $proses[] = proc_open(['php', $root . '/tests/penugasan_keamanan_f1_worker.php', $dbName, $j, (string) $guruBalap, (string) $admin, (string) $mulai],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipa);
    $pipaSemua[] = $pipa;
}
$hasil = [];
foreach ($proses as $i => $p) {
    $hasil[] = json_decode((string) stream_get_contents($pipaSemua[$i][1]), true);
    proc_close($p);
}
$jumlahIdentitas = (int) $satu("SELECT COUNT(*) FROM pengurus WHERE guru_id = $guruBalap");
$identitasBalap = (int) $satu("SELECT id FROM pengurus WHERE guru_id = $guruBalap");
$assert(($hasil[0]['ok'] ?? false) && ($hasil[1]['ok'] ?? false), 'I-10 dua penugasan bersamaan untuk guru yang sama keduanya berhasil (' . json_encode($hasil) . ')');
$assert($jumlahIdentitas === 1
    && (int) $satu("SELECT COUNT(*) FROM pembimbing_assignments WHERE pengurus_id = $identitasBalap") === 1
    && (int) $satu("SELECT COUNT(*) FROM kesehatan_assignments WHERE pengurus_id = $identitasBalap") === 1
    && $audit('penugasan.identitas_guru.buat', 'pengurus', $identitasBalap) === 1,
    'I-10 tepat satu identitas dan satu audit pembentukan; kedua penugasan merujuknya');

// --- I-11 pengubahan dan pengurus biasa --------------------------------------------
$svc->ubah(PenugasanJenis::PEMBIMBING, $idPembimbing, ['target_type' => 'Kamar', 'kamar_id' => $kamar, 'tanggal_mulai' => $hari, 'catatan' => 'Diubah PK', 'alasan' => 'Uji pengubahan identitas'], $admin);
$assert($satu("SELECT catatan FROM pembimbing_assignments WHERE id = $idPembimbing") === 'Diubah PK' && (int) $satu("SELECT pengurus_id FROM pembimbing_assignments WHERE id = $idPembimbing") === $identitasId,
    'I-11 penugasan milik identitas guru dapat diubah tanpa mengganti subjek');
$idBiasa = $svc->buat(PenugasanJenis::KESEHATAN, $umum((string) $pengurusBiasa), $admin);
$assert((int) $satu("SELECT pengurus_id FROM kesehatan_assignments WHERE id = $idBiasa") === $pengurusBiasa && (int) $satu("SELECT COUNT(*) FROM pengurus WHERE guru_id IS NOT NULL") === 3,
    'I-11 pengurus biasa ditugaskan seperti sebelumnya tanpa identitas baru');
$assert($fitur($userGanda) === $fiturGandaAwal, 'I-11 hak akun ganda warisan tetap sama sampai akhir');
$assert((int) $satu('SELECT COUNT(*) FROM pengurus p JOIN guru g ON g.id = p.guru_id WHERE p.nama <> g.nama_guru OR p.is_active <> g.is_active OR NOT (p.archived_at <=> g.archived_at)') === 0,
    'I-11 akhir: seluruh identitas sinkron dengan data gurunya');
$assert((int) $satu('SELECT COUNT(*) FROM users u JOIN pengurus p ON p.id = u.pengurus_id WHERE p.guru_id IS NOT NULL AND NOT (u.guru_id <=> p.guru_id)') === 0,
    'I-11 akhir: tidak ada akun yang memegang identitas guru lain');

// --- I-12 peringatan nama sama -------------------------------------------------------
$tanda = static function (int $guruId) use ($svc): ?bool {
    foreach ($svc->opsi(PenugasanJenis::KESEHATAN)['subjek_guru'] as $g) {
        if ((int) $g['id'] === $guruId) {
            return $g['nama_sama_pengurus'];
        }
    }

    return null;
};
// Guru tanpa akun dan tanpa identitas yang belum bernama sama dengan pengurus mana pun.
$guruKembar = 0;
foreach ($db->query('SELECT g.id FROM guru g LEFT JOIN pengurus p ON p.guru_id = g.id LEFT JOIN users u ON u.guru_id = g.id
                      WHERE p.id IS NULL AND u.id IS NULL AND g.is_active = 1 AND g.archived_at IS NULL ORDER BY g.id DESC')->fetch_all() as [$kandidat]) {
    if ($tanda((int) $kandidat) === false) {
        $guruKembar = (int) $kandidat;
        break;
    }
}
$assert($guruKembar > 0, 'I-12 fixture: ada guru tanpa akun/identitas yang namanya belum dipakai pengurus');
$assert($tanda($guruGanda) === true, 'I-12 akun ganda warisan (guru dan pengurus bernama sama) ditandai');
$namaKembar = (string) $satu("SELECT nama_guru FROM guru WHERE id = $guruKembar");
$assert($tanda($guruBerakun) === false, 'I-12 identitas pengurus milik guru sendiri tidak memicu tanda');
$stmt = $db->prepare("INSERT INTO pengurus (nama, jabatan, is_active) VALUES (?, 'Bendahara', 1)");
$namaVarian = '  ' . mb_strtoupper(str_replace(' ', '   ', $namaKembar)) . ' ';
$stmt->bind_param('s', $namaVarian);
$stmt->execute();
$pengurusKembar = (int) $db->insert_id;
$assert($tanda($guruKembar) === true, 'I-12 pengurus aktif bernama sama (beda huruf/spasi) menandai guru tersebut');
$assert($svc->opsi(PenugasanJenis::MUROBI)['subjek_guru'] === [], 'I-12 jenis khusus guru tidak memuat kelompok/tanda guru tambahan');
$jumlahIdentitasSebelum = (int) $satu('SELECT COUNT(*) FROM pengurus WHERE guru_id IS NOT NULL');
$idKembar = $svc->buat(PenugasanJenis::KESEHATAN, $umum('guru:' . $guruKembar), $admin);
$assert($idKembar > 0 && (int) $satu('SELECT COUNT(*) FROM pengurus WHERE guru_id IS NOT NULL') === $jumlahIdentitasSebelum + 1
    && (int) $satu("SELECT guru_id FROM pengurus WHERE id = $pengurusKembar") === 0,
    'I-12 tanda hanya peringatan: admin tetap dapat memilih guru; pengurus bernama sama tidak diubah');
$jalankan("UPDATE pengurus SET is_active = 0 WHERE id = $pengurusKembar");
$assert($tanda($guruKembar) === false, 'I-12 pengurus bernama sama yang nonaktif tidak lagi menandai');

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal.' . PHP_EOL;
exit($gagal === [] ? 0 : 1);
