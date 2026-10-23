<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Ui\Denial;
use App\V3\AttachmentStorage;
use App\V3\V3Exception;

/**
 * Detail pelanggaran (redesain website Tahap 5, V-2).
 *
 * Tab Ringkasan · Poin santri · Lampiran · Konseling · Riwayat. Kolom kanan:
 * total poin santri terhadap ambang katalog (M-4) dan tindakan yang boleh,
 * masing-masing dalam dialog. "Siapkan publikasi" hanya aktif bila layanan
 * publikasi menerima sumber ini; bila ditolak (mis. tertaut kasus Rahasia)
 * tombolnya nonaktif dengan sebab dari server.
 */
require_once dirname(__DIR__).'/app/bootstrap.php';
$currentUser=authorization()->requireWebUser();$caps=capabilities()->v3Capabilities($currentUser);
if(array_intersect(['v3.pelanggaran.kelola','v3.binaan.baca','v3.pengawasan','v3.koreksi'],array_keys($caps))===[]){Denial::render('Akun ini tidak memiliki akses pelanggaran.','Akses mengikuti penugasan aktif atau hak pengawasan admin.');}
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);if(!$id){Denial::render('Catatan tidak ditemukan.','Nomor catatan tidak valid.');}
$service=v3_pelanggaran_service();$error='';$errorAksi='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        Csrf::requireValid($_POST['_csrf']??$_SERVER['HTTP_X_CSRF_TOKEN']??null);$action=(string)($_POST['aksi']??'');$errorAksi=$action;
        if($action==='koreksi'){$storage=new AttachmentStorage(APP_ROOT.'/storage/private/v3');$file=$storage->stageUpload($_FILES['lampiran']??null);$result=$service->correct($currentUser,(int)$id,$_POST,$file);$target=(int)$result['data']['pelanggaran']['id'];}
        elseif($action==='batal'){$result=$service->cancel($currentUser,(int)$id,$_POST);$target=(int)$id;}
        elseif($action==='diketahui'){$result=$service->acknowledge($currentUser,(int)$id,$_POST);$target=(int)$id;}
        else{throw new V3Exception('Aksi tidak dikenal.');}
        $pesan=['koreksi'=>'Koreksi tersimpan sebagai revisi baru; catatan lama tetap di riwayat.','batal'=>'Catatan dibatalkan; poinnya dikurangkan dari total santri.','diketahui'=>'Tanda mengetahui murobi tersimpan.'][$action];
        ah_flash_set('success',$result['replayed']?'Permintaan identik sudah diproses.':$pesan);ah_redirect('/portal/v3_pelanggaran_detail.php?id='.$target);
    }catch(V3Exception $exception){$error=$exception->getMessage();}
}
try{$detail=$service->show($currentUser,(int)$id);}catch(V3Exception $exception){Denial::render('Catatan tidak ditemukan atau tidak dapat diakses.',$exception->getMessage());}
$row=$detail['pelanggaran'];$current=$row['digantikan_oleh_id']===null;$canManage=isset($caps['v3.pelanggaran.kelola'])||isset($caps['v3.koreksi']);
$canAcknowledge=isset($caps['v3.murobi.mengetahui'])&&capabilities()->v3AppliesToSantri($currentUser,'v3.murobi.mengetahui',$row['santri_id'],$row['tahun_ajaran_id']);
$key=static fn(string $action):string=>'web-'.$action.'-'.bin2hex(random_bytes(12));
$bisaKoreksi=$current&&$canManage&&$row['status']!=='Dibatalkan';

// Publikasi: tombol mengikuti keputusan layanan publikasi, bukan tebakan halaman.
$publikasi=null;
if($current&&(isset($caps['v3.koreksi'])||isset($caps['v3.pelanggaran.kelola']))){
    try{v3_publikasi_service()->options($currentUser,'pelanggaran',(int)$id);$publikasi=['ok'=>true];}
    catch(V3Exception $exception){$publikasi=$exception->status===403?null:['ok'=>false,'sebab'=>$exception->getMessage()];}
}

