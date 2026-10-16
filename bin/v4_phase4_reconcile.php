<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli')exit(77);
// Read-only reconciliation. Never automatically deletes a PDF (incl. uncertain commit).
$db=app_db();$repo=new App\V4\BahanRepository($db,audit_logger());$refs=array_column($repo->query('SELECT pdf_private_path FROM v4_rapor_versi'),'pdf_private_path');$orphans=0;
foreach(glob(APP_ROOT.'/storage/private/v4/*.pdf')?:[] as $file){if(!in_array(basename($file),$refs,true)&&filemtime($file)<time()-86400){$orphans++;}}
echo "PDF tanpa referensi berumur >24 jam: $orphans. Tidak ada berkas dihapus. Hentikan penulis, backup dan periksa DB sebelum pembersihan manual.\n";
require __DIR__.'/v4_phase4_verify.php';
