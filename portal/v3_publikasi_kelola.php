<?php
declare(strict_types=1);
use App\Http\Csrf;
use App\Ui\Denial;
use App\V3\V3Exception;
require_once dirname(__DIR__).'/app/bootstrap.php';
$user=authorization()->requireWebUser();header('Cache-Control: private, no-store');$service=v3_publikasi_service();$caps=capabilities()->v3Capabilities($user);
if(array_intersect(['v3.koreksi','v3.konseling.kelola','v3.pelanggaran.kelola'],array_keys($caps))===[])Denial::render('Tidak memiliki akses publikasi.','Hak publikasi memerlukan penugasan aktif atau admin.');
$type=is_string($_GET['sumber_type']??null)?$_GET['sumber_type']:'kasus';$sourceId=(int)($_GET['sumber_id']??0);$id=(int)($_GET['id']??0);$error='';$preview=null;$options=null;$managed=null;
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        Csrf::requireValid($_POST['_csrf']??null);
        switch($_POST['aksi']??''){
            case 'pratinjau':if($id===0&&!isset($_POST['wali_ids']))$_POST['wali_ids']=[];$preview=$service->preview($user,$_POST);break;
            case 'terbit':$result=$service->publish($user,$_POST);ah_redirect('/portal/v3_publikasi_kelola.php?id='.(int)$result['data']['publikasi_ids'][0]);
            case 'tarik':$service->withdraw($user,$id,$_POST);ah_redirect('/portal/v3_publikasi_kelola.php?id='.$id);
            default:throw new V3Exception('Aksi tidak valid.');
        }
    }
    if($id>0){$managed=$service->manage($user,$id);$type=$managed['sumber_type'];$sourceId=$managed['sumber_id'];}
    if($sourceId>0&&($managed===null||$managed['publikasi']['status']==='Terbit'))$options=$service->options($user,$type,$sourceId);
}catch(V3Exception $e){if($e->status===403)Denial::render('Publikasi tidak dapat diakses.','Periksa cakupan penugasan aktif.');http_response_code($e->status);$error=$e->getMessage();}
ah_page_open(['title'=>'Publikasi orang tua','heading'=>'Publikasi orang tua','user'=>$user,'active'=>'v3.publikasi']);if($error!=='')ah_note('danger',$error);
if($sourceId===0&&$id===0): ?><section class="ah-card mb-3"><div class="ah-card__body"><p>Buka detail kasus konseling atau pelanggaran dalam cakupan Anda untuk menyiapkan publikasi bagi orang tua.</p><p><a href="<?= ah_e(app_url('/portal/v3_konseling.php')) ?>">Lihat kasus konseling</a> · <a href="<?= ah_e(app_url('/portal/v3_pelanggaran.php')) ?>">Lihat pelanggaran</a></p></div></section><?php endif;
if($managed!==null):$p=$managed['publikasi']; ?>
<section class="ah-card mb-3"><div class="ah-card__body"><h2 class="h5">Publikasi #<?= $id ?> · <?= ah_e($p['status']) ?> · versi <?= (int)$p['version'] ?></h2>
<?php $konten=$p;require __DIR__.'/partials/v3_publikasi_konten.php'; ?>
<p><?= $p['dibaca_pada']===null?'Belum dibaca':'Dibaca '.ah_e($p['dibaca_pada']) ?></p>
<?php if($p['status']==='Terbit'): ?><form method="post" class="d-grid gap-2"><?= ah_csrf() ?><input type="hidden" name="aksi" value="tarik"><input type="hidden" name="version" value="<?= (int)$p['version'] ?>"><input type="hidden" name="idempotency_key" value="<?= ah_e('withdraw-'.bin2hex(random_bytes(12))) ?>"><label>Alasan penarikan<textarea class="form-control" name="alasan" minlength="5" maxlength="1000" required></textarea></label><button class="btn btn-outline-danger">Tarik publikasi dengan alasan</button></form><?php endif ?>
<h3 class="h6 mt-3">Riwayat</h3><?php foreach($managed['riwayat'] as $history): ?><p><?= ah_e($history['tindakan'].' · versi '.$history['version'].' · '.$history['created_at'].' · '.($history['alasan']??'')) ?></p><?php endforeach ?></div></section>
<?php endif ?>
<?php if($preview!==null): ?>
<section class="ah-card mb-3"><div class="ah-card__body"><h2 class="h5">Pratinjau orang tua</h2><p>Periksa isi berikut. Isi ini disimpan persis saat Anda mengonfirmasi; waktu terbit dan status baca ditambahkan saat publikasi.</p>
<?php $konten=$preview['konten'];require __DIR__.'/partials/v3_publikasi_konten.php'; ?>
<p>Wali penerima: <?= ah_e(implode(', ',array_map(static fn($w)=>$w['nama'],array_filter($options['wali']??[],static fn($w)=>in_array($w['id'],$preview['wali_ids'],true))))) ?></p>
<form method="post" class="d-grid gap-3"><?= ah_csrf() ?><input type="hidden" name="aksi" value="terbit"><input type="hidden" name="pratinjau_token" value="<?= ah_e($preview['pratinjau_token']) ?>"><input type="hidden" name="idempotency_key" value="<?= ah_e('publish-'.bin2hex(random_bytes(12))) ?>"><label><input type="checkbox" name="konfirmasi" value="1" required> Saya sudah memeriksa isi dan penerima, dan menyetujui penerbitan ini.</label><button class="btn btn-primary">Konfirmasi dan terbitkan</button></form></div></section>
<?php elseif($options!==null&&($managed===null||$managed['publikasi']['status']==='Terbit')): ?>
<section class="ah-card"><div class="ah-card__body"><h2 class="h5"><?= $id>0?'Koreksi publikasi':'Siapkan publikasi' ?></h2><form method="post" class="d-grid gap-3"><?= ah_csrf() ?><input type="hidden" name="aksi" value="pratinjau"><input type="hidden" name="sumber_type" value="<?= ah_e($type) ?>"><input type="hidden" name="sumber_id" value="<?= $sourceId ?>"><input type="hidden" name="sumber_version" value="<?= (int)$options['sumber_version'] ?>">
<?php if($id>0): ?><input type="hidden" name="publikasi_id" value="<?= $id ?>"><input type="hidden" name="version" value="<?= (int)$managed['publikasi']['version'] ?>"><label>Alasan koreksi<textarea name="alasan" class="form-control" minlength="5" maxlength="1000" required></textarea></label><?php else: ?>
<fieldset><legend class="h6">Wali penerima</legend><?php foreach($options['wali'] as $wali): ?><label class="d-block"><input type="checkbox" name="wali_ids[]" value="<?= (int)$wali['id'] ?>" checked> <?= ah_e($wali['nama']) ?></label><?php endforeach ?></fieldset><label>Alasan bila memilih sebagian wali<textarea name="alasan_penerima" class="form-control" maxlength="1000"></textarea></label><?php endif ?>
<label>Ringkasan khusus orang tua<textarea name="ringkasan" class="form-control" maxlength="5000" required><?= ah_e($managed['publikasi']['ringkasan']??'') ?></textarea></label>
<label>Tindak lanjut khusus orang tua<textarea name="tindak_lanjut" class="form-control" maxlength="5000" required><?= ah_e($managed['publikasi']['tindak_lanjut']??'') ?></textarea></label><button class="btn btn-primary">Lihat pratinjau</button></form></div></section>
<?php endif ?>
<?php if($options!==null): ?><section class="ah-card mt-3"><div class="ah-card__body"><h2 class="h5">Publikasi sumber ini</h2><?php foreach($options['publikasi'] as $row):?><p><a href="<?= ah_e(app_url('/portal/v3_publikasi_kelola.php?id='.(int)$row['id'])) ?>">Publikasi #<?= (int)$row['id'] ?> · wali #<?= (int)$row['wali_id'] ?></a> · <?= $row['ditarik_pada']===null?'Terbit':'Ditarik' ?> · <?= $row['dibaca_pada']===null?'Belum dibaca':'Dibaca' ?></p><?php endforeach ?></div></section><?php endif;ah_page_close(); ?>
