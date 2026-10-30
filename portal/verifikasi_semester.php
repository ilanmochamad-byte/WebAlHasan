<?php
declare(strict_types=1);
use App\Auth\Capabilities;
use App\Penugasan\PenugasanJenis;
use App\V4\RaporTampilan;
use App\V4\VerifikasiService;
use App\V4\V4Exception;
use App\Http\Csrf;
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once __DIR__.'/_rapor_ui.php';

/*
 * R-5 Kelengkapan semester (matriks) — redesain website Tahap 7, keputusan V.
 *
 * Matriks santri × bahan (mapel, sikap, kehadiran) dengan simbol + warna +
 * legenda; klik sel membuka panel detail dengan Verifikasi / Kembalikan
 * beralasan, atau "Serahkan rekap kehadiran". "Verifikasi semua yang
 * diserahkan" memproses satu bahan per permintaan dengan bilah kemajuan.
 * Setiap tindakan tetap `VerifikasiService::act()` (cakupan Pendidikan,
 * versi + sidik sumber, transaksi, audit). Pemeriksaan kelayakan finalisasi
 * kini di tab Penerbitan; aksi POST lamanya tetap diterima di sini.
 */
$user=authorization()->requireWebUser();header('Cache-Control: private, no-store');
$service=new VerifikasiService(app_db(),audit_logger());
$tampil=new RaporTampilan(app_db());
$minta=(int)($_POST['rencana_id']??$_GET['rencana_id']??0);$sid=(int)($_POST['santri_id']??$_GET['santri_id']??0);
$bahanDipilih=(string)($_GET['bahan']??'');
$current=null;$options=[];$plan=0;$cocok=true;$json=ah_wants_json();
$caps=capabilities();
$sebagaiAdmin=$caps->featureSource($user,PenugasanJenis::CAP_RAPOR_VERIFIKASI)!==Capabilities::SUMBER_PENUGASAN;
/** @return array{0:int,1:string,2:int,3:string,4:string}|null "santri|jenis|kunci|versi|sidik" */
$urai=static fn(mixed $p):?array=>is_string($p)&&preg_match('/^(\d+)\|(nilai|sikap|kehadiran)\|(\d+)\|(\d+)\|([a-f0-9]{64})$/D',$p,$m)?[(int)$m[1],$m[2],(int)$m[3],$m[4],$m[5]]:null;
// Saringan "perlu perbaikan" dari kartu Beranda (Tahap 2): status EFEKTIF
// Dikembalikan pada matriks, himpunan yang sama dengan angka Beranda.
$saringPerbaikan=($_GET['status']??'')==='Dikembalikan';
try{
    $options=$service->options($user);
    // Pada peran Pendidikan, daftar tujuan kartu memakai penugasan sendiri,
    // sama dengan Beranda. Layanan bisnis tetap menyediakan pengawasan Admin.
    if(\App\Ui\Peran::active($user)===\App\Ui\Peran::PENDIDIKAN){
        $options=array_values(array_filter($options,static fn($o)=>capabilities()->featureOwnAppliesTo($user,
            \App\Penugasan\PenugasanJenis::CAP_RAPOR_VERIFIKASI,
            ['tahun_ajaran_id'=>(int)$o['tahun_ajaran_id'],'kelas_id'=>(int)$o['kelas_id']])));
        if($minta&&!in_array($minta,array_map('intval',array_column($options,'id')),true)){
            if(in_array(\App\Ui\Peran::ADMIN,\App\Ui\Peran::available($user),true)){
                \App\Ui\Denial::otherRole(\App\Ui\Peran::PENDIDIKAN,[\App\Ui\Peran::ADMIN],\App\Http\SafeRedirect::currentRequest(),null);
            }
            throw new V4Exception('Rencana di luar penugasan Pendidikan Anda.',403);
        }
    }
    // Beranda "perlu perbaikan" tanpa kelas: daftar lintas kelas, bukan rencana bawaan.
    $daftarLintas=$saringPerbaikan&&!$minta&&!isset($_GET['tahun_ajaran_id']);
    if(!$daftarLintas){
        $pilih=rapor_pilih_rencana($options,$minta,(int)($_GET['tahun_ajaran_id']??0),(int)($_GET['kelas_id']??0));
        $plan=$pilih['id'];$cocok=$pilih['cocok'];
    }
    if($_SERVER['REQUEST_METHOD']==='POST'){
        Csrf::requireValid(is_string($_POST['_csrf']??null)?$_POST['_csrf']:null);
        if(($_POST['aksi']??'')==='verifikasi_massal'){
            // Dengan JavaScript satu bahan per permintaan; tanpa JavaScript berurutan.
            $pilihan=$_POST['pilih']??[];$pilihan=is_string($pilihan)?[$pilihan]:(is_array($pilihan)?$pilihan:[]);
            $alasan=is_string($_POST['alasan']??null)?$_POST['alasan']:'';
            $ok=0;$gagal=[];
            foreach($pilihan as $p){
                $u=$urai($p);
                try{
                    if($u===null)throw new V4Exception('Pilihan tidak valid.');
                    $service->act($user,['rencana_id'=>$minta,'santri_id'=>$u[0],'jenis'=>$u[1],'mapel_key'=>$u[2],'version'=>$u[3],'sumber_hash'=>$u[4],'action'=>'verifikasi','alasan'=>$alasan]);
                    $ok++;
                }catch(V4Exception $ex){
                    if($json)ah_json(['ok'=>false,'message'=>$ex->getMessage()],$ex->status);
                    $gagal[]=$ex->getMessage();
                }
            }
            if($json)ah_json(['ok'=>true,'message'=>'Bahan terverifikasi.']);
            if($gagal!==[])ah_flash_set($ok>0?'warning':'danger',$ok.' bahan terverifikasi · '.count($gagal).' gagal.','<ul class="mb-0 small">'.implode('',array_map(static fn($g)=>'<li>'.ah_e($g).'</li>',array_unique($gagal))).'</ul>');
            else ah_flash_set('success',$ok.' bahan terverifikasi.');
            ah_redirect(app_url('/portal/verifikasi_semester.php').'?rencana_id='.$minta);
        }
        $result=$service->act($user,$_POST);
        if($json)ah_json(['ok'=>true,'message'=>'Status pemeriksaan tersimpan.']);
        ah_flash_set('success',isset($result['siap_finalisasi'])?'Bahan lengkap dan terverifikasi. Pemeriksaan selesai.':'Status pemeriksaan tersimpan.');
        $kunci=(string)($_POST['jenis']??'')!==''?'&bahan='.urlencode((string)$_POST['jenis'].':'.(int)($_POST['mapel_key']??0)):'';
        ah_redirect(app_url('/portal/verifikasi_semester.php').'?rencana_id='.$minta.'&santri_id='.$sid.$kunci.'#ah-panel');
    }
    if($plan)$current=$service->read($user,$plan);
}catch(V4Exception $ex){
    if($json)ah_json(['ok'=>false,'message'=>$ex->getMessage()],$ex->status);
    http_response_code($ex->status);if($ex->status===403){\App\Ui\Denial::page('Kelengkapan semester','Bagian Pendidikan',$ex->getMessage());}
    $error=$ex->getMessage();if($plan){try{$current=$service->read($user,$plan);}catch(V4Exception){}}
}
$e=static fn($v)=>ah_e($v);
$daftarPerbaikan=[];
if($saringPerbaikan&&$current){
    $current['matriks']=array_values(array_filter($current['matriks'],static fn($st)=>array_filter($st['bahan'],static fn($m)=>$m['pemeriksaan']['status']==='Dikembalikan')!==[]));
}elseif($saringPerbaikan&&!$plan){
    foreach($options as $o){
        try{$r=beranda_service()->ringkasBahan((int)$o['id'],(int)$o['tahun_ajaran_id'],(int)$o['kelas_id']);}catch(Throwable){continue;}
        if($r['perbaikan']>0)$daftarPerbaikan[]=['rencana'=>$o,'perbaikan'=>$r['perbaikan']];
    }
}
// ---- Ringkasan & matriks -----------------------------------------------------------
$stat=['total'=>0,'Terverifikasi'=>0,'Dikirim'=>0,'Dikembalikan'=>0,'belum'=>0];
$kolom=[];$siapMassal=[];$orangIds=[];
// PK-T1 (pemisahan tugas): bahan nilai yang memuat isian akun ini tidak ia verifikasi.
$sendiri=array_flip($current['diisi_sendiri']??[]);
$adaSumber=static fn(array $m):bool=>match($m['jenis']){'nilai'=>$m['komponen']!==[],'sikap'=>!empty($m['sikap']),default=>true};
foreach($current['matriks']??[] as $st){
    foreach($st['bahan'] as $m){
        $kolom[$m['jenis'].':'.$m['mapel_key']]=$m['jenis']==='kehadiran'?'Hadir':$m['label'];
        $s=$m['pemeriksaan']['status'];$stat['total']++;
        if(isset($stat[$s]))$stat[$s]++;else $stat['belum']++;
        if($s==='Dikirim'&&$m['lengkap']&&!isset($sendiri[(int)$st['santri']['id'].':'.(int)$m['mapel_key']]))$siapMassal[]=['v'=>(int)$st['santri']['id'].'|'.$m['jenis'].'|'.(int)$m['mapel_key'].'|'.$m['pemeriksaan']['version'].'|'.$m['pemeriksaan']['sumber_hash'],'nama'=>$st['santri']['nama_santri'].' · '.$m['label']];
        foreach($m['komponen']??[] as $k)$orangIds[]=(int)$k['pelaku_user_id'];
        if(!empty($m['sikap']))$orangIds[]=(int)($m['sikap']['diketahui_murobi_user_id']??0);
    }
    foreach($st['riwayat_pemeriksaan'] as $h)$orangIds[]=(int)(json_decode((string)$h['snapshot_json'],true)['actor_user_id']??0);
}
$orang=$tampil->namaPengguna($orangIds);
$rencanaRow=$current['rencana']??null;
$urlSel=static fn(int $s,string $k):string=>app_url('/portal/verifikasi_semester.php').'?'.http_build_query(array_filter(['rencana_id'=>$plan,'santri_id'=>$s,'bahan'=>$k,'status'=>$saringPerbaikan?'Dikembalikan':null])).'#ah-panel';
$massal='';
if($siapMassal!==[]){
    ob_start();
    ah_action_dialog_open('verifikasi-semua','Verifikasi semua yang diserahkan ('.count($siapMassal).')','Verifikasi '.count($siapMassal).' bahan yang diserahkan','primary');
    echo '<form method="post" action="'.$e(app_url('/portal/verifikasi_semester.php')).'" data-rapor-massal="'.$e('Memverifikasi '.count($siapMassal).' bahan').'">'.ah_csrf()
        .'<input type="hidden" name="aksi" value="verifikasi_massal"><input type="hidden" name="rencana_id" value="'.$plan.'">';
    foreach($siapMassal as $p)echo '<input type="hidden" name="pilih[]" value="'.$e($p['v']).'" data-nama="'.$e($p['nama']).'">';
    echo '<p class="small">Setiap bahan diverifikasi satu per satu dan tetap tercatat pada riwayatnya. Bahan yang berubah sejak halaman dibuka akan gagal dan didaftar.</p>'
        .'<label class="form-label" for="alasan-massal">Alasan'.($sebagaiAdmin?' <span class="text-danger">*</span> <span class="small text-muted">(tindakan admin)</span>':' <span class="small text-muted">(opsional)</span>').'</label>'
        .'<textarea class="form-control" id="alasan-massal" name="alasan" maxlength="1000"'.($sebagaiAdmin?' required':'').'></textarea>'
        .'<div class="ah-act__actions"><button class="btn btn-primary">Verifikasi '.count($siapMassal).' bahan</button></div></form>';
    ah_action_dialog_close();
    $massal=(string)ob_get_clean();
}
[$opsiSemester,$opsiKelas]=rapor_konteks_opsi($options);
ah_page_open(['title'=>'Kelengkapan','heading'=>'Penilaian & rapor','user'=>$user,'active'=>'rapor','actions'=>$massal,'wide'=>true,
    'description'=>$rencanaRow?($rencanaRow['nama_kelas'].' · '.rapor_semester($rencanaRow).' · pemeriksaan nilai, sikap, dan kehadiran sebelum rapor terbit.'):'Pemeriksaan nilai, sikap, dan kehadiran sebelum rapor terbit.']);
