<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once __DIR__.'/_rapor_ui.php';
use App\V4\{RaporTampilan,RencanaService,VerifikasiService,V4Exception};
use App\Http\Csrf;

/*
 * R-7 Penanda tangan rapor — redesain website Tahap 7 (khusus admin).
 *
 * Kartu penanda tangan dengan pratinjau gambar (jalur berpenjaga
 * `rapor_ttd.php`), tambah lewat panel, aktif/nonaktif lewat dialog. Kartu
 * "Ganti penanda tangan pada rencana yang sudah dinilai" memakai jalur khusus
 * admin beralasan `RencanaService::changeSigners()` (keputusan Human
 * Developer 16 Oktober 2026): berlaku untuk penerbitan berikutnya, rapor
 * terbit tidak berubah. Riwayat ditulis sebagai kalimat dengan nama.
 */
header('Cache-Control: private, no-store');$user=authorization()->requireWebUser();
$svc=new RencanaService(app_db(),capabilities(),audit_logger());$tampil=new RaporTampilan(app_db());
$minta=(int)($_POST['rencana_id']??$_GET['rencana_id']??0);
$options=['penanda_tangan'=>[],'aset'=>[]];$plans=[];$current=null;$plan=0;$cocok=true;$history=[];
$kembali=static fn(array $q=[]):string=>app_url('/portal/rapor_penanda.php').(($q=array_filter($q))?'?'.http_build_query($q):'');
try{
 $svc->signers($user);$options=$svc->options($user);
 if($_SERVER['REQUEST_METHOD']==='POST'){
  Csrf::requireValid(is_string($_POST['_csrf']??null)?$_POST['_csrf']:null);
  $aksi=(string)($_POST['action']??'');
  if($aksi==='signer'){
   try{$svc->saveSigner($user,$_POST,$_FILES['gambar']??null);}
   catch(V4Exception $ex){ah_flash_set('danger',$ex->getMessage());ah_redirect($kembali(['tambah'=>1,'rencana_id'=>$minta?:null]));}
   ah_flash_set('success','Penanda tangan '.trim((string)($_POST['nama']??'')).' dan gambar tanda tangannya tersimpan privat.');
   ah_redirect($kembali(['rencana_id'=>$minta?:null]));
  }
  if($aksi==='signer_status'){
   $svc->setSignerActive($user,(int)($_POST['id']??0),($_POST['is_active']??'')==='1');
   ah_flash_set('success',($_POST['is_active']??'')==='1'?'Penanda tangan diaktifkan.':'Penanda tangan dinonaktifkan; rencana dan rapor terbit yang memakainya tidak berubah.');
   ah_redirect($kembali(['rencana_id'=>$minta?:null]));
  }
  $input=$_POST;
  foreach(['pendidikan','pimpinan'] as $prefix){
   $pair=$_POST[$prefix.'_pasangan']??null;if(!is_string($pair))continue;
   if(!preg_match('/^(\d+):(\d+)$/D',$pair,$m))throw new V4Exception('Pilih penanda tangan '.rapor_jabatan(ucfirst($prefix)).' yang aktif.');
   $input[$prefix.'_penanda_id']=$m[1];$input[$prefix.'_aset_id']=$m[2];
  }
  $svc->changeSigners($user,$input);
  ah_flash_set('success','Penanda tangan dan tempat diperbarui dengan riwayat. Berlaku untuk penerbitan berikutnya; rapor terbit tidak berubah.');
  ah_redirect($kembali(['rencana_id'=>$minta]));
 }
 $plans=(new VerifikasiService(app_db(),audit_logger()))->options($user);
 $pilih=rapor_pilih_rencana($plans,$minta,(int)($_GET['tahun_ajaran_id']??0),(int)($_GET['kelas_id']??0));
 $plan=$pilih['id'];$cocok=$pilih['cocok'];
 foreach($plans as $p)if((int)$p['id']===$plan)$current=$svc->read($user,(int)$p['tahun_ajaran_id'],(int)$p['kelas_id'])['rencana'];
 if($current)$history=$tampil->riwayatPenanda((int)$current['id']);
}catch(V4Exception $e){http_response_code($e->status);if($e->status===403){\App\Ui\Denial::page('Penanda tangan rapor','admin',$e->getMessage());}$error=$e->getMessage();}
$e=static fn($v)=>ah_e($v);
$kartu=$tampil->penandaDenganAset(true);
$namaPenanda=$tampil->namaPenanda(array_merge(...array_map(static function($h){$s=json_decode((string)$h['snapshot_json'],true)?:[];return [$s['sebelum']['pendidikan_penanda_id']??0,$s['sesudah']['pendidikan_penanda_id']??0,$s['sebelum']['pimpinan_penanda_id']??0,$s['sesudah']['pimpinan_penanda_id']??0];},$history?:[['snapshot_json'=>'{}']])));
$aktor=$tampil->namaPengguna(array_column($history,'actor_user_id'));
[$opsiSemester,$opsiKelas]=rapor_konteks_opsi($plans);
ah_page_open(['title'=>'Penanda tangan','heading'=>'Penilaian & rapor','user'=>$user,'active'=>'rapor',
 'actions'=>'<a class="btn btn-primary" href="'.$e($kembali(['tambah'=>1,'rencana_id'=>$plan?:null])).'"><i class="fas fa-plus" aria-hidden="true"></i> Tambah penanda tangan</a>',
 'description'=>'Identitas dan gambar tanda tangan rapor. Gambar tersimpan privat dan hanya dipakai saat penerbitan.']);
