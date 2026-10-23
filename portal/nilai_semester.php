<?php
declare(strict_types=1);
use App\Auth\Capabilities;
use App\Penugasan\PenugasanJenis;
use App\V4\BahanRepository;
use App\V4\NilaiService;
use App\V4\RaporTampilan;
use App\V4\V4Exception;
use App\Http\Csrf;
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once __DIR__.'/_rapor_ui.php';

/*
 * R-2 Nilai semester (kisi per kelas) dan R-3 Nilai pengganti — redesain
 * website Tahap 7, keputusan U, V, W.
 *
 * Satu kisi untuk satu mapel–kelas–semester. Penyimpanan tetap per santri
 * lewat `NilaiService::save()` (transaksi, versi optimistis, riwayat, audit,
 * cakupan penugasan diperiksa ulang): "Simpan draf" memanggilnya untuk setiap
 * santri yang berubah, "Serahkan yang lengkap" satu santri per permintaan
 * dengan bilah kemajuan (tanpa JavaScript: berurutan dalam satu kiriman).
 * Nilai yang sudah diserahkan hanya berubah lewat koreksi beralasan di panel
 * santri. Impor Excel tidak ada (keputusan W). Bentuk POST lama per santri
 * (`komponen[...]`, `action`) tetap diterima.
 */
$user=authorization()->requireWebUser();
$service=new NilaiService(app_db(),capabilities(),audit_logger());
$tampil=new RaporTampilan(app_db());
header('Cache-Control: private, no-store');
$pengganti=($_POST['pengganti']??$_GET['pengganti']??'0')==='1';
$mapel=(int)($_POST['rencana_mapel_id']??$_GET['rencana_mapel_id']??0);
$santri=(int)($_POST['santri_id']??$_GET['santri_id']??0);
$kolomMinta=max(0,min(100,(int)($_GET['kolom']??0)));
$current=null;$options=[];$panel=null;
$json=ah_wants_json();
$caps=capabilities();
// Pengisian pengganti dan tindakan admin wajib beralasan (V4Access); alasan sesi diisi sekali (R-3).
$jenisAlasan=$pengganti?'pengganti':'admin';
$perluAlasan=$pengganti||in_array($caps->featureSource($user,PenugasanJenis::CAP_NILAI_INPUT),[Capabilities::SUMBER_ADMIN,Capabilities::SUMBER_KEDUANYA],true);
$self=static fn(array $q=[]):string=>app_url('/portal/nilai_semester.php').'?'.http_build_query(array_filter(['pengganti'=>$pengganti?'1':'0']+$q,static fn($v)=>$v!==null&&$v!==''&&$v!==0));

/** Komponen tersimpan dalam bentuk muatan `NilaiService::save()`. */
$muatanDb=static function(array $rows):array{
    $out=[];foreach($rows as $r)$out[$r['komponen'].':'.(int)$r['nomor_harian']]=['komponen'=>$r['komponen'],'nomor_harian'=>(int)$r['nomor_harian'],'version'=>(int)$r['version'],'nilai'=>(string)$r['nilai'],'catatan'=>(string)$r['catatan']];
    return $out;
};
$angka=static fn(mixed $v):string=>is_string($v)?str_replace(',','.',trim($v)):'';
$terkunciDb=static fn(array $rows):bool=>array_filter($rows,static fn($r)=>$r['status']!=='Draf')!==[];

