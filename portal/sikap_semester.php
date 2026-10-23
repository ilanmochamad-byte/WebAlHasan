<?php
declare(strict_types=1);
use App\V4\BahanRepository;
use App\V4\RaporTampilan;
use App\V4\SikapService;
use App\V4\V4Exception;
use App\Http\Csrf;
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once __DIR__.'/_rapor_ui.php';

/*
 * R-4 Sikap semester (pembimbing) dan Sikap binaan (murobi) — redesain
 * website Tahap 7, keputusan U dan V.
 *
 * Kisi santri × tiga aspek. Penyimpanan tetap per santri lewat
 * `SikapService::save()` (cakupan penugasan aktif, versi optimistis, riwayat,
 * audit): isian baru tersimpan sebagai draf, perubahan isian tersimpan adalah
 * koreksi beralasan (aturan layanan), "Serahkan yang lengkap" dan "Tandai
 * diketahui" satu santri per permintaan dengan bilah kemajuan. Catatan dan
 * riwayat dibuka di panel santri. Bentuk POST lama per santri tetap diterima.
 */
$user=authorization()->requireWebUser();header('Cache-Control: private, no-store');
$service=new SikapService(app_db(),audit_logger());
$tampil=new RaporTampilan(app_db());
$repo=new BahanRepository(app_db(),audit_logger());
$mode=(string)($_POST['mode']??$_GET['mode']??'pembimbing');
$plan=(int)($_POST['rencana_id']??$_GET['rencana_id']??0);$sid=(int)($_POST['santri_id']??$_GET['santri_id']??0);
$tahun=(int)($_GET['tahun_ajaran_id']??0);$cari=trim((string)($_GET['q']??''));
$current=null;$options=[];$semua=[];$json=ah_wants_json();
$aspek=['adab'=>'Adab / akhlak','kedisiplinan'=>'Kedisiplinan','tanggung_jawab'=>'Tanggung jawab'];
$self=static fn(array $q=[]):string=>app_url('/portal/sikap_semester.php').'?'.http_build_query(array_filter(['mode'=>$mode]+$q,static fn($v)=>$v!==null&&$v!==''&&$v!==0));
/** @return array{0:int,1:int,2:int}|null "rencana:santri:versi" */
$urai=static fn(mixed $p):?array=>is_string($p)&&preg_match('/^(\d+):(\d+):(\d+)$/D',$p,$m)?[(int)$m[1],(int)$m[2],(int)$m[3]]:null;
$saring='';
try{
    $semua=$service->options($user,$mode);
    // Saringan dari kartu Beranda (Tahap 2): himpunan sama dengan angkanya.
    $saring=in_array($_GET['status']??'',['belum','perlu_diketahui'],true)?(string)$_GET['status']:'';
    $options=$saring!==''?$service->filterOptions($semua,$saring):$semua;
    if($_SERVER['REQUEST_METHOD']==='POST'){
        Csrf::requireValid(is_string($_POST['_csrf']??null)?$_POST['_csrf']:null);
        $aksi=(string)($_POST['aksi']??'');
        $nama=array_column($semua,'nama_santri','santri_id');
        if($aksi==='kisi'){
            // Simpan draf kisi: satu panggilan layanan per santri yang berubah.
            $isian=is_array($_POST['s']??null)?$_POST['s']:[];$ver=is_array($_POST['v']??null)?$_POST['v']:[];
            $alasanKoreksi=is_string($_POST['alasan_koreksi']??null)?trim($_POST['alasan_koreksi']):'';
            $boleh=[];foreach($semua as $o)$boleh[(int)$o['rencana_id'].':'.(int)$o['santri_id']]=$o;
            $ok=0;$gagal=[];
            foreach($isian as $kunci=>$nilai){
                if(!is_array($nilai)||!isset($boleh[$kunci])){$gagal[]='Santri di luar penugasan Anda.';continue;}
                $o=$boleh[$kunci];$s=(int)$o['santri_id'];
                $lama=$tampil->sikapKelas((int)$o['tahun_ajaran_id'],[$s])[$s]??null;
                $isi=[];foreach(array_keys($aspek) as $k)$isi[$k]=is_string($nilai[$k]??null)?$nilai[$k]:'';
                if($lama===null){
                    if(implode('',$isi)==='')continue;
                    if(in_array('',$isi,true)){$gagal[]=($nama[$s]??'Santri').': isi ketiga aspek sikap.';continue;}
                    $aksiS='draf';$alasan='';
                }else{
                    $sama=true;foreach($isi as $k=>$v)if($v!==(string)$lama[$k])$sama=false;
                    if($sama)continue;
                    if($alasanKoreksi===''){$gagal[]=($nama[$s]??'Santri').': mengubah sikap tersimpan memerlukan alasan koreksi.';continue;}
                    $aksiS='koreksi';$alasan=$alasanKoreksi;
                }
                try{$service->save($user,$isi+['rencana_id'=>(int)$o['rencana_id'],'santri_id'=>$s,'version'=>(string)($ver[$kunci]??($lama['version']??0)),'catatan'=>(string)($lama['catatan']??''),'action'=>$aksiS,'alasan'=>$alasan],'pembimbing');$ok++;}
                catch(V4Exception $ex){$gagal[]=($nama[$s]??'Santri').': '.$ex->getMessage();}
            }
            if($gagal!==[])ah_flash_set($ok>0?'warning':'danger',$ok.' santri tersimpan · '.count($gagal).' belum tersimpan.','<ul class="mb-0 small">'.implode('',array_map(static fn($g)=>'<li>'.ah_e($g).'</li>',$gagal)).'</ul>');
            else ah_flash_set('success',$ok>0?'Sikap '.$ok.' santri tersimpan beserta riwayatnya.':'Tidak ada perubahan untuk disimpan.');
            ah_redirect($self(['tahun_ajaran_id'=>$tahun?:null,'q'=>$cari,'status'=>$saring]));
        }
        if($aksi==='serahkan_lengkap'||$aksi==='diketahui'){
            // Dengan JavaScript satu santri per permintaan; tanpa JavaScript berurutan.
            $pilih=$_POST['pilih']??[];$pilih=is_string($pilih)?[$pilih]:(is_array($pilih)?$pilih:[]);
            $ok=0;$gagal=[];
            foreach($pilih as $p){
                $u=$urai($p);
                try{
                    if($u===null)throw new V4Exception('Pilihan tidak valid.');
                    [$r,$s,$versi]=$u;
                    if($aksi==='diketahui'){
                        $service->save($user,['rencana_id'=>$r,'santri_id'=>$s,'version'=>(string)$versi,'action'=>'diketahui','alasan'=>''],'murobi');
                    }else{
                        $baca=$service->read($user,$r,$s,'pembimbing');$row=$baca['sikap'];
                        if(!$row||(int)$row['version']!==$versi)throw new V4Exception('Sikap berubah sejak halaman dibuka. Muat ulang.',409);
                        $service->save($user,['rencana_id'=>$r,'santri_id'=>$s,'version'=>(string)$versi,'adab'=>$row['adab'],'kedisiplinan'=>$row['kedisiplinan'],'tanggung_jawab'=>$row['tanggung_jawab'],'catatan'=>(string)$row['catatan'],'action'=>'serahkan','alasan'=>''],'pembimbing');
                    }
                    $ok++;
                }catch(V4Exception $ex){
                    if($json)ah_json(['ok'=>false,'message'=>$ex->getMessage()],$ex->status);
                    $gagal[]=($u?($nama[$u[1]]??'Santri'):'Santri').': '.$ex->getMessage();
                }
            }
            $kata=$aksi==='diketahui'?'ditandai diketahui':'diserahkan';
            if($json)ah_json(['ok'=>true,'message'=>'Sikap '.$kata.'.']);
            if($gagal!==[])ah_flash_set($ok>0?'warning':'danger',$ok.' santri '.$kata.' · '.count($gagal).' gagal.','<ul class="mb-0 small">'.implode('',array_map(static fn($g)=>'<li>'.ah_e($g).'</li>',$gagal)).'</ul>');
            else ah_flash_set('success',$ok.' sikap santri '.$kata.'.');
            ah_redirect($self(['tahun_ajaran_id'=>$tahun?:null,'q'=>$cari,'status'=>$saring]));
        }
        // Bentuk lama/panel: satu santri.
        $service->save($user,$_POST,$mode);
        if($json)ah_json(['ok'=>true,'message'=>'Sikap tersimpan.']);
        ah_flash_set('success','Sikap dan riwayat tersimpan.');
        ah_redirect($self(['rencana_id'=>$plan,'santri_id'=>$sid]));
    }
    if($plan&&$sid)$current=$service->read($user,$plan,$sid,$mode);
}catch(V4Exception $ex){
    if($json)ah_json(['ok'=>false,'message'=>$ex->getMessage()],$ex->status);
    http_response_code($ex->status);
    if($ex->status===403){\App\Ui\Denial::page('Sikap semester','pembimbing, murobi, atau Bagian Pendidikan yang memegang penugasan aktif',$ex->getMessage());}
    $error=$ex->getMessage();if($plan&&$sid){try{$current=$service->read($user,$plan,$sid,$mode);}catch(V4Exception){}}
}
$e=static fn($v)=>ah_e($v);
// ---- Semester, pencarian, data kisi ---------------------------------------------
$semester=[];foreach($semua as $o)$semester[(int)$o['tahun_ajaran_id']]=rapor_semester($o);
if(!isset($semester[$tahun]))$tahun=(int)(array_key_first($semester)??0);
$baris=array_values(array_filter($options,static fn($o)=>(int)$o['tahun_ajaran_id']===$tahun&&($cari===''||mb_stripos((string)$o['nama_santri'],$cari)!==false)));
$ids=array_map(static fn($o)=>(int)$o['santri_id'],$baris);
$sikap=$tahun?$tampil->sikapKelas($tahun,$ids):[];
$rapor=$tahun?$tampil->raporKelas($tahun,$ids):[];
$pelaku=$tampil->namaPengguna(array_column($sikap,'diketahui_murobi_user_id'));
$murobi=$mode==='murobi';$pembimbing=$mode==='pembimbing';
$siapSerah=[];$siapTahu=0;$belum=0;
foreach($baris as &$o){
    $s=(int)$o['santri_id'];$row=$sikap[$s]??null;
    $o['sikap']=$row;
    $o['state']=$repo->state((int)$o['rencana_id'],$s,'sikap',0,$row?[$row]:[],$row['status']??'Draf');
    $o['kunci']=($rapor[$s]['status']??'')==='Terbit';
    $o['bisa_tahu']=$row!==null&&$row['status']==='Dikirim'&&$row['diketahui_murobi_user_id']===null&&$o['state']['status']!=='Dikembalikan'&&!$o['kunci'];
    if($row===null||$row['status']==='Draf')$belum++;
    if($o['bisa_tahu'])$siapTahu++;
    if($pembimbing&&$row!==null&&$row['status']==='Draf'&&!$o['kunci'])$siapSerah[]=$o;
}
unset($o);
$judul=$murobi?'Sikap binaan':($mode==='pendidikan'?'Sikap semester (pemeriksaan)':'Sikap semester');
$serahkanForm='';
if($siapSerah!==[]){
    $serahkanForm='<form method="post" id="serahkan-sikap" class="d-inline" action="'.$e(app_url('/portal/sikap_semester.php')).'" data-rapor-massal="'.$e('Menyerahkan '.count($siapSerah).' sikap').'">'.ah_csrf()
        .'<input type="hidden" name="aksi" value="serahkan_lengkap"><input type="hidden" name="mode" value="pembimbing">';
    foreach($siapSerah as $o)$serahkanForm.='<input type="hidden" name="pilih[]" value="'.(int)$o['rencana_id'].':'.(int)$o['santri_id'].':'.(int)$o['sikap']['version'].'" data-nama="'.$e($o['nama_santri']).'">';
    $serahkanForm.='<button class="btn btn-primary" type="submit">Serahkan yang lengkap ('.count($siapSerah).')</button></form>';
}
ah_page_open(['title'=>$judul,'user'=>$user,'active'=>$murobi?'sikap_binaan':'sikap','actions'=>$serahkanForm,'wide'=>true,
    'description'=>count($baris).' santri · '.($semester[$tahun]??'semester belum dipilih').($murobi?' · '.$siapTahu.' menunggu diketahui':' · '.$belum.' belum diserahkan')]);
