<?php

declare(strict_types=1);

/**
 * Uji end-to-end layanan PRD V7 Fase 5 — kedua jalur (wali → pembimbing →
 * murobi/admin pengganti dan pengurus V2 → murobi) di atas DB
 * `tests/v7_phase5_db.sh` (fixture sintetis), termasuk konkurensi proses
 * terpisah lewat pekerja Fase 1–2 yang sudah ada.
 *
 *   V7F5_DB=test_v7_f5_test bash tests/v7_phase5_db.sh && V7F5_DB=test_v7_f5_test php tests/v7_phase5_e2e.php
 *
 *   E-1  satu pembimbing + satu murobi: perbaikan → teruskan → putus; satu ID,
 *        rantai status, notifikasi per tahap kepada pihak yang tepat
 *   E-2  NOL pembimbing: antrean admin, penetapan bukan-sah ditolak; sesudah
 *        penugasan sah dibuat, admin menetapkan → pembimbing → murobi
 *   E-3  DUA pembimbing: antrean admin; admin menetapkan salah satu; yang lain 404
 *   E-4  pembimbing tanpa akun: nol kandidat SAH → antrean admin
 *   E-5  NOL murobi: Perlu Penetapan Admin; admin pengganti beralasan memutus
 *   E-6  DUA murobi: admin menetapkan murobi B; murobi A ditolak; B memutus
 *   E-7  dua wali + wali asing + ID tebakan pada daftar/rincian/mutasi
 *   E-8  pencabutan relasi wali, penugasan pembimbing, dan penugasan murobi
 *        pada sesi lama; penetapan ulang pembimbing oleh admin
 *   E-9  irisan V2 tetap sesudah seluruh pemakaian V7 (manifest preflight)
 *   E-10 benturan tanggal lintas jalur wali ↔ pengurus; rentang dilepas sesudah
 *        ditolak pembimbing/dibatalkan
 *   E-11 retry berkunci sama untuk setiap mutasi → hasil sama; isi beda → 409
 *   E-12 konkurensi proses: klik ganda buat, buat lintas jalur, batal vs putus
 *        (beberapa ronde), batal vs teruskan, putus murobi vs admin pengganti
 *   E-13 jalur pengurus V2: buat, batal, putus, koreksi — tanpa tahap pembimbing
 *   E-14 notifikasi: InApp per peristiwa/penerima tepat satu, WhatsApp nol,
 *        Push OFF nol baris; retry tidak menambah
 *   E-15 verifier rilis `bin/v7_verify.php` lulus pada DB berbeban
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$dbName = getenv('V7F5_DB') ?: 'test_v7_f5_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once $root . '/app/bootstrap.php';

use App\Izin\IzinException;
use App\Izin\IzinWaliNotifikasi as N;

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
$akun = static function (string $username) use ($satu, $db): array {
    $id = (int) $satu("SELECT id FROM users WHERE username = '$username'");
    $guru = $satu("SELECT guru_id FROM users WHERE id = $id");
    $roles = array_column($db->query("SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = $id")->fetch_all(MYSQLI_ASSOC), 'slug');

    return ['id' => $id, 'roles' => $roles, 'guru_id' => $guru === null ? null : (int) $guru, 'username' => $username];
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
$meta = ['ip' => '127.0.0.1', 'user_agent' => 'uji-v7-f5'];
$w = static fn () => izin_wali_service();
$v2 = static fn () => izin_workflow_service();
$versi = static fn (int $id): int => (int) $satu("SELECT version FROM izin_pengajuan WHERE id = $id");
$status = static fn (int $id): string => (string) $satu("SELECT status FROM izin_pengajuan WHERE id = $id");
$hitung = static fn (string $tabel, string $where): int => (int) $satu("SELECT COUNT(*) FROM $tabel WHERE $where");
$kunciNo = 0;
$kunci = static function (string $awal) use (&$kunciNo): string {
    $kunciNo++;

    return 'v7f5-' . $awal . '-' . str_pad((string) $kunciNo, 4, '0', STR_PAD_LEFT) . '-' . bin2hex(random_bytes(3));
};
$hari = 0;
// Rentang unik per pengajuan agar uji tidak saling berbenturan kecuali disengaja.
$rentang = static function () use (&$hari): array {
    $hari += 3;
    $dari = date('Y-m-d', strtotime('2027-01-04 +' . $hari . ' days'));

    return [$dari, date('Y-m-d', strtotime($dari . ' +1 day'))];
};
$buat = static function (array $wali, int $santri, ?array $r = null, string $alasan = 'Acara keluarga E2E') use ($w, $meta, $kunci, $rentang): array {
    [$dari, $sampai] = $r ?? $rentang();

    return $w()->buat($wali, ['santri_id' => $santri, 'tgl_izin' => $dari, 'tgl_kembali' => $sampai, 'alasan' => $alasan, 'catatan' => 'Catatan E2E'], $kunci('buat'), $meta);
};
$tinjau = static fn (array $pb, int $id, string $t, ?string $catatan = null) => izin_wali_service()->tinjau($pb, $id, $t, $catatan, $versi($id), $kunci('tinjau'), $meta);
$putus = static fn (array $m, int $id, string $hasil = 'Disetujui') => izin_workflow_service()->decide($m, $id, $hasil, 'Keputusan E2E', null, $versi($id), $kunci('putus'), $meta, 'murobi');
$idDaftar = static fn (array $hasil): array => array_map('intval', array_column($hasil['items'] ?? [], 'id'));
$notif = static fn (int $id, string $event): array => array_map('intval', array_column($db->query("SELECT penerima_user_id FROM notifikasi_outbox
    WHERE event_key LIKE 'v7:izin:$id:%' AND event_type = '" . $db->real_escape_string($event) . "' AND kanal = 'InApp' ORDER BY penerima_user_id")->fetch_all(MYSQLI_ASSOC), 'penerima_user_id'));

$wA = $akun('sbx_v7_wali_a');
$wB = $akun('sbx_v7_wali_b');
$wAsing = $akun('sbx_ortu_b');
$pb1 = $akun('sbx_v7_pembimbing_satu');
$pb2 = $akun('sbx_v7_pembimbing_dua');
$admin = $akun('sbx_admin');
$mA = $akun('sbx_murobi_a');
$mB = $akun('sbx_murobi_b');
$pengurusLain = $akun('sbx_pengurus_a');
// Jalur pengurus V2: pengurus membuat untuk santri dalam cakupan penugasan pembimbingnya sendiri.
$pengurusV2 = $pb1;
$sSatu = $sid('V7S0001');
$sNol = $sid('V7S0002');
$sDua = $sid('V7S0003');
$sTanpaAkun = $sid('V7S0004');
$sTanpaMurobi = $sid('V7S0006');
$sDuaMurobi = $sid('V7S0007');
$sCadangan = $sid('V7S0008');
$assert(min($wA['id'], $wB['id'], $pb1['id'], $pb2['id'], $admin['id'], $mA['id'], $mB['id'], $pengurusLain['id'], $sSatu, $sNol, $sDua, $sTanpaAkun, $sTanpaMurobi, $sDuaMurobi, $sCadangan) > 0
    && $hitung('izin_pengajuan', "asal_pengajuan = 'Wali'") === 0, 'Fixture V7 tersedia (1/0/2 pembimbing, 1/0/2 murobi, dua wali); belum ada pengajuan wali');
$assert((int) $satu('SELECT push_enabled FROM pengaturan_notifikasi WHERE singleton = 1') === 0 && (int) $satu('SELECT whatsapp_enabled FROM pengaturan_notifikasi WHERE singleton = 1') === 0,
    'Sakelar Push dan WhatsApp OFF (keadaan rilis yang diuji)');

// Manifest sebelum pemakaian V7, untuk E-9.
$manifestAwal = tempnam(sys_get_temp_dir(), 'v7f5-manifest-');
exec('DB_NAME=' . escapeshellarg($dbName) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/v7_preflight.php') . ' ' . escapeshellarg($manifestAwal) . ' 2>&1', $o, $k);
$assert($k === 0, 'Preflight rilis V7 lulus pada DB uji dan menulis manifest awal');
$outboxV2Awal = $hitung('notifikasi_outbox', "event_key NOT LIKE 'v7:izin:%'");

// ===================================================================== E-1
echo PHP_EOL . '=== E-1. Satu pembimbing dan satu murobi: perbaikan → teruskan → putus ===' . PHP_EOL;
$h = $buat($wA, $sSatu);
$id1 = (int) $h['id'];
$assert($h['status'] === 'Menunggu Pembimbing' && $id1 > 0, 'Wali A mengajukan untuk anak dengan tepat satu pembimbing sah → Menunggu Pembimbing');
$assert(in_array($id1, $idDaftar($w()->antreanPembimbing($pb1)), true) && !in_array($id1, $idDaftar($w()->antreanPembimbing($pb2)), true),
    'Pembimbing Satu melihat item; Pembimbing Dua tidak');
$assert($galat(fn () => $w()->detail($mA, $id1)) === 404 && $hitung('izin_pengajuan', "id = $id1 AND murobi_guru_id IS NOT NULL") === 0,
    'Murobi belum menerima/melihat sebelum penerusan (404, tanpa murobi tujuan)');
$assert($notif($id1, N::MASUK_PEMBIMBING) === [$pb1['id']], 'Notifikasi masuk pembimbing: tepat satu, kepada Pembimbing Satu');
$h = $tinjau($pb1, $id1, 'minta_perbaikan', 'Mohon jelaskan jam jemput');
$assert($h['status'] === 'Perlu Perbaikan Wali' && $notif($id1, N::PERLU_PERBAIKAN) === [$wA['id']], 'Minta perbaikan → wali pengaju diberi tahu');
$assert($galat(fn () => $w()->perbaiki($wB, $id1, ['tgl_izin' => '2027-01-07', 'tgl_kembali' => '2027-01-09', 'alasan' => 'Bukan pengaju'], $versi($id1), $kunci('pb'), $meta)) !== null,
    'Wali kedua tidak dapat memperbaiki');
$d = $w()->detail($wA, $id1)['pengajuan'];
$h = $w()->perbaiki($wA, $id1, ['tgl_izin' => $d['tgl_izin'], 'tgl_kembali' => date('Y-m-d', strtotime($d['tgl_kembali'] . ' +1 day')), 'alasan' => 'Acara keluarga E2E, dijemput pukul 16.00'],
    $versi($id1), $kunci('perbaiki'), $meta);
$assert($h['status'] === 'Menunggu Pembimbing' && $hitung('izin_wali_perbaikan', "pengajuan_id = $id1 AND versi_sebelum = 2") === 1, 'Wali pengaju memperbaiki; versi sebelum/sesudah tercatat');
$h = $tinjau($pb1, $id1, 'teruskan');
$guruA = (int) $mA['guru_id'];
$assert($h['status'] === 'Diajukan' && (int) $satu("SELECT murobi_guru_id FROM izin_pengajuan WHERE id = $id1") === $guruA, 'Teruskan → routing V2 murobi tunggal (Murobi A) pada ID yang sama');
$assert($notif($id1, N::DITERUSKAN) === [$wA['id']] && $notif($id1, N::MASUK_MUROBI) === [$mA['id']], 'Notifikasi diteruskan → wali; masuk murobi → Murobi A');
$assert(($w()->detail($mA, $id1)['tindakan']['putuskan'] ?? false) === true && $galat(fn () => $w()->detail($mB, $id1)) === 404, 'Murobi A dapat memutus; Murobi B 404');
$h = $putus($mA, $id1);
$assert($h['status'] === 'Disetujui' && $hitung('izin_keputusan', "pengajuan_id = $id1 AND kapasitas = 'Murobi'") === 1 && $notif($id1, N::DIPUTUS) === [$wA['id']],
    'Murobi A memutus: satu keputusan Murobi; wali diberi tahu');
$assert($hitung('izin_pengajuan', "santri_id = $sSatu AND asal_pengajuan = 'Wali'") === 1 && $versi($id1) === 5, 'Satu baris dari pembuatan sampai keputusan (versi 5 = 1 + 4 peristiwa)');
$assert(($w()->detail($wA, $id1)['pengajuan']['status'] ?? '') === 'Disetujui' && ($w()->detail($wB, $id1)['pengajuan']['status'] ?? '') === 'Disetujui'
    && !str_contains(json_encode($w()->detail($wB, $id1)), 'jemput'), 'Wali pengaju dan wali kedua melihat status sama; wali kedua tanpa isi');
$assert($galat(fn () => $w()->batalkan($wA, $id1, 'Terlambat', $versi($id1), $kunci('batal'), $meta)) === 409, 'Pembatalan sesudah keputusan → 409');
$h = $v2()->correctDecision($admin, $id1, 'Ditolak', 'Bertepatan ujian susulan', 'Jadwal ujian baru diumumkan', $versi($id1), $kunci('koreksi'), $meta);
$assert($h['status'] === 'Ditolak' && $hitung('izin_keputusan', "pengajuan_id = $id1 AND jumlah_koreksi = 1") === 1 && $notif($id1, N::DIKOREKSI) === [$wA['id']]
    && ($w()->detail($wA, $id1)['pengajuan']['status'] ?? '') === 'Ditolak', 'Admin mengoreksi keputusan lewat mekanisme V2: tetap satu keputusan, wali diberi tahu dan melihat hasil baru');

// ===================================================================== E-2
echo PHP_EOL . '=== E-2. Nol pembimbing → admin, penugasan sah dibuat, ditetapkan ===' . PHP_EOL;
$h = $buat($wA, $sNol);
$id2 = (int) $h['id'];
$assert($h['status'] === 'Perlu Penetapan Pembimbing' && in_array($id2, $idDaftar($w()->daftarAdmin($admin, ['antrean' => '1'])), true)
    && $notif($id2, N::PERLU_PENETAPAN_PEMBIMBING) !== [] && $notif($id2, N::MASUK_PEMBIMBING) === [],
    'Nol kandidat → antrean admin, notifikasi admin, tanpa notifikasi pembimbing');
$assert($w()->kandidatPembimbing($admin, $id2) === [] && $galat(fn () => $w()->tetapkanPembimbing($admin, $id2, $satu("SELECT pengurus_id FROM users WHERE id = {$pb1['id']}"),
    'Coba bukan sah', $versi($id2), $kunci('tetap'), $meta)) === 422 && $status($id2) === 'Perlu Penetapan Pembimbing', 'Admin tidak dapat menetapkan pengurus yang bukan pembimbing sah (422)');
$kamarNol = (int) $satu("SELECT id FROM kamar WHERE nama_kamar = 'SBX V7 Kamar Nol'");
$pengurus2 = (int) $satu("SELECT pengurus_id FROM users WHERE id = {$pb2['id']}");
$tahun = (int) $satu("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
$jalankan("INSERT INTO pembimbing_assignments (pengurus_id, tahun_ajaran_id, target_type, kamar_id, tanggal_mulai, is_active, created_by)
    VALUES ($pengurus2, $tahun, 'Kamar', $kamarNol, CURDATE() - INTERVAL 1 DAY, 1, {$admin['id']})");
$assert(array_map('intval', array_column($w()->kandidatPembimbing($admin, $id2), 'pengurus_id')) === [$pengurus2], 'Sesudah penugasan sah dibuat admin, kandidat = Pembimbing Dua');
$assert($status($id2) === 'Perlu Penetapan Pembimbing' && $hitung('izin_pengajuan', "id = $id2 AND pembimbing_pengurus_id IS NULL") === 1, 'Penugasan baru tidak memindahkan pengajuan tanpa penetapan beralasan');
$h = $w()->tetapkanPembimbing($admin, $id2, $pengurus2, 'Penugasan kamar baru', $versi($id2), $kunci('tetap'), $meta);
$assert($h['status'] === 'Menunggu Pembimbing' && $notif($id2, N::MASUK_PEMBIMBING) === [$pb2['id']] && $hitung('izin_pembimbing_penetapan', "pengajuan_id = $id2") === 1,
    'Admin menetapkan → antrean Pembimbing Dua, notifikasi, riwayat penetapan');
$h = $tinjau($pb2, $id2, 'teruskan');
echo '[info] Routing murobi V7S0002 sesudah diteruskan: ' . $h['status'] . PHP_EOL;
$assert(in_array($h['status'], ['Diajukan', 'Perlu Penetapan Admin'], true), 'Pembimbing Dua meneruskan; routing murobi V2 berjalan');
if ($h['status'] === 'Perlu Penetapan Admin') {
    $v2()->assignMurobi($admin, $id2, $guruA, 'Penetapan murobi E-2', $versi($id2), $kunci('mur'), $meta);
}
$murobi2 = (int) $satu("SELECT u.id FROM users u JOIN izin_pengajuan p ON p.murobi_guru_id = u.guru_id WHERE p.id = $id2 LIMIT 1");
$h = $putus($murobi2 === $mB['id'] ? $mB : $mA, $id2, 'Ditolak');
$assert($h['status'] === 'Ditolak' && $hitung('izin_keputusan', "pengajuan_id = $id2") === 1, 'Murobi memutus Ditolak; satu keputusan');

// ===================================================================== E-3
echo PHP_EOL . '=== E-3. Dua pembimbing → admin menetapkan salah satu ===' . PHP_EOL;
$h = $buat($wA, $sDua);
$id3 = (int) $h['id'];
$kandidat = array_map('intval', array_column($w()->kandidatPembimbing($admin, $id3), 'pengurus_id'));
sort($kandidat);
$pengurus1 = (int) $satu("SELECT pengurus_id FROM users WHERE id = {$pb1['id']}");
$assert($h['status'] === 'Perlu Penetapan Pembimbing' && $kandidat === [min($pengurus1, $pengurus2), max($pengurus1, $pengurus2)]
    && $hitung('izin_pengajuan', "id = $id3 AND murobi_guru_id IS NULL") === 1, 'Dua kandidat → antrean admin (dua kandidat sah), tanpa murobi');
$assert(!in_array($id3, $idDaftar($w()->antreanPembimbing($pb1)), true) && !in_array($id3, $idDaftar($w()->antreanPembimbing($pb2)), true),
    'Belum masuk antrean pembimbing mana pun sebelum penetapan');
$w()->tetapkanPembimbing($admin, $id3, $pengurus2, 'Pembimbing kelas lebih dekat', $versi($id3), $kunci('tetap'), $meta);
$assert($galat(fn () => $tinjau($pb1, $id3, 'teruskan')) === 404 && $status($id3) === 'Menunggu Pembimbing', 'Pembimbing yang tidak ditetapkan → 404 tanpa perubahan');
$h = $tinjau($pb2, $id3, 'tolak', 'Tanggal bertepatan ujian');
$assert($h['status'] === 'Ditolak Pembimbing' && $hitung('izin_keputusan', "pengajuan_id = $id3") === 0 && $notif($id3, N::DITOLAK_PEMBIMBING) === [$wA['id']],
    'Pembimbing Dua menolak: terminal tanpa keputusan murobi; wali diberi tahu');

// ===================================================================== E-4
echo PHP_EOL . '=== E-4. Pembimbing tanpa akun: nol kandidat sah ===' . PHP_EOL;
$h = $buat($wA, $sTanpaAkun);
$id4 = (int) $h['id'];
$assert($h['status'] === 'Perlu Penetapan Pembimbing' && $w()->kandidatPembimbing($admin, $id4) === [], 'Penugasan milik pengurus tanpa akun bukan kandidat sah → antrean admin');
$h = $w()->batalkan($wA, $id4, 'Tidak jadi berangkat', $versi($id4), $kunci('batal'), $meta);
$assert($h['status'] === 'Dibatalkan' && $hitung('izin_keputusan', "pengajuan_id = $id4") === 0, 'Wali pengaju membatalkan dari antrean admin');

// ===================================================================== E-5
echo PHP_EOL . '=== E-5. Nol murobi → admin pengganti beralasan ===' . PHP_EOL;
$id5 = (int) $buat($wA, $sTanpaMurobi)['id'];
$h = $tinjau($pb1, $id5, 'teruskan');
$assert($h['status'] === 'Perlu Penetapan Admin' && $notif($id5, N::PERLU_PENETAPAN_MUROBI) !== [] && $notif($id5, N::MASUK_MUROBI) === [],
    'Routing murobi nol → Perlu Penetapan Admin; admin diberi tahu, tidak ada murobi');
$assert($galat(fn () => izin_workflow_service()->decide($admin, $id5, 'Disetujui', 'Tanpa alasan pengganti', null, $versi($id5), $kunci('ap'), $meta, 'admin')) !== null
    && $hitung('izin_keputusan', "pengajuan_id = $id5") === 0, 'Admin pengganti tanpa alasan penggantian ditolak');
$h = izin_workflow_service()->decide($admin, $id5, 'Disetujui', 'Disetujui admin', 'Murobi belum ditugaskan', $versi($id5), $kunci('ap'), $meta, 'admin');
$assert($h['status'] === 'Disetujui' && $hitung('izin_keputusan', "pengajuan_id = $id5 AND kapasitas = 'Admin Pengganti' AND alasan_penggantian IS NOT NULL") === 1,
    'Admin pengganti beralasan memutus: satu keputusan berkapasitas Admin Pengganti');

// ===================================================================== E-6
echo PHP_EOL . '=== E-6. Dua murobi → admin menetapkan Murobi B ===' . PHP_EOL;
$id6 = (int) $buat($wA, $sDuaMurobi)['id'];
$h = $tinjau($pb1, $id6, 'teruskan');
$assert($h['status'] === 'Perlu Penetapan Admin' && $galat(fn () => $putus($mA, $id6)) !== null && $galat(fn () => $putus($mB, $id6)) !== null,
    'Routing murobi ganda → admin; kedua murobi belum dapat memutus');
$guruB = (int) $mB['guru_id'];
$v2()->assignMurobi($admin, $id6, $guruB, 'Murobi kelas', $versi($id6), $kunci('mur'), $meta);
$assert($galat(fn () => $putus($mA, $id6)) !== null && $galat(fn () => $w()->detail($mA, $id6)) === 404, 'Murobi A ditolak sesudah penetapan Murobi B');
$h = $putus($mB, $id6);
$assert($h['status'] === 'Disetujui' && $hitung('izin_keputusan', "pengajuan_id = $id6") === 1, 'Murobi B memutus; satu keputusan');

// ===================================================================== E-7
echo PHP_EOL . '=== E-7. Dua wali, wali asing, ID tebakan ===' . PHP_EOL;
$id7 = (int) $buat($wA, $sCadangan, null, 'Alasan pribadi E2E-7')['id'];
$dB = $w()->detail($wB, $id7);
$assert(in_array($id7, $idDaftar($w()->daftarWali($wB)), true) && $dB['tindakan'] === [] && !str_contains(json_encode($dB), 'E2E-7') && ($dB['pengajuan']['pengaju_sendiri'] ?? true) === false,
    'Wali kedua melihat status ringkas tanpa alasan dan tanpa tindakan');
$assert($galat(fn () => $w()->batalkan($wB, $id7, 'Bukan pengaju', $versi($id7), $kunci('batal'), $meta)) !== null && $status($id7) === 'Menunggu Pembimbing',
    'Wali kedua tidak dapat membatalkan');
$assert(!in_array($id7, $idDaftar($w()->daftarWali($wAsing)), true) && $galat(fn () => $w()->detail($wAsing, $id7)) === 404, 'Wali asing: daftar tanpa item, rincian 404');
$assert($galat(fn () => $buat($wAsing, $sCadangan)) !== null && $galat(fn () => $w()->batalkan($wAsing, $id7, 'x', $versi($id7), $kunci('b'), $meta)) !== null,
    'Wali asing tidak dapat mengajukan atau membatalkan untuk anak orang lain');
$maks = (int) $satu('SELECT MAX(id) FROM izin_pengajuan');
$tebakan = 0;
foreach ([$maks + 1, $maks + 50, 1, $id1, $id5] as $tebak) {
    $tebakan += $galat(fn () => $w()->detail($wAsing, $tebak)) === 404 ? 1 : 0;
}
$assert($tebakan === 5, 'ID tebakan (tidak ada, V2, milik anak lain) → 404 bagi wali asing');
$assert($galat(fn () => $w()->detail($pengurusLain, $id7)) === 404 && $galat(fn () => $tinjau($pengurusLain, $id7, 'teruskan')) !== null, 'Pengurus V2 bukan pembimbing tujuan: rincian 404, tinjauan ditolak');

// ===================================================================== E-8
echo PHP_EOL . '=== E-8. Pencabutan relasi dan penugasan pada sesi lama ===' . PHP_EOL;
$relasiA = (int) $satu("SELECT sw.id FROM santri_wali sw JOIN users u ON u.wali_id = sw.wali_id WHERE u.id = {$wA['id']} AND sw.santri_id = $sCadangan");
$jalankan("UPDATE santri_wali SET archived_at = NOW() WHERE id = $relasiA");
$assert($galat(fn () => $w()->detail($wA, $id7)) === 404 && $galat(fn () => $w()->batalkan($wA, $id7, 'Relasi dicabut', $versi($id7), $kunci('b'), $meta)) !== null
    && !in_array($id7, $idDaftar($w()->daftarWali($wA)), true), 'Relasi wali pengaju diarsipkan: rincian 404, batal ditolak, daftar kosong');
$assert(($w()->detail($pb1, $id7)['peran_akses'] ?? '') === 'pembimbing', 'Pembimbing tetap meninjau (cakupan santri, bukan relasi wali)');
$jalankan("UPDATE santri_wali SET archived_at = NULL WHERE id = $relasiA");
$paSatu = (int) $satu("SELECT pembimbing_assignment_id FROM izin_pengajuan WHERE id = $id7");
$jalankan("UPDATE pembimbing_assignments SET is_active = 0 WHERE id = $paSatu");
$assert($galat(fn () => $tinjau($pb1, $id7, 'teruskan')) !== null && $galat(fn () => $w()->detail($pb1, $id7)) === 404 && $status($id7) === 'Menunggu Pembimbing',
    'Penugasan pembimbing dicabut: tinjauan ditolak, rincian 404, status tetap');
$kamarSatu = (int) $satu("SELECT id FROM kamar WHERE nama_kamar = 'SBX V7 Kamar Satu'");
$jalankan("INSERT INTO pembimbing_assignments (pengurus_id, tahun_ajaran_id, target_type, kamar_id, tanggal_mulai, is_active, created_by)
    VALUES ($pengurus2, $tahun, 'Kamar', $kamarSatu, CURDATE() - INTERVAL 1 DAY, 1, {$admin['id']})");
$h = $w()->tetapkanPembimbing($admin, $id7, $pengurus2, 'Pembimbing lama dicabut', $versi($id7), $kunci('tetap'), $meta);
$assert($h['status'] === 'Menunggu Pembimbing' && $hitung('izin_riwayat_status', "pengajuan_id = $id7 AND peristiwa = 'pembimbing_ditetapkan_ulang'") === 1,
    'Admin menetapkan ulang pembimbing sah (riwayat penetapan ulang beralasan)');
$h = $tinjau($pb2, $id7, 'teruskan');
$assert($h['status'] === 'Diajukan' && (int) $satu("SELECT murobi_guru_id FROM izin_pengajuan WHERE id = $id7") === $guruA, 'Pembimbing pengganti meneruskan ke Murobi A');
// Aturan V2: murobi tujuan berhak memutus selama masih memiliki penugasan
// murobi aktif (`IzinRouter::isEligibleMurobi`). Seluruh penugasannya dicabut.
$maA = array_map('intval', array_column($db->query("SELECT id FROM murobi_assignments WHERE guru_id = $guruA AND is_active = 1")->fetch_all(MYSQLI_ASSOC), 'id'));
$jalankan('UPDATE murobi_assignments SET is_active = 0 WHERE id IN (' . implode(',', $maA) . ')');
$assert($galat(fn () => $putus($mA, $id7)) !== null && $hitung('izin_keputusan', "pengajuan_id = $id7") === 0 && $status($id7) === 'Diajukan',
    'Seluruh penugasan murobi dicabut pada sesi lama: keputusan ditolak, nol keputusan');
$jalankan('UPDATE murobi_assignments SET is_active = 1 WHERE id IN (' . implode(',', $maA) . ')');
// Pulihkan keadaan fixture: Pembimbing Satu kembali tunggal di Kamar Satu.
$jalankan("UPDATE pembimbing_assignments SET is_active = 1 WHERE id = $paSatu");
$jalankan("UPDATE pembimbing_assignments SET is_active = 0 WHERE pengurus_id = $pengurus2 AND kamar_id = $kamarSatu");
$h = $w()->batalkan($wA, $id7, 'Acara diundur', $versi($id7), $kunci('batal'), $meta);
$assert($h['status'] === 'Dibatalkan' && $notif($id7, N::DIBATALKAN) === [$mA['id']], 'Pembatalan sesudah diteruskan; pemegang antrean (murobi) diberi tahu');
// Temuan F5-1 (perilaku V2, tidak diubah): cabut HANYA penugasan kamar santri.
$id8b = (int) $buat($wA, $sCadangan)['id'];
$tinjau($pb1, $id8b, 'teruskan');
$maKamar = (int) $satu("SELECT id FROM murobi_assignments WHERE guru_id = $guruA AND kamar_id = $kamarSatu AND is_active = 1 LIMIT 1");
$jalankan("UPDATE murobi_assignments SET is_active = 0 WHERE id = $maKamar");
$hasilF51 = $galat(fn () => $putus($mA, $id8b));
echo '[temuan] F5-1: penugasan murobi atas kamar santri dicabut, murobi tujuan masih memiliki penugasan murobi lain → keputusan '
    . ($hasilF51 === null ? 'DITERIMA (aturan V2 isEligibleMurobi)' : 'ditolak ' . $hasilF51) . '; status ' . $status($id8b) . PHP_EOL;
$jalankan("UPDATE murobi_assignments SET is_active = 1 WHERE id = $maKamar");

// ===================================================================== E-9
echo PHP_EOL . '=== E-9. Irisan V2 tetap sesudah pemakaian V7 ===' . PHP_EOL;
$o = [];
exec('DB_NAME=' . escapeshellarg($dbName) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/v7_verify.php') . ' --irisan-v2 ' . escapeshellarg($manifestAwal) . ' 2>&1', $o, $k);
$irisan = array_values(array_filter($o, static fn (string $l): bool => str_contains($l, 'Irisan V2')));
$assert($k === 0 && count($irisan) === 6 && count(array_filter($irisan, static fn (string $l): bool => str_starts_with($l, '[lulus]'))) === 6,
    'Keputusan, riwayat, idempotensi, dan kolom V2 baris Pengurus, perizinan warisan, serta identitas tidak berubah oleh E-1…E-8 (6 irisan; verifier exit ' . $k . ')');
$assert($hitung('notifikasi_outbox', "event_key NOT LIKE 'v7:izin:%'") === $outboxV2Awal, 'Tidak ada notifikasi peristiwa V2 untuk seluruh baris Wali');

// ===================================================================== E-10
echo PHP_EOL . '=== E-10. Benturan tanggal lintas jalur ===' . PHP_EOL;
$r10 = ['2027-03-10', '2027-03-12'];
$id10 = (int) $buat($wA, $sCadangan, $r10)['id'];
$assert($galat(fn () => $v2()->create($pengurusV2, ['santri_id' => $sCadangan, 'tgl_izin' => '2027-03-11', 'tgl_kembali' => '2027-03-13', 'alasan' => 'Benturan V2'],
    $kunci('v2'), $meta, 'pengurus')) === 409, 'Pengurus V2 bertumpang tindih dengan pengajuan wali yang menunggu pembimbing → 409');
$tinjau($pb1, $id10, 'minta_perbaikan', 'Perjelas');
$assert($galat(fn () => $v2()->create($pengurusV2, ['santri_id' => $sCadangan, 'tgl_izin' => '2027-03-11', 'tgl_kembali' => '2027-03-13', 'alasan' => 'Benturan V2'],
    $kunci('v2'), $meta, 'pengurus')) === 409, 'Perlu Perbaikan Wali tetap menahan rentang');
$w()->batalkan($wA, $id10, 'Diganti jalur pengurus', $versi($id10), $kunci('batal'), $meta);
$hV2 = $v2()->create($pengurusV2, ['santri_id' => $sCadangan, 'tgl_izin' => '2027-03-11', 'tgl_kembali' => '2027-03-13', 'alasan' => 'Sesudah dibatalkan'], $kunci('v2'), $meta, 'pengurus');
$assert((int) $hV2['id'] > 0, 'Sesudah wali membatalkan, rentang dilepas: pengurus V2 dapat mengajukan');
$assert($galat(fn () => $buat($wA, $sCadangan, ['2027-03-12', '2027-03-14'])) === 409, 'Sebaliknya: pengajuan wali bertumpang tindih dengan pengajuan pengurus aktif → 409');
$idTolak = (int) $buat($wA, $sCadangan, ['2027-04-01', '2027-04-02'])['id'];
$tinjau($pb1, $idTolak, 'tolak', 'Tidak layak');
$assert((int) $buat($wA, $sCadangan, ['2027-04-01', '2027-04-02'])['id'] > $idTolak, 'Rentang yang ditolak pembimbing dilepas untuk pengajuan baru');

// ===================================================================== E-11
echo PHP_EOL . '=== E-11. Retry berkunci sama dan isi berbeda ===' . PHP_EOL;
[$d11, $s11] = $rentang();
$k11 = $kunci('retry-buat');
$isi11 = ['santri_id' => $sTanpaMurobi, 'tgl_izin' => $d11, 'tgl_kembali' => $s11, 'alasan' => 'Retry E-11'];
$a = $w()->buat($wA, $isi11, $k11, $meta);
$b = $w()->buat($wA, $isi11, $k11, $meta);
$id11 = (int) $a['id'];
$assert((int) $b['id'] === $id11 && $b['idempotent_replay'] === true && $galat(fn () => $w()->buat($wA, ['alasan' => 'Isi lain'] + $isi11, $k11, $meta)) === 409
    && $hitung('izin_pengajuan', "santri_id = $sTanpaMurobi AND tgl_izin = '$d11'") === 1, 'Buat: retry → ID sama; isi beda → 409; satu baris');
$outboxSebelum = $hitung('notifikasi_outbox', "event_key LIKE 'v7:izin:$id11:%'");
$kt = $kunci('retry-tinjau');
$v = $versi($id11);
$a = $w()->tinjau($pb1, $id11, 'minta_perbaikan', 'Lengkapi', $v, $kt, $meta);
$b = $w()->tinjau($pb1, $id11, 'minta_perbaikan', 'Lengkapi', $v, $kt, $meta);
$assert($b['idempotent_replay'] === true && $b['status'] === $a['status'] && $galat(fn () => $w()->tinjau($pb1, $id11, 'tolak', 'Lengkapi', $v, $kt, $meta)) === 409
    && $hitung('izin_pembimbing_tinjauan', "pengajuan_id = $id11") === 1, 'Tinjau: retry → hasil sama; tindakan lain berkunci sama → 409; satu tinjauan');
$assert($galat(fn () => $w()->tinjau($pb1, $id11, 'teruskan', null, $v, $kunci('lama'), $meta)) === 409, 'Versi lama → 409');
$kp = $kunci('retry-perbaiki');
$v = $versi($id11);
$isiP = ['tgl_izin' => $d11, 'tgl_kembali' => $s11, 'alasan' => 'Retry E-11 dilengkapi'];
$a = $w()->perbaiki($wA, $id11, $isiP, $v, $kp, $meta);
$b = $w()->perbaiki($wA, $id11, $isiP, $v, $kp, $meta);
$assert($b['idempotent_replay'] === true && $hitung('izin_wali_perbaikan', "pengajuan_id = $id11") === 1, 'Perbaiki: retry → satu perbaikan');
$kf = $kunci('retry-teruskan');
$v = $versi($id11);
$w()->tinjau($pb1, $id11, 'teruskan', null, $v, $kf, $meta);
$b = $w()->tinjau($pb1, $id11, 'teruskan', null, $v, $kf, $meta);
$assert($b['idempotent_replay'] === true && $hitung('izin_pembimbing_tinjauan', "pengajuan_id = $id11 AND tindakan = 'Teruskan'") === 1, 'Teruskan: retry → satu penerusan');
$kd = $kunci('retry-putus');
$v = $versi($id11);
$alasanP = 'Admin pengganti E-11';
$a = $v2()->decide($admin, $id11, 'Ditolak', $alasanP, 'Murobi kosong', $v, $kd, $meta, 'admin');
$b = $v2()->decide($admin, $id11, 'Ditolak', $alasanP, 'Murobi kosong', $v, $kd, $meta, 'admin');
$assert($b['idempotent_replay'] === true && $hitung('izin_keputusan', "pengajuan_id = $id11") === 1
    && $galat(fn () => $v2()->decide($admin, $id11, 'Disetujui', $alasanP, 'Murobi kosong', $v, $kd, $meta, 'admin')) === 409, 'Keputusan: retry → satu keputusan; hasil lain berkunci sama → 409');
$perPeristiwa = (int) $satu("SELECT COUNT(*) FROM (SELECT event_key, kanal, penerima_user_id FROM notifikasi_outbox WHERE event_key LIKE 'v7:izin:$id11:%'
    GROUP BY event_key, kanal, penerima_user_id HAVING COUNT(*) > 1) d");
$assert($perPeristiwa === 0, 'Seluruh retry E-11 tidak menggandakan notifikasi (0 kunci peristiwa/penerima ganda)');
$kb = $kunci('retry-batal');
$id11b = (int) $buat($wA, $sTanpaMurobi)['id'];
$v = $versi($id11b);
$w()->batalkan($wA, $id11b, 'Retry batal', $v, $kb, $meta);
$b = $w()->batalkan($wA, $id11b, 'Retry batal', $v, $kb, $meta);
$assert($b['idempotent_replay'] === true && $hitung('izin_riwayat_status', "pengajuan_id = $id11b AND peristiwa = 'pembatalan_wali'") === 1, 'Batal: retry → satu pembatalan');

// ===================================================================== E-12
echo PHP_EOL . '=== E-12. Konkurensi proses terpisah ===' . PHP_EOL;
$jalanBersama = static function (array $perintah) use ($root, $dbName): array {
    $mulai = (int) floor(microtime(true) * 1000) + 900;
    $proses = [];
    foreach ($perintah as $i => $args) {
        $cmd = array_merge([PHP_BINARY, $root . '/tests/' . $args[0]], array_map('strval', array_slice($args, 1)));
        $cmd = array_merge(array_slice($cmd, 0, -1), [(string) $mulai], array_slice($cmd, -1));
        $proses[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, array_merge(getenv(), ['DB_NAME' => $dbName]));
        $proses[$i] = [$proses[$i], $pipes];
    }
    $hasil = [];
    foreach ($proses as $i => [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($p);
        $hasil[$i] = json_decode(trim((string) $out), true) ?? ['ok' => false, 'http' => 0, 'pesan' => trim((string) $out)];
    }

    return $hasil;
};
// Pekerja Fase 1: <jalur> <user> <santri> <dari> <sampai> <kunci> <mulai> [alasan]; kolom "mulai" disisipkan sebelum argumen terakhir.
[$d12, $s12] = $rentang();
$k12 = $kunci('ganda');
$hasil = $jalanBersama(array_fill(0, 6, ['v7_phase1_worker.php', 'wali', 'sbx_v7_wali_a', $sSatu, $d12, $s12, $k12, 'Klik ganda E-12']));
$ids = array_unique(array_map(static fn (array $x): int => (int) ($x['id'] ?? 0), array_filter($hasil, static fn (array $x): bool => $x['ok'])));
$assert(count($ids) === 1 && $hitung('izin_pengajuan', "santri_id = $sSatu AND tgl_izin = '$d12'") === 1 && count(array_filter($hasil, static fn (array $x): bool => ($x['http'] ?? 0) === 500)) === 0,
    'Enam kiriman wali bersamaan berkunci sama → satu ID, satu baris, nol 500');
[$d12b, $s12b] = $rentang();
$hasil = $jalanBersama([
    ['v7_phase1_worker.php', 'wali', 'sbx_v7_wali_a', $sCadangan, $d12b, $s12b, $kunci('lintas-w'), 'Lintas wali'],
    ['v7_phase1_worker.php', 'pengurus', 'sbx_v7_pembimbing_satu', $sCadangan, $d12b, $s12b, $kunci('lintas-p'), 'Lintas pengurus'],
    ['v7_phase1_worker.php', 'wali', 'sbx_v7_wali_a', $sCadangan, $d12b, $s12b, $kunci('lintas-w2'), 'Lintas wali kedua'],
]);
$aktif = $hitung('izin_pengajuan', "santri_id = $sCadangan AND tgl_izin = '$d12b' AND status IN (" . implode(',', array_map(static fn ($s) => "'$s'", \App\Izin\IzinWriteRepository::STATUS_MENAHAN)) . ')');
$assert($aktif === 1 && count(array_filter($hasil, static fn (array $x): bool => $x['ok'])) === 1, 'Tiga kiriman lintas jalur bersamaan pada rentang sama → tepat satu pengajuan aktif');

// Batal vs putus, beberapa ronde.
$hasilRonde = ['batal' => 0, 'putus' => 0, 'ganda' => 0, 'galat500' => 0];
for ($ronde = 0; $ronde < 4; $ronde++) {
    $id = (int) $buat($wA, $sCadangan)['id'];
    $tinjau($pb1, $id, 'teruskan');
    $v = $versi($id);
    $hasil = $jalanBersama([
        ['v7_phase2_worker.php', 'batal', 'sbx_v7_wali_a', $id, $v, $kunci('bp-b'), ''],
        ['v7_phase2_worker.php', 'putus', 'sbx_murobi_a', $id, $v, $kunci('bp-p'), 'Disetujui'],
    ]);
    $st = $status($id);
    $kep = $hitung('izin_keputusan', "pengajuan_id = $id");
    $hasilRonde[$st === 'Dibatalkan' ? 'batal' : 'putus']++;
    $berhasil = count(array_filter($hasil, static fn (array $x): bool => $x['ok']));
    if ($berhasil !== 1 || !(($st === 'Dibatalkan' && $kep === 0) || ($st === 'Disetujui' && $kep === 1))) {
        $hasilRonde['ganda']++;
    }
    $hasilRonde['galat500'] += count(array_filter($hasil, static fn (array $x): bool => ($x['http'] ?? 0) === 500));
}
echo '[info] Batal vs putus 4 ronde: ' . json_encode($hasilRonde) . PHP_EOL;
$assert($hasilRonde['ganda'] === 0 && $hasilRonde['galat500'] === 0, 'Batal vs putus bersamaan (4 ronde): tepat satu berhasil, satu status terminal, paling banyak satu keputusan, nol 500');
$id = (int) $buat($wA, $sCadangan)['id'];
$v = $versi($id);
$hasil = $jalanBersama([
    ['v7_phase2_worker.php', 'batal', 'sbx_v7_wali_a', $id, $v, $kunci('bt-b'), ''],
    ['v7_phase2_worker.php', 'teruskan', 'sbx_v7_pembimbing_satu', $id, $v, $kunci('bt-t'), ''],
]);
$st = $status($id);
$assert(count(array_filter($hasil, static fn (array $x): bool => $x['ok'])) === 1 && in_array($st, ['Dibatalkan', 'Diajukan'], true)
    && $hitung('izin_pembimbing_tinjauan', "pengajuan_id = $id") === ($st === 'Diajukan' ? 1 : 0), 'Batal vs teruskan bersamaan: tepat satu hasil efektif (' . $st . ')');
if ($st === 'Diajukan') {
    $w()->batalkan($wA, $id, 'Rapikan', $versi($id), $kunci('rapi'), $meta);
}
$id = (int) $buat($wA, $sCadangan)['id'];
$tinjau($pb1, $id, 'teruskan');
$v = $versi($id);
$kSama = $kunci('dobel-putus');
$hasil = $jalanBersama([
    ['v7_phase2_worker.php', 'putus', 'sbx_murobi_a', $id, $v, $kSama, 'Disetujui'],
    ['v7_phase2_worker.php', 'putus', 'sbx_murobi_a', $id, $v, $kSama, 'Disetujui'],
    ['v7_phase2_worker.php', 'putus_admin', 'sbx_admin', $id, $v, $kunci('dobel-admin'), 'Ditolak'],
]);
$assert($hitung('izin_keputusan', "pengajuan_id = $id") === 1 && count(array_filter($hasil, static fn (array $x): bool => ($x['http'] ?? 0) === 500)) === 0
    && count(array_unique(array_map(static fn (array $x): string => (string) ($x['status'] ?? ''), array_filter($hasil, static fn (array $x): bool => $x['ok'])))) === 1,
    'Klik ganda murobi + admin pengganti bersamaan → satu keputusan efektif, hasil seragam bagi yang berhasil, nol 500');

// ===================================================================== E-13
echo PHP_EOL . '=== E-13. Jalur pengurus V2 tanpa tahap pembimbing ===' . PHP_EOL;
[$d13, $s13] = $rentang();
$h = $v2()->create($pengurusV2, ['santri_id' => $sSatu, 'tgl_izin' => $d13, 'tgl_kembali' => $s13, 'alasan' => 'Jalur pengurus E-13'], $kunci('v2'), $meta, 'pengurus');
$idV2 = (int) $h['id'];
$assert(in_array($h['status'], ['Diajukan', 'Perlu Penetapan Admin'], true) && $hitung('izin_pengajuan', "id = $idV2 AND asal_pengajuan = 'Pengurus' AND pembimbing_pengurus_id IS NULL") === 1
    && $hitung('izin_pembimbing_tinjauan', "pengajuan_id = $idV2") === 0, 'Pengurus V2 langsung ke routing murobi (asal Pengurus, tanpa tinjauan pembimbing)');
$h = $putus($mA, $idV2);
$assert($h['status'] === 'Disetujui' && $hitung('izin_keputusan', "pengajuan_id = $idV2") === 1, 'Murobi memutus pengajuan pengurus');
$h = $v2()->correctDecision($admin, $idV2, 'Ditolak', 'Dikoreksi', 'Salah input', $versi($idV2), $kunci('kor'), $meta);
$assert($h['status'] === 'Ditolak' && $hitung('izin_keputusan', "pengajuan_id = $idV2 AND hasil = 'Ditolak' AND jumlah_koreksi = 1") === 1, 'Admin mengoreksi keputusan V2 (tetap satu baris keputusan)');
[$d13b, $s13b] = $rentang();
$idV2b = (int) $v2()->create($pengurusV2, ['santri_id' => $sSatu, 'tgl_izin' => $d13b, 'tgl_kembali' => $s13b, 'alasan' => 'Dibatalkan pengurus'], $kunci('v2'), $meta, 'pengurus')['id'];
$h = $v2()->cancel($pengurusV2, $idV2b, 'Batal pengurus', $versi($idV2b), $kunci('v2b'), $meta, 'pengurus');
$assert($h['status'] === 'Dibatalkan', 'Pengurus membatalkan pengajuan V2-nya');
$assert($galat(fn () => $v2()->cancel($admin, $id1, 'Admin mencoba', $versi($id1), $kunci('x'), $meta, 'admin')) !== null, 'Pembatalan V2 tetap ditolak untuk pengajuan Wali');

// ===================================================================== E-14
echo PHP_EOL . '=== E-14. Notifikasi ===' . PHP_EOL;
$assert($hitung('notifikasi_outbox', "event_key LIKE 'v7:izin:%' AND kanal = 'WhatsApp'") === 0, 'Nol baris WhatsApp untuk seluruh peristiwa V7');
$assert($hitung('notifikasi_outbox', "event_key LIKE 'v7:izin:%' AND kanal = 'Push'") === 0, 'Push OFF → nol baris Push (nol request provider)');
$assert((int) $satu("SELECT COUNT(*) FROM (SELECT event_key, kanal, penerima_user_id FROM notifikasi_outbox WHERE event_key LIKE 'v7:izin:%'
    GROUP BY event_key, kanal, penerima_user_id HAVING COUNT(*) > 1) d") === 0, 'Tepat satu baris per peristiwa/kanal/penerima pada seluruh E2E');
$peristiwa = array_column($db->query("SELECT DISTINCT event_type FROM notifikasi_outbox WHERE event_key LIKE 'v7:izin:%'")->fetch_all(MYSQLI_ASSOC), 'event_type');
sort($peristiwa);
$semuaEvent = N::EVENTS;
sort($semuaEvent);
echo '[info] Jenis peristiwa V7 yang terjadi: ' . count($peristiwa) . '/' . count($semuaEvent) . ' (' . implode(', ', array_diff($semuaEvent, $peristiwa)) . ' tidak terjadi)' . PHP_EOL;
$assert($peristiwa === $semuaEvent, 'Kesepuluh jenis peristiwa V7 terjadi pada E2E dan masing-masing tercatat');
$bocor = (int) $satu("SELECT COUNT(*) FROM notifikasi_outbox WHERE event_key LIKE 'v7:izin:%'
    AND (CONCAT(judul, isi, data_json) LIKE '%SBX V7 Santri%' OR CONCAT(judul, isi, data_json) LIKE '%E2E%' OR CONCAT(judul, isi, data_json) LIKE '%2027-%')");
$assert($bocor === 0, 'Judul/isi/data notifikasi tanpa nama santri, alasan, atau tanggal');

// ===================================================================== E-15
echo PHP_EOL . '=== E-15. Verifier rilis ===' . PHP_EOL;
$o = [];
exec('DB_NAME=' . escapeshellarg($dbName) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/v7_verify.php') . ' 2>&1', $o, $k);
foreach (array_filter($o, static fn (string $l): bool => str_contains($l, '[gagal]') || str_starts_with($l, 'Ringkasan')) as $l) {
    echo '    ' . $l . PHP_EOL;
}
$assert($k === 0, 'bin/v7_verify.php lulus pada DB sesudah seluruh E2E dan konkurensi (rantai riwayat, audit, keputusan tunggal, yatim, laporan)');
@unlink($manifestAwal);

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
