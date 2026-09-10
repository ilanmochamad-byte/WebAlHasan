<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Ui\Denial;
use App\V3\AttachmentStorage;
use App\V3\V3Exception;

require_once dirname(__DIR__).'/app/bootstrap.php';
$currentUser=authorization()->requireWebUser();
$v3Caps=capabilities()->v3Capabilities($currentUser);
if(array_intersect(['v3.pelanggaran.kelola','v3.binaan.baca','v3.pengawasan','v3.koreksi'],array_keys($v3Caps))===[]){
    Denial::render('Akun ini tidak memiliki akses pelanggaran V3.','Akses dihitung dari penugasan pembimbing/murobi aktif atau hak pengawasan admin.');
}
$service=v3_pelanggaran_service();$error='';$input=$_POST;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        Csrf::requireValid($_POST['_csrf']??$_SERVER['HTTP_X_CSRF_TOKEN']??null);
        if(($_POST['aksi']??'')!=='buat'){throw new V3Exception('Aksi tidak dikenal.');}
        $scope=explode('|',(string)($_POST['santri_scope']??''),2);
        $input['santri_id']=$scope[0]??'';$input['tahun_ajaran_id']=$scope[1]??'';
        $storage=new AttachmentStorage(APP_ROOT.'/storage/private/v3');
        $attachment=$storage->stageUpload($_FILES['lampiran']??null);
        $result=$service->create($currentUser,$input,$attachment);
        ah_flash_set('success',$result['replayed']?'Permintaan identik sudah pernah disimpan.':'Pelanggaran dicatat dan poin direkonsiliasi.');
        ah_redirect('/portal/v3_pelanggaran_detail.php?id='.(int)$result['data']['pelanggaran']['id']);
    }catch(V3Exception $exception){$error=$exception->getMessage();}
}
$options=$service->options($currentUser);
try{$list=$service->page($currentUser,$_GET);}catch(V3Exception $exception){$error=$exception->getMessage();$list=['rows'=>[],'total'=>0,'page'=>1,'per_page'=>25,'peringatan_konfigurasi'=>[]];}
$newKey='web-'.bin2hex(random_bytes(16));
ah_page_open(['title'=>'Pelanggaran & poin','heading'=>'Pelanggaran & poin V3','description'=>'Catat kejadian dalam cakupan aktif, pantau ledger poin, dan tindak lanjuti rekomendasi secara manual.','user'=>$currentUser,'active'=>'v3.pelanggaran']);
if($error!==''){ah_note('danger',$error);}
foreach(array_unique(array_merge($options['peringatan_konfigurasi'],$list['peringatan_konfigurasi'])) as $warning){ah_note('warning',$warning);}
?>
<?php if($options['dapat_mencatat']): ?>
<section class="ah-card mb-4"><div class="ah-card__body"><h2 class="h5">Catat pelanggaran</h2>
<p class="text-muted">Poin diambil dari snapshot katalog saat disimpan. Santri tanpa penempatan aktif tidak ditampilkan.</p>
<form method="post" enctype="multipart/form-data" class="row g-3">
<?= ah_csrf() ?><input type="hidden" name="aksi" value="buat"><input type="hidden" name="idempotency_key" value="<?= ah_e($input['idempotency_key']??$newKey) ?>">
<div class="col-12 col-lg-6"><label class="form-label" for="v3-santri">Santri dalam cakupan</label><select class="form-select" id="v3-santri" name="santri_scope" required><option value="">Pilih santri</option><?php foreach($options['santri'] as $student): $scope=$student['santri_id'].'|'.$student['tahun_ajaran_id']; ?><option value="<?= ah_e($scope) ?>" <?= (string)($input['santri_scope']??'')===$scope?'selected':'' ?>><?= ah_e($student['nama'].' — '.$student['tahun'].' / '.$student['semester']) ?></option><?php endforeach ?></select></div>
<div class="col-12 col-lg-6"><label class="form-label" for="v3-katalog">Jenis pelanggaran</label><select class="form-select" id="v3-katalog" name="katalog_id" required><option value="">Pilih jenis</option><?php foreach($options['katalog'] as $catalog): ?><option value="<?= (int)$catalog['id'] ?>" <?= (string)($input['katalog_id']??'')===(string)$catalog['id']?'selected':'' ?>><?= ah_e($catalog['kode'].' — '.$catalog['nama'].' · '.$catalog['tingkat'].' · '.$catalog['poin_default'].' poin') ?></option><?php endforeach ?></select></div>
<div class="col-12 col-md-6"><label class="form-label" for="v3-waktu">Waktu kejadian</label><input class="form-control" type="datetime-local" id="v3-waktu" name="waktu_kejadian" value="<?= ah_e($input['waktu_kejadian']??date('Y-m-d\TH:i')) ?>" max="<?= ah_e(date('Y-m-d\TH:i')) ?>" required></div>
<div class="col-12 col-md-6"><label class="form-label" for="v3-tempat">Tempat (opsional)</label><input class="form-control" id="v3-tempat" name="tempat" maxlength="255" value="<?= ah_e($input['tempat']??'') ?>"></div>
<div class="col-12"><label class="form-label" for="v3-uraian">Uraian kejadian</label><textarea class="form-control" id="v3-uraian" name="uraian" maxlength="5000" required><?= ah_e($input['uraian']??'') ?></textarea></div>
<div class="col-12"><label class="form-label" for="v3-saksi">Saksi (opsional)</label><textarea class="form-control" id="v3-saksi" name="saksi" maxlength="2000"><?= ah_e($input['saksi']??'') ?></textarea></div>
<div class="col-12"><label class="form-label" for="v3-lampiran">Bukti privat (opsional)</label><input class="form-control" type="file" id="v3-lampiran" name="lampiran" accept="image/jpeg,image/png,application/pdf"><div class="form-text">JPG, PNG, atau PDF; maksimum 5 MB. Tidak dibagikan kepada orang tua.</div></div>
<div class="col-12"><button class="btn btn-primary" type="submit">Simpan catatan</button></div>
</form></div></section>
<?php endif ?>
<section class="ah-card"><div class="ah-card__body"><h2 class="h5">Daftar pelanggaran</h2>
<form method="get" class="row g-2 mb-3"><div class="col-12 col-md-4"><label class="form-label" for="filter-status">Status</label><select class="form-select" id="filter-status" name="status"><option value="">Semua</option><?php foreach(['Dicatat','Ditindaklanjuti','Selesai','Dibatalkan'] as $status): ?><option <?= ($_GET['status']??'')===$status?'selected':'' ?>><?= ah_e($status) ?></option><?php endforeach ?></select></div><div class="col-12 col-md-4 align-self-end"><button class="btn btn-outline-primary">Terapkan filter</button></div></form>
<?php if($list['rows']===[]): ?><?= ah_empty('Belum ada catatan','Belum ada pelanggaran yang dapat dibaca dalam cakupan aktif Anda.') ?><?php else: ?><div class="table-responsive"><table class="table"><caption class="visually-hidden">Daftar pelanggaran V3</caption><thead><tr><th>Santri</th><th>Waktu</th><th>Kategori</th><th>Poin</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($list['rows'] as $row): ?><tr><td><?= ah_e($row['santri_nama']) ?><small class="d-block text-muted"><?= ah_e($row['tahun'].' / '.$row['semester']) ?></small></td><td><?= ah_e($row['waktu_kejadian']) ?></td><td><?= ah_e($row['kategori'].' · '.$row['tingkat']) ?></td><td><?= (int)$row['poin'] ?></td><td><?= ah_e($row['status']) ?></td><td><a href="<?= ah_e(app_url('/portal/v3_pelanggaran_detail.php?id='.(int)$row['id'])) ?>">Detail</a></td></tr><?php endforeach ?></tbody></table></div><?php ah_pagination($list['total'],$list['page'],$list['per_page']); endif ?>
</div></section>
<?php ah_page_close(); ?>
