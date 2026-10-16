<?php
require __DIR__.'/v4_phase4_support.php';
$ids=json_decode(file_get_contents('/private/tmp/v4-fase4-ids.json'),true);$rid=(int)$ids['rapor'];
$row=$q("SELECT * FROM v4_nilai WHERE santri_id=$sid AND komponen='Akhir' ORDER BY id LIMIT 1")[0];$id=(int)$row['id'];$mid=(int)$row['rencana_mapel_id'];
$counts=static fn()=>[(int)$q('SELECT COUNT(*) n FROM v4_rapor_versi')[0]['n'],(int)$q('SELECT COUNT(*) n FROM notifikasi_outbox')[0]['n']];$before=$counts();
// Synthetic source defect: remove the final component without deleting protected history.
// The temporary row remains a valid Harian row; all other subjects/attitude/attendance stay complete.
$db->query("UPDATE v4_nilai SET komponen='Harian',nomor_harian=99 WHERE id=$id");
try{
 $matrix=$vs->read($pe,$plan,$sid)['matriks'][0];$m=array_values(array_filter($matrix['bahan'],fn($x)=>$x['jenis']==='nilai'&&$x['mapel_key']===$mid))[0];
 $ok($m['hasil']['kelengkapan']==='Belum lengkap'&&!$m['lengkap'],'Komponen akhir mapel wajib hilang benar-benar Belum lengkap');
 $reject(fn()=>$svc->act($pe,$make('Ganti')),422,'Penerbitan versi baru ditolak saat satu komponen mapel wajib hilang');
 $ok($before===$counts(),'Nol versi/PDF terbit baru dan nol notifikasi pada nilai wajib hilang');
}finally{
 $s=$db->prepare('UPDATE v4_nilai SET komponen=?,nomor_harian=?,updated_at=? WHERE id=?');$s->bind_param('sisi',$row['komponen'],$row['nomor_harian'],$row['updated_at'],$id);$s->execute();
}
$ok(hash('sha256',$svc->pdf($pe,$rid,1,true))===$ids['hash_v1'],'PDF terbit terdahulu tetap identik setelah probe sumber sintetis');
