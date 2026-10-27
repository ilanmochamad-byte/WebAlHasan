<?php

declare(strict_types=1);

/**
 * Uji integrasi PRD V7 Fase 2 — tinjauan pembimbing, perbaikan/pembatalan
 * wali pengaju, penerusan ke routing murobi V2, dan keputusan akhir V2 di atas
 * DB uji `tests/v7_phase2_db.sh` (fixture sintetis), termasuk konkurensi
 * proses terpisah lewat `tests/v7_phase2_worker.php`.
 *
 *   bash tests/v7_phase2_db.sh && php tests/v7_phase2_integration.php
 *
 *   J-1  minta perbaikan → hanya wali pengaju memperbaiki → teruskan → murobi memutus;
 *        satu ID, versi sebelum/sesudah, riwayat, audit tanpa isi
 *   J-2  penolakan pembimbing: terminal tanpa izin_keputusan, rentang dilepas lintas jalur
 *   J-3  routing murobi nol/dua → antrean admin (V7 dan V2); penetapan murobi dan
 *        admin pengganti beralasan memakai aturan V2
 *   J-4  murobi di luar penugasan dan peran lain ditolak
 *   J-5  versi lama, kunci konflik, retry, isi tidak berubah, benturan saat perbaikan
 *   J-6  pembatalan wali pengaju pada setiap tahap; wali lain/admin V2 tidak dapat
 *   J-7  pencabutan penugasan/akun/relasi pada sesi lama
 *   J-8  permukaan V2: orang tua tidak melihat baris wali, aksi batal V2 tersembunyi,
 *        notifikasi V2 tidak dipancarkan untuk baris wali
 *   J-9  konkurensi proses terpisah: batal vs teruskan, batal vs keputusan,
 *        klik ganda tinjauan/batal, keputusan murobi vs admin pengganti
 *   J-10 jalur pengurus V2 tetap membuat, membatalkan, memutus, mengoreksi
 *   J-11 CHECK basis data 031
 *   J-12 verifier Fase 2 sesudah seluruh uji
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$dbName = getenv('V7F2_DB') ?: 'test_v7_f2_test';
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
$akun = static function (string $username) use ($satu, $db): array {
    $id = (int) $satu("SELECT id FROM users WHERE username = '$username'");
    $guru = $satu("SELECT guru_id FROM users WHERE id = $id");
    $roles = array_column($db->query("SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = $id")->fetch_all(MYSQLI_ASSOC), 'slug');

    return ['id' => $id, 'roles' => $roles, 'guru_id' => $guru === null ? null : (int) $guru];
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
$meta = ['ip' => '127.0.0.1', 'user_agent' => 'uji-v7-f2'];
$svc = static fn () => izin_wali_service();
$v2 = static fn () => izin_workflow_service();
$versi = static fn (int $id): int => (int) $satu("SELECT version FROM izin_pengajuan WHERE id = $id");
$status = static fn (int $id): string => (string) $satu("SELECT status FROM izin_pengajuan WHERE id = $id");
$hitung = static fn (string $tabel, string $where): int => (int) $satu("SELECT COUNT(*) FROM $tabel WHERE $where");
$isi = static fn (int $santri, string $dari, string $sampai, string $alasan = 'Acara keluarga besar', ?string $catatan = null): array =>
    ['santri_id' => $santri, 'tgl_izin' => $dari, 'tgl_kembali' => $sampai, 'alasan' => $alasan, 'catatan' => $catatan];
$kunciNo = 0;
$kunci = static function (string $awal) use (&$kunciNo): string {
    $kunciNo++;

    return 'v7f2-' . $awal . '-' . str_pad((string) $kunciNo, 4, '0', STR_PAD_LEFT);
};

$wA = $akun('sbx_v7_wali_a');
$wB = $akun('sbx_v7_wali_b');
$wAsing = $akun('sbx_ortu_b');
$pb1 = $akun('sbx_v7_pembimbing_satu');
$pb2 = $akun('sbx_v7_pembimbing_dua');
$admin = $akun('sbx_admin');
$murobiA = $akun('sbx_murobi_a');
$murobiB = $akun('sbx_murobi_b');
$pengurusV2 = $akun('sbx_pengurus_a');
$sSatu = $sid('V7S0001');
$sNol = $sid('V7S0002');
$sCadangan = $sid('V7S0008');
$sTanpaMurobi = $sid('V7S0006');
$sDuaMurobi = $sid('V7S0007');
$assert(min($wA['id'], $wB['id'], $pb1['id'], $admin['id'], $murobiA['id'], $murobiB['id'], $sSatu, $sCadangan, $sTanpaMurobi, $sDuaMurobi) > 0
    && (int) $murobiA['guru_id'] > 0 && (int) $murobiB['guru_id'] > 0
    && $hitung('izin_pengajuan', "asal_pengajuan = 'Wali'") === 0 && $hitung('izin_pembimbing_tinjauan', '1') === 0,
    'Fixture V7 Fase 2 tersedia; belum ada pengajuan wali atau tinjauan');
$v2Sebelum = (string) $satu("SELECT SHA2(GROUP_CONCAT(CONCAT_WS(':', id, status, version) ORDER BY id), 256) FROM izin_pengajuan");
$maxV2Sebelum = (int) $satu('SELECT MAX(id) FROM izin_pengajuan');
$outboxSebelum = $hitung('notifikasi_outbox', '1');

// ===================================================================== J-1
echo PHP_EOL . '=== J-1. Minta perbaikan, perbaikan wali pengaju, penerusan, keputusan murobi ===' . PHP_EOL;
$jumlahAwal = $hitung('izin_pengajuan', '1');
$h = $svc()->buat($wA, $isi($sCadangan, '2026-12-01', '2026-12-02', 'Menghadiri acara keluarga', 'Dijemput paman'), $kunci('buat'), $meta);
$id1 = (int) $h['id'];
$assert($h['status'] === 'Menunggu Pembimbing' && $versi($id1) === 1, 'Pengajuan wali #' . $id1 . ' menunggu Pembimbing Satu (versi 1)');
$assert($galat(fn () => $svc()->perbaiki($wA, $id1, ['tgl_izin' => '2026-12-01', 'tgl_kembali' => '2026-12-03', 'alasan' => 'Ubah sendiri'], 1, $kunci('perbaiki'), $meta)) === 409,
    'Wali pengaju tidak dapat memperbaiki sebelum pembimbing meminta (409)');
$dPb = $svc()->detail($pb1, $id1);
$assert($dPb['peran_akses'] === 'pembimbing' && $dPb['tindakan'] === ['minta_perbaikan' => true, 'tolak' => true, 'teruskan' => true], 'Rincian pembimbing tujuan menawarkan tiga tindakan tinjauan');
$assert($galat(fn () => $svc()->tinjau($pb1, $id1, 'minta_perbaikan', '', 1, $kunci('tinjau'), $meta)) === 422
    && $galat(fn () => $svc()->tinjau($pb1, $id1, 'setujui', 'x', 1, $kunci('tinjau'), $meta)) === 422
    && $galat(fn () => $svc()->tinjau($pb1, $id1, 'minta_perbaikan', 'Perjelas', null, $kunci('tinjau'), $meta)) === 422,
    'Minta perbaikan tanpa catatan, tindakan tak dikenal, atau tanpa versi → 422');
$kMinta = $kunci('minta');
$t1 = $svc()->tinjau($pb1, $id1, 'minta_perbaikan', 'Mohon cantumkan tanggal kembali yang pasti', 1, $kMinta, $meta);
$assert($t1['status'] === 'Perlu Perbaikan Wali' && $t1['version'] === 2 && $versi($id1) === 2 && $t1['idempotent_replay'] === false, 'Minta perbaikan → Perlu Perbaikan Wali, versi 1→2');
$t1b = $svc()->tinjau($pb1, $id1, 'minta_perbaikan', 'Mohon cantumkan tanggal kembali yang pasti', 1, $kMinta, $meta);
$assert($t1b['idempotent_replay'] === true && $t1b['tinjauan_id'] === $t1['tinjauan_id'] && $hitung('izin_pembimbing_tinjauan', "pengajuan_id = $id1") === 1,
    'Kiriman ulang kunci sama → hasil sama, tanpa tinjauan kedua');
$dWali = $svc()->detail($wA, $id1);
$assert($dWali['tindakan'] === ['perbaiki' => true, 'batalkan' => true] && $dWali['tinjauan'][0]['catatan'] === 'Mohon cantumkan tanggal kembali yang pasti',
    'Wali pengaju melihat catatan perbaikan dan tindakan perbaiki/batalkan');
$dB = $svc()->detail($wB, $id1);
$assert($dB['pengajuan']['ringkas'] === true && $dB['tindakan'] === [] && !isset($dB['tinjauan']) && !isset($dB['pengajuan']['alasan'])
    && $dB['pengajuan']['status_label'] === 'Perlu perbaikan wali', 'Wali kedua hanya melihat status ringkas tanpa catatan pembimbing, alasan, atau tindakan');
$isiBaru = ['tgl_izin' => '2026-12-01', 'tgl_kembali' => '2026-12-03', 'alasan' => 'Menghadiri acara keluarga di Bandung', 'catatan' => 'Kembali Kamis sore'];
$assert($galat(fn () => $svc()->perbaiki($wB, $id1, $isiBaru, 2, $kunci('perbaiki-b'), $meta)) === 403, 'Wali kedua berelasi tidak dapat memperbaiki (403)');
$assert($galat(fn () => $svc()->perbaiki($wAsing, $id1, $isiBaru, 2, $kunci('perbaiki-asing'), $meta)) === 404, 'Wali asing tidak dapat memperbaiki (404)');
$assert($galat(fn () => $svc()->perbaiki($pb1, $id1, $isiBaru, 2, $kunci('perbaiki-pb'), $meta)) === 403, 'Pembimbing tidak dapat mengubah isi wali (403)');
$assert($galat(fn () => $svc()->perbaiki($wA, $id1, ['tgl_izin' => '2026-12-01', 'tgl_kembali' => '2026-12-02', 'alasan' => 'Menghadiri acara keluarga', 'catatan' => 'Dijemput paman'], 2, $kunci('sama'), $meta)) === 422,
    'Perbaikan tanpa perubahan isi ditolak (422)');
$kPerbaiki = $kunci('perbaiki-a');
$p1 = $svc()->perbaiki($wA, $id1, $isiBaru, 2, $kPerbaiki, $meta);
$baris = $db->query("SELECT * FROM izin_pengajuan WHERE id = $id1")->fetch_assoc();
$assert($p1['status'] === 'Menunggu Pembimbing' && $p1['version'] === 3 && $baris['tgl_kembali'] === '2026-12-03' && $baris['alasan'] === $isiBaru['alasan']
    && $baris['catatan_wali'] === 'Kembali Kamis sore', 'Perbaikan wali pengaju → isi baru, kembali Menunggu Pembimbing, versi 2→3');
$perb = $db->query("SELECT * FROM izin_wali_perbaikan WHERE pengajuan_id = $id1")->fetch_all(MYSQLI_ASSOC);
$assert(count($perb) === 1 && $perb[0]['alasan_sebelum'] === 'Menghadiri acara keluarga' && $perb[0]['tgl_kembali_sebelum'] === '2026-12-02'
    && $perb[0]['catatan_sebelum'] === 'Dijemput paman' && (int) $perb[0]['versi_sebelum'] === 2 && (int) $perb[0]['versi_sesudah'] === 3
    && (int) $perb[0]['dikirim_oleh_user_id'] === $wA['id'], 'Versi isi sebelum/sesudah tercatat (2→3) oleh akun wali pengaju; isi lama tidak hilang');
$assert($svc()->perbaiki($wA, $id1, $isiBaru, 2, $kPerbaiki, $meta)['idempotent_replay'] === true && $hitung('izin_wali_perbaikan', "pengajuan_id = $id1") === 1,
    'Kirim ulang perbaikan kunci sama → putar ulang tanpa versi baru');
$antreanMurobi = static fn (array $m): int => izin_service()->queueCount($m, 'murobi');
$antreanASebelum = $antreanMurobi($murobiA);
$assert($galat(fn () => $svc()->detail($murobiA, $id1)) === 404 && izin_service()->list($murobiA, ['id' => $id1], 1, 20, 'murobi')['total'] === 0,
    'Murobi belum melihat pengajuan sebelum diteruskan (V7 404, V2 kosong)');
$kTeruskan = $kunci('teruskan');
$t2 = $svc()->tinjau($pb1, $id1, 'teruskan', 'Sudah dicek dengan wali', 3, $kTeruskan, $meta);
$baris = $db->query("SELECT * FROM izin_pengajuan WHERE id = $id1")->fetch_assoc();
$assert($t2['status'] === 'Diajukan' && $t2['perlu_penetapan_murobi'] === false && (int) $baris['murobi_guru_id'] === (int) $murobiA['guru_id']
    && (int) $baris['routing_kandidat'] === 1 && $baris['routing_pada'] !== null && (int) $baris['version'] === 4,
    'Teruskan → routing murobi V2 tunggal: Diajukan ke SBX Murobi A, versi 3→4');
$assert($hitung('izin_pengajuan', '1') === $jumlahAwal + 1 && (int) $baris['id'] === $id1 && $baris['asal_pengajuan'] === 'Wali',
    'Penerusan tidak membuat pengajuan kedua (ID dan asal tetap)');
$assert($antreanMurobi($murobiA) === $antreanASebelum + 1 && izin_service()->list($murobiA, ['id' => $id1], 1, 20, 'murobi')['total'] === 1,
    'Antrean murobi A bertambah tepat satu (pengajuan yang sama)');
$dM = $svc()->detail($murobiA, $id1);
$assert($dM['peran_akses'] === 'murobi' && $dM['tindakan']['putuskan'] === true && count($dM['tinjauan']) === 2 && $dM['tinjauan'][1]['catatan'] === 'Sudah dicek dengan wali',
    'Murobi tujuan membaca rincian V7 beserta tinjauan dan catatan penerusan');
$assert($galat(fn () => $svc()->tinjau($pb1, $id1, 'tolak', 'Terlambat', 4, $kunci('tolak-akhir'), $meta)) === 409 && $versi($id1) === 4,
    'Pembimbing tidak dapat meninjau lagi sesudah diteruskan (409)');
$assert($galat(fn () => $svc()->perbaiki($wA, $id1, $isiBaru + ['catatan' => 'lain'], 4, $kunci('perbaiki-akhir'), $meta)) === 409,
    'Wali tidak dapat mengubah isi sesudah diteruskan (409)');
$k = $v2()->decide($murobiA, $id1, 'Disetujui', 'Izin diberikan, kembali tepat waktu', null, 4, $kunci('putus'), $meta, 'murobi');
$assert($k['status'] === 'Disetujui' && $k['kapasitas'] === 'Murobi' && $hitung('izin_keputusan', "pengajuan_id = $id1") === 1 && $versi($id1) === 5,
    'Murobi memutus lewat layanan V2: satu keputusan efektif, versi 4→5');
$dWali = $svc()->detail($wA, $id1);
$assert($dWali['keputusan']['hasil'] === 'Disetujui' && $dWali['keputusan']['oleh'] === 'Murobi' && $dWali['tindakan'] === ['perbaiki' => false, 'batalkan' => false]
    && $dWali['pengajuan']['status_label'] === 'Disetujui murobi', 'Wali pengaju melihat keputusan murobi; tidak ada tindakan lagi');
$riwayat = array_column($db->query("SELECT peristiwa FROM izin_riwayat_status WHERE pengajuan_id = $id1 ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'peristiwa');
$assert($riwayat === ['pengajuan_wali_dibuat', 'routing_pembimbing_otomatis', 'tinjauan_minta_perbaikan', 'perbaikan_wali_dikirim', 'tinjauan_diteruskan', 'routing_otomatis', 'keputusan'],
    'Riwayat lengkap berurutan: ' . implode(', ', $riwayat));
$tj = $db->query("SELECT tindakan, versi_sebelum, versi_sesudah, pembimbing_pengurus_id, ditinjau_oleh_user_id, murobi_guru_id, isi_hash FROM izin_pembimbing_tinjauan WHERE pengajuan_id = $id1 ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$assert(count($tj) === 2 && $tj[0]['tindakan'] === 'Minta Perbaikan' && (int) $tj[0]['versi_sebelum'] === 1 && $tj[1]['tindakan'] === 'Teruskan' && (int) $tj[1]['versi_sebelum'] === 3
    && (int) $tj[1]['ditinjau_oleh_user_id'] === $pb1['id'] && (int) $tj[1]['murobi_guru_id'] === (int) $murobiA['guru_id']
    && $tj[1]['isi_hash'] === \App\Izin\IzinWaliService::isiHash('2026-12-01', '2026-12-03', $isiBaru['alasan'], $isiBaru['catatan']),
    'Tinjauan mencatat pelaku, versi sebelum/sesudah, murobi hasil routing, dan sidik isi yang ditinjau');
$auditTeks = (string) $satu("SELECT GROUP_CONCAT(CONCAT(COALESCE(before_json,''), COALESCE(after_json,''))) FROM audit_logs WHERE entity_type = 'izin_pengajuan' AND entity_id = $id1 AND action LIKE 'izin\\_wali\\_%'");
$assert($hitung('audit_logs', "entity_type = 'izin_pengajuan' AND entity_id = $id1 AND action IN ('izin_wali_diminta_perbaikan', 'izin_wali_perbaikan_dikirim', 'izin_wali_diteruskan')") === 3
    && !str_contains($auditTeks, 'Bandung') && !str_contains($auditTeks, 'cantumkan') && !str_contains($auditTeks, 'dicek') && !str_contains($auditTeks, '2026-12'),
    'Audit tiap tindakan ada dan tanpa alasan izin, catatan, atau tanggal');

// ===================================================================== J-2
echo PHP_EOL . '=== J-2. Penolakan pembimbing dan pelepasan rentang ===' . PHP_EOL;
$id2 = (int) $svc()->buat($wA, $isi($sSatu, '2026-12-10', '2026-12-12'), $kunci('buat'), $meta)['id'];
$assert($galat(fn () => $svc()->buat($wA, $isi($sSatu, '2026-12-11', '2026-12-13'), $kunci('buat'), $meta)) === 409
    && $galat(fn () => $v2()->create($pb1, $isi($sSatu, '2026-12-11', '2026-12-11'), $kunci('v2'), $meta, 'pengurus')) === 409,
    'Sebelum ditolak, rentang ditahan bagi wali dan pengurus V2 (409)');
$assert($galat(fn () => $svc()->tinjau($pb1, $id2, 'tolak', 'ab', 1, $kunci('tolak'), $meta)) === 422, 'Tolak dengan alasan < 3 karakter → 422');
$t = $svc()->tinjau($pb1, $id2, 'tolak', 'Jadwal ujian pekan itu wajib diikuti', 1, $kunci('tolak'), $meta);
$assert($t['status'] === 'Ditolak Pembimbing' && $versi($id2) === 2 && $hitung('izin_keputusan', "pengajuan_id = $id2") === 0,
    'Tolak → Ditolak Pembimbing tanpa baris izin_keputusan murobi');
$assert($satu("SELECT murobi_guru_id FROM izin_pengajuan WHERE id = $id2") === null && izin_service()->list(['roles' => ['admin']] + $admin, ['id' => $id2], 1, 20, 'admin')['total'] === 0,
    'Ditolak pembimbing tidak pernah masuk murobi maupun daftar keputusan V2');
$dWali = $svc()->detail($wA, $id2);
$assert($dWali['pengajuan']['status_label'] === 'Ditolak pembimbing' && $dWali['keputusan'] === null && $dWali['tinjauan'][0]['catatan'] === 'Jadwal ujian pekan itu wajib diikuti'
    && $dWali['tindakan']['batalkan'] === false, 'Wali pengaju melihat hasil tahap pembimbing (bukan keputusan murobi) beserta alasannya');
$assert($svc()->detail($wB, $id2)['pengajuan']['status_label'] === 'Ditolak pembimbing', 'Wali kedua melihat status "Ditolak pembimbing" yang berbeda dari "Ditolak murobi"');
$assert($galat(fn () => $v2()->decide($murobiA, $id2, 'Disetujui', 'Coba putus', null, 2, $kunci('v2'), $meta, 'murobi')) === 409
    && $galat(fn () => $v2()->cancel(['roles' => ['admin']] + $admin, $id2, 'Coba batal', 2, $kunci('v2'), $meta, 'admin')) === 409
    && $galat(fn () => $svc()->batalkan($wA, $id2, 'Batal sesudah ditolak', 2, $kunci('batal'), $meta)) === 409,
    'Sesudah ditolak pembimbing: keputusan V2, pembatalan V2, dan pembatalan wali ditolak (409)');
$id2b = (int) $svc()->buat($wA, $isi($sSatu, '2026-12-11', '2026-12-13'), $kunci('buat'), $meta)['id'];
$assert($id2b > $id2, 'Pengajuan wali baru pada rentang yang sudah ditolak diterima (rentang dilepas)');
$svc()->tinjau($pb1, $id2b, 'tolak', 'Masih bentrok kegiatan', 1, $kunci('tolak'), $meta);
$v2Baru = $v2()->create($pb1, $isi($sSatu, '2026-12-12', '2026-12-12', 'Kontrol kesehatan'), $kunci('v2'), $meta, 'pengurus');
$assert((int) $v2Baru['id'] > 0, 'Pengurus V2 dapat mengajukan pada rentang yang dilepas penolakan pembimbing (lintas jalur)');

// ===================================================================== J-3
echo PHP_EOL . '=== J-3. Routing murobi tidak tunggal → admin; penetapan dan admin pengganti V2 ===' . PHP_EOL;
$adminV2 = $admin;
$id3 = (int) $svc()->buat($wA, $isi($sTanpaMurobi, '2026-12-15', '2026-12-16'), $kunci('buat'), $meta)['id'];
$t = $svc()->tinjau($pb1, $id3, 'teruskan', null, 1, $kunci('teruskan'), $meta);
$baris = $db->query("SELECT * FROM izin_pengajuan WHERE id = $id3")->fetch_assoc();
$assert($t['status'] === 'Perlu Penetapan Admin' && $t['perlu_penetapan_murobi'] === true && $baris['murobi_guru_id'] === null && (int) $baris['routing_kandidat'] === 0,
    'Nol murobi → Perlu Penetapan Admin, tanpa murobi tujuan');
$adminList = $svc()->daftarAdmin($admin, ['antrean' => '1']);
$assert(in_array($id3, array_column($adminList['items'], 'id'), true) && izin_service()->list($adminV2, ['id' => $id3, 'antrean' => '1'], 1, 20, 'admin')['total'] === 1,
    'Muncul di antrean admin V7 dan antrean penetapan murobi V2');
$assert(izin_service()->list($murobiA, ['id' => $id3], 1, 20, 'murobi')['total'] === 0 && izin_service()->list($murobiB, ['id' => $id3], 1, 20, 'murobi')['total'] === 0,
    'Tidak ada murobi yang menerima antrean');
$assert($galat(fn () => $v2()->decide($murobiA, $id3, 'Disetujui', 'Putus tanpa penetapan', null, 2, $kunci('v2'), $meta, 'murobi')) === 403, 'Murobi tidak dapat memutus sebelum ditetapkan (403)');
$dAdmin = $svc()->detail($admin, $id3);
$assert($dAdmin['tindakan']['tetapkan_murobi'] === true && $dAdmin['tindakan']['buka_v2'] === true && $dAdmin['pengajuan']['perlu_admin'] === true,
    'Rincian admin V7 menandai perlu penetapan murobi dan mengarah ke halaman V2');
require_once __DIR__ . '/v7_phase5_fixture.php';
$f51Assignment = v7f5_fixture_murobi($db, (int) $murobiB['guru_id'], (int) $sTanpaMurobi);
$v2()->assignMurobi($adminV2, $id3, (int) $murobiB['guru_id'], 'Murobi wali kelas santri', 2, $kunci('v2'), $meta);
$assert($status($id3) === 'Diajukan' && (int) $satu("SELECT murobi_guru_id FROM izin_pengajuan WHERE id = $id3") === (int) $murobiB['guru_id'] && $versi($id3) === 3,
    'Admin menetapkan murobi lewat V2 (alasan, versi) pada ID yang sama');
$assert($galat(fn () => $v2()->decide($murobiA, $id3, 'Disetujui', 'Bukan murobi tujuan', null, 3, $kunci('v2'), $meta, 'murobi')) === 403, 'Murobi A (bukan tujuan) ditolak 403');
$v2()->decide($murobiB, $id3, 'Ditolak', 'Belum waktunya pulang', null, 3, $kunci('v2'), $meta, 'murobi');
$dWali = $svc()->detail($wA, $id3);
$assert($status($id3) === 'Ditolak' && $dWali['pengajuan']['status_label'] === 'Ditolak murobi' && $dWali['keputusan']['oleh'] === 'Murobi',
    'Murobi tujuan menolak: wali melihat "Ditolak murobi", berbeda dari penolakan pembimbing');
$id3b = (int) $svc()->buat($wA, $isi($sDuaMurobi, '2026-12-15', '2026-12-16'), $kunci('buat'), $meta)['id'];
$t = $svc()->tinjau($pb1, $id3b, 'teruskan', null, 1, $kunci('teruskan'), $meta);
$assert($t['status'] === 'Perlu Penetapan Admin' && (int) $satu("SELECT routing_kandidat FROM izin_pengajuan WHERE id = $id3b") === 2
    && (int) $satu("SELECT murobi_routing_kandidat FROM izin_pembimbing_tinjauan WHERE pengajuan_id = $id3b") === 2,
    'Dua murobi → Perlu Penetapan Admin (dua kandidat tercatat di pengajuan dan tinjauan)');
$assert($galat(fn () => $v2()->decide($adminV2, $id3b, 'Disetujui', 'Darurat keluarga', '', 2, $kunci('v2'), $meta, 'admin')) === 422,
    'Admin pengganti tanpa alasan penggantian → 422');
$k = $v2()->decide($adminV2, $id3b, 'Disetujui', 'Darurat keluarga', 'Dua murobi berhalangan', 2, $kunci('v2'), $meta, 'admin');
$assert($k['kapasitas'] === 'Admin Pengganti' && (string) $satu("SELECT alasan_penggantian FROM izin_keputusan WHERE pengajuan_id = $id3b") === 'Dua murobi berhalangan'
    && $svc()->detail($wA, $id3b)['keputusan']['oleh'] === 'Admin pengganti', 'Admin pengganti memutus beralasan (aturan V2); wali melihat "Admin pengganti"');
$assert($galat(fn () => $v2()->decide($adminV2, $id3b, 'Ditolak', 'Keputusan kedua', 'Ulang', 3, $kunci('v2'), $meta, 'admin')) === 409
    && $hitung('izin_keputusan', "pengajuan_id = $id3b") === 1, 'Keputusan kedua ditolak (409); tetap satu keputusan efektif');
$kor = $v2()->correctDecision($adminV2, $id3b, 'Ditolak', 'Ternyata tidak mendesak', 'Informasi baru dari pembimbing', 3, $kunci('v2'), $meta);
$assert($kor['status'] === 'Ditolak' && $hitung('izin_keputusan_koreksi', "pengajuan_id = $id3b") === 1 && $hitung('izin_keputusan', "pengajuan_id = $id3b") === 1,
    'Koreksi keputusan memakai mekanisme V2 (riwayat koreksi, satu keputusan)');

// ===================================================================== J-4
echo PHP_EOL . '=== J-4. Murobi di luar penugasan dan peran lain ===' . PHP_EOL;
$id4 = (int) $svc()->buat($wA, $isi($sCadangan, '2026-12-20', '2026-12-21'), $kunci('buat'), $meta)['id'];
$assert($galat(fn () => $svc()->tinjau($pb2, $id4, 'teruskan', null, 1, $kunci('tinjau'), $meta)) === 404, 'Pembimbing lain (bukan tujuan) → 404');
$assert($galat(fn () => $svc()->tinjau($admin, $id4, 'teruskan', null, 1, $kunci('tinjau'), $meta)) === 403, 'Admin tidak menggantikan tinjauan pembimbing → 403');
$assert($galat(fn () => $svc()->tinjau($wA, $id4, 'teruskan', null, 1, $kunci('tinjau'), $meta)) === 403
    && $galat(fn () => $svc()->tinjau($murobiA, $id4, 'teruskan', null, 1, $kunci('tinjau'), $meta)) === 403, 'Wali dan murobi tidak dapat meninjau → 403');
$svc()->tinjau($pb1, $id4, 'teruskan', null, 1, $kunci('teruskan'), $meta);
$assert($galat(fn () => $v2()->decide($murobiB, $id4, 'Disetujui', 'Bukan tujuan', null, 2, $kunci('v2'), $meta, 'murobi')) === 403
    && $galat(fn () => $svc()->detail($murobiB, $id4)) === 404 && $hitung('izin_keputusan', "pengajuan_id = $id4") === 0,
    'Murobi B di luar penugasan: keputusan 403, rincian V7 404, tanpa keputusan');
$guruBiasa = $akun('sbx_guru_biasa');
$assert($galat(fn () => $v2()->decide($guruBiasa, $id4, 'Disetujui', 'Guru tanpa penugasan', null, 2, $kunci('v2'), $meta, 'murobi')) === 403, 'Guru tanpa penugasan murobi → 403');
$assert($galat(fn () => $v2()->decide($wA, $id4, 'Disetujui', 'Wali memutus', null, 2, $kunci('v2'), $meta)) === 403, 'Wali tidak dapat memutus → 403');

// ===================================================================== J-5
echo PHP_EOL . '=== J-5. Versi lama, kunci konflik, benturan saat perbaikan ===' . PHP_EOL;
$id5 = (int) $svc()->buat($wA, $isi($sSatu, '2027-01-05', '2027-01-06'), $kunci('buat'), $meta)['id'];
$assert($galat(fn () => $svc()->tinjau($pb1, $id5, 'teruskan', null, 7, $kunci('tinjau'), $meta)) === 409 && $versi($id5) === 1 && $hitung('izin_pembimbing_tinjauan', "pengajuan_id = $id5") === 0,
    'Tinjauan dengan versi lama → 409 tanpa perubahan');
$kSama = $kunci('konflik');
$svc()->tinjau($pb1, $id5, 'minta_perbaikan', 'Perjelas alasan', 1, $kSama, $meta);
$assert($galat(fn () => $svc()->tinjau($pb1, $id5, 'minta_perbaikan', 'Isi berbeda', 1, $kSama, $meta)) === 409 && $versi($id5) === 2,
    'Kunci sama dengan isi berbeda → 409 tanpa perubahan');
$id5b = (int) $svc()->buat($wA, $isi($sSatu, '2027-01-10', '2027-01-11'), $kunci('buat'), $meta)['id'];
$assert($galat(fn () => $svc()->perbaiki($wA, $id5, ['tgl_izin' => '2027-01-05', 'tgl_kembali' => '2027-01-10', 'alasan' => 'Diperpanjang'], 2, $kunci('perbaiki'), $meta)) === 409
    && $versi($id5) === 2 && $status($id5) === 'Perlu Perbaikan Wali', 'Perbaikan yang membentur pengajuan aktif lain → 409 tanpa perubahan');
$assert($galat(fn () => $svc()->perbaiki($wA, $id5, ['tgl_izin' => '2027-01-05', 'tgl_kembali' => '2027-01-04', 'alasan' => 'Tanggal terbalik'], 2, $kunci('perbaiki'), $meta)) === 422,
    'Perbaikan dengan tanggal kembali mendahului tanggal izin → 422');
$assert($galat(fn () => $svc()->perbaiki($wA, $id5, ['tgl_izin' => '2027-01-05', 'tgl_kembali' => '2027-01-07', 'alasan' => 'Diperpanjang sehari'], 1, $kunci('perbaiki'), $meta)) === 409,
    'Perbaikan dengan versi lama → 409');
$svc()->perbaiki($wA, $id5, ['tgl_izin' => '2027-01-05', 'tgl_kembali' => '2027-01-07', 'alasan' => 'Diperpanjang sehari'], 2, $kunci('perbaiki'), $meta);
$assert($status($id5) === 'Menunggu Pembimbing' && $versi($id5) === 3, 'Perbaikan dengan rentang bebas diterima (versi 3)');
$svc()->tinjau($pb1, $id5, 'minta_perbaikan', 'Masih kurang jelas', 3, $kunci('minta'), $meta);
$svc()->perbaiki($wA, $id5, ['tgl_izin' => '2027-01-05', 'tgl_kembali' => '2027-01-07', 'alasan' => 'Diperpanjang sehari untuk kontrol dokter'], 4, $kunci('perbaiki'), $meta);
$assert($hitung('izin_wali_perbaikan', "pengajuan_id = $id5") === 2 && $hitung('izin_pembimbing_tinjauan', "pengajuan_id = $id5 AND tindakan = 'Minta Perbaikan'") === 2 && $versi($id5) === 5,
    'Dua putaran perbaikan: dua versi isi lama tersimpan, versi 5');

// ===================================================================== J-6
echo PHP_EOL . '=== J-6. Pembatalan wali pengaju pada setiap tahap ===' . PHP_EOL;
$batal = static function (int $id, string $label) use ($svc, $wA, $kunci, $meta, $versi, $status, $hitung, $assert): void {
    $sebelum = $status($id);
    $h = $svc()->batalkan($wA, $id, 'Rencana keluarga berubah', $versi($id), $kunci('batal'), $meta);
    $assert($h['status'] === 'Dibatalkan' && $status($id) === 'Dibatalkan' && $hitung('izin_keputusan', "pengajuan_id = $id") === 0,
        'Wali pengaju membatalkan dari ' . $sebelum . ' (' . $label . ')');
};
$assert($galat(fn () => $svc()->batalkan($wB, $id5b, 'Wali lain', 1, $kunci('batal'), $meta)) === 403 && $galat(fn () => $svc()->batalkan($wAsing, $id5b, 'Asing', 1, $kunci('batal'), $meta)) === 404
    && $galat(fn () => $svc()->batalkan($wA, $id5b, 'ab', 1, $kunci('batal'), $meta)) === 422, 'Wali lain 403, wali asing 404, alasan pendek 422');
$batal($id5b, 'menunggu pembimbing');
$id6 = (int) $svc()->buat($wA, $isi($sNol, '2027-01-12', '2027-01-13'), $kunci('buat'), $meta)['id'];
$batal($id6, 'menunggu penetapan pembimbing oleh admin');
$id6b = (int) $svc()->buat($wA, $isi($sSatu, '2027-01-15', '2027-01-16'), $kunci('buat'), $meta)['id'];
$svc()->tinjau($pb1, $id6b, 'minta_perbaikan', 'Lengkapi', 1, $kunci('minta'), $meta);
$batal($id6b, 'perlu perbaikan wali');
$id6c = (int) $svc()->buat($wA, $isi($sSatu, '2027-01-18', '2027-01-19'), $kunci('buat'), $meta)['id'];
$svc()->tinjau($pb1, $id6c, 'teruskan', null, 1, $kunci('teruskan'), $meta);
$assert($galat(fn () => $v2()->cancel(['roles' => ['admin']] + $admin, $id6c, 'Admin membatalkan', 2, $kunci('v2'), $meta, 'admin')) === 403 && $status($id6c) === 'Diajukan',
    'Pembatalan V2 oleh admin atas pengajuan wali → 403 (hanya wali pengaju)');
$batal($id6c, 'sesudah diteruskan, sebelum keputusan murobi');
$assert(izin_service()->list($murobiA, ['id' => $id6c, 'antrean' => '1'], 1, 20, 'murobi')['total'] === 0
    && $galat(fn () => $v2()->decide($murobiA, $id6c, 'Disetujui', 'Terlambat', null, 3, $kunci('v2'), $meta, 'murobi')) === 409,
    'Murobi melihat status terkini: keluar dari antrean, keputusan sesudah batal → 409');
$jalankan('UPDATE murobi_assignments SET is_active = 0 WHERE id = ' . $f51Assignment);
$id6d = (int) $svc()->buat($wA, $isi($sTanpaMurobi, '2027-01-20', '2027-01-21'), $kunci('buat'), $meta)['id'];
$svc()->tinjau($pb1, $id6d, 'teruskan', null, 1, $kunci('teruskan'), $meta);
$batal($id6d, 'menunggu penetapan murobi');
$assert($galat(fn () => $svc()->batalkan($wA, $id1, 'Sesudah diputus', $versi($id1), $kunci('batal'), $meta)) === 409, 'Pembatalan sesudah keputusan murobi → 409');
$assert((int) $svc()->buat($wA, $isi($sSatu, '2027-01-15', '2027-01-19'), $kunci('buat'), $meta)['id'] > 0, 'Rentang yang dibatalkan dilepas untuk pengajuan baru');
$riwayatBatal = $db->query("SELECT alasan, pelaku_kapasitas FROM izin_riwayat_status WHERE pengajuan_id = $id6c AND peristiwa = 'pembatalan_wali'")->fetch_assoc();
$assert(($riwayatBatal['pelaku_kapasitas'] ?? '') === 'Wali' && ($riwayatBatal['alasan'] ?? '') === 'Rencana keluarga berubah'
    && (int) $satu("SELECT dibatalkan_oleh_user_id FROM izin_pengajuan WHERE id = $id6c") === $wA['id'], 'Pembatalan tercatat dengan pelaku wali pengaju dan alasannya');

// ===================================================================== J-7
echo PHP_EOL . '=== J-7. Pencabutan pada sesi lama ===' . PHP_EOL;
$id7 = (int) $svc()->buat($wA, $isi($sSatu, '2027-02-01', '2027-02-02'), $kunci('buat'), $meta)['id'];
$paSatu = (int) $satu("SELECT pembimbing_assignment_id FROM izin_pengajuan WHERE id = $id7");
$jalankan("UPDATE pembimbing_assignments SET is_active = 0 WHERE id = $paSatu");
$assert($galat(fn () => $svc()->tinjau($pb1, $id7, 'teruskan', null, 1, $kunci('tinjau'), $meta)) === 404 && $versi($id7) === 1 && $galat(fn () => $svc()->detail($pb1, $id7)) === 404,
    'Penugasan pembimbing dinonaktifkan: tinjauan dan rincian sesi lama → 404 tanpa perubahan');
$assert(in_array($id7, array_column($svc()->daftarAdmin($admin, ['antrean' => '1'])['items'], 'id'), true), 'Pengajuan itu muncul di antrean admin untuk penetapan ulang');
$jalankan("UPDATE pembimbing_assignments SET is_active = 1 WHERE id = $paSatu");
$jalankan("UPDATE pembimbing_assignments SET tanggal_selesai = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = $paSatu");
$assert($galat(fn () => $svc()->tinjau($pb1, $id7, 'tolak', 'Kedaluwarsa', 1, $kunci('tinjau'), $meta)) === 404 && $versi($id7) === 1, 'Penugasan berakhir kemarin → 404 tanpa perubahan');
$jalankan("UPDATE pembimbing_assignments SET tanggal_selesai = NULL WHERE id = $paSatu");
$jalankan("UPDATE users SET is_active = 0 WHERE id = {$pb1['id']}");
$assert($galat(fn () => $svc()->tinjau($pb1, $id7, 'teruskan', null, 1, $kunci('tinjau'), $meta)) === 403 && $versi($id7) === 1, 'Akun pembimbing dinonaktifkan → 403');
$jalankan("UPDATE users SET is_active = 1 WHERE id = {$pb1['id']}");
capabilities()->forget($pb1['id']);
$kTinjauLama = $kunci('tinjau-lama');
$svc()->tinjau($pb1, $id7, 'minta_perbaikan', 'Perjelas', 1, $kTinjauLama, $meta);
$jalankan("UPDATE pembimbing_assignments SET is_active = 0 WHERE id = $paSatu");
$assert($galat(fn () => $svc()->tinjau($pb1, $id7, 'minta_perbaikan', 'Perjelas', 1, $kTinjauLama, $meta)) === 404, 'Putar ulang tinjauan sesudah penugasan dicabut → 404 (hasil tersimpan tidak dibuka)');
$jalankan("UPDATE pembimbing_assignments SET is_active = 1 WHERE id = $paSatu");
$relasiA = (int) $satu("SELECT sw.id FROM santri_wali sw JOIN users u ON u.wali_id = sw.wali_id WHERE u.id = {$wA['id']} AND sw.santri_id = $sSatu AND sw.archived_at IS NULL");
$kPerbaikiLama = $kunci('perbaiki-lama');
$jalankan("UPDATE santri_wali SET archived_at = NOW() WHERE id = $relasiA");
$assert($galat(fn () => $svc()->perbaiki($wA, $id7, ['tgl_izin' => '2027-02-01', 'tgl_kembali' => '2027-02-03', 'alasan' => 'Perbaikan'], 2, $kPerbaikiLama, $meta)) === 404
    && $galat(fn () => $svc()->batalkan($wA, $id7, 'Batal', 2, $kunci('batal'), $meta)) === 404 && $galat(fn () => $svc()->detail($wA, $id7)) === 404 && $versi($id7) === 2,
    'Relasi wali dicabut: perbaiki/batal/rincian sesi lama → 404 tanpa perubahan');
$jalankan("UPDATE santri_wali SET archived_at = NULL WHERE id = $relasiA");
$svc()->perbaiki($wA, $id7, ['tgl_izin' => '2027-02-01', 'tgl_kembali' => '2027-02-03', 'alasan' => 'Perbaikan sesudah relasi pulih'], 2, $kunci('perbaiki'), $meta);
$svc()->tinjau($pb1, $id7, 'teruskan', null, 3, $kunci('teruskan'), $meta);
$maSatu = (int) $satu("SELECT id FROM murobi_assignments WHERE guru_id = {$murobiA['guru_id']} AND kamar_id = (SELECT id FROM kamar WHERE nama_kamar = 'SBX V7 Kamar Satu')");
$maLain = array_map('intval', array_column($db->query("SELECT id FROM murobi_assignments WHERE guru_id = {$murobiA['guru_id']} AND is_active = 1 AND id <> $maSatu")->fetch_all(MYSQLI_ASSOC), 'id'));
$jalankan('UPDATE murobi_assignments SET is_active = 0 WHERE guru_id = ' . (int) $murobiA['guru_id']);
capabilities()->forget($murobiA['id']);
$assert($galat(fn () => $v2()->decide($murobiA, $id7, 'Disetujui', 'Sesi lama', null, 4, $kunci('v2'), $meta, 'murobi')) === 403 && $hitung('izin_keputusan', "pengajuan_id = $id7") === 0,
    'Seluruh penugasan murobi A dicabut: keputusan dari sesi lama → 403 tanpa keputusan');
$jalankan("UPDATE murobi_assignments SET is_active = 1 WHERE id IN (" . implode(',', array_merge([$maSatu], $maLain)) . ')');
capabilities()->forget($murobiA['id']);
$jalankan("UPDATE users SET is_active = 0 WHERE id = {$wA['id']}");
$assert($galat(fn () => $svc()->batalkan($wA, $id7, 'Akun nonaktif', 4, $kunci('batal'), $meta)) === 403 && $status($id7) === 'Diajukan', 'Akun wali pengaju dinonaktifkan → 403');
$jalankan("UPDATE users SET is_active = 1 WHERE id = {$wA['id']}");
capabilities()->forget($wA['id']); // cache kemampuan per permintaan; proses uji ini satu "permintaan" panjang
$v2()->decide($murobiA, $id7, 'Disetujui', 'Izin diberikan', null, 4, $kunci('v2'), $meta, 'murobi');
$assert($status($id7) === 'Disetujui', 'Sesudah penugasan murobi dipulihkan, murobi tujuan memutus seperti biasa');

// ===================================================================== J-8
echo PHP_EOL . '=== J-8. Permukaan V2: orang tua, tindakan batal, notifikasi ===' . PHP_EOL;
$ortuV2 = izin_service()->list($wA, [], 1, 100, 'orang_tua');
$idsOrtu = array_map('intval', array_column($ortuV2['rows'], 'id'));
$assert(array_intersect($idsOrtu, [$id1, $id3, $id3b, $id6c, $id7]) === [] && $galat(fn () => izin_service()->detail($wA, $id1, 'orang_tua')) === 403,
    'Daftar/rincian V2 orang tua tidak memuat pengajuan wali yang sudah diteruskan (dibaca lewat V7)');
$laporan = izin_report_service()->report($wA, ['date_from' => '2026-11-01', 'date_to' => '2027-03-31', 'per_page' => 100], 'orang_tua');
$laporanAdmin = izin_report_service()->report($admin, ['date_from' => '2026-11-01', 'date_to' => '2027-03-31', 'per_page' => 100], 'admin');
$assert(array_intersect(array_map('intval', array_column($laporan['items'], 'id')), [$id1, $id3, $id3b, $id7]) === []
    && in_array($id1, array_map('intval', array_column($laporanAdmin['items'], 'id')), true),
    'Laporan V2 cakupan orang tua tidak memuat pengajuan wali; cakupan admin tetap memuatnya');
$dAdminV2 = izin_service()->detail($admin, $id4, 'admin');
$aksi = $v2()->actionsFor($dAdminV2['pengajuan'], $dAdminV2['scope']);
$assert($dAdminV2['pengajuan']['asal_pengajuan'] === 'Wali' && $aksi['batalkan'] === false && $aksi['putuskan_admin'] === true,
    'Rincian V2 admin atas pengajuan wali: putuskan sebagai pengganti ada, batalkan tidak ditawarkan');
$dMurobiV2 = izin_service()->detail($murobiA, $id4, 'murobi');
$assert($v2()->actionsFor($dMurobiV2['pengajuan'], $dMurobiV2['scope'])['putuskan_murobi'] === true, 'Rincian V2 murobi tujuan menawarkan keputusan');
$assert($hitung('notifikasi_outbox', "pengajuan_id IN (SELECT id FROM izin_pengajuan WHERE asal_pengajuan = 'Wali')") === 0,
    'Notifikasi V2 tidak dipancarkan untuk pengajuan wali (notifikasi V7 adalah Fase 3)');
$asal = $svc()->asalWali($id4);
$assert(($asal['pembimbing'] ?? null) === 'SBX V7 Pembimbing Satu' && $asal['diteruskan_pada'] !== null && $svc()->asalWali($maxV2Sebelum) === null,
    'Penanda asal wali untuk rincian V2 hanya untuk baris wali');

// ===================================================================== J-9
echo PHP_EOL . '=== J-9. Konkurensi proses terpisah ===' . PHP_EOL;
// `$tunda[aksi]` = milidetik tambahan sebelum aksi itu berangkat, agar setiap
// cabang balapan (mis. pembatalan yang menang) benar-benar teramati.
$paralel = static function (array $tugas, array $tunda = []) use ($root, $dbName): array {
    $mulai = (int) floor(microtime(true) * 1000) + 1500;
    $proc = [];
    $pipes = [];
    foreach ($tugas as $i => $t) {
        $cmd = array_merge([PHP_BINARY, $root . '/tests/v7_phase2_worker.php'], array_map('strval', array_slice($t, 0, 5)),
            [(string) ($mulai + (int) ($tunda[$t[0]] ?? 0))], array_map('strval', array_slice($t, 5)));
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
$ringkas = static fn (array $hasil): string => json_encode(array_count_values(array_map(static fn ($h) => $h['aksi'] . ':' . ($h['ok'] ? 'ok' : (string) $h['http']), $hasil)));
$tanpa500 = static fn (array $hasil): bool => array_filter($hasil, static fn ($h) => !$h['ok'] && !in_array($h['http'], [403, 404, 409], true)) === [];

// (a) batal vs teruskan pada versi yang sama.
$hasilBalap = [];
$tundaPutaran = [[], ['teruskan' => 250], ['batal' => 250], [], ['teruskan' => 250]];
for ($r = 0; $r < 5; $r++) {
    $idR = (int) $svc()->buat($wA, $isi($sCadangan, '2027-05-' . sprintf('%02d', 2 * $r + 1), '2027-05-' . sprintf('%02d', 2 * $r + 2)), $kunci('buat'), $meta)['id'];
    $tugas = [];
    for ($i = 0; $i < 4; $i++) {
        $tugas[] = ['batal', 'sbx_v7_wali_a', $idR, 1, 'v7f2-balap-bt-' . $r . '-' . $i];
        $tugas[] = ['teruskan', 'sbx_v7_pembimbing_satu', $idR, 1, 'v7f2-balap-tr-' . $r . '-' . $i];
    }
    $hasil = $paralel($tugas, $tundaPutaran[$r]);
    $sukses = array_values(array_filter($hasil, static fn ($h) => $h['ok']));
    $st = $status($idR);
    $hasilBalap[] = $st;
    $assert(count($sukses) === 1 && $tanpa500($hasil) && $versi($idR) === 2
        && (($sukses[0]['aksi'] === 'batal' && $st === 'Dibatalkan' && $hitung('izin_pembimbing_tinjauan', "pengajuan_id = $idR") === 0)
         || ($sukses[0]['aksi'] === 'teruskan' && $st === 'Diajukan' && $hitung('izin_pembimbing_tinjauan', "pengajuan_id = $idR") === 1)),
        'Putaran ' . ($r + 1) . ' batal vs teruskan (4+4 proses): tepat satu menang → ' . $st . ', versi 2 ' . $ringkas($hasil));
}
$assert(in_array('Dibatalkan', $hasilBalap, true) && in_array('Diajukan', $hasilBalap, true), 'Kedua cabang balapan batal vs teruskan teramati: ' . implode(', ', $hasilBalap));
// (b) batal vs keputusan murobi sesudah diteruskan.
$hasilBalap = [];
$tundaPutaran = [[], ['putus' => 250], ['batal' => 250], [], ['putus' => 250]];
for ($r = 0; $r < 5; $r++) {
    $idR = (int) $svc()->buat($wA, $isi($sCadangan, '2027-06-' . sprintf('%02d', 2 * $r + 1), '2027-06-' . sprintf('%02d', 2 * $r + 2)), $kunci('buat'), $meta)['id'];
    $svc()->tinjau($pb1, $idR, 'teruskan', null, 1, $kunci('teruskan'), $meta);
    $tugas = [];
    for ($i = 0; $i < 4; $i++) {
        $tugas[] = ['batal', 'sbx_v7_wali_a', $idR, 2, 'v7f2-balap-bk-' . $r . '-' . $i];
        $tugas[] = ['putus', 'sbx_murobi_a', $idR, 2, 'v7f2-balap-pk-' . $r . '-' . $i, $i % 2 === 0 ? 'Disetujui' : 'Ditolak'];
    }
    $hasil = $paralel($tugas, $tundaPutaran[$r]);
    $sukses = array_values(array_filter($hasil, static fn ($h) => $h['ok']));
    $st = $status($idR);
    $hasilBalap[] = $st;
    $kep = $hitung('izin_keputusan', "pengajuan_id = $idR");
    $assert(count($sukses) === 1 && $tanpa500($hasil) && $versi($idR) === 3 && $kep <= 1
        && (($st === 'Dibatalkan' && $kep === 0) || (in_array($st, ['Disetujui', 'Ditolak'], true) && $kep === 1)),
        'Putaran ' . ($r + 1) . ' batal vs keputusan (4+4 proses): satu status terminal ' . $st . ', keputusan efektif ' . $kep . ' ' . $ringkas($hasil));
}
$assert(in_array('Dibatalkan', $hasilBalap, true) && array_intersect(['Disetujui', 'Ditolak'], $hasilBalap) !== [], 'Kedua cabang balapan batal vs keputusan teramati: ' . implode(', ', $hasilBalap));
// (c) klik ganda kunci sama: tinjauan dan pembatalan.
$idK = (int) $svc()->buat($wA, $isi($sCadangan, '2027-02-20', '2027-02-21'), $kunci('buat'), $meta)['id'];
$hasil = $paralel(array_fill(0, 8, ['minta_perbaikan', 'sbx_v7_pembimbing_satu', $idK, 1, 'v7f2-klik-tinjau-01']));
$assert(count(array_filter($hasil, static fn ($h) => $h['ok'])) === 8 && $hitung('izin_pembimbing_tinjauan', "pengajuan_id = $idK") === 1 && $versi($idK) === 2,
    'Delapan klik ganda tinjauan kunci sama → delapan jawaban sukses, satu tinjauan, versi 2 ' . $ringkas($hasil));
$hasil = $paralel(array_fill(0, 8, ['perbaiki', 'sbx_v7_wali_a', $idK, 2, 'v7f2-klik-perbaiki-01', '2027-03-02']));
$idKBaru = $idK;
$assert(count(array_filter($hasil, static fn ($h) => $h['ok'])) === 8 && $hitung('izin_wali_perbaikan', "pengajuan_id = $idK") === 1 && $versi($idK) === 3,
    'Delapan klik ganda perbaikan kunci sama → satu versi isi baru ' . $ringkas($hasil));
$hasil = $paralel(array_fill(0, 8, ['batal', 'sbx_v7_wali_a', $idK, 3, 'v7f2-klik-batal-01']));
$assert(count(array_filter($hasil, static fn ($h) => $h['ok'])) === 8 && $status($idK) === 'Dibatalkan' && $versi($idK) === 4
    && $hitung('izin_riwayat_status', "pengajuan_id = $idK AND peristiwa = 'pembatalan_wali'") === 1, 'Delapan klik ganda pembatalan kunci sama → satu pembatalan ' . $ringkas($hasil));
// (d) perbaikan vs pembatalan pada versi yang sama.
$idP = (int) $svc()->buat($wA, $isi($sCadangan, '2027-02-24', '2027-02-25'), $kunci('buat'), $meta)['id'];
$svc()->tinjau($pb1, $idP, 'minta_perbaikan', 'Lengkapi', 1, $kunci('minta'), $meta);
$tugas = [];
for ($i = 0; $i < 4; $i++) {
    $tugas[] = ['perbaiki', 'sbx_v7_wali_a', $idP, 2, 'v7f2-balap-pb-' . $i, '2027-03-0' . ($i + 3)];
    $tugas[] = ['batal', 'sbx_v7_wali_a', $idP, 2, 'v7f2-balap-pbt-' . $i];
}
$hasil = $paralel($tugas);
$assert(count(array_filter($hasil, static fn ($h) => $h['ok'])) === 1 && $tanpa500($hasil) && $versi($idP) === 3
    && $hitung('izin_wali_perbaikan', "pengajuan_id = $idP") === ($status($idP) === 'Menunggu Pembimbing' ? 1 : 0),
    'Perbaikan vs pembatalan (4+4 proses, akun sama): tepat satu menang → ' . $status($idP) . ' ' . $ringkas($hasil));
// (e) keputusan murobi vs admin pengganti.
$idD = (int) $svc()->buat($wA, $isi($sCadangan, '2027-03-10', '2027-03-11'), $kunci('buat'), $meta)['id'];
$svc()->tinjau($pb1, $idD, 'teruskan', null, 1, $kunci('teruskan'), $meta);
$tugas = [];
for ($i = 0; $i < 4; $i++) {
    $tugas[] = ['putus', 'sbx_murobi_a', $idD, 2, 'v7f2-balap-dm-' . $i, 'Disetujui'];
    $tugas[] = ['putus_admin', 'sbx_admin', $idD, 2, 'v7f2-balap-da-' . $i, 'Ditolak'];
}
$hasil = $paralel($tugas);
$assert(count(array_filter($hasil, static fn ($h) => $h['ok'])) === 1 && $hitung('izin_keputusan', "pengajuan_id = $idD") === 1 && $versi($idD) === 3,
    'Keputusan murobi vs admin pengganti (4+4 proses): tepat satu keputusan efektif ' . $ringkas($hasil));

// ===================================================================== J-10
echo PHP_EOL . '=== J-10. Jalur pengurus V2 tetap utuh ===' . PHP_EOL;
$p = $v2()->create($pb1, $isi($sCadangan, '2027-04-01', '2027-04-02', 'Pengajuan pengurus langsung'), $kunci('v2'), $meta, 'pengurus');
$baris = $db->query("SELECT * FROM izin_pengajuan WHERE id = {$p['id']}")->fetch_assoc();
$assert($baris['asal_pengajuan'] === 'Pengurus' && $baris['status'] === 'Diajukan' && (int) $baris['murobi_guru_id'] === (int) $murobiA['guru_id']
    && $hitung('izin_pembimbing_tinjauan', "pengajuan_id = {$p['id']}") === 0, 'Pengurus V2 mengajukan langsung ke murobi tanpa tahap pembimbing');
$assert($galat(fn () => $svc()->tinjau($pb1, (int) $p['id'], 'teruskan', null, 1, $kunci('tinjau'), $meta)) === 404
    && $galat(fn () => $svc()->batalkan($wA, (int) $p['id'], 'Bukan milik wali', 1, $kunci('batal'), $meta)) === 404,
    'Tindakan V7 tidak berlaku atas pengajuan pengurus (404)');
$assert($galat(fn () => $svc()->buat($wA, $isi($sCadangan, '2027-04-02', '2027-04-03'), $kunci('buat'), $meta)) === 409, 'Pengajuan pengurus aktif menahan rentang bagi wali (409)');
$v2()->cancel($pb1, (int) $p['id'], 'Dibatalkan pengurus', 1, $kunci('v2'), $meta, 'pengurus');
$assert($status((int) $p['id']) === 'Dibatalkan', 'Pengurus V2 membatalkan pengajuannya sendiri');
$p2 = $v2()->create($pb1, $isi($sCadangan, '2027-04-05', '2027-04-05', 'Pengajuan pengurus kedua'), $kunci('v2'), $meta, 'pengurus');
$v2()->decide($murobiA, (int) $p2['id'], 'Disetujui', 'Diizinkan', null, 1, $kunci('v2'), $meta, 'murobi');
$v2()->correctDecision($admin, (int) $p2['id'], 'Ditolak', 'Dikoreksi', 'Salah input', 2, $kunci('v2'), $meta);
$assert($status((int) $p2['id']) === 'Ditolak' && $hitung('notifikasi_outbox', "pengajuan_id = {$p2['id']}") > 0,
    'Pengurus V2: keputusan murobi, koreksi admin, dan notifikasi V2 tetap berjalan');

// ===================================================================== J-11
echo PHP_EOL . '=== J-11. CHECK basis data 031 ===' . PHP_EOL;
$coba = static function (string $sql) use ($db): bool {
    try {
        return $db->query($sql) !== false;
    } catch (mysqli_sql_exception) {
        return false;
    }
};
$pbId = (int) $satu("SELECT pengurus_id FROM users WHERE id = {$pb1['id']}");
$hash = str_repeat('a', 64);
$assert(!$coba("INSERT INTO izin_pembimbing_tinjauan (pengajuan_id, tindakan, pembimbing_pengurus_id, pembimbing_assignment_id, catatan, status_sebelum, status_sesudah, versi_sebelum, versi_sesudah, isi_hash, ditinjau_pada)
    VALUES ($id5, 'Tolak', $pbId, $paSatu, NULL, 'Menunggu Pembimbing', 'Ditolak Pembimbing', 50, 51, '$hash', NOW())"), 'CHECK: tolak tanpa alasan ditolak basis data');
$assert(!$coba("INSERT INTO izin_pembimbing_tinjauan (pengajuan_id, tindakan, pembimbing_pengurus_id, pembimbing_assignment_id, status_sebelum, status_sesudah, versi_sebelum, versi_sesudah, isi_hash, murobi_routing_kandidat, ditinjau_pada)
    VALUES ($id5, 'Teruskan', $pbId, $paSatu, 'Menunggu Pembimbing', 'Diajukan', 50, 51, '$hash', 1, NOW())"), 'CHECK: penerusan berstatus Diajukan tanpa murobi ditolak');
$assert(!$coba("INSERT INTO izin_pembimbing_tinjauan (pengajuan_id, tindakan, pembimbing_pengurus_id, pembimbing_assignment_id, catatan, status_sebelum, status_sesudah, versi_sebelum, versi_sesudah, isi_hash, ditinjau_pada)
    VALUES ($id5, 'Tolak', $pbId, $paSatu, 'Alasan', 'Menunggu Pembimbing', 'Ditolak', 50, 51, '$hash', NOW())"), 'CHECK: penolakan pembimbing tidak dapat ditulis sebagai status Ditolak murobi');
$assert(!$coba("INSERT INTO izin_pembimbing_tinjauan (pengajuan_id, tindakan, pembimbing_pengurus_id, pembimbing_assignment_id, catatan, status_sebelum, status_sesudah, versi_sebelum, versi_sesudah, isi_hash, ditinjau_pada)
    VALUES ($id5, 'Minta Perbaikan', $pbId, $paSatu, 'Alasan', 'Menunggu Pembimbing', 'Perlu Perbaikan Wali', 50, 52, '$hash', NOW())"), 'CHECK: versi tinjauan harus naik tepat satu');
$tinjauanAda = (int) $satu("SELECT id FROM izin_pembimbing_tinjauan WHERE pengajuan_id = $id5 AND tindakan = 'Minta Perbaikan' ORDER BY id LIMIT 1");
$waliAId = (int) $satu("SELECT wali_id FROM users WHERE id = {$wA['id']}");
$assert(!$coba("INSERT INTO izin_wali_perbaikan (pengajuan_id, tinjauan_id, wali_id, tgl_izin_sebelum, tgl_kembali_sebelum, alasan_sebelum, tgl_izin_sesudah, tgl_kembali_sesudah, alasan_sesudah, isi_hash_sebelum, isi_hash_sesudah, versi_sebelum, versi_sesudah, dikirim_pada)
    VALUES ($id5, $tinjauanAda + 100000, $waliAId, '2027-01-05', '2027-01-06', 'a', '2027-01-05', '2027-01-06', 'abc', '$hash', '$hash', 60, 61, NOW())"), 'CHECK/kunci asing: perbaikan tanpa perubahan isi atau tanpa permintaan ditolak');

// ===================================================================== J-12
echo PHP_EOL . '=== J-12. Verifier dan preservasi V2 ===' . PHP_EOL;
$v2Sesudah = (string) $satu("SELECT SHA2(GROUP_CONCAT(CONCAT_WS(':', id, status, version) ORDER BY id), 256) FROM izin_pengajuan WHERE id <= $maxV2Sebelum");
$assert($v2Sesudah === $v2Sebelum, 'Baris pengajuan V2/warisan yang ada sebelum uji tidak berubah (ID, status, versi)');
exec('DB_NAME=' . escapeshellarg($dbName) . ' php ' . escapeshellarg($root . '/bin/v7_phase2_verify.php') . ' 2>&1', $out, $kode);
$assert($kode === 0, 'bin/v7_phase2_verify.php lulus sesudah seluruh uji (' . trim((string) end($out)) . ')');
if ($kode !== 0) {
    echo implode(PHP_EOL, array_filter($out, static fn ($l) => str_starts_with($l, '[gagal]'))) . PHP_EOL;
}
$assert($hitung('notifikasi_outbox', '1') > $outboxSebelum, '[info] Outbox hanya bertambah dari jalur pengurus V2');

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
