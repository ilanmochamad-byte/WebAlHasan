<?php
declare(strict_types=1);
use App\Http\Csrf;
use App\Ui\Denial;
use App\V3\V3Exception;
/**
 * Pembinaan santri › Publikasi ke orang tua (redesain website Tahap 5, V-5).
 *
 * Tanpa parameter: daftar seluruh publikasi dalam cakupan (satu baris per wali
 * penerima; status Terbit atau Ditarik, plus tanda dibaca). Dengan sumber:
 * wizard Penerima → Isi → Pratinjau. Pratinjau menampilkan isi persis seperti
 * dilihat wali dan berlaku 30 menit; bila habis, "Buat pratinjau ulang" memakai
 * isian yang sama. Dengan ?id: kelola satu publikasi (koreksi, tarik beralasan).
 * Hak, cakupan, kerahasiaan, dan idempotensi tetap milik PublikasiService.
 */
require_once dirname(__DIR__).'/app/bootstrap.php';
$user=authorization()->requireWebUser();header('Cache-Control: private, no-store');$service=v3_publikasi_service();$caps=capabilities()->v3Capabilities($user);
if(array_intersect(['v3.koreksi','v3.konseling.kelola','v3.pelanggaran.kelola'],array_keys($caps))===[])Denial::render('Tidak memiliki akses publikasi.','Hak publikasi memerlukan penugasan pembimbing aktif atau admin.');
$type=is_string($_GET['sumber_type']??null)?$_GET['sumber_type']:'kasus';$sourceId=(int)($_GET['sumber_id']??0);$id=(int)($_GET['id']??0);$error='';$errorAksi='';$preview=null;$options=null;$managed=null;$list=null;
$isi=($_SERVER['REQUEST_METHOD']??'GET')==='POST'?$_POST:[];
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        Csrf::requireValid($_POST['_csrf']??null);$errorAksi=(string)($_POST['aksi']??'');
        if(isset($_POST['sumber_type'])&&is_string($_POST['sumber_type']))$type=$_POST['sumber_type'];
        if(isset($_POST['sumber_id']))$sourceId=(int)$_POST['sumber_id'];
        switch($_POST['aksi']??''){
            case 'pratinjau':if($id===0&&!isset($_POST['wali_ids']))$_POST['wali_ids']=[];$preview=$service->preview($user,$_POST);break;
            case 'ubah':break; // Kembali dari pratinjau ke formulir dengan isian yang sama; tidak ada mutasi.
            case 'terbit':$result=$service->publish($user,$_POST);ah_flash_set('success','Publikasi terbit. Wali menerima pemberitahuan di aplikasi.');ah_redirect('/portal/v3_publikasi_kelola.php?id='.(int)$result['data']['publikasi_ids'][0]);
            case 'tarik':$service->withdraw($user,$id,$_POST);ah_flash_set('success','Publikasi ditarik. Wali melihat keterangan bahwa informasi telah ditarik.');ah_redirect('/portal/v3_publikasi_kelola.php?id='.$id);
            default:throw new V3Exception('Aksi tidak valid.');
        }
    }
}catch(V3Exception $e){if($e->status===403)Denial::render('Publikasi tidak dapat diakses.','Periksa cakupan penugasan aktif.');http_response_code($e->status);$error=$e->getMessage();}
try{
    if($id>0){$managed=$service->manage($user,$id);$type=$managed['sumber_type'];$sourceId=$managed['sumber_id'];}
    if($sourceId>0&&($managed===null||$managed['publikasi']['status']==='Terbit'))$options=$service->options($user,$type,$sourceId);
    if($id===0&&$sourceId===0){
        $status=in_array($_GET['status']??'',['Terbit','Ditarik'],true)?(string)$_GET['status']:'';$q=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,100):'';
        $list=$service->managePage($user,array_filter(['status'=>$status,'q'=>$q,'page'=>(string)max(1,(int)($_GET['page']??1))],static fn($v):bool=>$v!==''));
    }
}catch(V3Exception $e){if($e->status===403)Denial::render('Publikasi tidak dapat diakses.','Periksa cakupan penugasan aktif.');http_response_code($e->status);$error=$error!==''?$error:$e->getMessage();}

