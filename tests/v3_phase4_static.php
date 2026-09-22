<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
$fail=0;$check=static function($ok,$label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
$files=['app/V3/PublikasiService.php','app/V3/PublikasiRepository.php','portal/v3_publikasi.php','portal/v3_publikasi_kelola.php','portal/partials/v3_publikasi_konten.php','bin/v3_phase4_preflight.php','bin/v3_phase4_verify.php'];
foreach($files as $f){exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg(APP_ROOT.'/'.$f),$out,$status);$check($status===0,'PHP lint '.$f);}
$repo=file_get_contents(APP_ROOT.'/app/V3/PublikasiRepository.php');$from=substr($repo,strpos($repo,'private function parentFrom'),strpos($repo,'public function history')-strpos($repo,'private function parentFrom'));
$check(!str_contains($from,'v3_konseling')&&!str_contains($from,'v3_pelanggaran'),'SQL orang tua tidak mengambil isi kasus/sesi/pelanggaran');
$check(str_contains($from,'sw.archived_at IS NULL')&&str_contains($from,'u.id=?')&&str_contains($from,'w.is_active=1'),'SQL pembacaan dibatasi pengguna dan relasi aktif');
$service=file_get_contents(APP_ROOT.'/app/V3/PublikasiService.php');$check(str_contains($service,'parentSerializer(')&&str_contains($service,'waliSantri('),'Memakai allowlist dan resolver wali yang ada');
$rollback=file_get_contents(APP_ROOT.'/database/rollbacks/018_v3_fase4_publikasi.sql');$check(!preg_match('/\b(DROP|DELETE|TRUNCATE)\b/i',$rollback),'Rollback tidak menghapus data atau tabel bisnis');
$api=file_get_contents(APP_ROOT.'/api/v1/index.php');preg_match_all('/^.*\$method === \'GET\'.*$/m',$api,$matches);$bad=fn($lines)=>array_filter($lines,fn($l)=>str_contains($l,'/v3/publikasi')&&preg_match('#/(terbit|tarik|dibaca|pratinjau)\b#',$l));
$check($bad($matches[0])===[],'Tidak ada mutasi publikasi lewat GET');$check(count($bad(["if (\$method === 'GET' && \$path === '/v3/publikasi/terbit')"]))===1,'Penjaga GET mendeteksi contoh yang salah');
$mobile=getenv('MOBILE_APP_ROOT')?:'/Users/ilanmochamad/alhasanApps';$layout=file_get_contents($mobile.'/src/app/_layout.tsx');$screen=file_get_contents($mobile.'/src/app/publikasi/[id].tsx');$push=file_get_contents($mobile.'/src/notifications/notification-context.tsx');
$guard=strpos($layout,'<Stack.Protected guard={Boolean(profile)}>');$route=strpos($layout,'name="publikasi/[id]"');$close=strpos($layout,'</Stack.Protected>',$guard);
$check($guard<$route&&$route<$close,'Layar deep-link berada di Stack autentikasi');$check(str_contains($screen,'api.publikasiDetail(id)')&&str_contains($screen,'setData(null)')&&str_contains($screen,'useFocusEffect'),'Detail memuat ulang API dan menghapus konten lama');$check(str_contains($push,"payload.tipe !== 'v3_publikasi'")&&str_contains($push,'tertunda.current = { tipe: payload.tipe, id }'),'Payload V3 divalidasi dan tujuan sebelum login ditunda');
$check(!preg_match('/console\.|AsyncStorage|Clipboard|analytics/',$screen),'Layar publikasi tidak melog atau menyimpan isi ke penyimpanan/clipboard');
exit($fail?1:0);