try {
    $options=$service->options($user,$pengganti);
    $optionIds=array_map('intval',array_column($options,'id'));
    $ingat=(int)($_SESSION['ah_rapor_mapel'][$pengganti?1:0]??0);
    if($mapel<1)$mapel=in_array($ingat,$optionIds,true)?$ingat:($optionIds[0]??0);
    $alasanSesi=rapor_alasan_sesi($jenisAlasan,$mapel);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        Csrf::requireValid(is_string($_POST['_csrf']??null)?$_POST['_csrf']:null);
        $aksi=(string)($_POST['aksi']??'');
        if($aksi==='alasan_sesi'){
            $teks=is_string($_POST['alasan_sesi']??null)?$_POST['alasan_sesi']:'';
            if(trim($teks)===''||mb_strlen($teks)>1000)throw new V4Exception('Alasan wajib diisi (maksimal 1000 karakter).');
            $service->read($user,$mapel,0,$pengganti);
            rapor_alasan_sesi_simpan($jenisAlasan,$mapel,$teks);
            ah_flash_set('success','Alasan tersimpan untuk sesi ini dan akan tercatat pada setiap perubahan mapel ini.');
            ah_redirect($self(['rencana_mapel_id'=>$mapel]));
        }
        if($aksi==='kisi'){
            // Simpan draf seluruh kisi: satu panggilan layanan (satu transaksi) per santri yang berubah.
            $baca=$service->read($user,$mapel,0,$pengganti);
            $ids=array_map('intval',array_column($baca['santri'],'id'));
            $nama=array_column($baca['santri'],'nama_santri','id');
            $db=$tampil->nilaiKelas($mapel,$ids);
            $n=is_array($_POST['n']??null)?$_POST['n']:[];$ver=is_array($_POST['ver']??null)?$_POST['ver']:[];
            $alasanKoreksi=is_string($_POST['alasan_koreksi']??null)?trim($_POST['alasan_koreksi']):'';
            $ok=0;$gagal=[];
            foreach($n as $sid=>$sel){
                $sid=(int)$sid;if(!is_array($sel))continue;
                if(!in_array($sid,$ids,true)){$gagal[]='Santri #'.$sid.': di luar kelas dan semester.';continue;}
                $rows=$db[$sid]??[];$items=$muatanDb($rows);$baru=false;$ubahTersimpan=false;$salah=null;
                foreach($items as $key=>&$item){
                    if(!array_key_exists($key,$sel))continue;
                    $v=$angka($sel[$key]);
                    if($v===''){$salah='nilai tersimpan tidak boleh dikosongkan.';break;}
                    if((float)$v!==(float)$item['nilai']||$v!==$item['nilai']&&!is_numeric($v)){$item['nilai']=$v;$ubahTersimpan=true;}
                    $item['version']=filter_var($ver[$sid][$key]??$item['version'],FILTER_VALIDATE_INT)?:(int)$item['version'];
                }
                unset($item);
                if($salah===null)foreach($sel as $key=>$v){
                    if(isset($items[$key]))continue;$v=$angka($v);if($v==='')continue;
                    if(!is_string($key)||!preg_match('/^(Harian|Tengah|Akhir):(\d{1,3})$/D',$key,$m)){$salah='kolom nilai tidak dikenal.';break;}
                    $items[$key]=['komponen'=>$m[1],'nomor_harian'=>(int)$m[2],'version'=>0,'nilai'=>$v,'catatan'=>''];$baru=true;
                }
                if($salah!==null){$gagal[]=($nama[$sid]??'Santri').': '.$salah;continue;}
                if(!$baru&&!$ubahTersimpan)continue;
                // Aturan layanan: mengubah nilai tersimpan, atau menambah pada nilai yang sudah
                // diserahkan, adalah koreksi beralasan (status kembali draf).
                $koreksi=$ubahTersimpan||$terkunciDb($rows);
                if($koreksi&&$alasanKoreksi===''){$gagal[]=($nama[$sid]??'Santri').': mengubah nilai tersimpan memerlukan alasan koreksi.';continue;}
                try{$service->save($user,['rencana_mapel_id'=>$mapel,'santri_id'=>$sid,'komponen'=>array_values($items),'action'=>$koreksi?'koreksi':'draf','alasan'=>$koreksi?$alasanKoreksi:$alasanSesi],$pengganti);$ok++;}
                catch(V4Exception $ex){$gagal[]=($nama[$sid]??'Santri').': '.$ex->getMessage();}
            }
            if($gagal!==[])ah_flash_set($ok>0?'warning':'danger',$ok.' santri tersimpan · '.count($gagal).' belum tersimpan.','<ul class="mb-0 small">'.implode('',array_map(static fn($g)=>'<li>'.ah_e($g).'</li>',$gagal)).'</ul>');
            else ah_flash_set('success',$ok>0?'Draf nilai '.$ok.' santri tersimpan beserta riwayatnya.':'Tidak ada perubahan untuk disimpan.');
            ah_redirect($self(['rencana_mapel_id'=>$mapel,'kolom'=>$kolomMinta?:null]));
        }
        if($aksi==='serahkan_lengkap'){
            // Dengan JavaScript satu santri per permintaan (`pilih` = "id:tanda"); tanpa JavaScript
            // seluruh pilihan berurutan. Tanda memastikan nilai yang diserahkan sama dengan yang dilihat.
            $pilih=$_POST['pilih']??[];$pilih=is_string($pilih)?[$pilih]:(is_array($pilih)?$pilih:[]);
            $baca=$service->read($user,$mapel,0,$pengganti);
            $ids=array_map('intval',array_column($baca['santri'],'id'));$nama=array_column($baca['santri'],'nama_santri','id');
            $ok=0;$gagal=[];
            foreach($pilih as $p){
                if(!is_string($p)||!preg_match('/^(\d+):([a-f0-9]{64})$/D',$p,$m)){$gagal[]='Pilihan tidak valid.';continue;}
                $sid=(int)$m[1];
                try{
                    if(!in_array($sid,$ids,true))throw new V4Exception('Santri di luar kelas dan semester.',403);
                    $rows=$tampil->nilaiKelas($mapel,[$sid])[$sid]??[];
                    if(!hash_equals(BahanRepository::fingerprint($rows),$m[2]))throw new V4Exception('Nilai berubah sejak halaman dibuka. Muat ulang.',409);
                    $service->save($user,['rencana_mapel_id'=>$mapel,'santri_id'=>$sid,'komponen'=>array_values($muatanDb($rows)),'action'=>'serahkan','alasan'=>$alasanSesi],$pengganti);
                    $ok++;
                }catch(V4Exception $ex){
                    if($json)ah_json(['ok'=>false,'message'=>$ex->getMessage()],$ex->status);
                    $gagal[]=($nama[$sid]??'Santri').': '.$ex->getMessage();
                }
            }
            if($json)ah_json(['ok'=>true,'message'=>'Nilai diserahkan.']);
            if($gagal!==[])ah_flash_set($ok>0?'warning':'danger',$ok.' santri diserahkan · '.count($gagal).' gagal.','<ul class="mb-0 small">'.implode('',array_map(static fn($g)=>'<li>'.ah_e($g).'</li>',$gagal)).'</ul>');
            else ah_flash_set('success',$ok.' nilai santri diserahkan.');
            ah_redirect($self(['rencana_mapel_id'=>$mapel]));
        }
        // Bentuk lama/panel: satu santri, seluruh komponen (draf, serahkan, koreksi).
        $items=$_POST['komponen']??[];
        if(!is_array($items))throw new V4Exception('Komponen tidak valid.');
        // Baris baru kosong tidak disimpan; baris tersimpan tidak boleh dikosongkan/diabaikan.
        $items=array_values(array_filter($items,fn($r)=>!is_array($r)||($r['nilai']??'')!==''||(int)($r['version']??0)>0));
        $input=array_replace($_POST,['komponen'=>$items]);
        // Draf/serahkan memakai alasan sesi bila kolom alasan kosong; koreksi tetap wajib beralasan sendiri.
        if(trim((string)($input['alasan']??''))===''&&($input['action']??'draf')!=='koreksi')$input['alasan']=$alasanSesi;
        $service->save($user,$input,$pengganti);
        if($json)ah_json(['ok'=>true,'message'=>'Nilai tersimpan.']);
        ah_flash_set('success','Nilai tersimpan beserta riwayat revisinya.');
        ah_redirect($self(['rencana_mapel_id'=>$mapel,'santri_id'=>$santri]));
    }
    if($mapel){
        $current=$service->read($user,$mapel,0,$pengganti);
        $_SESSION['ah_rapor_mapel'][$pengganti?1:0]=$mapel;
        if($santri)$panel=$service->read($user,$mapel,$santri,$pengganti);
    }
} catch(V4Exception $ex) {
    if($json)ah_json(['ok'=>false,'message'=>$ex->getMessage()],$ex->status);
    http_response_code($ex->status);
    if($ex->status===403){\App\Ui\Denial::page($pengganti?'Nilai pengganti':'Nilai semester','guru mata pelajaran yang ditugaskan, atau Bagian Pendidikan untuk nilai pengganti',$ex->getMessage());}
    $error=$ex->getMessage();
    if($mapel){try{$current=$service->read($user,$mapel,0,$pengganti);if($santri)$panel=$service->read($user,$mapel,$santri,$pengganti);}catch(V4Exception){}}
}
$e=static fn($v)=>ah_e($v);
$alasanSesi=$mapel?rapor_alasan_sesi($jenisAlasan,$mapel):'';
$butuhAlasanSesi=$perluAlasan&&$alasanSesi==='';

