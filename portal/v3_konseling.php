<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Ui\Denial;
use App\V3\V3Exception;

require_once dirname(__DIR__).'/app/bootstrap.php';
$currentUser=authorization()->requireWebUser();$caps=capabilities()->v3Capabilities($currentUser);
if(array_intersect(['v3.konseling.kelola','v3.binaan.baca','v3.pengawasan','v3.koreksi'],array_keys($caps))===[]){Denial::render('Akun ini tidak memiliki akses konseling V3.','Akses dihitung dari penugasan pembimbing/murobi aktif atau hak pengawasan admin.');}
$service=v3_konseling_service();$error='';$input=$_POST;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        Csrf::requireValid($_POST['_csrf']??$_SERVER['HTTP_X_CSRF_TOKEN']??null);if(($_POST['aksi']??'')!=='buat')throw new V3Exception('Aksi tidak dikenal.');
        $scope=explode('|',(string)($_POST['santri_scope']??''),2);$input['santri_id']=$scope[0]??'';$input['tahun_ajaran_id']=$scope[1]??'';
        $result=$service->createCase($currentUser,$input);ah_flash_set('success',$result['replayed']?'Permintaan identik sudah pernah diproses.':'Kasus konseling dibuka dengan audit dan tautan yang dipilih.');ah_redirect('/portal/v3_konseling_detail.php?id='.(int)$result['data']['kasus']['id']);
    }catch(V3Exception $exception){$error=$exception->getMessage();}
}
$options=$service->options($currentUser);try{$list=$service->page($currentUser,$_GET);}catch(V3Exception $exception){$error=$exception->getMessage();$list=['rows'=>[],'total'=>0,'page'=>1,'per_page'=>25];}
$newKey='web-case-'.bin2hex(random_bytes(14));
ah_page_open(['title'=>'Konseling','heading'=>'Konseling & tindak lanjut V3','description'=>'Kelola kasus dan beberapa sesi terhubung tanpa membuka catatan internal kepada pihak yang tidak berwenang.','user'=>$currentUser,'active'=>'v3.konseling']);
if($error!=='')ah_note('danger',$error);
?>
<?php if($options['dapat_membuat']): ?>
<section class="ah-card mb-4"><div class="ah-card__body"><h2 class="h5">Buka kasus konseling</h2><p class="text-muted">Tautan pelanggaran dan rekomendasi dipilih manual; sistem tidak pernah membuat kasus otomatis.</p>
<form method="post" class="row g-3"><?= ah_csrf() ?><input type="hidden" name="aksi" value="buat"><input type="hidden" name="idempotency_key" value="<?= ah_e($input['idempotency_key']??$newKey) ?>">
<div class="col-12 col-lg-6"><label class="form-label" for="case-student">Santri dalam cakupan</label><select class="form-select" id="case-student" name="santri_scope" required><option value="">Pilih santri</option><?php foreach($options['santri'] as $student):$scope=$student['santri_id'].'|'.$student['tahun_ajaran_id'];?><option value="<?= ah_e($scope) ?>" <?= (string)($input['santri_scope']??'')===$scope?'selected':'' ?>><?= ah_e($student['nama'].' — '.$student['tahun'].' / '.$student['semester']) ?></option><?php endforeach ?></select></div>
<div class="col-12 col-lg-3"><label class="form-label" for="case-opened">Waktu pembukaan</label><input class="form-control" id="case-opened" type="datetime-local" name="dibuka_pada" value="<?= ah_e($input['dibuka_pada']??date('Y-m-d\TH:i')) ?>" max="<?= ah_e(date('Y-m-d\TH:i')) ?>" required></div>
<div class="col-12 col-lg-3"><label class="form-label" for="case-privacy">Kerahasiaan</label><select class="form-select" id="case-privacy" name="kerahasiaan"><option>Rahasia</option><option <?= ($input['kerahasiaan']??'')==='Internal'?'selected':'' ?>>Internal</option></select><div class="form-text">Internal: pembimbing dan murobi terkait. Rahasia: hanya pembimbing pemilik kasus; admin dapat membuka untuk pengawasan dan tercatat audit.</div></div>
<div class="col-12"><label class="form-label" for="case-purpose">Tujuan pendampingan</label><textarea class="form-control" id="case-purpose" name="tujuan" maxlength="5000" required><?= ah_e($input['tujuan']??'') ?></textarea></div>
<?php if($options['pelanggaran']!==[]): ?><fieldset class="col-12"><legend class="form-label">Tautkan pelanggaran (opsional)</legend><div class="row g-2"><?php foreach($options['pelanggaran'] as $violation): ?><div class="col-12 col-lg-6"><label class="border rounded p-2 d-block"><input class="form-check-input me-2" type="checkbox" name="pelanggaran_ids[]" value="<?= (int)$violation['id'] ?>">#<?= (int)$violation['id'] ?> · <?= ah_e(($violation['santri_nama']??'').' · '.$violation['kategori'].' · '.$violation['waktu_kejadian']) ?></label></div><?php endforeach ?></div></fieldset><?php endif ?>
<?php if($options['rekomendasi_belum_ditindaklanjuti']!==[]): ?><fieldset class="col-12"><legend class="form-label">Rekomendasi berlaku yang belum ditindaklanjuti (opsional)</legend><?php foreach($options['rekomendasi_belum_ditindaklanjuti'] as $recommendation): ?><label class="border rounded p-2 d-block mb-2"><input class="form-check-input me-2" type="checkbox" name="rekomendasi_ids[]" value="<?= (int)$recommendation['id'] ?>"><?= ah_e($recommendation['santri_nama'].' · '.$recommendation['label'].' · '.$recommendation['total_poin'].' poin') ?></label><?php endforeach ?></fieldset><?php endif ?>
<div class="col-12"><button class="btn btn-primary" type="submit">Buka kasus</button></div></form></div></section>
<?php endif ?>
<section class="ah-card"><div class="ah-card__body"><h2 class="h5">Daftar kasus konseling</h2><form method="get" class="row g-2 mb-3"><div class="col-12 col-md-4"><label class="form-label" for="case-status-filter">Status</label><select class="form-select" id="case-status-filter" name="status"><option value="">Semua</option><?php foreach(['Dibuka','Dalam Pendampingan','Selesai','Dibatalkan'] as $status): ?><option <?= ($_GET['status']??'')===$status?'selected':'' ?>><?= ah_e($status) ?></option><?php endforeach ?></select></div><div class="col-12 col-md-4 align-self-end"><button class="btn btn-outline-primary">Terapkan filter</button></div></form>
<?php if($list['rows']===[]): ?><?= ah_empty('Belum ada kasus','Belum ada kasus konseling yang dapat dibaca dalam cakupan aktif Anda.') ?><?php else: ?><div class="table-responsive"><table class="table"><caption class="visually-hidden">Daftar kasus konseling V3</caption><thead><tr><th>Santri</th><th>Dibuka</th><th>Status</th><th>Sesi</th><th></th></tr></thead><tbody><?php foreach($list['rows'] as $row): ?><tr><td><?= ah_e($row['santri_nama']) ?><small class="d-block text-muted"><?= ah_e($row['tahun'].' / '.$row['semester']) ?></small></td><td><?= ah_e($row['dibuka_pada']) ?></td><td><?= ah_e($row['status']) ?></td><td><?= (int)$row['jumlah_sesi'] ?></td><td><a href="<?= ah_e(app_url('/portal/v3_konseling_detail.php?id='.(int)$row['id'])) ?>">Detail</a></td></tr><?php endforeach ?></tbody></table></div><?php ah_pagination($list['total'],$list['page'],$list['per_page']);endif ?></div></section>
<?php ah_page_close(); ?>
