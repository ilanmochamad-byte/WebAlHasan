<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
use App\V4\{RaporService,RaporDocument,VerifikasiService,V4Exception};
use App\Http\Csrf;
header('Cache-Control: private, no-store');$user=authorization()->requireWebUser();header('Referrer-Policy: no-referrer');
$svc=new RaporService(app_db(),audit_logger());$options=[];$preview=null;$detail=null;$students=[];
$plan=(int)($_POST['rencana_id']??$_GET['rencana_id']??0);$sid=(int)($_POST['santri_id']??$_GET['santri_id']??0);$note=(string)($_POST['catatan_umum']??'');
try{
 $options=(new VerifikasiService(app_db(),audit_logger()))->options($user);
 if($plan)$students=(new VerifikasiService(app_db(),audit_logger()))->read($user,$plan)['matriks'];
 if($_SERVER['REQUEST_METHOD']==='POST'){
  Csrf::requireValid(is_string($_POST['_csrf']??null)?$_POST['_csrf']:null);
  if(isset($_POST['action'])){$result=$svc->act($user,$_POST);ah_flash_set('success','Operasi rapor tersimpan.');ah_redirect(app_url('/portal/rapor_semester.php').'?rencana_id='.$plan.'&santri_id='.$sid);}
 }
 if($plan&&$sid){$preview=$svc->preview($user,$plan,$sid,$note);if($preview['rapor_id'])$detail=$svc->show($user,$preview['rapor_id'],true);}
}catch(V4Exception $ex){http_response_code($ex->status);if($ex->status===403){\App\Ui\Denial::page('Penerbitan rapor','Bagian Pendidikan',$ex->getMessage());}$error=$ex->getMessage();}
ah_page_open(['title'=>'Penerbitan Rapor Semester','user'=>$user,'description'=>'Pratinjau, penerbitan dan riwayat versi tetap.']);$e=static fn($v)=>ah_e($v);
?>
<style>.rapor{overflow-wrap:anywhere}.rapor table{width:100%;border-collapse:collapse;table-layout:fixed}.rapor td,.rapor th{border:1px solid #cbd5d1;padding:.4rem;word-break:break-word}.rapor .sign td{text-align:center}.rapor img{max-width:100%;height:auto}.rapor textarea,.rapor select,.rapor input{max-width:100%;width:100%}.rapor h1{font-size:1.5rem}.rapor h2{font-size:1.2rem}.rapor-actions{display:flex;gap:.7rem;flex-wrap:wrap}</style>
<div class="rapor"><p><a href="<?= $e(app_url('/portal/verifikasi_semester.php')) ?>">Periksa kelengkapan bahan</a></p>
<?php if(isset($error)):?><div class="alert alert-danger" role="alert"><?= $e($error) ?></div><?php endif ?>
<form method="get"><label>Rencana kelas/semester<select name="rencana_id" class="form-control" required><?php foreach($options as $p):?><option value="<?= $p['id'] ?>" <?= $plan===$p['id']||$plan===(int)$p['id']?'selected':'' ?>><?= $e($p['nama_kelas'].' · '.$p['tahun'].' '.$p['semester']) ?></option><?php endforeach ?></select></label><button class="btn btn-primary mt-3">Muat kelas</button></form><?php if($plan):?><form method="post"><?= ah_csrf() ?><input type="hidden" name="rencana_id" value="<?= $plan ?>"><label>Santri<select name="santri_id" class="form-control"><option value="">Pilih setelah memuat kelas</option><?php foreach($students as $student):$s=$student['santri'];?><option value="<?= $s['id'] ?>" <?= $sid===(int)$s['id']?'selected':'' ?>><?= $e($s['nama_santri']) ?></option><?php endforeach ?></select></label><label>Catatan umum<textarea class="form-control" name="catatan_umum" maxlength="2000"><?= $e($note) ?></textarea></label><button class="btn btn-primary mt-3">Muat pratinjau</button></form><?php endif ?>
<?php if($preview):?><hr><p>Status: <?= $e($preview['status']) ?> · Versi saat ini <?= $preview['current_version'] ?></p><?= RaporDocument::body($preview['snapshot']) ?>
<form method="post"><?= ah_csrf() ?><?php foreach(['rencana_id'=>$plan,'santri_id'=>$sid,'catatan_umum'=>$note,'current_version'=>$preview['current_version'],'revision'=>$preview['revision'],'pratinjau_hash'=>$preview['pratinjau_hash'],'idempotency_key'=>bin2hex(random_bytes(24))] as $k=>$v):?><input type="hidden" name="<?= $e($k) ?>" value="<?= $e($v) ?>"><?php endforeach ?><label>Alasan tindakan (wajib untuk admin, penggantian, pembukaan, penarikan)<textarea class="form-control" name="alasan" maxlength="1000"></textarea></label><div class="rapor-actions mt-3">
<?php if($preview['siap_terbit']):?><button class="btn btn-success" name="action" value="<?= $preview['current_version']?'Ganti':'Terbit' ?>"><?= $preview['current_version']?'Terbitkan versi pengganti':'Terbitkan rapor' ?></button><?php endif ?>
<?php if($preview['status']==='Terbit'):?><button class="btn btn-outline-primary" name="action" value="Buka">Buka kembali untuk koreksi</button><button class="btn btn-outline-danger" name="action" value="Tarik">Tarik rapor</button><?php endif ?></div></form>
<?php endif ?>
<?php if($detail):?><h2 class="mt-4">Riwayat penerbitan</h2><?php foreach($detail['versi'] as $v):?><p>Versi <?= $v['version'] ?> · <?= $e($v['status']) ?> · <?= $e($v['diterbitkan_pada']) ?> · <a href="<?= $e(app_url('/portal/rapor_pdf.php').'?id='.$detail['id'].'&version='.$v['version'].'&petugas=1') ?>">Unduh PDF kanonis / cetak</a></p><?php endforeach ?><?php foreach($detail['riwayat'] as $h):?><p><?= $e($h['tindakan'].' · '.$h['kapasitas'].' #'.$h['actor_user_id'].' · '.$h['created_at'].' · '.$h['alasan']) ?></p><?php endforeach;endif ?></div>
<?php ah_page_close(); ?>
