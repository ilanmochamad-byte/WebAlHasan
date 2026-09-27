<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/bootstrap.php';
$r=new App\V3\KonselingRepository(app_db());$fail=0;
$check=static function($ok,$label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
foreach(['v3_preview_retensi','v3_laporan_periode','v3_laporan_kasus'] as $index)$check($r->one('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME=?',[$index])!==null,'Indeks '.$index);
$checks=[
 'Selisih poin nol'=>"SELECT COUNT(*) n FROM v3_poin_agregat a WHERE a.total_poin<>(SELECT COALESCE(SUM(l.perubahan_poin),0) FROM v3_poin_ledger l WHERE l.santri_id=a.santri_id AND l.tahun_ajaran_id=a.tahun_ajaran_id AND l.archived_at IS NULL)",
 'Publikasi duplikat nol'=>'SELECT COUNT(*) n FROM (SELECT event_key,wali_id FROM v3_publikasi GROUP BY event_key,wali_id HAVING COUNT(*)>1) x',
 'Status pelanggaran sah'=>"SELECT COUNT(*) n FROM v3_pelanggaran WHERE status NOT IN ('Draf','Dicatat','Ditindaklanjuti','Selesai','Dibatalkan')",
 'Status kasus sah'=>"SELECT COUNT(*) n FROM v3_konseling_kasus WHERE status NOT IN ('Dibuka','Dalam Pendampingan','Selesai','Dibatalkan')",
 'Status sesi sah'=>"SELECT COUNT(*) n FROM v3_konseling_sesi WHERE status NOT IN ('Dijadwalkan','Selesai','Tidak Hadir','Dijadwalkan Ulang','Dibatalkan')",
];
foreach($checks as $label=>$sql)$check((int)$r->one($sql)['n']===0,$label);
foreach($r->all("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_NAME LIKE 'v3\\_%'") as $fk){
 foreach($fk as $name)if(!preg_match('/^\w+$/D',$name))exit(2);
 $sql='SELECT COUNT(*) n FROM `'.$fk['TABLE_NAME'].'` c LEFT JOIN `'.$fk['REFERENCED_TABLE_NAME'].'` p ON p.`'.$fk['REFERENCED_COLUMN_NAME'].'`=c.`'.$fk['COLUMN_NAME'].'` WHERE c.`'.$fk['COLUMN_NAME'].'` IS NOT NULL AND p.`'.$fk['REFERENCED_COLUMN_NAME'].'` IS NULL';
 $check((int)$r->one($sql)['n']===0,'Tidak yatim '.$fk['TABLE_NAME'].'.'.$fk['COLUMN_NAME']);
}
$warnings=[
 'Outbox tertunda >30 menit'=>"SELECT COUNT(*) n FROM notifikasi_outbox WHERE event_type LIKE 'v3_%' AND status IN ('Queued','Processing') AND created_at<DATE_SUB(NOW(),INTERVAL 30 MINUTE)",
 'Publikasi aktif tanpa wali aktif (akses tetap ditolak)'=>"SELECT COUNT(*) n FROM v3_publikasi p WHERE p.ditarik_pada IS NULL AND p.archived_at IS NULL AND NOT EXISTS(SELECT 1 FROM santri_wali sw JOIN wali w ON w.id=sw.wali_id AND w.is_active=1 AND w.archived_at IS NULL WHERE sw.santri_id=p.santri_id AND sw.wali_id=p.wali_id AND sw.archived_at IS NULL)",
 'Draf melewati retensi'=>"SELECT COUNT(*) n FROM v3_publikasi_pratinjau WHERE expires_at<DATE_SUB(NOW(),INTERVAL 7 DAY)",
];
foreach($warnings as $label=>$sql)echo '[operasional] '.$label.': '.(int)$r->one($sql)['n'].PHP_EOL;
exit($fail?1:0);
