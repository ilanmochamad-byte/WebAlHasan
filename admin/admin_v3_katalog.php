<?php

declare(strict_types=1);
use App\V3\V3Exception;

/**
 * Pembinaan santri › Katalog & poin (redesain website Tahap 5, M-4).
 *
 * Khusus admin (`admin/_guard.php` dan KatalogService::requireAdmin). Segmen
 * Kategori · Jenis pelanggaran · Ambang poin; tambah/ubah di panel samping
 * dengan alasan perubahan; riwayat ditulis sebagai kalimat perubahan, bukan
 * JSON mentah. Aturan simpan, versi, dan audit tetap milik KatalogService.
 */
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_master_ui.php';
$service=v3_katalog_service();
$kind=$_GET['jenis']??'katalog';
if (!is_string($kind) || !isset(\App\V3\KatalogRepository::TABLES[$kind])) { http_response_code(422);exit('Jenis data tidak valid.'); }
$input=[];$error=null;$history=[];
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $id=filter_var($_POST['id']??0,FILTER_VALIDATE_INT);
        if ($id===false || $id<0) { throw new V3Exception('Data tidak valid.'); }
        $service->save($kind,$_POST,$currentUser,$id>0?$id:null);
        master_flash('success','Perubahan tersimpan dan tercatat dalam riwayat.');
        master_redirect('admin_v3_katalog.php?jenis='.$kind);
    }
    if (isset($_GET['edit'])) {
        $edit=filter_var($_GET['edit'],FILTER_VALIDATE_INT);
        if ($edit===false || $edit<1) { throw new V3Exception('Data tidak valid.'); }
        $input=$service->find($kind,$edit,$currentUser);
        $history=$service->history($kind,$edit,$currentUser);
    }
} catch (V3Exception $e) {
    http_response_code($e->status);$error=$e->getMessage();
    if ($_SERVER['REQUEST_METHOD']==='POST') { $input=array_filter($_POST,static fn($v)=>is_scalar($v)); }
}
try { $options=$service->options($currentUser);$list=$service->page($kind,$_GET,$currentUser);$operationalWarnings=v3_pelanggaran_service()->configurationWarnings();$recent=$service->recent($kind,$currentUser); }
catch(V3Exception $e) { http_response_code($e->status); master_header('Katalog & poin');echo '<div class="alert alert-danger">'.ah_e($e->getMessage()).'</div>';master_footer();exit; }
$labels=['kategori'=>'Kategori','katalog'=>'Jenis pelanggaran','ambang'=>'Ambang poin'];
$kategoriNama=array_column($options['kategori'],'nama','id');
$tahunNama=[];foreach($options['tahun'] as $year){$tahunNama[(int)$year['id']]=$year['semester'].' '.$year['tahun'].(($year['status']??'')==='Aktif'?'':' (nonaktif)');}
$buka=$input!==[]||($_GET['action']??'')==='create';
$tutup=app_url('/admin/admin_v3_katalog.php?jenis='.$kind);
$value=static fn($key,$default='')=>ah_e($input[$key]??$default);

