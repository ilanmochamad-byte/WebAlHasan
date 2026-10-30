<?php

declare(strict_types=1);

/**
 * Uji integrasi Fase 2 penugasan lintas guru/pengurus — pemisahan tugas per
 * akun (rancangan §3.2; keputusan Human Developer PK-T1, PK-T5…PK-T7) di atas
 * DB uji `tests/penugasan_keamanan_f2_db.sh`.
 *
 *   bash tests/penugasan_keamanan_f2_db.sh && php tests/penugasan_keamanan_f2_integration.php
 *
 * Akun X = `sbx_guru_biasa` (guru) yang lewat Fase 1 memegang identitas
 * pengurus, lalu menjadi pembimbing DAN murobi atas santri yang sama.
 *
 *   J-1  routing V2: guru milik akun pengaju dikecualikan (sendiri → admin; dua → yang lain)
 *   J-2  penetapan admin/daftar murobi berhak menolak guru pengaju
 *   J-3  keputusan oleh akun pengaju ditolak walau dipaksa diarahkan; tombol tersembunyi
 *   J-4  admin pengganti: pembuat tidak memutus/mengoreksi; admin lain boleh (PK-T6)
 *   J-5  V7: penerusan pembimbing mengecualikan peninjau; penetapan & keputusan ditolak
 *   J-6  sikap V4: pengisi pembimbing tidak mengetahui/mengoreksi sebagai murobi (PK-T7)
 *   J-7  PK-T1/PK-T5: Pendidikan tidak memverifikasi/mengembalikan nilai isiannya sendiri
 *   J-8  V3: murobi tidak menandai mengetahui pelanggaran/kasus/sesi miliknya sendiri
 *   J-9  kontrol: akun yang tidak terlibat tetap berjalan seperti sebelumnya
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$dbName = getenv('PKF2_DB') ?: 'test_pk_f2_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once $root . '/app/bootstrap.php';

use App\Auth\Capabilities;
use App\Izin\IzinException;
use App\Izin\IzinRouter;
use App\Penugasan\PenugasanJenis;
use App\V3\V3Exception;
use App\V4\NilaiService;
use App\V4\SikapService;
use App\V4\V4Exception;
use App\V4\VerifikasiService;

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
$caps = capabilities();
$akun = static function (int $userId) use ($satu, $caps): array {
    $caps->forget($userId);
    $roles = (string) $satu("SELECT GROUP_CONCAT(r.slug ORDER BY r.slug) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = $userId");
    $guru = $satu("SELECT guru_id FROM users WHERE id = $userId");

    return ['id' => $userId, 'roles' => $roles === '' ? [] : explode(',', $roles), 'guru_id' => $guru === null ? null : (int) $guru];
};
/** @return array{0:?int,1:string} status HTTP dan pesan galat (null bila berhasil) */
$coba = static function (callable $f): array {
    try {
        $f();
    } catch (IzinException $e) {
        return [$e->status(), $e->getMessage()];
    } catch (V4Exception $e) {
        return [$e->status, $e->getMessage()];
    } catch (V3Exception $e) {
        return [$e->status, $e->getMessage()];
    }

    return [null, ''];
};
$kunci = static fn (string $awal): string => 'pkf2-' . $awal . '-' . bin2hex(random_bytes(6));
$meta = ['ip' => '127.0.0.1', 'user_agent' => 'pkf2-integrasi'];
$hari = date('Y-m-d');
$tanggal = static fn (int $tambah): string => date('Y-m-d', strtotime("+$tambah day"));

$svc = penugasan_service();
$alur = izin_workflow_service();
$wali = izin_wali_service();
$router = izin_router();

$id = static fn (string $username): int => (int) $satu("SELECT id FROM users WHERE username = '$username'");
$admin = $id('sbx_admin');
$adminLain = $id('sbx_v6_admin_bsa');
$x = $id('sbx_guru_biasa');
$guruX = (int) $satu("SELECT guru_id FROM users WHERE id = $x");
$y = $id('sbx_murobi_a');
$guruY = (int) $satu("SELECT guru_id FROM users WHERE id = $y");
$murobiC = $id('sbx_murobi_c');
$waliA = $id('sbx_v7_wali_a');
$pendidikanLain = $id('sbx_pendidikan');
$tahun = (int) $satu("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1");
$kamar = 381;
$santriCadangan = 1721;
$santriSatu = 1714;
$santriTanpaMurobi = 1719;
$murobiYKamar = (int) $satu("SELECT id FROM murobi_assignments WHERE guru_id = $guruY AND kamar_id = $kamar AND is_active = 1");
$pembimbingLamaKamar = (int) $satu("SELECT id FROM pembimbing_assignments WHERE kamar_id = $kamar AND is_active = 1 ORDER BY id LIMIT 1");
$assert($admin > 0 && $adminLain > 0 && $x > 0 && $guruX > 0 && $y > 0 && $waliA > 0 && $tahun > 0 && $murobiYKamar > 0 && $pembimbingLamaKamar > 0,
    'fixture tersedia: dua admin, akun X (guru), murobi Y kamar 381, wali V7, pembimbing lama kamar 381');

