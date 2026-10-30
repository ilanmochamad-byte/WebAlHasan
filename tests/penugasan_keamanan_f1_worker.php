<?php

declare(strict_types=1);

/**
 * Worker konkurensi Fase 1 penugasan lintas guru/pengurus. Menunggu sampai
 * detik awal yang sama dengan worker lain, lalu membuat satu penugasan untuk
 * seorang guru lewat `PenugasanService::buat()` dan mencetak hasil JSON.
 *
 *   php tests/penugasan_keamanan_f1_worker.php <db> <jenis> <guruId> <aktorId> <mulaiMikrodetik>
 */

[$_, $dbName, $jenis, $guruId, $aktorId, $mulai] = $argv + [null, '', '', '0', '0', '0'];
if (!preg_match('/^test_.*_test$/', (string) $dbName)) {
    fwrite(STDERR, "DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$input = [
    'pengurus_id' => 'guru:' . (int) $guruId,
    'tahun_ajaran_id' => (int) app_db()->query("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1")->fetch_row()[0],
    'target_type' => 'Kamar',
    'kamar_id' => (int) app_db()->query('SELECT id FROM kamar ORDER BY id LIMIT 1')->fetch_row()[0],
    'tanggal_mulai' => date('Y-m-d'),
];
while ((int) (microtime(true) * 1e6) < (int) $mulai) {
    usleep(500);
}
try {
    $id = penugasan_service()->buat((string) $jenis, $input, (int) $aktorId);
    echo json_encode(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'kelas' => get_class($e), 'pesan' => $e->getMessage()]);
}
