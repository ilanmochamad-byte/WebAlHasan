<?php
declare(strict_types=1);
// Run identical executable on current and baseline trees, on identical DB copies without 036.
$dbName=getenv('IOP_DB')?:'';if(!preg_match('/^test_.*_test$/',$dbName))exit(2);
putenv('DB_NAME='.$dbName);require dirname(__DIR__).'/app/bootstrap.php';
$db=app_db();mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db->query('SET timestamp=1795435200');
$one=static fn(string $q):mixed=>$db->query($q)->fetch_row()[0]??null;
$sc=$db->query("SELECT * FROM jadwal_ngaji WHERE fan_ilmu LIKE 'Migrasi %' ORDER BY id DESC LIMIT 1")->fetch_assoc();
if(!$sc)throw new RuntimeException('Expected synthetic migration fixture');
$aid=(int)$one("SELECT id FROM users WHERE username='sbx_admin'");
$uid=(int)$one("SELECT id FROM users WHERE guru_id={$sc['id_guru']} LIMIT 1");
$u=['id'=>$uid,'roles'=>['guru'],'guru_id'=>(int)$sc['id_guru']];
$date=(new DateTimeImmutable('next thursday'))->modify('+140 days')->format('Y-m-d');
$open=teacher_api_service()->openMeeting((int)$sc['id'],['date'=>$date,'idempotency_key'=>'rollback-audit-open'],$u)['data'];
$m=$open['id'];$r=teacher_api_service()->refreshParticipants($m,$u);
$p=['idempotency_key'=>'rollback-audit-save','teacher'=>['status'=>'Hadir'],'students'=>array_map(static fn(array $s):array=>['student_id'=>$s['student_id'],'status'=>'Hadir','notes'=>'Kontrol baseline'],$r['students'])];
$saved=teacher_api_service()->saveAttendance($m,$p,$u)['data'];
$report=report_service()->meeting($m,['id'=>$aid,'roles'=>['admin'],'guru_id'=>null]);
$normalize=static function(mixed $v)use(&$normalize):mixed{
 if(!is_array($v))return $v;
 foreach($v as $k=>$x){
  if($k==='izin'){if($x!==null)throw new RuntimeException('Suggestion without 036');unset($v[$k]);continue;}
  if(in_array($k,['izin_pengajuan_id','izin_keterangan','izin_sampai'],true)){if($x!==null)throw new RuntimeException('Snapshot without 036');unset($v[$k]);continue;}
  $v[$k]=$normalize($x);
 }
 return $v;
};
$before=$db->query("SELECT action,before_json,after_json FROM audit_logs WHERE entity_type='pertemuan_pengajian' AND entity_id=$m ORDER BY id")->fetch_all(MYSQLI_ASSOC);
foreach($before as &$row){foreach(['before_json','after_json']as $k)$row[$k]=$normalize(json_decode($row[$k]??'null',true));}unset($row);
echo json_encode($normalize(['open'=>$open,'refresh'=>$r,'save'=>$saved,'report'=>$report,'audit'=>$before]),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
