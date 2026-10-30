<?php
declare(strict_types=1);

// Jalankan sesudah f2_integration pada DB sintetis yang sama. Untuk kontrol
// cache sebelum Fase 2, PKF2_BASELINE menunjuk arsip 464d9a1 (.env/vendor lokal).
$name=getenv('PKF2_DB')?:'test_pk_f2_audit_test';
if(!preg_match('/^test_[A-Za-z0-9_]+_test$/D',$name))exit(2);
putenv('DB_NAME='.$name);
$legacy=($argv[1]??'')==='--legacy';
require ($legacy?getenv('PKF2_BASELINE'):(getenv('PKF2_KODE')?:dirname(__DIR__))).'/app/bootstrap.php';
use App\Izin\IzinException;
use App\Penugasan\PenugasanJenis as J;
use App\V3\V3Exception;
$db=app_db();
$one=static fn(string $q):mixed=>$db->query($q)->fetch_row()[0]??null;
$user=static function(string $name)use($db):array{
    $s=$db->prepare('SELECT id,guru_id FROM users WHERE username=?');$s->bind_param('s',$name);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
    capabilities()->forget((int)$r['id']);
    return ['id'=>(int)$r['id'],'guru_id'=>$r['guru_id']===null?null:(int)$r['guru_id'],'roles'=>array_column($db->query('SELECT r.slug FROM roles r JOIN user_roles ur ON ur.role_id=r.id WHERE ur.user_id='.(int)$r['id'])->fetch_all(MYSQLI_ASSOC),'slug')];
};
$invoke=static function(array $in)use($user):array{
    $u=$user($in['user']);$s=izin_workflow_service();
    return match($in['op']){
        'v3p'=>v3_pelanggaran_service()->acknowledge($u,$in['id'],['idempotency_key'=>$in['key']]),
        'v3k','v3s'=>v3_konseling_service()->acknowledge($u,$in['op']==='v3k'?'kasus':'sesi',$in['id'],['idempotency_key'=>$in['key']]),
        'decide'=>$s->decide($u,$in['id'],'Disetujui','Probe cache lama',$in['mode']==='admin'?'Pengganti beralasan':null,$in['version'],$in['key'],[],$in['mode']),
        'correct'=>$s->correctDecision($u,$in['id'],'Ditolak','Probe cache koreksi','Koreksi beralasan',$in['version'],$in['key'],[]),
        'assign'=>$s->assignMurobi($u,$in['id'],$in['guru'],'Penetapan lama',$in['version'],$in['key'],[]),
    };
};
if($legacy){echo json_encode($invoke(json_decode($argv[2],true)),JSON_THROW_ON_ERROR);exit;}
$pass=$fail=0;
$assert=static function(bool $ok,string $label)use(&$pass,&$fail):void{echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;$ok?$pass++:$fail++;};
$code=static function(callable $f):int{try{$f();return 200;}catch(IzinException $e){return $e->status();}catch(V3Exception $e){return $e->status;}};
$key=static fn():string=>'pkf2-audit-'.bin2hex(random_bytes(10));
$x=$user('sbx_guru_biasa');$y=$user('sbx_murobi_a');$admin=$user('sbx_admin');$otherAdmin=$user('sbx_v6_admin_bsa');
$tahun=(int)$one("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1");
// Y menjadi pembimbing lain yang sah; kasus bukan milik X.
if(!(int)$one("SELECT COUNT(*) FROM pembimbing_assignments pa JOIN users u ON u.pengurus_id=pa.pengurus_id WHERE u.id={$y['id']} AND pa.kamar_id=381 AND pa.is_active=1"))
    penugasan_service()->buat(J::PEMBIMBING,['pengurus_id'=>'guru:'.$y['guru_id'],'tahun_ajaran_id'=>$tahun,'target_type'=>'Kamar','kamar_id'=>381,'tanggal_mulai'=>date('Y-m-d')],$admin['id']);
$y=$user('sbx_murobi_a');$k=v3_konseling_service();
$case=(int)$k->createCase($y,['santri_id'=>1714,'tahun_ajaran_id'=>$tahun,'tujuan'=>'Probe riwayat sesi','kerahasiaan'=>'Internal','idempotency_key'=>$key()])['data']['kasus']['id'];
$session=(int)$k->createSession($y,$case,['jadwal'=>date('Y-m-d\TH:i',time()+7200),'tindak_lanjut'=>'Awal','idempotency_key'=>$key()])['data']['sesi']['id'];
$rev1=$k->correctSession($x,$session,['version'=>1,'alasan'=>'Koreksi oleh X','tindak_lanjut'=>'Koreksi X','idempotency_key'=>$key()])['data']['sesi'];
$rev2=$k->correctSession($y,(int)$rev1['id'],['version'=>$rev1['version'],'alasan'=>'Koreksi oleh Y','tindak_lanjut'=>'Koreksi Y','idempotency_key'=>$key()])['data']['sesi'];
$assert(!$k->milikSendiri($x,'kasus',$case),'Kontrol: kasus tidak dimiliki/diperbarui X');
$assert($k->milikSendiri($x,'sesi',(int)$rev2['id']),'F-1 riwayat revisi sesi tetap mengenali X sebagai pengisi');
$n=(int)$one('SELECT COUNT(*) FROM v3_murobi_catatan');
$assert($code(fn()=>$k->acknowledge($x,'sesi',(int)$rev2['id'],['idempotency_key'=>$key()]))===403&&(int)$one('SELECT COUNT(*) FROM v3_murobi_catatan')===$n,'F-1 X tidak mengetahui revisi terbaru sesi yang pernah ia isi');

