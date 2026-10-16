<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/bootstrap.php';
$db=app_db();$fail=0;
foreach(['v4_verifikasi','v4_rapor_versi','v4_rapor','v4_sikap_riwayat','v4_sikap','v4_nilai_riwayat','v4_nilai','v4_rencana_mapel','v4_rencana','v4_tanda_tangan_aset','v4_penanda_tangan'] as $t){$n=(int)$db->query('SELECT COUNT(*) n FROM `'.$t.'`')->fetch_assoc()['n'];echo ($n===0?'[lulus] ':'[blokir] ').$t.' = '.$n.PHP_EOL;if($n!==0)$fail++;}echo "Jangan rollback bila ada data V4; pulihkan backup terverifikasi atau buat migrasi korektif.\n";exit($fail?1:0);