?>
<?php if(isset($error)):?><div class="ah-note ah-note--danger" role="alert"><i class="fas fa-circle-exclamation mt-1" aria-hidden="true"></i><div><strong class="ah-note__title">Perubahan belum tersimpan.</strong><span><?= $e($error) ?></span></div></div><?php endif ?>
<?php if(($_GET['tambah']??'')==='1'): ah_panel_open(['title'=>'Tambah penanda tangan','description'=>'Unggah gambar hanya setelah persetujuan pemilik tanda tangan. Berkas tidak memiliki alamat publik.','close_url'=>$kembali(['rencana_id'=>$plan?:null])]); ?>
 <form method="post" enctype="multipart/form-data"><?= ah_csrf() ?><input type="hidden" name="action" value="signer"><input type="hidden" name="rencana_id" value="<?= $plan ?>">
  <div class="mb-3"><label class="form-label" for="jabatan">Jabatan</label><select class="form-select" id="jabatan" name="jabatan"><option value="Pendidikan">Bagian Pendidikan</option><option value="Pimpinan">Pimpinan pesantren</option></select></div>
  <div class="mb-3"><label class="form-label" for="nama">Nama <span class="text-danger" aria-hidden="true">*</span></label><input class="form-control" id="nama" name="nama" maxlength="150" required></div>
  <div class="mb-3"><label class="form-label" for="gelar">Gelar <span class="text-muted fw-normal">(opsional)</span></label><input class="form-control" id="gelar" name="gelar" maxlength="100"></div>
  <div class="mb-3"><label class="form-label" for="gambar">Gambar tanda tangan <span class="text-danger" aria-hidden="true">*</span></label><input class="form-control" type="file" id="gambar" name="gambar" accept="image/png,image/jpeg,image/webp" required aria-describedby="gambar-bantu"><p class="form-text" id="gambar-bantu">PNG, JPEG, atau WebP; maksimal 2 MB dan 3000 × 3000 piksel. Latar putih atau transparan.</p></div>
  <div class="ah-actbar mb-0"><a class="btn btn-outline-secondary" href="<?= $e($kembali(['rencana_id'=>$plan?:null])) ?>">Batal</a><button class="btn btn-primary">Simpan penanda tangan</button></div>
 </form>
