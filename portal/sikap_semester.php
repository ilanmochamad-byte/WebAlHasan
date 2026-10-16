<?php
declare(strict_types=1);
use App\V4\SikapService;
use App\V4\V4Exception;
use App\Http\Csrf;
require_once dirname(__DIR__).'/app/bootstrap.php';
$user=authorization()->requireWebUser();header('Cache-Control: private, no-store');
$service=new SikapService(app_db(),audit_logger());
$mode=(string)($_POST['mode']??$_GET['mode']??'pembimbing');
$plan=(int)($_POST['rencana_id']??$_GET['rencana_id']??0);$sid=(int)($_POST['santri_id']??$_GET['santri_id']??0);
$current=null;$options=[];
try{
    $options=$service->options($user,$mode);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        Csrf::requireValid(is_string($_POST['_csrf']??null)?$_POST['_csrf']:null);
        $service->save($user,$_POST,$mode);
        ah_flash_set('success','Sikap dan riwayat tersimpan.');
        ah_redirect(app_url('/portal/sikap_semester.php').'?mode='.urlencode($mode).'&rencana_id='.$plan.'&santri_id='.$sid);
    }
    if($plan&&$sid)$current=$service->read($user,$plan,$sid,$mode);
}catch(V4Exception $ex){http_response_code($ex->status);if($ex->status===403){echo '403 — Akses ditolak.';exit;}$error=$ex->getMessage();if($plan&&$sid){try{$current=$service->read($user,$plan,$sid,$mode);}catch(V4Exception){}}}
$e=static fn($v)=>ah_e($v);
ah_page_open(['title'=>$mode==='murobi'?'Pemeriksaan Sikap Murobi':'Sikap Semester','user'=>$user,'description'=>'Tiga aspek sikap dan riwayat pemeriksaannya.']);
?>
<style>.sikap-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:1rem}.sikap-grid>*{min-width:0}textarea,select{max-width:100%;width:100%}.sikap-actions{display:flex;flex-wrap:wrap;gap:.75rem}.sikap-history{overflow-wrap:anywhere;border-bottom:1px solid #ddd;margin:1rem 0}</style>
<?php if(isset($error)):?><p class="alert alert-danger" role="alert"><?= $e($error) ?> Data tersimpan terbaru ditampilkan; muat ulang sebelum mencoba kembali.</p><?php endif ?>
<section class="card p-3 mb-3"><details <?= $current?'':'open' ?>><summary>Pilih santri dan semester</summary><?php if(!$options):?><p>Belum ada santri dalam penugasan aktif Anda.</p><?php endif ?>
<?php foreach($options as $o):?><p><a href="?mode=<?= $e($mode) ?>&amp;rencana_id=<?= (int)$o['rencana_id'] ?>&amp;santri_id=<?= (int)$o['santri_id'] ?>"><?= $e($o['nama_santri'].' · '.$o['nama_kelas'].' · '.$o['tahun'].' '.$o['semester']) ?></a></p><?php endforeach ?></details></section>
<?php if($current):$row=$current['sikap']??[]; ?>
<section class="card p-3"><h2 class="h5">Penilaian sikap</h2><p id="status-sikap"><?= $e($current['pemeriksaan']['status']) ?> · versi <?= (int)($row['version']??0) ?> · <?= empty($row['diketahui_murobi_user_id'])?'Belum diketahui murobi':'Diketahui / dikoreksi murobi #'.(int)$row['diketahui_murobi_user_id'] ?></p>
<?php if($current['pemeriksaan']['alasan']):?><p class="alert alert-info">Alasan pemeriksaan / koreksi: <?= $e($current['pemeriksaan']['alasan']) ?></p><?php endif ?>
<?php if(!$current['murobi_aktif']):?><p class="alert alert-warning">Tidak ada murobi aktif yang sah. Minta admin memperbaiki penugasan.</p><?php endif ?>
<?php if($mode==='murobi'&&!$row):?><p>Pembimbing belum mengisi sikap.</p><?php elseif($mode!=='pendidikan'):?>
<form method="post" id="sikap-form"><?= ah_csrf() ?><input type="hidden" name="mode" value="<?= $e($mode) ?>"><input type="hidden" name="rencana_id" value="<?= $plan ?>"><input type="hidden" name="santri_id" value="<?= $sid ?>"><input type="hidden" name="version" value="<?= (int)($row['version']??0) ?>">
<div class="sikap-grid"><?php foreach(['adab'=>'Adab / akhlak','kedisiplinan'=>'Kedisiplinan','tanggung_jawab'=>'Tanggung jawab'] as $key=>$label):?><label><?= $e($label) ?><select class="form-select" name="<?= $key ?>" required><option value="">Pilih predikat</option><?php foreach(SikapService::PREDIKAT as $pred):?><option <?= ($row[$key]??'')===$pred?'selected':'' ?>><?= $e($pred) ?></option><?php endforeach ?></select></label><?php endforeach ?></div>
<label class="d-block mt-3">Catatan<textarea class="form-control" name="catatan" maxlength="2000"><?= $e($row['catatan']??'') ?></textarea></label>
<label class="d-block mt-3">Alasan koreksi<textarea class="form-control" name="alasan" maxlength="1000"></textarea></label>
<div class="sikap-actions mt-3"><?php if($mode==='pembimbing'):?><button class="btn btn-primary" name="action" value="draf">Simpan draf</button><button class="btn btn-success" name="action" value="serahkan">Serahkan sikap</button><?php elseif(empty($row['diketahui_murobi_user_id'])):?><button class="btn btn-success" name="action" value="diketahui">Tandai diketahui</button><?php endif ?><button class="btn btn-outline-primary" name="action" value="koreksi">Koreksi beralasan</button></div></form>
<?php endif ?>
<details class="mt-3"><summary>Riwayat sikap (<?= count($current['riwayat']) ?>)</summary><?php foreach($current['riwayat'] as $h):$s=json_decode($h['snapshot_json'],true);?><div class="sikap-history">Versi <?= (int)$h['version'] ?> · <?= $e($h['kapasitas']) ?> #<?= (int)$h['actor_user_id'] ?><p><?= $e(($s['adab']??'').' · '.($s['kedisiplinan']??'').' · '.($s['tanggung_jawab']??'')) ?></p><p><?= $e($s['catatan']??'') ?></p><p>Alasan: <?= $e($h['alasan']) ?></p></div><?php endforeach ?></details></section>
<?php endif; ah_page_close(); ?>