// --- Penyiapan: X menjadi pembimbing DAN satu-satunya murobi kamar 381 ---------
$jalankan("UPDATE pembimbing_assignments SET is_active = 0 WHERE id = $pembimbingLamaKamar");
$jalankan("UPDATE murobi_assignments SET is_active = 0 WHERE id = $murobiYKamar");
$svc->buat(PenugasanJenis::PEMBIMBING, ['pengurus_id' => 'guru:' . $guruX, 'tahun_ajaran_id' => $tahun, 'target_type' => 'Kamar', 'kamar_id' => $kamar, 'tanggal_mulai' => $hari], $admin);
$svc->buat(PenugasanJenis::MUROBI, ['guru_id' => $guruX, 'tahun_ajaran_id' => $tahun, 'target_type' => 'Kamar', 'kamar_id' => $kamar, 'tanggal_mulai' => $hari], $admin);
$identitasX = (int) $satu("SELECT pengurus_id FROM users WHERE id = $x");
$fitur = $caps->forUser($akun($x));
$assert($identitasX > 0 && in_array(Capabilities::PENGURUS, $fitur, true) && in_array(Capabilities::MUROBI, $fitur, true),
    'penyiapan: akun X memegang mode Pengurus (identitas milik guru) dan Murobi sekaligus');
$assert(count($router->candidates($santriCadangan, $hari)) === 1 && (int) $router->candidates($santriCadangan, $hari)[0]['guru_id'] === $guruX,
    'penyiapan: X satu-satunya kandidat murobi santri kamar 381 (tanpa pengecualian)');

// --- J-1 routing V2 ----------------------------------------------------------------
$buatV2 = $alur->create($akun($x), ['santri_id' => $santriCadangan, 'tgl_izin' => $tanggal(1), 'tgl_kembali' => $tanggal(2), 'alasan' => 'Uji pemisahan tugas'], $kunci('v2a'), $meta, Capabilities::PENGURUS);
$izinA = (int) $buatV2['id'];
$baris = $db->query("SELECT * FROM izin_pengajuan WHERE id = $izinA")->fetch_assoc();
$assert($buatV2['status'] === IzinRouter::STATUS_PERLU_ADMIN && $baris['murobi_guru_id'] === null && (int) $baris['routing_kandidat'] === 0
    && str_contains((string) $baris['routing_catatan'], 'dikecualikan'),
    'J-1a pengajuan oleh X: satu-satunya murobi = X sendiri → Perlu Penetapan Admin, catatan routing menyebut pengecualian');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'izin_routing_resolved' AND entity_id = $izinA AND after_json LIKE '%dikecualikan%'") === 1,
    'J-1b audit routing mencatat catatan pengecualian');
$pratinjau = $alur->routingPreview($akun($x), $santriCadangan, Capabilities::PENGURUS);
$assert($pratinjau['murobi'] === [] && $pratinjau['perlu_admin'] === true, 'J-1c pratinjau tujuan bagi X juga mengecualikan dirinya');
$jalankan("UPDATE murobi_assignments SET is_active = 1 WHERE id = $murobiYKamar");
$tanpa = $router->resolve($santriSatu, $hari);
$dengan = $router->resolve($santriSatu, $hari, [$guruX]);
$assert($tanpa['jumlah'] === 2 && $tanpa['status'] === IzinRouter::STATUS_PERLU_ADMIN && $dengan['jumlah'] === 1 && $dengan['murobi_guru_id'] === $guruY && $dengan['dikecualikan'] === 1,
    'J-1d router: dua kandidat (X dan Y) tetap ke admin tanpa pengecualian; dengan X dikecualikan → Y');
$buatV2b = $alur->create($akun($x), ['santri_id' => $santriSatu, 'tgl_izin' => $tanggal(1), 'tgl_kembali' => $tanggal(2), 'alasan' => 'Uji dua murobi'], $kunci('v2b'), $meta, Capabilities::PENGURUS);
$izinB = (int) $buatV2b['id'];
$assert($buatV2b['status'] === IzinRouter::STATUS_TERARAH && (int) $buatV2b['murobi_guru_id'] === $guruY && str_contains((string) $buatV2b['routing_catatan'], 'dikecualikan'),
    'J-1e pengajuan X dengan murobi X dan Y → langsung diarahkan ke Y');