// Riwayat sebagai kalimat: "Poin: 3 → 5" + alasan, bukan JSON mentah.
$kalimat=static function(array $event)use($kategoriNama,$tahunNama):array{
    $before=json_decode((string)($event['before_json']??''),true)?:[];$after=json_decode((string)($event['after_json']??''),true)?:[];
    $nama=(string)($after['nama']??$after['label']??$before['nama']??$before['label']??'data');
    $judul=$before===[]?'Menambah “'.$nama.'”':'Mengubah “'.$nama.'”';
    $medan=['kode'=>'Kode','nama'=>'Nama','label'=>'Label','kategori_id'=>'Kategori','tingkat'=>'Tingkat','poin_default'=>'Poin','nilai_minimum'=>'Nilai minimum','nilai_maksimum'=>'Nilai maksimum','tahun_ajaran_id'=>'Semester','tanggal_mulai'=>'Mulai berlaku','tanggal_selesai'=>'Selesai berlaku','is_active'=>'Status','uraian'=>'Uraian','rekomendasi'=>'Rekomendasi'];
    $tampil=static function(string $k,mixed $v)use($kategoriNama,$tahunNama):string{
        if($v===null||$v==='')return '—';
        return match($k){'kategori_id'=>(string)($kategoriNama[(int)$v]??'kategori lain'),'tahun_ajaran_id'=>(string)($tahunNama[(int)$v]??'semester lain'),'is_active'=>(int)$v===1?'Aktif':'Nonaktif','uraian','rekomendasi'=>mb_strimwidth((string)$v,0,60,'…'),default=>(string)$v};
    };
    $isi=[];
    if($before!==[]){foreach($medan as $k=>$label){if(array_key_exists($k,$after)&&(string)($before[$k]??'')!==(string)($after[$k]??'')){$isi[]=$label.': '.$tampil($k,$before[$k]??null).' → '.$tampil($k,$after[$k]);}}}
    if(($after['alasan_status']??'')!==''&&$before!==[])$isi[]='Alasan: '.$after['alasan_status'];
    return ['waktu'=>ah_waktu((string)$event['created_at']),'pelaku'=>(string)($event['actor_nama']??'Admin'),'judul'=>$judul,'isi'=>$isi===[]&&$before!==[]?'Tanpa perubahan isi.':implode("\n",$isi)];
};

master_header('Katalog & poin',['heading'=>'Pembinaan santri','description'=>'Atur kategori, jenis pelanggaran, dan ambang poin. Ambang hanya memberi rekomendasi tindak lanjut; tidak ada hukuman otomatis.',
    'actions'=>'<a class="btn btn-primary" href="'.ah_e(app_url('/admin/admin_v3_katalog.php?jenis='.$kind.'&action=create')).'#ah-panel"><i class="fas fa-plus" aria-hidden="true"></i> Tambah '.ah_e(mb_strtolower($labels[$kind])).'</a>',
    'breadcrumbs'=>[['label'=>'Beranda','url'=>app_url('/portal/index.php')],['label'=>'Pembinaan','url'=>app_url('/portal/v3_pelanggaran.php')],['label'=>'Katalog & poin']]]);
