<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$r=new App\V3\KonselingRepository(app_db());$fail=0;
$check=static function($ok,$label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
foreach(['013_v3_fase1.sql','017_v3_fase3_kerahasiaan_dan_revisi.sql'] as $name)$check($r->one('SELECT id FROM schema_migrations WHERE migration=?',[$name])!==null,'Prasyarat '.$name);
foreach(['v3_publikasi','santri_wali','notifikasi_outbox','v3_idempotency'] as $table)$check($r->one('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])!==null,'Tabel '.$table);
$check($r->one("SELECT p.id FROM v3_publikasi p LEFT JOIN v3_konseling_sesi s ON s.id=p.sesi_id JOIN v3_konseling_kasus k ON k.id=COALESCE(p.kasus_id,s.kasus_id) WHERE p.ditarik_pada IS NULL AND k.kerahasiaan='Rahasia' LIMIT 1")===null,'Tidak ada publikasi aktif sumber Rahasia');
$linkedSecret=false;
foreach($r->all('SELECT pelanggaran_id,santri_id FROM v3_publikasi WHERE pelanggaran_id IS NOT NULL AND ditarik_pada IS NULL AND archived_at IS NULL') as $publication){
    if($r->violationHasSecretCase((int)$publication['pelanggaran_id'],(int)$publication['santri_id'])){$linkedSecret=true;break;}
}
$check(!$linkedSecret,'Tidak ada publikasi pelanggaran aktif terkait kasus Rahasia');
echo 'Backup/restore point wajib dibuat sebelum migrasi; tidak dihasilkan oleh preflight.'.PHP_EOL;exit($fail?1:0);
