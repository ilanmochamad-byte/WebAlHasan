<?php
declare(strict_types=1);
use App\V4\RaporTampilan;
use App\V4\RencanaService;
use App\V4\V4Exception;
use App\Http\Csrf;
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once __DIR__.'/_rapor_ui.php';

/*
 * R-1 Rencana nilai semester (redesain website Tahap 7).
 *
 * Tampilan saja: penyimpanan tetap `RencanaService::save()` (cakupan Bagian
 * Pendidikan per kelas & semester, bobot 40/30/30; mapel bernilai terkunci
 * targetnya dan tidak dapat dihapus — keputusan Human Developer 23 Oktober
 * 2026). "Salin dari semester lalu" hanya untuk admin (keputusan yang sama).
 * "Salin dari semester lalu" (keputusan X) hanya mengisi awal formulir dari
 * `RencanaService::read()` semester sebelumnya; tidak tersimpan sampai
 * "Simpan rencana". Kelola penanda tangan pindah ke tab Penanda tangan (R-7);
 * aksi POST lama `signer`/`signer_status` di halaman ini tetap diterima.
 */
$user=authorization()->requireWebUser();
$service=new RencanaService(app_db(),capabilities(),audit_logger());
$tampil=new RaporTampilan(app_db());
header('Cache-Control: private, no-store');
$tahun=(int)($_POST['tahun_ajaran_id']??$_GET['tahun_ajaran_id']??0);$kelas=(int)($_POST['kelas_id']??$_GET['kelas_id']??0);
$options=['tahun_ajaran'=>[],'kelas'=>[],'mata_pelajaran'=>[],'guru_assignments'=>[],'penanda_tangan'=>[],'aset'=>[]];
$current=null;$salinan=null;$salinCatatan=null;
try {
    $options=$service->options($user);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        Csrf::requireValid($_POST['_csrf']??null);
        if (($_POST['action']??'')==='signer') {
            $service->saveSigner($user,$_POST,$_FILES['gambar']??null);
            ah_flash_set('success','Penanda tangan dan aset privat tersimpan.');
        } elseif (($_POST['action']??'')==='signer_status') {
            $service->setSignerActive($user,(int)($_POST['id']??0),($_POST['is_active']??'')==='1');
            ah_flash_set('success','Status penanda tangan tersimpan.');
        } else {
            $subjectIds=$_POST['mata_pelajaran_id']??[];$targets=$_POST['target_ketuntasan']??[];$assignments=$_POST['guru_mapel_assignment_id']??[];$mapel=[];
            if (!is_array($subjectIds)||!is_array($targets)||!is_array($assignments)) throw new V4Exception('Baris mapel tidak valid.');
            // Mapel tersimpan yang dicentang "Hapus dari rencana" tidak dikirim; layanan menolak bila sudah bernilai.
            $hapus=is_array($_POST['hapus_mapel']??null)?array_map('strval',$_POST['hapus_mapel']):[];
            foreach($subjectIds as $i=>$id) if ((string)$id!==''&&!in_array((string)$id,$hapus,true)) $mapel[]=['mata_pelajaran_id'=>$id,'target_ketuntasan'=>$targets[$i]??70,'guru_mapel_assignment_id'=>$assignments[$i]??null];
            $input=$_POST;
            // Pilihan "nama + tanda tangan" (R-1) dikirim sebagai pasangan penanda:aset.
            foreach(['pendidikan','pimpinan'] as $prefix){
                $pair=$_POST[$prefix.'_pasangan']??null;
                if(!is_string($pair))continue;
                if($pair===''){$input[$prefix.'_penanda_id']='';$input[$prefix.'_aset_id']='';continue;}
                if(!preg_match('/^(\d+):(\d+)$/D',$pair,$m))throw new V4Exception('Pilihan penanda tangan tidak valid.');
                $input[$prefix.'_penanda_id']=$m[1];$input[$prefix.'_aset_id']=$m[2];
            }
            $service->save($user,$input+['mapel'=>$mapel]);
            rapor_konteks_simpan($tahun,$kelas);
            ah_flash_set('success','Rencana semester tersimpan dan dicatat pada audit.');
        }
        ah_redirect(app_url('/portal/rencana_nilai.php').'?tahun_ajaran_id='.urlencode((string)($_POST['tahun_ajaran_id']??($_GET['tahun_ajaran_id']??''))).'&kelas_id='.urlencode((string)($_POST['kelas_id']??($_GET['kelas_id']??''))));
    }
    // Konteks: alamat → sesi → semester aktif dan kelas pertama dalam cakupan.
    if($tahun<1||$kelas<1){
        $k=rapor_konteks();
        $tahunIds=array_map('intval',array_column($options['tahun_ajaran'],'id'));$kelasIds=array_map('intval',array_column($options['kelas'],'id'));
        if($tahun<1)$tahun=in_array($k['tahun'],$tahunIds,true)?$k['tahun']:(int)(array_values(array_filter($options['tahun_ajaran'],static fn($y)=>$y['status']==='Aktif'))[0]['id']??($tahunIds[0]??0));
        if($kelas<1)$kelas=in_array($k['kelas'],$kelasIds,true)?$k['kelas']:($kelasIds[0]??0);
    }
    if($tahun>0&&$kelas>0){
        $current=$service->read($user,$tahun,$kelas);
        rapor_konteks_simpan($tahun,$kelas);
        // Keputusan X: isi-awal dari rencana kelas yang sama semester sebelumnya.
        if(($_GET['salin']??'')==='1'&&$current['mapel']===[]){
            $lalu=$tampil->semesterSebelumnya($tahun);
            if($lalu===null){$salinCatatan='Tidak ada semester sebelumnya untuk disalin.';}
            else{
                try{$sumber=$service->read($user,(int)$lalu['id'],$kelas);}catch(V4Exception){$sumber=null;$salinCatatan='Rencana '.rapor_semester($lalu).' untuk kelas ini di luar penugasan Anda.';}
                if($sumber!==null&&$sumber['mapel']===[]){$salinCatatan='Kelas ini belum punya rencana pada '.rapor_semester($lalu).'.';}
                elseif($sumber!==null){$salinan=['dari'=>$lalu,'rencana'=>$sumber['rencana'],'mapel'=>$sumber['mapel']];}
            }
        }
    }
} catch(V4Exception $e) {
    http_response_code($e->status);
    if($e->status===403){\App\Ui\Denial::page('Rencana nilai','Bagian Pendidikan',$e->getMessage());}
    $error=$e->getMessage();$current=null;
    // Setelah simpan gagal, tampilkan kembali rencana tersimpan agar pengguna dapat memperbaiki isian.
    if($tahun>0&&$kelas>0){try{$current=$service->read($user,$tahun,$kelas);}catch(V4Exception){$current=null;}}
}
$e=static fn($v)=>ah_e($v);
$plan=$current['rencana']??null;
$bernilai=$plan?$tampil->mapelBernilai((int)$plan['id']):[];
// Keputusan Human Developer 23 Okt 2026: hanya mapel bernilai yang terkunci (target, hapus);
// penanda tangan dan tempat rencana bernilai diganti admin lewat R-7.
$adaNilai=$bernilai!==[];
// Guru pengampu: penugasan aktif semester & kelas ini, dengan nama dan guru_id.
$penugasanKelas=array_values(array_filter($options['guru_assignments'],static fn($a)=>(int)$a['tahun_ajaran_id']===$tahun&&(int)$a['kelas_id']===$kelas));
$guruPenugasan=$tampil->penugasanGuru(array_merge(array_column($penugasanKelas,'id'),array_column($current['mapel']??[],'guru_mapel_assignment_id'),array_column($salinan['mapel']??[],'guru_mapel_assignment_id')));
$rows=$current['mapel']??[];$catatanSalin=[];
if($salinan!==null){
    $rows=[];
    foreach($salinan['mapel'] as $m){
        $lama=$guruPenugasan[(int)$m['guru_mapel_assignment_id']]??null;$baru='';
        foreach($penugasanKelas as $a){if($lama!==null&&$lama['aktif']===true&&(int)$a['mata_pelajaran_id']===(int)$m['mata_pelajaran_id']&&($guruPenugasan[(int)$a['id']]['guru_id']??0)===$lama['guru_id']){$baru=(int)$a['id'];break;}}
        if($lama!==null&&$baru==='')$catatanSalin[(int)$m['mata_pelajaran_id']]=$lama['nama_guru'].($lama['aktif']?' belum ditugaskan pada semester ini':' sudah nonaktif').' — pilih guru pengganti.';
        $rows[]=['mata_pelajaran_id'=>$m['mata_pelajaran_id'],'mata_pelajaran'=>$m['mata_pelajaran'],'target_ketuntasan'=>$m['target_ketuntasan'],'guru_mapel_assignment_id'=>$baru];
    }
}
$signerSource=$plan??($salinan['rencana']??null);
$penanda=$tampil->penandaDenganAset(false);
$tahunLabel='';foreach($options['tahun_ajaran'] as $y)if((int)$y['id']===$tahun)$tahunLabel=rapor_semester($y);
$kelasLabel='';foreach($options['kelas'] as $k)if((int)$k['id']===$kelas)$kelasLabel=(string)$k['nama_kelas'];
// Tombol hanya bila rencana semester lalu boleh dibaca akun ini (cakupan `RencanaService::read`).
$lalu=$current!==null&&($current['mapel']??[])===[]&&$salinan===null?$tampil->semesterSebelumnya($tahun):null;
$bisaSalin=$lalu!==null&&capabilities()->featureAppliesToKelas($user,\App\Penugasan\PenugasanJenis::CAP_RAPOR_RENCANA,$kelas,(int)$lalu['id']);
$actions='';
if($bisaSalin)$actions.='<a class="btn btn-outline-primary" href="?'.$e(http_build_query(['tahun_ajaran_id'=>$tahun,'kelas_id'=>$kelas,'salin'=>1])).'"><i class="fas fa-copy" aria-hidden="true"></i> Salin dari semester lalu</a>';
if($current!==null)$actions.='<button class="btn btn-primary" type="submit" form="rencana-form">Simpan rencana</button>';
ah_page_open(['title'=>'Rencana','heading'=>'Penilaian & rapor','user'=>$user,'active'=>'rapor','actions'=>$actions,
    'description'=>'Mata pelajaran wajib, target ketuntasan, guru pengampu, dan penanda tangan rapor per kelas dan semester.']);
