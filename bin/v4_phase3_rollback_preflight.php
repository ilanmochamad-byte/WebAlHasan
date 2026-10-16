<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/bootstrap.php';
$db=app_db();$total=0;
foreach(['v4_bahan_riwayat','v4_bahan'] as $table){$r=$db->query('SELECT COUNT(*) n FROM '.$table);if(!$r){fwrite(STDERR,"Tabel tidak dapat diperiksa; rollback ditolak.\n");exit(1);}$n=(int)$r->fetch_assoc()['n'];$total+=$n;echo "$table: $n baris\n";}
if($total){fwrite(STDERR,"Rollback ditolak: ada status/riwayat bisnis. Pulihkan backup terverifikasi atau siapkan forward fix; jangan hapus riwayat.\n");exit(1);}
echo "Rollback 021 diizinkan hanya selama kedua tabel tetap kosong dan penulis dihentikan.\n";
