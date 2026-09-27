<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/bootstrap.php';
$r=new App\V3\KonselingRepository(app_db());$fail=0;
foreach(['018_v3_fase4_publikasi.sql'] as $m){$ok=$r->one('SELECT id FROM schema_migrations WHERE migration=?',[$m])!==null;echo ($ok?'[lulus] ':'[gagal] ').$m.PHP_EOL;if(!$ok)$fail++;}
foreach(['v3_publikasi_pratinjau','v3_pelanggaran','v3_konseling_kasus','audit_logs'] as $t){$ok=$r->one('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$t])!==null;echo ($ok?'[lulus] ':'[gagal] ').$t.PHP_EOL;if(!$ok)$fail++;}
echo "Backup database dan berkas wajib dibuat operator; preflight tidak membuat backup.\n";exit($fail?1:0);
