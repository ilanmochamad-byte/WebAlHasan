<?php
declare(strict_types=1);
require __DIR__.'/v4_phase4_support.php';
$audit=$q("SELECT before_json,after_json FROM audit_logs WHERE action LIKE 'v4.%'");
$encoded=json_encode($audit,JSON_THROW_ON_ERROR);
foreach(['"nilai"','"catatan"','"nama_santri"','data:image','"gambar"','"password"','"token"'] as $needle)$ok(!str_contains(stripslashes($encoded),$needle),'Audit V4 tanpa isi sensitif: '.$needle);
$rows=$q("SELECT judul,isi,data_json FROM notifikasi_outbox WHERE event_type='v4_rapor_terbit'");
$ok(count($rows)>0,'Ada outbox rapor untuk pemeriksaan payload');
foreach($rows as $row){$data=json_decode($row['data_json'],true);$ok($row['judul']==='Rapor semester tersedia'&&$row['isi']==='Rapor semester tersedia'&&array_keys($data)===['tipe','rapor_id']&&$data['tipe']==='rapor','Outbox hanya teks generik dan penunjuk rapor');}
$logs='';foreach(['V4_HTTP_LOG','V4_METRO_LOG'] as $key){$path=getenv($key);if(!$path||!str_starts_with($path,'/private/tmp/')||!is_file($path))exit(77);$logs.=file_get_contents($path);}
$secrets=array_values(json_decode(file_get_contents(getenv('V4_HTTP_TOKENS')),true));
foreach(['V4_TEST_PASSWORD','API_TOKEN_HASH_SECRET','PUSH_TOKEN_KEY'] as $key)$secrets[]=getenv($key);
foreach($secrets as $value)$ok(!$value||!str_contains($logs,(string)$value),'Log umum tidak berisi credential/token uji (nilai tidak ditampilkan)');
$ok(!preg_match('/Santri Sintetis|Catatan aplikasi sintetis|Koreksi murobi dari aplikasi|data:image|Bearer\s+[A-Za-z0-9_-]{20}/',$logs),'Log HTTP/Metro tanpa nama santri, catatan, gambar atau bearer');
$ok(!preg_match('/PHP (Warning|Fatal)|Uncaught Error/',$logs),'Log HTTP/Metro tanpa warning/fatal PHP atau error runtime');
