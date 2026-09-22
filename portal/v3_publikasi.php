<?php
declare(strict_types=1);
use App\Http\Csrf;
use App\Ui\Denial;
use App\V3\V3Exception;
require_once dirname(__DIR__).'/app/bootstrap.php';
$user=authorization()->requireWebUser();header('Cache-Control: private, no-store');$service=v3_publikasi_service();$id=(int)($_GET['id']??0);$error='';
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        Csrf::requireValid($_POST['_csrf']??null);if(($_POST['aksi']??'')!=='dibaca')throw new V3Exception('Aksi tidak valid.');
        $service->markRead($user,$id,$_POST);ah_redirect('/portal/v3_publikasi.php?id='.$id);
    }
    $detail=$id>0?$service->show($user,$id):null;$list=$id>0?null:$service->page($user,$_GET);
}catch(V3Exception $e){if($e->status===403)Denial::render('Publikasi tidak dapat diakses.','Akses memerlukan relasi wali aktif.');http_response_code($e->status);$error=$e->getMessage();$detail=null;$list=null;}
ah_page_open(['title'=>'Informasi pembinaan','heading'=>'Informasi pembinaan','user'=>$user,'active'=>'v3.publikasi']);
if($error!=='')ah_note('danger',$error);
if($detail!==null):$row=$detail['publikasi']; ?>
<section class="ah-card"><div class="ah-card__body"><h2 class="h5"><?= ah_e($row['status']) ?> · versi <?= (int)$row['version'] ?></h2>
<?php $konten=$row;require __DIR__.'/partials/v3_publikasi_konten.php'; ?>
<p class="text-muted">Diterbitkan <?= ah_e($row['diterbitkan_pada']) ?> · <?= $row['dibaca_pada']===null?'Belum ditandai dibaca':'Dibaca '.ah_e($row['dibaca_pada']) ?></p>
<form method="post"><?= ah_csrf() ?><input type="hidden" name="aksi" value="dibaca"><input type="hidden" name="version" value="<?= (int)$row['version'] ?>"><button class="btn btn-primary">Tandai dibaca</button></form>
<h3 class="h6 mt-4">Riwayat informasi</h3><ul><?php foreach($detail['riwayat'] as $history):?><li><?= ah_e($history['tindakan'].' · versi '.$history['version'].' · '.$history['created_at']) ?></li><?php endforeach ?></ul>
<a href="<?= ah_e(app_url('/portal/v3_publikasi.php')) ?>">Daftar informasi</a></div></section>
<?php elseif($list!==null): ?>
<?php if($list['rows']===[])ah_empty('Belum ada informasi','Informasi akan muncul setelah pembimbing menerbitkannya.'); ?>
<?php foreach($list['rows'] as $row): ?><section class="ah-card mb-3"><div class="ah-card__body"><h2 class="h5">Santri #<?= (int)$row['santri_id'] ?></h2><p><?= ah_e($row['status'].' · '.$row['diterbitkan_pada']) ?></p><p><?= ah_e($row['ringkasan']) ?></p><a href="<?= ah_e(app_url('/portal/v3_publikasi.php?id='.(int)$row['id'])) ?>">Baca informasi<?= $row['dibaca_pada']===null?' · belum dibaca':'' ?></a></div></section><?php endforeach ?>
<?php ah_pagination($list['total'],$list['page'],$list['per_page']);endif;ah_page_close(); ?>
