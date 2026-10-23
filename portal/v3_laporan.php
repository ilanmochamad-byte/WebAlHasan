<?php
declare(strict_types=1);
use App\Auth\Capabilities;
use App\Ui\Periode;
use App\V3\LaporanService;
use App\V3\PelanggaranRepository;
use App\V3\V3Exception;
use App\Ui\Denial;
/**
 * Pembinaan santri › Laporan (redesain website Tahap 5, V-6).
 *
 * Seluruh saringan memakai pilihan bernama atau pencarian nama santri; isian
 * "ID santri/kelas/kamar/pembimbing/murobi" dihapus. Nilai yang dikirim tetap
 * divalidasi dan dibatasi cakupan oleh LaporanService, yang juga menyusun
 * ringkasan angka dengan kueri dan penyaring kerahasiaan yang sama dengan
 * daftar. CSV dan respons API tidak berubah bentuk.
 */
require_once dirname(__DIR__).'/app/bootstrap.php';
$user=authorization()->requireWebUser();
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$service=new LaporanService(new PelanggaranRepository(app_db()),capabilities());
$kinds=$service->kinds($user);if($kinds===[])Denial::render('Laporan tidak dapat diakses.','Masuk dengan akun yang berhak atau periksa penugasan aktif.');
$format=is_string($_GET['format']??null)?$_GET['format']:'html';
$ortu=$kinds===['publikasi'];
$jenis=is_string($_GET['jenis']??null)&&$_GET['jenis']!==''?$_GET['jenis']:$kinds[0];

// Periode bernama → mulai/sampai. Alamat lama dengan tanggal tetap "Rentang sendiri".
$adaTanggal=(($_GET['mulai']??'')!=='')||(($_GET['sampai']??'')!=='');
$periodeKode=is_string($_GET['periode']??null)&&($_GET['periode']==='semua'||array_key_exists($_GET['periode'],Periode::PILIHAN))?$_GET['periode']:($adaTanggal?'rentang':($ortu||$jenis==='ambang'?'semua':'semester'));
$masukan=$_GET;unset($masukan['periode'],$masukan['format']);
if($jenis==='ambang'){unset($masukan['mulai'],$masukan['sampai']);$periodeKode='semua';}
elseif($periodeKode==='semua'){unset($masukan['mulai'],$masukan['sampai']);}
elseif($periodeKode!=='rentang'){
    $aktif=app_db()->query("SELECT tahun,semester FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1")?->fetch_assoc();
    [$masukan['mulai'],$masukan['sampai']]=Periode::rentang($periodeKode,$aktif?:null);
}
$masukan=array_filter($masukan,static fn($v):bool=>!is_string($v)||$v!=='');
try{
    if(!in_array($format,['html','cetak','csv'],true))throw new V3Exception('Format tidak tersedia.');
    $list=$service->read($user,$masukan,$format!=='html');
    $ringkasan=$format==='csv'?null:$service->ringkasan($user,$masukan);
}catch(V3Exception $e){
    if($e->status===403)Denial::render('Laporan tidak dapat diakses.','Masuk dengan akun yang berhak atau periksa penugasan aktif.');
    http_response_code($e->status);ah_page_open(['title'=>'Laporan pembinaan','user'=>$user]);ah_note('danger',$e->getMessage(),'<p class="mb-0 mt-2"><a href="'.ah_e(app_url('/portal/v3_laporan.php')).'">Bersihkan saringan</a></p>');ah_page_close();exit;
}
if($format==='csv'){
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="laporan-pembinaan.csv"');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,array_values($list['columns']),',','"','');
    foreach($list['rows'] as $row)fputcsv($out,array_map([LaporanService::class,'csvCell'],array_values($row)),',','"','');fclose($out);exit;
}
$print=$format==='cetak';
$pilihan=$service->filterOptions($user);
$penempatan=$ortu?[]:$service->penempatan(array_column($list['rows'],'santri_id'));
$namaAnak=[];if($ortu){try{foreach(izin_service()->santriInScope($user,Capabilities::ORANG_TUA) as $s){$namaAnak[(int)$s['id']]=(string)$s['nama_santri'];}}catch(Throwable){}}
$nilai=static fn(string $k):string=>is_scalar($_GET[$k]??null)?(string)$_GET[$k]:'';
$labelJenis=['pelanggaran'=>'Pelanggaran','kasus'=>'Kasus konseling','ambang'=>'Santri mencapai ambang','publikasi'=>'Informasi pembinaan'];
$aksiEkspor='';
foreach(['csv'=>'Ekspor CSV','cetak'=>'Cetak / PDF'] as $f=>$label){$q=$_GET;$q['format']=$f;$q['periode']=$periodeKode;unset($q['page']);$aksiEkspor.='<a class="btn '.($f==='cetak'?'btn-primary':'btn-outline-primary').'" href="'.ah_e(app_url('/portal/v3_laporan.php?'.http_build_query($q))).'"'.($f==='cetak'?' target="_blank" rel="noopener"':'').'>'.ah_e($label).'</a>';}
ah_page_open(['title'=>$ortu?'Rekap informasi pembinaan':'Laporan pembinaan','heading'=>$ortu?'Rekap informasi pembinaan':($print?'Laporan pembinaan · '.$labelJenis[$list['jenis']]:'Pembinaan santri'),'user'=>$user,
    'description'=>$print?'Sesuai hak akses dan cakupan aktif · dicetak '.ah_waktu(date('Y-m-d H:i:s')):($ortu?'Informasi pembinaan anak Anda yang sudah diterbitkan.':'Laporan sesuai hak akses dan cakupan aktif Anda.'),
    'actions'=>$print?'':$aksiEkspor]);