?>
<?php if(isset($error)):?><div class="ah-note ah-note--danger" role="alert"><i class="fas fa-circle-exclamation mt-1" aria-hidden="true"></i><div><strong class="ah-note__title">Sikap belum tersimpan.</strong><span><?= $e($error) ?> Data tersimpan terbaru ditampilkan.</span></div></div><?php endif ?>
<?php ah_filter_open(($cari!==''?1:0)+($saring!==''?1:0),['mode'=>$mode]) ?>
    <div><label class="form-label" for="f-semester">Semester</label><select class="form-select" id="f-semester" name="tahun_ajaran_id"><?php foreach($semester as $id=>$label):?><option value="<?= (int)$id ?>" <?= $id===$tahun?'selected':'' ?>><?= $e($label) ?></option><?php endforeach ?></select></div>
    <div><label class="form-label" for="f-cari">Cari santri</label><input class="form-control" type="search" id="f-cari" name="q" value="<?= $e($cari) ?>" placeholder="Cari santri…"></div>
    <div><label class="form-label" for="f-status">Status</label><select class="form-select" id="f-status" name="status">
        <option value="">Semua</option><option value="belum" <?= $saring==='belum'?'selected':'' ?>>Belum diserahkan</option><option value="perlu_diketahui" <?= $saring==='perlu_diketahui'?'selected':'' ?>>Menunggu diketahui murobi</option></select></div>
