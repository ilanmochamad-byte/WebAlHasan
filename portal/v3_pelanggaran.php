<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Ui\Denial;
use App\Ui\Periode;
use App\V3\AttachmentStorage;
use App\V3\V3Exception;

/**
 * Pembinaan santri › Pelanggaran (redesain website Tahap 5, V-1).
 *
 * Chip status berangka, cari santri, periode, dan tabel yang menjadi kartu di
 * HP. "Catat pelanggaran" dibuka di panel samping (?action=create) sehingga
 * daftar tetap terlihat. Cakupan, poin, dan validasi sepenuhnya milik
 * PelanggaranService; halaman ini hanya tampilan.
 */
require_once dirname(__DIR__).'/app/bootstrap.php';
$currentUser=authorization()->requireWebUser();
$v3Caps=capabilities()->v3Capabilities($currentUser);
if(array_intersect(['v3.pelanggaran.kelola','v3.binaan.baca','v3.pengawasan','v3.koreksi'],array_keys($v3Caps))===[]){
    Denial::render('Akun ini tidak memiliki akses pelanggaran.','Akses dihitung dari penugasan pembimbing/murobi aktif atau hak pengawasan admin.');
}
$service=v3_pelanggaran_service();$error='';$input=$_POST;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        Csrf::requireValid($_POST['_csrf']??$_SERVER['HTTP_X_CSRF_TOKEN']??null);
        if(($_POST['aksi']??'')!=='buat'){throw new V3Exception('Aksi tidak dikenal.');}
        $scope=explode('|',(string)($_POST['santri_scope']??''),2);
        $input['santri_id']=$scope[0]??'';$input['tahun_ajaran_id']=$scope[1]??'';
        $storage=new AttachmentStorage(APP_ROOT.'/storage/private/v3');
        $attachment=$storage->stageUpload($_FILES['lampiran']??null);
        $result=$service->create($currentUser,$input,$attachment);
        ah_flash_set('success',$result['replayed']?'Permintaan identik sudah pernah disimpan.':'Pelanggaran dicatat; total poin santri diperbarui.');
        ah_redirect('/portal/v3_pelanggaran_detail.php?id='.(int)$result['data']['pelanggaran']['id']);
    }catch(V3Exception $exception){$error=$exception->getMessage();}
}
$options=$service->options($currentUser);

// Saringan: chip status, cari nama santri, dan periode waktu kejadian.
$teks=static fn(string $key):string=>is_string($_GET[$key]??null)?mb_substr(trim($_GET[$key]),0,100):'';
$status=in_array($teks('status'),['Dicatat','Ditindaklanjuti','Selesai','Dibatalkan'],true)?$teks('status'):'';
$q=$teks('q');
$periodeKode=array_key_exists($teks('periode'),Periode::PILIHAN)?$teks('periode'):'semua';
$mulai='';$sampai='';
if($periodeKode==='rentang'){
    $tanggal=static fn(string $v):string=>preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)?$v:'';
    $mulai=$tanggal($teks('mulai'));$sampai=$tanggal($teks('sampai'));
}elseif($periodeKode!=='semua'){
    $aktif=app_db()->query("SELECT tahun,semester FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1")?->fetch_assoc();
    [$mulai,$sampai]=Periode::rentang($periodeKode,$aktif?:null);
}
$filters=array_filter(['status'=>$status,'q'=>$q,'mulai'=>$mulai,'sampai'=>$sampai,'page'=>(string)max(1,(int)($_GET['page']??1))],static fn($v):bool=>$v!=='');
try{
    $list=$service->page($currentUser,$filters);
    $counts=$service->statusCounts($currentUser,$filters);
}catch(V3Exception $exception){$error=$error!==''?$error:$exception->getMessage();$list=['rows'=>[],'total'=>0,'page'=>1,'per_page'=>25,'peringatan_konfigurasi'=>[]];$counts=['Dicatat'=>0,'Ditindaklanjuti'=>0,'Selesai'=>0,'Dibatalkan'=>0];}

$buka=$options['dapat_mencatat']&&((($_GET['action']??'')==='create')||$error!==''&&($_SERVER['REQUEST_METHOD']??'GET')==='POST');
$sisaQuery=ah_query(['action'=>null]);
$tutup=app_url('/portal/v3_pelanggaran.php').($sisaQuery!==''?'?'.$sisaQuery:'');
$tombolCatat=$options['dapat_mencatat']?'<a class="btn btn-primary" href="?'.ah_e(ah_query(['action'=>'create'])).'#ah-panel"><i class="fas fa-plus" aria-hidden="true"></i> Catat pelanggaran</a>':'';
$newKey='web-'.bin2hex(random_bytes(16));
ah_page_open(['title'=>'Pelanggaran','heading'=>'Pembinaan santri','user'=>$currentUser,
    'description'=>isset($v3Caps['v3.pelanggaran.kelola'])?'Pelanggaran santri binaan Anda. Poin mengikuti katalog saat dicatat.':(isset($v3Caps['v3.pengawasan'])?'Seluruh pelanggaran yang tercatat.':'Pelanggaran santri binaan Anda (hanya-baca).'),
    'actions'=>$tombolCatat,
    'breadcrumbs'=>[['label'=>'Beranda','url'=>app_url('/portal/index.php')],['label'=>'Pembinaan','url'=>app_url('/portal/v3_pelanggaran.php')],['label'=>'Pelanggaran']]]);
