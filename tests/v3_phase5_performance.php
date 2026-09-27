<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$r=new App\V3\KonselingRepository(app_db());$s=new App\V3\LaporanService(new App\V3\PelanggaranRepository(app_db()),capabilities());$fail=0;
$check=static function($ok,$label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
$u=$r->one("SELECT id FROM users WHERE username='sbx_pengurus_a'");$opt=(new App\V3\PelanggaranRepository(app_db()))->studentOptions((int)$u['id'],1)[0];$tag='SBX-F5-PERF-'.bin2hex(random_bytes(5));$ids=[];
try{
    for($n=0;$n<1000;$n++){
        $r->execute("INSERT INTO v3_pelanggaran(santri_id,tahun_ajaran_id,waktu_kejadian,uraian,kategori_snapshot,tingkat_snapshot,poin_snapshot,status,sumber_warisan,id_warisan) VALUES(?,?,NOW(),'Fixture performa sintetis',?,'Ringan',0,'Draf',?,?)",[(int)$opt['santri_id'],(int)$opt['tahun_ajaran_id'],$tag,$tag,(string)$n]);$ids[]=(int)app_db()->insert_id;
    }
    $start=microtime(true);$first=$s->read($u,['kategori'=>$tag]);$elapsed=microtime(true)-$start;
    $check($first['total']===1000&&count($first['rows'])===25,'Daftar 1.000 data berhalaman');$check($elapsed<2,'Waktu daftar lokal di bawah 2 detik');
    $seen=[];for($page=1;$page<=40;$page++)foreach($s->read($u,['kategori'=>$tag,'page'=>$page])['rows'] as $row)$seen[]=(int)$row['id'];
    $check(count($seen)===1000&&count(array_unique($seen))===1000,'40 halaman tanpa duplikasi atau kehilangan data');
    $check(count($s->read($u,['kategori'=>$tag],true)['rows'])===1000,'Ekspor memuat semua 1.000 baris');
    for($n=1000;$n<10001;$n++){$r->execute("INSERT INTO v3_pelanggaran(santri_id,tahun_ajaran_id,waktu_kejadian,uraian,kategori_snapshot,tingkat_snapshot,poin_snapshot,status,sumber_warisan,id_warisan) VALUES(?,?,NOW(),'Fixture batas ekspor',?,'Ringan',0,'Draf',?,?)",[(int)$opt['santri_id'],(int)$opt['tahun_ajaran_id'],$tag,$tag,(string)$n]);$ids[]=(int)app_db()->insert_id;}
    try{$s->read($u,['kategori'=>$tag],true);$check(false,'Ekspor berlebih ditolak');}catch(App\V3\V3Exception $e){$check($e->status===422&&str_contains($e->getMessage(),'10.000'),'10.001 baris ditolak tanpa ekspor parsial');}
    echo json_encode(['fixture_count'=>1000,'list_seconds'=>$elapsed,'database'=>'MariaDB lokal; bukan staging/cPanel','pagination_ids'=>1000],JSON_PRETTY_PRINT).PHP_EOL;
}finally{
    // Hanya ID hasil INSERT run ini dengan penanda run; foreign key tetap ON.
    foreach(array_chunk($ids,500) as $chunk)$r->execute('DELETE FROM v3_pelanggaran WHERE sumber_warisan=? AND id IN ('.implode(',',array_fill(0,count($chunk),'?')).')',[$tag,...$chunk]);
    $check($r->one('SELECT id FROM v3_pelanggaran WHERE sumber_warisan=?',[$tag])===null,'Fixture performa dibersihkan tepat sesuai run');
}
exit($fail?1:0);
