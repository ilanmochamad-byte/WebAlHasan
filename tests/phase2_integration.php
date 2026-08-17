<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;

$root = dirname(__DIR__);
if (getenv('PHASE2_RUN_INTEGRATION') !== '1') {
    fwrite(STDOUT, "[lewati] Set PHASE2_RUN_INTEGRATION=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}
require_once $root . '/app/bootstrap.php';
if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Pengujian integrasi ditolak: DB_NAME wajib berakhiran _test.\n");
    exit(2);
}

$db = app_db();
$service = master_data_service();
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) { $failures[] = $message; }
};
$admin = $db->query("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.slug='admin' AND u.is_active=1 LIMIT 1")?->fetch_assoc();
if (!$admin) { fwrite(STDERR, "Akun admin fixture tidak tersedia.\n"); exit(2); }
$_SESSION['user_id'] = (int) $admin['id'];
$suffix = strtoupper(bin2hex(random_bytes(3)));
$created = ['guru' => [], 'santri' => [], 'wali' => [], 'pengurus' => [], 'kelas' => [], 'tahun' => [], 'murobi' => []];
$oldActive = $db->query("SELECT id FROM tahun_ajaran WHERE status='Aktif' LIMIT 1")?->fetch_assoc();

try {
    $guruId = $service->saveGuru(['nip' => 'T' . $suffix, 'nama_guru' => 'Guru Uji ' . $suffix, 'no_hp' => '081234567890', 'status' => 'Guru']);
    $created['guru'][] = $guruId;
    $santriId = $service->saveSantri(['nis'=>'S'.$suffix,'nama_santri'=>'Santri Uji '.$suffix,'jenis_kelamin'=>'L','tempat_lahir'=>'Ciamis','tgl_lahir'=>'2010-01-02','alamat'=>'Alamat Uji','desa'=>'Desa','kecamatan'=>'Kecamatan','kab_kota'=>'Ciamis','provinsi'=>'Jawa Barat','nama_ayah'=>'Ayah Uji','no_hp_ayah'=>'081234567891','nama_ibu'=>'Ibu Uji','no_hp_ibu'=>'081234567892','asal_sekolah'=>'Sekolah A','sekolah_saat_ini'=>'Sekolah B','foto'=>'default.jpg']);
    $created['santri'][] = $santriId;
    $assert($service->guru($guruId)!==null && $service->guruList(['q'=>$suffix,'state'=>'all'],1)['total']===1, 'Guru dapat dibuat, dilihat, dicari, dan difilter');
    $assert($service->santri($santriId)!==null && $service->santriList(['q'=>$suffix,'state'=>'all'],1)['total']===1, 'Santri dapat dibuat, dilihat, dicari, dan difilter');

    try { $service->saveGuru(['nip'=>'T'.$suffix,'nama_guru'=>'Duplikat','no_hp'=>'','status'=>'Guru']); $assert(false,'NIP duplikat ditolak'); } catch (MasterDataException) { $assert(true,'NIP duplikat ditolak'); }
    try { $service->saveSantri(['nis'=>'S'.$suffix,'nama_santri'=>'Duplikat','jenis_kelamin'=>'L','tgl_lahir'=>'2010-01-02','foto'=>'default.jpg']); $assert(false,'NIS duplikat ditolak'); } catch (MasterDataException) { $assert(true,'NIS duplikat ditolak'); }

    $service->setGuruState($guruId,'deactivate'); $service->setSantriState($santriId,'deactivate');
    $assert($service->guru($guruId)!==null && $service->santri($santriId)!==null, 'Nonaktif tidak menghapus guru atau santri');
    $service->setGuruState($guruId,'activate'); $service->setSantriState($santriId,'activate');

    $santri2 = $service->saveSantri(['nis'=>'R'.$suffix,'nama_santri'=>'Saudara Uji '.$suffix,'jenis_kelamin'=>'P','tempat_lahir'=>'Ciamis','tgl_lahir'=>'2011-02-03','alamat'=>'Alamat Uji','desa'=>'Desa','kecamatan'=>'Kecamatan','kab_kota'=>'Ciamis','provinsi'=>'Jawa Barat','asal_sekolah'=>'Sekolah A','sekolah_saat_ini'=>'Sekolah B','foto'=>'default.jpg']);
    $created['santri'][]=$santri2;
    $waliId=$service->saveWali(['nama'=>'Wali Bersama '.$suffix,'no_hp'=>'081234567893','alamat'=>'Alamat Uji']);$created['wali'][]=$waliId;
    $service->attachWali($waliId,['santri_id'=>$santriId,'hubungan'=>'Wali','is_primary'=>1],(int)$admin['id']);
    $service->attachWali($waliId,['santri_id'=>$santri2,'hubungan'=>'Wali'],(int)$admin['id']);
    $assert(count($service->wali($waliId)['relations'])===2,'Satu wali terhubung ke dua santri dan dapat dibaca kembali');

    $yearId=$service->saveYear(['tahun'=>'2098/2099','semester'=>'Ganjil']);$created['tahun'][]=$yearId;$service->activateYear($yearId);
    $activeCount=(int)($db->query("SELECT COUNT(*) total FROM tahun_ajaran WHERE status='Aktif'")->fetch_assoc()['total']??0);
    $assert($activeCount===1,'Tepat satu semester aktif');
    $class1=$service->saveClass(['nama_kelas'=>'Kelas A '.$suffix,'jenjang'=>'Uji']);$class2=$service->saveClass(['nama_kelas'=>'Kelas B '.$suffix,'jenjang'=>'Uji']);$created['kelas']=[$class1,$class2];
    $service->assignActiveClass(['santri_id'=>$santriId,'kelas_id'=>$class1,'tanggal_mulai'=>'2098-07-01'],(int)$admin['id']);
    $service->assignActiveClass(['santri_id'=>$santriId,'kelas_id'=>$class2,'tanggal_mulai'=>'2098-08-01'],(int)$admin['id']);
    $history=$service->membershipHistory($santriId);$assert(count($history)>=2 && count(array_filter($history,fn($r)=>$r['status']==='Aktif'))===1,'Keanggotaan kelas baru aktif dan riwayat lama tersimpan');

    $room=$service->kamarOptions()[0]??null;
    if($room){$murobiId=$service->saveMurobi(['guru_id'=>$guruId,'tahun_ajaran_id'=>$yearId,'target_type'=>'Kamar','kamar_id'=>$room['id'],'tanggal_mulai'=>'2098-07-01','tanggal_selesai'=>''],(int)$admin['id']);$created['murobi'][]=$murobiId;$accountCount=(int)($db->query('SELECT COUNT(*) total FROM users WHERE guru_id='.(int)$guruId)->fetch_assoc()['total']??0);$assert($accountCount===0,'Penugasan murobi tidak membuat akun atau akses approval izin');}
    else{$assert(false,'Fixture kamar tersedia untuk penugasan murobi');}

    $guruFilter=['q'=>$suffix,'state'=>'all'];$santriFilter=['q'=>$suffix,'state'=>'all','gender'=>'','kelas_id'=>''];
    $assert(count($service->exportGuru($guruFilter))===$service->guruList($guruFilter,1,100)['total'],'Jumlah ekspor guru sama dengan hasil filter UI');
    $assert(count($service->exportSantri($santriFilter))===$service->santriList($santriFilter,1,100)['total'],'Jumlah ekspor santri sama dengan hasil filter UI');
    $audit=$db->query("SELECT actor_user_id,created_at,before_json,after_json FROM audit_logs WHERE entity_id={$guruId} AND entity_type='guru' ORDER BY id DESC LIMIT 1")?->fetch_assoc();
    $assert($audit && (int)$audit['actor_user_id']===(int)$admin['id'] && $audit['created_at'] && !str_contains(strtolower(($audit['before_json']??'').($audit['after_json']??'')),'password'),'Audit menyimpan pelaku dan waktu tanpa nilai rahasia');
} finally {
    if($oldActive){$service->activateYear((int)$oldActive['id']);}
    foreach(array_reverse($created['murobi']) as $id){$db->query('DELETE FROM murobi_assignments WHERE id='.(int)$id);}
    $relatedWali=[];
    foreach($created['santri'] as $id){$result=$db->query('SELECT wali_id FROM santri_wali WHERE santri_id='.(int)$id);while($result&&$row=$result->fetch_assoc()){$relatedWali[]=(int)$row['wali_id'];}}
    foreach(array_reverse($created['santri']) as $id){$db->query('DELETE FROM santri_wali WHERE santri_id='.(int)$id);$db->query('DELETE FROM plotting_kelas WHERE id_santri='.(int)$id);$db->query('DELETE FROM santri WHERE id='.(int)$id);}
    foreach(array_unique(array_merge($created['wali'],$relatedWali)) as $id){$db->query('DELETE FROM santri_wali WHERE wali_id='.(int)$id);$db->query('DELETE FROM wali WHERE id='.(int)$id);}
    foreach(array_reverse($created['kelas']) as $id){$db->query('DELETE FROM kelas WHERE id='.(int)$id);}
    foreach(array_reverse($created['tahun']) as $id){$db->query('DELETE FROM tahun_ajaran WHERE id='.(int)$id);}
    foreach(array_reverse($created['guru']) as $id){$db->query('DELETE FROM guru WHERE id='.(int)$id);}
}
exit($failures===[]?0:1);