if($error!==''&&!$buka){ah_note('danger',$error);}
foreach(array_unique(array_merge($options['peringatan_konfigurasi'],$list['peringatan_konfigurasi'])) as $warning){ah_note('warning',$warning);}
?>
<?php if($buka): ?>
<?php ah_panel_open(['title'=>'Catat pelanggaran','close_url'=>$tutup,'description'=>'Hanya santri binaan dengan penempatan aktif yang dapat dipilih.']); ?>
    <?php if($error!==''): ?><?php ah_note('danger',$error); ?><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="row g-3" action="<?= ah_e(app_url('/portal/v3_pelanggaran.php')) ?>">
        <?= ah_csrf() ?><input type="hidden" name="aksi" value="buat"><input type="hidden" name="idempotency_key" value="<?= ah_e($input['idempotency_key']??$newKey) ?>">
        <div class="col-12"><label class="form-label" for="v3-santri">Santri <span class="text-danger">*</span></label>
            <input class="form-control mb-2" type="search" hidden data-ah-option-filter="v3-santri" placeholder="Ketik nama untuk menyaring" aria-label="Saring daftar santri" aria-describedby="v3-santri-status" autocomplete="off">
            <span class="ah-visually-hidden" id="v3-santri-status" aria-live="polite"></span>
            <select class="form-select" id="v3-santri" name="santri_scope" required>
                <option value="">— Pilih santri binaan (<?= count($options['santri']) ?>) —</option>
                <?php foreach($options['santri'] as $student): $scope=$student['santri_id'].'|'.$student['tahun_ajaran_id']; ?>
                    <option value="<?= ah_e($scope) ?>" data-total="<?= (int)$student['total_poin'] ?>" <?= (string)($input['santri_scope']??'')===$scope?'selected':'' ?>><?= ah_e($student['nama'].' · '.$student['semester'].' '.$student['tahun'].' · '.$student['total_poin'].' poin') ?></option>
                <?php endforeach ?>
            </select></div>
        <div class="col-12"><label class="form-label" for="v3-katalog">Jenis pelanggaran <span class="text-danger">*</span></label>
            <select class="form-select" id="v3-katalog" name="katalog_id" required>
                <option value="">— Pilih jenis —</option>
                <?php $kategoriKini=null; foreach($options['katalog'] as $catalog): ?>
                    <?php if($kategoriKini!==$catalog['kategori']): ?><?= $kategoriKini!==null?'</optgroup>':'' ?><optgroup label="<?= ah_e($catalog['kategori']) ?>"><?php $kategoriKini=$catalog['kategori']; endif ?>
                    <option value="<?= (int)$catalog['id'] ?>" data-poin="<?= (int)$catalog['poin_default'] ?>" data-tingkat="<?= ah_e($catalog['tingkat']) ?>" <?= (string)($input['katalog_id']??'')===(string)$catalog['id']?'selected':'' ?>><?= ah_e($catalog['nama'].' · '.$catalog['tingkat'].' · '.$catalog['poin_default'].' poin') ?></option>
                <?php endforeach ?><?= $kategoriKini!==null?'</optgroup>':'' ?>
            </select>
            <p class="ah-poin-hint mt-2 mb-0" data-ah-poin-preview hidden aria-live="polite"></p></div>
        <div class="col-12"><label class="form-label" for="v3-waktu">Waktu kejadian <span class="text-danger">*</span></label><input class="form-control" type="datetime-local" id="v3-waktu" name="waktu_kejadian" value="<?= ah_e($input['waktu_kejadian']??date('Y-m-d\TH:i')) ?>" max="<?= ah_e(date('Y-m-d\TH:i')) ?>" required></div>
        <div class="col-12"><label class="form-label" for="v3-tempat">Tempat</label><input class="form-control" id="v3-tempat" name="tempat" maxlength="255" value="<?= ah_e($input['tempat']??'') ?>" placeholder="Mis. masjid, kobong"></div>
        <div class="col-12"><label class="form-label" for="v3-uraian">Uraian kejadian <span class="text-danger">*</span></label><textarea class="form-control" id="v3-uraian" name="uraian" rows="3" maxlength="5000" required><?= ah_e($input['uraian']??'') ?></textarea></div>
        <div class="col-12"><label class="form-label" for="v3-saksi">Saksi</label><textarea class="form-control" id="v3-saksi" name="saksi" rows="2" maxlength="2000"><?= ah_e($input['saksi']??'') ?></textarea></div>
        <div class="col-12"><label class="form-label" for="v3-lampiran">Bukti (privat)</label><input class="form-control" type="file" id="v3-lampiran" name="lampiran" accept="image/jpeg,image/png,application/pdf"><div class="form-text">JPG, PNG, atau PDF; maksimum 5 MB. Tidak pernah dibagikan kepada orang tua.</div></div>
        <div class="col-12 d-flex flex-wrap gap-2 ah-sticky-sm">
            <a class="btn btn-outline-secondary" href="<?= ah_e($tutup) ?>">Batal</a>
            <button class="btn btn-primary" type="submit">Simpan catatan</button>
        </div>
    </form>