$sumberLabel=static fn(string $t):string=>['pelanggaran'=>'Pelanggaran','kasus'=>'Kasus konseling','sesi'=>'Sesi konseling'][$t]??'Sumber';
$sumberUrl=static fn(string $t,int $sid,?int $kasusId=null):?string=>match($t){'pelanggaran'=>app_url('/portal/v3_pelanggaran_detail.php?id='.$sid),'kasus'=>app_url('/portal/v3_konseling_detail.php?id='.$sid),'sesi'=>$kasusId!==null?app_url('/portal/v3_konseling_detail.php?id='.$kasusId.'#sesi-'.$sid):null,default=>null};
$waliNama=[];foreach(($options['wali']??[]) as $w){$waliNama[(int)$w['id']]=$w['nama'];}
$judul=$list!==null?'Publikasi ke orang tua':($managed!==null?'Informasi pembinaan · '.$managed['santri_nama'].' · '.ah_tanggal($managed['publikasi']['diterbitkan_pada'],false):'Siapkan publikasi'.($options!==null?' · '.$options['santri_nama']:''));
ah_page_open(['title'=>$judul,'heading'=>$list!==null?'Pembinaan santri':$judul,'user'=>$user,
    'description'=>$list!==null?'Informasi yang diterbitkan untuk wali santri. Satu baris per wali penerima.':($options!==null?$sumberLabel($type).' · penerima hanya wali aktif santri ini.':''),
    'breadcrumbs'=>[['label'=>'Pembinaan','url'=>app_url('/portal/v3_pelanggaran.php')],['label'=>'Publikasi ke orang tua','url'=>app_url('/portal/v3_publikasi_kelola.php')],['label'=>$list!==null?'Daftar':$judul]]]);
if($error!=='')ah_note('danger',$error);

if($list!==null):
    $chips=[['label'=>'Semua','url'=>'?'.ah_query(['status'=>null,'page'=>null]),'active'=>$status===''],['label'=>'Terbit','url'=>'?'.ah_query(['status'=>'Terbit','page'=>null]),'active'=>$status==='Terbit'],['label'=>'Ditarik','url'=>'?'.ah_query(['status'=>'Ditarik','page'=>null]),'active'=>$status==='Ditarik']];
    echo ah_chips($chips,'Saring menurut status');
    ah_filter_open((int)($q!==''),['status'=>$status]); ?>
    <div class="ah-filter__wide"><label class="form-label" for="pub-q">Cari santri</label><input class="form-control" type="search" id="pub-q" name="q" maxlength="100" value="<?= ah_e($q) ?>" placeholder="Nama santri"></div>
<?php ah_filter_close(['status'=>$status]); ?>
    <section class="ah-card" aria-labelledby="ah-daftar-publikasi">
        <div class="ah-card__head"><span id="ah-daftar-publikasi">Daftar publikasi</span><span class="text-muted small"><?= count($list['rows']) ?> dari <?= (int)$list['total'] ?></span></div>
        <?php if($list['rows']===[]): ?><div class="ah-card__body"><?= ah_empty('Belum ada publikasi','Siapkan publikasi dari detail pelanggaran atau kasus konseling Internal dalam cakupan Anda.','<a class="btn btn-sm btn-outline-primary" href="'.ah_e(app_url('/portal/v3_pelanggaran.php')).'">Buka pelanggaran</a> <a class="btn btn-sm btn-outline-primary" href="'.ah_e(app_url('/portal/v3_konseling.php')).'">Buka konseling</a>') ?></div>
        <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Publikasi ke orang tua dalam cakupan Anda</caption>
            <thead><tr><th scope="col">Santri</th><th scope="col">Penerima</th><th scope="col">Sumber</th><th scope="col">Status</th><th scope="col" class="ah-col-secondary">Dibaca</th></tr></thead><tbody>
            <?php foreach($list['rows'] as $row): ?><tr>
                <td><span class="ah-cell-wrap"><a class="ah-row-link" href="<?= ah_e(app_url('/portal/v3_publikasi_kelola.php?id='.(int)$row['id'])) ?>"><?= ah_e($row['santri_nama']) ?></a><span class="ah-cell-sub">Terbit <?= ah_e(ah_tanggal($row['diterbitkan_pada'],false)) ?></span></span></td>
                <td><?= ah_e($row['wali_nama']) ?></td>
                <td><span class="ah-cell-wrap"><?php $urlSumber=$sumberUrl($row['sumber_type'],$row['sumber_id'],$row['sumber_kasus_id']); ?><?php if($urlSumber!==null): ?><a href="<?= ah_e($urlSumber) ?>"><?= ah_e($sumberLabel($row['sumber_type'])) ?></a><?php else: ?><?= ah_e($sumberLabel($row['sumber_type'])) ?><?php endif ?><span class="ah-cell-sub"><?= ah_e(trim(($row['sumber_kategori']??'').' '.ah_tanggal($row['sumber_waktu'],false))) ?></span></span></td>
                <td><?= ah_status($row['status']) ?></td>
                <td class="ah-col-secondary"><?= $row['dibaca_pada']===null?ah_status('Belum dibaca'):ah_e(ah_waktu((string)$row['dibaca_pada'])) ?></td>
            </tr><?php endforeach ?>
            </tbody></table></div>
        <?php endif ?>
    </section>
    <?php ah_pagination((int)$list['total'],(int)$list['page'],(int)$list['per_page']);
    ah_page_close();exit;
