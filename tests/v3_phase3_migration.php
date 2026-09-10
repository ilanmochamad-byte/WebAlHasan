<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$repo=new App\V3\KonselingRepository(app_db());$fail=0;$check=static function(bool $ok,string $label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};$count=static fn(string $sql,array $p=[]):int=>(int)array_values($repo->one($sql,$p)??[0])[0];
$latest=(string)$repo->one('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1')['migration'];if($latest!=='016_v3_fase3_konseling.sql'){echo "Urutan migrasi tidak aman untuk drill Fase 3.\n";exit(2);}
$index=static fn(string $table,string $name):int=>$count('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',[$table,$name]);
$preserved=[];foreach(['v3_konseling_kasus','v3_konseling_sesi','v3_konseling_tautan','v3_rekomendasi','v3_pelanggaran','v3_poin_ledger'] as $table)$preserved[$table]=$count('SELECT COUNT(*) FROM `'.$table.'`');
// Keputusan bisnis milik 016 tidak dapat dibangun ulang dari tabel lain, sehingga harus bertahan melewati rollback (audit K4).
$decisions=static function()use($repo):array{$out=[];foreach(['kasus'=>'SELECT id,alasan_revisi_terakhir,alasan_pembatalan FROM v3_konseling_kasus ORDER BY id','sesi'=>'SELECT id,alasan_penjadwalan_ulang,alasan_pembatalan FROM v3_konseling_sesi ORDER BY id','rekomendasi'=>'SELECT id,status,ditindaklanjuti_kasus_id,ditindaklanjuti_pada FROM v3_rekomendasi ORDER BY id'] as $name=>$sql)foreach($repo->all($sql) as $row)$out[$name][(int)$row['id']]=$row;return $out;};
$before=$decisions();
$migrator=new App\Database\Migrator(app_db(),APP_ROOT.'/database/migrations',APP_ROOT.'/database/rollbacks');$check($migrator->up()===[],'Runner ulang tidak menggandakan migrasi 016');$check($migrator->rollbackLast()==='016_v3_fase3_konseling.sql','Rollback 016 berhasil');
$check($count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_kasus' AND COLUMN_NAME IN ('alasan_revisi_terakhir','alasan_pembatalan')")===2&&$count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_sesi' AND COLUMN_NAME IN ('alasan_penjadwalan_ulang','alasan_pembatalan')")===2&&$count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi' AND COLUMN_NAME IN ('ditindaklanjuti_kasus_id','ditindaklanjuti_pada')")===2,'Rollback tidak membuang kolom keputusan bisnis Fase 3');
$check($decisions()===$before,'Rollback mempertahankan alasan kasus/sesi dan tautan rekomendasi ke kasus');
$check($index('v3_konseling_tautan','tautan_unik_efektif')===0&&$count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_tautan' AND COLUMN_NAME='sesi_unik_guard'")===0,'Rollback melepas guard tautan draf yang redundan');
$check($count("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_tautan' AND INDEX_NAME='tautan_unik' AND NON_UNIQUE=0")>0,'Unique tautan 013 tetap menjaga tautan sesi NULL selama rollback');
$check($index('v3_konseling_sesi','revisi_dari_id')>0,'Rollback memulihkan indeks penyangga foreign key revisi');
$check($index('v3_konseling_sesi','sesi_satu_revisi')===0&&$index('v3_rekomendasi','rekomendasi_tindak_lanjut_index')===0,'Rollback melepas indeks struktural milik 016');
$unchanged=true;foreach($preserved as $table=>$rows)if($count('SELECT COUNT(*) FROM `'.$table.'`')!==$rows)$unchanged=false;$check($unchanged,'Rollback tidak menghapus kasus, sesi, tautan, rekomendasi, pelanggaran, atau ledger');
$check($migrator->up()===['016_v3_fase3_konseling.sql'],'Migrasi 016 terpasang kembali');$check($migrator->up()===[],'Pemasangan ulang tetap idempoten');
$after=$decisions();$kept=true;foreach($before as $name=>$rows)foreach($rows as $id=>$row)foreach($row as $column=>$value)if($value!==null&&$value!==''&&($after[$name][$id][$column]??null)!==$value)$kept=false;
$check($kept,'Pasang ulang tidak menimpa keputusan bisnis yang sudah tersimpan');
$check($count("SELECT COUNT(*) FROM v3_konseling_kasus WHERE status='Dibatalkan' AND (alasan_pembatalan IS NULL OR alasan_pembatalan='')")===0,'Pasang ulang memberi penanda jujur pada kasus batal tanpa alasan');
$check($index('v3_konseling_sesi','sesi_satu_revisi')>0,'Penjaga satu revisi sesi tersedia kembali');
$check($index('v3_konseling_tautan','tautan_unik_efektif')===0,'Pemasangan ulang tidak memasang guard tautan duplikat');
$check($index('v3_konseling_sesi','sesi_revisi_fk')===0,'Pemasangan ulang tidak meninggalkan indeks rollback redundan');
$check($count("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi' AND CONSTRAINT_NAME='rekomendasi_kasus_fk'")===1,'Foreign key rekomendasi ke kasus tersedia kembali');
// Guard sesi NULL dibuktikan pada tingkat database, bukan hanya lewat service.
$link=$repo->one('SELECT pelanggaran_id,kasus_id FROM v3_konseling_tautan WHERE sesi_id IS NULL ORDER BY id LIMIT 1');$check($link!==null,'Fixture tautan tingkat kasus tersedia untuk uji guard database');
if($link!==null){$linksBefore=$count('SELECT COUNT(*) FROM v3_konseling_tautan');try{$repo->execute('INSERT INTO v3_konseling_tautan (pelanggaran_id,kasus_id,sesi_id,is_active) VALUES (?,?,NULL,1)',[(int)$link['pelanggaran_id'],(int)$link['kasus_id']]);$check(false,'Database menolak tautan tingkat kasus ganda dengan sesi NULL');}catch(App\V3\V3Exception $exception){$check($exception->status===409&&$count('SELECT COUNT(*) FROM v3_konseling_tautan')===$linksBefore,'Database menolak tautan tingkat kasus ganda dengan sesi NULL');}}
exit($fail?1:0);
