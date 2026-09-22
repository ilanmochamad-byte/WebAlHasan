<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$r=new App\V3\KonselingRepository(app_db());$fail=0;
$check=static function($ok,$label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
try{
foreach(['v3_publikasi_pratinjau','v3_publikasi_riwayat','v3_publikasi_outbox'] as $table)$check($r->one('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])!==null,'Tabel '.$table);
$queries=[
 'Aktif tidak menunjuk Rahasia'=>"SELECT p.id FROM v3_publikasi p LEFT JOIN v3_konseling_sesi s ON s.id=p.sesi_id JOIN v3_konseling_kasus k ON k.id=COALESCE(p.kasus_id,s.kasus_id) WHERE p.ditarik_pada IS NULL AND k.kerahasiaan='Rahasia'",
 'Sumber tepat satu'=>'SELECT id FROM v3_publikasi WHERE (kasus_id IS NOT NULL)+(sesi_id IS NOT NULL)+(pelanggaran_id IS NOT NULL)<>1',
 'Riwayat versi terkini ada'=>'SELECT p.id FROM v3_publikasi p LEFT JOIN v3_publikasi_riwayat h ON h.publikasi_id=p.id AND h.version=p.version WHERE h.id IS NULL',
 'Riwayat tidak yatim'=>'SELECT h.id FROM v3_publikasi_riwayat h LEFT JOIN v3_publikasi p ON p.id=h.publikasi_id WHERE p.id IS NULL',
 'Penarikan beralasan'=>"SELECT id FROM v3_publikasi WHERE ditarik_pada IS NOT NULL AND (alasan_penarikan IS NULL OR CHAR_LENGTH(TRIM(alasan_penarikan))<5)",
 'Koreksi beralasan'=>"SELECT id FROM v3_publikasi_riwayat WHERE tindakan IN ('Koreksi','Tarik') AND (alasan IS NULL OR CHAR_LENGTH(TRIM(alasan))<5)",
 'Relasi outbox benar'=>'SELECT x.outbox_id FROM v3_publikasi_outbox x LEFT JOIN notifikasi_outbox n ON n.id=x.outbox_id LEFT JOIN v3_publikasi p ON p.id=x.publikasi_id WHERE n.id IS NULL OR p.id IS NULL OR n.pengajuan_id IS NOT NULL',
 'Setiap outbox V3 memiliki relasi'=>"SELECT n.id FROM notifikasi_outbox n LEFT JOIN v3_publikasi_outbox x ON x.outbox_id=n.id WHERE n.event_type LIKE 'v3_publikasi_%' AND x.outbox_id IS NULL",
 'Payload generik'=>"SELECT n.id FROM notifikasi_outbox n JOIN v3_publikasi_outbox x ON x.outbox_id=n.id WHERE n.judul<>'Pembaruan pembinaan' OR n.isi<>'Ada pembaruan pembinaan. Masuk untuk melihat informasi.' OR n.data_json<>CONCAT('{\"tipe\":\"v3_publikasi\",\"publikasi_id\":',x.publikasi_id,'}')",
 'WhatsApp V3 nol'=>"SELECT n.id FROM notifikasi_outbox n JOIN v3_publikasi_outbox x ON x.outbox_id=n.id WHERE n.kanal='WhatsApp'",
];foreach($queries as $label=>$sql)$check($r->one($sql.' LIMIT 1')===null,$label);
$linkedSecret=false;
foreach($r->all('SELECT pelanggaran_id,santri_id FROM v3_publikasi WHERE pelanggaran_id IS NOT NULL AND ditarik_pada IS NULL AND archived_at IS NULL') as $publication){
    if($r->violationHasSecretCase((int)$publication['pelanggaran_id'],(int)$publication['santri_id'])){$linkedSecret=true;break;}
}
$check(!$linkedSecret,'Pelanggaran terbit tidak terkait kasus Rahasia melalui revisi');
}catch(Throwable $e){$check(false,'Verifier tidak dapat diselesaikan; periksa skema.');}
exit($fail?1:0);
