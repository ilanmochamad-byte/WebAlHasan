<?php

declare(strict_types=1);
use App\V3\V3Exception;
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';
$service=v3_katalog_service();
$kind=$_GET['jenis']??'katalog';
if (!is_string($kind) || !isset(\App\V3\KatalogRepository::TABLES[$kind])) { http_response_code(422);exit('Jenis data tidak valid.'); }
$input=[];$error=null;$history=[];
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $id=filter_var($_POST['id']??0,FILTER_VALIDATE_INT);
        if ($id===false || $id<0) { throw new V3Exception('ID tidak valid.'); }
        $service->save($kind,$_POST,$currentUser,$id>0?$id:null);
        master_flash('success','Perubahan tersimpan dan tercatat dalam audit.');
        master_redirect('admin_v3_katalog.php?jenis='.$kind);
    }
    if (isset($_GET['edit'])) {
        $edit=filter_var($_GET['edit'],FILTER_VALIDATE_INT);
        if ($edit===false || $edit<1) { throw new V3Exception('ID tidak valid.'); }
        $input=$service->find($kind,$edit,$currentUser);
        $history=$service->history($kind,$edit,$currentUser);
    }
} catch (V3Exception $e) {
    http_response_code($e->status);$error=$e->getMessage();
    if ($_SERVER['REQUEST_METHOD']==='POST') { $input=array_filter($_POST,static fn($v)=>is_scalar($v)); }
}
try { $options=$service->options($currentUser);$list=$service->page($kind,$_GET,$currentUser); }
catch(V3Exception $e) { http_response_code($e->status); master_header('Katalog & Ambang V3');echo '<div class="alert alert-danger">'.ah_e($e->getMessage()).'</div>';master_footer();exit; }
$labels=['kategori'=>'Kategori','katalog'=>'Jenis pelanggaran','ambang'=>'Ambang poin'];
$tabs=[];foreach($labels as $k=>$label) { $tabs[]=['label'=>$label,'url'=>'admin_v3_katalog.php?jenis='.$k,'active'=>$kind===$k]; }
master_header('Katalog & Ambang V3',['description'=>'Atur katalog dan rekomendasi pembinaan. Ambang poin hanya memberikan rekomendasi tindak lanjut.','active'=>'v3.katalog','tabs'=>$tabs]);
$value=static fn($key,$default='')=>ah_e($input[$key]??$default);
?>
<style>.v3-form{min-width:0}.v3-form input,.v3-form select,.v3-form textarea{max-width:100%;min-width:0}.v3-history{white-space:pre-wrap;overflow-wrap:anywhere}.v3-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:1rem}</style>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?= ah_e($error) ?></div><?php endif ?>
<section class="card p-3 mb-4"><h2 class="h5"><?= empty($input['id'])?'Tambah':'Ubah' ?> <?= ah_e($labels[$kind]) ?></h2>
<p class="text-muted">Untuk nonaktifkan, pilih status Nonaktif. Untuk mengakhiri, isi tanggal selesai. Setiap perubahan data lama memerlukan alasan.</p>
<form method="post" class="v3-form">
<?= master_csrf() ?><input type="hidden" name="id" value="<?= $value('id',0) ?>"><input type="hidden" name="version" value="<?= $value('version',1) ?>">
<div class="v3-grid">
<?php
$fields=$kind==='ambang'?['label'=>['Label ambang','text',150],'nilai_minimum'=>['Nilai minimum','number',null],'nilai_maksimum'=>['Nilai maksimum (opsional)','number',null]]:['kode'=>['Kode unik','text',40],'nama'=>['Nama','text',150]];
if($kind==='katalog') { $fields['poin_default']=['Poin default','number',null]; }
$fields+=['tanggal_mulai'=>['Mulai berlaku','date',null],'tanggal_selesai'=>['Selesai berlaku (opsional)','date',null]];
foreach($fields as $name=>[$label,$type,$max]): ?>
<div><label class="form-label" for="v3-<?= ah_e($name) ?>"><?= ah_e($label) ?></label><input class="form-control" id="v3-<?= ah_e($name) ?>" name="<?= ah_e($name) ?>" type="<?= ah_e($type) ?>" value="<?= $value($name,$name==='tanggal_mulai'?date('Y-m-d'):'') ?>" <?= in_array($name,['nilai_maksimum','tanggal_selesai'],true)?'':'required' ?> <?= $type==='number'?'min="0" step="1" max="2147483647"':'' ?> <?= $max?'maxlength="'.$max.'"':'' ?>></div>
<?php endforeach;
$selects=['is_active'=>['Status',[1=>'Aktif',0=>'Nonaktif']]];
if($kind==='katalog') { $selects['kategori_id']=['Kategori',array_column($options['kategori'],'nama','id')];$selects['tingkat']=['Tingkat',array_combine(['Ringan','Sedang','Berat'],['Ringan','Sedang','Berat'])]; }
if($kind==='ambang') { $years=[];foreach($options['tahun'] as $year) { $years[$year['id']]=$year['tahun'].' / '.$year['semester']; } $selects['tahun_ajaran_id']=['Tahun ajaran',$years]; }
foreach($selects as $name=>[$label,$choices]): ?>
<div><label class="form-label" for="v3-<?= ah_e($name) ?>"><?= ah_e($label) ?></label><select class="form-select" id="v3-<?= ah_e($name) ?>" name="<?= ah_e($name) ?>" required><?php foreach($choices as $key=>$label): ?><option value="<?= ah_e($key) ?>" <?= (string)($input[$name]??($name==='is_active'?1:''))===(string)$key?'selected':'' ?>><?= ah_e($label) ?></option><?php endforeach ?></select></div>
<?php endforeach ?>
</div>
<?php $name=$kind==='ambang'?'rekomendasi':'uraian'; ?><label class="form-label mt-3" for="v3-description"><?= $kind==='ambang'?'Rekomendasi tindak lanjut':'Uraian (opsional)' ?></label><textarea class="form-control" id="v3-description" name="<?= $name ?>" maxlength="5000" <?= $kind==='ambang'?'required':'' ?>><?= $value($name) ?></textarea>
<label class="form-label mt-3" for="v3-reason">Alasan perubahan</label><textarea class="form-control" id="v3-reason" name="alasan" maxlength="1000" <?= empty($input['id'])?'':'required' ?>><?= $value('alasan') ?></textarea>
<div class="d-flex flex-wrap gap-2 mt-3"><button class="btn btn-primary" type="submit">Simpan <?= ah_e($labels[$kind]) ?></button><a class="btn btn-outline-secondary" href="?jenis=<?= $kind ?>">Bersihkan formulir</a></div>
</form></section>
<section class="card p-3"><h2 class="h5">Daftar <?= ah_e($labels[$kind]) ?></h2>
<form method="get" class="v3-grid mb-3"><input type="hidden" name="jenis" value="<?= $kind ?>">
<?php $filterSelects=['status'=>['Status',[''=>'Semua','aktif'=>'Aktif sekarang','nonaktif'=>'Nonaktif','akan_datang'=>'Akan datang','berakhir'=>'Berakhir']]];
foreach($selects as $name=>$def) { if($name!=='is_active') { $filterSelects[$name]=[$def[0],[''=>'Semua']+$def[1]]; } }
foreach($filterSelects as $name=>[$label,$choices]): ?><div><label class="form-label" for="filter-<?= ah_e($name) ?>"><?= ah_e($label) ?></label><select class="form-select" name="<?= ah_e($name) ?>" id="filter-<?= ah_e($name) ?>"><?php foreach($choices as $key=>$label): ?><option value="<?= ah_e($key) ?>" <?= (string)($_GET[$name]??'')===(string)$key?'selected':'' ?>><?= ah_e($label) ?></option><?php endforeach ?></select></div><?php endforeach ?><div class="align-self-end"><button class="btn btn-outline-primary">Terapkan filter</button></div></form>
<?php if($list['rows']===[]): ?><p>Belum ada data yang cocok.</p><?php endif ?>
<div class="table-responsive"><table class="table"><thead><tr><th>Nama / label</th><th>Kode / rentang</th><th>Masa berlaku</th><th>Status</th><th>Tindakan</th></tr></thead><tbody>
<?php foreach($list['rows'] as $row): ?><tr><td><?= ah_e($row['nama']??$row['label']) ?><?php if($kind==='katalog'): ?><small class="d-block text-muted"><?= ah_e($row['kategori_nama'].' · '.$row['tingkat'].' · '.$row['poin_default'].' poin') ?></small><?php endif ?></td><td><?= ah_e($row['kode']??($row['nilai_minimum'].'–'.($row['nilai_maksimum']??'∞'))) ?></td><td><?= ah_e($row['tanggal_mulai'].' — '.($row['tanggal_selesai']??'Tanpa batas')) ?></td><td><?= ah_e($row['status_efektif']) ?></td><td><a href="?jenis=<?= $kind ?>&amp;edit=<?= (int)$row['id'] ?>">Ubah / riwayat</a></td></tr><?php endforeach ?>
</tbody></table></div><?php master_pagination($list['total'],$list['page'],$list['per_page']); ?></section>
<?php if($history): ?><section class="card p-3 mt-4"><h2 class="h5">50 perubahan terakhir</h2><?php foreach($history as $event): ?><details><summary><?= ah_e($event['created_at'].' · '.$event['action'].' · akun #'.$event['actor_user_id']) ?></summary><pre class="v3-history"><?= ah_e('Sebelum: '.($event['before_json']??'—')."\nSesudah: ".($event['after_json']??'—')) ?></pre></details><?php endforeach ?></section><?php endif ?>
<?php master_footer(); ?>
