<?php
require __DIR__.'/v4_phase4_support.php';
$s=$svc->preview($pe,$plan,$sid,'Catatan umum sintetis')['snapshot'];$base=$s['mapel'][0];$s['mapel']=[];
for($i=1;$i<=30;$i++){$m=$base;$m['nama']='Mata Pelajaran Sintetis '.$i;$m['catatan']=str_repeat('Capaian sintetis dengan catatan untuk pemeriksaan pemenggalan halaman. ',5);$s['mapel'][]=$m;}
$s['catatan_umum']=str_repeat('Catatan sintetis panjang. ',50);
$code=str_repeat('b',48);$bytes=(new App\V4\RaporDocument())->render($s,app_url('/verifikasi_rapor.php').'#'.$code,$code);file_put_contents(APP_ROOT.'/docs/phase-v4-4/rapor-sintetis-banyakhal.pdf',$bytes);
$ok(strlen($bytes)>1000,'Dokumen uji 30 mapel dengan catatan panjang dirender tanpa mutasi penerbitan');
