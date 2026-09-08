<?php

declare(strict_types=1);
$root=dirname(__DIR__);$fail=0;
$check=static function(bool $ok,string $label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
foreach(['admin/admin_v3_katalog.php','admin/admin_pelanggaran.php','app/V3/KatalogService.php','app/V3/KatalogRepository.php','app/V3/V3Exception.php','app/Auth/Capabilities.php','api/v1/index.php','bin/v3_verify.php'] as $f){exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($root.'/'.$f).' 2>&1',$out,$code);$check($code===0,'PHP lint '.$f);}
$sql=file_get_contents($root.'/database/migrations/013_v3_fase1.sql');
$check(!preg_match('/\b(DROP|DELETE|TRUNCATE|ALTER|INSERT)\b/i',$sql),'Migrasi hanya menambah struktur tanpa mengganti/backfill lama');
foreach(glob($root.'/app/V3/*.php') as $file){$check(!preg_match('/\bDELETE\s+FROM\b/i',file_get_contents($file)),'Tidak ada hard delete '.basename($file));}
$web=file_get_contents($root.'/admin/admin_v3_katalog.php');$check(str_contains($web,'master_csrf()')&&str_contains($web,"'/_guard.php'"),'Guard dan CSRF web');
$legacy=file_get_contents($root.'/admin/admin_pelanggaran.php');$check(!preg_match('/\b(INSERT INTO|DELETE FROM|UPDATE pelanggaran)\b/i',$legacy),'Warisan hanya baca');
$api=file_get_contents($root.'/api/v1/index.php');$check(!preg_match('~\$method\s*===\s*\'POST\'.*/v3/~',$api),'Tidak ada mutasi V3 API');
exit($fail?1:0);
