<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Ui\Denial;
use App\V3\V3Exception;

/**
 * Pembinaan santri › Konseling (redesain website Tahap 5, V-3).
 *
 * Papan per status (Dibuka · Dalam pendampingan · Selesai) atau daftar
 * berhalaman. Angka dan kartu berasal dari layanan yang sama dengan daftar,
 * sehingga kasus Rahasia yang tidak boleh diketahui akun ini tidak ikut
 * tampil maupun terhitung. Status tidak diubah dari papan; menutup atau
 * membatalkan kasus wajib beralasan di halaman detail.
 *
 * "Buka kasus" adalah wizard dua langkah di panel samping: (1) pilih santri,
 * (2) tujuan, kerahasiaan, dan tautan pelanggaran/rekomendasi milik santri
 * itu saja. Langkah 1 GET (tanpa isi sensitif), langkah 2 POST + CSRF.
 */
require_once dirname(__DIR__).'/app/bootstrap.php';
$currentUser=authorization()->requireWebUser();$caps=capabilities()->v3Capabilities($currentUser);
if(array_intersect(['v3.konseling.kelola','v3.binaan.baca','v3.pengawasan','v3.koreksi'],array_keys($caps))===[]){Denial::render('Akun ini tidak memiliki akses konseling.','Akses dihitung dari penugasan pembimbing/murobi aktif atau hak pengawasan admin.');}
$service=v3_konseling_service();$error='';$input=$_POST;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        Csrf::requireValid($_POST['_csrf']??$_SERVER['HTTP_X_CSRF_TOKEN']??null);if(($_POST['aksi']??'')!=='buat')throw new V3Exception('Aksi tidak dikenal.');
        $scope=explode('|',(string)($_POST['santri_scope']??''),2);$input['santri_id']=$scope[0]??'';$input['tahun_ajaran_id']=$scope[1]??'';
        $result=$service->createCase($currentUser,$input);ah_flash_set('success',$result['replayed']?'Permintaan identik sudah pernah diproses.':'Kasus konseling dibuka.');ah_redirect('/portal/v3_konseling_detail.php?id='.(int)$result['data']['kasus']['id']);
    }catch(V3Exception $exception){$error=$exception->getMessage();}
}
$options=$service->options($currentUser);
$teks=static fn(string $key):string=>is_string($_GET[$key]??null)?mb_substr(trim($_GET[$key]),0,100):'';
$q=$teks('q');$tampil=$teks('tampil')==='daftar'?'daftar':'papan';
$status=in_array($teks('status'),['Dibuka','Dalam Pendampingan','Selesai','Dibatalkan'],true)?$teks('status'):'';
$saring=array_filter(['q'=>$q],static fn($v):bool=>$v!=='');
try{
    $counts=$service->statusCounts($currentUser,$saring);
    if($tampil==='daftar'){$list=$service->page($currentUser,$saring+array_filter(['status'=>$status,'page'=>(string)max(1,(int)($_GET['page']??1))]));}
    else{$papan=[];foreach(['Dibuka','Dalam Pendampingan','Selesai'] as $kolom){$papan[$kolom]=$service->page($currentUser,$saring+['status'=>$kolom])['rows'];}}
}catch(V3Exception $exception){$error=$error!==''?$error:$exception->getMessage();$counts=['Dibuka'=>0,'Dalam Pendampingan'=>0,'Selesai'=>0,'Dibatalkan'=>0];$list=['rows'=>[],'total'=>0,'page'=>1,'per_page'=>25];$papan=['Dibuka'=>[],'Dalam Pendampingan'=>[],'Selesai'=>[]];}