// ---- Data kisi ---------------------------------------------------------------
$plan=$current['rencana_mapel']??null;
$siswa=$current['santri']??[];
$ids=array_map('intval',array_column($siswa,'id'));
$nilaiDb=$plan?$tampil->nilaiKelas((int)$plan['id'],$ids):[];
$rapor=$plan?$tampil->raporKelas((int)$plan['tahun_ajaran_id'],$ids):[];
$repo=new BahanRepository(app_db(),audit_logger());
$harian=1;foreach($nilaiDb as $rows)foreach($rows as $r)if($r['komponen']==='Harian')$harian=max($harian,(int)$r['nomor_harian']);
$harian=min(100,max($harian,$kolomMinta));
$target=(float)($plan['target_ketuntasan']??70);
$baris=[];$diserahkan=0;$belumLengkap=0;$siapSerah=[];
foreach($siswa as $s){
    $sid=(int)$s['id'];$rows=$nilaiDb[$sid]??[];$idx=[];foreach($rows as $r)$idx[$r['komponen'].':'.(int)$r['nomor_harian']]=$r;
    $hasil=NilaiService::calculate($rows,$target);
    $awal=$rows&&count(array_filter($rows,fn($r)=>$r['status']==='Dikirim'))===count($rows)?'Dikirim':'Draf';
    $state=$plan?$repo->state((int)$plan['rencana_id'],$sid,'nilai',(int)$plan['id'],$rows,$awal):['status'=>'Draf','perlu_verifikasi_ulang'=>false];
    // Hanya rapor terbit yang mengunci kisi; nilai diserahkan tetap dapat dikoreksi dengan alasan.
    $kunci=($rapor[$sid]['status']??'')==='Terbit'?'Rapor terbit':'';
    $sudahSerah=$terkunciDb($rows);
    if(in_array($state['status'],['Dikirim','Terverifikasi'],true)&&!$state['perlu_verifikasi_ulang'])$diserahkan++;
    if($hasil['kelengkapan']!=='Lengkap')$belumLengkap++;
    $bisaSerah=$kunci===''&&$rows!==[]&&!$sudahSerah&&$hasil['kelengkapan']==='Lengkap';
    if($bisaSerah)$siapSerah[]=['id'=>$sid,'nama'=>(string)$s['nama_santri'],'tanda'=>BahanRepository::fingerprint($rows)];
    $baris[]=['santri'=>$s,'idx'=>$idx,'hasil'=>$hasil,'state'=>$state,'kunci'=>$kunci,'diserahkan'=>$sudahSerah,'kosong'=>$rows===[]];
}
$judul=$plan?($plan['mata_pelajaran'].' · '.$plan['nama_kelas']):($pengganti?'Nilai pengganti':'Nilai semester');
$serahkanForm='';
if($plan&&$siapSerah!==[]&&!$butuhAlasanSesi){
    $serahkanForm='<form method="post" id="serahkan-form" class="d-inline" action="'.$e(app_url('/portal/nilai_semester.php')).'" data-rapor-massal="'.$e('Menyerahkan '.count($siapSerah).' nilai').'">'.ah_csrf()
        .'<input type="hidden" name="aksi" value="serahkan_lengkap"><input type="hidden" name="pengganti" value="'.($pengganti?'1':'0').'"><input type="hidden" name="rencana_mapel_id" value="'.(int)$plan['id'].'">';
    foreach($siapSerah as $s)$serahkanForm.='<input type="hidden" name="pilih[]" value="'.$s['id'].':'.$e($s['tanda']).'" data-nama="'.$e($s['nama']).'">';
    $serahkanForm.='<button class="btn btn-primary" type="submit" data-rapor-serahkan-btn>Serahkan yang lengkap ('.count($siapSerah).')</button></form>';
}
ah_page_open(['title'=>$judul,'user'=>$user,'active'=>$pengganti?'pengganti':'nilai','actions'=>$serahkanForm,'wide'=>true,
    'breadcrumbs'=>[['label'=>'Beranda','url'=>app_url('/portal/index.php')],['label'=>$pengganti?'Nilai pengganti':'Nilai semester','url'=>$self()],['label'=>$plan?$judul:'Pilih mapel']],
    'description'=>$plan?('Target ketuntasan '.rapor_angka($plan['target_ketuntasan']).' · '.count($siswa).' santri · '.$diserahkan.' diserahkan · '.rapor_semester($plan)):'Pilih mapel dan kelas yang Anda ampu.']);
