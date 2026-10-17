<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'||app_config('env')!=='testing'||app_config('database.database')!=='webalhasan_v4_fase2_test')exit(77);
$password=getenv('V4_TEST_PASSWORD');
if(!is_string($password)||strlen($password)<12)exit(2);
$db=app_db();$hash=password_hash($password,PASSWORD_DEFAULT);
$s=$db->prepare("UPDATE users SET password=? WHERE username LIKE 'v4\\_%'");$s->bind_param('s',$hash);$s->execute();
echo "Akun fixture V4 pada DB uji disiapkan untuk login aplikasi.\n";