<?php ah_panel_close(); endif ?>
<section aria-labelledby="ah-daftar-penanda">
 <h2 class="h6" id="ah-daftar-penanda">Penanda tangan</h2>
 <?php if($kartu===[]):?><div class="ah-card"><div class="ah-card__body"><?= ah_empty('Belum ada penanda tangan','Tambahkan penanda tangan Bagian Pendidikan dan Pimpinan pesantren sebelum rapor diterbitkan.') ?></div></div><?php endif ?>
 <div class="ah-signer-cards">
 <?php foreach($kartu as $k): $aktif=(int)$k['is_active']===1; ?>
  <article class="ah-card ah-signer-card<?= $aktif?'':' ah-row-muted' ?>">
   <div class="ah-ttd ah-ttd--card"><?php if($k['aset_id']):?><img src="<?= $e(rapor_ttd_url((int)$k['aset_id'])) ?>" alt="Tanda tangan <?= $e($k['nama']) ?>" width="200" height="70" loading="lazy"><?php else:?><span class="text-muted small">Belum ada gambar</span><?php endif ?></div>
   <div class="ah-card__body">
    <h3 class="h6 mb-1"><?= $e(trim($k['nama'].' '.($k['gelar']??''))) ?></h3>
    <p class="small text-muted mb-2"><?= $e(rapor_jabatan($k['jabatan'])) ?> · <?= ah_badge($aktif?'Aktif':'Nonaktif',$aktif?'ok':'muted') ?><?= $aktif&&!$k['aset_berlaku']?' '.ah_badge('Gambar tidak berlaku','warn'):'' ?></p>
    <?php if($k['aset_diunggah']):?><p class="small text-muted mb-2">Gambar diunggah <?= $e(rapor_waktu($k['aset_diunggah'])) ?></p><?php endif ?>
    <form method="post" data-confirm="<?= $e($aktif?'Penanda tangan nonaktif tidak dapat dipilih untuk rencana baru. Rencana dan rapor terbit yang memakainya tidak berubah; penerbitan berikutnya memerlukan penanda tangan aktif.':'Penanda tangan kembali dapat dipilih pada rencana.') ?>" data-confirm-title="<?= $e(($aktif?'Nonaktifkan ':'Aktifkan ').$k['nama'].'?') ?>" data-confirm-ok="<?= $aktif?'Nonaktifkan':'Aktifkan' ?>">
     <?= ah_csrf() ?><input type="hidden" name="action" value="signer_status"><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><input type="hidden" name="is_active" value="<?= $aktif?'0':'1' ?>"><input type="hidden" name="rencana_id" value="<?= $plan ?>">
     <button class="btn btn-sm btn-outline-<?= $aktif?'secondary':'primary' ?>"><?= $aktif?'Nonaktifkan':'Aktifkan' ?></button></form>
   </div>
  </article>
 <?php endforeach ?>
 </div>
