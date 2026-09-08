<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli') { http_response_code(404);exit; }
$pre=in_array('--pre',$argv,true);$fail=0;
$check=static function(bool $ok,string $label)use(&$fail):void{echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
try {
    $r=new \App\V3\KatalogRepository(app_db());
    $count=static fn(string $sql,array $v=[]):int=>(int)array_values($r->rows($sql,$v)[0])[0];
    $check($count("SELECT COUNT(*) FROM schema_migrations WHERE migration='012_fondasi_penugasan_v3_v6.sql'")===1,'Fondasi 012 tercatat');
    foreach(['users','roles','user_roles','guru','pengurus','wali','santri','tahun_ajaran','kelas','kamar','santri_wali','pembimbing_assignments','murobi_assignments','audit_logs','notifikasi_outbox','perangkat_push','pelanggaran'] as $table) {
        $check($count('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])===1,'Prasyarat '.$table);
    }
    $roles=array_column($r->rows('SELECT slug FROM roles ORDER BY slug'),'slug');
    $check($roles===['admin','guru','orang_tua','pengurus'],'Role dasar tepat empat, tanpa role fungsional');
    $check($count("SELECT COUNT(*) FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL")>0,'Tahun ajaran aktif tersedia');
    $admins=$r->rows("SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.is_active=1 AND r.slug='admin'");
    $check($admins!==[],'Admin aktif tersedia');
    $check($count('SELECT COUNT(*) FROM santri_wali sw JOIN wali w ON w.id=sw.wali_id JOIN santri s ON s.id=sw.santri_id WHERE sw.archived_at IS NULL AND w.is_active=1 AND w.archived_at IS NULL AND s.is_active=1 AND s.archived_at IS NULL')>0,'Relasi wali aktif tersedia');
    $caps=new \App\Auth\Capabilities(app_db());$p=0;$m=0;
    foreach($r->rows('SELECT id FROM users WHERE is_active=1') as $u) {
        $c=$caps->v3Capabilities($u);$p+=isset($c['v3.pelanggaran.kelola'])?1:0;$m+=isset($c['v3.murobi.mengetahui'])?1:0;
    }
    $check($p>0,'Capability pembimbing dapat dihitung');$check($m>0,'Capability murobi dapat dihitung');
    if(!$pre) {
        $check($count("SELECT COUNT(*) FROM schema_migrations WHERE migration='013_v3_fase1.sql'")===1,'Migrasi V3 tercatat');
        $sql=file_get_contents(APP_ROOT.'/database/migrations/013_v3_fase1.sql');
        preg_match_all('/CREATE TABLE IF NOT EXISTS (v3_\w+) \((.*?)\n\) ENGINE/s',$sql,$tables,PREG_SET_ORDER);
        foreach($tables as $t) {
            $table=$t[1];$body=$t[2];
            $check($count('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])===1,'Tabel '.$table);
            preg_match_all('/^    (\w+) (?:BIGINT|INT|TINYINT|VARCHAR|CHAR|TEXT|LONGTEXT|ENUM|DATE|DATETIME|TIMESTAMP)\b/m',$body,$columns);
            $actual=array_column($r->rows('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]),'COLUMN_NAME');
            $check(array_diff($columns[1],$actual)===[],'Kolom '.$table);
            foreach(['FOREIGN KEY'=>'FOREIGN KEY','CHECK'=>'CHECK','UNIQUE'=>'UNIQUE'] as $type=>$pattern) {
                $expected=preg_match_all('/\b'.$pattern.'\b/',$body);
                $check($count('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_TYPE=?',[$table,$type])===$expected,$type.' '.$table);
            }
            $check($count("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY'",[$table])===1,'Primary index '.$table);
            preg_match_all('/\b(?:INDEX|UNIQUE KEY) (\w+)/',$body,$indexes);
            foreach($indexes[1] as $index) { $check($count('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',[$table,$index])>0,'Index '.$index); }
        }
        // Read actual FK metadata and check every reference, including the legacy foundation.
        foreach($r->rows('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL') as $fk) {
            foreach($fk as $identifier) {if(!preg_match('/^[a-zA-Z0-9_]+$/D',$identifier))throw new RuntimeException('Identifier tidak valid.');}
            $q='SELECT COUNT(*) FROM `'.$fk['TABLE_NAME'].'` c LEFT JOIN `'.$fk['REFERENCED_TABLE_NAME'].'` p ON p.`'.$fk['REFERENCED_COLUMN_NAME'].'`=c.`'.$fk['COLUMN_NAME'].'` WHERE c.`'.$fk['COLUMN_NAME'].'` IS NOT NULL AND p.`'.$fk['REFERENCED_COLUMN_NAME'].'` IS NULL';
            $check($count($q)===0,'Tidak yatim '.$fk['TABLE_NAME'].'.'.$fk['COLUMN_NAME']);
        }
        $check($count("SELECT COUNT(*) FROM v3_ambang a JOIN v3_ambang b ON a.id<b.id AND a.tahun_ajaran_id=b.tahun_ajaran_id WHERE a.is_active=1 AND b.is_active=1 AND a.archived_at IS NULL AND b.archived_at IS NULL AND a.nilai_minimum<=COALESCE(b.nilai_maksimum,2147483647) AND b.nilai_minimum<=COALESCE(a.nilai_maksimum,2147483647) AND a.tanggal_mulai<=COALESCE(b.tanggal_selesai,'9999-12-31') AND b.tanggal_mulai<=COALESCE(a.tanggal_selesai,'9999-12-31')")===0,'Tidak ada ambang bertumpang tindih');
    }
} catch(Throwable $e) { $check(false,'Diagnostik tidak dapat diselesaikan; periksa koneksi/skema pada lingkungan yang tepat.'); }
echo $fail===0?"LULUS: tidak ada blocker.\n":"BLOCKER: {$fail} pemeriksaan gagal.\n";exit($fail===0?0:1);
