<?php
declare(strict_types=1);
// DB sintetis sesudah f2_integration. Probe tambahan: riwayat pengisi,
// penantian kunci rencana, dan PK-T5 sampai finalisasi/penerbitan nyata lokal.
$name=getenv('PKF2_DB')?:'test_pk_f2_audit_test';
if(!preg_match('/^test_[A-Za-z0-9_]+_test$/D',$name))exit(2);
putenv('DB_NAME='.$name);require dirname(__DIR__).'/app/bootstrap.php';
use App\V4\{NilaiService,SikapService,VerifikasiService,V4Exception,BahanRepository,RaporService,RaporStorage};
use App\Penugasan\PenugasanJenis as J;
$db=app_db();
$one=static fn(string $q):mixed=>$db->query($q)->fetch_row()[0]??null;
$user=static function(string $name)use($db):array{
    $s=$db->prepare('SELECT id,guru_id FROM users WHERE username=?');$s->bind_param('s',$name);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();capabilities()->forget((int)$r['id']);
    return ['id'=>(int)$r['id'],'guru_id'=>$r['guru_id']===null?null:(int)$r['guru_id'],'roles'=>array_column($db->query('SELECT r.slug FROM roles r JOIN user_roles ur ON ur.role_id=r.id WHERE ur.user_id='.(int)$r['id'])->fetch_all(MYSQLI_ASSOC),'slug')];
};
$code=static function(callable $f):int{try{$f();return 200;}catch(V4Exception $e){return $e->status;}};
$v=new VerifikasiService($db,audit_logger());$s=new SikapService($db,audit_logger());$n=new NilaiService($db,capabilities(),audit_logger());
$x=$user('sbx_guru_biasa');$edu=$user('sbx_pendidikan');$admin=$user('sbx_admin');$y=$user('sbx_murobi_a');$c=$user('sbx_murobi_c');
$year=(int)$one("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1");$plan=(int)$one("SELECT id FROM v4_rencana WHERE kelas_id=333 AND tahun_ajaran_id=$year");$sid=1694;
$material=static function(array $u,int $student,string $kind,int $key=0)use($v,$plan):array{foreach($v->read($u,$plan,$student)['matriks'][0]['bahan']as$m)if($m['jenis']===$kind&&(int)$m['mapel_key']===$key)return $m;throw new RuntimeException('Bahan tidak ditemukan');};
$act=static function(array $u,int $student,string $kind,int $key,string $action)use($v,$plan,$material):array{$m=$material($u,$student,$kind,$key);return $v->act($u,['rencana_id'=>$plan,'santri_id'=>$student,'jenis'=>$kind,'mapel_key'=>$key,'action'=>$action,'alasan'=>'Probe independen auditor']+$m['pemeriksaan']);};
if(($argv[1]??'')==='--worker'){
    $in=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
    // Payload dibaca sebelum menunggu kunci, seperti formulir yang sudah terbuka.
    $m=$material($x,$sid,'nilai',$in['rm']);$in=['rencana_id'=>$plan,'santri_id'=>$sid,'jenis'=>'nilai','mapel_key'=>$in['rm'],'action'=>'verifikasi']+$m['pemeriksaan'];
    echo $db->thread_id.PHP_EOL;flush();
    echo json_encode(['status'=>$code(fn()=>$v->act($x,$in))]).PHP_EOL;exit;
}
$pass=$fail=0;$assert=static function(bool $ok,string $label)use(&$pass,&$fail):void{echo($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;$ok?$pass++:$fail++;};
$key=static fn():string=>'pkf2_v4_'.bin2hex(random_bytes(10));
$rm=(int)$one("SELECT MAX(id) FROM v4_rencana_mapel WHERE rencana_id=$plan");
$components=static function(array $rows):array{return array_map(static fn($r)=>array_intersect_key($r,array_flip(['komponen','nomor_harian','nilai','version','catatan'])),$rows);};
// Pengisi terkini diganti Pendidikan lain; pengisi terdahulu tetap dilarang.
$r=$n->read($edu,$rm,$sid,true);$items=$components($r['nilai']);foreach($items as&$i)$i['nilai']=(float)$i['nilai']+1;unset($i);
$n->save($edu,['rencana_mapel_id'=>$rm,'santri_id'=>$sid,'komponen'=>$items,'action'=>'koreksi','alasan'=>'Koreksi pihak lain'],true);
$r=$n->read($edu,$rm,$sid,true);$n->save($edu,['rencana_mapel_id'=>$rm,'santri_id'=>$sid,'komponen'=>$components($r['nilai']),'action'=>'serahkan','alasan'=>'Serahkan pihak lain'],true);
$assert((int)$one("SELECT COUNT(*) FROM v4_nilai WHERE rencana_mapel_id=$rm AND santri_id=$sid AND pelaku_user_id={$x['id']}")===0,'Kontrol: seluruh komponen kini diisi pihak lain');
$assert($code(fn()=>$act($x,$sid,'nilai',$rm,'verifikasi'))===403&&$code(fn()=>$act($x,$sid,'nilai',$rm,'kembalikan'))===403,'Riwayat nilai X tetap menutup verifikasi dan pengembalian setelah pengisi terakhir berubah');
$act($admin,$sid,'nilai',$rm,'verifikasi');

// Sikap Y -> koreksi X -> koreksi/serahkan Y: pembimbing pertama bukan X.
penugasan_service()->buat(J::PEMBIMBING,['pengurus_id'=>'guru:'.$y['guru_id'],'tahun_ajaran_id'=>$year,'target_type'=>'Kelas','kelas_id'=>333,'tanggal_mulai'=>date('Y-m-d')],$admin['id']);$y=$user('sbx_murobi_a');
$input=['rencana_id'=>$plan,'santri_id'=>1695,'adab'=>'Baik','kedisiplinan'=>'Baik','tanggung_jawab'=>'Baik','catatan'=>'Awal'];
$r=$s->save($y,$input+['version'=>0,'action'=>'draf'],'pembimbing');
$r=$s->save($x,array_replace($input,['version'=>$r['sikap']['version'],'action'=>'koreksi','alasan'=>'Koreksi X','catatan'=>'Pernah diisi X']),'pembimbing');
$r=$s->save($y,array_replace($input,['version'=>$r['sikap']['version'],'action'=>'koreksi','alasan'=>'Koreksi Y']),'pembimbing');
$r=$s->save($y,$input+['version'=>$r['sikap']['version'],'action'=>'serahkan'],'pembimbing');
$assert((int)$r['sikap']['pembimbing_user_id']===$y['id']&&$s->read($x,$plan,1695,'murobi')['diisi_sendiri'],'Riwayat pembimbing X tetap dikenali meski pembimbing pertama dan terakhir adalah Y');
$assert($code(fn()=>$s->save($x,['rencana_id'=>$plan,'santri_id'=>1695,'version'=>$r['sikap']['version'],'action'=>'diketahui'],'murobi'))===403,'Murobi X tidak mengetahui sikap yang pernah dikoreksi sebagai pembimbing');

// Isi mapel lain oleh Pendidikan; satu commit fixture menjadi isian X ketika
// verifikasi X sedang menunggu kunci yang sama dengan NilaiService::save().
$other=(int)$one("SELECT MIN(id) FROM v4_rencana_mapel WHERE rencana_id=$plan");
$items=[['komponen'=>'Harian','nomor_harian'=>1,'nilai'=>81,'version'=>0],['komponen'=>'Tengah','nomor_harian'=>0,'nilai'=>82,'version'=>0],['komponen'=>'Akhir','nomor_harian'=>0,'nilai'=>83,'version'=>0]];
$n->save($edu,['rencana_mapel_id'=>$other,'santri_id'=>$sid,'komponen'=>$items,'action'=>'serahkan','alasan'=>'Guru berhalangan'],true);
$db->begin_transaction();$db->query("SELECT id FROM v4_rencana WHERE id=$plan FOR UPDATE");
try{
    $proc=proc_open([PHP_BINARY,__FILE__,'--worker',json_encode(['rm'=>$other])],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$thread=(int)fgets($pipes[1]);$waited=false;$until=microtime(true)+8;
    do{$info=(string)$one('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID='.$thread);if(str_contains($info,'SELECT id FROM v4_rencana WHERE id=')){$waited=true;break;}usleep(20000);}while(microtime(true)<$until);
    $assert($waited,'Kontrol konkurensi: verifikasi benar-benar menunggu kunci rencana');
    $db->query("UPDATE v4_nilai SET pelaku_user_id={$x['id']},version=version+1 WHERE rencana_mapel_id=$other AND santri_id=$sid");
    $db->commit();$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);$r=json_decode(trim($out),true);
    $assert($exit===0&&($r['status']??0)===403&&$material($edu,$sid,'nilai',$other)['pemeriksaan']['status']!=='Terverifikasi','Sesudah menunggu, isian X yang baru ter-commit ditolak 403 tanpa verifikasi');
}finally{$db->rollback();}
// Sinkronkan sumber fixture sebagai Dikirim melalui penyimpanan resmi pihak lain.
$r=$n->read($edu,$other,$sid,true);$n->save($edu,['rencana_mapel_id'=>$other,'santri_id'=>$sid,'komponen'=>$components($r['nilai']),'action'=>'koreksi','alasan'=>'Pulihkan bahan fixture'],true);
$r=$n->read($edu,$other,$sid,true);$n->save($edu,['rencana_mapel_id'=>$other,'santri_id'=>$sid,'komponen'=>$components($r['nilai']),'action'=>'serahkan','alasan'=>'Serahkan kembali'],true);$act($admin,$sid,'nilai',$other,'verifikasi');
// Sikap yang diketahui murobi lain dan kehadiran tetap boleh diperiksa X.
$r=$s->read($c,$plan,$sid,'murobi');$r=$s->save($c,['rencana_id'=>$plan,'santri_id'=>$sid,'version'=>$r['sikap']['version'],'action'=>'koreksi','alasan'=>'Selaraskan bahan','adab'=>'Sangat Baik','kedisiplinan'=>'Baik','tanggung_jawab'=>'Baik','catatan'=>'Diketahui murobi lain'],'murobi');
$act($x,$sid,'sikap',0,'verifikasi');$act($x,$sid,'kehadiran',0,'serahkan');$act($x,$sid,'kehadiran',0,'verifikasi');
foreach($db->query("SELECT id FROM v4_rencana_mapel WHERE rencana_id=$plan AND wajib=1")->fetch_all(MYSQLI_ASSOC)as$mp){
    $id=(int)$mp['id'];$m=$material($admin,$sid,'nilai',$id);
    if(!$m['sumber']){$n->save($edu,['rencana_mapel_id'=>$id,'santri_id'=>$sid,'komponen'=>$items,'action'=>'serahkan','alasan'=>'Lengkapi fixture'],true);$act($admin,$sid,'nilai',$id,'verifikasi');}
}
$matrix=$v->read($x,$plan,$sid);$assert($matrix['matriks'][0]['siap_finalisasi'],'PK-T5: nilai sendiri sudah diperiksa pihak lain; sikap dan kehadiran tetap dapat diverifikasi X');
$r=$v->act($x,['rencana_id'=>$plan,'santri_id'=>$sid,'action'=>'periksa_finalisasi','matriks_hash'=>$matrix['matriks_hash']]);$assert($r['siap_finalisasi']===true,'PK-T5: finalisasi oleh X tetap boleh sesudah seluruh bahan sah');
// PDF nyata, disimpan hanya dalam direktori sementara milik uji ini.
$tmp=sys_get_temp_dir().'/'.$key();mkdir($tmp,0700);$p=$matrix['rencana'];
foreach(['pendidikan_aset_id','pimpinan_aset_id']as$a){$file=(string)$one('SELECT private_path FROM v4_tanda_tangan_aset WHERE id='.(int)$p[$a]);copy(APP_ROOT.'/storage/private/v4/'.$file,$tmp.'/'.$file);}
try{$rapor=new RaporService($db,audit_logger(),new RaporStorage($tmp));$preview=$rapor->preview($x,$plan,$sid);$r=$rapor->act($x,['rencana_id'=>$plan,'santri_id'=>$sid,'action'=>'Terbit','current_version'=>$preview['current_version'],'revision'=>$preview['revision'],'pratinjau_hash'=>$preview['pratinjau_hash'],'idempotency_key'=>$key()]);
    $assert($preview['siap_terbit']&&$r['status']==='Terbit'&&count(glob($tmp.'/*.pdf'))===1,'PK-T5: X dapat menerbitkan satu PDF nyata sesudah verifikasi pihak lain');
}finally{foreach(glob($tmp.'/*')as$f)unlink($f);rmdir($tmp);}
echo "Hasil: $pass lulus, $fail gagal\n";exit($fail?1:0);