</section>
<section class="ah-card mt-4" aria-labelledby="ah-ganti-penanda">
 <div class="ah-card__head"><h2 class="h6 mb-0" id="ah-ganti-penanda">Ganti penanda tangan pada rencana yang sudah dinilai</h2></div>
 <div class="ah-card__body">
 <?php if($plans!==[]):?><?= rapor_konteks_bar($opsiSemester,(int)($current['tahun_ajaran_id']??rapor_konteks()['tahun']),$opsiKelas,(int)($current['kelas_id']??rapor_konteks()['kelas'])) ?><?php endif ?>
 <?php if(!$cocok):?><?php ah_note('warning','Kelas ini belum punya rencana nilai pada semester tersebut.') ?><?php endif ?>
 <?php if($current): ?>
  <?php ah_note('info','Berlaku untuk penerbitan berikutnya. Rapor yang sudah terbit tidak berubah.') ?>
  <form method="post"><?= ah_csrf() ?><input type="hidden" name="rencana_id" value="<?= (int)$current['id'] ?>"><input type="hidden" name="version" value="<?= (int)$current['version'] ?>">
   <div class="ah-signer-grid">
   <?php foreach(['pendidikan'=>'Pendidikan','pimpinan'=>'Pimpinan'] as $prefix=>$jabatan):
    $selS=(int)($current[$prefix.'_penanda_id']??0);$selA=(int)($current[$prefix.'_aset_id']??0);$ada=false;
    foreach($options['aset'] as $a)if($a['jabatan']===$jabatan&&(int)$a['penanda_tangan_id']===$selS&&(int)$a['id']===$selA)$ada=true; ?>
    <div class="ah-signer"><label class="form-label" for="ganti-<?= $prefix ?>"><?= $e(rapor_jabatan($jabatan)) ?> <span class="text-danger" aria-hidden="true">*</span></label>
     <select class="form-select" id="ganti-<?= $prefix ?>" name="<?= $prefix ?>_pasangan" required data-rapor-ttd="ttd-ganti-<?= $prefix ?>">
      <option value="">Pilih penanda tangan</option>
      <?php foreach($options['aset'] as $a):if($a['jabatan']!==$jabatan)continue;?><option value="<?= (int)$a['penanda_tangan_id'].':'.(int)$a['id'] ?>" data-src="<?= $e(rapor_ttd_url((int)$a['id'])) ?>" <?= $ada&&(int)$a['id']===$selA?'selected':'' ?>><?= $e($a['nama']) ?></option><?php endforeach ?>
     </select>
     <?php if($selS&&!$ada):?><p class="small ah-field-warn mb-0 mt-1">Saat ini: <?= $e($namaPenanda[$selS]??$tampil->namaPenanda([$selS])[$selS]??'—') ?> (tidak lagi aktif).</p><?php endif ?>
     <div class="ah-ttd" id="ttd-ganti-<?= $prefix ?>"><?php if($ada):?><img src="<?= $e(rapor_ttd_url($selA)) ?>" alt="Pratinjau tanda tangan <?= $e(rapor_jabatan($jabatan)) ?>" width="180" height="70" loading="lazy"><?php endif ?></div></div>
   <?php endforeach ?>
    <div class="ah-signer"><label class="form-label" for="ganti-tempat">Tempat terbit <span class="text-danger" aria-hidden="true">*</span></label><input class="form-control" id="ganti-tempat" name="tempat" value="<?= $e($current['tempat']??'') ?>" maxlength="120" required></div>
   </div>
   <label class="form-label mt-3" for="ganti-alasan">Alasan perubahan <span class="text-danger" aria-hidden="true">*</span></label>
   <textarea class="form-control" id="ganti-alasan" name="alasan" maxlength="1000" rows="2" required placeholder="Mis. mutasi jabatan Kepala Bagian Pendidikan"></textarea>
   <button class="btn btn-primary mt-3">Simpan perubahan</button></form>
  <h3 class="h6 mt-4">Riwayat perubahan</h3>
  <?= ah_timeline(array_map(static function($h)use($namaPenanda,$aktor){
    $s=json_decode((string)$h['snapshot_json'],true)?:[];$a=$s['sebelum']??[];$b=$s['sesudah']??[];$bagian=[];
    foreach(['pendidikan'=>'Bagian Pendidikan','pimpinan'=>'Pimpinan'] as $k=>$label){
     $x=(int)($a[$k.'_penanda_id']??0);$y=(int)($b[$k.'_penanda_id']??0);$ax=(int)($a[$k.'_aset_id']??0);$ay=(int)($b[$k.'_aset_id']??0);
     if($x!==$y)$bagian[]=$label.': '.($namaPenanda[$x]??'—').' → '.($namaPenanda[$y]??'—');elseif($ax!==$ay)$bagian[]=$label.': gambar tanda tangan diganti';
    }
    if((string)($a['tempat']??'')!==(string)($b['tempat']??''))$bagian[]='Tempat: '.(($a['tempat']??'')?:'—').' → '.(($b['tempat']??'')?:'—');
    return ['waktu'=>rapor_waktu($h['created_at']),'pelaku'=>($aktor[(int)$h['actor_user_id']]??'Admin').' (admin)','judul'=>$bagian?implode(' · ',$bagian):'Disimpan tanpa perubahan isi','isi'=>'Alasan: '.$h['alasan']];
   },array_reverse($history)),'Riwayat perubahan penanda tangan') ?>
 <?php endif ?>
 </div>
</section>
<script src="<?= $e(\App\Ui\Layout::asset('/assets/ui/rapor.js')) ?>" defer></script>
<?php ah_page_close(); ?>
