<?php
require __DIR__.'/v4_phase4_support.php';
$ids=json_decode(file_get_contents('/private/tmp/v4-fase4-ids.json'),true);
$action=$argv[1]??'';
if($action==='relation'){$active=($argv[2]??'')==='1';$wid=(int)$ids['wali4']['a'];$db->query("UPDATE santri_wali SET archived_at=".($active?'NULL':'NOW()')." WHERE santri_id=$sid AND wali_id=$wid");}
elseif($action==='summary')echo json_encode(['pdfs'=>(int)$q('SELECT COUNT(*) n FROM v4_rapor_versi')[0]['n'],'inapp'=>(int)$q("SELECT COUNT(*) n FROM notifikasi_outbox WHERE kanal='InApp'")[0]['n']]);
elseif($action==='hash')echo $q('SELECT pdf_sha256 FROM v4_rapor_versi WHERE rapor_id='.(int)$ids['rapor'].' AND version=1')[0]['pdf_sha256'];
else exit(2);