// Wizard buka kasus: santri yang dipilih harus termasuk pilihan cakupan akun.
$pilihanSantri=[];foreach($options['santri'] as $student){$pilihanSantri[$student['santri_id'].'|'.$student['tahun_ajaran_id']]=$student;}
$postGagal=$error!==''&&($_SERVER['REQUEST_METHOD']??'GET')==='POST';
$santriDipilih=$postGagal?(string)($_POST['santri_scope']??''):$teks('santri');
if(!isset($pilihanSantri[$santriDipilih]))$santriDipilih='';
$buka=$options['dapat_membuat']&&(($_GET['action']??'')==='create'||$postGagal);
$langkah2=$buka&&$santriDipilih!=='';
$tautan=['pelanggaran'=>[],'rekomendasi'=>[]];
if($langkah2){
    $s=$pilihanSantri[$santriDipilih];
    $tautan['pelanggaran']=$service->options($currentUser,$s['santri_id'],$s['tahun_ajaran_id'])['pelanggaran'];
    $tautan['rekomendasi']=array_values(array_filter($options['rekomendasi_belum_ditindaklanjuti'],static fn(array $r):bool=>$r['santri_id']===$s['santri_id']&&$r['tahun_ajaran_id']===$s['tahun_ajaran_id']));
}
$dicentang=static function(string $field,int $value)use($postGagal):bool{
    $pilih=$postGagal?($_POST[$field]??[]):($field==='rekomendasi_ids'&&isset($_GET['rekomendasi'])?[(string)$_GET['rekomendasi']]:[]);
    return is_array($pilih)&&in_array((string)$value,array_map('strval',$pilih),true);
};
$sisaQuery=ah_query(['action'=>null,'santri'=>null,'rekomendasi'=>null]);
$tutup=app_url('/portal/v3_konseling.php').($sisaQuery!==''?'?'.$sisaQuery:'');
$newKey='web-case-'.bin2hex(random_bytes(14));
$tombolBuka=$options['dapat_membuat']?'<a class="btn btn-primary" href="?'.ah_e(ah_query(['action'=>'create','santri'=>null])).'#ah-panel"><i class="fas fa-plus" aria-hidden="true"></i> Buka kasus</a>':'';
ah_page_open(['title'=>'Konseling','heading'=>'Pembinaan santri','user'=>$currentUser,
    'description'=>'Kasus konseling dalam cakupan Anda. Kasus Rahasia hanya terlihat oleh pembimbing pemiliknya dan admin.',
    'actions'=>$tombolBuka,
    'breadcrumbs'=>[['label'=>'Beranda','url'=>app_url('/portal/index.php')],['label'=>'Pembinaan','url'=>app_url('/portal/v3_pelanggaran.php')],['label'=>'Konseling']]]);