echo rapor_konteks_bar(array_map(static fn($y)=>['id'=>(int)$y['id'],'label'=>rapor_semester($y).($y['status']==='Aktif'?' · aktif':'')],$options['tahun_ajaran']),$tahun,
    array_map(static fn($k)=>['id'=>(int)$k['id'],'label'=>$k['nama_kelas'].' · '.$k['jenjang']],$options['kelas']),$kelas);
?>
<?php if(isset($error)): ?><div class="ah-note ah-note--danger" role="alert"><i class="fas fa-circle-exclamation mt-1" aria-hidden="true"></i><div><strong class="ah-note__title">Rencana belum tersimpan.</strong><span><?= $e($error) ?></span></div></div><?php endif ?>
<?php if($options['tahun_ajaran']===[]||$options['kelas']===[]): ?>
    <section class="ah-card"><div class="ah-card__body"><?= ah_empty('Belum ada kelas dalam cakupan','Penugasan Bagian Pendidikan Anda belum mencakup kelas aktif.') ?></div></section>
<?php elseif($current!==null): ?>
<p class="ah-plan-status">Status rencana:
    <?= $plan===null?ah_badge('Belum dibuat','muted'):ah_badge('Tersimpan','ok').($adaNilai?' '.ah_badge(count($bernilai).' mapel sudah bernilai','info'):'') ?>
    <span class="text-muted small"><?= $e($kelasLabel.' · '.$tahunLabel) ?><?= $plan?' · versi '.(int)$plan['version']:'' ?></span></p>
