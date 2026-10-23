<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once __DIR__.'/_rapor_ui.php';
use App\Auth\Capabilities;
use App\Penugasan\PenugasanJenis;
use App\V4\{BahanRepository,RaporService,RaporTampilan,VerifikasiService,V4Exception};
use App\Http\Csrf;

/*
 * R-6 Penerbitan rapor — redesain website Tahap 7, keputusan V.
 *
 * Daftar kelas dengan chip status, centang santri yang siap, lalu
 * "Terbitkan n rapor": dengan JavaScript satu santri per permintaan
 * (bilah kemajuan) sehingga pembuatan PDF tidak melewati batas waktu cPanel;
 * tanpa JavaScript paling banyak 10 santri per kiriman. Setiap penerbitan
 * tetap `RaporService::act()` dengan sidik pratinjau yang dihitung saat
 * daftar dibuka, kunci idempotensi per santri, transaksi SERIALIZABLE, dan
 * audit. Catatan umum per santri diisi di panel pratinjau dan disimpan
 * sebagai draf sesi (ikut dalam sidik pratinjau). Draf tetap ada sesudah
 * terbit, sehingga pengulangan dengan kunci yang sama menghasilkan
 * permintaan identik dan versi pengganti berikutnya terisi catatan yang sama.
 */
header('Cache-Control: private, no-store');$user=authorization()->requireWebUser();header('Referrer-Policy: no-referrer');
$svc=new RaporService(app_db(),audit_logger());$verif=new VerifikasiService(app_db(),audit_logger());$tampil=new RaporTampilan(app_db());
$minta=(int)($_POST['rencana_id']??$_GET['rencana_id']??0);$sid=(int)($_POST['santri_id']??$_GET['santri_id']??0);
$tampilan=in_array($_GET['tampil']??'',['siap','terbit','belum'],true)?(string)$_GET['tampil']:'';
$json=ah_wants_json();$options=[];$matriks=[];$plan=0;$cocok=true;$preview=null;$detail=null;$rencanaRow=null;
$caps=capabilities();
$adminTerbit=$caps->featureSource($user,PenugasanJenis::CAP_RAPOR_FINALISASI)!==Capabilities::SUMBER_PENUGASAN;
$catatanSesi=static fn(int $p,int $s):string=>is_string($_SESSION['ah_rapor_catatan'][$p][$s]??null)?$_SESSION['ah_rapor_catatan'][$p][$s]:'';
/** @return array{0:int,1:string,2:int,3:int,4:string}|null "santri|sidik|versi|revisi|kunci" */
$urai=static fn(mixed $p):?array=>is_string($p)&&preg_match('/^(\d+)\|([a-f0-9]{64})\|(\d+)\|(\d+)\|([A-Za-z0-9_-]{16,100})$/D',$p,$m)?[(int)$m[1],$m[2],(int)$m[3],(int)$m[4],$m[5]]:null;
$kembali=static fn(array $q=[]):string=>app_url('/portal/rapor_semester.php').'?'.http_build_query(array_filter($q+['rencana_id'=>$minta],static fn($v)=>$v!==null&&$v!==''&&$v!==0));
try{
 $options=$verif->options($user);
 $pilih=rapor_pilih_rencana($options,$minta,(int)($_GET['tahun_ajaran_id']??0),(int)($_GET['kelas_id']??0));
 $plan=$pilih['id'];$cocok=$pilih['cocok'];
 if($_SERVER['REQUEST_METHOD']==='POST'){
  Csrf::requireValid(is_string($_POST['_csrf']??null)?$_POST['_csrf']:null);
  $aksi=(string)($_POST['aksi']??'');
  if($aksi==='catatan'||(!isset($_POST['action'])&&$aksi===''&&isset($_POST['catatan_umum'])&&$sid>0)){
   // Draf catatan umum per santri (sesi). Dibaca ulang lewat pratinjau agar cakupan tetap diperiksa.
   $teks=is_string($_POST['catatan_umum']??null)?$_POST['catatan_umum']:'';
   if(mb_strlen($teks)>2000)throw new V4Exception('Catatan umum maksimal 2000 karakter.');
   $svc->preview($user,$minta,$sid,$teks);
   $_SESSION['ah_rapor_catatan'][$minta][$sid]=$teks;
   ah_flash_set('success','Catatan umum tersimpan untuk pratinjau dan penerbitan berikutnya (belum terbit).');
   ah_redirect($kembali(['santri_id'=>$sid]).'#ah-panel');
  }
  if($aksi==='terbit_massal'){
   $pilihan=$_POST['pilih']??[];$pilihan=is_string($pilihan)?[$pilihan]:(is_array($pilihan)?$pilihan:[]);
   $alasan=is_string($_POST['alasan']??null)?$_POST['alasan']:'';
   $ok=0;$gagal=[];$sisa=0;
   foreach(array_values($pilihan) as $i=>$p){
    // Tanpa JavaScript dibatasi 10 per kiriman agar pembuatan PDF tidak melewati batas waktu.
    if(!$json&&$i>=10){$sisa++;continue;}
    $u=$urai($p);
    try{
     if($u===null)throw new V4Exception('Pilihan tidak valid.');
     [$s,$hash,$ver,$rev,$key]=$u;
     $svc->act($user,['action'=>$ver>0?'Ganti':'Terbit','rencana_id'=>$minta,'santri_id'=>$s,'current_version'=>$ver,'revision'=>$rev,'pratinjau_hash'=>$hash,'catatan_umum'=>$catatanSesi($minta,$s),'alasan'=>$alasan,'idempotency_key'=>$key]);
     $ok++;
    }catch(V4Exception $ex){
     if($json)ah_json(['ok'=>false,'message'=>$ex->getMessage()],$ex->status);
     $gagal[]=$ex->getMessage();
    }
   }
   if($json)ah_json(['ok'=>true,'message'=>'Rapor terbit.']);
   $pesan=$ok.' rapor terbit · '.count($gagal).' gagal'.($sisa?' · '.$sisa.' belum diproses (tanpa JavaScript paling banyak 10 per kiriman; kirim lagi)':'').'.';
   ah_flash_set($gagal||$sisa?'warning':'success',$pesan,$gagal?'<ul class="mb-0 small">'.implode('',array_map(static fn($g)=>'<li>'.ah_e($g).'</li>',array_unique($gagal))).'</ul>':null);
   ah_redirect($kembali());
  }
  if($aksi==='periksa'){
   $result=$verif->act($user,['action'=>'periksa_finalisasi','rencana_id'=>$minta,'santri_id'=>$sid,'matriks_hash'=>(string)($_POST['matriks_hash']??''),'alasan'=>(string)($_POST['alasan']??'')]);
   ah_flash_set('success','Pemeriksaan kelayakan: bahan lengkap dan terverifikasi; rapor siap terbit.');
   ah_redirect($kembali(['santri_id'=>$sid]).'#ah-panel');
  }
  // Bentuk lama/panel: satu tindakan rapor (Terbit, Ganti, Buka, Tarik).
  if(isset($_POST['action'])){
   if(!isset($_POST['catatan_umum'])||!is_string($_POST['catatan_umum']))$_POST['catatan_umum']=$catatanSesi($minta,$sid);
   $svc->act($user,$_POST);
   if($json)ah_json(['ok'=>true,'message'=>'Operasi rapor tersimpan.']);
   ah_flash_set('success',match($_POST['action']){'Terbit'=>'Rapor terbit; orang tua mendapat pemberitahuan.','Ganti'=>'Versi pengganti terbit; versi sebelumnya ditandai diganti.','Tarik'=>'Rapor ditarik; tautan verifikasi versi itu kini berstatus ditarik.','Buka'=>'Rapor dibuka untuk koreksi.',default=>'Operasi rapor tersimpan.'});
   ah_redirect($kembali(['santri_id'=>$sid]).'#ah-panel');
  }
  // Bentuk lama "Muat pratinjau" (POST tanpa tindakan): buka panel santri itu.
  ah_redirect($kembali(['santri_id'=>$sid]).'#ah-panel');
 }
 if($plan){
  $baca=$verif->read($user,$plan);$matriks=$baca['matriks'];$rencanaRow=$baca['rencana'];
  if($sid){$preview=$svc->preview($user,$plan,$sid,$catatanSesi($plan,$sid));if($preview['rapor_id'])$detail=$svc->show($user,$preview['rapor_id'],true);}
 }
}catch(V4Exception $ex){
 if($json)ah_json(['ok'=>false,'message'=>$ex->getMessage()],$ex->status);
 http_response_code($ex->status);if($ex->status===403){\App\Ui\Denial::page('Penerbitan rapor','Bagian Pendidikan',$ex->getMessage());}$error=$ex->getMessage();
 if($plan){try{$baca=$verif->read($user,$plan);$matriks=$baca['matriks'];$rencanaRow=$baca['rencana'];if($sid){$preview=$svc->preview($user,$plan,$sid,$catatanSesi($plan,$sid));if($preview['rapor_id'])$detail=$svc->show($user,$preview['rapor_id'],true);}}catch(V4Exception){}}
}
$e=static fn($v)=>ah_e($v);
// ---- Daftar kelas ---------------------------------------------------------------------
$ids=array_map(static fn($st)=>(int)$st['santri']['id'],$matriks);
$rapor=$rencanaRow?$tampil->raporKelas((int)$rencanaRow['tahun_ajaran_id'],$ids):[];
$baris=[];$hitung=['semua'=>0,'siap'=>0,'terbit'=>0,'belum'=>0];
foreach($matriks as $st){
 $s=(int)$st['santri']['id'];$r=$rapor[$s]??null;$terbit=($r['status']??'')==='Terbit';
 $kelompok=$terbit?'terbit':($st['siap_finalisasi']?'siap':'belum');
 $hitung['semua']++;$hitung[$kelompok]++;
 $baris[]=['st'=>$st,'rapor'=>$r,'kelompok'=>$kelompok];
}
$tampilBaris=array_values(array_filter($baris,static fn($b)=>$tampilan===''||$b['kelompok']===$tampilan));
// Sidik pratinjau untuk santri yang dapat diterbitkan, dihitung saat daftar dibuka.
$siap=[];
foreach($baris as $b){
 if(!$b['st']['siap_finalisasi'])continue;$s=(int)$b['st']['santri']['id'];
 if(($b['rapor']['status']??'')==='Terbit')continue;
 try{$p=$svc->preview($user,$plan,$s,$catatanSesi($plan,$s));}catch(V4Exception){continue;}
 if(!$p['siap_terbit'])continue;
 $siap[$s]=$s.'|'.$p['pratinjau_hash'].'|'.$p['current_version'].'|'.$p['revision'].'|'.bin2hex(random_bytes(16));
}
$adaPengganti=false;foreach($siap as $s=>$v)if((int)($rapor[$s]['current_version']??0)>0)$adaPengganti=true;
$opsiMenu=static function(array $b)use($plan,$e):array{
 $s=(int)$b['st']['santri']['id'];$r=$b['rapor'];$items=[['label'=>'Pratinjau','url'=>app_url('/portal/rapor_semester.php').'?rencana_id='.$plan.'&santri_id='.$s.'#ah-panel','icon'=>'fa-eye']];
 if($r&&(int)$r['current_version']>0)$items[]=['label'=>'Lihat PDF','url'=>app_url('/portal/rapor_pdf.php').'?id='.(int)$r['id'].'&version='.(int)$r['current_version'].'&petugas=1','icon'=>'fa-file-pdf'];
 if($r&&(int)$r['current_version']>0&&$b['st']['siap_finalisasi'])$items[]=['label'=>'Terbitkan versi pengganti','url'=>app_url('/portal/rapor_semester.php').'?rencana_id='.$plan.'&santri_id='.$s.'#ah-panel','icon'=>'fa-rotate'];
 if($r&&$r['status']==='Terbit'){
  $f=['rencana_id'=>$plan,'santri_id'=>$s,'current_version'=>(int)$r['current_version'],'revision'=>(int)$r['revision'],'idempotency_key'=>bin2hex(random_bytes(16))];
  $items[]=['label'=>'Buka untuk koreksi','action'=>app_url('/portal/rapor_semester.php'),'fields'=>$f+['action'=>'Buka'],'icon'=>'fa-lock-open',
   'confirm'=>['title'=>'Buka rapor '.$b['st']['santri']['nama_santri'].' untuk koreksi?','text'=>'Rapor yang terbit ditarik dari orang tua sampai versi pengganti diterbitkan. Bahan dapat dikoreksi kembali.','ok'=>'Buka untuk koreksi','reason'=>'alasan','reason_label'=>'Alasan']];
  $items[]=['label'=>'Tarik rapor','action'=>app_url('/portal/rapor_semester.php'),'fields'=>$f+['action'=>'Tarik'],'icon'=>'fa-ban','danger'=>true,
   'confirm'=>['title'=>'Tarik rapor '.$b['st']['santri']['nama_santri'].'?','text'=>'Orang tua tidak lagi dapat mengunduh versi ini dan kode verifikasinya berstatus ditarik. Riwayat tetap tersimpan.','ok'=>'Tarik rapor','reason'=>'alasan','reason_label'=>'Alasan penarikan']];
 }
 return $items;
};
$statusRapor=static fn(?array $r):string=>$r===null||(int)$r['current_version']===0?ah_badge('Belum terbit','muted'):rapor_status_badge((string)$r['status']);
$chip=static fn(string $k,string $label)=>['label'=>$label,'url'=>app_url('/portal/rapor_semester.php').'?rencana_id='.$plan.($k?'&tampil='.$k:''),'active'=>$tampilan===$k,'count'=>$hitung[$k?:'semua']];
[$opsiSemester,$opsiKelas]=rapor_konteks_opsi($options);
// Tindakan utama: terbitkan yang siap (dialog alasan; satu santri per permintaan).
ob_start();
if($siap!==[]){
 ah_action_dialog_open('terbit-siap','Terbitkan yang siap ('.count($siap).')','Terbitkan '.count($siap).' rapor','primary');
 echo '<form method="post" id="terbit-siap-form" action="'.$e(app_url('/portal/rapor_semester.php')).'" data-rapor-massal="'.$e('Menerbitkan '.count($siap).' rapor').'">'.ah_csrf()
  .'<input type="hidden" name="aksi" value="terbit_massal"><input type="hidden" name="rencana_id" value="'.$plan.'">';
 foreach($siap as $s=>$v){$nm='';foreach($matriks as $st)if((int)$st['santri']['id']===$s)$nm=(string)$st['santri']['nama_santri'];echo '<input type="hidden" name="pilih[]" value="'.$e($v).'" data-nama="'.$e($nm).'">';}
 echo '<p class="small">Setiap rapor dibuat dan diterbitkan satu per satu (PDF, kode verifikasi, pemberitahuan orang tua). Yang gagal didaftar dan dapat diulang.</p>'
  .'<label class="form-label" for="alasan-terbit-semua">Alasan'.(($adminTerbit||$adaPengganti)?' <span class="text-danger">*</span>':' <span class="small text-muted">(opsional)</span>').'</label>'
  .'<textarea class="form-control" id="alasan-terbit-semua" name="alasan" maxlength="1000"'.(($adminTerbit||$adaPengganti)?' required':'').'></textarea>'
  .(($adminTerbit||$adaPengganti)?'<p class="small text-muted mt-1">Wajib karena '.($adaPengganti?'ada versi pengganti':'tindakan admin').'.</p>':'')
  .'<div class="ah-act__actions"><button class="btn btn-primary">Terbitkan '.count($siap).' rapor</button></div></form>';
 ah_action_dialog_close();
}
$utama=(string)ob_get_clean();
ah_page_open(['title'=>'Penerbitan','heading'=>'Penilaian & rapor','user'=>$user,'active'=>'rapor','actions'=>$utama,'wide'=>true,
 'description'=>$rencanaRow?($rencanaRow['nama_kelas'].' · '.rapor_semester($rencanaRow).' · '.$hitung['terbit'].' terbit dari '.$hitung['semua'].' santri'):'Pratinjau, penerbitan, dan riwayat versi rapor.']);