endif;

if($managed!==null):$p=$managed['publikasi']; ?>
<div class="ah-split">
    <div class="ah-split__col">
        <section class="ah-card" aria-labelledby="ah-isi-publikasi">
            <div class="ah-card__head"><span id="ah-isi-publikasi">Isi yang dilihat wali</span><?= ah_status($p['status']) ?></div>
            <div class="ah-card__body"><?php $konten=$p;$kontenMeta=['Santri'=>$managed['santri_nama'],'Penerima'=>$managed['wali_nama'],'Dibaca'=>$p['dibaca_pada']===null?'Belum dibaca':ah_waktu((string)$p['dibaca_pada'])];require __DIR__.'/partials/v3_publikasi_konten.php'; ?></div>
        </section>
        <section class="ah-card" aria-labelledby="ah-riwayat-publikasi">
            <div class="ah-card__head"><span id="ah-riwayat-publikasi">Riwayat</span></div>
            <div class="ah-card__body"><?= ah_timeline(array_map(static fn(array $h):array=>['waktu'=>ah_waktu((string)$h['created_at']),'pelaku'=>'versi '.(int)$h['version'],'judul'=>['Terbit'=>'Diterbitkan','Koreksi'=>'Dikoreksi','Tarik'=>'Ditarik'][$h['tindakan']]??(string)$h['tindakan'],'isi'=>(string)($h['alasan']??''),'tone'=>$h['tindakan']==='Tarik'?'danger':''],$managed['riwayat']),'Riwayat publikasi') ?></div>
        </section>
    </div>
    <div class="ah-split__col">
        <section class="ah-card ah-no-print" aria-labelledby="ah-tindakan-publikasi">
            <div class="ah-card__head"><span id="ah-tindakan-publikasi">Tindakan</span></div>
            <div class="ah-card__body"><div class="ah-acts">
                <?php if(($u=$sumberUrl($type,$sourceId,$managed['sumber_kasus_id']??$options['sumber_kasus_id']??null))!==null): ?><a class="btn btn-outline-secondary" href="<?= ah_e($u) ?>">Buka sumber (<?= ah_e(mb_strtolower($sumberLabel($type))) ?>)</a><?php endif ?>
                <?php if($p['status']==='Terbit'): ?>
                    <?php ah_action_dialog_open('tarik-publikasi','Tarik publikasi','Tarik publikasi ini?','outline-danger',$errorAksi==='tarik'&&$error!==''); ?>
                        <form method="post"><?= ah_csrf() ?><input type="hidden" name="aksi" value="tarik"><input type="hidden" name="version" value="<?= (int)$p['version'] ?>"><input type="hidden" name="idempotency_key" value="<?= ah_e('withdraw-'.bin2hex(random_bytes(12))) ?>">
                            <p class="small">Wali tidak lagi melihat isi informasi ini; yang tampil hanya keterangan bahwa informasi telah ditarik.</p>
                            <label class="form-label" for="alasan-tarik">Alasan penarikan <span class="text-danger">*</span></label><textarea class="form-control" id="alasan-tarik" name="alasan" rows="2" minlength="5" maxlength="1000" required></textarea>
                            <div class="ah-act__actions"><button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Kembali</button><button class="btn btn-danger" type="submit">Tarik publikasi</button></div>
                        </form>
                    <?php ah_action_dialog_close(); ?>
                <?php else: ?><p class="small text-muted mb-0">Publikasi sudah ditarik dan tidak dapat diubah.</p><?php endif ?>
            </div></div>
        </section>
    </div>
</div>
<?php endif;

if($preview!==null):
    $berlakuSampai=date('H.i',time()+60*(int)$preview['berlaku_menit']); ?>
