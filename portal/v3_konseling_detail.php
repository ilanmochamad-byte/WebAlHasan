<?php

declare(strict_types=1);

use App\Http\Csrf;
use App\Ui\Denial;
use App\V3\V3Exception;

/**
 * Detail kasus konseling (redesain website Tahap 5, V-4).
 *
 * Delapan formulir bertumpuk diganti tab (Sesi · Tautan · Catatan murobi ·
 * Riwayat) dan dialog. Setiap sesi tampil sebagai kartu dengan satu tombol
 * yang membuka dialognya; dialog "Catat hasil sesi" hanya menampilkan isian
 * yang relevan dengan hasil yang dipilih. Kolom kanan memuat status kasus
 * (mulai, tutup, batalkan, koreksi — beralasan), publikasi, dan pelanggaran
 * terkait. Semua mutasi tetap POST + CSRF ke layanan konseling yang sama.
 */
require_once dirname(__DIR__).'/app/bootstrap.php';
$currentUser=authorization()->requireWebUser();$caps=capabilities()->v3Capabilities($currentUser);
if(array_intersect(['v3.konseling.kelola','v3.binaan.baca','v3.pengawasan','v3.koreksi'],array_keys($caps))===[]){Denial::render('Akun ini tidak memiliki akses konseling.','Akses mengikuti penugasan aktif atau hak pengawasan admin.');}
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);if(!$id)Denial::render('Kasus tidak ditemukan.','Nomor kasus tidak valid.');
$service=v3_konseling_service();$error='';$errorAksi='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        Csrf::requireValid($_POST['_csrf']??$_SERVER['HTTP_X_CSRF_TOKEN']??null);$action=(string)($_POST['aksi']??'');$result=null;
        $errorAksi=$action.(isset($_POST['sesi_id'])?'-'.(int)$_POST['sesi_id']:'').($action==='status_kasus'?'-'.(string)($_POST['status']??''):'');
        if($action==='koreksi_kasus')$result=$service->correctCase($currentUser,(int)$id,$_POST);
        elseif($action==='status_kasus')$result=$service->transitionCase($currentUser,(int)$id,$_POST);
        elseif($action==='tambah_tautan')$result=$service->addLinks($currentUser,(int)$id,$_POST);
        elseif($action==='buat_sesi')$result=$service->createSession($currentUser,(int)$id,$_POST);
        elseif($action==='status_sesi')$result=$service->transitionSession($currentUser,(int)($_POST['sesi_id']??0),$_POST);
        elseif($action==='koreksi_sesi')$result=$service->correctSession($currentUser,(int)($_POST['sesi_id']??0),$_POST);
        elseif($action==='diketahui_kasus')$result=$service->acknowledge($currentUser,'kasus',(int)$id,$_POST);
        elseif($action==='diketahui_sesi')$result=$service->acknowledge($currentUser,'sesi',(int)($_POST['sesi_id']??0),$_POST);
        else throw new V3Exception('Aksi tidak dikenal.');
        ah_flash_set('success',$result['replayed']?'Permintaan identik sudah pernah diproses.':'Perubahan konseling tersimpan.');ah_redirect('/portal/v3_konseling_detail.php?id='.(int)$id.(str_contains($action,'tautan')?'&tab=tautan':''));
    }catch(V3Exception $exception){$error=$exception->getMessage();}
}
try{$detail=$service->show($currentUser,(int)$id);$timeline=$service->timeline($currentUser,(int)$id,$detail);}catch(V3Exception $exception){Denial::render('Kasus tidak ditemukan atau tidak dapat diakses.',$exception->getMessage());}
$case=$detail['kasus'];$canManage=isset($caps['v3.konseling.kelola'])&&capabilities()->v3AppliesToSantri($currentUser,'v3.konseling.kelola',$case['santri_id'],$case['tahun_ajaran_id']);$canCorrect=$canManage||isset($caps['v3.koreksi']);$canAck=isset($caps['v3.murobi.mengetahui'])&&capabilities()->v3AppliesToSantri($currentUser,'v3.murobi.mengetahui',$case['santri_id'],$case['tahun_ajaran_id']);$open=!in_array($case['status'],['Selesai','Dibatalkan'],true);$options=$canManage?$service->options($currentUser,$case['santri_id'],$case['tahun_ajaran_id']):['pelanggaran'=>[],'rekomendasi_belum_ditindaklanjuti'=>[]];$key=static fn(string $action):string=>'web-'.$action.'-'.bin2hex(random_bytes(12));
// Pilihan tautan manual: pelanggaran yang belum tertaut pada tingkat kasus (termasuk revisinya) dan rekomendasi antrean milik santri/tahun kasus ini.
$caseLinked=[];foreach($detail['tautan'] as $link){if($link['sesi_id']===null){$caseLinked[]=$link['pelanggaran_id'];if($link['pelanggaran_digantikan_oleh_id']!==null)$caseLinked[]=$link['pelanggaran_digantikan_oleh_id'];}}
$linkableViolations=array_values(array_filter($options['pelanggaran'],static fn(array $violation):bool=>!in_array($violation['id'],$caseLinked,true)));
$linkableRecommendations=array_values(array_filter($options['rekomendasi_belum_ditindaklanjuti'],static fn(array $recommendation):bool=>$recommendation['santri_id']===$case['santri_id']&&$recommendation['tahun_ajaran_id']===$case['tahun_ajaran_id']));
$rahasia=$case['kerahasiaan']==='Rahasia';$internal=$detail['akses_internal'];
$gagal=static fn(string $aksi):bool=>$error!==''&&$aksi===$errorAksi;
$galatDi=static function(string $aksi)use($error,$errorAksi):void{if($error!==''&&$aksi===$errorAksi)ah_note('danger',$error);};

