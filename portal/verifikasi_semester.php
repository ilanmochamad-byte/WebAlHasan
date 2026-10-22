<?php
declare(strict_types=1);
use App\V4\VerifikasiService;
use App\V4\BahanRepository;
use App\V4\V4Exception;
use App\Http\Csrf;
require_once dirname(__DIR__).'/app/bootstrap.php';
$user=authorization()->requireWebUser();header('Cache-Control: private, no-store');
$service=new VerifikasiService(app_db(),audit_logger());$plan=(int)($_POST['rencana_id']??$_GET['rencana_id']??0);$sid=(int)($_POST['santri_id']??$_GET['santri_id']??0);$current=null;$options=[];
try{
    $options=$service->options($user);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        Csrf::requireValid(is_string($_POST['_csrf']??null)?$_POST['_csrf']:null);
        $result=$service->act($user,$_POST);
        ah_flash_set('success',isset($result['siap_finalisasi'])?'Bahan lengkap dan terverifikasi. Pemeriksaan selesai.':'Status pemeriksaan tersimpan.');
        ah_redirect(app_url('/portal/verifikasi_semester.php').'?rencana_id='.$plan.'&santri_id='.$sid);
    }
    if($plan)$current=$service->read($user,$plan,$sid);
}catch(V4Exception $ex){http_response_code($ex->status);if($ex->status===403){\App\Ui\Denial::page('Kelengkapan semester','Bagian Pendidikan',$ex->getMessage());}$error=$ex->getMessage();if($plan){try{$current=$service->read($user,$plan,$sid);}catch(V4Exception){}}}
// Saringan "perlu perbaikan" dari kartu Beranda (Tahap 2): status EFEKTIF
// Dikembalikan pada matriks, himpunan yang sama dengan angka Beranda.
$saringPerbaikan=($_GET['status']??'')==='Dikembalikan';
$daftarPerbaikan=[];
if($saringPerbaikan&&$current){
    $current['matriks']=array_values(array_filter($current['matriks'],static fn($st)=>array_filter($st['bahan'],static fn($m)=>$m['pemeriksaan']['status']==='Dikembalikan')!==[]));
}elseif($saringPerbaikan&&!$plan){
    foreach($options as $o){
        try{$r=beranda_service()->ringkasBahan((int)$o['id'],(int)$o['tahun_ajaran_id'],(int)$o['kelas_id']);}catch(Throwable){continue;}
        if($r['perbaikan']>0)$daftarPerbaikan[]=['rencana'=>$o,'perbaikan'=>$r['perbaikan']];
    }
}
$e=static fn($v)=>ah_e($v);$labels=['Dikirim'=>'Diserahkan','Dikembalikan'=>'Perlu Perbaikan','Draf'=>'Draf','Terverifikasi'=>'Terverifikasi'];
ah_page_open(['title'=>'Kelengkapan Semester','user'=>$user,'description'=>'Pemeriksaan nilai, sikap, dan sumber kehadiran sebelum finalisasi.']);
?>
<style>.bahan-card{border:1px solid #ddd;border-radius:8px;padding:1rem;margin:.75rem 0;overflow-wrap:anywhere}.bahan-actions{display:flex;gap:.75rem;flex-wrap:wrap}.bahan-card input,.bahan-card textarea{max-width:100%;width:100%}.bahan-list{padding-left:1.2rem}pre{white-space:pre-wrap;overflow-wrap:anywhere}</style>
<?php if(isset($error)):?><p class="alert alert-danger" role="alert"><?= $e($error) ?> Data terbaru ditampilkan; periksa sebelum mencoba lagi.</p><?php endif ?>
<section class="card p-3 mb-3"><form method="get"><label>Kelas dan semester<select class="form-select" name="rencana_id" required><option value="">Pilih rencana</option><?php foreach($options as $o):?><option value="<?= (int)$o['id'] ?>" <?= $plan===(int)$o['id']?'selected':'' ?>><?= $e($o['nama_kelas'].' · '.$o['tahun'].' '.$o['semester']) ?></option><?php endforeach ?></select></label><?php if($saringPerbaikan):?><input type="hidden" name="status" value="Dikembalikan"><?php endif ?><button class="btn btn-primary mt-3">Buka matriks</button></form><?php if(!$options):?><p>Belum ada rencana dalam cakupan Pendidikan Anda.</p><?php endif ?></section>
<?php if($saringPerbaikan):?><section class="card p-3 mb-3" data-ah-saringan="Dikembalikan"><p class="mb-2"><b>Saringan: bahan perlu perbaikan.</b> <a href="<?= $e(app_url('/portal/verifikasi_semester.php').($plan?'?rencana_id='.$plan:'')) ?>">Tampilkan semua</a></p>
<?php if($plan&&$current):?><p class="mb-0"><?= count($current['matriks']) ?> santri dengan bahan perlu perbaikan.</p>
<?php elseif(!$plan):?><?php if($daftarPerbaikan===[]):?><p class="mb-0">Tidak ada bahan perlu perbaikan.</p><?php else:?><ul class="mb-0"><?php foreach($daftarPerbaikan as $d):?><li><a href="?rencana_id=<?= (int)$d['rencana']['id'] ?>&amp;status=Dikembalikan"><?= $e($d['rencana']['nama_kelas'].' · '.$d['rencana']['tahun'].' '.$d['rencana']['semester']) ?></a> · <?= (int)$d['perbaikan'] ?> bahan</li><?php endforeach ?></ul><?php endif ?><?php endif ?></section><?php endif ?>
<?php if($current):foreach($current['matriks'] as $student):$id=(int)$student['santri']['id']; ?>
<section class="card p-3 mb-3" data-santri="<?= $id ?>"><h2 class="h5"><?= $e($student['santri']['nama_santri']) ?></h2>
<p><?= $student['siap_finalisasi']?'Seluruh bahan terverifikasi':'Masih ada kekurangan / perlu verifikasi' ?></p>
<ul class="bahan-list"><?php foreach($student['kekurangan'] as $msg):?><li><?= $e($msg) ?></li><?php endforeach ?></ul>
<?php foreach($student['bahan'] as $m):$state=$m['pemeriksaan'];?><article class="bahan-card" data-jenis="<?= $e($m['jenis']) ?>" data-key="<?= $m['mapel_key'] ?>"><h3 class="h6"><?= $e($m['label']) ?> · <?= $e($labels[$state['status']]) ?></h3>
<p><?= $m['lengkap']?'Lengkap':'Belum lengkap' ?><?= $state['perlu_verifikasi_ulang']?' · Sumber berubah; perlu diserahkan dan diverifikasi ulang.':'' ?></p>
<?php if($m['jenis']==='nilai'):?><ul class="bahan-list"><?php foreach($m['komponen'] as $n):?><li><?= $e($n['komponen'].' #'.$n['nomor_harian'].' · '.$n['nilai'].' · '.$n['status'].' · '.$n['kapasitas']) ?> pelaku #<?= (int)$n['pelaku_user_id'] ?></li><?php endforeach ?></ul><p><?= $e($m['hasil']['kelengkapan']) ?></p><?php elseif($m['jenis']==='sikap'):?><ul class="bahan-list"><?php foreach(['adab'=>'Adab / akhlak','kedisiplinan'=>'Kedisiplinan','tanggung_jawab'=>'Tanggung jawab']as $key=>$label):?><li><?= $e($label.': '.($m['sikap'][$key]??'Belum diisi')) ?></li><?php endforeach ?></ul><p><?= $e($m['penghalang']??'Diketahui / dikoreksi murobi yang sah.') ?></p><a href="sikap_semester.php?mode=pendidikan&amp;rencana_id=<?= $plan ?>&amp;santri_id=<?= $id ?>">Lihat riwayat sikap</a><?php else:$att=$m['kehadiran'];?><p>Jumlah pertemuan: <?= $att['jumlah_pertemuan'] ?></p><ul class="bahan-list"><?php foreach($att['hitungan'] as $name=>$count):?><li><?= $e($name) ?>: <?= $count ?></li><?php endforeach ?><li>Belum dicatat: <?= $att['belum_dicatat'] ?></li></ul><?php foreach($att['kekurangan'] as $r):?><p>Pertemuan #<?= (int)$r['pertemuan_id'] ?> · <?= $e($r['tanggal_pertemuan']) ?> belum dicatat.</p><?php endforeach;endif ?>
<?php if($state['alasan']):?><p>Alasan pemeriksaan: <?= $e($state['alasan']) ?></p><?php endif ?>
<?php if($state['status']==='Dikirim'||($m['jenis']==='kehadiran'&&$state['status']!=='Terverifikasi')):?><form method="post"><?= ah_csrf() ?><input type="hidden" name="rencana_id" value="<?= $plan ?>"><input type="hidden" name="santri_id" value="<?= $id ?>"><input type="hidden" name="jenis" value="<?= $e($m['jenis']) ?>"><input type="hidden" name="mapel_key" value="<?= $m['mapel_key'] ?>"><input type="hidden" name="version" value="<?= $state['version'] ?>"><input type="hidden" name="sumber_hash" value="<?= $e($state['sumber_hash']) ?>"><label>Alasan tindakan<textarea class="form-control" name="alasan" maxlength="1000"></textarea></label><div class="bahan-actions mt-3"><?php if($state['status']==='Dikirim'):?><button class="btn btn-success" name="action" value="verifikasi">Verifikasi bahan</button><button class="btn btn-outline-primary" name="action" value="kembalikan">Kembalikan beralasan</button><?php elseif($m['jenis']==='kehadiran'&&$state['status']!=='Terverifikasi'):?><button class="btn btn-primary" name="action" value="serahkan">Serahkan rekap kehadiran</button><?php endif ?></div></form><?php endif ?></article><?php endforeach ?>
<form method="post"><?= ah_csrf() ?><input type="hidden" name="rencana_id" value="<?= $plan ?>"><input type="hidden" name="santri_id" value="<?= $id ?>"><input type="hidden" name="matriks_hash" value="<?= BahanRepository::fingerprint($student) ?>"><label>Alasan pemeriksaan admin (jika bertindak sebagai admin)<textarea class="form-control" name="alasan" maxlength="1000"></textarea></label><button class="btn btn-outline-primary mt-3" name="action" value="periksa_finalisasi">Periksa kelayakan finalisasi</button></form>
<details class="mt-3"><summary>Riwayat pemeriksaan (<?= count($student['riwayat_pemeriksaan']) ?>)</summary><?php foreach($student['riwayat_pemeriksaan'] as $h):$snap=json_decode($h['snapshot_json'],true);?><p><?= $e(($snap['jenis']??'').' · '.($snap['status']??'').' · '.($snap['kapasitas']??'')) ?> #<?= (int)($snap['actor_user_id']??0) ?> · versi <?= (int)$h['version'] ?> · <?= $e($snap['alasan']??'') ?></p><?php endforeach ?></details></section>
<?php endforeach;endif;ah_page_close(); ?>
