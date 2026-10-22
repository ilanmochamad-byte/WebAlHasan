<?php
declare(strict_types=1);
use App\V4\RencanaService;
use App\V4\V4Exception;
use App\Http\Csrf;
require_once dirname(__DIR__).'/app/bootstrap.php';
$user=authorization()->requireWebUser();
$service=new RencanaService(app_db(),capabilities(),audit_logger());
header('Cache-Control: private, no-store');
$tahun=(int)($_POST['tahun_ajaran_id']??$_GET['tahun_ajaran_id']??0);$kelas=(int)($_POST['kelas_id']??$_GET['kelas_id']??0);
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
            foreach($subjectIds as $i=>$id) if ((string)$id!=='') $mapel[]=['mata_pelajaran_id'=>$id,'target_ketuntasan'=>$targets[$i]??70,'guru_mapel_assignment_id'=>$assignments[$i]??null];
            $service->save($user,$_POST+['mapel'=>$mapel]);
            ah_flash_set('success','Rencana semester tersimpan dan dicatat pada audit.');
        }
        ah_redirect(app_url('/portal/rencana_nilai.php').'?tahun_ajaran_id='.urlencode((string)($_POST['tahun_ajaran_id']??($_GET['tahun_ajaran_id']??''))).'&kelas_id='.urlencode((string)($_POST['kelas_id']??($_GET['kelas_id']??''))));
    }
    $current=($tahun>0&&$kelas>0)?$service->read($user,$tahun,$kelas):['rencana'=>null,'mapel'=>[]];
    $signers=in_array('admin',$user['roles'],true)?$service->signers($user):[];
} catch(V4Exception $e) {
    http_response_code($e->status);
    if($e->status===403){\App\Ui\Denial::page('Rencana nilai','Bagian Pendidikan',$e->getMessage());}
    $error=$e->getMessage();$current=null;$options=$options??['tahun_ajaran'=>[],'kelas'=>[],'mata_pelajaran'=>[],'guru_assignments'=>[],'penanda_tangan'=>[],'aset'=>[]];
    // Setelah simpan gagal, tampilkan kembali rencana tersimpan agar pengguna dapat memperbaiki isian.
    if($tahun>0&&$kelas>0){try{$current=$service->read($user,$tahun,$kelas);}catch(V4Exception){$current=null;}}
    if(!isset($signers)){try{$signers=in_array('admin',$user['roles'],true)?$service->signers($user):[];}catch(V4Exception){$signers=[];}}
}
$e=static fn($v)=>ah_e($v);
ah_page_open(['title'=>'Rencana Nilai Semester','user'=>$user,'description'=>'Susun mata pelajaran wajib, target ketuntasan, dan penanda tangan sebelum pengisian nilai.']);
?>
<style>.v4-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:1rem}.v4-grid>*{min-width:0}.v4-grid input,.v4-grid select{width:100%;max-width:100%}.v4-row{border:1px solid #ddd;border-radius:8px;padding:1rem;margin:.75rem 0}.v4-actions{display:flex;flex-wrap:wrap;gap:.75rem}</style>
<?php if(isset($error)): ?><div class="alert alert-danger" role="alert"><?= $e($error) ?></div><?php endif ?>
<section class="card p-3 mb-3"><h2 class="h5">Pilih kelas dan semester</h2><form method="get" class="v4-grid">
<label>Semester<select class="form-select" name="tahun_ajaran_id" required><option value="">Pilih semester</option><?php foreach($options['tahun_ajaran'] as $y): ?><option value="<?= (int)$y['id'] ?>" <?= $tahun===(int)$y['id']?'selected':'' ?>><?= $e($y['tahun'].' '.$y['semester'].' · '.$y['status']) ?></option><?php endforeach ?></select></label>
<label>Kelas<select class="form-select" name="kelas_id" required><option value="">Pilih kelas</option><?php foreach($options['kelas'] as $k): ?><option value="<?= (int)$k['id'] ?>" <?= $kelas===(int)$k['id']?'selected':'' ?>><?= $e($k['nama_kelas'].' · '.$k['jenjang']) ?></option><?php endforeach ?></select></label><div class="align-self-end"><button class="btn btn-outline-primary">Buka rencana</button></div></form></section>
<?php if($tahun>0&&$kelas>0&&$current!==null): $plan=$current['rencana'];$rows=$current['mapel']?:[['mata_pelajaran_id'=>'','target_ketuntasan'=>70,'guru_mapel_assignment_id'=>''],['mata_pelajaran_id'=>'','target_ketuntasan'=>70,'guru_mapel_assignment_id'=>'']]; ?>
<section class="card p-3 mb-3"><h2 class="h5"><?= $plan?'Ubah rencana':'Rencana baru' ?></h2><p>Bobot tetap: harian/praktik 40%, tengah semester 30%, akhir semester 30%. Target dapat diubah sebelum nilai diisi.</p>
<form method="post"><?= ah_csrf() ?><input type="hidden" name="tahun_ajaran_id" value="<?= $tahun ?>"><input type="hidden" name="kelas_id" value="<?= $kelas ?>">
<div id="v4-mapel-rows"><?php foreach($rows as $i=>$row): ?><div class="v4-row v4-grid"><label>Mata pelajaran wajib<select class="form-select" name="mata_pelajaran_id[]" required><option value="">Pilih mapel</option><?php foreach($options['mata_pelajaran'] as $m): ?><option value="<?= (int)$m['id'] ?>" <?= (int)($row['mata_pelajaran_id']??0)===(int)$m['id']?'selected':'' ?>><?= $e($m['nama']) ?></option><?php endforeach ?></select></label><label>Target ketuntasan<input class="form-control" name="target_ketuntasan[]" type="number" min="0" max="100" step="0.01" value="<?= $e($row['target_ketuntasan']??70) ?>" required></label><label>Guru pengampu (opsional saat draf)<select class="form-select v4-guru" name="guru_mapel_assignment_id[]"><option value="">Belum ditugaskan</option><?php foreach($options['guru_assignments'] as $assignment): if((int)$assignment['tahun_ajaran_id']!==$tahun||(int)$assignment['kelas_id']!==$kelas)continue; ?><option value="<?= (int)$assignment['id'] ?>" data-mapel="<?= (int)$assignment['mata_pelajaran_id'] ?>" <?= (int)($row['guru_mapel_assignment_id']??0)===(int)$assignment['id']?'selected':'' ?>><?= $e($assignment['mata_pelajaran'].' · '.$assignment['nama_guru']) ?></option><?php endforeach ?></select></label></div><?php endforeach ?></div>
<div class="v4-actions"><button type="button" class="btn btn-outline-secondary" id="v4-add">Tambah mapel</button></div>
<h3 class="h6 mt-4">Penanda tangan</h3><p>Pilih pasangan identitas dan aset yang aktif. Aset tetap privat.</p><div class="v4-grid">
<?php foreach(['Pendidikan'=>'pendidikan','Pimpinan'=>'pimpinan'] as $label=>$prefix):
    $selectedSigner=(int)($plan[$prefix.'_penanda_id']??0);
    $selectedAsset=(int)($plan[$prefix.'_aset_id']??0);
    $activeSignerIds=array_map('intval',array_column($options['penanda_tangan'],'id'));
    $activeAssetIds=array_map('intval',array_column($options['aset'],'id'));
?><label><?= $label ?><select class="form-select" name="<?= $prefix ?>_penanda_id"><option value="">Belum dipilih</option>
<?php if($selectedSigner>0&&!in_array($selectedSigner,$activeSignerIds,true)): ?><option value="<?= $selectedSigner ?>" selected>Penanda tangan nonaktif #<?= $selectedSigner ?> — ganti sebelum menyimpan</option><?php endif ?>
<?php foreach($options['penanda_tangan'] as $s): if($s['jabatan']===$label): ?><option value="<?= (int)$s['id'] ?>" <?= $selectedSigner===(int)$s['id']?'selected':'' ?>><?= $e($s['nama']) ?></option><?php endif; endforeach ?></select></label>
<label>Aset <?= $label ?><select class="form-select" name="<?= $prefix ?>_aset_id"><option value="">Belum dipilih</option>
<?php if($selectedAsset>0&&!in_array($selectedAsset,$activeAssetIds,true)): ?><option value="<?= $selectedAsset ?>" selected>Aset nonaktif #<?= $selectedAsset ?> — ganti sebelum menyimpan</option><?php endif ?>
<?php foreach($options['aset'] as $a): if($a['jabatan']===$label): ?><option value="<?= (int)$a['id'] ?>" <?= $selectedAsset===(int)$a['id']?'selected':'' ?>><?= $e($a['nama'].' · aset #'.$a['id']) ?></option><?php endif; endforeach ?></select></label><?php endforeach ?>
<label>Tempat terbit (opsional)<input class="form-control" name="tempat" maxlength="120" value="<?= $e($plan['tempat']??'') ?>"></label></div><button class="btn btn-primary mt-3">Simpan rencana</button></form></section>
<script>function syncGuru(row){const id=row.querySelector('select[name="mata_pelajaran_id[]"]').value;const guru=row.querySelector('.v4-guru');for(const option of guru.options){if(option.value)option.hidden=option.dataset.mapel!==id;}if(guru.selectedOptions[0]?.hidden)guru.value='';}document.querySelectorAll('.v4-row').forEach(row=>{syncGuru(row);row.querySelector('select[name="mata_pelajaran_id[]"]').addEventListener('change',()=>syncGuru(row));});document.getElementById('v4-add').addEventListener('click',()=>{const box=document.getElementById('v4-mapel-rows');const row=box.querySelector('.v4-row').cloneNode(true);row.querySelector('select').value='';row.querySelector('input[type=number]').value='70';row.querySelector('.v4-guru').value='';box.appendChild(row);syncGuru(row);row.querySelector('select[name="mata_pelajaran_id[]"]').addEventListener('change',()=>syncGuru(row));});</script>
<?php endif ?>
<?php if(in_array('admin',$user['roles'],true)): ?><section class="card p-3"><h2 class="h5">Tambah penanda tangan</h2><p>Unggah gambar hanya setelah persetujuan pemilik tanda tangan. Berkas tidak memiliki URL publik.</p><form method="post" enctype="multipart/form-data" class="v4-grid"><?= ah_csrf() ?><input type="hidden" name="action" value="signer"><label>Jabatan<select class="form-select" name="jabatan"><option>Pendidikan</option><option>Pimpinan</option></select></label><label>Nama<input class="form-control" name="nama" maxlength="150" required></label><label>Gelar<input class="form-control" name="gelar" maxlength="100"></label><label>Gambar tanda tangan<input class="form-control" type="file" name="gambar" accept="image/png,image/jpeg,image/webp" required></label><div class="align-self-end"><button class="btn btn-outline-primary">Simpan penanda tangan</button></div></form><h3 class="h6 mt-4">Status penanda tangan</h3><?php foreach($signers as $signer): ?><form method="post" class="d-flex flex-wrap align-items-center gap-2 mb-2"><?= ah_csrf() ?><input type="hidden" name="action" value="signer_status"><input type="hidden" name="id" value="<?= (int)$signer['id'] ?>"><input type="hidden" name="is_active" value="<?= (int)$signer['is_active']===1?'0':'1' ?>"><span><?= $e($signer['jabatan'].' · '.$signer['nama'].' · '.((int)$signer['is_active']===1?'Aktif':'Nonaktif')) ?></span><button class="btn btn-sm btn-outline-secondary"><?= (int)$signer['is_active']===1?'Nonaktifkan':'Aktifkan' ?></button></form><?php endforeach ?></section><?php endif ?>
<?php ah_page_close(); ?>