// Publikasi ke orang tua mengikuti keputusan layanan publikasi (Rahasia ditolak).
$publikasi=null;
if(isset($caps['v3.koreksi'])||isset($caps['v3.konseling.kelola'])){
    try{v3_publikasi_service()->options($currentUser,'kasus',(int)$id);$publikasi=['ok'=>true];}
    catch(V3Exception $exception){$publikasi=$exception->status===403?null:['ok'=>false,'sebab'=>$exception->getMessage()];}
}
$sesiTerkini=array_values(array_filter($detail['sesi'],static fn(array $s):bool=>$s['digantikan_oleh_id']===null));
// Nomor sesi untuk tampilan: sesi asal berurutan; revisi memakai nomor sesi asalnya.
$asal=[];foreach($detail['sesi'] as $s){$asal[$s['id']]=$s['revisi_dari_id'];}
$akarSesi=static function(int $sid)use($asal):int{$jaga=0;while(($asal[$sid]??null)!==null&&$jaga++<100){$sid=(int)$asal[$sid];}return $sid;};
$akarUrut=array_keys(array_filter($asal,static fn($dari):bool=>$dari===null));sort($akarUrut);
$nomorSesi=[];foreach($detail['sesi'] as $s){$nomorSesi[$s['id']]=(int)array_search($akarSesi($s['id']),$akarUrut,true)+1;}
$tab=in_array($_GET['tab']??'',['tautan','murobi','riwayat'],true)?(string)$_GET['tab']:'sesi';
if($error!==''&&in_array(strtok($errorAksi,'-'),['tambah_tautan'],true))$tab='tautan';
if($error!==''&&in_array(strtok($errorAksi,'-'),['diketahui_kasus'],true))$tab='murobi';
$tabUrl=static fn(string $t):string=>'?'.ah_query(['tab'=>$t==='sesi'?null:$t],['id'=>(int)$id]);
$aksiKepala='<a class="btn btn-outline-secondary" href="'.ah_e(app_url('/portal/v3_konseling_cetak.php?id='.(int)$id)).'" target="_blank" rel="noopener"><i class="fas fa-print" aria-hidden="true"></i> Cetak</a>';
ah_page_open(['title'=>'Kasus konseling · '.$case['santri_nama'],'heading'=>'Kasus konseling · '.$case['santri_nama'],'user'=>$currentUser,
    'description'=>'Dibuka '.ah_tanggal($case['dibuka_pada'],false).' · '.$case['kerahasiaan'].($internal?' · Tujuan: '.mb_strimwidth((string)$case['tujuan'],0,80,'…'):''),
    'actions'=>$aksiKepala,
    'breadcrumbs'=>[['label'=>'Pembinaan','url'=>app_url('/portal/v3_pelanggaran.php')],['label'=>'Konseling','url'=>app_url('/portal/v3_konseling.php')],['label'=>$case['santri_nama']]]]);