<?php ah_filter_close(['mode'=>$mode]) ?>
<?php if($saring!==''):?><p class="small" data-ah-saringan="<?= $e($saring) ?>"><?= $e($saring==='belum'?'Hanya santri yang sikapnya belum diserahkan':'Hanya sikap yang sudah diserahkan dan belum diketahui murobi') ?> · <?= count($baris) ?> santri · <a href="<?= $e($self()) ?>">Tampilkan semua</a></p><?php endif ?>
<?php if($baris===[]):?>
    <section class="ah-card"><div class="ah-card__body"><?= ah_empty($saring!==''||$cari!==''?'Tidak ada yang sesuai':'Belum ada santri',$saring!==''||$cari!==''?'Tidak ada santri yang sesuai saringan ini.':'Belum ada santri dalam penugasan aktif Anda.') ?></div></section>
<?php else: ?>
<?php if($murobi): ?>
<form method="post" action="<?= $e(app_url('/portal/sikap_semester.php')) ?>" data-rapor-massal="Menandai sikap diketahui" data-ah-selection="santri" id="murobi-form">
<?= ah_csrf() ?><input type="hidden" name="aksi" value="diketahui"><input type="hidden" name="mode" value="murobi">
<?php else: ?>
<form method="post" action="<?= $e(app_url('/portal/sikap_semester.php').'?'.http_build_query(array_filter(['tahun_ajaran_id'=>$tahun,'q'=>$cari,'status'=>$saring]))) ?>" id="kisi-sikap"<?= $pembimbing?' data-rapor-kisi':'' ?>>
<?= ah_csrf() ?><input type="hidden" name="aksi" value="kisi"><input type="hidden" name="mode" value="pembimbing">
<?php endif ?>
<div class="ah-table-wrap ah-kisi-wrap"><table class="ah-table ah-kisi ah-kisi--sikap" data-rapor-pager>
    <caption class="ah-visually-hidden"><?= $e($judul) ?>: baris santri, kolom aspek sikap</caption>
    <thead><tr><?php if($murobi):?><th scope="col" class="ah-col-check"><input class="form-check-input" type="checkbox" data-ah-select-all aria-label="Pilih semua yang menunggu diketahui"<?= $siapTahu?'':' disabled' ?>></th><?php endif ?>
        <th scope="col" class="ah-kisi__nama">Santri</th><th scope="col">Kelas</th><?php foreach($aspek as $label):?><th scope="col"><?= $e($label) ?></th><?php endforeach ?><th scope="col">Status</th></tr></thead>
    <tbody>
    <?php foreach($baris as $no=>$o): $s=(int)$o['santri_id'];$row=$o['sikap'];$kunci=(int)$o['rencana_id'].':'.$s;$nm=(string)$o['nama_santri'];
        $panel=$self(['rencana_id'=>(int)$o['rencana_id'],'santri_id'=>$s,'tahun_ajaran_id'=>$tahun,'q'=>$cari,'status'=>$saring]).'#ah-panel'; ?>
        <tr data-santri="<?= $s ?>"<?= $row&&$row['status']==='Dikirim'?' data-diserahkan':'' ?><?= $o['kunci']?' class="is-locked"':'' ?>>
            <?php if($murobi):?><td class="ah-col-check" data-label=""><?php if($o['bisa_tahu']):?><input class="form-check-input" type="checkbox" name="pilih[]" value="<?= $kunci.':'.(int)$row['version'] ?>" data-ah-select data-nama="<?= $e($nm) ?>" aria-label="Pilih <?= $e($nm) ?>"><?php endif ?></td><?php endif ?>
            <th scope="row" class="ah-kisi__nama" data-label="Santri"><a href="<?= $e($panel) ?>"><?= $e($nm) ?></a><span class="ah-kisi__pos small text-muted"><?= $no+1 ?> dari <?= count($baris) ?></span></th>
            <td data-label="Kelas"><?= $e($o['nama_kelas']) ?></td>
            <?php foreach($aspek as $k=>$label): $v=(string)($row[$k]??''); ?>
                <td data-label="<?= $e($label) ?>"><?php if($pembimbing&&!$o['kunci']):?>
                    <label class="ah-visually-hidden" for="s-<?= $s ?>-<?= $k ?>"><?= $e($label.' '.$nm) ?></label>
                    <select class="form-select form-select-sm" id="s-<?= $s ?>-<?= $k ?>" name="s[<?= $kunci ?>][<?= $k ?>]" data-aspek data-asal="<?= $e($v) ?>"><option value="">—</option><?php foreach(SikapService::PREDIKAT as $pred):?><option <?= $v===$pred?'selected':'' ?>><?= $e($pred) ?></option><?php endforeach ?></select>
                <?php else: ?><?= $v===''?'<span class="text-muted">—</span>':$e($v) ?><?php endif ?></td>
            <?php endforeach ?>
            <td data-label="Status"><?php
                if($o['kunci'])echo ah_badge('Rapor terbit','ok');
                elseif($row===null)echo ah_badge('Belum diisi','muted');
                else echo rapor_status_badge($o['state']['status']);
                if($o['state']['perlu_verifikasi_ulang'])echo ' '.ah_badge('Perlu diserahkan ulang','warn');
                if($row&&$row['diketahui_murobi_user_id']!==null)echo ' <span class="small text-muted d-block">Diketahui '.$e($pelaku[(int)$row['diketahui_murobi_user_id']]??'murobi').'</span>';
                elseif($row&&$row['status']==='Dikirim')echo ' <span class="small text-muted d-block">Menunggu diketahui murobi</span>'; ?>
                <?php if($pembimbing&&$row):?><input type="hidden" name="v[<?= $kunci ?>]" value="<?= (int)$row['version'] ?>"><?php endif ?></td>
        </tr>
    <?php endforeach ?>
    </tbody></table></div>
