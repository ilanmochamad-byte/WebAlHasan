<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$repo=new App\V3\PelanggaranRepository(app_db());
$user=static fn(string $name):array=>$repo->one('SELECT id FROM users WHERE username=?',[$name])??throw new RuntimeException('Fixture hilang');
if(($argv[1]??'')==='worker'){
    $operation=(string)$argv[2];$actor=$user((string)$argv[3]);$input=json_decode(base64_decode((string)$argv[4],true)?:'',true);
    try{
        $result=$operation==='create'?v3_pelanggaran_service()->create($actor,$input):v3_pelanggaran_service()->correct($actor,(int)$argv[5],$input);
        echo $operation==='create'?($result['replayed']?'replayed':'created'):'corrected';
    }catch(App\V3\V3Exception $exception){echo $exception->status===409?'conflict':'failed-'.$exception->status;}
    exit;
}
$fails=0;$assert=static function(bool $ok,string $label)use(&$fails){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fails++;};
$spawn=static function(string $operation,string $username,array $input,?int $id=null):array{
    $command=[PHP_BINARY,__FILE__,'worker',$operation,$username,base64_encode(json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))];if($id!==null)$command[]=(string)$id;
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Worker gagal dibuat');fclose($pipes[0]);return [$process,$pipes];
};
$finish=static function(array $worker):string{[$process,$pipes]=$worker;$output=stream_get_contents($pipes[1]);fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($process);return $code===0?$output:'process-'.$code.'-'.$error;};
$pembimbingA=$user('sbx_pengurus_a');$pembimbingB=$user('sbx_pengurus_b');$year=(int)$repo->one("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1")['id'];
$childA=(int)$repo->one("SELECT sw.santri_id FROM santri_wali sw JOIN users u ON u.wali_id=sw.wali_id WHERE u.username='sbx_ortu_a' AND sw.archived_at IS NULL LIMIT 1")['santri_id'];
$childB=(int)$repo->one("SELECT sw.santri_id FROM santri_wali sw JOIN users u ON u.wali_id=sw.wali_id WHERE u.username='sbx_ortu_b' AND sw.archived_at IS NULL LIMIT 1")['santri_id'];
$catalog=(int)$repo->one("SELECT k.id FROM v3_katalog k JOIN v3_kategori c ON c.id=k.kategori_id WHERE k.is_active=1 AND c.is_active=1 AND k.archived_at IS NULL AND c.archived_at IS NULL AND k.tanggal_mulai<=CURDATE() AND (k.tanggal_selesai IS NULL OR k.tanggal_selesai>=CURDATE()) AND c.tanggal_mulai<=CURDATE() AND (c.tanggal_selesai IS NULL OR c.tanggal_selesai>=CURDATE()) ORDER BY k.id DESC LIMIT 1")['id'];
$nonce=bin2hex(random_bytes(5));$input=['santri_id'=>$childA,'tahun_ajaran_id'=>$year,'katalog_id'=>$catalog,'waktu_kejadian'=>date('Y-m-d H:i:s',time()-random_int(1200,2400)),'tempat'=>'SBX konkurensi','uraian'=>'SBX create dua proses '.$nonce,'saksi'=>'','idempotency_key'=>'concurrent-'.$nonce];
app_db()->begin_transaction();$repo->lockSubject($childA,$year,(int)$pembimbingA['id']);
try{$workers=[$spawn('create','sbx_pengurus_a',$input),$spawn('create','sbx_pengurus_a',$input)];usleep(350000);}finally{app_db()->commit();}
$outputs=array_map($finish,$workers);sort($outputs);
$createdRow=$repo->one('SELECT id,version FROM v3_pelanggaran WHERE created_by=? AND idempotency_key=?',[(int)$pembimbingA['id'],$input['idempotency_key']]);
$assert($outputs===['created','replayed']&&$createdRow!==null,'Dua proses dengan idempotency sama: satu create dan satu replay');
$id=(int)$createdRow['id'];$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_poin_ledger WHERE pelanggaran_id=?',[$id])['n']===1,'Konkurensi create hanya memberi satu dampak ledger');

$correctA=['version'=>1,'idempotency_key'=>'corr-a-'.$nonce,'uraian'=>'SBX koreksi proses A '.$nonce,'alasan'=>'Uji koreksi bersamaan A'];
$correctB=['version'=>1,'idempotency_key'=>'corr-b-'.$nonce,'uraian'=>'SBX koreksi proses B '.$nonce,'alasan'=>'Uji koreksi bersamaan B'];
app_db()->begin_transaction();$repo->lockSubject($childA,$year,(int)$pembimbingA['id']);
try{$workers=[$spawn('correct','sbx_pengurus_a',$correctA,$id),$spawn('correct','sbx_pengurus_a',$correctB,$id)];usleep(350000);}finally{app_db()->commit();}
$outputs=array_map($finish,$workers);sort($outputs);
$assert($outputs===['conflict','corrected'],'Dua koreksi versi lama: satu berhasil dan satu konflik 409');
$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_pelanggaran WHERE revisi_dari_id=?',[$id])['n']===1,'Optimistic concurrency menghasilkan tepat satu revisi');
$ledgerTotal=(int)$repo->one('SELECT COALESCE(SUM(perubahan_poin),0) n FROM v3_poin_ledger WHERE santri_id=? AND tahun_ajaran_id=?',[$childA,$year])['n'];$aggregate=(int)$repo->aggregate($childA,$year)['total_poin'];
$assert($ledgerTotal===$aggregate,'Agregat tetap sama dengan ledger sesudah balapan koreksi');

// Membuktikan kunci subjek A tidak menahan mutasi santri B.
$inputB=['santri_id'=>$childB,'tahun_ajaran_id'=>$year,'katalog_id'=>$catalog,'waktu_kejadian'=>date('Y-m-d H:i:s',time()-random_int(2600,3600)),'tempat'=>'SBX subjek B','uraian'=>'SBX kunci berbeda '.$nonce,'saksi'=>'','idempotency_key'=>'subject-b-'.$nonce];
app_db()->begin_transaction();$repo->lockSubject($childA,$year,(int)$pembimbingA['id']);$workerB=$spawn('create','sbx_pengurus_b',$inputB);stream_set_blocking($workerB[1][1],false);$finished=false;$output='';
for($i=0;$i<80;$i++){usleep(50000);$output.=stream_get_contents($workerB[1][1]);$status=proc_get_status($workerB[0]);if(!$status['running']){$finished=true;break;}}
app_db()->commit();stream_set_blocking($workerB[1][1],true);$output.=stream_get_contents($workerB[1][1]);fclose($workerB[1][1]);$error=stream_get_contents($workerB[1][2]);fclose($workerB[1][2]);$code=proc_close($workerB[0]);
$assert($finished&&$code===0&&$output==='created','Kunci santri/tahun A tidak menjadi gerbang global bagi subjek B');
exit($fails?1:0);
