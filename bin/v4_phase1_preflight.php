<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/bootstrap.php';
$db=app_db();$fail=0;
$check=static function(bool $ok,string $label)use(&$fail):void{echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
$name=(string)$db->query('SELECT DATABASE() name')->fetch_assoc()['name'];
echo '[info] Basis data terpilih: '.$name.' (preflight hanya membaca; migrasi produksi memerlukan otorisasi operator).'.PHP_EOL;
foreach(['users','tahun_ajaran','kelas','santri','mata_pelajaran','guru_mapel_assignments','pendidikan_assignments','plotting_kelas','pertemuan_peserta','absensi_santri','audit_logs','notifikasi_outbox','schema_migrations'] as $table){$s=$db->prepare('SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->bind_param('s',$table);$s->execute();$check((int)$s->get_result()->fetch_assoc()['n']===1,'Tabel '.$table);$s->close();}
$s=$db->prepare('SELECT COUNT(*) n FROM schema_migrations WHERE migration=?');$migration='019_v3_fase5_laporan_retensi.sql';$s->bind_param('s',$migration);$s->execute();$check((int)$s->get_result()->fetch_assoc()['n']===1,'Migrasi 019 tercatat');$s->close();
$legacy=[];$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME NOT LIKE 'v4\\_%' AND TABLE_NAME NOT IN ('schema_migrations','audit_logs') ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC);
foreach($tables as $row){$t=$row['TABLE_NAME'];if(!preg_match('/^[a-zA-Z0-9_]+$/D',$t))exit(2);$legacy[$t]=(int)$db->query('SELECT COUNT(*) n FROM `'.$t.'`')->fetch_assoc()['n'];}
$manifest=json_encode(['database'=>$name,'legacy_counts'=>$legacy,'captured_at'=>date(DATE_ATOM)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
if(isset($argv[1])){file_put_contents($argv[1],$manifest.PHP_EOL);echo 'Manifest ditulis: '.$argv[1].PHP_EOL;}
else echo $manifest.PHP_EOL;
echo "Backup DB, berkas privat, dan manifest ini wajib disimpan sebelum migrasi.\n";exit($fail?1:0);