if($options!==[])echo rapor_konteks_bar($opsiSemester,(int)($rencanaRow['tahun_ajaran_id']??rapor_konteks()['tahun']),$opsiKelas,(int)($rencanaRow['kelas_id']??rapor_konteks()['kelas']));
?>
<?php if(isset($error)):?><div class="ah-note ah-note--danger" role="alert"><i class="fas fa-circle-exclamation mt-1" aria-hidden="true"></i><div><strong class="ah-note__title">Tindakan rapor belum tersimpan.</strong><span><?= $e($error) ?></span></div></div><?php endif ?>
<?php if(!$options):?><section class="ah-card"><div class="ah-card__body"><?= ah_empty('Belum ada rencana','Belum ada rencana kelas dalam cakupan Anda.') ?></div></section><?php endif ?>
<?php if(!$cocok):?><?php ah_note('warning','Kelas ini belum punya rencana nilai pada semester tersebut.') ?><?php endif ?>
<?php if($rencanaRow): ?>
<?= ah_chips([$chip('','Semua'),$chip('siap','Siap terbit'),$chip('terbit','Terbit'),$chip('belum','Belum lengkap')],'Saring status rapor') ?>
<?php if($tampilBaris===[]):?>
 <section class="ah-card"><div class="ah-card__body"><?= ah_empty('Tidak ada santri','Tidak ada santri pada saringan ini.') ?></div></section>
