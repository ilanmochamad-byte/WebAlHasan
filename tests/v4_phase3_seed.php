<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
$db=app_db();if(PHP_SAPI!=='cli'||app_config('env')!=='testing'||app_config('database.database')!=='webalhasan_v4_fase2_test')exit(77);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$hash=password_hash((string)getenv('V4_TEST_PASSWORD'),PASSWORD_DEFAULT);
$tokens=json_decode(file_get_contents((string)getenv('V4_HTTP_TOKENS')),true);
foreach(['pembimbing'=>['pengurus',2],'pembimbing_lain'=>['pengurus',3],'murobi'=>['guru',2],'murobi_lain'=>['guru',3]] as $name=>[$role,$class]){
    $username='v4_'.$name;
    if($role==='guru'){$db->query("INSERT INTO guru (nama_guru,status) VALUES ('Petugas Sintetis Fase 3','Guru')");$master=$db->insert_id;}
    else{$db->query("INSERT INTO pengurus (nama,jabatan) VALUES ('Petugas Sintetis Fase 3','Pembimbing')");$master=$db->insert_id;}
    $column=$role==='guru'?'guru_id':'pengurus_id';
    $s=$db->prepare("INSERT INTO users (name,username,password,$column) VALUES ('Petugas Sintetis Fase 3',?,?,?)");$s->bind_param('ssi',$username,$hash,$master);$s->execute();$uid=$db->insert_id;
    $db->query("INSERT INTO user_roles (user_id,role_id) SELECT $uid,id FROM roles WHERE slug='$role'");
    $table=$role==='guru'?'murobi_assignments':'pembimbing_assignments';
    $db->query("INSERT INTO $table ($column,tahun_ajaran_id,target_type,kelas_id,tanggal_mulai,is_active,created_by) VALUES ($master,4,'Kelas',$class,'2026-01-01',1,1)");
    $token=bin2hex(random_bytes(32));$h=(new App\Auth\TokenHasher((string)app_config('api.token_hash_secret'),'testing'))->hash($token);
    $s=$db->prepare("INSERT INTO api_tokens (user_id,token_hash,name,expires_at) VALUES (?,?,'v4-fase3-test',DATE_ADD(NOW(),INTERVAL 1 DAY))");$s->bind_param('is',$uid,$h);$s->execute();$tokens[$username]=$token;
}
file_put_contents((string)getenv('V4_HTTP_TOKENS'),json_encode($tokens));
$ids=json_decode(file_get_contents('/private/tmp/v4-fase2-ids.json'),true);
$plan=$db->query('SELECT rencana_id FROM v4_rencana_mapel WHERE id='.(int)$ids['mapel'])->fetch_assoc();$ids['rencana']=(int)$plan['rencana_id'];
$students=$db->query('SELECT DISTINCT id_santri FROM plotting_kelas WHERE id_kelas=2 AND id_tahun=4 ORDER BY id_santri')->fetch_all(MYSQLI_ASSOC);
$ids['sikap_concurrent']=(int)$students[8]['id_santri'];
$ids['rencana_luar']=array_map('intval',array_column($db->query('SELECT id FROM v4_rencana WHERE id<>'.(int)$ids['rencana'])->fetch_all(MYSQLI_ASSOC),'id'));
$db->query("UPDATE santri s SET nama_santri=CONCAT('Santri Sintetis Fase 3 #',s.id) WHERE EXISTS(SELECT 1 FROM plotting_kelas pk WHERE pk.id_santri=s.id AND pk.id_kelas=2 AND pk.id_tahun=4)");
file_put_contents('/private/tmp/v4-fase3-ids.json',json_encode($ids));
echo "Fixture petugas, penugasan, dan token privat Fase 3 dibuat di DB uji.\n";