<section class="ah-card" aria-labelledby="ah-pratinjau">
    <div class="ah-card__head"><span id="ah-pratinjau">Pratinjau · persis seperti dilihat wali</span></div>
    <div class="ah-card__body">
        <?= ah_stepper(['Penerima','Isi','Pratinjau'],2,'Langkah publikasi') ?>
        <?php ah_note('info','Pratinjau berlaku 30 menit (sampai '.$berlakuSampai.'). Bila habis, tekan "Buat pratinjau ulang".'); ?>
        <div class="ah-card mb-3"><div class="ah-card__head"><span>Informasi pembinaan · <?= ah_e(ah_tanggal(date('Y-m-d'),false)) ?></span></div><div class="ah-card__body">
            <?php $konten=$preview['konten'];$kontenMeta=['Santri'=>$options['santri_nama']??'','Dari'=>($options['pengirim']??'Pesantren Al Hasan').($options!==null&&($options['pengirim']??'Pesantren Al Hasan')!=='Pesantren Al Hasan'?' (pembimbing)':'')];require __DIR__.'/partials/v3_publikasi_konten.php'; ?>
        </div></div>
        <p class="mb-3"><b>Penerima:</b> <?= ah_e(implode(', ',array_map(static fn($w)=>$w['nama'],array_filter($options['wali']??[],static fn($w)=>in_array($w['id'],$preview['wali_ids'],true))))) ?></p>
        <form method="post" class="d-grid gap-3"><?= ah_csrf() ?><input type="hidden" name="aksi" value="terbit"><input type="hidden" name="pratinjau_token" value="<?= ah_e($preview['pratinjau_token']) ?>"><input type="hidden" name="idempotency_key" value="<?= ah_e('publish-'.bin2hex(random_bytes(12))) ?>">
            <?php // Salinan isian agar "Buat pratinjau ulang" setelah kedaluwarsa tidak mengetik ulang. ?>
            <?php foreach(['sumber_type','sumber_id','sumber_version','ringkasan','tindak_lanjut','alasan_penerima','alasan','publikasi_id','version'] as $f): if(isset($_POST[$f])&&is_scalar($_POST[$f])): ?><input type="hidden" name="<?= ah_e($f) ?>" value="<?= ah_e((string)$_POST[$f]) ?>"><?php endif; endforeach ?>
            <?php foreach((array)($_POST['wali_ids']??[]) as $w): ?><input type="hidden" name="wali_ids[]" value="<?= (int)$w ?>"><?php endforeach ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="konfirmasi" name="konfirmasi" value="1" required><label class="form-check-label" for="konfirmasi">Saya sudah memeriksa isi dan penerima, dan menyetujui penerbitan ini.</label></div>
            <div class="d-flex flex-wrap gap-2 ah-sticky-sm"><button class="btn btn-outline-secondary" type="submit" name="aksi" value="ubah" formnovalidate>Kembali ubah isi</button><button class="btn btn-primary" type="submit">Terbitkan</button></div>
        </form>
    </div>
</section>
<?php elseif($options!==null&&($managed===null||$managed['publikasi']['status']==='Terbit')):
    $koreksi=$id>0;$pratinjauUlang=$errorAksi==='terbit'; ?>