$keputusanB = $alur->decide($akun($y), $izinB, 'Disetujui', 'Disetujui murobi Y', null, null, $kunci('v2b-putus'), $meta, Capabilities::MUROBI);
$assert($keputusanB['status'] === 'Disetujui', 'J-1f murobi Y (tidak terlibat) memutus seperti biasa');
$jalankan("UPDATE murobi_assignments SET is_active = 0 WHERE id = $murobiYKamar");

// --- J-2 penetapan admin --------------------------------------------------------------
$berhak = array_map(static fn (array $g): int => (int) $g['guru_id'], $alur->eligibleMurobi($izinA));
$berhakUmum = array_map(static fn (array $g): int => (int) $g['guru_id'], $alur->eligibleMurobi());
$assert(!in_array($guruX, $berhak, true) && in_array($guruX, $berhakUmum, true) && in_array($guruY, $berhak, true),
    'J-2a daftar murobi berhak pengajuan X tidak memuat X (daftar umum tetap memuat X)');
$assert(array_filter($alur->routingCandidates($baris), static fn (array $g): bool => (int) $g['guru_id'] === $guruX) === [],
    'J-2b kandidat routing yang ditampilkan admin tidak memuat X');
[$st, $pesan] = $coba(fn () => $alur->assignMurobi($akun($admin), $izinA, $guruX, 'Tetapkan X', null, $kunci('v2a-tetap-x'), $meta));
$assert($st === 422 && str_contains($pesan, 'pemisahan tugas') && $satu("SELECT murobi_guru_id FROM izin_pengajuan WHERE id = $izinA") === null,
    'J-2c admin menetapkan X sebagai murobi pengajuan X → 422 tanpa perubahan');

// --- J-3 keputusan oleh pengaju ---------------------------------------------------------
[$st] = $coba(fn () => $alur->decide($akun($x), $izinA, 'Disetujui', 'Putus sendiri', null, null, $kunci('v2a-putus-x'), $meta, Capabilities::MUROBI));
$assert($st === 403, 'J-3a X memutus pengajuannya yang belum diarahkan → 403');
// Data dipaksa: seolah routing lama/race mengarahkan ke X.
$jalankan("UPDATE izin_pengajuan SET murobi_guru_id = $guruX, status = 'Diajukan' WHERE id = $izinA");
[$st, $pesan] = $coba(fn () => $alur->decide($akun($x), $izinA, 'Disetujui', 'Putus sendiri', null, null, $kunci('v2a-putus-x2'), $meta, Capabilities::MUROBI));
$assert($st === 403 && str_contains($pesan, 'mengajukan atau meninjau') && (int) $satu("SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id = $izinA") === 0
    && $satu("SELECT status FROM izin_pengajuan WHERE id = $izinA") === 'Diajukan',
    'J-3b pengajuan dipaksa diarahkan ke X: keputusan X tetap 403, tanpa baris keputusan');
$barisPaksa = izin_service()->detail($akun($x), $izinA, Capabilities::MUROBI);
$aksiTanpa = $alur->actionsFor($barisPaksa['pengajuan'], $barisPaksa['scope']);
$aksiDengan = $alur->actionsFor($barisPaksa['pengajuan'], $barisPaksa['scope'], $x);
$assert($aksiTanpa['putuskan_murobi'] === true && $aksiDengan['putuskan_murobi'] === false && array_keys($aksiDengan) === array_keys($aksiTanpa),
    'J-3c tombol Putuskan disembunyikan bagi pengaju; kunci aksi tetap sama (kontrak aplikasi)');
$assert($alur->terlibat($x, $izinA) && !$alur->terlibat($y, $izinA), 'J-3d penanda terlibat: X ya, Y tidak');
$jalankan("UPDATE izin_pengajuan SET murobi_guru_id = NULL, status = 'Perlu Penetapan Admin' WHERE id = $izinA");
$versiA = (int) $satu("SELECT version FROM izin_pengajuan WHERE id = $izinA");
$alur->assignMurobi($akun($admin), $izinA, $guruY, 'Tetapkan murobi lain', $versiA, $kunci('v2a-tetap-y'), $meta);
$putusA = $alur->decide($akun($y), $izinA, 'Ditolak', 'Ditolak murobi lain', null, null, $kunci('v2a-putus-y'), $meta, Capabilities::MUROBI);
$assert($putusA['status'] === 'Ditolak', 'J-3e admin menetapkan Y; Y memutus pengajuan X');