if($options!==[])echo rapor_konteks_bar($opsiSemester,(int)($rencanaRow['tahun_ajaran_id']??rapor_konteks()['tahun']),$opsiKelas,(int)($rencanaRow['kelas_id']??rapor_konteks()['kelas']),$saringPerbaikan?['status'=>'Dikembalikan']:[]);
?>
<?php if(isset($error)):?><div class="ah-note ah-note--danger" role="alert"><i class="fas fa-circle-exclamation mt-1" aria-hidden="true"></i><div><strong class="ah-note__title">Tindakan belum tersimpan.</strong><span><?= $e($error) ?> Data terbaru ditampilkan; periksa sebelum mencoba lagi.</span></div></div><?php endif ?>
<?php if(!$options):?><section class="ah-card"><div class="ah-card__body"><?= ah_empty('Belum ada rencana','Belum ada rencana kelas dalam cakupan Pendidikan Anda.') ?></div></section><?php endif ?>
<?php if(!$cocok):?><?php ah_note('warning','Kelas ini belum punya rencana nilai pada semester tersebut.','<p class="small mb-0 mt-1"><a href="'.$e(app_url('/portal/rencana_nilai.php')).'">Buka tab Rencana</a></p>') ?><?php endif ?>
<?php if($saringPerbaikan):?><section class="ah-card" data-ah-saringan="Dikembalikan"><div class="ah-card__body"><p class="mb-2"><b>Saringan: bahan perlu perbaikan.</b> <a href="<?= $e(app_url('/portal/verifikasi_semester.php').($plan?'?rencana_id='.$plan:'')) ?>">Tampilkan semua</a></p>
<?php if($plan&&$current):?><p class="mb-0"><?= count($current['matriks']) ?> santri dengan bahan perlu perbaikan.</p>
<?php elseif(!$plan):?><?php if($daftarPerbaikan===[]):?><p class="mb-0">Tidak ada bahan perlu perbaikan.</p><?php else:?><ul class="mb-0"><?php foreach($daftarPerbaikan as $d):?><li><a href="?rencana_id=<?= (int)$d['rencana']['id'] ?>&amp;status=Dikembalikan"><?= $e($d['rencana']['nama_kelas'].' · '.rapor_semester($d['rencana'])) ?></a> · <?= (int)$d['perbaikan'] ?> bahan</li><?php endforeach ?></ul><?php endif ?><?php endif ?></div></section><?php endif ?>
<?php if($current): ?>
<?= ah_stats([
    ['label'=>'Bahan','value'=>(string)$stat['total']],
    ['label'=>'Terverifikasi','value'=>(string)$stat['Terverifikasi']],
    ['label'=>'Diserahkan','value'=>(string)$stat['Dikirim']],
    ['label'=>'Perlu perbaikan','value'=>(string)$stat['Dikembalikan'],'warn'=>$stat['Dikembalikan']>0,'url'=>$stat['Dikembalikan']>0?app_url('/portal/verifikasi_semester.php').'?rencana_id='.$plan.'&status=Dikembalikan':null],
    ['label'=>'Belum ada','value'=>(string)$stat['belum'],'hint'=>'Draf atau belum diisi'],
],'Ringkasan bahan') ?>
<ul class="ah-legend" aria-label="Arti simbol matriks">
    <?php foreach([['Terverifikasi',true],['Dikirim',true],['Dikembalikan',true],['Draf',true],['',false]] as [$s,$a]):$c=rapor_sel($s,$a);?><li><span class="ah-sel ah-sel--<?= $c['kelas'] ?>" aria-hidden="true"><?= $c['simbol'] ?></span> <?= $e($c['label']) ?></li><?php endforeach ?>
    <li><span class="ah-sel ah-sel--ok" aria-hidden="true">✓</span> di kolom Siap = semua bahan terverifikasi</li>