if($error!==''&&!$buka)ah_note('danger',$error);
?>
<?php if($buka): ?>
<?php ah_panel_open(['title'=>'Buka kasus konseling','close_url'=>$tutup,'description'=>'Sistem tidak pernah membuka kasus otomatis; tautan dipilih manual.']); ?>
    <?= ah_stepper(['Pilih santri','Tujuan & tautan'],$langkah2?1:0,'Langkah buka kasus') ?>
    <?php if($error!==''): ?><?php ah_note('danger',$error); ?><?php endif ?>
    <?php if(!$langkah2): ?>
        <form method="get" action="<?= ah_e(app_url('/portal/v3_konseling.php')) ?>" class="row g-3">
            <input type="hidden" name="action" value="create"><?php if($tampil==='daftar'): ?><input type="hidden" name="tampil" value="daftar"><?php endif ?>
            <div class="col-12"><label class="form-label" for="case-student">Santri <span class="text-danger">*</span></label>
                <input class="form-control mb-2" type="search" hidden data-ah-option-filter="case-student" placeholder="Ketik nama untuk menyaring" aria-label="Saring daftar santri" aria-describedby="case-student-status" autocomplete="off">
                <span class="ah-visually-hidden" id="case-student-status" aria-live="polite"></span>
                <select class="form-select" id="case-student" name="santri" required>
                    <option value="">— Pilih santri binaan (<?= count($options['santri']) ?>) —</option>
                    <?php foreach($pilihanSantri as $scope=>$student): ?><option value="<?= ah_e($scope) ?>"><?= ah_e($student['nama'].' · '.$student['semester'].' '.$student['tahun']) ?></option><?php endforeach ?>
                </select></div>
            <div class="col-12 d-flex flex-wrap gap-2 ah-sticky-sm"><a class="btn btn-outline-secondary" href="<?= ah_e($tutup) ?>">Batal</a><button class="btn btn-primary" type="submit">Lanjut</button></div>
        </form>
    <?php else: $s=$pilihanSantri[$santriDipilih]; ?>
        <form method="post" class="row g-3" action="<?= ah_e(app_url('/portal/v3_konseling.php')) ?>">
            <?= ah_csrf() ?><input type="hidden" name="aksi" value="buat"><input type="hidden" name="idempotency_key" value="<?= ah_e($input['idempotency_key']??$newKey) ?>"><input type="hidden" name="santri_scope" value="<?= ah_e($santriDipilih) ?>">
            <div class="col-12"><p class="mb-1"><b><?= ah_e($s['nama']) ?></b> · <?= ah_e($s['semester'].' '.$s['tahun']) ?></p><a class="small" href="?<?= ah_e(ah_query(['action'=>'create','santri'=>null,'rekomendasi'=>null])) ?>#ah-panel">Ganti santri</a></div>
            <div class="col-12"><label class="form-label" for="case-purpose">Tujuan pendampingan <span class="text-danger">*</span></label><textarea class="form-control" id="case-purpose" name="tujuan" rows="3" maxlength="5000" required><?= ah_e($input['tujuan']??'') ?></textarea></div>
            <div class="col-12"><label class="form-label" for="case-privacy">Kerahasiaan <span class="text-danger">*</span></label><select class="form-select" id="case-privacy" name="kerahasiaan" required><option value="">— Pilih —</option><option <?= ($input['kerahasiaan']??'')==='Internal'?'selected':'' ?>>Internal</option><option <?= ($input['kerahasiaan']??'')==='Rahasia'?'selected':'' ?>>Rahasia</option></select>
                <div class="form-text">Internal: pembimbing dan murobi terkait. Rahasia: hanya pembimbing pemilik kasus (admin dapat membuka untuk pengawasan, tercatat audit) dan tidak dapat dipublikasikan ke orang tua.</div></div>
            <div class="col-12"><label class="form-label" for="case-opened">Waktu pembukaan <span class="text-danger">*</span></label><input class="form-control" id="case-opened" type="datetime-local" name="dibuka_pada" value="<?= ah_e($input['dibuka_pada']??date('Y-m-d\TH:i')) ?>" max="<?= ah_e(date('Y-m-d\TH:i')) ?>" required></div>
            <fieldset class="col-12"><legend class="form-label">Tautkan pelanggaran (opsional)</legend>
                <?php if($tautan['pelanggaran']===[]): ?><p class="small text-muted mb-0">Santri ini belum memiliki pelanggaran berlaku semester ini.</p><?php else: ?><div class="ah-check-list"><?php foreach($tautan['pelanggaran'] as $violation): ?><label><input class="form-check-input" type="checkbox" name="pelanggaran_ids[]" value="<?= (int)$violation['id'] ?>"<?= $dicentang('pelanggaran_ids',(int)$violation['id'])?' checked':'' ?>><span><?= ah_e($violation['kategori'].' · '.ah_waktu($violation['waktu_kejadian']).' · '.$violation['poin'].' poin') ?> <?= ah_tingkat($violation['tingkat']) ?></span></label><?php endforeach ?></div><?php endif ?></fieldset>
            <fieldset class="col-12"><legend class="form-label">Tindak lanjuti rekomendasi (opsional)</legend>
                <?php if($tautan['rekomendasi']===[]): ?><p class="small text-muted mb-0">Tidak ada rekomendasi yang menunggu untuk santri ini.</p><?php else: ?><div class="ah-check-list"><?php foreach($tautan['rekomendasi'] as $recommendation): ?><label><input class="form-check-input" type="checkbox" name="rekomendasi_ids[]" value="<?= (int)$recommendation['id'] ?>"<?= $dicentang('rekomendasi_ids',(int)$recommendation['id'])?' checked':'' ?>><span><?= ah_e($recommendation['label'].' · '.$recommendation['total_poin'].' poin') ?></span></label><?php endforeach ?></div><?php endif ?></fieldset>
            <div class="col-12 d-flex flex-wrap gap-2 ah-sticky-sm"><a class="btn btn-outline-secondary" href="<?= ah_e($tutup) ?>">Batal</a><button class="btn btn-primary" type="submit">Buka kasus</button></div>
        </form>
    <?php endif ?>