// Poin santri: catatan berlaku = baris ledger positif yang belum dibalik.
$dibalik=array_filter(array_map(static fn(array $l):?int=>$l['pembalik_dari_id'],$detail['ledger']));
$catatanBerlaku=count(array_filter($detail['ledger'],static fn(array $l):bool=>$l['perubahan_poin']>0&&!in_array($l['id'],$dibalik,true)));
$total=(int)$detail['total_poin'];$ambangKini=null;$ambangBerikut=null;
foreach($detail['ambang'] as $a){
    if($a['nilai_minimum']<=$total&&($a['nilai_maksimum']===null||$a['nilai_maksimum']>=$total)){$ambangKini=$a;}
    elseif($a['nilai_minimum']>$total&&$ambangBerikut===null){$ambangBerikut=$a;}
}
$rentangAmbang=static fn(array $a):string=>$a['nilai_minimum'].($a['nilai_maksimum']===null?'+':'–'.$a['nilai_maksimum']);

$tab=in_array($_GET['tab']??'',['poin','lampiran','konseling','riwayat'],true)?(string)$_GET['tab']:'ringkasan';
$tabUrl=static fn(string $t):string=>'?'.ah_query(['tab'=>$t==='ringkasan'?null:$t],['id'=>(int)$id]);
$judul=(string)($row['jenis']??$row['kategori']);
ah_page_open(['title'=>$judul.' · '.$row['santri_nama'],'heading'=>$judul,'user'=>$currentUser,
    'description'=>$row['santri_nama'].' · '.ah_waktu($row['waktu_kejadian']).($row['tempat']?' · '.$row['tempat']:''),
    'breadcrumbs'=>[['label'=>'Pembinaan','url'=>app_url('/portal/v3_pelanggaran.php')],['label'=>'Pelanggaran','url'=>app_url('/portal/v3_pelanggaran.php')],['label'=>$row['santri_nama'].' · '.ah_tanggal($row['waktu_kejadian'],false,false)]]]);