<section class="ah-card" aria-labelledby="ah-siapkan">
    <div class="ah-card__head"><span id="ah-siapkan"><?= $koreksi?'Koreksi publikasi':'Siapkan publikasi · '.ah_e($options['santri_nama']) ?></span></div>
    <div class="ah-card__body">
        <?= ah_stepper(['Penerima','Isi','Pratinjau'],$koreksi?1:0,'Langkah publikasi') ?>
        <?php if($pratinjauUlang): ?><?php ah_note('warning','Pratinjau tidak dapat diterbitkan. Periksa isian lalu tekan "Buat pratinjau ulang".'); ?><?php endif ?>
        <form method="post" class="row g-3"><?= ah_csrf() ?><input type="hidden" name="aksi" value="pratinjau"><input type="hidden" name="sumber_type" value="<?= ah_e($type) ?>"><input type="hidden" name="sumber_id" value="<?= $sourceId ?>"><input type="hidden" name="sumber_version" value="<?= (int)$options['sumber_version'] ?>">
            <?php if($koreksi): ?>
                <input type="hidden" name="publikasi_id" value="<?= $id ?>"><input type="hidden" name="version" value="<?= (int)$managed['publikasi']['version'] ?>">
                <div class="col-12"><label class="form-label" for="alasan-koreksi">Alasan koreksi <span class="text-danger">*</span></label><textarea class="form-control" id="alasan-koreksi" name="alasan" rows="2" minlength="5" maxlength="1000" required><?= ah_e($isi['alasan']??'') ?></textarea></div>
            <?php else: $waliDipilih=isset($isi['wali_ids'])?array_map('intval',(array)$isi['wali_ids']):array_column($options['wali'],'id'); ?>
                <fieldset class="col-12"><legend class="form-label">1 · Wali penerima</legend>
                    <?php if($options['wali']===[]): ?><p class="text-muted small mb-0">Santri ini belum memiliki wali aktif yang dapat menerima informasi.</p><?php else: ?>
                    <div class="ah-check-list"><?php foreach($options['wali'] as $wali): ?><label><input class="form-check-input" type="checkbox" name="wali_ids[]" value="<?= (int)$wali['id'] ?>"<?= in_array((int)$wali['id'],$waliDipilih,true)?' checked':'' ?>><span><?= ah_e($wali['nama']) ?></span></label><?php endforeach ?></div><?php endif ?>
                </fieldset>
                <div class="col-12"><label class="form-label" for="alasan-penerima">Alasan bila memilih sebagian wali</label><textarea class="form-control" id="alasan-penerima" name="alasan_penerima" rows="2" maxlength="1000"><?= ah_e($isi['alasan_penerima']??'') ?></textarea></div>
            <?php endif ?>
            <div class="col-12"><label class="form-label" for="ringkasan"><?= $koreksi?'':'2 · ' ?>Ringkasan untuk orang tua <span class="text-danger">*</span></label><textarea class="form-control" id="ringkasan" name="ringkasan" rows="4" maxlength="5000" required placeholder="Bahasa yang mudah dipahami, tanpa detail rahasia"><?= ah_e($isi['ringkasan']??($managed['publikasi']['ringkasan']??'')) ?></textarea></div>
            <div class="col-12"><label class="form-label" for="tindak-lanjut">Tindak lanjut di rumah <span class="text-danger">*</span></label><textarea class="form-control" id="tindak-lanjut" name="tindak_lanjut" rows="3" maxlength="5000" required><?= ah_e($isi['tindak_lanjut']??($managed['publikasi']['tindak_lanjut']??'')) ?></textarea></div>
            <p class="col-12 small text-muted mb-0">Wali melihat judul "Informasi pembinaan · tanggal", nama anak, dan nama pembimbing pengirim (<?= ah_e($options['pengirim']) ?>). Jenis sumber dan catatan internal tidak ditampilkan.</p>
            <div class="col-12 d-flex flex-wrap gap-2 ah-sticky-sm"><?php if(($u=$sumberUrl($type,$sourceId,$managed['sumber_kasus_id']??$options['sumber_kasus_id']??null))!==null): ?><a class="btn btn-outline-secondary" href="<?= ah_e($u) ?>">Kembali ke sumber</a><?php endif ?><button class="btn btn-primary" type="submit"><?= $pratinjauUlang?'Buat pratinjau ulang':'Lihat pratinjau' ?></button></div>
        </form>
    </div>
</section>
<?php endif ?>
<?php if($options!==null&&$options['publikasi']!==[]&&$preview===null): ?>
<section class="ah-card" aria-labelledby="ah-publikasi-sumber">
    <div class="ah-card__head"><span id="ah-publikasi-sumber">Publikasi dari sumber ini</span></div>
    <ul class="ah-qlist"><?php foreach($options['publikasi'] as $row): ?><li><a href="<?= ah_e(app_url('/portal/v3_publikasi_kelola.php?id='.(int)$row['id'])) ?>"<?= (int)$row['id']===$id?' aria-current="true"':'' ?>><span class="ah-qlist__main"><b><?= ah_e($waliNama[(int)$row['wali_id']]??'Wali (relasi tidak aktif)') ?></b><small>Terbit <?= ah_e(ah_waktu((string)$row['diterbitkan_pada'])) ?> · <?= $row['dibaca_pada']===null?'belum dibaca':'dibaca' ?></small></span><?= ah_status($row['ditarik_pada']===null?'Terbit':'Ditarik') ?></a></li><?php endforeach ?></ul>
</section>
<?php endif ?>
<?php ah_page_close(); ?>
