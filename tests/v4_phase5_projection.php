<?php
declare(strict_types=1);
require __DIR__.'/v4_phase4_support.php';
$rid=(int)$q('SELECT id FROM v4_rapor LIMIT 1')[0]['id'];
$before=$q("SELECT snapshot_json,pdf_sha256 FROM v4_rapor_versi WHERE rapor_id=$rid ORDER BY version");
$view=$svc->parentView($users['v4_wali4a'],$rid);
$allowed=['adab','kedisiplinan','tanggung_jawab','catatan'];
$found=false;
foreach($view['versi'] as $v){
 if(!isset($v['snapshot']))continue;
 $found=true;$s=$v['snapshot'];
 $ok(!array_diff(array_keys($s['sikap']),$allowed),'Wali hanya menerima sikap tampilan');
 $ok(!isset($s['kehadiran']['sumber'])&&!isset($s['rencana_id'])&&!isset($s['santri']['id']),'Wali tidak menerima sumber kehadiran/ID internal');
 foreach($s['penanda'] as $p)$ok(array_keys($p)===['jabatan','nama','gelar'],'Penanda wali tanpa gambar/aset/hash internal');
 $ok(!str_contains(json_encode($s),'data:image'),'JSON wali nol gambar tanda tangan');
 $staff=$svc->show($pe,$rid,true);
 $ok(isset($staff['versi'][0]['snapshot']['penanda'][0]['gambar']),'Pembacaan petugas/web tetap lengkap');
}
$ok($found,'Fixture memiliki versi terbit untuk uji proyeksi');
$ok($before===$q("SELECT snapshot_json,pdf_sha256 FROM v4_rapor_versi WHERE rapor_id=$rid ORDER BY version"),'Proyeksi tidak mengubah snapshot/hash versi lama');
$reject(fn()=>$svc->parentView($users['v4_wali4lain'],$rid),403,'Proyeksi tetap menolak wali lain');
$ok(in_array('rapor.wali',capabilities()->v4Capabilities($users['v4_wali4a'])['list'],true),'Menu wali berasal dari relasi aktif server');
$ok(!in_array('rapor.verifikasi',capabilities()->v4Capabilities($users['v4_guru'])['list'],true),'Guru tidak mendapat menu verifikasi');
$ok(in_array('nilai.input_pengganti',capabilities()->v4Capabilities($pe)['list'],true),'Pendidikan mendapat menu pengganti');