?>
<?php if(!$print): ?>
<?php
if(!$ortu){
    $seg=[];foreach(array_diff($kinds,['publikasi']) as $k){$seg[]=['label'=>$labelJenis[$k],'url'=>'?'.http_build_query(array_filter(['jenis'=>$k,'periode'=>$k==='ambang'?null:($periodeKode==='semester'?null:$periodeKode),'mulai'=>$k==='ambang'?null:$nilai('mulai'),'sampai'=>$k==='ambang'?null:$nilai('sampai'),'q'=>$nilai('q'),'kelas_id'=>$nilai('kelas_id'),'kamar_id'=>$nilai('kamar_id')],static fn($v):bool=>$v!==null&&$v!=='')),'active'=>$list['jenis']===$k];}
    echo ah_seg($seg,'Jenis laporan');
}
$lanjutanKeys=$list['jenis']==='ambang'?[]:['status','kategori','tingkat','poin_min','poin_max','tindak_lanjut','pembimbing_id','murobi_id'];
$lanjutan=count(array_filter($lanjutanKeys,static fn(string $k):bool=>$nilai($k)!==''));
$aktifSaring=$lanjutan+(int)($nilai('q')!=='')+(int)($nilai('kelas_id')!=='')+(int)($nilai('kamar_id')!=='')+(int)($periodeKode!==($ortu||$list['jenis']==='ambang'?'semua':'semester'));
ah_filter_open($aktifSaring,array_filter(['jenis'=>$list['jenis'],'santri_id'=>$nilai('santri_id'),'aktif'=>$nilai('aktif')]));
?>
    <?php if($list['jenis']!=='ambang'): ?>
    <div><label class="form-label" for="periode">Periode</label><select class="form-select" id="periode" name="periode">
        <option value="semua"<?= $periodeKode==='semua'?' selected':'' ?>>Semua waktu</option>
        <?php foreach(['pekan','bulan','semester','rentang'] as $kode): ?><option value="<?= $kode ?>"<?= $periodeKode===$kode?' selected':'' ?>><?= ah_e(Periode::label($kode)) ?></option><?php endforeach ?>
    </select></div>
    <?php endif ?>
    <?php if(!$ortu): ?>
    <div><label class="form-label" for="kelas_id">Kelas</label><select class="form-select" id="kelas_id" name="kelas_id"><option value="">Semua kelas</option><?php foreach($pilihan['kelas'] as $o): ?><option value="<?= $o['id'] ?>"<?= $nilai('kelas_id')===(string)$o['id']?' selected':'' ?>><?= ah_e($o['nama']) ?></option><?php endforeach ?></select></div>
    <div><label class="form-label" for="kamar_id">Kamar</label><select class="form-select" id="kamar_id" name="kamar_id"><option value="">Semua kamar</option><?php foreach($pilihan['kamar'] as $o): ?><option value="<?= $o['id'] ?>"<?= $nilai('kamar_id')===(string)$o['id']?' selected':'' ?>><?= ah_e($o['nama']) ?></option><?php endforeach ?></select></div>
    <?php endif ?>
    <div class="ah-filter__wide"><label class="form-label" for="q">Santri</label><input class="form-control" type="search" id="q" name="q" maxlength="100" value="<?= ah_e($nilai('q')) ?>" placeholder="Cari nama santri"></div>
    <?php if($list['jenis']!=='ambang'): ?>
    <details class="ah-filter__more"<?= $lanjutan>0||$periodeKode==='rentang'?' open':'' ?>>
        <summary>Filter lanjutan<?= $lanjutan>0?' ('.$lanjutan.')':'' ?></summary>
        <div class="ah-filter__more-body">
            <div><label class="form-label" for="mulai">Dari tanggal (rentang sendiri)</label><input class="form-control" type="date" id="mulai" name="mulai" value="<?= ah_e($periodeKode==='rentang'?$nilai('mulai'):'') ?>"></div>
            <div><label class="form-label" for="sampai">Sampai tanggal</label><input class="form-control" type="date" id="sampai" name="sampai" value="<?= ah_e($periodeKode==='rentang'?$nilai('sampai'):'') ?>"></div>
            <?php $statusPilihan=match($list['jenis']){'pelanggaran'=>['Dicatat','Ditindaklanjuti','Selesai','Dibatalkan'],'kasus'=>['Dibuka','Dalam Pendampingan','Selesai','Dibatalkan'],default=>['Terbit','Ditarik']}; ?>
            <div><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">Semua status</option><?php foreach($statusPilihan as $o): ?><option<?= $nilai('status')===$o?' selected':'' ?>><?= ah_e($o) ?></option><?php endforeach ?></select></div>
            <div><label class="form-label" for="tindak_lanjut">Tindak lanjut</label><select class="form-select" id="tindak_lanjut" name="tindak_lanjut"><option value="">Semua</option><option value="ada"<?= $nilai('tindak_lanjut')==='ada'?' selected':'' ?>><?= $list['jenis']==='kasus'?'Sudah ada sesi':($ortu?'Ada tindak lanjut':'Sudah ditindaklanjuti konseling') ?></option><option value="belum"<?= $nilai('tindak_lanjut')==='belum'?' selected':'' ?>>Belum</option></select></div>
            <?php if(!$ortu): ?>
            <div><label class="form-label" for="kategori">Kategori</label><select class="form-select" id="kategori" name="kategori"><option value="">Semua kategori</option><?php foreach($pilihan['kategori'] as $o): ?><option<?= $nilai('kategori')===$o?' selected':'' ?>><?= ah_e($o) ?></option><?php endforeach ?></select></div>
            <div><label class="form-label" for="tingkat">Tingkat</label><select class="form-select" id="tingkat" name="tingkat"><option value="">Semua tingkat</option><?php foreach(['Ringan','Sedang','Berat'] as $o): ?><option<?= $nilai('tingkat')===$o?' selected':'' ?>><?= $o ?></option><?php endforeach ?></select></div>
            <div><label class="form-label" for="poin_min">Poin paling sedikit</label><input class="form-control" type="number" min="0" id="poin_min" name="poin_min" value="<?= ah_e($nilai('poin_min')) ?>"></div>
            <div><label class="form-label" for="poin_max">Poin paling banyak</label><input class="form-control" type="number" min="0" id="poin_max" name="poin_max" value="<?= ah_e($nilai('poin_max')) ?>"></div>
            <?php if($pilihan['pembimbing']!==[]): ?><div><label class="form-label" for="pembimbing_id">Pembimbing</label><select class="form-select" id="pembimbing_id" name="pembimbing_id"><option value="">Semua pembimbing</option><?php foreach($pilihan['pembimbing'] as $o): ?><option value="<?= $o['id'] ?>"<?= $nilai('pembimbing_id')===(string)$o['id']?' selected':'' ?>><?= ah_e($o['nama']) ?></option><?php endforeach ?></select></div><?php endif ?>
            <?php if($pilihan['murobi']!==[]): ?><div><label class="form-label" for="murobi_id">Murobi</label><select class="form-select" id="murobi_id" name="murobi_id"><option value="">Semua murobi</option><?php foreach($pilihan['murobi'] as $o): ?><option value="<?= $o['id'] ?>"<?= $nilai('murobi_id')===(string)$o['id']?' selected':'' ?>><?= ah_e($o['nama']) ?></option><?php endforeach ?></select></div><?php endif ?>
            <?php endif ?>
        </div>
    </details>
    <?php endif ?>
