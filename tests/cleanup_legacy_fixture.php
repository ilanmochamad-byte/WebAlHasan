<?php

declare(strict_types=1);

// Additive synthetic sentinels only. Run after the existing SBX seed.
if (PHP_SAPI !== 'cli' || getenv('CLEANUP_RUN_TESTS') !== '1') exit(77);
require_once dirname(__DIR__) . '/app/bootstrap.php';
if (app_config('env') !== 'testing' || !str_ends_with((string) app_config('database.database'), '_test')) exit(2);
$db = app_db();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if ((int) $db->query('SELECT COUNT(*) n FROM perizinan')->fetch_assoc()['n'] !== 0) {
    exit("Use a fresh synthetic test database, never existing operational data.\n");
}
$db->begin_transaction();
try {
    $db->query("INSERT INTO perizinan (id_santri,tgl_izin,tgl_kembali,alasan,status)
        SELECT id,'2025-01-01','2025-01-02','CLEANUP synthetic history','Pending' FROM santri WHERE nis='SBX-S-001'");
    $db->query("INSERT INTO pelanggaran (id_santri,tgl_pelanggaran,jenis_pelanggaran,poin,hukuman)
        SELECT id,'2025-01-01','CLEANUP <script>synthetic</script>',2,'Synthetic archive' FROM santri WHERE nis='SBX-S-001'");
    $db->query("INSERT INTO alumni (nis,nama_santri,jenis_kelamin,tempat_lahir,tgl_lahir,alamat,desa,kecamatan,kab_kota,
        provinsi,nama_ayah,nama_ibu,asal_sekolah,unit_terakhir,tahun_angkatan,tingkat,status_keluar,tgl_keluar)
        VALUES ('CLEANUP-A','Synthetic alumni','L','Test','2010-01-01','Test','Test','Test','Test',
        'Test','Synthetic father','Synthetic mother','Test','Test','2025','Tsanawi','Lulus','2025-01-01')");
    $db->query("INSERT INTO psb_pendaftar (no_pendaftaran,nama_lengkap,nisn,nik,tempat_lahir,tgl_lahir,jenis_kelamin,
        alamat_jalan,desa,kecamatan,kab_kota,provinsi,nama_ayah,nama_ibu,no_hp_wali,sekolah_asal,jenjang_tujuan)
        VALUES ('CLEANUP-PSB','Synthetic applicant','0000000001','0000000000000001','Test','2014-01-01','L',
        'Test','Test','Test','Test','Test','Synthetic father','Synthetic mother','0800000000','Test','Tsanawi')");
    $db->query("INSERT INTO psb_pembayaran (no_pendaftaran,kategori_biaya,syahriyyah,infaq,rincian_wajib,total_wajib,total_keseluruhan)
        VALUES ('CLEANUP-PSB','Synthetic',100,100,'Synthetic',200,200)");
    $db->commit();
    echo "Synthetic history, alumni and PSB sentinels added; no historical rows removed.\n";
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}
