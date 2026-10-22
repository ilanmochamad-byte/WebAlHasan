<?php
declare(strict_types=1);
use App\V4\NilaiService;
use App\V4\V4Exception;
use App\Http\Csrf;
require_once dirname(__DIR__).'/app/bootstrap.php';
$user=authorization()->requireWebUser();
$service=new NilaiService(app_db(),capabilities(),audit_logger());
header('Cache-Control: private, no-store');
$pengganti=($_POST['pengganti']??$_GET['pengganti']??'0')==='1';
$mapel=(int)($_POST['rencana_mapel_id']??$_GET['rencana_mapel_id']??0);
$santri=(int)($_POST['santri_id']??$_GET['santri_id']??0);
$current=null;$options=[];
try {
    $options=$service->options($user,$pengganti);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        Csrf::requireValid(is_string($_POST['_csrf']??null)?$_POST['_csrf']:null);
        $items=$_POST['komponen']??[];
        if(!is_array($items))throw new V4Exception('Komponen tidak valid.');
        // Baris baru kosong tidak disimpan; baris tersimpan tidak boleh dikosongkan/diabaikan.
        $items=array_values(array_filter($items,fn($r)=>!is_array($r)||($r['nilai']??'')!==''||(int)($r['version']??0)>0));
        $service->save($user,array_replace($_POST,['komponen'=>$items]),$pengganti);
        ah_flash_set('success','Nilai tersimpan beserta riwayat revisinya.');
        ah_redirect(app_url('/portal/nilai_semester.php').'?pengganti='.($pengganti?'1':'0').'&rencana_mapel_id='.$mapel.'&santri_id='.$santri);
    }
    if($mapel)$current=$service->read($user,$mapel,$santri,$pengganti);
} catch(V4Exception $ex) {
    http_response_code($ex->status);
    if($ex->status===403){\App\Ui\Denial::page('Nilai semester','guru mata pelajaran yang ditugaskan, atau Bagian Pendidikan untuk nilai pengganti',$ex->getMessage());}
    $error=$ex->getMessage();
    if($mapel){try{$current=$service->read($user,$mapel,$santri,$pengganti);}catch(V4Exception){}}
}
$e=static fn($v)=>ah_e($v);
ah_page_open(['title'=>$pengganti?'Pengisian Nilai Pengganti':'Nilai Semester','user'=>$user,'description'=>'Nilai harian/praktik, tengah semester, akhir semester, dan catatan capaian.']);
?>
<style>.nilai-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:1rem}.nilai-grid>*{min-width:0}.nilai-grid input,.nilai-grid select,textarea{width:100%;max-width:100%}.nilai-row{border:1px solid #ddd;border-radius:8px;padding:1rem;margin:.75rem 0}.nilai-actions{display:flex;flex-wrap:wrap;gap:.75rem}pre{white-space:pre-wrap;overflow-wrap:anywhere}</style>
<?php if(isset($error)): ?><div class="alert alert-danger" role="alert"><?= $e($error) ?> Isian tersimpan terbaru ditampilkan di bawah; periksa sebelum mencoba lagi.</div><?php endif ?>
<?php if($pengganti): ?><p class="alert alert-info">Tindakan pengganti Bagian Pendidikan. Alasan wajib; penugasan guru tetap seperti semula.</p><?php endif ?>
<section class="card p-3 mb-3"><form method="get" class="nilai-grid"><input type="hidden" name="pengganti" value="<?= $pengganti?'1':'0' ?>"><label>Mapel · kelas · semester<select class="form-select" name="rencana_mapel_id" required><option value="">Pilih rencana mapel</option><?php foreach($options as $o): ?><option value="<?= (int)$o['id'] ?>" <?= $mapel===(int)$o['id']?'selected':'' ?>><?= $e($o['mata_pelajaran'].' · '.$o['nama_kelas'].' · '.$o['tahun'].' '.$o['semester']) ?></option><?php endforeach ?></select></label><div><button class="btn btn-outline-primary">Buka daftar santri</button></div></form><?php if(!$options): ?><p>Belum ada rencana mapel dalam penugasan Anda.</p><?php endif ?></section>
<?php if($current): ?><section class="card p-3 mb-3"><form method="get" class="nilai-grid"><input type="hidden" name="pengganti" value="<?= $pengganti?'1':'0' ?>"><input type="hidden" name="rencana_mapel_id" value="<?= $mapel ?>"><label>Santri<select class="form-select" name="santri_id" required><option value="">Pilih santri</option><?php foreach($current['santri'] as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $santri===(int)$s['id']?'selected':'' ?>><?= $e($s['nama_santri']) ?></option><?php endforeach ?></select></label><div><button class="btn btn-outline-primary">Buka nilai</button></div></form><?php if(!$current['santri']): ?><p>Belum ada penempatan santri pada kelas dan semester ini.</p><?php endif ?></section><?php endif ?>
<?php if($current&&$santri):
$rows=$current['nilai'];$keys=[];$last=0;foreach($rows as $r){$keys[$r['komponen']]=true;$last=max($last,(int)$r['nomor_harian']);}
if(!$last)$rows[]=['komponen'=>'Harian','nomor_harian'=>1,'version'=>0,'nilai'=>'','catatan'=>''];
foreach(['Tengah','Akhir'] as $kind)if(!isset($keys[$kind]))$rows[]=['komponen'=>$kind,'nomor_harian'=>0,'version'=>0,'nilai'=>'','catatan'=>''];
$hasil=$current['hasil']; ?>
<section class="card p-3"><h2 class="h5">Pengisian nilai</h2><p>Status bahan: <?= $e(['Dikirim'=>'Diserahkan','Dikembalikan'=>'Perlu Perbaikan'][$current['pemeriksaan']['status']]??$current['pemeriksaan']['status']) ?></p><?php if($current['pemeriksaan']['alasan']): ?><p class="alert alert-info">Alasan pemeriksaan / koreksi: <?= $e($current['pemeriksaan']['alasan']) ?></p><?php endif ?><p id="hasil-nilai"><?= $hasil['nilai_tampil']===null?'Belum lengkap':'Nilai akhir '.$hasil['nilai_tampil'].' · predikat '.$hasil['predikat'] ?> · Target <?= $e($hasil['target_ketuntasan']) ?></p><p>Bobot 40% rata-rata harian/praktik, 30% tengah, 30% akhir. Kosong belum lengkap; angka 0 adalah nilai sah. Untuk mengubah nilai atau catatan tersimpan, pilih Koreksi dan isi alasan.</p>
<form method="post" id="nilai-form"><?= ah_csrf() ?><input type="hidden" name="pengganti" value="<?= $pengganti?'1':'0' ?>"><input type="hidden" name="rencana_mapel_id" value="<?= $mapel ?>"><input type="hidden" name="santri_id" value="<?= $santri ?>">
<div id="komponen"><?php foreach($rows as $i=>$r): ?><div class="nilai-row"><h3 class="h6"><?= $e($r['komponen'].($r['komponen']==='Harian'?' / praktik #'.$r['nomor_harian']:'')) ?> · <?= $e($r['status']??'Belum diisi') ?></h3><input type="hidden" name="komponen[<?= $i ?>][komponen]" value="<?= $e($r['komponen']) ?>"><input type="hidden" name="komponen[<?= $i ?>][nomor_harian]" value="<?= (int)$r['nomor_harian'] ?>"><input type="hidden" name="komponen[<?= $i ?>][version]" value="<?= (int)$r['version'] ?>"><div class="nilai-grid"><label>Nilai <?= $e($r['komponen']) ?><input class="form-control" type="number" min="0" max="100" step="0.01" name="komponen[<?= $i ?>][nilai]" value="<?= $e($r['nilai']) ?>"></label><label>Catatan capaian<textarea class="form-control" maxlength="2000" name="komponen[<?= $i ?>][catatan]"><?= $e($r['catatan']) ?></textarea></label></div></div><?php endforeach ?></div>
<button type="button" id="tambah-harian" class="btn btn-outline-secondary">Tambah harian/praktik</button><label class="d-block mt-3">Alasan pengganti / koreksi<textarea class="form-control" name="alasan" maxlength="1000" <?= $pengganti?'required':'' ?>></textarea></label><div class="nilai-actions mt-3"><button class="btn btn-primary" name="action" value="draf">Simpan draf</button><button class="btn btn-success" name="action" value="serahkan">Serahkan nilai</button><button class="btn btn-outline-primary" name="action" value="koreksi">Koreksi beralasan</button></div></form>
<details class="mt-3"><summary>Riwayat revisi (<?= count($current['riwayat']) ?>)</summary><?php foreach($current['riwayat'] as $h):$snap=json_decode($h['snapshot_json'],true); ?><div class="nilai-row">Versi <?= (int)$h['version'] ?> · <?= $e($snap['komponen']??'') ?> · <?= $e($h['kapasitas']) ?> · pelaku #<?= (int)$h['pelaku_user_id'] ?> · <?= $e($h['created_at']) ?><p>Nilai <?= $e($snap['nilai']??'') ?> · <?= $e($snap['catatan']??'') ?></p><p>Alasan: <?= $e($h['alasan']) ?></p></div><?php endforeach ?></details></section>
<script>let next=<?= count($rows) ?>,nomor=<?= max($last,1) ?>;document.getElementById('tambah-harian').addEventListener('click',()=>{if(nomor>=100)return;const box=document.createElement('div');box.className='nilai-row';const n=next++,h=++nomor;box.innerHTML=`<h3 class="h6">Harian / praktik #${h}</h3><input type="hidden" name="komponen[${n}][komponen]" value="Harian"><input type="hidden" name="komponen[${n}][nomor_harian]" value="${h}"><input type="hidden" name="komponen[${n}][version]" value="0"><div class="nilai-grid"><label>Nilai Harian<input class="form-control" type="number" min="0" max="100" step="0.01" name="komponen[${n}][nilai]"></label><label>Catatan capaian<textarea class="form-control" maxlength="2000" name="komponen[${n}][catatan]"></textarea></label></div>`;document.getElementById('komponen').appendChild(box);});</script>
<?php endif;ah_page_close(); ?>