<?php if($murobi): ?>
    <?= ah_selection_bar('<button class="btn btn-primary btn-sm" type="submit">Tandai diketahui</button>') ?>
    <p class="small text-muted mt-2">Centang santri yang sikapnya sudah diserahkan pembimbing, atau "pilih semua", lalu "Tandai diketahui". Koreksi isian dibuka lewat nama santri.</p>
</form>
<?php elseif($pembimbing): ?>
<div class="ah-koreksi" data-rapor-koreksi>
    <label class="form-label" for="alasan-koreksi-sikap">Alasan koreksi <span class="text-muted fw-normal small">— wajib bila mengubah sikap yang sudah tersimpan. Sikap yang dikoreksi kembali menjadi draf dan perlu diserahkan ulang.</span></label>
    <textarea class="form-control" id="alasan-koreksi-sikap" name="alasan_koreksi" maxlength="1000" rows="2"></textarea>
</div>
<div class="ah-actbar ah-actbar--kisi ah-no-print"><span class="ah-actbar__text" data-rapor-sbar-text><?= $belum ?> santri belum diserahkan</span>
    <div class="ah-actbar__btns"><button class="btn btn-outline-primary" type="submit">Simpan draf</button><?php if($serahkanForm!==''):?><button class="btn btn-primary" type="submit" form="serahkan-sikap">Serahkan <?= count($siapSerah) ?> sikap</button><?php endif ?></div></div>
