<?php
require dirname(__DIR__).'/app/bootstrap.php';
$target=$argv[1]??'';if(!str_starts_with($target,'/private/tmp/'))exit(2);
if(app_config('env')!=='testing'||!str_ends_with((string)app_config('database.database'),'_test'))exit(77);
$db=app_db();$out=[];
$admin=$db->query("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.slug='admin' AND u.is_active=1 ORDER BY u.id LIMIT 1")->fetch_assoc();
foreach(['admin'=>(int)$admin['id']] as $k=>$uid){$raw=bin2hex(random_bytes(32));$h=(new App\Auth\TokenHasher((string)app_config('api.token_hash_secret'),'testing'))->hash($raw);$s=$db->prepare("INSERT INTO api_tokens (user_id,token_hash,name,expires_at) VALUES (?,?,'audit-v4-fase5',DATE_ADD(NOW(),INTERVAL 1 DAY))");$s->bind_param('is',$uid,$h);$s->execute();$out[$k]=$raw;}
file_put_contents($target,json_encode($out));chmod($target,0600);
echo "admin uid ".$admin['id']."\n";