</ul>
<?php if($current['matriks']===[]):?>
    <section class="ah-card"><div class="ah-card__body"><?= ah_empty('Tidak ada santri',$saringPerbaikan?'Tidak ada santri dengan bahan perlu perbaikan.':'Belum ada santri aktif pada kelas dan semester ini.') ?></div></section>
<?php else: ?>
<div class="ah-table-wrap ah-matrix-wrap"><table class="ah-table ah-matrix">
    <caption class="ah-visually-hidden">Kelengkapan bahan rapor per santri</caption>
    <thead><tr><th scope="col" class="ah-kisi__nama">Santri</th><?php foreach($kolom as $label):?><th scope="col"><?= $e($label) ?></th><?php endforeach ?><th scope="col">Siap</th></tr></thead>
    <tbody>
    <?php foreach($current['matriks'] as $st): $s=(int)$st['santri']['id'];$byKey=[];foreach($st['bahan'] as $m)$byKey[$m['jenis'].':'.$m['mapel_key']]=$m;
        $ver=count(array_filter($st['bahan'],static fn($m)=>$m['pemeriksaan']['status']==='Terverifikasi')); ?>
        <tr<?= $sid===$s?' class="is-current"':'' ?>><th scope="row" class="ah-kisi__nama"><a href="<?= $e($urlSel($s,'')) ?>"><?= $e($st['santri']['nama_santri']) ?></a></th>
        <?php foreach(array_keys($kolom) as $k): $m=$byKey[$k]??null; if(!$m){echo '<td>—</td>';continue;} $c=rapor_sel($m['pemeriksaan']['status'],$adaSumber($m)); ?>
            <td class="ah-matrix__cell"><a class="ah-sel ah-sel--<?= $c['kelas'] ?><?= $sid===$s&&$bahanDipilih===$k?' is-current':'' ?>" href="<?= $e($urlSel($s,$k)) ?>" title="<?= $e($kolom[$k].': '.$c['label']) ?>"><span aria-hidden="true"><?= $c['simbol'] ?></span><span class="ah-visually-hidden"><?= $e($kolom[$k].' '.$st['santri']['nama_santri'].': '.$c['label']) ?></span></a></td>
        <?php endforeach ?>
        <td><?= $st['siap_finalisasi']?'<span class="ah-sel ah-sel--ok" title="Siap terbit"><span aria-hidden="true">✓</span><span class="ah-visually-hidden">Siap terbit</span></span>':'<span class="small text-muted">'.$ver.'/'.count($st['bahan']).'</span>' ?></td></tr>
    <?php endforeach ?>
    </tbody></table></div>