// --- J-4 admin pengganti (PK-T6) ---------------------------------------------------------
$buatAdmin = $alur->create($akun($admin), ['santri_id' => $santriTanpaMurobi, 'tgl_izin' => $tanggal(3), 'tgl_kembali' => $tanggal(4), 'alasan' => 'Dibuat admin'], $kunci('v2c'), $meta, Capabilities::ADMIN);
$izinC = (int) $buatAdmin['id'];
[$st] = $coba(fn () => $alur->decide($akun($admin), $izinC, 'Disetujui', 'Putus sendiri', 'Tidak ada murobi', null, $kunci('v2c-putus-a'), $meta, Capabilities::ADMIN));
$detailC = izin_service()->detail($akun($admin), $izinC, Capabilities::ADMIN);
$assert($st === 403 && $alur->actionsFor($detailC['pengajuan'], $detailC['scope'], $admin)['putuskan_admin'] === false
    && $alur->actionsFor($detailC['pengajuan'], $detailC['scope'], $adminLain)['putuskan_admin'] === true,
    'J-4a admin pembuat tidak memutus sebagai admin pengganti (403, tombol tersembunyi); admin lain melihat tombol');
$putusC = $alur->decide($akun($adminLain), $izinC, 'Disetujui', 'Diputus admin lain', 'Tidak ada murobi', null, $kunci('v2c-putus-b'), $meta, Capabilities::ADMIN);
$assert($putusC['status'] === 'Disetujui' && $putusC['kapasitas'] === 'Admin Pengganti', 'J-4b admin lain memutus sebagai admin pengganti');
[$st] = $coba(fn () => $alur->correctDecision($akun($admin), $izinC, 'Ditolak', 'Koreksi sendiri', 'Salah putus', null, $kunci('v2c-koreksi-a'), $meta));
$assert($st === 403 && $satu("SELECT status FROM izin_pengajuan WHERE id = $izinC") === 'Disetujui', 'J-4c admin pembuat tidak mengoreksi keputusan pengajuannya → 403');
$koreksi = $alur->correctDecision($akun($adminLain), $izinC, 'Ditolak', 'Dikoreksi admin lain', 'Perbaikan data', null, $kunci('v2c-koreksi-b'), $meta);
$assert($koreksi['status'] === 'Ditolak', 'J-4d admin lain mengoreksi keputusan');

// --- J-5 V7: penerusan pembimbing ---------------------------------------------------------
$v7 = $wali->buat($akun($waliA), ['santri_id' => $santriCadangan, 'tgl_izin' => $tanggal(10), 'tgl_kembali' => $tanggal(11), 'alasan' => 'Izin keluarga V7'], $kunci('v7a'), $meta);
$izinW = (int) $v7['id'];
$assert($v7['status'] === 'Menunggu Pembimbing' && (int) $satu("SELECT pembimbing_pengurus_id FROM izin_pengajuan WHERE id = $izinW") === $identitasX,
    'J-5a pengajuan wali diarahkan ke X sebagai pembimbing tunggal kamar 381');
$terus = $wali->tinjau($akun($x), $izinW, 'teruskan', null, 1, $kunci('v7a-terus'), $meta);
$barisW = $db->query("SELECT * FROM izin_pengajuan WHERE id = $izinW")->fetch_assoc();
$assert($terus['status'] === IzinRouter::STATUS_PERLU_ADMIN && $barisW['murobi_guru_id'] === null && str_contains((string) $barisW['routing_catatan'], 'dikecualikan')
    && (int) $satu("SELECT COUNT(*) FROM izin_pembimbing_tinjauan WHERE pengajuan_id = $izinW AND murobi_guru_id IS NULL AND murobi_routing_kandidat = 0") === 1,
    'J-5b X meneruskan: X (peninjau) dikecualikan dari routing murobi → Perlu Penetapan Admin');
$assert(!in_array($guruX, array_map(static fn (array $g): int => (int) $g['guru_id'], $alur->eligibleMurobi($izinW)), true),
    'J-5c daftar murobi berhak pengajuan wali tidak memuat peninjau X');
$versiW = (int) $barisW['version'];
[$st] = $coba(fn () => $alur->assignMurobi($akun($admin), $izinW, $guruX, 'Tetapkan X', $versiW, $kunci('v7a-tetap-x'), $meta));
$assert($st === 422 && (int) $satu("SELECT version FROM izin_pengajuan WHERE id = $izinW") === $versiW, 'J-5d admin menetapkan peninjau X sebagai murobi → 422');
$jalankan("UPDATE izin_pengajuan SET murobi_guru_id = $guruX, status = 'Diajukan' WHERE id = $izinW");
[$st, $pesan] = $coba(fn () => $alur->decide($akun($x), $izinW, 'Disetujui', 'Putus sendiri', null, $versiW, $kunci('v7a-putus-x'), $meta, Capabilities::MUROBI));
$assert($st === 403 && str_contains($pesan, 'mengajukan atau meninjau') && (int) $satu("SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id = $izinW") === 0,
    'J-5e pengajuan wali dipaksa diarahkan ke peninjau X: keputusan X → 403 (pesan pemisahan tugas)');