// Batas lama 100 revisi menghilangkan pengisi pada rantai yang lebih panjang.
$p=v3_pelanggaran_service();$catalog=(int)$one('SELECT id FROM v3_katalog WHERE is_active=1 AND archived_at IS NULL AND tanggal_mulai<=CURDATE() AND (tanggal_selesai IS NULL OR tanggal_selesai>=CURDATE()) LIMIT 1');
$v=$p->create($y,['santri_id'=>1714,'tahun_ajaran_id'=>$tahun,'katalog_id'=>$catalog,'waktu_kejadian'=>date('Y-m-d H:i',time()-3600),'uraian'=>'Probe rantai revisi '.$key(),'idempotency_key'=>$key()])['data']['pelanggaran'];
$v=$p->correct($x,(int)$v['id'],['version'=>$v['version'],'alasan'=>'Koreksi X','uraian'=>'Pernah diisi X','idempotency_key'=>$key()])['data']['pelanggaran'];
for($i=0;$i<101;$i++)$v=$p->correct($y,(int)$v['id'],['version'=>$v['version'],'alasan'=>'Koreksi lanjutan','uraian'=>'Revisi Y '.$i,'idempotency_key'=>$key()])['data']['pelanggaran'];
$assert($p->milikSendiri($x,(int)$v['id']),'F-1 pengisi tetap dikenali setelah lebih dari 100 revisi pelanggaran');
$n=(int)$one('SELECT COUNT(*) FROM v3_murobi_catatan');
$assert($code(fn()=>$p->acknowledge($x,(int)$v['id'],['idempotency_key'=>$key()]))===403&&(int)$one('SELECT COUNT(*) FROM v3_murobi_catatan')===$n,'F-1 rantai panjang tidak membuka tanda mengetahui sendiri');