<ul class="ah-matrix-list" aria-label="Kelengkapan per santri">
    <?php foreach($current['matriks'] as $st): $s=(int)$st['santri']['id'];$ver=count(array_filter($st['bahan'],static fn($m)=>$m['pemeriksaan']['status']==='Terverifikasi')); ?>
    <li><a href="<?= $e($urlSel($s,'')) ?>"><b><?= $e($st['santri']['nama_santri']) ?></b><span><?= $ver ?> dari <?= count($st['bahan']) ?> bahan terverifikasi</span><?= $st['siap_finalisasi']?ah_badge('Siap','ok'):'' ?></a></li>
    <?php endforeach ?>
</ul>
<div id="rapor-bulk" class="ah-no-print"></div>
<?php endif ?>
<?php
// ---- Panel santri / bahan -------------------------------------------------------------
$panelSantri=null;foreach($current['matriks'] as $st)if((int)$st['santri']['id']===$sid)$panelSantri=$st;
if($panelSantri):
    $nm=(string)$panelSantri['santri']['nama_santri'];$m=null;
    foreach($panelSantri['bahan'] as $b)if($b['jenis'].':'.$b['mapel_key']===$bahanDipilih)$m=$b;
    ah_panel_open(['title'=>$m?($m['jenis']==='kehadiran'?'Kehadiran':$m['label']).' · '.$nm:$nm,'description'=>$rencanaRow['nama_kelas'].' · '.rapor_semester($rencanaRow),'close_url'=>app_url('/portal/verifikasi_semester.php').'?rencana_id='.$plan.($saringPerbaikan?'&status=Dikembalikan':'')]);
    if($m===null): ?>
        <p><?= $panelSantri['siap_finalisasi']?ah_badge('Seluruh bahan terverifikasi','ok'):ah_badge('Masih ada kekurangan','warn') ?></p>
        <ul class="ah-bahan-list"><?php foreach($panelSantri['bahan'] as $b):$c=rapor_sel($b['pemeriksaan']['status'],$adaSumber($b));?>
            <li><a href="<?= $e($urlSel($sid,$b['jenis'].':'.$b['mapel_key'])) ?>"><span class="ah-sel ah-sel--<?= $c['kelas'] ?>" aria-hidden="true"><?= $c['simbol'] ?></span> <b><?= $e($b['jenis']==='kehadiran'?'Kehadiran':$b['label']) ?></b> <span class="text-muted small"><?= $e($c['label']) ?><?= $b['lengkap']?'':' · belum lengkap' ?></span></a></li>
        <?php endforeach ?></ul>
        <?php if($panelSantri['kekurangan']):?><h3 class="h6 mt-3">Kekurangan</h3><ul class="small"><?php foreach($panelSantri['kekurangan'] as $msg):?><li><?= $e($msg) ?></li><?php endforeach ?></ul><?php endif ?>
    <?php else: $state=$m['pemeriksaan']; ?>
        <dl class="ah-kv">
            <dt>Status</dt><dd><?= rapor_status_badge($state['status']) ?><?= $state['perlu_verifikasi_ulang']?' '.ah_badge('Sumber berubah · perlu diserahkan ulang','warn'):'' ?></dd>
            <dt>Kelengkapan</dt><dd><?= $m['lengkap']?'Lengkap':'Belum lengkap' ?></dd>
        <?php if($m['jenis']==='nilai'): $pengisi=array_values(array_unique(array_map(static fn($k)=>$orang[(int)$k['pelaku_user_id']]??'Pengguna',$m['komponen']))); ?>
            <dt>Diisi oleh</dt><dd><?= $pengisi?$e(implode(', ',$pengisi)):'—' ?></dd>
            <dt>Nilai akhir</dt><dd><?= $m['hasil']['nilai_tampil']===null?'Belum lengkap':(int)$m['hasil']['nilai_tampil'].' · '.$e($m['hasil']['predikat']) ?></dd>
            <dt>Komponen</dt><dd><?php if(!$m['komponen']):?>—<?php else:?><ul class="mb-0 ps-3"><?php foreach($m['komponen'] as $k):?><li><?= $e(($k['komponen']==='Harian'?'Harian '.(int)$k['nomor_harian']:$k['komponen']).': '.rapor_angka($k['nilai'])) ?></li><?php endforeach ?></ul><?php endif ?></dd>
        <?php elseif($m['jenis']==='sikap'): $sk=$m['sikap']??[]; ?>
            <?php foreach(['adab'=>'Adab / akhlak','kedisiplinan'=>'Kedisiplinan','tanggung_jawab'=>'Tanggung jawab'] as $k=>$label):?><dt><?= $e($label) ?></dt><dd><?= $e($sk[$k]??'Belum diisi') ?></dd><?php endforeach ?>
            <dt>Murobi</dt><dd><?= $e($m['penghalang']??('Diketahui '.($orang[(int)($sk['diketahui_murobi_user_id']??0)]??'murobi'))) ?></dd>
        <?php else: $att=$m['kehadiran']; ?>
            <dt>Pertemuan</dt><dd><?= (int)$att['jumlah_pertemuan'] ?></dd>
            <?php foreach($att['hitungan'] as $k=>$n):?><dt><?= $e($k) ?></dt><dd><?= (int)$n ?></dd><?php endforeach ?>
            <dt>Belum dicatat</dt><dd><?= (int)$att['belum_dicatat'] ?> pertemuan</dd>
        <?php endif ?>
        </dl>
        <?php if($m['jenis']==='sikap'):?><p class="small"><a href="<?= $e(app_url('/portal/sikap_semester.php').'?mode=pendidikan&rencana_id='.$plan.'&santri_id='.$sid.'#ah-panel') ?>">Lihat riwayat sikap</a></p><?php endif ?>
        <?php if($m['jenis']==='kehadiran'&&$m['kehadiran']['kekurangan']):?><ul class="small"><?php foreach($m['kehadiran']['kekurangan'] as $r):?><li>Pertemuan <?= $e(ah_tanggal($r['tanggal_pertemuan'])) ?> belum dicatat.</li><?php endforeach ?></ul><?php endif ?>
        <?php if($state['alasan']):?><?php ah_note('info','Catatan pemeriksaan terakhir: '.$state['alasan']) ?><?php endif ?>
        <?php $tetap='<input type="hidden" name="rencana_id" value="'.$plan.'"><input type="hidden" name="santri_id" value="'.$sid.'"><input type="hidden" name="jenis" value="'.$e($m['jenis']).'"><input type="hidden" name="mapel_key" value="'.(int)$m['mapel_key'].'"><input type="hidden" name="version" value="'.(int)$state['version'].'"><input type="hidden" name="sumber_hash" value="'.$e($state['sumber_hash']).'">'; ?>
        <?php if($m['jenis']==='nilai'&&isset($sendiri[$sid.':'.(int)$m['mapel_key']])&&in_array($state['status'],['Dikirim','Terverifikasi'],true)): ?>
            <?php if($state['status']==='Dikirim')ah_note('info','Nilai ini memuat isian Anda sendiri, sehingga diverifikasi Bagian Pendidikan lain atau admin (pemisahan tugas).') ?>
        <?php elseif($state['status']==='Dikirim'): ?>
            <form method="post" class="mt-3"><?= ah_csrf() ?><?= $tetap ?>
                <label class="form-label" for="alasan-verif">Alasan<?= $sebagaiAdmin?' <span class="text-danger">*</span> <span class="small text-muted">(tindakan admin)</span>':' <span class="small text-muted">(opsional)</span>' ?></label>
                <textarea class="form-control" id="alasan-verif" name="alasan" maxlength="1000" rows="2"<?= $sebagaiAdmin?' required':'' ?>></textarea>
                <div class="ah-act__actions"><button class="btn btn-primary" name="action" value="verifikasi"<?= $m['lengkap']?'':' disabled' ?>>Verifikasi</button></div></form>
            <?php ah_action_dialog_open('kembalikan-bahan','Kembalikan…','Kembalikan bahan '.($m['jenis']==='kehadiran'?'kehadiran':$m['label']).' '.$nm,'outline-danger') ?>
                <form method="post"><?= ah_csrf() ?><?= $tetap ?><p class="small">Pengisi bahan akan melihat alasan ini dan perlu menyerahkan ulang.</p>
                    <label class="form-label" for="alasan-kembali">Alasan dikembalikan <span class="text-danger">*</span></label><textarea class="form-control" id="alasan-kembali" name="alasan" maxlength="1000" required></textarea>
                    <div class="ah-act__actions"><button class="btn btn-danger" name="action" value="kembalikan">Kembalikan</button></div></form>
            <?php ah_action_dialog_close() ?>
        <?php elseif($m['jenis']==='kehadiran'&&$state['status']!=='Terverifikasi'): $wajib=$state['status']==='Dikembalikan'||$state['perlu_verifikasi_ulang']||$sebagaiAdmin; ?>
            <form method="post" class="mt-3"><?= ah_csrf() ?><?= $tetap ?>
                <label class="form-label" for="alasan-hadir">Alasan<?= $wajib?' <span class="text-danger">*</span>':' <span class="small text-muted">(opsional)</span>' ?></label>
                <textarea class="form-control" id="alasan-hadir" name="alasan" maxlength="1000" rows="2"<?= $wajib?' required':'' ?>></textarea>
                <div class="ah-act__actions"><button class="btn btn-primary" name="action" value="serahkan"<?= $m['lengkap']?'':' disabled' ?>>Serahkan rekap kehadiran</button></div></form>
        <?php elseif($state['status']!=='Terverifikasi'): ?>
            <p class="small text-muted mt-3"><?= $m['jenis']==='nilai'?'Menunggu guru mapel menyerahkan nilai.':'Menunggu pembimbing menyerahkan dan murobi mengetahui sikap.' ?></p>
        <?php endif ?>
        <?php $riw=array_values(array_filter($panelSantri['riwayat_pemeriksaan'],static function($h)use($m){$s=json_decode((string)$h['snapshot_json'],true)?:[];return ($s['jenis']??'')===$m['jenis']&&(int)($s['mapel_key']??-1)===(int)$m['mapel_key'];})); ?>
        <h3 class="h6 mt-4">Riwayat pemeriksaan</h3>
        <?= ah_timeline(array_map(static function($h)use($orang){$s=json_decode((string)$h['snapshot_json'],true)?:[];
            return ['waktu'=>rapor_waktu($s['updated_at']??null),'pelaku'=>($orang[(int)($s['actor_user_id']??0)]??'Pengguna').' ('.rapor_kapasitas($s['kapasitas']??'').')','judul'=>rapor_status_label($s['status']??''),'isi'=>trim((string)($s['alasan']??''))!==''?'Alasan: '.$s['alasan']:''];},array_reverse($riw)),'Riwayat pemeriksaan bahan') ?>
        <p class="mt-3 small"><a href="<?= $e($urlSel($sid,'')) ?>">‹ Semua bahan <?= $e($nm) ?></a></p>
    <?php endif;
    ah_panel_close();
endif ?>
<?php endif ?>
<script src="<?= $e(\App\Ui\Layout::asset('/assets/ui/rapor.js')) ?>" defer></script>
<?php ah_page_close(); ?>
