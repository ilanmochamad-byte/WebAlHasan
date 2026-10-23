<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once __DIR__.'/_rapor_ui.php';
use App\V4\{RaporService,RaporTampilan,V4Exception};

/*
 * R-8 Rapor anak (orang tua/wali) — redesain website Tahap 7, keputusan Y.
 *
 * Chip per anak, daftar rapor per semester sebagai kartu berlabel status,
 * lalu HANYA versi terbaru dengan catatan "menggantikan versi n". Versi lama
 * dibuka lewat "Versi sebelumnya" dan diberi tanda "Sudah diganti"; isinya
 * tetap tidak ditampilkan karena `RaporService::show()` hanya memberi isi
 * versi yang masih terbit kepada orang tua (aturan hak akses tidak berubah).
 * Data yang tampil tetap proyeksi tanpa data internal staf.
 */
header('Cache-Control: private, no-store');$user=authorization()->requireWebUser();header('Referrer-Policy: no-referrer');
$svc=new RaporService(app_db(),audit_logger());$tampil=new RaporTampilan(app_db());
$id=(int)($_GET['id']??0);$versiMinta=(int)($_GET['versi']??0);$anakMinta=(int)($_GET['anak']??0);
try{$rows=$svc->listing($user);$detail=$id?$svc->show($user,$id):null;}
catch(V4Exception $e){http_response_code($e->status);\App\Ui\Denial::page('Rapor','orang tua atau wali santri yang terhubung',$e->getMessage(),$e->status);}
$e=static fn($v)=>ah_e($v);
$anak=$tampil->anakWali((int)$user['id']);
$pemilik=$tampil->santriRapor(array_column($rows,'id'));
if($id&&isset($pemilik[$id]))$anakMinta=$pemilik[$id];
$anakIds=array_column($anak,'id');
$aktif=in_array($anakMinta,$anakIds,true)?$anakMinta:(int)($anakIds[0]??0);
$namaAnak='';foreach($anak as $a)if($a['id']===$aktif)$namaAnak=$a['nama_santri'];
$daftar=array_values(array_filter($rows,static fn($r)=>($pemilik[(int)$r['id']]??0)===$aktif));
if($id===0&&$daftar!==[])$id=(int)$daftar[0]['id'];
if($id&&$detail===null&&in_array($id,array_map('intval',array_column($daftar,'id')),true)){try{$detail=$svc->show($user,$id);}catch(V4Exception){$detail=null;}}
$terbaru=static function(array $d):?array{foreach($d['versi'] as $v)if((int)$v['version']===(int)$d['current_version'])return $v;return null;};
ah_page_open(['title'=>$namaAnak!==''?'Rapor '.$namaAnak:'Rapor','user'=>$user,'active'=>'rapor_wali',
 'description'=>'Rapor semester yang sudah diterbitkan pesantren untuk anak Anda.']);
?>
<?php if(count($anak)>1): ?>
<?= ah_chips(array_map(static fn($a)=>['label'=>$a['nama_santri'],'url'=>app_url('/portal/rapor_wali.php').'?anak='.$a['id'],'active'=>$a['id']===$aktif],$anak),'Pilih anak') ?>
<?php endif ?>
<?php if($anak===[]): ?>
 <section class="ah-card"><div class="ah-card__body"><?= ah_empty('Belum ada anak terhubung','Akun Anda belum terhubung dengan data santri. Hubungi admin pesantren.') ?></div></section>
<?php elseif($daftar===[]): ?>
 <section class="ah-card"><div class="ah-card__body"><?= ah_empty('Belum ada rapor terbit','Rapor '.$namaAnak.' akan tampil di sini setelah diterbitkan pesantren. Anda juga mendapat pemberitahuan.') ?></div></section>