// Respons sukses nyata dibentuk dengan layanan baseline, bukan cache buatan.
if(!is_file((string)getenv('PKF2_BASELINE').'/app/bootstrap.php'))throw new RuntimeException('PKF2_BASELINE wajib arsip 464d9a1');
$old=static function(array $in):array{
    $proc=proc_open([PHP_BINARY,__FILE__,'--legacy',json_encode($in,JSON_THROW_ON_ERROR)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);
    if($exit!==0)throw new RuntimeException('Kontrol baseline gagal: '.$err);
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
};
$offset=random_int(1000,20000);
$make=static function(array $u,string $mode,int $day)use($key,$offset):int{$day+=$offset;return (int)izin_workflow_service()->create($u,['santri_id'=>1721,'tgl_izin'=>date('Y-m-d',strtotime('+'.$day.' days')),'tgl_kembali'=>date('Y-m-d',strtotime('+'.$day.' days')),'alasan'=>'Fixture keputusan lama'],$key(),[],$mode)['id'];};
foreach(['murobi','admin']as$i=>$mode){
    $actor=$mode==='murobi'?$x:$admin;$id=$make($actor,$mode==='murobi'?'pengurus':'admin',40+$i);
    if($mode==='murobi')$db->query("UPDATE izin_pengajuan SET status='Diajukan',murobi_guru_id={$x['guru_id']} WHERE id=$id");
    $in=['op'=>'decide','user'=>$mode==='murobi'?'sbx_guru_biasa':'sbx_admin','id'=>$id,'mode'=>$mode,'version'=>null,'key'=>$key()];
    $r=$old($in);$assert($r['status']==='Disetujui','Kontrol baseline: keputusan sendiri '.$mode.' berhasil sebelum Fase 2');
    $before=(int)$one("SELECT version FROM izin_pengajuan WHERE id=$id");
    $assert($code(fn()=>$invoke($in))===403&&(int)$one("SELECT version FROM izin_pengajuan WHERE id=$id")===$before,'F-2 replay keputusan sendiri '.$mode.' ditolak tanpa mengubah sejarah');
    if($mode==='admin'){
        $in['op']='correct';$in['key']=$key();$r=$old($in);
        $assert($r['status']==='Ditolak','Kontrol baseline: koreksi admin pengaju berhasil sebelum Fase 2');
        $assert($code(fn()=>$invoke($in))===403,'F-2 replay koreksi admin pengaju ditolak');
    }
}
// Kontrol replay sah tetap idempoten.
$id=$make($admin,'admin',43);$in=['op'=>'decide','user'=>'sbx_v6_admin_bsa','id'=>$id,'mode'=>'admin','version'=>null,'key'=>$key()];
$invoke($in);$r=$invoke($in);$assert($r['idempotent_replay']===true&&(int)$one("SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id=$id")===1,'Kontrol replay admin lain tetap satu keputusan');
$id=$make($x,'pengurus',44);$in=['op'=>'assign','user'=>'sbx_admin','id'=>$id,'guru'=>$x['guru_id'],'version'=>null,'key'=>$key()];
$assert((int)$old($in)['murobi_guru_id']===$x['guru_id'],'Kontrol baseline: penetapan kepada pengaju sendiri berhasil sebelum Fase 2');
$assert($code(fn()=>$invoke($in))===422,'F-2 replay penetapan kepada guru pengaju sendiri ditolak');
// Jalur V7 memakai versi eksplisit dan pemeriksaan cakupan sendiri.
$w=izin_wali_service();$wali=$user('sbx_v7_wali_a');$day=date('Y-m-d',strtotime('+'.($offset+45).' days'));
$r=$w->buat($wali,['santri_id'=>1721,'tgl_izin'=>$day,'tgl_kembali'=>$day,'alasan'=>'Probe replay V7'],$key(),[]);$id=(int)$r['id'];
if($r['status']!=='Menunggu Pembimbing')$w->tetapkanPembimbing($admin,$id,(int)$one("SELECT pengurus_id FROM users WHERE id={$x['id']}"),'Tetapkan X',1,$key(),[]);
$version=(int)$one("SELECT version FROM izin_pengajuan WHERE id=$id");$w->tinjau($x,$id,'teruskan',null,$version,$key(),[]);
$version=(int)$one("SELECT version FROM izin_pengajuan WHERE id=$id");
$db->query("UPDATE izin_pengajuan SET status='Diajukan',murobi_guru_id={$x['guru_id']} WHERE id=$id");
$in=['op'=>'decide','user'=>'sbx_guru_biasa','id'=>$id,'mode'=>'murobi','version'=>$version,'key'=>$key()];
$assert($old($in)['status']==='Disetujui','Kontrol baseline: peninjau V7 dapat memutus sendiri sebelum Fase 2');
$assert($code(fn()=>$invoke($in))===403,'F-2 replay keputusan peninjau V7 ditolak');
foreach(['v3p','v3k','v3s']as$op){
    if($op==='v3p')$source=(int)$p->create($x,['santri_id'=>1714,'tahun_ajaran_id'=>$tahun,'katalog_id'=>$catalog,'waktu_kejadian'=>date('Y-m-d H:i',time()-3600),'uraian'=>'Probe cache V3 '.$key(),'idempotency_key'=>$key()])['data']['pelanggaran']['id'];
    elseif($op==='v3k'){$ownCase=(int)$k->createCase($x,['santri_id'=>1714,'tahun_ajaran_id'=>$tahun,'tujuan'=>'Probe cache V3','kerahasiaan'=>'Internal','idempotency_key'=>$key()])['data']['kasus']['id'];$source=$ownCase;}
    else $source=(int)$k->createSession($x,$ownCase,['jadwal'=>date('Y-m-d\TH:i',time()+7200),'idempotency_key'=>$key()])['data']['sesi']['id'];
    $in=['op'=>$op,'user'=>'sbx_guru_biasa','id'=>$source,'key'=>$key()];
    $assert($old($in)['status']===201,'Kontrol baseline: mengetahui sendiri '.$op.' berhasil sebelum Fase 2');
    $n=(int)$one('SELECT COUNT(*) FROM v3_murobi_catatan');
    $assert($code(fn()=>$invoke($in))===403&&(int)$one('SELECT COUNT(*) FROM v3_murobi_catatan')===$n,'F-2 replay mengetahui sendiri '.$op.' ditolak tanpa mutasi');
}
// Replay yang tidak melibatkan pengisi tetap berhasil pada V3.
$c=$user('sbx_murobi_c');
if(!(int)$one("SELECT COUNT(*) FROM murobi_assignments WHERE guru_id={$c['guru_id']} AND kamar_id=381 AND is_active=1"))
    penugasan_service()->buat(J::MUROBI,['guru_id'=>$c['guru_id'],'tahun_ajaran_id'=>$tahun,'target_type'=>'Kamar','kamar_id'=>381,'tanggal_mulai'=>date('Y-m-d')],$admin['id']);
$in=['op'=>'v3s','user'=>'sbx_murobi_c','id'=>$source,'key'=>$key()];$r=$invoke($in);$again=$invoke($in);
$assert($r['status']===201&&$again['replayed']===true,'Kontrol mengetahui dan replay oleh murobi lain tetap sah');
$assert((int)$one("SELECT COUNT(*) FROM v3_murobi_catatan WHERE sesi_id=$source AND created_by={$c['id']}")===1,'Kontrol replay V3 tidak menggandakan tanda mengetahui');
echo "Hasil: $pass lulus, $fail gagal\n";exit($fail?1:0);
