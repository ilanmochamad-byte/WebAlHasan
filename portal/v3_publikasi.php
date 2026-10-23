<?php
declare(strict_types=1);
use App\Auth\Capabilities;
use App\Http\Csrf;
use App\Ui\Denial;
use App\V3\V3Exception;
/**
 * Informasi pembinaan untuk orang tua/wali (redesain website Tahap 5, V-7).
 *
 * Keputusan Z: judul "Informasi pembinaan · tanggal terbit", nama anak, dan
 * nama pembimbing pengirim (atau "Pesantren Al Hasan"). Jenis sumber
 * (pelanggaran/konseling) tidak ditampilkan. Satu-satunya data baru bagi
 * orang tua adalah nama pengirim dari PublikasiService; nama anak dibaca dari
 * daftar anak akun ini (relasi wali yang sama dengan Beranda dan Izin anak).
 */
require_once dirname(__DIR__).'/app/bootstrap.php';
$user=authorization()->requireWebUser();header('Cache-Control: private, no-store');$service=v3_publikasi_service();$id=(int)($_GET['id']??0);$error='';
$anak=[];
try{$anak=izin_service()->santriInScope($user,Capabilities::ORANG_TUA);}catch(Throwable){$anak=[];}
$namaAnak=[];foreach($anak as $s){$namaAnak[(int)$s['id']]=(string)$s['nama_santri'];}
$santriPilih=(int)($_GET['santri_id']??0);if(!isset($namaAnak[$santriPilih]))$santriPilih=0;
$belum=($_GET['baca']??'')==='belum';
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        Csrf::requireValid($_POST['_csrf']??null);if(($_POST['aksi']??'')!=='dibaca')throw new V3Exception('Aksi tidak valid.');
        $service->markRead($user,$id,$_POST);ah_flash_set('success','Terima kasih. Informasi ditandai sudah dibaca.');ah_redirect('/portal/v3_publikasi.php?id='.$id);
    }
    $detail=$id>0?$service->show($user,$id):null;
    $list=$id>0?null:$service->page($user,array_filter(['page'=>(string)max(1,(int)($_GET['page']??1)),'santri_id'=>$santriPilih>0?(string)$santriPilih:'','baca'=>$belum?'belum':''],static fn($v):bool=>$v!==''));
}catch(V3Exception $e){if($e->status===403)Denial::render('Informasi tidak dapat diakses.','Akses memerlukan relasi wali aktif.');http_response_code($e->status);$error=$e->getMessage();$detail=null;$list=null;}
$judulInfo=static fn(array $row):string=>'Informasi pembinaan · '.ah_tanggal((string)$row['diterbitkan_pada'],false);
$dari=static fn(array $row):string=>$row['pengirim']==='Pesantren Al Hasan'?'Pesantren Al Hasan':$row['pengirim'].' (pembimbing)';
$anakDari=static fn(array $row):string=>$namaAnak[(int)$row['santri_id']]??'Anak Anda';
ah_page_open(['title'=>$detail!==null?$judulInfo($detail['publikasi']):'Informasi pembinaan','heading'=>$detail!==null?$judulInfo($detail['publikasi']):'Informasi pembinaan','user'=>$user,
    'description'=>$detail!==null?$anakDari($detail['publikasi']):'Informasi dari pembimbing tentang pembinaan anak Anda.',
    'breadcrumbs'=>$detail!==null?[['label'=>'Beranda','url'=>app_url('/portal/index.php')],['label'=>'Informasi pembinaan','url'=>app_url('/portal/v3_publikasi.php')],['label'=>ah_tanggal((string)$detail['publikasi']['diterbitkan_pada'],false)]]:null]);
if($error!=='')ah_note('danger',$error);
if($detail!==null):$row=$detail['publikasi']; ?>
<section class="ah-card" aria-labelledby="ah-info">
    <div class="ah-card__head"><span id="ah-info"><?= ah_e($judulInfo($row)) ?></span><?= ah_status($row['status']==='Ditarik'?'Ditarik':($row['dibaca_pada']===null?'Belum dibaca':'Dibaca')) ?></div>
    <div class="ah-card__body">
        <?php $konten=$row;$kontenMeta=['Santri'=>$anakDari($row),'Dari'=>$dari($row)];require __DIR__.'/partials/v3_publikasi_konten.php'; ?>
        <?php if((int)$row['version']>1&&$row['status']==='Terbit'): ?><p class="small text-muted mt-3 mb-0">Informasi ini diperbarui pembimbing setelah pertama terbit.</p><?php endif ?>
        <?php if($row['status']==='Terbit'&&$row['dibaca_pada']===null): ?>
            <form method="post" class="mt-3"><?= ah_csrf() ?><input type="hidden" name="aksi" value="dibaca"><input type="hidden" name="version" value="<?= (int)$row['version'] ?>"><button class="btn btn-primary">Saya sudah membaca</button></form>
        <?php elseif($row['dibaca_pada']!==null): ?><p class="small text-muted mt-3 mb-0">Ditandai dibaca <?= ah_e(ah_waktu((string)$row['dibaca_pada'])) ?>.</p><?php endif ?>
    </div>
</section>
<p><a href="<?= ah_e(app_url('/portal/v3_publikasi.php')) ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i> Semua informasi</a></p>
<?php elseif($list!==null): ?>
<?php if(count($anak)>1){$chips=[['label'=>'Semua anak','url'=>'?'.ah_query(['santri_id'=>null,'page'=>null]),'active'=>$santriPilih===0]];foreach($anak as $s){$chips[]=['label'=>(string)$s['nama_santri'],'url'=>'?'.ah_query(['santri_id'=>(int)$s['id'],'page'=>null]),'active'=>$santriPilih===(int)$s['id']];}echo ah_chips($chips,'Pilih anak');} ?>
<section class="ah-card" aria-labelledby="ah-daftar-info">
    <div class="ah-card__head"><span id="ah-daftar-info"><?= $belum?'Belum dibaca':'Semua informasi' ?></span><span class="text-muted small"><?= (int)$list['total'] ?> informasi</span></div>
    <?php if($list['rows']===[]): ?><div class="ah-card__body"><?= ah_empty($belum?'Semua informasi sudah dibaca':'Belum ada informasi','Informasi akan muncul setelah pembimbing menerbitkannya.') ?></div>
    <?php else: ?><ul class="ah-qlist ah-pub-list"><?php foreach($list['rows'] as $row): $baru=$row['status']==='Terbit'&&$row['dibaca_pada']===null; ?>
        <li><a href="<?= ah_e(app_url('/portal/v3_publikasi.php?id='.(int)$row['id'])) ?>"<?= $baru?' class="is-unread"':'' ?>><span class="ah-qlist__main"><b><?= ah_e($judulInfo($row)) ?></b><small><?= ah_e($anakDari($row).' · dari '.$dari($row)) ?></small></span><?= ah_status($row['status']==='Ditarik'?'Ditarik':($baru?'Belum dibaca':'Dibaca')) ?></a></li>
    <?php endforeach ?></ul><?php endif ?>
</section>
<?php ah_pagination((int)$list['total'],(int)$list['page'],(int)$list['per_page']); ?>
<p class="small"><a href="<?= ah_e(app_url('/portal/v3_laporan.php')) ?>">Rekap informasi pembinaan (cetak atau unduh CSV)</a></p>
<?php endif;ah_page_close(); ?>