$jalankan("UPDATE izin_pengajuan SET murobi_guru_id = NULL, status = 'Perlu Penetapan Admin' WHERE id = $izinW");
$jalankan("UPDATE murobi_assignments SET is_active = 1 WHERE id = $murobiYKamar");
$alur->assignMurobi($akun($admin), $izinW, $guruY, 'Murobi kamar lain', $versiW, $kunci('v7a-tetap-y'), $meta);
$putusW = $alur->decide($akun($y), $izinW, 'Disetujui', 'Disetujui Y', null, $versiW + 1, $kunci('v7a-putus-y'), $meta, Capabilities::MUROBI);
$assert($putusW['status'] === 'Disetujui', 'J-5f admin menetapkan Y yang mencakup santri; Y memutus pengajuan wali');
$v7b = $wali->buat($akun($waliA), ['santri_id' => $santriSatu, 'tgl_izin' => $tanggal(12), 'tgl_kembali' => $tanggal(13), 'alasan' => 'Izin kedua V7'], $kunci('v7b'), $meta);
$terusB = $wali->tinjau($akun($x), (int) $v7b['id'], 'teruskan', null, 1, $kunci('v7b-terus'), $meta);
$assert($terusB['status'] === IzinRouter::STATUS_TERARAH && (int) $terusB['murobi_guru_id'] === $guruY,
    'J-5g murobi X dan Y aktif: penerusan X langsung diarahkan ke Y');
$detailAdminW = $wali->detail($akun($admin), (int) $v7b['id']);
$assert(($detailAdminW['tindakan']['putuskan_pengganti'] ?? null) === true, 'J-5h admin yang tidak terlibat tetap melihat tindakan putuskan pengganti V7');

// --- J-6 sikap V4 (PK-T7) -------------------------------------------------------------------
$kelas = 333;
$rencana = (int) $satu("SELECT id FROM v4_rencana WHERE kelas_id = $kelas AND tahun_ajaran_id = $tahun");
$santriV4 = 1694;
$santriV4b = 1695;
$murobiKelasC = (int) $satu("SELECT id FROM murobi_assignments WHERE kelas_id = $kelas AND tahun_ajaran_id = $tahun AND is_active = 1 ORDER BY id LIMIT 1");
$svc->buat(PenugasanJenis::PEMBIMBING, ['pengurus_id' => 'guru:' . $guruX, 'tahun_ajaran_id' => $tahun, 'target_type' => 'Kelas', 'kelas_id' => $kelas, 'tanggal_mulai' => $hari], $admin);
$svc->buat(PenugasanJenis::MUROBI, ['guru_id' => $guruX, 'tahun_ajaran_id' => $tahun, 'target_type' => 'Kelas', 'kelas_id' => $kelas, 'tanggal_mulai' => $hari], $admin);
$sikap = new SikapService($db, audit_logger());
$verif = new VerifikasiService($db, audit_logger());
$isiSikap = ['rencana_id' => $rencana, 'santri_id' => $santriV4, 'adab' => 'Baik', 'kedisiplinan' => 'Baik', 'tanggung_jawab' => 'Cukup', 'catatan' => 'Isi X'];
$r = $sikap->save($akun($x), $isiSikap + ['version' => 0, 'action' => 'serahkan'], 'pembimbing');
$versiSikap = (int) $r['sikap']['version'];
$assert($rencana > 0 && $murobiKelasC > 0 && $r['sikap']['status'] === 'Dikirim' && (int) $r['sikap']['pembimbing_user_id'] === $x,
    'J-6a X (pembimbing kelas 333) mengisi dan menyerahkan sikap santri');
$bacaMurobi = $sikap->read($akun($x), $rencana, $santriV4, 'murobi');
$assert($bacaMurobi['diisi_sendiri'] === true && in_array($x, $bacaMurobi['pengisi_pembimbing'], true), 'J-6b pembacaan murobi X menandai sikap diisi sendiri');
[$st, $pesan] = $coba(fn () => $sikap->save($akun($x), ['rencana_id' => $rencana, 'santri_id' => $santriV4, 'version' => $versiSikap, 'action' => 'diketahui', 'alasan' => ''], 'murobi'));
$assert($st === 403 && str_contains($pesan, 'pemisahan tugas') && $satu("SELECT diketahui_murobi_user_id FROM v4_sikap WHERE santri_id = $santriV4 AND tahun_ajaran_id = $tahun") === null,
    'J-6c X sebagai murobi mengetahui sikap isiannya sendiri → 403 tanpa perubahan');