if($error!==''&&$errorAksi===''){ah_note('danger',$error);}
if($rahasia){ah_note('warning','Kasus Rahasia tidak dapat dipublikasikan ke orang tua dan tidak terlihat oleh murobi. Tombol "Siapkan publikasi" hanya tersedia untuk kasus Internal.');}
echo ah_seg([
    ['label'=>'Sesi '.count($sesiTerkini),'url'=>$tabUrl('sesi'),'active'=>$tab==='sesi'],
    ['label'=>'Tautan','url'=>$tabUrl('tautan'),'active'=>$tab==='tautan'],
    ['label'=>'Catatan murobi','url'=>$tabUrl('murobi'),'active'=>$tab==='murobi'],
    ['label'=>'Riwayat','url'=>$tabUrl('riwayat'),'active'=>$tab==='riwayat'],
],'Bagian detail kasus');
?>
<div class="ah-split">
<div class="ah-split__col">
<?php if($tab==='sesi'): ?>
    <?php if($internal): ?><section class="ah-card"><div class="ah-card__body"><dl class="ah-info-list"><div><dt>Tujuan pendampingan</dt><dd style="white-space:pre-wrap"><?= ah_e($case['tujuan']) ?></dd></div><?php if($case['ringkasan_penutupan']): ?><div><dt>Ringkasan penutupan</dt><dd style="white-space:pre-wrap"><?= ah_e($case['ringkasan_penutupan']) ?></dd></div><?php endif ?><?php if($case['alasan_pembatalan']??null): ?><div><dt>Alasan pembatalan</dt><dd style="white-space:pre-wrap"><?= ah_e($case['alasan_pembatalan']) ?></dd></div><?php endif ?></dl></div></section><?php endif ?>
    <?php if(!$open&&$canManage): ?><p class="text-muted small">Kasus sudah ditutup; sesi yang masih terjadwal ikut ditutup sebagai Dibatalkan, dan hanya koreksi beralasan yang tersedia.</p><?php endif ?>
    <?php if($detail['sesi']===[]): ?><section class="ah-card"><div class="ah-card__body"><?= ah_empty('Belum ada sesi',$canManage&&$open?'Tekan "Jadwalkan sesi" untuk memulai pendampingan.':'Pembimbing belum menjadwalkan sesi.') ?></div></section><?php endif ?>
    <?php foreach($detail['sesi'] as $session): $sid=(int)$session['id']; $terkini=$session['digantikan_oleh_id']===null; $bisaCatat=$canManage&&$open&&$terkini&&in_array($session['status'],['Dijadwalkan','Dijadwalkan Ulang'],true); ?>
        <section class="ah-card<?= $terkini?'':' opacity-75' ?>" aria-labelledby="sesi-<?= $sid ?>">
            <div class="ah-card__head"><span id="sesi-<?= $sid ?>">Sesi <?= (int)($nomorSesi[$sid]??0) ?> · <?= ah_e(ah_waktu($session['realisasi']??$session['jadwal'])) ?><?= $terkini?'':' · direvisi' ?></span><?= ah_status($session['status']) ?></div>
            <div class="ah-card__body">
                <?php if($internal): ?><dl class="ah-info-list">
                    <?php if($session['ringkasan_internal']): ?><div><dt>Ringkasan internal</dt><dd style="white-space:pre-wrap"><?= ah_e($session['ringkasan_internal']) ?></dd></div><?php endif ?>
                    <?php if($session['hasil']): ?><div><dt>Hasil</dt><dd style="white-space:pre-wrap"><?= ah_e($session['hasil']) ?></dd></div><?php endif ?>
                    <?php if($session['tindak_lanjut']): ?><div><dt>Tindak lanjut</dt><dd style="white-space:pre-wrap"><?= ah_e($session['tindak_lanjut']) ?></dd></div><?php endif ?>
                    <?php if($session['jadwal_berikut']): ?><div><dt>Jadwal berikutnya</dt><dd><?= ah_e(ah_waktu($session['jadwal_berikut'])) ?></dd></div><?php endif ?>
                    <?php foreach(['alasan_penjadwalan_ulang'=>'Alasan jadwal ulang','alasan_pembatalan'=>'Alasan pembatalan','alasan_revisi'=>'Alasan koreksi'] as $kolom=>$labelKolom): ?><?php if($session[$kolom]??null): ?><div><dt><?= ah_e($labelKolom) ?></dt><dd style="white-space:pre-wrap"><?= ah_e($session[$kolom]) ?></dd></div><?php endif ?><?php endforeach ?>
                </dl><?php else: ?><p class="small text-muted mb-0">Isi sesi hanya untuk pembimbing dan admin.</p><?php endif ?>
                <?php if($terkini&&($bisaCatat||($canCorrect&&$internal)||$canAck)): ?><div class="ah-acts mt-3">
                <?php if($bisaCatat): ?>
                    <?php ah_action_dialog_open('catat-sesi-'.$sid,'Catat hasil sesi','Catat hasil sesi '.(int)($nomorSesi[$sid]??0),'primary',$gagal('status_sesi-'.$sid)); ?>
                        <?php $galatDi('status_sesi-'.$sid); ?>
                        <form method="post"><?= ah_csrf() ?><input type="hidden" name="aksi" value="status_sesi"><input type="hidden" name="sesi_id" value="<?= $sid ?>"><input type="hidden" name="version" value="<?= (int)$session['version'] ?>"><input type="hidden" name="idempotency_key" value="<?= ah_e($key('session-status')) ?>">
                            <label class="form-label" for="st-status-<?= $sid ?>">Hasil <span class="text-danger">*</span></label>
                            <select class="form-select mb-2" id="st-status-<?= $sid ?>" name="status"><option>Selesai</option><option>Tidak Hadir</option><option>Dijadwalkan Ulang</option><option>Dibatalkan</option></select>
                            <div data-ah-when="status" data-ah-when-value="Selesai|Tidak Hadir"><label class="form-label" for="st-realisasi-<?= $sid ?>">Waktu pelaksanaan (Selesai/Tidak hadir)</label><input class="form-control mb-2" id="st-realisasi-<?= $sid ?>" type="datetime-local" name="realisasi" value="<?= ah_e(date('Y-m-d\TH:i')) ?>"></div>
                            <div data-ah-when="status" data-ah-when-value="Selesai"><label class="form-label" for="st-ringkasan-<?= $sid ?>">Ringkasan internal (wajib bila Selesai)</label><textarea class="form-control mb-2" id="st-ringkasan-<?= $sid ?>" name="ringkasan_internal" rows="3"></textarea>
                                <label class="form-label" for="st-hasil-<?= $sid ?>">Hasil pendampingan (wajib bila Selesai)</label><textarea class="form-control mb-2" id="st-hasil-<?= $sid ?>" name="hasil" rows="2"></textarea>
                                <label class="form-label" for="st-tindak-<?= $sid ?>">Tindak lanjut (kosongkan untuk mempertahankan rencana tersimpan)</label><textarea class="form-control mb-2" id="st-tindak-<?= $sid ?>" name="tindak_lanjut" rows="2"></textarea></div>
                            <div data-ah-when="status" data-ah-when-value="Dijadwalkan Ulang"><label class="form-label" for="st-jadwal-<?= $sid ?>">Jadwal baru (Dijadwalkan ulang)</label><input class="form-control mb-2" id="st-jadwal-<?= $sid ?>" type="datetime-local" name="jadwal" value="<?= ah_e(str_replace(' ','T',substr($session['jadwal'],0,16))) ?>"></div>
                            <div data-ah-when="status" data-ah-when-value="Dijadwalkan Ulang|Dibatalkan"><label class="form-label" for="st-alasan-<?= $sid ?>">Alasan (wajib bila dijadwalkan ulang atau dibatalkan)</label><textarea class="form-control" id="st-alasan-<?= $sid ?>" name="alasan" rows="2"></textarea></div>
                            <div class="ah-act__actions"><button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Batal</button><button class="btn btn-primary" type="submit">Simpan hasil</button></div>
                        </form>
                    <?php ah_action_dialog_close(); ?>
                <?php endif ?>
                <?php if($canCorrect&&$internal): ?>
                    <?php ah_action_dialog_open('koreksi-sesi-'.$sid,'Perbarui sesi','Koreksi sesi '.(int)($nomorSesi[$sid]??0),'outline-primary',$gagal('koreksi_sesi-'.$sid)); ?>
                        <?php $galatDi('koreksi_sesi-'.$sid); ?>
                        <form method="post"><?= ah_csrf() ?><input type="hidden" name="aksi" value="koreksi_sesi"><input type="hidden" name="sesi_id" value="<?= $sid ?>"><input type="hidden" name="version" value="<?= (int)$session['version'] ?>"><input type="hidden" name="idempotency_key" value="<?= ah_e($key('session-correct')) ?>">
                            <p class="small text-muted">Koreksi menyimpan revisi sesi; isi sebelumnya tetap di riwayat.</p>
                            <label class="form-label" for="ks-jadwal-<?= $sid ?>">Jadwal</label><input class="form-control mb-2" id="ks-jadwal-<?= $sid ?>" type="datetime-local" name="jadwal" value="<?= ah_e(str_replace(' ','T',substr($session['jadwal'],0,16))) ?>" required>
                            <label class="form-label" for="ks-ringkasan-<?= $sid ?>">Ringkasan internal</label><textarea class="form-control mb-2" id="ks-ringkasan-<?= $sid ?>" name="ringkasan_internal" rows="3"><?= ah_e($session['ringkasan_internal']??'') ?></textarea>
                            <label class="form-label" for="ks-hasil-<?= $sid ?>">Hasil</label><textarea class="form-control mb-2" id="ks-hasil-<?= $sid ?>" name="hasil" rows="2"><?= ah_e($session['hasil']??'') ?></textarea>
                            <label class="form-label" for="ks-tindak-<?= $sid ?>">Tindak lanjut</label><textarea class="form-control mb-2" id="ks-tindak-<?= $sid ?>" name="tindak_lanjut" rows="2"><?= ah_e($session['tindak_lanjut']??'') ?></textarea>
                            <label class="form-label" for="ks-alasan-<?= $sid ?>">Alasan koreksi <span class="text-danger">*</span></label><input class="form-control" id="ks-alasan-<?= $sid ?>" name="alasan" required>
                            <div class="ah-act__actions"><button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Batal</button><button class="btn btn-primary" type="submit">Simpan revisi sesi</button></div>
                        </form>
                    <?php ah_action_dialog_close(); ?>
                <?php endif ?>
                <?php if($canAck): ?>
                    <?php ah_action_dialog_open('ketahui-sesi-'.$sid,'Tandai mengetahui sesi','Tandai mengetahui sesi '.(int)($nomorSesi[$sid]??0),'outline-primary',$gagal('diketahui_sesi-'.$sid)); ?>
                        <?php $galatDi('diketahui_sesi-'.$sid); ?>
                        <form method="post"><?= ah_csrf() ?><input type="hidden" name="aksi" value="diketahui_sesi"><input type="hidden" name="sesi_id" value="<?= $sid ?>"><input type="hidden" name="idempotency_key" value="<?= ah_e($key('ack-session')) ?>">
                            <label class="form-label" for="ack-sesi-<?= $sid ?>">Catatan murobi untuk sesi (opsional)</label><textarea class="form-control" id="ack-sesi-<?= $sid ?>" name="catatan" rows="2" maxlength="2000"></textarea>
                            <div class="ah-act__actions"><button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Batal</button><button class="btn btn-primary" type="submit">Tandai mengetahui</button></div>
                        </form>
                    <?php ah_action_dialog_close(); ?>
                <?php endif ?>
                </div><?php endif ?>
            </div>
        </section>
    <?php endforeach ?>