<?php ah_filter_close(array_filter(['jenis'=>$list['jenis']])); ?>
<?php if($nilai('santri_id')!==''): ?><?php ah_note('info','Laporan dibatasi pada satu santri dari tautan sebelumnya.','<p class="mb-0 mt-2"><a href="?'.ah_e(ah_query(['santri_id'=>null,'page'=>null])).'">Tampilkan semua santri</a></p>'); ?><?php endif ?>
<?php if($nilai('aktif')==='1'): ?><p class="small text-muted">Tanpa catatan yang dibatalkan.</p><?php endif ?>
<?php if($list['total']>LaporanService::EXPORT_LIMIT): ?><?php ah_note('warning','Hasil melebihi batas ekspor 10.000 baris. Persempit periode atau saringan sebelum mengekspor; ekspor ditolak seluruhnya, tanpa berkas parsial.'); ?><?php endif ?>
<?php else: ?>
<style>@page{size:landscape}@media print{.ah-table-wrap{overflow:visible!important}thead{display:table-header-group}tr{break-inside:avoid}}</style>
<p class="ah-no-print"><button class="btn btn-primary" type="button" data-ah-print hidden>Cetak / Simpan PDF</button></p>
<?php endif ?>
<?php if($ringkasan!==null&&!$ortu): ?>
    <?= ah_stats([
        ['label'=>'Catatan pelanggaran','value'=>(string)$ringkasan['catatan'],'hint'=>$periodeKode==='semua'?'Semua waktu':Periode::label($periodeKode),'url'=>$print?null:'?'.http_build_query(array_filter(['jenis'=>'pelanggaran','periode'=>$periodeKode,'mulai'=>$periodeKode==='rentang'?$nilai('mulai'):'','sampai'=>$periodeKode==='rentang'?$nilai('sampai'):'','q'=>$nilai('q'),'kelas_id'=>$nilai('kelas_id'),'kamar_id'=>$nilai('kamar_id')]))],
        ['label'=>'Total poin','value'=>(string)$ringkasan['total_poin'],'hint'=>'Tanpa catatan yang dibatalkan'],
        ['label'=>'Kasus berjalan','value'=>(string)$ringkasan['kasus_berjalan'],'hint'=>'Dibuka atau dalam pendampingan'],
        ['label'=>'Santri ≥ ambang','value'=>(string)$ringkasan['ambang'],'warn'=>$ringkasan['ambang']>0,'hint'=>'Semester aktif','url'=>$print?null:'?'.http_build_query(array_filter(['jenis'=>'ambang','q'=>$nilai('q'),'kelas_id'=>$nilai('kelas_id'),'kamar_id'=>$nilai('kamar_id')]))],
    ],'Ringkasan pembinaan') ?>