[$st] = $coba(fn () => $sikap->save($akun($x), $isiSikap + ['version' => $versiSikap, 'action' => 'koreksi', 'alasan' => 'Koreksi murobi', 'adab' => 'Sangat Baik'], 'murobi'));
$assert($st === 403 && (int) $satu("SELECT version FROM v4_sikap WHERE santri_id = $santriV4 AND tahun_ajaran_id = $tahun") === $versiSikap,
    'J-6d X sebagai murobi mengoreksi (yang otomatis berarti mengetahui) → 403');
$opsiX = $sikap->options($akun($x), 'murobi');
$hitung = static fn (array $rows): int => count(array_filter($rows, static fn (array $o): bool => (int) $o['santri_id'] === $santriV4));
$assert($hitung($sikap->filterOptions($opsiX, 'perlu_diketahui')) === 1 && $hitung($sikap->filterOptions($opsiX, 'perlu_diketahui', $x)) === 0,
    'J-6e saringan "perlu diketahui" bagi X tidak menghitung sikap isiannya sendiri');
$materiSikap = static function () use ($verif, $akun, $pendidikanLain, $rencana, $santriV4): array {
    foreach ($verif->read($akun($pendidikanLain), $rencana, $santriV4)['matriks'][0]['bahan'] as $m) {
        if ($m['jenis'] === 'sikap') {
            return $m;
        }
    }
    throw new RuntimeException('bahan sikap tidak ada');
};
$r = $sikap->save($akun($murobiC), ['rencana_id' => $rencana, 'santri_id' => $santriV4, 'version' => $versiSikap, 'action' => 'diketahui', 'alasan' => ''], 'murobi');
$assert((int) $r['sikap']['diketahui_murobi_user_id'] === $murobiC && $materiSikap()['diketahui_sah'] === true,
    'J-6f murobi kelas lain (tidak mengisi) mengetahui; matriks Pendidikan menganggapnya sah');
$jalankan("UPDATE v4_sikap SET diketahui_murobi_user_id = $x WHERE santri_id = $santriV4 AND tahun_ajaran_id = $tahun");
$assert($materiSikap()['diketahui_sah'] === false, 'J-6g tanda diketahui oleh pengisi sendiri (data lama/paksa) tidak dihitung sah');
$jalankan("UPDATE murobi_assignments SET is_active = 0 WHERE id = $murobiKelasC");
$caps->forget($murobiC);
$m = $materiSikap();
$assert(!$m['lengkap'] && str_contains((string) $m['penghalang'], 'pemisahan tugas'),
    'J-6h seluruh murobi aktif = pengisi: bahan tertahan dengan penghalang pemisahan tugas (tanpa jalur pengganti)');
$jalankan("UPDATE murobi_assignments SET is_active = 1 WHERE id = $murobiKelasC");
$jalankan("UPDATE v4_sikap SET diketahui_murobi_user_id = $murobiC WHERE santri_id = $santriV4 AND tahun_ajaran_id = $tahun");

// --- J-7 PK-T1 / PK-T5 -----------------------------------------------------------------------
$rmX = (int) $satu("SELECT rm.id FROM v4_rencana_mapel rm WHERE rm.rencana_id = $rencana ORDER BY rm.id DESC LIMIT 1");
$mapelX = (int) $satu("SELECT mata_pelajaran_id FROM v4_rencana_mapel WHERE id = $rmX");
$rmLain = (int) $satu("SELECT rm.id FROM v4_rencana_mapel rm WHERE rm.rencana_id = $rencana AND rm.id <> $rmX ORDER BY rm.id LIMIT 1");
$svc->buat(PenugasanJenis::GURU_MAPEL, ['guru_id' => $guruX, 'mata_pelajaran_id' => $mapelX, 'kelas_id' => $kelas, 'tahun_ajaran_id' => $tahun, 'tanggal_mulai' => $hari], $admin);
$svc->buat(PenugasanJenis::PENDIDIKAN, ['pengurus_id' => 'guru:' . $guruX, 'tahun_ajaran_id' => $tahun, 'jenjang' => '', 'tanggal_mulai' => $hari], $admin);
$nilai = new NilaiService($db, $caps, audit_logger());
$komponen = [['komponen' => 'Harian', 'nomor_harian' => 1, 'nilai' => 80, 'version' => 0, 'catatan' => ''], ['komponen' => 'Tengah', 'nomor_harian' => 0, 'nilai' => 82, 'version' => 0, 'catatan' => ''], ['komponen' => 'Akhir', 'nomor_harian' => 0, 'nilai' => 85, 'version' => 0, 'catatan' => '']];
$nilai->save($akun($x), ['rencana_mapel_id' => $rmX, 'santri_id' => $santriV4, 'komponen' => $komponen, 'action' => 'serahkan']);
$bahan = static function (int $userId, int $santri, int $rm) use ($verif, $akun, $rencana): array {
    $baca = $verif->read($akun($userId), $rencana, $santri);
    foreach ($baca['matriks'][0]['bahan'] as $m) {
        if ($m['jenis'] === 'nilai' && $m['mapel_key'] === $rm) {
            return [$m, $baca];
        }
    }
    throw new RuntimeException('bahan nilai tidak ada');
};
$aksiBahan = static function (int $userId, int $santri, int $rm, string $aksi, string $alasan = '') use ($verif, $akun, $rencana, $bahan): array {
    [$m] = $bahan($userId, $santri, $rm);

    return $verif->act($akun($userId), ['rencana_id' => $rencana, 'santri_id' => $santri, 'jenis' => 'nilai', 'mapel_key' => $rm, 'action' => $aksi, 'alasan' => $alasan] + $m['pemeriksaan']);
};
[$mX, $bacaX] = $bahan($x, $santriV4, $rmX);
$assert($mX['pemeriksaan']['status'] === 'Dikirim' && in_array($santriV4 . ':' . $rmX, $bacaX['diisi_sendiri'], true),
    'J-7a X (Guru Mapel) menyerahkan nilai; matriks X (Pendidikan) menandai bahan itu diisi sendiri');