<?php ah_panel_close(); ?>
<?php endif ?>
<?php
echo ah_seg([['label'=>'Papan','url'=>'?'.ah_query(['tampil'=>null,'status'=>null,'page'=>null,'action'=>null,'santri'=>null]),'active'=>$tampil==='papan'],['label'=>'Daftar','url'=>'?'.ah_query(['tampil'=>'daftar','action'=>null,'santri'=>null]),'active'=>$tampil==='daftar']],'Tampilan kasus');
ah_filter_open((int)($q!==''),array_filter(['tampil'=>$tampil==='daftar'?'daftar':'','status'=>$tampil==='daftar'?$status:'']));
?>
    <div class="ah-filter__wide"><label class="form-label" for="case-q">Cari santri</label><input class="form-control" type="search" id="case-q" name="q" maxlength="100" value="<?= ah_e($q) ?>" placeholder="Nama santri"></div>
<?php ah_filter_close(array_filter(['tampil'=>$tampil==='daftar'?'daftar':''])); ?>
<?php
$kartuKasus=static function(array $row):string{
    return '<li><a class="ah-icard" href="'.ah_e(app_url('/portal/v3_konseling_detail.php?id='.(int)$row['id'])).'">'
        .'<span class="ah-icard__head"><span>'.ah_e($row['santri_nama']).'</span>'.ah_badge($row['kerahasiaan'],$row['kerahasiaan']==='Rahasia'?'danger':'muted').'</span>'
        .'<span class="ah-icard__meta">Dibuka '.ah_e(ah_tanggal($row['dibuka_pada'],false)).' · '.(int)$row['jumlah_sesi'].' sesi</span></a></li>';
};
?>
<?php if($tampil==='papan'): ?>
    <div class="ah-board" aria-label="Kasus per status">
        <?php foreach($papan as $kolom=>$rows): $jumlah=(int)($counts[$kolom]??0); ?>
            <section class="ah-board__col" aria-labelledby="kolom-<?= ah_e(strtolower(str_replace(' ','-',$kolom))) ?>">
                <h2 class="ah-board__head" id="kolom-<?= ah_e(strtolower(str_replace(' ','-',$kolom))) ?>"><span><?= ah_e($kolom==='Dalam Pendampingan'?'Dalam pendampingan':$kolom) ?></span><span class="ah-chips__count"><?= $jumlah ?></span></h2>
                <?php if($rows===[]): ?><p class="small text-muted mb-0 px-1">Tidak ada kasus.</p><?php else: ?>
                    <ul class="ah-board__list"><?php foreach(array_slice($rows,0,6) as $row){echo $kartuKasus($row);} ?></ul>
                    <?php if($jumlah>6): ?><a class="ah-board__more" href="?<?= ah_e(ah_query(['tampil'=>'daftar','status'=>$kolom])) ?>">+<?= $jumlah-6 ?> lainnya · Lihat semua</a><?php endif ?>
                <?php endif ?>
            </section>
        <?php endforeach ?>
    </div>
    <?php if(($counts['Dibatalkan']??0)>0): ?><p class="small text-muted"><a href="?<?= ah_e(ah_query(['tampil'=>'daftar','status'=>'Dibatalkan'])) ?>"><?= (int)$counts['Dibatalkan'] ?> kasus dibatalkan</a> tidak ditampilkan di papan.</p><?php endif ?>
