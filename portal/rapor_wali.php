<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
use App\V4\{RaporService,RaporDocument,V4Exception};
header('Cache-Control: private, no-store');$user=authorization()->requireWebUser();header('Referrer-Policy: no-referrer');
$svc=new RaporService(app_db(),audit_logger());$id=(int)($_GET['id']??0);
try{$rows=$svc->listing($user);$detail=$id?$svc->show($user,$id):null;}catch(V4Exception $e){http_response_code($e->status);echo ah_e($e->getMessage());exit;}
ah_page_open(['title'=>'Rapor Semester','user'=>$user,'description'=>'Rapor yang telah diterbitkan untuk santri Anda.']);
?>
<style>.rapor{overflow-wrap:anywhere}.rapor table{width:100%;table-layout:fixed;border-collapse:collapse}.rapor td,.rapor th{border:1px solid #ccc;padding:.4rem;word-break:break-word}.rapor img{max-width:100%;height:auto}.rapor h1{font-size:1.5rem}.rapor h2{font-size:1.2rem}</style>
<div class="rapor"><?php if(!$rows):?><p>Belum ada rapor terbit.</p><?php endif ?><?php foreach($rows as $r):?><p><a href="<?= ah_e(app_url('/portal/rapor_wali.php').'?id='.$r['id']) ?>"><?= ah_e($r['nama_santri'].' · '.$r['tahun'].' '.$r['semester']) ?></a> · <?= ah_e($r['status']) ?></p><?php endforeach ?>
<?php if($detail):?><h2>Status: <?= ah_e($detail['status']) ?></h2><?php foreach($detail['versi'] as $v):?><p>Versi <?= $v['version'] ?> · <?= ah_e($v['status']) ?> · <?= ah_e($v['diterbitkan_pada']) ?></p><?php if(isset($v['snapshot'])):?><?= RaporDocument::body($v['snapshot']) ?><p><a class="btn btn-primary" href="<?= ah_e(app_url('/portal/rapor_pdf.php').'?id='.$id.'&version='.$v['version']) ?>">Unduh PDF rapor</a></p><?php endif;endforeach;endif ?></div>
<?php ah_page_close(); ?>
