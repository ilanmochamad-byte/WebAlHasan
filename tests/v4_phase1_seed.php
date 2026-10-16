<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require_once dirname(__DIR__).'/app/bootstrap.php';
$db=app_db();$name=(string)$db->query('SELECT DATABASE() n')->fetch_assoc()['n'];
if(!preg_match('/(?:^|_)(?:test|uji)(?:_|$)/i',$name)||app_config('env')!=='testing')exit("Hanya DB uji dan APP_ENV=testing.\n");
$password=getenv('V4_TEST_PASSWORD');$tokenFile=$argv[1]??null;
if(!is_string($password)||strlen($password)<12||!is_string($tokenFile)||!str_starts_with($tokenFile,'/private/tmp/'))exit("Set V4_TEST_PASSWORD minimal 12 karakter dan path token di /private/tmp.\n");
if((int)$db->query("SELECT COUNT(*) n FROM users WHERE username='v4_pendidikan'")->fetch_assoc()['n']>0)exit("Fixture V4 sudah ada; gunakan DB uji baru.\n");
$sql=(string)file_get_contents(__DIR__.'/fixtures/v4_phase1.sql');
if(!$db->multi_query($sql))exit("Fixture SQL gagal.\n");
do{if($r=$db->store_result())$r->free();if($db->errno)exit("Fixture SQL gagal.\n");}while($db->more_results()&&$db->next_result());
$wali=$db->query('SELECT w.id FROM wali w LEFT JOIN users u ON u.wali_id=w.id WHERE u.id IS NULL AND w.is_active=1 AND w.archived_at IS NULL LIMIT 1')->fetch_assoc();
if($wali){$waliId=(int)$wali['id'];$s=$db->prepare("UPDATE users SET wali_id=? WHERE username='v4_wali'");$s->bind_param('i',$waliId);if(!$s->execute())exit(1);$s->close();}
$hash=password_hash($password,PASSWORD_DEFAULT);$s=$db->prepare("UPDATE users SET password=? WHERE username IN ('admin','v4_pendidikan')");$s->bind_param('s',$hash);if(!$s->execute())exit(1);$s->close();
$hasher=new App\Auth\TokenHasher((string)app_config('api.token_hash_secret'),(string)app_config('env'));
$tokens=[];foreach(['v4_pendidikan','v4_pengurus','v4_guru','v4_wali'] as $username){$raw=bin2hex(random_bytes(32));$tokenHash=$hasher->hash($raw);$label='v4-http-test';$s=$db->prepare('INSERT INTO api_tokens (user_id,token_hash,name,expires_at,created_at) SELECT id,?,?,DATE_ADD(NOW(),INTERVAL 1 DAY),NOW() FROM users WHERE username=?');$s->bind_param('sss',$tokenHash,$label,$username);if(!$s->execute()||$s->affected_rows!==1)exit(1);$s->close();$tokens[$username]=$raw;}
file_put_contents($tokenFile,json_encode($tokens));chmod($tokenFile,0600);
echo "Fixture, password, dan token sementara dibuat pada DB uji; token disimpan privat.\n";
