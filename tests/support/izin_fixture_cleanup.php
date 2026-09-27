<?php
function cleanupIzinFixture(mysqli $db,array $createdIds):void
{
    if(!str_ends_with((string)app_config('database.database'),'_test'))throw new RuntimeException('Fixture cleanup test-only');
    $ids=array_values(array_filter(array_map('intval',$createdIds)));if($ids===[])return;
    $list=implode(',',$ids);
    $db->query('DELETE FROM notifikasi_percobaan WHERE outbox_id IN (SELECT id FROM notifikasi_outbox WHERE pengajuan_id IN ('.$list.'))');
    $db->query('DELETE FROM notifikasi_outbox WHERE pengajuan_id IN ('.$list.')');
}
