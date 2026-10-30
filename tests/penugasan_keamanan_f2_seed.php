<?php

declare(strict_types=1);

/**
 * Skenario sintetis Fase 2 (pemisahan tugas) untuk uji HTTP dan browser, di
 * atas DB `tests/penugasan_keamanan_f2_db.sh`. Seluruh data dibentuk lewat
 * layanan resmi; hanya dua langkah memaksa data agar jalur penolakan dapat
 * diuji (pengajuan diarahkan ke pengaju sendiri, seolah data lama/race).
 *
 * Akun X = `sbx_guru_biasa`: pembimbing + murobi kamar 381 dan kelas 333,
 * Bagian Pendidikan, dan Guru Mapel satu mapel kelas 333.
 *
 *   php tests/penugasan_keamanan_f2_seed.php     # DB dari PKF2_DB, mencetak ID JSON
 *   $ids = require 'tests/penugasan_keamanan_f2_seed.php';   # dari uji lain (DB sudah dipilih)
 *
 * Hanya sekali per DB; bangun ulang DB sebelum mengulang.
 */

use App\Auth\Capabilities;
use App\Penugasan\PenugasanJenis;
use App\V4\NilaiService;
use App\V4\SikapService;

if (!function_exists('app_db')) {
    $nama = getenv('PKF2_DB') ?: 'test_pk_f2_browser_test';
    if (!preg_match('/^test_.*_test$/', $nama)) {
        fwrite(STDERR, "DB uji harus bernama test_*_test.\n");
        exit(2);
    }
    putenv('DB_NAME=' . $nama);
    require_once dirname(__DIR__) . '/app/bootstrap.php';
}

