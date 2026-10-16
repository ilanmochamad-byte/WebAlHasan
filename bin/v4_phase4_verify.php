<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli')exit(77);
$db=app_db();$repo=new App\V4\BahanRepository($db,audit_logger());$fail=0;
$checks=[
 'PDF dan hash wajib'=>"SELECT COUNT(*) n FROM v4_rapor_versi WHERE pdf_private_path IS NULL OR pdf_sha256 IS NULL",
 'Kode dan versi konsisten'=>"SELECT COUNT(*) n FROM v4_rapor_versi v LEFT JOIN v4_verifikasi k ON k.rapor_versi_id=v.id WHERE k.id IS NULL OR k.status<>v.status OR k.pdf_sha256<>v.pdf_sha256",
 'Operasi penerbitan wajib'=>"SELECT COUNT(*) n FROM v4_rapor_versi v WHERE NOT EXISTS(SELECT 1 FROM v4_rapor_operasi o WHERE o.rapor_id=v.rapor_id AND o.version=v.version AND o.tindakan IN ('Terbit','Ganti'))",
 'Versi current ada'=>"SELECT COUNT(*) n FROM v4_rapor r WHERE r.current_version>0 AND NOT EXISTS(SELECT 1 FROM v4_rapor_versi v WHERE v.rapor_id=r.id AND v.version=r.current_version)",
 'Tidak ada dua versi sah'=>"SELECT COUNT(*) n FROM (SELECT rapor_id FROM v4_rapor_versi WHERE status='Terbit' GROUP BY rapor_id HAVING COUNT(*)>1) x",
];foreach($checks as $label=>$sql){$ok=(int)$repo->query($sql)[0]['n']===0;echo($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;$fail+=(int)!$ok;}
$rows=$repo->query('SELECT * FROM v4_rapor_versi');foreach($rows as $v){try{(new App\V4\RaporStorage())->read($v);echo '[lulus] PDF versi #'.$v['id'].' utuh'.PHP_EOL;}catch(Throwable){echo '[gagal] PDF versi #'.$v['id'].PHP_EOL;$fail++;}}
exit($fail?1:0);