<?php else: ?>
<div data-ah-selection="santri" id="pilihan-rapor">
<div class="ah-table-wrap"><table class="ah-table ah-table--cards ah-rapor-table">
 <caption class="ah-visually-hidden">Status rapor per santri</caption>
 <thead><tr><th scope="col" class="ah-col-check"><input class="form-check-input" type="checkbox" data-ah-select-all aria-label="Pilih semua yang siap terbit"<?= $siap?'':' disabled' ?>></th><th scope="col">Santri</th><th scope="col">Kelengkapan</th><th scope="col">Rapor</th><th scope="col" class="ah-col-opt">Versi</th><th scope="col" class="ah-col-opt">Terbit</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
 <tbody>
 <?php foreach($tampilBaris as $b): $st=$b['st'];$s=(int)$st['santri']['id'];$r=$b['rapor'];$ver=count(array_filter($st['bahan'],static fn($m)=>$m['pemeriksaan']['status']==='Terverifikasi')); ?>
  <tr<?= $sid===$s?' class="is-current"':'' ?>>
   <td class="ah-col-check" data-label=""><?php if(isset($siap[$s])):?><input class="form-check-input" type="checkbox" name="pilih[]" form="terbit-pilih-form" value="<?= $e($siap[$s]) ?>" data-ah-select data-nama="<?= $e($st['santri']['nama_santri']) ?>" aria-label="Pilih <?= $e($st['santri']['nama_santri']) ?>"><?php endif ?></td>
   <td data-label="Santri"><a href="<?= $e($kembali(['santri_id'=>$s,'tampil'=>$tampilan])) ?>#ah-panel"><?= $e($st['santri']['nama_santri']) ?></a></td>
   <td data-label="Kelengkapan"><?= $st['siap_finalisasi']?ah_badge('Lengkap','ok'):'<span class="small">'.$ver.' dari '.count($st['bahan']).' bahan terverifikasi</span>' ?></td>
   <td data-label="Rapor"><?= $statusRapor($r) ?><?= isset($siap[$s])?' '.ah_badge('Siap terbit','info'):'' ?></td>
   <td data-label="Versi" class="ah-col-opt"><?= $r&&(int)$r['current_version']>0?(int)$r['current_version']:'—' ?></td>
   <td data-label="Terbit" class="ah-col-opt"><?= $e(($r['diterbitkan_pada']??null)?ah_tanggal(substr((string)$r['diterbitkan_pada'],0,10),false):'—') ?></td>
   <td data-label="" class="text-end"><?= ah_menu($opsiMenu($b),'Tindakan untuk '.$st['santri']['nama_santri']) ?></td>
  </tr>
 <?php endforeach ?>
 </tbody></table></div>