?>
<form method="get" class="ah-ctx ah-no-print" aria-label="Konteks mapel dan kelas"><b class="ah-ctx__lead">Konteks:</b>
    <input type="hidden" name="pengganti" value="<?= $pengganti?'1':'0' ?>">
    <label class="ah-ctx__field"><span class="ah-visually-hidden">Mapel, kelas, dan semester</span><select class="form-select form-select-sm" name="rencana_mapel_id" data-ah-autosubmit>
        <?php if(!$mapel): ?><option value="">Pilih mapel</option><?php endif ?>
        <?php $grup=null;foreach($options as $o):$g=rapor_semester($o);if($g!==$grup){if($grup!==null)echo '</optgroup>';echo '<optgroup label="'.$e($g).'">';$grup=$g;} ?>
            <option value="<?= (int)$o['id'] ?>" <?= $mapel===(int)$o['id']?'selected':'' ?>><?= $e($o['mata_pelajaran'].' · '.$o['nama_kelas']) ?></option>
        <?php endforeach;if($grup!==null)echo '</optgroup>'; ?>
    </select></label>
    <button class="btn btn-sm btn-outline-primary ah-ctx__go" type="submit">Buka</button><span class="ah-ctx__note">Tersimpan untuk sesi ini</span></form>
<?php if(isset($error)): ?><div class="ah-note ah-note--danger" role="alert"><i class="fas fa-circle-exclamation mt-1" aria-hidden="true"></i><div><strong class="ah-note__title">Nilai belum tersimpan.</strong><span><?= $e($error) ?> Data tersimpan terbaru ditampilkan.</span></div></div><?php endif ?>
<?php if(!$options): ?>
    <section class="ah-card"><div class="ah-card__body"><?= ah_empty('Belum ada mapel','Belum ada rencana mapel dalam penugasan Anda. Rencana disusun Bagian Pendidikan.') ?></div></section>