[$st, $pesan] = $coba(fn () => $aksiBahan($x, $santriV4, $rmX, 'verifikasi'));
$assert($st === 403 && str_contains($pesan, 'pemisahan tugas') && $bahan($pendidikanLain, $santriV4, $rmX)[0]['pemeriksaan']['status'] === 'Dikirim',
    'J-7b X sebagai Pendidikan memverifikasi nilai isiannya sendiri → 403 tanpa perubahan');
[$st] = $coba(fn () => $aksiBahan($x, $santriV4, $rmX, 'kembalikan', 'Kembalikan sendiri'));
$assert($st === 403, 'J-7c X mengembalikan nilai isiannya sendiri → 403');
$bacaLain = $bahan($pendidikanLain, $santriV4, $rmX)[1];
$assert(!in_array($santriV4 . ':' . $rmX, $bacaLain['diisi_sendiri'], true) && $bacaX['matriks_hash'] !== null && $bacaLain['matriks_hash'] === $bacaX['matriks_hash'],
    'J-7d matriks bahan sama bagi kedua Pendidikan; penanda diisi sendiri di luar matriks (sidik jari tidak bergantung pembaca)');
$aksiBahan($pendidikanLain, $santriV4, $rmX, 'verifikasi');
$assert($bahan($pendidikanLain, $santriV4, $rmX)[0]['pemeriksaan']['status'] === 'Terverifikasi', 'J-7e Pendidikan lain memverifikasi nilai X');
// Kontrol: nilai isian Pendidikan lain (pengganti) boleh diverifikasi X; nilai isian X sebagai pengganti tidak.
$nilai->save($akun($pendidikanLain), ['rencana_mapel_id' => $rmX, 'santri_id' => $santriV4b, 'komponen' => $komponen, 'action' => 'serahkan', 'alasan' => 'Guru berhalangan'], true);
$aksiBahan($x, $santriV4b, $rmX, 'verifikasi');
$assert($bahan($x, $santriV4b, $rmX)[0]['pemeriksaan']['status'] === 'Terverifikasi', 'J-7f X memverifikasi nilai yang tidak ia isi (per bahan, PK-T5)');
$nilai->save($akun($x), ['rencana_mapel_id' => $rmLain, 'santri_id' => $santriV4b, 'komponen' => $komponen, 'action' => 'serahkan', 'alasan' => 'Guru berhalangan'], true);
[$st] = $coba(fn () => $aksiBahan($x, $santriV4b, $rmLain, 'verifikasi'));
$assert($st === 403, 'J-7g nilai isian X sebagai Pendidikan pengganti juga tidak ia verifikasi');
$aksiBahan($admin, $santriV4b, $rmLain, 'verifikasi', 'Diverifikasi admin');
$assert($bahan($admin, $santriV4b, $rmLain)[0]['pemeriksaan']['status'] === 'Terverifikasi', 'J-7h admin tetap jalur pengganti verifikasi');