<?php if($siap!==[]): ob_start(); ?>
 <a class="btn btn-sm btn-outline-secondary ah-js-only" href="<?= $e($kembali()) ?>" data-rapor-pratinjau-pilihan="<?= $e($kembali()) ?>">Pratinjau</a>
 <?php ah_action_dialog_open('terbit-pilih','Terbitkan yang dipilih','Terbitkan rapor santri terpilih','primary btn-sm') ?>
  <form method="post" id="terbit-pilih-form" action="<?= $e(app_url('/portal/rapor_semester.php')) ?>" data-rapor-massal="Menerbitkan rapor terpilih" data-rapor-pilihan="#pilihan-rapor"><?= ah_csrf() ?>
   <input type="hidden" name="aksi" value="terbit_massal"><input type="hidden" name="rencana_id" value="<?= $plan ?>">
   <label class="form-label" for="alasan-terbit-pilih">Alasan<?= ($adminTerbit||$adaPengganti)?' <span class="small text-muted">(wajib bila tindakan admin atau versi pengganti)</span>':' <span class="small text-muted">(opsional)</span>' ?></label>
   <textarea class="form-control" id="alasan-terbit-pilih" name="alasan" maxlength="1000"<?= $adminTerbit?' required':'' ?>></textarea>
   <div class="ah-act__actions"><button class="btn btn-primary">Terbitkan</button></div></form>
 <?php ah_action_dialog_close() ?>
