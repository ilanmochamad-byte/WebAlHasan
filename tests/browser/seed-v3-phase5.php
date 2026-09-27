<?php
// Tahun sintetis khusus uji katalog browser; tidak mengubah tahun aktif.
require_once dirname(__DIR__,2).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$r=new App\V3\KonselingRepository(app_db());$n=random_int(9000,9998);$name=$n.'/'.($n+1);while($r->one('SELECT id FROM tahun_ajaran WHERE tahun=?',[$name])!==null){$n=random_int(9000,9998);$name=$n.'/'.($n+1);}
$r->execute("INSERT INTO tahun_ajaran(tahun,semester,status) VALUES(?,'Ganjil','Non-Aktif')",[$name]);
echo $name.' / Ganjil — non-aktif'.PHP_EOL;
