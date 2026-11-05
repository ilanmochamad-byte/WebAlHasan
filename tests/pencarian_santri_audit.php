<?php
/** Probe auditor: fixture sintetis yang sudah di-seed Keamanan, tanpa mengubah data. */
declare(strict_types=1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/^test_.*_test$/', $db)) { exit(2); }
require dirname(__DIR__) . '/app/bootstrap.php';
$n = 0; $gagal = 0;
function cek(bool $v, string $s): void { global $n, $gagal; echo ($v ? '[lulus] ' : '[gagal] ') . $s . PHP_EOL; $v ? $n++ : $gagal++; }
function akun(string $name): array {
    $db = app_db();
    $s = $db->prepare('SELECT id,guru_id FROM users WHERE username=?'); $s->execute([$name]); $u = $s->get_result()->fetch_assoc();
    $r = $db->query('SELECT r.slug FROM roles r JOIN user_roles ur ON ur.role_id=r.id WHERE ur.user_id=' . (int)$u['id']);
    $u['roles'] = array_column($r->fetch_all(MYSQLI_ASSOC), 'slug'); return $u;
}
$k = akun('sbx_pengurus_biasa');
$api = keamanan_api_service();
$q = ['date_from'=>'2020-01-01','date_to'=>'2030-01-01','per_page'=>100];
$all = []; $page = 1;
do { $d=$api->kehadiran($k, $q+['page'=>$page++]); array_push($all,...$d['items']); } while(count($all)<$d['pagination']['total']);
cek(count($all)>1, 'Fixture mempunyai banyak baris kehadiran');
$target = $all[count($all)-1];
$first = $api->kehadiran($k, array_merge($q,['per_page'=>1]));
cek($first['items'][0]['attendance_id'] !== $target['attendance_id'], 'Target berada di luar halaman pertama (per_page=1)');
foreach ([$target['identity_number'],mb_strtoupper($target['subject_name'])] as $term) {
    $expected=array_values(array_filter($all, static fn($r)=>str_contains(mb_strtolower($r['subject_name']),mb_strtolower($term)) || str_contains(mb_strtolower($r['identity_number']),mb_strtolower($term))));
    $found=$api->kehadiran($k,array_merge($q,['q'=>$term]));
    cek(array_column($found['items'],'attendance_id')===array_column($expected,'attendance_id'), 'Pencarian nama/NIS menjangkau seluruh halaman dan mempertahankan urutan');
    cek($found['summary']['detail_count']===count($expected) && $found['pagination']['total']===count($expected), 'Ringkasan dan pagination mengikuti q');
}
foreach (['tidak-ada-PCS-CODEX','%','_'] as $term) {
    $expected=array_filter($all, static fn($r)=>str_contains($r['subject_name'],$term)||str_contains($r['identity_number'],$term));
    $found=$api->kehadiran($k,$q+['q'=>$term]);
    cek($found['summary']['detail_count']===count($expected), 'Tanpa hasil / wildcard harfiah: ' . $term);
}
foreach ([['array'],str_repeat('x',101)] as $invalid) {
    try { $api->kehadiran($k,$q+['q'=>$invalid]); cek(false,'q tidak sah ditolak 422'); }
    catch (App\Keamanan\KeamananException $e) { cek($e->status()===422,'q tidak sah ditolak 422'); }
}
$empty=$api->kehadiran($k,$q+['q'=>'  ']);
cek($empty['summary']===$d['summary'], 'q kosong mempertahankan ringkasan lama');
foreach (['sbx_admin','sbx_pengurus_b'] as $name) {
    try { $api->kehadiran(akun($name),$q+['q'=>$target['identity_number']]); cek(false,'Pencarian tidak membuka akses akun tanpa Keamanan'); }
    catch (App\Keamanan\KeamananException $e) { cek($e->status()===403,'Pencarian tidak membuka akses akun tanpa Keamanan'); }
}
cek(!array_filter($all,static fn($r)=>!(bool)app_db()->query('SELECT is_active=1 AND archived_at IS NULL FROM santri WHERE id='.(int)$r['subject_id'])->fetch_row()[0]),'Oracle hanya peserta santri aktif');
echo "Ringkasan: $n lulus, $gagal gagal\n"; exit($gagal?1:0);