<?php else: ?>
<ul class="ah-rapor-list" aria-label="Rapor <?= $e($namaAnak) ?> per semester">
 <?php foreach($daftar as $r): $d=(int)$r['id']===(int)($detail['id']??0)?$detail:null; try{$d??=$svc->show($user,(int)$r['id']);}catch(V4Exception){$d=null;} $v=$d?$terbaru($d):null; ?>
 <li><a href="<?= $e(app_url('/portal/rapor_wali.php').'?id='.(int)$r['id']) ?>"<?= (int)$r['id']===$id?' aria-current="page"':'' ?>>
  <b>Semester <?= $e(rapor_semester($r)) ?></b>
  <span class="small text-muted"><?= $r['status']==='Terbit'?'Terbit '.$e(rapor_waktu($v['diterbitkan_pada']??null)).' · versi '.(int)$r['current_version']:'Sedang diperbaiki pesantren' ?></span>
  <?= $r['status']==='Terbit'?ah_badge('Terbit','ok'):ah_badge($r['status']==='Ditarik'?'Ditarik':'Sedang diperbaiki','warn') ?></a></li>
 <?php endforeach ?>
</ul>
<?php if($detail): $v=$terbaru($detail);$lama=array_values(array_filter($detail['versi'],static fn($x)=>(int)$x['version']<(int)$detail['current_version']));
 $judulSemester='';foreach($daftar as $r)if((int)$r['id']===(int)$detail['id'])$judulSemester=rapor_semester($r); ?>
 <?php if($versiMinta>0&&$versiMinta<(int)$detail['current_version']): $x=null;foreach($detail['versi'] as $w)if((int)$w['version']===$versiMinta)$x=$w; ?>
  <section class="ah-card ah-rapor-card" aria-labelledby="ah-versi-lama">
   <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-versi-lama"><?= $e($judulSemester) ?> · versi <?= $versiMinta ?></h2><?= ah_badge('Sudah diganti','muted') ?></div>
   <div class="ah-card__body">
    <p>Versi <?= $versiMinta ?> terbit <?= $e(rapor_waktu($x['diterbitkan_pada']??null)) ?> dan sudah diganti oleh versi <?= (int)$detail['current_version'] ?>.</p>
    <p class="small text-muted">Isi dan berkas versi yang sudah diganti tidak lagi dibagikan. Gunakan versi terbaru.</p>
    <a class="btn btn-outline-primary" href="<?= $e(app_url('/portal/rapor_wali.php').'?id='.(int)$detail['id']) ?>">Lihat versi terbaru</a>
   </div></section>
 <?php elseif($detail['status']==='Terbit'&&$v&&isset($v['snapshot'])): ?>
  <section class="ah-card ah-rapor-card" aria-labelledby="ah-rapor-terbaru">
   <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-rapor-terbaru"><?= $e($judulSemester) ?> · versi <?= (int)$v['version'] ?></h2>
    <a class="btn btn-primary btn-sm" href="<?= $e(app_url('/portal/rapor_pdf.php').'?id='.(int)$detail['id'].'&version='.(int)$v['version']) ?>"><i class="fas fa-download" aria-hidden="true"></i> Unduh PDF</a></div>
   <div class="ah-card__body">
    <?php if($lama!==[]): $sebelum=$lama[0]; ?><?php ah_note('info','Versi '.(int)$v['version'].' terbit '.rapor_waktu($v['diterbitkan_pada']).', menggantikan versi '.(int)$sebelum['version'].' (terbit '.rapor_waktu($sebelum['diterbitkan_pada']).').') ?><?php endif ?>
    <article class="ah-rapor-doc" aria-label="Isi rapor"><?= rapor_dokumen($v['snapshot']) ?></article>
    <?php if($lama!==[]): ?><p class="mt-3 mb-0"><a href="<?= $e(app_url('/portal/rapor_wali.php').'?id='.(int)$detail['id'].'&versi='.(int)$lama[0]['version']) ?>">Versi sebelumnya (<?= (int)$lama[0]['version'] ?>) ›</a></p><?php endif ?>
   </div></section>
 <?php else: ?>
  <section class="ah-card"><div class="ah-card__body"><?= ah_empty('Rapor sedang diperbaiki','Rapor '.$judulSemester.' ditarik sementara oleh pesantren. Versi pengganti akan tampil di sini setelah diterbitkan.') ?></div></section>
 <?php endif ?>
<?php endif ?>
<?php endif ?>
<?php ah_page_close(); ?>