<?php elseif($tab==='tautan'): ?>
    <section class="ah-card" aria-labelledby="ah-tautan-pelanggaran">
        <div class="ah-card__head"><span id="ah-tautan-pelanggaran">Pelanggaran terhubung</span></div>
        <div class="ah-card__body"><?php if($detail['tautan']===[]): ?><p class="text-muted mb-0">Kasus ini tidak tertaut ke pelanggaran.</p><?php else: ?>
            <ul class="ah-qlist"><?php foreach($detail['tautan'] as $link): ?><li><a href="<?= ah_e(app_url('/portal/v3_pelanggaran_detail.php?id='.(int)$link['pelanggaran_id'])) ?>"><span class="ah-qlist__main"><b><?= ah_e($link['kategori'].' · '.ah_tanggal($link['waktu_kejadian'],false)) ?></b><small><?= (int)$link['poin'] ?> poin<?= $link['sesi_id']?' · untuk sesi '.(int)($nomorSesi[(int)$link['sesi_id']]??0):'' ?><?= $link['pelanggaran_digantikan_oleh_id']?' · sudah dikoreksi':'' ?><?= ($link['alasan']??null)?' · '.ah_e($link['alasan']):'' ?></small></span><?= ah_status($link['status']) ?></a></li><?php endforeach ?></ul>
        <?php endif ?></div>
    </section>
    <section class="ah-card" aria-labelledby="ah-tautan-rekomendasi">
        <div class="ah-card__head"><span id="ah-tautan-rekomendasi">Rekomendasi yang ditindaklanjuti</span></div>
        <div class="ah-card__body"><?php if($detail['rekomendasi']===[]): ?><p class="text-muted mb-0">Tidak ada rekomendasi tertaut.</p><?php else: ?><ul class="mb-0"><?php foreach($detail['rekomendasi'] as $recommendation): ?><li><strong><?= ah_e($recommendation['label']) ?></strong> · <?= (int)$recommendation['total_poin'] ?> poin</li><?php endforeach ?></ul><?php endif ?></div>
    </section>
    <?php if($canManage&&$open&&($linkableViolations!==[]||$linkableRecommendations!==[])): ?>
    <section class="ah-card" aria-labelledby="ah-tambah-tautan">
        <div class="ah-card__head"><span id="ah-tambah-tautan">Tautkan pelanggaran atau rekomendasi</span></div>
        <div class="ah-card__body"><?php $galatDi('tambah_tautan'); ?><p class="text-muted small">Dipilih manual; tidak mengubah poin dan tidak membuat sesi otomatis.</p>
            <form method="post" class="row g-3"><?= ah_csrf() ?><input type="hidden" name="aksi" value="tambah_tautan"><input type="hidden" name="idempotency_key" value="<?= ah_e($key('links')) ?>">
                <?php if($linkableViolations!==[]): ?><fieldset class="col-12"><legend class="form-label">Pelanggaran santri ini</legend><div class="ah-check-list"><?php foreach($linkableViolations as $violation): ?><label><input class="form-check-input" type="checkbox" name="pelanggaran_ids[]" value="<?= (int)$violation['id'] ?>"><span><?= ah_e($violation['kategori'].' · '.ah_waktu($violation['waktu_kejadian']).' · '.$violation['poin'].' poin') ?></span></label><?php endforeach ?></div></fieldset><?php endif ?>
                <?php if($linkableRecommendations!==[]): ?><fieldset class="col-12"><legend class="form-label">Rekomendasi berlaku yang belum ditindaklanjuti</legend><div class="ah-check-list"><?php foreach($linkableRecommendations as $recommendation): ?><label><input class="form-check-input" type="checkbox" name="rekomendasi_ids[]" value="<?= (int)$recommendation['id'] ?>"><span><?= ah_e($recommendation['label'].' · '.$recommendation['total_poin'].' poin') ?></span></label><?php endforeach ?></div></fieldset><?php endif ?>
                <div class="col-12"><label class="form-label" for="link-alasan">Alasan tautan (opsional)</label><input class="form-control" id="link-alasan" name="alasan" maxlength="1000"></div>
                <div class="col-12"><button class="btn btn-outline-primary">Simpan tautan</button></div>
            </form></div>
    </section>
    <?php endif ?>