<?php ah_panel_close(); ?>
<?php endif; ?>
<?php
$chips=[['label'=>'Semua','url'=>'?'.ah_query(['status'=>null,'page'=>null,'action'=>null]),'active'=>$status==='','count'=>array_sum($counts)]];
foreach($counts as $nilai=>$jumlah){$chips[]=['label'=>$nilai,'url'=>'?'.ah_query(['status'=>$nilai,'page'=>null,'action'=>null]),'active'=>$status===$nilai,'count'=>$jumlah];}
echo ah_chips($chips,'Saring menurut status');
$aktifSaring=(int)($q!=='')+(int)($periodeKode!=='semua');
ah_filter_open($aktifSaring,['status'=>$status]);
?>
    <div class="ah-filter__wide"><label class="form-label" for="q">Cari santri</label>
        <input class="form-control" type="search" id="q" name="q" maxlength="100" value="<?= ah_e($q) ?>" placeholder="Nama santri"></div>
    <div><label class="form-label" for="periode">Periode kejadian</label>
        <select class="form-select" id="periode" name="periode">
            <option value="semua">Semua waktu</option>
            <?php foreach(['pekan','bulan','semester','rentang'] as $kode): ?><option value="<?= $kode ?>"<?= $periodeKode===$kode?' selected':'' ?>><?= ah_e(Periode::label($kode)) ?></option><?php endforeach ?>
        </select></div>
    <div><label class="form-label" for="mulai">Dari (rentang sendiri)</label><input class="form-control" type="date" id="mulai" name="mulai" value="<?= ah_e($periodeKode==='rentang'?$mulai:'') ?>"></div>
    <div><label class="form-label" for="sampai">Sampai</label><input class="form-control" type="date" id="sampai" name="sampai" value="<?= ah_e($periodeKode==='rentang'?$sampai:'') ?>"></div>
<?php ah_filter_close(['status'=>$status]); ?>

<section class="ah-card" aria-labelledby="ah-daftar-pelanggaran">
    <div class="ah-card__head"><span id="ah-daftar-pelanggaran">Daftar pelanggaran</span>
        <span class="text-muted small"><?= count($list['rows']) ?> dari <?= (int)$list['total'] ?><?= $periodeKode!=='semua'&&$mulai!==''?' · '.ah_e(ah_rentang($mulai,$sampai!==''?$sampai:$mulai,false)):'' ?></span></div>
    <?php if($list['rows']===[]): ?>
        <div class="ah-card__body"><?= ah_empty('Belum ada catatan yang cocok',($status!==''||$q!==''||$periodeKode!=='semua')?'Ubah chip status atau saringan untuk melihat catatan lain dalam cakupan Anda.':'Belum ada pelanggaran yang dapat dibaca dalam cakupan aktif Anda.',$tombolCatat!==''?str_replace('btn btn-primary','btn btn-sm btn-primary',$tombolCatat):null) ?></div>
    <?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards">
            <caption class="ah-visually-hidden">Pelanggaran sesuai saringan dalam cakupan Anda</caption>
            <thead><tr><th scope="col">Santri</th><th scope="col">Waktu</th><th scope="col">Jenis</th><th scope="col">Tingkat</th><th scope="col">Poin</th><th scope="col">Status</th></tr></thead>
            <tbody>
            <?php foreach($list['rows'] as $row): $url=app_url('/portal/v3_pelanggaran_detail.php?id='.(int)$row['id']); ?>
                <tr>
                    <td><span class="ah-cell-wrap"><a class="ah-row-link" href="<?= ah_e($url) ?>"><?= ah_e($row['santri_nama']) ?></a><span class="ah-cell-sub"><?= ah_e($row['semester'].' '.$row['tahun']) ?></span></span></td>
                    <td><?= ah_e(ah_waktu($row['waktu_kejadian'])) ?></td>
                    <td><span class="ah-cell-wrap"><?= ah_e($row['jenis']??$row['kategori']) ?><?php if(($row['jenis']??null)!==null): ?><span class="ah-cell-sub"><?= ah_e($row['kategori']) ?></span><?php endif ?></span></td>
                    <td><?= ah_tingkat($row['tingkat']) ?></td>
                    <td><?= (int)$row['poin'] ?></td>
                    <td><?= ah_status($row['status']) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
    <?php endif ?>
</section>
<?php ah_pagination((int)$list['total'],(int)$list['page'],(int)$list['per_page']); ?>
<?php ah_page_close(); ?>
