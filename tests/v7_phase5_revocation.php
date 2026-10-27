<?php

declare(strict_types=1);

// F5-1: cakupan terkini, pemulihan admin, riwayat, worker palsu, dan balapan
// pencabutan. Hanya DB sintetis, tidak ada request provider nyata.
if (getenv('V7_RUN_AUDIT') !== '1' || !preg_match('/^test_[A-Za-z0-9_]*_test$/D', (string) getenv('DB_NAME'))) exit(2);
require_once dirname(__DIR__) . '/app/bootstrap.php';
use App\Izin\{IzinException, IzinRouter, IzinWaliNotifikasi as N};
use App\Notification\{DeviceRepository, NotificationDispatcher, OutboxRepository, PushTokenProtector, SettingsRepository, WorkerLock};
$db = app_db();
$n = static fn (string $q): int => (int) $db->query($q)->fetch_row()[0];
$rows = static fn (string $q): array => $db->query($q)->fetch_all(MYSQLI_ASSOC);
$user = static function (string $name) use ($db): array {
    $s = $db->prepare('SELECT id,guru_id FROM users WHERE username=?');$s->bind_param('s',$name);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
    $roles=array_column($db->query('SELECT r.slug FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id='.(int)$r['id'])->fetch_all(MYSQLI_ASSOC),'slug');
    return ['id'=>(int)$r['id'],'guru_id'=>$r['guru_id']===null?null:(int)$r['guru_id'],'roles'=>$roles];
};
$code = static function (callable $f): int {try{$f();return 200;}catch(IzinException $e){return $e->status();}};
if (($argv[1]??'')==='--worker') {
    echo $db->thread_id.PHP_EOL;flush();
    echo json_encode(['http'=>$code(fn()=>izin_workflow_service()->decide($user('sbx_murobi_a'),(int)$argv[2],'Disetujui','Probe balapan F5-1',null,2,$argv[3],[],'murobi'))]).PHP_EOL;
    exit;
}
$pass=$fail=0;
$assert=static function(bool $ok,string $label)use(&$pass,&$fail):void{echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;$ok?$pass++:$fail++;};
$w=$user('sbx_v7_wali_a');$pb=$user('sbx_v7_pembimbing_satu');$a=$user('sbx_admin');$m=$user('sbx_murobi_a');$b=$user('sbx_murobi_b');
$sid=$n("SELECT id FROM santri WHERE nis='V7S0008'");$key=static fn():string=>'f51-'.bin2hex(random_bytes(10));
$seq=$n("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan='Wali'");
$make=static function()use(&$seq,$sid,$key,$w,$pb):array {
    $d=(new DateTimeImmutable('2064-01-01'))->modify('+'.(++$seq*3).' days')->format('Y-m-d');
    $id=(int)izin_wali_service()->buat($w,['santri_id'=>$sid,'tgl_izin'=>$d,'tgl_kembali'=>$d,'alasan'=>'Privat fixture F5-1'],$key(),[])['id'];
    izin_wali_service()->tinjau($pb,$id,'teruskan',null,1,$key(),[]);return [$id,$d];
};
$state=static function(int $id)use($rows):string {
    $v=[];foreach(['izin_pengajuan'=>'id','izin_keputusan'=>'pengajuan_id','izin_riwayat_status'=>'pengajuan_id','izin_idempotency_keys'=>'pengajuan_id']as$t=>$column)$v[$t]=$rows("SELECT * FROM $t WHERE $column=$id ORDER BY id");
    $v['audit']=$rows("SELECT * FROM audit_logs WHERE entity_type='izin_pengajuan' AND entity_id=$id ORDER BY id");return hash('sha256',json_encode($v));
};
$assignment=$n("SELECT ma.id FROM murobi_assignments ma JOIN plotting_kamar pk ON pk.id_kamar=ma.kamar_id AND pk.id_tahun=ma.tahun_ajaran_id WHERE pk.id_santri=$sid AND ma.guru_id={$m['guru_id']} AND ma.is_active=1 LIMIT 1");
$original=$rows("SELECT is_active,tanggal_mulai,tanggal_selesai FROM murobi_assignments WHERE id=$assignment")[0];
$restore=static function()use($db,$assignment,$original,$m):void {
    $s=$db->prepare('UPDATE murobi_assignments SET is_active=?,tanggal_mulai=?,tanggal_selesai=? WHERE id=?');$s->bind_param('issi',$original['is_active'],$original['tanggal_mulai'],$original['tanggal_selesai'],$assignment);$s->execute();$s->close();capabilities()->forget($m['id']);
};
try {
    $countDay=(new DateTimeImmutable('2074-01-01'))->modify('+'.$n("SELECT COUNT(*)*3 FROM izin_pengajuan WHERE asal_pengajuan='Wali'").' days')->format('Y-m-d');
    izin_workflow_service()->create($pb,['santri_id'=>$sid,'tgl_izin'=>$countDay,'tgl_kembali'=>$countDay,'alasan'=>'Fixture hitungan semester Pengurus'],$key(),[],'pengurus');
    [$id,$day]=$make();$before=$state($id);
    $db->query("UPDATE murobi_assignments SET is_active=0 WHERE id=$assignment");capabilities()->forget($m['id']);
    $assert(capabilities()->has($m,'murobi') && !(new IzinRouter($db))->coversSantri($m['guru_id'],$sid,date('Y-m-d')), 'Kontrol: hanya cakupan santri hilang, kemampuan murobi lain tetap aktif');
    $assert(izin_service()->list($m,['id'=>$id],1,20,'murobi')['total']===0,'Daftar/antrean V2 menyembunyikan pengajuan Wali di luar cakupan');
    $expected=$n("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan='Pengurus' AND santri_id=$sid AND tahun_ajaran_id=(SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1) AND status<>'Dibatalkan'");
    $assert(izin_service()->santriSemesterCount($m,$sid,'murobi')['total']===$expected,'Hitungan semester mempertahankan Pengurus dan tidak membocorkan jumlah Wali di luar cakupan');
    $filter=['asal'=>'wali','santri_id'=>(string)$sid,'dari'=>$day,'sampai'=>$day];
    $assert(izin_laporan_service()->laporan($m,$filter)['total']===0 && izin_laporan_service()->ekspor($m,$filter)['jumlah']===0,'Laporan dan ekspor V7 sama-sama kosong setelah pencabutan');
    $v2filter=App\Report\IzinReportFilter::fromInput(['date_from'=>$day,'date_to'=>$day,'santri_id'=>(string)$sid],'Asia/Jakarta')->forScope(izin_service()->scopeFor($m,'murobi'));
    $reports=new App\Report\IzinReportRepository($db);
    $assert($reports->summary($v2filter)['total']===0 && $reports->allRows($v2filter)===[],'Ringkasan, ekspor dan cetak laporan V2 memakai cakupan terkini');
    $admin=izin_wali_service()->daftarAdmin($a,['antrean'=>'1']);
    $assert(in_array($id,array_column($admin['items'],'id'),true) && izin_service()->list($a,['id'=>$id,'antrean'=>'1'],1,20,'admin')['total']===1,'Kehilangan murobi sah muncul pada antrean admin V7 dan V2');
    $assert(izin_wali_service()->detail($a,$id)['pengajuan']['perlu_admin']===true && $state($id)===$before,'Bacaan antrean admin hanya menandai, tanpa mutasi ID/status/versi/riwayat/audit');
    $eligible=izin_workflow_service()->eligibleMurobi($id);
    $assert(!in_array($m['guru_id'],array_map('intval',array_column($eligible,'guru_id')),true),'Pilihan penetapan admin menyingkirkan murobi yang tidak mencakup santri');
    $assert($code(fn()=>izin_workflow_service()->assignMurobi($a,$id,$m['guru_id'],'Bukan cakupan',2,$key(),[]))===422 && $state($id)===$before,'Admin tidak dapat mengembalikan tujuan tanpa cakupan, tanpa mutasi');
    $h=izin_workflow_service()->decide($a,$id,'Ditolak','Keputusan admin','Murobi dipindahkan',2,$key(),[],'admin');
    $assert($h['id']===$id && $h['status']==='Ditolak' && $n("SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id=$id AND kapasitas='Admin Pengganti'")===1,'Admin pengganti beralasan menyelesaikan pengajuan yang kehilangan murobi pada ID sama');
    $assert($code(fn()=>izin_wali_service()->detail($m,$id))===404 && $code(fn()=>izin_service()->detail($m,$id,'murobi'))===403,'Riwayat terminal juga tertutup bagi murobi yang tidak lagi mencakup santri');
    $restore();

    // Keputusan yang sudah berhasil tetap tidak boleh di-replay setelah cakupan dicabut.
    [$id]=$make();$k=$key();izin_workflow_service()->decide($m,$id,'Disetujui','Keputusan sebelum pencabutan',null,2,$k,[],'murobi');$before=$state($id);
    $db->query("UPDATE murobi_assignments SET is_active=0 WHERE id=$assignment");
    $assert($code(fn()=>izin_workflow_service()->decide($m,$id,'Disetujui','Keputusan sebelum pencabutan',null,2,$k,[],'murobi'))===403 && $state($id)===$before,'Replay keputusan lama ditolak 403 setelah pencabutan sebagian, tanpa membuka hasil atau mutasi');
    $restore();
    $assert(izin_workflow_service()->decide($m,$id,'Disetujui','Keputusan sebelum pencabutan',null,2,$k,[],'murobi')['idempotent_replay']===true,'Penugasan sah dipulihkan: replay tetap satu keputusan, tidak menghilangkan riwayat');

    // Penugasan, masa berlaku, dan penempatan berubah saat worker menunggu.
    $pk=$rows("SELECT id,id_kamar FROM plotting_kamar WHERE id_santri=$sid ORDER BY id LIMIT 1")[0];
    foreach(['penugasan','tanggal','penempatan']as$kind){
        [$id]=$make();$before=$state($id);
        try{
            $db->begin_transaction();
            $db->query($kind==='tanggal'?"SELECT id FROM murobi_assignments WHERE id=$assignment FOR UPDATE":"SELECT id FROM izin_pengajuan WHERE id=$id FOR UPDATE");
            $p=proc_open([PHP_BINARY,__FILE__,'--worker',(string)$id,$key()],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
            $thread=(int)fgets($pipes[1]);$waited=false;$until=microtime(true)+8;
            do{$info=$db->query('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID='.$thread)->fetch_row()[0]??'';
                if(str_contains((string)$info,$kind==='tanggal'?'SELECT id FROM murobi_assignments':'SELECT p.* FROM izin_pengajuan')){$waited=true;break;}usleep(20000);
            }while(microtime(true)<$until);
            $assert($waited,"Kontrol $kind: worker benar-benar menunggu kunci");
            if($kind==='penugasan')$db->query("UPDATE murobi_assignments SET is_active=0 WHERE id=$assignment");
            elseif($kind==='tanggal')$db->query("UPDATE murobi_assignments SET tanggal_mulai=CURDATE()-INTERVAL 10 DAY,tanggal_selesai=CURDATE()-INTERVAL 1 DAY WHERE id=$assignment");
            else{$km=$n("SELECT id FROM kamar WHERE nama_kamar='SBX V7 Kamar Tanpa Murobi'");$db->query('UPDATE plotting_kamar SET id_kamar='.$km.' WHERE id='.(int)$pk['id']);}
            $changed=$db->affected_rows>0;$db->commit();
            $raw=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);$exit=proc_close($p);$answer=json_decode(trim($raw),true);
            $assert($changed && $exit===0 && ($answer['http']??0)===403 && $state($id)===$before,"Pencabutan $kind saat menunggu: 403, nol keputusan dan riwayat baru");
        }finally{$db->rollback();$restore();$db->query('UPDATE plotting_kamar SET id_kamar='.(int)$pk['id_kamar'].' WHERE id='.(int)$pk['id']);}
    }

    // Pemulihan lewat penetapan admin, bukan pembukaan akses otomatis kepada kandidat lain.
    [$id]=$make();$before=$state($id);
    $assert($code(fn()=>izin_workflow_service()->assignMurobi($a,$id,$b['guru_id'],'Belum mencakup',2,$key(),[]))===422 && $state($id)===$before,'Murobi aktif lain tidak dapat dipilih sebelum mencakup santri');
    require_once __DIR__.'/v7_phase5_fixture.php';$new=v7f5_fixture_murobi($db,$b['guru_id'],$sid);
    $assert($code(fn()=>izin_wali_service()->detail($b,$id))===404,'Penugasan baru saja tidak membuka pengajuan yang masih ditujukan kepada murobi lain');
    izin_workflow_service()->assignMurobi($a,$id,$b['guru_id'],'Penugasan pengganti sah',2,$key(),[]);
    $assert($code(fn()=>izin_wali_service()->detail($m,$id))===404 && $code(fn()=>izin_wali_service()->detail($b,$id))===200,'Penetapan ulang beralasan memindah akses ke murobi tujuan baru yang sah');
    izin_workflow_service()->decide($b,$id,'Disetujui','Keputusan pengganti sah',null,3,$key(),[],'murobi');
    $assert($n("SELECT COUNT(*) FROM izin_pengajuan WHERE id=$id")===1 && $n("SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id=$id")===1
        && $n("SELECT COUNT(*) FROM izin_riwayat_status WHERE pengajuan_id=$id AND peristiwa='murobi_ditetapkan_ulang' AND alasan IS NOT NULL")===1,'Rerouting sah mempertahankan ID dan satu keputusan dengan jejak beralasan');
    $db->query('UPDATE murobi_assignments SET is_active=0 WHERE id='.$new);

    // Jalur Pengurus V2 tetap memakai aturan lamanya.
    $day=(new DateTimeImmutable('2068-01-01'))->modify('+'.random_int(1,2000).' days')->format('Y-m-d');
    $v2=izin_workflow_service()->create($pb,['santri_id'=>$sid,'tgl_izin'=>$day,'tgl_kembali'=>$day,'alasan'=>'Preservasi jalur Pengurus'],$key(),[],'pengurus');$id=(int)$v2['id'];
    $db->query("UPDATE murobi_assignments SET is_active=0 WHERE id=$assignment");capabilities()->forget($m['id']);
    $assert($code(fn()=>izin_service()->detail($m,$id,'murobi'))===200 && izin_workflow_service()->decide($m,$id,'Ditolak','Jalur Pengurus V2',null,1,$key(),[],'murobi')['status']==='Ditolak','Pencabutan parsial tidak mengubah kontrak baca/keputusan asal Pengurus V2');
    $restore();
}finally{$restore();}
echo "Hasil: $pass lulus, $fail gagal\n";exit($fail===0?0:1);
