<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'||app_config('env')!=='testing'||app_config('database.database')!=='webalhasan_v4_fase2_test')exit(77);
$password=getenv('V4_TEST_PASSWORD');if(!is_string($password)||strlen($password)<12)exit(2);
$db=app_db();$hash=password_hash($password,PASSWORD_DEFAULT);$s=$db->prepare("UPDATE users SET password=? WHERE username='v4_guru'");$s->bind_param('s',$hash);$s->execute();
$ids=json_decode(file_get_contents('/private/tmp/v4-fase2-ids.json'),true);
foreach(['santri','santri2','concurrent','web'] as $key){$name='Santri Uji Fase 2 '.$key;$id=(int)$ids[$key];$s=$db->prepare('UPDATE santri SET nama_santri=? WHERE id=?');$s->bind_param('si',$name,$id);$s->execute();}
echo "Alias sintetis untuk tangkapan layar dan akun guru web disiapkan hanya pada DB uji.\n";