<?php elseif($tab==='murobi'): ?>
    <section class="ah-card" aria-labelledby="ah-murobi">
        <div class="ah-card__head"><span id="ah-murobi">Catatan murobi</span></div>
        <div class="ah-card__body">
            <?php if($detail['catatan_murobi']===[]): ?><p class="text-muted">Murobi belum menandai mengetahui.</p><?php else: ?>
                <?= ah_timeline(array_map(static fn(array $note):array=>['waktu'=>ah_waktu($note['diketahui_pada']),'pelaku'=>'murobi','judul'=>$note['sesi_id']?'Mengetahui sesi '.(int)($nomorSesi[(int)$note['sesi_id']]??0):'Mengetahui kasus','isi'=>(string)($note['catatan']??'')],$detail['catatan_murobi']),'Catatan murobi') ?>
            <?php endif ?>
            <?php if($canAck): ?><div class="ah-acts mt-3">
                <?php ah_action_dialog_open('ketahui-kasus','Tandai mengetahui kasus','Tandai mengetahui kasus','primary',$gagal('diketahui_kasus')); ?>
                    <?php $galatDi('diketahui_kasus'); ?>
                    <form method="post"><?= ah_csrf() ?><input type="hidden" name="aksi" value="diketahui_kasus"><input type="hidden" name="idempotency_key" value="<?= ah_e($key('ack-case')) ?>">
                        <label class="form-label" for="ack-kasus">Catatan murobi (terpisah dari catatan internal)</label><textarea class="form-control" id="ack-kasus" name="catatan" rows="2" maxlength="2000"></textarea>
                        <div class="ah-act__actions"><button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Batal</button><button class="btn btn-primary" type="submit">Tandai mengetahui</button></div>
                    </form>
                <?php ah_action_dialog_close(); ?>
            </div><?php endif ?>
        </div>
    </section>
