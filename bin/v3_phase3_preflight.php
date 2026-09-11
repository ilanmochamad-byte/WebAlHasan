<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$repo=new App\V3\KonselingRepository(app_db());$fail=0;
$check=static function(bool $ok,string $label)use(&$fail):void{echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
$count=static fn(string $sql,array $params=[]):int=>(int)array_values($repo->one($sql,$params)??[0])[0];

try{
    foreach(['013_v3_fase1.sql','014_v3_fase2_pelanggaran.sql','015_v3_fase2_koreksi_dan_rekomendasi.sql'] as $migration){
        $check($count('SELECT COUNT(*) FROM schema_migrations WHERE migration=?',[$migration])===1,'Prasyarat '.$migration.' tercatat');
    }
    foreach(['v3_konseling_kasus','v3_konseling_sesi','v3_konseling_tautan','v3_rekomendasi'] as $table){
        $check($count('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])===1,'Prasyarat tabel '.$table);
    }
    $check($count('SELECT COUNT(*) FROM (SELECT revisi_dari_id FROM v3_konseling_sesi WHERE revisi_dari_id IS NOT NULL GROUP BY revisi_dari_id HAVING COUNT(*)>1) d')===0,'Tidak ada sumber sesi dengan lebih dari satu revisi langsung');
    $check($count('SELECT COUNT(*) FROM v3_konseling_sesi c JOIN v3_konseling_sesi p ON p.id=c.revisi_dari_id WHERE c.kasus_id<>p.kasus_id')===0,'Revisi sesi tidak berpindah kasus');
    $check($count('SELECT COUNT(*) FROM (SELECT pelanggaran_id,kasus_id,IFNULL(sesi_id,0) sesi_guard FROM v3_konseling_tautan WHERE archived_at IS NULL GROUP BY pelanggaran_id,kasus_id,IFNULL(sesi_id,0) HAVING COUNT(*)>1) d')===0,'Tidak ada tautan pelanggaran/kasus/sesi efektif yang ganda');

    $index=$repo->one("SELECT NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) kolom FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_sesi' AND INDEX_NAME='sesi_satu_revisi' GROUP BY NON_UNIQUE");
    $check($index===null||((int)$index['NON_UNIQUE']===0&&(string)$index['kolom']==='revisi_dari_id'),'Nama indeks sesi_satu_revisi kosong atau sudah kompatibel');
    $caseColumn=$repo->one("SELECT DATA_TYPE,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi' AND COLUMN_NAME='ditindaklanjuti_kasus_id'");
    $check($caseColumn===null||($caseColumn['DATA_TYPE']==='bigint'&&str_contains((string)$caseColumn['COLUMN_TYPE'],'unsigned')&&$caseColumn['IS_NULLABLE']==='YES'),'Kolom tautan rekomendasi kosong atau sudah kompatibel');
    $timeColumn=$repo->one("SELECT DATA_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi' AND COLUMN_NAME='ditindaklanjuti_pada'");
    $check($timeColumn===null||($timeColumn['DATA_TYPE']==='datetime'&&$timeColumn['IS_NULLABLE']==='YES'),'Kolom waktu tindak lanjut kosong atau sudah kompatibel');
    $foreign=$repo->one("SELECT REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_rekomendasi' AND CONSTRAINT_NAME='rekomendasi_kasus_fk'");
    $check($foreign===null||($foreign['REFERENCED_TABLE_NAME']==='v3_konseling_kasus'&&$foreign['REFERENCED_COLUMN_NAME']==='id'),'Foreign key rekomendasi kosong atau sudah kompatibel');
    // Migrasi 017 (keputusan Human Developer 11 September 2026): tabel revisi kasus harus belum ada atau sudah kompatibel.
    $revisionTable=$count("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_kasus_revisi'");
    $check($revisionTable===0||$count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_kasus_revisi' AND COLUMN_NAME IN ('kasus_id','versi_sebelum','tujuan_sebelum','tujuan_sesudah','kerahasiaan_sebelum','kerahasiaan_sesudah','alasan','kapasitas','created_by')")===9,'Tabel revisi kasus 017 kosong atau sudah kompatibel');
    $dangling=$count("SELECT COUNT(*) FROM v3_konseling_sesi s JOIN v3_konseling_kasus k ON k.id=s.kasus_id WHERE NOT EXISTS (SELECT 1 FROM v3_konseling_sesi nx WHERE nx.revisi_dari_id=s.id) AND s.archived_at IS NULL AND s.status IN ('Dijadwalkan','Dijadwalkan Ulang') AND k.status IN ('Selesai','Dibatalkan')");
    echo 'Manifest baris: kasus '.$count('SELECT COUNT(*) FROM v3_konseling_kasus').', sesi '.$count('SELECT COUNT(*) FROM v3_konseling_sesi').', tautan '.$count('SELECT COUNT(*) FROM v3_konseling_tautan').', rekomendasi '.$count('SELECT COUNT(*) FROM v3_rekomendasi').', sesi terjadwal pada kasus tertutup yang akan ditutup 017 '.$dangling.PHP_EOL;
}catch(Throwable $exception){$check(false,'Preflight tidak dapat diselesaikan: '.$exception->getMessage());}

echo $fail===0?'LULUS: migrasi 016 dan 017 siap dijalankan setelah backup/restore point dibuat.'.PHP_EOL:'BLOCKER: '.$fail.' pemeriksaan preflight gagal.'.PHP_EOL;
exit($fail===0?0:1);