<?php elseif($plan): ?>
<?php if($perluAlasan): $guru=$tampil->penugasanGuru([(int)($plan['guru_mapel_assignment_id']??0)])[(int)($plan['guru_mapel_assignment_id']??0)]??null; ?>
    <?php if($alasanSesi!==''): ?>
        <div class="ah-note ah-note--warn" role="note"><i class="fas fa-user-pen mt-1" aria-hidden="true"></i><div>
            <strong class="ah-note__title"><?= $pengganti?'Anda mengisi atas nama '.$e($guru['nama_guru']??'guru pengampu').' ('.$e($judul).').':'Anda bertindak sebagai admin pada '.$e($judul).'.' ?></strong>
            <span>Alasan: <?= $e($alasanSesi) ?> · tercatat pada setiap perubahan.</span>
            <?php ah_action_dialog_open('ganti-alasan','Ganti alasan','Ganti alasan sesi','link btn-sm px-0') ?>
                <form method="post"><?= ah_csrf() ?><input type="hidden" name="aksi" value="alasan_sesi"><input type="hidden" name="pengganti" value="<?= $pengganti?'1':'0' ?>"><input type="hidden" name="rencana_mapel_id" value="<?= (int)$plan['id'] ?>">
                    <label class="form-label" for="alasan-ganti">Alasan</label><textarea class="form-control" id="alasan-ganti" name="alasan_sesi" maxlength="1000" required><?= $e($alasanSesi) ?></textarea>
                    <div class="ah-act__actions"><button class="btn btn-primary">Simpan alasan</button></div></form>
            <?php ah_action_dialog_close() ?>
        </div></div>
    <?php else: ?>
        <section class="ah-card" aria-labelledby="ah-alasan-sesi"><div class="ah-card__head"><h2 class="h6 mb-0" id="ah-alasan-sesi"><?= $pengganti?'Mulai sesi nilai pengganti':'Alasan tindakan admin' ?></h2></div>
            <div class="ah-card__body"><p class="small"><?= $pengganti?'Anda akan mengisi atas nama '.$e($guru['nama_guru']??'guru pengampu').' ('.$e($judul).'). Penugasan guru tidak berubah.':'Perubahan nilai oleh admin wajib beralasan.' ?> Alasan diisi sekali dan tercatat pada setiap perubahan mapel ini selama sesi.</p>
            <form method="post" class="ah-inline-form"><?= ah_csrf() ?><input type="hidden" name="aksi" value="alasan_sesi"><input type="hidden" name="pengganti" value="<?= $pengganti?'1':'0' ?>"><input type="hidden" name="rencana_mapel_id" value="<?= (int)$plan['id'] ?>">
                <label class="form-label" for="alasan-sesi">Alasan <span class="text-danger">*</span></label>
                <textarea class="form-control" id="alasan-sesi" name="alasan_sesi" maxlength="1000" rows="2" required placeholder="Mis. guru cuti sakit sejak 1 Oktober"></textarea>
                <button class="btn btn-primary mt-2">Mulai mengisi</button></form></div></section>
    <?php endif ?>
<?php endif ?>
<?php if($siswa===[]): ?>
    <section class="ah-card"><div class="ah-card__body"><?= ah_empty('Belum ada santri','Belum ada penempatan santri pada kelas dan semester ini.') ?></div></section>