<?php else: ?>
    <section class="ah-card" aria-labelledby="ah-linimasa">
        <div class="ah-card__head"><span id="ah-linimasa">Linimasa kasus</span></div>
        <div class="ah-card__body"><?= ah_timeline(array_map(static fn(array $event):array=>['waktu'=>ah_waktu((string)$event['waktu']),'pelaku'=>'kasus','judul'=>(string)$event['jenis']],$timeline['rows']),'Linimasa kasus') ?></div>
    </section>
    <?php if($internal): ?><section class="ah-card" aria-labelledby="ah-revisi">
        <div class="ah-card__head"><span id="ah-revisi">Riwayat revisi kasus</span></div>
        <div class="ah-card__body"><?php if($detail['riwayat_revisi_kasus']===[]): ?><p class="text-muted mb-0">Belum ada koreksi.</p><?php else: ?>
            <?= ah_timeline(array_map(static fn(array $revision):array=>['waktu'=>ah_waktu($revision['created_at']),'pelaku'=>$revision['kapasitas'],'judul'=>'Koreksi dari versi '.$revision['versi_sebelum'],
                'isi'=>'Alasan: '.$revision['alasan'].($revision['kerahasiaan_sebelum']!==$revision['kerahasiaan_sesudah']?"\nKerahasiaan: ".$revision['kerahasiaan_sebelum'].' → '.$revision['kerahasiaan_sesudah']:'').($revision['tujuan_sebelum']!==$revision['tujuan_sesudah']?"\nTujuan sebelumnya: ".$revision['tujuan_sebelum']:'')],$detail['riwayat_revisi_kasus']),'Riwayat revisi kasus') ?>
        <?php endif ?></div>
    </section><?php endif ?>
