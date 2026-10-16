<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/bootstrap.php';
$db=app_db();$fails=0;$check=static function($v,$s)use(&$fails){echo($v?'[lulus] ':'[gagal] ').$s.PHP_EOL;if(!$v)$fails++;};
foreach(['v4_bahan','v4_bahan_riwayat']as $t){$s=$db->prepare('SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->bind_param('s',$t);$s->execute();$check((int)$s->get_result()->fetch_assoc()['n']===1,'Tabel '.$t);}
$check((int)$db->query("SELECT COUNT(*) n FROM schema_migrations WHERE migration='021_v4_fase3_verifikasi.sql'")->fetch_assoc()['n']===1,'Migrasi 021 tercatat tepat sekali');
$check((int)$db->query('SELECT COUNT(*) n FROM v4_bahan b LEFT JOIN v4_rencana r ON r.id=b.rencana_id LEFT JOIN santri s ON s.id=b.santri_id LEFT JOIN users u ON u.id=b.actor_user_id WHERE r.id IS NULL OR s.id IS NULL OR u.id IS NULL')->fetch_assoc()['n']===0,'Status bahan tidak yatim');
$check((int)$db->query("SELECT COUNT(*) n FROM v4_bahan b LEFT JOIN v4_rencana_mapel rm ON rm.id=b.mapel_key AND rm.rencana_id=b.rencana_id WHERE b.jenis='nilai' AND rm.id IS NULL")->fetch_assoc()['n']===0,'Mapel bahan milik rencana yang sama');
$check((int)$db->query('SELECT COUNT(*) n FROM v4_bahan_riwayat h LEFT JOIN v4_bahan b ON b.id=h.bahan_id WHERE b.id IS NULL')->fetch_assoc()['n']===0,'Riwayat bahan tidak yatim');
$check((int)$db->query('SELECT COUNT(*) n FROM v4_bahan b LEFT JOIN v4_bahan_riwayat h ON h.bahan_id=b.id AND h.version=b.version WHERE h.id IS NULL')->fetch_assoc()['n']===0,'Versi bahan mempunyai riwayat');
exit($fails?1:0);