<?php else: ?>
<div class="ah-kisi-tools ah-no-print">
    <span class="small text-muted">Nilai 0–100, boleh dua desimal. Kosong = belum diisi; 0 adalah nilai sah. Nilai akhir = 40% rata-rata harian + 30% tengah + 30% akhir.</span>
    <?php if($harian<100): ?><a class="btn btn-sm btn-outline-secondary" href="<?= $e($self(['rencana_mapel_id'=>(int)$plan['id'],'kolom'=>$harian+1])) ?>"><i class="fas fa-plus" aria-hidden="true"></i> Kolom harian</a><?php endif ?>
</div>
<form method="post" id="kisi-form" data-rapor-kisi data-target="<?= $e($target) ?>">
<?= ah_csrf() ?><input type="hidden" name="aksi" value="kisi"><input type="hidden" name="pengganti" value="<?= $pengganti?'1':'0' ?>"><input type="hidden" name="rencana_mapel_id" value="<?= (int)$plan['id'] ?>"><?php if($kolomMinta): ?><input type="hidden" name="kolom" value="<?= $kolomMinta ?>"><?php endif ?>
<fieldset<?= $butuhAlasanSesi?' disabled':'' ?>><legend class="ah-visually-hidden">Kisi nilai <?= $e($judul) ?></legend>
<div class="ah-table-wrap ah-kisi-wrap"><table class="ah-table ah-kisi" data-rapor-pager>
    <caption class="ah-visually-hidden">Nilai <?= $e($judul) ?>: baris santri, kolom komponen</caption>
    <thead><tr><th scope="col" class="ah-kisi__nama">Santri</th>
        <?php for($h=1;$h<=$harian;$h++): ?><th scope="col">Harian <?= $h ?></th><?php endfor ?>
        <th scope="col">Tengah</th><th scope="col">Akhir</th><th scope="col">Nilai akhir</th><th scope="col">Predikat</th><th scope="col">Status</th></tr></thead>
    <tbody>
    <?php foreach($baris as $no=>$b): $sid=(int)$b['santri']['id'];$nm=(string)$b['santri']['nama_santri'];$ro=$b['kunci']!=='';
        $sel=static function(string $key,string $label)use($b,$sid,$ro,$e,$nm):string{
            $r=$b['idx'][$key]??null;$v=$r===null?'':rapor_angka($r['nilai']);if($r!==null&&$v==='')$v='0';
            $id='n-'.$sid.'-'.str_replace(':','-',$key);
            return '<td data-label="'.$e($label).'"><label class="ah-visually-hidden" for="'.$id.'">'.$e($label.' '.$nm).'</label>'
                .'<input class="form-control ah-input-num" id="'.$id.'" name="n['.$sid.']['.$e($key).']" value="'.$e($v).'" data-asal="'.$e($v).'" inputmode="decimal" autocomplete="off" pattern="\d{1,3}([.,]\d{1,2})?" data-komponen="'.$e(explode(':',$key)[0]).'"'.($ro?' readonly aria-readonly="true"':'').'>'
                .($r!==null?'<input type="hidden" name="ver['.$sid.']['.$e($key).']" value="'.(int)$r['version'].'">':'').'</td>';
        };
        $st=$b['state']; ?>
        <tr data-santri="<?= $sid ?>"<?= $b['diserahkan']?' data-diserahkan':'' ?><?= $ro?' class="is-locked"':'' ?>>
            <th scope="row" class="ah-kisi__nama" data-label="Santri"><a href="<?= $e($self(['rencana_mapel_id'=>(int)$plan['id'],'santri_id'=>$sid,'kolom'=>$kolomMinta?:null])) ?>#ah-panel"><?= $e($nm) ?></a>
                <span class="ah-kisi__pos small text-muted"><?= $no+1 ?> dari <?= count($baris) ?></span></th>
            <?php for($h=1;$h<=$harian;$h++)echo $sel('Harian:'.$h,'Harian '.$h); ?>
            <?= $sel('Tengah:0','Tengah') ?><?= $sel('Akhir:0','Akhir') ?>
            <td data-label="Nilai akhir" data-rapor-akhir><?= $b['hasil']['nilai_tampil']===null?'<span class="text-muted">—</span>':(int)$b['hasil']['nilai_tampil'] ?></td>
            <td data-label="Predikat" data-rapor-predikat><?= $e($b['hasil']['predikat']??'—') ?></td>
            <td data-label="Status"><?php
                if($b['kunci']==='Rapor terbit')echo ah_badge('Rapor terbit','ok');
                elseif($b['kosong'])echo ah_badge('Belum diisi','muted');
                else{echo rapor_status_badge($st['status']);if($st['status']==='Draf'&&$b['hasil']['kelengkapan']!=='Lengkap')echo ' '.ah_badge('Belum lengkap','warn');}
                if($st['perlu_verifikasi_ulang'])echo ' '.ah_badge('Perlu diserahkan ulang','warn'); ?></td>
        </tr>
    <?php endforeach ?>
    </tbody></table></div>