foreach($operationalWarnings as $warning){ah_note('warning','Perlu perhatian operasional: '.$warning);}
if($error&&!$buka){ah_note('danger',$error);}
echo ah_seg(array_map(static fn(string $k,string $label):array=>['label'=>$label,'url'=>app_url('/admin/admin_v3_katalog.php?jenis='.$k),'active'=>$kind===$k],array_keys($labels),$labels),'Jenis data katalog');
?>
<?php if($buka): ?>
<?php ah_panel_open(['title'=>(empty($input['id'])?'Tambah ':'Ubah ').mb_strtolower($labels[$kind]),'close_url'=>$tutup,'description'=>'Untuk menonaktifkan, pilih status Nonaktif; untuk mengakhiri, isi tanggal selesai.']); ?>
    <?php if($error): ?><?php ah_note('danger',$error); ?><?php endif ?>
    <form method="post" class="row g-3" action="<?= ah_e(app_url('/admin/admin_v3_katalog.php?jenis='.$kind)) ?>">
    <?= master_csrf() ?><input type="hidden" name="id" value="<?= $value('id',0) ?>"><input type="hidden" name="version" value="<?= $value('version',1) ?>">
    <?php
    $fields=$kind==='ambang'?['label'=>['Label ambang','text',150],'nilai_minimum'=>['Nilai minimum','number',null],'nilai_maksimum'=>['Nilai maksimum (opsional)','number',null]]:['kode'=>['Kode unik','text',40],'nama'=>['Nama','text',150]];
    if($kind==='katalog') { $fields['poin_default']=['Poin','number',null]; }
    $fields+=['tanggal_mulai'=>['Mulai berlaku','date',null],'tanggal_selesai'=>['Selesai berlaku (opsional)','date',null]];
    foreach($fields as $name=>[$label,$type,$max]): ?>
    <div class="col-12"><label class="form-label" for="v3-<?= ah_e($name) ?>"><?= ah_e($label) ?></label><input class="form-control" id="v3-<?= ah_e($name) ?>" name="<?= ah_e($name) ?>" type="<?= ah_e($type) ?>" value="<?= $value($name,$name==='tanggal_mulai'?date('Y-m-d'):'') ?>" <?= in_array($name,['nilai_maksimum','tanggal_selesai'],true)?'':'required' ?> <?= $type==='number'?'min="0" step="1" max="2147483647"':'' ?> <?= $max?'maxlength="'.$max.'"':'' ?>></div>
    <?php endforeach;
    $selects=['is_active'=>['Status',[1=>'Aktif',0=>'Nonaktif']]];
    if($kind==='katalog') { $selects['kategori_id']=['Kategori',$kategoriNama];$selects['tingkat']=['Tingkat',array_combine(['Ringan','Sedang','Berat'],['Ringan','Sedang','Berat'])]; }
    $defaults=['is_active'=>1];
    if($kind==='ambang') {
        $selects['tahun_ajaran_id']=['Semester',$tahunNama];
        foreach($options['tahun'] as $year){if(($year['status']??'')==='Aktif'){$defaults['tahun_ajaran_id']=(int)$year['id'];break;}}
    }
    foreach($selects as $name=>[$label,$choices]): ?>
    <div class="col-12"><label class="form-label" for="v3-<?= ah_e($name) ?>"><?= ah_e($label) ?></label><select class="form-select" id="v3-<?= ah_e($name) ?>" name="<?= ah_e($name) ?>" required><?php foreach($choices as $key=>$optionLabel): ?><option value="<?= ah_e($key) ?>" <?= (string)($input[$name]??($defaults[$name]??''))===(string)$key?'selected':'' ?>><?= ah_e($optionLabel) ?></option><?php endforeach ?></select><?php if($name==='tahun_ajaran_id'): ?><small class="form-text text-muted">Ambang untuk semester nonaktif tersimpan, tetapi belum terbaca pembimbing.</small><?php endif ?></div>
    <?php endforeach ?>
    <?php $name=$kind==='ambang'?'rekomendasi':'uraian'; ?>
    <div class="col-12"><label class="form-label" for="v3-description"><?= $kind==='ambang'?'Rekomendasi tindak lanjut':'Uraian (opsional)' ?></label><textarea class="form-control" id="v3-description" name="<?= $name ?>" rows="3" maxlength="5000" <?= $kind==='ambang'?'required':'' ?>><?= $value($name) ?></textarea></div>
    <div class="col-12"><label class="form-label" for="v3-reason">Alasan perubahan<?= empty($input['id'])?' (opsional untuk data baru)':' <span class="text-danger">*</span>' ?></label><textarea class="form-control" id="v3-reason" name="alasan" rows="2" maxlength="1000" <?= empty($input['id'])?'':'required' ?>><?= ah_e($_SERVER['REQUEST_METHOD']==='POST'?($input['alasan']??''):'') ?></textarea></div>
    <div class="col-12 d-flex flex-wrap gap-2 ah-sticky-sm"><a class="btn btn-outline-secondary" href="<?= ah_e($tutup) ?>">Batal</a><button class="btn btn-primary" type="submit">Simpan <?= ah_e(mb_strtolower($labels[$kind])) ?></button></div>
    </form>
    <?php if($history): ?><h3 class="h6 mt-4">Riwayat perubahan</h3><?= ah_timeline(array_map($kalimat,array_reverse($history)),'Riwayat perubahan data ini') ?><?php endif ?>