$pkf2Ids = (static function (): array {
    $conn = app_db();
    $satu = static function (string $sql) use ($conn): mixed {
        $r = $conn->query($sql);
        if ($r === false) {
            throw new RuntimeException($conn->error . ' :: ' . $sql);
        }

        return $r->fetch_row()[0] ?? null;
    };
    $jalankan = static function (string $sql) use ($conn): void {
        if ($conn->query($sql) === false) {
            throw new RuntimeException($conn->error . ' :: ' . $sql);
        }
    };
    $caps = capabilities();
    $akun = static function (int $userId) use ($satu, $caps): array {
        $caps->forget($userId);
        $roles = (string) $satu("SELECT GROUP_CONCAT(r.slug ORDER BY r.slug) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = $userId");
        $guru = $satu("SELECT guru_id FROM users WHERE id = $userId");

        return ['id' => $userId, 'roles' => $roles === '' ? [] : explode(',', $roles), 'guru_id' => $guru === null ? null : (int) $guru];
    };
    $kunci = static fn (string $awal): string => 'pkf2s-' . $awal . '-' . bin2hex(random_bytes(6));
    $meta = ['ip' => '127.0.0.1', 'user_agent' => 'pkf2-seed'];
    $hari = date('Y-m-d');
    $tanggal = static fn (int $tambah): string => date('Y-m-d', strtotime("+$tambah day"));
    $id = static fn (string $u): int => (int) $satu("SELECT id FROM users WHERE username = '$u'");

    $svc = penugasan_service();
    $alur = izin_workflow_service();
    $admin = $id('sbx_admin');
    $x = $id('sbx_guru_biasa');
    $guruX = (int) $satu("SELECT guru_id FROM users WHERE id = $x");
    $tahun = (int) $satu("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1");
    $kamar = 381;
    $kelas = 333;
    $santriKamar = 1721;
    $santriKelas = 1694;
    $jalankan("UPDATE pembimbing_assignments SET is_active = 0 WHERE kamar_id = $kamar");
    $jalankan("UPDATE murobi_assignments SET is_active = 0 WHERE kamar_id = $kamar");
    $svc->buat(PenugasanJenis::PEMBIMBING, ['pengurus_id' => 'guru:' . $guruX, 'tahun_ajaran_id' => $tahun, 'target_type' => 'Kamar', 'kamar_id' => $kamar, 'tanggal_mulai' => $hari], $admin);
    $svc->buat(PenugasanJenis::MUROBI, ['guru_id' => $guruX, 'tahun_ajaran_id' => $tahun, 'target_type' => 'Kamar', 'kamar_id' => $kamar, 'tanggal_mulai' => $hari], $admin);
    $svc->buat(PenugasanJenis::PEMBIMBING, ['pengurus_id' => 'guru:' . $guruX, 'tahun_ajaran_id' => $tahun, 'target_type' => 'Kelas', 'kelas_id' => $kelas, 'tanggal_mulai' => $hari], $admin);
    $svc->buat(PenugasanJenis::MUROBI, ['guru_id' => $guruX, 'tahun_ajaran_id' => $tahun, 'target_type' => 'Kelas', 'kelas_id' => $kelas, 'tanggal_mulai' => $hari], $admin);
    $svc->buat(PenugasanJenis::PENDIDIKAN, ['pengurus_id' => 'guru:' . $guruX, 'tahun_ajaran_id' => $tahun, 'jenjang' => '', 'tanggal_mulai' => $hari], $admin);
    $rencana = (int) $satu("SELECT id FROM v4_rencana WHERE kelas_id = $kelas AND tahun_ajaran_id = $tahun");
    $rmX = (int) $satu("SELECT id FROM v4_rencana_mapel WHERE rencana_id = $rencana ORDER BY id DESC LIMIT 1");
    $svc->buat(PenugasanJenis::GURU_MAPEL, ['guru_id' => $guruX, 'mata_pelajaran_id' => (int) $satu("SELECT mata_pelajaran_id FROM v4_rencana_mapel WHERE id = $rmX"), 'kelas_id' => $kelas, 'tahun_ajaran_id' => $tahun, 'tanggal_mulai' => $hari], $admin);

    $izinX = (int) $alur->create($akun($x), ['santri_id' => $santriKamar, 'tgl_izin' => $tanggal(1), 'tgl_kembali' => $tanggal(2), 'alasan' => 'Uji pemisahan tugas'], $kunci('v2'), $meta, Capabilities::PENGURUS)['id'];
    // Dipaksa: seolah routing lama/race mengarahkan ke pengaju sendiri.
    $jalankan("UPDATE izin_pengajuan SET murobi_guru_id = $guruX, status = 'Diajukan' WHERE id = $izinX");
    $izinAdmin = (int) $alur->create($akun($admin), ['santri_id' => 1719, 'tgl_izin' => $tanggal(3), 'tgl_kembali' => $tanggal(4), 'alasan' => 'Dibuat admin'], $kunci('v2a'), $meta, Capabilities::ADMIN)['id'];
    (new SikapService($conn, audit_logger()))->save($akun($x), ['rencana_id' => $rencana, 'santri_id' => $santriKelas, 'adab' => 'Baik', 'kedisiplinan' => 'Baik', 'tanggung_jawab' => 'Baik', 'catatan' => '', 'version' => 0, 'action' => 'serahkan'], 'pembimbing');
    $komponen = [['komponen' => 'Harian', 'nomor_harian' => 1, 'nilai' => 80, 'version' => 0, 'catatan' => ''], ['komponen' => 'Tengah', 'nomor_harian' => 0, 'nilai' => 80, 'version' => 0, 'catatan' => ''], ['komponen' => 'Akhir', 'nomor_harian' => 0, 'nilai' => 80, 'version' => 0, 'catatan' => '']];
    (new NilaiService($conn, $caps, audit_logger()))->save($akun($x), ['rencana_mapel_id' => $rmX, 'santri_id' => $santriKelas, 'komponen' => $komponen, 'action' => 'serahkan']);
    $katalog = (int) $satu('SELECT id FROM v3_katalog WHERE is_active = 1 AND archived_at IS NULL AND tanggal_mulai <= CURDATE() AND (tanggal_selesai IS NULL OR tanggal_selesai >= CURDATE()) ORDER BY id LIMIT 1');
    $idPel = (int) v3_pelanggaran_service()->create($akun($x), ['santri_id' => 1714, 'tahun_ajaran_id' => $tahun, 'katalog_id' => $katalog, 'waktu_kejadian' => date('Y-m-d H:i', time() - 3600), 'uraian' => 'Uji pemisahan tugas V3', 'idempotency_key' => $kunci('v3')])['data']['pelanggaran']['id'];
    $kasus = (int) v3_konseling_service()->createCase($akun($x), ['santri_id' => 1714, 'tahun_ajaran_id' => $tahun, 'tujuan' => 'Pendampingan uji', 'kerahasiaan' => 'Internal', 'idempotency_key' => $kunci('v3k')])['data']['kasus']['id'];

    return [
        'admin' => $admin, 'x' => $x, 'guru_x' => $guruX, 'tahun' => $tahun, 'rencana' => $rencana, 'rm_x' => $rmX,
        'santri_kelas' => $santriKelas, 'izin_x' => $izinX, 'izin_admin' => $izinAdmin, 'pelanggaran' => $idPel, 'kasus' => $kasus,
        'versi_sikap' => (int) $satu("SELECT version FROM v4_sikap WHERE santri_id = $santriKelas AND tahun_ajaran_id = $tahun"),
    ];
})();

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    echo json_encode($pkf2Ids, JSON_PRETTY_PRINT) . PHP_EOL;
}

return $pkf2Ids;
