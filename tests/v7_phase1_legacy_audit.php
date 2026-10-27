<?php

declare(strict_types=1);

// Lengkapi uji migrasi dengan baris warisan yang benar-benar berisi.
if (getenv('V7_RUN_AUDIT') !== '1' || !preg_match('/^test_v7_audit_.*_test$/D', (string) getenv('DB_NAME'))) {
    exit(2);
}
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
$db = app_db();
$last = $db->query('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1')->fetch_row()[0];
if ($last !== '029_v6_fase3_tabungan.sql') {
    throw new RuntimeException('Fixture harus berada sebelum 030.');
}
$santri = (int) $db->query("SELECT id FROM santri WHERE nis='SBX-S-001'")->fetch_row()[0];
$id = max((int) $db->query('SELECT COALESCE(MAX(id),0) FROM perizinan')->fetch_row()[0],
    (int) $db->query('SELECT MAX(id) FROM izin_pengajuan')->fetch_row()[0]) + 100;
$ids = [];
$db->begin_transaction();
foreach (['Pending' => 'Diajukan', 'Disetujui' => 'Disetujui', 'Ditolak' => 'Ditolak'] as $old => $status) {
    $id++;
    $ids[] = $id;
    // Bentuk hasil migrasi 006: ID kanonis = ID V1, tanpa pelaku rekaan.
    foreach ([
        "INSERT INTO perizinan(id,id_santri,tgl_izin,tgl_kembali,alasan,status)
            VALUES($id,$santri,'2025-01-01','2025-01-02','Izin warisan sintetis audit','$old')",
        "INSERT INTO izin_pengajuan(id,legacy_perizinan_id,is_legacy,santri_id,tgl_izin,tgl_kembali,alasan,status)
            VALUES($id,$id,1,$santri,'2025-01-01','2025-01-02','Izin warisan sintetis audit','$status')",
        "INSERT INTO izin_riwayat_status(pengajuan_id,peristiwa,status_sesudah,alasan)
            VALUES($id,'migrasi_warisan','$status','Data warisan V1: pelaku tidak tercatat pada sistem lama.')",
    ] as $sql) {
        if (!$db->query($sql)) { throw new RuntimeException($db->error); }
    }
}
$db->commit();
echo '[info] Tiga perizinan V1 + tiga pengajuan is_legacy + tiga riwayat migrasi dengan ID sama ditambahkan sebelum snapshot.' . PHP_EOL;
$p = proc_open([PHP_BINARY, $root . '/tests/v7_phase1_migration.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root,
    array_merge(getenv(), ['V7F1_DB' => (string) getenv('DB_NAME')]));
echo stream_get_contents($pipes[1]), stream_get_contents($pipes[2]);
$code = proc_close($p);
$list = implode(',', $ids);
$n = (int) $db->query("SELECT COUNT(*) FROM izin_pengajuan p JOIN perizinan old ON old.id=p.id AND old.id=p.legacy_perizinan_id
    JOIN izin_riwayat_status r ON r.pengajuan_id=p.id AND r.peristiwa='migrasi_warisan'
    WHERE p.id IN ($list) AND p.is_legacy=1 AND p.pengurus_id IS NULL AND p.diajukan_oleh_user_id IS NULL
      AND old.alasan=p.alasan AND old.tgl_izin=p.tgl_izin AND old.tgl_kembali=p.tgl_kembali
      AND p.status=CASE old.status WHEN 'Pending' THEN 'Diajukan' ELSE old.status END")->fetch_row()[0];
echo ($n === 3 ? '[lulus]' : '[gagal]') . ' Tiga ID, nilai bisnis, pelaku NULL, dan riwayat warisan tetap terbaca sesudah seluruh siklus.' . PHP_EOL;
exit($code === 0 && $n === 3 ? 0 : 1);