<div class="ah-koreksi" data-rapor-koreksi>
    <label class="form-label" for="alasan-koreksi-kisi">Alasan koreksi <span class="text-muted fw-normal small">— wajib bila mengubah nilai yang sudah tersimpan atau menambah nilai pada santri yang sudah diserahkan. Nilai yang dikoreksi kembali menjadi draf dan perlu diserahkan ulang.</span></label>
    <textarea class="form-control" id="alasan-koreksi-kisi" name="alasan_koreksi" maxlength="1000" rows="2"></textarea>
</div>
</fieldset>
<div class="ah-actbar ah-actbar--kisi ah-no-print" data-rapor-sbar>
    <span class="ah-actbar__text" data-rapor-sbar-text><?= $belumLengkap ?> santri belum lengkap · <?= $diserahkan ?> diserahkan</span>
    <div class="ah-actbar__btns">
        <button class="btn btn-outline-primary" type="submit"<?= $butuhAlasanSesi?' disabled':'' ?>>Simpan draf</button>
        <?php if($serahkanForm!==''): ?><button class="btn btn-primary" type="submit" form="serahkan-form" data-rapor-serahkan-btn>Serahkan <?= count($siapSerah) ?> nilai</button><?php endif ?>
    </div>
</div>
</form>
<div id="rapor-bulk" class="ah-no-print"></div>
<p class="small text-muted">Klik nama santri untuk catatan capaian per komponen, riwayat revisi, dan koreksi beralasan. Nilai yang sudah diserahkan terkunci di kisi.</p>
<?php endif ?>
<?php if($panel): $p=$panel;$rows=$p['nilai'];$keys=[];$last=0;foreach($rows as $r){$keys[$r['komponen']]=true;$last=max($last,(int)$r['nomor_harian']);}
    $rows[]=['komponen'=>'Harian','nomor_harian'=>$last+1,'version'=>0,'nilai'=>'','catatan'=>'','status'=>null];
    foreach(['Tengah','Akhir'] as $kind)if(!isset($keys[$kind]))$rows[]=['komponen'=>$kind,'nomor_harian'=>0,'version'=>0,'nilai'=>'','catatan'=>'','status'=>null];
    $namaPanel='';foreach($siswa as $s)if((int)$s['id']===$santri)$namaPanel=(string)$s['nama_santri'];
    $diserahkanPanel=$terkunciDb($p['nilai']);
    $pelaku=$tampil->namaPengguna(array_merge(array_column($p['riwayat'],'pelaku_user_id'),array_map(static fn($h)=>(int)(json_decode((string)$h['snapshot_json'],true)['actor_user_id']??0),$p['riwayat_pemeriksaan'])));
    ah_panel_open(['title'=>$namaPanel,'description'=>$judul.' · '.($p['hasil']['nilai_tampil']===null?'Belum lengkap':'Nilai akhir '.$p['hasil']['nilai_tampil'].' · '.$p['hasil']['predikat']),'close_url'=>$self(['rencana_mapel_id'=>(int)$plan['id'],'kolom'=>$kolomMinta?:null])]); ?>
    <p>Status: <?= rapor_status_badge($p['pemeriksaan']['status']) ?><?= $p['pemeriksaan']['perlu_verifikasi_ulang']?' '.ah_badge('Sumber berubah · serahkan ulang','warn'):'' ?></p>
    <?php if($p['pemeriksaan']['alasan']): ?><?php ah_note('info','Catatan pemeriksaan: '.$p['pemeriksaan']['alasan']) ?><?php endif ?>
    <form method="post" id="panel-nilai"><?= ah_csrf() ?><input type="hidden" name="pengganti" value="<?= $pengganti?'1':'0' ?>"><input type="hidden" name="rencana_mapel_id" value="<?= (int)$plan['id'] ?>"><input type="hidden" name="santri_id" value="<?= $santri ?>">
    <?php foreach($rows as $i=>$r): $label=$r['komponen']==='Harian'?'Harian '.(int)$r['nomor_harian']:$r['komponen']; $baru=(int)$r['version']===0; ?>
        <fieldset class="ah-komponen"><legend class="h6"><?= $e($label) ?><?= $baru&&$r['komponen']==='Harian'&&$r['nomor_harian']>1?' <span class="text-muted small fw-normal">(baru, opsional)</span>':'' ?> <?= $r['status']?rapor_status_badge($r['status']):'' ?></legend>
            <input type="hidden" name="komponen[<?= $i ?>][komponen]" value="<?= $e($r['komponen']) ?>"><input type="hidden" name="komponen[<?= $i ?>][nomor_harian]" value="<?= (int)$r['nomor_harian'] ?>"><input type="hidden" name="komponen[<?= $i ?>][version]" value="<?= (int)$r['version'] ?>">
            <div class="ah-komponen__grid"><label>Nilai<input class="form-control ah-input-num" inputmode="decimal" type="number" min="0" max="100" step="0.01" name="komponen[<?= $i ?>][nilai]" value="<?= $e($r['nilai']) ?>"></label>
            <label>Catatan capaian<textarea class="form-control" rows="2" maxlength="2000" name="komponen[<?= $i ?>][catatan]"><?= $e($r['catatan']) ?></textarea></label></div>
        </fieldset>
    <?php endforeach ?>
    <div class="ah-act__actions">
        <?php if(!$diserahkanPanel): ?>
            <button class="btn btn-outline-primary" name="action" value="draf"<?= $butuhAlasanSesi?' disabled':'' ?>>Simpan draf</button>
            <button class="btn btn-primary" name="action" value="serahkan"<?= $butuhAlasanSesi?' disabled':'' ?>>Serahkan</button>
        <?php endif ?>
    </div>
    <?php if($p['nilai']!==[]): ?>
        <?php ah_note('info',$diserahkanPanel?'Nilai sudah diserahkan. Untuk mengubahnya, ubah isian di atas lalu pilih "Koreksi" dan tulis alasannya; status kembali menjadi draf dan perlu diserahkan ulang.':'Isian baru tersimpan dengan "Simpan draf". Mengubah nilai atau catatan yang sudah tersimpan memakai "Koreksi" beralasan.') ?>
        <?php ah_action_dialog_open('koreksi-nilai','Koreksi…','Koreksi nilai '.$namaPanel,'outline-primary') ?>
            <label class="form-label" for="alasan-koreksi">Alasan koreksi <span class="text-danger">*</span></label>
            <textarea class="form-control" id="alasan-koreksi" name="alasan" maxlength="1000" required form="panel-nilai"></textarea>
            <div class="ah-act__actions"><button class="btn btn-primary" name="action" value="koreksi" form="panel-nilai">Simpan koreksi</button></div>
        <?php ah_action_dialog_close() ?>
    <?php endif ?>
    </form>
    <h3 class="h6 mt-4">Riwayat revisi</h3>
    <?= ah_timeline(array_map(static function($h)use($pelaku){$s=json_decode((string)$h['snapshot_json'],true)?:[];$label=($s['komponen']??'')==='Harian'?'Harian '.(int)($s['nomor_harian']??0):(string)($s['komponen']??'');
        return ['waktu'=>rapor_waktu($h['created_at']),'pelaku'=>($pelaku[(int)$h['pelaku_user_id']]??'Pengguna').' ('.rapor_kapasitas($h['kapasitas']).')','judul'=>'Versi '.(int)$h['version'].' · '.$label.': '.($s['nilai']??'—').' · '.rapor_status_label($s['status']??''),
            'isi'=>trim((($s['catatan']??'')!==''?'Catatan: '.$s['catatan']."\n":'').(trim((string)$h['alasan'])!==''?'Alasan: '.$h['alasan']:''))];},array_reverse($p['riwayat'])),'Riwayat revisi nilai') ?>
    <?php if($p['riwayat_pemeriksaan']): ?><h3 class="h6 mt-4">Riwayat pemeriksaan</h3>
    <?= ah_timeline(array_map(static function($h)use($pelaku){$s=json_decode((string)$h['snapshot_json'],true)?:[];
        return ['waktu'=>rapor_waktu($s['updated_at']??null),'pelaku'=>($pelaku[(int)($s['actor_user_id']??0)]??'Pengguna').' ('.rapor_kapasitas($s['kapasitas']??'').')','judul'=>rapor_status_label($s['status']??''),'isi'=>trim((string)($s['alasan']??''))!==''?'Alasan: '.$s['alasan']:''];},array_reverse($p['riwayat_pemeriksaan'])),'Riwayat pemeriksaan') ?><?php endif ?>
<?php ah_panel_close(); endif ?>
<?php endif ?>
<script src="<?= $e(\App\Ui\Layout::asset('/assets/ui/rapor.js')) ?>" defer></script>
<?php ah_page_close(); ?>