<?php endif ?>
<?php
$kolom=match($list['jenis']){
    'pelanggaran'=>['santri'=>'Santri','kelas'=>'Kelas','kamar'=>'Kamar','periode'=>'Kejadian','kategori'=>'Kategori','tingkat'=>'Tingkat','poin'=>'Poin','status'=>'Status'],
    'kasus'=>['santri'=>'Santri','kelas'=>'Kelas','kamar'=>'Kamar','periode'=>'Dibuka','kerahasiaan'=>'Kerahasiaan','jumlah_sesi'=>'Sesi','status'=>'Status'],
    'ambang'=>['santri'=>'Santri','kelas'=>'Kelas','kamar'=>'Kamar','periode'=>'Semester','total_poin'=>'Total poin','ambang'=>'Ambang tercapai'],
    default=>['periode'=>'Diterbitkan','anak'=>'Anak','status'=>'Status','ringkasan'=>'Ringkasan','tindak_lanjut'=>'Tindak lanjut'],
};
$sekunder=['kamar'=>true,'kerahasiaan'=>true];
$tautan=static fn(array $row):?string=>match($list['jenis']){'pelanggaran'=>app_url('/portal/v3_pelanggaran_detail.php?id='.(int)$row['id']),'kasus'=>app_url('/portal/v3_konseling_detail.php?id='.(int)$row['id']),'publikasi'=>app_url('/portal/v3_publikasi.php?id='.(int)$row['id']),default=>null};
?>
<section class="ah-card" aria-labelledby="ah-laporan">
    <div class="ah-card__head"><span id="ah-laporan"><?= ah_e($labelJenis[$list['jenis']]) ?></span><span class="text-muted small"><?= (int)$list['total'] ?> baris sesuai saringan</span></div>
    <?php if($list['rows']===[]): ?><div class="ah-card__body"><?= ah_empty('Belum ada data','Tidak ada data yang dapat dilihat untuk saringan dan cakupan ini.') ?></div>
    <?php else: ?>
    <div class="ah-table-wrap"><table class="ah-table ah-table--cards"><caption class="ah-visually-hidden"><?= ah_e($labelJenis[$list['jenis']]) ?> sesuai saringan</caption>
        <thead><tr><?php foreach($kolom as $k=>$label): ?><th scope="col"<?= isset($sekunder[$k])?' class="ah-col-secondary"':'' ?>><?= ah_e($label) ?></th><?php endforeach ?></tr></thead>
        <tbody><?php foreach($list['rows'] as $row): $pos=$penempatan[(int)($row['santri_id']??0)]??['kelas'=>null,'kamar'=>null]; $url=$print?null:$tautan($row); ?><tr>
            <?php foreach(array_keys($kolom) as $i=>$k): ?><td<?= isset($sekunder[$k])?' class="ah-col-secondary"':'' ?>><?php
                $v=match($k){'kelas'=>$pos['kelas']??'—','kamar'=>$pos['kamar']??'—','anak'=>$namaAnak[(int)$row['santri_id']]??'Anak Anda','periode'=>$list['jenis']==='ambang'?(string)$row['periode']:ah_waktu((string)$row['periode']),default=>(string)($row[$k]??'—')};
                if($k==='status')echo ah_status($v);elseif($k==='tingkat')echo ah_tingkat($v);elseif($k==='kerahasiaan')echo ah_badge($v,$v==='Rahasia'?'danger':'muted');
                elseif($i===0&&$url!==null)echo '<a class="ah-row-link" href="'.ah_e($url).'">'.ah_e($v).'</a>';
                elseif(in_array($k,['ringkasan','tindak_lanjut'],true))echo '<span style="white-space:pre-wrap">'.ah_e($v).'</span>';else echo ah_e($v);
            ?></td><?php endforeach ?>
        </tr><?php endforeach ?></tbody>
    </table></div>
    <?php endif ?>
</section>
<?php if(!$print)ah_pagination((int)$list['total'],(int)$list['page'],(int)$list['per_page']);ah_page_close();