<?php else: ?>
    <?php
    $chips=[['label'=>'Semua','url'=>'?'.ah_query(['status'=>null,'page'=>null]),'active'=>$status==='','count'=>array_sum($counts)]];
    foreach($counts as $nilai=>$jumlah){$chips[]=['label'=>$nilai==='Dalam Pendampingan'?'Dalam pendampingan':$nilai,'url'=>'?'.ah_query(['status'=>$nilai,'page'=>null]),'active'=>$status===$nilai,'count'=>$jumlah];}
    echo ah_chips($chips,'Saring menurut status');
    ?>
    <section class="ah-card" aria-labelledby="ah-daftar-kasus">
        <div class="ah-card__head"><span id="ah-daftar-kasus">Daftar kasus konseling</span><span class="text-muted small"><?= count($list['rows']) ?> dari <?= (int)$list['total'] ?></span></div>
        <?php if($list['rows']===[]): ?><div class="ah-card__body"><?= ah_empty('Belum ada kasus','Belum ada kasus konseling yang dapat dibaca dalam cakupan dan saringan ini.') ?></div><?php else: ?>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Kasus konseling dalam cakupan Anda</caption>
            <thead><tr><th scope="col">Santri</th><th scope="col">Dibuka</th><th scope="col">Kerahasiaan</th><th scope="col">Sesi</th><th scope="col">Status</th></tr></thead><tbody>
            <?php foreach($list['rows'] as $row): ?><tr><td><span class="ah-cell-wrap"><a class="ah-row-link" href="<?= ah_e(app_url('/portal/v3_konseling_detail.php?id='.(int)$row['id'])) ?>"><?= ah_e($row['santri_nama']) ?></a><span class="ah-cell-sub"><?= ah_e($row['semester'].' '.$row['tahun']) ?></span></span></td><td><?= ah_e(ah_tanggal($row['dibuka_pada'],false)) ?></td><td><?= ah_badge($row['kerahasiaan'],$row['kerahasiaan']==='Rahasia'?'danger':'muted') ?></td><td><?= (int)$row['jumlah_sesi'] ?></td><td><?= ah_status($row['status']) ?></td></tr><?php endforeach ?>
            </tbody></table></div>
        <?php endif ?>
    </section>
    <?php ah_pagination((int)$list['total'],(int)$list['page'],(int)$list['per_page']); ?>
<?php endif ?>
<?php $rekomendasiTertunda=$options['rekomendasi_belum_ditindaklanjuti']; ?>
<section class="ah-card" id="rekomendasi" aria-labelledby="rekomendasi-judul">
    <div class="ah-card__head"><span id="rekomendasi-judul">Rekomendasi belum ditindaklanjuti (<?= count($rekomendasiTertunda) ?>)</span></div>
    <?php if($rekomendasiTertunda===[]): ?><div class="ah-card__body"><?= ah_empty('Tidak ada rekomendasi tertunda','Rekomendasi baru muncul saat total poin santri mencapai ambang yang berlaku.') ?></div>
    <?php else: ?>
        <div class="ah-card__body pb-0"><p class="text-muted small mb-2"><?= $options['dapat_membuat']?'Tekan "Buka kasus" pada baris untuk menindaklanjuti rekomendasi lewat kasus konseling baru.':'Tindak lanjut dibuka oleh pembimbing santri; daftar ini untuk pemantauan.' ?></p></div>
        <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Rekomendasi pembinaan yang belum ditindaklanjuti</caption>
            <thead><tr><th scope="col">Santri</th><th scope="col">Ambang</th><th scope="col">Total poin</th><?php if($options['dapat_membuat']): ?><th scope="col"><span class="ah-visually-hidden">Tindakan</span></th><?php endif ?></tr></thead><tbody>
            <?php foreach($rekomendasiTertunda as $recommendation): $scopeRek=$recommendation['santri_id'].'|'.$recommendation['tahun_ajaran_id']; ?><tr data-rekomendasi="<?= (int)$recommendation['id'] ?>"><td><?= ah_e($recommendation['santri_nama']) ?></td><td><?= ah_e($recommendation['label']) ?></td><td><?= (int)$recommendation['total_poin'] ?></td><?php if($options['dapat_membuat']): ?><td data-label=""><?php if(isset($pilihanSantri[$scopeRek])): ?><a class="btn btn-sm btn-outline-primary" href="?<?= ah_e(ah_query(['action'=>'create','santri'=>$scopeRek,'rekomendasi'=>(int)$recommendation['id']])) ?>#ah-panel">Buka kasus</a><?php endif ?></td><?php endif ?></tr><?php endforeach ?>
            </tbody></table></div>
    <?php endif ?>
</section>
<?php ah_page_close(); ?>
