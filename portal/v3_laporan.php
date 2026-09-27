<?php
declare(strict_types=1);
use App\V3\LaporanService;
use App\V3\PelanggaranRepository;
use App\V3\V3Exception;
use App\Ui\Denial;
require_once dirname(__DIR__).'/app/bootstrap.php';
$user=authorization()->requireWebUser();
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$service=new LaporanService(new PelanggaranRepository(app_db()),capabilities());
$kinds=$service->kinds($user);if($kinds===[])Denial::render('Laporan tidak dapat diakses.','Masuk dengan akun yang berhak atau periksa penugasan aktif.');
$format=is_string($_GET['format']??null)?$_GET['format']:'html';
try{
    if(!in_array($format,['html','cetak','csv'],true))throw new V3Exception('Format tidak tersedia.');
    $list=$service->read($user,$_GET,$format!=='html');
}catch(V3Exception $e){
    if($e->status===403)Denial::render('Laporan tidak dapat diakses.','Masuk dengan akun yang berhak atau periksa penugasan aktif.');
    http_response_code($e->status);ah_page_open(['title'=>'Laporan pembinaan','user'=>$user,'active'=>'v3.laporan']);ah_note('danger',$e->getMessage());ah_page_close();exit;
}
if($format==='csv'){
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="laporan-pembinaan.csv"');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,array_values($list['columns']),',','"','');
    foreach($list['rows'] as $row)fputcsv($out,array_map([LaporanService::class,'csvCell'],array_values($row)),',','"','');fclose($out);exit;
}
$print=$format==='cetak';
ah_page_open(['title'=>'Laporan pembinaan','heading'=>'Laporan pembinaan V3','description'=>'Data sesuai hak akses dan cakupan aktif.','user'=>$user,'active'=>'v3.laporan']);
?>
<?php if(!$print): ?>
<form method="get" class="row g-3 mb-4">
<div class="col-12 col-md-4"><label for="jenis" class="form-label">Jenis laporan</label><select id="jenis" name="jenis" class="form-select"><?php foreach($kinds as $kind): ?><option value="<?= ah_e($kind) ?>" <?= $list['jenis']===$kind?'selected':'' ?>><?= ah_e(ucfirst($kind)) ?></option><?php endforeach ?></select></div>
<?php
$fields=['mulai'=>['Awal periode','date'],'sampai'=>['Akhir periode','date'],'santri_id'=>['ID santri','number'],'status'=>['Status','text'],'tindak_lanjut'=>['Tindak lanjut (ada / belum)','text']];
if($list['jenis']!=='publikasi')$fields+=['kelas_id'=>['ID kelas','number'],'kamar_id'=>['ID kamar','number'],'kategori'=>['Kategori','text'],'tingkat'=>['Tingkat','text'],'poin_min'=>['Poin minimum','number'],'poin_max'=>['Poin maksimum','number'],'pembimbing_id'=>['ID pembimbing','number'],'murobi_id'=>['ID murobi','number']];
foreach($fields as $name=>[$label,$type]): ?>
<div class="col-12 col-md-4"><label for="<?= ah_e($name) ?>" class="form-label"><?= ah_e($label) ?></label><input id="<?= ah_e($name) ?>" name="<?= ah_e($name) ?>" type="<?= ah_e($type) ?>" class="form-control" value="<?= ah_e(is_scalar($_GET[$name]??'')?$_GET[$name]??'':'') ?>"></div>
<?php endforeach ?>
<div class="col-12"><button class="btn btn-primary">Terapkan filter</button></div></form>
<p>Batas ekspor: 10.000 baris. Jika melebihi batas, persempit periode atau filter; ekspor ditolak seluruhnya.</p>
<div class="d-flex flex-wrap gap-2 mb-3">
<?php foreach(['cetak'=>'Cetak / Simpan PDF','csv'=>'Ekspor CSV'] as $f=>$label):$q=$_GET;$q['format']=$f;unset($q['page']); ?><a class="btn btn-outline-primary" href="<?= ah_e(app_url('/portal/v3_laporan.php?'.http_build_query($q))) ?>"><?= ah_e($label) ?></a><?php endforeach ?>
</div>
<?php else: ?>
<style>@media print{nav,aside,header,.ah-sidebar,.ah-topbar,.no-print{display:none!important}main,.ah-main{margin:0!important;width:100%!important}.table-responsive{overflow:visible!important}thead{display:table-header-group}tr{break-inside:avoid}}@page{size:landscape}</style>
<button class="btn btn-primary no-print" onclick="window.print()">Cetak / Simpan PDF</button>
<?php endif ?>
<p><?= (int)$list['total'] ?> baris sesuai filter · <?= ah_e($list['jenis']) ?></p>
<div class="table-responsive"><table class="table"><thead><tr><?php foreach($list['columns'] as $label): ?><th><?= ah_e($label) ?></th><?php endforeach ?></tr></thead><tbody>
<?php foreach($list['rows'] as $row): ?><tr><?php foreach(array_keys($list['columns']) as $key): ?><td><?= ah_e($row[$key]??'—') ?></td><?php endforeach ?></tr><?php endforeach ?>
</tbody></table></div>
<?php if($list['rows']===[])ah_empty('Belum ada data','Tidak ada data yang dapat dilihat untuk filter dan cakupan ini.');
if(!$print)ah_pagination($list['total'],$list['page'],$list['per_page']);ah_page_close();