<?= ah_selection_bar((string)ob_get_clean()) ?>
<?php endif ?>
</div>
<div id="rapor-bulk" class="ah-no-print"></div>
<?php endif ?>
<?php
// ---- Panel pratinjau satu santri -----------------------------------------------------------------
if($preview):
 $urut=array_map(static fn($b)=>(int)$b['st']['santri']['id'],$baris);$pos=array_search($sid,$urut,true);
 $sebelum=$pos!==false&&$pos>0?$urut[$pos-1]:null;$sesudah=$pos!==false&&$pos<count($urut)-1?$urut[$pos+1]:null;
 $stSantri=null;foreach($matriks as $st)if((int)$st['santri']['id']===$sid)$stSantri=$st;
 $snap=$preview['snapshot'];
 ah_panel_open(['title'=>'Rapor '.($snap['santri']['nama_santri']??''),'description'=>$rencanaRow['nama_kelas'].' · '.rapor_semester($rencanaRow).' · '.($preview['current_version']?'versi '.$preview['current_version'].' · '.rapor_status_label($preview['status']):'belum terbit'),'close_url'=>$kembali(['tampil'=>$tampilan])]); ?>
 <nav class="ah-panel-nav" aria-label="Santri sebelumnya dan berikutnya">
  <?php if($sebelum):?><a class="btn btn-sm btn-outline-secondary" href="<?= $e($kembali(['santri_id'=>$sebelum,'tampil'=>$tampilan])) ?>#ah-panel"><i class="fas fa-chevron-left" aria-hidden="true"></i> Sebelumnya</a><?php endif ?>
  <span class="small text-muted"><?= $pos===false?'':($pos+1).' dari '.count($urut) ?></span>
  <?php if($sesudah):?><a class="btn btn-sm btn-outline-secondary" href="<?= $e($kembali(['santri_id'=>$sesudah,'tampil'=>$tampilan])) ?>#ah-panel">Berikutnya <i class="fas fa-chevron-right" aria-hidden="true"></i></a><?php endif ?>
 </nav>
 <?php if(!$preview['siap_terbit']):?><?php ah_note('warning','Belum siap terbit.','<ul class="small mb-0 mt-1">'.implode('',array_map(static fn($k)=>'<li>'.ah_e($k).'</li>',$snap['kekurangan'])).'</ul>') ?><?php endif ?>
 <form method="post" class="mb-3"><?= ah_csrf() ?><input type="hidden" name="aksi" value="catatan"><input type="hidden" name="rencana_id" value="<?= $plan ?>"><input type="hidden" name="santri_id" value="<?= $sid ?>">
  <label class="form-label" for="catatan-umum">Catatan umum</label>
  <textarea class="form-control" id="catatan-umum" name="catatan_umum" maxlength="2000" rows="2"><?= $e($catatanSesi($plan,$sid)) ?></textarea>
  <button class="btn btn-sm btn-outline-primary mt-2">Simpan catatan</button> <span class="small text-muted">Dipakai pada penerbitan rapor ini berikutnya (selama sesi masuk).</span></form>
 <?php $tetap='<input type="hidden" name="rencana_id" value="'.$plan.'"><input type="hidden" name="santri_id" value="'.$sid.'"><input type="hidden" name="current_version" value="'.(int)$preview['current_version'].'"><input type="hidden" name="revision" value="'.(int)$preview['revision'].'"><input type="hidden" name="pratinjau_hash" value="'.$e($preview['pratinjau_hash']).'"><input type="hidden" name="catatan_umum" value="'.$e($catatanSesi($plan,$sid)).'"><input type="hidden" name="idempotency_key" value="'.bin2hex(random_bytes(24)).'">'; ?>
 <div class="ah-act__actions">
 <?php if($preview['siap_terbit']):$ganti=$preview['current_version']>0;$wajib=$ganti||$adminTerbit; ?>
  <?php ah_action_dialog_open('terbit-satu',$ganti?'Terbitkan versi pengganti':'Terbitkan rapor',($ganti?'Terbitkan versi pengganti ':'Terbitkan rapor ').($snap['santri']['nama_santri']??''),'primary') ?>
   <form method="post"><?= ah_csrf() ?><?= $tetap ?><input type="hidden" name="action" value="<?= $ganti?'Ganti':'Terbit' ?>">
    <p class="small"><?= $ganti?'Versi '.(int)$preview['current_version'].' akan ditandai diganti; orang tua melihat versi baru dengan catatan "menggantikan".':'Rapor dibuat sebagai PDF tetap, diberi kode verifikasi, dan orang tua mendapat pemberitahuan.' ?></p>
    <label class="form-label" for="alasan-terbit">Alasan<?= $wajib?' <span class="text-danger">*</span>':' <span class="small text-muted">(opsional)</span>' ?></label>
    <textarea class="form-control" id="alasan-terbit" name="alasan" maxlength="1000"<?= $wajib?' required':'' ?>></textarea>
    <div class="ah-act__actions"><button class="btn btn-primary"><?= $ganti?'Terbitkan versi pengganti':'Terbitkan' ?></button></div></form>
  <?php ah_action_dialog_close() ?>
 <?php endif ?>
 <?php if($preview['status']==='Terbit'): ?>
  <?php ah_action_dialog_open('buka-koreksi','Buka untuk koreksi','Buka rapor untuk koreksi','outline-primary') ?>
   <form method="post"><?= ah_csrf() ?><?= $tetap ?><input type="hidden" name="action" value="Buka"><p class="small">Rapor ditarik dari orang tua sampai versi pengganti diterbitkan.</p>
    <label class="form-label" for="alasan-buka">Alasan <span class="text-danger">*</span></label><textarea class="form-control" id="alasan-buka" name="alasan" maxlength="1000" required></textarea>
    <div class="ah-act__actions"><button class="btn btn-primary">Buka untuk koreksi</button></div></form>
  <?php ah_action_dialog_close() ?>
  <?php ah_action_dialog_open('tarik-rapor','Tarik rapor','Tarik rapor','outline-danger') ?>
   <form method="post"><?= ah_csrf() ?><?= $tetap ?><input type="hidden" name="action" value="Tarik"><p class="small">Orang tua tidak lagi dapat mengunduh versi ini; kode verifikasinya berstatus ditarik.</p>
    <label class="form-label" for="alasan-tarik">Alasan penarikan <span class="text-danger">*</span></label><textarea class="form-control" id="alasan-tarik" name="alasan" maxlength="1000" required></textarea>
    <div class="ah-act__actions"><button class="btn btn-danger">Tarik rapor</button></div></form>
  <?php ah_action_dialog_close() ?>
 <?php endif ?>
 <?php if($adminTerbit&&$stSantri): ?>
  <?php ah_action_dialog_open('periksa-layak','Periksa kelayakan (admin)','Periksa kelayakan finalisasi','outline-secondary') ?>
   <form method="post"><?= ah_csrf() ?><input type="hidden" name="aksi" value="periksa"><input type="hidden" name="rencana_id" value="<?= $plan ?>"><input type="hidden" name="santri_id" value="<?= $sid ?>"><input type="hidden" name="matriks_hash" value="<?= $e(BahanRepository::fingerprint($stSantri)) ?>">
    <p class="small">Memeriksa ulang di server bahwa seluruh bahan lengkap dan terverifikasi. Tidak menerbitkan rapor.</p>
    <label class="form-label" for="alasan-periksa">Alasan pemeriksaan <span class="text-danger">*</span></label><textarea class="form-control" id="alasan-periksa" name="alasan" maxlength="1000" required></textarea>
    <div class="ah-act__actions"><button class="btn btn-primary">Periksa</button></div></form>
  <?php ah_action_dialog_close() ?>
 <?php endif ?>
 </div>
 <article class="ah-rapor-doc" aria-label="Pratinjau rapor"><?= rapor_dokumen($snap) ?></article>
 <?php if($detail): $orang=$tampil->namaPengguna(array_column($detail['riwayat'],'actor_user_id')); ?>
  <h3 class="h6 mt-4">Versi</h3>
  <ul class="ah-bahan-list"><?php foreach($detail['versi'] as $v):?><li><b>Versi <?= (int)$v['version'] ?></b> <?= rapor_status_badge($v['status']==='Diganti'?'Sudah diganti':$v['status']) ?> <span class="small text-muted"><?= $e(rapor_waktu($v['diterbitkan_pada'])) ?></span> · <a href="<?= $e(app_url('/portal/rapor_pdf.php').'?id='.$detail['id'].'&version='.$v['version'].'&petugas=1') ?>">Unduh PDF</a></li><?php endforeach ?></ul>
  <h3 class="h6 mt-3">Riwayat</h3>
  <?= ah_timeline(array_map(static fn($h)=>['waktu'=>rapor_waktu($h['created_at']),'pelaku'=>($orang[(int)$h['actor_user_id']]??'Pengguna').' ('.rapor_kapasitas($h['kapasitas']).')',
   'judul'=>match($h['tindakan']){'Terbit'=>'Terbit','Ganti'=>'Versi pengganti terbit','Tarik'=>'Ditarik','Buka'=>'Dibuka untuk koreksi',default=>(string)$h['tindakan']}.' · versi '.(int)$h['version'],'isi'=>trim((string)$h['alasan'])!==''?'Alasan: '.$h['alasan']:''],$detail['riwayat']),'Riwayat penerbitan') ?>
 <?php endif ?>
<?php ah_panel_close(); endif ?>
<?php endif ?>
<script src="<?= $e(\App\Ui\Layout::asset('/assets/ui/rapor.js')) ?>" defer></script>
<?php ah_page_close(); ?>