</form>
<p class="small text-muted">Pilih predikat Sangat Baik, Baik, Cukup, atau Perlu Bimbingan untuk ketiga aspek. Catatan pembimbing dan riwayat dibuka lewat nama santri.</p>
<?php else: ?></form><?php endif ?>
<div id="rapor-bulk" class="ah-no-print"></div>
<?php endif ?>
<?php if($current): $row=$current['sikap']??[];$namaPanel='';foreach($semua as $o)if((int)$o['santri_id']===$sid)$namaPanel=(string)$o['nama_santri'];
    $orang=$tampil->namaPengguna(array_merge(array_column($current['riwayat'],'actor_user_id'),$current['murobi_aktif'],[(int)($row['diketahui_murobi_user_id']??0),(int)($row['pembimbing_user_id']??0)]));
    ah_panel_open(['title'=>$namaPanel?:'Sikap santri','description'=>rapor_semester($current['rencana']).' · '.$current['rencana']['nama_kelas'],'close_url'=>$self(['tahun_ajaran_id'=>$tahun,'q'=>$cari,'status'=>$saring])]); ?>
    <p>Status: <?= rapor_status_badge($current['pemeriksaan']['status']) ?>
        <span class="small text-muted d-block"><?= empty($row['diketahui_murobi_user_id'])?'Belum diketahui murobi':'Diketahui '.$e($orang[(int)$row['diketahui_murobi_user_id']]??'murobi').' · '.$e(rapor_waktu($row['diketahui_pada']??null)) ?></span>
        <span class="small text-muted d-block">Murobi aktif: <?= $current['murobi_aktif']?$e(implode(', ',array_map(static fn($id)=>$orang[$id]??'murobi',$current['murobi_aktif']))):'—' ?></span></p>
    <?php if($current['pemeriksaan']['alasan']):?><?php ah_note('info','Catatan pemeriksaan: '.$current['pemeriksaan']['alasan']) ?><?php endif ?>
    <?php if(!$current['murobi_aktif']):?><?php ah_note('warning','Tidak ada murobi aktif yang sah. Minta admin memperbaiki penugasan.') ?><?php endif ?>
    <?php if($mode==='murobi'&&!$row):?><p>Pembimbing belum mengisi sikap.</p>
    <?php elseif($mode!=='pendidikan'):?>
    <form method="post" id="panel-sikap" data-rapor-panel-koreksi><?= ah_csrf() ?><input type="hidden" name="mode" value="<?= $e($mode) ?>"><input type="hidden" name="rencana_id" value="<?= $plan ?>"><input type="hidden" name="santri_id" value="<?= $sid ?>"><input type="hidden" name="version" value="<?= (int)($row['version']??0) ?>">
        <div class="ah-komponen__grid"><?php foreach($aspek as $key=>$label):?><label><?= $e($label) ?><select class="form-select" name="<?= $key ?>" required><option value="">Pilih predikat</option><?php foreach(SikapService::PREDIKAT as $pred):?><option <?= ($row[$key]??'')===$pred?'selected':'' ?>><?= $e($pred) ?></option><?php endforeach ?></select></label><?php endforeach ?></div>
        <label class="d-block mt-3">Catatan pembimbing<textarea class="form-control" name="catatan" maxlength="2000" rows="3"><?= $e($row['catatan']??'') ?></textarea></label>
        <div class="ah-act__actions mt-3">
        <?php if($mode==='pembimbing'&&(!$row||$row['status']==='Draf')):?><button class="btn btn-outline-primary" name="action" value="draf">Simpan draf</button><button class="btn btn-primary" name="action" value="serahkan">Serahkan</button><?php endif ?>
        <?php if($mode==='murobi'&&(empty($row['diketahui_murobi_user_id'])||!in_array((int)$row['diketahui_murobi_user_id'],$current['murobi_aktif'],true))):?><button class="btn btn-primary" name="action" value="diketahui">Tandai diketahui</button><?php endif ?>
        </div>
        <?php if($row):?>
            <?php ah_action_dialog_open('koreksi-sikap','Koreksi…','Koreksi sikap '.$namaPanel,'outline-primary') ?>
                <p class="small">Ubah isian di panel lalu tulis alasannya. Sikap yang dikoreksi <?= $mode==='murobi'?'tercatat sebagai diketahui Anda':'kembali menjadi draf dan perlu diserahkan ulang' ?>.</p>
                <label class="form-label" for="alasan-koreksi">Alasan koreksi <span class="text-danger">*</span></label>
                <textarea class="form-control" id="alasan-koreksi" name="alasan" maxlength="1000" form="panel-sikap"></textarea>
                <div class="ah-act__actions"><button class="btn btn-primary" name="action" value="koreksi" form="panel-sikap">Simpan koreksi</button></div>
            <?php ah_action_dialog_close() ?>
        <?php endif ?>
    </form>
    <?php else: ?>
        <dl class="ah-kv"><?php foreach($aspek as $key=>$label):?><dt><?= $e($label) ?></dt><dd><?= $e($row[$key]??'Belum diisi') ?></dd><?php endforeach ?><dt>Catatan</dt><dd><?= $e(($row['catatan']??'')!==''?$row['catatan']:'—') ?></dd></dl>
    <?php endif ?>
    <h3 class="h6 mt-4">Riwayat sikap</h3>
    <?= ah_timeline(array_map(static function($h)use($orang){$s=json_decode((string)$h['snapshot_json'],true)?:[];
        return ['waktu'=>rapor_waktu($s['updated_at']??($h['created_at']??null)),'pelaku'=>($orang[(int)$h['actor_user_id']]??'Pengguna').' ('.rapor_kapasitas($h['kapasitas']).')','judul'=>'Versi '.(int)$h['version'].' · '.rapor_status_label($s['status']??''),
            'isi'=>trim(($s['adab']??'').' · '.($s['kedisiplinan']??'').' · '.($s['tanggung_jawab']??'').(($s['catatan']??'')!==''?"\nCatatan: ".$s['catatan']:'').(trim((string)$h['alasan'])!==''?"\nAlasan: ".$h['alasan']:''))];},array_reverse($current['riwayat'])),'Riwayat sikap') ?>
<?php ah_panel_close(); endif ?>
<script src="<?= $e(\App\Ui\Layout::asset('/assets/ui/rapor.js')) ?>" defer></script>
<?php ah_page_close(); ?>
