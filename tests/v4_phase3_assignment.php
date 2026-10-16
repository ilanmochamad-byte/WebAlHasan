<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'||app_config('env')!=='testing'||app_config('database.database')!=='webalhasan_v4_fase2_test')exit(77);
$fixture=['v4_pembimbing'=>['pembimbing_assignments','pengurus_id'],'v4_murobi'=>['murobi_assignments','guru_id'],'v4_pendidikan'=>['pendidikan_assignments','pengurus_id']];
$name=$argv[1]??'';$active=$argv[2]??'';if(!isset($fixture[$name])||!in_array($active,['0','1'],true))exit(2);
[$table,$column]=$fixture[$name];$s=app_db()->prepare("UPDATE $table a JOIN users u ON u.$column=a.$column SET a.is_active=? WHERE u.username=?");$s->bind_param('is',$active,$name);if(!$s->execute())exit(1);