<?php endif ?>
</div>
<div class="ah-split__col">
    <section class="ah-card ah-no-print" aria-labelledby="ah-status-kasus">
        <div class="ah-card__head"><span id="ah-status-kasus">Status kasus</span><?= ah_status($case['status']) ?></div>
        <div class="ah-card__body"><div class="ah-acts">
            <?php if($canManage&&$open): ?>
                <?php ah_action_dialog_open('jadwal-sesi','Jadwalkan sesi','Jadwalkan sesi baru','primary',$gagal('buat_sesi')); ?>
                    <?php $galatDi('buat_sesi'); ?>
                    <form method="post"><?= ah_csrf() ?><input type="hidden" name="aksi" value="buat_sesi"><input type="hidden" name="idempotency_key" value="<?= ah_e($key('session-create')) ?>">
                        <label class="form-label" for="new-jadwal">Jadwal <span class="text-danger">*</span></label><input class="form-control mb-2" id="new-jadwal" type="datetime-local" name="jadwal" value="<?= ah_e(date('Y-m-d\TH:i',time()+86400)) ?>" required>
                        <label class="form-label" for="new-berikut">Jadwal berikutnya (opsional)</label><input class="form-control mb-2" id="new-berikut" type="datetime-local" name="jadwal_berikut">
                        <label class="form-label" for="new-tindak">Rencana tindak lanjut (opsional)</label><textarea class="form-control mb-2" id="new-tindak" name="tindak_lanjut" rows="2" maxlength="5000"></textarea>
                        <?php if($options['pelanggaran']!==[]): ?><fieldset><legend class="form-label">Tautkan pelanggaran ke sesi ini (opsional)</legend><div class="ah-check-list"><?php foreach($options['pelanggaran'] as $violation): ?><label><input class="form-check-input" type="checkbox" name="pelanggaran_ids[]" value="<?= (int)$violation['id'] ?>"><span><?= ah_e($violation['kategori'].' · '.ah_tanggal($violation['waktu_kejadian'],false)) ?></span></label><?php endforeach ?></div></fieldset><?php endif ?>
                        <div class="ah-act__actions"><button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Batal</button><button class="btn btn-primary" type="submit">Simpan jadwal</button></div>
                    </form>
                <?php ah_action_dialog_close(); ?>
                <?php if($case['status']==='Dibuka'): ?>
                    <?php ah_action_dialog_open('mulai-kasus','Mulai pendampingan','Mulai pendampingan?','outline-primary',$gagal('status_kasus-Dalam Pendampingan')); ?>
                        <?php $galatDi('status_kasus-Dalam Pendampingan'); ?>
                        <form method="post"><?= ah_csrf() ?><input type="hidden" name="aksi" value="status_kasus"><input type="hidden" name="status" value="Dalam Pendampingan"><input type="hidden" name="version" value="<?= (int)$case['version'] ?>"><input type="hidden" name="idempotency_key" value="<?= ah_e($key('case-start')) ?>">
                            <p class="small">Status kasus menjadi "Dalam pendampingan". Murobi terkait diberi tahu bila kasus Internal.</p>
                            <div class="ah-act__actions"><button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Batal</button><button class="btn btn-primary" type="submit">Mulai pendampingan</button></div>
                        </form>
                    <?php ah_action_dialog_close(); ?>
                <?php else: ?>
                    <?php ah_action_dialog_open('tutup-kasus','Tutup kasus','Tutup kasus sebagai Selesai','outline-primary',$gagal('status_kasus-Selesai')); ?>
                        <?php $galatDi('status_kasus-Selesai'); ?>
                        <form method="post"><?= ah_csrf() ?><input type="hidden" name="aksi" value="status_kasus"><input type="hidden" name="status" value="Selesai"><input type="hidden" name="version" value="<?= (int)$case['version'] ?>"><input type="hidden" name="idempotency_key" value="<?= ah_e($key('case-done')) ?>">
                            <p class="small text-muted">Kasus hanya dapat ditutup setelah sedikitnya satu sesi selesai. Menutup kasus ikut menutup sesi yang masih terjadwal sebagai Dibatalkan.</p>
                            <label class="form-label" for="sk-ringkasan">Ringkasan penutupan <span class="text-danger">*</span></label><textarea class="form-control" id="sk-ringkasan" name="ringkasan_penutupan" rows="3" required></textarea>
                            <div class="ah-act__actions"><button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Batal</button><button class="btn btn-primary" type="submit">Tutup kasus</button></div>
                        </form>
                    <?php ah_action_dialog_close(); ?>
                <?php endif ?>
                <?php ah_action_dialog_open('batal-kasus','Batalkan kasus','Batalkan kasus ini?','outline-danger',$gagal('status_kasus-Dibatalkan')); ?>
                    <?php $galatDi('status_kasus-Dibatalkan'); ?>
                    <form method="post"><?= ah_csrf() ?><input type="hidden" name="aksi" value="status_kasus"><input type="hidden" name="status" value="Dibatalkan"><input type="hidden" name="version" value="<?= (int)$case['version'] ?>"><input type="hidden" name="idempotency_key" value="<?= ah_e($key('case-cancel')) ?>">
                        <p class="small">Membatalkan kasus ikut menutup sesi yang masih terjadwal sebagai Dibatalkan; rekomendasi tertaut kembali ke antrean.</p>
                        <label class="form-label" for="sk-alasan">Alasan pembatalan <span class="text-danger">*</span></label><textarea class="form-control" id="sk-alasan" name="alasan" rows="2" required></textarea>
                        <div class="ah-act__actions"><button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Kembali</button><button class="btn btn-danger" type="submit">Batalkan kasus</button></div>
                    </form>
                <?php ah_action_dialog_close(); ?>
            <?php endif ?>
            <?php if($canCorrect&&$internal&&$open): ?>
                <?php ah_action_dialog_open('koreksi-kasus','Koreksi kasus','Koreksi kasus','outline-secondary',$gagal('koreksi_kasus')); ?>
                    <?php $galatDi('koreksi_kasus'); ?>
                    <form method="post"><?= ah_csrf() ?><input type="hidden" name="aksi" value="koreksi_kasus"><input type="hidden" name="version" value="<?= (int)$case['version'] ?>"><input type="hidden" name="idempotency_key" value="<?= ah_e($key('case-correct')) ?>">
                        <p class="small text-muted">Koreksi disimpan sebagai revisi beserta nilai sebelum/sesudah. Mengubah ke Rahasia menyembunyikan kasus dari murobi dan pembimbing selain pemilik.</p>
                        <label class="form-label" for="kk-tujuan">Tujuan</label><textarea class="form-control mb-2" id="kk-tujuan" name="tujuan" rows="3" required><?= ah_e($case['tujuan']) ?></textarea>
                        <label class="form-label" for="kk-kerahasiaan">Kerahasiaan</label><select class="form-select mb-2" id="kk-kerahasiaan" name="kerahasiaan"><option <?= $case['kerahasiaan']==='Internal'?'selected':'' ?>>Internal</option><option <?= $rahasia?'selected':'' ?>>Rahasia</option></select>
                        <label class="form-label" for="kk-alasan">Alasan koreksi <span class="text-danger">*</span></label><input class="form-control" id="kk-alasan" name="alasan" required>
                        <div class="ah-act__actions"><button class="btn btn-outline-secondary" type="button" data-ah-dialog-close>Batal</button><button class="btn btn-primary" type="submit">Simpan koreksi</button></div>
                    </form>
                <?php ah_action_dialog_close(); ?>
            <?php endif ?>
            <?php if($publikasi!==null&&$publikasi['ok']): ?>
                <a class="btn btn-outline-primary" href="<?= ah_e(app_url('/portal/v3_publikasi_kelola.php?sumber_type=kasus&sumber_id='.(int)$id)) ?>"><i class="fas fa-paper-plane" aria-hidden="true"></i> Siapkan publikasi ke orang tua</a>
            <?php elseif($publikasi!==null): ?>
                <button class="btn btn-outline-secondary" type="button" disabled aria-describedby="sebab-publikasi">Siapkan publikasi ke orang tua</button>
                <p class="small text-muted mb-0" id="sebab-publikasi"><?= ah_e($publikasi['sebab']) ?></p>
            <?php endif ?>
            <?php if(!$canManage&&!$canCorrect&&$publikasi===null): ?><p class="small text-muted mb-0">Halaman ini hanya-baca untuk akun Anda.</p><?php endif ?>
        </div></div>
    </section>
    <section class="ah-card" aria-labelledby="ah-pelanggaran-terkait">
        <div class="ah-card__head"><span id="ah-pelanggaran-terkait">Pelanggaran terkait</span></div>
        <div class="ah-card__body"><?php $kasusTaut=array_values(array_filter($detail['tautan'],static fn(array $l):bool=>$l['pelanggaran_digantikan_oleh_id']===null)); ?>
            <?php if($kasusTaut===[]): ?><p class="text-muted small mb-0">Tidak ada.</p><?php else: ?><ul class="ah-qlist"><?php foreach($kasusTaut as $link): ?><li><a href="<?= ah_e(app_url('/portal/v3_pelanggaran_detail.php?id='.(int)$link['pelanggaran_id'])) ?>"><span class="ah-qlist__main"><b><?= ah_e($link['kategori']) ?></b><small><?= ah_e(ah_tanggal($link['waktu_kejadian'],false).' · '.$link['poin'].' poin') ?></small></span></a></li><?php endforeach ?></ul><?php endif ?>
        </div>
    </section>
</div>
</div>
<?php if($canManage&&$open): ?><div class="ah-actbar ah-only-sm ah-no-print"><a class="btn btn-primary" href="#jadwal-sesi" data-ah-open-dialog="jadwal-sesi">Jadwalkan sesi</a></div><?php endif ?>
<?php ah_page_close(); ?>
