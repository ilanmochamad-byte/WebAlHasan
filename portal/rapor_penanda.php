<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
use App\V4\{RencanaService,V4Exception};
use App\Http\Csrf;
header('Cache-Control: private, no-store');$user=authorization()->requireWebUser();$svc=new RencanaService(app_db(),capabilities(),audit_logger());$plan=null;
try{
 $svc->signers($user);$options=$svc->options($user);
 $id=(int)($_POST['rencana_id']??$_GET['rencana_id']??0);
 if($_SERVER['REQUEST_METHOD']==='POST'){Csrf::requireValid(is_string($_POST['_csrf']??null)?$_POST['_csrf']:null);$svc->changeSigners($user,$_POST);ah_flash_set('success','Penanda tangan dan tempat diperbarui dengan riwayat.');ah_redirect(app_url('/portal/rapor_penanda.php').'?rencana_id='.$id);}
 $plans=(new App\V4\VerifikasiService(app_db(),audit_logger()))->options($user);
 if($id){$repo=new App\V4\BahanRepository(app_db(),audit_logger());$plan=$repo->query('SELECT * FROM v4_rencana WHERE id=?','i',[$id])[0]??null;$history=$repo->query('SELECT version,actor_user_id,alasan,created_at FROM v4_rencana_penanda_riwayat WHERE rencana_id=? ORDER BY id DESC','i',[$id]);}
}catch(V4Exception $e){http_response_code($e->status);if($e->status===403){echo '403 — Akses ditolak.';exit;}$error=$e->getMessage();}
ah_page_open(['title'=>'Perubahan Penanda Tangan Rapor','user'=>$user,'description'=>'Tindakan khusus admin beralasan; berlaku pada penerbitan versi berikutnya.']);
?>
<?php if(isset($error)):?><p role="alert"><?= ah_e($error) ?></p><?php endif ?>
<form method="get"><label>Rencana<select class="form-control" name="rencana_id"><?php foreach($plans??[] as $p):?><option value="<?= $p['id'] ?>" <?= $id===(int)$p['id']?'selected':'' ?>><?= ah_e($p['nama_kelas'].' · '.$p['tahun'].' '.$p['semester']) ?></option><?php endforeach ?></select></label><button class="btn btn-primary mt-3">Pilih</button></form>
<?php if($plan):?><form method="post"><?= ah_csrf() ?><input type="hidden" name="rencana_id" value="<?= $id ?>"><input type="hidden" name="version" value="<?= $plan['version'] ?>">
<?php foreach(['pendidikan'=>'Pendidikan','pimpinan'=>'Pimpinan'] as $key=>$job):?><label class="d-block mt-3">Identitas <?= $job ?><select class="form-control" name="<?= $key ?>_penanda_id"><?php foreach($options['penanda_tangan'] as $p):if($p['jabatan']!==$job)continue;?><option value="<?= $p['id'] ?>" <?= $plan[$key.'_penanda_id']===$p['id']?'selected':'' ?>><?= ah_e($p['nama'].' '.$p['gelar']) ?></option><?php endforeach ?></select></label><label class="d-block mt-3">Aset <?= $job ?><select class="form-control" name="<?= $key ?>_aset_id"><?php foreach($options['aset'] as $a):if($a['jabatan']!==$job)continue;?><option value="<?= $a['id'] ?>" <?= $plan[$key.'_aset_id']===$a['id']?'selected':'' ?>><?= ah_e($a['nama']) ?> · aset <?= $a['id'] ?></option><?php endforeach ?></select></label><?php endforeach ?><label class="d-block mt-3">Tempat<input class="form-control" name="tempat" value="<?= ah_e($plan['tempat']) ?>" maxlength="120" required></label><label class="d-block mt-3">Alasan<textarea class="form-control" name="alasan" maxlength="1000" required></textarea></label><button class="btn btn-primary mt-3">Simpan perubahan beralasan</button></form><h2 class="mt-3">Riwayat perubahan</h2><?php foreach($history as $h):?><p><?= ah_e('Versi '.$h['version'].' · '.$h['created_at'].' · Admin #'.$h['actor_user_id'].' · '.$h['alasan']) ?></p><?php endforeach;endif;ah_page_close(); ?>