if($error!==''&&$errorAksi===''){ah_note('danger',$error);}
foreach($detail['peringatan_konfigurasi'] as $warning){ah_note('warning',$warning);}
if(!$current){ah_note('info','Catatan ini sudah dikoreksi dan digantikan revisi yang lebih baru.','<p class="mb-0 mt-2"><a href="'.ah_e(app_url('/portal/v3_pelanggaran_detail.php?id='.(int)$row['digantikan_oleh_id'])).'">Buka revisi terbaru</a></p>');}
echo ah_seg([
    ['label'=>'Ringkasan','url'=>$tabUrl('ringkasan'),'active'=>$tab==='ringkasan'],
    ['label'=>'Poin santri','url'=>$tabUrl('poin'),'active'=>$tab==='poin'],
    ['label'=>'Lampiran '.count($detail['lampiran']),'url'=>$tabUrl('lampiran'),'active'=>$tab==='lampiran'],
    ['label'=>'Konseling '.count(array_unique(array_column($detail['konseling'],'kasus_id'))),'url'=>$tabUrl('konseling'),'active'=>$tab==='konseling'],
    ['label'=>'Riwayat','url'=>$tabUrl('riwayat'),'active'=>$tab==='riwayat'],
],'Bagian detail pelanggaran');
?>
<div class="ah-split">
    <div class="ah-split__col">
    <?php if($tab==='ringkasan'): ?>
        <section class="ah-card" aria-labelledby="ah-catatan">
            <div class="ah-card__head"><span id="ah-catatan">Catatan</span><?= ah_status($row['status']) ?></div>
            <div class="ah-card__body"><dl class="ah-info-list">
                <div><dt>Santri</dt><dd><?= ah_e($row['santri_nama']) ?> · <?= ah_e($row['semester'].' '.$row['tahun']) ?></dd></div>
                <div><dt>Jenis</dt><dd><?= ah_e($judul) ?><?= ($row['jenis']??null)!==null?' · '.ah_e($row['kategori']):'' ?> <?= ah_tingkat($row['tingkat']) ?></dd></div>
                <div><dt>Poin</dt><dd><?= (int)$row['poin'] ?></dd></div>
                <div><dt>Waktu &amp; tempat</dt><dd><?= ah_e(ah_waktu($row['waktu_kejadian']).' · '.($row['tempat']??'Tempat tidak dicatat')) ?></dd></div>
                <div><dt>Uraian</dt><dd style="white-space:pre-wrap"><?= ah_e($row['uraian']) ?></dd></div>
                <div><dt>Saksi</dt><dd style="white-space:pre-wrap"><?= ah_e($row['saksi']??'—') ?></dd></div>
                <div><dt>Dicatat</dt><dd><?= ah_e(($row['pencatat']??'Admin pesantren').' · '.ah_waktu($row['created_at'])) ?></dd></div>
                <?php if($row['alasan_revisi']): ?><div><dt>Alasan koreksi</dt><dd style="white-space:pre-wrap"><?= ah_e($row['alasan_revisi']) ?></dd></div><?php endif ?>
                <?php if($row['alasan_pembatalan']): ?><div><dt>Alasan pembatalan</dt><dd style="white-space:pre-wrap"><?= ah_e($row['alasan_pembatalan']) ?></dd></div><?php endif ?>
            </dl></div>
        </section>
    <?php elseif($tab==='poin'): ?>
        <section class="ah-card" aria-labelledby="ah-poin">
            <div class="ah-card__head"><span id="ah-poin">Perubahan poin <?= ah_e($row['santri_nama']) ?> · <?= ah_e($row['semester'].' '.$row['tahun']) ?></span></div>
            <?php if($detail['ledger']===[]): ?><div class="ah-card__body"><p class="text-muted mb-0">Belum ada perubahan poin.</p></div><?php else: ?>
            <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden">Perubahan poin santri</caption>
                <thead><tr><th scope="col">Waktu</th><th scope="col">Perubahan</th><th scope="col">Keterangan</th></tr></thead><tbody>
                <?php foreach($detail['ledger'] as $ledger): ?><tr><td><?= ah_e(ah_waktu($ledger['created_at'])) ?></td><td><?= $ledger['perubahan_poin']>=0?'+':'' ?><?= (int)$ledger['perubahan_poin'] ?></td><td><?= ah_e($ledger['pembalik_dari_id']?'Pengurangan karena catatan dikoreksi atau dibatalkan':$ledger['alasan']) ?></td></tr><?php endforeach ?>
                </tbody></table></div>
            <?php endif ?>
        </section>
    <?php elseif($tab==='lampiran'): ?>
        <section class="ah-card" aria-labelledby="ah-lampiran">
            <div class="ah-card__head"><span id="ah-lampiran">Lampiran privat</span></div>
            <div class="ah-card__body"><?php if($detail['lampiran']===[]): ?><p class="text-muted mb-0">Tidak ada lampiran.</p><?php else: ?>
                <ul class="ah-qlist"><?php foreach($detail['lampiran'] as $file): ?><li><a href="<?= ah_e(app_url('/portal/v3_lampiran.php?id='.(int)$file['id'])) ?>"><span class="ah-qlist__main"><b><?= ah_e($file['nama']) ?></b><small><?= ah_e($file['mime'].' · '.number_format($file['ukuran']/1024,1,',','.').' KB · '.ah_waktu($file['created_at'])) ?></small></span><i class="fas fa-download" aria-hidden="true"></i></a></li><?php endforeach ?></ul>
                <p class="small text-muted mt-2 mb-0">Lampiran hanya dapat diunduh petugas dalam cakupan; tidak pernah dibagikan kepada orang tua.</p>
            <?php endif ?></div>
        </section>
    <?php elseif($tab==='konseling'): ?>
        <section class="ah-card" aria-labelledby="ah-konseling">
            <div class="ah-card__head"><span id="ah-konseling">Tindak lanjut konseling</span></div>
            <div class="ah-card__body"><?php if($detail['konseling']===[]): ?><p class="text-muted mb-0">Belum ditautkan ke kasus konseling.</p><?php else: $perKasus=[]; foreach($detail['konseling'] as $f){$perKasus[$f['kasus_id']][]=$f;} ?>
                <ul class="ah-qlist"><?php foreach($perKasus as $kasusId=>$items): ?><li><a href="<?= ah_e(app_url('/portal/v3_konseling_detail.php?id='.(int)$kasusId)) ?>"><span class="ah-qlist__main"><b>Kasus konseling <?= ah_e($row['santri_nama']) ?></b><small><?= ah_e(implode(' · ',array_map(static fn(array $f):string=>$f['sesi_id']?'Sesi '.ah_tanggal((string)$f['jadwal'],false).' '.$f['sesi_status']:'Belum ada sesi',$items))) ?></small></span><?= ah_status($items[0]['kasus_status']) ?></a></li><?php endforeach ?></ul>
            <?php endif ?></div>
        </section>
    <?php else: ?>
        <section class="ah-card" aria-labelledby="ah-riwayat">
            <div class="ah-card__head"><span id="ah-riwayat">Riwayat catatan</span></div>
            <div class="ah-card__body"><?php
                $linimasa=[];
                foreach($detail['riwayat_revisi'] as $revision){
                    $linimasa[]=['t'=>(string)$revision['created_at'],'waktu'=>ah_waktu($revision['created_at']),'pelaku'=>$revision['id']===(int)$id?'catatan ini':'revisi lain',
                        'judul'=>$revision['revisi_dari_id']===null?'Catatan dibuat':'Koreksi menjadi revisi baru',
                        'isi'=>trim(($revision['alasan_revisi']?'Alasan koreksi: '.$revision['alasan_revisi']:'').($revision['alasan_pembatalan']?"\nDibatalkan: ".$revision['alasan_pembatalan']:'')),'tone'=>$revision['status']==='Dibatalkan'?'warn':''];
                }
                foreach($detail['murobi'] as $note){$linimasa[]=['t'=>(string)$note['diketahui_pada'],'waktu'=>ah_waktu($note['diketahui_pada']),'pelaku'=>'murobi','judul'=>'Murobi menandai mengetahui','isi'=>(string)($note['catatan']??'')];}
                usort($linimasa,static fn(array $a,array $b):int=>strcmp($a['t'],$b['t']));
                echo ah_timeline(array_map(static fn(array $i):array=>array_diff_key($i,['t'=>true]),$linimasa),'Riwayat catatan dan tanda mengetahui');
            ?>
            <?php if(count($detail['riwayat_revisi'])>1): ?><p class="small mt-3 mb-0">Revisi: <?php foreach($detail['riwayat_revisi'] as $i=>$revision): ?><?= $i>0?' · ':'' ?><?php if($revision['id']===(int)$id): ?><b>versi <?= $i+1 ?> (dibuka)</b><?php else: ?><a href="?id=<?= (int)$revision['id'] ?>&amp;tab=riwayat">versi <?= $i+1 ?></a><?php endif ?><?php endforeach ?></p><?php endif ?>
            </div>
        </section>
    <?php endif ?>
    </div>
    <div class="ah-split__col">
        <section class="ah-card" aria-labelledby="ah-total-poin">
            <div class="ah-card__head"><span id="ah-total-poin">Poin santri semester ini</span></div>
            <div class="ah-card__body">
                <p class="ah-big mb-2"><?= $total ?> <small>poin · <?= $catatanBerlaku ?> catatan</small></p>
                <?php if($detail['ambang']===[]): ?>
                    <p class="small text-muted mb-0">Belum ada ambang poin yang berlaku untuk semester ini.</p>
                <?php else: $batas=$ambangBerikut['nilai_minimum']??($ambangKini['nilai_maksimum']??max($total,1)); $persen=(int)min(100,round($total/max(1,$batas)*100)); ?>
                    <div class="ah-meter">
                        <div class="ah-meter__label"><span><?= $ambangKini!==null?'Ambang: '.ah_e($ambangKini['label']).' ('.$rentangAmbang($ambangKini).')':'Belum mencapai ambang' ?></span><span><?= $total ?><?= $ambangBerikut!==null?' / '.(int)$ambangBerikut['nilai_minimum']:'' ?></span></div>
                        <div class="ah-meter__bar<?= $ambangKini!==null?' ah-meter__bar--warn':'' ?>" role="img" aria-label="<?= $total ?> poin"><span style="width:<?= $persen ?>%"></span></div>
                        <?php if($ambangBerikut!==null): ?><p class="small text-muted mb-0">Berikutnya: <?= ah_e($ambangBerikut['label']) ?> mulai <?= (int)$ambangBerikut['nilai_minimum'] ?> poin.</p><?php endif ?>
                    </div>
                <?php endif ?>
                <?php foreach($detail['rekomendasi'] as $recommendation): ?>
                    <div class="ah-note ah-note--<?= $recommendation['berlaku']&&$recommendation['status']==='Baru'?'warn':'info' ?> mt-3 mb-0"><div>
                        <strong class="ah-note__title">Rekomendasi dari ambang <?= ah_e($recommendation['label']) ?></strong>
                        <span><?= ah_e($recommendation['rekomendasi']) ?></span>
                        <span class="d-block small"><?= !$recommendation['berlaku']?'Tidak berlaku lagi: '.ah_e((string)$recommendation['tidak_berlaku_alasan']):($recommendation['status']==='Baru'?'Belum ditindaklanjuti.':'Sudah ditindaklanjuti.') ?></span>
                    </div></div>
                <?php endforeach ?>
                <?php if($detail['rekonsiliasi']['selisih']!==0&&isset($caps['v3.pengawasan'])): ?>
                    <?php ah_note('warning','Total tersimpan ('.(int)$detail['rekonsiliasi']['agregat'].') berbeda dengan rincian poin ('.(int)$detail['rekonsiliasi']['ledger'].'). Jalankan rekonsiliasi poin.'); ?>
                <?php endif ?>
            </div>
        </section>
        <section class="ah-card ah-no-print" aria-labelledby="ah-tindakan">
            <div class="ah-card__head"><span id="ah-tindakan">Tindakan</span></div>
            <div class="ah-card__body"><div class="ah-acts">
                <?php if($publikasi!==null&&$publikasi['ok']): ?>
                    <a class="btn btn-outline-primary" href="<?= ah_e(app_url('/portal/v3_publikasi_kelola.php?sumber_type=pelanggaran&sumber_id='.(int)$id)) ?>"><i class="fas fa-paper-plane" aria-hidden="true"></i> Siapkan publikasi ke orang tua</a>
                <?php elseif($publikasi!==null): ?>
                    <button class="btn btn-outline-secondary" type="button" disabled aria-describedby="sebab-publikasi">Siapkan publikasi ke orang tua</button>
                    <p class="small text-muted mb-0" id="sebab-publikasi"><?= ah_e($publikasi['sebab']) ?></p>
                <?php endif ?>
                <?php if($bisaKoreksi): ?>
                    <?php ah_action_dialog_open('aksi-koreksi','Koreksi','Koreksi catatan','outline-primary',$errorAksi==='koreksi'); ?>
                        <?php if($errorAksi==='koreksi'): ?><?php ah_note('danger',$error); ?><?php endif ?>
                        <form method="post" enctype="multipart/form-data">
                            <?= ah_csrf() ?><input type="hidden" name="aksi" value="koreksi"><input type="hidden" name="version" value="<?= (int)$row['version'] ?>"><input type="hidden" name="idempotency_key" value="<?= ah_e($key('correct')) ?>"><input type="hidden" name="katalog_id" value="<?= (int)$row['katalog_id'] ?>">
                            <p class="small text-muted">Koreksi membuat revisi baru dan menyesuaikan poin; catatan ini tetap tersimpan di riwayat.</p>
                            <label class="form-label" for="corr-time">Waktu kejadian</label><input class="form-control mb-2" id="corr-time" type="datetime-local" name="waktu_kejadian" value="<?= ah_e(str_replace(' ','T',substr($row['waktu_kejadian'],0,16))) ?>" required>
                            <label class="form-label" for="corr-place">Tempat</label><input class="form-control mb-2" id="corr-place" name="tempat" maxlength="255" value="<?= ah_e($row['tempat']??'') ?>">
                            <label class="form-label" for="corr-desc">Uraian</label><textarea class="form-control mb-2" id="corr-desc" name="uraian" rows="3" maxlength="5000" required><?= ah_e($row['uraian']) ?></textarea>
                            <label class="form-label" for="corr-witness">Saksi</label><textarea class="form-control mb-2" id="corr-witness" name="saksi" rows="2" maxlength="2000"><?= ah_e($row['saksi']??'') ?></textarea>
                            <?php if(isset($caps['v3.koreksi'])): ?><label class="form-label" for="corr-point">Poin (koreksi admin)</label><input class="form-control mb-2" id="corr-point" type="number" name="poin" min="0" max="2147483647" value="<?= (int)$row['poin'] ?>"><?php endif ?>
                            <label class="form-label" for="corr-file">Bukti tambahan (privat)</label><input class="form-control mb-2" id="corr-file" type="file" name="lampiran" accept="image/jpeg,image/png,application/pdf">
                            <label class="form-label" for="corr-reason">Alasan koreksi <span class="text-danger">*</span></label><textarea class="form-control" id="corr-reason" name="alasan" rows="2" maxlength="1000" required></textarea>
                            <div class="ah-act__actions"><button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Batal</button><button class="btn btn-primary" type="submit">Simpan koreksi</button></div>
                        </form>
                    <?php ah_action_dialog_close(); ?>
                    <?php ah_action_dialog_open('aksi-batal','Batalkan catatan','Batalkan catatan ini?','outline-danger',$errorAksi==='batal'); ?>
                        <?php if($errorAksi==='batal'): ?><?php ah_note('danger',$error); ?><?php endif ?>
                        <form method="post">
                            <?= ah_csrf() ?><input type="hidden" name="aksi" value="batal"><input type="hidden" name="version" value="<?= (int)$row['version'] ?>"><input type="hidden" name="idempotency_key" value="<?= ah_e($key('cancel')) ?>">
                            <p class="small">Poin <?= (int)$row['poin'] ?> akan dikurangkan dari total santri. Catatan tidak dihapus dan tetap terlihat di riwayat.</p>
                            <label class="form-label" for="cancel-reason">Alasan pembatalan <span class="text-danger">*</span></label><textarea class="form-control" id="cancel-reason" name="alasan" rows="2" maxlength="1000" required></textarea>
                            <div class="ah-act__actions"><button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Kembali</button><button class="btn btn-danger" type="submit">Batalkan catatan</button></div>
                        </form>
                    <?php ah_action_dialog_close(); ?>
                <?php endif ?>
                <?php if($canAcknowledge): ?>
                    <?php ah_action_dialog_open('aksi-ketahui','Tandai mengetahui','Tandai mengetahui sebagai murobi','primary',$errorAksi==='diketahui'); ?>
                        <?php if($errorAksi==='diketahui'): ?><?php ah_note('danger',$error); ?><?php endif ?>
                        <form method="post">
                            <?= ah_csrf() ?><input type="hidden" name="aksi" value="diketahui"><input type="hidden" name="idempotency_key" value="<?= ah_e($key('ack')) ?>">
                            <label class="form-label" for="murobi-note">Catatan murobi (opsional)</label><textarea class="form-control" id="murobi-note" name="catatan" rows="2" maxlength="2000"></textarea>
                            <div class="ah-act__actions"><button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Batal</button><button class="btn btn-primary" type="submit">Tandai mengetahui</button></div>
                        </form>
                    <?php ah_action_dialog_close(); ?>
                <?php endif ?>
                <?php if($publikasi===null&&!$bisaKoreksi&&!$canAcknowledge): ?><p class="small text-muted mb-0">Tidak ada tindakan untuk akun ini. Halaman ini hanya-baca.</p><?php endif ?>
                <?php if($detail['murobi']!==[]): ?><p class="small text-muted mb-0">Murobi sudah menandai mengetahui <?= count($detail['murobi']) ?> kali.</p><?php endif ?>
            </div></div>
        </section>
    </div>
</div>
<?php $utama=$canAcknowledge?['aksi-ketahui','Tandai mengetahui']:($bisaKoreksi?['aksi-koreksi','Koreksi']:null); ?>
<?php if($utama!==null): ?>
    <div class="ah-actbar ah-only-sm ah-no-print"><a class="btn btn-primary" href="#<?= ah_e($utama[0]) ?>" data-ah-open-dialog="<?= ah_e($utama[0]) ?>"><?= ah_e($utama[1]) ?></a></div>
<?php endif ?>
<?php ah_page_close(); ?>