// --- J-8 V3: tanda mengetahui murobi ----------------------------------------------------------
$katalog = (int) $satu('SELECT id FROM v3_katalog WHERE is_active = 1 AND archived_at IS NULL AND tanggal_mulai <= CURDATE() AND (tanggal_selesai IS NULL OR tanggal_selesai >= CURDATE()) ORDER BY id LIMIT 1');
$pelanggaran = v3_pelanggaran_service();
$konseling = v3_konseling_service();
$catat = $pelanggaran->create($akun($x), ['santri_id' => $santriSatu, 'tahun_ajaran_id' => $tahun, 'katalog_id' => $katalog, 'waktu_kejadian' => date('Y-m-d H:i', time() - 3600), 'uraian' => 'Uji pemisahan tugas V3', 'idempotency_key' => $kunci('v3p')]);
$idPel = (int) $catat['data']['pelanggaran']['id'];
[$st, $pesan] = $coba(fn () => $pelanggaran->acknowledge($akun($x), $idPel, ['idempotency_key' => $kunci('v3p-ack-x')]));
$assert($idPel > 0 && $st === 403 && str_contains($pesan, 'pemisahan tugas') && $pelanggaran->milikSendiri($akun($x), $idPel)
    && (int) $satu("SELECT COUNT(*) FROM v3_murobi_catatan WHERE pelanggaran_id = $idPel") === 0,
    'J-8a X mencatat pelanggaran sebagai pembimbing; tanda mengetahui X sebagai murobi → 403');
$ack = $pelanggaran->acknowledge($akun($y), $idPel, ['idempotency_key' => $kunci('v3p-ack-y')]);
$assert($ack['status'] === 201 && !$pelanggaran->milikSendiri($akun($y), $idPel), 'J-8b murobi Y (bukan pencatat) menandai mengetahui');
$kasus = $konseling->createCase($akun($x), ['santri_id' => $santriSatu, 'tahun_ajaran_id' => $tahun, 'tujuan' => 'Pendampingan uji', 'kerahasiaan' => 'Internal', 'idempotency_key' => $kunci('v3k')]);
$idKasus = (int) $kasus['data']['kasus']['id'];
[$st] = $coba(fn () => $konseling->acknowledge($akun($x), 'kasus', $idKasus, ['idempotency_key' => $kunci('v3k-ack-x')]));
$assert($st === 403 && $konseling->milikSendiri($akun($x), 'kasus', $idKasus), 'J-8c X mengetahui kasus Internal miliknya sendiri → 403');
$sesi = $konseling->createSession($akun($x), $idKasus, ['jadwal' => date('Y-m-d\TH:i', time() + 3600), 'tindak_lanjut' => 'Sesi uji', 'idempotency_key' => $kunci('v3s')]);
$idSesi = (int) $sesi['data']['sesi']['id'];
[$st] = $coba(fn () => $konseling->acknowledge($akun($x), 'sesi', $idSesi, ['idempotency_key' => $kunci('v3s-ack-x')]));
$assert($st === 403, 'J-8d X mengetahui sesi kasusnya sendiri → 403');
$ackK = $konseling->acknowledge($akun($y), 'kasus', $idKasus, ['idempotency_key' => $kunci('v3k-ack-y')]);
$ackS = $konseling->acknowledge($akun($y), 'sesi', $idSesi, ['idempotency_key' => $kunci('v3s-ack-y')]);
$assert($ackK['status'] === 201 && $ackS['status'] === 201 && !$konseling->milikSendiri($akun($y), 'sesi', $idSesi), 'J-8e murobi Y mengetahui kasus dan sesi');
$assert((int) $satu("SELECT COUNT(*) FROM v3_murobi_catatan WHERE created_by = $x") === 0, 'J-8f tidak ada satu pun tanda mengetahui oleh X');

// --- J-9 kontrol akun tak terlibat ---------------------------------------------------------------
$pengurusB = $id('sbx_pengurus_b');
$buatKontrol = $alur->create($akun($pengurusB), ['santri_id' => 3, 'tgl_izin' => $tanggal(20), 'tgl_kembali' => $tanggal(21), 'alasan' => 'Kontrol pengurus biasa'], $kunci('kontrol'), $meta, Capabilities::PENGURUS);
$kontrolResolve = $router->resolve(3, $hari);
$assert($buatKontrol['status'] === $kontrolResolve['status'] && $buatKontrol['murobi_guru_id'] === $kontrolResolve['murobi_guru_id'] && !str_contains((string) $buatKontrol['routing_catatan'], 'dikecualikan'),
    'J-9a pengurus biasa (bukan guru): routing identik dengan router tanpa pengecualian');
$murobiKontrol = (int) $satu('SELECT id FROM users WHERE guru_id = ' . (int) $buatKontrol['murobi_guru_id']);
$hasilKontrol = $alur->decide($akun($murobiKontrol), (int) $buatKontrol['id'], 'Disetujui', 'Kontrol', null, null, $kunci('kontrol-putus'), $meta, Capabilities::MUROBI);
$assert($buatKontrol['status'] === IzinRouter::STATUS_TERARAH && $hasilKontrol['status'] === 'Disetujui', 'J-9b murobi tujuan pengajuan pengurus biasa memutus seperti biasa');

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
