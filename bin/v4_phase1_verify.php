<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/bootstrap.php';
$db=app_db();$fail=0;$check=static function(bool $ok,string $label)use(&$fail):void{echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
$tables=['v4_penanda_tangan','v4_tanda_tangan_aset','v4_rencana','v4_rencana_mapel','v4_nilai','v4_nilai_riwayat','v4_sikap','v4_sikap_riwayat','v4_rapor','v4_rapor_versi','v4_verifikasi'];
foreach($tables as $t){$s=$db->prepare('SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->bind_param('s',$t);$s->execute();$check((int)$s->get_result()->fetch_assoc()['n']===1,'Tabel '.$t);$s->close();}
$check((int)$db->query("SELECT COUNT(*) n FROM schema_migrations WHERE migration='020_v4_fase1_fondasi.sql'")->fetch_assoc()['n']===1,'Migrasi 020 tercatat sekali');
$orphans=0;
$fks=$db->query("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'v4\\_%' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
foreach($fks as $fk){foreach($fk as $id)if(!preg_match('/^[a-zA-Z0-9_]+$/D',$id))exit(2);$c=$fk['TABLE_NAME'];$p=$fk['REFERENCED_TABLE_NAME'];$col=$fk['COLUMN_NAME'];$key=$fk['REFERENCED_COLUMN_NAME'];$n=(int)$db->query("SELECT COUNT(*) n FROM `$c` c LEFT JOIN `$p` p ON p.`$key`=c.`$col` WHERE c.`$col` IS NOT NULL AND p.`$key` IS NULL")->fetch_assoc()['n'];$orphans+=$n;$check($n===0,"Referensi baru $c.$col tidak yatim");}
echo "Jumlah referensi yatim V4: $orphans\n";
if(isset($argv[1])){$manifest=json_decode((string)file_get_contents($argv[1]),true);if(!is_array($manifest)||!isset($manifest['legacy_counts'])){fwrite(STDERR,"Manifest tidak valid.\n");exit(2);}foreach($manifest['legacy_counts'] as $t=>$before){if(in_array($t,['schema_migrations','audit_logs'],true))continue;if(!preg_match('/^[a-zA-Z0-9_]+$/D',$t))exit(2);$now=(int)$db->query('SELECT COUNT(*) n FROM `'.$t.'`')->fetch_assoc()['n'];$check($now===(int)$before,"Jumlah baris lama $t tetap $before");}}
else echo "[belum diperiksa] Perbandingan baris V1–V3: berikan path manifest preflight.\n";
exit($fail?1:0);