<?php if($salinCatatan!==null): ?><?php ah_note('warning',$salinCatatan) ?><?php endif ?>
<?php if($salinan!==null): ?><?php ah_note('info','Formulir diisi dari rencana '.$kelasLabel.' '.rapor_semester($salinan['dari']).'. Belum tersimpan sampai Anda menekan "Simpan rencana".') ?><?php endif ?>
<?php if($adaNilai): ?><?php ah_note('info','Mapel yang sudah memiliki nilai tidak dapat dihapus dan targetnya terkunci; guru pengampunya tetap dapat diganti. Mapel lain dan mapel baru tetap dapat diubah.','<p class="small mb-0 mt-1">Penanda tangan dan tempat terbit rencana yang sudah bernilai diganti admin dengan alasan di tab Penanda tangan; berlaku untuk penerbitan berikutnya.</p>') ?><?php endif ?>
<?php ah_note('info','Bobot nilai akhir: harian/praktik 40%, tengah semester 30%, akhir semester 30%.') ?>
<form method="post" id="rencana-form" data-ah-dirty><?= ah_csrf() ?><input type="hidden" name="tahun_ajaran_id" value="<?= $tahun ?>"><input type="hidden" name="kelas_id" value="<?= $kelas ?>">
<section class="ah-card" aria-labelledby="ah-mapel-wajib">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-mapel-wajib">Mata pelajaran wajib (<?= count($rows) ?>)</h2>
        <button type="button" class="btn btn-sm btn-outline-primary ah-js-only" data-rapor-tambah-mapel><i class="fas fa-plus" aria-hidden="true"></i> Tambah mapel</button></div>
    <div class="ah-table-wrap"><table class="ah-table ah-table--cards ah-plan-table">
        <caption class="ah-visually-hidden">Mata pelajaran wajib, target ketuntasan, dan guru pengampu</caption>
        <thead><tr><th scope="col">Mata pelajaran</th><th scope="col">Target ketuntasan</th><th scope="col">Guru pengampu</th></tr></thead>
        <tbody data-rapor-mapel-rows>
        <?php
        // Satu baris kosong tambahan agar mapel dapat ditambah tanpa JavaScript (baris kosong diabaikan).
        $tampilRows=array_merge($rows,array_fill(0,$rows===[]?3:1,['mata_pelajaran_id'=>'','target_ketuntasan'=>70,'guru_mapel_assignment_id'=>'']));
        foreach($tampilRows as $i=>$row):
            $mid=(int)($row['mata_pelajaran_id']??0);$tersimpan=isset($row['id']);$kunci=$tersimpan&&isset($bernilai[(int)$row['id']]);
            $guru=$guruPenugasan[(int)($row['guru_mapel_assignment_id']??0)]??null;
        ?>
            <tr<?= $mid===0?' data-rapor-baris-baru':'' ?>>
            <?php if($kunci): ?>
                <td data-label="Mata pelajaran"><?= $e($row['mata_pelajaran']??'') ?> <?= ah_badge('Sudah ada nilai','info') ?><input type="hidden" name="mata_pelajaran_id[]" value="<?= $mid ?>"></td>
                <td data-label="Target ketuntasan"><?= $e(rapor_angka($row['target_ketuntasan'])) ?><input type="hidden" name="target_ketuntasan[]" value="<?= $e(rapor_angka($row['target_ketuntasan'])) ?>"></td>
            <?php else: ?>
                <td data-label="Mata pelajaran"><label class="ah-visually-hidden" for="mp-<?= $i ?>">Mata pelajaran baris <?= $i+1 ?></label>
                    <select class="form-select" id="mp-<?= $i ?>" name="mata_pelajaran_id[]" data-rapor-mapel<?= $tersimpan?' required':'' ?>><option value=""><?= $tersimpan?'Pilih mapel':'— Tidak ditambah —' ?></option><?php foreach($options['mata_pelajaran'] as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $mid===(int)$m['id']?'selected':'' ?>><?= $e($m['nama']) ?></option><?php endforeach ?></select>
                    <?php if(isset($catatanSalin[$mid])): ?><p class="ah-field-warn small mb-0 mt-1"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> <?= $e($catatanSalin[$mid]) ?></p><?php endif ?>
                    <?php if($tersimpan): ?><label class="ah-hapus-mapel"><input class="form-check-input" type="checkbox" name="hapus_mapel[]" value="<?= $mid ?>" data-rapor-hapus> Hapus dari rencana saat disimpan</label><?php endif ?></td>
                <td data-label="Target ketuntasan"><label class="ah-visually-hidden" for="tg-<?= $i ?>">Target ketuntasan baris <?= $i+1 ?></label>
                    <input class="form-control ah-input-num" id="tg-<?= $i ?>" name="target_ketuntasan[]" type="number" min="0" max="100" step="0.01" inputmode="decimal" value="<?= $e(rapor_angka(($row['target_ketuntasan']??70))) ?>"></td>
            <?php endif ?>
                <td data-label="Guru pengampu"><label class="ah-visually-hidden" for="gr-<?= $i ?>">Guru pengampu baris <?= $i+1 ?></label>
                    <select class="form-select<?= isset($catatanSalin[$mid])?' is-warn':'' ?>" id="gr-<?= $i ?>" name="guru_mapel_assignment_id[]" data-rapor-guru<?= $kunci?' data-mapel-tetap="'.$mid.'"':'' ?>><option value="">Belum ditugaskan</option><?php foreach($penugasanKelas as $a): if($kunci&&(int)$a['mata_pelajaran_id']!==$mid)continue; ?><option value="<?= (int)$a['id'] ?>" data-mapel="<?= (int)$a['mata_pelajaran_id'] ?>" <?= (int)($row['guru_mapel_assignment_id']??0)===(int)$a['id']?'selected':'' ?>><?= $e($a['nama_guru'].' · '.$a['mata_pelajaran']) ?></option><?php endforeach ?></select></td>
            </tr>
        <?php endforeach ?>
        </tbody></table></div>
    <div class="ah-card__body pt-0"><p class="small text-muted mb-0">Guru pengampu boleh dikosongkan selama rencana disusun. Baris "— Tidak ditambah —" diabaikan. Mapel tersimpan yang belum bernilai dapat dihapus dari rencana.</p></div>
</section>
<section class="ah-card" aria-labelledby="ah-penanda">
    <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-penanda">Penanda tangan rapor</h2>
        <?php if(in_array('admin',$user['roles'],true)): ?><a class="small" href="<?= $e(app_url('/portal/rapor_penanda.php')) ?>">Kelola penanda tangan</a><?php endif ?></div>
    <div class="ah-card__body ah-signer-grid">
    <?php foreach(['pendidikan'=>'Pendidikan','pimpinan'=>'Pimpinan'] as $prefix=>$jabatan):
        $selSigner=(int)($signerSource[$prefix.'_penanda_id']??0);$selAset=(int)($signerSource[$prefix.'_aset_id']??0);
        $pilihan=array_values(array_filter($options['aset'],static fn($a)=>$a['jabatan']===$jabatan));
        $adaPilihan=false;foreach($pilihan as $a)if((int)$a['penanda_tangan_id']===$selSigner&&(int)$a['id']===$selAset)$adaPilihan=true;
        $gambarAwal=$adaPilihan?rapor_ttd_url($selAset):'';
    ?>
        <div class="ah-signer">
            <label class="form-label" for="ps-<?= $prefix ?>"><?= $e(rapor_jabatan($jabatan)) ?></label>
            <?php if($adaNilai): ?>
                <p class="mb-1"><?= $selSigner?$e($tampil->namaPenanda([$selSigner])[$selSigner]??'—'):'<span class="text-muted">Belum dipilih</span>' ?></p>
                <input type="hidden" name="<?= $prefix ?>_pasangan" value="<?= $selSigner&&$selAset?$selSigner.':'.$selAset:'' ?>">
                <?php if($selSigner&&!$adaPilihan): ?><p class="small ah-field-warn mb-0">Tidak lagi aktif — admin perlu menggantinya sebelum rapor terbit.</p><?php endif ?>
            <?php else: ?>
            <select class="form-select" id="ps-<?= $prefix ?>" name="<?= $prefix ?>_pasangan" data-rapor-ttd="ttd-<?= $prefix ?>">
                <option value="">Belum dipilih</option>
                <?php if($selSigner>0&&$selAset>0&&!$adaPilihan&&$plan!==null): ?><option value="<?= $selSigner.':'.$selAset ?>" selected><?= $e(($tampil->namaPenanda([$selSigner])[$selSigner]??'Penanda sebelumnya').' — tidak lagi aktif; ganti sebelum rapor terbit') ?></option><?php endif ?>
                <?php foreach($pilihan as $a): ?><option value="<?= (int)$a['penanda_tangan_id'].':'.(int)$a['id'] ?>" data-src="<?= $e(rapor_ttd_url((int)$a['id'])) ?>" <?= $adaPilihan&&(int)$a['id']===$selAset?'selected':'' ?>><?= $e($a['nama']) ?></option><?php endforeach ?>
            </select>
            <?php endif ?>
            <div class="ah-ttd" id="ttd-<?= $prefix ?>"><?php if($gambarAwal!==''): ?><img src="<?= $e($gambarAwal) ?>" alt="Pratinjau tanda tangan <?= $e(rapor_jabatan($jabatan)) ?>" width="180" height="70" loading="lazy"><?php else: ?><span class="text-muted small">Pratinjau tanda tangan tampil setelah dipilih.</span><?php endif ?></div>
        </div>
    <?php endforeach ?>
        <div class="ah-signer"><label class="form-label" for="tempat">Tempat terbit <span class="text-muted fw-normal">(opsional saat draf)</span></label>
            <input class="form-control" id="tempat" name="tempat" maxlength="120" value="<?= $e($signerSource['tempat']??'') ?>"<?= $adaNilai?' readonly':'' ?>></div>
    </div>
</section>
<div class="ah-actbar"><span class="ah-actbar__text small text-muted" data-rapor-dirty-text>Perubahan tersimpan setelah "Simpan rencana".</span><div class="ah-actbar__btns"><button class="btn btn-primary">Simpan rencana</button></div></div>
</form>
<template id="rapor-mapel-template"><tr data-rapor-baris-baru>
    <td data-label="Mata pelajaran"><label class="ah-visually-hidden" for="mp-__N__">Mata pelajaran baris baru</label><select class="form-select" id="mp-__N__" name="mata_pelajaran_id[]" data-rapor-mapel><option value="">— Tidak ditambah —</option><?php foreach($options['mata_pelajaran'] as $m): ?><option value="<?= (int)$m['id'] ?>"><?= $e($m['nama']) ?></option><?php endforeach ?></select></td>
    <td data-label="Target ketuntasan"><label class="ah-visually-hidden" for="tg-__N__">Target ketuntasan baris baru</label><input class="form-control ah-input-num" id="tg-__N__" name="target_ketuntasan[]" type="number" min="0" max="100" step="0.01" inputmode="decimal" value="70"></td>
    <td data-label="Guru pengampu"><label class="ah-visually-hidden" for="gr-__N__">Guru pengampu baris baru</label><select class="form-select" id="gr-__N__" name="guru_mapel_assignment_id[]" data-rapor-guru><option value="">Belum ditugaskan</option><?php foreach($penugasanKelas as $a): ?><option value="<?= (int)$a['id'] ?>" data-mapel="<?= (int)$a['mata_pelajaran_id'] ?>"><?= $e($a['nama_guru'].' · '.$a['mata_pelajaran']) ?></option><?php endforeach ?></select></td>
</tr></template>
<?php endif ?>
<script src="<?= $e(\App\Ui\Layout::asset('/assets/ui/rapor.js')) ?>" defer></script>
<?php ah_page_close(); ?>