<?php ah_panel_close(); ?>
<?php endif ?>
<?php
$filterSelects=['status'=>['Status',[''=>'Semua','aktif'=>'Aktif sekarang','nonaktif'=>'Nonaktif','akan_datang'=>'Akan datang','berakhir'=>'Berakhir']]];
if($kind==='katalog'){$filterSelects['kategori_id']=['Kategori',[''=>'Semua']+$kategoriNama];$filterSelects['tingkat']=['Tingkat',[''=>'Semua','Ringan'=>'Ringan','Sedang'=>'Sedang','Berat'=>'Berat']];}
if($kind==='ambang'){$filterSelects['tahun_ajaran_id']=['Semester',[''=>'Semua']+$tahunNama];}
$aktifSaring=count(array_filter(array_keys($filterSelects),static fn(string $k):bool=>($_GET[$k]??'')!==''));
ah_filter_open($aktifSaring,['jenis'=>$kind]);
foreach($filterSelects as $name=>[$label,$choices]): ?><div><label class="form-label" for="filter-<?= ah_e($name) ?>"><?= ah_e($label) ?></label><select class="form-select" name="<?= ah_e($name) ?>" id="filter-<?= ah_e($name) ?>"><?php foreach($choices as $key=>$optionLabel): ?><option value="<?= ah_e($key) ?>" <?= (string)($_GET[$name]??'')===(string)$key?'selected':'' ?>><?= ah_e($optionLabel) ?></option><?php endforeach ?></select></div><?php endforeach;
ah_filter_close(['jenis'=>$kind]); ?>
<section class="ah-card" aria-labelledby="ah-daftar-katalog">
    <div class="ah-card__head"><span id="ah-daftar-katalog"><?= ah_e($labels[$kind]) ?></span><span class="text-muted small"><?= count($list['rows']) ?> dari <?= (int)$list['total'] ?></span></div>
    <?php if($list['rows']===[]): ?><div class="ah-card__body"><?= ah_empty('Belum ada data yang cocok','Tambah data baru atau ubah saringan.') ?></div><?php else: ?>
    <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden"><?= ah_e($labels[$kind]) ?></caption>
        <thead><tr><?php if($kind==='ambang'): ?><th scope="col">Label</th><th scope="col">Rentang poin</th><th scope="col">Semester</th><?php else: ?><th scope="col">Kode</th><th scope="col">Nama</th><?php if($kind==='katalog'): ?><th scope="col">Kategori</th><th scope="col">Tingkat</th><th scope="col">Poin</th><?php endif ?><?php endif ?><th scope="col" class="ah-col-secondary">Masa berlaku</th><th scope="col">Status</th><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th></tr></thead>
        <tbody><?php foreach($list['rows'] as $row): $ubah=app_url('/admin/admin_v3_katalog.php?jenis='.$kind.'&edit='.(int)$row['id']).'#ah-panel'; ?><tr>
            <?php if($kind==='ambang'): ?><td><a class="ah-row-link" href="<?= ah_e($ubah) ?>"><?= ah_e($row['label']) ?></a></td><td><?= ah_e($row['nilai_minimum'].($row['nilai_maksimum']===null?'+':'–'.$row['nilai_maksimum'])) ?></td><td><?= ah_e($tahunNama[(int)$row['tahun_ajaran_id']]??'—') ?></td>
            <?php else: ?><td><?= ah_e($row['kode']) ?></td><td><a class="ah-row-link" href="<?= ah_e($ubah) ?>"><?= ah_e($row['nama']) ?></a></td><?php if($kind==='katalog'): ?><td><?= ah_e($row['kategori_nama']) ?></td><td><?= ah_tingkat($row['tingkat']) ?></td><td><?= (int)$row['poin_default'] ?></td><?php endif ?><?php endif ?>
            <td class="ah-col-secondary"><?= ah_e(ah_tanggal($row['tanggal_mulai'],false).' – '.($row['tanggal_selesai']?ah_tanggal($row['tanggal_selesai'],false):'tanpa batas')) ?></td>
            <td><?= ah_status($row['status_efektif']==='Akan datang'?'Belum waktunya':$row['status_efektif']) ?></td>
            <td data-label=""><a class="btn btn-sm btn-outline-primary" href="<?= ah_e($ubah) ?>">Ubah</a></td>
        </tr><?php endforeach ?></tbody>
    </table></div>
    <?php endif ?>
</section>
<?php master_pagination($list['total'],$list['page'],$list['per_page']); ?>
<section class="ah-card" aria-labelledby="ah-riwayat-katalog">
    <div class="ah-card__head"><span id="ah-riwayat-katalog">Riwayat perubahan terbaru</span></div>
    <div class="ah-card__body"><?= $recent===[]?'<p class="text-muted mb-0">Belum ada perubahan.</p>':ah_timeline(array_map($kalimat,$recent),'Riwayat perubahan terbaru') ?></div>
</section>
<?php master_footer(); ?>
