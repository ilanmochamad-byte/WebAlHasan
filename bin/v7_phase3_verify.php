<?php

declare(strict_types=1);

/**
 * Verifier PRD V7 Fase 3 (notifikasi tahap dan audit). Hanya membaca.
 *
 *   php bin/v7_phase3_verify.php
 *
 * Fase 3 tidak menambah migrasi; jalankan bersama `bin/v7_phase2_verify.php`
 * (invarians skema/alur Fase 1–2). Invarians tambahan atas `notifikasi_outbox`:
 *   - baris peristiwa V7 hanya kanal InApp/Push, TIDAK PERNAH WhatsApp;
 *   - kunci peristiwa `v7:izin:{id}:{peristiwa}:v{versi}` cocok dengan jenis,
 *     menunjuk pengajuan asal Wali, dan versinya tidak melebihi versi baris;
 *   - `pengajuan_id` NULL; data hanya `{tipe: izin_wali, pengajuan_id}`;
 *     judul/isi sama persis dengan katalog generik (tanpa data pribadi);
 *   - tidak ada notifikasi peristiwa V2 (`izin.*` lama) untuk baris asal Wali;
 *   - audit antrean notifikasi hanya memuat kunci metadata.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Izin\IzinWaliNotifikasi;
use App\Notification\NotificationEvent;

$db = app_db();
$gagal = 0;
$periksa = static function (bool $ok, string $label) use (&$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    if (!$ok) {
        $gagal++;
    }
};
$semua = static fn (string $sql): array => $db->query($sql)?->fetch_all(MYSQLI_ASSOC) ?? [];
$jenis = "'" . implode("','", IzinWaliNotifikasi::EVENTS) . "'";

$baris = $semua("SELECT o.id, o.event_key, o.event_type, o.kanal, o.pengajuan_id, o.judul, o.isi, o.data_json,
                        p.id AS pid, p.asal_pengajuan, p.version
                   FROM notifikasi_outbox o
                   LEFT JOIN izin_pengajuan p ON p.id = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(o.event_key, ':', 3), ':', -1) AS UNSIGNED)
                  WHERE o.event_type IN ($jenis) OR o.event_key LIKE 'v7:izin:%'");
echo '[info] Baris notifikasi V7: ' . count($baris) . PHP_EOL;
$salah = ['kanal' => [], 'kunci' => [], 'pengajuan' => [], 'data' => [], 'teks' => []];
foreach ($baris as $r) {
    $ok = preg_match('/^v7:izin:(\d+):([a-z_]+):v(\d+)$/', (string) $r['event_key'], $m) === 1;
    $id = $ok ? (int) $m[1] : 0;
    if (!in_array($r['kanal'], ['InApp', 'Push'], true)) {
        $salah['kanal'][] = (int) $r['id'];
    }
    if (!$ok || !in_array($r['event_type'], IzinWaliNotifikasi::EVENTS, true)
        || IzinWaliNotifikasi::eventKey((string) $r['event_type'], $id, (int) $m[3]) !== (string) $r['event_key']) {
        $salah['kunci'][] = (int) $r['id'];
        continue;
    }
    if ($r['pid'] === null || $r['asal_pengajuan'] !== 'Wali' || (int) $m[3] > (int) $r['version'] || $r['pengajuan_id'] !== null) {
        $salah['pengajuan'][] = (int) $r['id'];
    }
    if (json_decode((string) $r['data_json'], true) !== IzinWaliNotifikasi::data($id)) {
        $salah['data'][] = (int) $r['id'];
    }
    if (IzinWaliNotifikasi::teks((string) $r['event_type'], $id) !== ['judul' => (string) $r['judul'], 'isi' => (string) $r['isi']]) {
        $salah['teks'][] = (int) $r['id'];
    }
}
$periksa($salah['kanal'] === [], 'Notifikasi V7 hanya InApp/Push (nol WhatsApp)' . ($salah['kanal'] === [] ? '' : ': ' . implode(',', array_slice($salah['kanal'], 0, 10))));
$periksa($salah['kunci'] === [], 'Kunci peristiwa V7 cocok dengan jenis dan berformat v7:izin:{id}:{peristiwa}:v{versi}');
$periksa($salah['pengajuan'] === [], 'Kunci menunjuk pengajuan asal Wali, versi tidak melebihi versi baris, pengajuan_id NULL');
$periksa($salah['data'] === [], 'Data tautan hanya {tipe: izin_wali, pengajuan_id}');
$periksa($salah['teks'] === [], 'Judul/isi sama dengan katalog generik (tanpa nama, alasan, tanggal, nomor telepon)');

$v2 = "'" . implode("','", NotificationEvent::PERISTIWA_IZIN) . "'";
$periksa((int) ($db->query("SELECT COUNT(*) FROM notifikasi_outbox o JOIN izin_pengajuan p ON p.id = o.pengajuan_id
    WHERE p.asal_pengajuan = 'Wali' AND o.event_type IN ($v2)")?->fetch_row()[0] ?? -1) === 0,
    'Tidak ada notifikasi peristiwa V2 untuk pengajuan asal Wali');
$periksa((int) ($db->query("SELECT COUNT(*) FROM (SELECT event_key, kanal, penerima_user_id FROM notifikasi_outbox
    WHERE event_key LIKE 'v7:izin:%' GROUP BY event_key, kanal, penerima_user_id HAVING COUNT(*) > 1) d")?->fetch_row()[0] ?? -1) === 0,
    'Paling banyak satu baris per peristiwa, kanal, dan penerima');

$kunciBoleh = ['event', 'version', 'penerima', 'in_app', 'push'];
$auditSalah = 0;
foreach ($semua("SELECT action, after_json FROM audit_logs WHERE action IN ('izin_wali_notifikasi_diantrekan', 'izin_wali_notifikasi_gagal')") as $a) {
    $isi = json_decode((string) $a['after_json'], true);
    if (!is_array($isi) || array_diff(array_keys($isi), $kunciBoleh) !== [] || !in_array($isi['event'] ?? '', IzinWaliNotifikasi::EVENTS, true)) {
        $auditSalah++;
    }
}
$periksa($auditSalah === 0, 'Audit antrean notifikasi V7 hanya memuat metadata');

echo $gagal === 0 ? "Verifikasi V7 Fase 3 lulus.\n" : "Verifikasi V7 Fase 3 GAGAL: {$gagal} pemeriksaan.\n";
exit($gagal > 0 ? 1 : 0);
